<?php

namespace App\Models;

use App\Casts\RoomSettingsCast;
use App\Enums\InputDifficulty;
use App\Enums\RoomStatus;
use App\Settings\RoomSettings;
use App\Support\Room\RoomCode;
use Carbon\CarbonImmutable;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Le salon — le lieu, jamais la partie (§ 6.2).
 *
 * `status` décrit le cycle de vie du SALON seulement ; le statut d'une partie vit
 * sur `game.status`. Le salon n'a ni colonne de durée de manche (`D` est la somme
 * des paliers) ni locale : chaque joueur porte la sienne.
 *
 * **Les cinq colonnes de projection ne sont jamais écrites seules.** `capacity`,
 * `frames_per_round`, `rounds_count`, `input_difficulty` et `allow_late_join` sont
 * la projection typée de {@see RoomSettings} : un unique point d'écriture applique
 * le value object ET la projection dans la même transaction, et aucune des six
 * colonnes (version comprise) ne porte de défaut en base — les défauts vivent dans
 * `RoomSettingsBounds`, la version dans `RoomSettings::VERSION`. Un INSERT partiel
 * échoue bruyamment (1364 en MySQL strict) plutôt que de fabriquer un salon dont la
 * projection ment sur son value object. **`#[Fillable]` est donc vide** : un
 * `$room->update($request->validated())` fabriquerait exactement la seconde voie
 * d'écriture que cette phrase interdit — `frames_per_round: 5` sans `settings`
 * passerait sans que la borne croisée `D ≥ 5 s × N` de
 * {@see RoomSettings::fromInput()} soit jamais évaluée, et `capacity: 99` sans que
 * `PlatformLimits::roomSeats` (12) le soit (règle 2).
 *
 * **Aucun compteur de sièges dénormalisé** : la prise de siège verrouille la ligne
 * (`lockForUpdate`) et compte les `player` dont `connection_state <> 'left'`
 * ({@see Player::holdingSeat()}), la reprise de siège précédant toujours le comptage.
 *
 * @property int $id
 * @property string $room_code
 * @property string|null $room_code_active
 * @property RoomStatus $status
 * @property int|null $host_player_id
 * @property int $capacity
 * @property int $frames_per_round
 * @property int $rounds_count
 * @property InputDifficulty $input_difficulty
 * @property bool $allow_late_join
 * @property RoomSettings $settings
 * @property int $settings_version
 * @property CarbonImmutable|null $launched_at
 * @property CarbonImmutable $last_activity_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Player|null $hostPlayer
 * @property-read Collection<int, Player> $players
 * @property-read Collection<int, SeenFrame> $seenFrames
 * @property-read Collection<int, Game> $games
 * @property-read Collection<int, Round> $rounds
 */
#[Table('room')]
#[RouteKey('room_code')]
#[Fillable([])]
#[Hidden(['id', 'host_player_id', 'settings_version'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /**
     * Code court, alphabet non ambigu, **normalisé en majuscules en PHP avant toute
     * écriture et toute requête** : MySQL est insensible à la casse, SQLite en
     * BINARY ne l'est pas, et la portabilité vient de la donnée repliée, jamais
     * d'une collation déclarée (§ 1.4).
     *
     * Délègue à {@see RoomCode::normalize()} (spec 50 § 6.3), seule règle de
     * normalisation du code : majuscules, espaces et tirets retirés.
     */
    public static function normalizeCode(string $code): string
    {
        return RoomCode::normalize($code);
    }

    /**
     * Résout d'abord le créneau actif, puis le code parmi les salons archivés.
     *
     * `#[RouteKey]` ne suffit pas : sans la seconde lecture, un lien de salon
     * archivé rendrait un 404 au lieu du message explicite « salon expiré ». Le
     * créneau `room_code_active` est mis à NULL à l'archivage, ce qui recycle le
     * code sans index partiel — donc plusieurs salons archivés peuvent partager un
     * code, et c'est le plus récemment archivé qui répond.
     *
     * Le motif de route est tolérant (`RoomCode::ROUTE_PATTERN`) : la saisie est
     * d'abord normalisée, puis sa forme contrôlée ; un code mal formé répond 404
     * **sans aucune requête** (spec 50 § 6.3).
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        if ($field !== null && $field !== 'room_code' && $field !== 'room_code_active') {
            $resolved = parent::resolveRouteBinding($value, $field);

            return $resolved instanceof self ? $resolved : null;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $code = self::normalizeCode((string) $value);

        if (! RoomCode::isWellFormed($code)) {
            return null;
        }

        return static::query()
            ->where('room_code_active', $code)
            ->first()
            ?? static::query()
                ->where('room_code', $code)
                ->whereNotNull('archived_at')
                ->orderByDesc('archived_at')
                ->first();
    }

    /**
     * Hôte courant — **référence souple, sans contrainte de FK** (§ 1.5) : une
     * lecture qui ne trouve pas sa cible déclenche un transfert d'hôte, jamais une
     * erreur.
     *
     * @return BelongsTo<Player, $this>
     */
    public function hostPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'host_player_id');
    }

    /**
     * Tous les sièges du salon, y compris ceux déjà partis : une ligne `player`
     * n'est jamais supprimée avant l'archivage.
     *
     * @return HasMany<Player, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * Mémoire d'images du salon — axe salon seul, aucun axe joueur (§ 7.9).
     *
     * @return HasMany<SeenFrame, $this>
     */
    public function seenFrames(): HasMany
    {
        return $this->hasMany(SeenFrame::class);
    }

    /**
     * @return HasMany<Game, $this>
     */
    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    /**
     * @return HasMany<Round, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    /**
     * Miroir EXACT des défauts SQL de `room` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'status' => RoomStatus::Lobby->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'host_player_id' => 'integer',
            'capacity' => 'integer',
            'frames_per_round' => 'integer',
            'rounds_count' => 'integer',
            'input_difficulty' => InputDifficulty::class,
            'allow_late_join' => 'boolean',
            'settings' => RoomSettingsCast::class,
            'settings_version' => 'integer',
            'launched_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
