<?php

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingFailure;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Jobs\Curation\ProcessFrameImage;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Policies\FramePolicy;
use App\Settings\PlatformLimits;
use App\Support\Curation\ReviewQueue;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Frames\ImagickLimits;
use Tests\Support\Frames\SourceImages;

/*
|--------------------------------------------------------------------------
| Ajouter une variante par capture — contrat C9, spec 20 § 5.4, lot L20-33
|--------------------------------------------------------------------------
|
| La voie capture est ouverte au jalon 1 (D38 du 28/09) ; seule une valeur
| explicitement fausse de `catalog.curation.capture_enabled` la ferme, et la
| route répond alors 403 motivé par `admin.frame.capture.disabled`, rendu par
| `FramePolicy::createFromCapture` avant toute résolution de requête.
|
| Ouverte, elle reçoit ce que le navigateur a normalisé (R-46) : un WebP de
| 1920 de large sous le plafond d'entrée, son minutage `h:mm:ss` et le cadre.
| Le serveur revalide le type par le CONTENU, le poids, les dimensions réelles
| et le plancher, dépose les octets reçus sous `master_path`, puis le job les
| normalise TOUJOURS et dérive le jeu.
|
| Aucune image réelle n'entre dans le dépôt : les sources sont générées par
| Imagick au moment du test (`SourceImages`). Le disque `frames` est faux ; la
| file aussi (`Queue::fake()` en tête de test), sauf là où le test joue le
| traitement de bout en bout.
|
*/

beforeEach(function (): void {
    // Un fichier ne participe à aucune transaction : sans ce `fake`, les
    // octets provisoires partiraient dans la racine réelle du disque.
    Storage::fake(FrameStoragePrefix::DISK);

    // Les tests qui jouent le job posent les limites d'Imagick pour tout le
    // processus : elles sont relevées avant, et reposées après.
    ImagickLimits::capture();
});

afterEach(function (): void {
    ImagickLimits::restore();
});

/**
 * Un texte du back-office, résolu en français : le domaine `admin` n'existe
 * qu'en français, et la locale ambiante des tests est l'anglais.
 *
 * @param  array<string, int|string>  $replace
 */
function frameCaptureText(string $key, array $replace = []): string
{
    return (string) __($key, $replace, 'fr');
}

/**
 * Une source normalisée, telle que le navigateur l'enverrait : un WebP
 * synthétique, généré une fois par dimensions pour tout le fichier.
 */
function frameCaptureSource(int $width = 1920, int $height = 1080): string
{
    /** @var array<string, string> $sources */
    static $sources = [];

    return $sources[$width.'x'.$height] ??= SourceImages::webp($width, $height);
}

/**
 * Une capture reçue, sous le nom que le navigateur lui donne.
 */
function frameCaptureFile(string $bytes, string $name = 'capture.webp'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/**
 * Un envoi conforme : une source normalisée (WebP de 1920 de large), son
 * minutage, un niveau, le cadre par défaut du master qu'elle donne et le
 * temps de recadrage posté par le recadreur.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function frameCapturePayload(array $overrides = [], ?string $bytes = null, int $width = 1920, int $height = 1080): array
{
    $crop = FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor($width, $height), PlatformLimits::current());

    return [
        'source' => frameCaptureFile($bytes ?? frameCaptureSource($width, $height)),
        'source_timecode' => '0:12:34',
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
 * L'envoi du formulaire, posté depuis la fiche du film.
 *
 * @param  array<string, mixed>  $payload
 */
function frameCapturePost(Movie $movie, array $payload, ?User $curator = null): TestResponse
{
    return test()
        ->actingAs($curator ?? User::factory()->curator()->create())
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->post(route('admin.catalog.frames.capture.store', ['movie' => $movie->id]), $payload);
}

/**
 * Tous les fichiers du disque `frames`, triés.
 *
 * @return list<string>
 */
function frameCaptureFiles(): array
{
    $files = Storage::disk(FrameStoragePrefix::DISK)->allFiles();
    sort($files);

    return array_values($files);
}

/**
 * Aucune image, aucun octet, aucun traitement.
 */
function frameCaptureAssertNothingWritten(): void
{
    expect(Frame::query()->count())->toBe(0)
        ->and(frameCaptureFiles())->toBe([]);

    Queue::assertNothingPushed();
}

test('la voie capture désactivée refuse côté serveur', function (): void {
    Queue::fake();

    // Une valeur explicitement fausse : la voie est ouverte par défaut.
    Config::set('catalog.curation.capture_enabled', false);

    $movie = Movie::factory()->create();
    $url = route('admin.catalog.frames.capture.store', ['movie' => $movie->id]);

    foreach ([User::factory()->curator()->create(), User::factory()->admin()->create()] as $user) {
        // Un envoi complet et conforme : refusé.
        $this->actingAs($user)
            ->post($url, frameCapturePayload())
            ->assertForbidden();

        // Un envoi vide : 403 et non 422 — le refus tombe avant toute
        // validation, et il est MOTIVÉ par une clé, jamais par un texte brut.
        $this->actingAs($user)
            ->postJson($url)
            ->assertForbidden()
            ->assertJsonPath('message', FramePolicy::CAPTURE_DISABLED);

        // C'est bien la policy qui motive le refus de la garde.
        $verdict = Gate::forUser($user)->inspect('createFromCapture', [Frame::class, $movie]);

        expect($verdict->denied())->toBeTrue()
            ->and($verdict->message())->toBe(FramePolicy::CAPTURE_DISABLED);
    }

    frameCaptureAssertNothingWritten();

    // La clé existe, et le motif se lit en français.
    expect(__(FramePolicy::CAPTURE_DISABLED, [], 'fr'))->not->toBe(FramePolicy::CAPTURE_DISABLED);
});

test('une capture conforme crée une frame capture en brouillon et part en traitement', function (): void {
    Queue::fake();

    $bytes = frameCaptureSource();
    $movie = Movie::factory()->create();
    $curator = User::factory()->curator()->create();
    $payload = frameCapturePayload(bytes: $bytes);

    $response = frameCapturePost($movie, $payload, $curator)
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => frameCaptureText('admin.frame.flash.queued')]);

    $frame = Frame::query()->sole();

    // Une capture : aucun chemin TMDB, le minutage saisi en millisecondes.
    expect($frame->source_kind)->toBe(FrameSourceKind::Capture)
        ->and($frame->tmdb_file_path)->toBeNull()
        ->and($frame->source_timecode_ms)->toBe(754_000)
        ->and($frame->movie_id)->toBe($movie->id)
        ->and($frame->uploaded_by_id)->toBe($curator->id);

    // Un brouillon en traitement : aucun dérivé, jamais publié, hors de toute
    // variante jouable.
    expect($frame->availability)->toBe(ContentAvailability::Draft)
        ->and($frame->processing_state)->toBe(FrameProcessingState::Pending)
        ->and($frame->processing_error)->toBeNull()
        ->and($frame->game_path)->toBeNull()
        ->and($frame->published_hash)->toBeNull()
        ->and($frame->first_published_at)->toBeNull()
        ->and($frame->isServable())->toBeFalse();

    // Le rectangle, le niveau et le temps de recadrage postés, tels quels.
    expect($frame->frame_level)->toBe(FrameLevel::Level3)
        ->and($frame->crop_x)->toBe($payload['crop_x'])
        ->and($frame->crop_y)->toBe($payload['crop_y'])
        ->and($frame->crop_width)->toBe($payload['crop_width'])
        ->and($frame->crop_height)->toBe($payload['crop_height'])
        ->and($frame->crop_seconds)->toBe(42);

    // Les octets reçus, déposés PROVISOIREMENT sous `master_path` — le seul
    // fichier écrit par la requête —, sous un nom aléatoire du préfixe
    // `master/`, jamais sous le nom du fichier envoyé.
    expect(FrameStoragePrefix::Master->owns((string) $frame->master_path))->toBeTrue()
        ->and(Storage::disk(FrameStoragePrefix::DISK)->get((string) $frame->master_path))->toBe($bytes)
        ->and(frameCaptureFiles())->toBe([$frame->master_path])
        ->and((string) $frame->master_path)->not->toContain('capture');

    // Un seul job, pour cette frame, sur `default` — jamais `game` —, et
    // marqué pour partir après le commit.
    Queue::assertPushedOn(ProcessFrameImage::QUEUE, ProcessFrameImage::class, fn (ProcessFrameImage $job): bool => $job->frameId === $frame->id
        && $job->afterCommit === true);
    Queue::assertCount(1);

    // Ni chemin disque ni empreinte dans la réponse ou la session.
    $surfaces = [
        'corps' => (string) $response->getContent(),
        'en-têtes' => (string) json_encode($response->headers->all()),
        'session' => (string) json_encode(session()->all()),
    ];

    foreach ($surfaces as $surface => $content) {
        foreach ([(string) $frame->master_path, $frame->source_hash] as $secret) {
            expect($content)->not->toContain($secret, $surface);
        }
    }
});

test('un fichier de plus de 1 536 Ko échoue en erreur traduite et jamais en 419', function (): void {
    Queue::fake();

    // Le plafond d'entrée par défaut, sous `upload_max_filesize` (2 Mo) et
    // `post_max_size` (8 Mo) : un envoi qui le dépasse arrive entier, jeton
    // CSRF compris, et reçoit un refus traduit.
    $max = PlatformLimits::frameUploadMaxKilobytes();

    expect($max)->toBe(1_536);

    $movie = Movie::factory()->create();
    $refusal = frameCaptureText('admin.validation.frame_source.max', ['max' => $max]);

    // Le refus nomme le plafond, en français, sans substitution restée brute.
    expect($refusal)->toContain((string) $max)
        ->and($refusal)->not->toContain(':max')
        ->and($refusal)->not->toBe('admin.validation.frame_source.max');

    // Un WebP conforme en tout point, sauf son poids : un Ko de trop.
    $heavy = frameCaptureFile(frameCaptureSource())->size($max + 1);

    $response = frameCapturePost($movie, frameCapturePayload(['source' => $heavy]));

    expect($response->getStatusCode())->toBe(302);

    $response->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasErrors(['source' => $refusal]);

    // Au-delà de `upload_max_filesize`, PHP refuse lui-même le fichier
    // (`UPLOAD_ERR_INI_SIZE`) : le même refus traduit, jamais une page
    // d'erreur.
    $received = frameCaptureFile(frameCaptureSource());
    $refusedByPhp = new UploadedFile($received->getPathname(), 'capture.webp', 'image/webp', UPLOAD_ERR_INI_SIZE, true);

    $response = frameCapturePost($movie, frameCapturePayload(['source' => $refusedByPhp]));

    expect($response->getStatusCode())->toBe(302);

    $response->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasErrors(['source' => $refusal]);

    frameCaptureAssertNothingWritten();
});

test('source_hash est l\'empreinte de la source reçue', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();
    $first = frameCaptureSource();
    $second = frameCaptureSource(1920, 1072);

    // Une empreinte que le navigateur déclarerait est ignorée : elle n'est
    // jamais lue.
    frameCapturePost($movie, frameCapturePayload(['source_hash' => str_repeat('0', 64)], $first))
        ->assertSessionHasNoErrors();

    frameCapturePost($movie, frameCapturePayload(bytes: $second, height: 1072))
        ->assertSessionHasNoErrors();

    [$one, $two] = Frame::query()->orderBy('id')->get()->all();

    // Le SHA-256 des octets REÇUS, ceux-là mêmes déposés sous `master_path`.
    expect($one->source_hash)->toBe(hash('sha256', $first))
        ->and(Storage::disk(FrameStoragePrefix::DISK)->get((string) $one->master_path))->toBe($first)
        ->and($two->source_hash)->toBe(hash('sha256', $second))
        ->and(Storage::disk(FrameStoragePrefix::DISK)->get((string) $two->master_path))->toBe($second)
        ->and($one->source_hash)->not->toBe($two->source_hash);
});

test('le timecode est obligatoire pour une capture', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();
    $refusal = frameCaptureText('admin.frame.capture.timecode');

    expect($refusal)->not->toBe('admin.frame.capture.timecode');

    // Absent, vide, ou fait de blancs seuls : aucune source à déclarer en
    // revue, donc aucune image.
    $payload = frameCapturePayload();
    unset($payload['source_timecode']);

    frameCapturePost($movie, $payload)
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasErrors(['source_timecode' => $refusal]);

    foreach (['', '   '] as $blank) {
        frameCapturePost($movie, frameCapturePayload(['source_timecode' => $blank]))
            ->assertSessionHasErrors(['source_timecode' => $refusal]);
    }

    frameCaptureAssertNothingWritten();
});

test('un minutage mal formé est refusé, un minutage conforme se relit tel qu\'il a été saisi', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();
    $refusal = frameCaptureText('admin.frame.capture.timecode');

    // Minutes et secondes sur deux chiffres, de 00 à 59 ; heures sur un ou
    // deux chiffres ; rien d'autre.
    foreach (['12:34', '0:1:34', '0:60:00', '0:12:60', '123:00:00', '0:12:34.5', '-0:12:34', '0h12m34s', 'abc'] as $malformed) {
        frameCapturePost($movie, frameCapturePayload(['source_timecode' => $malformed]))
            ->assertSessionHasErrors(['source_timecode' => $refusal]);
    }

    frameCaptureAssertNothingWritten();

    // Des secondes entières × 1 000 : la revue relit exactement l'instant
    // saisi, heures sans zéro de tête. Chaque envoi décale le cadre d'un pas,
    // pour ne jamais être un doublon du précédent.
    $accepted = [
        '00:12:34' => [754_000, '0:12:34'],
        '1:02:03' => [3_723_000, '1:02:03'],
        '99:59:59' => [359_999_000, '99:59:59'],
    ];
    $default = frameCapturePayload();
    $step = 0;

    foreach ($accepted as $typed => [$milliseconds, $declared]) {
        frameCapturePost($movie, frameCapturePayload([
            'source_timecode' => $typed,
            'crop_x' => $default['crop_x'] - $step * FrameGeometry::ASPECT_WIDTH,
        ]))->assertSessionHasNoErrors();

        $frame = Frame::query()->latest('id')->firstOrFail();

        expect($frame->source_timecode_ms)->toBe($milliseconds, $typed)
            ->and(ReviewQueue::declaredSource($frame))->toBe(['kind' => FrameSourceKind::Capture->value, 'reference' => $declared]);

        $step++;
    }
});

test('une source qui n\'est pas un WebP est refusée, quel que soit son nom', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();
    $refusal = frameCaptureText('admin.validation.frame_source.mimetypes');

    expect($refusal)->not->toBe('admin.validation.frame_source.mimetypes');

    // Un PNG et un JPEG, sous leur nom.
    foreach (['capture.png' => SourceImages::png(1920, 1080), 'capture.jpg' => SourceImages::jpeg(1920, 1080)] as $name => $bytes) {
        frameCapturePost($movie, frameCapturePayload(['source' => frameCaptureFile($bytes, $name)]))
            ->assertSessionHasErrors(['source' => $refusal]);
    }

    // Le même PNG sous un nom `.webp` et un type déclaré `image/webp` : le
    // type se lit dans le CONTENU (`finfo`), jamais dans le nom ni dans la
    // déclaration du navigateur.
    $png = frameCaptureFile(SourceImages::png(1920, 1080));
    $disguised = new UploadedFile($png->getPathname(), 'capture.webp', 'image/webp', null, true);

    expect($disguised->getMimeType())->toBe('image/png');

    frameCapturePost($movie, frameCapturePayload(['source' => $disguised]))
        ->assertSessionHasErrors(['source' => $refusal]);

    // Aucun fichier : une chaîne n'est pas une capture.
    frameCapturePost($movie, frameCapturePayload(['source' => 'capture.webp']))
        ->assertSessionHasErrors('source');

    frameCaptureAssertNothingWritten();
});

test('une source trop étroite, plus large que le master ou en portrait est refusée', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();
    $refusal = frameCaptureText('admin.validation.frame_source.dimensions', ['width' => FrameGeometry::GAME_WIDTH]);

    $sources = [
        // Moins large qu'une image de jeu : elle devrait l'agrandir (requête).
        'trop étroite' => [FrameGeometry::GAME_WIDTH - FrameGeometry::ASPECT_WIDTH, 711],
        // Plus large que le master : le navigateur ne l'a pas normalisée
        // (requête).
        'plus large que le master' => [2_560, 1_440],
        // En portrait, à une largeur admise (contrôleur).
        'en portrait' => [FrameGeometry::GAME_WIDTH, 1_600],
        // Si allongée qu'aucun cadre n'y respecte le plancher (contrôleur).
        'sans cadre admis' => [FrameGeometry::MASTER_WIDTH, 200],
    ];

    foreach ($sources as $label => [$width, $height]) {
        frameCapturePost($movie, frameCapturePayload(['source' => frameCaptureFile(frameCaptureSource($width, $height))]))
            ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
            ->assertSessionHasErrors(['source' => $refusal]);
    }

    // Refusé avant tout dépôt d'octets : aucune image n'échoue plus tard au
    // job.
    frameCaptureAssertNothingWritten();
});

test('un cadre hors du plancher est refusé par la requête puis par le contrôleur', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();
    $limits = PlatformLimits::current();
    $default = frameCapturePayload();
    $step = FrameGeometry::ASPECT_WIDTH;

    // La requête : ratio exact, largeur minimale.
    frameCapturePost($movie, frameCapturePayload(['crop_height' => $default['crop_height'] - 1]))
        ->assertSessionHasErrors(['crop_height' => frameCaptureText('admin.validation.crop.aspect')]);

    $narrow = $limits->frameCropMinWidthPx - $step;

    frameCapturePost($movie, frameCapturePayload(['crop_width' => $narrow, 'crop_height' => intdiv($narrow * FrameGeometry::ASPECT_HEIGHT, $step)]))
        ->assertSessionHasErrors(['crop_width' => frameCaptureText('admin.validation.crop.too_narrow')]);

    // Le contrôleur, sur la hauteur de master des dimensions RÉELLES du
    // fichier reçu : le cadre par défaut d'un master de 1080 est trop HAUT
    // pour une capture 2:1 (master de 960), et un cadre qui déborde est
    // refusé.
    frameCapturePost($movie, frameCapturePayload(['source' => frameCaptureFile(frameCaptureSource(1920, 960)), 'crop_y' => 0]))
        ->assertSessionHasErrors(['crop' => frameCaptureText('admin.validation.crop.too_wide')]);

    frameCapturePost($movie, frameCapturePayload(['crop_x' => FrameGeometry::MASTER_WIDTH - $default['crop_width'] + $step]))
        ->assertSessionHasErrors(['crop' => frameCaptureText('admin.validation.crop.out_of_bounds')]);

    frameCaptureAssertNothingWritten();

    // Le cadre par défaut de ce master 2:1, lui, est admis.
    frameCapturePost($movie, frameCapturePayload(width: 1920, height: 960))
        ->assertSessionHasNoErrors();

    expect(Frame::query()->count())->toBe(1);
});

test('une même capture sous le même cadre ne crée qu\'une frame', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();
    $bytes = frameCaptureSource();

    frameCapturePost($movie, frameCapturePayload(bytes: $bytes))->assertSessionHasNoErrors();

    // Les mêmes octets, le même cadre, un autre fichier envoyé, un autre
    // niveau et un autre minutage : ce seraient deux variantes identiques.
    frameCapturePost($movie, frameCapturePayload([
        'frame_level' => FrameLevel::Level5->value,
        'source_timecode' => '1:00:00',
    ], $bytes))
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasErrors(['crop' => frameCaptureText('admin.frame.capture.duplicate')]);

    $frame = Frame::query()->sole();

    // Une ligne, un fichier, un job : le refus précède tout dépôt d'octets.
    expect($frame->frame_level)->toBe(FrameLevel::Level3)
        ->and(frameCaptureFiles())->toBe([$frame->master_path]);

    Queue::assertCount(1);

    // La même capture recadrée autrement reste une variante légitime.
    $payload = frameCapturePayload(bytes: $bytes);

    frameCapturePost($movie, frameCapturePayload(['crop_x' => $payload['crop_x'] - FrameGeometry::ASPECT_WIDTH], $bytes))
        ->assertSessionHasNoErrors();

    expect(Frame::query()->count())->toBe(2);
});

test('la banque d\'un film suspendu ou retiré ne reçoit aucune capture', function (): void {
    Queue::fake();

    foreach ([Movie::factory()->suspended()->create(), Movie::factory()->withdrawn()->create()] as $closed) {
        frameCapturePost($closed, frameCapturePayload())->assertForbidden();
        frameCapturePost($closed, frameCapturePayload(), User::factory()->admin()->create())->assertForbidden();
    }

    frameCaptureAssertNothingWritten();
});

test('un joueur ne franchit jamais la voie capture', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();

    frameCapturePost($movie, frameCapturePayload(), User::factory()->player()->create())
        ->assertForbidden();

    frameCaptureAssertNothingWritten();
});

test('une capture traitée devient prête sur un master renormalisé, et sa source déclarée est son minutage', function (): void {
    // La vraie file des tests (`sync`) : le job part après le commit de
    // l'ajout et traite la capture de bout en bout.
    $bytes = frameCaptureSource();
    $movie = Movie::factory()->create();

    frameCapturePost($movie, frameCapturePayload(['source_timecode' => '00:12:34'], $bytes))
        ->assertSessionHasNoErrors();

    $frame = Frame::query()->sole();
    $disk = Storage::disk(FrameStoragePrefix::DISK);

    expect($frame->processing_state)->toBe(FrameProcessingState::Ready)
        ->and($frame->processing_error)->toBeNull()
        ->and($frame->source_hash)->toBe(hash('sha256', $bytes))
        ->and($frame->game_path)->not->toBeNull();

    // Un WebP déjà à la largeur du master n'est jamais pris pour un master
    // produit : la capture est RENORMALISÉE (dépouillée, réencodée), et les
    // octets reçus ne survivent pas au traitement.
    $master = (string) $disk->get((string) $frame->master_path);
    $masterSize = getimagesizefromstring($master);

    expect(hash('sha256', $master))->not->toBe($frame->source_hash)
        ->and($masterSize)->not->toBeFalse()
        ->and([$masterSize[0], $masterSize[1]])->toBe([FrameGeometry::MASTER_WIDTH, 1080])
        ->and($masterSize['mime'])->toBe('image/webp');

    // Le dérivé servi, 1280 × 720, dérivé par le SERVEUR du master et du
    // rectangle.
    $gameSize = getimagesizefromstring((string) $disk->get((string) $frame->game_path));

    expect($gameSize)->not->toBeFalse()
        ->and([$gameSize[0], $gameSize[1]])->toBe([FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT]);

    // La source que la revue déclarera : le minutage, relu sans zéro de
    // tête aux heures.
    expect(ReviewQueue::declaredSource($frame))->toBe(['kind' => FrameSourceKind::Capture->value, 'reference' => '0:12:34']);
});

test('une capture animée est refusée dès l\'envoi, sur son seul en-tête', function (): void {
    Queue::fake();

    // Son type et ses dimensions sont ceux d'une capture : c'est le drapeau
    // d'animation de l'en-tête `VP8X` qui la refuse, avant tout décodage.
    $movie = Movie::factory()->create();

    frameCapturePost($movie, frameCapturePayload(bytes: SourceImages::animatedWebp(1920, 1080)))
        ->assertSessionHasErrors(['source' => frameCaptureText('admin.validation.frame_source.animated')]);

    // Une image fixe au même en-tête étendu passe.
    frameCapturePost($movie, frameCapturePayload())->assertSessionHasNoErrors();

    expect(Frame::query()->count())->toBe(1);
});

test('une source animée qui atteint le traitement est refusée avant d\'être décodée, et ses octets supprimés', function (): void {
    // Un envoi qui passerait la requête — ou une source TMDB animée — est
    // compté par un « ping » des en-têtes : jamais décodé image par image.
    $movie = Movie::factory()->create();
    $bytes = SourceImages::animatedWebp(1920, 1080, 12);
    $masterPath = FrameStoragePrefix::Master->newPath();

    Storage::disk(FrameStoragePrefix::DISK)->put($masterPath, $bytes);

    // Les octets reçus d'une capture ont pour empreinte `source_hash` : le
    // traitement les renormalise donc toujours.
    $frame = Frame::factory()->for($movie)->capture(754_000)->create([
        'processing_state' => FrameProcessingState::Pending,
        'processing_error' => null,
        'master_path' => $masterPath,
        'game_path' => null,
        'source_hash' => hash('sha256', $bytes),
    ]);

    ProcessFrameImage::dispatchSync($frame->id);

    $frame->refresh();

    expect($frame->processing_state)->toBe(FrameProcessingState::Failed)
        ->and($frame->processing_error)->toBe(FrameProcessingFailure::SourceAnimated)
        ->and($frame->game_path)->toBeNull()
        ->and(frameCaptureFiles())->toBe([]);
});

test('une panne d\'envoi qui n\'est pas une affaire de poids reçoit un message neutre', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();
    $received = frameCaptureFile(frameCaptureSource());

    foreach ([UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_PARTIAL] as $error) {
        $failed = new UploadedFile($received->getPathname(), 'capture.webp', 'image/webp', $error, true);

        frameCapturePost($movie, frameCapturePayload(['source' => $failed]))
            ->assertSessionHasErrors(['source' => frameCaptureText('admin.validation.frame_source.upload_failed')]);
    }

    frameCaptureAssertNothingWritten();
});
