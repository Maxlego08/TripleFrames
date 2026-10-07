import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
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

const EmptyState = () => {
    const { t } = useTranslations();

    return (
        <div className="settings-empty-state p-8 text-center">
            <div className="settings-empty-state__icon mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl">
                <KeyRound className="h-7 w-7" aria-hidden="true" />
            </div>
            <p className="font-medium">{t('account.passkeys.empty')}</p>
            <p className="mt-1 text-sm text-muted-foreground">
                {t('account.passkeys.empty_hint')}
            </p>
        </div>
    );
};

export default function ManagePasskeys(props: Props) {
    const passkeys = props.passkeys ?? [];
    const { t } = useTranslations();

    const handleDelete = (id: number, onError: () => void) => {
        router.delete(destroy.url(id), {
            preserveScroll: true,
            onError,
        });
    };

    const handleRegisterSuccess = () => {
        router.reload();
    };

    if (!(props.canManagePasskeys ?? false)) {
        return null;
    }

    return (
        <div className="settings-section space-y-6">
            <Heading
                variant="small"
                title={t('account.passkeys.heading')}
                description={t('account.passkeys.description')}
            />

            <div className="settings-credential-list">
                {passkeys.length > 0 ? (
                    passkeys.map((passkey) => (
                        <PasskeyItem
                            key={passkey.id}
                            passkey={passkey}
                            onDelete={handleDelete}
                        />
                    ))
                ) : (
                    <EmptyState />
                )}
            </div>

            <PasskeyRegistration onSuccess={handleRegisterSuccess} />
        </div>
    );
}
