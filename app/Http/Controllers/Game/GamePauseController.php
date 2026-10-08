<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\CancelPauseRequest;
use App\Actions\Game\RequestGamePause;
use App\Actions\Game\ResumePausedGame;
use App\Enums\GameMode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Policies\RoomPolicy;
use App\Support\Game\PauseGestureOutcome;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * La pause manuelle d'une partie multijoueur — D64 du 07/10, spec 60
 * § 14.1 bis, § 14.2 et § 10.1 :
 *
 * - `room.game.pause`, `POST /r/{room}/game/pause` — l'hôte met la partie en
 *   pause : immédiate entre deux manches, sinon demandée pour la fin de la
 *   révélation de la manche en cours ({@see RequestGamePause}) ;
 * - `room.game.pause.cancel`, `POST /r/{room}/game/pause/cancel` — l'hôte
 *   retire une pause demandée pas encore effective ({@see CancelPauseRequest}) ;
 * - `room.game.resume`, `POST /r/{room}/game/resume` — l'hôte reprend ; si
 *   l'hôte n'est pas un siège présent, tout siège présent de la partie
 *   ({@see ResumePausedGame}).
 *
 * Pile : `seat.active` (403 sans siège, 409 `seat_superseded`), puis
 * `throttle:game-write`, puis la liaison du salon. La policy
 * ({@see RoomPolicy::pauseGame()}, {@see RoomPolicy::resumeGame()}) est une
 * première garde ; l'action **relit l'autorité sous le verrou du salon**.
 *
 * Réponses, JSON à destinataire unique : **204** (l'état arrive par les
 * diffusions) ; **409** `{ "code": … }` (`not_running`, `no_round_left`,
 * `budget_exhausted`, `draining`), rendu par `game.pause.errors.{code}` ;
 * **403** pour un siège sans autorité.
 */
final class GamePauseController extends Controller
{
    /**
     * @throws AuthorizationException Le siège n'est pas l'hôte du salon.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function pause(Request $request, Room $room, RequestGamePause $pause): Response|JsonResponse
    {
        [$seat, $game] = self::gesture($request, $room, 'pauseGame');

        if ($game === null) {
            return self::respond(PauseGestureOutcome::NotRunning);
        }

        $gate = Gate::forUser($request->user());

        return self::respond($pause->handle(
            $game,
            Date::now()->toImmutable(),
            static fn (Room $lockedRoom): bool => $gate->allows('pauseGame', [$lockedRoom, $seat]),
        ));
    }

    /**
     * @throws AuthorizationException Le siège n'est pas l'hôte du salon.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function cancel(Request $request, Room $room, CancelPauseRequest $cancel): Response|JsonResponse
    {
        [$seat, $game] = self::gesture($request, $room, 'pauseGame');

        if ($game === null) {
            return self::respond(PauseGestureOutcome::Unchanged);
        }

        $gate = Gate::forUser($request->user());

        return self::respond($cancel->handle(
            $game,
            Date::now()->toImmutable(),
            static fn (Room $lockedRoom): bool => $gate->allows('pauseGame', [$lockedRoom, $seat]),
        ));
    }

    /**
     * @throws AuthorizationException Le siège ne peut pas reprendre.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function resume(Request $request, Room $room, ResumePausedGame $resume): Response|JsonResponse
    {
        $seat = self::seat($request);
        $game = self::game($request, $room);

        // Sans partie en cours, tout siège du salon reçoit le refus traduit
        // `not_running` : un siège qui n'est pas l'hôte a pu se voir offrir
        // « Reprendre » (hôte absent) juste avant la clôture de la pause.
        if ($game === null) {
            abort_unless($seat->room_id === $room->id, HttpStatus::HTTP_FORBIDDEN);

            return self::respond(PauseGestureOutcome::NotRunning);
        }

        Gate::authorize('resumeGame', [$room, $seat, $game]);

        $gate = Gate::forUser($request->user());

        return self::respond($resume->handle(
            $game,
            $seat,
            Date::now()->toImmutable(),
            static fn (Room $lockedRoom): bool => $gate->allows('resumeGame', [$lockedRoom, $seat, $game]),
        ));
    }

    /**
     * Le siège, autorisé par `$ability`, et la partie multijoueur en cours
     * du salon (ou NULL).
     *
     * @return array{0: Player, 1: Game|null}
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    private static function gesture(Request $request, Room $room, string $ability): array
    {
        $seat = self::seat($request);

        Gate::authorize($ability, [$room, $seat]);

        return [$seat, self::game($request, $room)];
    }

    /**
     * @throws LogicException
     */
    private static function seat(Request $request): Player
    {
        return EnsureActiveSeat::seat($request)
            ?? throw new LogicException('Les gestes de pause exigent le middleware seat.active (spec 60 § 10.1).');
    }

    /** La partie en cours du salon, mise en mémoire par `seat.active`. */
    private static function game(Request $request, Room $room): ?Game
    {
        $game = EnsureActiveSeat::game($request);

        return $game !== null && $game->mode === GameMode::Multiplayer && $game->room_id === $room->id ? $game : null;
    }

    private static function respond(PauseGestureOutcome $outcome): Response|JsonResponse
    {
        if ($outcome === PauseGestureOutcome::Forbidden) {
            abort(HttpStatus::HTTP_FORBIDDEN);
        }

        $code = $outcome->conflictCode();

        return $code === null
            ? response()->noContent()
            : response()->json(['code' => $code], HttpStatus::HTTP_CONFLICT);
    }
}
