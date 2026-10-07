<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\CancelPauseRequest;
use App\Actions\Game\CatchUpGame;
use App\Actions\Game\RecordHeartbeat;
use App\Actions\Game\RequestGamePause;
use App\Actions\Game\ResumePausedGame;
use App\Enums\GameMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Game\Concerns\RespondsWithSoloState;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Game;
use App\Models\Player;
use App\Support\Game\PauseGestureOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * La pause manuelle d'une partie solo — D64 du 07/10, spec 60 § 14.1 bis,
 * § 14.2 et § 16.5 :
 *
 * - `solo.pause`, `POST /solo/game/pause` ({@see RequestGamePause}) ;
 * - `solo.pause.cancel`, `POST /solo/game/pause/cancel` ({@see CancelPauseRequest}) ;
 * - `solo.resume`, `POST /solo/game/resume` ({@see ResumePausedGame}).
 *
 * Même pile que les autres gestes solo (`seat.active`, `throttle:game-write`,
 * siège solo du jeton). **Chaque geste vaut battement** (§ 16.5) — un
 * battement ne reprend jamais une pause `manual` : seul `solo.resume` la
 * reprend. Réponses : **200** et le `GameStatePacket` à jour ; **409**
 * `{ "code": … }` (`not_running`, `no_round_left`, `budget_exhausted`,
 * `draining`), rendu par `game.pause.errors.{code}`. Aucune diffusion.
 */
final class SoloPauseController extends Controller
{
    use RespondsWithSoloState;

    /**
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function pause(Request $request, RequestGamePause $pause, RecordHeartbeat $heartbeat, CatchUpGame $catchUp): JsonResponse
    {
        [$seat, $game, $now] = self::gesture($request);

        if ($game === null) {
            return self::conflict(PauseGestureOutcome::NotRunning);
        }

        $heartbeat->handle($seat, null, $now);

        return $this->respond($request, $seat, $catchUp, $pause->handle($game, $now));
    }

    /**
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function cancel(Request $request, CancelPauseRequest $cancel, RecordHeartbeat $heartbeat, CatchUpGame $catchUp): JsonResponse
    {
        [$seat, $game, $now] = self::gesture($request);

        if ($game === null) {
            return self::conflict(PauseGestureOutcome::NotRunning);
        }

        $heartbeat->handle($seat, null, $now);

        return $this->respond($request, $seat, $catchUp, $cancel->handle($game, $now));
    }

    /**
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function resume(Request $request, ResumePausedGame $resume, RecordHeartbeat $heartbeat, CatchUpGame $catchUp): JsonResponse
    {
        [$seat, $game, $now] = self::gesture($request);

        if ($game === null) {
            return self::conflict(PauseGestureOutcome::NotRunning);
        }

        $heartbeat->handle($seat, null, $now);

        return $this->respond($request, $seat, $catchUp, $resume->handle($game, $seat, $now));
    }

    /**
     * @throws LogicException Issue d'autorité, qui n'existe qu'en multijoueur.
     */
    private function respond(Request $request, Player $seat, CatchUpGame $catchUp, PauseGestureOutcome $outcome): JsonResponse
    {
        if ($outcome === PauseGestureOutcome::Forbidden) {
            throw new LogicException('Geste de pause solo : aucune autorité à relire (spec 60 § 14).');
        }

        return $outcome->conflictCode() === null
            ? $this->soloState($request, $seat->refresh(), $catchUp, journal: false)
            : self::conflict($outcome);
    }

    /**
     * Le siège solo résolu par `seat.active`, sa partie solo en cours (ou
     * NULL), et l'instant du geste, à la milliseconde de `timestamp(3)`.
     *
     * @return array{0: Player, 1: Game|null, 2: CarbonImmutable}
     *
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    private static function gesture(Request $request): array
    {
        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('Les gestes solo exigent le middleware seat.active (spec 60 § 10.1).');

        if ($seat->room_id !== null) {
            throw new LogicException('seat.active a résolu un siège de salon sur une route solo (spec 60 § 10.2).');
        }

        $game = EnsureActiveSeat::game($request);

        return [
            $seat,
            $game !== null && $game->mode === GameMode::Solo ? $game : null,
            Date::now()->toImmutable()->startOfMillisecond(),
        ];
    }

    private static function conflict(PauseGestureOutcome $outcome): JsonResponse
    {
        return response()->json(['code' => $outcome->conflictCode()], Response::HTTP_CONFLICT);
    }
}
