<?php

use App\Console\Commands\LoadTestForgetCommand;
use App\Enums\Locale;
use App\Models\Frame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Models\SeenFrame;
use App\Models\TmdbCompany;
use App\Models\WrongAnswer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| `loadtest:forget` — spec 100 § 16.5, lot L100-12
|--------------------------------------------------------------------------
|
| Le nettoyage des données synthétiques du test de charge : un salon listé
| par le scénario n'est oublié que si CHACUN de ses sièges porte un pseudo
| synthétique (`k6-` suivi d'un numéro) ; ses faits partent dans l'ordre
| imposé par les `restrictOnDelete` (10 § 11.3), puis ses sièges et le salon,
| dont la cascade emporte `seen_frame`. Aucune table du catalogue n'est
| touchée (10 § 11.2).
|
| SQLite applique les clés étrangères (`foreign_key_constraints`) : un ordre
| de suppression fautif échouerait ici comme en production.
|
*/

beforeEach(function (): void {
    $this->codesFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tripleframes-loadtest-'.bin2hex(random_bytes(6)).'.txt';
});

afterEach(function (): void {
    File::delete($this->codesFile);
});

/** Un pseudo synthétique numéroté, tel que le scénario k6 l'écrit. */
function loadTestNickname(int $number): string
{
    return LoadTestForgetCommand::SYNTHETIC_NICKNAME_PREFIX.$number;
}

/**
 * Un salon et une partie terminée, avec tous ses faits : un siège par pseudo,
 * sa participation figée, deux manches révélées, leurs paliers, leurs
 * participations, un QCM composé, une bonne réponse, et la mémoire d'images
 * du salon.
 *
 * @param  list<string>  $nicknames
 */
function loadTestRoom(array $nicknames, bool $gameInProgress = false): Room
{
    $room = Room::factory()->playing()->create();
    $seats = array_map(
        static fn (string $nickname): Player => Player::factory()->for($room)->withNickname($nickname)->create(),
        $nicknames,
    );

    $game = $gameInProgress
        ? Game::factory()->forRoom($room)->create()
        : Game::factory()->forRoom($room)->completed()->create();

    foreach ($seats as $seat) {
        GamePlayer::factory()->for($game)->frozenFrom($seat)->create();
    }

    foreach ([1, 2] as $sequence) {
        $round = Round::factory()->forGame($game)->atSequence($sequence)->completed()->create();

        foreach ([1, 2, 3] as $tierIndex) {
            RoundTier::factory()->atTier($tierIndex)->create(['round_id' => $round->id]);
        }

        foreach ($seats as $seat) {
            RoundPlayer::factory()->forRound($round, $seat)->create();
        }

        RoundChoiceSet::factory()->forRound($round)->create();
        Guess::factory()->forRound($round, $seats[0])->create();
        WrongAnswer::factory()->forRound($round, $seats[1])->create();
    }

    SeenFrame::factory()->forRoom($room)->forFrame(Frame::factory()->create())->create();

    return $room;
}

/** Le fichier de codes que le scénario écrit : un code par ligne. */
function loadTestCodesFile(string $path, Room ...$rooms): string
{
    File::put($path, "# Test de charge — salons créés\n\n".implode("\n", array_map(
        static fn (Room $room): string => $room->room_code,
        $rooms,
    ))."\n");

    return $path;
}

/**
 * Les lignes encore présentes, par table, pour le salon `$room` (identifiant
 * relevé AVANT l'appel : le salon a pu disparaître).
 *
 * @return array<string, int>
 */
function loadTestFacts(int $roomId): array
{
    $gameIds = Game::query()->where('room_id', $roomId)->pluck('id');
    $roundIds = Round::query()->whereIn('game_id', $gameIds)->pluck('id');

    return [
        'guess' => Guess::query()->whereIn('round_id', $roundIds)->count(),
        'wrong_answer' => WrongAnswer::query()->whereIn('round_id', $roundIds)->count(),
        'round_choice_set' => RoundChoiceSet::query()->whereIn('round_id', $roundIds)->count(),
        'round_tier' => RoundTier::query()->whereIn('round_id', $roundIds)->count(),
        'round_player' => RoundPlayer::query()->whereIn('round_id', $roundIds)->count(),
        'round' => Round::query()->whereIn('game_id', $gameIds)->count(),
        'game_player' => GamePlayer::query()->whereIn('game_id', $gameIds)->count(),
        'game' => $gameIds->count(),
        'player' => Player::query()->where('room_id', $roomId)->count(),
        'seen_frame' => SeenFrame::query()->where('room_id', $roomId)->count(),
        'room' => Room::query()->whereKey($roomId)->count(),
    ];
}

/** Aucune ligne restante, table par table. */
function loadTestNoFacts(): array
{
    return array_fill_keys(
        ['guess', 'wrong_answer', 'round_choice_set', 'round_tier', 'round_player', 'round', 'game_player', 'game', 'player', 'seen_frame', 'room'],
        0,
    );
}

/**
 * Les tables du catalogue, liste de 10 § 11.2 (familles « Catalogue » et
 * « Images »).
 *
 * @return list<string>
 */
function loadTestCatalogueTables(): array
{
    return [
        'movie', 'movie_projection', 'movie_group', 'collection', 'movie_title', 'alias', 'answer_key',
        'movie_certification', 'movie_tmdb_tag', 'tmdb_company', 'theme', 'theme_label', 'movie_theme', 'import_run',
        'frame', 'frame_review',
    ];
}

/**
 * Les écritures SQL jouées pendant `$run`, dans l'ordre : `[verbe, table]`.
 *
 * @return list<array{0: string, 1: string}>
 */
function loadTestWrites(Closure $run): array
{
    $writes = [];

    DB::listen(static function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(delete\s+from|update|insert\s+into)\s+["`]?([a-z_]+)["`]?/i', $query->sql, $match) === 1) {
            $writes[] = [strtolower((string) preg_replace('/\s+/', ' ', $match[1])), $match[2]];
        }
    });

    $run();

    return $writes;
}

function loadTestText(string $key, array $replace = []): string
{
    $text = trans($key, $replace, Locale::French->value);

    expect($text)->toBeString()->not->toBe($key);

    return (string) $text;
}

it('n’efface que les salons listés dont chaque siège porte le préfixe synthétique', function (): void {
    $synthetic = loadTestRoom([loadTestNickname(1), loadTestNickname(2), loadTestNickname(3)]);
    $mixed = loadTestRoom([loadTestNickname(4), 'Zoé']);
    $lookalike = loadTestRoom([loadTestNickname(5), LoadTestForgetCommand::SYNTHETIC_NICKNAME_PREFIX.'bob']);
    $unlisted = loadTestRoom([loadTestNickname(6), loadTestNickname(7)]);

    $before = [
        $mixed->id => loadTestFacts($mixed->id),
        $lookalike->id => loadTestFacts($lookalike->id),
        $unlisted->id => loadTestFacts($unlisted->id),
    ];

    $this->artisan('loadtest:forget', ['file' => loadTestCodesFile($this->codesFile, $synthetic, $mixed, $lookalike)])
        ->expectsOutputToContain(loadTestText('admin.console.loadtest.deleted', ['rooms' => 1]))
        ->expectsOutputToContain(loadTestText('admin.console.loadtest.skipped_real_seat', ['count' => 2]))
        ->assertExitCode(0)
        ->run();

    expect(loadTestFacts($synthetic->id))->toBe(loadTestNoFacts());

    foreach ($before as $roomId => $facts) {
        expect(loadTestFacts($roomId))->toBe($facts);
    }

    // Le pseudo seul ne suffit pas : un préfixe suivi d'autre chose qu'un
    // numéro n'est pas synthétique, pas plus qu'un pseudo effacé.
    expect(LoadTestForgetCommand::isSyntheticNickname(loadTestNickname(12)))->toBeTrue()
        ->and(LoadTestForgetCommand::isSyntheticNickname(LoadTestForgetCommand::SYNTHETIC_NICKNAME_PREFIX))->toBeFalse()
        ->and(LoadTestForgetCommand::isSyntheticNickname(loadTestNickname(12).'a'))->toBeFalse()
        ->and(LoadTestForgetCommand::isSyntheticNickname(loadTestNickname(12)."\n"))->toBeFalse()
        ->and(LoadTestForgetCommand::isSyntheticNickname('x'.loadTestNickname(12)))->toBeFalse()
        ->and(LoadTestForgetCommand::isSyntheticNickname(null))->toBeFalse();
});

it('supprime les faits de ces salons dans l’ordre imposé par les restrict', function (): void {
    $room = loadTestRoom([loadTestNickname(1), loadTestNickname(2)]);
    $roomId = $room->id;

    expect(loadTestFacts($roomId))->each->toBeGreaterThan(0);

    $writes = loadTestWrites(function () use ($room): void {
        $this->artisan('loadtest:forget', ['file' => loadTestCodesFile($this->codesFile, $room)])
            ->assertExitCode(0)
            ->run();
    });

    // Feuilles d'abord (10 § 11.3), puis les sièges et le salon ; la
    // mémoire d'images part par la cascade de `seen_frame.room_id`, sans
    // instruction propre.
    expect(array_map(static fn (array $write): string => $write[1], $writes))->toBe([
        'guess', 'wrong_answer', 'round_choice_set', 'round_tier', 'round_player', 'round', 'game_player', 'game', 'player', 'room',
    ])->and(array_unique(array_map(static fn (array $write): string => $write[0], $writes)))->toBe(['delete from'])
        ->and(loadTestFacts($roomId))->toBe(loadTestNoFacts());
});

it('ne touche aucune table du catalogue', function (): void {
    $room = loadTestRoom([loadTestNickname(1), loadTestNickname(2)]);
    // `tmdb_company` est au périmètre interdit (10 § 11.2, D43 du 01/10) :
    // une ligne au moins, pour que son compte prouve quelque chose.
    TmdbCompany::factory()->create();
    $count = static fn (): array => array_combine(
        loadTestCatalogueTables(),
        array_map(static fn (string $table): int => DB::table($table)->count(), loadTestCatalogueTables()),
    );
    $catalogue = $count();

    expect($catalogue['movie'])->toBeGreaterThan(0)
        ->and($catalogue['frame'])->toBeGreaterThan(0)
        ->and($catalogue['tmdb_company'])->toBeGreaterThan(0);

    $writes = loadTestWrites(function () use ($room): void {
        $this->artisan('loadtest:forget', ['file' => loadTestCodesFile($this->codesFile, $room)])
            ->assertExitCode(0)
            ->run();
    });

    expect(Room::query()->whereKey($room->id)->exists())->toBeFalse()
        ->and(array_intersect(array_map(static fn (array $write): string => $write[1], $writes), loadTestCatalogueTables()))->toBe([])
        ->and($count())->toBe($catalogue);
});

it('ne fait rien sous --dry-run', function (): void {
    $synthetic = loadTestRoom([loadTestNickname(1), loadTestNickname(2)]);
    $mixed = loadTestRoom([loadTestNickname(3), 'Zoé']);
    $before = [$synthetic->id => loadTestFacts($synthetic->id), $mixed->id => loadTestFacts($mixed->id)];

    $writes = loadTestWrites(function () use ($synthetic, $mixed): void {
        $this->artisan('loadtest:forget', ['file' => loadTestCodesFile($this->codesFile, $synthetic, $mixed), '--dry-run' => true])
            ->expectsOutputToContain(loadTestText('admin.console.loadtest.dry_run', ['rooms' => 1]))
            ->expectsOutputToContain(loadTestText('admin.console.loadtest.skipped_real_seat', ['count' => 1]))
            ->doesntExpectOutputToContain(loadTestText('admin.console.loadtest.deleted', ['rooms' => 1]))
            ->assertExitCode(0)
            ->run();
    });

    expect($writes)->toBe([]);

    foreach ($before as $roomId => $facts) {
        expect(loadTestFacts($roomId))->toBe($facts);
    }
});

// Ajoutés à la livraison, hors des intitulés du lot.

it('écarte un salon dont une partie est encore en cours, et un code sans salon actif', function (): void {
    $running = loadTestRoom([loadTestNickname(1), loadTestNickname(2)], gameInProgress: true);
    $archived = Room::factory()->archived()->create();
    $before = loadTestFacts($running->id);

    // Le code, saisi comme un lien : en minuscules et coupé d'un tiret.
    File::put($this->codesFile, implode("\n", [
        strtolower(substr($running->room_code, 0, 3)).'-'.strtolower(substr($running->room_code, 3)),
        $archived->room_code,
        'ZZ',
    ]));

    $this->artisan('loadtest:forget', ['file' => $this->codesFile])
        ->expectsOutputToContain(loadTestText('admin.console.loadtest.skipped_in_progress', ['count' => 1]))
        ->expectsOutputToContain(loadTestText('admin.console.loadtest.not_found', ['count' => 2]))
        ->expectsOutputToContain(loadTestText('admin.console.loadtest.deleted', ['rooms' => 0]))
        ->assertExitCode(0)
        ->run();

    expect(loadTestFacts($running->id))->toBe($before)
        ->and(Room::query()->whereKey($archived->id)->exists())->toBeTrue();
});

it('relit les sièges sous le verrou du salon : un siège réel arrivé entre-temps l’écarte', function (): void {
    $room = loadTestRoom([loadTestNickname(1), loadTestNickname(2)]);
    $arrived = false;

    // Un joueur réel prend un siège entre le relevé et le verrou : simulé à
    // la lecture verrouillée du salon, première requête de l'oubli.
    DB::listen(static function (QueryExecuted $query) use ($room, &$arrived): void {
        if (! $arrived && preg_match('/^select \* from "room" where "room"\."id" = \?/', $query->sql) === 1) {
            $arrived = true;
            Player::factory()->for($room)->withNickname('Zoé')->create();
        }
    });

    $this->artisan('loadtest:forget', ['file' => loadTestCodesFile($this->codesFile, $room)])
        ->expectsOutputToContain(loadTestText('admin.console.loadtest.skipped_real_seat', ['count' => 1]))
        ->expectsOutputToContain(loadTestText('admin.console.loadtest.deleted', ['rooms' => 0]))
        ->assertExitCode(0)
        ->run();

    expect($arrived)->toBeTrue()
        ->and(Room::query()->whereKey($room->id)->exists())->toBeTrue()
        ->and(Game::query()->where('room_id', $room->id)->count())->toBe(1)
        ->and(Player::query()->where('room_id', $room->id)->count())->toBe(3);
});

it('refuse une liste illisible sans rien supprimer', function (): void {
    $room = loadTestRoom([loadTestNickname(1), loadTestNickname(2)]);
    $before = loadTestFacts($room->id);

    $this->artisan('loadtest:forget', ['file' => $this->codesFile])
        ->expectsOutputToContain(loadTestText('admin.console.loadtest.missing_file', ['file' => $this->codesFile]))
        ->assertExitCode(1)
        ->run();

    expect(loadTestFacts($room->id))->toBe($before);
});
