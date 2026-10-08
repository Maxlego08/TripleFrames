import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * Message d'erreur d'un champ des écrans de compte (spec 90 § 11.2) : rendu
 * aux tokens (`text-destructive`), la teinte propre à chaque coquille vivant
 * dans sa feuille (`.input-error` de `auth/_forms.scss` et `settings.scss`).
 * Un `id` passé ici sert de cible à l'`aria-describedby` du champ.
 */
export default function InputError({
    message,
    className,
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p
            {...props}
            className={cn('input-error text-sm text-destructive', className)}
        >
            {message}
        </p>
    );
}
