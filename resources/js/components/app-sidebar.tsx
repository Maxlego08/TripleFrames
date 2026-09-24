import { Link } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useTranslations } from '@/hooks/use-translations';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

/**
 * Barre latérale du starter, gardée au jalon 1 pour `dashboard` et
 * `settings/*` (spec 90 § 2.5 : dette assumée, soldée au jalon 2 par 40).
 *
 * Son pied ne porte plus les deux liens du starter (dépôt du kit,
 * documentation Laravel), retirés avec leurs clés (spec 90 § 6.6) : les liens
 * légaux et l'attribution TMDB vivent dans le pied de page joueur que monte
 * `AppLayout`, sous le contenu.
 */
export function AppSidebar() {
    const { t } = useTranslations();

    // Les listes de navigation vivent dans le rendu, pas au niveau module :
    // un libellé calculé à l'import resterait figé dans la langue du bundle.
    const mainNavItems: NavItem[] = [
        {
            title: t('common.nav.dashboard'),
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
