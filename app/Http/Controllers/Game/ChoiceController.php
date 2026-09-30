<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\SubmitChoice;
use App\Enums\RoundPlayerInputState;
use App\Enums\SubmissionOutcome;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Requests\Game\ChoiceStoreRequest;
use App\Models\Player;
use App\Support\Game\ReceptionInstant;
use App\ValueObjects\Answers\SubmissionVerdict;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * Clic d'une proposition du QCM — `round.choice.store`, `POST
 * /seat/{player:public_id}/choice` (spec 70 § 7.6, contrat C10 § 2 et § 3).
 *
 * Le siège est adressé par `player.public_id`, donc la même route sert le
 * salon et le solo ; le `public_id` ne donne **aucun droit** : `seat.active`
 * exige que le `player_token` courant tienne ce siège et que `X-Seat-Token`
 * soit le jeton d'onglet actif (403, ou 409 `seat_superseded`), puis met en
 * mémoire le siège et sa partie courante (contrat C17).
 *
 * **Sans partie courante, 409 `closed` sans appeler l'action**, et rien n'est
 * écrit. Sinon, l'action {@see SubmitChoice} juge le clic à l'instant de
 * réception ({@see ReceptionInstant}), capturé à l'entrée de la requête avant
 * tout verrou.
 *
 * **Réponses** (§ 7.7), JSON à destinataire unique : 200 `accepted` (clic
 * juste) ou `rejected` (clic faux, `qcm_wrong`), 409 `closed` avec `message`
 * résolu dans la locale de la requête (`game.answer.closed`, lettre du
 * contrat C10 § 3), 422 pour une chaîne absente des quatre propositions
 * (`game.choices.invalid`). Aucune ne porte de titre, d'index ni de drapeau
 * de la bonne proposition.
 */
final class ChoiceController extends Controller
{
    /**
     * @throws LogicException La route ne porte pas `seat.active`, ou son siège
     *                        n'est pas le siège adressé.
     */
    public function store(ChoiceStoreRequest $request, Player $player): JsonResponse
    {
        $receivedAt = ReceptionInstant::of($request);

        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('Le clic exige le middleware seat.active (spec 70 § 7.1).');

        if (! $seat->is($player)) {
            throw new LogicException('seat.active a résolu un autre siège que le siège adressé (spec 70 § 7.1).');
        }

        $game = EnsureActiveSeat::game($request);

        // Résolue ici seulement : sans partie courante, l'action n'est ni
        // construite ni appelée.
        $verdict = $game === null
            ? SubmissionVerdict::closed(RoundPlayerInputState::Open)
            : app(SubmitChoice::class)->handle($seat, $game, $request->roundSequence(), $request->rawChoice(), $receivedAt);

        $body = $verdict->toArray();

        // Destinataire unique : le message est résolu dans la locale de la
        // requête (05 § Erreurs, validation et messages à destinataire unique).
        if ($verdict->outcome === SubmissionOutcome::Closed) {
            $body['message'] = __('game.answer.closed');
        }

        return response()->json($body, $verdict->httpStatus());
    }
}
