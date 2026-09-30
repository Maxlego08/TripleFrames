<?php

namespace App\Models;

use App\Enums\ContentOrigin;
use Carbon\CarbonImmutable;
use Database\Factories\AliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variante acceptée en réponse, à usage EXCLUSIF de validation.
 *
 * Jamais affichée : un alias FR n'est jamais proposé comme titre EN à la
 * révélation. La validation ne passe jamais par cette table, elle passe par
 * `answer_key` ; aucune unicité ne porte sur le texte (§ 3.4). Un alias ne
 * produit jamais de clé de nature `prefix`, et la translittération latine
 * n'est pas un alias : elle vit sur `movie.title_original_latin`.
 *
 * @property int $id
 * @property int $movie_id
 * @property string $locale locale de CATALOGUE string(12), jamais castée par App\Enums\Locale
 * @property string $alias
 * @property ContentOrigin $origin
 * @property int|null $created_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Movie $movie
 * @property-read User|null $createdBy
 */
#[Table('alias')]
#[Fillable(['locale', 'alias'])]
class Alias extends Model
{
    /** @use HasFactory<AliasFactory> */
    use HasFactory;

    /**
     * Miroir EXACT des défauts SQL de `alias` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'origin' => ContentOrigin::Curator->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => ContentOrigin::class,
        ];
    }

    /**
     * Le film dont cet alias est une variante acceptée.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * L'auteur de l'alias curé, nul pour une ligne importée.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
