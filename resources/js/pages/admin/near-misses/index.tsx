import { Head, Link, router } from '@inertiajs/react';
import { RefreshCwIcon, SparklesIcon, XIcon } from 'lucide-react';
import { useState } from 'react';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import { LOCALE_KEYS } from '@/lib/admin-enum-keys';
import { formatInteger, formatMonth } from '@/lib/admin-format';
import { show as catalogShow } from '@/routes/admin/catalog';
import {
    dismiss,
    index as nearMissesIndex,
    promote,
    refresh,
} from '@/routes/admin/near_misses';
import type { AdminAliasSuggestion, Paginated } from '@/types/admin';

type Locale = 'fr' | 'en';

type Props = {
    suggestions: Paginated<AdminAliasSuggestion>;
};

/**
 * File de réponses fausses récurrentes. Aucun pseudo, siège ni partie ne
 * traverse cette page : chaque ligne représente au moins trois manches.
 */
export default function AdminNearMissesIndex({ suggestions }: Props) {
    const { t, locale } = useTranslations();
    const [locales, setLocales] = useState<Record<number, Locale>>({});
    const [aliases, setAliases] = useState<Record<number, string>>({});
    const [busy, setBusy] = useState<number | 'refresh' | null>(null);

    const selectedLocale = (id: number): Locale => locales[id] ?? 'fr';
    const selectedAlias = (suggestion: AdminAliasSuggestion): string =>
        aliases[suggestion.id] ?? suggestion.normalized_text;

    const promoteSuggestion = (suggestion: AdminAliasSuggestion): void => {
        setBusy(suggestion.id);
        router.post(
            promote(suggestion.id).url,
            {
                locale: selectedLocale(suggestion.id),
                alias: selectedAlias(suggestion),
            },
            {
                preserveScroll: true,
                onFinish: () => setBusy(null),
            },
        );
    };

    const dismissSuggestion = (suggestion: AdminAliasSuggestion): void => {
        setBusy(suggestion.id);
        router.post(
            dismiss(suggestion.id).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusy(null),
            },
        );
    };

    return (
        <>
            <Head title={t('admin.near_misses.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.near_misses.heading')}
                    description={t('admin.near_misses.description')}
                    actions={
                        <Button
                            variant="outline"
                            className="min-h-11"
                            disabled={busy !== null}
                            onClick={() => {
                                setBusy('refresh');
                                router.post(
                                    refresh().url,
                                    {},
                                    { onFinish: () => setBusy(null) },
                                );
                            }}
                        >
                            <RefreshCwIcon aria-hidden />
                            {t('admin.near_misses.refresh')}
                        </Button>
                    }
                />

                {suggestions.data.length === 0 ? (
                    <AdminEmptyState
                        icon={SparklesIcon}
                        title={t('admin.near_misses.empty')}
                    />
                ) : (
                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.near_misses.list')}
                            </AdminCardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t(
                                                    'admin.near_misses.column.movie',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.near_misses.column.answer',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.near_misses.column.frequency',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.near_misses.column.period',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.near_misses.column.locale',
                                                )}
                                            </TableHead>
                                            <TableHead className="text-right">
                                                {t(
                                                    'admin.near_misses.column.actions',
                                                )}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {suggestions.data.map((suggestion) => (
                                            <TableRow key={suggestion.id}>
                                                <TableCell>
                                                    <Button
                                                        variant="link"
                                                        className="h-auto min-h-11 justify-start p-0 text-left"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={catalogShow(
                                                                suggestion.movie
                                                                    .id,
                                                            )}
                                                        >
                                                            {
                                                                suggestion.movie
                                                                    .title_original
                                                            }
                                                            {suggestion.movie
                                                                .release_year !==
                                                                null && (
                                                                <span className="text-muted-foreground">
                                                                    {' '}
                                                                    (
                                                                    {
                                                                        suggestion
                                                                            .movie
                                                                            .release_year
                                                                    }
                                                                    )
                                                                </span>
                                                            )}
                                                        </Link>
                                                    </Button>
                                                </TableCell>
                                                <TableCell>
                                                    <Input
                                                        className="min-h-11 min-w-64"
                                                        value={selectedAlias(
                                                            suggestion,
                                                        )}
                                                        maxLength={255}
                                                        aria-label={t(
                                                            'admin.near_misses.alias_label',
                                                            {
                                                                answer: suggestion.normalized_text,
                                                            },
                                                        )}
                                                        onChange={(event) =>
                                                            setAliases(
                                                                (current) => ({
                                                                    ...current,
                                                                    [suggestion.id]:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                }),
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {t(
                                                        'admin.near_misses.frequency',
                                                        {
                                                            occurrences:
                                                                formatInteger(
                                                                    suggestion.occurrences,
                                                                    locale,
                                                                ),
                                                            rounds: formatInteger(
                                                                suggestion.distinct_rounds,
                                                                locale,
                                                            ),
                                                            distance:
                                                                formatInteger(
                                                                    suggestion.best_distance,
                                                                    locale,
                                                                ),
                                                        },
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {t(
                                                        'admin.near_misses.period',
                                                        {
                                                            first:
                                                                formatMonth(
                                                                    suggestion.first_seen_on,
                                                                    locale,
                                                                ) ?? '',
                                                            last:
                                                                formatMonth(
                                                                    suggestion.last_seen_on,
                                                                    locale,
                                                                ) ?? '',
                                                        },
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Select
                                                        value={selectedLocale(
                                                            suggestion.id,
                                                        )}
                                                        onValueChange={(
                                                            value: Locale,
                                                        ) =>
                                                            setLocales(
                                                                (current) => ({
                                                                    ...current,
                                                                    [suggestion.id]:
                                                                        value,
                                                                }),
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            className="min-h-11 min-w-32"
                                                            aria-label={t(
                                                                'admin.near_misses.locale_label',
                                                                {
                                                                    answer: suggestion.normalized_text,
                                                                },
                                                            )}
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="fr">
                                                                {t(
                                                                    LOCALE_KEYS.fr ??
                                                                        'admin.enum.locale.fr',
                                                                )}
                                                            </SelectItem>
                                                            <SelectItem value="en">
                                                                {t(
                                                                    LOCALE_KEYS.en ??
                                                                        'admin.enum.locale.en',
                                                                )}
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            className="min-h-11"
                                                            disabled={
                                                                busy !== null ||
                                                                selectedAlias(
                                                                    suggestion,
                                                                ).trim() === ''
                                                            }
                                                            onClick={() =>
                                                                promoteSuggestion(
                                                                    suggestion,
                                                                )
                                                            }
                                                        >
                                                            <SparklesIcon
                                                                aria-hidden
                                                            />
                                                            {t(
                                                                'admin.near_misses.promote',
                                                            )}
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="icon"
                                                            className="min-h-11 min-w-11"
                                                            aria-label={t(
                                                                'admin.near_misses.dismiss_label',
                                                                {
                                                                    answer: suggestion.normalized_text,
                                                                },
                                                            )}
                                                            disabled={
                                                                busy !== null
                                                            }
                                                            onClick={() =>
                                                                dismissSuggestion(
                                                                    suggestion,
                                                                )
                                                            }
                                                        >
                                                            <XIcon
                                                                aria-hidden
                                                            />
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            <AdminPagination
                                meta={suggestions.meta}
                                href={(page) =>
                                    nearMissesIndex({ query: { page } })
                                }
                            />
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
