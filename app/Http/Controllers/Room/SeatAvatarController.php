<?php

namespace App\Http\Controllers\Room;

use App\Actions\Room\ChangeSeatAvatar;
use App\Enums\RoomRefusal;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Requests\Room\ChangeSeatAvatarRequest;
use App\Models\Room;
use App\Policies\RoomPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un siège change son avatar en salle d'attente — `room.avatar.update`,
 * `POST /r/{room}/avatar` (spec 50 § 8.1, spec 40 § 11.4 ; D55 du 02/10).
 *
 * Pile : `seat.active` (403 sans siège, 409 `seat_superseded`), puis
 * `throttle:game-write`, puis la liaison du salon ; la policy
 * ({@see RoomPolicy::changeAvatar()}) exige que le siège appartienne au
 * salon, et {@see ChangeSeatAvatar} relit tout sous les verrous du salon et
 * du siège. Aucune autorité d'hôte : c'est le siège du demandeur.
 *
 * Réponses, toutes en 303 vers la page qui a posté (à défaut `room.show`) :
 * succès ou choix inchangé, sans message ; avatar pris → erreur `avatar`
 * (`room.lobby.avatar_taken`) ; hors du lobby (partie, podium) → erreur
 * `avatar` (`room.refusal.not_in_lobby`) ; salon archivé → `room.show`, qui
 * rend « salon expiré ». Messages dans la langue de la requête.
 */
class SeatAvatarController extends Controller
{
    /**
     * @throws AuthorizationException Le siège n'appartient pas au salon.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function update(ChangeSeatAvatarRequest $request, Room $room, ChangeSeatAvatar $change): RedirectResponse
    {
        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('Le changement d\'avatar exige le middleware seat.active (D55 du 02/10).');

        Gate::authorize('changeAvatar', [$room, $seat]);

        $back = back(Response::HTTP_SEE_OTHER, fallback: route('room.show', $room));

        try {
            $refusal = $change->handle($room, $seat, $request->avatarChoice(), $request);
        } catch (ValidationException $refused) {
            return $back->withErrors($refused->errors());
        }

        return match ($refusal) {
            null => $back,
            RoomRefusal::RoomArchived => to_route('room.show', $room, Response::HTTP_SEE_OTHER),
            default => $back->withErrors(['avatar' => self::text(__($refusal->messageKey()), $refusal->messageKey())]),
        };
    }

    /** Un message traduit, à défaut sa clé. */
    private static function text(mixed $message, string $key): string
    {
        return is_string($message) ? $message : $key;
    }
}
