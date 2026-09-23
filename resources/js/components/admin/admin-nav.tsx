import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslations } from '@/hooks/use-translations';
import { hasAtLeastRole } from '@/lib/roles';
import { toUrl } from '@/lib/utils';
import type { UserRole } from '@/types/auth';
import type { AdminNavItem } from '@/types/navigation';

const NAV_LABEL_ID = 'admin-nav-label';

type Props = {
    items: AdminNavItem[];
    /** Rôle du curateur connecté : sert au **masquage** d'une entrée, jamais à l'autorisation. */
    role: UserRole;
};

/**
 * Navigation principale du back-office.
 *
 * Deux règles portées ici et nulle part ailleurs :
 *
 * 1. **Une entrée au-dessus du rôle ne s'affiche pas.** Aucune entrée n'est
 *    réservée à l'administrateur dans ce lot — les cinq écrans sont au seuil
 *    `curator` —, mais la gestion des accès et la modération de la spec 20 le
 *    seront : elles porteront `minRole: 'admin'` et disparaîtront pour un
 *    curateur. Masquage seulement : le serveur reste le seul juge.
 * 2. **Le tableau de bord se compare en `exact`.** Son URL `/admin` est le
 *    préfixe de toutes les autres : en comparaison par préfixe, il resterait
 *    allumé sur la fiche d'un film.
 */
export function AdminNav({ items, role }: Props) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const { t } = useTranslations();

    const visibleItems = items.filter((item) =>
        hasAtLeastRole(role, item.minRole ?? 'curator'),
    );

    return (
        <SidebarGroup>
            <SidebarGroupLabel id={NAV_LABEL_ID}>
                {t('admin.nav.section')}
            </SidebarGroupLabel>
            <SidebarGroupContent>
                <SidebarMenu aria-labelledby={NAV_LABEL_ID}>
                    {visibleItems.map((item) => {
                        const isActive =
                            item.match === 'exact'
                                ? isCurrentUrl(item.href)
                                : isCurrentOrParentUrl(item.href);

                        return (
                            <SidebarMenuItem key={toUrl(item.href)}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isActive}
                                    tooltip={{ children: item.title }}
                                >
                                    <Link
                                        href={item.href}
                                        prefetch
                                        aria-current={
                                            isActive ? 'page' : undefined
                                        }
                                    >
                                        {item.icon && <item.icon aria-hidden />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        );
                    })}
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
