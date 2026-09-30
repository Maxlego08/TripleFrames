import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type AdminField = {
    /** Libellé DÉJÀ traduit. */
    label: string;
    /**
     * Valeur déjà rendue. `null` vaut « rien à dire » et n'affiche PAS la
     * ligne : c'est à l'écran de choisir entre l'omettre et écrire
     * `admin.common.none`, la nuance lui appartient.
     */
    value: ReactNode;
};

type Props = {
    fields: AdminField[];
    className?: string;
};

/**
 * Une liste de définitions — le squelette de toute fiche en lecture seule.
 *
 * `<dl>` et non un tableau : ce sont des couples libellé/valeur d'un seul
 * objet, pas des lignes comparables entre elles. Un lecteur d'écran annonce
 * alors « terme » / « définition », ce qu'un `<table>` de deux colonnes ne dit
 * jamais.
 *
 * Aucune copie n'est écrite ici, et aucune largeur en `px` : la grille passe
 * d'une colonne en portrait à deux au point d'arrêt `sm`, en unités relatives.
 */
export function AdminFieldList({ fields, className }: Props) {
    const visible = fields.filter((field) => field.value !== null);

    return (
        <dl className={cn('grid gap-x-6 gap-y-3 sm:grid-cols-2', className)}>
            {visible.map((field, index) => (
                <div key={index} className="min-w-0 space-y-0.5">
                    <dt className="text-xs font-medium text-muted-foreground">
                        {field.label}
                    </dt>
                    <dd className="text-sm break-words text-foreground">
                        {field.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
