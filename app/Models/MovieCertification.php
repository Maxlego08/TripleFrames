<?php

namespace App\Models;

use App\Enums\CertificationCountry;
use Carbon\CarbonImmutable;
use Database\Factories\MovieCertificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La base unique du filtre de contenu : une seule certification retenue par pays.
 *
 * `certification` est la chaîne brute TMDB conservée telle quelle (`18`, `-16`,
 * `NC-17`, `X`, `R`) : la réinterpréter à l'import perdrait la preuve de ce que
 * TMDB a réellement répondu. `released_on` fait de « la plus récente fait foi »
 * une règle vérifiable, et la résolution se fait EN PHP, jamais en SQL : seule
 * la gagnante est stockée. `is_restrictive` est dénormalisée à l'écriture et
 * fait basculer `movie.content_flag` en `blocked` — jamais de dépublication
 * automatique, jamais de destruction de fichier (§ 3.8).
 *
 * @property int $id
 * @property int $movie_id
 * @property CertificationCountry $country
 * @property string $certification
 * @property CarbonImmutable|null $released_on
 * @property bool $is_restrictive
 * @property CarbonImmutable $read_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Movie $movie
 */
#[Table('movie_certification')]
#[Fillable(['country', 'certification', 'released_on', 'read_at'])]
class MovieCertification extends Model
{
    /** @use HasFactory<MovieCertificationFactory> */
    use HasFactory;

    /**
     * Miroir EXACT des défauts SQL de `movie_certification` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'is_restrictive' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'country' => CertificationCountry::class,
            'released_on' => 'date',
            'is_restrictive' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Le film dont cette ligne porte la certification retenue pour un pays.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }
}
