import { Link } from '@inertiajs/react';
import { useId } from 'react';
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

type Props = {
    items: AdminNavItem[];
    /** Rôle du curateur connecté : sert au **masquage** d'une entrée, jamais à l'autorisation. */
    role: UserRole;
    /** Libellé DÉJÀ traduit du groupe ; « Curation » par défaut. */
    label?: string;
};

/**
 * Un groupe de la navigation du back-office.
 *
 * Deux règles portées ici et nulle part ailleurs :
 *
 * 1. **Une entrée au-dessus du rôle ne s'affiche pas.** La gestion des accès
 *    et l'annuaire des comptes (spec 20 § 2.8) portent `minRole: 'admin'` et
 *    disparaissent pour un curateur ; un groupe dont aucune entrée n'est
 *    visible ne se rend pas du tout, libellé compris. Masquage seulement : le
 *    serveur reste le seul juge.
 * 2. **Le tableau de bord se compare en `exact`.** Son URL `/admin` est le
 *    préfixe de toutes les autres : en comparaison par préfixe, il resterait
 *    allumé sur la fiche d'un film.
 *
 * L'identifiant du libellé vient de `useId()` : deux groupes coexistent dans
 * la barre, et un identifiant fixe se dupliquerait.
 */
export function AdminNav({ items, role, label }: Props) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const { t } = useTranslations();
    const labelId = useId();

    const visibleItems = items.filter((item) =>
        hasAtLeastRole(role, item.minRole ?? 'curator'),
    );

    if (visibleItems.length === 0) {
        return null;
    }

    return (
        <SidebarGroup>
            <SidebarGroupLabel id={labelId}>
                {label ?? t('admin.nav.section')}
            </SidebarGroupLabel>
            <SidebarGroupContent>
                <SidebarMenu aria-labelledby={labelId}>
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
