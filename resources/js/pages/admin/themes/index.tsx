import { Head, router } from '@inertiajs/react';
import { PlusIcon, TagsIcon } from 'lucide-react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { toast } from 'sonner';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { ThemeFormDialog } from '@/components/admin/theme-form-dialog';
import type { ThemeFormTarget } from '@/components/admin/theme-form-dialog';
import { ThemePublishDialog } from '@/components/admin/theme-publish-dialog';
import { ruleValueLabel } from '@/components/admin/theme-rule-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import { THEME_KIND_KEYS } from '@/lib/admin-enum-keys';
import { formatInteger } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as themesIndex } from '@/routes/admin/themes';
import type {
    AdminTheme,
    AdminThemeAbilities,
    AdminThemePrefill,
    AdminThemePublication,
    CreatableThemeKind,
    ThemeKind,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    themes: AdminTheme[];
    kinds: CreatableThemeKind[];
    prefill: AdminThemePrefill | null;
    publication: AdminThemePublication;
    abilities: AdminThemeAbilities;
};

/** L'ordre des blocs de natures, celui de `ThemeKind::sortBlock()`. */
const KIND_ORDER: ThemeKind[] = [
    'genre',
    'studio',
    'decade',
    'language',
    'difficulty',
    'saga',
];

/** Identifiant du toast de déconnexion : un seul à l'écran, jamais une pile. */
const OFFLINE_TOAST_ID = 'admin-themes-offline';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.themes', href: themesIndex() },
];

/**
 * L'écran des thèmes — spec 20 § 9.6, ligne 28 de la matrice (J1 depuis D43
 * du 01/10).
 *
 * Un tableau par nature : clé, libellés, règle résolue en noms, nombre
 * d'**œuvres** du thème seul (la mesure qui gouverne la publication), films
 * actifs, état, ordre. Trois gestes, chacun sous sa policy : créer, modifier,
 * publier ou dépublier. Les booléens `abilities` ne font que montrer un
 * bouton.
 *
 * Le sélecteur de thèmes du lobby reste masqué au J1 : l'écran le dit, pour
 * qu'une publication ne surprenne pas par son absence côté joueur.
 */
export default function AdminThemesIndex({
    themes,
    kinds,
    prefill,
    publication,
    abilities,
}: Props) {
    const { t, locale } = useTranslations();
    const [formTarget, setFormTarget] = useState<ThemeFormTarget | null>(
        prefill !== null && abilities.create
            ? { mode: 'create', prefill }
            : null,
    );
    const [publishing, setPublishing] = useState<AdminTheme | null>(null);
    const triggerRef = useRef<HTMLElement | null>(null);
    const zoneRef = useRef<HTMLDivElement>(null);

    const announceOffline = useEffectEvent((): void => {
        toast.error(t('admin.common.offline'), { id: OFFLINE_TOAST_ID });
    });

    useEffect(() => router.on('networkError', () => announceOffline()), []);

    function remember(): void {
        triggerRef.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
    }

    function returnFocus(): void {
        const trigger = triggerRef.current;

        if (trigger !== null && trigger.isConnected) {
            trigger.focus();
        } else {
            zoneRef.current?.focus();
        }
    }

    const groups = KIND_ORDER.map((kind) => ({
        kind,
        themes: themes.filter((theme) => theme.kind === kind),
    })).filter((group) => group.themes.length > 0);

    return (
        <>
            <Head title={t('admin.themes.title')} />

            <div
                ref={zoneRef}
                tabIndex={-1}
                className="flex w-full flex-col gap-6 p-4 outline-none md:p-6"
            >
                <AdminPageHeading
                    title={t('admin.themes.heading')}
                    description={t('admin.themes.description')}
                    actions={
                        abilities.create && (
                            <Button
                                className="min-h-11"
                                onClick={() => {
                                    remember();
                                    setFormTarget({
                                        mode: 'create',
                                        prefill: null,
                                    });
                                }}
                            >
                                <PlusIcon aria-hidden />
                                {t('admin.themes.create')}
                            </Button>
                        )
                    }
                />

                <Alert>
                    <AlertDescription className="flex flex-col gap-1">
                        <span>
                            {t('admin.themes.threshold', {
                                min: formatInteger(
                                    publication.min_works,
                                    locale,
                                ),
                                frames: formatInteger(
                                    publication.frames_per_round,
                                    locale,
                                ),
                            })}
                        </span>
                        <span>{t('admin.themes.selector_hidden')}</span>
                    </AlertDescription>
                </Alert>

                {groups.length === 0 ? (
                    <AdminEmptyState
                        icon={TagsIcon}
                        title={t('admin.themes.none')}
                    />
                ) : (
                    groups.map((group) => (
                        <Card key={group.kind}>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t(THEME_KIND_KEYS[group.kind])}
                                </AdminCardTitle>
                                <CardDescription>
                                    {t('admin.themes.works_hint', {
                                        frames: formatInteger(
                                            publication.frames_per_round,
                                            locale,
                                        ),
                                    })}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ThemeTable
                                    themes={group.themes}
                                    minWorks={publication.min_works}
                                    caption={t('admin.themes.a11y.group', {
                                        kind: t(THEME_KIND_KEYS[group.kind]),
                                    })}
                                    onEdit={(theme) => {
                                        remember();
                                        setFormTarget({ mode: 'edit', theme });
                                    }}
                                    onPublish={(theme) => {
                                        remember();
                                        setPublishing(theme);
                                    }}
                                />
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>

            <ThemeFormDialog
                target={formTarget}
                kinds={kinds}
                onClose={() => setFormTarget(null)}
                onReturnFocus={returnFocus}
            />

            <ThemePublishDialog
                theme={publishing}
                publication={publication}
                onClose={() => setPublishing(null)}
                onReturnFocus={returnFocus}
            />
        </>
    );
}

AdminThemesIndex.layout = { breadcrumbs };

/** La règle d'un thème, composée à partir de ses données. */
function RuleCell({ theme }: { theme: AdminTheme }) {
    const { t } = useTranslations();

    if (theme.manual) {
        return (
            <span className="text-muted-foreground">
                {t('admin.themes.rule.manual')}
            </span>
        );
    }

    const values = theme.rule_items
        .map((item) => ruleValueLabel(theme.kind, item.value, item.name, t))
        .join(', ');

    return (
        <span>
            {theme.negated && (
                <span className="font-medium">
                    {t('admin.themes.rule.negated')}{' '}
                </span>
            )}
            {values}
        </span>
    );
}

/** Les thèmes d'une nature et leurs gestes. */
function ThemeTable({
    themes,
    minWorks,
    caption,
    onEdit,
    onPublish,
}: {
    themes: AdminTheme[];
    minWorks: number;
    caption: string;
    onEdit: (theme: AdminTheme) => void;
    onPublish: (theme: AdminTheme) => void;
}) {
    const { t, locale } = useTranslations();

    return (
        <Table>
            <caption className="sr-only">{caption}</caption>
            <TableHeader>
                <TableRow>
                    <TableHead>{t('admin.themes.column.key')}</TableHead>
                    <TableHead>{t('admin.themes.column.label_fr')}</TableHead>
                    <TableHead>{t('admin.themes.column.label_en')}</TableHead>
                    <TableHead>{t('admin.themes.column.rule')}</TableHead>
                    <TableHead className="text-right">
                        {t('admin.themes.column.works')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.themes.column.active_films')}
                    </TableHead>
                    <TableHead>{t('admin.themes.column.published')}</TableHead>
                    <TableHead className="text-right">
                        {t('admin.themes.column.sort_order')}
                    </TableHead>
                    <TableHead>{t('admin.themes.column.actions')}</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {themes.map((theme) => (
                    <TableRow key={theme.id}>
                        <TableCell className="align-top font-mono text-xs break-all">
                            {theme.key}
                        </TableCell>
                        <TableCell className="align-top">
                            {theme.labels.fr ?? t('admin.common.none')}
                        </TableCell>
                        <TableCell className="align-top">
                            {theme.labels.en ?? t('admin.common.none')}
                        </TableCell>
                        <TableCell className="align-top">
                            <RuleCell theme={theme} />
                        </TableCell>
                        <TableCell className="text-right align-top tabular-nums">
                            <span
                                className={
                                    theme.works < minWorks
                                        ? 'text-muted-foreground'
                                        : 'font-medium'
                                }
                            >
                                {formatInteger(theme.works, locale)}
                            </span>
                        </TableCell>
                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(theme.active_films, locale)}
                        </TableCell>
                        <TableCell className="align-top">
                            <div className="flex flex-col items-start gap-1">
                                <Badge
                                    variant={
                                        theme.is_published
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    {theme.is_published
                                        ? t('admin.themes.status.published')
                                        : t('admin.themes.status.unpublished')}
                                </Badge>
                                {theme.missing_locales.length > 0 && (
                                    <span className="text-xs text-destructive">
                                        {t('admin.themes.missing_labels', {
                                            locales:
                                                theme.missing_locales.join(
                                                    ', ',
                                                ),
                                        })}
                                    </span>
                                )}
                            </div>
                        </TableCell>
                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(theme.sort_order, locale)}
                        </TableCell>
                        <TableCell className="align-top">
                            <div className="flex flex-wrap gap-2">
                                {theme.abilities.update && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="min-h-11"
                                        aria-label={t(
                                            'admin.themes.a11y.edit',
                                            {
                                                key: theme.key,
                                            },
                                        )}
                                        onClick={() => onEdit(theme)}
                                    >
                                        {t('admin.themes.actions.edit')}
                                    </Button>
                                )}
                                {theme.abilities.publish && (
                                    <Button
                                        size="sm"
                                        variant={
                                            theme.is_published
                                                ? 'outline'
                                                : 'default'
                                        }
                                        className="min-h-11"
                                        aria-label={
                                            theme.is_published
                                                ? t(
                                                      'admin.themes.a11y.unpublish',
                                                      { key: theme.key },
                                                  )
                                                : t(
                                                      'admin.themes.a11y.publish',
                                                      { key: theme.key },
                                                  )
                                        }
                                        onClick={() => onPublish(theme)}
                                    >
                                        {theme.is_published
                                            ? t(
                                                  'admin.themes.actions.unpublish',
                                              )
                                            : t('admin.themes.actions.publish')}
                                    </Button>
                                )}
                            </div>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
