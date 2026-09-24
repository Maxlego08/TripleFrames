import { Link, usePage } from '@inertiajs/react';
import { Clapperboard, DownloadCloud, LayoutDashboard } from 'lucide-react';
import { AdminBrand } from '@/components/admin/admin-brand';
import { AdminNav } from '@/components/admin/admin-nav';
import { AdminUserPanel } from '@/components/admin/admin-user-panel';
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
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as catalogIndex } from '@/routes/admin/catalog';
import { index as importIndex } from '@/routes/admin/import';
import type { AdminNavItem } from '@/types/navigation';

/**
 * Coquille de navigation du back-office.
 *
 * Elle ne réutilise PAS `<AppSidebar>` : un re-skin du site joueur ne doit pas
 * toucher l'outil de catalogue, et l'inverse non plus (règle 5). Les
 * primitives `ui/sidebar` sont partagées, la composition ne l'est pas.
 *
 * `collapsible="icon"` et variante par défaut — la variante `inset` du starter
 * mange de la largeur en marges arrondies, et un tableau de catalogue à onze
 * colonnes la réclame toute.
 *
 * Pas de `<SidebarRail>` : le composant généré porte `aria-label="Toggle
 * sidebar"` **et** `title="Toggle sidebar"` en dur, deux textes anglais qu'on
 * ne peut pas traduire sans éditer `components/ui/*`, ce que le dépôt
 * s'interdit. Le bouton de l'en-tête, lui, reçoit son nom accessible par
 * `aria-label`.
 *
 * **La même fuite subsiste sur le chemin MOBILE, et elle n'est pas corrigée
 * ici.** Sous le point d'arrêt mobile, `<Sidebar>` se rend dans un `<Sheet>`
 * dont le `SheetTitle` (« Sidebar ») et la `SheetDescription` (« Displays the
 * mobile sidebar. ») sont écrits en dur : ce sont le nom et la description
 * accessibles du dialogue, et ils sont annoncés. Ils ne sont pas supplantables
 * de l'extérieur — `Sidebar` répand ses props sur `<Sheet>`, la racine Radix
 * qui ne rend aucun élément, et non sur `<SheetContent>`. La corriger suppose
 * de composer une coquille mobile propre à ce fichier ; c'est consigné dans
 * `docs/REPRISE.md` § « À trancher par la spec 20 », point 24, plutôt que
 * laissé sans trace.
 */
export function AdminSidebar() {
    const { t } = useTranslations();
    const { auth } = usePage().props;

    // Rendu derrière `auth` et `role:curator`, mais le type partagé ne le sait
    // pas : `auth.user` est nul pour tout visiteur (spec 40 § 8.5). Garde
    // explicite, jamais d'assertion non nulle.
    if (!auth.user) {
        return null;
    }

    // La liste vit dans le rendu, pas au niveau module : un libellé calculé à
    // l'import resterait figé dans la langue du bundle.
    const navItems: AdminNavItem[] = [
        {
            title: t('admin.nav.dashboard'),
            href: adminDashboard(),
            icon: LayoutDashboard,
            match: 'exact',
        },
        {
            title: t('admin.nav.catalog'),
            href: catalogIndex(),
            icon: Clapperboard,
        },
        {
            title: t('admin.nav.import'),
            href: importIndex(),
            icon: DownloadCloud,
        },
    ];

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={adminDashboard()} prefetch>
                                <AdminBrand />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <nav
                    aria-label={t('admin.a11y.nav')}
                    className="flex w-full min-w-0 flex-col"
                >
                    <AdminNav items={navItems} role={auth.user.role} />
                </nav>
            </SidebarContent>

            <SidebarFooter>
                <AdminUserPanel user={auth.user} />
            </SidebarFooter>
        </Sidebar>
    );
}
