<?php

use App\Http\Controllers\Game\ClockController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Moteur de partie — spec 60 § 10.1, contrat C7 § 2.4
|--------------------------------------------------------------------------
|
| Routes du moteur, `require`-é par `routes/web.php` : resynchronisation,
| battements, geste d'hôte, solo, horloge et service d'image. Seuls les NOMS
| font contrat ; les URL partent au client par Wayfinder, sauf celles des
| images, produites par le serveur (§ 7.5).
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
