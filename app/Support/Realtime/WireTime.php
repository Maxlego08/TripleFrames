<?php

namespace App\Support\Realtime;

use Carbon\CarbonImmutable;

/**
 * Format d'un instant sur le fil — spec 60 § 11.1, contrat C7 § 2.5.
 *
 * `IsoMs` : `YYYY-MM-DDTHH:mm:ss.sssZ`, **UTC, à la milliseconde** (05 :
 * instants ISO-8601 UTC), quelle que soit la zone de l'instant reçu. Le client
 * le relit par `parseIsoMs()` (`resources/js/lib/game/wire.ts`). Aucun instant
 * ne part autrement vers un client de jeu, et aucune date n'est formatée pour
 * l'affichage côté serveur : le client la met en forme dans sa locale.
 */
final class WireTime
{
    /** Format PHP d'`IsoMs` : `v` = millisecondes, tronquées depuis les microsecondes. */
    private const string FORMAT = 'Y-m-d\TH:i:s.v\Z';

    public static function iso(CarbonImmutable $instant): string
    {
        return $instant->utc()->format(self::FORMAT);
    }
}
