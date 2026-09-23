import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useInitials } from '@/hooks/use-initials';
import { useTranslations } from '@/hooks/use-translations';
import { dashboard } from '@/routes';
import type { User } from '@/types/auth';

type Props = {
    user: User;
};

/**
 * Pied de la barre latérale : qui est connecté, à quel rôle, et la sortie vers
 * le site joueur.
 *
 * Ce n'est PAS `<NavUser>` : son menu passe par `UserMenuContent`, dont les
 * libellés vivent dans le domaine `common` — et
 * `TranslationDomains::selected()` rend `['admin']` **seul** dès que le
 * domaine `admin` est demandé. Un écran d'administration ne reçoit donc jamais
 * `common` : réutiliser ce menu afficherait des clés brutes au curateur. Même
 * raison pour l'avatar, dont le repli en initiales est peint en
 * `bg-neutral-200 dark:bg-neutral-700` dans `<UserInfo>`.
 *
 * Il n'y a volontairement **pas** de bouton de déconnexion ici : le geste vit
 * sur le site joueur, à un lien d'ici, et l'y dupliquer demanderait une clé du
 * domaine `common`.
 */
export function AdminUserPanel({ user }: Props) {
    const { t } = useTranslations();
    const getInitials = useInitials();

    const roleLabel =
        user.role === 'admin'
            ? t('admin.nav.role.admin')
            : t('admin.nav.role.curator');

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <div className="flex items-center gap-2 overflow-hidden rounded-md p-2 text-left">
                    <Avatar className="size-8 shrink-0 overflow-hidden rounded-full">
                        <AvatarImage src={user.avatar} alt="" />
                        <AvatarFallback className="rounded-full bg-muted text-xs text-muted-foreground">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="grid min-w-0 flex-1 gap-1 leading-tight group-data-[collapsible=icon]:hidden">
                        <span className="truncate text-sm font-medium">
                            {user.name}
                        </span>
                        <Badge
                            variant="secondary"
                            className="w-fit max-w-full truncate"
                        >
                            {roleLabel}
                        </Badge>
                    </div>
                </div>
            </SidebarMenuItem>

            <SidebarMenuItem>
                <SidebarMenuButton
                    asChild
                    tooltip={{ children: t('admin.nav.back_to_site') }}
                >
                    <Link href={dashboard()}>
                        <ArrowLeft aria-hidden />
                        <span>{t('admin.nav.back_to_site')}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
