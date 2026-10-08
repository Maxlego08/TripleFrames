<?php

use App\Actions\Game\CancelRound;
use App\Actions\Game\CatchUpGame;
use App\Actions\Game\FinalizeGame;
use App\Enums\GamePauseKind;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Http\Middleware\EnsureActiveSeat;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Jobs\Game\SweepSeatPresence;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Settings\EngineConstants;
use App\Support\Deploy\DeployDrain;
use App\Support\Game\GameJournal;
use App\Support\Game\PauseDeadline;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Game\GameJournalRecorder;
use Tests\Support\Game\PresenceFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pause manuelle d'une partie — D64 du 07/10, spec 60 § 14.1 bis et § 14.2
|--------------------------------------------------------------------------
|
| L'hôte (le joueur en solo) met la partie en pause : immédiatement entre
| deux manches (décompte, `T₁` à venir), sinon à la fin de la révélation de
| la manche jouée — l'horloge d'une manche ne se met jamais en pause. Seul un
| geste reprend une pause manuelle (l'hôte, ou tout siège présent si l'hôte
| n'en est plus un), jamais un battement ; le budget des pauses manuelles est
| cumulé par partie, décomptes de reprise compris.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class, SweepSeatPresence::class]);

    $this->now = CarbonImmutable::parse('2026-10-07 14:00:00.250');
    Date::setTestNow($this->now);
});

/**
 * Un salon en partie, l'hôte et un autre siège tenus par des jetons. Avec
 * `$revealed`, la manche 1 est jouée jusqu'à sa révélation ; sinon elle est
 * seulement programmée à `now + 5 s` (décompte de lancement).
 *
 * @return array{room: Room, game: Game, first: Round, host: Player, hostToken: PlayerToken, seat: Player, seatToken: PlayerToken}
 */
function manualPauseRoom(bool $revealed = true): array
{
    [$room, $host, $hostToken] = HostGestures::room();
    [$seat, $seatToken] = HostGestures::seat($room);

    if ($revealed) {
        [$game, $first] = HostGestures::runningGame($room, [$host, $seat]);

        foreach (range(2, $game->frames_per_round) as $tierIndex) {
            EngineFixtures::openTier($first, $tierIndex);
        }

        EngineFixtures::close($first);
        EngineFixtures::reveal($first);
    } else {
        $game = EngineFixtures::game(EngineFixtures::settings(), room: $room);

        foreach ([$host, $seat] as $each) {
            GamePlayer::factory()->for($game)->frozenFrom($each)->create(['status' => GamePlayerStatus::Playing]);
        }

        Room::query()->whereKey($room->id)->update(['status' => RoomStatus::Playing->value, 'launched_at' => Date::now()]);
        EngineFixtures::materialize($game);
        $first = EngineFixtures::round($game, 1);
        EngineFixtures::schedule($first, Date::now()->toImmutable()->addSeconds(5));
    }

    foreach ([$host, $seat] as $each) {
        $each->forceFill(['connection_state' => PlayerConnectionState::Connected])->save();
    }

    return [
        'room' => $room->refresh(),
        'game' => $game->refresh(),
        'first' => $first->refresh(),
        'host' => $host->refresh(),
        'hostToken' => $hostToken,
        'seat' => $seat->refresh(),
        'seatToken' => $seatToken,
    ];
}

/**
 * Un geste de pause par la route (`room.game.pause`, `room.game.pause.cancel`,
 * `room.game.resume`), tel que le client l'envoie : JSON, cookie du jeton,
 * onglet actif, reçu à `$at`.
 *
 * @return TestResponse<Response>
 */
function manualPauseGesture(TestCase $test, string $route, Room $room, Player $seat, PlayerToken $token, CarbonImmutable $at): TestResponse
{
    Date::setTestNow($at);
    LobbyWrites::actAs($test, $token);

    return $test->postJson(route($route, $room), [], [EnsureActiveSeat::HEADER => (string) $seat->refresh()->active_seat_token]);
}

test('une pause demandée pendant la révélation prend effet à sa fin et déprogramme la manche suivante', function (): void {
    $recorder = RecordingBroadcaster::install();
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'hostToken' => $token, 'seat' => $seat, 'seatToken' => $seatToken] = manualPauseRoom();
    $second = EngineFixtures::round($game, 2);
    $revealEndsAt = $first->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');
    $gestureAt = $revealEndsAt->subSeconds(3);
    $recorder->sent = [];

    manualPauseGesture($this, 'room.game.pause', $room, $host, $token, $gestureAt)->assertNoContent();

    $game->refresh();

    // Demandée seulement : la révélation court, la manche suivante reste
    // programmée à `reveal_ends_at` — l'horloge d'une manche n'est jamais suspendue.
    expect($game->status)->toBe(GameStatus::Running)
        ->and($game->pause_requested_at?->equalTo($gestureAt))->toBeTrue()
        ->and($second->refresh()->started_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and(array_column($recorder->sent, 'event'))->toBe(['game.pause_requested'])
        ->and($recorder->sent[0]['payload']['requestedAt'])->toBe(WireTime::iso($gestureAt));

    // Le paquet de resynchronisation le dit à tout siège.
    Date::setTestNow($gestureAt->addMillisecond());
    LobbyWrites::actAs($this, $seatToken);
    $this->getJson(route('room.state', $room))->assertOk()
        ->assertJsonPath('pauseRequested', true)
        ->assertJsonPath('pause', null);

    // Double clic : idempotent, rien de neuf.
    $recorder->sent = [];
    manualPauseGesture($this, 'room.game.pause', $room, $host, $token, $gestureAt->addMillisecond())->assertNoContent();

    expect($recorder->sent)->toBe([])
        ->and($game->refresh()->pause_requested_at?->equalTo($gestureAt))->toBeTrue();

    // La fin de la révélation la rend effective, à son instant théorique,
    // même avec des sièges présents.
    EngineFixtures::endReveal($first, $revealEndsAt->addMilliseconds(40));
    $game->refresh();

    $deadline = $revealEndsAt->addMilliseconds(EngineConstants::pauseTimeoutMs() - EngineConstants::launchCountdownMs());

    expect($game->status)->toBe(GameStatus::Paused)
        ->and($game->pause_kind)->toBe(GamePauseKind::Manual)
        ->and($game->pause_requested_at)->toBeNull()
        ->and($game->paused_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and(PauseDeadline::of($game)?->equalTo($deadline))->toBeTrue()
        ->and($second->refresh()->started_at)->toBeNull()
        ->and($second->status)->toBe(RoundStatus::Pending)
        ->and(array_column($recorder->sent, 'event'))->toBe(['game.paused'])
        ->and($recorder->sent[0]['payload']['kind'])->toBe('manual')
        ->and($recorder->sent[0]['payload']['interruptsAt'])->toBe(WireTime::iso($deadline));

    $interruption = Queue::pushed(InterruptPausedGame::class, static fn (InterruptPausedGame $job): bool => $job->gameId === $game->id)->last();

    expect($interruption?->interruptsAt()->equalTo($deadline))->toBeTrue();

    LobbyWrites::actAs($this, $seatToken);
    $this->getJson(route('room.state', $room), [EnsureActiveSeat::HEADER => (string) $seat->active_seat_token])->assertOk()
        ->assertJsonPath('status', 'paused')
        ->assertJsonPath('pauseRequested', false)
        ->assertJsonPath('pause.kind', 'manual')
        ->assertJsonPath('pause.interruptsAt', WireTime::iso($deadline));
});

test('entre deux manches, la pause est immédiate, et la reprise de l\'hôte reprogramme la manche après le décompte en imputant le budget', function (): void {
    $recorder = RecordingBroadcaster::install();
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'hostToken' => $token] = manualPauseRoom(revealed: false);
    $t1 = $first->started_at ?? throw new LogicException('Manche 1 non programmée.');
    $gestureAt = $t1->subMilliseconds(3_200);
    $recorder->sent = [];

    manualPauseGesture($this, 'room.game.pause', $room, $host, $token, $gestureAt)->assertNoContent();

    $game->refresh();

    expect($game->status)->toBe(GameStatus::Paused)
        ->and($game->pause_kind)->toBe(GamePauseKind::Manual)
        ->and($game->paused_at?->equalTo($gestureAt))->toBeTrue()
        ->and($game->rounds_completed)->toBe(0)
        ->and($first->refresh()->started_at)->toBeNull()
        ->and(array_column($recorder->sent, 'event'))->toBe(['game.paused']);

    // Le job d'ouverture déjà programmé ne l'ouvre pas : étape périmée.
    expect(EngineFixtures::openTier($first, 1, $t1)->served_at)->toBeNull();

    // Un battement d'un siège présent ne reprend JAMAIS une pause manuelle.
    PresenceFixtures::beat($this, $room, $token, $gestureAt->addSeconds(2))->assertNoContent();

    expect($game->refresh()->status)->toBe(GameStatus::Paused);

    // L'hôte reprend 61,5 s plus tard.
    $recorder->sent = [];
    $resumeAt = $gestureAt->addMilliseconds(61_500);

    manualPauseGesture($this, 'room.game.resume', $room, $host, $token, $resumeAt)->assertNoContent();

    $game->refresh();
    $startsAt = $resumeAt->addMilliseconds(EngineConstants::launchCountdownMs());

    expect($game->status)->toBe(GameStatus::Running)
        ->and($game->pause_kind)->toBeNull()
        ->and($game->paused_at)->toBeNull()
        ->and($game->total_paused_ms)->toBe(61_500)
        ->and($game->manual_paused_ms)->toBe(61_500 + EngineConstants::launchCountdownMs())
        ->and($first->refresh()->started_at?->equalTo($startsAt))->toBeTrue()
        ->and(array_column($recorder->sent, 'event'))->toBe(['game.resumed', 'round.scheduled']);

    // Reprendre une partie déjà en cours ne fait rien.
    $recorder->sent = [];
    manualPauseGesture($this, 'room.game.resume', $room, $host, $token, $resumeAt->addSecond())->assertNoContent();

    expect($recorder->sent)->toBe([]);
});

test('l\'hôte retire sa demande avant la fin de la révélation, et la partie continue', function (): void {
    $recorder = RecordingBroadcaster::install();
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'hostToken' => $token] = manualPauseRoom();
    $revealEndsAt = $first->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');

    manualPauseGesture($this, 'room.game.pause', $room, $host, $token, $revealEndsAt->subSeconds(4))->assertNoContent();
    $recorder->sent = [];
    manualPauseGesture($this, 'room.game.pause.cancel', $room, $host, $token, $revealEndsAt->subSeconds(2))->assertNoContent();

    expect($game->refresh()->pause_requested_at)->toBeNull()
        ->and(array_column($recorder->sent, 'event'))->toBe(['game.pause_request_cancelled'])
        ->and($recorder->sent[0]['payload'])->not->toHaveKey('requestedAt');

    // Un second retrait est sans effet.
    $recorder->sent = [];
    manualPauseGesture($this, 'room.game.pause.cancel', $room, $host, $token, $revealEndsAt->subSecond())->assertNoContent();

    expect($recorder->sent)->toBe([]);

    EngineFixtures::endReveal($first);

    expect($game->refresh()->status)->toBe(GameStatus::Running)
        ->and($game->pause_kind)->toBeNull();
});

test('un siège qui n\'est pas l\'hôte ne peut ni mettre en pause ni retirer la demande, et ne reprend que si l\'hôte n\'est plus présent', function (): void {
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'hostToken' => $hostToken, 'seat' => $seat, 'seatToken' => $seatToken] = manualPauseRoom();
    $revealEndsAt = $first->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');

    manualPauseGesture($this, 'room.game.pause', $room, $seat, $seatToken, $revealEndsAt->subSeconds(4))->assertForbidden();

    expect($game->refresh()->pause_requested_at)->toBeNull();

    manualPauseGesture($this, 'room.game.pause', $room, $host, $hostToken, $revealEndsAt->subSeconds(3))->assertNoContent();
    manualPauseGesture($this, 'room.game.pause.cancel', $room, $seat, $seatToken, $revealEndsAt->subSeconds(2))->assertForbidden();

    expect($game->refresh()->pause_requested_at)->not->toBeNull();

    EngineFixtures::endReveal($first);

    expect($game->refresh()->status)->toBe(GameStatus::Paused);

    // L'hôte présent : le siège ne reprend pas.
    manualPauseGesture($this, 'room.game.resume', $room, $seat, $seatToken, $revealEndsAt->addSeconds(10))->assertForbidden();

    expect($game->refresh()->status)->toBe(GameStatus::Paused);

    // L'hôte n'est plus un siège présent : tout siège présent reprend.
    $host->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()])->save();
    $journal = GameJournalRecorder::start();

    try {
        manualPauseGesture($this, 'room.game.resume', $room, $seat, $seatToken, $revealEndsAt->addSeconds(20))->assertNoContent();

        expect($game->refresh()->status)->toBe(GameStatus::Running)
            ->and(array_column($journal->contexts(GameJournal::GAME_RESUMED), 'by'))->toBe(['seat']);
    } finally {
        $journal->stop();
    }
});

test('le budget des pauses manuelles est cumulé par partie : épuisé, la pause est refusée ; atteint, la partie s\'interrompt à l\'échéance', function (): void {
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'hostToken' => $token] = manualPauseRoom(revealed: false);
    $t1 = $first->started_at ?? throw new LogicException('Manche 1 non programmée.');
    $budget = EngineConstants::pauseTimeoutMs();
    $countdown = EngineConstants::launchCountdownMs();

    // Il reste un peu moins de deux décomptes : une fois réservé le décompte
    // de la reprise, l'attente permise serait inférieure à un décompte, la
    // pause est refusée.
    $game->forceFill(['manual_paused_ms' => $budget - 2 * $countdown + 1])->save();

    manualPauseGesture($this, 'room.game.pause', $room, $host, $token, $t1->subSeconds(3))
        ->assertConflict()
        ->assertExactJson(['code' => 'budget_exhausted']);

    expect($game->refresh()->status)->toBe(GameStatus::Running)
        ->and($first->refresh()->started_at?->equalTo($t1))->toBeTrue();

    // Avec deux décomptes de reste, l'attente permise est d'un décompte.
    $game->forceFill(['manual_paused_ms' => $budget - 2 * $countdown])->save();
    $pausedAt = $t1->subSeconds(3);

    manualPauseGesture($this, 'room.game.pause', $room, $host, $token, $pausedAt)->assertNoContent();

    $deadline = $pausedAt->addMilliseconds($countdown);

    expect(PauseDeadline::of($game->refresh())?->equalTo($deadline))->toBeTrue();

    $interruption = Queue::pushed(InterruptPausedGame::class, static fn (InterruptPausedGame $job): bool => $job->gameId === $game->id)->last();

    expect($interruption?->interruptsAt()->equalTo($deadline))->toBeTrue();

    // Une reprise tardive ne reprend rien : la partie est close à l'échéance.
    manualPauseGesture($this, 'room.game.resume', $room, $host, $token, $deadline->addMillisecond())
        ->assertConflict()
        ->assertExactJson(['code' => 'not_running']);

    $game->refresh();

    expect($game->status)->toBe(GameStatus::Interrupted)
        ->and($game->ended_at?->equalTo($deadline))->toBeTrue()
        ->and($game->rounds_completed)->toBe(0);
});

test('pendant un drainage, une pause est refusée mais une reprise reste permise', function (): void {
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'hostToken' => $token] = manualPauseRoom(revealed: false);
    $t1 = $first->started_at ?? throw new LogicException('Manche 1 non programmée.');

    manualPauseGesture($this, 'room.game.pause', $room, $host, $token, $t1->subSeconds(4))->assertNoContent();

    $drain = app(DeployDrain::class);
    $drain->release();
    $drain->start(DeployDrain::defaultTimeoutMinutes());

    try {
        manualPauseGesture($this, 'room.game.resume', $room, $host, $token, $t1->addSeconds(10))->assertNoContent();

        expect($game->refresh()->status)->toBe(GameStatus::Running);

        $restart = $game->refresh()->rounds()->where('sequence_index', 1)->firstOrFail()->started_at ?? throw new LogicException('Manche non reprogrammée.');

        manualPauseGesture($this, 'room.game.pause', $room, $host, $token, $restart->subSecond())
            ->assertConflict()
            ->assertExactJson(['code' => 'draining']);

        expect($game->refresh()->status)->toBe(GameStatus::Running);
    } finally {
        $drain->release();
    }
});

test('pendant la dernière manche, la pause est refusée : il ne reste rien à suspendre', function (): void {
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'hostToken' => $token] = manualPauseRoom();
    $revealEndsAt = $first->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');

    // Plus aucune manche à jouer après la première.
    Round::query()->where('game_id', $game->id)->where('sequence_index', '>', 1)->update(['status' => RoundStatus::Completed->value]);

    manualPauseGesture($this, 'room.game.pause', $room, $host, $token, $revealEndsAt->subSecond())
        ->assertConflict()
        ->assertExactJson(['code' => 'no_round_left']);

    expect($game->refresh()->pause_requested_at)->toBeNull();
});

test('en solo, le joueur demande la pause, la retire, la redemande, et seul son geste reprend la partie', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound(
        [$token],
        SubmissionFixtures::settings(InputDifficulty::Expert),
        solo: true,
    );
    $t1 = $round->started_at ?? throw new LogicException('Manche non programmée.');
    $recorder = RecordingBroadcaster::install();

    $gesture = function (string $route, CarbonImmutable $at) use ($token, $seat): TestResponse {
        Date::setTestNow($at);
        LobbyWrites::actAs($this, $token);

        return $this->postJson(route($route), [], [EnsureActiveSeat::HEADER => (string) $seat->refresh()->active_seat_token]);
    };

    $gesture('solo.pause', $t1->addSecond())->assertOk()->assertJsonPath('pauseRequested', true);
    $gesture('solo.pause.cancel', $t1->addSeconds(2))->assertOk()->assertJsonPath('pauseRequested', false);
    $gesture('solo.pause', $t1->addSeconds(3))->assertOk()->assertJsonPath('pauseRequested', true);

    // La manche va au bout, puis sa révélation ; à sa fin, la pause.
    $revealEndsAt = EngineFixtures::durationEnd($round)
        ->addMilliseconds($game->tier_grace_ms)
        ->addSeconds($game->settings_snapshot->revealDuration);

    Date::setTestNow($revealEndsAt);
    app(CatchUpGame::class)->handle($game, $revealEndsAt);

    expect($game->refresh()->status)->toBe(GameStatus::Paused)
        ->and($game->pause_kind)->toBe(GamePauseKind::Manual);

    // Chaque geste solo vaut battement, et un battement ne reprend pas.
    Date::setTestNow($revealEndsAt->addSeconds(5));
    LobbyWrites::actAs($this, $token);
    $this->postJson(route('solo.heartbeat'))->assertNoContent();

    expect($game->refresh()->status)->toBe(GameStatus::Paused);

    $resumed = $gesture('solo.resume', $revealEndsAt->addSeconds(30))->assertOk();

    expect($resumed->json('status'))->toBe('running')
        ->and($resumed->json('pause'))->toBeNull()
        ->and($game->refresh()->manual_paused_ms)->toBe(30_000 + EngineConstants::launchCountdownMs())
        // Aucune diffusion en solo.
        ->and($recorder->sent)->toBe([]);
});

test('une pause demandée pendant une manche annulée devient immédiate au décompte du remplaçant', function (): void {
    $recorder = RecordingBroadcaster::install();
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'hostToken' => $token] = manualPauseRoom(revealed: false);
    $t1 = $first->started_at ?? throw new LogicException('Manche 1 non programmée.');
    $second = EngineFixtures::round($game, 2);

    // La manche 1 est jouée : la pause est seulement demandée.
    Date::setTestNow($t1);
    EngineFixtures::openTier($first, 1, $t1);
    manualPauseGesture($this, 'room.game.pause', $room, $host, $token, $t1->addSeconds(2))->assertNoContent();

    expect($game->refresh()->pause_requested_at)->not->toBeNull();

    // Un échec technique annule la manche jouée : la suite est programmée
    // après un décompte, où la pause est immédiate.
    $cancelledAt = $t1->addSeconds(4);
    Date::setTestNow($cancelledAt);
    $recorder->sent = [];
    DB::transaction(static fn () => app(CancelRound::class)->handle($first->refresh(), RoundIncidentReason::FrameUnavailable, $cancelledAt));

    $game->refresh();

    expect($first->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and($game->status)->toBe(GameStatus::Paused)
        ->and($game->pause_kind)->toBe(GamePauseKind::Manual)
        ->and($game->pause_requested_at)->toBeNull()
        ->and($game->paused_at?->equalTo($cancelledAt))->toBeTrue()
        ->and($second->refresh()->started_at)->toBeNull()
        ->and(array_column($recorder->sent, 'event'))->toBe(['round.cancelled', 'round.scheduled', 'game.paused']);
});

test('sans partie en cours, un siège qui n\'est pas l\'hôte reçoit le refus not_running à sa reprise', function (): void {
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'hostToken' => $hostToken, 'seat' => $seat, 'seatToken' => $seatToken] = manualPauseRoom(revealed: false);
    $t1 = $first->started_at ?? throw new LogicException('Manche 1 non programmée.');

    manualPauseGesture($this, 'room.game.pause', $room, $host, $hostToken, $t1->subSeconds(3))->assertNoContent();

    $deadline = PauseDeadline::of($game->refresh()) ?? throw new LogicException('Pause sans échéance.');

    DB::transaction(static fn () => app(FinalizeGame::class)->handle($game->refresh(), GameStatus::Interrupted, $deadline));

    manualPauseGesture($this, 'room.game.resume', $room, $seat, $seatToken, $deadline->addSecond())
        ->assertConflict()
        ->assertExactJson(['code' => 'not_running']);
});

/**
 * Un geste solo par la route, tel que le client l'envoie, reçu à `$at`.
 *
 * @return TestResponse<Response>
 */
function manualPauseSoloGesture(TestCase $test, PlayerToken $token, Player $seat, string $route, CarbonImmutable $at): TestResponse
{
    Date::setTestNow($at);
    LobbyWrites::actAs($test, $token);

    return $test->postJson(route($route), [], [EnsureActiveSeat::HEADER => (string) $seat->refresh()->active_seat_token]);
}

test('en solo, passer la manche rend effective une pause demandée, sans programmer la suivante', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], SubmissionFixtures::settings(InputDifficulty::Expert), solo: true);
    $t1 = $round->started_at ?? throw new LogicException('Manche non programmée.');
    $second = EngineFixtures::round($game, 2);

    manualPauseSoloGesture($this, $token, $seat, 'solo.pause', $t1->addSecond())->assertOk()->assertJsonPath('pauseRequested', true);

    $skippedAt = $t1->addSeconds(2);
    $response = manualPauseSoloGesture($this, $token, $seat, 'solo.skip', $skippedAt)->assertOk();

    $game->refresh();

    expect($response->json('status'))->toBe('paused')
        ->and($response->json('pauseRequested'))->toBeFalse()
        ->and($response->json('pause.kind'))->toBe('manual')
        ->and($round->refresh()->status)->toBe(RoundStatus::Completed)
        ->and($game->status)->toBe(GameStatus::Paused)
        ->and($game->pause_kind)->toBe(GamePauseKind::Manual)
        ->and($game->pause_requested_at)->toBeNull()
        ->and($game->paused_at?->equalTo($skippedAt))->toBeTrue()
        ->and($game->rounds_completed)->toBe(1)
        ->and($second->refresh()->started_at)->toBeNull();
});

test('en solo, après une manche passée sans demande, une pause pendant le décompte est immédiate', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], SubmissionFixtures::settings(InputDifficulty::Expert), solo: true);
    $t1 = $round->started_at ?? throw new LogicException('Manche non programmée.');
    $second = EngineFixtures::round($game, 2);
    $skippedAt = $t1->addSecond();

    manualPauseSoloGesture($this, $token, $seat, 'solo.skip', $skippedAt)->assertOk();

    $next = $second->refresh()->started_at ?? throw new LogicException('Manche suivante non programmée.');
    $pausedAt = $skippedAt->addMilliseconds(100);

    expect($pausedAt->lessThan($next))->toBeTrue();

    manualPauseSoloGesture($this, $token, $seat, 'solo.pause', $pausedAt)->assertOk()
        ->assertJsonPath('status', 'paused')
        ->assertJsonPath('pauseRequested', false);

    $game->refresh();

    expect($game->status)->toBe(GameStatus::Paused)
        ->and($game->pause_kind)->toBe(GamePauseKind::Manual)
        ->and($game->paused_at?->equalTo($pausedAt))->toBeTrue()
        ->and($second->refresh()->started_at)->toBeNull();
});
