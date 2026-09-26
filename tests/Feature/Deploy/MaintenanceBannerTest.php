<?php

use App\Enums\Locale;
use App\Models\User;
use App\Support\Deploy\DeployDrain;
use App\Support\I18n\LocaleCookie;
use App\Support\I18n\TranslationDomains;
use App\ValueObjects\Deploy\DrainState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Prop partagée `maintenance` — spec 100 § 11.3, contrat C18-bis § 2 et § 3
|--------------------------------------------------------------------------
|
| Côté joueurs, le drainage se réduit à UN booléen dans les props Inertia :
| ni heure, ni phase, ni compte de parties, aucune diffusion Reverb. Le
| bandeau de 90 (L90-3b) le lit à la réponse suivante ; le refus de
| lancement, côté serveur, reste la seule garantie. Ce fichier prouve la
| prop ; le rendu du bandeau appartient à 90.
|
*/

beforeEach(function (): void {
    config(['app.debug' => false]);
    $this->withoutVite();

    Date::setTestNow(CarbonImmutable::parse('2026-09-26 14:00:00.250'));
});

/**
 * Chaque page joueur qui existe à ce lot, en invité et connecté, dans les
 * deux langues, plus la page `error` d'une URL inconnue. Les pages `room/*`,
 * `game/*` et `solo/*` reçoivent la même prop par le même middleware.
 *
 * @return array<string, Closure(): TestResponse>
 */
function maintenanceBannerPages(): array
{
    $verified = User::factory()->create();

    return [
        'accueil' => fn () => test()->get(route('home')),
        'accueil anglophone' => fn () => test()->withUnencryptedCookie(LocaleCookie::NAME, Locale::English->value)->get(route('home')),
        'mentions légales' => fn () => test()->get(route('legal.notice')),
        'CGU' => fn () => test()->get(route('legal.terms')),
        'confidentialité' => fn () => test()->get(route('legal.privacy')),
        'signaler un contenu' => fn () => test()->get(route('takedown.create')),
        'connexion' => fn () => test()->get(route('login')),
        'mot de passe oublié' => fn () => test()->get(route('password.request')),
        'page error' => fn () => test()->get('/__maintenance/definitely-unknown-url'),
        'tableau de bord' => fn () => test()->actingAs($verified)->get(route('dashboard')),
        'profil' => fn () => test()->actingAs($verified)->get(route('profile.edit')),
    ];
}

/**
 * La prop `maintenance` de chaque page, et rien du drapeau ailleurs dans la
 * page : ni ses instants, ni sa clé de cache.
 *
 * @param  array<string, Closure(): TestResponse>  $pages
 * @return array<string, mixed>
 */
function maintenanceBannerProps(array $pages, ?DrainState $state): array
{
    $props = [];

    foreach ($pages as $label => $visit) {
        // Une requête neuve : sélection de domaines vide (singleton), et aucun
        // compte hérité d'une visite connectée précédente (`actingAs`).
        app()->forgetInstance(TranslationDomains::class);
        app('auth')->forgetGuards();

        $response = $visit();

        expect($response->status())->toBeIn([200, 404], $label);

        $page = $response->viewData('page');

        expect($page)->toBeArray($label)
            ->and($page['props'] ?? null)->toBeArray($label)
            ->and(array_key_exists('maintenance', $page['props']))->toBeTrue("{$label} : prop maintenance absente");

        if ($state instanceof DrainState) {
            $json = (string) json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            foreach ([...array_values($state->toCache()), $state->expiresAt->utc()->toIso8601ZuluString(), config()->string('deploy.cache_key')] as $leak) {
                expect(str_contains($json, $leak))->toBeFalse("{$label} : le drapeau fuit ({$leak})");
            }
        }

        $props[$label] = $page['props']['maintenance'];
    }

    return $props;
}

it('partage le drapeau de drainage en booléen avec toute page joueur', function (): void {
    $pages = maintenanceBannerPages();
    $drain = app(DeployDrain::class);

    // Sans drapeau : faux, en booléen, partout.
    foreach (maintenanceBannerProps($pages, null) as $label => $value) {
        expect($value)->toBeFalse($label);
    }

    // Phase `draining` : vrai partout.
    $draining = $drain->start(DeployDrain::defaultTimeoutMinutes());

    foreach (maintenanceBannerProps($pages, $draining) as $label => $value) {
        expect($value)->toBeTrue($label);
    }

    // Phase `window` : vrai aussi — le drapeau bloque les lancements dans les
    // deux phases.
    $window = $drain->openWindow(config()->integer('deploy.window_minutes'));

    foreach (maintenanceBannerProps($pages, $window) as $label => $value) {
        expect($value)->toBeTrue($label);
    }

    // Drapeau échu, puis levé : faux de nouveau, dès la réponse suivante.
    $this->travelTo($window->expiresAt);

    foreach (maintenanceBannerProps($pages, null) as $label => $value) {
        expect($value)->toBeFalse($label);
    }

    $this->travelTo($window->startedAt);
    $drain->openWindow(config()->integer('deploy.window_minutes'));
    $drain->release();

    foreach (maintenanceBannerProps($pages, null) as $label => $value) {
        expect($value)->toBeFalse($label);
    }
});
