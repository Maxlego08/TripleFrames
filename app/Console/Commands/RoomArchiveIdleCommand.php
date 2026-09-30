<?php

namespace App\Console\Commands;

use App\Jobs\Room\ArchiveIdleRooms;
use App\Support\Room\RoomExpiry;
use Illuminate\Console\Command;

/**
 * `room:archive-idle` — spec 50 § 16.2.
 *
 * Planifiée toutes les {@see RoomExpiry::SWEEP_EVERY_MINUTES} dans
 * `routes/console.php`, elle dépose le balayage des échéances de salon
 * ({@see ArchiveIdleRooms} : archivage anticipé du lobby, périmètre
 * `stale_lobby`, puis archivage à 24 h) sur la file `default`, **jamais
 * `game`** — la file est posée par le job.
 *
 * Le job est unique : un balayage déjà en file, ou en cours, n'est pas
 * doublé, et le dépôt est alors abandonné sans erreur — le passage en
 * attente fera le travail. Aucune sortie : la trace d'un passage est la ligne
 * `purge_run` du périmètre `stale_lobby`, écrite par le job, et la sonde
 * `purge` de `100` la surveille.
 */
class RoomArchiveIdleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'room:archive-idle';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dépose le balayage des échéances de salon (archivage anticipé du lobby, archivage) sur la file default';

    public function handle(): int
    {
        ArchiveIdleRooms::dispatch();

        return self::SUCCESS;
    }
}
