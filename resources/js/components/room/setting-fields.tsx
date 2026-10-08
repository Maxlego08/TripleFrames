import { CircleAlert } from 'lucide-react';
import { useLayoutEffect, useRef } from 'react';
import type { KeyboardEvent } from 'react';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';

/**
 * Les champs de réglage du salon, partagés par l'onglet Simple
 * (`room-settings-form.tsx`, L50-5) et l'onglet Avancé
 * (`advanced-settings-form.tsx`, L50-10) : curseur, groupe radio,
 * interrupteur et message d'erreur. Sortis du formulaire Simple par L50-10
 * pour que les deux onglets les partagent sans import circulaire.
 *
 * Présentation seule : aucune écriture, aucune valeur de jeu, tokens du
 * thème seulement (règle 5). Cibles d'au moins 44 px.
 */

/** Identifiants d'un champ : libellé, aide, erreur. */
export type FieldIds = { label: string; help: string; error: string };

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

/** Message d'erreur d'un champ : icône et texte, jamais la seule couleur. */
export function FieldError({
    id,
    message,
}: {
    id: string;
    message: string | null;
}) {
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
    /** Aide sous le libellé ; `null` pour un curseur d'un groupe déjà décrit. */
    help: string | null;
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
export function SettingSlider({
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
        help === null ? null : ids.help,
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
        thumb.setAttribute('aria-valuetext', valueText);

        if (describedBy === '') {
            thumb.removeAttribute('aria-describedby');
        } else {
            thumb.setAttribute('aria-describedby', describedBy);
        }

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
            {help !== null && (
                <p id={ids.help} className="text-sm text-muted-foreground">
                    {help}
                </p>
            )}
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
export function SettingRadioGroup({
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

type SettingSwitchProps = {
    ids: FieldIds;
    label: string;
    help: string;
    /** Information complémentaire sous l'aide (`B_max` du bonus), déjà traduite. */
    note?: string | null;
    error: string | null;
    checked: boolean;
    disabled: boolean;
    onCheckedChange: (checked: boolean) => void;
};

/**
 * Un interrupteur de réglage (primitive `switch` de shadcn, Radix) : le
 * libellé le nomme, l'aide le décrit ; la valeur part au geste, la position
 * affichée reste celle du serveur.
 */
export function SettingSwitch({
    ids,
    label,
    help,
    note = null,
    error,
    checked,
    disabled,
    onCheckedChange,
}: SettingSwitchProps) {
    const noteId = `${ids.label}-note`;
    const describedBy = [
        ids.help,
        note === null ? null : noteId,
        error === null ? null : ids.error,
    ]
        .filter((id) => id !== null)
        .join(' ');

    return (
        <div className="flex flex-col gap-1">
            <div className="flex items-start justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <Label
                        htmlFor={ids.label}
                        className="flex min-h-11 cursor-pointer items-center text-base"
                    >
                        {label}
                    </Label>
                    <p id={ids.help} className="text-sm text-muted-foreground">
                        {help}
                    </p>
                    {note !== null && (
                        <p
                            id={noteId}
                            className="text-sm text-muted-foreground"
                        >
                            {note}
                        </p>
                    )}
                </div>

                <div className="flex min-h-11 items-center">
                    <Switch
                        id={ids.label}
                        checked={checked}
                        onCheckedChange={onCheckedChange}
                        disabled={disabled}
                        aria-invalid={error === null ? undefined : true}
                        aria-describedby={describedBy}
                    />
                </div>
            </div>

            <FieldError id={ids.error} message={error} />
        </div>
    );
}
