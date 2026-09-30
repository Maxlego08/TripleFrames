<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundStatus;
use App\Enums\ScoreScope;
use App\Models\Alias;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\Player;
use App\Settings\RoomSettings;
use App\Support\Scoring\Ranking;
use App\Support\Scoring\Scoreboard;
use App\ValueObjects\Scoring\TierScore;
use Carbon\CarbonImmutable;
use Tests\Support\Scoring\ScoringFixtures;
use Tests\Support\Scoring\ScoringTypes;

/*
|--------------------------------------------------------------------------
| Ce qui devient public, et quand — spec 80 § 7 et § 9, contrat C13 (L80-3)
|--------------------------------------------------------------------------
|
| Les points et le palier d'une manche deviennent publics dès
| `round.status = revealing`, jamais avant (E10-52) : le classement montré à
| un tiers se lit en portée `Publishable`, et reste figé à la dernière manche
| révélée pendant une manche en cours ; seul le siège lui-même voit ses
| points de la manche en cours (portée `Own`, ciblée). Aucun bloc de score ne
| porte d'identifiant interne, de titre ni de nature d'appariement.
|
*/

/**
 * Les lignes du classement, indexées par `publicId`.
 *
 * @param  array{rows: list<array<string, mixed>>}  $leaderboard
 * @return array<string, array<string, mixed>>
 */
function leaderboardVisibilityRows(array $leaderboard): array
{
    return array_column($leaderboard['rows'], null, 'publicId');
}

/**
 * Toutes les clés et toutes les feuilles d'une charge, à toute profondeur.
 *
 * @param  array<array-key, mixed>  $payload
 * @return array{keys: list<string>, strings: list<string>}
 */
function leaderboardVisibilityLeaves(array $payload): array
{
    $keys = [];
    $strings = [];

    array_walk_recursive($payload, static function (mixed $value, int|string $key) use (&$keys, &$strings): void {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_string($value)) {
            $strings[] = $value;
        }
    });

    // array_walk_recursive ne passe pas par les clés des sous-tableaux.
    $walk = static function (array $node) use (&$walk, &$keys): void {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                if (is_string($key)) {
                    $keys[] = $key;
                }

                $walk($value);
            }
        }
    };

    $walk($payload);

    return ['keys' => array_values(array_unique($keys)), 'strings' => array_values(array_unique($strings))];
}

test('la resynchronisation d’un tiers pendant une manche où A est verrouillé laisse le score de A inchangé', function (): void {
    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);
    $c = ScoringFixtures::seat($game);

    // Manche 1, close : A à 2 000 ms reçus (palier 1, 424), B au palier 2.
    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, 1_700 + $grace);
    ScoringFixtures::find($first, $b, 11_700 + $grace);
    ScoringFixtures::played($first, $c);

    // Manche 2 en cours : tout le monde cherche.
    $second = ScoringFixtures::round($game, 2, RoundStatus::Running);
    ScoringFixtures::played($second, $b);
    ScoringFixtures::played($second, $c);

    // Resynchronisation d'un tiers, sans manche en révélation.
    $before = Scoreboard::leaderboard($game);
    $ownBefore = Scoreboard::seatScore($game, $a);

    // A trouve au palier 1 de la manche 2 : 450 points de plus pour lui seul.
    $guess = ScoringFixtures::find($second, $a, $grace);

    expect($guess->points_total)->toBe(450);

    $after = Scoreboard::leaderboard($game);

    // Le classement d'un tiers ne bouge pas d'un octet : ni score, ni bonnes
    // réponses, ni manche jouée, ni temps, ni rang.
    expect($after)->toBe($before)
        ->and(leaderboardVisibilityRows($after)[$a->public_id]['score'])->toBe(424)
        ->and($after['roundNumber'])->toBeNull()
        ->and(array_column($after['rows'], 'roundDelta'))->toBe([0, 0, 0]);

    // Le siège verrouillé, lui, voit ses points tout de suite (portée Own, ciblée).
    expect($ownBefore)->toBe(['ownScore' => 424])
        ->and(Scoreboard::seatScore($game, $a))->toBe(['ownScore' => 424 + 450])
        ->and(Scoreboard::seatScore($game, $b)['ownScore'])->toBe(leaderboardVisibilityRows($after)[$b->public_id]['score']);

    // Les totaux publiables et propres ne diffèrent que de la manche en cours.
    $publishable = collect(Scoreboard::tallies($game, ScoreScope::Publishable))->keyBy('publicId');
    $own = collect(Scoreboard::tallies($game, ScoreScope::Own))->keyBy('publicId');

    expect($own[$a->public_id]->score - $publishable[$a->public_id]->score)->toBe(450)
        ->and($own[$b->public_id]->score)->toBe($publishable[$b->public_id]->score);
});

test('points et palier d’une manche deviennent publics dès revealing, jamais avant', function (): void {
    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);

    $round = ScoringFixtures::round($game, 1, RoundStatus::Running);
    // A au palier 1, t = 1 700 : 300 + 124 ; B au palier 2, t = 1 700 : 200 + 83.
    ScoringFixtures::find($round, $a, 1_700 + $grace);
    ScoringFixtures::find($round, $b, 11_700 + $grace);

    // Pendant la manche : rien de la manche en cours, ni points ni palier.
    $running = leaderboardVisibilityRows(Scoreboard::leaderboard($game));

    foreach ([$a, $b] as $seat) {
        expect($running[$seat->public_id])->toMatchArray([
            'rank' => null,
            'score' => 0,
            'correctAnswers' => 0,
            'roundsPlayed' => 0,
            'totalAnswerTimeMs' => 0,
            'roundDelta' => 0,
        ]);
    }

    expect(fn () => Scoreboard::roundFinders($round))->toThrow(LogicException::class)
        ->and(fn () => Scoreboard::leaderboard($game, $round))->toThrow(LogicException::class);

    // Dès revealing : points, palier et rang de la manche deviennent publics.
    ScoringFixtures::moveTo($round, RoundStatus::Revealing);

    $revealed = Scoreboard::leaderboard($game, $round);
    $rows = leaderboardVisibilityRows($revealed);

    expect($revealed['roundNumber'])->toBe(1)
        ->and($rows[$a->public_id])->toMatchArray(['rank' => 1, 'score' => 424, 'correctAnswers' => 1, 'roundsPlayed' => 1, 'roundDelta' => 424])
        ->and($rows[$b->public_id])->toMatchArray(['rank' => 2, 'score' => 283, 'correctAnswers' => 1, 'roundsPlayed' => 1, 'roundDelta' => 283]);

    expect(Scoreboard::roundFinders($round))->toBe([
        ['publicId' => $a->public_id, 'lockRank' => 1, 'tierIndex' => 1, 'answeredAtMs' => 1_700 + $grace, 'pointsTier' => 300, 'pointsBonus' => 124, 'pointsTotal' => 424],
        ['publicId' => $b->public_id, 'lockRank' => 2, 'tierIndex' => 2, 'answeredAtMs' => 11_700 + $grace, 'pointsTier' => 200, 'pointsBonus' => 83, 'pointsTotal' => 283],
    ]);

    // Une resynchronisation sans manche en révélation voit les mêmes totaux.
    expect(leaderboardVisibilityRows(Scoreboard::leaderboard($game))[$a->public_id]['score'])->toBe(424);

    // Close, la manche reste publique.
    ScoringFixtures::moveTo($round, RoundStatus::Completed);

    expect(leaderboardVisibilityRows(Scoreboard::leaderboard($game, $round))[$b->public_id]['roundDelta'])->toBe(283)
        ->and(Scoreboard::roundFinders($round))->toHaveCount(2);

    // Annulée, elle ne l'est plus jamais : ses lignes restent en base (L1).
    $cancelled = ScoringFixtures::round($game, 2, RoundStatus::Revealing);
    ScoringFixtures::find($cancelled, $a, $grace);
    ScoringFixtures::moveTo($cancelled, RoundStatus::Cancelled);

    expect(leaderboardVisibilityRows(Scoreboard::leaderboard($game))[$a->public_id]['score'])->toBe(424)
        ->and(fn () => Scoreboard::roundFinders($cancelled))->toThrow(LogicException::class)
        ->and(Guess::query()->where('round_id', $cancelled->id)->count())->toBe(1);
});

test('roundFinders refuse une manche running', function (): void {
    $game = ScoringFixtures::game();
    $seat = ScoringFixtures::seat($game);
    $round = ScoringFixtures::round($game, 1, RoundStatus::Running);
    ScoringFixtures::find($round, $seat, $game->tier_grace_ms);

    expect(fn () => Scoreboard::roundFinders($round))
        ->toThrow(LogicException::class, 'la manche est running');

    // Ni une manche en attente, ni une manche annulée.
    $pending = ScoringFixtures::round($game, 2, RoundStatus::Pending);
    $cancelled = ScoringFixtures::round($game, 3, RoundStatus::Cancelled);

    expect(fn () => Scoreboard::roundFinders($pending))->toThrow(LogicException::class)
        ->and(fn () => Scoreboard::roundFinders($cancelled))->toThrow(LogicException::class);

    // Une manche en révélation est publique.
    ScoringFixtures::moveTo($round, RoundStatus::Revealing);

    expect(Scoreboard::roundFinders($round))->toHaveCount(1);
});

test('aucun bloc TierScore, SeatScore, Leaderboard ni RoundFinder ne contient d’identifiant interne, de titre ni de nature d’appariement ; seul Podium.recap porte des titres, après le gel', function (): void {
    // Facile : un clic QCM se note sans plancher. Titres et alias inventés.
    $game = ScoringFixtures::game(RoomSettings::fromInput(['inputDifficulty' => InputDifficulty::Easy->value]));
    $grace = $game->tier_grace_ms;
    $seats = [ScoringFixtures::seat($game), ScoringFixtures::seat($game), ScoringFixtures::seat($game, GamePlayerStatus::Left)];
    $secrets = [];
    $rounds = [];

    foreach ([RoundStatus::Completed, RoundStatus::Revealing, RoundStatus::Running] as $position => $status) {
        $original = "Le Phare des Brumes {$position}";
        $movie = Movie::factory()->create(['title_original' => $original, 'release_year' => 1987 + $position]);
        MovieTitle::factory()->for($movie)->forLocale(Locale::French)->titled("Le Phare des Brumes {$position}")->create();
        MovieTitle::factory()->for($movie)->forLocale(Locale::English)->titled("The Lighthouse of Mists {$position}")->create();
        Alias::factory()->for($movie)->forLocale(Locale::French)->accepting("Phare Brumeux {$position}")->create();

        array_push($secrets, $original, "The Lighthouse of Mists {$position}", "Phare Brumeux {$position}", "le phare des brumes {$position}", "phare brumeux {$position}");

        $rounds[] = ScoringFixtures::round($game, $position + 1, $status, movie: $movie);
    }

    // Trois natures d'appariement et deux sources, dans chaque manche.
    $guesses = [];

    foreach ($rounds as $position => $round) {
        $guesses[] = ScoringFixtures::find($round, $seats[0], 1_000 + $grace, state: [
            'match_kind' => GuessMatchKind::Alias,
            'answer_key_normalized' => "phare brumeux {$position}",
            'submitted_normalized' => "phare brumeux {$position}",
        ]);
        $guesses[] = ScoringFixtures::find($round, $seats[1], 4_000 + $grace, GuessSource::Choice, state: [
            'answer_key_normalized' => "le phare des brumes {$position}",
            'submitted_normalized' => "le phare des brumes {$position}",
        ]);
        $guesses[] = ScoringFixtures::find($round, $seats[2], 12_000 + $grace, state: [
            'match_kind' => GuessMatchKind::Prefix,
            'answer_key_normalized' => "le phare des brumes {$position}",
            'submitted_normalized' => 'le phare',
        ]);
    }

    [$completed, $revealing] = $rounds;

    $blocks = [
        'TierScore' => array_map(
            static fn (Guess $guess): array => TierScore::fromGuess(Guess::query()->findOrFail($guess->id))->toArray(),
            $guesses,
        ),
        'SeatScore' => array_map(static fn (Player $seat): array => Scoreboard::seatScore($game, $seat), $seats),
        'Leaderboard' => [Scoreboard::leaderboard($game), Scoreboard::leaderboard($game, $revealing), Scoreboard::leaderboard($game, $completed)],
        'RoundFinder' => [...Scoreboard::roundFinders($completed), ...Scoreboard::roundFinders($revealing)],
    ];

    // Clés exactes de chaque bloc : C13 § 3, rien de plus.
    $allowed = [
        'TierScore' => ['tierIndex', 'pointsTier', 'pointsBonus', 'pointsTotal'],
        'SeatScore' => ['ownScore'],
        'Leaderboard' => ['scoreless', 'roundNumber', 'rows', 'publicId', 'rank', 'rankShared', 'score', 'correctAnswers', 'roundsPlayed', 'totalAnswerTimeMs', 'roundDelta', 'status', 'firstRoundNumber'],
        'RoundFinder' => ['publicId', 'lockRank', 'tierIndex', 'answeredAtMs', 'pointsTier', 'pointsBonus', 'pointsTotal'],
    ];
    $forbiddenKeys = [
        'id', 'gameId', 'game_id', 'roundId', 'round_id', 'playerId', 'player_id', 'gamePlayerId', 'game_player_id',
        'guessId', 'guess_id', 'movieId', 'movie_id', 'answerKeyId', 'answer_key_id', 'answerKeyNormalized',
        'answer_key_normalized', 'submittedNormalized', 'submitted_normalized', 'matchKind', 'match_kind', 'source',
        'title', 'titles', 'originalTitle', 'alias', 'aliases', 'year', 'drawSeed', 'draw_seed', 'frameLevel', 'frame_level',
        'sequenceIndex', 'lang',
    ];
    $publicIds = array_map(static fn (Player $seat): string => $seat->public_id, $seats);
    $outcomes = array_map(static fn (GamePlayerStatus $status): string => $status->value, GamePlayerStatus::cases());
    $appariements = [
        ...array_map(static fn (GuessMatchKind $kind): string => $kind->value, GuessMatchKind::cases()),
        ...array_map(static fn (GuessSource $source): string => $source->value, GuessSource::cases()),
    ];

    foreach ($blocks as $block => $payloads) {
        $leaves = leaderboardVisibilityLeaves($payloads);

        expect(array_diff($leaves['keys'], $allowed[$block]))->toBe([], $block)
            ->and(array_intersect($leaves['keys'], $forbiddenKeys))->toBe([], $block);

        // Les seules chaînes : les publicId des sièges et leur issue figée.
        expect(array_values(array_diff($leaves['strings'], [...$publicIds, ...$outcomes])))->toBe([], $block);

        $json = (string) json_encode($payloads, JSON_UNESCAPED_UNICODE);

        foreach ([...$secrets, ...array_map(static fn (string $value): string => '"'.$value.'"', $appariements)] as $secret) {
            expect(mb_stripos($json, $secret))->toBeFalse("{$block} contient « {$secret} »");
        }
    }

    // Un siège s'y désigne par son public_id, jamais par player.id.
    expect(array_column($blocks['Leaderboard'][0]['rows'], 'publicId'))->toEqualCanonicalizing($publicIds);

    // Seul Podium.recap porte des titres : dans le miroir client des blocs de
    // score, le paquet de titres n'est référencé que par l'entrée du
    // récapitulatif…
    $declarations = ScoringTypes::declarations();
    $carriers = array_keys(array_filter(
        $declarations,
        static fn (string $declaration, string $name): bool => $name !== 'TitlePacket'
            && preg_match('/\b(?:TitlePacket|RevealMovie|RevealTitle)\b/', $declaration) === 1,
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($carriers)->toBe(['RecapEntry'])
        ->and(preg_match('/\btitles\s*:\s*TitlePacket\s*;/', $declarations['RecapEntry']))->toBe(1)
        ->and($declarations['TitlePacket'])->toMatch('/^type\s+TitlePacket\s*=\s*RevealMovie\s*;$/');

    foreach (['TierScore', 'SeatScore', 'LeaderboardRow', 'Leaderboard', 'RoundFinder'] as $name) {
        foreach (ScoringTypes::objectFields($declarations[$name]) as $fields) {
            expect(array_intersect($fields, $forbiddenKeys))->toBe([], $name);
        }
    }

    // … et sur la charge réelle (L80-5) : pas de podium avant le gel.
    expect(fn () => Scoreboard::podium($game))->toThrow(LogicException::class, 'n’est pas gelée');

    // Le gel clôt d'office la manche révélée et la manche en cours, arrivée à D.
    expect(app(FinalizeGame::class)->handle($game, GameStatus::Completed, CarbonImmutable::now()->addMilliseconds($rounds[2]->duration_ms)))
        ->toBeTrue();

    $podium = Scoreboard::podium($game);

    // Chaque manche close porte son paquet de titres dans le récapitulatif…
    expect(array_column($podium['recap'], 'outcome'))->toBe(['completed', 'completed', 'completed']);

    foreach ($podium['recap'] as $entry) {
        expect(array_keys($entry))->toBe(['roundNumber', 'outcome', 'titles', 'foundCount', 'finders'])
            ->and($entry['titles'])->not->toBeNull();
    }

    // … et nulle part ailleurs : ni dans le classement final, ni dans les
    // faits marquants, ni dans le reste du récapitulatif.
    $titleKeys = ['title', 'titles', 'originalTitle', 'originalTitleLatin', 'originalLanguage', 'text', 'lang', 'year'];

    foreach (['standings', 'highlights'] as $part) {
        expect(array_intersect(leaderboardVisibilityLeaves($podium[$part])['keys'], $titleKeys))->toBe([], $part);
    }

    $untitled = $podium;

    foreach (array_keys($untitled['recap']) as $position) {
        $untitled['recap'][$position]['titles'] = null;
    }

    $untitledJson = (string) json_encode($untitled, JSON_UNESCAPED_UNICODE);
    $podiumJson = (string) json_encode($podium, JSON_UNESCAPED_UNICODE);

    foreach ($secrets as $secret) {
        expect(mb_stripos($untitledJson, $secret))->toBeFalse("le podium, hors titres du récapitulatif, contient « {$secret} »");
    }

    // Jamais un alias ni une nature d'appariement, même dans le récapitulatif.
    foreach ([0, 1, 2] as $position) {
        expect(mb_stripos($podiumJson, "Phare Brumeux {$position}"))->toBeFalse();
    }

    foreach ($appariements as $value) {
        expect(str_contains($podiumJson, ':"'.$value.'"'))->toBeFalse("le podium contient la valeur « {$value} »");
    }
});

// Ajout de la spec 80 § 16 (marqué « ajout » dans la liste des tests).
test('en solo, le classement intermédiaire ne porte aucun rang', function (): void {
    $game = ScoringFixtures::game(solo: true);
    $grace = $game->tier_grace_ms;
    $seat = ScoringFixtures::seat($game);

    expect($game->room_id)->toBeNull()
        ->and($seat->room_id)->toBeNull();

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $seat, 1_700 + $grace);
    $second = ScoringFixtures::round($game, 2, RoundStatus::Revealing);
    ScoringFixtures::find($second, $seat, 11_700 + $grace);

    foreach ([Scoreboard::leaderboard($game), Scoreboard::leaderboard($game, $second)] as $leaderboard) {
        expect($leaderboard['rows'])->toHaveCount(1)
            ->and($leaderboard['rows'][0])->toMatchArray([
                'publicId' => $seat->public_id,
                'rank' => null,
                'rankShared' => false,
                'score' => 424 + 283,
                'correctAnswers' => 2,
                'roundsPlayed' => 2,
            ]);
    }

    // Le rang nul est forcé par la lecture, pas par la chaîne : Ranking ignore
    // le mode de la partie et classerait ce siège premier.
    $standings = Ranking::rank(Scoreboard::tallies($game, ScoreScope::Publishable), $game->frames_per_round, $game->scoring_version);

    expect($standings[0]->rank)->toBe(1);
});
