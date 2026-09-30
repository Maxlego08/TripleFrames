<?php

namespace App\Support\I18n;

use App\Enums\Locale;
use App\Enums\OriginalTitleForm;
use App\Models\Movie;
use App\ValueObjects\I18n\ResolvedTitle;

/**
 * **Unique** implémentation de la chaîne de repli d'affichage d'un titre de
 * la spec 05 (spec 70 § 10.5, contrat C11, R-23) — partagée par le QCM (70) et
 * la révélation (60, `RevealMovie`) ; `MovieTitleResolver` n'existe pas.
 *
 * 1. `movie_title` de la locale demandée ;
 * 2. `movie_title` d'une autre locale **activée**, dans l'ordre de repli
 *    déclaré par l'instance ({@see Locale::fallbackRank()}) ;
 * 3. {@see self::original()}.
 *
 * **Jamais un alias** : un alias est une clé de validation, jamais un rendu
 * (10 § A4). Un `movie_title` d'une locale de catalogue non activée (`ja`,
 * `es`…) n'entre pas dans la chaîne : seul `title_original` représente la
 * langue d'origine, comme dans `movie_projection.title_locale_mask`, dont le
 * profil de titre des leurres dépend.
 *
 * Aucune écriture, aucun repli recopié en base (05 : « le repli est un
 * affichage, jamais une écriture »). Les titres sont lus par la relation
 * `titles` : un appelant qui résout plusieurs films la charge d'avance.
 */
final readonly class DisplayTitleResolver
{
    /**
     * Le titre affiché du film pour la locale demandée, avec la locale
     * atteinte et son rang.
     */
    public function resolve(Movie $movie, Locale $locale): ResolvedTitle
    {
        $titles = self::activatedTitles($movie);

        if (isset($titles[$locale->value])) {
            return new ResolvedTitle($titles[$locale->value], $locale, ResolvedTitle::RANK_REQUESTED_LOCALE);
        }

        foreach (self::fallbackOrder() as $fallback) {
            if ($fallback !== $locale && isset($titles[$fallback->value])) {
                return new ResolvedTitle($titles[$fallback->value], $fallback, ResolvedTitle::RANK_OTHER_LOCALE);
            }
        }

        return new ResolvedTitle($this->original($movie), null, ResolvedTitle::RANK_ORIGINAL);
    }

    /**
     * Le dernier maillon de la chaîne : `title_original_latin` quand
     * `title_original` n'est pas latin et qu'une translittération existe
     * ({@see OriginalTitleForm::Transliterated}), sinon `title_original`.
     */
    public function original(Movie $movie): string
    {
        if (OriginalTitleForm::of($movie) === OriginalTitleForm::Transliterated) {
            return $movie->title_original_latin ?? $movie->title_original;
        }

        return $movie->title_original;
    }

    /**
     * Les locales activées dans l'ordre de repli d'instance (`en`, puis `fr`).
     *
     * @return list<Locale>
     */
    private static function fallbackOrder(): array
    {
        $locales = Locale::cases();

        usort($locales, static fn (Locale $a, Locale $b): int => $a->fallbackRank() <=> $b->fallbackRank());

        return $locales;
    }

    /**
     * Les titres du film dans les seules locales activées, indexés par la
     * valeur de {@see Locale} — une ligne au plus par couple (film, locale),
     * `movie_title_movie_locale_uq`.
     *
     * @return array<string, string>
     */
    private static function activatedTitles(Movie $movie): array
    {
        $titles = [];

        foreach ($movie->titles as $title) {
            $locale = Locale::tryFrom($title->locale);

            if ($locale !== null) {
                $titles[$locale->value] = $title->title;
            }
        }

        return $titles;
    }
}
