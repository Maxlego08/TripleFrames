<?php

use App\Enums\Locale;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\GameTrace;
use App\Models\Round;
use App\Support\Game\GameJournal;
use App\Support\Identity\PlayerToken;
use App\Support\Perf\GameTraceWriter;
use App\Support\Perf\PerfRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Chronologie technique d'une partie — spec 100 § 10.11, spec 10 § 7.11,
| D47 du 01/10
|--------------------------------------------------------------------------
|
| Transitions, diffusions, jobs de frontière et soumissions s'écrivent dans
| `game_trace` après commit, avec leur retard sur l'instant théorique ;
| jamais une saisie ni un titre. `phpunit.xml` coupe la mesure : chaque test
| l'active.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();
    Config::set('perf.enabled', true);
    app(PerfRecorder::class)->reset();

    Date::setTestNow(CarbonImmutable::parse('2026-10-01 14:00:00.250'));
});

it('écrit une transition après commit avec son retard sur l\'instant théorique', function (): void {
    $game = Game::factory()->create();
    $round = Round::factory()->forGame($game)->running()->create();
    $theoretical = Date::now()->toImmutable()->subMilliseconds(120);

    DB::beginTransaction();
    GameJournal::roundOpened($game, $round, $theoretical);
    DB::rollBack();

    expect(GameTrace::query()->count())->toBe(0);

    DB::transaction(static fn () => GameJournal::roundOpened($game, $round, $theoretical));

    $line = GameTrace::query()->sole();

    expect($line->game_id)->toBe($game->id)
        ->and($line->event)->toBe(GameTraceWriter::ROUND_OPENED)
        ->and($line->sequence_index)->toBe($round->sequence_index)
        ->and($line->delay_ms)->toBe(120);
});

it('écrit le retard réel d\'une diffusion de frontière', function (): void {
    $game = Game::factory()->create();
    $now = Date::now()->toImmutable();

    GameJournal::emitting('tier.opened', 'ref', 2, 3, $now, $now->subMilliseconds(35), $game->id);
    GameJournal::emitting('seat.input', 'ref', 2, null, $now, null, $game->id);

    $line = GameTrace::query()->sole();

    expect($line->event)->toBe(GameTraceWriter::BROADCAST_PREFIX.'tier.opened')
        ->and($line->tier_index)->toBe(3)
        ->and($line->delay_ms)->toBe(35);
});

it('écrit une soumission avec sa durée et son issue, jamais la saisie', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);

    // Les transitions de l'ouverture n'entrent pas dans ce constat.
    GameTrace::query()->delete();

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, EngineFixtures::opensAt($round, 1)->addSecond())
        ->assertOk();

    $line = GameTrace::query()->where('event', GameTraceWriter::ANSWER_TEXT)->sole();

    expect($line->game_id)->toBe($game->id)
        ->and($line->player_id)->toBe($seat->id)
        ->and($line->sequence_index)->toBe($round->sequence_index)
        ->and($line->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($line->query_count)->toBeGreaterThan(0)
        ->and($line->details)->toMatchArray(['http' => 200, 'result' => 'rejected'])
        ->and(json_encode($line->getAttributes()))->not->toContain(SubmissionFixtures::WRONG);
});

it('n\'écrit aucune chronologie quand la mesure est coupée', function (): void {
    Config::set('perf.enabled', false);
    $game = Game::factory()->create();

    GameJournal::gamePaused($game, Date::now()->toImmutable());

    expect(GameTrace::query()->count())->toBe(0);
});
