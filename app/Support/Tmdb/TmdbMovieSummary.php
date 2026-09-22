<?php

namespace App\Support\Tmdb;

/**
 * Un résultat de `discover` — l'aperçu, jamais la fiche.
 *
 * Distinct de {@see TmdbMovie} parce que TMDB ne renvoie pas la même chose :
 * `discover` ne porte ni collection, ni sociétés, ni certifications, et ses
 * genres arrivent en `genre_ids` quand la fiche les détaille en objets. Les
 * confondre imposerait de rendre nullable tout ce que la fiche garantit, et
 * ferait mentir le typage à l'endroit exact où l'import écrit en base.
 *
 * Aucun champ que ne lit aucune règle : ni `vote_average`, ni `popularity`, ni
 * synopsis, ni affiche (§ 3.1, « ce que `movie` ne contient pas »).
 */
final readonly class TmdbMovieSummary
{
    /**
     * @param  list<int>  $genreIds  Étiquettes TMDB brutes, jamais des libellés.
     */
    public function __construct(
        public int $tmdbId,
        public string $title,
        public string $originalTitle,
        public string $originalLanguage,
        public ?string $releaseDate,
        public int $voteCount,
        public bool $adult,
        public array $genreIds,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $context = 'discover.result'): self
    {
        return new self(
            tmdbId: TmdbData::integer($data, 'id', $context),
            title: TmdbData::optionalText($data, 'title', $context)
                ?? TmdbData::text($data, 'original_title', $context),
            originalTitle: TmdbData::text($data, 'original_title', $context),
            originalLanguage: TmdbData::text($data, 'original_language', $context),
            releaseDate: TmdbData::date(TmdbData::optionalText($data, 'release_date', $context)),
            voteCount: TmdbData::counter($data, 'vote_count', $context),
            adult: TmdbData::flag($data, 'adult', $context),
            genreIds: TmdbData::integers($data, 'genre_ids', $context),
        );
    }

    /**
     * Année de sortie, seule granularité que lise une règle du projet.
     */
    public function releaseYear(): ?int
    {
        return TmdbData::year($this->releaseDate);
    }
}
