<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

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
| Portée limitée à `Feature` : seule cette suite est liée à `Tests\TestCase`,
| donc à une application bootée. Un test de `Unit` tourne sur le `TestCase` nu
| de PHPUnit, où la façade `Http` n'a aucune racine — et n'a, par construction,
| aucun client HTTP à détourner.
|
*/

pest()->beforeEach(function (): void {
    Http::preventStrayRequests();
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
