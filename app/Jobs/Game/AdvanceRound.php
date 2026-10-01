<?php

namespace App\Jobs\Game;

use App\Actions\Game\CatchUpGame;
use App\Models\Game;
use App\Settings\EngineConstants;
use App\Support\Game\GameJournal;
use App\Support\Game\RoundStep;
use App\Support\Perf\GameTraceWriter;
use App\Support\Perf\PerfRecorder;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Throwable;

/**
 * **Le** job de frontière — spec 60 § 4.2, contrat C7 § 2.5 (règle 8 : un job
 * par frontière de palier, ni ordonnanceur à la minute, ni minuteur client).
 *
 * **Qui le dispatche** : la transition qui fixe l'instant de l'étape suivante,
 * après commit. `ScheduleRound` programme `OpenTier(1)` (lot L60-5) ;
 * `OpenTier(i)` programme `OpenTier(i+1)` ou `Close` ; `CloseRound` programme
 * `Reveal` ; `RevealRound` et `AdvanceToNextRound` programment `EndReveal`
 * (L60-6, L60-7). Chaque partie en cours a ainsi toujours au moins un job en
 * file (contrat C17 § 4.2).
 *
 * **Après commit, toujours** (`ShouldQueueAfterCommit`) : une transaction
 * annulée ne programme rien, et un job ne se réveille jamais sur une écriture
 * qui n'a pas eu lieu.
 *
 * **Délai en instant, jamais en entier ni en chaîne** : `delay()` lit un
 * entier en SECONDES et n'admet pas une chaîne (`InteractsWithTime::
 * availableAt()`, vérifié) ; le délai est donc posé ici, une fois pour
 * toutes, sur l'instant `CarbonImmutable::parse($dueAt)`. `availableAt()`
 * tronque à la seconde : le job peut se réveiller jusqu'à 999 ms trop tôt,
 * jamais plus — d'où l'attente en processus bornée par
 * `EngineConstants::transitionMaxWaitMs()` (L60-7).
 *
 * **File `game`, un seul essai, jamais relâché** : sous `--tries=1`, un job
 * relâché échoue sans s'exécuter (vérifié, A-29, A-54). Un doublon, un rejeu
 * ou un job périmé exécutent le même rattrapage, qui ne fait rien s'il n'y a
 * rien d'échu : `$step` et `$tierIndex` ne servent qu'au délai et à la
 * journalisation, jamais à une décision (§ 4.2, point 4).
 *
 * **Le travail** (§ 4.2, points 3 à 5 ; § 4.6 — lot L60-7) :
 *
 * 1. **attente en processus** : réveillé tôt, le job attend
 *    `Sleep::for(min(restant, transitionMaxWaitMs))->milliseconds()` —
 *    jamais `Sleep::until()`, qui n'a aucun plafond —, `restant` =
 *    `dueAt − now` en millisecondes entières, arrondi à la milliseconde
 *    supérieure : une échéance à venir n'est jamais tenue pour atteinte. Il
 *    **ne se relâche jamais**. Cette attente immobilise le processus `game` :
 *    c'est le risque de tête de file que mesure le test de charge D33 du
 *    23/09 ;
 * 2. **travail** : `CatchUpGame::handle($game, Date::now())` — le job ne
 *    décide rien ; un doublon, un rejeu ou un job périmé exécutent le même
 *    rattrapage, qui ne fait rien s'il n'y a rien d'échu. Une partie
 *    disparue (purgée) : rien ;
 * 3. **filet de dérive d'horloge** : si l'étape ne peut pas être échue après
 *    l'attente maximale (horloges désaccordées, jamais attendu), le job
 *    dispatche un job **neuf** pour le même `$dueAt` et se termine — ce n'est
 *    pas un relâchement. L'attente, qui ne la rendrait pas échue, est alors
 *    épargnée au processus `game`. Sur une file synchrone, qui ignore tout
 *    délai, le job neuf s'exécuterait aussitôt, sans fin : il n'est pas
 *    dispatché, et l'étape reste due pour les filets suivants (rattrapage
 *    d'une requête, `game:reschedule`) ;
 * 4. **échec** ({@see self::failed()}) : une exception dans une transition
 *    annule sa transaction, rien n'est émis, le job échoue ; un job neuf est
 *    dispatché pour le même instant, retardé de `transitionMaxWaitMs`, marqué
 *    `$retried` ; un second échec n'est pas redispatché et part au journal
 *    `game`.
 */
final class AdvanceRound implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Un seul essai : un job de frontière ne se rejoue jamais (`--tries=1`). */
    public int $tries = 1;

    /**
     * @param  int  $gameId  Partie dont le rattrapage est réveillé.
     * @param  int  $roundId  Manche de l'étape — journalisation seulement.
     * @param  RoundStep  $step  Étape attendue — délai et journalisation seulement.
     * @param  int|null  $tierIndex  Palier de `OpenTier`, nul pour toute autre étape.
     * @param  string  $dueAt  Instant théorique de l'étape, en `IsoMs` ({@see WireTime}).
     * @param  bool  $retried  Vrai pour le job neuf qu'un premier échec redispatche (§ 4.6).
     *
     * @throws InvalidArgumentException `$dueAt` hors du format `IsoMs`, ou palier
     *                                  absent d'une ouverture (présent ailleurs).
     */
    public function __construct(
        public readonly int $gameId,
        public readonly int $roundId,
        public readonly RoundStep $step,
        public readonly ?int $tierIndex,
        public readonly string $dueAt,
        public readonly bool $retried = false,
    ) {
        if (preg_match(WireTime::PATTERN, $dueAt) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'AdvanceRound : l’instant de l’étape doit être un IsoMs, reçu [%s].',
                $dueAt,
            ));
        }

        if ($step->targetsTier() !== ($tierIndex !== null)) {
            throw new InvalidArgumentException(sprintf(
                'AdvanceRound : l’étape %s %s un palier.',
                $step->value,
                $step->targetsTier() ? 'exige' : 'ne porte pas',
            ));
        }

        $this->onQueue('game');
        $this->delay($this->dueAtInstant());
    }

    /** L'instant théorique de l'étape, relu depuis son `IsoMs`. */
    public function dueAtInstant(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->dueAt);
    }

    /**
     * Réveil du moteur : attente en processus bornée, puis rattrapage de la
     * partie — ou, si l'étape ne peut pas être échue dans l'attente permise,
     * un job neuf pour le même instant.
     */
    public function handle(CatchUpGame $catchUp): void
    {
        $remainingMs = $this->remainingMs();
        $maxWaitMs = EngineConstants::transitionMaxWaitMs();

        if ($remainingMs > $maxWaitMs) {
            $this->redispatchForDrift();

            return;
        }

        if ($remainingMs > 0) {
            Sleep::for($remainingMs)->milliseconds();
        }

        if ($this->remainingMs() > 0) {
            $this->redispatchForDrift();

            return;
        }

        $game = Game::query()->find($this->gameId);

        if (! $game instanceof Game) {
            return;
        }

        $startedAt = Date::now()->toImmutable();
        $recorder = app(PerfRecorder::class);
        $before = $recorder->snapshot();
        $startedNs = PerfRecorder::nowNs();

        $catchUp->handle($game, $startedAt);

        // La chronologie technique (D47 du 01/10) : retard au démarrage du
        // travail sur l'instant théorique, durée et requêtes du rattrapage.
        $after = $recorder->snapshot();

        GameTraceWriter::record(
            gameId: $game->id,
            event: GameTraceWriter::JOB_PREFIX.$this->step->value,
            tierIndex: $this->tierIndex,
            theoreticalAt: $this->dueAtInstant(),
            recordedAt: $startedAt,
            durationMs: PerfRecorder::elapsedMs($startedNs),
            queryCount: $before !== null && $after !== null ? $after['query_count'] - $before['query_count'] : null,
            details: ['round' => $this->roundId, 'retried' => $this->retried],
        );
    }

    /**
     * Premier échec : un job neuf pour le même instant, retardé de
     * `transitionMaxWaitMs`, marqué `$retried`. Second échec : ni
     * redispatch, ni relâchement — le journal `game`, et les filets de § 4.6.
     */
    public function failed(?Throwable $exception = null): void
    {
        if ($this->retried) {
            GameJournal::transitionFailedTwice($this->gameId, $this->roundId, $this->step, $this->tierIndex, $this->dueAt, $exception);

            return;
        }

        self::dispatch($this->gameId, $this->roundId, $this->step, $this->tierIndex, $this->dueAt, true)
            ->delay(Date::now()->toImmutable()->addMilliseconds(EngineConstants::transitionMaxWaitMs()));
    }

    /**
     * Millisecondes entières jusqu'à l'échéance, arrondies à la milliseconde
     * supérieure, nulles ou négatives si elle est atteinte.
     */
    private function remainingMs(): int
    {
        $remainingUs = (int) $this->dueAtInstant()->format('Uu') - (int) Date::now()->format('Uu');

        return $remainingUs > 0 ? intdiv($remainingUs + 999, 1000) : intdiv($remainingUs, 1000);
    }

    /**
     * Le filet de dérive : un job neuf, même étape, même instant — sauf sur
     * une file synchrone, qui l'exécuterait aussitôt, sans fin.
     */
    private function redispatchForDrift(): void
    {
        if ($this->job instanceof SyncJob) {
            return;
        }

        self::dispatch($this->gameId, $this->roundId, $this->step, $this->tierIndex, $this->dueAt, $this->retried);
    }
}
