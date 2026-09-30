import { Form, Head } from '@inertiajs/react';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { store } from '@/routes/password/confirm';
import type { AuthLayoutKeys } from '@/types';

/**
 * `canUsePasskeys` suit l'interrupteur de compte (spec 40 § 8.2) : fermé en
 * production au jalon 1, où les routes de confirmation par passkey
 * répondent 404.
 */
type Props = {
    canUsePasskeys: boolean;
};

export default function ConfirmPassword({ canUsePasskeys }: Props) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('account.confirm_password.title')} />

            {canUsePasskeys && (
                <PasskeyVerify
                    routes={{
                        options: confirmOptions(),
                        submit: confirmStore(),
                    }}
                    label={t('account.confirm_password.passkey.submit')}
                    loadingLabel={t('account.confirm_password.passkey.loading')}
                    separator={t('account.confirm_password.passkey.separator')}
                />
            )}

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {t('account.fields.password')}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder={t('account.fields.password')}
                                autoComplete="current-password"
                                autoFocus
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center">
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && (
                                    <Spinner
                                        aria-label={t('common.state.loading')}
                                    />
                                )}
                                {t('account.confirm_password.submit')}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'account.confirm_password.heading',
    description: 'account.confirm_password.description',
} satisfies AuthLayoutKeys;
