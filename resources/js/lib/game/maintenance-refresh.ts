/**
 * La relecture du drapeau de drainage par une page de jeu (spec 50 § 8.1,
 * 100 § 11.3, 90 § 3.3 ; BUG-P2 de la répétition VM du 28/09).
 *
 * Côté joueurs, le drainage se réduit à la prop partagée `maintenance`,
 * relue à chaque réponse Inertia, sans diffusion Reverb au J1. Or
 * « Lancer la partie », « Rejouer » et la relance solo sont **désactivés**
 * tant qu'elle vaut vrai : sur le podium ou l'écran de relance, le geste
 * désactivé était la seule requête qui l'aurait relue, et la page restait
 * bloquée après la levée du drapeau jusqu'à un rechargement manuel.
 *
 * Tant que la page affiche le drapeau — et seulement alors —, elle le relit
 * donc par un rechargement partiel (`only: ['maintenance']`) toutes les
 * `heartbeatIntervalMs` — prop partagée `realtime`, jamais une constante du
 * client — et au réveil de l'onglet (retour de visibilité, retour en ligne).
 * Aucune relecture au démarrage : la prop vient d'être lue avec la page.
 * Aucune relecture ne chevauche la précédente. **Le client ne décide
 * rien** : le refus serveur de tout lancement reste la seule garantie.
 *
 * Fonction pure, sans DOM ni réseau (C18 § 2.4) : `use-maintenance-refresh`
 * l'appelle depuis un effet, dont le nettoyage l'arrête — le double montage
 * de `strictMode` ne laisse courir qu'une cadence.
 */

export type MaintenanceRefreshOptions = {
    /** `heartbeatIntervalMs` de la prop `realtime`. */
    intervalMs: number;
    /**
     * Relit la prop partagée `maintenance` ; appelle `done` à la fin de la
     * relecture, réussie ou non.
     */
    reload: (done: () => void) => void;
    /**
     * S'abonne aux réveils de l'onglet (retour de visibilité, retour en
     * ligne) ; rend le désabonnement.
     */
    subscribeWake: (listener: () => void) => () => void;
};

/** Démarre la relecture du drapeau ; rend son arrêt, définitif. */
export function watchMaintenance(
    options: MaintenanceRefreshOptions,
): () => void {
    const { intervalMs, reload, subscribeWake } = options;
    let inFlight = false;
    let stopped = false;

    const fire = (): void => {
        if (stopped || inFlight) {
            return;
        }

        inFlight = true;
        reload(() => {
            inFlight = false;
        });
    };

    const cadence = setInterval(fire, intervalMs);
    const offWake = subscribeWake(fire);

    return () => {
        stopped = true;
        clearInterval(cadence);
        offWake();
    };
}
