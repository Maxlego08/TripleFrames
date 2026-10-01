<?php

namespace App\Http\Middleware;

use App\Support\Perf\GameTraceWriter;
use App\Support\Perf\PerfRecorder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mesure chaque requête HTTP — spec 100 § 10.11 (D47 du 01/10).
 *
 * Middleware global et **terminable** : la portée s'ouvre à l'entrée, et
 * l'échantillon s'écrit dans `terminate()`, après l'envoi de la réponse sous
 * PHP-FPM — le joueur n'attend jamais la mesure. Le nom de la route est
 * retenu, **jamais l'URL**, qui porte codes de salon et jetons ; une requête
 * sans route nommée s'écrit `unnamed`.
 *
 * Une soumission de réponse (texte ou clic) écrit en plus sa ligne dans la
 * chronologie technique de la partie du siège : durée, requêtes SQL, statut
 * HTTP et issue (`accepted`, `rejected`…), jamais la saisie.
 */
class MeasureRequest
{
    /** Les routes de soumission, et l'événement de chronologie de chacune. */
    private const array SUBMISSIONS = [
        'round.answer.store' => GameTraceWriter::ANSWER_TEXT,
        'round.choice.store' => GameTraceWriter::ANSWER_CHOICE,
    ];

    public function __construct(private readonly PerfRecorder $recorder) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->recorder->begin('request', $request->getMethod());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->recorder->active()) {
            return;
        }

        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;
        $name = is_string($name) && $name !== '' ? $name : 'unnamed';

        $event = self::SUBMISSIONS[$name] ?? null;

        if ($event !== null) {
            $this->traceSubmission($request, $response, $event);
        }

        $this->recorder->finish($name, (string) $response->getStatusCode());
    }

    /**
     * La soumission dans la chronologie de la partie du siège, si `seat.active`
     * l'a résolue.
     */
    private function traceSubmission(Request $request, Response $response, string $event): void
    {
        $game = EnsureActiveSeat::game($request);
        $seat = EnsureActiveSeat::seat($request);
        $snapshot = $this->recorder->snapshot();

        if ($game === null || $seat === null || $snapshot === null) {
            return;
        }

        $result = $response instanceof JsonResponse ? $response->getData(true) : null;
        $sequence = $request->input('round');

        GameTraceWriter::record(
            gameId: $game->id,
            event: $event,
            sequenceIndex: is_numeric($sequence) ? (int) $sequence : null,
            durationMs: $snapshot['duration_ms'],
            queryCount: $snapshot['query_count'],
            playerId: $seat->id,
            details: [
                'http' => $response->getStatusCode(),
                'result' => is_array($result) && is_string($result['result'] ?? null) ? $result['result'] : null,
                'inputState' => is_array($result) && is_string($result['inputState'] ?? null) ? $result['inputState'] : null,
            ],
        );
    }
}
