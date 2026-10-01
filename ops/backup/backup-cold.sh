#!/usr/bin/env bash
#
# Tier froid hebdomadaire de TripleFrames — spec 100 § 13.3 et § 13.4, lot L100-10.
#
# Tâche planifiée Plesk de l'utilisateur d'abonnement, chaque semaine (le
# passage quotidien est proposé au porteur, N100-2). Consignée dans
# ops/plesk/settings.md, section 4.
#
# Périmètre : php artisan backup:manifest --cold, une ligne « <sha256> <chemin
# relatif> » par fichier non retéléchargeable — dérivé de jeu de toute frame
# publiée, master et dérivé de toute capture (D38 du 28/09).
#
# Chaque fichier est chiffré pour la CLÉ PUBLIQUE age et envoyé UNE SEULE FOIS,
# adressé par son condensat :
#
#   game/…   ->  cold/game/<sha256>.webp.age    (= published_hash d'un dérivé publié)
#   master/… ->  cold/master/<sha256>.webp.age  (master d'une capture)
#
# La correspondance condensat -> chemin d'un master vient du manifeste du tier
# chaud (chemin, taille, SHA-256 de chaque fichier) ; celle d'un dérivé publié,
# de frame.published_hash dans le vidage.
#
# La clé d'écriture seule ne permet pas de demander au stockage si un objet y
# est : la liste des objets déjà envoyés est tenue en local, une ligne
# « game/<sha256> » ou « master/<sha256> » par envoi réussi. La perdre ne fait
# que renvoyer des objets identiques (bucket versionné).
#
# Le condensat est relu juste avant l'envoi : un fichier changé depuis le
# manifeste n'est pas envoyé sous un nom qui mentirait sur son contenu.
#
# Codes de sortie : 0 si tout le périmètre est envoyé ou déjà présent ; 1 si un
# fichier du périmètre manque sur le disque, est altéré (dérivé publié dont le
# condensat diffère de published_hash : backup:manifest --cold ne le liste pas
# et sort en 1), a changé ou n'a pu être envoyé — tout ce qui pouvait partir
# est parti avant.
#
# Battement de la supervision (§ 15, sonde « sauvegarde froide ») en cas de
# succès SEULEMENT, comme le tier chaud : un passage qui échoue chaque semaine
# ne reste jamais silencieux.
#
# Secrets et réglages lus dans ~/.config/tripleframes/backup.env (0600, hors
# dépôt), ceux de backup-hot.sh plus :
#
#   FRAMES_DISK_ROOT       racine du disque frames (la même que le .env)
#   BACKUP_COLD_HEARTBEAT_URL  adresse de battement de la sonde du tier froid
#
# [à confirmer au relevé du VPS] age, rclone, sha256sum, flock et curl dans le shell
# de l'abonnement ; drapeaux rclone sans lecture du fournisseur.

set -euo pipefail

umask 077

readonly PHP="${TF_PHP_BIN:-/opt/plesk/php/8.4/bin/php}"

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."

# shellcheck source=/dev/null
source "${HOME:?}/.config/tripleframes/backup.env"
: "${AGE_RECIPIENT:?}" "${BACKUP_REMOTE:?}" "${FRAMES_DISK_ROOT:?}" "${BACKUP_COLD_HEARTBEAT_URL:?}"

if [[ -n "${RCLONE_CONFIG:-}" ]]; then
    export RCLONE_CONFIG
fi

read -r -a rclone_flags <<< "${RCLONE_FLAGS:---s3-no-check-bucket --s3-no-head --no-check-dest --no-traverse}"

state_dir="${HOME}/.local/state/tripleframes"
sent_list="${state_dir}/cold-sent.list"
mkdir -p -- "$state_dir"
touch -- "$sent_list"

exec 9> "${state_dir}/backup-cold.lock"
flock -n 9

# Sous l'état privé de l'abonnement, jamais sous /tmp ; un passage tué sans
# nettoyer laisse un répertoire que le suivant supprime, sous le même verrou.
rm -rf -- "${state_dir}"/cold.work.*
work="$(mktemp -d "${state_dir}/cold.work.XXXXXX")"
cleanup() {
    rm -rf -- "$work"
}
trap cleanup EXIT

status=0

printf '==> [1/2] périmètre du tier froid\n'
"$PHP" artisan backup:manifest --cold --no-ansi > "${work}/cold.txt" || status=1

printf '==> [2/2] chiffrement et envoi des objets nouveaux\n'
sent=0
skipped=0
failed=0
# La liste est lue sur le descripteur 3 : age, rclone et sha256sum gardent
# /dev/null pour entrée, et ne consomment jamais une ligne du manifeste (un
# rclone qui demanderait un mot de passe la prendrait pour une réponse).
while read -r hash path <&3; do
    [[ -n "$hash" ]] || continue

    prefix="${path%%/*}"
    case "$prefix" in
        game | master) ;;
        *)
            failed=$((failed + 1))
            continue
            ;;
    esac

    key="${prefix}/${hash}"
    if grep -qxF -- "$key" "$sent_list"; then
        skipped=$((skipped + 1))
        continue
    fi

    source_file="${FRAMES_DISK_ROOT%/}/${path}"
    current="$(sha256sum -- "$source_file" 2> /dev/null < /dev/null | cut -d ' ' -f 1 || true)"
    if [[ "$current" != "$hash" ]]; then
        failed=$((failed + 1))
        continue
    fi

    object="${work}/object.age"
    if age -r "$AGE_RECIPIENT" -o "$object" "$source_file" < /dev/null \
        && rclone copyto "${rclone_flags[@]}" "$object" "${BACKUP_REMOTE}/cold/${key}.webp.age" < /dev/null; then
        printf '%s\n' "$key" >> "$sent_list"
        sent=$((sent + 1))
    else
        failed=$((failed + 1))
    fi
    rm -f -- "$object"
done 3< "${work}/cold.txt"

printf 'Envoyés : %d ; déjà présents : %d ; en échec : %d.\n' "$sent" "$skipped" "$failed"

if ((failed > 0)); then
    status=1
fi

if ((status != 0)); then
    exit "$status"
fi

printf '==> battement de la supervision\n'
printf 'url = "%s"\n' "$BACKUP_COLD_HEARTBEAT_URL" | curl -fsS --max-time 20 --retry 3 -o /dev/null -K -
