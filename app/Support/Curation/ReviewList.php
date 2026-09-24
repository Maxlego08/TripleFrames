<?php

namespace App\Support\Curation;

/**
 * Les trois listes de la file de revue (spec 20 § 7.3) — et les trois onglets
 * de l'écran `admin/review/index`, dans cet ordre.
 *
 * Aucune colonne ne les porte : l'appartenance d'une image à une liste se
 * DÉRIVE de sa disponibilité, de son traitement, de ses octets et de ses
 * revues, par les prédicats de {@see ReviewQueue}, seul porteur de cette
 * règle. Les valeurs sont celles des props de l'écran.
 */
enum ReviewList: string
{
    /** Prêtes, hors du jeu, sans revue qui les juge depuis leur dernier changement d'état. */
    case ToReview = 'to_review';

    /** En jeu, revues sous une version antérieure de la grille. */
    case ToReReview = 'to_rereview';

    /** Dont la revue qui les juge encore est rejetée. */
    case Rejected = 'rejected';
}
