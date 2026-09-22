<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MovieGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groupe manuel d'homonymes et de remakes (§ 3.3) — « même œuvre », jamais
 * « même saga », qui est le rôle de `collection`. `label` est un libellé
 * interne de back-office (« Old Boy 2003 / 2013 »), jamais affiché à un joueur
 * et donc jamais localisé : c'est ce qui le distingue d'un thème de saga.
 * Jamais alimentée automatiquement — le back-office signale des candidats, un
 * curateur tranche. Jamais alimentée par TMDB, elle survit au réimport.
 *
 * @property int $id
 * @property string $label
 * @property string|null $note
 * @property int|null $created_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $createdBy
 * @property-read Collection<int, Movie> $movies
 */
#[Table('movie_group')]
#[Fillable(['label', 'note'])]
class MovieGroup extends Model
{
    /** @use HasFactory<MovieGroupFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_by_id' => 'integer',
        ];
    }

    /**
     * Auteur du regroupement, `nullOnDelete` : la traçabilité ne dépend pas de
     * la survie d'un compte.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return HasMany<Movie, $this>
     */
    public function movies(): HasMany
    {
        return $this->hasMany(Movie::class, 'group_id');
    }
}
