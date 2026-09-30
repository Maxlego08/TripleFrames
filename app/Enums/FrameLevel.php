<?php

namespace App\Enums;

/** Échelle fermée de cryptivité d'une image, 1 très cryptique à 5 évident : cast de `frame.frame_level` et `round_tier.frame_level`. */
enum FrameLevel: int
{
    case Level1 = 1;

    case Level2 = 2;

    case Level3 = 3;

    case Level4 = 4;

    case Level5 = 5;

    /** Bit de ce niveau dans `movie_projection.levels_mask` ; la publication se teste `levels_mask & 21 = 21`. */
    public function bit(): int
    {
        return match ($this) {
            self::Level1 => 1 << 0,
            self::Level2 => 1 << 1,
            self::Level3 => 1 << 2,
            self::Level4 => 1 << 3,
            self::Level5 => 1 << 4,
        };
    }

    /** Colonne de comptage de variantes jouables de ce niveau sur `movie_projection`. */
    public function variantsColumn(): string
    {
        return match ($this) {
            self::Level1 => 'level_1_variants',
            self::Level2 => 'level_2_variants',
            self::Level3 => 'level_3_variants',
            self::Level4 => 'level_4_variants',
            self::Level5 => 'level_5_variants',
        };
    }
}
