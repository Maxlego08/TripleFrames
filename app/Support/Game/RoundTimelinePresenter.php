<?php

namespace App\Support\Game;

use App\Events\Game\RoundScheduled;
use App\Models\Game;
use App\Models\Round;
use App\Models\RoundTier;
use App\Support\Realtime\WireTime;
use App\ValueObjects\Scoring\TierWindow;
use LogicException;

/**
 * `RoundTimeline` — la chronologie publique d'une manche (spec 60 § 11.4 et
 * § 11.5, contrat C7 § 3). Miroir de `RoundTimeline` dans
 * `resources/js/types/game-wire.ts`, clés dans l'ordre du type.
 *
 * | Champ                | Provenance                                                       |
 * |----------------------|------------------------------------------------------------------|
 * | `sequenceIndex`      | `round.sequence_index`                                           |
 * | `roundNumber`        | `round.round_number` (une manche jouée est toujours numérotée)   |
 * | `roundsCount`        | `game.rounds_count`                                              |
 * | `startsAt`           | `round.started_at`, en `IsoMs`                                   |
 * | `durationMs`         | `round.duration_ms`                                              |
 * | `tiers`              | {@see TierWindow::fromRoundTier()} des `N` lignes, par `tier_index` |
 * | `choicesAtTierIndex` | `game.input_difficulty->choicesOpenTierIndex(N)`                 |
 *
 * Seul constructeur serveur de la forme : `round.scheduled`
 * ({@see RoundScheduled}, L60-5) et le paquet de resynchronisation
 * (`RoundState`, L60-12) la prennent ici. `D`, les `dᵢ` et les valeurs de
 * palier sont des réglages publics et transitent (principe 2) ; la durée du
 * FILM, ni aucun niveau, image, identifiant ou titre, jamais (§ 11.7).
 *
 * @phpstan-type TierWindowPayload array{tierIndex: int, startsAtOffsetMs: int, durationMs: int, points: int}
 * @phpstan-type RoundTimelinePayload array{sequenceIndex: int, roundNumber: int, roundsCount: int, startsAt: string, durationMs: int, tiers: list<TierWindowPayload>, choicesAtTierIndex: int|null}
 */
final class RoundTimelinePresenter
{
    /**
     * @return RoundTimelinePayload
     *
     * @throws LogicException Manche d'une autre partie, de réserve (sans numéro)
     *                        ou sans origine de temps.
     */
    public static function timeline(Game $game, Round $round): array
    {
        if ($round->game_id !== $game->id) {
            throw new LogicException('RoundTimelinePresenter : la manche n’appartient pas à la partie.');
        }

        $roundNumber = $round->round_number ?? throw new LogicException(sprintf(
            'RoundTimelinePresenter : la manche %d est une manche de réserve, sans numéro ; elle n’a pas de chronologie publique.',
            $round->sequence_index,
        ));

        $startsAt = $round->started_at ?? throw new LogicException(sprintf(
            'RoundTimelinePresenter : la manche %d n’est pas programmée.',
            $round->sequence_index,
        ));

        $tiers = RoundTier::query()
            ->where('round_id', $round->id)
            ->orderBy('tier_index')
            ->get(['id', 'round_id', 'tier_index', 'starts_at_offset_ms', 'duration_ms', 'points']);

        return [
            'sequenceIndex' => $round->sequence_index,
            'roundNumber' => $roundNumber,
            'roundsCount' => $game->rounds_count,
            'startsAt' => WireTime::iso($startsAt),
            'durationMs' => $round->duration_ms,
            'tiers' => array_values($tiers
                ->map(static fn (RoundTier $tier): array => TierWindow::fromRoundTier($tier)->toArray())
                ->all()),
            'choicesAtTierIndex' => $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round),
        ];
    }
}
