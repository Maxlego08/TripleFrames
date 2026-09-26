<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\SubmitTextAnswer;
use App\Enums\RoundPlayerInputState;
use App\Enums\SubmissionOutcome;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Requests\Game\AnswerStoreRequest;
use App\Models\Player;
use App\Support\Game\ReceptionInstant;
use App\ValueObjects\Answers\SubmissionVerdict;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * Soumission d'une réponse en texte libre — `round.answer.store`, `POST
 * /seat/{player:public_id}/answer` (spec 70 § 7, contrat C10 § 2 et § 3).
 *
 * Le siège est adressé par `player.public_id`, donc la même route sert le
 * salon et le solo ; le `public_id` ne donne **aucun droit** : `seat.active`
 * exige que le `player_token` courant tienne ce siège et que `X-Seat-Token`
 * soit le jeton d'onglet actif (403, ou 409 `seat_superseded`), puis met en
 * mémoire le siège et sa partie courante (contrat C17).
 *
 * **Sans partie courante, 409 `closed` sans appeler l'action**, et rien n'est
 * compté : aucune manche n'est ouverte pour ce siège. Sinon, l'action
 * {@see SubmitTextAnswer} juge la soumission à l'instant de réception
 * ({@see ReceptionInstant}), capturé à l'entrée de la requête avant tout
 * verrou.
 *
 * **Réponses** (§ 7.7), JSON à destinataire unique : 200 `accepted` ou
 * `rejected`, 409 `closed` avec `message` résolu dans la locale de la requête
 * (`game.answer.closed`), 422 pour une saisie trop longue ou vide après
 * normalisation. Aucune ne porte de titre, d'alias, de nature d'appariement,
 * de forme normalisée ni de distance.
 */
final class AnswerController extends Controller
{
    /**
     * @throws LogicException La route ne porte pas `seat.active`, ou son siège
     *                        n'est pas le siège adressé.
     */
    public function store(AnswerStoreRequest $request, Player $player): JsonResponse
    {
        $receivedAt = ReceptionInstant::of($request);

        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('La soumission exige le middleware seat.active (spec 70 § 7.1).');

        if (! $seat->is($player)) {
            throw new LogicException('seat.active a résolu un autre siège que le siège adressé (spec 70 § 7.1).');
        }

        $game = EnsureActiveSeat::game($request);

        // Résolue ici seulement : sans partie courante, l'action n'est ni
        // construite ni appelée.
        $verdict = $game === null
            ? SubmissionVerdict::closed(RoundPlayerInputState::Open)
            : app(SubmitTextAnswer::class)->handle($seat, $game, $request->roundSequence(), $request->rawAnswer(), $receivedAt);

        $body = $verdict->toArray();

        // Destinataire unique : le message est résolu dans la locale de la
        // requête (05 § Erreurs, validation et messages à destinataire unique).
        if ($verdict->outcome === SubmissionOutcome::Closed) {
            $body['message'] = __('game.answer.closed');
        }

        return response()->json($body, $verdict->httpStatus());
    }
}
