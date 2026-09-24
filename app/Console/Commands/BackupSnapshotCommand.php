<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use Carbon\CarbonImmutable;
use DeflateContext;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * L'instantané bloquant de la règle 12 — spec 100 § 13.1, contrat C18-bis.
 *
 * **Pourquoi.** `movie`, `frame`, `movie_title`, `alias` et `frame_review`
 * portent des centaines d'heures de curation irremplaçables ; le code se
 * réécrit, elles non. Deux déclencheurs :
 *
 * 1. le hook de déploiement, étape 4, sous `--if-pending` : rien à faire (code
 *    0) quand le migrateur ne signale aucune migration en attente, quelle que
 *    soit la table — « en attente » est ce que rapporte le migrateur, jamais
 *    une liste tenue à la main ;
 * 2. tout geste de console qui écrit l'une des cinq tables : la commande sans
 *    option, code 0 exigé avant de poursuivre.
 *
 * **Contenu.** Deux passes de `mysqldump --single-transaction --quick
 * --no-tablespaces` (l'utilisateur MySQL de Plesk n'a pas le privilège
 * `PROCESS`), concaténées dans un seul fichier compressé : la STRUCTURE de
 * toutes les tables, puis les DONNÉES de toutes sauf les sept tables exclues
 * du tier chaud ({@see self::EXCLUDED_DATA_TABLES}) — elles portent des IP,
 * des adresses et des charges sérialisées sans valeur pour un retour arrière
 * de migration. La seconde passe saute les déclencheurs, déjà écrits par la
 * première : les écrire deux fois ferait échouer la restauration.
 *
 * **Le mot de passe ne passe jamais en argument** : il serait lisible dans la
 * liste des processus de toute la machine, qui héberge d'autres abonnements.
 * Il voyage par un fichier d'options `0600` (`--defaults-extra-file`, premier
 * argument par exigence de `mysqldump`), supprimé dès la fin des passes.
 *
 * **Vérifié avant de rendre la main** ({@see self::verifies()}) : intégrité de
 * la compression — flux gzip complet, somme de contrôle comprise — et ligne
 * finale de fin de vidage, une par passe. Le fichier s'écrit sous un nom
 * `.partial` et n'est renommé qu'une fois vérifié : un nom d'instantané
 * désigne toujours un vidage complet, et un échec ne laisse RIEN derrière lui.
 * Codes de sortie : 0 (écrit et vérifié, ou rien à faire sous `--if-pending`),
 * 1 (tout le reste) ; sous `set -e`, un 1 arrête le hook avant la migration.
 *
 * **Local et non chiffré, délibérément** : même frontière de confiance que la
 * base elle-même. Fichier `0600`, nommé par l'instant UTC
 * ({@see self::fileNameFor()}), élagué par `backup:prune-snapshots`.
 *
 * Sortie en français, par clés littérales du domaine `admin` et locale forcée
 * — comme `admin:first-admin` : une console ne traverse aucun middleware.
 */
class BackupSnapshotCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:snapshot
        {--if-pending : ne rien faire sans migration en attente}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Instantané bloquant de la base avant une migration ou un geste de console sur le catalogue (règle 12)';

    /**
     * Les sept tables dont les DONNÉES sont exclues, comme du tier chaud
     * (§ 13.2) ; leur structure, elle, est toujours vidée.
     *
     * @var list<string>
     */
    public const array EXCLUDED_DATA_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ];

    /** Nom d'un instantané : `snapshot-<instant UTC>.sql.gz`, `.partial` tant qu'il n'est pas vérifié. */
    public const string FILE_PATTERN = '/^snapshot-(\d{8}T\d{6}Z)\.sql\.gz(?:\.partial)?$/';

    public const string PARTIAL_SUFFIX = '.partial';

    /** Format de l'instant dans le nom, toujours en UTC. */
    private const string INSTANT_FORMAT = 'Ymd\THis\Z';

    /** Début de la ligne que `mysqldump` écrit en dernier quand un vidage a abouti. */
    private const string DUMP_COMPLETED_MARKER = '-- Dump completed';

    /** Pilotes dont le vidage passe par `mysqldump`. */
    private const array SUPPORTED_DRIVERS = ['mysql', 'mariadb'];

    /**
     * Borne d'une passe. Un vidage de quelques centaines de mégaoctets tient en
     * une minute sur MySQL local ; au-delà, mieux vaut échouer et arrêter le
     * hook que le laisser pendre.
     */
    private const int DUMP_TIMEOUT_SECONDS = 1800;

    /** Niveau de compression : le défaut de gzip, le vidage n'est pas archivé. */
    private const int COMPRESSION_LEVEL = 6;

    private const int READ_CHUNK_BYTES = 65536;

    /** Lignes de diagnostic de `mysqldump` rapportées au porteur, au plus. */
    private const int DIAGNOSTIC_LINES = 5;

    /** Fin de stderr retenue pour ce diagnostic, en octets : le reste est jeté au fil de l'eau. */
    private const int ERROR_TAIL_BYTES = 8192;

    public function handle(): int
    {
        $directory = self::safeDirectory();

        if ($directory === null) {
            $this->components->error($this->translated(trans('admin.console.backup.snapshot_unsafe_dir', [], Locale::French->value)));

            return self::FAILURE;
        }

        if ($this->option('if-pending') && ! $this->hasPendingMigrations()) {
            $this->components->info($this->translated(trans('admin.console.backup.snapshot_skipped', [], Locale::French->value)));

            return self::SUCCESS;
        }

        $connection = self::connection();

        if (! in_array($connection['driver'], self::SUPPORTED_DRIVERS, true)) {
            $this->components->error($this->translated(trans(
                'admin.console.backup.snapshot_driver',
                ['driver' => $connection['driver'] === '' ? '?' : $connection['driver']],
                Locale::French->value,
            )));

            return self::FAILURE;
        }

        /** @var list<string> $diagnostics */
        $diagnostics = [];

        $file = $connection['database'] === ''
            ? null
            : $this->takeSnapshot($directory, $connection, $diagnostics);

        if ($file === null) {
            $this->components->error($this->translated(trans('admin.console.backup.snapshot_failed', [], Locale::French->value)));

            if ($diagnostics !== []) {
                $this->components->bulletList($diagnostics);
            }

            return self::FAILURE;
        }

        $this->components->info($this->translated(trans(
            'admin.console.backup.snapshot_written',
            ['file' => $file],
            Locale::French->value,
        )));

        return self::SUCCESS;
    }

    /**
     * Le nom d'un instantané pris à cet instant.
     */
    public static function fileNameFor(CarbonImmutable $at): string
    {
        return 'snapshot-'.$at->utc()->format(self::INSTANT_FORMAT).'.sql.gz';
    }

    /**
     * L'instant d'un instantané d'après son NOM, ou `null` si le nom n'est pas
     * celui d'un instantané — y compris un instant impossible
     * (`20261399T999999Z`), qu'un analyseur complaisant reporterait au mois
     * suivant. C'est le seul critère d'élagage : l'heure de modification d'un
     * fichier se réécrit par une simple copie.
     */
    public static function takenAt(string $fileName): ?CarbonImmutable
    {
        if (preg_match(self::FILE_PATTERN, $fileName, $match) !== 1) {
            return null;
        }

        try {
            $at = CarbonImmutable::createFromFormat('!'.self::INSTANT_FORMAT, $match[1], 'UTC');
        } catch (Throwable) {
            return null;
        }

        if (! $at instanceof CarbonImmutable || $at->format(self::INSTANT_FORMAT) !== $match[1]) {
            return null;
        }

        return $at;
    }

    /**
     * Le répertoire configuré, résolu d'une seule façon pour l'écrivain et
     * pour l'élagage : une valeur de `.env` entre guillemets qui porterait un
     * blanc en bord ferait sinon écrire dans un répertoire et élaguer dans un
     * autre — la rétention cesserait en silence. Chaîne vide si rien n'est
     * configuré.
     */
    public static function configuredDirectory(): string
    {
        $configured = config('backup.snapshot_dir');

        return is_string($configured) ? trim($configured) : '';
    }

    /**
     * Le répertoire d'instantanés, ou `null` s'il est refusé.
     *
     * Hors `local` et `testing`, sur le modèle de
     * `AppServiceProvider::assertFramesDiskRoot()` : refus d'un chemin vide,
     * relatif (il se résoudrait contre le répertoire courant, qui est la racine
     * du déploiement) ou situé sous `base_path()` — comparaison faite sur des
     * chemins normalisés, `..` résolus, pour qu'un détour par le parent ne
     * franchisse pas la garde.
     */
    public static function safeDirectory(): ?string
    {
        $directory = self::configuredDirectory();

        if (app()->environment(['local', 'testing'])) {
            return $directory === '' ? null : $directory;
        }

        if ($directory === '' || ! self::isAbsolute($directory)) {
            return null;
        }

        $candidate = self::normalized($directory);
        $deployment = self::normalized(base_path());

        if ($candidate === $deployment || str_starts_with($candidate, $deployment.'/')) {
            return null;
        }

        return $directory;
    }

    /**
     * Vérifie un instantané : flux gzip complet et intègre (la somme de
     * contrôle et la longueur de fin de flux sont contrôlées par zlib), autant
     * de lignes de fin de vidage que de passes, et la dernière ligne non vide
     * en est une. Publique pour être éprouvée sur un fichier corrompu.
     */
    public static function verifies(string $path, int $dumps): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $inflate = inflate_init(ZLIB_ENCODING_GZIP);

            if ($inflate === false) {
                return false;
            }

            $carry = '';
            $completed = 0;
            $lastLine = '';

            while (! feof($handle)) {
                $chunk = fread($handle, self::READ_CHUNK_BYTES);

                if ($chunk === false) {
                    return false;
                }

                $plain = inflate_add($inflate, $chunk);

                if ($plain === false) {
                    return false;
                }

                $carry = self::scanLines($carry.$plain, $completed, $lastLine);
            }

            // Fin de flux atteinte, somme de contrôle comprise ; un flux tronqué
            // s'arrête avant, sans erreur, et c'est ce statut qui le trahit.
            if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
                return false;
            }

            // Le saut de ligne ajouté achève une dernière ligne sans fin de ligne.
            self::scanLines($carry."\n", $completed, $lastLine);

            return $completed === $dumps
                && str_starts_with($lastLine, self::DUMP_COMPLETED_MARKER);
        } catch (Throwable) {
            // Un flux tronqué ou altéré fait lever zlib : c'est un échec de
            // vérification, jamais une exception qui remonte au hook.
            return false;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Les lignes complètes de `$text` : compte les fins de vidage, retient la
     * dernière ligne non vide, rend le reste inachevé.
     */
    private static function scanLines(string $text, int &$completed, string &$lastLine): string
    {
        $end = strrpos($text, "\n");

        if ($end === false) {
            return $text;
        }

        foreach (explode("\n", substr($text, 0, $end)) as $line) {
            $line = rtrim($line, "\r");

            if (trim($line) === '') {
                continue;
            }

            $lastLine = $line;

            if (str_starts_with($line, self::DUMP_COMPLETED_MARKER)) {
                $completed++;
            }
        }

        return substr($text, $end + 1);
    }

    /**
     * « Migration en attente » au sens du migrateur, exactement comme
     * `migrate:status --pending` : un fichier de migration que la table des
     * migrations ne connaît pas. Sans table des migrations, tout est en attente.
     */
    private function hasPendingMigrations(): bool
    {
        // Le migrateur du framework : celui-là même que lit `migrate:status`.
        $migrator = $this->laravel->make(Migrator::class);

        return (bool) $migrator->usingConnection(DB::getDefaultConnection(), static function () use ($migrator): bool {
            $files = $migrator->getMigrationFiles(array_merge(
                $migrator->paths(),
                [database_path('migrations')],
            ));

            if ($files === []) {
                return false;
            }

            if (! $migrator->repositoryExists()) {
                return true;
            }

            return array_diff(array_keys($files), $migrator->getRepository()->getRan()) !== [];
        });
    }

    /**
     * La connexion par défaut, lue dans la configuration et jamais ouverte :
     * c'est `mysqldump` qui s'y connecte.
     *
     * @return array{driver: string, host: string, port: string, socket: string, database: string, username: string, password: string, prefix: string}
     */
    private static function connection(): array
    {
        $config = config('database.connections.'.DB::getDefaultConnection());
        $config = is_array($config) ? $config : [];

        $read = static function (string $key) use ($config): string {
            $value = $config[$key] ?? null;

            return is_scalar($value) ? trim((string) $value) : '';
        };

        return [
            'driver' => $read('driver'),
            'host' => $read('host'),
            'port' => $read('port'),
            'socket' => $read('unix_socket'),
            'database' => $read('database'),
            'username' => $read('username'),
            'password' => is_scalar($config['password'] ?? null) ? (string) $config['password'] : '',
            'prefix' => $read('prefix'),
        ];
    }

    /**
     * Écrit, vérifie et nomme l'instantané ; rend son chemin, ou `null` en
     * laissant le répertoire tel qu'il était.
     *
     * @param  array{driver: string, host: string, port: string, socket: string, database: string, username: string, password: string, prefix: string}  $connection
     * @param  list<string>  $diagnostics
     */
    private function takeSnapshot(string $directory, array $connection, array &$diagnostics): ?string
    {
        $partial = null;
        $handle = null;
        $credentials = null;

        try {
            File::ensureDirectoryExists($directory, 0700);

            $final = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.self::fileNameFor(CarbonImmutable::now());
            $candidate = $final.self::PARTIAL_SUFFIX;

            // Création EXCLUSIVE : un fichier du même nom n'est jamais écrasé,
            // et il n'est pas non plus supprimé par le nettoyage ci-dessous.
            $opened = fopen($candidate, 'xb');

            if ($opened === false) {
                return null;
            }

            $handle = $opened;
            $partial = $candidate;

            // `0600` AVANT la première écriture : aucun octet du vidage n'est
            // jamais lisible au-delà de l'utilisateur d'abonnement.
            chmod($partial, 0600);

            $credentials = self::writeCredentials($connection);
            $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => self::COMPRESSION_LEVEL]);

            if ($credentials === null || $deflate === false) {
                return null;
            }

            $passes = self::dumpCommands($connection, $credentials);

            foreach ($passes as $command) {
                if (! $this->runPass($command, $handle, $deflate, $diagnostics)) {
                    return null;
                }
            }

            $tail = deflate_add($deflate, '', ZLIB_FINISH);

            if ($tail === false || fwrite($handle, $tail) !== strlen($tail) || ! fflush($handle)) {
                return null;
            }

            fclose($handle);
            $handle = null;

            if (! self::verifies($partial, count($passes))) {
                return null;
            }

            if (! rename($partial, $final)) {
                return null;
            }

            $partial = null;

            return $final;
        } catch (Throwable $exception) {
            $diagnostics[] = $exception->getMessage();

            return null;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($credentials !== null && is_file($credentials)) {
                @unlink($credentials);
            }

            if ($partial !== null && is_file($partial)) {
                @unlink($partial);
            }
        }
    }

    /**
     * Une passe de `mysqldump`, sa sortie compressée au fil de l'eau dans le
     * fichier de l'instantané.
     *
     * **Sortie non retenue** (`quietly()`) : sans elle, Symfony Process garde
     * en plus une copie BRUTE de tout le vidage, dans un tampon qui déborde
     * au-delà d'un mégaoctet dans le répertoire temporaire du système — un
     * vidage en clair hors du répertoire d'instantanés, que l'élagage ne voit
     * jamais et qu'un hook tué laisserait en place. Le rappel reçoit toujours
     * les deux flux ; seule la fin de stderr est gardée, bornée, pour le
     * diagnostic.
     *
     * @param  list<string>  $command
     * @param  resource  $handle
     * @param  list<string>  $diagnostics
     */
    private function runPass(array $command, $handle, DeflateContext $deflate, array &$diagnostics): bool
    {
        $writeFailed = false;
        $errorTail = '';

        $result = Process::timeout(self::DUMP_TIMEOUT_SECONDS)
            ->quietly()
            ->start($command, static function (string $type, string $buffer) use ($handle, $deflate, &$writeFailed, &$errorTail): void {
                if ($type !== 'out') {
                    $errorTail = substr($errorTail.$buffer, -self::ERROR_TAIL_BYTES);

                    return;
                }

                if ($writeFailed) {
                    return;
                }

                $compressed = deflate_add($deflate, $buffer, ZLIB_NO_FLUSH);

                if ($compressed === false || fwrite($handle, $compressed) !== strlen($compressed)) {
                    $writeFailed = true;
                }
            })
            ->wait();

        if ($result->successful() && ! $writeFailed) {
            return true;
        }

        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $errorTail)),
            static fn (string $line): bool => $line !== '',
        ));

        foreach (array_slice($lines, -self::DIAGNOSTIC_LINES) as $line) {
            $diagnostics[] = $line;
        }

        return false;
    }

    /**
     * Les deux passes : structure de toutes les tables, puis données de toutes
     * sauf les sept tables exclues. Le nom de la base est le dernier argument ;
     * le fichier d'options, le premier, comme `mysqldump` l'exige.
     *
     * @param  array{driver: string, host: string, port: string, socket: string, database: string, username: string, password: string, prefix: string}  $connection
     * @return list<list<string>>
     */
    private static function dumpCommands(array $connection, string $credentials): array
    {
        $common = [
            'mysqldump',
            '--defaults-extra-file='.$credentials,
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
        ];

        $ignored = array_map(
            static fn (string $table): string => '--ignore-table='.$connection['database'].'.'.$connection['prefix'].$table,
            self::EXCLUDED_DATA_TABLES,
        );

        return [
            [...$common, '--no-data', $connection['database']],
            [...$common, '--no-create-info', '--skip-triggers', ...$ignored, $connection['database']],
        ];
    }

    /**
     * Le fichier d'options `[client]` de `mysqldump`, créé `0600` par
     * `tempnam()` et supprimé par l'appelant ; `null` s'il n'a pu être écrit.
     *
     * @param  array{driver: string, host: string, port: string, socket: string, database: string, username: string, password: string, prefix: string}  $connection
     */
    private static function writeCredentials(array $connection): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'tf-snapshot-');

        if ($path === false) {
            return null;
        }

        chmod($path, 0600);

        $lines = ['[client]'];

        foreach (['user' => 'username', 'password' => 'password', 'host' => 'host', 'port' => 'port', 'socket' => 'socket'] as $option => $key) {
            if ($connection[$key] !== '') {
                $lines[] = $option.'='.self::optionValue($connection[$key]);
            }
        }

        if (file_put_contents($path, implode("\n", $lines)."\n") === false) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    /**
     * Une valeur de fichier d'options MySQL : entre guillemets, séquences
     * d'échappement de MySQL pour la barre oblique inverse, le guillemet
     * délimiteur et les blancs. Le guillemet DOIT être échappé : l'analyseur
     * suit l'état des guillemets et coupe au premier `#` rencontré hors d'eux,
     * si bien qu'un mot de passe `a"b#c` non échappé serait lu tronqué.
     */
    private static function optionValue(string $value): string
    {
        return '"'.strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]).'"';
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * Chemin lexical normalisé : séparateur `/`, segments `.` et `..` résolus,
     * sans séparateur final ; insensible à la casse sous Windows, où le système
     * de fichiers l'est.
     */
    private static function normalized(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = '';

        if (preg_match('#^([A-Za-z]:)?/#', $path, $match) === 1) {
            $root = $match[0];
            $path = substr($path, strlen($root));
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalized = rtrim($root.implode('/', $segments), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }

    /**
     * `trans()` rend `array|string` ; une ligne de console est une chaîne.
     */
    private function translated(mixed $translated): string
    {
        return is_string($translated) ? $translated : '';
    }
}
