import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
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
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { useTranslations } from '@/hooks/use-translations';
import type { TranslationKey } from '@/types/translations';

/**
 * Les trois apparences, dans leur ordre d'affichage. Table écrite ici plutôt
 * qu'une clé construite à l'exécution (spec 90 § 6.7) : chaque libellé reste
 * un littéral que les vérifications de couverture voient.
 */
const OPTIONS: ReadonlyArray<{
    value: Appearance;
    label: TranslationKey;
    icon: LucideIcon;
}> = [
    { value: 'light', label: 'common.appearance.light', icon: Sun },
    { value: 'dark', label: 'common.appearance.dark', icon: Moon },
    { value: 'system', label: 'common.appearance.system', icon: Monitor },
];

function isAppearance(value: string): value is Appearance {
    return OPTIONS.some((option) => option.value === value);
}

/**
 * Choix d'apparence de l'en-tête public (spec 90 § 2.4).
 *
 * La page de réglage d'apparence du starter est derrière `auth` : sans ce
 * sélecteur, un invité ne pourrait pas choisir son thème, alors que tout le
 * site hors jeu suit ce choix (principe 13). Les pages `game/*`, forcées en
 * sombre, ne le montent pas.
 *
 * Motif ARIA *menu button*, comme `LanguageSwitcher` : déclencheur nommé
 * `common.appearance.label`, options `menuitemradio` dont `aria-checked` dit
 * l'apparence en vigueur. Le choix s'applique sans aller-retour serveur : il
 * est écrit dans le stockage local et dans le cookie `appearance`, que Blade
 * relit au chargement suivant.
 *
 * Mouvement réduit (spec 90 § 8) : le menu porte `motion-reduce:animate-none!`,
 * avec l'important, qui seul l'emporte sur `data-[state=open]:animate-in`.
 */
export function AppearanceToggle() {
    const { t } = useTranslations();
    const { appearance, updateAppearance } = useAppearance();

    const CurrentIcon =
        OPTIONS.find((option) => option.value === appearance)?.icon ?? Monitor;

    const change = (value: string): void => {
        if (isAppearance(value) && value !== appearance) {
            updateAppearance(value);
        }
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="min-h-11 min-w-11"
                    aria-label={t('common.appearance.label')}
                >
                    <CurrentIcon aria-hidden="true" className="size-4" />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="end"
                className="min-w-40 motion-reduce:animate-none!"
            >
                <DropdownMenuLabel>
                    {t('common.appearance.label')}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup
                    value={appearance}
                    onValueChange={change}
                >
                    {OPTIONS.map(({ value, label, icon: Icon }) => (
                        <DropdownMenuRadioItem key={value} value={value}>
                            <Icon aria-hidden="true" className="size-4" />
                            {t(label)}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
