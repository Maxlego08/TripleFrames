import { Link, router, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Clapperboard,
    DownloadCloud,
    Activity,
    BarChart3,
    CircleUserRound,
    Gamepad2,
    Gauge,
    LayoutDashboard,
    ListChecks,
    ListOrdered,
    ScrollText,
    ShieldCheck,
    Sparkles,
    Tags,
    UserRound,
    Users,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { AdminBrand } from '@/components/admin/admin-brand';
import { AdminNav } from '@/components/admin/admin-nav';
import { AdminUserPanel } from '@/components/admin/admin-user-panel';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useTranslations } from '@/hooks/use-translations';
import {
    dashboard as adminDashboard,
    guide as adminGuide,
} from '@/routes/admin';
import { index as accessIndex } from '@/routes/admin/access';
import { index as audienceIndex } from '@/routes/admin/audience';
import { index as avatarsIndex } from '@/routes/admin/avatars';
import { index as catalogIndex } from '@/routes/admin/catalog';
import { index as curationIndex } from '@/routes/admin/curation';
import { index as gamesIndex } from '@/routes/admin/games';
import { index as importIndex } from '@/routes/admin/import';
import { index as journalIndex } from '@/routes/admin/journal';
import { index as nearMissesIndex } from '@/routes/admin/near_misses';
import { index as performanceIndex } from '@/routes/admin/performance';
import { index as playersIndex } from '@/routes/admin/players';
import { index as reviewIndex } from '@/routes/admin/review';
import { index as themesIndex } from '@/routes/admin/themes';
import { index as throughputIndex } from '@/routes/admin/throughput';
import { index as usersIndex } from '@/routes/admin/users';
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
 * **Coquille mobile propre** (spec 20 § 13.4, 90 § 2.5, dette n° 24 de
 * `REPRISE.md`). Sous le point d'arrêt mobile, `<Sidebar>` se rendrait dans un
 * `<Sheet>` dont le `SheetTitle` (« Sidebar ») et la `SheetDescription`
 * (« Displays the mobile sidebar. ») sont écrits en dur, en anglais, et non
 * supplantables de l'extérieur — `Sidebar` répand ses props sur la racine
 * Radix, qui ne rend aucun élément. Ce fichier ne s'appuie donc **jamais** sur
 * le `Sheet` intégré : sur mobile, il compose sa propre feuille, pilotée par
 * le même état `openMobile` que le bouton de l'en-tête, titrée
 * `admin.a11y.nav_mobile` et décrite par `admin.a11y.nav_mobile_description`.
 * Le contenu — marque, navigation, panneau du compte — est le même des deux
 * côtés.
 */
export function AdminSidebar() {
    const { t } = useTranslations();
    const { auth } = usePage().props;
    const { isMobile, openMobile, setOpenMobile } = useSidebar();

    // L'élément qui avait le focus à l'ouverture de la feuille : le bouton de
    // l'en-tête, le plus souvent. La feuille est pilotée par un état et non
    // par un `SheetTrigger`, si bien que Radix ne saurait pas où rendre le
    // focus à la fermeture (spec 20 § 13.4 : focus rendu à l'élément
    // déclencheur).
    const returnFocusRef = useRef<HTMLElement | null>(null);

    // La coquille est persistante d'une page à l'autre : sans ce geste, la
    // feuille resterait ouverte par-dessus l'écran que le curateur vient de
    // choisir. L'événement `navigate` couvre aussi le retour arrière ; il ne
    // couvre PAS une visite vers l'URL courante (Inertia la fait en
    // `replace` et ne l'émet pas), d'où la fermeture au clic sur un lien,
    // posée plus bas sur le corps de la feuille.
    useEffect(
        () => router.on('navigate', () => setOpenMobile(false)),
        [setOpenMobile],
    );

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
            title: t('admin.nav.curation'),
            href: curationIndex(),
            icon: ListOrdered,
        },
        {
            title: t('admin.nav.catalog'),
            href: catalogIndex(),
            icon: Clapperboard,
        },
        {
            title: t('admin.nav.near_misses'),
            href: nearMissesIndex(),
            icon: Sparkles,
        },
        // L'écran des thèmes (spec 20 § 9.6, D43 du 01/10) : créer, corriger,
        // publier sous seuil.
        {
            title: t('admin.nav.themes'),
            href: themesIndex(),
            icon: Tags,
        },
        {
            title: t('admin.nav.review'),
            href: reviewIndex(),
            icon: ListChecks,
        },
        {
            title: t('admin.nav.import'),
            href: importIndex(),
            icon: DownloadCloud,
        },
        {
            title: t('admin.nav.throughput'),
            href: throughputIndex(),
            icon: Gauge,
        },
        // La page « premiers pas du curateur » (spec 20 § 13.6) : atteinte
        // d'un clic, jamais par une adresse à connaître.
        {
            title: t('admin.nav.guide'),
            href: adminGuide(),
            icon: BookOpen,
        },
    ];

    // Les écrans de l'administrateur seul (spec 20 § 2.8) : un groupe à part,
    // que `AdminNav` ne rend pas du tout pour un curateur.
    const administrationItems: AdminNavItem[] = [
        {
            title: t('admin.nav.users'),
            href: usersIndex(),
            icon: Users,
            minRole: 'admin',
            prefetch: false,
        },
        {
            title: t('admin.nav.access'),
            href: accessIndex(),
            icon: ShieldCheck,
            minRole: 'admin',
            prefetch: false,
        },
        // Le journal d'administration (ligne 41, D41 du 30/09) : comme ses
        // deux voisines, l'entrée ne précharge pas (spec 20 § 2.7 et § 2.10).
        {
            title: t('admin.nav.journal'),
            href: journalIndex(),
            icon: ScrollText,
            minRole: 'admin',
            prefetch: false,
        },
        // L'inspection des parties et des sièges (lignes 36 et 42, D46 du
        // 01/10) : chaque visite est consignée, donc jamais préchargée.
        {
            title: t('admin.nav.games'),
            href: gamesIndex(),
            icon: Gamepad2,
            minRole: 'admin',
            prefetch: false,
        },
        {
            title: t('admin.nav.players'),
            href: playersIndex(),
            icon: UserRound,
            minRole: 'admin',
            prefetch: false,
        },
        // Les performances (ligne 43, D47 du 01/10).
        {
            title: t('admin.nav.performance'),
            href: performanceIndex(),
            icon: Activity,
            minRole: 'admin',
            prefetch: false,
        },
        // L'audience (ligne 44, D48 du 01/10).
        {
            title: t('admin.nav.audience'),
            href: audienceIndex(),
            icon: BarChart3,
            minRole: 'admin',
            prefetch: false,
        },
        // Les avatars téléversés (ligne 45, D49 du 01/10).
        {
            title: t('admin.nav.avatars'),
            href: avatarsIndex(),
            icon: CircleUserRound,
            minRole: 'admin',
            prefetch: false,
        },
    ];

    const body = (
        <>
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
                    <AdminNav
                        items={administrationItems}
                        role={auth.user.role}
                        label={t('admin.nav.administration')}
                    />
                </nav>
            </SidebarContent>

            <SidebarFooter>
                <AdminUserPanel user={auth.user} />
            </SidebarFooter>
        </>
    );

    if (!isMobile) {
        return <Sidebar collapsible="icon">{body}</Sidebar>;
    }

    /*
     * La fermeture générée par `SheetContent` porte le nom accessible
     * « Close », en dur et en anglais, sans prop pour le remplacer : elle est
     * masquée (`[&>button:last-child]:hidden`, restreint au dernier enfant
     * pour ne pas masquer la fermeture propre, qui vit dans `SheetFooter`) et
     * remplacée par une fermeture traduite `admin.a11y.close` (spec 20
     * § 13.4). `Échap` ferme aussi la feuille (Radix).
     *
     * Mouvement réduit (spec 90 § 8) : `motion-reduce:animate-none!`, avec
     * l'important, car `data-[state=open]:animate-in` du composant généré est
     * plus spécifique qu'une variante `motion-reduce:` nue.
     */
    return (
        <Sheet open={openMobile} onOpenChange={setOpenMobile}>
            <SheetContent
                side="left"
                className="w-72 gap-0 bg-sidebar p-0 text-sidebar-foreground motion-reduce:animate-none! [&>button:last-child]:hidden"
                onOpenAutoFocus={() => {
                    returnFocusRef.current =
                        document.activeElement instanceof HTMLElement
                            ? document.activeElement
                            : null;
                }}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    returnFocusRef.current?.focus();
                    returnFocusRef.current = null;
                }}
            >
                <SheetHeader className="sr-only">
                    <SheetTitle>{t('admin.a11y.nav_mobile')}</SheetTitle>
                    <SheetDescription>
                        {t('admin.a11y.nav_mobile_description')}
                    </SheetDescription>
                </SheetHeader>

                {/*
                 * Tout lien suivi ferme la feuille, y compris celui de la page
                 * courante (tableau de bord, marque), pour lequel Inertia
                 * n'émet pas `navigate`. Jamais `router.on('start')` : il part
                 * aussi sur les préchargements et sur les rechargements
                 * partiels périodiques de l'import. Les événements React
                 * traversent les portails : les liens du menu du compte
                 * (`AdminUserPanel`) sont couverts. Un clic modifié (nouvel
                 * onglet) laisse la feuille ouverte, la page ne change pas.
                 */}
                <div
                    className="flex min-h-0 w-full flex-1 flex-col"
                    onClick={(event) => {
                        if (
                            event.metaKey ||
                            event.ctrlKey ||
                            event.shiftKey ||
                            event.altKey
                        ) {
                            return;
                        }

                        if (
                            event.target instanceof Element &&
                            event.target.closest('a[href]') !== null
                        ) {
                            setOpenMobile(false);
                        }
                    }}
                >
                    {body}
                </div>

                <SheetFooter className="mt-0 border-t border-sidebar-border p-2">
                    <SheetClose asChild>
                        <Button variant="outline" className="min-h-11">
                            {t('admin.a11y.close')}
                        </Button>
                    </SheetClose>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
