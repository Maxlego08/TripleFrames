<?php

namespace App\Models;

use App\Enums\Locale;
use App\Enums\RequesterCapacity;
use App\Enums\TakedownDecision;
use App\Enums\TakedownScopeKind;
use App\Enums\TakedownStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TakedownRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une demande de retrait publique et sa décision motivée — la preuve que
 * l'engagement a été tenu (§ 8.2).
 *
 * `reference` est le numéro public, ALÉATOIRE base32 et jamais dérivé de l'`id` :
 * un numéro séquentiel révélerait combien de demandes le service a reçues. C'est
 * lui, et non l'`id`, qui lie une route au dossier.
 *
 * `locale` est la langue de réponse STOCKÉE AVEC LA DEMANDE : le middleware
 * d'administration force `fr`, donc `App::getLocale()` enverrait tout en
 * français au moment de l'accusé et de la notification, et la voie publique n'a
 * pas de compte où lire une préférence. C'est une locale d'INTERFACE, `string(5)`
 * castée par {@see Locale} — jamais une locale de catalogue `string(12)`.
 *
 * Deux portées, jamais confondues : `claimed_scope` est le texte VERBATIM du
 * demandeur, jamais interrogé ; `scope_kind` est la portée réellement retenue
 * par l'administrateur, elle requêtable. `target_movie_id` et `target_frame_id`
 * sont identifiés AU TRI par l'administrateur, pas par le demandeur, et sont en
 * `restrictOnDelete` : la purge ne peut structurellement pas atteindre la cible
 * d'une preuve.
 *
 * Aucune adresse IP malgré une voie publique anonyme : le garde-fou anti-abus
 * est un `throttle` nommé sur la route publique plus un captcha sans cookie,
 * jamais une colonne.
 *
 * **Conservation : la preuve est permanente, l'identité du tiers ne l'est pas.**
 * Le périmètre de purge `takedown_identity` vide `requester_name`,
 * `requester_email`, `claimed_scope` et `body` à l'échéance de prescription, EN
 * CONSERVANT la ligne, ses quatre dates, sa décision, son motif, sa portée et sa
 * cible, et pose `requester_anonymized_at`. La ligne elle-même est en périmètre
 * INTERDIT de purge : une demande purgée est une preuve détruite.
 *
 * La demande progresse dans sa file : elle porte des `timestamps` complets, ce
 * n'est pas une table journal. Les gabarits FR et EN d'accusé et de notification
 * vivent dans `lang/`, jamais en base.
 *
 * **`#[Hidden]` est ici une règle de sécurité, pas de cosmétique.** L'`id` n'est
 * jamais exposé au demandeur (§ 8.2) et `reference` est la seule identité
 * publique ; `requester_name` et `requester_email` sont les coordonnées d'un
 * tiers qui n'a jamais eu de compte, et la page publique de suivi sérialiserait
 * la ligne entière ; `decided_by_id` est le compte de l'administrateur ayant
 * décidé, qu'aucun identifiant interne ne doit faire quitter le serveur. Le
 * back-office qui doit afficher l'identité du demandeur la rend visible
 * explicitement, geste auditable.
 *
 * **`#[Fillable]` s'arrête au formulaire public** : nom, adresse, qualité,
 * langue, portée déclarée et corps. Tout le reste est une colonne d'autorité —
 * `reference` (frappée par le serveur), `status`, `scope_kind`, les deux cibles,
 * `decision`, `decision_reason` et les cinq horodatages —, et `decision`,
 * `decided_at` et `decided_by_id` doivent de toute façon s'écrire ensemble.
 *
 * @property int $id
 * @property string $reference
 * @property TakedownStatus $status
 * @property string $requester_name
 * @property string $requester_email
 * @property RequesterCapacity $requester_capacity
 * @property Locale $locale
 * @property string|null $claimed_scope
 * @property TakedownScopeKind|null $scope_kind
 * @property string $body
 * @property int|null $target_movie_id
 * @property int|null $target_frame_id
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property TakedownDecision|null $decision
 * @property string|null $decision_reason
 * @property CarbonImmutable|null $decided_at
 * @property int|null $decided_by_id
 * @property CarbonImmutable|null $notified_at
 * @property CarbonImmutable|null $requester_anonymized_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Movie|null $targetMovie
 * @property-read Frame|null $targetFrame
 * @property-read User|null $decidedBy
 * @property-read Collection<int, AdminAction> $adminActions
 */
#[Table('takedown_request')]
#[RouteKey('reference')]
#[Fillable([
    'requester_name',
    'requester_email',
    'requester_capacity',
    'locale',
    'claimed_scope',
    'body',
])]
#[Hidden(['id', 'requester_name', 'requester_email', 'decided_by_id'])]
class TakedownRequest extends Model
{
    /** @use HasFactory<TakedownRequestFactory> */
    use HasFactory;

    /**
     * Miroir EXACT des défauts SQL de `takedown_request` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'status' => TakedownStatus::Received->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TakedownStatus::class,
            'requester_capacity' => RequesterCapacity::class,
            'locale' => Locale::class,
            'scope_kind' => TakedownScopeKind::class,
            'decision' => TakedownDecision::class,
            'received_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'decided_at' => 'datetime',
            'notified_at' => 'datetime',
            'requester_anonymized_at' => 'datetime',
        ];
    }

    /**
     * Le film identifié au tri par l'administrateur, jamais par le demandeur.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function targetMovie(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'target_movie_id');
    }

    /**
     * L'image précise, pour un retrait plus étroit qu'un film entier.
     *
     * @return BelongsTo<Frame, $this>
     */
    public function targetFrame(): BelongsTo
    {
        return $this->belongsTo(Frame::class, 'target_frame_id');
    }

    /**
     * L'administrateur qui a décidé. `nullOnDelete` : la décision survit à son
     * auteur, et c'est `admin_action.actor_name` qui en garde le nom.
     *
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /**
     * Tout geste pris en exécution de cette demande. Sans cette clé, la seule
     * jointure disponible serait une corrélation d'horodatages entre
     * `decided_at` et `created_at`, qui ne prouve rien quand deux demandes
     * visent le même film la même semaine.
     *
     * @return HasMany<AdminAction, $this>
     */
    public function adminActions(): HasMany
    {
        return $this->hasMany(AdminAction::class, 'takedown_request_id');
    }
}
