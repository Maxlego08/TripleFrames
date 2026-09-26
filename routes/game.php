<?php

use App\Http\Controllers\Game\ClockController;
use App\Http\Controllers\Game\FrameServeController;
use App\Http\Controllers\Game\RoomStateController;
use App\Http\Controllers\Room\RoomPresetController;
use App\Http\Controllers\Room\RoomSettingsController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Moteur de partie et salon — spec 60 § 10.1, contrat C7 § 2.4 ; spec 50
| § 21, contrats C0 et C6
|--------------------------------------------------------------------------
|
| Routes du moteur et du salon, `require`-é par `routes/web.php` :
| resynchronisation, battements, gestes d'hôte, réglages, solo, horloge et
| service d'image. Seuls les NOMS font contrat ; les URL partent au client
| par Wayfinder, sauf celles des images, produites par le serveur (§ 7.5).
|
| Limiteurs (`FortifyServiceProvider::configureRateLimiting()`, § 10.3) :
| `game-read` sur les lectures, `game-write` sur les écritures, `frame-serve`
| sur les octets d'image, clés sur le hash du `player_token`, repli sur l'IP.
|
| Pile SANS SESSION de `clock.show` et `frame.serve` (contrat C8 § 2) : ni
| session, ni `Set-Cookie`. `PreventRequestForgery` lirait la session pour
| poser `XSRF-TOKEN` sur un GET ; `AddQueuedCookiesToResponse` émettrait le
| cookie `locale` que `SetLocale` met en file à la négociation. `EncryptCookies`
| reste, pour lire le `player_token` du limiteur. Ces deux routes n'ont aucun
| domaine de traduction : elles ne rendent aucune page (exclues nommément par
| `TranslationDomainDeclarationTest`).
|
*/

Route::get('clock', [ClockController::class, 'show'])
    ->name('clock.show')
    ->middleware('throttle:game-read')
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        AddQueuedCookiesToResponse::class,
    ]);

// Service d'image d'un palier (§ 7.3, contrat C8 § 2), adressé par le
// `serve_token` de la manche, jamais par un chemin. URL signée RELATIVE
// produite par le serveur (`ServeUrl`), jamais reconstruite par Wayfinder :
// 403 sur une signature invalide ou expirée, 429 au-delà du débit, 404
// uniforme sinon. Posée par L60-5, que `ServeUrl` exige ; le prédicat de
// service (`ServeGuard`) y est branché par L60-8, et d'ici là la route
// refuse tout.
Route::get('f/{serveToken}', [FrameServeController::class, 'show'])
    ->name('frame.serve')
    ->where('serveToken', '[0-9a-f]{32}')
    ->middleware(['signed:relative', 'throttle:frame-serve'])
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        AddQueuedCookiesToResponse::class,
    ]);

// Resynchronisation d'un siège de salon (§ 12) : le paquet `GameStatePacket`,
// JSON à destinataire unique. Une LECTURE : ni `seat.active` — l'onglet
// supplanté doit pouvoir y lire `seatActive: false` (§ 12.7) —, ni frappe de
// jeton d'onglet. `translations:game,room,legal` comme toute route GET joueur
// du moteur (contrat C15 § 2.3).
Route::get('r/{room}/state', [RoomStateController::class, 'show'])
    ->name('room.state')
    ->middleware(['translations:game,room,legal', 'throttle:game-read']);

// Réglages du salon par l'hôte (spec 50 § 3, § 5.3, § 17.1, § 21 ; contrat
// C0) : l'onglet Simple et l'application d'un preset. Toute écriture du lobby
// passe par `seat.active` (contrat C7 : 403 sans siège tenu par le jeton, 409
// `seat_superseded` pour un onglet supplanté), puis par `throttle:game-write`,
// dans cet ordre et avant la liaison de `{room}` (liste de priorité de
// `bootstrap/app.php`). Aucun domaine de traduction : ces routes ne rendent
// aucune page, elles redirigent vers le lobby.
Route::middleware(['seat.active', 'throttle:game-write'])->group(function (): void {
    Route::patch('r/{room}/settings', [RoomSettingsController::class, 'update'])
        ->name('room.settings.update');

    Route::post('r/{room}/settings/preset', [RoomPresetController::class, 'store'])
        ->name('room.settings.preset');
});
