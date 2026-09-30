<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Events\Game\RoundClosed;
use App\Jobs\Game\AdvanceRound;
use App\Models\Game;
use App\Models\Room;
use App\Models\Round;
use App\Support\Game\GameJournal;
use App\Support\Game\RoundStep;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La clôture d'une manche — spec 60 § 9.1, contrat C7 § 4.6 (action interne,
 * nom donné par le contrat).
 *
 * Sous le **seul verrou `round`**, idempotente (`ended_at` non nul → rien).
 * Elle ne prend jamais `game` : `SeatInputClosed` l'appelle en tenant déjà le
 * verrou de la manche (fin anticipée, § 8.2), et prendre `game` après `round`
 * inverserait l'ordre global room → player → game → round → round_player
 * (§ 4.5). La partie est lue sans verrou : ses colonnes lues ici
 * (`tier_grace_ms`, `settings_snapshot`, `mode`) sont figées au lancement, et
 * son statut ne change jamais pendant qu'une manche court — la pause n'arrive
 * qu'entre deux manches, et le gel prend `round` après `game`, donc attend
 * cette clôture.
 *
 * - `ended_at = min($endedAt, started_at + D)` : l'instant demandé est
 *   toujours borné. **À `D`**, le job passe `started_at + D`, l'instant
 *   théorique ; **en fin anticipée**, `SeatInputClosed` passe l'instant de
 *   l'événement déclencheur tel qu'il est écrit en base (`input_closed_at`,
 *   `disconnected_at`, `left_at`), jamais l'heure d'exécution de l'écouteur :
 *   un écouteur en retard ne décale ni `ended_at`, ni la fenêtre
 *   d'acceptation qui en dépend (contrat C7 § 4.6) ;
 * - `reveal_ends_at = ended_at + tier_grace_ms + R × 1000`, `tier_grace_ms`
 *   relu sur la partie (colonne figée, jamais la configuration) et `R` sur
 *   son instantané de réglages ;
 * - **`round.status` reste `running`** jusqu'à `RevealRound` : c'est ce qui
 *   garde recevable une soumission reçue dans la grâce finale ;
 * - après commit : `round.closed` `{ sequenceIndex, roundNumber, endedAt,
 *   revealStartsAt, revealEndsAt }`, **en multijoueur seulement**, sans
 *   aucun titre — diffusion de frontière, mesurée contre l'instant de
 *   clôture écrit ; le job `Reveal` à `ended_at + tier_grace_ms` ; la
 *   clôture au journal `game`, avec sa cause : `D` si l'instant écrit est
 *   `started_at + D`, fin anticipée sinon (§ 4.7).
 *
 * Une clôture sur une partie close ou en pause, ou sur une manche qui ne
 * court pas (`pending`, `revealing`, `completed`, `cancelled`), est périmée :
 * rien n'est écrit ni émis.
 */
final readonly class CloseRound
{
    /**
     * Colonnes que la clôture écrit, recopiées sur l'instance de l'appelant.
     *
     * @var list<string>
     */
    private const array CLOSED_COLUMNS = ['ended_at', 'reveal_ends_at', 'updated_at'];

    /**
     * @param  CarbonImmutable  $endedAt  Instant de clôture demandé : `started_at + D` à `D`,
     *                                    instant écrit de l'événement déclencheur en fin anticipée.
     *
     * @throws LogicException Instant de clôture antérieur au début de la manche.
     */
    public function handle(Round $round, CarbonImmutable $endedAt): void
    {
        DB::transaction(function () use ($round, $endedAt): void {
            $lockedRound = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
            $game = Game::query()->findOrFail($lockedRound->game_id);

            if ($lockedRound->ended_at !== null
                || $lockedRound->status !== RoundStatus::Running
                || $game->ended_at !== null
                || $game->status !== GameStatus::Running) {
                self::reflect($round, $lockedRound);

                return;
            }

            $startedAt = $lockedRound->started_at ?? throw new LogicException(sprintf(
                'CloseRound : la manche %d court sans origine de temps.',
                $lockedRound->sequence_index,
            ));

            if ($endedAt->lessThan($startedAt)) {
                throw new LogicException(sprintf(
                    'CloseRound : la manche %d ne peut pas se clore avant son début.',
                    $lockedRound->sequence_index,
                ));
            }

            // Borné à `D`, à la milliseconde de `timestamp(3)` : la fin de
            // révélation se calcule sur l'instant tel qu'il est écrit.
            $durationEnd = $startedAt->addMilliseconds($lockedRound->duration_ms);
            $closedAt = ($endedAt->lessThan($durationEnd) ? $endedAt : $durationEnd)->startOfMillisecond();
            $revealStartsAt = $closedAt->addMilliseconds($game->tier_grace_ms);
            $revealEndsAt = $revealStartsAt->addSeconds($game->settings_snapshot->revealDuration);

            $lockedRound->forceFill([
                'ended_at' => $closedAt,
                'reveal_ends_at' => $revealEndsAt,
            ])->save();

            self::reflect($round, $lockedRound);

            GameJournal::roundClosed(
                $game,
                $lockedRound,
                $closedAt->equalTo($durationEnd->startOfMillisecond()) ? GameJournal::CLOSE_CAUSE_DURATION : GameJournal::CLOSE_CAUSE_EARLY_END,
                $closedAt,
            );

            // Garde de mode (§ 11.2) : aucune diffusion en solo. Aucun titre.
            if ($game->mode === GameMode::Multiplayer) {
                event((new RoundClosed(self::room($game), $game, [
                    'sequenceIndex' => $lockedRound->sequence_index,
                    'roundNumber' => (int) $lockedRound->round_number,
                    'endedAt' => WireTime::iso($closedAt),
                    'revealStartsAt' => WireTime::iso($revealStartsAt),
                    'revealEndsAt' => WireTime::iso($revealEndsAt),
                ]))->atBoundary($closedAt));
            }

            AdvanceRound::dispatch($game->id, $lockedRound->id, RoundStep::Reveal, null, WireTime::iso($revealStartsAt));
        });
    }

    /**
     * @throws LogicException
     */
    private static function room(Game $game): Room
    {
        return $game->room ?? throw new LogicException('CloseRound : partie multijoueur sans salon.');
    }

    /**
     * Recopie sur l'instance de l'appelant ce que porte la manche relue, sans
     * rien réécrire en base.
     */
    private static function reflect(Round $round, Round $lockedRound): void
    {
        $round->forceFill($lockedRound->only(self::CLOSED_COLUMNS))
            ->syncOriginalAttributes(self::CLOSED_COLUMNS);
    }
}
