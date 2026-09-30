<?php

namespace App\Models;

use App\Enums\TmdbTagKind;
use Carbon\CarbonImmutable;
use Database\Factories\MovieTmdbTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'étiquette TMDB brute, seul support local des règles de genre et de studio.
 *
 * `tmdb_tag_id` est un identifiant TMDB brut, JAMAIS un libellé : le nom d'un
 * genre est du contenu traduit et vit en `theme_label`. Ces lignes sont des
 * métadonnées TMDB pures, écrasées sans exception par une resynchronisation —
 * l'exception d'appartenance vit dans `movie_theme.manual_state`.
 *
 * Table à `created_at` seul : `const UPDATED_AT = null;` est obligatoire, sans
 * quoi Eloquent écrit une colonne inexistante (1054 en MySQL).
 *
 * @property int $id
 * @property int $movie_id
 * @property TmdbTagKind $tag_kind
 * @property int $tmdb_tag_id
 * @property CarbonImmutable|null $created_at
 * @property-read Movie $movie
 */
#[Table('movie_tmdb_tag')]
#[Fillable(['tag_kind', 'tmdb_tag_id'])]
class MovieTmdbTag extends Model
{
    /** @use HasFactory<MovieTmdbTagFactory> */
    use HasFactory;

    /**
     * Une étiquette ne se modifie pas : elle apparaît ou disparaît.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tag_kind' => TmdbTagKind::class,
            'tmdb_tag_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Le film que cette étiquette TMDB qualifie.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }
}
