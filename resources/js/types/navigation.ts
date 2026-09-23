import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { TranslationKey } from '@/types/translations';

/**
 * Un fil d'Ariane est déclaré dans `Page.layout`, donc **au chargement du
 * module**, là où aucun hook ne peut tourner : son libellé est une **clé** de
 * traduction, résolue par `<Breadcrumbs>` au rendu. Une chaîne écrite ici
 * resterait figée dans la langue du bundle et ne basculerait jamais.
 */
export type BreadcrumbItem = {
    title: TranslationKey;
    href: NonNullable<InertiaLinkProps['href']>;
};

/**
 * Un élément de navigation est construit **pendant le rendu** (les listes
 * vivent dans le corps des composants, pas au niveau module) : son libellé est
 * donc déjà traduit.
 */
export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};
