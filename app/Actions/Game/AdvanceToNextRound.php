<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Jobs\Game\AdvanceRound;
use App\Models\Game;
use App\Models\Room;
use App\Models\Round;
use App\Settings\EngineConstants;
use App\Support\Game\NextRoundOutcome;
use App\Support\Game\RoundStep;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le geste « manche suivante » — spec 60 § 5.4, contrat C7 § 2.5 (action
 * interne, nom donné par le contrat) : il **raccourcit `R`, jamais `D`**, et
 * ne touche aucune manche en cours (00 § Déroulé d'une partie, « Pouvoirs de
 * l'hôte en partie »).
 *
 * **Appelants** : la route de l'hôte `room.round.next` (policy
 * `RoomPolicy::advanceRound`, L60-13) et celle du joueur solo `solo.next`
 * (L60-16), qui traduisent l'issue ({@see NextRoundOutcome}) en réponse.
 *
 * 0. {@see CatchUpGame} d'abord, **avant de lire la phase** (§ 4.4) : sans ce
 *    rattrapage, le geste jugerait une révélation que son job en retard n'a
 *    pas encore terminée ;
 * 1. verrous `room` (multijoueur) → `game` → manche `k` → manche `k+1`, dans
 *    l'ordre du § 4.5. En multijoueur, l'autorité (`$authorize`, qui évalue
 *    `RoomPolicy::advanceRound`) est évaluée **après** le verrou `room`, sur
 *    le salon relu, donc sur `room.host_player_id` relu : un transfert
 *    d'hôte concurrent, qui verrouille le salon, est sérialisé avec le
 *    geste ;
 * 2. précondition : une manche `k` en `revealing` et `now < reveal_ends_at(k)`
 *    — sinon `NotRevealing` (409 `not_revealing`) ;
 * 3. `$newEnd = now + preload_lead_ms + nextRoundMarginMs` — `preload_lead_ms`
 *    relu sur la partie (colonne figée), la marge dans `EngineConstants` :
 *    jamais moins, sans quoi le palier 1 suivant n'aurait aucune fenêtre de
 *    préchargement ;
 * 4. si `$newEnd ≥ reveal_ends_at(k)`, rien (`Unchanged`) ; sinon
 *    `reveal_ends_at(k) = $newEnd`, `ScheduleRound(k+1, $newEnd)` s'il reste
 *    une manche — réémission de `round.scheduled` pour une manche `pending`
 *    reprogrammée (contrat C7 § 4.2), mesurée contre l'instant de cette
 *    transaction (§ 4.7) —, et un job `EndReveal` à `$newEnd` après commit.
 *
 * `T₁(k+1) = reveal_ends_at(k)` reste vrai : l'enchaînement sans intervalle
 * du § 5.3, et l'ordre « fin de révélation d'abord » à instant égal, tiennent.
 * Les jobs programmés pour l'ancienne fin de révélation se réveillent sur un
 * rattrapage qui n'a plus rien à faire.
 */
final readonly class AdvanceToNextRound
{
    public function __construct(
        private CatchUpGame $catchUp,
        private ScheduleRound $schedule,
    ) {}

    /**
     * @param  CarbonImmutable  $now  Instant du geste.
     * @param  (Closure(Room): bool)|null  $authorize  Multijoueur : l'autorité du geste, évaluée
     *                                                 sur le salon relu sous son verrou. Ignorée en solo.
     *
     * @throws LogicException Geste multijoueur sans autorité à évaluer.
     */
    public function handle(Game $game, CarbonImmutable $now, ?Closure $authorize = null): NextRoundOutcome
    {
        if ($game->mode === GameMode::Multiplayer && ! $authorize instanceof Closure) {
            throw new LogicException('AdvanceToNextRound : en multijoueur, le geste est réservé à l’hôte ; son autorité doit être évaluée sous le verrou du salon.');
        }

        $this->catchUp->handle($game, $now);

        return DB::transaction(function () use ($game, $now, $authorize): NextRoundOutcome {
            if ($game->mode === GameMode::Multiplayer && $authorize instanceof Closure) {
                $lockedRoom = Room::query()->whereKey($game->room_id)->lockForUpdate()->firstOrFail();

                if (! $authorize($lockedRoom)) {
                    return NextRoundOutcome::Forbidden;
                }
            }

            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();

            if ($lockedGame->ended_at !== null || $lockedGame->status !== GameStatus::Running) {
                return NextRoundOutcome::NotRevealing;
            }

            $revealingRound = Round::query()
                ->where('game_id', $lockedGame->id)
                ->where('status', RoundStatus::Revealing->value)
                ->orderBy('sequence_index')
                ->lockForUpdate()
                ->first();

            $revealEndsAt = $revealingRound?->reveal_ends_at;

            if (! $revealingRound instanceof Round || $revealEndsAt === null || $now->greaterThanOrEqualTo($revealEndsAt)) {
                return NextRoundOutcome::NotRevealing;
            }

            // À la milliseconde de `timestamp(3)` : la fin écrite, la
            // programmation de la suite et le job portent le même instant.
            $newEnd = $now
                ->addMilliseconds($lockedGame->preload_lead_ms + EngineConstants::nextRoundMarginMs())
                ->startOfMillisecond();

            if ($newEnd->greaterThanOrEqualTo($revealEndsAt)) {
                return NextRoundOutcome::Unchanged;
            }

            $revealingRound->forceFill(['reveal_ends_at' => $newEnd])->save();

            $next = Round::query()->where('game_id', $lockedGame->id)->toPlay()->first();

            if ($next instanceof Round) {
                $this->schedule->handle(Round::query()->whereKey($next->id)->lockForUpdate()->firstOrFail(), $newEnd);
            }

            AdvanceRound::dispatch($lockedGame->id, $revealingRound->id, RoundStep::EndReveal, null, WireTime::iso($newEnd));

            return NextRoundOutcome::Advanced;
        });
    }
}
