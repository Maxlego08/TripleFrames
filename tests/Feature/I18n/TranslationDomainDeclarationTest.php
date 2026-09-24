<?php

use App\Models\User;
use App\Support\I18n\TranslationDomains;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\I18n\FrontSource;

/*
|--------------------------------------------------------------------------
| Déclaration des domaines de traduction — spec 90 § 6.3, contrat C15
|--------------------------------------------------------------------------
|
| Une page ne reçoit que les domaines que sa route déclare (`common` joint
| d'office, `translations:…`) : une clé d'un domaine non déclaré s'affiche
| brute, sans qu'aucune erreur ne le signale (règle 4). Trois propriétés :
|
| 1. toute route joueur déclare `legal`, parce que le pied de page est présent
|    sur tous les écrans (n° 68, D3 du 23/09) ;
| 2. le back-office ne reçoit que `admin`, jamais un domaine joueur ;
| 3. chaque page, et chaque répertoire de composants qui lui est rattaché,
|    n'appelle que des clés des domaines que sa route déclare.
|
| Complété par L90-5 (pages d'erreur joueur et back-office).
|
*/

/**
 * Domaines déclarés par une pile de middlewares de route, `common` compris.
 *
 * @param  list<string>  $middleware
 * @return list<string>
 */
function domainDeclarationDeclared(array $middleware): array
{
    $domains = [TranslationDomains::BASE];

    foreach ($middleware as $entry) {
        if (str_starts_with($entry, 'translations:')) {
            array_push($domains, ...explode(',', substr($entry, strlen('translations:'))));
        }
    }

    $domains = array_values(array_unique($domains));
    sort($domains);

    return $domains;
}

/**
 * Routes que le balayage lit : GET ou HEAD, du groupe `web`.
 *
 * @return list<RoutingRoute>
 */
function domainDeclarationWebReadRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (RoutingRoute $route): bool => array_intersect(['GET', 'HEAD'], $route->methods()) !== []
            && in_array('web', $route->gatherMiddleware(), true),
    ));
}

/**
 * Domaines permis par préfixe de page, table de la spec 90 § 6.3 (`common`
 * joint d'office), et répertoires de composants rattachés à ces pages.
 *
 * Au-delà de la lettre de la spec, qui nomme `components/{game,room,public,
 * state}` : les coquilles propres (`layouts/public`, `layouts/game`,
 * `layouts/admin`) et `components/admin`. `PublicLayout` sert l'accueil, les
 * pages légales, la page d'erreur et les pages `room/*` : il n'a droit qu'à
 * l'intersection de leurs domaines, `common` et `legal`, comme
 * `components/public`.
 *
 * @return array<string, list<string>> chemin sous `resources/js/` → domaines
 */
function domainDeclarationTable(): array
{
    $player = ['common', 'legal'];
    $account = ['account', 'common', 'legal'];
    $game = ['common', 'game', 'legal', 'room'];
    $admin = [TranslationDomains::ADMIN];

    return [
        // Pages, par préfixe de nom (§ 6.3).
        'pages/welcome.tsx' => $player,
        'pages/legal/' => $player,
        'pages/error.tsx' => $player,
        'pages/auth/' => $account,
        'pages/settings/' => $account,
        'pages/dashboard.tsx' => $account,
        'pages/game/' => $game,
        'pages/room/' => ['common', 'legal', 'room'],
        'pages/admin/' => $admin,

        // Répertoires rattachés (§ 6.3, L90-3).
        'components/game/' => $game,
        'components/room/' => $game,
        'components/public/' => $player,
        'components/state/' => ['common'],

        // Coquilles propres et composants du back-office.
        'layouts/public/' => $player,
        'layouts/game/' => $game,
        'layouts/admin/' => $admin,
        'components/admin/' => $admin,
    ];
}

it('déclare le domaine legal sur toute route joueur', function () {
    // Exclusions fermées et nommées (spec 90, L90-3) : routes qui ne rendent
    // jamais une page Inertia joueur — passkeys de gestionnaire de mots de
    // passe, fichiers du disque local, octets d'image, horloge, et
    // l'autorisation de canal de diffusion (sans nom).
    $excludedNames = ['well-known.passkeys', 'storage.local', 'frame.serve', 'clock.show'];
    $excludedUris = ['broadcasting/auth'];

    $checked = [];
    $violations = [];

    foreach (domainDeclarationWebReadRoutes() as $route) {
        $name = $route->getName();

        if ($name !== null && (str_starts_with($name, 'admin.') || in_array($name, $excludedNames, true))) {
            continue;
        }

        if (in_array(trim($route->uri(), '/'), $excludedUris, true)) {
            continue;
        }

        $label = $name ?? '/'.trim($route->uri(), '/');
        $checked[] = $label;

        if (! in_array('legal', domainDeclarationDeclared($route->gatherMiddleware()), true)) {
            $violations[] = $label;
        }
    }

    expect($violations)->toBe([], 'Route joueur sans le domaine legal : son pied de page afficherait des clés brutes.');

    // Le balayage voit bien les routes joueur de chaque lieu de déclaration :
    // un filtre trop large serait vert pour de mauvaises raisons.
    expect($checked)->toContain(
        'home',
        'login',
        'register',
        'password.request',
        'verification.notice',
        'password.confirm',
        'dashboard',
        'profile.edit',
        'appearance.edit',
        'security.edit',
    );
});

it("n'envoie que le domaine admin au back-office", function () {
    // Déclaration : toute route de lecture du back-office passe par
    // `ForceAdminLocale` (qui déclare `admin`) et ne déclare aucun domaine
    // joueur, `legal` compris — son pied porte `admin.footer.*` (spec 20).
    $adminRoutes = array_values(array_filter(
        domainDeclarationWebReadRoutes(),
        static fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'admin.'),
    ));

    expect($adminRoutes)->not->toBeEmpty();

    $violations = [];

    foreach ($adminRoutes as $route) {
        $middleware = $route->gatherMiddleware();
        $declared = array_diff(domainDeclarationDeclared($middleware), [TranslationDomains::BASE]);

        if (! in_array('admin.locale', $middleware, true)) {
            $violations[] = "{$route->getName()} : sans admin.locale";
        }

        if (array_diff($declared, [TranslationDomains::ADMIN]) !== []) {
            $violations[] = "{$route->getName()} : déclare ".implode(', ', $declared);
        }
    }

    expect($violations)->toBe([]);

    // Charge utile réelle : un curateur ne reçoit que des clés `admin.*`, en
    // français, et jamais `common` ni `legal`.
    $curator = User::factory()->curator()->create();

    foreach (['admin.dashboard', 'admin.catalog.index'] as $name) {
        // Singleton : une requête neuve repart d'une sélection vide.
        app()->forgetInstance(TranslationDomains::class);

        $response = $this->actingAs($curator)->get(route($name))->assertOk();
        $translations = $response->inertiaProps('translations');

        expect($translations)->toBeArray()->not->toBeEmpty()
            ->and(array_filter(
                array_keys($translations),
                static fn (string $key): bool => ! str_starts_with($key, TranslationDomains::ADMIN.'.'),
            ))->toBe([], "{$name} reçoit une clé hors du domaine admin")
            ->and($response->inertiaProps('locale'))->toBe('fr');
    }
});

it("n'appelle que des clés des domaines déclarés pour son préfixe de page", function () {
    $root = resource_path('js');
    $table = domainDeclarationTable();
    $violations = [];
    $scanned = 0;

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $allowed = null;

        foreach ($table as $prefix => $domains) {
            if ($relative === $prefix || (str_ends_with($prefix, '/') && str_starts_with($relative, $prefix))) {
                $allowed = $domains;

                break;
            }
        }

        if ($allowed === null) {
            // Toute page a sa ligne dans la table : une page sans déclaration
            // ne saurait pas quels domaines sa route lui envoie.
            if (str_starts_with($relative, 'pages/')) {
                $violations[] = "{$relative} : page sans ligne dans la table des domaines (spec 90 § 6.3)";
            }

            continue;
        }

        $scanned++;
        $foreign = array_diff(FrontSource::referencedDomains((string) file_get_contents($file->getPathname())), $allowed);

        if ($foreign !== []) {
            $violations[] = "{$relative} : appelle ".implode(', ', $foreign).', hors de '.implode(', ', $allowed);
        }
    }

    expect($violations)->toBe([]);

    // Le balayage lit bien des pages de chaque famille présente.
    expect($scanned)->toBeGreaterThan(20);
});
