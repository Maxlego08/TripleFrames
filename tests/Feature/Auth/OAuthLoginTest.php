<?php

use App\Enums\OAuthProvider;
use App\Enums\UserRole;
use App\Http\Controllers\Auth\OAuthController;
use App\Jobs\Account\CopyProviderAvatar;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
|--------------------------------------------------------------------------
| Connexion par Discord et Google — spec 40 § 12.1 à § 12.3, D51 du 01/10
|--------------------------------------------------------------------------
|
| Socialite est SIMULÉ : aucune clé réelle, aucun appel réseau (CI à zéro
| secret). Chaque test rejoue l'aller (`oauth.redirect`, qui pose
| l'intention en session) puis le retour (`oauth.callback`).
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    Queue::fake([CopyProviderAvatar::class]);

    foreach (['google', 'discord'] as $provider) {
        Config::set("services.{$provider}.client_id", 'test-id');
        Config::set("services.{$provider}.client_secret", 'test-secret');
    }

    // L'inscription par mot de passe est fermée : la connexion par
    // fournisseur ne la lit pas (D51 du 01/10).
    Config::set('accounts.registration_open', false);
});

/**
 * Un retour Google simulé.
 *
 * @param  array<string, mixed>  $attributes
 */
function oauthGoogle(array $attributes = []): SocialiteUser
{
    return SocialiteUser::fake([
        'id' => 'google-123',
        'name' => 'Alice Martin',
        'given_name' => 'Alice',
        'email' => 'alice@example.test',
        'email_verified' => true,
        'avatar' => 'https://lh3.googleusercontent.com/a/photo',
        ...$attributes,
    ]);
}

it('répond 404 pour un fournisseur sans clés', function () {
    Config::set('services.discord.client_id', '');

    $this->get(route('oauth.redirect', ['provider' => 'discord']))->assertNotFound();
    $this->get(route('oauth.callback', ['provider' => 'discord']))->assertNotFound();
});

it('expose les fournisseurs actifs en prop partagée', function () {
    Config::set('services.discord.client_secret', '');

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('oauthProviders', ['google']));
});

it('pose l’intention en session et renvoie au fournisseur', function () {
    Socialite::fake('google', oauthGoogle());

    $this->get(route('oauth.redirect', ['provider' => 'google']))
        ->assertRedirect('https://socialite.fake/google/authorize')
        ->assertSessionHas(OAuthController::INTENT_KEY, ['provider' => 'google', 'intent' => 'login']);
});

it('met en attente l’inscription d’un inconnu, puis crée le compte avec ses trois consentements', function () {
    Socialite::fake('google', oauthGoogle());

    $this->get(route('oauth.redirect', ['provider' => 'google']));
    $this->get(route('oauth.callback', ['provider' => 'google']))->assertRedirect(route('oauth.finish'));

    expect(User::query()->count())->toBe(0);

    $this->get(route('oauth.finish'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/oauth-finish')
            ->where('provider', 'google')
            ->where('suggestedName', 'Alice'));

    $this->post(route('oauth.finish.store'), ['name' => 'Alice', 'terms' => '1'])
        ->assertSessionHasErrors('age');

    $this->post(route('oauth.finish.store'), ['name' => 'Alice M', 'terms' => '1', 'age' => '1'])
        ->assertRedirect(Config::string('fortify.home'));

    $user = User::query()->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Alice M')
        ->and($user->email)->toBe('alice@example.test')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->role)->toBe(UserRole::Player)
        ->and($user->terms_version)->toBe(Config::string('legal.terms_version'))
        ->and(UserConsent::query()->where('user_id', $user->id)->pluck('kind')->map(fn ($kind) => $kind->value)->sort()->values()->all())
        ->toBe(['age', 'provider_google', 'terms'])
        ->and(LinkedAccount::query()->sole()->provider)->toBe(OAuthProvider::Google);

    Queue::assertPushed(CopyProviderAvatar::class);
});

it('connecte le titulaire d’un compte fournisseur déjà lié', function () {
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create(['provider' => OAuthProvider::Google, 'provider_user_id' => 'google-123']);
    Socialite::fake('google', oauthGoogle());

    $this->get(route('oauth.redirect', ['provider' => 'google']));
    $this->get(route('oauth.callback', ['provider' => 'google']))->assertRedirect(Config::string('fortify.home'));

    $this->assertAuthenticatedAs($user);
});

it('passe par le défi de double authentification d’un compte qui l’a activée', function () {
    $user = User::factory()->withTwoFactor()->create();
    LinkedAccount::factory()->for($user)->create(['provider' => OAuthProvider::Google, 'provider_user_id' => 'google-123']);
    Socialite::fake('google', oauthGoogle());

    $this->get(route('oauth.redirect', ['provider' => 'google']));
    $this->get(route('oauth.callback', ['provider' => 'google']))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

it('lie automatiquement sur une adresse vérifiée des deux côtés', function () {
    $user = User::factory()->create(['email' => 'alice@example.test']);
    Socialite::fake('google', oauthGoogle());

    $this->get(route('oauth.redirect', ['provider' => 'google']));
    $this->get(route('oauth.callback', ['provider' => 'google']));

    $this->assertAuthenticatedAs($user);
    expect(LinkedAccount::query()->sole()->user_id)->toBe($user->id);
});

it('refuse la liaison automatique sur une adresse non vérifiée par le fournisseur', function () {
    User::factory()->create(['email' => 'alice@example.test']);
    Socialite::fake('google', oauthGoogle(['email_verified' => false]));

    $this->get(route('oauth.redirect', ['provider' => 'google']));
    $this->get(route('oauth.callback', ['provider' => 'google']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['oauth' => __('account.oauth.errors.email_taken')]);

    $this->assertGuest();
    expect(LinkedAccount::query()->count())->toBe(0);
});

it('refuse de lier sans second facteur un compte à double authentification', function () {
    User::factory()->withTwoFactor()->create(['email' => 'alice@example.test']);
    Socialite::fake('google', oauthGoogle());

    $this->get(route('oauth.redirect', ['provider' => 'google']));
    $this->get(route('oauth.callback', ['provider' => 'google']))
        ->assertSessionHasErrors(['oauth' => __('account.oauth.errors.two_factor_link')]);

    $this->assertGuest();
    expect(LinkedAccount::query()->count())->toBe(0);
});

it('accepte un compte Discord sans adresse e-mail', function () {
    Socialite::fake('discord', SocialiteUser::fake([
        'id' => 'discord-9',
        'username' => 'zoe',
        'global_name' => 'Zoé',
        'email' => null,
        'verified' => false,
        'avatar' => null,
    ]));

    $this->get(route('oauth.redirect', ['provider' => 'discord']));
    $this->get(route('oauth.callback', ['provider' => 'discord']))->assertRedirect(route('oauth.finish'));
    $this->post(route('oauth.finish.store'), ['name' => 'Zoé', 'terms' => '1', 'age' => '1']);

    $user = User::query()->sole();

    expect($user->email)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue();
    Queue::assertNotPushed(CopyProviderAvatar::class);
});

it('refuse un retour sans intention et un échec du fournisseur, avec un message traduit', function () {
    Socialite::fake('google', fn () => throw new RuntimeException('refus'));

    $this->get(route('oauth.callback', ['provider' => 'google']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['oauth' => __('account.oauth.errors.failed')]);

    $this->get(route('oauth.redirect', ['provider' => 'google']));
    $this->get(route('oauth.callback', ['provider' => 'google']))
        ->assertSessionHasErrors(['oauth' => __('account.oauth.errors.failed')]);
});

it('fait expirer une inscription en attente', function () {
    Socialite::fake('google', oauthGoogle());

    $this->get(route('oauth.redirect', ['provider' => 'google']));
    $this->get(route('oauth.callback', ['provider' => 'google']));
    $this->travel(OAuthController::PENDING_TTL_SECONDS + 1)->seconds();

    $this->get(route('oauth.finish'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['oauth' => __('account.oauth.errors.pending_expired')]);
});
