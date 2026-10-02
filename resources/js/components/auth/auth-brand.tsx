import { Link, usePage } from '@inertiajs/react';
import { useTranslations } from '@/hooks/use-translations';
import { home } from '@/routes';

type Props = {
    compact?: boolean;
};

type BrandMarkProps = {
    className?: string;
};

/** Symbole graphique partage entre les coquilles publiques et d'auth. */
export function BrandMark({ className }: BrandMarkProps) {
    return (
        <svg
            className={`auth-brand__logo ${className ?? ''}`.trim()}
            viewBox="0 0 64 52"
            aria-hidden="true"
        >
            <rect
                className="auth-brand__frame auth-brand__frame--back"
                x="5.5"
                y="6"
                width="43"
                height="33"
                rx="7"
                transform="rotate(-7 27 22.5)"
            />
            <rect
                className="auth-brand__frame auth-brand__frame--middle"
                x="15.5"
                y="12"
                width="43"
                height="33"
                rx="7"
                transform="rotate(7 37 28.5)"
            />
            <rect
                className="auth-brand__frame auth-brand__frame--front"
                x="10.5"
                y="8.5"
                width="43"
                height="33"
                rx="7"
            />
            <path className="auth-brand__play" d="M28 17.5 40 25 28 32.5Z" />
        </svg>
    );
}

/** Identite TripleFrames utilisee par la nouvelle coquille d'authentification. */
export function AuthBrand({ compact = false }: Props) {
    const { name } = usePage().props;
    const { t } = useTranslations();

    return (
        <Link
            href={home()}
            className="auth-brand"
            aria-label={`${name}, ${t('common.nav.home')}`}
        >
            <BrandMark />
            {!compact && <span className="auth-brand__name">{name}</span>}
        </Link>
    );
}
