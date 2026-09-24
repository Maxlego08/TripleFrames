import type { ReactNode } from 'react';
import { PublicHeader } from '@/components/public/public-header';
import { SiteFooter } from '@/components/public/site-footer';
import { Toaster } from '@/components/ui/sonner';
import { useTranslations } from '@/hooks/use-translations';

const MAIN_ID = 'public-main';

/**
 * Coquille des pages publiques (spec 90 § 2.4, contrat C16 § 2.4) : pages
 * légales, page d'erreur, pages d'entrée `room/*` et, par le cas par défaut
 * d'`app.tsx`, toute page qui n'a pas de coquille propre. Elle suit
 * l'apparence du visiteur : seules les pages `game/*` sont forcées en sombre.
 *
 * De haut en bas : le lien d'évitement (première cible de tabulation), l'en-
 * tête, le contenu, le pied de page complet. Le bandeau de maintenance
 * (L90-3b, sous l'en-tête) et l'annonceur, pour l'annonce de changement de
 * langue (L90-7), rejoignent la coquille avec leurs lots.
 *
 * `<Toaster />` est monté ICI et non plus dans `app.tsx` (spec 90 § 2.3) : la
 * section que rend sonner est une région `aria-live` toujours présente, même
 * vide, et une page de jeu ne doit en compter qu'une, son annonceur. Chaque
 * coquille hors jeu monte donc le sien ; `GameLayout`, jamais.
 */
export default function PublicLayout({ children }: { children: ReactNode }) {
    const { t } = useTranslations();

    return (
        <div className="flex min-h-svh flex-col bg-background text-foreground">
            <a
                href={`#${MAIN_ID}`}
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-primary focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground focus:ring-2 focus:ring-ring focus:outline-none"
            >
                {t('common.nav.skip_to_content')}
            </a>

            <PublicHeader />

            <main
                id={MAIN_ID}
                tabIndex={-1}
                className="flex w-full flex-1 flex-col outline-none"
            >
                {children}
            </main>

            <SiteFooter variant="full" />

            <Toaster />
        </div>
    );
}
