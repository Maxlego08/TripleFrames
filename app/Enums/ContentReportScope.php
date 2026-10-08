<?php

namespace App\Enums;

/**
 * Portée d'un signalement de contenu (D63 du 07/10) : une image précise ou le
 * film entier. Champ du formulaire public, jamais une colonne — la portée se
 * lit dans `content_report.frame_id` (NULL pour le film entier).
 */
enum ContentReportScope: string
{
    case Frame = 'frame';

    case Movie = 'movie';
}
