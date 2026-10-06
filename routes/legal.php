<?php

use App\Http\Controllers\Legal\ConsentController;
use App\Http\Controllers\Legal\LegalPageController;
use App\Http\Middleware\RobotsDirectives;
use App\Http\Middleware\VaryOnLanguage;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pages publiques — spec 90 § 4.1
|--------------------------------------------------------------------------
|
| Mentions légales, CGU, confidentialité et « signaler un contenu », toutes
| rendues par la page Inertia `legal/show` dans `PublicLayout`. Chemins en
| anglais, sans préfixe de locale (spec 05 § Pas de préfixe de locale dans les
| URL) : la locale vit en cookie et en colonne, jamais dans l'URL.
|
| - `translations:legal` : l'habillage et le pied de page (`common` est joint
|   d'office) ; `legal` est déclaré sur toute route joueur (spec 90 § 6.3).
| - `VaryOnLanguage::ROUTE_FLAG` sur les QUATRE routes : leur corps reste en
|   français, mais leur habillage suit la langue du visiteur (n° 69).
| - `RobotsDirectives::ROUTE_FLAG` sur les TROIS pages légales seulement,
|   sans effet tant que l'indexation n'est pas levée (tout le jalon 1).
|   « Signaler un contenu » ne le porte JAMAIS : elle reste `noindex` en
|   permanence, y compris après l'ouverture (spec 90 § 4.5) — un ayant droit y
|   arrive depuis les mentions légales, indexées et porteuses du contact.
|   `IndexingTest` refuse tout autre porteur du drapeau.
|
| Aucune route d'écriture au jalon 1 : `POST /report-content`
| (`takedown.store`, `throttle:takedown`) arrive au jalon 2 avec son
| formulaire.
|
*/

Route::middleware('translations:legal')->group(function () {
    Route::get('legal/notice', [LegalPageController::class, 'notice'])
        ->name('legal.notice')
        ->defaults(VaryOnLanguage::ROUTE_FLAG, true)
        ->defaults(RobotsDirectives::ROUTE_FLAG, true);

    Route::get('legal/terms', [LegalPageController::class, 'terms'])
        ->name('legal.terms')
        ->defaults(VaryOnLanguage::ROUTE_FLAG, true)
        ->defaults(RobotsDirectives::ROUTE_FLAG, true);

    Route::get('legal/privacy', [LegalPageController::class, 'privacy'])
        ->name('legal.privacy')
        ->defaults(VaryOnLanguage::ROUTE_FLAG, true)
        ->defaults(RobotsDirectives::ROUTE_FLAG, true);

    Route::get('report-content', [LegalPageController::class, 'report'])
        ->name('takedown.create')
        ->defaults(VaryOnLanguage::ROUTE_FLAG, true);
});

// Le choix de la bannière de consentement (D62 du 06/10, spec 90 § 4.6) :
// accepter dépose l'identifiant de visiteur, refuser le retire.
Route::post('consent', [ConsentController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('consent.store');
