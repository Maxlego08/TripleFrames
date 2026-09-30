<?php

namespace App\Actions\Game;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Settings\EngineConstants;
use App\Support\Game\GameJournal;
use App\Support\Game\SoloOpenRound;
use App\Support\Game\SoloRoundClosure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * « Passer la manche » — geste d'entraînement assisté du solo, spec 60
 * § 16.5 (D18 du 23/09), route `solo.skip` ; contrat C7 § 4.12 (action
 * interne, nom donné par le contrat).
 *
 * 0. **Barrière 1 de 10 § 7.10** : hors solo, `LogicException`, avant toute
 *    lecture ({@see SoloOpenRound::assertSolo()}) ;
 * 1. {@see CatchUpGame} **avant de lire la phase** (§ 4.4) ;
 * 2. dans une transaction, **le geste vaut battement** ({@see RecordHeartbeat},
 *    verrou `player` d'abord, § 4.5), refusé ou non ;
 * 3. précondition sous les verrous `game → round → round_player`
 *    ({@see SoloOpenRound::lock()}) : manche `running`, `ended_at` nul,
 *    saisie du siège `open` ou `text_exhausted` — sinon `false` (409
 *    `round_not_running`) ;
 * 4. clôture sans révélation ({@see SoloRoundClosure::skip()}) :
 *    `input_state = skipped`, `ended_at = reveal_ends_at = min(now,
 *    started_at + D)` — **jamais au-delà de `D`** —, `round.status =
 *    completed`, `rounds_completed` recalculé ;
 * 5. s'il reste une manche à jouer, elle est programmée à `now +
 *    preload_lead_ms + nextRoundMarginMs` ({@see ScheduleRound}) —
 *    `preload_lead_ms` relu sur la partie (colonne figée), jamais moins, sans
 *    quoi son palier 1 n'aurait aucune fenêtre de préchargement ; sinon la
 *    partie est gelée `completed` à `reveal_ends_at` de cette manche
 *    ({@see FinalizeGame}, `game` pris avant `round`, § 4.5 et § 14.5).
 *
 * **Aucune révélation** : la manche ne passe jamais `revealing`, et son titre
 * n'apparaît qu'au récapitulatif du podium (80). **Jamais de `guess`**
 * (10 § 7.10). **Aucune diffusion** (C7 § 4.12).
 */
final readonly class SkipSoloRound
{
    public function __construct(
        private CatchUpGame $catchUp,
        private RecordHeartbeat $heartbeat,
        private ScheduleRound $schedule,
        private FinalizeGame $finalize,
    ) {}

    /**
     * @param  Game  $game  La partie solo en cours du siège (`seat.active`).
     * @param  Player  $seat  Le siège solo résolu par le jeton.
     * @param  CarbonImmutable  $now  Instant du geste, à la milliseconde.
     * @return bool `true` si la manche est passée ; `false` hors précondition
     *              (409 `round_not_running`).
     *
     * @throws LogicException Partie non solo, siège de salon ou étranger à la partie.
     */
    public function handle(Game $game, Player $seat, CarbonImmutable $now): bool
    {
        SoloOpenRound::assertSolo($game, $seat);

        $this->catchUp->handle($game, $now);

        return DB::transaction(function () use ($game, $seat, $now): bool {
            if (! $this->heartbeat->handle($seat, null, $now)) {
                return false;
            }

            $open = SoloOpenRound::lock($game, $seat);

            if (! $open instanceof SoloOpenRound) {
                return false;
            }

            $lockedGame = $open->game;
            $closedAt = SoloRoundClosure::skip($lockedGame, $open->round, $now);
            $next = Round::query()->where('game_id', $lockedGame->id)->toPlay()->first();

            if ($next instanceof Round) {
                $startsAt = $now
                    ->addMilliseconds($lockedGame->preload_lead_ms + EngineConstants::nextRoundMarginMs())
                    ->startOfMillisecond();

                $this->schedule->handle(Round::query()->whereKey($next->id)->lockForUpdate()->firstOrFail(), $startsAt);

                return true;
            }

            // Aucune manche à jouer : le gel, à `reveal_ends_at` de la manche
            // passée (= min(now, started_at + D)).
            if ($this->finalize->handle($lockedGame, GameStatus::Completed, $closedAt)) {
                GameJournal::gameFinalized($lockedGame, GameStatus::Completed, $closedAt);
            }

            return true;
        });
    }
}
