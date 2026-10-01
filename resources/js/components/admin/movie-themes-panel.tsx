import { Link } from '@inertiajs/react';
import {
    MinusIcon,
    PlusIcon,
    SearchIcon,
    Undo2Icon,
    XIcon,
} from 'lucide-react';
import { useId, useState } from 'react';
import MovieThemeController from '@/actions/App/Http/Controllers/Admin/MovieThemeController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminFieldList } from '@/components/admin/admin-field-list';
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
    themeIds: number[];
    labels: string[];
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
    const dialogCopy = gesture === null ? null : themeGestureCopy(gesture, t);

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
                    key={addable.map((theme) => theme.id).join(':')}
                    themes={addable}
                    onAdd={(theme) =>
                        open({
                            kind: 'add',
                            themeIds: theme.map((item) => item.id),
                            labels: theme.map((item) => item.label),
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
                    title={dialogCopy?.title ?? ''}
                    description={dialogCopy?.description ?? ''}
                    submitLabel={dialogCopy?.submitLabel ?? ''}
                    errorFields={[
                        'theme_id',
                        'theme_ids',
                        'theme_ids.0',
                        'manual_state',
                    ]}
                    onClose={close}
                    onReturnFocus={focus.restore}
                >
                    {gesture.themeIds.length === 1 ? (
                        <input
                            type="hidden"
                            name="theme_id"
                            value={gesture.themeIds[0]}
                        />
                    ) : (
                        gesture.themeIds.map((themeId) => (
                            <input
                                key={themeId}
                                type="hidden"
                                name="theme_ids[]"
                                value={themeId}
                            />
                        ))
                    )}
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

function themeGestureCopy(
    gesture: ThemeGesture,
    t: Translator['t'],
): { title: string; description: string; submitLabel: string } {
    if (gesture.kind === 'add' && gesture.themeIds.length > 1) {
        const count = gesture.themeIds.length;

        return {
            title: t('admin.movie.themes.confirm.add_many.title'),
            description: t('admin.movie.themes.confirm.add_many.description', {
                count,
                themes: gesture.labels.join(', '),
            }),
            submitLabel: t('admin.movie.themes.confirm.add_many.submit', {
                count,
            }),
        };
    }

    const keys = GESTURE_KEYS[gesture.kind];

    return {
        title: t(keys.title),
        description: t(keys.description, { theme: gesture.labels[0] ?? '' }),
        submitLabel: t(keys.submit),
    };
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
                    themeIds: [theme.theme_id],
                    labels: [theme.label],
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

/** « Ajouter des thèmes » : les choix s'accumulent avant une confirmation. */
function AddThemeCard({
    themes,
    onAdd,
}: {
    themes: AdminAvailableTheme[];
    onAdd: (themes: AdminAvailableTheme[]) => void;
}) {
    const { t, tChoice } = useTranslations();
    const selectId = useId();
    const selectionId = useId();
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const selectedThemes = themes.filter((theme) =>
        selectedIds.includes(theme.id),
    );
    const remainingThemes = themes.filter(
        (theme) => !selectedIds.includes(theme.id),
    );

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
                    <div className="space-y-4">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={selectId}>
                                {t('admin.movie.themes.picker.label')}
                            </Label>
                            <ThemeSearchPicker
                                id={selectId}
                                themes={remainingThemes}
                                describedBy={selectionId}
                                onChoose={(theme) =>
                                    setSelectedIds([...selectedIds, theme.id])
                                }
                            />
                        </div>

                        <div
                            id={selectionId}
                            className="space-y-2 rounded-lg border bg-muted/20 p-3"
                            aria-live="polite"
                        >
                            <p className="text-sm font-medium text-foreground">
                                {t('admin.movie.themes.picker.selection')}
                            </p>
                            {selectedThemes.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'admin.movie.themes.picker.selection_empty',
                                    )}
                                </p>
                            ) : (
                                <ul className="grid gap-2 sm:grid-cols-2">
                                    {selectedThemes.map((theme) => (
                                        <li
                                            key={theme.id}
                                            className="flex min-h-11 items-center gap-2 rounded-md border bg-background pl-3 shadow-xs"
                                        >
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-foreground">
                                                    {theme.label}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {t(
                                                        THEME_KIND_KEYS[
                                                            theme.kind
                                                        ],
                                                    )}
                                                </span>
                                            </span>
                                            {!theme.is_published && (
                                                <Badge variant="outline">
                                                    {t(
                                                        'admin.movie.themes.unpublished',
                                                    )}
                                                </Badge>
                                            )}
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t(
                                                    'admin.movie.themes.picker.remove',
                                                    {
                                                        theme: theme.label,
                                                    },
                                                )}
                                                onClick={() =>
                                                    setSelectedIds(
                                                        selectedIds.filter(
                                                            (id) =>
                                                                id !== theme.id,
                                                        ),
                                                    )
                                                }
                                                className="min-h-11 min-w-11 shrink-0"
                                            >
                                                <XIcon aria-hidden />
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <Button
                            type="button"
                            disabled={selectedThemes.length === 0}
                            onClick={() => {
                                if (selectedThemes.length > 0) {
                                    onAdd(selectedThemes);
                                }
                            }}
                            className="min-h-11 w-full sm:w-auto"
                        >
                            <PlusIcon aria-hidden />
                            {tChoice(
                                'admin.movie.themes.picker.submit',
                                selectedThemes.length,
                                {
                                    count: selectedThemes.length,
                                },
                            )}
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function normalizeThemeSearch(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toLocaleLowerCase('fr')
        .trim();
}

/** Champ de recherche + liste d'options, utilisable à la souris et au clavier. */
function ThemeSearchPicker({
    id,
    themes,
    describedBy,
    onChoose,
}: {
    id: string;
    themes: AdminAvailableTheme[];
    describedBy: string;
    onChoose: (theme: AdminAvailableTheme) => void;
}) {
    const { t } = useTranslations();
    const listboxId = `${id}-options`;
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const normalizedQuery = normalizeThemeSearch(query);
    const filteredThemes = themes.filter((theme) => {
        if (normalizedQuery === '') {
            return true;
        }

        const haystack = normalizeThemeSearch(
            `${theme.label} ${t(THEME_KIND_KEYS[theme.kind])}`,
        );

        return haystack.includes(normalizedQuery);
    });
    const boundedActiveIndex = Math.min(
        activeIndex,
        Math.max(filteredThemes.length - 1, 0),
    );
    const activeTheme = filteredThemes[boundedActiveIndex];

    function choose(theme: AdminAvailableTheme): void {
        onChoose(theme);
        setQuery('');
        setActiveIndex(0);
        setOpen(themes.length > 1);
    }

    return (
        <div
            className="relative"
            onBlur={(event) => {
                if (
                    !(event.relatedTarget instanceof Node) ||
                    !event.currentTarget.contains(event.relatedTarget)
                ) {
                    setOpen(false);
                }
            }}
        >
            <SearchIcon
                aria-hidden
                className="pointer-events-none absolute top-3.5 left-3 z-10 size-4 text-muted-foreground"
            />
            <Input
                id={id}
                type="search"
                role="combobox"
                autoComplete="off"
                value={query}
                disabled={themes.length === 0}
                placeholder={t(
                    themes.length === 0
                        ? 'admin.movie.themes.picker.all_selected'
                        : 'admin.movie.themes.picker.placeholder',
                )}
                aria-autocomplete="list"
                aria-expanded={open && themes.length > 0}
                aria-controls={listboxId}
                aria-activedescendant={
                    open && activeTheme !== undefined
                        ? `${listboxId}-${activeTheme.id}`
                        : undefined
                }
                aria-describedby={describedBy}
                onFocus={() => setOpen(themes.length > 0)}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setActiveIndex(0);
                    setOpen(true);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        setOpen(true);
                        setActiveIndex((current) =>
                            Math.min(
                                current + 1,
                                Math.max(filteredThemes.length - 1, 0),
                            ),
                        );
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        setOpen(true);
                        setActiveIndex((current) => Math.max(current - 1, 0));
                    } else if (
                        event.key === 'Enter' &&
                        open &&
                        activeTheme !== undefined
                    ) {
                        event.preventDefault();
                        choose(activeTheme);
                    } else if (event.key === 'Escape') {
                        event.preventDefault();
                        setOpen(false);
                    }
                }}
                className="min-h-11 pr-3 pl-9"
            />

            {open && themes.length > 0 && (
                <div
                    id={listboxId}
                    role="listbox"
                    className="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                >
                    {filteredThemes.length === 0 ? (
                        <p className="px-3 py-3 text-sm text-muted-foreground">
                            {t('admin.movie.themes.picker.no_results')}
                        </p>
                    ) : (
                        <ul role="presentation">
                            {filteredThemes.map((theme, index) => (
                                <li key={theme.id} role="presentation">
                                    <button
                                        id={`${listboxId}-${theme.id}`}
                                        type="button"
                                        role="option"
                                        tabIndex={-1}
                                        aria-selected={
                                            index === boundedActiveIndex
                                        }
                                        onMouseDown={(event) =>
                                            event.preventDefault()
                                        }
                                        onMouseEnter={() =>
                                            setActiveIndex(index)
                                        }
                                        onClick={() => choose(theme)}
                                        className="flex min-h-11 w-full items-center gap-3 rounded-sm px-3 py-2 text-left text-sm outline-none hover:bg-accent hover:text-accent-foreground aria-selected:bg-accent aria-selected:text-accent-foreground"
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">
                                                {theme.label}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {t(THEME_KIND_KEYS[theme.kind])}
                                            </span>
                                        </span>
                                        {!theme.is_published && (
                                            <Badge variant="outline">
                                                {t(
                                                    'admin.movie.themes.unpublished',
                                                )}
                                            </Badge>
                                        )}
                                        <PlusIcon
                                            aria-hidden
                                            className="size-4 shrink-0"
                                        />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
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
