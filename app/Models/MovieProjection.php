<?php

namespace App\Models;

use App\Enums\FrameLevel;
use App\Enums\Locale;
use Carbon\CarbonImmutable;
use Database\Factories\MovieProjectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tout ce qui est dérivé d'un film, et rien d'autre (§ 3.2). Une ligne par
 * film, clé primaire `movie_id` — seule exception nommée du § 1.1 — créée
 * **dans la même transaction que la ligne `movie`**, par l'action de création
 * de film, avec toutes ses colonnes à leur valeur calculée. Aucun observateur
 * ne crée jamais une ligne de projection : un `updateOrCreate` d'observateur
 * insérerait `title_mask_version = 0`, valeur qui n'apparie jamais la version
 * courante, et le film serait jouable mais invisible au tirage des leurres.
 *
 * Écriture **synchrone**, jamais un job de fond : toute transition faisant
 * entrer ou sortir une variante du comptant recalcule `levels_*` dans la même
 * transaction, toute création, suppression ou changement de locale d'une ligne
 * `movie_title` recalcule `title_locale_mask` dans la même transaction.
 *
 * Elle porte des colonnes conventionnelles **malgré** `recomputed_at` (§ 1.7) :
 * sans elles, `$timestamps = true` par défaut écrirait une colonne inexistante
 * à chaque reprojection.
 *
 * @property int $movie_id
 * @property int $levels_mask
 * @property int $levels_count
 * @property int $level_1_variants
 * @property int $level_2_variants
 * @property int $level_3_variants
 * @property int $level_4_variants
 * @property int $level_5_variants
 * @property int $variants_total
 * @property int $title_locale_mask
 * @property int $title_mask_version
 * @property CarbonImmutable $recomputed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Movie $movie
 */
#[Table('movie_projection')]
#[WithoutIncrementing]
#[Fillable([
    'movie_id',
    'levels_mask',
    'levels_count',
    'level_1_variants',
    'level_2_variants',
    'level_3_variants',
    'level_4_variants',
    'level_5_variants',
    'variants_total',
    'title_locale_mask',
    'title_mask_version',
    'recomputed_at',
])]
class MovieProjection extends Model
{
    /** @use HasFactory<MovieProjectionFactory> */
    use HasFactory;

    /**
     * Clé primaire : `movie_id`, en 1:1 stricte avec `movie` (§ 1.1 et § 3.2).
     *
     * @var string
     */
    protected $primaryKey = 'movie_id';

    /** @var string */
    protected $keyType = 'int';

    /**
     * Miroir EXACT des défauts SQL de `movie_projection` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'levels_mask' => 0,
        'levels_count' => 0,
        'level_1_variants' => 0,
        'level_2_variants' => 0,
        'level_3_variants' => 0,
        'level_4_variants' => 0,
        'level_5_variants' => 0,
        'variants_total' => 0,
        'title_locale_mask' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movie_id' => 'integer',
            'levels_mask' => 'integer',
            'levels_count' => 'integer',
            'level_1_variants' => 'integer',
            'level_2_variants' => 'integer',
            'level_3_variants' => 'integer',
            'level_4_variants' => 'integer',
            'level_5_variants' => 'integer',
            'variants_total' => 'integer',
            'title_locale_mask' => 'integer',
            'title_mask_version' => 'integer',
            'recomputed_at' => 'datetime',
        ];
    }

    /**
     * Masque des niveaux exigés pour publier un film — 1, 3 et 5 (§ 3.2, § 4.3).
     * Calculé depuis `FrameLevel::bit()` pour qu'aucun littéral 21 ne traîne.
     */
    public static function publishableLevelsMask(): int
    {
        return FrameLevel::Level1->bit()
            | FrameLevel::Level3->bit()
            | FrameLevel::Level5->bit();
    }

    /**
     * Moitié « banque d'images » de la condition de publication : arithmétique
     * entière portable, là où `BIT_COUNT` existe en MySQL et pas en SQLite.
     * L'autre moitié, `content_flag = 'clear'`, vit sur `movie`.
     */
    public function coversPublishableLevels(): bool
    {
        $mask = self::publishableLevelsMask();

        return ($this->levels_mask & $mask) === $mask;
    }

    /**
     * Éligibilité à un `N` donné — jamais stockée, toujours dérivée (§ 3.2).
     */
    public function supportsFramesPerRound(int $framesPerRound): bool
    {
        return $this->levels_count >= $framesPerRound;
    }

    /**
     * Compte de variantes jouables d'un niveau. Une valeur à 1 est le signal
     * back-office « variante unique », jamais une contrainte de jeu.
     */
    public function variantsForLevel(FrameLevel $level): int
    {
        return match ($level) {
            FrameLevel::Level1 => $this->level_1_variants,
            FrameLevel::Level2 => $this->level_2_variants,
            FrameLevel::Level3 => $this->level_3_variants,
            FrameLevel::Level4 => $this->level_4_variants,
            FrameLevel::Level5 => $this->level_5_variants,
        };
    }

    /**
     * Le profil de titre est-il lisible à la version de bits courante ? Un
     * masque périmé ne doit jamais être lu comme valide : tant que
     * `catalog:reproject` n'a pas tourné, le QCM bascule sur `title_original`.
     */
    public function hasCurrentTitleMask(): bool
    {
        return $this->title_mask_version === Locale::MASK_VERSION;
    }

    /**
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'movie_id');
    }
}
