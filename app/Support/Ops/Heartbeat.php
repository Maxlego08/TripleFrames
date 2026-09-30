<?php

namespace App\Support\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Battements de cœur des workers — spec 100 § 15.
 *
 * Le worker se mesure par le battement de SA file, jamais par « l'unité est
 * active » : un processus vivant mais bloqué, ou une file que plus personne
 * ne dépile, garde son unité active. `App\Jobs\Ops\WorkerHeartbeat` écrit ici
 * l'instant où IL S'EXÉCUTE, donc depuis le worker qui dépile la file ; le
 * planificateur ne fait que l'y déposer. Si le planificateur s'arrête, les
 * battements vieillissent aussi : il est surveillé par la même sonde (§ 10.7).
 *
 * La clé `ops:heartbeat:<file>` vit dans le cache applicatif (Redis dédié en
 * production), qu'aucune procédure ne vide (§ 10.3). Elle porte l'instant en
 * millisecondes Unix (entier, relu en chaîne numérique sur Redis) ; une
 * valeur absente ou illisible vaut « périmé ».
 */
final class Heartbeat
{
    /** La file du temps réel : battement toutes les 30 s. */
    public const string GAME = 'game';

    /** La file des images, des imports et de la purge : battement chaque minute. */
    public const string DEFAULT = 'default';

    private const string KEY_PREFIX = 'ops:heartbeat:';

    public static function key(string $queue): string
    {
        return self::KEY_PREFIX.$queue;
    }

    public static function record(string $queue, CarbonImmutable $at): void
    {
        Cache::forever(self::key($queue), $at->getTimestampMs());
    }

    /**
     * L'instant du dernier battement, `null` si absent ou illisible.
     *
     * Le store Redis de la production écrit un entier SANS le sérialiser et
     * le relit tel que le client le rend : une chaîne numérique, jamais un
     * `int`. La valeur se lit donc comme un entier écrit en chiffres, quel
     * que soit son type PHP — un `is_int` laisserait la sonde en alerte en
     * permanence, alors que le store `array` des tests garde l'entier.
     */
    public static function lastBeatAt(string $queue): ?CarbonImmutable
    {
        $milliseconds = filter_var(Cache::get(self::key($queue)), FILTER_VALIDATE_INT);

        return $milliseconds === false ? null : CarbonImmutable::createFromTimestampMs($milliseconds);
    }

    /**
     * Motifs d'alerte : vide si le dernier battement a au plus `$staleSeconds`
     * secondes à l'instant `$now`.
     *
     * @return list<string>
     */
    public static function failures(string $queue, int $staleSeconds, CarbonImmutable $now): array
    {
        $last = self::lastBeatAt($queue);

        if ($last === null) {
            return ["{$queue} : aucun battement enregistré"];
        }

        $ageMs = $now->getTimestampMs() - $last->getTimestampMs();

        if ($ageMs > $staleSeconds * 1000) {
            return ["{$queue} : dernier battement il y a {$ageMs} ms, au-delà de {$staleSeconds} s"];
        }

        return [];
    }
}
