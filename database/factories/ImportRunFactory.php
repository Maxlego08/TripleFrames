<?php

namespace Database\Factories;

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un balayage d'import TMDB — la provenance, et la REPRISE (§ 9.1).
 *
 * Les trois natures n'ont ni le même filtre, ni le même droit d'écrasement, ni la même
 * conséquence sur `movie.is_import_exception` : `discover` applique le filtre de goût,
 * `paste` l'ignore entièrement (et marque tout film en exception), `resync` relit un
 * film déjà importé. Le filtre de CONTENU, lui, n'est contournable par aucune voie.
 *
 * Les valeurs `filter_*` de l'état par défaut sont des données de fixture, **pas** les
 * défauts du site : ceux-ci vivront dans `config('catalog.import_filter')`, que
 * `App\ValueObjects\Catalog\ImportFilter` lira. Un test qui a quelque chose à prouver
 * sur un seuil nomme le sien par {@see self::withFilter()}.
 *
 * `filter_languages` est une chaîne jointe par virgules, **jamais interrogée** :
 * affichage et rejeu, explicitement pas un critère de requête.
 *
 * @extends Factory<ImportRun>
 */
class ImportRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = CarbonImmutable::now()->subHours(2);
        $seen = fake()->numberBetween(120, 600);
        $imported = fake()->numberBetween(10, 100);
        $refused = fake()->numberBetween(0, 12);

        return [
            'run_kind' => ImportRunKind::Discover,
            'status' => ImportRunStatus::Completed,
            'actor_id' => User::factory()->curator(),
            'filter_min_vote_count' => fake()->numberBetween(100, 1_000),
            'filter_languages' => 'fr,en,ja',
            'filter_min_release_year' => fake()->numberBetween(1_950, 2_000),
            'is_widened' => false,
            'tmdb_page_cursor' => null,
            'last_request_at' => $startedAt->addHour(),
            'total_seen' => $seen,
            'total_imported' => $imported,
            'total_skipped' => max(0, $seen - $imported - $refused),
            'total_refused_content' => $refused,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->addHour(),
        ];
    }

    /**
     * Balayage filtré — la voie ordinaire.
     */
    public function discover(): static
    {
        return $this->state(['run_kind' => ImportRunKind::Discover]);
    }

    /**
     * Voie d'exception : collage d'identifiants, notoriété / langue / date IGNORÉES.
     * Les colonnes de filtre n'ont donc rien à porter.
     */
    public function paste(): static
    {
        return $this->state([
            'run_kind' => ImportRunKind::Paste,
            'filter_min_vote_count' => null,
            'filter_languages' => null,
            'filter_min_release_year' => null,
            'is_widened' => false,
        ]);
    }

    public function resync(): static
    {
        return $this->state(['run_kind' => ImportRunKind::Resync]);
    }

    /**
     * Balayage en cours : aucune fin, donc aucun compteur définitif.
     */
    public function running(): static
    {
        return $this->state([
            'status' => ImportRunStatus::Running,
            'finished_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(['status' => ImportRunStatus::Completed]);
    }

    public function failed(): static
    {
        return $this->state(['status' => ImportRunStatus::Failed]);
    }

    /**
     * Le filtre appliqué était plus large que le filtre par défaut : tout film entré
     * par ce balayage est marqué `is_import_exception`, avec son motif.
     */
    public function widened(): static
    {
        return $this->state(['is_widened' => true]);
    }

    /**
     * L'état exact qu'un worker doit retrouver au démarrage : `running` avec un curseur
     * de page — c'est ce que le scope `resumable` de {@see ImportRun} va chercher.
     */
    public function resumable(int $page = 3): static
    {
        return $this->running()->state([
            'tmdb_page_cursor' => $page,
            'last_request_at' => CarbonImmutable::now()->subMinutes(5),
        ]);
    }

    /**
     * Le filtre exact du balayage — le seul chemin par lequel un test nomme ses seuils.
     */
    public function withFilter(?int $minVoteCount, ?string $languages, ?int $minReleaseYear): static
    {
        return $this->state([
            'filter_min_vote_count' => $minVoteCount,
            'filter_languages' => $languages,
            'filter_min_release_year' => $minReleaseYear,
        ]);
    }

    /**
     * L'auteur du balayage — `nullOnDelete` : un balayage survit à son auteur.
     */
    public function actedBy(?User $actor): static
    {
        return $this->state(['actor_id' => $actor?->id]);
    }
}
