# Réglages Plesk de l'abonnement `<DOMAINE>`

Relevé des réglages saisis dans l'interface de Plesk, qui n'ont pas de fichier à recopier (spec `100` § 10). Un réglage système qu'on ne peut pas relire dans le dépôt ne peut pas être revu : ce fichier est mis à jour **dans le commit** qui change l'un d'eux, et le journal en fin de page en garde la trace.

- **État** : préparé le 28/09/2026 (étape 26, lot L100-9), sous l'hypothèse S2 (accès root, Plesk, PHP de l'abonnement en `/opt/plesk/php/8.4/bin/php`). Rien n'est encore appliqué : chaque ligne est à confirmer à la mise en service (étape 27, `ops/mise-en-service.md`), puis datée dans le journal. Répétition jouée le 28/09/2026 sur une VM sans Plesk, chaque réglage de l'interface y étant émulé par un fichier : `docs/ops/repetition-vm.md` (lignes « Sur le VPS Plesk » de chaque étape).
- **Marques** : `__TF_…__` = paramètre inconnu du dépôt, à remplacer ; « À AJUSTER AU RELEVÉ » = valeur de départ, à confirmer ou corriger selon le relevé du VPS (§ 10.1). Liste des paramètres : `ops/mise-en-service.md`, section 1.
- **Jamais ici** : le nom de domaine (écrire `<DOMAINE>`, `NoLiteralDomainTest` balaie `ops/`), une adresse IP, un secret.
- Les libellés de l'interface sont ceux de Plesk Obsidian en français, à corriger selon la version relevée.

## 1. Abonnement et hébergement (§ 10.2)

- Abonnement dédié `<DOMAINE>`, **utilisateur système dédié** `__TF_SUBSCRIPTION_USER__` (groupe `__TF_SUBSCRIPTION_GROUP__`), jamais partagé avec un autre site ; aucun processus du jeu ne tourne en root.
- Accès SSH de cet utilisateur : shell **non chrooté** (`/bin/bash`), pour le hook, composer, `mysqldump` et les tâches planifiées. À AJUSTER AU RELEVÉ.
- Racine du document : `tripleframes/public`. Chemin de déploiement Plesk Git : `tripleframes/` (chemin absolu `__TF_DEPLOY_PATH__`, attendu `/var/www/vhosts/<DOMAINE>/tripleframes`).
- Racines **hors du chemin de déploiement**, sous le répertoire privé de l'abonnement, jamais sous `httpdocs`, en `0700` à l'utilisateur d'abonnement : `FRAMES_DISK_ROOT` (attendu `/var/www/vhosts/<DOMAINE>/private/tripleframes/frames`) et `BACKUP_SNAPSHOT_DIR` (attendu `/var/www/vhosts/<DOMAINE>/private/tripleframes/snapshots`). À AJUSTER AU RELEVÉ : chemin du répertoire privé. Jamais un répertoire voisin dont le nom commence par celui du chemin de déploiement : la garde de `AppServiceProvider::assertFramesDiskRoot()` compare des préfixes de chaîne.
- Certificat Let's Encrypt géré par Plesk, renouvellement automatique. Reverb ne manipule aucun certificat (§ 10.5).
- Redirection permanente HTTP vers HTTPS (« 301 compatible avec le référencement »).
- **HSTS** à durée courte, **sans** `includeSubDomains` ni `preload` au J1. À AJUSTER AU RELEVÉ : durée la plus courte proposée par l'interface, notée ici.
- **Base MySQL dédiée** et utilisateur MySQL aux droits limités à cette base, créés par Plesk. Accès local seulement.

## 2. Apache et nginx (§ 10.5, § 10.8)

- **Mode proxy désactivé** : nginx sert directement PHP-FPM, sans Apache. À AJUSTER AU RELEVÉ : mode de service disponible.
- Service direct des fichiers statiques par nginx : **activé** (assets immuables de `public/build`).
- **Cache nginx : désactivé.** Aucun cache de page complète devant l'application : une même URL sert deux langues.
- Directives nginx supplémentaires : contenu de `ops/nginx/additional-directives.conf`, paramètres remplacés. Après toute modification : vérification « langue et cache » (`ops/mise-en-service.md`, étape 5).
- Directives Apache supplémentaires : aucune.

## 3. PHP de l'abonnement (§ 10.6, § 15)

Réglés dans l'interface, sans root. À AJUSTER AU RELEVÉ : champs réellement offerts par la version de Plesk.

| Réglage                       | Valeur                                                   |
| ----------------------------- | -------------------------------------------------------- |
| Version, gestionnaire         | 8.4, application FPM servie par nginx                    |
| `pm`                          | `ondemand`                                               |
| `pm.max_children`             | `12` (valeur de départ, D33)                             |
| `pm.max_requests`             | `500`                                                    |
| `pm.process_idle_timeout`     | `10s`                                                    |
| `pm.status_path`              | `/fpm-status`                                            |
| `upload_max_filesize`         | `2M` (inchangé)                                          |
| `post_max_size`               | `8M` (inchangé)                                          |
| `opcache.validate_timestamps` | `On`                                                     |
| `date.timezone`               | `UTC`                                                    |
| `open_basedir`                | défaut de Plesk, plus `/proc/meminfo` et `/proc/cpuinfo` |

- `pm.max_children` borne la mémoire que le jeu peut prendre aux voisins : c'est **lui** qui fait que la saturation dégrade le jeu plutôt que les autres sites. Recalibré par la séance de charge (D33 du 23/09).
- `pm.process_idle_timeout` et `pm.status_path` ne sont pas dans le formulaire de base : section `[php-fpm-pool-settings]` des directives PHP supplémentaires. À AJUSTER AU RELEVÉ.
- La page de statut du pool n'est **jamais publiée** : Plesk ne renvoie à PHP-FPM que les URI en `.php`, et `/fpm-status` tombe dans l'application (404). Elle se lit en root, directement sur le socket du pool, au test de charge (§ 16.4) : `SCRIPT_NAME=/fpm-status SCRIPT_FILENAME=/fpm-status REQUEST_METHOD=GET cgi-fcgi -bind -connect /var/www/vhosts/system/<DOMAINE>/php-fpm.sock` (outil `cgi-fcgi` du paquet `libfcgi-bin`). À AJUSTER AU RELEVÉ : chemin du socket.
- Sur ce socket unix, `listen queue` et `max listen queue` de la page de statut restent à `0` sous Linux, même pool saturé (répétition du 28/09 : 12 processus sur 12 occupés, jusqu'à 13 connexions en attente). La file réelle se lit en root par `ss -xlnH` (colonne `Recv-Q` de la ligne du socket en écoute) ; l'engorgement du pool, par `max children reached` de la page de statut, qui croît.
- `opcache.validate_timestamps` reste actif : le déploiement est en place et l'utilisateur d'abonnement ne peut pas recharger FPM ; un OPcache figé servirait l'ancien code.
- `open_basedir` : `/proc/meminfo` et `/proc/cpuinfo` ajoutés pour la sonde `load` (§ 15). Si Plesk refuse l'ajout, la sonde ne mesure que la charge, et `OPS_LOAD_CPU_COUNT` reçoit le `nproc` du relevé.
- Limites d'envoi inchangées : l'application plafonne l'entrée bien en dessous, pour qu'un échec soit toujours une erreur traduite et jamais le 419 muet.

## 4. Tâches planifiées (§ 10.7, § 13.2, § 13.3)

Tâches de l'utilisateur d'abonnement, « Exécuter une commande », **sortie non notifiée**. Heures en UTC. À AJUSTER AU RELEVÉ : fuseau du serveur (`timedatectl`), dans lequel Plesk lit les heures des tâches.

| Tâche            | Fréquence                  | Lot                |
| ---------------- | -------------------------- | ------------------ |
| `schedule:run`   | chaque minute              | L100-9 (étape 27)  |
| `backup-hot.sh`  | chaque jour, 03:10 UTC     | L100-10 (étape 30) |
| `backup-cold.sh` | chaque dimanche, 04:10 UTC | L100-10 (étape 30) |

- Commande du planificateur : `/opt/plesk/php/8.4/bin/php __TF_DEPLOY_PATH__/artisan schedule:run`.
- **Une tâche sous la minute tient `schedule:run` vivant toute la minute** : le battement de la file `game` est planifié toutes les 30 secondes (`everyThirtySeconds()`), si bien que chaque passage reste en vie jusqu'à la fin de sa minute pour lancer le second battement. Il y a donc en permanence un processus PHP CLI du planificateur, à compter dans le budget mémoire, et le passage suivant démarre quand le précédent se termine. Ne jamais activer la notification de sortie : un courriel par minute.
- Le planificateur est surveillé indirectement : s'il s'arrête, les battements vieillissent et les sondes `worker-game` et `worker-default` alertent (§ 15).
- Commandes des sauvegardes : `bash __TF_DEPLOY_PATH__/ops/backup/backup-hot.sh` et `bash __TF_DEPLOY_PATH__/ops/backup/backup-cold.sh`. Chacun force son répertoire sur la racine du déploiement et appelle `/opt/plesk/php/8.4/bin/php` par chemin absolu.
- Le tier chaud tourne **après** la purge de rétention (02:10) et l'élagage des instantanés (02:50) : on sauvegarde le moins de données personnelles possible (§ 13.2). Le tier froid tourne une heure après, pour ne jamais concourir avec lui. Le passage **quotidien** du tier froid est proposé au porteur (N100-2) : ne changer la fréquence qu'avec son accord, en mettant à jour ce tableau.
- Fichier hors dépôt de l'utilisateur d'abonnement, **avec secrets**, `0600` : `~/.config/tripleframes/backup.env`, clés `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `AGE_RECIPIENT` (clé **publique** age ; la clé privée n'est jamais sur le VPS, § 13.4), `BACKUP_REMOTE` (distant rclone et bucket, clé limitée au dépôt d'objets), `BACKUP_HEARTBEAT_URL` (battement de la sonde « sauvegarde », § 15), `BACKUP_COLD_HEARTBEAT_URL` (battement de la sonde « sauvegarde froide », § 15), `FRAMES_DISK_ROOT` (tier froid, la même valeur que le `.env` de l'application) ; facultatives : `RCLONE_CONFIG`, `RCLONE_FLAGS` (drapeaux d'envoi sans lecture, à confirmer selon le fournisseur), `MYSQLDUMP_EXTRA_ARGS` (`--set-gtid-purged=OFF` si le relevé trouve `@@gtid_mode` à `ON`). Le fichier `rclone.conf`, s'il est séparé, est aussi en `0600`.
- État local tenu par les scripts : `~/.local/state/tripleframes/` (verrous, `cold-sent.list`, liste des objets froids déjà envoyés — la perdre ne fait que renvoyer des objets identiques —, et répertoires de travail `hot.work.*` et `cold.work.*`, jamais sous `/tmp` : le vidage en clair n'y vit que le temps de son chiffrement, et un reste d'un passage tué est supprimé au passage suivant).
- Chaque tier bat sa propre sonde, en dernière commande et seulement en cas de succès : « sauvegarde » (< 26 h) pour le tier chaud, « sauvegarde froide » (< 8 j au rythme hebdomadaire) pour le tier froid. Une tâche qui échoue ou s'arrête alerte d'elle-même, malgré la sortie non notifiée. L'adresse de battement porte le jeton de la sonde : elle passe à `curl` par l'entrée standard, jamais en argument.

## 5. Journaux (§ 10.9)

- Rotation des journaux de l'abonnement (nginx, seul lieu des adresses IP) : quotidienne, **30 fichiers au plus**, compressés. À AJUSTER AU RELEVÉ : réglage offert par Plesk.
- Journaux de l'application : `storage/logs`, canaux `daily` et `game`, 14 jours (`LOG_STACK=daily`, `LOG_DAILY_DAYS=14`), hors rotation Plesk.

## 6. Git (§ 11.2, § 11.6)

- Dépôt distant : la forge, **branche `deploy`** (écrite par le seul job `artifacts`). Clé de déploiement SSH générée par Plesk, enregistrée **en lecture seule** sur la forge.
- Mode de déploiement : **manuel**. Aucun webhook.
- Actions de déploiement additionnelles : **vides** au premier déploiement ; ensuite `bash ops/deploy/hook.sh` (mise en service, étape 7). À AJUSTER AU RELEVÉ : répertoire courant et délai maximal des actions additionnelles (le hook force son répertoire lui-même).
- Fichier hors dépôt de l'utilisateur d'abonnement, sans secret : `~/.config/tripleframes/hook.env`, une ligne `COMPOSER_PHAR=<chemin absolu de composer.phar>`.

## 7. Pare-feu (§ 10.5)

- Aucune règle n'ouvre le port de Redis (`__TF_REDIS_PORT__`) ni celui de Reverb (`__TF_REVERB_PORT__`). Les deux écoutent sur la seule boucle locale ; le pare-feu est une défense de plus, pas la seule. À AJUSTER AU RELEVÉ : politique entrante par défaut du pare-feu de Plesk, notée ici.

## 8. Posé par root, hors interface

Fichiers versionnés dans `ops/`, recopiés une fois par root (`ops/mise-en-service.md`, étape 4) ; toute modification passe d'abord par le dépôt.

| Source dans le dépôt                  | Emplacement sur le serveur                  |
| ------------------------------------- | ------------------------------------------- |
| `ops/redis/tripleframes-redis.conf`   | `/etc/tripleframes/` (`0640`, root:tfredis) |
| `ops/systemd/worker-*.env`            | `/etc/tripleframes/` (`0644`)               |
| `ops/systemd/*.service`               | `/etc/systemd/system/` (`0644`)             |
| `ops/systemd/*.service.d/limits.conf` | `/etc/systemd/system/`, même arborescence   |

## Journal des changements

Une ligne par réglage appliqué ou modifié : date, section, valeur, commit.

| Date | Section | Changement                           | Commit |
| ---- | ------- | ------------------------------------ | ------ |
| —    | —       | aucun réglage appliqué au 28/09/2026 | —      |
