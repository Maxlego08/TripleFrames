import { seen } from '@/routes/audience';

/**
 * La preuve JavaScript de la mesure d'audience — spec 100 § 10.12, amendé
 * le 08/10.
 *
 * Le serveur ne compte un chargement complet de page joueur qu'une fois que
 * la page a exécuté son JavaScript **et** a été montrée : ce signal, envoyé
 * une seule fois par chargement, au premier instant où le document est
 * visible (jamais pendant un prérendu ni dans un onglet resté en arrière-plan).
 * Il ne porte rien ; le serveur le relie à ce qu'il a vu par l'empreinte du
 * jour. Les visites Inertia suivantes n'en ont pas besoin. Le back-office
 * n'est jamais mesuré : aucun signal n'en part.
 */
export function sendAudienceSeen(): void {
    if (
        typeof window === 'undefined' ||
        window.location.pathname === '/admin' ||
        window.location.pathname.startsWith('/admin/')
    ) {
        return;
    }

    const send = (): void => {
        const url = seen.url();

        if (navigator.sendBeacon?.(url) === true) {
            return;
        }

        void fetch(url, {
            method: 'POST',
            keepalive: true,
            credentials: 'omit',
        }).catch(() => undefined);
    };

    const isShown = (): boolean =>
        document.visibilityState === 'visible' &&
        (document as Document & { prerendering?: boolean }).prerendering !==
            true;

    if (isShown()) {
        send();

        return;
    }

    const onChange = (): void => {
        if (!isShown()) {
            return;
        }

        document.removeEventListener('visibilitychange', onChange);
        document.removeEventListener('prerenderingchange', onChange);
        send();
    };

    document.addEventListener('visibilitychange', onChange);
    document.addEventListener('prerenderingchange', onChange);
}
