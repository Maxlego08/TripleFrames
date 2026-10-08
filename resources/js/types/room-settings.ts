/**
 * Réglages de salon côté client (spec 50 § 2.6, contrat C0 § 3.4) : miroir des
 * charges de `App\Support\Room\RoomSettingsPresenter`, de
 * `RoomSettingsBounds::toClient()` et de `PlatformLimits::toArray()`.
 *
 * Types seuls. Livrés avec l'éditeur et le présentateur (lot L50-2), et non
 * avec la page du lobby : le fil temps réel de la spec 60 les importe pour
 * typer `settings.changed` et `room.replayed`.
 *
 * En données seulement : entiers, booléens, codes et clés de thème. Aucune
 * chaîne à afficher — chaque client met les codes en mots dans sa propre
 * langue —, aucun identifiant interne : les thèmes voyagent par `themeKeys`,
 * jamais par `themeIds`. Aucun type n'énumère les valeurs de `N` : les clés de
 * `byFramesPerRound` et de `speedBonusMaxPercent` sont des chaînes d'entier,
 * et les bornes de `N` ne vivent que dans `bounds`.
 */
import type { PoolReport } from '@/types/pool';

/** Difficulté de saisie du salon : cas de `App\Enums\InputDifficulty`. */
export type InputDifficulty = 'easy' | 'normal' | 'expert';

/**
 * Clé de réglage côté client : les seize champs du value object, `themeKeys` à
 * la place de `themeIds`, plus `roundDuration`, clé d'entrée de l'onglet
 * Simple. Clés du rapport de changements et des erreurs de validation.
 */
export type RoomSettingsFieldKey =
    | 'themeKeys'
    | 'roundsCount'
    | 'framesPerRound'
    | 'roundDuration'
    | 'tierDurations'
    | 'tierPoints'
    | 'revealDuration'
    | 'inputDifficulty'
    | 'capacity'
    | 'allowLateJoin'
    | 'speedBonus'
    | 'noRepeatMovies'
    | 'attemptsPerSecond'
    | 'attemptsPerRound'
    | 'maxAnswerLength'
    | 'disconnectGraceSeconds'
    | 'advanced';

/** Les seize champs rendus au client, durées en secondes entières. */
export type RoomSettingsView = {
    /** Clés des thèmes choisis, dans l'ordre de la sélection ; vide = tout le catalogue. */
    themeKeys: string[];
    roundsCount: number;
    framesPerRound: number;
    /** `D` = somme des paliers. */
    tierDurations: number[];
    tierPoints: number[];
    revealDuration: number;
    inputDifficulty: InputDifficulty;
    capacity: number;
    allowLateJoin: boolean;
    speedBonus: boolean;
    noRepeatMovies: boolean;
    attemptsPerSecond: number;
    attemptsPerRound: number;
    maxAnswerLength: number;
    disconnectGraceSeconds: number;
    advanced: boolean;
};

/**
 * Avertissement non bloquant (bornes croisées 4 et 5) ; `waiting_pays` est
 * l'avertissement « attendre paie » de l'onglet Avancé (L50-10, spec 80
 * § 3.4).
 */
export type RoomSettingsWarningCode =
    | 'short_reveal'
    | 'long_round'
    | 'non_decreasing_points'
    | 'waiting_pays'
    | 'all_tiers_zero';

/** Motif d'un changement rapporté à l'auteur d'une écriture de réglages. */
export type RoomSettingsChangeCode =
    | 'defaulted'
    | 'dropped'
    | 'clamped'
    | 'resized'
    | 'pruned'
    | 'coerced'
    | 'equalized'
    | 'reset'
    | 'raised'
    | 'overwritten';

/** Intervalle fermé d'entiers. */
export type Bound = { min: number; max: number };

/** Bornes des réglages pour un `N` donné (`RoomSettingsBounds::toArray(N)`). */
export type RoomSettingsBoundsForN = Record<
    | 'roundsCount'
    | 'framesPerRound'
    | 'roundDuration'
    | 'tierDuration'
    | 'tierPoints'
    | 'revealDuration'
    | 'capacity'
    | 'attemptsPerSecond'
    | 'attemptsPerRound'
    | 'maxAnswerLength'
    | 'disconnectGraceSeconds',
    Bound
>;

/** Prop `bounds` : `RoomSettingsBounds::toClient()`. */
export type RoomSettingsBoundsPayload = {
    /** Bornes par `N`, sous des clés en chaîne d'entier. */
    byFramesPerRound: Record<string, RoomSettingsBoundsForN>;
    derivation: {
        tierPointsUnit: number;
        attemptsPerRoundSecondsPerAttempt: number;
        attemptsPerRoundSoftCap: number;
    };
    warningThresholds: {
        recommendedMinRevealDuration: number;
        longRoundWarningDuration: number;
    };
};

/** Prop `limits` : `PlatformLimits::toArray()`, prop de page et jamais partagée. */
export type PlatformLimitsPayload = {
    savedConfigsPerUser: number;
    roomSeats: number;
    avatarPresets: number;
    historyWindowMonths: number;
    successRateMinRounds: number;
    /** `B_max(N)` en pourcentage entier, sous des clés en chaîne d'entier. */
    speedBonusMaxPercent: Record<string, number>;
};

/**
 * État des réglages d'un salon, diffusé au salon identique pour tous
 * (`settings.changed`, `room.replayed`) et rendu en prop `settings` du lobby.
 */
export type RoomSettingsState = {
    settings: RoomSettingsView;
    warnings: RoomSettingsWarningCode[];
    /**
     * Réglages propres à l'onglet Avancé qui s'écartent de leur défaut dérivé,
     * dans l'ordre des champs (`RoomSettingsEditor::customizedAdvancedFields()`) :
     * le bandeau `room.settings.advanced_active` de l'onglet Simple (L50-10).
     */
    advancedActive: RoomSettingsFieldKey[];
    pool: PoolReport;
};

/** Refus de règle d'un geste de salon : cas de `App\Enums\RoomRefusal`. */
export type RoomRefusalCode =
    | 'not_host'
    | 'room_archived'
    | 'not_in_lobby'
    | 'settings_outdated'
    | 'not_enough_players'
    | 'draining'
    | 'pool_insufficient'
    | 'game_not_ended';
