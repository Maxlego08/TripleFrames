import { describe, expect, it } from 'vite-plus/test';
import { flashNoticeMessage } from '@/hooks/game/use-flash-notice';
import {
    lockOverscroll,
    OVERSCROLL_LOCKED,
    OVERSCROLL_PROPERTY,
} from '@/hooks/game/use-overscroll-lock';
import {
    GAME_VIEWPORT_HEIGHT_PROPERTY,
    holdViewportHeight,
} from '@/hooks/game/use-visual-viewport';
import type {
    StyleTarget,
    ViewportSource,
} from '@/hooks/game/use-visual-viewport';

/*
 * Coquille de jeu (spec 90 § 2.3, contrat C16 § 2.3), lot L90-7 : parties
 * pures des hooks de `GameLayout`. Aucun DOM au jalon 1 (C18 § 2.4) : les
 * hooks ne font qu'appeler ces fonctions depuis un `useLayoutEffect`, avec
 * `window.visualViewport`, `<html>` et `<body>` ; on les joue ici sur des
 * doublures, double montage de `strictMode` compris.
 *
 * Au-delà des intitulés de la spec, qui n'en nomme aucun pour ce lot : le
 * comportement DOM de la coquille est vérifié à la main dans la liste
 * « terminé ».
 */

/** Doublure d'élément : les propriétés de style en ligne, lisibles. */
function styleTarget(): StyleTarget & { props: Map<string, string> } {
    const props = new Map<string, string>();

    return {
        props,
        style: {
            setProperty: (property, value) => {
                props.set(property, value);
            },
            removeProperty: (property) => {
                const previous = props.get(property) ?? '';

                props.delete(property);

                return previous;
            },
        },
    };
}

/**
 * Doublure de `visualViewport` : une hauteur et un zoom réglables, et leurs
 * écouteurs. Comme le vrai (CSSOM View), la hauteur est en pixels CSS de la
 * page zoomée : un pincement ×2 la divise par deux.
 */
function viewport(initial: number): ViewportSource & {
    resize: (height: number) => void;
    zoom: (scale: number) => void;
    listeners: Set<() => void>;
} {
    const listeners = new Set<() => void>();
    let visible = initial;
    let scale = 1;

    const emit = (): void => {
        for (const listener of listeners) {
            listener();
        }
    };

    return {
        listeners,
        get height() {
            return visible / scale;
        },
        get scale() {
            return scale;
        },
        addEventListener: (_type, listener) => {
            listeners.add(listener);
        },
        removeEventListener: (_type, listener) => {
            listeners.delete(listener);
        },
        resize: (next) => {
            visible = next;
            emit();
        },
        zoom: (next) => {
            scale = next;
            emit();
        },
    };
}

describe('hauteur visible de la coquille', () => {
    it('suit la hauteur visible du premier montage au dernier démontage, double montage compris', () => {
        const root = styleTarget();
        const source = viewport(640);

        // `strictMode` : monte, démonte, remonte.
        const first = holdViewportHeight(source, root);

        first();

        const mounted = holdViewportHeight(source, root);

        expect(root.props.get(GAME_VIEWPORT_HEIGHT_PROPERTY)).toBe('640px');
        expect(source.listeners.size).toBe(1);

        // Clavier ouvert : la hauteur visible se réduit, la variable suit.
        source.resize(360.5);

        expect(root.props.get(GAME_VIEWPORT_HEIGHT_PROPERTY)).toBe('360.5px');

        // Une seconde coquille chevauche la première le temps d'une
        // navigation : sa libération ne retire rien.
        const overlapping = holdViewportHeight(source, root);

        overlapping();
        overlapping();

        expect(root.props.get(GAME_VIEWPORT_HEIGHT_PROPERTY)).toBe('360.5px');
        expect(source.listeners.size).toBe(1);

        mounted();

        expect(root.props.has(GAME_VIEWPORT_HEIGHT_PROPERTY)).toBe(false);
        expect(source.listeners.size).toBe(0);
    });

    it('ne rétrécit pas au zoom par pincement, clavier ouvert ou non', () => {
        const root = styleTarget();
        const source = viewport(640);
        const release = holdViewportHeight(source, root);

        // Zoom ×2 : `height` passe à 320, la hauteur visible ne change pas.
        source.zoom(2);

        expect(source.height).toBe(320);
        expect(root.props.get(GAME_VIEWPORT_HEIGHT_PROPERTY)).toBe('640px');

        // Clavier ouvert sous zoom : seule la hauteur visible se réduit.
        source.resize(360);

        expect(root.props.get(GAME_VIEWPORT_HEIGHT_PROPERTY)).toBe('360px');

        source.zoom(1);

        expect(root.props.get(GAME_VIEWPORT_HEIGHT_PROPERTY)).toBe('360px');

        release();
    });

    it("n'écrit rien sans visualViewport, pour retomber sur 100dvh", () => {
        const root = styleTarget();
        const release = holdViewportHeight(null, root);

        expect(root.props.size).toBe(0);

        release();

        expect(root.props.size).toBe(0);
    });
});

describe('verrou du geste de rafraîchissement', () => {
    it('pose overscroll-behavior-y: none sur html et body du premier verrou au dernier', () => {
        const html = styleTarget();
        const body = styleTarget();

        const first = lockOverscroll([html, body]);

        first();

        const mounted = lockOverscroll([html, body]);

        for (const target of [html, body]) {
            expect(target.props.get(OVERSCROLL_PROPERTY)).toBe(
                OVERSCROLL_LOCKED,
            );
        }

        const overlapping = lockOverscroll([html, body]);

        overlapping();
        overlapping();

        expect(html.props.get(OVERSCROLL_PROPERTY)).toBe('none');
        expect(body.props.get(OVERSCROLL_PROPERTY)).toBe('none');

        mounted();

        expect(html.props.has(OVERSCROLL_PROPERTY)).toBe(false);
        expect(body.props.has(OVERSCROLL_PROPERTY)).toBe(false);
    });
});

describe("avis de page expirée d'une page de jeu", () => {
    it('lit le message déjà traduit d’un flash toast, et rien d’autre', () => {
        expect(
            flashNoticeMessage({
                toast: { type: 'error', message: 'La page a expiré.' },
            }),
        ).toBe('La page a expiré.');

        for (const flash of [
            null,
            undefined,
            'toast',
            {},
            { toast: null },
            { toast: 'La page a expiré.' },
            { toast: { type: 'error' } },
            { toast: { type: 'error', message: '   ' } },
            { toast: { type: 'error', message: 42 } },
            { notice: { message: 'La page a expiré.' } },
        ]) {
            expect(flashNoticeMessage(flash)).toBeNull();
        }
    });
});
