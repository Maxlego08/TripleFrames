import { Link, usePage } from '@inertiajs/react';
import LanguageSwitcher from '@/components/language-switcher';
import { AppearanceToggle } from '@/components/public/appearance-toggle';
import { useTranslations } from '@/hooks/use-translations';
import { dashboard, home, login, register } from '@/routes';

const NAV_LINK_CLASS =
    'inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/**
 * En-tête des pages publiques (spec 90 § 2.4).
 *
 * - Le nom du site **en texte**, tiré de la prop partagée `name` : aucun logo,
 *   la v1 n'a aucun actif de marque propre (principe 13).
 * - `LanguageSwitcher` **avec libellé visible** : étiqueté et présent dès
 *   l'accueil (principe 8).
 * - `AppearanceToggle` : un invité choisit son thème sans compte.
 * - Les liens de compte relèvent des interrupteurs de 40 : rendus **si et
 *   seulement si** `accountsOpen` est vrai (40 § 8.2), c'est-à-dire jamais en
 *   production au jalon 1, où aucun compte n'existe hors le premier admin
 *   (D1, D4 du 23/09). La prop décide seule : aucun littéral d'environnement
 *   n'entre ici. Un visiteur déjà connecté voit le tableau de bord à la place
 *   de « Se connecter » et « Créer un compte », qui le renverraient au même
 *   endroit.
 */
export function PublicHeader() {
    const { t } = useTranslations();
    const { name, accountsOpen, auth } = usePage().props;

    return (
        <header className="border-b border-border">
            <div className="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-2 px-4 py-2">
                <Link
                    href={home()}
                    className="inline-flex min-h-11 items-center rounded-md text-base font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {name}
                </Link>

                <div className="flex flex-wrap items-center gap-1">
                    {accountsOpen &&
                        (auth.user ? (
                            <Link href={dashboard()} className={NAV_LINK_CLASS}>
                                {t('common.nav.dashboard')}
                            </Link>
                        ) : (
                            <>
                                <Link href={login()} className={NAV_LINK_CLASS}>
                                    {t('common.nav.log_in')}
                                </Link>
                                <Link
                                    href={register()}
                                    className={NAV_LINK_CLASS}
                                >
                                    {t('common.nav.register')}
                                </Link>
                            </>
                        ))}

                    <LanguageSwitcher className="min-h-11 min-w-11" />
                    <AppearanceToggle />
                </div>
            </div>
        </header>
    );
}
