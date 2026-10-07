<?php

namespace App\Models;

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ImportSource;
use App\Enums\MovieDifficulty;
use App\Models\Pivots\MovieThemePivot;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * L'identité d'une œuvre, et aucune valeur dérivée (§ 3.1).
 *
 * Table **sanctuarisée** : toute migration la touchant exige un instantané
 * bloquant préalable, ce qui est la raison du refus des ENUM natifs et de la
 * sortie de toutes les valeurs dérivées vers `movie_projection` (§ 3.2).
 *
 * Elle ne porte aucun agrégat de jeu — ce serait un fait de partie soumis à la
 * purge logé dans une table que la purge ne doit jamais atteindre —, aucune
 * forme normalisée de titre (`answer_key` en est l'unique propriétaire), aucun
 * chemin d'image, aucune couverture de niveaux ni de titres
 * (`movie_projection`), aucun auteur de changement de disponibilité
 * (`admin_action`, arbitrage A2), et aucun champ TMDB que ne lit aucune règle.
 *
 * Le retrait juridique ne supprime jamais la ligne : il pose
 * `availability = 'withdrawn'` avec motif et horodatage, et le `tmdb_id` unique
 * devient à lui seul le blocage de réimport, sans table de bannissement (A10).
 *
 * Convention de nom imposée par le schéma, valable dans ce seul fichier — le
 * seul qui relie les deux : `Collection` non aliasée désigne le **modèle**
 * `App\Models\Collection`, la saga TMDB ; la collection Eloquent est importée
 * sous `EloquentCollection`. Partout ailleurs, c'est l'inverse.
 *
 * @property int $id
 * @property int|null $tmdb_id
 * @property ImportSource $import_source
 * @property int|null $import_run_id
 * @property bool $is_import_exception
 * @property bool $exception_for_language
 * @property bool $exception_for_vote_count
 * @property bool $exception_for_release_year
 * @property string $title_original
 * @property string|null $title_original_latin
 * @property string $original_language
 * @property int|null $release_year
 * @property int $vote_count
 * @property bool $adult
 * @property int|null $collection_id
 * @property int|null $group_id
 * @property ContentAvailability $availability
 * @property CarbonImmutable|null $availability_changed_at
 * @property string|null $availability_reason
 * @property CarbonImmutable|null $first_published_at
 * @property ContentFlag $content_flag
 * @property int|null $content_verified_by_id
 * @property CarbonImmutable|null $content_verified_at
 * @property MovieDifficulty|null $movie_difficulty
 * @property MovieDifficulty|null $movie_difficulty_derived
 * @property MovieDifficulty|null $movie_difficulty_override
 * @property int|null $curated_by_id
 * @property int $curation_active_seconds
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ImportRun|null $importRun
 * @property-read Collection|null $collection
 * @property-read MovieGroup|null $group
 * @property-read User|null $contentVerifiedBy
 * @property-read User|null $curatedBy
 * @property-read MovieProjection|null $projection
 * @property-read EloquentCollection<int, MovieTitle> $titles
 * @property-read EloquentCollection<int, Alias> $aliases
 * @property-read EloquentCollection<int, AnswerKey> $answerKeys
 * @property-read EloquentCollection<int, MovieCertification> $certifications
 * @property-read EloquentCollection<int, MovieTmdbTag> $tmdbTags
 * @property-read EloquentCollection<int, MovieTheme> $movieThemes
 * @property-read EloquentCollection<int, Theme> $themes
 * @property-read EloquentCollection<int, Frame> $frames
 * @property-read EloquentCollection<int, NearMiss> $nearMisses
 * @property-read EloquentCollection<int, Round> $rounds
 * @property-read EloquentCollection<int, TakedownRequest> $takedownRequests
 * @property-read EloquentCollection<int, ContentReport> $contentReports
 */
#[Table('movie')]
#[Fillable(['collection_id', 'group_id', 'movie_difficulty_override'])]
class Movie extends Model
{
    /** @use HasFactory<MovieFactory> */
    use HasFactory;

    /**
     * Les deux conditions de catalogue du prédicat de vivier, ensemble et
     * jamais séparées (§ 12, arbitrage A3), servies par
     * `movie_pool_idx (availability, content_flag, id)`. Les deux autres
     * conditions vivent ailleurs et ne sont pas de ce modèle :
     * `movie_projection.levels_count >= N`, et la non-répétition par salon,
     * qui est une garde du tirage (spec 30).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inPool(Builder $query): void
    {
        $query->where('availability', ContentAvailability::Published)
            ->where('content_flag', ContentFlag::Clear);
    }

    /**
     * La réserve non publiée des leurres (spec 70 § 10.3, rang R6, D53 du
     * 02/10) : films `draft` ou `unpublished` — écartés compris —, **jamais**
     * `suspended` ni `withdrawn`, et **toujours** `clear` : le filtre de
     * contenu n'est contournable par aucune voie. Servie par le même index
     * `movie_pool_idx (availability, content_flag, id)`.
     *
     * Lue par `PoolQuery` seul, pour un périmètre `PoolScope::asDecoyReserve()` :
     * un film de la réserve n'est **jamais** une cible, seulement un titre
     * proposé en dernier recours.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inDecoyReserve(Builder $query): void
    {
        $query->whereIn('availability', [ContentAvailability::Draft, ContentAvailability::Unpublished])
            ->where('content_flag', ContentFlag::Clear);
    }

    /**
     * Miroir EXACT des défauts SQL de `movie` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'import_source' => ImportSource::Discover->value,
        'is_import_exception' => false,
        'exception_for_language' => false,
        'exception_for_vote_count' => false,
        'exception_for_release_year' => false,
        'vote_count' => 0,
        'adult' => false,
        'availability' => ContentAvailability::Draft->value,
        'content_flag' => ContentFlag::UnratedPending->value,
        'curation_active_seconds' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tmdb_id' => 'integer',
            'import_source' => ImportSource::class,
            'import_run_id' => 'integer',
            'is_import_exception' => 'boolean',
            'exception_for_language' => 'boolean',
            'exception_for_vote_count' => 'boolean',
            'exception_for_release_year' => 'boolean',
            'release_year' => 'integer',
            'vote_count' => 'integer',
            'adult' => 'boolean',
            'collection_id' => 'integer',
            'group_id' => 'integer',
            'availability' => ContentAvailability::class,
            'availability_changed_at' => 'datetime',
            'first_published_at' => 'datetime',
            'content_flag' => ContentFlag::class,
            'content_verified_by_id' => 'integer',
            'content_verified_at' => 'datetime',
            'movie_difficulty' => MovieDifficulty::class,
            'movie_difficulty_derived' => MovieDifficulty::class,
            'movie_difficulty_override' => MovieDifficulty::class,
            'curated_by_id' => 'integer',
            'curation_active_seconds' => 'integer',
        ];
    }

    /**
     * Le balayage ou le collage qui a fait entrer ce film : son motif
     * d'exception en devient auditable. `nullOnDelete`.
     *
     * @return BelongsTo<ImportRun, $this>
     */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }

    /**
     * Saga TMDB — « même saga ». Cardinalité 0..1 côté TMDB, donc une colonne
     * et pas un pivot. À ne jamais confondre avec `group()`.
     *
     * @return BelongsTo<Collection, $this>
     */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id');
    }

    /**
     * Groupe manuel d'homonymes et de remakes — « même œuvre ». Jamais
     * alimenté par TMDB, jamais montré à un joueur, survit au réimport.
     *
     * @return BelongsTo<MovieGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MovieGroup::class, 'group_id');
    }

    /**
     * L'auteur de la coche « contenu vérifié », `nullOnDelete` : le geste reste
     * tracé par sa ligne `admin_action` même si le compte disparaît.
     *
     * @return BelongsTo<User, $this>
     */
    public function contentVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'content_verified_by_id');
    }

    /**
     * L'auteur de la passe de curation, `nullOnDelete`.
     *
     * @return BelongsTo<User, $this>
     */
    public function curatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'curated_by_id');
    }

    /**
     * Exactement une ligne, créée dans la **même transaction** que le film avec
     * toutes ses colonnes calculées (§ 3.2). Aucun observateur ne la crée.
     *
     * @return HasOne<MovieProjection, $this>
     */
    public function projection(): HasOne
    {
        return $this->hasOne(MovieProjection::class, 'movie_id');
    }

    /**
     * Les titres affichables, une ligne par locale de catalogue. L'absence
     * d'une ligne EST l'information : aucun titre n'est jamais recopié d'une
     * langue vers une autre.
     *
     * @return HasMany<MovieTitle, $this>
     */
    public function titles(): HasMany
    {
        return $this->hasMany(MovieTitle::class, 'movie_id');
    }

    /**
     * Les variantes acceptées en réponse, à usage exclusif de validation :
     * jamais affichées, et la validation elle-même passe par `answer_key`.
     *
     * @return HasMany<Alias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(Alias::class, 'movie_id');
    }

    /**
     * La projection consultée à chaque tentative, unique propriétaire des
     * formes normalisées et des clés dérivées, préfixes et sous-titres.
     *
     * @return HasMany<AnswerKey, $this>
     */
    public function answerKeys(): HasMany
    {
        return $this->hasMany(AnswerKey::class, 'movie_id');
    }

    /**
     * Base unique du filtre de contenu (§ 3.8).
     *
     * @return HasMany<MovieCertification, $this>
     */
    public function certifications(): HasMany
    {
        return $this->hasMany(MovieCertification::class, 'movie_id');
    }

    /**
     * Les étiquettes TMDB brutes, seul support des règles de genre et de studio.
     *
     * @return HasMany<MovieTmdbTag, $this>
     */
    public function tmdbTags(): HasMany
    {
        return $this->hasMany(MovieTmdbTag::class, 'movie_id');
    }

    /**
     * Pivot canonique de l'appartenance thématique : il porte `is_auto`,
     * `manual_state`, `is_active`, `assigned_by_id` et `assigned_at`. **Toute
     * écriture passe par ici**, jamais par `themes()`.
     *
     * @return HasMany<MovieTheme, $this>
     */
    public function movieThemes(): HasMany
    {
        return $this->hasMany(MovieTheme::class, 'movie_id');
    }

    /**
     * Lecture confortable des thèmes — seul `belongsToMany` du schéma, et
     * jamais un chemin d'écriture.
     *
     * `->using()` n'est pas un ornement : sans lui le pivot hydraté est un
     * `Pivot` nu, sans `casts()`, et `manual_state` rend la chaîne `'removed'`
     * au lieu du cas d'enum que {@see MovieTheme} documente. Les deux moitiés de
     * la relation déclarent le MÊME pivot et les MÊMES colonnes
     * ({@see Theme::movies()}), sans quoi le même filtre écrit d'un côté puis
     * copié de l'autre rendrait deux résultats opposés.
     *
     * @return BelongsToMany<Theme, $this, MovieThemePivot>
     */
    public function themes(): BelongsToMany
    {
        return $this->belongsToMany(Theme::class, 'movie_theme', 'movie_id', 'theme_id')
            ->using(MovieThemePivot::class)
            ->withPivot(['is_auto', 'manual_state', 'is_active', 'assigned_by_id', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * La banque d'images du film, tous niveaux confondus. L'ordre n'est pas une
     * propriété du film : il naît du tirage et vit dans `round_tier.tier_index`.
     *
     * @return HasMany<Frame, $this>
     */
    public function frames(): HasMany
    {
        return $this->hasMany(Frame::class, 'movie_id');
    }

    /**
     * Les quasi-réponses agrégées et k-anonymes, jamais rattachées à un joueur.
     *
     * @return HasMany<NearMiss, $this>
     */
    public function nearMisses(): HasMany
    {
        return $this->hasMany(NearMiss::class, 'movie_id');
    }

    /**
     * Les manches jouées sur ce film. FK `restrictOnDelete` : c'est ainsi que
     * le périmètre interdit de la purge devient une propriété du schéma.
     *
     * @return HasMany<Round, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class, 'movie_id');
    }

    /**
     * Les demandes de retrait visant ce film, par `target_movie_id`.
     *
     * @return HasMany<TakedownRequest, $this>
     */
    public function takedownRequests(): HasMany
    {
        return $this->hasMany(TakedownRequest::class, 'target_movie_id');
    }

    /**
     * Signalements de joueurs visant ce film ou l'une de ses images (D63 du
     * 07/10). `restrictOnDelete`.
     *
     * @return HasMany<ContentReport, $this>
     */
    public function contentReports(): HasMany
    {
        return $this->hasMany(ContentReport::class);
    }
}
