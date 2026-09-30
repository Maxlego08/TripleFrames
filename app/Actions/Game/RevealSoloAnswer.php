<?php

namespace App\Actions\Game;

use App\Enums\RoundPlayerInputState;
use App\Models\Game;
use App\Models\Player;
use App\Support\Game\SoloOpenRound;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * « Voir la réponse » — geste d'entraînement assisté du solo, spec 60 § 16.5
 * (D18 du 23/09), route `solo.reveal` ; contrat C7 § 4.12 (action interne,
 * nom donné par le contrat).
 *
 * 0. **Barrière 1 de 10 § 7.10** : hors solo, `LogicException`, avant toute
 *    lecture ({@see SoloOpenRound::assertSolo()}) ;
 * 1. {@see CatchUpGame} **avant de lire la phase** (§ 4.4) : sans lui, le
 *    geste jugerait une manche que son job en retard n'a pas encore close ;
 * 2. dans une transaction, **le geste vaut battement** : `last_seen_at =
 *    now`, et le siège revient à `connected` s'il était `disconnected`
 *    ({@see RecordHeartbeat}, verrou `player` pris d'abord, ordre du § 4.5).
 *    Sans cela, un siège passé `disconnected` (onglet mobile en arrière-plan)
 *    qui touche « Voir la réponse » avant son battement de retour ne serait
 *    pas participant, la fin anticipée exige `COUNT(participants) ≥ 1`
 *    (§ 9.2), et la manche courrait jusqu'à `D` contre D18. Un geste refusé
 *    reste un battement : il prouve la présence du siège ;
 * 3. précondition, relue sous les verrous `game → round → round_player`
 *    ({@see SoloOpenRound::lock()}) : manche `running`, `ended_at` nul,
 *    saisie du siège `open` ou `text_exhausted` — sinon `false` (409
 *    `round_not_running`), sans rien écrire d'autre ;
 * 4. `input_state = revealed`, `input_closed_at = now` ;
 * 5. {@see SeatInputClosed} avec l'instant écrit du geste (§ 9.1) : le siège
 *    solo étant le seul participant, la fin anticipée clôt la manche à cet
 *    instant ({@see CloseRound}, borné à `started_at + D`), et la
 *    **révélation normale de durée `R`** suit, à `ended_at + tier_grace_ms`,
 *    par le job `Reveal` que la clôture programme.
 *
 * **Jamais de `guess`** (10 § 7.10) : une manche révélée n'est jamais une
 * bonne réponse, elle rapporte 0 point, et `correct_answers` compte des
 * lignes `guess`. **Aucune diffusion** (C7 § 4.12) : toutes les transitions
 * appelées gardent leur émission au multijoueur.
 */
final readonly class RevealSoloAnswer
{
    public function __construct(
        private CatchUpGame $catchUp,
        private RecordHeartbeat $heartbeat,
        private SeatInputClosed $seatInputClosed,
    ) {}

    /**
     * @param  Game  $game  La partie solo en cours du siège (`seat.active`).
     * @param  Player  $seat  Le siège solo résolu par le jeton.
     * @param  CarbonImmutable  $now  Instant du geste, à la milliseconde.
     * @return bool `true` si la saisie du siège est close en `revealed` ;
     *              `false` hors précondition (409 `round_not_running`).
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

            $open->roundPlayer->forceFill([
                'input_state' => RoundPlayerInputState::Revealed,
                'input_closed_at' => $now,
            ])->save();

            // L'instant écrit de l'événement déclencheur (§ 9.1) : `input_closed_at`.
            $this->seatInputClosed->handle($open->round, $open->roundPlayer, null, $now);

            return true;
        });
    }
}
