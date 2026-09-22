<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SeenFrameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La mémoire d'images d'un salon (§ 7.9) — **axe salon seul**.
 *
 * **Aucune colonne de joueur, aucun `player_id`, aucun `game_id`, aucun
 * `round_id`** : l'axe joueur est retiré (jusqu'à 360 écritures par partie pour un
 * simple départage), tous les joueurs voient la même image, et le départage à
 * égalité se fait par la graine de la partie. Pas de compteur d'occurrences non
 * plus : « la moins récemment vue » n'a besoin que de `last_seen_at`.
 *
 * `room_id` est **NOT NULL**, donc **une partie solo n'écrit jamais ici** et ne
 * pollue aucune mémoire de salon (barrière 3 du § 7.10).
 *
 * **La ligne est upsertée à l'ouverture du palier**, jamais au lancement, et sur
 * `round_tier.served_frame_id` : une manche annulée, une fin anticipée ou une
 * substitution ne marquent jamais une image qui n'a pas été affichée. L'absence de
 * ligne est le cas normal — le tirage n'est jamais bloqué.
 *
 * **Une des deux seules tables du schéma sans aucune colonne conventionnelle**
 * (avec `frame_review`) : d'où `#[WithoutTimestamps]`. `last_seen_at` est écrite
 * explicitement à chaque écriture, jamais par `useCurrent()` — et c'est aussi ce
 * qui rend l'upsert sûr, `Builder::upsert()` n'ajoutant de colonnes d'horodatage
 * que si le modèle en déclare.
 *
 * @property int $id
 * @property int $room_id
 * @property int $frame_id
 * @property CarbonImmutable $last_seen_at
 * @property-read Room $room
 * @property-read Frame $frame
 */
#[Table('seen_frame')]
#[WithoutTimestamps]
#[Fillable(['room_id', 'frame_id', 'last_seen_at'])]
#[Hidden(['id', 'room_id', 'frame_id'])]
class SeenFrame extends Model
{
    /** @use HasFactory<SeenFrameFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<Frame, $this>
     */
    public function frame(): BelongsTo
    {
        return $this->belongsTo(Frame::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'room_id' => 'integer',
            'frame_id' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
