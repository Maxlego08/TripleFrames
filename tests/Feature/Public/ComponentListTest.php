<?php

/*
|--------------------------------------------------------------------------
| Liste close des composants de présentation — spec 90 § 9.2, C16 § 2.9
|--------------------------------------------------------------------------
|
| La v1 n'a pas de direction artistique, elle a une structure (principe 13,
| règle 5) : les écrans composent des primitives shadcn BRUTES, installées
| par la CLI en style new-york et jamais éditées ensuite. La liste est close
| et datée par jalon : ce qui n'y figure pas n'est pas installé, et une
| primitive du jalon 2 (`popover`, `command`) n'arrive pas en avance.
|
| `form` est refusé pour de bon : c'est une enveloppe de react-hook-form, qui
| doublerait `<Form>` d'Inertia (n° 42, CLAUDE.md §5, principe 11).
|
*/

/**
 * Primitives présentes avant le jalon 1 (spec 90 § 9.2, « Installés »).
 *
 * @return list<string>
 */
function componentListInstalled(): array
{
    return [
        'alert', 'avatar', 'badge', 'breadcrumb', 'button', 'card', 'checkbox',
        'collapsible', 'dialog', 'dropdown-menu', 'icon', 'input-otp', 'input',
        'label', 'navigation-menu', 'placeholder-pattern', 'select', 'separator',
        'sheet', 'sidebar', 'skeleton', 'sonner', 'spinner', 'table', 'tabs',
        'textarea', 'toggle-group', 'toggle', 'tooltip',
    ];
}

/**
 * Primitives installées au jalon 1 par le lot L90-1 (spec 90 § 9.2).
 *
 * @return list<string>
 */
function componentListFirstMilestone(): array
{
    return ['slider', 'switch', 'progress', 'scroll-area', 'radio-group'];
}

/**
 * Primitives du back-office seul (spec 90 § 9.2, amendé le 08/10) : `chart`
 * (recharts), pour les écrans « Audience » et « Statistiques de jeu ».
 *
 * @return list<string>
 */
function componentListBackOffice(): array
{
    return ['chart'];
}

/**
 * Bibliothèques d'interface ou d'animation courantes, refusées par la liste
 * close (« toute autre bibliothèque d'interface ou d'animation »). Relevé
 * non exhaustif des concurrents directs de shadcn et de Radix : il attrape
 * l'ajout par réflexe, la relecture du lot fait le reste.
 *
 * @return list<string>
 */
function componentListRefusedLibraries(): array
{
    return [
        'framer-motion', 'motion', 'react-spring', '@react-spring/web', 'gsap',
        'animejs', 'react-transition-group', 'auto-animate', '@formkit/auto-animate',
        '@mui/material', '@mui/joy', '@chakra-ui/react', 'antd', '@mantine/core',
        '@headlessui/react', 'react-bootstrap', '@heroui/react', '@nextui-org/react',
        'daisyui', 'flowbite-react', 'react-aria-components', '@ark-ui/react',
        'primereact', 'semantic-ui-react', '@base-ui-components/react', 'vaul',
    ];
}

/**
 * Toutes les dépendances déclarées par `package.json`, toutes sections.
 *
 * @return list<string>
 */
function componentListDeclaredPackages(): array
{
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    $names = [];

    foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
        $names = [...$names, ...array_keys((array) ($manifest[$section] ?? []))];
    }

    return array_values(array_map('strval', $names));
}

it("n'installe que la liste close des composants de présentation", function () {
    $directory = base_path('resources/js/components/ui');

    $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));

    // Un sous-répertoire serait une primitive déguisée, hors de toute liste.
    foreach ($entries as $entry) {
        expect(is_file($directory.DIRECTORY_SEPARATOR.$entry))
            ->toBeTrue("components/ui/{$entry} n'est pas un fichier de primitive");
        expect($entry)->toEndWith('.tsx', "components/ui/{$entry} n'est pas une primitive shadcn");
    }

    $installed = array_map(static fn (string $entry): string => basename($entry, '.tsx'), $entries);
    sort($installed);

    $expected = [...componentListInstalled(), ...componentListFirstMilestone(), ...componentListBackOffice()];
    sort($expected);

    expect($installed)->toBe($expected);

    // Installées par la CLI, dans le style et la base du dépôt.
    /** @var array<string, mixed> $config */
    $config = json_decode((string) file_get_contents(base_path('components.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($config['style'] ?? null)->toBe('new-york')
        ->and($config['iconLibrary'] ?? null)->toBe('lucide')
        ->and($config['tailwind']['baseColor'] ?? null)->toBe('neutral');

    expect(array_values(array_intersect(componentListDeclaredPackages(), componentListRefusedLibraries())))
        ->toBe([], "package.json déclare une bibliothèque d'interface ou d'animation hors de la liste close");
});

it("n'installe jamais l'enveloppe react-hook-form", function () {
    expect(base_path('resources/js/components/ui/form.tsx'))->not->toBeFile();

    $wrapper = ['react-hook-form', '@hookform/resolvers'];

    expect(array_values(array_intersect(componentListDeclaredPackages(), $wrapper)))->toBe([]);

    /** @var array<string, mixed> $lock */
    $lock = json_decode((string) file_get_contents(base_path('package-lock.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($wrapper as $package) {
        expect(array_key_exists("node_modules/{$package}", (array) ($lock['packages'] ?? [])))
            ->toBeFalse("package-lock.json installe {$package}");
    }

    // Aucun import, même transitif par un fichier de l'application.
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('resources/js'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        expect(preg_match('/[\'"](?:react-hook-form|@hookform\/[^\'"]+)[\'"]/', $source))
            ->toBe(0, "{$file->getPathname()} importe react-hook-form");
    }
});

it("ne sert les graphiques qu'au back-office", function () {
    $offenders = [];
    $root = base_path('resources/js');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', substr((string) $file, strlen($root) + 1));

        if (! preg_match('/\.tsx?$/', $path) || str_starts_with($path, 'components/ui/')
            || str_starts_with($path, 'components/admin/') || str_starts_with($path, 'pages/admin/')) {
            continue;
        }

        $source = (string) file_get_contents((string) $file);

        if (str_contains($source, "from 'recharts'") || str_contains($source, "from '@/components/ui/chart'")) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});
