<?php

use App\Http\Controllers\Locale\LocaleController;
use Illuminate\Support\Facades\Route;

// L'accueil est la seule page publique dont le contenu varie avec la locale :
// il embarque `common` (joint d'office) et `legal`, pour l'habillage du pied de
// page et l'attribution TMDB.
Route::inertia('/', 'welcome')->name('home')->middleware('translations:legal');

// Changement de langue : un seul geste, ouvert aux invités, sans préfixe de
// locale dans l'URL — un lien de salon se partage entre amis de langues
// différentes, le code de salon doit désigner un salon et pas une langue.
Route::post('locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
