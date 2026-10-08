# Mise en service initiale du VPS — liste de contrôle

Liste de contrôle de la mise en service initiale (spec `100` § 11.6), lot L100-9. Préparée par l'IA le 28/09/2026 (étape 26 de `docs/REPRISE.md`, annexe), **jouée par le porteur** : étapes 27 (mise en service, montage root), 28 (premier administrateur) et 29 (premier déploiement par le hook). Elle ne décide rien : en cas d'écart, la spec fait foi (`100` § 10, § 11, § 13.1, § 15). **Répétée de bout en bout sur la VM Homestead le 28/09/2026** : `docs/ops/repetition-vm.md` donne, pour chaque étape ci-dessous, les commandes exactes jouées, le résultat obtenu et ce qui change sur le VPS (section « Ordre de rejeu sur le VPS »).

**Terminé (L100-9)** quand `/up` et les sondes sont vertes, que le relevé et `ops/plesk/settings.md` sont consignés et qu'un premier déploiement complet par le hook a réussi, sous sa forme sans drainage.

## Conventions

- `<DOMAINE>` : le nom de domaine, remplacé **sur le serveur seulement**. Il n'entre jamais dans le dépôt, ni aucune adresse IP ni aucun secret.
- `__TF_…__` : paramètre inconnu du dépôt, à remplacer (liste à la section 1). Le contrôle final (section 9) vérifie qu'il n'en reste aucun sur le serveur.
- « À AJUSTER AU RELEVÉ » : valeur de départ plausible, à confirmer ou corriger selon le relevé.
- « root » : session SSH root. « abonnement » : session SSH de l'utilisateur d'abonnement, dans le répertoire de déploiement. Dans les commandes, `PHP=/opt/plesk/php/8.4/bin/php` : jamais un `php` du système, dont `artisan` et `composer.phar` désigneraient sinon le binaire par leur shebang.
- **Toute session de l'abonnement commence par `umask 027`**, comme le hook : un `"$PHP" artisan optimize` tapé à la main recrée `bootstrap/cache/config.php`, copie de **tous** les secrets du `.env`, avec l'umask de la session (`022` d'ordinaire sous Plesk, soit `644` : lisible par tout compte admis à traverser le HOME, serveur web compris).
- Gabarits : `ops/redis/`, `ops/systemd/`, `ops/nginx/` (recopiés par root) ; `ops/plesk/settings.md` (réglages de l'interface). Chaque en-tête de gabarit porte sa commande d'installation.

## 0. Préalables bloquants

- [ ] Domaine acheté (`docs/REPRISE.md`, étape 1 ; décision 5). Aucun compte de production ni aucune passkey avant.
- [ ] Relevé fait (section 1) : accès root confirmé, région UE ; les points propres à l'abonnement se relèvent à l'étape 1. **Sans root : arrêt et question au porteur** (repli S2, second VPS).
- [ ] Sur `main` : L100-4, L100-6, L100-7, L60-1, L30-7, L20-1, L20-2 et L40-7 livrés, ainsi que la préparation de L100-9 (`ops/`), cueillie depuis `develop` sans y porter la phase C (règle de branche, `docs/REPRISE.md` § A.2) ; `IndexingTest` (L90-2) vert (`noindex` intégral, § 12).
- [ ] Les deux workflows verts sur le commit source (§ 2.6) ; branche `deploy` écrite par le job `artifacts`.
- [ ] Logo TMDB déposé (`docs/REPRISE.md` § 3, geste 10), au plus tard ici.
- [ ] Deux exemplaires hors machine prêts (inventaire scellé, gestionnaire de secrets) : ils recevront `APP_KEY` (étape 2) et les codes de secours du second facteur (étape 6).

## 1. Relevé du VPS (§ 10.1)

Résultats à consigner, date comprise, dans `100` § 10.1 (« Résultats du relevé ») : jamais une IP, un nom de domaine ni un secret.

| Point                    | Commande (root, sauf mention)                                                                                                       | Attendu                                                                                                  |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Accès root               | `ssh root@<IP du VPS>`                                                                                                              | oui, une fois                                                                                            |
| Système                  | `cat /etc/os-release` ; `systemctl --version`                                                                                       | noté                                                                                                     |
| cgroups                  | `stat -fc %T /sys/fs/cgroup`                                                                                                        | `cgroup2fs` (`MemoryMax`, `CPUWeight`)                                                                   |
| RAM, vCPU, charge        | `free -m` ; `nproc` ; `uptime`                                                                                                      | ≥ 4 Go, ≥ 2 vCPU                                                                                         |
| Ports en écoute          | `ss -ltnp`                                                                                                                          | deux ports de bouclage libres ; 6379 noté                                                                |
| PHP de l'abonnement      | `/opt/plesk/php/8.4/bin/php -m`                                                                                                     | `imagick`, `pdo_mysql`, `mbstring`, `openssl`, `sodium` ; `pcntl` noté                                   |
| `memory_limit` du CLI    | `/opt/plesk/php/8.4/bin/php -r 'echo ini_get("memory_limit"), PHP_EOL;'`                                                            | noté (`PHP_ARGS` des workers)                                                                            |
| Redis                    | `command -v redis-server` ; `redis-server --version`                                                                                | présent, ou paquet à poser (étape 4 a)                                                                   |
| Redis et systemd         | `ldd "$(command -v redis-server)" \| grep libsystemd`                                                                               | une ligne (`Type=notify`)                                                                                |
| Plesk                    | `plesk version` ; interface                                                                                                         | nginx seul vers PHP-FPM, cache nginx, Git, actions additionnelles (répertoire, délai), tâches planifiées |
| Utilisateur d'abonnement | `id <utilisateur>` ; `getent passwd <utilisateur>`                                                                                  | shell non chrooté, `HOME` défini                                                                         |
| Outils (abonnement)      | `command -v mysqldump` ; chemin de `composer.phar`                                                                                  | accessibles                                                                                              |
| Répertoire privé         | `ls -ld /var/www/vhosts/<DOMAINE>/private`                                                                                          | existe, hors `httpdocs`                                                                                  |
| HOME de l'abonnement     | `stat -c '%A %U:%G' /var/www/vhosts/<DOMAINE>`                                                                                      | `drwx--x---`, utilisateur d'abonnement, groupe du serveur web (`psaserv`) ; jamais `0711`                |
| Région                   | contrat de l'hébergeur                                                                                                              | **UE** (décision 17)                                                                                     |
| Heure                    | `timedatectl` ; en SQL, `SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone` ; `date.timezone` du PHP (CLI et pool) | UTC partout, NTP actif                                                                                   |
| MySQL                    | en SQL, `SELECT VERSION(), @@explicit_defaults_for_timestamp, @@gtid_mode` ; accès local (socket ou `127.0.0.1`)                    | 8.0.x, `1`, `OFF` ; `DB_HOST` noté                                                                       |
| Pare-feu                 | Plesk › Pare-feu                                                                                                                    | politique entrante notée                                                                                 |

La lisibilité de `/proc/meminfo` et de `/proc/cpuinfo` sous l'`open_basedir` de l'abonnement se vérifie à l'étape 5, par la sonde `load`.

`@@gtid_mode` à `ON` : le vidage de `backup:snapshot` porterait `SET @@GLOBAL.GTID_PURGED`, que l'utilisateur d'une base Plesk ne peut pas rejouer, et toute restauration échouerait à l'import ; `--set-gtid-purged=OFF` est alors à ajouter à `backup:snapshot` avant la première sauvegarde (répétition du 28/09, `docs/ops/repetition-vm.md`, écart n° 16).

**Relevé « avant » des voisins**, root, avant tout geste de l'étape 4 : PID des services du relevé (`systemctl show -p MainPID --value <unité>`), code HTTP de chaque site hébergé (`plesk bin site --list`), code et sujet du certificat que sert l'adresse du VPS sans SNI. Refait après l'étape 5 : mêmes PID, mêmes codes, même serveur par défaut ; seuls ports nouveaux, ceux de Redis et de Reverb, en `127.0.0.1`. Blocs : `docs/ops/repetition-vm.md`, étapes 1.0 et 1.13.

**Paramètres dérivés du relevé.** Une fois le relevé fait, les paramètres non secrets (ports, utilisateur, groupe, chemin écrit avec `<DOMAINE>`) et les valeurs « À AJUSTER AU RELEVÉ » sont reportés **dans le dépôt**, par un commit « gabarits ajustés au relevé » ; sur le serveur ne restent à remplacer que `<DOMAINE>` et le mot de passe Redis.

Ce commit est porté sur `main`, la branche `deploy` est reconstruite par le job `artifacts` et tirée dans Plesk avant le premier « Déployer » de l'étape 1 : root recopie les gabarits depuis ce checkout (étape 4), jamais depuis une copie du poste. L'utilisateur d'abonnement, son répertoire privé et le chemin de déploiement ne se relèvent qu'une fois l'abonnement et Git créés : le commit se place donc à l'étape 1, entre ces réglages et le premier « Déployer ».

| Paramètre                   | Source                                        | Où                                                    |
| --------------------------- | --------------------------------------------- | ----------------------------------------------------- |
| `__TF_SUBSCRIPTION_USER__`  | utilisateur système de l'abonnement           | unités workers et Reverb                              |
| `__TF_SUBSCRIPTION_GROUP__` | `id -gn <utilisateur>`, d'ordinaire `psacln`  | unités workers et Reverb                              |
| `__TF_DEPLOY_PATH__`        | chemin absolu du déploiement Plesk Git        | unités, tâche planifiée                               |
| `__TF_REDIS_PORT__`         | `ss -ltnp` : libre, jamais 6379               | `tripleframes-redis.conf`, `REDIS_PORT`               |
| `__TF_REVERB_PORT__`        | `ss -ltnp` : libre, distinct du port de Redis | directives nginx, `REVERB_SERVER_PORT`, `REVERB_PORT` |
| `__TF_REDIS_PASSWORD__`     | `openssl rand -hex 32`, sur le serveur        | `tripleframes-redis.conf`, `REDIS_PASSWORD`           |

**Budget mémoire** (valeurs de départ, recalibrées par la séance de charge, D33 du 23/09) : `MemoryMax` de Redis 320 Mo, du worker `game` 192 Mo, du worker `default` 512 Mo, de Reverb 256 Mo, soit 1 280 Mo plafonnés ; s'y ajoutent le pool PHP-FPM (`pm.max_children` = 12) et le processus permanent du planificateur. Sous 4 Go de RAM, ces plafonds sont revus à la baisse **avant** la séance de charge, en gardant pour chaque worker `--memory` < `memory_limit` < `MemoryMax`.

## 2. Étape 1 — Plesk : abonnement, base, Git, premier « Déployer »

- [ ] Abonnement `<DOMAINE>` et hébergement réglés selon `ops/plesk/settings.md` § 1 et § 2 (sauf les directives nginx, posées à l'étape 4).
- [ ] Base MySQL dédiée et utilisateur limité à cette base.
- [ ] Git selon `ops/plesk/settings.md` § 6 : branche `deploy`, clé en lecture seule, mode manuel, **sans** actions additionnelles.
- [ ] Points du relevé propres à l'abonnement faits (utilisateur, répertoire privé, chemin de déploiement), puis commit « gabarits ajustés au relevé » (section 1) sur `main`, les deux workflows verts, `deploy` reconstruite par le job `artifacts` et tirée dans Plesk.
- [ ] Premier « Déployer » : les fichiers arrivent, sans `vendor/`, sans `.env`, sans drapeau.
- [ ] Fichiers servis lisibles du serveur web, en SSH de l'abonnement dans `__TF_DEPLOY_PATH__` : `find public \( -type f ! -perm -o=r \) -o \( -type d ! -perm -o=x \) | wc -l` rend `0`. Un fichier de `public/` en `640` est refusé en 403 par nginx (répétition du 28/09 : assets neufs déposés sous un umask `027`). Jamais corrigé en passant une session en `umask 022`, qui rendrait de nouveau lisibles les caches de démarrage : `chmod o+r` sur les fichiers et `o+rx` sur les répertoires de `public/` seuls, puis relever comment Plesk Git fixe les droits des fichiers déposés.

## 3. Étape 2 — `.env` de production, racines, `APP_KEY`

Abonnement :

```bash
PHP=/opt/plesk/php/8.4/bin/php
cd __TF_DEPLOY_PATH__
(umask 077 && mkdir -p /var/www/vhosts/<DOMAINE>/private/tripleframes/frames \
    /var/www/vhosts/<DOMAINE>/private/tripleframes/snapshots ~/.config/tripleframes)
(umask 077 && cp .env.example .env)
"$PHP" -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
```

- [ ] Racines hors déploiement créées en `0700` à l'utilisateur d'abonnement (`composer setup` ne tourne pas en production, et `AppServiceProvider` vérifie la variable sans créer le répertoire), ainsi que `~/.config/tripleframes`, qui reçoit `hook.env` (étape 7) et la configuration de sauvegarde (L100-10).
- [ ] `.env` en `0600`, hors dépôt, rempli selon le tableau ci-dessous (§ 10.10 fait foi). `.env.example` porte `DB_CONNECTION=sqlite`, les lignes `# DB_…` commentées et aucune ligne `SESSION_SECURE_COOKIE` : décommenter et remplir les premières, ajouter la dernière.
- [ ] `APP_KEY` = la ligne `base64:…` ci-dessus : même format que `key:generate`, qui exige `vendor/`, absent avant l'étape 3.
- [ ] **Copie immédiate d'`APP_KEY` hors machine**, dans les deux exemplaires (§ 13.4) : sans elle, une base restaurée rendrait illisible le second facteur de l'administrateur.

| Variable                                                           | Production                                                                            |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------- |
| `APP_ENV`, `APP_DEBUG`                                             | `production`, `false`                                                                 |
| `APP_URL`                                                          | `https://<DOMAINE>`                                                                   |
| `LOG_STACK`, `LOG_DAILY_DAYS`                                      | `daily`, `14`                                                                         |
| `SITE_INDEXABLE`                                                   | `false` (J1)                                                                          |
| `ACCOUNTS_REGISTRATION_OPEN`, `ACCOUNTS_PASSKEYS_ENABLED`          | absentes ou `false` (J1)                                                              |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`                              | `mysql`, hôte local (À AJUSTER AU RELEVÉ), `3306`                                     |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`                        | base et utilisateur de l'étape 1                                                      |
| `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`                          | `database`, `true`                                                                    |
| `FRAMES_DISK_ROOT`, `BACKUP_SNAPSHOT_DIR`                          | les deux chemins absolus créés ci-dessus                                              |
| `CACHE_STORE`, `QUEUE_CONNECTION`, `REDIS_CLIENT`                  | `redis`, `redis`, `predis`                                                            |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`                       | `127.0.0.1`, `__TF_REDIS_PORT__`, `__TF_REDIS_PASSWORD__`                             |
| `REDIS_QUEUE_RETRY_AFTER`                                          | `960`                                                                                 |
| `BROADCAST_CONNECTION`                                             | `reverb`                                                                              |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`             | générés ici (`openssl rand -hex 16`, `-hex 16`, `-hex 32`)                            |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`                      | `127.0.0.1`, `__TF_REVERB_PORT__`, `http`                                             |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`                         | `127.0.0.1`, `__TF_REVERB_PORT__`                                                     |
| `REVERB_CLIENT_HOST`, `REVERB_CLIENT_PORT`, `REVERB_CLIENT_SCHEME` | **vides tous les trois** (nginx publie `/app/` sur 443)                               |
| `REVERB_ALLOWED_ORIGINS`                                           | `<DOMAINE>`                                                                           |
| `REVERB_MAX_REQUEST_SIZE`                                          | `524288`                                                                              |
| `DEPLOY_DRAIN_TIMEOUT_MINUTES`, `DEPLOY_WINDOW_MINUTES`            | vide, `30`                                                                            |
| `BACKUP_SNAPSHOT_KEEP_DAYS`                                        | `7`                                                                                   |
| `OPS_PROBE_TOKEN`                                                  | secret long (`openssl rand -hex 32`)                                                  |
| `OPS_LOAD_CPU_COUNT`                                               | vide ; le `nproc` du relevé si `/proc/cpuinfo` est illisible (étape 5)                |
| `TMDB_API_READ_ACCESS_TOKEN`                                       | jeton de l'import et de la curation (règle 6)                                         |
| `CURATION_CAPTURE_ENABLED`                                         | vide : voie capture fermée au J1 (`20` § 5.4)                                         |
| `LEGAL_CONTACT_EMAIL`                                              | adresse de contact sous `<DOMAINE>`, dès qu'elle répond (`90` § 4.3) ; vide jusque-là |

- Secrets propres à TripleFrames, jamais partagés avec un autre abonnement (§ 10.2) ; générés dans le terminal et collés dans l'éditeur, jamais passés en argument d'une commande.
- `APP_ENV=production` n'est pas un détail : un `APP_ENV=local` recopié de `.env.example` rouvrirait inscription et passkeys (liste blanche de `40` § 8.2).
- Hors du tableau de § 10.10, laissés à la décision du porteur : `MAIL_*`, `LOG_LEVEL`, `APP_LOCALE`.

## 4. Étape 3 — dépendances, schéma, données de plateforme

Abonnement, dans `__TF_DEPLOY_PATH__` (chemin de `composer.phar` relevé à la section 1). La session s'ouvre par `umask 027`, comme le hook : quel que soit l'umask de l'utilisateur d'abonnement, rien de ce qu'elle crée ne naît lisible par un autre compte.

```bash
umask 027
PHP=/opt/plesk/php/8.4/bin/php
cd __TF_DEPLOY_PATH__
"$PHP" <chemin de composer.phar> install --no-dev --optimize-autoloader --no-interaction
grep -E '^(APP_ENV|DB_CONNECTION|DB_HOST|DB_DATABASE|DB_USERNAME)=' .env
"$PHP" artisan about --only=environment
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --class=PlatformDataSeeder --force
```

- [ ] Avant `migrate`, la base et l'environnement relus : `APP_ENV=production`, `DB_CONNECTION=mysql`, base et utilisateur de l'étape 1 ; `about` : `production`, débogage désactivé.
- [ ] Les trois commandes `composer`, `migrate` et `db:seed` sortent en 0. Base vide : aucun instantané n'est requis (la règle 12 protège une curation qui n'existe pas encore) ; le hook le prendra à chaque migration suivante.
- [ ] Si une commande échoue sur une connexion Redis refusée, monter Redis d'abord (étape 4, bloc d'ouverture puis a), puis reprendre : une fois le bloc d'ouverture joué, l'ordre des gestes root a) à f) de l'étape 4 est libre.

## 5. Étape 4 — root : Redis, unités, nginx, pare-feu, planificateur, réglages Plesk

**Ouverture**, root, avant tout geste de a) à f) : les commandes d'installation en tête des gabarits emploient des chemins relatifs au checkout de déploiement, et `/etc/tripleframes` reçoit la configuration Redis comme les arguments des workers.

```bash
cd __TF_DEPLOY_PATH__
install -d -o root -g root -m 0755 /etc/tripleframes
```

**a) Redis dédié** (`ops/redis/tripleframes-redis.conf`, `ops/systemd/tripleframes-redis.service`) :

- [ ] Binaire présent. S'il faut poser le paquet et que son installation active une instance par défaut sur 6379 **qu'aucun voisin n'utilisait au relevé**, la désactiver (`systemctl disable --now redis-server`) ; ne jamais toucher un Redis voisin.
- [ ] Utilisateur système : `useradd --system --user-group --no-create-home --home-dir /var/lib/tripleframes-redis --shell /usr/sbin/nologin tfredis`.
- [ ] Configuration recopiée (commande en tête du gabarit), paramètres remplacés, `root:tfredis 0640`.
- [ ] Unité recopiée, `systemctl daemon-reload && systemctl enable --now tripleframes-redis`.
- [ ] Vérifications, mot de passe lu sans écho et hors historique :

```bash
read -rs REDISCLI_AUTH && export REDISCLI_AUTH
redis-cli -p __TF_REDIS_PORT__ ping
redis-cli -p __TF_REDIS_PORT__ flushall
redis-cli -p __TF_REDIS_PORT__ info persistence | grep aof_enabled
redis-cli -p __TF_REDIS_PORT__ info memory | grep -E 'maxmemory:|maxmemory_policy'
ss -ltnp | grep __TF_REDIS_PORT__
unset REDISCLI_AUTH
```

Attendu : `PONG` ; `flushall` refusé (commande inconnue) ; `aof_enabled:1` ; `maxmemory_policy:noeviction` ; écoute sur `127.0.0.1` seulement. Les avertissements de démarrage sur les réglages du noyau (`vm.overcommit_memory`, pages énormes) concernent toute la machine : aucun réglage du noyau sans décision du porteur, les voisins en dépendent.

**b) Workers, Reverb et relance manuelle du déploiement** (`ops/systemd/`) :

- [ ] `tripleframes-worker@.service`, `worker-game.env`, `worker-default.env` et les deux drop-ins `limits.conf` recopiés (boucle en tête du gabarit), paramètres remplacés.
- [ ] `tripleframes-reverb.service` recopié, paramètres remplacés.
- [ ] `tripleframes-deploy.service` recopié, paramètres remplacés, mais **jamais activé** : `systemctl start tripleframes-deploy` relance à la demande le hook sous l'utilisateur d'abonnement après que Plesk a déposé la branche `deploy`. Il ne tire pas Git, ne construit pas les assets et ne remplace pas l'action additionnelle Plesk.
- [ ] `systemctl daemon-reload` puis `systemctl enable` des trois unités, **sans les démarrer** (étape 5).

**c) nginx** :

- [ ] `ops/nginx/additional-directives.conf`, paramètres remplacés, collé dans les directives nginx supplémentaires (`ops/plesk/settings.md` § 2) ; enregistrement accepté par Plesk.

**d) Pare-feu** :

- [ ] Aucune règle n'ouvre `__TF_REDIS_PORT__` ni `__TF_REVERB_PORT__` (`ops/plesk/settings.md` § 7).

**e) Planificateur** :

- [ ] Tâche planifiée `schedule:run` chaque minute (`ops/plesk/settings.md` § 4), sortie non notifiée.

**f) Réglages Plesk** :

- [ ] PHP-FPM, HSTS, cache nginx désactivé, rotation des journaux : appliqués et datés dans le journal de `ops/plesk/settings.md`.

## 6. Étape 5 — démarrage et vérifications

Abonnement (session ouverte par `umask 027`, comme le hook : `optimize` recopie **tous** les secrets du `.env` dans `bootstrap/cache/config.php`), puis root :

```bash
umask 027
PHP=/opt/plesk/php/8.4/bin/php
cd __TF_DEPLOY_PATH__
"$PHP" artisan optimize
"$PHP" artisan lang:hash
stat -c '%a %n' bootstrap/cache/config.php
```

```bash
systemctl start tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
systemctl --no-pager status tripleframes-redis tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
systemctl show tripleframes-worker@game tripleframes-worker@default tripleframes-redis tripleframes-reverb -p Id -p MemoryMax -p CPUWeight
ss -ltnp | grep -E ':(__TF_REDIS_PORT__|__TF_REVERB_PORT__)\b'
```

- [ ] `bootstrap/cache/config.php` en `640` (jamais `644` ni `664` : ce serait une copie lisible de tous les secrets du `.env`, qui est, lui, en `600`). Sinon : `chmod 0640 bootstrap/cache/*.php`, puis rouvrir la session par `umask 027`.
- [ ] Quatre unités actives ; `MemoryMax` et `CPUWeight` égaux aux gabarits (et non `infinity`) ; Redis et Reverb en écoute sur `127.0.0.1` seulement.
- [ ] Depuis le poste, les deux ports sont injoignables sur l'adresse publique du VPS (`Test-NetConnection <IP du VPS> -Port <port>` sous Windows : `TcpTestSucceeded : False`).
- [ ] `"$PHP" artisan about --only=environment` : `production`, débogage désactivé.
- [ ] `curl -s -o /dev/null -w '%{http_code}\n' https://<DOMAINE>/up` : `200`.
- [ ] `curl -sI https://<DOMAINE>/` porte `x-robots-tag: noindex, nofollow` et l'en-tête HSTS ; `https://<DOMAINE>/robots.txt` est le fichier statique (`Disallow: /admin`, `Disallow: /f/`).
- [ ] `https://<DOMAINE>/register` répond 404 : inscription fermée (`AccountSwitches`).
- [ ] `https://<DOMAINE>/login` répond 200, et non 502 : ses en-têtes (`Link` des assets préchargés et deux cookies) dépassent 4 Ko ; un 502 avec « upstream sent too big header » au journal d'erreurs nginx de l'abonnement signale que le tampon `fastcgi_buffer_size` des directives nginx supplémentaires manque.
- [ ] `https://<DOMAINE>/legal/notice` : le bloc de contact des pages légales affiche l'adresse, ou la mention d'indisponibilité si elle ne répond pas encore (`LEGAL_CONTACT_EMAIL` vide ; renseignée plus tard, puis `"$PHP" artisan optimize`).
- [ ] **Langue et cache** (§ 10.8), à rejouer après toute modification des directives nginx :

```bash
curl -s -D - -H 'Accept-Language: fr' https://<DOMAINE>/ | grep -iE '^(age|x-cache|x-proxy-cache):|<html'
curl -s -D - -H 'Accept-Language: en' https://<DOMAINE>/ | grep -iE '^(age|x-cache|x-proxy-cache):|<html'
```

Attendu : `<html lang="fr"` puis `<html lang="en"`, et aucune ligne `Age`, `X-Cache` ni `X-Proxy-Cache`.

- [ ] **Sondes** (§ 15), jeton lu sans écho et passé à `curl` par un fichier, jamais en argument : sur un VPS mutualisé, la liste des processus (`/proc/<pid>/cmdline`) est lisible des voisins.

```bash
read -rs TOKEN
for probe in worker-game worker-default load integrity purge; do
    printf '%s ' "$probe"
    curl -s -H @<(printf 'X-Probe-Token: %s\n' "$TOKEN") "https://<DOMAINE>/ops/probe/$probe"
    echo
done
unset TOKEN
```

Attendu : `{"status":"ok"}` partout. Sur une base neuve, `purge` reste en alerte tant qu'aucune exécution n'est terminée : jouer une fois `"$PHP" artisan purge:run --sync` (rien d'éligible), puis attendre un passage du balayage `room:archive-idle` (au plus une cadence, environ 10 minutes). Le motif de toute alerte est au journal de l'application (`storage/logs`), jamais dans la réponse.

- [ ] Sonde `load` : en alerte avec un motif de vCPU illisible → `/proc/cpuinfo` hors de l'`open_basedir` : `OPS_LOAD_CPU_COUNT` = le `nproc` du relevé, puis `"$PHP" artisan optimize`. Mémoire illisible → la sonde ne mesure que la charge : le noter dans `100` § 10.1.
- [ ] **Reverb de bout en bout**, depuis le poste, avec l'origine du site (sinon Reverb refuse la connexion) :

```bash
npx wscat -o https://<DOMAINE> -c "wss://<DOMAINE>/app/<REVERB_APP_KEY>?protocol=7&client=js&version=8.4.0"
```

Attendu : un message `pusher:connection_established`.

- [ ] **Premier vidage réel de `backup:snapshot`** (report de L100-6 : jamais joué sur le poste, faute de `mysqldump`) :

```bash
"$PHP" artisan backup:snapshot
ls -l /var/www/vhosts/<DOMAINE>/private/tripleframes/snapshots
gzip -t /var/www/vhosts/<DOMAINE>/private/tripleframes/snapshots/snapshot-*.sql.gz
zcat /var/www/vhosts/<DOMAINE>/private/tripleframes/snapshots/snapshot-*.sql.gz | tail -n 1
"$PHP" artisan backup:snapshot --if-pending
```

Attendu : code 0 ; fichier `snapshot-AAAAMMJJTHHMMSSZ.sql.gz` en `-rw-------` ; `gzip -t` muet ; dernière ligne `-- Dump completed on …` ; puis `--if-pending` en code 0 sans rien écrire.

## 7. Étape 6 — premier administrateur (`docs/REPRISE.md`, étape 28)

- [ ] Abonnement : `"$PHP" artisan admin:first-admin --create`. La commande demande l'adresse, le nom de compte, le **nom réel** (D12 du 23/09) et le mot de passe (invites masquées ; jamais en argument). Jamais avant l'achat du domaine ; **aucune passkey** au J1.
- [ ] Première connexion : enrôlement du second facteur exigé par la garde `admin.2fa`.
- [ ] Codes de secours Fortify imprimés et rangés en deux exemplaires hors machine, avec `APP_KEY` (§ 11.9, § 13.4).

## 8. Étape 7 — hook, puis premier déploiement par le hook (`docs/REPRISE.md`, étape 29)

- [ ] Abonnement : `~/.config/tripleframes/hook.env`, une ligne `COMPOSER_PHAR=<chemin absolu de composer.phar>`, sans secret, en `0600` :

```bash
(umask 077 && mkdir -p ~/.config/tripleframes && printf 'COMPOSER_PHAR=%s\n' '<chemin absolu de composer.phar>' > ~/.config/tripleframes/hook.env)
stat -c '%a %U %n' ~/.config/tripleframes/hook.env
```

- [ ] Relevé des points que `ops/deploy/hook.sh` porte en tête : répertoire courant et délai des actions additionnelles (le hook dure le temps de `composer install` et des migrations), `HOME` défini pour l'utilisateur d'abonnement dans ce contexte.
- [ ] Plesk Git : actions de déploiement additionnelles = `bash ops/deploy/hook.sh`.
- [ ] Premier déploiement par le hook, procédure du § 11.4 **sans ses étapes 3 et 4** (pas de drainage : les lignes `# drain:` du hook restent inactives, leur activation est l'étape 126) : tirer `deploy`, « Déployer », lire la sortie `[1/12]` à `[11/12]`, étapes 3 et 12 absentes, sans erreur.
- [ ] `/up` et les sondes de nouveau vertes ; `stat -c '%a %n' bootstrap/cache/*.php` : `config.php`, `events.php`, `routes-v7.php` et `lang-version.php` en `640` (`packages.php` et `services.php` en `750`, réécrits par `package:discover`, sans secret) ; `public/` toujours lisible du serveur web (même contrôle `find` qu'à l'étape 1). À refaire après **chaque** « Déployer », surtout quand les assets changent.

## 9. Contrôle final des gabarits recopiés

Root, lignes de valeur seulement (un commentaire des gabarits n'en porte jamais, `OpsTemplatesTest`), `.env` de production compris :

```bash
grep -rnE '^[^#]*(__TF_|<DOMAINE>)' /etc/tripleframes /etc/systemd/system/tripleframes-* __TF_DEPLOY_PATH__/.env
```

- [ ] Aucune ligne : tout paramètre est remplacé sur le serveur. Un `__TF_REDIS_PASSWORD__` resté à la fois dans `requirepass` et dans `REDIS_PASSWORD` fonctionnerait sans erreur, avec un mot de passe publié dans le dépôt : ce contrôle est le seul filet.

Root, écart entre le dépôt et le serveur (la ligne `requirepass` est ignorée, pour que le mot de passe ne s'affiche pas) :

```bash
cd __TF_DEPLOY_PATH__
diff -I '^requirepass ' ops/redis/tripleframes-redis.conf /etc/tripleframes/tripleframes-redis.conf
for f in tripleframes-redis.service tripleframes-reverb.service tripleframes-worker@.service; do
    diff "ops/systemd/${f}" "/etc/systemd/system/${f}"
done
for i in game default; do
    diff "ops/systemd/worker-${i}.env" "/etc/tripleframes/worker-${i}.env"
    diff "ops/systemd/tripleframes-worker@${i}.service.d/limits.conf" \
        "/etc/systemd/system/tripleframes-worker@${i}.service.d/limits.conf"
done
```

- [ ] Seules diffèrent les lignes où `<DOMAINE>` est remplacé.

## 10. Consignation

- [ ] Résultats du relevé dans `100` § 10.1, date comprise.
- [ ] Réglages Plesk appliqués datés dans le journal de `ops/plesk/settings.md`, dans un commit.
- [ ] Vérifié : les fichiers de `/etc/tripleframes` et `/etc/systemd/system/tripleframes-*` ne diffèrent de `ops/` que par `<DOMAINE>` et le mot de passe Redis (`diff`, section 9) ; tout autre écart repasse d'abord par le dépôt.
- [ ] Étapes 27 à 29 marquées dans `docs/REPRISE.md` ; L100-9 terminé.

## 11. Après la mise en service — interdits du J1

- **Aucune image curée en production avant l'étape 30** de `docs/REPRISE.md` : le tier chaud de sauvegarde est actif avant la première image curée (`100` § 11.6, § 13.2).
- **Aucune partie lancée sur le VPS avant l'étape 126** (déploiement du moteur par la transition du drainage) : le hook tourne ici sans drainage, et le drainage précède la première vraie partie, aucun lancement public entre les deux déploiements (`100` § 11, § 11.6 ; I-13). La première vraie partie attend en outre les conditions de l'étape 133 (restauration chronométrée, charge, recette).

## 12. Préproduction `preprod.<DOMAINE>` et promotion (J2, L100-15, spec `100` § 18 et § 19)

Second abonnement sur le **même VPS**, mis en service **une fois**. Les gestes root de cette section ne se répètent jamais : une promotion n'en demande aucun (n° 80, n° 6 de D66 du 07/10). Mêmes conventions que ci-dessus ; « abonnement de préproduction » désigne la session SSH de son utilisateur système, jamais celui de la production.

**Paramètres propres à la préproduction** (relevés comme à la section 1, jamais égaux à ceux de la production) :

| Paramètre                           | Relevé ou génération                                                                                              | Où il va                                                                                |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| `__TF_PREPROD_SUBSCRIPTION_USER__`  | utilisateur système de l'abonnement de préproduction                                                              | unités workers et Reverb de préproduction                                               |
| `__TF_PREPROD_SUBSCRIPTION_GROUP__` | `id -gn <utilisateur>`, d'ordinaire `psacln`                                                                      | unités workers et Reverb de préproduction                                               |
| `__TF_PREPROD_DEPLOY_PATH__`        | chemin absolu du déploiement Plesk Git de la préproduction                                                        | unités de préproduction                                                                 |
| `__TF_PREPROD_REDIS_PORT__`         | `ss -ltnp` : libre, jamais 6379, distinct de `__TF_REDIS_PORT__` et des ports Reverb                              | `tripleframes-preprod-redis.conf`, `REDIS_PORT` de préproduction                        |
| `__TF_PREPROD_REVERB_PORT__`        | `ss -ltnp` : libre, distinct des trois autres ports                                                               | directives nginx de préproduction, `REVERB_SERVER_PORT`, `REVERB_PORT` de préproduction |
| `__TF_PREPROD_REDIS_PASSWORD__`     | `openssl rand -hex 32`, sur le serveur, jamais celui de la production                                             | `tripleframes-preprod-redis.conf`, `REDIS_PASSWORD` de préproduction                    |
| `__TF_PREPROD_HTPASSWD_FILE__`      | chemin absolu d'un fichier `htpasswd -B` **hors** de la racine du document de la préproduction, lisible par nginx | directives nginx de préproduction                                                       |

**a) Plesk** (geste humain) :

- [ ] Abonnement `preprod.<DOMAINE>` : DNS, Let's Encrypt, PHP 8.4 FPM comme la production, racine du document sur `public`, **base MySQL séparée** et son utilisateur propre.
- [ ] Git en mode **manuel**, dépôt de la forge, **branche `deploy-preprod`**, clé de déploiement en lecture seule, **sans** actions additionnelles jusqu'au point e).
- [ ] Premier « Déployer ».

**b) `.env` de préproduction**, abonnement de préproduction, `0600`, à partir de `ops/preprod/env.reference` : `APP_ENV=staging`, `SITE_INDEXABLE` vide, base et Redis de préproduction, racines `FRAMES_DISK_ROOT`, `AVATARS_DISK_ROOT` et `BACKUP_SNAPSHOT_DIR` propres et hors du chemin de déploiement (`0700`), `MAIL_MAILER=log`, aucune clé TMDB ni OAuth, puis `key:generate`. **Jamais** une valeur, une clé, une base ni une image de la production (n° 80) ; rien de la préproduction n'est sauvegardé.

**c) Root, une seule fois** (commandes d'installation en tête de chaque gabarit, depuis `__TF_PREPROD_DEPLOY_PATH__`) :

- [ ] Redis : `ops/redis/tripleframes-preprod-redis.conf` et `ops/systemd/tripleframes-preprod-redis.service` (utilisateur `tfredis` de la production), `enable --now`, vérifications de la section 5 a) sur `__TF_PREPROD_REDIS_PORT__`.
- [ ] Workers : `ops/systemd/tripleframes-preprod-worker@.service`, `preprod-worker-game.env`, `preprod-worker-default.env` et les deux drop-ins `limits.conf` ; Reverb : `ops/systemd/tripleframes-preprod-reverb.service` ; `daemon-reload`, `enable` **sans démarrer** (point d).
- [ ] Authentification HTTP : `htpasswd -B -c __TF_PREPROD_HTPASSWD_FILE__ <identifiant>`, mot de passe fort rangé avec l'inventaire scellé ; propriétaire root, groupe de nginx, `0640`.
- [ ] nginx : `ops/nginx/additional-directives.preprod.conf`, paramètres remplacés, collé dans les directives nginx supplémentaires **de l'abonnement de préproduction**.
- [ ] Pare-feu : aucune règle n'ouvre `__TF_PREPROD_REDIS_PORT__` ni `__TF_PREPROD_REVERB_PORT__`.

**d) Abonnement de préproduction, une seule fois** (`umask 027`) :

```bash
"$PHP" "$COMPOSER_PHAR" install --no-interaction
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --force
"$PHP" artisan optimize
"$PHP" artisan lang:hash
```

`composer install` **avec** les dépendances de développement, cette fois seulement : le catalogue de démonstration est fait de fabriques qui exigent Faker. `db:seed` sans classe joue `DatabaseSeeder`, qui en `staging` pose les deux auteurs inouvrables du catalogue (`PreprodCurationAccountsSeeder`), les données du site et le **catalogue de démonstration**, jamais les comptes de démonstration (spec `100` § 8, règle 2). Le premier déploiement par le hook (point e) réinstalle sans les dépendances de développement. Puis, root : démarrage des trois unités et vérifications de la section 6 (`/up` sous authentification HTTP, `artisan about --only=environment` → `staging`). Administrateur de recette : `"$PHP" artisan admin:first-admin --create`, comme en production, nom réel exigé, **sans `--force`** : l'administrateur inouvrable semé (`App\Support\Preprod\PreprodAuthors`) ne compte jamais comme administrateur en place en `staging`.

- [ ] Sans identifiants, toute URL répond 401 (`curl -sI https://preprod.<DOMAINE>/` puis `/robots.txt`) ; avec eux, l'accueil répond 200 et porte `X-Robots-Tag: noindex`.
- [ ] Un salon se crée, deux navigateurs s'y retrouvent, une partie se lance (Reverb publié sous l'authentification).

**e) Hook** : actions additionnelles de la préproduction = `bash ops/deploy/hook.sh`, le **même** hook que la production (`~/.config/tripleframes/hook.env` de l'abonnement de préproduction, `COMPOSER_PHAR` seulement). Le drainage s'y joue comme en production (§ 11.4) dès qu'une partie de recette peut y être en cours.

**f) Chaîne de promotion** (spec `100` § 18 et § 19), à chaque mise en production :

1. Un commit vert sur `main` : le job `artifacts` publie `deploy-preprod`.
2. Plesk de la préproduction : tirer `deploy-preprod`, « Déployer » ; relever le **commit déployé** (40 caractères, affiché par Plesk Git).
3. Poste : les trois parcours Playwright contre la préproduction (`tests/Browser/README.md`), tous verts.
4. GitHub › Actions › `promote` › « Run workflow » sur `main` : le commit relevé au point 2, case « parcours joués » cochée. Le workflow avance `deploy` en avance rapide sur **ce** commit, ou refuse.
5. Production : § 11.4 (tirer `deploy`, drainage, « Déployer »), inchangé.

- [ ] Première promotion jouée de bout en bout, date consignée dans `docs/REPRISE.md`.
