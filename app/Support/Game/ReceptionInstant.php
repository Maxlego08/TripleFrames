<?php

namespace App\Support\Game;

use App\Http\Middleware\CaptureReceptionInstant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * L'instant serveur de réception d'une requête — spec 60 § 2.2, contrat C7
 * § 2.5 (R-22), contrat C10 L3.
 *
 * Capturé **une fois**, à l'entrée de la requête, **avant tout verrou et tout
 * travail de validation**, par {@see CaptureReceptionInstant}, en tête du
 * groupe `web` : l'attente d'un `lockForUpdate` ou d'un limiteur ne doit jamais
 * faire changer de palier une soumission déjà reçue. {@see self::of()} relit
 * l'attribut de requête {@see self::ATTRIBUTE} ; hors de cette pile (route sans
 * le groupe `web`), il retombe sur `Date::now()` et **mémorise** ce repli sur la
 * requête, pour que deux lectures rendent toujours le même instant.
 *
 * Source : `Date::now()`, jamais `$_SERVER['REQUEST_TIME_FLOAT']`, qui échappe à
 * `Date::setTestNow()` — aucun test à horloge figée ne pourrait alors prouver
 * la fenêtre d'acceptation (10 § 1.2, contrat C18). Aucune valeur envoyée par
 * le client n'y entre (invariant L3). **Limite connue** : l'attente d'une
 * requête dans la file de PHP-FPM sous saturation n'est pas comptée ; mesurée
 * au test de charge (D33 du 23/09), choix à confirmer au relevé du VPS.
 *
 * L'instant garde ses microsecondes : {@see RoundClock::offsetMs()} tronque
 * une seule fois, à la milliseconde entière.
 */
final class ReceptionInstant
{
    /** Attribut de requête qui porte l'instant capturé. */
    public const string ATTRIBUTE = 'tripleframes.received_at';

    /**
     * L'instant de réception de la requête : celui que le middleware a capturé,
     * ou, à défaut, `Date::now()` mémorisé sur la requête à la première lecture.
     */
    public static function of(Request $request): CarbonImmutable
    {
        return self::capture($request);
    }

    /**
     * Capture l'instant s'il ne l'est pas encore, et le rend. **Jamais
     * d'écrasement** : un second passage (middleware répété, lecture
     * ultérieure) rend l'instant de la première capture.
     */
    public static function capture(Request $request): CarbonImmutable
    {
        $captured = $request->attributes->get(self::ATTRIBUTE);

        if ($captured instanceof CarbonImmutable) {
            return $captured;
        }

        $now = Date::now()->toImmutable();

        $request->attributes->set(self::ATTRIBUTE, $now);

        return $now;
    }
}
