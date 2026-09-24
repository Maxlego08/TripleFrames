import { useState } from 'react';
import { useTranslations } from '@/hooks/use-translations';

/**
 * Chemin du logo TMDB : un fichier OFFICIEL statique de `public/brand/`
 * (exception fermée des marques tierces, spec 90 § 3.2, n° 72), jamais un SVG
 * en ligne — le script anti-couleur refuserait ses couleurs, et un re-skin ne
 * doit jamais toucher la marque d'un tiers. C'est un actif statique, pas une
 * route : Wayfinder n'a rien à générer pour lui. Aucun appel à TMDB n'est fait
 * pour l'afficher (règle 6).
 */
const TMDB_LOGO_PATH = '/brand/tmdb.svg';

/**
 * Attribution TMDB exigée par les conditions d'utilisation de l'API : la
 * mention `legal.tmdb.attribution` et le logo (principe 12, `00` § Ouverture,
 * conformité et gouvernance, point 4).
 *
 * Rendue par `SiteFooter` sur tous les écrans et, par la spec 60, sur l'écran
 * de révélation. **Aucun lien vers TMDB** : l'attribution n'en exige pas, et
 * chaque lien sortant depuis une page de jeu est un départ de page de plus.
 *
 * Le logo est un complément de la mention, jamais son remplaçant : si le
 * fichier manque ou ne se charge pas, l'image est retirée et la mention reste,
 * au lieu d'une icône d'image cassée sur chaque écran. Le fichier officiel est
 * déposé par le porteur (`public/brand/LICENSE.md`) ; son absence est tracée
 * par `THIRD_PARTY_NOTICES.md`, où sa ligne reste « attendue » tant qu'il
 * n'est pas livré.
 */
export function TmdbAttribution() {
    const { t } = useTranslations();
    const [logoFailed, setLogoFailed] = useState(false);

    return (
        <p className="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-muted-foreground">
            {!logoFailed && (
                <img
                    src={TMDB_LOGO_PATH}
                    alt={t('legal.tmdb.logo_alt')}
                    decoding="async"
                    className="h-4 w-auto"
                    onError={() => setLogoFailed(true)}
                />
            )}
            <span>{t('legal.tmdb.attribution')}</span>
        </p>
    );
}
