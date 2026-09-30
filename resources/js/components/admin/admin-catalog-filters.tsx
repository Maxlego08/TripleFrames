import { Form, Link } from '@inertiajs/react';
import CatalogController from '@/actions/App/Http/Controllers/Admin/CatalogController';
import type { AdminSelectOption } from '@/components/admin/admin-select';
import { AdminSelect } from '@/components/admin/admin-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import {
    AVAILABILITY_KEYS,
    CATALOG_EXCEPTION_KEYS,
    CONTENT_FLAG_KEYS,
    CURATION_STATUS_KEYS,
    IMPORT_SOURCE_KEYS,
    MISSING_TITLE_KEYS,
} from '@/lib/admin-enum-keys';
import { index as catalogIndex } from '@/routes/admin/catalog';
import type { AdminCatalogFilters, AdminCatalogOptions } from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

type Props = {
    filters: AdminCatalogFilters;
    options: AdminCatalogOptions;
};

type LabelKeys = Partial<Record<string, TranslationKey>>;

type Translate = (
    key: TranslationKey,
    replacements?: Record<string, string | number>,
) => string;

/**
 * La barre de filtres du catalogue — un `<Form>` en GET, et rien d'autre.
 *
 * Toute la sélection vit dans la **query string** : elle est donc partageable,
 * rechargeable, et c'est elle qui fabrique ensuite les liens de tri et de
 * pagination. Aucun état local, aucun `useForm` maison — l'écran n'a rien à
 * mémoriser que l'URL ne dise déjà.
 *
 * Les listes d'options sont celles que le `CatalogIndexRequest` accepte,
 * relues par le contrôleur : un choix offert ici est, par construction, un
 * choix accepté par la validation. Deux listes séparées finiraient par
 * diverger, et le curateur récolterait une erreur sur un geste légitime.
 *
 * `sort` et `direction` voyagent en champs cachés : filtrer ne doit pas
 * réinitialiser un tri choisi trois clics plus tôt.
 */
export function AdminCatalogFiltersForm({ filters, options }: Props) {
    const { t } = useTranslations();

    return (
        <Form
            {...CatalogController.index.form()}
            options={{ preserveState: true, preserveScroll: true }}
            aria-label={t('admin.a11y.filters_form')}
            className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <input type="hidden" name="sort" value={filters.sort} />
            <input type="hidden" name="direction" value={filters.direction} />

            <div className="space-y-1.5 md:col-span-2 xl:col-span-1">
                <Label htmlFor="catalog-q">
                    {t('admin.catalog.filters.search.label')}
                </Label>
                <Input
                    id="catalog-q"
                    name="q"
                    type="search"
                    defaultValue={filters.q ?? ''}
                    placeholder={t('admin.catalog.filters.search.placeholder')}
                    aria-describedby="catalog-q-hint"
                />
                <p
                    id="catalog-q-hint"
                    className="text-xs text-muted-foreground"
                >
                    {t('admin.catalog.filters.search.hint')}
                </p>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="catalog-availability">
                    {t('admin.catalog.filters.availability')}
                </Label>
                <AdminSelect
                    id="catalog-availability"
                    name="availability"
                    defaultValue={filters.availability ?? ''}
                    options={choices(
                        options.availability,
                        AVAILABILITY_KEYS,
                        t,
                    )}
                />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="catalog-content-flag">
                    {t('admin.catalog.filters.content_flag')}
                </Label>
                <AdminSelect
                    id="catalog-content-flag"
                    name="content_flag"
                    defaultValue={filters.content_flag ?? ''}
                    options={choices(
                        options.content_flag,
                        CONTENT_FLAG_KEYS,
                        t,
                    )}
                />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="catalog-import-source">
                    {t('admin.catalog.filters.import_source')}
                </Label>
                <AdminSelect
                    id="catalog-import-source"
                    name="import_source"
                    defaultValue={filters.import_source ?? ''}
                    options={choices(
                        options.import_source,
                        IMPORT_SOURCE_KEYS,
                        t,
                    )}
                />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="catalog-playable-at">
                    {t('admin.catalog.filters.playable_at.label')}
                </Label>
                <AdminSelect
                    id="catalog-playable-at"
                    name="playable_at"
                    defaultValue={
                        filters.playable_at === null
                            ? ''
                            : String(filters.playable_at)
                    }
                    options={[
                        { value: '', label: t('admin.common.all') },
                        ...options.playable_at.map((count) => ({
                            value: String(count),
                            label: t(
                                'admin.catalog.filters.playable_at.option',
                                { count },
                            ),
                        })),
                    ]}
                />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="catalog-exception">
                    {t('admin.catalog.filters.exception.label')}
                </Label>
                <AdminSelect
                    id="catalog-exception"
                    name="exception"
                    defaultValue={filters.exception ?? ''}
                    options={choices(
                        options.exception,
                        CATALOG_EXCEPTION_KEYS,
                        t,
                    )}
                />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="catalog-curation-status">
                    {t('admin.catalog.filters.curation_status.label')}
                </Label>
                <AdminSelect
                    id="catalog-curation-status"
                    name="curation_status"
                    defaultValue={filters.curation_status ?? ''}
                    options={choices(
                        options.curation_status,
                        CURATION_STATUS_KEYS,
                        t,
                    )}
                />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="catalog-missing-title">
                    {t('admin.catalog.filters.missing_title.label')}
                </Label>
                <AdminSelect
                    id="catalog-missing-title"
                    name="missing_title"
                    defaultValue={filters.missing_title ?? ''}
                    options={choices(
                        options.missing_title,
                        MISSING_TITLE_KEYS,
                        t,
                    )}
                    aria-describedby="catalog-missing-title-hint"
                />
                <p
                    id="catalog-missing-title-hint"
                    className="text-xs text-muted-foreground"
                >
                    {t('admin.catalog.filters.missing_title.hint')}
                </p>
            </div>

            <div className="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-3">
                <Button type="submit">
                    {t('admin.catalog.filters.submit')}
                </Button>
                <Button variant="ghost" asChild>
                    <Link href={catalogIndex()}>
                        {t('admin.catalog.filters.reset')}
                    </Link>
                </Button>
            </div>
        </Form>
    );
}

/**
 * Les options d'un filtre, « tous » en tête.
 *
 * La valeur vide n'est pas un bricolage : c'est l'ABSENCE de filtre, que
 * `ConvertEmptyStringsToNull` rend `null` et que la règle `nullable` du
 * FormRequest accepte. Elle vient en premier parce que c'est l'état par défaut
 * de l'écran.
 *
 * Une valeur que le dictionnaire ignore s'affiche brute : la liste blanche
 * vient du serveur, et mieux vaut lire `demo` qu'une ligne vide.
 */
function choices(
    values: string[],
    keys: LabelKeys,
    t: Translate,
): AdminSelectOption[] {
    return [
        { value: '', label: t('admin.common.all') },
        ...values.map((value) => {
            const key = keys[value];

            return { value, label: key === undefined ? value : t(key) };
        }),
    ];
}
