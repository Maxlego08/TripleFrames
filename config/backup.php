<?php

/*
|--------------------------------------------------------------------------
| Instantanés bloquants — spec 100 § 13.1 (règle 12, contrat C18-bis)
|--------------------------------------------------------------------------
|
| `backup:snapshot` prend un vidage LOCAL et NON CHIFFRÉ de la base avant
| toute migration en attente (hook de déploiement, étape 4) et avant tout
| geste de console qui écrit `movie`, `frame`, `movie_title`, `alias` ou
| `frame_review`. Il sert à revenir en arrière après une migration ratée,
| jamais à survivre à la perte de la machine : c'est le rôle du tier chaud
| (§ 13.2, lot L100-10), qui n'a rien à voir avec ce fichier.
|
| `snapshot_dir` : `BACKUP_SNAPSHOT_DIR`, vide = `storage/app/snapshots`, et
| c'est un chemin ABSOLU (une racine relative se résoudrait contre le
| répertoire courant du processus). Hors `local` et `testing`, la commande
| refuse tout répertoire vide ou situé sous le répertoire de déploiement :
| un re-clonage Plesk emporterait les instantanés avec le code, et
| `storage/` ne contient rien d'irremplaçable (§ 10.2). Le repli ci-dessous
| est donc refusé en production par construction — même parti que la racine
| du disque `frames`, et la garde vit dans la commande, jamais ici : un
| fichier de configuration ne doit jamais lever.
|
| `snapshot_keep_days` : `BACKUP_SNAPSHOT_KEEP_DAYS`, vide = 7. Ces vidages
| portent des données personnelles ; `backup:prune-snapshots` les élague
| chaque jour et ramène toute valeur à 30 jours au plus (10 § 11.1).
|
| `prune_at` : heure UTC de l'élagage quotidien (§ 10.7), après la purge de
| rétention de 02:10 et avant le tier chaud de 03:10.
|
*/

return [

    'snapshot_dir' => ((string) env('BACKUP_SNAPSHOT_DIR', '')) ?: storage_path('app/snapshots'),

    'snapshot_keep_days' => (int) (env('BACKUP_SNAPSHOT_KEEP_DAYS') ?: 7),

    'prune_at' => '02:50',

];
