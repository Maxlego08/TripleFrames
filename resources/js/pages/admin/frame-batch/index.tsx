import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import { ImagesIcon, ListChecksIcon, UploadIcon } from 'lucide-react';
import { useEffect } from 'react';
import FrameBatchController from '@/actions/App/Http/Controllers/Admin/FrameBatchController';
import ImportIdsController from '@/actions/App/Http/Controllers/Admin/ImportIdsController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminLoadingState } from '@/components/admin/admin-loading-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { dashboard as adminDashboard } from '@/routes/admin';
import { show as catalogShow } from '@/routes/admin/catalog';
import { index as frameBatchIndex } from '@/routes/admin/frame_batch';
import { index as reviewIndex } from '@/routes/admin/review';
import type {
    AdminFrameBatch,
    AdminFrameBatchRow,
    AdminFrameBatchRowStatus,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    frame_batch: AdminFrameBatch | null;
    poll_seconds: number;
    max_kilobytes: number;
    /** Plafond d'identifiants d'un collage : les absents se collent par tranches. */
    paste_max_ids: number;
};

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

/** La gravité d'un état, portée par une variante de `Badge` (règle 5). */
const STATUS_VARIANTS: Record<AdminFrameBatchRowStatus, BadgeVariant> = {
    ready: 'default',
    missing: 'outline',
    locked: 'destructive',
};

const MS_PER_SECOND = 1000;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.frame_batch', href: frameBatchIndex() },
];

/**
 * Les lots d'images — spec 20 § 5.10, D57 du 05/10.
 *
 * Déposer un lot montre son aperçu à blanc ; « Importer » le confie à la
 * file, et l'écran sonde `frame_batch` jusqu'à la fin. Les images entrent en
 * brouillon : la revue et la publication restent à faire, film par film.
 */
export default function AdminFrameBatchIndex({
    frame_batch: batch,
    poll_seconds: pollSeconds,
    max_kilobytes: maxKilobytes,
    paste_max_ids: pasteMaxIds,
}: Props) {
    const { t } = useTranslations();

    const inFlight = batch?.status === 'pending' || batch?.status === 'running';

    const { start, stop } = usePoll(
        pollSeconds * MS_PER_SECOND,
        { only: ['frame_batch'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (inFlight) {
            start();
        } else {
            stop();
        }
    }, [inFlight, start, stop]);

    const rows = batch?.rows ?? [];
    const importable = rows.filter((row) => row.status === 'ready');
    const importableFrames = importable.reduce(
        (sum, row) => sum + row.frames,
        0,
    );
    const missing = rows.filter((row) => row.status === 'missing');
    const done = rows.filter((row) => row.done).length;

    return (
        <>
            <Head title={t('admin.frame_batch.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.frame_batch.heading')}
                    description={t('admin.frame_batch.description')}
                />

                {(batch === null ||
                    batch.status === 'completed' ||
                    batch.status === 'failed') && (
                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {batch === null
                                    ? t('admin.frame_batch.upload.title')
                                    : t('admin.frame_batch.new_batch')}
                            </AdminCardTitle>
                            <CardDescription>
                                {t('admin.frame_batch.upload.description', {
                                    max: maxKilobytes,
                                })}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <UploadForm />
                        </CardContent>
                    </Card>
                )}

                {batch === null && (
                    <AdminEmptyState
                        icon={ImagesIcon}
                        title={t('admin.frame_batch.empty')}
                    />
                )}

                {batch !== null && (
                    <Card>
                        <CardHeader>
                            <div className="flex flex-wrap items-center gap-2">
                                <AdminCardTitle>
                                    {t('admin.frame_batch.preview.title')}
                                </AdminCardTitle>
                                <Badge variant="secondary">
                                    {t(
                                        `admin.frame_batch.state.${batch.status}`,
                                    )}
                                </Badge>
                            </div>
                            <CardDescription>
                                {t('admin.frame_batch.preview.description', {
                                    movies: rows.length,
                                    frames: rows.reduce(
                                        (sum, row) => sum + row.frames,
                                        0,
                                    ),
                                })}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {batch.status !== 'previewed' && (
                                <p
                                    role="status"
                                    aria-live="polite"
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('admin.frame_batch.progress', {
                                        done,
                                        total: rows.length,
                                    })}
                                </p>
                            )}

                            {inFlight && (
                                <AdminLoadingState
                                    label={t(
                                        `admin.frame_batch.state.${batch.status}`,
                                    )}
                                    rows={2}
                                />
                            )}

                            {batch.error_key !== null && (
                                <Alert variant="destructive">
                                    <AlertDescription>
                                        {t(batch.error_key)}
                                    </AlertDescription>
                                </Alert>
                            )}

                            <BatchTable rows={rows} />

                            {batch.status === 'previewed' &&
                                (importable.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('admin.frame_batch.preview.nothing')}
                                    </p>
                                ) : (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            className="min-h-11"
                                            onClick={() =>
                                                router.post(
                                                    FrameBatchController.importMethod()
                                                        .url,
                                                    { token: batch.token },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <UploadIcon aria-hidden />
                                            {t(
                                                'admin.frame_batch.preview.import',
                                                { frames: importableFrames },
                                            )}
                                        </Button>
                                    </div>
                                ))}

                            {batch.status === 'previewed' &&
                                missing.length > 0 && (
                                    <MissingMovies
                                        rows={missing}
                                        pasteMaxIds={pasteMaxIds}
                                    />
                                )}

                            {batch.status === 'completed' && (
                                <Alert>
                                    <ListChecksIcon />
                                    <AlertDescription className="space-y-2">
                                        <p>{t('admin.frame_batch.next')}</p>
                                        <Button
                                            asChild
                                            variant="outline"
                                            className="min-h-11"
                                        >
                                            <Link href={reviewIndex()}>
                                                {t(
                                                    'admin.frame_batch.open_review',
                                                )}
                                            </Link>
                                        </Button>
                                    </AlertDescription>
                                </Alert>
                            )}
                        </CardContent>
                    </Card>
                )}

                {batch?.status === 'previewed' && (
                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.frame_batch.new_batch')}
                            </AdminCardTitle>
                        </CardHeader>
                        <CardContent>
                            <UploadForm />
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

function UploadForm() {
    const { t } = useTranslations();

    return (
        <Form
            {...FrameBatchController.store.form()}
            options={{ preserveScroll: true }}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1.5">
                        <Label htmlFor="frame-batch-file">
                            {t('admin.frame_batch.upload.label')}
                        </Label>
                        <Input
                            id="frame-batch-file"
                            name="batch"
                            type="file"
                            accept=".json,application/json"
                            required
                            aria-invalid={errors.batch !== undefined}
                            aria-describedby="frame-batch-file-error"
                        />
                        <AdminInputError
                            id="frame-batch-file-error"
                            message={errors.batch}
                        />
                    </div>
                    <Button
                        type="submit"
                        className="min-h-11"
                        disabled={processing}
                    >
                        {t('admin.frame_batch.upload.submit')}
                    </Button>
                </>
            )}
        </Form>
    );
}

function BatchTable({ rows }: { rows: AdminFrameBatchRow[] }) {
    const { t } = useTranslations();

    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>
                            {t('admin.frame_batch.columns.movie')}
                        </TableHead>
                        <TableHead>
                            {t('admin.frame_batch.columns.status')}
                        </TableHead>
                        <TableHead className="text-right">
                            {t('admin.frame_batch.columns.frames')}
                        </TableHead>
                        <TableHead className="text-right">
                            {t('admin.frame_batch.columns.known')}
                        </TableHead>
                        <TableHead>
                            {t('admin.frame_batch.columns.result')}
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => (
                        <TableRow key={row.tmdb_id}>
                            <TableCell>
                                {row.movie_id !== null ? (
                                    <Link
                                        href={catalogShow(row.movie_id)}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {row.title ?? `TMDB ${row.tmdb_id}`}
                                    </Link>
                                ) : (
                                    (row.title ?? `TMDB ${row.tmdb_id}`)
                                )}
                                <span className="block text-xs text-muted-foreground">
                                    TMDB {row.tmdb_id}
                                </span>
                            </TableCell>
                            <TableCell>
                                <Badge variant={STATUS_VARIANTS[row.status]}>
                                    {t(
                                        `admin.frame_batch.status.${row.status}`,
                                    )}
                                </Badge>
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {row.frames}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {row.known}
                            </TableCell>
                            <TableCell className="text-sm">
                                {row.status !== 'ready' ? (
                                    t('admin.frame_batch.result.none')
                                ) : row.done ? (
                                    <>
                                        {t('admin.frame_batch.result.summary', {
                                            added: row.added,
                                            skipped: row.skipped,
                                            refused: row.refused.length,
                                        })}
                                        {row.refused.length > 0 && (
                                            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-xs text-muted-foreground">
                                                {row.refused.map((message) => (
                                                    <li key={message}>
                                                        {message}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </>
                                ) : (
                                    t('admin.frame_batch.result.waiting')
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * Les films du lot absents du catalogue : un clic importe par le collage
 * (§ 3.3), voie d'exception, avec toutes ses gardes, **au plus
 * `pasteMaxIds` films à la fois** — un envoi plus large serait refusé en
 * entier. Une fois le collage terminé, revenir sur cet écran relit l'état
 * des films : les films collés deviennent importables, et le bouton propose
 * la tranche suivante (amendé le 06/10).
 */
function MissingMovies({
    rows,
    pasteMaxIds,
}: {
    rows: AdminFrameBatchRow[];
    pasteMaxIds: number;
}) {
    const { t } = useTranslations();
    const slice = rows.slice(0, pasteMaxIds);

    return (
        <Alert>
            <AlertTitle>{t('admin.frame_batch.missing.title')}</AlertTitle>
            <AlertDescription className="space-y-2">
                <p>{t('admin.frame_batch.missing.description')}</p>
                {rows.length > slice.length && (
                    <p>
                        {t('admin.frame_batch.missing.sliced', {
                            max: pasteMaxIds,
                            total: rows.length,
                        })}
                    </p>
                )}
                <Form {...ImportIdsController.store.form()}>
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="ids"
                                value={slice
                                    .map((row) => row.tmdb_id)
                                    .join('\n')}
                            />
                            <AdminInputError message={errors.ids} />
                            <Button
                                type="submit"
                                variant="outline"
                                className="min-h-11"
                                disabled={processing}
                            >
                                {t('admin.frame_batch.missing.import', {
                                    count: slice.length,
                                })}
                            </Button>
                        </>
                    )}
                </Form>
            </AlertDescription>
        </Alert>
    );
}

AdminFrameBatchIndex.layout = { breadcrumbs };
