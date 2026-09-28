<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Gabarits d'exploitation du VPS — spec 100 § 10 (lot L100-9, préparation)
|--------------------------------------------------------------------------
|
| Les gabarits de `ops/redis/`, `ops/systemd/` et `ops/nginx/` sont recopiés
| une fois par root, puis ajustés au relevé du VPS. Ils ne se jouent pas en
| test (ni systemd, ni Redis, ni le nginx de Plesk sur le poste ou en CI) : ils
| se LISENT, comme le hook (DeployHookTest), pour que chaque ajustement garde
| les règles du § 10 — boucle locale, non-privilège, plafonds ordonnés, aucune
| éviction, aucun cache de page.
|
| Un piège propre à ces formats : systemd, un fichier d'environnement et Redis
| ne lisent un commentaire qu'en DÉBUT de ligne. Un « # … » en fin de ligne
| entre dans la valeur : systemd ignore alors le réglage avec un simple
| avertissement (un plafond disparaît en silence) et Redis refuse le fichier.
|
| Un paramètre `__TF_…__` n'est pas encore remplacé : les règles de valeur
| s'appliquent dès qu'il l'est.
|
*/

/**
 * Les gabarits lus par un analyseur système, chemins relatifs à `ops/`.
 *
 * @return list<string>
 */
function opsTemplateFiles(): array
{
    return [
        'redis/tripleframes-redis.conf',
        'systemd/tripleframes-redis.service',
        'systemd/tripleframes-worker@.service',
        'systemd/tripleframes-worker@game.service.d/limits.conf',
        'systemd/tripleframes-worker@default.service.d/limits.conf',
        'systemd/tripleframes-reverb.service',
        'systemd/worker-game.env',
        'systemd/worker-default.env',
        'nginx/additional-directives.conf',
    ];
}

/**
 * Les deux instances du worker (§ 10.4) : jamais Horizon, jamais une
 * troisième file.
 *
 * @return list<string>
 */
function opsTemplateWorkerInstances(): array
{
    return ['game', 'default'];
}

/** Le seul PHP qu'une unité a le droit d'invoquer : celui de l'abonnement. */
function opsTemplatePhp(): string
{
    return '/opt/plesk/php/8.4/bin/php';
}

function opsTemplateSource(string $relative): string
{
    $path = base_path('ops/'.$relative);

    expect(is_file($path))->toBeTrue("ops/{$relative} est absent");

    return (string) file_get_contents($path);
}

/**
 * Les lignes utiles d'un gabarit : ni vides, ni commentaires.
 *
 * @return list<string>
 */
function opsTemplateLines(string $relative): array
{
    $lines = [];

    foreach (explode("\n", opsTemplateSource($relative)) as $line) {
        $trimmed = trim($line);

        if ($trimmed !== '' && ! str_starts_with($trimmed, '#')) {
            $lines[] = $trimmed;
        }
    }

    return $lines;
}

/**
 * Les affectations `Clé=valeur` d'une unité systemd ou d'un fichier
 * d'environnement, toutes occurrences gardées, en-têtes de section exclus.
 *
 * @return array<string, list<string>>
 */
function opsTemplateAssignments(string $relative): array
{
    $assignments = [];

    foreach (opsTemplateLines($relative) as $line) {
        if (str_starts_with($line, '[')) {
            continue;
        }

        expect(str_contains($line, '='))->toBeTrue("ops/{$relative} : ligne sans affectation : {$line}");

        $assignments[Str::before($line, '=')][] = Str::after($line, '=');
    }

    return $assignments;
}

/** La valeur unique d'une clé d'unité ou d'environnement. */
function opsTemplateValue(string $relative, string $key): string
{
    $values = opsTemplateAssignments($relative)[$key] ?? [];

    expect($values)->toHaveCount(1, "ops/{$relative} : {$key} doit être déclarée une fois");

    return $values[0];
}

/**
 * Les directives de la configuration Redis : nom en minuscules → arguments.
 *
 * @return array<string, list<string>>
 */
function opsTemplateRedisDirectives(): array
{
    $directives = [];

    foreach (opsTemplateLines('redis/tripleframes-redis.conf') as $line) {
        $parts = preg_split('/\s+/', $line, 2) ?: [];
        $directives[strtolower($parts[0])][] = $parts[1] ?? '';
    }

    return $directives;
}

/**
 * Une taille en octets : suffixes de systemd (K, M, G, T en base 1024) et de
 * Redis (kb, mb, gb en base 1024 ; k, m, g en base 1000).
 */
function opsTemplateBytes(string $size): int
{
    expect(preg_match('/^(\d+)([A-Za-z]*)$/', $size, $match))->toBe(1, "Taille illisible : {$size}");

    $factors = [
        '' => 1, 'k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4,
        'kb' => 1024, 'mb' => 1024 ** 2, 'gb' => 1024 ** 3,
    ];
    $unit = strtolower($match[2]);

    // Redis lit `256m` en base 1000, systemd `256M` en base 1024 : seule la
    // configuration Redis emploie des minuscules.
    $decimal = ['k' => 1000, 'm' => 1000 ** 2, 'g' => 1000 ** 3];
    $factor = ctype_lower($match[2]) && isset($decimal[$unit]) ? $decimal[$unit] : ($factors[$unit] ?? null);

    expect($factor)->not->toBeNull("Unité de taille inconnue : {$size}");

    return (int) $match[1] * (int) $factor;
}

/**
 * Les options `--nom=valeur` d'une ligne d'arguments ; une option sans valeur
 * vaut chaîne vide.
 *
 * @return array<string, string>
 */
function opsTemplateOptions(string $arguments): array
{
    $options = [];

    foreach (preg_split('/\s+/', trim($arguments)) ?: [] as $token) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $token, $match) === 1) {
            $options[$match[1]] = $match[2] ?? '';
        }
    }

    return $options;
}

/** Vrai si la valeur est encore un paramètre à remplacer. */
function opsTemplateIsParameter(string $value): bool
{
    return preg_match('/^__TF_[A-Z_]+__$/', $value) === 1;
}

it('livre les gabarits d\'exploitation en fins de ligne Unix, sans paramètre dans un commentaire ni commentaire en fin de ligne là où l\'analyseur ne le lit pas', function (): void {
    foreach (opsTemplateFiles() as $relative) {
        $source = opsTemplateSource($relative);

        expect($source)->not->toContain("\r", "ops/{$relative} : fin de ligne Windows")
            ->and($source)->toEndWith("\n")
            ->and(opsTemplateLines($relative))->not->toBeEmpty();

        // Un paramètre ou `<DOMAINE>` ne se lit que sur une ligne de valeur :
        // en commentaire, il ne serait jamais remplacé et le contrôle final de
        // la mise en service (grep sur le serveur) ne reviendrait jamais vide.
        foreach (explode("\n", $source) as $line) {
            if (str_starts_with(trim($line), '#')) {
                expect(preg_match('/__TF_|<DOMAINE>/', $line))->toBe(0, "ops/{$relative} : paramètre à remplacer dans un commentaire : {$line}");
            }
        }

        // nginx lit les commentaires de fin de ligne ; systemd, un fichier
        // d'environnement et Redis, non.
        if (str_starts_with($relative, 'nginx/')) {
            continue;
        }

        foreach (opsTemplateLines($relative) as $line) {
            expect(str_contains($line, '#'))->toBeFalse("ops/{$relative} : commentaire en fin de ligne, lu comme une valeur : {$line}");
        }
    }
});

it('n\'expose Redis et Reverb qu\'en boucle locale et ne publie que le chemin WebSocket /app/', function (): void {
    $redis = opsTemplateRedisDirectives();

    expect($redis['bind'] ?? [])->toBe(['127.0.0.1'])
        ->and($redis['protected-mode'] ?? [])->toBe(['yes'])
        ->and($redis['port'] ?? [])->toHaveCount(1);

    $redisPort = $redis['port'][0];

    if (! opsTemplateIsParameter($redisPort)) {
        expect(ctype_digit($redisPort))->toBeTrue("Port Redis illisible : {$redisPort}")
            ->and((int) $redisPort)->not->toBe(6379, 'Le Redis dédié ne prend jamais le port d\'un Redis voisin')
            ->and((int) $redisPort)->toBeGreaterThan(1023)->toBeLessThan(65536);
    }

    // nginx : un seul proxy, vers la boucle locale, sous le seul préfixe /app/.
    $nginx = opsTemplateLines('nginx/additional-directives.conf');
    $proxies = array_values(array_filter($nginx, static fn (string $line): bool => str_starts_with($line, 'proxy_pass ')));
    $locations = array_values(array_map(
        static fn (string $line): string => trim(Str::between($line, 'location ', '{')),
        array_filter($nginx, static fn (string $line): bool => str_starts_with($line, 'location ')),
    ));

    expect($proxies)->toHaveCount(1)
        ->and($proxies[0])->toMatch('#^proxy_pass http://127\.0\.0\.1:(__TF_REVERB_PORT__|\d+);$#')
        ->and($locations)->toContain('^~ /app/')
        ->and(array_diff($locations, ['/', '^~ /app/']))->toBe([], 'Location inattendue : seul /app/ est publié vers Reverb');

    $joined = implode("\n", $nginx);

    // L'API HTTP /apps/ reste en boucle locale ; aucun cache de page (§ 10.8).
    expect($joined)->not->toContain('/apps')
        ->and($joined)->not->toMatch('/\b(?:proxy|fastcgi|uwsgi|scgi)_cache\b/');

    $reverbPort = Str::between($proxies[0], '127.0.0.1:', ';');

    if (! opsTemplateIsParameter($reverbPort)) {
        expect((int) $reverbPort)->not->toBe(6379)
            ->and($reverbPort)->not->toBe($redisPort, 'Redis et Reverb écoutent sur deux ports distincts');
    }

    // Reverb lit hôte et port dans la configuration de l'application, jamais
    // sur sa ligne de commande : une seule source.
    expect(opsTemplateValue('systemd/tripleframes-reverb.service', 'ExecStart'))
        ->toBe(opsTemplatePhp().' artisan reverb:start');
});

it('désactive sur Redis les commandes qui videraient le drapeau de drainage, sans jamais évincer ni perdre un job', function (): void {
    $redis = opsTemplateRedisDirectives();

    foreach (['FLUSHALL', 'FLUSHDB', 'KEYS', 'CONFIG', 'DEBUG'] as $command) {
        expect($redis['rename-command'] ?? [])->toContain("{$command} \"\"");
    }

    expect($redis['maxmemory-policy'] ?? [])->toBe(['noeviction'])
        ->and($redis['appendonly'] ?? [])->toBe(['yes'])
        ->and($redis['appendfsync'] ?? [])->toBe(['everysec'])
        ->and($redis['save'] ?? [])->toBe(['""'])
        ->and($redis['daemonize'] ?? [])->toBe(['no'])
        ->and($redis['supervised'] ?? [])->toBe(['systemd'])
        ->and($redis['requirepass'] ?? [])->toHaveCount(1)
        ->and($redis['requirepass'][0])->not->toBe('')
        ->and($redis['maxmemory'] ?? [])->toHaveCount(1);

    $unit = 'systemd/tripleframes-redis.service';

    // Le plafond de Redis joue avant celui du cgroup.
    expect(opsTemplateBytes(opsTemplateValue($unit, 'MemoryMax')))
        ->toBeGreaterThan(opsTemplateBytes($redis['maxmemory'][0]));

    // Type=notify suppose « supervised systemd » ; le répertoire de données
    // est celui que systemd crée pour l'unité.
    expect(opsTemplateValue($unit, 'Type'))->toBe('notify')
        ->and(opsTemplateValue($unit, 'ExecStart'))->toEndWith(' /etc/tripleframes/tripleframes-redis.conf')
        ->and($redis['dir'] ?? [])->toBe(['/var/lib/'.opsTemplateValue($unit, 'StateDirectory')]);
});

it('lance chaque processus sous un utilisateur non privilégié, les processus PHP par le PHP de l\'abonnement', function (): void {
    $units = [
        'systemd/tripleframes-redis.service',
        'systemd/tripleframes-worker@.service',
        'systemd/tripleframes-reverb.service',
    ];

    foreach ($units as $unit) {
        $user = opsTemplateValue($unit, 'User');

        expect($user)->not->toBe('')
            ->and($user)->not->toBe('root', "ops/{$unit} : aucun processus du jeu ne tourne en root")
            ->and(opsTemplateValue($unit, 'Group'))->not->toBe('root')
            ->and(opsTemplateValue($unit, 'Restart'))->toBe('always')
            ->and(opsTemplateValue($unit, 'NoNewPrivileges'))->toBe('true')
            ->and(opsTemplateValue($unit, 'StartLimitIntervalSec'))->toBe('0');
    }

    expect(opsTemplateValue('systemd/tripleframes-redis.service', 'User'))->toBe('tfredis');

    // Les processus PHP : même utilisateur (celui de l'abonnement), même
    // répertoire de déploiement, PHP de l'abonnement par chemin absolu.
    $php = ['systemd/tripleframes-worker@.service', 'systemd/tripleframes-reverb.service'];

    foreach ($php as $unit) {
        expect(opsTemplateValue($unit, 'ExecStart'))->toStartWith(opsTemplatePhp().' ')
            ->and(opsTemplateValue($unit, 'User'))->toBe(opsTemplateValue($php[0], 'User'))
            ->and(opsTemplateValue($unit, 'Group'))->toBe(opsTemplateValue($php[0], 'Group'))
            ->and(opsTemplateValue($unit, 'WorkingDirectory'))->toBe(opsTemplateValue($php[0], 'WorkingDirectory'))
            // Journaux et fichiers écrits illisibles des autres comptes (0022
            // par défaut sous systemd : journaux en 0644).
            ->and(opsTemplateValue($unit, 'UMask'))->toBe('0027');
    }

    expect(opsTemplateValue('systemd/tripleframes-worker@.service', 'ExecStart'))
        ->toBe(opsTemplatePhp().' $PHP_ARGS artisan queue:work redis --queue=%i $WORKER_ARGS')
        ->and(opsTemplateValue('systemd/tripleframes-worker@.service', 'EnvironmentFile'))
        ->toBe('/etc/tripleframes/worker-%i.env');
});

it('borne chaque worker sous son plafond de cgroup, dans l\'ordre --memory < memory_limit < MemoryMax', function (): void {
    // Exactement les deux instances du § 10.4, chacune avec ses arguments et
    // ses plafonds.
    $envFiles = array_map(
        static fn (string $path): string => Str::between(basename($path), 'worker-', '.env'),
        glob(base_path('ops/systemd/worker-*.env')) ?: [],
    );
    $dropIns = array_map(
        static fn (string $path): string => Str::between(basename($path), 'tripleframes-worker@', '.service.d'),
        glob(base_path('ops/systemd/tripleframes-worker@*.service.d'), GLOB_ONLYDIR) ?: [],
    );

    sort($envFiles);
    sort($dropIns);

    $instances = opsTemplateWorkerInstances();
    sort($instances);

    expect($envFiles)->toBe($instances)
        ->and($dropIns)->toBe($instances);

    $retryAfter = (int) config('queue.connections.redis.retry_after');

    foreach (opsTemplateWorkerInstances() as $instance) {
        $env = "systemd/worker-{$instance}.env";
        $limits = "systemd/tripleframes-worker@{$instance}.service.d/limits.conf";

        expect(array_keys(opsTemplateAssignments($env)))->toBe(['PHP_ARGS', 'WORKER_ARGS'])
            ->and(array_keys(opsTemplateAssignments($limits)))->toBe(['MemoryMax', 'CPUWeight']);

        $options = opsTemplateOptions(opsTemplateValue($env, 'WORKER_ARGS'));

        expect($options)->toHaveKeys(['tries', 'sleep', 'timeout', 'max-time', 'memory'])
            ->and(ctype_digit($options['memory']))->toBeTrue()
            ->and(ctype_digit($options['timeout']))->toBeTrue()
            ->and((int) $options['timeout'])->toBeLessThan($retryAfter, "Worker {$instance} : un job encore en cours serait délivré une seconde fois (§ 10.3)");

        $memory = (int) $options['memory'] * 1024 ** 2;
        $memoryMax = opsTemplateBytes(opsTemplateValue($limits, 'MemoryMax'));
        $phpArgs = opsTemplateValue($env, 'PHP_ARGS');

        if ($phpArgs !== '') {
            expect(preg_match('/^-d memory_limit=(\d+[KMG])$/', $phpArgs, $match))->toBe(1, "PHP_ARGS inattendu : {$phpArgs}");

            $memoryLimit = opsTemplateBytes($match[1]);

            expect($memory)->toBeLessThan($memoryLimit)
                ->and($memoryLimit)->toBeLessThan($memoryMax);
        }

        expect($memory)->toBeLessThan($memoryMax);

        // Sous contention, les pools PHP-FPM (poids 100 par défaut) passent
        // avant les workers (§ 10.4).
        expect((int) opsTemplateValue($limits, 'CPUWeight'))->toBeGreaterThan(0)->toBeLessThan(100);
    }

    // File game : cadencement (contrat C7 § 5) — un seul essai, un sondage à
    // vide d'au plus une seconde.
    $game = opsTemplateOptions(opsTemplateValue('systemd/worker-game.env', 'WORKER_ARGS'));

    expect($game['tries'])->toBe('1')
        ->and(is_numeric($game['sleep']))->toBeTrue()
        ->and((float) $game['sleep'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0);

    // Chaque unité déclare ses plafonds, les workers par leurs drop-ins.
    foreach (['systemd/tripleframes-redis.service', 'systemd/tripleframes-reverb.service'] as $unit) {
        expect(opsTemplateBytes(opsTemplateValue($unit, 'MemoryMax')))->toBeGreaterThan(0)
            ->and((int) opsTemplateValue($unit, 'CPUWeight'))->toBeGreaterThan(0)->toBeLessThan(100);
    }
});

it('documente dans la liste de mise en service chaque paramètre à remplacer des gabarits', function (): void {
    $checklist = (string) file_get_contents(base_path('ops/mise-en-service.md'));

    expect(is_file(base_path('ops/plesk/settings.md')))->toBeTrue('ops/plesk/settings.md est absent');

    $parameters = [];

    foreach (opsTemplateFiles() as $relative) {
        preg_match_all('/__TF_[A-Z_]+__/', opsTemplateSource($relative), $matches);

        foreach ($matches[0] as $parameter) {
            $parameters[$parameter] = true;
        }
    }

    foreach (array_keys($parameters) as $parameter) {
        expect(str_contains($checklist, "`{$parameter}`"))->toBeTrue("Paramètre {$parameter} absent de ops/mise-en-service.md");
    }
});
