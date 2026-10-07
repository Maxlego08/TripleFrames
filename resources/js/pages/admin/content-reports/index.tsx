import { Form, Head, Link } from '@inertiajs/react';
import {
    EyeOffIcon,
    FlagIcon,
    ImageOffIcon,
    TriangleAlertIcon,
    XIcon,
} from 'lucide-react';
import { useId, useState } from 'react';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import { ReasonDialog } from '@/components/admin/reason-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import { AVAILABILITY_KEYS } from '@/lib/admin-enum-keys';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { bank, show as catalogShow } from '@/routes/admin/catalog';
import {
    dismiss,
    index as contentReportsIndex,
    unpublishFrame,
    unpublishMovie,
} from '@/routes/admin/content-reports';
import type {
    AdminContentReportGroup,
    ContentReportFilter,
    ContentReportReason,
    ContentReportResolution,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    filter: ContentReportFilter;
    filters: ContentReportFilter[];
    counts: { open_targets: number; open_reports: number };
    groups: Paginated<AdminContentReportGroup>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.content_reports', href: contentReportsIndex() },
];

/** Tables de libellés écrites ici, jamais de clé construite (90 § 6.7). */
const FILTER_KEYS: Record<ContentReportFilter, TranslationKey> = {
    open: 'admin.content_report.filters.open',
    closed: 'admin.content_report.filters.closed',
};

const REASON_KEYS: Record<ContentReportReason, TranslationKey> = {
    wrong_movie: 'admin.content_report.reasons.wrong_movie',
    title_visible: 'admin.content_report.reasons.title_visible',
    wrong_level: 'admin.content_report.reasons.wrong_level',
    poor_quality: 'admin.content_report.reasons.poor_quality',
    offensive: 'admin.content_report.reasons.offensive',
    other: 'admin.content_report.reasons.other',
};

const RESOLUTION_KEYS: Record<ContentReportResolution, TranslationKey> = {
    movie_unpublished: 'admin.content_report.resolution.movie_unpublished',
    frame_unpublished: 'admin.content_report.resolution.frame_unpublished',
    dismissed: 'admin.content_report.resolution.dismissed',
    already_handled: 'admin.content_report.resolution.already_handled',
};

const SCOPE_KEYS: Record<AdminContentReportGroup['scope'], TranslationKey> = {
    frame: 'admin.content_report.scope.frame',
    movie: 'admin.content_report.scope.movie',
};

type GestureKind = 'unpublish_movie' | 'unpublish_frame' | 'dismiss';

/** Le geste ouvert : sur quelle cible, et lequel. */
type OpenGesture = { group: AdminContentReportGroup; kind: GestureKind } | null;

function movieLabel(movie: AdminContentReportGroup['movie']): string {
    return movie.release_year === null
        ? movie.title_original
        : `${movie.title_original} (${movie.release_year})`;
}

/**
 * La file des signalements de contenu par les joueurs — ligne 48, spec 20
 * § 11.6 (D63 du 07/10). **Curateur et au-delà.**
 *
 * Une carte par CIBLE — l'image, sinon le film entier —, les plus récemment
 * signalées d'abord : décompte par motif, derniers commentaires, vignette
 * de l'image, liens vers la fiche du film et sa banque d'images. Aucun
 * signalement ne change rien tout seul ; trois gestes, chacun clôt les
 * signalements ouverts de sa cible et s'inscrit au journal :
 *
 * - « Dépublier le film » : motif **obligatoire** (`ReasonDialog`) ;
 * - « Dépublier l'image » : motif facultatif, avertissement de perte de
 *   couverture 1-3-5 AVANT l'envoi ;
 * - « Ignorer » : motif facultatif.
 *
 * Pas de suspension : non livrée au J1 (L20-20). Chaque bouton n'est rendu
 * que si la policy l'accorde (`abilities`) ; le serveur la revérifie à
 * l'écriture.
 */
export default function AdminContentReportsIndex({
    filter,
    filters,
    counts,
    groups,
}: Props) {
    const { t, locale } = useTranslations();
    const [gesture, setGesture] = useState<OpenGesture>(null);
    const [trigger, setTrigger] = useState<HTMLElement | null>(null);

    const open = (group: AdminContentReportGroup, kind: GestureKind): void => {
        setTrigger(
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null,
        );
        setGesture({ group, kind });
    };

    const returnFocus = (): void => {
        if (trigger !== null && trigger.isConnected) {
            trigger.focus();
        }
    };

    const close = (): void => setGesture(null);

    return (
        <>
            <Head title={t('admin.content_report.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.content_report.heading')}
                    description={t('admin.content_report.description')}
                />

                <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                    <span>
                        {t('admin.content_report.counts.open_targets', {
                            count: formatInteger(counts.open_targets, locale),
                        })}
                    </span>
                    <span>
                        {t('admin.content_report.counts.open_reports', {
                            count: formatInteger(counts.open_reports, locale),
                        })}
                    </span>
                </p>

                <nav
                    className="flex flex-wrap gap-2"
                    aria-label={t('admin.content_report.filters.label')}
                >
                    {filters.map((value) => (
                        <Button
                            key={value}
                            variant={value === filter ? 'default' : 'outline'}
                            className="min-h-11"
                            asChild
                        >
                            <Link
                                href={contentReportsIndex({
                                    query: { filter: value },
                                })}
                                aria-current={
                                    value === filter ? 'page' : undefined
                                }
                                preserveScroll
                            >
                                {t(FILTER_KEYS[value])}
                            </Link>
                        </Button>
                    ))}
                </nav>

                {groups.data.length === 0 ? (
                    <AdminEmptyState
                        icon={FlagIcon}
                        title={
                            filter === 'open'
                                ? t('admin.content_report.empty')
                                : t('admin.content_report.empty_closed')
                        }
                    />
                ) : (
                    <section
                        aria-label={t('admin.content_report.list')}
                        className="flex flex-col gap-4"
                    >
                        <ul className="flex flex-col gap-4">
                            {groups.data.map((group) => (
                                <li key={`${group.scope}-${group.report_id}`}>
                                    <ReportGroupCard
                                        group={group}
                                        onGesture={(kind) => open(group, kind)}
                                    />
                                </li>
                            ))}
                        </ul>

                        <AdminPagination
                            meta={groups.meta}
                            href={(page) =>
                                contentReportsIndex({
                                    query: { filter, page },
                                })
                            }
                        />
                    </section>
                )}
            </div>

            <ReasonDialog
                open={gesture?.kind === 'unpublish_movie'}
                form={unpublishMovie.form(gesture?.group.report_id ?? 0)}
                title={t('admin.content_report.dialogs.unpublish_movie_title', {
                    title: gesture?.group.movie.title_original ?? '',
                })}
                description={t(
                    'admin.content_report.dialogs.unpublish_movie_description',
                )}
                reasonLabel={t('admin.content_report.reason_required_label')}
                submitLabel={t('admin.content_report.actions.unpublish_movie')}
                onClose={close}
                onReturnFocus={returnFocus}
            />

            <OptionalReasonDialog
                open={
                    gesture?.kind === 'unpublish_frame' ||
                    gesture?.kind === 'dismiss'
                }
                form={
                    gesture?.kind === 'dismiss'
                        ? dismiss.form(gesture.group.report_id)
                        : unpublishFrame.form(gesture?.group.report_id ?? 0)
                }
                title={
                    gesture?.kind === 'dismiss'
                        ? t('admin.content_report.dialogs.dismiss_title')
                        : t(
                              'admin.content_report.dialogs.unpublish_frame_title',
                          )
                }
                description={
                    gesture?.kind === 'dismiss'
                        ? t('admin.content_report.dialogs.dismiss_description')
                        : t(
                              'admin.content_report.dialogs.unpublish_frame_description',
                          )
                }
                notice={
                    gesture?.kind === 'unpublish_frame'
                        ? coverageNotice(gesture.group, t, locale)
                        : undefined
                }
                submitLabel={
                    gesture?.kind === 'dismiss'
                        ? t('admin.content_report.actions.dismiss')
                        : t('admin.content_report.actions.unpublish_frame')
                }
                onClose={close}
                onReturnFocus={returnFocus}
            />
        </>
    );
}

AdminContentReportsIndex.layout = { breadcrumbs };

type Translate = ReturnType<typeof useTranslations>['t'];

/** L'avertissement de perte de couverture 1-3-5 d'une image, ou rien. */
function coverageNotice(
    group: AdminContentReportGroup,
    t: Translate,
    locale: string,
): string | undefined {
    const warning = group.frame?.coverage_warning ?? null;

    if (warning === null) {
        return undefined;
    }

    return warning.playable_up_to === null
        ? t('admin.content_report.coverage_warning_unplayable')
        : t('admin.content_report.coverage_warning', {
              max: formatInteger(warning.playable_up_to, locale),
          });
}

type ReportGroupCardProps = {
    group: AdminContentReportGroup;
    onGesture: (kind: GestureKind) => void;
};

/** Une cible signalée et ses gestes. */
function ReportGroupCard({ group, onGesture }: ReportGroupCardProps) {
    const { t, locale } = useTranslations();
    const title = movieLabel(group.movie);
    const first = formatMoment(group.first_reported_at, locale) ?? '';
    const last = formatMoment(group.last_reported_at, locale) ?? '';
    const reasons = Object.entries(group.reasons) as [
        ContentReportReason,
        number,
    ][];
    const notice = coverageNotice(group, t, locale);
    const { abilities } = group;
    const anyGesture =
        abilities.unpublish_movie ||
        abilities.unpublish_frame ||
        abilities.dismiss;

    return (
        <Card>
            <CardHeader className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="outline">
                        {t(SCOPE_KEYS[group.scope])}
                    </Badge>
                    {group.frame !== null && (
                        <Badge variant="outline">
                            {t('admin.content_report.frame_level', {
                                level: formatInteger(
                                    group.frame.frame_level,
                                    locale,
                                ),
                            })}
                        </Badge>
                    )}
                    <Badge variant="secondary">
                        {t('admin.content_report.reports_count', {
                            count: formatInteger(group.reports_count, locale),
                        })}
                    </Badge>
                    {group.resolution !== null && (
                        <Badge>{t(RESOLUTION_KEYS[group.resolution])}</Badge>
                    )}
                </div>
                <AdminCardTitle>{title}</AdminCardTitle>
                <p className="text-sm text-muted-foreground">
                    {first === last
                        ? first
                        : t('admin.content_report.reported_between', {
                              first,
                              last,
                          })}
                    {group.resolved_at !== null && (
                        <>
                            {' · '}
                            {t('admin.content_report.resolved_on', {
                                date:
                                    formatMoment(group.resolved_at, locale) ??
                                    '',
                            })}
                        </>
                    )}
                </p>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                <div className="flex flex-col gap-4 md:flex-row">
                    {group.frame !== null && (
                        <div className="flex shrink-0 flex-col gap-1">
                            {group.frame.thumbnail_url === null ? (
                                <div className="flex aspect-video w-full items-center justify-center rounded-md border border-border text-muted-foreground md:w-64">
                                    <ImageOffIcon aria-hidden />
                                </div>
                            ) : (
                                <img
                                    src={group.frame.thumbnail_url}
                                    alt={t(
                                        'admin.content_report.thumbnail_alt',
                                        { title },
                                    )}
                                    loading="lazy"
                                    className="aspect-video w-full rounded-md border border-border object-cover md:w-64"
                                />
                            )}
                            <p className="text-xs text-muted-foreground">
                                {t('admin.content_report.availability', {
                                    state: t(
                                        AVAILABILITY_KEYS[
                                            group.frame.availability
                                        ],
                                    ),
                                })}
                            </p>
                        </div>
                    )}

                    <div className="flex min-w-0 flex-1 flex-col gap-3">
                        <div className="flex flex-col gap-1">
                            <h3 className="text-sm font-medium">
                                {t('admin.content_report.column.reasons')}
                            </h3>
                            <ul className="flex flex-wrap gap-2">
                                {reasons.map(([reason, count]) => (
                                    <li key={reason}>
                                        <Badge variant="outline">
                                            {t(REASON_KEYS[reason])}
                                            {' · '}
                                            {formatInteger(count, locale)}
                                        </Badge>
                                    </li>
                                ))}
                            </ul>
                        </div>

                        {group.comments.length > 0 && (
                            <div className="flex flex-col gap-1">
                                <h3 className="text-sm font-medium">
                                    {t('admin.content_report.column.comments')}
                                </h3>
                                <ul className="flex flex-col gap-2">
                                    {group.comments.map((comment, index) => (
                                        <li
                                            key={index}
                                            className="rounded-md border border-border p-2 text-sm"
                                        >
                                            <p className="break-words whitespace-pre-line">
                                                {comment.comment}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {t(REASON_KEYS[comment.reason])}
                                                {comment.reported_at !== null &&
                                                    ` · ${formatMoment(comment.reported_at, locale) ?? ''}`}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                            <span className="text-muted-foreground">
                                {t('admin.content_report.availability', {
                                    state: t(
                                        AVAILABILITY_KEYS[
                                            group.movie.availability
                                        ],
                                    ),
                                })}
                            </span>
                            <Link
                                href={catalogShow(group.movie.id)}
                                className="inline-flex min-h-11 items-center underline underline-offset-4"
                            >
                                {t('admin.content_report.movie_link')}
                            </Link>
                            <Link
                                href={bank(group.movie.id)}
                                className="inline-flex min-h-11 items-center underline underline-offset-4"
                            >
                                {t('admin.content_report.bank_link')}
                            </Link>
                        </p>
                    </div>
                </div>

                {abilities.unpublish_frame && notice !== undefined && (
                    <Alert>
                        <TriangleAlertIcon aria-hidden />
                        <AlertDescription>{notice}</AlertDescription>
                    </Alert>
                )}

                {anyGesture && (
                    <div className="flex flex-wrap justify-end gap-2">
                        {abilities.unpublish_frame && (
                            <Button
                                variant="outline"
                                className="min-h-11"
                                aria-label={t(
                                    'admin.content_report.gesture_label',
                                    {
                                        action: t(
                                            'admin.content_report.actions.unpublish_frame',
                                        ),
                                        title,
                                    },
                                )}
                                onClick={() => onGesture('unpublish_frame')}
                            >
                                <ImageOffIcon aria-hidden />
                                {t(
                                    'admin.content_report.actions.unpublish_frame',
                                )}
                            </Button>
                        )}
                        {abilities.unpublish_movie && (
                            <Button
                                variant="outline"
                                className="min-h-11"
                                aria-label={t(
                                    'admin.content_report.gesture_label',
                                    {
                                        action: t(
                                            'admin.content_report.actions.unpublish_movie',
                                        ),
                                        title,
                                    },
                                )}
                                onClick={() => onGesture('unpublish_movie')}
                            >
                                <EyeOffIcon aria-hidden />
                                {t(
                                    'admin.content_report.actions.unpublish_movie',
                                )}
                            </Button>
                        )}
                        {abilities.dismiss && (
                            <Button
                                variant="secondary"
                                className="min-h-11"
                                aria-label={t(
                                    'admin.content_report.gesture_label',
                                    {
                                        action: t(
                                            'admin.content_report.actions.dismiss',
                                        ),
                                        title,
                                    },
                                )}
                                onClick={() => onGesture('dismiss')}
                            >
                                <XIcon aria-hidden />
                                {t('admin.content_report.actions.dismiss')}
                            </Button>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

type OptionalReasonDialogProps = {
    open: boolean;
    form: RouteFormDefinition<'post'>;
    title: string;
    description: string;
    notice?: string;
    submitLabel: string;
    onClose: () => void;
    onReturnFocus: () => void;
};

/**
 * Un geste à motif FACULTATIF (dépublier l'image, ignorer), d'où une boîte
 * propre — `ReasonDialog` exige le sien. Même forme : focus piégé,
 * fermeture étiquetée `admin.a11y.close`, focus rendu au déclencheur ;
 * l'avertissement de couverture précède l'envoi.
 */
function OptionalReasonDialog({
    open,
    form,
    title,
    description,
    notice,
    submitLabel,
    onClose,
    onReturnFocus,
}: OptionalReasonDialogProps) {
    const { t } = useTranslations();
    const reasonId = useId();
    const errorId = useId();

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            <DialogContent
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    onReturnFocus();
                }}
                className="max-h-[90dvh] overflow-y-auto sm:max-w-lg [&>button:last-child]:hidden"
            >
                <DialogClose asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={t('admin.a11y.close')}
                        className="absolute top-3 right-3 min-h-11 min-w-11"
                    >
                        <XIcon aria-hidden />
                    </Button>
                </DialogClose>

                <Form
                    {...form}
                    noValidate
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader className="pr-12">
                                <DialogTitle>{title}</DialogTitle>
                                <DialogDescription>
                                    {description}
                                </DialogDescription>
                            </DialogHeader>

                            {notice !== undefined && (
                                <Alert>
                                    <TriangleAlertIcon aria-hidden />
                                    <AlertDescription>
                                        {notice}
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor={reasonId}>
                                    {t(
                                        'admin.content_report.reason_optional_label',
                                    )}
                                </Label>
                                <Textarea
                                    id={reasonId}
                                    name="reason"
                                    aria-invalid={
                                        errors.reason ? true : undefined
                                    }
                                    aria-describedby={errorId}
                                />
                                <AdminInputError
                                    id={errorId}
                                    message={errors.reason}
                                />
                            </div>

                            <AdminInputError message={errors.frame} />

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        {t('admin.common.cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    aria-busy={processing || undefined}
                                    className="min-h-11"
                                >
                                    {submitLabel}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
