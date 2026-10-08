import { Form } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    /** Faux pour un curateur ou un administrateur : la 2FA leur est obligatoire (spec 40 § 13.8). */
    canDisableTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

/**
 * Section « Authentification à deux facteurs » de l'écran Sécurité (spec 40
 * § 8, 90 § 11.2) : état actif ou inactif, activation en boîte de dialogue,
 * codes de secours. Tout est aux tokens ; l'habillage vit dans
 * `settings.scss`.
 */
export default function ManageTwoFactor(props: Props) {
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;
    const canDisableTwoFactor = props.canDisableTwoFactor ?? true;
    const { t } = useTranslations();
    const headingId = useId();

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <section
            className="settings-section space-y-6"
            aria-labelledby={headingId}
        >
            <Heading
                id={headingId}
                variant="small"
                title={t('account.two_factor.manage.heading')}
                description={t('account.two_factor.manage.description')}
            />
            {twoFactorEnabled ? (
                <div className="settings-two-factor-state settings-two-factor-state--enabled flex flex-col items-start justify-start space-y-4">
                    <p className="text-sm text-muted-foreground">
                        {t('account.two_factor.manage.enabled_hint')}
                    </p>

                    {canDisableTwoFactor ? (
                        <div className="relative inline">
                            <Form {...disable.form()}>
                                {({ processing, errors: disableErrors }) => (
                                    <>
                                        <Button
                                            variant="destructive"
                                            type="submit"
                                            className="min-h-11"
                                            disabled={processing}
                                            aria-busy={processing}
                                        >
                                            {t(
                                                'account.two_factor.manage.disable',
                                            )}
                                        </Button>
                                        <InputError
                                            role="alert"
                                            message={disableErrors.two_factor}
                                        />
                                    </>
                                )}
                            </Form>
                        </div>
                    ) : (
                        <p
                            className="text-sm text-muted-foreground"
                            data-test="two-factor-privileged-hint"
                        >
                            {t('account.two_factor.manage.privileged_hint')}
                        </p>
                    )}

                    <TwoFactorRecoveryCodes
                        recoveryCodesList={recoveryCodesList}
                        fetchRecoveryCodes={fetchRecoveryCodes}
                        errors={errors}
                    />
                </div>
            ) : (
                <div className="settings-two-factor-state settings-two-factor-state--disabled flex flex-col items-start justify-start space-y-4">
                    <p className="text-sm text-muted-foreground">
                        {t('account.two_factor.manage.disabled_hint')}
                    </p>

                    <div>
                        {hasSetupData ? (
                            <Button
                                type="button"
                                className="min-h-11"
                                onClick={() => setShowSetupModal(true)}
                            >
                                <ShieldCheck aria-hidden="true" />
                                {t('account.two_factor.manage.setup')}
                            </Button>
                        ) : (
                            <Form
                                {...enable.form()}
                                onSuccess={() => setShowSetupModal(true)}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        className="min-h-11"
                                        disabled={processing}
                                        aria-busy={processing}
                                    >
                                        {t('account.two_factor.manage.enable')}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />
        </section>
    );
}
