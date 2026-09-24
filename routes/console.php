<?php

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

// Élagage des instantanés de la règle 12 (§ 13.1) : après la purge de
// rétention, avant le tier chaud.
Schedule::command('backup:prune-snapshots')
    ->dailyAt(Config::string('backup.prune_at'));
