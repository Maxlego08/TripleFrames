import { http, usePage } from '@inertiajs/react';
import { useMemo, useSyncExternalStore } from 'react';
import { createHeartbeat } from '@/lib/game/heartbeat';
import type {
    Heartbeat,
    HeartbeatSendOutcome,
    HeartbeatStatus,
} from '@/lib/game/heartbeat';

/**
 * Le battement de présence d'une page de jeu (spec 60 § 13.1, contrat C7
 * § 2.6) : `url` est `room.heartbeat` du salon (`game/lobby`, du lobby au
 * podium) ou `solo.heartbeat` (`game/solo`, lot L60-16), produite par
 * Wayfinder ; `null` n'envoie rien — page qui quitte le salon (`seat.kicked`,
 * `room.archived`, 403 de resynchronisation). La cadence est
 * `heartbeatIntervalMs` de la prop partagée `realtime`, jamais une valeur du
 * client (règle 2) ; la logique vit dans `lib/game/heartbeat.ts`.
 *
 * **Tout passe par `useSyncExternalStore`** : l'abonnement démarre la
 * cadence, le désabonnement l'arrête, et React le rejoue sous `strictMode`
 * sans jamais laisser deux minuteurs courir ni envoyer deux battements au
 * montage. Idempotent sous React Compiler : le magasin ne dépend que de
 * l'URL et de la cadence.
 *
 * Rend l'état du battement : `refused` après un 403 — le jeton ne tient plus
 * de siège ici —, que la page traduit en resynchronisation.
 *
 * Le battement ne porte pas `seat.active` : la présence est celle du SIÈGE.
 * C'est la page qui passe `null` quand l'onglet ne doit plus écrire — onglet
 * supplanté (§ 12.7), départ en cours. Il n'a pas de corps ; le jeton de
 * CSRF part par le client HTTP d'Inertia.
 */
export function useHeartbeat(url: string | null): HeartbeatStatus {
    const { realtime } = usePage().props;
    const intervalMs = realtime.heartbeatIntervalMs;

    const heartbeat = useMemo<Heartbeat | null>(
        () =>
            url === null
                ? null
                : createHeartbeat({
                      url,
                      intervalMs,
                      send: sendHeartbeat,
                      subscribeWake,
                  }),
        [url, intervalMs],
    );

    return useSyncExternalStore(
        heartbeat?.subscribe ?? subscribeNothing,
        heartbeat?.status ?? idleStatus,
        idleStatus,
    );
}

/** HTTP 403 Forbidden : plus de siège tenu par ce jeton ici. */
const HTTP_FORBIDDEN = 403;

/** Un battement, par le client HTTP d'Inertia (en-tête XSRF compris). */
async function sendHeartbeat(url: string): Promise<HeartbeatSendOutcome> {
    try {
        await http.getClient().request({
            method: 'post',
            url,
            headers: { Accept: 'application/json' },
        });

        return 'ok';
    } catch (error) {
        return statusOf(error) === HTTP_FORBIDDEN ? 'refused' : 'failed';
    }
}

/** Le statut HTTP d'une erreur du client d'Inertia, ou `null`. */
function statusOf(error: unknown): number | null {
    if (typeof error !== 'object' || error === null || !('response' in error)) {
        return null;
    }

    const response = (error as { response?: { status?: unknown } }).response;

    return typeof response?.status === 'number' ? response.status : null;
}

/**
 * Réveils de l'onglet : retour de visibilité, retour en ligne. Partagés avec
 * la relecture du drapeau de drainage (`use-maintenance-refresh`).
 */
export function subscribeWake(listener: () => void): () => void {
    const onVisibility = (): void => {
        if (document.visibilityState === 'visible') {
            listener();
        }
    };

    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('online', listener);

    return () => {
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('online', listener);
    };
}

function subscribeNothing(): () => void {
    return () => undefined;
}

/** Aucun rendu serveur au J1 (C16) ; instantané neutre sans battement. */
function idleStatus(): HeartbeatStatus {
    return 'idle';
}
