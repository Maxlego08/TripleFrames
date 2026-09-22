<?php

namespace App\Support\Catalog;

use App\Enums\CertificationCountry;
use App\Enums\ContentFlag;
use App\Support\Tmdb\TmdbMovie;
use App\Support\Tmdb\TmdbReleaseDate;
use Carbon\CarbonImmutable;

/**
 * Le filtre de contenu appliqué à une fiche TMDB — la seule porte que **ni
 * `discover` ni la voie d'exception** ne contournent (décision 12, § 9.2).
 *
 * **Import en deux temps, et c'est ici que le second temps compte.** Les
 * certifications ne sont jamais filtrées dans `discover` — `certification.lte`
 * n'accepte qu'un pays et écarte silencieusement les non classifiés — mais lues
 * à l'appel de détail (`append_to_response=release_dates`), résolues « la plus
 * récente fait foi » en PHP, et seule la gagnante est stockée.
 *
 * Deux sorties, à ne jamais confondre :
 *
 * - `isRefused()` — le film n'entre pas au catalogue. `adult`, ou une
 *   certification retenue restrictive.
 * - `flag` — le verdict porté par `movie.content_flag` pour un film accepté.
 *   `clear` s'il existe au moins une certification non restrictive,
 *   `unrated_pending` sinon : un film sans aucune ligne reste non publiable
 *   jusqu'à la coche de curateur (§ 3.8).
 *
 * `blocked` n'est jamais rendu ici. Ce verdict n'existe qu'à la
 * **resynchronisation** d'un film déjà en base, quand une relecture découvre
 * après coup une classification restrictive : elle bascule `content_flag` et
 * **propose** une dépublication ; elle ne change jamais `availability`
 * elle-même et ne supprime jamais un fichier (§ 9.3).
 */
final readonly class ContentGate
{
    /**
     * @param  list<ResolvedCertification>  $certifications  Au plus une par pays.
     */
    private function __construct(
        public bool $adult,
        public array $certifications,
        public ContentFlag $flag,
        public ?CertificationCountry $restrictiveCountry,
    ) {}

    /**
     * Lit une fiche TMDB et rend le verdict. Ne touche à rien : l'écriture
     * appartient au service d'import.
     */
    public static function inspect(TmdbMovie $movie): self
    {
        $certifications = self::resolve($movie);

        $restrictive = null;

        foreach ($certifications as $certification) {
            if ($certification->isRestrictive) {
                $restrictive = $certification->country;
                break;
            }
        }

        $hasClear = false;

        foreach ($certifications as $certification) {
            if (! $certification->isRestrictive) {
                $hasClear = true;
                break;
            }
        }

        return new self(
            adult: $movie->adult,
            certifications: $certifications,
            flag: $restrictive !== null
                ? ContentFlag::Blocked
                : ($hasClear ? ContentFlag::Clear : ContentFlag::UnratedPending),
            restrictiveCountry: $restrictive,
        );
    }

    /**
     * Le film est-il refusé par le filtre de contenu ? `adult` d'abord — c'est
     * le drapeau que TMDB pose lui-même et il ne se discute pas —, puis toute
     * certification retenue restrictive.
     */
    public function isRefused(): bool
    {
        return $this->adult || $this->restrictiveCountry !== null;
    }

    /**
     * Le motif du refus, en **données** et jamais en chaîne pré-formatée
     * (règle 4) : une clé de traduction et ses substitutions.
     *
     * @return array{key: string, replacements: array<string, string>}|null
     */
    public function refusalReason(): ?array
    {
        if ($this->adult) {
            return ['key' => 'admin.catalog.import.refused.adult', 'replacements' => []];
        }

        if ($this->restrictiveCountry === null) {
            return null;
        }

        $certification = '';

        foreach ($this->certifications as $resolved) {
            if ($resolved->country === $this->restrictiveCountry) {
                $certification = $resolved->certification;
                break;
            }
        }

        return [
            'key' => 'admin.catalog.import.refused.certification',
            'replacements' => [
                'country' => $this->restrictiveCountry->value,
                'certification' => $certification,
            ],
        ];
    }

    /**
     * Les lignes `movie_certification` à écrire, une par pays au plus.
     *
     * @return list<array{country: CertificationCountry, certification: string, released_on: CarbonImmutable|null, is_restrictive: bool, read_at: CarbonImmutable}>
     */
    public function certificationRows(CarbonImmutable $readAt): array
    {
        return array_map(
            static fn (ResolvedCertification $resolved): array => $resolved->toAttributes($readAt),
            $this->certifications,
        );
    }

    /**
     * « La plus récente fait foi », pays par pays.
     *
     * Une sortie sans `release_date` ne peut jamais gagner contre une sortie
     * datée : TMDB laisse le champ vide sur de vieilles entrées, et la laisser
     * l'emporter rendrait le résultat dépendant de l'ordre du tableau, c'est-à-dire
     * du hasard. À dates égales, la première entrée du tableau gagne — un
     * départage stable vaut mieux qu'un départage subtil.
     *
     * @return list<ResolvedCertification>
     */
    private static function resolve(TmdbMovie $movie): array
    {
        $resolved = [];

        foreach (CertificationCountry::cases() as $country) {
            $winner = null;

            foreach ($movie->releaseDatesFor($country->value) as $release) {
                if (! $release->hasCertification()) {
                    continue;
                }

                if ($winner === null || self::isMoreRecent($release, $winner)) {
                    $winner = $release;
                }
            }

            if ($winner === null) {
                continue;
            }

            $resolved[] = new ResolvedCertification(
                country: $country,
                certification: $winner->certification,
                releasedOn: $winner->releasedOn === null
                    ? null
                    : CarbonImmutable::parse($winner->releasedOn)->startOfDay(),
                isRestrictive: RestrictiveCertifications::isRestrictive($country, $winner->certification),
            );
        }

        return $resolved;
    }

    /**
     * `$candidate` est-il strictement plus récent que `$incumbent` ?
     */
    private static function isMoreRecent(TmdbReleaseDate $candidate, TmdbReleaseDate $incumbent): bool
    {
        if ($candidate->releasedOn === null) {
            return false;
        }

        if ($incumbent->releasedOn === null) {
            return true;
        }

        return $candidate->releasedOn > $incumbent->releasedOn;
    }
}
