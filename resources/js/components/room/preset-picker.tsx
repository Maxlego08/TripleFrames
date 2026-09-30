import type { HttpExceptionResponse } from '@inertiajs/core';
import { Form } from '@inertiajs/react';
import { CircleAlert, TriangleAlert } from 'lucide-react';
import { useId } from 'react';
import RoomPresetController from '@/actions/App/Http/Controllers/Room/RoomPresetController';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import type { TranslationKey } from '@/types/translations';

/** Clé d'un preset du site (`SettingPresetKey`). */
export type PresetKey = 'classic' | 'fast' | 'hardcore' | 'discovery';

/** Aide de grisage d'un preset, calculée au rendu (spec 50 § 5.3). */
export type PresetOption = {
    key: PresetKey;
    grayed: boolean;
    nearestPlayableFramesPerRound: number | null;
};

type PresetPickerProps = {
    /** Code du salon (prop `room.code`), pour Wayfinder. */
    roomCode: string;
    /** Prop `presets`, triée par position, jamais stockée. */
    presets: PresetOption[];
    /**
     * Onglet supplanté, connexion perdue ou siège sorti (§ 8.1, état
     * « déconnexion »). Le serveur relit tout sous verrou de toute façon.
     */
    disabled: boolean;
    /** Intercepte le 409 `seat_superseded` de `seat.active` (§ 8.1). */
    onHttpException: (response: HttpExceptionResponse) => boolean | void;
    /** Un refus, déjà traduit : la page l'annonce — jamais un toast. */
    onRefused: (message: string) => void;
};

/**
 * Libellé et description de chaque preset (`room.presets.<clé>`) : une table
 * de clés littérales, jamais une clé composée (C15 § 2.7). Aucun chiffre
 * dans ces textes : les chiffres s'affichent depuis les réglages rendus,
 * une fois le preset appliqué (§ 5.1). Partagée avec le choix du preset
 * du solo (`solo-preset-field.tsx`, spec 60 § 16.4).
 */
export const PRESET_KEYS: Record<
    PresetKey,
    { label: TranslationKey; description: TranslationKey }
> = {
    classic: {
        label: 'room.presets.classic.label',
        description: 'room.presets.classic.description',
    },
    fast: {
        label: 'room.presets.fast.label',
        description: 'room.presets.fast.description',
    },
    hardcore: {
        label: 'room.presets.hardcore.label',
        description: 'room.presets.hardcore.description',
    },
    discovery: {
        label: 'room.presets.discovery.label',
        description: 'room.presets.discovery.description',
    },
};

/** Premier message d'erreur d'une écriture refusée, déjà traduit. */
function firstError(errors: Partial<Record<string, string>>): string | null {
    return (
        Object.values(errors).find(
            (message): message is string =>
                message !== undefined && message !== '',
        ) ?? null
    );
}

/**
 * Les presets du site (spec 50 § 5), proposés à l'hôte seul (§ 8.1).
 *
 * Un clic applique le preset : `POST room.settings.preset`, corps
 * `{ preset }`, par le `<Form>` d'Inertia — le bouton cliqué porte la clé
 * (`name="preset"`, soumise avec le formulaire). Le serveur pré-remplit les
 * seize champs, thèmes vidés et capacité au plafond, sous le verrou du salon.
 *
 * **Grisage** (§ 5.3) : un preset dont le vivier du salon ne tiendrait pas
 * ses manches est grisé, avec son motif — jouable à `N` images par manche,
 * ou pas du tout. C'est une aide, jamais une interdiction : le serveur
 * accepte un preset grisé, et le lobby montre alors le blocage du vivier et
 * ses remèdes ; la garde de lancement fait seule autorité. Le bouton reste
 * donc actif, son motif lié par `aria-describedby`.
 *
 * États : chargement (`processing`, `aria-busy`, un seul envoi) ; erreur
 * (rendue sous la liste en `Alert` au rôle `note`, annoncée par la page) ;
 * déconnexion (`disabled`). Clavier : boutons natifs d'au moins 44 px.
 * Tokens du thème seulement.
 */
export function PresetPicker({
    roomCode,
    presets,
    disabled,
    onHttpException,
    onRefused,
}: PresetPickerProps) {
    const { t } = useTranslations();
    const titleId = useId();
    const baseId = useId();

    return (
        <section aria-labelledby={titleId} className="flex flex-col gap-3">
            <h2 id={titleId} className="text-lg font-semibold">
                {t('room.lobby.presets_title')}
            </h2>

            <Form
                {...RoomPresetController.store.form({ room: roomCode })}
                options={{ preserveScroll: true, preserveState: true }}
                onHttpException={onHttpException}
                onError={(errors) => {
                    const message = firstError(errors);

                    if (message !== null) {
                        onRefused(message);
                    }
                }}
                className="flex flex-col gap-3"
            >
                {({ processing, errors }) => {
                    const error = firstError(errors);
                    const errorId = `${baseId}-error`;

                    return (
                        <>
                            <ul
                                aria-busy={processing}
                                className="grid gap-2 sm:grid-cols-2"
                            >
                                {presets.map((preset) => (
                                    <li key={preset.key}>
                                        <PresetButton
                                            baseId={`${baseId}-${preset.key}`}
                                            preset={preset}
                                            disabled={processing || disabled}
                                            errorId={
                                                error === null ? null : errorId
                                            }
                                        />
                                    </li>
                                ))}
                            </ul>

                            {error !== null && (
                                <Alert role="note" id={errorId}>
                                    <CircleAlert aria-hidden="true" />
                                    <AlertDescription className="text-foreground">
                                        {error}
                                    </AlertDescription>
                                </Alert>
                            )}
                        </>
                    );
                }}
            </Form>
        </section>
    );
}

type PresetButtonProps = {
    baseId: string;
    preset: PresetOption;
    disabled: boolean;
    /** Erreur de la dernière application, liée au bouton ; `null` sans. */
    errorId: string | null;
};

/**
 * Un preset : un bouton d'envoi qui porte sa clé. Son nom est le libellé
 * seul ; la description, le motif du grisage et l'erreur éventuelle le
 * décrivent (`aria-describedby`). Grisé, il reste actif (§ 5.3) : le motif
 * le dit par une icône et un texte, jamais par la seule couleur.
 */
function PresetButton({
    baseId,
    preset,
    disabled,
    errorId,
}: PresetButtonProps) {
    const { t, locale } = useTranslations();
    const keys = PRESET_KEYS[preset.key];
    const labelId = `${baseId}-label`;
    const descriptionId = `${baseId}-description`;
    const motiveId = `${baseId}-motive`;
    const describedBy = [
        descriptionId,
        preset.grayed ? motiveId : null,
        errorId,
    ]
        .filter((id) => id !== null)
        .join(' ');
    const nearest = preset.nearestPlayableFramesPerRound;
    const motive =
        nearest === null
            ? t('room.lobby.preset_unplayable')
            : t('room.lobby.preset_grayed', {
                  frames: new Intl.NumberFormat(locale).format(nearest),
              });

    return (
        <Button
            type="submit"
            name="preset"
            value={preset.key}
            variant="outline"
            disabled={disabled}
            aria-labelledby={labelId}
            aria-describedby={describedBy}
            data-grayed={preset.grayed ? '' : undefined}
            className="h-auto min-h-11 w-full flex-col items-start gap-1 py-3 text-start whitespace-normal data-[grayed]:text-muted-foreground"
        >
            <span id={labelId} className="font-semibold">
                {t(keys.label)}
            </span>
            <span
                id={descriptionId}
                className="text-sm font-normal text-muted-foreground"
            >
                {t(keys.description)}
            </span>
            {preset.grayed && (
                <span
                    id={motiveId}
                    className="inline-flex items-start gap-1 text-sm font-normal"
                >
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0"
                    />
                    {motive}
                </span>
            )}
        </Button>
    );
}
