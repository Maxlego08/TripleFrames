<?php

namespace App\Models;

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingFailure;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use Carbon\CarbonImmutable;
use Database\Factories\FrameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une variante d'image de la banque d'un film.
 *
 * La « banque d'images » n'est pas une table : c'est la relation `movie → frame`.
 * Il n'existe AUCUNE colonne `frame_position` ni aucune colonne d'ordre ici —
 * l'ordre naît du tirage et vit dans `round_tier.tier_index`. Plusieurs variantes
 * par niveau sont la règle, donc aucune unicité `(movie_id, frame_level)`.
 *
 * Les deux chemins de fichier, les deux empreintes, le rectangle de recadrage, la
 * référence TMDB et l'identifiant lui-même sont `#[Hidden]` : un chemin ne quitte
 * JAMAIS le serveur, ni en URL, ni en payload, ni en en-tête, et un identifiant
 * séquentiel permettrait de regrouper les images d'un même film. Ce que le client
 * reçoit est un `round_tier.serve_token`, lié à une manche et non à une image.
 *
 * @property int $id
 * @property int $movie_id `#[Hidden]` — cacher `frame.id` pour empêcher le regroupement des images d'un même film serait vain en laissant partir la clé de regroupement elle-même.
 * @property FrameLevel $frame_level
 * @property ContentAvailability $availability
 * @property CarbonImmutable|null $availability_changed_at
 * @property CarbonImmutable|null $first_published_at
 * @property int|null $published_review_id
 * @property FrameProcessingState $processing_state
 * @property FrameProcessingFailure|null $processing_error Clé de traduction de l'échec du job d'image, jamais un message brut (spec 20 § 5.6).
 * @property FrameSourceKind $source_kind
 * @property string|null $tmdb_file_path
 * @property int|null $source_timecode_ms
 * @property string $source_hash
 * @property string|null $published_hash
 * @property int $crop_x
 * @property int $crop_y
 * @property int $crop_width
 * @property int $crop_height
 * @property string|null $game_path
 * @property string|null $master_path
 * @property int|null $game_bytes
 * @property int|null $game_width
 * @property int|null $game_height
 * @property CarbonImmutable|null $files_deleted_at
 * @property string|null $files_deleted_error
 * @property int|null $uploaded_by_id
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $review_grid_version
 * @property int|null $crop_seconds
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Movie $movie
 * @property-read User|null $uploadedBy
 * @property-read FrameReview|null $publishedReview
 * @property-read Collection<int, FrameReview> $reviews
 * @property-read Collection<int, SeenFrame> $seenFrames
 * @property-read Collection<int, RoundTier> $drawnTiers
 * @property-read Collection<int, RoundTier> $servedTiers
 * @property-read Collection<int, TakedownRequest> $takedownRequests
 */
#[Table('frame')]
#[Fillable([
    'frame_level',
    'source_kind',
    'tmdb_file_path',
    'source_timecode_ms',
    'crop_x',
    'crop_y',
    'crop_width',
    'crop_height',
    'crop_seconds',
])]
#[Hidden([
    'id',
    'movie_id',
    'tmdb_file_path',
    'source_hash',
    'published_hash',
    'crop_x',
    'crop_y',
    'crop_width',
    'crop_height',
    'game_path',
    'master_path',
])]
class Frame extends Model
{
    /** @use HasFactory<FrameFactory> */
    use HasFactory;

    /**
     * Prédicat de CATALOGUE, et rien de plus : la frame est publiée, le job
     * d'image différé a produit le dérivé, et le chemin de ce dérivé existe.
     *
     * C'est MOT POUR MOT le prédicat qui compte une variante jouable dans
     * `movie_projection` : un prédicat de comptage plus permissif que le prédicat
     * de service fabriquerait des films qui passent la garde de vivier et cassent
     * une manche.
     *
     * **Ce n'est PAS le prédicat de sécurité du moteur, et le qualifier ainsi est
     * la faille.** Il ne porte aucune condition temporelle et ne connaît aucun
     * demandeur : le lire comme suffisant signerait les `N` URL d'un coup à chaque
     * resynchronisation, or une resynchronisation est déclenchable par le client
     * à volonté, par un simple rechargement. La signature d'une URL d'image exige
     * les TROIS parties ensemble : celui-ci, la garde temporelle
     * `RoundTier::isOpenForServing()` — `i = 1` compris — et l'appartenance du
     * demandeur à la manche, portée par `round_tier.serve_token` et jamais par un
     * chemin de fichier.
     *
     * Son jumeau SQL est la portée {@see self::servable()}, seule écriture du
     * prédicat en requête : les deux doivent rendre le même verdict sur la même
     * ligne.
     */
    public function isServable(): bool
    {
        return $this->availability->isPlayable()
            && $this->processing_state === FrameProcessingState::Ready
            && $this->game_path !== null;
    }

    /**
     * **Le prédicat unique de variante jouable** (spec 10 § 3.2), en SQL, et
     * nulle part ailleurs : `availability = 'published'` **ET**
     * `processing_state = 'ready'` **ET** `game_path IS NOT NULL`. Les trois
     * ensemble : sans la troisième, un job Imagick à moitié échoué produit un
     * film qui passe la garde de vivier et casse une manche.
     *
     * Jumeau exact de {@see self::isServable()}, et le même compte partout : le
     * projecteur de `movie_projection` (`levels_mask`, `levels_count`), le
     * chargement des variantes du tirage (`GameDrawer`, spec 30 § 6.2) et les
     * candidates de substitution (`VariantChooser::substitute()`, § 8.1). Un
     * prédicat de comptage plus permissif que celui du tirage fabriquerait des
     * films qui passent la garde de vivier et cassent une manche (E71-3).
     *
     * Colonnes qualifiées par la table : la portée reste juste sous une jointure
     * (`seen_frame` au tirage et à la substitution).
     *
     * Ce n'est, comme son jumeau, qu'un prédicat de CATALOGUE : la présence du
     * fichier sur le disque `frames`, la garde temporelle et l'appartenance du
     * demandeur restent à la charge de leurs appelants.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function servable(Builder $query): void
    {
        $query->where($query->qualifyColumn('availability'), ContentAvailability::Published->value)
            ->where($query->qualifyColumn('processing_state'), FrameProcessingState::Ready->value)
            ->whereNotNull($query->qualifyColumn('game_path'));
    }

    /**
     * Le film dont cette image est une variante.
     *
     * `restrictOnDelete` : une frame n'est jamais détruite par la disparition d'un
     * film, et un film n'est jamais détruit.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Auteur de l'ajout — traçabilité image par image. `nullOnDelete`.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * La ligne de revue EXACTE qui autorise la publication.
     *
     * Référence SOUPLE : la colonne ne porte aucune contrainte de clé étrangère,
     * elle coupe le cycle `frame ↔ frame_review`. Elle rend la vérification de
     * preuve une jointure par clé au lieu d'une comparaison d'empreintes, et rend
     * un état `published` sans preuve structurellement visible — la sonde
     * quotidienne compte les `published` dont elle est nulle, et doit valoir 0.
     *
     * @return BelongsTo<FrameReview, $this>
     */
    public function publishedReview(): BelongsTo
    {
        return $this->belongsTo(FrameReview::class, 'published_review_id');
    }

    /**
     * Tous les passages de revue de cette image, en ajout seul.
     *
     * La garde de publication interroge CETTE relation, jamais les copies
     * `reviewed_at` / `review_grid_version` portées par la frame, qui ne sont
     * qu'une file de travail.
     *
     * @return HasMany<FrameReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(FrameReview::class);
    }

    /**
     * Traces d'affichage de cette image, par SALON — jamais par joueur.
     *
     * @return HasMany<SeenFrame, $this>
     */
    public function seenFrames(): HasMany
    {
        return $this->hasMany(SeenFrame::class);
    }

    /**
     * Paliers où cette image a été TIRÉE au lancement de la partie.
     *
     * @return HasMany<RoundTier, $this>
     */
    public function drawnTiers(): HasMany
    {
        return $this->hasMany(RoundTier::class, 'frame_id');
    }

    /**
     * Paliers où cette image a réellement été SERVIE — elle diffère de l'image
     * tirée quand une substitution de même niveau a rattrapé une indisponibilité.
     *
     * @return HasMany<RoundTier, $this>
     */
    public function servedTiers(): HasMany
    {
        return $this->hasMany(RoundTier::class, 'served_frame_id');
    }

    /**
     * Demandes de retrait visant cette image. `restrictOnDelete`.
     *
     * @return HasMany<TakedownRequest, $this>
     */
    public function takedownRequests(): HasMany
    {
        return $this->hasMany(TakedownRequest::class, 'target_frame_id');
    }

    /**
     * Miroir EXACT des défauts SQL de `frame` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'availability' => ContentAvailability::Draft->value,
        'processing_state' => FrameProcessingState::Pending->value,
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
            'frame_level' => FrameLevel::class,
            'availability' => ContentAvailability::class,
            'availability_changed_at' => 'datetime',
            'first_published_at' => 'datetime',
            'published_review_id' => 'integer',
            'processing_state' => FrameProcessingState::class,
            'processing_error' => FrameProcessingFailure::class,
            'source_kind' => FrameSourceKind::class,
            'source_timecode_ms' => 'integer',
            'crop_x' => 'integer',
            'crop_y' => 'integer',
            'crop_width' => 'integer',
            'crop_height' => 'integer',
            'game_bytes' => 'integer',
            'game_width' => 'integer',
            'game_height' => 'integer',
            'files_deleted_at' => 'datetime',
            'uploaded_by_id' => 'integer',
            'reviewed_at' => 'datetime',
            'review_grid_version' => 'integer',
            'crop_seconds' => 'integer',
        ];
    }
}
