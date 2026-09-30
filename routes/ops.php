<?php

use App\Http\Controllers\Ops\ProbeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sondes d'exploitation — spec 100 § 15
|--------------------------------------------------------------------------
|
| Chargé par `bootstrap/app.php` (callback `then`) HORS du groupe `web` :
| sans session, sans cookie, sans jeton CSRF. Le groupe y pose
| `throttle:ops-probe` puis `EnsureProbeToken`.
|
| C'est la seule route admise sous `/ops/` (`ReservedPathsTest`), et elle
| ne porte jamais le drapeau d'indexation (`IndexingTest`) : ses réponses
| sortent en `noindex, nofollow` et `no-store` dans tous les états.
|
*/

Route::get('ops/probe/{probe}', [ProbeController::class, 'show'])->name('ops.probe');
