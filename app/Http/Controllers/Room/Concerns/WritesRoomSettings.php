<?php

namespace App\Http\Controllers\Room\Concerns;

use App\Enums\RoomRefusal;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Player;
use App\Models\Room;
use App\Policies\RoomPolicy;
use App\ValueObjects\Room\SettingsWriteOutcome;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ce que partagent les deux écritures de réglages de l'hôte (spec 50 § 2.5,
 * § 12.5, § 17.1) : le siège autorisé, et la traduction de
 * {@see SettingsWriteOutcome} en réponse HTTP.
 *
 * **Aucune réponse 409 ni 422 à une visite Inertia** (§ 12.5) : un refus de
 * règle passe par une redirection 303, une entrée invalide par les erreurs de
 * session (`ValidationException`, laissée au framework). Seul `seat.active`
 * répond 409 (`seat_superseded`), que le client du lobby intercepte.
 */
trait WritesRoomSettings
{
    /**
     * Le siège résolu par `seat.active`, autorisé à régler le salon
     * ({@see RoomPolicy::updateSettings()}) : 403 sinon.
     *
     * @throws AuthorizationException Le siège n'est pas l'hôte du salon.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    private function hostSeat(Request $request, Room $room): Player
    {
        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('Une écriture de réglages exige le middleware seat.active (spec 50 § 17.1, § 21).');

        Gate::authorize('updateSettings', [$room, $seat]);

        return $seat;
    }

    /**
     * La réponse d'une écriture de réglages, à destinataire unique :
     *
     * - **écrite** : le rapport de changements part à l'AUTEUR seul, en flash
     *   `settingsChanges` (déjà sous les clés client, `themeKeys` et jamais
     *   `themeIds`), puis retour au lobby ; il ne part jamais au salon, qui
     *   reçoit l'état par la diffusion anti-rebondie (§ 2.6, § 8.3). Le flash
     *   part même vide : l'auteur sait ainsi que sa dernière écriture n'a
     *   rien changé d'autre que ce qu'il a posté ;
     * - **`not_host`** — l'hôte a changé entre la policy et le verrou : 403 ;
     * - **`not_in_lobby`** — la partie est lancée : 303 vers la page du salon
     *   (`room.show`), sans erreur (§ 12.5, § 12.7), qui montre alors l'état
     *   de partie — jamais `back()`, qui dépendrait de l'en-tête `Referer`.
     */
    private function settingsWriteResponse(Room $room, SettingsWriteOutcome $outcome): RedirectResponse
    {
        $refusal = $outcome->refusal;

        if ($refusal === null) {
            Inertia::flash('settingsChanges', $outcome->changes);

            return back();
        }

        return match ($refusal) {
            RoomRefusal::NotHost => abort(Response::HTTP_FORBIDDEN),
            RoomRefusal::NotInLobby => to_route('room.show', $room, Response::HTTP_SEE_OTHER),
            default => throw new LogicException(sprintf(
                'Refus inattendu pour une écriture de réglages : %s.',
                $refusal->value,
            )),
        };
    }
}
