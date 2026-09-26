<?php

namespace App\Actions\Game;

use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Support\Draw\DrawnRound;
use App\Support\Draw\DrawnTier;
use App\Support\Draw\DrawResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * La matérialisation du tirage — spec 60 § 5.1, contrat C6 O8 et contrat C3
 * § 3 (nom et signature figés, R-15).
 *
 * Appelée par `OpenGame` (50, C6 O8) dans la transaction de lancement, donc au
 * lancement multijoueur comme au démarrage solo (`StartSoloGame` → `OpenGame`,
 * § 16.2) ; `StartSoloGame` ne l'appelle jamais directement, qui
 * matérialiserait le tirage solo une seconde fois. Elle écrit, dans la
 * transaction, et rien d'autre :
 *
 * - **les `K` manches** du {@see DrawResult} (réserve comprise, `K = min(M +
 *   PlatformLimits::drawSubstituteMargin(), W)`) : `game_id`, `room_id =
 *   game.room_id` (NULL en solo), `sequence_index`, `round_number` (NULL pour
 *   la réserve, E10-45), `movie_id`, `status = pending`, `started_at` NULL,
 *   `duration_ms = settings_snapshot->roundDuration() × 1000` ;
 * - **`N` lignes `round_tier` par manche** : `tier_index`, `frame_id`,
 *   `frame_level` (du tirage), `starts_at_offset_ms =
 *   settings_snapshot->tierStartOffsetMs(i)`, `duration_ms =
 *   tierDurations[i−1] × 1000`, `points = tierPoints[i−1]`.
 *
 * Par construction, `SUM(duration_ms) = round.duration_ms`, chaque
 * `duration_ms % 1000 = 0`, et `points` égale le barème figé (10 § 7.4 ;
 * 10 § 14, arbitrage A16) : **aucune colonne de temps ni de points d'un
 * palier n'est plus jamais réécrite**, un rejeu ou une resynchronisation ne
 * recalcule donc jamais un score différent. Toute valeur vient de
 * l'instantané figé de la partie (`game.settings_snapshot`, contrat C0),
 * jamais de la configuration ni d'un littéral (règle 2).
 *
 * **Colonnes de service laissées à leurs écrivains uniques** (C8 § 2,
 * E10-47) : `serve_token`, `served_frame_id` et `substitution_reason` restent
 * NULL jusqu'à la frappe (`MintTierServeToken`), `served_at` jusqu'à
 * l'ouverture (`OpenTier`). Aucun jeton n'est frappé ici : celui du palier 1
 * l'est par `ScheduleRound`, appelé ensuite par `OpenGame` (C6 O9).
 *
 * Deux insertions en masse (manches, puis paliers), une lecture des
 * identifiants : la transaction de lancement tient le verrou du salon, et
 * `K × N` paliers écrits un par un l'allongeraient d'autant. Aucun rejeu de
 * production ne recalcule le tirage : il relit ces lignes (C3 § 4).
 */
final readonly class MaterializeDraw
{
    /**
     * @throws InvalidArgumentException tirage incohérent avec la partie
     *                                  (nombre de manches demandé, paliers).
     * @throws LogicException partie déjà matérialisée, ou instantané de
     *                        réglages incohérent avec les colonnes figées.
     */
    public function handle(Game $game, DrawResult $result): void
    {
        $settings = $game->settings_snapshot;

        self::assertCoherent($game, $settings, $result);

        DB::transaction(static function () use ($game, $settings, $result): void {
            if (Round::query()->where('game_id', $game->id)->exists()) {
                throw new LogicException('MaterializeDraw : le tirage de cette partie est déjà matérialisé.');
            }

            $roundStamp = (new Round)->freshTimestampString();
            $rounds = [];

            foreach ($result->rounds as $drawn) {
                $rounds[] = [
                    'game_id' => $game->id,
                    'room_id' => $game->room_id,
                    'sequence_index' => $drawn->sequenceIndex,
                    'round_number' => $drawn->roundNumber,
                    'movie_id' => $drawn->movieId,
                    'status' => RoundStatus::Pending->value,
                    'duration_ms' => $settings->roundDuration() * 1000,
                    'created_at' => $roundStamp,
                    'updated_at' => $roundStamp,
                ];
            }

            Round::query()->insert($rounds);

            /** @var array<int, int> $roundIds sequence_index → round.id */
            $roundIds = Round::query()
                ->where('game_id', $game->id)
                ->pluck('id', 'sequence_index')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $tierStamp = (new RoundTier)->freshTimestampString();
            $tiers = [];

            foreach ($result->rounds as $drawn) {
                foreach ($drawn->tiers as $tier) {
                    $tiers[] = [
                        'round_id' => $roundIds[$drawn->sequenceIndex],
                        'tier_index' => $tier->tierIndex,
                        'frame_id' => $tier->frameId,
                        'frame_level' => $tier->frameLevel->value,
                        'starts_at_offset_ms' => $settings->tierStartOffsetMs($tier->tierIndex),
                        'duration_ms' => $settings->tierDurations[$tier->tierIndex - 1] * 1000,
                        'points' => $settings->tierPoints[$tier->tierIndex - 1],
                        'created_at' => $tierStamp,
                        'updated_at' => $tierStamp,
                    ];
                }
            }

            RoundTier::query()->insert($tiers);
        });
    }

    /**
     * Le tirage correspond à la partie qu'il matérialise : `M` demandé, `N`
     * paliers par manche indexés `1..N`, positions `1..K` sans trou, et un
     * instantané de réglages qui porte les mêmes `N` et `M` que les colonnes
     * figées. Toutes les gardes passent avant la première écriture.
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private static function assertCoherent(Game $game, RoomSettings $settings, DrawResult $result): void
    {
        if ($settings->framesPerRound !== $game->frames_per_round || $settings->roundsCount !== $game->rounds_count) {
            throw new LogicException('MaterializeDraw : l’instantané de réglages ne porte pas les N et M figés de la partie.');
        }

        if ($result->roundsCount !== $game->rounds_count) {
            throw new InvalidArgumentException(sprintf(
                'MaterializeDraw : tirage de %d manches pour une partie de %d.',
                $result->roundsCount,
                $game->rounds_count,
            ));
        }

        if (count($result->rounds) < $game->rounds_count
            || count($result->rounds) > $game->rounds_count + PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN) {
            throw new InvalidArgumentException(sprintf(
                'MaterializeDraw : %d manches tirées, hors de [M, M + marge maximale].',
                count($result->rounds),
            ));
        }

        foreach ($result->rounds as $position => $drawn) {
            self::assertRound($game, $drawn, $position + 1);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertRound(Game $game, DrawnRound $drawn, int $expectedSequenceIndex): void
    {
        $numbered = $expectedSequenceIndex <= $game->rounds_count;

        if ($drawn->sequenceIndex !== $expectedSequenceIndex
            || $drawn->roundNumber !== ($numbered ? $expectedSequenceIndex : null)) {
            throw new InvalidArgumentException(sprintf(
                'MaterializeDraw : la manche tirée en position %d porte la position %d et le numéro %s (GameDrawer, spec 30 § 6.3).',
                $expectedSequenceIndex,
                $drawn->sequenceIndex,
                $drawn->roundNumber === null ? 'nul' : (string) $drawn->roundNumber,
            ));
        }

        $tierIndexes = array_map(static fn (DrawnTier $tier): int => $tier->tierIndex, $drawn->tiers);

        if ($tierIndexes !== range(1, $game->frames_per_round)) {
            throw new InvalidArgumentException(sprintf(
                'MaterializeDraw : la manche %d ne porte pas exactement les paliers 1..%d.',
                $drawn->sequenceIndex,
                $game->frames_per_round,
            ));
        }
    }
}
