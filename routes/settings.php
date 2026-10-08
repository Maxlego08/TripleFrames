<?php

use App\Avatars\UploadedAvatars;
use App\Http\Controllers\Avatar\AvatarFileController;
use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\LinkedAccountController;
use App\Http\Controllers\Settings\PlayHistoryController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TermsAcceptanceController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Tous les écrans de réglages relèvent du domaine `account` (Fortify, profil,
// comptes liés, avatars) ; `common` est joint d'office, et `legal` l'est sur
// toute route joueur, pour le pied de page présent sur chaque écran (liens
// légaux, attribution TMDB ; spec 90 § 6.3).
//
// `terms.current` (spec 40 § 13.1) renvoie un compte dont la version des CGU
// est périmée vers l'interstitiel : posé sur toute page de compte, JAMAIS sur
// le jeu, le back-office, la déconnexion, l'export ni la suppression.
Route::middleware(['auth', 'translations:account,legal', 'terms.current'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    // L'avatar du compte (spec 40 § 11, D49 du 01/10) : choix de la nature,
    // téléversement, suppression. Le téléversement porte son limiteur : il
    // décode une image dans la requête.
    Route::get('settings/avatar', [AvatarController::class, 'edit'])->name('avatar.edit');
    Route::patch('settings/avatar', [AvatarController::class, 'update'])->name('avatar.update');
    Route::post('settings/avatar', [AvatarController::class, 'store'])
        ->middleware('throttle:avatar-upload')
        ->name('avatar.store');
    Route::delete('settings/avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');

    // Les comptes liés (spec 40 § 12.5, D51 du 01/10) : la déliaison exige une
    // confirmation fraîche — par mot de passe, ou par le fournisseur pour un
    // compte qui n'en a pas (§ 12.4).
    Route::get('settings/accounts', [LinkedAccountController::class, 'edit'])->name('linked_accounts.edit');
    Route::delete('settings/accounts/{provider}', [LinkedAccountController::class, 'destroy'])
        ->whereIn('provider', ['google', 'discord'])
        ->middleware(RequirePassword::class)
        ->name('linked_accounts.destroy');

    // « Mes parties » (spec 40 § 13.4, L40-13, D66 du 07/10) : la liste et
    // ses quatre compteurs, puis le détail d'une partie par `game.public_id`
    // (404 hors de l'historique du compte). Le détail joint `game` pour le
    // lien Letterboxd partagé avec la révélation (`letterboxd-link.tsx`).
    Route::get('settings/history', [PlayHistoryController::class, 'index'])->name('history.index');
    Route::get('settings/history/{game:public_id}', [PlayHistoryController::class, 'show'])
        ->where('game', '[0-9A-HJKMNP-TV-Z]{12}')
        ->middleware('translations:game')
        ->name('history.show');
});

// Ce groupe ne porte plus `profile.destroy` : aucune suppression de compte au
// jalon 1 (spec 40 § 8.3). La suppression dure contredit 10 § 5.5 (suppression
// = anonymisation), et le seul compte de production — l'administrateur, qui
// signe toute la curation — tomberait en un clic. La route renaîtra au jalon 2
// sur l'action d'anonymisation.
Route::middleware(['auth', 'verified', 'translations:account,legal', 'terms.current'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');
});

// L'interstitiel de ré-acceptation des CGU (spec 40 § 13.1, D66 du 07/10) :
// sans `terms.current`, qui y renverrait en boucle, et sans `verified`, pour
// qu'un compte à l'adresse non vérifiée puisse aussi accepter.
Route::middleware(['auth', 'translations:account,legal'])->group(function () {
    Route::get('account/terms', [TermsAcceptanceController::class, 'show'])->name('terms.show');
    Route::post('account/terms', [TermsAcceptanceController::class, 'store'])->name('terms.accept');
});

// Hors du groupe de Fortify, donc `accounts.switches` posé ici aussi : quand
// les passkeys sont fermées (spec 40 § 8.2), cette route répond 404 au lieu
// d'annoncer aux gestionnaires de mots de passe un point d'enrôlement qui
// n'existe pas.
Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->middleware('accounts.switches')->name('well-known.passkeys');

// Les octets d'un avatar téléversé (spec 40 § 11.3) : public, sans session ni
// cookie, comme `frame.serve` — mais cacheable, et sans domaine de traduction :
// la route ne rend aucune page. Elle répond 404 à une image masquée, retirée
// ou remplacée.
Route::get('a/{file}', [AvatarFileController::class, 'show'])
    ->name('avatar.show')
    ->where('file', UploadedAvatars::FILE_PATTERN)
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        AddQueuedCookiesToResponse::class,
    ]);
