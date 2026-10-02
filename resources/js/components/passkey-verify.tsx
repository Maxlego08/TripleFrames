import type { UrlMethodPair } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';

type Props = {
    routes?: {
        options: UrlMethodPair;
        submit: UrlMethodPair;
    };
    label?: string;
    loadingLabel?: string;
    separator?: string;
    showSeparator?: boolean;
    compact?: boolean;
};

export default function PasskeyVerify({
    routes,
    label,
    loadingLabel,
    separator,
    showSeparator = true,
    compact = false,
}: Props = {}) {
    const { t } = useTranslations();
    const { verify, isLoading, error, isSupported } = usePasskeyVerify({
        ...(routes && {
            routes: {
                options: routes.options.url,
                submit: routes.submit.url,
            },
        }),
        onSuccess: (response) => {
            router.visit(response.redirect ?? '/dashboard');
        },
    });

    if (!isSupported) {
        return null;
    }

    return (
        <div className="auth-passkey">
            <div className="grid gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="auth-provider-button auth-provider-button--passkey w-full"
                    onClick={verify}
                    disabled={isLoading}
                >
                    {isLoading ? (
                        <Spinner aria-label={t('common.state.loading')} />
                    ) : !compact ? (
                        <KeyRound className="h-4 w-4" />
                    ) : null}
                    {!isLoading && compact && (
                        <span className="auth-provider-icon" aria-hidden="true">
                            <KeyRound />
                        </span>
                    )}
                    {isLoading
                        ? (loadingLabel ?? t('account.passkeys.verify.loading'))
                        : compact
                          ? 'Passkey'
                          : (label ?? t('account.passkeys.verify.submit'))}
                </Button>
                {error && (
                    <InputError message={error} className="text-center" />
                )}
            </div>

            {showSeparator && (
                <div className="relative my-6">
                    <div className="absolute inset-0 flex items-center">
                        <Separator className="w-full" />
                    </div>
                    <div className="relative flex justify-center text-xs uppercase">
                        <span className="bg-background px-2 text-muted-foreground">
                            {separator ??
                                t('account.passkeys.verify.separator')}
                        </span>
                    </div>
                </div>
            )}
        </div>
    );
}
