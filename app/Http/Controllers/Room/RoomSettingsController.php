<?php

namespace App\Http\Controllers\Room;

use App\Actions\Room\UpdateRoomSettings;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Room\Concerns\WritesRoomSettings;
use App\Http\Requests\Room\UpdateRoomSettingsRequest;
use App\Models\Room;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * L'hôte règle son salon depuis l'onglet Simple — `room.settings.update`,
 * `PATCH /r/{room}/settings` (spec 50 § 3, § 21 ; contrat C0).
 *
 * Pile : `seat.active` (siège du jeton, onglet actif, sinon 403 ou 409
 * `seat_superseded`), puis `throttle:game-write`, puis la liaison du salon.
 * La policy est une première garde ; l'autorité, le statut, l'éditeur,
 * `fromInput()` et la capacité sont relus sous le verrou du salon par
 * {@see UpdateRoomSettings}, qui dispatche la diffusion anti-rebondie après
 * la validation de sa transaction (§ 8.3).
 *
 * Le client envoie un curseur à la validation du geste (`onValueCommit`),
 * jamais à chaque pas.
 */
class RoomSettingsController extends Controller
{
    use WritesRoomSettings;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(UpdateRoomSettingsRequest $request, Room $room, UpdateRoomSettings $update): RedirectResponse
    {
        $seat = $this->hostSeat($request, $room);

        return $this->settingsWriteResponse($update->handle($room, $seat, $request->posted()));
    }
}
