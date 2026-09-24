import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { notice, privacy, terms } from '@/routes/legal';
import type { TranslationKey } from '@/types/translations';
import type { RouteDefinition } from '@/wayfinder';

type LegalLink = {
    label: TranslationKey;
    href: RouteDefinition<'get'>;
};

/**
 * Liens du pied du back-office, dans l'ordre du pied joueur : mentions
 * légales, CGU, confidentialité. URL toujours par Wayfinder, vers les pages
 * légales de la spec 90 (`routes/legal.php`, L90-4). « Signaler un contenu »
 * n'y figure pas : c'est une porte pour un tiers extérieur, et le back-office
 * reçoit ses demandes par sa propre file (spec 20 § 11.4, J2).
 */
const LEGAL_LINKS: readonly LegalLink[] = [
    { label: 'admin.footer.notice', href: notice() },
    { label: 'admin.footer.terms', href: terms() },
    { label: 'admin.footer.privacy', href: privacy() },
];

/**
 * Logo TMDB : le même fichier officiel statique que le pied joueur
 * (`public/brand/`, exception fermée des marques tierces, spec 90 § 3.2),
 * jamais un SVG en ligne — le script anti-couleur refuserait ses couleurs.
 * C'est un actif statique, pas une route : Wayfinder n'a rien à générer.
 */
const TMDB_LOGO_PATH = '/brand/tmdb.svg';

/**
 * Pied du back-office (spec 20 § 13.2, contrats C15 et C16).
 *
 * Ce n'est PAS `SiteFooter` : le pied joueur appelle le domaine `legal`, que le
 * back-office ne reçoit jamais — `TranslationDomains::selected()` rend
 * `['admin']` seul dès que `admin` est demandé. Il afficherait des clés brutes
 * au curateur. Même forme, clés `admin.footer.*`, et un re-skin de l'un ne
 * touche pas l'autre (règle 5).
 *
 * L'attribution TMDB y est visible comme sur tout écran (principe 12). Le logo
 * est un complément de la mention, jamais son remplaçant : s'il ne se charge
 * pas, l'image est retirée et la mention reste.
 */
export function AdminFooter() {
    const { t } = useTranslations();
    const [logoFailed, setLogoFailed] = useState(false);

    return (
        <footer className="border-t border-border">
            <div className="flex w-full flex-col gap-2 px-4 py-4 text-sm text-muted-foreground md:px-6">
                <nav aria-label={t('admin.footer.label')}>
                    <ul className="flex flex-wrap gap-x-4">
                        {LEGAL_LINKS.map((link) => (
                            <li key={link.label}>
                                <Link
                                    href={link.href}
                                    className="inline-flex min-h-11 items-center rounded-sm underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {t(link.label)}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>

                <p className="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs">
                    {!logoFailed && (
                        <img
                            src={TMDB_LOGO_PATH}
                            alt={t('admin.footer.tmdb_logo_alt')}
                            decoding="async"
                            className="h-4 w-auto"
                            onError={() => setLogoFailed(true)}
                        />
                    )}
                    <span>{t('admin.footer.tmdb_attribution')}</span>
                </p>
            </div>
        </footer>
    );
}
