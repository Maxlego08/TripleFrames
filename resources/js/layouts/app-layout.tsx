import { SiteFooter } from '@/components/public/site-footer';
import { Toaster } from '@/components/ui/sonner';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

/**
 * Coquille du starter, gardée au jalon 1 pour `dashboard` et `settings/*`, que
 * ne voient que le porteur et les comptes de test (spec 90 § 2.5 : dette
 * assumée, soldée au jalon 2 par 40).
 *
 * Elle porte le pied de page joueur complet, sous le contenu, comme toute
 * coquille joueur (spec 90 § 2.4, § 3.1), et monte son propre `<Toaster />` :
 * il n'est plus monté globalement par `app.tsx` (spec 90 § 2.3). Les toasts
 * des écrans de réglages (profil enregistré…) s'affichent donc ici.
 */
export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <>
            <AppLayoutTemplate breadcrumbs={breadcrumbs}>
                {children}
                <SiteFooter variant="full" />
            </AppLayoutTemplate>
            <Toaster />
        </>
    );
}
