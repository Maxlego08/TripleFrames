<?php

namespace App\Support\Game;

use App\Models\Round;
use App\ValueObjects\Scoring\TierWindow;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * L'horloge d'une manche — spec 60 § 2.1, contrat C7 § 2.5 (R-22).
 *
 * **Seule formule de décalage du dépôt, 70 compris.** L'origine unique d'une
 * manche est `round.started_at`, écrite par `ScheduleRound` et immuable dès
 * `T₁` ; tout instant de la manche s'exprime en **millisecondes entières**
 * depuis elle (10 § 1.2). Le calcul part des **microsecondes** des deux
 * instants (`format('Uu')`) et tronque une seule fois, à la fin : un
 * verrouillage et son rejeu ne diffèrent jamais d'un arrondi, là où deux
 * `getTimestampMs()`, chacun arrondi au plus proche, pourraient décaler le
 * résultat d'une milliseconde et faire changer de palier.
 *
 * Les `timestamp(3)` de la base sont la trace de record, jamais l'entrée d'un
 * calcul. L'instant passé ici est celui que le serveur a retenu — l'instant de
 * réception d'une requête ({@see ReceptionInstant::of()}), l'instant d'une
 * transition — et jamais une valeur mesurée ou déclarée par le client
 * (invariant L3).
 *
 * **Aucune grâce ici.** `tier_grace_ms` est un décalage unilatéral appliqué
 * par ses seuls consommateurs (palier retenu et bonus par `ScoreCalculator`,
 * fenêtre d'acceptation par 70, début de révélation par `RevealRound`,
 * § 2.3) ; le palier courant rendu ici est le palier **affiché**, sans grâce.
 */
final class RoundClock
{
    /** Unité du calcul : `format('Uu')` compte en microsecondes. */
    private const int MICROSECONDS_PER_MILLISECOND = 1000;

    /**
     * Décalage de l'instant depuis l'origine de la manche, en millisecondes
     * entières : `intdiv(µs(instant) − µs(started_at), 1000)`, tronqué vers
     * zéro. Négatif avant `T₁`.
     *
     * @throws LogicException Manche sans origine (`started_at` nul) : aucun décalage n'existe.
     */
    public static function offsetMs(Round $round, CarbonImmutable $instant): int
    {
        $startedAt = $round->started_at
            ?? throw new LogicException('RoundClock : une manche sans started_at n\'a pas de décalage.');

        return intdiv(
            (int) $instant->format('Uu') - (int) $startedAt->format('Uu'),
            self::MICROSECONDS_PER_MILLISECOND,
        );
    }

    /**
     * Le rang du palier dont la fenêtre `[starts_at_offset_ms,
     * starts_at_offset_ms + duration_ms)` contient le décalage de l'instant,
     * **sans grâce** — ou `null` si la manche n'a pas démarré (origine nulle,
     * ou instant antérieur à `started_at`, même d'une fraction de
     * milliseconde que la troncature ramènerait à 0) ou si le décalage sort de
     * `[0, D)`, `D` = `round.duration_ms`.
     *
     * La fenêtre est celle de {@see TierWindow::contains()}, seule formule de
     * fenêtre du dépôt : le palier courant ne peut jamais désigner un autre
     * instant que celui du calcul de score.
     */
    public static function currentTierIndex(Round $round, CarbonImmutable $instant): ?int
    {
        $startedAt = $round->started_at;

        if ($startedAt === null || $instant->lessThan($startedAt)) {
            return null;
        }

        $offsetMs = self::offsetMs($round, $instant);

        if ($offsetMs >= $round->duration_ms) {
            return null;
        }

        foreach ($round->tiers as $tier) {
            if (TierWindow::fromRoundTier($tier)->contains($offsetMs)) {
                return $tier->tier_index;
            }
        }

        return null;
    }
}
