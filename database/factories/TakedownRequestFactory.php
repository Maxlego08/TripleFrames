<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Enums\RequesterCapacity;
use App\Enums\TakedownDecision;
use App\Enums\TakedownScopeKind;
use App\Enums\TakedownStatus;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\TakedownRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see TakedownRequest} — une demande de retrait publique et
 * sa décision motivée, **la preuve que l'engagement a été tenu** (§ 8.2).
 *
 * `reference` est le numéro public, **aléatoire base32 et jamais dérivé de
 * l'`id`** : un numéro séquentiel révélerait combien de demandes le service a
 * reçues. C'est lui, et non l'`id`, qui lie une route au dossier — d'où
 * `#[RouteKey('reference')]` sur le modèle.
 *
 * `locale` est la langue de réponse **stockée avec la demande** : le middleware
 * d'administration force `fr`, donc `App::getLocale()` enverrait tout en français
 * au moment de l'accusé et de la notification, et la voie publique n'a pas de
 * compte où lire une préférence. C'est une locale d'INTERFACE, `string(5)`.
 *
 * Deux portées, jamais confondues : `claimed_scope` est le texte **verbatim** du
 * demandeur, jamais interrogé ; `scope_kind` est la portée réellement retenue par
 * l'administrateur, elle requêtable — et elle reste donc **nulle** tant que
 * {@see self::decided()} n'a pas eu lieu. De même, `target_movie_id` et
 * `target_frame_id` sont identifiés **au tri** par l'administrateur, jamais par le
 * demandeur : ils sont nuls par défaut.
 *
 * `decision`, `decided_at` et `decided_by_id` doivent s'écrire **ensemble** :
 * {@see self::decided()} est le seul état qui les pose, et jamais l'une sans les
 * autres.
 *
 * @extends Factory<TakedownRequest>
 */
class TakedownRequestFactory extends Factory
{
    /** Alphabet base32 de `reference`, jamais dérivée de l'id (§ 1.1). */
    public const string REFERENCE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Longueur de `reference`, fixée par `char(12)`. */
    public const int REFERENCE_LENGTH = 12;

    /**
     * Define the model's default state.
     *
     * Une demande tout juste reçue : ni accusée, ni triée, ni décidée.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => self::reference(),
            'status' => TakedownStatus::Received,
            'requester_name' => fake()->name(),
            'requester_email' => fake()->unique()->safeEmail(),
            'requester_capacity' => RequesterCapacity::RightsHolder,
            'locale' => Locale::French,
            'claimed_scope' => 'Toutes les images extraites de l\'œuvre visée par la présente demande.',
            'scope_kind' => null,
            'body' => fake()->paragraph(),
            'target_movie_id' => null,
            'target_frame_id' => null,
            'received_at' => now(),
            'acknowledged_at' => null,
            'decision' => null,
            'decision_reason' => null,
            'decided_at' => null,
            'decided_by_id' => null,
            'notified_at' => null,
            'requester_anonymized_at' => null,
        ];
    }

    /**
     * Qualité déclarée du demandeur — jamais vérifiée par le schéma.
     */
    public function from(RequesterCapacity $capacity): static
    {
        return $this->state(fn (array $attributes): array => [
            'requester_capacity' => $capacity,
        ]);
    }

    /**
     * Accusé de réception envoyé, dans la langue **stockée sur la demande**.
     */
    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TakedownStatus::Acknowledged,
            'acknowledged_at' => now(),
        ]);
    }

    /**
     * Cible identifiée **au tri**, par l'administrateur. `restrictOnDelete` : la
     * purge ne peut structurellement pas atteindre la cible d'une preuve.
     */
    public function targetingMovie(Movie $movie): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_movie_id' => $movie->id,
            'scope_kind' => TakedownScopeKind::Movie,
        ]);
    }

    /**
     * Cible plus étroite qu'un film entier : une image précise.
     */
    public function targetingFrame(Frame $frame): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_frame_id' => $frame->id,
            'scope_kind' => TakedownScopeKind::Frame,
        ]);
    }

    /**
     * Décision motivée — les quatre colonnes ensemble, parce qu'une décision sans
     * auteur ni date ne prouve rien. `decided_by_id` est `nullOnDelete` : la
     * décision survit à son auteur, et c'est `admin_action.actor_name` qui en garde
     * le nom.
     */
    public function decided(
        TakedownDecision $decision = TakedownDecision::Withdrawn,
        ?User $decidedBy = null,
        ?TakedownScopeKind $scopeKind = TakedownScopeKind::Movie,
    ): static {
        return $this->state(fn (array $attributes): array => [
            'status' => TakedownStatus::Decided,
            'scope_kind' => $scopeKind,
            'decision' => $decision,
            'decision_reason' => 'Motif de décision de fabrique, jamais affiché en production.',
            'decided_at' => now(),
            'decided_by_id' => $decidedBy === null ? User::factory() : $decidedBy->id,
            'acknowledged_at' => now()->subHour(),
        ]);
    }

    /**
     * Dossier clos : le demandeur a été notifié.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TakedownStatus::Closed,
            'notified_at' => now(),
        ]);
    }

    /**
     * Périmètre de purge `takedown_identity` : les quatre colonnes d'identité sont
     * **vidées**, la ligne, ses dates, sa décision, son motif, sa portée et sa
     * cible sont **conservées**. Une demande purgée serait une preuve détruite —
     * la ligne elle-même est en périmètre INTERDIT de purge.
     */
    public function anonymized(): static
    {
        return $this->state(fn (array $attributes): array => [
            'requester_name' => '',
            'requester_email' => '',
            'claimed_scope' => null,
            'body' => '',
            'requester_anonymized_at' => now(),
        ]);
    }

    /**
     * Numéro public : base32 aléatoire, jamais un compteur.
     */
    private static function reference(): string
    {
        $alphabet = self::REFERENCE_ALPHABET;
        $last = strlen($alphabet) - 1;
        $reference = '';

        for ($index = 0; $index < self::REFERENCE_LENGTH; $index++) {
            $reference .= $alphabet[random_int(0, $last)];
        }

        return $reference;
    }
}
