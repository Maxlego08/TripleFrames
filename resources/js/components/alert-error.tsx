import { AlertCircleIcon } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useTranslations } from '@/hooks/use-translations';

/**
 * Liste d'erreurs d'un écran de compte (2FA : QR code, clé, codes de
 * secours), rendue en `Alert` destructive aux tokens. Les doublons sont
 * fusionnés ; chaque message est sa propre clé.
 */
export default function AlertError({
    errors,
    title,
}: {
    errors: string[];
    title?: string;
}) {
    const { t } = useTranslations();
    const messages = Array.from(new Set(errors));

    return (
        <Alert variant="destructive">
            <AlertCircleIcon aria-hidden="true" />
            <AlertTitle>{title ?? t('common.state.error')}</AlertTitle>
            <AlertDescription>
                <ul className="list-inside list-disc text-sm">
                    {messages.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
