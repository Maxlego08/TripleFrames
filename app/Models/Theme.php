<?php

namespace App\Models;

use App\Enums\Locale;
use App\Enums\ThemeKind;
use App\Models\Pivots\MovieThemePivot;
use Carbon\CarbonImmutable;
use Database\Factories\ThemeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un thème combinable par l'hôte (§ 3.7).
 *
 * `key` est l'identifiant technique stable et lisible (`genre.animation`,
 * `decade.1990`, `studio.ghibli`, `saga.star-wars`, `language.international`,
 * `difficulty.hard`) : c'est lui que les seeders et les tests nomment, jamais
 * l'identifiant auto-incrémenté.
 *
 * `theme_kind` détermine l'interprétation de `rule_value` — un `tmdb_tag_id`
 * pour `genre` et `studio`, l'année de début pour `decade`, un `collection.id`
 * pour `saga`, un code de langue pour `language`, une valeur de
 * `MovieDifficulty` pour `difficulty`. Colonne typée et non du JSON, « parce
 * que publier une saga ne doit pas exiger un déploiement ».
 *
 * **Aucun compteur de films ici** : le vivier dépend du couple (thèmes, `N`) et
 * un compteur par thème ne s'additionne pas en union (§ 3.7).
 *
 * Aucune colonne cachée : `theme.id` est précisément ce que l'hôte renvoie dans
 * `RoomSettings::$themeIds`, et `rule_value` ne porte aucun secret de jeu.
 *
 * @property int $id
 * @property string $key
 * @property ThemeKind $theme_kind
 * @property string|null $rule_value
 * @property bool $rule_negated
 * @property bool $is_published
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ThemeLabel> $labels
 * @property-read Collection<int, MovieTheme> $movieThemes
 * @property-read Collection<int, Movie> $movies
 */
#[Table('theme')]
#[Fillable(['key', 'theme_kind', 'rule_value', 'rule_negated', 'sort_order'])]
class Theme extends Model
{
    /** @use HasFactory<ThemeFactory> */
    use HasFactory;

    /**
     * Les libellés d'interface du thème, un par locale activée.
     *
     * @return HasMany<ThemeLabel, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(ThemeLabel::class);
    }

    /**
     * Les lignes d'appartenance, **source canonique de toute écriture**
     * (`is_auto`, `manual_state`, `is_active`, `assigned_by_id`, `assigned_at`).
     *
     * @return HasMany<MovieTheme, $this>
     */
    public function movieThemes(): HasMany
    {
        return $this->hasMany(MovieTheme::class);
    }

    /**
     * Confort de lecture seule. Toute écriture passe par {@see self::movieThemes()} :
     * le pivot porte cinq colonnes métier qu'un `attach()` ne saurait pas remplir.
     *
     * Symétrique EXACT de {@see Movie::themes()} — même pivot typé, mêmes colonnes.
     * Sans `withPivot()`, `aliasedPivotColumns()` ne produit que `pivot_theme_id` et
     * `pivot_movie_id` : un `$theme->movies->filter(fn ($m) => $m->pivot->is_active)`
     * rendrait une collection VIDE, sans erreur ni ligne de journal, là où le même
     * filtre écrit depuis `Movie::themes()` fonctionne.
     *
     * @return BelongsToMany<Movie, $this, MovieThemePivot>
     */
    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'movie_theme', 'theme_id', 'movie_id')
            ->using(MovieThemePivot::class)
            ->withPivot(['is_auto', 'manual_state', 'is_active', 'assigned_by_id', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * Les locales activées pour lesquelles il manque un libellé.
     *
     * Un thème n'est publiable que s'il porte un libellé dans **chaque** locale
     * activée (§ 3.7 et spec `05`), sans quoi le joueur verrait son identifiant
     * technique. La décision de publication elle-même appartient au back-office ;
     * cette méthode n'en écrit qu'une fois le prédicat.
     *
     * @return list<Locale>
     */
    public function missingLabelLocales(): array
    {
        $present = [];

        foreach ($this->labels as $label) {
            $present[$label->locale->value] = true;
        }

        return array_values(array_filter(
            Locale::cases(),
            fn (Locale $locale): bool => ! isset($present[$locale->value]),
        ));
    }

    public function hasEveryLocaleLabel(): bool
    {
        return $this->missingLabelLocales() === [];
    }

    /**
     * Miroir EXACT des défauts SQL de `theme` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'rule_negated' => false,
        'is_published' => false,
        'sort_order' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theme_kind' => ThemeKind::class,
            'rule_negated' => 'boolean',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
