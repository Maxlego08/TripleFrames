import { router } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import { update } from '@/routes/locale';

type Props = {
    className?: string;
    align?: 'start' | 'center' | 'end';
    /** Masque le libellé et ne laisse que l'icône — barre de jeu en portrait. */
    iconOnly?: boolean;
};

/**
 * Sélecteur de langue réutilisable, branché sur aucun écran pour l'instant.
 *
 * Chaque langue s'affiche **dans sa propre langue** et porte son propre
 * `lang` : un joueur qui ne lit pas l'interface courante doit pouvoir
 * retrouver la sienne, et un lecteur d'écran doit prononcer « Français » en
 * français.
 *
 * Le changement est une visite partielle : `preserveState` garantit que le
 * composant de page **n'est pas remonté** — ni la souscription Echo, ni
 * l'état de manche, ni le chronomètre client ne sont touchés (règle 1). Le
 * serveur seul persiste la préférence ; le client n'applique jamais un
 * dictionnaire qu'il n'a pas reçu, et affiche un état d'attente pendant
 * l'unique aller-retour.
 */
export default function LanguageSwitcher({
    className,
    align = 'end',
    iconOnly = false,
}: Props) {
    const { t, locale, locales } = useTranslations();
    const [pending, setPending] = useState(false);

    const current = locales.find((option) => option.value === locale);

    const change = (value: string): void => {
        if (pending || value === locale) {
            return;
        }

        router.post(
            update.url(),
            { locale: value },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['locale', 'translations'],
                onStart: () => setPending(true),
                onFinish: () => setPending(false),
            },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={pending}
                    className={cn('gap-2', className)}
                    aria-label={t('common.language.current', {
                        language: current?.label ?? locale,
                    })}
                >
                    {pending ? (
                        <Spinner />
                    ) : (
                        <Languages aria-hidden="true" className="size-4" />
                    )}
                    {!iconOnly && (
                        <span lang={current?.bcp47 ?? locale}>
                            {current?.label ?? locale}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align={align} className="min-w-40">
                <DropdownMenuLabel>
                    {t('common.language.label')}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup value={locale} onValueChange={change}>
                    {locales.map((option) => (
                        <DropdownMenuRadioItem
                            key={option.value}
                            value={option.value}
                            disabled={pending}
                        >
                            <span lang={option.bcp47}>{option.label}</span>
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
