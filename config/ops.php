<?php

/*
|--------------------------------------------------------------------------
| Exploitation — spec 100 § 10.7, § 14 et § 15
|--------------------------------------------------------------------------
|
| Rien ici n'est une valeur de jeu : ce sont les seuils de la supervision
| et de la purge de rétention, lus par le planificateur, les sondes et le
| moteur de purge. Toutes sont des VALEURS DE DÉPART, recalibrées par le
| test de charge (D33 du 23/09) ; seules deux viennent de l'environnement.
|
| `probe_token` : `OPS_PROBE_TOKEN`, comparé en temps constant à l'en-tête
| `X-Probe-Token` par `EnsureProbeToken`. Vide ou absent = toute sonde répond
| 404, en-tête présent ou non : une production qui aurait omis la variable
| n'ouvre pas ses sondes à tous, elle les met toutes en alerte.
|
| `heartbeat.*` : âge maximal, en secondes, du dernier battement écrit par le
| worker de chaque file (`WorkerHeartbeat`, planifié toutes les 30 s sur
| `game`, chaque minute sur `default`). La file `default` peut être tenue
| par une série d'images ou un balayage d'import ; un import qui la tient
| plus de dix minutes est déjà un incident à voir.
|
| `load.*` : la sonde `load` compare la charge moyenne sur 5 minutes à
| `max_per_cpu` × vCPU, et la mémoire disponible à `min_available_percent`
| (lue dans `/proc/meminfo`, si l'`open_basedir` de l'abonnement l'admet ;
| à défaut la sonde ne mesure que la charge). `cpu_count` :
| `OPS_LOAD_CPU_COUNT`, vide = compté dans `/proc/cpuinfo` ; à renseigner
| depuis le relevé du VPS (`nproc`) si l'`open_basedir` ne l'admet pas.
|
| `purge.*` : heure UTC de la purge quotidienne, lots bornés (au plus
| `batch_size` × `max_batches` lignes par périmètre et par nuit), et fenêtre
| de la sonde `purge` : dernière exécution terminée depuis moins de
| `stale_hours` heures, et aucun périmètre sans suppression sur la même
| fenêtre alors que des lignes restent éligibles (10 § 11.3, sonde n° 4).
|
*/

return [

    'probe_token' => env('OPS_PROBE_TOKEN'),

    'heartbeat' => [
        'game_stale_seconds' => 90,
        'default_stale_seconds' => 600,
    ],

    'load' => [
        'max_per_cpu' => 1.5,
        'min_available_percent' => 10.0,
        'cpu_count' => (int) (env('OPS_LOAD_CPU_COUNT') ?: 0),
    ],

    'purge' => [
        'daily_at' => '02:10',
        'batch_size' => 500,
        'max_batches' => 200,
        'stale_hours' => 48,
    ],

];
