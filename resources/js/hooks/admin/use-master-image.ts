import { useEffect, useState } from 'react';
import { masterHeightFor } from '@/lib/frame-geometry';

/**
 * La source de re-recadrage d'une image, prête à ouvrir dans le recadreur.
 *
 * - `loading` : les octets arrivent ;
 * - `failed` : l'aperçu `master` n'a pas répondu, ou ses octets ne se
 *   décodent pas — « Réessayer » relance ;
 * - `ready` : `url` est une URL d'objet LOCALE, et `masterHeight` la hauteur
 *   du master dans l'espace du recadreur (1920 de large).
 */
export type MasterImage =
    | { status: 'loading' }
    | { status: 'failed' }
    | { status: 'ready'; url: string; masterHeight: number };

/** Issue connue d'un chargement : pour quelle source, à quelle tentative. */
type Settled = {
    source: string;
    attempt: number;
    image: Exclude<MasterImage, { status: 'loading' }>;
};

/**
 * Charge la source de re-recadrage d'une image (spec 20 § 5.7, § 6.3) depuis
 * l'aperçu admin `master` (C9-bis), et en lit la hauteur.
 *
 * Le recadreur tient son cadre dans l'espace du master : il lui faut sa
 * hauteur AVANT de s'ouvrir, et elle n'est pas en base (§ 5.9). Les octets
 * sont donc téléchargés UNE fois, décodés pour la mesurer, puis montrés par
 * une URL d'objet : l'aperçu est servi en `no-store`, et une seconde
 * requête pour l'afficher les téléchargerait deux fois. Aucun canevas, aucun
 * encodage : le serveur relit le cadre et produit le rendu lui-même.
 *
 * L'URL d'objet est révoquée au démontage et à chaque changement de source ;
 * le téléchargement en cours est abandonné. Les effets montés deux fois par
 * `strictMode` se nettoient donc sans fuite.
 */
export function useMasterImage(
    sourceUrl: string | null,
    attempt: number,
): MasterImage {
    const [settled, setSettled] = useState<Settled | null>(null);

    useEffect(() => {
        if (sourceUrl === null) {
            return undefined;
        }

        const controller = new AbortController();
        let objectUrl: string | null = null;

        const settle = (image: Settled['image']): void => {
            if (!controller.signal.aborted) {
                setSettled({ source: sourceUrl, attempt, image });
            }
        };

        void (async () => {
            try {
                const response = await fetch(sourceUrl, {
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!response.ok) {
                    settle({ status: 'failed' });

                    return;
                }

                const blob = await response.blob();
                const bitmap = await createImageBitmap(blob);
                const masterHeight = masterHeightFor(
                    bitmap.width,
                    bitmap.height,
                );

                bitmap.close();

                if (controller.signal.aborted) {
                    return;
                }

                if (masterHeight < 1) {
                    settle({ status: 'failed' });

                    return;
                }

                objectUrl = URL.createObjectURL(blob);
                settle({ status: 'ready', url: objectUrl, masterHeight });
            } catch {
                settle({ status: 'failed' });
            }
        })();

        return () => {
            controller.abort();

            if (objectUrl !== null) {
                URL.revokeObjectURL(objectUrl);
            }
        };
    }, [sourceUrl, attempt]);

    if (
        sourceUrl === null ||
        settled === null ||
        settled.source !== sourceUrl ||
        settled.attempt !== attempt
    ) {
        return { status: 'loading' };
    }

    return settled.image;
}
