import { describe, expect, it } from 'vite-plus/test';
import { shortcutAction, shortcutTargetOf } from '@/lib/admin/shortcut-map';
import type {
    ShortcutContext,
    ShortcutKeyInput,
} from '@/lib/admin/shortcut-map';
import type { FrameLevel } from '@/types/admin';

/*
 * Raccourcis de débit du back-office (spec 20 § 6.4, lot L20-11) : la
 * correspondance (touche, cible, contexte) → action est une fonction pure,
 * testée sans environnement DOM (C18 § 2.4). La cible est un paramètre : la
 * description de l'élément focalisé, jamais l'élément lui-même.
 */

const CROPPER: ShortcutContext = {
    screen: 'cropper',
    canSend: true,
    busy: false,
};

const REVIEW: ShortcutContext = {
    screen: 'review',
    choosingFailures: false,
    busy: false,
};

const LEVELS: FrameLevel[] = [1, 2, 3, 4, 5];

const CONTEXTS: ShortcutContext[] = [
    CROPPER,
    { screen: 'cropper', canSend: false, busy: false },
    REVIEW,
];

/** Toutes les touches qui portent un raccourci, sous leurs formes courantes. */
const SHORTCUT_KEYS: ShortcutKeyInput[] = [
    ...LEVELS.map((level) => ({ key: String(level), code: `Digit${level}` })),
    ...LEVELS.map((level) => ({ key: String(level), code: `Numpad${level}` })),
    { key: '&', code: 'Digit1' },
    { key: '"', code: 'Digit3' },
    { key: '[', code: 'BracketLeft' },
    { key: ']', code: 'BracketRight' },
    { key: '[', code: 'Digit5', ctrlKey: true, altKey: true },
    { key: 'Enter', code: 'Enter' },
    { key: 'Enter', code: 'NumpadEnter' },
];

describe('shortcutAction', () => {
    it('les touches 1 à 5 classent et envoient depuis le cadre', () => {
        for (const level of LEVELS) {
            // Rangée principale, pavé numérique, et Maj + chiffre sur AZERTY.
            expect(
                shortcutAction(
                    { key: String(level), code: `Digit${level}` },
                    'frame',
                    CROPPER,
                ),
            ).toEqual({ kind: 'classify', level, send: true });
            expect(
                shortcutAction(
                    { key: String(level), code: `Numpad${level}` },
                    'frame',
                    CROPPER,
                ),
            ).toEqual({ kind: 'classify', level, send: true });
            expect(
                shortcutAction(
                    {
                        key: String(level),
                        code: `Digit${level}`,
                        shiftKey: true,
                    },
                    'frame',
                    CROPPER,
                ),
            ).toEqual({ kind: 'classify', level, send: true });
        }

        // AZERTY sans Maj : la touche du 1 rend `&`, celle du 5 `(` — la
        // position physique garde au raccourci son chiffre.
        expect(
            shortcutAction({ key: '&', code: 'Digit1' }, 'frame', CROPPER),
        ).toEqual({ kind: 'classify', level: 1, send: true });
        expect(
            shortcutAction({ key: '(', code: 'Digit5' }, 'frame', CROPPER),
        ).toEqual({ kind: 'classify', level: 5, send: true });

        // Un cadre hors plancher reçoit le niveau, jamais l'envoi.
        expect(
            shortcutAction({ key: '3', code: 'Digit3' }, 'frame', {
                screen: 'cropper',
                canSend: false,
                busy: false,
            }),
        ).toEqual({ kind: 'classify', level: 3, send: false });

        // Depuis le cadre seulement : ni le groupe de niveaux, ni un bouton,
        // ni un cadre encore en chargement (décrit comme une surface).
        for (const target of ['control', 'surface'] as const) {
            expect(
                shortcutAction({ key: '3', code: 'Digit3' }, target, CROPPER),
            ).toBeNull();
        }

        // Aucun autre chiffre, aucune combinaison, aucune répétition, et
        // jamais pendant un envoi.
        expect(
            shortcutAction({ key: '6', code: 'Digit6' }, 'frame', CROPPER),
        ).toBeNull();
        expect(
            shortcutAction({ key: '0', code: 'Digit0' }, 'frame', CROPPER),
        ).toBeNull();
        expect(
            shortcutAction(
                { key: '2', code: 'Digit2', ctrlKey: true },
                'frame',
                CROPPER,
            ),
        ).toBeNull();
        expect(
            shortcutAction(
                { key: '2', code: 'Digit2', metaKey: true },
                'frame',
                CROPPER,
            ),
        ).toBeNull();
        expect(
            shortcutAction(
                { key: '2', code: 'Digit2', repeat: true },
                'frame',
                CROPPER,
            ),
        ).toBeNull();
        expect(
            shortcutAction({ key: '2', code: 'Digit2' }, 'frame', {
                screen: 'cropper',
                canSend: true,
                busy: true,
            }),
        ).toBeNull();

        // Pavé numérique verrouillé : la touche du 1 rend Fin, qui n'est pas
        // un chiffre.
        expect(
            shortcutAction({ key: 'End', code: 'Numpad1' }, 'frame', CROPPER),
        ).toBeNull();

        // Une touche que le recadreur tient pour un geste reste ce geste :
        // sur un clavier tchèque, la touche du 1 rend `+`, qui élargit.
        expect(
            shortcutAction({ key: '+', code: 'Digit1' }, 'frame', CROPPER),
        ).toBeNull();

        // `Entrée` ne publie jamais depuis le recadreur (n° 2, A-24).
        expect(
            shortcutAction({ key: 'Enter', code: 'Enter' }, 'frame', CROPPER),
        ).toBeNull();
    });

    it("aucun raccourci n'agit dans un champ de saisie", () => {
        // La fonction pure, pour toute touche et tout contexte.
        for (const input of SHORTCUT_KEYS) {
            for (const context of CONTEXTS) {
                expect(shortcutAction(input, 'editable', context)).toBeNull();
            }
        }

        // La cible se lit sur la description de l'élément, sans DOM : tout
        // champ de saisie est `editable`, même marqué comme le cadre.
        const fields = [
            { tagName: 'INPUT', type: 'text' },
            { tagName: 'input', type: 'search' },
            { tagName: 'INPUT', type: 'number' },
            { tagName: 'INPUT', type: 'email' },
            { tagName: 'INPUT', type: '' },
            { tagName: 'INPUT', type: null },
            { tagName: 'INPUT', type: 'datetime-local' },
            { tagName: 'TEXTAREA' },
            { tagName: 'SELECT' },
            { tagName: 'DIV', isContentEditable: true },
            { tagName: 'DIV', role: 'textbox' },
            { tagName: 'DIV', role: 'combobox' },
            { tagName: 'SPAN', role: 'searchbox' },
            { tagName: 'INPUT', type: 'text', frame: 'ready' as const },
            {
                tagName: 'DIV',
                isContentEditable: true,
                frame: 'ready' as const,
            },
        ];

        for (const field of fields) {
            const target = shortcutTargetOf(field);

            expect(target).toBe('editable');

            for (const input of SHORTCUT_KEYS) {
                for (const context of CONTEXTS) {
                    expect(shortcutAction(input, target, context)).toBeNull();
                }
            }
        }

        // Ce qui n'est pas un champ de saisie garde sa cible.
        expect(shortcutTargetOf({ tagName: 'INPUT', type: 'checkbox' })).toBe(
            'control',
        );
        expect(shortcutTargetOf({ tagName: 'INPUT', type: 'radio' })).toBe(
            'control',
        );
        expect(shortcutTargetOf({ tagName: 'BUTTON' })).toBe('control');
        expect(shortcutTargetOf({ tagName: 'A' })).toBe('control');
        expect(shortcutTargetOf({ tagName: 'BUTTON', role: 'radio' })).toBe(
            'control',
        );
        expect(shortcutTargetOf({ tagName: 'BUTTON', role: 'checkbox' })).toBe(
            'control',
        );
        expect(
            shortcutTargetOf({ tagName: 'DIV', role: 'group', frame: 'ready' }),
        ).toBe('frame');
        expect(
            shortcutTargetOf({ tagName: 'DIV', role: 'group', frame: 'idle' }),
        ).toBe('surface');
        expect(shortcutTargetOf({ tagName: 'H2' })).toBe('surface');
        expect(shortcutTargetOf({ tagName: 'SECTION' })).toBe('surface');
    });

    it('la touche Entrée vaut conforme, publier dans la passe de revue', () => {
        // Depuis le titre de l'image, qui prend le focus à chaque image, ou
        // depuis le panneau lui-même.
        expect(
            shortcutAction({ key: 'Enter', code: 'Enter' }, 'surface', REVIEW),
        ).toEqual({ kind: 'pass' });
        expect(
            shortcutAction(
                { key: 'Enter', code: 'NumpadEnter' },
                'surface',
                REVIEW,
            ),
        ).toEqual({ kind: 'pass' });

        // Sur un bouton ou une case, `Entrée` garde son effet habituel :
        // « Non conforme » ne publie jamais.
        expect(
            shortcutAction({ key: 'Enter', code: 'Enter' }, 'control', REVIEW),
        ).toBeNull();

        // Jamais pendant le choix des points en défaut, où les réponses
        // portent un rejet, ni pendant un envoi.
        expect(
            shortcutAction({ key: 'Enter', code: 'Enter' }, 'surface', {
                screen: 'review',
                choosingFailures: true,
                busy: false,
            }),
        ).toBeNull();
        expect(
            shortcutAction({ key: 'Enter', code: 'Enter' }, 'surface', {
                screen: 'review',
                choosingFailures: false,
                busy: true,
            }),
        ).toBeNull();

        // Ni combinée, ni répétée par une touche maintenue.
        for (const modifier of [
            { shiftKey: true },
            { ctrlKey: true },
            { altKey: true },
            { metaKey: true },
            { repeat: true },
        ]) {
            expect(
                shortcutAction(
                    { key: 'Enter', code: 'Enter', ...modifier },
                    'surface',
                    REVIEW,
                ),
            ).toBeNull();
        }

        // Les raccourcis du recadreur n'ont pas cours dans la revue.
        expect(
            shortcutAction({ key: '3', code: 'Digit3' }, 'surface', REVIEW),
        ).toBeNull();
        expect(
            shortcutAction(
                { key: ']', code: 'BracketRight' },
                'surface',
                REVIEW,
            ),
        ).toBeNull();
    });
});
