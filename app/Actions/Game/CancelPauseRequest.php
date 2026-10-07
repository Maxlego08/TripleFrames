<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Events\Game\GamePauseRequestCancelled;
use App\Models\Game;
use App\Models\Room;
use App\Support\Game\GameJournal;
use App\Support\Game\PauseGestureOutcome;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le geste « Annuler la pause » — D64 du 07/10, spec 60 § 14.1 bis (action
 * interne, nom libre) : l'auteur d'une demande de pause (l'hôte, ou le
 * joueur solo) la retire **avant sa prise d'effet**.
 *
 * {@see CatchUpGame} d'abord : une fin de révélation échue rend la demande
 * effective avant que le retrait ne soit lu — la partie est alors en pause et
 * le retrait ne fait rien (`Unchanged`). Puis verrous `room` (multijoueur,
 * autorité relue) → `game`. Sans demande en attente : `Unchanged`
 * (idempotent). Sinon `pause_requested_at = NULL`, au journal, et
 * `game.pause_request_cancelled` en multijoueur.
 */
final readonly class CancelPauseRequest
{
    public function __construct(private CatchUpGame $catchUp) {}

    /**
     * @param  CarbonImmutable  $now  Instant du geste.
     * @param  (Closure(Room): bool)|null  $authorize  Multijoueur : l'autorité, évaluée sur le salon
     *                                                 relu sous son verrou. Ignorée en solo.
     *
     * @throws LogicException Geste multijoueur sans autorité à évaluer.
     */
    public function handle(Game $game, CarbonImmutable $now, ?Closure $authorize = null): PauseGestureOutcome
    {
        if ($game->mode === GameMode::Multiplayer && ! $authorize instanceof Closure) {
            throw new LogicException('CancelPauseRequest : en multijoueur, le geste est réservé à l’hôte ; son autorité doit être évaluée sous le verrou du salon.');
        }

        $now = $now->startOfMillisecond();

        $this->catchUp->handle($game, $now);

        return DB::transaction(static function () use ($game, $now, $authorize): PauseGestureOutcome {
            if ($game->mode === GameMode::Multiplayer && $authorize instanceof Closure) {
                $lockedRoom = Room::query()->whereKey($game->room_id)->lockForUpdate()->firstOrFail();

                if (! $authorize($lockedRoom)) {
                    return PauseGestureOutcome::Forbidden;
                }
            }

            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();

            if ($lockedGame->ended_at !== null || $lockedGame->pause_requested_at === null) {
                return PauseGestureOutcome::Unchanged;
            }

            $lockedGame->forceFill(['pause_requested_at' => null])->save();
            $game->forceFill(['pause_requested_at' => null])->syncOriginalAttributes(['pause_requested_at']);

            GameJournal::pauseRequestCancelled($lockedGame, $now);

            // Garde de mode (§ 11.2) : aucune diffusion en solo.
            if ($lockedGame->mode === GameMode::Multiplayer) {
                GamePauseRequestCancelled::dispatch(
                    $lockedGame->room ?? throw new LogicException('CancelPauseRequest : partie multijoueur sans salon.'),
                    $lockedGame,
                    [],
                );
            }

            return PauseGestureOutcome::Cancelled;
        });
    }
}
