<?php

namespace App\Support\Tmdb;

/**
 * Un titre secondaire — traduction ou titre alternatif — tel que TMDB le donne.
 *
 * Aucune promotion ici : ni « celui-ci est le titre français », ni « celui-là
 * est la translittération latine ». Le client rend les candidats avec leur
 * provenance ({@see TmdbTitleKind}), leur langue et leur pays ; le service
 * d'import choisit ce qui devient `movie_title`, ce qui devient un `alias`
 * d'`origin = 'tmdb'`, et ce qui devient `movie.title_original_latin`.
 *
 * `languageCode` est une locale de CATALOGUE (`string(12)` en base, `ja` et
 * `zh-Hant` compris), jamais une locale d'interface : `App\Enums\Locale` ne la
 * caste pas et ne doit jamais la filtrer ici.
 */
final readonly class TmdbTitle
{
    public function __construct(
        public TmdbTitleKind $kind,
        public string $title,
        public ?string $languageCode,
        public ?string $countryCode,
        public ?string $type = null,
    ) {}

    /**
     * Entrée d'`append_to_response=translations`. Rend `null` quand la
     * traduction ne porte aucun titre : l'absence d'une ligne `movie_title` EST
     * l'information, et aucun titre n'est jamais recopié d'une langue à l'autre
     * (§ 3.4).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromTranslation(array $data, string $context): ?self
    {
        $payload = TmdbData::nullableObjectAt($data, 'data', $context) ?? [];
        $title = TmdbData::optionalText($payload, 'title', $context.'.data');

        if ($title === null) {
            return null;
        }

        return new self(
            kind: TmdbTitleKind::Translation,
            title: $title,
            languageCode: TmdbData::optionalText($data, 'iso_639_1', $context),
            countryCode: TmdbData::optionalText($data, 'iso_3166_1', $context),
        );
    }

    /**
     * Entrée d'`append_to_response=alternative_titles`. Rend `null` quand le
     * titre est vide.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromAlternative(array $data, string $context): ?self
    {
        $title = TmdbData::optionalText($data, 'title', $context);

        if ($title === null) {
            return null;
        }

        return new self(
            kind: TmdbTitleKind::Alternative,
            title: $title,
            languageCode: TmdbData::optionalText($data, 'iso_639_1', $context),
            countryCode: TmdbData::optionalText($data, 'iso_3166_1', $context),
            type: TmdbData::optionalText($data, 'type', $context),
        );
    }
}
