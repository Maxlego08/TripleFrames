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

    /**
     * Le film entier a été suspendu depuis la file, par un administrateur
     * (J2, D66 du 07/10, n° 34 ; spec 20 § 11.6). 15 caractères sous
     * `string(20)` : aucune migration.
     */
    case MovieSuspended = 'movie_suspended';

    /** L'image signalée a été suspendue depuis la file, par un administrateur (J2). */
    case FrameSuspended = 'frame_suspended';

    /** L'état de file qui accompagne cette issue. */
    public function status(): ContentReportStatus
    {
        return $this === self::Dismissed ? ContentReportStatus::Dismissed : ContentReportStatus::Resolved;
    }
}
