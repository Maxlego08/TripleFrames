<?php

namespace App\Models;

use App\Casts\RoomSettingsCast;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use Carbon\CarbonImmutable;
use Database\Factories\SavedConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une configuration de salon sauvegardée par un compte (§ 6.3).
 *
 * Propriété **obligatoire et absolue**, et **strictement privée, y compris d'un
 * admin** : `SavedConfigPolicy` ne teste QUE la propriété, sans aucune clause de rôle,
 * et le projet s'interdit tout `Gate::before` global — c'est la raison nommée de cette
 * interdiction. Aucune colonne de visibilité, aucun jeton de partage, aucune table de
 * partage.
 *
 * `name_normalized` porte l'unicité, normalisée EN PHP : sans elle, « Ma config » et
 * « ma config » fusionnent en MySQL et coexistent en SQLite, donc le même test passe au
 * vert d'un côté et au rouge de l'autre.
 *
 * `default_slot` est un CRÉNEAU d'unicité, `null` ou `'d'` : c'est la seule écriture
 * portable de « au plus une configuration par défaut par utilisateur », MySQL 8 n'ayant
 * aucun index partiel et un booléen plus une contrainte étant impossible.
 *
 * Plafond de {@see PlatformLimits::savedConfigsPerUser()} par compte : non exprimable
 * en contrainte portable, appliqué par l'action de création, **jamais un littéral**.
 *
 * **Nommément hors du périmètre de la purge**, et la table ne porte volontairement
 * aucune colonne pilote datée ni aucun index daté, de sorte qu'un job de purge n'ait
 * rien à balayer ici. Elle est en revanche supprimée à l'anonymisation, qui n'est pas
 * une purge de rétention.
 *
 * Le JSON n'est **jamais réécrit en base** au chargement : {@see RoomSettingsCast} ne
 * normalise rien, et {@see RoomSettings::normalize()} est un appel explicite de
 * l'action de chargement dont le résultat n'est jamais réinjecté ici.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $name_normalized
 * @property RoomSettings $settings
 * @property int $settings_version
 * @property string|null $default_slot
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read bool $is_default
 * @property-read User $user
 */
#[Table('saved_config')]
// `name_normalized` est DÉRIVÉE (normalisée en PHP dans la même transaction que sa
// source) et `default_slot` est un créneau d'unicité réécrit par l'action qui déplace
// le défaut : ni l'une ni l'autre ne vient d'un formulaire.
#[Fillable(['name', 'settings'])]
// Deux mécaniques de stockage, remplacées à la sérialisation par `is_default`.
#[Hidden(['name_normalized', 'default_slot'])]
#[Appends(['is_default'])]
class SavedConfig extends Model
{
    /** @use HasFactory<SavedConfigFactory> */
    use HasFactory;

    /**
     * L'unique valeur non nulle de `default_slot`. `null` = non, `'d'` = oui.
     *
     * Une seconde valeur ouvrirait deux configurations par défaut pour un même compte :
     * c'est le créneau, et non la valeur, qui porte la garantie.
     */
    public const string DEFAULT_SLOT = 'd';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => RoomSettingsCast::class,
            'settings_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Le créneau d'unicité, rendu au client en booléen par `#[Appends(['is_default'])]`.
     *
     * > Même piège Eloquent que `User::avatar()`, et c'est pourquoi cette méthode
     * > renvoie un `Attribute` et non un `bool` : `hasAttributeMutator('is_default')`
     * > cherche la méthode `isDefault` et exige le type de retour exact
     * > `Illuminate\Database\Eloquent\Casts\Attribute`. Un `public function
     * > isDefault(): bool` ferait retomber `mutateAttributeForArray()` sur un
     * > `getIsDefaultAttribute()` inexistant, et toute page listant les configurations
     * > d'un compte renverrait 500. Le prédicat se lit donc `$config->is_default`.
     *
     * @return Attribute<bool, never>
     */
    protected function isDefault(): Attribute
    {
        return Attribute::get(fn (): bool => $this->default_slot === self::DEFAULT_SLOT);
    }
}
