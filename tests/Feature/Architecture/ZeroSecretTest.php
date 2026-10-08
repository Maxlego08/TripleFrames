<?php

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| CI à zéro secret — spec 100 § 7.1
|--------------------------------------------------------------------------
|
| La forge est hors UE (décision 17) : aucun secret de production, aucune
| clé TMDB, SMTP, OAuth ou de sauvegarde n'y passe, et le déploiement part
| du serveur qui TIRE avec une clé de lecture seule. « La suite doit échouer
| si une clé externe est requise, jamais la réclamer. »
|
| Quatre portes, chacune fermée ici par une lecture des fichiers du dépôt :
| les workflows ne lisent aucun secret ; aucun job ne garde d'identifiant
| après son `checkout`, sauf `artifacts` et `promote` ; `.env.example` ne livre aucune
| valeur d'identifiant ; `phpunit.xml` vide de force chaque identifiant
| externe, pour qu'une clé exportée dans le shell du développeur ne parte
| jamais avec la suite.
|
| `Http::preventStrayRequests()` (tests/Pest.php) reste la garde
| d'EXÉCUTION ; celles-ci sont les gardes de CONFIGURATION.
|
*/

/**
 * Chemins relatifs des fichiers de workflow, triés.
 *
 * @return list<string>
 */
function zeroSecretWorkflows(): array
{
    $files = array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('.github/workflows/*.yaml')) ?: [],
    );

    $relative = array_map(
        static fn (string $path): string => str_replace('\\', '/', Str::after($path, base_path().DIRECTORY_SEPARATOR)),
        $files,
    );

    sort($relative);

    return $relative;
}

/**
 * Affectations de `.env.example`, commentées comprises : une ligne
 * `# CLE=valeur` est un gabarit que le développeur décommente, et une vraie
 * valeur y serait autant publiée qu'active.
 *
 * Chaque occurrence compte : une clé écrite deux fois (commentée, puis
 * active) est vérifiée deux fois.
 *
 * @return list<array{name: string, value: string, line: int}> valeur sans guillemets ni commentaire de fin
 */
function zeroSecretEnvExample(): array
{
    $values = [];

    foreach (preg_split('/\R/', (string) file_get_contents(base_path('.env.example'))) ?: [] as $index => $line) {
        if (preg_match('/^\s*#?\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=(.*)$/', $line, $match) !== 1) {
            continue;
        }

        $value = trim($match[2]);

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $closing = strpos($value, $value[0], 1);
            $value = $closing === false ? substr($value, 1) : substr($value, 1, $closing - 1);
        } else {
            // Valeur nue : ` #` ouvre un commentaire de fin, comme pour phpdotenv.
            $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
        }

        $values[] = ['name' => $match[1], 'value' => $value, 'line' => $index + 1];
    }

    return $values;
}

/**
 * Noms des clés sensibles de `.env.example`, sans doublon, dans l'ordre du
 * fichier.
 *
 * @return list<string>
 */
function zeroSecretSensitiveNames(): array
{
    $names = array_map(static fn (array $entry): string => $entry['name'], zeroSecretEnvExample());

    return array_values(array_unique(array_filter($names, zeroSecretIsSensitive(...))));
}

/**
 * Clés sensibles : nom terminé par KEY, SECRET, TOKEN ou PASSWORD.
 */
function zeroSecretIsSensitive(string $name): bool
{
    return preg_match('/(?:KEY|SECRET|TOKEN|PASSWORD)$/', $name) === 1;
}

/**
 * Lectures de secrets dans le texte d'un workflow, hors
 * `secrets.GITHUB_TOKEN`, triées par ligne.
 *
 * Deux balayages :
 *
 * 1. dans toute expression `${{ … }}`, CHAQUE occurrence du contexte
 *    `secrets` qui n'est pas `secrets.GITHUB_TOKEN` : `secrets.X`,
 *    `secrets['X']`, mais aussi `toJSON(secrets)`, qui déverse tous les
 *    secrets sans jamais écrire `secrets.`. Les littéraux de chaîne sont
 *    blanchis d'abord : `'secrets'` n'est pas une lecture ;
 * 2. hors expression : `secrets: inherit` (appel d'un workflow réutilisable,
 *    qui lui transmet TOUS les secrets) et `secrets.X` ou `secrets[` dans une
 *    condition `if:` écrite sans `${{ }}`.
 *
 * @return list<array{line: int, match: string}>
 */
function zeroSecretReads(string $workflow): array
{
    $reads = [];
    $lineAt = static fn (int $offset): int => substr_count($workflow, "\n", 0, $offset) + 1;
    $blank = static fn (array $match): string => (string) preg_replace('/[^\n]/', ' ', $match[0]);

    preg_match_all('/\$\{\{.*?\}\}/s', $workflow, $expressions, PREG_OFFSET_CAPTURE);

    foreach ($expressions[0] as [$expression, $offset]) {
        $code = (string) preg_replace_callback("/'(?:[^']|'')*'/", $blank, $expression);

        preg_match_all('/\bsecrets\b(?!\s*\.\s*GITHUB_TOKEN\b)/i', $code, $hits, PREG_OFFSET_CAPTURE);

        foreach ($hits[0] as [, $position]) {
            $reads[] = [
                'line' => $lineAt($offset + $position),
                'match' => (string) preg_replace('/\s+/', ' ', $expression),
            ];
        }
    }

    $outside = (string) preg_replace_callback('/\$\{\{.*?\}\}/s', $blank, $workflow);

    preg_match_all(
        '/\bsecrets\s*(?:\.\s*([A-Za-z_][A-Za-z0-9_-]*)|\[|:\s*inherit\b)/i',
        $outside,
        $hits,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );

    foreach ($hits as $hit) {
        if (strcasecmp($hit[1][0] ?? '', 'GITHUB_TOKEN') === 0) {
            continue;
        }

        $reads[] = ['line' => $lineAt($hit[0][1]), 'match' => trim($hit[0][0])];
    }

    usort($reads, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

    return $reads;
}

it('ne référence aucun secret dans aucun workflow, hors jeton automatique', function () {
    $workflows = zeroSecretWorkflows();
    $violations = [];

    foreach ($workflows as $workflow) {
        foreach (zeroSecretReads((string) file_get_contents(base_path($workflow))) as $read) {
            $violations[] = sprintf('%s:%d : « %s »', $workflow, $read['line'], $read['match']);
        }
    }

    // Le balayage voit les workflows réels : sinon il serait vert à vide.
    expect($workflows)->toContain('.github/workflows/tests.yml', '.github/workflows/tests-mysql.yml')
        ->and($violations)->toBe([]);

    // Et le détecteur n'est pas muet : chaque forme de lecture est vue, le
    // jeton automatique et un littéral de chaîne ne le sont pas.
    $count = static fn (string $yaml): int => count(zeroSecretReads($yaml));

    expect($count('token: ${{ secrets.TMDB_API_KEY }}'))->toBe(1)
        ->and($count('token: ${{ secrets["TMDB_API_KEY"] }}'))->toBe(1)
        ->and($count('run: echo ${{ toJSON(secrets) }}'))->toBe(1)
        ->and($count('env: ${{ fromJSON(toJSON(secrets)).TMDB }}'))->toBe(1)
        ->and($count("key: \${{\n  secrets.MAIL_PASSWORD\n}}"))->toBe(1)
        ->and($count("    uses: ./.github/workflows/deploy.yml\n    secrets: inherit"))->toBe(1)
        ->and($count("    if: secrets.TMDB_API_KEY != ''"))->toBe(1)
        ->and($count('token: ${{ secrets.GITHUB_TOKEN }}'))->toBe(0)
        ->and($count("if: \${{ contains(github.event.head_commit.message, 'secrets') }}"))->toBe(0)
        ->and(zeroSecretReads("a: 1\nb: \${{ toJSON(secrets) }}")[0]['line'] ?? null)->toBe(2);
});

/**
 * Les seuls jobs qui gardent l'identifiant de leur `checkout` : [workflow, job].
 *
 * @return list<array{string, string}>
 */
function zeroSecretWritingJobs(): array
{
    return [
        ['.github/workflows/tests.yml', 'artifacts'],
        ['.github/workflows/promote.yml', 'promote'],
    ];
}

it('ne persiste aucun identifiant hors des jobs artifacts et promote', function () {
    $checked = 0;
    $violations = [];

    foreach (zeroSecretWorkflows() as $workflow) {
        $parsed = Yaml::parseFile(base_path($workflow));

        expect($parsed)->toBeArray();

        /** @var array<string, mixed> $jobs */
        $jobs = is_array($parsed['jobs'] ?? null) ? $parsed['jobs'] : [];

        foreach ($jobs as $job => $definition) {
            $steps = is_array($definition) && is_array($definition['steps'] ?? null) ? $definition['steps'] : [];

            foreach ($steps as $position => $step) {
                $uses = is_array($step) && is_string($step['uses'] ?? null) ? $step['uses'] : '';

                if (! Str::startsWith(strtolower($uses), 'actions/checkout@')) {
                    continue;
                }

                // Deux jobs poussent une branche d'artefacts avec le jeton
                // automatique, et eux seuls gardent l'identifiant : `artifacts`
                // (`deploy-preprod`, tests.yml) et `promote` (`deploy`,
                // promote.yml, spec 100 § 18).
                if (in_array([$workflow, $job], zeroSecretWritingJobs(), true)) {
                    continue;
                }

                $checked++;

                $persist = is_array($step['with'] ?? null) ? ($step['with']['persist-credentials'] ?? null) : null;

                if ($persist !== false && $persist !== 'false') {
                    $violations[] = sprintf(
                        '%s › jobs.%s.steps[%d] : actions/checkout sans « persist-credentials: false »',
                        $workflow,
                        $job,
                        $position,
                    );
                }
            }
        }
    }

    expect($violations)->toBe([])
        // Au moins le checkout des jobs `ci` et `mysql-redis` est vérifié.
        ->and($checked)->toBeGreaterThanOrEqual(2);
});

it('livre vide chaque clé sensible de .env.example', function () {
    $violations = [];

    foreach (zeroSecretEnvExample() as $entry) {
        // Identifiant PUBLIC par conception (n° 79) : la clé d'application
        // Reverb est servie à tout client dans la prop `realtime`, et livrée
        // non vide pour que le temps réel du poste fonctionne (§ 10.10). Son
        // secret, `REVERB_APP_SECRET`, reste vide et naît sur la machine (§ 2.4).
        if (! zeroSecretIsSensitive($entry['name']) || $entry['name'] === 'REVERB_APP_KEY') {
            continue;
        }

        if (! in_array(strtolower($entry['value']), ['', 'null', '(null)'], true)) {
            $violations[] = ".env.example:{$entry['line']} : {$entry['name']} n'est pas vide";
        }
    }

    expect(zeroSecretSensitiveNames())->toContain('APP_KEY', 'TMDB_API_KEY', 'TMDB_API_READ_ACCESS_TOKEN')
        ->and($violations)->toBe([]);
});

it('force à vide chaque identifiant externe dans phpunit.xml', function () {
    // Liste close (spec 100 § 7.1) : `APP_KEY` est posée par `key:generate`,
    // les deux mots de passe de base et de Redis sont surchargés par le job
    // `mysql-redis`, et `REVERB_APP_KEY` est un identifiant public.
    $notExternal = ['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'REVERB_APP_KEY'];

    $external = array_values(array_diff(zeroSecretSensitiveNames(), $notExternal));

    $phpunit = simplexml_load_file(base_path('phpunit.xml'));

    expect($phpunit)->not->toBeFalse();

    /** @var array<string, array{value: string, force: string}> $env */
    $env = [];
    /** @var array<string, string> $server */
    $server = [];

    foreach ($phpunit->php->env ?? [] as $node) {
        $env[(string) $node['name']] = ['value' => (string) $node['value'], 'force' => (string) $node['force']];
    }

    foreach ($phpunit->php->server ?? [] as $node) {
        $server[(string) $node['name']] = (string) $node['value'];
    }

    $violations = [];

    foreach ($external as $name) {
        // `force="true"` écrase getenv() et $_ENV ; le jumeau `<server>`
        // écrase $_SERVER, que phpdotenv — donc env() — lit EN PREMIER. Sans
        // la paire, une clé exportée dans le shell l'emporte (écart E6-7).
        if (($env[$name] ?? null) !== ['value' => '', 'force' => 'true']) {
            $violations[] = "{$name} : <env name=\"{$name}\" value=\"\" force=\"true\"/> absent de phpunit.xml";
        }

        if (($server[$name] ?? null) !== '') {
            $violations[] = "{$name} : <server name=\"{$name}\" value=\"\"/> absent de phpunit.xml";
        }
    }

    // Réciproque de § 2.3 : jamais `force` sur ce que le job `mysql-redis`
    // doit pouvoir surcharger par son environnement.
    foreach ($env as $name => $attributes) {
        $overridable = in_array($name, ['CACHE_STORE', 'QUEUE_CONNECTION'], true)
            || Str::startsWith($name, ['DB_', 'REDIS_']);

        if ($overridable && $attributes['force'] === 'true') {
            $violations[] = "{$name} : force=\"true\" interdit, le job mysql-redis doit pouvoir le surcharger";
        }
    }

    expect($external)->toContain('TMDB_API_KEY', 'TMDB_API_READ_ACCESS_TOKEN')
        ->and($violations)->toBe([]);
});
