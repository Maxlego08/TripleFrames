<?php

namespace App\Support\Tmdb;

/**
 * Une sortie datée et son classement, lue dans `append_to_response=release_dates`.
 *
 * **La chaîne de certification est conservée BRUTE** (`18`, `-16`, `NC-17`, `X`,
 * `R`) : la réinterpréter ici perdrait la preuve de ce que TMDB a réellement
 * répondu, et `movie_certification.certification` la stocke telle quelle
 * (§ 3.8). Savoir laquelle est restrictive, et laquelle l'emporte quand un pays
 * en porte plusieurs — « la plus récente fait foi », résolue en PHP, jamais en
 * SQL — appartient au service d'import, pas au client.
 *
 * `releasedOn` est la date de la sortie qui PORTE ce classement. Sans elle,
 * « la plus récente fait foi » ne serait pas une règle vérifiable et un film
 * classé X à sa sortie puis reclassé serait exclu à tort.
 *
 * Une certification vide n'est pas une absence de ligne : elle dit que TMDB
 * connaît cette sortie et ne lui associe aucun classement. Le film reste alors
 * `content_flag = 'unrated_pending'`, donc non publiable jusqu'à la coche d'un
 * curateur.
 */
final readonly class TmdbReleaseDate
{
    /** Avant-première. */
    public const int TYPE_PREMIERE = 1;

    /** Sortie en salles limitée. */
    public const int TYPE_THEATRICAL_LIMITED = 2;

    /** Sortie en salles. */
    public const int TYPE_THEATRICAL = 3;

    /** Sortie numérique. */
    public const int TYPE_DIGITAL = 4;

    /** Sortie physique. */
    public const int TYPE_PHYSICAL = 5;

    /** Diffusion télévisée. */
    public const int TYPE_TV = 6;

    public function __construct(
        public string $countryCode,
        public string $certification,
        public ?string $releasedOn,
        public int $type,
        public ?string $languageCode = null,
        public ?string $note = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Une entrée de `release_dates[].release_dates[]`.
     * @param  string  $countryCode  `iso_3166_1` du groupe parent, absent de l'entrée.
     */
    public static function fromArray(array $data, string $countryCode, string $context): self
    {
        return new self(
            countryCode: strtoupper($countryCode),
            certification: TmdbData::rawText($data, 'certification', $context),
            releasedOn: TmdbData::date(TmdbData::optionalText($data, 'release_date', $context)),
            type: TmdbData::optionalInteger($data, 'type', $context) ?? 0,
            languageCode: TmdbData::optionalText($data, 'iso_639_1', $context),
            note: TmdbData::optionalText($data, 'note', $context),
        );
    }

    /**
     * Vrai si TMDB associe un classement à cette sortie. Ne dit RIEN de son
     * caractère restrictif : `is_restrictive` est une décision d'import.
     */
    public function hasCertification(): bool
    {
        return $this->certification !== '';
    }
}
