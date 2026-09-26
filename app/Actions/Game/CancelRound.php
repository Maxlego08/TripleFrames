<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Events\Game\RoundCancelled;
use App\Models\Game;
use App\Models\Room;
use App\Models\Round;
use App\Settings\EngineConstants;
use App\Support\Draw\ReplacementRoundChooser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * L'annulation d'une manche sur échec technique — spec 60 § 15.2, contrat C7
 * § 2.5 (action interne, nom donné par le contrat).
 *
 * Décidée, au J1, par la frappe d'un jeton sans variante servable
 * (`no_variant_available`, {@see MintTierServeToken}), par l'ouverture d'un
 * palier dont la frame n'est plus servable (`frame_unavailable`) ou dont le
 * QCM Facile n'est pas composable (`choices_unavailable`) — `OpenTier`, L60-6
 * et L60-11. L'annulation ACTIVE sur suspension ou retrait admin
 * (`WithdrawContentFromLiveRounds`, `movie_suspended`) est du J2 (L60-17,
 * R-33) : au J1, une dépublication de curation reste paresseuse (§ 15.3).
 *
 * Sous les verrous `game` → manches (ordre global room → player → game →
 * round → round_player, § 4.5), dans une seule transaction :
 *
 * 1. `round.status = cancelled`, `cancel_reason`, `cancelled_at = $now`.
 *    **Aucun point** : toute agrégation exclut la manche (invariant L1,
 *    `Round::notCancelled()`), verrouillages déjà acquis compris ;
 * 2. **remplacement** : {@see ReplacementRoundChooser::next()} (contrat C3) ;
 *    le remplaçant reçoit le `round_number` de la manche annulée —
 *    `round_number` est non unique, volontairement (10 § 7.4) — et est
 *    programmé à `max($now + launchCountdownMs, T₁ prévu de la manche
 *    annulée)`, pour ne jamais empiéter sur une révélation en cours ;
 * 3. **réserve épuisée** : la partie continue avec une manche de moins — la
 *    manche suivante à jouer (numérotée, `pending`, plus petit
 *    `round_number`, § 1.2) est programmée au même instant, comme un
 *    remplaçant ; **aucune ne reste** et **aucune manche de la partie n'est
 *    en `revealing`** : `FinalizeGame::handle($game, GameStatus::Completed,
 *    $now)`, l'instant de l'annulation, dans la même transaction, `game` pris
 *    d'abord — si une manche est en révélation, rien : `EndReveal` gèle à sa
 *    fin ;
 * 4. après commit : `round.cancelled` `{ sequenceIndex, roundNumber }` — ni
 *    motif, ni titre — **en multijoueur seulement** (§ 11.2), toujours avant
 *    le `round.scheduled` du remplaçant et le `game.ended` d'un gel
 *    ({@see self::markCancelled()}).
 *
 * **Garde « aucune manche en révélation » avant le gel** (§ 15.2, étape 3,
 * lot L60-6) : `RevealRound(k)` programme `k+1` dès le début de la
 * révélation de `k`, et la frappe du palier 1 de `k+1` peut l'annuler
 * (`no_variant_available`) ; sans manche restante, geler à l'instant de
 * l'annulation couperait la révélation de `k`. Le gel est laissé à
 * `EndReveal(k)`, à `reveal_ends_at(k)` (§ 9.6, § 14.5).
 *
 * **Idempotente** : une manche déjà annulée n'est ni relue, ni remplacée, ni
 * rediffusée. Une annulation programme toujours la suite dans sa propre
 * transaction : `cancelled` n'est qu'un état transitoire d'un rattrapage
 * (§ 3.2). L'incident s'agrège par film dans la file de curation de 20, sans
 * jamais joindre `round_player`, `guess` ni `player` (10 § 7.4).
 */
final readonly class CancelRound
{
    /**
     * Colonnes que l'annulation écrit, recopiées sur l'instance de l'appelant.
     *
     * @var list<string>
     */
    private const array CANCELLED_COLUMNS = ['status', 'cancel_reason', 'cancelled_at', 'updated_at'];

    public function __construct(
        private ReplacementRoundChooser $replacements,
        private FinalizeGame $finalize,
    ) {}

    /**
     * @param  CarbonImmutable  $now  Instant de l'annulation (`round.cancelled_at`).
     *
     * @throws LogicException Partie close ou en pause, manche qui n'est ni
     *                        `pending` ni `running`, ou manche de réserve
     *                        (sans numéro, jamais programmée).
     */
    public function handle(Round $round, RoundIncidentReason $reason, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($round, $reason, $now): void {
            $game = Game::query()->whereKey($round->game_id)->lockForUpdate()->firstOrFail();
            $cancelled = self::markCancelled($game, $round, $reason, $now);

            if (! $cancelled instanceof Round) {
                return;
            }

            // `started_at` n'est pas touché par l'annulation : c'est le T₁ prévu.
            $startsAt = self::replacementStart($now, $cancelled->started_at);
            $next = $this->replacement($game, $cancelled) ?? self::nextToPlay($game);

            if ($next instanceof Round) {
                app(ScheduleRound::class)->handle($next, $startsAt);

                return;
            }

            // Garde « aucune manche en révélation » (§ 15.2, étape 3) : la
            // frappe du palier 1 de k+1, appelée par `RevealRound(k)`, peut
            // annuler k+1 pendant la révélation de k. Geler ici couperait
            // cette révélation — `FinalizeGame` la passerait d'office en
            // `completed`, refuserait ses URL et ferait partir `game.ended`
            // aussitôt. `EndReveal(k)`, ne trouvant aucune manche à jouer,
            // gèle à `reveal_ends_at(k)`.
            if (self::revealing($game)) {
                return;
            }

            $this->finalize->handle($game, GameStatus::Completed, $now);
        });
    }

    /**
     * Étape 1, et l'émission de l'étape 4, dans une transaction IMBRIQUÉE qui
     * valide avant que la suite ne soit programmée : les rappels après commit
     * d'une transaction imbriquée partent avant ceux de ses aînées validées
     * plus tard (`DatabaseTransactionsManager`, vérifié), si bien que, sur le
     * fil, `round.cancelled` précède toujours le `round.scheduled` du
     * remplaçant et le `game.ended` d'un gel.
     *
     * @return Round|null la manche annulée, relue sous son verrou ; `null` si
     *                    elle l'était déjà (rien n'est écrit ni émis).
     *
     * @throws LogicException
     */
    private static function markCancelled(Game $game, Round $round, RoundIncidentReason $reason, CarbonImmutable $now): ?Round
    {
        return DB::transaction(static function () use ($game, $round, $reason, $now): ?Round {
            $locked = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === RoundStatus::Cancelled) {
                self::reflect($round, $locked);

                return null;
            }

            self::assertCancellable($game, $locked);

            $locked->forceFill([
                'status' => RoundStatus::Cancelled,
                'cancel_reason' => $reason,
                'cancelled_at' => $now,
            ])->save();

            self::reflect($round, $locked);

            // Garde de mode (§ 11.2) : aucune diffusion en solo. Ni motif, ni
            // titre.
            if ($game->mode === GameMode::Multiplayer) {
                RoundCancelled::dispatch(self::room($game), $game, [
                    'sequenceIndex' => $locked->sequence_index,
                    'roundNumber' => (int) $locked->round_number,
                ]);
            }

            return $locked;
        });
    }

    /**
     * Instant de programmation de la suite : `max($now + launchCountdownMs,
     * T₁ prévu de la manche annulée)`. Une manche jamais programmée n'a pas
     * de `T₁` prévu : le décompte seul.
     */
    public static function replacementStart(CarbonImmutable $now, ?CarbonImmutable $plannedStart): CarbonImmutable
    {
        $countdownEnd = $now->addMilliseconds(EngineConstants::launchCountdownMs());

        return $plannedStart !== null && $plannedStart->greaterThan($countdownEnd) ? $plannedStart : $countdownEnd;
    }

    /**
     * Le remplaçant, verrouillé et numéroté du `round_number` de la manche
     * annulée — ou `null` : réserve épuisée.
     */
    private function replacement(Game $game, Round $cancelled): ?Round
    {
        $candidate = $this->replacements->next($game);

        if (! $candidate instanceof Round) {
            return null;
        }

        $replacement = Round::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

        $replacement->forceFill(['round_number' => $cancelled->round_number])->save();

        return $replacement;
    }

    /**
     * La manche suivante à jouer (§ 1.2) : `pending`, numérotée, de plus petit
     * `round_number` puis `sequence_index` — l'ordre de jeu (50 § 15.2).
     */
    private static function nextToPlay(Game $game): ?Round
    {
        $candidate = Round::query()
            ->where('game_id', $game->id)
            ->toPlay()
            ->first();

        return $candidate instanceof Round
            ? Round::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail()
            : null;
    }

    /**
     * Vrai si une manche de la partie est en révélation — relue sous le
     * verrou `game`, que tout écrivain de `revealing` tient (`RevealRound`,
     * `EndReveal`, `FinalizeGame`).
     */
    private static function revealing(Game $game): bool
    {
        return Round::query()
            ->where('game_id', $game->id)
            ->where('status', RoundStatus::Revealing->value)
            ->exists();
    }

    /**
     * @throws LogicException
     */
    private static function assertCancellable(Game $game, Round $round): void
    {
        if ($game->ended_at !== null || $game->status !== GameStatus::Running) {
            throw new LogicException(sprintf(
                'CancelRound : la partie est %s ; seule une manche d’une partie en cours s’annule.',
                $game->status->value,
            ));
        }

        if (! in_array($round->status, [RoundStatus::Pending, RoundStatus::Running], true)) {
            throw new LogicException(sprintf(
                'CancelRound : la manche %d est %s ; seule une manche pending ou running s’annule (§ 3.2).',
                $round->sequence_index,
                $round->status->value,
            ));
        }

        if ($round->round_number === null) {
            throw new LogicException(sprintf(
                'CancelRound : la manche %d est une manche de réserve, jamais programmée ; elle ne s’annule pas.',
                $round->sequence_index,
            ));
        }
    }

    /**
     * @throws LogicException
     */
    private static function room(Game $game): Room
    {
        return $game->room ?? throw new LogicException('CancelRound : partie multijoueur sans salon.');
    }

    /**
     * Recopie sur l'instance de l'appelant ce que porte la manche relue, sans
     * rien réécrire en base.
     */
    private static function reflect(Round $round, Round $locked): void
    {
        $round->forceFill($locked->only(self::CANCELLED_COLUMNS))
            ->syncOriginalAttributes(self::CANCELLED_COLUMNS);
    }
}
