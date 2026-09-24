<?php

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Jobs\Curation\ProcessFrameImage;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Policies\FramePolicy;
use App\Policies\MoviePolicy;
use App\Settings\PlatformLimits;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\TmdbFixture;
use Tests\Support\Frames\ImagickLimits;
use Tests\Support\Frames\SourceImages;

/*
|--------------------------------------------------------------------------
| Ajouter une variante depuis TMDB — contrat C9, spec 20 § 5.3
|--------------------------------------------------------------------------
|
| La garde d'ajout nomme la CLASSE en premier argument :
| `can:create,App\Models\Frame,movie`. Le middleware `can` résout la policy
| d'après ce premier argument ; écrite `can:create,movie`, elle résoudrait
| `MoviePolicy::create()`, qui refuse toujours parce que la création d'un
| film est un acte d'import, et toute la voie TMDB répondrait 403 (V-17).
|
| La route réelle `admin.catalog.frames.tmdb.store` (lot L20-7) a sa garde
| exacte tenue par la matrice des capacités (`tests/Datasets/AdminRoutes.php`,
| `AuthorizationMatrixTest`). La résolution, elle, se prouve dès la policy,
| sur deux routes de test à la forme du groupe `/admin` : la bonne garde et
| la mauvaise.
|
| Le reste joue la route réelle. TMDB est simulé de bout en bout — la liste
| des visuels du film par la fixture `movie-987654-images`, l'original par
| des octets SYNTHÉTIQUES générés par Imagick (`SourceImages`) : aucune image
| réelle, aucun appel réseau. Le disque `frames` est faux ; la file aussi
| (`Queue::fake()` en tête de test), sauf là où le test prouve que le job
| part après le commit.
|
*/

/** Identifiant TMDB de la fixture `movie-987654-images`. */
const FRAME_TMDB_ID = 987654;

/** Un backdrop 16:9 de 1920 × 1080 de la fixture, sans langue. */
const FRAME_TMDB_BACKDROP = '/6a7b8c9d0e1f2a3b4c5d6e7f80912a3b.jpg';

/** L'affiche de la fixture : jamais candidate à une image de jeu. */
const FRAME_TMDB_POSTER = '/0e1f2a3b4c5d6e7f80912a3b4c5d6e7f.jpg';

beforeEach(function (): void {
    // Un fichier ne participe à aucune transaction : sans ce `fake`, les
    // octets provisoires partiraient dans la racine réelle du disque.
    Storage::fake(FrameStoragePrefix::DISK);

    // TMDB configuré, jamais joint : chaque appel est simulé, et le retrait
    // exponentiel d'une panne ne coûte aucune seconde.
    Config::set('services.tmdb.read_access_token', 'fixture-token');
    Config::set('services.tmdb.api_key', null);
    Sleep::fake();

    // Le seul test qui joue le job pose les limites d'Imagick pour tout le
    // processus : elles sont relevées avant, et reposées après.
    ImagickLimits::capture();
});

afterEach(function (): void {
    Sleep::fake(false);
    ImagickLimits::restore();
});

/**
 * TMDB simulé : les visuels du film et l'original téléchargé. Rend les octets
 * que le serveur d'images renverra.
 *
 * @param  array<string, mixed>|null  $images  une charge utile de visuels, à la place de la fixture
 */
function frameTmdbFake(?string $original = null, ?array $images = null): string
{
    $original ??= SourceImages::jpeg(1920, 1080);

    Http::fake([
        '*themoviedb.org/3/movie/'.FRAME_TMDB_ID.'/images*' => Http::response($images ?? TmdbFixture::json('movie-987654-images')),
        '*image.tmdb.org/*' => Http::response($original),
    ]);

    return $original;
}

/**
 * Un ajout conforme sur le backdrop de 1920 × 1080 : niveau choisi, cadre par
 * défaut de son master, temps de recadrage posté par le recadreur.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function frameTmdbPayload(array $overrides = []): array
{
    $crop = FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(1920, 1080), PlatformLimits::current());

    return [
        'tmdb_file_path' => FRAME_TMDB_BACKDROP,
        'frame_level' => FrameLevel::Level3->value,
        'crop_x' => $crop->x,
        'crop_y' => $crop->y,
        'crop_width' => $crop->width,
        'crop_height' => $crop->height,
        'crop_seconds' => 42,
        ...$overrides,
    ];
}

/**
 * Un texte du back-office, résolu en français : le domaine `admin` n'existe
 * qu'en français, et la locale ambiante des tests est l'anglais.
 *
 * @param  array<string, int|string>  $replace
 */
function frameTmdbText(string $key, array $replace = []): string
{
    return (string) __($key, $replace, 'fr');
}

function frameTmdbMovie(): Movie
{
    return Movie::factory()->create(['tmdb_id' => FRAME_TMDB_ID]);
}

/**
 * L'envoi du formulaire, posté depuis la fiche du film.
 *
 * @param  array<string, mixed>  $payload
 */
function frameTmdbPost(Movie $movie, array $payload, ?User $curator = null): TestResponse
{
    return test()
        ->actingAs($curator ?? User::factory()->curator()->create())
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->post(route('admin.catalog.frames.tmdb.store', ['movie' => $movie->id]), $payload);
}

/**
 * Vrai si une requête simulée est partie vers le serveur d'images de TMDB,
 * c'est-à-dire si un original a été téléchargé.
 */
function frameTmdbDownloaded(): bool
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'image.tmdb.org'))->isNotEmpty();
}

/**
 * Tous les fichiers du disque `frames`, triés.
 *
 * @return list<string>
 */
function frameTmdbFiles(): array
{
    $files = Storage::disk(FrameStoragePrefix::DISK)->allFiles();
    sort($files);

    return array_values($files);
}

test('la garde d\'ajout passe par FramePolicy et jamais par MoviePolicy::create', function (): void {
    Route::middleware(['web', 'auth', 'role:curator', 'can:create,'.Frame::class.',movie'])
        ->post('_tests/catalog/{movie}/frames/tmdb', fn (Movie $movie): string => 'ajout')
        ->name('tests.frames.tmdb.store');

    Route::middleware(['web', 'auth', 'role:curator', 'can:create,movie'])
        ->post('_tests/catalog/{movie}/frames/wrong-guard', fn (Movie $movie): string => 'ajout')
        ->name('tests.frames.wrong_guard');

    // Noms posés après l'entrée des routes dans la collection : le noyau fait
    // ce geste une fois les fichiers de routes chargés, ici il nous revient.
    Route::getRoutes()->refreshNameLookups();

    $curator = User::factory()->curator()->create();
    $movie = Movie::factory()->create();

    // La policy résolue par le premier argument de la garde.
    expect(Gate::getPolicyFor(Frame::class))->toBeInstanceOf(FramePolicy::class)
        ->and(Gate::getPolicyFor($movie))->toBeInstanceOf(MoviePolicy::class)
        ->and(Gate::forUser($curator)->allows('create', [Frame::class, $movie]))->toBeTrue()
        ->and(Gate::forUser($curator)->allows('create', $movie))->toBeFalse()
        ->and(Gate::forUser($curator)->allows('create', Movie::class))->toBeFalse();

    // La bonne garde ouvre l'ajout au curateur ; la mauvaise le lui ferme.
    $this->actingAs($curator)
        ->post(route('tests.frames.tmdb.store', $movie))
        ->assertOk();

    $this->actingAs($curator)
        ->post(route('tests.frames.wrong_guard', $movie))
        ->assertForbidden();

    // Et c'est bien FramePolicy qui juge : son refus d'état passe par la même
    // garde — la banque d'un film suspendu ou retiré ne grossit pas.
    foreach ([Movie::factory()->suspended()->create(), Movie::factory()->withdrawn()->create()] as $closed) {
        $this->actingAs($curator)
            ->post(route('tests.frames.tmdb.store', $closed))
            ->assertForbidden();
    }

    // Un joueur ne la franchit jamais, un administrateur la franchit parce
    // qu'il est au-dessus du seuil.
    $this->actingAs(User::factory()->player()->create())
        ->post(route('tests.frames.tmdb.store', $movie))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('tests.frames.tmdb.store', $movie))
        ->assertOk();
});

test('un visuel absent des backdrops du film est refusé', function (): void {
    Queue::fake();
    frameTmdbFake();

    // Un identifiant que TMDB ne connaît plus.
    Http::fake([
        '*themoviedb.org/3/movie/'.(FRAME_TMDB_ID + 1).'/images*' => Http::response(TmdbFixture::json('error-404'), 404),
    ]);

    $movie = frameTmdbMovie();
    $refusal = frameTmdbText('admin.frame.tmdb.not_a_backdrop');

    // L'affiche du film : TMDB la connaît, mais une affiche ne devient jamais
    // une image de jeu (§ 6.2). Puis un chemin que TMDB ne propose pas pour ce
    // film, et un chemin hors forme, qui ne peut désigner aucun visuel.
    foreach ([FRAME_TMDB_POSTER, '/ffffffffffffffffffffffffffffffff.jpg', '/../game/aa.jpg'] as $path) {
        frameTmdbPost($movie, frameTmdbPayload(['tmdb_file_path' => $path]))
            ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
            ->assertSessionHasErrors(['tmdb_file_path' => $refusal]);
    }

    // Un film sans identifiant TMDB (démonstration) n'a aucun visuel proposé.
    $demo = Movie::factory()->demo()->create();

    frameTmdbPost($demo, frameTmdbPayload())
        ->assertSessionHasErrors(['tmdb_file_path' => $refusal]);

    // Un identifiant que TMDB ne connaît plus non plus.
    frameTmdbPost(Movie::factory()->create(['tmdb_id' => FRAME_TMDB_ID + 1]), frameTmdbPayload())
        ->assertSessionHasErrors(['tmdb_file_path' => $refusal]);

    // Aucun original téléchargé, aucune image, aucun octet, aucun job.
    expect(frameTmdbDownloaded())->toBeFalse()
        ->and(Frame::query()->count())->toBe(0)
        ->and(frameTmdbFiles())->toBe([]);

    Queue::assertNothingPushed();
});

test('source_hash est le SHA-256 des octets originaux téléchargés', function (): void {
    Queue::fake();
    $original = frameTmdbFake();
    $movie = frameTmdbMovie();
    $curator = User::factory()->curator()->create();

    frameTmdbPost($movie, frameTmdbPayload(), $curator)->assertSessionHasNoErrors();

    $frame = Frame::query()->sole();

    // L'empreinte des octets que le SERVEUR a téléchargés, jamais une
    // déclaration du navigateur : le formulaire n'envoie aucun octet.
    expect($frame->source_hash)->toBe(hash('sha256', $original))
        ->and($frame->source_kind)->toBe(FrameSourceKind::Tmdb)
        ->and($frame->tmdb_file_path)->toBe(FRAME_TMDB_BACKDROP)
        ->and($frame->uploaded_by_id)->toBe($curator->id)
        ->and($frame->movie_id)->toBe($movie->id);

    // Les octets originaux, déposés PROVISOIREMENT sous `master_path` — le
    // seul fichier écrit par la requête —, sous un nom aléatoire du préfixe
    // `master/`, jamais sous le chemin TMDB.
    expect(FrameStoragePrefix::Master->owns((string) $frame->master_path))->toBeTrue()
        ->and(Storage::disk(FrameStoragePrefix::DISK)->get((string) $frame->master_path))->toBe($original)
        ->and(frameTmdbFiles())->toBe([$frame->master_path])
        ->and((string) $frame->master_path)->not->toContain(trim(FRAME_TMDB_BACKDROP, '/'));

    // Le serveur a bien téléchargé l'original, sans jamais envoyer à ce
    // serveur tiers le jeton de l'API.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'image.tmdb.org/t/p/original'.FRAME_TMDB_BACKDROP)
        && ! $request->hasHeader('Authorization'));

    // Le rectangle, le niveau et le temps de recadrage postés sont écrits tels
    // quels ; le temps est plafonné côté serveur.
    $payload = frameTmdbPayload();

    expect($frame->crop_x)->toBe($payload['crop_x'])
        ->and($frame->crop_y)->toBe($payload['crop_y'])
        ->and($frame->crop_width)->toBe($payload['crop_width'])
        ->and($frame->crop_height)->toBe($payload['crop_height'])
        ->and($frame->frame_level)->toBe(FrameLevel::Level3)
        ->and($frame->crop_seconds)->toBe(42);

    Config::set('catalog.curation.crop_seconds_max', 90);

    frameTmdbPost($movie, frameTmdbPayload(['crop_x' => $payload['crop_x'] - FrameGeometry::ASPECT_WIDTH, 'crop_seconds' => 5_000]))
        ->assertSessionHasNoErrors();

    expect(Frame::query()->latest('id')->firstOrFail()->crop_seconds)->toBe(90);
});

test('la frame naît draft et pending et le job part sur la file default après commit', function (): void {
    frameTmdbFake();
    $movie = frameTmdbMovie();
    $payload = frameTmdbPayload();

    // « Après commit » se voit d'abord sur la vraie file des tests (`sync`) :
    // le job ne démarre qu'une fois la transaction d'ajout refermée — au
    // niveau de transaction d'avant l'envoi —, et il trouve sa ligne écrite.
    $base = DB::transactionLevel();
    $seen = [];

    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$seen): void {
        $seen[] = [
            'level' => DB::transactionLevel(),
            'job' => $event->job->resolveName(),
            'frames' => Frame::query()->count(),
        ];
    });

    frameTmdbPost($movie, $payload)->assertSessionHasNoErrors();

    expect($seen)->toBe([['level' => $base, 'job' => ProcessFrameImage::class, 'frames' => 1]])
        ->and(Frame::query()->sole()->processing_state)->toBe(FrameProcessingState::Ready);

    // Puis la file retenue : ce que l'ajout écrit, avant tout traitement.
    Queue::fake();

    frameTmdbPost($movie, frameTmdbPayload(['crop_x' => $payload['crop_x'] - FrameGeometry::ASPECT_WIDTH]))
        ->assertSessionHasNoErrors();

    $frame = Frame::query()->latest('id')->firstOrFail();

    // Un brouillon en traitement : aucun dérivé, aucune empreinte servie,
    // jamais publié, et hors de toute variante jouable.
    expect($frame->availability)->toBe(ContentAvailability::Draft)
        ->and($frame->processing_state)->toBe(FrameProcessingState::Pending)
        ->and($frame->processing_error)->toBeNull()
        ->and($frame->game_path)->toBeNull()
        ->and($frame->published_hash)->toBeNull()
        ->and($frame->first_published_at)->toBeNull()
        ->and($frame->isServable())->toBeFalse();

    // Un seul job, pour cette frame, sur `default` — jamais `game` —, et
    // marqué pour partir après le commit.
    Queue::assertPushedOn(ProcessFrameImage::QUEUE, ProcessFrameImage::class, fn (ProcessFrameImage $job): bool => $job->frameId === $frame->id
        && $job->afterCommit === true);
    Queue::assertCount(1);
});

test('un échec de téléchargement ne crée aucune frame', function (): void {
    Queue::fake();
    $movie = frameTmdbMovie();
    $images = TmdbFixture::json('movie-987654-images');
    $failure = 'server_error';

    // Une seule simulation, dont la panne change d'un envoi à l'autre : les
    // simulations d'URL s'évaluent toutes, dans l'ordre d'enregistrement, et
    // la première réponse l'emporte — une seconde ne remplacerait rien.
    Http::fake([
        '*themoviedb.org/3/movie/'.FRAME_TMDB_ID.'/images*' => function () use (&$failure, $images) {
            return $failure === 'rate_limited'
                ? Http::response(TmdbFixture::json('error-429'), 429)
                : Http::response($images);
        },
        '*image.tmdb.org/*' => function () use (&$failure) {
            return match ($failure) {
                'transport' => throw new ConnectionException('cURL error 28: Operation timed out'),
                'too_large' => Http::response(str_repeat("\x00", 2_048)),
                default => Http::response('', 503),
            };
        },
    ]);

    // TMDB en panne sur le serveur d'images, tentatives épuisées.
    frameTmdbPost($movie, frameTmdbPayload())
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasErrors(['tmdb_file_path' => frameTmdbText('admin.frame.tmdb.download_failed')]);

    // Connexion coupée.
    $failure = 'transport';

    frameTmdbPost($movie, frameTmdbPayload())
        ->assertSessionHasErrors(['tmdb_file_path' => frameTmdbText('admin.frame.tmdb.download_failed')]);

    // Original plus lourd que le plafond configuré.
    $failure = 'too_large';
    Config::set('catalog.curation.tmdb_original_max_kilobytes', 1);

    frameTmdbPost($movie, frameTmdbPayload())
        ->assertSessionHasErrors(['tmdb_file_path' => frameTmdbText('admin.frame.tmdb.too_large')]);

    // Quota atteint sur l'appel interactif : un message traduit, jamais une
    // page d'erreur, et le formulaire se renvoie tel quel (§ 3.6).
    $failure = 'rate_limited';

    frameTmdbPost($movie, frameTmdbPayload())
        ->assertSessionHasErrors(['tmdb_file_path' => frameTmdbText('admin.tmdb.error.rate_limited_interactive')]);

    // Aucune ligne, aucun octet, aucun job.
    expect(Frame::query()->count())->toBe(0)
        ->and(frameTmdbFiles())->toBe([]);

    Queue::assertNothingPushed();
});

test('la réponse ne contient ni chemin disque ni hash', function (): void {
    $this->withoutVite();
    Queue::fake();

    frameTmdbFake();
    $movie = frameTmdbMovie();
    $curator = User::factory()->curator()->create();

    $response = frameTmdbPost($movie, frameTmdbPayload(), $curator)
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => frameTmdbText('admin.frame.flash.queued')]);

    $frame = Frame::query()->sole();
    $secrets = [(string) $frame->master_path, $frame->source_hash, FrameStoragePrefix::Master->value, FrameStoragePrefix::Game->value];

    // Ni le corps, ni les en-têtes, ni la session — toast compris.
    $surfaces = [
        'corps' => (string) $response->getContent(),
        'en-têtes' => (string) json_encode($response->headers->all()),
        'session' => (string) json_encode(session()->all()),
    ];

    foreach ($surfaces as $surface => $content) {
        foreach ($secrets as $secret) {
            expect($content)->not->toContain($secret, $surface);
        }
    }

    // Ni la page où le retour arrière ramène le curateur.
    $page = (string) $this->actingAs($curator)->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertOk()
        ->getContent();

    foreach ($secrets as $secret) {
        expect($page)->not->toContain($secret);
    }
});

test('un visuel trop étroit ou en portrait est refusé avant tout téléchargement', function (): void {
    Queue::fake();
    $backdrop = static fn (string $path, int $width, int $height): array => [
        'aspect_ratio' => round($width / $height, 3),
        'height' => $height,
        'iso_639_1' => null,
        'file_path' => $path,
        'vote_average' => 5.0,
        'vote_count' => 1,
        'width' => $width,
    ];

    $narrow = '/1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d.jpg';
    $portrait = '/2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e.jpg';
    $panorama = '/3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f.jpg';
    $flat = '/4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a.jpg';

    frameTmdbFake(images: [
        'id' => FRAME_TMDB_ID,
        'backdrops' => [
            // Moins large qu'une image de jeu : elle devrait l'agrandir.
            $backdrop($narrow, FrameGeometry::GAME_WIDTH - FrameGeometry::ASPECT_WIDTH, 720),
            // En portrait, quelle que soit sa définition.
            $backdrop($portrait, 2_160, 3_840),
            // Si allongé qu'aucun cadre n'y respecte le plancher.
            $backdrop($panorama, 3_840, 400),
            // Sans hauteur : TMDB la déclare nulle, la lecture la replie à 0.
            [...$backdrop($flat, 1_920, 1_080), 'height' => 0],
        ],
        'posters' => [],
        'logos' => [],
    ]);

    $movie = frameTmdbMovie();
    $refusal = frameTmdbText('admin.validation.frame_source.dimensions', ['width' => FrameGeometry::GAME_WIDTH]);

    foreach ([$narrow, $portrait, $panorama, $flat] as $path) {
        frameTmdbPost($movie, frameTmdbPayload(['tmdb_file_path' => $path]))
            ->assertSessionHasErrors(['tmdb_file_path' => $refusal]);
    }

    // Refusé AVANT tout téléchargement : aucun octet n'est venu, aucun n'est
    // déposé, aucune image n'échoue plus tard au job.
    expect(frameTmdbDownloaded())->toBeFalse()
        ->and(Frame::query()->count())->toBe(0)
        ->and(frameTmdbFiles())->toBe([]);

    Queue::assertNothingPushed();
});

test('un double envoi du même cadre ne crée qu\'une frame', function (): void {
    Queue::fake();
    frameTmdbFake();
    $movie = frameTmdbMovie();
    $curator = User::factory()->curator()->create();

    frameTmdbPost($movie, frameTmdbPayload(), $curator)->assertSessionHasNoErrors();

    // Le même envoi, une seconde fois : même visuel, même cadre. Le niveau et
    // le temps de recadrage n'y changent rien — ce seraient deux variantes
    // identiques, qui compteraient double.
    frameTmdbPost($movie, frameTmdbPayload(['frame_level' => FrameLevel::Level5->value, 'crop_seconds' => 3]), $curator)
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasErrors(['crop' => frameTmdbText('admin.frame.tmdb.duplicate')]);

    $frame = Frame::query()->sole();

    // Une ligne, un fichier, un job : le refus précède tout dépôt d'octets.
    expect($frame->frame_level)->toBe(FrameLevel::Level3)
        ->and(frameTmdbFiles())->toBe([$frame->master_path]);

    Queue::assertCount(1);

    // Un autre curateur n'y change rien non plus : le dédoublonnage porte sur
    // la banque du film, pas sur l'auteur.
    frameTmdbPost($movie, frameTmdbPayload())
        ->assertSessionHasErrors(['crop' => frameTmdbText('admin.frame.tmdb.duplicate')]);

    expect(Frame::query()->count())->toBe(1);
});

test('une même source recadrée autrement crée une seconde variante', function (): void {
    Queue::fake();
    $original = frameTmdbFake();
    $movie = frameTmdbMovie();
    $payload = frameTmdbPayload();

    frameTmdbPost($movie, $payload)->assertSessionHasNoErrors();

    // Le même visuel, un cadre décalé d'un pas : une variante légitime.
    frameTmdbPost($movie, frameTmdbPayload(['crop_x' => $payload['crop_x'] - FrameGeometry::ASPECT_WIDTH]))
        ->assertSessionHasNoErrors();

    // Et un cadre plus serré, au même coin.
    $narrower = $payload['crop_width'] - FrameGeometry::ASPECT_WIDTH;

    frameTmdbPost($movie, frameTmdbPayload([
        'crop_width' => $narrower,
        'crop_height' => intdiv($narrower * FrameGeometry::ASPECT_HEIGHT, FrameGeometry::ASPECT_WIDTH),
    ]))->assertSessionHasNoErrors();

    $frames = Frame::query()->orderBy('id')->get();

    expect($frames)->toHaveCount(3)
        ->and($frames->pluck('source_hash')->unique()->all())->toBe([hash('sha256', $original)])
        ->and($frames->map(fn (Frame $frame): string => implode(',', [$frame->crop_x, $frame->crop_y, $frame->crop_width, $frame->crop_height]))->unique())->toHaveCount(3)
        ->and($frames->pluck('master_path')->unique())->toHaveCount(3)
        ->and(frameTmdbFiles())->toHaveCount(3);

    Queue::assertCount(3);
});

test('le dédoublonnage ignore une image écartée ou retirée, jamais une image dépubliée', function (): void {
    Queue::fake();
    frameTmdbFake();
    $movie = frameTmdbMovie();

    frameTmdbPost($movie, frameTmdbPayload())->assertSessionHasNoErrors();

    // Écartée : `unpublished` sans jamais avoir été publiée (EN20-1). Pour
    // réutiliser son visuel, on l'ajoute de nouveau (§ 8.4).
    Frame::query()->sole()->forceFill([
        'availability' => ContentAvailability::Unpublished,
        'first_published_at' => null,
    ])->save();

    frameTmdbPost($movie, frameTmdbPayload())->assertSessionHasNoErrors();

    // Retirée : hors de toute banque.
    Frame::query()->latest('id')->firstOrFail()->forceFill([
        'availability' => ContentAvailability::Withdrawn,
    ])->save();

    frameTmdbPost($movie, frameTmdbPayload())->assertSessionHasNoErrors();

    // Dépubliée après avoir été en jeu : elle se republie par la file de
    // revue, et bloque donc un doublon.
    Frame::query()->latest('id')->firstOrFail()->forceFill([
        'availability' => ContentAvailability::Unpublished,
        'first_published_at' => now(),
    ])->save();

    frameTmdbPost($movie, frameTmdbPayload())
        ->assertSessionHasErrors(['crop' => frameTmdbText('admin.frame.tmdb.duplicate')]);

    expect(Frame::query()->count())->toBe(3);
});

test('le cadre est vérifié contre le plancher par la requête puis par le contrôleur, avant tout téléchargement', function (): void {
    Queue::fake();

    $wide = '/4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f90.jpg';

    frameTmdbFake(images: [
        'id' => FRAME_TMDB_ID,
        'backdrops' => [
            ...TmdbFixture::array('movie-987654-images')['backdrops'],
            // Un visuel 2:1 : son master mesure 1920 × 960.
            ['aspect_ratio' => 2.0, 'height' => 1_920, 'iso_639_1' => null, 'file_path' => $wide, 'vote_average' => 5.0, 'vote_count' => 1, 'width' => 3_840],
        ],
        'posters' => [],
        'logos' => [],
    ]);

    $movie = frameTmdbMovie();
    $limits = PlatformLimits::current();
    $default = frameTmdbPayload();
    $step = FrameGeometry::ASPECT_WIDTH;

    // La requête : ratio exact, largeur minimale, borne de largeur.
    frameTmdbPost($movie, frameTmdbPayload(['crop_height' => $default['crop_height'] - 1]))
        ->assertSessionHasErrors(['crop_height' => frameTmdbText('admin.validation.crop.aspect')]);

    frameTmdbPost($movie, frameTmdbPayload(['crop_width' => $default['crop_width'] - 1]))
        ->assertSessionHasErrors(['crop_width' => frameTmdbText('admin.validation.crop.aspect')]);

    $narrow = $limits->frameCropMinWidthPx - $step;

    frameTmdbPost($movie, frameTmdbPayload(['crop_width' => $narrow, 'crop_height' => intdiv($narrow * FrameGeometry::ASPECT_HEIGHT, $step)]))
        ->assertSessionHasErrors(['crop_width' => frameTmdbText('admin.validation.crop.too_narrow')]);

    $wider = $default['crop_width'] + $step;

    frameTmdbPost($movie, frameTmdbPayload(['crop_width' => $wider, 'crop_height' => intdiv($wider * FrameGeometry::ASPECT_HEIGHT, $step), 'crop_x' => 0, 'crop_y' => 0]))
        ->assertSessionHasErrors(['crop_width' => frameTmdbText('admin.validation.crop.too_wide')]);

    // Le contrôleur, sur la hauteur de master tirée des métadonnées TMDB :
    // le cadre le plus large admis en largeur est trop HAUT pour un master de
    // 960, et un cadre qui déborde du master est refusé.
    frameTmdbPost($movie, frameTmdbPayload(['tmdb_file_path' => $wide, 'crop_y' => 0]))
        ->assertSessionHasErrors(['crop' => frameTmdbText('admin.validation.crop.too_wide')]);

    frameTmdbPost($movie, frameTmdbPayload(['crop_x' => FrameGeometry::MASTER_WIDTH - $default['crop_width'] + $step]))
        ->assertSessionHasErrors(['crop' => frameTmdbText('admin.validation.crop.out_of_bounds')]);

    // Rien n'a été téléchargé, rien n'est écrit.
    expect(frameTmdbDownloaded())->toBeFalse()
        ->and(Frame::query()->count())->toBe(0)
        ->and(frameTmdbFiles())->toBe([]);

    // Le cadre par défaut de ce master 2:1, lui, est admis.
    $crop = FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(3_840, 1_920), $limits);

    frameTmdbPost($movie, frameTmdbPayload([
        'tmdb_file_path' => $wide,
        'crop_x' => $crop->x,
        'crop_y' => $crop->y,
        'crop_width' => $crop->width,
        'crop_height' => $crop->height,
    ]))->assertSessionHasNoErrors();

    expect(Frame::query()->count())->toBe(1);
});
