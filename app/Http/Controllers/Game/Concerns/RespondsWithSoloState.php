<?php

namespace App\Http\Controllers\Game\Concerns;

use App\Actions\Game\CatchUpGame;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Player;
use App\Support\Game\CurrentGame;
use App\Support\Game\GameJournal;
use App\Support\Game\GameStateBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Le paquet `GameStatePacket` d'un siège solo, réponse JSON à destinataire
 * unique — spec 60 § 12.1, § 16.4 et § 16.5 : la réponse de `solo.state` et
 * celle de chaque geste solo (`solo.reveal`, `solo.skip`, `solo.next`), qui
 * « répond par le `GameStatePacket` à jour ».
 *
 * 1. L'instant du paquet est pris d'abord, **avant le rattrapage et toute
 *    lecture** : un `serverNow` jamais postérieur aux lectures. Après un
 *    geste, il est pris APRÈS le commit du geste : le paquet décrit le geste,
 *    et le magasin du client, qui écarte tout paquet antérieur au dernier
 *    appliqué, ne lui préfère jamais un sondage construit avant lui.
 * 2. La partie décrite suit le § 12.2 ({@see CurrentGame::forState()} : la
 *    partie solo en cours du siège, sinon sa dernière partie close pour son
 *    podium, sinon aucune) ; avec une partie, {@see CatchUpGame} exécute
 *    d'abord toutes les étapes échues — le solo ne vit que de ce rattrapage,
 *    aucun événement ne lui parvient —, puis la partie est relue.
 * 3. Le jeton présenté est l'en-tête `X-Seat-Token` : un onglet supplanté
 *    lit `self.seatActive: false` (§ 12.7).
 *
 * `no-store` : un paquet décrit l'état d'un siège à un instant, jamais
 * réutilisable.
 */
trait RespondsWithSoloState
{
    /**
     * @param  bool  $journal  Une ligne `game.resynchronized` au journal
     *                         `game` (§ 4.7) — pour `solo.state`, une par
     *                         requête avec une partie, rien du paquet.
     */
    private function soloState(Request $request, Player $seat, CatchUpGame $catchUp, bool $journal): JsonResponse
    {
        $now = Date::now()->toImmutable();
        $game = CurrentGame::forState($seat);

        if ($game !== null) {
            $catchUp->handle($game, $now);
            $game->refresh();

            if ($journal) {
                GameJournal::resynchronized($game);
            }
        }

        $packet = GameStateBuilder::build($game, $seat, $now, EnsureActiveSeat::presentedToken($request));

        return response()->json($packet)->header('Cache-Control', 'no-store, private');
    }
}
