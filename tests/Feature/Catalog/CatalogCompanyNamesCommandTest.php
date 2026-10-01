<?php

use App\Enums\Locale;
use App\Models\Movie;
use App\Models\TmdbCompany;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
|--------------------------------------------------------------------------
| `catalog:company-names` — spec 10 § 3.6 bis (L30-8, D43 du 01/10)
|--------------------------------------------------------------------------
|
| Le rattrapage des noms de sociétés des films importés avant la table. Aucun
| appel TMDB réel : `Http::fake` répond, et la suite tourne sans clé.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake();

    Config::set('services.tmdb.read_access_token', 'fixture-token');
    Config::set('services.tmdb.api_key', null);
    Config::set('catalog.import.requests_per_second', 1000);
});

afterEach(function (): void {
    Sleep::fake(false);
});

function companyNamesText(string $key, array $replacements = []): string
{
    $text = __($key, $replacements, Locale::French->value);

    return is_string($text) ? $text : $key;
}

function companyNamesFake(): void
{
    Http::fake([
        '*themoviedb.org/3/company/420*' => Http::response(['id' => 420, 'name' => 'Marvel Studios', 'headquarters' => ''], 200),
        '*themoviedb.org/3/company/429*' => Http::response(['id' => 429, 'name' => 'DC'], 200),
        '*themoviedb.org/3/company/999999*' => Http::response(['status_code' => 34, 'status_message' => 'Not found.'], 404),
    ]);
}

it('la commande nomme les sociétés connues par leurs seules étiquettes', function (): void {
    companyNamesFake();

    Movie::factory()->withCompany(420)->withCompany(429)->create();
    Movie::factory()->withCompany(420)->withGenre(16)->create();
    Movie::factory()->withCompany(999_999)->create();

    $this->artisan('catalog:company-names')
        ->expectsOutputToContain(companyNamesText('admin.console.company_names.done', ['named' => 2, 'unknown' => 1]))
        ->assertSuccessful();

    expect(TmdbCompany::query()->orderBy('tmdb_id')->pluck('name', 'tmdb_id')->all())
        ->toBe([420 => 'Marvel Studios', 429 => 'DC']);

    // Une requête par société distincte, jamais une par étiquette ; jamais
    // pour un genre.
    Http::assertSentCount(3);
});

it('la commande ne rappelle jamais TMDB pour une société déjà nommée', function (): void {
    companyNamesFake();

    TmdbCompany::factory()->named(420, 'Marvel Studios')->create();
    Movie::factory()->withCompany(420)->withCompany(429)->create();

    $this->artisan('catalog:company-names')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/company/429'));
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/company/420'));
});

it('la commande borne le nombre de sociétés traitées par --limit', function (): void {
    companyNamesFake();

    Movie::factory()->withCompany(420)->withCompany(429)->create();

    $this->artisan('catalog:company-names', ['--limit' => '1'])->assertSuccessful();

    expect(TmdbCompany::query()->pluck('tmdb_id')->all())->toBe([420]);

    $this->artisan('catalog:company-names', ['--limit' => '0'])
        ->expectsOutputToContain(companyNamesText('admin.console.company_names.invalid_limit'))
        ->assertFailed();
});

it('une société inconnue de TMDB ne bloque jamais la progression sous --limit', function (): void {
    Http::fake([
        '*themoviedb.org/3/company/7*' => Http::response(['status_code' => 34, 'status_message' => 'Not found.'], 404),
        '*themoviedb.org/3/company/420*' => Http::response(['id' => 420, 'name' => 'Marvel Studios'], 200),
        '*themoviedb.org/3/company/429*' => Http::response(['id' => 429, 'name' => 'DC'], 200),
    ]);

    // 7, inconnue (404), précède les autres et reste sans ligne : elle
    // redevient candidate à chaque passage, mais la borne ne compte que les
    // sociétés nommées.
    Movie::factory()->withCompany(7)->create();
    Movie::factory()->withCompany(420)->withCompany(429)->create();

    $this->artisan('catalog:company-names', ['--limit' => '1'])->assertSuccessful();
    $this->artisan('catalog:company-names', ['--limit' => '1'])->assertSuccessful();

    expect(TmdbCompany::query()->orderBy('tmdb_id')->pluck('tmdb_id')->all())->toBe([420, 429]);
});

it('la commande échoue sans clé TMDB plutôt que de la réclamer', function (): void {
    Http::fake();
    Config::set('services.tmdb.read_access_token', null);
    Config::set('services.tmdb.api_key', null);

    Movie::factory()->withCompany(420)->create();

    $this->artisan('catalog:company-names')
        ->expectsOutputToContain(companyNamesText('admin.console.company_names.not_configured'))
        ->assertFailed();

    Http::assertNothingSent();
    expect(TmdbCompany::query()->count())->toBe(0);
});

it('la simulation n\'écrit rien', function (): void {
    Http::fake();

    Movie::factory()->withCompany(420)->withCompany(429)->create();

    $this->artisan('catalog:company-names', ['--dry-run' => true])
        ->expectsOutputToContain(companyNamesText('admin.console.company_names.dry_run', ['count' => 2]))
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(TmdbCompany::query()->count())->toBe(0);
});

it('une panne TMDB arrête la commande en gardant les sociétés déjà nommées', function (): void {
    Http::fake([
        '*themoviedb.org/3/company/420*' => Http::response(['id' => 420, 'name' => 'Marvel Studios'], 200),
        '*themoviedb.org/3/company/429*' => Http::response(['status_code' => 7, 'status_message' => 'Invalid API key.'], 401),
    ]);

    Movie::factory()->withCompany(420)->withCompany(429)->create();

    $this->artisan('catalog:company-names')->assertFailed();

    expect(TmdbCompany::query()->pluck('name', 'tmdb_id')->all())->toBe([420 => 'Marvel Studios']);
});
