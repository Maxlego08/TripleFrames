import { AuthHeader } from '@/components/auth/auth-header';
import { SiteFooter } from '@/components/public/site-footer';
import { useTranslations } from '@/hooks/use-translations';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
    page,
}: AuthLayoutProps & { page: string }) {
    const { t } = useTranslations();
    const variant =
        page === 'auth/login'
            ? 'login'
            : page === 'auth/register'
              ? 'register'
              : null;

    return (
        <div
            className={`auth-shell ${variant ? `auth-shell--${variant}` : 'auth-shell--compact'}`}
        >
            <a className="auth-skip-link" href="#auth-content">
                {t('common.nav.skip_to_content')}
            </a>
            <AuthHeader page={page} />

            <main className="auth-stage" id="auth-content">
                <section className="auth-ticket" aria-labelledby="auth-title">
                    <div className="auth-ticket__form-panel">
                        <div className="auth-heading">
                            <h1 id="auth-title">{title}</h1>
                            {description && <p>{description}</p>}
                        </div>
                        <div className="auth-content">{children}</div>
                    </div>
                </section>
            </main>

            <SiteFooter variant="full" />
        </div>
    );
}
