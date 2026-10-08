import { Form, Head, usePage } from '@inertiajs/react';
import { ConsentFields } from '@/components/account/consent-fields';
import { OAuthButtons } from '@/components/account/oauth-buttons';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { login } from '@/routes';
import { store } from '@/routes/register';
import type { AuthLayoutKeys } from '@/types';

type Props = {
    passwordRules: string;
};

export default function Register({ passwordRules }: Props) {
    const { t } = useTranslations();

    const { oauthProviders } = usePage().props;

    return (
        <>
            <Head title={t('account.register.title')} />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="auth-form auth-form--register flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('account.fields.name')}
                                </Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder={t(
                                        'account.fields.name_placeholder',
                                    )}
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-2"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('account.fields.email')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder={t(
                                        'account.fields.email_placeholder',
                                    )}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {t('account.fields.password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder={t('account.fields.password')}
                                    passwordrules={passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    {t('account.fields.password_confirmation')}
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder={t(
                                        'account.fields.password_confirmation',
                                    )}
                                    passwordrules={passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <ConsentFields errors={errors} tabIndex={5} />

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={6}
                                data-test="register-user-button"
                            >
                                {processing && (
                                    <Spinner
                                        aria-label={t('common.state.loading')}
                                    />
                                )}
                                {t('account.register.submit')}
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            {t('account.register.has_account')}{' '}
                            <TextLink href={login()} tabIndex={7}>
                                {t('account.register.sign_in')}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>

            {oauthProviders.length > 0 && (
                <div className="auth-methods">
                    <div className="auth-divider">
                        <span>{t('account.oauth.separator')}</span>
                    </div>
                    <OAuthButtons
                        providers={oauthProviders}
                        intent="login"
                        compact
                    />
                </div>
            )}
        </>
    );
}

Register.layout = {
    title: 'account.register.heading',
    description: 'account.register.description',
} satisfies AuthLayoutKeys;
