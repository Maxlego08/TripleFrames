<?php

use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Aucune image réelle ni extrait de production — spec 100 § 7.3
|--------------------------------------------------------------------------
|
| « Aucune image de jeu réelle n'entre dans le dépôt, jamais », ni aucun
| extrait de base de production (`00` § Dépôt et licence, 10 § 13.3,
| décision 6) : la forge hors UE ne voit jamais une image curée ni une
| donnée personnelle. Les images du catalogue de démonstration sont GÉNÉRÉES
| par Imagick au seeding et ne sont jamais suivies ; les fixtures TMDB
| restent des JSON synthétiques.
|
| La vérité est ce que git suit, pas ce que le disque contient : la liste
| vient de `git ls-files`. Sans git, le test ÉCHOUE — il ne se saute jamais,
| car un test sauté est vert partout et ne prouve rien nulle part.
|
*/

/**
 * Sortie de git lancé à la racine du dépôt ; échoue si git est absent ou
 * refuse la commande.
 *
 * @param  list<string>  $arguments
 */
function realFixtureGit(array $arguments): string
{
    $result = Process::path(base_path())->run(['git', ...$arguments]);

    expect($result->successful())->toBeTrue(sprintf(
        'git %s a échoué (code %s) : %s',
        implode(' ', $arguments),
        var_export($result->exitCode(), true),
        trim($result->errorOutput()),
    ));

    return $result->output();
}

/**
 * Fichiers suivis par git (index compris : un ajout forcé est vu avant son
 * commit), chemins relatifs à la racine, séparateur `/`.
 *
 * @param  list<string>  $pathspec
 * @return list<string>
 */
function realFixtureTrackedFiles(array $pathspec = []): array
{
    $output = realFixtureGit(['ls-files', '-z', '--', ...$pathspec]);

    return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
}

/**
 * Extensions refusées : images, vidages et archives.
 *
 * @return list<string>
 */
function realFixtureForbiddenExtensions(): array
{
    return [
        // Images.
        'png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'svg', 'ico',
        // Vidages et archives.
        'sql', 'dump', 'gz', 'tgz', 'zip', '7z', 'tar', 'bak', 'sqlite', 'sqlite3', 'db',
    ];
}

/**
 * Liste d'autorisation. Un actif tiers n'y entre qu'avec sa licence, suivie
 * à côté de ses fichiers (spec 100 § 7.6).
 *
 * @param  list<string>  $tracked
 */
function realFixtureIsAllowed(string $path, array $tracked): bool
{
    // Icônes du site.
    if (in_array($path, ['public/favicon.ico', 'public/favicon.svg', 'public/apple-touch-icon.png'], true)) {
        return true;
    }

    // Marques tierces imposées (logo TMDB, contrat C16, n° 72).
    if (preg_match('#^public/brand/[^/]+$#', $path) === 1) {
        return in_array('public/brand/LICENSE.md', $tracked, true);
    }

    // Pack d'avatars prédéfinis (contrat C5, D27 du 23/09).
    if (preg_match('#^public/avatars/[^/]+\.webp$#', $path) === 1) {
        return in_array('public/avatars/LICENSE.md', $tracked, true);
    }

    // Maquettes d'interface du porteur (décision du 26/09) : images seulement,
    // jamais un vidage ni une archive, et seulement tant que le fichier qui
    // interdit tout photogramme dans ce dossier est suivi à côté d'elles.
    if (preg_match('#^design-test/.+\.(png|jpe?g|webp|svg)$#', $path) === 1) {
        return in_array('design-test/MAQUETTES.md', $tracked, true);
    }

    return false;
}

it("ne suit aucune image, aucun dump ni aucune archive hors de la liste d'autorisation", function () {
    $tracked = realFixtureTrackedFiles();
    $violations = [];

    foreach ($tracked as $path) {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, realFixtureForbiddenExtensions(), true) && ! realFixtureIsAllowed($path, $tracked)) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);

    // La liste vient bien du dépôt : un `git ls-files` muet serait vert pour
    // de mauvaises raisons.
    expect($tracked)->toContain('composer.json', 'public/favicon.ico', 'public/apple-touch-icon.png');
});

it('ne suit aucun fichier sous tests/Load/.data', function () {
    // Liste des titres publiés, codes de salon, adresses des sites voisins
    // (§ 16) : un extrait de production et une liste d'hôtes littéraux, que
    // le filtre par extension laisserait passer (`.json`, `.csv`, `.txt`).
    expect(realFixtureTrackedFiles(['tests/Load/.data']))->toBe([]);

    // Le `.gitignore` l'exclut. Seul, il ne protège pas d'un ajout forcé :
    // c'est l'assertion ci-dessus qui le fait.
    $ignored = Process::path(base_path())->run(['git', 'check-ignore', '--quiet', '--no-index', 'tests/Load/.data/titres.json']);

    expect($ignored->exitCode())->toBe(0, 'tests/Load/.data n\'est pas ignoré par .gitignore : '.trim($ignored->errorOutput()));
});
