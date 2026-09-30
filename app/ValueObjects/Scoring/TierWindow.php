<?php

namespace App\ValueObjects\Scoring;

use App\Models\RoundTier;

/**
 * La fenêtre d'un palier : rang, instant d'ouverture, durée et valeur (spec 80
 * § 4.1, contrat C13 § 2.2).
 *
 * **Seule formule de fenêtre du dépôt** : `[offset, offset + duration)`,
 * bornes en millisecondes depuis `round.started_at`. `RoundTier::containsOffsetMs()`
 * lui délègue, pour que le palier retenu au verrouillage, au rejeu et à
 * l'affichage ne puisse jamais désigner deux instants différents.
 *
 * Donnée publique : les valeurs de palier sont connues du salon (C13 § 3,
 * inclus par 60 dans `RoundTimeline.tiers`). Elle ne porte ni image, ni niveau,
 * ni identifiant interne.
 *
 * Aucune garde ici : la cohérence d'un calendrier (indices contigus, décalages
 * enchaînés, durées positives, valeurs dans les bornes du barème) est tenue
 * par {@see TierSchedule}, seule entrée du calcul.
 */
final readonly class TierWindow
{
    /**
     * @param  int  $tierIndex  Rang d'affichage `1..N`.
     * @param  int  $startsAtOffsetMs  Ouverture, en millisecondes depuis `round.started_at`.
     * @param  int  $durationMs  Durée `dᵢ`, en millisecondes.
     * @param  int  $points  Valeur du palier, figée au lancement.
     */
    public function __construct(
        public int $tierIndex,
        public int $startsAtOffsetMs,
        public int $durationMs,
        public int $points,
    ) {}

    /**
     * Les quatre colonnes figées d'une ligne `round_tier`, et rien d'autre.
     */
    public static function fromRoundTier(RoundTier $tier): self
    {
        return new self(
            tierIndex: $tier->tier_index,
            startsAtOffsetMs: $tier->starts_at_offset_ms,
            durationMs: $tier->duration_ms,
            points: $tier->points,
        );
    }

    /**
     * Le décalage appartient-il à `[offset, offset + duration)` ?
     *
     * L'appelant du calcul passe la valeur DÉJÀ corrigée de la grâce de
     * frontière ; aucune valeur mesurée ou déclarée par le client n'entre ici
     * (invariant L3).
     */
    public function contains(int $offsetMs): bool
    {
        return $offsetMs >= $this->startsAtOffsetMs
            && $offsetMs < $this->startsAtOffsetMs + $this->durationMs;
    }

    /**
     * @return array{tierIndex: int, startsAtOffsetMs: int, durationMs: int, points: int}
     */
    public function toArray(): array
    {
        return [
            'tierIndex' => $this->tierIndex,
            'startsAtOffsetMs' => $this->startsAtOffsetMs,
            'durationMs' => $this->durationMs,
            'points' => $this->points,
        ];
    }
}
