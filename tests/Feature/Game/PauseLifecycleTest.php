<?php

use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundStatus;
use App\Events\Game\GameFinalized;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Jobs\Game\SweepSeatPresence;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Settings\EngineConstants;
use App\Support\Game\RoundStep;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Game\PresenceFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;

/*
|--------------------------------------------------------------------------
| Pause, reprise et clôture à 15 minutes — spec 60 § 9.6, § 14.1 à § 14.3 (lots L60-6, L60-13)
|--------------------------------------------------------------------------
|
| `EndReveal(k)` met la partie en pause quand une manche reste à jouer et
| qu'aucun siège n'est PRÉSENT (ligne `game_player` non expulsée dont le
| siège est `connected`, écart (b) du § 22 bis) : la manche `k+1` est
| déprogrammée, et `InterruptPausedGame` gèle la partie à `paused_at +
| pauseTimeoutMs`, l'instant prévu. Les intitulés de ce lot portent sur la
| pause, sa déprogrammation, l'interruption programmée, le gel à l'instant
| prévu et le retardataire qui empêche la pause ; ceux de L60-13 sur la
| reprise par le battement d'un siège revenu (`ResumeGame`, § 14.2) et sur
| le battement tardif, qui gèle à l'échéance au lieu de reprendre ; la partie
| solo quittée (L60-16) arrive avec son lot.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-26 14:00:00.250');
    Date::setTestNow($this->now);
});

/**
 * Une partie dont la manche 1 est jouée jusqu'à sa révélation, `k+1`
 * programmée à `reveal_ends_at(1)`. Les sièges sont créés par `$seats`, avant
 * la matérialisation.
 *
 * @param  Closure(Game): void  $seats
 * @return array{Game, Round, Round}
 */
function pauseRevealedGame(Closure $seats, bool $solo = false): array
{
    $game = EngineFixtures::game(EngineFixtures::settings(), solo: $solo);
    $seats($game);
    EngineFixtures::materialize($game);

    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, Date::now()->toImmutable()->addSeconds(5));

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);
    EngineFixtures::reveal($first);

    return [$game, $first->refresh(), EngineFixtures::round($game, 2)];
}

/** Le siège passe `disconnected`, comme l'écrirait le balayage de présence. */
function pauseDisconnect(Player $seat): void
{
    $seat->forceFill([
        'connection_state' => PlayerConnectionState::Disconnected,
        'disconnected_at' => Date::now(),
    ])->save();
}

test('sans participant connecté en fin de révélation, la partie passe en pause et déprogramme la manche suivante', function (): void {
    $recorder = RecordingBroadcaster::install();
    $seats = [];

    [$game, $first, $second] = pauseRevealedGame(function (Game $game) use (&$seats): void {
        $seats[] = EngineFixtures::seat($game);
        $seats[] = EngineFixtures::seat($game);
        // Ni un siège parti, ni un siège expulsé ne sont présents, même si
        // leur ligne `game_player` existe.
        $seats[] = EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Left, 'disconnected_at' => Date::now(), 'left_at' => Date::now()], status: GamePlayerStatus::Left);
        $seats[] = EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Left, 'left_at' => Date::now(), 'kicked_at' => Date::now()], status: GamePlayerStatus::Kicked);
    });

    $revealEndsAt = $first->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');
    $token = EngineFixtures::tier($second, 1)->serve_token;

    expect($second->refresh()->started_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and($token)->toMatch('/^[0-9a-f]{32}$/');

    // Les deux sièges présents perdent le réseau pendant la révélation.
    pauseDisconnect($seats[0]);
    pauseDisconnect($seats[1]);
    $recorder->sent = [];

    // Le job de fin de révélation s'exécute en retard : la pause porte
    // l'instant THÉORIQUE de la fin de révélation.
    EngineFixtures::endReveal($first, $revealEndsAt->addMilliseconds(2_700));

    $game->refresh();
    $second->refresh();

    expect($first->refresh()->status)->toBe(RoundStatus::Completed)
        ->and($game->status)->toBe(GameStatus::Paused)
        ->and($game->paused_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and($game->ended_at)->toBeNull()
        ->and($game->total_paused_ms)->toBe(0)
        ->and($game->rounds_completed)->toBe(1)
        // Déprogrammée : plus d'origine de temps, donc plus de palier 1 servi ;
        // le jeton frappé est gardé pour la reprise, la manche reste numérotée.
        ->and($second->status)->toBe(RoundStatus::Pending)
        ->and($second->started_at)->toBeNull()
        ->and($second->round_number)->toBe(2)
        ->and(EngineFixtures::tier($second, 1)->serve_token)->toBe($token)
        ->and(EngineFixtures::tier($second, 1)->servingOpensAt($game->preload_lead_ms))->toBeNull();

    // Le job d'ouverture déjà programmé ne l'ouvre jamais : l'étape d'une
    // partie en pause est périmée.
    expect(EngineFixtures::openTier($second, 1, $revealEndsAt->addSecond())->served_at)->toBeNull()
        ->and($second->refresh()->status)->toBe(RoundStatus::Pending)
        ->and(RoundPlayer::query()->where('round_id', $second->id)->count())->toBe(0);

    // Sur le fil, la pause seule.
    expect(array_column($recorder->sent, 'event'))->toBe(['game.paused'])
        ->and($recorder->sent[0]['payload']['pausedAt'])->toBe(WireTime::iso($revealEndsAt));

    // Témoin : un siège connecté en fin de révélation, et la partie continue.
    [$running, $revealed, $next] = pauseRevealedGame(static function (Game $game): void {
        EngineFixtures::seat($game);
        pauseDisconnect(EngineFixtures::seat($game));
    });

    EngineFixtures::endReveal($revealed);

    expect($running->refresh()->status)->toBe(GameStatus::Running)
        ->and($running->paused_at)->toBeNull()
        ->and($next->refresh()->started_at?->equalTo($revealed->reveal_ends_at))->toBeTrue();

    Queue::assertPushed(InterruptPausedGame::class, 1);
});

test('la pause programme l\'interruption à paused_at + pauseTimeoutMs', function (): void {
    $recorder = RecordingBroadcaster::install();

    [$game, $first] = pauseRevealedGame(static function (Game $game): void {
        pauseDisconnect(EngineFixtures::seat($game));
    });

    $recorder->sent = [];
    EngineFixtures::endReveal($first);

    $pausedAt = $game->refresh()->paused_at ?? throw new LogicException('Partie non mise en pause.');
    $interruptsAt = $pausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs());

    // Après commit, sur la file `game`, un essai, le délai exprimé en INSTANT
    // (jamais en entier, lu en secondes), armé sur `paused_at`.
    Queue::assertPushedOn('game', InterruptPausedGame::class, static function (InterruptPausedGame $job) use ($game, $pausedAt, $interruptsAt): bool {
        return $job instanceof ShouldQueueAfterCommit
            && $job->gameId === $game->id
            && $job->pausedAt === WireTime::iso($pausedAt)
            && $job->interruptsAt()->equalTo($interruptsAt)
            && $job->delay instanceof CarbonImmutable
            && $job->delay->equalTo($interruptsAt)
            && $job->tries === 1;
    });
    Queue::assertPushed(InterruptPausedGame::class, 1);

    // `game.paused` annonce la même échéance.
    expect($recorder->sent)->toHaveCount(1)
        ->and($recorder->sent[0]['event'])->toBe('game.paused')
        ->and($recorder->sent[0]['payload'])->toMatchArray([
            'pausedAt' => WireTime::iso($pausedAt),
            'interruptsAt' => WireTime::iso($interruptsAt),
        ]);

    // En solo : la même pause, la même échéance, aucune diffusion.
    $recorder->sent = [];

    [$solo, $soloFirst] = pauseRevealedGame(static function (Game $game): void {
        EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()]);
    }, solo: true);

    EngineFixtures::endReveal($soloFirst);

    expect($solo->refresh()->status)->toBe(GameStatus::Paused)
        ->and($recorder->sent)->toBe([]);

    Queue::assertPushed(InterruptPausedGame::class, static fn (InterruptPausedGame $job): bool => $job->gameId === $solo->id
        && $job->pausedAt === WireTime::iso($soloFirst->refresh()->reveal_ends_at ?? throw new LogicException('Manche sans fin de révélation.')));
});

test('l\'interruption gèle la partie à l\'instant prévu et non à l\'heure d\'exécution du job', function (): void {
    Event::fake([GameFinalized::class]);
    Sleep::fake(syncWithCarbon: true);

    /** @return array{Game, InterruptPausedGame} */
    $paused = function (): array {
        [$game, $first] = pauseRevealedGame(static function (Game $game): void {
            pauseDisconnect(EngineFixtures::seat($game));
        });

        EngineFixtures::endReveal($first);

        $job = Queue::pushed(InterruptPausedGame::class, static fn (InterruptPausedGame $job): bool => $job->gameId === $game->id)->first();

        return [$game->refresh(), $job instanceof InterruptPausedGame ? $job : throw new LogicException('Interruption non programmée.')];
    };

    // 1. Le job part 42 s en retard : la partie est gelée à l'échéance prévue.
    [$game, $job] = $paused();
    $interruptsAt = $job->interruptsAt();

    Date::setTestNow($interruptsAt->addSeconds(42));
    app()->call([$job, 'handle']);

    $game->refresh();

    expect($game->status)->toBe(GameStatus::Interrupted)
        ->and($game->ended_at?->equalTo($interruptsAt))->toBeTrue()
        ->and($game->rounds_completed)->toBe(1)
        // La manche suivante, déprogrammée, reste intacte au gel.
        ->and(EngineFixtures::round($game, 2)->status)->toBe(RoundStatus::Pending);

    Event::assertDispatched(GameFinalized::class, static fn (GameFinalized $event): bool => $event->gameId === $game->id);
    Sleep::assertNeverSlept();

    // 2. Réveillé tôt par la troncature à la seconde : il attend en processus
    // jusqu'à l'échéance, puis gèle à l'instant prévu.
    [$early, $earlyJob] = $paused();
    $earlyAt = $earlyJob->interruptsAt();

    Date::setTestNow($earlyAt->subMilliseconds(400));
    app()->call([$earlyJob, 'handle']);

    expect($early->refresh()->status)->toBe(GameStatus::Interrupted)
        ->and($early->ended_at?->equalTo($earlyAt))->toBeTrue();

    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(400)->milliseconds()]);

    // 3. Réveillé bien plus tôt que la troncature (file synchrone, horloges
    // désaccordées) : rien avant l'échéance.
    [$tooEarly, $tooEarlyJob] = $paused();

    Date::setTestNow($tooEarlyJob->interruptsAt()->subMilliseconds(EngineConstants::transitionMaxWaitMs() + 1));
    app()->call([$tooEarlyJob, 'handle']);

    expect($tooEarly->refresh()->status)->toBe(GameStatus::Paused)
        ->and($tooEarly->ended_at)->toBeNull();

    // 4. Une partie qui n'est plus en pause, ou dont la pause a été réarmée
    // à un autre instant, n'est jamais gelée par un job périmé.
    [$resumed, $resumedJob] = $paused();
    $resumed->forceFill(['status' => GameStatus::Running, 'paused_at' => null])->save();
    [$rearmed, $rearmedJob] = $paused();
    $rearmed->forceFill(['paused_at' => $rearmed->paused_at?->addMinute()])->save();

    Date::setTestNow($rearmedJob->interruptsAt()->addSecond());
    app()->call([$resumedJob, 'handle']);
    app()->call([$rearmedJob, 'handle']);

    expect($resumed->refresh()->status)->toBe(GameStatus::Running)
        ->and($resumed->ended_at)->toBeNull()
        ->and($rearmed->refresh()->status)->toBe(GameStatus::Paused)
        ->and($rearmed->ended_at)->toBeNull();

    // Idempotent : rejoué sur la partie déjà gelée, il ne regèle pas.
    Date::setTestNow($interruptsAt->addHour());
    app()->call([$job, 'handle']);

    expect($game->refresh()->ended_at?->equalTo($interruptsAt))->toBeTrue();

    Event::assertDispatchedTimes(GameFinalized::class, 2);
});

test('un retardataire admis à la manche suivante et connecté empêche la pause en fin de révélation', function (): void {
    $recorder = RecordingBroadcaster::install();
    $originals = [];

    [$game, $first, $second] = pauseRevealedGame(function (Game $game) use (&$originals): void {
        $originals[] = EngineFixtures::seat($game);
        $originals[] = EngineFixtures::seat($game);
    });

    // Tous les sièges de la manche 1 sont partis du réseau ; un retardataire
    // est admis pendant la révélation, à la manche suivante (50 § 15.2), et
    // il est connecté. Entre deux manches, il n'existe aucun participant :
    // c'est le siège PRÉSENT qui compte.
    foreach ($originals as $seat) {
        pauseDisconnect($seat);
    }

    $lateJoiner = EngineFixtures::seat($game, firstRoundNumber: 2);

    expect(RoundPlayer::query()->where('round_id', $first->id)->where('player_id', $lateJoiner->id)->exists())->toBeFalse();

    $recorder->sent = [];
    EngineFixtures::endReveal($first);

    $game->refresh();
    $second->refresh();

    expect($first->refresh()->status)->toBe(RoundStatus::Completed)
        ->and($game->status)->toBe(GameStatus::Running)
        ->and($game->paused_at)->toBeNull()
        ->and($second->started_at?->equalTo($first->reveal_ends_at))->toBeTrue()
        ->and($recorder->sent)->toBe([]);

    Queue::assertNotPushed(InterruptPausedGame::class);

    // À T₁ de la manche 2, il y prend part — ses aînés déconnectés aussi.
    EngineFixtures::openTier($second, 1);

    expect(RoundPlayer::query()->where('round_id', $second->id)->pluck('player_id')->all())
        ->toEqualCanonicalizing([$lateJoiner->id, ...array_map(static fn (Player $seat): int => $seat->id, $originals)]);

    // Témoin : le même retardataire, déconnecté, ne l'empêche plus.
    [$other, $otherFirst] = pauseRevealedGame(static function (Game $game): void {
        pauseDisconnect(EngineFixtures::seat($game));
    });

    pauseDisconnect(EngineFixtures::seat($other, firstRoundNumber: 2));
    EngineFixtures::endReveal($otherFirst);

    expect($other->refresh()->status)->toBe(GameStatus::Paused);
});

/**
 * Les jobs de frontière poussés pour une manche, dans l'ordre : étape, palier,
 * instant théorique.
 *
 * @return list<array{string, int|null, string}>
 */
function pauseBoundaries(Round $round): array
{
    return Queue::pushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id)
        ->map(static fn (AdvanceRound $job): array => [$job->step->value, $job->tierIndex, $job->dueAt])
        ->values()
        ->all();
}

/**
 * Une partie multijoueur mise en pause en fin de révélation de la manche 1 :
 * deux sièges tenus par des jetons, tous deux passés `disconnected` pendant
 * la révélation. Rend la partie, son salon, les sièges et leurs jetons.
 *
 * @return array{Game, Room, Round, list<Player>, list<PlayerToken>}
 */
function pauseHeldPausedGame(): array
{
    $held = [];

    [$game, $first, $second] = pauseRevealedGame(function (Game $game) use (&$held): void {
        $held[] = PresenceFixtures::heldSeat($game);
        $held[] = PresenceFixtures::heldSeat($game);
    });

    $seats = array_column($held, 0);

    foreach ($seats as $seat) {
        pauseDisconnect($seat);
    }

    EngineFixtures::endReveal($first);

    $room = $game->refresh()->room ?? throw new LogicException('Partie sans salon.');

    expect($game->status)->toBe(GameStatus::Paused);

    return [$game, $room, $second->refresh(), $seats, array_column($held, 1)];
}

test('le retour d\'un siège reprend la partie et reprogramme la manche après le décompte', function (): void {
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class, SweepSeatPresence::class]);
    $recorder = RecordingBroadcaster::install();

    [$game, $room, $second, $seats, $tokens] = pauseHeldPausedGame();
    $pausedAt = $game->paused_at ?? throw new LogicException('Partie sans instant de pause.');
    $token = EngineFixtures::tier($second, 1)->serve_token;
    $interruption = Queue::pushed(InterruptPausedGame::class, static fn (InterruptPausedGame $job): bool => $job->gameId === $game->id)->first();
    $boundaries = pauseBoundaries($second);
    $recorder->sent = [];

    // Un siège revient 7,3 s après la pause : son battement reprend la partie.
    $backAt = $pausedAt->addMilliseconds(7_300);
    PresenceFixtures::beat($this, $room, $tokens[0], $backAt)->assertNoContent();

    $game->refresh();
    $second->refresh();
    $startsAt = $backAt->addMilliseconds(EngineConstants::launchCountdownMs());

    expect($game->status)->toBe(GameStatus::Running)
        ->and($game->paused_at)->toBeNull()
        ->and($game->ended_at)->toBeNull()
        // Le trou d'horloge, en millisecondes entières.
        ->and($game->total_paused_ms)->toBe(7_300)
        // La manche déprogrammée repart après le décompte de reprise, avec le
        // jeton du palier 1 frappé avant la pause.
        ->and($second->started_at?->equalTo($startsAt))->toBeTrue()
        ->and(EngineFixtures::tier($second, 1)->serve_token)->toBe($token)
        // Un job d'ouverture neuf, au nouveau T₁ ; celui d'avant la pause
        // se réveillera sur une étape périmée.
        ->and(pauseBoundaries($second))->toBe([...$boundaries, [RoundStep::OpenTier->value, 1, WireTime::iso($startsAt)]]);

    // Sur le fil : le siège revenu, puis la reprise, puis la programmation.
    expect(array_column($recorder->sent, 'event'))->toBe(['seat.updated', 'game.resumed', 'round.scheduled'])
        ->and($recorder->sent[1]['payload']['resumedAt'])->toBe(WireTime::iso($backAt))
        ->and($recorder->sent[2]['payload']['round']['startsAt'])->toBe(WireTime::iso($startsAt));

    // Le battement de l'autre siège ne reprend rien une seconde fois.
    $recorder->sent = [];
    PresenceFixtures::beat($this, $room, $tokens[1], $backAt->addSecond())->assertNoContent();

    expect($game->refresh()->total_paused_ms)->toBe(7_300)
        ->and($second->refresh()->started_at?->equalTo($startsAt))->toBeTrue()
        ->and(array_column($recorder->sent, 'event'))->toBe(['seat.updated']);

    // L'interruption armée par la pause, à son échéance, ne gèle plus rien.
    expect($interruption)->toBeInstanceOf(InterruptPausedGame::class);
    Date::setTestNow($interruption->interruptsAt());
    app()->call([$interruption, 'handle']);

    expect($game->refresh()->status)->toBe(GameStatus::Running)
        ->and($game->ended_at)->toBeNull();

    // La manche reprise s'ouvre à son nouveau T₁, les deux sièges y prenant part.
    EngineFixtures::openTier($second, 1);

    expect($second->refresh()->status)->toBe(RoundStatus::Running)
        ->and(RoundPlayer::query()->where('round_id', $second->id)->pluck('player_id')->all())
        ->toEqualCanonicalizing(array_map(static fn (Player $seat): int => $seat->id, $seats));
});

test('un battement reçu après paused_at + pauseTimeoutMs ne reprend pas la partie et la gèle à l\'instant prévu', function (): void {
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class, SweepSeatPresence::class]);
    $recorder = RecordingBroadcaster::install();

    // Le battement arrive 42 s après l'échéance, avant que le job
    // d'interruption, en retard en tête de file, ne l'ait constatée. Il vient
    // d'un siège PARTI pendant la pause : la partie close à l'échéance le
    // compte parti, quel que soit le retard du job.
    [$game, $room, $second, $seats, $tokens] = pauseHeldPausedGame();
    $interruptsAt = ($game->paused_at ?? throw new LogicException('Partie sans instant de pause.'))
        ->addMilliseconds(EngineConstants::pauseTimeoutMs());
    $gone = $seats[1];
    $gone->forceFill([
        'connection_state' => PlayerConnectionState::Left,
        'left_at' => $interruptsAt->subMinute(),
    ])->save();
    GamePlayer::query()->whereBelongsTo($game)->where('player_id', $gone->id)
        ->update(['status' => GamePlayerStatus::Left->value]);
    $boundaries = pauseBoundaries($second);
    $recorder->sent = [];

    PresenceFixtures::beat($this, $room, $tokens[1], $interruptsAt->addSeconds(42))->assertNoContent();

    $game->refresh();

    expect($game->status)->toBe(GameStatus::Interrupted)
        ->and($game->ended_at?->equalTo($interruptsAt))->toBeTrue()
        ->and($game->total_paused_ms)->toBe(0)
        ->and($second->refresh()->started_at)->toBeNull()
        ->and($second->status)->toBe(RoundStatus::Pending)
        ->and(pauseBoundaries($second))->toBe($boundaries)
        ->and(array_column($recorder->sent, 'event'))->toBe(['seat.updated', 'game.ended'])
        // Le siège revient au salon, mais sa participation à la partie close
        // à l'échéance reste `left` : le podium figé ne le montre pas présent.
        ->and($gone->refresh()->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and(GamePlayer::query()->whereBelongsTo($game)->where('player_id', $gone->id)->value('status'))
        ->toBe(GamePlayerStatus::Left);

    // À l'échéance exacte, déjà trop tard ; une milliseconde avant, la reprise.
    [$atDeadline, $deadlineRoom, , , $deadlineTokens] = pauseHeldPausedGame();
    $deadline = ($atDeadline->paused_at ?? throw new LogicException('Partie sans instant de pause.'))
        ->addMilliseconds(EngineConstants::pauseTimeoutMs());
    PresenceFixtures::beat($this, $deadlineRoom, $deadlineTokens[0], $deadline)->assertNoContent();

    expect($atDeadline->refresh()->status)->toBe(GameStatus::Interrupted)
        ->and($atDeadline->ended_at?->equalTo($deadline))->toBeTrue();

    [$justInTime, $justRoom, , , $justTokens] = pauseHeldPausedGame();
    $justDeadline = ($justInTime->paused_at ?? throw new LogicException('Partie sans instant de pause.'))
        ->addMilliseconds(EngineConstants::pauseTimeoutMs());
    PresenceFixtures::beat($this, $justRoom, $justTokens[0], $justDeadline->subMillisecond())->assertNoContent();

    expect($justInTime->refresh()->status)->toBe(GameStatus::Running)
        ->and($justInTime->ended_at)->toBeNull()
        ->and($justInTime->total_paused_ms)->toBe(EngineConstants::pauseTimeoutMs() - 1);

    // Le job d'interruption, parti ensuite, ne regèle pas : le gel est
    // idempotent, le premier gagne.
    $job = Queue::pushed(InterruptPausedGame::class, static fn (InterruptPausedGame $job): bool => $job->gameId === $game->id)->first();
    Date::setTestNow($interruptsAt->addMinute());
    app()->call([$job, 'handle']);

    expect($game->refresh()->ended_at?->equalTo($interruptsAt))->toBeTrue();
});
