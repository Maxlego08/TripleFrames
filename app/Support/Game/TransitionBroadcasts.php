<?php

namespace App\Support\Game;

use App\Actions\Game\CatchUpGame;
use App\Actions\Game\PauseGame;
use App\Actions\Game\RevealRound;
use App\Actions\Game\ScheduleRound;
use App\Events\Game\GamePaused;
use App\Events\Game\RoomBroadcast;
use App\Events\Game\RoundCancelled;
use App\Events\Game\RoundScheduled;
use App\Events\Game\SeatBroadcast;
use App\Events\Game\SeatChoicesOffered;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Attributes\Scoped;

/**
 * Les diffusions des transitions du moteur, vues de deux côtés — spec 60
 * § 4.4 et § 4.7 (classe interne, nom libre).
 *
 * **1. Diffusion après rattrapage** (§ 4.4). Un passage de
 * {@see CatchUpGame} qui rattrape plusieurs étapes **n'émet que l'état
 * courant** : pendant le passage ({@see self::coalesce()}), chaque diffusion
 * d'une transition validée est **retenue** au lieu de partir — les deux bases
 * d'événements la confient ici par `broadcastWhen()`, APRÈS le commit de sa
 * transaction (`ShouldDispatchAfterCommit`) : une transaction annulée ne
 * confie donc jamais rien. À la fin du passage, les diffusions retenues
 * partent dans leur ordre, **sauf celles qu'une diffusion postérieure de la
 * même manche (même `gameRef`, même `sequenceIndex`) rend périmées** :
 *
 * - une diffusion de frontière (`round.scheduled`, `tier.opened`,
 *   `round.closed`, `round.revealed`) s'efface devant toute diffusion de
 *   frontière postérieure de sa manche, ou devant son `round.cancelled` :
 *   par manche touchée, seul l'événement de la **dernière** étape part, et le
 *   `round.scheduled` d'une manche que le passage a programmée ne part que si
 *   le passage ne l'a pas ouverte ensuite — jamais après son `T₁` ;
 * - un `round.scheduled` s'efface en outre devant tout `game.paused`
 *   postérieur de sa partie : la pause ({@see PauseGame}) déprogramme la
 *   manche annoncée (`started_at` remis à NULL), dont l'annonce ne décrit
 *   plus l'état courant — et partirait après son `T₁` ;
 * - `seat.choices` s'efface de même : il ne part que si le palier du QCM est
 *   encore le palier courant de la manche, `ended_at` nul ;
 * - ne se périment jamais : `round.cancelled`, `game.paused`,
 *   `game.resumed`, `game.ended`, `player.locked`, et tout événement de siège
 *   ou de salon.
 *
 * Un passage qui n'exécute qu'une étape — le cas nominal d'un job à l'heure —
 * émet donc l'événement de cette étape (et, pour la révélation, le
 * `round.scheduled` de la manche suivante). Un client qui apprend ainsi un
 * `sequenceIndex` inconnu, ou un saut d'étape, se resynchronise (§ 12.6).
 *
 * Un passage exécuté DANS une transaction englobante (relance solo, § 16.6)
 * ne retient rien : ses diffusions ne sont confiées qu'au commit de
 * l'englobante, après le passage, et partent alors toutes — le solo n'en émet
 * aucune.
 *
 * **2. Instant théorique d'une programmation** (§ 4.7). Le retard réel de
 * `round.scheduled` se mesure contre l'instant théorique de l'étape qui l'a
 * décidé. {@see ScheduleRound}, dont la signature est figée, le lit ici
 * ({@see self::decidedAt()}) : {@see RevealRound} y pose le début théorique
 * de la révélation (`ended_at + tier_grace_ms`) le temps de programmer la
 * manche suivante ; hors de cette fenêtre, c'est l'instant de la transaction
 * du geste (lancement, « manche suivante », reprise, remplacement).
 *
 * Instance par requête ou par job (`#[Scoped]`) : aucun état ne survit d'un
 * job à l'autre dans un worker.
 */
#[Scoped]
final class TransitionBroadcasts
{
    /** Profondeur des passages de rattrapage imbriqués. */
    private int $depth = 0;

    /** @var list<RoomBroadcast|SeatBroadcast> */
    private array $held = [];

    private ?CarbonImmutable $decidedAt = null;

    /**
     * Exécute un passage de rattrapage en retenant ses diffusions, puis
     * libère celles qui décrivent l'état courant. Un passage imbriqué rejoint
     * le passage englobant. Les diffusions déjà retenues partent même si le
     * passage lève : leurs transitions ont été validées.
     *
     * @template T
     *
     * @param  Closure(): T  $pass
     * @return T
     */
    public function coalesce(Closure $pass): mixed
    {
        $this->depth++;

        try {
            return $pass();
        } finally {
            $this->depth--;

            if ($this->depth === 0) {
                $held = $this->held;
                $this->held = [];

                foreach (self::current($held) as $event) {
                    event($event);
                }
            }
        }
    }

    /**
     * Appelé par `broadcastWhen()` des bases : vrai si la diffusion est
     * retenue par le passage en cours (elle ne part pas maintenant).
     */
    public function holds(RoomBroadcast|SeatBroadcast $event): bool
    {
        if ($this->depth === 0) {
            return false;
        }

        $this->held[] = $event;

        return true;
    }

    /**
     * Exécute `$transition` en déclarant `$theoreticalAt` comme instant
     * théorique de l'étape qui décide les programmations qu'elle émet.
     *
     * @template T
     *
     * @param  Closure(): T  $transition
     * @return T
     */
    public function decidedAt(CarbonImmutable $theoreticalAt, Closure $transition): mixed
    {
        $previous = $this->decidedAt;
        $this->decidedAt = $theoreticalAt;

        try {
            return $transition();
        } finally {
            $this->decidedAt = $previous;
        }
    }

    /**
     * L'instant théorique de l'étape en cours, si une transition l'a déclaré ;
     * `null` sinon — l'appelant prend alors l'instant de sa transaction.
     */
    public function currentDecision(): ?CarbonImmutable
    {
        return $this->decidedAt;
    }

    /**
     * Les diffusions retenues qui décrivent l'état courant, dans leur ordre.
     *
     * @param  list<RoomBroadcast|SeatBroadcast>  $held
     * @return list<RoomBroadcast|SeatBroadcast>
     */
    private static function current(array $held): array
    {
        $current = [];

        foreach ($held as $position => $event) {
            if (! self::isSuperseded($event, array_slice($held, $position + 1))) {
                $current[] = $event;
            }
        }

        return $current;
    }

    /**
     * Vrai si une diffusion postérieure de la même manche rend `$event`
     * périmée — ou, pour un `round.scheduled`, une pause postérieure de sa
     * partie.
     *
     * @param  list<RoomBroadcast|SeatBroadcast>  $later
     */
    private static function isSuperseded(RoomBroadcast|SeatBroadcast $event, array $later): bool
    {
        if (! self::expires($event)) {
            return false;
        }

        if ($event instanceof RoundScheduled && self::pausedLater($event, $later)) {
            return true;
        }

        $key = self::roundKey($event);

        if ($key === null) {
            return false;
        }

        foreach ($later as $candidate) {
            if (self::roundKey($candidate) === $key && self::supersedes($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vrai si un `game.paused` postérieur de la même partie a déprogrammé la
     * manche que `$scheduled` annonce ({@see PauseGame} : toute manche
     * `pending` programmée repasse à `started_at` nul).
     *
     * @param  list<RoomBroadcast|SeatBroadcast>  $later
     */
    private static function pausedLater(RoundScheduled $scheduled, array $later): bool
    {
        $gameRef = $scheduled->gameRef();

        foreach ($later as $candidate) {
            if ($candidate instanceof GamePaused && $candidate->gameRef() === $gameRef) {
                return true;
            }
        }

        return false;
    }

    /** Les diffusions qui se périment : frontières d'une manche, et `seat.choices`. */
    private static function expires(RoomBroadcast|SeatBroadcast $event): bool
    {
        return ($event instanceof RoomBroadcast && $event::BOUNDARY) || $event instanceof SeatChoicesOffered;
    }

    /** Les diffusions qui périment les précédentes de leur manche. */
    private static function supersedes(RoomBroadcast|SeatBroadcast $event): bool
    {
        return ($event instanceof RoomBroadcast && $event::BOUNDARY) || $event instanceof RoundCancelled;
    }

    /**
     * La manche d'une diffusion : `gameRef` et `sequenceIndex` — `null` pour
     * un événement qui ne désigne aucune manche.
     */
    private static function roundKey(RoomBroadcast|SeatBroadcast $event): ?string
    {
        $sequenceIndex = $event->sequenceIndex();
        $gameRef = $event->gameRef();

        return $sequenceIndex === null || $gameRef === null ? null : $gameRef.'#'.$sequenceIndex;
    }
}
