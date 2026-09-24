import { useSyncExternalStore } from 'react';
import {
    currentAnnouncement,
    subscribeAnnouncements,
} from '@/lib/game/announcer';

/**
 * Numéro de chaque lot remis à la région, par identité : le magasin rend un
 * tableau neuf à chaque remise. Il sert de clé aux `<span>` : un lot dont le
 * texte répète le précédent (« Connexion rétablie. » après une seconde
 * coupure) remplace quand même ses nœuds, et la région est relue — à texte
 * identique, React ne toucherait pas au DOM, et rien ne serait annoncé.
 */
const batchIds = new WeakMap<readonly string[], number>();
let lastBatchId = 0;

function batchId(batch: readonly string[]): number {
    const known = batchIds.get(batch);

    if (known !== undefined) {
        return known;
    }

    lastBatchId += 1;
    batchIds.set(batch, lastBatchId);

    return lastBatchId;
}

/**
 * La **seule** région `aria-live` d'une page de jeu (spec 90 § 7.4, contrat
 * C16 § 2.6 et § 4). Montée par `GameLayout` dès le montage de la coquille,
 * parce qu'une région vivante doit exister avant son premier message ; montée
 * aussi par `PublicLayout`, pour la seule annonce du changement de langue.
 *
 * Un `<span>` par message fusionné, dans l'ordre d'arrivée ; la région est
 * atomique, le lot est lu d'un bloc. Lecture par `useSyncExternalStore` :
 * elle résiste au double montage de `strictMode`, puisque le lot vit dans le
 * magasin de module (`lib/game/announcer.ts`) et non dans un état local.
 *
 * Ce composant ne lit ni Echo ni horloge et n'appelle jamais `t()` : les
 * messages arrivent déjà traduits, par `announce()`.
 */
export function GameAnnouncer() {
    const batch = useSyncExternalStore(
        subscribeAnnouncements,
        currentAnnouncement,
        currentAnnouncement,
    );
    const id = batchId(batch);

    return (
        <div
            role="status"
            aria-live="polite"
            aria-atomic="true"
            className="sr-only"
        >
            {batch.map((message, index) => (
                <span key={`${id}:${index}`} className="block">
                    {message}
                </span>
            ))}
        </div>
    );
}
