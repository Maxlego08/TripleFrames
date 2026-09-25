<?php

use App\Actions\Curation\SaveMovieTitle;
use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\ContentOrigin;
use App\Enums\ImportRunKind;
use App\Enums\Locale;
use App\Models\AnswerKey;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\User;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Catalog\ImportDecision;
use App\Support\Catalog\MovieImporter;
use App\Support\Tmdb\TmdbMovie;
use App\ValueObjects\Catalog\ImportFilter;
use Database\Factories\MovieFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Tests\Fixtures\TmdbFixture;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Titres d'un film — spec 20 § 9.1, spec 10 § 3.4 et § 9.3
|--------------------------------------------------------------------------
|
| Un titre par locale ACTIVÉE, corrigé sur la fiche en `origin = curator` :
| c'est ce qui le protège de toute resynchronisation, là où la tâche
| quotidienne du curateur serait sinon écrasée en silence. La correction
| reprojette `title_locale_mask` et `answer_key` — titre, préfixe,
| sous-titre, ambiguïté — dans la MÊME transaction. Aucun titre n'est jamais
| recopié d'une langue à l'autre : l'absence d'une ligne est l'information.
| Seule une ligne `curator` se retire ; une ligne `tmdb` se corrige.
|
*/

beforeEach(function (): void {
    // Aucune écriture de titre ne parle à TMDB : la resynchronisation de ce
    // fichier se nourrit de fixtures.
    Http::preventStrayRequests();

    $this->curator = User::factory()->curator()->create();
});

/**
 * Un film au titre original donné, ses titres par locale, ses clés et sa
 * projection recalculées. `$published` : le film publié — ses formes pèsent
 * dans le recompte d'ambiguïté, qui ne lit que la disponibilité du film.
 *
 * @param  array<string, array{string, ContentOrigin}>  $titles  locale → (titre, origine)
 */
function movieTitleFilm(string $original, array $titles = [], bool $published = false): Movie
{
    $movie = Movie::factory()->create([
        'title_original' => $original,
        'title_original_latin' => null,
        'availability' => $published ? ContentAvailability::Published : ContentAvailability::Draft,
        'first_published_at' => $published ? now() : null,
    ]);

    foreach ($titles as $locale => [$title, $origin]) {
        MovieTitle::factory()->create([
            'movie_id' => $movie->id,
            'locale' => $locale,
            'title' => $title,
            'origin' => $origin,
        ]);
    }

    (new AnswerKeyProjector)->project($movie);
    MovieFactory::recomputeProjection($movie);

    return $movie->refresh();
}

/**
 * La correction d'un titre, postée depuis la fiche du film.
 */
function movieTitlePut(Movie $movie, Locale|string $locale, ?string $title): TestResponse
{
    return test()
        ->actingAs(test()->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->put(route('admin.catalog.titles.update', [
            'movie' => $movie->id,
            'locale' => $locale instanceof Locale ? $locale->value : $locale,
        ]), ['title' => $title]);
}

/**
 * Le retrait d'un titre, posté depuis la fiche du film.
 */
function movieTitleDelete(Movie $movie, Locale $locale): TestResponse
{
    return test()
        ->actingAs(test()->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->delete(route('admin.catalog.titles.destroy', ['movie' => $movie->id, 'locale' => $locale->value]));
}

/**
 * Les clés du film, forme normalisée → nature.
 *
 * @return array<string, string>
 */
function movieTitleKeys(Movie $movie): array
{
    $keys = [];

    foreach (AnswerKey::query()->where('movie_id', $movie->id)->get() as $key) {
        $keys[(string) $key->normalized] = $key->key_kind->value;
    }

    ksort($keys);

    return $keys;
}

/**
 * Les titres du film, locale → titre.
 *
 * @return array<string, string>
 */
function movieTitleRows(Movie $movie): array
{
    /** @var array<string, string> $rows */
    $rows = MovieTitle::query()
        ->where('movie_id', $movie->id)
        ->orderBy('locale')
        ->pluck('title', 'locale')
        ->all();

    return $rows;
}

/**
 * Le rechargement partiel qui ouvre la confirmation d'un titre ou d'un alias
 * saisi : la fiche ne rend que `text_preview`.
 */
function movieTitlePreview(Movie $movie, string $text, string $target): TestResponse
{
    $page = test()->actingAs(test()->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->viewData('page');

    return test()
        ->actingAs(test()->curator)
        ->get(route('admin.catalog.show', [
            'movie' => $movie->id,
            'preview_text' => $text,
            'preview_target' => $target,
        ]), [
            Header::INERTIA => 'true',
            Header::VERSION => (string) $page['version'],
            Header::PARTIAL_COMPONENT => 'admin/catalog/show',
            Header::PARTIAL_ONLY => 'text_preview',
        ]);
}

/**
 * Un texte du back-office, résolu en français, sa clé vérifiée d'abord.
 *
 * @param  array<string, int|string>  $replace
 */
function movieTitleText(string $key, array $replace = []): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, $replace, Locale::French->value);
}

test('corriger un titre l\'écrit en origin curator et reprojette answer_key dans la même transaction', function (): void {
    // Un film publié, dont le préfixe entrera en collision avec celui que le
    // titre corrigé dérive.
    $saga = movieTitleFilm('Vent d’Écume : Le Premier Rivage', published: true);

    $movie = movieTitleFilm('Glasgarten', [
        Locale::French->value => ['Le Jardin de verre', ContentOrigin::Tmdb],
    ], published: true);

    $corrected = 'Vent d’Écume : Le Dernier Rivage';

    // L'aperçu précède la confirmation, sur un film publié (§ 9.1, § 8.2) :
    // la forme exacte, et le préfixe que le titre rendrait ambigu, avec le
    // film qui le porte déjà. Il n'écrit rien.
    movieTitlePreview($movie, $corrected, 'title')
        ->assertOk()
        ->assertJsonPath('props.text_preview.form', AnswerKeyNormalizer::normalize($corrected))
        ->assertJsonPath('props.text_preview.accepted_as', null)
        ->assertJsonPath('props.text_preview.ambiguity.0.form', 'vent d ecume')
        ->assertJsonPath('props.text_preview.ambiguity.0.kinds', [AnswerKeyKind::Prefix->value])
        ->assertJsonPath('props.text_preview.ambiguity.0.movies.0.id', $saga->id);

    expect(MovieTitle::query()->where('movie_id', $movie->id)->value('title'))->toBe('Le Jardin de verre');

    movieTitlePut($movie, Locale::French, '  '.$corrected.'  ')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertiaFlash('toast.message', movieTitleText('admin.movie.titles.flash.saved'));

    /** @var MovieTitle $row */
    $row = MovieTitle::query()->where('movie_id', $movie->id)->where('locale', Locale::French->value)->sole();

    // La même ligne, réécrite, rognée, en correction de curateur signée.
    expect($row->title)->toBe($corrected)
        ->and($row->origin)->toBe(ContentOrigin::Curator)
        ->and($row->edited_by_id)->toBe($this->curator->id);

    // Les clés, par différence : l'ancien titre sort, le nouveau entre avec
    // son préfixe et son sous-titre — et le préfixe partagé avec un autre
    // film publié est aussitôt ambigu, des deux côtés.
    $keys = movieTitleKeys($movie);

    expect($keys)->not->toHaveKey(AnswerKeyNormalizer::normalize('Le Jardin de verre'))
        ->and($keys[AnswerKeyNormalizer::normalize($corrected)])->toBe(AnswerKeyKind::Title->value)
        ->and($keys['vent d ecume'])->toBe(AnswerKeyKind::Prefix->value)
        ->and($keys['dernier rivage'])->toBe(AnswerKeyKind::Subtitle->value);

    foreach ([$movie, $saga] as $carrier) {
        expect(AnswerKey::query()->where('movie_id', $carrier->id)->where('normalized', 'vent d ecume')->sole()->is_ambiguous)
            ->toBeTrue();
    }

    // Le masque des titres tient la locale.
    expect(FrameBank::projection($movie)->title_locale_mask & Locale::French->maskBit())->toBe(Locale::French->maskBit());

    // Corriger un titre REMPLACE, il n'ajoute rien : même quand la forme
    // saisie est déjà la sienne, l'aperçu ne l'annonce jamais « déjà
    // acceptée » — cet avertissement est celui d'un alias (§ 9.2).
    movieTitlePreview($movie, mb_strtoupper($corrected), 'title')
        ->assertJsonPath('props.text_preview.form', AnswerKeyNormalizer::normalize($corrected))
        ->assertJsonPath('props.text_preview.accepted_as', null)
        ->assertJsonPath('props.text_preview.promoted_from', null);

    // Même transaction : une panne pendant la reprojection des clés annule la
    // correction elle-même.
    $other = movieTitleFilm('Nachtzug', [
        Locale::French->value => ['Le Train de nuit', ContentOrigin::Tmdb],
    ]);
    $before = movieTitleKeys($other);

    Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
        if (preg_match('/^\s*insert\s+into\s+["`]?answer_key["`]?/i', $query->sql) === 1) {
            throw new RuntimeException('Panne simulée de la reprojection.');
        }
    });

    expect(fn () => app(SaveMovieTitle::class)->handle($other, $this->curator, Locale::French, 'Le Dernier Train'))
        ->toThrow(RuntimeException::class, 'Panne simulée');

    /** @var MovieTitle $untouched */
    $untouched = MovieTitle::query()->where('movie_id', $other->id)->sole();

    expect($untouched->title)->toBe('Le Train de nuit')
        ->and($untouched->origin)->toBe(ContentOrigin::Tmdb)
        ->and($untouched->edited_by_id)->toBeNull()
        ->and(movieTitleKeys($other))->toBe($before);
});

test('une ligne curator survit à la resynchronisation', function (): void {
    $fixture = static fn (string $name): TmdbMovie => TmdbMovie::fromArray(TmdbFixture::array($name));
    $run = static fn (ImportRunKind $kind): ImportRun => ImportRun::factory()->create([
        'run_kind' => $kind,
        'total_seen' => 0,
        'total_imported' => 0,
        'total_skipped' => 0,
        'total_refused_content' => 0,
    ]);

    app(MovieImporter::class)->import($fixture('movie-987654'), $run(ImportRunKind::Paste), ImportFilter::default());

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    expect(movieTitleRows($movie))->toBe([
        Locale::English->value => 'The Glass Orchard',
        Locale::French->value => 'Le Verger de verre',
    ]);

    // La correction du curateur, par l'écran.
    movieTitlePut($movie, Locale::French, 'Le Verger aux vitres')->assertSessionHasNoErrors();

    $outcome = app(MovieImporter::class)->import(
        $fixture('movie-987654-resynced'),
        $run(ImportRunKind::Resync),
        ImportFilter::default(),
    );

    expect($outcome->decision)->toBe(ImportDecision::Resynchronized);

    // La ligne `curator` n'est ni réécrite ni doublée ; la ligne `tmdb`, elle,
    // est relue.
    expect(movieTitleRows($movie))->toBe([
        Locale::English->value => 'The Glass Orchard Remastered',
        Locale::French->value => 'Le Verger aux vitres',
    ]);

    /** @var MovieTitle $french */
    $french = MovieTitle::query()->where('movie_id', $movie->id)->where('locale', Locale::French->value)->sole();

    expect($french->origin)->toBe(ContentOrigin::Curator)
        ->and($french->edited_by_id)->toBe($this->curator->id);

    // Et la reprojection de la resynchronisation garde la forme curée, sans
    // la traduction TMDB qu'elle aurait remplacée.
    $keys = movieTitleKeys($movie);

    expect($keys)->toHaveKey(AnswerKeyNormalizer::normalize('Le Verger aux vitres'))
        ->and($keys)->not->toHaveKey(AnswerKeyNormalizer::normalize('Le Verger de verre remasterisé'))
        ->and($keys)->toHaveKey(AnswerKeyNormalizer::normalize('The Glass Orchard Remastered'));
});

test('aucun titre n\'est recopié d\'une langue à l\'autre', function (): void {
    $movie = movieTitleFilm('Glasgarten', [
        Locale::English->value => ['The Glass Garden', ContentOrigin::Tmdb],
    ]);

    // Saisir le titre français n'écrit QUE la ligne française.
    movieTitlePut($movie, Locale::French, 'Le Jardin de verre')->assertSessionHasNoErrors();

    expect(movieTitleRows($movie))->toBe([
        Locale::English->value => 'The Glass Garden',
        Locale::French->value => 'Le Jardin de verre',
    ]);

    // Retirer le titre français ne le remplace par rien : la locale redevient
    // absente, et le masque le dit.
    movieTitleDelete($movie, Locale::French)->assertSessionHasNoErrors();

    expect(movieTitleRows($movie))->toBe([Locale::English->value => 'The Glass Garden'])
        ->and(FrameBank::projection($movie)->title_locale_mask)->toBe(Locale::English->maskBit());

    $titleKeys = AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->where('key_kind', AnswerKeyKind::Title->value)
        ->pluck('source_locale')
        ->all();

    expect($titleKeys)->toBe([Locale::English->value]);

    // La fiche dit l'absence telle quelle, par locale activée, lue dans le
    // masque de la projection (§ 9.3).
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn ($page) => $page
            ->where('title_locales', [
                ['locale' => Locale::English->value, 'covered' => true],
                ['locale' => Locale::French->value, 'covered' => false],
            ])
            ->has('titles', 1));

    // Un film sans aucun titre localisé : corriger l'anglais n'invente pas
    // le français.
    $bare = movieTitleFilm('Sillage');

    movieTitlePut($bare, Locale::English, 'Wake')->assertSessionHasNoErrors();

    expect(movieTitleRows($bare))->toBe([Locale::English->value => 'Wake']);

    // Une locale de catalogue non activée n'est pas éditable : ses lignes
    // restent en lecture seule, et rien n'y est écrit.
    movieTitlePut($bare, 'ja', '航跡')->assertNotFound();

    expect(movieTitleRows($bare))->toBe([Locale::English->value => 'Wake']);

    // Un titre vide est refusé sous le champ, sans rien écrire.
    movieTitlePut($bare, Locale::English, '   ')->assertSessionHasErrors('title');

    expect(movieTitleRows($bare))->toBe([Locale::English->value => 'Wake']);
});

test('seule une ligne curator se retire', function (): void {
    $movie = movieTitleFilm('Glasgarten', [
        Locale::English->value => ['The Glass Garden', ContentOrigin::Tmdb],
        Locale::French->value => ['Le Jardin de verre', ContentOrigin::Curator],
    ]);

    // Une ligne TMDB se corrige, elle ne se retire pas : refus traduit, rien
    // n'est écrit.
    movieTitleDelete($movie, Locale::English)
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasErrors(['title' => movieTitleText('admin.movie.titles.destroy.not_curator')]);

    expect(movieTitleRows($movie))->toHaveKey(Locale::English->value)
        ->and(movieTitleKeys($movie))->toHaveKey(AnswerKeyNormalizer::normalize('The Glass Garden'));

    // La correction de curateur, elle, se retire — et sa clé avec elle.
    movieTitleDelete($movie, Locale::French)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', movieTitleText('admin.movie.titles.flash.removed'));

    expect(movieTitleRows($movie))->toBe([Locale::English->value => 'The Glass Garden'])
        ->and(movieTitleKeys($movie))->not->toHaveKey(AnswerKeyNormalizer::normalize('Le Jardin de verre'));

    // Plus rien à retirer : refus traduit.
    movieTitleDelete($movie, Locale::French)
        ->assertSessionHasErrors(['title' => movieTitleText('admin.movie.titles.destroy.missing')]);
});
