#!/usr/bin/env bash
# =============================================================================
# deploy-vm.sh — met à jour la prod de RÉPÉTITION de la VM Homestead
# (https://tripleframes-prod.test) avec la branche `develop` COMMITÉE du dépôt.
#
# À LANCER DEPUIS GIT BASH, SUR LE POSTE WINDOWS, jamais sur la VM :
#     bash docs/ops/deploy-vm.sh              # CI locale comprise (~15 min)
#     bash docs/ops/deploy-vm.sh --skip-ci    # sans composer ci:check (~3 min)
#     bash docs/ops/deploy-vm.sh --check      # relevé de la VM seulement
#
# Ce qu'il fait, dans l'ordre (runbook docs/ops/repetition-vm.md, 100 § 11.4) :
#   1. relève la VM : unités, parties en cours, drainage, caches root ;
#   2. prend `develop` du dépôt dans le clone de construction, réactive le
#      drainage du hook (étapes 3 et 12, commit local jamais poussé) ;
#   3. arbre jetable : composer install, Wayfinder, [ci:check] ;
#   4. build des assets, commit `deploy` n° N (parent = le précédent), bundle ;
#   5. « Tirer » sur la VM (fetch du bundle dans le dépôt nu) ;
#   6. « Déployer » : drainage, garde, checkout (umask 022), hook, levée ;
#   7. contrôles : unités, /up, sondes, droits de public/ et des caches.
#
# Rien de ceci ne se joue sur le VPS : là-bas, la CI et Plesk Git font 2 à 5.
# Le .env de la VM n'est JAMAIS modifié par ce script.
# =============================================================================
set -euo pipefail

# --- Réglages -----------------------------------------------------------------
# Kit de la répétition : clé SSH, known_hosts, clone de construction (dont la
# branche `deploy` est la seule à porter la bonne chaîne de parents). Il vit
# dans %TEMP% : copie-le dans un dossier privé, HORS du dépôt, et pointe
# VM_KIT dessus (ex. VM_KIT=/c/Users/Admin/tripleframes-vm-kit bash …).
VM_KIT="${VM_KIT:-/c/Users/Admin/AppData/Local/Temp/claude/C--Users-Admin-Desktop-groupez-tripleframes/b09c7742-d0ac-4e3b-99b0-896bc951d09c/scratchpad/vm}"
VM_HOST="${VM_HOST:-vagrant@192.168.10.10}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SRC="$VM_KIT/build/src"                 # clone de construction
WT="$VM_KIT/../wt-deploy"               # arbre jetable (vendor, node_modules)
REMOTE_TMP=/home/vagrant/tripleframes-repetition
APP_HOME=/var/www/vhosts/tripleframes-prod.test
SKIP_CI=0; CHECK_ONLY=0
for a in "$@"; do
    case "$a" in
        --skip-ci) SKIP_CI=1 ;;
        --check) CHECK_ONLY=1 ;;
        -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
        *) echo "option inconnue : $a" >&2; exit 64 ;;
    esac
done

# --- Outils -------------------------------------------------------------------
step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mARRÊT : %s\033[0m\n' "$*" >&2; exit 1; }
KEY_OPTS=(-i "$VM_KIT/id_vm" -o UserKnownHostsFile="$VM_KIT/known_hosts" -o ConnectTimeout=15 -o BatchMode=yes)
vm()     { ssh "${KEY_OPTS[@]}" "$VM_HOST" "$@"; }          # vagrant
vm_root(){ vm 'sudo bash -s'; }                            # script root sur stdin
vm_abo() { vm 'sudo -u tripleframes -H bash -s'; }         # script abonnement sur stdin

# --- 0. Préalables du poste ---------------------------------------------------
step "0. Préalables du poste"
[ -n "${MSYSTEM:-}" ] || [ "$(uname -s | cut -c1-5)" = "MINGW" ] \
    || die "lance ce script depuis Git Bash sur le poste Windows, pas sur la VM."
[ -f "$VM_KIT/id_vm" ] || die "kit introuvable ($VM_KIT/id_vm). Règle VM_KIT."
[ -d "$SRC/.git" ]     || die "clone de construction introuvable ($SRC)."
git -C "$SRC" rev-parse -q --verify refs/heads/deploy >/dev/null \
    || die "le clone n'a pas de branche deploy : la VM refuserait l'artefact."
for t in php composer npm node ssh scp; do command -v "$t" >/dev/null || die "$t absent du PATH."; done
vm 'true' || die "connexion SSH à la VM impossible (VM éteinte ? vagrant up)."
if [ -n "$(git -C "$REPO" status --porcelain --untracked-files=no)" ]; then
    echo "Attention : le dépôt a des modifications NON commitées ; elles ne partent pas."
fi
echo "develop du dépôt : $(git -C "$REPO" log --oneline -1 develop)"

# --- 1. Relevé de la VM -------------------------------------------------------
step "1. Relevé de la VM"
vm_root <<EOF
set -uo pipefail
cd $APP_HOME/tripleframes
for u in tripleframes-php-fpm tripleframes-redis tripleframes-worker@game tripleframes-worker@default tripleframes-reverb; do
    printf '  %-28s %s\n' "\$u" "\$(systemctl is-active \$u)"
done
echo "  déployé : \$(git --git-dir=$APP_HOME/git/tripleframes.git log --oneline -1 deploy)"
echo "  \$(grep -E '^APP_ENV=' .env)   \$(grep -E '^ACCOUNTS_REGISTRATION_OPEN=' .env)"
echo "  parties en cours : \$(bash $REMOTE_TMP/outils/sqlp.sh "SELECT COUNT(*) FROM game WHERE ended_at IS NULL AND status IN ('running','paused')" | tail -1)"
roots=\$(find bootstrap/cache -maxdepth 1 -name '*.php' -user root | wc -l)
echo "  caches de démarrage appartenant à root : \$roots"
EOF
[ "$CHECK_ONLY" -eq 1 ] && { echo; echo "Relevé seulement (--check) : rien n'a été modifié."; exit 0; }

IN_PROGRESS="$(vm "sudo bash $REMOTE_TMP/outils/sqlp.sh \"SELECT COUNT(*) FROM game WHERE ended_at IS NULL AND status IN ('running','paused')\"" | tail -1 | tr -dc '0-9')"
[ "${IN_PROGRESS:-1}" = "0" ] || die "une partie est en cours sur la VM : attends sa fin (ou suis l'étape 4.13 du runbook)."

# Rattrapage : un `artisan` lancé en root a laissé des caches root:root 644
# (config.php = copie des secrets, lisible de tous). On les rend à l'abonnement ;
# le hook les régénère de toute façon à son étape 8.
vm_root <<EOF
cd $APP_HOME/tripleframes
find bootstrap/cache -maxdepth 1 -name '*.php' -user root -print -exec chown tripleframes:tripleframes {} + -exec chmod 0640 {} +
EOF

# --- 2. Source : develop du dépôt + activation du drainage --------------------
step "2. Source dans le clone de construction"
cd "$SRC"
[ -z "$(git status --porcelain --untracked-files=no)" ] || die "le clone $SRC a des modifications en cours."
PREV_SRC="$(git rev-parse develop)"
git branch -f "avant-$(date +%Y%m%d-%H%M%S)" develop
git fetch -q "$REPO" develop:refs/remotes/porteur/develop
git checkout -q -B develop porteur/develop
# develop garde les étapes 3 et 12 du hook commentées tant que l'étape 126
# n'est pas livrée ; la prod de la VM, elle, a le drainage ACTIF.
if grep -q '^# drain: step' ops/deploy/hook.sh; then
    sed -i 's/^# drain: step 3 /step 3 /; s/^# drain: step 12 /step 12 /' ops/deploy/hook.sh
    sed -i '/^function deployHookDrainSteps(): array$/,/^}$/ s/return \[3, 12\];/return [];/' tests/Feature/Deploy/DeployHookTest.php
    bash -n ops/deploy/hook.sh
    git -c user.name="répétition" -c user.email="repetition@tripleframes-prod.test" \
        commit -qam "répétition : activation du drainage dans le hook (I-13), jamais poussé"
fi
[ "$(grep -cE '^step (3|12) ' ops/deploy/hook.sh)" = "2" ] || die "le hook n'a pas ses étapes 3 et 12 actives."
echo "source : $(git log --oneline -1 develop)"

# --- 3. Arbre jetable : dépendances, Wayfinder, CI ----------------------------
step "3. Arbre jetable (composer, Wayfinder$([ $SKIP_CI -eq 0 ] && echo ', ci:check'))"
if [ ! -d "$WT" ]; then
    git -C "$SRC" worktree add -q --detach "$WT" develop
else
    git -C "$WT" checkout -q -f --detach develop
    git -C "$WT" clean -qfdx -e vendor -e node_modules -e .env
fi
cp "$REPO/.env" "$WT/.env"
cd "$WT"
composer install --no-interaction --no-progress
npm ci --no-audit --no-fund
php artisan wayfinder:generate --with-form
if [ "$SKIP_CI" -eq 0 ]; then
    [ ! -e public/hot ] || rm -f public/hot
    npm run build
    composer ci:check || die "composer ci:check est rouge : rien n'est déployé."
fi

# --- 4. Build, commit deploy, bundle -----------------------------------------
step "4. Assets et artefact"
cd "$SRC"
rm -rf resources/js/actions resources/js/routes resources/js/wayfinder
cp -r "$WT/resources/js/actions" "$WT/resources/js/routes" "$WT/resources/js/wayfinder" resources/js/
if ! git diff --quiet "$PREV_SRC" develop -- package-lock.json || [ ! -d node_modules ]; then
    npm ci --no-audit --no-fund
fi
# Faux php en tête du PATH : le greffon Wayfinder de Vite ne régénère rien.
PATH="$VM_KIT/build/php-stub:$PATH" npm run build
BAD="$(find public/build \( -iname '*.php*' -o -iname '*.phtml' -o -iname '*.phar' -o -name '.*' \) -print)"
[ -z "$BAD" ] || die "fichiers interdits dans public/build : $BAD"

N=$(( $(git rev-list --count deploy) + 1 ))
OUT="$(bash -s < "$VM_KIT/deploiement/rv10-artifacts.sh")"; echo "$OUT"
case "$OUT" in *"rien à publier"*) echo "Déjà à jour : rien à déployer."; exit 0 ;; esac
git bundle create -q "../deploy-$N.bundle" deploy
echo "artefact n° $N : $(git log --oneline -1 deploy)"

# --- 5. « Tirer » --------------------------------------------------------------
step "5. Transfert et « Tirer » (n° $N)"
scp -q "${KEY_OPTS[@]}" "../deploy-$N.bundle" "$VM_HOST:$REMOTE_TMP/deploy-$N.bundle"
vm "bash -s $N" <<'EOF'
set -euo pipefail
N="$1"; H=/var/www/vhosts/tripleframes-prod.test
sudo install -o tripleframes -g tripleframes -m 0600 "/home/vagrant/tripleframes-repetition/deploy-$N.bundle" "$H/incoming/deploy-$N.bundle"
sudo -u tripleframes -H bash -c "
umask 027; cd ~
git -C ~/git/tripleframes.git bundle verify -q ~/incoming/deploy-$N.bundle && echo 'bundle vérifié'
git -C ~/git/tripleframes.git fetch -q ~/incoming/deploy-$N.bundle deploy:deploy
git -C ~/git/tripleframes.git log --oneline -2 deploy"
EOF

# --- 6. « Déployer » (100 § 11.4) ---------------------------------------------
step "6. Drainage, garde, checkout, hook, levée"
LOG="$VM_KIT/build/deploy-$N.log"
set +e
vm_abo < "$VM_KIT/charge/deploy-drainer-deployer.sh" 2>&1 | tee "$LOG"
set -e
grep -q '^code du hook = 0' "$LOG" || die "le hook n'a pas abouti (journal : $LOG). Voir 100 § 11.5 : réparer, deploy:drain, relancer le hook."
grep -q 'public/ illisible des autres comptes : 0' "$LOG" \
    || { echo "public/ a des fichiers illisibles : rattrapage"; vm_abo < "$VM_KIT/cloture/public-lisible.sh"; }

# --- 7. Contrôles ---------------------------------------------------------------
step "7. Contrôles"
vm_root < "$VM_KIT/partie/vm/p-sondes.sh"
vm_root <<EOF
cd $APP_HOME/tripleframes
echo "déployé : \$(git --git-dir=$APP_HOME/git/tripleframes.git log --oneline -1 deploy)"
stat -c '%a %U:%G' public/build/manifest.json public/build/assets/* | sort | uniq -c
stat -c '%a %U:%G %n' bootstrap/cache/*.php
EOF
echo
echo "Terminé. Ouvre https://tripleframes-prod.test (ligne 192.168.10.10 dans le fichier hosts)."
echo "Journal du déploiement : $LOG"
