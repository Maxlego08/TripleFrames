<?php

namespace App\Jobs\Ops;

use App\Support\Ops\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Le battement d'une file — spec 100 § 10.7 et § 15.
 *
 * Planifié toutes les 30 s sur `game` et chaque minute sur `default`
 * (`routes/console.php`), il part SUR la file qu'il mesure et n'écrit
 * l'instant qu'à son exécution : c'est le worker qui dépile cette file qui
 * signe le battement, jamais le planificateur qui l'a déposé. Une file
 * arrêtée, bloquée ou en retard de plus que le seuil de sa sonde se voit donc
 * par un battement qui vieillit.
 *
 * Un seul essai : un battement perdu est remplacé par le suivant, et un
 * rejeu écrirait de toute façon l'instant de son exécution. Le job ne touche
 * que le cache ; il ne décide d'aucun instant de partie (règle 8).
 */
final class WorkerHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  string  $watchedQueue  la file mesurée, qui est aussi celle du job
     */
    public function __construct(public readonly string $watchedQueue)
    {
        $this->onQueue($watchedQueue);
    }

    public function handle(): void
    {
        Heartbeat::record($this->watchedQueue, CarbonImmutable::now());
    }

    /** Nom lu par `schedule:list` et par le journal de la file : la file y figure. */
    public function displayName(): string
    {
        return self::class." ({$this->watchedQueue})";
    }
}
