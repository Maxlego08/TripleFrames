<?php

use App\Avatars\AvatarPresetCatalog;
use App\Enums\AvatarKind;
use App\Enums\ContentAvailability;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Enums\SettingPresetKey;
use App\Http\Controllers\Game\SoloGameController;
use App\Models\Frame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Settings\EngineConstants;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Settings\SettingPresetCatalog;
use App\Support\Deploy\DeployDrain;
use App\Support\Draw\PoolTooSmallException;
use App\Support\Game\GameJournal;
use App\Support\Identity\NicknameNormalizer;
use App\Support\Identity\PlayerToken;
use App\Support\Room\SeatPublicId;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
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
use Tests\Support\Game\GameJournalRecorder;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Mode solo : démarrage et page d'entrée — spec 60 § 16.2 à § 16.4 et § 16.6,
| contrat C7 § 4.12, E10-N3 (lot L60-15)
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
| Les gestes, la page `game/solo` et son sondage appartiennent au lot
| L60-16 : aucune redirection vers `solo.show` n'est suivie ici, sauf pour
| prouver le premier passage.
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
