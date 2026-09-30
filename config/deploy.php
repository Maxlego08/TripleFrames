<?php

/*
|--------------------------------------------------------------------------
| Drainage de déploiement — spec 100 § 11.3, contrat C18-bis
|--------------------------------------------------------------------------
|
| Lu par `App\Support\Deploy\DeployDrain` et par les commandes `deploy:*`.
| Aucune valeur de jeu ici : la borne d'attente se DÉRIVE de la plus longue
| partie possible (`GamesInProgress::maxNaturalDurationMs()`, spec 60).
|
| `drain_timeout_minutes` : `DEPLOY_DRAIN_TIMEOUT_MINUTES`, vide = null =
| `DeployDrain::defaultTimeoutMinutes()`, soit environ 98 minutes aux bornes
| actuelles. L'option `--timeout` de `deploy:drain` l'emporte. Seul le vide
| vaut « par défaut » : `0`, comme toute valeur qui n'est pas un entier ≥ 1,
| est REFUSÉ par la commande (code 2), jamais remplacé en silence.
|
| `drain_margin_minutes` : ajoutée à la durée naturelle maximale ; elle couvre
| au moins une pause, attente puis décompte de reprise (`pauseTimeoutMs +
| launchCountdownMs`), ce que `DeployDrainCommandTest` prouve.
|
| `window_minutes` : `DEPLOY_WINDOW_MINUTES`, vide = 30 (`0` refusé, comme
| ci-dessus). La fenêtre libre
| laisse le temps de vérifier `deploy:guard`, de cliquer « Déployer » et de
| laisser le hook finir ; au-delà, le drapeau expire seul. L'option `--window`
| l'emporte.
|
| `poll_seconds` : intervalle entre deux relevés des parties en cours, un
| `COUNT` servi par l'index `game_ended_idx`.
|
| `cache_key` : l'entrée du cache par défaut qui porte le drapeau. L'étape 2
| du hook vide les caches de démarrage, JAMAIS le cache applicatif.
|
*/

return [

    'drain_timeout_minutes' => filled(env('DEPLOY_DRAIN_TIMEOUT_MINUTES')) ? env('DEPLOY_DRAIN_TIMEOUT_MINUTES') : null,

    'drain_margin_minutes' => 20,

    'window_minutes' => filled(env('DEPLOY_WINDOW_MINUTES')) ? env('DEPLOY_WINDOW_MINUTES') : 30,

    'poll_seconds' => 15,

    'cache_key' => 'deploy:drain',

];
