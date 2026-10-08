<?php

use App\Actions\Curation\SuspendFrame;
use App\Actions\Curation\SuspendMovie;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Jobs\Game\AdvanceRound;
use App\Models\Frame;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;

/*
|--------------------------------------------------------------------------
| Annulation active sur suspension — spec 60 § 15.4, lot L60-17 (J2)
|--------------------------------------------------------------------------
|
| Un geste de suspension de l'administrateur (spec 20 § 11.2) distribue,
| après son commit, `WithdrawContentFromLiveRounds` sur la file `game` : la
| manche en cours du film suspendu est annulée (`movie_suspended`) puis
| remplacée ; une image suspendue déjà frappée fait annuler sa manche
| (`frame_unavailable`). Les jobs du moteur tournent sur la file `sync` des
| tests, qui honore l'après-commit ; seul le job de frontière est simulé.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class]);

    $this->now = CarbonImmutable::parse('2026-10-08 14:00:00.250');
    Date::setTestNow($this->now);
    $this->admin = User::factory()->admin()->create();
});

test('suspendre le film d\'une manche en cours l\'annule avec movie_suspended et programme un remplaçant', function (): void {
    $recorder = RecordingBroadcaster::install();
    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::materialize($game, reserve: 2);

    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, $this->now, $this->now);
    EngineFixtures::openTier($first, 1, $this->now);
    $first->refresh();

    expect($first->status)->toBe(RoundStatus::Running);

    $recorder->sent = [];
    app(SuspendMovie::class)->handle($first->movie, $this->admin, 'Signalement à instruire.');

    $first->refresh();
    $replacement = EngineFixtures::round($game, $game->rounds_count + 1);

    expect($first->status)->toBe(RoundStatus::Cancelled)
        ->and($first->cancel_reason)->toBe(RoundIncidentReason::MovieSuspended)
        ->and($replacement->round_number)->toBe($first->round_number)
        ->and($replacement->status)->toBe(RoundStatus::Pending)
        ->and($replacement->started_at)->not->toBeNull()
        ->and($replacement->movie_id)->not->toBe($first->movie_id)
        ->and(array_column($recorder->sent, 'event'))->toContain('round.cancelled');

    // Les autres manches de la partie, d'autres films, ne sont pas touchées.
    expect(EngineFixtures::round($game, 2)->status)->toBe(RoundStatus::Pending);
});

test('suspendre une frame déjà frappée annule la manche avec frame_unavailable', function (): void {
    RecordingBroadcaster::install();
    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::materialize($game, reserve: 2);

    // Programmée : le jeton du palier 1 est frappé, son image retenue.
    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, $this->now->addSeconds(10), $this->now);
    $served = Frame::query()->findOrFail(EngineFixtures::tier($first, 1)->served_frame_id);

    // Une image du même film qu'aucun palier frappé ne sert : aucune
    // annulation, la frappe suivante substituera.
    $second = EngineFixtures::round($game, 2);
    $unminted = Frame::query()->findOrFail(EngineFixtures::tier($second, 2)->frame_id);
    app(SuspendFrame::class)->handle($unminted, $this->admin, null);

    expect($second->fresh()?->status)->toBe(RoundStatus::Pending)
        ->and($first->fresh()?->status)->toBe(RoundStatus::Pending);

    app(SuspendFrame::class)->handle($served, $this->admin, null);

    $first->refresh();

    expect($first->status)->toBe(RoundStatus::Cancelled)
        ->and($first->cancel_reason)->toBe(RoundIncidentReason::FrameUnavailable)
        ->and(EngineFixtures::round($game, $game->rounds_count + 1)->round_number)->toBe($first->round_number);
});
