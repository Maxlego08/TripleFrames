<?php

namespace App\Http\Controllers\Room;

use App\Actions\Room\LeaveRoom;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Room;
use App\Policies\RoomPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un joueur quitte le salon — `room.leave`, `POST /r/{room}/leave` (spec 50
 * § 11.4, § 21). Chaque joueur peut quitter, hôte compris.
 *
 * Pile : `seat.active` (403 sans siège, 409 `seat_superseded`), puis
 * `throttle:game-write`, puis la liaison du salon ; la policy
 * ({@see RoomPolicy::leave()}) exige que le siège appartienne au salon, et
 * {@see LeaveRoom} relit tout sous les verrous du salon et du siège.
 *
 * Réponse : 303 vers l'accueil (`home`). Le partant peut revenir par le lien
 * du salon : c'est une reprise, jamais une nouvelle entrée.
 */
class LeaveRoomController extends Controller
{
    /**
     * @throws AuthorizationException Le siège n'appartient pas au salon.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function store(Request $request, Room $room, LeaveRoom $leave): RedirectResponse
    {
        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('Le départ exige le middleware seat.active (spec 50 § 11.4, § 21).');

        Gate::authorize('leave', [$room, $seat]);

        $leave->handle($room, $seat);

        return to_route('home', status: Response::HTTP_SEE_OTHER);
    }
}
