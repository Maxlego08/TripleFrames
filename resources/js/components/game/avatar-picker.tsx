import { useId } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { isAvatarPresetKey } from '@/lib/game/avatar-keys';
import { ACCOUNT_AVATAR_CHOICE } from '@/types/player';
import type { AvatarPresetKey, SeatAvatarChoice } from '@/types/player';

/** Une option du sélecteur, déjà traduite par l'appelant. */
export type AvatarPickerOption = {
    key: AvatarPresetKey;
    /** `AvatarPresetOption.url` : fichier statique de `public/avatars/`. */
    url: string;
    /** Libellé du prédéfini (« Hibou »), par `AVATAR_PRESET_LABEL_KEYS`. */
    label: string;
    /** Déjà choisi par un siège tenu du salon : signalé, jamais interdit. */
    taken: boolean;
};

/**
 * L'image du compte (« Mon avatar », spec 40 § 11.4), déjà traduite : offerte
 * en première tuile à un compte qui porte une image visible.
 */
export type AvatarPickerAccountOption = {
    url: string;
    label: string;
};

export type AvatarPickerProps = {
    /** Nom du champ natif, sérialisé tel quel par `<Form>` d'Inertia. */
    name: string;
    options: AvatarPickerOption[];
    /** « Mon avatar », ou `null` : invité, ou compte sans image visible. */
    account?: AvatarPickerAccountOption | null;
    value: SeatAvatarChoice;
    onValueChange: (value: SeatAvatarChoice) => void;
    /** Déjà traduit : `common.avatar.picker.label`. */
    legend: string;
    /** Déjà traduit : `common.avatar.picker.taken`, affiché sous une option prise. */
    takenLabel: string;
};

/**
 * Sélecteur d'avatar prédéfini (spec 40 § 7.4, contrat C5 ; liste close de
 * 90 § 9.2), que 90 place à l'écran.
 *
 * **Aucun appel à `t()`, aucune dépendance à Inertia** : options, légende et
 * mention « déjà choisi » arrivent traduites. Aucun état maison : la primitive
 * `RadioGroup` porte un `name` natif et rend, dans un formulaire, un bouton
 * radio caché par option, que `<Form>` d'Inertia sérialise sans code.
 *
 * - **Un seul arrêt de tabulation, navigation aux flèches** : fournis par la
 *   primitive, qui coche l'option qu'elle focalise.
 * - **Nom accessible d'une option** : son libellé, suivi de `takenLabel` quand
 *   elle est prise (`aria-labelledby` sur les deux textes). L'image est
 *   décorative (I5.9) : elle ne répète pas le libellé.
 * - **Un avatar pris est signalé par texte**, jamais par la seule couleur, et
 *   reste choisissable : le doublon est permis, le pseudo unique par salon
 *   est le discriminant (I5.8).
 * - **L'option cochée se voit sans couleur** : le point de la primitive, en
 *   plus de la bordure au token.
 * - Cibles d'au moins 44 px (`min-h-11 min-w-11`) : la tuile entière est
 *   l'étiquette de son bouton radio.
 */
export function AvatarPicker({
    name,
    options,
    account = null,
    value,
    onValueChange,
    legend,
    takenLabel,
}: AvatarPickerProps) {
    const id = useId();
    const legendId = `${id}-legend`;

    function handleValueChange(next: string): void {
        if (isAvatarPresetKey(next)) {
            onValueChange(next);
        } else if (account !== null && next === ACCOUNT_AVATAR_CHOICE) {
            onValueChange(ACCOUNT_AVATAR_CHOICE);
        }
    }

    const accountId = `${id}-${ACCOUNT_AVATAR_CHOICE}`;

    return (
        <div className="grid gap-3">
            <p id={legendId} className="text-sm font-medium">
                {legend}
            </p>
            <RadioGroup
                name={name}
                value={value}
                onValueChange={handleValueChange}
                aria-labelledby={legendId}
                className="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6"
            >
                {account !== null && (
                    <label
                        htmlFor={accountId}
                        className="relative flex min-h-11 min-w-11 cursor-pointer flex-col items-center gap-1 rounded-md border border-border p-2 text-center has-focus-visible:border-ring has-data-[state=checked]:border-primary has-data-[state=checked]:bg-accent"
                    >
                        <Avatar aria-hidden="true" className="size-12">
                            <AvatarImage
                                src={account.url}
                                alt=""
                                draggable={false}
                            />
                            <AvatarFallback />
                        </Avatar>
                        <span
                            id={`${accountId}-label`}
                            className="text-xs wrap-break-word"
                        >
                            {account.label}
                        </span>
                        <RadioGroupItem
                            id={accountId}
                            value={ACCOUNT_AVATAR_CHOICE}
                            aria-labelledby={`${accountId}-label`}
                        />
                    </label>
                )}
                {options.map((option) => {
                    const itemId = `${id}-${option.key}`;
                    const labelId = `${itemId}-label`;
                    const takenId = `${itemId}-taken`;

                    return (
                        <label
                            key={option.key}
                            htmlFor={itemId}
                            className="relative flex min-h-11 min-w-11 cursor-pointer flex-col items-center gap-1 rounded-md border border-border p-2 text-center has-focus-visible:border-ring has-data-[state=checked]:border-primary has-data-[state=checked]:bg-accent"
                        >
                            <Avatar aria-hidden="true" className="size-12">
                                <AvatarImage
                                    src={option.url}
                                    alt=""
                                    draggable={false}
                                />
                                <AvatarFallback />
                            </Avatar>
                            <span
                                id={labelId}
                                className="text-xs wrap-break-word"
                            >
                                {option.label}
                            </span>
                            {option.taken ? (
                                <span
                                    id={takenId}
                                    className="text-xs text-muted-foreground"
                                >
                                    {takenLabel}
                                </span>
                            ) : null}
                            <RadioGroupItem
                                id={itemId}
                                value={option.key}
                                aria-labelledby={
                                    option.taken
                                        ? `${labelId} ${takenId}`
                                        : labelId
                                }
                            />
                        </label>
                    );
                })}
            </RadioGroup>
        </div>
    );
}
