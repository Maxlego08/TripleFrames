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

    /**
     * La seule forme admise d'un `IsoMs` sur le fil, miroir d'`ISO_MS_PATTERN`
     * de `resources/js/lib/game/wire.ts` : un champ d'instant d'une charge
     * d'événement qui ne la suit pas est refusé ({@see WirePayload}).
     * Modificateur `D` : sans lui, `$` admettrait un saut de ligne final que
     * le `$` de JavaScript refuse.
     */
    public const string PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D';

    public static function iso(CarbonImmutable $instant): string
    {
        return $instant->utc()->format(self::FORMAT);
    }
}
