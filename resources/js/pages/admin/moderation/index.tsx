import { Form, Head, Link } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useId, useState } from 'react';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import { ReasonDialog } from '@/components/admin/reason-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as moderationIndex } from '@/routes/admin/moderation';
import {
    ban as nicknameBan,
    unmask as nicknameUnmask,
} from '@/routes/admin/moderation/nickname';
import { show as playerShow } from '@/routes/admin/players';
import { show as userShow } from '@/routes/admin/users';
import type {
    AdminBlocklistForm,
    AdminModerationRow,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    seats: Paginated<AdminModerationRow>;
    blocklist: AdminBlocklistForm[];
    counts: { masked: number };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.moderation', href: moderationIndex() },
];

/** Le geste ouvert : sur quel siège, et lequel. */
type OpenGesture = { row: AdminModerationRow; kind: 'unmask' | 'ban' } | null;

/**
 * L'écran « Modération » des pseudos — ligne 35, spec 20 § 11.5 ; règle :
 * spec 40 § 13.3 (D66 du 07/10). **Administrateur seul.**
 *
 * Une ligne par siège masqué, banni compris : le pseudo (montré à
 * l'administrateur seul), le salon, le compte rattaché, les signalements —
 * fenêtre courante et compte figé au masquage —, les signaleurs avec leur
 * nombre de signalements, pour repérer un abus. Deux gestes consignés :
 * « Lever » (motif facultatif, refusé pour un banni) et « Bannir » (motif
 * obligatoire, définitif). En tête, les formes des pseudos bannis à recopier
 * dans la liste noire versionnée au commit suivant.
 */
export default function AdminModerationIndex({
    seats,
    blocklist,
    counts,
}: Props) {
    const { t, locale } = useTranslations();
    const [gesture, setGesture] = useState<OpenGesture>(null);
    const [trigger, setTrigger] = useState<HTMLElement | null>(null);

    const open = (row: AdminModerationRow, kind: 'unmask' | 'ban'): void => {
        setTrigger(
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null,
        );
        setGesture({ row, kind });
    };

    const returnFocus = (): void => {
        if (trigger !== null && trigger.isConnected) {
            trigger.focus();
        }
    };

    const nicknameOf = (row: AdminModerationRow): string =>
        row.nickname ?? t('admin.moderation.erased');

    return (
        <>
            <Head title={t('admin.moderation.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.moderation.title')}
                    description={t('admin.moderation.description')}
                />

                <p className="text-sm text-muted-foreground">
                    {t('admin.moderation.counts', {
                        masked: formatInteger(counts.masked, locale),
                    })}
                </p>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            <h2>{t('admin.moderation.blocklist.title')}</h2>
                        </CardTitle>
                        <p className="text-sm text-muted-foreground">
                            {t('admin.moderation.blocklist.description')}
                        </p>
                    </CardHeader>
                    <CardContent>
                        {blocklist.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.moderation.blocklist.empty')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t(
                                                'admin.moderation.blocklist.nickname',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.moderation.blocklist.form',
                                            )}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {blocklist.map((entry) => (
                                        <TableRow key={entry.id}>
                                            <TableCell>
                                                {entry.nickname}
                                            </TableCell>
                                            <TableCell>
                                                <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-sm select-all">
                                                    {entry.form}
                                                </code>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardContent>
                        {seats.data.length === 0 ? (
                            <p className="py-6 text-sm text-muted-foreground">
                                {t('admin.moderation.empty')}
                            </p>
                        ) : (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t(
                                                    'admin.moderation.columns.nickname',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.moderation.columns.room',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.moderation.columns.account',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.moderation.columns.masked_at',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.moderation.columns.reports',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.moderation.columns.reporters',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.moderation.columns.state',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.moderation.columns.actions',
                                                )}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {seats.data.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell className="whitespace-normal">
                                                    <Link
                                                        href={playerShow({
                                                            player: row.public_id,
                                                        })}
                                                        prefetch={false}
                                                        className="underline underline-offset-4"
                                                    >
                                                        {nicknameOf(row)}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    {row.room_code ??
                                                        t(
                                                            'admin.moderation.solo',
                                                        )}
                                                </TableCell>
                                                <TableCell>
                                                    {row.account === null ? (
                                                        t(
                                                            'admin.moderation.guest',
                                                        )
                                                    ) : (
                                                        <Link
                                                            href={userShow({
                                                                user: row
                                                                    .account.id,
                                                            })}
                                                            prefetch={false}
                                                            className="underline underline-offset-4"
                                                        >
                                                            {row.account.name}
                                                        </Link>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {formatMoment(
                                                        row.masked_at,
                                                        locale,
                                                    ) ?? '—'}
                                                </TableCell>
                                                <TableCell className="whitespace-normal">
                                                    {row.reports_count === null
                                                        ? t(
                                                              'admin.moderation.reports_frozen_none',
                                                              {
                                                                  current:
                                                                      formatInteger(
                                                                          row.current_reports,
                                                                          locale,
                                                                      ),
                                                              },
                                                          )
                                                        : t(
                                                              'admin.moderation.reports_value',
                                                              {
                                                                  current:
                                                                      formatInteger(
                                                                          row.current_reports,
                                                                          locale,
                                                                      ),
                                                                  frozen: formatInteger(
                                                                      row.reports_count,
                                                                      locale,
                                                                  ),
                                                              },
                                                          )}
                                                </TableCell>
                                                <TableCell className="whitespace-normal">
                                                    {row.reporters.length ===
                                                    0 ? (
                                                        <span className="text-sm text-muted-foreground">
                                                            {t(
                                                                'admin.moderation.no_reporters',
                                                            )}
                                                        </span>
                                                    ) : (
                                                        <ul className="flex flex-col gap-1 text-sm">
                                                            {row.reporters.map(
                                                                (reporter) => (
                                                                    <li
                                                                        key={
                                                                            reporter.id
                                                                        }
                                                                    >
                                                                        {reporter.nickname ===
                                                                        null
                                                                            ? t(
                                                                                  'admin.moderation.reporter_erased',
                                                                                  {
                                                                                      id: reporter.id,
                                                                                      count: formatInteger(
                                                                                          reporter.reports,
                                                                                          locale,
                                                                                      ),
                                                                                  },
                                                                              )
                                                                            : t(
                                                                                  'admin.moderation.reporter',
                                                                                  {
                                                                                      nickname:
                                                                                          reporter.nickname,
                                                                                      count: formatInteger(
                                                                                          reporter.reports,
                                                                                          locale,
                                                                                      ),
                                                                                  },
                                                                              )}
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            row.banned
                                                                ? 'destructive'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {row.banned
                                                            ? t(
                                                                  'admin.moderation.state.banned',
                                                              )
                                                            : t(
                                                                  'admin.moderation.state.masked',
                                                              )}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {!row.banned && (
                                                        <div className="flex flex-wrap gap-2">
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                className="min-h-11"
                                                                onClick={() =>
                                                                    open(
                                                                        row,
                                                                        'unmask',
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'admin.moderation.unmask',
                                                                )}
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="destructive"
                                                                className="min-h-11"
                                                                onClick={() =>
                                                                    open(
                                                                        row,
                                                                        'ban',
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'admin.moderation.ban',
                                                                )}
                                                            </Button>
                                                        </div>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>

                                <AdminPagination
                                    meta={seats.meta}
                                    href={(page) =>
                                        moderationIndex({ query: { page } })
                                    }
                                />
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>

            <ReasonDialog
                open={gesture?.kind === 'ban'}
                form={nicknameBan.form({ player: gesture?.row.id ?? 0 })}
                title={t('admin.moderation.ban_title', {
                    nickname: gesture === null ? '' : nicknameOf(gesture.row),
                })}
                description={t('admin.moderation.ban_body')}
                reasonLabel={t('admin.moderation.reason')}
                submitLabel={t('admin.moderation.ban')}
                onClose={() => setGesture(null)}
                onReturnFocus={returnFocus}
            />

            <UnmaskDialog
                row={gesture?.kind === 'unmask' ? gesture.row : null}
                nickname={gesture === null ? '' : nicknameOf(gesture.row)}
                onClose={() => setGesture(null)}
                onReturnFocus={returnFocus}
            />
        </>
    );
}

type UnmaskDialogProps = {
    row: AdminModerationRow | null;
    nickname: string;
    onClose: () => void;
    onReturnFocus: () => void;
};

/**
 * « Lever » : un motif FACULTATIF (`nickname.unmasked`), d'où une boîte
 * propre — `ReasonDialog` exige le sien. Même forme : focus piégé,
 * fermeture étiquetée `admin.a11y.close`, focus rendu au déclencheur.
 */
function UnmaskDialog({
    row,
    nickname,
    onClose,
    onReturnFocus,
}: UnmaskDialogProps) {
    const { t } = useTranslations();
    const reasonId = useId();
    const errorId = useId();

    return (
        <Dialog
            open={row !== null}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            <DialogContent
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    onReturnFocus();
                }}
                className="sm:max-w-lg [&>button:last-child]:hidden"
            >
                <DialogClose asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={t('admin.a11y.close')}
                        className="absolute top-3 right-3 min-h-11 min-w-11"
                    >
                        <XIcon aria-hidden />
                    </Button>
                </DialogClose>

                {row !== null && (
                    <Form
                        {...nicknameUnmask.form({ player: row.id })}
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader className="pr-12">
                                    <DialogTitle>
                                        {t('admin.moderation.unmask_title', {
                                            nickname,
                                        })}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {t('admin.moderation.unmask_body')}
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor={reasonId}>
                                        {t('admin.moderation.reason_optional')}
                                    </Label>
                                    <Textarea
                                        id={reasonId}
                                        name="reason"
                                        aria-invalid={
                                            errors.reason ? true : undefined
                                        }
                                        aria-describedby={errorId}
                                    />
                                    <AdminInputError
                                        id={errorId}
                                        message={errors.reason}
                                    />
                                </div>

                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="min-h-11"
                                        >
                                            {t('admin.moderation.cancel')}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        aria-busy={processing}
                                        className="min-h-11"
                                    >
                                        {t('admin.moderation.unmask')}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}

AdminModerationIndex.layout = { breadcrumbs };
