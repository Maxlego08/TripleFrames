<?php

use App\Actions\Curation\RecordCurationHeartbeat;
use App\Actions\Curation\RecropFrame;
use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\Locale;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Curation\CurationQueue;
use App\Support\Curation\PilotSettings;
use App\Support\Curation\PilotVerdict;
use App\Support\Curation\ThroughputReport;
use App\Support\Frames\CropRect;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Le tableau du débit et le verdict du pilote — spec 20 § 10.2 à § 10.4,
| lot L20-17 (décision 10, D10 et D11 du 23/09)
|--------------------------------------------------------------------------
|
| Un film est TERMINÉ à sa première ligne `movie.published` ou
| `movie.unpublished` du journal ; publié ou écarté selon cette ligne. Les
| films sont posés ici comme la curation les laisse : un temps actif figé,
| des images recadrées AVANT la terminaison, une ligne de journal datée.
| Les seuils sont ceux de la configuration versionnée — les tests en
| vérifient d'abord les valeurs quand leur intitulé les nomme.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->curator = User::factory()->curator()->create([
        'name' => 'pseudo-camille',
        'real_name' => 'Camille Curatrice',
        'email' => 'camille@example.test',
    ]);

    $this->start = CarbonImmutable::parse('2026-10-01 09:00:00');
});

/**
 * Un film réel terminé à `$at` : publié, ou écarté avec son motif. Son temps
 * actif est figé, et ses images recadrées ont été créées avant la
 * terminaison, une minute d'écart chacune.
 *
 * @param  list<int>  $crops  les `crop_seconds` de ses images
 */
function throughputFilm(
    User $curator,
    CarbonImmutable $at,
    string $entry = CurationQueue::ENTRY_DISCOVER,
    bool $published = true,
    int $active = 600,
    array $crops = [],
    string $reason = 'Aucun visuel TMDB exploitable.',
): Movie {
    $factory = Movie::factory();

    if ($entry === CurationQueue::ENTRY_EXCEPTION) {
        $factory = $factory->importException(forLanguage: true);
    }

    $movie = $factory->create([
        'availability' => $published ? ContentAvailability::Published : ContentAvailability::Unpublished,
        'availability_changed_at' => $at,
        'availability_reason' => $published ? null : $reason,
        'first_published_at' => $published ? $at : null,
        'curation_active_seconds' => $active,
        'curated_by_id' => $curator->id,
    ]);

    foreach ($crops as $index => $seconds) {
        Frame::factory()->for($movie)->create([
            'crop_seconds' => $seconds,
            'created_at' => $at->subMinutes(count($crops) - $index),
        ]);
    }

    throughputJournal($curator, $published ? AdminActionType::MoviePublished : AdminActionType::MovieUnpublished, $movie, $at, $reason);

    return $movie;
}

/**
 * Une ligne du journal sur un film, datée.
 */
function throughputJournal(User $actor, AdminActionType $action, Movie $movie, CarbonImmutable $at, string $reason = 'Motif du journal.'): void
{
    AdminAction::factory()
        ->byActor($actor)
        ->of($action, $movie->id)
        ->create([
            'created_at' => $at,
            'reason' => $action->requiresReason() ? $reason : null,
        ]);
}

/**
 * Un pilote : les films `discover`, puis ceux de la voie d'exception,
 * terminés dans cet ordre à une minute d'écart, tous publiés. Rend l'instant
 * de la dernière terminaison.
 *
 * @param  list<int>  $discover  temps actifs des films `discover`
 * @param  list<int>  $exception  temps actifs des films d'exception
 */
function throughputPilot(User $curator, CarbonImmutable $start, array $discover, array $exception): CarbonImmutable
{
    $at = $start;

    foreach ([CurationQueue::ENTRY_DISCOVER => $discover, CurationQueue::ENTRY_EXCEPTION => $exception] as $entry => $films) {
        foreach ($films as $active) {
            $at = $at->addMinute();
            throughputFilm($curator, $at, $entry, active: $active);
        }
    }

    return $at;
}

/**
 * Autant de temps actifs que la voie en compte au pilote.
 *
 * @return list<int>
 */
function throughputQuota(string $entry, int $active): array
{
    return array_fill(0, Config::integer('catalog.curation.pilot.composition.'.$entry), $active);
}

/**
 * Le verdict de la base courante, qui doit être rendu.
 */
function throughputVerdict(): PilotVerdict
{
    $verdict = ThroughputReport::measure()->verdict();

    expect($verdict)->toBeInstanceOf(PilotVerdict::class);

    return $verdict ?? throw new LogicException('Verdict absent.');
}

/**
 * Toutes les clés d'un tableau imbriqué, à toute profondeur.
 *
 * @param  array<array-key, mixed>  $values
 * @return list<string>
 */
function throughputKeys(array $values): array
{
    $keys = [];

    foreach ($values as $key => $value) {
        $keys[] = (string) $key;

        if (is_array($value)) {
            $keys = [...$keys, ...throughputKeys($value)];
        }
    }

    return $keys;
}

test('la médiane et le p90 suivent le rang le plus proche', function (): void {
    // Rang ⌈p × n⌉ des valeurs croissantes, sans interpolation.
    expect(ThroughputReport::nearestRank([40, 10, 30, 20], ThroughputReport::MEDIAN_PERCENT))->toBe(20)
        ->and(ThroughputReport::nearestRank([40, 10, 30, 20], ThroughputReport::P90_PERCENT))->toBe(40)
        ->and(ThroughputReport::nearestRank(range(1, 20), ThroughputReport::P90_PERCENT))->toBe(18)
        ->and(ThroughputReport::nearestRank(range(1, 20), ThroughputReport::MEDIAN_PERCENT))->toBe(10)
        ->and(ThroughputReport::nearestRank([7], ThroughputReport::P90_PERCENT))->toBe(7)
        ->and(ThroughputReport::nearestRank([], ThroughputReport::MEDIAN_PERCENT))->toBeNull();

    // Et le tableau les applique : dix films publiés, de 100 à 1 000 s
    // d'activité, terminés dans le désordre ; des images de 5 à 35 s.
    $actives = [700, 100, 1000, 300, 900, 200, 500, 800, 400, 600];

    foreach ($actives as $index => $active) {
        throughputFilm($this->curator, $this->start->addMinutes($index + 1), active: $active, crops: $index === 0 ? [35, 5, 25, 15] : []);
    }

    $measures = ThroughputReport::measure()->measures()[CurationQueue::ENTRY_DISCOVER];

    expect($measures['active_seconds_median'])->toBe(500)
        ->and($measures['active_seconds_p90'])->toBe(900)
        ->and($measures['crop_frames'])->toBe(4)
        ->and($measures['crop_seconds_median'])->toBe(15)
        ->and($measures['crop_seconds_p90'])->toBe(35);
});

test('les mesures sont ventilées par voie d\'entrée', function (): void {
    throughputFilm($this->curator, $this->start->addMinutes(1), active: 100, crops: [10]);
    throughputFilm($this->curator, $this->start->addMinutes(2), active: 300, crops: [30, 50]);
    throughputFilm($this->curator, $this->start->addMinutes(3), CurationQueue::ENTRY_EXCEPTION, active: 700, crops: [70]);
    throughputFilm($this->curator, $this->start->addMinutes(4), CurationQueue::ENTRY_EXCEPTION, published: false, active: 900);

    // Hors mesure : un brouillon jamais terminé, et un film de démonstration
    // publié.
    Movie::factory()->create(['curation_active_seconds' => 5000]);
    $demo = Movie::factory()->demo()->published()->create(['curation_active_seconds' => 5000]);
    throughputJournal($this->curator, AdminActionType::MoviePublished, $demo, $this->start->addMinutes(5));

    $measures = ThroughputReport::measure()->measures();

    expect($measures[CurationQueue::ENTRY_DISCOVER])->toMatchArray([
        'films' => 2,
        'published' => 2,
        'set_aside' => 0,
        'active_seconds_total' => 400,
        'active_seconds_median' => 100,
        'active_seconds_p90' => 300,
        'crop_frames' => 3,
        'crop_seconds_median' => 30,
        'crop_seconds_p90' => 50,
    ]);

    // Le temps actif total compte l'écarté ; la médiane et le p90, les seuls
    // films publiés.
    expect($measures[CurationQueue::ENTRY_EXCEPTION])->toMatchArray([
        'films' => 2,
        'published' => 1,
        'set_aside' => 1,
        'active_seconds_total' => 1600,
        'active_seconds_median' => 700,
        'active_seconds_p90' => 700,
        'crop_frames' => 1,
    ]);

    expect($measures[ThroughputReport::TOTAL])->toMatchArray([
        'films' => 4,
        'published' => 3,
        'set_aside' => 1,
        'active_seconds_total' => 2000,
        'active_seconds_median' => 300,
        'active_seconds_p90' => 700,
        'crop_frames' => 4,
    ]);
});

test('aucun curateur n\'est nommé dans le rapport', function (): void {
    throughputPilot(
        $this->curator,
        $this->start,
        throughputQuota(CurationQueue::ENTRY_DISCOVER, 600),
        throughputQuota(CurationQueue::ENTRY_EXCEPTION, 600),
    );
    throughputFilm($this->curator, $this->start->addHour(), published: false);

    // Lu par un AUTRE compte : la charge utile du rapport ne dit rien de son
    // auteur, ni nom, ni nom réel, ni adresse, ni identifiant.
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.throughput.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $page->component('admin/throughput')->has('report.verdict');

            $report = $page->toArray()['props']['report'];
            $json = (string) json_encode($report, JSON_UNESCAPED_UNICODE);

            foreach (['pseudo-camille', 'Camille', 'camille@example.test'] as $needle) {
                expect($json)->not->toContain($needle);
            }

            foreach (throughputKeys($report) as $key) {
                expect($key)->not->toMatch('/actor|curator|curated|reviewer|user|author|name$/');
            }
        });

    // Le service lui-même, hors de toute page : aucune clé d'auteur, et les
    // films terminés par un curateur ne le sont par personne en particulier.
    $report = ThroughputReport::measure()->toArray();

    foreach (throughputKeys($report) as $key) {
        expect($key)->not->toMatch('/actor|curator|curated|reviewer|user|author|name$/');
    }

    expect((string) json_encode($report, JSON_UNESCAPED_UNICODE))->not->toContain('Camille');
});

test('le pilote disqualifie au-delà de dix heures actives', function (): void {
    expect(Config::integer('catalog.curation.pilot.disqualify_hours'))->toBe(10)
        ->and(Config::integer('catalog.curation.pilot.size'))->toBe(20);

    // Premier pilote : vingt films de 30 min, dix heures tout rond — sous le
    // seuil, qui est un dépassement strict.
    $last = throughputPilot(
        $this->curator,
        $this->start,
        throughputQuota(CurationQueue::ENTRY_DISCOVER, 1800),
        throughputQuota(CurationQueue::ENTRY_EXCEPTION, 1800),
    );

    $verdict = throughputVerdict();

    expect($verdict->totalActiveSeconds)->toBe(10 * PilotSettings::SECONDS_PER_HOUR)
        ->and($verdict->disqualified)->toBeFalse();

    // Second pilote, mesuré en décalant les rangs après une re-livraison :
    // vingt secondes de plus, portées par un film ÉCARTÉ — le temps total
    // compte les films écartés.
    $at = $last;

    foreach ([CurationQueue::ENTRY_DISCOVER, CurationQueue::ENTRY_EXCEPTION] as $entry) {
        $quota = Config::integer('catalog.curation.pilot.composition.'.$entry);

        foreach (range(1, $quota) as $rank) {
            $at = $at->addMinute();
            $setAside = $entry === CurationQueue::ENTRY_DISCOVER && $rank === 1;

            throughputFilm($this->curator, $at, $entry, published: ! $setAside, active: $setAside ? 1820 : 1800);
        }

        Config::set('catalog.curation.pilot.first_rank.'.$entry, $quota + 1);
    }

    $verdict = throughputVerdict();

    expect($verdict->totalActiveSeconds)->toBe(10 * PilotSettings::SECONDS_PER_HOUR + 20)
        ->and($verdict->failures)->toBe(1)
        ->and($verdict->disqualified)->toBeTrue()
        ->and($verdict->toArray()['disqualified'])->toBeTrue();
});

test('le J1 reste à soixante films tant que p90 fois quarante tient dans la réserve', function (): void {
    expect(Config::integer('catalog.curation.pilot.j1_target_films'))->toBe(60)
        ->and(Config::integer('catalog.curation.pilot.reserve_hours'))->toBe(36)
        ->and(PilotSettings::current()->remainingAfterPilot())->toBe(40);

    // 36 h ÷ 40 films = 54 min : un p90 de 54 min tient EXACTEMENT dans la
    // réserve. Deux films plus lents que le p90 n'y changent rien : c'est le
    // p90 qui compte, jamais le maximum.
    $p90 = intdiv(36 * PilotSettings::SECONDS_PER_HOUR, 40);

    throughputPilot(
        $this->curator,
        $this->start,
        [...array_fill(0, 13, $p90), 9000, 9000],
        throughputQuota(CurationQueue::ENTRY_EXCEPTION, 600),
    );

    $verdict = throughputVerdict()->toArray();

    expect($verdict['p90_seconds'])->toBe($p90)
        ->and($verdict['j1']['films'])->toBe(60)
        ->and($verdict['j1']['target_kept'])->toBeTrue()
        ->and($verdict['j1']['seconds'])->toBe(60 * $p90);
});

test('sinon le J1 compte réserve divisée par p90 films', function (): void {
    $reserve = 36 * PilotSettings::SECONDS_PER_HOUR;

    // Une seconde au-dessus de 54 min : p90 × 40 dépasse la réserve.
    $p90 = intdiv($reserve, 40) + 1;

    throughputPilot(
        $this->curator,
        $this->start,
        throughputQuota(CurationQueue::ENTRY_DISCOVER, $p90),
        throughputQuota(CurationQueue::ENTRY_EXCEPTION, $p90),
    );

    $verdict = throughputVerdict()->toArray();

    expect($verdict['j1']['target_kept'])->toBeFalse()
        ->and($verdict['j1']['films'])->toBe(intdiv($reserve, $p90))
        ->and($verdict['j1']['films'])->toBe(39);

    // Deux heures au p90 : 36 h ÷ 2 h = 18 films.
    $projection = PilotVerdict::projection(2 * PilotSettings::SECONDS_PER_HOUR, PilotSettings::current());

    expect($projection['j1']['films'])->toBe(18)
        ->and($projection['j1']['target_kept'])->toBeFalse();
});

test('la cible de volume ne dépasse jamais le plafond configuré', function (): void {
    Config::set('catalog.curation.pilot.weekly_curation_hours', 10);
    Config::set('catalog.curation.pilot.horizon_weeks', 52);

    $cap = Config::integer('catalog.curation.pilot.volume_cap');
    $declared = 10 * 52 * PilotSettings::SECONDS_PER_HOUR;

    expect($cap)->toBe(500);

    foreach ([0, 1, 60, 3600, 7200, 36000] as $p90) {
        $volume = PilotVerdict::projection($p90, PilotSettings::current())['volume']['films'];

        expect($volume)->toBeLessThanOrEqual($cap)
            ->and($volume)->toBe($p90 === 0 ? $cap : min($cap, intdiv($declared, $p90)));
    }

    // Deux heures au p90 : 520 h ÷ 2 h = 260 films, sous le plafond.
    expect(PilotVerdict::projection(7200, PilotSettings::current())['volume']['films'])->toBe(260);

    // Un plafond abaissé est tenu lui aussi, jusque dans le verdict.
    Config::set('catalog.curation.pilot.volume_cap', 100);

    throughputPilot(
        $this->curator,
        $this->start,
        throughputQuota(CurationQueue::ENTRY_DISCOVER, 60),
        throughputQuota(CurationQueue::ENTRY_EXCEPTION, 60),
    );

    expect(throughputVerdict()->toArray()['volume']['films'])->toBe(100);
});

test('un film écarté du pilote compte comme un échec', function (): void {
    $at = $this->start;
    $setAside = null;

    foreach (range(1, 15) as $rank) {
        $at = $at->addMinute();

        // Le troisième film `discover` est incurable faute de visuels TMDB.
        $movie = throughputFilm($this->curator, $at, published: $rank !== 3, active: $rank === 3 ? 5000 : 600, reason: 'Aucun visuel de plateau exploitable.');
        $setAside = $rank === 3 ? $movie : $setAside;
    }

    foreach (range(1, 5) as $rank) {
        $at = $at->addMinute();
        throughputFilm($this->curator, $at, CurationQueue::ENTRY_EXCEPTION, active: 600);
    }

    // Curé puis publié plus tard, il reste un échec du pilote : sa
    // terminaison est sa première ligne.
    expect($setAside)->toBeInstanceOf(Movie::class);
    throughputJournal($this->curator, AdminActionType::MoviePublished, $setAside, $at->addHour());

    $report = ThroughputReport::measure();
    $verdict = throughputVerdict();

    expect($verdict->films)->toBe(20)
        ->and($verdict->failures)->toBe(1)
        // Le temps actif total compte l'écarté…
        ->and($verdict->totalActiveSeconds)->toBe(19 * 600 + 5000)
        // … le p90, jamais : il ne porte que sur les films publiés.
        ->and($verdict->projection['p90_seconds'])->toBe(600);

    $pilot = $report->toArray()['pilot'];

    expect($pilot['measures'][ThroughputReport::TOTAL]['set_aside'])->toBe(1)
        ->and($pilot['measures'][ThroughputReport::TOTAL]['published'])->toBe(19)
        ->and($pilot['set_aside'])->toHaveCount(1)
        ->and($pilot['set_aside'][0]['movie_id'])->toBe($setAside->id)
        ->and($pilot['set_aside'][0]['reason'])->toBe('Aucun visuel de plateau exploitable.');
});

test('la terminaison d\'un film est sa première ligne movie.published ou movie.unpublished', function (): void {
    $at = fn (int $minutes): CarbonImmutable => $this->start->addMinutes($minutes);

    // A : contenu vérifié (pas une terminaison), publié à 2, dépublié à 5,
    // republié à 6 — et `availability_changed_at` à 6.
    $a = Movie::factory()->published()->create(['availability_changed_at' => $at(6), 'first_published_at' => $at(2)]);
    throughputJournal($this->curator, AdminActionType::MovieContentVerified, $a, $at(0));
    throughputJournal($this->curator, AdminActionType::MoviePublished, $a, $at(2));
    throughputJournal($this->curator, AdminActionType::MovieUnpublished, $a, $at(5));
    throughputJournal($this->curator, AdminActionType::MovieRepublished, $a, $at(6));

    // B : écarté à 1, puis curé et publié à 4 — un écarté pour la mesure.
    $b = Movie::factory()->published()->create(['availability_changed_at' => $at(4), 'first_published_at' => $at(4)]);
    throughputJournal($this->curator, AdminActionType::MovieUnpublished, $b, $at(1));
    throughputJournal($this->curator, AdminActionType::MoviePublished, $b, $at(4));

    // E : publié à 3, `availability_changed_at` antérieur à tout — jamais lu.
    $e = Movie::factory()->published()->create(['availability_changed_at' => $at(-60), 'first_published_at' => $at(3)]);
    throughputJournal($this->curator, AdminActionType::MoviePublished, $e, $at(3));

    // Jamais terminés : un brouillon sans ligne, un film de démonstration.
    Movie::factory()->create();
    $demo = Movie::factory()->demo()->published()->create();
    throughputJournal($this->curator, AdminActionType::MoviePublished, $demo, $at(0));

    $films = ThroughputReport::measure()->pilotWindow()[CurationQueue::ENTRY_DISCOVER];

    expect(array_column($films, 'movie_id'))->toBe([$b->id, $a->id, $e->id])
        ->and(array_column($films, 'published'))->toBe([false, true, true])
        ->and(array_map(
            fn (array $film): string => $film['terminated_at']->toDateTimeString(),
            $films,
        ))->toBe([
            $at(1)->toDateTimeString(),
            $at(2)->toDateTimeString(),
            $at(3)->toDateTimeString(),
        ]);

    // Deux terminaisons de la même seconde : l'ordre du journal départage.
    $c = Movie::factory()->published()->create();
    $d = Movie::factory()->published()->create();
    throughputJournal($this->curator, AdminActionType::MoviePublished, $d, $at(10));
    throughputJournal($this->curator, AdminActionType::MoviePublished, $c, $at(10));

    $films = ThroughputReport::measure()->pilotWindow()[CurationQueue::ENTRY_DISCOVER];

    expect(array_slice(array_column($films, 'movie_id'), 3))->toBe([$d->id, $c->id]);
});

test('le verdict attend que chaque voie ait son quota de films terminés', function (): void {
    // Dix-huit films `discover` terminés avant deux films d'exception : la
    // voie `discover` a son quota, l'autre non — aucun verdict sur une
    // composition que D11 exclut.
    $at = $this->start;

    foreach (range(1, 18) as $rank) {
        $at = $at->addMinute();
        throughputFilm($this->curator, $at);
    }

    foreach (range(1, 2) as $rank) {
        $at = $at->addMinute();
        throughputFilm($this->curator, $at, CurationQueue::ENTRY_EXCEPTION);
    }

    $report = ThroughputReport::measure();

    expect($report->verdict())->toBeNull()
        ->and($report->pilotProgress())->toBe([
            CurationQueue::ENTRY_DISCOVER => ['terminated' => 15, 'quota' => 15, 'first_rank' => 1, 'full' => true],
            CurationQueue::ENTRY_EXCEPTION => ['terminated' => 2, 'quota' => 5, 'first_rank' => 1, 'full' => false],
        ])
        ->and($report->toArray()['verdict'])->toBeNull()
        ->and($report->toArray()['pilot']['full'])->toBeFalse();

    // Les trois films d'exception qui manquent : la fenêtre se remplit à la
    // terminaison du dernier.
    foreach (range(1, 3) as $rank) {
        $at = $at->addMinute();
        throughputFilm($this->curator, $at, CurationQueue::ENTRY_EXCEPTION);
    }

    $verdict = throughputVerdict();

    expect($verdict->filledAt->toDateTimeString())->toBe($at->toDateTimeString())
        ->and($verdict->films)->toBe(20);

    // Un rang de départ décalé (un film réel terminé à titre d'essai avant le
    // lot) : les rangs 4 à 18 remplissent encore la voie `discover`…
    Config::set('catalog.curation.pilot.first_rank.discover', 4);

    expect(ThroughputReport::measure()->verdict())->toBeInstanceOf(PilotVerdict::class);

    // … mais plus à partir du rang 5 : quatorze films seulement.
    Config::set('catalog.curation.pilot.first_rank.discover', 5);

    $report = ThroughputReport::measure();

    expect($report->verdict())->toBeNull()
        ->and($report->pilotProgress()[CurationQueue::ENTRY_DISCOVER]['terminated'])->toBe(14);
});

test('le verdict du pilote ne change pas quand un film du pilote est enrichi en passe 2, recadré ou suspendu', function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    Queue::fake();

    $at = $this->start;
    $films = [];

    foreach ([CurationQueue::ENTRY_DISCOVER => 15, CurationQueue::ENTRY_EXCEPTION => 5] as $entry => $count) {
        foreach (range(1, $count) as $rank) {
            $at = $at->addMinute();
            $films[] = throughputFilm($this->curator, $at, $entry, active: 600 + $rank, crops: [20 + $rank, 40]);
        }
    }

    // Une image de la passe 1 sans temps de recadrage, rendue et prête à
    // recadrer : créée avant la terminaison de son film.
    $first = $films[0];
    $unmeasured = Frame::factory()->for($first)->withFiles()->create([
        'crop_seconds' => null,
        'created_at' => $this->start,
    ]);

    $before = ThroughputReport::measure();
    $verdict = $before->verdict()?->toArray();
    $measures = $before->toArray()['pilot'];

    expect($verdict)->not->toBeNull();

    // Passe 2 : une variante de plus, recadrée en 999 s, APRÈS la
    // terminaison.
    Frame::factory()->for($first)->create(['crop_seconds' => 999, 'created_at' => $at->addDay()]);

    // Re-recadrage : la passe 1 est close, `crop_seconds` n'est pas écrit.
    $crop = FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(1920, 1080), PlatformLimits::current());

    app(RecropFrame::class)->handle(
        $unmeasured,
        $this->curator,
        new CropRect($crop->x - FrameGeometry::ASPECT_WIDTH, $crop->y - FrameGeometry::ASPECT_HEIGHT, $crop->width, $crop->height),
        'Cadre resserré en passe 2.',
        777,
    );

    expect($unmeasured->refresh()->crop_seconds)->toBeNull();

    // Des battements sur un film publié : rien ne s'ajoute.
    $heartbeat = app(RecordCurationHeartbeat::class);
    $heartbeat->handle($this->curator, $films[1], $at->addDay());
    $heartbeat->handle($this->curator, $films[1], $at->addDay()->addSeconds(15));

    // Suspension (geste administrateur du J2) : état et ligne du journal.
    $admin = User::factory()->admin()->create();
    $films[2]->forceFill([
        'availability' => ContentAvailability::Suspended,
        'availability_changed_at' => $at->addDays(2),
        'availability_reason' => 'Signalement en cours d’instruction.',
    ])->save();
    throughputJournal($admin, AdminActionType::MovieSuspended, $films[2], $at->addDays(2));

    // Dépublication puis republication d'un film du pilote.
    throughputJournal($this->curator, AdminActionType::MovieUnpublished, $films[3], $at->addDays(3));
    throughputJournal($this->curator, AdminActionType::MovieRepublished, $films[3], $at->addDays(4));

    // Un film terminé après la fenêtre n'y entre pas.
    throughputFilm($this->curator, $at->addDays(5), active: 99_999, crops: [500]);

    $after = ThroughputReport::measure();

    expect($after->verdict()?->toArray())->toBe($verdict)
        ->and($after->toArray()['pilot'])->toBe($measures);
});

test('sans heures déclarées, la projection en semaines affiche un message et jamais un quotient', function (): void {
    expect(Config::integer('catalog.curation.pilot.weekly_curation_hours'))->toBe(0)
        ->and(Config::integer('catalog.curation.pilot.horizon_weeks'))->toBe(0)
        ->and(Lang::hasForLocale('admin.throughput.hours_undeclared', Locale::French->value))->toBeTrue();

    throughputPilot(
        $this->curator,
        $this->start,
        throughputQuota(CurationQueue::ENTRY_DISCOVER, 1200),
        throughputQuota(CurationQueue::ENTRY_EXCEPTION, 1200),
    );

    // L'écran reçoit des heures, mais aucune semaine et aucune cible de
    // volume : il affiche `admin.throughput.hours_undeclared` à leur place.
    $this->actingAs($this->curator)
        ->get(route('admin.throughput.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/throughput')
            ->where('report.verdict.weekly_hours_declared', false)
            ->where('report.verdict.declared_hours', 0)
            ->where('report.verdict.j1.seconds', 60 * 1200)
            ->where('report.verdict.j1.weeks', null)
            ->where('report.verdict.volume.films', null)
            ->where('report.verdict.volume.weeks', null)
            ->where('report.all.projection.j1.weeks', null)
            ->where('report.all.projection.weekly_hours_declared', false));

    // Des heures hebdomadaires sans horizon : les semaines se calculent, la
    // cible de volume attend toujours les heures déclarées.
    Config::set('catalog.curation.pilot.weekly_curation_hours', 10);

    $verdict = throughputVerdict()->toArray();

    expect($verdict['j1']['weeks'])->toBe((float) (60 * 1200 / PilotSettings::SECONDS_PER_HOUR / 10))
        ->and($verdict['volume']['films'])->toBeNull();

    // Et l'horizon déclaré fait naître la cible : 10 h × 12 semaines ÷ 20 min.
    Config::set('catalog.curation.pilot.horizon_weeks', 12);

    $verdict = throughputVerdict()->toArray();

    expect($verdict['volume']['films'])->toBe(360)
        ->and($verdict['volume']['weeks'])->toBe((float) (360 * 1200 / PilotSettings::SECONDS_PER_HOUR / 10));
});
