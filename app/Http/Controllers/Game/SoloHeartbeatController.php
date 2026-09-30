<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\RecordHeartbeat;
use App\Http\Controllers\Controller;
use App\Support\Game\SoloSeat;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Le battement de présence du siège solo — route `solo.heartbeat`,
 * `POST /solo/heartbeat` (spec 60 § 10.1, § 13.1 et § 13.3 ; contrat C7
 * § 2.4).
 *
 * Même transaction que le battement de salon ({@see RecordHeartbeat}, sans
 * salon) : `last_seen_at` écrit par Eloquent, siège `disconnected` ramené à
 * `connected`, partie solo en pause reprise (§ 14.2, échéance vérifiée
 * d'abord), balayage du siège solo réarmé après commit. Un siège solo ne passe
 * jamais `left` (§ 13.3) : quitter la page le laisse `disconnected`, la
 * manche va au bout de `D`, puis la partie se met en pause et s'interrompt
 * à `paused_at + pauseTimeoutMs` (§ 16.6).
 *
 * Le siège est résolu par le hash du `player_token` courant ({@see SoloSeat}) :
 * **403** sans siège solo, ou si le siège a disparu ou a été effacé sous le
 * verrou. Ni `seat.active` — la présence est celle du SIÈGE ; c'est le
 * client qui cesse de battre dans un onglet supplanté (§ 12.7) —, ni
 * rattrapage, ni domaine de traduction : **204**, sans corps. **Aucune
 * diffusion** (C7 § 4.12).
 */
final class SoloHeartbeatController extends Controller
{
    public function store(Request $request, SoloSeat $soloSeat, RecordHeartbeat $heartbeat): Response
    {
        $seat = $soloSeat->of($request);

        abort_if($seat === null || ! $heartbeat->handle($seat, null), HttpStatus::HTTP_FORBIDDEN);

        return response()->noContent();
    }
}
