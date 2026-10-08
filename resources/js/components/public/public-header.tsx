import { Link, usePage } from '@inertiajs/react';
import { AuthBrand } from '@/components/auth/auth-brand';
import LanguageSwitcher from '@/components/language-switcher';
import { useTranslations } from '@/hooks/use-translations';
import { hasAtLeastRole } from '@/lib/roles';
import { login, register } from '@/routes';
import { dashboard as adminDashboard } from '@/routes/admin';
import { edit as editProfile } from '@/routes/profile';

const NAV_LINK_CLASS =
    'public-header__nav-link inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/**
 * En-tête des pages publiques (spec 90 § 2.4).
 *
 * - La marque TripleFrames est identique sur toutes les pages publiques.
 * - `LanguageSwitcher` **avec libellé visible** : étiqueté et présent dès
 *   l'accueil (principe 8).
 * - Les liens de compte relèvent des interrupteurs de 40 : rendus **si et
 *   seulement si** `accountsOpen` est vrai (40 § 8.2), c'est-à-dire jamais en
 *   production au jalon 1, où aucun compte n'existe hors le premier admin
 *   (D1, D4 du 23/09). La prop décide seule : aucun littéral d'environnement
 *   n'entre ici. Un visiteur déjà connecté voit ses réglages à la place de
 *   « Se connecter » et « Créer un compte », qui le renverraient au même
 *   endroit.
 * - Tout curateur ou administrateur connecté voit l'accès au back-office,
 *   indépendamment de l'ouverture publique des comptes. Les middlewares et
 *   policies du groupe `/admin` restent la source d'autorité.
 */
export function PublicHeader() {
    const { t } = useTranslations();
    const page = usePage();
    const { accountsOpen, oauthProviders, auth } = page.props;
    // La connexion par fournisseur est ouverte dès que ses clés sont posées
    // (spec 40 § 12.1, D51 du 01/10) ; l'inscription par mot de passe suit
    // seule `accountsOpen`.
    const canSignIn = accountsOpen || oauthProviders.length > 0;
    const canAccessAdmin =
        auth.user !== null && hasAtLeastRole(auth.user.role, 'curator');

    return (
        <header className="public-header border-b border-border">
            <div className="public-header__inner mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-2 px-4 py-2">
                <AuthBrand />

                <div className="public-header__controls flex flex-wrap items-center gap-1">
                    {canAccessAdmin && (
                        <Link
                            href={adminDashboard()}
                            className={`${NAV_LINK_CLASS} public-header__nav-admin`}
                        >
                            {t('common.nav.admin')}
                        </Link>
                    )}

                    {canSignIn &&
                        (auth.user ? (
                            <Link
                                href={editProfile()}
                                className={NAV_LINK_CLASS}
                            >
                                {t('common.nav.settings')}
                            </Link>
                        ) : (
                            <>
                                <Link href={login()} className={NAV_LINK_CLASS}>
                                    {t('common.nav.log_in')}
                                </Link>
                                {accountsOpen && (
                                    <Link
                                        href={register()}
                                        className={`${NAV_LINK_CLASS} public-header__nav-pill`}
                                    >
                                        {t('common.nav.register')}
                                    </Link>
                                )}
                            </>
                        ))}

                    <LanguageSwitcher className="public-header__language" />
                </div>
            </div>
        </header>
    );
}
