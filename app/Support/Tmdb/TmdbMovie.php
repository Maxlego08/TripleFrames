<?php

namespace App\Support\Tmdb;

/**
 * La fiche d'un film, appels `append_to_response` compris.
 *
 * Périmètre volontairement fermé : **aucun champ que ne lit aucune règle** — ni
 * `vote_average`, ni `runtime`, ni synopsis, ni affiche, ni casting (§ 3.1).
 * Ce qui est ici alimente exactement `movie`, `movie_tmdb_tag`,
 * `tmdb_company`, `movie_certification`, `movie_title` et `alias`.
 *
 * `genreIds` et `productionCompanyIds` sont des identifiants TMDB **bruts**,
 * jamais des libellés : le nom d'un genre est du contenu traduit et n'a rien à
 * faire en base (§ 3.6) ; le libellé montré au joueur est un `theme_label`.
 * Le nom d'une **société**, lui, est un nom propre non localisé : il est
 * conservé dans `productionCompanyNames` pour nommer la société en back-office
 * (`tmdb_company`, § 3.6 bis, D43 du 01/10), jamais pour un joueur.
 *
 * La collection est une COLONNE et non un pivot : la cardinalité TMDB est 0..1
 * (§ 3.3). `collectionName` est le nom TMDB non localisé, jamais affiché à un
 * joueur.
 *
 * Ce que cette classe ne fait pas, et ne fera jamais : décider du titre
 * français, résoudre la certification retenue, juger de la notoriété, écrire
 * une ligne. Elle rend ce que TMDB a répondu, validé.
 */
final readonly class TmdbMovie
{
    /**
     * Les trois `append_to_response` d'un appel de détail, en une requête.
     *
     * `release_dates` est exigé par le § 9.2 — les certifications ne sont jamais
     * filtrées dans `discover`, elles sont lues ici. `translations` et
     * `alternative_titles` s'y ajoutent pour que `movie_title` et `alias` ne
     * coûtent pas un appel de plus par langue : le quota TMDB est la ressource
     * rare d'un balayage de 500+ films, et un `append` est gratuit.
     */
    public const string APPEND_TO_RESPONSE = 'release_dates,translations,alternative_titles';

    /**
     * @param  list<int>  $genreIds  Étiquettes TMDB brutes, `tag_kind = 'genre'`.
     * @param  list<int>  $productionCompanyIds  Étiquettes TMDB brutes, `tag_kind = 'company'`.
     * @param  array<int, string>  $productionCompanyNames  Nom TMDB non localisé par identifiant de société ; une société sans nom en est absente.
     * @param  list<TmdbReleaseDate>  $releaseDates  Toutes les sorties classées, tous pays.
     * @param  list<TmdbTitle>  $titles  Traductions et titres alternatifs, non triés.
     */
    public function __construct(
        public int $tmdbId,
        public string $title,
        public string $originalTitle,
        public string $originalLanguage,
        public ?string $releaseDate,
        public int $voteCount,
        public bool $adult,
        public ?int $collectionTmdbId,
        public ?string $collectionName,
        public array $genreIds,
        public array $productionCompanyIds,
        public array $productionCompanyNames,
        public array $releaseDates,
        public array $titles,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $context = 'movie'): self
    {
        $collection = TmdbData::nullableObjectAt($data, 'belongs_to_collection', $context);

        return new self(
            tmdbId: TmdbData::integer($data, 'id', $context),
            title: TmdbData::optionalText($data, 'title', $context)
                ?? TmdbData::text($data, 'original_title', $context),
            originalTitle: TmdbData::text($data, 'original_title', $context),
            originalLanguage: TmdbData::text($data, 'original_language', $context),
            releaseDate: TmdbData::date(TmdbData::optionalText($data, 'release_date', $context)),
            voteCount: TmdbData::counter($data, 'vote_count', $context),
            adult: TmdbData::flag($data, 'adult', $context),
            collectionTmdbId: $collection === null
                ? null
                : TmdbData::integer($collection, 'id', $context.'.belongs_to_collection'),
            collectionName: $collection === null
                ? null
                : TmdbData::optionalText($collection, 'name', $context.'.belongs_to_collection'),
            genreIds: self::identifiers($data, 'genres', $context),
            productionCompanyIds: self::identifiers($data, 'production_companies', $context),
            productionCompanyNames: self::companyNames($data, $context),
            releaseDates: self::releaseDates($data, $context),
            titles: self::titles($data, $context),
        );
    }

    /**
     * Année de sortie — seule granularité que lise une règle du projet.
     */
    public function releaseYear(): ?int
    {
        return TmdbData::year($this->releaseDate);
    }

    /**
     * Les sorties classées d'un pays. Simple relecture : ni tri, ni choix de la
     * gagnante, ni lecture du caractère restrictif — les trois appartiennent au
     * service d'import.
     *
     * @return list<TmdbReleaseDate>
     */
    public function releaseDatesFor(string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);

        return array_values(array_filter(
            $this->releaseDates,
            static fn (TmdbReleaseDate $release): bool => $release->countryCode === $countryCode,
        ));
    }

    /**
     * Les titres d'une provenance donnée. Relecture, sans promotion.
     *
     * @return list<TmdbTitle>
     */
    public function titlesOfKind(TmdbTitleKind $kind): array
    {
        return array_values(array_filter(
            $this->titles,
            static fn (TmdbTitle $title): bool => $title->kind === $kind,
        ));
    }

    /**
     * Identifiants d'une liste d'objets TMDB portant une clé `id`.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function identifiers(array $data, string $key, string $context): array
    {
        $identifiers = [];
        $index = 0;

        foreach (TmdbData::objectsAt($data, $key, $context) as $entry) {
            $identifiers[] = TmdbData::integer($entry, 'id', $context.'.'.$key.'['.$index.']');
            $index++;
        }

        return $identifiers;
    }

    /**
     * Le nom de chaque société de production, indexé par son identifiant. Un
     * nom absent ou vide n'écrit aucune entrée : la société reste désignée par
     * son seul identifiant, jamais par un nom inventé.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private static function companyNames(array $data, string $context): array
    {
        $names = [];
        $index = 0;

        foreach (TmdbData::objectsAt($data, 'production_companies', $context) as $entry) {
            $entryContext = $context.'.production_companies['.$index.']';
            $index++;

            $name = TmdbData::optionalText($entry, 'name', $entryContext);

            if ($name !== null) {
                $names[TmdbData::integer($entry, 'id', $entryContext)] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<TmdbReleaseDate>
     */
    private static function releaseDates(array $data, string $context): array
    {
        $append = TmdbData::nullableObjectAt($data, 'release_dates', $context);

        if ($append === null) {
            return [];
        }

        $releases = [];
        $countryIndex = 0;

        foreach (TmdbData::objectsAt($append, 'results', $context.'.release_dates') as $country) {
            $countryContext = $context.'.release_dates.results['.$countryIndex.']';
            $countryCode = TmdbData::optionalText($country, 'iso_3166_1', $countryContext);
            $countryIndex++;

            if ($countryCode === null) {
                continue;
            }

            $entryIndex = 0;

            foreach (TmdbData::objectsAt($country, 'release_dates', $countryContext) as $entry) {
                $releases[] = TmdbReleaseDate::fromArray(
                    $entry,
                    $countryCode,
                    $countryContext.'.release_dates['.$entryIndex.']',
                );
                $entryIndex++;
            }
        }

        return $releases;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<TmdbTitle>
     */
    private static function titles(array $data, string $context): array
    {
        $titles = [];

        $translations = TmdbData::nullableObjectAt($data, 'translations', $context);

        if ($translations !== null) {
            $index = 0;

            foreach (TmdbData::objectsAt($translations, 'translations', $context.'.translations') as $entry) {
                $title = TmdbTitle::fromTranslation($entry, $context.'.translations.translations['.$index.']');
                $index++;

                if ($title !== null) {
                    $titles[] = $title;
                }
            }
        }

        $alternatives = TmdbData::nullableObjectAt($data, 'alternative_titles', $context);

        if ($alternatives !== null) {
            $index = 0;

            foreach (TmdbData::objectsAt($alternatives, 'titles', $context.'.alternative_titles') as $entry) {
                $title = TmdbTitle::fromAlternative($entry, $context.'.alternative_titles.titles['.$index.']');
                $index++;

                if ($title !== null) {
                    $titles[] = $title;
                }
            }
        }

        return $titles;
    }
}
