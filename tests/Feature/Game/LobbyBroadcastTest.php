<?php

use App\Actions\Room\UpdateRoomSettings;
use App\Enums\RoomStatus;
use App\Jobs\Game\BroadcastLobbyState;
use App\Models\Player;
use App\Models\Room;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Support\Realtime\ChannelNames;
use App\Support\Room\RoomSettingsPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Realtime\RecordingBroadcaster;

/*
|--------------------------------------------------------------------------
| Diffusion anti-rebondie de l'état de lobby — spec 60 § 11.2, contrat C7
| § 2.5 ; spec 50 § 8.3 (lot L60-4)
|--------------------------------------------------------------------------
|
| `BroadcastLobbyState` coalesce, par salon, la diffusion `settings.changed`
| d'une rafale d'écritures de réglages : unique jusqu'à son traitement, sur
| la file `game`, il relit l'état au moment d'émettre.
|
| La file est la vraie file `database` du framework, dépilée comme un worker
| le ferait (`pop()` puis `fire()`, qui relâche le verrou d'unicité avant le
| traitement) : c'est ce qui prouve la coalescence, qu'une file `sync`
| exécuterait à chaque dispatch. Les écritures dispatchent d'elles-mêmes,
| par `UpdateRoomSettings::dispatchLobbyBroadcast()` (L50-2, second temps),
| que ce fichier appelle aussi pour un salon sans écriture ; la preuve du
| délai (INSTANT arrondi à la seconde supérieure, après validation)
| appartient à l'appelant (`RoomSettingsWriteTest`, 50, R-04).
|
*/

/**
 * Un salon au lobby et son hôte.
 *
 * @return array{0: Room, 1: Player}
 */
function lobbyBroadcastRoom(): array
{
    $room = Room::factory()->withSettings(RoomSettings::defaults())->create();
    $host = Player::factory()->for($room)->create();

    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);

    return [$room->refresh(), $host];
}

/** Le dispatch que 50 pose après chaque écriture de réglages (§ 8.3). */
function lobbyBroadcastDispatch(Room $room): void
{
    UpdateRoomSettings::dispatchLobbyBroadcast($room, Date::now()->toImmutable());
}

/**
 * Dépile et exécute, comme un worker `--queue=game`, tous les jobs
 * disponibles de la file `game` ; rend leur nombre.
 */
function lobbyBroadcastWork(): int
{
    $processed = 0;

    while (($job = Queue::connection('database')->pop('game')) instanceof Job) {
        $job->fire();
        $job->delete();
        $processed++;
    }

    return $processed;
}

beforeEach(function (): void {
    config(['queue.default' => 'database']);
    $this->travelTo(CarbonImmutable::parse('2026-09-26 14:05:13.042'));
});

it("une rafale d'écritures de réglages n'émet qu'un settings.changed portant le dernier état", function (): void {
    $recorder = RecordingBroadcaster::install();
    [$room, $host] = lobbyBroadcastRoom();
    [$other] = lobbyBroadcastRoom();

    // Le job : file `game`, unique par salon jusqu'à son traitement, un essai.
    $job = new BroadcastLobbyState($room->id);

    expect($job)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->queue)->toBe('game')
        ->and($job->uniqueId())->toBe($room->id)
        ->and($job->tries)->toBe(1);

    // Une rafale : un curseur glissé, trois écritures immédiates, chacune
    // dispatchant elle-même sa diffusion (L50-2, second temps).
    $defaults = RoomSettings::defaults();
    $burst = [$defaults->roundsCount + 1, $defaults->roundsCount + 2, $defaults->roundsCount + 3];

    foreach ($burst as $roundsCount) {
        $outcome = app(UpdateRoomSettings::class)->handle($room, $host, ['roundsCount' => $roundsCount]);

        expect($outcome->isWritten())->toBeTrue();
    }

    // Un autre salon n'est jamais coalescé avec le premier.
    lobbyBroadcastDispatch($other);

    // Un seul job par salon, sur la file `game` ; rien n'est encore parti.
    expect(DB::table('jobs')->where('queue', 'game')->count())->toBe(2)
        ->and(DB::table('jobs')->where('queue', '<>', 'game')->count())->toBe(0)
        ->and(lobbyBroadcastWork())->toBe(0)
        ->and($recorder->sent)->toBe([]);

    // La fenêtre passée, le job part, et relit l'état AU MOMENT D'ÉMETTRE.
    $this->travel(PlatformLimits::lobbyBroadcastDebounceMs() + 1000)->milliseconds();

    expect(lobbyBroadcastWork())->toBe(2);

    $mine = array_values(array_filter(
        $recorder->sent,
        static fn (array $sent): bool => $sent['channels'] === ['presence-'.ChannelNames::room($room)],
    ));

    expect($recorder->sent)->toHaveCount(2)
        ->and($mine)->toHaveCount(1)
        ->and($mine[0]['event'])->toBe('settings.changed')
        ->and($mine[0]['payload']['gameRef'])->toBeNull()
        ->and($mine[0]['payload']['settings']['roundsCount'])->toBe(end($burst));

    $state = RoomSettingsPresenter::state($room->refresh(), Date::now()->toImmutable());
    $wire = json_decode((string) json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_diff_key($mine[0]['payload'], array_flip(['v', 'serverNow', 'gameRef'])))->toBe($wire);

    // Le verrou est relâché au début du traitement : l'écriture suivante
    // dispatche un nouveau job (cohérence finale, sans révision stockée).
    app(UpdateRoomSettings::class)->handle($room, $host, ['roundsCount' => $defaults->roundsCount]);

    expect(DB::table('jobs')->where('queue', 'game')->count())->toBe(1);

    $this->travel(PlatformLimits::lobbyBroadcastDebounceMs() + 1000)->milliseconds();

    expect(lobbyBroadcastWork())->toBe(1)
        ->and($recorder->sent)->toHaveCount(3)
        ->and($recorder->sent[2]['payload']['settings']['roundsCount'])->toBe($defaults->roundsCount);

    // Un dispatch dont la transaction est annulée ne part pas, et ne garde
    // pas le verrou : le dispatch suivant passe.
    try {
        DB::transaction(function () use ($room): void {
            lobbyBroadcastDispatch($room);

            throw new RuntimeException('annulée');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('jobs')->count())->toBe(0);

    lobbyBroadcastDispatch($room);

    expect(DB::table('jobs')->count())->toBe(1);
});

it("BroadcastLobbyState ne diffuse rien si le salon n'est plus au lobby", function (): void {
    $recorder = RecordingBroadcaster::install();
    [$room] = lobbyBroadcastRoom();

    // Partie lancée : les réglages sont figés, aucun état de lobby ne part.
    $room->forceFill(['status' => RoomStatus::Playing, 'launched_at' => Date::now()])->save();
    (new BroadcastLobbyState($room->id))->handle();

    // Salon archivé.
    $room->forceFill(['status' => RoomStatus::Archived, 'room_code_active' => null, 'archived_at' => Date::now()])->save();
    (new BroadcastLobbyState($room->id))->handle();

    // Salon disparu.
    $gone = $room->id;
    Player::query()->where('room_id', $gone)->delete();
    Room::query()->whereKey($gone)->delete();
    (new BroadcastLobbyState($gone))->handle();

    expect($recorder->attempts)->toBe(0)
        ->and($recorder->sent)->toBe([]);

    // Témoin : un salon au lobby diffuse.
    [$lobby] = lobbyBroadcastRoom();
    (new BroadcastLobbyState($lobby->id))->handle();

    expect($recorder->sent)->toHaveCount(1)
        ->and($recorder->sent[0]['event'])->toBe('settings.changed');
});
