<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Indexation : la variable et robots.txt — spec 100 § 12
|--------------------------------------------------------------------------
|
| Au jalon 1, le site est intégralement `noindex` ; au jalon 2, la levée est
| une variable d'ENVIRONNEMENT de production, jamais une valeur du dépôt,
| pour qu'aucun déploiement ne puisse l'inverser ni la rétablir par accident
| (CLAUDE.md §1). Cette spec possède la variable `SITE_INDEXABLE`, sa clé de
| configuration `app.indexable`, sa ligne de `.env.example` et
| `public/robots.txt`.
|
| Le comportement des en-têtes dans les deux états appartient au middleware
| de la spec 90 et à son `IndexingTest` (R-04 : une seule preuve, chez le
| propriétaire du comportement) : aucun test d'en-tête ici.
|
*/

/**
 * Relit `config/app.php` avec `SITE_INDEXABLE` posé à `$value`, ou absent
 * quand `$value` est nul, et rend sa clé `indexable`.
 *
 * `env()` lit `$_SERVER`, puis `$_ENV`, puis `getenv()` : les trois sources
 * sont posées ou retirées ensemble, puis rétablies, pour qu'une variable
 * exportée dans le shell du développeur ne fausse ni ce test ni les suivants.
 */
function siteIndexingRead(?string $value): mixed
{
    $name = 'SITE_INDEXABLE';
    $server = array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null;
    $hadServer = array_key_exists($name, $_SERVER);
    $env = array_key_exists($name, $_ENV) ? $_ENV[$name] : null;
    $hadEnv = array_key_exists($name, $_ENV);
    $process = getenv($name);

    try {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        } else {
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }

        /** @var array<string, mixed> $config */
        $config = require base_path('config/app.php');

        expect($config)->toHaveKey('indexable');

        return $config['indexable'];
    } finally {
        unset($_SERVER[$name], $_ENV[$name]);

        if ($hadServer) {
            $_SERVER[$name] = $server;
        }

        if ($hadEnv) {
            $_ENV[$name] = $env;
        }

        putenv($process === false ? $name : "{$name}={$process}");
    }
}

/**
 * Lectures de `SITE_INDEXABLE` dans le code PHP de l'application, hors
 * commentaires : un docblock qui CITE la variable n'est pas une lecture.
 *
 * @return list<string> chemins relatifs, séparateur `/`, une entrée par lecture
 */
function siteIndexingReaders(): array
{
    $readers = [];

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

            $count = preg_match_all(
                '/\b(?:env|getenv|Env::get)\s*\(\s*[\'"]SITE_INDEXABLE[\'"]|\$_(?:ENV|SERVER)\s*\[\s*[\'"]SITE_INDEXABLE[\'"]/',
                $code,
            );

            for ($index = 0; $index < $count; $index++) {
                $readers[] = $relative;
            }
        }
    }

    sort($readers);

    return $readers;
}

/**
 * Affectations actives (non commentées) d'une variable dans `.env.example`,
 * valeur sans guillemets ni commentaire de fin.
 *
 * @return list<string>
 */
function siteIndexingEnvExampleValues(string $name): array
{
    $values = [];

    foreach (preg_split('/\R/', (string) file_get_contents(base_path('.env.example'))) ?: [] as $line) {
        if (preg_match('/^\s*'.preg_quote($name, '/').'\s*=(.*)$/', $line, $match) !== 1) {
            continue;
        }

        $value = trim($match[1]);
        $value = trim((string) preg_replace('/\s+#.*$/', '', $value));

        $values[] = trim($value, '"\'');
    }

    return $values;
}

it("lit SITE_INDEXABLE en booléen dans config('app.indexable'), faux quand la variable est absente", function () {
    // Absente : `false`, jamais `null` — le middleware de 90 compare à
    // `=== true`, et une clé nulle serait lue comme une troisième valeur.
    expect(siteIndexingRead(null))->toBeFalse();

    // Toujours un booléen, et la conversion échoue FERMÉE : ce qu'un humain
    // écrit pour « non » et toute valeur inconnue gardent le noindex.
    foreach (['false', 'FALSE', '(false)', '0', '', 'off', 'no', 'null', 'oui', 'indexable'] as $value) {
        expect(siteIndexingRead($value))->toBeFalse("SITE_INDEXABLE={$value} doit garder le noindex");
    }

    foreach (['true', 'TRUE', '(true)', '1', 'on', 'yes'] as $value) {
        expect(siteIndexingRead($value))->toBeTrue("SITE_INDEXABLE={$value} doit lever le noindex");
    }

    // L'application démarrée porte la clé, en booléen.
    expect(config('app.indexable'))->toBeBool();

    // Lue une seule fois, par la configuration : un `env()` hors de
    // `config/` rendrait `null` sous `config:cache`, en production.
    expect(siteIndexingReaders())->toBe(['config/app.php']);

    // `.env.example`, que `composer setup` recopie, la livre fausse : le dépôt
    // ne lève jamais le noindex d'une machine installée depuis lui.
    $shipped = siteIndexingEnvExampleValues('SITE_INDEXABLE');

    expect($shipped)->toHaveCount(1)
        ->and(siteIndexingRead($shipped[0]))->toBeFalse();
});

it("livre un robots.txt qui n'interdit que /admin et /f/", function () {
    $path = public_path('robots.txt');

    expect(is_file($path))->toBeTrue('public/robots.txt est absent');

    $agents = [];
    $disallowed = [];
    $others = [];

    foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
        $line = trim((string) preg_replace('/#.*$/', '', $line));

        if ($line === '') {
            continue;
        }

        expect($line)->toContain(':');

        [$field, $value] = array_map(trim(...), explode(':', $line, 2));

        match (strtolower($field)) {
            'user-agent' => $agents[] = $value,
            'disallow' => $disallowed[] = $value,
            default => $others[] = $line,
        };
    }

    // Un seul groupe, pour tous les robots, et rien d'autre qu'interdire.
    expect($agents)->toBe(['*'])
        ->and($others)->toBe([]);

    // JAMAIS `Disallow: /` : un robot qui ne peut pas lire une page ne lit pas
    // son noindex, et l'URL nue d'un lien partagé pourrait être indexée sans
    // contenu (n° 71). Le noindex passe par l'en-tête ; `Disallow` n'épargne
    // que l'exploration du back-office et des images signées.
    expect($disallowed)->not->toContain('/')
        ->and($disallowed)->toEqualCanonicalizing(['/admin', '/f/'])
        ->and($disallowed)->toHaveCount(2);

    // Fichier STATIQUE, servi par nginx avant Laravel, donc identique quand
    // `SITE_INDEXABLE` change : aucune route ne le rend.
    $routes = array_map(
        static fn (Route $route): string => $route->uri(),
        app('router')->getRoutes()->getRoutes(),
    );

    expect($routes)->not->toBeEmpty()
        ->and($routes)->not->toContain('robots.txt');
});

it('une page légale provisoire reste noindex même indexable', function () {
    // Garde de code (n° 19, spec 100 § 21) : lever `SITE_INDEXABLE` avant que
    // les textes définitifs soient déposés n'indexe aucun texte « à fournir ».
    // Le comportement d'en-tête complet reste prouvé par `Public/IndexingTest` ;
    // ce test garde la seule condition propre à la levée.
    $this->withoutVite();

    config(['app.indexable' => true]);

    foreach (['notice' => '/legal/notice', 'terms' => '/legal/terms', 'privacy' => '/legal/privacy'] as $page => $url) {
        config(["legal.pages.{$page}.provisional" => true]);

        expect($this->get($url)->assertOk()->headers->all('x-robots-tag'))
            ->toBe(['noindex, nofollow'], "{$url} provisoire doit rester noindex");

        // Entrée d'un autre type qu'un vrai booléen : provisoire, l'erreur sans danger.
        config(["legal.pages.{$page}.provisional" => 'false']);

        expect($this->get($url)->assertOk()->headers->all('x-robots-tag'))
            ->toBe(['noindex, nofollow'], "{$url} au drapeau mal typé doit rester noindex");

        config(["legal.pages.{$page}.provisional" => false]);

        $this->get($url)->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    // Avec la configuration livrée par le dépôt, la variable levée seule
    // n'indexe que l'accueil tant qu'une page reste provisoire.
    /** @var array{pages: array<string, array{provisional: mixed}>} $shipped */
    $shipped = require base_path('config/legal.php');
    config(['legal.pages' => $shipped['pages']]);

    foreach (['notice' => '/legal/notice', 'terms' => '/legal/terms', 'privacy' => '/legal/privacy'] as $page => $url) {
        if ($shipped['pages'][$page]['provisional'] !== false) {
            expect($this->get($url)->headers->all('x-robots-tag'))->toBe(['noindex, nofollow'], "{$url} est livrée provisoire");
        }
    }

    $this->get('/')->assertOk()->assertHeaderMissing('X-Robots-Tag');
});
