<?php

namespace Tests\Support\Answers;

use App\Actions\Game\MaterializeDraw;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Settings\EngineConstants;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Identity\PlayerToken;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\LobbyWrites;
use Tests\TestCase;

/**
 * Fixtures de la soumission texte (spec 70 § 7, lot L70-5) : une vraie partie,
 * matérialisée par l'action réelle, sa manche 1 programmée et ouverte par les
 * transitions réelles, des sièges tenus par un `player_token`, onglet actif
 * frappé, et la soumission envoyée PAR LA ROUTE, comme le client l'enverra :
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
     * Un film de vivier au titre imposé — `title_original` et titre anglais —,
     * sans alias inventé, une variante publiée par niveau nominal du `N` par
     * défaut ; ses clés `answer_key` sont celles du vrai projecteur.
     */
    public static function movie(string $title): Movie
    {
        $levels = FrameLevelCoverage::nominal(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);

        $movie = Movie::factory()
            ->playable(titles: ['en' => $title], aliases: ['fr' => []])
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
