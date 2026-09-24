<?php

use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\ImportDiscoverController;
use App\Http\Controllers\Admin\ImportIdsController;
use App\Http\Controllers\Admin\ImportResumeController;
use App\Http\Controllers\Admin\TwoFactorRequiredController;
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
| Les trois routes d'écriture portent en plus `throttle:admin-import`, limiteur
| nommé déclaré dans `FortifyServiceProvider::configureRateLimiting()`, là où
| vivent déjà `login`, `two-factor` et `passkeys`.
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
