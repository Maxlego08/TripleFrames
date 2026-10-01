#!/usr/bin/env bash
#
# Tier chaud quotidien de TripleFrames — spec 100 § 13.2 et § 13.4, lot L100-10.
#
# Tâche planifiée Plesk de l'utilisateur d'abonnement, chaque jour à 03:10 UTC,
# après la purge de rétention (§ 14) : on sauvegarde le moins de données
# personnelles possible. Consignée dans ops/plesk/settings.md, section 4.
#
#   1. Vidage MySQL : structure de toutes les tables, données de toutes SAUF
#      les sept tables exclues (même liste que backup:snapshot), en deux passes
#      de mysqldump --single-transaction --quick --no-tablespaces.
#   2. Manifeste du disque frames : php artisan backup:manifest.
#   3. Compression gzip, vérification (intégrité gzip, deux lignes de fin de
#      vidage), chiffrement age pour la CLÉ PUBLIQUE, envoi par rclone sous
#      hot/<AAAA-MM-JJ>/ avec une clé limitée au dépôt d'objets.
#   4. Battement de la supervision (§ 15) en cas de succès SEULEMENT : la sonde
#      « sauvegarde de moins de 26 h » est un interrupteur d'homme mort.
#
# La clé privée age n'est JAMAIS sur le VPS (§ 13.4) : rien ici ne déchiffre.
# Aucun élagage depuis le VPS : la rétention de 30 jours est une règle de cycle
# de vie du fournisseur.
#
# Secrets lus dans ~/.config/tripleframes/backup.env (0600, hors dépôt), jamais
# affichés, jamais en argument d'une commande (la liste des processus d'une
# machine mutualisée est lisible des voisins) :
#
#   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD   base de production
#   AGE_RECIPIENT          clé publique age (age1…), la seule clé sur le VPS
#   BACKUP_REMOTE          destination rclone, distant:bucket (clé en écriture seule)
#   BACKUP_HEARTBEAT_URL   adresse de battement de la supervision
#   RCLONE_CONFIG          facultatif, chemin du fichier rclone.conf (0600)
#   RCLONE_FLAGS           facultatif, remplace les drapeaux d'envoi sans lecture
#   MYSQLDUMP_EXTRA_ARGS   facultatif, ex. --set-gtid-purged=OFF (relevé du VPS)
#
# PHP de l'abonnement par chemin absolu ; TF_PHP_BIN, posé dans l'environnement
# de la tâche (jamais dans backup.env), le remplace sur une autre machine.
#
# [à confirmer au relevé du VPS] mysqldump, age, rclone, gzip, flock et curl
# dans le shell de l'abonnement ; drapeaux rclone sans lecture du fournisseur.

set -euo pipefail

# Tout fichier créé ici, vidage en clair compris, n'est lisible que de
# l'utilisateur d'abonnement.
umask 077

readonly PHP="${TF_PHP_BIN:-/opt/plesk/php/8.4/bin/php}"

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."

# shellcheck source=/dev/null
source "${HOME:?}/.config/tripleframes/backup.env"
: "${DB_DATABASE:?}" "${DB_USERNAME:?}" "${DB_PASSWORD:?}"
: "${AGE_RECIPIENT:?}" "${BACKUP_REMOTE:?}" "${BACKUP_HEARTBEAT_URL:?}"

if [[ -n "${RCLONE_CONFIG:-}" ]]; then
    export RCLONE_CONFIG
fi

# Dépôt d'objets sans lecture préalable : ni vérification du bucket, ni liste
# de la destination, ni HEAD après envoi. [à confirmer selon le fournisseur]
read -r -a rclone_flags <<< "${RCLONE_FLAGS:---s3-no-check-bucket --s3-no-head --no-check-dest --no-traverse}"
read -r -a dump_extra <<< "${MYSQLDUMP_EXTRA_ARGS:-}"

readonly EXCLUDED_DATA_TABLES=(
    sessions
    cache
    cache_locks
    jobs
    job_batches
    failed_jobs
    password_reset_tokens
)

state_dir="${HOME}/.local/state/tripleframes"
mkdir -p -- "$state_dir"

# Un seul passage à la fois.
exec 9> "${state_dir}/backup-hot.lock"
flock -n 9

# Répertoire de travail sous l'état privé de l'abonnement, jamais sous /tmp
# (souvent un petit tmpfs, hors de tout contrôle de rétention) : le vidage en
# clair n'y vit que le temps de sa compression et de son chiffrement. Un
# passage tué sans pouvoir nettoyer (SIGKILL, délai Plesk, redémarrage) laisse
# un répertoire que le passage suivant supprime, sous le même verrou.
rm -rf -- "${state_dir}"/hot.work.*
work="$(mktemp -d "${state_dir}/hot.work.XXXXXX")"
cleanup() {
    rm -rf -- "$work"
}
trap cleanup EXIT

# Fichier d'options [client] : le mot de passe n'est jamais en argument.
credentials="${work}/client.cnf"
option_value() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    printf '"%s"' "$value"
}
{
    printf '[client]\n'
    printf 'user=%s\n' "$(option_value "$DB_USERNAME")"
    printf 'password=%s\n' "$(option_value "$DB_PASSWORD")"
    if [[ -n "${DB_HOST:-}" ]]; then
        printf 'host=%s\n' "$(option_value "$DB_HOST")"
    fi
    if [[ -n "${DB_PORT:-}" ]]; then
        printf 'port=%s\n' "$(option_value "$DB_PORT")"
    fi
} > "$credentials"

common=(
    "--defaults-extra-file=${credentials}"
    --single-transaction
    --quick
    --no-tablespaces
    # Forme sûre sous set -u pour un tableau vide, même avant bash 4.4.
    ${dump_extra[@]+"${dump_extra[@]}"}
)

ignored=()
for table in "${EXCLUDED_DATA_TABLES[@]}"; do
    ignored+=("--ignore-table=${DB_DATABASE}.${table}")
done

instant="$(date -u +%Y%m%dT%H%M%SZ)"
day="$(date -u +%Y-%m-%d)"
dump="${work}/db-${instant}.sql.gz"
manifest="${work}/manifest-${instant}.txt.gz"

printf '==> [1/4] vidage de la base\n'
{
    mysqldump "${common[@]}" --no-data "$DB_DATABASE"
    mysqldump "${common[@]}" --no-create-info --skip-triggers "${ignored[@]}" "$DB_DATABASE"
} | gzip -c > "$dump"

gzip -t "$dump"
completed="$(gzip -dc "$dump" | grep -c '^-- Dump completed' || true)"
if [[ "$completed" != "2" ]]; then
    printf 'Vidage incomplet : %s ligne(s) de fin de vidage sur 2.\n' "$completed" >&2
    exit 1
fi
rm -f -- "$credentials"

printf '==> [2/4] manifeste du disque frames\n'
"$PHP" artisan backup:manifest --no-ansi | gzip -c > "$manifest"
gzip -t "$manifest"

printf '==> [3/4] chiffrement et envoi sous hot/%s/\n' "$day"
for file in "$dump" "$manifest"; do
    age -r "$AGE_RECIPIENT" -o "${file}.age" "$file"
    rm -f -- "$file"
    rclone copyto "${rclone_flags[@]}" "${file}.age" "${BACKUP_REMOTE}/hot/${day}/$(basename -- "$file").age"
done

printf '==> [4/4] battement de la supervision\n'
# L'adresse porte le jeton de la sonde : passée par une configuration curl sur
# l'entrée standard, jamais en argument (liste des processus lisible).
printf 'url = "%s"\n' "$BACKUP_HEARTBEAT_URL" | curl -fsS --max-time 20 --retry 3 -o /dev/null -K -
