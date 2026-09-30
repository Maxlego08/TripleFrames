<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\CatchUpGame;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Room;
use App\Support\Game\CurrentGame;
use App\Support\Game\GameJournal;
use App\Support\Game\GameStateBuilder;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * La resynchronisation d'un siège de salon — route `room.state`,
 * `GET /r/{room}/state` (spec 60 § 10.1 et § 12, contrat C7 § 2.4).
 *
 * Réponse : le paquet `GameStatePacket` ({@see GameStateBuilder}), à
 * destinataire unique. Ni `seat.active` — un onglet supplanté doit pouvoir
 * lire son `self.seatActive: false`, § 12.7 —, ni frappe de jeton d'onglet.
 * Le jeton présenté est l'en-tête `X-Seat-Token`.
 *
 * **403** sans siège tenu par le jeton dans ce salon (`seatIn()`, expulsé
 * exclu, contrat C4 I4.9), ou pour un salon archivé. La partie décrite suit
 * le § 12.2 ({@see CurrentGame::forState()}).
 *
 * **Rattrapage avant construction** (§ 12.1, § 4.4) : avec une partie,
 * {@see CatchUpGame} exécute d'abord toutes les étapes échues à l'instant de
 * la requête — le paquet décrit donc toujours l'état échu, même quand le job
 * de frontière est en retard —, puis la partie est relue. Ce sont, avec
 * l'écriture conditionnelle du cas défensif du QCM (70 § 10.8,
 * `SeatInputView::forSeat()` au sein du constructeur), les seules écritures
 * de la route — celles du rattrapage sont exactement celles qu'aurait faites
 * le job à l'heure. Une ligne `game.resynchronized` part au journal `game`
 * (§ 4.7), rien du paquet.
 *
 * L'instant du paquet est pris AVANT le rattrapage et toute lecture : un
 * `serverNow` jamais postérieur aux lectures, donc jamais un événement émis
 * après elles tenu par le client pour déjà compris (§ 11.8).
 *
 * `no-store` : un paquet décrit l'état d'un siège à un instant, jamais
 * réutilisable.
 */
final class RoomStateController extends Controller
{
    public function show(Request $request, Room $room, PlayerTokenManager $tokens, CatchUpGame $catchUp): JsonResponse
    {
        $now = Date::now()->toImmutable();
        $seat = $room->archived_at === null ? $tokens->seatIn($request, $room) : null;

        abort_if($seat === null, Response::HTTP_FORBIDDEN);

        $game = CurrentGame::forState($seat);

        if ($game !== null) {
            $catchUp->handle($game, $now);
            $game->refresh();

            GameJournal::resynchronized($game);
        }

        $packet = GameStateBuilder::build($game, $seat, $now, EnsureActiveSeat::presentedToken($request));

        return response()->json($packet)->header('Cache-Control', 'no-store, private');
    }
}
