<?php

use Database\Seeders\DemoCatalogueSeeder;

/*
|--------------------------------------------------------------------------
| Parcours Playwright — spec 100 § 19 (L100-16)
|--------------------------------------------------------------------------
|
| Les parcours se jouent à la main contre la préproduction : la suite ne les
| lance jamais. Elle garde ce qui peut dériver sans qu'aucun parcours ne soit
| joué : qu'ils restent hors de la CI, que leur cible reste une variable du
| poste, et que les deux copies qu'ils tiennent du dépôt — les titres du
| catalogue de démonstration et les libellés anglais qu'ils cherchent —
| restent égales à leur source.
|
*/

function browserJourneySource(string $relative): string
{
    $path = base_path($relative);

    expect(is_file($path))->toBeTrue("{$relative} est absent");

    return (string) file_get_contents($path);
}

it('garde les titres du parcours égaux aux titres anglais du catalogue de démonstration', function (): void {
    $definitions = (new ReflectionMethod(DemoCatalogueSeeder::class, 'movieDefinitions'))
        ->invoke(new DemoCatalogueSeeder);

    expect($definitions)->toBeArray();

    $expected = array_map(static fn (array $definition): string => $definition['titles']['en'], $definitions);

    preg_match_all("/^\s+(['\"])(.+)\\1,$/m", browserJourneySource('tests/Browser/support/demo-titles.ts'), $matches);

    $titles = array_map(
        static fn (string $quote, string $title): string => $quote === "'" ? str_replace("\\'", "'", $title) : $title,
        $matches[1],
        $matches[2],
    );

    expect($titles)->toBe($expected)
        ->and($titles)->toHaveCount(DemoCatalogueSeeder::DEMO_MOVIE_COUNT);
});

it('cherche dans la page les libellés anglais réels de l\'interface', function (): void {
    $players = browserJourneySource('tests/Browser/support/players.ts');

    $labels = [
        'consentRefuse' => 'legal.consent.refuse',
        'nickname' => 'room.identity.nickname_label',
        'createRoom' => 'room.create.submit',
        'joinRoom' => 'room.join.submit',
        'launch' => 'room.lobby.launch',
        'answer' => 'game.answer.label',
        'answerSubmit' => 'game.answer.submit',
        'answerLocked' => 'game.answer.locked',
        'found' => 'game.round.found',
        'choices' => 'game.choices.label',
        'revealHeading' => 'game.reveal.heading',
    ];

    foreach ($labels as $name => $key) {
        $value = trans($key, [], 'en');

        expect($value)->not->toBe($key)
            ->and($players)->toContain("    {$name}: ".var_export($value, true).',');
    }

    // Les deux libellés à nombres, lus en expression rationnelle.
    $patterns = [
        'roundNumber' => ['game.round.number', ['number', 'total']],
        'frame' => ['game.frame.alt', ['index', 'total']],
    ];

    foreach ($patterns as $name => [$key, $placeholders]) {
        // Les marques `:nom` sont remplacées AVANT preg_quote, qui échappe `:`.
        $value = trans($key, [], 'en');

        foreach ($placeholders as $placeholder) {
            $value = str_replace(':'.$placeholder, 'NUMBERPLACEHOLDER', $value);
        }

        $value = str_replace('NUMBERPLACEHOLDER', '\d+', preg_quote($value, '/'));

        expect($players)->toContain("    {$name}: /^{$value}$/,");
    }
});

it('ne joue jamais les parcours en CI : hors de ci:check, hors des workflows, cible lue sur le poste', function (): void {
    $composer = json_decode(browserJourneySource('composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $package = json_decode(browserJourneySource('package.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($package['scripts']['test:e2e'] ?? null)->toBe('playwright test');

    // Aucun script de la chaîne de CI n'appelle les parcours.
    $ciScripts = [
        json_encode($composer['scripts'] ?? [], JSON_THROW_ON_ERROR),
        $package['scripts']['check'] ?? '',
        $package['scripts']['test'] ?? '',
        $package['scripts']['types:check'] ?? '',
    ];

    foreach ($ciScripts as $script) {
        expect($script)->not->toMatch('/playwright|test:e2e/');
    }

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $workflow) {
        $source = (string) file_get_contents($workflow);

        expect($source)->not->toMatch('/test:e2e|playwright (?:test|install)|npx playwright/');
    }

    // Version épinglée, en dépendance de développement seulement.
    expect($package['devDependencies']['@playwright/test'] ?? null)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and($package['dependencies'] ?? [])->not->toHaveKey('@playwright/test');

    // La cible vient du poste : jamais une adresse dans la configuration, ni
    // une variable E2E_ dans .env.example (que composer setup recopie).
    $config = browserJourneySource('playwright.config.ts');

    expect($config)->toContain('const target = resolveTarget(process.env);')
        ->and($config)->toContain('baseURL: target.baseURL,')
        ->and($config)->not->toMatch('#https?://#')
        ->and(browserJourneySource('.env.example'))->not->toContain('E2E_');

    // Les parcours sont dans le périmètre du contrôle de types.
    $tsconfig = browserJourneySource('tsconfig.json');

    expect($tsconfig)->toContain('"tests/Browser/**/*.ts"')
        ->and($tsconfig)->toContain('"playwright.config.ts"');

    foreach (['create-join-launch', 'answer-lock-reveal', 'reconnect'] as $journey) {
        expect(is_file(base_path("tests/Browser/{$journey}.spec.ts")))->toBeTrue("Parcours {$journey} absent");
    }
});
