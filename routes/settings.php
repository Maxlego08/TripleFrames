<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Tous les écrans de réglages relèvent du domaine `account` (Fortify, profil,
// comptes liés, avatars) ; `common` est joint d'office.
Route::middleware(['auth', 'translations:account'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

// Ce groupe ne porte plus `profile.destroy` : aucune suppression de compte au
// jalon 1 (spec 40 § 8.3). La suppression dure contredit 10 § 5.5 (suppression
// = anonymisation), et le seul compte de production — l'administrateur, qui
// signe toute la curation — tomberait en un clic. La route renaîtra au jalon 2
// sur l'action d'anonymisation.
Route::middleware(['auth', 'verified', 'translations:account'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
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
