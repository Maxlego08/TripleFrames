import { GameAnnouncer } from '@/components/game/game-announcer';
import { MaintenanceBanner } from '@/components/public/maintenance-banner';
import { PublicHeader } from '@/components/public/public-header';
import { SiteFooter } from '@/components/public/site-footer';
import { Toaster } from '@/components/ui/sonner';
import { useTranslations } from '@/hooks/use-translations';
import type { BreadcrumbItem } from '@/types';

/**
 * Coquille des réglages du compte. Elle partage la marque et la navigation
 * publique, mais son corps est une composition autonome définie par
 * `settings.scss` : aucun vestige de la barre latérale du starter Laravel.
 */
export default function AppLayout({
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { t } = useTranslations();

    return (
        <>
            <div className="settings-shell">
                <a href="#settings-main" className="settings-skip-link">
                    {t('common.nav.skip_to_content')}
                </a>
                <PublicHeader />
                <MaintenanceBanner />
                <main id="settings-main" tabIndex={-1}>
                    {children}
                </main>
                <SiteFooter variant="full" />
                <GameAnnouncer />
            </div>
            <Toaster />
        </>
    );
}
