<?php

namespace App\Http\Controllers\Audience;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RecordVisit;
use App\Support\Audience\AudienceRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * La preuve JavaScript de la mesure d'audience — spec 100 § 10.12, amendé
 * le 08/10.
 *
 * La page joueur, une fois exécutée et visible, envoie ce signal ; les
 * chargements complets que le serveur a mis en attente sous la même
 * empreinte du jour deviennent des pages vues. Le signal ne porte rien : il
 * ne confirme que ce que le serveur a lui-même vu, donc il ne peut gonfler
 * aucun compteur. Hors du groupe `web` : ni session, ni cookie, ni jeton CSRF.
 * Réponse vide, `no-store`, `noindex`, quel que soit le sort.
 */
class AudienceSeenController extends Controller
{
    public function __invoke(Request $request, AudienceRecorder $recorder): Response
    {
        $userAgent = (string) $request->userAgent();

        if (! RecordVisit::isBot($userAgent)) {
            $recorder->confirm((string) $request->ip(), $userAgent);
        }

        return response()->noContent()->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
