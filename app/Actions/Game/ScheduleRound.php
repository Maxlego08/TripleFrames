<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Events\Game\RoundScheduled;
use App\Jobs\Game\AdvanceRound;
use App\Models\Game;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundTier;
use App\Support\Game\RoundStep;
use App\Support\Game\RoundTimelinePresenter;
use App\Support\Game\TierImageRefPresenter;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La programmation d'une manche — spec 60 § 5.2, contrat C7 § 2.5 et
 * contrat C6 O9 (nom et signature figés).
 *
 * Exige une manche `pending` numérotée et une partie `running`. Dans la
 * transaction appelante (verrous `game` puis `round`, § 4.5) :
 *
 * 1. écrit `round.started_at = $startsAt` — l'**origine unique** de la
 *    manche. Réécriture permise tant que la manche est `pending`
 *    (reprogrammation, reprise, « manche suivante ») ; dès `T₁`, elle est
 *    immuable (contrat C7 § 4.2) ;
 * 2. frappe le jeton du palier 1 par {@see MintTierServeToken} (§ 6.2),
 *    idempotente — un jeton déjà frappé est réutilisé (reprise, § 14.2) —,
 *    et qui peut annuler la manche (`no_variant_available`) : l'annulation
 *    programme alors elle-même la suite, et rien d'autre n'est fait ici ;
 * 3. après commit : le job `AdvanceRound(OpenTier, 1)` à `$startsAt` et, **en
 *    multijoueur seulement** (§ 11.2), `round.scheduled` `{ round:
 *    RoundTimeline, image: TierImageRef }` — le palier 1 seul, servable dès
 *    `T₁ − preload_lead_ms` comme tout palier, **sans exception pour la
 *    manche 1** (E10-60).
 *
 * **Appelants** : `OpenGame` pour la manche 1 à `$now + launchCountdownMs`
 * (50, C6 O9 ; solo compris) ; `CancelRound` pour un remplaçant (§ 15.2) ;
 * `RevealRound` (manche `k+1` à `reveal_ends_at(k)`), `ResumeGame` et
 * `AdvanceToNextRound` (L60-6, L60-7, L60-13). Réémettre `round.scheduled`
 * pour une manche `pending` reprogrammée est voulu : le client garde celui au
 * `serverNow` le plus grand (§ 11.8).
 *
 * Les lignes `round_player` naissent à `T₁`, jamais ici (E10-49). Aucun
 * palier n'est ouvert ici : `served_at` et `seen_frame` appartiennent à
 * `OpenTier` (C8 § 2).
 */
final readonly class ScheduleRound
{
    public function __construct(private MintTierServeToken $mint) {}

    /**
     * Au retour, l'instance reçue porte `started_at` tel qu'écrit, et le
     * statut `cancelled` si la frappe du palier 1 a annulé la manche.
     *
     * @throws LogicException Partie close ou en pause, manche qui n'est pas
     *                        `pending`, ou manche de réserve sans numéro.
     */
    public function handle(Round $round, CarbonImmutable $startsAt): void
    {
        DB::transaction(function () use ($round, $startsAt): void {
            $game = Game::query()->whereKey($round->game_id)->lockForUpdate()->firstOrFail();

            if ($game->ended_at !== null || $game->status !== GameStatus::Running) {
                throw new LogicException(sprintf(
                    'ScheduleRound : la partie est %s ; seule une partie en cours programme une manche.',
                    $game->status->value,
                ));
            }

            $locked = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RoundStatus::Pending) {
                throw new LogicException(sprintf(
                    'ScheduleRound : la manche %d est %s ; son origine est immuable dès T₁ (contrat C7 § 4.2).',
                    $locked->sequence_index,
                    $locked->status->value,
                ));
            }

            if ($locked->round_number === null) {
                throw new LogicException(sprintf(
                    'ScheduleRound : la manche %d est une manche de réserve ; elle n’est programmée qu’après avoir reçu un numéro (E10-45).',
                    $locked->sequence_index,
                ));
            }

            $locked->forceFill(['started_at' => $startsAt])->save();

            $firstTier = RoundTier::query()
                ->where('round_id', $locked->id)
                ->where('tier_index', 1)
                ->firstOrFail();

            $this->mint->handle($firstTier, Date::now()->toImmutable());

            // Relue telle qu'écrite (milliseconde de `timestamp(3)`), statut
            // compris : la frappe a pu annuler la manche.
            $locked->refresh();
            $round->forceFill($locked->only(['status', 'started_at', 'updated_at']))
                ->syncOriginalAttributes(['status', 'started_at', 'updated_at']);

            if ($locked->status !== RoundStatus::Pending) {
                return;
            }

            $scheduledAt = $locked->started_at ?? throw new LogicException('ScheduleRound : origine de temps perdue à l’écriture.');

            AdvanceRound::dispatch($game->id, $locked->id, RoundStep::OpenTier, 1, WireTime::iso($scheduledAt));

            // Garde de mode (§ 11.2) : aucune diffusion en solo. Charge
            // précalculée sous le verrou, `serverNow` pris à l'émission.
            if ($game->mode === GameMode::Multiplayer) {
                $locked->setRelation('game', $game);

                RoundScheduled::dispatch(self::room($game), $game, [
                    'round' => RoundTimelinePresenter::timeline($game, $locked),
                    'image' => TierImageRefPresenter::image($game, $firstTier->setRelation('round', $locked)),
                ]);
            }
        });
    }

    /**
     * @throws LogicException
     */
    private static function room(Game $game): Room
    {
        return $game->room ?? throw new LogicException('ScheduleRound : partie multijoueur sans salon.');
    }
}
