<?php

use App\Enums\FrameLevel;
use App\Enums\Locale;
use App\Enums\RoundStatus;
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
| Tirage des leurres du QCM — spec 70 § 10.2-10.4, contrat C11, D21 du 23/09
|--------------------------------------------------------------------------
|
| `DecoyPicker::pick()` porte l'échelle R1-R4 : vivier du salon au même
| profil de titre, catalogue publié non-répétition conservée, puis bascule de
| TOUT le salon sur le titre original, avec des leurres de même forme de titre
| original ; NULL au-delà (cas terminal). Toujours exclus : la cible, son
| `movie_group`, les films des manches déjà démarrées ; jamais ceux des
| manches futures.
|
| Les graines sont FIXES : un tirage à graine aléatoire ferait de chaque
| assertion d'ordre un pile ou face. Chaque film est un vrai film de vivier —
| variantes publiées, projection calculée par le projecteur (`PoolFixtures`),
| titres inventés ; seuls les tests du masque périmé écrivent la projection à
| la main, pour simuler un `catalog:reproject` qui n'a pas encore tourné.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    // L'instant THÉORIQUE de composition : `started_at` de la manche plus le
    // décalage du palier d'ouverture du QCM.
    $this->at = CarbonImmutable::parse('2026-09-24 10:00:00.250');
});

/**
 * Graines fixes, au format de `game.draw_seed` (64 caractères hexadécimaux).
 *
 * @return list<string>
 */
function decoyDrawSeeds(): array
{
    return array_map(
        static fn (int $index): string => hash('sha256', "decoy-draw-seed-{$index}"),
        range(1, 8),
    );
}

/** Un titre inventé, unique dans le test. */
function decoyDrawTitle(): string
{
    /** @var string $words */
    $words = fake()->unique()->words(3, true);

    return Str::title($words);
}

/**
 * Un film de vivier au profil de titre voulu.
 *
 * @param  array<string, string>  $titles  locale de catalogue => titre ; `[]` = `en` et `fr` (défaut de `playable()`)
 * @param  list<FrameLevel>|null  $levels
 */
function decoyDrawMovie(
    array $titles = [],
    ?Theme $theme = null,
    ?string $original = null,
    ?string $latin = null,
    ?MovieGroup $group = null,
    ?array $levels = null,
): Movie {
    $state = $original === null
        ? null
        : static fn (MovieFactory $factory): MovieFactory => $factory->state([
            'title_original' => $original,
            'title_original_latin' => $latin,
        ]);

    $movie = PoolFixtures::movie($levels, $group, $state, $titles);

    if ($theme instanceof Theme) {
        PoolFixtures::member($movie, $theme);
    }

    return $movie;
}

/** @return array<string, string> Profil « français seul ». */
function decoyDrawFrenchOnly(): array
{
    return [Locale::French->value => decoyDrawTitle()];
}

/** @return array<string, string> Profil « anglais seul ». */
function decoyDrawEnglishOnly(): array
{
    return [Locale::English->value => decoyDrawTitle()];
}

/** @return array<string, string> Aucun titre dans une locale activée : masque nul. */
function decoyDrawNoActivatedTitle(): array
{
    return ['es' => decoyDrawTitle()];
}

/**
 * Une partie à graine fixe : multijoueur dans `$room`, solo sans salon.
 */
function decoyDrawGame(?Room $room, RoomSettings $settings, string $seed): Game
{
    $factory = $room instanceof Room ? Game::factory()->forRoom($room) : Game::factory()->solo();

    return $factory->withSettings($settings)->state(['draw_seed' => $seed])->create();
}

/** La manche cible, démarrée avant l'instant de composition. */
function decoyDrawTarget(Game $game, Movie $movie, CarbonImmutable $at): Round
{
    return PoolFixtures::round(
        $game,
        $movie,
        $at->subSeconds(RoomSettingsBounds::MIN_TIER_DURATION),
        RoundStatus::Running,
    );
}

/**
 * Le tirage, relu en base, sur une instance de `PoolQuery` neuve (liaison
 * `scoped`) : comme au premier appel d'un job.
 */
function decoyDrawPick(Round $round, CarbonImmutable $at, ?string $seed = null): ?DecoyPick
{
    $game = Game::query()->findOrFail($round->game_id);

    if ($seed !== null) {
        $game->forceFill(['draw_seed' => $seed])->save();
    }

    app()->forgetScopedInstances();

    return app(DecoyPicker::class)->pick(Round::query()->findOrFail($round->id), $game, $at);
}

/**
 * Le parcours attendu d'un rang SANS rejet : les candidats triés par
 * `movie.id`, dans l'ordre de `permutation($context, n)` de la graine de la
 * partie, jusqu'au troisième leurre — la lettre du § 10.4.
 *
 * @param  list<Movie>  $rank
 * @param  list<int>  $retained
 * @return list<int>
 */
function decoyDrawWalk(Game $game, DrawContext $context, array $rank, array $retained = []): array
{
    $ids = decoyDrawIds(...$rank);

    foreach (SeededPrf::forGame($game)->permutation($context, count($ids)) as $index) {
        if (count($retained) === DecoyPick::COUNT) {
            break;
        }

        $retained[] = $ids[$index];
    }

    return $retained;
}

/**
 * Identifiants croissants.
 *
 * @return list<int>
 */
function decoyDrawIds(Movie ...$movies): array
{
    $ids = array_map(static fn (Movie $movie): int => $movie->id, $movies);
    sort($ids);

    return $ids;
}

/**
 * Identifiants tirés, triés — pour comparer des ensembles.
 *
 * @return list<int>
 */
function decoyDrawSet(DecoyPick $pick): array
{
    $ids = $pick->movieIds;
    sort($ids);

    return $ids;
}

it('tire les leurres dans le vivier du salon au même profil de titre', function () {
    $theme = Theme::factory()->published()->create();
    $settings = PoolFixtures::settings(themeIds: [$theme->id]);
    $game = decoyDrawGame(Room::factory()->create(), $settings, decoyDrawSeeds()[0]);

    $target = decoyDrawMovie(theme: $theme);
    $round = decoyDrawTarget($game, $target, $this->at);

    // Vivier du salon, même profil (`en` et `fr`).
    $peers = [
        decoyDrawMovie(theme: $theme),
        decoyDrawMovie(theme: $theme),
        decoyDrawMovie(theme: $theme),
        decoyDrawMovie(theme: $theme),
    ];

    // Vivier du salon, autres profils : jamais tant que R1 suffit.
    $otherProfiles = [
        decoyDrawMovie(decoyDrawFrenchOnly(), $theme),
        decoyDrawMovie(decoyDrawEnglishOnly(), $theme),
        decoyDrawMovie(decoyDrawNoActivatedTitle(), $theme),
    ];

    // Catalogue publié hors thème, même profil : jamais tant que R1 suffit.
    $outside = [decoyDrawMovie(), decoyDrawMovie()];

    foreach (decoyDrawSeeds() as $seed) {
        $pick = decoyDrawPick($round, $this->at, $seed);
        $expected = decoyDrawWalk(
            $game->refresh(),
            DrawContext::decoys($round->sequence_index),
            $peers,
        );

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds)->toBe($expected);

        foreach ([...$otherProfiles, ...$outside] as $never) {
            expect($pick?->movieIds)->not->toContain($never->id);
        }
    }

    // Masque nul (aucun titre dans une locale activée) : la forme du titre
    // original entre dans le profil — des titres natifs ne sont jamais
    // associés à une cible latine, même au même masque.
    $nullTheme = Theme::factory()->published()->create();
    $nullGame = decoyDrawGame(
        Room::factory()->create(),
        PoolFixtures::settings(themeIds: [$nullTheme->id]),
        decoyDrawSeeds()[1],
    );
    $nullTarget = decoyDrawMovie(decoyDrawNoActivatedTitle(), $nullTheme);
    $nullRound = decoyDrawTarget($nullGame, $nullTarget, $this->at);

    $latinPeers = [
        decoyDrawMovie(decoyDrawNoActivatedTitle(), $nullTheme),
        decoyDrawMovie(decoyDrawNoActivatedTitle(), $nullTheme),
        decoyDrawMovie(decoyDrawNoActivatedTitle(), $nullTheme),
    ];
    $nativeSameMask = decoyDrawMovie(decoyDrawNoActivatedTitle(), $nullTheme, '青い猫の庭の歌');
    $transliteratedSameMask = decoyDrawMovie(decoyDrawNoActivatedTitle(), $nullTheme, '赤い月の森の歌', 'Akai Tsuki No Mori No Uta');

    foreach (decoyDrawSeeds() as $seed) {
        $pick = decoyDrawPick($nullRound, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds)->toBe(decoyDrawWalk(
                $nullGame->refresh(),
                DrawContext::decoys($nullRound->sequence_index),
                $latinPeers,
            ))
            ->and($pick?->movieIds)->not->toContain($nativeSameMask->id)
            ->and($pick?->movieIds)->not->toContain($transliteratedSameMask->id);
    }
});

it('complète par le catalogue publié en conservant la non-répétition', function () {
    $theme = Theme::factory()->published()->create();
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(themeIds: [$theme->id], noRepeatMovies: true);

    // Une partie précédente du salon a joué ce film, au même profil, hors
    // thème : il serait un complément parfait, la mémoire du salon l'écarte.
    $played = decoyDrawMovie();
    PoolFixtures::round(PoolFixtures::game($room, $settings), $played, $this->at->subHour());

    $game = decoyDrawGame($room, $settings, decoyDrawSeeds()[0]);
    $target = decoyDrawMovie(theme: $theme);
    $round = decoyDrawTarget($game, $target, $this->at);

    // R1 : un seul pair au même profil dans le vivier du salon.
    $inRoom = decoyDrawMovie(theme: $theme);
    decoyDrawMovie(decoyDrawFrenchOnly(), $theme);

    // R2 : le catalogue publié, thèmes et N levés — un film jouable à aucun N
    // y entre.
    $catalogue = decoyDrawMovie();
    $bankless = decoyDrawMovie(levels: [FrameLevel::Level3]);

    foreach (decoyDrawSeeds() as $seed) {
        $pick = decoyDrawPick($round, $this->at, $seed);

        // Le leurre retenu en R1 est conservé, en tête ; R2 complète dans
        // l'ordre de SA permutation, sur le même contexte.
        $expected = decoyDrawWalk(
            $game->refresh(),
            DrawContext::decoys($round->sequence_index),
            [$catalogue, $bankless],
            [$inRoom->id],
        );

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds)->toBe($expected)
            ->and($pick?->movieIds)->not->toContain($played->id);
    }

    // Témoin : sans la mémoire du salon, le film joué est bien un candidat du
    // complément — c'est la non-répétition, et elle seule, qui l'écarte.
    $fresh = decoyDrawGame(Room::factory()->create(), $settings, decoyDrawSeeds()[0]);
    $freshRound = decoyDrawTarget($fresh, $target, $this->at);
    $seen = [];

    foreach (decoyDrawSeeds() as $seed) {
        $seen = [...$seen, ...(decoyDrawPick($freshRound, $this->at, $seed)->movieIds ?? [])];
    }

    expect($seen)->toContain($played->id);
});

it('bascule tout le salon sur title_original à défaut de trois leurres au même profil', function () {
    $theme = Theme::factory()->published()->create();
    $game = decoyDrawGame(
        Room::factory()->create(),
        PoolFixtures::settings(themeIds: [$theme->id]),
        decoyDrawSeeds()[0],
    );

    $target = decoyDrawMovie(theme: $theme);
    $round = decoyDrawTarget($game, $target, $this->at);

    // Deux pairs au même profil dans tout le catalogue publié : R1 + R2 ne
    // donnent que deux leurres.
    $peer = decoyDrawMovie(theme: $theme);
    $peerOutside = decoyDrawMovie();

    // R3 : le vivier du salon, à la seule forme de titre original (latine),
    // quel que soit le masque.
    $sameForm = [
        $peer,
        decoyDrawMovie(decoyDrawFrenchOnly(), $theme),
        decoyDrawMovie(decoyDrawEnglishOnly(), $theme),
        decoyDrawMovie(decoyDrawNoActivatedTitle(), $theme),
    ];
    $native = decoyDrawMovie(decoyDrawFrenchOnly(), $theme, '白い鳥の海の歌');

    foreach (decoyDrawSeeds() as $seed) {
        $pick = decoyDrawPick($round, $this->at, $seed);

        // Repris à zéro, sur le contexte `decoysOriginal` : rien de ce que R1
        // et R2 avaient retenu n'est conservé — le pair hors thème, retenu en
        // R2, n'apparaît jamais, R3 suffisant.
        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeTrue()
            ->and($pick?->movieIds)->toBe(decoyDrawWalk(
                $game->refresh(),
                DrawContext::decoysOriginal($round->sequence_index),
                $sameForm,
            ))
            ->and($pick?->movieIds)->not->toContain($peerOutside->id)
            ->and($pick?->movieIds)->not->toContain($native->id);
    }

    // Un troisième pair au même profil suffit à rester en mode normal.
    decoyDrawMovie();

    expect(decoyDrawPick($round, $this->at)?->useOriginalTitle)->toBeFalse();
});

it("en mode dégradé n'associe que des films de même forme de titre original", function () {
    // 1. Cible au titre natif (sans translittération), seule de son profil.
    $theme = Theme::factory()->published()->create();
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(themeIds: [$theme->id], noRepeatMovies: true);

    $target = decoyDrawMovie(decoyDrawFrenchOnly(), $theme, '青い猫の庭');

    // Vivier du salon : une seule autre forme native.
    $nativeInRoom = decoyDrawMovie([], $theme, '赤い月の森');
    decoyDrawMovie(theme: $theme);
    decoyDrawMovie(decoyDrawFrenchOnly(), $theme, '白い鳥の海', 'Shiroi Tori No Umi');

    // Catalogue : deux formes natives, une troisième déjà jouée par le salon
    // (non-répétition conservée en R4), et d'autres formes.
    $nativeOutside = [
        decoyDrawMovie(decoyDrawNoActivatedTitle(), null, '黒い犬の山'),
        decoyDrawMovie(decoyDrawEnglishOnly(), null, '緑の星の川'),
    ];
    $nativePlayed = decoyDrawMovie(decoyDrawEnglishOnly(), null, '金の魚の空');
    PoolFixtures::round(PoolFixtures::game($room, $settings), $nativePlayed, $this->at->subHour());
    decoyDrawMovie();
    decoyDrawMovie(decoyDrawNoActivatedTitle(), null, '銀の花の谷', 'Gin No Hana No Tani');

    $game = decoyDrawGame($room, $settings, decoyDrawSeeds()[0]);
    $round = decoyDrawTarget($game, $target, $this->at);

    foreach (decoyDrawSeeds() as $seed) {
        $pick = decoyDrawPick($round, $this->at, $seed);

        // R3 fournit la seule native du salon, R4 complète par le catalogue,
        // moins les candidats de R3, sur le même contexte `decoysOriginal`.
        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeTrue()
            ->and($pick?->movieIds)->toBe(decoyDrawWalk(
                $game->refresh(),
                DrawContext::decoysOriginal($round->sequence_index),
                $nativeOutside,
                [$nativeInRoom->id],
            ));
    }

    // 2. Cible translittérée, dans un salon sans thème : seules les formes
    // translittérées du catalogue, quel que soit leur masque.
    $transliteratedGame = decoyDrawGame(Room::factory()->create(), PoolFixtures::settings(), decoyDrawSeeds()[1]);
    $transliteratedTarget = decoyDrawMovie(['de' => decoyDrawTitle()], null, '紫の雲の城', 'Murasaki No Kumo No Shiro');
    $transliterated = [
        ...Movie::query()->where('title_original_latin', 'Shiroi Tori No Umi')->get()->all(),
        ...Movie::query()->where('title_original_latin', 'Gin No Hana No Tani')->get()->all(),
        decoyDrawMovie([], null, '茶色の風の島', 'Chairo No Kaze No Shima'),
    ];
    $transliteratedRound = decoyDrawTarget($transliteratedGame, $transliteratedTarget, $this->at);

    foreach (decoyDrawSeeds() as $seed) {
        $pick = decoyDrawPick($transliteratedRound, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeTrue()
            ->and(decoyDrawSet($pick ?? throw new LogicException))->toBe(decoyDrawIds(...$transliterated));
    }
});

it('exclut la cible, son movie_group et les films des manches déjà démarrées', function () {
    // Non-répétition COUPÉE : l'exclusion des manches démarrées ne doit rien à
    // la mémoire du salon.
    foreach (['mode normal' => false, 'mode dégradé' => true] as $label => $degraded) {
        $theme = Theme::factory()->published()->create();
        $game = decoyDrawGame(
            Room::factory()->create(),
            PoolFixtures::settings(themeIds: [$theme->id], noRepeatMovies: false),
            decoyDrawSeeds()[0],
        );
        $group = MovieGroup::factory()->create();

        // En mode dégradé, les exclus sont les SEULS pairs au même profil que la
        // cible : sans exclusion, R1 suffirait.
        $profile = static fn (): array => $degraded ? decoyDrawFrenchOnly() : [];

        $earlier = decoyDrawMovie($profile(), $theme);
        $cancelled = decoyDrawMovie($profile(), $theme);
        $target = decoyDrawMovie($profile(), $theme, group: $group);
        $sibling = decoyDrawMovie($profile(), $theme, group: $group);

        PoolFixtures::round($game, $earlier, $this->at->subMinutes(2));
        PoolFixtures::round($game, $cancelled, $this->at->subMinute(), RoundStatus::Cancelled);
        $round = decoyDrawTarget($game, $target, $this->at);

        $allowed = [
            decoyDrawMovie(theme: $theme),
            decoyDrawMovie(theme: $theme),
            decoyDrawMovie(theme: $theme),
        ];

        foreach (decoyDrawSeeds() as $seed) {
            $pick = decoyDrawPick($round, $this->at, $seed);

            expect($pick)->not->toBeNull($label)
                ->and($pick?->useOriginalTitle)->toBe($degraded, $label)
                ->and(decoyDrawSet($pick ?? throw new LogicException))->toBe(decoyDrawIds(...$allowed), $label);
        }
    }
});

it("n'exclut jamais le film d'une manche future", function () {
    $game = decoyDrawGame(Room::factory()->create(), PoolFixtures::settings(noRepeatMovies: true), decoyDrawSeeds()[0]);

    $target = decoyDrawMovie();
    $round = decoyDrawTarget($game, $target, $this->at);

    // Démarrée À l'instant de composition : jouée (`started_at <= $at`).
    $atCompose = decoyDrawMovie();
    PoolFixtures::round($game, $atCompose, $this->at, RoundStatus::Running);

    // Programmées après l'instant de composition, à la milliseconde près, et
    // réserve jamais démarrée : jamais exclues.
    $future = [decoyDrawMovie(), decoyDrawMovie(), decoyDrawMovie()];
    PoolFixtures::round($game, $future[0], $this->at->addMillisecond(), RoundStatus::Pending);
    PoolFixtures::round($game, $future[1], $this->at->addMinute(), RoundStatus::Pending);
    PoolFixtures::round($game, $future[2], null, RoundStatus::Pending, reserve: true);

    // Ce sont les trois seuls candidats : chaque graine doit les tirer tous.
    foreach (decoyDrawSeeds() as $seed) {
        $pick = decoyDrawPick($round, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeFalse()
            ->and(decoyDrawSet($pick ?? throw new LogicException))->toBe(decoyDrawIds(...$future));
    }

    // Même règle au mode dégradé : une cible seule de son profil tire, en R3,
    // les films des manches futures.
    $degradedTheme = Theme::factory()->published()->create();
    $degradedGame = decoyDrawGame(
        Room::factory()->create(),
        PoolFixtures::settings(themeIds: [$degradedTheme->id]),
        decoyDrawSeeds()[1],
    );
    $degradedTarget = decoyDrawMovie(decoyDrawFrenchOnly(), $degradedTheme);
    $degradedRound = decoyDrawTarget($degradedGame, $degradedTarget, $this->at);
    $degradedFuture = [
        decoyDrawMovie(theme: $degradedTheme),
        decoyDrawMovie(theme: $degradedTheme),
        decoyDrawMovie(theme: $degradedTheme),
    ];

    foreach ($degradedFuture as $movie) {
        PoolFixtures::round($degradedGame, $movie, $this->at->addMinute(), RoundStatus::Pending);
    }

    $pick = decoyDrawPick($degradedRound, $this->at);

    expect($pick?->useOriginalTitle)->toBeTrue()
        ->and(decoyDrawSet($pick ?? throw new LogicException))->toBe(decoyDrawIds(...$degradedFuture));
});

it('est déterministe pour une graine et un sequenceIndex donnés', function () {
    [$first, $second] = decoyDrawSeeds();

    $target = decoyDrawMovie();
    $candidates = [];

    for ($index = 0; $index < 8; $index++) {
        $candidates[] = decoyDrawMovie();
    }

    // Même graine, même `sequence_index`, même catalogue : même tirage, d'un
    // appel à l'autre comme d'une partie à l'autre.
    $game = decoyDrawGame(Room::factory()->create(), PoolFixtures::settings(), $first);
    $round = decoyDrawTarget($game, $target, $this->at);
    $twin = decoyDrawGame(Room::factory()->create(), PoolFixtures::settings(), $first);
    $twinRound = decoyDrawTarget($twin, $target, $this->at);

    DB::enableQueryLog();
    $pick = decoyDrawPick($round, $this->at);
    $statements = array_map(static fn (array $query): string => (string) $query['query'], DB::getQueryLog());
    DB::disableQueryLog();

    $expected = decoyDrawWalk($game, DrawContext::decoys($round->sequence_index), $candidates);

    expect($pick?->movieIds)->toBe($expected)
        ->and($pick?->useOriginalTitle)->toBeFalse()
        ->and(decoyDrawPick($round, $this->at)?->movieIds)->toBe($expected)
        ->and(decoyDrawPick($twinRound, $this->at)?->movieIds)->toBe($expected);

    // Le tirage ne fait que lire : aucune écriture, aucun verrou.
    foreach ($statements as $statement) {
        expect(Str::lower(ltrim($statement)))->toStartWith('select')
            ->and(Str::lower($statement))->not->toContain('for update');
    }

    // Autre `sequence_index` : autre contexte, autre tirage.
    $shifted = decoyDrawGame(Room::factory()->create(), PoolFixtures::settings(), $first);
    $shiftedRound = Round::factory()
        ->forGame($shifted)
        ->forMovie($target)
        ->atSequence($round->sequence_index + 1)
        ->state(['started_at' => $this->at->subSeconds(RoomSettingsBounds::MIN_TIER_DURATION), 'status' => RoundStatus::Running])
        ->create();
    $shiftedExpected = decoyDrawWalk($shifted, DrawContext::decoys($shiftedRound->sequence_index), $candidates);

    expect(decoyDrawPick($shiftedRound, $this->at)?->movieIds)->toBe($shiftedExpected)
        ->and($shiftedExpected)->not->toBe($expected);

    // Autre graine : autre tirage.
    $other = decoyDrawGame(Room::factory()->create(), PoolFixtures::settings(), $second);
    $otherRound = decoyDrawTarget($other, $target, $this->at);
    $otherExpected = decoyDrawWalk($other, DrawContext::decoys($otherRound->sequence_index), $candidates);

    expect(decoyDrawPick($otherRound, $this->at)?->movieIds)->toBe($otherExpected)
        ->and($otherExpected)->not->toBe($expected);

    // La manche doit appartenir à la partie passée.
    expect(fn () => app(DecoyPicker::class)->pick($round, $other, $this->at))
        ->toThrow(InvalidArgumentException::class);
});

it('un masque de version périmée fait basculer en mode dégradé', function () {
    $staleVersion = Locale::MASK_VERSION - 1;

    // 1. Masque de la CIBLE à une version périmée (`catalog:reproject` pas
    // encore joué) : bascule, même avec quatre pairs au même profil.
    $theme = Theme::factory()->published()->create();
    $game = decoyDrawGame(Room::factory()->create(), PoolFixtures::settings(themeIds: [$theme->id]), decoyDrawSeeds()[0]);
    $target = decoyDrawMovie(theme: $theme);
    $round = decoyDrawTarget($game, $target, $this->at);
    $peers = [
        decoyDrawMovie(theme: $theme),
        decoyDrawMovie(theme: $theme),
        decoyDrawMovie(theme: $theme),
        decoyDrawMovie(theme: $theme),
    ];

    expect(decoyDrawPick($round, $this->at)?->useOriginalTitle)->toBeFalse();

    MovieProjection::query()->whereKey($target->id)->update(['title_mask_version' => $staleVersion]);

    $pick = decoyDrawPick($round, $this->at);

    expect($pick?->useOriginalTitle)->toBeTrue()
        ->and($pick?->movieIds)->toBe(decoyDrawWalk($game->refresh(), DrawContext::decoysOriginal($round->sequence_index), $peers));

    // 2. Masque de CANDIDATS périmé : ils ne comptent plus au même profil, et
    // les pairs restants ne font pas trois leurres.
    $englishTheme = Theme::factory()->published()->create();
    $englishGame = decoyDrawGame(Room::factory()->create(), PoolFixtures::settings(themeIds: [$englishTheme->id]), decoyDrawSeeds()[1]);
    $englishTarget = decoyDrawMovie(decoyDrawEnglishOnly(), $englishTheme);
    $englishRound = decoyDrawTarget($englishGame, $englishTarget, $this->at);
    $englishPeers = [
        decoyDrawMovie(decoyDrawEnglishOnly(), $englishTheme),
        decoyDrawMovie(decoyDrawEnglishOnly(), $englishTheme),
        decoyDrawMovie(decoyDrawEnglishOnly(), $englishTheme),
    ];

    expect(decoyDrawPick($englishRound, $this->at)?->useOriginalTitle)->toBeFalse();

    MovieProjection::query()->whereKey($englishPeers[2]->id)->update(['title_mask_version' => $staleVersion]);

    $pick = decoyDrawPick($englishRound, $this->at);

    expect($pick?->useOriginalTitle)->toBeTrue()
        ->and($pick?->movieIds)->toBe(decoyDrawWalk($englishGame->refresh(), DrawContext::decoysOriginal($englishRound->sequence_index), $englishPeers));

    // 3. Masque à la version courante mais au contenu périmé : un film qui n'a
    // qu'un titre anglais porte le masque « français ». Les quatre films
    // n'atteignent plus la même locale : bascule, jamais un QCM hétérogène.
    $frenchTheme = Theme::factory()->published()->create();
    $frenchGame = decoyDrawGame(Room::factory()->create(), PoolFixtures::settings(themeIds: [$frenchTheme->id]), decoyDrawSeeds()[2]);
    $frenchTarget = decoyDrawMovie(decoyDrawFrenchOnly(), $frenchTheme);
    $frenchRound = decoyDrawTarget($frenchGame, $frenchTarget, $this->at);
    $frenchPeers = [
        decoyDrawMovie(decoyDrawFrenchOnly(), $frenchTheme),
        decoyDrawMovie(decoyDrawFrenchOnly(), $frenchTheme),
    ];
    $mislabelled = decoyDrawMovie(decoyDrawEnglishOnly(), $frenchTheme);

    MovieProjection::query()->whereKey($mislabelled->id)->update(['title_locale_mask' => Locale::French->maskBit()]);

    $pick = decoyDrawPick($frenchRound, $this->at);

    expect($pick?->useOriginalTitle)->toBeTrue()
        ->and($pick?->movieIds)->toBe(decoyDrawWalk(
            $frenchGame->refresh(),
            DrawContext::decoysOriginal($frenchRound->sequence_index),
            [...$frenchPeers, $mislabelled],
        ));
});

it('en solo, tire les leurres dans le vivier catalogue du preset, sans non-répétition de salon', function () {
    $theme = Theme::factory()->published()->create();
    // L'instantané garde `noRepeatMovies` à vrai : il est ignoré en solo.
    $preset = PoolFixtures::settings(themeIds: [$theme->id], noRepeatMovies: true);
    $game = decoyDrawGame(null, $preset, decoyDrawSeeds()[0]);

    $target = decoyDrawMovie(theme: $theme);
    $themed = [
        decoyDrawMovie(theme: $theme),
        decoyDrawMovie(theme: $theme),
        decoyDrawMovie(theme: $theme),
    ];
    $playedHere = decoyDrawMovie(theme: $theme);
    $outside = [decoyDrawMovie(), decoyDrawMovie()];

    // Films joués récemment AILLEURS — une autre partie solo, un salon — :
    // aucune mémoire de salon ne les écarte.
    PoolFixtures::round(Game::factory()->solo()->withSettings($preset)->create(), $themed[0], $this->at->subHour());
    PoolFixtures::round(PoolFixtures::game(Room::factory()->create(), $preset), $themed[1], $this->at->subHour());

    // Un film déjà joué DANS cette partie reste exclu, comme partout.
    PoolFixtures::round($game, $playedHere, $this->at->subMinutes(2));
    $round = decoyDrawTarget($game, $target, $this->at);

    foreach (decoyDrawSeeds() as $seed) {
        $pick = decoyDrawPick($round, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds)->toBe(decoyDrawWalk(
                $game->refresh(),
                DrawContext::decoys($round->sequence_index),
                $themed,
            ));

        foreach ([$playedHere, ...$outside] as $never) {
            expect($pick?->movieIds)->not->toContain($never->id);
        }
    }
});

it("rejette un candidat dont le movie_group est pris ou dont une chaîne rendue double celle de la cible ou d'un leurre retenu", function () {
    $theme = Theme::factory()->published()->create();
    $game = decoyDrawGame(Room::factory()->create(), PoolFixtures::settings(themeIds: [$theme->id]), decoyDrawSeeds()[0]);

    $target = decoyDrawMovie([
        Locale::English->value => 'The Hollow Lantern Keeper',
        Locale::French->value => 'Le Gardien De La Lanterne Creuse',
    ], $theme);
    $round = decoyDrawTarget($game, $target, $this->at);

    // Deux films d'une même œuvre : jamais ensemble dans un QCM.
    $group = MovieGroup::factory()->create();
    $sameWork = [
        decoyDrawMovie(theme: $theme, group: $group),
        decoyDrawMovie(theme: $theme, group: $group),
    ];

    // Deux films dont les titres anglais ont la même forme normalisée : un
    // clic serait ambigu, un seul est retenu.
    $sameEnglish = [
        decoyDrawMovie([
            Locale::English->value => 'The Salt Orchard Witness',
            Locale::French->value => 'Le Témoin Du Verger De Sel',
        ], $theme),
        decoyDrawMovie([
            Locale::English->value => 'the salt orchard witness!',
            Locale::French->value => 'La Déposition Au Verger Salé',
        ], $theme),
    ];

    // Titre français de même forme que celui de la cible : jamais.
    $likeTarget = decoyDrawMovie([
        Locale::English->value => 'A Keeper Of Hollow Lights',
        Locale::French->value => 'le gardien de la lanterne creuse.',
    ], $theme);

    $always = decoyDrawMovie(theme: $theme);

    foreach (decoyDrawSeeds() as $seed) {
        $pick = decoyDrawPick($round, $this->at, $seed);
        $ids = $pick->movieIds ?? [];

        expect($pick?->useOriginalTitle)->toBeFalse()
            ->and($ids)->toContain($always->id)
            ->and($ids)->not->toContain($likeTarget->id)
            ->and(count(array_intersect($ids, decoyDrawIds(...$sameWork))))->toBe(1)
            ->and(count(array_intersect($ids, decoyDrawIds(...$sameEnglish))))->toBe(1);
    }
});
