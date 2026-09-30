<?php

namespace App\Http\Controllers\Game;

use App\Actions\Game\AdvanceToNextRound;
use App\Actions\Game\CatchUpGame;
use App\Actions\Game\RecordHeartbeat;
use App\Actions\Game\RevealSoloAnswer;
use App\Actions\Game\SkipSoloRound;
use App\Enums\GameMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Game\Concerns\RespondsWithSoloState;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Game;
use App\Models\Player;
use App\Support\Game\NextRoundOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les trois gestes du joueur solo (spec 60 § 5.4, § 10.1 et § 16.5 ; D18 du
 * 23/09 ; contrat C7 § 2.4 et § 4.12) :
 *
 * - `solo.reveal`, `POST /solo/round/reveal` — « Voir la réponse »
 *   ({@see RevealSoloAnswer}) : la manche se clôt, la révélation normale de
 *   durée `R` suit, à 0 point ;
 * - `solo.skip`, `POST /solo/round/skip` — « Passer la manche »
 *   ({@see SkipSoloRound}) : la manche passe `completed` sans révélation, la
 *   suivante est programmée, ou la partie gelée ;
 * - `solo.next`, `POST /solo/round/next` — « Manche suivante »
 *   ({@see AdvanceToNextRound}, sans autorité à relire : le joueur solo est
 *   seul) : pendant la révélation, elle raccourcit `R`, jamais `D`.
 *
 * Pile : `seat.active` résout le **siège solo** du jeton (aucun `{room}`
 * dans le chemin) et sa partie solo en cours — 403 sans siège solo, 409
 * `seat_superseded` pour un onglet supplanté —, puis `throttle:game-write`.
 * Les routes ne résolvent donc que la partie solo du siège : un siège de
 * salon n'y atteint jamais sa partie multijoueur (barrière 1 de 10 § 7.10 ;
 * les actions lèvent de plus hors solo).
 *
 * **Chaque geste vaut battement** (§ 16.5), refusé ou non, dès qu'une partie
 * solo est en cours (sans partie, le geste est refusé sans rien écrire) : il
 * prouve la présence du siège, et un siège passé `disconnected` qui touche un
 * geste redevient participant avant que la phase ne soit lue. `solo.next` bat
 * avant {@see AdvanceToNextRound}, partagée avec l'hôte et qui ne bat pas :
 * sans cela, la fin de révélation raccourcie trouverait un siège
 * `disconnected` et mettrait la partie en pause.
 *
 * Réponses, JSON à destinataire unique :
 * - **200** : le `GameStatePacket` à jour ({@see RespondsWithSoloState}) ;
 *   « Manche suivante » dont la révélation finit déjà avant `now +
 *   preload_lead_ms + nextRoundMarginMs` rend aussi le paquet, inchangé
 *   (§ 5.4, étape 4) ;
 * - **409** `{ "code": "round_not_running" }` hors précondition de « Voir la
 *   réponse » et « Passer la manche » (manche qui ne court pas, saisie du
 *   siège close, aucune partie en cours), rendu par
 *   `game.errors.round_not_running` ;
 * - **409** `{ "code": "not_revealing" }` hors révélation pour « Manche
 *   suivante », rendu par `game.errors.not_revealing` (§ 5.4).
 *
 * Aucune diffusion (C7 § 4.12) ; aucune ligne `guess` (10 § 7.10).
 */
final class SoloRoundController extends Controller
{
    use RespondsWithSoloState;

    /** Code du 409 des deux gestes d'entraînement hors précondition (§ 16.5). */
    public const string ROUND_NOT_RUNNING = 'round_not_running';

    /** Clé qui le rend côté client (§ 19.4). */
    public const string KEY_ROUND_NOT_RUNNING = 'game.errors.round_not_running';

    /**
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function reveal(Request $request, RevealSoloAnswer $reveal, CatchUpGame $catchUp): JsonResponse
    {
        [$seat, $game, $now] = self::gesture($request);

        if ($game === null || ! $reveal->handle($game, $seat, $now)) {
            return self::conflict(self::ROUND_NOT_RUNNING);
        }

        return $this->soloState($request, $seat->refresh(), $catchUp, journal: false);
    }

    /**
     * @throws LogicException La route ne porte pas `seat.active`.
     */
    public function skip(Request $request, SkipSoloRound $skip, CatchUpGame $catchUp): JsonResponse
    {
        [$seat, $game, $now] = self::gesture($request);

        if ($game === null || ! $skip->handle($game, $seat, $now)) {
            return self::conflict(self::ROUND_NOT_RUNNING);
        }

        return $this->soloState($request, $seat->refresh(), $catchUp, journal: false);
    }

    /**
     * @throws LogicException La route ne porte pas `seat.active`, ou le geste
     *                        solo rend une issue d'autorité, qui n'existe
     *                        qu'en multijoueur.
     */
    public function next(Request $request, AdvanceToNextRound $advance, RecordHeartbeat $heartbeat, CatchUpGame $catchUp): JsonResponse
    {
        [$seat, $game, $now] = self::gesture($request);

        if ($game === null) {
            return self::conflict(NextRoundController::NOT_REVEALING);
        }

        // Le geste vaut battement, avant la manche suivante (§ 16.5).
        $heartbeat->handle($seat, null, $now);

        return match ($advance->handle($game, $now)) {
            NextRoundOutcome::Advanced, NextRoundOutcome::Unchanged => $this->soloState($request, $seat->refresh(), $catchUp, journal: false),
            NextRoundOutcome::NotRevealing => self::conflict(NextRoundController::NOT_REVEALING),
            NextRoundOutcome::Forbidden => throw new LogicException('AdvanceToNextRound : aucune autorité à relire en solo (spec 60 § 5.4).'),
        };
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

    private static function conflict(string $code): JsonResponse
    {
        return response()->json(['code' => $code], Response::HTTP_CONFLICT);
    }
}
