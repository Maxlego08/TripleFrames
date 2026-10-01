<?php

use App\Enums\Locale;
use App\Enums\RoundStatus;
use App\Enums\ThemeKind;
use App\Models\Collection;
use App\Models\Game;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\MovieProjection;
use App\Models\Room;
use App\Models\Round;
use App\Models\Theme;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Answers\DecoyPicker;
use App\Support\Draw\DrawContext;
use App\Support\Draw\SeededPrf;
use App\ValueObjects\Answers\DecoyPick;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Leurres apparentés à la cible — spec 70 § 10.3 bis, D44 du 01/10
|--------------------------------------------------------------------------
|
| Avant l'échelle R1-R4, `DecoyPicker::pick()` cherche ses trois leurres
| parmi les films apparentés à la cible, dans le catalogue publié entier :
| (A) même saga TMDB, (B) même thème studio, saga ou manuel, du plus
| spécifique au plus large, (C) même thème de genre. Un groupe n'est retenu
| que s'il fournit À LUI SEUL les trois leurres : les quatre propositions
| appartiennent alors au même groupe nommé, et la cible ne se lit pas dans
| un mélange « deux Toy Story et deux Pixar ».
|
| Graines fixes, vrais films de vivier (`PoolFixtures`), titres inventés.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->at = CarbonImmutable::parse('2026-10-01 10:00:00.250');
});

/** @return list<string> */
function affinitySeeds(): array
{
    return array_map(
        static fn (int $index): string => hash('sha256', "decoy-affinity-seed-{$index}"),
        range(1, 6),
    );
}

/**
 * Un film de vivier, éventuellement d'une saga et membre de thèmes.
 *
 * @param  list<Theme>  $themes
 * @param  array<string, string>  $titles  `[]` = `en` et `fr`
 */
function affinityMovie(
    ?Collection $collection = null,
    array $themes = [],
    array $titles = [],
    ?string $original = null,
    ?MovieGroup $group = null,
): Movie {
    $attributes = [];

    if ($collection instanceof Collection) {
        $attributes['collection_id'] = $collection->id;
    }

    if ($original !== null) {
        $attributes['title_original'] = $original;
        $attributes['title_original_latin'] = null;
    }

    $movie = PoolFixtures::movie(
        null,
        $group,
        $attributes === [] ? null : static fn (MovieFactory $factory): MovieFactory => $factory->state($attributes),
        $titles,
    );

    foreach ($themes as $theme) {
        PoolFixtures::member($movie, $theme);
    }

    return $movie;
}

/**
 * `count` films de vivier de la même saga et des mêmes thèmes.
 *
 * @param  list<Theme>  $themes
 * @return list<Movie>
 */
function affinityMovies(int $count, ?Collection $collection = null, array $themes = []): array
{
    return array_map(static fn (): Movie => affinityMovie($collection, $themes), range(1, $count));
}

/** Un thème de la nature voulue, publié ; `manual` = `rule_value` nul. */
function affinityTheme(ThemeKind $kind, bool $manual = false, int $sortOrder = 0, bool $published = true): Theme
{
    return Theme::factory()->state([
        'key' => $kind->value.'.affinity-'.Str::lower(Str::random(10)),
        'theme_kind' => $kind,
        'rule_value' => $manual ? null : (string) fake()->unique()->numberBetween(20_000, 90_000),
        'sort_order' => $sortOrder,
        'is_published' => $published,
    ])->create();
}

function affinityGame(?Room $room = null, ?RoomSettings $settings = null): Game
{
    $settings ??= PoolFixtures::settings();
    $factory = Game::factory()->forRoom($room ?? Room::factory()->create());

    return $factory->withSettings($settings)->state(['draw_seed' => affinitySeeds()[0]])->create();
}

function affinityRound(Game $game, Movie $movie, CarbonImmutable $at): Round
{
    return PoolFixtures::round($game, $movie, $at->subSeconds(RoomSettingsBounds::MIN_TIER_DURATION), RoundStatus::Running);
}

function affinityPick(Round $round, CarbonImmutable $at, ?string $seed = null): ?DecoyPick
{
    if ($seed !== null) {
        Game::query()->whereKey($round->game_id)->update(['draw_seed' => $seed]);
    }

    $game = Game::query()->findOrFail($round->game_id);

    app()->forgetScopedInstances();

    return app(DecoyPicker::class)->pick(Round::query()->findOrFail($round->id), $game, $at);
}

/**
 * Le parcours attendu d'un groupe sans rejet : membres triés par `movie.id`,
 * dans l'ordre de `permutation($context, n)`.
 *
 * @param  list<Movie>  $group
 * @return list<int>
 */
function affinityWalk(Game $game, DrawContext $context, array $group): array
{
    $ids = affinityIds(...$group);
    $retained = [];

    foreach (SeededPrf::forGame($game)->permutation($context, count($ids)) as $index) {
        $retained[] = $ids[$index];

        if (count($retained) === DecoyPick::COUNT) {
            break;
        }
    }

    return $retained;
}

/** @return list<int> */
function affinityIds(Movie ...$movies): array
{
    $ids = array_map(static fn (Movie $movie): int => $movie->id, $movies);
    sort($ids);

    return $ids;
}

/** @return list<int> */
function affinitySet(?DecoyPick $pick): array
{
    $ids = $pick->movieIds ?? [];
    sort($ids);

    return $ids;
}

it('ne propose que des Toy Story pour Toy Story 3 quand trois autres Toy Story sont publiés', function () {
    $toyStory = Collection::factory()->named('Toy Story Collection')->create();
    $pixar = affinityTheme(ThemeKind::Studio);
    $animation = affinityTheme(ThemeKind::Genre);

    $target = affinityMovie($toyStory, [$pixar, $animation]);
    $sequels = affinityMovies(3, $toyStory, [$pixar, $animation]);

    // D'autres Pixar, d'autres films d'animation, et un catalogue quelconque :
    // jamais choisis tant que la saga suffit.
    $otherPixar = affinityMovies(4, null, [$pixar, $animation]);
    $otherAnimation = affinityMovies(4, null, [$animation]);
    $unrelated = affinityMovies(4);

    $game = affinityGame();
    $round = affinityRound($game, $target, $this->at);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeFalse()
            ->and(affinitySet($pick))->toBe(affinityIds(...$sequels))
            ->and($pick?->movieIds)->toBe(affinityWalk($game->refresh(), DrawContext::decoysAffinity($round->sequence_index), $sequels));

        foreach ([...$otherPixar, ...$otherAnimation, ...$unrelated] as $never) {
            expect($pick?->movieIds)->not->toContain($never->id);
        }
    }

    // Parmi plus de trois Toy Story, le tirage parcourt toute la saga.
    $more = affinityMovies(3, $toyStory, [$pixar]);
    $seen = [];

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);
        $seen = [...$seen, ...($pick->movieIds ?? [])];

        expect($pick?->movieIds)->each->toBeIn(affinityIds(...$sequels, ...$more));
    }

    expect(array_values(array_unique($seen)))->not->toHaveCount(DecoyPick::COUNT);
});

it('avec un seul autre Toy Story, ne mélange jamais : les trois leurres viennent tous du thème Pixar', function () {
    $toyStory = Collection::factory()->create();
    $pixar = affinityTheme(ThemeKind::Studio);
    $animation = affinityTheme(ThemeKind::Genre);

    $target = affinityMovie($toyStory, [$pixar, $animation]);
    $sequel = affinityMovie($toyStory, [$pixar, $animation]);
    $otherPixar = affinityMovies(3, null, [$pixar, $animation]);

    // Le genre est plus peuplé de non-Pixar : il ne passe qu'après le studio.
    $otherAnimation = affinityMovies(6, null, [$animation]);
    affinityMovies(3);

    $game = affinityGame();
    $round = affinityRound($game, $target, $this->at);
    $pixarMembers = affinityIds($sequel, ...$otherPixar);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->movieIds)->each->toBeIn($pixarMembers)
            ->and($pick?->movieIds)->toBe(affinityWalk(
                $game->refresh(),
                DrawContext::decoysAffinity($round->sequence_index),
                [$sequel, ...$otherPixar],
            ));

        foreach ($otherAnimation as $never) {
            expect($pick?->movieIds)->not->toContain($never->id);
        }
    }
});

it('propose trois Marvel pour un Marvel sans collection, le thème le plus spécifique en premier', function () {
    $marvel = affinityTheme(ThemeKind::Studio, sortOrder: 250);
    // Un studio plus large, mieux classé par `sort_order` : la spécificité
    // (le moins de candidats éligibles) passe avant.
    $disney = affinityTheme(ThemeKind::Studio, sortOrder: 200);
    $action = affinityTheme(ThemeKind::Genre);

    $target = affinityMovie(null, [$marvel, $disney, $action]);
    $marvels = affinityMovies(4, null, [$marvel, $disney, $action]);
    $disneyOnly = affinityMovies(5, null, [$disney]);
    $actionOnly = affinityMovies(3, null, [$action]);

    $game = affinityGame();
    $round = affinityRound($game, $target, $this->at);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->movieIds)->each->toBeIn(affinityIds(...$marvels))
            ->and($pick?->movieIds)->toBe(affinityWalk($game->refresh(), DrawContext::decoysAffinity($round->sequence_index), $marvels));

        foreach ([...$disneyOnly, ...$actionOnly] as $never) {
            expect($pick?->movieIds)->not->toContain($never->id);
        }
    }

    // À effectif égal, `sort_order` départage ; un thème manuel (règle nulle)
    // de genre compte au palier des studios et des sagas.
    $tieTarget = affinityMovie(null, [$first = affinityTheme(ThemeKind::Studio, sortOrder: 10), $second = affinityTheme(ThemeKind::Genre, manual: true, sortOrder: 5)]);
    $inFirst = affinityMovies(3, null, [$first]);
    $inSecond = affinityMovies(3, null, [$second]);
    $tieRound = affinityRound(affinityGame(), $tieTarget, $this->at);

    foreach (affinitySeeds() as $seed) {
        expect(affinitySet(affinityPick($tieRound, $this->at, $seed)))->toBe(affinityIds(...$inSecond));
    }

    expect($inFirst)->toHaveCount(3);
});

it('retombe sur un thème de genre quand ni la saga ni le studio ne fournissent trois leurres', function () {
    $saga = Collection::factory()->create();
    $studio = affinityTheme(ThemeKind::Studio);
    // Un thème de saga non publié compte : l'appartenance active suffit.
    $sagaTheme = affinityTheme(ThemeKind::Saga, published: false);
    $scienceFiction = affinityTheme(ThemeKind::Genre);
    // Une décennie n'apparente pas deux films.
    $decade = affinityTheme(ThemeKind::Decade);

    $target = affinityMovie($saga, [$studio, $sagaTheme, $scienceFiction, $decade]);
    $sagaPeers = affinityMovies(2, $saga, [$sagaTheme]);
    $studioPeer = affinityMovie(null, [$studio]);
    $genrePeers = affinityMovies(4, null, [$scienceFiction]);
    $decadePeers = affinityMovies(5, null, [$decade]);

    $game = affinityGame();
    $round = affinityRound($game, $target, $this->at);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        expect($pick?->movieIds)->toBe(affinityWalk($game->refresh(), DrawContext::decoysAffinity($round->sequence_index), $genrePeers));

        foreach ([...$sagaPeers, $studioPeer, ...$decadePeers] as $never) {
            expect($pick?->movieIds)->not->toContain($never->id);
        }
    }

    // Le thème de saga non publié, complété, l'emporte sur le genre.
    $more = affinityMovie(null, [$sagaTheme]);

    foreach (affinitySeeds() as $seed) {
        expect(affinitySet(affinityPick($round, $this->at, $seed)))->toBe(affinityIds($more, ...$sagaPeers));
    }
});

it("retombe sur l'échelle R1-R4 inchangée quand aucun groupe ne suffit", function () {
    $decade = affinityTheme(ThemeKind::Decade);
    $saga = Collection::factory()->create();
    $studio = affinityTheme(ThemeKind::Studio);
    $genre = affinityTheme(ThemeKind::Genre);

    $settings = PoolFixtures::settings(themeIds: [$decade->id]);
    $game = affinityGame(null, $settings);
    $target = affinityMovie($saga, [$decade, $studio, $genre]);
    $round = affinityRound($game, $target, $this->at);

    // Deux apparentés par groupe, hors du vivier du salon.
    $related = [
        ...affinityMovies(2, $saga),
        ...affinityMovies(2, null, [$studio]),
        ...affinityMovies(2, null, [$genre]),
    ];
    // R1 : le vivier du salon, au même profil.
    $roomPeers = affinityMovies(4, null, [$decade]);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        expect($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds)->toBe(affinityWalk($game->refresh(), DrawContext::decoys($round->sequence_index), $roomPeers));

        foreach ($related as $never) {
            expect($pick?->movieIds)->not->toContain($never->id);
        }
    }
});

it('garde la non-répétition du salon et les manches démarrées hors des groupes, jamais les manches futures', function () {
    $toyStory = Collection::factory()->create();
    $pixar = affinityTheme(ThemeKind::Studio);
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(noRepeatMovies: true);

    $target = affinityMovie($toyStory, [$pixar]);
    [$playedBefore, $startedHere, $future, $free] = affinityMovies(4, $toyStory, [$pixar]);

    // Joué à une partie précédente du salon : mémoire du salon.
    PoolFixtures::round(PoolFixtures::game($room, $settings), $playedBefore, $this->at->subHour());

    $game = affinityGame($room, $settings);
    // Démarré dans cette partie avant la cible.
    PoolFixtures::round($game, $startedHere, $this->at->subMinutes(2));
    $round = affinityRound($game, $target, $this->at);
    // Programmé après : jamais exclu.
    PoolFixtures::round($game, $future, $this->at->addMinutes(2), RoundStatus::Pending);

    // Saga : les exclus retirés, restent `$future` et `$free` — la saga ne
    // suffit pas, Pixar suit, sans les exclus non plus.
    $pixarOnly = affinityMovies(2, null, [$pixar]);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        expect($pick?->movieIds)->each->toBeIn(affinityIds($future, $free, ...$pixarOnly))
            ->and($pick?->movieIds)->not->toContain($playedBefore->id)
            ->and($pick?->movieIds)->not->toContain($startedHere->id)
            ->and($pick?->movieIds)->toBe(affinityWalk(
                $game->refresh(),
                DrawContext::decoysAffinity($round->sequence_index),
                [$future, $free, ...$pixarOnly],
            ));
    }
});

it('tire un leurre apparenté hors des thèmes du salon', function () {
    $decade = affinityTheme(ThemeKind::Decade);
    $saga = Collection::factory()->create();

    $game = affinityGame(null, PoolFixtures::settings(themeIds: [$decade->id]));
    $target = affinityMovie($saga, [$decade]);
    $round = affinityRound($game, $target, $this->at);

    // Le vivier du salon suffirait à R1 ; la saga, hors thème, passe avant.
    affinityMovies(4, null, [$decade]);
    $sagaPeers = affinityMovies(3, $saga);

    foreach (affinitySeeds() as $seed) {
        expect(affinitySet(affinityPick($round, $this->at, $seed)))->toBe(affinityIds(...$sagaPeers));
    }
});

it('est déterministe à graine égale et varie avec la graine', function () {
    $saga = Collection::factory()->create();
    $target = affinityMovie($saga);
    $peers = affinityMovies(7, $saga);
    $game = affinityGame();
    $round = affinityRound($game, $target, $this->at);
    $orders = [];

    foreach (affinitySeeds() as $seed) {
        $first = affinityPick($round, $this->at, $seed);
        $second = affinityPick($round, $this->at, $seed);

        expect($second?->movieIds)->toBe($first?->movieIds)
            ->and($first?->movieIds)->toBe(affinityWalk($game->refresh(), DrawContext::decoysAffinity($round->sequence_index), $peers));

        $orders[] = implode(',', $first->movieIds ?? []);
    }

    expect(array_unique($orders))->not->toHaveCount(1);
});

it('respecte toujours le profil de titre et rejette les collisions et les movie_group pris dans un groupe', function () {
    $saga = Collection::factory()->create();
    $pixar = affinityTheme(ThemeKind::Studio);
    $group = MovieGroup::factory()->create();

    $target = affinityMovie($saga, [$pixar], [Locale::English->value => 'Harbor Of Glass', Locale::French->value => 'Le Port De Verre']);

    // Saga : deux valides, un autre profil, un homonyme normalisé, deux
    // films de la même œuvre.
    $valid = affinityMovies(2, $saga);
    $frenchOnly = affinityMovie($saga, [], [Locale::French->value => 'La Cloche Lointaine']);
    $homonym = affinityMovie($saga, [], [Locale::English->value => 'Harbor of Glass!', Locale::French->value => 'Un Autre Titre']);
    $twins = [affinityMovie($saga, [], [], null, $group), affinityMovie($saga, [], [], null, $group)];

    $game = affinityGame();
    $round = affinityRound($game, $target, $this->at);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        // Trois leurres de la saga : les deux valides et UN seul des jumeaux.
        expect($pick?->movieIds)->toHaveCount(3)
            ->and($pick?->movieIds)->each->toBeIn(affinityIds(...$valid, ...$twins))
            ->and(array_values(array_intersect($pick->movieIds ?? [], affinityIds(...$twins))))->toHaveCount(1)
            ->and($pick?->movieIds)->not->toContain($frenchOnly->id)
            ->and($pick?->movieIds)->not->toContain($homonym->id);
    }

    // Sans les jumeaux, la saga ne fournit que deux leurres valides : jamais
    // de complément, Pixar prend tout.
    Movie::query()->whereKey($twins[1]->id)->update(['group_id' => null, 'collection_id' => null]);
    Movie::query()->whereKey($twins[0]->id)->update(['collection_id' => null]);
    $pixarPeers = affinityMovies(3, null, [$pixar]);

    foreach (affinitySeeds() as $seed) {
        expect(affinitySet(affinityPick($round, $this->at, $seed)))->toBe(affinityIds(...$pixarPeers));
    }
});

it('écarte un membre au masque périmé sans écarter le groupe qui garde trois leurres valides', function () {
    $saga = Collection::factory()->create();

    $target = affinityMovie($saga, [], [Locale::French->value => 'Le Phare Du Nord']);
    $frenchPeers = [
        affinityMovie($saga, [], [Locale::French->value => 'La Dune Rouge']),
        affinityMovie($saga, [], [Locale::French->value => 'Le Val Perdu']),
        affinityMovie($saga, [], [Locale::French->value => 'La Forge Noire']),
    ];
    // Masque à la version courante mais au contenu périmé : un titre anglais
    // seul sous le masque « français ». Il passe le filtre de masque, jamais
    // `reachSameLocales` : invalide à lui seul, il ne fait pas tomber la saga.
    $mislabelled = affinityMovie($saga, [], [Locale::English->value => 'The Silent Bay']);
    MovieProjection::query()->whereKey($mislabelled->id)->update(['title_locale_mask' => Locale::French->maskBit()]);

    $game = affinityGame();
    $round = affinityRound($game, $target, $this->at);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        expect($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds)->not->toContain($mislabelled->id)
            ->and($pick?->movieIds)->toBe(affinityWalk($game->refresh(), DrawContext::decoysAffinity($round->sequence_index), $frenchPeers));
    }
});

it("n'admet dans un groupe aucun membre qu'un groupe plus prioritaire aurait servi : la cible ne se lit pas par élimination", function () {
    // Cible Pixar hors saga : le groupe est Pixar. Un Toy Story parmi les
    // leurres serait éliminable — Toy Story cible, la saga l'aurait emporté.
    $toyStory = Collection::factory()->create();
    $pixar = affinityTheme(ThemeKind::Studio);
    $animation = affinityTheme(ThemeKind::Genre);

    $target = affinityMovie(null, [$pixar, $animation]);
    $sagaMembers = affinityMovies(4, $toyStory, [$pixar, $animation]);
    $standalone = affinityMovies(3, null, [$pixar, $animation]);

    $game = affinityGame();
    $round = affinityRound($game, $target, $this->at);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        expect(affinitySet($pick))->toBe(affinityIds(...$standalone))
            ->and($pick?->movieIds)->toBe(affinityWalk($game->refresh(), DrawContext::decoysAffinity($round->sequence_index), $standalone));
    }

    // Cible d'animation hors studio : le groupe est le genre. Un Pixar (dont
    // le studio aurait suffi) ni un Toy Story n'y entre.
    $shrek = affinityMovie(null, [$animation]);
    $plain = affinityMovies(3, null, [$animation]);
    $shrekRound = affinityRound(affinityGame(), $shrek, $this->at);

    foreach (affinitySeeds() as $seed) {
        expect(affinitySet(affinityPick($shrekRound, $this->at, $seed)))->toBe(affinityIds(...$plain));
    }

});

it('en mode dégradé, applique les mêmes groupes au titre original avant R3-R4', function () {
    $saga = Collection::factory()->create();
    $decade = affinityTheme(ThemeKind::Decade);

    $game = affinityGame(null, PoolFixtures::settings(themeIds: [$decade->id]));
    // Cible en français seul : aucun autre film du catalogue n'a ce profil,
    // le mode normal est intenable.
    $target = affinityMovie($saga, [$decade], [Locale::French->value => 'Le Grand Phare']);
    $round = affinityRound($game, $target, $this->at);

    $sagaPeers = affinityMovies(3, $saga);
    $native = affinityMovie($saga, [], [], '白い鳥の海の歌');
    // R3 suffirait : le vivier du salon à la même forme latine.
    $roomPeers = affinityMovies(4, null, [$decade]);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        expect($pick?->useOriginalTitle)->toBeTrue()
            ->and($pick?->movieIds)->toBe(affinityWalk($game->refresh(), DrawContext::decoysAffinityOriginal($round->sequence_index), $sagaPeers))
            ->and($pick?->movieIds)->not->toContain($native->id);

        foreach ($roomPeers as $never) {
            expect($pick?->movieIds)->not->toContain($never->id);
        }
    }

    // Sans saga suffisante en mode dégradé : R3, inchangé.
    Movie::query()->whereKey($sagaPeers[0]->id)->update(['collection_id' => null]);

    foreach (affinitySeeds() as $seed) {
        $pick = affinityPick($round, $this->at, $seed);

        expect($pick?->useOriginalTitle)->toBeTrue()
            ->and($pick?->movieIds)->toBe(affinityWalk($game->refresh(), DrawContext::decoysOriginal($round->sequence_index), $roomPeers));
    }
});

it('tient un budget de requêtes constant, quelle que soit la taille du catalogue et des groupes', function () {
    $saga = Collection::factory()->create();
    $studio = affinityTheme(ThemeKind::Studio);
    $genre = affinityTheme(ThemeKind::Genre);

    $target = affinityMovie($saga, [$studio, $genre]);
    affinityMovie($saga);
    $game = affinityGame();
    $round = affinityRound($game, $target, $this->at);

    /** @return array{all: int, themes: int, titles: int} */
    $count = function () use ($round): array {
        app()->forgetScopedInstances();
        $fresh = Round::query()->findOrFail($round->id);
        $game = Game::query()->findOrFail($round->game_id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $pick = app(DecoyPicker::class)->pick($fresh, $game, $this->at);
        $queries = array_map(static fn (array $query): string => (string) $query['query'], DB::getQueryLog());
        DB::disableQueryLog();

        expect($pick)->not->toBeNull();

        return [
            'all' => count($queries),
            'themes' => count(array_filter($queries, static fn (string $sql): bool => preg_match('/from [`"]?movie_theme[`"]? /', $sql) === 1)),
            'titles' => count(array_filter($queries, static fn (string $sql): bool => preg_match('/from [`"]?movie_title[`"]? /', $sql) === 1)),
        ];
    };

    // Petit catalogue : la saga ne suffit pas, le studio suffit.
    affinityMovies(3, null, [$studio, $genre]);
    $small = $count();

    // Catalogue quatre fois plus grand, groupes plus peuplés.
    affinityMovies(8, null, [$studio, $genre]);
    affinityMovies(8, null, [$genre]);
    affinityMovies(8);
    $large = $count();

    // Deux lectures de thèmes (ceux de la cible, puis leurs membres), le
    // catalogue au profil et ses titres en une requête, plus les lectures de
    // la cible et du périmètre de `forDecoys` : jamais une requête par
    // candidat.
    expect($large)->toBe($small)
        ->and($small['themes'])->toBe(2)
        ->and($small['titles'])->toBe(2)
        ->and($small['all'])->toBeLessThanOrEqual(11);
});
