import type {
    FormDataConvertible,
    HttpExceptionResponse,
} from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { CircleAlert, SlidersHorizontal } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { AdvancedActiveBanner } from '@/components/room/advanced-active-banner';
import { AdvancedSettingsForm } from '@/components/room/advanced-settings-form';
import type {
    AdvancedSliderField,
    AdvancedToggle,
    TierList,
} from '@/components/room/advanced-settings-form';
import {
    SettingRadioGroup,
    SettingSlider,
    SettingSwitch,
} from '@/components/room/setting-fields';
import type { FieldIds } from '@/components/room/setting-fields';
import { SettingsWarnings } from '@/components/room/settings-warnings';
import { ReadOnlyNotice } from '@/components/state/read-only-notice';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useIsMobile } from '@/hooks/use-mobile';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import {
    advancedFramesPerRoundChange,
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
    PlatformLimitsPayload,
    RoomSettingsBoundsPayload,
    RoomSettingsState,
    RoomSettingsView,
} from '@/types/room-settings';
import type { TranslationKey } from '@/types/translations';

/** Les champs communs aux deux onglets et `roundDuration` (Simple seul). */
type SimpleField =
    | 'roundsCount'
    | 'framesPerRound'
    | 'roundDuration'
    | 'revealDuration'
    | 'inputDifficulty'
    | 'capacity'
    | 'allowLateJoin';

/** Les champs de l'onglet Simple réglés au curseur (§ 8.1). */
type SliderField =
    | 'roundsCount'
    | 'roundDuration'
    | 'revealDuration'
    | 'capacity';

/** Tout champ entier réglé au curseur, des deux onglets. */
type NumberField = SliderField | AdvancedSliderField;

/** Valeurs en cours de geste, affichées avant la réponse du serveur. */
type Drafts = Partial<Record<NumberField, number>> &
    Partial<Record<TierList, number[]>>;

/** Corps d'une écriture : les seuls champs changés. */
type WriteBody = Record<string, FormDataConvertible>;

/** L'onglet retenu : la valeur de `advanced` en données d'onglet. */
type Tab = 'simple' | 'advanced';

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
 * Champs dont l'erreur se rend sous le champ lui-même quand l'onglet Avancé
 * est affiché ; toute autre erreur se rend sous la section.
 */
const ADVANCED_FIELDS: ReadonlySet<string> = new Set([
    'tierDurations',
    'tierPoints',
    'speedBonus',
    'noRepeatMovies',
    'attemptsPerSecond',
    'attemptsPerRound',
    'maxAnswerLength',
    'disconnectGraceSeconds',
]);

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

function isTab(value: string): value is Tab {
    return value === 'simple' || value === 'advanced';
}

function sameList(left: number[], right: number[]): boolean {
    return (
        left.length === right.length &&
        left.every((value, index) => value === right[index])
    );
}

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
     * `settings.changed` —, tel que le serveur le rend : valeurs,
     * avertissements et réglages avancés actifs.
     */
    state: RoomSettingsState;
    /** Prop `bounds` : bornes de chaque `N`, dérivations et seuils. */
    bounds: RoomSettingsBoundsPayload;
    /** Prop `limits` : `B_max(N)` pour l'aide du bonus et `waiting_pays`. */
    limits: Pick<PlatformLimitsPayload, 'speedBonusMaxPercent'>;
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
     * L'onglet Avancé est-il livré ? Prop `editor.advancedAvailable`
     * (`RoomSettingsEditor::ADVANCED_TAB_AVAILABLE`, vrai depuis L50-10) : le
     * serveur décide de sa présence.
     */
    advancedAvailable: boolean;
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
 * Le formulaire des réglages du salon (spec 50 § 3, § 4 et § 8.1) — onglet
 * Simple du lot L50-5, interrupteur des retardataires de L50-9, onglet
 * Avancé de L50-10.
 *
 * **Deux vues du même objet** (§ 3.3). Les champs communs — nombre de
 * manches, images par manche, révélation, difficulté, places, retardataires —
 * sont rendus au-dessus des onglets ; l'onglet Simple porte la durée d'une
 * manche, découpée à parts égales, et le bandeau des réglages avancés restés
 * actifs (`room.settings.advanced_active`) ; l'onglet Avancé porte la durée
 * et les points de chaque palier, le bonus de rapidité, la non-répétition et
 * les limites de saisie (`AdvancedSettingsForm`). L'onglet affiché est celui
 * du serveur (`advanced`) : en changer est une écriture de l'hôte
 * (`{ advanced: true|false }`), et le retour en Simple réégalise les paliers
 * (`equalized`, rapporté par le serveur). Onglets au motif ARIA tabs,
 * activation manuelle — les flèches déplacent le focus, Entrée ou Espace
 * écrit —, pour qu'un parcours au clavier n'écrive jamais à chaque flèche.
 * Sur mobile, l'onglet Avancé s'ouvre en **feuille plein écran** (`Sheet`,
 * titre et description traduits), ouverte au geste de l'hôte et rouvrable
 * par un bouton ; aucune règle de jeu ne dépend de l'appareil.
 *
 * **L'écriture est immédiate et part au geste** (§ 8.3), par
 * `router.patch()` sur `room.settings.update` (`preserveState`,
 * `preserveScroll`, en-tête `X-Seat-Token` posé par le magasin) : le corps
 * ne porte que le champ changé, sans `advanced` — le serveur l'applique à
 * l'onglet courant et compose le reste avec l'état courant (règle D34 du
 * 23/09 en Simple ; rien n'est dérivé en Avancé). Un curseur envoie **à la
 * validation du geste** (`onValueCommit`) — au relâchement du pointeur, ou de
 * la touche au clavier —, jamais à chaque pas. Une seule écriture à la fois :
 * un geste fait pendant l'envoi attend la réponse, fusionné avec les
 * suivants, puis part seul ; un refus l'abandonne. La valeur affichée reste
 * celle du serveur, relue à la réponse puis diffusée aux autres sièges
 * (`settings.changed`) — seul le curseur tenu montre sa position.
 *
 * **Retour immédiat, le serveur restant seul juge** (§ 4.3,
 * `lib/room-settings.ts`, sans aucun littéral de jeu) :
 * - le curseur de `D` a pour borne basse le **minimum effectif** du `N`
 *   courant (`D ≥ 5 s × N`) ;
 * - quand l'hôte augmente `N` et que `D` tombe sous le nouveau minimum, `D`
 *   est remonté **dans le même envoi** et annoncé
 *   (`room.settings.roundDuration.raised`) ; en Avancé, le même envoi porte
 *   les deux listes redimensionnées (paliers égaux, barème par défaut) ;
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
 * est abandonné, sans envoi ni position tenue). Cibles d'au moins 44 px.
 * Aucune couleur en dur.
 */
export function RoomSettingsForm({
    roomCode,
    state,
    bounds,
    limits,
    headcount,
    editable,
    advancedAvailable,
    lateJoinAvailable,
    disabled,
    onHttpException,
    onRefused,
}: RoomSettingsFormProps) {
    const { t, locale } = useTranslations();
    const isMobile = useIsMobile();
    const [pending, setPending] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [drafts, setDrafts] = useState<Drafts>({});
    const [sheetOpen, setSheetOpen] = useState(false);
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
    const tabsLabelId = `${baseId}-tabs`;
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
    const tab: Tab =
        advancedAvailable && server.advanced ? 'advanced' : 'simple';

    // La feuille ne survit pas à l'onglet Avancé : un retour en Simple (geste
    // de l'hôte, preset, autre siège) la referme, pour qu'un passage ultérieur
    // à l'Avancé par un remède ne la rouvre pas d'office.
    const [previousTab, setPreviousTab] = useState(tab);

    if (tab !== previousTab) {
        setPreviousTab(tab);

        if (tab === 'simple') {
            setSheetOpen(false);
        }
    }
    const frames = server.framesPerRound;
    const forFrames = boundsFor(bounds, frames);
    const serverDuration = roundDuration(server);
    const duration = drafts.roundDuration ?? serverDuration;
    const shown: RoomSettingsView = {
        ...server,
        roundsCount: drafts.roundsCount ?? server.roundsCount,
        revealDuration: drafts.revealDuration ?? server.revealDuration,
        capacity: drafts.capacity ?? server.capacity,
        attemptsPerSecond: drafts.attemptsPerSecond ?? server.attemptsPerSecond,
        attemptsPerRound: drafts.attemptsPerRound ?? server.attemptsPerRound,
        maxAnswerLength: drafts.maxAnswerLength ?? server.maxAnswerLength,
        disconnectGraceSeconds:
            drafts.disconnectGraceSeconds ?? server.disconnectGraceSeconds,
        tierDurations:
            drafts.tierDurations ??
            (drafts.roundDuration === undefined
                ? server.tierDurations
                : defaultTierDurations(bounds, frames, duration)),
        tierPoints: drafts.tierPoints ?? server.tierPoints,
    };
    const shownWarnings =
        Object.keys(drafts).length > 0
            ? warnings(bounds, limits, shown)
            : state.warnings;
    const choicesAt = choicesAtPercent(shown);
    const speedBonusMaxPercent =
        limits.speedBonusMaxPercent[String(frames)] ?? null;

    const count = new Intl.NumberFormat(locale);
    const secondsUnit = new Intl.NumberFormat(locale, {
        style: 'unit',
        unit: 'second',
        unitDisplay: 'short',
    });
    const formatCount = (value: number): string => count.format(value);
    const formatSeconds = (value: number): string => secondsUnit.format(value);

    const fieldIds = (field: SimpleField): FieldIds => ({
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
    const hold = (field: NumberField, value: number, current: number): void => {
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
        field: NumberField,
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

    /** La liste d'un palier, la position `index` remplacée par `value`. */
    const tierListWith = (
        list: TierList,
        index: number,
        value: number,
    ): number[] =>
        (drafts[list] ?? server[list]).map((current, position) =>
            position === index ? value : current,
        );

    /** Position tenue d'un palier : la liste entière, comme elle partira. */
    const holdTier = (list: TierList, index: number, value: number): void => {
        const next = tierListWith(list, index, value);
        const settled = sameList(next, server[list]) && !inFlight.current;

        setDrafts((held) => {
            const updated = { ...held };

            if (settled) {
                delete updated[list];
            } else {
                updated[list] = next;
            }

            return updated;
        });
    };

    /**
     * Validation d'un geste de palier : l'onglet Avancé poste la liste
     * entière (`N` entrées), jamais une entrée isolée — `D` devient sa somme.
     */
    const commitTier = (list: TierList, index: number, value: number): void => {
        if (readOnly) {
            return;
        }

        const next = tierListWith(list, index, value);

        if (sameList(next, server[list]) && !inFlight.current) {
            setDrafts((held) => {
                const updated = { ...held };

                delete updated[list];

                return updated;
            });

            return;
        }

        send({ [list]: next });
    };

    const toggle = (field: AdvancedToggle, value: boolean): void => {
        if (!readOnly && value !== server[field]) {
            send({ [field]: value });
        }
    };

    const changeFrames = (value: string): void => {
        const next = Number(value);

        if (!Number.isInteger(next) || next === frames) {
            return;
        }

        // En Avancé, le client poste les deux listes redimensionnées : le
        // serveur ne dérive rien et refuserait une liste de mauvaise taille.
        const change =
            tab === 'advanced'
                ? advancedFramesPerRoundChange(bounds, shown, next)
                : framesPerRoundChange(bounds, shown, next);

        send(change.body);

        if (change.announcement !== null) {
            const seconds = change.announcement.seconds;

            setRaised({ frames: next, seconds });
            announce(
                t(change.announcement.key, { seconds: count.format(seconds) }),
            );
        }
    };

    /**
     * Changement d'onglet : une écriture de l'hôte (`advanced`). Sur mobile,
     * l'onglet Avancé s'ouvre aussitôt en feuille plein écran.
     */
    const changeTab = (value: string): void => {
        if (!isTab(value) || readOnly) {
            return;
        }

        if (value === 'advanced' && isMobile) {
            setSheetOpen(true);
        }

        if (value !== tab) {
            send({ advanced: value === 'advanced' });
        }
    };

    // Erreurs sans champ affiché (`themeKeys`, une clé avancée hors de
    // l'onglet Avancé…) : rendues sous la section.
    const otherErrors = Object.entries(errors)
        .filter(([field]) => {
            if (Object.hasOwn(FIELD_KEYS, field)) {
                return false;
            }

            const root = field.split('.')[0] ?? field;

            return !(tab === 'advanced' && ADVANCED_FIELDS.has(root));
        })
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

    const roundDurationSlider = renderSlider({
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
    });

    const settingsWarnings = (
        <SettingsWarnings
            warnings={shownWarnings}
            thresholds={bounds.warningThresholds}
        />
    );

    const advancedForm = (
        <AdvancedSettingsForm
            shown={shown}
            server={server}
            bounds={forFrames}
            speedBonusMaxPercent={speedBonusMaxPercent}
            readOnly={readOnly}
            errors={errors}
            onHold={hold}
            onCommit={commit}
            onHoldTier={holdTier}
            onCommitTier={commitTier}
            onToggle={toggle}
        />
    );

    // Sur mobile, l'onglet Avancé vit dans une feuille plein écran (§ 3.3,
    // C16 § 2.9) : le bouton de fermeture anglais de `SheetContent` est
    // masqué et remplacé par une fermeture traduite ; `Échap` ferme aussi.
    const advancedSheet = (
        <>
            <Button
                type="button"
                variant="outline"
                className="min-h-11 self-start"
                onClick={() => setSheetOpen(true)}
            >
                <SlidersHorizontal aria-hidden="true" />
                {t('room.settings.advanced_sheet.title')}
            </Button>

            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent
                    side="bottom"
                    className="h-dvh max-h-dvh motion-reduce:animate-none! [&>button:last-child]:hidden"
                >
                    <SheetHeader className="mx-auto w-full max-w-2xl">
                        <SheetTitle>
                            {t('room.settings.advanced_sheet.title')}
                        </SheetTitle>
                        <SheetDescription>
                            {t('room.settings.advanced_sheet.description')}
                        </SheetDescription>
                    </SheetHeader>

                    <div
                        role="region"
                        aria-label={t('room.settings.advanced_sheet.title')}
                        aria-busy={pending}
                        tabIndex={0}
                        className="mx-auto flex min-h-0 w-full max-w-2xl flex-1 flex-col gap-5 overflow-y-auto px-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {advancedForm}
                        {settingsWarnings}
                    </div>

                    <SheetFooter className="mx-auto w-full max-w-2xl">
                        <SheetClose asChild>
                            <Button variant="outline" className="min-h-11">
                                {t('common.action.close')}
                            </Button>
                        </SheetClose>
                    </SheetFooter>
                </SheetContent>
            </Sheet>
        </>
    );

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
                <SettingSwitch
                    ids={fieldIds('allowLateJoin')}
                    label={t(FIELD_KEYS.allowLateJoin.label)}
                    help={t(FIELD_KEYS.allowLateJoin.help)}
                    error={errorFor('allowLateJoin')}
                    checked={server.allowLateJoin}
                    disabled={readOnly}
                    onCheckedChange={(allowLateJoin) => send({ allowLateJoin })}
                />
            )}

            {advancedAvailable ? (
                <Tabs
                    value={tab}
                    onValueChange={changeTab}
                    activationMode="manual"
                    className="gap-4"
                >
                    <span id={tabsLabelId} className="text-base font-medium">
                        {t('room.settings.advanced.label')}
                    </span>
                    <TabsList
                        aria-labelledby={tabsLabelId}
                        className="h-auto! min-h-11"
                    >
                        <TabsTrigger
                            value="simple"
                            disabled={readOnly}
                            className="min-h-10 px-4"
                        >
                            {t('room.settings.tabs.simple')}
                        </TabsTrigger>
                        <TabsTrigger
                            value="advanced"
                            disabled={readOnly}
                            className="min-h-10 px-4"
                        >
                            {t('room.settings.tabs.advanced')}
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="simple" className="flex flex-col gap-5">
                        {roundDurationSlider}
                        <AdvancedActiveBanner fields={state.advancedActive} />
                    </TabsContent>

                    <TabsContent
                        value="advanced"
                        className="flex flex-col gap-5"
                    >
                        <p className="text-sm text-muted-foreground">
                            {t('room.settings.advanced_sheet.description')}
                        </p>
                        {isMobile ? advancedSheet : advancedForm}
                    </TabsContent>
                </Tabs>
            ) : (
                roundDurationSlider
            )}

            {settingsWarnings}

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
