<?php

use App\Http\Controllers\Audience\AudienceSeenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Preuve JavaScript de la mesure d'audience — spec 100 § 10.12, amendé le 08/10
|--------------------------------------------------------------------------
|
| Chargé par `bootstrap/app.php` (callback `then`) HORS du groupe `web` :
| sans session, sans cookie, sans jeton CSRF. Le groupe y pose
| `throttle:audience-seen`.
|
*/

Route::post('audience/seen', AudienceSeenController::class)->name('audience.seen');
