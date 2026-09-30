<?php

namespace App\Listeners\Game;

use App\Actions\Game\SeatInputClosed;
use App\Events\Game\AnswerAccepted;
use App\Events\Game\InputClosed;
use App\Models\Guess;
use App\Models\Round;
use App\Models\RoundPlayer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Les crochets de fin de saisie — spec 60 § 8.2, contrat C7 § 2.5 et § 4.7
 * (écouteurs internes, noms libres ; lot L60-11).
 *
 * Deux écouteurs, enregistrés dans `AppServiceProvider::boot()` faute
 * d'`EventServiceProvider` : {@see self::answerAccepted()} pour
 * l'événement de domaine `AnswerAccepted` de 70 (une bonne réponse vient
 * d'être verrouillée) et {@see self::inputClosed()} pour `InputClosed`
 * (saisie close sans bonne réponse : clic faux, tentatives épuisées, cas
 * terminal du QCM en Normal). Tous deux sont livrés **après le commit** de
 * la transaction qui a clos la saisie, **synchrones** — la réévaluation suit
 * le commit dans le même processus, sans file ni tête de file —, et jamais
 * diffusés : leurs identifiants sont internes.
 *
 * Chacun ouvre **sa propre transaction**, dont la **première lecture** est
 * `round FOR UPDATE` (passation d'E93-4) : en REPEATABLE READ, la lecture non
 * verrouillante qui suit — la participation, la bonne réponse, puis les
 * participants que lit {@see SeatInputClosed} — voit tout ce qu'une autre
 * clôture a validé avant ce verrou. Comme toute écriture qui clôt une saisie
 * tient le verrou `round` jusqu'à son commit, **le dernier à clore voit
 * toujours l'état complet** : deux derniers verrouillages concurrents
 * clôturent la manche une seule fois.
 *
 * L'instant passé au crochet est `round_player.input_closed_at` **relu**,
 * l'instant écrit de la clôture (instant de réception de 70, ou instant
 * théorique de composition pour le cas terminal), jamais l'heure
 * d'exécution de l'écouteur (contrat C7 § 4.6).
 *
 * **Idempotents** : un événement redélivré réévalue la même manche, que
 * {@see SeatInputClosed} ne reclôt jamais ; un `player.locked` rejoué est
 * dédoublonné par le client. Une manche ou une participation disparue
 * (purge) n'a plus rien à réévaluer.
 */
final readonly class CloseSeatInput
{
    public function __construct(private SeatInputClosed $hook) {}

    /**
     * Bonne réponse verrouillée : `player.locked` en multijoueur, puis la
     * fin anticipée réévaluée.
     *
     * @throws LogicException Participation `locked` sans ligne `guess`, ou saisie close sans instant.
     */
    public function answerAccepted(AnswerAccepted $event): void
    {
        $this->reevaluate($event->roundId, $event->playerId, withGuess: true);
    }

    /**
     * Saisie close sans bonne réponse : la fin anticipée seule est
     * réévaluée — `InputClosed` ne part jamais sur le fil (§ 8.5).
     *
     * @throws LogicException Saisie close sans instant.
     */
    public function inputClosed(InputClosed $event): void
    {
        $this->reevaluate($event->roundId, $event->playerId, withGuess: false);
    }

    /**
     * @throws LogicException
     */
    private function reevaluate(int $roundId, int $playerId, bool $withGuess): void
    {
        DB::transaction(function () use ($roundId, $playerId, $withGuess): void {
            // Première lecture : le verrou de la manche, jamais une lecture
            // simple, qui fixerait l'instantané avant lui.
            $lockedRound = Round::query()->whereKey($roundId)->lockForUpdate()->first();

            if (! $lockedRound instanceof Round) {
                return;
            }

            $participation = RoundPlayer::query()
                ->where('round_id', $roundId)
                ->where('player_id', $playerId)
                ->first();

            if (! $participation instanceof RoundPlayer) {
                return;
            }

            $closedAt = self::closedAt($participation, $lockedRound);
            $guess = $withGuess ? self::guess($participation, $lockedRound) : null;

            $this->hook->handle($lockedRound, $participation, $guess, $closedAt);
        });
    }

    /**
     * L'instant ÉCRIT de la clôture.
     *
     * @throws LogicException
     */
    private static function closedAt(RoundPlayer $participation, Round $round): CarbonImmutable
    {
        return $participation->input_closed_at ?? throw new LogicException(sprintf(
            'CloseSeatInput : une saisie annoncée close dans la manche %d ne porte aucun instant de clôture.',
            $round->sequence_index,
        ));
    }

    /**
     * La bonne réponse qui a clos la saisie : `locked` si et seulement si une
     * ligne `guess` existe (10 § 7.6).
     *
     * @throws LogicException
     */
    private static function guess(RoundPlayer $participation, Round $round): Guess
    {
        return Guess::query()
            ->where('round_id', $participation->round_id)
            ->where('player_id', $participation->player_id)
            ->first() ?? throw new LogicException(sprintf(
                'CloseSeatInput : une bonne réponse annoncée dans la manche %d n’a pas de ligne guess.',
                $round->sequence_index,
            ));
    }
}
