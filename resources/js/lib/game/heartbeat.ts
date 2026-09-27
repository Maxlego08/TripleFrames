/**
 * Le battement de présence d'un siège, côté client (spec 60 § 13.1, contrat
 * C7 § 2.6) : `room.heartbeat` (ou `solo.heartbeat`, lot L60-16) toutes les
 * `heartbeatIntervalMs` — valeur de la prop partagée `realtime`, jamais une
 * constante du client —, plus immédiatement au montage, au retour de
 * visibilité de l'onglet et au retour en ligne.
 *
 * **Le client ne décide rien** : le serveur écrit `last_seen_at`, ramène un
 * siège déconnecté ou parti à `connected`, reprend une partie en pause et
 * arme le balayage qui, faute de battement, fait passer le siège
 * `disconnected` puis `left`. Un onglet mobile en arrière-plan voit ses
 * minuteries bridées : ses battements manquent, et c'est voulu — le siège
 * sort des participants et ne bloque plus la fin anticipée (§ 13.2). Aucun
 * minuteur n'est donc suspendu à la perte de visibilité.
 *
 * Magasin externe pur, pour `useSyncExternalStore` (`use-heartbeat.ts`) : le
 * premier abonné démarre la cadence, le dernier l'arrête. Le battement du
 * montage part au tour suivant (`setTimeout(…, 0)`) : sous `strictMode`, le
 * double montage abonne, désabonne puis réabonne dans le même tour, et
 * n'envoie qu'un battement. Aucun battement ne chevauche le précédent.
 *
 * Un refus **403** — le jeton ne tient plus de siège ici : expulsion, salon
 * archivé — arrête la cadence pour de bon (`refused`) ; la page se
 * resynchronise alors et quitte le salon. Tout autre échec (réseau, 429,
 * 5xx) est abandonné en silence : le battement suivant réessaie, et le
 * bandeau de connexion dit déjà la coupure.
 */

/** Issue d'un envoi, telle que la transporte la fonction `send`. */
export type HeartbeatSendOutcome = 'ok' | 'refused' | 'failed';

/**
 * `idle` sans abonné ; `beating` tant qu'il bat ; `refused` après un 403,
 * définitif pour ce magasin.
 */
export type HeartbeatStatus = 'idle' | 'beating' | 'refused';

export type HeartbeatOptions = {
    /** URL du battement, produite par Wayfinder. */
    url: string;
    /** `heartbeatIntervalMs` de la prop `realtime`. */
    intervalMs: number;
    /** Envoie un battement ; ne lève jamais. */
    send: (url: string) => Promise<HeartbeatSendOutcome>;
    /**
     * S'abonne aux réveils de l'onglet (retour de visibilité, retour en
     * ligne) ; rend le désabonnement.
     */
    subscribeWake: (listener: () => void) => () => void;
};

export type Heartbeat = {
    subscribe: (listener: () => void) => () => void;
    status: () => HeartbeatStatus;
};

export function createHeartbeat(options: HeartbeatOptions): Heartbeat {
    const { url, intervalMs, send, subscribeWake } = options;
    const listeners = new Set<() => void>();
    let status: HeartbeatStatus = 'idle';
    let inFlight = false;
    let stopCadence: (() => void) | null = null;

    const setStatus = (next: HeartbeatStatus): void => {
        if (next === status) {
            return;
        }

        status = next;

        for (const listener of listeners) {
            listener();
        }
    };

    const stop = (): void => {
        stopCadence?.();
        stopCadence = null;
    };

    const beat = async (): Promise<void> => {
        if (inFlight || status !== 'beating') {
            return;
        }

        inFlight = true;

        try {
            if ((await send(url)) === 'refused') {
                stop();
                setStatus('refused');
            }
        } finally {
            inFlight = false;
        }
    };

    const start = (): void => {
        const fire = (): void => {
            void beat();
        };
        const immediate = setTimeout(fire, 0);
        const cadence = setInterval(fire, intervalMs);
        const offWake = subscribeWake(fire);

        stopCadence = () => {
            clearTimeout(immediate);
            clearInterval(cadence);
            offWake();
        };
    };

    return {
        subscribe(listener: () => void): () => void {
            listeners.add(listener);

            if (listeners.size === 1 && status !== 'refused') {
                start();
                setStatus('beating');
            }

            return () => {
                listeners.delete(listener);

                if (listeners.size === 0 && status === 'beating') {
                    stop();
                    status = 'idle';
                }
            };
        },
        status: () => status,
    };
}
