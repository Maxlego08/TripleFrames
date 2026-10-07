<?php

namespace App\Enums;

/**
 * Issue d'un signalement de contenu clos (D63 du 07/10) : cast de
 * `content_report.resolution`, NULL tant que le signalement est ouvert.
 */
enum ContentReportResolution: string
{
    /** Le film entier a été dépublié depuis la file. */
    case MovieUnpublished = 'movie_unpublished';

    /** L'image signalée a été dépubliée depuis la file. */
    case FrameUnpublished = 'frame_unpublished';

    /** Ignoré : la cible reste en jeu. */
    case Dismissed = 'dismissed';

    /** Ignoré alors que la cible était déjà hors jeu : rien à faire. */
    case AlreadyHandled = 'already_handled';

    /** L'état de file qui accompagne cette issue. */
    public function status(): ContentReportStatus
    {
        return $this === self::Dismissed ? ContentReportStatus::Dismissed : ContentReportStatus::Resolved;
    }
}
