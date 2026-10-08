<?php

namespace App\Http\Controllers\Room;

use App\Actions\Room\ReportSeatNickname;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Player;
use App\Models\Room;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un siège signale le pseudo d'un autre siège —
 * `room.players.report_nickname`, `POST /r/{room}/players/{target}/report-nickname`
 * (spec 40 § 13.3, D66 du 07/10).
 *
 * Même pile que le signalement d'avatar : `seat.active` résout le siège du
 * DEMANDEUR, `{target}` est le `public_id` du siège visé, résolu dans ce
 * salon, 404 sinon ; `throttle:seat-report` par siège. Tout siège actif peut
 * signaler : aucune autorité d'hôte.
 *
 * Réponse : retour au salon (303), sans message — la page annonce elle-même
 * l'envoi (`common.player.report.sent`), identique dans tous les cas
 * acceptés. Un signalement irrecevable : retour avec l'erreur `nickname`.
 */
class NicknameReportController extends Controller
{
    /**
     * @throws ModelNotFoundException Aucun siège de ce salon ne porte ce `public_id`.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function store(Request $request, Room $room, string $target, ReportSeatNickname $report): RedirectResponse
    {
        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('Le signalement exige le middleware seat.active (spec 40 § 13.3).');

        $targeted = Player::query()
            ->whereBelongsTo($room)
            ->where('public_id', $target)
            ->firstOrFail();

        try {
            $report->handle($room, $seat, $targeted);
        } catch (ValidationException $refusal) {
            return back(Response::HTTP_SEE_OTHER, fallback: route('room.show', $room))->withErrors($refusal->errors());
        }

        return back(Response::HTTP_SEE_OTHER, fallback: route('room.show', $room));
    }
}
