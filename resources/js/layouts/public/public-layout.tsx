import { ConsentBanner } from '@/components/public/consent-banner';
import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { GameAnnouncer } from '@/components/game/game-announcer';
import { MaintenanceBanner } from '@/components/public/maintenance-banner';
import { PublicHeader } from '@/components/public/public-header';
import { SiteFooter } from '@/components/public/site-footer';
import { Toaster } from '@/components/ui/sonner';
import { useTranslations } from '@/hooks/use-translations';

const MAIN_ID = 'public-main';

/**
 * Coquille des pages publiques (spec 90 § 2.4, contrat C16 § 2.4) : pages
 * légales, page d'erreur, pages d'entrée `room/*` et, par le cas par défaut
 * d'`app.tsx`, toute page qui n'a pas de coquille propre. Sombre comme tout
 * le site (D56 du 02/10).
 *
 * De haut en bas : le lien d'évitement (première cible de tabulation), l'en-
 * tête, le bandeau de maintenance (rendu seulement pendant un drainage,
 * § 3.3), le contenu, le pied de page complet, le bandeau de consentement
 * superposé en bas, l'annonceur — pour la seule annonce du changement de
 * langue du sélecteur de l'en-tête (`common.language.changed`, § 7.4) — et
 * le `Toaster`.
 *
 * `<Toaster />` est monté ICI et non plus dans `app.tsx` (spec 90 § 2.3) : la
 * section que rend sonner est une région `aria-live` toujours présente, même
 * vide, et une page de jeu ne doit en compter qu'une, son annonceur. Chaque
 * coquille hors jeu monte donc le sien ; `GameLayout`, jamais. Hors jeu,
 * l'annonceur et la section du `Toaster` coexistent : l'invariant d'une seule
 * région vivante ne vise que les pages de jeu (C16 § 4).
 */
export default function PublicLayout({ children }: { children: ReactNode }) {
    const { t } = useTranslations();
    const component = usePage().component;
    const isHomePage = component === 'welcome';
    const isLegalPage = component.startsWith('legal/');
    // Créer, rejoindre un salon et jouer en solo partagent le gabarit des
    // pages d'entrée (`room-entry.scss`).
    const isRoomEntryPage =
        component === 'room/create' ||
        component === 'room/join' ||
        component === 'room/solo';
    const shellVariant = isHomePage
        ? 'public-shell--home'
        : isLegalPage
          ? 'public-shell--legal'
          : isRoomEntryPage
            ? 'public-shell--room-entry'
            : '';

    return (
        <div
            className={`public-shell ${shellVariant} relative flex min-h-svh flex-col bg-background text-foreground`}
        >
            <a
                href={`#${MAIN_ID}`}
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-primary focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground focus:ring-2 focus:ring-ring focus:outline-none"
            >
                {t('common.nav.skip_to_content')}
            </a>

            <PublicHeader />

            <MaintenanceBanner />

            <main
                id={MAIN_ID}
                tabIndex={-1}
                className="flex w-full flex-1 flex-col outline-none"
            >
                {children}
            </main>

            <SiteFooter variant="full" />

            <ConsentBanner />

            <GameAnnouncer />

            <Toaster />
        </div>
    );
}
