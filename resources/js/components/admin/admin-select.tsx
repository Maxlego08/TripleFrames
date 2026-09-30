import type { SelectHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export type AdminSelectOption = {
    /** `''` signifie « pas de filtre » : `ConvertEmptyStringsToNull` le rend `null` au FormRequest. */
    value: string;
    /** Libellé DÉJÀ traduit. */
    label: string;
};

type Props = SelectHTMLAttributes<HTMLSelectElement> & {
    options: AdminSelectOption[];
};

/**
 * Un `<select>` natif, habillé des mêmes tokens que le reste du back-office.
 *
 * **Pourquoi pas `components/ui/select` ?** Le `Select` de shadcn est bâti sur
 * Radix, qui refuse une option de valeur vide — c'est une contrainte
 * documentée de la primitive. Or une barre de filtres a besoin d'exactement
 * cela : « tous », c'est-à-dire *aucun filtre*, soit la chaîne vide que
 * `ConvertEmptyStringsToNull` transforme en `null` et que la règle `nullable`
 * du `CatalogIndexRequest` accepte. Contourner par une valeur sentinelle
 * (`'all'`) enverrait au serveur une valeur hors liste blanche : la validation
 * la refuserait, et la liste reviendrait avec une erreur de session pour un
 * geste parfaitement légitime.
 *
 * Le `<select>` natif règle aussi le reste sans une ligne de JavaScript :
 * il est soumis par le `<Form>` en GET, donc l'état des filtres vit dans
 * l'URL — partageable, rechargeable, indexé par l'historique du navigateur —
 * et il reste utilisable si le script ne charge pas.
 *
 * Le fond et le texte des `<option>` sont posés explicitement : sous Windows,
 * le menu natif n'hérite pas toujours du fond transparent du `<select>`, mais
 * conserve sa couleur de texte, ce qui rendrait des libellés clairs illisibles
 * sur le fond système clair. Les tokens `popover` gardent les deux thèmes
 * cohérents sans imposer une couleur littérale.
 *
 * Aucune couleur littérale, aucune taille en `px` : tout est token de thème.
 */
export function AdminSelect({ options, className, ...props }: Props) {
    return (
        <select
            {...props}
            className={cn(
                'h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm text-foreground shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
        >
            {options.map((option) => (
                <option
                    key={option.value}
                    value={option.value}
                    className="bg-popover text-popover-foreground"
                >
                    {option.label}
                </option>
            ))}
        </select>
    );
}
