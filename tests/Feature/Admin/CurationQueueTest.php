<?php

use App\Actions\Curation\AddFrame;
use App\Actions\Curation\RecropFrame;
use App\Actions\Curation\ReviewFrame;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameLevel;
use App\Enums\Locale;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Curation\CurationQueue;
use App\Support\Curation\ExclusionGrid;
use App\Support\Curation\ReviewQueue;
use App\Support\Frames\CropRect;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Frames\SourceImages;

/*
|--------------------------------------------------------------------------
| La file de curation et « film suivant » — spec 20 § 4.1, lot L20-15
|--------------------------------------------------------------------------
|
| Périmètre : brouillons hors démonstration. Ordre : les films entamés
| d'abord — au moins une image non écartée —, du plus récemment touché au
| plus ancien, puis les autres par votes décroissants. « Touché » se lit sur
| `frame.updated_at` : chaque geste sur une image la réécrit, et c'est ce qui
| remonte le film, sans aucun `$touches`. Les tests jouent donc les VRAIS
| gestes — ajout, re-recadrage, revue —, jamais une date posée à la main.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    // Les images de fixture écrivent leurs octets : sur un disque faux, jamais
    // dans la racine réelle de `frames`.
    Storage::fake(FrameStoragePrefix::DISK);

    // Ajouter et recadrer distribuent un traitement : il ne part pas.
    Queue::fake();

    $this->curator = User::factory()->curator()->create();
});

/**
 * Un texte du back-office, résolu en français — sa clé vérifiée d'abord : une
 * clé absente se rendrait telle quelle des deux côtés d'une comparaison.
 */
function curationQueueText(string $key): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, [], Locale::French->value);
}

/**
 * Les identifiants de la file, dans son ordre.
 *
 * @return list<int>
 */
function curationQueueIds(?CurationQueue $queue = null): array
{
    return array_values(($queue ?? new CurationQueue)->query()->pluck('movie.id')->map(fn (mixed $id): int => (int) $id)->all());
}

/**
 * Un brouillon « entamé » : une image prête, jamais revue.
 *
 * @param  array<string, mixed>  $attributes
 */
function curationQueueStarted(array $attributes = []): Movie
{
    $movie = Movie::factory()->create($attributes);

    Frame::factory()->for($movie)->level(FrameLevel::Level3)->withFiles()->create();

    return $movie;
}

test('la file montre d\'abord les films entamés puis les autres par votes décroissants', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00'));
    $olderStarted = curationQueueStarted(['vote_count' => 100]);

    $this->travelTo(CarbonImmutable::parse('2026-09-21 10:00:00'));
    $recentStarted = curationQueueStarted(['vote_count' => 50]);

    $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00:00'));
    $lowVotes = Movie::factory()->create(['vote_count' => 9_000]);
    $highVotes = Movie::factory()->create(['vote_count' => 20_000]);

    // Des votes égaux se départagent par identifiant croissant.
    $tieFirst = Movie::factory()->create(['vote_count' => 5_000]);
    $tieSecond = Movie::factory()->create(['vote_count' => 5_000]);

    // Une image ÉCARTÉE — `unpublished` jamais publiée — n'entame pas un
    // film : il reste rangé par ses votes, ici les plus hauts.
    $onlySetAsideFrame = Movie::factory()->create(['vote_count' => 30_000]);
    Frame::factory()->for($onlySetAsideFrame)->withFiles()->create([
        'availability' => ContentAvailability::Unpublished,
        'availability_changed_at' => CarbonImmutable::now(),
        'first_published_at' => null,
    ]);

    // Hors périmètre : publié, écarté, suspendu.
    Movie::factory()->published()->create(['vote_count' => 90_000]);
    Movie::factory()->create([
        'vote_count' => 90_000,
        'availability' => ContentAvailability::Unpublished,
        'availability_reason' => 'Aucun visuel TMDB exploitable',
    ]);
    Movie::factory()->suspended()->create(['vote_count' => 90_000]);

    $expected = [
        $recentStarted->id,
        $olderStarted->id,
        $onlySetAsideFrame->id,
        $highVotes->id,
        $lowVotes->id,
        $tieFirst->id,
        $tieSecond->id,
    ];

    expect(curationQueueIds())->toBe($expected);

    $this->actingAs($this->curator)
        ->get(route('admin.curation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/curation/index', false)
            ->has('movies.data', count($expected))
            ->where('movies.meta.total', count($expected))
            ->missing('movies.links')
            ->where('movies.data.0.id', $recentStarted->id)
            ->where('movies.data.0.rank', 1)
            ->where('movies.data.0.is_started', true)
            ->whereType('movies.data.0.touched_at', 'string')
            ->where('movies.data.2.id', $onlySetAsideFrame->id)
            ->where('movies.data.2.is_started', false)
            ->where('movies.data.2.touched_at', null)
            ->where('movies.data.6.rank', 7));

    $this->travelBack();
});

test('ajouter, recadrer ou revoir une image remonte son film dans la file', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00'));
    $forAdd = curationQueueStarted();

    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:01:00'));
    $forRecrop = Movie::factory()->create();
    $recropped = Frame::factory()->for($forRecrop)->level(FrameLevel::Level3)->withFiles()->create();

    $this->travelTo(CarbonImmutable::parse('2026-09-20 10:02:00'));
    $forReview = Movie::factory()->create();
    $reviewed = Frame::factory()->for($forReview)->level(FrameLevel::Level1)->withFiles()->create();

    // Le plus récemment touché d'abord.
    expect(curationQueueIds())->toBe([$forReview->id, $forRecrop->id, $forAdd->id]);

    // 1. Ajouter une variante depuis TMDB.
    $this->travelTo(CarbonImmutable::parse('2026-09-20 11:00:00'));
    $crop = FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(1920, 1080), PlatformLimits::current());

    app(AddFrame::class)->fromTmdb(
        $forAdd,
        $this->curator,
        FrameLevel::Level5,
        $crop,
        '/'.bin2hex(random_bytes(16)).'.jpg',
        SourceImages::jpeg(1920, 1080),
        null,
    );

    expect(curationQueueIds()[0])->toBe($forAdd->id);

    // 2. Re-recadrer une image : un cadre plus serré, calé en haut à gauche.
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    $width = $crop->width - 8 * FrameGeometry::ASPECT_WIDTH;

    app(RecropFrame::class)->handle(
        $recropped,
        $this->curator,
        new CropRect(0, 0, $width, intdiv($width * FrameGeometry::ASPECT_HEIGHT, FrameGeometry::ASPECT_WIDTH)),
        null,
        null,
    );

    expect(curationQueueIds()[0])->toBe($forRecrop->id);

    // 3. Revoir une image : la revue passante la publie.
    $this->travelTo(CarbonImmutable::parse('2026-09-20 13:00:00'));
    $reviewed->refresh();

    app(ReviewFrame::class)->handle(
        $reviewed,
        $this->curator,
        ExclusionGrid::CURRENT_VERSION,
        (string) $reviewed->published_hash,
        array_fill_keys(ExclusionGrid::slugsFor($reviewed->frame_level), true),
        ReviewQueue::declaredSource($reviewed)['reference'],
    );

    expect(curationQueueIds())->toBe([$forReview->id, $forRecrop->id, $forAdd->id]);

    $this->travelBack();
});

test('la file exclut le catalogue de démonstration', function (): void {
    $demo = Movie::factory()->demo()->create(['vote_count' => 99_000]);
    Frame::factory()->for($demo)->withFiles()->create();

    $real = Movie::factory()->create(['vote_count' => 10]);

    expect(curationQueueIds())->toBe([$real->id])
        ->and(CurationQueue::totalsByEntry())->toBe(['discover' => 1, 'exception' => 0]);

    $this->actingAs($this->curator)
        ->get(route('admin.curation.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movies.data', 1)
            ->where('movies.data.0.id', $real->id));

    // « Film suivant » ne mène jamais à un film de démonstration.
    $this->actingAs($this->curator)
        ->get(route('admin.curation.next', ['current' => $real->id]))
        ->assertRedirect(route('admin.curation.index'));
});

test('film suivant mène au premier film de la file autre que le courant', function (): void {
    $first = Movie::factory()->create(['vote_count' => 3_000]);
    $second = Movie::factory()->create(['vote_count' => 2_000]);
    Movie::factory()->create(['vote_count' => 1_000]);

    // Sans film courant : le premier de la file.
    $this->actingAs($this->curator)
        ->get(route('admin.curation.next'))
        ->assertRedirect(route('admin.catalog.bank', ['movie' => $first->id]));

    // Depuis le premier : le suivant, jamais lui-même.
    $this->actingAs($this->curator)
        ->get(route('admin.curation.next', ['current' => $first->id]))
        ->assertRedirect(route('admin.catalog.bank', ['movie' => $second->id]));

    // Depuis un film hors de la file — publié à l'instant — : la tête.
    $published = Movie::factory()->published()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.curation.next', ['current' => $published->id]))
        ->assertRedirect(route('admin.catalog.bank', ['movie' => $first->id]));

    // Un film courant illisible est refusé, jamais ignoré en silence.
    $this->actingAs($this->curator)
        ->get(route('admin.curation.next', ['current' => 'premier']))
        ->assertSessionHasErrors('current');
});

test('film suivant sur une file vide ramène à la file avec un message traduit', function (): void {
    // Une file vide…
    $this->actingAs($this->curator)
        ->get(route('admin.curation.next'))
        ->assertRedirect(route('admin.curation.index'))
        ->assertInertiaFlash('toast.message', curationQueueText('admin.curation.empty'));

    // … et une file dont le seul film est le courant.
    $only = Movie::factory()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.curation.next', ['current' => $only->id]))
        ->assertRedirect(route('admin.curation.index'))
        ->assertInertiaFlash('toast.message', curationQueueText('admin.curation.empty'));

    // La file vide se rend, et l'écran affiche le même message.
    Movie::query()->whereKey($only->id)->update(['availability' => ContentAvailability::Published->value]);

    $this->actingAs($this->curator)
        ->get(route('admin.curation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/curation/index', false)
            ->has('movies.data', 0)
            ->where('movies.meta.total', 0));
});

test('les filtres par voie servent la composition du pilote', function (): void {
    $discoverHigh = Movie::factory()->create(['vote_count' => 8_000]);
    $discoverLow = Movie::factory()->create(['vote_count' => 700]);
    $language = Movie::factory()->exceptionForLanguage()->create(['vote_count' => 6_000]);
    $releaseYear = Movie::factory()->exceptionForReleaseYear()->contentFlag(ContentFlag::Blocked)->create(['vote_count' => 5_000]);
    // Collé sans aucun motif : entré par exception quand même.
    $pasted = Movie::factory()->importException()->create(['vote_count' => 4_000]);

    // Le reste à curer par voie : toute la file, filtres ignorés.
    $this->actingAs($this->curator)
        ->get(route('admin.curation.index', ['entry' => 'discover']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.entry', 'discover')
            ->where('totals', ['discover' => 2, 'exception' => 3])
            ->where('options.entry', ['discover', 'exception'])
            ->has('movies.data', 2)
            ->where('movies.data.0.id', $discoverHigh->id)
            ->where('movies.data.1.id', $discoverLow->id));

    // La strate d'exception, dans l'ordre de la file.
    expect(curationQueueIds(new CurationQueue(entry: 'exception')))
        ->toBe([$language->id, $releaseYear->id, $pasted->id])
        // Un motif restreint à sa strate.
        ->and(curationQueueIds(new CurationQueue(motive: 'language')))->toBe([$language->id])
        ->and(curationQueueIds(new CurationQueue(entry: 'discover', motive: 'language')))->toBe([])
        // Le drapeau de contenu.
        ->and(curationQueueIds(new CurationQueue(contentFlag: ContentFlag::Blocked)))->toBe([$releaseYear->id]);

    // « Film suivant » reste dans la strate, et la dépose sur l'URL de
    // l'éditeur pour le film d'après.
    $this->actingAs($this->curator)
        ->get(route('admin.curation.next', ['entry' => 'exception']))
        ->assertRedirect(route('admin.catalog.bank', ['movie' => $language->id, 'entry' => 'exception']));

    $this->actingAs($this->curator)
        ->get(route('admin.curation.next', ['entry' => 'exception', 'current' => $language->id]))
        ->assertRedirect(route('admin.catalog.bank', ['movie' => $releaseYear->id, 'entry' => 'exception']));

    // Strate épuisée : retour à la file, filtre conservé.
    $this->actingAs($this->curator)
        ->get(route('admin.curation.next', ['motive' => 'language', 'current' => $language->id]))
        ->assertRedirect(route('admin.curation.index', ['motive' => 'language']))
        // D'autres films attendent hors de la strate : le message invite à
        // effacer les filtres, jamais à importer.
        ->assertInertiaFlash('toast.message', curationQueueText('admin.curation.empty_stratum'));

    // Une voie hors liste blanche est refusée.
    $this->actingAs($this->curator)
        ->get(route('admin.curation.index', ['entry' => 'paste']))
        ->assertSessionHasErrors('entry');
});
