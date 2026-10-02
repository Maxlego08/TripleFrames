import { Form, Head, usePage } from '@inertiajs/react';
import { OAuthButtons } from '@/components/account/oauth-buttons';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import type { AuthLayoutKeys } from '@/types';

/**
 * `canRegister` et `canUsePasskeys` suivent les interrupteurs de compte
 * (spec 40 § 8.2) : fermés en production au jalon 1, où le lien et le bouton
 * mèneraient à un 404.
 */
type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    canUsePasskeys: boolean;
};

export default function Login({
    status,
    canResetPassword,
    canRegister,
    canUsePasskeys,
}: Props) {
    const { t } = useTranslations();
    const { oauthProviders, errors } = usePage<{
        errors: Partial<Record<string, string>>;
    }>().props;

    return (
        <>
            <Head title={t('account.login.title')} />

            <InputError message={errors.oauth} />

            {status && <div className="auth-status">{status}</div>}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="auth-form auth-form--login flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('account.fields.email')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder={t(
                                        'account.fields.email_placeholder',
                                    )}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">
                                        {t('account.fields.password')}
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            {t('account.login.forgot')}
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder={t('account.fields.password')}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">
                                    {t('account.login.remember')}
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && (
                                    <Spinner
                                        aria-label={t('common.state.loading')}
                                    />
                                )}
                                {t('account.login.submit')}
                            </Button>
                        </div>

                        {canRegister && (
                            <div className="text-center text-sm text-muted-foreground">
                                {t('account.login.no_account')}{' '}
                                <TextLink href={register()} tabIndex={5}>
                                    {t('account.login.sign_up')}
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>

            {(canUsePasskeys || oauthProviders.length > 0) && (
                <div className="auth-methods">
                    <div className="auth-divider">
                        <span>{t('account.oauth.separator')}</span>
                    </div>

                    {canUsePasskeys && (
                        <PasskeyVerify showSeparator={false} compact />
                    )}

                    {/* Connexion et création de compte par fournisseur (spec
                        40 § 12, D51 du 01/10) : ouvertes dès qu'un fournisseur
                        est actif, inscription par mot de passe fermée ou non. */}
                    {oauthProviders.length > 0 && (
                        <OAuthButtons
                            providers={oauthProviders}
                            intent="login"
                            compact
                        />
                    )}
                </div>
            )}
        </>
    );
}

// Des **clés**, pas des chaînes : `Page.layout` est évalué au chargement du
// module, hors de tout rendu. `AuthLayout` les résout dans la langue du
// joueur, et `satisfies` fait vérifier leur existence par `tsc`.
Login.layout = {
    title: 'account.login.heading',
    description: 'account.login.description',
} satisfies AuthLayoutKeys;
