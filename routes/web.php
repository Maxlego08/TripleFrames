<?php

use App\Http\Controllers\Locale\LocaleController;
use App\Http\Middleware\VaryOnLanguage;
use Illuminate\Support\Facades\Route;

// L'accueil est la seule page publique dont le contenu varie avec la locale :
// il embarque `common` (joint d'office) et `legal`, pour l'habillage du pied de
// page et l'attribution TMDB. C'est aussi la seule à porter
// `Vary: Accept-Language` — les pages légales sont mono-langue FR et doivent
// rester cachables (spec 05 § Pas de préfixe de locale dans les URL).
Route::inertia('/', 'welcome')
    ->name('home')
    ->defaults(VaryOnLanguage::ROUTE_FLAG, true)
    ->middleware('translations:legal');

// Changement de langue : un seul geste, ouvert aux invités, sans préfixe de
// locale dans l'URL — un lien de salon se partage entre amis de langues
// différentes, le code de salon doit désigner un salon et pas une langue.
Route::post('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
