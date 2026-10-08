import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { update } from '@/routes/password';
import type { AuthLayoutKeys } from '@/types';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

/**
 * Réinitialisation du mot de passe (Fortify, spec 90 § 11.2) : écran réécrit
 * hors du starter, habillé par `AuthLayout` et `auth/_forms.scss`, chaque
 * erreur reliée à son champ par `aria-describedby`.
 */
export default function ResetPassword({ token, email, passwordRules }: Props) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('account.reset_password.title')} />

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">
                                {t('account.fields.email')}
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                className="mt-1 block w-full"
                                readOnly
                                aria-invalid={errors.email ? true : undefined}
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                            />
                            <InputError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {t('account.fields.password')}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                autoFocus
                                placeholder={t('account.fields.password')}
                                passwordrules={passwordRules}
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
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                placeholder={t(
                                    'account.fields.password_confirmation',
                                )}
                                passwordrules={passwordRules}
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

                        <Button
                            type="submit"
                            className="mt-4 min-h-11 w-full"
                            disabled={processing}
                            aria-busy={processing}
                            data-test="reset-password-button"
                        >
                            {processing && (
                                <Spinner
                                    aria-label={t('common.state.loading')}
                                />
                            )}
                            {t('account.reset_password.submit')}
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

ResetPassword.layout = {
    title: 'account.reset_password.heading',
    description: 'account.reset_password.description',
} satisfies AuthLayoutKeys;
