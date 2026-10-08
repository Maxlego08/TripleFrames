#!/usr/bin/env bash
#
# Promotion en un geste — spec 100 § 18, L100-15 (n° 80).
#
# Avance la branche `deploy` (tirée par la production) sur un commit EXACT de
# la branche `deploy-preprod` (tirée par la préproduction), en avance rapide
# seulement. Lancé par le workflow manuel .github/workflows/promote.yml, après
# que le porteur a joué les trois parcours Playwright sur la préproduction
# (spec 100 § 19) ; jouable aussi à la main depuis un clone qui a le droit
# d'écrire. Le tirage Plesk de production et le drainage restent manuels
# (§ 11.4) : ce script ne touche aucun serveur.
#
#   bash ops/deploy/promote.sh <dépôt distant> <commit de deploy-preprod>
#
# Refus (code non nul, rien n'est poussé) :
#   2  commit illisible : 40 caractères hexadécimaux, jamais un nom de branche,
#      pour que soit promu ce qui a été joué et non ce qui a été poussé depuis ;
#   3  commit absent de l'historique de deploy-preprod ;
#   4  deploy n'est pas un ancêtre du commit : la promotion réécrirait
#      l'historique que la production tire, ou la ramènerait en arrière.
# Jamais de poussée forcée : `git push` refuse de lui-même toute poussée qui ne
# serait pas une avance rapide, ce qui double la vérification 4.

set -euo pipefail

readonly REMOTE="${1:?dépôt distant attendu}"
readonly CANDIDATE="${2:?commit de deploy-preprod attendu}"

if [[ ! "$CANDIDATE" =~ ^[0-9a-f]{40}$ ]]; then
    printf 'Commit illisible : %s (40 caractères hexadécimaux attendus).\n' "$CANDIDATE" >&2
    exit 2
fi

git fetch --no-tags --quiet "$REMOTE" "+refs/heads/deploy-preprod:refs/promote/deploy-preprod"

if ! git merge-base --is-ancestor "$CANDIDATE" refs/promote/deploy-preprod; then
    printf 'Refus : %s n est pas un commit de deploy-preprod.\n' "$CANDIDATE" >&2
    exit 3
fi

status=0
git ls-remote --exit-code "$REMOTE" refs/heads/deploy > /dev/null || status=$?

case "$status" in
    0)
        git fetch --no-tags --quiet "$REMOTE" "+refs/heads/deploy:refs/promote/deploy"
        current="$(git rev-parse refs/promote/deploy)"

        if [ "$current" = "$CANDIDATE" ]; then
            printf 'deploy est déjà sur %s : rien à promouvoir.\n' "$CANDIDATE"
            exit 0
        fi

        if ! git merge-base --is-ancestor "$current" "$CANDIDATE"; then
            printf 'Refus : deploy (%s) n est pas un ancêtre de %s ; aucune avance rapide possible.\n' "$current" "$CANDIDATE" >&2
            exit 4
        fi
        ;;
    2)
        printf 'Branche deploy absente : première promotion.\n'
        ;;
    *)
        printf 'Lecture de la branche deploy impossible (code %s).\n' "$status" >&2
        exit "$status"
        ;;
esac

git push --quiet "$REMOTE" "${CANDIDATE}:refs/heads/deploy"
printf 'Promu : deploy avance sur %s.\n' "$CANDIDATE"
