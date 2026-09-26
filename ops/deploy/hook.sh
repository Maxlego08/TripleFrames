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
# Livré SANS drainage tant que le moteur de jeu n'existe pas (D37 du 23/09).
# Les étapes 3 (deploy:guard) et 12 (deploy:release) sont PRÊTES, à leur place,
# sur les deux lignes « # drain: » ci-dessous, et restent INACTIVES : c'est la
# transition I-13, en deux déploiements (accord du porteur du 24/09).
#
#   1. Premier déploiement : il porte le moteur, le drapeau de drainage et les
#      commandes deploy:drain, deploy:guard et deploy:release, avec ce hook
#      SANS les étapes 3 et 12. Avant lui, aucune partie ne peut exister en
#      production : aucun drainage n'est nécessaire. Active, l'étape 3
#      arrêterait ce déploiement lui-même, faute de fenêtre libre : aucun
#      deploy:drain n'a pu tourner avant, la commande n'existant pas encore
#      sur le serveur.
#   2. Commit d'activation : retirer le préfixe « # drain: » des deux lignes
#      et vider deployHookDrainSteps() dans DeployHookTest, rien d'autre.
#   3. Second déploiement, par la procédure complète du § 11.4 : deploy:drain
#      jusqu'à la fenêtre libre, deploy:guard à la main, puis « Déployer ».
#      Tout déploiement suivant suit le § 11.4.
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
# drain: step 3 "$PHP" artisan deploy:guard
step 4 "$PHP" artisan backup:snapshot --if-pending
step 5 "$PHP" artisan migrate --force
step 6 "$PHP" artisan db:seed --class=PlatformDataSeeder --force
step 7 "$PHP" artisan catalog:reproject
step 8 "$PHP" artisan optimize
step 9 "$PHP" artisan lang:hash
step 10 "$PHP" artisan queue:restart
step 11 "$PHP" artisan reverb:restart
# drain: step 12 "$PHP" artisan deploy:release
