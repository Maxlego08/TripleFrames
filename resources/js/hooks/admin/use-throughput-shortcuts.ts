import type { KeyboardEvent } from 'react';
import type { StripDirection } from '@/lib/admin/backdrop-strip';
import {
    SHORTCUT_FRAME_ATTRIBUTE,
    shortcutAction,
    shortcutTargetOf,
} from '@/lib/admin/shortcut-map';
import type {
    ShortcutAction,
    ShortcutContext,
    ShortcutTarget,
} from '@/lib/admin/shortcut-map';
import type { FrameLevel } from '@/types/admin';

/**
 * Ce que l'écran fait d'un raccourci. Un écran ne branche que les siens : une
 * action sans gestionnaire laisse la touche suivre son cours.
 */
export type ThroughputHandlers = {
    /** `1` à `5` depuis le cadre : poser le niveau, et envoyer si `send`. */
    onClassify?: (level: FrameLevel, send: boolean) => void;
    /** `Page précédente` / `Page suivante`, `[` / `]` : visuel voisin. */
    onNeighbour?: (direction: StripDirection) => void;
    /** `Entrée` dans la passe de revue : « Conforme, publier ». */
    onPass?: () => void;
};

/** L'état du cadre, lu sur l'attribut ; `null` hors du cadre. */
function frameStateOf(element: HTMLElement): 'ready' | 'idle' | null {
    const value = element.getAttribute(SHORTCUT_FRAME_ATTRIBUTE);

    return value === 'ready' || value === 'idle' ? value : null;
}

/** La cible d'une touche : l'élément focalisé, décrit sans rien en déduire. */
function targetOf(target: EventTarget): ShortcutTarget | null {
    if (!(target instanceof HTMLElement)) {
        return null;
    }

    return shortcutTargetOf({
        tagName: target.tagName,
        type: target instanceof HTMLInputElement ? target.type : null,
        role: target.getAttribute('role'),
        isContentEditable: target.isContentEditable,
        frame: frameStateOf(target),
    });
}

/** Le geste de l'écran pour une action, ou `null` s'il ne la branche pas. */
function gestureFor(
    action: ShortcutAction,
    handlers: ThroughputHandlers,
): (() => void) | null {
    switch (action.kind) {
        case 'classify': {
            const { onClassify } = handlers;

            return onClassify === undefined
                ? null
                : () => onClassify(action.level, action.send);
        }
        case 'neighbour': {
            const { onNeighbour } = handlers;

            return onNeighbour === undefined
                ? null
                : () => onNeighbour(action.direction);
        }
        case 'pass': {
            const { onPass } = handlers;

            return onPass === undefined ? null : () => onPass();
        }
    }
}

/**
 * Raccourcis de débit (spec 20 § 6.4, lot L20-11) : branche la
 * correspondance pure `shortcutAction()` sur le `keydown` d'un conteneur —
 * le formulaire du recadreur, la bande, l'écran de revue.
 *
 * Le hook ne décide rien : la correspondance (touche, cible, contexte) →
 * action vit dans `lib/admin/shortcut-map.ts`, testée sans DOM (C18 § 2.4).
 * Il ne fait que ce que la fonction pure ne peut pas faire :
 *
 * - décrire l'élément focalisé (balise, type, rôle, contenu éditable, état
 *   du cadre) pour en tirer la cible ;
 * - ignorer une touche déjà consommée plus bas — un geste du recadreur, que
 *   `use-cropper-keyboard` a traité sur le cadre avant que la touche ne
 *   remonte jusqu'ici — et une frappe en cours de composition ;
 * - empêcher l'effet par défaut d'une touche devenue raccourci, et seulement
 *   alors.
 */
export function useThroughputShortcuts(
    context: ShortcutContext,
    handlers: ThroughputHandlers,
): (event: KeyboardEvent<HTMLElement>) => void {
    return (event) => {
        if (event.defaultPrevented || event.nativeEvent.isComposing) {
            return;
        }

        const target = targetOf(event.target);

        if (target === null) {
            return;
        }

        const action = shortcutAction(event, target, context);
        const gesture = action === null ? null : gestureFor(action, handlers);

        if (gesture === null) {
            return;
        }

        event.preventDefault();
        gesture();
    };
}
