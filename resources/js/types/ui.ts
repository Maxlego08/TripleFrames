import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

/**
 * Props reçues par `AdminLayout` depuis `Page.layout`.
 *
 * Même forme qu'`AppLayoutProps`, type distinct volontairement : le
 * back-office ne réutilise pas la coquille joueur, et un re-skin de l'un ne
 * doit pas toucher l'autre (règle 5). Les libellés de fil d'Ariane sont des
 * **clés**, résolues par `<Breadcrumbs>` au rendu.
 */
export type AdminLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

/**
 * Props de `GameLayout`, coquille plein écran des pages `game/*` (spec 90
 * § 2.3, contrat C16 § 2.3) : la page seule. La coquille ne reçoit ni titre,
 * ni fil d'Ariane, ni en-tête — l'écran appartient à l'image, au chrono et à
 * la saisie.
 */
export type GameLayoutProps = {
    children: ReactNode;
};

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

/**
 * Props reçues par `AuthLayout` depuis `Page.layout` (ou `setLayoutProps`) :
 * des **clés**, pour la même raison qu'un fil d'Ariane — elles sont écrites au
 * chargement du module, hors de tout rendu.
 */
export type AuthLayoutKeys = {
    title?: TranslationKey;
    description?: TranslationKey;
};

/** Props des gabarits d'authentification, qui reçoivent des textes déjà résolus. */
export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
