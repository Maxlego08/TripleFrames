<?php

use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
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

/**
 * Pose des valeurs sous `game.platform.*` et oublie l'instance mémoïsée.
 *
 * `PlatformLimits` est liée `scoped` dans le conteneur : un `config()->set()`
 * nu, une fois l'instance résolue, serait ignoré en silence. Tout test qui
 * change `game.platform.*` en cours de test passe par cette fonction, qui
 * rejoue ce que ferait le début d'une nouvelle requête ou d'un nouveau job.
 *
 * `EngineConstants` est oubliée aussi : sa garde de clôture après pause lit
 * la marge de tirage de la plateforme (spec 60 § 19.1).
 *
 * @param  array<string, mixed>  $values
 */
function platformLimitsConfigure(array $values): void
{
    foreach ($values as $key => $value) {
        config()->set('game.platform.'.$key, $value);
    }

    app()->forgetInstance(PlatformLimits::class);
    app()->forgetInstance(EngineConstants::class);
}

/**
 * Pose des valeurs sous `game.engine.*` et oublie l'instance mémoïsée.
 *
 * Même régime que {@see platformLimitsConfigure()} : `EngineConstants` est
 * liée `scoped`, et un `config()->set()` nu serait ignoré en silence.
 *
 * @param  array<string, mixed>  $values
 */
function engineConstantsConfigure(array $values): void
{
    foreach ($values as $key => $value) {
        config()->set('game.engine.'.$key, $value);
    }

    app()->forgetInstance(EngineConstants::class);
}
