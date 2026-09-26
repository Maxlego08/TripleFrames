<?php

namespace App\Http\Controllers\Room;

use App\Actions\Game\ClaimSeatTab;
use App\Actions\Room\CreateRoom;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Room\Concerns\PresentsSeatForm;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Requests\Room\StoreRoomRequest;
use App\Models\Room;
use App\Support\Game\CurrentGame;
use App\Support\Game\GameStateBuilder;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le salon : création et page (spec 50 § 6 et § 7.2 ; 21).
 *
 * - `room.create`, `GET /r/new` : le formulaire de pseudo et d'avatar de
 *   l'hôte (`room/create`, `PublicLayout`, apparence du visiteur). **Un GET
 *   ne frappe jamais de jeton** (C4 I4.1) : le jeton courant est seulement
 *   lu, pour sa revendication d'avatar.
 * - `room.store`, `POST /r` : crée le salon aux réglages par défaut, prend le
 *   siège du créateur, le nomme hôte ({@see CreateRoom}), puis 303 vers la
 *   page du salon — les réglages se font dans le lobby (§ 6.1).
 * - `room.show`, `GET /r/{room}` : la page UNIQUE du salon, du lobby au
 *   podium, sous `game.appearance` — voir {@see self::show()}.
 */
class RoomController extends Controller
{
    use PresentsSeatForm;

    public function create(Request $request, PlayerTokenManager $tokens): InertiaResponse
    {
        return Inertia::render('room/create', [
            'avatars' => $this->avatarProps($tokens->current($request), []),
            'nickname' => $this->nicknameProps(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreRoomRequest $request, CreateRoom $create): RedirectResponse
    {
        $room = $create->handle(
            $request,
            $request->nickname(),
            $request->avatarPreset(),
            $this->effectiveLocale(),
            $request->user(),
        );

        return to_route('room.show', $room, Response::HTTP_SEE_OTHER);
    }

    /**
     * La page du salon rend, dans cet ordre (§ 7.2) :
     *
     * 1. un salon archivé → `game/room-expired`, statut **410** (§ 16.3) ;
     * 2. aucun siège non expulsé pour ce jeton (`seatIn()`) → 303 vers la
     *    page d'entrée publique `room.entry`, hors de `game.appearance` ;
     * 3. sinon : `ClaimSeatTab` (le second onglet prend la main), puis
     *    `game/lobby` avec le paquet `state` construit sur le jeton d'onglet
     *    rendu, et ce jeton en prop `seatToken` (60 § 12.7). La partie décrite
     *    est celle de `room.state` ({@see CurrentGame::forState()}).
     *
     * Toute URL de salon reste `noindex`, et le code n'entre jamais dans un
     * titre (§ 6.5). Ce lot (L50-3b) ne pose que la séquence imposée à toute
     * page de salon : les props de réglages (`settings`, `bounds`, `limits`,
     * `presets`, `launch`, `editor`, `themes`, `configs`), la réparation
     * d'hôte au rendu et la page elle-même sont le lot L50-4.
     */
    public function show(Request $request, Room $room, PlayerTokenManager $tokens, ClaimSeatTab $claim): InertiaResponse|Response
    {
        if ($room->status === RoomStatus::Archived) {
            return Inertia::render('game/room-expired')
                ->toResponse($request)
                ->setStatusCode(Response::HTTP_GONE);
        }

        $seat = $tokens->seatIn($request, $room);

        if ($seat === null) {
            return to_route('room.entry', $room, Response::HTTP_SEE_OTHER);
        }

        $seatToken = $claim->handle($seat, EnsureActiveSeat::presentedToken($request));

        return Inertia::render('game/lobby', [
            'room' => ['code' => $room->room_code],
            'state' => GameStateBuilder::build(CurrentGame::forState($seat), $seat, Date::now()->toImmutable(), $seatToken),
            'seatToken' => $seatToken,
        ]);
    }
}
