<?php

namespace App\Settings;

/**
 * Éditeur des réglages de salon, par onglet (contrat C0, spec 50 § 3.1).
 *
 * Fonction pure : il transforme une charge postée par l'onglet Simple ou Avancé en
 * entrée complète de {@see RoomSettings::fromInput()}. Les deux onglets sont deux
 * vues d'un SEUL objet de réglages.
 *
 * Ce fichier ne porte encore que les listes de clés postables et le verrou de
 * l'onglet Avancé : le rapport de vivier de la spec 30 (L30-3) les lit pour
 * prouver que chaque cause de blocage désigne une clé que l'hôte peut réellement
 * poster. `simple()` et `advanced()` arrivent avec l'écriture des réglages (L50-2).
 *
 * Toute clé hors de la liste de l'onglet, et `advanced: true` tant que
 * {@see self::ADVANCED_TAB_AVAILABLE} est faux, est refusée : sans ce refus, un
 * client scripté poserait au J1 un barème que l'interface ne montre pas.
 */
final readonly class RoomSettingsEditor
{
    /** Clés postées par l'onglet Simple, seul onglet au J1. `themeKeys` devient `themeIds`. */
    public const array SIMPLE_KEYS = [
        'themeKeys',
        'roundsCount',
        'framesPerRound',
        'roundDuration',
        'revealDuration',
        'inputDifficulty',
        'capacity',
        'allowLateJoin',
        'advanced',
    ];

    /** [J2] Clés postées par l'onglet Avancé : `D = Σ tierDurations`, donc pas de `roundDuration`. */
    public const array ADVANCED_KEYS = [
        'themeKeys',
        'roundsCount',
        'framesPerRound',
        'tierDurations',
        'tierPoints',
        'revealDuration',
        'inputDifficulty',
        'capacity',
        'allowLateJoin',
        'speedBonus',
        'noRepeatMovies',
        'attemptsPerSecond',
        'attemptsPerRound',
        'maxAnswerLength',
        'disconnectGraceSeconds',
        'advanced',
    ];

    /**
     * Onglet Avancé livré ou non — constante de CODE, jamais un drapeau de
     * configuration : elle passe à `true` par un commit du lot L50-10 [J2], jamais
     * à l'exécution. Ce n'est donc pas un « feature flag ».
     */
    public const bool ADVANCED_TAB_AVAILABLE = false;
}
