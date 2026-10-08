<?php

use App\Models\Frame;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Support\Design\DesignScenarios;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\I18n\TranslationDomains;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Le banc d'essai du design — spec 20 § 13.8, ligne 50
|--------------------------------------------------------------------------
|
| Demande du porteur du 08/10 : chaque page joueur avec des données de test,
| administrateur seul, jamais en production, sans aucune écriture.
| `admin.design.index` est aussi jouée par la matrice (`AdminRoutes.php`) ;
| `design.frame`, hors du groupe `admin.*`, ne l'est qu'ici.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake(FrameStoragePrefix::DISK);
});

/** Les props de page propres à `design.frame`, partagées exclues. */
const DESIGN_FRAME_PROPS = [
    'scenario',
    'images',
    'bounds',
    'limits',
    'settings',
    'presets',
    'launch',
    'editor',
    'nickname',
    'avatars',
    'passwordRules',
    'report',
];

it("ouvre l'écran à un administrateur, avec chaque scénario du registre et son libellé", function (): void {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.design.index'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/design/index')
        ->has('scenarios', count(DesignScenarios::SCENARIOS))
        ->has('groups', count(DesignScenarios::GROUPS))
        ->where('designPreview', true));

    foreach (DesignScenarios::forIndex() as $scenario) {
        expect(Lang::has($scenario['labelKey'], 'fr'))->toBeTrue($scenario['labelKey'])
            ->and(in_array($scenario['group'], DesignScenarios::GROUPS, true))->toBeTrue($scenario['key']);
    }

    foreach (DesignScenarios::groupsForIndex() as $group) {
        expect(Lang::has($group['labelKey'], 'fr'))->toBeTrue($group['labelKey']);
    }
});

it("rend un scénario fictif de chaque hôte, dans la coquille de sa page, avec l'image réelle du catalogue publié", function (string $scenario, string $component): void {
    $movie = Movie::factory()->published()->create();
    $frame = Frame::factory()->for($movie)->published()->create();
    $admin = User::factory()->admin()->create();

    app()->forgetInstance(TranslationDomains::class);

    $response = $this->actingAs($admin)->get(route('design.frame', ['scenario' => $scenario]))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component($component)
        ->where('scenario', $scenario)
        ->where('images', [route('admin.catalog.frames.game', ['movie' => $movie->id, 'frame' => $frame->id], false)])
        ->where('editor', ['advancedAvailable' => true, 'themeSelectorVisible' => false, 'lateJoinAvailable' => true])
        ->has('settings.pool')
        ->has('presets', 4));

    // Domaines joueur, jamais `admin` : la page est une page joueur.
    $domains = array_values(array_unique(array_map(
        static fn (string $key): string => explode('.', $key, 2)[0],
        array_keys((array) $response->inertiaProps('translations')),
    )));
    sort($domains);

    expect($domains)->toBe(['account', 'common', 'game', 'legal', 'room']);
})->with([
    'jeu' => ['game.lobby_host', 'game/design-preview'],
    'authentification' => ['auth.login', 'auth/design-preview'],
    'pages publiques' => ['public.error_404', 'legal/design-preview'],
]);

it('rend la page fictive dans la langue demandée', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('design.frame', ['scenario' => 'game.round_normal', 'locale' => 'en']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en')->where('images', []));

    $this->actingAs($admin)
        ->get(route('design.frame', ['scenario' => 'game.round_normal', 'locale' => 'fr']))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'fr'));
});

it("refuse le banc d'essai à un curateur et à un joueur, et renvoie un invité à la connexion", function (): void {
    $urls = [route('admin.design.index'), route('design.frame', ['scenario' => 'game.lobby_host'])];

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    foreach ([User::factory()->curator()->create(), User::factory()->player()->create()] as $user) {
        foreach ($urls as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    expect(Gate::forUser(User::factory()->curator()->create())->allows('previewDesign', User::class))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('previewDesign', User::class))->toBeTrue();
});

it('répond 404 sur une clé hors registre ou sur un scénario réel', function (string $scenario): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/design/frame/'.$scenario)
        ->assertNotFound();
})->with(['game.inconnu', 'auth.nope', 'live.home', 'admin.dashboard', 'game']);

it('garde la page fictive derrière la même porte que le back-office, domaines joueur déclarés', function (): void {
    $middleware = Route::getRoutes()->getByName('design.frame')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('auth', 'verified', 'role:admin', 'admin.2fa', 'can:previewDesign,'.User::class, 'translations:account,game,room,legal')
        ->not->toContain('admin.locale');
});

it("n'envoie aucune donnée réelle de joueur et n'écrit rien en base", function (): void {
    $player = Player::factory()->create(['nickname' => 'PseudoReelUnique']);
    $admin = User::factory()->admin()->create();
    $counts = fn (): array => [Room::query()->count(), Player::query()->count(), Game::query()->count()];
    $before = $counts();

    foreach (array_keys(DesignScenarios::SCENARIOS) as $scenario) {
        if (! DesignScenarios::isFixture($scenario)) {
            continue;
        }

        app()->forgetInstance(TranslationDomains::class);

        $response = $this->actingAs($admin)->get(route('design.frame', ['scenario' => $scenario]))->assertOk();
        $page = $response->inertiaPage();
        $own = array_diff_key($page['props'], array_flip([
            'name', 'auth', 'sidebarOpen', 'accountsOpen', 'oauthProviders', 'consent',
            'frameFormat', 'realtime', 'maintenance', 'locale', 'locales', 'translations', 'errors', 'flash',
        ]));

        expect(array_keys($own))->toBe(DESIGN_FRAME_PROPS, $scenario)
            ->and((string) $response->getContent())->not->toContain('PseudoReelUnique')
            ->not->toContain((string) $player->public_id);
    }

    expect($counts())->toBe($before);
});

it('déclare côté client exactement les clés du registre serveur, et chaque scénario réel nomme une route', function (): void {
    $source = (string) file_get_contents(resource_path('js/lib/design/scenario-keys.ts'));

    preg_match_all("/'((?:live|game|auth|public)\.[a-z0-9_]+)'/", $source, $matches);

    $client = array_values(array_unique($matches[1]));
    $server = array_keys(DesignScenarios::SCENARIOS);

    sort($client);
    sort($server);

    expect($client)->toBe($server);

    foreach (DesignScenarios::SCENARIOS as $key => $scenario) {
        if (DesignScenarios::kind($key) === DesignScenarios::KIND_LIVE) {
            expect($scenario['route'])->toBeString($key)
                ->and(Route::has((string) $scenario['route']))->toBeTrue($key);
        } else {
            expect($scenario['route'])->toBeNull($key)
                ->and(DesignScenarios::host($key))->toBeIn(DesignScenarios::HOSTS);
        }
    }
});

it("n'enregistre aucune des deux routes en production, et la garde y refuse même un administrateur", function (): void {
    $admin = User::factory()->admin()->create();

    app()->detectEnvironment(fn (): string => 'production');

    // Les fichiers de routes relus sous l'environnement de production : c'est
    // à leur chargement que se décide l'enregistrement. Sans le groupe `web`,
    // inutile ici — seule compte la présence des routes.
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    require base_path('routes/web.php');
    $router->getRoutes()->refreshNameLookups();

    expect(app()->environment('production'))->toBeTrue()
        ->and(Route::has('admin.design.index'))->toBeFalse()
        ->and(Route::has('design.frame'))->toBeFalse()
        ->and(Route::has('admin.dashboard'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('previewDesign', User::class))->toBeFalse();

    $this->actingAs($admin)->get('/admin/design')->assertNotFound();
    $this->actingAs($admin)->get('/admin/design/frame/game.lobby_host')->assertNotFound();
});
