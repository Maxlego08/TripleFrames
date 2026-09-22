<?php

namespace Database\Factories;

use App\Models\Movie;
use App\Models\NearMiss;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see NearMiss} — les chaînes quasi-justes **agrégées par
 * film**, au-dessus d'un seuil de k-anonymat (§ 7.9).
 *
 * **La table n'a AUCUNE colonne de joueur** : ni `player_id`, ni `room_id`, ni
 * `game_id`, ni `round_id`, ni adresse IP, ni locale, et `dismissed_at` est sans
 * auteur. `movie_id` est son seul lien, et il ne désigne personne. Une fabrique
 * qui exposerait un état « par joueur » n'aurait donc littéralement aucune colonne
 * où l'écrire — c'est la qualification « agrégée et sans aucune donnée
 * personnelle » de la page de confidentialité qui en dépend.
 *
 * Deux gardes de k-anonymat que la fabrique **respecte dans ses défauts**, parce
 * qu'une ligne de fixture qui les violerait rendrait vert un test de purge ou de
 * curation sur un montage illégal :
 *
 * 1. une ligne ne naît qu'au **troisième** couple (manche, chaîne) distinct — d'où
 *    `occurrences` et `distinct_rounds` à 3, jamais à 1, une ligne
 *    `occurrences = 1` étant la saisie d'UNE personne ;
 * 2. `first_seen_on` et `last_seen_on` sont à granularité **mensuelle** (date au
 *    1er du mois) ; `distinct_rounds` remplace la date fine pour trier la file.
 *
 * Purge à **90 jours sur `last_seen_on`**, et non à 12 mois.
 *
 * @extends Factory<NearMiss>
 */
class NearMissFactory extends Factory
{
    /** Seuil de k-anonymat : en dessous, la ligne n'existe pas (§ 7.9). */
    public const int MIN_OCCURRENCES = 3;

    /**
     * Formes quasi-justes de fabrique — déjà repliées par le même normaliseur
     * qu'`answer_key`, d'où leur longueur de 200.
     *
     * @var list<string>
     */
    private const array NORMALIZED_TEXTS = [
        'ombre et cit', 'ombres et cite', 'lombre de la cite',
        'riviere dorage', 'le serment dhorizon', 'chasseur du silence',
    ];

    /**
     * Define the model's default state.
     *
     * Une ligne tout juste au-dessus du seuil, vue ce mois-ci, non rejetée.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'movie_id' => Movie::factory(),
            'normalized_text' => self::NORMALIZED_TEXTS[random_int(0, count(self::NORMALIZED_TEXTS) - 1)],
            'occurrences' => self::MIN_OCCURRENCES,
            'distinct_rounds' => self::MIN_OCCURRENCES,
            'best_distance' => 2,
            'first_seen_on' => now()->startOfMonth(),
            'last_seen_on' => now()->startOfMonth(),
            'dismissed_at' => null,
        ];
    }

    /**
     * File d'un film existant — l'unique `near_miss_movie_text_uq` porte sur le
     * couple (film, forme normalisée), donc deux lignes du même film exigent deux
     * chaînes différentes.
     */
    public function forMovie(Movie $movie): static
    {
        return $this->state(fn (array $attributes): array => [
            'movie_id' => $movie->id,
        ]);
    }

    /**
     * Forme normalisée imposée — déjà repliée : la table ne normalise rien.
     */
    public function withText(string $normalizedText): static
    {
        return $this->state(fn (array $attributes): array => [
            'normalized_text' => $normalizedText,
        ]);
    }

    /**
     * Ligne saillante de la file du curateur, servie par
     * `near_miss_movie_occ_idx (movie_id, occurrences)`.
     */
    public function frequent(int $occurrences = 42, int $distinctRounds = 17): static
    {
        return $this->state(fn (array $attributes): array => [
            'occurrences' => max(self::MIN_OCCURRENCES, $occurrences),
            'distinct_rounds' => max(self::MIN_OCCURRENCES, $distinctRounds),
        ]);
    }

    /**
     * Ligne ancienne : c'est `last_seen_on` qui pilote la purge à 90 jours, et la
     * granularité reste mensuelle.
     */
    public function lastSeenMonthsAgo(int $months): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_seen_on' => now()->subMonths($months + 1)->startOfMonth(),
            'last_seen_on' => now()->subMonths($months)->startOfMonth(),
        ]);
    }

    /**
     * Rejetée par le curateur — geste **sans auteur** : aucune clé vers `users`
     * n'existe sur cette table, sous aucun nom.
     */
    public function dismissed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'dismissed_at' => now(),
        ]);
    }
}
