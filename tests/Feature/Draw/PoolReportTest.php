<?php

use App\Enums\FrameLevel;
use App\Enums\PoolFault;
use App\Enums\PoolRemedyKind;
use App\Models\Movie;
use App\Models\Room;
use App\Models\Theme;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Settings\RoomSettingsEditor;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolRemedy;
use App\Support\Draw\PoolReport;
use App\Support\Draw\PoolReporter;
use App\Support\Draw\PoolScope;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\I18n\FrontSource;

/*
|--------------------------------------------------------------------------
| Rapport de vivier en données — spec 30 § 4, lot L30-3, contrat C2
|--------------------------------------------------------------------------
|
| La borne croisée 3 (`vivier(thèmes, N) ≥ M`) calculée en DONNÉES : des
| entiers, des booléens et des codes, jamais une chaîne ni un identifiant
| interne. Causes et remèdes suivent un ordre fixe, et chaque remède
| débloque à lui seul : c'est ce que prouvent les tests d'ordre, en
| appliquant chaque remède sur le salon réel et en rejouant le rapport.
|
| Les fixtures sont de vrais films à vraies variantes (`PoolFixtures`) : la
| projection est calculée par le projecteur, jamais écrite à la main. `M` et
| `N` sont lus dans `RoomSettingsBounds` dès qu'ils ne décrivent pas une
| distribution de fixture.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->now = CarbonImmutable::parse('2026-09-24 10:00:00.250');
});

function poolReporter(): PoolReporter
{
    return app(PoolReporter::class);
}

/**
 * Le rapport du salon sur ses réglages, à `$now`, pour le `M` des réglages.
 */
function poolReportForRoom(Room $room, RoomSettings $settings, CarbonImmutable $now): PoolReport
{
    return poolReporter()->report(PoolScope::forRoom($room, $settings, $now), $settings->roundsCount);
}

/**
 * Le film `$movie` démarré par le salon une heure avant `$now` : joué, donc
 * retiré du vivier du salon par la non-répétition.
 */
function poolReportPlayed(Room $room, Movie $movie, CarbonImmutable $now): void
{
    PoolFixtures::round(PoolFixtures::game($room), $movie, $now->subHour());
}

/**
 * Le SQL émis pendant `$run`, capturé par `DB::listen`.
 *
 * @return list<string>
 */
function poolReportQueries(Closure $run): array
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
 * Remèdes sous leur forme de charge : `[kind, value, count]`.
 *
 * @return list<array{kind: string, value: int|null, count: int}>
 */
function poolReportRemedies(PoolReport $report): array
{
    return array_map(static fn (PoolRemedy $remedy): array => $remedy->toArray(), $report->remedies);
}

/**
 * Un salon dont le vivier est bloqué par les QUATRE causes à la fois, au plus
 * petit `M` qui le permette : `M = MIN_ROUNDS_COUNT + 1`, vivier =
 * `MIN_ROUNDS_COUNT` films du thème, jouables au `N` par défaut. Trois films
 * de plus, chacun écarté par UNE seule clause, font que chaque réglage levé
 * seul rend exactement `M` œuvres :
 *
 * - un film du thème déjà joué par le salon (non-répétition) ;
 * - un film hors du thème (thèmes) ;
 * - un film du thème à banque de `N − 1` niveaux (N).
 *
 * @return array{room: Room, theme: Theme, settings: RoomSettings}
 */
function poolReportFourCauses(CarbonImmutable $now): array
{
    $room = Room::factory()->create();
    $theme = Theme::factory()->published()->create();
    $framesPerRound = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;

    foreach (PoolFixtures::movies(RoomSettingsBounds::MIN_ROUNDS_COUNT) as $movie) {
        PoolFixtures::member($movie, $theme);
    }

    $played = PoolFixtures::movie();
    PoolFixtures::member($played, $theme);
    poolReportPlayed($room, $played, $now);

    PoolFixtures::movie();

    $thin = PoolFixtures::movie(FrameLevelCoverage::nominal($framesPerRound - 1));
    PoolFixtures::member($thin, $theme);

    return [
        'room' => $room,
        'theme' => $theme,
        'settings' => PoolFixtures::settings(
            themeIds: [$theme->id],
            framesPerRound: $framesPerRound,
            noRepeatMovies: true,
            roundsCount: RoomSettingsBounds::MIN_ROUNDS_COUNT + 1,
        ),
    ];
}

/**
 * Les littéraux d'une union de chaînes de `resources/js/types/pool.ts`.
 *
 * @return list<string>
 */
function poolReportTsUnion(string $source, string $type): array
{
    expect(preg_match('/export\s+type\s+'.$type.'\s*=([^;]+);/', $source, $declaration))
        ->toBe(1, "type {$type} introuvable dans types/pool.ts");

    preg_match_all("/'([^']*)'/", $declaration[1], $literals);

    // Rien d'autre que des littéraux : un `string` élargirait l'union en silence.
    expect(trim((string) preg_replace("/'[^']*'|\|/", '', $declaration[1])))->toBe('', $type);

    return $literals[1];
}

it('un vivier bloqué par la seule non-répétition nomme noRepeatMovies et propose un nouveau salon', function () {
    $roundsCount = RoomSettingsBounds::MIN_ROUNDS_COUNT;
    $room = Room::factory()->create();
    $movies = PoolFixtures::movies($roundsCount);
    poolReportPlayed($room, $movies[0], $this->now);

    $settings = PoolFixtures::settings(noRepeatMovies: true, roundsCount: $roundsCount);
    $report = poolReportForRoom($room, $settings, $this->now);

    expect($report->blocked())->toBeTrue()
        ->and($report->count)->toBe($roundsCount - 1)
        ->and($report->causes)->toBe([PoolFault::NoRepeatMovies])
        ->and(poolReportRemedies($report))->toBe([
            ['kind' => PoolRemedyKind::OpenNewRoom->value, 'value' => null, 'count' => $roundsCount],
            // Toujours produit, pour que le J2 ne demande aucun changement de
            // calcul : c'est le présentateur de la spec 50 qui le retire au J1.
            ['kind' => PoolRemedyKind::DisableNoRepeat->value, 'value' => null, 'count' => $roundsCount],
        ])
        ->and($report->nearestPlayableFramesPerRound)->toBeNull()
        ->and($report->themesPruned)->toBeFalse()
        ->and($report->toArray()['causes'])->toBe(['noRepeatMovies']);

    // « Nouveau salon » est un vrai remède : sa mémoire est vide.
    $fresh = poolReportForRoom(Room::factory()->create(), $settings, $this->now);

    expect($fresh->blocked())->toBeFalse()
        ->and($fresh->count)->toBe($roundsCount);

    // Le vivier catalogue (supervision, solo) n'a aucune clause de salon : le
    // code noRepeatMovies n'y apparaît jamais.
    $catalogue = poolReporter()->report(
        PoolScope::catalogue([], $settings->framesPerRound)->excluding([$movies[1]->id], []),
        $roundsCount,
    );

    expect($catalogue->blocked())->toBeTrue()
        ->and($catalogue->causes)->not->toContain(PoolFault::NoRepeatMovies)
        ->and(array_column(poolReportRemedies($catalogue), 'kind'))
        ->not->toContain(PoolRemedyKind::OpenNewRoom->value)
        ->not->toContain(PoolRemedyKind::DisableNoRepeat->value);
});

it('un vivier bloqué par N nomme framesPerRound et propose le N jouable le plus proche', function () {
    $roundsCount = RoomSettingsBounds::MIN_ROUNDS_COUNT + 1;
    $passOne = FrameLevelCoverage::nominal(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);

    // Passe 1 seule : jouables jusqu'au N par défaut, jamais au-delà. Un seul
    // film à banque complète, sous le minimum de manches : réduire M n'est pas
    // proposé.
    PoolFixtures::movies($roundsCount, $passOne);
    PoolFixtures::movie(FrameLevel::cases());

    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(
        framesPerRound: RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
        noRepeatMovies: true,
        roundsCount: $roundsCount,
    );
    $report = poolReportForRoom($room, $settings, $this->now);

    expect($report->blocked())->toBeTrue()
        ->and($report->count)->toBe(1)
        ->and($report->framesPerRound)->toBe(RoomSettingsBounds::MAX_FRAMES_PER_ROUND)
        ->and($report->causes)->toBe([PoolFault::FramesPerRound])
        ->and($report->nearestPlayableFramesPerRound)->toBe(count($passOne))
        ->and(poolReportRemedies($report))->toBe([
            ['kind' => PoolRemedyKind::LowerFramesPerRound->value, 'value' => count($passOne), 'count' => $roundsCount + 1],
        ])
        ->and($report->toArray()['nearestPlayableFramesPerRound'])->toBe(count($passOne));

    // Le remède appliqué seul débloque.
    $lowered = poolReportForRoom(
        $room,
        PoolFixtures::settings(framesPerRound: count($passOne), noRepeatMovies: true, roundsCount: $roundsCount),
        $this->now,
    );

    expect($lowered->blocked())->toBeFalse()
        ->and($lowered->count)->toBe($roundsCount + 1);
});

it('le N jouable le plus proche est le plus grand N inférieur atteignant M, null sinon', function () {
    // Distribution : 3 films à banque complète, puis un film à 4, 3 et 2
    // niveaux — soit 6, 5, 4 et 3 œuvres de N = 2 à N = 5.
    PoolFixtures::movies(3, FrameLevel::cases());
    PoolFixtures::movie([FrameLevel::Level1, FrameLevel::Level2, FrameLevel::Level3, FrameLevel::Level5]);
    PoolFixtures::movie([FrameLevel::Level1, FrameLevel::Level3, FrameLevel::Level5]);
    PoolFixtures::movie([FrameLevel::Level1, FrameLevel::Level5]);

    $reporter = poolReporter();

    expect($reporter->catalogueWorksByFramesPerRound())->toBe([2 => 6, 3 => 5, 4 => 4, 5 => 3]);

    $atMax = PoolScope::catalogue([], RoomSettingsBounds::MAX_FRAMES_PER_ROUND);

    // Le PLUS GRAND N' < N qui atteint M, jamais au-delà de N.
    expect($reporter->nearestPlayableFramesPerRound($atMax, 4))->toBe(4)
        ->and($reporter->nearestPlayableFramesPerRound($atMax, 5))->toBe(3)
        ->and($reporter->nearestPlayableFramesPerRound($atMax, 6))->toBe(2)
        ->and($reporter->nearestPlayableFramesPerRound($atMax, 7))->toBeNull()
        ->and($reporter->nearestPlayableFramesPerRound($atMax->withFramesPerRound(3), 6))->toBe(2)
        ->and($reporter->nearestPlayableFramesPerRound($atMax->withFramesPerRound(3), 7))->toBeNull()
        // Sous N = MIN, l'intervalle [MIN, N − 1] est vide : jamais de proposition.
        ->and($reporter->nearestPlayableFramesPerRound(
            $atMax->withFramesPerRound(RoomSettingsBounds::MIN_FRAMES_PER_ROUND),
            RoomSettingsBounds::MIN_ROUNDS_COUNT,
        ))->toBeNull();

    // Le rapport porte le même N que le calcul seul.
    foreach ([4, 5, 6, 7] as $roundsCount) {
        expect($reporter->report($atMax, $roundsCount)->nearestPlayableFramesPerRound)
            ->toBe($reporter->nearestPlayableFramesPerRound($atMax, $roundsCount), "M = {$roundsCount}");
    }

    // Un périmètre sans N (complément des leurres) est refusé, jamais écrêté.
    expect(fn () => $reporter->nearestPlayableFramesPerRound($atMax->withFramesPerRound(null), 4))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reporter->report($atMax->withFramesPerRound(null), 4))
        ->toThrow(InvalidArgumentException::class);

    // Un M devenu hors bornes (réglages de salon à version périmée, relus
    // fidèlement par le cast) se calcule tel quel : c'est le lancement qui
    // normalise (50 § 12.2, L5), jamais le rapport.
    $aboveMax = $reporter->report($atMax, RoomSettingsBounds::MAX_ROUNDS_COUNT + 1);

    expect($aboveMax->roundsCount)->toBe(RoomSettingsBounds::MAX_ROUNDS_COUNT + 1)
        ->and($aboveMax->count)->toBe(3)
        ->and($aboveMax->blocked())->toBeTrue()
        ->and($reporter->report($atMax, RoomSettingsBounds::MIN_ROUNDS_COUNT - 1)->roundsCount)
        ->toBe(RoomSettingsBounds::MIN_ROUNDS_COUNT - 1);
});

it("réduire M n'est proposé que si le vivier atteint le minimum de manches", function () {
    $minimum = RoomSettingsBounds::MIN_ROUNDS_COUNT;
    $movies = PoolFixtures::movies($minimum);
    $reporter = poolReporter();
    $scope = PoolScope::catalogue([], RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);

    // Vivier = MIN : réduire M au vivier courant est un M admis.
    $report = $reporter->report($scope, $minimum + 1);

    expect($report->blocked())->toBeTrue()
        ->and($report->causes)->toBe([PoolFault::RoundsCount])
        ->and(poolReportRemedies($report))->toBe([
            ['kind' => PoolRemedyKind::ReduceRoundsCount->value, 'value' => $minimum, 'count' => $minimum],
        ])
        ->and($reporter->report($scope, $minimum)->blocked())->toBeFalse();

    // Vivier = MIN − 1 : aucun M admis ne tiendrait, le remède n'est pas proposé.
    $short = $scope->excluding([$movies[0]->id], []);

    foreach ([$minimum, $minimum + 1] as $roundsCount) {
        $blocked = $reporter->report($short, $roundsCount);

        expect($blocked->blocked())->toBeTrue()
            ->and($blocked->count)->toBe($minimum - 1)
            ->and($blocked->causes)->not->toContain(PoolFault::RoundsCount)
            ->and(array_column(poolReportRemedies($blocked), 'kind'))
            ->not->toContain(PoolRemedyKind::ReduceRoundsCount->value);
    }
});

it("un vivier non bloqué n'a ni cause ni remède", function () {
    $roundsCount = RoomSettingsBounds::MIN_ROUNDS_COUNT;
    PoolFixtures::movies($roundsCount);
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(noRepeatMovies: true, roundsCount: $roundsCount);

    // Le périmètre est construit avant la capture : sa fenêtre de mémoire est
    // lue une fois, à la construction, hors du coût du rapport.
    $scope = PoolScope::forRoom($room, $settings, $this->now);
    $report = null;

    $queries = poolReportQueries(function () use ($scope, $roundsCount, &$report): void {
        $report = poolReporter()->report($scope, $roundsCount);
    });

    // Vivier = M, borne comprise : non bloqué, un seul compte.
    expect($report)->toBeInstanceOf(PoolReport::class)
        ->and($queries)->toHaveCount(1)
        ->and($report->toArray())->toBe([
            'count' => $roundsCount,
            'framesPerRound' => $settings->framesPerRound,
            'roundsCount' => $roundsCount,
            'blocked' => false,
            'causes' => [],
            'remedies' => [],
            'nearestPlayableFramesPerRound' => null,
            'themesPruned' => false,
        ]);

    // Un thème élagué ne change rien au verdict : signalé, jamais accusé.
    $dropped = Theme::factory()->unpublished()->create();
    $pruned = (new PoolReporter(new PoolQuery))->report(
        PoolScope::forRoom($room, PoolFixtures::settings(themeIds: [$dropped->id], roundsCount: $roundsCount), $this->now),
        $roundsCount,
    );

    expect($pruned->blocked())->toBeFalse()
        ->and($pruned->themesPruned)->toBeTrue()
        ->and($pruned->causes)->toBe([])
        ->and($pruned->remedies)->toBe([])
        ->and($pruned->nearestPlayableFramesPerRound)->toBeNull();

    // La sémantique est gardée à la construction : un vivier non bloqué qui
    // porterait une cause est refusé.
    expect(fn () => new PoolReport(
        count: $roundsCount,
        framesPerRound: $settings->framesPerRound,
        roundsCount: $roundsCount,
        causes: [PoolFault::RoundsCount],
        remedies: [],
        nearestPlayableFramesPerRound: null,
        themesPruned: false,
    ))->toThrow(InvalidArgumentException::class);
});

it('le rapport ne contient que des entiers, des booléens et des codes', function () {
    ['room' => $room, 'theme' => $theme, 'settings' => $settings] = poolReportFourCauses($this->now);
    $dropped = Theme::factory()->unpublished()->create();

    // Toutes les causes, tous les remèdes, et un thème élagué.
    $report = poolReportForRoom(
        $room,
        PoolFixtures::settings(
            themeIds: [$theme->id, $dropped->id],
            framesPerRound: $settings->framesPerRound,
            noRepeatMovies: true,
            roundsCount: $settings->roundsCount,
        ),
        $this->now,
    );
    $payload = $report->toArray();

    expect(array_keys($payload))->toBe([
        'count',
        'framesPerRound',
        'roundsCount',
        'blocked',
        'causes',
        'remedies',
        'nearestPlayableFramesPerRound',
        'themesPruned',
    ])
        ->and($payload['causes'])->toHaveCount(count(PoolFault::cases()))
        ->and($payload['remedies'])->toHaveCount(count(PoolRemedyKind::cases()))
        // Un booléen, jamais la liste des thèmes élagués.
        ->and($payload['themesPruned'])->toBeTrue();

    foreach ($payload['remedies'] as $remedy) {
        expect(array_keys($remedy))->toBe(['kind', 'value', 'count']);
    }

    $codes = [
        ...array_map(static fn (PoolFault $fault): string => $fault->value, PoolFault::cases()),
        ...array_map(static fn (PoolRemedyKind $kind): string => $kind->value, PoolRemedyKind::cases()),
    ];

    foreach (Arr::dot($payload) as $path => $leaf) {
        if (is_string($leaf)) {
            expect($leaf)->toBeIn($codes, (string) $path);
        } else {
            expect(is_int($leaf) || is_bool($leaf) || $leaf === null)->toBeTrue((string) $path);
        }
    }

    // Aucun identifiant interne ni aucune donnée de catalogue : ni clé de
    // thème, ni titre.
    $json = (string) json_encode($payload);

    expect($json)->not->toContain($theme->key)
        ->and($json)->not->toContain($dropped->key);

    foreach (Movie::query()->pluck('title_original') as $title) {
        expect($json)->not->toContain((string) $title);
    }
});

it('chaque cas de PoolFault est une clé postable de RoomSettingsEditor', function () {
    $postable = [...RoomSettingsEditor::SIMPLE_KEYS, ...RoomSettingsEditor::ADVANCED_KEYS];

    foreach (PoolFault::cases() as $fault) {
        expect($fault->value)->toBeIn($postable, $fault->name);
    }

    // Le nom client du champ, jamais son nom serveur (R-11) ; la non-répétition
    // est une clé de l'onglet Avancé (D28 du 23/09).
    expect(array_map(static fn (PoolFault $fault): string => $fault->value, PoolFault::cases()))
        ->not->toContain('themeIds')
        ->and(PoolFault::NoRepeatMovies->value)->toBeIn(RoomSettingsEditor::ADVANCED_KEYS);
});

it('les unions de resources/js/types/pool.ts couvrent exactement les cas des deux enums', function () {
    $path = resource_path('js/types/pool.ts');

    expect($path)->toBeFile();

    $source = FrontSource::withoutComments((string) file_get_contents($path));

    foreach ([
        'PoolFault' => PoolFault::cases(),
        'PoolRemedyKind' => PoolRemedyKind::cases(),
    ] as $type => $cases) {
        $literals = poolReportTsUnion($source, $type);
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);

        sort($literals);
        sort($values);

        expect($literals)->toBe($values, $type);
    }

    // Miroir de la forme : les champs du type client sont les clés de toArray().
    expect(preg_match('/export\s+type\s+PoolReport\s*=\s*\{([^}]*)\}/', $source, $report))->toBe(1)
        ->and(preg_match('/export\s+type\s+PoolRemedy\s*=\s*\{([^}]*)\}/', $source, $remedy))->toBe(1);

    preg_match_all('/^\s*(\w+)\s*:/m', $report[1], $reportFields);
    preg_match_all('/^\s*(\w+)\s*:/m', $remedy[1], $remedyFields);

    $sample = new PoolReport(
        count: 0,
        framesPerRound: RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
        roundsCount: RoomSettingsBounds::DEFAULT_ROUNDS_COUNT,
        causes: [],
        remedies: [],
        nearestPlayableFramesPerRound: null,
        themesPruned: false,
    );

    expect($reportFields[1])->toBe(array_keys($sample->toArray()))
        ->and($remedyFields[1])->toBe(array_keys((new PoolRemedy(PoolRemedyKind::ClearThemes, null, 0))->toArray()));
});

it('un vivier bloqué par les seuls thèmes nomme themeKeys et propose de vider les thèmes', function () {
    $roundsCount = RoomSettingsBounds::MIN_ROUNDS_COUNT;
    $theme = Theme::factory()->published()->create();
    $dropped = Theme::factory()->unpublished()->create();
    $movies = PoolFixtures::movies($roundsCount);
    PoolFixtures::member($movies[0], $theme);
    PoolFixtures::member($movies[1], $dropped);

    $room = Room::factory()->create();
    $report = poolReportForRoom(
        $room,
        PoolFixtures::settings(themeIds: [$theme->id], noRepeatMovies: true, roundsCount: $roundsCount),
        $this->now,
    );

    expect($report->blocked())->toBeTrue()
        ->and($report->count)->toBe(1)
        ->and($report->causes)->toBe([PoolFault::ThemeKeys])
        ->and(poolReportRemedies($report))->toBe([
            ['kind' => PoolRemedyKind::ClearThemes->value, 'value' => null, 'count' => $roundsCount],
        ])
        ->and($report->themesPruned)->toBeFalse()
        // Le code désigne le champ client, jamais `themeIds` (R-11).
        ->and($report->toArray()['causes'])->toBe(['themeKeys']);

    // Vider les thèmes débloque.
    expect(poolReportForRoom($room, PoolFixtures::settings(noRepeatMovies: true, roundsCount: $roundsCount), $this->now)->blocked())
        ->toBeFalse();

    // Un thème dépublié est élagué à la lecture : il ne filtre plus rien et
    // n'est jamais accusé.
    $pruned = poolReportForRoom(
        $room,
        PoolFixtures::settings(themeIds: [$dropped->id], noRepeatMovies: true, roundsCount: $roundsCount),
        $this->now,
    );

    expect($pruned->blocked())->toBeFalse()
        ->and($pruned->count)->toBe($roundsCount)
        ->and($pruned->themesPruned)->toBeTrue()
        ->and($pruned->causes)->not->toContain(PoolFault::ThemeKeys);
});

it('les causes et les remèdes suivent l\'ordre fixe et chaque remède débloque à lui seul', function () {
    ['room' => $room, 'theme' => $theme, 'settings' => $settings] = poolReportFourCauses($this->now);
    $roundsCount = $settings->roundsCount;
    $report = poolReportForRoom($room, $settings, $this->now);

    // L'ordre fixe est celui des cas des deux enums, que la spec 50 reprend.
    expect($report->blocked())->toBeTrue()
        ->and($report->count)->toBe(RoomSettingsBounds::MIN_ROUNDS_COUNT)
        ->and($report->causes)->toBe(PoolFault::cases())
        ->and(array_map(static fn (PoolRemedy $remedy): PoolRemedyKind => $remedy->kind, $report->remedies))
        ->toBe(PoolRemedyKind::cases())
        ->and(poolReportRemedies($report))->toBe([
            ['kind' => 'open_new_room', 'value' => null, 'count' => $roundsCount],
            ['kind' => 'disable_no_repeat', 'value' => null, 'count' => $roundsCount],
            ['kind' => 'clear_themes', 'value' => null, 'count' => $roundsCount],
            ['kind' => 'lower_frames_per_round', 'value' => $settings->framesPerRound - 1, 'count' => $roundsCount],
            ['kind' => 'reduce_rounds_count', 'value' => $report->count, 'count' => $report->count],
        ]);

    // Chaque remède, appliqué SEUL sur le salon réel, débloque et rend
    // exactement le vivier annoncé.
    $now = $this->now;
    $applied = static fn (
        ?Room $inRoom = null,
        ?array $themeIds = null,
        ?int $framesPerRound = null,
        ?bool $noRepeatMovies = null,
        ?int $rounds = null,
    ): PoolReport => poolReportForRoom(
        $inRoom ?? $room,
        PoolFixtures::settings(
            themeIds: $themeIds ?? [$theme->id],
            framesPerRound: $framesPerRound ?? $settings->framesPerRound,
            noRepeatMovies: $noRepeatMovies ?? true,
            roundsCount: $rounds ?? $roundsCount,
        ),
        $now,
    );

    foreach ($report->remedies as $remedy) {
        $unblocked = match ($remedy->kind) {
            PoolRemedyKind::OpenNewRoom => $applied(inRoom: Room::factory()->create()),
            PoolRemedyKind::DisableNoRepeat => $applied(noRepeatMovies: false),
            PoolRemedyKind::ClearThemes => $applied(themeIds: []),
            PoolRemedyKind::LowerFramesPerRound => $applied(framesPerRound: $remedy->value),
            PoolRemedyKind::ReduceRoundsCount => $applied(rounds: $remedy->value),
        };

        expect($unblocked->blocked())->toBeFalse($remedy->kind->value)
            ->and($unblocked->count)->toBe($remedy->count, $remedy->kind->value);
    }
});

it("un vivier bloqué qu'aucun réglage ne débloque rend des causes vides", function () {
    $roundsCount = RoomSettingsBounds::MIN_ROUNDS_COUNT;
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(
        framesPerRound: RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
        noRepeatMovies: true,
        roundsCount: $roundsCount,
    );

    // Catalogue vide.
    $empty = poolReportForRoom($room, $settings, $this->now);

    expect($empty->toArray())->toBe([
        'count' => 0,
        'framesPerRound' => RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
        'roundsCount' => $roundsCount,
        'blocked' => true,
        'causes' => [],
        'remedies' => [],
        'nearestPlayableFramesPerRound' => null,
        'themesPruned' => false,
    ]);

    // Moins de M films, tous du thème et à banque complète, dont un déjà joué :
    // ni la non-répétition, ni les thèmes, ni N, ni M ne suffisent seuls.
    $theme = Theme::factory()->published()->create();
    $movies = PoolFixtures::movies($roundsCount - 1, FrameLevel::cases());

    foreach ($movies as $movie) {
        PoolFixtures::member($movie, $theme);
    }

    poolReportPlayed($room, $movies[0], $this->now);

    $starved = (new PoolReporter(new PoolQuery))->report(
        PoolScope::forRoom($room, PoolFixtures::settings(
            themeIds: [$theme->id],
            framesPerRound: RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
            noRepeatMovies: true,
            roundsCount: $roundsCount,
        ), $this->now),
        $roundsCount,
    );

    expect($starved->blocked())->toBeTrue()
        ->and($starved->count)->toBe($roundsCount - 2)
        ->and($starved->causes)->toBe([])
        ->and($starved->remedies)->toBe([])
        ->and($starved->nearestPlayableFramesPerRound)->toBeNull();
});
