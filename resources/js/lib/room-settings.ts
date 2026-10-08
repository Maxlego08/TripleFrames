/**
 * Dérivations des réglages de salon côté client, pour le retour immédiat des
 * onglets Simple et Avancé (spec 50 § 3.3 et § 4.3, contrat C0).
 *
 * Tout est calculé **uniquement** depuis `RoomSettingsBoundsPayload`
 * (`RoomSettingsBounds::toClient()`, prop `bounds` du lobby) et, pour le seul
 * avertissement `waiting_pays`, depuis `B_max(N)` de la prop `limits`
 * (`PlatformLimits::toArray().speedBonusMaxPercent`), sans aucun littéral de
 * jeu : aucune durée, aucun nombre d'images, aucun barème ni aucun seuil
 * n'est écrit ici. Le serveur reste **seul juge** : il redérive,
 * revalide et refuse sous le verrou du salon ; ces fonctions ne font que
 * montrer à l'hôte, avant l'envoi, ce que le serveur rendra.
 *
 * La parité est prouvée par le jeu partagé `tests/Fixtures/room/derivations.json`
 * (`php artisan room:derivations-fixture`) : `RoomSettingsDerivationParityTest`
 * l'exige du serveur, `tests/Frontend/room/room-settings.test.ts` de ce module.
 *
 * Module pur : ni DOM, ni horloge, ni Echo.
 */
import type {
    Bound,
    PlatformLimitsPayload,
    RoomSettingsBoundsForN,
    RoomSettingsBoundsPayload,
    RoomSettingsChangeCode,
    RoomSettingsFieldKey,
    RoomSettingsView,
    RoomSettingsWarningCode,
} from '@/types/room-settings';
import type { TranslationKey } from '@/types/translations';

/** Borne de `N`, identique pour chaque entrée de `byFramesPerRound`. */
export function framesPerRoundBound(bounds: RoomSettingsBoundsPayload): Bound {
    const first = Object.values(bounds.byFramesPerRound)[0];

    if (first === undefined) {
        throw new Error(
            'RoomSettingsBoundsPayload: byFramesPerRound est vide.',
        );
    }

    return first.framesPerRound;
}

/**
 * `N` ramené dans ses bornes, comme `RoomSettingsBounds::clampFramesPerRound()`
 * pour les dérivations de défaut et de bornes.
 */
export function clampFramesPerRound(
    bounds: RoomSettingsBoundsPayload,
    framesPerRound: number,
): number {
    const { min, max } = framesPerRoundBound(bounds);

    return Math.max(min, Math.min(max, framesPerRound));
}

/** Les bornes d'un `N` donné, `N` ramené dans ses bornes. */
export function boundsFor(
    bounds: RoomSettingsBoundsPayload,
    framesPerRound: number,
): RoomSettingsBoundsForN {
    const entry =
        bounds.byFramesPerRound[
            String(clampFramesPerRound(bounds, framesPerRound))
        ];

    if (entry === undefined) {
        throw new Error(
            `RoomSettingsBoundsPayload: aucune borne pour N = ${framesPerRound}.`,
        );
    }

    return entry;
}

/** Valeurs de `N` proposées, de la borne basse à la borne haute. */
export function framesPerRoundOptions(
    bounds: RoomSettingsBoundsPayload,
): number[] {
    const { min, max } = framesPerRoundBound(bounds);
    const options: number[] = [];

    for (let frames = min; frames <= max; frames++) {
        options.push(frames);
    }

    return options;
}

/**
 * `D` minimal pour `N` — borne croisée 1, `D ≥ 5 s × N` : le minimum
 * **effectif** que le curseur de `D` affiche (25 s à N = 5 aux bornes par
 * défaut).
 */
export function minRoundDuration(
    bounds: RoomSettingsBoundsPayload,
    framesPerRound: number,
): number {
    return boundsFor(bounds, framesPerRound).roundDuration.min;
}

/** `D` = somme des paliers, et rien d'autre. */
export function roundDuration(
    view: Pick<RoomSettingsView, 'tierDurations'>,
): number {
    return view.tierDurations.reduce((sum, duration) => sum + duration, 0);
}

/**
 * Paliers égaux en secondes entières, le **dernier** absorbant le reste
 * (`RoomSettingsBounds::defaultTierDurations()`) : 40 s / 3 → 13 / 13 / 14.
 */
export function defaultTierDurations(
    bounds: RoomSettingsBoundsPayload,
    framesPerRound: number,
    duration: number,
): number[] {
    const count = clampFramesPerRound(bounds, framesPerRound);
    const { min, max } = boundsFor(bounds, count).roundDuration;
    const clamped = Math.max(min, Math.min(max, duration));
    const base = Math.floor(clamped / count);
    const durations = Array.from({ length: count - 1 }, () => base);

    durations.push(clamped - base * (count - 1));

    return durations;
}

/**
 * Barème par défaut : valeur du palier `i` = `(N − i + 1) × unité`
 * (`RoomSettingsBounds::defaultTierPoints()`).
 */
export function defaultTierPoints(
    bounds: RoomSettingsBoundsPayload,
    framesPerRound: number,
): number[] {
    const count = clampFramesPerRound(bounds, framesPerRound);
    const unit = bounds.derivation.tierPointsUnit;

    return Array.from({ length: count }, (_, index) => (count - index) * unit);
}

/**
 * `attemptsPerRound` par défaut : `ceil(D / secondes par tentative)`,
 * plafonné, puis borné (`RoomSettingsBounds::defaultAttemptsPerRound()`).
 */
export function defaultAttemptsPerRound(
    bounds: RoomSettingsBoundsPayload,
    duration: number,
): number {
    const { attemptsPerRoundSecondsPerAttempt, attemptsPerRoundSoftCap } =
        bounds.derivation;
    const { min, max } = boundsFor(
        bounds,
        framesPerRoundBound(bounds).min,
    ).attemptsPerRound;
    const attempts = Math.min(
        Math.ceil(duration / attemptsPerRoundSecondsPerAttempt),
        attemptsPerRoundSoftCap,
    );

    return Math.max(min, Math.min(max, attempts));
}

/** Une violation des bornes croisées 1 et 2, en données (§ 4.1). */
export type CrossBoundError =
    | {
          field: 'roundDuration';
          code: 'round_duration';
          min: number;
          max: number;
          frames: number;
      }
    | { field: 'tierDurations'; code: 'list_size'; size: number }
    | {
          field: 'tierDurations';
          code: 'tier_duration';
          tier: number;
          min: number;
          max: number;
      }
    | { field: 'tierDurations'; code: 'sum_between'; min: number; max: number }
    | {
          field: 'tierDurations';
          code: 'duration_mismatch';
          sum: number;
          duration: number;
      };

/** Entrée soumise aux bornes croisées 1 et 2. */
export type CrossBoundInput = {
    framesPerRound: number;
    roundDuration?: number;
    tierDurations?: number[];
};

/**
 * Bornes croisées 1 et 2, dans l'ordre et selon la règle d'interaction de
 * `RoomSettings::validate()` (§ 4.1) : `N` ramené dans ses bornes, un `D`
 * refusé jamais comparé à la somme des paliers, une liste de mauvaise taille
 * ne produisant que `list_size`. Le serveur les refuse de toute façon ; le
 * client s'en sert pour ne pas envoyer ce que le serveur refuserait — `D`
 * remonté quand `N` augmente.
 */
export function crossBoundErrors(
    bounds: RoomSettingsBoundsPayload,
    input: CrossBoundInput,
): CrossBoundError[] {
    const frames = clampFramesPerRound(bounds, input.framesPerRound);
    const { roundDuration: durationBound, tierDuration: tierBound } = boundsFor(
        bounds,
        frames,
    );
    const errors: CrossBoundError[] = [];
    let acceptedDuration: number | null = null;

    if (input.roundDuration !== undefined) {
        if (
            input.roundDuration < durationBound.min ||
            input.roundDuration > durationBound.max
        ) {
            errors.push({
                field: 'roundDuration',
                code: 'round_duration',
                min: durationBound.min,
                max: durationBound.max,
                frames,
            });
        } else {
            acceptedDuration = input.roundDuration;
        }
    }

    if (input.tierDurations === undefined) {
        return errors;
    }

    if (input.tierDurations.length !== frames) {
        errors.push({
            field: 'tierDurations',
            code: 'list_size',
            size: frames,
        });

        return errors;
    }

    input.tierDurations.forEach((duration, index) => {
        if (duration < tierBound.min || duration > tierBound.max) {
            errors.push({
                field: 'tierDurations',
                code: 'tier_duration',
                tier: index + 1,
                min: tierBound.min,
                max: tierBound.max,
            });
        }
    });

    const sum = roundDuration({ tierDurations: input.tierDurations });

    if (sum < durationBound.min || sum > durationBound.max) {
        errors.push({
            field: 'tierDurations',
            code: 'sum_between',
            min: durationBound.min,
            max: durationBound.max,
        });
    }

    if (acceptedDuration !== null && sum !== acceptedDuration) {
        errors.push({
            field: 'tierDurations',
            code: 'duration_mismatch',
            sum,
            duration: acceptedDuration,
        });
    }

    return errors;
}

/** Unité des pourcentages de `B_max` (`PlatformLimits::FULL_PERCENT`). */
const FULL_PERCENT = 100;

/**
 * « Attendre paie » (`ScoringRules::waitingPays()`, spec 80 § 3.4) : vrai si,
 * pour un palier `i < N`, `P_i < P_{i+1} + ⌊P_{i+1} × B_max(N) / 100⌋` — la
 * fin d'un palier rapporte moins que l'ouverture du suivant, bonus compris.
 * `percent` est `B_max(N)` en pourcentage entier, lu dans la prop `limits`.
 */
export function waitingPays(tierPoints: number[], percent: number): boolean {
    return tierPoints.some((points, index) => {
        const next = tierPoints[index + 1];

        return (
            next !== undefined &&
            points < next + Math.floor((next * percent) / FULL_PERCENT)
        );
    });
}

/**
 * Avertissements non bloquants, cumulables — bornes croisées 4 et 5, dans
 * l'ordre de `RoomSettings::warnings()` : révélation courte, manche longue,
 * barème non strictement décroissant, « attendre paie » (bonus actif
 * seulement, jamais en plus du précédent), barème entièrement à zéro.
 *
 * `N` est la taille du barème, et `B_max(N)` est lu dans
 * `limits.speedBonusMaxPercent` : une table sans ce `N` ne lève jamais
 * `waiting_pays` (le serveur reste seul juge).
 */
export function warnings(
    bounds: RoomSettingsBoundsPayload,
    limits: Pick<PlatformLimitsPayload, 'speedBonusMaxPercent'>,
    view: Pick<
        RoomSettingsView,
        'revealDuration' | 'tierDurations' | 'tierPoints' | 'speedBonus'
    >,
): RoomSettingsWarningCode[] {
    const { recommendedMinRevealDuration, longRoundWarningDuration } =
        bounds.warningThresholds;
    const codes: RoomSettingsWarningCode[] = [];

    if (view.revealDuration < recommendedMinRevealDuration) {
        codes.push('short_reveal');
    }

    if (roundDuration(view) > longRoundWarningDuration) {
        codes.push('long_round');
    }

    if (
        view.tierPoints.some(
            (points, index) =>
                index > 0 && points >= (view.tierPoints[index - 1] ?? points),
        )
    ) {
        codes.push('non_decreasing_points');
    }

    const percent = limits.speedBonusMaxPercent[String(view.tierPoints.length)];

    if (
        !codes.includes('non_decreasing_points') &&
        view.speedBonus &&
        percent !== undefined &&
        waitingPays(view.tierPoints, percent)
    ) {
        codes.push('waiting_pays');
    }

    if (view.tierPoints.reduce((sum, points) => sum + points, 0) === 0) {
        codes.push('all_tiers_zero');
    }

    return codes;
}

/**
 * Instant d'apparition des propositions en difficulté Normal (§ 4.5) :
 * `T_N`, en secondes depuis le début de la manche (`Σ_{i<N} dᵢ`), et sa part
 * de la manche en pourcentage arrondi. Une information, pas un
 * avertissement.
 */
export function choicesAtPercent(
    view: Pick<RoomSettingsView, 'tierDurations'>,
): {
    seconds: number;
    percent: number;
} {
    const total = roundDuration(view);
    const seconds = total - (view.tierDurations.at(-1) ?? 0);

    return {
        seconds,
        percent: total === 0 ? 0 : Math.round((100 * seconds) / total),
    };
}

/** Annonce d'un réglage ajusté par le client lui-même. */
export type SettingsAnnouncement = {
    key: TranslationKey;
    seconds: number;
};

/** Écriture de l'onglet Simple composée par le client pour un changement de `N`. */
export type FramesPerRoundChange = {
    body: { framesPerRound: number; roundDuration?: number };
    /** `D` remonté au minimum du nouveau `N`, à annoncer ; `null` sinon. */
    announcement: SettingsAnnouncement | null;
};

/**
 * Changement de `N` dans l'onglet Simple (§ 3.2, § 4.3) : le serveur
 * n'ajuste jamais `D`. Quand `D` tombe sous le minimum du nouveau `N` (borne
 * croisée 1), le client le remonte à ce minimum **dans le même envoi** et
 * l'annonce par `room.settings.roundDuration.raised` — jamais d'ajustement
 * silencieux. Baisser `N` ne remonte jamais rien : le minimum décroît avec
 * `N`.
 */
export function framesPerRoundChange(
    bounds: RoomSettingsBoundsPayload,
    view: Pick<RoomSettingsView, 'tierDurations'>,
    framesPerRound: number,
): FramesPerRoundChange {
    const duration = roundDuration(view);
    const violation = crossBoundErrors(bounds, {
        framesPerRound,
        roundDuration: duration,
    }).find((error) => error.code === 'round_duration');

    // Aucune violation, ou un `D` au-dessus de son plafond : rien à remonter
    // (le plafond de `D` ne dépend pas de `N`, le serveur refuserait).
    if (violation === undefined || duration >= violation.min) {
        return { body: { framesPerRound }, announcement: null };
    }

    return {
        body: { framesPerRound, roundDuration: violation.min },
        announcement: {
            key: 'room.settings.roundDuration.raised',
            seconds: violation.min,
        },
    };
}

/** Écriture de l'onglet Avancé composée par le client pour un changement de `N`. */
export type AdvancedFramesPerRoundChange = {
    body: {
        framesPerRound: number;
        tierDurations: number[];
        tierPoints: number[];
    };
    /** `D` remonté au minimum du nouveau `N`, à annoncer ; `null` sinon. */
    announcement: SettingsAnnouncement | null;
    /** Le barème personnalisé est remplacé par le défaut du nouveau `N`. */
    pointsReset: boolean;
};

/**
 * Changement de `N` dans l'onglet Avancé (§ 3.3) : l'éditeur Avancé ne
 * dérive rien, c'est le client qui poste les **deux listes redimensionnées**
 * — paliers égaux sur `max(D₀, minRoundDuration(N₁))` et barème par défaut
 * du nouveau `N` —, et qui annonce `D` remonté. Le serveur rapporte `reset`
 * quand le barème remplacé était personnalisé ; `pointsReset` le dit
 * d'avance, pour l'écran.
 */
export function advancedFramesPerRoundChange(
    bounds: RoomSettingsBoundsPayload,
    view: Pick<RoomSettingsView, 'tierDurations' | 'tierPoints'>,
    framesPerRound: number,
): AdvancedFramesPerRoundChange {
    const duration = roundDuration(view);
    const minimum = minRoundDuration(bounds, framesPerRound);
    const target = Math.max(duration, minimum);
    const current = view.tierPoints.length;

    return {
        body: {
            framesPerRound,
            tierDurations: defaultTierDurations(bounds, framesPerRound, target),
            tierPoints: defaultTierPoints(bounds, framesPerRound),
        },
        announcement:
            target === duration
                ? null
                : {
                      key: 'room.settings.roundDuration.raised',
                      seconds: target,
                  },
        pointsReset:
            framesPerRound !== current &&
            !sameList(view.tierPoints, defaultTierPoints(bounds, current)),
    };
}

function sameList(left: number[], right: number[]): boolean {
    return (
        left.length === right.length &&
        left.every((value, index) => value === right[index])
    );
}

/** Rapport de changements rendu à l'auteur d'une écriture (§ 2.6). */
export type SettingsChangeReport = Partial<
    Record<RoomSettingsFieldKey, RoomSettingsChangeCode>
>;

/** Clés du rapport : les champs client (`themeKeys`, jamais `themeIds`). */
const FIELD_KEYS: ReadonlySet<string> = new Set<RoomSettingsFieldKey>([
    'themeKeys',
    'roundsCount',
    'framesPerRound',
    'roundDuration',
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
]);

/** Codes du rapport (`RoomSettings::CHANGE_*`). */
const CHANGE_CODES: ReadonlySet<string> = new Set<RoomSettingsChangeCode>([
    'defaulted',
    'dropped',
    'clamped',
    'resized',
    'pruned',
    'coerced',
    'equalized',
    'reset',
    'raised',
    'overwritten',
]);

function isFieldKey(value: string): value is RoomSettingsFieldKey {
    return FIELD_KEYS.has(value);
}

function isChangeCode(value: unknown): value is RoomSettingsChangeCode {
    return typeof value === 'string' && CHANGE_CODES.has(value);
}

/**
 * Le rapport porté par le flash `settingsChanges` d'une écriture de
 * réglages, ou `null` si le flash n'en porte pas.
 *
 * Le serveur le pose **toujours**, même vide : un rapport vide arrive en
 * tableau JSON `[]` (tableau PHP vide) et se lit comme un rapport sans
 * changement, qui efface le précédent. Une clé ou un code inconnu est
 * ignoré : le client ne rend que ce qu'il sait nommer. L'ordre reçu (celui
 * de `FIELDS`) est conservé.
 */
export function settingsChangesFrom(
    flash: unknown,
): SettingsChangeReport | null {
    if (typeof flash !== 'object' || flash === null) {
        return null;
    }

    const payload: unknown = Reflect.get(flash, 'settingsChanges');

    if (payload === undefined || payload === null) {
        return null;
    }

    const report: SettingsChangeReport = {};

    if (typeof payload !== 'object' || Array.isArray(payload)) {
        return report;
    }

    for (const [field, code] of Object.entries(payload)) {
        if (isFieldKey(field) && isChangeCode(code)) {
            report[field] = code;
        }
    }

    return report;
}

/**
 * Le libellé de chaque champ client (§ 20.1), qui nomme aussi le champ dans
 * une ligne du rapport de changements (`:attribute`). Une table de clés
 * littérales, jamais une clé composée (C15 § 2.7) : un champ ajouté à
 * `RoomSettingsFieldKey` sans sa ligne casse `tsc`.
 */
export const SETTING_LABEL_KEYS: Record<RoomSettingsFieldKey, TranslationKey> =
    {
        themeKeys: 'room.settings.themeKeys.label',
        roundsCount: 'room.settings.roundsCount.label',
        framesPerRound: 'room.settings.framesPerRound.label',
        roundDuration: 'room.settings.roundDuration.label',
        tierDurations: 'room.settings.tierDurations.label',
        tierPoints: 'room.settings.tierPoints.label',
        revealDuration: 'room.settings.revealDuration.label',
        inputDifficulty: 'room.settings.inputDifficulty.label',
        capacity: 'room.settings.capacity.label',
        allowLateJoin: 'room.settings.allowLateJoin.label',
        speedBonus: 'room.settings.speedBonus.label',
        noRepeatMovies: 'room.settings.noRepeatMovies.label',
        attemptsPerSecond: 'room.settings.attemptsPerSecond.label',
        attemptsPerRound: 'room.settings.attemptsPerRound.label',
        maxAnswerLength: 'room.settings.maxAnswerLength.label',
        disconnectGraceSeconds: 'room.settings.disconnectGraceSeconds.label',
        advanced: 'room.settings.advanced.label',
    };

/** Une clé par code du rapport (`RoomSettings::CHANGE_*`, § 20.2). */
export const SETTINGS_CHANGE_KEYS: Record<
    RoomSettingsChangeCode,
    TranslationKey
> = {
    defaulted: 'room.settings.change.defaulted',
    dropped: 'room.settings.change.dropped',
    clamped: 'room.settings.change.clamped',
    resized: 'room.settings.change.resized',
    pruned: 'room.settings.change.pruned',
    coerced: 'room.settings.change.coerced',
    equalized: 'room.settings.change.equalized',
    reset: 'room.settings.change.reset',
    raised: 'room.settings.change.raised',
    overwritten: 'room.settings.change.overwritten',
};

/**
 * Les lignes d'un rapport, dans l'ordre reçu : `room.settings.change.<code>`,
 * `:attribute` recevant le libellé traduit du champ (§ 20.2).
 */
export function settingsChangeLines(
    changes: SettingsChangeReport,
    t: (key: TranslationKey, replacements?: Record<string, string>) => string,
): string[] {
    const lines: string[] = [];

    for (const [field, code] of Object.entries(changes)) {
        if (isFieldKey(field) && isChangeCode(code)) {
            lines.push(
                t(SETTINGS_CHANGE_KEYS[code], {
                    attribute: t(SETTING_LABEL_KEYS[field]),
                }),
            );
        }
    }

    return lines;
}
