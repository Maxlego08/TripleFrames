<?php

namespace Database\Factories;

use App\Enums\FrameSourceKind;
use App\Enums\ReviewDecision;
use App\Enums\UserRole;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\User;
use App\Support\Curation\ExclusionGrid;
use App\Support\Eloquent\AppendOnlyBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de {@see FrameReview} — la preuve opposable d'un passage de revue.
 *
 * Table en AJOUT SEUL : une frame accumule une ligne par passage, jamais une mise
 * à jour. Cette fabrique n'écrit donc que des INSERT ; le garde `saving` du modèle
 * et {@see AppendOnlyBuilder} refusent tout le reste, y
 * compris `FrameReview::query()->update(...)`. Aucun état d'ici ne rattrape une
 * ligne déjà écrite : on en ajoute une nouvelle.
 *
 * **`reviewed_hash` n'est jamais tiré au hasard face à un `published_hash` tiré
 * ailleurs.** {@see self::forFrame()} le prend sur la frame, et c'est ce lien
 * — plus `grid_version` à la version courante — qui satisfait la condition de
 * publication. Deux empreintes indépendantes donneraient un catalogue de
 * démonstration complet dont aucun film n'est publiable, et un lobby qui affiche
 * « 0 film » sans qu'aucune ligne ne paraisse manquer.
 *
 * `reviewer_name` et `reviewer_role` sont des INSTANTANÉS pris à l'instant de la
 * revue, et non des lectures de la relation : la preuve doit rester nominative
 * quand le compte du curateur est anonymisé, faute de quoi elle se réduit à
 * « relecteur n° 42 ». `reviewer_id` est donc nullable — et nul par défaut ici,
 * parce qu'une revue sans compte survivant est un état légitime. Le seeder de
 * démonstration, lui, passe le curateur par {@see self::by()} : c'est l'exigence 5
 * du § 13.3.
 *
 * @extends Factory<FrameReview>
 */
class FrameReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'frame_id' => Frame::factory(),
            'reviewer_id' => null,
            'reviewer_name' => fake()->name(),
            'reviewer_role' => UserRole::Curator,
            'grid_version' => ExclusionGrid::CURRENT_VERSION,
            'decision' => ReviewDecision::Passed,
            'reviewed_hash' => hash('sha256', random_bytes(32)),
            'declared_source_kind' => FrameSourceKind::Tmdb,
            'declared_source_reference' => null,
            'answers' => array_fill_keys(ExclusionGrid::slugs(), true),
            'reviewed_at' => now(),
        ];
    }

    /**
     * Rattache la revue à une frame précise, et surtout à ses OCTETS.
     *
     * `reviewed_hash` vaut `published_hash` dès que le dérivé existe : c'est ce
     * qui périme mécaniquement la revue après un re-recadrage, sans qu'aucune
     * ligne ne soit modifiée. Tant que le job n'a rien produit, la revue ne peut
     * porter que sur les octets reçus, donc sur `source_hash`.
     *
     * `declared_source_kind` et `declared_source_reference` sont eux aussi des
     * instantanés : une source déclarée après coup n'aurait aucune valeur de
     * preuve. La référence est un chemin TMDB ou un timecode textuel, JAMAIS
     * l'outil ni la méthode d'extraction.
     *
     * À chaîner AVANT {@see self::rejected()}, qui décide du verdict et de
     * l'item en cause.
     */
    public function forFrame(Frame $frame): static
    {
        return $this->state(fn (array $attributes): array => [
            'frame_id' => $frame->id,
            'reviewed_hash' => $frame->published_hash ?? $frame->source_hash,
            'declared_source_kind' => $frame->source_kind,
            'declared_source_reference' => $frame->source_kind === FrameSourceKind::Tmdb
                ? $frame->tmdb_file_path
                : self::timecode($frame->source_timecode_ms),
            'answers' => array_fill_keys(ExclusionGrid::slugsFor($frame->frame_level), true),
        ]);
    }

    /**
     * Le curateur qui a réellement exercé la grille, figé sur la ligne.
     *
     * Le rôle est celui porté À L'INSTANT de la revue : la preuve devient
     * autoportante, sans interroger l'historique des rôles.
     */
    public function by(User $reviewer): static
    {
        return $this->state(fn (array $attributes): array => [
            'reviewer_id' => $reviewer->id,
            'reviewer_name' => $reviewer->name,
            'reviewer_role' => $reviewer->role,
        ]);
    }

    /**
     * Revue passante : la grille est BLOQUANTE, donc tout item applicable est
     * répondu. Un item resté sans réponse ne publie rien.
     */
    public function passed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'decision' => ReviewDecision::Passed,
            'answers' => array_fill_keys(array_keys(self::answersFrom($attributes)), true),
        ]);
    }

    /**
     * Revue rejetée : un item d'exclusion au moins est en défaut.
     *
     * Les réponses restent clées par SLUG, donc relisibles sans le code de la
     * version d'alors — c'est tout ce que le schéma garantit du contenu de la
     * grille, qui n'a volontairement aucune table.
     */
    public function rejected(?string $failedSlug = null): static
    {
        return $this->state(function (array $attributes) use ($failedSlug): array {
            $answers = array_fill_keys(array_keys(self::answersFrom($attributes)), true);
            $slug = $failedSlug ?? (string) array_key_first($answers);

            $answers[$slug] = false;

            return [
                'decision' => ReviewDecision::Rejected,
                'answers' => $answers,
            ];
        });
    }

    /**
     * Version de grille effectivement appliquée.
     *
     * Une seule version est publiée aujourd'hui : une revue « à re-revoir » se
     * fabrique donc en citant explicitement une version autre que
     * {@see ExclusionGrid::CURRENT_VERSION}, sans qu'aucune version périmée
     * n'existe encore dans le dépôt.
     */
    public function gridVersion(int $version): static
    {
        return $this->state(fn (array $attributes): array => [
            'grid_version' => $version,
        ]);
    }

    /**
     * Les réponses déjà posées par les états précédents, ou la grille complète.
     *
     * Le filtrage n'est pas décoratif : `frame_review.answers` est annotée
     * `array<string, string|bool|null>` et le niveau 7 lit cette annotation, or
     * un état antérieur reçoit `$attributes` en `mixed`.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|bool|null>
     */
    private static function answersFrom(array $attributes): array
    {
        $candidate = $attributes['answers'] ?? null;

        if (! is_array($candidate)) {
            return array_fill_keys(ExclusionGrid::slugs(), true);
        }

        $answers = [];

        foreach ($candidate as $slug => $answer) {
            if (is_string($slug) && (is_string($answer) || is_bool($answer) || $answer === null)) {
                $answers[$slug] = $answer;
            }
        }

        return $answers === [] ? array_fill_keys(ExclusionGrid::slugs(), true) : $answers;
    }

    /**
     * Timecode textuel d'une capture — un instant DANS L'ŒUVRE, et rien d'autre.
     */
    private static function timecode(?int $milliseconds): ?string
    {
        if ($milliseconds === null) {
            return null;
        }

        return sprintf(
            '%02d:%02d:%02d.%03d',
            intdiv($milliseconds, 3_600_000),
            intdiv($milliseconds, 60_000) % 60,
            intdiv($milliseconds, 1_000) % 60,
            $milliseconds % 1_000,
        );
    }
}
