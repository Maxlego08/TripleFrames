<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Room;
use App\Support\Game\CurrentGame;
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
 * destinataire unique. **Lecture, jamais écriture** : ni `seat.active` — un
 * onglet supplanté doit pouvoir lire son `self.seatActive: false`, § 12.7 —,
 * ni frappe de jeton d'onglet. Le jeton présenté est l'en-tête
 * `X-Seat-Token`.
 *
 * **403** sans siège tenu par le jeton dans ce salon (`seatIn()`, expulsé
 * exclu, contrat C4 I4.9), ou pour un salon archivé. La partie décrite suit
 * le § 12.2 ({@see CurrentGame::forState()}).
 *
 * Le rattrapage des étapes échues (`CatchUpGame`) avant la construction du
 * paquet arrive avec la branche de partie (lot L60-12) : sans partie, il
 * n'y a rien à rattraper.
 *
 * `no-store` : un paquet décrit l'état d'un siège à un instant, jamais
 * réutilisable.
 */
final class RoomStateController extends Controller
{
    public function show(Request $request, Room $room, PlayerTokenManager $tokens): JsonResponse
    {
        $seat = $room->archived_at === null ? $tokens->seatIn($request, $room) : null;

        abort_if($seat === null, Response::HTTP_FORBIDDEN);

        $packet = GameStateBuilder::build(
            CurrentGame::forState($seat),
            $seat,
            Date::now()->toImmutable(),
            EnsureActiveSeat::presentedToken($request),
        );

        return response()->json($packet)->header('Cache-Control', 'no-store, private');
    }
}
