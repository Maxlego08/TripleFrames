<?php

namespace App\Models;

use App\Casts\AdminActionDetailsCast;
use App\Enums\AdminActionRetention;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\UserRole;
use App\Support\Admin\AdminJournal;
use App\Support\Eloquent\AppendOnlyBuilder;
use App\ValueObjects\Admin\AdminActionDetails;
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
 * l'instantané ROGNÉ de `users.real_name` — le nom réel (D12 du 23/09),
 * jamais `users.name`, pseudo de compte — à l'instant du geste : sans lui,
 * l'auteur d'un retrait juridique devient « n° 42 » dès qu'il supprime son
 * compte. Il est exclu de l'anonymisation, qui vide `users.real_name` mais
 * jamais cet instantané.
 *
 * **Deux valeurs réservées, jamais un nom réel** (comparaison sans tenir
 * compte de la casse), et `actor_id` NULL pour elles seules :
 *
 * - {@see self::SYSTEM_ACTOR} pour les deux gestes AUTOMATIQUES —
 *   `avatar.hidden` et `nickname.masked`, déclenchés par le seuil de deux
 *   signaleurs distincts et non par une personne ;
 * - {@see self::CONSOLE_ACTOR} pour les gestes passés par la ligne de
 *   commande, admis pour `role.changed` (premier administrateur),
 *   `site.closed` et `site.reopened` seulement.
 *
 * Sans valeur réservée, l'insertion serait structurellement impossible sur une
 * colonne NOT NULL, et une colonne nullable rendrait indistinguables « geste
 * automatique » et « oubli d'écriture ». La garde `creating` ci-dessous fait de
 * ces règles une propriété du modèle et non une consigne.
 *
 * `subject_type` porte un ALIAS COURT (`movie` / `frame` / `user` / `player` /
 * `takedown_request` / `site`) et non un nom de classe : ce n'est PAS un
 * `morphTo`, et `subject_id` n'a aucune clé étrangère, la cible étant
 * polymorphe — elle n'est jamais détruite, donc la ligne ne pend jamais. C'est
 * cette colonne typée qui rend l'exemption de purge vérifiable par requête.
 * `subject_id` est NULL si et seulement si le sujet n'a pas d'identifiant :
 * le site, ou l'ensemble des comptes d'une lecture sensible (D41 du 30/09).
 *
 * `details` (D41 du 30/09) : le complément typé
 * ({@see AdminActionDetails}) des gestes qui détruisent ou écrasent ce qu'ils
 * changent — obligatoire pour eux ({@see AdminActionType::hasDetails()}), NULL
 * partout ailleurs, borné à {@see AdminActionDetails::MAX_BYTES} octets.
 * Affiché seulement, jamais lu dans une clause `WHERE`.
 *
 * `role_before` et `role_after` sont typées et non JSON, parce que « quel rôle
 * portait cette personne à cet instant » est la seule requête d'audit qui doit
 * s'écrire en SQL : il n'existe AUCUNE table `role_history`, elle dupliquerait
 * ce journal. Non nulles et différentes pour `role.changed`, nulles partout
 * ailleurs.
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
 * **Écrivain unique** : {@see AdminJournal}, qui exige en plus une transaction
 * ouverte. La garde de ce modèle vaut pour TOUT chemin d'écriture d'une
 * instance, fabriques comprises.
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
 * @property AdminActionDetails|null $details
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
     * Valeur réservée d'`actor_name` pour un geste pris depuis la ligne de
     * commande — déplacée depuis `FirstAdminCommand` (contrat C14).
     *
     * **Jamais `system`** : une console n'est pas un seuil de signalement,
     * c'est une personne devant un terminal, et le journal doit pouvoir les
     * distinguer dix-huit mois plus tard.
     */
    public const string CONSOLE_ACTOR = 'console';

    /** Longueur déclarée de `admin_action.reason` (`string(500)`, § 8.3). */
    public const int REASON_MAX_LENGTH = 500;

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
            'details' => AdminActionDetailsCast::class,
        ];
    }

    /**
     * Vrai si la valeur désigne un des deux acteurs réservés, sans tenir
     * compte de la casse ni des blancs de bord : `System` ou ` CONSOLE ` ne
     * sont jamais des noms réels, ni sur un compte, ni au journal.
     */
    public static function isReservedActorName(string $name): bool
    {
        $folded = mb_strtolower(trim($name));

        return $folded === self::SYSTEM_ACTOR || $folded === self::CONSOLE_ACTOR;
    }

    /**
     * Deux gardes, et aucun déclencheur SQL.
     *
     * À l'insertion, les sept invariants du contrat C14 (§ 2.7 de `20`) :
     * `subject_type` et `retention_class` DÉRIVÉS de l'action, jamais fournis ;
     * `subject_id` NULL si et seulement si le sujet est le site ; les deux
     * acteurs réservés et leurs seuls gestes ; un auteur identifié et nommé
     * partout ailleurs ; le motif obligatoire ; les rôles de `role.changed`.
     * Une ligne sans action est un bug d'appel — elle échoue ici, bruyamment,
     * plutôt que d'entrer en base avec une classe de conservation fausse.
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
            $type = $action->getAttribute('action');

            if (! $type instanceof AdminActionType) {
                throw new LogicException('Une ligne du journal d\'administration exige une action de la liste fermée.');
            }

            $action->subject_type = $type->subject();
            $action->retention_class = $type->retentionClass();

            self::guardSubject($action, $type);
            self::guardActor($action, $type);
            self::guardReason($action, $type);
            self::guardRoles($action, $type);
            self::guardDetails($action, $type);
        });

        static::updating(function (self $action): void {
            throw new LogicException(
                'Le journal d\'administration est en ajout seul : la ligne ['.$action->id.'] ne peut pas être modifiée.',
            );
        });
    }

    /**
     * L'auteur du geste. `nullOnDelete`, et en pratique toujours renseigné :
     * l'anonymisation garde la ligne `User`. NULL signifie « acteur réservé »,
     * et `actor_name` vaut alors {@see self::SYSTEM_ACTOR} ou
     * {@see self::CONSOLE_ACTOR}.
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

    /**
     * Invariant 2 : `subject_id` NULL si et seulement si le sujet n'a pas
     * d'identifiant — le site, ou l'ensemble des comptes (D41 du 30/09).
     */
    private static function guardSubject(self $action, AdminActionType $type): void
    {
        $identified = $type->subject()->hasIdentifier();

        if ($identified === ($action->subject_id === null)) {
            throw new LogicException(
                'L\'action ['.$type->value.'] '.($identified
                    ? 'exige un subject_id : seuls le site et l\'ensemble des comptes sont des sujets sans identifiant.'
                    : 'vise un sujet sans identifiant : son subject_id doit rester nul.'),
            );
        }
    }

    /**
     * Invariants 3, 4 et 5 : les acteurs réservés, leurs seuls gestes, et un
     * auteur identifié et nommé partout ailleurs. L'instantané est rogné ici,
     * pour que « Camille » et « Camille  » ne fassent jamais deux auteurs.
     */
    private static function guardActor(self $action, AdminActionType $type): void
    {
        $raw = $action->getAttribute('actor_name');
        $name = is_string($raw) ? trim($raw) : '';

        if ($type->isAutomatic()) {
            if ($action->actor_id !== null || $name !== self::SYSTEM_ACTOR) {
                throw new LogicException(
                    'L\'action automatique ['.$type->value.'] s\'écrit sans acteur : actor_id nul et actor_name ['.self::SYSTEM_ACTOR.'].',
                );
            }

            $action->actor_name = self::SYSTEM_ACTOR;

            return;
        }

        $folded = mb_strtolower($name);

        if ($folded === self::SYSTEM_ACTOR) {
            throw new LogicException(
                'La valeur réservée ['.self::SYSTEM_ACTOR.'] est refusée à l\'action ['.$type->value.'] : seuls les gestes automatiques s\'écrivent sans acteur.',
            );
        }

        if ($folded === self::CONSOLE_ACTOR) {
            if (! $type->allowsConsoleActor() || $action->actor_id !== null || $name !== self::CONSOLE_ACTOR) {
                throw new LogicException(
                    'La valeur réservée ['.self::CONSOLE_ACTOR.'] n\'est admise, avec un actor_id nul, que pour role.changed, site.closed et site.reopened ; refusée à l\'action ['.$type->value.'].',
                );
            }

            $action->actor_name = self::CONSOLE_ACTOR;

            return;
        }

        if ($action->actor_id === null) {
            throw new LogicException(
                'L\'action ['.$type->value.'] est le geste d\'une personne : elle exige un actor_id.',
            );
        }

        if ($name === '') {
            throw new LogicException(
                'L\'action ['.$type->value.'] exige le nom réel de son auteur : actor_name est vide.',
            );
        }

        $action->actor_name = $name;
    }

    /**
     * Invariant 6 : un motif rogné non vide quand l'action l'exige, et jamais
     * au-delà de la colonne. Un motif fait de blancs sur un geste facultatif
     * est ramené à NULL : il ne dit rien, et le journal ne doit pas le montrer
     * comme s'il disait quelque chose.
     */
    private static function guardReason(self $action, AdminActionType $type): void
    {
        $raw = $action->getAttribute('reason');
        $reason = is_string($raw) ? trim($raw) : null;

        if ($reason === '') {
            $reason = null;
        }

        if ($reason === null && $type->requiresReason()) {
            throw new LogicException('L\'action ['.$type->value.'] exige un motif non vide.');
        }

        if ($reason !== null && mb_strlen($reason) > self::REASON_MAX_LENGTH) {
            throw new LogicException(
                'Un motif du journal fait au plus '.self::REASON_MAX_LENGTH.' caractères ; celui de l\'action ['.$type->value.'] en compte '.mb_strlen($reason).'.',
            );
        }

        $action->reason = $reason;
    }

    /**
     * Invariant 7 : `role.changed` porte deux rôles non nuls et différents,
     * toute autre action n'en porte aucun — un retrait de film qui porterait
     * un rôle ferait croire à un changement de rôle qui n'a pas eu lieu.
     */
    private static function guardRoles(self $action, AdminActionType $type): void
    {
        $before = $action->role_before;
        $after = $action->role_after;

        if ($type === AdminActionType::RoleChanged) {
            if ($before === null || $after === null || $before === $after) {
                throw new LogicException(
                    'L\'action ['.$type->value.'] exige un role_before et un role_after non nuls et différents.',
                );
            }

            return;
        }

        if ($before !== null || $after !== null) {
            throw new LogicException(
                'L\'action ['.$type->value.'] ne change aucun rôle : role_before et role_after restent nuls.',
            );
        }
    }

    /**
     * Invariant 9 (D41 du 30/09) : `details` présent si et seulement si
     * l'action en déclare ({@see AdminActionType::hasDetails()}), et jamais
     * au-delà de {@see AdminActionDetails::MAX_BYTES} octets sérialisé — la
     * ligne est permanente, elle ne devient jamais un dépôt de données.
     */
    private static function guardDetails(self $action, AdminActionType $type): void
    {
        $details = $action->details;

        if ($type->hasDetails() !== ($details !== null)) {
            throw new LogicException(
                'L\'action ['.$type->value.'] '.($type->hasDetails()
                    ? 'exige un complément details.'
                    : 'ne porte aucun complément : details doit rester nul.'),
            );
        }

        if ($details !== null && strlen($details->toJson()) > AdminActionDetails::MAX_BYTES) {
            throw new LogicException(
                'Le complément details de l\'action ['.$type->value.'] dépasse '.AdminActionDetails::MAX_BYTES.' octets.',
            );
        }
    }
}
