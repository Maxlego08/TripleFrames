<?php

use App\Enums\Locale;
use App\Models\User;
use App\Support\I18n\LocaleCookie;
use App\Support\I18n\TranslationDomains;
use App\Support\I18n\Translations;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use Illuminate\Support\Facades\App;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

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

/*
|--------------------------------------------------------------------------
| Niveau 3 : la revendication `locale` du `player_token` (spec 40 § 4.1)
|--------------------------------------------------------------------------
|
| Il couvre exactement un cas (A-38) : le cookie `locale` a disparu alors
| que le jeton est toujours là. Le cookie `locale`, choix manuel exprimé sur
| cet appareil, passe avant lui.
|
*/

/** Les `Set-Cookie` `player_token` d'une réponse. */
function setLocalePlayerTokenCookies(TestResponse $response): array
{
    return array_values(array_filter(
        $response->headers->getCookies(),
        static fn (SymfonyCookie $cookie): bool => $cookie->getName() === PlayerTokenCookie::NAME,
    ));
}

it("restaure la langue d'un invité depuis le player_token quand le cookie locale a disparu", function () {
    $token = PlayerToken::mint(Locale::French, 'preset-07');

    // Pas de cookie `locale` ; la négociation dirait `en`, le jeton dit `fr`.
    $response = $this
        ->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR))
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('/');

    $response->assertOk();

    expect(inertiaProps($response)['locale'])->toBe('fr')
        ->and(App::getLocale())->toBe('fr')
        // Lire le jeton ne le repose pas : aucun identifiant posé (I4.1).
        ->and(setLocalePlayerTokenCookies($response))->toBe([]);

    // Une revendication que l'enum ne connaît plus est nulle : la chaîne
    // descend à la négociation, sans erreur.
    $claims = $token->toClaims();
    $claims['locale'] = 'de';

    $unknown = $this
        ->withCookie(PlayerTokenCookie::NAME, json_encode($claims, JSON_THROW_ON_ERROR))
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('/');

    $unknown->assertOk();

    expect(inertiaProps($unknown)['locale'])->toBe('en');

    // Un jeton invalide — ici non chiffré — est absent : négociation aussi.
    $forged = $this
        ->withUnencryptedCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR))
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('/');

    $forged->assertOk();

    expect(inertiaProps($forged)['locale'])->toBe('en');
});

it('préfère le cookie locale à la revendication du player_token', function () {
    $french = PlayerToken::mint(Locale::French);

    $response = $this
        ->withCookie(PlayerTokenCookie::NAME, json_encode($french->toClaims(), JSON_THROW_ON_ERROR))
        ->withUnencryptedCookie(LocaleCookie::NAME, Locale::English->value)
        ->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
        ->get('/');

    $response->assertOk();

    expect(inertiaProps($response)['locale'])->toBe('en');

    // Dans l'autre sens aussi : c'est le rang qui décide, pas la langue.
    $english = PlayerToken::mint(Locale::English);

    $reverse = $this
        ->withCookie(PlayerTokenCookie::NAME, json_encode($english->toClaims(), JSON_THROW_ON_ERROR))
        ->withUnencryptedCookie(LocaleCookie::NAME, Locale::French->value)
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('/');

    $reverse->assertOk();

    expect(inertiaProps($reverse)['locale'])->toBe('fr');
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
| les trois pages légales et « signaler un contenu » (`routes/legal.php`,
| L90-4) le portent aussi (n° 69, spec 90 § 4.2 ; preuve : LegalPagesTest),
| leur habillage suivant la langue du visiteur. Hors de
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
