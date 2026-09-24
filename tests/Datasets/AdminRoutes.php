<?php

use App\Enums\FrameLevel;
use App\Models\Frame;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Frames\FrameGeometry;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\TmdbFixture;
use Tests\Support\Frames\SourceImages;

/*
|--------------------------------------------------------------------------
| Matrice des capacités du back-office — spec 20 § 2.2 et § 2.9, L20-3
|--------------------------------------------------------------------------
|
| Le jeu de données UNIQUE de la famille de tests d'autorisation (décision 9) :
| une ligne par route `admin.*` enregistrée, ni plus ni moins, avec sa
| méthode, ses gardes `can:`, ses paramètres de fabrique et la réponse
| attendue pour chacun des quatre visiteurs. `AuthorizationMatrixTest` le joue
| et vérifie qu'il couvre EXACTEMENT `Route::getRoutes()` filtré sur
| `admin.*` : une route ajoutée sans sa ligne fait échouer la suite, une
| ligne sans route aussi.
|
| Les routes naissent au fil des lots (§ 2.2) ; chaque lot qui en déclare une
| ajoute ici sa ligne, avec le numéro de la ligne de la matrice qu'elle
| réalise. Deux règles communes, posées par `adminRoutesRow()` et jamais
| réécrites ligne à ligne : un invité est renvoyé vers la connexion, un
| joueur reçoit 403 avant toute résolution de modèle. La réponse d'un
| `curator` et d'un `admin` est celle d'un compte passé la porte, second
| facteur confirmé (défaut des fabriques).
|
| Les paramètres et la charge utile sont des fermetures : elles sont appelées
| dans le test, base prête, une fois par visiteur. La fermeture `redirect`
| d'une écriture est appelée APRÈS la requête et rend l'URL attendue du 302
| d'un compte privilégié : un 302 de retour arrière, message d'erreur à
| l'appui, ne passe donc jamais pour un geste autorisé.
|
*/

/**
 * Une ligne de la matrice, règles communes comprises.
 *
 * @param  int  $row  numéro de la ligne du § 2.2 que la route réalise
 * @param  'GET'|'POST'|'PUT'|'PATCH'|'DELETE'  $method
 * @param  list<string>  $guards  les gardes `can:` exactes de la route, vides pour la seule porte
 * @param  (Closure(): array<string, int|string>)|null  $parameters
 * @param  (Closure(): array<string, mixed>)|null  $payload
 * @param  (Closure(array<string, int|string>): string)|null  $redirect
 * @return array{row: int, method: string, guards: list<string>, parameters: Closure(): array<string, int|string>, payload: Closure(): array<string, mixed>, redirect: (Closure(array<string, int|string>): string)|null, responses: array{guest: 'login', player: int, curator: int, admin: int}}
 */
function adminRoutesRow(
    int $row,
    string $method,
    array $guards,
    int $curator,
    int $admin,
    ?Closure $parameters = null,
    ?Closure $payload = null,
    ?Closure $redirect = null,
): array {
    return [
        'row' => $row,
        'method' => $method,
        'guards' => $guards,
        'parameters' => $parameters ?? fn (): array => [],
        'payload' => $payload ?? fn (): array => [],
        'redirect' => $redirect,
        'responses' => [
            'guest' => 'login',
            'player' => 403,
            'curator' => $curator,
            'admin' => $admin,
        ],
    ];
}

/**
 * Chaque route `admin.*` enregistrée, par nom.
 *
 * @return array<string, array{row: int, method: string, guards: list<string>, parameters: Closure(): array<string, int|string>, payload: Closure(): array<string, mixed>, redirect: (Closure(array<string, int|string>): string)|null, responses: array{guest: 'login', player: int, curator: int, admin: int}}>
 */
function adminRoutesMatrix(): array
{
    $latestRun = fn (array $parameters): string => route('admin.import.show', [
        'importRun' => ImportRun::query()->latest('id')->value('id'),
    ]);

    return [
        // Ligne 9 — la porte seule : aucune garde `can:` (§ 2.3).
        'admin.two_factor.required' => adminRoutesRow(
            row: 9,
            method: 'GET',
            guards: [],
            curator: 200,
            admin: 200,
        ),

        // Ligne 1 — deux gardes, parce que l'écran lit deux modèles.
        'admin.dashboard' => adminRoutesRow(
            row: 1,
            method: 'GET',
            guards: ['can:viewAny,'.Movie::class, 'can:viewAny,'.ImportRun::class],
            curator: 200,
            admin: 200,
        ),

        // Ligne 2 — le catalogue, et la fiche d'un film en tout état.
        'admin.catalog.index' => adminRoutesRow(
            row: 2,
            method: 'GET',
            guards: ['can:viewAny,'.Movie::class],
            curator: 200,
            admin: 200,
        ),

        'admin.catalog.show' => adminRoutesRow(
            row: 2,
            method: 'GET',
            guards: ['can:view,movie'],
            curator: 200,
            admin: 200,
            parameters: fn (): array => ['movie' => Movie::factory()->withdrawn()->create()->getKey()],
        ),

        // Ligne 5 — l'aperçu des octets `game` et `master` (C9-bis). Une
        // image traitée, donc des octets RÉELS sur le disque `frames`, faux
        // pour toute la matrice (`beforeEach` d'`AuthorizationMatrixTest`).
        'admin.catalog.frames.game' => adminRoutesRow(
            row: 5,
            method: 'GET',
            guards: ['can:view,frame'],
            curator: 200,
            admin: 200,
            parameters: fn (): array => adminRoutesFrameParameters(),
        ),

        'admin.catalog.frames.master' => adminRoutesRow(
            row: 5,
            method: 'GET',
            guards: ['can:view,frame'],
            curator: 200,
            admin: 200,
            parameters: fn (): array => adminRoutesFrameParameters(),
        ),

        // Ligne 13 — ajouter une variante depuis TMDB (C9). La garde nomme la
        // CLASSE `Frame` : `can:create,movie` résoudrait `MoviePolicy::create()`.
        // TMDB est simulé et le traitement ne part pas (`Bus::fake()`) : le
        // 302 est l'ajout lui-même, retour à la page d'où il est posté.
        'admin.catalog.frames.tmdb.store' => adminRoutesRow(
            row: 13,
            method: 'POST',
            guards: ['can:create,'.Frame::class.',movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesTmdbFrameParameters(),
            payload: fn (): array => adminRoutesTmdbFramePayload(),
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        // Ligne 14 — la voie capture : 403 motivé tant qu'elle est fermée,
        // pour tous les rôles, avant toute résolution de requête (§ 5.4).
        'admin.catalog.frames.capture.store' => adminRoutesRow(
            row: 14,
            method: 'POST',
            guards: ['can:createFromCapture,'.Frame::class.',movie'],
            curator: 403,
            admin: 403,
            parameters: fn (): array => ['movie' => Movie::factory()->create()->getKey()],
        ),

        // Ligne 7 — l'écran d'import et le détail d'un balayage.
        'admin.import.index' => adminRoutesRow(
            row: 7,
            method: 'GET',
            guards: ['can:viewAny,'.ImportRun::class],
            curator: 200,
            admin: 200,
        ),

        'admin.import.show' => adminRoutesRow(
            row: 7,
            method: 'GET',
            guards: ['can:view,importRun'],
            curator: 200,
            admin: 200,
            // Le balayage d'un AUTRE compte : un journal de provenance se lit
            // quel qu'en soit l'auteur.
            parameters: fn (): array => [
                'importRun' => ImportRun::factory()->actedBy(User::factory()->curator()->create())->create()->getKey(),
            ],
        ),

        // Ligne 10 — balayage `discover`, collage, reprise.
        'admin.import.discover' => adminRoutesRow(
            row: 10,
            method: 'POST',
            guards: ['can:create,'.ImportRun::class],
            curator: 302,
            admin: 302,
            payload: fn (): array => ['min_votes' => 500, 'languages' => ['fr'], 'min_year' => 1970, 'pages' => 1],
            redirect: $latestRun,
        ),

        'admin.import.ids' => adminRoutesRow(
            row: 10,
            method: 'POST',
            guards: ['can:create,'.ImportRun::class],
            curator: 302,
            admin: 302,
            payload: fn (): array => ['ids' => '550'],
            redirect: $latestRun,
        ),

        'admin.import.resume' => adminRoutesRow(
            row: 10,
            method: 'POST',
            guards: ['can:update,importRun'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => ['importRun' => ImportRun::factory()->discover()->running()->create()->getKey()],
            redirect: fn (array $parameters): string => route('admin.import.show', $parameters),
        ),
    ];
}

/**
 * Un film et l'une de ses images, dérivé et master écrits sur le disque
 * `frames` : les paramètres des deux routes d'aperçu, dans l'ordre de leur
 * URL.
 *
 * @return array{movie: int, frame: int}
 */
function adminRoutesFrameParameters(): array
{
    $movie = Movie::factory()->create();
    $frame = Frame::factory()->for($movie)->withFiles()->create();

    return ['movie' => $movie->id, 'frame' => $frame->id];
}

/**
 * Un film dont TMDB propose le visuel de fixture, TMDB simulé — visuels du
 * film et original téléchargé, des octets synthétiques —, et l'envoi posté
 * depuis la fiche du film, comme depuis l'écran : le retour arrière y mène.
 *
 * @return array{movie: int}
 */
function adminRoutesTmdbFrameParameters(): array
{
    $movie = Movie::factory()->create(['tmdb_id' => 987654]);

    Http::fake([
        '*themoviedb.org/3/movie/987654/images*' => Http::response(TmdbFixture::json('movie-987654-images')),
        '*image.tmdb.org/*' => Http::response(SourceImages::jpeg(1920, 1080)),
    ]);

    test()->from(route('admin.catalog.show', ['movie' => $movie->id]));

    return ['movie' => $movie->id];
}

/**
 * Un ajout conforme : un backdrop de la fixture (1920 × 1080), un niveau, et
 * le cadre par défaut de son master.
 *
 * @return array<string, int|string>
 */
function adminRoutesTmdbFramePayload(): array
{
    $crop = FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(1920, 1080), PlatformLimits::current());

    return [
        'tmdb_file_path' => '/6a7b8c9d0e1f2a3b4c5d6e7f80912a3b.jpg',
        'frame_level' => FrameLevel::Level3->value,
        'crop_x' => $crop->x,
        'crop_y' => $crop->y,
        'crop_width' => $crop->width,
        'crop_height' => $crop->height,
    ];
}

dataset('admin.routes', function (): iterable {
    foreach (adminRoutesMatrix() as $name => $row) {
        yield $name => [$name, $row];
    }
});
