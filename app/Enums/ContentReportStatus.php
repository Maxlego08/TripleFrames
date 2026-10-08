<?php

namespace App\Enums;

/** État d'un signalement de contenu dans la file du back-office (D63 du 07/10), filtré et trié à chaque affichage : cast de `content_report.status`. */
enum ContentReportStatus: string
{
    case Open = 'open';

    /** Clos par un geste qui a changé l'état de la cible, ou trouvée déjà hors jeu. */
    case Resolved = 'resolved';

    /** Clos sans suite par un curateur. */
    case Dismissed = 'dismissed';
}
