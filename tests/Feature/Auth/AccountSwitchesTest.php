<?php

use App\Http\Middleware\EnforceAccountSwitches;
use App\Models\User;
use App\Support\Identity\AccountSwitches;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Interrupteurs de compte — spec 40 § 8.2, lot L40-7
|--------------------------------------------------------------------------
|
| La production existe dès le jalon 1 avec un seul compte, le premier
| administrateur. L'inscription publique et les passkeys y restent fermées
| par deux interrupteurs, `ACCOUNTS_REGISTRATION_OPEN` et
| `ACCOUNTS_PASSKEYS_ENABLED` : seuls `true` et `false` déclarés sont lus,
| toute autre valeur applique une LISTE BLANCHE (ouvert en `local` et
| `testing`, fermé partout ailleurs). Les routes de Fortify restent
| enregistrées ; `accounts.switches` leur répond 404.
|
*/

/**
 * Les deux variables d'environnement, et elles seules.
 *
 * @return list<string>
 */
function accountSwitchesVariables(): array
{
    return ['ACCOUNTS_REGISTRATION_OPEN', 'ACCOUNTS_PASSKEYS_ENABLED'];
}

/**
 * Joue `$callback` sous `APP_ENV = $environment`, puis rétablit
 * l'environnement de la suite. `App::environment()` lit `$app['env']`.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function accountSwitchesUnder(string $environment, Closure $callback): mixed
{
    $previous = app()['env'];
    app()['env'] = $environment;

    try {
        return $callback();
    } finally {
        app()['env'] = $previous;
    }
}

/** Pose la même valeur, déclarée ou non, dans les deux clés de `config/accounts.php`. */
function accountSwitchesConfigure(mixed $value): void
{
    config([
        'accounts.registration_open' => $value,
        'accounts.passkeys_enabled' => $value,
    ]);
}

/**
 * Pose (ou retire, pour `null`) les deux variables dans les trois sources que
 * lit `env()`, joue `$callback`, puis rétablit l'état exact d'avant — pour
 * qu'une variable exportée dans le shell du développeur ne fausse ni ce test
 * ni les suivants.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function accountSwitchesWithEnv(?string $value, Closure $callback): mixed
{
    $saved = [];

    foreach (accountSwitchesVariables() as $name) {
        $saved[$name] = [
            'server' => array_key_exists($name, $_SERVER) ? [$_SERVER[$name]] : null,
            'env' => array_key_exists($name, $_ENV) ? [$_ENV[$name]] : null,
            'process' => getenv($name),
        ];

        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        } else {
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    try {
        return $callback();
    } finally {
        foreach ($saved as $name => $state) {
            unset($_SERVER[$name], $_ENV[$name]);

            if ($state['server'] !== null) {
                $_SERVER[$name] = $state['server'][0];
            }

            if ($state['env'] !== null) {
                $_ENV[$name] = $state['env'][0];
            }

            putenv($state['process'] === false ? $name : "{$name}={$state['process']}");
        }
    }
}

/**
 * Relit `config/accounts.php` avec les deux variables posées à `$value` (ou
 * absentes pour `null`).
 *
 * @return array<string, mixed>
 */
function accountSwitchesReadConfig(?string $value): array
{
    return accountSwitchesWithEnv($value, function (): array {
        /** @var array<string, mixed> $config */
        $config = require base_path('config/accounts.php');

        expect($config)->toHaveKeys(['registration_open', 'passkeys_enabled']);

        return $config;
    });
}

/**
 * Code PHP de l'application, commentaires retirés : un docblock qui CITE une
 * variable ou une clé n'est pas une lecture.
 *
 * @return array<string, string> chemin relatif (séparateur `/`) => code
 */
function accountSwitchesPhpSources(): array
{
    $sources = [];

    foreach (['app', 'bootstrap', 'config', 'database', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR));

            // Caches compilés : `config:cache` y recopie la VALEUR, pas une lecture.
            if (str_starts_with($relative, 'bootstrap/cache/')) {
                continue;
            }

            $code = '';

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            $sources[$relative] = $code;
        }
    }

    ksort($sources);

    return $sources;
}

/**
 * Routes que ferme l'interrupteur des passkeys : toutes celles de Fortify
 * nommées `passkey.*`, plus `well-known.passkeys`.
 *
 * @return list<RoutingRoute>
 */
function accountSwitchesPasskeyRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (RoutingRoute $route): bool => is_string($route->getName())
            && ($route->getName() === EnforceAccountSwitches::PASSKEY_DISCOVERY_ROUTE
                || str_starts_with($route->getName(), EnforceAccountSwitches::PASSKEY_ROUTE_PREFIX)),
    ));
}

it("ferme l'inscription et les passkeys hors local et testing quand aucune valeur n'est déclarée", function () {
    // `production` puis `staging`, exigés par la spec ; `prod` et une faute de
    // frappe montrent qu'il s'agit bien d'une liste blanche.
    foreach (['production', 'staging', 'prod', 'lcoal'] as $environment) {
        foreach ([null, ''] as $undeclared) {
            accountSwitchesConfigure($undeclared);

            accountSwitchesUnder($environment, function () use ($environment): void {
                expect(AccountSwitches::registrationOpen())
                    ->toBeFalse("inscription ouverte sous APP_ENV={$environment}")
                    ->and(AccountSwitches::passkeysEnabled())
                    ->toBeFalse("passkeys ouvertes sous APP_ENV={$environment}");
            });
        }
    }

    // De bout en bout, sous `production` puis `staging` : la valeur vide de
    // `.env.example` ferme l'écran d'inscription et la découverte des passkeys.
    accountSwitchesConfigure('');

    foreach (['production', 'staging'] as $environment) {
        accountSwitchesUnder($environment, function (): void {
            $this->get(route('register'))->assertNotFound();
            $this->get(route('well-known.passkeys'))->assertNotFound();
        });
    }
});

it("ouvre l'inscription et les passkeys en local et en testing quand aucune valeur n'est déclarée", function () {
    foreach (['local', 'testing'] as $environment) {
        foreach ([null, ''] as $undeclared) {
            accountSwitchesConfigure($undeclared);

            accountSwitchesUnder($environment, function () use ($environment): void {
                expect(AccountSwitches::registrationOpen())
                    ->toBeTrue("inscription fermée sous APP_ENV={$environment}")
                    ->and(AccountSwitches::passkeysEnabled())
                    ->toBeTrue("passkeys fermées sous APP_ENV={$environment}");
            });
        }
    }
});

it('ne lit que true ou false et traite toute autre valeur comme non déclarée', function () {
    $cases = [
        // Ce que `env()` convertit en booléen : une déclaration.
        'true' => true, 'TRUE' => true, '(true)' => true,
        'false' => false, 'FALSE' => false, '(false)' => false,
        // Tout le reste, vide compris : non déclarée.
        '' => null, '1' => null, '0' => null, 'yes' => null, 'no' => null,
        'on' => null, 'off' => null, 'null' => null, 'empty' => null,
        'oui' => null, 'open' => null,
    ];

    $expectations = [];

    foreach ($cases as $value => $declared) {
        $expectations[] = [(string) $value, $declared];
    }

    // Variable absente : non déclarée.
    $expectations[] = [null, null];

    foreach ($expectations as [$value, $declared]) {
        $label = $value === null ? '(absente)' : "« {$value} »";

        config(['accounts' => accountSwitchesReadConfig($value)]);

        foreach (['production', 'staging', 'local', 'testing'] as $environment) {
            // Déclarée : la valeur l'emporte sur l'environnement, dans les deux
            // sens. Non déclarée : la liste blanche décide.
            $expected = $declared ?? in_array($environment, AccountSwitches::OPEN_WHEN_UNDECLARED_IN, true);

            accountSwitchesUnder($environment, function () use ($expected, $label, $environment): void {
                expect(AccountSwitches::registrationOpen())
                    ->toBe($expected, "ACCOUNTS_REGISTRATION_OPEN={$label} sous APP_ENV={$environment}")
                    ->and(AccountSwitches::passkeysEnabled())
                    ->toBe($expected, "ACCOUNTS_PASSKEYS_ENABLED={$label} sous APP_ENV={$environment}");
            });
        }
    }

    // Une seule lecture de chaque variable, par la configuration : un `env()`
    // hors de `config/` rendrait `null` sous `config:cache`, en production.
    // Et une seule lecture de chaque clé, par `AccountSwitches` : ce n'est pas
    // un système de drapeaux de fonctionnalités (00 § Hors périmètre v1).
    $envReaders = [];
    $configReaders = [];

    foreach (accountSwitchesPhpSources() as $path => $code) {
        $envReaders = [...$envReaders, ...array_fill(0, (int) preg_match_all(
            '/\b(?:env|getenv|Env::get)\s*\(\s*[\'"]ACCOUNTS_|\$_(?:ENV|SERVER)\s*\[\s*[\'"]ACCOUNTS_/',
            $code,
        ), $path)];

        $configReaders = [...$configReaders, ...array_fill(0, (int) preg_match_all(
            '/[\'"]accounts(?:\.(?:registration_open|passkeys_enabled))?[\'"]/',
            $code,
        ), $path)];
    }

    expect($envReaders)->toBe(['config/accounts.php', 'config/accounts.php'])
        ->and($configReaders)->toBe([
            'app/Support/Identity/AccountSwitches.php',
            'app/Support/Identity/AccountSwitches.php',
        ]);
});

it("répond 404 à l'écran et à l'envoi d'inscription quand l'inscription est fermée, sans créer de compte", function () {
    $email = 'fermee@example.com';

    accountSwitchesConfigure(false);

    $this->get(route('register'))->assertNotFound();

    $this->post(route('register.store'), [
        'name' => 'Visiteur',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::query()->where('email', $email)->exists())->toBeFalse()
        ->and(User::query()->count())->toBe(0);

    // Contre-épreuve : ouverte, la même route rend l'écran. Le 404 venait
    // bien de l'interrupteur, pas d'une route absente.
    accountSwitchesConfigure(true);

    $this->get(route('register'))->assertOk();
});

it('répond 404 à toute route de passkey, .well-known/passkey-endpoints compris, quand les passkeys sont fermées', function () {
    // Le limiteur `passkeys` s'exécute avant l'interrupteur (priorité des
    // middlewares) : sans cette neutralisation, la contre-épreuve finirait en
    // 429 et ne prouverait plus rien.
    RateLimiter::for('passkeys', fn () => Limit::none());

    $routes = accountSwitchesPasskeyRoutes();
    $names = array_map(static fn (RoutingRoute $route): ?string => $route->getName(), $routes);

    expect($names)->toContain(
        'well-known.passkeys',
        'passkey.login-options',
        'passkey.login',
        'passkey.confirm-options',
        'passkey.confirm',
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    );

    $user = User::factory()->create();
    $passkey = $user->passkeys()->create([
        'name' => 'Clé de test',
        'credential_id' => 'credential-'.Str::random(16),
        'credential' => ['fixture' => true],
    ]);

    $hit = function (RoutingRoute $route) use ($user, $passkey) {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            expect($parameter)->toBe('passkey', "paramètre inattendu {$parameter} sur {$route->getName()}");
            $parameters[$parameter] = $passkey->getKey();
        }

        $middleware = $route->gatherMiddleware();
        $method = collect($route->methods())->first(static fn (string $verb): bool => $verb !== 'HEAD');

        // Chaque route est jouée dans le seul état où elle peut répondre :
        // invitée pour les routes `guest`, connectée et mot de passe
        // fraîchement confirmé pour les routes `auth`.
        if (in_array('auth:web', $middleware, true)) {
            $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        } else {
            $this->app['auth']->forgetGuards();
        }

        return $this->call((string) $method, route((string) $route->getName(), $parameters));
    };

    accountSwitchesConfigure(false);

    foreach ($routes as $route) {
        expect($hit($route)->status())->toBe(404, "{$route->getName()} répond sans 404 alors que les passkeys sont fermées");
    }

    // Aucune route n'a atteint son contrôleur : la passkey existe toujours.
    expect($passkey->fresh())->not->toBeNull();

    // Contre-épreuve : ouvertes, aucune de ces routes ne répond 404. Le 404
    // venait bien de l'interrupteur, pas d'une liaison ni d'une route absente.
    accountSwitchesConfigure(true);

    foreach ($routes as $route) {
        expect($hit($route)->status())->not->toBe(404, "{$route->getName()} répond 404 alors que les passkeys sont ouvertes");
    }
});

it("garde les routes d'inscription et de passkeys enregistrées quel que soit l'interrupteur", function () {
    $expected = [
        'register',
        'register.store',
        'passkey.login-options',
        'passkey.login',
        'passkey.confirm-options',
        'passkey.confirm',
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
        'well-known.passkeys',
    ];

    // L'application est redémarrée avec les deux interrupteurs déclarés à
    // `false`, puis à `true` : les routes sont enregistrées au démarrage, et
    // c'est là qu'une condition sur l'interrupteur casserait les helpers
    // Wayfinder régénérés au build.
    foreach (['false' => false, 'true' => true] as $value => $state) {
        accountSwitchesWithEnv((string) $value, function () use ($expected, $state): void {
            $this->refreshApplication();

            expect(config('accounts.registration_open'))->toBe($state)
                ->and(config('accounts.passkeys_enabled'))->toBe($state)
                ->and(AccountSwitches::registrationOpen())->toBe($state)
                ->and(AccountSwitches::passkeysEnabled())->toBe($state)
                ->and(Features::enabled(Features::registration()))->toBeTrue()
                ->and(Features::canManagePasskeys())->toBeTrue();

            foreach ($expected as $name) {
                $route = Route::getRoutes()->getByName($name);

                expect($route)->not->toBeNull("route {$name} absente");

                /** @var RoutingRoute $route */
                expect($route->gatherMiddleware())->toContain('accounts.switches');
            }
        });
    }

    $this->refreshApplication();
});

it("ne partage aucun lien de compte quand l'inscription est fermée", function () {
    accountSwitchesConfigure(false);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('accountsOpen', false));

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('accountsOpen', false)
            ->where('canRegister', false));

    // Non déclarée en production : même chose.
    accountSwitchesConfigure(null);

    accountSwitchesUnder('production', function (): void {
        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('accountsOpen', false)
                ->where('canRegister', false));
    });

    // Contre-épreuve : ouverte, les liens sont partagés.
    accountSwitchesConfigure(true);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accountsOpen', true)
            ->where('canRegister', true));
});

it('ne propose aucune passkey à la connexion, à la confirmation du mot de passe ni dans la sécurité quand les passkeys sont fermées', function () {
    $user = User::factory()->create();

    foreach ([false, true] as $state) {
        accountSwitchesConfigure($state);

        $this->app['auth']->forgetGuards();

        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/login')
                ->where('canUsePasskeys', $state));

        $this->actingAs($user)
            ->get(route('password.confirm'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/confirm-password')
                ->where('canUsePasskeys', $state));

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security')
                ->where('canManagePasskeys', $state)
                ->where('passkeys', []));
    }
});

it('déclare les deux interrupteurs vides dans .env.example', function () {
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('.env.example'))) ?: [];

    foreach (accountSwitchesVariables() as $name) {
        $values = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*'.preg_quote($name, '/').'\s*=(.*)$/', $line, $match) === 1) {
                $values[] = trim($match[1]);
            }
        }

        // Une seule affectation active, VIDE : non déclarée, donc la liste
        // blanche. Le dépôt n'ouvre ni ne ferme rien par une valeur écrite.
        expect($values)->toBe([''], "{$name} doit être déclarée une fois, vide, dans .env.example");
    }
});
