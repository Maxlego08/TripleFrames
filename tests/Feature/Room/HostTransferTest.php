<?php

use App\Actions\Room\HandOverHost;
use App\Actions\Room\LaunchGame;
use App\Actions\Room\TransferHost;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomRefusal;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Player;
use App\Models\Room;
use App\Support\I18n\LocaleCookie;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\WirePayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Transfert du rôle d'hôte — spec 50 § 11.1, § 11.2 et § 11.4 (lot L50-6)
|--------------------------------------------------------------------------
|
| L'hôte est un siège. Le rôle passe AUTOMATIQUEMENT au départ de l'hôte —
| jamais à sa déconnexion — au siège connecté le plus ancien, et une lecture
| qui ne trouve pas de cible valide le répare au lieu de lever. L'hôte peut
| aussi le confier à un siège connecté. L'ancien hôte ne récupère jamais le
| rôle de lui-même. Tout changement effectif émet `host.changed` avec les
| deux `public_id`, après la validation.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-27 17:12:40.618');
    $this->travelTo($this->now);
});

/** Le siège hôte du salon, relu en base, ou `null`. */
function transferHostOf(Room $room): ?int
{
    $hostId = Room::query()->whereKey($room->id)->value('host_player_id');

    return is_int($hostId) ? $hostId : null;
}

/**
 * Les `host.changed` enregistrés, charge hors enveloppe.
 *
 * @return list<array{hostPublicId: string, previousHostPublicId: string|null}>
 */
function transferHostChanges(RecordingBroadcaster $recorder): array
{
    return array_values(array_map(
        static fn (array $sent): array => [
            'hostPublicId' => $sent['payload']['hostPublicId'],
            'previousHostPublicId' => $sent['payload']['previousHostPublicId'],
        ],
        array_filter($recorder->sent, static fn (array $sent): bool => $sent['event'] === 'host.changed'),
    ));
}

it("transfère le rôle au joueur connecté le plus ancien quand l'hôte part", function (): void {
    [$room, $host, $hostToken] = HostGestures::room();
    // Plus anciens que le successeur, mais pas des cibles : un siège expulsé,
    // un siège parti, un siège déconnecté.
    [$kicked] = HostGestures::seat($room, 25, ['connection_state' => PlayerConnectionState::Left, 'left_at' => Date::now(), 'kicked_at' => Date::now()]);
    [$left] = HostGestures::seat($room, 24, ['connection_state' => PlayerConnectionState::Left, 'left_at' => Date::now()]);
    [$disconnected] = HostGestures::seat($room, 23, ['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()]);
    [$oldest] = HostGestures::seat($room, 20);
    [$younger] = HostGestures::seat($room, 5);
    $recorder = RecordingBroadcaster::install();

    HostGestures::leave($this, $room, $hostToken, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('home'));

    expect(transferHostOf($room))->toBe($oldest->id)
        ->and(transferHostChanges($recorder))->toBe([
            ['hostPublicId' => $oldest->public_id, 'previousHostPublicId' => $host->public_id],
        ]);

    // Plus aucun connecté : le déconnecté le plus ancien (il reste hôte tant
    // qu'il n'est pas parti) ; puis personne, sans rien émettre.
    $recorder->sent = [];
    Player::query()->whereKey($younger->id)->update(['connection_state' => PlayerConnectionState::Disconnected->value]);
    DB::transaction(function () use ($room, $oldest, $disconnected): void {
        $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
        Player::query()->whereKey($oldest->id)->update(['connection_state' => PlayerConnectionState::Left->value, 'left_at' => Date::now()]);

        expect(app(TransferHost::class)->automatic($locked, Date::now()->toImmutable())?->id)->toBe($disconnected->id);
    });

    Player::query()->whereIn('id', [$disconnected->id, $younger->id])->update(['connection_state' => PlayerConnectionState::Left->value]);

    DB::transaction(function () use ($room): void {
        $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

        expect(app(TransferHost::class)->automatic($locked, Date::now()->toImmutable()))->toBeNull();
    });

    expect(transferHostOf($room))->toBeNull()
        ->and(transferHostChanges($recorder))->toBe([
            ['hostPublicId' => $disconnected->public_id, 'previousHostPublicId' => $oldest->public_id],
        ])
        ->and($kicked->refresh()->wasKicked())->toBeTrue()
        ->and($left->refresh()->connection_state)->toBe(PlayerConnectionState::Left);
});

it("ne rend pas le rôle à l'ancien hôte qui revient", function (): void {
    [$room, $host, $hostToken] = HostGestures::room();
    [$successor, $successorToken] = HostGestures::seat($room, 20);
    [$other] = HostGestures::seat($room, 10);

    HostGestures::leave($this, $room, $hostToken, $host)->assertRedirect(route('home'));

    expect(transferHostOf($room))->toBe($successor->id);

    // Il revient par le lien : une reprise, sans nouveau siège ; puis le
    // battement de présence le ramène à `connected` (60, ici écrit à la main).
    LobbyWrites::actAs($this, $hostToken);
    $this->post(route('room.join', $room), [])->assertRedirect(route('room.show', $room));

    expect(Player::query()->whereBelongsTo($room)->count())->toBe(3);

    Player::query()->whereKey($host->id)->update([
        'connection_state' => PlayerConnectionState::Connected->value,
        'left_at' => null,
    ]);

    // Aucune lecture ne lui rend le rôle : sa page, l'entrée d'un nouveau
    // venu, un lancement tenté par lui.
    $this->get(route('room.show', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('state.self.isHost', false));

    LobbyWrites::actAs($this, PlayerToken::mint(Locale::French));
    $this->post(route('room.join', $room), SeatEntry::form('Nouvelle'))->assertRedirect(route('room.show', $room));

    expect(app(LaunchGame::class)->handle($room, $host->refresh())->refusal)->toBe(RoomRefusal::NotHost)
        ->and(transferHostOf($room))->toBe($successor->id);

    // Le rôle ne lui revient que vacant, et s'il est alors le plus ancien
    // connecté : le successeur part.
    HostGestures::leave($this, $room, $successorToken, $successor)->assertRedirect(route('home'));

    expect(transferHostOf($room))->toBe($host->id)
        ->and($other->refresh()->connection_state)->toBe(PlayerConnectionState::Connected);
});

it("conserve l'hôte déconnecté tant qu'il n'est pas parti", function (): void {
    [$room, $host] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room, 20);
    Player::query()->whereKey($host->id)->update([
        'connection_state' => PlayerConnectionState::Disconnected->value,
        'disconnected_at' => Date::now(),
    ]);
    $recorder = RecordingBroadcaster::install();

    expect(TransferHost::hasValidHost($room->refresh()))->toBeTrue();

    // La page d'un autre siège, l'entrée d'un nouveau venu, un lancement
    // tenté par le plus ancien connecté : aucun ne transfère.
    LobbyWrites::actAs($this, $guestToken);
    $this->get(route('room.show', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('state.self.isHost', false));

    LobbyWrites::actAs($this, PlayerToken::mint(Locale::French));
    $this->post(route('room.join', $room), SeatEntry::form('Arrivée'))->assertRedirect(route('room.show', $room));

    expect(app(LaunchGame::class)->handle($room, $guest)->refusal)->toBe(RoomRefusal::NotHost);

    // Une heure plus tard, toujours déconnecté : toujours hôte. C'est le
    // passage à `left` (délai de grâce, 60) qui transfère, jamais la coupure.
    $this->travel(1)->hours();
    LobbyWrites::actAs($this, $guestToken);
    $this->get(route('room.show', $room))->assertOk();

    expect(transferHostOf($room))->toBe($host->id)
        ->and(transferHostChanges($recorder))->toBe([]);
});

it('répare un hôte sans cible à la lecture au lieu de lever une erreur', function (): void {
    [$elsewhere] = HostGestures::room();
    [$foreign] = HostGestures::seat($elsewhere, 1);

    $cases = [
        'référence vide' => static fn (Room $room): ?int => null,
        'siège absent' => static fn (Room $room): ?int => (int) Player::query()->max('id') + 1_000,
        'siège parti' => static fn (Room $room): ?int => HostGestures::seat($room, 40, ['connection_state' => PlayerConnectionState::Left, 'left_at' => Date::now()])[0]->id,
        'siège expulsé' => static fn (Room $room): ?int => HostGestures::seat($room, 40, ['connection_state' => PlayerConnectionState::Left, 'left_at' => Date::now(), 'kicked_at' => Date::now()])[0]->id,
        "siège d'un autre salon" => static fn (Room $room): ?int => $foreign->id,
    ];

    foreach ($cases as $label => $dangling) {
        foreach (['page du salon', 'entrée', 'lancement'] as $reader) {
            [$room, $host] = HostGestures::room();
            [$oldest] = HostGestures::seat($room, 20);
            [$viewer, $viewerToken] = HostGestures::seat($room, 10);
            Room::query()->whereKey($room->id)->update(['host_player_id' => $dangling($room)]);
            Player::query()->whereKey($host->id)->update(['connection_state' => PlayerConnectionState::Left->value, 'left_at' => Date::now()]);
            $recorder = RecordingBroadcaster::install();

            // Une lecture sans cible valide transfère, jamais une erreur.
            match ($reader) {
                'page du salon' => (function () use ($room, $viewerToken): void {
                    LobbyWrites::actAs($this, $viewerToken);
                    $this->get(route('room.show', $room))->assertOk();
                })(),
                'entrée' => (function () use ($room): void {
                    LobbyWrites::actAs($this, PlayerToken::mint(Locale::French));
                    $this->post(route('room.join', $room), SeatEntry::form('Relève'))->assertRedirect(route('room.show', $room));
                })(),
                'lancement' => expect(app(LaunchGame::class)->handle($room, $viewer)->refusal)->toBe(RoomRefusal::NotHost),
            };

            expect(transferHostOf($room))->toBe($oldest->id, "{$label}, {$reader}");

            $changes = transferHostChanges($recorder);

            expect($changes)->toHaveCount(1)
                ->and($changes[0]['hostPublicId'])->toBe($oldest->public_id);

            // Le prédécesseur n'est nommé que s'il était un siège de CE salon :
            // jamais le `public_id` d'un siège d'ailleurs.
            if ($label === "siège d'un autre salon" || $label === 'siège absent' || $label === 'référence vide') {
                expect($changes[0]['previousHostPublicId'])->toBeNull("{$label}, {$reader}");
            }
        }
    }
});

it("ne transfère le rôle à la demande de l'hôte qu'à un joueur connecté", function (): void {
    [$room, $host, $hostToken] = HostGestures::room();
    [$connected, $connectedToken] = HostGestures::seat($room, 20);
    [$disconnected] = HostGestures::seat($room, 15, ['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()]);
    [$left] = HostGestures::seat($room, 14, ['connection_state' => PlayerConnectionState::Left, 'left_at' => Date::now()]);
    [$kicked] = HostGestures::seat($room, 13, ['connection_state' => PlayerConnectionState::Left, 'left_at' => Date::now(), 'kicked_at' => Date::now()]);
    [$elsewhere] = HostGestures::room();
    [$foreign] = HostGestures::seat($elsewhere);
    $before = HostGestures::raw('room', $room->id);

    // Déconnecté, parti, expulsé : retour au salon avec l'erreur sous
    // `publicId`, dans la langue de la requête, rien d'écrit.
    foreach ([$disconnected, $left, $kicked] as $target) {
        foreach ([Locale::French, Locale::English] as $locale) {
            HostGestures::transfer($this->withUnencryptedCookie(LocaleCookie::NAME, $locale->value), $room, $hostToken, $host, ['publicId' => $target->public_id])
                ->assertStatus(Response::HTTP_SEE_OTHER)
                ->assertRedirect(LobbyWrites::lobbyUrl($room))
                ->assertSessionHasErrors(['publicId' => trans('room.lobby.transfer_unavailable', [], $locale->value)]);
        }
    }

    expect(fn () => app(HandOverHost::class)->handle($room, $host, $disconnected->public_id))
        ->toThrow(ValidationException::class);

    // Un siège d'un autre salon, ou inconnu : 404 ; un corps sans cible :
    // erreur de validation sous `publicId`.
    HostGestures::transfer($this, $room, $hostToken, $host, ['publicId' => $foreign->public_id])->assertNotFound();
    HostGestures::transfer($this, $room, $hostToken, $host, ['publicId' => 'ZZZZZZZZZZZZ'])->assertNotFound();
    HostGestures::transfer($this, $room, $hostToken, $host, [])
        ->assertStatus(Response::HTTP_FOUND)
        ->assertSessionHasErrors('publicId');

    // Un non-hôte : 403, sa cible fût-elle connectée.
    HostGestures::transfer($this, $room, $connectedToken, $connected, ['publicId' => $connected->public_id])->assertForbidden();

    expect(HostGestures::raw('room', $room->id))->toBe($before);

    // Un siège connecté : le rôle passe, et le geste est une source d'activité.
    $this->travel(3)->seconds();
    HostGestures::transfer($this, $room, $hostToken, $host, ['publicId' => $connected->public_id])
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors();

    expect(transferHostOf($room))->toBe($connected->id)
        ->and($room->refresh()->last_activity_at?->equalTo(Date::now()->startOfSecond()))->toBeTrue();

    // L'ancien hôte n'a plus aucun geste d'hôte.
    HostGestures::transfer($this, $room, $hostToken, $host, ['publicId' => $host->public_id])->assertForbidden();

    // En partie aussi, le rôle se confie (§ 11.1).
    [$playing, $playingHost, $playingToken] = HostGestures::room();
    [$player] = HostGestures::seat($playing, 20);
    HostGestures::runningGame($playing, [$playingHost, $player]);

    HostGestures::transfer($this, $playing, $playingToken, $playingHost, ['publicId' => $player->public_id])
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertSessionHasNoErrors();

    expect(transferHostOf($playing))->toBe($player->id);
});

it("ne fait rien quand l'hôte se désigne lui-même", function (): void {
    [$room, $host, $hostToken] = HostGestures::room();
    HostGestures::seat($room, 20);
    Room::query()->whereKey($room->id)->update(['last_activity_at' => Date::now()->subHour()]);
    $before = HostGestures::raw('room', $room->id);
    $recorder = RecordingBroadcaster::install();
    $this->travel(7)->seconds();

    HostGestures::transfer($this, $room, $hostToken, $host, ['publicId' => $host->public_id])
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors();

    app(HandOverHost::class)->handle($room, $host, $host->public_id);

    // Aucune écriture — pas même l'activité —, aucun message.
    expect(HostGestures::raw('room', $room->id))->toBe($before)
        ->and($recorder->sent)->toBe([]);
});

it('émet host.changed avec les deux public_id après validation', function (): void {
    [$room, $host, $hostToken] = HostGestures::room();
    [$target] = HostGestures::seat($room, 20);
    $recorder = RecordingBroadcaster::install();

    // Transaction annulée : ni écriture, ni message.
    DB::beginTransaction();
    app(HandOverHost::class)->handle($room, $host, $target->public_id);
    expect($recorder->sent)->toBe([]);
    DB::rollBack();

    expect(transferHostOf($room))->toBe($host->id)
        ->and($recorder->attempts)->toBe(0);

    // Validée : rien avant le COMMIT, un seul message après.
    DB::transaction(function () use ($room, $host, $target, $recorder): void {
        app(HandOverHost::class)->handle($room, $host, $target->public_id);

        expect($recorder->sent)->toBe([]);
    });

    expect($recorder->sent)->toHaveCount(1);

    $sent = $recorder->sent[0];

    expect($sent['event'])->toBe('host.changed')
        ->and($sent['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and(array_keys($sent['payload']))->toBe(['v', 'serverNow', 'gameRef', 'hostPublicId', 'previousHostPublicId'])
        ->and($sent['payload']['hostPublicId'])->toBe($target->public_id)
        ->and($sent['payload']['previousHostPublicId'])->toBe($host->public_id)
        ->and($sent['payload']['gameRef'])->toBeNull();

    WirePayload::assertSafe($sent['payload'], $sent['event']);

    // Par la route, en partie : même charge, enveloppe de la partie en cours.
    [$playing, $playingHost, $playingToken] = HostGestures::room();
    [$player] = HostGestures::seat($playing, 20);
    [$game] = HostGestures::runningGame($playing, [$playingHost, $player]);
    $recorder->sent = [];

    HostGestures::transfer($this, $playing, $playingToken, $playingHost, ['publicId' => $player->public_id])
        ->assertStatus(Response::HTTP_SEE_OTHER);

    $changed = collect($recorder->sent)->where('event', 'host.changed')->values();

    expect($changed)->toHaveCount(1)
        ->and($changed[0]['payload']['hostPublicId'])->toBe($player->public_id)
        ->and($changed[0]['payload']['previousHostPublicId'])->toBe($playingHost->public_id)
        ->and($changed[0]['payload']['gameRef'])->toBe(GameRef::for($game));
});
