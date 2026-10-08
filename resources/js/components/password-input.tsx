import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/**
 * Champ de mot de passe avec bascule « afficher / masquer » (spec 90
 * § 11.2). La bascule est un vrai bouton de 44 px de côté (`size-11`), nommé
 * et à état (`aria-pressed`), relié au champ par `aria-controls`.
 */
export default function PasswordInput({
    className,
    ref,
    id,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & { ref?: Ref<HTMLInputElement> }) {
    const [showPassword, setShowPassword] = useState(false);
    const { t } = useTranslations();
    const Icon = showPassword ? EyeOff : Eye;

    return (
        <div className="password-input relative">
            <Input
                id={id}
                type={showPassword ? 'text' : 'password'}
                className={cn('pr-12', className)}
                ref={ref}
                {...props}
            />
            <button
                type="button"
                onClick={() => setShowPassword((prev) => !prev)}
                className="password-input__toggle absolute inset-y-0 right-0 flex min-w-11 items-center justify-center rounded-r-md text-muted-foreground hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                aria-label={
                    showPassword
                        ? t('account.fields.password_hide')
                        : t('account.fields.password_show')
                }
                aria-pressed={showPassword}
                aria-controls={id}
            >
                <Icon className="size-4" aria-hidden="true" />
            </button>
        </div>
    );
}
