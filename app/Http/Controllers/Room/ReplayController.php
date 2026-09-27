<?php

namespace App\Http\Controllers\Room;

use App\Actions\Room\ReplayRoom;
use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Room\Concerns\AnswersRoomGesture;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Room;
use App\Policies\RoomPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * L'hôte rejoue depuis le podium — `room.replay`, `POST /r/{room}/replay`
 * (spec 50 § 13, § 21 ; contrat C6).
 *
 * Pile : `seat.active` (siège du jeton, onglet actif, sinon 403 ou 409
 * `seat_superseded`), puis `throttle:game-write`, puis la liaison du salon.
 * La policy ({@see RoomPolicy::replay()}) est une première garde ;
 * {@see ReplayRoom} relit tout sous le verrou du salon.
 *
 * **Réponses**, à destinataire unique, donc dans la langue de la requête
 * (§ 12.5) — jamais un 409 ni un 422 à une visite Inertia :
 * - fait, ou déjà fait (double clic, second onglet) : 303 vers la page du
 *   salon, qui montre de nouveau le lobby ;
 * - `not_host` (hôte changé entre la policy et le verrou) : 403 ;
 * - `game_not_ended`, `draining`, `room_archived` : retour au salon avec
 *   l'erreur `room` — `common.maintenance.launch_blocked` pour le drainage,
 *   message unique de la maintenance (R-09) ;
 * - **échec technique** (base, délai de verrou) : une ligne part au journal
 *   `game` sans donnée personnelle, comme pour le lancement. Levée DANS la
 *   transaction, l'exception l'annule : le salon reste en partie, sur son
 *   podium, et l'hôte lit `room.errors.launch_failed` (§ 12.5 : le même
 *   message pour le lancement et « Rejouer »), jamais une 500 brute en
 *   anglais ;
 * - **échec après la validation** : le salon, relu, est déjà au lobby — le
 *   geste a eu lieu, par cet appel ou par un appel concurrent — et la
 *   réponse est celle d'un « Rejouer » réussi, sans erreur. Seule la
 *   diffusion suit la validation, et elle avale ses propres échecs
 *   (`ShouldRescue`) : les clients se rattrapent par la resynchronisation.
 */
class ReplayController extends Controller
{
    use AnswersRoomGesture;

    /** Libellé de la ligne du journal `game` d'un « Rejouer » en échec (§ 12.5). */
    public const string LOG_REPLAY_FAILED = 'room.replay_failed';

    /**
     * @throws AuthorizationException Le siège n'est pas l'hôte du salon.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function store(Request $request, Room $room, ReplayRoom $replay): RedirectResponse
    {
        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('« Rejouer » exige le middleware seat.active (spec 50 § 12.1, § 21).');

        Gate::authorize('replay', [$room, $seat]);

        try {
            $refusal = $replay->handle($room, $seat);
        } catch (Throwable $exception) {
            $inLobby = self::rereadStatus($room) === RoomStatus::Lobby;
            self::journalGestureFailure(self::LOG_REPLAY_FAILED, $exception, ['roomLobby' => $inLobby]);

            if ($inLobby) {
                return to_route('room.show', $room, Response::HTTP_SEE_OTHER);
            }

            return self::backToRoom($room, LaunchController::KEY_LAUNCH_FAILED);
        }

        if ($refusal === null) {
            return to_route('room.show', $room, Response::HTTP_SEE_OTHER);
        }

        if ($refusal === RoomRefusal::NotHost) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return self::backToRoom($room, $refusal->messageKey());
    }
}
