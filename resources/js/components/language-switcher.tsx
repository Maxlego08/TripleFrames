import { router } from '@inertiajs/react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { Check, ChevronDown, Languages, LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import { translate } from '@/lib/i18n';
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
 * Le composant utilise directement les primitives de comportement Radix :
 * aucun `Button`, composant `ui/*` ou utilitaire Tailwind n'entre dans son
 * rendu. Sa forme, ses états et son mouvement sont entièrement définis dans
 * `_language-switcher.scss`.
 *
 * États (spec 90 § 8) :
 * - **aller-retour en cours** : le déclencheur porte `aria-busy`, les options
 *   sont désactivées et le pictogramme de chargement est purement décoratif.
 *   Le déclencheur n'est pas désactivé : le menu referme en lui rendant le
 *   focus, qu'un bouton désactivé perdrait ;
 * - **changement reçu** : `common.language.changed` est annoncé par
 *   `announce()`, une fois le dictionnaire reçu, dans la NOUVELLE langue —
 *   la seule région qui parle, `GameAnnouncer`, est montée par `GameLayout`
 *   et par `PublicLayout`.
 *
 * Mouvement réduit : la feuille dédiée neutralise directement rotation,
 * transitions et ouverture du menu sous `prefers-reduced-motion`.
 */
export default function LanguageSwitcher({
    className,
    align = 'end',
    iconOnly = false,
}: Props) {
    const { t, locale, locales } = useTranslations();
    const [pending, setPending] = useState(false);

    const current = locales.find((option) => option.value === locale);
    const triggerClassName = [
        'language-switcher__trigger',
        iconOnly ? 'language-switcher__trigger--icon' : null,
        className,
    ]
        .filter(Boolean)
        .join(' ');

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
        <DropdownMenu.Root>
            <DropdownMenu.Trigger asChild>
                <button
                    type="button"
                    aria-busy={pending}
                    className={triggerClassName}
                    aria-label={t('common.language.current', {
                        language: current?.label ?? locale,
                    })}
                >
                    <span className="language-switcher__symbol">
                        {pending ? (
                            <LoaderCircle
                                aria-hidden="true"
                                className="language-switcher__loader"
                            />
                        ) : (
                            <Languages aria-hidden="true" />
                        )}
                    </span>
                    {!iconOnly && (
                        <span className="language-switcher__selection">
                            <span
                                className="language-switcher__current"
                                lang={current?.bcp47 ?? locale}
                            >
                                {current?.label ?? locale}
                            </span>
                            <span
                                aria-hidden="true"
                                className="language-switcher__current-code"
                            >
                                {locale.toUpperCase()}
                            </span>
                        </span>
                    )}
                    {!iconOnly && (
                        <ChevronDown
                            aria-hidden="true"
                            className="language-switcher__chevron"
                        />
                    )}
                </button>
            </DropdownMenu.Trigger>

            <DropdownMenu.Portal>
                <DropdownMenu.Content
                    align={align}
                    sideOffset={10}
                    collisionPadding={12}
                    className="language-switcher__menu"
                >
                    <DropdownMenu.Label className="language-switcher__menu-heading">
                        <span className="language-switcher__menu-kicker">
                            <Languages aria-hidden="true" />
                            {t('common.language.label')}
                        </span>
                        <span
                            aria-hidden="true"
                            className="language-switcher__menu-count"
                        >
                            {String(locales.length).padStart(2, '0')}
                        </span>
                    </DropdownMenu.Label>
                    <DropdownMenu.Separator className="language-switcher__separator" />
                    <DropdownMenu.RadioGroup
                        value={locale}
                        onValueChange={change}
                    >
                        {locales.map((option) => (
                            <DropdownMenu.RadioItem
                                key={option.value}
                                value={option.value}
                                disabled={pending}
                                className="language-switcher__option"
                            >
                                <span
                                    aria-hidden="true"
                                    className="language-switcher__option-code"
                                >
                                    {option.value.toUpperCase()}
                                </span>
                                <span
                                    className="language-switcher__option-label"
                                    lang={option.bcp47}
                                >
                                    {option.label}
                                </span>
                                <DropdownMenu.ItemIndicator asChild>
                                    <Check
                                        aria-hidden="true"
                                        className="language-switcher__option-check"
                                    />
                                </DropdownMenu.ItemIndicator>
                            </DropdownMenu.RadioItem>
                        ))}
                    </DropdownMenu.RadioGroup>
                </DropdownMenu.Content>
            </DropdownMenu.Portal>
        </DropdownMenu.Root>
    );
}
