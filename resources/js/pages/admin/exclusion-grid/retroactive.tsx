import { Head, Link } from '@inertiajs/react';
import { EyeOffIcon, ShieldAlertIcon } from 'lucide-react';
import { useId, useState } from 'react';
import ExclusionGridRetroactiveController from '@/actions/App/Http/Controllers/Admin/ExclusionGridRetroactiveController';
import { AvailabilityBadge } from '@/components/admin/admin-badges';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import {
    ConfirmGestureDialog,
    useGestureFocus,
} from '@/components/admin/confirm-gesture-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import type { Translator } from '@/hooks/use-translations';
import { formatInteger } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { show as catalogShow } from '@/routes/admin/catalog';
import type { AdminRetroactiveMovie } from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    available: boolean;
    version: number;
    levels: number[];
    movies: AdminRetroactiveMovie[];
    frames_total: number;
    reason_max_length: number;
    reference_length: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    {
        title: 'admin.nav.exclusion_grid',
        href: ExclusionGridRetroactiveController.show(),
    },
];

/**
 * Le geste rétroactif de grille (spec 20 § 7.7, ligne 33, D13 du 23/09,
 * L20-25), administrateur seul.
 *
 * L'écran dit d'abord **ce que le geste fera** : la version courante, les
 * niveaux visés, et, par film, le nombre d'images publiées qui sortiront du
 * jeu ; un film publié que le geste rendrait incomplet est nommé avant toute
 * confirmation, avec son `N` jouable maximal après le geste. Le geste
 * lui-même passe par une confirmation qui exige le motif juridique, recopié
 * sur chaque ligne du journal, et accepte la référence d'une demande de
 * retrait. Sous une version non rétroactive, l'écran le dit et n'offre rien.
 */
export default function AdminExclusionGridRetroactive({
    available,
    version,
    levels,
    movies,
    frames_total,
    reason_max_length,
    reference_length,
}: Props) {
    const { t, locale } = useTranslations();
    const focus = useGestureFocus();
    const [confirming, setConfirming] = useState(false);
    const reasonId = useId();
    const reasonHintId = useId();
    const referenceId = useId();
    const referenceHintId = useId();

    return (
        <>
            <Head title={t('admin.exclusion_grid.retroactive.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.exclusion_grid.retroactive.heading')}
                    description={t(
                        'admin.exclusion_grid.retroactive.description',
                    )}
                />

                {!available ? (
                    <AdminEmptyState
                        icon={ShieldAlertIcon}
                        title={t(
                            'admin.exclusion_grid.retroactive.unavailable',
                        )}
                        description={t(
                            'admin.exclusion_grid.retroactive.version',
                            { version },
                        )}
                    />
                ) : (
                    <Card>
                        <section
                            ref={focus.zoneRef}
                            tabIndex={-1}
                            aria-labelledby="retroactive-heading"
                            className="flex flex-col gap-6 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <CardHeader>
                                <AdminCardTitle id="retroactive-heading">
                                    {t(
                                        'admin.exclusion_grid.retroactive.version',
                                        { version },
                                    )}
                                </AdminCardTitle>
                                <CardDescription>
                                    {t(
                                        'admin.exclusion_grid.retroactive.levels',
                                        {
                                            levels: levels.join(
                                                t(
                                                    'admin.common.list_separator',
                                                ),
                                            ),
                                        },
                                    )}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {movies.length === 0 ? (
                                    <p
                                        role="status"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {t(
                                            'admin.exclusion_grid.retroactive.nothing',
                                        )}
                                    </p>
                                ) : (
                                    <>
                                        <p className="text-sm text-foreground">
                                            {t(
                                                'admin.exclusion_grid.retroactive.summary',
                                                {
                                                    frames: formatInteger(
                                                        frames_total,
                                                        locale,
                                                    ),
                                                    movies: formatInteger(
                                                        movies.length,
                                                        locale,
                                                    ),
                                                },
                                            )}
                                        </p>

                                        <RetroactiveTable movies={movies} />

                                        <Button
                                            type="button"
                                            variant="destructive"
                                            onClick={() => {
                                                focus.remember();
                                                setConfirming(true);
                                            }}
                                            className="min-h-11"
                                        >
                                            <EyeOffIcon aria-hidden />
                                            {t(
                                                'admin.exclusion_grid.retroactive.confirm',
                                            )}
                                        </Button>
                                    </>
                                )}
                            </CardContent>
                        </section>
                    </Card>
                )}
            </div>

            {confirming && (
                <ConfirmGestureDialog
                    open
                    form={ExclusionGridRetroactiveController.store.form()}
                    title={t('admin.exclusion_grid.retroactive.confirm_title')}
                    description={t(
                        'admin.exclusion_grid.retroactive.confirm_description',
                    )}
                    submitLabel={t('admin.exclusion_grid.retroactive.confirm')}
                    errorFields={['version', 'reason', 'takedown_reference']}
                    onClose={() => setConfirming(false)}
                    onReturnFocus={focus.restore}
                >
                    <input type="hidden" name="version" value={version} />

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={reasonId}>
                            {t('admin.exclusion_grid.retroactive.reason')}
                        </Label>
                        <Textarea
                            id={reasonId}
                            name="reason"
                            required
                            aria-required="true"
                            maxLength={reason_max_length}
                            aria-describedby={reasonHintId}
                        />
                        <p
                            id={reasonHintId}
                            className="text-sm text-muted-foreground"
                        >
                            {t('admin.exclusion_grid.retroactive.reason_hint')}
                        </p>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={referenceId}>
                            {t('admin.exclusion_grid.retroactive.reference')}
                        </Label>
                        <Input
                            id={referenceId}
                            name="takedown_reference"
                            maxLength={reference_length}
                            autoComplete="off"
                            aria-describedby={referenceHintId}
                            className="min-h-11"
                        />
                        <p
                            id={referenceHintId}
                            className="text-sm text-muted-foreground"
                        >
                            {t(
                                'admin.exclusion_grid.retroactive.reference_hint',
                            )}
                        </p>
                    </div>
                </ConfirmGestureDialog>
            )}
        </>
    );
}

AdminExclusionGridRetroactive.layout = { breadcrumbs };

/** Les films touchés, et ce que chacun devient après le geste. */
function RetroactiveTable({ movies }: { movies: AdminRetroactiveMovie[] }) {
    const { t, locale } = useTranslations();

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>
                        {t('admin.exclusion_grid.retroactive.column.movie')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.exclusion_grid.retroactive.column.frames')}
                    </TableHead>
                    <TableHead>
                        {t('admin.exclusion_grid.retroactive.column.after')}
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {movies.map((movie) => (
                    <TableRow key={movie.id}>
                        <TableCell className="align-top">
                            <div className="flex flex-wrap items-center gap-2">
                                <Link
                                    href={catalogShow(movie.id)}
                                    className="font-medium text-foreground underline-offset-4 hover:underline"
                                >
                                    {movieLabel(movie, t)}
                                </Link>
                                <AvailabilityBadge value={movie.availability} />
                            </div>
                        </TableCell>
                        <TableCell className="text-right align-top">
                            {formatInteger(movie.frames, locale)}
                        </TableCell>
                        <TableCell className="align-top">
                            <AfterGesture movie={movie} />
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/** Ce que le film devient : complet, ou incomplet avec son `N` jouable. */
function AfterGesture({ movie }: { movie: AdminRetroactiveMovie }) {
    const { t } = useTranslations();

    if (!movie.becomes_incomplete) {
        return (
            <span className="text-sm text-muted-foreground">
                {t('admin.exclusion_grid.retroactive.stays')}
            </span>
        );
    }

    return (
        <Alert variant="destructive" className="py-2">
            <AlertTitle className="text-sm">
                {movie.playable_up_to === null
                    ? t(
                          'admin.exclusion_grid.retroactive.incomplete_unplayable',
                      )
                    : t('admin.exclusion_grid.retroactive.incomplete', {
                          max: movie.playable_up_to,
                      })}
            </AlertTitle>
            <AlertDescription className="sr-only">
                {movieLabel(movie, t)}
            </AlertDescription>
        </Alert>
    );
}

/** « Titre (année) », ou le titre seul quand l'année est inconnue. */
function movieLabel(
    movie: { title_original: string; release_year: number | null },
    t: Translator['t'],
): string {
    return movie.release_year === null
        ? t('admin.movie.group.movie_without_year', {
              title: movie.title_original,
          })
        : t('admin.movie.group.movie', {
              title: movie.title_original,
              year: movie.release_year,
          });
}
