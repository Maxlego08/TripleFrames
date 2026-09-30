<?php

namespace App\Support\Game;

use App\Enums\GameMode;
use App\Enums\RoomStatus;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;

/**
 * La partie d'un siège — spec 60 § 10.2, § 12.2 et § 17 (contrat C17).
 *
 * Deux lectures, deux usages, jamais confondus :
 *
 * - {@see self::of()} : la partie **en cours** du siège au sens du prédicat
 *   de 60 § 17.1 (`Game::inProgress()`), ou NULL. C'est celle que
 *   `seat.active` met en mémoire sur la requête (§ 10.2) et que lisent le
 *   limiteur `answer` et la soumission de 70 : une partie close n'est jamais
 *   la partie courante d'un siège ;
 * - {@see self::forState()} : la partie que décrit un paquet de
 *   resynchronisation (§ 12.2) — la partie en cours, à défaut la dernière
 *   partie du salon tant que `room.status = playing` (podium compris), en
 *   solo la dernière partie close du siège ; NULL au lobby.
 *
 * **Siège de salon** : la partie du SALON (`game.room_id`), servie par
 * `game_room_started_idx`. **Siège solo** : la partie solo dont une ligne
 * `game_player` porte ce siège (10 § 7.10 : une partie solo n'a pas de
 * salon). Au plus une partie en cours par salon et par siège solo (C6, 60
 * § 16.2) ; l'ordre `started_at` puis `id` décroissants ne fait que rendre
 * la lecture déterministe sur une base qui violerait cet invariant.
 */
final class CurrentGame
{
    /**
     * La partie en cours du siège (C17), ou NULL.
     */
    public static function of(Player $seat): ?Game
    {
        return self::latest(self::games($seat)->inProgress());
    }

    /**
     * La partie que décrit le paquet de resynchronisation du siège (§ 12.2),
     * ou NULL (lobby, solo pas encore lancé).
     */
    public static function forState(Player $seat): ?Game
    {
        $current = self::of($seat);

        if ($current !== null) {
            return $current;
        }

        if ($seat->room_id === null) {
            return self::latest(self::games($seat));
        }

        $playing = Room::query()
            ->whereKey($seat->room_id)
            ->where('status', RoomStatus::Playing->value)
            ->exists();

        return $playing ? self::latest(self::games($seat)) : null;
    }

    /**
     * Les parties du siège : celles de son salon, ou, en solo, celles où il
     * a une participation.
     *
     * @return Builder<Game>
     */
    private static function games(Player $seat): Builder
    {
        if ($seat->room_id !== null) {
            return Game::query()->where('room_id', $seat->room_id);
        }

        return Game::query()
            ->whereNull('room_id')
            ->where('mode', GameMode::Solo->value)
            ->whereHas('gamePlayers', fn (Builder $participation) => $participation->where('player_id', $seat->id));
    }

    /**
     * @param  Builder<Game>  $games
     */
    private static function latest(Builder $games): ?Game
    {
        return $games->orderByDesc('started_at')->orderByDesc('id')->first();
    }
}
