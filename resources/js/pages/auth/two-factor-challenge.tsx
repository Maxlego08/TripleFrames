import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { useTranslations } from '@/hooks/use-translations';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';
import type { AuthLayoutKeys } from '@/types';

export default function TwoFactorChallenge() {
    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');
    const { t } = useTranslations();

    // Le titre part vers `AuthLayout` en **clé** : les props de gabarit sont
    // partagées, et seul le gabarit sait dans quelle langue les rendre.
    const authConfigContent = useMemo<
        Required<AuthLayoutKeys> & { toggleText: string }
    >(() => {
        if (showRecoveryInput) {
            return {
                title: 'account.two_factor.challenge.recovery.heading',
                description:
                    'account.two_factor.challenge.recovery.description',
                toggleText: t('account.two_factor.challenge.recovery.toggle'),
            };
        }

        return {
            title: 'account.two_factor.challenge.code.heading',
            description: 'account.two_factor.challenge.code.description',
            toggleText: t('account.two_factor.challenge.code.toggle'),
        };
    }, [showRecoveryInput, t]);

    setLayoutProps({
        title: authConfigContent.title,
        description: authConfigContent.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <>
            <Head title={t('account.two_factor.challenge.title')} />

            <div className="space-y-6">
                <Form
                    {...store.form()}
                    className="space-y-4"
                    resetOnError
                    resetOnSuccess={!showRecoveryInput}
                >
                    {({ errors, processing, clearErrors }) => (
                        <>
                            {showRecoveryInput ? (
                                <>
                                    <Input
                                        name="recovery_code"
                                        type="text"
                                        placeholder={t(
                                            'account.two_factor.challenge.recovery.placeholder',
                                        )}
                                        autoFocus={showRecoveryInput}
                                        required
                                    />
                                    <InputError
                                        message={errors.recovery_code}
                                    />
                                </>
                            ) : (
                                <div className="flex flex-col items-center justify-center space-y-3 text-center">
                                    <div className="flex w-full items-center justify-center">
                                        <InputOTP
                                            name="code"
                                            maxLength={OTP_MAX_LENGTH}
                                            value={code}
                                            onChange={(value) => setCode(value)}
                                            disabled={processing}
                                            pattern={REGEXP_ONLY_DIGITS}
                                            autoFocus
                                        >
                                            <InputOTPGroup>
                                                {Array.from(
                                                    { length: OTP_MAX_LENGTH },
                                                    (_, index) => (
                                                        <InputOTPSlot
                                                            key={index}
                                                            index={index}
                                                        />
                                                    ),
                                                )}
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <InputError message={errors.code} />
                                </div>
                            )}

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                {t('account.two_factor.challenge.submit')}
                            </Button>

                            <div className="text-center text-sm text-muted-foreground">
                                <span>
                                    {t(
                                        'account.two_factor.challenge.toggle_prefix',
                                    )}{' '}
                                </span>
                                <button
                                    type="button"
                                    className="cursor-pointer text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                    onClick={() =>
                                        toggleRecoveryMode(clearErrors)
                                    }
                                >
                                    {authConfigContent.toggleText}
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
