<?php

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingFailure;
use App\Http\Controllers\Admin\FrameBankController;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettingsBounds;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\TmdbFixture;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| L'éditeur de la banque d'images — spec 20 § 6, lot L20-10
|--------------------------------------------------------------------------
|
| La page s'affiche sans jamais attendre TMDB : ses visuels sont une prop
| DIFFÉRÉE, chargée par un second aller-retour que ces tests jouent
| (`loadDeferredProps`, ou une requête partielle écrite à la main quand il
| faut lire la réponse brute). TMDB est simulé de bout en bout par des
| charges utiles anonymisées : aucune image réelle, aucun appel réseau. Le
| disque `frames` est faux : les fabriques y écrivent de vrais octets.
|
*/

/** Identifiant TMDB de la fixture `movie-987654-images`. */
const FRAME_BANK_TMDB_ID = 987654;

/**
 * Les trois backdrops de la fixture, dans l'ordre de TMDB : deux sans langue
 * — l'un vide, ramené à nul —, proposés ; un anglais, écarté (D39 du 28/09).
 */
const FRAME_BANK_BACKDROP_NEUTRAL = '/6a7b8c9d0e1f2a3b4c5d6e7f80912a3b.jpg';

const FRAME_BANK_BACKDROP_EMPTY_LANGUAGE = '/7b8c9d0e1f2a3b4c5d6e7f80912a3b4c.jpg';

const FRAME_BANK_BACKDROP_ENGLISH = '/8c9d0e1f2a3b4c5d6e7f80912a3b4c5d.jpg';

beforeEach(function (): void {
    $this->withoutVite();

    // Obligatoire avant toute fixture de `frame` : sans lui, les octets
    // partent dans la racine réelle du disque `frames`.
    Storage::fake(FrameStoragePrefix::DISK);

    // TMDB configuré, jamais joint : chaque appel est simulé, et le retrait
    // exponentiel d'une panne ne coûte aucune seconde.
    Config::set('services.tmdb.read_access_token', 'fixture-token');
    Config::set('services.tmdb.api_key', null);
    Sleep::fake();

    $this->curator = User::factory()->curator()->create();
});

afterEach(function (): void {
    Sleep::fake(false);
});

/**
 * Un film du catalogue que TMDB connaît sous l'identifiant de la fixture.
 *
 * @param  array<string, mixed>  $attributes
 */
function frameBankMovie(array $attributes = []): Movie
{
    return Movie::factory()->create(['tmdb_id' => FRAME_BANK_TMDB_ID, ...$attributes]);
}

/**
 * Un visuel TMDB de charge utile, anonymisé.
 *
 * @return array<string, mixed>
 */
function frameBankImage(string $filePath, int $width, int $height, ?string $language = null): array
{
    return [
        'aspect_ratio' => $height > 0 ? round($width / $height, 3) : 0.0,
        'height' => $height,
        'iso_639_1' => $language,
        'file_path' => $filePath,
        'vote_average' => 5.0,
        'vote_count' => 1,
        'width' => $width,
    ];
}

/**
 * TMDB simulé pour les visuels du film : la fixture, une charge utile à la
 * place, ou une fermeture qui décide de la réponse à chaque appel.
 *
 * @param  array<string, mixed>|Closure|null  $images
 */
function frameBankFake(array|Closure|null $images = null): void
{
    Http::fake([
        '*themoviedb.org/3/movie/'.FRAME_BANK_TMDB_ID.'/images*' => $images instanceof Closure
            ? $images
            : Http::response($images ?? TmdbFixture::json('movie-987654-images')),
    ]);
}

/**
 * L'éditeur, ouvert par un compte privilégié.
 */
function frameBankOpen(Movie $movie, ?User $user = null): TestResponse
{
    return test()
        ->actingAs($user ?? test()->curator)
        ->get(route('admin.catalog.bank', ['movie' => $movie->id]));
}

/**
 * Un rechargement partiel de l'éditeur, écrit à la main pour lire la réponse
 * brute — ce que ferait le navigateur pour une prop différée ou facultative.
 *
 * @param  array<string, int|string>  $query
 */
function frameBankPartial(Movie $movie, string $only, array $query = []): TestResponse
{
    $page = frameBankOpen($movie)->viewData('page');

    return test()
        ->actingAs(test()->curator)
        ->get(route('admin.catalog.bank', ['movie' => $movie->id, ...$query]), [
            Header::INERTIA => 'true',
            Header::VERSION => (string) $page['version'],
            Header::PARTIAL_COMPONENT => 'admin/catalog/bank',
            Header::PARTIAL_ONLY => $only,
        ]);
}

/**
 * Un texte du back-office, résolu en français : le domaine `admin` n'existe
 * qu'en français, et la locale ambiante des tests est l'anglais.
 *
 * @param  array<string, int|string>  $replace
 */
function frameBankText(string $key, array $replace = []): string
{
    return (string) __($key, $replace, 'fr');
}

/**
 * L'URL d'aperçu du dérivé d'une frame, telle que l'éditeur la sert.
 */
function frameBankGameUrl(Frame $frame): string
{
    return route('admin.catalog.frames.game', ['movie' => $frame->movie_id, 'frame' => $frame->id]);
}

test('l\'éditeur rend le film, sa banque, ses plafonds et ses séquences par N sans jamais attendre TMDB', function (): void {
    // Tout appel TMDB serait enregistré ici : aucun ne doit partir avant
    // l'affichage.
    frameBankFake();

    [$movie] = FrameBank::movieWith(frameBankMovie(), [1, 3, 5]);
    Frame::factory()->for($movie)->level(FrameLevel::Level2)->create();

    $response = frameBankOpen($movie)->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/catalog/bank')
        ->where('movie.id', $movie->id)
        ->where('movie.title_original', $movie->title_original)
        ->where('movie.availability', ContentAvailability::Draft->value)
        ->where('movie.coverage.covers_publishable', true)
        ->where('movie.coverage.pass', 2)
        ->where('movie.coverage.target_reached', false)
        ->where('movie.has_pending', true)
        ->where('movie.processing_stalled', false)
        ->has('frames', 4)
        ->where('frames.0.frame_level', FrameLevel::Level1->value)
        ->where('frames.0.curation_state', 'in_play')
        ->where('frames.1.frame_level', FrameLevel::Level2->value)
        ->where('frames.1.curation_state', 'processing')
        ->where('limits', [
            'frameCropMaxWidthPercent' => PlatformLimits::frameCropMaxWidthPercent(),
            'frameCropMinWidthPx' => PlatformLimits::frameCropMinWidthPx(),
            'frameUploadMaxKilobytes' => PlatformLimits::frameUploadMaxKilobytes(),
        ])
        ->where('captureEnabled', true)
        ->where('pollSeconds', Config::integer('catalog.curation.poll_seconds'))
        ->where('abilities.createFrame', true)
        ->has('sequencePreview', RoomSettingsBounds::MAX_FRAMES_PER_ROUND - RoomSettingsBounds::MIN_FRAMES_PER_ROUND + 1)
        ->where('sequencePreview.0.frames_per_round', RoomSettingsBounds::MIN_FRAMES_PER_ROUND)
        ->missing('backdrops')
        ->missing('unpublish_preview'));

    // Les visuels sont différés : ils partent au second aller-retour.
    expect($response->viewData('page')['deferredProps'] ?? null)->toBe(['default' => ['backdrops']]);

    Http::assertNothingSent();

    // La couverture par niveau : jouables lus sur la projection, images en
    // route lues sur la banque, cible de la passe 2, variante unique.
    $response->assertInertia(fn (Assert $page) => $page
        ->where('movie.coverage.levels.0', [
            'level' => 1,
            'playable' => 1,
            'processing' => 0,
            'awaiting_review' => 0,
            'rejected' => 0,
            'failed' => 0,
            'target' => 2,
            'single_variant' => true,
        ])
        ->where('movie.coverage.levels.1.playable', 0)
        ->where('movie.coverage.levels.1.processing', 1)
        ->where('movie.coverage.levels.1.target', 1)
        ->where('movie.coverage.levels.1.single_variant', false));
});

test('fermer la voie capture le dit à l\'éditeur, qui ne propose alors ni envoi ni collage', function (): void {
    // L'autre moitié de la double garde : le serveur refuse la route (voir
    // FrameCaptureStoreTest), et l'écran, lui, ne propose plus le geste.
    frameBankFake();
    Config::set('catalog.curation.capture_enabled', false);

    [$movie] = FrameBank::movieWith(frameBankMovie(), [1, 3, 5]);

    frameBankOpen($movie)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/catalog/bank')
        ->where('captureEnabled', false));

    expect(__('admin.frame.capture.disabled_notice'))->not->toBe('admin.frame.capture.disabled_notice')
        ->and(__('admin.bank.description_tmdb_only'))->not->toContain('capture')
        ->and(__('admin.bank.cropper.empty_tmdb_only'))->not->toContain('capture')
        ->and(__('admin.bank.list.empty_tmdb_only'))->not->toContain('capture')
        ->and(__('admin.frame.tmdb.with_text_no_capture'))->not->toContain('capture');
});

test('les visuels proposés sont les seuls backdrops sans texte, les autres comptés', function (): void {
    $english = '/0a0b0c0d0e0f0a0b0c0d0e0f0a0b0c0d.jpg';
    $neutral = '/1a1b1c1d1e1f1a1b1c1d1e1f1a1b1c1d.jpg';
    $french = '/2a2b2c2d2e2f2a2b2c2d2e2f2a2b2c2d.jpg';
    $empty = '/3a3b3c3d3e3f3a3b3c3d3e3f3a3b3c3d.jpg';
    $poster = '/4a4b4c4d4e4f4a4b4c4d4e4f4a4b4c4d.jpg';
    $logo = '/5a5b5c5d5e5f5a5b5c5d5e5f5a5b5c5d.png';

    frameBankFake([
        'id' => FRAME_BANK_TMDB_ID,
        'backdrops' => [
            frameBankImage($english, 1920, 1080, 'en'),
            frameBankImage($neutral, 1920, 1080),
            frameBankImage($french, 1920, 1080, 'fr'),
            frameBankImage($empty, 3840, 2160, ''),
        ],
        'posters' => [frameBankImage($poster, 1000, 1500, 'fr')],
        'logos' => [frameBankImage($logo, 640, 200, 'ja')],
    ]);

    $movie = frameBankMovie();

    frameBankOpen($movie)->assertInertia(fn (Assert $page) => $page
        ->missing('backdrops')
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('backdrops.status', FrameBankController::BACKDROPS_READY)
            // Seuls les visuels sans langue — nulle ou vide — sont proposés,
            // dans l'ordre de TMDB (D39 du 28/09) : l'anglais et le français
            // peuvent contenir du texte, et l'écran dit combien sont écartés.
            ->has('backdrops.items', 2)
            ->where('backdrops.excluded', 2)
            ->where('backdrops.items.0.file_path', $neutral)
            ->where('backdrops.items.1.file_path', $empty)
            // Toujours vrai sur un visuel proposé : la marque ne part plus.
            ->missing('backdrops.items.0.language_neutral')
            // Vignette et affichage chargés depuis le serveur d'images de TMDB.
            ->where('backdrops.items.0.thumb_url', 'https://image.tmdb.org/t/p/'.FrameBankController::THUMBNAIL_SIZE.$neutral)
            ->where('backdrops.items.0.image_url', 'https://image.tmdb.org/t/p/'.FrameBankController::DISPLAY_SIZE.$neutral)
            ->where('backdrops.items.1.width', 3840)
            ->where('backdrops.items.1.height', 2160)
            // Ni affiche ni logo, jamais candidats à une image de jeu ; ni
            // visuel auquel TMDB attache une langue.
            ->where('backdrops.items', fn ($items): bool => collect($items)
                ->pluck('file_path')
                ->intersect([$poster, $logo, $english, $french])
                ->isEmpty())));

    // Un second affichage relit la liste en cache : TMDB n'est appelé qu'une
    // fois par identifiant, dans la fenêtre configurée.
    frameBankOpen($movie)->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->has('backdrops.items', 2)
            ->where('backdrops.excluded', 2)));

    Http::assertSentCount(1);

    // Le compte se lit en français, au singulier comme au pluriel.
    expect(trans_choice('admin.bank.backdrops_excluded', 1, ['count' => 1], 'fr'))->toStartWith('1 visuel écarté')
        ->and(trans_choice('admin.bank.backdrops_excluded', 2, ['count' => 2], 'fr'))->toStartWith('2 visuels écartés');
});

test('un film dont tous les visuels portent du texte montre l\'état vide et le compte', function (): void {
    frameBankFake([
        'id' => FRAME_BANK_TMDB_ID,
        'backdrops' => [
            frameBankImage('/0c0b0c0d0e0f0a0b0c0d0e0f0a0b0c0d.jpg', 1920, 1080, 'en'),
            frameBankImage('/1c1b1c1d1e1f1a1b1c1d1e1f1a1b1c1d.jpg', 1920, 1080, 'fr'),
            frameBankImage('/2c2b2c2d2e2f2a2b2c2d2e2f2a2b2c2d.jpg', 3840, 2160, 'ja'),
        ],
        // Une affiche sans langue ne compense rien : jamais candidate.
        'posters' => [frameBankImage('/3c3b3c3d3e3f3a3b3c3d3e3f3a3b3c3d.jpg', 1000, 1500)],
        'logos' => [],
    ]);

    $movie = frameBankMovie();

    // Tous écartés : l'état vide, jamais une grille vide, avec leur nombre —
    // l'écran dit pourquoi rien n'est proposé au lieu de prétendre que TMDB
    // ne fournit rien.
    frameBankOpen($movie)->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('backdrops.status', FrameBankController::BACKDROPS_EMPTY)
            ->where('backdrops.excluded', 3)
            ->where('backdrops.items', [])));

    expect(frameBankText('admin.bank.no_backdrops_all_text'))->not->toBe('admin.bank.no_backdrops_all_text')
        ->and(frameBankText('admin.bank.no_backdrops_all_text'))->not->toBe(frameBankText('admin.bank.no_backdrops'));

    // Un film que TMDB ne dote d'aucun backdrop, lui, n'a rien d'écarté.
    Http::fake([
        '*themoviedb.org/3/movie/'.(FRAME_BANK_TMDB_ID + 1).'/images*' => Http::response([
            'id' => FRAME_BANK_TMDB_ID + 1,
            'backdrops' => [],
            'posters' => [],
            'logos' => [],
        ]),
    ]);

    frameBankOpen(Movie::factory()->create(['tmdb_id' => FRAME_BANK_TMDB_ID + 1]))->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('backdrops.status', FrameBankController::BACKDROPS_EMPTY)
            ->where('backdrops.excluded', 0)
            ->where('backdrops.items', [])));
});

test('un visuel trop étroit ou en portrait est proposé désactivé avec son motif', function (): void {
    $narrow = '/6b6b6c6d6e6f6a6b6c6d6e6f6a6b6c6d.jpg';
    $portrait = '/7b7b7c7d7e7f7a7b7c7d7e7f7a7b7c7d.jpg';
    $usable = '/8b8b8c8d8e8f8a8b8c8d8e8f8a8b8c8d.jpg';
    $flat = '/9b9b9c9d9e9f9a9b9c9d9e9f9a9b9c9d.jpg';

    frameBankFake([
        'id' => FRAME_BANK_TMDB_ID,
        'backdrops' => [
            frameBankImage($narrow, FrameGeometry::GAME_WIDTH - FrameGeometry::ASPECT_WIDTH, 720),
            frameBankImage($portrait, 1080, 1920),
            frameBankImage($usable, 1920, 1080),
            // Si large et si plat qu'aucun cadre n'y tient le plancher.
            frameBankImage($flat, 1920, 300),
        ],
        'posters' => [],
        'logos' => [],
    ]);

    $movie = frameBankMovie();

    frameBankOpen($movie)->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            // Tous restent proposés, dans l'ordre : aucun n'est escamoté.
            ->has('backdrops.items', 4)
            ->where('backdrops.items.0.file_path', $narrow)
            ->where('backdrops.items.0.refusal', FrameBankController::BACKDROP_REFUSAL)
            ->where('backdrops.items.1.file_path', $portrait)
            ->where('backdrops.items.1.refusal', FrameBankController::BACKDROP_REFUSAL)
            ->where('backdrops.items.2.file_path', $usable)
            ->where('backdrops.items.2.refusal', null)
            ->where('backdrops.items.3.file_path', $flat)
            ->where('backdrops.items.3.refusal', FrameBankController::BACKDROP_REFUSAL)));

    // Le motif est un texte traduit, et c'est le refus que l'ajout opposerait
    // au même visuel, avant tout téléchargement : un seul prédicat.
    $refusal = frameBankText(FrameBankController::BACKDROP_REFUSAL, ['width' => FrameGeometry::GAME_WIDTH]);

    expect($refusal)->not->toBe(FrameBankController::BACKDROP_REFUSAL);

    $crop = FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(1920, 1080), PlatformLimits::current());

    test()->actingAs($this->curator)
        ->from(route('admin.catalog.bank', ['movie' => $movie->id]))
        ->post(route('admin.catalog.frames.tmdb.store', ['movie' => $movie->id]), [
            'tmdb_file_path' => $narrow,
            'frame_level' => FrameLevel::Level3->value,
            'crop_x' => $crop->x,
            'crop_y' => $crop->y,
            'crop_width' => $crop->width,
            'crop_height' => $crop->height,
        ])
        ->assertRedirect(route('admin.catalog.bank', ['movie' => $movie->id]))
        ->assertSessionHasErrors(['tmdb_file_path' => $refusal]);

    expect(Frame::query()->count())->toBe(0);

    // L'ajout a vérifié l'appartenance sur la liste que l'éditeur tient en
    // cache (§ 6.2) : TMDB n'a été appelé qu'une fois, à l'affichage.
    Http::assertSentCount(1);
});

test('une panne de TMDB donne un état traduit et un bouton réessayer', function (): void {
    $failing = true;
    $images = TmdbFixture::json('movie-987654-images');

    frameBankFake(function () use (&$failing, $images) {
        return $failing
            ? Http::response(TmdbFixture::json('error-500'), 500)
            : Http::response($images);
    });

    $movie = frameBankMovie();

    // La page s'affiche ; les visuels, eux, reviennent en état d'échec —
    // jamais une page d'erreur.
    $response = frameBankOpen($movie)->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('backdrops.status', FrameBankController::BACKDROPS_FAILED)
            ->where('backdrops.excluded', 0)
            ->where('backdrops.items', [])));

    // L'état se lit en français, et « Réessayer » a son libellé.
    expect(frameBankText('admin.bank.backdrops_failed'))->not->toBe('admin.bank.backdrops_failed')
        ->and(frameBankText('admin.bank.retry'))->toBe('Réessayer');

    // La panne n'est jamais mise en cache : « Réessayer » rappelle TMDB, qui
    // répond désormais.
    $failing = false;

    $response->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('backdrops.status', FrameBankController::BACKDROPS_READY)
            ->has('backdrops.items', 2)
            ->where('backdrops.excluded', 1)));
});

test('un 429 à l\'ouverture de l\'éditeur donne un message traduit et Réessayer', function (): void {
    $limited = true;
    $images = TmdbFixture::json('movie-987654-images');

    frameBankFake(function () use (&$limited, $images) {
        return $limited
            ? Http::response(TmdbFixture::json('error-429'), 429)
            : Http::response($images);
    });

    $movie = frameBankMovie();
    $response = frameBankOpen($movie)->assertOk();

    // Quota atteint sur cet appel interactif : un état, jamais un 429 ni une
    // page d'erreur rendus au curateur (§ 3.6).
    $partial = frameBankPartial($movie, 'backdrops')
        ->assertOk()
        ->assertJsonPath('props.backdrops.status', FrameBankController::BACKDROPS_RATE_LIMITED)
        ->assertJsonPath('props.backdrops.items', []);

    expect($partial->status())->toBe(200)
        ->and(frameBankText('admin.tmdb.error.rate_limited_interactive'))
        ->not->toBe('admin.tmdb.error.rate_limited_interactive')
        ->and(frameBankText('admin.bank.retry'))->toBe('Réessayer');

    // Le quota revenu, « Réessayer » obtient les visuels.
    $limited = false;

    $response->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('backdrops.status', FrameBankController::BACKDROPS_READY)));
});

test('une frame en attente au-delà du délai affiche l\'état traitement en panne', function (): void {
    $movie = frameBankMovie();
    $minutes = Config::integer('catalog.curation.stale_pending_minutes');

    // En file depuis moins longtemps que le délai : un traitement ordinaire.
    $recent = now()->subMinutes($minutes - 1);
    Frame::factory()->for($movie)->create(['created_at' => $recent, 'updated_at' => $recent]);

    // Une image en échec depuis longtemps n'est pas un traitement en panne.
    $old = now()->subMinutes($minutes + 5);
    Frame::factory()->for($movie)->processingFailed(FrameProcessingFailure::CropInvalid)
        ->create(['created_at' => $old, 'updated_at' => $old]);

    frameBankOpen($movie)->assertInertia(fn (Assert $page) => $page
        ->where('movie.has_pending', true)
        ->where('movie.processing_stalled', false));

    // Une image encore en file au-delà du délai : le traitement d'arrière-plan
    // ne répond pas, et l'écran le dit.
    $stale = now()->subMinutes($minutes + 1);
    Frame::factory()->for($movie)->create(['created_at' => $stale, 'updated_at' => $stale]);

    frameBankOpen($movie)->assertInertia(fn (Assert $page) => $page
        ->where('movie.has_pending', true)
        ->where('movie.processing_stalled', true)
        ->where('frames', fn ($frames): bool => collect($frames)
            ->where('curation_state', 'processing')
            ->count() === 2));

    expect(frameBankText('admin.bank.processing_stalled'))->not->toBe('admin.bank.processing_stalled');
});

test('un visuel déjà utilisé porte les niveaux des frames qui en proviennent', function (): void {
    frameBankFake();

    $movie = frameBankMovie();
    $factory = Frame::factory()->for($movie);

    // Trois images tirées du premier visuel, à trois niveaux, dont une en
    // jeu et une encore en traitement.
    $factory->level(FrameLevel::Level3)->published()->create(['tmdb_file_path' => FRAME_BANK_BACKDROP_NEUTRAL]);
    $factory->level(FrameLevel::Level1)->withFiles()->create(['tmdb_file_path' => FRAME_BANK_BACKDROP_NEUTRAL]);
    $factory->level(FrameLevel::Level3)->create(['tmdb_file_path' => FRAME_BANK_BACKDROP_NEUTRAL]);

    // Une image écartée et une image retirée du même visuel ne comptent pas.
    $factory->level(FrameLevel::Level5)->withFiles()->create([
        'tmdb_file_path' => FRAME_BANK_BACKDROP_NEUTRAL,
        'availability' => ContentAvailability::Unpublished,
        'availability_changed_at' => now(),
    ]);
    $factory->level(FrameLevel::Level4)->withFiles()->withdrawn()->create(['tmdb_file_path' => FRAME_BANK_BACKDROP_NEUTRAL]);

    // Une image dépubliée, elle, provient toujours de son visuel.
    $factory->level(FrameLevel::Level2)->withFiles()->create([
        'tmdb_file_path' => FRAME_BANK_BACKDROP_EMPTY_LANGUAGE,
        'availability' => ContentAvailability::Unpublished,
        'availability_changed_at' => now(),
        'first_published_at' => now()->subDay(),
    ]);

    // Une image tirée d'un visuel auquel TMDB attache une langue, ajoutée
    // avant qu'il ne soit écarté (D39 du 28/09), reste dans la banque ; son
    // visuel, lui, n'est plus proposé, et ses niveaux ne le font pas revenir.
    $factory->level(FrameLevel::Level4)->create(['tmdb_file_path' => FRAME_BANK_BACKDROP_ENGLISH]);

    frameBankOpen($movie)->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload
            ->has('backdrops.items', 2)
            ->where('backdrops.excluded', 1)
            ->where('backdrops.items.0.file_path', FRAME_BANK_BACKDROP_NEUTRAL)
            ->where('backdrops.items.0.used_levels', [1, 3])
            ->where('backdrops.items.1.file_path', FRAME_BANK_BACKDROP_EMPTY_LANGUAGE)
            ->where('backdrops.items.1.used_levels', [2])));
});

test('les séquences par N suivent FrameLevelCoverage et signalent le repli', function (): void {
    // En jeu : niveaux 1, 2, 4 et 5 — le niveau 3 manque.
    [$movie, $published] = FrameBank::publishedMovie([1, 2, 4, 5]);

    // Une seconde variante du niveau 1, plus récente : la séquence montre la
    // plus ancienne.
    $factory = Frame::factory()->for($movie);
    $factory->level(FrameLevel::Level1)->published()->create();

    // Une image de niveau 3 prête, en attente de revue : elle ne compte
    // qu'« après revue ».
    $awaiting = $factory->level(FrameLevel::Level3)->withFiles()->create();

    // Une image de niveau 3 rejetée en revue sur ses octets courants, et une
    // image écartée : ni l'une ni l'autre ne comptent, même après revue.
    $rejected = $factory->level(FrameLevel::Level3)->withFiles()->create();
    FrameReview::factory()->forFrame($rejected)->rejected()->create();
    $factory->level(FrameLevel::Level3)->withFiles()->create([
        'availability' => ContentAvailability::Unpublished,
        'availability_changed_at' => now(),
    ]);

    $inPlayMask = FrameLevelCoverage::maskOf([FrameLevel::Level1, FrameLevel::Level2, FrameLevel::Level4, FrameLevel::Level5]);
    $afterReviewMask = FrameLevelCoverage::maskOf(FrameLevel::cases());

    $levelsOf = static fn (?array $levels): array => array_map(
        static fn (FrameLevel $level): int => $level->value,
        $levels ?? [],
    );

    $response = frameBankOpen($movie);

    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $position => $framesPerRound) {
        $inPlay = FrameLevelCoverage::select($framesPerRound, $inPlayMask);
        $afterReview = FrameLevelCoverage::select($framesPerRound, $afterReviewMask);

        $response->assertInertia(fn (Assert $page) => $page
            ->where("sequencePreview.{$position}.frames_per_round", $framesPerRound)
            ->where("sequencePreview.{$position}.in_play.playable", $inPlay !== null)
            ->where("sequencePreview.{$position}.in_play.levels", $levelsOf($inPlay))
            ->where("sequencePreview.{$position}.in_play.usesFallback", FrameLevelCoverage::usesFallback($framesPerRound, $inPlayMask))
            ->has("sequencePreview.{$position}.in_play.frames", count($inPlay ?? []))
            ->where("sequencePreview.{$position}.after_review.playable", $afterReview !== null)
            ->where("sequencePreview.{$position}.after_review.levels", $levelsOf($afterReview))
            ->where("sequencePreview.{$position}.after_review.usesFallback", FrameLevelCoverage::usesFallback($framesPerRound, $afterReviewMask))
            ->has("sequencePreview.{$position}.after_review.frames", count($afterReview ?? [])));
    }

    // À N = 3 : en jeu, la séquence de référence est 1, 2 et 5, dans les
    // plages, donc sans repli (D45 du 01/10) ; après revue, la répartition
    // nominale 1, 3 et 5 revient, avec l'image en attente de revue.
    $position = 3 - RoomSettingsBounds::MIN_FRAMES_PER_ROUND;

    $response->assertInertia(fn (Assert $page) => $page
        ->where("sequencePreview.{$position}.in_play.levels", [1, 2, 5])
        ->where("sequencePreview.{$position}.in_play.usesFallback", false)
        ->where("sequencePreview.{$position}.in_play.frames.0", ['level' => 1, 'game_url' => frameBankGameUrl($published[0])])
        ->where("sequencePreview.{$position}.in_play.frames.1", ['level' => 2, 'game_url' => frameBankGameUrl($published[1])])
        ->where("sequencePreview.{$position}.in_play.frames.2", ['level' => 5, 'game_url' => frameBankGameUrl($published[3])])
        ->where("sequencePreview.{$position}.after_review.levels", [1, 3, 5])
        ->where("sequencePreview.{$position}.after_review.usesFallback", false)
        ->where("sequencePreview.{$position}.after_review.frames.1", ['level' => 3, 'game_url' => frameBankGameUrl($awaiting)]));

    // Les états affichés que la séquence lit.
    $response->assertInertia(fn (Assert $page) => $page
        ->where('frames', fn ($frames): bool => collect($frames)->firstWhere('id', $awaiting->id)['curation_state'] === 'awaiting_review'
            && collect($frames)->firstWhere('id', $rejected->id)['curation_state'] === 'rejected'));
});

test('une image en jeu rejetée en re-revue reste en jeu et porte le drapeau de rejet', function (): void {
    frameBankFake();

    [$movie, $frames] = FrameBank::publishedMovie([1, 3, 5]);

    // Re-revue sur les mêmes octets, à la version courante : rejetée. L'image
    // reste en jeu jusqu'à décision (§ 7.5), et la liste « Rejetées » la
    // compte (§ 7.3) : l'état affiché ne suffit pas à le dire.
    FrameReview::factory()->forFrame($frames[1])->rejected()->create(['reviewed_at' => now()->addSecond()]);

    frameBankOpen($movie)->assertInertia(fn (Assert $page) => $page
        ->where('frames', function ($rows) use ($frames): bool {
            $rows = collect($rows);
            $rejected = $rows->firstWhere('id', $frames[1]->id);

            return $rejected['curation_state'] === 'in_play'
                && $rejected['review_rejected'] === true
                && $rows->where('id', '!=', $frames[1]->id)->every(
                    fn (array $row): bool => $row['curation_state'] === 'in_play' && $row['review_rejected'] === false,
                );
        }));
});

test('l\'éditeur est refusé sur un film retiré', function (): void {
    $withdrawn = frameBankMovie(['availability' => ContentAvailability::Withdrawn]);

    frameBankOpen($withdrawn)->assertForbidden();
    frameBankOpen($withdrawn, User::factory()->admin()->create())->assertForbidden();

    // La fiche d'un film retiré se lit toujours, sans lien vers l'éditeur.
    test()->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $withdrawn->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('abilities.curate', false));

    // Un film suspendu s'ouvre, mais aucune image ne peut y être ajoutée.
    $suspended = Movie::factory()->suspended()->create();

    frameBankOpen($suspended)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('abilities.createFrame', false));

    // La fiche d'un film curable mène à l'éditeur.
    test()->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => Movie::factory()->create()->id]))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.curate', true));
});

test('aucune prop de l\'éditeur ne porte un chemin disque ni une empreinte', function (): void {
    frameBankFake();

    $movie = frameBankMovie();
    $factory = Frame::factory()->for($movie);

    $frames = [
        $factory->level(FrameLevel::Level1)->published()->create(),
        $factory->level(FrameLevel::Level3)->withFiles()->create(),
        $factory->level(FrameLevel::Level5)->processingFailed()->create(),
        $factory->level(FrameLevel::Level2)->create(),
    ];

    $published = $frames[0];
    FrameReview::factory()->forFrame($frames[1])->rejected()->create();

    $responses = [
        frameBankOpen($movie)->assertOk(),
        frameBankPartial($movie, 'backdrops')->assertOk(),
        frameBankPartial($movie, 'unpublish_preview', [FrameBankController::PREVIEW_FRAME_PARAMETER => $published->id])->assertOk(),
    ];

    foreach ($responses as $response) {
        foreach ($frames as $frame) {
            $frame->refresh();

            foreach ([$frame->game_path, $frame->master_path, $frame->source_hash, $frame->published_hash, $frame->tmdb_file_path] as $secret) {
                if (is_string($secret) && $secret !== '') {
                    $response->assertDontSee($secret, false);
                }
            }
        }

        foreach (['game_path', 'master_path', 'source_hash', 'published_hash', 'reviewed_hash', 'tmdb_file_path'] as $column) {
            $response->assertDontSee('"'.$column.'"', false);
        }
    }

    // L'identifiant d'une image n'adresse que le back-office : ses gestes et
    // son aperçu, jamais un chemin.
    $responses[0]->assertInertia(fn (Assert $page) => $page
        ->where('frames', fn ($rows): bool => collect($rows)->every(
            fn (array $row): bool => array_keys($row) === [
                'id',
                'frame_level',
                'availability',
                'processing_state',
                'processing_error',
                'is_retryable',
                'source_kind',
                'crop',
                'game_url',
                'master_url',
                'review_outdated',
                'curation_state',
                'review_rejected',
                'abilities',
            ],
        ))
        ->where('frames', fn ($rows): bool => collect($rows)->firstWhere('id', $published->id)['game_url'] === frameBankGameUrl($published)));
});

test('l\'avertissement de couverture est servi au seul rechargement qui ouvre la confirmation', function (): void {
    // Un film publié dont le niveau 3 ne tient qu'à une image.
    [$movie, $frames] = FrameBank::publishedMovie([1, 3, 5, 5]);

    // Absent de l'affichage ordinaire : prop facultative.
    frameBankOpen($movie)->assertInertia(fn (Assert $page) => $page->missing('unpublish_preview'));

    // Sortir la seule variante du niveau 3 casse la couverture 1-3-5 : il ne
    // reste que les niveaux 1 et 5, le film restera jouable jusqu'à N = 2.
    frameBankPartial($movie, 'unpublish_preview', [FrameBankController::PREVIEW_FRAME_PARAMETER => $frames[1]->id])
        ->assertOk()
        ->assertJsonPath('props.unpublish_preview', ['frame_id' => $frames[1]->id, 'playable_up_to' => 2]);

    // Une des deux variantes du niveau 5 : rien à annoncer.
    frameBankPartial($movie, 'unpublish_preview', [FrameBankController::PREVIEW_FRAME_PARAMETER => $frames[3]->id])
        ->assertOk()
        ->assertJsonPath('props.unpublish_preview', null);

    // L'image d'un autre film n'est pas de cette banque.
    [, $others] = FrameBank::publishedMovie([1, 3, 5]);

    frameBankPartial($movie, 'unpublish_preview', [FrameBankController::PREVIEW_FRAME_PARAMETER => $others[1]->id])
        ->assertOk()
        ->assertJsonPath('props.unpublish_preview', null);
});
