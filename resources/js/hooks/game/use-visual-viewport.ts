import { useLayoutEffect } from 'react';

/**
 * Variable CSS tenue par ce hook sur `document.documentElement`, lue par la
 * racine de `GameLayout` (`h-[var(--game-viewport-height,100dvh)]`).
 */
export const GAME_VIEWPORT_HEIGHT_PROPERTY = '--game-viewport-height';

/**
 * Ce que le hook lit de `window.visualViewport` : sa hauteur visible, son
 * facteur de zoom et son événement `resize`. Forme minimale, pour qu'un test
 * sans DOM puisse jouer le compteur avec une doublure.
 */
export type ViewportSource = {
    readonly height: number;
    readonly scale: number;
    addEventListener(type: 'resize', listener: () => void): void;
    removeEventListener(type: 'resize', listener: () => void): void;
};

/** Ce que le hook écrit : une propriété de style de la racine du document. */
export type StyleTarget = {
    readonly style: {
        setProperty(property: string, value: string): void;
        removeProperty(property: string): string;
    };
};

/** Nombre de montages en cours, au niveau MODULE (double montage). */
let holders = 0;

/** Arrêt du suivi en cours ; `null` quand personne ne tient la variable. */
let stopTracking: (() => void) | null = null;

function track(viewport: ViewportSource | null, root: StyleTarget): () => void {
    // Sans `visualViewport`, rien n'est écrit : la coquille retombe sur
    // `100dvh`, jamais sur `100vh`, qui compte la barre d'adresse (principe 5).
    if (viewport === null) {
        return () => undefined;
    }

    // `height` est en pixels CSS de la page ZOOMÉE : un pincement ×2 la
    // divise par deux sans que rien ne se ferme. Multipliée par `scale`, elle
    // redevient la hauteur visible en pixels non zoomés — réduite par le
    // clavier, jamais par le zoom, qui doit grossir la coquille et non la
    // retasser dans la moitié haute (principe 8).
    const write = (): void => {
        root.style.setProperty(
            GAME_VIEWPORT_HEIGHT_PROPERTY,
            `${viewport.height * viewport.scale}px`,
        );
    };

    write();
    viewport.addEventListener('resize', write);

    return () => {
        viewport.removeEventListener('resize', write);
        root.style.removeProperty(GAME_VIEWPORT_HEIGHT_PROPERTY);
    };
}

/**
 * Prend la variable pour un montage ; rend sa libération, sans effet au-delà
 * du premier appel. Le suivi commence au premier montage et s'arrête au
 * dernier démontage réel : `strictMode` monte deux fois en développement
 * (monte → démonte → remonte), et deux coquilles peuvent se chevaucher le
 * temps d'une navigation.
 */
export function holdViewportHeight(
    viewport: ViewportSource | null,
    root: StyleTarget,
): () => void {
    holders += 1;

    if (holders === 1) {
        stopTracking = track(viewport, root);
    }

    let released = false;

    return () => {
        if (released) {
            return;
        }

        released = true;
        holders -= 1;

        if (holders === 0) {
            stopTracking?.();
            stopTracking = null;
        }
    };
}

/**
 * Hauteur visible de l'écran de jeu (spec 90 § 2.3, contrat C16 § 2.3).
 *
 * Tient `--game-viewport-height` à la hauteur de `window.visualViewport`,
 * ramenée à l'échelle 1 (`height × scale`) — la surface réellement visible,
 * **clavier ouvert compris**, zoom par pincement exclu : c'est sur elle
 * que se mesure le « 40 % de hauteur » de l'image (principe 5, D5 du 23/09),
 * au viewport minimal déclaré de 360 × 640. La variable est retirée au dernier
 * démontage, et la coquille retombe alors sur `100dvh`.
 *
 * C'est la seule valeur mesurée en pixels que le socle écrit ; elle n'est pas
 * une valeur de design, et n'entre dans aucune règle de jeu.
 *
 * `useLayoutEffect` : la hauteur est posée avant la peinture, sans une frame
 * à la hauteur de repli. Aucun SSR en v1.
 */
export function useVisualViewport(): void {
    useLayoutEffect(
        () =>
            holdViewportHeight(
                window.visualViewport ?? null,
                document.documentElement,
            ),
        [],
    );
}
