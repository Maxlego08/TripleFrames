import { Breadcrumbs } from '@/components/breadcrumbs';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useTranslations } from '@/hooks/use-translations';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

/**
 * Barre supérieure du back-office : le bouton de repli, puis le fil d'Ariane.
 *
 * `<Breadcrumbs>` est réutilisé tel quel — il n'écrit aucune chaîne, il
 * résout les **clés** que la page a posées dans `Page.layout`, ce qui marche
 * aussi bien avec des clés `admin.*` qu'avec des clés joueur.
 *
 * Le nom accessible du bouton est posé ICI : `SidebarTrigger` répand ses props
 * sur son bouton, et un `aria-label` supplante le `sr-only` anglais figé dans
 * le composant généré — que le dépôt s'interdit d'éditer.
 *
 * `sticky` : un tableau de catalogue se lit en défilant, le repère de position
 * doit rester à l'écran.
 */
export function AdminHeader({ breadcrumbs = [] }: Props) {
    const { t } = useTranslations();

    return (
        <header className="sticky top-0 z-10 flex h-16 shrink-0 items-center gap-2 border-b border-border bg-background px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-6">
            <SidebarTrigger
                aria-label={t('admin.a11y.nav')}
                className="-ml-1 shrink-0"
            />
            {breadcrumbs.length > 0 && (
                <Separator
                    orientation="vertical"
                    className="mr-1 data-[orientation=vertical]:h-4"
                />
            )}
            <div className="min-w-0 flex-1 overflow-x-auto">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
