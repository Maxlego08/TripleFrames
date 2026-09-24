import { describe, expect, it } from 'vite-plus/test';
import {
    isInStrip,
    STRIP_SWIPE_MIN_DISTANCE,
    stripAfterSend,
    stripItems,
    stripNeighbour,
    stripPosition,
    swipeDirection,
} from '@/lib/admin/backdrop-strip';
import { shortcutAction } from '@/lib/admin/shortcut-map';
import type { ShortcutContext } from '@/lib/admin/shortcut-map';
import type { AdminBackdrop, FrameLevel } from '@/types/admin';

/*
 * La bande balayable de l'éditeur de la banque (spec 20 § 6.2 et § 6.3, lot
 * L20-11) : les visuels non encore utilisés, dans l'ordre de la grille. Le
 * choix du visuel est une fonction pure, testée sans DOM (C18 § 2.4). Aucune
 * image réelle : des références TMDB inventées.
 */

type BackdropOptions = {
    used?: FrameLevel[];
    refused?: boolean;
};

function backdrop(name: string, options: BackdropOptions = {}): AdminBackdrop {
    return {
        file_path: `/${name}.jpg`,
        width: 3840,
        height: 2160,
        language_neutral: true,
        thumb_url: `w300/${name}.jpg`,
        image_url: `w1280/${name}.jpg`,
        refusal:
            options.refused === true
                ? 'admin.validation.frame_source.dimensions'
                : null,
        used_levels: options.used ?? [],
    };
}

/** Le visuel `filePath` après un envoi : il porte désormais son niveau. */
function used(items: AdminBackdrop[], filePath: string): AdminBackdrop[] {
    return items.map((item): AdminBackdrop =>
        item.file_path === filePath ? { ...item, used_levels: [3] } : item,
    );
}

/**
 * Sept visuels dans l'ordre de la grille : `b` a déjà servi, `d` est trop
 * étroit (proposé désactivé), les autres sont libres. La bande : a, c, e, f, g.
 */
const GRID: AdminBackdrop[] = [
    backdrop('a'),
    backdrop('b', { used: [1, 5] }),
    backdrop('c'),
    backdrop('d', { refused: true }),
    backdrop('e'),
    backdrop('f'),
    backdrop('g'),
];

const CROPPER: ShortcutContext = {
    screen: 'cropper',
    canSend: true,
    busy: false,
};

function path(item: AdminBackdrop | null): string | null {
    return item === null ? null : item.file_path;
}

describe('bande balayable', () => {
    it('ne garde que les visuels non utilisés et ouvrables, dans l’ordre de la grille', () => {
        expect(stripItems(GRID).map((item) => item.file_path)).toEqual([
            '/a.jpg',
            '/c.jpg',
            '/e.jpg',
            '/f.jpg',
            '/g.jpg',
        ]);
        expect(isInStrip(backdrop('x', { used: [2] }))).toBe(false);
        expect(isInStrip(backdrop('x', { refused: true }))).toBe(false);
        expect(stripPosition(GRID, '/e.jpg')).toEqual({ index: 3, count: 5 });
        expect(stripPosition(GRID, '/b.jpg')).toBeNull();
        expect(stripPosition(GRID, null)).toBeNull();
    });

    it('la bande passe au visuel suivant non utilisé après un envoi', () => {
        // Liste lue à l'envoi : le visuel envoyé y paraît encore libre, mais
        // n'est jamais proposé ; un visuel utilisé ou refusé est sauté.
        expect(path(stripAfterSend(GRID, '/a.jpg'))).toBe('/c.jpg');
        expect(path(stripAfterSend(GRID, '/c.jpg'))).toBe('/e.jpg');
        expect(path(stripAfterSend(GRID, '/f.jpg'))).toBe('/g.jpg');

        // Liste rechargée après l'envoi : le visuel envoyé porte son niveau,
        // le suivant est le même.
        expect(path(stripAfterSend(used(GRID, '/c.jpg'), '/c.jpg'))).toBe(
            '/e.jpg',
        );

        // Un visuel déjà utilisé, rouvert depuis la grille pour une autre
        // variante : la bande reprend après lui.
        expect(path(stripAfterSend(GRID, '/b.jpg'))).toBe('/c.jpg');

        // Au bout de la bande, elle reprend depuis le début les visuels
        // sautés.
        expect(path(stripAfterSend(GRID, '/g.jpg'))).toBe('/a.jpg');

        // Envoi après envoi, la bande se vide sans jamais reproposer un
        // visuel envoyé, puis s'épuise.
        let items = GRID;
        let current: string | null = '/a.jpg';
        const opened: string[] = [];

        while (current !== null) {
            opened.push(current);
            const next = stripAfterSend(items, current);
            items = used(items, current);
            current = path(next);
        }

        expect(opened).toEqual([
            '/a.jpg',
            '/c.jpg',
            '/e.jpg',
            '/f.jpg',
            '/g.jpg',
        ]);
        expect(stripAfterSend(items, '/g.jpg')).toBeNull();
        expect(stripItems(items)).toEqual([]);
    });

    it('les touches crochets passent au visuel voisin sans quitter le cadre', () => {
        // `[` et `]` frappés sur le cadre focalisé : un pas dans la bande,
        // jamais un classement, et le cadre reste la cible.
        expect(
            shortcutAction(
                { key: ']', code: 'BracketRight' },
                'frame',
                CROPPER,
            ),
        ).toEqual({ kind: 'neighbour', direction: 'next' });
        expect(
            shortcutAction({ key: '[', code: 'BracketLeft' }, 'frame', CROPPER),
        ).toEqual({ kind: 'neighbour', direction: 'previous' });

        // Sur un clavier AZERTY, AltGr (Ctrl + Alt sous Windows) compose `[`
        // et `]` ; Ctrl seul et Méta restent au navigateur.
        expect(
            shortcutAction(
                { key: '[', code: 'Digit5', ctrlKey: true, altKey: true },
                'frame',
                CROPPER,
            ),
        ).toEqual({ kind: 'neighbour', direction: 'previous' });
        expect(
            shortcutAction(
                { key: ']', code: 'BracketRight', ctrlKey: true },
                'frame',
                CROPPER,
            ),
        ).toBeNull();
        expect(
            shortcutAction(
                { key: '[', code: 'BracketLeft', metaKey: true },
                'frame',
                CROPPER,
            ),
        ).toBeNull();

        // Depuis un contrôle du recadreur ou de la bande aussi, mais jamais
        // pendant un envoi : le cadre ne change pas sous une requête.
        expect(
            shortcutAction(
                { key: ']', code: 'BracketRight' },
                'control',
                CROPPER,
            ),
        ).toEqual({ kind: 'neighbour', direction: 'next' });
        expect(
            shortcutAction({ key: ']', code: 'BracketRight' }, 'frame', {
                screen: 'cropper',
                canSend: true,
                busy: true,
            }),
        ).toBeNull();

        // Le voisin est le visuel libre le plus proche dans l'ordre de la
        // grille : un visuel utilisé ou refusé est sauté.
        expect(path(stripNeighbour(GRID, '/a.jpg', 'next'))).toBe('/c.jpg');
        expect(path(stripNeighbour(GRID, '/c.jpg', 'next'))).toBe('/e.jpg');
        expect(path(stripNeighbour(GRID, '/e.jpg', 'previous'))).toBe('/c.jpg');
        expect(path(stripNeighbour(GRID, '/c.jpg', 'previous'))).toBe('/a.jpg');

        // Un visuel déjà utilisé, ouvert depuis la grille, a ses voisins.
        expect(path(stripNeighbour(GRID, '/b.jpg', 'next'))).toBe('/c.jpg');
        expect(path(stripNeighbour(GRID, '/b.jpg', 'previous'))).toBe('/a.jpg');

        // Sans boucle : au bord, le cadre garde son visuel.
        expect(stripNeighbour(GRID, '/g.jpg', 'next')).toBeNull();
        expect(stripNeighbour(GRID, '/a.jpg', 'previous')).toBeNull();

        // Aucun visuel ouvert : `]` ouvre le premier visuel de la bande.
        expect(path(stripNeighbour(GRID, null, 'next'))).toBe('/a.jpg');
        expect(stripNeighbour(GRID, null, 'previous')).toBeNull();
        expect(stripNeighbour([], null, 'next')).toBeNull();
    });

    it('un glissement horizontal passe au visuel voisin', () => {
        const far = STRIP_SWIPE_MIN_DISTANCE + 10;

        // Vers la gauche, le suivant ; vers la droite, le précédent.
        expect(swipeDirection(-far, 4)).toBe('next');
        expect(swipeDirection(far, -4)).toBe('previous');

        // Trop court : un clic ; plus vertical qu'horizontal : un défilement.
        expect(swipeDirection(-(STRIP_SWIPE_MIN_DISTANCE - 1), 0)).toBeNull();
        expect(swipeDirection(-far, far + 1)).toBeNull();
        expect(swipeDirection(0, 0)).toBeNull();
    });
});
