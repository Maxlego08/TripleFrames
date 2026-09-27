import { TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import type { Ref } from 'react';
import { PRESET_KEYS } from '@/components/room/preset-picker';
import type { PresetKey, PresetOption } from '@/components/room/preset-picker';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslations } from '@/hooks/use-translations';

type SoloPresetFieldProps = {
    /**
     * Prop `presets` de la page (spec 60 § 16.4) : les quatre presets dans
     * l'ordre du site, chacun avec son `N` jouable le plus proche sur le
     * vivier catalogue — même forme que les presets du lobby.
     */
    presets: PresetOption[];
    /** Légende du groupe, DÉJÀ traduite (`room.solo.choose_preset`). */
    legend: string;
    /**
     * Refus ou échec du démarrage, DÉJÀ traduit par le serveur (erreur
     * `preset` : preset invalide, drainage, vivier, échec technique), lié au
     * groupe par `aria-describedby` ; `undefined` sans erreur.
     */
    error?: string;
    /** Le groupe, pour que la page y porte le focus après un refus. */
    groupRef?: Ref<HTMLDivElement>;
};

/** Nom du champ, tel que `SoloStartRequest` le valide (§ 16.2). */
const PRESET_FIELD = 'preset';

/**
 * Le preset proposé d'emblée : le premier, dans l'ordre du site, qui se joue
 * — tel quel ou à son `N` jouable le plus proche —, sinon le premier.
 */
function initialPreset(presets: PresetOption[]): PresetKey | null {
    const playable = presets.find(
        (preset) =>
            !preset.grayed || preset.nearestPlayableFramesPerRound !== null,
    );

    return (playable ?? presets[0])?.key ?? null;
}

/**
 * Choix du preset d'une partie solo (spec 60 § 16.3 et § 16.4, D19 du
 * 23/09) : un des quatre presets du site, sans formulaire de réglages.
 *
 * Un groupe radio à nom natif (`preset`), que le `<Form>` d'Inertia de la
 * page sérialise sans code — aucun état de formulaire maison. Chaque option
 * porte son libellé (nom accessible) et sa description ; un preset dont le
 * vivier catalogue ne tient pas les manches à son `N` le dit par une icône
 * et un texte, jamais par la seule couleur : il se joue alors à `N` images
 * par manche, appliqué d'office par le serveur (`room.lobby.preset_grayed`),
 * ou pas du tout (`room.lobby.preset_unplayable`) — il reste choisissable,
 * le serveur refusant et disant pourquoi. Le serveur reste seul juge : il
 * recalcule le vivier au démarrage.
 *
 * Clavier : un seul arrêt de tabulation, flèches entre les options
 * (primitive). Cibles d'au moins 44 px : la tuile entière est l'étiquette
 * de son bouton radio. Tokens du thème seulement.
 */
export function SoloPresetField({
    presets,
    legend,
    error,
    groupRef,
}: SoloPresetFieldProps) {
    const { t, locale } = useTranslations();
    const id = useId();
    const legendId = `${id}-legend`;
    const errorId = `${id}-error`;
    const [value, setValue] = useState<PresetKey | null>(() =>
        initialPreset(presets),
    );

    function handleValueChange(next: string): void {
        const option = presets.find((preset) => preset.key === next);

        if (option !== undefined) {
            setValue(option.key);
        }
    }

    if (value === null) {
        return null;
    }

    const number = new Intl.NumberFormat(locale);

    return (
        <div className="grid gap-3">
            <p id={legendId} className="text-sm font-medium">
                {legend}
            </p>

            <RadioGroup
                ref={groupRef}
                name={PRESET_FIELD}
                value={value}
                onValueChange={handleValueChange}
                aria-labelledby={legendId}
                aria-describedby={error !== undefined ? errorId : undefined}
                aria-invalid={error !== undefined ? true : undefined}
                className="grid gap-2 sm:grid-cols-2"
            >
                {presets.map((preset) => {
                    const keys = PRESET_KEYS[preset.key];
                    const itemId = `${id}-${preset.key}`;
                    const labelId = `${itemId}-label`;
                    const descriptionId = `${itemId}-description`;
                    const motiveId = `${itemId}-motive`;
                    const nearest = preset.nearestPlayableFramesPerRound;

                    return (
                        <label
                            key={preset.key}
                            htmlFor={itemId}
                            className="flex min-h-11 cursor-pointer items-start gap-3 rounded-md border border-border p-3 has-focus-visible:border-ring has-data-[state=checked]:border-primary has-data-[state=checked]:bg-accent"
                        >
                            <RadioGroupItem
                                id={itemId}
                                value={preset.key}
                                aria-labelledby={labelId}
                                aria-describedby={[
                                    descriptionId,
                                    preset.grayed ? motiveId : null,
                                    error !== undefined ? errorId : null,
                                ]
                                    .filter((ref) => ref !== null)
                                    .join(' ')}
                                className="mt-0.5"
                            />
                            <span className="flex flex-col gap-1">
                                <span id={labelId} className="font-semibold">
                                    {t(keys.label)}
                                </span>
                                <span
                                    id={descriptionId}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t(keys.description)}
                                </span>
                                {preset.grayed && (
                                    <span
                                        id={motiveId}
                                        className="inline-flex items-start gap-1 text-sm"
                                    >
                                        <TriangleAlert
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 shrink-0"
                                        />
                                        {nearest === null
                                            ? t('room.lobby.preset_unplayable')
                                            : t('room.lobby.preset_grayed', {
                                                  frames: number.format(
                                                      nearest,
                                                  ),
                                              })}
                                    </span>
                                )}
                            </span>
                        </label>
                    );
                })}
            </RadioGroup>

            {error !== undefined && (
                <p id={errorId} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
