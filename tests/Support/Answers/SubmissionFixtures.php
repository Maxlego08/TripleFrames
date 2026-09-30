<?php

namespace Tests\Support\Answers;

use App\Actions\Game\CatchUpGame;
use App\Actions\Game\ComposeChoiceSets;
use App\Actions\Game\MaterializeDraw;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Settings\EngineConstants;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Identity\PlayerToken;
use App\ValueObjects\Answers\DecoyPick;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\LobbyWrites;
use Tests\TestCase;

/**
 * Fixtures de la soumission (spec 70 § 7 et § 9, lots L70-5, L70-6 et
 * L70-9) : une vraie partie, matérialisée par l'action réelle, sa manche 1
 * programmée et ouverte par les transitions réelles, des sièges tenus par un
 * `player_token`, onglet actif frappé, et la soumission — texte ou clic —
 * envoyée PAR LA ROUTE, comme le client l'enverra :
 * JSON, cookie `player_token`, en-tête `X-Seat-Token`, horloge figée à
 * l'instant de réception.
 *
 * Les films de vivier viennent de {@see PoolFixtures} (fichiers sur le disque
 * `frames` simulé : chaque test appelle `PoolFixtures::fakeFramesDisk()`
 * d'abord) ; la cible d'un test d'appariement se fabrique par
 * {@see self::movie()}, titre imposé, clés projetées par le vrai projecteur.
 * Aucune valeur de jeu en littéral : durées, plafond, grâce et cadence se
 * lisent sur la partie.
 */
final class SubmissionFixtures
{
    /**
     * Une saisie qu'aucun film de ces fixtures ne porte, à distance au-delà de
     * toute tolérance de leurs clés (titres et alias inventés en lorem ipsum).
     */
    public const string WRONG = 'Xqzv Wkjb';

    /**
     * Réglages d'une partie courte : `M` au minimum des bornes, la difficulté
     * voulue, tout le reste au défaut, par le seul constructeur borné.
     */
    public static function settings(InputDifficulty $difficulty = RoomSettingsBounds::DEFAULT_INPUT_DIFFICULTY): RoomSettings
    {
        return RoomSettings::fromInput([
            'roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT,
            'inputDifficulty' => $difficulty->value,
        ]);
    }

    /**
     * Un film de vivier au titre imposé — `title_original` et titre anglais,
     * et titre français si `$frenchTitle` est donné —, sans alias inventé,
     * une variante publiée par niveau nominal du `N` par défaut ; ses clés
     * `answer_key` sont celles du vrai projecteur.
     *
     * Avec ses deux titres, le film a le profil de titre des films de vivier
     * par défaut : ses leurres se tirent au premier rang, sans mode dégradé
     * (spec 70 § 10.3).
     */
    public static function movie(string $title, ?string $frenchTitle = null): Movie
    {
        $levels = FrameLevelCoverage::nominal(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);
        $titles = [Locale::English->value => $title];

        if ($frenchTitle !== null) {
            $titles[Locale::French->value] = $frenchTitle;
        }

        $movie = Movie::factory()
            ->playable(titles: $titles, aliases: [Locale::French->value => []])
            ->state(['title_original' => $title])
            ->has(
                Frame::factory()
                    ->count(count($levels))
                    ->published()
                    ->sequence(...array_map(
                        static fn ($level): array => ['frame_level' => $level],
                        $levels,
                    )),
                'frames',
            )
            ->create();

        MovieFactory::recomputeProjection($movie);

        return $movie->refresh();
    }

    /**
     * Une partie en cours — multijoueur dans un salon, ou solo — dont la
     * manche 1 porte `$target` (un film de vivier sinon). Un siège par jeton,
     * onglet actif frappé, ligne `game_player` gelée ; la manche 1 est
     * programmée, et ouverte à son palier 1 par `OpenTier` sauf `$open =
     * false` : ses lignes `round_player` naissent alors, comme en production.
     *
     * @param  list<PlayerToken>  $tokens
     * @return array{0: Game, 1: Round, 2: list<Player>}
     */
    public static function openedRound(
        array $tokens,
        ?RoomSettings $settings = null,
        ?Movie $target = null,
        bool $solo = false,
        int $reserve = 0,
        bool $open = true,
    ): array {
        $game = EngineFixtures::game($settings ?? self::settings(), solo: $solo);
        $seats = [];

        foreach ($tokens as $token) {
            $seats[] = EngineFixtures::seat($game, [
                'player_token_hash' => $token->hash(),
                'active_seat_token' => (string) Str::ulid(),
            ]);
        }

        $levels = FrameLevelCoverage::nominal($game->frames_per_round);
        $movies = [
            $target ?? PoolFixtures::movie($levels),
            ...PoolFixtures::movies($game->rounds_count - 1 + $reserve, $levels),
        ];

        DB::transaction(static fn () => app(MaterializeDraw::class)->handle($game, EngineFixtures::draw($game, $movies)));

        $round = EngineFixtures::round($game, 1);

        EngineFixtures::schedule($round, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));

        if ($open) {
            EngineFixtures::openTier($round, 1);
        }

        return [$game->refresh(), $round->refresh(), $seats];
    }

    /**
     * La soumission d'un siège par la route, telle que le client l'envoie,
     * reçue à `$at` (l'horloge courante sinon).
     *
     * @return TestResponse<Response>
     */
    public static function submit(
        TestCase $test,
        Player $seat,
        PlayerToken $token,
        string $answer,
        ?CarbonImmutable $at = null,
        int $round = 1,
    ): TestResponse {
        if ($at instanceof CarbonImmutable) {
            Date::setTestNow($at);
        }

        LobbyWrites::actAs($test, $token);

        return $test->postJson(
            route('round.answer.store', $seat),
            ['round' => $round, 'answer' => $answer],
            [EnsureActiveSeat::HEADER => (string) $seat->active_seat_token],
        );
    }

    /**
     * Le clic d'une proposition du QCM par la route, tel que le client
     * l'envoie — la chaîne reçue, jamais un index —, reçu à `$at` (l'horloge
     * courante sinon). Lot L70-9.
     *
     * @return TestResponse<Response>
     */
    public static function click(
        TestCase $test,
        Player $seat,
        PlayerToken $token,
        string $choice,
        ?CarbonImmutable $at = null,
        int $round = 1,
    ): TestResponse {
        if ($at instanceof CarbonImmutable) {
            Date::setTestNow($at);
        }

        LobbyWrites::actAs($test, $token);

        return $test->postJson(
            route('round.choice.store', $seat),
            ['round' => $round, 'choice' => $choice],
            [EnsureActiveSeat::HEADER => (string) $seat->active_seat_token],
        );
    }

    /**
     * Des films de vivier de plus, au profil de titre des films de vivier par
     * défaut (un titre dans chaque locale activée) : de quoi tirer trois
     * leurres au premier rang, sans cas terminal, quel que soit `M`.
     */
    public static function decoyCandidates(): void
    {
        PoolFixtures::movies(DecoyPick::COUNT);
    }

    /**
     * L'ouverture du QCM de la manche à son instant théorique — `T₁` en
     * Facile, `T_N` en Normal — : le rattrapage à cet instant ouvre les
     * paliers échus — la transition d'ouverture compose le QCM (lot L60-11) —,
     * puis la composition, rappelée directement à cet instant : idempotente,
     * elle rend vrai sans rien réécrire, et prouve que le QCM existe. Elle
     * doit composer : jamais le cas terminal. L'horloge reste figée à
     * l'instant rendu.
     */
    public static function openChoices(Round $round): CarbonImmutable
    {
        $game = Game::query()->findOrFail($round->game_id);
        $tierIndex = $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round)
            ?? throw new LogicException('SubmissionFixtures : aucun QCM en Expert.');
        $at = EngineFixtures::opensAt($round, $tierIndex);

        Date::setTestNow($at);
        app(CatchUpGame::class)->handle($game, $at);

        // Comme au premier appel d'un job : liaisons `scoped` oubliées.
        app()->forgetScopedInstances();

        expect(app(ComposeChoiceSets::class)->handle(Round::query()->findOrFail($round->id), $at))->toBeTrue();

        return $at;
    }

    /**
     * La ligne du QCM dans la langue de composition du siège, relue en base.
     */
    public static function choiceSet(Round $round, Player $seat): RoundChoiceSet
    {
        $locale = self::participation($round, $seat)->choices_locale
            ?? throw new LogicException('SubmissionFixtures : siège sans langue de composition du QCM.');

        return RoundChoiceSet::query()->where('round_id', $round->id)->where('locale', $locale->value)->sole();
    }

    /** La bonne proposition du siège : `choice_1` de sa ligne, en clair. */
    public static function correctChoice(Round $round, Player $seat): string
    {
        return self::choiceSet($round, $seat)->choice_1;
    }

    /** Une mauvaise proposition du siège : le premier leurre de sa ligne. */
    public static function wrongChoice(Round $round, Player $seat): string
    {
        return self::choiceSet($round, $seat)->choice_2;
    }

    /**
     * L'écart entre deux soumissions d'un même siège qui respecte la cadence
     * de la partie (`attemptsPerSecond`, 70 § 8) : le limiteur `answer` ne
     * refuse jamais une soumission d'un test qui ne l'éprouve pas.
     */
    public static function cadenceMs(Game $game): int
    {
        return intdiv(1000, $game->settings_snapshot->attemptsPerSecond);
    }

    /**
     * Le corps d'un refus (§ 7.7), identique quelle qu'en soit la cause.
     *
     * @return array{result: string, inputState: string, attemptsLeft: int}
     */
    public static function rejectedBody(int $attemptsLeft, RoundPlayerInputState $inputState = RoundPlayerInputState::Open): array
    {
        return ['result' => 'rejected', 'inputState' => $inputState->value, 'attemptsLeft' => $attemptsLeft];
    }

    /**
     * Le corps d'une clôture (§ 7.7), message résolu dans la langue du jeton.
     *
     * @return array{result: string, inputState: string, message: string}
     */
    public static function closedBody(Locale $locale, RoundPlayerInputState $inputState = RoundPlayerInputState::Open): array
    {
        $message = trans('game.answer.closed', [], $locale->value);

        expect($message)->toBeString()->not->toBe('game.answer.closed');

        return ['result' => 'closed', 'inputState' => $inputState->value, 'message' => (string) $message];
    }

    /** La participation d'un siège à une manche, relue en base. */
    public static function participation(Round $round, Player $seat): RoundPlayer
    {
        return RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $seat->id)->firstOrFail();
    }

    /**
     * La bonne réponse verrouillée d'un siège dans une manche, relue en base
     * (lot L70-6).
     */
    public static function guess(Round $round, Player $seat): Guess
    {
        return Guess::query()->where('round_id', $round->id)->where('player_id', $seat->id)->sole();
    }

    /**
     * Le siège a déjà épuisé `$wrongAttempts` tentatives, comptées comme 70
     * les compte : saisie `open`, rien d'autre.
     */
    public static function spend(Round $round, Player $seat, int $wrongAttempts): void
    {
        RoundPlayer::query()
            ->where('round_id', $round->id)
            ->where('player_id', $seat->id)
            ->update(['wrong_attempts' => $wrongAttempts, 'input_state' => RoundPlayerInputState::Open->value]);
    }

    /**
     * Les requêtes SQL exécutées par `$callback`, dans l'ordre.
     *
     * @return list<QueryExecuted>
     */
    public static function queries(callable $callback): array
    {
        $recording = true;
        $queries = [];

        DB::listen(static function (QueryExecuted $query) use (&$recording, &$queries): void {
            if ($recording) {
                $queries[] = $query;
            }
        });

        try {
            $callback();
        } finally {
            $recording = false;
        }

        return $queries;
    }

    /**
     * Les instructions d'écriture d'un relevé de requêtes (`insert`, `update`,
     * `delete`), par leur SQL.
     *
     * @param  list<QueryExecuted>  $queries
     * @return list<string>
     */
    public static function writes(array $queries): array
    {
        return array_values(array_map(
            static fn (QueryExecuted $query): string => $query->sql,
            array_filter(
                $queries,
                static fn (QueryExecuted $query): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1,
            ),
        ));
    }

    /**
     * Vrai si l'instruction porte sur cette table, quel que soit le
     * guillemet du moteur (SQLite ou MySQL).
     */
    public static function touches(string $sql, string $table): bool
    {
        return preg_match('/(from|into|update|join)\s+[`"]?'.preg_quote($table, '/').'[`"]?(\s|$)/i', $sql) === 1;
    }
}
