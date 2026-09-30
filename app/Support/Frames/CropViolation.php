<?php

namespace App\Support\Frames;

/**
 * Cause du refus d'un rectangle de recadrage (contrat C9, spec 20 § 5.1 et
 * § 5.2), rendue par {@see FrameGeometry::violation()}.
 *
 * Ce n'est PAS un enum de schéma : aucune colonne ne le porte. Il nomme une
 * erreur de validation, jamais un état persisté ; l'échec du job sur un
 * rectangle devenu invalide est `FrameProcessingFailure::CropInvalid`.
 *
 * L'ordre des cas est l'ordre d'évaluation : un cadre qui cumule deux fautes
 * est nommé par la première.
 *
 * Miroir client : le type `CropViolation` de `resources/js/lib/frame-geometry.ts`,
 * aux mêmes valeurs.
 */
enum CropViolation: string
{
    /** Hors 16:9 exact : largeur non multiple de 16, ou hauteur ≠ largeur × 9 ÷ 16. */
    case Aspect = 'aspect';

    /**
     * Au-delà du plancher de D6 du 23/09 : plus large que la fraction admise de
     * la largeur du master, OU plus haut que la même fraction de sa hauteur.
     */
    case TooWide = 'too_wide';

    /** Plus étroit que `PlatformLimits::frameCropMinWidthPx()`. */
    case TooNarrow = 'too_narrow';

    /** Déborde du master, d'un côté au moins. */
    case OutOfBounds = 'out_of_bounds';

    /**
     * Message traduit du back-office : `admin.validation.crop.<valeur>`.
     */
    public function translationKey(): string
    {
        return 'admin.validation.crop.'.$this->value;
    }
}
