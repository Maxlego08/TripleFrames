<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Support\Realtime\WireTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

/**
 * L'instant serveur de la poignée de main d'horloge — route `clock.show`,
 * `GET /clock` (spec 60 § 2.4 et § 10.1, contrat C7 § 2.4).
 *
 * Réponse : `{ "serverNow": IsoMs }`, rien d'autre. Le client en tire son
 * décalage **pour l'affichage seulement** (`lib/game/server-clock.ts`) : aucun
 * palier, aucune clôture, aucun score ne dépend de ce que le client en fait,
 * et il ne renvoie jamais ce décalage au serveur (contrat C10 L3).
 *
 * Même pile sans session que `frame.serve` (`routes/game.php`) : ni session,
 * ni `Set-Cookie`, `EncryptCookies` conservé pour que `throttle:game-read`
 * compte par jeton. `no-store` : un instant servi depuis un cache fausserait
 * le décalage d'autant.
 */
final class ClockController extends Controller
{
    public function show(): JsonResponse
    {
        return response()
            ->json(['serverNow' => WireTime::iso(Date::now()->toImmutable())])
            ->header('Cache-Control', 'no-store, private');
    }
}
