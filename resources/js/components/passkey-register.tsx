import { usePasskeyRegister } from '@laravel/passkeys/react';
import type { FormEvent } from 'react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';

type Props = {
    onSuccess: () => void;
};

/**
 * Nom proposé d'office pour une passkey : navigateur et système lus dans
 * l'agent utilisateur, composés par une clé traduite. Jamais envoyé sans que
 * le joueur l'ait vu : le champ reste éditable.
 */
function useSuggestedName(): string {
    const { t } = useTranslations();
    const ua = typeof navigator === 'undefined' ? '' : navigator.userAgent;

    const browser = [
        { pattern: /Edg|Edge/, name: 'Edge' },
        { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
        { pattern: /Firefox|FxiOS/, name: 'Firefox' },
        { pattern: /Chrome|CriOS/, name: 'Chrome' },
        { pattern: /Safari/, name: 'Safari' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    const os = [
        { pattern: /iPhone/, name: 'iPhone' },
        { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
        { pattern: /Android/, name: 'Android' },
        { pattern: /Mac/, name: 'Mac' },
        { pattern: /Windows/, name: 'Windows' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    if (browser !== undefined && os !== undefined) {
        return t('account.passkeys.name_default', { browser, os });
    }

    return browser ?? os ?? '';
}

/**
 * Ajout d'une passkey (spec 40 § 8.1, 90 § 11.2) : un bouton, puis un petit
 * formulaire nommé. L'aide et l'erreur sont reliées au champ par
 * `aria-describedby` ; l'envoi en cours est annoncé par `aria-busy`.
 */
export default function PasskeyRegistration({ onSuccess }: Props) {
    const { t } = useTranslations();
    const suggestedName = useSuggestedName();
    const fieldId = useId();
    const hintId = `${fieldId}-hint`;
    const errorId = `${fieldId}-error`;
    const [name, setName] = useState(suggestedName);
    const [showForm, setShowForm] = useState(false);
    const { register, isLoading, error, isSupported } = usePasskeyRegister({
        onSuccess: () => {
            setName('');
            setShowForm(false);
            onSuccess();
        },
    });

    const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!name.trim()) {
            return;
        }

        await register(name);
    };

    const handleCancel = () => {
        setShowForm(false);
        setName(suggestedName);
    };

    if (!isSupported) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('account.passkeys.unsupported')}
            </p>
        );
    }

    if (!showForm) {
        return (
            <Button
                type="button"
                variant="outline"
                className="min-h-11"
                onClick={() => setShowForm(true)}
            >
                {t('account.passkeys.register')}
            </Button>
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="settings-passkey-form space-y-4 p-4"
        >
            <div className="grid gap-2">
                <Label htmlFor={fieldId}>{t('account.passkeys.name')}</Label>
                <Input
                    id={fieldId}
                    type="text"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                    placeholder={t('account.passkeys.name_placeholder')}
                    className="mt-1 block w-full"
                    aria-describedby={error ? `${hintId} ${errorId}` : hintId}
                    aria-invalid={error ? true : undefined}
                    autoFocus
                />
                <p id={hintId} className="text-xs text-muted-foreground">
                    {t('account.passkeys.name_hint')}
                </p>
            </div>

            <InputError
                id={errorId}
                role="alert"
                message={error ?? undefined}
            />

            <div className="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    className="min-h-11"
                    disabled={isLoading || !name.trim()}
                    aria-busy={isLoading}
                >
                    {isLoading
                        ? t('account.passkeys.registering')
                        : t('account.passkeys.submit')}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    className="min-h-11"
                    onClick={handleCancel}
                >
                    {t('account.passkeys.cancel')}
                </Button>
            </div>
        </form>
    );
}
