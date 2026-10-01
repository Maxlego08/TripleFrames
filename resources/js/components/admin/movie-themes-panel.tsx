import { Link } from '@inertiajs/react';
import { MinusIcon, PlusIcon, Undo2Icon } from 'lucide-react';
import { useId, useState } from 'react';
import MovieThemeController from '@/actions/App/Http/Controllers/Admin/MovieThemeController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminFieldList } from '@/components/admin/admin-field-list';
import type { AdminSelectOption } from '@/components/admin/admin-select';
import { AdminSelect } from '@/components/admin/admin-select';
import {
    ConfirmGestureDialog,
    useGestureFocus,
} from '@/components/admin/confirm-gesture-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
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
import type { Translator } from '@/hooks/use-translations';
import { THEME_KIND_KEYS, THEME_MEMBERSHIP_KEYS } from '@/lib/admin-enum-keys';
import { index as themesIndex } from '@/routes/admin/themes';
import type {
    AdminAvailableTheme,
    AdminMovieCollection,
    AdminMovieTheme,
    ThemeMembershipState,
} from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

/** Les trois gestes d'appartenance (spec 20 § 9.6). */
type ThemeGestureKind = 'add' | 'remove' | 'clear';

type ThemeGesture = {
    kind: ThemeGestureKind;
    themeId: number;
    label: string;
};

/** L'exception envoyée par chaque geste : vide annule l'exception. */
const GESTURE_STATE: Record<ThemeGestureKind, ThemeMembershipState | ''> = {
    add: 'added',
    remove: 'removed',
    clear: '',
};

const GESTURE_KEYS: Record<
    ThemeGestureKind,
    {
        title: TranslationKey;
        description: TranslationKey;
        submit: TranslationKey;
    }
> = {
    add: {
        title: 'admin.movie.themes.confirm.add.title',
        description: 'admin.movie.themes.confirm.add.description',
        submit: 'admin.movie.themes.confirm.add.submit',
    },
    remove: {
        title: 'admin.movie.themes.confirm.remove.title',
        description: 'admin.movie.themes.confirm.remove.description',
        submit: 'admin.movie.themes.confirm.remove.submit',
    },
    clear: {
        title: 'admin.movie.themes.confirm.clear.title',
        description: 'admin.movie.themes.confirm.clear.description',
        submit: 'admin.movie.themes.confirm.clear.submit',
    },
};

/**
 * Le bloc « Thèmes » de la fiche film (spec 20 § 9.6, D43 du 01/10).
 *
 * - Chaque appartenance : le thème, sa nature, son origine (règle,
 *   exception, ou les deux), l'appartenance effective, et les gestes selon
 *   l'état — « Retirer » sur une appartenance active, « Ajouter » sur une
 *   inactive, « Annuler l'exception » sur une exception. Chaque geste passe
 *   par une confirmation, et le serveur le journalise.
 * - « Ajouter un thème » sur tous les thèmes, publiés ou non (rappel
 *   « non publié »), privé de ceux où le film est déjà actif.
 * - La collection TMDB du film, la saga qui la désigne, ou le lien « Créer
 *   la saga depuis cette collection » vers l'écran des thèmes pré-rempli.
 *
 * Les gestes n'apparaissent que si `canCurate` ; le lien de création, que si
 * `canEditThemes`. Chaque route garde sa policy.
 */
export function MovieThemesPanel({
    movieId,
    themes,
    availableThemes,
    collection,
    canCurate,
    canEditThemes,
}: {
    movieId: number;
    themes: AdminMovieTheme[];
    availableThemes: AdminAvailableTheme[];
    collection: AdminMovieCollection | null;
    canCurate: boolean;
    canEditThemes: boolean;
}) {
    const { t } = useTranslations();
    const headingId = useId();
    const focus = useGestureFocus();
    const [gesture, setGesture] = useState<ThemeGesture | null>(null);

    function open(next: ThemeGesture): void {
        focus.remember();
        setGesture(next);
    }

    const close = (): void => setGesture(null);

    const activeIds = new Set(
        themes
            .filter((theme) => theme.is_active)
            .map((theme) => theme.theme_id),
    );
    const addable = availableThemes.filter((theme) => !activeIds.has(theme.id));

    return (
        <div className="space-y-6">
            <Card>
                <section
                    ref={focus.zoneRef}
                    tabIndex={-1}
                    aria-labelledby={headingId}
                    className="flex flex-col gap-6 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <CardHeader>
                        <AdminCardTitle id={headingId}>
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
                            <MembershipTable
                                themes={themes}
                                canCurate={canCurate}
                                onGesture={open}
                            />
                        )}
                    </CardContent>
                </section>
            </Card>

            {canCurate && (
                <AddThemeCard
                    themes={addable}
                    onAdd={(theme) =>
                        open({
                            kind: 'add',
                            themeId: theme.id,
                            label: theme.label,
                        })
                    }
                />
            )}

            <CollectionCard
                collection={collection}
                canEditThemes={canEditThemes}
            />

            {gesture !== null && (
                <ConfirmGestureDialog
                    open
                    form={MovieThemeController.update.form(movieId)}
                    title={t(GESTURE_KEYS[gesture.kind].title)}
                    description={t(GESTURE_KEYS[gesture.kind].description, {
                        theme: gesture.label,
                    })}
                    submitLabel={t(GESTURE_KEYS[gesture.kind].submit)}
                    errorFields={['theme_id', 'manual_state']}
                    onClose={close}
                    onReturnFocus={focus.restore}
                >
                    <input
                        type="hidden"
                        name="theme_id"
                        value={gesture.themeId}
                    />
                    <input
                        type="hidden"
                        name="manual_state"
                        value={GESTURE_STATE[gesture.kind]}
                    />
                </ConfirmGestureDialog>
            )}
        </div>
    );
}

/** Le tableau des appartenances, gestes compris. */
function MembershipTable({
    themes,
    canCurate,
    onGesture,
}: {
    themes: AdminMovieTheme[];
    canCurate: boolean;
    onGesture: (gesture: ThemeGesture) => void;
}) {
    const { t } = useTranslations();

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>
                        {t('admin.movie.themes.column.theme')}
                    </TableHead>
                    <TableHead>{t('admin.movie.themes.column.kind')}</TableHead>
                    <TableHead>
                        {t('admin.movie.themes.column.origin')}
                    </TableHead>
                    <TableHead>
                        {t('admin.movie.themes.column.active')}
                    </TableHead>
                    {canCurate && (
                        <TableHead>
                            {t('admin.movie.themes.column.actions')}
                        </TableHead>
                    )}
                </TableRow>
            </TableHeader>
            <TableBody>
                {themes.map((theme) => (
                    <TableRow key={theme.theme_id}>
                        <TableCell className="font-medium text-foreground">
                            <span className="flex flex-wrap items-center gap-2">
                                {theme.label}
                                {!theme.is_published && (
                                    <Badge
                                        variant="outline"
                                        title={t(
                                            'admin.movie.themes.unpublished_hint',
                                        )}
                                    >
                                        {t('admin.movie.themes.unpublished')}
                                    </Badge>
                                )}
                            </span>
                        </TableCell>
                        <TableCell>{t(THEME_KIND_KEYS[theme.kind])}</TableCell>
                        <TableCell>{originText(theme, t)}</TableCell>
                        <TableCell>
                            {theme.is_active ? (
                                <Badge variant="secondary">
                                    {t('admin.movie.themes.active.yes')}
                                </Badge>
                            ) : (
                                <Badge variant="outline">
                                    {t('admin.movie.themes.active.no')}
                                </Badge>
                            )}
                        </TableCell>
                        {canCurate && (
                            <TableCell>
                                <div className="flex flex-wrap gap-2">
                                    {theme.is_active ? (
                                        <GestureButton
                                            kind="remove"
                                            theme={theme}
                                            onGesture={onGesture}
                                        />
                                    ) : (
                                        <GestureButton
                                            kind="add"
                                            theme={theme}
                                            onGesture={onGesture}
                                        />
                                    )}
                                    {theme.manual_state !== null && (
                                        <GestureButton
                                            kind="clear"
                                            theme={theme}
                                            onGesture={onGesture}
                                        />
                                    )}
                                </div>
                            </TableCell>
                        )}
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

const BUTTON_KEYS: Record<
    ThemeGestureKind,
    { text: TranslationKey; label: TranslationKey }
> = {
    add: {
        text: 'admin.movie.themes.add',
        label: 'admin.movie.themes.add_label',
    },
    remove: {
        text: 'admin.movie.themes.remove',
        label: 'admin.movie.themes.remove_label',
    },
    clear: {
        text: 'admin.movie.themes.clear',
        label: 'admin.movie.themes.clear_label',
    },
};

function GestureButton({
    kind,
    theme,
    onGesture,
}: {
    kind: ThemeGestureKind;
    theme: AdminMovieTheme;
    onGesture: (gesture: ThemeGesture) => void;
}) {
    const { t } = useTranslations();
    const Icon =
        kind === 'add' ? PlusIcon : kind === 'remove' ? MinusIcon : Undo2Icon;

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            aria-label={t(BUTTON_KEYS[kind].label, { theme: theme.label })}
            onClick={() =>
                onGesture({
                    kind,
                    themeId: theme.theme_id,
                    label: theme.label,
                })
            }
            className="min-h-11"
        >
            <Icon aria-hidden />
            {t(BUTTON_KEYS[kind].text)}
        </Button>
    );
}

/** L'origine d'une appartenance : la règle, l'exception, ou les deux. */
function originText(theme: AdminMovieTheme, t: Translator['t']): string {
    if (theme.manual_state === null) {
        return t('admin.movie.themes.origin.auto');
    }

    const state = t(THEME_MEMBERSHIP_KEYS[theme.manual_state]);

    return theme.is_auto
        ? t('admin.movie.themes.origin.auto_manual', { state })
        : t('admin.movie.themes.origin.manual', { state });
}

/** « Ajouter un thème » : un thème choisi, puis la confirmation. */
function AddThemeCard({
    themes,
    onAdd,
}: {
    themes: AdminAvailableTheme[];
    onAdd: (theme: AdminAvailableTheme) => void;
}) {
    const { t } = useTranslations();
    const selectId = useId();
    const [selected, setSelected] = useState('');
    const chosen = themes.find((theme) => String(theme.id) === selected);

    const options: AdminSelectOption[] = [
        { value: '', label: t('admin.movie.themes.picker.placeholder') },
        ...themes.map((theme) => ({
            value: String(theme.id),
            label: t('admin.movie.themes.picker.option', {
                label: theme.is_published
                    ? theme.label
                    : t('admin.movie.themes.picker.unpublished', {
                          label: theme.label,
                      }),
                kind: t(THEME_KIND_KEYS[theme.kind]),
            }),
        })),
    ];

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.movie.themes.picker.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.movie.themes.picker.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {themes.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.movie.themes.picker.empty')}
                    </p>
                ) : (
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="flex flex-1 flex-col gap-1.5">
                            <Label htmlFor={selectId}>
                                {t('admin.movie.themes.picker.label')}
                            </Label>
                            <AdminSelect
                                id={selectId}
                                value={selected}
                                onChange={(event) =>
                                    setSelected(event.target.value)
                                }
                                options={options}
                                className="min-h-11"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            aria-disabled={chosen === undefined || undefined}
                            onClick={() => {
                                if (chosen !== undefined) {
                                    onAdd(chosen);
                                }
                            }}
                            className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                        >
                            <PlusIcon aria-hidden />
                            {t('admin.movie.themes.picker.submit')}
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * La collection TMDB du film et sa saga — ou « Créer la saga depuis cette
 * collection », qui ouvre l'écran des thèmes avec la nature et la
 * collection pré-remplies.
 */
function CollectionCard({
    collection,
    canEditThemes,
}: {
    collection: AdminMovieCollection | null;
    canEditThemes: boolean;
}) {
    const { t } = useTranslations();

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.movie.themes.collection.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.movie.themes.collection.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {collection === null ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.movie.themes.collection.none')}
                    </p>
                ) : (
                    <>
                        <AdminFieldList
                            fields={[
                                {
                                    label: t(
                                        'admin.movie.themes.collection.name',
                                    ),
                                    value: collection.name,
                                },
                                {
                                    label: t(
                                        'admin.movie.themes.collection.saga',
                                    ),
                                    value:
                                        collection.saga === null
                                            ? t(
                                                  'admin.movie.themes.collection.no_saga',
                                              )
                                            : collection.saga.is_published
                                              ? collection.saga.label
                                              : t(
                                                    'admin.movie.themes.picker.unpublished',
                                                    {
                                                        label: collection.saga
                                                            .label,
                                                    },
                                                ),
                                },
                            ]}
                        />
                        {collection.saga === null && canEditThemes && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={themesIndex({
                                        query: {
                                            create: 'saga',
                                            collection_id: collection.id,
                                        },
                                    })}
                                    className="min-h-11"
                                >
                                    <PlusIcon aria-hidden />
                                    {t(
                                        'admin.movie.themes.collection.create_saga',
                                    )}
                                </Link>
                            </Button>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
