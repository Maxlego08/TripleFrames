<?php

namespace Database\Factories;

use App\Models\DataExport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Une archive d'export de données personnelles (§ 5.4).
 *
 * `path` est nommé par ULID, jamais par un identifiant devinable : le fichier produit
 * est le concentré de données personnelles le plus dense que le système fabrique, et la
 * colonne est `#[Hidden]` — l'archive se télécharge par une URL signée à durée courte.
 *
 * `deleted_at` N'EST PAS une colonne de `SoftDeletes` : c'est la date d'effacement du
 * FICHIER. {@see self::deleted()} la pose, et la ligne reste parfaitement visible.
 *
 * L'engagement est « URL signée valable sept jours » (principe 12) : `expires_at` pilote
 * le périmètre de purge `data_export`, qui supprime le fichier PUIS la ligne.
 *
 * @extends Factory<DataExport>
 */
class DataExportFactory extends Factory
{
    /**
     * Durée de vie d'une archive, en jours — l'engagement public du § 5.4. Valeur de
     * fixture : la durée applicable vivra dans la configuration de rétention.
     */
    private const int EXPIRY_DAYS = 7;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $requestedAt = CarbonImmutable::now()->subHours(3);

        return [
            'user_id' => User::factory(),
            'path' => Str::ulid()->toBase32().'.zip',
            'size_bytes' => fake()->numberBetween(4_096, 4_194_304),
            'requested_at' => $requestedAt,
            'completed_at' => $requestedAt->addMinutes(4),
            'expires_at' => $requestedAt->addDays(self::EXPIRY_DAYS),
            'downloaded_at' => null,
            'deleted_at' => null,
        ];
    }

    /**
     * Demandée, pas encore produite : le job différé n'a pas tourné, donc ni taille ni
     * date d'achèvement.
     */
    public function pending(): static
    {
        return $this->state([
            'size_bytes' => null,
            'completed_at' => null,
            'downloaded_at' => null,
            'deleted_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'completed_at' => CarbonImmutable::now(),
            'size_bytes' => fake()->numberBetween(4_096, 4_194_304),
        ]);
    }

    public function downloaded(): static
    {
        return $this->state(['downloaded_at' => CarbonImmutable::now()]);
    }

    /**
     * Échue : c'est le prédicat exact que balaie la purge, par `data_export_expiry_idx`.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => CarbonImmutable::now()->subDay()]);
    }

    /**
     * Fichier effacé du disque, ligne conservée — la trace de rétention, pas un
     * `SoftDeletes`.
     */
    public function deleted(): static
    {
        return $this->state(['deleted_at' => CarbonImmutable::now()]);
    }
}
