import type {
    FormDataConvertible,
    HttpExceptionResponse,
} from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useTranslations } from '@/hooks/use-translations';
import { update } from '@/routes/room/settings';
import type { RoomSettingsView } from '@/types/room-settings';

type RoomSettingsFormProps = {
    /** Code du salon (prop `room.code`), pour Wayfinder. */
    roomCode: string;
    /** Les réglages du salon (`settings.settings`), tels que le serveur les rend. */
    settings: RoomSettingsView;
    /**
     * Le siège est l'hôte : lui seul règle (§ 8.1) ; les autres voient les
     * mêmes réglages en lecture seule — la page le dit (`room.lobby.read_only`).
     */
    editable: boolean;
    /**
     * L'interrupteur `allowLateJoin` est-il proposé ? Prop
     * `editor.lateJoinAvailable` de la page (§ 8.1) — `true` au J1 comme au
     * J2 (D35 du 23/09, § 15.4) : le serveur décide de sa présence.
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

/** Premier message d'erreur d'une écriture refusée, déjà traduit. */
function firstError(errors: Record<string, string>): string | null {
    return Object.values(errors).find((message) => message !== '') ?? null;
}

/**
 * Le formulaire des réglages du salon, onglet Simple (spec 50 § 3, § 8.1).
 *
 * Livré avec son premier champ par le lot des retardataires (L50-9) :
 * l'interrupteur `allowLateJoin` (§ 15.4, D35 du 23/09 — réglable par l'hôte
 * au J1 comme tout réglage Simple, défaut `false`). Les autres champs de
 * l'onglet Simple, les presets et le rapport de changements s'y ajoutent
 * avec leur lot (L50-5).
 *
 * L'écriture est immédiate et part au geste (`router.patch()` sur
 * `room.settings.update`, `preserveState`, `preserveScroll`), comme les
 * remèdes du vivier : la valeur affichée reste celle du serveur, relue à la
 * réponse puis diffusée aux autres sièges (`settings.changed`) — jamais une
 * valeur optimiste. Le serveur relit l'autorité d'hôte, le statut et les
 * bornes sous le verrou du salon.
 *
 * États : chargement (`aria-busy`, jamais un second envoi, focus gardé) ; erreur (message rendu sous le champ en `Alert` au rôle `note`,
 * lié par `aria-describedby`, et annoncé par la page) ; déconnexion et
 * onglet supplanté (`disabled`). Clavier : l'interrupteur est un bouton
 * natif (Espace, Entrée) ; son libellé, d'au moins 44 px de haut, le bascule
 * aussi. Aucune couleur en dur : jetons du thème seulement.
 */
export function RoomSettingsForm({
    roomCode,
    settings,
    editable,
    lateJoinAvailable,
    disabled,
    onHttpException,
    onRefused,
}: RoomSettingsFormProps) {
    const { t } = useTranslations();
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const titleId = useId();
    const lateJoinId = useId();
    const lateJoinHelpId = useId();
    const errorId = useId();

    const write = (body: Record<string, FormDataConvertible>): void => {
        router.patch(update.url({ room: roomCode }), body, {
            preserveScroll: true,
            preserveState: true,
            onHttpException,
            onStart: () => {
                setPending(true);
                setError(null);
            },
            onError: (errors) => {
                const message = firstError(errors);

                setError(message);

                if (message !== null) {
                    onRefused(message);
                }
            },
            onFinish: () => setPending(false),
        });
    };

    return (
        <section aria-labelledby={titleId} className="flex flex-col gap-3">
            <h2 id={titleId} className="text-lg font-semibold">
                {t('room.lobby.settings_title')}
            </h2>

            {lateJoinAvailable && (
                <div className="flex items-start justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <Label
                            htmlFor={lateJoinId}
                            className="flex min-h-11 cursor-pointer items-center text-base"
                        >
                            {t('room.settings.allowLateJoin.label')}
                        </Label>
                        <p
                            id={lateJoinHelpId}
                            className="text-sm text-muted-foreground"
                        >
                            {t('room.settings.allowLateJoin.help')}
                        </p>
                    </div>

                    <div className="flex min-h-11 items-center">
                        <Switch
                            id={lateJoinId}
                            checked={settings.allowLateJoin}
                            onCheckedChange={(allowLateJoin) => {
                                // Un seul envoi à la fois ; l'interrupteur
                                // garde le focus (un bouton désactivé le
                                // perdrait).
                                if (!pending) {
                                    write({ allowLateJoin });
                                }
                            }}
                            disabled={!editable || disabled}
                            aria-busy={pending}
                            aria-describedby={
                                error !== null
                                    ? `${lateJoinHelpId} ${errorId}`
                                    : lateJoinHelpId
                            }
                        />
                    </div>
                </div>
            )}

            {error !== null && (
                <Alert role="note" id={errorId}>
                    <CircleAlert aria-hidden="true" />
                    <AlertDescription className="text-foreground">
                        {error}
                    </AlertDescription>
                </Alert>
            )}
        </section>
    );
}
