<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Saga TMDB (§ 3.3) — « même saga », jamais « même œuvre », qui est le rôle de
 * `movie_group`. Cardinalité TMDB 0..1, donc une colonne `movie.collection_id`
 * et aucun pivot. Jamais affichée à un joueur : ce sont le thème de saga et ses
 * `theme_label` qui le sont, donc `name` n'est pas localisé. Deux consommations
 * seulement : `rule_value` d'un thème de kind `saga`, et pondération de
 * `movie.movie_difficulty_derived`.
 *
 * Ce fichier déclarant la classe `Collection`, la collection Eloquent y est
 * forcément aliasée `EloquentCollection` — seule exception à la convention
 * inverse qui vaut dans tous les autres modèles.
 *
 * @property int $id
 * @property int|null $tmdb_id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read EloquentCollection<int, Movie> $movies
 */
#[Table('collection')]
#[Fillable(['tmdb_id', 'name'])]
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tmdb_id' => 'integer',
        ];
    }

    /**
     * @return HasMany<Movie, $this>
     */
    public function movies(): HasMany
    {
        return $this->hasMany(Movie::class, 'collection_id');
    }
}
