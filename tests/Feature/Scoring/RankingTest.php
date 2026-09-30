<?php

use App\Enums\GamePlayerStatus;
use App\Enums\RoundStatus;
use App\Models\Player;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Scoring\Ranking;
use App\Support\Scoring\Scoreboard;
use App\Support\Scoring\ScoringRules;
use App\Support\Scoring\UnsupportedScoringVersion;
use App\ValueObjects\Scoring\PlayerTally;
use App\ValueObjects\Scoring\Standing;
use Tests\Support\Scoring\ScoringFixtures;

/*
|--------------------------------------------------------------------------
| Chaîne de départage et rang — spec 80 § 8, contrat C13 § 4.4 (L80-3)
|--------------------------------------------------------------------------
|
| `Ranking::rank()` est une fonction pure : score décroissant, bonnes réponses
| décroissantes, temps cumulé brut croissant, trouvailles aux paliers 1 à
| N − 1 décroissantes, puis place partagée en classement de compétition.
| Jamais la graine. Rang nul pour un siège sans manche jouée, placé après les
| sièges classés.
|
| Les cas en base passent par `Scoreboard::leaderboard()` et des bonnes
| réponses notées par la règle elle-même (`ScoringFixtures::find()`) : les
| points attendus sont recalculés en commentaire depuis 80 § 3.2 et § 4.5.
|
*/

/**
 * Le classement réduit à (siège, rang, place partagée), dans l'ordre rendu.
 *
 * @param  list<Standing>  $standings
 * @return list<array{0: int, 1: int|null, 2: bool}>
 */
function rankingOrder(array $standings): array
{
    return array_map(
        static fn (Standing $standing): array => [$standing->tally->gamePlayerId, $standing->rank, $standing->shared],
        $standings,
    );
}

/**
 * Les lignes d'un classement intermédiaire réduites à (siège, rang, place
 * partagée, score), les sièges nommés par leur étiquette de test.
 *
 * @param  array{rows: list<array<string, mixed>>}  $leaderboard
 * @param  array<string, Player>  $seats
 * @return list<array{0: string, 1: mixed, 2: mixed, 3: mixed}>
 */
function rankingRows(array $leaderboard, array $seats): array
{
    $labels = array_flip(array_map(static fn (Player $seat): string => $seat->public_id, $seats));

    return array_map(
        static fn (array $row): array => [$labels[$row['publicId']], $row['rank'], $row['rankShared'], $row['score']],
        $leaderboard['rows'],
    );
}

test('ordre : score, puis bonnes réponses, puis temps cumulé, puis trouvailles aux paliers 1 à N − 1', function (): void {
    // N = 4 : les trouvailles se comparent aux paliers 1, 2 et 3, le palier 4
    // se déduisant des autres. Chaque paire consécutive n'est départagée que
    // par UN maillon de la chaîne ; les identifiants de siège décroissent le
    // long de l'ordre attendu, pour que le tri stable ne puisse rien décider.
    $framesPerRound = 4;
    $tallies = [
        // Score d'abord : 900 avec une seule bonne réponse passe devant 800 avec trois.
        ScoringFixtures::tally(111, 900, [0, 0, 0, 1], 25_000),
        // Bonnes réponses ensuite, à score égal : 3 devant 2, malgré un temps six fois plus long.
        ScoringFixtures::tally(110, 800, [0, 1, 1, 1], 60_000),
        ScoringFixtures::tally(109, 800, [1, 0, 1, 0], 10_000),
        // Temps cumulé ensuite, croissant, même quand les trouvailles au palier 1
        // disent l’inverse : le temps passe avant les trouvailles.
        ScoringFixtures::tally(108, 700, [0, 1, 1, 0], 20_000),
        ScoringFixtures::tally(107, 700, [2, 0, 0, 0], 30_000),
        // Trouvailles au palier 1 d'abord : 1 devant 0, même avec moins au palier 2.
        ScoringFixtures::tally(106, 600, [1, 0, 0, 1], 30_000),
        ScoringFixtures::tally(105, 600, [0, 2, 0, 0], 30_000),
        // Palier 1 égal : le palier 2 départage.
        ScoringFixtures::tally(104, 500, [1, 1, 0, 0], 40_000),
        ScoringFixtures::tally(103, 500, [1, 0, 1, 0], 40_000),
        // Paliers 1 et 2 égaux : le palier N − 1 = 3 départage encore.
        ScoringFixtures::tally(102, 400, [1, 0, 1, 0], 40_000),
        ScoringFixtures::tally(101, 400, [1, 0, 0, 1], 40_000),
    ];

    $expected = [];

    foreach ($tallies as $position => $tally) {
        $expected[] = [$tally->gamePlayerId, $position + 1, false];
    }

    expect(rankingOrder(Ranking::rank($tallies, $framesPerRound, ScoringRules::VERSION)))->toBe($expected);

    // L'ordre des totaux reçus n'y change rien.
    expect(rankingOrder(Ranking::rank(array_reverse($tallies), $framesPerRound, ScoringRules::VERSION)))->toBe($expected);

    // Le palier N ne départage jamais : deux sièges égaux aux paliers 1 à N − 1
    // le sont aussi au palier N, la somme des trouvailles valant les bonnes
    // réponses. Égaux sur toute la chaîne, ils partagent la place.
    $tied = Ranking::rank([
        ScoringFixtures::tally(2, 300, [1, 0, 1, 1], 30_000),
        ScoringFixtures::tally(1, 300, [1, 0, 1, 1], 30_000),
    ], $framesPerRound, ScoringRules::VERSION);

    expect(rankingOrder($tied))->toBe([[1, 1, true], [2, 1, true]]);

    // La chaîne suivie est celle de la version, dans l'ordre de TIE_BREAK_CHAIN.
    expect(ScoringRules::TIE_BREAK_CHAIN)->toBe(['score_desc', 'correct_answers_desc', 'total_answer_time_ms_asc', 'tier_finds_desc']);
});

test('à score égal, le siège qui a le plus de bonnes réponses passe devant', function (): void {
    // Réglage par défaut : N = 3, trois paliers de 10 s à 300 / 200 / 100,
    // bonus actif, B_max = 50 %. Les instants sont posés en instant CORRIGÉ,
    // la grâce figée sur la partie ajoutée par-dessus.
    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $seats = ['fast' => ScoringFixtures::seat($game), 'steady' => ScoringFixtures::seat($game)];

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);

    // Un seul film, trouvé vite : palier 1, t = 3 333 ms,
    // 300 + intdiv(300 × 50 × 6 667, 100 × 10 000) = 300 + 100 = 400.
    $quick = ScoringFixtures::find($first, $seats['fast'], 3_333 + $grace);
    ScoringFixtures::played($second, $seats['fast']);

    // Deux films, trouvés au bout du palier 2 : t = 9 999 ms,
    // 200 + intdiv(200 × 50 × 1, 100 × 10 000) = 200 + 0, deux fois.
    $late = [
        ScoringFixtures::find($first, $seats['steady'], 19_999 + $grace),
        ScoringFixtures::find($second, $seats['steady'], 19_999 + $grace),
    ];

    expect($quick->points_total)->toBe(400)
        ->and(array_map(static fn ($guess): int => $guess->points_total, $late))->toBe([200, 200]);

    $leaderboard = Scoreboard::leaderboard($game);

    // 400 contre 400 : deux bonnes réponses passent devant une seule, bien
    // qu'un temps cumulé dix fois plus long.
    expect(rankingRows($leaderboard, $seats))->toBe([
        ['steady', 1, false, 400],
        ['fast', 2, false, 400],
    ]);

    expect($leaderboard['scoreless'])->toBeFalse()
        ->and($leaderboard['rows'][0]['correctAnswers'])->toBe(2)
        ->and($leaderboard['rows'][1]['correctAnswers'])->toBe(1)
        ->and($leaderboard['rows'][0]['totalAnswerTimeMs'])->toBeGreaterThan($leaderboard['rows'][1]['totalAnswerTimeMs']);
});

test('place partagée en classement de compétition 1, 2, 2, 4', function (): void {
    $framesPerRound = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;

    // Deux sièges égaux sur toute la chaîne partagent le rang 2, et le suivant
    // saute au rang 4. L'ordre d'affichage à égalité suit game_player.id.
    $standings = Ranking::rank([
        ScoringFixtures::tally(40, 300, [0, 1, 1], 12_000),
        ScoringFixtures::tally(30, 500, [1, 1, 0], 15_000),
        ScoringFixtures::tally(20, 800, [2, 1, 0], 9_000),
        ScoringFixtures::tally(10, 500, [1, 1, 0], 15_000),
    ], $framesPerRound, ScoringRules::VERSION);

    expect(rankingOrder($standings))->toBe([
        [20, 1, false],
        [10, 2, true],
        [30, 2, true],
        [40, 4, false],
    ]);

    // Trois sièges à égalité en tête : 1, 1, 1, puis 4, puis 5.
    $standings = Ranking::rank([
        ScoringFixtures::tally(5, 100, [0, 0, 1], 25_000),
        ScoringFixtures::tally(4, 300, [1, 0, 0], 5_000),
        ScoringFixtures::tally(3, 300, [1, 0, 0], 5_000),
        ScoringFixtures::tally(2, 200, [0, 1, 0], 15_000),
        ScoringFixtures::tally(1, 300, [1, 0, 0], 5_000),
    ], $framesPerRound, ScoringRules::VERSION);

    expect(rankingOrder($standings))->toBe([
        [1, 1, true],
        [3, 1, true],
        [4, 1, true],
        [2, 4, false],
        [5, 5, false],
    ]);

    // Le même rang partagé, lu en base : deux sièges aux réponses identiques.
    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $seats = [
        'a' => ScoringFixtures::seat($game),
        'b' => ScoringFixtures::seat($game),
        'c' => ScoringFixtures::seat($game),
        'd' => ScoringFixtures::seat($game),
    ];
    $round = ScoringFixtures::round($game, 1, RoundStatus::Completed);

    ScoringFixtures::find($round, $seats['a'], 1_000 + $grace);
    ScoringFixtures::find($round, $seats['c'], 4_000 + $grace);
    ScoringFixtures::find($round, $seats['b'], 4_000 + $grace);
    ScoringFixtures::find($round, $seats['d'], 12_000 + $grace);

    expect(array_map(
        static fn (array $row): array => [$row[0], $row[1], $row[2]],
        rankingRows(Scoreboard::leaderboard($game), $seats),
    ))->toBe([
        ['a', 1, false],
        ['b', 2, true],
        ['c', 2, true],
        ['d', 4, false],
    ]);
});

test('aucune graine n’intervient : deux parties aux graines différentes et aux totaux identiques donnent le même classement', function (): void {
    // Deux graines fixes et différentes, au format de game.draw_seed.
    $seeds = [hash('sha256', 'ranking-seed-1'), hash('sha256', 'ranking-seed-2')];
    $boards = [];

    foreach ($seeds as $seed) {
        $game = ScoringFixtures::game(state: ['draw_seed' => $seed]);
        $grace = $game->tier_grace_ms;
        $seats = [];

        foreach (['a', 'b', 'c', 'd', 'e'] as $label) {
            $seats[$label] = ScoringFixtures::seat($game);
        }

        $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
        $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);

        // b et c identiques sur toute la chaîne (même instant dans la même
        // manche) : une place partagée que seule une graine pourrait trancher.
        ScoringFixtures::find($first, $seats['a'], 1_500 + $grace);
        ScoringFixtures::find($first, $seats['b'], 6_000 + $grace);
        ScoringFixtures::find($first, $seats['c'], 6_000 + $grace);
        ScoringFixtures::played($first, $seats['d']);
        ScoringFixtures::played($first, $seats['e']);

        ScoringFixtures::played($second, $seats['a']);
        ScoringFixtures::played($second, $seats['b']);
        ScoringFixtures::played($second, $seats['c']);
        // d et e : même score — 300 à la dernière milliseconde du palier 1,
        // 200 + 100 à la première du palier 2 (80 § 3.3) —, départagés par le temps.
        ScoringFixtures::find($second, $seats['e'], 9_999 + $grace);
        ScoringFixtures::find($second, $seats['d'], 9_999 + $grace + 1);

        expect($game->fresh()?->draw_seed)->toBe($seed);

        $boards[] = rankingRows(Scoreboard::leaderboard($game), $seats);
    }

    expect($boards[0])->toBe($boards[1])
        ->and(array_map(static fn (array $row): array => [$row[0], $row[1], $row[2]], $boards[0]))->toBe([
            ['a', 1, false],
            ['b', 2, true],
            ['c', 2, true],
            ['e', 4, false],
            ['d', 5, false],
        ]);

    // La fonction de départage ne reçoit rien qui porte une graine.
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(Ranking::class, 'rank'))->getParameters(),
    );

    expect($parameters)->toBe(['tallies', 'framesPerRound', 'scoringVersion']);
});

test('partis et expulsés restent classés avec leurs points', function (): void {
    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $seats = [
        'stayed' => ScoringFixtures::seat($game),
        'left' => ScoringFixtures::seat($game, GamePlayerStatus::Left),
        'kicked' => ScoringFixtures::seat($game, GamePlayerStatus::Kicked),
    ];
    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);

    // Palier 1 à t = 0 : 300 + 150 = 450 ; palier 2 à t = 0 : 200 + 100 = 300 ;
    // palier 3 à t = 0 : 100 + 50 = 150 (80 § 4.5).
    ScoringFixtures::find($first, $seats['left'], $grace);
    ScoringFixtures::find($first, $seats['kicked'], 10_000 + $grace);
    ScoringFixtures::played($first, $seats['stayed']);

    // Seul le siège resté joue la seconde manche : il a joué plus de manches,
    // et passe pourtant derrière les deux sièges qui ne sont plus là.
    ScoringFixtures::find($second, $seats['stayed'], 20_000 + $grace);

    $rows = Scoreboard::leaderboard($game)['rows'];
    $labels = array_flip(array_map(static fn (Player $seat): string => $seat->public_id, $seats));

    expect(array_map(static fn (array $row): array => [
        $labels[$row['publicId']],
        $row['rank'],
        $row['score'],
        $row['correctAnswers'],
        $row['roundsPlayed'],
        $row['status'],
    ], $rows))->toBe([
        ['left', 1, 450, 1, 1, 'left'],
        ['kicked', 2, 300, 1, 1, 'kicked'],
        ['stayed', 3, 150, 1, 2, 'playing'],
    ]);
});

test('un siège sans manche jouée n’a pas de rang', function (): void {
    $framesPerRound = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;

    // Fonction pure : un siège sans manche n'a ni rang ni place partagée, même
    // à égalité avec un autre ; il passe après tous les sièges classés, et un
    // siège classé sans bonne réponse garde son rang.
    $standings = Ranking::rank([
        ScoringFixtures::tally(4, 0, [0, 0, 0], 0, roundsPlayed: 0),
        ScoringFixtures::tally(3, 0, [0, 0, 0], 0, roundsPlayed: 2),
        ScoringFixtures::tally(2, 0, [0, 0, 0], 0, roundsPlayed: 0),
        ScoringFixtures::tally(1, 300, [1, 0, 0], 4_000, roundsPlayed: 2),
    ], $framesPerRound, ScoringRules::VERSION);

    expect(rankingOrder($standings))->toBe([
        [1, 1, false],
        [3, 2, false],
        [2, null, false],
        [4, null, false],
    ]);

    // En base : un retardataire dont la manche d'entrée court encore, et un
    // siège parti avant sa première manche. Les sièges du lancement portent
    // first_round_number = 1 (10 § 7.3, naissance au lancement).
    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $seats = [
        'player' => ScoringFixtures::seat($game, firstRoundNumber: 1),
        'late' => ScoringFixtures::seat($game, firstRoundNumber: 2),
        'gone' => ScoringFixtures::seat($game, GamePlayerStatus::Left, firstRoundNumber: 1),
    ];

    // Partie à k = 0 : aucune manche close ni révélée, personne n'a de rang.
    // Palier 1, t = 1 700 ms : 300 + 124 = 424 (80 § 4.5).
    $first = ScoringFixtures::round($game, 1, RoundStatus::Running);
    ScoringFixtures::find($first, $seats['player'], 1_700 + $grace);

    expect(array_column(Scoreboard::leaderboard($game)['rows'], 'rank'))->toBe([null, null, null]);

    ScoringFixtures::moveTo($first, RoundStatus::Completed);
    $second = ScoringFixtures::round($game, 2, RoundStatus::Running);
    ScoringFixtures::find($second, $seats['late'], 2_000 + $grace);
    ScoringFixtures::played($second, $seats['player']);

    $leaderboard = Scoreboard::leaderboard($game);

    expect(rankingRows($leaderboard, $seats))->toBe([
        ['player', 1, false, 424],
        ['late', null, false, 0],
        ['gone', null, false, 0],
    ]);

    // La manche d'entrée suit le siège jusque dans la ligne publiée.
    expect(array_column($leaderboard['rows'], 'firstRoundNumber'))->toBe([1, 2, 1]);
});

test('mode sans score : le classement suit la chaîne à partir des bonnes réponses', function (): void {
    $settings = RoomSettings::fromInput([
        'tierPoints' => array_fill(0, RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND, RoomSettingsBounds::MIN_TIER_POINTS),
    ]);

    expect(ScoringRules::isScoreless($settings))->toBeTrue();

    $game = ScoringFixtures::game($settings);
    $grace = $game->tier_grace_ms;
    $seats = [
        'two_slow' => ScoringFixtures::seat($game),
        'one_fast' => ScoringFixtures::seat($game),
        'two_fast' => ScoringFixtures::seat($game),
        'one_early' => ScoringFixtures::seat($game),
    ];
    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);

    ScoringFixtures::find($first, $seats['two_fast'], 1_000 + $grace);
    ScoringFixtures::find($first, $seats['one_fast'], 1_500 + $grace);
    ScoringFixtures::find($first, $seats['two_slow'], 12_000 + $grace);
    ScoringFixtures::played($first, $seats['one_early']);

    ScoringFixtures::find($second, $seats['two_fast'], 5_000 + $grace);
    ScoringFixtures::find($second, $seats['two_slow'], 25_000 + $grace);
    ScoringFixtures::played($second, $seats['one_fast']);
    // Même instant et même palier que one_fast, dans une autre manche.
    ScoringFixtures::find($second, $seats['one_early'], 1_500 + $grace);

    $leaderboard = Scoreboard::leaderboard($game);

    // Tous les scores valent 0 : bonnes réponses, puis temps cumulé.
    expect($leaderboard['scoreless'])->toBeTrue()
        ->and(rankingRows($leaderboard, $seats))->toBe([
            ['two_fast', 1, false, 0],
            ['two_slow', 2, false, 0],
            ['one_fast', 3, true, 0],
            ['one_early', 3, true, 0],
        ]);

    // one_fast et one_early : même nombre de bonnes réponses, même temps
    // cumulé, même palier 1 — égaux sur toute la chaîne, place partagée.
    expect(array_column($leaderboard['rows'], 'correctAnswers'))->toBe([2, 2, 1, 1]);
});

// Ajouté par le lot (hors intitulés de la spec) : les gardes de la fonction pure.
test('Ranking refuse une version inconnue, un N hors bornes, un siège en double et des trouvailles incohérentes', function (): void {
    $framesPerRound = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;
    $valid = [ScoringFixtures::tally(1, 300, [1, 0, 0], 1_000)];

    expect(fn () => Ranking::rank($valid, $framesPerRound, ScoringRules::VERSION + 1))
        ->toThrow(UnsupportedScoringVersion::class);

    expect(fn () => Ranking::rank($valid, RoomSettingsBounds::MIN_FRAMES_PER_ROUND - 1, ScoringRules::VERSION))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Ranking::rank($valid, RoomSettingsBounds::MAX_FRAMES_PER_ROUND + 1, ScoringRules::VERSION))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => Ranking::rank([...$valid, ...$valid], $framesPerRound, ScoringRules::VERSION))
        ->toThrow(InvalidArgumentException::class);

    // Trouvailles qui ne couvrent pas les paliers 1..N.
    expect(fn () => Ranking::rank([ScoringFixtures::tally(1, 300, [1, 0], 1_000)], $framesPerRound, ScoringRules::VERSION))
        ->toThrow(InvalidArgumentException::class);

    // Trouvailles qui ne somment pas aux bonnes réponses.
    $inconsistent = new PlayerTally(
        gamePlayerId: 1,
        publicId: 'seat00000001',
        status: GamePlayerStatus::Playing,
        firstRoundNumber: null,
        roundsPlayed: 3,
        correctAnswers: 2,
        score: 300,
        totalAnswerTimeMs: 1_000,
        findsByTier: [1 => 1, 2 => 0, 3 => 0],
    );

    expect(fn () => Ranking::rank([$inconsistent], $framesPerRound, ScoringRules::VERSION))
        ->toThrow(InvalidArgumentException::class);

    // Aucun siège : aucun classement.
    expect(Ranking::rank([], $framesPerRound, ScoringRules::VERSION))->toBe([]);
});
