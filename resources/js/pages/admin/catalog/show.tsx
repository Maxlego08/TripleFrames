import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeftIcon,
    ExternalLinkIcon,
    ImageOffIcon,
    ImagesIcon,
} from 'lucide-react';
import {
    AvailabilityBadge,
    ContentFlagBadge,
    ExceptionBadge,
    ImportSourceBadge,
} from '@/components/admin/admin-badges';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import type { AdminField } from '@/components/admin/admin-field-list';
import { AdminFieldList } from '@/components/admin/admin-field-list';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminLevelDots } from '@/components/admin/admin-stat-tile';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslations } from '@/hooks/use-translations';
import {
    CERTIFICATION_COUNTRY_KEYS,
    CONTENT_ORIGIN_KEYS,
    EXCEPTION_MOTIVE_KEYS,
    FRAME_PROCESSING_KEYS,
    localeLabel,
    MOVIE_DIFFICULTY_KEYS,
    THEME_MEMBERSHIP_KEYS,
    TMDB_TAG_KIND_KEYS,
} from '@/lib/admin-enum-keys';
import {
    formatDay,
    formatInteger,
    formatMoment,
    levelsFromMask,
} from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { bank, index as catalogIndex } from '@/routes/admin/catalog';
import { show as runShow } from '@/routes/admin/import';
import type {
    AdminImportRunRow,
    AdminMovieAlias,
    AdminMovieCertification,
    AdminMovieAbilities,
    AdminMovieDetail,
    AdminMovieFrameRow,
    AdminMovieProjection,
    AdminMovieTag,
    AdminMovieTheme,
    AdminMovieTitle,
    ContentFlag,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    movie: AdminMovieDetail;
    projection: AdminMovieProjection | null;
    titles: AdminMovieTitle[];
    aliases: AdminMovieAlias[];
    certifications: AdminMovieCertification[];
    tags: AdminMovieTag[];
    themes: AdminMovieTheme[];
    frames: AdminMovieFrameRow[];
    import_run: AdminImportRunRow | null;
    abilities: AdminMovieAbilities;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.catalog', href: catalogIndex() },
    { title: 'admin.movie.title', href: catalogIndex() },
];

/** La fiche publique d'un film sur TMDB — un acte d'administration, jamais un chemin de jeu. */
const TMDB_MOVIE_URL = 'https://www.themoviedb.org/movie/';

/**
 * La fiche film — **lecture seule**, en onglets.
 *
 * Le bandeau de disponibilité vit HORS des onglets, et c'est le point de la
 * spec 10 § 3.1 : devant une mise en demeure, la fiche doit se suffire. État,
 * horodatage et motif du changement doivent être lisibles sans chercher dans
 * quel onglet ils se cachent.
 *
 * Le verdict de publiabilité énonce les DEUX conditions ensemble —
 * `content_flag = 'clear'` ET `levels_mask & 21 = 21` — et nomme la moitié qui
 * manque. Un « non publiable » sec laisserait un curateur cocher le contenu
 * d'un film dont ce sont les images qui manquent.
 *
 * Aucun geste d'écriture n'est offert ici : les images se curent dans
 * l'éditeur de la banque (spec 20 § 6), que le lien « Curer les images »
 * ouvre quand `abilities.curate` le permet — jamais sur un film retiré. Le
 * booléen ne fait que montrer le lien : la route garde sa policy.
 */
export default function AdminCatalogShow({
    movie,
    projection,
    titles,
    aliases,
    certifications,
    tags,
    themes,
    frames,
    import_run,
    abilities,
}: Props) {
    const { t, locale } = useTranslations();

    const genres = tags.filter((tag) => tag.tag_kind === 'genre');
    const companies = tags.filter((tag) => tag.tag_kind === 'company');

    // Les DEUX conditions de la spec 10 § 4.3, calculées une seule fois : le
    // verdict, sa gravité visuelle et la ligne qui l'explicite doivent tous
    // trois lire le même booléen.
    const coversPublishable = projection?.covers_publishable ?? false;
    const publishable = movie.content_flag === 'clear' && coversPublishable;

    return (
        <>
            <Head title={movie.title_original} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={movie.title_original}
                    description={t('admin.movie.read_only_notice')}
                    actions={
                        <>
                            <Badge variant="outline">
                                {t('admin.common.read_only')}
                            </Badge>
                            {abilities.curate && (
                                <Button size="sm" asChild>
                                    <Link href={bank(movie.id)}>
                                        <ImagesIcon aria-hidden />
                                        {t('admin.movie.curate')}
                                    </Link>
                                </Button>
                            )}
                            <Button variant="outline" size="sm" asChild>
                                <Link href={catalogIndex()}>
                                    <ArrowLeftIcon aria-hidden />
                                    {t('admin.movie.back')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                {/* Bandeau permanent : il ne se cache dans aucun onglet. */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.movie.availability.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <AvailabilityBadge value={movie.availability} />
                            <ContentFlagBadge value={movie.content_flag} />
                            {movie.is_import_exception && <ExceptionBadge />}
                        </div>

                        <AdminFieldList
                            fields={[
                                {
                                    label: t(
                                        'admin.movie.availability.changed_at',
                                    ),
                                    value:
                                        formatMoment(
                                            movie.availability_changed_at,
                                            locale,
                                        ) ?? t('admin.common.none'),
                                },
                                {
                                    label: t('admin.movie.availability.reason'),
                                    value:
                                        movie.availability_reason ??
                                        t('admin.common.none'),
                                },
                                {
                                    label: t(
                                        'admin.movie.availability.first_published_at',
                                    ),
                                    value:
                                        formatMoment(
                                            movie.first_published_at,
                                            locale,
                                        ) ?? t('admin.common.none'),
                                },
                                {
                                    label: t(
                                        'admin.movie.availability.content_verified_by',
                                    ),
                                    value:
                                        movie.content_verified_by ??
                                        t(
                                            'admin.movie.availability.not_verified',
                                        ),
                                },
                                {
                                    label: t(
                                        'admin.movie.availability.content_verified_at',
                                    ),
                                    value:
                                        formatMoment(
                                            movie.content_verified_at,
                                            locale,
                                        ) ??
                                        t(
                                            'admin.movie.availability.not_verified',
                                        ),
                                },
                            ]}
                        />

                        {/*
                         * La GRAVITÉ est portée par la variante, comme partout
                         * ailleurs dans ce lot (`admin-badges.tsx`). Un bandeau
                         * neutre sur un film bloqué contredirait le badge rouge
                         * juste au-dessus : deux signaux opposés dans le
                         * bandeau que la spec 10 § 3.1 veut lisible d'un coup
                         * d'œil devant une mise en demeure.
                         */}
                        <Alert
                            variant={publishable ? 'default' : 'destructive'}
                        >
                            <AlertTitle>
                                {t(
                                    publishabilityKey(
                                        movie.content_flag,
                                        coversPublishable,
                                    ),
                                )}
                            </AlertTitle>
                            <AlertDescription>
                                {t('admin.common.label_value', {
                                    label: t(
                                        'admin.movie.projection.covers_publishable',
                                    ),
                                    value: coversPublishable
                                        ? t('admin.common.yes')
                                        : t('admin.common.no'),
                                })}
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                </Card>

                <Tabs defaultValue="identity" className="w-full">
                    <div className="w-full overflow-x-auto">
                        <TabsList>
                            <TabsTrigger value="identity">
                                {t('admin.movie.tabs.identity')}
                            </TabsTrigger>
                            <TabsTrigger value="titles">
                                {t('admin.movie.tabs.titles')}
                            </TabsTrigger>
                            <TabsTrigger value="tags">
                                {t('admin.movie.tabs.tags')}
                            </TabsTrigger>
                            <TabsTrigger value="projection">
                                {t('admin.movie.tabs.projection')}
                            </TabsTrigger>
                            <TabsTrigger value="themes">
                                {t('admin.movie.tabs.themes')}
                            </TabsTrigger>
                            <TabsTrigger value="frames">
                                {t('admin.movie.tabs.frames')}
                            </TabsTrigger>
                            <TabsTrigger value="import">
                                {t('admin.movie.tabs.import')}
                            </TabsTrigger>
                        </TabsList>
                    </div>

                    {/* Identité */}
                    <TabsContent value="identity">
                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.movie.identity.heading')}
                                </AdminCardTitle>
                            </CardHeader>
                            <CardContent>
                                <AdminFieldList
                                    fields={identityFields(movie, locale, t)}
                                />
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Titres et alias */}
                    <TabsContent value="titles" className="space-y-6">
                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.movie.titles.heading')}
                                </AdminCardTitle>
                                <CardDescription>
                                    {t('admin.movie.titles.description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {titles.length === 0 ? (
                                    <AdminEmptyState
                                        title={t('admin.movie.titles.empty')}
                                    />
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.titles.column.locale',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.titles.column.title',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.titles.column.origin',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.titles.column.edited_by',
                                                    )}
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {titles.map((title) => (
                                                <TableRow
                                                    key={`${title.locale}-${title.title}`}
                                                >
                                                    <TableCell>
                                                        {localeLabel(
                                                            title.locale,
                                                            t,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-medium text-foreground">
                                                        {title.title}
                                                    </TableCell>
                                                    <TableCell>
                                                        {t(
                                                            CONTENT_ORIGIN_KEYS[
                                                                title.origin
                                                            ],
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {title.edited_by ??
                                                            t(
                                                                'admin.common.none',
                                                            )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.movie.aliases.heading')}
                                </AdminCardTitle>
                                <CardDescription>
                                    {t('admin.movie.aliases.description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {aliases.length === 0 ? (
                                    <AdminEmptyState
                                        title={t('admin.movie.aliases.empty')}
                                    />
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.aliases.column.locale',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.aliases.column.alias',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.aliases.column.origin',
                                                    )}
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {aliases.map((alias) => (
                                                <TableRow
                                                    key={`${alias.locale}-${alias.alias}`}
                                                >
                                                    <TableCell>
                                                        {localeLabel(
                                                            alias.locale,
                                                            t,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-medium text-foreground">
                                                        {alias.alias}
                                                    </TableCell>
                                                    <TableCell>
                                                        {t(
                                                            CONTENT_ORIGIN_KEYS[
                                                                alias.origin
                                                            ],
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Étiquettes TMDB et classifications */}
                    <TabsContent value="tags" className="space-y-6">
                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.movie.tags.heading')}
                                </AdminCardTitle>
                                <CardDescription>
                                    {t('admin.movie.tags.description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {tags.length === 0 ? (
                                    <AdminEmptyState
                                        title={t('admin.movie.tags.empty')}
                                    />
                                ) : (
                                    <>
                                        <RawTagList
                                            label={t('admin.movie.tags.genres')}
                                            kindLabel={t(
                                                TMDB_TAG_KIND_KEYS.genre,
                                            )}
                                            tags={genres}
                                            empty={t('admin.common.none')}
                                        />
                                        <RawTagList
                                            label={t(
                                                'admin.movie.tags.companies',
                                            )}
                                            kindLabel={t(
                                                TMDB_TAG_KIND_KEYS.company,
                                            )}
                                            tags={companies}
                                            empty={t('admin.common.none')}
                                        />
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.movie.certifications.heading')}
                                </AdminCardTitle>
                                <CardDescription>
                                    {t(
                                        'admin.movie.certifications.description',
                                    )}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {certifications.length === 0 ? (
                                    <AdminEmptyState
                                        title={t(
                                            'admin.movie.certifications.empty',
                                        )}
                                    />
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.certifications.column.country',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.certifications.column.certification',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.certifications.column.released_on',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.certifications.column.read_at',
                                                    )}
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {certifications.map(
                                                (certification) => (
                                                    <TableRow
                                                        key={
                                                            certification.country
                                                        }
                                                    >
                                                        <TableCell>
                                                            {t(
                                                                CERTIFICATION_COUNTRY_KEYS[
                                                                    certification
                                                                        .country
                                                                ],
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <span className="flex flex-wrap items-center gap-2">
                                                                <span className="font-medium text-foreground">
                                                                    {
                                                                        certification.certification
                                                                    }
                                                                </span>
                                                                {certification.is_restrictive && (
                                                                    <Badge variant="destructive">
                                                                        {t(
                                                                            'admin.movie.certifications.restrictive',
                                                                        )}
                                                                    </Badge>
                                                                )}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell>
                                                            {formatDay(
                                                                certification.released_on,
                                                                locale,
                                                            ) ??
                                                                t(
                                                                    'admin.common.unknown',
                                                                )}
                                                        </TableCell>
                                                        <TableCell>
                                                            {formatMoment(
                                                                certification.read_at,
                                                                locale,
                                                            ) ??
                                                                t(
                                                                    'admin.common.unknown',
                                                                )}
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )}
                                        </TableBody>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Projection */}
                    <TabsContent value="projection">
                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.movie.projection.heading')}
                                </AdminCardTitle>
                                <CardDescription>
                                    {t('admin.movie.projection.description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {projection === null ? (
                                    <AdminEmptyState
                                        title={t(
                                            'admin.movie.projection.missing',
                                        )}
                                    />
                                ) : (
                                    <>
                                        {!projection.title_mask_current && (
                                            <Alert>
                                                <AlertTitle>
                                                    {t(
                                                        'admin.movie.projection.title_mask_stale',
                                                    )}
                                                </AlertTitle>
                                            </Alert>
                                        )}

                                        <AdminLevelDots
                                            levels={levelsFromMask(
                                                projection.levels_mask,
                                            )}
                                        />

                                        <AdminFieldList
                                            fields={projectionFields(
                                                projection,
                                                locale,
                                                t,
                                            )}
                                        />
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Thèmes */}
                    <TabsContent value="themes">
                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.movie.themes.heading')}
                                </AdminCardTitle>
                                <CardDescription>
                                    {t('admin.movie.themes.description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {themes.length === 0 ? (
                                    <AdminEmptyState
                                        title={t('admin.movie.themes.empty')}
                                    />
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.themes.column.theme',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.themes.column.auto',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.themes.column.manual',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'admin.movie.themes.column.active',
                                                    )}
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {themes.map((theme) => (
                                                <TableRow key={theme.key}>
                                                    <TableCell className="font-medium text-foreground">
                                                        {theme.label}
                                                    </TableCell>
                                                    <TableCell>
                                                        {theme.is_auto
                                                            ? t(
                                                                  'admin.common.yes',
                                                              )
                                                            : t(
                                                                  'admin.common.no',
                                                              )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {theme.manual_state ===
                                                        null
                                                            ? t(
                                                                  'admin.common.none',
                                                              )
                                                            : t(
                                                                  THEME_MEMBERSHIP_KEYS[
                                                                      theme
                                                                          .manual_state
                                                                  ],
                                                              )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {theme.is_active
                                                            ? t(
                                                                  'admin.common.yes',
                                                              )
                                                            : t(
                                                                  'admin.common.no',
                                                              )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Banque d'images */}
                    <TabsContent value="frames">
                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.movie.frames.heading')}
                                </AdminCardTitle>
                                <CardDescription>
                                    {t('admin.movie.frames.description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {frames.length === 0 ? (
                                    <AdminEmptyState
                                        icon={ImageOffIcon}
                                        title={t('admin.movie.frames.empty')}
                                        description={t(
                                            'admin.movie.frames.editor_hint',
                                        )}
                                        action={
                                            abilities.curate ? (
                                                <EditorLink
                                                    movieId={movie.id}
                                                    label={t(
                                                        'admin.movie.frames.open_editor',
                                                    )}
                                                />
                                            ) : undefined
                                        }
                                    />
                                ) : (
                                    <>
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>
                                                        {t(
                                                            'admin.movie.frames.column.level',
                                                        )}
                                                    </TableHead>
                                                    <TableHead>
                                                        {t(
                                                            'admin.movie.frames.column.availability',
                                                        )}
                                                    </TableHead>
                                                    <TableHead>
                                                        {t(
                                                            'admin.movie.frames.column.processing',
                                                        )}
                                                    </TableHead>
                                                    <TableHead>
                                                        {t(
                                                            'admin.movie.frames.column.error',
                                                        )}
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {frames.map((frame, index) => (
                                                    <TableRow key={index}>
                                                        <TableCell className="tabular-nums">
                                                            {frame.frame_level}
                                                        </TableCell>
                                                        <TableCell>
                                                            <AvailabilityBadge
                                                                value={
                                                                    frame.availability
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell>
                                                            {t(
                                                                FRAME_PROCESSING_KEYS[
                                                                    frame
                                                                        .processing_state
                                                                ],
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-muted-foreground">
                                                            {frame.processing_error ===
                                                            null
                                                                ? t(
                                                                      'admin.common.none',
                                                                  )
                                                                : t(
                                                                      frame.processing_error,
                                                                  )}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>

                                        <p className="max-w-prose text-xs text-muted-foreground">
                                            {t(
                                                'admin.movie.frames.editor_hint',
                                            )}
                                        </p>

                                        {abilities.curate && (
                                            <EditorLink
                                                movieId={movie.id}
                                                label={t(
                                                    'admin.movie.frames.open_editor',
                                                )}
                                            />
                                        )}
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Provenance */}
                    <TabsContent value="import" className="space-y-6">
                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.movie.import.heading')}
                                </AdminCardTitle>
                                <CardDescription>
                                    {t('admin.movie.import.description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <ImportSourceBadge
                                        value={movie.import_source}
                                    />
                                    {movie.is_import_exception && (
                                        <ExceptionBadge />
                                    )}
                                </div>

                                <AdminFieldList
                                    fields={[
                                        {
                                            label: t(
                                                'admin.movie.import.exception',
                                            ),
                                            value: movie.is_import_exception ? (
                                                <ExceptionMotiveList
                                                    movie={movie}
                                                />
                                            ) : (
                                                t(
                                                    'admin.movie.import.exception_none',
                                                )
                                            ),
                                        },
                                        {
                                            label: t('admin.movie.import.run'),
                                            value:
                                                import_run === null ? (
                                                    t(
                                                        'admin.movie.import.run_missing',
                                                    )
                                                ) : (
                                                    <Link
                                                        href={runShow(
                                                            import_run.id,
                                                        )}
                                                        className="underline underline-offset-4"
                                                        aria-label={t(
                                                            'admin.a11y.open_run',
                                                            {
                                                                id: import_run.id,
                                                            },
                                                        )}
                                                    >
                                                        {t(
                                                            'admin.import.run.heading',
                                                            {
                                                                id: import_run.id,
                                                            },
                                                        )}
                                                    </Link>
                                                ),
                                        },
                                    ]}
                                />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.movie.curation.heading')}
                                </AdminCardTitle>
                            </CardHeader>
                            <CardContent>
                                <AdminFieldList
                                    fields={[
                                        {
                                            label: t(
                                                'admin.movie.curation.curated_by',
                                            ),
                                            value:
                                                movie.curated_by ??
                                                t(
                                                    'admin.movie.curation.not_curated',
                                                ),
                                        },
                                        {
                                            label: t(
                                                'admin.movie.curation.active_seconds',
                                            ),
                                            value: formatInteger(
                                                movie.curation_active_seconds,
                                                locale,
                                            ),
                                        },
                                    ]}
                                />
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

AdminCatalogShow.layout = { breadcrumbs };

type Translate = (
    key: TranslationKey,
    replacements?: Record<string, string | number>,
) => string;

/**
 * Le verdict de publiabilité, **les deux conditions ensemble**.
 *
 * Nommer la moitié qui manque n'est pas une politesse : un « non publiable »
 * sec enverrait un curateur cocher le contenu d'un film dont ce sont les
 * images qui manquent, et inversement.
 */
function publishabilityKey(
    contentFlag: ContentFlag,
    coversPublishable: boolean,
): TranslationKey {
    const contentOk = contentFlag === 'clear';

    if (contentOk && coversPublishable) {
        return 'admin.movie.availability.publishable';
    }

    if (!contentOk && !coversPublishable) {
        return 'admin.movie.availability.blocked_by_both';
    }

    return contentOk
        ? 'admin.movie.availability.blocked_by_levels'
        : 'admin.movie.availability.blocked_by_content';
}

function identityFields(
    movie: AdminMovieDetail,
    locale: string,
    t: Translate,
): AdminField[] {
    return [
        {
            label: t('admin.movie.identity.tmdb_id'),
            value:
                movie.tmdb_id === null ? (
                    t('admin.movie.identity.tmdb_missing')
                ) : (
                    <a
                        href={`${TMDB_MOVIE_URL}${movie.tmdb_id}`}
                        target="_blank"
                        rel="noreferrer noopener"
                        aria-label={t('admin.movie.identity.tmdb_link')}
                        className="inline-flex items-center gap-1 underline underline-offset-4"
                    >
                        {movie.tmdb_id}
                        <ExternalLinkIcon aria-hidden className="size-3" />
                    </a>
                ),
        },
        {
            label: t('admin.movie.identity.title_original'),
            value: movie.title_original,
        },
        {
            label: t('admin.movie.identity.title_latin'),
            value: movie.title_original_latin ?? t('admin.common.none'),
        },
        {
            label: t('admin.movie.identity.original_language'),
            value: localeLabel(movie.original_language, t),
        },
        {
            label: t('admin.movie.identity.release_year'),
            value: movie.release_year ?? t('admin.common.unknown'),
        },
        {
            label: t('admin.movie.identity.vote_count'),
            value: formatInteger(movie.vote_count, locale),
        },
        {
            label: t('admin.movie.identity.adult'),
            value: movie.adult ? t('admin.common.yes') : t('admin.common.no'),
        },
        {
            label: t('admin.movie.identity.collection'),
            value: movie.collection_name ?? t('admin.common.none'),
        },
        {
            label: t('admin.movie.identity.group'),
            value: movie.group_label ?? t('admin.common.none'),
        },
        {
            label: t('admin.movie.identity.difficulty'),
            value: difficultyLabel(movie.movie_difficulty, t),
        },
        {
            label: t('admin.movie.identity.difficulty_derived'),
            value: difficultyLabel(movie.movie_difficulty_derived, t),
        },
        {
            label: t('admin.movie.identity.difficulty_override'),
            value: difficultyLabel(movie.movie_difficulty_override, t),
        },
        {
            label: t('admin.movie.identity.created_at'),
            value:
                formatMoment(movie.created_at, locale) ??
                t('admin.common.unknown'),
        },
        {
            label: t('admin.movie.identity.updated_at'),
            value:
                formatMoment(movie.updated_at, locale) ??
                t('admin.common.unknown'),
        },
    ];
}

function difficultyLabel(
    value: AdminMovieDetail['movie_difficulty'],
    t: Translate,
): string {
    return value === null
        ? t('admin.common.none')
        : t(MOVIE_DIFFICULTY_KEYS[value]);
}

function projectionFields(
    projection: AdminMovieProjection,
    locale: string,
    t: Translate,
): AdminField[] {
    const perLevel: AdminField[] = [
        projection.level_1_variants,
        projection.level_2_variants,
        projection.level_3_variants,
        projection.level_4_variants,
        projection.level_5_variants,
    ].map((variants, index) => ({
        label: t('admin.movie.projection.level_variants', {
            level: index + 1,
        }),
        value: formatInteger(variants, locale),
    }));

    return [
        {
            label: t('admin.movie.projection.levels_count'),
            value: formatInteger(projection.levels_count, locale),
        },
        {
            label: t('admin.movie.projection.levels_mask'),
            value: projection.levels_mask,
        },
        ...perLevel,
        {
            label: t('admin.movie.projection.variants_total'),
            value: formatInteger(projection.variants_total, locale),
        },
        {
            label: t('admin.movie.projection.playable_at'),
            value:
                projection.playable_at.length === 0
                    ? t('admin.common.none')
                    : projection.playable_at.join(
                          t('admin.common.list_separator'),
                      ),
        },
        {
            label: t('admin.movie.projection.covers_publishable'),
            value: projection.covers_publishable
                ? t('admin.common.yes')
                : t('admin.common.no'),
        },
        {
            label: t('admin.movie.projection.title_mask'),
            value: projection.title_locale_mask,
        },
        {
            label: t('admin.movie.projection.title_mask_version'),
            value: projection.title_mask_version,
        },
        {
            label: t('admin.movie.projection.recomputed_at'),
            value:
                formatMoment(projection.recomputed_at, locale) ??
                t('admin.common.none'),
        },
    ];
}

/** Le lien vers l'éditeur de la banque d'images (spec 20 § 4.3). */
function EditorLink({ movieId, label }: { movieId: number; label: string }) {
    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={bank(movieId)}>
                <ImagesIcon aria-hidden />
                {label}
            </Link>
        </Button>
    );
}

/** Les motifs d'entrée par exception, cumulables et donc lus indépendamment. */
function ExceptionMotiveList({ movie }: { movie: AdminMovieDetail }) {
    const { t } = useTranslations();

    const motives: TranslationKey[] = [];

    if (movie.exception_for_language) {
        motives.push(EXCEPTION_MOTIVE_KEYS.language);
    }

    if (movie.exception_for_vote_count) {
        motives.push(EXCEPTION_MOTIVE_KEYS.vote_count);
    }

    if (movie.exception_for_release_year) {
        motives.push(EXCEPTION_MOTIVE_KEYS.release_year);
    }

    if (motives.length === 0) {
        return <>{t('admin.catalog.exception.badge')}</>;
    }

    return (
        <ul className="space-y-0.5">
            {motives.map((motive) => (
                <li key={motive}>{t(motive)}</li>
            ))}
        </ul>
    );
}

/**
 * Des identifiants TMDB **bruts**, et l'écran le dit.
 *
 * Le schéma ne stocke aucun libellé de genre ni de société (§ 3.6) : afficher
 * « 878 » est la vérité, inventer « Science-fiction » serait un libellé qui
 * n'existe nulle part et qu'aucune resynchronisation ne tiendrait à jour.
 */
function RawTagList({
    label,
    kindLabel,
    tags,
    empty,
}: {
    label: string;
    kindLabel: string;
    tags: AdminMovieTag[];
    empty: string;
}) {
    return (
        <div className="space-y-1.5">
            <h3 className="text-xs font-medium text-muted-foreground">
                {label}
            </h3>
            {tags.length === 0 ? (
                <p className="text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ul className="flex flex-wrap gap-2">
                    {tags.map((tag) => (
                        <li key={`${tag.tag_kind}-${tag.tmdb_tag_id}`}>
                            <Badge variant="outline">
                                <span className="sr-only">{kindLabel} </span>
                                {tag.tmdb_tag_id}
                            </Badge>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
