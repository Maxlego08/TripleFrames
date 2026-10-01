<?php

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameLevel;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\MovieProjection;
use App\Models\MovieTheme;
use App\Models\Room;
use App\Models\Theme;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\PoolCandidate;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolReporter;
use App\Support\Draw\PoolScope;
use App\Support\Draw\RoomMemoryWindow;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Constructeur unique du vivier — spec 30 § 3, lot L30-2, contrat C2
|--------------------------------------------------------------------------
|
| `PoolQuery` est LE prédicat de vivier : le compteur du lobby, la garde et le
| tirage du lancement, le solo, la supervision et les leurres le lisent tous.
| Les fixtures sont de vrais films à vraies variantes (`PoolFixtures`) : la
| projection est calculée par le projecteur, jamais écrite à la main.
|
| Les instants portent des millisecondes : `round.started_at` est un
| `timestamp(3)`, et une comparaison tronquée à la seconde ferait basculer une
| manche démarrée à la frontière d'un côté ou de l'autre selon le moteur.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->now = CarbonImmutable::parse('2026-09-24 10:00:00.250');
});

/**
 * Identifiants des films du vivier, dans l'ordre rendu par `movies()`.
 *
 * @return list<int>
 */
function poolQueryIds(PoolScope $scope, ?PoolQuery $pool = null): array
{
    return array_values(array_map(
        static fn (Movie $movie): int => $movie->id,
        ($pool ?? app(PoolQuery::class))->movies($scope)->get()->all(),
    ));
}

/**
 * Identifiants triés, pour comparer à `poolQueryIds()`.
 *
 * @return list<int>
 */
function poolQuerySorted(Movie ...$movies): array
{
    $ids = array_map(static fn (Movie $movie): int => $movie->id, $movies);
    sort($ids);

    return $ids;
}

/**
 * Le SQL émis pendant `$run`, capturé par `DB::listen`.
 *
 * @return list<string>
 */
function poolQueryCapture(Closure $run): array
{
    $captured = [];
    $listening = true;

    DB::listen(static function (QueryExecuted $query) use (&$captured, &$listening): void {
        if ($listening) {
            $captured[] = $query->sql;
        }
    });

    try {
        $run();
    } finally {
        $listening = false;
    }

    return $captured;
}

/**
 * Requêtes qui lisent la table `theme` (et non `theme_label` ni `movie_theme`).
 *
 * @param  list<string>  $queries
 * @return list<string>
 */
function poolQueryThemeReads(array $queries): array
{
    return array_values(array_filter(
        $queries,
        static fn (string $sql): bool => preg_match('/\bfrom\s+["`]?theme["`]?(?![\w])/i', $sql) === 1,
    ));
}

/**
 * Le `N` par défaut du salon, lu dans ses bornes.
 */
function poolQueryDefaultN(): int
{
    return RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;
}

it("le vivier compte des œuvres : deux films d'un même movie_group comptent pour un", function () {
    $remakes = MovieGroup::factory()->create();
    $homonyms = MovieGroup::factory()->create();

    $original = PoolFixtures::movie(group: $remakes);
    $remake = PoolFixtures::movie(group: $remakes);
    $homonym = PoolFixtures::movie(group: $homonyms);
    // Hors vivier : il ne compte pas pour son groupe, qui reste UNE œuvre par
    // son seul membre publié.
    PoolFixtures::movie(group: $homonyms, state: static fn (MovieFactory $movie): MovieFactory => $movie->unpublished());
    [$alone, $single] = PoolFixtures::movies(2);

    $pool = app(PoolQuery::class);
    $scope = PoolScope::catalogue([], poolQueryDefaultN());

    // 2 films seuls + 2 groupes = 4 œuvres, pour 5 films au vivier.
    expect($pool->countWorks($scope))->toBe(4)
        ->and($pool->movies($scope)->count())->toBe(5)
        ->and(poolQueryIds($scope))->toBe(poolQuerySorted($original, $remake, $homonym, $alone, $single));

    $candidates = $pool->candidates($scope);

    expect(array_map(static fn (PoolCandidate $candidate): int => $candidate->movieId, $candidates))
        ->toBe(poolQuerySorted($original, $remake, $homonym, $alone, $single));

    $groups = [];

    foreach ($candidates as $candidate) {
        $groups[$candidate->movieId] = $candidate->groupId;
    }

    expect($groups[$original->id])->toBe($remakes->id)
        ->and($groups[$remake->id])->toBe($remakes->id)
        ->and($groups[$homonym->id])->toBe($homonyms->id)
        ->and($groups[$alone->id])->toBeNull();

    // Exclure un membre ne retire pas l'œuvre ; exclure le groupe la retire.
    expect($pool->countWorks($scope->excluding([$original->id], [])))->toBe(4)
        ->and($pool->countWorks($scope->excluding([], [$remakes->id])))->toBe(3)
        ->and(poolQueryIds($scope->excluding([], [$remakes->id])))
        ->toBe(poolQuerySorted($homonym, $alone, $single));
});

it('le vivier ne teste que levels_count >= N, jamais le masque 1-3-5', function () {
    // Publié puis devenu incomplet : ni niveau 1 ni niveau 5, mais trois niveaux.
    $incomplete = PoolFixtures::movie([FrameLevel::Level2, FrameLevel::Level3, FrameLevel::Level4]);
    $passOne = PoolFixtures::movie();
    $thin = PoolFixtures::movie([FrameLevel::Level1, FrameLevel::Level5]);

    $publishable = MovieProjection::publishableLevelsMask();
    $mask = MovieProjection::query()->findOrFail($incomplete->id)->levels_mask;

    expect($mask & $publishable)->not->toBe($publishable);

    $pool = app(PoolQuery::class);
    $threeFrames = PoolScope::catalogue([], poolQueryDefaultN());

    $queries = poolQueryCapture(function () use ($pool, $threeFrames): void {
        $pool->countWorks($threeFrames);
        $pool->movies($threeFrames)->get();
    });

    expect(poolQueryIds($threeFrames))->toBe(poolQuerySorted($incomplete, $passOne))
        ->and(poolQueryIds($threeFrames->withFramesPerRound(RoomSettingsBounds::MIN_FRAMES_PER_ROUND)))
        ->toBe(poolQuerySorted($incomplete, $passOne, $thin))
        ->and(poolQueryIds($threeFrames->withFramesPerRound(RoomSettingsBounds::MAX_FRAMES_PER_ROUND)))
        ->toBe([]);

    // Aucun masque dans le prédicat : ni colonne, ni opérateur binaire.
    foreach ($queries as $sql) {
        expect($sql)->not->toContain('levels_mask')
            ->and($sql)->not->toContain('&');
    }
});

it('une sélection de thèmes vide prend la branche sans thème', function () {
    $theme = Theme::factory()->published()->create();
    [$themed, $untouched] = PoolFixtures::movies(2);
    PoolFixtures::member($themed, $theme);

    $pool = app(PoolQuery::class);
    $scope = PoolScope::catalogue([], poolQueryDefaultN());

    $queries = poolQueryCapture(function () use ($pool, $scope): void {
        $pool->countWorks($scope);
        $pool->movies($scope)->get();
        $pool->candidates($scope);
    });

    // Tout le catalogue jouable, jamais zéro : aucun thème choisi n'est pas un
    // thème vide.
    expect($pool->countWorks($scope))->toBe(2)
        ->and(poolQueryIds($scope))->toBe(poolQuerySorted($themed, $untouched))
        ->and($pool->effectiveThemeIds($scope))->toBe([]);

    foreach ($queries as $sql) {
        expect($sql)->not->toContain('movie_theme');
    }

    // Le réglage par défaut du salon ne porte aucun thème.
    $room = Room::factory()->create();

    expect($pool->countWorks(PoolScope::forRoom($room, $room->settings, $this->now)))->toBe(2);
});

it('les thèmes se combinent en union', function () {
    $pixar = Theme::factory()->published()->create();
    $ghibli = Theme::factory()->published()->create();

    [$pixarOnly, $both, $ghibliOnly, $neither, $removed] = PoolFixtures::movies(5);

    PoolFixtures::member($pixarOnly, $pixar);
    PoolFixtures::member($both, $pixar);
    PoolFixtures::member($both, $ghibli);
    PoolFixtures::member($ghibliOnly, $ghibli);
    // Retrait manuel : la ligne survit, inactive, et ne filtre rien.
    MovieTheme::factory()->for($removed)->for($pixar)->manualRemoved()->create();

    $pool = app(PoolQuery::class);
    $n = poolQueryDefaultN();

    expect(poolQueryIds(PoolScope::catalogue([$pixar->id, $ghibli->id], $n)))
        ->toBe(poolQuerySorted($pixarOnly, $both, $ghibliOnly))
        // Un film des deux thèmes n'est compté qu'une fois.
        ->and($pool->countWorks(PoolScope::catalogue([$pixar->id, $ghibli->id], $n)))->toBe(3)
        ->and(poolQueryIds(PoolScope::catalogue([$pixar->id], $n)))->toBe(poolQuerySorted($pixarOnly, $both))
        ->and(poolQueryIds(PoolScope::catalogue([$ghibli->id], $n)))->toBe(poolQuerySorted($both, $ghibliOnly))
        ->and(poolQueryIds(PoolScope::catalogue([], $n)))
        ->toBe(poolQuerySorted($pixarOnly, $both, $ghibliOnly, $neither, $removed));
});

it('un thème dépublié est élagué, signalé par themesPruned, et ne produit jamais un IN vide', function () {
    $kept = Theme::factory()->published()->create();
    $dropped = Theme::factory()->unpublished()->create();

    [$inKept, $inDropped, $neither] = PoolFixtures::movies(3);
    PoolFixtures::member($inKept, $kept);
    PoolFixtures::member($inDropped, $dropped);

    $pool = app(PoolQuery::class);
    $n = poolQueryDefaultN();

    // Le thème dépublié est retiré de l'union : seul le thème publié filtre.
    $mixed = PoolScope::catalogue([$kept->id, $dropped->id], $n);

    expect(poolQueryIds($mixed))->toBe([$inKept->id])
        ->and($pool->effectiveThemeIds($mixed))->toBe([$kept->id])
        ->and($pool->themesPruned($mixed))->toBeTrue();

    // Seul thème demandé, et dépublié : branche sans thème, jamais `IN ()`.
    $onlyDropped = PoolScope::catalogue([$dropped->id], $n);

    $queries = poolQueryCapture(function () use ($pool, $onlyDropped): void {
        $pool->countWorks($onlyDropped);
        $pool->movies($onlyDropped)->get();
        $pool->candidates($onlyDropped);
    });

    expect(poolQueryIds($onlyDropped))->toBe(poolQuerySorted($inKept, $inDropped, $neither))
        ->and($pool->effectiveThemeIds($onlyDropped))->toBe([])
        ->and($pool->themesPruned($onlyDropped))->toBeTrue();

    // Laravel ne compile jamais un `whereIn([])` en `in ()` : il écrit `0 = 1`
    // (et `1 = 1` pour un `whereNotIn([])`). Le contrôle couvre les trois
    // formes ; ce périmètre ne porte aucune exclusion, donc aucune ne doit
    // apparaître (clause 5 : chaque clause omise quand sa liste est vide).
    foreach ($queries as $sql) {
        expect(preg_match('/\bin\s*\(\s*\)|\b0\s*=\s*1\b|\b1\s*=\s*1\b/i', $sql))->toBe(0, $sql)
            ->and($sql)->not->toContain('movie_theme');
    }

    expect($pool->themesPruned(PoolScope::catalogue([$kept->id], $n)))->toBeFalse()
        ->and($pool->themesPruned(PoolScope::catalogue([], $n)))->toBeFalse();
});

it('la mesure d’un thème non publié ne compte que ses films', function () {
    $theme = Theme::factory()->unpublished()->create();
    $other = Theme::factory()->published()->create();

    [$member, $removed, $grouped, $groupedToo, $outside, $tooShort] = [
        ...PoolFixtures::movies(2),
        ...(function (): array {
            $group = MovieGroup::factory()->create();

            return [PoolFixtures::movie(group: $group), PoolFixtures::movie(group: $group)];
        })(),
        PoolFixtures::movie(),
        // Éligible à aucun `N` par défaut : sa banque ne couvre qu'un niveau.
        PoolFixtures::movie([FrameLevel::Level1]),
    ];

    PoolFixtures::member($member, $theme);
    MovieTheme::factory()->for($removed)->for($theme)->auto()->manualRemoved()->create();
    PoolFixtures::member($grouped, $theme);
    PoolFixtures::member($groupedToo, $theme);
    PoolFixtures::member($outside, $other);
    PoolFixtures::member($tooShort, $theme);

    $pool = app(PoolQuery::class);
    $probe = PoolScope::themeProbe($theme->id);

    // Un film actif, plus un movie_group entier (une œuvre) ; ni l'exclusion
    // manuelle, ni le film d'un autre thème, ni celui inéligible au N par défaut.
    expect($probe->framesPerRound)->toBe(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND)
        ->and($probe->roomId)->toBeNull()
        ->and($probe->noRepeatMovies)->toBeFalse()
        ->and($pool->effectiveThemeIds($probe))->toBe([$theme->id])
        ->and($pool->themesPruned($probe))->toBeFalse()
        ->and(poolQueryIds($probe))->toBe(poolQuerySorted($member, $grouped, $groupedToo))
        ->and($pool->countWorks($probe))->toBe(2)
        ->and(app(PoolReporter::class)->themeWorks($theme->id))->toBe(2);

    // La mesure ne lit pas les thèmes publiés : aucune requête sur `theme`.
    $queries = poolQueryCapture(fn () => app(PoolQuery::class)->countWorks($probe));

    expect(poolQueryThemeReads($queries))->toBe([]);

    // Un thème publié se mesure de même.
    expect(app(PoolReporter::class)->themeWorks($other->id))->toBe(1);
});

it('aucun autre périmètre que la mesure d’un thème ne compte un thème non publié', function () {
    $theme = Theme::factory()->unpublished()->create();
    [$member, $outside] = PoolFixtures::movies(2);
    PoolFixtures::member($member, $theme);

    $pool = app(PoolQuery::class);
    $n = poolQueryDefaultN();
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(themeIds: [$theme->id]);

    $scopes = [
        'catalogue' => PoolScope::catalogue([$theme->id], $n),
        'salon' => PoolScope::forRoom($room, $settings, $this->now),
        'partie' => PoolScope::forGame(PoolFixtures::game($room, $settings), $this->now),
        'solo' => PoolScope::forGame(Game::factory()->solo()->withSettings($settings)->create(), $this->now),
    ];

    foreach ($scopes as $label => $scope) {
        expect($scope->themesUnpublishedIncluded)->toBeFalse($label)
            ->and($pool->effectiveThemeIds($scope))->toBe([], $label)
            ->and($pool->themesPruned($scope))->toBeTrue($label)
            // Élagué : branche sans thème, le film hors thème reste au vivier.
            ->and(poolQueryIds($scope, $pool))->toBe(poolQuerySorted($member, $outside), $label);
    }

    // La mesure est un périmètre fermé : un seul thème, jamais de salon.
    expect(PoolScope::themeProbe($theme->id)->themesUnpublishedIncluded)->toBeTrue()
        ->and(PoolScope::themeProbe($theme->id)->withThemeIds([$theme->id])->themesUnpublishedIncluded)->toBeTrue();
    expect(fn () => PoolScope::themeProbe($theme->id)->withThemeIds([]))
        ->toThrow(InvalidArgumentException::class);
});

it('un film suspendu, retiré, bloqué ou non vérifié est hors du vivier', function () {
    $playable = PoolFixtures::movie();

    $excluded = [
        'suspendu' => PoolFixtures::movie(state: static fn (MovieFactory $movie): MovieFactory => $movie->suspended()),
        'retiré' => PoolFixtures::movie(state: static fn (MovieFactory $movie): MovieFactory => $movie->withdrawn()),
        'bloqué' => PoolFixtures::movie(state: static fn (MovieFactory $movie): MovieFactory => $movie->contentFlag(ContentFlag::Blocked)),
        'non vérifié' => PoolFixtures::movie(state: static fn (MovieFactory $movie): MovieFactory => $movie->contentFlag(ContentFlag::UnratedPending)),
        'dépublié' => PoolFixtures::movie(state: static fn (MovieFactory $movie): MovieFactory => $movie->unpublished()),
        'brouillon' => PoolFixtures::movie(state: static fn (MovieFactory $movie): MovieFactory => $movie->state([
            'availability' => ContentAvailability::Draft,
        ])),
    ];

    // Chacun a une banque complète : c'est son état, et lui seul, qui l'exclut.
    foreach ($excluded as $label => $movie) {
        expect(MovieProjection::query()->findOrFail($movie->id)->supportsFramesPerRound(poolQueryDefaultN()))
            ->toBeTrue("{$label} : banque incomplète, le test ne prouverait rien");
    }

    $scope = PoolScope::catalogue([], poolQueryDefaultN());

    expect(poolQueryIds($scope))->toBe([$playable->id])
        ->and(app(PoolQuery::class)->countWorks($scope))->toBe(1);
});

it('la non-répétition retire les films démarrés dans la fenêtre du salon, manches annulées comprises', function () {
    $room = Room::factory()->create();
    $elsewhere = Room::factory()->create();
    $sequel = MovieGroup::factory()->create();

    $completed = PoolFixtures::movie(group: $sequel);
    $sibling = PoolFixtures::movie(group: $sequel);
    [$cancelled, $longAgo, $playedElsewhere, $fresh] = PoolFixtures::movies(4);

    $game = PoolFixtures::game($room);
    PoolFixtures::round($game, $completed, $this->now->subHour());
    PoolFixtures::round($game, $cancelled, $this->now->subMinutes(30), RoundStatus::Cancelled);

    // Hors de la fenêtre en jours : de nouveau disponible.
    PoolFixtures::round(
        PoolFixtures::game($room),
        $longAgo,
        $this->now->subDays(PlatformLimits::roomMemoryWindowDays())->subMillisecond(),
    );

    // Un autre salon n'a aucune mémoire commune avec celui-ci.
    PoolFixtures::round(PoolFixtures::game($elsewhere), $playedElsewhere, $this->now->subHour());

    $pool = app(PoolQuery::class);
    $scope = PoolScope::forRoom($room, PoolFixtures::settings(noRepeatMovies: true), $this->now);

    // Sur le FILM, jamais sur le groupe : la suite reste jouable.
    expect(poolQueryIds($scope))->toBe(poolQuerySorted($sibling, $longAgo, $playedElsewhere, $fresh))
        ->and($pool->countWorks($scope))->toBe(4)
        ->and($pool->countWorks($scope->withoutNoRepeat()))->toBe(5)
        ->and(poolQueryIds(PoolScope::forRoom($room, PoolFixtures::settings(noRepeatMovies: false), $this->now)))
        ->toBe(poolQuerySorted($completed, $sibling, $cancelled, $longAgo, $playedElsewhere, $fresh));
});

it('une manche de réserve jamais démarrée ne compte pas comme jouée', function () {
    $room = Room::factory()->create();
    [$played, $reserve] = PoolFixtures::movies(2);

    $game = PoolFixtures::game($room);
    PoolFixtures::round($game, $played, $this->now->subMinutes(5));
    PoolFixtures::round($game, $reserve, null, RoundStatus::Pending, reserve: true);

    $scope = PoolScope::forRoom($room, PoolFixtures::settings(noRepeatMovies: true), $this->now);

    expect(poolQueryIds($scope))->toBe([$reserve->id])
        ->and(app(PoolQuery::class)->countWorks($scope))->toBe(1);
});

it("une manche programmée dont T₁ n'est pas atteint ne compte pas comme jouée", function () {
    $room = Room::factory()->create();
    [$scheduled] = PoolFixtures::movies(1);
    $firstTier = $this->now->addSeconds(RoomSettingsBounds::MIN_TIER_DURATION);

    PoolFixtures::round(PoolFixtures::game($room), $scheduled, $firstTier, RoundStatus::Pending);

    $settings = PoolFixtures::settings(noRepeatMovies: true);

    // Avant T₁, fût-ce d'une milliseconde : pas jouée.
    expect(poolQueryIds(PoolScope::forRoom($room, $settings, $this->now)))->toBe([$scheduled->id])
        ->and(poolQueryIds(PoolScope::forRoom($room, $settings, $firstTier->subMillisecond())))
        ->toBe([$scheduled->id])
        // T₁ franchi, borne comprise : jouée.
        ->and(poolQueryIds(PoolScope::forRoom($room, $settings, $firstTier)))->toBe([]);
});

it('le vivier est monotone décroissant en N', function () {
    $room = Room::factory()->create();
    $pair = MovieGroup::factory()->create();

    PoolFixtures::movie([FrameLevel::Level1, FrameLevel::Level5]);
    PoolFixtures::movie();
    PoolFixtures::movie([FrameLevel::Level1, FrameLevel::Level2, FrameLevel::Level3, FrameLevel::Level5]);
    PoolFixtures::movie(FrameLevel::cases(), $pair);
    PoolFixtures::movie(FrameLevel::cases(), $pair);
    $played = PoolFixtures::movie(FrameLevel::cases());
    PoolFixtures::movie([]);

    PoolFixtures::round(PoolFixtures::game($room), $played, $this->now->subMinute());

    $pool = app(PoolQuery::class);
    $scopes = [
        'catalogue' => PoolScope::catalogue([], poolQueryDefaultN()),
        'salon' => PoolScope::forRoom($room, PoolFixtures::settings(noRepeatMovies: true), $this->now),
    ];

    $counts = [];

    foreach ($scopes as $label => $scope) {
        foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $n) {
            $counts[$label][$n] = $pool->countWorks($scope->withFramesPerRound($n));
        }

        foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND - 1) as $n) {
            expect($counts[$label][$n + 1])->toBeLessThanOrEqual($counts[$label][$n], "{$label} : N={$n}");
        }
    }

    // Le film sans variante n'est jamais au vivier ; la paire compte pour une.
    expect($counts['catalogue'])->toBe([2 => 5, 3 => 4, 4 => 3, 5 => 2])
        ->and($counts['salon'])->toBe([2 => 4, 3 => 3, 4 => 2, 5 => 1]);
});

it('les périmètres catalogue et solo ne portent aucune clause de salon', function () {
    $room = Room::factory()->create();
    [$played, $other] = PoolFixtures::movies(2);
    PoolFixtures::round(PoolFixtures::game($room), $played, $this->now->subMinute());

    $soloGame = Game::factory()->solo()->withSettings(PoolFixtures::settings(noRepeatMovies: true))->create();

    $pool = app(PoolQuery::class);

    // Le solo ne lit aucune mémoire de salon — pas même à sa construction —,
    // alors que son instantané garde la non-répétition à vrai (spec 30 § 9).
    $solo = null;
    $construction = poolQueryCapture(function () use (&$solo, $soloGame): void {
        $solo = PoolScope::forGame($soloGame, $this->now);
    });

    expect($soloGame->settings_snapshot->noRepeatMovies)->toBeTrue()
        ->and($construction)->toBe([]);

    $scopes = [
        'catalogue' => PoolScope::catalogue([], poolQueryDefaultN()),
        'solo' => $solo,
    ];

    foreach ($scopes as $label => $scope) {
        expect($scope)->toBeInstanceOf(PoolScope::class);
        assert($scope instanceof PoolScope);

        expect($scope->roomId)->toBeNull($label)
            ->and($scope->memorySince)->toBeNull($label)
            ->and($scope->playedUntil)->toBeNull($label)
            ->and($scope->noRepeatMovies)->toBeFalse($label)
            ->and($scope->excludedMovieIds)->toBe([], $label)
            ->and($scope->excludedGroupIds)->toBe([], $label);

        $ids = [];
        $queries = poolQueryCapture(function () use ($pool, $scope, &$ids): void {
            $pool->countWorks($scope);
            $ids = poolQueryIds($scope, $pool);
        });

        // Le film joué par un salon reste au vivier catalogue et au solo.
        expect($ids)->toBe(poolQuerySorted($played, $other), $label);

        foreach ($queries as $sql) {
            expect(preg_match('/["`]round["`]/', $sql))->toBe(0, "{$label} : {$sql}");
        }
    }
});

it("aucune requête de vivier n'émet de GROUP BY", function () {
    $room = Room::factory()->create();
    $theme = Theme::factory()->published()->create();
    $group = MovieGroup::factory()->create();

    [$target, $sibling] = [PoolFixtures::movie(group: $group), PoolFixtures::movie(group: $group)];
    [$themed, $played] = PoolFixtures::movies(2);
    PoolFixtures::member($themed, $theme);

    $game = PoolFixtures::game($room, PoolFixtures::settings(themeIds: [$theme->id], noRepeatMovies: true));
    PoolFixtures::round($game, $played, $this->now->subMinutes(2));
    $round = PoolFixtures::round($game, $target, $this->now->subSeconds(10), RoundStatus::Running);

    $pool = app(PoolQuery::class);

    $queries = poolQueryCapture(function () use ($pool, $room, $theme, $group, $round): void {
        RoomMemoryWindow::since($room->id, $this->now);

        $scopes = [
            PoolScope::catalogue([], poolQueryDefaultN()),
            PoolScope::forRoom($room, PoolFixtures::settings(themeIds: [$theme->id], noRepeatMovies: true), $this->now),
            PoolScope::forGame($round->game, $this->now),
            PoolScope::forDecoys($round, $this->now),
            PoolScope::forDecoys($round, $this->now)->withThemeIds([])->withFramesPerRound(null),
            PoolScope::catalogue([], poolQueryDefaultN())->excluding([], [$group->id]),
        ];

        foreach ($scopes as $scope) {
            $pool->countWorks($scope);
            $pool->candidates($scope);
            $pool->movies($scope)->get();
            $pool->movies($scope)->count();
            $pool->effectiveThemeIds($scope);
            $pool->themesPruned($scope);
        }

        $pool->publishedThemeIds();
        $pool->publishedThemeIdsByKey();
    });

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $sql) {
        expect(preg_match('/\bgroup\s+by\b/i', $sql))->toBe(0, $sql);
    }

    expect($sibling->group_id)->toBe($group->id);
});

it('la liste des thèmes publiés par clé et la liste des ids décrivent le même ensemble', function () {
    $second = Theme::factory()->withKey('genre.b')->sortedAt(20)->published()->create();
    $first = Theme::factory()->withKey('genre.a')->sortedAt(20)->published()->create();
    $studio = Theme::factory()->withKey('studio.x')->sortedAt(10)->published()->create();
    $hidden = Theme::factory()->withKey('genre.hidden')->sortedAt(5)->unpublished()->create();

    $pool = app(PoolQuery::class);
    $byKey = $pool->publishedThemeIdsByKey();
    $ids = $pool->publishedThemeIds();

    // Triés par sort_order, puis par clé.
    expect(array_keys($byKey))->toBe(['studio.x', 'genre.a', 'genre.b'])
        ->and($byKey)->toBe(['studio.x' => $studio->id, 'genre.a' => $first->id, 'genre.b' => $second->id]);

    // Les ids, croissants, sont exactement les valeurs de la liste par clé.
    $values = array_values($byKey);
    sort($values);

    expect($ids)->toBe($values)
        ->and($ids)->not->toContain($hidden->id);
});

it('une sélection de thèmes vide ne lit pas les thèmes publiés', function () {
    Theme::factory()->published()->create();
    PoolFixtures::movies(2);
    $room = Room::factory()->create();

    $queries = poolQueryCapture(function () use ($room): void {
        $pool = app(PoolQuery::class);

        foreach ([
            PoolScope::catalogue([], poolQueryDefaultN()),
            PoolScope::forRoom($room, $room->settings, $this->now),
        ] as $scope) {
            $pool->countWorks($scope);
            $pool->candidates($scope);
            $pool->movies($scope)->get();
            $pool->effectiveThemeIds($scope);
            $pool->themesPruned($scope);
        }
    });

    expect($queries)->not->toBeEmpty()
        ->and(poolQueryThemeReads($queries))->toBe([]);
});

it("les thèmes publiés ne sont lus qu'une fois par instance de PoolQuery et une dépublication vaut à l'instance suivante", function () {
    $pixar = Theme::factory()->published()->create();
    $ghibli = Theme::factory()->published()->create();

    [$inPixar, $inGhibli] = PoolFixtures::movies(2);
    PoolFixtures::movies(1);
    PoolFixtures::member($inPixar, $pixar);
    PoolFixtures::member($inGhibli, $ghibli);

    $pool = app(PoolQuery::class);
    $scope = PoolScope::catalogue([$pixar->id, $ghibli->id], poolQueryDefaultN());

    // Une instance par cycle de vie : le rapport et le tirage la partagent.
    expect(app(PoolQuery::class))->toBe($pool);

    $queries = poolQueryCapture(function () use ($pool, $scope): void {
        $pool->countWorks($scope);
        $pool->countWorks($scope);
        $pool->candidates($scope);
        $pool->movies($scope)->get();
        $pool->publishedThemeIds();
        $pool->publishedThemeIdsByKey();
        $pool->themesPruned($scope);
        $pool->countWorks($scope->withFramesPerRound(RoomSettingsBounds::MIN_FRAMES_PER_ROUND));
    });

    expect(poolQueryThemeReads($queries))->toHaveCount(1);

    Theme::query()->whereKey($ghibli->id)->update(['is_published' => false]);

    // La même instance garde sa lecture…
    expect($pool->countWorks($scope))->toBe(2)
        ->and($pool->themesPruned($scope))->toBeFalse();

    // … la suivante (requête ou job suivant) voit la dépublication.
    app()->forgetScopedInstances();
    $next = app(PoolQuery::class);

    expect($next)->not->toBe($pool)
        ->and($next->countWorks($scope))->toBe(1)
        ->and(poolQueryIds($scope, $next))->toBe([$inPixar->id])
        ->and($next->themesPruned($scope))->toBeTrue()
        ->and($next->publishedThemeIds())->toBe([$pixar->id]);
});

it("movies n'hydrate que les colonnes de movie", function () {
    $movie = PoolFixtures::movie();

    // Des horodatages de projection distincts de ceux du film : une jointure
    // sans `select` hydraterait ceux-ci.
    DB::table('movie_projection')->where('movie_id', $movie->id)->update([
        'created_at' => '2001-01-01 00:00:00',
        'updated_at' => '2001-01-01 00:00:00',
    ]);

    $hydrated = app(PoolQuery::class)->movies(PoolScope::catalogue([], poolQueryDefaultN()))->sole();

    expect(array_keys($hydrated->getAttributes()))->toEqualCanonicalizing(Schema::getColumnListing('movie'))
        ->and($hydrated->created_at?->equalTo($movie->created_at))->toBeTrue()
        ->and($hydrated->updated_at?->equalTo($movie->updated_at))->toBeTrue()
        ->and($hydrated->getAttributes())->not->toHaveKey('levels_count')
        ->and($hydrated->getAttributes())->not->toHaveKey('movie_id');
});
