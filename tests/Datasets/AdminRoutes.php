<?php

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameLevel;
use App\Enums\Locale;
use App\Models\AdminAction;
use App\Models\Alias;
use App\Models\AudienceDaily;
use App\Models\ContentReport;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Game;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\NearMiss;
use App\Models\PerfSample;
use App\Models\Player;
use App\Models\Theme;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Catalog\AmbiguityPreview;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Curation\ExclusionGrid;
use App\Support\Curation\ReadyBatch;
use App\Support\Curation\ReviewQueue;
use App\Support\Frames\FrameGeometry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fixtures\TmdbFixture;
use Tests\Support\Frames\FrameBank;
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

        // Ligne 9 — la page de premiers pas : la porte seule, elle aussi,
        // mais derrière `admin.2fa`, comme tout le reste de l'outil.
        'admin.guide' => adminRoutesRow(
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

        // Ligne 28 — l'écran des thèmes (§ 9.6, J1 depuis D43 du 01/10). Sans
        // paramètre, l'index rend 200 : le préremplissage est facultatif et
        // n'émet jamais de 422 (C23).
        'admin.themes.index' => adminRoutesRow(
            row: 28,
            method: 'GET',
            guards: ['can:viewAny,'.Theme::class],
            curator: 200,
            admin: 200,
        ),

        // La création s'éprouve sur une nature sans dépendance au catalogue
        // (une décennie), sous un libellé anglais neuf à chaque visiteur :
        // la clé en dérive et ne se crée qu'une fois (C22).
        'admin.themes.store' => adminRoutesRow(
            row: 28,
            method: 'POST',
            guards: ['can:create,'.Theme::class],
            curator: 302,
            admin: 302,
            payload: fn (): array => adminRoutesThemePayload(['theme_kind' => 'decade', 'rule_value' => 1990]),
            redirect: fn (array $parameters): string => route('admin.themes.index'),
        ),

        'admin.themes.update' => adminRoutesRow(
            row: 28,
            method: 'PATCH',
            guards: ['can:update,theme'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => ['theme' => Theme::factory()->create()->id],
            payload: fn (): array => adminRoutesThemePayload(['rule_value' => 28, 'sort_order' => 150]),
            redirect: fn (array $parameters): string => route('admin.themes.index'),
        ),

        // Le 302 de la publication s'éprouve par une DÉPUBLICATION d'un thème
        // publié : une publication sous le seuil serait refusée (C21).
        'admin.themes.publish' => adminRoutesRow(
            row: 28,
            method: 'POST',
            guards: ['can:publish,theme'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => ['theme' => Theme::factory()->published()->create()->id],
            payload: fn (): array => ['is_published' => false],
            redirect: fn (array $parameters): string => route('admin.themes.index'),
        ),

        'admin.catalog.show' => adminRoutesRow(
            row: 2,
            method: 'GET',
            guards: ['can:view,movie'],
            curator: 200,
            admin: 200,
            parameters: fn (): array => ['movie' => Movie::factory()->withdrawn()->create()->getKey()],
        ),

        // Ligne 3 — la file de curation, et « film suivant » : une redirection
        // vers l'éditeur du premier film de la file. Un brouillon réel est
        // créé d'abord : le 302 est le geste de débit lui-même, pas le repli
        // d'une file vide.
        'admin.curation.index' => adminRoutesRow(
            row: 3,
            method: 'GET',
            guards: ['can:viewAny,'.Movie::class],
            curator: 200,
            admin: 200,
        ),

        'admin.curation.next' => adminRoutesRow(
            row: 3,
            method: 'GET',
            guards: ['can:viewAny,'.Movie::class],
            curator: 302,
            admin: 302,
            parameters: function (): array {
                Movie::factory()->create();

                return [];
            },
            redirect: fn (array $parameters): string => route('admin.catalog.bank', [
                'movie' => Movie::query()->latest('id')->value('id'),
            ]),
        ),

        // Ligne 8 — le débit de curation et le verdict du pilote : un agrégat
        // en lecture seule, curateur et au-delà.
        'admin.throughput.index' => adminRoutesRow(
            row: 8,
            method: 'GET',
            guards: ['can:viewAny,'.Movie::class],
            curator: 200,
            admin: 200,
        ),

        // Ligne 24 — le battement de débit : `MoviePolicy::curate`, réponse
        // 204 sans corps, sur un brouillon en passe 1.
        'admin.catalog.heartbeat' => adminRoutesRow(
            row: 24,
            method: 'POST',
            guards: ['can:curate,movie'],
            curator: 204,
            admin: 204,
            parameters: fn (): array => ['movie' => Movie::factory()->create()->getKey()],
        ),

        // Ligne 19 — publier un film : un brouillon PUBLIABLE — contenu
        // vérifié, niveaux 1, 3 et 5 en jeu, clés de réponse projetées — et
        // l'empreinte de l'aperçu d'ambiguïté que la confirmation montrerait.
        // Le 302 est la publication elle-même, retour à la fiche.
        'admin.catalog.publish' => adminRoutesRow(
            row: 19,
            method: 'POST',
            guards: ['can:publish,movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesPublishableParameters(),
            payload: fn (): array => [
                'ambiguity_digest' => app(AmbiguityPreview::class)
                    ->forPublication(Movie::query()->latest('id')->firstOrFail())
                    ->digest(),
            ],
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        // Ligne 20 — dépublier un film publié (ou écarter un brouillon) :
        // motif obligatoire.
        'admin.catalog.unpublish' => adminRoutesRow(
            row: 20,
            method: 'POST',
            guards: ['can:unpublish,movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesMovieGestureParameters(Movie::factory()->published()->create()),
            payload: fn (): array => ['reason' => 'Motif de la matrice.'],
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        // Ligne 30 — suspendre un film, lever sa suspension (L20-20) :
        // administrateur seul, motif facultatif. Un film suspendu sans ligne
        // au journal revient brouillon : la levée n'exige alors aucun aperçu.
        'admin.catalog.suspend' => adminRoutesRow(
            row: 30,
            method: 'POST',
            guards: ['can:suspend,movie'],
            curator: 403,
            admin: 302,
            parameters: fn (): array => adminRoutesMovieGestureParameters(Movie::factory()->published()->create()),
            payload: fn (): array => ['reason' => 'Motif de la matrice.'],
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        'admin.catalog.unsuspend' => adminRoutesRow(
            row: 30,
            method: 'POST',
            guards: ['can:unsuspend,movie'],
            curator: 403,
            admin: 302,
            parameters: fn (): array => adminRoutesMovieGestureParameters(Movie::factory()->suspended()->create()),
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        // Ligne 21 — cocher « contenu vérifié » sur un film dont la
        // classification reste à vérifier : motif obligatoire.
        'admin.catalog.content_verified' => adminRoutesRow(
            row: 21,
            method: 'POST',
            guards: ['can:verifyContent,movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesMovieGestureParameters(Movie::factory()->create()),
            payload: fn (): array => ['reason' => 'Motif de la matrice.'],
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        // Ligne 22 — corriger un titre : la ligne française d'un brouillon,
        // réécrite en correction de curateur ; puis la retirer, une ligne
        // `curator` seulement. `{locale}` est une locale activée.
        'admin.catalog.titles.update' => adminRoutesRow(
            row: 22,
            method: 'PUT',
            guards: ['can:curate,movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => [
                ...adminRoutesMovieGestureParameters(Movie::factory()->create()),
                'locale' => Locale::French->value,
            ],
            payload: fn (): array => ['title' => 'Titre de la matrice'],
            redirect: fn (array $parameters): string => route('admin.catalog.show', ['movie' => $parameters['movie']]),
        ),

        'admin.catalog.titles.destroy' => adminRoutesRow(
            row: 22,
            method: 'DELETE',
            guards: ['can:curate,movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => [
                ...adminRoutesMovieGestureParameters(
                    MovieTitle::factory()->forLocale(Locale::French)->curated()->create()->movie,
                ),
                'locale' => Locale::French->value,
            ],
            redirect: fn (array $parameters): string => route('admin.catalog.show', ['movie' => $parameters['movie']]),
        ),

        // Ligne 22 — ajouter un alias dans une locale activée, puis retirer
        // un alias, TMDB compris. L'alias est lié à son film.
        'admin.catalog.aliases.store' => adminRoutesRow(
            row: 22,
            method: 'POST',
            guards: ['can:curate,movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesMovieGestureParameters(Movie::factory()->create()),
            payload: fn (): array => ['locale' => Locale::French->value, 'alias' => 'Alias de la matrice'],
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        'admin.catalog.aliases.destroy' => adminRoutesRow(
            row: 22,
            method: 'DELETE',
            guards: ['can:curate,movie'],
            curator: 302,
            admin: 302,
            parameters: function (): array {
                $alias = Alias::factory()->tmdb()->create();

                return [
                    ...adminRoutesMovieGestureParameters($alias->movie),
                    'alias' => $alias->id,
                ];
            },
            redirect: fn (array $parameters): string => route('admin.catalog.show', ['movie' => $parameters['movie']]),
        ),

        // Ligne 46 — lots d'images (§ 5.10, D57 du 05/10) : curateur au
        // moins. Le dépôt rend l'aperçu sans appel TMDB ; l'import d'un jeton
        // inconnu de l'auteur revient à l'écran, message à l'appui, sans job.
        'admin.frame_batch.index' => adminRoutesRow(
            row: 46,
            method: 'GET',
            guards: ['can:importBatch,'.Frame::class],
            curator: 200,
            admin: 200,
        ),

        'admin.frame_batch.store' => adminRoutesRow(
            row: 46,
            method: 'POST',
            guards: ['can:importBatch,'.Frame::class],
            curator: 302,
            admin: 302,
            payload: fn (): array => [
                'batch' => UploadedFile::fake()->createWithContent('lot.json', (string) json_encode([
                    'format' => 'tripleframes.frame-batch',
                    'version' => 1,
                    'movies' => [[
                        'tmdb_id' => 987654,
                        'title' => null,
                        'frames' => [['tmdb_file_path' => '/6a7b8c9d0e1f2a3b4c5d6e7f80912a3b.jpg', 'level' => 1, 'crop' => null]],
                    ]],
                ])),
            ],
            redirect: fn (array $parameters): string => route('admin.frame_batch.index'),
        ),

        'admin.frame_batch.import' => adminRoutesRow(
            row: 46,
            method: 'POST',
            guards: ['can:importBatch,'.Frame::class],
            curator: 302,
            admin: 302,
            payload: fn (): array => ['token' => bin2hex(random_bytes(16))],
            redirect: fn (array $parameters): string => route('admin.frame_batch.index'),
        ),

        // Ligne 47 — publier les films prêts (§ 8.1 bis, D59 du 06/10) :
        // curateur au moins. Un lot changé revient à l'écran, refus à
        // l'appui, sans aucune écriture ; le 302 est ici la
        // publication d'un lot d'un film prêt, retour au catalogue.
        'admin.catalog.ready' => adminRoutesRow(
            row: 47,
            method: 'GET',
            guards: ['can:publishReady,'.Movie::class],
            curator: 200,
            admin: 200,
        ),

        'admin.catalog.ready.publish' => adminRoutesRow(
            row: 47,
            method: 'POST',
            guards: ['can:publishReady,'.Movie::class],
            curator: 302,
            admin: 302,
            payload: function (): array {
                adminRoutesPublishableParameters();
                $batch = app(ReadyBatch::class)->preview();

                return ['movie_ids' => array_column($batch['movies'], 'id'), 'ambiguity_digest' => $batch['digest']];
            },
            redirect: fn (array $parameters): string => route('admin.catalog.index'),
        ),

        // Ligne 48 — la file des signalements de contenu par les joueurs
        // (§ 11.6, D63 du 07/10) : curateur au moins ; chaque geste clôt les
        // signalements ouverts de sa cible et revient à la file.
        'admin.content-reports.index' => adminRoutesRow(
            row: 48,
            method: 'GET',
            guards: ['can:viewAny,'.ContentReport::class],
            curator: 200,
            admin: 200,
        ),

        'admin.content-reports.unpublish-movie' => adminRoutesRow(
            row: 48,
            method: 'POST',
            guards: ['can:resolve,contentReport'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => [
                'contentReport' => ContentReport::factory()->forMovie(Movie::factory()->published()->create())->create()->id,
            ],
            payload: fn (): array => ['reason' => 'Motif de la matrice.'],
            redirect: fn (array $parameters): string => route('admin.content-reports.index'),
        ),

        'admin.content-reports.unpublish-frame' => adminRoutesRow(
            row: 48,
            method: 'POST',
            guards: ['can:resolve,contentReport'],
            curator: 302,
            admin: 302,
            parameters: function (): array {
                $frame = Frame::query()->findOrFail(adminRoutesFrameGestureParameters(published: true)['frame']);

                return ['contentReport' => ContentReport::factory()->forFrame($frame)->create()->id];
            },
            redirect: fn (array $parameters): string => route('admin.content-reports.index'),
        ),

        'admin.content-reports.dismiss' => adminRoutesRow(
            row: 48,
            method: 'POST',
            guards: ['can:resolve,contentReport'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => ['contentReport' => ContentReport::factory()->create()->id],
            redirect: fn (array $parameters): string => route('admin.content-reports.index'),
        ),

        // Ligne 48, au J2 (D66 du 07/10, n° 34) — suspendre depuis la file :
        // `resolve` garde la route, et la policy de la suspension, repassée
        // sous verrou par l'action, refuse le curateur.
        'admin.content-reports.suspend-movie' => adminRoutesRow(
            row: 48,
            method: 'POST',
            guards: ['can:resolve,contentReport'],
            curator: 403,
            admin: 302,
            parameters: fn (): array => [
                'contentReport' => ContentReport::factory()->forMovie(Movie::factory()->published()->create())->create()->id,
            ],
            redirect: fn (array $parameters): string => route('admin.content-reports.index'),
        ),

        'admin.content-reports.suspend-frame' => adminRoutesRow(
            row: 48,
            method: 'POST',
            guards: ['can:resolve,contentReport'],
            curator: 403,
            admin: 302,
            parameters: function (): array {
                $frame = Frame::query()->findOrFail(adminRoutesFrameGestureParameters(published: true)['frame']);

                return ['contentReport' => ContentReport::factory()->forFrame($frame)->create()->id];
            },
            redirect: fn (array $parameters): string => route('admin.content-reports.index'),
        ),

        // Ligne 29 — films jamais trouvés et incidents (L20-29), agrégat par
        // film sans identité de joueur.
        'admin.incidents.index' => adminRoutesRow(
            row: 29,
            method: 'GET',
            guards: ['can:viewAny,'.Movie::class],
            curator: 200,
            admin: 200,
        ),

        // Ligne 27 — file agrégée de suggestions, reconstruction idempotente,
        // promotion en alias curé et rejet sans auteur.
        'admin.near_misses.index' => adminRoutesRow(
            row: 27,
            method: 'GET',
            guards: ['can:viewAny,'.NearMiss::class],
            curator: 200,
            admin: 200,
        ),

        'admin.near_misses.refresh' => adminRoutesRow(
            row: 27,
            method: 'POST',
            guards: ['can:viewAny,'.NearMiss::class],
            curator: 302,
            admin: 302,
            redirect: fn (array $parameters): string => route('admin.near_misses.index'),
        ),

        'admin.near_misses.promote' => adminRoutesRow(
            row: 27,
            method: 'POST',
            guards: ['can:promote,nearMiss'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => ['nearMiss' => NearMiss::factory()->create()->id],
            payload: fn (): array => [
                'locale' => Locale::French->value,
                'alias' => 'Alias suggéré par la matrice',
            ],
            redirect: fn (array $parameters): string => route('admin.near_misses.index'),
        ),

        'admin.near_misses.dismiss' => adminRoutesRow(
            row: 27,
            method: 'POST',
            guards: ['can:dismiss,nearMiss'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => ['nearMiss' => NearMiss::factory()->create()->id],
            redirect: fn (array $parameters): string => route('admin.near_misses.index'),
        ),

        // Ligne 23 — regrouper deux films sans groupe : le groupe naît.
        'admin.catalog.group.update' => adminRoutesRow(
            row: 23,
            method: 'PATCH',
            guards: ['can:curate,movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesMovieGestureParameters(Movie::factory()->create()),
            payload: fn (): array => ['with_movie_id' => Movie::factory()->create()->id],
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        // Ligne 28 — l'appartenance manuelle d'un film à un thème (§ 9.6,
        // D43 du 01/10) : `MoviePolicy::curate`, tout thème, publié ou non.
        'admin.catalog.themes.update' => adminRoutesRow(
            row: 28,
            method: 'PATCH',
            guards: ['can:curate,movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesMovieGestureParameters(Movie::factory()->create()),
            payload: fn (): array => ['theme_id' => Theme::factory()->create()->id, 'manual_state' => 'added'],
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        // Ligne 4 — l'éditeur de la banque d'images : `MoviePolicy::curate`,
        // curateur et au-delà, sur tout film non retiré. La page n'appelle
        // pas TMDB avant l'affichage : ses visuels sont une prop différée.
        'admin.catalog.bank' => adminRoutesRow(
            row: 4,
            method: 'GET',
            guards: ['can:curate,movie'],
            curator: 200,
            admin: 200,
            parameters: fn (): array => ['movie' => Movie::factory()->create()->getKey()],
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

        // Ligne 14 — ajouter une variante par capture (§ 5.4, L20-33) :
        // ouverte par défaut (D38 du 28/09), même seuil que la ligne 13. La
        // source est un WebP synthétique et le traitement ne part pas
        // (`Bus::fake()`) : le 302 est l'ajout lui-même, retour à la page
        // d'où il est posté. Le refus motivé d'une voie fermée est tenu par
        // les assertions de policy d'`AuthorizationMatrixTest` et par
        // `FrameCaptureStoreTest`.
        'admin.catalog.frames.capture.store' => adminRoutesRow(
            row: 14,
            method: 'POST',
            guards: ['can:createFromCapture,'.Frame::class.',movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesMovieGestureParameters(Movie::factory()->create()),
            payload: fn (): array => adminRoutesCaptureFramePayload(),
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        // Ligne 15 — re-recadrer et relancer (C9) : `FramePolicy::update`,
        // sans condition d'état. Une image publiée recadrée sort du jeu ; le
        // job ne part pas (`Bus::fake()`), le 302 est le geste lui-même.
        'admin.catalog.frames.crop.update' => adminRoutesRow(
            row: 15,
            method: 'PATCH',
            guards: ['can:update,frame'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesFrameGestureParameters(published: true),
            payload: fn (): array => adminRoutesRecropPayload(),
            redirect: fn (array $parameters): string => route('admin.catalog.show', ['movie' => $parameters['movie']]),
        ),

        'admin.catalog.frames.retry' => adminRoutesRow(
            row: 15,
            method: 'POST',
            guards: ['can:update,frame'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesFrameGestureParameters(published: false, failed: true),
            redirect: fn (array $parameters): string => route('admin.catalog.show', ['movie' => $parameters['movie']]),
        ),

        // Ligne 16 — changer le niveau d'une image publiée : elle repasse en
        // revue.
        'admin.catalog.frames.level.update' => adminRoutesRow(
            row: 16,
            method: 'PATCH',
            guards: ['can:update,frame'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesFrameGestureParameters(published: true),
            payload: fn (): array => ['frame_level' => FrameLevel::Level4->value],
            redirect: fn (array $parameters): string => route('admin.catalog.show', ['movie' => $parameters['movie']]),
        ),

        // Ligne 18 — dépublier une image publiée (ou écarter un brouillon).
        'admin.catalog.frames.unpublish' => adminRoutesRow(
            row: 18,
            method: 'POST',
            guards: ['can:unpublish,frame'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesFrameGestureParameters(published: true),
            payload: fn (): array => ['reason' => 'Motif de la matrice.'],
            redirect: fn (array $parameters): string => route('admin.catalog.show', ['movie' => $parameters['movie']]),
        ),

        // Ligne 30 — suspendre une image publiée, lever sa suspension
        // (L20-20) : administrateur seul ; la levée suppose un film qui
        // n'est pas lui-même suspendu.
        'admin.catalog.frames.suspend' => adminRoutesRow(
            row: 30,
            method: 'POST',
            guards: ['can:suspend,frame'],
            curator: 403,
            admin: 302,
            parameters: fn (): array => adminRoutesFrameGestureParameters(published: true),
            redirect: fn (array $parameters): string => route('admin.catalog.show', ['movie' => $parameters['movie']]),
        ),

        'admin.catalog.frames.unsuspend' => adminRoutesRow(
            row: 30,
            method: 'POST',
            guards: ['can:unsuspend,frame'],
            curator: 403,
            admin: 302,
            parameters: function (): array {
                $parameters = adminRoutesFrameGestureParameters(published: true);

                Frame::query()->findOrFail($parameters['frame'])
                    ->forceFill(['availability' => ContentAvailability::Suspended])
                    ->save();

                return $parameters;
            },
            redirect: fn (array $parameters): string => route('admin.catalog.show', ['movie' => $parameters['movie']]),
        ),

        // Ligne 6 — la file de revue : `can:create` sur la CLASSE
        // `FrameReview`, curateur et au-delà.
        'admin.review.index' => adminRoutesRow(
            row: 6,
            method: 'GET',
            guards: ['can:create,'.FrameReview::class],
            curator: 200,
            admin: 200,
        ),

        // Ligne 17 — passer une revue : une image prête, jamais revue, et la
        // revue conforme que l'écran enverrait. Le 302 est la publication
        // elle-même, retour à la file d'où la revue est postée.
        'admin.catalog.frames.review.store' => adminRoutesRow(
            row: 17,
            method: 'POST',
            guards: ['can:create,'.FrameReview::class],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesReviewParameters(),
            payload: fn (): array => adminRoutesReviewPayload(),
            redirect: fn (array $parameters): string => route('admin.review.index'),
        ),

        // Ligne 17 — valider en lot les images en attente d'un film (D42 du
        // 30/09, § 7.9) : même garde que la revue unitaire. Le 302 est le
        // lot validé, retour à la fiche d'où il est posté.
        'admin.catalog.frames.review_all' => adminRoutesRow(
            row: 17,
            method: 'POST',
            guards: ['can:create,'.FrameReview::class],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesBatchReviewParameters(),
            payload: fn (): array => adminRoutesBatchReviewPayload(),
            redirect: fn (array $parameters): string => route('admin.catalog.show', ['movie' => $parameters['movie']]),
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

        // Ligne 11 — l'aperçu à blanc d'un collage : aucune ligne
        // `import_run`, un job en file (`Bus::fake()`), retour à l'écran
        // d'import qui le sonde.
        'admin.import.preview' => adminRoutesRow(
            row: 11,
            method: 'POST',
            guards: ['can:create,'.ImportRun::class],
            curator: 302,
            admin: 302,
            payload: fn (): array => ['ids' => '550'],
            redirect: fn (array $parameters): string => route('admin.import.index'),
        ),

        // Ligne 11 — la liste d'amorçage : la liste de fixture porte des
        // identifiants absents du catalogue, le 302 est le collage ouvert.
        'admin.import.seed_list' => adminRoutesRow(
            row: 11,
            method: 'POST',
            guards: ['can:create,'.ImportRun::class],
            curator: 302,
            admin: 302,
            parameters: function (): array {
                Config::set('catalog.import.seed_list_path', 'tests/Fixtures/Import/seed-list.txt');

                return [];
            },
            redirect: $latestRun,
        ),

        // Ligne 12 — la recherche TMDB, à son propre limiteur. TMDB est
        // simulé : la page rend ses résultats, jamais un appel réel.
        'admin.import.search' => adminRoutesRow(
            row: 12,
            method: 'GET',
            guards: ['can:viewAny,'.ImportRun::class],
            curator: 200,
            admin: 200,
            payload: function (): array {
                Http::fake([
                    '*themoviedb.org/3/search/movie*' => Http::response(TmdbFixture::json('search-page')),
                ]);

                return ['q' => 'Orchard'];
            },
        ),

        // Ligne 40 — l'annuaire des comptes et la fiche d'un compte :
        // administrateur seul, le curateur reçoit 403 à la garde.
        'admin.users.index' => adminRoutesRow(
            row: 40,
            method: 'GET',
            guards: ['can:viewAny,'.User::class],
            curator: 403,
            admin: 200,
        ),

        'admin.users.show' => adminRoutesRow(
            row: 40,
            method: 'GET',
            guards: ['can:view,user'],
            curator: 403,
            admin: 200,
            parameters: fn (): array => ['user' => User::factory()->player()->create()->getKey()],
        ),

        // Ligne 34 — la gestion des accès : l'écran, puis ses deux gestes,
        // postés depuis la fiche du compte visé, où le retour arrière mène.
        'admin.access.index' => adminRoutesRow(
            row: 34,
            method: 'GET',
            guards: ['can:viewAny,'.User::class],
            curator: 403,
            admin: 200,
        ),

        // Attribuer `curator` à un joueur vérifié, sans nom réel : le nom
        // réel voyage dans le même formulaire (D12 du 23/09).
        'admin.access.update' => adminRoutesRow(
            row: 34,
            method: 'PATCH',
            guards: ['can:updateRole,user'],
            curator: 403,
            admin: 302,
            parameters: fn (): array => adminRoutesAccountGestureParameters(User::factory()->player()->create()),
            payload: fn (): array => ['role' => 'curator', 'real_name' => 'Nom Réel Matrice'],
            redirect: fn (array $parameters): string => route('admin.users.show', $parameters),
        ),

        // Corriger le nom réel d'un curateur.
        'admin.access.real_name.update' => adminRoutesRow(
            row: 34,
            method: 'PATCH',
            guards: ['can:updateRealName,user'],
            curator: 403,
            admin: 302,
            parameters: fn (): array => adminRoutesAccountGestureParameters(User::factory()->curator()->create()),
            payload: fn (): array => ['real_name' => 'Nom Corrigé Matrice'],
            redirect: fn (array $parameters): string => route('admin.users.show', $parameters),
        ),

        // Ligne 41 — le journal d'administration (D41 du 30/09) :
        // administrateur seul, le curateur reçoit 403 à la garde.
        'admin.journal.index' => adminRoutesRow(
            row: 41,
            method: 'GET',
            guards: ['can:viewAny,'.AdminAction::class],
            curator: 403,
            admin: 200,
        ),

        // Ligne 36 — les parties et la fiche d'une partie (D46 du 01/10) :
        // administrateur seul.
        'admin.games.index' => adminRoutesRow(
            row: 36,
            method: 'GET',
            guards: ['can:viewAny,'.Game::class],
            curator: 403,
            admin: 200,
        ),

        'admin.games.show' => adminRoutesRow(
            row: 36,
            method: 'GET',
            guards: ['can:view,game'],
            curator: 403,
            admin: 200,
            parameters: fn (): array => ['game' => Game::factory()->create()->getKey()],
        ),

        // Ligne 42 — l'annuaire des sièges et la fiche d'un siège (D46 du
        // 01/10) : administrateur seul, `{player}` lié par `public_id`.
        'admin.players.index' => adminRoutesRow(
            row: 42,
            method: 'GET',
            guards: ['can:viewAny,'.Player::class],
            curator: 403,
            admin: 200,
        ),

        'admin.players.show' => adminRoutesRow(
            row: 42,
            method: 'GET',
            guards: ['can:view,player'],
            curator: 403,
            admin: 200,
            parameters: fn (): array => ['player' => Player::factory()->create()->public_id],
        ),

        // Ligne 43 — les performances (D47 du 01/10) : administrateur seul.
        'admin.performance.index' => adminRoutesRow(
            row: 43,
            method: 'GET',
            guards: ['can:viewAny,'.PerfSample::class],
            curator: 403,
            admin: 200,
        ),

        // Ligne 44 — l'audience (D48 du 01/10) : administrateur seul.
        'admin.audience.index' => adminRoutesRow(
            row: 44,
            method: 'GET',
            guards: ['can:viewAny,'.AudienceDaily::class],
            curator: 403,
            admin: 200,
        ),

        // Ligne 51 — les statistiques de jeu (demande du porteur du 08/10) :
        // administrateur seul, agrégats sans donnée personnelle.
        'admin.game-stats.index' => adminRoutesRow(
            row: 51,
            method: 'GET',
            guards: ['can:viewStats,'.Game::class],
            curator: 403,
            admin: 200,
        ),

        // Ligne 45 — les avatars téléversés (D49 du 01/10) : administrateur
        // seul, l'image servie même masquée, deux gestes consignés.
        'admin.avatars.index' => adminRoutesRow(
            row: 45,
            method: 'GET',
            guards: ['can:moderateAvatars,'.User::class],
            curator: 403,
            admin: 200,
        ),

        'admin.avatars.image' => adminRoutesRow(
            row: 45,
            method: 'GET',
            guards: ['can:moderateAvatar,user'],
            curator: 403,
            admin: 200,
            parameters: fn (): array => ['user' => User::factory()->uploadedAvatarHidden()->create()->getKey()],
        ),

        'admin.avatars.unhide' => adminRoutesRow(
            row: 45,
            method: 'POST',
            guards: ['can:moderateAvatar,user'],
            curator: 403,
            admin: 302,
            parameters: fn (): array => ['user' => User::factory()->uploadedAvatarHidden()->create()->getKey()],
            redirect: fn (array $parameters): string => route('admin.avatars.index'),
        ),

        'admin.avatars.remove' => adminRoutesRow(
            row: 45,
            method: 'POST',
            guards: ['can:moderateAvatar,user'],
            curator: 403,
            admin: 302,
            parameters: fn (): array => ['user' => User::factory()->withUploadedAvatar()->create()->getKey()],
            payload: fn (): array => ['reason' => 'Retrait matrice'],
            redirect: fn (array $parameters): string => route('admin.avatars.index'),
        ),

        // Ligne 35 — la modération des pseudos (D66 du 07/10) : administrateur
        // seul, deux gestes consignés — lever, bannir (motif obligatoire).
        'admin.moderation.index' => adminRoutesRow(
            row: 35,
            method: 'GET',
            guards: ['can:moderateNicknames,'.Player::class],
            curator: 403,
            admin: 200,
        ),

        'admin.moderation.nickname.unmask' => adminRoutesRow(
            row: 35,
            method: 'POST',
            guards: ['can:moderateNickname,player'],
            curator: 403,
            admin: 302,
            parameters: fn (): array => ['player' => Player::factory()->masked()->create()->getKey()],
            redirect: fn (array $parameters): string => route('admin.moderation.index'),
        ),

        // Ligne 26 — resynchroniser depuis TMDB (§ 3.7, L20-24). L'écran
        // d'un film de démonstration n'appelle jamais TMDB : il le nomme
        // écarté. Le lancement ouvre un balayage `resync` et le met en file
        // (`Bus::fake()`), jamais un appel TMDB dans la requête.
        'admin.catalog.resync.show' => adminRoutesRow(
            row: 26,
            method: 'GET',
            guards: ['can:resync,'.Movie::class],
            curator: 200,
            admin: 200,
            payload: fn (): array => ['movies' => [Movie::factory()->demo()->create()->getKey()]],
        ),

        'admin.catalog.resync.store' => adminRoutesRow(
            row: 26,
            method: 'POST',
            guards: ['can:resync,'.Movie::class],
            curator: 302,
            admin: 302,
            payload: fn (): array => ['movies' => [Movie::factory()->create()->getKey()]],
            redirect: $latestRun,
        ),

        // Ligne 25 — clore un balayage suspendu (§ 3.8, L20-24) : un
        // balayage « en file », qu'aucun traitement ne tient.
        'admin.import.abandon' => adminRoutesRow(
            row: 25,
            method: 'POST',
            guards: ['can:update,importRun'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => ['importRun' => ImportRun::factory()->paste()->running()->create(['started_at' => null])->getKey()],
            redirect: fn (array $parameters): string => route('admin.import.show', $parameters),
        ),

        // Ligne 28 — corriger la difficulté d'un film (§ 9.6, L20-28b).
        'admin.catalog.difficulty.update' => adminRoutesRow(
            row: 28,
            method: 'PATCH',
            guards: ['can:curate,movie'],
            curator: 302,
            admin: 302,
            parameters: fn (): array => adminRoutesMovieGestureParameters(Movie::factory()->create()),
            payload: fn (): array => ['movie_difficulty_override' => 'hard'],
            redirect: fn (array $parameters): string => route('admin.catalog.show', $parameters),
        ),

        // Ligne 33 — le geste rétroactif de grille (§ 7.7, L20-25),
        // administrateur seul. Sous la grille v1, non rétroactive, l'envoi
        // valide revient à l'écran, qui dit « indisponible ».
        'admin.exclusion_grid.retroactive.show' => adminRoutesRow(
            row: 33,
            method: 'GET',
            guards: ['can:applyRetroactiveGrid,'.Frame::class],
            curator: 403,
            admin: 200,
        ),

        'admin.exclusion_grid.retroactive.store' => adminRoutesRow(
            row: 33,
            method: 'POST',
            guards: ['can:applyRetroactiveGrid,'.Frame::class],
            curator: 403,
            admin: 302,
            payload: fn (): array => ['version' => ExclusionGrid::CURRENT_VERSION, 'reason' => 'Motif juridique de la matrice.'],
            redirect: fn (array $parameters): string => route('admin.exclusion_grid.retroactive.show'),
        ),

        'admin.moderation.nickname.ban' => adminRoutesRow(
            row: 35,
            method: 'POST',
            guards: ['can:moderateNickname,player'],
            curator: 403,
            admin: 302,
            parameters: fn (): array => ['player' => Player::factory()->create()->getKey()],
            payload: fn (): array => ['reason' => 'Bannissement matrice'],
            redirect: fn (array $parameters): string => route('admin.moderation.index'),
        ),

        // Ligne 50 — le banc d'essai du design (demande du porteur du
        // 08/10) : administrateur seul, jamais en production (route absente).
        // Sa page fictive `design.frame` vit hors du groupe `admin.*` :
        // `DesignPreviewTest` la garde.
        'admin.design.index' => adminRoutesRow(
            row: 50,
            method: 'GET',
            guards: ['can:previewDesign,'.User::class],
            curator: 403,
            admin: 200,
        ),
    ];
}

/**
 * Un thème valide pour la création ou la correction : un libellé dans chaque
 * locale activée, l'anglais neuf à chaque appel — la clé en dérive.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function adminRoutesThemePayload(array $fields): array
{
    return [
        'labels' => [
            'fr' => 'Thème matrice',
            'en' => 'Matrix theme '.Str::lower(Str::random(10)),
        ],
        ...$fields,
    ];
}

/**
 * Un geste d'accès sur un compte, posté depuis sa fiche, où le retour arrière
 * mène.
 *
 * @return array{user: int}
 */
function adminRoutesAccountGestureParameters(User $user): array
{
    test()->from(route('admin.users.show', ['user' => $user->id]));

    return ['user' => $user->id];
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
 * Un film et l'une de ses images, pour les gestes sur une image existante :
 * publiée (dérivé, master et revue passante), ou en échec REJOUABLE (octets
 * gardés sous `master_path`). L'envoi est posté depuis la fiche du film, où
 * le retour arrière mène.
 *
 * @return array{movie: int, frame: int}
 */
function adminRoutesFrameGestureParameters(bool $published, bool $failed = false): array
{
    $movie = Movie::factory()->create();
    $factory = Frame::factory()->for($movie)->level(FrameLevel::Level3);

    $frame = match (true) {
        $failed => $factory->processingFailed()->create(),
        $published => $factory->published()->create(),
        default => $factory->withFiles()->create(),
    };

    test()->from(route('admin.catalog.show', ['movie' => $movie->id]));

    return ['movie' => $movie->id, 'frame' => $frame->id];
}

/**
 * Un geste sur un film, posté depuis sa fiche, où le retour arrière mène.
 *
 * @return array{movie: int}
 */
function adminRoutesMovieGestureParameters(Movie $movie): array
{
    test()->from(route('admin.catalog.show', ['movie' => $movie->id]));

    return ['movie' => $movie->id];
}

/**
 * Un brouillon publiable (spec 20 § 8.1) : contenu vérifié par la voie d'une
 * certification non restrictive, une image publiée à chacun des niveaux 1, 3
 * et 5 — octets sur le disque `frames`, faux pour toute la matrice —, et ses
 * clés de réponse projetées.
 *
 * @return array{movie: int}
 */
function adminRoutesPublishableParameters(): array
{
    $movie = Movie::factory()->withCertification()->contentFlag(ContentFlag::Clear)->create();

    FrameBank::movieWith($movie, [1, 3, 5]);
    (new AnswerKeyProjector)->project($movie);

    return adminRoutesMovieGestureParameters($movie);
}

/**
 * Un film et une image prête à revoir — brouillon traité, jamais revu —, la
 * revue postée depuis la file de revue, où le retour arrière mène.
 *
 * @return array{movie: int, frame: int}
 */
function adminRoutesReviewParameters(): array
{
    $movie = Movie::factory()->create();
    $frame = Frame::factory()->for($movie)->level(FrameLevel::Level3)->withFiles()->create();

    test()->from(route('admin.review.index'));

    return ['movie' => $movie->id, 'frame' => $frame->id];
}

/**
 * Un film dont une image prête attend sa revue : le lot de « Tout valider »,
 * posté depuis la fiche du film.
 *
 * @return array<string, int>
 */
function adminRoutesBatchReviewParameters(): array
{
    $movie = Movie::factory()->create();
    Frame::factory()->for($movie)->level(FrameLevel::Level3)->withFiles()->create();

    test()->from(route('admin.catalog.show', ['movie' => $movie->id]));

    return ['movie' => $movie->id];
}

/**
 * Le lot du dernier film créé, tel que la confirmation l'enverrait.
 *
 * @return array<string, mixed>
 */
function adminRoutesBatchReviewPayload(): array
{
    $movie = Movie::query()->latest('id')->firstOrFail();

    return [
        'frames' => array_map(static fn (Frame $frame): array => [
            'id' => $frame->id,
            'hash' => (string) $frame->published_hash,
        ], ReviewQueue::batchOf($movie)),
    ];
}

/**
 * La revue conforme de la dernière image créée, telle que l'écran l'enverrait.
 *
 * @return array<string, mixed>
 */
function adminRoutesReviewPayload(): array
{
    $frame = Frame::query()->latest('id')->firstOrFail();

    return [
        'grid_version' => ExclusionGrid::CURRENT_VERSION,
        'reviewed_hash' => $frame->published_hash,
        'answers' => array_fill_keys(ExclusionGrid::slugsFor($frame->frame_level), true),
        'declared_source_reference' => ReviewQueue::declaredSource($frame)['reference'],
    ];
}

/**
 * Un nouveau cadre conforme sur le master de fixture (1920 × 1080) : le cadre
 * par défaut, décalé d'un pas vers le coin haut gauche.
 *
 * @return array<string, int|string>
 */
function adminRoutesRecropPayload(): array
{
    $crop = FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(1920, 1080), PlatformLimits::current());

    return [
        'crop_x' => $crop->x - FrameGeometry::ASPECT_WIDTH,
        'crop_y' => $crop->y - FrameGeometry::ASPECT_HEIGHT,
        'crop_width' => $crop->width,
        'crop_height' => $crop->height,
        'reason' => 'Motif de la matrice.',
    ];
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

/**
 * Une capture conforme, telle que le navigateur l'enverrait : un WebP
 * synthétique déjà à la largeur du master (1920 × 1080), son minutage, un
 * niveau, et le cadre par défaut de son master.
 *
 * @return array<string, mixed>
 */
function adminRoutesCaptureFramePayload(): array
{
    $crop = FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(1920, 1080), PlatformLimits::current());

    return [
        'source' => UploadedFile::fake()->createWithContent('capture.webp', SourceImages::webp(1920, 1080)),
        'source_timecode' => '0:12:34',
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
