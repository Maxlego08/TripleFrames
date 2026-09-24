<?php

use App\Support\Tmdb\TmdbClient;
use App\Support\Tmdb\TmdbDiscoverQuery;
use App\Support\Tmdb\TmdbErrorKind;
use App\Support\Tmdb\TmdbException;
use Carbon\CarbonInterval;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| Client TMDB — aucun appel réseau, jamais
|--------------------------------------------------------------------------
|
| `Http::preventStrayRequests()` fait échouer tout appel non simulé : c'est la
| garantie mécanique que la CI n'a besoin d'aucune clé et qu'aucun test ne sort
| de la machine. `Sleep::fake()` neutralise le retrait exponentiel, sans quoi
| éprouver trois tentatives coûterait plusieurs secondes.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake();

    Config::set('services.tmdb.read_access_token', 'fixture-token');
    Config::set('services.tmdb.api_key', null);
    Config::set('services.tmdb.retry_times', 3);
    Config::set('services.tmdb.retry_base_delay_ms', 500);
});

afterEach(function (): void {
    Sleep::fake(false);
});

function tmdbFakeDiscover(): void
{
    Http::fake([
        '*themoviedb.org/3/discover/movie*' => Http::response(
            TmdbFixture::json('discover-page-1'),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);
}

it('se construit depuis le conteneur sans liaison déclarée', function (): void {
    expect(app(TmdbClient::class))->toBeInstanceOf(TmdbClient::class);
});

it('se tait quand aucune clé n’est posée, et ne casse rien', function (): void {
    Config::set('services.tmdb.read_access_token', null);
    Config::set('services.tmdb.api_key', null);

    $client = app(TmdbClient::class);

    expect($client->isConfigured())->toBeFalse();

    $exception = null;

    try {
        $client->discover(new TmdbDiscoverQuery);
    } catch (TmdbException $caught) {
        $exception = $caught;
    }

    expect($exception)->not->toBeNull()
        ->and($exception?->kind)->toBe(TmdbErrorKind::NotConfigured)
        ->and($exception?->translationKey())->toBe('admin.tmdb.error.not_configured')
        ->and($exception?->isTransient())->toBeFalse();

    Http::assertNothingSent();
});

it('traite une variable vide comme une variable absente', function (): void {
    Config::set('services.tmdb.read_access_token', '');
    Config::set('services.tmdb.api_key', '   ');

    expect(app(TmdbClient::class)->isConfigured())->toBeFalse();
});

it('authentifie par le jeton v4 en en-tête, jamais par la clé en URL', function (): void {
    tmdbFakeDiscover();

    app(TmdbClient::class)->discover(new TmdbDiscoverQuery);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer fixture-token')
        && ! str_contains($request->url(), 'api_key='));
});

it('replie sur la clé v3 en paramètre quand le jeton est absent', function (): void {
    Config::set('services.tmdb.read_access_token', null);
    Config::set('services.tmdb.api_key', 'fixture-key');

    tmdbFakeDiscover();

    app(TmdbClient::class)->discover(new TmdbDiscoverQuery);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api_key=fixture-key')
        && ! $request->hasHeader('Authorization'));
});

it('envoie les critères de balayage, la page, et include_adult=false', function (): void {
    tmdbFakeDiscover();

    $query = new TmdbDiscoverQuery(
        originalLanguage: 'ja',
        minVoteCount: 500,
        minReleaseYear: 1970,
    );

    app(TmdbClient::class)->discover($query, 3);

    Http::assertSent(function (Request $request): bool {
        $url = $request->url();

        return str_contains($url, '/3/discover/movie')
            && str_contains($url, 'include_adult=false')
            && str_contains($url, 'with_original_language=ja')
            && str_contains($url, 'vote_count.gte=500')
            && str_contains($url, 'primary_release_date.gte=1970-01-01')
            && str_contains($url, 'sort_by=vote_count.desc')
            && str_contains($url, 'page=3');
    });
});

it('lit une page de balayage et sait où reprendre', function (): void {
    tmdbFakeDiscover();

    $page = app(TmdbClient::class)->discover(new TmdbDiscoverQuery);

    expect($page->page)->toBe(1)
        ->and($page->totalPages)->toBe(3)
        ->and($page->totalResults)->toBe(57)
        ->and($page->results)->toHaveCount(3)
        ->and($page->hasMore())->toBeTrue()
        ->and($page->nextPage())->toBe(2)
        ->and($page->isEmpty())->toBeFalse();

    $first = $page->results[0];

    expect($first->tmdbId)->toBe(987654)
        ->and($first->title)->toBe('The Glass Orchard')
        ->and($first->originalTitle)->toBe('ガラスの果樹園')
        ->and($first->originalLanguage)->toBe('ja')
        ->and($first->releaseYear())->toBe(1998)
        ->and($first->voteCount)->toBe(2410)
        ->and($first->adult)->toBeFalse()
        ->and($first->genreIds)->toBe([16, 18]);

    $second = $page->results[1];

    expect($second->releaseDate)->toBeNull()
        ->and($second->releaseYear())->toBeNull()
        ->and($second->voteCount)->toBe(0)
        ->and($second->genreIds)->toBe([]);
});

it('refuse une page hors des bornes de pagination de TMDB', function (): void {
    tmdbFakeDiscover();

    expect(fn () => app(TmdbClient::class)->discover(new TmdbDiscoverQuery, 501))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('demande la fiche avec ses trois append_to_response en un seul appel', function (): void {
    Http::fake([
        '*themoviedb.org/3/movie/987654/images*' => Http::response('{}', 200),
        '*themoviedb.org/3/movie/987654*' => Http::response(TmdbFixture::json('movie-987654'), 200),
    ]);

    app(TmdbClient::class)->movie(987654);

    Http::assertSent(function (Request $request): bool {
        $url = urldecode($request->url());

        return str_contains($url, '/3/movie/987654')
            && str_contains($url, 'append_to_response=release_dates,translations,alternative_titles');
    });
});

it('rend null sur un film inconnu, et ne retente pas', function (): void {
    Http::fake([
        '*themoviedb.org/3/movie/*' => Http::response(TmdbFixture::json('error-404'), 404),
    ]);

    expect(app(TmdbClient::class)->movie(987654))->toBeNull();

    Http::assertSentCount(1);
});

it('lève une erreur typée et muette sur une clé invalide', function (): void {
    Http::fake([
        '*' => Http::response(TmdbFixture::json('error-401'), 401),
    ]);

    $exception = null;

    try {
        app(TmdbClient::class)->discover(new TmdbDiscoverQuery);
    } catch (TmdbException $caught) {
        $exception = $caught;
    }

    expect($exception?->kind)->toBe(TmdbErrorKind::Unauthorized)
        ->and($exception?->httpStatus)->toBe(401)
        ->and($exception?->translationKey())->toBe('admin.tmdb.error.unauthorized')
        ->and($exception?->translationReplacements())->toBe(['status' => 401])
        ->and($exception?->isTransient())->toBeFalse()
        ->and($exception?->getMessage())->not->toContain('fixture-token');

    Http::assertSentCount(1);
});

it('retente un quota atteint, obéit à Retry-After, puis rend une erreur typée', function (): void {
    Http::fake([
        '*' => Http::response(TmdbFixture::json('error-429'), 429, ['Retry-After' => '2']),
    ]);

    $exception = null;

    try {
        app(TmdbClient::class)->discover(new TmdbDiscoverQuery);
    } catch (TmdbException $caught) {
        $exception = $caught;
    }

    expect($exception?->kind)->toBe(TmdbErrorKind::RateLimited)
        ->and($exception?->retryAfterSeconds)->toBe(2)
        ->and($exception?->isTransient())->toBeTrue()
        ->and($exception?->translationReplacements())->toBe(['status' => 429, 'retry_after' => 2]);

    Http::assertSentCount(3);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 2_000, 2);
});

it('retente une panne serveur avec un retrait exponentiel', function (): void {
    Http::fake([
        '*' => Http::response(TmdbFixture::json('error-500'), 503),
    ]);

    $exception = null;

    try {
        app(TmdbClient::class)->images(987654);
    } catch (TmdbException $caught) {
        $exception = $caught;
    }

    expect($exception?->kind)->toBe(TmdbErrorKind::ServerError)
        ->and($exception?->httpStatus)->toBe(503);

    Http::assertSentCount(3);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 500, 1);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 1_000, 1);
});

it('rend une erreur de transport quand la connexion échoue', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $exception = null;

    try {
        app(TmdbClient::class)->discover(new TmdbDiscoverQuery);
    } catch (TmdbException $caught) {
        $exception = $caught;
    }

    expect($exception?->kind)->toBe(TmdbErrorKind::Transport)
        ->and($exception?->isTransient())->toBeTrue()
        ->and($exception?->translationKey())->toBe('admin.tmdb.error.transport');
});

it('refuse un corps 200 qui n’est pas un objet', function (): void {
    Http::fake([
        '*' => Http::response('"pas un objet"', 200),
    ]);

    $exception = null;

    try {
        app(TmdbClient::class)->discover(new TmdbDiscoverQuery);
    } catch (TmdbException $caught) {
        $exception = $caught;
    }

    expect($exception?->kind)->toBe(TmdbErrorKind::Malformed)
        ->and($exception?->isTransient())->toBeFalse();

    Http::assertSentCount(1);
});

it('lit les visuels sans jamais filtrer par langue', function (): void {
    Http::fake([
        '*themoviedb.org/3/movie/987654/images*' => Http::response(
            TmdbFixture::json('movie-987654-images'),
            200,
        ),
    ]);

    $images = app(TmdbClient::class)->images(987654);

    expect($images->tmdbId)->toBe(987654)
        ->and($images->backdrops)->toHaveCount(3)
        ->and($images->posters)->toHaveCount(1)
        ->and($images->logos)->toHaveCount(1)
        ->and($images->isEmpty())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'language='));
});

it('construit une URL de visuel sans jamais l’inventer', function (): void {
    expect(app(TmdbClient::class)->imageUrl('/6a7b8c9d0e1f2a3b4c5d6e7f80912a3b.jpg', 'w1280'))
        ->toBe('https://image.tmdb.org/t/p/w1280/6a7b8c9d0e1f2a3b4c5d6e7f80912a3b.jpg');
});

it('refuse un identifiant TMDB absurde sans appeler quoi que ce soit', function (): void {
    expect(fn () => app(TmdbClient::class)->movie(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(TmdbClient::class)->images(-1))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('le téléchargement d\'un original refuse un fichier plus lourd que le plafond configuré', function (): void {
    Config::set('catalog.curation.tmdb_original_max_kilobytes', 1);

    $path = '/6a7b8c9d0e1f2a3b4c5d6e7f80912a3b.jpg';
    $ceiling = str_repeat("\x01", 1_024);
    $body = null;
    $length = null;

    Http::fake([
        '*image.tmdb.org/*' => function () use (&$body, &$length) {
            return Http::response((string) $body, 200, $length === null ? [] : ['Content-Length' => (string) $length]);
        },
    ]);

    $kind = function () use ($path): ?TmdbErrorKind {
        try {
            app(TmdbClient::class)->downloadImage($path);
        } catch (TmdbException $exception) {
            return $exception->kind;
        }

        return null;
    };

    // Au plafond exactement : les octets reviennent tels quels, téléchargés à
    // la taille `original`, sans que le jeton de l'API parte chez ce tiers.
    $body = $ceiling;

    expect(app(TmdbClient::class)->downloadImage($path))->toBe($ceiling);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://image.tmdb.org/t/p/original'.$path
        && ! $request->hasHeader('Authorization'));

    // Un octet de plus, sans `Content-Length` : la lecture s'arrête au plafond.
    $body = $ceiling."\x01";

    expect($kind())->toBe(TmdbErrorKind::TooLarge);

    // Un `Content-Length` déclaré au-delà : refus sans lire le corps.
    $body = 'x';
    $length = 1_025;

    expect($kind())->toBe(TmdbErrorKind::TooLarge);

    // Le plafond suit la configuration.
    Config::set('catalog.curation.tmdb_original_max_kilobytes', 2);
    $body = $ceiling."\x01";
    $length = null;

    expect(app(TmdbClient::class)->downloadImage($path))->toBe($ceiling."\x01");

    // Un refus de taille n'est jamais retenté, et se nomme par une clé.
    expect(TmdbErrorKind::TooLarge->isTransient())->toBeFalse()
        ->and(TmdbErrorKind::TooLarge->translationKey())->toBe('admin.tmdb.error.too_large');
});

it('le téléchargement d\'un original s\'interrompt à l\'échéance, même servi au goutte-à-goutte', function (): void {
    Config::set('services.tmdb.timeout', 2);
    $this->freezeTime();

    // Un corps qui ne finit jamais : un octet par lecture, une seconde de
    // l'horloge simulée à chaque lecture. Le plafond de taille ne sera jamais
    // atteint — seule l'échéance peut arrêter la lecture.
    $reads = 0;
    $drip = FnStream::decorate(Utils::streamFor(''), [
        'eof' => fn (): bool => false,
        'isSeekable' => fn (): bool => false,
        'read' => function (int $length) use (&$reads): string {
            $reads++;
            $this->travel(1)->seconds();

            return "\x01";
        },
    ]);

    Http::fake(['*image.tmdb.org/*' => Http::response($drip)]);

    $kind = null;

    try {
        app(TmdbClient::class)->downloadImage('/6a7b8c9d0e1f2a3b4c5d6e7f80912a3b.jpg');
    } catch (TmdbException $exception) {
        $kind = $exception->kind;
    }

    // Coupé en panne de transport, avec au plus une lecture au-delà de
    // l'échéance — jamais tenu indéfiniment par le serveur.
    expect($kind)->toBe(TmdbErrorKind::Transport)
        ->and($reads)->toBe(3);
});
