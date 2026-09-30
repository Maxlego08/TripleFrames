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
import { announce } from '@/lib/game/announcer';
import { translate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { update } from '@/routes/locale';

type Props = {
    className?: string;
    align?: 'start' | 'center' | 'end';
    /** Masque le libellé et ne laisse que l'icône — barre de jeu en portrait. */
    iconOnly?: boolean;
};

/**
 * Sélecteur de langue (spec 90 § 8), monté par l'en-tête public avec libellé
 * visible et par la ligne basse de `GameLayout` en icône seule.
 *
 * Motif ARIA *menu button* : déclencheur nommé `common.language.current`,
 * options `menuitemradio` dans un groupe étiqueté `common.language.label`.
 * Chaque langue s'affiche **dans sa propre langue** et porte son propre
 * `lang` : un joueur qui ne lit pas l'interface courante doit pouvoir
 * retrouver la sienne, et un lecteur d'écran doit prononcer « Français » en
 * français. `Échap` referme le menu sans changer de langue (Radix).
 *
 * Le changement est une visite partielle : `preserveState` garantit que le
 * composant de page **n'est pas remonté** — ni la souscription Echo, ni
 * l'état de manche, ni le chronomètre client ne sont touchés (règle 1). Le
 * serveur seul persiste la préférence ; le client n'applique jamais un
 * dictionnaire qu'il n'a pas reçu.
 *
 * États (spec 90 § 8) :
 * - **aller-retour en cours** : le déclencheur porte `aria-busy`, les options
 *   sont désactivées et le `Spinner` est neutralisé — son `role="status"` et
 *   son nom « Loading » générés, en dur et en anglais, feraient une seconde
 *   région vivante sur une page de jeu (C16 § 4). Le déclencheur n'est pas
 *   désactivé : le menu referme en lui rendant le focus, qu'un bouton
 *   désactivé perdrait ;
 * - **changement reçu** : `common.language.changed` est annoncé par
 *   `announce()`, une fois le dictionnaire reçu, dans la NOUVELLE langue —
 *   la seule région qui parle, `GameAnnouncer`, est montée par `GameLayout`
 *   et par `PublicLayout`.
 *
 * Mouvement réduit : `motion-reduce:animate-none` sur le `Spinner`, et
 * `motion-reduce:animate-none!` sur le menu, l'important étant requis contre
 * `data-[state=open]:animate-in` du composant généré.
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
                onSuccess: (page) => {
                    const {
                        locale: next,
                        locales: options,
                        translations,
                    } = page.props;
                    const option = options.find(
                        (candidate) => candidate.value === next,
                    );

                    announce(
                        translate(
                            { locale: next, messages: translations },
                            'common.language.changed',
                            { language: option?.label ?? next },
                        ),
                    );
                },
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
                    aria-busy={pending}
                    className={cn('gap-2', className)}
                    aria-label={t('common.language.current', {
                        language: current?.label ?? locale,
                    })}
                >
                    {pending ? (
                        <Spinner
                            aria-hidden="true"
                            role="presentation"
                            aria-label={undefined}
                            className="motion-reduce:animate-none"
                        />
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

            <DropdownMenuContent
                align={align}
                className="min-w-40 motion-reduce:animate-none!"
            >
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
