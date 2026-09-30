<?php

use App\Enums\Locale;
use App\Settings\EngineConstants;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Limiteurs du moteur — spec 60 § 10.3, contrat C7 § 2.4, C8 (ajout)
|--------------------------------------------------------------------------
|
| Ajout, hors des intitulés de 60 § 20 : aucun test nommé ne prouve les trois
| limiteurs posés par L60-2, alors qu'un limiteur nommé absent se lit comme un
| maximum de zéro et refuse tout en 429. Débits lus dans `EngineConstants`,
| clés sur le hash du `player_token`, repli sur l'IP.
|
*/

/** Une requête portant le `player_token` déjà déchiffré, comme après `EncryptCookies`. */
function gameLimiterRequest(?PlayerToken $token, string $ip = '203.0.113.7'): Request
{
    $cookies = $token === null
        ? []
        : [PlayerTokenCookie::NAME => json_encode($token->toClaims(), JSON_THROW_ON_ERROR)];

    return Request::create('/_test/limiter', 'GET', cookies: $cookies, server: ['REMOTE_ADDR' => $ip]);
}

/** Le `Limit` unique que rend le limiteur nommé pour cette requête. */
function gameLimiterLimit(string $name, Request $request): Limit
{
    $resolver = RateLimiter::limiter($name);

    expect($resolver)->not->toBeNull("Limiteur {$name} absent : toute route qui le porte répondrait 429.");

    $limit = $resolver($request);

    expect($limit)->toBeInstanceOf(Limit::class);

    return $limit;
}

it("les limiteurs game-read, game-write et frame-serve lisent EngineConstants et comptent par jeton, avec repli sur l'IP", function (): void {
    $alice = PlayerToken::mint(Locale::French);
    $bob = PlayerToken::mint(Locale::English);

    $expected = [
        'game-read' => EngineConstants::gameReadsPerMinute(),
        'game-write' => EngineConstants::gameWritesPerMinute(),
        'frame-serve' => EngineConstants::frameServePerMinute(),
    ];

    foreach ($expected as $name => $perMinute) {
        $forAlice = gameLimiterLimit($name, gameLimiterRequest($alice));
        $forAliceElsewhere = gameLimiterLimit($name, gameLimiterRequest($alice, '198.51.100.9'));
        $forBob = gameLimiterLimit($name, gameLimiterRequest($bob));
        $anonymous = gameLimiterLimit($name, gameLimiterRequest(null));

        expect($forAlice->maxAttempts)->toBe($perMinute)
            ->and($forAlice->decaySeconds)->toBe(60)
            // Par jeton : même compteur d'une adresse à l'autre, distinct d'un
            // autre jeton sur la même adresse.
            ->and($forAlice->key)->toBe('token:'.$alice->hash())
            ->and($forAliceElsewhere->key)->toBe($forAlice->key)
            ->and($forBob->key)->not->toBe($forAlice->key)
            // Sans jeton : l'adresse, dans un espace qui ne recouvre jamais
            // celui des jetons.
            ->and($anonymous->key)->toBe('ip:203.0.113.7');
    }

    // Une configuration du moteur change le débit : aucun littéral.
    engineConstantsConfigure(['game_writes_per_minute' => EngineConstants::DEFAULT_GAME_WRITES_PER_MINUTE + 7]);

    expect(gameLimiterLimit('game-write', gameLimiterRequest($alice))->maxAttempts)
        ->toBe(EngineConstants::DEFAULT_GAME_WRITES_PER_MINUTE + 7);

    // De bout en bout, sur la première route qui porte `game-read` : le jeton
    // qui a épuisé son débit reçoit 429, un autre jeton sur la même adresse
    // passe encore.
    $this->withCredentials();
    $asAlice = fn () => $this->withCookie(PlayerTokenCookie::NAME, json_encode($alice->toClaims(), JSON_THROW_ON_ERROR))
        ->getJson(route('clock.show'));

    for ($request = 0; $request < EngineConstants::gameReadsPerMinute(); $request++) {
        $asAlice()->assertOk();
    }

    $asAlice()->assertStatus(429);

    $this->withCookie(PlayerTokenCookie::NAME, json_encode($bob->toClaims(), JSON_THROW_ON_ERROR))
        ->getJson(route('clock.show'))
        ->assertOk();
});
