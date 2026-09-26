import { useCallback, useSyncExternalStore } from 'react';
import {
    realtimeStatus,
    subscribeGameChannels,
    subscribeRealtimeStatus,
} from '@/lib/game/echo';
import type {
    GameChannels,
    GameEventListener,
    RealtimeStatus,
} from '@/lib/game/echo';
import type { RealtimeConfig } from '@/types/game-wire';

/**
 * Suit les deux canaux d'un siège (spec 60 § 10.4 et § 10.5) — présence du
 * salon `room.{roomKey}`, privé du siège `seat.{publicId}` — et rend l'état
 * du client temps réel.
 *
 * **Tout passe par `useSyncExternalStore`** : la souscription aux canaux est
 * la fonction `subscribe` du magasin externe, et l'état de connexion son
 * instantané. React la pose au montage, la retire au démontage ou quand les
 * canaux changent, et la rejoue deux fois sous `strictMode` : les
 * souscriptions à compteur de `lib/game/echo.ts` (canal quitté au tour
 * suivant seulement) rendent ce double montage sans effet — ni écoute en
 * double, ni aller-retour d'autorisation. Idempotent sous React Compiler :
 * la souscription ne dépend que de valeurs primitives et de `onEvent`, que
 * l'appelant garde stable (le `receive` du magasin).
 *
 * `channels` nul : aucun canal suivi — solo, ou page qui quitte le salon
 * (`seat.kicked`, `room.archived`, démontage). La page quitte alors ses
 * canaux ; un client non coopératif resté abonné ne reçoit rien qui apprenne
 * la réponse avant la révélation (60 § 18).
 *
 * Ce hook ne lit ni n'écrit l'état du jeu : il relaie chaque événement de la
 * liste close à `onEvent`, qui décide.
 */
export function useGameChannel(
    channels: GameChannels | null,
    realtime: RealtimeConfig,
    onEvent: GameEventListener,
): RealtimeStatus {
    const room = channels?.room ?? null;
    const seat = channels?.seat ?? null;
    const { key, host, port, scheme, heartbeatIntervalMs, clockSamples } =
        realtime;

    const subscribe = useCallback(
        (notify: () => void): (() => void) => {
            const unsubscribeStatus = subscribeRealtimeStatus(notify);
            const unsubscribeChannels =
                room === null || seat === null
                    ? () => undefined
                    : subscribeGameChannels(
                          {
                              key,
                              host,
                              port,
                              scheme,
                              heartbeatIntervalMs,
                              clockSamples,
                          },
                          { room, seat },
                          onEvent,
                      );

            return () => {
                unsubscribeChannels();
                unsubscribeStatus();
            };
        },
        [
            room,
            seat,
            key,
            host,
            port,
            scheme,
            heartbeatIntervalMs,
            clockSamples,
            onEvent,
        ],
    );

    return useSyncExternalStore(subscribe, realtimeStatus, idleStatus);
}

/** Aucun rendu serveur au J1 (C16) ; instantané neutre par principe. */
function idleStatus(): RealtimeStatus {
    return 'idle';
}
