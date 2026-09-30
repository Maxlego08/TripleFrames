<?php

/*
|--------------------------------------------------------------------------
| Licence du dépôt et relevé des actifs tiers — spec 100 § 7.6
|--------------------------------------------------------------------------
|
| Le dépôt est privé, TOUS DROITS RÉSERVÉS (décision 6), et « l'absence de
| fichier laisse le statut ambigu » (`00` § Dépôt et licence). Le starter
| déclarait `MIT`, ce qui disait l'inverse (n° 75) : les trois déclarations
| — `LICENSE`, `composer.json`, `package.json` — doivent dire la même chose.
|
| Les actifs tiers tracent leur licence À CÔTÉ de leurs fichiers
| (`public/avatars/LICENSE.md`, `public/brand/LICENSE.md`, en-têtes des
| listes noires) et `THIRD_PARTY_NOTICES.md` les relève : une attribution
| CC BY due sans propriétaire ne serait tenue par personne.
|
*/

/**
 * Lignes d'un tableau Markdown placé sous un titre `## ...` du relevé,
 * cellules nettoyées ; en-tête et ligne de séparation exclus.
 *
 * @return list<array<string, string>> en-tête de colonne → cellule
 */
function licenseNoticeRows(string $heading): array
{
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('THIRD_PARTY_NOTICES.md'))) ?: [];
    $start = array_search("## {$heading}", $lines, true);

    expect($start)->not->toBeFalse("THIRD_PARTY_NOTICES.md n'a pas de section « {$heading} »");

    $table = [];

    for ($index = (int) $start + 1; $index < count($lines); $index++) {
        if (str_starts_with($lines[$index], '## ')) {
            break;
        }

        if (str_starts_with(trim($lines[$index]), '|')) {
            $table[] = array_map('trim', explode('|', trim(trim($lines[$index]), '|')));
        }
    }

    expect(count($table))->toBeGreaterThanOrEqual(2, "la section « {$heading} » n'a pas de tableau");

    $header = array_shift($table);
    array_shift($table); // Ligne de séparation.

    return array_map(static function (array $cells) use ($header): array {
        expect(count($cells))->toBe(count($header));

        return array_combine($header, $cells);
    }, $table);
}

/**
 * Éléments de code (entre accents graves) d'une cellule.
 *
 * @return list<string>
 */
function licenseNoticeCodes(string $cell): array
{
    preg_match_all('/`([^`]+)`/', $cell, $matches);

    return $matches[1];
}

/**
 * Fichiers qui existent pour un chemin du relevé (motif `*` admis).
 *
 * @return list<string>
 */
function licenseNoticeMatches(string $pattern): array
{
    return array_values(array_filter(glob(base_path($pattern)) ?: [], 'is_file'));
}

/**
 * Vrai si la licence exige une attribution : toute la famille CC BY
 * (CC BY, CC BY-SA, CC BY-NC…), jamais CC0.
 */
function licenseNoticeRequiresAttribution(string $licence): bool
{
    return preg_match('/\bCC[ -]BY\b/i', $licence) === 1;
}

it('déclare le dépôt tous droits réservés dans LICENSE, composer.json et package.json', function () {
    expect(base_path('LICENSE'))->toBeFile();

    $licence = (string) file_get_contents(base_path('LICENSE'));

    // « Copyright (c) 2026 — <nom du porteur>. Tous droits réservés. », en tête.
    expect($licence)->toMatch('/\ACopyright \(c\) 20\d{2}(?:-20\d{2})? — (?!\[)\S[^\n]*?\. Tous droits réservés\.$/mu')
        ->and($licence)->not->toContain('nom réel du porteur')
        // Suivi de l'interdiction de reproduction, de distribution et
        // d'usage sans autorisation écrite.
        ->and($licence)->toContain('reproduction', 'distribution', 'utilisation', 'autorisation écrite')
        // Et d'aucune licence libre.
        ->and($licence)->not->toContain('Permission is hereby granted')
        ->and($licence)->not->toMatch('/\b(?:MIT|GPL|Apache|BSD)\b/');

    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['license'] ?? null)->toBe('proprietary')
        ->and($composer['name'] ?? null)->toBe('tripleframes/tripleframes')
        // Restes du starter retirés dans le même commit.
        ->and($composer['description'] ?? '')->toBeString()->toContain('TripleFrames')
        ->and($composer)->not->toHaveKey('keywords');

    /** @var array<string, mixed> $package */
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($package['license'] ?? null)->toBe('UNLICENSED')
        ->and($package['private'] ?? null)->toBeTrue();
});

it('trace la licence de chaque actif tiers livré au J1, attribution comprise pour une source CC BY', function () {
    $delivered = licenseNoticeRows('Actifs livrés');
    $expected = licenseNoticeRows('Actifs attendus au jalon 1');
    $violations = [];

    // Toute ligne, livrée ou attendue : une licence CC BY porte son texte
    // d'attribution dès qu'elle entre au relevé.
    foreach ([...$delivered, ...$expected] as $row) {
        $attribution = trim($row['Attribution'], " \u{2014}-");

        if (licenseNoticeRequiresAttribution($row['Licence']) && $attribution === '') {
            $violations[] = "{$row['Chemin']} : licence {$row['Licence']} sans texte d'attribution";
        }
    }

    // Actif livré : ses chemins existent, sa licence existe à côté de lui.
    $coveredFiles = [];

    foreach ($delivered as $row) {
        $paths = licenseNoticeCodes($row['Chemin']);

        if ($paths === []) {
            $violations[] = "ligne livrée sans chemin : {$row['Chemin']}";
        }

        foreach ($paths as $path) {
            $matches = licenseNoticeMatches($path);

            if ($matches === []) {
                $violations[] = "{$path} : relevé comme livré mais introuvable";
            }

            array_push($coveredFiles, ...$matches);

            foreach (licenseNoticeCodes($row['Porteur de la licence']) as $carrier) {
                // `# license:` : la licence est portée par l'en-tête du
                // fichier lui-même (le contenu de l'en-tête est prouvé par 40).
                if (str_starts_with($carrier, '#')) {
                    foreach ($matches as $file) {
                        if (preg_match('/^'.preg_quote($carrier, '/').'/m', (string) file_get_contents($file)) !== 1) {
                            $violations[] = "{$path} : en-tête « {$carrier} » absent";
                        }
                    }

                    continue;
                }

                if (! is_file(base_path($carrier))) {
                    $violations[] = "{$path} : porteur de licence {$carrier} introuvable";
                }
            }
        }

        if (licenseNoticeCodes($row['Porteur de la licence']) === []) {
            $violations[] = "{$row['Chemin']} : aucun porteur de licence nommé";
        }
    }

    // Actif attendu : sa ligne passe dans « Actifs livrés » dans le commit
    // qui le livre, jamais après.
    foreach ($expected as $row) {
        foreach (licenseNoticeCodes($row['Chemin']) as $path) {
            if (licenseNoticeMatches($path) !== []) {
                $violations[] = "{$path} : livré, mais sa ligne est encore dans « Actifs attendus au jalon 1 »";
            }
        }
    }

    // Tout fichier d'actif tiers statique est couvert par une ligne livrée,
    // sa licence exceptée.
    $coveredFiles = array_map('realpath', $coveredFiles);

    foreach (['public/avatars', 'public/brand'] as $directory) {
        foreach (glob(base_path("{$directory}/*")) ?: [] as $file) {
            if (is_file($file) && basename($file) !== 'LICENSE.md' && ! in_array(realpath($file), $coveredFiles, true)) {
                $violations[] = "{$directory}/".basename($file).' : actif tiers absent des « Actifs livrés »';
            }
        }
    }

    expect($violations)->toBe([]);

    // Le relevé couvre les trois actifs tiers du J1 (40 § 5.7, § 9 ; 90,
    // n° 72), et la règle d'attribution s'exerce au moins sur une ligne.
    $allPaths = array_merge(...array_map(
        static fn (array $row): array => licenseNoticeCodes($row['Chemin']),
        [...$delivered, ...$expected],
    ));

    expect($allPaths)->toContain(
        'public/avatars/preset-*.webp',
        'resources/moderation/nicknames/en.txt',
        'resources/moderation/nicknames/fr.txt',
        'public/brand/tmdb.svg',
    )->and(array_filter(
        [...$delivered, ...$expected],
        static fn (array $row): bool => licenseNoticeRequiresAttribution($row['Licence']),
    ))->not->toBeEmpty();
});
