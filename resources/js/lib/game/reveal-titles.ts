import type { LocaleCode, RevealMovie, RevealTitle } from '@/types/game-wire';

/**
 * Ce qu'un écran rend du film révélé, dans la langue du joueur.
 *
 * - `title` : le titre de la locale, avec la langue dans laquelle il est
 *   ÉCRIT (`lang`, à poser en attribut sur son nœud) — la locale atteinte
 *   par la chaîne de repli de 05, pas forcément celle du joueur.
 * - `original` : le titre original, **seulement s'il diffère** du titre
 *   rendu ; nul sinon.
 * - `year` : l'année de sortie, discriminant des homonymes et des remakes.
 */
export type RevealTitles = {
    title: RevealTitle;
    original: RevealTitle | null;
    year: number | null;
};

/** Suffixe BCP 47 de l'écriture latine d'une translittération (05). */
const LATIN_SCRIPT_SUFFIX = '-Latn';

/**
 * L'assistant client **unique** de rendu des titres d'un `RevealMovie`
 * (spec 60 § 9.5, propriété de 60 ; 80 § 1.4 et § 11.4) : l'écran de
 * révélation (60) et le récapitulatif du podium (80, L80-7) le consomment
 * tous deux, et ni l'un ni l'autre ne réimplémente ce choix — la révélation
 * et le récapitulatif ne peuvent donc pas diverger.
 *
 * Le paquet porte déjà les titres de **toutes** les locales activées, chacun
 * avec son `lang` (composés par le seul `RevealMovieBuilder` du serveur) :
 * ce module ne résout aucune chaîne de repli, il choisit. Au changement de
 * langue, l'appelant le rappelle avec la nouvelle locale, sans rien
 * redemander au serveur.
 *
 * - `title` = `movie.titles[locale]`, tel quel ;
 * - `original` = la translittération latine si elle existe
 *   (`originalTitleLatin`, de `lang` = `originalLanguage` suffixé `-Latn`),
 *   sinon le titre original (`originalTitle`, de `lang` =
 *   `originalLanguage`) ; **nul quand son texte égale celui de `title`** —
 *   « titre original s'il diffère » ;
 * - `year` = `movie.year`.
 *
 * Aucune phrase n'est composée ici : l'écran place ces données dans ses
 * textes traduits, chaque titre dans un fragment portant son `lang`.
 */
export function revealTitles(
    movie: RevealMovie,
    locale: LocaleCode,
): RevealTitles {
    const title = movie.titles[locale];
    const original: RevealTitle =
        movie.originalTitleLatin === null
            ? { text: movie.originalTitle, lang: movie.originalLanguage }
            : {
                  text: movie.originalTitleLatin,
                  lang: `${movie.originalLanguage}${LATIN_SCRIPT_SUFFIX}`,
              };

    return {
        title,
        original: original.text === title.text ? null : original,
        year: movie.year,
    };
}
