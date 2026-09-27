<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Events\Game\GameResumed;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Room;
use App\Models\Round;
use App\Settings\EngineConstants;
use App\Support\Game\GameJournal;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La reprise d'une partie en pause — spec 60 § 14.2, contrat C7 § 4.11
 * (action interne, nom donné par le contrat).
 *
 * **Appelant** : le battement qui ramène un siège de la partie à `connected`
 * (§ 13.1, {@see RecordHeartbeat}), dans sa transaction, après les verrous
 * `room` (s'il le tient) puis `player` : la reprise prend `game`, puis la
 * manche à reprogrammer (ordre global du § 4.5).
 *
 * **Sous le verrou `game`, l'échéance est vérifiée d'abord** : si `now ≥
 * paused_at + pauseTimeoutMs`, la reprise ne reprend rien et gèle la partie
 * par `FinalizeGame::handle($game, GameStatus::Interrupted, paused_at +
 * pauseTimeoutMs)` (contrat C13 § 4.5) — l'instant PRÉVU, celui
 * qu'écrit aussi {@see InterruptPausedGame} : l'issue d'une partie ne dépend
 * jamais du retard de ce job en tête de file (§ 4.2, règle 1), et le gel est
 * idempotent, le premier gagne.
 *
 * **Dans le délai** :
 *
 * 1. `game.status = running`, `total_paused_ms += now − paused_at` (en
 *    millisecondes entières, sur les microsecondes), `paused_at = NULL` ;
 *    en multijoueur, `game.resumed` `{ resumedAt }` ; la reprise au journal
 *    `game` (§ 4.7) ;
 * 2. `ScheduleRound(k+1, now + launchCountdownMs)` : la manche déprogrammée
 *    par la pause reçoit une origine neuve, après le décompte de reprise ; le
 *    jeton du palier 1 déjà frappé est réutilisé (la frappe est idempotente)
 *    — et `round.scheduled` en multijoueur.
 *
 * **Ordre sur le fil** (E90-7) : l'étape 1 et son émission vivent dans une
 * transaction IMBRIQUÉE validée avant `ScheduleRound` — les rappels après
 * commit d'une transaction imbriquée partent avant ceux de ses aînées
 * validées plus tard —, si bien que `game.resumed` précède toujours le
 * `round.scheduled` qu'il annonce (§ 3.1, § 11.3).
 *
 * **Le drapeau de drainage ne bloque jamais une reprise** (contrat C17
 * § 4.4, § 17.3 point 4) : il n'est pas lu ici. Une partie qui n'est pas en
 * pause (reprise concurrente déjà faite, partie close) n'est pas touchée. Une
 * partie en pause sans manche restante à jouer — impossible par construction
 * ({@see PauseGame} n'intervient que si une manche reste) — n'est pas reprise :
 * elle reste en pause, et son interruption la clôt à l'échéance.
 */
final readonly class ResumeGame
{
    /**
     * Colonnes de `game` que la reprise écrit, recopiées sur l'instance de
     * l'appelant.
     *
     * @var list<string>
     */
    private const array RESUMED_COLUMNS = ['status', 'paused_at', 'total_paused_ms', 'updated_at'];

    public function __construct(
        private ScheduleRound $schedule,
        private FinalizeGame $finalize,
    ) {}

    /**
     * @param  CarbonImmutable  $now  Instant du battement qui ramène le siège, à la milliseconde.
     * @return bool `true` si CET appel a repris la partie.
     */
    public function handle(Game $game, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($game, $now): bool {
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->first();

            if (! $lockedGame instanceof Game
                || $lockedGame->ended_at !== null
                || $lockedGame->status !== GameStatus::Paused
                || $lockedGame->paused_at === null) {
                return false;
            }

            $pausedAt = $lockedGame->paused_at;
            $interruptsAt = $pausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs());

            // L'échéance d'abord : un battement tardif ne reprend jamais une
            // partie que sa pause a close, il la gèle à l'instant prévu.
            if ($now->greaterThanOrEqualTo($interruptsAt)) {
                if ($this->finalize->handle($lockedGame, GameStatus::Interrupted, $interruptsAt)) {
                    GameJournal::gameFinalized($lockedGame, GameStatus::Interrupted, $interruptsAt);
                }

                return false;
            }

            $next = Round::query()->where('game_id', $lockedGame->id)->toPlay()->first();

            if (! $next instanceof Round) {
                return false;
            }

            self::markResumed($lockedGame, $pausedAt, $now);

            $game->forceFill($lockedGame->only(self::RESUMED_COLUMNS))
                ->syncOriginalAttributes(self::RESUMED_COLUMNS);

            $this->schedule->handle(
                Round::query()->whereKey($next->id)->lockForUpdate()->firstOrFail(),
                $now->addMilliseconds(EngineConstants::launchCountdownMs()),
            );

            return true;
        });
    }

    /**
     * Étape 1 et son émission, dans une transaction IMBRIQUÉE validée avant
     * la programmation (E90-7) : `game.resumed` part avant `round.scheduled`.
     */
    private static function markResumed(Game $lockedGame, CarbonImmutable $pausedAt, CarbonImmutable $now): void
    {
        DB::transaction(static function () use ($lockedGame, $pausedAt, $now): void {
            $lockedGame->forceFill([
                'status' => GameStatus::Running,
                'paused_at' => null,
                'total_paused_ms' => $lockedGame->total_paused_ms + self::elapsedMs($pausedAt, $now),
            ])->save();

            GameJournal::gameResumed($lockedGame, $now);

            // Garde de mode (§ 11.2) : aucune diffusion en solo.
            if ($lockedGame->mode === GameMode::Multiplayer) {
                GameResumed::dispatch(self::room($lockedGame), $lockedGame, [
                    'resumedAt' => WireTime::iso($now),
                ]);
            }
        });
    }

    /**
     * Millisecondes entières de `$from` à `$to`, sur les microsecondes — la
     * même arithmétique que `RoundClock::offsetMs()` (§ 2.1). Jamais
     * négative : une pause ne se reprend pas avant d'avoir commencé.
     */
    private static function elapsedMs(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return max(0, intdiv((int) $to->format('Uu') - (int) $from->format('Uu'), 1000));
    }

    /**
     * @throws LogicException
     */
    private static function room(Game $game): Room
    {
        return $game->room ?? throw new LogicException('ResumeGame : partie multijoueur sans salon.');
    }
}
