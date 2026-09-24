<?php

use App\Jobs\Ops\WorkerHeartbeat;
use App\Jobs\Retention\RunRetentionPurge;
use App\Support\Ops\Heartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Planification d'exploitation — spec 100 § 10.7
|--------------------------------------------------------------------------
|
| Une tâche planifiée Plesk lance `schedule:run` chaque minute, en UTC comme
| tout le serveur. La règle 8 (« pas de scheduler minute ») vise le chrono de
| jeu : aucun instant de partie n'est jamais décidé ici, qui ne porte que
| l'exploitation.
|
*/

// Battements des workers (§ 15) : chacun part SUR la file qu'il mesure et
// n'écrit l'instant qu'une fois exécuté par le worker qui la dépile. Si le
// planificateur s'arrête, les battements vieillissent et la sonde alerte :
// il est surveillé par la même mesure (§ 10.7).
Schedule::job(new WorkerHeartbeat(Heartbeat::GAME))->everyThirtySeconds();
Schedule::job(new WorkerHeartbeat(Heartbeat::DEFAULT))->everyMinute();

// Purge de rétention (§ 14) : chaque jour, sur la file `default`, jamais
// `game` (la file est posée par le job). Avant l'élagage des instantanés et
// le tier chaud, pour qu'une sauvegarde ne capture jamais ce qui aurait dû
// être purgé la veille.
Schedule::job(new RunRetentionPurge)
    ->dailyAt(Config::string('ops.purge.daily_at'));

// Élagage des instantanés de la règle 12 (§ 13.1) : après la purge de
// rétention, avant le tier chaud.
Schedule::command('backup:prune-snapshots')
    ->dailyAt(Config::string('backup.prune_at'));
