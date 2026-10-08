<?php

namespace App\Listeners\Account;

use App\Events\Game\GameFinalized;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Support\Account\PlayHistory;
use App\Support\Account\PlayHistoryCache;

/**
 * Oublie le cache court de l'historique des comptes d'une partie qui vient
 * d'être gelée — spec 40 § 13.4, spec 80 § 10.7 et § 13 (L40-13, D66 du
 * 07/10).
 *
 * Écoute `GameFinalized`, livré après le commit du gel : les compteurs relus
 * ensuite par {@see PlayHistory} portent les agrégats que le gel vient
 * d'écrire. Oublie la clé de chaque compte titulaire d'un siège de la partie
 * (`player.user_id`, jamais `game_player`, qui n'a pas de colonne de compte).
 *
 * **Idempotent et tolérant** : oublier une clé absente ne fait rien, et une
 * partie déjà purgée (clôture `stale_game` suivie de la purge) n'a plus de
 * siège à lire — rien à oublier, le cache expirant de lui-même.
 */
final readonly class ForgetPlayHistoryCache
{
    public function handle(GameFinalized $event): void
    {
        Player::query()
            ->whereIn('id', GamePlayer::query()->select('player_id')->where('game_id', $event->gameId))
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->each(static function (mixed $userId): void {
                PlayHistoryCache::forget((int) $userId);
            });
    }
}
