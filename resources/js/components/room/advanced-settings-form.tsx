import { useId } from 'react';
import { SettingSlider, SettingSwitch } from '@/components/room/setting-fields';
import type { FieldIds } from '@/components/room/setting-fields';
import { useTranslations } from '@/hooks/use-translations';
import { roundDuration } from '@/lib/room-settings';
import type {
    RoomSettingsBoundsForN,
    RoomSettingsView,
} from '@/types/room-settings';
import type { TranslationKey } from '@/types/translations';

/** Les champs de l'onglet Avancé réglés au curseur, hors paliers. */
export type AdvancedSliderField =
    | 'attemptsPerSecond'
    | 'attemptsPerRound'
    | 'maxAnswerLength'
    | 'disconnectGraceSeconds';

/** Les deux listes d'un palier par image (`N` entrées). */
export type TierList = 'tierDurations' | 'tierPoints';

/** Les interrupteurs de l'onglet Avancé. */
export type AdvancedToggle = 'speedBonus' | 'noRepeatMovies';

type Unit = 'seconds' | 'count';

/**
 * Libellé, aide et unité de chaque curseur (§ 20.1) : une table de clés
 * littérales, jamais une clé composée (C15 § 2.7).
 */
const SLIDERS: Record<
    AdvancedSliderField,
    { label: TranslationKey; help: TranslationKey; unit: Unit }
> = {
    attemptsPerSecond: {
        label: 'room.settings.attemptsPerSecond.label',
        help: 'room.settings.attemptsPerSecond.help',
        unit: 'count',
    },
    attemptsPerRound: {
        label: 'room.settings.attemptsPerRound.label',
        help: 'room.settings.attemptsPerRound.help',
        unit: 'count',
    },
    maxAnswerLength: {
        label: 'room.settings.maxAnswerLength.label',
        help: 'room.settings.maxAnswerLength.help',
        unit: 'count',
    },
    disconnectGraceSeconds: {
        label: 'room.settings.disconnectGraceSeconds.label',
        help: 'room.settings.disconnectGraceSeconds.help',
        unit: 'seconds',
    },
};

const TIER_LISTS: Record<
    TierList,
    { label: TranslationKey; help: TranslationKey; unit: Unit }
> = {
    tierDurations: {
        label: 'room.settings.tierDurations.label',
        help: 'room.settings.tierDurations.help',
        unit: 'seconds',
    },
    tierPoints: {
        label: 'room.settings.tierPoints.label',
        help: 'room.settings.tierPoints.help',
        unit: 'count',
    },
};

const TOGGLES: Record<
    AdvancedToggle,
    { label: TranslationKey; help: TranslationKey }
> = {
    speedBonus: {
        label: 'room.settings.speedBonus.label',
        help: 'room.settings.speedBonus.help',
    },
    noRepeatMovies: {
        label: 'room.settings.noRepeatMovies.label',
        help: 'room.settings.noRepeatMovies.help',
    },
};

type AdvancedSettingsFormProps = {
    /** Les réglages affichés : ceux du serveur, positions tenues comprises. */
    shown: RoomSettingsView;
    /** Les réglages du serveur, pour savoir si un geste change quelque chose. */
    server: RoomSettingsView;
    /** Bornes du `N` courant (`bounds.byFramesPerRound[N]`). */
    bounds: RoomSettingsBoundsForN;
    /**
     * `B_max(N)` du `N` courant, en pourcentage entier
     * (`limits.speedBonusMaxPercent[N]`, D22 du 23/09) ; `null` si la table
     * ne le porte pas.
     */
    speedBonusMaxPercent: number | null;
    readOnly: boolean;
    /** Erreurs de la dernière écriture refusée, déjà traduites, par champ. */
    errors: Record<string, string>;
    onHold: (
        field: AdvancedSliderField,
        value: number,
        current: number,
    ) => void;
    onCommit: (
        field: AdvancedSliderField,
        value: number,
        current: number,
    ) => void;
    onHoldTier: (list: TierList, index: number, value: number) => void;
    onCommitTier: (list: TierList, index: number, value: number) => void;
    onToggle: (field: AdvancedToggle, value: boolean) => void;
};

/**
 * Les réglages propres à l'onglet Avancé (spec 50 § 3.3, lot L50-10) : durée
 * de chaque palier — `D` devient leur somme —, points de chaque palier,
 * bonus de rapidité, non-répétition, cadence et plafond des tentatives,
 * longueur maximale d'une réponse, délai avant « parti ».
 *
 * Présentation seule : les écritures, positions tenues et la file d'envoi
 * appartiennent au formulaire des réglages (`room-settings-form.tsx`), qui
 * monte ce composant dans l'onglet Avancé sur desktop et dans sa feuille
 * plein écran sur mobile. Bornes lues dans `bounds` du `N` courant, jamais
 * écrites ici (règle 2) ; `B_max(N)` affiché dans l'aide du bonus
 * (`game.help.scoring.speed_bonus`, § 4.5), jamais réglable.
 *
 * Accessibilité : chaque liste de paliers est un groupe nommé (`role="group"`
 * à `aria-labelledby`), décrit par son aide ; chaque curseur porte « Image i »
 * et sa valeur en mots. Erreurs sous le curseur fautif (`tierDurations.{i}`,
 * `tierPoints.{i}`) ou sous la liste (`tierDurations`, `tierPoints`).
 */
export function AdvancedSettingsForm({
    shown,
    server,
    bounds,
    speedBonusMaxPercent,
    readOnly,
    errors,
    onHold,
    onCommit,
    onHoldTier,
    onCommitTier,
    onToggle,
}: AdvancedSettingsFormProps) {
    const { t, locale } = useTranslations();
    const baseId = useId();
    const count = new Intl.NumberFormat(locale);
    const secondsUnit = new Intl.NumberFormat(locale, {
        style: 'unit',
        unit: 'second',
        unitDisplay: 'short',
    });
    const format = (unit: Unit) =>
        unit === 'seconds'
            ? (value: number): string => secondsUnit.format(value)
            : (value: number): string => count.format(value);

    const fieldIds = (field: string): FieldIds => ({
        label: `${baseId}-${field}-label`,
        help: `${baseId}-${field}-help`,
        error: `${baseId}-${field}-error`,
    });

    const errorFor = (field: string): string | null => errors[field] ?? null;

    const renderTierList = (list: TierList) => {
        const keys = TIER_LISTS[list];
        const ids = fieldIds(list);
        const values = shown[list];
        const listError = errorFor(list);
        const bound =
            list === 'tierDurations' ? bounds.tierDuration : bounds.tierPoints;
        const total = list === 'tierDurations' ? roundDuration(shown) : null;

        return (
            <div
                role="group"
                aria-labelledby={ids.label}
                aria-describedby={
                    listError === null ? ids.help : `${ids.help} ${ids.error}`
                }
                className="flex flex-col gap-3"
            >
                <div className="flex flex-col gap-1">
                    <div className="flex items-baseline justify-between gap-4">
                        <span id={ids.label} className="text-base font-medium">
                            {t(keys.label)}
                        </span>
                        {total !== null && (
                            <span className="font-medium tabular-nums">
                                {secondsUnit.format(total)}
                            </span>
                        )}
                    </div>
                    <p id={ids.help} className="text-sm text-muted-foreground">
                        {t(keys.help)}
                    </p>
                </div>

                {values.map((value, index) => {
                    const tier = index + 1;
                    const tierIds = fieldIds(`${list}-${tier}`);

                    return (
                        <SettingSlider
                            // Une position par palier : `N` fixe la longueur.
                            key={tier}
                            ids={tierIds}
                            label={t('room.settings.tier_label', {
                                index: count.format(tier),
                            })}
                            help={null}
                            note={null}
                            error={errorFor(`${list}.${index}`)}
                            value={value}
                            min={bound.min}
                            max={bound.max}
                            format={format(keys.unit)}
                            disabled={readOnly}
                            onDraft={(next) => onHoldTier(list, index, next)}
                            onCommit={(next) => onCommitTier(list, index, next)}
                        />
                    );
                })}

                {listError !== null && (
                    <p id={ids.error} className="text-sm text-destructive">
                        {listError}
                    </p>
                )}
            </div>
        );
    };

    const renderSlider = (field: AdvancedSliderField) => {
        const keys = SLIDERS[field];

        return (
            <SettingSlider
                ids={fieldIds(field)}
                label={t(keys.label)}
                help={t(keys.help)}
                note={null}
                error={errorFor(field)}
                value={shown[field]}
                min={bounds[field].min}
                max={bounds[field].max}
                format={format(keys.unit)}
                disabled={readOnly}
                onDraft={(value) => onHold(field, value, server[field])}
                onCommit={(value) => onCommit(field, value, server[field])}
            />
        );
    };

    const renderToggle = (field: AdvancedToggle, note: string | null) => {
        const keys = TOGGLES[field];

        return (
            <SettingSwitch
                ids={fieldIds(field)}
                label={t(keys.label)}
                help={t(keys.help)}
                note={note}
                error={errorFor(field)}
                checked={server[field]}
                disabled={readOnly}
                onCheckedChange={(value) => onToggle(field, value)}
            />
        );
    };

    return (
        <div className="flex flex-col gap-5">
            {renderTierList('tierDurations')}
            {renderTierList('tierPoints')}
            {renderToggle(
                'speedBonus',
                speedBonusMaxPercent === null
                    ? null
                    : t('game.help.scoring.speed_bonus', {
                          percent: count.format(speedBonusMaxPercent),
                      }),
            )}
            {renderToggle('noRepeatMovies', null)}
            {renderSlider('attemptsPerSecond')}
            {renderSlider('attemptsPerRound')}
            {renderSlider('maxAnswerLength')}
            {renderSlider('disconnectGraceSeconds')}
        </div>
    );
}
