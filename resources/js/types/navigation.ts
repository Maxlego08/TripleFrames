import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { UserRole } from '@/types/auth';
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

/**
 * Entrée de navigation du back-office. Comme `NavItem`, elle est construite
 * **pendant le rendu** : son libellé est déjà traduit.
 *
 * Elle ajoute deux notions que la navigation joueur n'a pas :
 *
 * - `minRole`, seuil d'**affichage** miroir de `UserRole::atLeast()`. Aucune
 *   entrée n'est réservée à l'administrateur dans ce lot — les cinq écrans
 *   sont au seuil `curator` — mais la gestion des accès et la modération de la
 *   spec 20 le seront. Ce n'est qu'un masquage : l'autorisation reste serveur,
 *   posée route par route par `can:` et par les policies.
 * - `match`, parce que l'URL du tableau de bord (`/admin`) est le préfixe de
 *   toutes les autres : comparée par préfixe, elle resterait allumée sur la
 *   fiche d'un film.
 */
export type AdminNavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    /** Absent = `curator`, le seuil du groupe `/admin`. */
    minRole?: UserRole;
    /** Défaut : `'prefix'`, pour qu'une fiche film allume l'entrée « catalogue ». */
    match?: 'exact' | 'prefix';
};
