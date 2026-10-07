<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\AdvanceToNextRound;
use App\Enums\GameMode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Room;
use App\Policies\RoomPolicy;
use App\Support\Game\NextRoundOutcome;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * « Manche suivante », un pouvoir de l'hôte en partie (avec la pause
 * manuelle, D64 du 07/10, `GamePauseController`) — route
 * `room.round.next`, `POST /r/{room}/round/next` (spec 60 § 5.4, § 10.1 et
 * § 13.5 ; contrat C7 § 2.4 ; 00 § Déroulé d'une partie, « Pouvoirs de
 * l'hôte en partie »). Il **raccourcit `R`, jamais `D`**, et ne touche
 * aucune manche en cours.
 *
 * Pile : `seat.active` (siège du jeton, onglet actif : sinon 403 ou 409
 * `seat_superseded`), puis `throttle:game-write`, puis la liaison du salon.
 * {@see RoomPolicy::advanceRound()} est une première garde (403) ;
 * {@see AdvanceToNextRound} rattrape la partie, puis **relit l'autorité sous
 * le verrou du salon**, sur `room.host_player_id` relu : un transfert d'hôte
 * concurrent, qui verrouille le salon, est sérialisé avec le geste.
 *
 * Réponses, JSON à destinataire unique :
 * - **204** : la révélation est raccourcie à `now + preload_lead_ms +
 *   nextRoundMarginMs`, ou finit déjà avant (rien) ;
 * - **409** `{ "code": "not_revealing" }` hors révélation — aucune manche en
 *   `revealing`, `now ≥ reveal_ends_at`, aucune partie en cours (lobby,
 *   pause, podium) —, rendu par le client avec `game.errors.not_revealing` ;
 * - **403** si le siège n'est pas l'hôte, à la policy comme sous le verrou.
 */
final class NextRoundController extends Controller
{
    /** Code du 409 hors révélation (§ 5.4). */
    public const string NOT_REVEALING = 'not_revealing';

    /** Clé qui le rend côté client (contrat C7 § 2.7). */
    public const string KEY_NOT_REVEALING = 'game.errors.not_revealing';

    /**
     * @throws AuthorizationException Le siège n'est pas l'hôte du salon.
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function store(Request $request, Room $room, AdvanceToNextRound $advance): Response|JsonResponse
    {
        $seat = EnsureActiveSeat::seat($request)
            ?? throw new LogicException('La manche suivante exige le middleware seat.active (spec 60 § 10.1).');

        Gate::authorize('advanceRound', [$room, $seat]);

        // La partie en cours du salon, mise en mémoire par `seat.active`.
        $game = EnsureActiveSeat::game($request);

        if ($game === null || $game->mode !== GameMode::Multiplayer || $game->room_id !== $room->id) {
            return self::notRevealing();
        }

        $gate = Gate::forUser($request->user());
        $outcome = $advance->handle(
            $game,
            Date::now()->toImmutable(),
            static fn (Room $lockedRoom): bool => $gate->allows('advanceRound', [$lockedRoom, $seat]),
        );

        return match ($outcome) {
            NextRoundOutcome::Advanced, NextRoundOutcome::Unchanged => response()->noContent(),
            NextRoundOutcome::NotRevealing => self::notRevealing(),
            NextRoundOutcome::Forbidden => abort(HttpStatus::HTTP_FORBIDDEN),
        };
    }

    private static function notRevealing(): JsonResponse
    {
        return response()->json(['code' => self::NOT_REVEALING], HttpStatus::HTTP_CONFLICT);
    }
}
