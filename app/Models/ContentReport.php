<?php

namespace App\Models;

use App\Enums\ContentReportReason;
use App\Enums\ContentReportResolution;
use App\Enums\ContentReportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ContentReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un signalement de contenu par un joueur (D63 du 07/10, spec 10 § 8.1 bis) :
 * un film, ou une image de ce film, signalé depuis la révélation d'une
 * manche ou le récapitulatif du podium.
 *
 * Distinct de {@see Report} (pseudo et avatar, seuil de masquage automatique)
 * et de {@see TakedownRequest} (voie juridique d'un ayant droit). **Aucun
 * effet automatique** : un signalement ne change jamais l'état d'un film ou
 * d'une image ; seul un geste humain curateur+ le fait, depuis la file du
 * back-office, et clôt alors les signalements de la cible.
 *
 * `target_key` (`f:<frame_id>` ou `m:<movie_id>`, {@see self::targetKeyFor()})
 * porte le dédoublonnage : UNIQUE `(reporter_user_id, target_key)` et
 * `(reporter_player_id, target_key)`. Le signaleur est le compte connecté,
 * sinon le siège le plus récent du `player_token` — jamais une IP, jamais un
 * visiteur.
 *
 * `comment` est une saisie libre du joueur : donnée personnelle possible,
 * purgée avec la ligne 12 mois après `created_at` (périmètre
 * `content_report`). `status`, `resolution`, `resolved_at` et
 * `resolved_by_id` s'écrivent ensemble, par la seule clôture du back-office.
 *
 * @property int $id
 * @property int $movie_id
 * @property int|null $frame_id
 * @property string $target_key
 * @property ContentReportReason $reason
 * @property string|null $comment
 * @property int|null $reporter_user_id
 * @property int|null $reporter_player_id
 * @property ContentReportStatus $status
 * @property ContentReportResolution|null $resolution
 * @property CarbonImmutable|null $resolved_at
 * @property int|null $resolved_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Movie $movie
 * @property-read Frame|null $frame
 * @property-read User|null $reporterUser
 * @property-read Player|null $reporterPlayer
 * @property-read User|null $resolvedBy
 */
#[Table('content_report')]
#[Fillable(['reason', 'comment'])]
#[Hidden(['id', 'movie_id', 'frame_id', 'target_key', 'reporter_user_id', 'reporter_player_id', 'resolved_by_id'])]
class ContentReport extends Model
{
    /** @use HasFactory<ContentReportFactory> */
    use HasFactory;

    /** Longueur maximale du texte libre facultatif. */
    public const int COMMENT_MAX_LENGTH = 1000;

    /** Préfixe de `target_key` pour une image. */
    public const string FRAME_KEY_PREFIX = 'f:';

    /** Préfixe de `target_key` pour le film entier. */
    public const string MOVIE_KEY_PREFIX = 'm:';

    /**
     * Miroir EXACT des défauts SQL de `content_report` (§ 1.7).
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'status' => ContentReportStatus::Open->value,
    ];

    /** La clé de dédoublonnage d'une cible : l'image si elle est désignée, sinon le film. */
    public static function targetKeyFor(Movie $movie, ?Frame $frame): string
    {
        return $frame === null
            ? self::MOVIE_KEY_PREFIX.$movie->id
            : self::FRAME_KEY_PREFIX.$frame->id;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movie_id' => 'integer',
            'frame_id' => 'integer',
            'reason' => ContentReportReason::class,
            'reporter_user_id' => 'integer',
            'reporter_player_id' => 'integer',
            'status' => ContentReportStatus::class,
            'resolution' => ContentReportResolution::class,
            'resolved_at' => 'datetime',
            'resolved_by_id' => 'integer',
        ];
    }

    /**
     * Le film signalé, ou celui de l'image signalée. `restrictOnDelete`.
     *
     * @return BelongsTo<Movie, $this>
     */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * L'image signalée, NULL pour un signalement du film entier.
     *
     * @return BelongsTo<Frame, $this>
     */
    public function frame(): BelongsTo
    {
        return $this->belongsTo(Frame::class);
    }

    /**
     * Le compte signaleur, s'il était connecté. `nullOnDelete`.
     *
     * @return BelongsTo<User, $this>
     */
    public function reporterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /**
     * Le siège signaleur d'un invité. `nullOnDelete`.
     *
     * @return BelongsTo<Player, $this>
     */
    public function reporterPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'reporter_player_id');
    }

    /**
     * Le curateur qui a clos le signalement. `nullOnDelete`.
     *
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}
