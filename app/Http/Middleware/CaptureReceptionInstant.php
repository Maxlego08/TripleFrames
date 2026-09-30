<?php

namespace App\Http\Middleware;

use App\Support\Game\ReceptionInstant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capture l'instant serveur de réception, **en tête du groupe `web`** — spec 60
 * § 2.2, contrat C10 L3.
 *
 * Premier middleware de route de toute requête du groupe `web`, avant
 * `VaryOnLanguage`, les cookies, la session, la langue et tout middleware de
 * jeu (`throttle:*`, `seat.active`) : ce qu'ils coûtent — une attente de cache,
 * un verrou, une validation — ne décale jamais l'instant retenu. Seuls les
 * middlewares globaux le précèdent, coût uniforme pour tous les joueurs et
 * sous `tier_grace_ms`.
 *
 * Il ne touche pas la réponse : `VaryOnLanguage`, juste derrière lui, reste le
 * dernier à la modifier.
 */
final class CaptureReceptionInstant
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        ReceptionInstant::capture($request);

        return $next($request);
    }
}
