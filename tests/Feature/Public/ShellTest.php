<?php

use App\Enums\Locale;
use App\Http\Middleware\ForceGameAppearance;
use App\Models\User;
use App\Support\I18n\TranslationDomains;
use App\Support\Identity\PlayerToken;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\I18n\FrontSource;
use Tests\Support\Room\LobbyWrites;

/*
|--------------------------------------------------------------------------
| Coquilles et thème — spec 90 § 2, contrat C16 § 2.1-2.4
|--------------------------------------------------------------------------
|
| Créé par L90-1, complété par L90-3b (bandeau de maintenance), L90-7
| (coquille de jeu, forçage sombre de `game/*`, région vivante unique) et
| L90-9. « rend le lobby en sombre quelle que soit l'apparence du visiteur »
| y est écrit par L50-4, première page `game/*` servie par une vraie route
| (dépendance inversée, R-04).
|
| Un seul forçage d'apparence existe dans le produit : les pages `game/*`, en
| sombre. Le back-office a perdu le sien (D8 du 23/09) : forcer le clair
| n'avait d'autre motif qu'une préférence, alors que la revue d'une image
| exige de la voir telle qu'elle sera servie en jeu — ce que seul un cadre
| sombre LOCAL donne. Il suit donc l'apparence du visiteur, lue dans le
| cookie `appearance` par `HandleAppearance`, comme tout le reste du site.
|
| Aucun DOM au jalon 1, ni en Pest ni en Vitest (C18 § 2.4) : ce que rend la
| coquille côté client se prouve sur la source sans ses commentaires
| (`FrontSource`) ; ce que rend Blade, sur la réponse.
|
*/

/**
 * La balise `<html>` d'une réponse rendue par `app.blade.php`.
 */
function shellHtmlTag(TestResponse $response): string
{
    $matched = preg_match('/<html\b[^>]*>/i', (string) $response->getContent(), $match);

    expect($matched)->toBe(1, "la réponse n'a pas de balise <html>");

    return $match[0];
}

/**
 * Classes CSS de la balise `<html>`.
 *
 * @return list<string>
 */
function shellHtmlClasses(string $tag): array
{
    if (preg_match('/\sclass="([^"]*)"/', $tag, $match) !== 1) {
        return [];
    }

    return array_values(array_filter(preg_split('/\s+/', $match[1]) ?: []));
}

/**
 * Source d'un fichier de `resources/js`, sans ses commentaires : un docblock
 * qui CITE une règle (« aucun `Toaster` ») n'est pas une infraction.
 */
function shellSource(string $relative): string
{
    $path = resource_path('js/'.$relative);

    expect(is_file($path))->toBeTrue("{$relative} est introuvable");

    return FrontSource::withoutComments((string) file_get_contents($path));
}

/**
 * Fichiers `.ts` et `.tsx` sous des chemins de `resources/js` (répertoires
 * parcourus récursivement, fichiers pris tels quels ; un chemin absent n'est
 * pas une erreur : l'écran n'existe pas encore).
 *
 * @param  list<string>  $entries  chemins relatifs à `resources/js`
 * @return list<string> chemins relatifs à `resources/js`, séparateur `/`, triés
 */
function shellFrontFiles(array $entries): array
{
    $root = resource_path('js');
    $files = [];

    foreach ($entries as $entry) {
        $path = $root.'/'.$entry;

        if (is_file($path)) {
            $files[] = $entry;

            continue;
        }

        if (! is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }

    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

/**
 * Balises ouvrantes `<Nom …>` d'une source JSX, attributs compris, jusqu'au
 * `>` qui la ferme hors de toute expression `{…}` et de toute chaîne (une
 * flèche `=>` dans un gestionnaire n'y met pas fin). `\b` écarte les noms
 * plus longs : `<Alert` ne capture pas `<AlertDescription`.
 *
 * @return list<string>
 */
function shellOpeningTags(string $source, string $name): array
{
    $tags = [];
    $length = strlen($source);
    $offset = 0;

    while (preg_match('/<'.preg_quote($name, '/').'\b/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $match[0][1];
        $depth = 0;
        $quote = null;
        $index = $start + strlen($match[0][0]);

        for (; $index < $length; $index++) {
            $char = $source[$index];

            if ($quote !== null) {
                if ($char === '\\') {
                    $index++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'" || $char === '`') {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            } elseif ($char === '>' && $depth === 0) {
                break;
            }
        }

        $tags[] = substr($source, $start, $index - $start + 1);
        $offset = $index + 1;
    }

    return $tags;
}

/**
 * Le `switch` de coquilles d'`app.tsx`, en groupes ordonnés : les conditions
 * `case` (ou `default`) qui mènent à un même `return`, et ce `return`.
 *
 * @return list<array{conditions: list<string>, layout: string}>
 */
function shellLayoutSwitch(): array
{
    $source = shellSource('app.tsx');

    expect(preg_match('/layout:\s*\(name\)\s*=>\s*\{\s*switch\s*\(true\)\s*\{(.*?)\n {8}\}\n/s', $source, $match))
        ->toBe(1, "le switch de coquilles d'app.tsx est introuvable");

    preg_match_all('/case\s+(.+?):|default:|return\s+(.+?);/s', $match[1], $tokens, PREG_SET_ORDER);

    $groups = [];
    $conditions = [];

    foreach ($tokens as $token) {
        if (isset($token[2]) && $token[2] !== '') {
            $groups[] = ['conditions' => $conditions, 'layout' => trim((string) preg_replace('/\s+/', ' ', $token[2]))];
            $conditions = [];

            continue;
        }

        $conditions[] = $token[0] === 'default:' ? 'default' : trim($token[1]);
    }

    expect($conditions)->toBe([], 'un case sans return clôt le switch');

    return $groups;
}

/**
 * Classes de contrôleur que `routes/game.php` importe, et les traits qu'elles
 * emploient : c'est leur code qui répond aux routes de jeu.
 *
 * @return list<string>
 */
function shellGameControllerClasses(): array
{
    preg_match_all(
        '/^use\s+(App\\\\Http\\\\Controllers\\\\[A-Za-z0-9_\\\\]+);/m',
        (string) file_get_contents(base_path('routes/game.php')),
        $matches,
    );

    $classes = [];

    foreach ($matches[1] as $class) {
        expect(class_exists($class))->toBeTrue("{$class}, importé par routes/game.php, n'existe pas");

        $classes[] = $class;

        foreach (class_uses_recursive($class) as $trait) {
            $classes[] = $trait;
        }
    }

    return array_values(array_unique($classes));
}

/**
 * Chaînes littérales d'un fichier PHP, commentaires exclus.
 *
 * @return list<string>
 */
function shellPhpStringLiterals(string $path): array
{
    $strings = [];

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $strings[] = substr($token[1], 1, -1);
        } elseif (is_array($token) && $token[0] === T_ENCAPSED_AND_WHITESPACE) {
            $strings[] = $token[1];
        }
    }

    return $strings;
}

/**
 * Périmètre `WATCHED` du script anti-couleur, tel qu'écrit dans le script
 * (option `--perimeter`, spec 90 § 9.3), relatif à `resources/js`.
 *
 * @return list<string>
 */
function shellWatchedEntries(): array
{
    $result = Process::path(base_path())->run(['node', 'scripts/check-theme-tokens.mjs', '--perimeter']);

    expect($result->successful())->toBeTrue('node scripts/check-theme-tokens.mjs --perimeter a échoué : '.trim($result->errorOutput()));

    /** @var array{watched: list<string>} $perimeter */
    $perimeter = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_map(
        static fn (string $entry): string => substr($entry, strlen('resources/js/')),
        array_filter($perimeter['watched'], static fn (string $entry): bool => str_starts_with($entry, 'resources/js/')),
    ));
}

it("laisse le back-office suivre l'apparence du visiteur", function () {
    // Les pages React ne sont pas en cause ici : seule compte la balise
    // `<html>` que Blade rend autour d'elles.
    $this->withoutVite();

    $curator = User::factory()->curator()->create();

    // Le retrait est entier : ni classe, ni alias, ni middleware sur le groupe.
    expect(class_exists('App\\Http\\Middleware\\ForceAdminAppearance'))->toBeFalse()
        ->and(array_keys(app('router')->getMiddleware()))->not->toContain('admin.appearance')
        ->and(Route::getRoutes()->getByName('admin.dashboard')?->gatherMiddleware())
        ->not->toContain('admin.appearance');

    // `appearance` est exclu du chiffrement des cookies (`bootstrap/app.php`) :
    // le front l'écrit en clair, le test aussi.
    $dark = $this->actingAs($curator)
        ->withUnencryptedCookie('appearance', 'dark')
        ->get(route('admin.dashboard'))
        ->assertOk();

    $darkTag = shellHtmlTag($dark);

    expect($darkTag)->not->toContain('data-appearance-forced')
        ->and(shellHtmlClasses($darkTag))->toContain('dark');

    $light = $this->actingAs($curator)
        ->withUnencryptedCookie('appearance', 'light')
        ->get(route('admin.dashboard'))
        ->assertOk();

    $lightTag = shellHtmlTag($light);

    expect($lightTag)->not->toContain('data-appearance-forced')
        ->and(shellHtmlClasses($lightTag))->not->toContain('dark');

    // Le script en ligne ne force rien non plus : il ne lit que la préférence
    // (`light` ici), et n'ajoute `dark` qu'en préférence « système ».
    expect((string) $light->getContent())->toContain("const appearance = 'light';");
});

it("monte le bandeau de maintenance sous l'en-tête public, au rôle note, sur sa seule clé sans paramètre", function () {
    // Ajouté par L90-3b, hors des intitulés de la spec : le rendu se vérifie à
    // la main (aucun DOM au jalon 1, C18 § 2.4), la prop par
    // `MaintenanceBannerTest` (100). Ce test garde dans la source les
    // invariants du § 3.3 (rien hors drainage, rôle note porté par `Alert`,
    // seule clé sans paramètre, place dans la coquille), que la vérification
    // manuelle ne rejoue pas à chaque commit.
    $banner = FrontSource::withoutComments((string) file_get_contents(
        resource_path('js/components/public/maintenance-banner.tsx'),
    ));

    // Il ne lit que le booléen partagé, et ne rend que sa clé.
    expect(substr_count($banner, 'usePage()'))->toBe(1)
        ->and($banner)->toMatch('/const \{\s*maintenance\s*\} = usePage\(\)\.props;/')
        ->and(FrontSource::literalKeys($banner))->toBe(['common.maintenance.banner']);

    // Hors drainage, il ne rend rien : le retour `null` précède tout rendu. Un
    // bandeau permanent dirait à tout visiteur qu'aucune partie ne se lance.
    expect($banner)->toMatch('/if \(!maintenance\) \{\s*return null;\s*\}/');
    expect(strpos($banner, 'return null;'))->toBeInt()
        ->toBeLessThan(strpos($banner, '<Alert'));

    // Il ne parle pas : l'unique `Alert` porte lui-même le rôle `note` (seul un
    // rôle passé à `Alert` supplante le `role="alert"` de `ui/alert.tsx`), et
    // aucun autre rôle ni aucune région vivante n'est posé. `\b` écarte
    // `<AlertDescription`.
    expect($banner)->toMatch('/<Alert\b[^>]*\brole="note"/');
    expect(preg_match_all('/<Alert\b/', $banner))->toBe(1)
        ->and(substr_count($banner, 'role='))->toBe(1)
        ->and($banner)->not->toContain('aria-live');

    // Ni heure, ni phase, ni nombre de parties : le texte n'a ni paramètre ni
    // chiffre, dans aucune langue activée (C18-bis § 3).
    foreach (Locale::cases() as $locale) {
        $text = __('common.maintenance.banner', [], $locale->value);

        expect($text)->toBeString()
            ->not->toBe('common.maintenance.banner')
            ->not->toMatch('/:[a-z_]+/')
            ->not->toMatch('/\d/');
    }

    // Monté une fois par la coquille publique, entre l'en-tête et le contenu
    // (§ 2.4).
    $layout = FrontSource::withoutComments((string) file_get_contents(
        resource_path('js/layouts/public/public-layout.tsx'),
    ));

    $header = strpos($layout, '<PublicHeader />');
    $mounted = strpos($layout, '<MaintenanceBanner />');
    $main = strpos($layout, '<main');

    expect(substr_count($layout, '<MaintenanceBanner />'))->toBe(1)
        ->and($header)->toBeInt()
        ->and($mounted)->toBeInt()
        ->and($main)->toBeInt()
        ->and($header < $mounted && $mounted < $main)->toBeTrue();
});

it('garde le tableau de bord du starter dans AppLayout', function () {
    // Côté serveur : la cible de `fortify.home` rend toujours la page du
    // starter `dashboard`, conservée au jalon 1 (spec 90 § 2.1) ; son retrait
    // relève de 40 au jalon 2.
    expect(config('fortify.home'))->toBe(parse_url(route('dashboard'), PHP_URL_PATH));

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboard'));

    // Côté client : le `switch` d'`app.tsx`, dans l'ordre de C16 § 2.1.
    // `welcome` a rejoint `PublicLayout` à sa réécriture en accueil (L90-8) :
    // la forme transitoire, sans coquille, n'est plus admise.
    $groups = shellLayoutSwitch();

    expect($groups)->toBe([
        ['conditions' => ["name === 'welcome'", "name === 'error'", "name.startsWith('legal/')"], 'layout' => 'PublicLayout'],
        ['conditions' => ["name.startsWith('game/')"], 'layout' => 'GameLayout'],
        ['conditions' => ["name.startsWith('admin/')"], 'layout' => 'AdminLayout'],
        ['conditions' => ["name.startsWith('auth/')"], 'layout' => 'AuthLayout'],
        ['conditions' => ["name.startsWith('settings/')"], 'layout' => '[AppLayout, SettingsLayout]'],
        ['conditions' => ["name === 'dashboard'"], 'layout' => 'AppLayout'],
        ['conditions' => ['default'], 'layout' => 'PublicLayout'],
    ]);
});

it("ne marque jamais une page publique comme d'apparence forcée", function () {
    // Seule compte ici la balise `<html>` que Blade rend autour des pages ;
    // la page d'erreur est celle de la production, hors mode debug.
    $this->withoutVite();
    config(['app.debug' => false]);

    // 1. Les pages joueurs hors `game/*` qui existent : accueil, pages
    //    légales, « signaler un contenu », écrans de compte, tableau de bord,
    //    réglages, page d'erreur d'une URL inconnue. Chacune suit le cookie
    //    `appearance`, jamais un forçage.
    $user = User::factory()->create();

    $pages = [
        'accueil' => fn () => $this->get(route('home')),
        'mentions légales' => fn () => $this->get(route('legal.notice')),
        'CGU' => fn () => $this->get(route('legal.terms')),
        'confidentialité' => fn () => $this->get(route('legal.privacy')),
        'signaler un contenu' => fn () => $this->get(route('takedown.create')),
        'connexion' => fn () => $this->get(route('login')),
        'URL inconnue' => fn () => $this->get('/__shell/introuvable'),
        'tableau de bord' => fn () => $this->actingAs($user)->get(route('dashboard')),
        'profil' => fn () => $this->actingAs($user)->get(route('profile.edit')),
    ];

    foreach (['light', 'dark'] as $appearance) {
        foreach ($pages as $label => $visit) {
            // Requête neuve : ni domaines ni compte hérités de la visite
            // précédente (un invité connecté serait renvoyé de `login`).
            app()->forgetInstance(TranslationDomains::class);
            app('auth')->forgetGuards();

            $this->withUnencryptedCookie('appearance', $appearance);

            $response = $visit();

            expect($response->status())->toBeIn([200, 404], "{$label} : statut {$response->status()}");

            $tag = shellHtmlTag($response);

            expect($tag)->not->toContain('data-appearance-forced', "{$label} ({$appearance}) : forcée")
                ->and(in_array('dark', shellHtmlClasses($tag), true))->toBe($appearance === 'dark', "{$label} ({$appearance}) : classe dark");
        }
    }

    // 2. La moitié serveur existe, sous son alias, et force bien une page de
    //    jeu malgré un cookie clair : sans quoi son absence ailleurs ne
    //    prouverait rien. Route d'essai déclarée ici, comme le fera le groupe
    //    `game/*` de `routes/game.php`. Jouée APRÈS les pages publiques : le
    //    partage de vue survit d'une requête à l'autre dans l'application du
    //    test, jamais d'une requête à l'autre en production.
    expect(app('router')->getMiddleware())->toHaveKey('game.appearance')
        ->and(app('router')->getMiddleware()['game.appearance'])->toBe(ForceGameAppearance::class);

    Route::middleware(['web', 'game.appearance', 'translations:game,room,legal'])
        ->get('/__shell/game', fn () => Inertia::render('game/lobby'));

    app()->forgetInstance(TranslationDomains::class);

    $forced = shellHtmlTag($this->withUnencryptedCookie('appearance', 'light')->get('/__shell/game')->assertOk());

    expect($forced)->toContain('data-appearance-forced="dark"')
        ->and(shellHtmlClasses($forced))->toContain('dark');

    // 3. Règle de groupe (spec 90 § 2.2) : une route qui porte
    //    `game.appearance` ne rend QUE des pages `game/*` — le partage a lieu
    //    avant le contrôleur, qui ne peut plus rien décider après coup. Garde
    //    auto-activée : `room.show` le porte depuis L50-3b. Les pages d'entrée
    //    `room/*` ne le portent jamais.
    $violations = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        if (! in_array('game.appearance', $route->gatherMiddleware(), true) || str_starts_with($route->uri(), '__shell/')) {
            continue;
        }

        $label = $route->getName() ?? $route->uri();

        if (! in_array('web', $route->gatherMiddleware(), true)) {
            $violations[] = "{$label} : hors du groupe web";
        }

        $component = $route->defaults['component'] ?? null;

        if (is_string($component)) {
            if (! str_starts_with($component, 'game/')) {
                $violations[] = "{$label} : rend {$component}";
            }

            continue;
        }

        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            $violations[] = "{$label} : action {$action} illisible, le rendu n'est pas vérifiable";

            continue;
        }

        [$class, $method] = explode('@', $action, 2);
        $reflection = new ReflectionMethod($class, $method);
        $lines = array_slice(
            file((string) $reflection->getFileName()) ?: [],
            (int) $reflection->getStartLine() - 1,
            (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1,
        );
        $body = implode('', $lines);

        preg_match_all('/(?:Inertia::render|\binertia)\(\s*(.)/', $body, $calls, PREG_SET_ORDER);
        preg_match_all('/(?:Inertia::render|\binertia)\(\s*([\'"])([^\'"]+)\1/', $body, $names);

        if (count($calls) !== count($names[2])) {
            $violations[] = "{$label} : nom de page non littéral";
        }

        foreach ($names[2] as $name) {
            if (! str_starts_with($name, 'game/')) {
                $violations[] = "{$label} : rend {$name}";
            }
        }
    }

    foreach (['room.create', 'room.entry', 'solo.create'] as $name) {
        $route = Route::getRoutes()->getByName($name);

        if ($route !== null && in_array('game.appearance', $route->gatherMiddleware(), true)) {
            $violations[] = "{$name} : page d'entrée room/* forcée";
        }
    }

    expect($violations)->toBe([]);

    // 4. Moitié cliente : `useForcedAppearance` n'est appelé que par
    //    `GameLayout`, en sombre ; aucune autre coquille ne force rien.
    $callers = [];

    foreach (shellFrontFiles(['']) as $file) {
        if ($file === 'hooks/use-forced-appearance.ts' || str_starts_with($file, 'components/ui/')) {
            continue;
        }

        preg_match_all('/\buseForcedAppearance\(([^)]*)\)/', shellSource($file), $calls);

        foreach ($calls[1] as $argument) {
            $callers[] = "{$file} : {$argument}";
        }
    }

    expect($callers)->toBe(["layouts/game/game-layout.tsx : 'dark'"]);
});

it("rend le lobby en sombre quelle que soit l'apparence du visiteur", function () {
    // Écrit par L50-4 (dépendance inversée, spec 90 § L90-7) : `game/lobby`
    // est la première page `game/*` servie par une vraie route, `room.show`.
    // Le forçage a trois moitiés (spec 90 § 2.2) : le serveur force le
    // document quel que soit le cookie `appearance`, l'attribut
    // `data-appearance-forced` empêche `initializeTheme()` de reposer la
    // préférence stockée, et `GameLayout`, coquille de toute page `game/*`,
    // tient la moitié cliente pour une navigation Inertia.
    $this->withoutVite();

    $token = PlayerToken::mint(Locale::French);
    [$room] = LobbyWrites::hostedRoom($token);
    LobbyWrites::actAs($this, $token);

    expect(Route::getRoutes()->getByName('room.show')?->gatherMiddleware())->toContain('game.appearance');

    // Sans cookie d'abord, puis chaque préférence : le partage de vue survit
    // d'une requête à l'autre dans l'application du test, il est donc remis à
    // zéro avant chacune — chaque réponse doit forcer d'elle-même.
    foreach ([null, 'light', 'system', 'dark'] as $appearance) {
        app()->forgetInstance(TranslationDomains::class);
        Cache::flush();
        View::share('appearanceForced', false);
        View::share('appearance', null);

        if ($appearance !== null) {
            $this->withUnencryptedCookie('appearance', $appearance);
        }

        $label = $appearance ?? 'sans cookie';
        $response = $this->get(route('room.show', $room))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->component('game/lobby')->etc());

        $tag = shellHtmlTag($response);

        expect(str_contains($tag, 'data-appearance-forced="dark"'))->toBeTrue("{$label} : non forcée")
            ->and(in_array('dark', shellHtmlClasses($tag), true))->toBeTrue("{$label} : classe dark absente");
    }

    // Moitié cliente : la page prend `GameLayout`, qui force le sombre.
    $game = array_values(array_filter(
        shellLayoutSwitch(),
        static fn (array $group): bool => in_array("name.startsWith('game/')", $group['conditions'], true),
    ));

    expect($game)->toHaveCount(1)
        ->and($game[0]['layout'])->toBe('GameLayout')
        ->and(is_file(resource_path('js/pages/game/lobby.tsx')))->toBeTrue()
        ->and(substr_count(shellSource('layouts/game/game-layout.tsx'), "useForcedAppearance('dark')"))->toBe(1);
});

it('garde le rendu côté serveur désactivé', function () {
    // Aucun SSR en v1 (n° 70, E10-13) : la production n'a aucun processus
    // Node, et un SSR actif en développement seulement ferait diverger le
    // rendu initial, donc le forçage d'apparence et l'indexation.
    expect(config('inertia.ssr.enabled'))->toBeFalse();

    // Écrit en dur, jamais lu d'une variable d'environnement qu'un
    // déploiement pourrait inverser.
    expect((string) file_get_contents(config_path('inertia.php')))
        ->toMatch("/'ssr'\s*=>\s*\[\s*'enabled'\s*=>\s*false\s*,/");

    // Aucun point d'entrée SSR, ni dans le dépôt ni dans la configuration de
    // Vite.
    foreach (['ssr.ts', 'ssr.tsx', 'ssr.js', 'ssr.jsx'] as $entry) {
        expect(file_exists(resource_path("js/{$entry}")))->toBeFalse("resources/js/{$entry} existe");
    }

    expect(FrontSource::withoutComments((string) file_get_contents(base_path('vite.config.ts'))))
        ->not->toMatch('/\bssr\s*:/');

    // Le document sert un point de montage vide : le client rend la page.
    $html = (string) $this->withoutVite()->get(route('legal.notice'))->assertOk()->getContent();

    expect($html)->toContain('<div id="app"></div>')
        ->toContain('<script data-page="app" type="application/json">');

    // Aucun docblock ne dit le contraire (E10-13).
    foreach ([app_path('Models/User.php'), resource_path('js/hooks/use-forced-appearance.ts')] as $path) {
        $source = (string) file_get_contents($path);

        expect($source)->toContain('Aucun SSR en v1')
            ->not->toMatch('/SSR (?:est )?(?:activé|actif)|SSR is (?:on|enabled)/i');
    }
});

it("n'émet aucun toast depuis une page, un composant ou un hook de jeu", function () {
    // Un message de jeu passe par du texte à l'écran et par `announce()`,
    // jamais par un toast (spec 90 § 2.3) : sur une page de jeu, aucun
    // `Toaster` ne l'afficherait, et sa section serait une seconde région
    // vivante. Côté client, sans import de sonner ni du hook de flash, aucun
    // toast ne part ; `router.flash('toast', …)` en poserait un.
    $files = shellFrontFiles(['pages/game', 'components/game', 'hooks/game', 'lib/game', 'layouts/game']);

    expect($files)->toContain('components/game/game-help.tsx', 'hooks/game/use-flash-notice.ts', 'layouts/game/game-layout.tsx');

    $violations = [];

    foreach ($files as $file) {
        $source = shellSource($file);

        foreach ([
            'import de sonner' => '/from\s+[\'"](?:sonner|@\/components\/ui\/sonner)[\'"]/',
            'hook de toast' => '/\buseFlashToast\b|use-flash-toast/',
            'appel toast()' => '/\btoast\s*(?:\.\s*[a-z]+\s*)?\(/',
            'flash toast client' => '/\.flash\(\s*(?:[\'"]toast[\'"]|\{[^}]*\btoast\s*:)/',
        ] as $rule => $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $violations[] = "{$file} : {$rule}";
            }
        }
    }

    // Côté serveur : aucun contrôleur de `routes/game.php`, traits compris,
    // ne pose de flash `toast`. Le seul flash qu'une page de jeu subit est
    // celui de la page expirée, posé par le gestionnaire d'exceptions (§ 4.8)
    // et rendu en texte par `GameLayout`.
    $classes = shellGameControllerClasses();

    expect(count($classes))->toBeGreaterThanOrEqual(5);

    foreach ($classes as $class) {
        $path = (string) (new ReflectionClass($class))->getFileName();

        foreach (shellPhpStringLiterals($path) as $literal) {
            if (preg_match('/\btoast\b/i', $literal) === 1) {
                $violations[] = class_basename($class)." : littéral « {$literal} »";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('ne monte ni Toaster ni seconde région aria-live sur une page de jeu', function () {
    // Une page de jeu n'a qu'UNE région vivante, `GameAnnouncer` (C16 § 4).
    // Balayage de la spec (coquille, pages, composants de jeu, de salon et
    // d'état, hooks de jeu), étendu aux composants hors de ces répertoires
    // que la coquille monte elle-même (sélecteur de langue, bandeau, pied).
    $files = shellFrontFiles(['layouts/game', 'pages/game', 'components/game', 'components/room', 'components/state', 'hooks/game']);

    $pending = ['layouts/game/game-layout.tsx'];

    while ($pending !== []) {
        $file = array_shift($pending);

        preg_match_all('/from\s+[\'"]@\/(components\/(?!ui\/)[a-z0-9\/-]+)[\'"]/', shellSource($file), $imports);

        foreach ($imports[1] as $import) {
            $mounted = $import.'.tsx';

            if (is_file(resource_path('js/'.$mounted)) && ! in_array($mounted, $files, true)) {
                $files[] = $mounted;
                $pending[] = $mounted;
            }
        }
    }

    expect($files)->toContain(
        'components/game/game-announcer.tsx',
        'components/language-switcher.tsx',
        'components/public/maintenance-banner.tsx',
        'components/public/site-footer.tsx',
        'components/public/tmdb-attribution.tsx',
    );

    $violations = [];
    $liveRegions = [];

    foreach ($files as $file) {
        $source = shellSource($file);

        if (preg_match('/\bToaster\b|from\s+[\'"](?:sonner|@\/components\/ui\/sonner)[\'"]/', $source) === 1) {
            $violations[] = "{$file} : Toaster";
        }

        // Régions vivantes explicites ou implicites (rôles status, alert,
        // log, marquee, timer).
        $count = preg_match_all('/aria-live\s*=|\brole\s*=\s*\{?\s*[\'"`](?:status|alert|log|marquee|timer)[\'"`]/', $source);

        if ($count > 0) {
            $liveRegions[$file] = $count;
        }

        // Tout `Alert` au rôle `note`, qui supplante le `role="alert"` du
        // composant généré.
        foreach (shellOpeningTags($source, 'Alert') as $tag) {
            if (preg_match('/\brole="note"/', $tag) !== 1) {
                $violations[] = "{$file} : {$tag}";
            }
        }

        // Tout `Spinner` neutralisé : son `role="status"` généré serait une
        // région de plus, et son nom « Loading » est en dur, en anglais.
        foreach (shellOpeningTags($source, 'Spinner') as $tag) {
            if (preg_match('/\baria-hidden="true"/', $tag) !== 1 || preg_match('/\brole="presentation"/', $tag) !== 1) {
                $violations[] = "{$file} : {$tag}";
            }
        }
    }

    expect($violations)->toBe([])
        // La région de l'annonceur (rôle `status` et `aria-live`), et elle seule.
        ->and($liveRegions)->toBe(['components/game/game-announcer.tsx' => 2]);

    // La coquille monte l'annonceur une fois, et aucun `Toaster`.
    $layout = shellSource('layouts/game/game-layout.tsx');

    expect(substr_count($layout, '<GameAnnouncer />'))->toBe(1)
        ->and($layout)->not->toContain('Toaster');
});

it('ne rend aucune SheetContent ni DialogContent sans masquer la fermeture générée en anglais', function () {
    // `SheetContent` et `DialogContent` rendent après leurs enfants un bouton
    // nommé « Close », en dur et en anglais (`components/ui/*`, jamais
    // édité) : tout fichier surveillé le masque, restreint au dernier enfant,
    // et compose sa propre fermeture, son titre et sa description traduits
    // (spec 90 § 2.5).
    $files = array_values(array_filter(
        shellFrontFiles(shellWatchedEntries()),
        static fn (string $file): bool => str_ends_with($file, '.tsx'),
    ));

    $seen = [];
    $violations = [];

    foreach ($files as $file) {
        $source = shellSource($file);

        foreach (['Sheet', 'Dialog'] as $kind) {
            $tags = shellOpeningTags($source, "{$kind}Content");

            if ($tags === []) {
                continue;
            }

            $seen[] = $file;

            foreach ($tags as $tag) {
                if (! str_contains($tag, '[&>button:last-child]:hidden')) {
                    $violations[] = "{$file} : fermeture générée exposée — ".preg_replace('/\s+/', ' ', $tag);
                }
            }

            foreach (["<{$kind}Close", "<{$kind}Title", "<{$kind}Description"] as $part) {
                if (! str_contains($source, $part)) {
                    $violations[] = "{$file} : aucun {$part}>";
                }
            }

            // Côté joueur, la fermeture propre porte `common.action.close` ;
            // le back-office a ses clés `admin.*`, en français seul.
            $admin = preg_match('#^(?:pages|components|layouts|hooks|lib)/admin/#', $file) === 1;

            if (! $admin && ! in_array('common.action.close', FrontSource::literalKeys($source), true)) {
                $violations[] = "{$file} : fermeture sans common.action.close";
            }
        }
    }

    expect($violations)->toBe([])
        ->and($seen)->toContain(
            'components/public/site-footer.tsx',
            'components/game/game-help.tsx',
            'components/admin/admin-sidebar.tsx',
        );
});

it('compose la coquille de jeu, de haut en bas, du bandeau, de la page, de la ligne basse et de l’annonceur', function () {
    // Ajouté par L90-7, au-delà des intitulés de la spec : la structure de
    // `GameLayout` (spec 90 § 2.3, C16 § 2.3) se vérifie à la main (aucun DOM
    // au jalon 1) ; ce test en garde les invariants dans la source.
    $layout = shellSource('layouts/game/game-layout.tsx');

    // Forcée en sombre, hauteur visible, geste de rafraîchissement bloqué,
    // avis de page expirée : les quatre hooks de la coquille.
    foreach (["useForcedAppearance('dark')", 'useVisualViewport()', 'useOverscrollLock()', 'useFlashNotice()'] as $hook) {
        expect(substr_count($layout, $hook))->toBe(1, "{$hook} absent ou répété");
    }

    // Racine plein écran : hauteur visible, repli `100dvh`, jamais `100vh`,
    // aucun défilement de page.
    expect($layout)->toContain('h-[var(--game-viewport-height,100dvh)]')
        ->toContain('overflow-hidden')
        ->not->toContain('100vh');

    // Ni barre latérale, ni fil d'Ariane, ni en-tête.
    expect($layout)->not->toMatch('/Sidebar|Breadcrumb|<header\b|PublicHeader/');

    // De haut en bas, exactement. `\b` écarte `<AlertDescription`.
    $order = [
        '/<MaintenanceBanner \/>/',
        '/<Alert\b/',
        '/<main id="game-main" className="min-h-0 flex-1">/',
        '/<LanguageSwitcher\b/',
        '/<SiteFooter variant="collapsed" \/>/',
        '/<GameAnnouncer \/>/',
    ];
    $positions = [];

    foreach ($order as $pattern) {
        expect(preg_match_all($pattern, $layout, $found, PREG_OFFSET_CAPTURE))->toBe(1, "{$pattern} absent ou répété");

        $positions[] = $found[0][0][1];
    }

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted);

    // L'avis de page expirée : un `Alert` au rôle note, rendu seulement quand
    // un message est là.
    expect($layout)->toMatch('/\{notice !== null && \(\s*<div[^>]*>\s*<Alert\b[^>]*\brole="note"/');

    // La ligne basse contient exactement le sélecteur de langue en icône
    // seule, à 44 px, et le déclencheur du pied replié.
    expect(preg_match('/<LanguageSwitcher\b(.*?)\/>\s*<SiteFooter variant="collapsed" \/>\s*<\/div>/s', $layout, $line))->toBe(1)
        ->and($line[1])->toMatch('/\biconOnly\b/')
        ->and($line[1])->toContain('min-h-11 min-w-11');

    // `PublicLayout` monte aussi l'annonceur, une fois, après le pied et
    // avant le `Toaster`, pour la seule annonce du changement de langue
    // (§ 2.4).
    $public = shellSource('layouts/public/public-layout.tsx');

    expect(substr_count($public, '<GameAnnouncer />'))->toBe(1)
        ->and(strpos($public, '<SiteFooter variant="full" />') < strpos($public, '<GameAnnouncer />'))->toBeTrue()
        ->and(strpos($public, '<GameAnnouncer />') < strpos($public, '<Toaster />'))->toBeTrue();
});
