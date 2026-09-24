<?php

/*
|--------------------------------------------------------------------------
| Empreintes de la règle de validation, par version (spec 70 § 12)
|--------------------------------------------------------------------------
|
| `AnswerRules::fingerprint()` : SHA-256 du JSON canonique des paramètres
| de la règle — séparateurs et longueur minimale de `config/catalog.php`,
| articles, règles romaine, des chiffres et du sous-titre, barème de
| tolérance, marge de quasi-juste, longueur maximale, translittération.
|
| Une entrée par version, JAMAIS réécrite : un changement de configuration
| ou de constante sans nouvelle version fait échouer
| `ValidationVersionFingerprintTest`, et le remède est d'incrémenter
| `AnswerRules::VERSION` puis d'AJOUTER ici l'entrée de la nouvelle version,
| à côté d'un nouveau `normalizer-v{n}.php`. Seule l'entrée de la version
| courante est exécutée ; les précédentes restent l'historique opposable de
| ce qu'a signifié `game.validation_version`.
|
*/

return [
    1 => '765630258d12850a378a98f1b629faad89dfdfaef970592bc455778f52d3b34e',
];
