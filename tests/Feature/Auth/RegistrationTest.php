<?php

use App\Enums\ConsentKind;
use App\Enums\Locale;
use App\Enums\UserRole;
use App\Models\Player;
use App\Models\User;
use App\Models\UserConsent;
use App\Providers\FortifyServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Ouverture des comptes — spec 40 § 13.1 (L40-10, D66 du 07/10)
|--------------------------------------------------------------------------
|
| Inscription par mot de passe : cases CGU et âge distinctes, écrivain
| unique des consentements, limiteur `register` par adresse, règle de
| pseudo de jeu sur le nom du compte.
|
*/

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

/**
 * Un envoi d'inscription complet, à surcharger.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationPayload(array $overrides = []): array
{
    return [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        'age' => '1',
        ...$overrides,
    ];
}

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), registrationPayload());

    $this->assertAuthenticated();
    $response->assertRedirect(route('profile.edit', absolute: false));
});

it("refuse une inscription sans CGU ou sans déclaration d'âge", function (string $missing) {
    $payload = registrationPayload();
    unset($payload[$missing]);

    $this->post(route('register.store'), $payload)
        ->assertSessionHasErrors([$missing => __("account.consent.errors.{$missing}_required")]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and(UserConsent::query()->count())->toBe(0);
})->with(['terms', 'age']);

it('refuse une case non cochée envoyée vide', function () {
    $this->post(route('register.store'), registrationPayload(['terms' => '0', 'age' => '']))
        ->assertSessionHasErrors(['terms', 'age']);

    expect(User::query()->count())->toBe(0);
});

it('écrit deux lignes user_consent et les projections à la version courante dans la transaction de création', function () {
    Config::set('legal.terms_version', 'version-test');
    $this->withHeader('Accept-Language', 'fr');

    $this->post(route('register.store'), registrationPayload())->assertRedirect();

    $user = User::query()->sole();
    $consents = UserConsent::query()->where('user_id', $user->id)->orderBy('kind')->get();

    expect($consents->map(fn (UserConsent $consent): string => $consent->kind->value)->sort()->values()->all())
        ->toBe([ConsentKind::Age->value, ConsentKind::Terms->value])
        ->and($consents->pluck('version')->unique()->all())->toBe(['version-test'])
        ->and($user->terms_version)->toBe('version-test')
        ->and($user->terms_accepted_at)->not->toBeNull()
        ->and($user->age_confirmed_at)->not->toBeNull()
        ->and($user->terms_accepted_at?->equalTo($consents->firstWhere('kind', ConsentKind::Terms)?->accepted_at))->toBeTrue()
        // Aucun rôle, et la connexion qui suit pose `last_login_at`.
        ->and($user->role)->toBe(UserRole::Player)
        ->and($user->last_login_at)->not->toBeNull();
});

it('crée le compte dans la langue de la requête', function () {
    $this->withUnencryptedCookie('locale', 'fr')->post(route('register.store'), registrationPayload())->assertRedirect();

    expect(User::query()->sole()->locale)->toBe(Locale::French);
});

it('limite les inscriptions par adresse', function () {
    // Des envois refusés (CGU absentes) consomment aussi le seau.
    for ($i = 0; $i < FortifyServiceProvider::REGISTRATIONS_PER_HOUR; $i++) {
        $this->post(route('register.store'), registrationPayload(['terms' => null]))
            ->assertSessionHasErrors('terms');
    }

    $this->post(route('register.store'), registrationPayload())->assertTooManyRequests();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    // Une autre adresse garde son propre seau.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->post(route('register.store'), registrationPayload())
        ->assertRedirect();

    $this->assertAuthenticated();
});

it("ne compte rien quand l'inscription est fermée", function () {
    Config::set('accounts.registration_open', false);

    $this->post(route('register.store'), registrationPayload())->assertNotFound();

    expect(RateLimiter::attempts(md5('register'.'127.0.0.1')))->toBe(0);
});

it("applique la règle de pseudo de jeu au nom de compte, sans l'unicité par salon", function () {
    // Une écriture non latine : refusée comme à la prise de siège.
    $this->post(route('register.store'), registrationPayload(['name' => 'Борис']))
        ->assertSessionHasErrors('name');

    // Trop court.
    $this->post(route('register.store'), registrationPayload(['name' => 'A']))
        ->assertSessionHasErrors('name');

    expect(User::query()->count())->toBe(0);

    // Un pseudo déjà tenu dans un salon reste un nom de compte valide, et la
    // forme canonique est écrite (espaces réduits).
    Player::factory()->create(['nickname' => 'Camille']);

    $this->post(route('register.store'), registrationPayload(['name' => '  Camille   Martin ']))
        ->assertSessionHasNoErrors();

    expect(User::query()->sole()->name)->toBe('Camille Martin');
});
