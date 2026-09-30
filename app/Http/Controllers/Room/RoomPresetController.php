<?php

namespace App\Http\Controllers\Room;

use App\Actions\Room\ApplyRoomPreset;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Room\Concerns\WritesRoomSettings;
use App\Http\Requests\Room\ApplyRoomPresetRequest;
use App\Models\Room;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * L'hôte applique un preset du site — `room.settings.preset`,
 * `POST /r/{room}/settings/preset` (spec 50 § 5.3, § 21 ; contrat C0).
 *
 * Même pile et même réponse que {@see RoomSettingsController} : les seize
 * champs sont pré-remplis par le preset, sous le verrou du salon, par
 * {@see ApplyRoomPreset}. Un preset grisé n'est pas refusé : le lobby montre
 * alors le blocage du vivier et ses remèdes.
 */
class RoomPresetController extends Controller
{
    use WritesRoomSettings;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function store(ApplyRoomPresetRequest $request, Room $room, ApplyRoomPreset $apply): RedirectResponse
    {
        $seat = $this->hostSeat($request, $room);

        return $this->settingsWriteResponse($room, $apply->handle($room, $seat, $request->preset()));
    }
}
