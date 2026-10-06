import { router } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import SeatAvatarController from '@/actions/App/Http/Controllers/Room/SeatAvatarController';
import { AvatarPicker } from '@/components/game/avatar-picker';
import { PlayerAvatar } from '@/components/game/player-avatar';
import type { RoomGestureContext } from '@/components/room/seat-actions';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { avatarAltKey } from '@/lib/game/avatar-keys';
import { lobbyAvatarOptions, lobbyAvatarValue } from '@/lib/game/lobby-avatars';
import type { LobbyAvatars } from '@/lib/game/lobby-avatars';
import type { AvatarData, SeatAvatarChoice } from '@/types/player';

/** Champ que `ChangeSeatAvatarRequest` valide, et sous lequel il refuse. */
const AVATAR_FIELD = 'avatar';

/** Les seules props que le sélecteur recharge à son ouverture. */
const RELOADED_PROPS = ['avatars'];

type LobbyAvatarPickerProps = RoomGestureContext & {
    /** Prop `avatars` de la page, servie au seul demandeur. */
    avatars: LobbyAvatars;
    /** L'avatar affiché du siège, tel que le salon le voit (`SeatView`). */
    seatAvatar: AvatarData | null;
    /** Refus du serveur sous `avatar`, déjà traduit (erreur de page). */
    error: string | null;
    /**
     * Empreinte des avatars des autres sièges (`otherSeatsAvatarSignature`) :
     * quand elle change, le sélecteur ouvert relit ses clés prises.
     */
    othersSignature: string;
};

/**
 * « Votre avatar » au lobby (D55 du 02/10 ; spec 50 § 8.1, spec 40 § 11.4) :
 * le siège change son avatar tant qu'aucune partie n'est en cours — la page
 * ne monte ce composant qu'à l'état de lobby, avant le lancement et après
 * « Rejouer » ; le serveur refuse de toute façon en partie et sur le podium
 * (`room.refusal.not_in_lobby`), sous le verrou du salon.
 *
 * - L'avatar actuel, puis « Changer d'avatar » qui déplie le sélecteur
 *   (`aria-expanded`, `aria-controls`). L'ouverture recharge la seule prop
 *   `avatars` (`only`), pour des clés prises fraîches : un autre siège a pu
 *   choisir depuis le rendu ; panneau ouvert, chaque changement d'avatar,
 *   de présence ou d'expulsion d'un autre siège reçu du salon la relit.
 * - Une tuile tenue par un autre siège est désactivée (`AvatarPicker`) ; le
 *   serveur reste juge et refuse une clé prise entre-temps
 *   (`room.lobby.avatar_taken`), message rendu en `Alert` au rôle `note`
 *   sous le sélecteur, et annoncé.
 *   La réponse du refus porte déjà la prop relue : aucune autre requête.
 * - Cocher une tuile ne fait que la sélectionner : la primitive coche
 *   l'option qu'elle focalise aux flèches, un envoi par changement de valeur
 *   enverrait chaque avatar parcouru au clavier. Le choix part au bouton
 *   « Choisir cet avatar » (`router.post`, Wayfinder
 *   `SeatAvatarController.update`), `preserveState` et `preserveScroll`,
 *   un seul envoi en vol ; la valeur relue du serveur fait foi ensuite. Le
 *   salon voit le changement par le `seat.updated` existant.
 * - Les tuiles ne sont jamais désactivées par l'envoi (le focus resterait
 *   sur une tuile désactivée) ; seuls les gestes désactivés (onglet
 *   supplanté, connexion perdue) les désactivent ; le 409 d'un onglet
 *   supplanté passe par `onHttpException`.
 */
export function LobbyAvatarPicker({
    roomCode,
    disabled,
    onHttpException,
    onRefused,
    avatars,
    seatAvatar,
    error,
    othersSignature,
}: LobbyAvatarPickerProps) {
    const { t } = useTranslations();
    const id = useId();
    const titleId = `${id}-title`;
    const panelId = `${id}-panel`;
    const errorId = `${id}-error`;
    const [open, setOpen] = useState(false);
    const [pending, setPending] = useState<SeatAvatarChoice | null>(null);
    // La tuile cochée et pas encore envoyée ; `null` suit le serveur.
    const [selected, setSelected] = useState<SeatAvatarChoice | null>(null);
    const lastSignature = useRef(othersSignature);

    const current = lobbyAvatarValue(avatars);
    const options = lobbyAvatarOptions(avatars, t);
    const target = selected ?? pending;
    const canApply =
        !disabled && pending === null && target !== null && target !== current;

    // Un autre siège a changé d'avatar, est parti ou revenu : panneau
    // ouvert, ses clés prises sont relues. Idempotent au double montage.
    useEffect(() => {
        if (lastSignature.current === othersSignature) {
            return;
        }

        lastSignature.current = othersSignature;

        if (open) {
            router.reload({ only: RELOADED_PROPS, onHttpException });
        }
    }, [othersSignature, open, onHttpException]);

    const toggle = (): void => {
        const next = !open;

        setOpen(next);
        setSelected(null);

        if (next) {
            router.reload({ only: RELOADED_PROPS, onHttpException });
        }
    };

    const apply = (): void => {
        if (!canApply || target === null) {
            return;
        }

        const choice = target;

        setPending(choice);

        router.post(
            SeatAvatarController.update.url({ room: roomCode }),
            { [AVATAR_FIELD]: choice },
            {
                preserveScroll: true,
                preserveState: true,
                onHttpException,
                onError: (failed) => {
                    const message =
                        failed[AVATAR_FIELD] ??
                        Object.values(failed).find((value) => value !== '');

                    if (message !== undefined) {
                        onRefused(message);
                    }
                },
                onFinish: () => {
                    setPending(null);
                    // Une tuile cochée pendant l'envoi reste sélectionnée.
                    setSelected((still) => (still === choice ? null : still));
                },
            },
        );
    };

    return (
        <section
            aria-labelledby={titleId}
            className="flex flex-col gap-3 rounded-md border border-border p-4"
        >
            <div className="flex flex-wrap items-center gap-3">
                {seatAvatar !== null && (
                    <PlayerAvatar
                        avatar={seatAvatar}
                        alt={t(avatarAltKey(seatAvatar.altKey))}
                        className="size-12"
                    />
                )}
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 id={titleId} className="text-sm font-medium">
                        {t('room.lobby.avatar.title')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('room.lobby.avatar.hint')}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    onClick={toggle}
                    aria-expanded={open}
                    aria-controls={panelId}
                    className="min-h-11 w-full sm:w-auto"
                >
                    {open
                        ? t('room.lobby.avatar.done')
                        : t('room.lobby.avatar.change')}
                </Button>
            </div>

            {open && (
                <div
                    id={panelId}
                    aria-busy={pending !== null}
                    aria-describedby={error === null ? undefined : errorId}
                    className="grid gap-2"
                >
                    <AvatarPicker
                        name={AVATAR_FIELD}
                        options={options}
                        account={
                            avatars.account === null
                                ? null
                                : {
                                      url: avatars.account.url,
                                      label: t('common.avatar.picker.account'),
                                  }
                        }
                        value={target ?? current}
                        onValueChange={setSelected}
                        disabled={disabled}
                        legend={t('common.avatar.picker.label')}
                        takenLabel={t('common.avatar.picker.taken')}
                    />
                    <Button
                        type="button"
                        onClick={apply}
                        disabled={!canApply}
                        className="min-h-11 w-full sm:w-auto sm:justify-self-start"
                    >
                        {pending !== null && (
                            <Spinner
                                aria-hidden="true"
                                role="presentation"
                                aria-label={undefined}
                                className="motion-reduce:animate-none"
                            />
                        )}
                        {t('room.lobby.avatar.apply')}
                    </Button>
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
