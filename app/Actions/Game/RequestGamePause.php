<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GamePauseKind;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Events\Game\GamePauseRequested;
use App\Models\Game;
use App\Models\Room;
use App\Models\Round;
use App\Support\Deploy\DeployDrain;
use App\Support\Game\GameJournal;
use App\Support\Game\PauseDeadline;
use App\Support\Game\PauseGestureOutcome;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le geste « Pause » — D64 du 07/10, spec 60 § 14.1 bis (action interne,
 * nom libre). Geste de l'hôte en multijoueur (`RoomPolicy::pauseGame`), du
 * joueur en solo.
 *
 * **L'horloge d'une manche ne se met jamais en pause** (10 § 7.4) : la pause
 * n'arrive qu'entre deux manches.
 *
 * 0. {@see CatchUpGame} d'abord, avant de lire la phase (§ 4.4) ;
 * 1. verrous `room` (multijoueur, autorité relue sur le salon relu) →
 *    `game` (ordre du § 4.5 ; {@see PauseGame} prend ensuite les manches) ;
 * 2. refus : partie close (`NotRunning`) ; déjà en pause ou déjà demandée
 *    (`Unchanged`, idempotent) ; drainage en cours (`Draining`) ; budget
 *    manuel épuisé (`BudgetExhausted`, {@see PauseDeadline}) ; aucune manche
 *    à jouer après la manche en cours (`NoRoundLeft`) ;
 * 3. **une manche est jouée** — en cours, en révélation, ou programmée et
 *    `T₁` franchi (60 § 1.2) — : la pause est **demandée**
 *    (`pause_requested_at = now`, `game.pause_requested`), et
 *    {@see EndReveal} la rend effective à la fin de sa révélation ;
 * 4. sinon (décompte de lancement ou de reprise, `T₁` non atteint) : pause
 *    **immédiate**, {@see PauseGame} `manual` à l'instant du geste, qui
 *    déprogramme la manche.
 */
final readonly class RequestGamePause
{
    /** @var list<string> */
    private const array PAUSE_COLUMNS = ['status', 'paused_at', 'pause_kind', 'pause_requested_at', 'updated_at'];

    public function __construct(
        private CatchUpGame $catchUp,
        private PauseGame $pause,
        private DeployDrain $drain,
    ) {}

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
            throw new LogicException('RequestGamePause : en multijoueur, le geste est réservé à l’hôte ; son autorité doit être évaluée sous le verrou du salon.');
        }

        $now = $now->startOfMillisecond();

        $this->catchUp->handle($game, $now);

        return DB::transaction(function () use ($game, $now, $authorize): PauseGestureOutcome {
            if ($game->mode === GameMode::Multiplayer && $authorize instanceof Closure) {
                $lockedRoom = Room::query()->whereKey($game->room_id)->lockForUpdate()->firstOrFail();

                if (! $authorize($lockedRoom)) {
                    return PauseGestureOutcome::Forbidden;
                }
            }

            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();

            if ($lockedGame->ended_at !== null) {
                return PauseGestureOutcome::NotRunning;
            }

            if ($lockedGame->status === GameStatus::Paused || $lockedGame->pause_requested_at !== null) {
                return PauseGestureOutcome::Unchanged;
            }

            if ($lockedGame->status !== GameStatus::Running) {
                return PauseGestureOutcome::NotRunning;
            }

            if ($this->drain->isDraining()) {
                return PauseGestureOutcome::Draining;
            }

            if (! PauseDeadline::manualBudgetAllowsPause($lockedGame)) {
                return PauseGestureOutcome::BudgetExhausted;
            }

            $played = self::playedRound($lockedGame, $now);
            $next = Round::query()->where('game_id', $lockedGame->id)->toPlay();

            if ($played instanceof Round) {
                $next->whereKeyNot($played->id);
            }

            if (! $next->exists()) {
                return PauseGestureOutcome::NoRoundLeft;
            }

            if ($played instanceof Round) {
                $lockedGame->forceFill(['pause_requested_at' => $now])->save();
                self::reflect($game, $lockedGame);

                GameJournal::pauseRequested($lockedGame, $now);

                // Garde de mode (§ 11.2) : aucune diffusion en solo.
                if ($lockedGame->mode === GameMode::Multiplayer) {
                    GamePauseRequested::dispatch(self::room($lockedGame), $lockedGame, [
                        'requestedAt' => WireTime::iso($now),
                    ]);
                }

                return PauseGestureOutcome::Requested;
            }

            $this->pause->handle($lockedGame, $now, GamePauseKind::Manual);
            self::reflect($game, $lockedGame);

            return PauseGestureOutcome::Paused;
        });
    }

    /**
     * La manche **jouée** à `$now` (60 § 1.2) : en cours, en révélation, ou
     * programmée et `T₁` franchi alors que son ouverture tarde. NULL entre
     * deux manches (décompte, `T₁` à venir).
     */
    private static function playedRound(Game $lockedGame, CarbonImmutable $now): ?Round
    {
        // Comparé en PHP, à la milliseconde : un instant lié en SQL perdrait
        // ses millisecondes (format de date de la grammaire).
        return Round::query()
            ->where('game_id', $lockedGame->id)
            ->where(static function (Builder $query): void {
                $query->whereIn('status', [RoundStatus::Running->value, RoundStatus::Revealing->value])
                    ->orWhere(static function (Builder $pending): void {
                        $pending->where('status', RoundStatus::Pending->value)->whereNotNull('started_at');
                    });
            })
            ->orderBy('sequence_index')
            ->get()
            ->first(static fn (Round $round): bool => $round->status !== RoundStatus::Pending
                || ($round->started_at !== null && $round->started_at->lessThanOrEqualTo($now)));
    }

    /** Recopie sur l'instance de l'appelant ce que le geste a écrit. */
    private static function reflect(Game $game, Game $lockedGame): void
    {
        $game->forceFill($lockedGame->only(self::PAUSE_COLUMNS))->syncOriginalAttributes(self::PAUSE_COLUMNS);
    }

    /**
     * @throws LogicException
     */
    private static function room(Game $game): Room
    {
        return $game->room ?? throw new LogicException('RequestGamePause : partie multijoueur sans salon.');
    }
}
