<?php

use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\CurationHeartbeatController;
use App\Http\Controllers\Admin\CurationQueueController;
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
use App\Http\Controllers\Admin\GuideController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\ImportDiscoverController;
use App\Http\Controllers\Admin\ImportIdsController;
use App\Http\Controllers\Admin\ImportPreviewController;
use App\Http\Controllers\Admin\ImportResumeController;
use App\Http\Controllers\Admin\ImportSearchController;
use App\Http\Controllers\Admin\ImportSeedListController;
use App\Http\Controllers\Admin\JournalController;
use App\Http\Controllers\Admin\MovieAliasController;
use App\Http\Controllers\Admin\MovieContentVerifiedController;
use App\Http\Controllers\Admin\MovieFramesReviewController;
use App\Http\Controllers\Admin\MovieGroupController;
use App\Http\Controllers\Admin\MoviePublishController;
use App\Http\Controllers\Admin\MovieThemeController;
use App\Http\Controllers\Admin\MovieTitleController;
use App\Http\Controllers\Admin\MovieUnpublishController;
use App\Http\Controllers\Admin\ThemeController;
use App\Http\Controllers\Admin\ThemePublishController;
use App\Http\Controllers\Admin\ThroughputController;
use App\Http\Controllers\Admin\TwoFactorRequiredController;
use App\Http\Controllers\Admin\UserDirectoryController;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\Theme;
use App\Models\User;
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
| (`admin.two_factor.required`) et la page de premiers pas (`admin.guide`).
| Les policies vivent dans `app/Policies/` et sont auto-découvertes par
| convention `App\Models\X` → `App\Policies\XPolicy` : aucun enregistrement
| de provider, et surtout aucun `Gate::before` — il contournerait la
| propriété d'une `saved_config`, déclarée strictement privée.
|
| **Une seconde porte, `role:admin`**, garde en plus le seul sous-groupe des
| écrans de l'administrateur (annuaire des comptes et gestion des accès,
| § 2.8 ; journal d'administration, ligne 41) : prioritaire sur
| `SubstituteBindings` comme la première, elle rend à un curateur le même 403
| sur un identifiant réel ou inconnu.
|
| **Toute route ajoutée ici prend sa ligne dans `tests/Datasets/AdminRoutes.php`**,
| la matrice des capacités de la spec 20 § 2.2 : `AuthorizationMatrixTest`
| refuse une route `admin.*` sans ligne, une ligne sans route, et une garde
| `can:` autre que celle que la ligne écrit.
|
| Les routes d'écriture de l'import — balayage, collage, reprise, aperçu à
| blanc, liste d'amorçage — portent en plus `throttle:admin-import`, la
| recherche TMDB `throttle:admin-tmdb-search` (§ 3.4), le battement de débit
| `throttle:admin-heartbeat` (§ 10.1) ; les écritures d'image qui téléchargent un original
| ou distribuent un job Imagick — ajout, re-recadrage, relance —
| `throttle:admin-frame` (C9 § 2) ; les gestes de curation qui n'écrivent
| qu'en base — changer un niveau, passer une revue, dépublier ou écarter
| une image, publier, dépublier ou écarter un film, cocher son contenu
| vérifié, corriger ou retirer un titre, ajouter ou retirer un alias,
| regrouper deux films — et les deux gestes d'accès qui n'écrivent eux aussi
| qu'en base — changer un rôle, corriger un nom réel (§ 2.8) —
| `throttle:admin-curation` (§ 13.7). Limiteurs nommés déclarés dans
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

        // La page « premiers pas du curateur » (§ 13.6, ligne 9) : sans `can:`,
        // comme l'écran d'enrôlement — elle ne lit aucun modèle, seulement la
        // grille d'exclusion courante et le plancher de recadrage, et la porte
        // suffit à la garder. Elle reste derrière `admin.2fa` : c'est le guide
        // de l'outil, pas de l'enrôlement.
        Route::get('guide', [GuideController::class, 'show'])
            ->name('guide');

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

        // L'écran des thèmes (§ 9.6, ligne 28 ; J1 depuis D43 du 01/10) : la
        // liste, puis trois gestes qui n'écrivent qu'en base — créer toute
        // nature ou un thème manuel, corriger règle, libellés et ordre,
        // publier sous seuil ou dépublier. Aucune suppression. La nature
        // `difficulty` est refusée en validation, jamais par un 403.
        Route::get('themes', [ThemeController::class, 'index'])
            ->middleware('can:viewAny,'.Theme::class)
            ->name('themes.index');

        Route::post('themes', [ThemeController::class, 'store'])
            ->middleware(['can:create,'.Theme::class, 'throttle:admin-curation'])
            ->name('themes.store');

        Route::patch('themes/{theme}', [ThemeController::class, 'update'])
            ->middleware(['can:update,theme', 'throttle:admin-curation'])
            ->name('themes.update');

        Route::post('themes/{theme}/publish', [ThemePublishController::class, 'store'])
            ->middleware(['can:publish,theme', 'throttle:admin-curation'])
            ->name('themes.publish');

        // La file de curation et « film suivant » (§ 4.1, ligne 3) : deux
        // lectures, la seconde une simple redirection vers l'éditeur du
        // premier film de la file autre que le courant — ou vers la file,
        // vide. L'éditeur garde sa propre policy (`curate`).
        Route::get('curation', [CurationQueueController::class, 'index'])
            ->middleware('can:viewAny,'.Movie::class)
            ->name('curation.index');

        Route::get('curation/next', [CurationQueueController::class, 'next'])
            ->middleware('can:viewAny,'.Movie::class)
            ->name('curation.next');

        // Le débit de curation et le verdict du lot pilote (§ 10.2 à § 10.4,
        // ligne 8) : un agrégat en lecture seule, jamais nominatif.
        Route::get('throughput', [ThroughputController::class, 'index'])
            ->middleware('can:viewAny,'.Movie::class)
            ->name('throughput.index');

        // Le battement de débit (§ 10.1, ligne 24) : posté par l'éditeur, la
        // fiche et la revue après une saisie, il n'écrit que
        // `movie.curation_active_seconds` et répond 204. À SON limiteur,
        // réglé pour deux onglets ouverts sur la même page.
        Route::post('catalog/{movie}/heartbeat', [CurationHeartbeatController::class, 'store'])
            ->middleware(['can:curate,movie', 'throttle:admin-heartbeat'])
            ->name('catalog.heartbeat');

        // Publier ou republier un film (§ 8.1, ligne 19) : un geste explicite,
        // derrière une confirmation qui montre d'abord l'aperçu d'ambiguïté et
        // en poste l'empreinte (§ 8.2). La garde porte l'état — brouillon,
        // dépublié ou écarté — ; contenu, couverture, devinabilité et aperçu
        // périmé sont des erreurs traduites relues sous le verrou, jamais des
        // 403.
        Route::post('catalog/{movie}/publish', [MoviePublishController::class, 'store'])
            ->middleware(['can:publish,movie', 'throttle:admin-curation'])
            ->name('catalog.publish');

        // Dépublier un film publié, ou écarter un brouillon (§ 8.3, § 4.2,
        // ligne 20) : motif obligatoire, les images restent publiées.
        Route::post('catalog/{movie}/unpublish', [MovieUnpublishController::class, 'store'])
            ->middleware(['can:unpublish,movie', 'throttle:admin-curation'])
            ->name('catalog.unpublish');

        // Cocher « contenu vérifié » (§ 4.4, ligne 21) : un film
        // `unrated_pending` seulement, motif obligatoire. Aucune route ne
        // décoche, aucune ne lève `blocked` (décision 12).
        Route::post('catalog/{movie}/content-verified', [MovieContentVerifiedController::class, 'store'])
            ->middleware(['can:verifyContent,movie', 'throttle:admin-curation'])
            ->name('catalog.content_verified');

        // Corriger ou retirer un titre (§ 9.1, ligne 22). `{locale}` est lié à
        // `App\Enums\Locale` par le contrôleur : une locale de catalogue non
        // activée répond 404, ses lignes restent en lecture seule. Seule une
        // ligne `curator` se retire — refus traduit sinon, jamais un 403.
        Route::put('catalog/{movie}/titles/{locale}', [MovieTitleController::class, 'update'])
            ->middleware(['can:curate,movie', 'throttle:admin-curation'])
            ->name('catalog.titles.update');

        Route::delete('catalog/{movie}/titles/{locale}', [MovieTitleController::class, 'destroy'])
            ->middleware(['can:curate,movie', 'throttle:admin-curation'])
            ->name('catalog.titles.destroy');

        // Ajouter ou retirer un alias (§ 9.2, ligne 22) — tout alias, TMDB
        // compris. `scopeBindings` : l'alias d'un AUTRE film répond 404 avant
        // la garde.
        Route::post('catalog/{movie}/aliases', [MovieAliasController::class, 'store'])
            ->middleware(['can:curate,movie', 'throttle:admin-curation'])
            ->name('catalog.aliases.store');

        Route::delete('catalog/{movie}/aliases/{alias}', [MovieAliasController::class, 'destroy'])
            ->scopeBindings()
            ->middleware(['can:curate,movie', 'throttle:admin-curation'])
            ->name('catalog.aliases.destroy');

        // Regrouper deux films en une même œuvre, ou retirer un film de son
        // groupe (§ 9.4, ligne 23) : un geste manuel, jamais TMDB.
        Route::patch('catalog/{movie}/group', [MovieGroupController::class, 'update'])
            ->middleware(['can:curate,movie', 'throttle:admin-curation'])
            ->name('catalog.group.update');

        // L'appartenance manuelle d'un film à un thème (§ 9.6, ligne 28 ; D43
        // du 01/10) : tout thème, publié ou non ; journalisé `movie.theme_set`.
        Route::patch('catalog/{movie}/themes', [MovieThemeController::class, 'update'])
            ->middleware(['can:curate,movie', 'throttle:admin-curation'])
            ->name('catalog.themes.update');

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

            // Voie capture (§ 5.4, ligne 14 ; L20-33, D38 du 28/09) : ouverte
            // par défaut, même seuil que l'ajout TMDB — la source normalisée
            // par le navigateur, son minutage et le cadre, en multipart. Même
            // limiteur : chaque ajout distribue un job Imagick. Fermée par une
            // valeur explicitement fausse de `capture_enabled`, la garde répond
            // 403 motivé par `admin.frame.capture.disabled`, avant toute
            // résolution de requête.
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

            // Valider en lot les images du film en attente de revue (D42 du
            // 30/09, § 7.9) : même capacité et même limiteur que la revue
            // unitaire. Une ligne `frame_review` par image ; tout ou rien,
            // relu sous le verrou — les refus sont des erreurs traduites.
            Route::post('catalog/{movie}/frames/review-all', [MovieFramesReviewController::class, 'store'])
                ->middleware(['can:create,'.FrameReview::class, 'throttle:admin-curation'])
                ->name('catalog.frames.review_all');
        });

        // La file de revue (§ 7.3, ligne 6) : même garde que la revue — lire
        // la file, c'est déjà s'apprêter à revoir.
        Route::get('review', [FrameReviewQueueController::class, 'index'])
            ->middleware('can:create,'.FrameReview::class)
            ->name('review.index');

        Route::get('import', [ImportController::class, 'index'])
            ->middleware('can:viewAny,'.ImportRun::class)
            ->name('import.index');

        // La recherche TMDB (§ 3.4, ligne 12) : un appel TMDB DANS la requête,
        // d'où SON limiteur, distinct d'`admin-import`. `admin.import.index`,
        // que l'aperçu d'un collage sonde, n'en porte aucun.
        Route::get('import/search', [ImportSearchController::class, 'index'])
            ->middleware(['can:viewAny,'.ImportRun::class, 'throttle:admin-tmdb-search'])
            ->name('import.search');

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

        // L'aperçu à blanc d'un collage et la liste d'amorçage (§ 3.3, § 3.5,
        // ligne 11). L'aperçu n'ouvre aucune ligne `import_run` mais confie un
        // collage entier à TMDB : même limiteur contre le double clic.
        Route::post('import/preview', [ImportPreviewController::class, 'store'])
            ->middleware(['can:create,'.ImportRun::class, 'throttle:admin-import'])
            ->name('import.preview');

        Route::post('import/seed-list', [ImportSeedListController::class, 'store'])
            ->middleware(['can:create,'.ImportRun::class, 'throttle:admin-import'])
            ->name('import.seed_list');

        // Les écrans de l'administrateur seul (§ 2.8, lignes 34 et 40) :
        // une seconde porte, `role:admin`, en plus de la garde `can:` de
        // chaque route. Elle n'autorise rien de plus que les policies ; elle
        // est là pour l'ÉNUMÉRATION. `role` précède `SubstituteBindings`
        // (`bootstrap/app.php`), `Authorize` le suit : sans elle, un curateur
        // passé la porte `role:curator` lirait 404 sur un `{user}` inconnu et
        // 403 sur un compte réel, et le couple de codes de statut
        // énumérerait la table `users`. Avec elle, il reçoit 403 sur tout
        // identifiant, avant toute résolution.
        Route::middleware('role:admin')->group(function (): void {
            // L'annuaire des comptes et la fiche d'un compte (ligne 40) : deux
            // lectures — l'annuaire montre l'adresse de chaque compte.
            // `{user}` est lié par `id`.
            Route::get('users', [UserDirectoryController::class, 'index'])
                ->middleware('can:viewAny,'.User::class)
                ->name('users.index');

            Route::get('users/{user}', [UserDirectoryController::class, 'show'])
                ->middleware('can:view,user')
                ->name('users.show');

            // La gestion des accès (ligne 34) : l'écran, puis ses deux
            // gestes. Les refus d'état — son propre rôle, rôle inchangé,
            // adresse non vérifiée, nom réel manquant, dernier
            // administrateur, nom inchangé — sont des erreurs traduites
            // relues sous verrou, jamais des 403. Chacun écrit sa ligne au
            // journal.
            Route::get('access', [AccessController::class, 'index'])
                ->middleware('can:viewAny,'.User::class)
                ->name('access.index');

            Route::patch('access/{user}', [AccessController::class, 'update'])
                ->middleware(['can:updateRole,user', 'throttle:admin-curation'])
                ->name('access.update');

            Route::patch('access/{user}/real-name', [AccessController::class, 'updateRealName'])
                ->middleware(['can:updateRealName,user', 'throttle:admin-curation'])
                ->name('access.real_name.update');

            // Le journal d'administration (ligne 41, D41 du 30/09) : une
            // lecture de tout `admin_action`, filtrable par acteur, action,
            // période et sujet. Aucune route n'écrit ni ne supprime une ligne
            // (ligne 37).
            Route::get('journal', [JournalController::class, 'index'])
                ->middleware('can:viewAny,'.AdminAction::class)
                ->name('journal.index');
        });
    });
