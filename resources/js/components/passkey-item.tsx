import { KeyRound, Trash2, XIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import InputError from '@/components/input-error';
import { useTranslations } from '@/hooks/use-translations';
import type { Passkey } from '@/types/auth';

type Props = {
    passkey: Passkey;
    onDelete: (
        id: number,
        onError: (message: string | undefined) => void,
    ) => void;
};

/**
 * Une passkey de l'écran Sécurité (spec 90 § 11.2) : un élément de liste,
 * son bouton de suppression nommé d'après la passkey et une boîte de
 * confirmation dont le bouton « Close » généré est masqué au profit d'un
 * `DialogClose` traduit (§ 2.5).
 */
export default function PasskeyItem({ passkey, onDelete }: Props) {
    const [isDeleting, setIsDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | undefined>();
    const { t } = useTranslations();

    const handleDelete = () => {
        setIsDeleting(true);
        setDeleteError(undefined);
        onDelete(passkey.id, (message) => {
            setIsDeleting(false);
            setDeleteError(message);
        });
    };

    return (
        <li className="settings-credential-item flex items-center justify-between gap-3 border-b p-4 last:border-b-0">
            <div className="flex min-w-0 items-center gap-4">
                <div
                    className="settings-credential-icon flex size-10 shrink-0 items-center justify-center rounded-xl"
                    aria-hidden="true"
                >
                    <KeyRound className="size-5" />
                </div>
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2.5">
                        <p className="font-medium tracking-tight break-words">
                            {passkey.name}
                        </p>
                        {passkey.authenticator && (
                            <span className="settings-credential-tag inline-flex items-center rounded-md px-2 py-0.5 text-xs">
                                {passkey.authenticator}
                            </span>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {t('account.passkeys.added', {
                            date: passkey.created_at_diff,
                        })}
                        {passkey.last_used_at_diff && (
                            <>
                                <span
                                    className="mx-1 text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    /
                                </span>
                                {t('account.passkeys.last_used', {
                                    date: passkey.last_used_at_diff,
                                })}
                            </>
                        )}
                    </p>
                </div>
            </div>

            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="settings-destructive-icon min-h-11 min-w-11 shrink-0"
                        aria-label={t('account.passkeys.remove_named', {
                            name: passkey.name,
                        })}
                    >
                        <Trash2 className="size-4" aria-hidden="true" />
                    </Button>
                </DialogTrigger>
                <DialogContent className="settings-dialog [&>button:last-child]:hidden">
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={t('common.action.close')}
                            className="settings-dialog__close absolute top-3 right-3 min-h-11 min-w-11"
                        >
                            <XIcon aria-hidden="true" />
                        </Button>
                    </DialogClose>
                    <DialogHeader className="pr-12">
                        <DialogTitle>
                            {t('account.passkeys.remove')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('account.passkeys.remove_confirm', {
                                name: passkey.name,
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    <InputError role="alert" message={deleteError} />
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                className="min-h-11"
                            >
                                {t('account.passkeys.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            variant="destructive"
                            className="min-h-11"
                            onClick={handleDelete}
                            disabled={isDeleting}
                            aria-busy={isDeleting}
                        >
                            {isDeleting
                                ? t('account.passkeys.removing')
                                : t('account.passkeys.remove')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </li>
    );
}
