<?php

use App\Actions\Curation\PublishMovie;
use App\Actions\Curation\UnpublishMovie;
use App\Enums\AdminActionType;
use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\AdminAction;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Models\User;
use App\Support\Catalog\AmbiguityPreview;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Catalog\TextTarget;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| L'avertissement nominatif d'ambiguïté — spec 20 § 8.2, décision 13, E10-09
|--------------------------------------------------------------------------
|
| Avant de confirmer une publication, le curateur lit les préfixes et les
| sous-titres que son geste rendra ambigus, et à quels films ils
| appartenaient. L'aperçu est en LECTURE SEULE (B5, invariant L2) ; son
| empreinte repart avec la publication, qui la recalcule sous verrou : un
| catalogue changé entre-temps fait refuser le geste, jamais publier sous un
| avertissement périmé. L'ambiguïté se mesure sur le catalogue publié
| ENTIER, jamais sur un vivier.
|
*/

beforeEach(function (): void {
    // Les images publiées de fixture écrivent leurs octets : sur un disque faux.
    Storage::fake(FrameStoragePrefix::DISK);
    Queue::fake();

    $this->curator = User::factory()->curator()->create();
});

/**
 * Un film au titre original donné, ses clés projetées. `$publishable` : le
 * contenu vérifié et une image publiée à chacun des niveaux 1, 3 et 5.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<string>  $aliases  alias français, projetés avec les titres
 */
function ambiguityFilm(
    string $title,
    ContentAvailability $availability = ContentAvailability::Draft,
    bool $publishable = false,
    array $attributes = [],
    array $aliases = [],
): Movie {
    $factory = $publishable
        ? Movie::factory()->withCertification()->contentFlag(ContentFlag::Clear)
        : Movie::factory();

    $movie = $factory->create([
        'title_original' => $title,
        'title_original_latin' => null,
        'availability' => $availability,
        'first_published_at' => $availability === ContentAvailability::Draft ? null : now(),
        ...$attributes,
    ]);

    foreach ($aliases as $alias) {
        Alias::factory()->create([
            'movie_id' => $movie->id,
            'locale' => Locale::French->value,
            'alias' => $alias,
            'origin' => ContentOrigin::Curator,
        ]);
    }

    if ($publishable) {
        FrameBank::movieWith($movie, [1, 3, 5]);
    }

    (new AnswerKeyProjector)->project($movie);

    return $movie->refresh();
}

/**
 * La ligne attendue d'un film porteur, telle que l'aperçu la rend.
 *
 * @return array{id: int, title_original: string, release_year: int|null, kind: string}
 */
function ambiguityCarrier(Movie $movie, AnswerKeyKind $kind): array
{
    return [
        'id' => $movie->id,
        'title_original' => $movie->title_original,
        'release_year' => $movie->release_year,
        'kind' => $kind->value,
    ];
}

/**
 * Un rechargement partiel de la fiche qui ne demande que l'aperçu — ce que
 * fait la confirmation de publication à son ouverture.
 */
function ambiguityPartial(Movie $movie): TestResponse
{
    $page = test()->actingAs(test()->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->viewData('page');

    return test()
        ->actingAs(test()->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]), [
            Header::INERTIA => 'true',
            Header::VERSION => (string) $page['version'],
            Header::PARTIAL_COMPONENT => 'admin/catalog/show',
            Header::PARTIAL_ONLY => 'publication_preview',
        ]);
}

/**
 * Un texte du back-office, résolu en français, sa clé vérifiée d'abord.
 *
 * @param  array<string, int|string>  $replace
 */
function ambiguityText(string $key, array $replace = []): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, $replace, Locale::French->value);
}

test('l\'aperçu nomme les préfixes et sous-titres que la publication rend ambigus et leurs films', function (): void {
    // (a) le préfixe du brouillon est porté par un film publié, sous le même
    // nom de nature ; son sous-titre, par le TITRE d'un autre film publié.
    $saga = ambiguityFilm('Vent d’Écume : Le Premier Rivage', ContentAvailability::Published, attributes: ['release_year' => 2001]);
    $tide = ambiguityFilm('La Marée Haute', ContentAvailability::Published, attributes: ['release_year' => 1998]);

    // (b) le brouillon porte, en ALIAS, le préfixe d'un autre film publié :
    // ce préfixe cessera d'être accepté.
    $mist = ambiguityFilm('Brume Lointaine : Retour', ContentAvailability::Published, attributes: ['release_year' => null]);

    // Un titre exact partagé des deux côtés n'est pas une ambiguïté : un
    // titre complet est toujours accepté.
    $twin = ambiguityFilm('Récif Oublié', ContentAvailability::Published);

    $draft = ambiguityFilm(
        'Vent d’Écume : La Marée Haute',
        publishable: true,
        aliases: ['Brume lointaine', 'Récif oublié'],
    );

    $report = app(AmbiguityPreview::class)->forPublication($draft);

    // Triées par forme ; pour chaque forme, sa nature pour CE film, et les
    // films publiés qui la portent avec la leur.
    expect($report->lines)->toBe([
        [
            'form' => 'brume lointaine',
            'kinds' => [AnswerKeyKind::Alias->value],
            'movies' => [ambiguityCarrier($mist, AnswerKeyKind::Prefix)],
        ],
        [
            'form' => 'maree haute',
            'kinds' => [AnswerKeyKind::Subtitle->value],
            'movies' => [ambiguityCarrier($tide, AnswerKeyKind::TitleOriginal)],
        ],
        [
            'form' => 'vent d ecume',
            'kinds' => [AnswerKeyKind::Prefix->value],
            'movies' => [ambiguityCarrier($saga, AnswerKeyKind::Prefix)],
        ],
    ]);

    expect(collect($report->lines)->pluck('form'))->not->toContain('recif oublie')
        ->and($twin->availability)->toBe(ContentAvailability::Published);

    // Servi à la confirmation par rechargement partiel, et seulement là : la
    // prop est facultative.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $draft->id]))
        ->assertInertia(fn ($page) => $page->missing('publication_preview'));

    ambiguityPartial($draft)
        ->assertOk()
        ->assertJsonPath('props.publication_preview.lines', $report->lines)
        ->assertJsonPath('props.publication_preview.digest', $report->digest());

    // Chaque ligne se dit en français, formes et films nommés.
    $line = ambiguityText('admin.movie.publish.preview.line', [
        'form' => 'vent d ecume',
        'kinds' => ambiguityText('admin.movie.publish.preview.kind.prefix'),
        'movies' => ambiguityText('admin.movie.publish.preview.movie', [
            'title' => $saga->title_original,
            'year' => 2001,
            'kind' => ambiguityText('admin.movie.publish.preview.kind.prefix'),
        ]),
    ]);

    expect($line)->toContain('« vent d ecume »')
        ->and($line)->toContain('Vent d’Écume : Le Premier Rivage (2001)');

    foreach ([':form', ':kinds', ':movies', ':title', ':year', ':kind'] as $placeholder) {
        expect($line)->not->toContain($placeholder);
    }

    // Un film sans forme partagée : une liste vide, dite en toutes lettres.
    $alone = ambiguityFilm('Sillage Nocturne', publishable: true);
    $empty = app(AmbiguityPreview::class)->forPublication($alone);

    expect($empty->isEmpty())->toBeTrue()
        ->and($empty->digest())->toHaveLength(64)
        ->and(ambiguityText('admin.movie.publish.preview.none'))->toBe('Aucune forme ne deviendra ambiguë.');
});

test('l\'aperçu n\'écrit rien', function (): void {
    ambiguityFilm('Vent d’Écume : Le Premier Rivage', ContentAvailability::Published);
    $draft = ambiguityFilm('Vent d’Écume : La Marée Haute', publishable: true);

    $keys = AnswerKey::query()->orderBy('id')->get(['id', 'movie_id', 'normalized', 'key_kind', 'is_ambiguous', 'updated_at'])->toArray();
    $movies = Movie::query()->orderBy('id')->get(['id', 'availability', 'updated_at'])->toArray();

    DB::enableQueryLog();

    // L'aperçu, directement, puis par les deux écrans qui le servent.
    $report = app(AmbiguityPreview::class)->forPublication($draft);
    app(AmbiguityPreview::class)->forText($draft, 'Vent d’Écume : Le Dernier Rivage', TextTarget::Title);

    ambiguityPartial($draft)->assertOk();

    $version = (string) $this->actingAs($this->curator)
        ->get(route('admin.catalog.bank', ['movie' => $draft->id]))
        ->viewData('page')['version'];

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.bank', ['movie' => $draft->id]), [
            Header::INERTIA => 'true',
            Header::VERSION => $version,
            Header::PARTIAL_COMPONENT => 'admin/catalog/bank',
            Header::PARTIAL_ONLY => 'publication_preview',
        ])
        ->assertOk()
        ->assertJsonPath('props.publication_preview.digest', $report->digest());

    $writes = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1)
        ->values()
        ->all();

    DB::disableQueryLog();

    expect($writes)->toBe([])
        ->and($report->isEmpty())->toBeFalse()
        ->and(AnswerKey::query()->orderBy('id')->get(['id', 'movie_id', 'normalized', 'key_kind', 'is_ambiguous', 'updated_at'])->toArray())->toBe($keys)
        ->and(Movie::query()->orderBy('id')->get(['id', 'availability', 'updated_at'])->toArray())->toBe($movies)
        ->and(AdminAction::query()->count())->toBe(0);
});

test('un aperçu périmé fait refuser la publication', function (): void {
    ambiguityFilm('Vent d’Écume : Le Premier Rivage', ContentAvailability::Published);
    $draft = ambiguityFilm('Vent d’Écume : La Marée Haute', publishable: true);

    // Le curateur ouvre la confirmation : un préfixe partagé avec un film.
    $seen = app(AmbiguityPreview::class)->forPublication($draft);

    expect($seen->lines)->toHaveCount(1);

    // Entre l'aperçu et le clic, un troisième épisode est publié.
    $storm = ambiguityFilm('Vent d’Écume : L’Orage', publishable: true);
    app(PublishMovie::class)->handle($storm, $this->curator, app(AmbiguityPreview::class)->forPublication($storm)->digest());

    $lines = AdminAction::query()->count();

    $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', ['movie' => $draft->id]))
        ->post(route('admin.catalog.publish', ['movie' => $draft->id]), ['ambiguity_digest' => $seen->digest()])
        ->assertRedirect(route('admin.catalog.show', ['movie' => $draft->id]))
        ->assertSessionHasErrors(['ambiguity_digest' => ambiguityText('admin.movie.publish.preview_stale')]);

    // Rien n'est écrit : ni l'état, ni le journal, ni les drapeaux.
    expect($draft->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and($draft->first_published_at)->toBeNull()
        ->and(AdminAction::query()->count())->toBe($lines);

    // Une empreinte absente ou mal formée ne vient pas de la confirmation à
    // jour : même refus, traduit.
    $this->actingAs($this->curator)
        ->post(route('admin.catalog.publish', ['movie' => $draft->id]), ['ambiguity_digest' => 'abc'])
        ->assertSessionHasErrors(['ambiguity_digest' => ambiguityText('admin.movie.publish.preview_stale')]);

    // L'aperçu se réaffiche, à jour : il nomme le nouvel épisode.
    $fresh = app(AmbiguityPreview::class)->forPublication($draft);

    ambiguityPartial($draft)->assertJsonPath('props.publication_preview.digest', $fresh->digest());

    expect($fresh->digest())->not->toBe($seen->digest())
        ->and(collect($fresh->lines[0]['movies'])->pluck('id')->all())->toContain($storm->id);

    // Sous l'avertissement à jour, la publication passe.
    $this->actingAs($this->curator)
        ->post(route('admin.catalog.publish', ['movie' => $draft->id]), ['ambiguity_digest' => $fresh->digest()])
        ->assertSessionHasNoErrors();

    expect($draft->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and(AdminAction::query()->where('subject_id', $draft->id)->sole()->action)->toBe(AdminActionType::MoviePublished);
});

test('l\'ambiguïté se mesure sur le catalogue publié entier et jamais sur un vivier', function (): void {
    // Un film publié HORS de tout vivier : contenu encore à vérifier, aucune
    // image, jouable à aucun N. Il pèse pourtant dans le recompte.
    $outside = ambiguityFilm('Vent d’Écume : Le Premier Rivage', ContentAvailability::Published);

    expect($outside->content_flag)->toBe(ContentFlag::UnratedPending)
        ->and(Movie::query()->inPool()->whereKey($outside->id)->exists())->toBeFalse()
        ->and(FrameBank::projection($outside)->levels_count)->toBe(0);

    // Un brouillon, un film dépublié, un film écarté : hors du catalogue
    // publié, ils ne pèsent pas, même s'ils portent la forme.
    ambiguityFilm('Vent d’Écume : Brouillon', ContentAvailability::Draft);
    ambiguityFilm('Vent d’Écume : Dépublié', ContentAvailability::Unpublished);
    ambiguityFilm('Vent d’Écume : Écarté', ContentAvailability::Unpublished, attributes: ['first_published_at' => null]);

    $draft = ambiguityFilm('Vent d’Écume : La Marée Haute', publishable: true);

    $report = app(AmbiguityPreview::class)->forPublication($draft);

    expect($report->lines)->toBe([[
        'form' => 'vent d ecume',
        'kinds' => [AnswerKeyKind::Prefix->value],
        'movies' => [ambiguityCarrier($outside, AnswerKeyKind::Prefix)],
    ]]);

    // Publié, le brouillon rend le préfixe ambigu pour les DEUX films publiés,
    // l'un d'eux hors de tout vivier.
    app(PublishMovie::class)->handle($draft, $this->curator, $report->digest());

    $flags = fn (Movie $movie): bool => AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->where('normalized', 'vent d ecume')
        ->sole()
        ->is_ambiguous;

    expect($flags($outside))->toBeTrue()
        ->and($flags($draft))->toBeTrue();

    // Le film hors vivier dépublié, le préfixe redevient accepté pour le seul
    // film publié qui le porte : c'est le catalogue publié qui compte.
    app(UnpublishMovie::class)->handle($outside, $this->curator, 'Contenu à vérifier avant publication.');

    expect($flags($draft))->toBeFalse()
        ->and($flags($outside))->toBeTrue();
});

test('l\'aperçu d\'un texte saisi nomme les formes qu\'un titre ou un alias rendrait ambiguës', function (): void {
    $saga = ambiguityFilm('Vent d’Écume : Le Premier Rivage', ContentAvailability::Published);
    $movie = ambiguityFilm('Sillage Nocturne', ContentAvailability::Published);
    $preview = app(AmbiguityPreview::class);

    // Un titre : sa forme exacte, son préfixe et son sous-titre.
    $title = $preview->forText($movie, 'Vent d’Écume : Premier Rivage', TextTarget::Title);

    expect($title->lines)->toBe([
        [
            'form' => 'premier rivage',
            'kinds' => [AnswerKeyKind::Subtitle->value],
            'movies' => [ambiguityCarrier($saga, AnswerKeyKind::Subtitle)],
        ],
        [
            'form' => 'vent d ecume',
            'kinds' => [AnswerKeyKind::Prefix->value],
            'movies' => [ambiguityCarrier($saga, AnswerKeyKind::Prefix)],
        ],
    ]);

    // Un alias : sa seule forme exacte, jamais dérivée — il ne rend ambigu
    // que le préfixe d'un autre film qu'il reprend mot pour mot.
    $alias = $preview->forText($movie, 'Vent d’Écume : Premier Rivage', TextTarget::Alias);

    expect($alias->isEmpty())->toBeTrue();

    expect($preview->forText($movie, 'Vent d’Écume', TextTarget::Alias)->lines)->toBe([[
        'form' => 'vent d ecume',
        'kinds' => [AnswerKeyKind::Alias->value],
        'movies' => [ambiguityCarrier($saga, AnswerKeyKind::Prefix)],
    ]]);
});
