<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\DrainPhase;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Models\Game;
use App\Models\Room;
use App\Support\Deploy\DeployDrain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/*
|--------------------------------------------------------------------------
| `deploy:guard` — spec 100 § 11.3 et § 11.5 (étape 3 du hook), contrat C18-bis
|--------------------------------------------------------------------------
|
| Code 0 si et seulement si aucune partie n'est en cours ET la phase est
| `window` ; code 1 si une partie est en cours ; code 2 hors fenêtre. Dans le
| hook, un code non nul arrête tout avant l'instantané et les migrations. La
| garde ne fait que lire : ni le drapeau ni une partie ne changent.
|
*/

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-09-26 14:00:00.250'));
});

/** Un message de la console du drainage, tel que le porteur le lit. */
function deployGuardText(string $key, array $replace = []): string
{
    $text = trans($key, $replace, Locale::French->value);

    expect($text)->toBeString()->not->toBe($key);

    return (string) $text;
}

/** Ouvre une fenêtre libre comme le ferait `deploy:drain`. */
function deployGuardOpenWindow(): void
{
    $drain = app(DeployDrain::class);
    $drain->start(DeployDrain::defaultTimeoutMinutes());
    $drain->openWindow(config()->integer('deploy.window_minutes'));

    expect($drain->state()?->phase)->toBe(DrainPhase::Window);
}

it("échoue tant qu'une partie est en cours", function (): void {
    deployGuardOpenWindow();

    $inProgress = deployGuardText('admin.console.deploy.guard_games_in_progress', ['count' => 1]);

    // Multijoueur, en pause, solo : chacune est une partie en cours (60 § 17,
    // solo compris), chacune suffit à refuser, et sa clôture par l'action de
    // gel rouvre la garde.
    $cases = [
        'multijoueur' => static fn (): Game => Game::factory()->create(),
        'en pause' => static fn (): Game => Game::factory()->paused()->create(),
        'solo' => static fn (): Game => Game::factory()->solo()->create(),
    ];

    foreach ($cases as $label => $make) {
        $game = $make();

        $this->artisan('deploy:guard')->expectsOutputToContain($inProgress)->assertExitCode(1)->run();

        app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, Date::now()->toImmutable());

        $this->artisan('deploy:guard')->assertExitCode(0)->run();

        expect($game->refresh()->ended_at)->not->toBeNull($label);
    }

    // Plusieurs ensemble : le compte est celui du prédicat.
    Game::factory()->count(2)->create();
    Game::factory()->solo()->paused()->create();

    $this->artisan('deploy:guard')
        ->expectsOutputToContain(deployGuardText('admin.console.deploy.guard_games_in_progress', ['count' => 3]))
        ->assertExitCode(1)
        ->run();

    // Hors fenêtre ET avec une partie en cours : la partie l'emporte (code 1).
    app(DeployDrain::class)->release();

    $this->artisan('deploy:guard')->expectsOutputToContain(deployGuardText('admin.console.deploy.guard_games_in_progress', ['count' => 3]))->assertExitCode(1)->run();

    // La garde n'a rien écrit : aucun drapeau reposé, aucune partie close.
    expect(app(DeployDrain::class)->state())->toBeNull()
        ->and(Game::query()->inProgress()->count())->toBe(3);
});

it("échoue hors d'une fenêtre libre", function (): void {
    // Aucune partie en cours ; un salon au lobby ne compte pas.
    Room::factory()->create();
    Game::factory()->completed()->create();
    Game::factory()->interrupted()->create();

    $noWindow = deployGuardText('admin.console.deploy.guard_no_window');

    // Aucun drapeau.
    $this->artisan('deploy:guard')->expectsOutputToContain($noWindow)->assertExitCode(2)->run();

    // Drainage en cours, fenêtre pas encore ouverte.
    $drain = app(DeployDrain::class);
    $draining = $drain->start(DeployDrain::defaultTimeoutMinutes());

    $this->artisan('deploy:guard')->expectsOutputToContain($noWindow)->assertExitCode(2)->run();

    expect($drain->state())->toEqual($draining);

    // Fenêtre échue : le drapeau ne vaut plus rien.
    $window = $drain->openWindow(config()->integer('deploy.window_minutes'));

    $this->travelTo($window->expiresAt);

    $this->artisan('deploy:guard')->expectsOutputToContain($noWindow)->assertExitCode(2)->run();

    // Drapeau illisible : il ne vaut pas une fenêtre.
    cache()->put(config()->string('deploy.cache_key'), ['phase' => 'window', 'startedAt' => 'hier', 'expiresAt' => 'demain'], 600);

    $this->artisan('deploy:guard')->expectsOutputToContain($noWindow)->assertExitCode(2)->run();
});

it('passe dans une fenêtre libre sans partie en cours', function (): void {
    Room::factory()->create();
    Game::factory()->completed()->create();
    Game::factory()->interrupted()->create();

    deployGuardOpenWindow();

    $window = app(DeployDrain::class)->state();

    $this->artisan('deploy:guard')
        ->expectsOutputToContain(deployGuardText('admin.console.deploy.guard_ok'))
        ->assertExitCode(0)
        ->run();

    // Jusqu'à la dernière milliseconde de la fenêtre.
    expect($window)->not->toBeNull();

    $this->travelTo($window->expiresAt->subMillisecond());

    $this->artisan('deploy:guard')->assertExitCode(0)->run();

    // La garde ne lève ni ne prolonge le drapeau.
    expect(app(DeployDrain::class)->state())->toEqual($window);
});
