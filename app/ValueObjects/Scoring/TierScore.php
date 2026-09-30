<?php

namespace App\ValueObjects\Scoring;

use App\Models\Guess;

/**
 * Le palier retenu et les points d'une bonne réponse (spec 80 § 4.1, contrat
 * C13 § 2.2) : les quatre sorties de `ScoreCalculator`, écrites TELLES QUELLES
 * dans `guess.tier_index`, `points_tier`, `points_bonus` et `points_total` par la
 * transaction de verrouillage, et jamais recalculées.
 *
 * **Ciblé, jamais diffusé** avant la révélation : réponse HTTP de la
 * soumission du seul siège (C10) et `SeatInputView.locked` à sa
 * resynchronisation. Aucun identifiant interne.
 *
 * Aucune garde de cohérence (`pointsTotal = pointsTier + pointsBonus`) : le
 * calcul la garantit par construction, et {@see self::fromGuess()} doit pouvoir
 * lire tel quel un journal altéré pour que le rejeu nomme l'écart au lieu de
 * lever.
 */
final readonly class TierScore
{
    public function __construct(
        public int $tierIndex,
        public int $pointsTier,
        public int $pointsBonus,
        public int $pointsTotal,
    ) {}

    /**
     * Ce que le verrouillage a écrit, lu tel quel.
     */
    public static function fromGuess(Guess $guess): self
    {
        return new self(
            tierIndex: $guess->tier_index,
            pointsTier: $guess->points_tier,
            pointsBonus: $guess->points_bonus,
            pointsTotal: $guess->points_total,
        );
    }

    public function equals(self $other): bool
    {
        return $this->tierIndex === $other->tierIndex
            && $this->pointsTier === $other->pointsTier
            && $this->pointsBonus === $other->pointsBonus
            && $this->pointsTotal === $other->pointsTotal;
    }

    /**
     * @return array{tierIndex: int, pointsTier: int, pointsBonus: int, pointsTotal: int}
     */
    public function toArray(): array
    {
        return [
            'tierIndex' => $this->tierIndex,
            'pointsTier' => $this->pointsTier,
            'pointsBonus' => $this->pointsBonus,
            'pointsTotal' => $this->pointsTotal,
        ];
    }
}
