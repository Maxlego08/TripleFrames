<?php

namespace App\Console\Commands;

use App\Actions\Game\CatchUpGame;
use App\Actions\Game\FinalizeGame;
use App\Enums\GameStatus;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\EngineConstants;
use App\Support\Game\GameJournal;
use App\Support\Game\GamesInProgress;
use App\Support\Game\RoundStep;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `game:reschedule` — spec 60 § 17.5 et § 14.4, contrat C17 § 2 et § 4.5
 * (nom et signature figés).
 *
 * Redonne sa **terminaison bornée** (§ 17.3, point 2) à toute partie en cours
 * — solo compris — dont les jobs ont été perdus : perte de Redis, ou second
 * échec d'une même transition (§ 4.6). Pour chaque partie en cours :
 *
 * 1. **partie bloquée** (§ 14.4) — `now > started_at + maxNaturalDurationMs()
 *    + total_paused_ms + pauseTimeoutMs` : elle ne peut plus légitimement
 *    tourner, et le gel la clôt par `FinalizeGame::handle($game,
 *    GameStatus::Interrupted, FinalizeGame::lastKnownActivity($game, now))`
 *    (contrat C13 § 4.5, E10-62) — sa dernière activité connue, jamais
 *    « maintenant », sans quoi une partie ancienne repartirait pour douze mois
 *    de conservation. Aucun rattrapage n'est joué avant : il écrirait des
 *    manches qu'aucun joueur n'a vues. Une partie en pause qui porte son
 *    `paused_at` n'est jamais bloquée : sa fin est déjà fixée à `paused_at +
 *    pauseTimeoutMs` (§ 14.3), quelle que soit l'heure de passage (E96-6) ;
 * 2. sinon **rattrapage** — `CatchUpGame($game, now)`, qui exécute toute étape
 *    échue par sa transition réelle, aux instants théoriques, clôture d'une
 *    pause échue comprise ;
 * 3. puis **dispatch du job de la prochaine étape non échue**, lue dans
 *    {@see CatchUpGame::nextStep()} — le rattrapage et la commande lisent la
 *    même définition : pour une partie en pause, {@see InterruptPausedGame}
 *    armé sur son `paused_at` ; pour une partie en cours, un
 *    {@see AdvanceRound}.
 *
 * **Idempotente** : rejouée, elle n'écrit rien de plus (rien n'est échu, une
 * partie close n'est plus en cours) et ne dispatche que le même job. Les
 * doublons sont inoffensifs (§ 4.2) : le job ne décide rien, il réveille le
 * rattrapage, qui ne fait rien s'il n'y a rien d'échu ; `InterruptPausedGame`
 * ne fait rien si `paused_at` a changé ou si la partie n'est plus en pause.
 * Elle ne touche ni au lobby, ni au drapeau de drainage, qui ne bloque jamais
 * une partie en cours (§ 17.3, point 4).
 *
 * **Quand** : `deploy:drain` l'appelle **avant** d'attendre (contrat
 * C18-bis), pour qu'aucune partie aux jobs perdus ne bloque le drainage
 * jusqu'à l'échéance ; **obligatoire après toute restauration de Redis**
 * (A-31, A-56 ; procédure de 100). Jamais planifiée : le chrono de jeu repose
 * sur un job par frontière (règle 8).
 *
 * **Une partie en échec n'arrête pas les autres** : l'exception est rapportée,
 * la commande passe à la suivante et sort en code 1 ; code 0 sinon.
 *
 * **Sortie** : les lignes de {@see GamesInProgress::summary()} après le
 * passage — des données (modes, statuts, `IsoMs`, comptes), jamais du texte
 * et jamais une donnée de joueur ; rien quand aucune partie n'est en cours.
 * Le journal `game` porte la clôture d'une partie bloquée
 * ({@see GameJournal::gameFinalized()}), les transitions rattrapées ayant
 * leurs propres lignes (§ 4.7).
 */
class GameRescheduleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'game:reschedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rattrape chaque partie en cours, lui redonne le job de sa prochaine étape et clôt une partie bloquée';

    public function handle(CatchUpGame $catchUp, FinalizeGame $finalize): int
    {
        $failed = false;

        $games = Game::query()->inProgress()->orderBy('id')->get(['id']);

        foreach ($games as $game) {
            try {
                $this->reschedule($game->id, Date::now()->toImmutable(), $catchUp, $finalize);
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
            }
        }

        $summary = GamesInProgress::summary();

        if ($summary !== []) {
            $this->table(array_keys($summary[0]), $summary);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Une partie : clôture si elle est bloquée ; sinon rattrapage, puis job de
     * la prochaine étape.
     */
    private function reschedule(int $gameId, CarbonImmutable $now, CatchUpGame $catchUp, FinalizeGame $finalize): void
    {
        if ($this->closeIfBlocked($gameId, $now, $finalize)) {
            return;
        }

        $game = self::inProgress($gameId);

        if (! $game instanceof Game) {
            return;
        }

        $catchUp->handle($game, $now);

        // Relue par la seule définition de la prochaine étape : le rattrapage
        // a pu mettre la partie en pause, ou la clore (`null`).
        $step = CatchUpGame::nextStep($gameId);

        if ($step === null) {
            return;
        }

        if ($step['kind'] === CatchUpGame::INTERRUPT) {
            $pausedAt = $step['game']->paused_at;

            if ($pausedAt !== null) {
                InterruptPausedGame::dispatch($gameId, WireTime::iso($pausedAt), WireTime::iso($step['at']));
            }

            return;
        }

        $roundStep = RoundStep::tryFrom($step['kind']);
        $round = $step['round'];
        $tier = $step['tier'];

        if (! $roundStep instanceof RoundStep || ! $round instanceof Round) {
            return;
        }

        AdvanceRound::dispatch(
            $gameId,
            $round->id,
            $roundStep,
            $roundStep->targetsTier() && $tier instanceof RoundTier ? $tier->tier_index : null,
            WireTime::iso($step['at']),
        );
    }

    /**
     * La clôture d'une partie bloquée (§ 14.4), décidée et écrite sous le
     * verrou `game` : partie relue en `FOR UPDATE` d'abord (ordre global game
     * → round, § 4.5), dernière activité lue ensuite, puis le gel, qui
     * s'emboîte dans cette transaction.
     *
     * **Une partie en pause qui porte son `paused_at` n'est jamais bloquée**
     * (journal E96-6, au porteur) : sa fin est déjà déterminée,
     * `paused_at + pauseTimeoutMs` (§ 14.3, 80 § 10.5), et le rattrapage la
     * gèle à cet instant, ou `InterruptPausedGame` est réarmé tant qu'une
     * reprise reste permise (§ 14.2). La geler ici à `paused_at` rendrait sa
     * date de fin fonction de l'heure de passage de la commande, et
     * couperait une reprise encore permise.
     *
     * @return bool vrai si la partie était bloquée (gelée par cet appel ou par
     *              un gel concurrent), faux sinon
     */
    private function closeIfBlocked(int $gameId, CarbonImmutable $now, FinalizeGame $finalize): bool
    {
        return DB::transaction(static function () use ($gameId, $now, $finalize): bool {
            $lockedGame = Game::query()->whereKey($gameId)->lockForUpdate()->first();

            if (! $lockedGame instanceof Game
                || $lockedGame->ended_at !== null
                || ! in_array($lockedGame->status, [GameStatus::Running, GameStatus::Paused], true)
                || ($lockedGame->status === GameStatus::Paused && $lockedGame->paused_at !== null)
                || ! self::isBlocked($lockedGame, $now)) {
                return false;
            }

            $endedAt = FinalizeGame::lastKnownActivity($lockedGame, $now);

            if ($finalize->handle($lockedGame, GameStatus::Interrupted, $endedAt)) {
                GameJournal::gameFinalized($lockedGame, GameStatus::Interrupted, $endedAt);
            }

            return true;
        });
    }

    /**
     * Partie bloquée (§ 14.4) : `now > started_at + maxNaturalDurationMs() +
     * total_paused_ms + pauseTimeoutMs + manual_paused_ms`. La marge
     * `pauseTimeoutMs` couvre les décomptes de reprise des pauses `empty`, que
     * `total_paused_ms` ne compte pas (§ 17.3, point 3 ; garde
     * d'`EngineConstants`) ; `manual_paused_ms`, qui impute à chaque reprise
     * manuelle son décompte, couvre ceux des pauses manuelles (D64 du 07/10)
     * — il recompte leur attente, ce qui ne fait qu'élargir le seuil.
     */
    private static function isBlocked(Game $game, CarbonImmutable $now): bool
    {
        $limit = $game->started_at->addMilliseconds(
            GamesInProgress::maxNaturalDurationMs() + $game->total_paused_ms + EngineConstants::pauseTimeoutMs() + $game->manual_paused_ms,
        );

        return $now->greaterThan($limit);
    }

    /** La partie relue en base, si elle est encore en cours. */
    private static function inProgress(int $gameId): ?Game
    {
        return Game::query()->inProgress()->whereKey($gameId)->first();
    }
}
