<?php

use App\Enums\Locale;
use App\Models\User;
use App\Support\I18n\LocaleCookie;
use App\Support\I18n\TranslationDomains;
use App\Support\I18n\Translations;
use Illuminate\Support\Facades\App;
use Illuminate\Testing\TestResponse;

/**
 * Ordre de résolution de la langue et forme des props partagées.
 *
 * Le contrat consommé par le front tient en trois props : `locale` (le code
 * actif), `locales` (le registre, libellés natifs) et `translations` (le
 * dictionnaire **aplati en clés pointées**, limité aux domaines de la page).
 */

/**
 * @return array<string, mixed>
 */
function inertiaProps(TestResponse $response): array
{
    $page = $response->viewData('page');

    return is_array($page) && is_array($page['props']) ? $page['props'] : [];
}

it('falls back to the instance locale when nothing is known', function () {
    $this->get('/')->assertOk();

    expect(App::getLocale())->toBe(Translations::fallback()->value);
});

it('negotiates the language of a first visit and makes it sticky', function () {
    $response = $this
        ->withHeader('Accept-Language', 'fr-CA,fr;q=0.9,en;q=0.8')
        ->get('/');

    $response->assertOk();
    $response->assertCookie(LocaleCookie::NAME, Locale::French->value, encrypted: false);

    expect(inertiaProps($response)['locale'])->toBe('fr');
});

it('respects quality values and ignores unknown languages', function () {
    $response = $this
        ->withHeader('Accept-Language', 'de-DE,de;q=0.9,en-GB;q=0.8,fr;q=0.2')
        ->get('/');

    expect(inertiaProps($response)['locale'])->toBe('en');
});

it('prefers a cookie over the negotiated language', function () {
    $response = $this
        ->withUnencryptedCookie(LocaleCookie::NAME, Locale::French->value)
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('/');

    expect(inertiaProps($response)['locale'])->toBe('fr');
});

it('ignores a cookie that does not name an activated locale', function () {
    $response = $this
        ->withUnencryptedCookie(LocaleCookie::NAME, '../../etc/passwd')
        ->get('/');

    $response->assertOk();

    expect(inertiaProps($response)['locale'])->toBe('en');
});

it('prefers the account language over every device signal', function () {
    $user = User::factory()->locale(Locale::French)->create();

    $response = $this
        ->actingAs($user)
        ->withUnencryptedCookie(LocaleCookie::NAME, Locale::English->value)
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get(route('profile.edit'));

    expect(inertiaProps($response)['locale'])->toBe('fr');
});

it('persists the chosen language on the account and on the device', function () {
    $user = User::factory()->locale(Locale::English)->create();

    $response = $this
        ->actingAs($user)
        ->from('/')
        ->post(route('locale.update'), ['locale' => Locale::French->value]);

    $response->assertRedirect('/');
    $response->assertCookie(LocaleCookie::NAME, Locale::French->value, encrypted: false);

    expect($user->refresh()->locale)->toBe(Locale::French);
});

it('refuses a language that is not activated', function () {
    $this
        ->from('/')
        ->post(route('locale.update'), ['locale' => 'de'])
        ->assertSessionHasErrors('locale');
});

it('shares the activated locales with their native label', function () {
    $locales = inertiaProps($this->get('/'))['locales'];

    expect($locales)->toBe([
        ['value' => 'en', 'label' => 'English', 'bcp47' => 'en', 'dir' => 'ltr'],
        ['value' => 'fr', 'label' => 'Français', 'bcp47' => 'fr', 'dir' => 'ltr'],
    ]);
});

it('ships a flat dictionary limited to the domains the page declares', function () {
    $translations = inertiaProps($this->get('/'))['translations'];

    expect($translations)
        ->toHaveKey('common.action.save')
        ->toHaveKey('legal.tmdb.attribution')
        ->and(array_keys($translations))
        ->each->toMatch('/^(common|legal)\./');
});

it('ships the account domain to a settings screen and never the admin one', function () {
    $user = User::factory()->create();

    $translations = inertiaProps($this->actingAs($user)->get(route('profile.edit')))['translations'];

    expect($translations)->toHaveKey('account.profile.heading');

    foreach (array_keys($translations) as $key) {
        expect($key)->not->toStartWith(TranslationDomains::ADMIN.'.');
    }
});

it('preloads the dictionary of a target locale without switching the request', function () {
    $response = $this->get('/?'.Translations::PREVIEW_PARAM.'=fr');

    $props = inertiaProps($response);

    expect($props['locale'])->toBe('en')
        ->and($props['translations']['common.action.save'])->toBe('Enregistrer');
});

/*
|--------------------------------------------------------------------------
| `Vary: Accept-Language`, là où le contenu varie avec la locale
|--------------------------------------------------------------------------
|
| L'accueil est négocié depuis `Accept-Language` et doit le DIRE aux caches ;
| les trois pages légales et « signaler un contenu » le porteront aussi (n° 69,
| spec 90 § 4.2, L90-4), leur habillage suivant la langue du visiteur. Hors de
| ces pages, la route ne pose pas `VaryOnLanguage::ROUTE_FLAG` et l'en-tête
| n'est jamais émis (`login` ici) — d'où les deux assertions, de sens opposé.
|
*/

it('declares that the home page varies with the requested language', function () {
    expect($this->get('/')->headers->get('Vary'))->toContain('Accept-Language');
});

it('never declares it on a page whose content does not vary with the language', function () {
    expect($this->get(route('login'))->headers->get('Vary') ?? '')
        ->not->toContain('Accept-Language');
});
