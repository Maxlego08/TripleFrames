import { InfoIcon, TriangleAlertIcon } from 'lucide-react';
import { FRAME_LEVEL_KEYS } from '@/components/admin/level-picker';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import { formatInteger } from '@/lib/admin-format';
import { cn } from '@/lib/utils';
import type { AdminBankCoverage } from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

type Props = {
    coverage: AdminBankCoverage;
    className?: string;
};

/**
 * L'indicateur de couverture de la banque, niveau par niveau (spec 20 § 6.6).
 *
 * - **jouables** : variantes lues sur `movie_projection`, le prédicat unique
 *   de `10` § 3.2 — rien n'est recompté ici ; à côté, la cible de la passe 2,
 *   objectif de curation et jamais condition de publication ;
 * - **en route** : les images en traitement, en attente de revue, rejetées
 *   et en échec, lues sur la banque ;
 * - **variante unique** : un niveau 1, 3 ou 5 qui ne tient qu'à une image,
 *   dit en toutes lettres — jamais par la seule couleur — et en back-office
 *   seulement ;
 * - le bandeau dit quelle passe est en cours, et un film publié devenu
 *   incomplet l'est dit sans détour : il reste publié, jouable jusqu'à
 *   `N = playable_up_to` avec repli de niveau (E10-23).
 *
 * Aucun élément focalisable : l'indicateur se lit, il ne s'actionne pas, et
 * n'ajoute aucun arrêt de tabulation entre la banque et la publication.
 */
export function CoverageMeter({ coverage, className }: Props) {
    const { t, locale } = useTranslations();

    const passKey: TranslationKey =
        coverage.pass === 1
            ? 'admin.bank.coverage.pass_one'
            : coverage.target_reached
              ? 'admin.bank.coverage.pass_two_reached'
              : 'admin.bank.coverage.pass_two';

    return (
        <div className={cn('flex flex-col gap-4', className)}>
            <Alert>
                <InfoIcon aria-hidden />
                <AlertDescription>{t(passKey)}</AlertDescription>
            </Alert>

            {coverage.incomplete && (
                <Alert variant="destructive">
                    <TriangleAlertIcon aria-hidden />
                    <AlertTitle>
                        {coverage.playable_up_to === null
                            ? t('admin.bank.coverage.incomplete_unplayable')
                            : t('admin.bank.coverage.incomplete', {
                                  max: coverage.playable_up_to,
                              })}
                    </AlertTitle>
                </Alert>
            )}

            <div className="w-full overflow-x-auto">
                <Table>
                    <TableCaption className="sr-only">
                        {t('admin.bank.coverage.table_label')}
                    </TableCaption>
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col">
                                {t('admin.bank.coverage.column.level')}
                            </TableHead>
                            <TableHead scope="col">
                                {t('admin.bank.coverage.column.playable')}
                            </TableHead>
                            <TableHead scope="col">
                                {t('admin.bank.coverage.column.processing')}
                            </TableHead>
                            <TableHead scope="col">
                                {t(
                                    'admin.bank.coverage.column.awaiting_review',
                                )}
                            </TableHead>
                            <TableHead scope="col">
                                {t('admin.bank.coverage.column.rejected')}
                            </TableHead>
                            <TableHead scope="col">
                                {t('admin.bank.coverage.column.failed')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {coverage.levels.map((row) => (
                            <TableRow key={row.level}>
                                <TableHead scope="row" className="font-medium">
                                    {t('admin.level.option', {
                                        level: row.level,
                                        label: t(
                                            FRAME_LEVEL_KEYS[row.level].label,
                                        ),
                                    })}
                                </TableHead>
                                <TableCell>
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span
                                            className={cn(
                                                'tabular-nums',
                                                row.playable >= row.target
                                                    ? 'font-medium text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {t(
                                                'admin.bank.coverage.playable_ratio',
                                                {
                                                    count: formatInteger(
                                                        row.playable,
                                                        locale,
                                                    ),
                                                    target: formatInteger(
                                                        row.target,
                                                        locale,
                                                    ),
                                                },
                                            )}
                                        </span>
                                        {row.single_variant && (
                                            <Badge variant="outline">
                                                {t(
                                                    'admin.bank.coverage.single_variant',
                                                )}
                                            </Badge>
                                        )}
                                    </span>
                                </TableCell>
                                <CountCell value={row.processing} />
                                <CountCell value={row.awaiting_review} />
                                <CountCell value={row.rejected} />
                                <CountCell value={row.failed} />
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}

/** Un compte d'images en route : zéro s'affiche zéro, en sourdine. */
function CountCell({ value }: { value: number }) {
    const { locale } = useTranslations();

    return (
        <TableCell
            className={cn(
                'tabular-nums',
                value === 0 ? 'text-muted-foreground' : 'text-foreground',
            )}
        >
            {formatInteger(value, locale)}
        </TableCell>
    );
}
