<?php

use App\Console\Commands\BackupSnapshotCommand;
use App\Enums\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| L'élagage des instantanés — spec 100 § 13.1, planifié au § 10.7
|--------------------------------------------------------------------------
|
| Les instantanés portent des données personnelles : leur rétention est
| EXÉCUTÉE chaque jour, jamais seulement déclarée, et elle ne dépasse jamais
| les 30 jours des sauvegardes (10 § 11.1). L'âge se lit dans le NOM du
| fichier, que produit `BackupSnapshotCommand::fileNameFor()`.
|
*/

beforeEach(function (): void {
    $this->snapshotDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tripleframes-prune-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($this->snapshotDirectory);
    config(['backup.snapshot_dir' => $this->snapshotDirectory]);

    $this->travelTo(CarbonImmutable::parse('2026-09-24 02:50:00', 'UTC'));
});

afterEach(function (): void {
    File::deleteDirectory($this->snapshotDirectory);
});

/**
 * Pose un instantané pris il y a `$age`, sous son nom de § 13.1. Son heure
 * de modification est celle d'aujourd'hui : l'élagage ne doit pas la lire.
 */
function backupPruneSnapshot(string $directory, string $age, bool $partial = false): string
{
    $name = BackupSnapshotCommand::fileNameFor(CarbonImmutable::now()->sub($age))
        .($partial ? BackupSnapshotCommand::PARTIAL_SUFFIX : '');

    File::put($directory.DIRECTORY_SEPARATOR.$name, 'vidage fictif');

    return $name;
}

/**
 * @return list<string>
 */
function backupPruneFiles(string $directory): array
{
    $files = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    sort($files);

    return $files;
}

function backupPruneMessage(int $count): string
{
    $line = trans('admin.console.backup.pruned', ['count' => $count], Locale::French->value);

    expect($line)->toBeString()->not->toBe('admin.console.backup.pruned');

    return (string) $line;
}

it('supprime les instantanés plus vieux que la rétention configurée, jamais au-delà de 30 jours', function (): void {
    $directory = $this->snapshotDirectory;

    $fresh = backupPruneSnapshot($directory, '2 days');
    $atLimit = backupPruneSnapshot($directory, '7 days');
    $eightDays = backupPruneSnapshot($directory, '8 days');
    $interrupted = backupPruneSnapshot($directory, '8 days 1 hour', partial: true);
    $twentyNine = backupPruneSnapshot($directory, '29 days');
    $thirtyOne = backupPruneSnapshot($directory, '31 days');

    // Rétention par défaut, 7 jours : tout instantané échu part — y compris le
    // reste `.partial` d'un instantané interrompu, qui porte les mêmes données.
    config(['backup.snapshot_keep_days' => 7]);

    $this->artisan('backup:prune-snapshots')
        ->expectsOutputToContain(backupPruneMessage(4))
        ->assertExitCode(0)
        ->run();

    expect(backupPruneFiles($directory))->toBe(backupPruneSorted([$fresh, $atLimit]))
        ->and(backupPruneFiles($directory))->not->toContain($eightDays, $interrupted, $twentyNine, $thirtyOne);

    // Une rétention réglée au-delà de 30 jours est ramenée à 30 : un
    // instantané de 31 jours part, un de 29 reste.
    $twentyNine = backupPruneSnapshot($directory, '29 days');
    $thirtyOne = backupPruneSnapshot($directory, '31 days');
    $year = backupPruneSnapshot($directory, '400 days');

    config(['backup.snapshot_keep_days' => 90]);

    $this->artisan('backup:prune-snapshots')
        ->expectsOutputToContain(backupPruneMessage(2))
        ->assertExitCode(0)
        ->run();

    expect(backupPruneFiles($directory))->toBe(backupPruneSorted([$fresh, $atLimit, $twentyNine]));

    // Un réglage nul ou illisible n'efface pas l'instantané du déploiement du
    // jour : la rétention ne descend pas sous un jour.
    $today = backupPruneSnapshot($directory, '1 hour');

    foreach ([0, -5, 'abc'] as $keepDays) {
        config(['backup.snapshot_keep_days' => $keepDays]);

        $this->artisan('backup:prune-snapshots')->assertExitCode(0)->run();

        expect(backupPruneFiles($directory))->toContain($today);
    }

    // Le répertoire est résolu comme l'écrivain le résout : une valeur de
    // `.env` à blancs de bord élague là où `backup:snapshot` écrit, au lieu
    // d'annoncer « 0 » en silence.
    $padded = backupPruneSnapshot($directory, '10 days');

    config(['backup.snapshot_keep_days' => 7, 'backup.snapshot_dir' => '  '.$directory.' ']);

    $this->artisan('backup:prune-snapshots')
        ->expectsOutputToContain(backupPruneMessage(1))
        ->assertExitCode(0)
        ->run();

    expect(backupPruneFiles($directory))->not->toContain($padded);

    // Un répertoire absent n'est pas une panne : rien à élaguer.
    config(['backup.snapshot_dir' => $directory.DIRECTORY_SEPARATOR.'absent']);

    $this->artisan('backup:prune-snapshots')
        ->expectsOutputToContain(backupPruneMessage(0))
        ->assertExitCode(0)
        ->run();

    expect($year)->not->toBeIn(backupPruneFiles($directory));
});

it('ne touche aucun autre fichier du répertoire', function (): void {
    $directory = $this->snapshotDirectory;
    $old = CarbonImmutable::now()->subDays(365);
    $oldName = BackupSnapshotCommand::fileNameFor($old);

    $others = [
        // Des fichiers qui ne sont pas des instantanés, fussent-ils vieux.
        'notes.txt',
        'dump-manuel.sql.gz',
        'snapshot-ancien.sql.gz',
        'snapshot-'.$old->format('Ymd\THis').'.sql.gz',
        'autre-'.$oldName,
        $oldName.'.bak',
        strtoupper($oldName),
        // Un nom au motif exact mais à l'instant impossible.
        'snapshot-20251399T999999Z.sql.gz',
    ];

    foreach ($others as $name) {
        File::put($directory.DIRECTORY_SEPARATOR.$name, 'à garder');
        touch($directory.DIRECTORY_SEPARATOR.$name, $old->getTimestamp());
    }

    // Un RÉPERTOIRE qui porte un nom d'instantané échu n'est pas un fichier
    // d'instantané : ni lui ni son contenu ne sont touchés.
    $nested = $directory.DIRECTORY_SEPARATOR.BackupSnapshotCommand::fileNameFor($old->subDay());
    File::ensureDirectoryExists($nested);
    File::put($nested.DIRECTORY_SEPARATOR.'interne.txt', 'à garder');

    // Le seul instantané échu du répertoire.
    $expired = backupPruneSnapshot($directory, '40 days');

    config(['backup.snapshot_keep_days' => 7]);

    $this->artisan('backup:prune-snapshots')
        ->expectsOutputToContain(backupPruneMessage(1))
        ->assertExitCode(0)
        ->run();

    expect(backupPruneFiles($directory))->toBe(backupPruneSorted([...$others, basename($nested)]))
        ->and(backupPruneFiles($directory))->not->toContain($expired)
        ->and(is_file($nested.DIRECTORY_SEPARATOR.'interne.txt'))->toBeTrue();

    foreach ($others as $name) {
        expect(file_get_contents($directory.DIRECTORY_SEPARATOR.$name))->toBe('à garder');
    }
});

it('est planifiée chaque jour à l\'heure UTC de backup.prune_at', function (): void {
    // Le planificateur n'est rempli qu'au démarrage du noyau de console.
    Artisan::call('list');

    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'backup:prune-snapshots'),
    ));

    expect(config('backup.prune_at'))->toBe('02:50')
        ->and($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('50 2 * * *')
        ->and(config('app.timezone'))->toBe('UTC');
});

/**
 * @param  list<string>  $files
 * @return list<string>
 */
function backupPruneSorted(array $files): array
{
    sort($files);

    return $files;
}
