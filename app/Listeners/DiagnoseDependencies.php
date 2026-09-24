<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Étend la route de santé `/up` — spec 100 § 15.
 *
 * Le framework émet `DiagnosingHealth` à chaque `GET /up` et répond 500 dès
 * qu'un écouteur lève. Celui-ci interroge :
 *
 * - la base, par une requête réelle sur la connexion par défaut ;
 * - Redis **seulement si** le cache ou la file par défaut en dépendent : la CI
 *   (`phpunit.xml` : `array`, `sync`) n'a pas de Redis, et `/up` doit y rester
 *   vert. Chaque connexion Redis utilisée est interrogée une fois.
 *
 * Aucun détail ne sort dans la réponse : le framework rapporte l'exception au
 * journal de l'application, et la page de santé ne dit que « en difficulté ».
 */
final class DiagnoseDependencies
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::connection()->select('select 1');

        foreach (self::redisConnections() as $connection) {
            Redis::connection($connection)->ping();
        }
    }

    /**
     * Les connexions Redis dont dépendent le cache et la file par défaut.
     *
     * @return list<string>
     */
    public static function redisConnections(): array
    {
        $connections = [];

        $store = Config::string('cache.default');

        if (config("cache.stores.{$store}.driver") === 'redis') {
            $connections[] = (string) config("cache.stores.{$store}.connection", 'default');
            $connections[] = (string) config("cache.stores.{$store}.lock_connection", 'default');
        }

        $queue = Config::string('queue.default');

        if (config("queue.connections.{$queue}.driver") === 'redis') {
            $connections[] = (string) config("queue.connections.{$queue}.connection", 'default');
        }

        return array_values(array_unique($connections));
    }
}
