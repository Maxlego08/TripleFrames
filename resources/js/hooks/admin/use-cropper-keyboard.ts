import type { KeyboardEvent } from 'react';
import { cropKeyCommand } from '@/lib/admin/crop-state';
import type { CropCommand } from '@/lib/admin/crop-state';

/**
 * Opérabilité clavier du cadre (spec 20 § 6.4, lot L20-9a) : branche la
 * traduction pure `cropKeyCommand()` sur le `keydown` du cadre focalisé.
 *
 * Le hook ne décide rien : la correspondance touche → geste vit dans
 * `lib/admin/crop-state.ts`, testée sans DOM (C18 § 2.4). Il ne fait que
 * trois choses que la fonction pure ne peut pas faire :
 *
 * - n'agir que sur le cadre lui-même (`target === currentTarget`) : une
 *   touche frappée dans un bouton ou un champ niché — « Réessayer » d'un
 *   visuel en échec — suit son cours normal ;
 * - ignorer une frappe en cours de composition (méthode de saisie) ;
 * - empêcher le défilement de la page par les flèches quand elles déplacent
 *   le cadre, et seulement alors.
 *
 * Les raccourcis de débit (`1` à `5`, `Page précédente` / `Page suivante`,
 * `[` / `]`) n'en font pas partie : ils relèvent du lot L20-11, hors de la
 * barre « terminé ».
 */
export function useCropperKeyboard(
    onCommand: (command: CropCommand) => void,
    enabled: boolean,
): (event: KeyboardEvent<HTMLElement>) => void {
    return (event) => {
        if (
            !enabled ||
            event.target !== event.currentTarget ||
            event.nativeEvent.isComposing
        ) {
            return;
        }

        const command = cropKeyCommand(event);

        if (command === null) {
            return;
        }

        event.preventDefault();
        onCommand(command);
    };
}
