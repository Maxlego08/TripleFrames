<?php

namespace App\Models;

use App\Enums\AdminActionRetention;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\UserRole;
use App\Support\Eloquent\AppendOnlyBuilder;
use Carbon\CarbonImmutable;
use Database\Factories\AdminActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Le journal en AJOUT SEUL de tout geste engageant : qui, quoi, sur quoi,
 * pourquoi, quand (§ 8.3).
 *
 * L'ordre des identifiants est l'ordre du journal. `actor_name` est
 * l'instantané de `users.name` à l'instant du geste — sans lui, l'auteur d'un
 * retrait juridique devient « n° 42 » dès qu'il supprime son compte —, et il est
 * exclu de l'anonymisation.
 *
 * **La valeur réservée `system`.** Pour les deux gestes AUTOMATIQUES —
 * `avatar.hidden` et `nickname.masked`, déclenchés par le seuil de deux
 * signaleurs distincts et non par une personne —, `actor_id` est NULL et
 * `actor_name` porte {@see self::SYSTEM_ACTOR} : sans elle, l'insertion serait
 * structurellement impossible sur une colonne NOT NULL, et une colonne nullable
 * rendrait indistinguables « geste automatique » et « oubli d'écriture ».
 * Aucune autre action n'écrit cette valeur, et la garde de `creating` ci-dessous
 * en fait une propriété du modèle et non une consigne.
 *
 * `subject_type` porte un ALIAS COURT (`movie` / `frame` / `user` / `player` /
 * `takedown_request`) et non un nom de classe : ce n'est PAS un `morphTo`, et
 * `subject_id` n'a aucune clé étrangère, la cible étant polymorphe — elle n'est
 * jamais détruite, donc la ligne ne pend jamais. C'est cette colonne typée qui
 * rend l'exemption de purge vérifiable par requête.
 *
 * `role_before` et `role_after` sont typées et non JSON, parce que « quel rôle
 * portait cette personne à cet instant » est la seule requête d'audit qui doit
 * s'écrire en SQL : il n'existe AUCUNE table `role_history`, elle dupliquerait
 * ce journal.
 *
 * **L'exemption de purge est une propriété du schéma, pas une clause `WHERE`.**
 * L'index de purge étant `(retention_class, created_at)`, le balayage ne
 * rencontre structurellement jamais une ligne `permanent` : elle est hors de
 * l'index parcouru, et non exclue par une condition qu'un futur développeur
 * pourrait oublier. Encore faut-il que `retention_class` soit juste : elle est
 * donc DÉRIVÉE DE L'ACTION à l'insertion ({@see AdminActionType::retentionClass()}),
 * jamais fournie par un appelant.
 *
 * **Ajout seul.** La table n'a pas d'`updated_at` : `const UPDATED_AT = null`,
 * sans quoi `$timestamps = true` écrirait une colonne inexistante (1054 en
 * MySQL, « no such column » en SQLite). Une garde sur `updating` refuse toute
 * réécriture ; la SUPPRESSION reste permise, c'est elle que la purge exerce sur
 * les seules lignes `rolling_12m`.
 *
 * `#[Hidden]` : `actor_id` est le compte de l'auteur, et aucun identifiant
 * interne ne quitte le serveur — `actor_name` est précisément la colonne qui
 * existe pour être affichée à sa place.
 *
 * `#[Fillable]` vide : le journal se compose en code, geste par geste. Toutes
 * ses colonnes sont d'autorité, `retention_class` nommément, et ses quatre
 * colonnes NOT NULL font échouer bruyamment (1364) toute écriture par tableau
 * de requête.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $actor_name
 * @property AdminActionType $action
 * @property AdminActionSubject $subject_type
 * @property int|null $subject_id
 * @property int|null $takedown_request_id
 * @property AdminActionRetention $retention_class
 * @property string|null $reason
 * @property int|null $reports_count
 * @property UserRole|null $role_before
 * @property UserRole|null $role_after
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $actor
 * @property-read TakedownRequest|null $takedownRequest
 */
#[Table('admin_action')]
#[Fillable([])]
#[Hidden(['actor_id'])]
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class AdminAction extends Model
{
    /** @use HasFactory<AdminActionFactory> */
    use HasFactory;

    /** La table ne porte que `created_at` (§ 8.3). */
    public const UPDATED_AT = null;

    /**
     * Valeur réservée d'`actor_name` pour les deux gestes déclenchés par un
     * seuil et non par une personne. Aucune autre action ne l'écrit.
     */
    public const string SYSTEM_ACTOR = 'system';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AdminActionType::class,
            'subject_type' => AdminActionSubject::class,
            'retention_class' => AdminActionRetention::class,
            'role_before' => UserRole::class,
            'role_after' => UserRole::class,
        ];
    }

    /**
     * Deux gardes, et aucun déclencheur SQL.
     *
     * À l'insertion, `retention_class` est dérivée de l'action : une trace ne
     * peut jamais être plus courte que l'état qu'elle justifie, et une classe
     * fournie à la main finirait par diverger de la liste du § 8.3. Une ligne
     * sans action est un bug d'appel — elle échoue ici, bruyamment, plutôt que
     * d'entrer en base avec une classe de conservation fausse. La même garde
     * refuse la valeur réservée `system` à toute action non automatique.
     *
     * Ensuite, le journal est en ajout seul : `updating` refuse. `deleting` ne
     * l'est pas — la purge supprime les lignes `rolling_12m` à 12 mois, et c'est
     * le seul geste qui les touche.
     *
     * **Cette garde ne couvre que l'écriture d'une INSTANCE.** Aucun événement de
     * modèle n'est émis par une mise à jour de masse : c'est
     * {@see AppendOnlyBuilder}, posé par `#[UseEloquentBuilder]`, qui ferme
     * `AdminAction::where(...)->update([...])` — l'écriture naturelle de
     * l'anonymisation de compte, alors que `actor_name` en est nommément exclue
     * (§ 8.3). Ni l'une ni l'autre ne couvre `DB::table('admin_action')`.
     */
    protected static function booted(): void
    {
        static::creating(function (self $action): void {
            $action->retention_class = $action->action->retentionClass();

            if ($action->actor_name === self::SYSTEM_ACTOR && ! $action->action->isAutomatic()) {
                throw new LogicException(
                    'La valeur réservée ['.self::SYSTEM_ACTOR.'] est refusée à l\'action ['.$action->action->value.'] : seuls les gestes automatiques s\'écrivent sans acteur.',
                );
            }
        });

        static::updating(function (self $action): void {
            throw new LogicException(
                'Le journal d\'administration est en ajout seul : la ligne ['.$action->id.'] ne peut pas être modifiée.',
            );
        });
    }

    /**
     * L'auteur du geste. `nullOnDelete`, et en pratique toujours renseigné :
     * l'anonymisation garde la ligne `User`. NULL signifie « geste automatique »,
     * et `actor_name` vaut alors {@see self::SYSTEM_ACTOR}.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * La demande de retrait en exécution de laquelle le geste a été pris, s'il
     * y en a une. `restrictOnDelete` : la preuve ne peut pas disparaître sous
     * le geste qu'elle motive.
     *
     * @return BelongsTo<TakedownRequest, $this>
     */
    public function takedownRequest(): BelongsTo
    {
        return $this->belongsTo(TakedownRequest::class, 'takedown_request_id');
    }
}
