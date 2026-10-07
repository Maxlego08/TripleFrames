<?php

namespace App\Models;

use App\Casts\RoomSettingsCast;
use App\Enums\GameMode;
use App\Enums\GamePauseKind;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Settings\RoomSettings;
use Carbon\CarbonImmutable;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
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
use LogicException;

/**
 * La partie — et la règle appliquée, figée au lancement (§ 7.2).
 *
 * Le journal opposable d'une partie tient dans `settings_snapshot` +
 * `settings_version` (les réglages), `scoring_version` / `validation_version`
 * (la règle), `tier_grace_ms` / `preload_lead_ms` (les deux constantes serveur,
 * sans lesquelles un rejeu ne peut redonner ni le palier retenu ni la fenêtre de
 * service) et `draw_seed` (le tirage). Aucune de ces colonnes n'est `#[Fillable]`.
 *
 * `tier_grace_ms` n'est PAS dans `settings_snapshot` et n'est JAMAIS un réglage
 * d'hôte : y loger la constante la rendrait réglable par la voie du JSON, et un
 * hôte postant `tierGraceMs: 120000` s'achèterait le palier 1 pour toute la
 * manche (§ 7.5). À ne jamais confondre avec `disconnectGraceSeconds`, réglage
 * d'hôte de 15 à 180 s (§ 1.3).
 *
 * **Colonnes figées** ({@see self::FROZEN_COLUMNS}, spec 50 § 12.7, contrat
 * C6, E10-41) : immuables après l'INSERT, que seul `OpenGame` écrit. La
 * garde `updating` ({@see self::booted()}) lève sur toute sauvegarde qui en
 * changerait une ; `settings_snapshot` y est comparé par VALEUR décodée
 * (`RoomSettingsCast`, `ComparesCastableAttributes`), jamais par chaîne :
 * MySQL relit une colonne `json` normalisée, et une sauvegarde qui suit une
 * simple lecture de l'instantané ne change rien.
 *
 * @property int $id
 * @property int|null $room_id NULL en solo ; `restrictOnDelete` vers `room`. `#[Hidden]` — `room.id` est cachée à la source (§ 6.2).
 * @property GameMode $mode Figé à la création, aucun chemin ne le mute (§ 7.10).
 * @property GameStatus $status Aucun état `pending` : la partie naît au lancement.
 * @property InputDifficulty $input_difficulty
 * @property int $rounds_count `M`, figé.
 * @property int $frames_per_round `N`, figé ; borne le nombre de lignes `round_tier`.
 * @property int $rounds_completed Le `k` de « interrompue à la manche k sur M ».
 * @property string $draw_seed `bin2hex(random_bytes(32))`, CSPRNG. `#[Hidden]`.
 * @property int $draw_pool_size Taille du vivier au lancement. `#[Hidden]`.
 * @property int $tier_grace_ms Constante serveur de frontière de palier (§ 7.5).
 * @property int $preload_lead_ms Avance maximale de signature d'une image.
 * @property int $settings_version
 * @property RoomSettings $settings_snapshot Jamais interrogée en SQL. `#[Hidden]`.
 * @property int $scoring_version
 * @property int $validation_version
 * @property CarbonImmutable $started_at Lancement, origine du journal.
 * @property CarbonImmutable|null $paused_at
 * @property GamePauseKind|null $pause_kind Nature de la pause en cours, NULL hors pause (D64 du 07/10).
 * @property CarbonImmutable|null $pause_requested_at Demande de pause manuelle en attente de la fin de révélation (D64).
 * @property int $total_paused_ms
 * @property int $manual_paused_ms Budget consommé par les pauses manuelles, décomptes de reprise compris (D64).
 * @property CarbonImmutable|null $ended_at Colonne pilote unique de la fenêtre de 12 mois.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Room|null $room
 * @property-read Collection<int, GamePlayer> $gamePlayers
 * @property-read Collection<int, Round> $rounds
 */
#[Table('game')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
#[Hidden(['room_id', 'draw_seed', 'draw_pool_size', 'settings_snapshot'])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    /**
     * Les colonnes figées de la partie — spec 50 § 12.7 et contrat C6 § 2, à
     * la lettre et dans leur ordre (E10-41) : la règle appliquée (réglages,
     * versions, constantes serveur), le tirage (graine, vivier), l'origine du
     * journal (`started_at`) et le salon. Écrites une fois, à l'INSERT de
     * `OpenGame` ; aucune ne change ensuite, pas même après le podium ni au
     * « Rejouer » de l'hôte : un rejeu doit redonner exactement le même
     * palier, les mêmes points et le même tirage.
     *
     * Hors de la liste, et donc écrites en cours de partie par leurs seuls
     * écrivains : `status`, `paused_at`, `pause_kind`, `pause_requested_at`,
     * `total_paused_ms`, `manual_paused_ms`, `rounds_completed` et `ended_at`
     * (60, 80).
     *
     * @var list<string>
     */
    public const array FROZEN_COLUMNS = [
        'mode',
        'input_difficulty',
        'rounds_count',
        'frames_per_round',
        'draw_seed',
        'draw_pool_size',
        'tier_grace_ms',
        'preload_lead_ms',
        'settings_version',
        'settings_snapshot',
        'scoring_version',
        'validation_version',
        'started_at',
        'room_id',
    ];

    /**
     * Miroir EXACT des défauts SQL de `game` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'status' => GameStatus::Running->value,
        'rounds_completed' => 0,
        'total_paused_ms' => 0,
        'manual_paused_ms' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'room_id' => 'integer',
            'mode' => GameMode::class,
            'status' => GameStatus::class,
            'input_difficulty' => InputDifficulty::class,
            'rounds_count' => 'integer',
            'frames_per_round' => 'integer',
            'rounds_completed' => 'integer',
            'draw_pool_size' => 'integer',
            'tier_grace_ms' => 'integer',
            'preload_lead_ms' => 'integer',
            'settings_version' => 'integer',
            'settings_snapshot' => RoomSettingsCast::class,
            'scoring_version' => 'integer',
            'validation_version' => 'integer',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'pause_kind' => GamePauseKind::class,
            'pause_requested_at' => 'datetime',
            'total_paused_ms' => 'integer',
            'manual_paused_ms' => 'integer',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * La garde des colonnes figées (spec 50 § 12.7, contrat C6 § 2) : toute
     * mise à jour Eloquent qui en changerait une lève, avant la moindre
     * requête. Un défaut de l'appelant, jamais un cas d'exécution : aucun
     * chemin légitime ne réécrit la règle d'une partie lancée.
     *
     * La garde écoute le modèle ; une requête de mise à jour directe
     * (`Game::query()->update()`) ou une sauvegarde sans événements ne passe
     * pas par elle — aucun code de `app/` n'écrit `game` ainsi.
     */
    protected static function booted(): void
    {
        static::updating(static function (Game $game): void {
            $changed = $game->changedFrozenColumns();

            if ($changed !== []) {
                throw new LogicException(sprintf(
                    'Game #%d : colonne(s) figée(s) modifiée(s) après le lancement : %s (spec 50 § 12.7, contrat C6).',
                    $game->getKey(),
                    implode(', ', $changed),
                ));
            }
        });
    }

    /**
     * Les colonnes figées que la prochaine sauvegarde changerait, dans l'ordre
     * de {@see self::FROZEN_COLUMNS}. L'instantané des réglages est comparé
     * par valeur (`RoomSettingsCast::compare()`).
     *
     * @return list<string>
     */
    public function changedFrozenColumns(): array
    {
        $dirty = $this->getDirty();

        return array_values(array_filter(
            self::FROZEN_COLUMNS,
            static fn (string $column): bool => array_key_exists($column, $dirty),
        ));
    }

    /**
     * Partie **en cours** — prédicat propriété de la spec 60 (§ 17.1, contrat
     * C17, D32 du 23/09) : `ended_at IS NULL AND status IN ('running',
     * 'paused')`, **solo compris**. Servi par `game_ended_idx (ended_at)`.
     *
     * C'est la « partie courante » d'un siège que `seat.active` met en mémoire
     * (§ 10.2) et que le drainage compte (`GamesInProgress`, L60-10). Un salon
     * au lobby n'en a aucune. L'invariant `ended_at IS NOT NULL` ⇔ statut
     * terminal (`FinalizeGame`, seul écrivain) rend les deux clauses
     * redondantes sur une base saine ; elles sont gardées toutes deux, à la
     * lettre du prédicat, pour qu'une ligne incohérente ne compte jamais comme
     * une partie en cours.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function inProgress(Builder $query): void
    {
        $query->whereNull('ended_at')
            ->whereIn('status', [GameStatus::Running->value, GameStatus::Paused->value]);
    }

    /**
     * Le salon d'origine — NULL en solo, une partie solo n'ayant pas de salon.
     *
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Les participations, avec leurs agrégats figés à `ended_at`.
     *
     * Aucun `belongsToMany` vers `Player` : le pivot porte des données propres
     * (pseudo et avatar gelés, issue, agrégats) et reste la source canonique.
     *
     * @return HasMany<GamePlayer, $this>
     */
    public function gamePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    /**
     * Les manches du tirage figé, `sequence_index` = `1..K`, où
     * `K = min(M + PlatformLimits::drawSubstituteMargin(), W)` et `W` le nombre
     * d'œuvres du vivier (spec 30 § 1.1) ; réserve comprise.
     *
     * @return HasMany<Round, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }
}
