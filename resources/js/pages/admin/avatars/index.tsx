import { Form, Head, Link } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useId, useState } from 'react';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import { ReasonDialog } from '@/components/admin/reason-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import {
    index as avatarsIndex,
    remove as avatarsRemove,
    unhide as avatarsUnhide,
} from '@/routes/admin/avatars';
import { show as userShow } from '@/routes/admin/users';
import type {
    AdminAvatarFilter,
    AdminAvatarRow,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    avatars: Paginated<AdminAvatarRow>;
    filters: { filter: AdminAvatarFilter | null };
    options: { filter: AdminAvatarFilter[] };
    counts: { uploaded: number; hidden: number };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.avatars', href: avatarsIndex() },
];

const FILTER_KEYS: Record<AdminAvatarFilter, TranslationKey> = {
    hidden: 'admin.avatars.filter.hidden',
    reported: 'admin.avatars.filter.reported',
};

const STATE_KEYS: Record<AdminAvatarRow['state'], TranslationKey> = {
    visible: 'admin.avatars.state.visible',
    hidden: 'admin.avatars.state.hidden',
    removed: 'admin.avatars.state.removed',
};

/** Le geste ouvert : sur quel compte, et lequel. */
type OpenGesture = { row: AdminAvatarRow; kind: 'unhide' | 'remove' } | null;

/**
 * L'écran « Avatars » — ligne 45, spec 20 § 12.5 ; règle : spec 40 § 11.6 et
 * § 11.7 (D49 du 01/10). **Administrateur seul.**
 *
 * Une ligne par compte qui porte une image téléversée ou un masquage ;
 * l'image passe par `admin.avatars.image`, qui la sert même masquée. Deux
 * gestes, chacun consigné au journal : « Lever » (motif facultatif) et
 * « Retirer » (motif obligatoire, `ReasonDialog`).
 */
export default function AdminAvatarsIndex({
    avatars,
    filters,
    options,
    counts,
}: Props) {
    const { t, locale } = useTranslations();
    const [gesture, setGesture] = useState<OpenGesture>(null);
    const [trigger, setTrigger] = useState<HTMLElement | null>(null);

    const open = (row: AdminAvatarRow, kind: 'unhide' | 'remove'): void => {
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

    return (
        <>
            <Head title={t('admin.avatars.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.avatars.title')}
                    description={t('admin.avatars.description')}
                />

                <p className="text-sm text-muted-foreground">
                    {t('admin.avatars.counts', {
                        uploaded: formatInteger(counts.uploaded, locale),
                        hidden: formatInteger(counts.hidden, locale),
                    })}
                </p>

                <nav
                    className="flex flex-wrap gap-2"
                    aria-label={t('admin.avatars.filter.label')}
                >
                    <Button
                        variant={
                            filters.filter === null ? 'default' : 'outline'
                        }
                        className="min-h-11"
                        asChild
                    >
                        <Link
                            href={avatarsIndex()}
                            aria-current={
                                filters.filter === null ? 'page' : undefined
                            }
                            preserveScroll
                        >
                            {t('admin.avatars.filter.all')}
                        </Link>
                    </Button>
                    {options.filter.map((value) => (
                        <Button
                            key={value}
                            variant={
                                value === filters.filter ? 'default' : 'outline'
                            }
                            className="min-h-11"
                            asChild
                        >
                            <Link
                                href={avatarsIndex({
                                    query: { filter: value },
                                })}
                                aria-current={
                                    value === filters.filter
                                        ? 'page'
                                        : undefined
                                }
                                preserveScroll
                            >
                                {t(FILTER_KEYS[value])}
                            </Link>
                        </Button>
                    ))}
                </nav>

                <Card>
                    <CardContent>
                        {avatars.data.length === 0 ? (
                            <p className="py-6 text-sm text-muted-foreground">
                                {t('admin.avatars.empty')}
                            </p>
                        ) : (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t(
                                                    'admin.avatars.columns.image',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.avatars.columns.account',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.avatars.columns.state',
                                                )}
                                            </TableHead>
                                            <TableHead className="text-right">
                                                {t(
                                                    'admin.avatars.columns.reports',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.avatars.columns.last_reported_at',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.avatars.columns.actions',
                                                )}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {avatars.data.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell>
                                                    {row.image_url === null ? (
                                                        <span className="text-sm text-muted-foreground">
                                                            {t(
                                                                'admin.avatars.no_image',
                                                            )}
                                                        </span>
                                                    ) : (
                                                        <img
                                                            src={row.image_url}
                                                            alt={t(
                                                                'admin.avatars.image_alt',
                                                                {
                                                                    name: row.name,
                                                                },
                                                            )}
                                                            width={64}
                                                            height={64}
                                                            className="size-16 rounded-full border border-border object-cover"
                                                        />
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Link
                                                        href={userShow({
                                                            user: row.id,
                                                        })}
                                                        prefetch={false}
                                                        className="underline underline-offset-4"
                                                    >
                                                        {row.name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            row.state ===
                                                            'visible'
                                                                ? 'outline'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {t(
                                                            STATE_KEYS[
                                                                row.state
                                                            ],
                                                        )}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatInteger(
                                                        row.reports,
                                                        locale,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {formatMoment(
                                                        row.last_reported_at,
                                                        locale,
                                                    ) ??
                                                        t(
                                                            'admin.avatars.never',
                                                        )}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap gap-2">
                                                        {row.state !==
                                                            'visible' && (
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                className="min-h-11"
                                                                onClick={() =>
                                                                    open(
                                                                        row,
                                                                        'unhide',
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'admin.avatars.unhide',
                                                                )}
                                                            </Button>
                                                        )}
                                                        {row.state !==
                                                            'removed' && (
                                                            <Button
                                                                type="button"
                                                                variant="destructive"
                                                                className="min-h-11"
                                                                onClick={() =>
                                                                    open(
                                                                        row,
                                                                        'remove',
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'admin.avatars.remove',
                                                                )}
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>

                                <AdminPagination
                                    meta={avatars.meta}
                                    href={(page) =>
                                        avatarsIndex({
                                            query: {
                                                ...(filters.filter === null
                                                    ? {}
                                                    : {
                                                          filter: filters.filter,
                                                      }),
                                                page,
                                            },
                                        })
                                    }
                                />
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>

            <ReasonDialog
                open={gesture?.kind === 'remove'}
                form={avatarsRemove.form({ user: gesture?.row.id ?? 0 })}
                title={t('admin.avatars.remove_title', {
                    name: gesture?.row.name ?? '',
                })}
                description={t('admin.avatars.remove_body')}
                reasonLabel={t('admin.avatars.reason')}
                submitLabel={t('admin.avatars.remove')}
                onClose={() => setGesture(null)}
                onReturnFocus={returnFocus}
            />

            <UnhideDialog
                row={gesture?.kind === 'unhide' ? gesture.row : null}
                onClose={() => setGesture(null)}
                onReturnFocus={returnFocus}
            />
        </>
    );
}

type UnhideDialogProps = {
    row: AdminAvatarRow | null;
    onClose: () => void;
    onReturnFocus: () => void;
};

/**
 * « Lever » : un motif FACULTATIF (`avatar.unhidden`), d'où une boîte propre
 * — `ReasonDialog` exige le sien. Même forme : focus piégé, fermeture
 * étiquetée `admin.a11y.close`, focus rendu au déclencheur.
 */
function UnhideDialog({ row, onClose, onReturnFocus }: UnhideDialogProps) {
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
                        {...avatarsUnhide.form({ user: row.id })}
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader className="pr-12">
                                    <DialogTitle>
                                        {t('admin.avatars.unhide_title', {
                                            name: row.name,
                                        })}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {t('admin.avatars.unhide_body')}
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor={reasonId}>
                                        {t('admin.avatars.reason_optional')}
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
                                            {t('admin.avatars.cancel')}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        aria-busy={processing}
                                        className="min-h-11"
                                    >
                                        {t('admin.avatars.unhide')}
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

AdminAvatarsIndex.layout = { breadcrumbs };
