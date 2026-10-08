import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useId } from 'react';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import Heading from '@/components/heading';
import PasskeyItem from '@/components/passkey-item';
import PasskeyRegistration from '@/components/passkey-register';
import { useTranslations } from '@/hooks/use-translations';
import type { Passkey } from '@/types/auth';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

function EmptyState() {
    const { t } = useTranslations();

    return (
        <div className="settings-credential-list settings-empty-state p-8 text-center">
            <div
                className="settings-empty-state__icon mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl"
                aria-hidden="true"
            >
                <KeyRound className="size-7" />
            </div>
            <p className="font-medium">{t('account.passkeys.empty')}</p>
            <p className="mt-1 text-sm text-muted-foreground">
                {t('account.passkeys.empty_hint')}
            </p>
        </div>
    );
}

/**
 * Section « Passkeys » de l'écran Sécurité (spec 40 § 8.1, 90 § 11.2) :
 * rendue seulement quand l'interrupteur `canManagePasskeys` est ouvert. La
 * liste est une vraie liste (`ul`), nommée par le titre de la section.
 */
export default function ManagePasskeys(props: Props) {
    const passkeys = props.passkeys ?? [];
    const { t } = useTranslations();
    const headingId = useId();

    // Un refus traduit — la dernière méthode de connexion (spec 40 § 13.8) —
    // remonte à l'élément, qui l'affiche dans sa boîte de confirmation.
    const handleDelete = (
        id: number,
        onError: (message: string | undefined) => void,
    ) => {
        router.delete(destroy.url(id), {
            preserveScroll: true,
            onError: (errors) => onError(errors.passkey),
        });
    };

    const handleRegisterSuccess = () => {
        router.reload();
    };

    if (!(props.canManagePasskeys ?? false)) {
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
                title={t('account.passkeys.heading')}
                description={t('account.passkeys.description')}
            />

            {passkeys.length > 0 ? (
                <ul
                    className="settings-credential-list"
                    aria-labelledby={headingId}
                >
                    {passkeys.map((passkey) => (
                        <PasskeyItem
                            key={passkey.id}
                            passkey={passkey}
                            onDelete={handleDelete}
                        />
                    ))}
                </ul>
            ) : (
                <EmptyState />
            )}

            <PasskeyRegistration onSuccess={handleRegisterSuccess} />
        </section>
    );
}
