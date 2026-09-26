<?php

namespace App\Models;

use App\Avatars\AvatarRef;
use App\Enums\AvatarKind;
use App\Enums\GamePlayerStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GamePlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La participation d'un siège à une partie, et ses agrégats figés (§ 7.3).
 *
 * **Famille d'horodatage : `timestamps()` de précision 0, et surtout PAS
 * `#[DateFormat('Y-m-d H:i:s.v')]`.** La migration écrit `$table->timestamps();`
 * sans précision, contrairement aux six autres tables de faits de partie : poser
 * un format sub-seconde ici ferait écrire des millisecondes dans des colonnes qui
 * n'en ont pas.
 *
 * Le score vivant n'est pas ici : il se lit par `Scoreboard` sous
 * `ScoreScope::Own` ou `Publishable` ; les cinq colonnes nullables sont écrites
 * par `FinalizeGame` seul (spec 80 § 7.4 et § 10.4), UNIQUEMENT à
 * `game.ended_at`, ce qui fait du gel un événement vérifiable plutôt qu'un état
 * qu'on oublie de déclencher.
 *
 * Aucune colonne `user_id` : le propriétaire se lit par `player.user_id`, source
 * unique, et l'anonymisation le détache en une seule écriture.
 *
 * @property int $id
 * @property int $game_id
 * @property int $player_id `restrictOnDelete` : un siège ne disparaît jamais sous un podium.
 * @property string|null $display_nickname Pseudo gelé pour la durée de la partie ; effacé à l'archivage du salon.
 * @property AvatarKind|null $display_avatar_kind NULL = repli initiales.
 * @property string|null $display_avatar_preset Jamais un chemin de copie provider, pour qu'un masquage postérieur fasse redescendre la chaîne de repli.
 * @property int|null $first_round_number Manche d'entrée d'un retardataire.
 * @property GamePlayerStatus $status Issue figée ; la présence vive vit sur `player.connection_state`.
 * @property int|null $rounds_played Manches réellement jouées par CE joueur, jamais `M`.
 * @property int|null $correct_answers
 * @property int|null $final_score
 * @property int|null $total_answer_time_ms
 * @property int|null $final_rank `unsignedSmallInteger` (E10-04) : la table n'est pas bornée, un rang peut dépasser 255. NULL en solo, où l'historique affiche « — », ou si `rounds_played = 0`.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Game $game
 * @property-read Player $player
 */
#[Table('game_player')]
#[Fillable([])]
#[Hidden(['player_id'])]
class GamePlayer extends Model
{
    /** @use HasFactory<GamePlayerFactory> */
    use HasFactory;

    /**
     * Miroir EXACT des défauts SQL de `game_player` (§ 1.7).
     *
     * Un défaut de base ne remplit que la LIGNE : l'instance qui vient de
     * l'écrire garde l'attribut absent, donc `null`, et l'annotation `@property`
     * — sans `|null`, parce que la colonne est `NOT NULL` — mentirait au runtime
     * là où PHPStan la croit sûre. Même patron que {@see User::$attributes}.
     *
     * @var array<string, string|int|bool>
     */
    protected $attributes = [
        'status' => GamePlayerStatus::Playing->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'player_id' => 'integer',
            'display_avatar_kind' => AvatarKind::class,
            'first_round_number' => 'integer',
            'status' => GamePlayerStatus::class,
            'rounds_played' => 'integer',
            'correct_answers' => 'integer',
            'final_score' => 'integer',
            'total_answer_time_ms' => 'integer',
            'final_rank' => 'integer',
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

    /**
     * Résolution d'avatar du siège GELÉ, au-dessus des colonnes `display_*`.
     *
     * Même accesseur que {@see Player::avatarRef()} et {@see User::avatarRef()}
     * (§ 1.3), au-dessus des colonnes gelées de la partie : le podium et
     * l'historique affichent le siège tel qu'il était, jamais tel qu'il est devenu.
     * Aucune branche `provider` : `display_avatar_preset` n'est jamais un chemin de
     * copie provider (§ 7.3).
     */
    public function avatarRef(): AvatarRef
    {
        $initials = AvatarRef::initialsFrom($this->display_nickname);

        if ($this->display_avatar_kind === AvatarKind::Preset && $this->display_avatar_preset !== null) {
            return AvatarRef::preset($this->display_avatar_preset, $initials);
        }

        return AvatarRef::initials($initials);
    }
}
