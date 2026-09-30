<?php

namespace App\Enums;

/**
 * Réglage fautif d'un vivier bloqué (spec 30 § 4.2 et § 4.3, contrat C2).
 *
 * Chaque valeur est une **clé postable** de `RoomSettingsEditor`
 * (`SIMPLE_KEYS ∪ ADVANCED_KEYS`) : le code voyage vers le client et y désigne
 * le champ fautif sous son nom client — `themeKeys`, jamais `themeIds` (R-11).
 * `noRepeatMovies` n'est postable que depuis l'onglet Avancé (J2) : au J1, son
 * remède « nouveau salon » reste proposé (D28 du 23/09).
 *
 * L'ordre des cas est l'**ordre fixe** des causes d'un `PoolReport`, que la
 * spec 50 reprend ligne à ligne. Le texte appartient à la spec 50
 * (`room.pool.cause.<valeur>`) ; aucune colonne n'est castée par cet enum.
 * Miroir client : `resources/js/types/pool.ts`.
 */
enum PoolFault: string
{
    case NoRepeatMovies = 'noRepeatMovies';

    case ThemeKeys = 'themeKeys';

    case FramesPerRound = 'framesPerRound';

    case RoundsCount = 'roundsCount';
}
