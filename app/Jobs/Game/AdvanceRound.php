<?php

namespace App\Jobs\Game;

use App\Support\Game\RoundStep;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

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
 * **Lot L60-5** : classe, constructeur, file et délai. Le travail —
 * `CatchUpGame::handle($game, Date::now())` précédé de l'attente en processus,
 * le filet de dérive d'horloge et `failed()` (§ 4.2, points 3 à 5 ; § 4.6) —
 * est livré par L60-7 avec le rattrapage : jusque-là, {@see self::handle()}
 * ne fait rien, ce qui est le comportement exact d'un job réveillé sur une
 * étape non échue.
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
     * Réveil du moteur — complété par L60-7 (rattrapage `CatchUpGame`, attente
     * en processus, filet de dérive). Ne rien faire est, d'ici là, le
     * comportement exact d'un réveil sur une étape non échue.
     */
    public function handle(): void {}
}
