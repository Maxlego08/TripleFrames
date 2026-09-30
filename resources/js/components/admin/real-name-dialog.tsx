import { Form } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useId, useState } from 'react';
import AccessController from '@/actions/App/Http/Controllers/Admin/AccessController';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import type { AdminAccountRow } from '@/types/admin';

/** La largeur de `users.real_name` (spec 10 § 5.1). */
const REAL_NAME_MAX_LENGTH = 255;

/** La largeur de `admin_action.reason` (`AdminAction::REASON_MAX_LENGTH`). */
const REASON_MAX_LENGTH = 500;

type Props = {
    /** Le compte privilégié visé ; `null` ferme la boîte. */
    account: Pick<AdminAccountRow, 'id' | 'name' | 'real_name'> | null;
    onClose: () => void;
    /** Rend le focus au déclencheur, ou à la zone s'il a disparu. */
    onReturnFocus: () => void;
};

/**
 * Corriger le nom réel d'un compte privilégié — spec 20 § 2.8, route
 * `admin.access.real_name.update`, faute de frappe comprise.
 *
 * Le nom réel signe les preuves du projet (D12 du 23/09) : la correction est
 * inscrite au journal (`user.real_name_changed`), motif facultatif, et elle
 * ne vaut que pour les gestes SUIVANTS — la boîte le dit avant l'envoi. Les
 * revues et les lignes du journal déjà signées gardent l'ancien nom.
 *
 * L'envoi reste inactif — jamais retiré — tant que le nom saisi est vide ou
 * identique au nom en place. Refus du serveur sous le champ ; déconnexion :
 * saisie conservée (toast de l'écran). Fermeture générée masquée et remplacée
 * (spec 20 § 13.4).
 */
export function RealNameDialog({ account, onClose, onReturnFocus }: Props) {
    const { t } = useTranslations();

    return (
        <Dialog
            open={account !== null}
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
                className="max-h-[90dvh] overflow-y-auto sm:max-w-lg [&>button:last-child]:hidden"
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

                {account !== null && (
                    <RealNameForm account={account} onDone={onClose} />
                )}
            </DialogContent>
        </Dialog>
    );
}

/** Le formulaire, remonté à chaque ouverture : une saisie abandonnée ne revient jamais. */
function RealNameForm({
    account,
    onDone,
}: {
    account: NonNullable<Props['account']>;
    onDone: () => void;
}) {
    const { t } = useTranslations();
    const realNameId = useId();
    const hintId = useId();
    const realNameErrorId = useId();
    const reasonId = useId();
    const reasonErrorId = useId();

    const current = account.real_name ?? '';
    const [realName, setRealName] = useState(current);
    const trimmed = realName.trim();
    const unchanged = trimmed === '' || trimmed === current;

    return (
        <Form
            {...AccessController.updateRealName.form(account.id)}
            noValidate
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={onDone}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => {
                const blocked = unchanged || processing;

                return (
                    <>
                        <DialogHeader className="pr-12">
                            <DialogTitle>
                                {t('admin.access.real_name_dialog.title', {
                                    name: account.name,
                                })}
                            </DialogTitle>
                            <DialogDescription>
                                {t('admin.access.real_name_dialog.description')}
                            </DialogDescription>
                        </DialogHeader>

                        <Alert>
                            <AlertDescription>
                                {t('admin.access.real_name_dialog.notice')}
                            </AlertDescription>
                        </Alert>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={realNameId}>
                                {t('admin.access.real_name_dialog.field')}
                            </Label>
                            <Input
                                id={realNameId}
                                name="real_name"
                                value={realName}
                                maxLength={REAL_NAME_MAX_LENGTH}
                                autoComplete="off"
                                onChange={(event) =>
                                    setRealName(event.target.value)
                                }
                                aria-required="true"
                                aria-invalid={
                                    errors.real_name ? true : undefined
                                }
                                aria-describedby={`${hintId} ${realNameErrorId}`}
                                className="min-h-11"
                            />
                            <p
                                id={hintId}
                                className="text-sm text-muted-foreground"
                            >
                                {unchanged
                                    ? t(
                                          'admin.access.real_name_dialog.unchanged',
                                      )
                                    : t('admin.access.real_name_dialog.hint')}
                            </p>
                            <AdminInputError
                                id={realNameErrorId}
                                message={errors.real_name}
                            />
                        </div>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={reasonId}>
                                {t('admin.access.reason_optional')}
                            </Label>
                            <Textarea
                                id={reasonId}
                                name="reason"
                                maxLength={REASON_MAX_LENGTH}
                                aria-invalid={errors.reason ? true : undefined}
                                aria-describedby={reasonErrorId}
                            />
                            <AdminInputError
                                id={reasonErrorId}
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
                                    {t('admin.common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                aria-disabled={blocked ? true : undefined}
                                aria-busy={processing || undefined}
                                aria-describedby={hintId}
                                onClick={(event) => {
                                    if (blocked) {
                                        event.preventDefault();
                                    }
                                }}
                                className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            >
                                {t('admin.access.real_name_dialog.submit')}
                            </Button>
                        </DialogFooter>
                    </>
                );
            }}
        </Form>
    );
}
