<?php

namespace App\Listeners\Game;

use App\Actions\Game\FinalizeGame;
use App\Enums\GameMode;
use App\Events\Game\GameEnded;
use App\Events\Game\GameFinalized;
use App\Models\Game;
use App\Models\Room;
use App\Support\Scoring\Scoreboard;

/**
 * L'annonce de la fin d'une partie — spec 60 § 14.5 et § 11.3, contrat C7
 * § 2.5 (écouteur interne, nom libre ; lot L60-11).
 *
 * Écoute l'événement de domaine `GameFinalized` (contrat C13), livré après
 * le commit du gel ({@see FinalizeGame}), et émet `game.ended`
 * `{ podium }` au salon, **en multijoueur seulement** (§ 11.2) : en solo,
 * aucune diffusion, le podium part par `solo.state`. Le podium est composé
 * par son seul producteur, {@see Scoreboard::podium()}, sur la partie
 * RELUE : issue, instant de fin et agrégats figés tels que le gel les a
 * validés.
 *
 * **Idempotent et tolérant** : une partie disparue (clôture `stale_game`
 * suivie de sa purge), solo ou sans salon n'annonce rien ; un événement
 * redélivré réémet le même podium, que le client range sans effet. Le salon
 * reste `playing`, podium compris, jusqu'au « Rejouer » de l'hôte (50).
 *
 * `game.ended` n'est jamais une frontière et ne se périme jamais : un gel
 * décidé dans un passage de rattrapage part dans le flux du passage (§ 4.4).
 */
final readonly class BroadcastGameEnded
{
    public function handle(GameFinalized $event): void
    {
        $game = Game::query()->find($event->gameId);

        if (! $game instanceof Game || $game->mode !== GameMode::Multiplayer || $game->ended_at === null) {
            return;
        }

        $room = Room::query()->find($game->room_id);

        if (! $room instanceof Room) {
            return;
        }

        GameEnded::dispatch($room, $game, ['podium' => Scoreboard::podium($game)]);
    }
}
