<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Suites et groupes — contrat C18 (spec 100 § 2.1 et § 2.2)
|--------------------------------------------------------------------------
|
| Trois groupes, liste close, prouvée par `TestGroupsTest` :
|
| - défaut (aucun groupe) : tout ce qui passe sur SQLite `:memory:`, cache
|   `array`, file `sync`, diffusion `null`. Joué partout.
| - `mysql` : ce que SQLite ne voit pas (1406, 1071, `ONLY_FULL_GROUP_BY`,
|   collation, JSON natif). Déclaré AU NIVEAU DU FICHIER par
|   `pest()->group('mysql');` suivi de `beforeEach(fn () => requireMysql());`,
|   jamais par `->group('mysql')` sur un test isolé.
| - `locks-timing` : concurrence réelle de deux connexions, verrous Redis,
|   jobs de frontière. Vit sous `tests/Concurrency/<Domaine>/<Sujet>Test.php`,
|   où le groupe s'applique par répertoire, ci-dessous.
|
| `composer test` exclut les deux groupes ; le job `mysql-redis` joue tout.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| `DatabaseTruncation` et non une transaction englobante : deux connexions
| concurrentes doivent VOIR les écritures l'une de l'autre, ce qu'une
| transaction de test interdit.
*/

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->group('locks-timing')
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Aucun test ne touche le réseau
|--------------------------------------------------------------------------
|
| Garanti par construction, et non par relecture : un appel HTTP non simulé
| lève au lieu de partir. Sans cette ligne, un futur test d'import qui
| oublierait `Http::fake()` enverrait le jeton TMDB sur le réseau depuis le
| poste du développeur — et passerait au vert en local pour échouer en CI,
| où aucune clé n'est posée. Un test qui a besoin d'un appel simulé appelle
| `Http::fake()` comme d'habitude ; `preventStrayRequests()` ne gêne que ce
| qui n'est pas simulé.
|
| Portée limitée aux suites liées à `Tests\TestCase`, donc à une application
| bootée. Un test de `Unit` tourne sur le `TestCase` nu de PHPUnit, où la
| façade `Http` n'a aucune racine — et n'a, par construction, aucun client
| HTTP à détourner.
|
*/

pest()->beforeEach(function (): void {
    Http::preventStrayRequests();
})->in('Feature', 'Concurrency');

/*
| Un test `locks-timing` ÉCHOUE hors de MySQL, il ne se saute jamais : un test
| sauté en silence est vert partout et ne prouve rien nulle part.
*/

pest()->beforeEach(fn () => requireMysql())->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Fonctions
|--------------------------------------------------------------------------
*/

/**
 * Fait échouer — jamais sauter — un test qui exige MySQL quand la connexion
 * par défaut est un autre moteur.
 *
 * Appelée par le `beforeEach` de chaque fichier du groupe `mysql` et, pour
 * `tests/Concurrency`, par le crochet de répertoire ci-dessus. Le message dit
 * comment rejouer le test au lieu de laisser un « failed » muet.
 */
function requireMysql(): void
{
    $driver = DB::connection()->getDriverName();

    expect($driver)->toBe('mysql', sprintf(
        'Ce test exige MySQL et tourne sur le pilote [%s]. Il est exclu de `composer test` '
        .'et joué par le job CI `mysql-redis`. En local : `composer test:mysql` contre une base '
        .'MySQL de TEST (DB_CONNECTION=mysql et DB_DATABASE exportés), jamais la base de développement.',
        $driver,
    ));
}
