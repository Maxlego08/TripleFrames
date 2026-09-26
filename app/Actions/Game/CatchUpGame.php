<?php

namespace App\Actions\Game;

use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Jobs\Game\AdvanceRound;
use App\Models\Game;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\EngineConstants;
use App\Support\Game\GameJournal;
use App\Support\Game\RoundStep;
use App\Support\Game\TransitionBroadcasts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Le rattrapage synchrone d'une partie — spec 60 § 4.4, contrat C7 § 2.5 et
 * § 4.5 (nom et signature figés, consommé par 70 et 100).
 *
 * Exécute **toutes les étapes échues à `$now`, dans l'ordre chronologique**,
 * chacune par sa transition réelle et donc dans sa propre transaction, jusqu'à
 * ce qu'aucune ne soit plus due :
 *
 * 1. partie close : rien ;
 * 2. partie en pause : seulement la clôture, si `now ≥ paused_at +
 *    pauseTimeoutMs` (§ 14.3) — `FinalizeGame(Interrupted)` à cette
 *    échéance, jamais à l'heure du rattrapage ;
 * 3. sinon, pour la manche courante puis la suivante : `OpenTier(1..N)`
 *    échus, `Close` à `started_at + D`, `Reveal` à `ended_at +
 *    tier_grace_ms`, `EndReveal` à `reveal_ends_at`, et ainsi de suite tant
 *    que la manche suivante programmée a elle-même des étapes échues.
 *
 * **L'ordre** est celui des instants théoriques ; à instant égal,
 * `EndReveal(k)` précède `OpenTier(k+1, 1)` (§ 4.1, contrat C7 § 4.14) —
 * à l'intérieur d'une manche, deux étapes ne tombent jamais au même instant.
 * Un palier dont `Tᵢ ≥ ended_at` (fin anticipée) n'est jamais une étape : il
 * ne s'ouvrira pas. **Le rattrapage ne décide rien** : il désigne l'étape due,
 * et la transition relit l'état sous ses verrous, revérifie validité,
 * échéance et idempotence (§ 4.3), et écrit les instants théoriques — un
 * rattrapage tardif écrit donc exactement ce qu'un job à l'heure aurait
 * écrit. Un passage qui ne trouve rien d'échu ne fait rien : doublons, jobs
 * rejoués ou périmés sont inoffensifs.
 *
 * **Diffusion après rattrapage** : le passage entier s'exécute sous
 * {@see TransitionBroadcasts::coalesce()} — il n'émet que l'état courant (par
 * manche touchée, l'événement de la dernière étape exécutée ; jamais
 * périmés : `round.cancelled`, `game.paused`, `game.ended`, `player.locked`).
 *
 * **Appelants** : le job {@see AdvanceRound}, 70 avant tout jugement
 * (contrat C10, étape S3), `room.state` et `solo.state` (§ 12),
 * `game:reschedule` (§ 17.5), les gestes {@see AdvanceToNextRound},
 * `RevealSoloAnswer` et `SkipSoloRound` avant de lire la phase, et
 * `StartSoloGame` avant d'interrompre la partie solo en cours (§ 16.6).
 * Appelé hors de toute transaction, chaque étape valide la sienne ; appelé
 * dans une transaction englobante, les étapes s'y emboîtent.
 *
 * **Borne** : chaque étape exécutée fait avancer l'état ; une étape désignée
 * deux fois de suite (transition qui n'a rien pu écrire) arrête le passage,
 * jamais une boucle — les filets suivants la reprennent.
 *
 * @phpstan-type EngineStep array{kind: string, at: CarbonImmutable, game: Game, round: Round|null, tier: RoundTier|null}
 */
final readonly class CatchUpGame
{
    /** Étape de partie : la clôture d'une pause échue ({@see self::nextStep()}). */
    public const string INTERRUPT = 'interrupt';

    public function __construct(
        private OpenTier $openTier,
        private CloseRound $closeRound,
        private RevealRound $revealRound,
        private EndReveal $endReveal,
        private FinalizeGame $finalize,
        private TransitionBroadcasts $broadcasts,
    ) {}

    /**
     * @param  CarbonImmutable  $now  Instant du rattrapage : échéance de chaque étape.
     */
    public function handle(Game $game, CarbonImmutable $now): void
    {
        $this->broadcasts->coalesce(function () use ($game, $now): void {
            $previous = null;

            while (($step = self::nextDueStep($game->id, $now)) !== null) {
                $identity = implode(':', [$step['kind'], $step['round']?->id, $step['tier']?->tier_index]);

                if ($identity === $previous) {
                    return;
                }

                $previous = $identity;
                $this->execute($step, $now);
            }
        });
    }

    /**
     * Exécute l'étape désignée par sa transition réelle.
     *
     * @param  EngineStep  $step
     */
    private function execute(array $step, CarbonImmutable $now): void
    {
        $round = $step['round'];

        if ($step['kind'] === self::INTERRUPT) {
            $this->interrupt($step['game'], $step['at']);

            return;
        }

        if (! $round instanceof Round) {
            return;
        }

        if ($step['kind'] === RoundStep::OpenTier->value && $step['tier'] instanceof RoundTier) {
            $this->openTier->handle($step['tier'], $now);
        } elseif ($step['kind'] === RoundStep::Close->value) {
            // L'instant théorique de la clôture à `D`, jamais `now`.
            $this->closeRound->handle($round, $step['at']);
        } elseif ($step['kind'] === RoundStep::Reveal->value) {
            $this->revealRound->handle($round, $now);
        } elseif ($step['kind'] === RoundStep::EndReveal->value) {
            $this->endReveal->handle($round, $now);
        }
    }

    /**
     * La prochaine étape échue de la partie, relue en base — ou `null`.
     *
     * @return EngineStep|null
     */
    private static function nextDueStep(int $gameId, CarbonImmutable $now): ?array
    {
        $step = self::nextStep($gameId);

        return $step !== null && $step['at']->lessThanOrEqualTo($now) ? $step : null;
    }

    /**
     * La prochaine étape de la partie, **échue ou non**, relue en base, avec
     * son instant théorique — ou `null` (partie close ou disparue, ou sans
     * étape programmée).
     *
     * Partie en pause : la clôture à `paused_at + pauseTimeoutMs` (`kind` =
     * {@see self::INTERRUPT}). Partie en cours : la première étape de manche
     * dans l'ordre du rattrapage (instant, puis fin de révélation d'abord à
     * instant égal), `kind` = valeur d'un {@see RoundStep}. Seule définition
     * de « la prochaine étape » : le rattrapage la lit pour exécuter ce qui est
     * échu, et `game:reschedule` pour redonner à la partie le job de l'étape
     * qui n'est pas encore échue (§ 17.5, lot L60-10). Elle ne décide rien.
     *
     * @return EngineStep|null
     */
    public static function nextStep(int $gameId): ?array
    {
        $game = Game::query()->find($gameId);

        if (! $game instanceof Game || $game->ended_at !== null) {
            return null;
        }

        if ($game->status === GameStatus::Paused) {
            if ($game->paused_at === null) {
                return null;
            }

            $interruptsAt = $game->paused_at->addMilliseconds(EngineConstants::pauseTimeoutMs());

            return ['kind' => self::INTERRUPT, 'at' => $interruptsAt, 'game' => $game, 'round' => null, 'tier' => null];
        }

        if ($game->status !== GameStatus::Running) {
            return null;
        }

        $rounds = Round::query()
            ->where('game_id', $game->id)
            ->where(static function (Builder $query): void {
                $query->whereIn('status', [RoundStatus::Running->value, RoundStatus::Revealing->value])
                    ->orWhere(static function (Builder $pending): void {
                        $pending->where('status', RoundStatus::Pending->value)->whereNotNull('started_at');
                    });
            })
            ->orderBy('sequence_index')
            ->get();

        $candidates = [];

        foreach ($rounds as $round) {
            $candidate = self::roundStep($game, $round);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Ordre chronologique ; à instant égal, la fin de révélation d'abord.
        usort($candidates, static function (array $left, array $right): int {
            $byInstant = (int) $left['at']->format('Uu') <=> (int) $right['at']->format('Uu');

            return $byInstant !== 0 ? $byInstant : self::rank($left['kind']) <=> self::rank($right['kind']);
        });

        return $candidates[0];
    }

    /**
     * L'étape suivante d'une manche programmée, en cours ou en révélation,
     * avec son instant théorique — échue ou non.
     *
     * @return array{kind: string, at: CarbonImmutable, game: Game, round: Round, tier: RoundTier|null}|null
     */
    private static function roundStep(Game $game, Round $round): ?array
    {
        $startedAt = $round->started_at;

        if ($startedAt === null) {
            return null;
        }

        if ($round->status === RoundStatus::Revealing) {
            return $round->reveal_ends_at === null
                ? null
                : ['kind' => RoundStep::EndReveal->value, 'at' => $round->reveal_ends_at, 'game' => $game, 'round' => $round, 'tier' => null];
        }

        // Programmée ou en cours : le premier palier non ouvert qui s'ouvrira
        // encore (`Tᵢ` avant la clôture), sinon la clôture ou la révélation.
        $closesAt = $round->ended_at ?? $startedAt->addMilliseconds($round->duration_ms);

        $tier = RoundTier::query()
            ->where('round_id', $round->id)
            ->whereNull('served_at')
            ->orderBy('tier_index')
            ->get()
            ->first(static fn (RoundTier $tier): bool => $startedAt->addMilliseconds($tier->starts_at_offset_ms)->lessThan($closesAt));

        if ($tier instanceof RoundTier) {
            return [
                'kind' => RoundStep::OpenTier->value,
                'at' => $startedAt->addMilliseconds($tier->starts_at_offset_ms),
                'game' => $game,
                'round' => $round,
                'tier' => $tier,
            ];
        }

        if ($round->status !== RoundStatus::Running) {
            return null;
        }

        return $round->ended_at === null
            ? ['kind' => RoundStep::Close->value, 'at' => $closesAt, 'game' => $game, 'round' => $round, 'tier' => null]
            : ['kind' => RoundStep::Reveal->value, 'at' => $round->ended_at->addMilliseconds($game->tier_grace_ms), 'game' => $game, 'round' => $round, 'tier' => null];
    }

    /** À instant égal, la fin de révélation précède toute autre étape (§ 4.1). */
    private static function rank(string $kind): int
    {
        return $kind === RoundStep::EndReveal->value ? 0 : 1;
    }

    /**
     * La clôture d'une partie en pause à son échéance, `paused_at +
     * pauseTimeoutMs`, relue sous le verrou `game` — même règle
     * qu'`InterruptPausedGame` (§ 14.3), qu'elle double quand le job est perdu
     * ou en retard : l'instant prévu, jamais l'heure du rattrapage.
     */
    private function interrupt(Game $game, CarbonImmutable $interruptsAt): void
    {
        DB::transaction(function () use ($game, $interruptsAt): void {
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->first();

            if (! $lockedGame instanceof Game
                || $lockedGame->ended_at !== null
                || $lockedGame->status !== GameStatus::Paused
                || $lockedGame->paused_at === null
                || ! $lockedGame->paused_at->addMilliseconds(EngineConstants::pauseTimeoutMs())->equalTo($interruptsAt)) {
                return;
            }

            if ($this->finalize->handle($lockedGame, GameStatus::Interrupted, $interruptsAt)) {
                GameJournal::gameFinalized($lockedGame, GameStatus::Interrupted, $interruptsAt);
            }
        });
    }
}
