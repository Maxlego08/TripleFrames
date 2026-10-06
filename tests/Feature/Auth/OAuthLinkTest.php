<?php

use App\Avatars\AccountImage;
use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Enums\OAuthProvider;
use App\Jobs\Account\CopyProviderAvatar;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PragmaRX\Google2FA\Google2FA;

/*
|--------------------------------------------------------------------------
| Comptes liés, déliaison, compte sans mot de passe — spec 40 § 12.4 et
| § 12.5, D51 du 01/10
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();
    Queue::fake([CopyProviderAvatar::class]);
    Storage::fake(UploadedAvatars::DISK);

    foreach (['google', 'discord'] as $provider) {
        Config::set("services.{$provider}.client_id", 'test-id');
        Config::set("services.{$provider}.client_secret", 'test-secret');
    }
});

function oauthLinkGoogle(string $id = 'google-123'): SocialiteUser
{
    return SocialiteUser::fake([
        'id' => $id,
        'name' => 'Alice',
        'email' => 'autre@example.test',
        'email_verified' => true,
        'avatar' => 'https://lh3.googleusercontent.com/a/photo',
    ]);
}

/** Une confirmation fraîche, comme après `password.confirm`. */
function oauthLinkConfirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}

it('lie un fournisseur au compte connecté depuis les réglages', function () {
    $user = User::factory()->create();
    Socialite::fake('google', oauthLinkGoogle());

    $this->actingAs($user)->get(route('oauth.redirect', ['provider' => 'google', 'intent' => 'link']));
    $this->actingAs($user)->get(route('oauth.callback', ['provider' => 'google']))
        ->assertRedirect(route('linked_accounts.edit'));

    expect(LinkedAccount::query()->sole()->user_id)->toBe($user->id);

    $this->actingAs($user)->get(route('linked_accounts.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/accounts')
            ->where('providers.0.provider', 'discord')
            ->where('providers.0.linked', false)
            ->where('providers.1.provider', 'google')
            ->where('providers.1.linked', true)
            ->missing('providers.1.provider_user_id'));
});

it('refuse de lier un compte fournisseur déjà lié à un autre compte', function () {
    $other = User::factory()->create();
    LinkedAccount::factory()->for($other)->create(['provider' => OAuthProvider::Google, 'provider_user_id' => 'google-123']);
    $user = User::factory()->create();
    Socialite::fake('google', oauthLinkGoogle());

    $this->actingAs($user)->get(route('oauth.redirect', ['provider' => 'google', 'intent' => 'link']));
    $this->actingAs($user)->get(route('oauth.callback', ['provider' => 'google']))
        ->assertRedirect(route('linked_accounts.edit'))
        ->assertSessionHasErrors(['oauth' => __('account.oauth.errors.linked_elsewhere')]);

    $this->assertAuthenticatedAs($user);
    expect(LinkedAccount::query()->count())->toBe(1);
});

it('confirme par le fournisseur un compte sans mot de passe', function () {
    $user = User::factory()->create(['password' => null]);
    LinkedAccount::factory()->for($user)->create(['provider' => OAuthProvider::Google, 'provider_user_id' => 'google-123']);
    Socialite::fake('google', oauthLinkGoogle());

    $this->actingAs($user)->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasPassword', false)
            ->where('confirmProviders', ['google']));

    $this->actingAs($user)->get(route('oauth.redirect', ['provider' => 'google', 'intent' => 'confirm']));
    $this->actingAs($user)->get(route('oauth.callback', ['provider' => 'google']))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('auth.password_confirmed_at');
});

it('refuse de confirmer par un compte fournisseur qui n’est pas le sien', function () {
    $user = User::factory()->create(['password' => null]);
    Socialite::fake('google', oauthLinkGoogle());

    $this->actingAs($user)->get(route('oauth.redirect', ['provider' => 'google', 'intent' => 'confirm']));
    $this->actingAs($user)->get(route('oauth.callback', ['provider' => 'google']))
        ->assertSessionHasErrors(['oauth' => __('account.oauth.errors.confirm_mismatch')])
        ->assertSessionMissing('auth.password_confirmed_at');
});

it('laisse un compte sans mot de passe en définir un sans ancien mot de passe', function () {
    $user = User::factory()->create(['password' => null]);

    $this->actingAs($user)->withSession(oauthLinkConfirmed())
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('hasPassword', false));

    $this->actingAs($user)->withSession(oauthLinkConfirmed())
        ->put(route('user-password.update'), ['password' => 'Nouveau-mot-de-passe-42', 'password_confirmation' => 'Nouveau-mot-de-passe-42'])
        ->assertSessionHasNoErrors();

    expect(Hash::check('Nouveau-mot-de-passe-42', (string) $user->refresh()->password))->toBeTrue();
});

it('refuse de délier la dernière méthode de connexion', function () {
    $user = User::factory()->create(['password' => null]);
    LinkedAccount::factory()->for($user)->create(['provider' => OAuthProvider::Google]);

    $this->actingAs($user)->withSession(oauthLinkConfirmed())
        ->delete(route('linked_accounts.destroy', ['provider' => 'google']))
        ->assertSessionHasErrors(['provider' => __('account.oauth.errors.last_method')]);

    expect(LinkedAccount::query()->count())->toBe(1);
});

it('exige une confirmation fraîche pour délier', function () {
    $user = User::factory()->create();
    LinkedAccount::factory()->for($user)->create(['provider' => OAuthProvider::Google]);

    $this->actingAs($user)
        ->delete(route('linked_accounts.destroy', ['provider' => 'google']))
        ->assertRedirect(route('password.confirm'));

    expect(LinkedAccount::query()->count())->toBe(1);
});

it('délie et supprime la photo venue de ce fournisseur', function () {
    $user = User::factory()->create(['avatar_preset' => 'preset-06']);
    LinkedAccount::factory()->for($user)->create(['provider' => OAuthProvider::Google]);
    $path = UploadedAvatars::newPath(AccountImage::Provider);
    Storage::disk(UploadedAvatars::DISK)->put($path, 'octets');
    $user->forceFill([
        'avatar_kind' => AvatarKind::Provider,
        'avatar_provider_path' => $path,
        'avatar_provider_source' => OAuthProvider::Google,
    ])->save();

    $this->actingAs($user)->withSession(oauthLinkConfirmed())
        ->delete(route('linked_accounts.destroy', ['provider' => 'google']))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect(LinkedAccount::query()->count())->toBe(0)
        ->and($user->avatar_provider_path)->toBeNull()
        ->and($user->avatar_provider_source)->toBeNull()
        ->and($user->avatar_kind)->toBe(AvatarKind::Preset);
    Storage::disk(UploadedAvatars::DISK)->assertMissing($path);
});

it('exige un code de second facteur pour délier sous double authentification', function () {
    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();
    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt($secret)]);
    LinkedAccount::factory()->for($user)->create(['provider' => OAuthProvider::Google]);

    $this->actingAs($user)->withSession(oauthLinkConfirmed())
        ->delete(route('linked_accounts.destroy', ['provider' => 'google']), ['code' => '000000'])
        ->assertSessionHasErrors(['code' => __('account.linked.errors.code')]);

    $code = $google2fa->getCurrentOtp($secret);

    $this->actingAs($user)->withSession(oauthLinkConfirmed())
        ->delete(route('linked_accounts.destroy', ['provider' => 'google']), ['code' => $code])
        ->assertSessionHasNoErrors();

    expect(LinkedAccount::query()->count())->toBe(0);
});
