import type { ComponentProps } from 'react';
import { CardTitle } from '@/components/ui/card';

/**
 * Le titre d'une carte d'administration, **exposé comme un titre**.
 *
 * `components/ui/card.tsx` rend `CardTitle` en `<div>`, et le dépôt s'interdit
 * de modifier ce fichier. Conséquence mesurable : sur un écran dense — six
 * blocs et une vingtaine de tuiles sur le tableau de bord, une quinzaine de
 * cartes sur la fiche film — un curateur au lecteur d'écran n'obtient qu'un
 * seul titre pour toute la page, celui d'`AdminPageHeading`, et doit traverser
 * linéairement pour atteindre le bloc qu'il cherche. La navigation par titres
 * (touche H sous NVDA/JAWS, rotor VoiceOver) est pourtant le moyen normal de
 * parcourir un tel écran.
 *
 * `CardTitle` répand ses props : `role="heading"` et `aria-level` suffisent à
 * le rendre en titre sans toucher au fichier généré. Le niveau est un
 * paramètre parce que la hiérarchie se décide sur place — un bloc dans un
 * onglet n'est pas au même rang qu'un bloc de premier niveau.
 *
 * Il est encapsulé une fois pour que la discipline ne dérive pas, comme
 * `AdminPageHeading`, `AdminInputError` et `AdminBrand` : côté administration,
 * on n'appelle que celui-ci.
 */
export function AdminCardTitle({
    level = 2,
    ...props
}: ComponentProps<typeof CardTitle> & { level?: 2 | 3 | 4 }) {
    return <CardTitle role="heading" aria-level={level} {...props} />;
}
