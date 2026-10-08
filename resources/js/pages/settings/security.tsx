import { Form, Head } from '@inertiajs/react';
import { useId, useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { edit } from '@/routes/security';
import type { BreadcrumbItem } from '@/types';

// oxfmt-ignore
type Props = {
    passwordRules: string;
    /** Faux pour un compte créé par un fournisseur : il DÉFINIT un mot de passe (spec 40 § 12.4). */
    hasPassword: boolean;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'account.security.title',
        href: edit(),
    },
];

/**
 * Réglages › Sécurité (spec 40 § 8 et § 12.4, 90 § 11.2) : mot de passe
 * (défini pour un compte né d'un fournisseur), 2FA et passkeys. Écran réécrit
 * hors du starter : tokens et `settings.scss` seulement, chaque erreur reliée
 * à son champ par `aria-describedby`.
 */
export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const { t } = useTranslations();
    const headingId = useId();

    return (
        <>
            <Head title={t('account.security.title')} />

            <h1 className="sr-only">{t('account.security.title')}</h1>

            <section
                className="settings-section space-y-6"
                aria-labelledby={headingId}
            >
                <Heading
                    id={headingId}
                    variant="small"
                    title={
                        props.hasPassword
                            ? t('account.security.heading')
                            : t('account.security.set_heading')
                    }
                    description={
                        props.hasPassword
                            ? t('account.security.description')
                            : t('account.security.set_description')
                    }
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            {props.hasPassword && (
                                <div className="grid gap-2">
                                    <Label htmlFor="current_password">
                                        {t('account.fields.current_password')}
                                    </Label>

                                    <PasswordInput
                                        id="current_password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        className="mt-1 block w-full"
                                        autoComplete="current-password"
                                        placeholder={t(
                                            'account.fields.current_password',
                                        )}
                                        aria-invalid={
                                            errors.current_password
                                                ? true
                                                : undefined
                                        }
                                        aria-describedby={
                                            errors.current_password
                                                ? 'current_password-error'
                                                : undefined
                                        }
                                    />

                                    <InputError
                                        id="current_password-error"
                                        message={errors.current_password}
                                    />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {t('account.fields.new_password')}
                                </Label>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder={t(
                                        'account.fields.new_password',
                                    )}
                                    passwordrules={props.passwordRules}
                                    aria-invalid={
                                        errors.password ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.password
                                            ? 'password-error'
                                            : undefined
                                    }
                                />

                                <InputError
                                    id="password-error"
                                    message={errors.password}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    {t('account.fields.password_confirmation')}
                                </Label>

                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder={t(
                                        'account.fields.password_confirmation',
                                    )}
                                    passwordrules={props.passwordRules}
                                    aria-invalid={
                                        errors.password_confirmation
                                            ? true
                                            : undefined
                                    }
                                    aria-describedby={
                                        errors.password_confirmation
                                            ? 'password_confirmation-error'
                                            : undefined
                                    }
                                />

                                <InputError
                                    id="password_confirmation-error"
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    type="submit"
                                    className="min-h-11"
                                    disabled={processing}
                                    aria-busy={processing}
                                    data-test="update-password-button"
                                >
                                    {t('account.security.submit')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </section>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                canDisableTwoFactor={props.canDisableTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = { breadcrumbs };
