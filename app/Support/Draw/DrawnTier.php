<?php

namespace App\Support\Draw;

use App\Enums\FrameLevel;

/**
 * Un palier tiré (spec 30 § 6.3, contrat C3) : la variante que la manche
 * montrera au palier `tierIndex`.
 *
 * `tierIndex = i` ⟺ `frameLevel = FrameLevelCoverage::select(N, masque)[i − 1]` :
 * le niveau le plus cryptique disponible est toujours au palier 1 (§ 2.2).
 * Matérialisé en `round_tier` (`tier_index`, `frame_id`, `frame_level`) par la
 * spec 60 ; les instants et les points n'y sont pas, 60 et 80 les dérivent de
 * `settings_snapshot`.
 *
 * Jamais sérialisé : `frameId` et `frameLevel` ne quittent jamais le serveur
 * (§ 6.5, règle 3).
 */
final readonly class DrawnTier
{
    /**
     * @param  int  $tierIndex  `i`, de 1 à `N`.
     * @param  int  $frameId  Variante tirée, `frame.id`.
     * @param  FrameLevel  $frameLevel  Niveau de la variante, celui du palier.
     */
    public function __construct(
        public int $tierIndex,
        public int $frameId,
        public FrameLevel $frameLevel,
    ) {}
}
