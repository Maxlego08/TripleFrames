import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

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
