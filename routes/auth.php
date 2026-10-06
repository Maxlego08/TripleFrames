<?php

use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\OAuthFinishController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Connexion par Discord et Google — spec 40 § 12, D51 du 01/10
|--------------------------------------------------------------------------
|
| `{provider}` est une valeur d'`OAuthProvider` ; un fournisseur inactif
| (clés absentes) répond 404, contrôleur compris. Limiteur `oauth` sur tout
| le groupe. L'aller et le retour n'ont aucune garde d'invité : l'intention
| `link` et `confirm` partent d'un compte connecté, et le contrôleur relit
| l'intention. L'écran de finalisation est réservé aux invités.
|
| Ces routes ne lisent PAS `ACCOUNTS_REGISTRATION_OPEN` (D51 du 01/10) : la
| création d'un compte par fournisseur est ouverte dès que ses clés sont
| posées.
|
*/

Route::middleware(['translations:account,legal', 'throttle:oauth'])->group(function (): void {
    Route::get('auth/{provider}/redirect', [OAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'discord'])
        ->name('oauth.redirect');

    Route::get('auth/{provider}/callback', [OAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'discord'])
        ->name('oauth.callback');

    Route::middleware('guest')->group(function (): void {
        Route::get('auth/finish', [OAuthFinishController::class, 'show'])->name('oauth.finish');
        Route::post('auth/finish', [OAuthFinishController::class, 'store'])->name('oauth.finish.store');
    });
});
