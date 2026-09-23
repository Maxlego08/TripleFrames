import type { InertiaLinkProps } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { formatInteger } from '@/lib/admin-format';
import type { Paginated } from '@/types/admin';

type Props = {
    meta: Paginated<unknown>['meta'];
    /** L'URL d'une page. L'appelant possède ses filtres, ce composant la forme. */
    href: (page: number) => NonNullable<InertiaLinkProps['href']>;
    /** Sert à distinguer deux paginations sur un même écran. */
    id?: string;
};

/**
 * La pagination du back-office, bâtie sur `Button` et `<Link>`.
 *
 * **Le composant `pagination` de shadcn n'est volontairement pas installé** :
 * `PaginationPrevious` et `PaginationNext` embarquent « Previous » et « Next »
 * EN DUR dans `components/ui/pagination.tsx`, fichier que le dépôt s'interdit
 * de modifier — ce serait un texte anglais impossible à corriger depuis
 * `lang/fr/admin.php`, donc une violation définitive de la règle 4.
 *
 * Pour la même raison, le contrôleur n'expédie jamais le tableau `links` d'un
 * `LengthAwarePaginator` : il porte « Previous », « Next » et « &laquo; »
 * produits par le framework en anglais. Seuls `data` et les six champs de
 * `meta` traversent, et les trois libellés vivent dans le dictionnaire.
 *
 * Une seule page ⇒ rien à afficher : un bandeau de navigation qui ne mène
 * nulle part est du bruit pour tout le monde, et une tabulation de plus pour
 * qui navigue au clavier.
 */
export function AdminPagination({ meta, href, id }: Props) {
    const { t, locale } = useTranslations();

    if (meta.last_page <= 1) {
        return null;
    }

    const hasPrevious = meta.current_page > 1;
    const hasNext = meta.current_page < meta.last_page;

    return (
        <nav
            aria-label={t('admin.a11y.pagination')}
            id={id}
            className="flex flex-col items-center justify-between gap-3 border-t border-border pt-4 sm:flex-row"
        >
            <div className="flex flex-col gap-0.5 text-xs text-muted-foreground">
                <span>
                    {t('admin.pagination.page', {
                        current: formatInteger(meta.current_page, locale),
                        last: formatInteger(meta.last_page, locale),
                    })}
                </span>
                {meta.from !== null && meta.to !== null && (
                    <span>
                        {t('admin.pagination.summary', {
                            from: formatInteger(meta.from, locale),
                            to: formatInteger(meta.to, locale),
                            total: formatInteger(meta.total, locale),
                        })}
                    </span>
                )}
            </div>

            <div className="flex items-center gap-2">
                {hasPrevious ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={href(meta.current_page - 1)}
                            preserveScroll
                            rel="prev"
                        >
                            <ChevronLeftIcon aria-hidden />
                            {t('admin.pagination.previous')}
                        </Link>
                    </Button>
                ) : (
                    <Button variant="outline" size="sm" disabled>
                        <ChevronLeftIcon aria-hidden />
                        {t('admin.pagination.previous')}
                    </Button>
                )}

                {hasNext ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={href(meta.current_page + 1)}
                            preserveScroll
                            rel="next"
                        >
                            {t('admin.pagination.next')}
                            <ChevronRightIcon aria-hidden />
                        </Link>
                    </Button>
                ) : (
                    <Button variant="outline" size="sm" disabled>
                        {t('admin.pagination.next')}
                        <ChevronRightIcon aria-hidden />
                    </Button>
                )}
            </div>
        </nav>
    );
}
