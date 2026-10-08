<?php

namespace App\Support\Account;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Game\RevealMovieBuilder;
use App\Support\Realtime\WireTime;
use App\Support\Scoring\ScoringRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * « Mes parties » et ses quatre compteurs — spec 40 § 13.4 (L40-13, D66 du
 * 07/10, n° 29 à 31) ; matière première : les agrégats figés au podium de
 * `80` § 10.4 et § 13.
 *
 * **Seul lecteur** de l'historique d'un compte. Trois règles le tiennent :
 *
 * - **Aucun agrégat stocké** (décision 15) : les compteurs sont une requête
 *   sur `game_player`, dans la fenêtre glissante
 *   `game.ended_at ≥ now − PlatformLimits::historyWindowMonths()` ; une
 *   partie qui sort de la fenêtre sort des compteurs, et le meilleur score
 *   peut baisser.
 * - **Les compteurs ne lisent jamais `guess`** (`80` § 13) : seul
 *   {@see self::detail()} lit les `guess` du siège, sur les manches
 *   `completed` (invariant L1 de `10` § 1.8). Aucune requête dans un JSON.
 * - **Périmètre** : sièges `player.user_id = compte`, parties gelées
 *   (`ended_at` non nul) à `rounds_played ≥ 1` — une partie à zéro manche
 *   jouée n'est ni listée ni comptée (n° 31). Chemin d'index
 *   `player_user_idx` → `game_player_player_game_uq` → `game_mode_ended_idx`.
 *
 * Les compteurs et la date de la plus ancienne partie sont tenus en **cache
 * court** sous {@see PlayHistoryCache::key()}, oublié à la fin d'une partie
 * (`ForgetPlayHistoryCache`) et au rattachement d'un siège invité
 * (`ClaimSeatForAccount`). La liste paginée, elle, n'est pas mise en cache :
 * une page se lit par l'index, et une clé par page échapperait à l'oubli.
 *
 * **Données, jamais phrases** : entiers, instants ISO et paquets de titres ;
 * le client met en forme (`lib/account/play-history.ts`).
 *
 * @phpstan-import-type RevealMoviePayload from RevealMovieBuilder
 *
 * @phpstan-type HistoryRowPayload array{publicId: string, endedAt: string, mode: string, roomCode: string|null, status: string, roundsCompleted: int, roundsCount: int, framesPerRound: int, roundsPlayed: int, correctAnswers: int, finalScore: int, rank: int|null}
 * @phpstan-type BestScorePayload array{score: int, endedAt: string, roundsCount: int, framesPerRound: int, publicId: string}
 * @phpstan-type CountersPayload array{gamesPlayed: int, correctAnswers: int, roundsPlayed: int, successRate: float|null, bestScore: BestScorePayload|null}
 * @phpstan-type SummaryPayload array{counters: CountersPayload, oldestKeptAt: string|null, windowMonths: int, successRateMinRounds: int}
 * @phpstan-type DetailRoundPayload array{number: int, movie: RevealMoviePayload, outcome: 'found'|'missed'|'absent', points: int|null}
 * @phpstan-type DetailPayload array{game: HistoryRowPayload, scoreless: bool, rounds: list<DetailRoundPayload>}
 */
final class PlayHistory
{
    /** Parties par page de la liste « Mes parties ». */
    public const int PER_PAGE = 20;

    /**
     * Durée du cache court des compteurs, en secondes : il est oublié à
     * chaque fin de partie et à chaque rattachement, la durée ne borne que
     * le glissement de la fenêtre.
     */
    public const int CACHE_TTL_SECONDS = 600;

    /** Début de la fenêtre glissante, à l'instant présent. */
    public static function windowStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMonths(PlatformLimits::historyWindowMonths());
    }

    /**
     * Les quatre compteurs, la date de la plus ancienne partie conservée et
     * les deux bornes d'affichage — en cache court par compte.
     *
     * @return SummaryPayload
     */
    public function summary(User $user): array
    {
        /** @var SummaryPayload */
        return Cache::remember(
            PlayHistoryCache::key($user->id),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->computeSummary($user),
        );
    }

    /**
     * Une page de « Mes parties », de la plus récente à la plus ancienne.
     *
     * @return LengthAwarePaginator<int, HistoryRowPayload>
     */
    public function page(User $user, int $page): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, stdClass> $paginator */
        $paginator = $this->listed($user)
            ->leftJoin('room', 'room.id', '=', 'game.room_id')
            ->select([
                'game.public_id',
                'game.ended_at',
                'game.mode',
                'game.status',
                'game.rounds_completed',
                'game.rounds_count',
                'game.frames_per_round',
                'game_player.rounds_played',
                'game_player.correct_answers',
                'game_player.final_score',
                'game_player.final_rank',
                'room.room_code',
            ])
            ->orderByDesc('game.ended_at')
            ->orderByDesc('game.id')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));

        /** @var LengthAwarePaginator<int, HistoryRowPayload> */
        return $paginator->through(static fn (stdClass $row): array => self::fromQueryRow($row));
    }

    /**
     * Le siège du compte dans cette partie, s'il entre dans l'historique :
     * partie gelée dans la fenêtre, au moins une manche jouée. `null` sinon —
     * l'appelant répond alors 404, jamais 403 (`GamePolicy::viewHistory`).
     */
    public function seatFor(User $user, Game $game): ?GamePlayer
    {
        if ($game->ended_at === null || $game->ended_at->lessThan(self::windowStart())) {
            return null;
        }

        return GamePlayer::query()
            ->where('game_id', $game->id)
            ->whereIn('player_id', Player::query()->select('id')->where('user_id', $user->id))
            ->where('rounds_played', '>=', 1)
            ->first();
    }

    /**
     * Le détail d'une partie (n° 30) : ses manches `completed` (L1), par
     * film le paquet de titres de {@see RevealMovieBuilder} (lien Letterboxd
     * compris, D58), trouvé ou non par CE siège et les points de la manche.
     * Aucune image, aucun pseudo d'un autre joueur (décision 19).
     *
     * `absent` : le siège n'a pas joué cette manche (retardataire, départ),
     * faute de ligne `round_player` — la même définition que `rounds_played`.
     *
     * @return DetailPayload
     */
    public function detail(Game $game, GamePlayer $seat): array
    {
        $rounds = Round::query()
            ->where('game_id', $game->id)
            ->where('status', RoundStatus::Completed)
            ->orderBy('round_number')
            ->orderBy('sequence_index')
            ->with('movie.titles')
            ->get();

        $roundIds = $rounds->modelKeys();

        /** @var array<int, true> $played */
        $played = RoundPlayer::query()
            ->where('player_id', $seat->player_id)
            ->whereIn('round_id', $roundIds)
            ->pluck('round_id')
            ->mapWithKeys(static fn (mixed $roundId): array => [(int) $roundId => true])
            ->all();

        /** @var array<int, int> $points Manche → points du siège. */
        $points = Guess::query()
            ->where('player_id', $seat->player_id)
            ->whereIn('round_id', $roundIds)
            ->pluck('points_total', 'round_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $entries = [];

        foreach ($rounds as $round) {
            $found = array_key_exists($round->id, $points);

            $entries[] = [
                'number' => $round->round_number ?? $round->sequence_index,
                'movie' => RevealMovieBuilder::build($round->movie),
                'outcome' => $found ? 'found' : (isset($played[$round->id]) ? 'missed' : 'absent'),
                'points' => $found ? $points[$round->id] : null,
            ];
        }

        return [
            'game' => self::row(
                publicId: $game->public_id,
                endedAt: $game->ended_at,
                mode: $game->mode->value,
                status: $game->status->value,
                roundsCompleted: $game->rounds_completed,
                roundsCount: $game->rounds_count,
                framesPerRound: $game->frames_per_round,
                roundsPlayed: $seat->rounds_played,
                correctAnswers: $seat->correct_answers,
                finalScore: $seat->final_score,
                finalRank: $seat->final_rank,
                roomCode: $game->room?->room_code,
            ),
            'scoreless' => ScoringRules::isScoreless($game->settings_snapshot),
            'rounds' => $entries,
        ];
    }

    /**
     * @return SummaryPayload
     */
    private function computeSummary(User $user): array
    {
        $totals = $this->counted($user)
            ->selectRaw('COUNT(*) AS games_played')
            ->selectRaw('COALESCE(SUM(game_player.correct_answers), 0) AS correct_answers')
            ->selectRaw('COALESCE(SUM(game_player.rounds_played), 0) AS rounds_played')
            ->first();

        $gamesPlayed = (int) ($totals->games_played ?? 0);
        $correctAnswers = (int) ($totals->correct_answers ?? 0);
        $roundsPlayed = (int) ($totals->rounds_played ?? 0);

        // n° 29 : une partie sans score (tous les paliers à 0) a
        // `final_score = 0` et n'entre jamais dans le meilleur score — sans
        // aucune lecture du JSON des réglages.
        $best = $this->counted($user)
            ->where('game_player.final_score', '>', 0)
            ->select(['game_player.final_score', 'game.ended_at', 'game.rounds_count', 'game.frames_per_round', 'game.public_id'])
            ->orderByDesc('game_player.final_score')
            ->orderByDesc('game.ended_at')
            ->orderByDesc('game.id')
            ->first();

        $oldest = $this->listed($user)->min('game.ended_at');

        $minRounds = PlatformLimits::successRateMinRounds();

        return [
            'counters' => [
                'gamesPlayed' => $gamesPlayed,
                'correctAnswers' => $correctAnswers,
                'roundsPlayed' => $roundsPlayed,
                'successRate' => $roundsPlayed >= $minRounds && $roundsPlayed > 0
                    ? min(1.0, (float) $correctAnswers / $roundsPlayed)
                    : null,
                'bestScore' => $best === null ? null : [
                    'score' => (int) $best->final_score,
                    'endedAt' => self::iso($best->ended_at),
                    'roundsCount' => (int) $best->rounds_count,
                    'framesPerRound' => (int) $best->frames_per_round,
                    'publicId' => (string) $best->public_id,
                ],
            ],
            'oldestKeptAt' => $oldest === null ? null : self::iso($oldest),
            'windowMonths' => PlatformLimits::historyWindowMonths(),
            'successRateMinRounds' => $minRounds,
        ];
    }

    /**
     * Le périmètre listé : sièges du compte, parties gelées dans la fenêtre,
     * au moins une manche jouée. Solo compris.
     */
    private function listed(User $user): Builder
    {
        return DB::table('player')
            ->join('game_player', 'game_player.player_id', '=', 'player.id')
            ->join('game', 'game.id', '=', 'game_player.game_id')
            ->where('player.user_id', $user->id)
            ->whereNotNull('game.ended_at')
            ->where('game.ended_at', '>=', self::windowStart())
            ->where('game_player.rounds_played', '>=', 1);
    }

    /** Le périmètre des compteurs : le périmètre listé, multijoueur seul (`80` § 12). */
    private function counted(User $user): Builder
    {
        return $this->listed($user)->where('game.mode', GameMode::Multiplayer->value);
    }

    /**
     * Une ligne de la liste, depuis une ligne de la requête paginée.
     *
     * @return HistoryRowPayload
     */
    private static function fromQueryRow(stdClass $row): array
    {
        return self::row(
            publicId: (string) $row->public_id,
            endedAt: $row->ended_at,
            mode: (string) $row->mode,
            status: (string) $row->status,
            roundsCompleted: (int) $row->rounds_completed,
            roundsCount: (int) $row->rounds_count,
            framesPerRound: (int) $row->frames_per_round,
            roundsPlayed: (int) $row->rounds_played,
            correctAnswers: (int) $row->correct_answers,
            finalScore: (int) $row->final_score,
            finalRank: $row->final_rank === null ? null : (int) $row->final_rank,
            roomCode: is_string($row->room_code) ? $row->room_code : null,
        );
    }

    /**
     * Une ligne de la liste, depuis une ligne de requête ou une partie relue.
     * Arguments nommés plutôt qu'un objet rempli champ à champ : une
     * affectation `->final_score = …` hors de `FinalizeGame` serait lue comme
     * une écriture des agrégats gelés (`ScoringWritersTest`).
     *
     * @return HistoryRowPayload
     */
    private static function row(
        string $publicId,
        mixed $endedAt,
        string $mode,
        string $status,
        int $roundsCompleted,
        int $roundsCount,
        int $framesPerRound,
        int $roundsPlayed,
        int $correctAnswers,
        int $finalScore,
        ?int $finalRank,
        ?string $roomCode,
    ): array {
        return [
            'publicId' => $publicId,
            'endedAt' => self::iso($endedAt),
            'mode' => $mode,
            'roomCode' => $mode === GameMode::Multiplayer->value ? $roomCode : null,
            'status' => self::endedStatus($status),
            'roundsCompleted' => $roundsCompleted,
            'roundsCount' => $roundsCount,
            'framesPerRound' => $framesPerRound,
            'roundsPlayed' => $roundsPlayed,
            'correctAnswers' => $correctAnswers,
            'finalScore' => $finalScore,
            'rank' => $mode === GameMode::Solo->value ? null : $finalRank,
        ];
    }

    /** Une partie gelée est interrompue ou, à défaut, terminée. */
    private static function endedStatus(string $status): string
    {
        return GameStatus::tryFrom($status) === GameStatus::Interrupted
            ? GameStatus::Interrupted->value
            : GameStatus::Completed->value;
    }

    /** Un instant de base (chaîne SQL ou date) en ISO 8601 UTC du fil. */
    private static function iso(mixed $value): string
    {
        $instant = $value instanceof CarbonImmutable
            ? $value
            : CarbonImmutable::parse(is_string($value) ? $value : '');

        return WireTime::iso($instant);
    }
}
