<?php

use App\Actions\Game\RevealSoloAnswer;
use App\Actions\Game\SkipSoloRound;
use App\Avatars\AvatarPresetCatalog;
use App\Enums\AvatarKind;
use App\Enums\ContentAvailability;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Enums\SettingPresetKey;
use App\Http\Controllers\Game\SoloGameController;
use App\Http\Controllers\Game\SoloRoundController;
use App\Http\Middleware\EnsureActiveSeat;
use App\Jobs\Game\AdvanceRound;
use App\Models\Frame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Settings\SettingPresetCatalog;
use App\Support\Deploy\DeployDrain;
use App\Support\Draw\PoolTooSmallException;
use App\Support\Game\GameJournal;
use App\Support\Game\RoundStep;
use App\Support\Game\SoloPresets;
use App\Support\Game\SoloRoundClosure;
use App\Support\Identity\NicknameNormalizer;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\WireTime;
use App\Support\Room\SeatPublicId;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Game\GameJournalRecorder;
use Tests\Support\Game\PresenceFixtures;
use Tests\Support\I18n\FrontSource;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Mode solo — spec 60 § 16, contrat C7 § 4.12, E10-N3 (lots L60-15, L60-16)
|--------------------------------------------------------------------------
|
| Le démarrage passe par la route, comme le client l'enverra : `solo.store`
| depuis `room/solo` (premier siège : preset, pseudo, avatar) ou depuis
| `game/solo` (relance : preset seul, sous le cookie `player_token`). Une
| seule transaction : drainage d'abord, siège verrouillé, réglages D19 et
| refus de vivier avant toute écriture, jeton, siège, interruption de la
| partie solo en cours, puis `OpenGame`. Tout refus annule tout,
| interruption comprise ; un échec technique se rend traduit.
|
| Les parties se jouent sur de vrais films (`PoolFixtures`, une variante
| publiée par niveau 1, 3 et 5 : un catalogue en passe 1), fichiers sur le
| disque `frames` simulé. Le preset des relances est Classique (Normal : pas
| de QCM avant le dernier palier). Les jobs de frontière sont simulés : les
| étapes échues s'exécutent par le rattrapage du démarrage.
|
| Lot L60-16 (seconde moitié du fichier) : la page `game/solo` et ses props,
| le sondage `solo.state`, le battement `solo.heartbeat` et les gestes
| `solo.reveal`, `solo.skip`, `solo.next`, envoyés PAR LA ROUTE comme le
| client les envoie — JSON, cookie `player_token`, onglet actif en
| `X-Seat-Token`, horloge figée à l'instant de réception. Le solo ne reçoit
| aucun événement : chaque étape échue s'exécute par le rattrapage d'un
| sondage ou d'un geste, les jobs de frontière restant simulés.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    SeatEntry::isolateCookies();
    Queue::fake();

    $this->now = CarbonImmutable::parse('2026-09-27 10:15:00.250');
    $this->travelTo($this->now);
});

/** Le preset des démarrages et relances de ce fichier. */
const SOLO_PRESET = SettingPresetKey::Classic;

/**
 * Un catalogue de `$works` films de vivier en passe 1 : jouables à N = 2 et
 * 3, jamais à 4 ni 5.
 *
 * @return list<Movie>
 */
function soloCatalogue(int $works): array
{
    return PoolFixtures::movies($works);
}

/** Les œuvres qu'exige un preset : ses `M` manches. */
function soloRoundsOf(SettingPresetKey $preset = SOLO_PRESET): int
{
    return SettingPresetCatalog::settingsFor($preset)->roundsCount;
}

/**
 * Le démarrage par la route, depuis la page `$from` (`room/solo` par défaut),
 * formulaire classique : un refus revient en 303 avec les erreurs de session.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function soloStart(TestCase $test, array $body, ?string $from = null): TestResponse
{
    return $test->from($from ?? route('solo.create'))->post(route('solo.store'), $body);
}

/**
 * Le corps d'un premier démarrage : preset, pseudo et avatar.
 *
 * @return array{preset: string, nickname: string, avatar: string}
 */
function soloFirstBody(SettingPresetKey $preset = SOLO_PRESET): array
{
    return ['preset' => $preset->value, ...SeatEntry::form()];
}

/**
 * Un premier démarrage réussi, sans jeton : rend le jeton posé, le siège
 * solo et la partie née.
 *
 * @return array{0: PlayerToken, 1: Player, 2: Game}
 */
function soloStarted(TestCase $test, SettingPresetKey $preset = SOLO_PRESET): array
{
    $response = soloStart($test, soloFirstBody($preset))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasNoErrors();

    $token = SeatEntry::tokenFrom($response);
    $seat = Player::query()->whereNull('room_id')->heldByToken($token)->sole();

    return [$token, $seat, soloGameOf($seat)];
}

/** La partie solo en cours du siège. */
function soloGameOf(Player $seat): Game
{
    return Game::query()
        ->inProgress()
        ->whereHas('gamePlayers', static fn ($participation) => $participation->where('player_id', $seat->id))
        ->sole();
}

/** `T₁` de la manche 1 d'une partie, relu en base. */
function soloFirstRoundStart(Game $game): CarbonImmutable
{
    $round = Round::query()->where('game_id', $game->id)->where('round_number', 1)->sole();

    return $round->started_at ?? throw new LogicException('Manche 1 non programmée.');
}

/**
 * Les lignes d'une partie, colonnes brutes : pour prouver qu'un démarrage
 * refusé ou en échec n'y a rien écrit.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function soloRawRows(Game $game): array
{
    $rows = static fn (string $table, string $column, array $ids): array => DB::table($table)
        ->whereIn($column, $ids)
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->values()
        ->all();

    $roundIds = Round::query()->where('game_id', $game->id)->pluck('id')->all();

    return [
        'game' => $rows('game', 'id', [$game->id]),
        'game_player' => $rows('game_player', 'game_id', [$game->id]),
        'round' => $rows('round', 'game_id', [$game->id]),
        'round_tier' => $rows('round_tier', 'round_id', $roundIds),
        'round_player' => $rows('round_player', 'round_id', $roundIds),
    ];
}

/** Le message d'une clé dans une langue, qui doit exister. */
function soloMessage(string $key, Locale $locale): string
{
    $message = trans($key, [], $locale->value);

    expect($message)->toBeString()->not->toBe($key);

    return (string) $message;
}

/** La balise `<html>` d'une page rendue. */
function soloHtmlTag(TestResponse $response): string
{
    expect(preg_match('/<html\b[^>]*>/i', (string) $response->getContent(), $match))->toBe(1);

    return $match[0];
}

/** Le jeton courant, posé en cookie comme le navigateur l'envoie. */
function soloActAs(TestCase $test, PlayerToken $token): void
{
    LobbyWrites::actAs($test, $token);
}

/**
 * Une file injoignable à la poussée (Redis plein en `noeviction`, par
 * exemple), à la place de la file simulée : `AdvanceRound` est
 * `ShouldQueueAfterCommit`, sa poussée court APRÈS le COMMIT et lève hors de
 * `DB::transaction()` (même file que `LaunchGameTest`).
 */
function soloUnreachableQueue(): void
{
    $fake = Queue::getFacadeRoot();

    if ($fake instanceof QueueFake) {
        Queue::swap($fake->queue);
    }

    Queue::extend('unreachable', static fn (): ConnectorInterface => new class implements ConnectorInterface
    {
        public function connect(array $config): QueueContract
        {
            // `AdvanceRound` porte son instant : il part par `later()`.
            return new class extends NullQueue
            {
                public function push($job, $data = '', $queue = null): mixed
                {
                    return $this->later(null, $job, $data, $queue);
                }

                public function later($delay, $job, $data = '', $queue = null): mixed
                {
                    return $this->enqueueUsing(
                        $job,
                        $this->createPayload($job, $queue ?? 'default', $data),
                        $queue,
                        $delay,
                        static fn (): never => throw new RuntimeException('file injoignable'),
                    );
                }
            };
        }
    });

    config(['queue.connections.unreachable' => ['driver' => 'unreachable'], 'queue.default' => 'unreachable']);
}

it('un second lancement solo sous le même jeton reprend le siège', function (): void {
    $recorder = RecordingBroadcaster::install();
    soloCatalogue(soloRoundsOf());

    [$token, $seat, $first] = soloStarted($this);

    // Le siège solo : sans salon, créneau d'unicité écrit dans la même
    // écriture que le hash du jeton (E10-N3), pseudo canonique et avatar.
    expect($seat->room_id)->toBeNull()
        ->and($seat->player_token_hash)->toBe($token->hash())
        ->and($seat->solo_token_hash)->toBe($token->hash())
        ->and($seat->nickname)->toBe(SeatEntry::NICKNAME)
        ->and($seat->nickname_normalized)->toBe(NicknameNormalizer::normalize(SeatEntry::NICKNAME))
        ->and($seat->avatar_preset)->toBe(SeatEntry::avatar(3))
        ->and($seat->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and($first->mode)->toBe(GameMode::Solo)
        ->and($first->room_id)->toBeNull();

    // L'avatar choisi rejoint le jeton après la validation (I4.5).
    expect($token->avatar)->toBe(SeatEntry::avatar(3));

    $before = DB::table('player')->where('id', $seat->id)->first();

    // Deux relances sous le même jeton : sans pseudo ni avatar, puis avec un
    // autre pseudo, que la reprise ignore — jamais un second siège.
    foreach ([['preset' => SOLO_PRESET->value], ['preset' => SOLO_PRESET->value, 'nickname' => 'Autre', 'avatar' => SeatEntry::avatar(5)]] as $step => $body) {
        $this->travelTo($this->now->addSeconds($step + 1));
        soloActAs($this, $token);

        $response = soloStart($this, $body, route('solo.show'))
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('solo.show'))
            ->assertSessionHasNoErrors();

        // Le cookie glisse, même identité.
        expect(SeatEntry::tokenFrom($response)->sameIdentityAs($token))->toBeTrue();
    }

    expect(Player::query()->count())->toBe(1)
        ->and(DB::table('player')->where('id', $seat->id)->first())->toEqual($before)
        ->and(GamePlayer::query()->distinct()->pluck('player_id')->all())->toBe([$seat->id])
        ->and(Game::query()->count())->toBe(3)
        ->and(Game::query()->inProgress()->count())->toBe(1)
        ->and($first->refresh()->status)->toBe(GameStatus::Interrupted)
        // Aucune diffusion : le solo n'a ni salon ni canal.
        ->and($recorder->sent)->toBe([]);
});

it('refuse de démarrer une partie solo pendant un drainage, sans partie créée', function (): void {
    soloCatalogue(soloRoundsOf());
    $drain = app(DeployDrain::class);
    $drain->start(DeployDrain::defaultTimeoutMinutes());

    foreach ([Locale::French, Locale::English] as $locale) {
        $response = $this->withHeader('Accept-Language', $locale->value)
            ->from(route('solo.create'))
            ->post(route('solo.store'), soloFirstBody());

        // Le message unique de la maintenance, sous `preset`, dans la langue
        // de la requête ; aucun jeton frappé sur un visiteur refusé.
        $response->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('solo.create'))
            ->assertSessionHasErrors(['preset' => soloMessage('common.maintenance.launch_blocked', $locale)]);

        expect(SeatEntry::tokenCookies($response))->toBe([]);
        $this->flushSession();
    }

    // Aucune partie, aucun siège, aucune manche.
    expect(Game::query()->count())->toBe(0)
        ->and(Player::query()->count())->toBe(0)
        ->and(Round::query()->count())->toBe(0);

    // Le drapeau levé, le même envoi démarre : c'était bien le drainage.
    $drain->release();

    soloStart($this, soloFirstBody())->assertRedirect(route('solo.show'))->assertSessionHasNoErrors();

    expect(Game::query()->count())->toBe(1);
});

it('un nouveau lancement solo interrompt la partie solo en cours du siège', function (): void {
    $recorder = RecordingBroadcaster::install();
    $journal = GameJournalRecorder::start();
    soloCatalogue(soloRoundsOf());

    [$token, $seat, $first] = soloStarted($this);
    $firstStart = soloFirstRoundStart($first);

    expect($firstStart->equalTo($this->now->addMilliseconds(EngineConstants::launchCountdownMs())))->toBeTrue();

    // La relance tombe pendant le palier 1 de la manche 1 : le rattrapage
    // l'ouvre (le job de frontière est simulé), puis la manche est close
    // comme par « Passer la manche ».
    $relaunchedAt = $firstStart->addMilliseconds(1500);
    $this->travelTo($relaunchedAt);
    soloActAs($this, $token);

    soloStart($this, ['preset' => SOLO_PRESET->value], route('solo.show'))
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasNoErrors();

    $first->refresh();
    $round = Round::query()->where('game_id', $first->id)->where('round_number', 1)->sole();
    $participation = RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $seat->id)->sole();

    // La partie : gelée `interrupted` à l'instant de la relance.
    expect($first->status)->toBe(GameStatus::Interrupted)
        ->and($first->ended_at?->equalTo($relaunchedAt))->toBeTrue()
        ->and($first->rounds_completed)->toBe(1);

    // La manche : `completed`, close et « révélée » au même instant, jamais
    // au-delà de D ; la saisie du siège `skipped`, aucune bonne réponse.
    expect($round->status)->toBe(RoundStatus::Completed)
        ->and($round->ended_at?->equalTo($relaunchedAt))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($relaunchedAt))->toBeTrue()
        ->and($participation->input_state)->toBe(RoundPlayerInputState::Skipped)
        ->and($participation->input_closed_at?->equalTo($relaunchedAt))->toBeTrue()
        ->and(Guess::query()->count())->toBe(0);

    // Les manches suivantes restent `pending`, jamais jouées.
    expect(Round::query()->where('game_id', $first->id)->where('id', '<>', $round->id)->pluck('status')->unique()->values()->all())
        ->toBe([RoundStatus::Pending]);

    // Les agrégats du siège sont gelés, sans rang en solo.
    $frozen = GamePlayer::query()->where('game_id', $first->id)->sole();

    expect($frozen->correct_answers)->toBe(0)
        ->and($frozen->final_score)->toBe(0)
        ->and($frozen->final_rank)->toBeNull();

    // La nouvelle partie : sur le même siège, programmée après le décompte.
    $second = soloGameOf($seat);

    expect($second->id)->not->toBe($first->id)
        ->and($second->started_at->equalTo($relaunchedAt))->toBeTrue()
        ->and(soloFirstRoundStart($second)->equalTo($relaunchedAt->addMilliseconds(EngineConstants::launchCountdownMs())))->toBeTrue()
        ->and(Game::query()->inProgress()->count())->toBe(1)
        ->and(Player::query()->count())->toBe(1);

    // Le gel est journalisé, sans aucune donnée de joueur ; rien n'est diffusé.
    expect(array_column($journal->contexts(GameJournal::GAME_FINALIZED), 'outcome'))->toBe([GameStatus::Interrupted->value])
        ->and($journal->raw())->not->toContain(SeatEntry::NICKNAME)
        ->and($recorder->sent)->toBe([]);

    $journal->stop();
});

it('le refus de drainage n\'interrompt pas la partie solo en cours', function (): void {
    $journal = GameJournalRecorder::start();
    soloCatalogue(soloRoundsOf());

    [$token, , $game] = soloStarted($this);

    // Pendant la manche 1 : une interruption aurait des étapes à rattraper.
    $this->travelTo(soloFirstRoundStart($game)->addSeconds(2));
    $before = soloRawRows($game);

    $drain = app(DeployDrain::class);
    $drain->start(DeployDrain::defaultTimeoutMinutes());
    soloActAs($this, $token);

    soloStart($this, ['preset' => SOLO_PRESET->value], route('solo.show'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasErrors(['preset' => soloMessage('common.maintenance.launch_blocked', Locale::English)]);

    expect(soloRawRows($game))->toBe($before)
        ->and($game->refresh()->status)->toBe(GameStatus::Running)
        ->and(Game::query()->count())->toBe(1);

    // Un drapeau posé APRÈS l'interruption, par la clôture de la manche en
    // cours (étape 7) : l'étape 1 est passée, la garde O1 d'OpenGame refuse,
    // et son `LaunchOutcome::refused`, qui ne lève pas, annule la transaction
    // entière, interruption comprise (§ 16.2). Le drapeau vit dans le cache :
    // l'annulation ne l'emporte pas.
    $drain->release();
    $this->flushSession();

    Round::saved(static function (Round $round) use ($drain): void {
        if ($round->status === RoundStatus::Completed && ! $drain->isDraining()) {
            $drain->start(DeployDrain::defaultTimeoutMinutes());
        }
    });

    soloActAs($this, $token);

    soloStart($this, ['preset' => SOLO_PRESET->value], route('solo.show'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasErrors(['preset' => soloMessage('common.maintenance.launch_blocked', Locale::English)]);

    // La clôture a bien couru (le drapeau en témoigne), puis a été annulée :
    // rattrapage, clôture et gel compris ; aucun gel journalisé.
    expect($drain->isDraining())->toBeTrue()
        ->and(soloRawRows($game))->toBe($before)
        ->and($game->refresh()->status)->toBe(GameStatus::Running)
        ->and(Game::query()->count())->toBe(1)
        ->and($journal->contexts(GameJournal::GAME_FINALIZED))->toBe([]);

    $journal->stop();
});

it('un refus de vivier n\'interrompt pas la partie solo en cours', function (): void {
    $journal = GameJournalRecorder::start();
    $movies = soloCatalogue(soloRoundsOf());

    [$token, , $game] = soloStarted($this);

    $this->travelTo(soloFirstRoundStart($game)->addSeconds(2));
    $before = soloRawRows($game);

    // D'abord un vivier réduit APRÈS l'interruption, par la clôture de la
    // manche en cours (étape 7) : l'étape 4 est passée, la garde O3 d'OpenGame
    // refuse, et son `LaunchOutcome::refused` annule la transaction entière,
    // interruption et réduction comprises (§ 16.2) ; son rapport part en
    // données.
    $shrunk = false;

    Round::saved(static function (Round $round) use ($movies, &$shrunk): void {
        if ($shrunk || $round->status !== RoundStatus::Completed) {
            return;
        }

        $shrunk = true;
        Frame::query()->where('movie_id', $movies[0]->id)->update(['availability' => ContentAvailability::Draft->value]);
        MovieFactory::recomputeProjection($movies[0]);
    });

    soloActAs($this, $token);

    soloStart($this, ['preset' => SOLO_PRESET->value], route('solo.show'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasErrors(['preset' => soloMessage('game.errors.pool_too_small', Locale::English)])
        ->assertInertiaFlash('pool.count', soloRoundsOf() - 1)
        ->assertInertiaFlash('pool.blocked', true);

    expect($shrunk)->toBeTrue()
        ->and(Frame::query()->where('movie_id', $movies[0]->id)->where('availability', ContentAvailability::Draft->value)->count())->toBe(0)
        ->and(soloRawRows($game))->toBe($before)
        ->and($game->refresh()->status)->toBe(GameStatus::Running)
        ->and(Game::query()->count())->toBe(1)
        ->and($journal->contexts(GameJournal::GAME_FINALIZED))->toBe([]);

    $journal->stop();
    $this->flushSession();

    // Un film quitte le vivier, projection recalculée : le catalogue ne tient
    // plus les M œuvres du preset, ni à son N ni au-dessous.
    Frame::query()->where('movie_id', $movies[0]->id)->update(['availability' => ContentAvailability::Draft->value]);
    MovieFactory::recomputeProjection($movies[0]);

    soloActAs($this, $token);

    soloStart($this, ['preset' => SOLO_PRESET->value], route('solo.show'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasErrors(['preset' => soloMessage('game.errors.pool_too_small', Locale::English)])
        // Le rapport de vivier, en données, au seul joueur (30 § 4.6).
        ->assertInertiaFlash('pool.count', soloRoundsOf() - 1)
        ->assertInertiaFlash('pool.blocked', true)
        ->assertInertiaFlash('pool.nearestPlayableFramesPerRound', null);

    expect(soloRawRows($game))->toBe($before)
        ->and($game->refresh()->status)->toBe(GameStatus::Running)
        ->and(Game::query()->count())->toBe(1);
});

it('rend une erreur traduite et n\'interrompt pas la partie solo en cours quand le tirage lève PoolTooSmallException', function (): void {
    $journal = GameJournalRecorder::start();
    $movies = soloCatalogue(soloRoundsOf());

    [$token, $seat, $game] = soloStarted($this);

    $this->travelTo(soloFirstRoundStart($game)->addSeconds(2));
    $before = soloRawRows($game);

    // Une projection périmée : le vivier compte encore M œuvres, le tirage
    // n'en retient que M − 1 (30 § 4.6) — un échec technique, jamais un refus
    // de vivier.
    $stale = FrameLevelCoverage::nominal(SettingPresetCatalog::settingsFor(SOLO_PRESET)->framesPerRound)[0];
    DB::table('frame')->where('movie_id', $movies[0]->id)->where('frame_level', $stale->value)
        ->update(['availability' => ContentAvailability::Draft->value]);

    foreach ([Locale::French, Locale::English] as $locale) {
        soloActAs($this, $token->withLocale($locale));

        soloStart($this, ['preset' => SOLO_PRESET->value], route('solo.show'))
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('solo.show'))
            ->assertSessionHasErrors(['preset' => soloMessage(SoloGameController::KEY_START_FAILED, $locale)])
            ->assertSessionDoesntHaveErrors(['preset' => soloMessage('game.errors.pool_too_small', $locale)]);

        $this->flushSession();
    }

    // Rien d'écrit, la partie en cours intacte — rattrapage et interruption
    // compris, annulés avec la transaction.
    expect(soloRawRows($game))->toBe($before)
        ->and($game->refresh()->status)->toBe(GameStatus::Running)
        ->and(Game::query()->count())->toBe(1)
        ->and(Player::query()->count())->toBe(1);

    // Une ligne par démarrage au journal `game`, sans donnée personnelle ni
    // message d'exception ; l'exception interceptée est celle du tirage.
    $contexts = $journal->contexts(SoloGameController::LOG_START_FAILED);

    expect(array_column($contexts, 'exception'))->toBe([PoolTooSmallException::class, PoolTooSmallException::class])
        ->and(array_column($contexts, 'soloStarted'))->toBe([false, false])
        ->and($journal->raw())->not->toContain((string) $seat->nickname)
        ->and($journal->raw())->not->toContain($seat->public_id);

    $journal->stop();
});

it('rend la page de partie sans erreur quand la file refuse le job de la manche 1 après la validation', function (): void {
    $journal = GameJournalRecorder::start();
    soloCatalogue(soloRoundsOf());
    soloUnreachableQueue();

    // Des instants à la microseconde : `started_at` s'écrit à la
    // milliseconde, et la partie née dans la milliseconde de la requête doit
    // se lire comme née pendant elle.
    $this->travelTo(CarbonImmutable::parse('2026-09-27 10:15:00.250400'));

    // Premier siège : la partie est née, la réponse est celle d'un démarrage
    // réussi — jamais « le démarrage a échoué » sur `room/solo`.
    $response = soloStart($this, soloFirstBody())
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasNoErrors();

    $seat = Player::query()->whereNull('room_id')->sole();
    $first = soloGameOf($seat);

    // La manche 1 est programmée, sans job : `solo.state` la rattrapera.
    expect($first->status)->toBe(GameStatus::Running)
        ->and(Round::query()->where('game_id', $first->id)->where('status', RoundStatus::Pending->value)->whereNotNull('started_at')->count())->toBe(1);

    // Relance depuis `game/solo` : la partie précédente est interrompue, la
    // nouvelle en cours, et aucune erreur ne prétend le contraire.
    $this->flushSession();
    $this->travelTo(CarbonImmutable::parse('2026-09-27 10:15:01.250400'));
    soloActAs($this, SeatEntry::tokenFrom($response));

    soloStart($this, ['preset' => SOLO_PRESET->value], route('solo.show'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasNoErrors();

    expect($first->refresh()->status)->toBe(GameStatus::Interrupted)
        ->and(soloGameOf($seat)->id)->not->toBe($first->id)
        ->and(Game::query()->inProgress()->count())->toBe(1);

    // Une ligne par démarrage au journal `game` : l'exception de la file, et
    // la partie née.
    $contexts = $journal->contexts(SoloGameController::LOG_START_FAILED);

    expect(array_column($contexts, 'exception'))->toBe([RuntimeException::class, RuntimeException::class])
        ->and(array_column($contexts, 'previous'))->toBe([null, null])
        ->and(array_column($contexts, 'soloStarted'))->toBe([true, true]);

    $journal->stop();
});

it('relancer pendant la phase closed d\'une manche interrompt la partie sans exception et laisse le siège locked', function (): void {
    $token = PlayerToken::mint(Locale::English);
    $target = SubmissionFixtures::movie('Quintessence Nocturne');

    // Une partie solo en Expert, manche 1 ouverte, siège tenu par le jeton.
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound(
        [$token],
        SubmissionFixtures::settings(InputDifficulty::Expert),
        $target,
        solo: true,
    );

    // De quoi relancer le preset : ses M œuvres au catalogue.
    soloCatalogue(soloRoundsOf() - Round::query()->where('game_id', $game->id)->count());

    // La bonne réponse verrouille le siège ; seul participant, la manche se
    // clôt d'anticipation (phase `closed`, révélation en attente de la grâce).
    $answeredAt = soloFirstRoundStart($game)->addSeconds(1);

    SubmissionFixtures::submit($this, $seat, $token, 'Quintessence Nocturne', $answeredAt)->assertOk();

    $round->refresh();
    $endedAt = $round->ended_at ?? throw new LogicException('Manche non close d’anticipation.');

    expect($round->status)->toBe(RoundStatus::Running)
        ->and(SubmissionFixtures::participation($round, $seat)->input_state)->toBe(RoundPlayerInputState::Locked);

    // La relance, avant la fin de la grâce : la révélation n'est pas échue.
    $relaunchedAt = $endedAt->addMilliseconds(intdiv($game->tier_grace_ms, 2));
    $this->travelTo($relaunchedAt);

    soloStart($this, ['preset' => SOLO_PRESET->value], route('solo.show'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasNoErrors();

    $game->refresh();
    $round->refresh();

    // La manche : `completed`, révélation tenue pour achevée à `ended_at` ;
    // la saisie reste `locked` et sa bonne réponse compte (locked ⟺ guess).
    expect($round->status)->toBe(RoundStatus::Completed)
        ->and($round->ended_at?->equalTo($endedAt))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($endedAt))->toBeTrue()
        ->and(SubmissionFixtures::participation($round, $seat)->input_state)->toBe(RoundPlayerInputState::Locked)
        ->and(Guess::query()->where('round_id', $round->id)->where('player_id', $seat->id)->count())->toBe(1);

    // La partie : gelée `interrupted` à la relance, la bonne réponse comptée.
    expect($game->status)->toBe(GameStatus::Interrupted)
        ->and($game->ended_at?->equalTo($relaunchedAt))->toBeTrue()
        ->and(GamePlayer::query()->where('game_id', $game->id)->sole()->correct_answers)->toBe(1);

    // La nouvelle partie, sur le même siège.
    expect(soloGameOf($seat)->id)->not->toBe($game->id)
        ->and(Player::query()->count())->toBe(1);
});

it('un premier passage sans siège solo redirige vers la page d\'entrée du solo sans frapper de jeton', function (): void {
    // Sans jeton.
    $response = $this->get(route('solo.show'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.create'));

    expect(SeatEntry::tokenCookies($response))->toBe([]);

    // Un jeton qui ne tient qu'un siège de salon : aucun siège solo.
    $token = PlayerToken::mint(Locale::French);
    LobbyWrites::hostedRoom($token);
    soloActAs($this, $token);

    $response = $this->get(route('solo.show'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.create'));

    expect(SeatEntry::tokenCookies($response))->toBe([])
        ->and(Player::query()->whereNull('room_id')->count())->toBe(0);

    // La page d'entrée elle-même ne frappe rien.
    $this->withoutVite();

    expect(SeatEntry::tokenCookies($this->get(route('solo.create'))->assertOk()))->toBe([]);
});

it('la page d\'entrée du solo rend room/solo dans l\'apparence du visiteur', function (): void {
    $this->withoutVite();
    soloCatalogue(soloRoundsOf());

    // Page `room/*` : domaines `room` et `legal`, jamais `game.appearance`.
    $middleware = Route::getRoutes()->getByName('solo.create')?->gatherMiddleware() ?? [];

    expect($middleware)->not->toContain('game.appearance')
        ->and($middleware)->toContain('translations:room,legal')
        ->and(Route::getRoutes()->getByName('solo.show')?->gatherMiddleware())->toContain('game.appearance');

    foreach (['light', 'dark'] as $appearance) {
        $response = $this->withUnencryptedCookie('appearance', $appearance)->get(route('solo.create'))->assertOk();
        $tag = soloHtmlTag($response);

        expect($tag)->not->toContain('data-appearance-forced', $appearance)
            ->and(preg_match('/\sclass="[^"]*\bdark\b/', $tag))->toBe($appearance === 'dark' ? 1 : 0, $appearance);
    }

    // Les props : les quatre presets dans l'ordre du site et leur N jouable
    // le plus proche sur le vivier catalogue (passe 1 : Hardcore à 3), le
    // catalogue des avatars sans avatar pris, les bornes du pseudo — ni
    // paquet, ni jeton d'onglet.
    $this->get(route('solo.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('room/solo')
        ->where('presets', [
            ['key' => 'classic', 'grayed' => false, 'nearestPlayableFramesPerRound' => null],
            ['key' => 'fast', 'grayed' => false, 'nearestPlayableFramesPerRound' => null],
            ['key' => 'hardcore', 'grayed' => true, 'nearestPlayableFramesPerRound' => RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND],
            ['key' => 'discovery', 'grayed' => false, 'nearestPlayableFramesPerRound' => null],
        ])
        ->where('avatars.options', AvatarPresetCatalog::options())
        ->where('avatars.taken', [])
        ->where('avatars.suggested', AvatarPresetCatalog::suggest(null, []))
        ->where('nickname', ['min' => NicknameNormalizer::MIN_LENGTH, 'max' => NicknameNormalizer::MAX_LENGTH])
        ->missing('state')
        ->missing('seatToken'));

    // Un porteur de siège solo n'y revient pas : sa relance vit dans `game/solo`.
    [$token] = soloStarted($this);
    soloActAs($this, $token);

    $this->get(route('solo.create'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('solo.show'));
});

it('ramène d\'office un preset au N injouable au N jouable le plus proche et le passe en flash settingsNotice', function (): void {
    // Catalogue en passe 1 : Hardcore (N = 5) n'a aucune œuvre à 5 ni à 4.
    soloCatalogue(soloRoundsOf(SettingPresetKey::Hardcore));
    $preset = SettingPresetCatalog::settingsFor(SettingPresetKey::Hardcore);
    $applied = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;

    $response = soloStart($this, soloFirstBody(SettingPresetKey::Hardcore))
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas(SoloGameController::SETTINGS_NOTICE, [
            'preset' => SettingPresetKey::Hardcore->value,
            'requestedFramesPerRound' => $preset->framesPerRound,
            'appliedFramesPerRound' => $applied,
        ]);

    $game = soloGameOf(Player::query()->whereNull('room_id')->heldByToken(SeatEntry::tokenFrom($response))->sole());
    $snapshot = $game->settings_snapshot;

    // Hardcore en Expert à N = 3 : les champs dérivés redérivés par la règle
    // Simple — paliers réégalisés sur la même durée, barème par défaut du N
    // appliqué —, le reste du preset intact.
    expect($game->frames_per_round)->toBe($applied)
        ->and($game->input_difficulty)->toBe(InputDifficulty::Expert)
        ->and($game->rounds_count)->toBe($preset->roundsCount)
        ->and($snapshot->framesPerRound)->toBe($applied)
        ->and($snapshot->roundDuration())->toBe($preset->roundDuration())
        ->and($snapshot->tierDurations)->toBe(RoomSettingsBounds::defaultTierDurations($applied, $preset->roundDuration()))
        ->and($snapshot->tierPoints)->toBe(RoomSettingsBounds::defaultTierPoints($applied))
        ->and($snapshot->revealDuration)->toBe($preset->revealDuration)
        ->and($snapshot->attemptsPerRound)->toBe($preset->attemptsPerRound)
        ->and($snapshot->equals(RoomSettings::fromInput([...$snapshot->toPayload()])))->toBeTrue();

    // Un preset jouable tel quel : aucune annonce.
    $this->flushSession();

    soloStart($this, soloFirstBody())
        ->assertRedirect(route('solo.show'))
        ->assertSessionMissing(SoloGameController::SETTINGS_NOTICE);
});

it('exige pseudo et avatar d\'un premier siège solo, jamais d\'une reprise, et nomme le champ preset dans la langue du joueur', function (): void {
    soloCatalogue(soloRoundsOf());

    // Premier siège : pseudo et avatar exigés ; aucun jeton frappé.
    $response = soloStart($this, ['preset' => SOLO_PRESET->value])
        ->assertRedirect(route('solo.create'))
        ->assertSessionHasErrors(['nickname', 'avatar']);

    expect(SeatEntry::tokenCookies($response))->toBe([])
        ->and(Player::query()->count())->toBe(0);

    // Un preset inconnu : refusé sous son libellé traduit, jamais le nom brut.
    foreach ([Locale::French, Locale::English] as $locale) {
        $this->flushSession();

        $this->withHeader('Accept-Language', $locale->value)
            ->from(route('solo.create'))
            ->post(route('solo.store'), ['preset' => 'marathon', ...SeatEntry::form()])
            ->assertSessionHasErrors('preset');

        $errors = session('errors');

        expect($errors)->toBeInstanceOf(ViewErrorBag::class)
            ->and($errors instanceof ViewErrorBag ? $errors->getBag('default')->first('preset') : '')
            ->toContain(soloMessage('validation.attributes.preset', $locale))
            ->not->toContain('validation.');
    }

    // Reprise : le preset seul suffit.
    $this->flushSession();
    [$token] = soloStarted($this);
    soloActAs($this, $token);
    $this->travelTo($this->now->addSecond());

    soloStart($this, ['preset' => SOLO_PRESET->value], route('solo.show'))->assertSessionHasNoErrors();
});

it('reprend le siège solo écrit par un lancement concurrent quand l\'unicité refuse le second', function (): void {
    soloCatalogue(soloRoundsOf());
    $token = PlayerToken::mint(Locale::English);
    soloActAs($this, $token);

    // Un premier lancement concurrent écrit le siège solo du jeton entre la
    // lecture verrouillante (vide) et l'insertion : `player_solo_token_uq`
    // refuse la seconde, qui relit le siège existant.
    $rival = null;

    Player::creating(static function (Player $seat) use ($token, &$rival): void {
        if ($rival !== null || $seat->room_id !== null) {
            return;
        }

        $rival = SeatPublicId::generate();

        DB::table('player')->insert([
            'public_id' => $rival,
            'room_id' => null,
            'nickname' => 'Rival',
            'nickname_normalized' => NicknameNormalizer::normalize('Rival'),
            'player_token_hash' => $token->hash(),
            'solo_token_hash' => $token->hash(),
            'locale' => Locale::English->value,
            'avatar_kind' => AvatarKind::Preset->value,
            'avatar_preset' => SeatEntry::avatar(1),
            'joined_at' => '2026-09-27 10:15:00.250',
            'connection_state' => PlayerConnectionState::Connected->value,
            'last_seen_at' => '2026-09-27 10:15:00.250',
            'created_at' => '2026-09-27 10:15:00.250',
            'updated_at' => '2026-09-27 10:15:00.250',
        ]);
    });

    soloStart($this, soloFirstBody())
        ->assertRedirect(route('solo.show'))
        ->assertSessionHasNoErrors();

    $seat = Player::query()->sole();

    expect($seat->public_id)->toBe($rival)
        ->and($seat->nickname)->toBe('Rival')
        ->and(soloGameOf($seat)->mode)->toBe(GameMode::Solo)
        ->and(Game::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Lot L60-16 — la page `game/solo`, le sondage, le battement et les gestes
|--------------------------------------------------------------------------
*/

/**
 * Une partie solo dont la manche 1 est ouverte à son palier 1 par les
 * transitions réelles (`SubmissionFixtures::openedRound`) : siège solo tenu
 * par un jeton neuf, onglet actif frappé, `M` au minimum des bornes, tout le
 * reste au défaut. L'horloge est laissée à `T₁` (à la programmation, sans
 * ouverture).
 *
 * @return array{0: PlayerToken, 1: Player, 2: Game, 3: Round}
 */
function soloOpened(InputDifficulty $difficulty = InputDifficulty::Expert, int $reserve = 0, ?Movie $target = null, bool $open = true): array
{
    $token = PlayerToken::mint(Locale::English);

    [$game, $round, [$seat]] = SubmissionFixtures::openedRound(
        [$token],
        SubmissionFixtures::settings($difficulty),
        $target,
        solo: true,
        reserve: $reserve,
        open: $open,
    );

    return [$token, $seat, $game, $round];
}

/**
 * L'en-tête de l'onglet actif du siège, relu en base.
 *
 * @return array<string, string>
 */
function soloTabHeader(Player $seat): array
{
    return [EnsureActiveSeat::HEADER => (string) $seat->refresh()->active_seat_token];
}

/**
 * Un geste solo par la route (`solo.reveal`, `solo.skip`, `solo.next`), tel
 * que le client l'envoie : JSON, cookie du jeton, onglet actif, reçu à `$at`
 * (l'horloge courante sinon).
 *
 * @return TestResponse<Response>
 */
function soloGesture(TestCase $test, PlayerToken $token, Player $seat, string $route, ?CarbonImmutable $at = null): TestResponse
{
    if ($at instanceof CarbonImmutable) {
        Date::setTestNow($at);
    }

    soloActAs($test, $token);

    return $test->postJson(route($route), [], soloTabHeader($seat));
}

/**
 * Le sondage `solo.state` par la route, reçu à `$at`.
 *
 * @return TestResponse<Response>
 */
function soloPoll(TestCase $test, PlayerToken $token, Player $seat, ?CarbonImmutable $at = null): TestResponse
{
    if ($at instanceof CarbonImmutable) {
        Date::setTestNow($at);
    }

    soloActAs($test, $token);

    return $test->getJson(route('solo.state'), soloTabHeader($seat));
}

/**
 * Le battement `solo.heartbeat` par la route, reçu à `$at`.
 *
 * @return TestResponse<Response>
 */
function soloBeat(TestCase $test, PlayerToken $token, ?CarbonImmutable $at = null): TestResponse
{
    if ($at instanceof CarbonImmutable) {
        Date::setTestNow($at);
    }

    soloActAs($test, $token);

    return $test->postJson(route('solo.heartbeat'));
}

/**
 * Passe, une à une, toutes les manches restantes d'une partie solo jusqu'à
 * son gel : chaque manche programmée est ouverte par le sondage (le
 * rattrapage), puis passée. Rend la réponse du dernier geste.
 *
 * @return TestResponse<Response>
 */
function soloSkipToEnd(TestCase $test, PlayerToken $token, Player $seat, Game $game): TestResponse
{
    $last = null;

    while ($game->refresh()->ended_at === null) {
        $next = Round::query()
            ->where('game_id', $game->id)
            ->whereIn('status', [RoundStatus::Running->value, RoundStatus::Pending->value])
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->first()
            ?? throw new LogicException('Aucune manche programmée dans une partie en cours.');

        $startedAt = $next->started_at ?? throw new LogicException('Manche sans origine de temps.');
        $at = Date::now()->toImmutable()->max($startedAt)->addMilliseconds(1234);

        soloPoll($test, $token, $seat, $at)->assertOk()->assertJsonPath('round.phase', 'running');
        $last = soloGesture($test, $token, $seat, 'solo.skip', $at)->assertOk();
    }

    return $last ?? throw new LogicException('Partie déjà gelée.');
}

/** Le titre anglais du film de la manche : une réponse toujours acceptée (70). */
function soloAnswerOf(Round $round): string
{
    $title = $round->movie->titles()->where('locale', Locale::English->value)->value('title');

    return is_string($title) ? $title : throw new LogicException('Film sans titre anglais.');
}

it('voir la réponse clôt en revealed et ouvre une révélation de durée R sans guess', function (): void {
    [$token, $seat, $game, $round] = soloOpened();
    $t1 = $round->started_at ?? throw new LogicException('Manche non programmée.');
    $revealedAt = $t1->addMilliseconds(2345);

    $response = soloGesture($this, $token, $seat, 'solo.reveal', $revealedAt)->assertOk();

    $round->refresh();
    $participation = SubmissionFixtures::participation($round, $seat);
    $revealStartsAt = $revealedAt->addMilliseconds($game->tier_grace_ms);
    $revealEndsAt = $revealStartsAt->addSeconds($game->settings_snapshot->revealDuration);

    // La saisie close en `revealed` à l'instant du geste ; le siège solo, seul
    // participant, déclenche la fin anticipée à ce même instant — bien avant
    // D — et la révélation normale, de durée R, est programmée.
    expect($participation->input_state)->toBe(RoundPlayerInputState::Revealed)
        ->and($participation->input_closed_at?->equalTo($revealedAt))->toBeTrue()
        ->and($round->status)->toBe(RoundStatus::Running)
        ->and($round->ended_at?->equalTo($revealedAt))->toBeTrue()
        ->and($round->ended_at?->lessThan(EngineFixtures::durationEnd($round)))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($revealEndsAt))->toBeTrue();

    Queue::assertPushedOn('game', AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id
        && $job->step === RoundStep::Reveal
        && $job->dueAt === WireTime::iso($revealStartsAt));

    // Le geste répond par le paquet à jour : phase `closed`, instants de la
    // révélation, et encore aucun titre avant `revealStartsAt`.
    $response->assertJsonPath('mode', GameMode::Solo->value)
        ->assertJsonPath('round.phase', 'closed')
        ->assertJsonPath('round.endedAt', WireTime::iso($revealedAt))
        ->assertJsonPath('round.revealStartsAt', WireTime::iso($revealStartsAt))
        ->assertJsonPath('round.revealEndsAt', WireTime::iso($revealEndsAt))
        ->assertJsonPath('round.reveal', null)
        ->assertJsonPath('self.input.inputState', RoundPlayerInputState::Revealed->value);

    expect((string) $response->getContent())->not->toContain(soloAnswerOf($round));

    // Au sondage de `revealStartsAt`, la révélation normale : le film, personne
    // n'a trouvé, zéro point.
    $reveal = soloPoll($this, $token, $seat, $revealStartsAt)->assertOk()
        ->assertJsonPath('round.phase', 'revealing')
        ->assertJsonPath('round.reveal.movie.originalTitle', $round->movie->title_original)
        ->assertJsonPath('round.reveal.finders', [])
        ->assertJsonPath('self.ownScore', 0);

    expect($round->refresh()->status)->toBe(RoundStatus::Revealing)
        ->and($reveal->json('round.revealEndsAt'))->toBe(WireTime::iso($revealEndsAt))
        ->and(Guess::query()->count())->toBe(0)
        ->and(SubmissionFixtures::participation($round, $seat)->input_state)->toBe(RoundPlayerInputState::Revealed);

    // Elle dure R : la manche se complète à `reveal_ends_at`, où la suivante
    // s'ouvre ; la manche révélée ne compte aucune bonne réponse.
    soloPoll($this, $token, $seat, $revealEndsAt)->assertOk()
        ->assertJsonPath('round.sequenceIndex', 2)
        ->assertJsonPath('round.phase', 'running');

    expect($round->refresh()->status)->toBe(RoundStatus::Completed)
        ->and($game->refresh()->rounds_completed)->toBe(1)
        ->and(Guess::query()->count())->toBe(0);
});

it('passer la manche clôt en skipped et programme la suivante sans révélation', function (): void {
    [$token, $seat, $game, $round] = soloOpened();
    $t1 = $round->started_at ?? throw new LogicException('Manche non programmée.');
    $skippedAt = $t1->addMilliseconds(3456);

    $response = soloGesture($this, $token, $seat, 'solo.skip', $skippedAt)->assertOk();

    $round->refresh();
    $game->refresh();
    $participation = SubmissionFixtures::participation($round, $seat);
    $next = EngineFixtures::round($game, 2);
    $startsAt = $skippedAt->addMilliseconds($game->preload_lead_ms + EngineConstants::nextRoundMarginMs());

    // La saisie close en `skipped`, la manche close et « révélée » au même
    // instant — celui du geste —, `completed` d'emblée, comptée jouée.
    expect($participation->input_state)->toBe(RoundPlayerInputState::Skipped)
        ->and($participation->input_closed_at?->equalTo($skippedAt))->toBeTrue()
        ->and($round->status)->toBe(RoundStatus::Completed)
        ->and($round->ended_at?->equalTo($skippedAt))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($skippedAt))->toBeTrue()
        ->and($game->rounds_completed)->toBe(1)
        ->and($game->status)->toBe(GameStatus::Running)
        ->and(Guess::query()->count())->toBe(0);

    // La suivante, programmée à `now + preload_lead_ms + nextRoundMarginMs` :
    // son palier 1 garde une fenêtre de préchargement entière.
    expect($next->started_at?->equalTo($startsAt))->toBeTrue()
        ->and($next->status)->toBe(RoundStatus::Pending);

    Queue::assertPushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $next->id
        && $job->step === RoundStep::OpenTier
        && $job->tierIndex === 1
        && $job->dueAt === WireTime::iso($startsAt));

    // Aucune révélation : aucun job de révélation pour la manche passée, et le
    // paquet porte déjà la manche suivante, programmée, sans titre.
    Queue::assertNotPushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id
        && in_array($job->step, [RoundStep::Reveal, RoundStep::EndReveal], true));

    $response->assertJsonPath('round.sequenceIndex', 2)
        ->assertJsonPath('round.phase', 'scheduled')
        ->assertJsonPath('round.startsAt', WireTime::iso($startsAt))
        ->assertJsonPath('round.reveal', null);

    expect((string) $response->getContent())->not->toContain(soloAnswerOf($round));

    // Au `T₁` suivant, la manche 2 s'ouvre ; la manche passée n'a jamais été
    // révélée.
    soloPoll($this, $token, $seat, $startsAt)->assertOk()
        ->assertJsonPath('round.sequenceIndex', 2)
        ->assertJsonPath('round.phase', 'running');

    expect($round->refresh()->status)->toBe(RoundStatus::Completed)
        ->and($round->reveal_ends_at?->equalTo($round->ended_at))->toBeTrue();
});

it('revealed et skipped sont refusés hors solo', function (): void {
    $token = PlayerToken::mint(Locale::English);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);
    $before = soloRawRows($game);

    expect($game->mode)->toBe(GameMode::Multiplayer);

    // Les actions lèvent hors solo, avant toute lecture (barrière 1 de 10 § 7.10).
    foreach ([RevealSoloAnswer::class, SkipSoloRound::class] as $action) {
        expect(fn () => app($action)->handle($game, $seat, Date::now()->toImmutable()))
            ->toThrow(LogicException::class);
    }

    // Les routes ne résolvent que le siège SOLO du jeton : un siège de salon
    // n'en a aucun, et chaque geste est refusé en 403.
    foreach (['solo.reveal', 'solo.skip', 'solo.next'] as $route) {
        soloGesture($this, $token, $seat, $route)->assertForbidden();
    }

    // Le même jeton tient aussi un siège solo, sans partie : la route résout
    // celui-là, jamais la partie du salon, et refuse faute de manche en cours.
    $solo = Player::factory()->solo()->create(['player_token_hash' => $token->hash()]);

    foreach (['solo.reveal', 'solo.skip'] as $route) {
        soloGesture($this, $token, $solo, $route)
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertExactJson(['code' => 'round_not_running']);
    }

    // La partie multijoueur n'a rien vu : ni `revealed`, ni `skipped`.
    expect(soloRawRows($game))->toBe($before)
        ->and(RoundPlayer::query()->whereIn('input_state', [RoundPlayerInputState::Revealed->value, RoundPlayerInputState::Skipped->value])->count())->toBe(0)
        ->and(SubmissionFixtures::participation($round, $seat)->input_state)->toBe(RoundPlayerInputState::Open);
});

it('une partie solo n\'émet aucun événement de diffusion', function (): void {
    $this->withoutVite();
    $recorder = RecordingBroadcaster::install();
    soloCatalogue(soloRoundsOf());

    [$token, $seat, $game] = soloStarted($this);
    soloActAs($this, $token);
    $this->get(route('solo.show'))->assertOk();

    // Manche 1 : ouverte au sondage de T₁ ; une réponse fausse, puis la bonne
    // — verrou, fin anticipée —, la révélation au sondage.
    $first = Round::query()->where('game_id', $game->id)->where('round_number', 1)->sole();
    $t1 = soloFirstRoundStart($game);

    soloPoll($this, $token, $seat, $t1)->assertOk()->assertJsonPath('round.phase', 'running');
    SubmissionFixtures::submit($this, $seat->refresh(), $token, SubmissionFixtures::WRONG, $t1->addSecond())->assertOk();
    SubmissionFixtures::submit($this, $seat, $token, soloAnswerOf($first), $t1->addSeconds(2))
        ->assertOk()
        ->assertJsonPath('result', 'accepted');

    $revealStartsAt = ($first->refresh()->ended_at ?? throw new LogicException('Manche non close.'))
        ->addMilliseconds($game->tier_grace_ms);

    soloPoll($this, $token, $seat, $revealStartsAt)->assertOk()->assertJsonPath('round.phase', 'revealing');

    // « Manche suivante » raccourcit la révélation ; la manche 2 s'ouvre à
    // son nouveau T₁.
    soloGesture($this, $token, $seat, 'solo.next', $revealStartsAt->addSecond())->assertOk();
    $second = Round::query()->where('game_id', $game->id)->where('round_number', 2)->sole();
    $t1Second = $second->started_at ?? throw new LogicException('Manche 2 non programmée.');

    soloPoll($this, $token, $seat, $t1Second)->assertOk()->assertJsonPath('round.phase', 'running');

    // Manche 2 : « Voir la réponse », révélation, fin de révélation.
    soloGesture($this, $token, $seat, 'solo.reveal', $t1Second->addSecond())->assertOk();
    soloPoll($this, $token, $seat, $second->refresh()->reveal_ends_at)->assertOk();

    // Les manches suivantes : « Passer la manche », jusqu'au gel.
    soloSkipToEnd($this, $token, $seat, $game)->assertJsonPath('status', GameStatus::Completed->value);

    expect($game->refresh()->status)->toBe(GameStatus::Completed)
        ->and(GamePlayer::query()->where('game_id', $game->id)->sole()->correct_answers)->toBe(1)
        // Toute la partie : ni salon, ni canal, rien sur le fil.
        ->and($recorder->sent)->toBe([]);
});

it('un preset au N injouable est ramené au N jouable le plus proche et la page l\'annonce', function (): void {
    $this->withoutVite();
    soloCatalogue(soloRoundsOf(SettingPresetKey::Hardcore));
    $preset = SettingPresetCatalog::settingsFor(SettingPresetKey::Hardcore);
    $applied = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;
    $notice = [
        'preset' => SettingPresetKey::Hardcore->value,
        'requestedFramesPerRound' => $preset->framesPerRound,
        'appliedFramesPerRound' => $applied,
    ];

    $response = soloStart($this, soloFirstBody(SettingPresetKey::Hardcore))->assertRedirect(route('solo.show'));
    soloActAs($this, SeatEntry::tokenFrom($response));

    // La page où mène la redirection reçoit l'annonce, en données, et la
    // partie se joue au N appliqué.
    $this->get(route('solo.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('game/solo')
        ->where('settingsNotice', $notice)
        ->where('state.mode', GameMode::Solo->value)
        ->where('state.framesPerRound', $applied));

    // Une annonce, pas un état : la page rechargée ne la rejoue pas.
    $this->get(route('solo.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('game/solo')
        ->where('settingsNotice', null));

    // La page l'annonce, dans les deux langues : le texte porte le preset et
    // les deux N, formatés par le client ; le hook de la page le compose
    // depuis la prop et le dit dans l'unique région vivante, la page le rend.
    foreach ([Locale::French, Locale::English] as $locale) {
        $text = trans('game.solo.frames_adjusted', ['preset' => 'PRESET', 'requested' => '97', 'applied' => '13'], $locale->value);

        expect($text)->toBeString()
            ->toContain('PRESET')
            ->toContain('97')
            ->toContain('13')
            ->not->toContain(':preset')
            ->not->toContain(':requested')
            ->not->toContain(':applied');
    }

    $hook = FrontSource::withoutComments((string) file_get_contents(resource_path('js/hooks/game/use-solo-state.ts')));
    $page = FrontSource::withoutComments((string) file_get_contents(resource_path('js/pages/game/solo.tsx')));

    expect($hook)->toContain("'game.solo.frames_adjusted'")
        ->toContain('announce(')
        ->toContain('settingsNotice')
        ->and($page)->toContain('noticeMessage')
        ->toContain('settingsNotice');
});

it('solo.state et solo.heartbeat répondent 403 sans siège solo', function (): void {
    // Sans jeton : refus, et aucun jeton frappé.
    $state = $this->getJson(route('solo.state'))->assertForbidden();
    $beat = $this->postJson(route('solo.heartbeat'))->assertForbidden();

    expect(SeatEntry::tokenCookies($state))->toBe([])
        ->and(SeatEntry::tokenCookies($beat))->toBe([]);

    // Un jeton qui ne tient qu'un siège de salon : aucun siège solo. Les
    // gestes, sous `seat.active`, sont refusés de même.
    $token = PlayerToken::mint(Locale::French);
    LobbyWrites::hostedRoom($token);
    soloActAs($this, $token);

    $this->getJson(route('solo.state'))->assertForbidden();
    $this->postJson(route('solo.heartbeat'))->assertForbidden();

    foreach (['solo.reveal', 'solo.skip', 'solo.next'] as $route) {
        $this->postJson(route($route))->assertForbidden();
    }

    expect(Player::query()->whereNull('room_id')->count())->toBe(0);

    // Témoin : le même jeton, une fois son siège solo né, lit son paquet —
    // sans partie, sans canal, `[soi]` — et bat.
    $solo = Player::factory()->solo()->create(['player_token_hash' => $token->hash()]);

    $this->getJson(route('solo.state'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('mode', GameMode::Solo->value)
        ->assertJsonPath('channels', null)
        ->assertJsonPath('gameRef', null)
        ->assertJsonPath('round', null)
        ->assertJsonPath('seats.0.publicId', $solo->public_id);

    $this->postJson(route('solo.heartbeat'))->assertNoContent();
});

it('voir la réponse clôt la manche même si le siège solo était passé disconnected', function (): void {
    [$token, $seat, $game, $round] = soloOpened();
    $t1 = $round->started_at ?? throw new LogicException('Manche non programmée.');

    // Le siège bat à T₁, puis son onglet passe en arrière-plan : le balayage
    // le passe `disconnected`. Il sort des participants sans clore la manche
    // — zéro participant ne clôt jamais une manche.
    soloBeat($this, $token, $t1)->assertNoContent();
    $sweep = PresenceFixtures::lastSweep();
    PresenceFixtures::run($sweep);

    expect($seat->refresh()->connection_state)->toBe(PlayerConnectionState::Disconnected)
        ->and($round->refresh()->ended_at)->toBeNull();

    // Il touche « Voir la réponse » avant son battement de retour : le geste
    // vaut battement, le siège redevient participant, et la fin anticipée
    // clôt la manche à l'instant du geste, pas à D.
    $revealedAt = PresenceFixtures::dueAt($sweep)->addMilliseconds(1500);

    expect($revealedAt->lessThan(EngineFixtures::durationEnd($round)))->toBeTrue();

    soloGesture($this, $token, $seat, 'solo.reveal', $revealedAt)->assertOk()
        ->assertJsonPath('round.phase', 'closed');

    $seat->refresh();
    $round->refresh();

    expect($seat->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and($seat->disconnected_at)->toBeNull()
        ->and($seat->last_seen_at->equalTo($revealedAt))->toBeTrue()
        ->and(SubmissionFixtures::participation($round, $seat)->input_state)->toBe(RoundPlayerInputState::Revealed)
        ->and($round->ended_at?->equalTo($revealedAt))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($revealedAt->addMilliseconds($game->tier_grace_ms)->addSeconds($game->settings_snapshot->revealDuration)))->toBeTrue();

    // Le geste réarme aussi le balayage du siège, à sa propre échéance.
    expect(PresenceFixtures::dueAt(PresenceFixtures::lastSweep())->equalTo($revealedAt->addMilliseconds(EngineConstants::disconnectAfterMs())->ceilSecond()))->toBeTrue();
});

it('hors précondition, voir la réponse et passer la manche répondent 409 round_not_running', function (): void {
    $refused = static function (TestCase $test, PlayerToken $token, Player $seat, Game $game, CarbonImmutable $at): void {
        // L'état échu d'abord (le sondage rattrape) : le geste, qui rattrape
        // aussi avant de lire la phase, n'écrit alors plus rien de lui-même.
        soloPoll($test, $token, $seat, $at)->assertOk();
        $before = soloRawRows($game);

        foreach (['solo.reveal', 'solo.skip'] as $route) {
            soloGesture($test, $token, $seat, $route, $at)
                ->assertStatus(Response::HTTP_CONFLICT)
                ->assertExactJson(['code' => 'round_not_running']);
        }

        // Rien n'est écrit sur la partie : ni saisie, ni manche.
        expect(soloRawRows($game))->toBe($before);
    };

    // 1. Pendant le décompte : la manche est programmée, pas ouverte.
    [$token, $seat, $game, $round] = soloOpened(open: false);
    $t1 = $round->started_at ?? throw new LogicException('Manche non programmée.');

    $refused($this, $token, $seat, $game, $t1->subSecond());

    expect($round->refresh()->status)->toBe(RoundStatus::Pending);

    // 2. Après la bonne réponse : le siège est verrouillé, la manche close
    // d'anticipation — dans la grâce finale, puis pendant la révélation.
    [$token, $seat, $game, $round] = soloOpened(target: SubmissionFixtures::movie('Quintessence Nocturne'));
    $t1 = $round->started_at ?? throw new LogicException('Manche non programmée.');

    // « Manche suivante » hors révélation : son propre 409 (§ 5.4).
    soloGesture($this, $token, $seat, 'solo.next', $t1->addMilliseconds(500))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(['code' => 'not_revealing']);

    SubmissionFixtures::submit($this, $seat, $token, 'Quintessence Nocturne', $t1->addSecond())->assertOk();
    $endedAt = $round->refresh()->ended_at ?? throw new LogicException('Manche non close d’anticipation.');

    $refused($this, $token, $seat, $game, $endedAt->addMilliseconds(intdiv($game->tier_grace_ms, 2)));
    $refused($this, $token, $seat, $game, $endedAt->addMilliseconds($game->tier_grace_ms)->addSecond());

    expect($round->refresh()->status)->toBe(RoundStatus::Revealing)
        ->and(SubmissionFixtures::participation($round, $seat)->input_state)->toBe(RoundPlayerInputState::Locked);

    // 3. Saisie close dans une manche qui court encore : le siège, passé
    // `disconnected`, a trouvé sans clore la manche (zéro participant ne la
    // clôt jamais). Son geste vaut battement, mais sa saisie n'est plus
    // ouverte : refus, et le siège reste `locked` (`locked` ⟺ `guess`).
    [$token, $seat, $game, $round] = soloOpened(target: SubmissionFixtures::movie('Vespertine Horizon'));
    $t1 = $round->started_at ?? throw new LogicException('Manche non programmée.');

    soloBeat($this, $token, $t1)->assertNoContent();
    PresenceFixtures::run(PresenceFixtures::lastSweep());
    $answeredAt = Date::now()->toImmutable()->addSecond();

    SubmissionFixtures::submit($this, $seat, $token, 'Vespertine Horizon', $answeredAt)->assertOk();

    expect($round->refresh()->ended_at)->toBeNull()
        ->and(SubmissionFixtures::participation($round, $seat)->input_state)->toBe(RoundPlayerInputState::Locked);

    $refused($this, $token, $seat, $game, $answeredAt->addSecond());

    expect($round->refresh()->ended_at)->toBeNull()
        ->and(SubmissionFixtures::participation($round, $seat)->input_state)->toBe(RoundPlayerInputState::Locked)
        ->and(Guess::query()->where('round_id', $round->id)->count())->toBe(1);

    // Le code est rendu dans les deux langues par le client.
    foreach ([Locale::French, Locale::English] as $locale) {
        soloMessage(SoloRoundController::KEY_ROUND_NOT_RUNNING, $locale);
    }
});

it('passer la manche n\'écrit jamais ended_at au-delà de started_at + D', function (): void {
    // 1. Une milliseconde avant D : l'instant du geste.
    [$token, $seat, , $round] = soloOpened();
    $durationEnd = EngineFixtures::durationEnd($round);

    soloGesture($this, $token, $seat, 'solo.skip', $durationEnd->subMillisecond())->assertOk();
    $round->refresh();

    expect($round->status)->toBe(RoundStatus::Completed)
        ->and($round->ended_at?->equalTo($durationEnd->subMillisecond()))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($durationEnd->subMillisecond()))->toBeTrue();

    // 2. Reçu après D, le job de clôture en retard : le rattrapage du geste
    // clôt d'abord la manche à D, et le geste est refusé — jamais un
    // `ended_at` à l'heure du geste.
    [$token, $seat, , $late] = soloOpened();
    $lateEnd = EngineFixtures::durationEnd($late);

    expect($late->refresh()->ended_at)->toBeNull();

    soloGesture($this, $token, $seat, 'solo.skip', $lateEnd->addMilliseconds(1500))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(['code' => 'round_not_running']);

    expect($late->refresh()->ended_at?->equalTo($lateEnd))->toBeTrue()
        ->and(SubmissionFixtures::participation($late, $seat)->input_state)->toBe(RoundPlayerInputState::Open);

    // 3. La clôture elle-même borne l'instant qu'on lui passe à D.
    [, , $game, $direct] = soloOpened();
    $directEnd = EngineFixtures::durationEnd($direct);

    DB::transaction(static fn (): CarbonImmutable => SoloRoundClosure::skip($game->refresh(), $direct->refresh(), $directEnd->addSeconds(5)));
    $direct->refresh();

    expect($direct->ended_at?->equalTo($directEnd))->toBeTrue()
        ->and($direct->reveal_ends_at?->equalTo($directEnd))->toBeTrue();
});

it('ni l\'annulation d\'une manche solo, ni le battement, ni le balayage de présence, ni la prise d\'onglet, ni la fin d\'une partie solo n\'émettent d\'événement de diffusion', function (): void {
    $this->withoutVite();
    $recorder = RecordingBroadcaster::install();
    [$token, $seat, $game, $round] = soloOpened(reserve: 1);

    // L'annulation : la variante du palier 2, frappée à l'ouverture du
    // palier 1, n'est plus servable à T₂ ; le sondage rattrape l'ouverture,
    // qui annule la manche, et la remplaçante est programmée.
    $served = EngineFixtures::tier($round, 2)->served_frame_id;
    Frame::query()->whereKey($served)->update(['availability' => ContentAvailability::Draft->value]);

    soloPoll($this, $token, $seat, EngineFixtures::opensAt($round, 2))->assertOk();
    $round->refresh();

    expect($round->status)->toBe(RoundStatus::Cancelled)
        ->and($round->cancel_reason)->toBe(RoundIncidentReason::FrameUnavailable)
        ->and(Round::query()->where('game_id', $game->id)->where('round_number', 1)->where('status', RoundStatus::Pending->value)->whereNotNull('started_at')->count())->toBe(1);

    // Le battement.
    soloBeat($this, $token)->assertNoContent();

    // La prise d'onglet : deux chargements complets sans en-tête, chacun
    // supplantant l'onglet précédent.
    $previous = $seat->refresh()->active_seat_token;
    $this->get(route('solo.show'))->assertOk();
    $this->get(route('solo.show'))->assertOk();

    expect($seat->refresh()->active_seat_token)->not->toBe($previous);

    // Le balayage de présence : le siège se tait et passe `disconnected`.
    PresenceFixtures::run(PresenceFixtures::lastSweep());

    expect($seat->refresh()->connection_state)->toBe(PlayerConnectionState::Disconnected);

    // La fin de la partie : les manches restantes passées, jusqu'au gel.
    soloSkipToEnd($this, $token, $seat, $game)->assertJsonPath('status', GameStatus::Completed->value);

    expect($game->refresh()->status)->toBe(GameStatus::Completed)
        ->and($recorder->sent)->toBe([]);
});

it('passer la dernière manche gèle la partie en completed à reveal_ends_at de cette manche', function (): void {
    $journal = GameJournalRecorder::start();
    [$token, $seat, $game] = soloOpened();

    $response = soloSkipToEnd($this, $token, $seat, $game);

    $last = Round::query()->where('game_id', $game->id)->where('round_number', $game->rounds_count)->sole();
    $game->refresh();

    // Gelée `completed`, à `reveal_ends_at` de la manche passée — son instant
    // de clôture, sans révélation.
    expect($game->status)->toBe(GameStatus::Completed)
        ->and($last->status)->toBe(RoundStatus::Completed)
        ->and($last->reveal_ends_at?->equalTo($last->ended_at))->toBeTrue()
        ->and($game->ended_at?->equalTo($last->reveal_ends_at))->toBeTrue()
        ->and($game->rounds_completed)->toBe($game->rounds_count);

    // Les agrégats du siège sont gelés : aucune bonne réponse, aucun rang en solo.
    $frozen = GamePlayer::query()->where('game_id', $game->id)->sole();

    expect($frozen->correct_answers)->toBe(0)
        ->and($frozen->final_score)->toBe(0)
        ->and($frozen->final_rank)->toBeNull();

    // Le geste répond par le podium, et plus aucune transition n'est attendue.
    $response->assertJsonPath('status', GameStatus::Completed->value)
        ->assertJsonPath('podium.gameStatus', GameStatus::Completed->value)
        ->assertJsonPath('round', null)
        ->assertJsonPath('nextTransitionAt', null);

    expect(array_column($journal->contexts(GameJournal::GAME_FINALIZED), 'outcome'))->toBe([GameStatus::Completed->value]);

    $journal->stop();
});

it('passe à game/solo les limites de plateforme et les presets de relance', function (): void {
    $this->withoutVite();
    soloCatalogue(soloRoundsOf());

    [$token, $seat, $game] = soloStarted($this);
    soloActAs($this, $token);

    // `limits` : la prop de page du lobby (`PlatformLimits::toArray()`), dont
    // l'aide lit `speedBonusMaxPercent` ; `presets` : les quatre presets et
    // leur N jouable le plus proche sur le vivier catalogue, même forme que
    // sur `room/solo` (passe 1 : Hardcore à 3).
    $this->get(route('solo.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('game/solo')
        ->where('limits', PlatformLimits::current()->toArray())
        ->where('presets', app(SoloPresets::class)->options())
        ->where('presets', [
            ['key' => 'classic', 'grayed' => false, 'nearestPlayableFramesPerRound' => null],
            ['key' => 'fast', 'grayed' => false, 'nearestPlayableFramesPerRound' => null],
            ['key' => 'hardcore', 'grayed' => true, 'nearestPlayableFramesPerRound' => RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND],
            ['key' => 'discovery', 'grayed' => false, 'nearestPlayableFramesPerRound' => null],
        ])
        ->where('settingsNotice', null)
        ->where('seatToken', $seat->refresh()->active_seat_token)
        ->where('state.mode', GameMode::Solo->value)
        ->where('state.framesPerRound', $game->frames_per_round)
        ->missing('state.seatToken'));

    // L'aide trouve son plafond de bonus pour le N de la partie.
    expect(PlatformLimits::current()->toArray()['speedBonusMaxPercent'])->toHaveKey($game->frames_per_round);
});
