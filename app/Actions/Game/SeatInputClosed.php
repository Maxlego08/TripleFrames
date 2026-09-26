<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\RoundStatus;
use App\Events\Game\PlayerLocked;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le crochet de fin de saisie d'un siège — spec 60 § 8.2, § 9.1 et § 9.2,
 * contrat C7 § 2.5 et § 4.7 (nom et signature figés, consommé par 50 et 70).
 *
 * **Appelants** : les écouteurs de 60 des événements de domaine
 * `AnswerAccepted` et `InputClosed` de 70 (L60-11) ; `KickSeat` et
 * `LeaveRoom` de 50, dans une seconde transaction après le commit du geste
 * (§ 13.4) ; le balayage de présence, quand un siège sort des participants
 * (§ 13.2) ; « Voir la réponse » en solo (§ 16.5). Chacun l'appelle dans sa
 * propre transaction, qui reprend `round FOR UPDATE` ; ce crochet le reprend
 * lui-même (sans effet sous un appelant qui le tient déjà), et c'est sa
 * PREMIÈRE lecture : en REPEATABLE READ, la lecture non verrouillante des
 * participants qui suit voit tout ce qui a été validé avant le verrou. Il ne
 * prend **jamais** `game` (§ 4.5) : l'ordre global room → player → game →
 * round → round_player serait inversé.
 *
 * 1. **En multijoueur seulement**, si `$guess` est non nul : `player.locked`
 *    `{ sequenceIndex, publicId, lockRank }` — rien d'autre, ni points, ni
 *    palier, ni chaîne (10 § 7.6). Un `player.locked` redélivré est
 *    dédoublonné par le client sur (`gameRef`, `sequenceIndex`, `publicId`).
 * 2. Si la manche est `running`, `ended_at` nul, et que
 *    {@see Round::isEarlyEndReached()} est vrai — **au moins un participant**
 *    (`round_player` dont le siège est `connected` et `left_at` nul) **et
 *    tous** à saisie close, `text_exhausted` n'étant PAS une saisie close
 *    (10 § 7.7 amendé par D20 du 23/09, E10-53) — : {@see CloseRound} à
 *    `$now`. Zéro participant ne clôt jamais une manche : elle va au bout de
 *    `D`. Un siège déconnecté, ou un retardataire connecté sans ligne dans la
 *    manche, ne bloque jamais la fin anticipée.
 *
 * **`$now` est l'instant de l'événement déclencheur tel qu'il est écrit en
 * base** (contrat C7 § 4.6) — `round_player.input_closed_at` du siège qui
 * vient de clore, le `disconnected_at` ou le `left_at` que le balayage, le
 * départ ou l'expulsion viennent d'écrire —, jamais l'heure d'exécution de
 * l'écouteur : un écouteur en retard ne décale ni `ended_at`, ni la fenêtre
 * d'acceptation qui en dépend. `CloseRound` le borne à `started_at + D`.
 *
 * Comme chaque écriture qui clôt une saisie est suivie, après son commit,
 * d'une réévaluation sous le verrou `round`, **le dernier à clore voit
 * toujours l'état complet** : deux derniers verrouillages concurrents
 * clôturent la manche une seule fois. Idempotent : une manche déjà close,
 * révélée, terminée ou annulée n'est pas reclose.
 */
final readonly class SeatInputClosed
{
    /**
     * Colonnes de clôture recopiées sur l'instance de l'appelant.
     *
     * @var list<string>
     */
    private const array CLOSED_COLUMNS = ['ended_at', 'reveal_ends_at', 'updated_at'];

    public function __construct(private CloseRound $close) {}

    /**
     * @param  Round  $lockedRound  La manche du siège, que l'appelant a reprise `FOR UPDATE`.
     * @param  RoundPlayer  $roundPlayer  La participation dont la saisie vient de se clore.
     * @param  Guess|null  $guess  La bonne réponse qui l'a close (`AnswerAccepted`), nulle sinon.
     * @param  CarbonImmutable  $now  Instant écrit de l'événement déclencheur.
     *
     * @throws LogicException Participation ou réponse d'une autre manche ou d'un autre siège.
     */
    public function handle(Round $lockedRound, RoundPlayer $roundPlayer, ?Guess $guess, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($lockedRound, $roundPlayer, $guess, $now): void {
            $round = Round::query()->whereKey($lockedRound->id)->lockForUpdate()->firstOrFail();

            self::assertBelongs($round, $roundPlayer, $guess);

            // Lue sans verrou (§ 4.5) : `mode` et `room_id` sont figés à la
            // création de la partie.
            $game = Game::query()->findOrFail($round->game_id);

            // Dans une transaction IMBRIQUÉE validée avant la clôture : les
            // rappels après commit d'une transaction imbriquée partent avant
            // ceux de ses aînées validées plus tard (E90-4), si bien que, sur
            // le fil, `player.locked` précède toujours le `round.closed` qu'il
            // déclenche.
            if ($guess !== null && $game->mode === GameMode::Multiplayer) {
                DB::transaction(static function () use ($game, $round, $roundPlayer, $guess): void {
                    PlayerLocked::dispatch(self::room($game), $game, [
                        'sequenceIndex' => $round->sequence_index,
                        'publicId' => Player::query()->whereKey($roundPlayer->player_id)->value('public_id'),
                        'lockRank' => $guess->lock_rank,
                    ]);
                });
            }

            if ($round->status === RoundStatus::Running
                && $round->ended_at === null
                && $round->isEarlyEndReached()) {
                $this->close->handle($round, $now);
            }

            $lockedRound->forceFill($round->only(self::CLOSED_COLUMNS))
                ->syncOriginalAttributes(self::CLOSED_COLUMNS);
        });
    }

    /**
     * @throws LogicException
     */
    private static function assertBelongs(Round $round, RoundPlayer $roundPlayer, ?Guess $guess): void
    {
        if ($roundPlayer->round_id !== $round->id) {
            throw new LogicException(sprintf(
                'SeatInputClosed : la participation n’appartient pas à la manche %d.',
                $round->sequence_index,
            ));
        }

        if ($guess !== null && ($guess->round_id !== $round->id || $guess->player_id !== $roundPlayer->player_id)) {
            throw new LogicException(sprintf(
                'SeatInputClosed : la bonne réponse n’est pas celle de ce siège dans la manche %d.',
                $round->sequence_index,
            ));
        }
    }

    /**
     * @throws LogicException
     */
    private static function room(Game $game): Room
    {
        return $game->room ?? throw new LogicException('SeatInputClosed : partie multijoueur sans salon.');
    }
}
