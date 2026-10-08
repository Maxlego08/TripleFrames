<?php

namespace App\Jobs\Game;

use App\Actions\Game\FinalizeGame;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Settings\EngineConstants;
use App\Support\Game\GameJournal;
use App\Support\Game\PauseDeadline;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * La clôture d'une partie en pause — spec 60 § 14.3, contrat C7 § 2.5 (job
 * interne, nom donné par le contrat).
 *
 * Construit sur la partie, l'instant `paused_at` qui l'a armé et l'échéance
 * de cette pause ({@see PauseDeadline} : `paused_at + pauseTimeoutMs` pour
 * une pause `empty` — l'échéance « 15 min sans joueur connecté » de 00
 * § Déroulé d'une partie, « Salon vide » —, moins le budget manuel déjà
 * consommé pour une pause `manual`, D64 du 07/10), dispatché par `PauseGame`
 * après commit, sur la file `game`, pour cette échéance. À l'exécution, sous
 * le verrou `game` :
 *
 * - la partie n'est plus en pause, ou `paused_at` a changé (reprise, puis
 *   nouvelle pause armée par son propre job), ou son échéance n'est plus
 *   celle du job : **rien** ;
 * - sinon `FinalizeGame::handle($game, GameStatus::Interrupted, échéance)`
 *   (contrat C13 § 4.5) — **l'instant prévu, jamais l'heure
 *   d'exécution** : l'issue et la date de fin d'une partie ne dépendent
 *   jamais du retard du job en tête de file (§ 4.2, règle 1). Un battement
 *   tardif qui trouve l'échéance dépassée gèle au même instant (§ 14.2) ;
 *   `FinalizeGame` est idempotent, le premier gagne.
 *
 * **Délai en instant** (§ 4.2, point 2) : `delay()` lit un entier en secondes
 * et n'admet pas une chaîne ; `availableAt()` tronque l'instant à la seconde,
 * si bien que le job peut se réveiller jusqu'à 999 ms trop tôt. Il **attend
 * alors en processus**, au plus `EngineConstants::transitionMaxWaitMs()`
 * (garde « couvre la troncature à la seconde »), et **ne se relâche jamais**
 * (`--tries=1`). Réveillé plus tôt encore — une file synchrone, ou des
 * horloges désaccordées, jamais attendu en production —, il n'interrompt rien
 * avant l'échéance et ne se redispatche pas (sur une file synchrone, un job
 * neuf s'exécuterait aussitôt, sans fin) : la clôture reste due, et la
 * constatent le battement tardif (`ResumeGame`, qui gèle à la même échéance,
 * § 14.2), le rattrapage (`CatchUpGame`, § 4.4, point 1) et `game:reschedule`,
 * qui rearme ce job pour toute partie en pause (§ 17.5).
 */
final class InterruptPausedGame implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Un seul essai : un job du moteur ne se rejoue jamais (`--tries=1`). */
    public int $tries = 1;

    /**
     * @param  int  $gameId  Partie en pause.
     * @param  string  $pausedAt  `paused_at` qui a armé ce job, en `IsoMs` ({@see WireTime}).
     * @param  string|null  $deadline  Échéance de la pause ({@see PauseDeadline}), en `IsoMs` ;
     *                                 NULL = `paused_at + pauseTimeoutMs` (pause `empty`).
     *
     * @throws InvalidArgumentException Un instant hors du format `IsoMs`.
     */
    public function __construct(
        public readonly int $gameId,
        public readonly string $pausedAt,
        public readonly ?string $deadline = null,
    ) {
        foreach ([$pausedAt, $deadline] as $instant) {
            if ($instant !== null && preg_match(WireTime::PATTERN, $instant) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'InterruptPausedGame : un instant de pause doit être un IsoMs, reçu [%s].',
                    $instant,
                ));
            }
        }

        $this->onQueue('game');
        $this->delay($this->interruptsAt());
    }

    /** L'échéance de la pause qui a armé ce job. */
    public function interruptsAt(): CarbonImmutable
    {
        return $this->deadline !== null
            ? CarbonImmutable::parse($this->deadline)
            : CarbonImmutable::parse($this->pausedAt)->addMilliseconds(EngineConstants::pauseTimeoutMs());
    }

    public function handle(FinalizeGame $finalize): void
    {
        $interruptsAt = $this->interruptsAt();
        $remainingMs = self::remainingMs($interruptsAt);

        if ($remainingMs > EngineConstants::transitionMaxWaitMs()) {
            return;
        }

        if ($remainingMs > 0) {
            Sleep::for($remainingMs)->milliseconds();
        }

        DB::transaction(function () use ($finalize, $interruptsAt): void {
            $lockedGame = Game::query()->whereKey($this->gameId)->lockForUpdate()->first();

            if (! $lockedGame instanceof Game
                || $lockedGame->ended_at !== null
                || $lockedGame->status !== GameStatus::Paused
                || $lockedGame->paused_at === null
                || WireTime::iso($lockedGame->paused_at) !== $this->pausedAt
                || PauseDeadline::of($lockedGame)?->equalTo($interruptsAt) !== true) {
                return;
            }

            if ($finalize->handle($lockedGame, GameStatus::Interrupted, $interruptsAt)) {
                GameJournal::gameFinalized($lockedGame, GameStatus::Interrupted, $interruptsAt);
            }
        });
    }

    /**
     * Millisecondes entières jusqu'à l'échéance, arrondies à la milliseconde
     * supérieure — une échéance à venir n'est jamais tenue pour atteinte —,
     * nulles ou négatives si elle est passée.
     */
    private static function remainingMs(CarbonImmutable $interruptsAt): int
    {
        $remainingUs = (int) $interruptsAt->format('Uu') - (int) Date::now()->format('Uu');

        return $remainingUs > 0 ? intdiv($remainingUs + 999, 1000) : intdiv($remainingUs, 1000);
    }
}
