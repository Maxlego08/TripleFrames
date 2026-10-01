<?php

namespace App\Models;

use App\Support\Perf\GameTraceWriter;
use Carbon\CarbonImmutable;
use Database\Factories\GameTraceFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de la chronologie technique d'une partie — spec 10 § 7.11,
 * spec 100 § 10.11 (D47 du 01/10).
 *
 * Transitions du moteur, diffusions de frontière et leur retard, jobs de
 * frontière, soumissions et resynchronisations, avec durée et requêtes SQL.
 * **Jamais lue par le moteur**, jamais diffusée ; seule la fiche d'une partie
 * du back-office (administrateur seul) la lit. Jamais un titre, une saisie ni
 * une chaîne du QCM. Écrite par {@see GameTraceWriter}
 * seul, après commit ; conservée 14 jours (périmètre `game_trace`).
 *
 * @property int $id
 * @property int $game_id `#[Hidden]`.
 * @property int|null $sequence_index
 * @property int|null $tier_index
 * @property string $event
 * @property int|null $player_id `#[Hidden]`.
 * @property CarbonImmutable|null $theoretical_at
 * @property CarbonImmutable $recorded_at
 * @property int|null $delay_ms Réel − théorique, signé.
 * @property int|null $duration_ms
 * @property int|null $query_count
 * @property array<string, scalar|null>|null $details
 * @property-read Game $game
 * @property-read Player|null $player
 */
#[Table('game_trace')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
#[Hidden(['game_id', 'player_id'])]
class GameTrace extends Model
{
    /** @use HasFactory<GameTraceFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'sequence_index' => 'integer',
            'tier_index' => 'integer',
            'player_id' => 'integer',
            'theoretical_at' => 'datetime',
            'recorded_at' => 'datetime',
            'delay_ms' => 'integer',
            'duration_ms' => 'integer',
            'query_count' => 'integer',
            'details' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
