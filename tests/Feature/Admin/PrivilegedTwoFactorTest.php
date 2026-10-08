<?php

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;

/*
|--------------------------------------------------------------------------
| Second facteur des rôles privilégiés — spec 40 § 13.8 (n° 10 option A)
|--------------------------------------------------------------------------
|
| La 2FA TOTP reste obligatoire pour `curator` et `admin` : le titulaire ne
| la coupe pas (`two_factor.keep` sur `two-factor.disable`, bouton masqué),
| et une session ouverte par passkey sans TOTP confirmé n'entre pas au
| back-office (la porte `admin.2fa` ne lit que `two_factor_confirmed_at`).
|
*/

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    $this->withoutVite();
});

it('un curateur ou un administrateur ne désactive pas son second facteur', function (string $role) {
    /** @var User $user */
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->delete(route('two-factor.disable'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHasErrors(['two_factor' => __('account.two_factor.errors.privileged_required')]);

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->not->toBeNull();

    // Le refus vaut aussi pour une requête JSON : 422, rien d'exécuté.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('two-factor.disable'))
        ->assertUnprocessable();

    expect($user->fresh()?->two_factor_confirmed_at)->not->toBeNull();

    // L'écran Sécurité masque le bouton.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('twoFactorEnabled', true)
            ->where('canDisableTwoFactor', false));
})->with(['curateur' => 'curator', 'administrateur' => 'admin']);

it('un joueur garde la désactivation de son second facteur', function () {
    $user = User::factory()->player()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('canDisableTwoFactor', true));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->delete(route('two-factor.disable'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

it("une session ouverte par passkey sans TOTP confirmé n'entre pas au back-office", function () {
    config(['accounts.passkeys_enabled' => true]);
    RateLimiter::for('passkeys', fn () => Limit::none());

    $curator = User::factory()->curator()->withoutTwoFactor()->create();

    /** @var Passkey $passkey */
    $passkey = $curator->passkeys()->create([
        'name' => 'Clé du curateur',
        'credential_id' => 'credential-'.Str::random(16),
        'credential' => ['fixture' => true],
    ]);

    $this->mock(VerifyPasskey::class, function ($mock) use ($passkey): void {
        $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
    });

    $this->getJson(route('passkey.login-options'))->assertOk();
    $this->post(route('passkey.login'), ['credential' => passkeyAssertionPayload()]);

    $this->assertAuthenticatedAs($curator);

    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.two_factor.required'));
});

it('un curateur ou un administrateur ne remplace pas son secret TOTP confirmé par force', function (string $role) {
    /** @var User $user */
    $user = User::factory()->{$role}()->create();
    $secret = $user->two_factor_secret;
    $codes = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('two-factor.enable'), ['force' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['two_factor' => __('account.two_factor.errors.privileged_rotate')]);

    $user->refresh();

    expect($user->two_factor_secret)->toBe($secret)
        ->and($user->two_factor_recovery_codes)->toBe($codes)
        ->and($user->two_factor_confirmed_at)->not->toBeNull();

    // Sans `force`, l'activation sur un secret existant reste sans effet et passe.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->post(route('two-factor.enable'))
        ->assertSessionHasNoErrors();

    expect($user->fresh()?->two_factor_secret)->toBe($secret);
})->with(['curateur' => 'curator', 'administrateur' => 'admin']);

it('un joueur garde la régénération forcée de son secret TOTP', function () {
    $user = User::factory()->player()->withTwoFactor()->create();
    $secret = $user->two_factor_secret;

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->post(route('two-factor.enable'), ['force' => true])
        ->assertSessionHasNoErrors();

    expect($user->fresh()?->two_factor_secret)->not->toBe($secret);
});
