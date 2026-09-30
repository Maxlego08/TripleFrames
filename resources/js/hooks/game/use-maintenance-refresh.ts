import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { subscribeWake } from '@/hooks/game/use-heartbeat';
import { watchMaintenance } from '@/lib/game/maintenance-refresh';

/** La seule prop relue : la prop partagée du drapeau de drainage. */
const MAINTENANCE_PROP = ['maintenance'];

/**
 * Relecture du drapeau de drainage (spec 50 § 8.1, 100 § 11.3 ; BUG-P2) par
 * une page de jeu qui désactive un geste tant que la prop partagée
 * `maintenance` vaut vrai — `game/lobby` (« Lancer la partie »,
 * « Rejouer ») et `game/solo` (relance). La logique vit dans
 * `lib/game/maintenance-refresh.ts`.
 *
 * `enabled` n'est vrai que si la page affiche le drapeau **et** que l'onglet
 * tient le siège (`active`) : le rechargement partiel repasse par le rendu
 * de la page, qui présente `X-Seat-Token` (`useGameState`) et ne frappe
 * donc aucun jeton ; depuis un onglet supplanté, il reprendrait la main
 * (`ClaimSeatTab`, 60 § 12.7). Seule `maintenance` est demandée : ni le
 * paquet ni les réglages ne sont reconstruits, et la page ne bouge pas
 * (`preserveState`). La cadence est `heartbeatIntervalMs` de la prop
 * partagée `realtime`, jamais une valeur du client (règle 2).
 */
export function useMaintenanceRefresh(enabled: boolean): void {
    const { realtime } = usePage().props;
    const intervalMs = realtime.heartbeatIntervalMs;

    useEffect(() => {
        if (!enabled) {
            return undefined;
        }

        return watchMaintenance({
            intervalMs,
            reload: reloadMaintenance,
            subscribeWake,
        });
    }, [enabled, intervalMs]);
}

/** Rechargement partiel de la seule prop `maintenance`. */
function reloadMaintenance(done: () => void): void {
    router.reload({ only: MAINTENANCE_PROP, onFinish: done });
}
