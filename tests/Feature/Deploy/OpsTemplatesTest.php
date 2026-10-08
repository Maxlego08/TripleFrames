<?php

use App\Console\Commands\BackupSnapshotCommand;
use Illuminate\Support\Facades\Process;
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
        'systemd/tripleframes-deploy.service',
        'systemd/worker-game.env',
        'systemd/worker-default.env',
        'nginx/additional-directives.conf',
        // Préproduction (spec 100 § 18, L100-15).
        'redis/tripleframes-preprod-redis.conf',
        'systemd/tripleframes-preprod-redis.service',
        'systemd/tripleframes-preprod-worker@.service',
        'systemd/tripleframes-preprod-worker@game.service.d/limits.conf',
        'systemd/tripleframes-preprod-worker@default.service.d/limits.conf',
        'systemd/tripleframes-preprod-reverb.service',
        'systemd/preprod-worker-game.env',
        'systemd/preprod-worker-default.env',
        'nginx/additional-directives.preprod.conf',
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
function opsTemplateRedisDirectives(string $relative = 'redis/tripleframes-redis.conf'): array
{
    $directives = [];

    foreach (opsTemplateLines($relative) as $line) {
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

it('offre un service manuel de déploiement qui réutilise le hook sans reconstruire les assets sur le VPS', function (): void {
    $unit = 'systemd/tripleframes-deploy.service';
    $assignments = opsTemplateAssignments($unit);
    $lines = opsTemplateLines($unit);

    expect(opsTemplateValue($unit, 'Type'))->toBe('oneshot')
        ->and(opsTemplateValue($unit, 'User'))->toBe(opsTemplateValue('systemd/tripleframes-worker@.service', 'User'))
        ->and(opsTemplateValue($unit, 'Group'))->toBe(opsTemplateValue('systemd/tripleframes-worker@.service', 'Group'))
        ->and(opsTemplateValue($unit, 'WorkingDirectory'))->toBe(opsTemplateValue('systemd/tripleframes-worker@.service', 'WorkingDirectory'))
        ->and(opsTemplateValue($unit, 'ExecStartPre'))->toBe('/usr/bin/test -f __TF_DEPLOY_PATH__/public/build/manifest.json')
        ->and(opsTemplateValue($unit, 'ExecStart'))->toBe('/usr/bin/bash __TF_DEPLOY_PATH__/ops/deploy/hook.sh')
        ->and(opsTemplateValue($unit, 'UMask'))->toBe('0027')
        ->and(opsTemplateValue($unit, 'NoNewPrivileges'))->toBe('true')
        ->and($assignments)->not->toHaveKey('Restart')
        ->and($lines)->not->toContain('[Install]')
        ->and(implode("\n", $lines))->not->toMatch('/\b(?:npm|node|systemctl|supervisor)\b/i');
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

/**
 * Les scripts de sauvegarde (§ 13.2 à § 13.4), chemins relatifs à `ops/`.
 *
 * @return list<string>
 */
function opsBackupScripts(): array
{
    return ['backup/backup-hot.sh', 'backup/backup-cold.sh'];
}

it('livre des scripts de sauvegarde stricts, sans secret, au PHP de l\'abonnement et aux secrets hors dépôt', function (): void {
    foreach (opsBackupScripts() as $relative) {
        $source = opsTemplateSource($relative);
        $code = opsTemplateLines($relative);
        $joined = implode("\n", $code);

        expect($source)->not->toContain("\r", "ops/{$relative} : fin de ligne Windows")
            ->and($source)->toStartWith("#!/usr/bin/env bash\n")
            ->and($source)->toEndWith("\n")
            ->and($code)->toContain('set -euo pipefail')
            ->and($code)->toContain('umask 077')
            // Le PHP de l'abonnement par défaut, figé avant la lecture des secrets.
            ->and($code)->toContain('readonly PHP="${TF_PHP_BIN:-'.opsTemplatePhp().'}"')
            ->and($joined)->toContain('source "${HOME:?}/.config/tripleframes/backup.env"')
            ->and(array_search('readonly PHP="${TF_PHP_BIN:-'.opsTemplatePhp().'}"', $code, true))
            ->toBeLessThan(array_search('source "${HOME:?}/.config/tripleframes/backup.env"', $code, true))
            // Jamais de trace d'exécution : elle afficherait les secrets.
            ->and($joined)->not->toMatch('/\bset\s+-[a-z]*x/')
            ->and($joined)->not->toContain('echo')
            ->and($joined)->not->toMatch('/__TF_|<DOMAINE>/')
            // Aucun php du système : toute commande artisan passe par "$PHP".
            ->and($joined)->not->toMatch('/(^|[\s;|&(])php\s/m')
            ->and(preg_match_all('/\bartisan\b/', $joined))->toBe(preg_match_all('/"\$PHP" artisan /', $joined));

        // Chiffrement pour une clé PUBLIQUE seulement : rien ne déchiffre sur le VPS.
        foreach ($code as $line) {
            if (preg_match('/(^|\s)age\s/', $line) === 1) {
                expect($line)->toContain('age -r "$AGE_RECIPIENT"')
                    ->and($line)->not->toMatch('/\s(-d|--decrypt|-i|--identity|-p|--passphrase)\b/');
            }
        }

        // rclone en dépôt seul : jamais une commande qui lit, liste ou supprime.
        preg_match_all('/\brclone\s+([a-z]+)/', $joined, $rclone);

        expect($rclone[1])->not->toBeEmpty()
            ->and(array_unique($rclone[1]))->toBe(['copyto'])
            ->and($joined)->toContain('--s3-no-check-bucket --s3-no-head --no-check-dest --no-traverse');

        // Le mot de passe ne sort de sa variable que vers le fichier d'options.
        foreach ($code as $line) {
            if (str_contains($line, 'DB_PASSWORD') && ! str_starts_with($line, ': ')) {
                expect($line)->toBe('printf \'password=%s\n\' "$(option_value "$DB_PASSWORD")"');
            }
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $result = Process::run(['bash', '-n', base_path('ops/'.$relative)]);

            expect($result->successful())->toBeTrue($result->errorOutput());
        }
    }
});

it('vide la base comme backup:snapshot et ne bat la supervision qu\'après l\'envoi réussi', function (): void {
    $code = opsTemplateLines('backup/backup-hot.sh');
    $joined = implode("\n", $code);

    // Les sept tables exclues : la même liste que l'instantané de la règle 12.
    $start = array_search('readonly EXCLUDED_DATA_TABLES=(', $code, true);
    $end = $start === false ? false : array_search(')', array_slice($code, $start, null, true), true);

    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse()
        ->and(array_slice($code, (int) $start + 1, (int) $end - (int) $start - 1))
        ->toBe(BackupSnapshotCommand::EXCLUDED_DATA_TABLES);

    expect($joined)->toContain('--defaults-extra-file=${credentials}')
        ->and($joined)->toContain('--single-transaction')
        ->and($joined)->toContain('--quick')
        ->and($joined)->toContain('--no-tablespaces')
        ->and($joined)->toContain('mysqldump "${common[@]}" --no-data "$DB_DATABASE"')
        ->and($joined)->toContain('mysqldump "${common[@]}" --no-create-info --skip-triggers "${ignored[@]}" "$DB_DATABASE"')
        ->and($joined)->toContain('"$PHP" artisan backup:manifest --no-ansi')
        ->and($joined)->toContain('${BACKUP_REMOTE}/hot/${day}/')
        ->and($joined)->toContain('day="$(date -u +%Y-%m-%d)"');

    // Le battement est la DERNIÈRE commande : sous set -e, il n'est jamais
    // atteint après un échec.
    $curl = array_values(array_filter($code, static fn (string $line): bool => str_contains($line, 'curl ')));

    // L'adresse de battement porte le jeton de la sonde : jamais en argument.
    expect($curl)->toHaveCount(1)
        ->and(end($code))->toBe($curl[0])
        ->and($curl[0])->toBe('printf \'url = "%s"\n\' "$BACKUP_HEARTBEAT_URL" | curl -fsS --max-time 20 --retry 3 -o /dev/null -K -');

    // Un tableau d'options vide reste sûr sous set -u, même avant bash 4.4.
    expect($code)->toContain('${dump_extra[@]+"${dump_extra[@]}"}')
        ->and($joined)->not->toContain('"${dump_extra[@]}"'."\n");

    // Le tier froid envoie par condensat et bat sa propre sonde, en dernière
    // commande, après la sortie en échec.
    $coldCode = opsTemplateLines('backup/backup-cold.sh');
    $cold = implode("\n", $coldCode);
    $coldCurl = array_values(array_filter($coldCode, static fn (string $line): bool => str_contains($line, 'curl ')));

    expect($cold)->toContain('"$PHP" artisan backup:manifest --cold --no-ansi')
        ->and($cold)->toContain('${BACKUP_REMOTE}/cold/${key}.webp.age')
        ->and($cold)->toContain('key="${prefix}/${hash}"')
        ->and($cold)->toContain('"${BACKUP_COLD_HEARTBEAT_URL:?}"')
        ->and($coldCurl)->toHaveCount(1)
        ->and(end($coldCode))->toBe($coldCurl[0])
        ->and($coldCurl[0])->toBe('printf \'url = "%s"\n\' "$BACKUP_COLD_HEARTBEAT_URL" | curl -fsS --max-time 20 --retry 3 -o /dev/null -K -')
        ->and($cold)->toContain("if ((status != 0)); then\nexit \"\$status\"\nfi")
        // La liste est lue sur le descripteur 3 : rien n'hérite du manifeste en entrée.
        ->and($cold)->toContain('while read -r hash path <&3; do')
        ->and($cold)->toContain('done 3< "${work}/cold.txt"');

    // Le répertoire de travail, vidage en clair compris, vit sous l'état privé
    // de l'abonnement, jamais sous /tmp, et un reste d'un passage tué est
    // supprimé au suivant.
    foreach (['hot' => $code, 'cold' => $coldCode] as $tier => $lines) {
        expect($lines)->toContain("rm -rf -- \"\${state_dir}\"/{$tier}.work.*")
            ->and($lines)->toContain("work=\"\$(mktemp -d \"\${state_dir}/{$tier}.work.XXXXXX\")\"")
            ->and(implode("\n", $lines))->not->toMatch('/mktemp -d\)|\/tmp\b/');
    }
});

/*
| Préproduction (spec 100 § 18, L100-15, n° 6 de D66 du 07/10) : unités
| système toujours actives, posées une fois par root, plafonds plus serrés que
| la production ; Redis, Reverb, utilisateur et répertoire PROPRES ; même
| comportement que la production pour tout le reste.
*/

/**
 * Paires [production => préproduction] des gabarits homologues.
 *
 * @return array<string, string>
 */
function opsTemplatePreprodPairs(): array
{
    return [
        'redis/tripleframes-redis.conf' => 'redis/tripleframes-preprod-redis.conf',
        'systemd/tripleframes-redis.service' => 'systemd/tripleframes-preprod-redis.service',
        'systemd/tripleframes-worker@.service' => 'systemd/tripleframes-preprod-worker@.service',
        'systemd/tripleframes-worker@game.service.d/limits.conf' => 'systemd/tripleframes-preprod-worker@game.service.d/limits.conf',
        'systemd/tripleframes-worker@default.service.d/limits.conf' => 'systemd/tripleframes-preprod-worker@default.service.d/limits.conf',
        'systemd/tripleframes-reverb.service' => 'systemd/tripleframes-preprod-reverb.service',
        'systemd/worker-game.env' => 'systemd/preprod-worker-game.env',
        'systemd/worker-default.env' => 'systemd/preprod-worker-default.env',
        'nginx/additional-directives.conf' => 'nginx/additional-directives.preprod.conf',
    ];
}

it('donne à la préproduction un Redis dédié, aux mêmes garde-fous que la production et à son propre port', function (): void {
    $production = opsTemplateRedisDirectives();
    $preprod = opsTemplateRedisDirectives('redis/tripleframes-preprod-redis.conf');

    // Mêmes directives, mêmes valeurs, sauf l'identité de l'instance et son
    // plafond.
    $own = ['port', 'requirepass', 'dir', 'maxmemory'];

    expect(array_keys($preprod))->toBe(array_keys($production));

    foreach (array_diff(array_keys($production), $own) as $directive) {
        expect($preprod[$directive])->toBe($production[$directive], "Directive {$directive} différente de la production");
    }

    foreach (['port', 'requirepass', 'dir'] as $directive) {
        expect($preprod[$directive])->toHaveCount(1)
            ->and($preprod[$directive][0])->not->toBe($production[$directive][0], "La préproduction partage {$directive} avec la production");
    }

    expect($preprod['port'][0])->toBe('__TF_PREPROD_REDIS_PORT__')
        ->and($preprod['requirepass'][0])->toBe('__TF_PREPROD_REDIS_PASSWORD__')
        ->and(opsTemplateBytes($preprod['maxmemory'][0]))->toBeLessThan(opsTemplateBytes($production['maxmemory'][0]));

    $unit = 'systemd/tripleframes-preprod-redis.service';

    expect(opsTemplateBytes(opsTemplateValue($unit, 'MemoryMax')))->toBeGreaterThan(opsTemplateBytes($preprod['maxmemory'][0]))
        ->and(opsTemplateValue($unit, 'ExecStart'))->toEndWith(' /etc/tripleframes/tripleframes-preprod-redis.conf')
        ->and($preprod['dir'])->toBe(['/var/lib/'.opsTemplateValue($unit, 'StateDirectory')])
        ->and(opsTemplateValue($unit, 'Type'))->toBe('notify')
        ->and(opsTemplateValue($unit, 'User'))->toBe('tfredis');
});

it('lance les services de préproduction sous leur propre abonnement, plafonnés plus serré que la production', function (): void {
    $units = [
        'systemd/tripleframes-preprod-redis.service',
        'systemd/tripleframes-preprod-worker@.service',
        'systemd/tripleframes-preprod-reverb.service',
    ];

    foreach ($units as $unit) {
        expect(opsTemplateValue($unit, 'User'))->not->toBe('root')
            ->and(opsTemplateValue($unit, 'Restart'))->toBe('always')
            ->and(opsTemplateValue($unit, 'NoNewPrivileges'))->toBe('true')
            ->and(opsTemplateValue($unit, 'StartLimitIntervalSec'))->toBe('0')
            // Toujours actives : une promotion ne demande aucun geste root.
            ->and(opsTemplateValue($unit, 'WantedBy'))->toBe('multi-user.target');
    }

    $php = ['systemd/tripleframes-preprod-worker@.service', 'systemd/tripleframes-preprod-reverb.service'];

    foreach ($php as $unit) {
        $wants = implode("\n", opsTemplateAssignments($unit)['Wants'] ?? []);

        expect(opsTemplateValue($unit, 'User'))->toBe('__TF_PREPROD_SUBSCRIPTION_USER__')
            ->and(opsTemplateValue($unit, 'Group'))->toBe('__TF_PREPROD_SUBSCRIPTION_GROUP__')
            ->and(opsTemplateValue($unit, 'WorkingDirectory'))->toBe('__TF_PREPROD_DEPLOY_PATH__')
            ->and(opsTemplateValue($unit, 'ExecStart'))->toStartWith(opsTemplatePhp().' ')
            ->and(opsTemplateValue($unit, 'UMask'))->toBe('0027')
            ->and($wants)->toContain('tripleframes-preprod-redis.service')
            ->and($wants)->not->toMatch('/\btripleframes-redis\.service/');
    }

    // Même commande de worker qu'en production, sur ses propres arguments.
    expect(opsTemplateValue('systemd/tripleframes-preprod-worker@.service', 'ExecStart'))
        ->toBe(opsTemplateValue('systemd/tripleframes-worker@.service', 'ExecStart'))
        ->and(opsTemplateValue('systemd/tripleframes-preprod-worker@.service', 'EnvironmentFile'))
        ->toBe('/etc/tripleframes/preprod-worker-%i.env')
        ->and(opsTemplateValue('systemd/tripleframes-preprod-reverb.service', 'ExecStart'))
        ->toBe(opsTemplatePhp().' artisan reverb:start');

    // Plafonds : chacun au plus celui de son homologue de production, et un
    // poids CPU strictement plus bas (la production passe toujours avant).
    $capped = [
        'systemd/tripleframes-redis.service',
        'systemd/tripleframes-reverb.service',
        'systemd/tripleframes-worker@game.service.d/limits.conf',
        'systemd/tripleframes-worker@default.service.d/limits.conf',
    ];

    foreach ($capped as $production) {
        $preprod = opsTemplatePreprodPairs()[$production];

        expect(opsTemplateBytes(opsTemplateValue($preprod, 'MemoryMax')))
            ->toBeLessThanOrEqual(opsTemplateBytes(opsTemplateValue($production, 'MemoryMax')), "{$preprod} : MemoryMax au-dessus de la production")
            ->and((int) opsTemplateValue($preprod, 'CPUWeight'))
            ->toBeGreaterThan(0)
            ->toBeLessThan((int) opsTemplateValue($production, 'CPUWeight'), "{$preprod} : CPUWeight pas plus bas que la production");
    }

    // Bornes mémoire de chaque worker, dans l'ordre de la production, et même
    // cadencement de la file game.
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    foreach (opsTemplateWorkerInstances() as $instance) {
        $env = "systemd/preprod-worker-{$instance}.env";
        $limits = "systemd/tripleframes-preprod-worker@{$instance}.service.d/limits.conf";
        $options = opsTemplateOptions(opsTemplateValue($env, 'WORKER_ARGS'));

        expect(array_keys(opsTemplateAssignments($env)))->toBe(['PHP_ARGS', 'WORKER_ARGS'])
            ->and(array_keys(opsTemplateAssignments($limits)))->toBe(['MemoryMax', 'CPUWeight'])
            ->and($options)->toHaveKeys(['tries', 'sleep', 'timeout', 'max-time', 'memory'])
            ->and((int) $options['timeout'])->toBeLessThan($retryAfter);

        expect(preg_match('/^-d memory_limit=(\d+[KMG])$/', opsTemplateValue($env, 'PHP_ARGS'), $match))->toBe(1);

        $memory = (int) $options['memory'] * 1024 ** 2;
        $memoryLimit = opsTemplateBytes($match[1]);

        expect($memory)->toBeLessThan($memoryLimit)
            ->and($memoryLimit)->toBeLessThan(opsTemplateBytes(opsTemplateValue($limits, 'MemoryMax')));
    }

    $game = opsTemplateOptions(opsTemplateValue('systemd/preprod-worker-game.env', 'WORKER_ARGS'));
    $productionGame = opsTemplateOptions(opsTemplateValue('systemd/worker-game.env', 'WORKER_ARGS'));

    expect($game['tries'])->toBe($productionGame['tries'])
        ->and($game['sleep'])->toBe($productionGame['sleep']);
});

it('ferme la préproduction par une authentification HTTP héritée de tout le site, Reverb compris', function (): void {
    $preprod = opsTemplateLines('nginx/additional-directives.preprod.conf');
    $production = opsTemplateLines('nginx/additional-directives.conf');

    // Au niveau du serveur, AVANT toute location : héritée par toutes, celles
    // que Plesk génère comprises.
    $firstLocation = null;

    foreach ($preprod as $index => $line) {
        if ($firstLocation === null && str_starts_with($line, 'location ')) {
            $firstLocation = $index;
        }
    }

    $auth = array_search('auth_basic "TripleFrames preproduction";', $preprod, true);
    $userFile = array_search('auth_basic_user_file __TF_PREPROD_HTPASSWD_FILE__;', $preprod, true);

    expect($firstLocation)->not->toBeNull()
        ->and($auth)->not->toBeFalse()
        ->and($userFile)->not->toBeFalse()
        ->and($auth)->toBeLessThan($firstLocation)
        ->and($userFile)->toBeLessThan($firstLocation);

    // Aucune location ne la désactive.
    expect(implode("\n", $preprod))->not->toContain('auth_basic off');

    // Hors l'authentification et le port de Reverb, les mêmes directives que
    // la production : la préproduction répète la production.
    $normalised = array_values(array_filter(
        array_map(static fn (string $line): string => str_replace('__TF_PREPROD_REVERB_PORT__', '__TF_REVERB_PORT__', $line), $preprod),
        static fn (string $line): bool => ! str_starts_with($line, 'auth_basic'),
    ));

    expect($normalised)->toBe($production);
});

it('livre un .env de référence de préproduction sans secret ni donnée de production, en APP_ENV=staging', function (): void {
    $source = opsTemplateSource('preprod/env.reference');

    expect($source)->not->toContain("\r");

    $values = [];

    foreach (opsTemplateLines('preprod/env.reference') as $line) {
        expect(str_contains($line, '='))->toBeTrue("Ligne sans affectation : {$line}");

        $values[Str::before($line, '=')] = Str::after($line, '=');
    }

    expect($values['APP_ENV'] ?? null)->toBe('staging')
        ->and($values['APP_DEBUG'] ?? null)->toBe('false')
        ->and($values['SITE_INDEXABLE'] ?? null)->toBe('')
        ->and($values['ACCOUNTS_REGISTRATION_OPEN'] ?? null)->toBe('')
        ->and($values['ACCOUNTS_PASSKEYS_ENABLED'] ?? null)->toBe('')
        ->and($values['MAIL_MAILER'] ?? null)->toBe('log')
        ->and($values['REDIS_CLIENT'] ?? null)->toBe('predis');

    // Aucun secret : toute clé sensible est vide.
    foreach ($values as $name => $value) {
        if (preg_match('/(?:KEY|SECRET|TOKEN|PASSWORD)$/', $name) === 1) {
            expect($value)->toBe('', "{$name} porte une valeur dans le dépôt");
        }
    }

    // Toute variable de référence est une variable connue du projet : de
    // .env.example, ou SESSION_SECURE_COOKIE, propre aux machines servies
    // (spec 100 § 10.10).
    $example = (string) file_get_contents(base_path('.env.example'));

    foreach (array_keys($values) as $name) {
        if ($name === 'SESSION_SECURE_COOKIE') {
            continue;
        }

        expect(preg_match('/^#?\s*'.preg_quote($name, '/').'=/m', $example))->toBe(1, "{$name} absente de .env.example");
    }
});
