import { Form, Head, Link } from '@inertiajs/react';
import { ClapperboardIcon, SendIcon } from 'lucide-react';
import MovieBatchPublishController from '@/actions/App/Http/Controllers/Admin/MovieBatchPublishController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import {
    ambiguityLineText,
    PUBLICATION_BLOCKER_KEYS,
} from '@/components/admin/publish-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import type { Translator } from '@/hooks/use-translations';
import { formatInteger } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import {
    index as catalogIndex,
    show as catalogShow,
} from '@/routes/admin/catalog';
import type { AdminReadyBatch } from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    batch: AdminReadyBatch;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.catalog', href: catalogIndex() },
    {
        title: 'admin.catalog.publish_ready.title',
        href: MovieBatchPublishController.create(),
    },
];

/** « Titre (année) », ou le titre seul quand l'année est inconnue. */
function movieLabel(
    movie: { title_original: string; release_year: number | null },
    t: Translator['t'],
): string {
    return movie.release_year === null
        ? t('admin.catalog.publish_ready.movie_without_year', {
              title: movie.title_original,
          })
        : t('admin.catalog.publish_ready.movie', {
              title: movie.title_original,
              year: movie.release_year,
          });
}

/**
 * « Publier les films prêts » (spec 20 § 8.1 bis, ligne 47, D59 du 06/10).
 *
 * Le lot est lu AVANT tout envoi : chaque film prêt, avec les formes que sa
 * publication rendra ambiguës — le lot entier compté comme publié —, puis
 * les films prêts mis de côté avec leur condition manquante. L'envoi poste
 * les identifiants et l'empreinte de CE lot ; si un film n'est plus prêt, si
 * un autre l'est devenu ou si un avertissement a changé, le serveur refuse
 * tout sans rien écrire, et l'écran se recharge sur le lot à jour avec le
 * refus affiché.
 */
export default function AdminCatalogReady({ batch }: Props) {
    const { t, tChoice, locale } = useTranslations();
    const count = batch.movies.length;

    return (
        <>
            <Head title={t('admin.catalog.publish_ready.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.catalog.publish_ready.title')}
                    description={t('admin.catalog.publish_ready.description')}
                />

                {count === 0 && batch.skipped.length === 0 ? (
                    <AdminEmptyState
                        icon={ClapperboardIcon}
                        title={t('admin.catalog.publish_ready.empty_title')}
                        description={t(
                            'admin.catalog.publish_ready.empty_description',
                        )}
                    />
                ) : (
                    <>
                        {count > 0 && (
                            <Card>
                                <CardHeader>
                                    <AdminCardTitle>
                                        {t(
                                            'admin.catalog.publish_ready.list_heading',
                                        )}
                                    </AdminCardTitle>
                                    <CardDescription>
                                        {t(
                                            'admin.catalog.publish_ready.ambiguity_description',
                                        )}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Form
                                        {...MovieBatchPublishController.store.form()}
                                        noValidate
                                        className="flex flex-col gap-4"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <AdminInputError
                                                    message={
                                                        Object.values(errors)[0]
                                                    }
                                                />

                                                <ol className="flex flex-col gap-3">
                                                    {batch.movies.map(
                                                        (movie) => (
                                                            <li
                                                                key={movie.id}
                                                                className="space-y-1 rounded-md border border-border px-3 py-2 text-sm"
                                                            >
                                                                <input
                                                                    type="hidden"
                                                                    name="movie_ids[]"
                                                                    value={
                                                                        movie.id
                                                                    }
                                                                />
                                                                <Link
                                                                    href={catalogShow(
                                                                        movie.id,
                                                                    )}
                                                                    className="font-medium text-foreground underline-offset-4 hover:underline"
                                                                >
                                                                    {movieLabel(
                                                                        movie,
                                                                        t,
                                                                    )}
                                                                </Link>
                                                                {movie.lines
                                                                    .length ===
                                                                0 ? (
                                                                    <p className="text-muted-foreground">
                                                                        {t(
                                                                            'admin.catalog.publish_ready.none_ambiguous',
                                                                        )}
                                                                    </p>
                                                                ) : (
                                                                    <ul className="list-disc space-y-1 pl-5 text-foreground">
                                                                        {movie.lines.map(
                                                                            (
                                                                                line,
                                                                            ) => (
                                                                                <li
                                                                                    key={
                                                                                        line.form
                                                                                    }
                                                                                >
                                                                                    {ambiguityLineText(
                                                                                        line,
                                                                                        t,
                                                                                    )}
                                                                                </li>
                                                                            ),
                                                                        )}
                                                                    </ul>
                                                                )}
                                                            </li>
                                                        ),
                                                    )}
                                                </ol>

                                                <input
                                                    type="hidden"
                                                    name="ambiguity_digest"
                                                    value={batch.digest}
                                                />

                                                <Button
                                                    type="submit"
                                                    aria-disabled={
                                                        processing || undefined
                                                    }
                                                    aria-busy={
                                                        processing || undefined
                                                    }
                                                    onClick={(event) => {
                                                        if (processing) {
                                                            event.preventDefault();
                                                        }
                                                    }}
                                                    className="min-h-11 self-start aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                                >
                                                    <SendIcon aria-hidden />
                                                    {tChoice(
                                                        'admin.catalog.publish_ready.submit',
                                                        count,
                                                        {
                                                            count: formatInteger(
                                                                count,
                                                                locale,
                                                            ),
                                                        },
                                                    )}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </CardContent>
                            </Card>
                        )}

                        {batch.skipped.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <AdminCardTitle>
                                        {t(
                                            'admin.catalog.publish_ready.skipped_heading',
                                        )}
                                    </AdminCardTitle>
                                    <CardDescription>
                                        {t(
                                            'admin.catalog.publish_ready.skipped_description',
                                        )}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ul className="flex flex-col gap-2 text-sm">
                                        {batch.skipped.map((movie) => (
                                            <li key={movie.id}>
                                                <Link
                                                    href={catalogShow(movie.id)}
                                                    className="font-medium text-foreground underline-offset-4 hover:underline"
                                                >
                                                    {movieLabel(movie, t)}
                                                </Link>
                                                <ul className="list-disc pl-5 text-muted-foreground">
                                                    {movie.blockers.map(
                                                        (blocker) => (
                                                            <li key={blocker}>
                                                                {t(
                                                                    PUBLICATION_BLOCKER_KEYS[
                                                                        blocker
                                                                    ],
                                                                )}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </li>
                                        ))}
                                    </ul>
                                </CardContent>
                            </Card>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

AdminCatalogReady.layout = { breadcrumbs };
