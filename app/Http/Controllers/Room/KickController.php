<?php

namespace App\Http\Controllers\Room;

use App\Actions\Room\KickSeat;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Player;
use App\Models\Room;
use App\Policies\RoomPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'hôte retire un siège du salon — `room.players.kick`,
 * `POST /r/{room}/players/{target}/kick` (spec 50 § 11.3, § 21 ; D15 du
 * 23/09).
 *
 * **`{target}`, jamais `{player}`** : `seat.active` lit `{player}` comme le
 * siège du DEMANDEUR et exige que le jeton courant le tienne. Le jeton de
 * l'hôte ne tenant jamais le siège de la cible, toute expulsion serait
 * refusée. Le siège de l'hôte est résolu par `{room}` (`seatIn()`, expulsé
 * exclu) ; `{target}` est le `public_id` du siège VISÉ, lu comme chaîne et
 * résolu dans ce salon, 404 sinon — après la policy, pour qu'un siège sans
 * autorité ne sonde pas les `public_id` d'un salon.
 *
 * Pile : `seat.active` (403 sans siège, 409 `seat_superseded`), puis
 * `throttle:game-write`, puis la liaison du salon. La policy
 * ({@see RoomPolicy::kick()}) est une première garde ; {@see KickSeat} relit
 * l'autorité sous le verrou du salon.
 *
 * **Réponses** à destinataire unique, dans la langue de la requête — jamais
 * un 409 ni un 422 à une visite Inertia (§ 12.5) : retour au salon (303) ;
 * `not_host` sous le verrou : 403 ; l'hôte qui se vise lui-même : retour au
 * salon avec l'erreur `room` (`room.lobby.cannot_kick_self`). Un second geste
 * sur un siège déjà expulsé ne fait rien et revient au salon sans erreur.
 */
class KickController extends Controller
{
    /**
     * @throws AuthorizationException Le siège n'est pas l'hôte du salon.
     * @throws ModelNotFoundException Aucun siège de ce salon ne porte ce `public_id`.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function store(Request $request, Room $room, string $target, KickSeat $kick): RedirectResponse
    {
        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('L’expulsion exige le middleware seat.active (spec 50 § 11.3, § 21).');

        Gate::authorize('kick', [$room, $seat]);

        $targeted = Player::query()
            ->whereBelongsTo($room)
            ->where('public_id', $target)
            ->firstOrFail();

        try {
            $kick->handle($room, $seat, $targeted);
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
