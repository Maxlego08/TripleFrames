<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Support\Game\PauseGestureOutcome;
use App\Support\Game\ResumeAuthor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le geste « Reprendre » — D64 du 07/10, spec 60 § 14.2 (action interne, nom
 * libre). En multijoueur : l'hôte ; si l'hôte n'est pas un siège présent
 * (§ 1.2), tout siège présent de la partie (`RoomPolicy::resumeGame`). En
 * solo : le joueur. **Le drapeau de drainage ne bloque jamais une reprise**
 * (§ 17.3, point 4).
 *
 * {@see CatchUpGame} d'abord : une pause échue y est close à son échéance.
 * Puis verrous `room` (multijoueur, autorité relue sur le salon relu) →
 * `game`, puis {@see ResumeGame} avec son auteur (`host`, `seat` ou `solo`),
 * qui vérifie l'échéance, écrit la reprise et reprogramme la manche
 * suivante après le décompte de lancement. Une partie déjà en cours :
 * `Unchanged` (reprise concurrente) ; close : `NotRunning`.
 */
final readonly class ResumePausedGame
{
    public function __construct(
        private CatchUpGame $catchUp,
        private ResumeGame $resume,
    ) {}

    /**
     * @param  Player  $seat  Le siège qui reprend.
     * @param  CarbonImmutable  $now  Instant du geste.
     * @param  (Closure(Room): bool)|null  $authorize  Multijoueur : l'autorité, évaluée sur le salon
     *                                                 relu sous son verrou. Ignorée en solo.
     *
     * @throws LogicException Geste multijoueur sans autorité à évaluer.
     */
    public function handle(Game $game, Player $seat, CarbonImmutable $now, ?Closure $authorize = null): PauseGestureOutcome
    {
        if ($game->mode === GameMode::Multiplayer && ! $authorize instanceof Closure) {
            throw new LogicException('ResumePausedGame : en multijoueur, l’autorité du geste doit être évaluée sous le verrou du salon.');
        }

        $now = $now->startOfMillisecond();

        $this->catchUp->handle($game, $now);

        return DB::transaction(function () use ($game, $seat, $now, $authorize): PauseGestureOutcome {
            $by = ResumeAuthor::Solo;

            if ($game->mode === GameMode::Multiplayer && $authorize instanceof Closure) {
                $lockedRoom = Room::query()->whereKey($game->room_id)->lockForUpdate()->firstOrFail();

                if (! $authorize($lockedRoom)) {
                    return PauseGestureOutcome::Forbidden;
                }

                $by = $lockedRoom->host_player_id === $seat->id ? ResumeAuthor::Host : ResumeAuthor::Seat;
            }

            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();

            if ($lockedGame->ended_at !== null) {
                return PauseGestureOutcome::NotRunning;
            }

            if ($lockedGame->status !== GameStatus::Paused) {
                return PauseGestureOutcome::Unchanged;
            }

            return $this->resume->handle($lockedGame, $now, $by)
                ? PauseGestureOutcome::Resumed
                : PauseGestureOutcome::NotRunning;
        });
    }
}
