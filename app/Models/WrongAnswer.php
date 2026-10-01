<?php

namespace App\Models;

use App\Enums\GuessSource;
use Carbon\CarbonImmutable;
use Database\Factories\WrongAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une réponse fausse comptée (§ 7.6 bis, D46 du 01/10) : texte faux dont
 * l'instruction de refus a touché la ligne `round_player`, ou clic QCM faux.
 *
 * **Un journal de lecture, rien d'autre** : le plafond et la fin anticipée
 * lisent `round_player.wrong_attempts` et `input_state`, jamais cette table ;
 * elle n'est ni diffusée ni lue par le moteur ou le score. Seule l'inspection
 * du back-office (spec 20 § 12.2, administrateur seul) la lit, et jamais pour
 * une manche non révélée d'une partie en cours (règle 3).
 *
 * Écrite dans la transaction du refus, **si et seulement si** l'instruction a
 * touché la ligne : une condition d'état et de temps, jamais de proximité
 * (invariant L4, spec 70 § 7.4).
 *
 * Famille d'horodatage de {@see Guess} : `created_at` seul, en `timestamp(3)`.
 *
 * @property int $id
 * @property int $round_id
 * @property int $player_id `restrictOnDelete`, comme `guess`.
 * @property GuessSource $source
 * @property string $submitted_text Saisie brute ou chaîne cliquée. `#[Hidden]`.
 * @property string $submitted_normalized `#[Hidden]`.
 * @property int|null $attempt_number Rang de la tentative texte ; NULL pour un clic.
 * @property CarbonImmutable $received_at Instant SERVEUR de réception.
 * @property int $answered_at_ms Millisecondes depuis `round.started_at`.
 * @property CarbonImmutable|null $created_at
 * @property-read Round $round
 * @property-read Player $player
 */
#[Table('wrong_answer')]
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([])]
#[Hidden([
    'player_id',
    'submitted_text',
    'submitted_normalized',
])]
class WrongAnswer extends Model
{
    /** @use HasFactory<WrongAnswerFactory> */
    use HasFactory;

    /**
     * La table n'a pas de colonne `updated_at` : la ligne ne bouge plus.
     */
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'round_id' => 'integer',
            'player_id' => 'integer',
            'source' => GuessSource::class,
            'attempt_number' => 'integer',
            'received_at' => 'datetime',
            'answered_at_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Round, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
