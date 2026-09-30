<?php

namespace App\Models;

use App\Enums\FrameSourceKind;
use App\Enums\ReviewDecision;
use App\Enums\UserRole;
use App\Support\Eloquent\AppendOnlyBuilder;
use Carbon\CarbonImmutable;
use Database\Factories\FrameReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * La preuve opposable d'un passage de revue d'image, en AJOUT SEUL.
 *
 * Une frame accumule une ligne par passage de revue, jamais une mise à jour :
 * `reviewed_at` est la SEULE colonne temporelle de la table, et l'absence
 * d'`updated_at` est le signal structurel de cette immuabilité — d'où
 * `#[WithoutTimestamps]`, `frame_review` étant l'une des deux seules tables du
 * schéma à ne porter aucune colonne conventionnelle.
 *
 * L'immuabilité est outillée par convention applicative, jamais par un
 * déclencheur SQL — un déclencheur s'écrirait différemment en MySQL et en SQLite,
 * donc ne serait jamais exercé par la suite de tests, et l'immuabilité serait
 * fictive. Trois pièces : aucune `updated_at` ni `deleted_at`, une Policy refusant
 * `update` et `delete`, et le garde sur l'évènement `saving` ci-dessous.
 *
 * **Condition de publication d'une frame, et elle seule** : il existe une ligne
 * `decision = passed` dont `reviewed_hash = frame.published_hash` et dont
 * `grid_version` est la version courante de la grille d'exclusion, et
 * `frame.published_review_id` désigne cette ligne. Un re-recadrage change
 * `published_hash` et périme donc mécaniquement la revue, sans qu'aucune ligne
 * ne soit modifiée.
 *
 * @property int $id
 * @property int $frame_id
 * @property int|null $reviewer_id
 * @property string $reviewer_name
 * @property UserRole $reviewer_role
 * @property int $grid_version
 * @property ReviewDecision $decision
 * @property string $reviewed_hash
 * @property FrameSourceKind $declared_source_kind
 * @property string|null $declared_source_reference
 * @property array<string, string|bool|null> $answers
 * @property CarbonImmutable $reviewed_at
 * @property-read Frame $frame
 * @property-read User|null $reviewer
 * @property-read Frame|null $publishedFrame
 */
#[Table('frame_review')]
#[WithoutTimestamps]
#[Fillable(['decision', 'answers'])]
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class FrameReview extends Model
{
    /** @use HasFactory<FrameReviewFactory> */
    use HasFactory;

    /**
     * L'image examinée. `restrictOnDelete` : une frame n'est jamais supprimée,
     * donc la preuve n'est jamais orpheline.
     *
     * @return BelongsTo<Frame, $this>
     */
    public function frame(): BelongsTo
    {
        return $this->belongsTo(Frame::class);
    }

    /**
     * Le curateur, `nullOnDelete`.
     *
     * La preuve ne repose JAMAIS sur cette relation : `reviewer_name` et
     * `reviewer_role` sont des instantanés pris à l'instant de la revue,
     * explicitement exclus de l'anonymisation, faute de quoi la suppression d'un
     * compte réduirait une preuve nominative à « relecteur n° 42 ».
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * La frame dont CETTE ligne autorise la publication — référence souple, sans
     * contrainte de clé étrangère, qui coupe le cycle `frame ↔ frame_review`.
     *
     * @return HasOne<Frame, $this>
     */
    public function publishedFrame(): HasOne
    {
        return $this->hasOne(Frame::class, 'published_review_id');
    }

    /**
     * Garde d'immuabilité : une ligne de revue ne se met jamais à jour.
     *
     * Le refus est levé à l'écriture et non seulement en policy, pour que la
     * garantie tienne aussi loin de toute requête HTTP — une passe de curation,
     * une commande d'exploitation ou un test.
     *
     * **Il ne couvre que l'écriture d'une INSTANCE** : aucun événement de modèle
     * n'est émis par une mise à jour de masse, et c'est {@see AppendOnlyBuilder},
     * posé par `#[UseEloquentBuilder]`, qui ferme le
     * `FrameReview::where('frame_id', $id)->update(['grid_version' => …])` d'une
     * passe de re-revue — lequel ferait passer pour revues, sous une grille
     * qu'aucun curateur n'a exercée, des images que personne n'a regardées. Ni
     * l'une ni l'autre ne couvre `DB::table('frame_review')`.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $review): void {
            if ($review->exists) {
                throw new LogicException(
                    'Une ligne frame_review est une preuve en ajout seul : elle ne se met jamais à jour.'
                );
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frame_id' => 'integer',
            'reviewer_id' => 'integer',
            'reviewer_role' => UserRole::class,
            'grid_version' => 'integer',
            'decision' => ReviewDecision::class,
            'declared_source_kind' => FrameSourceKind::class,
            'answers' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
