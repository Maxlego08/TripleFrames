<?php

namespace App\Support\Scoring;

use App\Enums\GameMode;
use App\Enums\ScoreScope;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\ValueObjects\Scoring\PlayerTally;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * Le côté lecture du score (spec 80 § 7, § 9 et § 11, contrat C13) : totaux
 * relus en base, et blocs de DONNÉES que 60 transporte — classement
 * intermédiaire, trouvailles d'une manche, score du siège, podium.
 *
 * **Toute lecture de score passe par ici** (§ 7.4), sous une portée de
 * {@see ScoreScope}, jamais sous `Guess::counted()` seul :
 * - `Own` (manches `running`, `revealing`, `completed`) pour le siège qui a
 *   répondu, et pour lui seul — {@see self::seatScore()}, ciblé ;
 * - `Publishable` (`revealing`, `completed`) pour tout score montré à un AUTRE
 *   siège — {@see self::leaderboard()}, {@see self::roundFinders()} : les
 *   points et le palier d'une manche deviennent publics dès
 *   `round.status = revealing`, jamais avant (E10-52), et le classement d'un
 *   tiers reste figé à la dernière manche révélée pendant une manche en cours ;
 * - `Settled` (`completed`) pour le gel et le podium.
 * Toutes excluent `cancelled` (invariant L1).
 *
 * Chaque lecture emploie UNE sous-requête de manches,
 * `round.game_id = ? AND round.status IN (…)` (index `round_game_status_idx`),
 * dont la liste de statuts ne vit que dans {@see ScoreScope::roundStatuses()}.
 * Les agrégats sont groupés par `player_id` (et `tier_index`), sans colonne non
 * agrégée, donc sûrs sous `ONLY_FULL_GROUP_BY`, puis repliés en PHP en
 * {@see PlayerTally}. **Aucun cache** : une seconde source de vérité pour un
 * gain nul (§ 7.4).
 *
 * **Un seul instantané** : les lectures d'un même appel (`round_player`,
 * `guess`, `game_player`, et les deltas du classement) passent dans UNE
 * transaction, donc sous une seule vue de lecture InnoDB (`REPEATABLE READ`).
 * Sans elle, une resynchronisation concurrente du passage `running` →
 * `revealing` (aucun verrou côté lecture) pourrait compter la manche dans
 * `guess` et pas dans `round_player`, et publier `correctAnswers` supérieur à
 * `roundsPlayed` (invariant de 80 § 10.4). Appelé sous la transaction d'une
 * action (`RevealRound`, `FinalizeGame`), l'appel s'y emboîte.
 *
 * **Ce qui sort d'ici** : des entiers, des booléens, `player.public_id` et les
 * issues de siège, rien d'autre. Aucun `game.id`, `game_player.id`,
 * `player.id`, `round.id`, `guess.id`, `movie_id`, `answer_key_*`,
 * `submitted_normalized`, `match_kind` ni `source` ; aucune chaîne traduite ;
 * les manches par `round_number`. Le seul bloc qui porte des titres est
 * `Podium.recap`, après le gel (L80-5). Pseudo et avatar ne sont pas dans le
 * classement : le client les lit dans les sièges de 60 (identité gelée).
 *
 * Formes des charges : contrat C13 § 3, miroirs client dans
 * `resources/js/types/scoring.ts`.
 *
 * @phpstan-type SeatOutcomePayload 'playing'|'left'|'kicked'
 * @phpstan-type LeaderboardRowPayload array{publicId: string, rank: int|null, rankShared: bool, score: int, correctAnswers: int, roundsPlayed: int, totalAnswerTimeMs: int, roundDelta: int, status: SeatOutcomePayload, firstRoundNumber: int|null}
 * @phpstan-type LeaderboardPayload array{scoreless: bool, roundNumber: int|null, rows: list<LeaderboardRowPayload>}
 * @phpstan-type RoundFinderPayload array{publicId: string, lockRank: int, tierIndex: int, answeredAtMs: int, pointsTier: int, pointsBonus: int, pointsTotal: int}
 * @phpstan-type SeatScorePayload array{ownScore: int}
 * @phpstan-type TitlePacketPayload array{titles: array<string, array{text: string, lang: string}>, originalTitle: string, originalTitleLatin: string|null, originalLanguage: string, year: int|null}
 * @phpstan-type PodiumStandingPayload array{publicId: string, nickname: string|null, masked: bool, avatar: array{kind: string|null, url: string|null, altKey: string, initials: string}, status: SeatOutcomePayload, firstRoundNumber: int|null, rank: int|null, rankShared: bool, finalScore: int, correctAnswers: int, roundsPlayed: int, totalAnswerTimeMs: int}
 * @phpstan-type RecapEntryPayload array{roundNumber: int, outcome: 'completed'|'cancelled', titles: TitlePacketPayload|null, foundCount: int, finders: list<RoundFinderPayload>}
 * @phpstan-type PodiumHighlightsPayload array{bestAnswer: array{publicId: string, roundNumber: int, tierIndex: int, answeredAtMs: int, pointsTotal: int}|null, fastestFind: array{publicId: string, roundNumber: int, answeredAtMs: int}|null, unfoundRoundNumbers: list<int>}
 * @phpstan-type PodiumPayload array{gameStatus: 'completed'|'interrupted', mode: 'multiplayer'|'solo', roundsCompleted: int, roundsCount: int, framesPerRound: int, scoreless: bool, endedAt: string, standings: list<PodiumStandingPayload>, recap: list<RecapEntryPayload>, highlights: PodiumHighlightsPayload}
 */
final class Scoreboard
{
    /** Premier rang de palier : la plus cryptique des images. */
    private const int FIRST_TIER_INDEX = 1;

    /**
     * Les totaux de CHAQUE siège de la partie (toutes les lignes `game_player`,
     * partis, expulsés et retardataires compris, sans plafond), sous une portée.
     *
     * Un siège sans manche dans la portée a des totaux nuls ; ses trouvailles
     * couvrent toujours les paliers `1..N`, à zéro.
     *
     * @return list<PlayerTally> Par `game_player.id` croissant.
     *
     * @throws LogicException Bonne réponse à un palier hors de `1..N` (journal incohérent).
     */
    public static function tallies(Game $game, ScoreScope $scope): array
    {
        return DB::transaction(static fn (): array => self::readTallies($game, $scope));
    }

    /**
     * Le corps de {@see self::tallies()}, hors transaction : appelé sous celle
     * de l'appelant, pour que toutes ses lectures partagent un instantané.
     *
     * @return list<PlayerTally> Par `game_player.id` croissant.
     *
     * @throws LogicException Bonne réponse à un palier hors de `1..N` (journal incohérent).
     */
    private static function readTallies(Game $game, ScoreScope $scope): array
    {
        $framesPerRound = $game->frames_per_round;

        /** @var array<int, int> $roundsPlayed */
        $roundsPlayed = [];

        foreach (RoundPlayer::query()->toBase()
            ->whereIn('round_id', self::roundsInScope($game, $scope))
            ->select('player_id')
            ->selectRaw('count(*) as rounds_played')
            ->groupBy('player_id')
            ->get() as $row) {
            $roundsPlayed[(int) $row->player_id] = (int) $row->rounds_played;
        }

        /** @var array<int, array{correctAnswers: int, score: int, totalAnswerTimeMs: int, findsByTier: array<int, int>}> $totals */
        $totals = [];

        foreach (Guess::query()->toBase()
            ->whereIn('round_id', self::roundsInScope($game, $scope))
            ->select(['player_id', 'tier_index'])
            ->selectRaw('count(*) as finds, sum(points_total) as score, sum(answered_at_ms) as answer_time_ms')
            ->groupBy('player_id', 'tier_index')
            ->get() as $row) {
            $playerId = (int) $row->player_id;
            $tierIndex = (int) $row->tier_index;
            $finds = (int) $row->finds;

            if ($tierIndex < self::FIRST_TIER_INDEX || $tierIndex > $framesPerRound) {
                throw new LogicException(sprintf(
                    'Scoreboard : une bonne réponse porte le palier %d, hors de 1..%d.',
                    $tierIndex,
                    $framesPerRound,
                ));
            }

            $totals[$playerId] ??= [
                'correctAnswers' => 0,
                'score' => 0,
                'totalAnswerTimeMs' => 0,
                'findsByTier' => self::noFinds($framesPerRound),
            ];
            $totals[$playerId]['correctAnswers'] += $finds;
            $totals[$playerId]['score'] += (int) $row->score;
            $totals[$playerId]['totalAnswerTimeMs'] += (int) $row->answer_time_ms;
            $totals[$playerId]['findsByTier'][$tierIndex] += $finds;
        }

        $seats = GamePlayer::query()
            ->where('game_id', $game->id)
            ->with('player:id,public_id')
            ->orderBy('id')
            ->get(['id', 'game_id', 'player_id', 'status', 'first_round_number']);

        $tallies = [];

        foreach ($seats as $seat) {
            $total = $totals[$seat->player_id] ?? null;

            $tallies[] = new PlayerTally(
                gamePlayerId: $seat->id,
                publicId: $seat->player->public_id,
                status: $seat->status,
                firstRoundNumber: $seat->first_round_number,
                roundsPlayed: $roundsPlayed[$seat->player_id] ?? 0,
                correctAnswers: $total['correctAnswers'] ?? 0,
                score: $total['score'] ?? 0,
                totalAnswerTimeMs: $total['totalAnswerTimeMs'] ?? 0,
                findsByTier: $total['findsByTier'] ?? self::noFinds($framesPerRound),
            );
        }

        return $tallies;
    }

    /**
     * Le classement intermédiaire (§ 9), en portée `Publishable` : diffusé par
     * `round.revealed` avec la manche révélée, et présent dans toute
     * resynchronisation — avec la manche en révélation s'il y en a une, sans
     * manche sinon, donc figé à la dernière manche révélée pendant une manche
     * en cours.
     *
     * `roundDelta` est la somme des `points_total` du siège sur la manche
     * révélée, 0 sans manche. En solo, aucun rang (§ 8.2).
     *
     * @param  Round|null  $revealedRound  Manche `revealing` ou `completed` de cette partie, ou `null`.
     * @return LeaderboardPayload
     *
     * @throws LogicException Manche hors de `{revealing, completed}` ou d'une autre partie.
     */
    public static function leaderboard(Game $game, ?Round $revealedRound = null): array
    {
        if ($revealedRound !== null) {
            self::assertPublishable($revealedRound, 'leaderboard');

            if ($revealedRound->game_id !== $game->id) {
                throw new LogicException('Scoreboard::leaderboard() : la manche révélée n’appartient pas à cette partie.');
            }
        }

        return DB::transaction(static fn (): array => self::composeLeaderboard($game, $revealedRound));
    }

    /**
     * Le corps de {@see self::leaderboard()}, gardes passées : deltas et totaux
     * lus sous la même transaction.
     *
     * @return LeaderboardPayload
     */
    private static function composeLeaderboard(Game $game, ?Round $revealedRound): array
    {
        $deltas = [];

        if ($revealedRound !== null) {
            foreach (self::guessesOf($revealedRound)
                ->select(['player.public_id'])
                ->selectRaw('sum(guess.points_total) as round_delta')
                ->groupBy('player.public_id')
                ->get() as $row) {
                $deltas[(string) $row->public_id] = (int) $row->round_delta;
            }
        }

        $standings = Ranking::rank(
            self::readTallies($game, ScoreScope::Publishable),
            $game->frames_per_round,
            $game->scoring_version,
        );

        $solo = $game->mode === GameMode::Solo;
        $rows = [];

        foreach ($standings as $standing) {
            $tally = $standing->tally;

            $rows[] = [
                'publicId' => $tally->publicId,
                'rank' => $solo ? null : $standing->rank,
                'rankShared' => ! $solo && $standing->shared,
                'score' => $tally->score,
                'correctAnswers' => $tally->correctAnswers,
                'roundsPlayed' => $tally->roundsPlayed,
                'totalAnswerTimeMs' => $tally->totalAnswerTimeMs,
                'roundDelta' => $deltas[$tally->publicId] ?? 0,
                'status' => $tally->status->value,
                'firstRoundNumber' => $tally->firstRoundNumber,
            ];
        }

        return [
            'scoreless' => ScoringRules::isScoreless($game->settings_snapshot),
            'roundNumber' => $revealedRound?->round_number,
            'rows' => $rows,
        ];
    }

    /**
     * Les bonnes réponses d'une manche révélée (§ 9.3), par `lockRank`
     * croissant : diffusées par `round.revealed`, reprises au récapitulatif.
     *
     * @return list<RoundFinderPayload>
     *
     * @throws LogicException Manche hors de `{revealing, completed}` — en cours,
     *                        en attente ou annulée.
     */
    public static function roundFinders(Round $round): array
    {
        self::assertPublishable($round, 'roundFinders');

        $rows = self::guessesOf($round)
            ->orderBy('guess.lock_rank')
            ->get([
                'player.public_id',
                'guess.lock_rank',
                'guess.tier_index',
                'guess.answered_at_ms',
                'guess.points_tier',
                'guess.points_bonus',
                'guess.points_total',
            ])
            ->all();

        return array_values(array_map(
            static fn (stdClass $row): array => [
                'publicId' => (string) $row->public_id,
                'lockRank' => (int) $row->lock_rank,
                'tierIndex' => (int) $row->tier_index,
                'answeredAtMs' => (int) $row->answered_at_ms,
                'pointsTier' => (int) $row->points_tier,
                'pointsBonus' => (int) $row->points_bonus,
                'pointsTotal' => (int) $row->points_total,
            ],
            $rows,
        ));
    }

    /**
     * Le score du seul siège demandeur (§ 9.4), en portée `Own`, manche en
     * cours comprise : ciblé, jamais diffusé (`SelfState.ownScore` de 60). Le
     * détail de la manche en cours est porté par la vue de saisie de 70.
     *
     * @return SeatScorePayload
     */
    public static function seatScore(Game $game, Player $player): array
    {
        $ownScore = Guess::query()->toBase()
            ->where('player_id', $player->id)
            ->whereIn('round_id', self::roundsInScope($game, ScoreScope::Own))
            ->sum('points_total');

        return ['ownScore' => (int) $ownScore];
    }

    /**
     * La sous-requête des manches de la partie dans une portée — la seule, pour
     * toutes les lectures.
     *
     * @return Builder<Round>
     */
    private static function roundsInScope(Game $game, ScoreScope $scope): Builder
    {
        return Round::query()
            ->where('game_id', $game->id)
            ->whereIn('status', $scope->roundStatuses())
            ->select('id');
    }

    /**
     * Les bonnes réponses d'une manche, jointes au siège pour son `public_id`.
     */
    private static function guessesOf(Round $round): QueryBuilder
    {
        return Guess::query()->toBase()
            ->join('player', 'player.id', '=', 'guess.player_id')
            ->where('guess.round_id', $round->id);
    }

    /**
     * Les points d'une manche ne sont publics qu'à partir de `revealing`
     * (portée `Publishable`) : jamais pendant qu'elle court, jamais pour une
     * manche annulée.
     *
     * @throws LogicException
     */
    private static function assertPublishable(Round $round, string $reader): void
    {
        if (! in_array($round->status, ScoreScope::Publishable->roundStatuses(), true)) {
            throw new LogicException(sprintf(
                'Scoreboard::%s() : la manche est %s ; ses points ne sont publics qu’en revealing ou completed.',
                $reader,
                $round->status->value,
            ));
        }
    }

    /**
     * Aucune trouvaille, paliers `1..N`.
     *
     * @return array<int, int>
     */
    private static function noFinds(int $framesPerRound): array
    {
        return array_fill(self::FIRST_TIER_INDEX, $framesPerRound, 0);
    }
}
