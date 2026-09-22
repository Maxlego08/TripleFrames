<?php

use App\Support\I18n\TranslationDomains;

/**
 * Les trois vérifications de couverture que la spec 05 exige en CI, plus le
 * contrôle de dérive de `translations.d.ts`.
 *
 * Portée : le **domaine joueur** seulement — `mail` compris, gabarits de
 * réponse aux demandes de retrait inclus, puisqu'ils partent vers un tiers
 * extérieur dont la langue n'est pas celle du curateur. Seul `admin` en est
 * exclu : il est français par construction et `lang/en/admin.php` n'existe
 * pas.
 *
 * Ces vérifications lisent les **fichiers**, jamais le `Translator` : c'est le
 * contenu versionné que la CI doit refuser, pas ce qu'un repli de locale
 * rattrape à l'exécution. Les chemins sont dérivés de `__DIR__` et non de
 * `lang_path()`, parce qu'un jeu de données Pest est résolu avant que
 * l'application ne soit construite.
 */
function i18nBasePath(string $path): string
{
    return dirname(__DIR__, 3).'/'.$path;
}

/**
 * @param  array<array-key, mixed>  $lines
 * @return array<string, string>
 */
function i18nFlatten(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat = array_merge($flat, i18nFlatten($value, $path));
        } elseif (is_string($value)) {
            $flat[$path] = $value;
        }
    }

    return $flat;
}

/**
 * @return array<string, string>
 */
function i18nFile(string $locale, string $domain): array
{
    $path = i18nBasePath('lang/'.$locale.'/'.$domain.'.php');

    if (! is_file($path)) {
        return [];
    }

    $lines = require $path;

    return is_array($lines) ? i18nFlatten($lines) : [];
}

/**
 * @return array<string, string>
 */
function i18nJson(string $locale): array
{
    $decoded = json_decode((string) file_get_contents(i18nBasePath('lang/'.$locale.'.json')), true);

    return is_array($decoded) ? i18nFlatten($decoded) : [];
}

/**
 * Jeu de `:placeholder` d'une ligne, trié : c'est lui qui doit être identique
 * d'une langue à l'autre, pas l'ordre des mots.
 *
 * @return list<string>
 */
function i18nPlaceholders(string $line): array
{
    preg_match_all('/:(?!:)([A-Za-z][A-Za-z0-9_]*)/', $line, $matches);

    $found = array_values(array_unique($matches[1]));
    sort($found);

    return $found;
}

/**
 * Domaines soumis à la symétrie : tout fichier de `lang/en`, framework
 * compris, sauf le domaine du back-office.
 *
 * @return list<string>
 */
function i18nCheckedDomains(): array
{
    $domains = [];

    foreach (glob(i18nBasePath('lang/en/*.php')) ?: [] as $file) {
        $domain = basename($file, '.php');

        if ($domain !== TranslationDomains::ADMIN) {
            $domains[] = $domain;
        }
    }

    sort($domains);

    return $domains;
}

/**
 * Littéraux passés à `t()` / `tChoice()` dans le front, fichier par fichier.
 *
 * Un balayage par expression régulière suffit parce que `t()` est maison et
 * que ses clés sont des littéraux. L'appel est précédé d'un caractère qui
 * n'est ni un mot ni un point : `format(`, `object.t(` et `assert(` ne
 * ressemblent donc pas à un appel de traduction.
 *
 * @return array<string, list<string>>
 */
function i18nFrontCalls(): array
{
    $ignored = [
        '/resources/js/routes/',
        '/resources/js/actions/',
        '/resources/js/wayfinder/',
        '/resources/js/components/ui/',
    ];

    $calls = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(i18nBasePath('resources/js'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        foreach ($ignored as $prefix) {
            if (str_contains($path, $prefix)) {
                continue 2;
            }
        }

        preg_match_all(
            '/(?<![\w$.])t(?:Choice)?\(\s*[\'"]([^\'"]+)[\'"]/',
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        if ($matches[1] !== []) {
            $calls[$path] = array_values(array_unique($matches[1]));
        }
    }

    return $calls;
}

it('keeps the same keys in fr and en for every checked domain', function (string $domain) {
    $en = i18nFile('en', $domain);
    $fr = i18nFile('fr', $domain);

    expect(array_diff(array_keys($en), array_keys($fr)))
        ->toBe([], "Clés présentes en `en` et absentes en `fr` dans le domaine [{$domain}].");

    expect(array_diff(array_keys($fr), array_keys($en)))
        ->toBe([], "Clés présentes en `fr` et absentes en `en` dans le domaine [{$domain}].");
})->with(i18nCheckedDomains());

it('keeps the same placeholders in both translations of a key', function (string $domain) {
    $en = i18nFile('en', $domain);
    $fr = i18nFile('fr', $domain);

    $mismatched = [];

    foreach (array_intersect_key($en, $fr) as $key => $line) {
        if (i18nPlaceholders($line) !== i18nPlaceholders($fr[$key])) {
            $mismatched[] = $domain.'.'.$key;
        }
    }

    expect($mismatched)->toBe(
        [],
        'Un :placeholder oublié ne se voit pas à la relecture et produit une phrase fausse en production.',
    );
})->with(i18nCheckedDomains());

it('keeps the literal dictionaries of fortify and the framework symmetrical', function () {
    $en = i18nJson('en');
    $fr = i18nJson('fr');

    expect($en)->not->toBeEmpty();
    expect(array_diff(array_keys($en), array_keys($fr)))->toBe([]);
    expect(array_diff(array_keys($fr), array_keys($en)))->toBe([]);

    $mismatched = [];

    foreach (array_intersect_key($en, $fr) as $key => $line) {
        if (i18nPlaceholders($line) !== i18nPlaceholders($fr[$key])) {
            $mismatched[] = $key;
        }
    }

    expect($mismatched)->toBe([]);
});

it('carries the two literal keys the repository already emits', function () {
    // Le dépôt n'en compte que deux et la spec 05 les nomme : ce sont des
    // chaînes de la famille Fortify, pas des clés TripleFrames. Toute clé
    // écrite pour le projet est de la forme `domaine.chemin.clé`.
    expect(i18nJson('fr'))->toHaveKeys(['Profile updated.', 'Password updated.']);
});

it('only calls translation keys that exist in a dictionary', function () {
    $known = [];

    foreach ([...i18nCheckedDomains(), TranslationDomains::ADMIN] as $domain) {
        foreach (['en', 'fr'] as $locale) {
            foreach (array_keys(i18nFile($locale, $domain)) as $key) {
                $known[$domain.'.'.$key] = true;
            }
        }
    }

    $unknown = [];

    foreach (i18nFrontCalls() as $path => $keys) {
        foreach ($keys as $key) {
            if (! isset($known[$key])) {
                $unknown[] = $key.' ('.$path.')';
            }
        }
    }

    expect($unknown)->toBe([], 'Clé appelée par le front et absente de tout dictionnaire.');
});

it('keeps the generated translation types in sync with the dictionaries', function () {
    $this->artisan('lang:types', ['--check' => true])->assertSuccessful();
});
