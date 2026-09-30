import { Form } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useId, useState } from 'react';
import AccessController from '@/actions/App/Http/Controllers/Admin/AccessController';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminSelect } from '@/components/admin/admin-select';
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
import { USER_ROLE_KEYS } from '@/lib/admin-enum-keys';
import { hasAtLeastRole } from '@/lib/roles';
import type { AdminAccountRow } from '@/types/admin';
import type { UserRole } from '@/types/auth';

/** Les trois rôles, du moins au plus privilégié : l'ordre du sélecteur. */
const ROLES: UserRole[] = ['player', 'curator', 'admin'];

/** La largeur de `users.real_name` (spec 10 § 5.1). */
const REAL_NAME_MAX_LENGTH = 255;

/** La largeur de `admin_action.reason` (`AdminAction::REASON_MAX_LENGTH`). */
const REASON_MAX_LENGTH = 500;

type Props = {
    /** Le compte visé ; `null` ferme la boîte. */
    account: Pick<
        AdminAccountRow,
        'id' | 'name' | 'email' | 'email_verified' | 'real_name' | 'role'
    > | null;
    onClose: () => void;
    /** Rend le focus au déclencheur, ou à la zone s'il a disparu. */
    onReturnFocus: () => void;
};

/**
 * Attribuer ou retirer un rôle — spec 20 § 2.8, route `admin.access.update`.
 *
 * - Le rôle courant est présélectionné ; l'envoi reste inactif — jamais
 *   retiré — tant qu'il n'a pas changé, et un texte dit pourquoi.
 * - **Nom réel** (D12 du 23/09) : demandé ici seulement quand le compte n'en
 *   porte pas et qu'un rôle privilégié lui est attribué. Un nom réel déjà
 *   porté se corrige par son propre geste, journalisé à part.
 * - Motif facultatif, inscrit tel quel au journal (`role.changed`).
 * - Les refus d'état relus sous verrou par le serveur — son propre rôle, rôle
 *   inchangé, adresse non vérifiée, nom réel manquant, dernier administrateur —
 *   reviennent sous leur champ ; une déconnexion laisse la saisie telle quelle
 *   (toast de l'écran).
 * - Boîte de dialogue au focus piégé (Radix), rendu au déclencheur ; la
 *   fermeture générée, au nom anglais figé, est masquée et remplacée
 *   (spec 20 § 13.4).
 */
export function RoleChangeDialog({ account, onClose, onReturnFocus }: Props) {
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
                    <RoleChangeForm account={account} onDone={onClose} />
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * Le formulaire, remonté à chaque ouverture — `DialogContent` ne rend ses
 * enfants que boîte ouverte : un choix abandonné ne revient jamais.
 */
function RoleChangeForm({
    account,
    onDone,
}: {
    account: NonNullable<Props['account']>;
    onDone: () => void;
}) {
    const { t } = useTranslations();
    const roleId = useId();
    const roleHintId = useId();
    const roleErrorId = useId();
    const realNameId = useId();
    const realNameHintId = useId();
    const realNameErrorId = useId();
    const reasonId = useId();
    const reasonErrorId = useId();

    const [role, setRole] = useState<UserRole>(account.role);
    const [realName, setRealName] = useState('');

    const privileged = hasAtLeastRole(role, 'curator');
    const asksRealName = privileged && (account.real_name ?? '').trim() === '';
    const unchanged = role === account.role;
    const realNameMissing = asksRealName && realName.trim() === '';

    return (
        <Form
            {...AccessController.update.form(account.id)}
            noValidate
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={onDone}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => {
                const blocked = unchanged || realNameMissing || processing;

                return (
                    <>
                        <DialogHeader className="pr-12">
                            <DialogTitle>
                                {t('admin.access.role_dialog.title', {
                                    name: account.name,
                                })}
                            </DialogTitle>
                            <DialogDescription>
                                {t('admin.access.role_dialog.description')}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={roleId}>
                                {t('admin.access.role_dialog.role')}
                            </Label>
                            <AdminSelect
                                id={roleId}
                                name="role"
                                value={role}
                                onChange={(event) =>
                                    setRole(
                                        ROLES.find(
                                            (value) =>
                                                value === event.target.value,
                                        ) ?? account.role,
                                    )
                                }
                                aria-required="true"
                                aria-invalid={errors.role ? true : undefined}
                                aria-describedby={`${roleHintId} ${roleErrorId}`}
                                className="min-h-11"
                                options={ROLES.map((value) => ({
                                    value,
                                    label: t(USER_ROLE_KEYS[value]),
                                }))}
                            />
                            <p
                                id={roleHintId}
                                className="text-sm text-muted-foreground"
                            >
                                {unchanged
                                    ? t('admin.access.role_dialog.unchanged')
                                    : privileged
                                      ? t(
                                            'admin.access.role_dialog.privileged_notice',
                                        )
                                      : t(
                                            'admin.access.role_dialog.player_notice',
                                        )}
                            </p>
                            <AdminInputError
                                id={roleErrorId}
                                message={errors.role}
                            />
                        </div>

                        {privileged && !account.email_verified && (
                            <Alert variant="destructive">
                                <AlertDescription>
                                    {t(
                                        'admin.access.role_dialog.email_unverified',
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}

                        {asksRealName && (
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor={realNameId}>
                                    {t('admin.access.role_dialog.real_name')}
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
                                    aria-describedby={`${realNameHintId} ${realNameErrorId}`}
                                    className="min-h-11"
                                />
                                <p
                                    id={realNameHintId}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t(
                                        'admin.access.role_dialog.real_name_hint',
                                    )}
                                </p>
                                <AdminInputError
                                    id={realNameErrorId}
                                    message={errors.real_name}
                                />
                            </div>
                        )}

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
                                aria-describedby={roleHintId}
                                onClick={(event) => {
                                    if (blocked) {
                                        event.preventDefault();
                                    }
                                }}
                                className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                            >
                                {t('admin.access.role_dialog.submit')}
                            </Button>
                        </DialogFooter>
                    </>
                );
            }}
        </Form>
    );
}
