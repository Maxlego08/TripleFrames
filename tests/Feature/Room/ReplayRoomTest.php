<?php

use App\Actions\Game\FinalizeGame;
use App\Actions\Room\LaunchGame;
use App\Actions\Room\ReplayRoom;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\PoolFault;
use App\Enums\PoolRemedyKind;
use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Http\Controllers\Room\LaunchController;
use App\Http\Controllers\Room\ReplayController;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Support\Deploy\DeployDrain;
use App\Support\Draw\PoolReporter;
use App\Support\Draw\PoolScope;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\WirePayload;
use App\Support\Room\RoomSettingsPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Game\GameJournalRecorder;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| « Rejouer » — spec 50 § 13, contrat C6 (lot L50-7b)
|--------------------------------------------------------------------------
|
| Sur le podium, l'hôte ramène le salon au lobby : une transaction sous le
| verrou du salon — autorité relue (et réparée), partie figée, drainage —,
| le statut et l'activité par mise à jour ciblée, puis `room.replayed` après
| la validation, avec l'état des réglages RECALCULÉ : la non-répétition a
| réduit le vivier. Les réglages redeviennent modifiables, et le lancement
| suivant repasse par la garde de vivier. Un refus est une donnée, rendue
| dans la langue de la requête ; un échec technique annule tout et se rend
| traduit.
|
| Le podium est atteint par le vrai chemin : lancement par `LaunchGame`,
| manches jouées par le moteur à leurs instants théoriques, gel par la fin
| de la dernière révélation (`FinalizeGame`, 80) — ou, quand seule compte la
| partie figée, par l'action de gel directement.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-27 16:20:31.125');
    $this->travelTo($this->now);
});

/**
 * Réglages d'une partie courte : `M` au minimum des bornes, tout le reste au
 * défaut — non-répétition comprise —, par le seul constructeur borné.
 */
function replaySettings(): RoomSettings
{
    return RoomSettings::fromInput(['roundsCount' => Bounds::MIN_ROUNDS_COUNT]);
}

/**
 * Un salon au lobby, son hôte (siège du jeton, onglet actif) et un invité
 * connecté.
 *
 * @return array{0: Room, 1: Player, 2: Player}
 */
function replayRoom(PlayerToken $token, ?RoomSettings $settings = null): array
{
    [$room, $host] = LobbyWrites::hostedRoom($token, $settings ?? replaySettings());
    $guest = Player::factory()->for($room)->create();

    return [$room, $host, $guest];
}

/** La partie lancée par l'action réelle, ou l'échec du test. */
function replayLaunch(Room $room, Player $host): Game
{
    $outcome = app(LaunchGame::class)->handle($room, $host);

    return $outcome->game ?? throw new LogicException('Lancement refusé : '.($outcome->refusal->value ?? 'inconnu').'.');
}

/**
 * Le salon sur son podium par le vrai chemin : partie lancée, ses `M`
 * manches numérotées jouées par le moteur, gelée par la fin de la dernière
 * révélation. Le salon reste en `playing` jusqu'au « Rejouer ».
 */
function replayPlayToPodium(Room $room, Player $host): Game
{
    $game = replayLaunch($room, $host);

    foreach (range(1, $game->rounds_count) as $sequenceIndex) {
        EngineFixtures::play(EngineFixtures::round($game, $sequenceIndex));
    }

    $game->refresh();

    expect($game->ended_at)->not->toBeNull('la fin de la dernière révélation gèle la partie')
        ->and($game->status)->toBe(GameStatus::Completed)
        ->and($room->refresh()->status)->toBe(RoomStatus::Playing);

    return $game;
}

/**
 * Le salon sur son podium, la partie figée par l'action de gel elle-même,
 * sans manche jouée.
 */
function replayFreeze(Game $game): Game
{
    app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, Date::now()->toImmutable());

    return $game->refresh();
}

/**
 * « Rejouer » par la route, depuis la page du salon, onglet actif du siège.
 *
 * @return TestResponse<Response>
 */
function replayPost(TestCase $test, Room $room, Player $seat): TestResponse
{
    return LobbyWrites::send($test, 'POST', route('room.replay', $room), $room, [], $seat);
}

/**
 * Une ligne brute, sans cast : pour prouver qu'un geste n'a rien écrit.
 *
 * @return array<string, mixed>
 */
function replayRaw(string $table, int $id): array
{
    return (array) DB::table($table)->where('id', $id)->first();
}

/**
 * Les sièges bruts du salon, par identifiant.
 *
 * @return list<array<string, mixed>>
 */
function replayRawSeats(Room $room): array
{
    return DB::table('player')->where('room_id', $room->id)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->values()->all();
}

/**
 * Les diffusions enregistrées sous ce nom.
 *
 * @return list<array{channels: list<string>, event: string, payload: array<string, mixed>, json: string}>
 */
function replayBroadcasts(RecordingBroadcaster $recorder, string $event): array
{
    return array_values(array_filter($recorder->sent, static fn (array $sent): bool => $sent['event'] === $event));
}

/**
 * Le message d'un refus, dans la langue de la requête.
 *
 * @param  array<string, int>  $replace
 */
function replayMessage(string $key, Locale $locale, array $replace = []): string
{
    $message = trans($key, $replace, $locale->value);

    expect($message)->toBeString()->not->toBe($key);

    return (string) $message;
}

/**
 * Une valeur telle que le client la reçoit : après un aller-retour JSON.
 */
function replayJson(mixed $value): mixed
{
    return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
}

it('ramène le salon en lobby après le podium et déverrouille les réglages', function (): void {
    $settings = replaySettings();
    PoolFixtures::movies($settings->roundsCount + PlatformLimits::drawSubstituteMargin() + 2);
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = replayRoom($token, $settings);
    $game = replayPlayToPodium($room, $host);
    $launchedAt = $room->refresh()->launched_at?->toDateTimeString();

    // Des sièges dans tous les états : parti, expulsé, entré sans
    // participation (§ 15) — « Rejouer » n'en touche aucun.
    Player::factory()->for($room)->left()->create();
    Player::factory()->for($room)->kicked()->create();
    Player::factory()->for($room)->create();
    LobbyWrites::actAs($this, $token);

    // Sur le podium, les réglages restent figés : l'écriture repart vers la
    // page du salon, sans erreur et sans rien écrire (§ 12.7).
    $frozen = replayRaw('room', $room->id);

    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, ['roundsCount' => $settings->roundsCount + 1], $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    expect(replayRaw('room', $room->id))->toBe($frozen);

    // « Rejouer », par la route.
    $recorder = RecordingBroadcaster::install();
    $seats = replayRawSeats($room);
    $gameRow = replayRaw('game', $game->id);
    $this->travel(7)->seconds();
    $replayedAt = Date::now()->toImmutable()->startOfMillisecond();

    replayPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    // Le salon au lobby, l'activité à l'instant du geste ; ni les réglages,
    // ni l'hôte, ni `launched_at` (premier lancement seulement) ne changent.
    $room->refresh();
    $untouched = ['status', 'last_activity_at', 'updated_at'];

    expect($room->status)->toBe(RoomStatus::Lobby)
        ->and($room->last_activity_at->toDateTimeString())->toBe($replayedAt->toDateTimeString())
        ->and($room->launched_at?->toDateTimeString())->toBe($launchedAt)
        ->and($room->host_player_id)->toBe($host->id)
        ->and($room->settings->equals($settings))->toBeTrue()
        ->and(Arr::except(replayRaw('room', $room->id), $untouched))->toBe(Arr::except($frozen, $untouched));

    // Aucun siège réécrit : les partis restent partis, les expulsés
    // expulsés ; la partie figée reste intacte, à l'octet.
    expect(replayRawSeats($room))->toBe($seats)
        ->and(replayRaw('game', $game->id))->toBe($gameRow);

    // Une seule diffusion, `room.replayed`, au salon : l'état des réglages
    // recalculé à l'instant du geste, en données, hors partie.
    $replayed = replayBroadcasts($recorder, 'room.replayed');

    expect($recorder->sent)->toHaveCount(1)
        ->and($replayed)->toHaveCount(1)
        ->and($replayed[0]['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and($replayed[0]['payload']['gameRef'])->toBeNull()
        ->and(Arr::except($replayed[0]['payload'], ['v', 'serverNow', 'gameRef']))
        ->toBe(replayJson(RoomSettingsPresenter::state($room, $replayedAt)));

    WirePayload::assertSafe($replayed[0]['payload'], 'room.replayed');

    // Les réglages redeviennent modifiables.
    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, ['roundsCount' => $settings->roundsCount + 1], $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors();

    expect($room->refresh()->settings->roundsCount)->toBe($settings->roundsCount + 1)
        ->and($room->rounds_count)->toBe($settings->roundsCount + 1);

    // Double clic, second onglet : déjà fait — aucune écriture, aucune
    // diffusion de plus, aucune erreur.
    $lobbyRow = replayRaw('room', $room->id);

    replayPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    expect(app(ReplayRoom::class)->handle($room, $host))->toBeNull()
        ->and(replayRaw('room', $room->id))->toBe($lobbyRow)
        ->and(replayBroadcasts($recorder, 'room.replayed'))->toHaveCount(1)
        ->and(replayRaw('game', $game->id))->toBe($gameRow);

    // La page du salon rend de nouveau le lobby : la même page, sans partie.
    $this->get(route('room.show', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('game/lobby')->where('state.gameRef', null)->etc());
});

it("refuse game_not_ended tant que la partie n'est pas figée", function (): void {
    $settings = replaySettings();
    PoolFixtures::movies($settings->roundsCount + PlatformLimits::drawSubstituteMargin());

    foreach ([Locale::French, Locale::English] as $locale) {
        $token = PlayerToken::mint($locale);
        [$room, $host] = replayRoom($token, $settings);
        $game = replayLaunch($room, $host);
        $recorder = RecordingBroadcaster::install();
        $before = replayRaw('room', $room->id);
        LobbyWrites::actAs($this, $token);

        // Partie en cours : refus en données, puis traduit pour l'hôte.
        expect(app(ReplayRoom::class)->handle($room, $host))->toBe(RoomRefusal::GameNotEnded);

        replayPost($this, $room, $host)
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(LobbyWrites::lobbyUrl($room))
            ->assertSessionHasErrors(['room' => replayMessage('room.refusal.game_not_ended', $locale)]);

        // Partie en pause : pas davantage figée.
        Game::query()->whereKey($game->id)->update([
            'status' => GameStatus::Paused->value,
            'paused_at' => Date::now(),
        ]);

        expect(app(ReplayRoom::class)->handle($room, $host))->toBe(RoomRefusal::GameNotEnded)
            ->and(replayRaw('room', $room->id))->toBe($before)
            ->and($recorder->sent)->toBe([]);

        // Seul le gel rend « Rejouer » possible.
        $this->travel(1)->seconds();
        replayFreeze($game);

        replayPost($this, $room, $host)
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('room.show', $room))
            ->assertSessionHasNoErrors();

        expect($room->refresh()->status)->toBe(RoomStatus::Lobby)
            ->and(replayBroadcasts($recorder, 'room.replayed'))->toHaveCount(1);

        // La DERNIÈRE partie du salon fait foi : une partie plus ancienne,
        // figée, ne rend pas « Rejouer » possible pendant la suivante.
        $this->travel(1)->seconds();
        $next = replayLaunch($room, $host);

        expect($next->started_at->greaterThan($game->started_at))->toBeTrue()
            ->and(app(ReplayRoom::class)->handle($room, $host))->toBe(RoomRefusal::GameNotEnded)
            ->and($room->refresh()->status)->toBe(RoomStatus::Playing);
    }
});

it('refuse de rejouer pendant le drainage', function (): void {
    $settings = replaySettings();
    PoolFixtures::movies($settings->roundsCount + PlatformLimits::drawSubstituteMargin());
    $drain = app(DeployDrain::class);

    foreach ([Locale::French, Locale::English] as $locale) {
        $token = PlayerToken::mint($locale);
        [$room, $host] = replayRoom($token, $settings);
        replayFreeze(replayLaunch($room, $host));
        $recorder = RecordingBroadcaster::install();
        $before = replayRaw('room', $room->id);
        LobbyWrites::actAs($this, $token);

        $drain->release();
        $drain->start(DeployDrain::defaultTimeoutMinutes());

        // Le message unique de la maintenance, jamais `room.refusal.draining`.
        expect(app(ReplayRoom::class)->handle($room, $host))->toBe(RoomRefusal::Draining);

        replayPost($this, $room, $host)
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(LobbyWrites::lobbyUrl($room))
            ->assertSessionHasErrors(['room' => replayMessage('common.maintenance.launch_blocked', $locale)]);

        // Fenêtre libre ouverte : toujours drainé.
        $window = $drain->openWindow(1);

        replayPost($this, $room, $host)
            ->assertSessionHasErrors(['room' => replayMessage('common.maintenance.launch_blocked', $locale)]);

        expect(replayRaw('room', $room->id))->toBe($before)
            ->and($recorder->sent)->toBe([]);

        // Le drapeau expiré de lui-même : « Rejouer » passe.
        $this->travelTo($window->expiresAt);

        replayPost($this, $room, $host)
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('room.show', $room))
            ->assertSessionHasNoErrors();

        expect($room->refresh()->status)->toBe(RoomStatus::Lobby)
            ->and(replayBroadcasts($recorder, 'room.replayed'))->toHaveCount(1);

        // Un « Rejouer » déjà fait reste fait sous un nouveau drapeau (R4
        // avant R6) : rien d'écrit, rien de refusé.
        $drain->start(DeployDrain::defaultTimeoutMinutes());
        $lobbyRow = replayRaw('room', $room->id);

        expect(app(ReplayRoom::class)->handle($room, $host))->toBeNull()
            ->and(replayRaw('room', $room->id))->toBe($lobbyRow);

        $drain->release();
    }
});

it('refuse un non-hôte', function (): void {
    $settings = replaySettings();
    PoolFixtures::movies($settings->roundsCount + PlatformLimits::drawSubstituteMargin());
    $hostToken = PlayerToken::mint(Locale::French);
    $guestToken = PlayerToken::mint(Locale::French);
    [$room, $host] = replayRoom($hostToken, $settings);
    $guest = LobbyWrites::seat($room, $guestToken);
    replayFreeze(replayLaunch($room, $host));
    $recorder = RecordingBroadcaster::install();
    $before = replayRaw('room', $room->id);

    // Par la route : la policy refuse le siège qui n'est pas l'hôte.
    LobbyWrites::actAs($this, $guestToken);

    replayPost($this, $room, $guest)->assertForbidden();

    // Par l'action : l'autorité relue sous le verrou refuse le siège d'un
    // invité, et celui de l'hôte d'un autre salon.
    [$elsewhere, $foreignHost] = replayRoom(PlayerToken::mint(Locale::French), $settings);

    expect(app(ReplayRoom::class)->handle($room, $guest))->toBe(RoomRefusal::NotHost)
        ->and(app(ReplayRoom::class)->handle($room, $foreignHost))->toBe(RoomRefusal::NotHost)
        ->and(replayRaw('room', $room->id))->toBe($before)
        ->and($recorder->sent)->toBe([]);

    // L'hôte, lui, rejoue ; l'autre salon n'a pas bougé.
    LobbyWrites::actAs($this, $hostToken);

    replayPost($this, $room, $host)->assertRedirect(route('room.show', $room));

    expect($room->refresh()->status)->toBe(RoomStatus::Lobby)
        ->and($elsewhere->refresh()->status)->toBe(RoomStatus::Lobby)
        ->and(Game::query()->where('room_id', $elsewhere->id)->exists())->toBeFalse();
});

it('diffuse le vivier réduit par la non-répétition', function (): void {
    // `M` au minimum des bornes : réduire `M` n'est jamais un remède, et la
    // non-répétition sera la seule cause du blocage final.
    $settings = replaySettings();
    $roundsCount = $settings->roundsCount;
    $works = 3 * $roundsCount - 1;
    PoolFixtures::movies($works);
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = replayRoom($token, $settings);
    LobbyWrites::actAs($this, $token);

    expect($settings->noRepeatMovies)->toBeTrue()
        ->and(RoomSettingsPresenter::state($room, $this->now)['pool']['count'])->toBe($works);

    // Première partie : `M` œuvres jouées, retirées du vivier du salon.
    replayPlayToPodium($room, $host);
    $recorder = RecordingBroadcaster::install();
    $firstAt = Date::now()->toImmutable()->startOfMillisecond();

    replayPost($this, $room, $host)->assertRedirect(route('room.show', $room))->assertSessionHasNoErrors();

    $first = replayBroadcasts($recorder, 'room.replayed');
    $firstPool = $first[0]['payload']['pool'];

    expect($first)->toHaveCount(1)
        ->and($firstPool)->toBe(replayJson(RoomSettingsPresenter::state($room->refresh(), $firstAt)['pool']))
        ->and($firstPool['count'])->toBe($works - $roundsCount)
        ->and($firstPool['roundsCount'])->toBe($roundsCount)
        ->and($firstPool['blocked'])->toBeFalse()
        // Le catalogue n'a pas changé : c'est la mémoire du salon qui réduit.
        ->and(app(PoolReporter::class)->report(PoolScope::catalogue([], $settings->framesPerRound), $roundsCount)->count)->toBe($works);

    // Seconde partie, sur le vivier réduit : encore `M` œuvres jouées.
    replayPlayToPodium($room, $host);
    $secondAt = Date::now()->toImmutable()->startOfMillisecond();

    replayPost($this, $room, $host)->assertRedirect(route('room.show', $room))->assertSessionHasNoErrors();

    $second = replayBroadcasts($recorder, 'room.replayed');
    $pool = $second[1]['payload']['pool'];

    // Le vivier est tombé sous `M` : bloqué pour tous, la non-répétition
    // nommée seule cause, « nouveau salon » proposé, puis l'interrupteur de
    // l'onglet Avancé, livré par L50-10 (D28 du 23/09).
    expect($second)->toHaveCount(2)
        ->and($pool)->toBe(replayJson(RoomSettingsPresenter::state($room->refresh(), $secondAt)['pool']))
        ->and($pool['count'])->toBe($works - 2 * $roundsCount)
        ->and($pool['blocked'])->toBeTrue()
        ->and($pool['causes'])->toBe([PoolFault::NoRepeatMovies->value])
        ->and(array_column($pool['remedies'], 'kind'))->toBe([PoolRemedyKind::OpenNewRoom->value, PoolRemedyKind::DisableNoRepeat->value])
        ->and($pool['remedies'][0]['count'])->toBe($works)
        ->and($pool['remedies'][1]['count'])->toBe($works);

    WirePayload::assertSafe($second[1]['payload'], 'room.replayed');

    // Le lancement suivant repasse par la garde de vivier : refusé.
    $outcome = app(LaunchGame::class)->handle($room, $host);

    expect($outcome->refusal)->toBe(RoomRefusal::PoolInsufficient)
        ->and($outcome->replace)->toBe(['playable' => $works - 2 * $roundsCount, 'required' => $roundsCount])
        ->and(Game::query()->where('room_id', $room->id)->count())->toBe(2)
        ->and($room->refresh()->status)->toBe(RoomStatus::Lobby);
});

it('rend une erreur traduite et laisse le salon sur son podium quand la transaction de « Rejouer » échoue', function (): void {
    $settings = replaySettings();
    PoolFixtures::movies($settings->roundsCount + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::English);
    [$room, $host] = replayRoom($token, $settings);
    replayFreeze(replayLaunch($room, $host));
    $recorder = RecordingBroadcaster::install();
    $journal = GameJournalRecorder::start();
    $before = replayRaw('room', $room->id);
    LobbyWrites::actAs($this, $token);

    // Panne APRÈS l'écriture du statut, au calcul du vivier diffusé : toute
    // la transaction est annulée, le salon reste en partie.
    $armed = true;
    $written = false;
    $failure = new RuntimeException('panne du vivier', 0, new PDOException('verrou perdu'));
    $failureLine = __LINE__ - 1;

    DB::beforeExecuting(static function (string $query) use (&$armed, &$written, $failure): void {
        if (! $armed) {
            return;
        }

        if (preg_match('/^update ["`]?room["`]? set/i', $query) === 1) {
            $written = true;

            return;
        }

        if ($written && preg_match('/["`]movie["`]/i', $query) === 1) {
            throw $failure;
        }
    });

    replayPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasErrors(['room' => replayMessage(LaunchController::KEY_LAUNCH_FAILED, Locale::English)]);

    // Rien d'écrit, rien de diffusé ; au journal `game`, la classe, le code
    // et le lieu de l'exception, la classe de sa cause et le statut relu —
    // ni message ni trace.
    expect($written)->toBeTrue()
        ->and(replayRaw('room', $room->id))->toBe($before)
        ->and($room->refresh()->status)->toBe(RoomStatus::Playing)
        ->and($recorder->sent)->toBe([])
        ->and($journal->contexts(ReplayController::LOG_REPLAY_FAILED))->toBe([[
            'exception' => RuntimeException::class,
            'code' => 0,
            'file' => 'tests/Feature/Room/ReplayRoomTest.php',
            'line' => $failureLine,
            'previous' => PDOException::class,
            'roomLobby' => false,
        ]]);

    $line = json_encode($journal->lines(), JSON_THROW_ON_ERROR);

    expect($line)->not->toContain($room->room_code)
        ->and($line)->not->toContain((string) $host->nickname)
        ->and($line)->not->toContain('panne du vivier')
        ->and($line)->not->toContain('verrou perdu');

    // Une exception APRÈS la validation (un rappel `afterCommit` qui lève) :
    // le salon est déjà au lobby, le geste a eu lieu — la réponse est celle
    // d'un « Rejouer » réussi, sans erreur, et le journal le dit.
    $armed = false;
    $registered = false;

    DB::beforeExecuting(static function (string $query) use (&$registered): void {
        if (! $registered && preg_match('/^update ["`]?room["`]? set/i', $query) === 1) {
            $registered = true;
            DB::afterCommit(static fn (): never => throw new RuntimeException('rappel après validation'));
        }
    });

    replayPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    expect($room->refresh()->status)->toBe(RoomStatus::Lobby)
        ->and($journal->contexts(ReplayController::LOG_REPLAY_FAILED))->toHaveCount(2)
        ->and($journal->contexts(ReplayController::LOG_REPLAY_FAILED)[1])->toMatchArray([
            'exception' => RuntimeException::class,
            'previous' => null,
            'roomLobby' => true,
        ]);
});

it('refuse un salon archivé avant toute réparation d\'hôte', function (): void {
    $settings = replaySettings();
    PoolFixtures::movies($settings->roundsCount + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::French);
    [$room, $host, $guest] = replayRoom($token, $settings);
    replayFreeze(replayLaunch($room, $host));

    // Hôte parti : la lecture sans cible valide répare l'hôte (R3), puis
    // refuse le demandeur qui n'est pas le nouvel hôte ; la réparation reste.
    Player::query()->whereKey($host->id)->update(['connection_state' => 'left', 'left_at' => Date::now()]);

    expect(app(ReplayRoom::class)->handle($room, $host))->toBe(RoomRefusal::NotHost)
        ->and($room->refresh()->host_player_id)->toBe($guest->id)
        ->and(app(ReplayRoom::class)->handle($room, $guest))->toBeNull()
        ->and($room->refresh()->status)->toBe(RoomStatus::Lobby);

    // Un salon archivé n'a plus d'hôte : refusé AVANT la réparation, qui lui
    // en rendrait un (même lecture que le lancement, E102-1).
    Room::query()->whereKey($room->id)->update([
        'status' => RoomStatus::Archived->value,
        'archived_at' => Date::now(),
        'room_code_active' => null,
        'host_player_id' => null,
    ]);
    $archived = replayRaw('room', $room->id);

    expect(app(ReplayRoom::class)->handle($room, $guest))->toBe(RoomRefusal::RoomArchived)
        ->and(replayRaw('room', $room->id))->toBe($archived);
});
