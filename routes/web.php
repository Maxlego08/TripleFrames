<?php

use App\Http\Controllers\Locale\LocaleController;
use App\Http\Middleware\RobotsDirectives;
use App\Http\Middleware\VaryOnLanguage;
use Illuminate\Support\Facades\Route;

// L'accueil embarque `common` (joint d'office) et `legal`, pour l'habillage du
// pied de page et l'attribution TMDB. Son contenu varie avec la locale
// négociée, d'où `Vary: Accept-Language` — que les pages légales et
// « signaler un contenu » (`routes/legal.php`) portent aussi (spec 90 § 4.2) :
// leur corps reste en français, mais leur habillage suit la langue du
// visiteur. Aucune de ces pages n'est « cachable » telle quelle : la règle est
// l'absence de tout cache HTTP de page complète devant l'application (spec 05
// § Pas de préfixe de locale dans les URL).
//
// Indexation (spec 90 § 5) : l'accueil est l'une des QUATRE routes qui
// portent `RobotsDirectives::ROUTE_FLAG`, avec les trois pages légales. Le
// drapeau reste sans effet tant que `SITE_INDEXABLE` est fausse, c'est-à-dire
// pendant tout le jalon 1 : toute réponse sort alors en `noindex, nofollow`.
// Ne jamais le poser ailleurs, `IndexingTest` refuse tout autre porteur.
Route::inertia('/', 'welcome')
    ->name('home')
    ->defaults(VaryOnLanguage::ROUTE_FLAG, true)
    ->defaults(RobotsDirectives::ROUTE_FLAG, true)
    ->middleware('translations:legal');

// Changement de langue : un seul geste, ouvert aux invités, sans préfixe de
// locale dans l'URL — un lien de salon se partage entre amis de langues
// différentes, le code de salon doit désigner un salon et pas une langue.
Route::post('locale', [LocaleController::class, 'update'])->name('locale.update');

// Page du starter conservée au jalon 1 comme cible de `fortify.home` (spec 90
// § 2.1). Rendue dans `AppLayout`, elle porte le pied de page joueur : `legal`
// est déclaré sur toute route joueur (spec 90 § 6.3).
Route::middleware(['auth', 'verified', 'translations:account,legal'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/admin.php';
require __DIR__.'/legal.php';
require __DIR__.'/settings.php';
