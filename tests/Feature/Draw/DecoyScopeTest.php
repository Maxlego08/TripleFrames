<?php

use App\Enums\FrameLevel;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\Room;
use App\Models\Round;
use App\Models\Theme;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolScope;
use App\Support\Draw\RoomMemoryWindow;
use Carbon\CarbonImmutable;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Périmètres d'une partie lancée — spec 30 § 3.2 et § 10, lot L30-2
|--------------------------------------------------------------------------
|
| `forGame` relit l'instantané FIGÉ de la partie, jamais les réglages que
| l'hôte a pu changer depuis. `forDecoys` y ajoute, toujours (D21 du 23/09),
| l'exclusion du film cible, de son `movie_group` et des films des manches
| DÉMARRÉES à l'instant théorique de composition — jamais des manches
| futures, dont l'absence trahirait le tirage.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    // L'instant THÉORIQUE de composition du QCM : `started_at` de la manche
    // plus le décalage de son palier d'ouverture.
    $this->at = CarbonImmutable::parse('2026-09-24 10:00:00.250');
});

/**
 * Identifiants des films du périmètre, dans l'ordre de `movies()`.
 *
 * @return list<int>
 */
function decoyScopeIds(PoolScope $scope): array
{
    return array_values(array_map(
        static fn (Movie $movie): int => $movie->id,
        app(PoolQuery::class)->movies($scope)->get()->all(),
    ));
}

/**
 * @return list<int>
 */
function decoyScopeSorted(Movie ...$movies): array
{
    $ids = array_map(static fn (Movie $movie): int => $movie->id, $movies);
    sort($ids);

    return $ids;
}

/**
 * Une partie et ses manches autour de la manche cible : deux démarrées avant
 * elle (l'une annulée), la cible démarrée, une programmée et une de réserve.
 *
 * @return array{target: Round, earlier: Movie, cancelled: Movie, targetMovie: Movie, sibling: Movie, future: Movie, reserve: Movie}
 */
function decoyScopeGame(Game $game, CarbonImmutable $at): array
{
    $group = MovieGroup::factory()->create();

    $earlier = PoolFixtures::movie();
    $cancelled = PoolFixtures::movie();
    $targetMovie = PoolFixtures::movie(group: $group);
    $sibling = PoolFixtures::movie(group: $group);
    $future = PoolFixtures::movie();
    $reserve = PoolFixtures::movie();

    PoolFixtures::round($game, $earlier, $at->subMinutes(2));
    PoolFixtures::round($game, $cancelled, $at->subMinute(), RoundStatus::Cancelled);
    $target = PoolFixtures::round(
        $game,
        $targetMovie,
        $at->subSeconds(RoomSettingsBounds::MIN_TIER_DURATION),
        RoundStatus::Running,
    );
    PoolFixtures::round($game, $future, $at->addMinute(), RoundStatus::Pending);
    PoolFixtures::round($game, $reserve, null, RoundStatus::Pending, reserve: true);

    return compact('target', 'earlier', 'cancelled', 'targetMovie', 'sibling', 'future', 'reserve');
}

it('forDecoys exclut la cible, son groupe et les manches démarrées, jamais les manches futures', function () {
    $bystander = PoolFixtures::movie();
    // Jouable à aucun N : absent du vivier du salon, présent au complément.
    $bankless = PoolFixtures::movie([FrameLevel::Level3]);

    foreach ([
        'non-répétition active' => PoolFixtures::game(
            Room::factory()->create(),
            PoolFixtures::settings(noRepeatMovies: true),
        ),
        'non-répétition coupée' => PoolFixtures::game(
            Room::factory()->create(),
            PoolFixtures::settings(noRepeatMovies: false),
        ),
        'solo' => Game::factory()->solo()->withSettings(PoolFixtures::settings(noRepeatMovies: true))->create(),
    ] as $label => $game) {
        $round = decoyScopeGame($game, $this->at);
        $scope = PoolScope::forDecoys($round['target'], $this->at);

        $ids = decoyScopeIds($scope);

        // Exclus, toujours : la cible, son groupe, les manches démarrées,
        // annulée comprise.
        foreach (['targetMovie', 'sibling', 'earlier', 'cancelled'] as $excluded) {
            expect(in_array($round[$excluded]->id, $ids, true))->toBeFalse("{$label} : {$excluded}");
        }

        // Jamais exclues : la manche programmée et la réserve.
        foreach (['future', 'reserve'] as $kept) {
            expect(in_array($round[$kept]->id, $ids, true))->toBeTrue("{$label} : {$kept}");
        }

        expect(in_array($bystander->id, $ids, true))->toBeTrue("{$label} : bystander")
            ->and(in_array($bankless->id, $ids, true))->toBeFalse("{$label} : bankless");

        expect($scope->excludedMovieIds)
            ->toBe(decoyScopeSorted($round['earlier'], $round['cancelled'], $round['targetMovie']), $label)
            ->and($scope->excludedGroupIds)->toBe([$round['targetMovie']->group_id], $label);

        // Le complément « catalogue publié » garde les exclusions et la
        // non-répétition, mais lâche les thèmes et N.
        $complement = $scope->withThemeIds([])->withFramesPerRound(null);

        expect($complement->excludedMovieIds)->toBe($scope->excludedMovieIds)
            ->and($complement->noRepeatMovies)->toBe($scope->noRepeatMovies)
            ->and($complement->framesPerRound)->toBeNull()
            ->and(in_array($bankless->id, decoyScopeIds($complement), true))->toBeTrue($label)
            ->and(in_array($round['targetMovie']->id, decoyScopeIds($complement), true))->toBeFalse($label);
    }

    // La manche programmée n'est exclue qu'une fois son T₁ franchi : un job
    // de composition exécuté en retard compose à l'instant THÉORIQUE.
    $game = PoolFixtures::game(Room::factory()->create(), PoolFixtures::settings(noRepeatMovies: false));
    $round = decoyScopeGame($game, $this->at);

    expect(decoyScopeIds(PoolScope::forDecoys($round['target'], $this->at)))->toContain($round['future']->id)
        ->and(decoyScopeIds(PoolScope::forDecoys($round['target'], $this->at->addMinute())))
        ->not->toContain($round['future']->id);
});

it("forGame reconstruit le vivier du salon depuis l'instantané figé", function () {
    $theme = Theme::factory()->published()->create();
    $room = Room::factory()->create();
    $snapshot = PoolFixtures::settings(noRepeatMovies: true);

    $complete = PoolFixtures::movie();
    $themed = PoolFixtures::movie();
    $thin = PoolFixtures::movie([FrameLevel::Level1, FrameLevel::Level5]);
    $playedBefore = PoolFixtures::movie();
    PoolFixtures::member($themed, $theme);

    // Une partie précédente du salon a joué un film.
    PoolFixtures::round(PoolFixtures::game($room), $playedBefore, $this->at->subHour());

    $game = PoolFixtures::game($room, $snapshot);

    // Après le lancement, l'hôte change les réglages du salon : N minimal et un
    // thème. La partie lancée n'en voit rien.
    $changed = PoolFixtures::settings(
        themeIds: [$theme->id],
        framesPerRound: RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
        noRepeatMovies: false,
    );
    $room->forceFill([
        'settings' => $changed,
        'frames_per_round' => $changed->framesPerRound,
    ])->save();

    $scope = PoolScope::forGame($game->refresh(), $this->at);

    expect($scope->themeIds)->toBe($snapshot->themeIds)
        ->and($scope->framesPerRound)->toBe($snapshot->framesPerRound)
        ->and($scope->noRepeatMovies)->toBeTrue()
        ->and($scope->roomId)->toBe($room->id)
        ->and($scope->memorySince?->equalTo(RoomMemoryWindow::since($room->id, $this->at)))->toBeTrue()
        ->and($scope->playedUntil?->equalTo($this->at))->toBeTrue()
        ->and($scope->excludedMovieIds)->toBe([])
        ->and($scope->excludedGroupIds)->toBe([]);

    $fromSnapshot = decoyScopeIds(PoolScope::forRoom($room->refresh(), $game->settings_snapshot, $this->at));

    expect(decoyScopeIds($scope))->toBe($fromSnapshot)
        ->and(decoyScopeIds($scope))->toBe(decoyScopeSorted($complete, $themed))
        // Les réglages courants du salon donnent un autre vivier.
        ->and(decoyScopeIds(PoolScope::forRoom($room, $room->settings, $this->at)))->toBe([$themed->id])
        ->and($thin->id)->not->toBeIn(decoyScopeIds($scope));
});
