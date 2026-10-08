<?php

namespace App\Support\Game;

use App\Enums\GamePlayerStatus;
use App\Enums\PlayerConnectionState;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le **siège présent** d'une partie — spec 60 § 1.2 (classe interne, nom
 * libre) : une ligne `game_player` non expulsée dont le `player` est
 * `connected` et non expulsé, retardataire admis compris.
 *
 * Critère de la pause automatique (`EndReveal` : aucun siège présent) et,
 * depuis D64 du 07/10, de la reprise d'une pause manuelle : l'hôte reprend ;
 * s'il n'est pas un siège présent, tout siège présent de la partie reprend.
 */
final class SeatPresence
{
    /** La partie a-t-elle au moins un siège présent ? */
    public static function any(Game $game): bool
    {
        return self::query($game)->exists();
    }

    /** Le siège `$playerId` est-il un siège présent de la partie ? */
    public static function isPresent(Game $game, ?int $playerId): bool
    {
        return $playerId !== null && self::query($game)->where('player_id', $playerId)->exists();
    }

    /**
     * @return Builder<GamePlayer>
     */
    private static function query(Game $game): Builder
    {
        return GamePlayer::query()
            ->where('game_id', $game->id)
            ->where('status', '<>', GamePlayerStatus::Kicked->value)
            ->whereIn('player_id', Player::query()
                ->where('connection_state', PlayerConnectionState::Connected->value)
                ->whereNull('kicked_at')
                ->select('id'));
    }
}
