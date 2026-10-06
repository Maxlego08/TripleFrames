import { PencilIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { useId, useState } from 'react';
import MovieAliasController from '@/actions/App/Http/Controllers/Admin/MovieAliasController';
import MovieTitleController from '@/actions/App/Http/Controllers/Admin/MovieTitleController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import {
    ConfirmGestureDialog,
    useGestureFocus,
} from '@/components/admin/confirm-gesture-dialog';
import { TextGestureDialog } from '@/components/admin/text-gesture-dialog';
import type { TextGesture } from '@/components/admin/text-gesture-dialog';
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
import { CONTENT_ORIGIN_KEYS, localeLabel } from '@/lib/admin-enum-keys';
import type {
    AdminAnswerKeyRow,
    AdminMovieAlias,
    AdminMovieTitle,
    AdminTextPreview,
    AdminTitleLocale,
    AnswerKeyKind,
} from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

/** Chaque nature de clé de réponse, en tête de cellule. */
const ANSWER_KEY_KIND_LABELS: Record<AnswerKeyKind, TranslationKey> = {
    title_original: 'admin.movie.answer_keys.kind.title_original',
    title_latin: 'admin.movie.answer_keys.kind.title_latin',
    title: 'admin.movie.answer_keys.kind.title',
    alias: 'admin.movie.answer_keys.kind.alias',
    prefix: 'admin.movie.answer_keys.kind.prefix',
    subtitle: 'admin.movie.answer_keys.kind.subtitle',
};

/** Les natures soumises à la règle de collision — miroir de `isCollisionChecked()`. */
const DERIVED_KINDS: AnswerKeyKind[] = ['prefix', 'subtitle'];

type TitleGesture =
    | { kind: 'edit'; locale: string; current: string | null }
    | { kind: 'remove'; locale: string };

/**
 * Les titres affichables (spec 20 § 9.1) : une ligne par locale ACTIVÉE,
 * présente ou absente — l'absence est une information, jamais comblée par
 * une autre langue —, avec « Corriger » (ou « Saisir ») et, pour une
 * correction de curateur seulement, « Retirer ». Les titres d'autres
 * locales de catalogue suivent, en lecture seule.
 *
 * Les gestes n'apparaissent que si `canCurate` (affichage seulement : chaque
 * route garde sa policy). Un geste réussi peut faire disparaître son bouton :
 * le focus revient alors à la carte, jamais au document.
 */
export function MovieTitlesCard({
    movieId,
    titles,
    titleLocales,
    canCurate,
    preview,
}: {
    movieId: number;
    titles: AdminMovieTitle[];
    titleLocales: AdminTitleLocale[];
    canCurate: boolean;
    preview: AdminTextPreview | null | undefined;
}) {
    const { t } = useTranslations();
    const headingId = useId();
    const focus = useGestureFocus();
    const [gesture, setGesture] = useState<TitleGesture | null>(null);

    const enabledLocales = titleLocales.map((row) => row.locale);
    const rows = titleLocales.map(({ locale, covered }) => ({
        locale,
        covered,
        title: titles.find((title) => title.locale === locale) ?? null,
    }));
    const others = titles.filter(
        (title) => !enabledLocales.includes(title.locale),
    );
    const anyMissing = rows.some((row) => row.title === null);
    const anyTmdb = rows.some((row) => row.title?.origin === 'tmdb');

    function open(next: TitleGesture): void {
        focus.remember();
        setGesture(next);
    }

    const close = (): void => setGesture(null);

    const textGesture: TextGesture | null =
        gesture?.kind === 'edit'
            ? {
                  kind: 'title',
                  locale: gesture.locale,
                  current: gesture.current,
              }
            : null;

    return (
        <Card>
            <section
                ref={focus.zoneRef}
                tabIndex={-1}
                aria-labelledby={headingId}
                className="flex flex-col gap-6 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <CardHeader>
                    <AdminCardTitle id={headingId}>
                        {t('admin.movie.titles.heading')}
                    </AdminCardTitle>
                    <CardDescription>
                        {t('admin.movie.titles.description')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('admin.movie.titles.column.locale')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.movie.titles.column.title')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.movie.titles.column.origin')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.movie.titles.column.edited_by')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.movie.titles.column.coverage')}
                                </TableHead>
                                {canCurate && (
                                    <TableHead>
                                        {t('admin.movie.titles.column.actions')}
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map(({ locale, covered, title }) => {
                                const language = localeLabel(locale, t);

                                return (
                                    <TableRow key={locale}>
                                        <TableCell>{language}</TableCell>
                                        <TableCell className="font-medium whitespace-normal text-foreground">
                                            {title === null ? (
                                                <span className="font-normal text-muted-foreground">
                                                    {t(
                                                        'admin.movie.titles.missing',
                                                    )}
                                                </span>
                                            ) : (
                                                title.title
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {title === null
                                                ? t('admin.common.none')
                                                : t(
                                                      CONTENT_ORIGIN_KEYS[
                                                          title.origin
                                                      ],
                                                  )}
                                        </TableCell>
                                        <TableCell>
                                            {title?.edited_by ??
                                                t('admin.common.none')}
                                        </TableCell>
                                        <TableCell>
                                            <CoverageBadge covered={covered} />
                                        </TableCell>
                                        {canCurate && (
                                            <TableCell>
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        aria-label={t(
                                                            title === null
                                                                ? 'admin.movie.titles.add_label'
                                                                : 'admin.movie.titles.edit_label',
                                                            {
                                                                locale: language,
                                                            },
                                                        )}
                                                        onClick={() =>
                                                            open({
                                                                kind: 'edit',
                                                                locale,
                                                                current:
                                                                    title?.title ??
                                                                    null,
                                                            })
                                                        }
                                                        className="min-h-11"
                                                    >
                                                        {title === null ? (
                                                            <PlusIcon
                                                                aria-hidden
                                                            />
                                                        ) : (
                                                            <PencilIcon
                                                                aria-hidden
                                                            />
                                                        )}
                                                        {title === null
                                                            ? t(
                                                                  'admin.movie.titles.add',
                                                              )
                                                            : t(
                                                                  'admin.movie.titles.edit',
                                                              )}
                                                    </Button>
                                                    {title?.origin ===
                                                        'curator' && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            aria-label={t(
                                                                'admin.movie.titles.remove_label',
                                                                {
                                                                    locale: language,
                                                                },
                                                            )}
                                                            onClick={() =>
                                                                open({
                                                                    kind: 'remove',
                                                                    locale,
                                                                })
                                                            }
                                                            className="min-h-11"
                                                        >
                                                            <Trash2Icon
                                                                aria-hidden
                                                            />
                                                            {t(
                                                                'admin.movie.titles.remove',
                                                            )}
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>

                    {anyMissing && (
                        <p className="max-w-prose text-sm text-muted-foreground">
                            {t('admin.movie.titles.missing_hint')}
                        </p>
                    )}

                    {canCurate && anyTmdb && (
                        <p className="max-w-prose text-sm text-muted-foreground">
                            {t('admin.movie.titles.tmdb_hint')}
                        </p>
                    )}

                    {others.length > 0 && (
                        <div className="space-y-2">
                            <h3 className="text-sm font-semibold text-foreground">
                                {t('admin.movie.titles.other_locales')}
                            </h3>
                            <p className="max-w-prose text-sm text-muted-foreground">
                                {t(
                                    'admin.movie.titles.other_locales_description',
                                )}
                            </p>
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
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {others.map((title) => (
                                        <TableRow key={title.locale}>
                                            <TableCell>
                                                {localeLabel(title.locale, t)}
                                            </TableCell>
                                            <TableCell className="whitespace-normal text-foreground">
                                                {title.title}
                                            </TableCell>
                                            <TableCell>
                                                {t(
                                                    CONTENT_ORIGIN_KEYS[
                                                        title.origin
                                                    ],
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </section>

            <TextGestureDialog
                gesture={textGesture}
                movieId={movieId}
                enabledLocales={enabledLocales}
                preview={preview}
                onClose={close}
                onReturnFocus={focus.restore}
            />

            {gesture?.kind === 'remove' && (
                <ConfirmGestureDialog
                    open
                    form={MovieTitleController.destroy.form({
                        movie: movieId,
                        locale: gesture.locale,
                    })}
                    title={t('admin.movie.titles.remove_dialog.title', {
                        locale: localeLabel(gesture.locale, t),
                    })}
                    description={t(
                        'admin.movie.titles.remove_dialog.description',
                    )}
                    submitLabel={t('admin.movie.titles.remove_dialog.submit')}
                    errorFields={['title']}
                    onClose={close}
                    onReturnFocus={focus.restore}
                />
            )}
        </Card>
    );
}

/**
 * La couverture d'une langue, lue dans `title_locale_mask` (spec 20 § 9.3) :
 * présente, absente, ou masque périmé — jamais lu comme valide.
 */
function CoverageBadge({ covered }: { covered: boolean | null }) {
    const { t } = useTranslations();

    if (covered === null) {
        return (
            <Badge variant="destructive">
                {t('admin.movie.titles.coverage.stale')}
            </Badge>
        );
    }

    return (
        <Badge variant={covered ? 'secondary' : 'outline'}>
            {covered
                ? t('admin.movie.titles.coverage.present')
                : t('admin.movie.titles.coverage.absent')}
        </Badge>
    );
}

/**
 * Les alias acceptés (spec 20 § 9.2) : « Ajouter un alias », derrière
 * l'aperçu de sa forme — l'écran avertit si le film l'accepte déjà —, et
 * « Retirer » sur tout alias, TMDB compris ; un alias TMDB retiré revient à
 * la resynchronisation suivante, et la confirmation le dit.
 */
export function MovieAliasesCard({
    movieId,
    aliases,
    enabledLocales,
    canCurate,
    preview,
    initialAlias = null,
}: {
    movieId: number;
    aliases: AdminMovieAlias[];
    enabledLocales: string[];
    canCurate: boolean;
    preview: AdminTextPreview | null | undefined;
    /**
     * Une réponse de joueur à proposer en alias (`?alias=` de la fiche,
     * depuis l'inspection d'une partie) : la boîte d'ajout s'ouvre déjà
     * remplie, l'aperçu d'ambiguïté restant à vérifier avant l'envoi.
     */
    initialAlias?: string | null;
}) {
    const { t } = useTranslations();
    const headingId = useId();
    const focus = useGestureFocus();
    const [adding, setAdding] = useState(canCurate && initialAlias !== null);
    const [removing, setRemoving] = useState<AdminMovieAlias | null>(null);

    function openAdd(): void {
        focus.remember();
        setAdding(true);
    }

    function openRemove(alias: AdminMovieAlias): void {
        focus.remember();
        setRemoving(alias);
    }

    return (
        <Card>
            <section
                ref={focus.zoneRef}
                tabIndex={-1}
                aria-labelledby={headingId}
                className="flex flex-col gap-6 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <CardHeader>
                    <AdminCardTitle id={headingId}>
                        {t('admin.movie.aliases.heading')}
                    </AdminCardTitle>
                    <CardDescription>
                        {t('admin.movie.aliases.description')}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {aliases.length === 0 ? (
                        <AdminEmptyState
                            title={t('admin.movie.aliases.empty')}
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('admin.movie.aliases.column.locale')}
                                    </TableHead>
                                    <TableHead>
                                        {t('admin.movie.aliases.column.alias')}
                                    </TableHead>
                                    <TableHead>
                                        {t('admin.movie.aliases.column.origin')}
                                    </TableHead>
                                    <TableHead>
                                        {t(
                                            'admin.movie.aliases.column.created_by',
                                        )}
                                    </TableHead>
                                    {canCurate && (
                                        <TableHead>
                                            {t(
                                                'admin.movie.aliases.column.actions',
                                            )}
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {aliases.map((alias) => (
                                    <TableRow key={alias.id}>
                                        <TableCell>
                                            {localeLabel(alias.locale, t)}
                                        </TableCell>
                                        <TableCell className="font-medium whitespace-normal text-foreground">
                                            {alias.alias}
                                        </TableCell>
                                        <TableCell>
                                            {t(
                                                CONTENT_ORIGIN_KEYS[
                                                    alias.origin
                                                ],
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {alias.created_by ??
                                                t('admin.common.none')}
                                        </TableCell>
                                        {canCurate && (
                                            <TableCell>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    aria-label={t(
                                                        'admin.movie.aliases.remove_label',
                                                        { alias: alias.alias },
                                                    )}
                                                    onClick={() =>
                                                        openRemove(alias)
                                                    }
                                                    className="min-h-11"
                                                >
                                                    <Trash2Icon aria-hidden />
                                                    {t(
                                                        'admin.movie.aliases.remove',
                                                    )}
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}

                    {canCurate && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={openAdd}
                            className="min-h-11"
                        >
                            <PlusIcon aria-hidden />
                            {t('admin.movie.aliases.add.heading')}
                        </Button>
                    )}
                </CardContent>
            </section>

            <TextGestureDialog
                gesture={
                    adding
                        ? { kind: 'alias', initial: initialAlias ?? undefined }
                        : null
                }
                movieId={movieId}
                enabledLocales={enabledLocales}
                preview={preview}
                onClose={() => setAdding(false)}
                onReturnFocus={focus.restore}
            />

            {removing !== null && (
                <ConfirmGestureDialog
                    open
                    form={MovieAliasController.destroy.form({
                        movie: movieId,
                        alias: removing.id,
                    })}
                    title={t('admin.movie.aliases.remove_dialog.title')}
                    description={t(
                        'admin.movie.aliases.remove_dialog.description',
                        { alias: removing.alias },
                    )}
                    notice={
                        removing.origin === 'tmdb'
                            ? t('admin.movie.aliases.remove_dialog.tmdb_notice')
                            : undefined
                    }
                    submitLabel={t('admin.movie.aliases.remove_dialog.submit')}
                    errorFields={[]}
                    onClose={() => setRemoving(null)}
                    onReturnFocus={focus.restore}
                />
            )}
        </Card>
    );
}

/**
 * Les formes acceptées du film, en lecture seule (spec 20 § 9.2) : ce que le
 * jeu compare vraiment à une réponse — forme normalisée, nature, et
 * acceptation. Une nature exacte est toujours acceptée ; un préfixe ou un
 * sous-titre porté par un autre film publié ne l'est plus seul.
 */
export function MovieAnswerKeysCard({
    answerKeys,
}: {
    answerKeys: AdminAnswerKeyRow[];
}) {
    const { t } = useTranslations();

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.movie.answer_keys.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.movie.answer_keys.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {answerKeys.length === 0 ? (
                    <AdminEmptyState
                        title={t('admin.movie.answer_keys.empty')}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('admin.movie.answer_keys.column.form')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.movie.answer_keys.column.kind')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.movie.answer_keys.column.status')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {answerKeys.map((key) => (
                                <TableRow key={key.form}>
                                    <TableCell className="font-mono whitespace-normal text-foreground">
                                        {key.form}
                                    </TableCell>
                                    <TableCell>
                                        {t(ANSWER_KEY_KIND_LABELS[key.kind])}
                                    </TableCell>
                                    <TableCell className="whitespace-normal">
                                        {!DERIVED_KINDS.includes(key.kind) ? (
                                            t('admin.movie.answer_keys.exact')
                                        ) : key.is_ambiguous ? (
                                            <Badge variant="destructive">
                                                {t(
                                                    'admin.movie.answer_keys.ambiguous',
                                                )}
                                            </Badge>
                                        ) : (
                                            t(
                                                'admin.movie.answer_keys.accepted',
                                            )
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}
