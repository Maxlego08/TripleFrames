<?php

use App\Console\Commands\BackupSnapshotCommand;
use App\Enums\Locale;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| L'instantané bloquant de la règle 12 — spec 100 § 13.1, contrat C18-bis
|--------------------------------------------------------------------------
|
| `mysqldump` n'est jamais lancé : `Process::fake()` le simule et
| `preventStrayProcesses()` fait échouer tout processus non simulé. La
| compression, la vérification, le nommage et le nettoyage, eux, sont RÉELS,
| dans un répertoire temporaire hors du dépôt : c'est eux que ces tests
| prouvent, et un instantané simulé de bout en bout ne prouverait rien.
|
| La suite tourne sur SQLite ; la connexion par défaut est basculée sur une
| connexion MySQL fictive le temps d'une commande qui doit aller jusqu'au
| vidage, et toujours rétablie — la transaction du test vit sur SQLite.
|
*/

/** Mot de passe fictif : il ne doit jamais paraître dans une ligne de commande. */
const BACKUP_SNAPSHOT_TEST_PASSWORD = 'mot-de-passe-fictif-jamais-en-argument';

const BACKUP_SNAPSHOT_TEST_DATABASE = 'tripleframes_instantane';

beforeEach(function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tripleframes-snapshots-'.bin2hex(random_bytes(6));

    config(['backup.snapshot_dir' => $directory]);

    $this->snapshotDirectory = $directory;
    $this->scratchDirectories = [$directory];
});

afterEach(function (): void {
    foreach ($this->scratchDirectories as $directory) {
        File::deleteDirectory($directory);
    }
});

/**
 * Une ligne de console du domaine `admin`, rendue en français.
 *
 * @param  array<string, int|string>  $replace
 */
function backupSnapshotMessage(string $key, array $replace = []): string
{
    $line = trans('admin.console.backup.'.$key, $replace, Locale::French->value);

    expect($line)->toBeString()->not->toBe('admin.console.backup.'.$key);

    return (string) $line;
}

/**
 * Joue `$run` avec une connexion par défaut MySQL fictive, puis rétablit la
 * connexion de la suite quoi qu'il arrive.
 */
function backupSnapshotOnMysql(Closure $run, string $password = BACKUP_SNAPSHOT_TEST_PASSWORD): void
{
    $previous = config('database.default');

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.driver' => 'mysql',
        'database.connections.mysql.host' => '127.0.0.1',
        'database.connections.mysql.port' => '3306',
        'database.connections.mysql.unix_socket' => '',
        'database.connections.mysql.database' => BACKUP_SNAPSHOT_TEST_DATABASE,
        'database.connections.mysql.username' => 'tripleframes',
        'database.connections.mysql.password' => $password,
        'database.connections.mysql.prefix' => '',
    ]);

    try {
        $run();
    } finally {
        config(['database.default' => $previous]);
    }
}

/**
 * Simule les deux passes de `mysqldump` : la passe de structure (`--no-data`)
 * et celle des données rendent chacune leur sortie ; chaque commande est
 * consignée avec l'existence de son fichier d'options au moment de l'appel et
 * le mode silencieux de son processus.
 *
 * @param  array{structure?: string, data?: string, structureExit?: int, dataExit?: int, dataError?: string}  $outputs
 * @return ArrayObject<int, array{command: list<string>, quietly: bool, credentials: string|null, credentialsExisted: bool, credentialsContent: string}>
 */
function backupSnapshotFakeDump(array $outputs = []): ArrayObject
{
    /** @var ArrayObject<int, array{command: list<string>, quietly: bool, credentials: string|null, credentialsExisted: bool, credentialsContent: string}> $calls */
    $calls = new ArrayObject;

    Process::preventStrayProcesses();

    Process::fake(function (PendingProcess $process) use ($outputs, $calls) {
        /** @var list<string> $command */
        $command = is_array($process->command) ? array_values($process->command) : [];
        $credentials = null;

        foreach ($command as $argument) {
            if (str_starts_with($argument, '--defaults-extra-file=')) {
                $credentials = substr($argument, strlen('--defaults-extra-file='));
            }
        }

        $calls->append([
            'command' => $command,
            'quietly' => $process->quietly,
            'credentials' => $credentials,
            'credentialsExisted' => $credentials !== null && is_file($credentials),
            'credentialsContent' => $credentials !== null && is_file($credentials) ? (string) file_get_contents($credentials) : '',
        ]);

        $structure = in_array('--no-data', $command, true);

        return Process::result(
            output: $structure
                ? ($outputs['structure'] ?? "-- MySQL dump\nCREATE TABLE `movie` (`id` bigint);\n-- Dump completed on 2026-09-24 10:15:00")
                : ($outputs['data'] ?? "-- MySQL dump\nINSERT INTO `movie` VALUES (1);\n-- Dump completed on 2026-09-24 10:15:01"),
            errorOutput: $structure ? '' : ($outputs['dataError'] ?? ''),
            exitCode: $structure ? ($outputs['structureExit'] ?? 0) : ($outputs['dataExit'] ?? 0),
        );
    });

    return $calls;
}

/**
 * Les fichiers du répertoire d'instantanés, `.` et `..` exclus.
 *
 * @return list<string>
 */
function backupSnapshotFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
}

function backupSnapshotInflate(string $path): string
{
    $plain = gzdecode((string) file_get_contents($path));

    expect($plain)->toBeString();

    return (string) $plain;
}

it('ne fait rien et réussit sans migration en attente sous --if-pending', function (): void {
    Process::fake();
    Process::preventStrayProcesses();

    // La suite a joué toutes ses migrations : le migrateur n'en signale aucune.
    $this->artisan('backup:snapshot', ['--if-pending' => true])
        ->expectsOutputToContain(backupSnapshotMessage('snapshot_skipped'))
        ->assertExitCode(0)
        ->run();

    Process::assertNothingRan();
    expect(backupSnapshotFiles($this->snapshotDirectory))->toBe([]);

    // Une migration en attente — un fichier que la table des migrations ne
    // connaît pas, exactement ce que rapporte `migrate:status --pending` — et
    // l'option ne dispense plus de rien : la commande tente l'instantané, que
    // la connexion SQLite de la suite ne sait pas prendre.
    $pending = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tripleframes-pending-'.bin2hex(random_bytes(6));
    $this->scratchDirectories[] = $pending;

    File::ensureDirectoryExists($pending);
    File::put(
        $pending.DIRECTORY_SEPARATOR.'2099_01_01_000000_backup_snapshot_pending_probe.php',
        "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class extends Migration\n{\n    public function up(): void {}\n};\n",
    );

    app('migrator')->path($pending);

    $this->artisan('backup:snapshot', ['--if-pending' => true])
        ->doesntExpectOutputToContain(backupSnapshotMessage('snapshot_skipped'))
        ->expectsOutputToContain(backupSnapshotMessage('snapshot_driver', ['driver' => 'sqlite']))
        ->assertExitCode(1)
        ->run();

    Process::assertNothingRan();
    expect(backupSnapshotFiles($this->snapshotDirectory))->toBe([]);
});

it('sort en échec quand l\'instantané ne peut être écrit ou vérifié', function (): void {
    // 1. `mysqldump` échoue sur la passe des données : code 1, sa raison est
    //    rapportée, et le vidage à moitié écrit ne survit pas.
    $calls = backupSnapshotFakeDump([
        'dataExit' => 2,
        'dataError' => 'mysqldump: Got error: 1044: Access denied when using LOCK TABLES',
    ]);

    backupSnapshotOnMysql(function (): void {
        $this->artisan('backup:snapshot')
            ->expectsOutputToContain(backupSnapshotMessage('snapshot_failed'))
            ->expectsOutputToContain('Access denied when using LOCK TABLES')
            ->assertExitCode(1)
            ->run();
    });

    expect($calls)->toHaveCount(2)
        ->and(backupSnapshotFiles($this->snapshotDirectory))->toBe([]);

    foreach ($calls as $call) {
        expect($call['credentialsExisted'])->toBeTrue()
            ->and(is_file((string) $call['credentials']))->toBeFalse('Le fichier d\'options survit à l\'échec');
    }

    // 2. `mysqldump` sort en 0 mais son vidage est tronqué — sans sa ligne de
    //    fin : l'instantané ne se vérifie pas et n'est pas conservé.
    backupSnapshotFakeDump(['data' => "-- MySQL dump\nINSERT INTO `movie` VALUES (1);"]);

    backupSnapshotOnMysql(function (): void {
        $this->artisan('backup:snapshot')
            ->expectsOutputToContain(backupSnapshotMessage('snapshot_failed'))
            ->assertExitCode(1)
            ->run();
    });

    expect(backupSnapshotFiles($this->snapshotDirectory))->toBe([]);

    // 3. Le répertoire ne peut pas être créé : un FICHIER occupe son parent.
    //    Aucun vidage n'est lancé.
    $blocker = $this->snapshotDirectory.DIRECTORY_SEPARATOR.'occupe';
    File::ensureDirectoryExists($this->snapshotDirectory);
    File::put($blocker, 'pas un répertoire');
    config(['backup.snapshot_dir' => $blocker.DIRECTORY_SEPARATOR.'instantanes']);

    $calls = backupSnapshotFakeDump();

    backupSnapshotOnMysql(function (): void {
        $this->artisan('backup:snapshot')
            ->expectsOutputToContain(backupSnapshotMessage('snapshot_failed'))
            ->assertExitCode(1)
            ->run();
    });

    expect($calls)->toHaveCount(0)
        ->and(backupSnapshotFiles($this->snapshotDirectory))->toBe(['occupe']);

    // 4. La vérification elle-même : un flux gzip tronqué ou altéré, ou privé
    //    d'une ligne de fin de vidage, n'est jamais tenu pour un instantané.
    $plain = "CREATE TABLE `movie` (`id` bigint);\n-- Dump completed on 2026-09-24 10:15:00\n";
    $compressed = (string) gzencode($plain);
    $probe = $this->snapshotDirectory.DIRECTORY_SEPARATOR.'probe.sql.gz';

    File::put($probe, $compressed);
    expect(BackupSnapshotCommand::verifies($probe, 1))->toBeTrue()
        ->and(BackupSnapshotCommand::verifies($probe, 2))->toBeFalse();

    File::put($probe, substr($compressed, 0, -6));
    expect(BackupSnapshotCommand::verifies($probe, 1))->toBeFalse();

    $altered = $compressed;
    $altered[strlen($altered) - 5] = chr(ord($altered[strlen($altered) - 5]) ^ 0xFF);
    File::put($probe, $altered);
    expect(BackupSnapshotCommand::verifies($probe, 1))->toBeFalse();

    File::put($probe, (string) gzencode("-- Dump completed on 2026-09-24 10:15:00\nINSERT INTO `movie` VALUES (1);\n"));
    expect(BackupSnapshotCommand::verifies($probe, 1))->toBeFalse();

    File::put($probe, 'pas du gzip');
    expect(BackupSnapshotCommand::verifies($probe, 1))->toBeFalse()
        ->and(BackupSnapshotCommand::verifies($probe.'.absent', 1))->toBeFalse();
});

it('refuse un répertoire d\'instantanés situé sous le chemin de déploiement hors local et testing', function (): void {
    Process::fake();
    Process::preventStrayProcesses();

    $previous = app()['env'];
    app()['env'] = 'production';

    try {
        $refused = [
            base_path('storage/app/snapshots'),
            base_path(),
            base_path('storage').DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'snapshots',
            dirname(base_path()).DIRECTORY_SEPARATOR.basename(base_path()).DIRECTORY_SEPARATOR.'snapshots',
            'storage/app/snapshots',
            '',
        ];

        foreach ($refused as $directory) {
            config(['backup.snapshot_dir' => $directory]);

            foreach ([[], ['--if-pending' => true]] as $options) {
                $this->artisan('backup:snapshot', $options)
                    ->expectsOutputToContain(backupSnapshotMessage('snapshot_unsafe_dir'))
                    ->assertExitCode(1)
                    ->run();
            }
        }

        // Hors du déploiement — y compris un voisin qui partage seulement le
        // début du nom —, la garde laisse passer : la commande va jusqu'au
        // vidage, qu'elle ne sait pas prendre sur la connexion SQLite.
        foreach ([
            dirname(base_path()).DIRECTORY_SEPARATOR.basename(base_path()).'-instantanes',
            $this->snapshotDirectory,
        ] as $directory) {
            config(['backup.snapshot_dir' => $directory]);

            $this->artisan('backup:snapshot')
                ->doesntExpectOutputToContain(backupSnapshotMessage('snapshot_unsafe_dir'))
                ->expectsOutputToContain(backupSnapshotMessage('snapshot_driver', ['driver' => 'sqlite']))
                ->assertExitCode(1)
                ->run();
        }
    } finally {
        app()['env'] = $previous;
    }

    // En `testing` (comme en `local`), le repli sous `storage/` reste permis.
    config(['backup.snapshot_dir' => base_path('storage/app/snapshots')]);

    $this->artisan('backup:snapshot')
        ->doesntExpectOutputToContain(backupSnapshotMessage('snapshot_unsafe_dir'))
        ->assertExitCode(1)
        ->run();

    Process::assertNothingRan();
});

it('exclut du vidage les données des sept tables exclues du tier chaud', function (): void {
    $this->freezeTime();

    $calls = backupSnapshotFakeDump();

    backupSnapshotOnMysql(function (): void {
        $this->artisan('backup:snapshot')
            ->expectsOutputToContain(backupSnapshotMessage('snapshot_written', ['file' => '']))
            ->assertExitCode(0)
            ->run();
    });

    // Les sept tables existent sous ces noms dans le schéma : un renommage ne
    // ferait pas entrer leurs données en silence dans l'instantané.
    expect(BackupSnapshotCommand::EXCLUDED_DATA_TABLES)->toBe([
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ]);

    foreach (BackupSnapshotCommand::EXCLUDED_DATA_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Table exclue absente du schéma : {$table}");
    }

    expect($calls)->toHaveCount(2);

    [$structure, $data] = [$calls[0], $calls[1]];

    foreach ([$structure, $data] as $call) {
        $command = $call['command'];

        // mysqldump, options communes, le fichier d'options en PREMIER, la base
        // en dernier, et jamais le mot de passe en argument.
        expect($command[0])->toBe('mysqldump')
            ->and($command[1])->toStartWith('--defaults-extra-file=')
            ->and($command)->toContain('--single-transaction', '--quick', '--no-tablespaces')
            ->and(end($command))->toBe(BACKUP_SNAPSHOT_TEST_DATABASE)
            ->and(str_contains(implode(' ', $command), BACKUP_SNAPSHOT_TEST_PASSWORD))->toBeFalse();

        // La sortie n'est jamais retenue par le processus : elle ne passe que
        // par le rappel qui la compresse, sans copie brute du vidage en clair
        // dans le répertoire temporaire du système.
        expect($call['quietly'])->toBeTrue();

        // Le mot de passe voyage par le fichier d'options, qui n'existe que le
        // temps du vidage.
        expect($call['credentialsExisted'])->toBeTrue()
            ->and($call['credentialsContent'])->toContain('[client]', 'password="'.BACKUP_SNAPSHOT_TEST_PASSWORD.'"')
            ->and(is_file((string) $call['credentials']))->toBeFalse();
    }

    $ignored = static fn (array $command): array => array_values(array_filter(
        $command,
        static fn (string $argument): bool => str_starts_with($argument, '--ignore-table'),
    ));

    // Passe 1 : la STRUCTURE de toutes les tables, les sept comprises.
    expect($structure['command'])->toContain('--no-data')
        ->and($structure['command'])->not->toContain('--no-create-info')
        ->and($ignored($structure['command']))->toBe([]);

    // Passe 2 : les DONNÉES de toutes, sauf exactement les sept ; sans
    // déclencheurs, déjà écrits par la passe 1.
    expect($data['command'])->toContain('--no-create-info', '--skip-triggers')
        ->and($data['command'])->not->toContain('--no-data')
        ->and($ignored($data['command']))->toBe(array_map(
            static fn (string $table): string => '--ignore-table='.BACKUP_SNAPSHOT_TEST_DATABASE.'.'.$table,
            BackupSnapshotCommand::EXCLUDED_DATA_TABLES,
        ));

    // Un seul fichier, nommé par l'instant UTC, vérifié puis renommé : il
    // contient les deux passes, dans l'ordre, et rien d'autre.
    $expectedName = BackupSnapshotCommand::fileNameFor(now());

    expect(backupSnapshotFiles($this->snapshotDirectory))->toBe([$expectedName])
        ->and(BackupSnapshotCommand::takenAt($expectedName)?->equalTo(now()->startOfSecond()))->toBeTrue();

    $path = $this->snapshotDirectory.DIRECTORY_SEPARATOR.$expectedName;

    expect(backupSnapshotInflate($path))->toBe(
        "-- MySQL dump\nCREATE TABLE `movie` (`id` bigint);\n-- Dump completed on 2026-09-24 10:15:00\n"
        ."-- MySQL dump\nINSERT INTO `movie` VALUES (1);\n-- Dump completed on 2026-09-24 10:15:01\n",
    )->and(BackupSnapshotCommand::verifies($path, 2))->toBeTrue();

    // `0600` : lisible par l'utilisateur d'abonnement seul (sans objet sous
    // Windows, qui n'a pas ces bits).
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(fileperms($path) & 0777)->toBe(0600);
    }

    // Un mot de passe qui porte le guillemet délimiteur, un `#` et une barre
    // oblique inverse s'écrit échappé : non échappé, l'analyseur des fichiers
    // d'options fermerait les guillemets après `a` et lirait le reste comme un
    // commentaire — mysqldump s'authentifierait avec un mot de passe tronqué.
    $this->travel(1)->seconds();

    $calls = backupSnapshotFakeDump();

    backupSnapshotOnMysql(function (): void {
        $this->artisan('backup:snapshot')->assertExitCode(0)->run();
    }, password: 'a"b#c\\d');

    expect($calls)->toHaveCount(2);

    foreach ($calls as $call) {
        expect($call['credentialsContent'])->toContain('password="a\\"b#c\\\\d"'."\n")
            ->and(str_contains(implode(' ', $call['command']), 'b#c'))->toBeFalse();
    }
});
