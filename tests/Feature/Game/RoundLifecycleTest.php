<?php

use App\Actions\Game\CancelRound;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Events\Game\GameFinalized;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Support\Game\RoundStep;
use App\Support\Realtime\WireTime;
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
| Cycle de vie d'une manche — spec 60 § 3.2, § 4.1, § 5.3, § 6.3 et § 9 (lot L60-6)
|--------------------------------------------------------------------------
|
| Les transitions réelles, chacune appelée à son instant théorique, horloge
| figée à cet instant comme un job de frontière à l'heure : `OpenTier` à
| `Tᵢ`, `CloseRound` à `D`, `RevealRound` à `ended_at + tier_grace_ms`,
| `EndReveal` à `reveal_ends_at`. Les intitulés de ce lot portent sur le
| cycle, la programmation de `k+1`, l'ordre à instant égal et
| `rounds_completed` ; ceux du rattrapage, du job réveillé tôt, de la partie
| close ou en pause et du journal (L60-7) et de la « manche suivante »
| (L60-13) arrivent avec leurs lots.
|
| Parties matérialisées par l'action réelle (`EngineFixtures`), vraies
| variantes à fichier réel ; aucune valeur de jeu en littéral : durées,
| grâce et révélation relues sur la partie et ses paliers.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-26 14:00:00.250');
    Date::setTestNow($this->now);
});

/**
 * Les jobs de frontière poussés pour une manche, dans l'ordre : étape, palier,
 * instant théorique.
 *
 * @return list<array{string, int|null, string}>
 */
function lifecycleBoundaries(Round $round): array
{
    return Queue::pushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id)
        ->map(static fn (AdvanceRound $job): array => [$job->step->value, $job->tierIndex, $job->dueAt])
        ->values()
        ->all();
}

test('une manche passe de pending à running à T₁, puis ferme à D, révèle à ended_at + tier_grace_ms et se complète à reveal_ends_at', function (): void {
    $recorder = RecordingBroadcaster::install();

    $game = EngineFixtures::game(EngineFixtures::settings());
    $present = EngineFixtures::seat($game);
    $away = EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => $this->now]);
    // Ni un siège parti, ni un siège expulsé, ni un retardataire admis à la
    // manche suivante ne naissent dans la manche 1 (E10-49).
    EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Left, 'disconnected_at' => $this->now, 'left_at' => $this->now], status: GamePlayerStatus::Left);
    EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Left, 'left_at' => $this->now, 'kicked_at' => $this->now], status: GamePlayerStatus::Kicked);
    EngineFixtures::seat($game, firstRoundNumber: 2);
    EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);

    $startsAt = $this->now->addSeconds(5);
    EngineFixtures::schedule($round, $startsAt);
    $recorder->sent = [];

    // Programmée, la manche attend son T₁ : une ouverture une milliseconde
    // trop tôt n'écrit rien.
    $t1 = EngineFixtures::opensAt($round, 1);

    expect(EngineFixtures::openTier($round, 1, $t1->subMillisecond())->served_at)->toBeNull()
        ->and($round->refresh()->status)->toBe(RoundStatus::Pending)
        ->and(RoundPlayer::query()->where('round_id', $round->id)->count())->toBe(0);

    // T₁ : `running`, le palier 1 servi à son instant théorique, une ligne
    // `open` par siège non parti, déconnecté compris (E10-49).
    $first = EngineFixtures::openTier($round, 1);

    expect($round->refresh()->status)->toBe(RoundStatus::Running)
        ->and($round->ended_at)->toBeNull()
        ->and($first->served_at?->equalTo($t1))->toBeTrue()
        ->and(RoundPlayer::query()->where('round_id', $round->id)->orderBy('player_id')->pluck('player_id')->all())
        ->toEqualCanonicalizing([$present->id, $away->id])
        ->and(RoundPlayer::query()->where('round_id', $round->id)->pluck('input_state')->unique()->all())
        ->toBe([RoundPlayerInputState::Open]);

    // Les paliers suivants à leur Tᵢ, dans l'ordre.
    foreach (range(2, $game->frames_per_round) as $tierIndex) {
        $opened = EngineFixtures::openTier($round, $tierIndex);

        expect($opened->served_at?->equalTo(EngineFixtures::opensAt($round, $tierIndex)))->toBeTrue();
    }

    // D : `ended_at` à l'instant théorique, la manche reste `running`. La
    // clôture est demandée ici par un verrouillage reçu dans la grâce finale,
    // après `D` : l'instant écrit est toujours borné à `started_at + D`.
    $durationEnd = EngineFixtures::durationEnd($round);
    EngineFixtures::close($round, $durationEnd->addMilliseconds(intdiv($game->tier_grace_ms, 2)));

    $round->refresh();
    $revealStartsAt = $durationEnd->addMilliseconds($game->tier_grace_ms);
    $revealEndsAt = $revealStartsAt->addSeconds($game->settings_snapshot->revealDuration);

    expect($round->status)->toBe(RoundStatus::Running)
        ->and($round->ended_at?->equalTo($durationEnd))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($revealEndsAt))->toBeTrue();

    // Une clôture rejouée ne déplace rien.
    EngineFixtures::close($round, $durationEnd->addSeconds(3));

    expect($round->refresh()->ended_at?->equalTo($durationEnd))->toBeTrue();

    // La révélation attend `ended_at + tier_grace_ms`, jamais avant.
    EngineFixtures::reveal($round, $revealStartsAt->subMillisecond());

    expect($round->refresh()->status)->toBe(RoundStatus::Running);

    EngineFixtures::reveal($round);

    expect($round->refresh()->status)->toBe(RoundStatus::Revealing);

    // La fin de révélation attend `reveal_ends_at`.
    EngineFixtures::endReveal($round, $revealEndsAt->subMillisecond());

    expect($round->refresh()->status)->toBe(RoundStatus::Revealing);

    EngineFixtures::endReveal($round);

    expect($round->refresh()->status)->toBe(RoundStatus::Completed)
        ->and($round->ended_at?->equalTo($durationEnd))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($revealEndsAt))->toBeTrue();

    // Un job par frontière, chacun à l'instant théorique de l'étape suivante.
    $expected = [];

    foreach (range(2, $game->frames_per_round) as $tierIndex) {
        $expected[] = [RoundStep::OpenTier->value, $tierIndex, WireTime::iso(EngineFixtures::opensAt($round, $tierIndex))];
    }

    $expected[] = [RoundStep::Close->value, null, WireTime::iso($durationEnd)];
    $expected[] = [RoundStep::Reveal->value, null, WireTime::iso($revealStartsAt)];
    $expected[] = [RoundStep::EndReveal->value, null, WireTime::iso($revealEndsAt)];

    expect(array_slice(lifecycleBoundaries($round), 1))->toBe($expected);

    // Sur le fil, une diffusion par étape de la manche.
    $ownEvents = array_values(array_filter(
        $recorder->sent,
        static fn (array $sent): bool => ($sent['payload']['sequenceIndex'] ?? $sent['payload']['round']['sequenceIndex'] ?? null) === 1,
    ));

    expect(array_column($ownEvents, 'event'))->toBe([
        ...array_fill(0, $game->frames_per_round, 'tier.opened'),
        'round.closed',
        'round.revealed',
    ]);
});

test('la manche k+1 est programmée à reveal_ends_at(k) dès le début de la révélation de k', function (): void {
    $recorder = RecordingBroadcaster::install();

    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game);
    $first = EngineFixtures::round($game, 1);
    $second = EngineFixtures::round($game, 2);

    EngineFixtures::schedule($first, $this->now->addSeconds(5));

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);

    // Close, la manche k ne programme encore rien.
    expect($second->refresh()->started_at)->toBeNull();

    $recorder->sent = [];

    // La révélation s'exécute EN RETARD : les instants écrits restent les
    // instants théoriques (règle 1, § 1.1) — `T₁(k+1)` et le job de fin de
    // révélation à `reveal_ends_at(k)`, jamais à l'heure d'exécution + `R`.
    $endedAt = $first->refresh()->ended_at ?? throw new LogicException('Manche 1 non close.');
    EngineFixtures::reveal($first, $endedAt->addMilliseconds($game->tier_grace_ms + 2_700));

    $revealEndsAt = $first->refresh()->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');
    $second->refresh();

    // Dès le début de la révélation : T₁(k+1) = reveal_ends_at(k), sans
    // intervalle, palier 1 frappé, `OpenTier(1)` programmé à cet instant.
    expect($first->status)->toBe(RoundStatus::Revealing)
        ->and($second->status)->toBe(RoundStatus::Pending)
        ->and($second->started_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and(EngineFixtures::tier($second, 1)->serve_token)->toMatch('/^[0-9a-f]{32}$/')
        ->and(EngineFixtures::tier($second, 2)->serve_token)->toBeNull()
        ->and(lifecycleBoundaries($second))->toBe([[RoundStep::OpenTier->value, 1, WireTime::iso($revealEndsAt)]])
        ->and(lifecycleBoundaries($first))->toContain([RoundStep::EndReveal->value, null, WireTime::iso($revealEndsAt)]);

    // Sur le fil : les titres de k, PUIS la programmation de k+1, dont le
    // palier 1 devient servable dans les `preload_lead_ms` finales de R.
    expect(array_column($recorder->sent, 'event'))->toBe(['round.revealed', 'round.scheduled'])
        ->and($recorder->sent[1]['payload']['round'])->toMatchArray([
            'sequenceIndex' => 2,
            'roundNumber' => 2,
            'startsAt' => WireTime::iso($revealEndsAt),
        ])
        ->and($recorder->sent[1]['payload']['image']['fetchNotBefore'])
        ->toBe(WireTime::iso($revealEndsAt->subMilliseconds($game->preload_lead_ms)));

    // La fin de révélation ne reprogramme rien : k+1 l'est déjà.
    EngineFixtures::endReveal($first);

    expect($second->refresh()->started_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and(lifecycleBoundaries($second))->toHaveCount(1);

    // La dernière manche ne programme rien : aucune manche ne reste à jouer.
    foreach (range(2, $game->rounds_count) as $sequenceIndex) {
        $round = EngineFixtures::round($game, $sequenceIndex);

        if ($sequenceIndex > 2) {
            expect($round->started_at?->equalTo(EngineFixtures::round($game, $sequenceIndex - 1)->reveal_ends_at))->toBeTrue();
        }

        foreach (range(1, $game->frames_per_round) as $tierIndex) {
            EngineFixtures::openTier($round, $tierIndex);
        }

        EngineFixtures::close($round);
        $recorder->sent = [];
        EngineFixtures::reveal($round);

        if ($sequenceIndex < $game->rounds_count) {
            EngineFixtures::endReveal($round);
        }
    }

    expect(array_column($recorder->sent, 'event'))->toBe(['round.revealed'])
        ->and(Round::query()->where('game_id', $game->id)->whereNull('started_at')->count())->toBe(0);
});

test('la fin de révélation précède l\'ouverture du palier 1 suivant à instant égal', function (): void {
    Event::fake([GameFinalized::class]);

    // Deux parties jouées jusqu'à la fin de révélation de la manche 1 : dans
    // la première, plus aucun siège n'est connecté ; dans la seconde, un
    // siège reste présent.
    $scenarios = [];

    foreach (['absent' => false, 'présent' => true] as $label => $connected) {
        $game = EngineFixtures::game(EngineFixtures::settings());
        $seat = EngineFixtures::seat($game);
        EngineFixtures::materialize($game);
        $first = EngineFixtures::round($game, 1);

        EngineFixtures::schedule($first, $this->now->addSeconds(5));

        foreach (range(1, $game->frames_per_round) as $tierIndex) {
            EngineFixtures::openTier($first, $tierIndex);
        }

        EngineFixtures::close($first);
        EngineFixtures::reveal($first);

        if (! $connected) {
            $seat->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()])->save();
        }

        $scenarios[$label] = [$game, $first, EngineFixtures::round($game, 2)];
    }

    foreach ($scenarios as $label => [$game, $first, $second]) {
        $instant = $first->refresh()->reveal_ends_at;

        // Les deux étapes tombent au même instant.
        expect($instant)->not->toBeNull()
            ->and($second->refresh()->started_at?->equalTo($instant))->toBeTrue($label);

        // L'ouverture est appelée la PREMIÈRE, à cet instant : tant que k est
        // en révélation, elle attend, sans rien écrire ni se périmer.
        $opened = EngineFixtures::openTier($second, 1, $instant);

        expect($opened->served_at)->toBeNull($label)
            ->and($second->refresh()->status)->toBe(RoundStatus::Pending, $label)
            ->and(RoundPlayer::query()->where('round_id', $second->id)->count())->toBe(0, $label)
            ->and(EngineFixtures::tier($second, 2)->serve_token)->toBeNull($label);

        // La fin de révélation de k passe, au même instant…
        EngineFixtures::endReveal($first, $instant);

        expect($first->refresh()->status)->toBe(RoundStatus::Completed, $label);

        // …puis l'ouverture rejouée voit l'état qu'elle a laissé.
        $opened = EngineFixtures::openTier($second, 1, $instant);
        $game->refresh();
        $second->refresh();

        if ($label === 'absent') {
            // Pause : k+1 est déprogrammée avant d'avoir pu s'ouvrir.
            expect($game->status)->toBe(GameStatus::Paused)
                ->and($second->status)->toBe(RoundStatus::Pending)
                ->and($second->started_at)->toBeNull()
                ->and($opened->served_at)->toBeNull()
                ->and(RoundPlayer::query()->where('round_id', $second->id)->count())->toBe(0);

            continue;
        }

        expect($game->status)->toBe(GameStatus::Running)
            ->and($second->status)->toBe(RoundStatus::Running)
            ->and($opened->served_at?->equalTo($instant))->toBeTrue()
            ->and(RoundPlayer::query()->where('round_id', $second->id)->count())->toBe(1);
    }
});

test('rounds_completed compte les manches completed à chaque fin de révélation', function (): void {
    Event::fake([GameFinalized::class]);

    $game = EngineFixtures::game(EngineFixtures::settings(roundsCount: 4));
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game, reserve: 1);

    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, $this->now->addSeconds(5));
    EngineFixtures::play($first);

    expect($game->refresh()->rounds_completed)->toBe(1)
        ->and($game->status)->toBe(GameStatus::Running);

    // La manche 2, programmée, est annulée avant son T₁ : son remplaçant de
    // réserve prend son numéro. Une manche annulée n'est jamais comptée.
    $second = EngineFixtures::round($game, 2);
    $startsAt = $second->refresh()->started_at ?? throw new LogicException('Manche 2 non programmée.');

    DB::transaction(static fn () => app(CancelRound::class)->handle($second, RoundIncidentReason::FrameUnavailable, $startsAt->subSecond()));

    expect($game->refresh()->rounds_completed)->toBe(1);

    $replacement = Round::query()->where('game_id', $game->id)->where('round_number', 2)->where('status', RoundStatus::Pending->value)->firstOrFail();
    EngineFixtures::play($replacement);

    expect($game->refresh()->rounds_completed)->toBe(2);

    // Tant qu'une révélation court, le compteur ne la compte pas.
    $third = EngineFixtures::round($game, 3);

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($third, $tierIndex);
    }

    EngineFixtures::close($third);
    EngineFixtures::reveal($third);

    expect($game->refresh()->rounds_completed)->toBe(2);

    EngineFixtures::endReveal($third);

    expect($game->refresh()->rounds_completed)->toBe(3);

    // Dernière manche : le gel écrase le compteur par le même décompte, et
    // fige la partie à la fin de la dernière révélation (§ 14.5) — à
    // l'instant théorique `reveal_ends_at`, même quand le job de fin de
    // révélation s'exécute en retard (règle 1, § 1.1 ; § 9.6 étape 2).
    $last = EngineFixtures::round($game, 4);

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($last, $tierIndex);
    }

    EngineFixtures::close($last);
    EngineFixtures::reveal($last);

    $lastRevealEndsAt = $last->refresh()->reveal_ends_at ?? throw new LogicException('Dernière manche sans fin de révélation.');
    EngineFixtures::endReveal($last, $lastRevealEndsAt->addMilliseconds(2_700));

    $game->refresh();

    expect($last->refresh()->status)->toBe(RoundStatus::Completed)
        ->and($game->status)->toBe(GameStatus::Completed)
        ->and($game->rounds_completed)->toBe(4)
        ->and($game->rounds_completed)->toBe(Round::query()->where('game_id', $game->id)->completed()->count())
        ->and($game->ended_at?->equalTo($lastRevealEndsAt))->toBeTrue();

    Event::assertDispatched(GameFinalized::class, 1);
});
