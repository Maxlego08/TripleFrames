<?php

namespace App\Support\Catalog;

use App\Support\Tmdb\TmdbPage;
use InvalidArgumentException;

/**
 * La position reprenable d'un balayage, encodée dans l'unique colonne
 * `import_run.tmdb_page_cursor`.
 *
 * **Pourquoi une position composite.** `with_original_language` n'accepte
 * qu'**une** langue : un filtre à trois langues est trois séries d'appels
 * paginées indépendamment. Le schéma n'offre qu'un `unsignedInteger` pour la
 * reprise (§ 9.1), et un balayage non reprenable n'aboutit jamais — c'est la
 * première des trois raisons d'exister de la table. L'index de langue et la
 * page tiennent donc dans le même entier, `MAX_PAGE` servant de base : à trois
 * langues et 500 pages, la valeur maximale est 1 999, très loin du plafond de
 * la colonne.
 *
 * L'alternative — un `import_run` par langue — a été écartée : elle éclaterait
 * un balayage en trois provenances, ferait mentir `filter_languages`, qui est
 * décrite comme une chaîne jointe par virgules, et tripleraient les lignes que
 * le back-office doit recoller pour rendre le compte d'un balayage.
 *
 * La langue est désignée par son **rang dans le filtre**, jamais par son code :
 * le code vit déjà dans `filter_languages`, et le dupliquer dans un entier
 * ouvrirait la porte à deux vérités.
 */
final readonly class DiscoverCursor
{
    /**
     * @param  int  $languageIndex  Rang de la langue dans `ImportFilter::$languages`, à partir de 0.
     * @param  int  $page  Page TMDB, à partir de 1.
     */
    public function __construct(
        public int $languageIndex,
        public int $page,
    ) {
        if ($languageIndex < 0) {
            throw new InvalidArgumentException('Rang de langue négatif : ['.$languageIndex.'].');
        }

        if ($page < 1 || $page > TmdbPage::MAX_PAGE) {
            throw new InvalidArgumentException(
                'Page hors bornes ['.$page.'] : TMDB plafonne `discover` à '.TmdbPage::MAX_PAGE.'.',
            );
        }
    }

    /**
     * Le début d'un balayage : première langue, première page.
     */
    public static function start(): self
    {
        return new self(0, 1);
    }

    /**
     * La position relue depuis `import_run.tmdb_page_cursor`. Une colonne nulle
     * — balayage jamais commencé — rend le début.
     */
    public static function fromColumn(?int $cursor): self
    {
        if ($cursor === null || $cursor < 0) {
            return self::start();
        }

        return new self(
            languageIndex: intdiv($cursor, TmdbPage::MAX_PAGE),
            page: ($cursor % TmdbPage::MAX_PAGE) + 1,
        );
    }

    /**
     * La valeur à écrire dans `import_run.tmdb_page_cursor`.
     */
    public function toColumn(): int
    {
        return $this->languageIndex * TmdbPage::MAX_PAGE + ($this->page - 1);
    }

    /**
     * La page suivante de la même langue, ou `null` quand la série est épuisée
     * — c'est `TmdbPage::nextPage()` qui répond, plafond `MAX_PAGE` compris.
     */
    public function next(TmdbPage $page): ?self
    {
        $nextPage = $page->nextPage();

        return $nextPage === null ? null : new self($this->languageIndex, $nextPage);
    }

    /**
     * La première page de la langue suivante, ou `null` quand le filtre est
     * épuisé — et le balayage avec lui.
     */
    public function nextLanguage(int $languageCount): ?self
    {
        $index = $this->languageIndex + 1;

        return $index >= $languageCount ? null : new self($index, 1);
    }
}
