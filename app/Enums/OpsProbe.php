<?php

namespace App\Enums;

/**
 * Les sondes que l'application sert sous `GET /ops/probe/{probe}` — spec 100
 * § 15, liste close.
 *
 * Les autres sondes du tableau ne passent pas par l'application : `/up`
 * (route de santé du framework, étendue par un écouteur de
 * `DiagnosingHealth`), `reverb` (vrai handshake `wss` de bout en bout par la
 * supervision), la sauvegarde (battement sortant du script du tier chaud) et
 * la langue et le cache (deux `GET /` de la supervision).
 *
 * Un nom inconnu rend la même réponse qu'un jeton absent ou faux : 404.
 */
enum OpsProbe: string
{
    /** Âge du battement écrit par le worker de la file `game`. */
    case WorkerGame = 'worker-game';

    /** Âge du battement écrit par le worker de la file `default`. */
    case WorkerDefault = 'worker-default';

    /** Charge moyenne sur 5 minutes et mémoire disponible. */
    case Load = 'load';

    /** Sondes SQL 1 à 3 de 10 § 11.3 et les quatre sondes de frame de 20 § 5.9. */
    case Integrity = 'integrity';

    /** Fraîcheur de la purge par périmètre et sonde n° 4 de 10 § 11.3. */
    case Purge = 'purge';
}
