<?php

namespace App\Models;

use App\Enums\AnswerKeyKind;
use Carbon\CarbonImmutable;
use Database\Factories\AnswerKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La projection consultée à chaque tentative, unique propriétaire des formes
 * normalisées et des préfixes.
 *
 * `normalized` est calculée EN PHP et réduite à `[a-z0-9 ]` : sur cet alphabet,
 * `utf8mb4_unicode_ci` et BINARY rendent le même verdict. `is_ambiguous` est
 * dénormalisée et posée par le projecteur synchrone (§ 3.5), jamais par une
 * agrégation à la tentative ; elle n'est jamais rétroactive, `guess` porte
 * l'instantané. Une ligne qui existe encore garde son identifiant :
 * reconstruction par différence, jamais par purge et réinsertion.
 *
 * @property int $id
 * @property int $movie_id
 * @property AnswerKeyKind $key_kind
 * @property string|null $source_locale locale de CATALOGUE string(12), purement traçante, jamais un WHERE de validation
 * @property string $normalized
 * @property bool $is_ambiguous
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Movie $movie
 * @property-read Collection<int, Guess> $guesses
 * @property-read int|null $guesses_count
 */
#[Table('answer_key')]
#[Fillable(['key_kind', 'source_locale', 'normalized'])]
class AnswerKey extends Model
{
    /** @use HasFactory<AnswerKeyFactory> */
    use HasFactory;

    /**
     * Miroir EXACT des défauts SQL de `answer_key` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'is_ambiguous' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_kind' => AnswerKeyKind::class,
            'is_ambiguous' => 'boolean',
        ];
    }

    /**
     * Le film que cette chaîne acceptée désigne.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Les bonnes réponses ayant retenu cette clé, conservées douze mois.
     *
     * @return HasMany<Guess, $this>
     */
    public function guesses(): HasMany
    {
        return $this->hasMany(Guess::class);
    }
}
