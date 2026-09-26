<?php

use App\Enums\RoundStatus;
use App\Enums\ScoreScope;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;

/*
|--------------------------------------------------------------------------
| Portées de lecture du score — spec 80 § 7.1 et § 7.4, contrat C13 (L80-2)
|--------------------------------------------------------------------------
|
| Ajout : aucun intitulé de la spec ne porte sur les portées elles-mêmes, que
| `LeaderboardVisibilityTest` (L80-3) et `FinalizeGameTest` (L80-4)
| n'éprouvent qu'à travers `Scoreboard`. Elles sont prouvées ici, là où elles
| naissent.
|
| Trois lectures, une seule liste de statuts par portée
| (`ScoreScope::roundStatuses()`) : `Own` pour le siège lui-même, manche en
| cours comprise ; `Publishable` pour tout autre siège, dès `revealing` et
| jamais avant ; `Settled` pour le gel et le podium. Aucune ne lit une manche
| annulée (L1).
|
*/

test('chaque portée lit ses seuls statuts de manche, aucune ne lit une manche annulée ou en attente', function (): void {
    expect(ScoreScope::cases())->toHaveCount(3);

    expect(ScoreScope::Own->roundStatuses())->toBe([RoundStatus::Running, RoundStatus::Revealing, RoundStatus::Completed])
        ->and(ScoreScope::Publishable->roundStatuses())->toBe([RoundStatus::Revealing, RoundStatus::Completed])
        ->and(ScoreScope::Settled->roundStatuses())->toBe([RoundStatus::Completed]);

    foreach (ScoreScope::cases() as $scope) {
        expect($scope->roundStatuses())
            ->not->toContain(RoundStatus::Cancelled)
            ->not->toContain(RoundStatus::Pending);
    }

    // Une manche en cours ne devient publique qu'à revealing : seul Own la lit.
    expect(array_values(array_filter(
        ScoreScope::cases(),
        static fn (ScoreScope $scope): bool => in_array(RoundStatus::Running, $scope->roundStatuses(), true),
    )))->toBe([ScoreScope::Own]);
});

test('Guess::inScoreScope et RoundPlayer::inScoreScope filtrent par statut de manche sans changer l’agrégat, et Own équivaut à counted', function (): void {
    $game = Game::factory()->create();
    $seat = Player::factory()->create();
    $rounds = [];

    // Une manche par statut ; la manche en attente ne porte, comme en jeu, ni
    // participation ni bonne réponse.
    foreach (RoundStatus::cases() as $position => $status) {
        $factory = Round::factory()->forGame($game)->atSequence($position + 1);
        $round = match ($status) {
            RoundStatus::Pending => $factory->create(),
            RoundStatus::Running => $factory->running()->create(),
            RoundStatus::Revealing => $factory->revealing()->create(),
            RoundStatus::Completed => $factory->completed()->create(),
            RoundStatus::Cancelled => $factory->cancelled()->create(),
        };

        $rounds[$status->value] = $round;

        if ($status !== RoundStatus::Pending) {
            RoundPlayer::factory()->forRound($round, $seat)->locked()->create();
            // Un total distinct par manche : une somme fausse ne peut pas tomber juste.
            Guess::factory()->forRound($round, $seat)->withSpeedBonus($position)->create();
        }
    }

    $roundIdsOf = static fn (array $statuses): array => collect($statuses)
        ->map(static fn (RoundStatus $status): int => $rounds[$status->value]->id)
        ->sort()
        ->values()
        ->all();

    foreach (ScoreScope::cases() as $scope) {
        $expected = $roundIdsOf($scope->roundStatuses());

        expect(Guess::query()->inScoreScope($scope)->orderBy('round_id')->pluck('round_id')->all())->toBe($expected, $scope->name)
            ->and(RoundPlayer::query()->inScoreScope($scope)->orderBy('round_id')->pluck('round_id')->all())->toBe($expected, $scope->name);

        // Sous-requête et non jointure : ni le select ni la cardinalité de
        // l'agrégat qui suit ne changent.
        $expectedTotal = (int) Guess::query()->whereIn('round_id', $expected)->sum('points_total');

        expect((int) Guess::query()->inScoreScope($scope)->sum('points_total'))->toBe($expectedTotal, $scope->name)
            ->and(Guess::query()->inScoreScope($scope)->count())->toBe(count($expected), $scope->name);
    }

    // Own équivaut à counted() : L1 seul, une manche en attente ne portant jamais de guess.
    expect(Guess::query()->inScoreScope(ScoreScope::Own)->orderBy('id')->pluck('id')->all())
        ->toBe(Guess::query()->counted()->orderBy('id')->pluck('id')->all());

    // La manche annulée garde ses lignes, trace de l'incident, hors de toute portée.
    expect(Guess::query()->where('round_id', $rounds[RoundStatus::Cancelled->value]->id)->exists())->toBeTrue();
});
