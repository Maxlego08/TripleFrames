<?php

namespace App\Enums;

/**
 * Remède proposé à un vivier bloqué (spec 30 § 4.3, contrat C2).
 *
 * Chaque remède débloque **à lui seul** : appliqué sans rien changer d'autre,
 * il rend un vivier d'au moins `M` œuvres. L'ordre des cas est l'ordre fixe
 * des remèdes d'un `PoolReport`.
 *
 * `disable_no_repeat` est toujours produit à côté d'`open_new_room`, pour que
 * le J2 ne demande aucun changement de calcul ; c'est le présentateur de la
 * spec 50 qui le retire au J1, l'interrupteur vivant dans l'onglet Avancé
 * (D28 du 23/09). Le texte appartient à la spec 50
 * (`room.pool.remedy.<valeur>`) ; aucune colonne n'est castée par cet enum.
 * Miroir client : `resources/js/types/pool.ts`.
 */
enum PoolRemedyKind: string
{
    /** Un nouveau salon a une mémoire vide (§ 3.5, § 4.5). */
    case OpenNewRoom = 'open_new_room';

    /** [J2] Couper la non-répétition, dans l'onglet Avancé. */
    case DisableNoRepeat = 'disable_no_repeat';

    /** Vider la sélection de thèmes : branche sans thème. */
    case ClearThemes = 'clear_themes';

    /** Descendre au `N` jouable le plus proche, porté par `value`. */
    case LowerFramesPerRound = 'lower_frames_per_round';

    /** Réduire `M` au vivier courant, porté par `value`. */
    case ReduceRoundsCount = 'reduce_rounds_count';
}
