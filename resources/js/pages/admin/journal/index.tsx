import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ScrollTextIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import JournalController from '@/actions/App/Http/Controllers/Admin/JournalController';
import { RoleBadge } from '@/components/admin/admin-badges';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import type { AdminSelectOption } from '@/components/admin/admin-select';
import { AdminSelect } from '@/components/admin/admin-select';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import {
    ADMIN_ACTION_KEYS,
    ADMIN_ACTION_RETENTION_KEYS,
    ADMIN_ACTION_SUBJECT_KEYS,
    JOURNAL_DETAIL_KEYS,
} from '@/lib/admin-enum-keys';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { show as catalogShow } from '@/routes/admin/catalog';
import { show as runShow } from '@/routes/admin/import';
import { index as journalIndex } from '@/routes/admin/journal';
import { show as usersShow } from '@/routes/admin/users';
import type {
    AdminJournalFilters,
    AdminJournalLine,
    AdminJournalOptions,
    AdminJournalSubject,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

/** Les deux acteurs réservés, nommés sans jamais passer pour une personne. */
const RESERVED_ACTOR_KEYS: Record<'system' | 'console', TranslationKey> = {
    system: 'admin.journal.actor.system',
    console: 'admin.journal.actor.console',
};

type Props = {
    lines: Paginated<AdminJournalLine>;
    filters: AdminJournalFilters;
    /** Le sujet du filtre courant, résolu ; `null` sans filtre de sujet complet. */
    subject: AdminJournalSubject | null;
    options: AdminJournalOptions;
};

/** Les champs du formulaire, dans l'ordre où leurs erreurs s'annoncent. */
const FILTER_FIELDS = [
    'actor',
    'action',
    'from',
    'to',
    'subject_type',
    'subject_id',
    'movie',
] as const;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.journal', href: journalIndex() },
];

/**
 * Le journal d'administration — spec 20 § 2.2, ligne 41 (D41 du 30/09),
 * **administrateur seul**, en lecture seule et entièrement piloté par l'URL.
 *
 * Chaque ligne dit qui (le nom réel signé à l'instant du geste), quoi, sur
 * quel sujet, quand, et ce que le geste a écrasé (`details`). Les liens
 * « Historique » de la fiche d'un film et de la fiche d'un compte ouvrent cet
 * écran filtré sur leur sujet.
 *
 * Comme l'annuaire, l'attente d'un filtre ou d'une page s'exprime par un
 * attribut sur le tableau maintenu monté, jamais par un démontage : le focus
 * survit à « Suivant » au clavier. Un filtre refusé renvoie à l'écran nu,
 * erreurs à l'appui : le bandeau d'erreur dit alors que le journal affiché
 * n'est PAS filtré.
 */
export default function AdminJournalIndex({
    lines,
    filters,
    subject,
    options,
}: Props) {
    const { t, locale } = useTranslations();
    const { errors } = usePage().props;
    const [busy, setBusy] = useState(false);
    const filtered = FILTER_FIELDS.some((field) => filters[field] !== null);
    const refused = FILTER_FIELDS.some((field) => errors[field] !== undefined);

    useEffect(() => {
        // `router.on` est GLOBAL : seules les visites de cet écran allument
        // son attente.
        const here = journalIndex().url;
        const concerns = (visited: URL): boolean => visited.pathname === here;

        const stopStart = router.on('start', (event) => {
            if (concerns(event.detail.visit.url)) {
                setBusy(true);
            }
        });

        const stopFinish = router.on('finish', (event) => {
            if (concerns(event.detail.visit.url)) {
                setBusy(false);
            }
        });

        return () => {
            stopStart();
            stopFinish();
        };
    }, []);

    return (
        <>
            <Head title={t('admin.journal.title')} />

            <div className="flex w-full min-w-0 flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.journal.heading')}
                    description={t('admin.journal.description')}
                    actions={
                        <Badge variant="outline">
                            {t('admin.common.read_only')}
                        </Badge>
                    }
                />

                {refused && (
                    <AdminErrorState
                        title={t('admin.journal.error.heading')}
                        description={t('admin.journal.error.description')}
                    />
                )}

                {subject !== null && (
                    <Alert>
                        <ScrollTextIcon aria-hidden />
                        <AlertDescription className="flex flex-wrap items-center gap-2">
                            <span className="font-medium text-foreground">
                                {t('admin.journal.subject.filtered', {
                                    subject: subjectText(subject, t),
                                })}
                            </span>
                            {filters.movie !== null && (
                                <span>
                                    {t('admin.journal.subject.with_frames')}
                                </span>
                            )}
                            {!subject.exists && (
                                <span>
                                    {t('admin.journal.subject.unknown')}
                                </span>
                            )}
                            <Button variant="outline" size="sm" asChild>
                                <Link href={journalIndex()}>
                                    {t('admin.journal.subject.clear')}
                                </Link>
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.journal.filters.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent>
                        <JournalFiltersForm
                            filters={filters}
                            options={options}
                            errors={errors}
                        />
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.journal.list.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.journal.results', {
                                total: formatInteger(lines.meta.total, locale),
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="min-w-0 space-y-4">
                        <span
                            role="status"
                            aria-live="polite"
                            className="sr-only"
                        >
                            {busy ? t('admin.common.loading') : ''}
                        </span>

                        {lines.data.length === 0 && (
                            <AdminEmptyState
                                icon={ScrollTextIcon}
                                title={t('admin.journal.empty.heading')}
                                description={
                                    filtered
                                        ? t('admin.journal.empty.filtered')
                                        : t('admin.journal.empty.no_lines')
                                }
                                action={
                                    filtered ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={journalIndex()}>
                                                {t(
                                                    'admin.journal.filters.reset',
                                                )}
                                            </Link>
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )}

                        {lines.data.length > 0 && (
                            <div
                                aria-busy={busy}
                                className={
                                    busy
                                        ? 'min-w-0 space-y-4 opacity-60 transition-opacity'
                                        : 'min-w-0 space-y-4'
                                }
                            >
                                {/*
                                 * Le tableau défile dans SA région : à 375 px,
                                 * la page ne défile jamais à l'horizontale.
                                 * La région est focalisable pour défiler au
                                 * clavier ; le conteneur propre de `Table`
                                 * cède son défilement à la région, sans quoi
                                 * les flèches ne feraient rien.
                                 */}
                                <div
                                    role="region"
                                    aria-label={t('admin.journal.list.heading')}
                                    tabIndex={0}
                                    className="max-w-full overflow-x-auto rounded-md focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none [&>[data-slot=table-container]]:overflow-visible"
                                >
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    {t(
                                                        'admin.journal.column.created_at',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.journal.column.actor',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.journal.column.action',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.journal.column.subject',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.journal.column.details',
                                                    )}
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {lines.data.map((line) => (
                                                <JournalRow
                                                    key={line.id}
                                                    line={line}
                                                />
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                <AdminPagination
                                    meta={lines.meta}
                                    href={(page) =>
                                        journalIndex({
                                            query: journalQuery(filters, {
                                                page,
                                            }),
                                        })
                                    }
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AdminJournalIndex.layout = { breadcrumbs };

type Translate = ReturnType<typeof useTranslations>['t'];

/** « Film n° 12 — Titre », « Comptes » : le sujet dit en une phrase. */
function subjectText(subject: AdminJournalSubject, t: Translate): string {
    const type = t(ADMIN_ACTION_SUBJECT_KEYS[subject.type]);

    if (subject.id === null) {
        return type;
    }

    if (subject.label !== null) {
        return t('admin.journal.subject.labelled', {
            type,
            id: String(subject.id),
            label: subject.label,
        });
    }

    return t('admin.journal.subject.numbered', {
        type,
        id: String(subject.id),
    });
}

/**
 * L'écran qui montre le sujet, s'il en est un : la fiche du film (d'un film
 * ou d'une image), la fiche du compte, le détail d'un balayage.
 */
function subjectHref(subject: AdminJournalSubject) {
    if (subject.id === null || !subject.exists) {
        return null;
    }

    switch (subject.type) {
        case 'movie':
        case 'frame':
            return subject.movie_id !== null
                ? catalogShow(subject.movie_id)
                : null;
        case 'user':
            return usersShow(subject.id);
        case 'import_run':
            return runShow(subject.id);
        default:
            return null;
    }
}

/** Une ligne du journal : qui, quoi, sur quoi, quand, et le complément. */
function JournalRow({ line }: { line: AdminJournalLine }) {
    const { t, locale } = useTranslations();
    const href = subjectHref(line.subject);
    const subject = subjectText(line.subject, t);
    const reservedActor =
        line.actor_id === null &&
        (line.actor_name === 'system' || line.actor_name === 'console')
            ? line.actor_name
            : null;

    return (
        <TableRow>
            <TableCell className="align-top whitespace-nowrap">
                {formatMoment(line.created_at, locale) ??
                    t('admin.common.unknown')}
            </TableCell>
            <TableCell className="min-w-40 align-top">
                {line.actor_id !== null ? (
                    <Link
                        href={usersShow(line.actor_id)}
                        className="font-medium text-foreground underline-offset-4 hover:underline focus-visible:underline"
                        aria-label={t('admin.a11y.journal_open_actor', {
                            name: line.actor_name,
                        })}
                    >
                        {line.actor_name}
                    </Link>
                ) : (
                    <span className="font-medium text-foreground">
                        {reservedActor !== null
                            ? t(RESERVED_ACTOR_KEYS[reservedActor])
                            : line.actor_name}
                    </span>
                )}
            </TableCell>
            <TableCell className="min-w-48 align-top">
                <div className="flex flex-col gap-1">
                    <span className="font-medium text-foreground">
                        {t(ADMIN_ACTION_KEYS[line.action])}
                    </span>
                    <code className="text-xs text-muted-foreground">
                        {line.action}
                    </code>
                </div>
            </TableCell>
            <TableCell className="min-w-48 align-top">
                {href !== null ? (
                    <Link
                        href={href}
                        className="text-foreground underline-offset-4 hover:underline focus-visible:underline"
                        aria-label={t('admin.a11y.journal_open_subject', {
                            subject,
                        })}
                    >
                        {subject}
                    </Link>
                ) : (
                    <span>{subject}</span>
                )}
            </TableCell>
            <TableCell className="min-w-64 align-top">
                <JournalLineDetails line={line} />
            </TableCell>
        </TableRow>
    );
}

/**
 * Motif, rôles, signalements, complément et conservation — ce qui existe
 * seulement. Les valeurs du complément sont des DONNÉES (codes, identifiants,
 * rectangles) : affichées telles quelles, jamais traduites.
 */
function JournalLineDetails({ line }: { line: AdminJournalLine }) {
    const { t, locale } = useTranslations();
    const entries = line.details === null ? [] : Object.entries(line.details);

    return (
        <dl className="grid gap-1 text-sm">
            {line.reason !== null && (
                <DetailItem label={t('admin.journal.line.reason')}>
                    <span className="break-words whitespace-pre-line">
                        {line.reason}
                    </span>
                </DetailItem>
            )}

            {line.role_before !== null && line.role_after !== null && (
                <DetailItem label={t('admin.journal.line.roles')}>
                    <span className="flex flex-wrap items-center gap-1">
                        <RoleBadge value={line.role_before} />
                        <span aria-hidden>→</span>
                        <RoleBadge value={line.role_after} />
                    </span>
                </DetailItem>
            )}

            {line.reports_count !== null && (
                <DetailItem label={t('admin.journal.line.reports')}>
                    {formatInteger(line.reports_count, locale)}
                </DetailItem>
            )}

            {entries.map(([key, value]) => {
                const labelKey = JOURNAL_DETAIL_KEYS[key];

                return (
                    <DetailItem
                        key={key}
                        label={labelKey !== undefined ? t(labelKey) : key}
                    >
                        <DetailValue value={value} />
                    </DetailItem>
                );
            })}

            {line.reason === null &&
                line.role_before === null &&
                line.reports_count === null &&
                entries.length === 0 && (
                    <span className="text-muted-foreground">
                        {t('admin.journal.line.no_details')}
                    </span>
                )}

            <DetailItem label={t('admin.journal.line.retention')}>
                <span className="text-muted-foreground">
                    {t(ADMIN_ACTION_RETENTION_KEYS[line.retention_class])}
                </span>
            </DetailItem>
        </dl>
    );
}

function DetailItem({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-wrap gap-x-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-foreground">{children}</dd>
        </div>
    );
}

/** Une valeur du complément : scalaire en clair, structure en JSON compact. */
function DetailValue({ value }: { value: unknown }) {
    const { t } = useTranslations();

    if (value === null || value === undefined) {
        return <span>{t('admin.common.none')}</span>;
    }

    if (typeof value === 'boolean') {
        return <span>{t(value ? 'admin.common.yes' : 'admin.common.no')}</span>;
    }

    if (typeof value === 'string' || typeof value === 'number') {
        return <span className="break-words">{String(value)}</span>;
    }

    return (
        <code className="text-xs break-all text-foreground">
            {JSON.stringify(value)}
        </code>
    );
}

/**
 * La query string du journal, filtres courants compris : paginer ne doit
 * jamais perdre un filtre. Une valeur nulle n'est pas envoyée.
 */
function journalQuery(
    filters: AdminJournalFilters,
    overrides: { page?: number },
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    for (const field of FILTER_FIELDS) {
        const value = filters[field];

        if (value !== null) {
            query[field] = value;
        }
    }

    if (overrides.page !== undefined && overrides.page > 1) {
        query.page = overrides.page;
    }

    return query;
}

/**
 * La barre de filtres — un `<Form>` en GET : toute la sélection vit dans la
 * query string, partageable et rechargeable. Les listes d'options sont celles
 * que `AdminJournalRequest` accepte, relues par le contrôleur.
 */
function JournalFiltersForm({
    filters,
    options,
    errors,
}: {
    filters: AdminJournalFilters;
    options: AdminJournalOptions;
    errors: Partial<Record<string, string>>;
}) {
    const { t } = useTranslations();

    const all: AdminSelectOption = { value: '', label: t('admin.common.all') };
    const described = (field: string, hint?: string): string | undefined =>
        [hint, errors[field] !== undefined ? `journal-${field}-error` : null]
            .filter((id): id is string => typeof id === 'string')
            .join(' ') || undefined;

    return (
        <Form
            {...JournalController.index.form()}
            options={{ preserveState: true, preserveScroll: true }}
            aria-label={t('admin.a11y.journal_filters_form')}
            className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <div className="space-y-1.5">
                <Label htmlFor="journal-actor">
                    {t('admin.journal.filters.actor')}
                </Label>
                <AdminSelect
                    id="journal-actor"
                    name="actor"
                    defaultValue={filters.actor ?? ''}
                    aria-invalid={errors.actor !== undefined}
                    aria-describedby={described('actor')}
                    options={[
                        all,
                        ...options.actors,
                        ...options.reserved_actors.map((value) => ({
                            value,
                            label: t(RESERVED_ACTOR_KEYS[value]),
                        })),
                    ]}
                />
                <AdminInputError
                    id="journal-actor-error"
                    message={errors.actor}
                />
            </div>

            <div className="space-y-1.5 xl:col-span-2">
                <Label htmlFor="journal-action">
                    {t('admin.journal.filters.action')}
                </Label>
                <AdminSelect
                    id="journal-action"
                    name="action"
                    defaultValue={filters.action ?? ''}
                    aria-invalid={errors.action !== undefined}
                    aria-describedby={described('action')}
                    options={[
                        all,
                        ...options.actions.map((value) => ({
                            value,
                            label: t(ADMIN_ACTION_KEYS[value]),
                        })),
                    ]}
                />
                <AdminInputError
                    id="journal-action-error"
                    message={errors.action}
                />
            </div>

            <div className="grid grid-cols-2 gap-2 md:col-span-2 xl:col-span-1">
                <div className="space-y-1.5">
                    <Label htmlFor="journal-from">
                        {t('admin.journal.filters.from')}
                    </Label>
                    <Input
                        id="journal-from"
                        name="from"
                        type="date"
                        defaultValue={filters.from ?? ''}
                        aria-invalid={errors.from !== undefined}
                        aria-describedby={described(
                            'from',
                            'journal-period-hint',
                        )}
                    />
                    <AdminInputError
                        id="journal-from-error"
                        message={errors.from}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="journal-to">
                        {t('admin.journal.filters.to')}
                    </Label>
                    <Input
                        id="journal-to"
                        name="to"
                        type="date"
                        defaultValue={filters.to ?? ''}
                        aria-invalid={errors.to !== undefined}
                        aria-describedby={described(
                            'to',
                            'journal-period-hint',
                        )}
                    />
                    <AdminInputError
                        id="journal-to-error"
                        message={errors.to}
                    />
                </div>
                <p
                    id="journal-period-hint"
                    className="col-span-2 text-xs text-muted-foreground"
                >
                    {t('admin.journal.filters.period_hint')}
                </p>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="journal-subject-type">
                    {t('admin.journal.filters.subject_type')}
                </Label>
                <AdminSelect
                    id="journal-subject-type"
                    name="subject_type"
                    defaultValue={filters.subject_type ?? ''}
                    aria-invalid={errors.subject_type !== undefined}
                    aria-describedby={described('subject_type')}
                    options={[
                        all,
                        ...options.subject_types.map((value) => ({
                            value,
                            label: t(ADMIN_ACTION_SUBJECT_KEYS[value]),
                        })),
                    ]}
                />
                <AdminInputError
                    id="journal-subject_type-error"
                    message={errors.subject_type}
                />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="journal-subject-id">
                    {t('admin.journal.filters.subject_id')}
                </Label>
                <Input
                    id="journal-subject-id"
                    name="subject_id"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    defaultValue={filters.subject_id ?? ''}
                    aria-invalid={errors.subject_id !== undefined}
                    aria-describedby={described(
                        'subject_id',
                        'journal-subject-id-hint',
                    )}
                />
                <p
                    id="journal-subject-id-hint"
                    className="text-xs text-muted-foreground"
                >
                    {t('admin.journal.filters.subject_id_hint')}
                </p>
                <AdminInputError
                    id="journal-subject_id-error"
                    message={errors.subject_id}
                />
            </div>

            {/*
             * L'historique d'un film se garde quand on affine l'auteur,
             * l'action ou la période ; « Voir tout le journal » le retire.
             */}
            {filters.movie !== null && (
                <input type="hidden" name="movie" value={filters.movie} />
            )}

            <div className="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-3">
                <Button type="submit" className="min-h-11">
                    {t('admin.journal.filters.submit')}
                </Button>
                <Button variant="ghost" className="min-h-11" asChild>
                    <Link href={journalIndex()}>
                        {t('admin.journal.filters.reset')}
                    </Link>
                </Button>
            </div>
        </Form>
    );
}
