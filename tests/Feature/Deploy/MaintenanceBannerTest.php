<?php

use App\Enums\Locale;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Deploy\DeployDrain;
use App\Support\I18n\LocaleCookie;
use App\Support\I18n\TranslationDomains;
use App\Support\Identity\PlayerToken;
use App\ValueObjects\Deploy\DrainState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Tests\Support\I18n\FrontSource;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

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

it('relit le drapeau levé par un rechargement partiel de la seule prop, sans supplanter l’onglet (BUG-P2)', function (): void {
    SeatEntry::isolateCookies();

    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token);
    LobbyWrites::actAs($this, $token);

    $active = (string) $host->active_seat_token;
    $drain = app(DeployDrain::class);

    // Le rechargement partiel que la page envoie tant qu'elle affiche le
    // drapeau : jeton d'onglet présenté, seule `maintenance` demandée.
    $reload = fn (): array => $this->get(route('room.show', $room), [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'game/lobby',
        'X-Inertia-Partial-Data' => 'maintenance',
        EnsureActiveSeat::HEADER => $active,
    ])->assertOk()->json('props');

    $drain->start(DeployDrain::defaultTimeoutMinutes());

    expect($reload()['maintenance'] ?? null)->toBeTrue();

    $drain->release();
    $props = $reload();

    // Levé, relu faux ; ni paquet, ni réglages, ni jeton reconstruits, et
    // l'onglet garde la main.
    expect($props['maintenance'] ?? null)->toBeFalse()
        ->and(array_values(array_intersect(array_keys($props), ['state', 'seatToken', 'settings', 'presets'])))->toBe([])
        ->and($host->fresh()?->active_seat_token)->toBe($active);

    // Les deux pages qui désactivent un geste pendant le drainage le relisent
    // tant qu'elles l'affichent et que l'onglet tient le siège.
    foreach (['lobby', 'solo'] as $page) {
        $source = FrontSource::withoutComments((string) file_get_contents(resource_path("js/pages/game/{$page}.tsx")));

        expect($source)->toContain('useMaintenanceRefresh(maintenance && active)');
    }
});
