<?php

use App\Actions\Curation\PublishMovie;
use App\Enums\AdminActionType;
use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\Locale;
use App\Models\AdminAction;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Models\User;
use App\Support\Catalog\AmbiguityPreview;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Publier, republier, dépublier, écarter un film — spec 20 § 8.1, § 8.3, § 4.2
|--------------------------------------------------------------------------
|
| La publication est un GESTE EXPLICITE du curateur, jamais un déclencheur
| (n° 3) : « publié dès qu'il couvre 1, 3 et 5 » décrit son éligibilité. Les
| gardes de transition — contenu vérifié, couverture 1-3-5 jouable, une clé
| exacte non vide, aperçu d'ambiguïté à jour — refusent en erreurs traduites,
| sans rien écrire. Chaque transition de disponibilité d'un film a sa ligne
| au journal, première publication comprise, et le recompte de l'ambiguïté
| de ses formes dans la même transaction.
|
*/

beforeEach(function (): void {
    // Les images publiées de fixture écrivent leurs octets : sur un disque faux.
    Storage::fake(FrameStoragePrefix::DISK);

    // Publier ne distribue aucun traitement.
    Queue::fake();

    $this->curator = User::factory()->curator()->create();
});

/**
 * Un film dont la banque porte une image publiée par niveau donné, le
 * contenu au drapeau donné, et ses clés de réponse projetées.
 *
 * `clear` passe par une certification non restrictive, l'une des deux voies
 * réelles vers ce verdict : jamais un drapeau posé nu.
 *
 * @param  list<int>  $levels
 * @param  array<string, mixed>  $attributes
 */
function moviePublicationFilm(
    array $levels = [1, 3, 5],
    ContentFlag $flag = ContentFlag::Clear,
    array $attributes = [],
): Movie {
    $factory = $flag === ContentFlag::Clear
        ? Movie::factory()->withCertification()->contentFlag(ContentFlag::Clear)
        : Movie::factory()->contentFlag($flag);

    $movie = $factory->create($attributes);

    FrameBank::movieWith($movie, $levels);
    (new AnswerKeyProjector)->project($movie);

    return $movie->refresh();
}

/**
 * L'empreinte de l'aperçu que la confirmation montrerait à l'instant.
 */
function moviePublicationDigest(Movie $movie): string
{
    return app(AmbiguityPreview::class)->forPublication($movie)->digest();
}

/**
 * La publication, postée depuis la fiche du film avec l'empreinte courante.
 */
function moviePublicationPost(Movie $movie, ?User $curator = null, ?string $digest = null): TestResponse
{
    return test()
        ->actingAs($curator ?? test()->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->post(route('admin.catalog.publish', ['movie' => $movie->id]), [
            'ambiguity_digest' => $digest ?? moviePublicationDigest($movie),
        ]);
}

/**
 * La dépublication (ou la mise à l'écart), postée depuis la fiche du film.
 */
function moviePublicationUnpublish(Movie $movie, ?string $reason, ?User $curator = null): TestResponse
{
    return test()
        ->actingAs($curator ?? test()->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->post(route('admin.catalog.unpublish', ['movie' => $movie->id]), ['reason' => $reason]);
}

/**
 * Un texte du back-office, résolu en français — sa clé vérifiée d'abord : une
 * clé absente se rendrait telle quelle des deux côtés d'une comparaison.
 *
 * @param  array<string, int|string>  $replace
 */
function moviePublicationText(string $key, array $replace = []): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, $replace, Locale::French->value);
}

/**
 * Le drapeau d'ambiguïté d'une forme, pour un film.
 */
function moviePublicationAmbiguous(Movie $movie, string $normalized): bool
{
    return AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->where('normalized', $normalized)
        ->sole()
        ->is_ambiguous;
}

test('un film couvrant 1, 2 et 4 ne peut pas être publié', function (): void {
    $movie = moviePublicationFilm([1, 2, 4]);

    // Trois niveaux en jeu, mais pas les trois exigés : jouable à N = 2 ou 3,
    // jamais publiable.
    expect(FrameBank::projection($movie)->levels_count)->toBe(3)
        ->and(FrameBank::projection($movie)->coversPublishableLevels())->toBeFalse();

    $levels = implode(moviePublicationText('admin.common.list_separator'), [3, 5]);

    moviePublicationPost($movie)
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasErrors([
            'movie' => moviePublicationText('admin.movie.publish.coverage_missing', ['levels' => $levels]),
        ]);

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and($movie->first_published_at)->toBeNull()
        ->and($movie->curated_by_id)->toBeNull()
        ->and(AdminAction::query()->count())->toBe(0);

    // Le bouton de la fiche est inactif, la condition manquante nommée.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('abilities.publish', true)
            ->where('publication.blockers', [PublishMovie::COVERAGE_MISSING])
            ->where('publication.missing_levels', [3, 5]));

    // Le niveau 3, puis le niveau 5 : publiable.
    FrameBank::movieWith($movie, [3, 5]);

    moviePublicationPost($movie)->assertSessionHasNoErrors();

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Published);
});

test('un film au contenu non vérifié ou bloqué ne peut pas être publié', function (ContentFlag $flag): void {
    $movie = moviePublicationFilm([1, 3, 5], $flag);

    moviePublicationPost($movie)
        ->assertSessionHasErrors([
            'movie' => moviePublicationText('admin.movie.publish.content_not_clear'),
        ]);

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and($movie->content_flag)->toBe($flag)
        ->and(AdminAction::query()->count())->toBe(0);

    // La couverture ne rachète pas le contenu : une seule condition manque,
    // et c'est elle que la fiche nomme.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('publication.blockers', [PublishMovie::CONTENT_NOT_CLEAR])
            ->where('publication.missing_levels', []));

    // Même refus au pied de l'éditeur de la banque, qui nomme la même
    // condition sous son bouton.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.bank', ['movie' => $movie->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('abilities.publish', true)
            ->where('movie.publication.blockers', [PublishMovie::CONTENT_NOT_CLEAR]));
})->with([
    'classification à vérifier' => ContentFlag::UnratedPending,
    'bloqué' => ContentFlag::Blocked,
]);

test('un film sans aucune clé exacte non vide ne peut pas être publié', function (): void {
    // Un titre original sans lettre ni chiffre, sans titre ni alias : la
    // projection n'en tire aucune clé.
    $movie = moviePublicationFilm([1, 3, 5], ContentFlag::Clear, [
        'title_original' => '¿ ! … ?',
        'title_original_latin' => null,
    ]);

    expect(AnswerKey::query()->where('movie_id', $movie->id)->count())->toBe(0);

    moviePublicationPost($movie)
        ->assertSessionHasErrors([
            'movie' => moviePublicationText('admin.movie.publish.not_guessable'),
        ]);

    // Une clé DÉRIVÉE ne suffit jamais — un préfixe peut devenir ambigu —,
    // ni une clé exacte à la forme vide.
    AnswerKey::factory()->prefix('Saga sans titre')->create(['movie_id' => $movie->id]);
    AnswerKey::factory()->create([
        'movie_id' => $movie->id,
        'key_kind' => AnswerKeyKind::Title,
        'normalized' => '',
    ]);

    moviePublicationPost($movie)
        ->assertSessionHasErrors([
            'movie' => moviePublicationText('admin.movie.publish.not_guessable'),
        ]);

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and(AdminAction::query()->count())->toBe(0);

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('publication.blockers', [PublishMovie::NOT_GUESSABLE]));

    // Un alias lisible le rend devinable.
    AnswerKey::factory()->alias('Le film sans titre')->create(['movie_id' => $movie->id]);

    moviePublicationPost($movie)->assertSessionHasNoErrors();

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Published);
});

test('la première publication écrit movie.published, pose first_published_at et curated_by_id, puis ne les réécrit jamais', function (): void {
    $first = User::factory()->curator()->create();
    $second = User::factory()->curator()->create();
    $movie = moviePublicationFilm();

    moviePublicationPost($movie, $first)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    $movie->refresh();
    $publishedAt = $movie->first_published_at;

    expect($movie->availability)->toBe(ContentAvailability::Published)
        ->and($publishedAt)->not->toBeNull()
        ->and($movie->availability_changed_at?->equalTo($publishedAt))->toBeTrue()
        ->and($movie->availability_reason)->toBeNull()
        ->and($movie->curated_by_id)->toBe($first->id);

    $line = AdminAction::query()->sole();

    expect($line->action)->toBe(AdminActionType::MoviePublished)
        ->and($line->subject_id)->toBe($movie->id)
        ->and($line->actor_id)->toBe($first->id)
        ->and($line->actor_name)->toBe($first->real_name)
        ->and($line->reason)->toBeNull();

    // Dépublié puis republié par un autre curateur, plus tard : ni la date de
    // première publication ni l'auteur de la passe de curation ne bougent.
    $this->travel(2)->days();

    moviePublicationUnpublish($movie, 'Titre à revoir avant de rouvrir.', $second)->assertSessionHasNoErrors();

    $this->travel(1)->days();

    moviePublicationPost($movie->refresh(), $second)->assertSessionHasNoErrors();

    $movie->refresh();

    expect($movie->availability)->toBe(ContentAvailability::Published)
        ->and($movie->first_published_at?->equalTo($publishedAt))->toBeTrue()
        ->and($movie->availability_changed_at?->greaterThan($publishedAt))->toBeTrue()
        ->and($movie->curated_by_id)->toBe($first->id)
        ->and(AdminAction::query()->where('action', AdminActionType::MoviePublished->value)->count())->toBe(1)
        ->and(AdminAction::query()->where('action', AdminActionType::MovieRepublished->value)->sole()->actor_id)->toBe($second->id);

    // Un film publié ne se publie pas deux fois : la garde le refuse.
    moviePublicationPost($movie)->assertForbidden();
});

test('une republication écrit movie.republished', function (): void {
    $movie = moviePublicationFilm();
    $movie->forceFill([
        'availability' => ContentAvailability::Unpublished,
        'availability_changed_at' => now()->subWeek(),
        'availability_reason' => 'Retiré du vivier par un curateur.',
        'first_published_at' => now()->subMonth(),
    ])->save();

    $firstPublishedAt = $movie->refresh()->first_published_at;

    // La fiche l'annonce comme une republication.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('publication.first', false)
            ->where('publication.blockers', []));

    moviePublicationPost($movie)->assertSessionHasNoErrors();

    $movie->refresh();

    expect($movie->availability)->toBe(ContentAvailability::Published)
        ->and($movie->availability_reason)->toBeNull()
        ->and($movie->first_published_at?->equalTo($firstPublishedAt))->toBeTrue();

    $line = AdminAction::query()->sole();

    expect($line->action)->toBe(AdminActionType::MovieRepublished)
        ->and($line->subject_id)->toBe($movie->id)
        ->and($line->actor_id)->toBe($this->curator->id)
        ->and($line->reason)->toBeNull();
});

test('la publication recalcule l\'ambiguïté des formes du film dans la même transaction', function (): void {
    // Un film publié, et un brouillon qui partage son préfixe.
    $published = moviePublicationFilm([1, 3, 5], ContentFlag::Clear, ['title_original' => 'Vent d’Écume : Le Premier Rivage']);
    moviePublicationPost($published)->assertSessionHasNoErrors();

    $draft = moviePublicationFilm([1, 3, 5], ContentFlag::Clear, ['title_original' => 'Vent d’Écume : La Marée Haute']);

    // Seul film publié à le porter, son préfixe est accepté ; celui du
    // brouillon, lui, est déjà ambigu — un film publié porte la forme.
    expect(moviePublicationAmbiguous($published, 'vent d ecume'))->toBeFalse()
        ->and(moviePublicationAmbiguous($draft, 'vent d ecume'))->toBeTrue();

    moviePublicationPost($draft)->assertSessionHasNoErrors();

    // Deux films publiés portent le préfixe : il n'est plus accepté seul,
    // pour aucun des deux. Les sous-titres, propres à chacun, le restent.
    expect(moviePublicationAmbiguous($published, 'vent d ecume'))->toBeTrue()
        ->and(moviePublicationAmbiguous($draft, 'vent d ecume'))->toBeTrue()
        ->and(moviePublicationAmbiguous($published, 'premier rivage'))->toBeFalse()
        ->and(moviePublicationAmbiguous($draft, 'maree haute'))->toBeFalse();

    // Même transaction : une panne pendant le recompte annule la publication
    // et sa ligne de journal, et laisse les drapeaux intacts.
    $echo = moviePublicationFilm([1, 3, 5], ContentFlag::Clear, ['title_original' => 'Écho Pâle : Aube']);
    moviePublicationPost($echo)->assertSessionHasNoErrors();

    $candidate = moviePublicationFilm([1, 3, 5], ContentFlag::Clear, ['title_original' => 'Écho Pâle : Crépuscule']);
    $digest = moviePublicationDigest($candidate);
    $lines = AdminAction::query()->count();

    Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
        if (preg_match('/^\s*update\s+["`]?answer_key["`]?/i', $query->sql) === 1) {
            throw new RuntimeException('Panne simulée du recompte d’ambiguïté.');
        }
    });

    expect(fn () => app(PublishMovie::class)->handle($candidate, $this->curator, $digest))
        ->toThrow(RuntimeException::class, 'Panne simulée');

    expect($candidate->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and($candidate->first_published_at)->toBeNull()
        ->and(AdminAction::query()->count())->toBe($lines)
        ->and(moviePublicationAmbiguous($echo, 'echo pale'))->toBeFalse();
});

test('dépublier exige un motif, écrit movie.unpublished et laisse les images publiées', function (): void {
    [$movie, $frames] = FrameBank::publishedMovie([1, 3, 5]);
    $movie->forceFill(['title_original' => 'Brume Lointaine : Retour'])->save();
    (new AnswerKeyProjector)->project($movie);

    // Un second film publié partage son préfixe : ambigu tant que les deux
    // sont publiés.
    $sibling = moviePublicationFilm([1, 3, 5], ContentFlag::Clear, ['title_original' => 'Brume Lointaine : Départ']);
    moviePublicationPost($sibling)->assertSessionHasNoErrors();

    expect(moviePublicationAmbiguous($sibling, 'brume lointaine'))->toBeTrue();

    $lines = AdminAction::query()->count();
    $projection = FrameBank::projection($movie)->levels_mask;

    // Sans motif, ou un motif fait d'espaces : refusé, rien n'est écrit.
    foreach ([null, '   '] as $empty) {
        moviePublicationUnpublish($movie, $empty)->assertSessionHasErrors('reason');
    }

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and(AdminAction::query()->count())->toBe($lines);

    moviePublicationUnpublish($movie, 'Titre anglais erroné, à corriger avant de rouvrir.')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    $movie->refresh();

    expect($movie->availability)->toBe(ContentAvailability::Unpublished)
        ->and($movie->availability_reason)->toBe('Titre anglais erroné, à corriger avant de rouvrir.')
        ->and($movie->availability_changed_at)->not->toBeNull()
        ->and($movie->first_published_at)->not->toBeNull();

    $line = AdminAction::query()->where('action', AdminActionType::MovieUnpublished->value)->sole();

    expect($line->subject_id)->toBe($movie->id)
        ->and($line->actor_id)->toBe($this->curator->id)
        ->and($line->reason)->toBe('Titre anglais erroné, à corriger avant de rouvrir.');

    // Les images ne suivent pas : toutes restent en jeu, la projection aussi.
    foreach ($frames as $frame) {
        expect($frame->refresh()->availability)->toBe(ContentAvailability::Published)
            ->and($frame->isServable())->toBeTrue();
    }

    expect(FrameBank::projection($movie)->levels_mask)->toBe($projection)
        ->and(AdminAction::query()->where('action', AdminActionType::FrameUnpublished->value)->exists())->toBeFalse();

    // Sa sortie du catalogue publié lève l'ambiguïté du préfixe partagé.
    expect(moviePublicationAmbiguous($sibling, 'brume lointaine'))->toBeFalse();
});

test('écarter un brouillon le passe unpublished sans first_published_at', function (): void {
    // Un film incurable : aucune image, aucun visuel exploitable.
    $movie = Movie::factory()->withCertification()->contentFlag(ContentFlag::Clear)->create();
    $reason = moviePublicationText('admin.movie.set_aside.default_reason');

    moviePublicationUnpublish($movie, $reason)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    $movie->refresh();

    expect($movie->availability)->toBe(ContentAvailability::Unpublished)
        ->and($movie->first_published_at)->toBeNull()
        ->and($movie->availability_reason)->toBe($reason);

    $line = AdminAction::query()->sole();

    expect($line->action)->toBe(AdminActionType::MovieUnpublished)
        ->and($line->reason)->toBe($reason);

    // Un film écarté ne s'écarte pas deux fois.
    moviePublicationUnpublish($movie, $reason)->assertForbidden();

    // Rien n'est perdu : curé puis publié plus tard, il fait sa PREMIÈRE
    // publication.
    FrameBank::movieWith($movie, [1, 3, 5]);
    Movie::query()->whereKey($movie->id)->update(['title_original' => 'Récif Oublié']);
    (new AnswerKeyProjector)->project($movie->refresh());

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('abilities.publish', true)
            ->where('abilities.unpublish', false)
            ->where('publication.first', true));

    moviePublicationPost($movie)->assertSessionHasNoErrors();

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and($movie->first_published_at)->not->toBeNull()
        ->and(AdminAction::query()->where('action', AdminActionType::MoviePublished->value)->sole()->subject_id)->toBe($movie->id);
});
