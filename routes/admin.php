<?php

use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FrameBankController;
use App\Http\Controllers\Admin\FrameCaptureController;
use App\Http\Controllers\Admin\FrameCropController;
use App\Http\Controllers\Admin\FrameImageController;
use App\Http\Controllers\Admin\FrameLevelController;
use App\Http\Controllers\Admin\FrameRetryController;
use App\Http\Controllers\Admin\FrameReviewController;
use App\Http\Controllers\Admin\FrameReviewQueueController;
use App\Http\Controllers\Admin\FrameTmdbController;
use App\Http\Controllers\Admin\FrameUnpublishController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\ImportDiscoverController;
use App\Http\Controllers\Admin\ImportIdsController;
use App\Http\Controllers\Admin\ImportResumeController;
use App\Http\Controllers\Admin\TwoFactorRequiredController;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\ImportRun;
use App\Models\Movie;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Back-office — la porte `/admin` (spec 20 § 2.3)
|--------------------------------------------------------------------------
|
| Cinq middlewares sur le groupe, dans cet ordre, et pas un de plus :
|
| - `auth` et `verified` : le panneau est derrière un compte vérifié ;
| - `role:curator` (`EnsureUserHasRole`) garde la PORTE, là où les `can:` posés
|   route par route gardent la RESSOURCE. Il est prioritaire sur
|   `SubstituteBindings` : sans lui, `/admin/catalog/{movie}` répondrait 404 à un
|   joueur sur un identifiant inconnu contre 403 sur un identifiant réel, et le
|   simple couple de codes de statut énumérerait la table `movie` ;
| - `admin.2fa` (`EnsurePrivilegedTwoFactor`) ferme la porte à tout compte
|   privilégié — `curator` ET `admin` — dont le second facteur n'est pas
|   CONFIRMÉ, et le renvoie par une redirection 303 vers l'écran d'enrôlement
|   `admin.two_factor.required` : jamais un 403 muet, et jamais une écriture
|   exécutée (spec 20 § 2.4, `10` A16). Il vient APRÈS `role`, pour qu'un
|   joueur reçoive 403 avant toute redirection qui confirmerait l'existence de
|   la porte, et il rejoint `role` en tête de la liste de priorité, avant
|   `SubstituteBindings`, pour la même raison d'énumération
|   (`bootstrap/app.php`). L'écran d'enrôlement en est retiré par
|   `withoutMiddleware`, sans quoi la porte se renverrait à elle-même ;
| - `admin.locale` (`ForceAdminLocale`) force `Locale::French` ET appelle déjà
|   `TranslationDomains::need('admin')`. **Ne jamais ajouter `translations:admin`
|   ici** : ce serait un doublon. À noter pour les écrans — `TranslationDomains::selected()`
|   rend `['admin']` SEUL dès que le domaine `admin` est demandé, `common` n'est
|   donc PAS joint : toute clé appelée par une page d'administration vit dans
|   `lang/fr/admin.php`, pied de page compris (`admin.footer.*`, jamais `legal`).
|
| **Aucun forçage d'apparence** : le back-office suit l'apparence choisie par
| le visiteur (D8 du 23/09, spec 90 § 2.2). Seuls les cadres de revue et de
| prévisualisation d'image passent en sombre, localement, sous les tokens du
| jeu (spec 20 § 6.7) — jamais le document entier.
|
| **L'autorisation est posée route par route par `can:`, jamais par un test de
| rôle dans un contrôleur.** Deux routes seulement n'en portent pas, parce que
| la porte suffit à les garder : l'écran d'enrôlement
| (`admin.two_factor.required`) et, au lot L20-18, la page de premiers pas
| (`admin.guide`). Les policies vivent dans `app/Policies/` et sont
| auto-découvertes par convention `App\Models\X` → `App\Policies\XPolicy` :
| aucun enregistrement de provider, et surtout aucun `Gate::before` — il
| contournerait la propriété d'une `saved_config`, déclarée strictement privée.
|
| **Toute route ajoutée ici prend sa ligne dans `tests/Datasets/AdminRoutes.php`**,
| la matrice des capacités de la spec 20 § 2.2 : `AuthorizationMatrixTest`
| refuse une route `admin.*` sans ligne, une ligne sans route, et une garde
| `can:` autre que celle que la ligne écrit.
|
| Les trois routes d'écriture de l'import portent en plus
| `throttle:admin-import` ; les écritures d'image qui téléchargent un original
| ou distribuent un job Imagick — ajout, re-recadrage, relance —
| `throttle:admin-frame` (C9 § 2) ; les gestes de curation qui n'écrivent
| qu'en base — changer un niveau, passer une revue, dépublier ou écarter
| une image — `throttle:admin-curation` (§ 13.7). Limiteurs nommés déclarés dans
| `FortifyServiceProvider::configureRateLimiting()`, là où vivent déjà
| `login`, `two-factor` et `passkeys`.
|
*/

Route::middleware(['auth', 'verified', 'role:curator', 'admin.2fa', 'admin.locale'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        // L'écran d'enrôlement : hors de `admin.2fa`, sans `can:` — la porte
        // (`auth`, `verified`, `role:curator`) le garde seule, et un joueur y
        // reçoit 403 comme partout ailleurs sous `/admin`.
        Route::get('two-factor', [TwoFactorRequiredController::class, 'show'])
            ->withoutMiddleware('admin.2fa')
            ->name('two_factor.required');

        // DEUX gardes, parce que l'écran sert DEUX modèles : les compteurs de
        // catalogue et les cinq derniers `import_run` avec leur auteur, leur
        // filtre figé et leurs quatre compteurs — exactement la charge utile
        // que `admin.import.index` protège par `viewAny` sur `ImportRun`. Les
        // deux seuils coïncident aujourd'hui ; le jour où la spec 20 les
        // sépare, la garde nomme déjà les deux modèles réellement lus.
        Route::get('/', [DashboardController::class, 'index'])
            ->middleware(['can:viewAny,'.Movie::class, 'can:viewAny,'.ImportRun::class])
            ->name('dashboard');

        Route::get('catalog', [CatalogController::class, 'index'])
            ->middleware('can:viewAny,'.Movie::class)
            ->name('catalog.index');

        Route::get('catalog/{movie}', [CatalogController::class, 'show'])
            ->middleware('can:view,movie')
            ->name('catalog.show');

        // L'éditeur de la banque d'images (§ 6, ligne 4) : `MoviePolicy::curate`,
        // refusé sur un film retiré. Il n'écrit rien : chaque geste qu'il
        // offre a sa route ci-dessous, sa garde et son limiteur. Les visuels
        // TMDB y sont une prop différée : la page n'attend jamais TMDB.
        Route::get('catalog/{movie}/bank', [FrameBankController::class, 'show'])
            ->middleware('can:curate,movie')
            ->name('catalog.bank');

        // Aperçu des octets d'une image (C9-bis, § 5.8, ligne 5) : seul second
        // lecteur du disque `frames` avec `/f/{serveToken}`, jamais adressé par
        // un `serve_token`. `scopeBindings` : une frame d'un AUTRE film répond
        // 404 avant la policy, qui n'a donc jamais à comparer `movie_id`. Une
        // frame `withdrawn` répond 404 aussi, par `FramePolicy::view`. Le
        // préfixe `master/` n'est lu que par `admin.catalog.frames.master`.
        Route::scopeBindings()->group(function (): void {
            Route::get('catalog/{movie}/frames/{frame}/game', [FrameImageController::class, 'game'])
                ->middleware('can:view,frame')
                ->name('catalog.frames.game');

            Route::get('catalog/{movie}/frames/{frame}/master', [FrameImageController::class, 'master'])
                ->middleware('can:view,frame')
                ->name('catalog.frames.master');

            // Ajouter une variante depuis TMDB (C9, § 5.3, ligne 13). La garde
            // nomme la CLASSE en premier argument : écrite `can:create,movie`,
            // elle résoudrait `MoviePolicy::create()`, qui refuse toujours, et
            // toute la voie TMDB répondrait 403 (V-17).
            Route::post('catalog/{movie}/frames/tmdb', [FrameTmdbController::class, 'store'])
                ->middleware(['can:create,'.Frame::class.',movie', 'throttle:admin-frame'])
                ->name('catalog.frames.tmdb.store');

            // Voie capture (§ 5.4, ligne 14) : au jalon 1, la route existe pour
            // REFUSER — 403 motivé par `admin.frame.capture.disabled`, rendu
            // par la garde avant toute résolution de requête. La branche qui
            // accepte un fichier arrive avec le lot L20-33.
            Route::post('catalog/{movie}/frames/capture', [FrameCaptureController::class, 'store'])
                ->middleware(['can:createFromCapture,'.Frame::class.',movie', 'throttle:admin-frame'])
                ->name('catalog.frames.capture.store');

            // Re-recadrer, en place, et relancer un traitement (C9, § 5.6,
            // § 5.7, ligne 15). `FramePolicy::update` n'a AUCUNE condition
            // d'état : une image en traitement, sans rendu, suspendue ou
            // retirée se refuse par une erreur traduite, jamais par un 403.
            // Même limiteur que l'ajout : chacun distribue un job Imagick.
            Route::patch('catalog/{movie}/frames/{frame}/crop', [FrameCropController::class, 'update'])
                ->middleware(['can:update,frame', 'throttle:admin-frame'])
                ->name('catalog.frames.crop.update');

            Route::post('catalog/{movie}/frames/{frame}/retry', [FrameRetryController::class, 'store'])
                ->middleware(['can:update,frame', 'throttle:admin-frame'])
                ->name('catalog.frames.retry');

            // Changer le niveau d'une image (§ 5.7, ligne 16) : une image
            // publiée sort du jeu et repasse en revue.
            Route::patch('catalog/{movie}/frames/{frame}/level', [FrameLevelController::class, 'update'])
                ->middleware(['can:update,frame', 'throttle:admin-curation'])
                ->name('catalog.frames.level.update');

            // Dépublier une image publiée, ou écarter une image jamais publiée
            // (§ 8.4, ligne 18). La garde, elle, porte l'état : `draft` ou
            // `published` seulement — suspendre et retirer appartiennent à
            // l'administrateur.
            Route::post('catalog/{movie}/frames/{frame}/unpublish', [FrameUnpublishController::class, 'store'])
                ->middleware(['can:unpublish,frame', 'throttle:admin-curation'])
                ->name('catalog.frames.unpublish');

            // Passer une revue (§ 7.5, ligne 17) : une revue passante PUBLIE
            // l'image. La garde nomme la CLASSE `FrameReview` — la revue
            // n'existe pas encore. Les refus d'état (image verrouillée,
            // octets ou niveau changés, image déjà jugée) sont des erreurs
            // traduites relues sous le verrou, jamais des 403. Aucune route
            // ne modifie ni ne supprime une revue (§ 7.8, ligne 38).
            Route::post('catalog/{movie}/frames/{frame}/review', [FrameReviewController::class, 'store'])
                ->middleware(['can:create,'.FrameReview::class, 'throttle:admin-curation'])
                ->name('catalog.frames.review.store');
        });

        // La file de revue (§ 7.3, ligne 6) : même garde que la revue — lire
        // la file, c'est déjà s'apprêter à revoir.
        Route::get('review', [FrameReviewQueueController::class, 'index'])
            ->middleware('can:create,'.FrameReview::class)
            ->name('review.index');

        Route::get('import', [ImportController::class, 'index'])
            ->middleware('can:viewAny,'.ImportRun::class)
            ->name('import.index');

        Route::get('import/run/{importRun}', [ImportController::class, 'show'])
            ->middleware('can:view,importRun')
            ->name('import.show');

        Route::post('import/discover', [ImportDiscoverController::class, 'store'])
            ->middleware(['can:create,'.ImportRun::class, 'throttle:admin-import'])
            ->name('import.discover');

        Route::post('import/ids', [ImportIdsController::class, 'store'])
            ->middleware(['can:create,'.ImportRun::class, 'throttle:admin-import'])
            ->name('import.ids');

        Route::post('import/run/{importRun}/resume', [ImportResumeController::class, 'store'])
            ->middleware(['can:update,importRun', 'throttle:admin-import'])
            ->name('import.resume');
    });
