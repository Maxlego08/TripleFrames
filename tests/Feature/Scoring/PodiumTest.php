<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\GameMode;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundStatus;
use App\Enums\ScoreScope;
use App\Events\Game\GameEnded;
use App\Models\Alias;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Settings\EngineConstants;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Game\RevealMovieBuilder;
use App\Support\Identity\PlayerIdentity;
use App\Support\Realtime\WirePayload;
use App\Support\Realtime\WireTime;
use App\Support\Scoring\Scoreboard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\Scoring\ScoringFixtures;

/*
|--------------------------------------------------------------------------
| Podium, récapitulatif et faits marquants — spec 80 § 11, contrat C13 (L80-5)
|--------------------------------------------------------------------------
|
| `Scoreboard::podium()` ne se lit qu'après le gel. Le classement final vient
| UNIQUEMENT des agrégats figés de `game_player` ; le récapitulatif et les
| faits marquants (D25 du 23/09) se dérivent des `guess` et des `round` en
| portée `Settled`. Une entrée de récapitulatif par numéro de manche —
| jamais une manche `pending`, jamais le titre d'une annulation —, un paquet
| de titres par locale activée (le `RevealMovie` de la révélation, R-24), et
| aucun identifiant interne.
|
| Les points attendus sont recalculés en commentaire depuis 80 § 3.2 et
| § 4.5 (N = 3, D = 30 s en trois paliers de 10 s, B_max = 50 %), sur
| l'instant corrigé de la grâce. Au barème par défaut 300 / 200 / 100 :
| 0 → 450 ; 1 700 → 424 ; 11 700 → 283 ; 21 700 → 141.
|
*/

/**
 * Gèle la partie par l'action de gel, seule écrivaine des agrégats.
 */
function podiumTestFreeze(Game $game, GameStatus $outcome = GameStatus::Completed, ?CarbonImmutable $endedAt = null): Game
{
    expect(app(FinalizeGame::class)->handle($game, $outcome, $endedAt ?? CarbonImmutable::now()))->toBeTrue();

    return $game;
}

/**
 * Un film aux titres inventés, dans les locales demandées.
 *
 * @param  array<string, string>  $titles  Valeur de {@see Locale} → titre.
 * @param  array<string, mixed>  $state  Colonnes de `movie` imposées.
 */
function podiumTestMovie(array $titles, array $state = []): Movie
{
    $movie = Movie::factory()->create($state);

    foreach ($titles as $locale => $title) {
        MovieTitle::factory()->for($movie)->forLocale($locale)->titled($title)->create();
    }

    return $movie;
}

/**
 * Une manche de réserve, jamais numérotée tant qu'elle ne remplace rien.
 */
function podiumTestReserve(Game $game, int $sequenceIndex, ?Movie $movie = null): Round
{
    $factory = Round::factory()->forGame($game)->state(['sequence_index' => $sequenceIndex, 'round_number' => null]);

    return ($movie instanceof Movie ? $factory->forMovie($movie) : $factory)->create();
}

/**
 * Le classement final réduit à ses agrégats, dans l'ordre de la charge :
 * `[publicId, rank, rankShared, finalScore, correctAnswers, roundsPlayed, totalAnswerTimeMs]`.
 *
 * @param  list<array<string, mixed>>  $standings
 * @return list<list<mixed>>
 */
function podiumTestAggregates(array $standings): array
{
    return array_map(static fn (array $row): array => [
        $row['publicId'],
        $row['rank'],
        $row['rankShared'],
        $row['finalScore'],
        $row['correctAnswers'],
        $row['roundsPlayed'],
        $row['totalAnswerTimeMs'],
    ], $standings);
}

/**
 * L'identité gelée d'un siège, par son seul constructeur (C5).
 *
 * @return array<string, mixed>
 */
function podiumTestIdentity(Game $game, Player $seat): array
{
    $participation = GamePlayer::query()
        ->where('game_id', $game->id)
        ->where('player_id', $seat->id)
        ->with('player:'.implode(',', PlayerIdentity::FROZEN_SEAT_COLUMNS))
        ->firstOrFail();

    return PlayerIdentity::fromGamePlayer($participation)->toArray();
}

/**
 * Toutes les clés d'une charge, à toute profondeur.
 *
 * @param  array<array-key, mixed>  $payload
 * @return list<string>
 */
function podiumTestKeys(array $payload): array
{
    $keys = [];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($value)) {
            array_push($keys, ...podiumTestKeys($value));
        }
    }

    return array_values(array_unique($keys));
}

test('le podium ne lit que les agrégats figés', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC'));

    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game, firstRoundNumber: 1);
    $b = ScoringFixtures::seat($game, firstRoundNumber: 1);
    $c = ScoringFixtures::seat($game, GamePlayerStatus::Kicked, 1);
    $late = ScoringFixtures::seat($game, firstRoundNumber: 3);

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, 1_700 + $grace);   // 424
    ScoringFixtures::find($first, $b, 11_700 + $grace);  // 283
    ScoringFixtures::played($first, $c);

    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);
    ScoringFixtures::find($second, $b, 1_700 + $grace);  // 424
    ScoringFixtures::played($second, $a);
    ScoringFixtures::played($second, $c);

    // Tant que la partie n'est pas gelée, il n'y a pas de podium.
    expect(fn () => Scoreboard::podium($game))->toThrow(LogicException::class, 'n’est pas gelée');

    podiumTestFreeze($game);
    $podium = Scoreboard::podium($game);

    // B : 283 + 424 ; A : 424 ; C, expulsé, reste classé ; le retardataire
    // n'a joué aucune manche : sans rang, en dernier.
    expect(podiumTestAggregates($podium['standings']))->toBe([
        [$b->public_id, 1, false, 707, 2, 2, 13_400 + 2 * $grace],
        [$a->public_id, 2, false, 424, 1, 2, 1_700 + $grace],
        [$c->public_id, 3, false, 0, 0, 2, 0],
        [$late->public_id, null, false, 0, 0, 0, 0],
    ])
        ->and(array_column($podium['standings'], 'status'))->toBe(['playing', 'playing', 'kicked', 'playing'])
        ->and(array_column($podium['standings'], 'firstRoundNumber'))->toBe([1, 1, 1, 3]);

    // Pseudo et avatar : l'identité gelée de C5, et rien d'autre.
    foreach ([$b, $a, $c, $late] as $position => $seat) {
        expect(array_intersect_key($podium['standings'][$position], array_flip(['publicId', 'nickname', 'masked', 'avatar'])))
            ->toBe(podiumTestIdentity($game, $seat));
    }

    // Après le gel, le journal change sous le podium — points réécrits, une
    // manche de plus trouvée par le retardataire : le classement final n'en
    // voit rien, alors que les totaux relus en direct, eux, ont bougé.
    DB::table('guess')->whereIn('round_id', [$first->id, $second->id])->update(['points_total' => 1_500]);
    $third = ScoringFixtures::round($game, 3, RoundStatus::Completed);
    ScoringFixtures::find($third, $late, $grace);

    $live = collect(Scoreboard::tallies($game, ScoreScope::Settled))->keyBy('publicId');

    expect(Scoreboard::podium($game)['standings'])->toBe($podium['standings'])
        ->and($live[$late->public_id]->roundsPlayed)->toBe(1)
        ->and($live[$b->public_id]->score)->toBe(3_000);

    // Les agrégats réécrits en base sont repris tels quels, rang partagé
    // compris : aucun recalcul, aucune chaîne de départage rejouée.
    $overwrite = static fn (Player $seat, array $columns) => DB::table('game_player')
        ->where('game_id', $game->id)
        ->where('player_id', $seat->id)
        ->update($columns);

    $overwrite($a, ['final_rank' => 1, 'final_score' => 999, 'correct_answers' => 2]);
    $overwrite($b, ['final_rank' => 1, 'final_score' => 999, 'correct_answers' => 2, 'total_answer_time_ms' => 1_700 + $grace]);

    $sql = [];
    DB::listen(static function (QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    $rewritten = Scoreboard::podium($game);

    // A et B à égalité sur le rang figé : place partagée, ordre par siège.
    expect(podiumTestAggregates($rewritten['standings']))->toBe([
        [$a->public_id, 1, true, 999, 2, 2, 1_700 + $grace],
        [$b->public_id, 1, true, 999, 2, 2, 1_700 + $grace],
        [$c->public_id, 3, false, 0, 0, 2, 0],
        [$late->public_id, null, false, 0, 0, 0, 0],
    ]);

    // Aucune agrégation SQL : le podium ne recompte rien.
    expect(array_filter($sql, static fn (string $query): bool => preg_match('/\b(?:sum|count|avg|min|max)\s*\(/i', $query) === 1))->toBe([]);

    // Une partie close sans gel — `ended_at` posé, agrégats nuls — n'a pas de
    // podium : il publierait des zéros jamais calculés.
    $unfrozen = Game::factory()->completed()->create();
    GamePlayer::factory()->for($unfrozen)->create();

    expect(fn () => Scoreboard::podium($unfrozen))->toThrow(LogicException::class, 'agrégats figés');

    // Ni une partie qui porte ended_at sans statut terminal.
    $running = ScoringFixtures::game();
    $running->ended_at = CarbonImmutable::now();

    expect(fn () => Scoreboard::podium($running))->toThrow(LogicException::class, 'statut est running');
});

test('meilleure réponse : points puis vitesse ; en mode sans score, la plus rapide', function (): void {
    // Barème par défaut à rebours, admis et averti (80 § 2.4) : 100 / 200 / 300.
    // La réponse la plus rapide n'y est plus la mieux payée.
    $settings = RoomSettings::fromInput([
        'tierPoints' => array_reverse(RoomSettingsBounds::defaultTierPoints(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND)),
    ]);
    $game = ScoringFixtures::game($settings);
    $grace = $game->tier_grace_ms;

    expect($game->settings_snapshot->tierPoints)->toBe([100, 200, 300]);

    [$a, $b, $c, $d, $e, $f, $g] = array_map(static fn (): Player => ScoringFixtures::seat($game), range(1, 7));

    // Manche 1 : A au palier 1 à t = 0 (100 + 50), la plus rapide de toutes ;
    // B au palier 3 à t = 1 700 (300 + 124).
    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    $fastest = ScoringFixtures::find($first, $a, $grace);
    $slow = ScoringFixtures::find($first, $b, 21_700 + $grace);

    // Manche 2 : C au palier 3 à t = 1 667 — mêmes 424 points que B, plus
    // vite —, puis E au même instant, arrivé second.
    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);
    $best = ScoringFixtures::find($second, $c, 21_667 + $grace);
    $sameInstant = ScoringFixtures::find($second, $e, 21_667 + $grace);

    // Manche 3 : D, mêmes points, même instant que C, manche plus tardive.
    $third = ScoringFixtures::round($game, 3, RoundStatus::Completed);
    $laterRound = ScoringFixtures::find($third, $d, 21_667 + $grace);

    // Manche 4, annulée (L1) : 450 points à t = 0 du palier 3, et une réponse
    // reçue avant toute autre. Ni l'une ni l'autre ne compte.
    $cancelled = ScoringFixtures::round($game, 4, RoundStatus::Cancelled);
    $voidBest = ScoringFixtures::find($cancelled, $f, 20_000 + $grace);
    $voidFastest = ScoringFixtures::find($cancelled, $g, 0);

    expect([$fastest->points_total, $slow->points_total, $best->points_total, $sameInstant->points_total, $laterRound->points_total])
        ->toBe([150, 424, 424, 424, 424])
        ->and([$voidBest->points_total, $voidFastest->answered_at_ms])->toBe([450, 0]);

    podiumTestFreeze($game);
    $highlights = Scoreboard::podium($game)['highlights'];

    // Les points d'abord, puis l'instant de réception, puis la manche, puis
    // le rang d'arrivée : C devant B (plus rapide), D (manche 3) et E (arrivé
    // après lui). La réponse la plus rapide, A, n'est pas la meilleure.
    expect($highlights['bestAnswer'])->toBe([
        'publicId' => $c->public_id,
        'roundNumber' => 2,
        'tierIndex' => 3,
        'answeredAtMs' => 21_667 + $grace,
        'pointsTotal' => 424,
    ])
        ->and($highlights['fastestFind'])->toBe([
            'publicId' => $a->public_id,
            'roundNumber' => 1,
            'answeredAtMs' => $grace,
        ]);

    // Mode sans score : tous les paliers à 0, chaque réponse vaut 0, et la
    // meilleure réponse est mécaniquement la plus rapide (D25 du 23/09).
    $scoreless = ScoringFixtures::game(RoomSettings::fromInput([
        'tierPoints' => array_fill(0, RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND, RoomSettingsBounds::MIN_TIER_POINTS),
    ]));
    [$x, $y, $z] = array_map(static fn (): Player => ScoringFixtures::seat($scoreless), range(1, 3));

    $one = ScoringFixtures::round($scoreless, 1, RoundStatus::Completed);
    ScoringFixtures::find($one, $x, 5_000 + $grace);
    ScoringFixtures::find($one, $y, 3_000 + $grace);
    $two = ScoringFixtures::round($scoreless, 2, RoundStatus::Completed);
    ScoringFixtures::find($two, $z, 3_000 + $grace);
    ScoringFixtures::find($two, $x, 12_000 + $grace);

    podiumTestFreeze($scoreless);
    $podium = Scoreboard::podium($scoreless);

    // Y et Z au même instant : la manche 1 passe devant.
    expect($podium['scoreless'])->toBeTrue()
        ->and($podium['highlights']['bestAnswer'])->toBe([
            'publicId' => $y->public_id,
            'roundNumber' => 1,
            'tierIndex' => 1,
            'answeredAtMs' => 3_000 + $grace,
            'pointsTotal' => 0,
        ])
        ->and($podium['highlights']['fastestFind'])->toBe([
            'publicId' => $y->public_id,
            'roundNumber' => 1,
            'answeredAtMs' => 3_000 + $grace,
        ]);
});

test('film que personne n’a eu : manches completed à found_count = 0', function (): void {
    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);

    // 1 : trouvée. 2 : personne. 3 : annulée sans trouvaille, non remplacée.
    $found = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($found, $a, 1_700 + $grace);
    ScoringFixtures::played($found, $b);

    $nobody = ScoringFixtures::round($game, 2, RoundStatus::Completed);
    ScoringFixtures::played($nobody, $a);
    ScoringFixtures::played($nobody, $b);

    $cancelledEmpty = ScoringFixtures::round($game, 3, RoundStatus::Cancelled);

    // 4 : annulée après une trouvaille (found_count jamais remis à zéro),
    // remplacée par une manche de réserve que personne ne trouve.
    $cancelledFound = ScoringFixtures::round($game, 4, RoundStatus::Cancelled);
    ScoringFixtures::find($cancelledFound, $b, 1_700 + $grace);
    $replacement = ScoringFixtures::round($game, 4, RoundStatus::Completed, sequenceIndex: RoomSettingsBounds::DEFAULT_ROUNDS_COUNT + 1);
    ScoringFixtures::played($replacement, $a);

    // 5 : personne. 6 : programmée, jamais jouée. Une réserve jamais entrée.
    $nobodyAgain = ScoringFixtures::round($game, 5, RoundStatus::Completed);
    ScoringFixtures::round($game, 6, RoundStatus::Pending);
    podiumTestReserve($game, RoomSettingsBounds::DEFAULT_ROUNDS_COUNT + 2);

    expect([$cancelledEmpty->refresh()->found_count, $cancelledFound->refresh()->found_count])->toBe([0, 1])
        ->and([$nobody->found_count, $replacement->found_count, $nobodyAgain->found_count])->toBe([0, 0, 0]);

    podiumTestFreeze($game, GameStatus::Interrupted);
    $podium = Scoreboard::podium($game);

    expect($podium['highlights']['unfoundRoundNumbers'])->toBe([2, 4, 5]);

    // Chacune est une manche close du récapitulatif, sans trouvaille.
    $recap = array_column($podium['recap'], null, 'roundNumber');

    foreach ([2, 4, 5] as $number) {
        expect($recap[$number])->toMatchArray(['outcome' => 'completed', 'foundCount' => 0, 'finders' => []])
            ->and($recap[$number]['titles'])->not->toBeNull();
    }

    expect($recap[3]['outcome'])->toBe('cancelled');

    // En solo, « voir la réponse » et « passer la manche » ne trouvent jamais :
    // leurs manches sont des films que personne n'a eus.
    $solo = ScoringFixtures::game(solo: true);
    $seat = ScoringFixtures::seat($solo);

    $soloFound = ScoringFixtures::round($solo, 1, RoundStatus::Completed);
    ScoringFixtures::find($soloFound, $seat, 1_700 + $grace);
    $revealed = ScoringFixtures::round($solo, 2, RoundStatus::Completed);
    RoundPlayer::factory()->forRound($revealed, $seat)->revealed()->create();
    $skipped = ScoringFixtures::round($solo, 3, RoundStatus::Completed);
    RoundPlayer::factory()->forRound($skipped, $seat)->skipped()->create();

    podiumTestFreeze($solo);
    $soloPodium = Scoreboard::podium($solo);

    expect($soloPodium['highlights']['unfoundRoundNumbers'])->toBe([2, 3])
        ->and($soloPodium['mode'])->toBe(GameMode::Solo->value)
        ->and(podiumTestAggregates($soloPodium['standings']))->toBe([[$seat->public_id, null, false, 424, 1, 3, 1_700 + $grace]]);
});

test('le récapitulatif ne montre jamais une manche pending et montre une annulation non remplacée sans titre', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC'));

    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);

    $movies = [
        'shown' => podiumTestMovie(['fr' => 'Le Phare des Brumes', 'en' => 'The Lighthouse of Mists'], ['title_original' => 'The Lighthouse of Mists']),
        'cancelled' => podiumTestMovie(['fr' => 'La Forge du Givre', 'en' => 'The Frost Forge'], ['title_original' => 'Frostschmiede', 'original_language' => 'de']),
        'scheduled' => podiumTestMovie(['fr' => 'Le Jardin des Échos', 'en' => 'The Garden of Echoes'], ['title_original' => 'Il giardino degli echi', 'original_language' => 'it']),
        'reserve' => podiumTestMovie(['fr' => 'Le Col des Ombres', 'en' => 'The Pass of Shadows'], ['title_original' => 'El paso de las sombras', 'original_language' => 'es']),
    ];

    $shown = ScoringFixtures::round($game, 1, RoundStatus::Completed, movie: $movies['shown']);
    ScoringFixtures::find($shown, $a, 1_700 + $grace);   // 424
    ScoringFixtures::played($shown, $b);

    // Annulée après une trouvaille de 450 : la ligne guess reste (L1), rien ne compte.
    $cancelled = ScoringFixtures::round($game, 2, RoundStatus::Cancelled, movie: $movies['cancelled']);
    ScoringFixtures::find($cancelled, $b, $grace);

    // La manche 3, programmée, et une réserve : jamais jouées.
    $scheduled = ScoringFixtures::round($game, 3, RoundStatus::Pending, movie: $movies['scheduled']);
    $scheduled->started_at = CarbonImmutable::now()->addSeconds(5);
    $scheduled->save();
    podiumTestReserve($game, RoomSettingsBounds::DEFAULT_ROUNDS_COUNT + 1, $movies['reserve']);

    podiumTestFreeze($game, GameStatus::Interrupted);
    $podium = Scoreboard::podium($game);

    expect($podium['roundsCompleted'])->toBe(1)
        ->and(array_column($podium['recap'], 'roundNumber'))->toBe([1, 2])
        ->and($podium['recap'][0])->toMatchArray([
            'roundNumber' => 1,
            'outcome' => 'completed',
            'titles' => RevealMovieBuilder::build($movies['shown']),
            'foundCount' => 1,
        ])
        // L'annulation non remplacée : ni titre, ni trouvaille, ni compte.
        ->and($podium['recap'][1])->toBe([
            'roundNumber' => 2,
            'outcome' => 'cancelled',
            'titles' => null,
            'foundCount' => 0,
            'finders' => [],
        ]);

    // La trace de l'incident reste en base, hors de tout ce que montre le podium.
    expect($cancelled->refresh()->found_count)->toBe(1)
        ->and(Guess::query()->where('round_id', $cancelled->id)->count())->toBe(1)
        ->and($podium['highlights']['bestAnswer']['publicId'] ?? null)->toBe($a->public_id)
        ->and($podium['highlights']['bestAnswer']['pointsTotal'] ?? null)->toBe(424)
        ->and($podium['highlights']['fastestFind']['roundNumber'] ?? null)->toBe(1);

    // Aucun titre d'une manche jamais révélée, dans aucune locale, ni l'original.
    $json = (string) json_encode($podium, JSON_UNESCAPED_UNICODE);

    foreach (['cancelled', 'scheduled', 'reserve'] as $name) {
        $movie = $movies[$name]->load('titles');

        foreach ([$movie->title_original, ...$movie->titles->pluck('title')->all()] as $title) {
            expect(mb_stripos($json, (string) $title))->toBeFalse("le podium montre « {$title} »");
        }
    }
});

test('le récapitulatif porte un paquet de titres par locale activée et aucun identifiant interne', function (): void {
    // Facile : un clic QCM se note sans plancher.
    $game = ScoringFixtures::game(RoomSettings::fromInput(['inputDifficulty' => InputDifficulty::Easy->value]));
    $room = Room::query()->findOrFail($game->room_id);
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game, GamePlayerStatus::Left);

    // Traduit dans chaque locale, original non latin translittéré.
    $translated = podiumTestMovie(
        ['fr' => 'Le Phare des Brumes', 'en' => 'The Lighthouse of Mists'],
        ['title_original' => '霧の灯台', 'title_original_latin' => 'Kiri no Toudai', 'original_language' => 'ja', 'release_year' => 2001],
    );
    Alias::factory()->for($translated)->forLocale(Locale::French)->accepting('Phare Brumeux')->create();
    // Traduit en français seulement : l'anglais se replie sur le français.
    $half = podiumTestMovie(['fr' => 'La Forge du Givre'], ['title_original' => 'Frostschmiede', 'original_language' => 'de', 'release_year' => 1987]);
    // Sans aucun titre de catalogue : l'original partout.
    $bare = podiumTestMovie([], ['title_original' => 'The Garden of Echoes', 'original_language' => 'en', 'release_year' => 1994]);

    $rounds = [
        ScoringFixtures::round($game, 1, RoundStatus::Completed, movie: $translated),
        ScoringFixtures::round($game, 2, RoundStatus::Completed, movie: $half),
        ScoringFixtures::round($game, 3, RoundStatus::Completed, movie: $bare),
    ];

    ScoringFixtures::find($rounds[0], $a, 1_700 + $grace, state: [
        'match_kind' => GuessMatchKind::Alias,
        'answer_key_normalized' => 'phare brumeux',
        'submitted_normalized' => 'phare brumeux',
    ]);
    ScoringFixtures::find($rounds[0], $b, 4_000 + $grace, GuessSource::Choice);
    ScoringFixtures::find($rounds[1], $a, 12_000 + $grace, state: [
        'match_kind' => GuessMatchKind::Prefix,
        'answer_key_normalized' => 'la forge du givre',
        'submitted_normalized' => 'la forge',
    ]);
    ScoringFixtures::played($rounds[1], $b);

    podiumTestFreeze($game);
    $podium = Scoreboard::podium($game);
    $locales = array_map(static fn (Locale $locale): string => $locale->value, Locale::cases());

    // Un paquet par manche close : celui de la révélation, par son seul
    // constructeur, un titre par locale activée.
    foreach ([$translated, $half, $bare] as $position => $movie) {
        $titles = $podium['recap'][$position]['titles'];

        expect($titles)->toBe(RevealMovieBuilder::build($movie))
            ->and(array_keys($titles['titles'] ?? []))->toBe($locales);
    }

    expect($podium['recap'][0]['titles'])->toBe([
        'titles' => [
            'en' => ['text' => 'The Lighthouse of Mists', 'lang' => 'en'],
            'fr' => ['text' => 'Le Phare des Brumes', 'lang' => 'fr'],
        ],
        'originalTitle' => '霧の灯台',
        'originalTitleLatin' => 'Kiri no Toudai',
        'originalLanguage' => 'ja',
        'year' => 2001,
        'letterboxdUrl' => "https://letterboxd.com/tmdb/{$translated->tmdb_id}/",
    ])
        // Le repli garde la langue du titre servi, jamais celle du joueur.
        ->and($podium['recap'][1]['titles']['titles'] ?? null)->toBe([
            'en' => ['text' => 'La Forge du Givre', 'lang' => 'fr'],
            'fr' => ['text' => 'La Forge du Givre', 'lang' => 'fr'],
        ])
        ->and($podium['recap'][2]['titles']['titles'] ?? null)->toBe([
            'en' => ['text' => 'The Garden of Echoes', 'lang' => 'en'],
            'fr' => ['text' => 'The Garden of Echoes', 'lang' => 'en'],
        ]);

    // Aucun identifiant interne, à toute profondeur : la garde du fil passe,
    // et `game.ended` accepte la charge telle quelle.
    WirePayload::assertSafe($podium, 'podium');
    $event = new GameEnded($room, $game, ['podium' => $podium]);

    expect($event->broadcastWith())->toHaveKey('podium');

    $allowed = [
        'gameStatus', 'mode', 'roundsCompleted', 'roundsCount', 'framesPerRound', 'scoreless', 'endedAt',
        'standings', 'publicId', 'nickname', 'masked', 'avatar', 'kind', 'url', 'altKey', 'initials', 'status',
        'firstRoundNumber', 'rank', 'rankShared', 'finalScore', 'correctAnswers', 'roundsPlayed', 'totalAnswerTimeMs',
        'recap', 'roundNumber', 'outcome', 'titles', ...$locales, 'text', 'lang', 'originalTitle', 'originalTitleLatin',
        'originalLanguage', 'year', 'letterboxdUrl', 'foundCount', 'finders', 'lockRank', 'tierIndex', 'answeredAtMs', 'pointsTier',
        'pointsBonus', 'pointsTotal', 'highlights', 'bestAnswer', 'fastestFind', 'unfoundRoundNumbers',
    ];

    expect(array_values(array_diff(podiumTestKeys($podium), $allowed)))->toBe([]);

    // Ni alias, ni forme normalisée, ni nature d'appariement, ni source, ni
    // graine du tirage, ni URL d'image de jeu.
    $json = (string) json_encode($podium, JSON_UNESCAPED_UNICODE);
    $game->refresh();

    foreach (['Phare Brumeux', 'phare brumeux', 'la forge"', $game->draw_seed, '/f/'] as $secret) {
        expect(mb_stripos($json, $secret))->toBeFalse("le podium contient « {$secret} »");
    }

    foreach ([...GuessMatchKind::cases(), ...GuessSource::cases()] as $case) {
        expect(str_contains($json, ':"'.$case->value.'"'))->toBeFalse("le podium contient la valeur « {$case->value} »");
    }

    // Seules les entrées closes du récapitulatif portent des titres.
    $titleKeys = ['title', 'titles', 'originalTitle', 'originalTitleLatin', 'text', 'lang', 'year'];

    expect(array_intersect(podiumTestKeys($podium['standings']), $titleKeys))->toBe([])
        ->and(array_intersect(podiumTestKeys($podium['highlights']), $titleKeys))->toBe([]);
});

test('le podium d’une partie interrompue porte k et M', function (): void {
    // Exemple de 80 § 11.8 : N = 3, M = 10, interrompue après deux manches closes.
    $this->travelTo(CarbonImmutable::parse('2026-09-23 20:16:04.000', 'UTC'));

    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;

    $alice = Player::factory()->withNickname('Alice')->create(['room_id' => $game->room_id]);
    GamePlayer::factory()->for($game)->frozenFrom($alice, 1)->create();
    $bob = Player::factory()->left()->withNickname('Bob')->create(['room_id' => $game->room_id, 'avatar_kind' => null, 'avatar_preset' => null]);
    GamePlayer::factory()->for($game)->frozenFrom($bob, 1)->create(['status' => GamePlayerStatus::Left]);

    $movie = podiumTestMovie(
        ['fr' => 'Le Phare des Brumes', 'en' => 'The Lighthouse of Mists'],
        ['title_original' => '霧の灯台', 'title_original_latin' => 'Kiri no Toudai', 'original_language' => 'ja', 'release_year' => 2001],
    );

    // Manche 1 : A reçue à 2 000 ms (palier 1, 300 + 124), B à 12 000 ms
    // (instant corrigé 11 700, palier 2, 200 + 83). Manche 2 : personne.
    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed, movie: $movie);
    ScoringFixtures::find($first, $alice, 1_700 + $grace);
    ScoringFixtures::find($first, $bob, 11_700 + $grace);
    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);
    ScoringFixtures::played($second, $alice);
    ScoringFixtures::played($second, $bob);

    // La suite du tirage, jamais jouée.
    foreach (range(3, RoomSettingsBounds::DEFAULT_ROUNDS_COUNT) as $number) {
        ScoringFixtures::round($game, $number, RoundStatus::Pending);
    }

    podiumTestReserve($game, RoomSettingsBounds::DEFAULT_ROUNDS_COUNT + 1);

    // Plus personne : pause, puis clôture à l'échéance prévue (60 § 14.2).
    $pausedAt = CarbonImmutable::now();
    $game->forceFill(['status' => GameStatus::Paused, 'paused_at' => $pausedAt, 'rounds_completed' => 2])->save();
    $endedAt = $pausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs());

    podiumTestFreeze($game, GameStatus::Interrupted, $endedAt);
    $podium = Scoreboard::podium($game);

    expect($podium)->toBe([
        'gameStatus' => 'interrupted',
        'mode' => 'multiplayer',
        'roundsCompleted' => 2,
        'roundsCount' => RoomSettingsBounds::DEFAULT_ROUNDS_COUNT,
        'framesPerRound' => RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
        'scoreless' => false,
        'endedAt' => WireTime::iso($endedAt),
        'standings' => [
            [...podiumTestIdentity($game, $alice), 'status' => 'playing', 'firstRoundNumber' => 1, 'rank' => 1, 'rankShared' => false,
                'finalScore' => 424, 'correctAnswers' => 1, 'roundsPlayed' => 2, 'totalAnswerTimeMs' => 1_700 + $grace],
            [...podiumTestIdentity($game, $bob), 'status' => 'left', 'firstRoundNumber' => 1, 'rank' => 2, 'rankShared' => false,
                'finalScore' => 283, 'correctAnswers' => 1, 'roundsPlayed' => 2, 'totalAnswerTimeMs' => 11_700 + $grace],
        ],
        'recap' => [
            [
                'roundNumber' => 1,
                'outcome' => 'completed',
                'titles' => [
                    'titles' => [
                        'en' => ['text' => 'The Lighthouse of Mists', 'lang' => 'en'],
                        'fr' => ['text' => 'Le Phare des Brumes', 'lang' => 'fr'],
                    ],
                    'originalTitle' => '霧の灯台',
                    'originalTitleLatin' => 'Kiri no Toudai',
                    'originalLanguage' => 'ja',
                    'year' => 2001,
                    'letterboxdUrl' => "https://letterboxd.com/tmdb/{$movie->tmdb_id}/",
                ],
                'foundCount' => 2,
                'finders' => [
                    ['publicId' => $alice->public_id, 'lockRank' => 1, 'tierIndex' => 1, 'answeredAtMs' => 1_700 + $grace, 'pointsTier' => 300, 'pointsBonus' => 124, 'pointsTotal' => 424],
                    ['publicId' => $bob->public_id, 'lockRank' => 2, 'tierIndex' => 2, 'answeredAtMs' => 11_700 + $grace, 'pointsTier' => 200, 'pointsBonus' => 83, 'pointsTotal' => 283],
                ],
            ],
            [
                'roundNumber' => 2,
                'outcome' => 'completed',
                'titles' => RevealMovieBuilder::build(Movie::query()->findOrFail($second->movie_id)),
                'foundCount' => 0,
                'finders' => [],
            ],
        ],
        'highlights' => [
            'bestAnswer' => ['publicId' => $alice->public_id, 'roundNumber' => 1, 'tierIndex' => 1, 'answeredAtMs' => 1_700 + $grace, 'pointsTotal' => 424],
            'fastestFind' => ['publicId' => $alice->public_id, 'roundNumber' => 1, 'answeredAtMs' => 1_700 + $grace],
            'unfoundRoundNumbers' => [2],
        ],
    ])
        ->and($podium['endedAt'])->toMatch(WireTime::PATTERN)
        ->and($podium['standings'][1]['avatar']['kind'])->toBeNull()
        ->and($podium['standings'][0]['nickname'])->toBe('Alice');

    // Interrompue avant toute manche close : k = 0 sur M, aucun rang, aucun
    // récapitulatif, aucun fait marquant.
    $empty = ScoringFixtures::game();
    $seats = [ScoringFixtures::seat($empty, firstRoundNumber: 1), ScoringFixtures::seat($empty, firstRoundNumber: 1)];
    ScoringFixtures::round($empty, 1, RoundStatus::Pending);

    podiumTestFreeze($empty, GameStatus::Interrupted, FinalizeGame::lastKnownActivity($empty, CarbonImmutable::now()));
    $emptyPodium = Scoreboard::podium($empty);

    expect($emptyPodium)->toMatchArray([
        'gameStatus' => 'interrupted',
        'roundsCompleted' => 0,
        'roundsCount' => $empty->rounds_count,
        'recap' => [],
        'highlights' => ['bestAnswer' => null, 'fastestFind' => null, 'unfoundRoundNumbers' => []],
    ])
        ->and(podiumTestAggregates($emptyPodium['standings']))->toBe([
            [$seats[0]->public_id, null, false, 0, 0, 0, 0],
            [$seats[1]->public_id, null, false, 0, 0, 0, 0],
        ]);
});

// Ajout de la spec 80 § 16 (marqué « ajout » dans la liste des tests).
test('une manche annulée puis remplacée n\'apparaît qu\'une fois, sous son remplaçant', function (): void {
    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);

    $cancelledMovie = podiumTestMovie(['fr' => 'La Forge du Givre', 'en' => 'The Frost Forge'], ['title_original' => 'Frostschmiede', 'original_language' => 'de']);
    $replacementMovie = podiumTestMovie(['fr' => 'Le Col des Ombres', 'en' => 'The Pass of Shadows'], ['title_original' => 'El paso de las sombras', 'original_language' => 'es']);
    $reserve = RoomSettingsBounds::DEFAULT_ROUNDS_COUNT;

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, 1_700 + $grace);

    // Manche 2 : annulée après la trouvaille de B, remplacée par la première
    // réserve, qui reprend son numéro et que A trouve.
    $cancelled = ScoringFixtures::round($game, 2, RoundStatus::Cancelled, movie: $cancelledMovie);
    ScoringFixtures::find($cancelled, $b, $grace);
    $replacement = ScoringFixtures::round($game, 2, RoundStatus::Completed, sequenceIndex: $reserve + 1, movie: $replacementMovie);
    ScoringFixtures::find($replacement, $a, 11_700 + $grace);   // 283

    // Manche 3 : annulée, remplacée, et le remplaçant annulé à son tour, sans
    // réserve restante — une seule annulation non remplacée.
    ScoringFixtures::round($game, 3, RoundStatus::Cancelled);
    ScoringFixtures::round($game, 3, RoundStatus::Cancelled, sequenceIndex: $reserve + 2);

    $fourth = ScoringFixtures::round($game, 4, RoundStatus::Completed);
    ScoringFixtures::played($fourth, $a);

    podiumTestFreeze($game);
    $podium = Scoreboard::podium($game);
    $recap = $podium['recap'];

    expect(array_column($recap, 'roundNumber'))->toBe([1, 2, 3, 4])
        ->and(array_column($recap, 'outcome'))->toBe(['completed', 'completed', 'cancelled', 'completed'])
        ->and($recap[1]['titles'])->toBe(RevealMovieBuilder::build($replacementMovie))
        ->and($recap[1]['foundCount'])->toBe(1)
        ->and($recap[1]['finders'])->toBe(Scoreboard::roundFinders($replacement))
        ->and(array_column($recap[1]['finders'], 'publicId'))->toBe([$a->public_id])
        ->and($podium['roundsCompleted'])->toBe(3);

    // Le film annulé n'apparaît nulle part.
    $json = (string) json_encode($podium, JSON_UNESCAPED_UNICODE);

    foreach (['La Forge du Givre', 'The Frost Forge', 'Frostschmiede'] as $title) {
        expect(mb_stripos($json, $title))->toBeFalse("le podium montre « {$title} »");
    }
});

// Ajout (hors intitulés de la spec) : comme leaderboard(), le podium lit tout
// sous un seul instantané (E82-6).
test('le podium lit tout sous une seule transaction, donc un seul instantané', function (): void {
    $game = ScoringFixtures::game();
    $seat = ScoringFixtures::seat($game);
    $round = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($round, $seat, $game->tier_grace_ms);
    ScoringFixtures::round($game, 2, RoundStatus::Cancelled);
    podiumTestFreeze($game);

    $base = DB::transactionLevel();
    $levels = [];

    DB::listen(static function (QueryExecuted $query) use (&$levels): void {
        $levels[] = $query->connection->transactionLevel();
    });

    Scoreboard::podium($game);

    expect($levels)->not->toBeEmpty()
        ->and(array_values(array_unique($levels)))->toBe([$base + 1]);
});
