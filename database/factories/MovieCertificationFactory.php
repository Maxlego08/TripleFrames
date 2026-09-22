<?php

namespace Database\Factories;

use App\Enums\CertificationCountry;
use App\Models\Movie;
use App\Models\MovieCertification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * La base unique du filtre de contenu (§ 3.8) — une seule certification retenue
 * par pays, la plus récente ayant fait foi lors de la résolution **en PHP**.
 *
 * `certification` est la chaîne brute TMDB conservée telle quelle (`18`, `-16`,
 * `NC-17`, `X`, `R`) : la réinterpréter à l'import perdrait la preuve de ce que
 * TMDB a réellement répondu. `is_restrictive` est dénormalisée à l'écriture —
 * c'est elle qui fait basculer `movie.content_flag` en `blocked` sans analyser
 * une chaîne à chaque lecture, et jamais l'inverse.
 *
 * Un film **sans aucune ligne** reste en `content_flag = unrated_pending`, donc
 * non publiable jusqu'à la coche de curateur
 * ({@see MovieFactory::contentVerifiedBy()}) : les deux voies du § 13.3 sont
 * exclusives, et un film de démonstration doit emprunter l'une des deux.
 *
 * UNIQUE `movie_cert_movie_country_uq (movie_id, country)` : un `->count(2)`
 * sans changer de pays échoue, et c'est le comportement voulu.
 *
 * @extends Factory<MovieCertification>
 */
class MovieCertificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * La certification FR la plus permissive, non restrictive.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'movie_id' => Movie::factory(),
            'country' => CertificationCountry::France,
            'certification' => 'Tous publics',
            'released_on' => fake()->date(),
            'is_restrictive' => false,
            'read_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * Une certification nommée, pour un pays nommé. `is_restrictive` est
     * **toujours** passée explicitement : le schéma ne réinterprète jamais la
     * chaîne, c'est l'import qui décide et la colonne qui garde la décision.
     */
    public function forCountry(
        CertificationCountry $country,
        string $certification,
        bool $isRestrictive = false,
    ): static {
        return $this->state([
            'country' => $country,
            'certification' => $certification,
            'is_restrictive' => $isRestrictive,
        ]);
    }

    /**
     * La certification US par défaut d'un film tout public.
     */
    public function unitedStates(string $certification = 'PG', bool $isRestrictive = false): static
    {
        return $this->forCountry(CertificationCountry::UnitedStates, $certification, $isRestrictive);
    }

    /**
     * Une certification restrictive (FR -18, US NC-17, US X) : découvrir cette
     * ligne sur un film déjà curé bascule `content_flag` en `blocked` et
     * **propose** une dépublication — jamais de dépublication automatique,
     * jamais de destruction de fichier.
     */
    public function restrictive(): static
    {
        return $this->forCountry(CertificationCountry::France, '-18', true);
    }

    /**
     * La date de la sortie qui porte cette certification. Sans elle, « la plus
     * récente fait foi » ne serait pas vérifiable et Orange mécanique, classé X
     * puis reclassé, serait exclu à tort.
     */
    public function releasedOn(CarbonImmutable $releasedOn): static
    {
        return $this->state(['released_on' => $releasedOn]);
    }

    /**
     * L'instant de lecture TMDB : l'écran de différences d'une
     * resynchronisation compare la nouvelle lecture à celle-ci.
     */
    public function readAt(CarbonImmutable $readAt): static
    {
        return $this->state(['read_at' => $readAt]);
    }
}
