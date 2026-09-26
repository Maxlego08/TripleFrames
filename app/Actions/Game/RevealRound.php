<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Events\Game\RoundRevealed;
use App\Jobs\Game\AdvanceRound;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundTier;
use App\Support\Game\RevealMovieBuilder;
use App\Support\Game\RoundStep;
use App\Support\Game\TierImageRefPresenter;
use App\Support\Realtime\WireTime;
use App\Support\Scoring\Scoreboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le début de la révélation d'une manche — spec 60 § 9.4 et § 9.5, contrat
 * C7 § 4.6 et § 4.14 (action interne, nom donné par le contrat).
 *
 * À `ended_at + tier_grace_ms`, sous les verrous `game` → manche `k` →
 * manche `k+1` (ordre global du § 4.5) :
 *
 * 1. `round.status = revealing` : points et `tier_index` de la manche
 *    deviennent **publiables** (E10-52), et plus aucune soumission n'est
 *    recevable (fenêtre d'acceptation de 70 : `round.status = running`) ;
 * 2. s'il reste une manche à jouer : `ScheduleRound(k+1, reveal_ends_at(k))`
 *    — **`T₁(k+1) = reveal_ends_at(k)`**, sans intervalle (§ 5.3) : son
 *    palier 1 devient servable dans les `preload_lead_ms` finales de `R`, et
 *    lui seul ;
 * 3. après commit : en multijoueur, `round.revealed` (D14 du 23/09 : titres
 *    de toutes les locales par {@see RevealMovieBuilder}, images des seuls
 *    paliers ouverts, trouvailles et classement intermédiaire), **puis**
 *    `round.scheduled` de `k+1` ; le job `EndReveal` à `reveal_ends_at(k)`.
 *
 * **Jamais avant `ended_at + tier_grace_ms`** : aucune soumission reçue
 * après l'émission des titres n'est donc acceptable (§ 2.3). Une étape non
 * échue, périmée (partie close ou en pause, manche qui ne court pas ou n'est
 * pas close) ou déjà faite (`revealing`, `completed`) n'écrit rien.
 *
 * **Ordre sur le fil** : l'étape 1 et l'émission de `round.revealed` vivent
 * dans une transaction IMBRIQUÉE, validée avant la programmation de `k+1`
 * ({@see self::markRevealing()}) : les rappels après commit d'une transaction
 * imbriquée partent avant ceux de ses aînées validées plus tard (E90-4) —
 * sans elle, `round.scheduled(k+1)`, voire `round.cancelled(k+1)` si la
 * frappe du palier 1 l'annule, partiraient avant les titres de `k` (E90-7).
 */
final readonly class RevealRound
{
    /**
     * Colonnes que la révélation écrit, recopiées sur l'instance de l'appelant.
     *
     * @var list<string>
     */
    private const array REVEALED_COLUMNS = ['status', 'updated_at'];

    public function __construct(private ScheduleRound $schedule) {}

    /**
     * @param  CarbonImmutable  $now  Instant d'exécution : l'étape n'est échue qu'à `ended_at + tier_grace_ms`.
     */
    public function handle(Round $round, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($round, $now): void {
            $lockedGame = Game::query()->whereKey($round->game_id)->lockForUpdate()->firstOrFail();
            $revealedRound = self::markRevealing($lockedGame, $round, $now);

            if (! $revealedRound instanceof Round) {
                return;
            }

            $revealEndsAt = $revealedRound->reveal_ends_at
                ?? throw new LogicException('RevealRound : manche close sans fin de révélation.');

            $next = self::nextToPlay($lockedGame);

            if ($next instanceof Round) {
                $this->schedule->handle($next, $revealEndsAt);
            }

            AdvanceRound::dispatch($lockedGame->id, $revealedRound->id, RoundStep::EndReveal, null, WireTime::iso($revealEndsAt));
        });
    }

    /**
     * Étape 1, et l'émission de `round.revealed`, dans une transaction
     * imbriquée validée avant la programmation de la manche suivante.
     *
     * @return Round|null la manche passée en `revealing`, relue sous son
     *                    verrou ; `null` si l'étape n'est pas à faire.
     */
    private static function markRevealing(Game $lockedGame, Round $round, CarbonImmutable $now): ?Round
    {
        return DB::transaction(static function () use ($lockedGame, $round, $now): ?Round {
            $lockedRound = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();

            if (! self::isDue($lockedGame, $lockedRound, $now)) {
                self::reflect($round, $lockedRound);

                return null;
            }

            $lockedRound->forceFill(['status' => RoundStatus::Revealing])->save();

            self::reflect($round, $lockedRound);

            // Garde de mode (§ 11.2) : aucune diffusion en solo. Charge
            // précalculée sous le verrou, points publiables dès `revealing`.
            if ($lockedGame->mode === GameMode::Multiplayer) {
                RoundRevealed::dispatch(self::room($lockedGame), $lockedGame, self::payload($lockedGame, $lockedRound));
            }

            return $lockedRound;
        });
    }

    /**
     * Valide et échue (§ 4.3) : partie en cours, manche `running` close, et
     * `ended_at + tier_grace_ms ≤ now`.
     */
    private static function isDue(Game $lockedGame, Round $lockedRound, CarbonImmutable $now): bool
    {
        if ($lockedGame->ended_at !== null || $lockedGame->status !== GameStatus::Running) {
            return false;
        }

        if ($lockedRound->status !== RoundStatus::Running || $lockedRound->ended_at === null) {
            return false;
        }

        return $lockedRound->ended_at->addMilliseconds($lockedGame->tier_grace_ms)->lessThanOrEqualTo($now);
    }

    /**
     * La charge de `round.revealed` (§ 11.5) : titres par le seul
     * constructeur de `RevealMovie`, une `TierImageRef` par palier **ouvert**
     * (`served_at` non nul), par `tier_index` — jamais un palier non ouvert
     * (D14 du 23/09) —, trouvailles et classement en portée publiable.
     *
     * @return array<string, mixed>
     */
    private static function payload(Game $lockedGame, Round $lockedRound): array
    {
        $revealEndsAt = $lockedRound->reveal_ends_at
            ?? throw new LogicException('RevealRound : manche close sans fin de révélation.');

        $lockedRound->setRelation('game', $lockedGame);

        $images = RoundTier::query()
            ->where('round_id', $lockedRound->id)
            ->whereNotNull('served_at')
            ->orderBy('tier_index')
            ->get()
            ->map(static fn (RoundTier $tier): array => TierImageRefPresenter::image(
                $lockedGame,
                $tier->setRelation('round', $lockedRound),
            ))
            ->values()
            ->all();

        return [
            'sequenceIndex' => $lockedRound->sequence_index,
            'roundNumber' => (int) $lockedRound->round_number,
            'revealEndsAt' => WireTime::iso($revealEndsAt),
            'movie' => RevealMovieBuilder::build(Movie::query()->findOrFail($lockedRound->movie_id)),
            'images' => $images,
            'finders' => Scoreboard::roundFinders($lockedRound),
            'leaderboard' => Scoreboard::leaderboard($lockedGame, $lockedRound),
        ];
    }

    /**
     * La manche suivante à jouer (§ 1.2), verrouillée — ou `null` : c'était
     * la dernière.
     */
    private static function nextToPlay(Game $lockedGame): ?Round
    {
        $candidate = Round::query()
            ->where('game_id', $lockedGame->id)
            ->toPlay()
            ->first();

        return $candidate instanceof Round
            ? Round::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail()
            : null;
    }

    /**
     * @throws LogicException
     */
    private static function room(Game $game): Room
    {
        return $game->room ?? throw new LogicException('RevealRound : partie multijoueur sans salon.');
    }

    /**
     * Recopie sur l'instance de l'appelant ce que porte la manche relue, sans
     * rien réécrire en base.
     */
    private static function reflect(Round $round, Round $lockedRound): void
    {
        $round->forceFill($lockedRound->only(self::REVEALED_COLUMNS))
            ->syncOriginalAttributes(self::REVEALED_COLUMNS);
    }
}
