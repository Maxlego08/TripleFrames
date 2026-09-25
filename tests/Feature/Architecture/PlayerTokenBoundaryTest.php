<?php

use App\Enums\Locale;
use App\Support\I18n\LocaleCookie;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Frontières du `player_token` — spec 40 § 3.2, § 3.3 et § 3.12 (L40-1)
|--------------------------------------------------------------------------
|
| Trois propriétés que rien ne rendrait visibles en relecture :
|
| - le cookie est CHIFFRÉ : une ligne ajoutée à `encryptCookies(except: …)`
|   — à côté de `locale`, qui y est légitimement — livrerait le `tid` en
|   clair et rendrait la charge falsifiable ;
| - `PlayerTokenCookie` est son SEUL lecteur et son SEUL écrivain, et
|   `PlayerTokenManager` son seul appelant : un `$request->cookie('player_token')`
|   ailleurs contournerait le décodage (jeton invalide = absent) et le mémo de
|   la requête (un seul `Set-Cookie`) ; le front ne le lit jamais (HttpOnly) ;
| - le `tid` est tiré au CSPRNG, jamais un ULID ni un horodatage : trié dans le
|   temps, il rendrait corrélables deux jetons frappés à la même seconde.
|
*/

/**
 * Fichiers PHP du code applicatif, en chemins relatifs normalisés.
 *
 * @return list<string>
 */
function playerTokenBoundaryPhpFiles(): array
{
    $files = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR)) {
                $files[] = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR));
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Tokens significatifs d'un fichier PHP (sans espaces ni commentaires).
 *
 * @return list<array{0: int|string, 1: string, 2: int}>
 */
function playerTokenBoundaryTokens(string $relativePath): array
{
    $tokens = [];

    foreach (token_get_all((string) file_get_contents(base_path($relativePath))) as $token) {
        if (is_string($token)) {
            $tokens[] = [$token, $token, 0];

            continue;
        }

        if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $tokens[] = [$token[0], $token[1], $token[2]];
        }
    }

    return $tokens;
}

it("n'exempte jamais le cookie player_token du chiffrement", function () {
    // La classe réellement posée dans le groupe `web` — pas une hypothèse sur son nom.
    $class = collect(app(HttpKernel::class)->getMiddlewareGroups()['web'] ?? [])
        ->first(static fn (mixed $middleware): bool => is_string($middleware) && is_a($middleware, EncryptCookies::class, true));

    expect($class)->toBeString();

    /** @var EncryptCookies $middleware */
    $middleware = app((string) $class);

    // Témoin : les exceptions de `bootstrap/app.php` sont bien vues.
    expect($middleware->isDisabled(LocaleCookie::NAME))->toBeTrue()
        ->and($middleware->isDisabled(PlayerTokenCookie::NAME))->toBeFalse();

    // Et la pile réelle le chiffre, préfixe lié au nom compris.
    Route::middleware('web')->post('/_test/player-token-boundary/ensure', function (Request $request, PlayerTokenManager $tokens): Response {
        $tokens->ensure($request);

        return response()->noContent();
    });

    $response = $this->post('/_test/player-token-boundary/ensure')->assertNoContent();
    $raw = (string) $response->getCookie(PlayerTokenCookie::NAME, decrypt: false)?->getValue();

    /** @var Encrypter $encrypter */
    $encrypter = app('encrypter');
    $plain = CookieValuePrefix::validate(PlayerTokenCookie::NAME, $encrypter->decrypt($raw, false), $encrypter->getAllKeys());

    expect(json_decode($raw, true))->toBeNull()
        ->and($plain)->toBeString()
        ->and(json_decode((string) $plain, true))->toHaveKeys(['v', 'tid', 'locale', 'avatar'])
        ->and($raw)->not->toContain((string) json_decode((string) $plain, true)['tid']);
});

it("ne lit et n'écrit le cookie player_token que par PlayerTokenCookie", function () {
    $cookieClass = 'app/Support/Identity/PlayerTokenCookie.php';
    $manager = 'app/Support/Identity/PlayerTokenManager.php';

    // Le nom en littéral : la constante de `PlayerTokenCookie`, et la liste
    // close des clés expurgées des journaux (spec 100 § 10.9), qui ne touche
    // aucun cookie.
    $literalAllowed = [$cookieClass, 'app/Support/Ops/RedactPersonalData.php'];

    $violations = [];

    foreach (playerTokenBoundaryPhpFiles() as $file) {
        $tokens = playerTokenBoundaryTokens($file);

        foreach ($tokens as $index => [$kind, $text, $line]) {
            if ($kind === T_CONSTANT_ENCAPSED_STRING && substr($text, 1, -1) === PlayerTokenCookie::NAME && ! in_array($file, $literalAllowed, true)) {
                $violations[] = "{$file}:{$line} écrit le nom du cookie en littéral.";
            }

            $isCookieClass = in_array($kind, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && ($text === 'PlayerTokenCookie' || str_ends_with($text, '\\PlayerTokenCookie'));

            if (! $isCookieClass || ($tokens[$index + 1][0] ?? null) !== T_DOUBLE_COLON || $file === $cookieClass) {
                continue;
            }

            $member = (string) ($tokens[$index + 2][1] ?? '');

            if ($member === 'NAME') {
                $violations[] = "{$file}:{$line} lit PlayerTokenCookie::NAME : seul PlayerTokenCookie touche au cookie.";
            }

            if (in_array($member, ['read', 'queue', 'make'], true) && $file !== $manager) {
                $violations[] = "{$file}:{$line} appelle PlayerTokenCookie::{$member}() hors de PlayerTokenManager, qui porte le mémo de la requête.";
            }
        }
    }

    // Le front ne lit jamais le jeton : il est HttpOnly, et le serveur lui
    // donne tout ce qu'il affiche.
    $front = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($front as $file) {
        if ($file instanceof SplFileInfo && in_array($file->getExtension(), ['ts', 'tsx', 'js', 'jsx'], true)
            && str_contains((string) file_get_contents($file->getPathname()), PlayerTokenCookie::NAME)) {
            $violations[] = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR)).' nomme le cookie player_token.';
        }
    }

    expect($violations)->toBe([]);

    // Témoin : le balayage voit bien les accès légitimes.
    $managerTokens = array_column(playerTokenBoundaryTokens($manager), 1);

    expect($managerTokens)->toContain('PlayerTokenCookie')
        ->and(playerTokenBoundaryPhpFiles())->toContain($cookieClass, $manager);
});

it('frappe des tid de 64 caractères hexadécimaux minuscules qui ne se répètent jamais', function () {
    $count = 2000;
    $tids = [];

    for ($index = 0; $index < $count; $index++) {
        $tids[] = PlayerToken::mint(Locale::English)->toClaims()['tid'];
    }

    // Et par le chemin réel de la frappe, une requête après l'autre.
    $tokens = app(PlayerTokenManager::class);

    for ($index = 0; $index < 50; $index++) {
        $tids[] = $tokens->ensure(Request::create('/', 'POST'))->toClaims()['tid'];
    }

    foreach ($tids as $tid) {
        expect($tid)->toMatch(PlayerToken::TID_PATTERN)
            ->and(strlen($tid))->toBe(PlayerToken::TID_BYTES * 2);
    }

    expect(array_unique($tids))->toHaveCount(count($tids));

    // Jamais trié dans le temps : un ULID ou un horodatage en tête donnerait une
    // suite croissante, et des voisins au long préfixe commun.
    $sorted = $tids;
    sort($sorted);

    expect($tids)->not->toBe($sorted);

    $sharedPrefixes = 0;

    for ($index = 1; $index < count($tids); $index++) {
        if (strncmp($tids[$index - 1], $tids[$index], 8) === 0) {
            $sharedPrefixes++;
        }
    }

    expect($sharedPrefixes)->toBe(0);
});
