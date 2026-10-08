<?php

use App\Actions\Account\DeletePasskeyUnlessLastMethod;
use App\Enums\OAuthProvider;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Support\Identity\LoginMethods;
use App\Support\Identity\PasskeyRelyingParty;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;

/*
|--------------------------------------------------------------------------
| Passkeys en production — spec 40 § 13.8 (L40-15, n° 10 option A, D66)
|--------------------------------------------------------------------------
|
| Une passkey à vérification de l'utilisateur vaut second facteur À LA
| CONNEXION : elle saute le défi TOTP, sans jamais dispenser d'enrôler le
| TOTP (porte `admin.2fa`, `PrivilegedTwoFactorTest`). Le relying party vient
| de l'environnement, à défaut d'`APP_URL` ; supprimer la dernière méthode de
| connexion est refusé, par la même aide que la déliaison.
|
| La vérification cryptographique (`VerifyPasskey`) est simulée : aucune clé
| réelle, aucun authentificateur. Le format du `credential` est, lui, réel
| (`WebAuthn::fromJson`) : c'est la requête du paquet qui le lit.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    // Les passkeys sont ouvertes en `testing` quand rien n'est déclaré ; on
    // le déclare pour que ces tests ne dépendent pas du repli.
    config(['accounts.passkeys_enabled' => true]);

    RateLimiter::for('passkeys', fn () => Limit::none());
});

/** Une passkey enregistrée pour le compte, sans clé réelle. */
function passkeyFor(User $user, string $name = 'Clé de test'): Passkey
{
    /** @var Passkey */
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => 'credential-'.Str::random(16),
        'credential' => ['fixture' => true],
    ]);
}

/**
 * Joue une connexion par passkey complète : options (posées en session par le
 * paquet), puis envoi, la vérification rendant la passkey donnée.
 */
function passkeyLogin(mixed $test, Passkey $passkey): TestResponse
{
    $verify = Mockery::mock(VerifyPasskey::class);
    $verify->shouldReceive('__invoke')->once()->andReturn($passkey);
    app()->instance(VerifyPasskey::class, $verify);

    $test->getJson(route('passkey.login-options'))->assertOk();

    return $test->post(route('passkey.login'), ['credential' => passkeyAssertionPayload()]);
}

it("les options exigent la vérification de l'utilisateur", function () {
    $login = $this->getJson(route('passkey.login-options'))->assertOk();

    expect($login->json('options.userVerification'))->toBe('required');

    $user = User::factory()->create();

    $registration = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson(route('passkey.registration-options'))
        ->assertOk();

    expect($registration->json('options.authenticatorSelection.userVerification'))->toBe('required');
});

it('la connexion par passkey saute le défi TOTP', function () {
    $user = User::factory()->withTwoFactor()->create();
    $passkey = passkeyFor($user);

    $response = passkeyLogin($this, $passkey);

    $response->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($user);

    // Aucun défi en attente : la session n'est pas celle d'un mot de passe
    // accepté qui attend son code.
    expect(session()->has('login.id'))->toBeFalse();
});

it('une connexion par passkey passe par la ré-acceptation des CGU', function () {
    $user = User::factory()->outdatedTerms()->create();
    $passkey = passkeyFor($user);

    passkeyLogin($this, $passkey)->assertRedirect(config('fortify.home'));

    $this->get((string) config('fortify.home'))->assertRedirect(route('terms.show'));
});

it('une pierre tombale ne se connecte jamais par passkey', function () {
    $user = User::factory()->anonymized()->create();
    $passkey = passkeyFor($user);

    passkeyLogin($this, $passkey);

    $this->assertGuest();
});

it("le RP ID et les origines viennent de l'environnement, à défaut d'APP_URL", function () {
    // L'aide seule, sur toutes ses entrées.
    expect(PasskeyRelyingParty::id(null, 'https://jeu.example.test'))->toBe('jeu.example.test')
        ->and(PasskeyRelyingParty::id('', 'https://jeu.example.test'))->toBe('jeu.example.test')
        ->and(PasskeyRelyingParty::id(' example.test ', 'https://dev.example.test'))->toBe('example.test')
        ->and(PasskeyRelyingParty::id('https://Example.test/', 'https://dev.example.test'))->toBe('example.test')
        ->and(PasskeyRelyingParty::origins(null, 'https://jeu.example.test'))->toBe(['https://jeu.example.test'])
        ->and(PasskeyRelyingParty::origins('https://a.example.test/, ,https://b.example.test', 'https://x.example.test'))
        ->toBe(['https://a.example.test', 'https://b.example.test']);

    // Le câblage du fichier de configuration : les variables d'environnement
    // l'emportent, leur absence rend l'hôte et l'URL d'APP_URL.
    $read = function (?string $rpId, ?string $origins): array {
        $saved = [];

        foreach (['PASSKEYS_RP_ID' => $rpId, 'PASSKEYS_ALLOWED_ORIGINS' => $origins] as $name => $value) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];

            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }

        try {
            /** @var array{passkeys: array{relying_party_id: ?string, allowed_origins: list<string>}} $config */
            $config = require config_path('fortify.php');

            return $config['passkeys'];
        } finally {
            foreach ($saved as $name => [$server, $env, $process]) {
                unset($_SERVER[$name], $_ENV[$name]);

                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }

                if ($env !== null) {
                    $_ENV[$name] = $env;
                }

                putenv($process === false ? $name : "{$name}={$process}");
            }
        }
    };

    config(['app.url' => 'https://dev.example.test']);

    $declared = $read('example.test', 'https://dev.example.test,https://example.test');

    expect($declared['relying_party_id'])->toBe('example.test')
        ->and($declared['allowed_origins'])->toBe(['https://dev.example.test', 'https://example.test']);

    $fallback = $read(null, null);

    expect($fallback['relying_party_id'])->toBe('dev.example.test')
        ->and($fallback['allowed_origins'])->toBe(['https://dev.example.test']);
});

it('déclare les deux variables du relying party vides dans .env.example', function () {
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('.env.example'))) ?: [];

    expect($lines)->toContain('PASSKEYS_RP_ID=', 'PASSKEYS_ALLOWED_ORIGINS=');
});

it('refuse la suppression de la dernière méthode de connexion', function () {
    // Compte né d'un fournisseur puis délié de tout : la passkey est sa seule porte.
    $user = User::factory()->oauthOnly()->create();
    $passkey = passkeyFor($user);

    expect(LoginMethods::wouldRemoveLast($user, passkeyId: $passkey->id))->toBeTrue();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->delete(route('passkey.destroy', $passkey))
        ->assertSessionHasErrors(['passkey' => __('account.passkeys.errors.last_method')]);

    expect($passkey->fresh())->not->toBeNull();

    // Contre-épreuve : une seconde passkey, ou un fournisseur lié, rouvre la
    // suppression de la première.
    passkeyFor($user, 'Seconde clé');

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->delete(route('passkey.destroy', $passkey))
        ->assertSessionHasNoErrors();

    expect($passkey->fresh())->toBeNull();
});

it('la déliaison et la suppression de passkey comptent les méthodes par la même aide', function () {
    $user = User::factory()->oauthOnly()->create();
    LinkedAccount::factory()->for($user)->state(['provider' => OAuthProvider::Google])->create();
    $passkey = passkeyFor($user);

    // Fournisseur + passkey : retirer l'un laisse l'autre.
    expect(LoginMethods::remainingWithout($user, provider: OAuthProvider::Google))->toBe(1)
        ->and(LoginMethods::remainingWithout($user, passkeyId: $passkey->id))->toBe(1)
        ->and(LoginMethods::remainingWithout($user))->toBe(2);

    $source = (string) file_get_contents(app_path('Actions/Account/UnlinkProvider.php'));

    expect($source)->toContain('LoginMethods::wouldRemoveLast(')
        ->and(app(DeletePasskey::class))
        ->toBeInstanceOf(DeletePasskeyUnlessLastMethod::class);
});
