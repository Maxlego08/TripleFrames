<?php

namespace App\Support\Perf;

use App\Http\Middleware\MeasureRequest;
use App\Models\GameTrace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La chronologie technique d'une partie — spec 100 § 10.11, spec 10 § 7.11
 * (D47 du 01/10).
 *
 * Appelée par `GameJournal` (transitions, diffusions et leur retard), par
 * `AdvanceRound` (retard au démarrage, durée, requêtes) et par
 * {@see MeasureRequest} (soumissions, resynchronisations).
 * Une ligne `game_trace` par fait, **après commit** de la transaction en cours
 * (aussitôt hors transaction) : la chronologie ne raconte que ce qui a eu
 * lieu, comme le journal `game`.
 *
 * **Jamais bloquant, jamais de contenu** : tout échec est avalé ; `details`
 * ne porte que des issues, causes, motifs et statuts, jamais un titre, une
 * saisie ni une chaîne du QCM. Ses propres écritures ne sont pas comptées
 * dans la portée de mesure qu'elles décrivent. Jamais échantillonnée, inerte
 * si `perf.enabled` est faux.
 */
final class GameTraceWriter
{
    /** Préfixe des diffusions : `broadcast.tier.opened`, `broadcast.round.closed`… */
    public const string BROADCAST_PREFIX = 'broadcast.';

    /** Préfixe des jobs de frontière : `job.open_tier`, `job.close_round`… */
    public const string JOB_PREFIX = 'job.';

    public const string ROUND_OPENED = 'round.opened';

    public const string ROUND_CLOSED = 'round.closed';

    public const string TIER_SUBSTITUTED = 'tier.substituted';

    public const string ROUND_CANCELLED = 'round.cancelled';

    /** Cas terminal du QCM (spec 70 § 10.7, D54 du 02/10). */
    public const string CHOICES_UNAVAILABLE = 'choices.unavailable';

    public const string GAME_PAUSED = 'game.paused';

    public const string GAME_RESUMED = 'game.resumed';

    public const string PAUSE_REQUESTED = 'game.pause_requested';

    public const string PAUSE_REQUEST_CANCELLED = 'game.pause_request_cancelled';

    public const string GAME_FINALIZED = 'game.finalized';

    public const string RESYNCHRONIZED = 'game.resynchronized';

    public const string TRANSITION_FAILED_TWICE = 'transition.failed_twice';

    public const string ANSWER_TEXT = 'answer.text';

    public const string ANSWER_CHOICE = 'answer.choice';

    /**
     * Écrit une ligne. `$theoreticalAt` donne le retard (`$recordedAt` −
     * théorique, signé) ; `$recordedAt` vaut l'instant présent par défaut.
     *
     * @param  array<string, scalar|null>  $details
     */
    public static function record(
        int $gameId,
        string $event,
        ?int $sequenceIndex = null,
        ?int $tierIndex = null,
        ?CarbonImmutable $theoreticalAt = null,
        ?CarbonImmutable $recordedAt = null,
        ?int $durationMs = null,
        ?int $queryCount = null,
        ?int $playerId = null,
        array $details = [],
    ): void {
        if (config('perf.enabled') !== true) {
            return;
        }

        $recordedAt ??= Date::now()->toImmutable();

        $write = static function () use ($gameId, $event, $sequenceIndex, $tierIndex, $theoreticalAt, $recordedAt, $durationMs, $queryCount, $playerId, $details): void {
            try {
                app(PerfRecorder::class)->silently(static function () use ($gameId, $event, $sequenceIndex, $tierIndex, $theoreticalAt, $recordedAt, $durationMs, $queryCount, $playerId, $details): void {
                    $line = new GameTrace;
                    $line->game_id = $gameId;
                    $line->sequence_index = $sequenceIndex;
                    $line->tier_index = $tierIndex;
                    $line->event = mb_substr($event, 0, 40);
                    $line->player_id = $playerId;
                    $line->theoretical_at = $theoreticalAt;
                    $line->recorded_at = $recordedAt;
                    $line->delay_ms = $theoreticalAt instanceof CarbonImmutable
                        ? PerfRecorder::diffMs($theoreticalAt, $recordedAt)
                        : null;
                    $line->duration_ms = $durationMs;
                    $line->query_count = $queryCount === null ? null : min($queryCount, 65_535);
                    $line->details = $details === [] ? null : $details;
                    $line->save();
                });
            } catch (Throwable) {
                // Tracer n'annule jamais une transition (§ 10.11).
            }
        };

        try {
            DB::afterCommit($write);
        } catch (Throwable) {
            $write();
        }
    }
}
