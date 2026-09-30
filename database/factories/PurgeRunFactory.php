<?php

namespace Database\Factories;

use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Models\PurgeRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see PurgeRun} — le journal d'exécution de la purge, sonde
 * de **la seule panne du projet dont la conséquence est juridique** : une purge
 * silencieusement arrêtée rend fausse une durée annoncée publiquement (§ 11.3).
 *
 * Une ligne par couple (exécution, périmètre). **`finished_at` NULL signifie « en
 * cours OU plantée »** : c'est la même absence, et c'est voulu — une sonde qui
 * distinguerait les deux aurait besoin d'un battement que personne n'écrirait.
 * {@see self::running()} et {@see self::failed()} laissent donc toutes deux
 * `finished_at` à `null`, et seul `status` les sépare.
 *
 * **Aucune clé étrangère, aucune donnée personnelle** : la table ne dit rien d'une
 * personne, seulement d'un balayage. Il n'y a donc aucun état « par utilisateur »
 * à exposer, et il ne faut pas en inventer.
 *
 * `ran_at` porte l'auto-purge à 13 mois — une fenêtre de plus que la plus longue
 * durée que la table atteste — et se distingue de `started_at`, que
 * `purge_run_scope_idx` sert.
 *
 * > **La colonne `scope` est `string(32)`, et pas 20** : `framework_failed_jobs`
 * > (21) et `framework_reset_tokens` (22) dépassent la borne courte, MySQL strict
 * > lèverait une 1406 à l'insertion là où SQLite tronquerait en silence.
 * > {@see self::forScope()} accepte donc **tous** les cas de {@see PurgeScope}
 * > sans précaution, et un test qui balaie l'enum entier est le bon test.
 *
 * @extends Factory<PurgeRun>
 */
class PurgeRunFactory extends Factory
{
    /** Durée de fabrique d'un balayage, en millisecondes. */
    public const int DEFAULT_DURATION_MS = 120_000;

    /**
     * Define the model's default state.
     *
     * Un balayage terminé, de deux minutes, sur le périmètre des faits de partie.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => PurgeScope::GameFacts,
            'status' => PurgeRunStatus::Completed,
            'started_at' => now()->subMilliseconds(self::DEFAULT_DURATION_MS),
            'finished_at' => now(),
            'rows_deleted' => 128,
            'batches' => 2,
            'duration_ms' => self::DEFAULT_DURATION_MS,
            'error' => null,
            'ran_at' => now(),
        ];
    }

    /**
     * Périmètre imposé — y compris les deux cas de plus de vingt caractères, que
     * `string(32)` accepte.
     */
    public function forScope(PurgeScope $scope): static
    {
        return $this->state(fn (array $attributes): array => [
            'scope' => $scope,
        ]);
    }

    /**
     * Balayage **en cours** : `finished_at` et `duration_ms` sont nuls.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PurgeRunStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'duration_ms' => null,
            'rows_deleted' => 0,
            'batches' => 0,
            'error' => null,
        ]);
    }

    /**
     * Balayage **planté** : `finished_at` reste nul — la même absence qu'« en
     * cours » —, seul `status` et le message d'exploitation le disent. Le job étant
     * résilient ligne à ligne, `rows_deleted` peut être non nul sur un échec.
     */
    public function failed(string $error = 'Échec de fabrique, jamais affiché en production.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PurgeRunStatus::Failed,
            'finished_at' => null,
            'duration_ms' => null,
            'error' => $error,
        ]);
    }

    /**
     * Balayage qui n'a rien supprimé — l'entrée de la sonde n° 4 du § 11.3, « un
     * périmètre n'a supprimé aucune ligne depuis 48 h alors que des lignes sont
     * éligibles ».
     */
    public function deletedNothing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rows_deleted' => 0,
            'batches' => 0,
        ]);
    }

    /**
     * Exécution ancienne : c'est `ran_at` qui pilote l'auto-purge à 13 mois.
     */
    public function ranMonthsAgo(int $months): static
    {
        return $this->state(fn (array $attributes): array => [
            'started_at' => now()->subMonths($months),
            'finished_at' => now()->subMonths($months),
            'ran_at' => now()->subMonths($months),
        ]);
    }
}
