import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { useTranslations } from '@/hooks/use-translations';

/**
 * Marque du back-office.
 *
 * Ce n'est PAS `<AppLogo>` : celui-ci porte `text-white dark:text-black`, deux
 * couleurs littérales et une variante `dark:`. Le back-office n'a le droit ni
 * de l'une ni de l'autre — un re-skin doit se faire en changeant les tokens du
 * thème, et rien d'autre. La pastille est donc peinte en
 * `bg-sidebar-primary` / `text-sidebar-primary-foreground`, et l'icône hérite
 * par `fill-current`.
 */
export function AdminBrand() {
    const { name } = usePage().props;
    const { t } = useTranslations();

    return (
        <>
            <div className="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon aria-hidden className="size-5 fill-current" />
            </div>
            <div className="grid min-w-0 flex-1 text-left leading-tight">
                <span className="truncate text-sm font-semibold">{name}</span>
                <span className="truncate text-xs text-muted-foreground">
                    {t('admin.title')}
                </span>
            </div>
        </>
    );
}
