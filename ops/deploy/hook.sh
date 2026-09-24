#!/usr/bin/env bash
#
# Hook de déploiement de TripleFrames — spec 100 § 11.5, contrat C18-bis.
#
# Lancé par les actions de déploiement additionnelles de Plesk Git
# (« bash ops/deploy/hook.sh »), APRÈS le dépôt des fichiers : un échec
# n'arrête que les étapes suivantes, jamais la mise à jour des fichiers
# (déploiement non atomique assumé, D31 du 23/09). Remède écrit au § 11.5.
#
# Ordre NORMATIF en douze étapes, prouvé par tests/Feature/Deploy/DeployHookTest.php.
# Livré SANS drainage tant que le moteur de jeu n'existe pas (D37 du 23/09) :
# les étapes 3 (deploy:guard) et 12 (deploy:release) sont ajoutées par le lot
# L100-5, au plus tard dans le déploiement qui porte le moteur en production.
#
# Jamais cache:clear (il viderait le drapeau de drainage et échoue de toute
# façon, FLUSHDB étant désactivé sur le Redis dédié), jamais systemctl (non
# privilège : le hook tourne sous l'utilisateur d'abonnement), jamais un PHP
# du système : tout passe par le PHP de l'abonnement, par chemin absolu —
# artisan comme composer, dont le shebang désignerait sinon le PHP du PATH.
#
# [à confirmer au relevé du VPS] répertoire courant et délai des actions
# additionnelles ; HOME défini pour l'utilisateur d'abonnement ; chemin de
# composer.phar.

set -euo pipefail

# PHP de l'abonnement, figé AVANT la lecture de hook.env : `readonly` interdit
# à ce fichier de le redéfinir.
readonly PHP=/opt/plesk/php/8.3/bin/php

# Répertoire courant forcé sur la racine du déploiement, quel que soit celui
# des actions additionnelles.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."

# Chemins propres à la machine, hors dépôt, sans aucun secret :
#   COMPOSER_PHAR=<chemin absolu de composer.phar>
# shellcheck source=/dev/null
source "${HOME:?}/.config/tripleframes/hook.env"
: "${COMPOSER_PHAR:?}"

step() {
    local number="$1"
    shift
    printf '\n==> [%s/12] %s\n' "$number" "$*"
    "$@"
}

step 1 "$PHP" "$COMPOSER_PHAR" install --no-dev --optimize-autoloader --no-interaction
step 2 "$PHP" artisan optimize:clear --except=cache
step 4 "$PHP" artisan backup:snapshot --if-pending
step 5 "$PHP" artisan migrate --force
step 6 "$PHP" artisan db:seed --class=PlatformDataSeeder --force
step 7 "$PHP" artisan catalog:reproject
step 8 "$PHP" artisan optimize
step 9 "$PHP" artisan lang:hash
step 10 "$PHP" artisan queue:restart
step 11 "$PHP" artisan reverb:restart
