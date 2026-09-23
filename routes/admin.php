<?php

use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\ImportDiscoverController;
use App\Http\Controllers\Admin\ImportIdsController;
use App\Http\Controllers\Admin\ImportResumeController;
use App\Models\ImportRun;
use App\Models\Movie;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Back-office — début de panel, pas encore le back-office de curation
|--------------------------------------------------------------------------
|
| Cinq middlewares sur le groupe, et pas un de plus :
|
| - `auth` et `verified` : le panneau est derrière un compte vérifié ;
| - `role:curator` (`EnsureUserHasRole`) garde la PORTE, là où les `can:` posés
|   route par route gardent la RESSOURCE. Il est prioritaire sur
|   `SubstituteBindings` : sans lui, `/admin/catalog/{movie}` répondrait 404 à un
|   joueur sur un identifiant inconnu contre 403 sur un identifiant réel, et le
|   simple couple de codes de statut énumérerait la table `movie` ;
| - `admin.locale` (`ForceAdminLocale`) force `Locale::French` ET appelle déjà
|   `TranslationDomains::need('admin')`. **Ne jamais ajouter `translations:admin`
|   ici** : ce serait un doublon. À noter pour les écrans — `TranslationDomains::selected()`
|   rend `['admin']` SEUL dès que le domaine `admin` est demandé, `common` n'est
|   donc PAS joint : toute clé appelée par une page d'administration vit dans
|   `lang/fr/admin.php` ;
| - `admin.appearance` (`ForceAdminAppearance`) force le thème clair côté serveur.
|
| **L'autorisation est posée route par route par `can:`, jamais par un test de
| rôle dans un contrôleur.** Les policies vivent dans `app/Policies/` et sont
| auto-découvertes par convention `App\Models\X` → `App\Policies\XPolicy` :
| aucun enregistrement de provider, et surtout aucun `Gate::before` — il
| contournerait la propriété d'une `saved_config`, déclarée strictement privée.
|
| Le groupe est écrit pour que la contrainte « 2FA obligatoire sur les rôles
| privilégiés » ne soit **qu'un middleware à ajouter ici**, sans toucher à un
| seul contrôleur. Elle n'est PAS implémentée dans ce lot, et elle n'est pas
| oubliée pour autant : elle est consignée, avec les autres points que la spec
| 20 devra trancher, dans `docs/REPRISE.md` § « À trancher par la spec 20 ».
|
| Les trois routes d'écriture portent en plus `throttle:admin-import`, limiteur
| nommé déclaré dans `FortifyServiceProvider::configureRateLimiting()`, là où
| vivent déjà `login`, `two-factor` et `passkeys`.
|
*/

Route::middleware(['auth', 'verified', 'role:curator', 'admin.locale', 'admin.appearance'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
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
