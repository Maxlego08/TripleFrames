<?php

use App\Jobs\Game\AdvanceRound;
use App\Models\Frame;
use App\Models\Round;
use App\Models\RoundTier;
use App\Support\Game\GameStateBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;

/*
|--------------------------------------------------------------------------
| L'identité publique des images révélées — D63 du 07/10, règle 3
|--------------------------------------------------------------------------
|
| `frame.public_id` adresse le lien « Signaler cette image ». Elle ne part
| qu'à la révélation (`round.revealed`, `round.reveal` du paquet), pour la
| variante réellement servie, et jamais dans `round.scheduled`, `tier.opened`
| ni le paquet d'une manche en cours : un identifiant stable d'image avant la
| révélation laisserait noter la paire (image → titre).
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class]);
    Date::setTestNow(CarbonImmutable::parse('2026-10-07 14:00:00.250'));
});

/**
 * Les `public_id` des images servies de la manche, par palier ouvert.
 *
 * @return list<array{tierIndex: int, framePublicId: string}>
 */
function revealFramesExpected(Round $round): array
{
    return RoundTier::query()
        ->where('round_id', $round->id)
        ->whereNotNull('served_at')
        ->orderBy('tier_index')
        ->get()
        ->map(static fn (RoundTier $tier): array => [
            'tierIndex' => $tier->tier_index,
            'framePublicId' => Frame::query()->findOrFail($tier->served_frame_id)->public_id,
        ])
        ->all();
}

it('ne livre l’identité publique des images qu’à la révélation, jamais dans une charge de manche en cours', function (): void {
    $recorder = RecordingBroadcaster::install();
    $game = EngineFixtures::game(EngineFixtures::settings());
    $seat = EngineFixtures::seat($game);
    EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($round, Date::now()->toImmutable()->addMilliseconds(5_381));

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($round, $tierIndex);
    }

    $round->refresh();
    $publicIds = Frame::query()->pluck('public_id')->all();

    expect($publicIds)->not->toBeEmpty();

    // Avant la révélation : ni dans les événements, ni dans le paquet.
    $before = (string) json_encode([
        $recorder->sent,
        GameStateBuilder::build($game->refresh(), $seat->refresh(), Date::now()->toImmutable(), null),
    ]);

    foreach ($publicIds as $publicId) {
        expect($before)->not->toContain($publicId);
    }

    expect($before)->not->toContain('framePublicId');

    EngineFixtures::close($round);
    $recorder->sent = [];
    EngineFixtures::reveal($round);

    $round->refresh();
    $expected = revealFramesExpected($round);
    $revealed = collect($recorder->sent)->firstWhere('event', 'round.revealed');

    expect($expected)->toHaveCount($game->frames_per_round)
        ->and($revealed['payload']['frames'] ?? null)->toBe($expected);

    // Le paquet de resynchronisation, en révélation : la même liste.
    $revealStartsAt = ($round->ended_at ?? throw new LogicException('Manche non close.'))->addMilliseconds($game->tier_grace_ms);
    $packet = GameStateBuilder::build($game->refresh(), $seat->refresh(), $revealStartsAt->addSecond(), null);

    expect($packet['round']['reveal']['frames'] ?? null)->toBe($expected)
        ->and($packet['round']['reveal']['movie']['tmdb'] ?? null)->toBe($round->movie->tmdb_id);
});

it('ne livre que les paliers ouverts, jamais un palier que la manche n’a pas montré', function (): void {
    $recorder = RecordingBroadcaster::install();
    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($round, Date::now()->toImmutable()->addMilliseconds(5_381));
    EngineFixtures::openTier($round, 1);

    EngineFixtures::close($round->refresh());
    EngineFixtures::reveal($round);

    $revealed = collect($recorder->sent)->firstWhere('event', 'round.revealed');

    expect(array_column($revealed['payload']['frames'] ?? [], 'tierIndex'))->toBe([1]);
});
