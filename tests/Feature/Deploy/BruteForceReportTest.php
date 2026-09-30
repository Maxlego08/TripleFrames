<?php

use App\Jobs\Ops\ReportBruteForce;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Support\Answers\BruteForceProbe;
use App\Support\Ops\Heartbeat;
use Carbon\CarbonImmutable;
use Database\Factories\GuessFactory;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Logger as Monolog;

/*
|--------------------------------------------------------------------------
| Rapport de force brute — spec 100 § 10.7 et § 15, lot L100-7 (2e temps)
|--------------------------------------------------------------------------
|
| Chaque lundi, sur la file `default`, `ReportBruteForce` exécute la sonde de
| 70 § 13.2 (`BruteForceProbe`, L70-11) avec `K` et la fenêtre de
| `config('ops.brute_force.*')`, et journalise le compte et la fenêtre sur le
| canal `game`, en information : un rapport non alertant, sans aucune donnée
| de joueur. Les manches annulées sont exclues (invariant L1).
|
| Le journal est lu par le VRAI canal `game`, écrit dans un répertoire
| temporaire : un rapport qui partirait sur un autre canal, en avertissement
| ou avec un siège dans son contexte ne passerait pas.
|
*/

beforeEach(function (): void {
    $this->logDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tripleframes-bruteforce-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->logDirectory);

    config(['logging.channels.game.path' => $this->logDirectory.DIRECTORY_SEPARATOR.'game.log']);

    Log::forgetChannel('game');
});

afterEach(function (): void {
    // Le fichier reste ouvert par son gestionnaire : fermé avant d'effacer le
    // répertoire, sans quoi Windows refuse la suppression.
    $logger = Log::channel('game')->getLogger();

    if ($logger instanceof Monolog) {
        $logger->close();
    }

    Log::forgetChannel('game');
    File::deleteDirectory($this->logDirectory);
});

/**
 * Une manche démarrée à `$startedAt`, gagnée par un siège qui a fait
 * `$wrongAttempts` tentatives fausses avant de trouver au palier `$tierIndex`.
 */
function bruteForceReportWin(
    CarbonImmutable $startedAt,
    int $wrongAttempts,
    int $tierIndex = 1,
    bool $viaChoice = false,
    bool $cancelled = false,
): void {
    $round = ($cancelled ? Round::factory()->cancelled() : Round::factory()->completed())
        ->create(['started_at' => $startedAt]);
    $player = Player::factory()->create();

    RoundPlayer::factory()->forRound($round, $player)->locked()->withWrongAttempts($wrongAttempts)->create();

    $guess = Guess::factory()->forRound($round, $player)->atTier($tierIndex);

    ($viaChoice ? $guess->viaChoice() : $guess)->create();
}

/**
 * Les lignes JSON écrites par le canal `game` dans le répertoire du test.
 *
 * @return list<array<string, mixed>>
 */
function bruteForceReportLines(string $directory): array
{
    $lines = [];

    foreach (File::files($directory) as $file) {
        if (! str_starts_with($file->getFilename(), 'game')) {
            continue;
        }

        foreach (preg_split('/\R/', trim((string) file_get_contents($file->getPathname()))) ?: [] as $line) {
            if ($line !== '') {
                $lines[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }
    }

    return $lines;
}

it('programme chaque semaine le rapport de force brute sur la file default', function (): void {
    $job = new ReportBruteForce;

    expect(ReportBruteForce::QUEUE)->toBe(Heartbeat::DEFAULT)->not->toBe(Heartbeat::GAME)
        ->and($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->queue)->toBe(ReportBruteForce::QUEUE)
        ->and($job->connection)->toBeNull();

    // Le planificateur n'est rempli qu'au démarrage du noyau de console.
    Artisan::call('list');

    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => $event instanceof CallbackEvent
            && $event->description === ReportBruteForce::class,
    ));

    // Une seule tâche, le lundi à `ops.brute_force.report_at`, en UTC.
    expect($events)->toHaveCount(1)
        ->and(config('ops.brute_force.report_at'))->toBe('04:10')
        ->and($events[0]->expression)->toBe('10 4 * * 1')
        ->and(config('app.timezone'))->toBe('UTC');

    // Chaque semaine : depuis un mercredi, les deux exécutions suivantes
    // tombent sur deux lundis consécutifs, à 04:10.
    $wednesday = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');

    expect($events[0]->nextRunDate($wednesday)->format('l Y-m-d H:i'))->toBe('Monday 2026-09-28 04:10')
        ->and($events[0]->nextRunDate($wednesday, 1)->format('l Y-m-d H:i'))->toBe('Monday 2026-10-05 04:10');

    // Le planificateur le dépose sur la file `default` de la connexion par
    // défaut, jamais `sync` ni `game` : il est dépilé par le worker `default`.
    Queue::fake();
    $events[0]->run(app());

    Queue::assertPushedOn(ReportBruteForce::QUEUE, ReportBruteForce::class, static fn (ReportBruteForce $pushed): bool => $pushed->connection === null);
    Queue::assertNotPushed(ReportBruteForce::class, static fn (ReportBruteForce $pushed, ?string $queue): bool => $queue === Heartbeat::GAME);
    Queue::assertPushed(ReportBruteForce::class, 1);
});

it('journalise le compte de BruteForceProbe sans aucune donnée de joueur', function (): void {
    // Un lundi, à l'heure du rapport.
    $now = CarbonImmutable::parse('2026-09-28 04:10:00.000', 'UTC');
    $this->travelTo($now);

    // Valeurs de départ de la configuration des sondes (§ 15) : `K` = 10,
    // fenêtre de 7 jours.
    expect(config('ops.brute_force.k'))->toBe(10)
        ->and(config('ops.brute_force.window_days'))->toBe(7);

    $since = $now->subDays(7);
    $inWindow = $now->subDay();

    // Comptées : plus de K tentatives, palier 1, texte libre, dans la fenêtre —
    // y compris une manche démarrée à l'instant exact du début de la fenêtre.
    bruteForceReportWin($since, 11);
    bruteForceReportWin($inWindow, 13);

    // Écartées, une condition à la fois : manche annulée (L1) ; exactement K ;
    // démarrée une milliseconde avant la fenêtre ; palier 2 ; clic QCM.
    bruteForceReportWin($inWindow, 15, cancelled: true);
    bruteForceReportWin($inWindow, 10);
    bruteForceReportWin($since->subMillisecond(), 15);
    bruteForceReportWin($inWindow, 15, tierIndex: 2);
    bruteForceReportWin($inWindow, 15, viaChoice: true);

    expect(app(BruteForceProbe::class)->count(10, $since))->toBe(2);

    /** @var list<array{level: string, message: string}> $logged */
    $logged = [];
    EventFacade::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = ['level' => $event->level, 'message' => $event->message];
    });

    /** @var list<string> $statements */
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    // Par la file réelle (`sync` en test).
    ReportBruteForce::dispatch();

    $lines = bruteForceReportLines($this->logDirectory);

    // Une ligne, sur le canal `game`, en information : le compte de la sonde et
    // la fenêtre, et RIEN d'autre — ni siège, ni pseudo, ni salon, ni manche,
    // ni film.
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['message'])->toBe(ReportBruteForce::LOG_MESSAGE)
        ->and($lines[0]['level_name'])->toBe('INFO')
        ->and($lines[0]['context'])->toBe([
            'k' => 10,
            'window_days' => 7,
            'since' => '2026-09-21T04:10:00.000Z',
            'until' => '2026-09-28T04:10:00.000Z',
            'count' => 2,
        ])
        ->and($lines[0]['extra'])->toBe([]);

    // Non alertant : un seul message, sur aucun autre canal, et aucun
    // avertissement.
    expect($logged)->toBe([['level' => 'info', 'message' => ReportBruteForce::LOG_MESSAGE]]);

    // Lecture seule.
    expect($statements)->not->toBeEmpty();

    foreach ($statements as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }

    // Aucune donnée de joueur, de salon ni de film sous aucune forme, dans
    // tout ce que le canal a écrit.
    $written = implode("\n", array_map(
        static fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()),
        File::files($this->logDirectory),
    ));

    $personal = [GuessFactory::NORMALIZED_ANSWER];

    foreach (Player::query()->get() as $player) {
        array_push($personal, $player->nickname, $player->nickname_normalized, $player->public_id, $player->player_token_hash, (string) $player->active_seat_token);
    }

    foreach (Room::query()->pluck('room_code') as $roomCode) {
        $personal[] = (string) $roomCode;
    }

    foreach (Movie::query()->pluck('title_original') as $title) {
        $personal[] = (string) $title;
    }

    expect(Player::query()->count())->toBe(7);

    foreach ($personal as $value) {
        expect(str_contains($written, $value))->toBeFalse();
        expect(str_contains($written, json_encode($value, JSON_THROW_ON_ERROR)))->toBeFalse();
    }

    // `K` et la fenêtre sont lus dans la configuration, pas dans le job :
    // abaissé d'un cran, `K` fait entrer la manche à exactement dix tentatives…
    config(['ops.brute_force.k' => 9]);
    ReportBruteForce::dispatch();

    // … et la fenêtre élargie d'un jour, la manche démarrée juste avant.
    config(['ops.brute_force.k' => 10, 'ops.brute_force.window_days' => 8]);
    ReportBruteForce::dispatch();

    $lines = bruteForceReportLines($this->logDirectory);

    expect($lines)->toHaveCount(3)
        ->and($lines[1]['context'])->toBe([
            'k' => 9,
            'window_days' => 7,
            'since' => '2026-09-21T04:10:00.000Z',
            'until' => '2026-09-28T04:10:00.000Z',
            'count' => 3,
        ])
        ->and($lines[2]['context'])->toBe([
            'k' => 10,
            'window_days' => 8,
            'since' => '2026-09-20T04:10:00.000Z',
            'until' => '2026-09-28T04:10:00.000Z',
            'count' => 3,
        ]);

    // Une fenêtre vide n'a aucun sens : jamais un compte silencieux, et
    // aucune ligne écrite.
    config(['ops.brute_force.window_days' => 0]);

    expect(fn () => (new ReportBruteForce)->handle(app(BruteForceProbe::class)))
        ->toThrow(InvalidArgumentException::class);

    expect(bruteForceReportLines($this->logDirectory))->toHaveCount(3);
});
