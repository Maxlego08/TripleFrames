/**
 * Magasin de l'annonceur `aria-live` des écrans de jeu (spec 90 § 7.4,
 * contrat C16 § 2.6).
 *
 * Une page de jeu n'a qu'**une seule** région qui parle, `GameAnnouncer`
 * (C16 § 4) : tout ce qui doit être entendu — seuils de manche, ouverture
 * d'un palier, apparition du QCM, fin de manche, reconnexion, changement de
 * langue, refus du lobby — passe par `announce()`, jamais par un toast ni par
 * une seconde région vivante.
 *
 * **Fusion.** Une fenêtre de `ANNOUNCER_MERGE_WINDOW_MS` s'ouvre à la
 * première annonce en attente ; toute annonce reçue avant sa fermeture la
 * rejoint, et la région reçoit le lot entier à la fermeture, en une seule
 * lecture. La fenêtre est semi-ouverte : une annonce reçue à l'instant exact
 * de la fermeture ouvre la fenêtre suivante. Sans fusion, une manche à la
 * borne basse de `D` et à deux paliers égaux déclencherait mi-manche et
 * changement de palier au même instant, et le second message couperait le
 * premier.
 *
 * Les annonces restent dans le navigateur : rien ne part au serveur, et rien
 * de ce module ne décide quoi que ce soit du jeu (règle 1).
 *
 * Les cinq constantes ci-dessous sont des **constantes de présentation du
 * principe 8**, déclarées dans ce seul fichier (C16 § 5) : elles ne touchent
 * ni palier, ni score, ni chrono, et ne sont donc pas des valeurs de jeu au
 * sens de la règle 2. Les diviseurs et le plafond servent aux seuils relatifs
 * de la chronologie (`round-timeline.ts`, L90-6b).
 */

/** Fenêtre de fusion des annonces, en millisecondes. */
export const ANNOUNCER_MERGE_WINDOW_MS = 1000;

/**
 * Plafond du dernier dixième, en millisecondes : une manche à la borne haute
 * de `D` n'annonce pas ses douze dernières secondes.
 */
export const FINAL_ANNOUNCEMENT_CAP_MS = 5000;

/** Mi-manche : `D − ⌊D / HALFWAY_DIVISOR⌋`. */
export const HALFWAY_DIVISOR = 2;

/** Dernier quart : `D − ⌊D / LAST_QUARTER_DIVISOR⌋`. */
export const LAST_QUARTER_DIVISOR = 4;

/** Dernier dixième : `D − min(⌊D / LAST_TENTH_DIVISOR⌋, plafond)`. */
export const LAST_TENTH_DIVISOR = 10;

/** Lot vide, référence stable : `useSyncExternalStore` compare par identité. */
const EMPTY: readonly string[] = Object.freeze([]);

/** Dernier lot remis à la région, lu par `GameAnnouncer`. */
let current: readonly string[] = EMPTY;

/** Annonces reçues pendant la fenêtre ouverte, dans leur ordre d'arrivée. */
let pending: string[] = [];

/** Fermeture programmée de la fenêtre ; `null` quand aucune n'est ouverte. */
let windowTimer: ReturnType<typeof setTimeout> | null = null;

const listeners = new Set<() => void>();

function notify(): void {
    for (const listener of listeners) {
        listener();
    }
}

/** Ferme la fenêtre et remet le lot entier à la région. */
function flush(): void {
    windowTimer = null;

    if (pending.length === 0) {
        return;
    }

    current = Object.freeze(pending);
    pending = [];
    notify();
}

/**
 * Met un message en attente d'annonce. Le message est **déjà traduit** par
 * l'appelant ; une chaîne vide est ignorée, et un message déjà en attente dans
 * la même fenêtre n'est pas répété.
 */
export function announce(message: string): void {
    const text = message.trim();

    if (text === '') {
        return;
    }

    if (!pending.includes(text)) {
        pending.push(text);
    }

    if (windowTimer === null) {
        windowTimer = setTimeout(flush, ANNOUNCER_MERGE_WINDOW_MS);
    }
}

/**
 * Abonnement aux remises de lot, au format attendu par
 * `useSyncExternalStore` : rend la fonction de désabonnement.
 */
export function subscribeAnnouncements(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

/**
 * Le dernier lot remis à la région : un message par entrée, dans leur ordre
 * d'arrivée. Même référence tant qu'aucun lot nouveau n'est remis.
 */
export function currentAnnouncement(): readonly string[] {
    return current;
}

/**
 * Oublie la fenêtre ouverte, les annonces en attente et le dernier lot. Les
 * abonnés restent inscrits, et sont prévenus si la région se vide.
 */
export function resetAnnouncer(): void {
    if (windowTimer !== null) {
        clearTimeout(windowTimer);
        windowTimer = null;
    }

    pending = [];

    if (current !== EMPTY) {
        current = EMPTY;
        notify();
    }
}
