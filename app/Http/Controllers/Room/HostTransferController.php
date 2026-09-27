<?php

namespace App\Http\Controllers\Room;

use App\Actions\Room\HandOverHost;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Requests\Room\HandOverHostRequest;
use App\Models\Room;
use App\Policies\RoomPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'hôte confie son rôle — `room.host.transfer`, `POST /r/{room}/host`, corps
 * `{ publicId }` (spec 50 § 11.4, § 21). La confirmation se fait côté client
 * (`room.lobby.transfer_confirm`).
 *
 * Pile : `seat.active` (403 sans siège, 409 `seat_superseded`), puis
 * `throttle:game-write`, puis la liaison du salon. La policy
 * ({@see RoomPolicy::transferHost()}) est une première garde ;
 * {@see HandOverHost} relit l'autorité sous le verrou du salon.
 *
 * **Réponses**, dans la langue de la requête, jamais un 422 à une visite
 * Inertia (§ 12.5) : retour au salon (303), transfert fait ou geste
 * idempotent ; `not_host` sous le verrou : 403 ; cible absente du salon :
 * 404 ; cible non connectée ou expulsée : retour au salon avec l'erreur sous
 * `publicId` (`room.lobby.transfer_unavailable`).
 */
class HostTransferController extends Controller
{
    /**
     * @throws AuthorizationException Le siège n'est pas l'hôte du salon.
     * @throws ModelNotFoundException Aucun siège de ce salon ne porte ce `public_id`.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function store(HandOverHostRequest $request, Room $room, HandOverHost $handOver): RedirectResponse
    {
        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('Le transfert d’hôte exige le middleware seat.active (spec 50 § 11.4, § 21).');

        Gate::authorize('transferHost', [$room, $seat]);

        try {
            $handOver->handle($room, $seat, $request->publicId());
        } catch (ValidationException $refusal) {
            return self::backToRoom($room)->withErrors($refusal->errors());
        }

        return self::backToRoom($room);
    }

    /**
     * Retour à la page du salon, d'où part le geste — jamais l'accueil, même
     * sans en-tête `Referer`.
     */
    private static function backToRoom(Room $room): RedirectResponse
    {
        return back(Response::HTTP_SEE_OTHER, fallback: route('room.show', $room));
    }
}
