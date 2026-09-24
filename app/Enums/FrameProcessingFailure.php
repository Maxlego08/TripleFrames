<?php

namespace App\Enums;

/**
 * Cause d'échec du traitement d'une image : cast de `frame.processing_error`
 * (colonne `string(120)` existante, aucune migration ; contrat C9, spec 20
 * § 5.6, E10-10).
 *
 * **La valeur de chaque cas est la clé de traduction complète**, parce que la
 * spec 10 § 4.1 exige que la colonne porte une clé et jamais un message brut :
 * le back-office reste lisible par un non-technicien, et le détail technique
 * part au journal applicatif, jamais à l'écran.
 *
 * Liste fermée de douze cas. Seuls `resource_limit` et `unexpected` sont
 * rejouables : un réessai sur les mêmes octets et le même rectangle rendrait
 * le même verdict pour tous les autres, et l'écran propose alors de
 * re-recadrer ou d'écarter plutôt que « Relancer ».
 */
enum FrameProcessingFailure: string
{
    /** Aucun fichier sous `master_path`, ou pas de `master_path`. */
    case SourceMissing = 'admin.frame.processing_error.source_missing';

    /** Octets annoncés comme une image, mais indécodables. */
    case SourceUnreadable = 'admin.frame.processing_error.source_unreadable';

    /** Format hors de la liste admise (JPEG, PNG, WebP), lu par `finfo`. */
    case SourceFormat = 'admin.frame.processing_error.source_format';

    /** Plus d'une image dans le fichier : une image de jeu est statique. */
    case SourceAnimated = 'admin.frame.processing_error.source_animated';

    /** Moins de `FrameGeometry::GAME_WIDTH` pixels de large. */
    case SourceTooSmall = 'admin.frame.processing_error.source_too_small';

    /** Portrait, ou aucun cadre admis par le plancher sur ce master. */
    case SourceAspect = 'admin.frame.processing_error.source_aspect';

    /** Le rectangle ne tient plus sur le master réel (`FrameGeometry::violation`). */
    case CropInvalid = 'admin.frame.processing_error.crop_invalid';

    /** Même à la qualité minimale, le dérivé dépasse le plafond d'encodage. */
    case TooHeavy = 'admin.frame.processing_error.too_heavy';

    /** Une limite de ressources d'Imagick a été atteinte. */
    case ResourceLimit = 'admin.frame.processing_error.resource_limit';

    /** Frame retirée : aucun fichier n'est écrit (spec 10 § 10). */
    case Withdrawn = 'admin.frame.processing_error.withdrawn';

    /** Frame en jeu : un traitement ne réécrit jamais une frame publiée (n° 16). */
    case Published = 'admin.frame.processing_error.published';

    /** Toute autre panne, après épuisement des réessais de la file. */
    case Unexpected = 'admin.frame.processing_error.unexpected';

    /**
     * Vrai si « Relancer » a un sens : le même traitement, sur les mêmes octets,
     * peut aboutir.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::ResourceLimit, self::Unexpected => true,
            default => false,
        };
    }
}
