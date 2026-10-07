import { Link } from '@inertiajs/react';
import { CircleUserRound, Image, Link2, ShieldCheck } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslations } from '@/hooks/use-translations';
import { toUrl } from '@/lib/utils';
import { edit as editAvatar } from '@/routes/avatar';
import { edit as editLinkedAccounts } from '@/routes/linked_accounts';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { t } = useTranslations();

    const sidebarNavItems: NavItem[] = [
        {
            title: t('account.settings.nav.profile'),
            href: edit(),
            icon: CircleUserRound,
        },
        {
            title: t('account.settings.nav.avatar'),
            href: editAvatar(),
            icon: Image,
        },
        {
            title: t('account.settings.nav.linked'),
            href: editLinkedAccounts(),
            icon: Link2,
        },
        {
            title: t('account.settings.nav.security'),
            href: editSecurity(),
            icon: ShieldCheck,
        },
    ];

    return (
        <div className="settings-page">
            <div className="settings-page__workspace">
                <aside className="settings-nav">
                    <nav aria-label={t('account.settings.heading')}>
                        <ul>
                            {sidebarNavItems.map((item) => {
                                const active = isCurrentOrParentUrl(item.href);

                                return (
                                    <li key={toUrl(item.href)}>
                                        <Link
                                            href={item.href}
                                            className="settings-nav__link"
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                        >
                                            <span className="settings-nav__icon">
                                                {item.icon && (
                                                    <item.icon aria-hidden="true" />
                                                )}
                                            </span>
                                            <span>{item.title}</span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>
                </aside>

                <section
                    className="settings-panel"
                    aria-label={t('account.settings.heading')}
                >
                    <div className="settings-panel__inner">{children}</div>
                </section>
            </div>
        </div>
    );
}
