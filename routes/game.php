<?php

use App\Http\Controllers\Game\ClockController;
use App\Http\Controllers\Game\FrameServeController;
use App\Http\Controllers\Game\RoomStateController;
use App\Http\Controllers\Room\RoomController;
use App\Http\Controllers\Room\RoomEntryController;
use App\Http\Controllers\Room\RoomPresetController;
use App\Http\Controllers\Room\RoomSettingsController;
use App\Support\Room\RoomCode;
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
| Routes du moteur et du salon, `require`-é par `routes/web.php` : création,
| page et entrée du salon, resynchronisation, battements, gestes d'hôte,
| réglages, solo, horloge et service d'image. Seuls les NOMS font contrat ;
| les URL partent au client par Wayfinder, sauf celles des images, produites
| par le serveur (§ 7.5).
|
| Limiteurs (`FortifyServiceProvider::configureRateLimiting()`, § 10.3 ;
| spec 50 § 17.3) : `game-read` sur les lectures, `game-write` sur les
| écritures, `frame-serve` sur les octets d'image, clés sur le hash du
| `player_token`, repli sur l'IP ; `room-create` (par adresse) et `room-join`
| (par jeton, repli sur l'IP) sur les deux gestes qui prennent un siège.
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

// Motif TOLÉRANT de `{room}` (spec 50 § 6.3), posé AVANT toute route qui
// porte `{room}` : `Route::pattern()` ne s'applique qu'aux routes déclarées
// après lui. Casse, espaces et tirets d'un lien saisi à la main passent le
// routeur ; `Room::resolveRouteBinding()` normalise, contrôle la forme et
// répond 404 sans requête à un code mal formé. Un motif strict tiré de
// l'alphabet renverrait 404 avant la liaison, contre `RoomCode::normalize()`.
Route::pattern('room', RoomCode::ROUTE_PATTERN);

// Création, page du salon et entrée (spec 50 § 6.2, § 7.2 et § 21). Un GET ne
// frappe jamais de `player_token` (contrat C4 I4.1) : seuls `room.store` et
// `room.join` en frappent un, une fois tous leurs refus écartés.
//
// `room.create` est déclarée AVANT `room.show` : `new` ne peut pas être un
// code, que le motif de `{room}` refuse d'ailleurs (trois signes). Les pages
// d'entrée `room/*` suivent l'apparence du visiteur, hors de `game.appearance`,
// qui ne force que les pages `game/*` : `room.show` le porte, et redirige
// vers `room.entry` le visiteur sans siège plutôt que de lui rendre un
// formulaire forcé en sombre (spec 90 § 2.2, règle de groupe).
Route::get('r/new', [RoomController::class, 'create'])
    ->name('room.create')
    ->middleware(['translations:room,legal', 'throttle:game-read']);

Route::post('r', [RoomController::class, 'store'])
    ->name('room.store')
    ->middleware('throttle:room-create');

Route::get('r/{room}', [RoomController::class, 'show'])
    ->name('room.show')
    ->middleware(['game.appearance', 'translations:game,room,legal', 'throttle:game-read']);

Route::get('r/{room}/join', [RoomEntryController::class, 'show'])
    ->name('room.entry')
    ->middleware(['translations:room,legal', 'throttle:game-read']);

Route::post('r/{room}/join', [RoomEntryController::class, 'store'])
    ->name('room.join')
    ->middleware('throttle:room-join');

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
// uniforme sinon — jeton inconnu, prédicat de service (`ServeGuard` :
// catalogue, temps, appartenance du demandeur) faux ou fichier absent. En
// lecture seule : aucune transition, aucun rattrapage, aucun `seen_frame`.
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
