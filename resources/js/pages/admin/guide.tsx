import { Head, Link, router } from '@inertiajs/react';
import { ListChecksIcon, ListOrderedIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import { formatInteger, formatPercent } from '@/lib/admin-format';
import {
    dashboard as adminDashboard,
    guide as adminGuide,
} from '@/routes/admin';
import { index as curationIndex } from '@/routes/admin/curation';
import { index as reviewIndex } from '@/routes/admin/review';
import type {
    AdminGuideFloor,
    AdminGuideGrid,
    AdminGuideGridItem,
    AdminGuideLevel,
    AdminShortcutKey,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    /** L'échelle 1-5 et les clés de chaque niveau, de la plus cryptique à la plus évidente. */
    scale: AdminGuideLevel[];
    /** La grille d'exclusion COURANTE, item par item, avec ses niveaux. */
    grid: AdminGuideGrid;
    /** Le plancher de recadrage réellement appliqué, en nombres. */
    floor: AdminGuideFloor;
    /** Les raccourcis de débit décrits, dans l'ordre du rappel de l'éditeur. */
    shortcuts: AdminShortcutKey[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.guide', href: adminGuide() },
];

/** Identifiant du toast de déconnexion : un seul à l'écran, jamais une pile. */
const OFFLINE_TOAST_ID = 'admin-guide-offline';

/** Les sections de la page, dans l'ordre de lecture et du sommaire. */
type SectionId =
    | 'passes'
    | 'scale'
    | 'floor'
    | 'grid'
    | 'review'
    | 'publication'
    | 'set_aside'
    | 'shortcuts'
    | 'never';

const SECTION_ORDER: readonly SectionId[] = [
    'passes',
    'scale',
    'floor',
    'grid',
    'review',
    'publication',
    'set_aside',
    'shortcuts',
    'never',
];

/** Le parcours d'un film, étape par étape (§ 13.6, « les deux passes »). */
const STEPS: readonly TranslationKey[] = [
    'admin.guide.passes.steps.open',
    'admin.guide.passes.steps.crop',
    'admin.guide.passes.steps.review',
    'admin.guide.passes.steps.publish',
    'admin.guide.passes.steps.next',
];

/** Ce qu'il ne faut jamais faire, dans l'ordre du § 13.6. */
const NEVER: readonly TranslationKey[] = [
    'admin.guide.never.poster',
    'admin.guide.never.credits',
    'admin.guide.never.set_photo',
    'admin.guide.never.delete',
    'admin.guide.never.alias_for_title',
];

/** L'ancre d'une section ; son titre prend la même, suffixée de `-heading`. */
function anchorOf(id: SectionId): string {
    return `guide-${id.replace('_', '-')}`;
}

/**
 * La page « premiers pas du curateur » (spec 20 § 13.6, lot L20-18) —
 * **lecture seule**, livrée avec l'outil et condition du lot pilote : les
 * deux passes, l'échelle 1-5, le plancher de recadrage et pourquoi, la
 * grille d'exclusion item par item, la revue et la source déclarée, la
 * publication et l'avertissement d'ambiguïté, écarter, les raccourcis, et ce
 * qu'il ne faut jamais faire.
 *
 * Elle ne recopie aucun texte normatif. L'échelle, la grille **courante** et
 * les raccourcis sont rendus depuis les clés que le serveur envoie — celles
 * que le sélecteur de niveau, la passe de revue et l'éditeur affichent déjà :
 * un item d'une nouvelle version de la grille paraît ici sans toucher la
 * page. Le plancher de recadrage est celui que le serveur applique, mis en
 * forme dans la langue du curateur, jamais un chiffre écrit dans un texte.
 *
 * Chaque niveau de l'échelle rappelle les points de la grille qui ne
 * s'appliquent qu'à lui (le visage du personnage principal aux niveaux 1 et
 * 2) : l'illustration de l'échelle est tirée de la grille elle-même.
 *
 * Clavier : un sommaire en tête, liens d'ancre vers chaque section, puis des
 * sections titrées (navigation par titres) ; les deux sorties vers l'outil
 * sont des liens. États (§ 13.5) : aucune donnée ne se charge après
 * l'affichage — la page arrive entière avec sa visite —, et une visite partie
 * d'ici sans réseau affiche `admin.guide.offline`, sans pile de toasts.
 */
export default function AdminGuide({ scale, grid, floor, shortcuts }: Props) {
    const { t, locale } = useTranslations();

    // Une visite qui part d'ici — sommaire exclu, il reste dans la page — et
    // n'obtient aucune réponse : l'écran le dit, au lieu de rester muet.
    useEffect(
        () =>
            router.on('networkError', () => {
                toast.error(t('admin.guide.offline'), {
                    id: OFFLINE_TOAST_ID,
                });
            }),
        [t],
    );

    const separator = t('admin.common.list_separator');

    const titles: Record<SectionId, string> = {
        passes: t('admin.guide.passes.heading'),
        scale: t('admin.guide.scale.heading'),
        floor: t('admin.guide.floor.heading'),
        grid: t('admin.guide.grid.heading', {
            version: formatInteger(grid.version, locale),
        }),
        review: t('admin.guide.review.heading'),
        publication: t('admin.guide.publication.heading'),
        set_aside: t('admin.guide.set_aside.heading'),
        shortcuts: t('admin.shortcuts.heading'),
        never: t('admin.guide.never.heading'),
    };

    /** Les niveaux d'un item, en clair : « Tous les niveaux » ou leur liste. */
    function levelsOf(item: AdminGuideGridItem): string {
        if (item.levels.length === scale.length) {
            return t('admin.guide.grid.levels_all');
        }

        return t('admin.guide.grid.levels', {
            levels: item.levels
                .map((level) => formatInteger(level, locale))
                .join(separator),
        });
    }

    /** Les points de la grille qui ne s'appliquent qu'à une partie de l'échelle, dont ce niveau. */
    function specificItems(level: AdminGuideLevel['level']): string[] {
        return grid.items
            .filter(
                (item) =>
                    item.levels.length < scale.length &&
                    item.levels.includes(level),
            )
            .map((item) => t(item.label_key));
    }

    return (
        <>
            <Head title={t('admin.guide.title')} />

            <div className="flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.guide.heading')}
                    description={t('admin.guide.description')}
                    actions={
                        <Button asChild className="min-h-11">
                            <Link href={curationIndex()}>
                                <ListOrderedIcon aria-hidden />
                                {t('admin.guide.open_queue')}
                            </Link>
                        </Button>
                    }
                />

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.guide.toc.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent>
                        <nav aria-label={t('admin.guide.toc.label')}>
                            <ol className="grid list-decimal gap-x-6 pl-5 text-sm text-card-foreground sm:grid-cols-2">
                                {SECTION_ORDER.map((id) => (
                                    <li key={id}>
                                        <a
                                            href={`#${anchorOf(id)}`}
                                            className="inline-flex min-h-11 items-center rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            {titles[id]}
                                        </a>
                                    </li>
                                ))}
                            </ol>
                        </nav>
                    </CardContent>
                </Card>

                <GuideSection id="passes" title={titles.passes}>
                    <p>{t('admin.guide.passes.intro')}</p>
                    <ul className="list-disc space-y-2 pl-5">
                        <li>{t('admin.guide.passes.one')}</li>
                        <li>{t('admin.guide.passes.two')}</li>
                    </ul>

                    <h3 className="font-semibold text-card-foreground">
                        {t('admin.guide.passes.steps.heading')}
                    </h3>
                    <ol className="list-decimal space-y-2 pl-5">
                        {STEPS.map((step) => (
                            <li key={step}>{t(step)}</li>
                        ))}
                    </ol>
                </GuideSection>

                <GuideSection id="scale" title={titles.scale}>
                    <p>{t('admin.level.hint')}</p>
                    <ol className="space-y-3">
                        {scale.map((row) => {
                            const specific = specificItems(row.level);

                            return (
                                <li
                                    key={row.level}
                                    className="space-y-1 rounded-md border border-border p-3"
                                >
                                    <p className="font-medium text-card-foreground">
                                        {t('admin.level.option', {
                                            level: formatInteger(
                                                row.level,
                                                locale,
                                            ),
                                            label: t(row.label_key),
                                        })}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {t(row.guide_key)}
                                    </p>
                                    {specific.length > 0 && (
                                        <p className="text-muted-foreground">
                                            {t(
                                                'admin.guide.scale.specific_items',
                                                {
                                                    items: specific.join(
                                                        separator,
                                                    ),
                                                },
                                            )}
                                        </p>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                    <p>{t('admin.guide.scale.change')}</p>
                </GuideSection>

                <GuideSection id="floor" title={titles.floor}>
                    <p>
                        {t('admin.guide.floor.rule', {
                            width: formatPercent(
                                floor.max_width_percent,
                                locale,
                            ),
                            surface: formatPercent(
                                floor.max_surface_percent,
                                locale,
                            ),
                        })}
                    </p>
                    <p>
                        {t('admin.guide.floor.min_width', {
                            min_width: formatInteger(
                                floor.min_width_px,
                                locale,
                            ),
                        })}
                    </p>
                    <p>{t('admin.guide.floor.why')}</p>
                    <p>{t('admin.guide.floor.checked')}</p>
                </GuideSection>

                <GuideSection id="grid" title={titles.grid}>
                    <p>{t('admin.guide.grid.intro')}</p>
                    <ol
                        aria-label={t('admin.guide.grid.items_label')}
                        className="space-y-3"
                    >
                        {grid.items.map((item) => (
                            <li
                                key={item.slug}
                                className="space-y-1 rounded-md border border-border p-3"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium text-card-foreground">
                                        {t(item.label_key)}
                                    </span>
                                    <Badge variant="outline">
                                        {levelsOf(item)}
                                    </Badge>
                                </div>
                                <p className="text-muted-foreground">
                                    {t(item.help_key)}
                                </p>
                            </li>
                        ))}
                    </ol>
                    <p>{t('admin.guide.grid.versioned')}</p>
                </GuideSection>

                <GuideSection id="review" title={titles.review}>
                    <p>{t('admin.guide.review.final_render')}</p>
                    <p>{t('admin.guide.review.gestures')}</p>
                    <p>{t('admin.guide.review.source')}</p>
                    <p>{t('admin.guide.review.immutable')}</p>
                    <div>
                        <Button asChild variant="outline" className="min-h-11">
                            <Link href={reviewIndex()}>
                                <ListChecksIcon aria-hidden />
                                {t('admin.guide.review.open')}
                            </Link>
                        </Button>
                    </div>
                </GuideSection>

                <GuideSection id="publication" title={titles.publication}>
                    <p>{t('admin.guide.publication.explicit')}</p>
                    <p>{t('admin.guide.publication.frames_first')}</p>
                    <p>{t('admin.movie.publish.preview.description')}</p>
                    <p>{t('admin.guide.publication.ambiguity')}</p>
                    <p>{t('admin.guide.publication.stale')}</p>
                </GuideSection>

                <GuideSection id="set_aside" title={titles.set_aside}>
                    <p>{t('admin.guide.set_aside.movie')}</p>
                    <p>{t('admin.guide.set_aside.unpublish_movie')}</p>
                    <p>{t('admin.guide.set_aside.frame')}</p>
                    <p>{t('admin.guide.set_aside.coverage')}</p>
                </GuideSection>

                <GuideSection id="shortcuts" title={titles.shortcuts}>
                    <p>{t('admin.shortcuts.description')}</p>
                    <ul className="list-disc space-y-2 pl-5">
                        {shortcuts.map((shortcut) => (
                            <li key={shortcut}>{t(shortcut)}</li>
                        ))}
                    </ul>
                    <p>{t('admin.guide.shortcuts.operability')}</p>
                </GuideSection>

                <GuideSection id="never" title={titles.never}>
                    <ul className="list-disc space-y-2 pl-5">
                        {NEVER.map((line) => (
                            <li key={line}>{t(line)}</li>
                        ))}
                    </ul>
                </GuideSection>
            </div>
        </>
    );
}

AdminGuide.layout = { breadcrumbs };

/**
 * Une section de la page : une carte titrée, cible d'une ancre du sommaire.
 * `scroll-mt-20` la dégage de l'en-tête collant du back-office quand le
 * sommaire y fait défiler.
 */
function GuideSection({
    id,
    title,
    children,
}: {
    id: SectionId;
    title: string;
    children: ReactNode;
}) {
    const anchor = anchorOf(id);
    const headingId = `${anchor}-heading`;

    return (
        <section
            id={anchor}
            aria-labelledby={headingId}
            className="scroll-mt-20"
        >
            <Card>
                <CardHeader>
                    <AdminCardTitle id={headingId}>{title}</AdminCardTitle>
                </CardHeader>
                <CardContent className="space-y-4 text-sm text-card-foreground">
                    {children}
                </CardContent>
            </Card>
        </section>
    );
}
