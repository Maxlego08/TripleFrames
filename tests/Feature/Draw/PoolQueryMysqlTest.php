<?php

use App\Enums\RoundStatus;
use App\Models\MovieGroup;
use App\Models\Room;
use App\Models\Theme;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\PoolCandidate;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolScope;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Compte en œuvres sous MySQL — spec 30 § 3.4 et § 3.6, lot L30-2, contrat C18
|--------------------------------------------------------------------------
|
| SQLite ne connaît pas `ONLY_FULL_GROUP_BY`, actif sous MySQL en mode strict :
| un compte écrit avec un `GROUP BY` ou une colonne non agrégée passerait au
| vert ici et lèverait en production, au lobby. La forme retenue —
| `COUNT(DISTINCT CASE …) + COUNT(DISTINCT group_id)`, sans `GROUP BY` — est
| donc rejouée sur MySQL, avec les instants à la milliseconde de
| `round.started_at` (`timestamp(3)`).
|
| Groupe `mysql` au niveau du fichier : exclu de `composer test`, joué par le
| job CI `mysql-redis`, et il ÉCHOUE hors de MySQL au lieu de se sauter.
|
*/

pest()->group('mysql');

beforeEach(fn () => requireMysql());

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
});

it('le compte en œuvres est identique sur MySQL', function () {
    $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode');

    expect(is_object($mode) ? (string) $mode->mode : '')->toContain('ONLY_FULL_GROUP_BY');

    $now = CarbonImmutable::parse('2026-09-24 10:00:00.250');
    $theme = Theme::factory()->published()->create();
    $remakes = MovieGroup::factory()->create();
    $homonyms = MovieGroup::factory()->create();

    $original = PoolFixtures::movie(group: $remakes);
    PoolFixtures::movie(group: $remakes);
    $homonym = PoolFixtures::movie(group: $homonyms);
    PoolFixtures::movie(group: $homonyms, state: static fn (MovieFactory $movie): MovieFactory => $movie->unpublished());
    [$alone, $played, $scheduled] = PoolFixtures::movies(3);

    PoolFixtures::member($original, $theme);
    PoolFixtures::member($homonym, $theme);
    PoolFixtures::member($alone, $theme);

    $room = Room::factory()->create();
    $game = PoolFixtures::game($room);
    // Démarrée une milliseconde avant `now` : jouée.
    PoolFixtures::round($game, $played, $now->subMillisecond());
    // Programmée une milliseconde après `now` : pas encore jouée.
    PoolFixtures::round($game, $scheduled, $now->addMillisecond(), RoundStatus::Pending);

    $pool = app(PoolQuery::class);
    $n = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;

    $cases = [
        // 3 films seuls + 2 groupes.
        'catalogue' => [PoolScope::catalogue([], $n), 5],
        // Le film joué sort du vivier du salon, pas le programmé.
        'salon' => [PoolScope::forRoom($room, PoolFixtures::settings(noRepeatMovies: true), $now), 4],
        // L'union des thèmes : un membre de chaque groupe et un film seul.
        'thèmes' => [PoolScope::catalogue([$theme->id], $n), 3],
        'groupe exclu' => [PoolScope::catalogue([], $n)->excluding([], [$remakes->id]), 4],
    ];

    foreach ($cases as $label => [$scope, $expected]) {
        // Le même compte recalculé en PHP depuis les candidats : groupes
        // distincts plus films sans groupe.
        $candidates = $pool->candidates($scope);
        $grouped = array_filter(array_map(static fn (PoolCandidate $candidate): ?int => $candidate->groupId, $candidates));
        $alones = array_filter($candidates, static fn (PoolCandidate $candidate): bool => $candidate->groupId === null);

        expect($pool->countWorks($scope))->toBe($expected, $label)
            ->and(count(array_unique($grouped)) + count($alones))->toBe($expected, $label);
    }
});
