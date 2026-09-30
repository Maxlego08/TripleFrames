<?php

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\MovieProjection;
use App\Models\Room;
use App\Models\SeenFrame;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\DrawContext;
use App\Support\Draw\DrawInput;
use App\Support\Draw\DrawnRound;
use App\Support\Draw\DrawnTier;
use App\Support\Draw\DrawResult;
use App\Support\Draw\GameDrawer;
use App\Support\Draw\PoolCandidate;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolReport;
use App\Support\Draw\PoolReporter;
use App\Support\Draw\PoolScope;
use App\Support\Draw\PoolTooSmallException;
use App\Support\Draw\SeededPrf;
use App\Support\Draw\VariantCandidate;
use App\Support\Draw\VariantChooser;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Tirage des films et des variantes — spec 30 § 6 et § 7, lot L30-5, contrat C3
|--------------------------------------------------------------------------
|
| `GameDrawer::drawFrom()` est une fonction PURE de (graine, `DrawInput`) : la
| plupart des preuves se jouent sans base, sur des entrées figées écrites ici.
| `GameDrawer::draw()` charge ces entrées en deux requêtes sur un `PoolScope` :
| les preuves qui portent sur le chargement (mémoire du salon, fenêtre, solo,
| projection périmée, égalité avec la garde) passent par de vrais films à
| vraies variantes (`PoolFixtures`), projection calculée par le projecteur.
|
| Les attendus de départage par la graine sont RECALCULÉS par `SeededPrf`
| (dont l'algorithme est figé par ses vecteurs de référence, L30-4), jamais
| recopiés : le test prouve que le tirage consulte le bon contexte sur le bon
| groupe d'égalité, pas qu'une valeur magique sort. Les trois contextes du
| tirage (§ 6.3) sont chacun épinglés par un oracle écrit sans `GameDrawer`
| (« un rejeu à graine et entrées figées… ») : `permutation(movies(), W)`
| pour l'ordre des œuvres, `index(workMember(s), |œuvre|)` pour le membre
| retenu, `variant(s, i)` pour CHAQUE palier `i`. Leur séparation est une
| propriété de secret (§ 5.1) : la même graine nourrit aussi les leurres et
| l'ordre du QCM, publics.
|
| `M` et `N` sont lus dans `RoomSettingsBounds`, la marge est posée par
| `platformLimitsConfigure()` : aucune valeur de jeu écrite en dur.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->now = CarbonImmutable::parse('2026-09-25 10:00:00.250');
});

/**
 * Une graine déterministe de 64 caractères hexadécimaux, indexée par `$k`.
 */
function gameDrawSeed(int $k): string
{
    return hash('sha256', 'game-draw:'.$k);
}

function gameDrawPrf(int $k = 0): SeededPrf
{
    return new SeededPrf(gameDrawSeed($k));
}

function gameDrawer(): GameDrawer
{
    return app(GameDrawer::class);
}

/**
 * Une banque de variantes pure : une variante par niveau listé (répéter un
 * niveau en donne plusieurs), `frameId = movieId × 100 + rang`.
 *
 * @param  list<FrameLevel>  $levels
 * @param  array<int, CarbonImmutable>  $seenAt  rang (0…) → `lastSeenAt`
 * @return list<VariantCandidate>
 */
function gameDrawBank(int $movieId, array $levels, array $seenAt = []): array
{
    $bank = [];

    foreach ($levels as $rank => $level) {
        $bank[] = new VariantCandidate($movieId * 100 + $rank + 1, $level, $seenAt[$rank] ?? null);
    }

    return $bank;
}

/**
 * Des entrées figées : films `$movieIds` (triés), groupes par `$groups`
 * (`movieId → groupId`), banque nominale du `N` sauf `$banks` explicites. Le
 * masque projeté de chaque candidat est celui de sa banque, sauf `$projected`.
 *
 * @param  list<int>  $movieIds
 * @param  array<int, int>  $groups
 * @param  array<int, list<VariantCandidate>>  $banks
 * @param  array<int, int>  $projected
 */
function gameDrawInput(
    array $movieIds,
    array $groups = [],
    array $banks = [],
    ?int $framesPerRound = null,
    int $roundsCount = RoomSettingsBounds::MIN_ROUNDS_COUNT,
    int $margin = 0,
    ?CarbonImmutable $memorySince = null,
    array $projected = [],
): DrawInput {
    $framesPerRound ??= RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;
    $candidates = [];
    $variantsByMovie = [];

    foreach ($movieIds as $movieId) {
        $bank = $banks[$movieId] ?? gameDrawBank($movieId, FrameLevelCoverage::nominal($framesPerRound));
        $variantsByMovie[$movieId] = $bank;

        $candidates[] = new PoolCandidate(
            movieId: $movieId,
            groupId: $groups[$movieId] ?? null,
            levelsMask: $projected[$movieId] ?? FrameLevelCoverage::maskOf(array_map(
                static fn (VariantCandidate $variant): FrameLevel => $variant->frameLevel,
                $bank,
            )),
        );
    }

    return new DrawInput($candidates, $variantsByMovie, $memorySince, $framesPerRound, $roundsCount, $margin);
}

/**
 * La séquence des films tirés, dans l'ordre des manches.
 *
 * @return list<int>
 */
function gameDrawSequence(DrawResult $result): array
{
    return array_map(static fn (DrawnRound $round): int => $round->movieId, $result->rounds);
}

/**
 * La manche tirée sur `$movieId`, ou nul.
 */
function gameDrawRoundOf(DrawResult $result, int $movieId): ?DrawnRound
{
    foreach ($result->rounds as $round) {
        if ($round->movieId === $movieId) {
            return $round;
        }
    }

    return null;
}

/**
 * L'exception de vivier trop petit levée par `$run`, ou un échec.
 */
function gameDrawTooSmall(Closure $run): PoolTooSmallException
{
    try {
        $run();
    } catch (PoolTooSmallException $exception) {
        return $exception;
    }

    throw new RuntimeException('PoolTooSmallException attendue, rien n’a été levé.');
}

/**
 * Les identifiants des variantes de niveau `$level` du film, croissants.
 *
 * @return list<int>
 */
function gameDrawFrameIds(Movie $movie, FrameLevel $level): array
{
    return Frame::query()
        ->where('movie_id', $movie->id)
        ->where('frame_level', $level->value)
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->values()
        ->all();
}

/**
 * Une ligne de mémoire du salon : `$frameId` vu par `$room` à `$seenAt`.
 */
function gameDrawSeen(Room $room, int $frameId, CarbonImmutable $seenAt): void
{
    SeenFrame::factory()->forRoom($room)->state([
        'frame_id' => $frameId,
        'last_seen_at' => $seenAt,
    ])->create();
}

/**
 * La variante que la graine désigne dans un groupe d'égalité : le groupe trié
 * par `frameId`, puis `index(variant(s, i), |groupe|)` — recalculé, jamais
 * recopié.
 *
 * @param  list<int>  $group
 */
function gameDrawSeedPick(SeededPrf $prf, int $sequenceIndex, int $tierIndex, array $group): int
{
    sort($group);

    return $group[$prf->index(DrawContext::variant($sequenceIndex, $tierIndex), count($group))];
}

/**
 * Le SQL émis pendant `$run`, capturé par `DB::listen`.
 *
 * @return list<string>
 */
function gameDrawQueries(Closure $run): array
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

it('le tirage rend min(M + marge, œuvres) films et au plus un par movie_group', function () {
    $roundsCount = RoomSettingsBounds::MIN_ROUNDS_COUNT;
    // Six films seuls, un groupe de trois (7, 8, 9), un groupe de deux
    // (10, 11) : onze films, huit œuvres.
    $groups = [7 => 70, 8 => 70, 9 => 70, 10 => 80, 11 => 80];
    $works = 8;
    $membersSeen = [70 => [], 80 => []];

    foreach ([0, 1, PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN, 5, 20] as $margin) {
        $input = gameDrawInput(range(1, 11), $groups, roundsCount: $roundsCount, margin: $margin);

        foreach (range(0, 7) as $k) {
            $result = gameDrawer()->drawFrom($input, gameDrawPrf($k));
            $sequence = gameDrawSequence($result);
            $drawnGroups = array_values(array_filter(array_map(
                static fn (int $movieId): ?int => $groups[$movieId] ?? null,
                $sequence,
            )));

            expect($result->rounds)->toHaveCount(min($roundsCount + $margin, $works))
                ->and($result->poolSize)->toBe($works)
                ->and($result->roundsCount)->toBe($roundsCount)
                ->and(array_unique($sequence))->toHaveCount(count($sequence))
                ->and(array_diff($sequence, range(1, 11)))->toBe([])
                // Au plus un film par movie_group, jamais deux Old Boy.
                ->and(array_unique($drawnGroups))->toHaveCount(count($drawnGroups));

            foreach ($sequence as $movieId) {
                if (isset($groups[$movieId])) {
                    $membersSeen[$groups[$movieId]][$movieId] = true;
                }
            }
        }
    }

    // Le membre retenu d'une œuvre est tiré par la graine (`workMember`), pas
    // toujours le plus petit : chaque groupe a vu plus d'un de ses films.
    expect(count($membersSeen[70]))->toBeGreaterThan(1)
        ->and(count($membersSeen[80]))->toBeGreaterThan(1);

    // Une marge hors bornes est refusée, jamais écrêtée.
    expect(fn () => gameDrawInput(range(1, 11), margin: -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => gameDrawInput(range(1, 11), margin: PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN + 1))
        ->toThrow(InvalidArgumentException::class);

    // Par la base : la marge est lue dans PlatformLimits, comptée en œuvres.
    platformLimitsConfigure(['draw_substitute_margin' => 1]);

    $remakes = MovieGroup::factory()->create();
    $original = PoolFixtures::movie(group: $remakes);
    $remake = PoolFixtures::movie(group: $remakes);
    PoolFixtures::movies(4);

    $result = gameDrawer()->draw(
        PoolScope::catalogue([], RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND),
        $roundsCount,
        gameDrawPrf(),
    );
    $sequence = gameDrawSequence($result);

    // Six films, cinq œuvres : K = min(M + 1, 5).
    expect($result->poolSize)->toBe(5)
        ->and($result->rounds)->toHaveCount(min($roundsCount + 1, 5))
        ->and(count(array_intersect($sequence, [$original->id, $remake->id])))->toBeLessThanOrEqual(1);
});

it('deux parties lancées à la même seconde sur le même vivier ne tirent pas la même séquence', function () {
    $this->freezeTime();

    $room = Room::factory()->create();
    $first = Game::factory()->forRoom($room)->create();
    $second = Game::factory()->forRoom($room)->create();

    // Même seconde, même salon : la graine ne dérive ni de l'horloge ni de la
    // partie, elle sort du CSPRNG (`SeededPrf::generateSeed()`).
    expect($first->started_at->equalTo($second->started_at))->toBeTrue()
        ->and($first->draw_seed)->not->toBe($second->draw_seed);

    $input = gameDrawInput(
        range(1, 20),
        roundsCount: RoomSettingsBounds::DEFAULT_ROUNDS_COUNT,
        margin: PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN,
    );

    $firstDraw = gameDrawer()->drawFrom($input, SeededPrf::forGame($first));
    $secondDraw = gameDrawer()->drawFrom($input, SeededPrf::forGame($second));

    expect(gameDrawSequence($firstDraw))->not->toBe(gameDrawSequence($secondDraw))
        ->and($firstDraw->poolSize)->toBe($secondDraw->poolSize);
});

it('un rejeu à graine et entrées figées reproduit exactement films et variantes', function () {
    $since = $this->now->subDays(PlatformLimits::DEFAULT_ROOM_MEMORY_WINDOW_DAYS);
    $levels = FrameLevel::cases();

    // Des œuvres groupées, plusieurs variantes par niveau, une mémoire du salon
    // partielle : chaque contexte de la graine est consulté.
    $banks = [];

    foreach (range(1, 12) as $movieId) {
        $banks[$movieId] = gameDrawBank(
            $movieId,
            [...$levels, ...$levels],
            [0 => $since->addDays($movieId), 5 => $since->subDay(), 6 => $since->addDays(2 * $movieId)],
        );
    }

    $input = gameDrawInput(
        range(1, 12),
        [3 => 30, 4 => 30, 9 => 90, 10 => 90, 11 => 90],
        $banks,
        roundsCount: RoomSettingsBounds::MIN_ROUNDS_COUNT,
        margin: PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN,
        memorySince: $since,
    );

    $first = gameDrawer()->drawFrom($input, new SeededPrf(gameDrawSeed(3)));
    // Nouvelle PRF sur la même graine, nouveau tireur : rien n'est mémorisé.
    $replay = (new GameDrawer(app(PoolQuery::class), new VariantChooser))
        ->drawFrom($input, new SeededPrf(gameDrawSeed(3)));

    expect($replay)->toEqual($first)
        ->and(gameDrawer()->drawFrom($input, gameDrawPrf(4)))->not->toEqual($first);

    // Des entrées égales (autre instance) rendent le même tirage.
    $rebuilt = new DrawInput(
        $input->candidates,
        $input->variantsByMovie,
        $input->memorySince,
        $input->framesPerRound,
        $input->roundsCount,
        $input->margin,
    );

    expect(gameDrawer()->drawFrom($rebuilt, new SeededPrf(gameDrawSeed(3))))->toEqual($first);

    // Les entrées sont figées dans un ordre : des candidats non triés sont
    // refusés, jamais retriés en silence.
    expect(fn () => new DrawInput(
        array_reverse($input->candidates),
        $input->variantsByMovie,
        $input->memorySince,
        $input->framesPerRound,
        $input->roundsCount,
        $input->margin,
    ))->toThrow(InvalidArgumentException::class);

    // Oracle écrit SANS GameDrawer, sur des entrées sans mémoire (toutes les
    // variantes d'un niveau forment le groupe d'égalité) : chaque contexte du
    // § 6.3 est épinglé. Un tirage qui emprunterait le flux d'un autre contexte
    // (leurres, QCM, autre manche, autre palier) sortirait de l'oracle.
    $groups = [3 => 30, 4 => 30, 9 => 90, 10 => 90, 11 => 90];
    $fullBanks = [];

    foreach (range(1, 12) as $movieId) {
        $fullBanks[$movieId] = gameDrawBank($movieId, [...$levels, ...$levels]);
    }

    $fullMask = FrameLevelCoverage::maskOf($levels);
    $membersPicked = [];

    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        $oracleInput = gameDrawInput(
            range(1, 12),
            $groups,
            $fullBanks,
            framesPerRound: $framesPerRound,
            roundsCount: RoomSettingsBounds::MIN_ROUNDS_COUNT,
            margin: PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN,
        );

        // 1. Les œuvres, par première apparition dans les candidats triés.
        $works = [];

        foreach ($oracleInput->candidates as $candidate) {
            $works[$candidate->groupId === null ? 'movie:'.$candidate->movieId : 'group:'.$candidate->groupId][] = $candidate->movieId;
        }

        $works = array_values($works);
        $target = min(RoomSettingsBounds::MIN_ROUNDS_COUNT + PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN, count($works));
        $tierLevels = FrameLevelCoverage::select($framesPerRound, $fullMask);

        // Une réserve existe (K > M) : les contextes des manches de réserve
        // sont éprouvés aussi.
        expect($works)->toHaveCount(9)
            ->and($target)->toBeGreaterThan(RoomSettingsBounds::MIN_ROUNDS_COUNT)
            ->and($tierLevels)->toHaveCount($framesPerRound);
        assert($tierLevels !== null);

        foreach (range(0, 5) as $k) {
            $result = gameDrawer()->drawFrom($oracleInput, gameDrawPrf($k));
            $prf = gameDrawPrf($k);

            // 2. L'ordre des œuvres : `permutation(movies(), W)`.
            $permutation = $prf->permutation(DrawContext::movies(), count($works));

            expect($result->rounds)->toHaveCount($target);

            // 3. Aucune œuvre sautée : la manche `s` est la `s`-ième de la
            //    permutation, son film tiré par `workMember(s)`.
            foreach (range(1, $target) as $sequenceIndex) {
                $members = $works[$permutation[$sequenceIndex - 1]];
                $movieId = count($members) === 1
                    ? $members[0]
                    : $members[$prf->index(DrawContext::workMember($sequenceIndex), count($members))];
                $round = $result->rounds[$sequenceIndex - 1];

                expect($round->sequenceIndex)->toBe($sequenceIndex)
                    ->and($round->movieId)->toBe($movieId)
                    ->and($round->tiers)->toHaveCount($framesPerRound);

                if (count($members) > 1) {
                    $membersPicked[$movieId] = true;
                }

                // 4. Chaque palier `i` : `variant(s, i)` sur les variantes du
                //    niveau `select(N, masque)[i − 1]`.
                foreach (range(1, $framesPerRound) as $tierIndex) {
                    $level = $tierLevels[$tierIndex - 1];
                    $tier = $round->tiers[$tierIndex - 1];
                    $sameLevel = array_values(array_map(
                        static fn (VariantCandidate $variant): int => $variant->frameId,
                        array_filter(
                            $fullBanks[$movieId],
                            static fn (VariantCandidate $variant): bool => $variant->frameLevel === $level,
                        ),
                    ));

                    expect($sameLevel)->toHaveCount(2)
                        ->and($tier->tierIndex)->toBe($tierIndex)
                        ->and($tier->frameLevel)->toBe($level)
                        ->and($tier->frameId)->toBe(gameDrawSeedPick($prf, $sequenceIndex, $tierIndex, $sameLevel));
                }
            }
        }
    }

    // L'oracle a bien traversé des œuvres à plusieurs membres, et plus d'un
    // membre de chacune.
    expect(count(array_intersect(array_keys($membersPicked), [3, 4])))->toBeGreaterThan(1)
        ->and(count(array_intersect(array_keys($membersPicked), [9, 10, 11])))->toBeGreaterThan(1);

    // Par la base : même PoolScope, même graine, même tirage.
    $room = Room::factory()->create();
    $movies = PoolFixtures::movies(5, [...FrameLevelCoverage::nominal(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND), FrameLevel::Level1]);
    gameDrawSeen($room, gameDrawFrameIds($movies[0], FrameLevel::Level1)[0], $this->now->subDay());

    $game = PoolFixtures::game($room, PoolFixtures::settings(roundsCount: RoomSettingsBounds::MIN_ROUNDS_COUNT));
    $scope = PoolScope::forRoom($room, $game->settings_snapshot, $this->now);

    $original = gameDrawer()->draw($scope, RoomSettingsBounds::MIN_ROUNDS_COUNT, SeededPrf::forGame($game));

    expect(gameDrawer()->draw($scope, RoomSettingsBounds::MIN_ROUNDS_COUNT, new SeededPrf($game->draw_seed)))
        ->toEqual($original);
});

it("les manches de réserve n'ont pas de numéro", function () {
    $minimum = RoomSettingsBounds::MIN_ROUNDS_COUNT;
    $margin = PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN;

    $numbersOf = static fn (DrawResult $result): array => array_map(
        static fn (DrawnRound $round): ?int => $round->roundNumber,
        $result->rounds,
    );
    $indexesOf = static fn (DrawResult $result): array => array_map(
        static fn (DrawnRound $round): int => $round->sequenceIndex,
        $result->rounds,
    );

    // W au-delà de M + marge : M manches numérotées, puis `marge` de réserve.
    $result = gameDrawer()->drawFrom(gameDrawInput(range(1, 10), roundsCount: $minimum, margin: $margin), gameDrawPrf());

    expect($indexesOf($result))->toBe(range(1, $minimum + $margin))
        ->and($numbersOf($result))->toBe([...range(1, $minimum), ...array_fill(0, $margin, null)]);

    // Au réglage par défaut.
    $default = gameDrawer()->drawFrom(gameDrawInput(
        range(1, 20),
        roundsCount: RoomSettingsBounds::DEFAULT_ROUNDS_COUNT,
        margin: $margin,
    ), gameDrawPrf(1));

    expect($numbersOf($default))->toBe([
        ...range(1, RoomSettingsBounds::DEFAULT_ROUNDS_COUNT),
        ...array_fill(0, $margin, null),
    ]);

    // W = M : aucune réserve, toutes les manches sont numérotées.
    $exact = gameDrawer()->drawFrom(gameDrawInput(range(1, $minimum), roundsCount: $minimum, margin: $margin), gameDrawPrf());

    expect($numbersOf($exact))->toBe(range(1, $minimum))
        ->and($indexesOf($exact))->toBe(range(1, $minimum));

    // Marge nulle : pas de réserve non plus.
    $noMargin = gameDrawer()->drawFrom(gameDrawInput(range(1, 10), roundsCount: $minimum), gameDrawPrf());

    expect($numbersOf($noMargin))->toBe(range(1, $minimum));
});

it('tier_index suit les niveaux croissants de FrameLevelCoverage', function () {
    $fallbacks = 0;

    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        // Trois banques complètes (deux variantes par niveau), puis, tant que
        // quatre niveaux suffisent, trois banques privées d'un niveau : le
        // repli de niveau y est exercé.
        $banks = [];

        foreach ([1, 2, 3] as $movieId) {
            $banks[$movieId] = gameDrawBank($movieId, [...FrameLevel::cases(), ...FrameLevel::cases()]);
        }

        foreach ([4 => FrameLevel::Level1, 5 => FrameLevel::Level3, 6 => FrameLevel::Level5] as $movieId => $missing) {
            $levels = array_values(array_filter(FrameLevel::cases(), static fn (FrameLevel $level): bool => $level !== $missing));

            if (count($levels) >= $framesPerRound) {
                $banks[$movieId] = gameDrawBank($movieId, $levels);
            }
        }

        $movieIds = array_keys($banks);
        $result = gameDrawer()->drawFrom(gameDrawInput(
            $movieIds,
            banks: $banks,
            framesPerRound: $framesPerRound,
            margin: count($movieIds),
        ), gameDrawPrf($framesPerRound));

        expect($result->rounds)->toHaveCount(count($movieIds));

        foreach ($result->rounds as $round) {
            $bank = $banks[$round->movieId];
            $mask = FrameLevelCoverage::maskOf(array_map(static fn (VariantCandidate $v): FrameLevel => $v->frameLevel, $bank));
            $expected = FrameLevelCoverage::select($framesPerRound, $mask);
            $fallbacks += FrameLevelCoverage::usesFallback($framesPerRound, $mask) ? 1 : 0;

            expect(array_map(static fn (DrawnTier $tier): int => $tier->tierIndex, $round->tiers))
                ->toBe(range(1, $framesPerRound))
                ->and(array_map(static fn (DrawnTier $tier): FrameLevel => $tier->frameLevel, $round->tiers))
                ->toBe($expected);

            $previous = 0;

            foreach ($round->tiers as $tier) {
                // Le niveau le plus cryptique disponible au palier 1, puis
                // strictement croissant.
                expect($tier->frameLevel->value)->toBeGreaterThan($previous);
                $previous = $tier->frameLevel->value;

                // La variante tirée est bien une variante de ce niveau.
                $levelOf = array_values(array_filter(
                    $bank,
                    static fn (VariantCandidate $variant): bool => $variant->frameId === $tier->frameId,
                ));

                expect($levelOf)->toHaveCount(1)
                    ->and($levelOf[0]->frameLevel)->toBe($tier->frameLevel);
            }
        }
    }

    // Le repli de niveau a bien été traversé (N = 2 à 4, banques incomplètes).
    expect($fallbacks)->toBeGreaterThan(0);

    // Un N hors bornes est refusé à l'entrée, jamais écrêté ; un périmètre sans
    // N ne se tire pas.
    expect(fn () => gameDrawInput([1, 2, 3], framesPerRound: RoomSettingsBounds::MAX_FRAMES_PER_ROUND + 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => gameDrawer()->draw(PoolScope::catalogue([], null), RoomSettingsBounds::MIN_ROUNDS_COUNT, gameDrawPrf()))
        ->toThrow(InvalidArgumentException::class);
});

it('préférence de variante : non vue par le salon, puis vue la moins récemment, puis la graine', function () {
    $chooser = new VariantChooser;
    $prf = gameDrawPrf();
    $since = $this->now->subDays(PlatformLimits::DEFAULT_ROOM_MEMORY_WINDOW_DAYS);
    $level = FrameLevel::Level1;
    $contexts = array_map(static fn (int $s): DrawContext => DrawContext::variant($s, 1), range(1, 24));

    // 1. Une non vue l'emporte sur toute variante vue, quel que soit le contexte.
    $oneUnseen = [
        new VariantCandidate(11, $level, $since->addDays(80)),
        new VariantCandidate(12, $level, null),
        new VariantCandidate(13, $level, $since->addDay()),
    ];

    foreach ($contexts as $context) {
        expect($chooser->choose($oneUnseen, $since, $prf, $context))->toBe(12);
    }

    // 2. Toutes vues : la moins récemment vue l'emporte.
    $allSeen = [
        new VariantCandidate(11, $level, $since->addDays(80)),
        new VariantCandidate(12, $level, $since->addDays(3)),
        new VariantCandidate(13, $level, $since->addDays(40)),
    ];

    foreach ($contexts as $context) {
        expect($chooser->choose($allSeen, $since, $prf, $context))->toBe(12);
    }

    // 3. Égalités : la graine départage, groupe trié par frameId, ordre d'entrée
    //    sans effet. Deux non vues parmi trois ; deux vues au même instant le
    //    moins récent.
    $unseenTie = [
        new VariantCandidate(14, $level, null),
        new VariantCandidate(12, $level, $since->addDays(5)),
        new VariantCandidate(11, $level, null),
    ];
    $leastRecentTie = [
        new VariantCandidate(14, $level, $since->addDays(2)),
        new VariantCandidate(12, $level, $since->addDays(9)),
        new VariantCandidate(11, $level, $since->addDays(2)),
    ];

    foreach ([$unseenTie, $leastRecentTie] as $candidates) {
        $picked = [];

        foreach ($contexts as $s => $context) {
            $choice = $chooser->choose($candidates, $since, $prf, $context);

            expect($choice)->toBe(gameDrawSeedPick($prf, $s + 1, 1, [11, 14]))
                ->and($chooser->choose(array_reverse($candidates), $since, $prf, $context))->toBe($choice);

            $picked[(int) $choice] = true;
        }

        // Les deux membres du groupe sortent : c'est bien la graine qui tranche.
        expect(array_keys($picked))->toEqualCanonicalizing([11, 14]);
    }

    // Une liste vide rend null ; un palier ne mêle jamais deux niveaux.
    expect($chooser->choose([], $since, $prf, $contexts[0]))->toBeNull()
        ->and(fn () => $chooser->choose([
            new VariantCandidate(11, FrameLevel::Level1, null),
            new VariantCandidate(12, FrameLevel::Level3, null),
        ], $since, $prf, $contexts[0]))->toThrow(InvalidArgumentException::class);

    // Par la base : la mémoire lue est celle DU salon, jamais d'un autre.
    platformLimitsConfigure(['draw_substitute_margin' => 0]);

    $room = Room::factory()->create();
    $elsewhere = Room::factory()->create();
    $nominal = FrameLevelCoverage::nominal(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);
    $movie = PoolFixtures::movie([FrameLevel::Level1, FrameLevel::Level1, FrameLevel::Level1, ...array_slice($nominal, 1)]);
    PoolFixtures::movies(RoomSettingsBounds::MIN_ROUNDS_COUNT - 1);
    [$old, $recent, $never] = gameDrawFrameIds($movie, FrameLevel::Level1);

    $settings = PoolFixtures::settings(roundsCount: RoomSettingsBounds::MIN_ROUNDS_COUNT);
    $drawTier1 = function (int $k) use ($room, $settings, $movie): int {
        $result = gameDrawer()->draw(PoolScope::forRoom($room, $settings, $this->now), $settings->roundsCount, gameDrawPrf($k));

        return gameDrawRoundOf($result, $movie->id)?->tiers[0]->frameId ?? 0;
    };

    // Vue par le salon : `$old` et `$recent` ; `$never` n'a été vue qu'ailleurs.
    gameDrawSeen($room, $old, $this->now->subDays(10));
    gameDrawSeen($room, $recent, $this->now->subDays(2));
    gameDrawSeen($elsewhere, $never, $this->now->subHour());

    foreach (range(0, 5) as $k) {
        expect($drawTier1($k))->toBe($never);
    }

    // Vue aussi par le salon, et plus récemment que les autres : la moins
    // récemment vue par le salon l'emporte.
    gameDrawSeen($room, $never, $this->now->subDay());

    foreach (range(0, 5) as $k) {
        expect($drawTier1($k))->toBe($old);
    }
});

it('une ligne seen_frame hors fenêtre est lue comme non vue', function () {
    $chooser = new VariantChooser;
    $since = $this->now->subDays(PlatformLimits::DEFAULT_ROOM_MEMORY_WINDOW_DAYS);
    $level = FrameLevel::Level1;

    // Pur : une ligne antérieure à memorySince entre dans le groupe des non
    // vues ; une ligne à memorySince pile est dans la fenêtre, donc vue.
    $outside = [new VariantCandidate(21, $level, $since->subSecond()), new VariantCandidate(22, $level, null)];
    $atBound = [new VariantCandidate(21, $level, $since), new VariantCandidate(22, $level, null)];
    $pickedOutside = [];

    foreach (range(0, 15) as $k) {
        $prf = gameDrawPrf($k);
        $context = DrawContext::variant(1, 1);
        $choice = $chooser->choose($outside, $since, $prf, $context);

        expect($choice)->toBe(gameDrawSeedPick($prf, 1, 1, [21, 22]))
            ->and($chooser->choose($atBound, $since, $prf, $context))->toBe(22);

        $pickedOutside[(int) $choice] = true;
    }

    expect(array_keys($pickedOutside))->toEqualCanonicalizing([21, 22]);

    // Par la base, fenêtre en jours : une ligne plus vieille que la fenêtre,
    // que la purge du jour n'a pas encore effacée, ne change pas le tirage.
    platformLimitsConfigure(['draw_substitute_margin' => 0]);

    $room = Room::factory()->create();
    $nominal = FrameLevelCoverage::nominal(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);
    $movie = PoolFixtures::movie([FrameLevel::Level1, ...$nominal]);
    PoolFixtures::movies(RoomSettingsBounds::MIN_ROUNDS_COUNT - 1);
    [$stale, $fresh] = gameDrawFrameIds($movie, FrameLevel::Level1);
    $settings = PoolFixtures::settings(roundsCount: RoomSettingsBounds::MIN_ROUNDS_COUNT);

    gameDrawSeen($room, $stale, $this->now->subDays(PlatformLimits::roomMemoryWindowDays() + 1));

    $pick = function (int $k) use ($room, $settings, $movie): array {
        $result = gameDrawer()->draw(PoolScope::forRoom($room, $settings, $this->now), $settings->roundsCount, gameDrawPrf($k));
        $round = gameDrawRoundOf($result, $movie->id);

        expect($round)->not->toBeNull();

        return [$round?->sequenceIndex ?? 0, $round?->tiers[0]->frameId ?? 0];
    };

    $picked = [];

    foreach (range(0, 15) as $k) {
        [$sequenceIndex, $frameId] = $pick($k);

        expect($frameId)->toBe(gameDrawSeedPick(gameDrawPrf($k), $sequenceIndex, 1, [$stale, $fresh]));
        $picked[$frameId] = true;
    }

    // La ligne hors fenêtre est lue comme absente : `$stale` sort aussi.
    expect(array_keys($picked))->toEqualCanonicalizing([$stale, $fresh]);

    // Fenêtre en manches : la N-ième manche jouée du salon la ferme plus tôt
    // que les jours, et la même règle s'applique à sa borne.
    SeenFrame::query()->delete();
    platformLimitsConfigure(['room_memory_window_rounds' => 1]);

    $played = PoolFixtures::movie();
    PoolFixtures::round(PoolFixtures::game($room, $settings), $played, $this->now->subDays(2));
    gameDrawSeen($room, $stale, $this->now->subDays(3));

    expect(PoolScope::forRoom($room, $settings, $this->now)->memorySince?->equalTo($this->now->subDays(2)))->toBeTrue();

    $picked = [];

    foreach (range(0, 15) as $k) {
        [$sequenceIndex, $frameId] = $pick($k);

        expect($frameId)->toBe(gameDrawSeedPick(gameDrawPrf($k), $sequenceIndex, 1, [$stale, $fresh]));
        $picked[$frameId] = true;
    }

    expect(array_keys($picked))->toEqualCanonicalizing([$stale, $fresh]);

    // Témoin : la même ligne DANS la fenêtre écarte `$stale` à chaque tirage.
    SeenFrame::query()->delete();
    gameDrawSeen($room, $stale, $this->now->subDay());

    foreach (range(0, 5) as $k) {
        expect($pick($k)[1])->toBe($fresh);
    }
});

it('le solo ignore la mémoire du salon et la non-répétition', function () {
    platformLimitsConfigure(['draw_substitute_margin' => 1]);

    $room = Room::factory()->create();
    $nominal = FrameLevelCoverage::nominal(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);
    $movie = PoolFixtures::movie([FrameLevel::Level1, ...$nominal]);
    $played = PoolFixtures::movie();
    PoolFixtures::movies(RoomSettingsBounds::MIN_ROUNDS_COUNT - 1);
    [$seen, $unseen] = gameDrawFrameIds($movie, FrameLevel::Level1);

    // Le réglage de non-répétition est actif : le solo le garde dans son
    // instantané et l'ignore.
    $settings = PoolFixtures::settings(noRepeatMovies: true, roundsCount: RoomSettingsBounds::MIN_ROUNDS_COUNT);
    PoolFixtures::round(PoolFixtures::game($room, $settings), $played, $this->now->subHour());
    gameDrawSeen($room, $seen, $this->now->subDay());

    $solo = Game::factory()->solo()->withSettings($settings)->create();
    $soloScope = PoolScope::forGame($solo, $this->now);

    expect($soloScope->roomId)->toBeNull()
        ->and($soloScope->memorySince)->toBeNull()
        ->and($soloScope->noRepeatMovies)->toBeFalse()
        ->and($solo->settings_snapshot->noRepeatMovies)->toBeTrue();

    $roomScope = PoolScope::forRoom($room, $settings, $this->now);
    $soloPicks = [];

    foreach (range(0, 11) as $k) {
        $result = null;
        $queries = gameDrawQueries(function () use (&$result, $soloScope, $settings, $k): void {
            $result = gameDrawer()->draw($soloScope, $settings->roundsCount, gameDrawPrf($k));
        });

        expect($result)->toBeInstanceOf(DrawResult::class);
        assert($result instanceof DrawResult);

        // Aucune lecture de mémoire : ni `seen_frame`, ni `round`.
        expect(array_filter($queries, static fn (string $sql): bool => preg_match('/\b(seen_frame|round)\b/i', $sql) === 1))
            ->toBe([]);

        // Non-répétition ignorée : le film joué par le salon est au vivier et
        // au tirage (K = W, toutes les œuvres sortent).
        expect($result->poolSize)->toBe(RoomSettingsBounds::MIN_ROUNDS_COUNT + 1)
            ->and(gameDrawSequence($result))->toContain($played->id);

        // Mémoire ignorée : la graine seule départage les deux variantes.
        $round = gameDrawRoundOf($result, $movie->id);
        $frameId = $round?->tiers[0]->frameId ?? 0;

        expect($frameId)->toBe(gameDrawSeedPick(gameDrawPrf($k), $round?->sequenceIndex ?? 0, 1, [$seen, $unseen]));
        $soloPicks[$frameId] = true;

        // Témoin multijoueur, même graine : le film joué est retiré, la
        // variante vue par le salon écartée.
        $multiplayer = gameDrawer()->draw($roomScope, $settings->roundsCount, gameDrawPrf($k));

        expect(gameDrawSequence($multiplayer))->not->toContain($played->id)
            ->and(gameDrawRoundOf($multiplayer, $movie->id)?->tiers[0]->frameId)->toBe($unseen);
    }

    // La variante vue par le salon sort aussi en solo.
    expect(array_keys($soloPicks))->toEqualCanonicalizing([$seen, $unseen]);
});

it('un vivier sous M lève PoolTooSmallException', function () {
    $minimum = RoomSettingsBounds::MIN_ROUNDS_COUNT;

    // W < M.
    $exception = gameDrawTooSmall(fn () => gameDrawer()->drawFrom(gameDrawInput(range(1, $minimum - 1)), gameDrawPrf()));

    expect($exception->works)->toBe($minimum - 1)
        ->and($exception->roundsCount)->toBe($minimum);

    // Assez de FILMS, pas assez d'ŒUVRES : deux groupes de deux.
    $exception = gameDrawTooSmall(fn () => gameDrawer()->drawFrom(
        gameDrawInput([1, 2, 3, 4], [1 => 10, 2 => 10, 3 => 20, 4 => 20]),
        gameDrawPrf(),
    ));

    expect($exception->works)->toBe(2)
        ->and($exception->roundsCount)->toBe($minimum);

    // W = M mais une œuvre sautée (projection périmée) : moins de M retenues.
    $nominal = FrameLevelCoverage::nominal(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);
    $exception = gameDrawTooSmall(fn () => gameDrawer()->drawFrom(gameDrawInput(
        range(1, $minimum),
        banks: [1 => gameDrawBank(1, array_slice($nominal, 1))],
        projected: [1 => FrameLevelCoverage::maskOf($nominal)],
    ), gameDrawPrf()));

    expect($exception->works)->toBe($minimum - 1)
        ->and($exception->roundsCount)->toBe($minimum);

    // Un M hors bornes est refusé à l'entrée, jamais écrêté.
    expect(fn () => gameDrawInput(range(1, 40), roundsCount: RoomSettingsBounds::MIN_ROUNDS_COUNT - 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => gameDrawInput(range(1, 40), roundsCount: RoomSettingsBounds::MAX_ROUNDS_COUNT + 1))
        ->toThrow(InvalidArgumentException::class);

    // Par la base : un vivier vide ou trop petit lève, sans rien écrire.
    PoolFixtures::movies($minimum - 1);

    $exception = gameDrawTooSmall(fn () => gameDrawer()->draw(
        PoolScope::catalogue([], RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND),
        $minimum,
        gameDrawPrf(),
    ));

    expect($exception->works)->toBe($minimum - 1)
        ->and($exception->roundsCount)->toBe($minimum);
});

it("une projection périmée fait sauter l'œuvre sans bloquer le tirage", function () {
    $minimum = RoomSettingsBounds::MIN_ROUNDS_COUNT;
    $framesPerRound = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;
    $nominal = FrameLevelCoverage::nominal($framesPerRound);

    // Chaque clause du prédicat de variante jouable (10 § 3.2 : `published` ET
    // `ready` ET `game_path` posé) cesse d'être vraie, chacune sur son film :
    // le tirage la lit par la portée `Frame::servable()` (L30-6, E71-3), que
    // ce test garde clause par clause côté tirage, `SubstitutionTest` côté
    // substitution.
    $staleWrites = [
        ['availability' => ContentAvailability::Draft->value],
        ...array_map(
            static fn (FrameProcessingState $state): array => ['processing_state' => $state->value],
            array_values(array_filter(
                FrameProcessingState::cases(),
                static fn (FrameProcessingState $state): bool => $state !== FrameProcessingState::Ready,
            )),
        ),
        ['game_path' => null],
    ];
    $staleCount = count($staleWrites);

    platformLimitsConfigure(['draw_substitute_margin' => PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN]);

    $movies = PoolFixtures::movies($minimum + 1 + $staleCount);
    $staleMovies = array_slice($movies, 1, $staleCount);
    $staleIds = array_map(static fn (Movie $movie): int => $movie->id, $staleMovies);

    // M + 1 œuvres retenables sous K = min(M + marge, W) : le parcours épuise
    // la permutation, il traverse forcément chaque œuvre périmée.
    expect(min($minimum + PlatformLimits::drawSubstituteMargin(), $minimum + 1 + $staleCount))
        ->toBeGreaterThan($minimum + 1);

    // La dernière variante d'un niveau quitte le jeu SANS reprojection (écriture
    // de masse, aucun événement de modèle) : la projection ment désormais.
    foreach ($staleMovies as $offset => $stale) {
        Frame::query()
            ->where('movie_id', $stale->id)
            ->where('frame_level', $nominal[$framesPerRound - 1]->value)
            ->update($staleWrites[$offset]);

        expect(MovieProjection::query()->findOrFail($stale->id)->levels_count)->toBe($framesPerRound);
    }

    $channel = Mockery::spy(LoggerInterface::class);
    Log::spy()->shouldReceive('channel')->with('game')->andReturn($channel);

    $result = gameDrawer()->draw(PoolScope::catalogue([], $framesPerRound), $minimum, gameDrawPrf());

    // Les œuvres sont comptées par le vivier, sautées par le tirage, et le
    // tirage continue : M + 1 manches, jamais moins de M.
    expect($result->poolSize)->toBe($minimum + 1 + $staleCount)
        ->and($result->rounds)->toHaveCount($minimum + 1)
        ->and(array_intersect(gameDrawSequence($result), $staleIds))->toBe([])
        ->and(array_map(static fn (DrawnRound $round): int => $round->sequenceIndex, $result->rounds))
        ->toBe(range(1, $minimum + 1));

    $realMask = FrameLevelCoverage::maskOf(array_slice($nominal, 0, $framesPerRound - 1));

    // Journal : canal `game`, libellé `draw.work_skipped`, le film, N, le masque
    // projeté et le masque réel — rien d'autre, aucune donnée de joueur. Une
    // ligne par œuvre sautée, et aucune autre.
    foreach ($staleIds as $staleId) {
        $channel->shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'draw.work_skipped'
                && $context === [
                    'movie_id' => $staleId,
                    'frames_per_round' => $framesPerRound,
                    'projected_levels_mask' => FrameLevelCoverage::maskOf($nominal),
                    'levels_mask' => $realMask,
                ])
            ->once();
    }

    $channel->shouldHaveReceived('warning')->times($staleCount);

    // Pur : le masque est recalculé depuis les variantes chargées, jamais lu
    // dans la projection.
    $pure = gameDrawer()->drawFrom(gameDrawInput(
        range(1, $minimum + 1),
        banks: [2 => gameDrawBank(2, array_slice($nominal, 1))],
        projected: [2 => FrameLevelCoverage::maskOf($nominal)],
        margin: 1,
    ), gameDrawPrf());

    expect(gameDrawSequence($pure))->not->toContain(2)
        ->and($pure->rounds)->toHaveCount($minimum);
});

it('draw_pool_size égale le compte du rapport de garde', function () {
    $minimum = RoomSettingsBounds::MIN_ROUNDS_COUNT;
    $room = Room::factory()->create();
    $group = MovieGroup::factory()->create();

    // Six films seuls, un groupe de deux, un film déjà joué par le salon :
    // neuf films publiés, huit au vivier du salon, sept œuvres.
    PoolFixtures::movies(6);
    PoolFixtures::movie(group: $group);
    PoolFixtures::movie(group: $group);
    $played = PoolFixtures::movie();
    $settings = PoolFixtures::settings(roundsCount: $minimum);

    PoolFixtures::round(PoolFixtures::game($room, $settings), $played, $this->now->subHour());

    // Un seul PoolScope, comme dans la transaction de lancement.
    $scope = PoolScope::forRoom($room, $settings, $this->now);

    $report = null;
    $reportQueries = gameDrawQueries(function () use (&$report, $scope, $minimum): void {
        $report = app(PoolReporter::class)->report($scope, $minimum);
    });

    $result = null;
    $drawQueries = gameDrawQueries(function () use (&$result, $scope, $minimum): void {
        $result = gameDrawer()->draw($scope, $minimum, gameDrawPrf());
    });

    assert($report instanceof PoolReport && $result instanceof DrawResult);

    expect($report->blocked())->toBeFalse()
        ->and($result->poolSize)->toBe($report->count)
        ->and($result->poolSize)->toBe(7)
        ->and($result->rounds)->toHaveCount(min($minimum + PlatformLimits::drawSubstituteMargin(), 7));

    // Budget du § 6.2 : un compte pour la garde, deux lectures pour le tirage,
    // aucune écriture.
    expect($reportQueries)->toHaveCount(1)
        ->and($drawQueries)->toHaveCount(2);

    foreach ([...$reportQueries, ...$drawQueries] as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }
});
