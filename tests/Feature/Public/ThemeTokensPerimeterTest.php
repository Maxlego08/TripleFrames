<?php

use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Périmètre du script anti-couleur — spec 90 § 9.3, contrat C16 § 2.11
|--------------------------------------------------------------------------
|
| `scripts/check-theme-tokens.mjs` refuse couleurs et tailles en dur dans
| les chemins de `WATCHED`, une LISTE BLANCHE : un écran joueur né hors de
| ces chemins échapperait au principe 13 sans que la CI le dise. La règle
| `[unclassified]`, qui refuse tout fichier ni surveillé ni exempté, est
| prouvée par la spec 100 (L100-2) ; ce fichier prouve le PÉRIMÈTRE, dont 90
| est propriétaire (R-04).
|
| Les deux listes sont lues dans le script lui-même, par son option
| `--perimeter` : le test ne les recopie pas, il les confronte au disque et
| à la spec.
|
*/

/**
 * Les deux listes du script, telles qu'écrites dans `WATCHED` et `EXEMPT`.
 *
 * @return array{watched: list<string>, exempt: list<string>}
 */
function themePerimeter(): array
{
    $result = Process::path(base_path())->run(['node', 'scripts/check-theme-tokens.mjs', '--perimeter']);

    expect($result->successful())->toBeTrue(sprintf(
        'node scripts/check-theme-tokens.mjs --perimeter a échoué (code %s) : %s',
        var_export($result->exitCode(), true),
        trim($result->errorOutput()),
    ));

    /** @var array{watched: list<string>, exempt: list<string>} $perimeter */
    $perimeter = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

    expect($perimeter)->toHaveKeys(['watched', 'exempt'])
        ->and($perimeter['watched'])->toBeList()
        ->and($perimeter['exempt'])->toBeList();

    return $perimeter;
}

/**
 * Vrai si une entrée désigne ce chemin : le chemin lui-même, ou un
 * répertoire qui le contient — jamais un simple préfixe de nom, même règle
 * que `covers()` du script.
 *
 * @param  list<string>  $entries
 */
function themePerimeterCovers(array $entries, string $path): bool
{
    foreach ($entries as $entry) {
        if ($path === $entry || str_starts_with($path, $entry.'/')) {
            return true;
        }
    }

    return false;
}

/**
 * Exemptions permanentes du script (spec 100 § 7.4) : fichiers générés.
 *
 * @return list<string>
 */
function themePerimeterGenerated(): array
{
    return [
        'resources/js/components/ui',
        'resources/js/routes',
        'resources/js/actions',
        'resources/js/wayfinder',
        'resources/js/types/translations.d.ts',
    ];
}

/**
 * Ajouts du jalon 1 à `WATCHED`, spec 90 § 9.3, à la lettre. `welcome.tsx`
 * y est entré « à sa réécriture en accueil » (L90-8), et non au gel : c'est
 * la spec qui le voulait. `hooks/admin` et `lib/admin`, inscrits « dès leur
 * création » par L20-9a, sont vérifiés avec le reste du back-office ; la
 * seconde assertion du test, sur les répertoires, attrape tout répertoire né
 * depuis le gel.
 *
 * @return list<string>
 */
function themePerimeterFirstMilestone(): array
{
    return [
        'resources/js/pages/game',
        'resources/js/pages/room',
        'resources/js/pages/legal',
        'resources/js/pages/error.tsx',
        'resources/js/pages/welcome.tsx',
        'resources/js/layouts/game',
        'resources/js/layouts/public',
        'resources/js/components/game',
        'resources/js/components/room',
        'resources/js/components/public',
        'resources/js/components/state',
        'resources/js/hooks/game',
        'resources/js/lib/game',
        'resources/js/components/language-switcher.tsx',
        'resources/js/app.tsx',
        'resources/js/types/game-wire.ts',
        'resources/js/types/answers.ts',
        'resources/js/types/scoring.ts',
        'resources/js/types/pool.ts',
        'resources/js/types/player.ts',
        'resources/js/types/room-settings.ts',
        'resources/js/lib/room-settings.ts',
        'resources/js/lib/frame-geometry.ts',
        'resources/js/types/legal.ts',
    ];
}

/**
 * Répertoires de `resources/js` hors de `WATCHED` au commit de gel du lot
 * L90-1 : ceux du starter, dont les fichiers vivent dans `EXEMPT`. Tout
 * autre répertoire est né depuis le jalon 1 et doit être surveillé.
 *
 * @return list<string>
 */
function themePerimeterFrozenDirectories(): array
{
    return [
        'resources/js',
        'resources/js/components',
        'resources/js/hooks',
        'resources/js/layouts',
        'resources/js/layouts/app',
        'resources/js/layouts/auth',
        'resources/js/layouts/settings',
        'resources/js/lib',
        'resources/js/pages',
        'resources/js/pages/auth',
        'resources/js/pages/settings',
        'resources/js/types',
    ];
}

/**
 * `EXEMPT` tel que régénéré par `--list-unclassified` au commit de gel du lot
 * L90-1, puis relu. La liste « ne fait que décroître » (spec 90 § 9.3, 100
 * § 7.4) : l'`EXEMPT` du script en est toujours un sous-ensemble.
 *
 * @return list<string>
 */
function themePerimeterFrozenExempt(): array
{
    return [
        'resources/js/components/alert-error.tsx',
        'resources/js/components/app-content.tsx',
        'resources/js/components/app-header.tsx',
        'resources/js/components/app-logo-icon.tsx',
        'resources/js/components/app-logo.tsx',
        'resources/js/components/app-shell.tsx',
        'resources/js/components/app-sidebar-header.tsx',
        'resources/js/components/app-sidebar.tsx',
        'resources/js/components/breadcrumbs.tsx',
        'resources/js/components/delete-user.tsx',
        'resources/js/components/heading.tsx',
        'resources/js/components/input-error.tsx',
        'resources/js/components/manage-passkeys.tsx',
        'resources/js/components/manage-two-factor.tsx',
        'resources/js/components/nav-footer.tsx',
        'resources/js/components/nav-main.tsx',
        'resources/js/components/nav-user.tsx',
        'resources/js/components/passkey-item.tsx',
        'resources/js/components/passkey-register.tsx',
        'resources/js/components/passkey-verify.tsx',
        'resources/js/components/password-input.tsx',
        'resources/js/components/text-link.tsx',
        'resources/js/components/two-factor-recovery-codes.tsx',
        'resources/js/components/two-factor-setup-modal.tsx',
        'resources/js/components/user-info.tsx',
        'resources/js/components/user-menu-content.tsx',
        'resources/js/hooks/use-clipboard.ts',
        'resources/js/hooks/use-current-url.ts',
        'resources/js/hooks/use-flash-toast.ts',
        'resources/js/hooks/use-initials.tsx',
        'resources/js/hooks/use-mobile-navigation.ts',
        'resources/js/hooks/use-mobile.tsx',
        'resources/js/hooks/use-translations.ts',
        'resources/js/hooks/use-two-factor-auth.ts',
        'resources/js/layouts/app-layout.tsx',
        'resources/js/layouts/app/app-header-layout.tsx',
        'resources/js/layouts/app/app-sidebar-layout.tsx',
        'resources/js/layouts/auth-layout.tsx',
        'resources/js/layouts/auth/auth-card-layout.tsx',
        'resources/js/layouts/auth/auth-simple-layout.tsx',
        'resources/js/layouts/auth/auth-split-layout.tsx',
        'resources/js/layouts/settings/layout.tsx',
        'resources/js/lib/i18n.ts',
        'resources/js/lib/utils.ts',
        'resources/js/pages/auth/confirm-password.tsx',
        'resources/js/pages/auth/forgot-password.tsx',
        'resources/js/pages/auth/login.tsx',
        'resources/js/pages/auth/register.tsx',
        'resources/js/pages/auth/reset-password.tsx',
        'resources/js/pages/auth/two-factor-challenge.tsx',
        'resources/js/pages/auth/verify-email.tsx',
        'resources/js/pages/dashboard.tsx',
        'resources/js/pages/settings/profile.tsx',
        'resources/js/pages/settings/security.tsx',
        'resources/js/pages/welcome.tsx',
        'resources/js/types/auth.ts',
        'resources/js/types/global.d.ts',
        'resources/js/types/index.ts',
        'resources/js/types/navigation.ts',
        'resources/js/types/ui.ts',
        'resources/js/types/vite-env.d.ts',
    ];
}

/**
 * Répertoires de `resources/js`, relatifs à la racine du dépôt, séparateur
 * `/`, hors des arbres générés.
 *
 * @return list<string>
 */
function themePerimeterDirectories(): array
{
    $root = base_path('resources/js');
    $directories = ['resources/js'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        if (! $entry->isDir()) {
            continue;
        }

        $relative = 'resources/js/'.str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));

        if (! themePerimeterCovers(themePerimeterGenerated(), $relative)) {
            $directories[] = $relative;
        }
    }

    sort($directories);

    return $directories;
}

it('surveille chaque répertoire joueur créé depuis le jalon 1', function () {
    $watched = themePerimeter()['watched'];

    expect($watched)->toBe(array_values(array_unique($watched)), 'WATCHED contient un doublon');

    foreach ($watched as $entry) {
        expect($entry)->toStartWith('resources/js/')
            ->and($entry)->not->toEndWith('/')
            ->and(themePerimeterCovers(themePerimeterGenerated(), $entry))
            ->toBeFalse("{$entry} est un fichier généré : l'y surveiller n'a aucun sens");
    }

    // Chaque ajout du jalon 1 nommé par la spec est surveillé DÈS LE GEL,
    // avant que le premier fichier n'y naisse.
    foreach (themePerimeterFirstMilestone() as $path) {
        expect(themePerimeterCovers($watched, $path))->toBeTrue("{$path} n'est pas surveillé (spec 90 § 9.3)");
    }

    // Le back-office reste surveillé.
    foreach ([
        'resources/js/pages/admin',
        'resources/js/components/admin',
        'resources/js/layouts/admin',
        'resources/js/types/admin.ts',
        // Répertoires de la spec 20, inscrits par L20-9a (EN20-2).
        'resources/js/hooks/admin',
        'resources/js/lib/admin',
    ] as $path) {
        expect(themePerimeterCovers($watched, $path))->toBeTrue("{$path} n'est plus surveillé");
    }

    // Tout répertoire né après le gel — joueur ou non — est sous WATCHED :
    // un nouvel écran n'échappe pas au principe 13 par simple oubli.
    $unwatched = array_values(array_filter(
        themePerimeterDirectories(),
        static fn (string $directory): bool => ! in_array($directory, themePerimeterFrozenDirectories(), true)
            && ! themePerimeterCovers($watched, $directory),
    ));

    expect($unwatched)->toBe([], 'répertoire(s) né(s) après le gel hors de WATCHED : '.implode(', ', $unwatched));
});

it('ne garde dans EXEMPT que des fichiers existants hors du périmètre surveillé', function () {
    ['watched' => $watched, 'exempt' => $exempt] = themePerimeter();

    expect($exempt)->toBe(array_values(array_unique($exempt)), 'EXEMPT contient un doublon');

    foreach ($exempt as $path) {
        expect($path)->toStartWith('resources/js/')
            ->and(str_ends_with($path, '.ts') || str_ends_with($path, '.tsx'))
            ->toBeTrue("{$path} n'est pas un fichier .ts ou .tsx")
            ->and(base_path($path))->toBeFile()
            ->and(themePerimeterCovers($watched, $path))
            ->toBeFalse("{$path} est déjà surveillé : il sort d'EXEMPT")
            ->and(themePerimeterCovers(themePerimeterGenerated(), $path))
            ->toBeFalse("{$path} est un fichier généré, exempté en permanence");
    }

    // EXEMPT ne fait que décroître : aucun fichier n'y entre après le gel.
    $added = array_values(array_diff($exempt, themePerimeterFrozenExempt()));

    expect($added)->toBe([], 'fichier(s) entré(s) dans EXEMPT après le gel : '.implode(', ', $added));
});
