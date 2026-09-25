import { useHttp } from '@inertiajs/react';
import { useEffect, useEffectEvent, useRef } from 'react';
import {
    afterInput,
    HEARTBEAT_GATE_IDLE,
    HEARTBEAT_INPUT_EVENTS,
    onTick,
} from '@/lib/admin/heartbeat-gate';
import type { HeartbeatGate } from '@/lib/admin/heartbeat-gate';
import { heartbeat } from '@/routes/admin/catalog';

const MS_PER_SECOND = 1000;

/**
 * Le battement de débit d'un écran de film (spec 20 § 10.1) : l'éditeur de la
 * banque, la fiche, la revue d'une de ses images.
 *
 * Toutes les `heartbeatSeconds` secondes (`catalog.curation.heartbeat_seconds`,
 * servi par la page), un tick consulte la porte pure `onTick()` : il poste un
 * battement sur `admin.catalog.heartbeat` si et seulement si la page est
 * visible et qu'une saisie a eu lieu depuis le tick précédent. Le serveur
 * décide du reste — écart, fenêtre d'inactivité, film encore en passe 1.
 *
 * - **Un seul minuteur par page**, posé à l'effet et nettoyé au démontage :
 *   le double montage de `strictMode` en pose deux puis en retire un, jamais
 *   deux qui courent ensemble. Changer de film (la revue passe d'une image à
 *   l'autre) ne le repose pas : le tick lit le film courant.
 * - **Invisible pour le curateur** : un battement refusé — limiteur, film
 *   retiré entre-temps, session expirée, réseau coupé — est abandonné en
 *   silence. Le temps actif est une mesure, jamais un geste ; un toast toutes
 *   les quinze secondes pendant une coupure serait un bruit sans sortie.
 * - `movieId` nul : aucun battement (film retiré, ou aucune image à revoir).
 */
export function useCurationHeartbeat(
    movieId: number | null,
    heartbeatSeconds: number,
): void {
    const { submit } = useHttp();
    const gateRef = useRef<HeartbeatGate>(HEARTBEAT_GATE_IDLE);

    const tick = useEffectEvent((): void => {
        const next = onTick(
            gateRef.current,
            document.visibilityState === 'visible',
        );

        gateRef.current = next.gate;

        if (!next.beat || movieId === null) {
            return;
        }

        submit(heartbeat(movieId)).catch(() => undefined);
    });

    useEffect(() => {
        if (heartbeatSeconds <= 0) {
            return;
        }

        const recordInput = (): void => {
            gateRef.current = afterInput();
        };

        // En phase de capture : une saisie arrêtée plus bas (un geste du
        // recadreur qui empêche la propagation) reste une saisie.
        for (const type of HEARTBEAT_INPUT_EVENTS) {
            window.addEventListener(type, recordInput, {
                capture: true,
                passive: true,
            });
        }

        const timer = window.setInterval(
            () => tick(),
            heartbeatSeconds * MS_PER_SECOND,
        );

        return () => {
            window.clearInterval(timer);

            for (const type of HEARTBEAT_INPUT_EVENTS) {
                window.removeEventListener(type, recordInput, {
                    capture: true,
                });
            }

            gateRef.current = HEARTBEAT_GATE_IDLE;
        };
    }, [heartbeatSeconds]);
}
