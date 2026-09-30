<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Le hook de déploiement — spec 100 § 11.5, contrat C18-bis
|--------------------------------------------------------------------------
|
| Le hook ne se joue pas en test : il appelle le PHP de l'abonnement par son
| chemin absolu, qui n'existe que sur le VPS. Il se LIT : c'est un script
| versionné pour être relu et prouvé (précision (3) du § 11.8), et sa forme est
| volontairement plate — une étape par ligne `step <n> "$PHP" …` — pour que
| cette lecture soit exacte et non heuristique.
|
| Avant le moteur, le hook est livré sans drainage (D37 du 23/09) : ce test
| vérifie l'ordre des douze étapes du § 11.5 privé des étapes 3 et 12, que le
| lot L100-5 ajoute au hook ET à ce test en vidant `deployHookDrainSteps()`.
|
| Transition I-13, en deux déploiements (accord du porteur du 24/09) : L100-5
| livre le drapeau et les commandes, et tient les étapes 3 et 12 PRÊTES dans
| le hook, sur des lignes `# drain: step <n> …` inactives, pour que le premier
| déploiement ne s'arrête pas lui-même à l'étape 3. Le commit d'activation du
| second déploiement retire ce préfixe et vide `deployHookDrainSteps()`, sans
| autre changement de ce test.
|
*/

/**
 * Le tableau normatif du § 11.5, numéroté comme lui.
 *
 * @return array<int, string>
 */
function deployHookNormativeSteps(): array
{
    return [
        1 => 'composer install --no-dev --optimize-autoloader --no-interaction',
        2 => 'artisan optimize:clear --except=cache',
        3 => 'artisan deploy:guard',
        4 => 'artisan backup:snapshot --if-pending',
        5 => 'artisan migrate --force',
        6 => 'artisan db:seed --class=PlatformDataSeeder --force',
        7 => 'artisan catalog:reproject',
        8 => 'artisan optimize',
        9 => 'artisan lang:hash',
        10 => 'artisan queue:restart',
        11 => 'artisan reverb:restart',
        12 => 'artisan deploy:release',
    ];
}

/**
 * Les étapes de drainage, absentes tant que le moteur n'existe pas
 * (D37 du 23/09). L100-5 rend cette liste vide.
 *
 * @return list<int>
 */
function deployHookDrainSteps(): array
{
    return [3, 12];
}

/**
 * Les étapes du hook, actives et préparées, dans l'ordre du fichier :
 * numéro => [commande, préparée ?]. Une étape préparée est une ligne
 * `# drain: step <n> "$PHP" …`, qu'un seul geste active : retirer le préfixe
 * (transition I-13).
 *
 * @return list<array{int, string, bool}>
 */
function deployHookStepsWithPrepared(): array
{
    $steps = [];

    foreach (explode("\n", (string) file_get_contents(deployHookPath())) as $line) {
        $trimmed = trim($line);
        $prepared = preg_match('/^# drain: (step .+)$/', $trimmed, $match) === 1;
        $candidate = $prepared ? $match[1] : $trimmed;

        if (preg_match('/^step\s+(\d+)\s+"\$PHP"\s+(.+)$/', $candidate, $step) !== 1) {
            continue;
        }

        $command = (string) preg_replace('/^"\$COMPOSER_PHAR"\s+/', 'composer ', $step[2]);

        $steps[] = [(int) $step[1], (string) preg_replace('/\s+/', ' ', $command), $prepared];
    }

    return $steps;
}

/** Le seul PHP que le hook a le droit d'invoquer : celui de l'abonnement. */
function deployHookPhp(): string
{
    return '/opt/plesk/php/8.4/bin/php';
}

function deployHookPath(): string
{
    return base_path('ops/deploy/hook.sh');
}

/**
 * Les lignes de CODE du hook, dans l'ordre : lignes vides et commentaires
 * retirés, shebang compris (il est vérifié à part). Le hook n'écrit aucun
 * commentaire en fin de ligne, et ce test le vérifie : un `#` hors d'une ligne
 * de commentaire ferait échouer la lecture au lieu de la tromper.
 *
 * @return list<string>
 */
function deployHookCodeLines(): array
{
    $lines = [];

    foreach (explode("\n", (string) file_get_contents(deployHookPath())) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $lines[] = $trimmed;
    }

    return $lines;
}

/**
 * Les étapes du hook, dans l'ordre du fichier : numéro => commande, le PHP de
 * l'abonnement retiré et `"$COMPOSER_PHAR"` lu comme `composer`.
 *
 * @return list<array{int, string}>
 */
function deployHookSteps(): array
{
    $steps = [];

    foreach (deployHookCodeLines() as $line) {
        if (preg_match('/^step\s+(\d+)\s+"\$PHP"\s+(.+)$/', $line, $match) !== 1) {
            continue;
        }

        $command = (string) preg_replace('/^"\$COMPOSER_PHAR"\s+/', 'composer ', $match[2]);

        $steps[] = [(int) $match[1], (string) preg_replace('/\s+/', ' ', $command)];
    }

    return $steps;
}

it('enchaîne les étapes du hook dans l\'ordre du § 11.5, sous set -e', function (): void {
    $path = deployHookPath();

    expect(is_file($path))->toBeTrue('ops/deploy/hook.sh est absent');

    $source = (string) file_get_contents($path);

    // Fins de ligne Unix : un `\r` ferait lire `set -euo pipefail\r` à bash,
    // qui refuserait l'option — ou pire, ferait chercher `artisan\r`.
    expect($source)->not->toContain("\r")
        ->and($source)->toStartWith("#!/usr/bin/env bash\n");

    $code = deployHookCodeLines();

    // La première instruction arme l'arrêt au premier échec ; rien ne le
    // désarme ni ne rattrape un échec ensuite.
    expect($code[0] ?? null)->toBe('set -euo pipefail');

    // Puis l'umask, avant toute écriture : `optimize` recopie tous les secrets
    // du .env dans bootstrap/cache/config.php, qui ne doit jamais naître
    // lisible par un autre compte (répétition sur VM, 28/09 : 0664 sous l'umask
    // 0002 d'un utilisateur Ubuntu).
    expect($code[1] ?? null)->toBe('umask 027');

    foreach ($code as $line) {
        expect($line)->not->toMatch('/^set\s+\+/', "Le hook désarme set -e : {$line}")
            ->and(str_contains($line, '||'))->toBeFalse("Le hook rattrape un échec : {$line}")
            ->and($line)->not->toMatch('/(^|\s)trap\s/', "Le hook piège un signal ou une erreur : {$line}")
            ->and(str_contains($line, '#'))->toBeFalse("Commentaire en fin de ligne, illisible pour ce test : {$line}");
    }

    // Répertoire courant forcé sur la racine du déploiement AVANT la
    // première étape.
    $firstStep = null;
    $cd = null;

    foreach ($code as $index => $line) {
        if ($cd === null && str_starts_with($line, 'cd ')) {
            $cd = $index;
        }

        if ($firstStep === null && str_starts_with($line, 'step ')) {
            $firstStep = $index;
        }
    }

    expect($cd)->not->toBeNull('Le hook ne fixe pas son répertoire courant')
        ->and($firstStep)->not->toBeNull('Le hook n\'a aucune étape')
        ->and($cd)->toBeLessThan($firstStep);

    // Chaque étape est une commande simple : ni enchaînement, ni tube, ni
    // arrière-plan, qui soustrairaient son code de sortie à set -e.
    $stepLines = array_values(array_filter($code, static fn (string $line): bool => str_starts_with($line, 'step ')));

    foreach ($stepLines as $line) {
        expect($line)->toMatch('/^step \d+ "\$PHP" (?:"\$COMPOSER_PHAR"|artisan) [A-Za-z0-9:=,\- ]+$/', "Étape de forme inattendue : {$line}");
    }

    // L'ordre normatif, privé des étapes de drainage (D37 du 23/09).
    $expected = [];

    foreach (deployHookNormativeSteps() as $number => $command) {
        if (! in_array($number, deployHookDrainSteps(), true)) {
            $expected[] = [$number, $command];
        }
    }

    expect(deployHookSteps())->toBe($expected)
        ->and($stepLines)->toHaveCount(count($expected));

    // L'étape 2 ne vide jamais le cache applicatif, qui porte le drapeau de
    // drainage lu par l'étape 3 (§ 11.8, précision (1)).
    expect(deployHookSteps()[1][1] ?? null)->toBe('artisan optimize:clear --except=cache');

    // Chaque commande artisan appelée existe dans l'application : une étape
    // qui nommerait une commande absente ne se découvrirait qu'au déploiement.
    $commands = Artisan::all();

    foreach (deployHookSteps() as [$number, $command]) {
        if (! str_starts_with($command, 'artisan ')) {
            continue;
        }

        $name = explode(' ', $command)[1];

        expect(array_key_exists($name, $commands))->toBeTrue("Étape {$number} : la commande {$name} n'existe pas");
    }

    expect(class_exists('Database\\Seeders\\PlatformDataSeeder'))->toBeTrue();

    // Là où bash existe (la CI Linux, le VPS), le script est au moins
    // syntaxiquement lisible par lui. Sous Windows, `bash` peut désigner WSL
    // sans distribution : la lecture ci-dessus reste la preuve.
    if (PHP_OS_FAMILY !== 'Windows') {
        $result = Process::run(['bash', '-n', $path]);

        expect($result->successful())->toBeTrue($result->errorOutput());
    }
});

it('n\'appelle jamais cache:clear, systemctl ni un php du système', function (): void {
    $code = deployHookCodeLines();
    $joined = implode("\n", $code);

    // Ni le vidage du cache applicatif, ni un geste système.
    expect($joined)->not->toContain('cache:clear')
        ->and($joined)->not->toMatch('/\b(?:systemctl|service|sudo|su)\b/');

    // Le PHP de l'abonnement est fixé une fois, en lecture seule, par son chemin
    // absolu, AVANT la lecture de hook.env qui ne peut donc pas le redéfinir.
    $assignments = array_values(array_filter($code, static fn (string $line): bool => preg_match('/\bPHP=/', $line) === 1));

    expect($assignments)->toBe(['readonly PHP='.deployHookPhp()]);

    $readonly = array_search($assignments[0], $code, true);
    $source = null;

    foreach ($code as $index => $line) {
        if (str_starts_with($line, 'source ') || str_starts_with($line, '. ')) {
            $source ??= $index;
        }
    }

    expect($source)->not->toBeNull()
        ->and($readonly)->toBeLessThan($source);

    // Hors du chemin absolu de l'abonnement, le mot `php` n'apparaît nulle
    // part : ni `php artisan`, ni `/usr/bin/php`, ni `/usr/bin/env php`.
    $withoutSubscriptionPhp = str_replace(deployHookPhp(), '', $joined);

    expect($withoutSubscriptionPhp)->not->toContain('php');

    // artisan et composer ne passent QUE par ce PHP : `./artisan` ou
    // `composer` nus suivraient leur shebang, `#!/usr/bin/env php`, donc le
    // PHP du PATH.
    foreach ($code as $line) {
        if (preg_match('/\bartisan\b/', $line) === 1) {
            expect(str_contains($line, '"$PHP" artisan '))->toBeTrue("artisan hors du PHP de l'abonnement : {$line}");
        }

        if (preg_match('/\bcomposer\b|COMPOSER_PHAR"\s/', $line) === 1) {
            expect(str_contains($line, '"$PHP" "$COMPOSER_PHAR" '))->toBeTrue("composer hors du PHP de l'abonnement : {$line}");
        }
    }

    expect(implode("\n", $code))->not->toMatch('/^(?:\.\/)?(?:artisan|composer)\b/m');
});

it('tient prêtes à leur place, inactives, les étapes de drainage absentes du hook', function (): void {
    $steps = deployHookStepsWithPrepared();
    $normative = deployHookNormativeSteps();

    // Une ligne préparée pour chaque étape de drainage absente, aucune de plus
    // (liste vide une fois l'activation faite).
    $prepared = array_values(array_filter($steps, static fn (array $step): bool => $step[2]));

    expect(array_map(static fn (array $step): int => $step[0], $prepared))->toBe(deployHookDrainSteps());

    // Retirer le préfixe suffit : actives et préparées ensemble donnent, dans
    // l'ordre du fichier, le tableau normatif complet en douze étapes.
    $sequence = array_map(static fn (array $step): array => [$step[0], $step[1]], $steps);
    $expected = [];

    foreach ($normative as $number => $command) {
        $expected[] = [$number, $command];
    }

    expect($sequence)->toBe($expected);

    // Chaque ligne préparée a exactement la forme d'une étape active, et sa
    // commande existe déjà : le premier déploiement livre deploy:guard et
    // deploy:release avant que le hook ne les appelle.
    $source = (string) file_get_contents(deployHookPath());
    $commands = Artisan::all();

    foreach ($prepared as [$number, $command]) {
        $line = sprintf('step %d "$PHP" %s', $number, $command);

        expect(substr_count($source, "\n# drain: {$line}\n"))->toBe(1, "Ligne préparée absente ou ambiguë : {$line}")
            ->and($line)->toMatch('/^step \d+ "\$PHP" artisan [A-Za-z0-9:=,\- ]+$/');

        $name = explode(' ', $command)[1];

        expect(array_key_exists($name, $commands))->toBeTrue("Étape préparée {$number} : la commande {$name} n'existe pas");
    }
});
