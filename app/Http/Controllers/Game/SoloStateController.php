<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\CatchUpGame;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Game\Concerns\RespondsWithSoloState;
use App\Support\Game\SoloSeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le sondage de la partie solo — route `solo.state`, `GET /solo/state` (spec
 * 60 § 10.1, § 12 et § 16.4 ; contrat C7 § 2.4 et § 4.12).
 *
 * Le solo ne reçoit **aucun événement** (aucun canal, 10 § 7.10) : le client
 * tire ce paquet au montage, à chaque `nextTransitionAt`, à chaque
 * `fetchNotBefore`, au retour de visibilité, et reçoit le même après chaque
 * geste. Le paquet porte l'état échu, rattrapage compris
 * ({@see RespondsWithSoloState}), et la règle du § 12.3 lui livre le palier 1
 * suivant avant `T₁`. Le débit `game-read` est dimensionné pour ce sondage
 * (§ 19.1, `gameReadsPerMinute`).
 *
 * Une LECTURE : ni `seat.active` — l'onglet supplanté doit pouvoir y lire
 * `seatActive: false` (§ 12.7) —, ni frappe de jeton d'onglet, ni jeton
 * `player_token` (un GET ne frappe jamais, C4 I4.1). **403** sans siège solo
 * tenu par le jeton courant ({@see SoloSeat}, § 16.4). Les seules écritures
 * sont celles du rattrapage — exactement celles qu'aurait faites le job à
 * l'heure — et le cas défensif du QCM de 70.
 */
final class SoloStateController extends Controller
{
    use RespondsWithSoloState;

    public function show(Request $request, SoloSeat $soloSeat, CatchUpGame $catchUp): JsonResponse
    {
        $seat = $soloSeat->of($request);

        abort_if($seat === null, Response::HTTP_FORBIDDEN);

        return $this->soloState($request, $seat, $catchUp, journal: true);
    }
}
