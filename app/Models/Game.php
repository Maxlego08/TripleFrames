<?php

namespace App\Models;

use App\Casts\RoomSettingsCast;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Settings\RoomSettings;
use Carbon\CarbonImmutable;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property int $total_paused_ms
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
            'total_paused_ms' => 'integer',
            'ended_at' => 'datetime',
        ];
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
     * Les manches du tirage figé, `1..min(M + 3, |vivier|)`.
     *
     * @return HasMany<Round, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }
}
