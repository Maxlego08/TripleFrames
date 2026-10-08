import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import SeatAvatarController from '@/actions/App/Http/Controllers/Room/SeatAvatarController';
import type { RoomGestureContext } from '@/components/room/seat-actions';
import { cycleLobbyAvatar, lobbyAvatarValue } from '@/lib/game/lobby-avatars';
import type { LobbyAvatars } from '@/lib/game/lobby-avatars';
import type { SeatAvatarChoice } from '@/types/player';

/**
 * Attente après le dernier geste avant l'envoi : des clics rapprochés sur
 * les flèches, ou un parcours au clavier de la grille, n'envoient que le
 * dernier choix. Un confort d'interface, pas une valeur de jeu.
 */
const SEND_DELAY_MS = 350;

/** La seule prop que la réponse recharge : le reste de la page ne change pas. */
const RELOADED_PROPS = ['avatars'];

type UseSeatAvatarOptions = {
    roomCode: string;
    avatars: LobbyAvatars;
    onHttpException: RoomGestureContext['onHttpException'];
    onRefused: (message: string) => void;
    /**
     * Le limiteur `game-write` a refusé (429) : trop de changements
     * rapprochés. L'appelant affiche « réessayez dans une minute ».
     */
    onTooManyRequests: () => void;
};

/** HTTP 429 Too Many Requests. */
const HTTP_TOO_MANY_REQUESTS = 429;

/**
 * Le choix d'avatar du siège dans la salle d'attente (D55 du 02/10, amendé
 * le 06/10) — flèches ‹ › et grille du clic sur l'avatar :
 *
 * - **optimiste** : le choix s'affiche aussitôt (`effective`), sans attendre
 *   le serveur ; tant qu'un envoi est dû ou en vol, c'est lui qui s'affiche ;
 * - **regroupé** : l'envoi part {@see SEND_DELAY_MS} après le dernier geste,
 *   un seul en vol, et un choix fait pendant le vol part à la suite ;
 * - **léger** : la réponse ne recharge que la prop `avatars` (`only`), d'où
 *   l'affichage du siège est dérivé ; le salon voit le changement par
 *   `seat.updated`.
 *
 * Le serveur reste juge : une clé prise entre-temps, ou une partie lancée,
 * est refusée, annoncée, et l'affichage revient à la valeur du serveur.
 */
export function useSeatAvatar({
    roomCode,
    avatars,
    onHttpException,
    onRefused,
    onTooManyRequests,
}: UseSeatAvatarOptions): {
    effective: SeatAvatarChoice | null;
    choose: (choice: SeatAvatarChoice) => void;
    cycle: (direction: 1 | -1) => void;
} {
    const [draft, setDraft] = useState<SeatAvatarChoice | null>(null);
    const [syncing, setSyncing] = useState(false);
    const timer = useRef<number | null>(null);
    const inFlight = useRef(false);
    const queued = useRef<SeatAvatarChoice | null>(null);
    const server = lobbyAvatarValue(avatars);
    const effective = syncing && draft !== null ? draft : server;

    // Le minuteur ne survit pas au démontage (double montage de strictMode).
    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    const send = (choice: SeatAvatarChoice): void => {
        if (inFlight.current) {
            queued.current = choice;

            return;
        }

        inFlight.current = true;

        router.post(
            SeatAvatarController.update.url({ room: roomCode }),
            { avatar: choice },
            {
                only: RELOADED_PROPS,
                preserveScroll: true,
                preserveState: true,
                // Un 429 ne mène jamais à la page d'erreur : le choix est
                // abandonné, l'affichage revient au serveur, et l'appelant
                // dit de réessayer dans une minute.
                onHttpException: (response) => {
                    if (response.status === HTTP_TOO_MANY_REQUESTS) {
                        queued.current = null;
                        setDraft(null);
                        onTooManyRequests();

                        return false;
                    }

                    return onHttpException(response);
                },
                onError: (failed) => {
                    const message = Object.values(failed).find(
                        (value) => value !== '',
                    );

                    queued.current = null;
                    setDraft(null);

                    if (message !== undefined) {
                        onRefused(message);
                    }
                },
                onFinish: () => {
                    inFlight.current = false;

                    const next = queued.current;
                    queued.current = null;

                    if (next !== null && next !== choice) {
                        send(next);

                        return;
                    }

                    if (timer.current === null) {
                        setSyncing(false);
                    }
                },
            },
        );
    };

    const choose = (choice: SeatAvatarChoice): void => {
        setDraft(choice);
        setSyncing(true);

        if (timer.current !== null) {
            window.clearTimeout(timer.current);
        }

        timer.current = window.setTimeout(() => {
            timer.current = null;
            send(choice);
        }, SEND_DELAY_MS);
    };

    const cycle = (direction: 1 | -1): void => {
        const target = cycleLobbyAvatar(
            { ...avatars, current: effective ?? avatars.current },
            direction,
        );

        if (target !== null) {
            choose(target);
        }
    };

    return { effective, choose, cycle };
}
