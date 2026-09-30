<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\RecordHeartbeat;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Le battement de présence d'un siège de salon — route `room.heartbeat`,
 * `POST /r/{room}/heartbeat` (spec 60 § 10.1 et § 13.1, contrat C7 § 2.4).
 *
 * Le siège est résolu par le hash du `player_token` courant, **expulsé
 * exclu** (`PlayerTokenManager::seatIn()`, contrat C4 I4.9) : **403** sans
 * siège dans ce salon, pour un siège expulsé, ou pour un salon archivé (dont
 * l'archivage a effacé le hash). Ni `seat.active` (table du § 10.1) — la
 * présence est celle du SIÈGE ; c'est le client qui cesse de battre dans un
 * onglet supplanté, qui n'écrit plus (§ 12.7) —, ni frappe de jeton
 * d'onglet, ni rattrapage : le battement reste léger. La transaction du
 * § 13.1 appartient à {@see RecordHeartbeat}.
 *
 * Réponse : **204**, sans corps. Aucun domaine de traduction : la route ne
 * rend aucune page. `throttle:game-write` (§ 10.3) : `gameWritesPerMinute`
 * couvre deux écritures par battement (`EngineConstants`).
 */
final class RoomHeartbeatController extends Controller
{
    public function store(Request $request, Room $room, PlayerTokenManager $tokens, RecordHeartbeat $heartbeat): Response
    {
        $seat = $room->archived_at === null ? $tokens->seatIn($request, $room) : null;

        abort_if($seat === null || ! $heartbeat->handle($seat, $room), HttpStatus::HTTP_FORBIDDEN);

        return response()->noContent();
    }
}
