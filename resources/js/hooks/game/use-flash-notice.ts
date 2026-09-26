import { router } from '@inertiajs/react';
import { useLayoutEffect, useState } from 'react';
import { announce } from '@/lib/game/announcer';

/**
 * Le message d'un flash `toast`, s'il en porte un : texte non vide, déjà
 * résolu côté serveur (destinataire unique, spec 05 § Erreurs). Toute autre
 * forme est ignorée.
 */
export function flashNoticeMessage(flash: unknown): string | null {
    if (typeof flash !== 'object' || flash === null) {
        return null;
    }

    const payload: unknown = Reflect.get(flash, 'toast');

    if (typeof payload !== 'object' || payload === null) {
        return null;
    }

    const message: unknown = Reflect.get(payload, 'message');

    return typeof message === 'string' && message.trim() !== ''
        ? message
        : null;
}

/**
 * Le seul flash qu'une page de jeu subit : l'expiration de page (419 sur une
 * visite Inertia, spec 90 § 4.8), que le serveur renvoie en flash `toast`
 * vers la page précédente. Aucun contrôleur de jeu ne pose de flash.
 *
 * Sur une page de jeu, il n'y a **aucun** `Toaster` (une seule région vivante,
 * C16 § 4) : `GameLayout` lit ce hook et rend le message en texte, dans un
 * `Alert` au rôle `note`, jusqu'à la visite suivante ; le hook l'annonce par
 * `announce()`, une fois, à sa réception. Le message reste visible sans créer
 * de seconde région.
 *
 * Même événement que `hooks/use-flash-toast.ts` (`router.on('flash')`). Le
 * message est effacé au début de toute visite Inertia suivante
 * (`router.on('start')`) : le flash d'une visite arrive toujours après son
 * propre début. Abonnement en `useLayoutEffect`, posé pendant le commit : un
 * flash émis juste après l'échange de page trouve son écouteur.
 */
export function useFlashNotice(): string | null {
    const [notice, setNotice] = useState<string | null>(null);

    useLayoutEffect(() => {
        const stopStart = router.on('start', () => {
            setNotice(null);
        });

        const stopFlash = router.on('flash', (event) => {
            const message = flashNoticeMessage(event.detail.flash);

            if (message === null) {
                return;
            }

            setNotice(message);
            announce(message);
        });

        return () => {
            stopStart();
            stopFlash();
        };
    }, []);

    return notice;
}
