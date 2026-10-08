<?php

namespace App\Jobs\Game;

use App\Actions\Game\CancelRound;
use App\Enums\GameStatus;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Round;
use App\Models\RoundTier;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * L'annulation ACTIVE des manches touchées par une suspension ou un retrait
 * — spec 60 § 15.4, spec 20 § 8.5 et § 11.2, contrat C8, R-33, E10-08 ;
 * lot L60-17 (J2).
 *
 * Dispatché **après commit** du geste de l'administrateur
 * ({@see ShouldQueueAfterCommit}), sur la file `game` :
 *
 * - **film** (`$movieId`) : toute manche `running`, ou `pending` programmée
 *   (`started_at` posé), de ce film, dans une partie en cours, est annulée
 *   avec `$reason` (`movie_suspended` ou `movie_withdrawn`) puis remplacée
 *   par {@see CancelRound} (§ 15.2) ;
 * - **image seule** (`$frameId`) : la manche est annulée (`frame_unavailable`)
 *   si l'image est la `served_frame` d'un palier **déjà frappé** ; sinon la
 *   frappe suivante substitue, comme pour une dépublication (§ 15.3) ;
 * - une manche en révélation n'est jamais annulée : la partie (1) du
 *   prédicat de service refuse ses URL, et une resynchronisation les omet.
 *
 * **Une partie en pause n'est pas touchée** (`CancelRound` refuse une partie
 * qui n'est pas en cours) : sa suite est déprogrammée, et la reprise la
 * reprogramme par `ScheduleRound`, dont la frappe du palier 1 refuse une
 * image devenue non servable — substitution au même niveau, sinon
 * annulation `no_variant_available` (§ 15.1). Forme livrée — amendé le 08/10.
 *
 * Chaque manche est relue sous les verrous `game` puis `round` (ordre du
 * § 4.5) dans sa propre transaction : une manche passée entre-temps en
 * révélation, close, ou une partie gelée ou en pause est laissée telle
 * quelle. **Idempotent** : une manche déjà annulée n'est plus visée.
 */
final class WithdrawContentFromLiveRounds implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Un seul essai : un job du moteur ne se rejoue jamais (`--tries=1`). */
    public int $tries = 1;

    /**
     * @throws InvalidArgumentException ni film ni image, ou les deux.
     */
    public function __construct(
        public readonly ?int $movieId,
        public readonly ?int $frameId,
        public readonly RoundIncidentReason $reason,
    ) {
        if (($movieId === null) === ($frameId === null)) {
            throw new InvalidArgumentException('WithdrawContentFromLiveRounds : un film OU une image, jamais les deux ni aucun.');
        }

        $this->onQueue('game');
    }

    /**
     * @throws Throwable
     */
    public function handle(CancelRound $cancel): void
    {
        foreach ($this->targetRoundIds() as $roundId) {
            DB::transaction(function () use ($roundId, $cancel): void {
                $candidate = Round::query()->find($roundId);

                if (! $candidate instanceof Round) {
                    return;
                }

                $game = Game::query()->whereKey($candidate->game_id)->lockForUpdate()->first();

                if (! $game instanceof Game || $game->status !== GameStatus::Running || $game->ended_at !== null) {
                    return;
                }

                $round = Round::query()->whereKey($roundId)->lockForUpdate()->firstOrFail();

                if (! $this->stillTargeted($round)) {
                    return;
                }

                $cancel->handle($round, $this->reason, Date::now()->toImmutable());
            });
        }
    }

    /**
     * Les manches visées, lues sans verrou : chacune est relue sous verrou
     * avant d'être annulée.
     *
     * @return list<int>
     */
    private function targetRoundIds(): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            $this->live(Round::query())
                ->when(
                    $this->movieId !== null,
                    fn (Builder $query): Builder => $query->where('movie_id', $this->movieId),
                    fn (Builder $query): Builder => $query->whereIn('id', $this->mintedTiersOfFrame()),
                )
                ->whereIn('game_id', Game::query()
                    ->select('id')
                    ->where('status', GameStatus::Running->value)
                    ->whereNull('ended_at'))
                ->orderBy('game_id')
                ->orderBy('sequence_index')
                ->pluck('id')
                ->all(),
        ));
    }

    /**
     * La manche relue sous verrou est-elle encore à annuler ?
     */
    private function stillTargeted(Round $round): bool
    {
        $live = $this->live(Round::query()->whereKey($round->id))->exists();

        if (! $live) {
            return false;
        }

        if ($this->movieId !== null) {
            return $round->movie_id === $this->movieId;
        }

        return RoundTier::query()
            ->where('round_id', $round->id)
            ->whereIn('id', $this->mintedTiersOfFrame(select: 'id'))
            ->exists();
    }

    /**
     * Une manche `running`, ou `pending` programmée et numérotée.
     *
     * @param  Builder<Round>  $query
     * @return Builder<Round>
     */
    private function live(Builder $query): Builder
    {
        return $query
            ->whereNotNull('round_number')
            ->where(static function (Builder $live): void {
                $live->where('status', RoundStatus::Running->value)
                    ->orWhere(static function (Builder $pending): void {
                        $pending->where('status', RoundStatus::Pending->value)
                            ->whereNotNull('started_at');
                    });
            });
    }

    /**
     * Les paliers déjà frappés dont l'image servie est celle visée.
     *
     * @return Builder<RoundTier>
     */
    private function mintedTiersOfFrame(string $select = 'round_id'): Builder
    {
        return RoundTier::query()
            ->select($select)
            ->where('served_frame_id', $this->frameId)
            ->whereNotNull('serve_token');
    }
}
