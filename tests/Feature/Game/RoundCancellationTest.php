<?php

use App\Actions\Game\CancelRound;
use App\Actions\Game\ScheduleRound;
use App\Enums\ContentAvailability;
use App\Enums\GameStatus;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Events\Game\GameFinalized;
use App\Jobs\Game\AdvanceRound;
use App\Models\Game;
use App\Models\Round;
use App\Settings\EngineConstants;
use App\Support\Game\RoundStep;
use App\Support\Realtime\WireTime;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;

/*
|--------------------------------------------------------------------------
| Annulation d'une manche — spec 60 § 15.2, lot L60-5
|--------------------------------------------------------------------------
|
| `CancelRound` : la manche passe `cancelled` sans aucun point, puis la suite
| est programmée dans la même transaction — le remplaçant de la réserve
| (`ReplacementRoundChooser`, contrat C3), numéroté comme la manche annulée ;
| à défaut, la manche suivante à jouer ; à défaut, le gel à l'instant de
| l'annulation (`FinalizeGame`, contrat C13). Les intitulés de ce lot portent
| sur ces trois issues ; les autres (60 § 20 : frame devenue non servable à
| l'ouverture, annulation décidée pendant une révélation, QCM Facile non
| composable, aucun point) arrivent avec `OpenTier`, `RevealRound` (L60-6) et
| la composition du QCM à l'ouverture (L60-11).
|
| Parties matérialisées par l'action réelle sur un tirage construit à la
| main (`EngineFixtures`), vraies variantes à fichier réel. Horloge figée.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->now = CarbonImmutable::parse('2026-09-26 14:00:00.250');
    Date::setTestNow($this->now);
});

/**
 * Programme la manche, dans une transaction.
 */
function cancellationSchedule(Round $round, CarbonImmutable $startsAt): void
{
    DB::transaction(static fn () => app(ScheduleRound::class)->handle($round, $startsAt));
}

/**
 * Annule la manche, dans une transaction.
 */
function cancellationCancel(Round $round, CarbonImmutable $now, RoundIncidentReason $reason = RoundIncidentReason::FrameUnavailable): void
{
    DB::transaction(static fn () => app(CancelRound::class)->handle($round, $reason, $now));
}

/**
 * La manche passe en cours à `$startedAt`, comme l'écrirait `OpenTier(1)`.
 */
function cancellationRun(Round $round, CarbonImmutable $startedAt): void
{
    $round->forceFill(['status' => RoundStatus::Running, 'started_at' => $startedAt])->save();
}

/**
 * La manche est jouée jusqu'au bout de sa révélation, avant `$before`.
 */
function cancellationComplete(Game $game, Round $round, CarbonImmutable $before): void
{
    $startedAt = $before->subMilliseconds($round->duration_ms + $game->tier_grace_ms)
        ->subSeconds($game->settings_snapshot->revealDuration + 1);
    $endedAt = $startedAt->addMilliseconds($round->duration_ms);

    $round->forceFill([
        'status' => RoundStatus::Completed,
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
        'reveal_ends_at' => $endedAt->addMilliseconds($game->tier_grace_ms)->addSeconds($game->settings_snapshot->revealDuration),
    ])->save();
}

test('le remplaçant reçoit le round_number de la manche annulée et part à max(now + launchCountdownMs, T₁ prévu)', function (): void {
    Queue::fake([AdvanceRound::class]);
    $recorder = RecordingBroadcaster::install();

    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::materialize($game, reserve: 2);
    $countdownEnd = $this->now->addMilliseconds(EngineConstants::launchCountdownMs());

    // 1. T₁ prévu APRÈS le décompte : la manche 1, programmée loin, est
    // annulée avant de démarrer ; le remplaçant prend sa place sans avancer.
    $first = EngineFixtures::round($game, 1);
    $planned = $countdownEnd->addSeconds(20);
    cancellationSchedule($first, $planned);
    $recorder->sent = [];

    cancellationCancel($first, $this->now);

    $first->refresh();
    $replacement = EngineFixtures::round($game, $game->rounds_count + 1);

    expect($first->status)->toBe(RoundStatus::Cancelled)
        ->and($first->cancel_reason)->toBe(RoundIncidentReason::FrameUnavailable)
        ->and($first->cancelled_at?->equalTo($this->now))->toBeTrue()
        ->and($replacement->round_number)->toBe($first->round_number)
        ->and($replacement->status)->toBe(RoundStatus::Pending)
        ->and($replacement->started_at?->equalTo($planned))->toBeTrue()
        ->and(EngineFixtures::tier($replacement, 1)->serve_token)->toMatch('/^[0-9a-f]{32}$/')
        // Le second de la réserve reste en réserve, sans numéro.
        ->and(EngineFixtures::round($game, $game->rounds_count + 2)->round_number)->toBeNull();

    Queue::assertPushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $replacement->id
        && $job->step === RoundStep::OpenTier
        && $job->dueAt === WireTime::iso($planned));

    // Sur le fil : l'annulation (ni motif, ni titre), puis la programmation
    // du remplaçant sous le même numéro affiché.
    expect(array_map(static fn (array $sent): string => $sent['event'], $recorder->sent))->toBe(['round.cancelled', 'round.scheduled'])
        ->and($recorder->sent[0]['payload'])->toMatchArray(['sequenceIndex' => 1, 'roundNumber' => 1])
        ->and(array_keys($recorder->sent[0]['payload']))->toBe(['v', 'serverNow', 'gameRef', 'sequenceIndex', 'roundNumber'])
        ->and($recorder->sent[1]['payload']['round'])->toMatchArray([
            'sequenceIndex' => $replacement->sequence_index,
            'roundNumber' => 1,
            'startsAt' => WireTime::iso($planned),
        ]);

    // 2. T₁ prévu AVANT le décompte : la manche 2 est en cours depuis 5 s
    // quand elle est annulée ; le remplaçant part au bout du décompte.
    $second = EngineFixtures::round($game, 2);
    cancellationRun($second, $this->now->subSeconds(5));

    cancellationCancel($second, $this->now);

    $next = EngineFixtures::round($game, $game->rounds_count + 2);

    expect($second->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and($next->round_number)->toBe(2)
        ->and($next->started_at?->equalTo($countdownEnd))->toBeTrue();

    // Idempotente : une annulation rejouée ne remplace pas une seconde fois.
    cancellationCancel($second, $this->now->addSecond());

    expect($second->refresh()->cancelled_at?->equalTo($this->now))->toBeTrue()
        ->and(Round::query()->where('game_id', $game->id)->where('round_number', 2)->count())->toBe(2);
});

test('réserve épuisée, la manche suivante à jouer est programmée comme un remplaçant et la partie compte une manche de moins', function (): void {
    Queue::fake([AdvanceRound::class]);
    $recorder = RecordingBroadcaster::install();

    $game = EngineFixtures::game(EngineFixtures::settings());
    $movies = EngineFixtures::materialize($game);
    $countdownEnd = $this->now->addMilliseconds(EngineConstants::launchCountdownMs());

    // L'unique variante du palier 1 de la manche 1 est retirée avant la
    // programmation : la frappe annule la manche (`no_variant_available`),
    // aucune réserve ne la remplace.
    EngineFixtures::variant($movies[0], FrameLevelCoverage::nominal($game->frames_per_round)[0])
        ->forceFill(['availability' => ContentAvailability::Withdrawn])
        ->save();

    $planned = $countdownEnd->addSeconds(15);
    cancellationSchedule(EngineFixtures::round($game, 1), $planned);

    $first = EngineFixtures::round($game, 1);
    $second = EngineFixtures::round($game, 2);

    // La manche suivante à jouer garde son propre numéro et part comme un
    // remplaçant : au plus tôt au bout du décompte, jamais avant le T₁ prévu.
    expect($first->status)->toBe(RoundStatus::Cancelled)
        ->and($first->cancel_reason)->toBe(RoundIncidentReason::NoVariantAvailable)
        ->and($second->status)->toBe(RoundStatus::Pending)
        ->and($second->round_number)->toBe(2)
        ->and($second->started_at?->equalTo($planned))->toBeTrue()
        ->and(EngineFixtures::tier($second, 1)->serve_token)->toMatch('/^[0-9a-f]{32}$/');

    // Une manche de moins : `M − 1` manches numérotées restent à jouer, la
    // partie continue, et `rounds_count` (figé) ne change pas.
    $game->refresh();

    expect(Round::query()->where('game_id', $game->id)->whereNotNull('round_number')->notCancelled()->count())->toBe($game->rounds_count - 1)
        ->and($game->status)->toBe(GameStatus::Running)
        ->and($game->ended_at)->toBeNull();

    Queue::assertPushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $second->id
        && $job->dueAt === WireTime::iso($planned));
    Queue::assertNotPushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $first->id);

    expect(array_map(static fn (array $sent): string => $sent['event'], $recorder->sent))->toBe(['round.cancelled', 'round.scheduled'])
        ->and($recorder->sent[1]['payload']['round'])->toMatchArray(['sequenceIndex' => 2, 'roundNumber' => 2]);

    // T₁ prévu déjà passé : la suite part au bout du décompte.
    cancellationRun($second, $this->now->subSeconds(3));
    cancellationCancel($second, $this->now);

    expect(EngineFixtures::round($game, 3)->started_at?->equalTo($countdownEnd))->toBeTrue();
});

test('sans manche restante, l\'annulation gèle la partie à l\'instant de l\'annulation', function (): void {
    Event::fake([GameFinalized::class]);

    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::materialize($game);

    foreach (range(1, $game->rounds_count - 1) as $sequenceIndex) {
        cancellationComplete($game, EngineFixtures::round($game, $sequenceIndex), $this->now);
    }

    $last = EngineFixtures::round($game, $game->rounds_count);
    cancellationRun($last, $this->now->subSeconds(4));

    cancellationCancel($last, $this->now, RoundIncidentReason::NoVariantAvailable);

    $game->refresh();
    $last->refresh();

    // Gelée par l'action de gel, à l'instant de l'annulation : `completed`,
    // manches jouées comptées, manche annulée hors de tout agrégat.
    expect($last->status)->toBe(RoundStatus::Cancelled)
        ->and($game->status)->toBe(GameStatus::Completed)
        ->and($game->ended_at?->equalTo($this->now))->toBeTrue()
        ->and($game->ended_at?->equalTo($last->cancelled_at))->toBeTrue()
        ->and($game->rounds_completed)->toBe($game->rounds_count - 1);

    Event::assertDispatched(GameFinalized::class, 1);
});
