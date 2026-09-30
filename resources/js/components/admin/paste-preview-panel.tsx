import { Form, Link, usePoll } from '@inertiajs/react';
import { ListChecksIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import ImportIdsController from '@/actions/App/Http/Controllers/Admin/ImportIdsController';
import ImportPreviewController from '@/actions/App/Http/Controllers/Admin/ImportPreviewController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { AdminLoadingState } from '@/components/admin/admin-loading-state';
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
import {
    EXCEPTION_MOTIVE_KEYS,
    IMPORT_DECISION_KEYS,
} from '@/lib/admin-enum-keys';
import { show as catalogShow } from '@/routes/admin/catalog';
import type {
    AdminPastePreview,
    AdminPastePreviewRow,
    ImportDecision,
} from '@/types/admin';

type Props = {
    preview: AdminPastePreview;
    /** Faux sur l'écran de recherche : son limiteur ne doit pas payer le sondage. */
    live: boolean;
    pollSeconds: number;
    tmdbConfigured: boolean;
    /** Un collage est ouvert : « Importer ces films » attend sa fin. */
    pasteBusy: boolean;
};

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

/**
 * La gravité d'un sort, portée par une variante de `Badge` — des tokens de
 * thème, jamais une couleur (règle 5). Un refus de contenu est un refus.
 */
const DECISION_VARIANTS: Record<ImportDecision, BadgeVariant> = {
    simulated: 'default',
    imported: 'default',
    resynchronized: 'secondary',
    duplicate: 'secondary',
    skipped_by_filter: 'outline',
    refused_content: 'destructive',
    refused_withdrawn: 'destructive',
    not_found: 'outline',
};

const MS_PER_SECOND = 1000;

/**
 * L'aperçu à blanc d'un collage — spec 20 § 3.3.
 *
 * Le résultat vit en cache, lisible par son seul auteur ; l'écran le sonde
 * par un rechargement partiel de `paste_preview` sur `admin.import.index`
 * — la route sans limiteur — jusqu'à complétude. Les refus de contenu
 * s'affichent comme des lignes refusées, avec leur motif ; l'aperçu reste
 * INDICATIF : « Importer ces films » ouvre un collage réel, qui rejoue toutes
 * les gardes.
 */
export function PastePreviewPanel({
    preview,
    live,
    pollSeconds,
    tmdbConfigured,
    pasteBusy,
}: Props) {
    const { t } = useTranslations();
    const [pollFailed, setPollFailed] = useState(false);

    const inFlight =
        preview.status === 'pending' || preview.status === 'running';

    /*
     * Rendre `false` à `onNetworkError` retient l'événement global : une
     * coupure pendant le sondage s'affiche DANS la carte, jamais en un toast
     * par tick.
     */
    const { start, stop } = usePoll(
        pollSeconds * MS_PER_SECOND,
        () => ({
            only: ['paste_preview'],
            onSuccess: () => setPollFailed(false),
            onHttpException: () => setPollFailed(true),
            onNetworkError: () => {
                setPollFailed(true);

                return false;
            },
        }),
        { autoStart: false },
    );

    useEffect(() => {
        if (live && inFlight) {
            start();
        } else {
            stop();
        }
    }, [live, inFlight, start, stop]);

    const collage = preview.identifiers.join('\n');

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.import.preview.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.import.preview.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <p
                    role="status"
                    aria-live="polite"
                    className="text-sm text-muted-foreground"
                >
                    {t('admin.import.preview.progress', {
                        processed: preview.processed,
                        total: preview.total,
                    })}
                </p>

                {preview.status === 'pending' && (
                    <AdminLoadingState
                        label={t('admin.import.preview.pending')}
                        rows={2}
                    />
                )}

                {preview.status === 'running' && (
                    <AdminLoadingState
                        label={t('admin.import.preview.running')}
                        rows={2}
                    />
                )}

                {pollFailed && (
                    <AdminErrorState
                        title={t('admin.common.error')}
                        description={t('admin.common.offline')}
                        retryLabel={t('admin.common.refresh')}
                        onRetry={() => {
                            setPollFailed(false);
                            start();
                        }}
                    />
                )}

                {preview.status === 'failed' && (
                    <AdminErrorState
                        title={t(
                            preview.error_key ?? 'admin.import.preview.failed',
                        )}
                        action={
                            <PreviewRetry
                                collage={collage}
                                disabled={!tmdbConfigured}
                            />
                        }
                    />
                )}

                {preview.rows.length === 0 ? (
                    preview.status === 'completed' && (
                        <AdminEmptyState
                            icon={ListChecksIcon}
                            title={t('admin.import.preview.empty')}
                        />
                    )
                ) : (
                    <PreviewTable rows={preview.rows} />
                )}

                {preview.status === 'completed' && (
                    <div className="space-y-2">
                        <Alert>
                            <ListChecksIcon aria-hidden />
                            <AlertDescription>
                                {t('admin.import.preview.import_hint')}
                            </AlertDescription>
                        </Alert>
                        {pasteBusy && (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.import.search.import_busy')}
                            </p>
                        )}
                        <Form
                            {...ImportIdsController.store.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="ids"
                                        value={collage}
                                    />
                                    <Button
                                        type="submit"
                                        className="min-h-11"
                                        disabled={
                                            processing ||
                                            pasteBusy ||
                                            !tmdbConfigured
                                        }
                                    >
                                        {t('admin.import.preview.import')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/** « Réessayer l'aperçu » : le même collage, reposté tel quel. */
function PreviewRetry({
    collage,
    disabled,
}: {
    collage: string;
    disabled: boolean;
}) {
    const { t } = useTranslations();

    return (
        <Form
            {...ImportPreviewController.store.form()}
            options={{ preserveScroll: true }}
            className="mt-2"
        >
            {({ processing }) => (
                <>
                    <input type="hidden" name="ids" value={collage} />
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        className="min-h-11"
                        disabled={processing || disabled}
                    >
                        {t('admin.import.preview.retry')}
                    </Button>
                </>
            )}
        </Form>
    );
}

/** Une ligne par identifiant, dans l'ordre du collage. */
function PreviewTable({ rows }: { rows: AdminPastePreviewRow[] }) {
    const { t } = useTranslations();

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>
                        {t('admin.import.preview.column.tmdb_id')}
                    </TableHead>
                    <TableHead>
                        {t('admin.import.preview.column.title')}
                    </TableHead>
                    <TableHead>
                        {t('admin.import.preview.column.year')}
                    </TableHead>
                    <TableHead>
                        {t('admin.import.preview.column.decision')}
                    </TableHead>
                    <TableHead>
                        {t('admin.import.preview.column.detail')}
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow key={row.tmdb_id}>
                        <TableCell className="align-top tabular-nums">
                            {row.tmdb_id}
                        </TableCell>
                        <TableCell className="align-top">
                            {row.movie_id === null ? (
                                (row.title_original ??
                                t('admin.common.unknown'))
                            ) : (
                                <Link
                                    href={catalogShow(row.movie_id)}
                                    className="underline underline-offset-4"
                                >
                                    {row.title_original ??
                                        t('admin.common.unknown')}
                                </Link>
                            )}
                        </TableCell>
                        <TableCell className="align-top tabular-nums">
                            {row.release_year ?? t('admin.common.unknown')}
                        </TableCell>
                        <TableCell className="align-top">
                            <Badge variant={DECISION_VARIANTS[row.decision]}>
                                {t(IMPORT_DECISION_KEYS[row.decision])}
                            </Badge>
                        </TableCell>
                        <TableCell className="align-top">
                            <PreviewDetail row={row} />
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * Le motif d'une ligne : la clé de refus et ses substitutions, ou, pour un
 * film qui serait importé, le marquage d'exception et ses motifs.
 */
function PreviewDetail({ row }: { row: AdminPastePreviewRow }) {
    const { t } = useTranslations();

    if (row.reason_key !== null) {
        return (
            <p className="max-w-prose text-sm text-foreground">
                {t(row.reason_key, row.reason_replacements)}
            </p>
        );
    }

    if (!row.is_import_exception) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-1">
            <Badge variant="outline">
                {t('admin.import.preview.exception')}
            </Badge>
            {row.motives.map((motive) => (
                <Badge key={motive} variant="secondary">
                    {t(EXCEPTION_MOTIVE_KEYS[motive])}
                </Badge>
            ))}
        </div>
    );
}
