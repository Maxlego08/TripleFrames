<?php

namespace App\Support\Tmdb;

use InvalidArgumentException;

/**
 * Les critères d'un balayage `discover`, traduits en paramètres TMDB.
 *
 * **Ce n'est pas le filtre d'import.** `App\ValueObjects\Catalog\ImportFilter`
 * porte les valeurs par défaut (notoriété, langues, année) et la notion
 * d'élargissement ; il construit cet objet-ci, qui ne sait qu'une chose :
 * comment TMDB nomme ses paramètres. Rien de ce qui est réglable ne vit ici.
 *
 * **`with_original_language` n'accepte qu'une langue par requête** : un balayage
 * sur `{fr, en, ja}` est trois séries d'appels, donc trois instances de cette
 * classe, et non une. C'est une contrainte de l'API, pas un choix de produit.
 *
 * **`include_adult` vaut `false`, toujours, et n'est pas un paramètre.** Les
 * filtres de contenu ne sont contournables par aucune voie (décision 12) ; en
 * faire un argument rendrait le contournement possible depuis l'appelant. Il
 * ne remplace pas la lecture des certifications, qui se fait à l'appel de
 * détail : `certification.lte` n'accepte qu'un pays et écarte silencieusement
 * les non classifiés (§ 9.2).
 */
final readonly class TmdbDiscoverQuery
{
    /** Tri par défaut : la notoriété brute, celle-là même que le filtre d'import borne. */
    public const string DEFAULT_SORT = 'vote_count.desc';

    /**
     * Jamais un paramètre : le filtre de contenu n'est contournable par aucune
     * voie. Écrit `false` en toutes lettres et non en booléen PHP, parce que
     * `http_build_query` sérialiserait `false` en `0` : TMDB comprend `false`
     * sans ambiguïté, et un paramètre de contenu ne se prête à aucune
     * interprétation de sérialisation.
     */
    private const string INCLUDE_ADULT = 'false';

    /**
     * @param  string|null  $originalLanguage  Code ISO 639-1 d'UNE langue d'origine.
     * @param  int|null  $minVoteCount  Plancher de notoriété, `vote_count.gte`.
     * @param  int|null  $minReleaseYear  Première année retenue, bornée au 1er janvier.
     * @param  string  $sortBy  Tri TMDB, de la forme `champ.asc` ou `champ.desc`.
     * @param  string|null  $language  Langue des libellés renvoyés ; `null` laisse le défaut du client.
     */
    public function __construct(
        public ?string $originalLanguage = null,
        public ?int $minVoteCount = null,
        public ?int $minReleaseYear = null,
        public string $sortBy = self::DEFAULT_SORT,
        public ?string $language = null,
    ) {
        if ($originalLanguage !== null && preg_match('/^[a-z]{2,3}$/', $originalLanguage) !== 1) {
            throw new InvalidArgumentException(
                'Langue d’origine TMDB hors format ISO 639-1 : ['.$originalLanguage.'].',
            );
        }

        if ($minVoteCount !== null && $minVoteCount < 0) {
            throw new InvalidArgumentException('Le plancher de notoriété ne peut pas être négatif.');
        }

        if ($minReleaseYear !== null && ($minReleaseYear < 1870 || $minReleaseYear > 2200)) {
            throw new InvalidArgumentException(
                'Année de sortie minimale hors bornes : ['.$minReleaseYear.'].',
            );
        }

        if (preg_match('/^[a-z_]+\.(asc|desc)$/', $sortBy) !== 1) {
            throw new InvalidArgumentException('Tri TMDB hors format : ['.$sortBy.'].');
        }

        if ($language !== null && preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $language) !== 1) {
            throw new InvalidArgumentException('Langue de libellés hors format : ['.$language.'].');
        }
    }

    /**
     * Même balayage, sur une autre langue d'origine — la forme d'appel d'un
     * filtre à plusieurs langues.
     */
    public function forOriginalLanguage(?string $originalLanguage): self
    {
        return new self(
            originalLanguage: $originalLanguage,
            minVoteCount: $this->minVoteCount,
            minReleaseYear: $this->minReleaseYear,
            sortBy: $this->sortBy,
            language: $this->language,
        );
    }

    /**
     * Paramètres d'URL, sans `page` : la pagination est un argument de
     * {@see TmdbClient::discover()} et jamais un état de cet objet.
     *
     * @return array<string, string|int>
     */
    public function toQueryParameters(): array
    {
        $parameters = [
            'include_adult' => self::INCLUDE_ADULT,
            'sort_by' => $this->sortBy,
        ];

        if ($this->originalLanguage !== null) {
            $parameters['with_original_language'] = $this->originalLanguage;
        }

        if ($this->minVoteCount !== null) {
            $parameters['vote_count.gte'] = $this->minVoteCount;
        }

        if ($this->minReleaseYear !== null) {
            $parameters['primary_release_date.gte'] = $this->minReleaseYear.'-01-01';
        }

        if ($this->language !== null) {
            $parameters['language'] = $this->language;
        }

        return $parameters;
    }
}
