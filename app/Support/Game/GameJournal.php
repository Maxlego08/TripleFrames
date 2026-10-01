<?php

namespace App\Support\Game;

use App\Enums\GameStatus;
use App\Enums\RoundIncidentReason;
use App\Models\Game;
use App\Models\Round;
use App\Support\Perf\GameTraceWriter;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Le journal du moteur sur le canal `game` — spec 60 § 4.7, exigence de 100
 * § 10.9 (lignes JSON, 14 jours, processeur `RedactPersonalData`).
 *
 * **Ce qui y part** (liste de 60, seul à choisir) : ouverture de manche
 * (`OpenTier(1)`) et clôture (`CloseRound`, avec sa cause : `D` ou fin
 * anticipée) ; substitutions (`MintTierServeToken`) ; annulations
 * (`CancelRound`, motif, remplacée ou non) ; pauses et reprises ; clôtures de
 * partie décidées par 60 (issue) ; resynchronisations de partie (une ligne
 * par requête, rien du paquet) ; **retard réel de chaque diffusion de
 * frontière** (`round.scheduled`, `tier.opened`, `round.closed`,
 * `round.revealed` : `delayMs` = `serverNow` d'émission − instant théorique de
 * l'étape émettrice, mesuré dans `broadcastWith()` des bases, critère 1 du
 * test de charge D33 du 23/09) ; diffusions en échec (`ShouldRescue`) ; second
 * échec d'une transition (§ 4.6).
 *
 * **Sans donnée de joueur** : une partie s'y désigne par `gameRef`, une manche
 * par `sequenceIndex` (et son numéro affiché), un palier par `tierIndex`, un
 * siège **jamais** — ni pseudo, ni `room_code`, ni `player_token`, ni IP, ni
 * identifiant interne. **Jamais** un titre, un alias, une chaîne du QCM, une
 * saisie ni un score d'autrui. Clés en camelCase, comme le fil.
 *
 * **Jamais bloquant** : un échec d'écriture du journal est avalé ; il
 * n'annule jamais une transition. **Après commit** : une ligne émise dans une
 * transaction ne part qu'à son commit ({@see DB::afterCommit()}), et jamais
 * si elle est annulée — le journal ne raconte que ce qui a eu lieu. Hors
 * transaction (diffusion, job), elle part aussitôt.
 */
final class GameJournal
{
    /** Le canal de 100 § 10.9. */
    public const string CHANNEL = 'game';

    /** Libellés stables, pour la relecture et le test de charge. */
    public const string ROUND_OPENED = 'game.round_opened';

    public const string ROUND_CLOSED = 'game.round_closed';

    public const string TIER_SUBSTITUTED = 'game.tier_substituted';

    public const string ROUND_CANCELLED = 'game.round_cancelled';

    public const string GAME_PAUSED = 'game.paused';

    public const string GAME_RESUMED = 'game.resumed';

    public const string GAME_FINALIZED = 'game.finalized';

    public const string RESYNCHRONIZED = 'game.resynchronized';

    public const string BROADCAST_DELAY = 'game.broadcast_delay';

    public const string BROADCAST_FAILED = 'game.broadcast_failed';

    public const string TRANSITION_FAILED_TWICE = 'game.transition_failed_twice';

    /** Cause d'une clôture : la durée `D` écoulée. */
    public const string CLOSE_CAUSE_DURATION = 'duration';

    /** Cause d'une clôture : la fin anticipée (§ 9.2). */
    public const string CLOSE_CAUSE_EARLY_END = 'early_end';

    /**
     * La dernière diffusion préparée par une base d'événements — ce qu'une
     * diffusion en échec, rapportée par `ShouldRescue` sans l'événement,
     * désigne ({@see self::broadcastFailed()}). Les diffusions du moteur sont
     * synchrones (`ShouldBroadcastNow`) : entre `broadcastWith()` et l'échec
     * du diffuseur, aucune autre ne s'intercale.
     *
     * @var array{event: string, gameRef: string|null, sequenceIndex: int|null}|null
     */
    private static ?array $lastEmission = null;

    /** Ouverture d'une manche : `OpenTier(1)`, à son `T₁` théorique. */
    public static function roundOpened(Game $game, Round $round, CarbonImmutable $startedAt): void
    {
        self::write(LogLevel::INFO, self::ROUND_OPENED, [
            ...self::gameContext($game),
            ...self::roundContext($round),
            'startedAt' => WireTime::iso($startedAt),
        ]);

        GameTraceWriter::record($game->id, GameTraceWriter::ROUND_OPENED, $round->sequence_index, 1, $startedAt);
    }

    /**
     * Clôture d'une manche, avec sa cause : `D` écoulée, ou fin anticipée.
     *
     * @param  string  $cause  {@see self::CLOSE_CAUSE_DURATION} ou {@see self::CLOSE_CAUSE_EARLY_END}.
     */
    public static function roundClosed(Game $game, Round $round, string $cause, CarbonImmutable $closedAt): void
    {
        self::write(LogLevel::INFO, self::ROUND_CLOSED, [
            ...self::gameContext($game),
            ...self::roundContext($round),
            'cause' => $cause,
            'closedAt' => WireTime::iso($closedAt),
        ]);

        GameTraceWriter::record($game->id, GameTraceWriter::ROUND_CLOSED, $round->sequence_index, theoreticalAt: $closedAt, details: ['cause' => $cause]);
    }

    /** Substitution décidée à la frappe du jeton d'un palier (E10-25). */
    public static function tierSubstituted(Game $game, Round $round, int $tierIndex, RoundIncidentReason $reason): void
    {
        self::write(LogLevel::WARNING, self::TIER_SUBSTITUTED, [
            ...self::gameContext($game),
            ...self::roundContext($round),
            'tierIndex' => $tierIndex,
            'reason' => $reason->value,
        ]);

        GameTraceWriter::record($game->id, GameTraceWriter::TIER_SUBSTITUTED, $round->sequence_index, $tierIndex, details: ['reason' => $reason->value]);
    }

    /**
     * Annulation d'une manche (§ 15.2), remplacée par une manche de réserve ou
     * non (réserve épuisée : manche suivante à jouer, ou gel).
     */
    public static function roundCancelled(Game $game, Round $round, RoundIncidentReason $reason, bool $replaced): void
    {
        self::write(LogLevel::WARNING, self::ROUND_CANCELLED, [
            ...self::gameContext($game),
            ...self::roundContext($round),
            'reason' => $reason->value,
            'replaced' => $replaced,
        ]);

        GameTraceWriter::record($game->id, GameTraceWriter::ROUND_CANCELLED, $round->sequence_index, details: ['reason' => $reason->value, 'replaced' => $replaced]);
    }

    /** Mise en pause (§ 14.1), à l'instant théorique de la pause. */
    public static function gamePaused(Game $game, CarbonImmutable $pausedAt): void
    {
        self::write(LogLevel::INFO, self::GAME_PAUSED, [
            ...self::gameContext($game),
            'pausedAt' => WireTime::iso($pausedAt),
        ]);

        GameTraceWriter::record($game->id, GameTraceWriter::GAME_PAUSED, theoreticalAt: $pausedAt);
    }

    /** Reprise d'une partie en pause (§ 14.2, `ResumeGame`, L60-13). */
    public static function gameResumed(Game $game, CarbonImmutable $resumedAt): void
    {
        self::write(LogLevel::INFO, self::GAME_RESUMED, [
            ...self::gameContext($game),
            'resumedAt' => WireTime::iso($resumedAt),
        ]);

        GameTraceWriter::record($game->id, GameTraceWriter::GAME_RESUMED, theoreticalAt: $resumedAt);
    }

    /**
     * Clôture de partie décidée par 60 — fin normale, gel d'une annulation,
     * interruption d'une pause —, par le gel de 80 (`FinalizeGame`), avec son
     * issue. Appelé seulement quand l'appel a gelé la partie.
     */
    public static function gameFinalized(Game $game, GameStatus $outcome, CarbonImmutable $finalizedAt): void
    {
        self::write(LogLevel::INFO, self::GAME_FINALIZED, [
            ...self::gameContext($game),
            'outcome' => $outcome->value,
            'finalizedAt' => WireTime::iso($finalizedAt),
        ]);

        GameTraceWriter::record($game->id, GameTraceWriter::GAME_FINALIZED, theoreticalAt: $finalizedAt, details: ['outcome' => $outcome->value]);
    }

    /**
     * Une resynchronisation de partie (`room.state`, `solo.state` avec une
     * partie, L60-12) : une ligne par requête, rien du paquet.
     */
    public static function resynchronized(Game $game): void
    {
        self::write(LogLevel::INFO, self::RESYNCHRONIZED, self::gameContext($game));

        GameTraceWriter::record($game->id, GameTraceWriter::RESYNCHRONIZED);
    }

    /**
     * Une diffusion part : appelé par `broadcastWith()` des deux bases, à
     * l'instant d'émission. Retient la diffusion pour
     * {@see self::broadcastFailed()} et, pour une diffusion de frontière
     * (instant théorique connu), journalise son **retard réel** en
     * millisecondes entières.
     */
    public static function emitting(
        string $event,
        ?string $gameRef,
        ?int $sequenceIndex,
        ?int $tierIndex,
        CarbonImmutable $serverNow,
        ?CarbonImmutable $theoreticalAt,
        ?int $gameId = null,
    ): void {
        self::$lastEmission = ['event' => $event, 'gameRef' => $gameRef, 'sequenceIndex' => $sequenceIndex];

        if (! $theoreticalAt instanceof CarbonImmutable) {
            return;
        }

        // La chronologie technique (D47 du 01/10) : la même mesure, rattachée
        // à la partie par son identifiant, que ce journal ne porte jamais.
        if ($gameId !== null) {
            GameTraceWriter::record($gameId, GameTraceWriter::BROADCAST_PREFIX.$event, $sequenceIndex, $tierIndex, $theoreticalAt, $serverNow);
        }

        self::write(LogLevel::INFO, self::BROADCAST_DELAY, array_filter([
            'gameRef' => $gameRef,
            'event' => $event,
            'sequenceIndex' => $sequenceIndex,
            'tierIndex' => $tierIndex,
            'delayMs' => self::elapsedMs($theoreticalAt, $serverNow),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Une diffusion en échec (Reverb indisponible), rapportée puis avalée par
     * `ShouldRescue` (§ 4.6) : elle n'annule ni la transition ni le job
     * suivant, et les clients se rattrapent par la resynchronisation.
     */
    public static function broadcastFailed(Throwable $exception): void
    {
        $emission = self::$lastEmission;
        self::$lastEmission = null;

        self::write(LogLevel::WARNING, self::BROADCAST_FAILED, array_filter([
            'gameRef' => $emission['gameRef'] ?? null,
            'event' => $emission['event'] ?? null,
            'sequenceIndex' => $emission['sequenceIndex'] ?? null,
            'exception' => $exception::class,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Second échec d'une même transition (§ 4.6) : il n'est pas redispatché.
     * Filets restants : tout rattrapage déclenché par une requête,
     * `game:reschedule`, la partie bloquée et la sonde n° 1 de 10 § 11.3.
     */
    public static function transitionFailedTwice(int $gameId, int $roundId, RoundStep $step, ?int $tierIndex, string $dueAt, ?Throwable $exception): void
    {
        $context = ['step' => $step->value, 'tierIndex' => $tierIndex, 'dueAt' => $dueAt, 'exception' => $exception === null ? null : $exception::class];

        try {
            $game = Game::query()->find($gameId);
            $round = Round::query()->find($roundId);

            $context = [
                ...($game instanceof Game ? self::gameContext($game) : []),
                ...($round instanceof Round ? self::roundContext($round) : []),
                ...$context,
            ];
        } catch (Throwable) {
            // Base indisponible : la ligne part sans référence de partie.
        }

        GameTraceWriter::record($gameId, GameTraceWriter::TRANSITION_FAILED_TWICE, details: ['step' => $step->value, 'tierIndex' => $tierIndex, 'dueAt' => $dueAt]);

        self::write(LogLevel::ERROR, self::TRANSITION_FAILED_TWICE, array_filter(
            $context,
            static fn (mixed $value): bool => $value !== null,
        ));
    }

    /**
     * Millisecondes entières écoulées de `$from` à `$to`, sur les
     * microsecondes, comme {@see RoundClock::offsetMs()}.
     */
    public static function elapsedMs(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return intdiv((int) $to->format('Uu') - (int) $from->format('Uu'), 1000);
    }

    /**
     * @return array{gameRef: string, mode: string}
     */
    private static function gameContext(Game $game): array
    {
        return ['gameRef' => GameRef::for($game), 'mode' => $game->mode->value];
    }

    /**
     * @return array{sequenceIndex: int, roundNumber: int|null}
     */
    private static function roundContext(Round $round): array
    {
        return ['sequenceIndex' => $round->sequence_index, 'roundNumber' => $round->round_number];
    }

    /**
     * Écrit la ligne après le commit de la transaction en cours (aussitôt
     * hors transaction), en avalant tout échec d'écriture.
     *
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        $line = static function () use ($level, $message, $context): void {
            try {
                Log::channel(self::CHANNEL)->log($level, $message, $context);
            } catch (Throwable) {
                // Journaliser n'annule jamais une transition (§ 4.7).
            }
        };

        try {
            DB::afterCommit($line);
        } catch (Throwable) {
            $line();
        }
    }
}
