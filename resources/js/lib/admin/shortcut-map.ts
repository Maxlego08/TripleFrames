/**
 * Raccourcis de débit du back-office (spec 20 § 6.4, lot L20-11) : la
 * correspondance (touche, cible, contexte) → action, fonction pure testée par
 * Vitest sans environnement DOM (C18 § 2.4). Le hook
 * `use-throughput-shortcuts` ne fait que la brancher sur les événements.
 *
 * Trois raccourcis, tous facultatifs — l'outil est intégralement opérable par
 * ses boutons et son clavier sans eux (principe 8), ce qui les laisse hors de
 * la barre « terminé » :
 *
 * - **cadre focalisé : `1` à `5`** = classer et envoyer en un geste ;
 * - **`[` et `]`** = visuel précédent et suivant de la bande, sans quitter
 *   le cadre ;
 * - **passe de revue : `Entrée`** = « conforme, publier » (§ 7.4). « Entrée
 *   publie » dans le recadreur est impossible : la publication d'une image
 *   exige une revue sur le rendu final, que produit un job que le curateur
 *   n'attend pas (n° 2, A-24).
 *
 * **Aucun raccourci n'agit dans un champ de saisie**, quel que soit l'écran.
 * Aucun ne vole non plus une touche à son usage natif : `Entrée` sur un
 * bouton, une case ou un lien l'active, et seule une touche frappée hors de
 * tout contrôle publie ; un chiffre ne classe que depuis le cadre lui-même,
 * jamais depuis le groupe de niveaux ni un bouton ; une touche que le
 * recadreur tient déjà pour un geste (`cropKeyCommand`) reste ce geste.
 */

import type { StripDirection } from '@/lib/admin/backdrop-strip';
import { cropKeyCommand } from '@/lib/admin/crop-state';
import type { FrameLevel } from '@/types/admin';

/**
 * L'échelle fermée `frame_level` (enum PHP `FrameLevel`), qui donne ses
 * chiffres aux raccourcis de classement : le domaine d'une colonne, jamais
 * une valeur de jeu.
 */
const SHORTCUT_LEVELS = [
    1, 2, 3, 4, 5,
] as const satisfies readonly FrameLevel[];

/** Le chiffre frappé, tel que la disposition le rend (`KeyboardEvent.key`). */
const LEVEL_BY_KEY = new Map<string, FrameLevel>(
    SHORTCUT_LEVELS.map((level) => [String(level), level]),
);

/**
 * La touche de chiffre, par sa position physique (`KeyboardEvent.code`) :
 * rangée principale et pavé numérique. Sur un clavier AZERTY, la rangée
 * principale rend `&`, `é`, `"`, `'`, `(` sans Maj : la position physique
 * garde au raccourci son chiffre.
 */
const LEVEL_BY_CODE = new Map<string, FrameLevel>(
    SHORTCUT_LEVELS.flatMap((level) => [
        [`Digit${level}`, level] as const,
        [`Numpad${level}`, level] as const,
    ]),
);

/**
 * Ce que les raccourcis lisent d'un événement clavier. Structurellement
 * compatible avec un `KeyboardEvent` de React comme du DOM, et constructible
 * à la main dans un test.
 */
export type ShortcutKeyInput = {
    key: string;
    /** Position physique de la touche (`KeyboardEvent.code`). */
    code?: string;
    shiftKey?: boolean;
    altKey?: boolean;
    ctrlKey?: boolean;
    metaKey?: boolean;
    /** Touche maintenue : la répétition automatique n'est jamais un geste. */
    repeat?: boolean;
};

/**
 * Où la touche a été frappée :
 *
 * - `editable` : un champ de saisie — texte, zone de texte, liste, contenu
 *   éditable ; aucun raccourci n'y agit ;
 * - `frame` : le cadre du recadreur, prêt (visuel chargé, aucun envoi en
 *   cours) ;
 * - `control` : un contrôle dont les touches ont un usage natif — bouton,
 *   lien, case, bouton radio, onglet ;
 * - `surface` : tout le reste — un titre, le fond d'un panneau, un cadre
 *   encore en chargement.
 */
export type ShortcutTarget = 'editable' | 'frame' | 'control' | 'surface';

/**
 * L'écran où la touche a été frappée, et ce qu'il permet à cet instant :
 *
 * - `cropper` : le recadreur de l'éditeur de la banque et sa bande ;
 *   `canSend` dit si l'envoi est permis (cadre admis par le plancher) ;
 * - `review` : l'écran de revue d'une image ; `choosingFailures` dit que le
 *   curateur coche les points en défaut (« Non conforme ») : `Entrée` n'y
 *   publie jamais.
 *
 * `busy` : un envoi est en cours ; aucun raccourci n'agit jusqu'à la réponse.
 */
export type ShortcutContext =
    | { screen: 'cropper'; canSend: boolean; busy: boolean }
    | { screen: 'review'; choosingFailures: boolean; busy: boolean };

/**
 * L'action d'un raccourci :
 *
 * - `classify` : poser le niveau `level` et, si `send`, envoyer l'image ;
 *   un cadre hors plancher reçoit le niveau, jamais l'envoi ;
 * - `neighbour` : ouvrir dans le cadre le visuel voisin de la bande ;
 * - `pass` : « Conforme, publier » sur l'image en revue.
 */
export type ShortcutAction =
    | { kind: 'classify'; level: FrameLevel; send: boolean }
    | { kind: 'neighbour'; direction: StripDirection }
    | { kind: 'pass' };

/**
 * Attribut qui marque le cadre du recadreur et son état : `ready` quand le
 * visuel est chargé et qu'aucun envoi n'est en cours, `idle` sinon. Posé par
 * `FrameCropper`, relu par `use-throughput-shortcuts` : un seul nom pour les
 * deux, sans quoi `1` à `5` cesseraient d'agir en silence.
 */
export const SHORTCUT_FRAME_ATTRIBUTE = 'data-shortcut-frame';

/**
 * Ce que les raccourcis lisent de l'élément qui a reçu la touche, sans DOM :
 * sa balise, le type d'un `<input>`, son rôle ARIA, s'il est éditable, et —
 * pour le cadre du recadreur — son état (`SHORTCUT_FRAME_ATTRIBUTE`).
 */
export type ShortcutTargetDescriptor = {
    tagName: string;
    type?: string | null;
    role?: string | null;
    isContentEditable?: boolean;
    frame?: 'ready' | 'idle' | null;
};

/** Rôles ARIA d'un champ de saisie. */
const EDITABLE_ROLES = new Set([
    'textbox',
    'searchbox',
    'combobox',
    'spinbutton',
]);

/** Types d'`<input>` qui ne sont pas des champs de saisie de texte. */
const NON_TEXT_INPUT_TYPES = new Set([
    'button',
    'checkbox',
    'color',
    'file',
    'hidden',
    'image',
    'radio',
    'range',
    'reset',
    'submit',
]);

/** Balises et rôles ARIA d'un contrôle dont les touches ont un usage natif. */
const CONTROL_TAGS = new Set(['A', 'BUTTON', 'SUMMARY']);
const CONTROL_ROLES = new Set([
    'button',
    'checkbox',
    'link',
    'menuitem',
    'menuitemcheckbox',
    'menuitemradio',
    'option',
    'radio',
    'slider',
    'switch',
    'tab',
    'treeitem',
]);

/**
 * La cible d'une touche, lue sur la description de l'élément focalisé. Un
 * champ de saisie l'emporte sur tout : même marqué, un champ reste un champ.
 */
export function shortcutTargetOf(
    descriptor: ShortcutTargetDescriptor,
): ShortcutTarget {
    const tag = descriptor.tagName.toUpperCase();
    // Un attribut `role` peut énumérer des rôles de repli : le premier fait foi.
    const [role = ''] = (descriptor.role ?? '').trim().split(/\s+/);
    const normalizedRole = role.toLowerCase();

    if (
        descriptor.isContentEditable === true ||
        tag === 'TEXTAREA' ||
        tag === 'SELECT' ||
        EDITABLE_ROLES.has(normalizedRole)
    ) {
        return 'editable';
    }

    if (tag === 'INPUT') {
        const type = (descriptor.type ?? '').toLowerCase();

        // Un type absent, vide ou inconnu est un champ de texte, comme pour
        // le navigateur.
        return NON_TEXT_INPUT_TYPES.has(type) ? 'control' : 'editable';
    }

    if (descriptor.frame === 'ready') {
        return 'frame';
    }

    if (CONTROL_TAGS.has(tag) || CONTROL_ROLES.has(normalizedRole)) {
        return 'control';
    }

    return 'surface';
}

/** Aucune touche de modification : ni Ctrl, ni Alt, ni Méta. */
function isPlain(input: ShortcutKeyInput): boolean {
    return (
        input.altKey !== true &&
        input.ctrlKey !== true &&
        input.metaKey !== true
    );
}

/**
 * La touche rend-elle un caractère, et non un raccourci du navigateur ou du
 * système ? Méta (Cmd + `[` : page précédente) et Ctrl seul (Ctrl + `[`)
 * appartiennent au navigateur ; AltGr, que Windows rapporte comme Ctrl + Alt,
 * et Option sur macOS composent un caractère : sur un clavier AZERTY, `[` et
 * `]` s'écrivent AltGr + `(` et AltGr + `)`.
 */
function composesCharacter(input: ShortcutKeyInput): boolean {
    return (
        input.metaKey !== true &&
        !(input.ctrlKey === true && input.altKey !== true)
    );
}

/** `[` et `]` : le sens du pas dans la bande. */
function bracketDirection(input: ShortcutKeyInput): StripDirection | null {
    if (!composesCharacter(input)) {
        return null;
    }

    switch (input.key) {
        case '[':
            return 'previous';
        case ']':
            return 'next';
        default:
            return null;
    }
}

/**
 * Le niveau d'une touche de chiffre : le caractère `1` à `5` quelle que soit
 * la touche qui le rend (Maj + `&` sur AZERTY) ; à défaut, la position
 * physique d'une touche de chiffre frappée sans Maj et qui rend un caractère
 * (`&` sur AZERTY). Une touche du pavé numérique verrouillé (Fin, Flèche
 * bas…) ne rend aucun caractère, et n'est donc pas un chiffre.
 */
function levelOf(input: ShortcutKeyInput): FrameLevel | null {
    const byKey = LEVEL_BY_KEY.get(input.key);

    if (byKey !== undefined) {
        return byKey;
    }

    if (
        input.shiftKey === true ||
        input.code === undefined ||
        input.key.length !== 1
    ) {
        return null;
    }

    return LEVEL_BY_CODE.get(input.code) ?? null;
}

/**
 * L'action d'une touche frappée sur `target`, dans `context`, ou `null` : la
 * touche suit alors son cours normal.
 */
export function shortcutAction(
    input: ShortcutKeyInput,
    target: ShortcutTarget,
    context: ShortcutContext,
): ShortcutAction | null {
    if (target === 'editable' || input.repeat === true || context.busy) {
        return null;
    }

    if (context.screen === 'review') {
        if (
            target !== 'surface' ||
            context.choosingFailures ||
            input.key !== 'Enter' ||
            input.shiftKey === true ||
            !isPlain(input)
        ) {
            return null;
        }

        return { kind: 'pass' };
    }

    const direction = bracketDirection(input);

    if (direction !== null) {
        return { kind: 'neighbour', direction };
    }

    if (target !== 'frame' || !isPlain(input)) {
        return null;
    }

    // Une touche que le recadreur tient pour un geste reste ce geste : sur
    // un clavier tchèque, la touche du 1 rend `+`, qui élargit le cadre.
    if (
        cropKeyCommand({
            key: input.key,
            code: input.code,
            shiftKey: input.shiftKey === true,
        }) !== null
    ) {
        return null;
    }

    const level = levelOf(input);

    return level === null
        ? null
        : { kind: 'classify', level, send: context.canSend };
}
