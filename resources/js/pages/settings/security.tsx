import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
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

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('account.security.title')} />

            <h1 className="sr-only">{t('account.security.title')}</h1>

            <div className="space-y-6">
                <Heading
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
                                    />

                                    <InputError
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
                                />

                                <InputError message={errors.password} />
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
                                />

                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    {t('account.security.submit')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
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
