<?php

use App\Actions\Game\RecordHeartbeat;
use App\Actions\Room\ArchiveRoom;
use App\Enums\GamePlayerStatus;
use App\Enums\PlayerConnectionState;
use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Enums\RoomStatus;
use App\Events\Game\RoomArchived;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\Game\SweepSeatPresence;
use App\Jobs\Room\ArchiveIdleRooms;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\PurgeRun;
use App\Models\Room;
use App\Support\Identity\PlayerToken;
use App\Support\Ops\Heartbeat;
use App\Support\Realtime\ChannelNames;
use App\Support\Room\RoomExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Game\PresenceFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;

/*
|--------------------------------------------------------------------------
| Échéances du salon — spec 50 § 16 ; 10 § 6.2 et § 11.1 (lot L50-8)
|--------------------------------------------------------------------------
|
| Deux échéances, comptées depuis la dernière activité : l'archivage anticipé
| du lobby jamais lancé (`RoomExpiry::LOBBY_IDLE_MINUTES`, périmètre
| `stale_lobby`, une ligne `purge_run` par passage) et l'archivage
| (`RoomExpiry::ROOM_IDLE_MINUTES`). Un seul chemin, `ArchiveRoom` : critère
| relu sous verrou, identifiants d'invité effacés dans la transaction de
| l'archivage, code recyclé, aucune ligne supprimée, `room.archived` après
| validation. Le balayage part sur la file `default`, jamais `game`, et
| s'exécute ici comme le worker l'exécute (`handle()` résolu par le
| conteneur). Horloge figée à la seconde : les colonnes pilotes sont à la
| seconde. Aucune durée en littéral : tout se lit sur `RoomExpiry`.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->now = CarbonImmutable::parse('2026-09-27 10:00:00');
    Date::setTestNow($this->now);
});

/** Un passage du balayage, comme le worker `default` l'exécute. */
function roomArchiveSweep(): void
{
    app()->call([new ArchiveIdleRooms, 'handle']);
}

/**
 * Un salon au lobby dont l'hôte est tenu par un jeton, dernière activité à
 * `$lastActivityAt`, lancé une fois ou jamais.
 *
 * @return array{0: Room, 1: Player, 2: PlayerToken}
 */
function roomArchiveRoom(CarbonImmutable $lastActivityAt, bool $launched = false): array
{
    [$room, $host, $token] = HostGestures::room();

    Room::query()->whereKey($room->id)->update([
        'launched_at' => $launched ? $lastActivityAt->subHour() : null,
        'last_activity_at' => $lastActivityAt,
    ]);

    return [$room->refresh(), $host->refresh(), $token];
}

/**
 * Les colonnes d'identité d'un siège, brutes.
 *
 * @return array{nickname: mixed, nickname_normalized: mixed, player_token_hash: mixed}
 */
function roomArchiveIdentity(Player $seat): array
{
    $row = DB::table('player')->where('id', $seat->id)->first(['nickname', 'nickname_normalized', 'player_token_hash']);

    expect($row)->not->toBeNull();

    return [
        'nickname' => $row?->nickname,
        'nickname_normalized' => $row?->nickname_normalized,
        'player_token_hash' => $row?->player_token_hash,
    ];
}

/** Table écrite par une requête d'écriture, `null` pour une lecture. */
function roomArchiveWrittenTable(string $sql): ?string
{
    return preg_match('/^\s*(?:insert\s+into|update|delete\s+from)\s+[`"]?(\w+)/i', $sql, $match) === 1 ? $match[1] : null;
}

/**
 * Les lignes `purge_run` écrites, dans l'ordre.
 *
 * @return list<PurgeRun>
 */
function roomArchiveRuns(): array
{
    return array_values(PurgeRun::query()->orderBy('id')->get()->all());
}

test('archive un salon au-delà de l\'échéance d\'inactivité et libère son code actif', function (): void {
    $recorder = RecordingBroadcaster::install();
    $deadline = RoomExpiry::roomIdleBefore($this->now);

    // Lancé une fois, revenu au lobby, une seconde au-delà de l'échéance.
    [$due, $host] = roomArchiveRoom($deadline->subSecond(), launched: true);
    [$guest] = HostGestures::seat($due);
    // Podium laissé ouvert : salon en `playing`, dernière partie figée.
    [$podium] = roomArchiveRoom($deadline->subHours(3), launched: true);
    Room::query()->whereKey($podium->id)->update(['status' => RoomStatus::Playing->value]);
    Game::factory()->forRoom($podium)->completed()->create(['started_at' => $deadline->subHours(4)]);
    // À l'échéance exacte, puis une seconde avant elle : conservés.
    [$edge] = roomArchiveRoom($deadline, launched: true);
    [$fresh] = roomArchiveRoom($deadline->addSecond(), launched: true);

    $code = $due->room_code;
    $podiumCode = $podium->room_code;

    // Avant l'archivage, le créneau d'unicité refuse un second salon actif
    // portant ce code.
    expect(fn () => DB::transaction(static fn () => Room::factory()->create(['room_code' => $code, 'room_code_active' => $code])))
        ->toThrow(UniqueConstraintViolationException::class);

    roomArchiveSweep();

    foreach ([$due, $podium] as $room) {
        $room->refresh();

        expect($room->status)->toBe(RoomStatus::Archived)
            ->and($room->archived_at?->equalTo($this->now))->toBeTrue()
            ->and($room->room_code_active)->toBeNull()
            ->and($room->host_player_id)->toBeNull();
    }

    // Le code permanent reste, pour l'historique : seul le créneau actif est
    // libéré.
    expect($due->room_code)->toBe($code)
        ->and($podium->room_code)->toBe($podiumCode);

    foreach ([$edge, $fresh] as $room) {
        $before = $room->getAttributes();
        $room->refresh();

        expect($room->status)->toBe(RoomStatus::Lobby)
            ->and($room->archived_at)->toBeNull()
            ->and($room->room_code_active)->toBe($room->room_code)
            ->and($room->host_player_id)->toBe($before['host_player_id']);
    }

    // Le code est libéré : un salon actif peut désormais le porter, et c'est
    // lui que le créneau désigne.
    $recycled = Room::factory()->create(['room_code' => $code, 'room_code_active' => $code]);

    expect(Room::query()->where('room_code_active', $code)->sole()->is($recycled))->toBeTrue();

    // Les sièges restent des lignes, identités effacées.
    foreach ([$host, $guest] as $seat) {
        expect(roomArchiveIdentity($seat))->toBe(['nickname' => null, 'nickname_normalized' => null, 'player_token_hash' => null]);
    }

    // L'archivage à 24 h est une action, pas une purge : seule la ligne du
    // périmètre `stale_lobby` est écrite, sans salon archivé (les deux
    // salons étaient déjà lancés).
    $runs = roomArchiveRuns();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->scope)->toBe(PurgeScope::StaleLobby)
        ->and($runs[0]->rows_deleted)->toBe(0);

    // `room.archived`, une fois par salon archivé, et rien d'autre : le rôle
    // d'hôte vidé n'a personne à prévenir.
    expect(array_column($recorder->sent, 'event'))->toBe(['room.archived', 'room.archived'])
        ->and(array_column($recorder->sent, 'channels'))->toEqualCanonicalizing([
            ['presence-'.ChannelNames::room($due)],
            ['presence-'.ChannelNames::room($podium)],
        ]);
});

test('efface pseudo, forme normalisée, hash du jeton et pseudo gelé dans une seule transaction', function (): void {
    [$room, $host] = roomArchiveRoom(RoomExpiry::roomIdleBefore($this->now)->subMinute(), launched: true);
    [$left] = HostGestures::seat($room, 20, ['connection_state' => PlayerConnectionState::Left, 'left_at' => $this->now->subDay()]);
    [$kicked] = HostGestures::seat($room, 15, [
        'connection_state' => PlayerConnectionState::Left,
        'left_at' => $this->now->subDay(),
    ]);
    Player::query()->whereKey($kicked->id)->update(['kicked_at' => $this->now->subDay()]);

    // Deux parties figées du salon, participations gelées depuis les sièges.
    foreach ([3, 2] as $daysAgo) {
        $game = Game::factory()->forRoom($room)->completed()->create([
            'started_at' => $this->now->subDays($daysAgo),
            'ended_at' => $this->now->subDays($daysAgo)->addHour(),
        ]);

        foreach ([$host, $left, $kicked] as $seat) {
            GamePlayer::factory()->for($game)->frozenFrom($seat)->create(['status' => GamePlayerStatus::Playing]);
        }
    }

    // Témoins : un autre salon, actif, et un siège solo.
    [$active, $activeHost] = roomArchiveRoom($this->now->subMinute(), launched: true);
    $activeGame = Game::factory()->forRoom($active)->completed()->create(['started_at' => $this->now->subHour()]);
    GamePlayer::factory()->for($activeGame)->frozenFrom($activeHost)->create();
    $solo = Player::factory()->solo()->create();

    $witnesses = [
        'active' => roomArchiveIdentity($activeHost),
        'solo' => roomArchiveIdentity($solo),
        'frozen' => GamePlayer::query()->where('game_id', $activeGame->id)->value('display_nickname'),
    ];

    expect($witnesses['active']['nickname'])->not->toBeNull()
        ->and($witnesses['solo']['player_token_hash'])->not->toBeNull()
        ->and($witnesses['frozen'])->not->toBeNull();

    $seats = [$host, $left, $kicked];
    $public = collect($seats)->mapWithKeys(static fn (Player $seat): array => [$seat->id => [
        $seat->public_id, $seat->avatar_preset, $seat->locale->value, $seat->refresh()->connection_state,
    ]])->all();

    // Trace des écritures, numérotées par transaction de premier niveau de
    // l'application (au-dessus de celle du test).
    $base = DB::transactionLevel();
    $transaction = 0;
    $writes = [];
    Event::listen(TransactionBeginning::class, static function () use (&$transaction, $base): void {
        if (DB::transactionLevel() === $base + 1) {
            $transaction++;
        }
    });
    DB::listen(static function (QueryExecuted $query) use (&$writes, &$transaction, $base): void {
        $table = roomArchiveWrittenTable($query->sql);

        if ($table !== null && $table !== 'purge_run') {
            $writes[] = ['table' => $table, 'sql' => $query->sql, 'transaction' => $transaction, 'inside' => DB::transactionLevel() > $base];
        }
    });

    roomArchiveSweep();

    // Les quatre effacements, et rien d'autre de ces colonnes.
    foreach ($seats as $seat) {
        expect(roomArchiveIdentity($seat))->toBe(['nickname' => null, 'nickname_normalized' => null, 'player_token_hash' => null]);

        $seat->refresh();

        expect([$seat->public_id, $seat->avatar_preset, $seat->locale->value, $seat->connection_state])->toBe($public[$seat->id]);
    }

    expect(GamePlayer::query()->whereIn('game_id', Game::query()->select('id')->where('room_id', $room->id))->count())->toBe(6)
        ->and(GamePlayer::query()->whereIn('game_id', Game::query()->select('id')->where('room_id', $room->id))->whereNotNull('display_nickname')->count())->toBe(0);

    // Les témoins, intacts.
    expect(roomArchiveIdentity($activeHost))->toBe($witnesses['active'])
        ->and(roomArchiveIdentity($solo))->toBe($witnesses['solo'])
        ->and(GamePlayer::query()->where('game_id', $activeGame->id)->value('display_nickname'))->toBe($witnesses['frozen'])
        ->and($active->refresh()->archived_at)->toBeNull();

    // Une seule transaction : salon, sièges et participations.
    $tables = array_values(array_unique(array_column($writes, 'table')));
    sort($tables);

    expect($tables)->toBe(['game_player', 'player', 'room'])
        ->and(array_unique(array_column($writes, 'transaction')))->toHaveCount(1)
        ->and(array_unique(array_column($writes, 'inside')))->toBe([true])
        ->and(collect($writes)->contains(static fn (array $write): bool => $write['table'] === 'player' && str_contains($write['sql'], 'player_token_hash')))->toBeTrue()
        ->and(collect($writes)->contains(static fn (array $write): bool => $write['table'] === 'game_player' && str_contains($write['sql'], 'display_nickname')))->toBeTrue();

    // Atomicité : un échec au dernier effacement annule tout — ni salon
    // archivé, ni pseudo effacé.
    [$other, $otherHost] = roomArchiveRoom(RoomExpiry::roomIdleBefore($this->now)->subMinute(), launched: true);
    $otherGame = Game::factory()->forRoom($other)->completed()->create(['started_at' => $this->now->subDays(2)]);
    GamePlayer::factory()->for($otherGame)->frozenFrom($otherHost)->create();
    $identity = roomArchiveIdentity($otherHost);
    $failing = true;
    DB::beforeExecuting(static function (string $query) use (&$failing): void {
        if ($failing && preg_match('/^\s*update\s+[`"]?game_player\b/i', $query) === 1) {
            throw new RuntimeException('échec simulé');
        }
    });
    Log::spy();

    roomArchiveSweep();

    $other->refresh();

    expect($other->archived_at)->toBeNull()
        ->and($other->status)->toBe(RoomStatus::Lobby)
        ->and($other->room_code_active)->toBe($other->room_code)
        ->and($other->host_player_id)->toBe($otherHost->id)
        ->and(roomArchiveIdentity($otherHost))->toBe($identity)
        ->and(GamePlayer::query()->where('game_id', $otherGame->id)->value('display_nickname'))->not->toBeNull();

    // L'échec est journalisé par sa classe, jamais par son message.
    Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => ($context['failures'] ?? null) === 1
        && ($context['exception'] ?? null) === RuntimeException::class
        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'simulé'));

    // Levé, le passage suivant archive.
    $failing = false;
    roomArchiveSweep();

    expect($other->refresh()->status)->toBe(RoomStatus::Archived)
        ->and(roomArchiveIdentity($otherHost)['player_token_hash'])->toBeNull();
});

test('archive par anticipation un lobby jamais lancé et journalise stale_lobby', function (): void {
    $recorder = RecordingBroadcaster::install();
    $deadline = RoomExpiry::lobbyIdleBefore($this->now);

    // Jamais lancé, une seconde au-delà de l'échéance anticipée : archivé.
    [$stale, $staleHost] = roomArchiveRoom($deadline->subSecond());
    // Jamais lancé, à l'échéance exacte : conservé.
    [$edge, $edgeHost] = roomArchiveRoom($deadline);
    // Lancé une fois (« Rejouer » l'a ramené au lobby), inactif depuis plus
    // longtemps que l'échéance anticipée mais moins que 24 h : conservé.
    [$replayed] = roomArchiveRoom($deadline->subHour(), launched: true);

    // Les salons que l'action verrouille : la sélection de la passe ne
    // retient que les lobbies jamais lancés, strictement échus.
    $locked = [];
    DB::listen(static function (QueryExecuted $query) use (&$locked): void {
        if (preg_match('/^\s*select\s+\*\s+from\s+"room"\s+where\s+"room"\."id"\s*=\s*\?\s+limit\s+1/i', $query->sql) === 1) {
            $locked[] = $query->bindings[0] ?? null;
        }
    });

    roomArchiveSweep();

    expect($locked)->toBe([$stale->id]);

    $stale->refresh();

    expect($stale->status)->toBe(RoomStatus::Archived)
        ->and($stale->archived_at?->equalTo($this->now))->toBeTrue()
        ->and($stale->room_code_active)->toBeNull()
        ->and($stale->host_player_id)->toBeNull()
        ->and(roomArchiveIdentity($staleHost))->toBe(['nickname' => null, 'nickname_normalized' => null, 'player_token_hash' => null]);

    foreach ([$edge, $replayed] as $room) {
        expect($room->refresh()->status)->toBe(RoomStatus::Lobby)
            ->and($room->archived_at)->toBeNull()
            ->and($room->room_code_active)->toBe($room->room_code);
    }

    expect(roomArchiveIdentity($edgeHost)['nickname'])->not->toBeNull();

    // Relu sous verrou : l'archivage anticipé ne touche jamais un salon déjà
    // lancé, même appelé sur lui.
    expect(app(ArchiveRoom::class)->handle($replayed, $deadline, lobbyOnly: true))->toBeFalse()
        ->and($replayed->refresh()->archived_at)->toBeNull();

    // La ligne du périmètre, écrite par le balayage.
    $runs = roomArchiveRuns();

    expect($runs)->toHaveCount(1);

    $run = $runs[0];

    expect($run->scope)->toBe(PurgeScope::StaleLobby)
        ->and($run->status)->toBe(PurgeRunStatus::Completed)
        ->and($run->rows_deleted)->toBe(1)
        ->and($run->batches)->toBe(1)
        ->and($run->error)->toBeNull()
        ->and($run->started_at->equalTo($this->now))->toBeTrue()
        ->and($run->ran_at->equalTo($this->now))->toBeTrue()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0);

    expect(array_column($recorder->sent, 'event'))->toBe(['room.archived'])
        ->and($recorder->sent[0]['channels'])->toBe(['presence-'.ChannelNames::room($stale)]);

    // Passage suivant : le lobby resté à l'échéance exacte est désormais
    // échu. Lots bornés, du plus ancien au plus récent, curseur compris : plus
    // d'un lot de lobbies échus, trois par seconde de dernière activité.
    Date::setTestNow($this->now->addMinutes(RoomExpiry::SWEEP_EVERY_MINUTES));
    $later = Date::now()->toImmutable();
    $created = [];

    foreach (range(1, RoomExpiry::BATCH_SIZE + 2) as $index) {
        $created[] = Room::factory()->create([
            'launched_at' => null,
            'last_activity_at' => RoomExpiry::lobbyIdleBefore($later)->subMinutes(intdiv($index, 3) + 1),
        ]);
    }

    $expected = collect([$edge->refresh(), ...$created])
        ->sortBy([
            static fn (Room $a, Room $b): int => $a->last_activity_at <=> $b->last_activity_at,
            static fn (Room $a, Room $b): int => $a->id <=> $b->id,
        ])
        ->map(static fn (Room $room): array => ['presence-'.ChannelNames::room($room)])
        ->values()
        ->all();
    $recorder->sent = [];

    roomArchiveSweep();

    $second = roomArchiveRuns()[1];

    expect($second->rows_deleted)->toBe(count($expected))
        ->and(count($expected))->toBeGreaterThan(RoomExpiry::BATCH_SIZE)
        ->and($second->batches)->toBe(2)
        ->and($second->status)->toBe(PurgeRunStatus::Completed)
        ->and($edge->refresh()->status)->toBe(RoomStatus::Archived)
        ->and($replayed->refresh()->status)->toBe(RoomStatus::Lobby)
        // Dans l'ordre de la dernière activité, puis de l'identifiant.
        ->and(array_column($recorder->sent, 'channels'))->toBe($expected);
});

test('journalise stale_lobby à chaque passage du balayage, même sans salon archivé', function (): void {
    // Aucun salon : une ligne quand même.
    roomArchiveSweep();

    // Un salon actif, rien d'échu : une ligne de plus, au passage suivant.
    roomArchiveRoom($this->now);
    Date::setTestNow($this->now->addMinutes(RoomExpiry::SWEEP_EVERY_MINUTES));
    roomArchiveSweep();

    $runs = roomArchiveRuns();

    expect($runs)->toHaveCount(2);

    foreach ($runs as $index => $run) {
        expect($run->scope)->toBe(PurgeScope::StaleLobby)
            ->and($run->status)->toBe(PurgeRunStatus::Completed)
            ->and($run->rows_deleted)->toBe(0)
            ->and($run->batches)->toBe(0)
            ->and($run->error)->toBeNull()
            ->and($run->finished_at)->not->toBeNull()
            ->and($run->ran_at->equalTo($this->now->addMinutes($index * RoomExpiry::SWEEP_EVERY_MINUTES)))->toBeTrue();
    }

    // Un salon en échec n'empêche ni la ligne ni les autres salons : ligne
    // `completed`, compte et classe de l'échec, jamais son message.
    $now = Date::now()->toImmutable();
    [$failing] = roomArchiveRoom(RoomExpiry::lobbyIdleBefore($now)->subMinutes(2));
    [$archived] = roomArchiveRoom(RoomExpiry::lobbyIdleBefore($now)->subMinute());
    $inject = true;
    DB::beforeExecuting(static function (string $query, array $bindings) use (&$inject, $failing): void {
        if ($inject && preg_match('/^\s*update\s+[`"]?player\b/i', $query) === 1 && in_array($failing->id, $bindings, true)) {
            throw new RuntimeException('valeur secrète');
        }
    });
    Log::spy();

    roomArchiveSweep();

    $run = roomArchiveRuns()[2];

    expect($run->status)->toBe(PurgeRunStatus::Completed)
        ->and($run->rows_deleted)->toBe(1)
        ->and($run->error)->toBe('1 salon(s) en échec ; dernier : RuntimeException (code 0)')
        ->and($failing->refresh()->archived_at)->toBeNull()
        ->and($archived->refresh()->archived_at)->not->toBeNull();

    // Une panne hors d'un salon (lecture du lot) : la ligne est écrite
    // `failed`, jamais absente ; la passe des 24 h tourne quand même.
    $inject = false;
    $interruptOnce = true;
    DB::beforeExecuting(static function (string $query) use (&$interruptOnce): void {
        if ($interruptOnce && preg_match('/^\s*select\s+"id",\s*"last_activity_at"\s+from\s+"room"/i', $query) === 1) {
            $interruptOnce = false;

            throw new RuntimeException('lecture impossible');
        }
    });
    [$daily] = roomArchiveRoom(RoomExpiry::roomIdleBefore($now)->subMinute(), launched: true);

    roomArchiveSweep();

    $run = roomArchiveRuns()[3];

    expect($run->scope)->toBe(PurgeScope::StaleLobby)
        ->and($run->status)->toBe(PurgeRunStatus::Failed)
        ->and($run->finished_at)->toBeNull()
        ->and($run->duration_ms)->toBeNull()
        ->and($run->error)->toBe('Passage interrompu : RuntimeException (code 0)')
        ->and($failing->refresh()->archived_at)->toBeNull()
        ->and($daily->refresh()->status)->toBe(RoomStatus::Archived);
});

test('ne touche pas un salon dont un joueur bat encore', function (): void {
    Queue::fake([SweepSeatPresence::class]);
    $recorder = RecordingBroadcaster::install();

    // Deux lobbies jamais lancés, à la même dernière activité inscrite, bien
    // au-delà des deux échéances ; dans le premier, l'hôte bat encore.
    $old = RoomExpiry::roomIdleBefore($this->now)->subHour();
    [$beating, $beatingHost, $beatingToken] = roomArchiveRoom($old);
    [$silent] = roomArchiveRoom($old);

    PresenceFixtures::beat($this, $beating, $beatingToken, $this->now->subSeconds(30))->assertNoContent();
    Date::setTestNow($this->now);

    // Course : un troisième lobby, sélectionné par le balayage, reçoit un
    // battement validé entre la sélection et le verrou de l'archivage.
    [$raced, $racedHost] = roomArchiveRoom($old->addMinute());
    $armed = true;
    DB::beforeExecuting(static function (string $query, array $bindings) use (&$armed, $raced, $racedHost): void {
        if ($armed
            && preg_match('/^\s*select\s+\*\s+from\s+"room"\s+where\s+"room"\."id"\s*=\s*\?/i', $query) === 1
            && $bindings === [$raced->id]) {
            $armed = false;

            expect(app(RecordHeartbeat::class)->handle(Player::query()->findOrFail($racedHost->id), Room::query()->findOrFail($raced->id)))->toBeTrue();
        }
    });

    $before = [
        'beating' => roomArchiveIdentity($beatingHost),
        'raced' => roomArchiveIdentity($racedHost),
    ];

    roomArchiveSweep();

    expect($armed)->toBeFalse();

    foreach (['beating' => [$beating, $beatingHost], 'raced' => [$raced, $racedHost]] as $label => [$room, $host]) {
        $room->refresh();

        expect($room->status)->toBe(RoomStatus::Lobby, $label)
            ->and($room->archived_at)->toBeNull($label)
            ->and($room->room_code_active)->toBe($room->room_code, $label)
            ->and($room->host_player_id)->toBe($host->id, $label)
            ->and(roomArchiveIdentity($host))->toBe($before[$label], $label);
    }

    // Le salon sans battement, lui, est archivé : le balayage a bien tourné.
    expect($silent->refresh()->status)->toBe(RoomStatus::Archived)
        ->and(roomArchiveRuns()[0]->rows_deleted)->toBe(1)
        ->and(array_column($recorder->sent, 'channels'))->toBe([['presence-'.ChannelNames::room($silent)]]);
});

test('ne supprime jamais une ligne room à l\'archivage', function (): void {
    [$lobby, $lobbyHost] = roomArchiveRoom(RoomExpiry::lobbyIdleBefore($this->now)->subMinute());
    [$launched, $launchedHost] = roomArchiveRoom(RoomExpiry::roomIdleBefore($this->now)->subMinute(), launched: true);
    HostGestures::seat($launched, 5, ['connection_state' => PlayerConnectionState::Left, 'left_at' => $this->now->subDay()]);
    $game = Game::factory()->forRoom($launched)->completed()->create(['started_at' => $this->now->subDays(2)]);
    GamePlayer::factory()->for($game)->frozenFrom($launchedHost)->create();

    $counts = static fn (): array => [
        'room' => DB::table('room')->count(),
        'player' => DB::table('player')->count(),
        'game' => DB::table('game')->count(),
        'game_player' => DB::table('game_player')->count(),
    ];
    $before = $counts();
    $deletes = [];
    DB::listen(static function (QueryExecuted $query) use (&$deletes): void {
        if (preg_match('/^\s*delete\b/i', $query->sql) === 1) {
            $deletes[] = $query->sql;
        }
    });

    roomArchiveSweep();

    expect($deletes)->toBe([])
        ->and($counts())->toBe($before)
        ->and($lobby->refresh()->status)->toBe(RoomStatus::Archived)
        ->and($launched->refresh()->status)->toBe(RoomStatus::Archived)
        ->and(Player::query()->whereKey([$lobbyHost->id, $launchedHost->id])->count())->toBe(2);
});

test('émet room.archived après validation', function (): void {
    $recorder = RecordingBroadcaster::install();
    $archive = app(ArchiveRoom::class);
    $idleBefore = RoomExpiry::roomIdleBefore($this->now);
    $base = DB::transactionLevel();

    // Aucun `host.changed` : vider le rôle n'émet rien.
    [$room] = roomArchiveRoom($idleBefore->subMinute(), launched: true);
    $observed = [];
    Event::listen(RoomArchived::class, static function () use (&$observed, $room, $base): void {
        $observed[] = [DB::transactionLevel() === $base, Room::query()->whereKey($room->id)->value('status')];
    });

    DB::transaction(function () use ($archive, $room, $idleBefore, $recorder): void {
        expect($archive->handle($room, $idleBefore))->toBeTrue()
            ->and($recorder->sent)->toBe([]);
    });

    // Émis une fois la transaction validée, jamais pendant : l'archivage est
    // déjà visible à l'émission.
    expect($observed)->toBe([[true, RoomStatus::Archived]])
        ->and($recorder->sent)->toHaveCount(1);

    $sent = $recorder->sent[0];

    expect($sent['event'])->toBe('room.archived')
        ->and($sent['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and(array_keys($sent['payload']))->toBe(['v', 'serverNow', 'gameRef'])
        ->and($sent['payload']['gameRef'])->toBeNull()
        ->and($sent['json'])->not->toContain($room->room_code);

    // Une transaction annulée n'émet rien, et n'archive rien.
    [$rolledBack] = roomArchiveRoom($idleBefore->subMinute(), launched: true);

    try {
        DB::transaction(function () use ($archive, $rolledBack, $idleBefore): void {
            expect($archive->handle($rolledBack, $idleBefore))->toBeTrue();

            throw new RuntimeException('annulée');
        });
    } catch (RuntimeException) {
    }

    expect($recorder->sent)->toHaveCount(1)
        ->and($rolledBack->refresh()->archived_at)->toBeNull();

    // Un salon qui n'est pas échu, ou déjà archivé : `false`, rien d'émis.
    [$fresh] = roomArchiveRoom($idleBefore);

    expect($archive->handle($fresh, $idleBefore))->toBeFalse()
        ->and($archive->handle($room, $idleBefore))->toBeFalse()
        ->and($recorder->sent)->toHaveCount(1)
        ->and($fresh->refresh()->archived_at)->toBeNull();
});

test('affiche la page de salon expiré en 410 pour un lien archivé', function (): void {
    [$room, $host, $token] = roomArchiveRoom(RoomExpiry::lobbyIdleBefore($this->now)->subMinute());
    $code = $room->room_code;

    // Avant l'archivage, le porteur du siège entre dans le lobby.
    LobbyWrites::actAs($this, $token);
    $this->get(route('room.show', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('game/lobby'));

    roomArchiveSweep();

    expect($room->refresh()->status)->toBe(RoomStatus::Archived);

    // Le même lien, saisi tel quel ou à la main (casse, tiret) : 410, la
    // page « salon expiré », et aucune information sur le salon.
    $variants = [
        route('room.show', $room),
        '/r/'.strtolower(substr($code, 0, 3)).'-'.strtolower(substr($code, 3)),
    ];

    foreach ($variants as $url) {
        LobbyWrites::actAs($this, $token);
        $response = $this->get($url);

        $response->assertStatus(Response::HTTP_GONE)
            ->assertInertia(fn (Assert $page) => $page->component('game/room-expired')
                ->missing('room')
                ->missing('state')
                ->missing('seatToken')
                ->missing('settings'));

        expect((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex');
    }

    // La visite du lobby encore ouvert (`room.archived` → `room.show`) :
    // réponse Inertia en 410, que le client rend comme une page.
    LobbyWrites::actAs($this, $token);
    $visit = $this->get(route('room.show', $room), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
    ]);

    $visit->assertStatus(Response::HTTP_GONE)
        ->assertHeader('X-Inertia', 'true');

    $props = array_keys((array) $visit->json('props'));

    expect($visit->json('component'))->toBe('game/room-expired')
        ->and($props)->not->toContain('room')
        ->and($props)->not->toContain('state')
        ->and($props)->not->toContain('seatToken');

    // Le jeton d'hier ne tient plus aucun siège : la page d'entrée et
    // l'entrée mènent aussi à « salon expiré ».
    $this->get(route('room.entry', $room))->assertRedirect(route('room.show', $room));

    // Recyclage : un salon actif reçoit le même code ; le vieux lien mène
    // désormais à lui (résidu assumé, § 6.3), jamais à l'archivé.
    $recycled = Room::factory()->create(['room_code' => $code, 'room_code_active' => $code]);

    $this->get(route('room.show', $recycled))
        ->assertRedirect(route('room.entry', $recycled));
});

test('balaie sur la file default et jamais sur la file game', function (): void {
    $job = new ArchiveIdleRooms;

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe(ArchiveIdleRooms::QUEUE)
        ->and(ArchiveIdleRooms::QUEUE)->toBe(Heartbeat::DEFAULT)
        ->and($job->connection)->toBeNull()
        // Verrou d'unicité strictement sous la cadence (un verrou orphelin est
        // expiré au dépôt suivant) et jamais nul (il n'expirerait pas).
        ->and($job->uniqueFor)->toBeGreaterThan(0)
        ->and($job->uniqueFor)->toBeLessThan(RoomExpiry::SWEEP_EVERY_MINUTES * 60);

    // Planifiée par la commande, à la cadence de `RoomExpiry`, jamais en
    // littéral.
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'room:archive-idle'),
    ));

    expect($events)->toHaveCount(1)
        ->and(60 % RoomExpiry::SWEEP_EVERY_MINUTES)->toBe(0)
        ->and($events[0]->expression)->toBe(RoomExpiry::sweepCron())
        ->and($events[0]->expression)->toBe('*/'.RoomExpiry::SWEEP_EVERY_MINUTES.' * * * *');

    // La commande dépose le balayage sur la file `default` de la connexion
    // par défaut ; un balayage déjà en file n'est pas doublé.
    Queue::fake();

    $this->artisan('room:archive-idle')->assertSuccessful();
    $this->artisan('room:archive-idle')->assertSuccessful();

    Queue::assertPushedOn(ArchiveIdleRooms::QUEUE, ArchiveIdleRooms::class, static fn (ArchiveIdleRooms $pushed): bool => $pushed->connection === null);
    Queue::assertPushed(ArchiveIdleRooms::class, 1);
    Queue::assertNotPushed(ArchiveIdleRooms::class, static fn (ArchiveIdleRooms $pushed, ?string $queue): bool => $queue === Heartbeat::GAME);

    // Le balayage lui-même ne dépose rien, ni sur `game` ni ailleurs : la
    // diffusion `room.archived` part sans file.
    Queue::fake();
    roomArchiveRoom(RoomExpiry::lobbyIdleBefore($this->now)->subMinute());
    roomArchiveRoom(RoomExpiry::roomIdleBefore($this->now)->subMinute(), launched: true);

    roomArchiveSweep();

    Queue::assertNothingPushed();
    expect(Room::query()->where('status', RoomStatus::Archived->value)->count())->toBe(2);
});

test('refuse d\'archiver un salon dont la dernière partie n\'est pas figée, sans bloquer le balayage', function (): void {
    Log::spy();
    $idleBefore = RoomExpiry::roomIdleBefore($this->now);

    // Un lot entier de salons que l'action refuse (défensif : partie jamais
    // figée), plus un salon échu derrière eux : le curseur les dépasse.
    $refused = [];

    foreach (range(1, RoomExpiry::BATCH_SIZE) as $index) {
        [$room] = roomArchiveRoom($idleBefore->subHours(2)->addSeconds($index), launched: true);
        Game::factory()->forRoom($room)->create(['started_at' => $idleBefore->subHours(3)]);
        $refused[] = $room;
    }

    [$due, $dueHost] = roomArchiveRoom($idleBefore->subMinute(), launched: true);

    roomArchiveSweep();

    expect(Room::query()->whereKey(array_map(static fn (Room $room): int => $room->id, $refused))->whereNull('archived_at')->count())->toBe(RoomExpiry::BATCH_SIZE)
        ->and($due->refresh()->status)->toBe(RoomStatus::Archived)
        ->and(roomArchiveIdentity($dueHost)['nickname'])->toBeNull();

    $sample = $refused[0];
    $game = Game::query()->where('room_id', $sample->id)->sole();

    Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => ($context['room_id'] ?? null) === $sample->id
        && ($context['game_id'] ?? null) === $game->id);
});
