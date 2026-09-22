<?php

namespace App\Models;

use App\Enums\ThemeMembershipState;
use Carbon\CarbonImmutable;
use Database\Factories\MovieThemeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'appartenance d'un film à un thème, la règle et l'exception séparées (§ 3.7).
 *
 * Modèle à part entière et non un `Pivot` : la ligne porte un auteur et un
 * horodatage, et modifier une primaire composite reconstruirait la table en
 * SQLite. C'est **la** source canonique d'écriture ;
 * {@see Movie::themes()} et {@see Theme::movies()} sont de la lecture de confort.
 *
 * Trois colonnes, trois autorités distinctes :
 * - `is_auto` — résultat de la règle automatique, **réécrit librement** à chaque
 *   recalcul et à chaque resynchronisation ;
 * - `manual_state` — `added` / `removed`, écrit par un **curateur seul**, jamais
 *   par une passe automatique : c'est elle, et elle seule, qui fait survivre
 *   l'appartenance manuelle au réimport (§ 9.3) ;
 * - `is_active` — appartenance **effective** dénormalisée, `manual_state` primant
 *   sur `is_auto`, servie par l'index couvrant `movie_theme_pool_idx`
 *   `(theme_id, is_active, movie_id)` pour que le vivier n'évalue pas un `OR` à
 *   deux branches à chaque frappe du lobby.
 *
 * Une ligne existe dès que la règle matche **ou** qu'une exception existe ; une
 * exception `removed` sur une ligne non automatique est conservée précisément
 * pour qu'un recalcul ne la ressuscite pas.
 *
 * L'appartenance au thème « difficulté » est **projetée ici** depuis
 * `movie.movie_difficulty` : c'est pourquoi `movie_difficulty` ne porte aucun
 * index — ce pivot **est** sa forme requêtable.
 *
 * Seule `manual_state` est mass-assignable : `is_auto` est le résultat d'une
 * passe automatique, `is_active` une valeur dérivée, `assigned_by_id` et
 * `assigned_at` sont posés par l'action depuis l'utilisateur authentifié et
 * l'instant serveur.
 *
 * @property int $id
 * @property int $movie_id
 * @property int $theme_id
 * @property bool $is_auto
 * @property ThemeMembershipState|null $manual_state
 * @property bool $is_active
 * @property int|null $assigned_by_id
 * @property CarbonImmutable|null $assigned_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Movie $movie
 * @property-read Theme $theme
 * @property-read User|null $assignedBy
 */
#[Table('movie_theme')]
#[Fillable(['manual_state'])]
class MovieTheme extends Model
{
    /** @use HasFactory<MovieThemeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * @return BelongsTo<Theme, $this>
     */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /**
     * Auteur de l'exception manuelle, nul pour une ligne purement automatique.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    /**
     * L'appartenance effective, écrite une seule fois dans le dépôt :
     * `manual_state` prime sur `is_auto`, `null` laissant la règle décider.
     *
     * C'est la valeur que toute écriture doit poser dans `is_active` — la
     * colonne est dénormalisée, donc aucune passe, aucun recalcul et aucune
     * resynchronisation n'a le droit de la dériver autrement.
     */
    public static function resolveIsActive(bool $isAuto, ?ThemeMembershipState $manualState): bool
    {
        return match ($manualState) {
            ThemeMembershipState::Added => true,
            ThemeMembershipState::Removed => false,
            null => $isAuto,
        };
    }

    /**
     * La valeur qu'`is_active` devrait porter pour l'état courant de la ligne.
     */
    public function computeIsActive(): bool
    {
        return self::resolveIsActive($this->is_auto, $this->manual_state);
    }

    /**
     * Miroir EXACT des défauts SQL de `movie_theme` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'is_auto' => false,
        'is_active' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_auto' => 'boolean',
            'manual_state' => ThemeMembershipState::class,
            'is_active' => 'boolean',
            'assigned_at' => 'datetime',
        ];
    }
}
