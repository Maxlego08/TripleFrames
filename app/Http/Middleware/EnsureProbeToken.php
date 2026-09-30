<?php

namespace App\Http\Middleware;

use App\Support\Ops\ProbeResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le jeton de supervision des sondes d'exploitation — spec 100 § 15.
 *
 * Compare l'en-tête `X-Probe-Token` à `config('ops.probe_token')` en temps
 * constant : les deux valeurs sont d'abord réduites à une empreinte de même
 * longueur, pour que la comparaison ne révèle pas davantage la LONGUEUR du
 * secret que son contenu.
 *
 * **Un jeton absent ou faux rend la même réponse qu'une sonde inconnue**
 * ({@see ProbeResponse::notFound()}) : l'existence des sondes ne se sonde pas.
 *
 * **Jeton non configuré = tout est refusé**, en-tête présent ou non.
 * `OPS_PROBE_TOKEN` est vide dans `.env.example` et forcé à vide dans
 * `phpunit.xml`, et `hash_equals('', '')` vaut vrai : sans cette garde, une
 * production qui aurait omis la variable ouvrirait ses sondes à quiconque
 * envoie un en-tête vide. L'oubli se voit aussitôt : toutes les sondes de la
 * supervision passent en 404, donc en alerte.
 *
 * Posé APRÈS `throttle:ops-probe` sur le groupe de `routes/ops.php`
 * (`bootstrap/app.php`) : les essais de jeton sont bornés par adresse.
 */
final class EnsureProbeToken
{
    public const string HEADER = 'X-Probe-Token';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('ops.probe_token');
        $given = $request->headers->get(self::HEADER);

        if (! is_string($expected) || trim($expected) === '' || ! is_string($given)) {
            return ProbeResponse::notFound();
        }

        if (! hash_equals(hash('sha256', $expected), hash('sha256', $given))) {
            return ProbeResponse::notFound();
        }

        return $next($request);
    }
}
