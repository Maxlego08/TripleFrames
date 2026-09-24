<?php

use App\Jobs\Ops\WorkerHeartbeat;
use App\Support\Ops\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Battements des workers — spec 100 § 10.7 et § 15
|--------------------------------------------------------------------------
|
| Le worker se mesure par le battement de SA file : `WorkerHeartbeat`,
| planifié toutes les 30 s sur `game` et chaque minute sur `default`, écrit
| `ops:heartbeat:<file>` à l'instant où le worker qui dépile cette file
| l'exécute, jamais à celui où le planificateur l'y dépose.
|
*/

/**
 * Les tâches planifiées de battement, indexées par la file sur laquelle
 * chacune dépose son job (lue en l'exécutant sous une file simulée).
 *
 * @return array<string, Event>
 */
function workerHeartbeatEvents(): array
{
    // Le planificateur n'est rempli qu'au démarrage du noyau de console.
    Artisan::call('list');

    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => $event instanceof CallbackEvent
            && str_starts_with((string) $event->description, WorkerHeartbeat::class),
    ));

    $byQueue = [];

    foreach ($events as $event) {
        Queue::fake();

        $event->run(app());

        $pushed = Queue::pushedJobs()[WorkerHeartbeat::class] ?? [];

        expect($pushed)->toHaveCount(1);

        $job = $pushed[0]['job'];
        expect($job)->toBeInstanceOf(WorkerHeartbeat::class)
            ->and($pushed[0]['queue'])->toBe($job->watchedQueue);

        $byQueue[$job->watchedQueue] = $event;
    }

    ksort($byQueue);

    return $byQueue;
}

it('programme le battement de la file game et celui de la file default', function (): void {
    $events = workerHeartbeatEvents();

    expect(array_keys($events))->toBe([Heartbeat::DEFAULT, Heartbeat::GAME]);

    // `game` : toutes les 30 secondes, sous-minute.
    expect($events[Heartbeat::GAME]->expression)->toBe('* * * * *')
        ->and($events[Heartbeat::GAME]->isRepeatable())->toBeTrue()
        ->and($events[Heartbeat::GAME]->repeatSeconds)->toBe(30);

    // `default` : chaque minute.
    expect($events[Heartbeat::DEFAULT]->expression)->toBe('* * * * *')
        ->and($events[Heartbeat::DEFAULT]->isRepeatable())->toBeFalse();

    // Chacune part sur la connexion de file par défaut, jamais `sync` : le
    // battement doit être dépilé par un worker.
    foreach ($events as $queue => $event) {
        Queue::fake();
        $event->run(app());

        Queue::assertPushedOn($queue, WorkerHeartbeat::class, static fn (WorkerHeartbeat $job): bool => $job->connection === null);
    }

    // Heures du planificateur en UTC, comme tout le serveur (§ 10.7).
    expect(config('app.timezone'))->toBe('UTC');
});

it('écrit l\'instant du battement depuis le worker qui l\'exécute', function (): void {
    $scheduledAt = CarbonImmutable::parse('2026-09-24 12:00:00.250', 'UTC');

    // Par la file réelle (`sync` en test), le battement est écrit à
    // l'exécution, sur la file qu'il mesure.
    $this->travelTo($scheduledAt->subMinutes(5));

    WorkerHeartbeat::dispatch(Heartbeat::GAME);

    expect(Heartbeat::lastBeatAt(Heartbeat::GAME)?->getTimestampMs())->toBe($scheduledAt->subMinutes(5)->getTimestampMs())
        ->and(Heartbeat::lastBeatAt(Heartbeat::DEFAULT))->toBeNull()
        ->and((new WorkerHeartbeat(Heartbeat::GAME))->queue)->toBe(Heartbeat::GAME)
        ->and((new WorkerHeartbeat(Heartbeat::DEFAULT))->queue)->toBe(Heartbeat::DEFAULT);

    cache()->forget(Heartbeat::key(Heartbeat::GAME));

    $this->travelTo($scheduledAt);

    $events = workerHeartbeatEvents();

    foreach ([Heartbeat::GAME, Heartbeat::DEFAULT] as $queue) {
        $this->travelTo($scheduledAt);

        // Le planificateur dépose le job ; il n'écrit rien lui-même.
        Queue::fake();
        $events[$queue]->run(app());

        expect(Heartbeat::lastBeatAt($queue))->toBeNull();

        /** @var WorkerHeartbeat $job */
        $job = Queue::pushedJobs()[WorkerHeartbeat::class][0]['job'];

        // Le worker le dépile sept secondes plus tard : c'est cet instant,
        // à la milliseconde, que porte le battement.
        $executedAt = $scheduledAt->addSeconds(7)->addMilliseconds(125);
        $this->travelTo($executedAt);

        $job->handle();

        expect(Heartbeat::lastBeatAt($queue)?->getTimestampMs())->toBe($executedAt->getTimestampMs())
            ->and(cache()->get(Heartbeat::key($queue)))->toBe($executedAt->getTimestampMs());
    }

    expect(Heartbeat::key(Heartbeat::GAME))->toBe('ops:heartbeat:game')
        ->and(Heartbeat::key(Heartbeat::DEFAULT))->toBe('ops:heartbeat:default');
});
