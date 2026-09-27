import type {
    FormDataConvertible,
    HttpExceptionResponse,
} from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useId, useLayoutEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { SettingsWarnings } from '@/components/room/settings-warnings';
import { ReadOnlyNotice } from '@/components/state/read-only-notice';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import {
    boundsFor,
    choicesAtPercent,
    defaultTierDurations,
    framesPerRoundChange,
    framesPerRoundOptions,
    minRoundDuration,
    roundDuration,
    warnings,
} from '@/lib/room-settings';
import { update } from '@/routes/room/settings';
import type {
    InputDifficulty,
    RoomSettingsBoundsPayload,
    RoomSettingsState,
    RoomSettingsView,
} from '@/types/room-settings';
import type { TranslationKey } from '@/types/translations';

/** Les champs de l'onglet Simple rendus au J1 (`SIMPLE_KEYS`, § 3.1). */
type SimpleField =
    | 'roundsCount'
    | 'framesPerRound'
    | 'roundDuration'
    | 'revealDuration'
    | 'inputDifficulty'
    | 'capacity'
    | 'allowLateJoin';

/** Les champs réglés au curseur (§ 8.1). */
type SliderField =
    | 'roundsCount'
    | 'roundDuration'
    | 'revealDuration'
    | 'capacity';

/** Valeurs en cours de geste, affichées avant la réponse du serveur. */
type Drafts = Partial<Record<SliderField, number>>;

/** Corps d'une écriture Simple : les seuls champs changés. */
type WriteBody = Partial<Record<SimpleField, FormDataConvertible>>;

/**
 * Libellé et aide de chaque champ (§ 20.1) : une table de clés littérales,
 * jamais une clé composée (C15 § 2.7).
 */
const FIELD_KEYS: Record<
    SimpleField,
    { label: TranslationKey; help: TranslationKey }
> = {
    roundsCount: {
        label: 'room.settings.roundsCount.label',
        help: 'room.settings.roundsCount.help',
    },
    framesPerRound: {
        label: 'room.settings.framesPerRound.label',
        help: 'room.settings.framesPerRound.help',
    },
    roundDuration: {
        label: 'room.settings.roundDuration.label',
        help: 'room.settings.roundDuration.help',
    },
    revealDuration: {
        label: 'room.settings.revealDuration.label',
        help: 'room.settings.revealDuration.help',
    },
    inputDifficulty: {
        label: 'room.settings.inputDifficulty.label',
        help: 'room.settings.inputDifficulty.help',
    },
    capacity: {
        label: 'room.settings.capacity.label',
        help: 'room.settings.capacity.help',
    },
    allowLateJoin: {
        label: 'room.settings.allowLateJoin.label',
        help: 'room.settings.allowLateJoin.help',
    },
};

/**
 * Les cas de `App\Enums\InputDifficulty`, dans l'ordre de présentation : le
 * domaine d'un enum, pas une valeur de jeu.
 */
const DIFFICULTY_OPTIONS: Record<InputDifficulty, TranslationKey> = {
    easy: 'room.settings.inputDifficulty.option.easy',
    normal: 'room.settings.inputDifficulty.option.normal',
    expert: 'room.settings.inputDifficulty.option.expert',
};

function isDifficulty(value: string): value is InputDifficulty {
    return Object.hasOwn(DIFFICULTY_OPTIONS, value);
}

/**
 * Touches qui déplacent un curseur (motif ARIA « slider ») : au clavier, le
 * geste est validé au relâchement de la touche, jamais à chaque pas.
 */
const SLIDER_KEYS: ReadonlySet<string> = new Set([
    'ArrowLeft',
    'ArrowRight',
    'ArrowUp',
    'ArrowDown',
    'PageUp',
    'PageDown',
    'Home',
    'End',
]);

/** Premier message d'une écriture refusée, déjà traduit. */
function firstError(errors: Partial<Record<string, string>>): string | null {
    return (
        Object.values(errors).find(
            (message): message is string =>
                message !== undefined && message !== '',
        ) ?? null
    );
}

type RoomSettingsFormProps = {
    /** Code du salon (prop `room.code`), pour Wayfinder. */
    roomCode: string;
    /**
     * L'état des réglages du salon — prop `settings`, puis chaque
     * `settings.changed` —, tel que le serveur le rend : valeurs et
     * avertissements.
     */
    state: RoomSettingsState;
    /** Prop `bounds` : bornes de chaque `N`, dérivations et seuils. */
    bounds: RoomSettingsBoundsPayload;
    /**
     * Effectif présent : sièges ni partis ni expulsés (`state.seats`). La
     * capacité n'est jamais abaissée en dessous (§ 10).
     */
    headcount: number;
    /**
     * Le siège est l'hôte : lui seul règle (§ 8.1) ; les autres voient les
     * mêmes réglages en lecture seule (`room.lobby.read_only`).
     */
    editable: boolean;
    /**
     * L'interrupteur `allowLateJoin` est-il proposé ? Prop
     * `editor.lateJoinAvailable` — `true` au J1 comme au J2 (D35 du 23/09,
     * § 15.4) : le serveur décide de sa présence.
     */
    lateJoinAvailable: boolean;
    /**
     * Écriture impossible : onglet supplanté, connexion perdue ou siège sorti
     * (§ 8.1, état « déconnexion »). Le serveur relit tout sous le verrou du
     * salon de toute façon.
     */
    disabled: boolean;
    /** Intercepte le 409 `seat_superseded` de `seat.active` (§ 8.1). */
    onHttpException: (response: HttpExceptionResponse) => boolean | void;
    /**
     * Un refus, déjà traduit : la page l'annonce dans l'unique région
     * vivante — jamais un toast.
     */
    onRefused: (message: string) => void;
};

/**
 * Le formulaire des réglages du salon, onglet Simple (spec 50 § 3, § 4 et
 * § 8.1) — lot L50-5, sur l'interrupteur des retardataires livré par L50-9.
 *
 * Champs du J1, dans l'ordre du § 20.1 : nombre de manches, images par
 * manche, durée d'une manche, durée de la révélation, difficulté de saisie,
 * places, retardataires. Le sélecteur de thèmes (J2, § 9.5) et l'onglet
 * Avancé (J2, § 3.3) n'y sont pas : le serveur accepte `themeKeys` sans que
 * l'écran le propose, et refuse tout champ avancé (`not_editable`).
 *
 * **L'écriture est immédiate et part au geste** (§ 8.3), par
 * `router.patch()` sur `room.settings.update` (`preserveState`,
 * `preserveScroll`, en-tête `X-Seat-Token` posé par le magasin) : le corps
 * ne porte que le champ changé, et le serveur compose le reste avec l'état
 * courant (règle D34 du 23/09). Un curseur envoie **à la validation du
 * geste** (`onValueCommit`) — au relâchement du pointeur, ou de la touche au
 * clavier —, jamais à chaque pas. Une seule écriture à la fois : un geste
 * fait pendant l'envoi attend la réponse, fusionné avec les suivants, puis
 * part seul ; un refus l'abandonne. La valeur affichée reste celle du
 * serveur, relue à la réponse puis diffusée aux autres sièges
 * (`settings.changed`) — seul le curseur tenu montre sa position.
 *
 * **Retour immédiat, le serveur restant seul juge** (§ 4.3,
 * `lib/room-settings.ts`, sans aucun littéral de jeu) :
 * - le curseur de `D` a pour borne basse le **minimum effectif** du `N`
 *   courant (`D ≥ 5 s × N`) ;
 * - quand l'hôte augmente `N` et que `D` tombe sous le nouveau minimum, `D`
 *   est remonté **dans le même envoi** et annoncé
 *   (`room.settings.roundDuration.raised`) ;
 * - pendant un geste, les avertissements et l'instant des propositions du
 *   mode Normal suivent la valeur tenue ; sinon, ceux du serveur ;
 * - la borne basse des places ne descend jamais sous l'effectif présent
 *   (§ 10).
 *
 * États : chargement (`aria-busy`, un seul envoi à la fois ; les contrôles
 * gardent le focus, aucun n'est désactivé pendant l'envoi — un contrôle
 * désactivé perdrait le focus à chaque pas au clavier) ; erreur (message
 * sous le champ fautif, lié par `aria-describedby`, ou sous la section en
 * `Alert` au rôle `note` s'il ne vise aucun champ affiché ; annoncé par la
 * page) ; déconnexion et onglet supplanté (`disabled` : un geste en cours
 * est abandonné, sans envoi ni position tenue). Clavier : curseurs
 * au motif ARIA « slider » (valeur, bornes et pas exposés), `N` et la
 * difficulté en groupes radio (un arrêt de tabulation, flèches),
 * interrupteur natif ; cibles d'au moins 44 px. Aucune couleur en dur.
 */
export function RoomSettingsForm({
    roomCode,
    state,
    bounds,
    headcount,
    editable,
    lateJoinAvailable,
    disabled,
    onHttpException,
    onRefused,
}: RoomSettingsFormProps) {
    const { t, locale } = useTranslations();
    const [pending, setPending] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [drafts, setDrafts] = useState<Drafts>({});
    // `D` remonté par le client au dernier changement de `N` : la note reste
    // sous le curseur tant que le serveur porte ce couple (N, D).
    const [raised, setRaised] = useState<{
        frames: number;
        seconds: number;
    } | null>(null);
    const inFlight = useRef(false);
    const queued = useRef<WriteBody | null>(null);
    const baseId = useId();
    const titleId = `${baseId}-title`;
    const generalErrorId = `${baseId}-error`;
    const readOnly = !editable || disabled;

    // Un geste interrompu par la lecture seule (connexion perdue, onglet
    // supplanté) est abandonné : Radix ne valide pas le geste d'un curseur
    // désactivé, et sa position tenue masquerait sinon la valeur du serveur
    // jusqu'à la prochaine écriture de ce formulaire.
    const [wasReadOnly, setWasReadOnly] = useState(readOnly);

    if (readOnly !== wasReadOnly) {
        setWasReadOnly(readOnly);

        if (readOnly) {
            setDrafts({});
        }
    }

    const server = state.settings;
    const frames = server.framesPerRound;
    const forFrames = boundsFor(bounds, frames);
    const serverDuration = roundDuration(server);
    const duration = drafts.roundDuration ?? serverDuration;
    const shown: RoomSettingsView = {
        ...server,
        roundsCount: drafts.roundsCount ?? server.roundsCount,
        revealDuration: drafts.revealDuration ?? server.revealDuration,
        capacity: drafts.capacity ?? server.capacity,
        tierDurations:
            drafts.roundDuration === undefined
                ? server.tierDurations
                : defaultTierDurations(bounds, frames, duration),
    };
    const shownWarnings =
        Object.keys(drafts).length > 0
            ? warnings(bounds, shown)
            : state.warnings;
    const choicesAt = choicesAtPercent(shown);

    const count = new Intl.NumberFormat(locale);
    const secondsUnit = new Intl.NumberFormat(locale, {
        style: 'unit',
        unit: 'second',
        unitDisplay: 'short',
    });
    const formatCount = (value: number): string => count.format(value);
    const formatSeconds = (value: number): string => secondsUnit.format(value);

    const fieldIds = (field: SimpleField) => ({
        label: `${baseId}-${field}-label`,
        help: `${baseId}-${field}-help`,
        error: `${baseId}-${field}-error`,
    });

    /**
     * Une écriture, ou sa mise en attente derrière celle qui est en vol. Un
     * refus (erreur de validation, 409, réseau, annulation) abandonne
     * l'attente et rend l'affichage au serveur.
     */
    const send = (body: WriteBody): void => {
        setRaised(null);

        if (inFlight.current) {
            queued.current = { ...queued.current, ...body };

            return;
        }

        inFlight.current = true;
        let failed = false;

        router.patch(update.url({ room: roomCode }), body, {
            preserveScroll: true,
            preserveState: true,
            onHttpException: (response) => {
                failed = true;

                return onHttpException(response);
            },
            onNetworkError: () => {
                failed = true;
            },
            onCancel: () => {
                failed = true;
            },
            onStart: () => {
                setPending(true);
                setErrors({});
            },
            onError: (fieldErrors) => {
                failed = true;
                setErrors(fieldErrors);

                const message = firstError(fieldErrors);

                if (message !== null) {
                    onRefused(message);
                }
            },
            onFinish: () => {
                inFlight.current = false;

                const next = queued.current;

                queued.current = null;

                if (next !== null && !failed) {
                    send(next);

                    return;
                }

                setPending(false);
                setDrafts({});
            },
        });
    };

    /**
     * Position tenue pendant un geste. Revenue sur la valeur du serveur hors
     * de tout envoi, elle n'est plus une valeur tenue : Radix ne valide pas
     * un geste qui finit là où il a commencé, et une position gardée
     * masquerait ensuite la valeur que le serveur prendrait par un autre
     * chemin (preset, remède). Pendant un envoi, elle reste tenue : la
     * réponse déplacera le serveur, et la fin de l'envoi efface tout.
     */
    const hold = (field: SliderField, value: number, current: number): void => {
        const settled = value === current && !inFlight.current;

        setDrafts((held) => {
            const next = { ...held };

            if (settled) {
                delete next[field];
            } else {
                next[field] = value;
            }

            return next;
        });
    };

    /**
     * Validation d'un geste de curseur : rien ne part si rien ne change, ni
     * si le formulaire est passé en lecture seule pendant le geste.
     */
    const commit = (
        field: SliderField,
        value: number,
        current: number,
    ): void => {
        if (readOnly) {
            return;
        }

        if (value === current && !inFlight.current) {
            setDrafts((held) => {
                const next = { ...held };

                delete next[field];

                return next;
            });

            return;
        }

        send({ [field]: value });
    };

    const changeFrames = (value: string): void => {
        const next = Number(value);

        if (!Number.isInteger(next) || next === frames) {
            return;
        }

        const change = framesPerRoundChange(bounds, shown, next);

        send(change.body);

        if (change.announcement !== null) {
            const seconds = change.announcement.seconds;

            setRaised({ frames: next, seconds });
            announce(
                t(change.announcement.key, { seconds: count.format(seconds) }),
            );
        }
    };

    // Erreurs sans champ affiché (`tierDurations`, `tierPoints`,
    // `themeKeys`…) : rendues sous la section.
    const shownFields = new Set<string>(Object.keys(FIELD_KEYS));
    const otherErrors = Object.entries(errors)
        .filter(([field]) => !shownFields.has(field))
        .map(([, message]) => message);

    const errorFor = (field: SimpleField): string | null =>
        errors[field] ?? null;

    // Borne basse des places (§ 10) : jamais sous l'effectif présent, sauf
    // quand l'effectif dépasse déjà la capacité (retour d'un siège parti) —
    // la capacité courante reste alors le plancher. Jamais au-dessus du
    // plafond de plateforme, même abaissé sous une capacité ancienne.
    const capacityBound = forFrames.capacity;
    const capacityMin = Math.min(
        capacityBound.max,
        Math.max(capacityBound.min, Math.min(headcount, server.capacity)),
    );

    /** Un curseur : valeur tenue, valeur du serveur, bornes, unité. */
    const renderSlider = (slider: {
        field: SliderField;
        value: number;
        current: number;
        min: number;
        max: number;
        format: (value: number) => string;
        note?: string;
    }) => {
        const keys = FIELD_KEYS[slider.field];

        return (
            <SettingSlider
                ids={fieldIds(slider.field)}
                label={t(keys.label)}
                help={t(keys.help)}
                note={slider.note ?? null}
                error={errorFor(slider.field)}
                value={slider.value}
                min={slider.min}
                max={slider.max}
                format={slider.format}
                disabled={readOnly}
                onDraft={(value) => hold(slider.field, value, slider.current)}
                onCommit={(value) =>
                    commit(slider.field, value, slider.current)
                }
            />
        );
    };

    const framesIds = fieldIds('framesPerRound');
    const difficultyIds = fieldIds('inputDifficulty');
    const lateJoinIds = fieldIds('allowLateJoin');
    const lateJoinError = errorFor('allowLateJoin');

    return (
        <section
            aria-labelledby={titleId}
            aria-busy={pending}
            className="flex flex-col gap-5"
        >
            <h2 id={titleId} className="text-lg font-semibold">
                {t('room.lobby.settings_title')}
            </h2>

            {!editable && (
                <ReadOnlyNotice message={t('room.lobby.read_only')} />
            )}

            {renderSlider({
                field: 'roundsCount',
                value: shown.roundsCount,
                current: server.roundsCount,
                min: forFrames.roundsCount.min,
                max: forFrames.roundsCount.max,
                format: formatCount,
            })}

            <SettingRadioGroup
                ids={framesIds}
                label={t(FIELD_KEYS.framesPerRound.label)}
                help={t(FIELD_KEYS.framesPerRound.help)}
                error={errorFor('framesPerRound')}
                value={String(frames)}
                disabled={readOnly}
                onValueChange={changeFrames}
                orientation="horizontal"
                options={framesPerRoundOptions(bounds).map((option) => ({
                    value: String(option),
                    label: count.format(option),
                    description: null,
                }))}
            />

            {renderSlider({
                field: 'roundDuration',
                value: duration,
                current: serverDuration,
                min: minRoundDuration(bounds, frames),
                max: forFrames.roundDuration.max,
                format: formatSeconds,
                note:
                    raised !== null &&
                    raised.frames === frames &&
                    raised.seconds === serverDuration
                        ? t('room.settings.roundDuration.raised', {
                              seconds: count.format(raised.seconds),
                          })
                        : undefined,
            })}

            {renderSlider({
                field: 'revealDuration',
                value: shown.revealDuration,
                current: server.revealDuration,
                min: forFrames.revealDuration.min,
                max: forFrames.revealDuration.max,
                format: formatSeconds,
            })}

            <SettingRadioGroup
                ids={difficultyIds}
                label={t(FIELD_KEYS.inputDifficulty.label)}
                help={t(FIELD_KEYS.inputDifficulty.help)}
                error={errorFor('inputDifficulty')}
                value={server.inputDifficulty}
                disabled={readOnly}
                onValueChange={(value) => {
                    if (
                        isDifficulty(value) &&
                        value !== server.inputDifficulty
                    ) {
                        send({ inputDifficulty: value });
                    }
                }}
                orientation="vertical"
                options={(
                    Object.entries(DIFFICULTY_OPTIONS) as [
                        InputDifficulty,
                        TranslationKey,
                    ][]
                ).map(([value, key]) => ({
                    value,
                    label: t(key),
                    // L'instant des propositions en Normal (§ 4.5) : une
                    // information, pas un avertissement.
                    description:
                        value === 'normal'
                            ? t('room.settings.inputDifficulty.choices_at', {
                                  seconds: count.format(choicesAt.seconds),
                                  percent: count.format(choicesAt.percent),
                              })
                            : null,
                }))}
            />

            {renderSlider({
                field: 'capacity',
                value: shown.capacity,
                current: server.capacity,
                min: capacityMin,
                max: capacityBound.max,
                format: formatCount,
            })}

            {lateJoinAvailable && (
                <div className="flex flex-col gap-1">
                    <div className="flex items-start justify-between gap-4">
                        <div className="flex flex-col gap-1">
                            <Label
                                htmlFor={lateJoinIds.label}
                                className="flex min-h-11 cursor-pointer items-center text-base"
                            >
                                {t(FIELD_KEYS.allowLateJoin.label)}
                            </Label>
                            <p
                                id={lateJoinIds.help}
                                className="text-sm text-muted-foreground"
                            >
                                {t(FIELD_KEYS.allowLateJoin.help)}
                            </p>
                        </div>

                        <div className="flex min-h-11 items-center">
                            <Switch
                                id={lateJoinIds.label}
                                checked={server.allowLateJoin}
                                onCheckedChange={(allowLateJoin) =>
                                    send({ allowLateJoin })
                                }
                                disabled={readOnly}
                                aria-invalid={
                                    lateJoinError === null ? undefined : true
                                }
                                aria-describedby={
                                    lateJoinError === null
                                        ? lateJoinIds.help
                                        : `${lateJoinIds.help} ${lateJoinIds.error}`
                                }
                            />
                        </div>
                    </div>

                    <FieldError
                        id={lateJoinIds.error}
                        message={lateJoinError}
                    />
                </div>
            )}

            <SettingsWarnings
                warnings={shownWarnings}
                thresholds={bounds.warningThresholds}
            />

            {otherErrors.length > 0 && (
                <Alert role="note" id={generalErrorId}>
                    <CircleAlert aria-hidden="true" />
                    <AlertDescription className="text-foreground">
                        <ul className="flex flex-col gap-1">
                            {otherErrors.map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}
        </section>
    );
}

type FieldIds = { label: string; help: string; error: string };

/** Message d'erreur d'un champ : icône et texte, jamais la seule couleur. */
function FieldError({ id, message }: { id: string; message: string | null }) {
    if (message === null) {
        return null;
    }

    return (
        <p id={id} className="flex items-start gap-1 text-sm text-destructive">
            <CircleAlert
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0"
            />
            {message}
        </p>
    );
}

type SettingSliderProps = {
    ids: FieldIds;
    label: string;
    help: string;
    /** Ajustement fait par le client lui-même (`D` remonté), déjà traduit. */
    note: string | null;
    error: string | null;
    value: number;
    min: number;
    max: number;
    /** Valeur mise en mots, avec son unité (`aria-valuetext`). */
    format: (value: number) => string;
    disabled: boolean;
    /** Position tenue pendant le geste. */
    onDraft: (value: number) => void;
    /** Geste validé : relâchement du pointeur ou de la touche. */
    onCommit: (value: number) => void;
};

/**
 * Un curseur de réglage (primitive `slider` de shadcn, Radix), au pas de 1.
 *
 * Le geste est validé **au relâchement** : `onValueCommit` au pointeur ;
 * au clavier, Radix valide à chaque touche, et le relâchement de la touche
 * (`keyup`) — ou la perte du focus — fait seul partir la valeur, pour qu'une
 * touche tenue n'écrive pas à chaque pas (§ 8.3, limiteur `game-write`).
 *
 * La primitive ne transmet aucune prop à sa poignée (`role="slider"`) : son
 * nom, sa description et sa valeur en mots y sont posés après chaque rendu,
 * sans quoi le curseur serait anonyme pour un lecteur d'écran. La racine
 * fait au moins 44 px de haut : toute sa surface déplace la poignée.
 */
function SettingSlider({
    ids,
    label,
    help,
    note,
    error,
    value,
    min,
    max,
    format,
    disabled,
    onDraft,
    onCommit,
}: SettingSliderProps) {
    const rootRef = useRef<HTMLSpanElement>(null);
    const keyboard = useRef<{ active: boolean; value: number | null }>({
        active: false,
        value: null,
    });
    const noteId = `${ids.label}-note`;
    const describedBy = [
        ids.help,
        note === null ? null : noteId,
        error === null ? null : ids.error,
    ]
        .filter((id) => id !== null)
        .join(' ');
    const valueText = format(value);

    useLayoutEffect(() => {
        const thumb = rootRef.current?.querySelector('[role="slider"]');

        if (!(thumb instanceof HTMLElement)) {
            return;
        }

        thumb.setAttribute('aria-labelledby', ids.label);
        thumb.setAttribute('aria-describedby', describedBy);
        thumb.setAttribute('aria-valuetext', valueText);

        if (error === null) {
            thumb.removeAttribute('aria-invalid');
        } else {
            thumb.setAttribute('aria-invalid', 'true');
        }
    });

    const flushKeyboard = (): void => {
        const pendingValue = keyboard.current.value;

        keyboard.current = { active: false, value: null };

        if (pendingValue !== null) {
            onCommit(pendingValue);
        }
    };

    return (
        <div className="flex flex-col gap-1">
            <div className="flex items-baseline justify-between gap-4">
                <span id={ids.label} className="text-base font-medium">
                    {label}
                </span>
                <span aria-hidden="true" className="font-medium tabular-nums">
                    {valueText}
                </span>
            </div>
            <p id={ids.help} className="text-sm text-muted-foreground">
                {help}
            </p>
            <Slider
                ref={rootRef}
                value={[Math.max(min, Math.min(max, value))]}
                min={min}
                max={max}
                step={1}
                disabled={disabled}
                onKeyDown={(event: KeyboardEvent<HTMLSpanElement>) => {
                    if (SLIDER_KEYS.has(event.key)) {
                        keyboard.current.active = true;
                    }
                }}
                onKeyUp={(event: KeyboardEvent<HTMLSpanElement>) => {
                    if (keyboard.current.active && SLIDER_KEYS.has(event.key)) {
                        flushKeyboard();
                    }
                }}
                onBlur={() => {
                    if (keyboard.current.active) {
                        flushKeyboard();
                    }
                }}
                onValueChange={([next]) => {
                    if (next === undefined) {
                        return;
                    }

                    if (keyboard.current.active) {
                        keyboard.current.value = next;
                    }

                    onDraft(next);
                }}
                onValueCommit={([next]) => {
                    if (next !== undefined && !keyboard.current.active) {
                        onCommit(next);
                    }
                }}
                className="min-h-11"
            />
            <div
                aria-hidden="true"
                className="flex justify-between text-xs text-muted-foreground tabular-nums"
            >
                <span>{format(min)}</span>
                <span>{format(max)}</span>
            </div>
            {note !== null && (
                <p id={noteId} className="text-sm text-muted-foreground">
                    {note}
                </p>
            )}
            <FieldError id={ids.error} message={error} />
        </div>
    );
}

type SettingRadioGroupProps = {
    ids: FieldIds;
    label: string;
    help: string;
    error: string | null;
    value: string;
    disabled: boolean;
    onValueChange: (value: string) => void;
    orientation: 'horizontal' | 'vertical';
    options: { value: string; label: string; description: string | null }[];
};

/**
 * Un groupe radio de réglage (primitive `radio-group` de shadcn, Radix) : un
 * seul arrêt de tabulation, les flèches changent la valeur — qui part au
 * geste, comme un clic. La valeur cochée reste celle du serveur.
 */
function SettingRadioGroup({
    ids,
    label,
    help,
    error,
    value,
    disabled,
    onValueChange,
    orientation,
    options,
}: SettingRadioGroupProps) {
    return (
        <div className="flex flex-col gap-1">
            <span id={ids.label} className="text-base font-medium">
                {label}
            </span>
            <p id={ids.help} className="text-sm text-muted-foreground">
                {help}
            </p>
            <RadioGroup
                value={value}
                onValueChange={onValueChange}
                disabled={disabled}
                aria-labelledby={ids.label}
                aria-describedby={
                    error === null ? ids.help : `${ids.help} ${ids.error}`
                }
                aria-invalid={error === null ? undefined : true}
                orientation={orientation}
                className={
                    orientation === 'horizontal'
                        ? 'flex flex-wrap gap-x-4 gap-y-1'
                        : 'flex flex-col gap-1'
                }
            >
                {options.map((option) => {
                    const itemId = `${ids.label}-${option.value}`;
                    const descriptionId = `${itemId}-description`;

                    return (
                        <div
                            key={option.value}
                            className="flex items-start gap-3"
                        >
                            <RadioGroupItem
                                id={itemId}
                                value={option.value}
                                aria-describedby={
                                    option.description === null
                                        ? undefined
                                        : descriptionId
                                }
                                className="mt-3.5"
                            />
                            <div className="flex flex-col">
                                <Label
                                    htmlFor={itemId}
                                    className="flex min-h-11 min-w-11 cursor-pointer items-center text-base font-normal"
                                >
                                    {option.label}
                                </Label>
                                {option.description !== null && (
                                    <p
                                        id={descriptionId}
                                        className="pb-1 text-sm text-muted-foreground"
                                    >
                                        {option.description}
                                    </p>
                                )}
                            </div>
                        </div>
                    );
                })}
            </RadioGroup>
            <FieldError id={ids.error} message={error} />
        </div>
    );
}
