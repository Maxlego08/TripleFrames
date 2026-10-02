import { Link, usePage } from '@inertiajs/react';
import { AuthBrand } from '@/components/auth/auth-brand';
import { useTranslations } from '@/hooks/use-translations';
import { login, register } from '@/routes';

type Props = {
    page: string;
};

/** En-tete leger et contextuel des ecrans de connexion et d'inscription. */
export function AuthHeader({ page }: Props) {
    const { t } = useTranslations();
    const { accountsOpen } = usePage().props;
    const isRegister = page === 'auth/register';
    const isLogin = page === 'auth/login';

    return (
        <header className="auth-header">
            <div className="auth-header__inner">
                <AuthBrand />

                {isRegister && (
                    <div className="auth-header__action">
                        <span>{t('account.register.has_account')}</span>
                        <Link href={login()}>
                            {t('account.register.sign_in')}
                        </Link>
                    </div>
                )}

                {isLogin && accountsOpen && (
                    <div className="auth-header__action">
                        <span>{t('account.login.no_account')}</span>
                        <Link href={register()}>
                            {t('account.login.sign_up')}
                        </Link>
                    </div>
                )}
            </div>
        </header>
    );
}
