<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Enums\RoundStatus;
use App\Enums\ScoreScope;
use App\Models\GamePlayer;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Support\Scoring\Scoreboard;
use App\ValueObjects\Scoring\PlayerTally;
use App\ValueObjects\Scoring\TierScore;
use App\ValueObjects\Scoring\TierWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\Scoring\ScoringFixtures;
use Tests\Support\Scoring\ScoringTypes;

/*
|--------------------------------------------------------------------------
| Totaux et charges de `Scoreboard` — spec 80 § 7.4, § 8.1 et § 9 (L80-3)
|--------------------------------------------------------------------------
|
| Ajout : aucun intitulé de la spec ne porte sur les totaux eux-mêmes, que
| `RankingTest` et `LeaderboardVisibilityTest` n'éprouvent qu'à travers le
| classement. Ils sont prouvés ici : une seule sous-requête de manches, celle
| de LA partie (`round.game_id = ?`) sous la portée demandée ; temps cumulé
| BRUT ; trouvailles au palier écrit, plancher du QCM compris ; toutes les
| lignes `game_player`, sans plafond. Et le miroir client
| `resources/js/types/scoring.ts`, seule déclaration de ces types (R-27),
| reprend exactement les clés que le serveur compose.
|
*/

/**
 * Les totaux réduits à ce que la chaîne compare, par `publicId`.
 *
 * @param  list<PlayerTally>  $tallies
 * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: array<int, int>}>
 */
function scoreboardTotals(array $tallies): array
{
    $totals = [];

    foreach ($tallies as $tally) {
        $totals[$tally->publicId] = [$tally->roundsPlayed, $tally->correctAnswers, $tally->score, $tally->totalAnswerTimeMs, $tally->findsByTier];
    }

    return $totals;
}

test('tallies ne lit que les manches de la partie, sous la portée demandée, en temps brut et au palier écrit, plancher du QCM compris', function (): void {
    // Normal, N = 3 : les propositions n'apparaissent qu'à T₃.
    $settings = RoomSettings::fromInput(['inputDifficulty' => InputDifficulty::Normal->value]);
    $game = ScoringFixtures::game($settings);
    $grace = $game->tier_grace_ms;
    $p = ScoringFixtures::seat($game);
    $q = ScoringFixtures::seat($game);
    $idle = ScoringFixtures::seat($game, firstRoundNumber: 6);

    // Manche 1, close. Instant corrigé 19 850 : palier 2 en texte libre
    // (200 + 1), plancher du palier 3 pour un clic (100 + 50) — 80 § 4.5.
    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    $click = ScoringFixtures::find($first, $p, 19_850 + $grace, GuessSource::Choice);
    $typed = ScoringFixtures::find($first, $q, 19_850 + $grace);

    expect(TierScore::fromGuess($click)->toArray())->toBe(['tierIndex' => 3, 'pointsTier' => 100, 'pointsBonus' => 50, 'pointsTotal' => 150])
        ->and(TierScore::fromGuess($typed)->toArray())->toBe(['tierIndex' => 2, 'pointsTier' => 200, 'pointsBonus' => 1, 'pointsTotal' => 201]);

    // Manche 2, en révélation : P au palier 1, t = 0 (450).
    $second = ScoringFixtures::round($game, 2, RoundStatus::Revealing);
    ScoringFixtures::find($second, $p, $grace);
    ScoringFixtures::played($second, $q);

    // Manche 3, en cours : Q au palier 1, t = 0 (450).
    $third = ScoringFixtures::round($game, 3, RoundStatus::Running);
    ScoringFixtures::find($third, $q, $grace);
    ScoringFixtures::played($third, $p);

    // Manche 4, annulée après un verrouillage : hors de toute portée (L1).
    $cancelled = ScoringFixtures::round($game, 4, RoundStatus::Revealing);
    ScoringFixtures::find($cancelled, $p, $grace);
    ScoringFixtures::moveTo($cancelled, RoundStatus::Cancelled);

    // Manche de réserve, en attente.
    ScoringFixtures::round($game, 5, RoundStatus::Pending);

    // La partie suivante du même salon (« Rejouer ») : mêmes sièges, autres
    // manches, qui ne comptent jamais dans la première.
    $replay = ScoringFixtures::game($settings, ['room_id' => $game->room_id]);
    GamePlayer::factory()->for($replay)->frozenFrom($p)->create();
    $elsewhere = ScoringFixtures::round($replay, 1, RoundStatus::Completed);
    ScoringFixtures::find($elsewhere, $p, $grace);

    $none = [1 => 0, 2 => 0, 3 => 0];

    // Temps cumulé BRUT : l'instant reçu, jamais l'instant corrigé de la grâce.
    expect(scoreboardTotals(Scoreboard::tallies($game, ScoreScope::Settled)))->toBe([
        $p->public_id => [1, 1, 150, 19_850 + $grace, [1 => 0, 2 => 0, 3 => 1]],
        $q->public_id => [1, 1, 201, 19_850 + $grace, [1 => 0, 2 => 1, 3 => 0]],
        $idle->public_id => [0, 0, 0, 0, $none],
    ]);

    expect(scoreboardTotals(Scoreboard::tallies($game, ScoreScope::Publishable)))->toBe([
        $p->public_id => [2, 2, 600, 19_850 + 2 * $grace, [1 => 1, 2 => 0, 3 => 1]],
        $q->public_id => [2, 1, 201, 19_850 + $grace, [1 => 0, 2 => 1, 3 => 0]],
        $idle->public_id => [0, 0, 0, 0, $none],
    ]);

    expect(scoreboardTotals(Scoreboard::tallies($game, ScoreScope::Own)))->toBe([
        $p->public_id => [3, 2, 600, 19_850 + 2 * $grace, [1 => 1, 2 => 0, 3 => 1]],
        $q->public_id => [3, 2, 651, 19_850 + 2 * $grace, [1 => 1, 2 => 1, 3 => 0]],
        $idle->public_id => [0, 0, 0, 0, $none],
    ]);

    // Identité, issue et manche d'entrée recopiées de game_player.
    $idleTally = Scoreboard::tallies($game, ScoreScope::Own)[2];

    expect($idleTally->status)->toBe(GamePlayerStatus::Playing)
        ->and($idleTally->firstRoundNumber)->toBe(6)
        ->and($idleTally->gamePlayerId)->toBe(GamePlayer::query()->where('player_id', $idle->id)->where('game_id', $game->id)->value('id'));

    // Le score du siège : portée Own, lui seul, sa partie seule.
    expect(Scoreboard::seatScore($game, $p))->toBe(['ownScore' => 600])
        ->and(Scoreboard::seatScore($game, $q))->toBe(['ownScore' => 651])
        ->and(Scoreboard::seatScore($game, $idle))->toBe(['ownScore' => 0])
        ->and(Scoreboard::seatScore($replay, $p))->toBe(['ownScore' => 450])
        ->and(scoreboardTotals(Scoreboard::tallies($replay, ScoreScope::Settled)))->toBe([
            $p->public_id => [1, 1, 450, $grace, [1 => 1, 2 => 0, 3 => 0]],
        ]);
});

test('le classement porte toutes les lignes game_player, sans plafond de sièges', function (): void {
    $game = ScoringFixtures::game();
    $round = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    $seats = PlatformLimits::roomSeats() + 2;

    foreach (range(1, $seats) as $position) {
        $seat = ScoringFixtures::seat($game, $position % 3 === 0 ? GamePlayerStatus::Left : GamePlayerStatus::Playing);
        ScoringFixtures::played($round, $seat);
    }

    $rows = Scoreboard::leaderboard($game)['rows'];

    // Personne n'a trouvé : tous à égalité, un seul rang partagé.
    expect($rows)->toHaveCount($seats)
        ->and(array_unique(array_column($rows, 'rank')))->toBe([1])
        ->and(array_unique(array_column($rows, 'rankShared')))->toBe([true]);
});

test('roundDelta ne compte que la manche révélée, et roundFinders suit lockRank, jamais l’instant de réception', function (): void {
    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);

    // Manche 1, close : A au palier 1, t = 0 (450).
    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, $grace);
    ScoringFixtures::played($first, $b);

    // Manche 2, en révélation : le verrou de A est acquis avant celui de B,
    // reçu pourtant une milliseconde plus tôt (80 § 8.3). Palier 2, t = 0 et
    // t = 1 : 200 + 100 et 200 + 99.
    $second = ScoringFixtures::round($game, 2, RoundStatus::Revealing);
    ScoringFixtures::find($second, $a, 10_000 + $grace + 1);
    ScoringFixtures::find($second, $b, 10_000 + $grace);

    expect(array_map(
        static fn (array $finder): array => [$finder['publicId'], $finder['lockRank'], $finder['answeredAtMs'], $finder['pointsTotal']],
        Scoreboard::roundFinders($second),
    ))->toBe([
        [$a->public_id, 1, 10_000 + $grace + 1, 299],
        [$b->public_id, 2, 10_000 + $grace, 300],
    ]);

    // Le delta d'un siège ne porte que sur la manche révélée ; le score, sur
    // toutes les manches publiables.
    $rows = collect(Scoreboard::leaderboard($game, $second)['rows'])->keyBy('publicId');

    expect($rows[$a->public_id])->toMatchArray(['score' => 450 + 299, 'roundDelta' => 299])
        ->and($rows[$b->public_id])->toMatchArray(['score' => 300, 'roundDelta' => 300]);

    // Rappelé avec la manche 1, close, le delta suit la manche passée.
    $rows = collect(Scoreboard::leaderboard($game, $first)['rows'])->keyBy('publicId');

    expect($rows[$a->public_id]['roundDelta'])->toBe(450)
        ->and($rows[$b->public_id]['roundDelta'])->toBe(0);
});

test('leaderboard refuse une manche non publiable ou d’une autre partie', function (): void {
    $game = ScoringFixtures::game();
    $other = ScoringFixtures::game();
    ScoringFixtures::seat($game);

    foreach ([RoundStatus::Pending, RoundStatus::Running, RoundStatus::Cancelled] as $position => $status) {
        $round = ScoringFixtures::round($game, $position + 1, $status);

        expect(fn () => Scoreboard::leaderboard($game, $round))->toThrow(LogicException::class, $status->value);
    }

    $foreign = ScoringFixtures::round($other, 1, RoundStatus::Revealing);

    expect(fn () => Scoreboard::leaderboard($game, $foreign))->toThrow(LogicException::class)
        ->and(Scoreboard::leaderboard($other, $foreign)['roundNumber'])->toBe(1);
});

// Ajouté à la porte de L80-3 : les lectures d'un appel partagent un instantané.
test('tallies et leaderboard lisent tout sous une seule transaction, donc un seul instantané', function (): void {
    $game = ScoringFixtures::game();
    $seat = ScoringFixtures::seat($game);
    $round = ScoringFixtures::round($game, 1, RoundStatus::Revealing);
    ScoringFixtures::find($round, $seat, $game->tier_grace_ms);

    $base = DB::transactionLevel();
    $levels = [];

    DB::listen(static function (QueryExecuted $query) use (&$levels): void {
        $levels[] = $query->connection->transactionLevel();
    });

    // Deltas, round_player, guess, game_player et player : cinq lectures,
    // toutes un niveau de transaction au-dessus de l'appelant.
    Scoreboard::leaderboard($game, $round);

    expect($levels)->toHaveCount(5)
        ->and(array_values(array_unique($levels)))->toBe([$base + 1]);

    // Les totaux seuls : quatre lectures, sous la même garantie.
    $levels = [];
    Scoreboard::tallies($game, ScoreScope::Settled);

    expect($levels)->toHaveCount(4)
        ->and(array_values(array_unique($levels)))->toBe([$base + 1]);
});

test('types/scoring.ts déclare une seule fois chaque type du contrat et reprend les clés des charges de Scoreboard', function (): void {
    $source = ScoringTypes::source();
    $declarations = ScoringTypes::declarations($source);

    // Les onze types de C13 § 2.4, exportés, et rien d'autre d'exporté.
    $contract = ['TierWindow', 'TierScore', 'SeatScore', 'LeaderboardRow', 'Leaderboard', 'RoundFinder', 'TitlePacket', 'RecapEntry', 'PodiumStanding', 'PodiumHighlights', 'Podium'];
    preg_match_all('/export\s+(?:interface|type)\s+([A-Za-z_]\w*)/', $source, $exported);

    expect($exported[1])->toEqualCanonicalizing($contract);

    // Types importés, jamais redéclarés : RevealMovie et IsoMs de C7, PlayerIdentity de C5.
    preg_match_all('/import\s+type\s*\{([^}]*)\}\s*from\s*\'([^\']+)\'/', $source, $imports, PREG_SET_ORDER);
    $imported = [];

    foreach ($imports as [, $names, $module]) {
        $imported[$module] = array_map(trim(...), explode(',', trim($names)));
    }

    expect($imported)->toEqualCanonicalizing([
        '@/types/game-wire' => ['IsoMs', 'RevealMovie'],
        '@/types/player' => ['PlayerIdentity'],
    ])
        ->and($declarations['PodiumStanding'])->toMatch('/^interface\s+PodiumStanding\s+extends\s+PlayerIdentity\s*\{/');

    // Seule déclaration de ces types dans resources/js (R-27).
    $elsewhere = [];
    $pattern = '/(?:^|\n)\s*(?:export\s+)?(?:interface|type)\s+('.implode('|', $contract).')\b/';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = str_replace('\\', '/', (string) $file);

        if (! preg_match('/\.tsx?$/', $path) || str_ends_with($path, 'types/scoring.ts')) {
            continue;
        }

        if (preg_match($pattern, (string) file_get_contents($path), $match) === 1) {
            $elsewhere[] = "{$path} : {$match[1]}";
        }
    }

    expect($elsewhere)->toBe([]);

    // Les clés, dans l'ordre où le serveur les compose.
    $game = ScoringFixtures::game();
    $seat = ScoringFixtures::seat($game);
    $round = ScoringFixtures::round($game, 1, RoundStatus::Revealing);
    $guess = ScoringFixtures::find($round, $seat, $game->tier_grace_ms);
    $leaderboard = Scoreboard::leaderboard($game, $round);
    $fields = static fn (string $name): array => ScoringTypes::objectFields($declarations[$name]);

    expect($fields('TierWindow'))->toBe([array_keys((new TierWindow(1, 0, 1, 0))->toArray())])
        ->and($fields('TierScore'))->toBe([array_keys(TierScore::fromGuess($guess)->toArray())])
        ->and($fields('SeatScore'))->toBe([array_keys(Scoreboard::seatScore($game, $seat))])
        ->and($fields('Leaderboard'))->toBe([array_keys($leaderboard)])
        ->and($fields('LeaderboardRow'))->toBe([array_keys($leaderboard['rows'][0])])
        ->and($fields('RoundFinder'))->toBe([array_keys(Scoreboard::roundFinders($round)[0])]);

    // Le podium (L80-5) : clés de C13 § 3, dans l'ordre de son producteur, sur
    // une partie gelée qui a une manche close et une annulation non remplacée.
    $frozen = ScoringFixtures::game();
    $finder = ScoringFixtures::seat($frozen);
    ScoringFixtures::find(ScoringFixtures::round($frozen, 1, RoundStatus::Completed), $finder, $frozen->tier_grace_ms);
    ScoringFixtures::round($frozen, 2, RoundStatus::Cancelled);
    app(FinalizeGame::class)->handle($frozen, GameStatus::Completed, CarbonImmutable::now());
    $podium = Scoreboard::podium($frozen);
    $identity = ['publicId', 'nickname', 'masked', 'avatar'];

    expect($fields('Podium'))->toBe([['gameStatus', 'mode', 'roundsCompleted', 'roundsCount', 'framesPerRound', 'scoreless', 'endedAt', 'standings', 'recap', 'highlights']])
        ->and($fields('Podium'))->toBe([array_keys($podium)])
        ->and($fields('PodiumStanding'))->toBe([['status', 'firstRoundNumber', 'rank', 'rankShared', 'finalScore', 'correctAnswers', 'roundsPlayed', 'totalAnswerTimeMs']])
        ->and(array_slice(array_keys($podium['standings'][0]), 0, count($identity)))->toBe($identity)
        ->and($fields('PodiumStanding'))->toBe([array_slice(array_keys($podium['standings'][0]), count($identity))])
        ->and($fields('RecapEntry'))->toBe([
            ['roundNumber', 'outcome', 'titles', 'foundCount', 'finders'],
            ['roundNumber', 'outcome', 'titles', 'foundCount', 'finders'],
        ])
        ->and(array_column($podium['recap'], 'outcome'))->toBe(['completed', 'cancelled'])
        ->and($fields('RecapEntry'))->toBe(array_map(array_keys(...), $podium['recap']))
        ->and($fields('PodiumHighlights'))->toBe([['bestAnswer', 'fastestFind', 'unfoundRoundNumbers']])
        ->and($fields('PodiumHighlights'))->toBe([array_keys($podium['highlights'])]);

    // L'issue d'un siège : exactement les cas de GamePlayerStatus.
    preg_match_all("/'([^']*)'/", $declarations['SeatOutcome'], $outcomes);

    expect($outcomes[1])->toEqualCanonicalizing(array_map(static fn (GamePlayerStatus $status): string => $status->value, GamePlayerStatus::cases()));
});
