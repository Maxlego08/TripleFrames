<?php

use App\Enums\FrameProcessingFailure;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameImageResponse;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
|--------------------------------------------------------------------------
| Aperçu admin des octets d'une image — contrat C9-bis, spec 20 § 5.8
|--------------------------------------------------------------------------
|
| Deux routes, `admin.catalog.frames.game` et `admin.catalog.frames.master`,
| seules lectrices du disque `frames` avec `/f/{serveToken}` (E10-61), et
| jamais adressées par un `serve_token`. Leurs réponses viennent toutes de
| `FrameImageResponse`, constructeur unique partagé avec la route de jeu : ce
| sont donc SES en-têtes que ce fichier éprouve, sur le chemin de l'aperçu.
|
| Les octets sont RÉELS — un aplat WebP généré par la fabrique, jamais une
| image de jeu — sur un disque faux.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    Storage::fake(FrameStoragePrefix::DISK);
});

/**
 * Un film et une image traitée, dérivé et master écrits sur le disque.
 */
function framePreviewFrame(?Movie $movie = null): Frame
{
    return Frame::factory()
        ->for($movie ?? Movie::factory()->create())
        ->withFiles()
        ->create();
}

/**
 * L'URL d'aperçu d'une image, sous le film donné ou sous le sien.
 */
function framePreviewUrl(string $variant, Frame $frame, ?Movie $movie = null): string
{
    return route('admin.catalog.frames.'.$variant, [
        'movie' => $movie instanceof Movie ? $movie->id : $frame->movie_id,
        'frame' => $frame->id,
    ]);
}

/**
 * Les octets du disque `frames`, relus.
 */
function framePreviewBytes(string $path): string
{
    return (string) Storage::disk(FrameStoragePrefix::DISK)->get($path);
}

/**
 * En-têtes que l'aperçu ne pose jamais : chacun rétablirait une empreinte
 * réutilisable ou ferait repartir un nom de fichier.
 *
 * @return list<string>
 */
function framePreviewForbiddenHeaders(): array
{
    return ['Content-Disposition', 'Last-Modified', 'ETag', 'Accept-Ranges', 'Expires'];
}

/**
 * Le texte source d'une action de route : la méthode seule, ou la classe
 * entière ; les lignes de la fermeture pour une route anonyme.
 */
function framePreviewActionSource(RoutingRoute $route, bool $wholeClass): string
{
    $uses = $route->getAction('uses');

    if ($uses instanceof Closure) {
        $reflection = new ReflectionFunction($uses);
    } elseif (is_string($uses) && str_contains($uses, '@')) {
        [$class, $method] = explode('@', ltrim($uses, '\\'), 2);

        if (! class_exists($class)) {
            return '';
        }

        $reflection = $wholeClass || ! method_exists($class, $method)
            ? new ReflectionClass($class)
            : new ReflectionMethod($class, $method);
    } else {
        return '';
    }

    $file = $reflection->getFileName();
    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();

    if ($file === false || $start === false || $end === false) {
        return '';
    }

    $lines = file($file) ?: [];

    return implode('', array_slice($lines, $start - 1, $end - $start + 1));
}

/**
 * Vrai si ce texte source lit le préfixe `master/` ou la colonne qui y mène.
 */
function framePreviewReadsMaster(string $source): bool
{
    foreach (['FrameStoragePrefix::Master', 'master_path', "'master/'", '"master/"'] as $needle) {
        if (str_contains($source, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Chemin de répertoire comparable : séparateurs unifiés, sans barre finale.
 */
function framePreviewDirectory(string $path): string
{
    $normalized = rtrim(str_replace('\\', '/', $path), '/');

    return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
}

test('un joueur reçoit 403 et un invité est redirigé', function (): void {
    $frame = framePreviewFrame();
    $absent = ['movie' => $frame->movie_id + 1_000_000, 'frame' => $frame->id + 1_000_000];

    // Un invité est renvoyé vers la connexion, jamais vers une image.
    foreach (['game', 'master'] as $variant) {
        $this->get(framePreviewUrl($variant, $frame))->assertRedirect(route('login'));
        $this->get(route('admin.catalog.frames.'.$variant, $absent))->assertRedirect(route('login'));
    }

    // Un joueur est arrêté à la porte, AVANT toute résolution de modèle : la
    // même réponse sur un identifiant qui n'existe pas, sans quoi le couple
    // 403 / 404 énumérerait les images.
    $this->actingAs(User::factory()->player()->create());

    foreach (['game', 'master'] as $variant) {
        $this->get(framePreviewUrl($variant, $frame))->assertForbidden();
        $this->get(route('admin.catalog.frames.'.$variant, $absent))->assertForbidden();
    }
});

test('un curateur reçoit le dérivé avec no-store, noindex et nosniff', function (): void {
    $frames = [
        'brouillon' => framePreviewFrame(),
        'publiée' => Frame::factory()->for(Movie::factory()->create())->published()->create(),
    ];

    $accounts = [
        'curateur' => User::factory()->curator()->create(),
        'administrateur' => User::factory()->admin()->create(),
    ];

    foreach ($accounts as $account => $user) {
        foreach ($frames as $state => $frame) {
            $label = "{$account}, image {$state}";
            $response = $this->actingAs($user)->get(framePreviewUrl('game', $frame))->assertOk();
            $bytes = $response->streamedContent();

            expect($response->headers->get('Content-Type'))->toBe('image/webp', $label)
                ->and($response->headers->get('Content-Length'))->toBe((string) $frame->game_bytes, $label)
                ->and($response->headers->get('Cache-Control'))->toBe('no-store, private', $label)
                ->and($response->headers->all('x-robots-tag'))->toBe(['noindex, nofollow'], $label)
                ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', $label)
                ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin', $label);

            // Les octets servis aux joueurs, ceux-là mêmes que la revue hache :
            // le dérivé paddé, 1280 × 720, au multiple de 8 192.
            $size = getimagesizefromstring($bytes);

            expect($bytes)->toBe(framePreviewBytes((string) $frame->game_path), $label)
                ->and(hash('sha256', $bytes))->toBe($frame->published_hash, $label)
                ->and(strlen($bytes))->toBe($frame->game_bytes, $label)
                ->and(strlen($bytes) % FrameGeometry::GAME_PAD_BYTES)->toBe(0, $label)
                ->and($size)->toBeArray($label)
                ->and([$size[0] ?? null, $size[1] ?? null])->toBe([FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT], $label);
        }
    }
});

test('l\'aperçu ne pose ni Content-Disposition, ni Last-Modified, ni ETag', function (): void {
    $frame = framePreviewFrame();
    $curator = User::factory()->curator()->create();

    foreach (['game', 'master'] as $variant) {
        $response = $this->actingAs($curator)->get(framePreviewUrl($variant, $frame))->assertOk();

        // Un flux nu : ni `Storage::response()`, qui pose `Content-Disposition`
        // avec le nom du fichier, ni `BinaryFileResponse`, qui rétablit
        // `Last-Modified` et `ETag` (spec 10 § 10, E10-59).
        expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class, $variant)
            ->and($response->baseResponse)->not->toBeInstanceOf(BinaryFileResponse::class, $variant);

        foreach (framePreviewForbiddenHeaders() as $header) {
            expect($response->headers->has($header))->toBeFalse("{$variant} : {$header}");
        }

        // Aucun nom de fichier ne repart dans un en-tête, quel qu'il soit.
        $path = (string) ($variant === 'game' ? $frame->game_path : $frame->master_path);
        $headers = (string) json_encode($response->headers->all());

        expect($headers)->not->toContain(basename($path, '.'.FrameStoragePrefix::EXTENSION), $variant);
    }

    // La 404 d'une image encore sans dérivé : corps vide, mêmes
    // `Cache-Control` et `X-Robots-Tag`, et pas davantage d'en-tête dérivé
    // d'un fichier.
    $pending = Frame::factory()->for(Movie::factory()->create())->create();

    foreach (['game', 'master'] as $variant) {
        $missing = $this->actingAs($curator)->get(framePreviewUrl($variant, $pending))->assertNotFound();

        expect($missing->getContent())->toBe('', $variant)
            ->and($missing->headers->get('Cache-Control'))->toBe('no-store, private', $variant)
            ->and($missing->headers->all('x-robots-tag'))->toBe(['noindex, nofollow'], $variant);

        foreach (framePreviewForbiddenHeaders() as $header) {
            expect($missing->headers->has($header))->toBeFalse("404 {$variant} : {$header}");
        }
    }
});

test('une frame d\'un autre film répond 404', function (): void {
    $curator = User::factory()->curator()->create();
    $frame = framePreviewFrame();
    $other = Movie::factory()->create();

    // `scopeBindings` : l'image existe, ses octets aussi, mais elle n'est pas
    // une image de CE film. Même réponse qu'un identifiant inconnu.
    foreach (['game', 'master'] as $variant) {
        $this->actingAs($curator)->get(framePreviewUrl($variant, $frame, $other))->assertNotFound();

        $this->actingAs($curator)
            ->get(route('admin.catalog.frames.'.$variant, ['movie' => $frame->movie_id, 'frame' => $frame->id + 1_000_000]))
            ->assertNotFound();

        // Et sous son propre film, elle est bien servie.
        $this->actingAs($curator)->get(framePreviewUrl($variant, $frame))->assertOk();
    }
});

test('une frame withdrawn répond 404', function (): void {
    // Les fichiers sont encore sur le disque — le job de suppression n'est
    // pas passé — et l'aperçu ne les montre plus à personne : 404, jamais
    // 403, par `FramePolicy::view` (§ 5.8).
    $frame = Frame::factory()
        ->for(Movie::factory()->create())
        ->withFiles()
        ->withdrawn()
        ->create();

    expect(Storage::disk(FrameStoragePrefix::DISK)->exists((string) $frame->game_path))->toBeTrue()
        ->and(Storage::disk(FrameStoragePrefix::DISK)->exists((string) $frame->master_path))->toBeTrue();

    foreach ([User::factory()->curator()->create(), User::factory()->admin()->create()] as $user) {
        foreach (['game', 'master'] as $variant) {
            $response = $this->actingAs($user)->get(framePreviewUrl($variant, $frame))->assertNotFound();

            expect($response->baseResponse)->not->toBeInstanceOf(StreamedResponse::class, $variant);
        }
    }
});

test('le master n\'est servi que par admin.catalog.frames.master', function (): void {
    $curator = User::factory()->curator()->create();
    $frame = framePreviewFrame();

    // La route `master` sert la source de re-cadrage, 1920 de large…
    $master = $this->actingAs($curator)->get(framePreviewUrl('master', $frame))->assertOk();
    $masterBytes = $master->streamedContent();
    $masterSize = getimagesizefromstring($masterBytes);

    expect($masterBytes)->toBe(framePreviewBytes((string) $frame->master_path))
        ->and($master->headers->get('Content-Type'))->toBe('image/webp')
        ->and($master->headers->get('Content-Length'))->toBe((string) strlen($masterBytes))
        ->and($masterSize)->toBeArray()
        ->and($masterSize[0] ?? null)->toBe(FrameGeometry::MASTER_WIDTH);

    // … et la route `game` ne la sert jamais : elle rend le dérivé.
    $game = $this->actingAs($curator)->get(framePreviewUrl('game', $frame))->assertOk()->streamedContent();

    expect($game)->not->toBe($masterBytes)
        ->and($game)->toBe(framePreviewBytes((string) $frame->game_path));

    // Par construction : le constructeur unique refuse un chemin `master/`
    // sous le préfixe de jeu — celui de `/f/` —, et inversement.
    expect(FrameImageResponse::make(FrameStoragePrefix::Game, (string) $frame->master_path)->getStatusCode())->toBe(404)
        ->and(FrameImageResponse::make(FrameStoragePrefix::Master, (string) $frame->game_path)->getStatusCode())->toBe(404)
        ->and(FrameImageResponse::make(FrameStoragePrefix::Game, '../'.$frame->master_path)->getStatusCode())->toBe(404);

    // Avant le premier traitement réussi, `master_path` porte encore les
    // octets ORIGINAUX, ni normalisés ni dépouillés : 404, fichier présent.
    $failed = Frame::factory()
        ->for(Movie::factory()->create())
        ->processingFailed(FrameProcessingFailure::Unexpected)
        ->create();

    expect(Storage::disk(FrameStoragePrefix::DISK)->exists((string) $failed->master_path))->toBeTrue();

    $this->actingAs($curator)->get(framePreviewUrl('master', $failed))->assertNotFound();
    $this->actingAs($curator)->get(framePreviewUrl('game', $failed))->assertNotFound();

    // Et dans toute l'application, une seule ACTION de route GET lit
    // `master/` : celle-ci. « Servi » vise une réponse GET : les écritures
    // du back-office (re-recadrage, relance) peuvent nommer la colonne sans
    // rien servir, et les routes hors admin sont gardées sans filtre de
    // méthode par le test suivant.
    $readers = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (framePreviewReadsMaster(framePreviewActionSource($route, wholeClass: false))) {
            $readers[] = $route->getName() ?? $route->uri();
        }
    }

    expect($readers)->toBe(['admin.catalog.frames.master']);
});

test('aucune route hors admin ne sert le préfixe master/', function (): void {
    // Le montage natif n'existe pas pour le disque `frames` : `'serve' =>
    // false`, donc aucune route `storage.frames` — une URL signée native
    // serait liée au chemin, jamais au joueur (spec 10 § 10).
    expect(Config::get('filesystems.disks.'.FrameStoragePrefix::DISK.'.serve'))->toBeFalse()
        ->and(Route::has('storage.'.FrameStoragePrefix::DISK))->toBeFalse()
        ->and(Route::has('storage.'.FrameStoragePrefix::DISK.'.upload'))->toBeFalse();

    // Aucun disque servi, aucune cible de `storage:link` ni le répertoire
    // public ne contient la racine de `frames` : ni une route native d'un
    // autre disque, ni nginx ne peuvent y descendre.
    $frames = framePreviewDirectory((string) Config::get('filesystems.disks.'.FrameStoragePrefix::DISK.'.root'));
    $exposed = [framePreviewDirectory(public_path())];

    /** @var array<string, array<string, mixed>> $disks */
    $disks = Config::get('filesystems.disks', []);

    foreach ($disks as $name => $disk) {
        if ($name !== FrameStoragePrefix::DISK && ($disk['driver'] ?? null) === 'local' && ($disk['serve'] ?? false) === true) {
            $exposed[] = framePreviewDirectory((string) ($disk['root'] ?? ''));
        }
    }

    /** @var array<string, string> $links */
    $links = Config::get('filesystems.links', []);

    foreach ($links as $target) {
        $exposed[] = framePreviewDirectory($target);
    }

    expect($frames)->not->toBe('');

    foreach ($exposed as $root) {
        expect($frames === $root || str_starts_with($frames, $root.'/'))->toBeFalse("racine exposée : {$root}");
    }

    // Aucune route hors `/admin` — contrôleur entier, ou fermeture — ne
    // nomme le préfixe `master/` ni la colonne qui y mène.
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = $route->getName();

        if (is_string($name) && str_starts_with($name, 'admin.')) {
            continue;
        }

        $checked++;

        if (framePreviewReadsMaster(framePreviewActionSource($route, wholeClass: true))) {
            $violations[] = $name ?? $route->uri();
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($violations)->toBe([]);

    // Et le détecteur n'est pas muet : il voit bien la route qui lit `master/`.
    $masterRoute = Route::getRoutes()->getByName('admin.catalog.frames.master');

    expect($masterRoute)->toBeInstanceOf(RoutingRoute::class)
        ->and(framePreviewReadsMaster(framePreviewActionSource($masterRoute, wholeClass: false)))->toBeTrue();

    // Le seul préfixe qu'une route joueur puisse demander au constructeur
    // unique refuse tout chemin `master/`, fichier présent ou non.
    $frame = framePreviewFrame();

    expect(FrameStoragePrefix::Game->owns((string) $frame->master_path))->toBeFalse()
        ->and(FrameImageResponse::make(FrameStoragePrefix::Game, (string) $frame->master_path)->getStatusCode())->toBe(404);
});

test('un fichier absent répond la même 404 et se journalise sans chemin', function (): void {
    $curator = User::factory()->curator()->create();
    $frame = framePreviewFrame();

    $channel = Mockery::spy(LoggerInterface::class);
    Log::spy()->shouldReceive('channel')->with('game')->andReturn($channel);

    Storage::disk(FrameStoragePrefix::DISK)->delete((string) $frame->game_path);

    $response = $this->actingAs($curator)->get(framePreviewUrl('game', $frame))->assertNotFound();

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->all('x-robots-tag'))->toBe(['noindex, nofollow']);

    $channel->shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $context === ['prefix' => FrameStoragePrefix::Game->value]
            && ! str_contains((string) json_encode($context), (string) $frame->game_path))
        ->once();
});
