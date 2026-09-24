<?php

namespace App\Support\Frames;

use App\Settings\PlatformLimits;
use InvalidArgumentException;

/**
 * Géométrie de la frame servable et plancher de recadrage — contrat C9, dont la
 * spec 20 est propriétaire (§ 5.1 et § 5.2 ; D5 et D6 du 23/09).
 *
 * **Constantes de format, jamais surchargeables** : aucune ne vient de la
 * configuration, et changer l'une d'elles impose de recadrer toute la banque.
 * Seules les deux bornes du plancher sont réglables, et elles vivent dans
 * {@see PlatformLimits} (famille « curation », C0, R-07), jamais ici : cette
 * classe ne lit aucune configuration, elle reçoit l'instance.
 *
 * **Espace du master.** Le master est un WebP de largeur EXACTEMENT
 * {@see self::MASTER_WIDTH}, de hauteur {@see self::masterHeightFor()}. Tout
 * rectangle `crop_*` s'exprime dans cet espace : c'est cette largeur fixe qui
 * rend le plancher vérifiable par une requête SQL sans colonne neuve (§ 5.9).
 *
 * **Plancher à double borne (D6 du 23/09).** Un cadre admis vérifie
 * `width × 100 ≤ pct × MASTER_WIDTH`, `height × 100 ≤ pct × masterHeight` et
 * `width ≥ frameCropMinWidthPx`. La borne de largeur seule ne garantissait la
 * surface que sur un master 16:9 ; avec les deux, la surface couverte ne
 * dépasse jamais `pct² ÷ 100` % du master, quel que soit son ratio.
 *
 * **Vérifié trois fois** : navigateur (miroir `resources/js/lib/frame-geometry.ts`,
 * non autoritaire), contrôleur sur la hauteur tirée des métadonnées TMDB, puis
 * job sur le master réel. Le serveur ne fait jamais confiance au miroir.
 */
final class FrameGeometry
{
    /** Ratio de jeu, largeur (D5 du 23/09). C'est aussi le pas d'un cadre, en pixels du master. */
    public const int ASPECT_WIDTH = 16;

    /** Ratio de jeu, hauteur (D5 du 23/09). */
    public const int ASPECT_HEIGHT = 9;

    /** Largeur EXACTE du dérivé servi : dimensions naturelles fixes, aucune empreinte par dimensions. */
    public const int GAME_WIDTH = 1280;

    /** Hauteur EXACTE du dérivé servi. */
    public const int GAME_HEIGHT = 720;

    /** Largeur EXACTE du master, espace de tout rectangle `crop_*`. */
    public const int MASTER_WIDTH = 1920;

    /** Plafond du dérivé servi, padding compris : 150 Ko (spec 10 § 4.1). */
    public const int GAME_MAX_BYTES = 153_600;

    /** Quantum de padding du dérivé servi (spec 10 § 4.1). */
    public const int GAME_PAD_BYTES = 8_192;

    /**
     * Plafond d'ENCODAGE : le plus grand multiple de {@see self::GAME_PAD_BYTES}
     * qui tient sous {@see self::GAME_MAX_BYTES}.
     *
     * Calculé, jamais écrit en littéral (C9 § 5) : un encodage qui passe sous ce
     * plafond reste sous `GAME_MAX_BYTES` une fois paddé.
     */
    public static function gameEncodeCeilingBytes(): int
    {
        return intdiv(self::GAME_MAX_BYTES, self::GAME_PAD_BYTES) * self::GAME_PAD_BYTES;
    }

    /**
     * Longueur paddée : le multiple de {@see self::GAME_PAD_BYTES} supérieur ou
     * égal à la longueur encodée.
     *
     * @throws InvalidArgumentException Une longueur négative.
     */
    public static function paddedLength(int $encodedBytes): int
    {
        if ($encodedBytes < 0) {
            throw new InvalidArgumentException(sprintf(
                'FrameGeometry : longueur encodée négative (%d).',
                $encodedBytes,
            ));
        }

        return intdiv($encodedBytes + self::GAME_PAD_BYTES - 1, self::GAME_PAD_BYTES) * self::GAME_PAD_BYTES;
    }

    /**
     * Hauteur du master d'une source de dimensions données, en entier : la source
     * est ramenée à {@see self::MASTER_WIDTH} de large, hauteur arrondie au plus
     * proche (demi vers le haut), en arithmétique entière pour que le navigateur,
     * le contrôleur et le job trouvent la même valeur.
     *
     * @throws InvalidArgumentException Une dimension nulle ou négative.
     */
    public static function masterHeightFor(int $sourceWidth, int $sourceHeight): int
    {
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            throw new InvalidArgumentException(sprintf(
                'FrameGeometry : dimensions de source non positives (%d × %d).',
                $sourceWidth,
                $sourceHeight,
            ));
        }

        return intdiv($sourceHeight * self::MASTER_WIDTH + intdiv($sourceWidth, 2), $sourceWidth);
    }

    /**
     * Largeur du plus grand cadre admis sur ce master, multiple de
     * {@see self::ASPECT_WIDTH} : le minimum de la borne de largeur
     * (`⌊pct × MASTER_WIDTH ÷ 100 ÷ 16⌋ × 16`) et de la largeur du plus grand
     * 16:9 dont la hauteur tient dans `pct` % de `$masterHeight`.
     *
     * Le cadre tient toujours dans le master : `pct` est borné sous 100 par la
     * garde de {@see PlatformLimits}. Une valeur sous
     * `PlatformLimits::frameCropMinWidthPx()` signifie qu'AUCUN cadre n'est admis
     * sur ce master : la source est refusée (`source_aspect`).
     *
     * @throws InvalidArgumentException Une hauteur de master non positive.
     */
    public static function maxCropWidth(int $masterHeight, PlatformLimits $limits): int
    {
        self::assertMasterHeight($masterHeight);

        $percent = $limits->frameCropMaxWidthPercent;

        $stepsByWidth = intdiv($percent * self::MASTER_WIDTH, PlatformLimits::FULL_PERCENT * self::ASPECT_WIDTH);
        $stepsByHeight = intdiv($percent * $masterHeight, PlatformLimits::FULL_PERCENT * self::ASPECT_HEIGHT);

        return min($stepsByWidth, $stepsByHeight) * self::ASPECT_WIDTH;
    }

    /**
     * Cadre par défaut : le plus grand cadre admis, centré dans le master.
     *
     * @throws InvalidArgumentException Une hauteur de master non positive, ou un
     *                                  master sur lequel aucun cadre n'est admis
     *                                  (`maxCropWidth() < frameCropMinWidthPx`).
     */
    public static function defaultCrop(int $masterHeight, PlatformLimits $limits): CropRect
    {
        $width = self::maxCropWidth($masterHeight, $limits);

        if ($width < $limits->frameCropMinWidthPx) {
            throw new InvalidArgumentException(sprintf(
                'FrameGeometry : aucun cadre admis sur un master de %d × %d (plus grand cadre %d, largeur minimale %d).',
                self::MASTER_WIDTH,
                $masterHeight,
                $width,
                $limits->frameCropMinWidthPx,
            ));
        }

        $height = self::heightFor($width);

        return new CropRect(
            x: intdiv(self::MASTER_WIDTH - $width, 2),
            y: intdiv($masterHeight - $height, 2),
            width: $width,
            height: $height,
        );
    }

    /**
     * Cause du refus d'un cadre sur ce master, ou `null` s'il est admis.
     *
     * Ordre d'évaluation, celui de {@see CropViolation} : ratio, plancher (deux
     * bornes), largeur minimale, débordement.
     *
     * @throws InvalidArgumentException Une hauteur de master non positive.
     */
    public static function violation(CropRect $crop, int $masterHeight, PlatformLimits $limits): ?CropViolation
    {
        self::assertMasterHeight($masterHeight);

        if ($crop->width % self::ASPECT_WIDTH !== 0
            || $crop->height * self::ASPECT_WIDTH !== $crop->width * self::ASPECT_HEIGHT) {
            return CropViolation::Aspect;
        }

        $percent = $limits->frameCropMaxWidthPercent;

        if ($crop->width * PlatformLimits::FULL_PERCENT > $percent * self::MASTER_WIDTH
            || $crop->height * PlatformLimits::FULL_PERCENT > $percent * $masterHeight) {
            return CropViolation::TooWide;
        }

        if ($crop->width < $limits->frameCropMinWidthPx) {
            return CropViolation::TooNarrow;
        }

        if ($crop->x < 0
            || $crop->y < 0
            || $crop->x + $crop->width > self::MASTER_WIDTH
            || $crop->y + $crop->height > $masterHeight) {
            return CropViolation::OutOfBounds;
        }

        return null;
    }

    /**
     * Hauteur 16:9 d'une largeur multiple de {@see self::ASPECT_WIDTH}.
     */
    private static function heightFor(int $width): int
    {
        return intdiv($width * self::ASPECT_HEIGHT, self::ASPECT_WIDTH);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertMasterHeight(int $masterHeight): void
    {
        if ($masterHeight < 1) {
            throw new InvalidArgumentException(sprintf(
                'FrameGeometry : hauteur de master non positive (%d).',
                $masterHeight,
            ));
        }
    }
}
