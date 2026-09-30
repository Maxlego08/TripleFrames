import { usePage } from '@inertiajs/react';
import { AdminFooter } from '@/components/admin/admin-footer';
import { AdminHeader } from '@/components/admin/admin-header';
import { AdminSidebar } from '@/components/admin/admin-sidebar';
import { SidebarProvider } from '@/components/ui/sidebar';
import { Toaster } from '@/components/ui/sonner';
import { useTranslations } from '@/hooks/use-translations';
import type { AdminLayoutProps } from '@/types/ui';

const MAIN_ID = 'admin-main';

/**
 * Coquille du back-office.
 *
 * Elle ne réutilise PAS `AppLayout` — règle 5 appliquée à la lettre : un
 * re-skin du site joueur ne doit pas toucher l'outil de catalogue, et
 * l'inverse non plus. Seules les primitives `ui/sidebar` sont partagées.
 *
 * **Aucun thème forcé** : le back-office suit l'apparence choisie par le
 * visiteur, clair, sombre ou système (D8 du 23/09, spec 90 § 2.2). La revue
 * d'une image exige de la voir telle qu'elle sera servie en jeu, ce que seul un
 * cadre sombre LOCAL donne — les cadres de revue et de prévisualisation passent
 * sous les tokens sombres du jeu (spec 20 § 6.7), jamais le document entier.
 * Tout passe par les tokens et par eux seuls : le back-office peint en
 * `bg-background`, `text-foreground`, `border-border`… Aucun composant
 * d'administration ne contient une couleur littérale ni une taille en `px`,
 * et `scripts/check-theme-tokens.mjs` fait échouer `npm run check` si l'un y
 * revient.
 *
 * Largeur : la barre latérale se replie en icônes et le contenu occupe tout le
 * reste — un tableau de catalogue à onze colonnes réclame chaque pixel.
 * `min-w-0` sur l'encart est ce qui empêche une cellule large de pousser le
 * `<body>` en défilement horizontal (règle 10) : chaque tableau défile dans
 * son propre conteneur, jamais la page.
 *
 * Le `<main>` ne rembourre PAS : c'est déjà la convention du côté joueur
 * (`app-sidebar-layout.tsx` ne pose que `min-w-0 overflow-x-clip`), et chaque
 * page ouvre son contenu par `p-4 md:p-6`. Le faire des deux côtés doublait la
 * marge — soixante-quatre pixels perdus sur un téléphone de 375, avant le
 * premier pixel d'un tableau à onze colonnes (règle 10).
 *
 * Le lien d'évitement est la première cible de tabulation : sans lui, un
 * curateur au clavier retraverse toute la navigation à chaque changement de
 * page.
 *
 * Le pied `AdminFooter` clôt chaque écran (spec 20 § 13.2) : pages légales
 * et attribution TMDB, sur les clés `admin.footer.*` — jamais `SiteFooter`, qui
 * appelle le domaine `legal` que le back-office ne reçoit pas.
 *
 * `<Toaster />` est monté ICI (spec 90 § 2.3) : il ne l'est plus globalement
 * par `app.tsx`, pour qu'aucune page de jeu ne porte une seconde région
 * `aria-live`. Les toasts du back-office (import mis en file, erreur TMDB…)
 * passent par lui.
 */
export default function AdminLayout({
    breadcrumbs = [],
    children,
}: AdminLayoutProps) {
    const { t } = useTranslations();
    const sidebarOpen = usePage().props.sidebarOpen;

    return (
        <>
            <SidebarProvider defaultOpen={sidebarOpen}>
                <a
                    href={`#${MAIN_ID}`}
                    className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-primary focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground focus:ring-2 focus:ring-ring focus:outline-none"
                >
                    {t('admin.a11y.main')}
                </a>

                <AdminSidebar />

                {/*
                 * Ce n'est PAS `<SidebarInset>` : le composant généré rend lui-même
                 * un `<main>`, et l'en-tête se retrouverait DANS le point de repère
                 * principal, avec un second `<main>` imbriqué pour le contenu. Deux
                 * repères `main` dans un document, c'est un défaut d'accessibilité,
                 * et `components/ui/*` ne se modifie pas. On garde donc la coquille
                 * en `<div>` et un seul `<main>`, après l'en-tête.
                 */}
                <div className="relative flex min-h-svh max-w-full min-w-0 flex-1 flex-col overflow-x-clip bg-background">
                    <AdminHeader breadcrumbs={breadcrumbs} />

                    <main
                        id={MAIN_ID}
                        tabIndex={-1}
                        className="flex w-full min-w-0 flex-1 flex-col outline-none"
                    >
                        {children}
                    </main>

                    <AdminFooter />
                </div>
            </SidebarProvider>
            <Toaster />
        </>
    );
}
