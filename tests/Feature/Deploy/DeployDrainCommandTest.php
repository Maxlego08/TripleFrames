<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\DrainPhase;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Support\Deploy\DeployDrain;
use App\Support\Deploy\DrainAlreadyRunning;
use App\Support\Game\GamesInProgress;
use App\ValueObjects\Deploy\DrainState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;

/*
|--------------------------------------------------------------------------
| `deploy:drain` — spec 100 § 11.3, contrat C18-bis (lot L100-5)
|--------------------------------------------------------------------------
|
| Le drainage borné qui précède tout déploiement une fois le moteur en
| production : drapeau posé (les lancements sont refusés), `game:reschedule`
| AVANT d'attendre, relevé des parties en cours toutes les `poll_seconds`,
| fenêtre libre après deux relevés nuls consécutifs, abandon à l'échéance.
|
| Le sommeil est simulé et l'horloge le suit (`Sleep::fake(syncWithCarbon:
| true)`) : chaque relevé tombe à son instant réel, et le TTL du drapeau
| s'écoule avec lui dans le cache `array`, qui lit la même horloge. Les
| parties changent d'état ENTRE deux relevés, par les rappels du sommeil
| simulé — jamais par un appel du test au milieu de la commande.
|
| Durées relues dans `config/deploy.php` et `EngineConstants` : aucune valeur
| de jeu en littéral.
|
| L'horloge porte des microsecondes non rondes, comme en production
| (`$this->clock`) ; le drapeau est à la milliseconde (`$this->start`, début
| du drainage tel que le cache le relit). Une horloge ronde à la milliseconde
| masquerait tout écart de précision entre l'état en mémoire et le drapeau
| relu.
|
*/

beforeEach(function (): void {
    Sleep::fake(syncWithCarbon: true);

    $this->clock = CarbonImmutable::parse('2026-09-26 14:00:00.250123');
    $this->start = $this->clock->startOfMillisecond();
    Date::setTestNow($this->clock);
});

/** Un message de la console du drainage, tel que le porteur le lit. */
function deployDrainText(string $key, array $replace = []): string
{
    $text = trans($key, $replace, Locale::French->value);

    expect($text)->toBeString()->not->toBe($key);

    return (string) $text;
}

/** Un instant tel que la console l'écrit : ISO-8601 UTC, à la seconde. */
function deployDrainInstant(CarbonImmutable $instant): string
{
    return $instant->utc()->toIso8601ZuluString();
}

function deployDrainPoll(): int
{
    return config()->integer('deploy.poll_seconds');
}

/**
 * Enregistre chaque effacement demandé de la clé du drapeau : la trace d'un
 * `release()`, que le TTL seul ne laisserait pas.
 *
 * @return ArrayObject<int, string>
 */
function deployDrainForgotten(): ArrayObject
{
    /** @var ArrayObject<int, string> $forgotten */
    $forgotten = new ArrayObject;
    $key = config()->string('deploy.cache_key');

    Event::listen(ForgettingKey::class, static function (ForgettingKey $event) use ($forgotten, $key): void {
        if ($event->key === $key) {
            $forgotten[] = $event->key;
        }
    });

    return $forgotten;
}

/** Clôt une partie par l'action de gel, seul écrivain de `ended_at`. */
function deployDrainEnd(Game $game): void
{
    app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, Date::now()->toImmutable());
}

/**
 * Remplace `game:reschedule` par un témoin qui note, à l'instant de son
 * appel, le drapeau en vigueur et le nombre d'attentes déjà faites.
 *
 * @return ArrayObject<int, array{?DrainState, int}>
 */
function deployDrainRescheduleWitness(ArrayObject $sleeps, int $exitCode = Command::SUCCESS): ArrayObject
{
    /** @var ArrayObject<int, array{?DrainState, int}> $calls */
    $calls = new ArrayObject;

    $witness = new class($calls, $sleeps, $exitCode) extends Command
    {
        protected $signature = 'game:reschedule';

        public function __construct(
            private readonly ArrayObject $calls,
            private readonly ArrayObject $sleeps,
            private readonly int $exitCode,
        ) {
            parent::__construct();
        }

        public function handle(): int
        {
            $this->calls[] = [app(DeployDrain::class)->state(), count($this->sleeps)];

            return $this->exitCode;
        }
    };

    app(Kernel::class)->registerCommand($witness);

    return $calls;
}

/**
 * Compte les attentes du drainage, dans un tableau partagé avec le test.
 *
 * @return ArrayObject<int, CarbonInterval>
 */
function deployDrainSleeps(): ArrayObject
{
    /** @var ArrayObject<int, CarbonInterval> $sleeps */
    $sleeps = new ArrayObject;

    Sleep::whenFakingSleep(static function (CarbonInterval $duration) use ($sleeps): void {
        $sleeps[] = $duration;
    });

    return $sleeps;
}

it('ouvre une fenêtre libre après deux relevés consécutifs sans partie en cours', function (): void {
    $first = Game::factory()->create();
    $late = null;
    $phases = [];
    $sleeps = deployDrainSleeps();

    // Relevés : 1 partie ; 0 ; 1 (un lancement validé juste avant la pose du
    // drapeau, la course du § 11.3) ; 0 ; 0 → fenêtre. Un seul relevé nul ne
    // suffit jamais.
    Sleep::whenFakingSleep(static function () use ($sleeps, $first, &$late, &$phases): void {
        $phases[] = app(DeployDrain::class)->state()?->phase;

        match (count($sleeps)) {
            1 => deployDrainEnd($first),
            2 => $late = Game::factory()->solo()->create(),
            3 => $late instanceof Game ? deployDrainEnd($late) : null,
            default => null,
        };
    });

    $windowOpensAt = $this->start->addSeconds(4 * deployDrainPoll());
    $windowEndsAt = $windowOpensAt->addMinutes(config()->integer('deploy.window_minutes'));

    $this->artisan('deploy:drain')
        ->expectsOutputToContain(deployDrainText('admin.console.deploy.started'))
        ->expectsOutputToContain(deployDrainText('admin.console.deploy.waiting', ['count' => 1]))
        ->expectsOutputToContain(deployDrainText('admin.console.deploy.window_open', ['until' => deployDrainInstant($windowEndsAt)]))
        ->assertExitCode(0)
        ->run();

    // Quatre attentes d'un relevé chacune, le drapeau en phase `draining`
    // pendant toutes : la fenêtre ne s'ouvre qu'au cinquième relevé.
    Sleep::assertSequence(array_fill(0, 4, Sleep::for(deployDrainPoll())->seconds()));

    expect($phases)->toBe(array_fill(0, 4, DrainPhase::Draining));

    // Fenêtre : ouverte au dernier relevé, pour `window_minutes`, l'instant de
    // début du drainage conservé.
    $state = app(DeployDrain::class)->state();

    expect($state?->phase)->toBe(DrainPhase::Window)
        ->and($state?->startedAt->equalTo($this->start))->toBeTrue()
        ->and($state?->expiresAt->equalTo($windowEndsAt))->toBeTrue()
        ->and(GamesInProgress::count())->toBe(0);

    // Le drapeau de la fenêtre expire seul, par son TTL, à son échéance.
    $key = config()->string('deploy.cache_key');

    $this->travelTo($windowEndsAt->subMillisecond());

    expect(app(DeployDrain::class)->isDraining())->toBeTrue()
        ->and(Cache::get($key))->not->toBeNull();

    $this->travelTo($windowEndsAt);

    expect(app(DeployDrain::class)->isDraining())->toBeFalse()
        ->and(Cache::get($key))->toBeNull();

    // `--window` l'emporte sur la configuration.
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo($this->clock);

    $this->artisan('deploy:drain', ['--window' => 45])->assertExitCode(0)->run();

    Sleep::assertSleptTimes(1);

    expect(app(DeployDrain::class)->state()?->expiresAt->equalTo($this->start->addSeconds(deployDrainPoll())->addMinutes(45)))->toBeTrue();
});

it("abandonne et lève le drapeau quand l'échéance passe avec une partie en cours", function (): void {
    $game = Game::factory()->create();
    $forgotten = deployDrainForgotten();
    $timeout = 1;

    $this->artisan('deploy:drain', ['--timeout' => $timeout])
        ->expectsOutputToContain(deployDrainText('admin.console.deploy.waiting', ['count' => 1]))
        ->expectsOutputToContain(deployDrainText('admin.console.deploy.abandoned'))
        ->doesntExpectOutputToContain(deployDrainText('admin.console.deploy.window_open', ['until' => deployDrainInstant($this->start->addMinutes($timeout)->addMinutes(config()->integer('deploy.window_minutes')))]))
        ->assertExitCode(1)
        ->run();

    // Un relevé par `poll_seconds` jusqu'à l'échéance, et aucun au-delà.
    Sleep::assertSleptTimes(intdiv($timeout * 60, deployDrainPoll()));

    expect(Date::now()->equalTo($this->clock->addMinutes($timeout)))->toBeTrue();

    // Le drapeau est levé par l'abandon lui-même, pas seulement par son TTL.
    expect($forgotten->getArrayCopy())->toBe([config()->string('deploy.cache_key')])
        ->and(app(DeployDrain::class)->isDraining())->toBeFalse()
        ->and(Cache::get(config()->string('deploy.cache_key')))->toBeNull();

    // Aucune partie en cours n'est coupée ni retardée.
    expect($game->refresh()->status)->toBe(GameStatus::Running)
        ->and($game->ended_at)->toBeNull();

    // Plusieurs parties : le relevé les compte toutes, solo compris.
    Sleep::fake(syncWithCarbon: true);
    Game::factory()->solo()->paused()->create();

    $this->artisan('deploy:drain', ['--timeout' => $timeout])
        ->expectsOutputToContain(deployDrainText('admin.console.deploy.waiting', ['count' => 2]))
        ->assertExitCode(1)
        ->run();
});

it('laisse le drapeau expirer de lui-même si la commande meurt', function (): void {
    Game::factory()->create();
    $forgotten = deployDrainForgotten();
    $timeout = 10;

    // La commande meurt pendant sa première attente (processus tué, session
    // SSH coupée) : aucun code ne s'exécute plus après.
    Sleep::whenFakingSleep(static function (): void {
        throw new RuntimeException('processus tué');
    });

    expect(fn () => $this->artisan('deploy:drain', ['--timeout' => $timeout])->run())
        ->toThrow(RuntimeException::class, 'processus tué');

    // Le drapeau tient : les lancements restent refusés jusqu'à l'échéance…
    $expiresAt = $this->start->addMinutes($timeout);
    $key = config()->string('deploy.cache_key');

    expect(app(DeployDrain::class)->state()?->phase)->toBe(DrainPhase::Draining)
        ->and(app(DeployDrain::class)->state()?->expiresAt->equalTo($expiresAt))->toBeTrue();

    $this->travelTo($expiresAt->subMillisecond());

    expect(app(DeployDrain::class)->isDraining())->toBeTrue()
        ->and(Cache::get($key))->not->toBeNull();

    // … puis l'entrée du cache expire d'elle-même (TTL), sans que personne ne
    // la lève : exactement la sémantique d'un abandon.
    $this->travelTo($expiresAt);

    expect(app(DeployDrain::class)->isDraining())->toBeFalse()
        ->and(Cache::get($key))->toBeNull()
        ->and($forgotten->getArrayCopy())->toBe([]);

    // Un nouveau drainage peut alors partir.
    Sleep::fake(syncWithCarbon: true);
    Game::query()->inProgress()->get()->each(static fn (Game $game) => deployDrainEnd($game));

    $this->artisan('deploy:drain')->assertExitCode(0)->run();
});

it('refuse de lancer un second drainage', function (): void {
    $drain = app(DeployDrain::class);
    $sleeps = deployDrainSleeps();
    $calls = deployDrainRescheduleWitness($sleeps);
    $forgotten = deployDrainForgotten();

    // Un drainage en cours, dans une autre session.
    $first = $drain->start(DeployDrain::defaultTimeoutMinutes());

    $this->artisan('deploy:drain', ['--timeout' => 5])
        ->expectsOutputToContain(deployDrainText('admin.console.deploy.already_running', [
            'phase' => deployDrainText('admin.console.deploy.phase.draining'),
            'until' => deployDrainInstant($first->expiresAt),
        ]))
        ->doesntExpectOutputToContain(deployDrainText('admin.console.deploy.started'))
        ->assertExitCode(2)
        ->run();

    // Rien n'a été modifié : même drapeau, aucune attente, aucun
    // `game:reschedule`, aucun effacement.
    expect($drain->state())->toEqual($first)
        ->and($calls)->toHaveCount(0)
        ->and($sleeps)->toHaveCount(0)
        ->and($forgotten->getArrayCopy())->toBe([]);

    // Pendant la fenêtre libre aussi : la phase est dite en français.
    $window = $drain->openWindow(config()->integer('deploy.window_minutes'));

    $this->artisan('deploy:drain')
        ->expectsOutputToContain(deployDrainText('admin.console.deploy.already_running', [
            'phase' => deployDrainText('admin.console.deploy.phase.window'),
            'until' => deployDrainInstant($window->expiresAt),
        ]))
        ->assertExitCode(2)
        ->run();

    expect($drain->state())->toEqual($window)
        ->and($calls)->toHaveCount(0);

    // `start()` lève l'exception nommée, qui porte le drapeau trouvé.
    try {
        $drain->start(5);
        $this->fail('Un second drainage a été posé.');
    } catch (DrainAlreadyRunning $running) {
        expect($running->state)->toEqual($window);
    }

    expect($drain->state())->toEqual($window);
});

it("appelle game:reschedule avant d'attendre", function (): void {
    // Une vraie partie dont la manche 1 court, dont Redis a perdu les jobs.
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game);

    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));
    EngineFixtures::openTier($first, 1);

    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $jobsAtFirstSleep = null;

    Sleep::whenFakingSleep(static function () use (&$jobsAtFirstSleep, $game): void {
        $jobsAtFirstSleep ??= Queue::pushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->gameId === $game->id)->count();
    });

    $this->artisan('deploy:drain', ['--timeout' => 1])->assertExitCode(1)->run();

    // Le job perdu est redonné par `game:reschedule` AVANT la première
    // attente : la partie retrouve sa terminaison bornée au lieu de tenir le
    // drainage jusqu'à l'échéance.
    expect($jobsAtFirstSleep)->toBe(1);

    // Ordre du § 11.3 : drapeau posé, PUIS `game:reschedule`, PUIS le premier
    // relevé et la première attente — un appel, un seul.
    Sleep::fake(syncWithCarbon: true);
    deployDrainEnd($game);

    $sleeps = deployDrainSleeps();
    $calls = deployDrainRescheduleWitness($sleeps);

    $this->artisan('deploy:drain')->assertExitCode(0)->run();

    expect($calls)->toHaveCount(1);

    [$flag, $sleptBefore] = $calls[0];

    expect($flag?->phase)->toBe(DrainPhase::Draining)
        ->and($sleptBefore)->toBe(0)
        ->and($sleeps)->toHaveCount(1);
});

it("dérive sa borne par défaut de maxNaturalDurationMs, du budget des pauses manuelles et d'une marge qui couvre une pause", function (): void {
    $margin = config()->integer('deploy.drain_margin_minutes');
    $expected = static fn (): int => (int) ceil((GamesInProgress::maxNaturalDurationMs() + GamesInProgress::manualPauseBudgetMs()) / 60_000) + $margin;

    // Le budget des pauses manuelles (D64 du 07/10) couvre attente et
    // décomptes de reprise de toutes les pauses manuelles d'une partie.
    expect(GamesInProgress::manualPauseBudgetMs())->toBe(EngineConstants::pauseTimeoutMs());

    // La marge couvre une pause : l'attente, PUIS le décompte de reprise, que
    // `total_paused_ms` ne compte pas (§ 11.3, § 11.8 précision (5)).
    expect($margin * 60_000)->toBeGreaterThanOrEqual(EngineConstants::pauseTimeoutMs() + EngineConstants::launchCountdownMs());

    // Dérivée du prédicat de 60, jamais d'un produit écrit à la main.
    $default = DeployDrain::defaultTimeoutMinutes();

    expect($default)->toBe($expected())
        ->and($default * 60_000)->toBeGreaterThanOrEqual(GamesInProgress::maxNaturalDurationMs() + GamesInProgress::manualPauseBudgetMs() + EngineConstants::pauseTimeoutMs() + EngineConstants::launchCountdownMs());

    // Elle suit la durée naturelle : une réserve de tirage plus large, puis un
    // décompte de lancement plus long, allongent la borne.
    platformLimitsConfigure(['draw_substitute_margin' => PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN + 4]);

    $wider = DeployDrain::defaultTimeoutMinutes();

    expect($wider)->toBe($expected())
        ->and($wider)->toBeGreaterThan($default);

    engineConstantsConfigure(['launch_countdown_ms' => EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS + 1500]);

    expect(DeployDrain::defaultTimeoutMinutes())->toBe($expected())
        ->and(GamesInProgress::maxNaturalDurationMs() + GamesInProgress::manualPauseBudgetMs())->toBeGreaterThan(($wider - $margin - 1) * 60_000);

    platformLimitsConfigure(['draw_substitute_margin' => PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN]);
    engineConstantsConfigure(['launch_countdown_ms' => EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS]);

    // `deploy:drain` sans `--timeout` ni `DEPLOY_DRAIN_TIMEOUT_MINUTES` pose
    // cette échéance.
    $sleeps = deployDrainSleeps();
    $calls = deployDrainRescheduleWitness($sleeps);

    config(['deploy.drain_timeout_minutes' => null]);

    $this->artisan('deploy:drain')->assertExitCode(0)->run();

    expect($calls[0][0]?->expiresAt->equalTo($this->start->addMinutes($expected())))->toBeTrue();

    // `DEPLOY_DRAIN_TIMEOUT_MINUTES` l'emporte sur le défaut, et `--timeout`
    // sur les deux.
    foreach ([[['deploy.drain_timeout_minutes' => '120'], [], 120], [['deploy.drain_timeout_minutes' => '120'], ['--timeout' => 7], 7]] as [$config, $options, $minutes]) {
        app(DeployDrain::class)->release();
        $this->travelTo($this->clock);
        config($config);

        $this->artisan('deploy:drain', $options)->assertExitCode(0)->run();

        $call = $calls[count($calls) - 1];

        expect($call[0]?->expiresAt->equalTo($this->start->addMinutes($minutes)))->toBeTrue();
    }
});

it("refuse une durée qui n'est pas un entier de minutes, sans rien poser", function (): void {
    $sleeps = deployDrainSleeps();
    $calls = deployDrainRescheduleWitness($sleeps);

    $cases = [
        '--timeout' => [['--timeout' => '0'], []],
        '--window' => [['--window' => 'trente'], []],
        'DEPLOY_DRAIN_TIMEOUT_MINUTES' => [[], ['deploy.drain_timeout_minutes' => '1.5']],
        'DEPLOY_WINDOW_MINUTES' => [[], ['deploy.window_minutes' => '-3']],
    ];

    foreach ($cases as $source => [$options, $config]) {
        config(['deploy.drain_timeout_minutes' => null, 'deploy.window_minutes' => 30, ...$config]);

        $this->artisan('deploy:drain', $options)
            ->expectsOutputToContain(deployDrainText('admin.console.deploy.invalid_minutes', ['option' => $source]))
            ->assertExitCode(2)
            ->run();

        expect(app(DeployDrain::class)->state())->toBeNull($source);
    }

    // Par la variable d'environnement elle-même, à travers `config/deploy.php` :
    // `0` est refusé, jamais remplacé en silence par le défaut ; seul le vide
    // vaut « par défaut ».
    $fromEnvironment = static function (string $variable, string $value): array {
        $had = array_key_exists($variable, $_SERVER);
        $previous = $_SERVER[$variable] ?? null;
        $_SERVER[$variable] = $value;

        try {
            return require config_path('deploy.php');
        } finally {
            if ($had) {
                $_SERVER[$variable] = $previous;
            } else {
                unset($_SERVER[$variable]);
            }
        }
    };

    expect($fromEnvironment('DEPLOY_DRAIN_TIMEOUT_MINUTES', '')['drain_timeout_minutes'])->toBeNull()
        ->and($fromEnvironment('DEPLOY_WINDOW_MINUTES', '')['window_minutes'])->toBe(30);

    foreach (['DEPLOY_DRAIN_TIMEOUT_MINUTES', 'DEPLOY_WINDOW_MINUTES'] as $variable) {
        config(['deploy' => $fromEnvironment($variable, '0')]);

        $this->artisan('deploy:drain')
            ->expectsOutputToContain(deployDrainText('admin.console.deploy.invalid_minutes', ['option' => $variable]))
            ->assertExitCode(2)
            ->run();

        expect(app(DeployDrain::class)->state())->toBeNull($variable);
    }

    expect($calls)->toHaveCount(0)
        ->and($sleeps)->toHaveCount(0);
});

it('abandonne sans rien lever quand son drapeau est levé ailleurs', function (): void {
    Game::factory()->create();
    $forgotten = deployDrainForgotten();
    $sleeps = deployDrainSleeps();
    $other = null;

    // Pendant la première attente, le porteur lève le drapeau dans une autre
    // session puis relance un drainage : le premier ne doit ni ouvrir de
    // fenêtre ni lever le drapeau du second.
    Sleep::whenFakingSleep(static function () use ($sleeps, &$other): void {
        if (count($sleeps) === 1) {
            app(DeployDrain::class)->release();
            Date::setTestNow(Date::now()->addMillisecond());
            $other = app(DeployDrain::class)->start(DeployDrain::defaultTimeoutMinutes());
        }
    });

    $this->artisan('deploy:drain')
        ->expectsOutputToContain(deployDrainText('admin.console.deploy.abandoned'))
        ->assertExitCode(1)
        ->run();

    expect($sleeps)->toHaveCount(1)
        ->and(app(DeployDrain::class)->state())->toEqual($other)
        ->and($forgotten->getArrayCopy())->toHaveCount(1);
});

it("n'efface pas le drapeau d'un autre drainage posé entre l'échéance et le réveil", function (): void {
    Game::factory()->create();
    $forgotten = deployDrainForgotten();
    $sleeps = deployDrainSleeps();
    $timeout = 1;
    $lastSleep = intdiv($timeout * 60, deployDrainPoll());
    $other = null;

    // La dernière attente atteint l'échéance : le drapeau du premier drainage
    // est échu. Avant son réveil, le porteur en lance un second ailleurs
    // (coupure SSH, nouvelle session) : l'abandon du premier ne doit pas
    // rouvrir les lancements en levant le drapeau du second.
    Sleep::whenFakingSleep(static function () use ($sleeps, $lastSleep, &$other): void {
        if (count($sleeps) === $lastSleep) {
            expect(app(DeployDrain::class)->state())->toBeNull();

            $other = app(DeployDrain::class)->start(DeployDrain::defaultTimeoutMinutes());
        }
    });

    $this->artisan('deploy:drain', ['--timeout' => $timeout])
        ->expectsOutputToContain(deployDrainText('admin.console.deploy.abandoned'))
        ->assertExitCode(1)
        ->run();

    expect($sleeps)->toHaveCount($lastSleep)
        ->and($other)->toBeInstanceOf(DrainState::class)
        ->and(app(DeployDrain::class)->state())->toEqual($other)
        ->and(app(DeployDrain::class)->isDraining())->toBeTrue()
        ->and($forgotten->getArrayCopy())->toBe([]);
});

it('continue le drainage quand game:reschedule rapporte une partie en échec', function (): void {
    Log::spy();

    $sleeps = deployDrainSleeps();
    $calls = deployDrainRescheduleWitness($sleeps, Command::FAILURE);

    $this->artisan('deploy:drain')->assertExitCode(0)->run();

    expect($calls)->toHaveCount(1)
        ->and(app(DeployDrain::class)->state()?->phase)->toBe(DrainPhase::Window);

    Log::shouldHaveReceived('warning')->once();
});

it("relit le drapeau à l'identique et tient pour absent un drapeau illisible", function (): void {
    $state = new DrainState(DrainPhase::Window, $this->start, $this->start->addMinutes(30));

    expect($state->toCache())->toBe([
        'phase' => 'window',
        'startedAt' => '2026-09-26T14:00:00.250Z',
        'expiresAt' => '2026-09-26T14:30:00.250Z',
    ])->and(DrainState::fromCache($state->toCache()))->toEqual($state);

    foreach ([
        null,
        'draining',
        [],
        ['phase' => 'paused', 'startedAt' => '2026-09-26T14:00:00.250Z', 'expiresAt' => '2026-09-26T14:30:00.250Z'],
        ['phase' => 'window', 'startedAt' => '2026-09-26 14:00:00', 'expiresAt' => '2026-09-26T14:30:00.250Z'],
        ['phase' => 'window', 'startedAt' => '2026-02-30T14:00:00.250Z', 'expiresAt' => '2026-09-26T14:30:00.250Z'],
        ['phase' => 'window', 'startedAt' => '2026-09-26T14:00:00.250Z', 'expiresAt' => "2026-09-26T14:30:00.250Z\n"],
        ['phase' => 'window', 'startedAt' => '2026-09-26T14:00:00.250Z'],
    ] as $raw) {
        expect(DrainState::fromCache($raw))->toBeNull(json_encode($raw) ?: '');
    }

    // TTL : secondes entières, arrondies au-dessus, au moins une.
    expect($state->ttlSeconds($this->start))->toBe(30 * 60)
        ->and($state->ttlSeconds($this->start->addMinutes(30)->subMillisecond()))->toBe(1)
        ->and($state->ttlSeconds($this->start->addHour()))->toBe(1);

    // Un drapeau échu ne vaut plus rien, même si son entrée survit à son
    // échéance (TTL arrondi à la seconde d'un autre magasin).
    $expired = new DrainState(DrainPhase::Window, $this->start->subHour(), $this->start->subMillisecond());
    Cache::put(config()->string('deploy.cache_key'), $expired->toCache(), 600);

    expect(app(DeployDrain::class)->state())->toBeNull()
        ->and(app(DeployDrain::class)->isDraining())->toBeFalse();

    // Un drapeau illisible ne bloque rien, et un drainage le remplace.
    Cache::put(config()->string('deploy.cache_key'), ['phase' => 'window'], 600);

    expect(app(DeployDrain::class)->isDraining())->toBeFalse()
        ->and(app(DeployDrain::class)->start(5)->phase)->toBe(DrainPhase::Draining)
        ->and(app(DeployDrain::class)->state()?->phase)->toBe(DrainPhase::Draining);
});
