# Installer TripleFrames sur le vrai VPS avec Plesk

> Guide opératoire simplifié, dérivé de `ops/mise-en-service.md`,
> `ops/plesk/settings.md`, des gabarits `ops/` et de la répétition complète
> `docs/ops/repetition-vm.md`.
>
> Ce document explique **quoi faire, où le faire, avec quel utilisateur et
> pourquoi**. Il est adapté à la configuration réelle de `tripleframes.fun` :
> application dans `httpdocs`, PHP 8.5 et Redis provisoirement distant. Les
> documents d'origine décrivent encore PHP 8.4 et un Redis local ; sur ces
> trois points précis, les commandes de ce guide remplacent donc leurs valeurs.

## 1. Ce que l'on installe réellement

TripleFrames n'est pas un simple site PHP. La production comprend :

- nginx et PHP-FPM 8.5, gérés par Plesk ;
- MySQL, avec une base et un utilisateur dédiés ;
- le code Laravel, livré par la branche Git `deploy` ;
- une instance Redis située temporairement sur un autre VPS, jointe par un
  réseau privé ou un tunnel chiffré ;
- deux workers Laravel : `game` et `default` ;
- Laravel Reverb pour les WebSockets ;
- le planificateur Laravel exécuté chaque minute ;
- des répertoires privés pour les images et les instantanés SQL ;
- un hook Plesk qui installe les dépendances, migre la base, reconstruit les
  caches et redémarre logiquement les workers et Reverb.

Plesk et le VPS ne sont donc pas deux installations différentes : **Plesk est
le panneau qui configure une partie du vrai VPS**. Certaines opérations se font
dans son interface et d'autres en SSH.

## 2. Les quatre contextes à ne jamais mélanger

| Marque dans ce guide | Endroit | Utilisation |
| --- | --- | --- |
| **[POSTE]** | ordinateur local et forge Git | pousser le code, suivre la CI, tester HTTPS et WSS |
| **[PLESK]** | interface web Plesk | domaine, PHP-FPM, base, Git, nginx, certificat et tâche planifiée |
| **[ABO]** | SSH avec l'utilisateur système de l'abonnement | `.env`, Composer, Artisan et déploiement applicatif |
| **[ROOT]** | SSH `root` sur le VPS applicatif | systemd, contrôle réseau et services |

Une commande **[ABO]** ne doit pas être lancée en `root`. Cela créerait des
fichiers appartenant à `root` que PHP-FPM et le hook ne pourraient plus gérer.

## 3. Règles absolues

1. Les valeurs réelles connues sont déjà inscrites dans ce guide. Les seuls
   champs encore à renseigner concernent les secrets et la connexion au VPS
   Redis distant.
2. Ne jamais copier une adresse IP, un mot de passe, `APP_KEY` ou un autre
   secret dans Git, dans ce document ou dans une commande conservée dans
   l'historique du shell.
3. Toute session **[ABO]** commence par `umask 027`.
4. Toujours appeler PHP par `/opt/plesk/php/8.5/bin/php`, jamais par `php`.
5. Reverb doit écouter uniquement sur `127.0.0.1:8081`. Le Redis distant ne
   doit jamais être joint en clair par son adresse publique : utiliser le
   réseau privé de l'hébergeur ou un tunnel WireGuard équivalent.
6. Ne jamais exécuter `composer setup` en production.
7. Ne jamais exécuter `npm install` ou `npm run build` sur le VPS. La CI
   construit déjà `public/build` et le publie dans la branche `deploy`.
8. Ne jamais exécuter `php artisan cache:clear` en production. Redis interdit
   volontairement `FLUSHDB`, car cette commande effacerait notamment le
   drapeau de drainage et les battements de supervision.
9. Ne jamais utiliser `DemoCatalogueSeeder` ou `DemoAccountsSeeder` en
   production. Seul `PlatformDataSeeder` est prévu ici.
10. Conserver `APP_KEY` et les codes de secours 2FA dans deux emplacements hors
    du VPS.

## 4. Fiche de valeurs à remplir avant de commencer

Ne mettre aucun secret dans cette fiche si elle est enregistrée dans Git.

| Valeur | Exemple de forme | Valeur réelle |
| --- | --- | --- |
| Domaine et hôte SSH | — | `tripleframes.fun` |
| Utilisateur système Plesk | nom créé par Plesk | `tripleframes` |
| Groupe de l'utilisateur | — | `psacln` |
| Racine de l'abonnement | — | `/var/www/vhosts/tripleframes.fun` |
| Chemin de déploiement | — | `/var/www/vhosts/tripleframes.fun/httpdocs` |
| Racine publique nginx | — | `/var/www/vhosts/tripleframes.fun/httpdocs/public` |
| PHP Plesk | — | `/opt/plesk/php/8.5/bin/php` |
| Composer Plesk | — | `/usr/lib/plesk-9.0/composer.phar` |
| Hôte Redis privé | VPS Redis distant | à renseigner dans `.env` |
| Port Redis privé | configuration du VPS Redis | à renseigner dans `.env` |
| Port Reverb local | à confirmer libre | `8081` |
| Base MySQL | — | `tripleframes` |
| Utilisateur MySQL | — | `tripleframes` |

## 5. Vue d'ensemble de l'ordre à suivre

1. Vérifier le VPS et confirmer que le port local `8081` est libre.
2. Créer/configurer le domaine, PHP et MySQL dans Plesk.
3. Connecter Plesk à la branche Git `deploy`, puis effectuer un premier
   déploiement **sans hook**.
4. Créer les répertoires privés et le `.env` avec l'utilisateur d'abonnement.
5. Valider la connexion privée au Redis situé sur l'autre VPS.
6. Installer les dépendances PHP, migrer et initialiser la plateforme.
7. Installer les unités systemd des workers et de Reverb.
8. Ajouter les directives nginx et la tâche planifiée dans Plesk.
9. Construire les caches, démarrer les services et tout vérifier.
10. Créer le premier administrateur.
11. Activer le hook Plesk et faire un second déploiement de validation.

## 6. Phase A — vérifier le vrai VPS

### 6.1 Connexion et relevé général

**[POSTE]**

```bash
ssh root@tripleframes.fun
```

**[ROOT]**

```bash
cat /etc/os-release
systemctl --version
plesk version
timedatectl
free -m
nproc
uptime
stat -fc %T /sys/fs/cgroup
ss -ltnp
```

Résultat attendu :

- Linux avec systemd ;
- `cgroup2fs` pour les limites mémoire et CPU ;
- heure système en UTC et NTP actif ;
- idéalement au moins 4 Go de RAM et 2 vCPU ;
- aucun service local sur le port `8081` réservé à Reverb.

### 6.2 Vérifier PHP 8.5

**[ROOT]**

```bash
/opt/plesk/php/8.5/bin/php -v
/opt/plesk/php/8.5/bin/php -m | grep -E 'imagick|pdo_mysql|mbstring|openssl|sodium|pcntl'
/opt/plesk/php/8.5/bin/php -r 'echo ini_get("memory_limit"), PHP_EOL;'
```

Les extensions indispensables sont `imagick`, `pdo_mysql`, `mbstring`,
`openssl` et `sodium`. `pcntl` est souhaitable pour borner correctement la
durée des jobs.

Si PHP 8.5 ou une extension manque, l'installer avec **Plesk Installer** plutôt
qu'avec le PHP système, puis rejouer ces commandes.

### 6.3 Vérifier Composer

**[ROOT]**

```bash
ls -l /usr/lib/plesk-9.0/composer.phar /usr/lib64/plesk-9.0/composer.phar 2>/dev/null
```

Sur Debian/Ubuntu, le chemin attendu est généralement
`/usr/lib/plesk-9.0/composer.phar`. Sur AlmaLinux/RHEL, il est généralement
`/usr/lib64/plesk-9.0/composer.phar`.

Vérifier avec le PHP Plesk :

```bash
/opt/plesk/php/8.5/bin/php /usr/lib/plesk-9.0/composer.phar --version
```

### 6.4 Préparer le client Redis

**[ROOT]**

Redis n'est pas installé comme serveur sur ce VPS applicatif. Seul
`redis-cli` est utile pour valider la connexion au VPS Redis distant :

```bash
command -v redis-cli || true
```

S'il manque sur Debian/Ubuntu :

```bash
apt-get update
apt-get install -y redis-tools
```

Ne pas installer ni activer `redis-server` localement dans cette topologie.

### 6.5 Vérifier MySQL

**[ROOT]**

```bash
plesk db "SELECT VERSION(), @@global.time_zone, @@session.time_zone, @@system_time_zone, @@explicit_defaults_for_timestamp, @@gtid_mode;"
```

Objectif : MySQL 8, horodatage cohérent en UTC, `explicit_defaults_for_timestamp`
à `1`. Noter la valeur de `@@gtid_mode`. Si elle vaut `ON`, la commande
`backup:snapshot` devra être corrigée pour utiliser
`--set-gtid-purged=OFF` avant de considérer les sauvegardes restaurables.

### 6.6 Confirmer que le port Reverb 8081 est libre

**[ROOT]**

```bash
ss -ltnp | grep ':8081\b' || echo 'Le port 8081 est libre'
```

Ne continuer que si aucune ligne d'écoute n'apparaît. Reverb écoutera ensuite
sur `127.0.0.1:8081`.

## 7. Phase B — préparer l'abonnement dans Plesk

### 7.1 Domaine et DNS

**[PLESK]**

1. Créer un abonnement dédié au domaine.
2. Utiliser un utilisateur système dédié, jamais partagé avec un autre site.
3. Régler l'accès SSH sur `/bin/bash`, **non chrooté**. Le hook, Composer et la
   tâche planifiée doivent pouvoir appeler `/opt/plesk/php/8.5/bin/php`.
4. Faire pointer les enregistrements DNS `A`/`AAAA` vers le VPS.
5. Attendre que le domaine résolve avant de demander le certificat.

**[POSTE]**

```bash
nslookup tripleframes.fun
```

Après la création de l'abonnement, relever les valeurs exactes.

**[ROOT]**

```bash
id tripleframes
getent passwd tripleframes
stat -c '%A %U:%G %n' /var/www/vhosts/tripleframes.fun
ls -ld /var/www/vhosts/tripleframes.fun/private
```

Reporter le groupe principal, le répertoire personnel et la présence du
répertoire `private` dans la fiche. Le HOME ne doit pas être ouvert en `0711` à
tous les utilisateurs ; sur Plesk il est normalement traversable seulement par
le groupe du serveur web.

Tester ensuite la connexion dédiée depuis le poste :

```bash
ssh tripleframes@tripleframes.fun
id
pwd
```

### 7.2 Hébergement

**[PLESK]** → Sites Web & Domaines → Hébergement :

- racine du document : `httpdocs/public` ;
- chemin de l'application/déploiement : `httpdocs` ;
- redirection HTTP vers HTTPS : permanente ;
- certificat : Let's Encrypt avec renouvellement automatique ;
- HSTS : durée courte au départ, sans `includeSubDomains`, sans `preload`.

La racine publique doit absolument finir par `/public`. Le `.env`, `vendor`,
`storage` et le code applicatif ne doivent jamais être directement servis par
nginx.

Le code complet se trouve donc dans
`/var/www/vhosts/tripleframes.fun/httpdocs`, mais nginx ne publie que
`/var/www/vhosts/tripleframes.fun/httpdocs/public`. L'utilisateur
`tripleframes` ayant un shell non chrooté peut naviguer dans les autres dossiers
de `/var/www/vhosts/tripleframes.fun/`, notamment `private/`.

### 7.3 PHP-FPM

**[PLESK]** → Paramètres PHP :

| Paramètre | Valeur initiale |
| --- | --- |
| version | PHP 8.5 |
| gestionnaire | application FPM servie par nginx |
| `pm` | `ondemand` |
| `pm.max_children` | `12` |
| `pm.max_requests` | `500` |
| `pm.process_idle_timeout` | `10s` |
| `pm.status_path` | `/fpm-status` |
| `opcache.validate_timestamps` | `On` |
| `date.timezone` | `UTC` |
| `upload_max_filesize` | `2M` |
| `post_max_size` | `8M` |

Conserver l'`open_basedir` de Plesk et y ajouter, si l'interface le permet :

```text
/proc/meminfo:/proc/cpuinfo
```

Ces deux fichiers permettent à la sonde de charge de mesurer la mémoire et le
nombre de CPU. Si Plesk refuse, ce n'est pas bloquant : on renseignera
`OPS_LOAD_CPU_COUNT` dans le `.env` avec le résultat de `nproc`.

### 7.4 nginx et Apache

**[PLESK]** → Paramètres d'Apache et de nginx :

- mode proxy vers Apache : désactivé ;
- fichiers statiques servis directement par nginx : activé ;
- cache nginx : désactivé ;
- directives Apache supplémentaires : vides ;
- directives nginx supplémentaires : elles seront ajoutées en phase G.

Le cache de page complète doit rester désactivé : la même URL peut rendre la
page en français ou en anglais selon le visiteur.

### 7.5 Base MySQL

**[PLESK]** → Bases de données :

1. Créer une base dédiée.
2. Créer un utilisateur MySQL dédié.
3. Lui accorder des droits uniquement sur cette base.
4. Utiliser un mot de passe long et unique.
5. Noter le nom de base, l'utilisateur, le mot de passe et l'hôte local dans le
   gestionnaire de secrets.

### 7.6 Journaux

**[PLESK]** → Journaux → Rotation :

- rotation quotidienne ;
- 30 fichiers au maximum ;
- compression activée.

Les journaux nginx contiennent les adresses IP et doivent être bornés. Les
journaux Laravel sont séparés dans `storage/logs` et le `.env` les conserve
14 jours avec `LOG_STACK=daily` et `LOG_DAILY_DAYS=14`.

## 8. Phase C — connecter Git et faire le premier déploiement

### 8.1 Vérifier la branche publiée

Le workflow `.github/workflows/tests.yml` construit les assets et publie une
branche spéciale `deploy`. Le VPS doit tirer cette branche et non `main` ou
`develop`, car `public/build` est ignoré dans les branches de développement.

**[POSTE]**

```bash
git ls-remote --heads origin deploy
```

Ne pas continuer si aucune référence `refs/heads/deploy` n'apparaît ou si les
workflows de tests/build sont rouges.

### 8.2 Ajouter le dépôt dans Plesk

**[PLESK]** → Sites Web & Domaines → Git → Ajouter un dépôt :

- type : dépôt Git distant ;
- URL : URL SSH du dépôt ;
- branche active : `deploy` ;
- chemin de déploiement : `httpdocs` ;
- mode : **déploiement manuel** ;
- webhook/déploiement automatique : désactivé ;
- actions additionnelles : **vides pour ce premier déploiement**.

Plesk affiche une clé SSH publique. Ajouter cette clé à la forge comme clé de
déploiement **en lecture seule**.

Dans Plesk, cliquer d'abord sur **Tirer les mises à jour**, puis sur
**Déployer depuis le dépôt**.

### 8.3 Contrôler les fichiers déposés

**[ABO]**

```bash
umask 027
cd /var/www/vhosts/tripleframes.fun/httpdocs
pwd
test -f artisan && echo 'artisan présent'
test -f public/index.php && echo 'racine publique présente'
test -f public/build/manifest.json && echo 'assets construits présents'
test ! -e .env && echo '.env absent : normal au premier déploiement'
test ! -d vendor && echo 'vendor absent : normal avant Composer'
```

Vérifier que le contenu public est lisible par nginx :

```bash
find public \( -type f ! -perm -o=r \) -o \( -type d ! -perm -o=x \) | wc -l
```

Le résultat attendu est `0`. Sinon, corriger **uniquement** `public/` :

```bash
find public -type d -exec chmod o+rx {} +
find public -type f -exec chmod o+r {} +
```

Ne jamais rendre `.env`, `bootstrap/cache` ou `storage` lisibles par tous.

## 9. Phase D — créer les données privées et le `.env`

### 9.1 Répertoires privés

**[ABO]**

```bash
umask 027
PHP=/opt/plesk/php/8.5/bin/php
cd /var/www/vhosts/tripleframes.fun/httpdocs
(umask 077 && mkdir -p \
    /var/www/vhosts/tripleframes.fun/private/tripleframes/frames \
    /var/www/vhosts/tripleframes.fun/private/tripleframes/snapshots \
    "$HOME/.config/tripleframes")
chmod 0700 \
    /var/www/vhosts/tripleframes.fun/private/tripleframes/frames \
    /var/www/vhosts/tripleframes.fun/private/tripleframes/snapshots \
    "$HOME/.config/tripleframes"
```

Ces répertoires sont hors du chemin Git : un nouveau déploiement ne peut pas
effacer les images ni les instantanés SQL.

### 9.2 Générer les secrets

**[ABO]**

Générer une clé Laravel :

```bash
"$PHP" -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
```

Générer les valeurs Reverb et le jeton des sondes :

```bash
openssl rand -hex 16
openssl rand -hex 16
openssl rand -hex 32
openssl rand -hex 32
```

Attribuer les résultats, dans l'ordre, à :

1. `REVERB_APP_ID` ;
2. `REVERB_APP_KEY` ;
3. `REVERB_APP_SECRET` ;
4. `OPS_PROBE_TOKEN`.

Ne pas générer un nouveau mot de passe si l'instance Redis distante existe
déjà. Utiliser le mot de passe ou l'identifiant ACL fourni par ce VPS et le
stocker dans le gestionnaire de secrets.

### 9.3 Créer et remplir le `.env`

**[ABO]**

```bash
cd /var/www/vhosts/tripleframes.fun/httpdocs
(umask 077 && cp .env.example .env)
chmod 0600 .env
nano .env
```

Adapter au minimum les lignes suivantes. Les valeurs `<SECRET_...>` doivent
être collées depuis le gestionnaire de secrets, jamais copiées dans Git.

```dotenv
APP_NAME=TripleFrames
APP_ENV=production
APP_KEY=<SECRET_APP_KEY>
APP_DEBUG=false
APP_URL=https://tripleframes.fun
APP_LOCALE=fr
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=info

SITE_INDEXABLE=false
ACCOUNTS_REGISTRATION_OPEN=false
ACCOUNTS_PASSKEYS_ENABLED=false
LEGAL_CONTACT_EMAIL=

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tripleframes
DB_USERNAME=tripleframes
DB_PASSWORD=<SECRET_DB_PASSWORD>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

FRAMES_DISK_ROOT=/var/www/vhosts/tripleframes.fun/private/tripleframes/frames
BACKUP_SNAPSHOT_DIR=/var/www/vhosts/tripleframes.fun/private/tripleframes/snapshots
BACKUP_SNAPSHOT_KEEP_DAYS=7

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=À_RENSEIGNER_HOTE_PRIVE_REDIS
REDIS_PORT=À_RENSEIGNER_PORT_REDIS
REDIS_USERNAME=
REDIS_PASSWORD=<SECRET_REDIS_PASSWORD>
REDIS_QUEUE_RETRY_AFTER=960

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<SECRET_REVERB_APP_ID>
REVERB_APP_KEY=<SECRET_REVERB_APP_KEY>
REVERB_APP_SECRET=<SECRET_REVERB_APP_SECRET>
REVERB_HOST=127.0.0.1
REVERB_PORT=8081
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8081
REVERB_CLIENT_HOST=
REVERB_CLIENT_PORT=
REVERB_CLIENT_SCHEME=
REVERB_ALLOWED_ORIGINS=tripleframes.fun
REVERB_MAX_REQUEST_SIZE=524288

DEPLOY_DRAIN_TIMEOUT_MINUTES=
DEPLOY_WINDOW_MINUTES=30

OPS_PROBE_TOKEN=<SECRET_OPS_PROBE_TOKEN>
OPS_LOAD_CPU_COUNT=

TMDB_API_READ_ACCESS_TOKEN=<SECRET_TMDB_TOKEN>
CURATION_CAPTURE_ENABLED=
```

Points importants :

- `APP_ENV=production` et `APP_DEBUG=false` sont obligatoires ;
- `SITE_INDEXABLE=false` maintient le site en `noindex` au lancement ;
- `REDIS_HOST` doit être l'adresse privée ou le nom DNS privé du VPS Redis,
  jamais son adresse publique exposée directement à Internet ;
- `REDIS_PORT` est le port réellement configuré sur ce VPS Redis ;
- `REDIS_USERNAME` reste vide pour une authentification par mot de passe seul,
  ou reçoit le nom ACL fourni par l'administrateur Redis ;
- `REVERB_CLIENT_*` restent vides, car les navigateurs passent par nginx en
  HTTPS/WSS sur le port 443 ;
- `REVERB_ALLOWED_ORIGINS` contient le domaine sans `https://` ;
- laisser `LEGAL_CONTACT_EMAIL` vide jusqu'à ce que l'adresse existe ;
- si `/proc/cpuinfo` est interdit par `open_basedir`, mettre le résultat de
  `nproc` dans `OPS_LOAD_CPU_COUNT` ;
- compléter les variables `MAIL_*` avant d'attendre de vrais courriels ;
- conserver `ACCOUNTS_REGISTRATION_OPEN=false` et
  `ACCOUNTS_PASSKEYS_ENABLED=false` au lancement.

Contrôler sans afficher les secrets :

```bash
stat -c '%a %U:%G %n' .env
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|DB_CONNECTION|DB_HOST|DB_DATABASE|DB_USERNAME|REDIS_HOST|REDIS_PORT|REVERB_SERVER_HOST|REVERB_SERVER_PORT)=' .env
```

Résultat attendu pour `.env` : permission `600`, propriétaire = utilisateur de
l'abonnement.

Copier immédiatement `APP_KEY` dans deux emplacements sécurisés hors du VPS.

## 10. Phase E — connecter le Redis distant

### 10.1 Conditions de sécurité obligatoires

Le Redis se trouve temporairement sur un autre VPS. Avant de continuer, il
doit respecter les conditions suivantes :

- liaison par réseau privé de l'hébergeur ou par tunnel WireGuard ;
- aucune écoute Redis directement accessible depuis Internet ;
- pare-feu du VPS Redis limité au réseau/tunnel du VPS applicatif ;
- authentification par mot de passe long ou ACL dédiée ;
- instance ou périmètre réservé à TripleFrames ;
- persistance AOF activée ;
- politique mémoire `noeviction` ;
- commandes `FLUSHALL`, `FLUSHDB`, `KEYS`, `CONFIG` et `DEBUG` désactivées.

Une connexion Redis brute vers une adresse publique n'est pas acceptable,
même avec un mot de passe : le protocole et les données applicatives seraient
exposés au réseau. Si aucun réseau privé ou tunnel n'existe encore, arrêter ici
et le mettre en place avant l'ouverture du site.

### 10.2 Tester depuis le VPS applicatif

**[ROOT]** ou **[ABO]** :

```bash
read -rp 'Hôte privé Redis : ' REDIS_HOST_TEST
read -rp 'Port Redis : ' REDIS_PORT_TEST
read -rp 'Utilisateur ACL Redis (vide si aucun) : ' REDIS_USER_TEST
read -rsp 'Mot de passe Redis : ' REDISCLI_AUTH; echo
export REDISCLI_AUTH
REDIS_TEST_ARGS=(-h "$REDIS_HOST_TEST" -p "$REDIS_PORT_TEST")
[ -z "$REDIS_USER_TEST" ] || REDIS_TEST_ARGS+=(--user "$REDIS_USER_TEST")
redis-cli "${REDIS_TEST_ARGS[@]}" ping
redis-cli "${REDIS_TEST_ARGS[@]}" info persistence | grep aof_enabled
redis-cli "${REDIS_TEST_ARGS[@]}" info memory | grep -E 'maxmemory:|maxmemory_policy'
unset REDISCLI_AUTH REDIS_HOST_TEST REDIS_PORT_TEST REDIS_USER_TEST REDIS_TEST_ARGS
```

Résultats attendus : `PONG`, `aof_enabled:1` et
`maxmemory_policy:noeviction`. Ne pas tester `FLUSHALL` sur un serveur distant
déjà utilisé : faire confirmer sa désactivation par l'administrateur Redis.

Cette topologie ajoute de la latence réseau sur la file `game`, le cache, les
sessions et Reverb. Elle est provisoire : refaire impérativement la séance de
charge et les tests de cadencement avant d'autoriser de vraies parties.

Reporter ensuite l'hôte privé, le port et le secret dans le `.env`. La phase
suivante vérifiera la configuration Laravel après l'installation de `vendor/`.

## 11. Phase F — installer Laravel et initialiser la base

### 11.1 Dépendances PHP

**[ABO]**

```bash
umask 027
PHP=/opt/plesk/php/8.5/bin/php
COMPOSER_PHAR=/usr/lib/plesk-9.0/composer.phar
cd /var/www/vhosts/tripleframes.fun/httpdocs
"$PHP" "$COMPOSER_PHAR" install \
    --no-dev --optimize-autoloader --no-interaction
```

Il faut exécuter `install`, jamais `update` : la production doit respecter
exactement `composer.lock`.

### 11.2 Contrôle avant migration

**[ABO]**

```bash
grep -E '^(APP_ENV|APP_DEBUG|DB_CONNECTION|DB_HOST|DB_DATABASE|DB_USERNAME)=' .env
"$PHP" artisan about --only=environment
"$PHP" artisan tinker --execute='dump(Illuminate\Support\Facades\Redis::connection()->ping());'
```

Ne migrer que si l'environnement est `production`, le débogage est désactivé
et la base affichée est bien la nouvelle base de production. Le dernier test
doit répondre `PONG` ; sinon, corriger la liaison vers le Redis distant avant
de lancer les migrations ou les workers.

### 11.3 Schéma et données de plateforme

**[ABO]**

```bash
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --class=PlatformDataSeeder --force
```

`PlatformDataSeeder` initialise les données structurelles de la plateforme et
est prévu par le hook. Les seeders de démonstration sont interdits ici.

## 12. Phase G — installer les workers et Reverb

### 12.1 Copier les unités systemd

**[ROOT]**

```bash
cd /var/www/vhosts/tripleframes.fun/httpdocs
install -d -o root -g root -m 0755 /etc/tripleframes
install -o root -g root -m 0644 ops/systemd/tripleframes-worker@.service \
    /etc/systemd/system/tripleframes-worker@.service
for queue in game default; do
    install -o root -g root -m 0644 "ops/systemd/worker-${queue}.env" \
        "/etc/tripleframes/worker-${queue}.env"
    install -D -o root -g root -m 0644 \
        "ops/systemd/tripleframes-worker@${queue}.service.d/limits.conf" \
        "/etc/systemd/system/tripleframes-worker@${queue}.service.d/limits.conf"
done
install -o root -g root -m 0644 ops/systemd/tripleframes-reverb.service \
    /etc/systemd/system/tripleframes-reverb.service
```

Remplacer les paramètres du VPS, passer les commandes systemd à PHP 8.5 et
retirer leur dépendance à l'unité Redis locale, qui n'existe pas dans la
topologie actuelle :

```bash
sed -i \
  -e 's/__TF_SUBSCRIPTION_USER__/tripleframes/g' \
  -e 's/__TF_SUBSCRIPTION_GROUP__/psacln/g' \
  -e 's|__TF_DEPLOY_PATH__|/var/www/vhosts/tripleframes.fun/httpdocs|g' \
  -e 's|/opt/plesk/php/8.4/bin/php|/opt/plesk/php/8.5/bin/php|g' \
  -e 's/ tripleframes-redis\.service//g' \
  /etc/systemd/system/tripleframes-worker@.service \
  /etc/systemd/system/tripleframes-reverb.service
```

Vérifier les remplacements :

```bash
grep -nE '^[^#]*(__TF_|tripleframes-redis\.service|php/8\.4/)' \
    /etc/systemd/system/tripleframes-worker@.service \
    /etc/systemd/system/tripleframes-reverb.service \
    /etc/tripleframes/worker-*.env || true
systemd-analyze verify \
    /etc/systemd/system/tripleframes-worker@.service \
    /etc/systemd/system/tripleframes-reverb.service
```

Aucune ligne de valeur ne doit encore contenir `__TF_`, PHP 8.4 ou une
dépendance à `tripleframes-redis.service`. Les commentaires des gabarits peuvent
encore citer ces anciennes valeurs pour les expliquer. Les avertissements qui
concernent des unités MySQL absentes peuvent être normaux ; une erreur de
syntaxe ne l'est pas.

Les limites livrées supposent environ 4 Go de RAM. Si le relevé montre moins de
4 Go, revoir les quatre plafonds avant le démarrage. Pour chaque worker,
conserver strictement l'ordre suivant : `--memory` du worker < `memory_limit`
de PHP < `MemoryMax` de systemd. Ne pas improviser ces valeurs sur un VPS qui
héberge déjà d'autres sites.

### 12.2 Activer sans démarrer

**[ROOT]**

```bash
systemctl daemon-reload
systemctl enable tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
```

Ne pas encore les démarrer : les caches Laravel doivent être construits avant.

### 12.3 Ajouter les directives nginx

**[ROOT]** — préparer une copie sans secret :

```bash
sed 's/__TF_REVERB_PORT__/8081/g' \
    /var/www/vhosts/tripleframes.fun/httpdocs/ops/nginx/additional-directives.conf
```

Copier toute la sortie.

**[PLESK]** → Sites Web & Domaines → Paramètres d'Apache et de nginx →
Directives nginx supplémentaires : coller le contenu, puis enregistrer.

Le bloc assure trois fonctions :

- des tampons FastCGI assez grands pour éviter les erreurs 502 ;
- le frontal Laravel `index.php` pour les routes sans extension ;
- le proxy WebSocket public `/app/` vers Reverb en boucle locale.

Si Plesk refuse `duplicate location /`, supprimer uniquement le bloc
`location / { ... }`, car sa configuration le fournit déjà. S'il refuse un
doublon de `fastcgi_buffer_size` et que sa valeur est au moins `16k`, supprimer
les deux lignes `fastcgi_*` du texte collé.

### 12.4 Pare-feu

**[PLESK]** ou **[ROOT]** : ne créer aucune règle entrante pour `8081`. Seuls
80/443 et le port SSH d'administration doivent être accessibles selon la
politique habituelle du serveur. La règle Redis se trouve sur l'autre VPS et
doit accepter uniquement le réseau privé ou le tunnel du VPS applicatif.

### 12.5 Ajouter le planificateur Laravel

**[PLESK]** → Tâches planifiées de l'abonnement → Exécuter une commande :

```text
/opt/plesk/php/8.5/bin/php /var/www/vhosts/tripleframes.fun/httpdocs/artisan schedule:run
```

Réglages :

- fréquence : chaque minute ;
- utilisateur : utilisateur système de l'abonnement ;
- notification de sortie : désactivée ;
- fuseau : vérifier qu'il correspond à UTC.

Cliquer sur **Exécuter maintenant** une fois, puis vérifier côté SSH :

**[ABO]**

```bash
umask 027
cd /var/www/vhosts/tripleframes.fun/httpdocs
/opt/plesk/php/8.5/bin/php artisan schedule:list
```

## 13. Phase H — construire les caches et démarrer les services

### 13.1 Construire les caches avec les bons droits

**[ABO]**

```bash
umask 027
PHP=/opt/plesk/php/8.5/bin/php
cd /var/www/vhosts/tripleframes.fun/httpdocs
"$PHP" artisan optimize
"$PHP" artisan lang:hash
stat -c '%a %U:%G %n' bootstrap/cache/config.php
```

`bootstrap/cache/config.php` contient une copie de tous les secrets du `.env`.
Il doit appartenir à l'utilisateur d'abonnement et être en `640`, jamais en
`644` ou `664`.

Si les droits sont trop ouverts :

```bash
chmod 0640 bootstrap/cache/*.php
```

Puis toujours conserver `umask 027` lors des futures commandes Artisan.

### 13.2 Démarrer les workers et Reverb

**[ROOT]**

```bash
systemctl start tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
systemctl --no-pager --full status \
    tripleframes-worker@game \
    tripleframes-worker@default \
    tripleframes-reverb
systemctl show \
    tripleframes-worker@game \
    tripleframes-worker@default \
    tripleframes-reverb \
    -p Id -p MemoryMax -p CPUWeight
ss -ltnp | grep ':8081\b'
```

Les trois unités locales doivent être `active (running)`. Reverb doit écouter
uniquement sur `127.0.0.1:8081`. La disponibilité du Redis distant est validée
par les sondes et par le test `redis-cli` de la phase E.

En cas d'échec :

```bash
journalctl -u tripleframes-worker@game -n 100 --no-pager
journalctl -u tripleframes-worker@default -n 100 --no-pager
journalctl -u tripleframes-reverb -n 100 --no-pager
```

## 14. Phase I — tests avant ouverture

### 14.1 Santé HTTP et sécurité de base

**[POSTE]** ou **[ABO]**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://tripleframes.fun/up
curl -sSI https://tripleframes.fun/
curl -sS -o /dev/null -w '%{http_code}\n' https://tripleframes.fun/login
curl -sS -o /dev/null -w '%{http_code}\n' https://tripleframes.fun/register
curl -sS https://tripleframes.fun/robots.txt
```

Attendu :

- `/up` → `200` ;
- `/login` → `200` ;
- `/register` → `404` ;
- en-tête `Strict-Transport-Security` présent ;
- en-tête `X-Robots-Tag: noindex, nofollow` au lancement ;
- `robots.txt` interdit au moins `/admin` et `/f/`.

### 14.2 Vérifier langue et absence de cache nginx

```bash
curl -s -D - -H 'Accept-Language: fr' https://tripleframes.fun/ \
    | grep -iE '^(age|x-cache|x-proxy-cache):|<html'
curl -s -D - -H 'Accept-Language: en' https://tripleframes.fun/ \
    | grep -iE '^(age|x-cache|x-proxy-cache):|<html'
```

Attendu : `<html lang="fr"` puis `<html lang="en"`, sans en-tête `Age`,
`X-Cache` ni `X-Proxy-Cache`.

### 14.3 Vérifier les sondes privées

**[ABO]**, dans Bash :

```bash
read -rsp 'OPS_PROBE_TOKEN : ' TOKEN; echo
for probe in worker-game worker-default load integrity purge; do
    printf '%s ' "$probe"
    curl -sS -H @<(printf 'X-Probe-Token: %s\n' "$TOKEN") \
        "https://tripleframes.fun/ops/probe/$probe"
    echo
done
unset TOKEN
```

Attendu : `{"status":"ok"}` pour chaque sonde.

Sur une base neuve, `purge` peut rester rouge jusqu'à la première exécution :

```bash
umask 027
cd /var/www/vhosts/tripleframes.fun/httpdocs
/opt/plesk/php/8.5/bin/php artisan purge:run --sync
```

Attendre ensuite jusqu'à environ dix minutes pour le premier passage de
`room:archive-idle`, puis retester.

### 14.4 Vérifier WebSocket de bout en bout

**[POSTE]**

```bash
npx wscat -o https://tripleframes.fun \
  -c 'wss://tripleframes.fun/app/<REVERB_APP_KEY>?protocol=7&client=js&version=8.4.0'
```

Attendu : événement `pusher:connection_established`.

### 14.5 Confirmer que Reverb n'est pas exposé directement

**[POSTE Windows / PowerShell]**

```powershell
Test-NetConnection tripleframes.fun -Port 8081
```

Attendu : `TcpTestSucceeded : False`. Les navigateurs atteignent Reverb
uniquement à travers `wss://tripleframes.fun/app/` sur le port 443.

### 14.6 Premier instantané SQL

**[ABO]**

```bash
umask 027
PHP=/opt/plesk/php/8.5/bin/php
cd /var/www/vhosts/tripleframes.fun/httpdocs
"$PHP" artisan backup:snapshot
ls -l /var/www/vhosts/tripleframes.fun/private/tripleframes/snapshots
gzip -t /var/www/vhosts/tripleframes.fun/private/tripleframes/snapshots/snapshot-*.sql.gz
zcat /var/www/vhosts/tripleframes.fun/private/tripleframes/snapshots/snapshot-*.sql.gz | tail -n 1
"$PHP" artisan backup:snapshot --if-pending
```

Attendu : fichier `snapshot-...sql.gz` en `600`, archive valide, dernière ligne
`-- Dump completed on ...`, puis aucune nouvelle archive pour `--if-pending`.

Important : ce dépôt ne contient pas encore `ops/backup/backup-hot.sh` ni
`backup-cold.sh`. Cet instantané local ne remplace donc pas une sauvegarde
chiffrée hors machine. Ne pas introduire de données irremplaçables ni ouvrir le
jeu publiquement avant la livraison et le test de la sauvegarde externe et de
la restauration.

## 15. Phase J — créer le premier administrateur

**[ABO]**

```bash
umask 027
cd /var/www/vhosts/tripleframes.fun/httpdocs
/opt/plesk/php/8.5/bin/php artisan admin:first-admin --create
```

La commande demande interactivement l'adresse e-mail, le nom de compte, le nom
réel et le mot de passe. Ne passer aucun de ces éléments en argument.

Ensuite :

1. ouvrir `https://tripleframes.fun/login` ;
2. se connecter ;
3. enrôler immédiatement le second facteur ;
4. ranger les codes de secours dans deux emplacements hors du VPS, avec la
   copie de `APP_KEY`.

## 16. Phase K — activer le hook de déploiement Plesk

### Prérequis bloquant : versionner PHP 8.5 et le Redis distant

Le hook et les unités versionnées désignent encore PHP 8.4 au moment de la
rédaction de ce guide. Les unités attendent aussi une unité Redis locale. Il
faut aligner ces fichiers avec la topologie réelle avant d'activer le hook.

**[POSTE]**, dans la branche de développement appropriée :

```bash
sed -i 's|/opt/plesk/php/8.4/bin/php|/opt/plesk/php/8.5/bin/php|g' \
  ops/deploy/hook.sh \
  ops/systemd/tripleframes-worker@.service \
  ops/systemd/tripleframes-reverb.service
sed -i 's/ tripleframes-redis\.service//g' \
  ops/systemd/tripleframes-worker@.service \
  ops/systemd/tripleframes-reverb.service
git diff --check
git grep -n '/opt/plesk/php/8.4/bin/php' -- \
  ops/deploy/hook.sh \
  ops/systemd/tripleframes-worker@.service \
  ops/systemd/tripleframes-reverb.service
git grep -n '^[^#].*tripleframes-redis\.service' -- \
  ops/systemd/tripleframes-worker@.service \
  ops/systemd/tripleframes-reverb.service
```

Les deux commandes `git grep` ne doivent rien afficher. Committer et pousser
cette modification, attendre la CI verte et la reconstruction de la branche
`deploy`. Ne pas modifier le hook directement dans `httpdocs`, car le prochain
déploiement écraserait cette modification.

### 16.1 Créer la configuration locale du hook

**[ABO]**

```bash
(umask 077 && mkdir -p "$HOME/.config/tripleframes" && \
  printf 'COMPOSER_PHAR=%s\n' '/usr/lib/plesk-9.0/composer.phar' \
  > "$HOME/.config/tripleframes/hook.env")
chmod 0600 "$HOME/.config/tripleframes/hook.env"
stat -c '%a %U:%G %n' "$HOME/.config/tripleframes/hook.env"
```

Le fichier ne contient pas de secret, mais ses droits restent privés par
cohérence. Le hook lit les vrais secrets dans `.env`.

### 16.2 Configurer l'action Plesk

**[PLESK]** → Git → Paramètres du dépôt → Actions de déploiement
additionnelles :

```text
bash ops/deploy/hook.sh
```

Conserver le mode manuel. Vérifier que le délai maximum accordé aux actions est
suffisant pour `composer install`, l'instantané SQL et les migrations.

### 16.3 Faire le déploiement de validation

**[PLESK]**

1. cliquer sur **Tirer les mises à jour** ;
2. vérifier le commit affiché ;
3. cliquer sur **Déployer depuis le dépôt** ;
4. lire toute la sortie du hook.

Le hook actuel exécute :

1. `composer install --no-dev` ;
2. nettoyage des caches de démarrage sans vider Redis ;
3. instantané SQL si une migration est en attente ;
4. migrations ;
5. `PlatformDataSeeder` ;
6. reprojection du catalogue ;
7. reconstruction des caches ;
8. empreinte des traductions ;
9. redémarrage logique des workers ;
10. redémarrage logique de Reverb.

Les étapes de drainage 3 et 12 sont encore commentées dans
`ops/deploy/hook.sh`. C'est intentionnel pour le premier lancement. Ne pas
ouvrir de vraies parties tant que l'activation versionnée du drainage n'a pas
été livrée.

### 16.4 Refaire les contrôles après le hook

**[ABO]**

```bash
umask 027
cd /var/www/vhosts/tripleframes.fun/httpdocs
stat -c '%a %U:%G %n' bootstrap/cache/*.php
find public \( -type f ! -perm -o=r \) -o \( -type d ! -perm -o=x \) | wc -l
```

Puis rejouer `/up`, les sondes et le test WSS. Le nombre de fichiers publics
illisibles doit rester `0`, et `bootstrap/cache/config.php` doit rester `640`.

## 17. Procédure des futurs déploiements

Tant que le drainage n'est pas activé, aucun déploiement ne doit avoir lieu
pendant une vraie partie.

Après livraison du commit qui active les étapes `deploy:guard` et
`deploy:release` dans le hook :

**[ABO]**

```bash
umask 027
PHP=/opt/plesk/php/8.5/bin/php
cd /var/www/vhosts/tripleframes.fun/httpdocs
"$PHP" artisan deploy:drain
"$PHP" artisan deploy:guard
```

`deploy:drain` ferme les nouvelles parties et attend la fin naturelle de celles
en cours. Cette attente peut être longue. La lancer dans `tmux` si nécessaire :

```bash
tmux new -s tripleframes-drain
```

Après le succès de `deploy:guard` :

1. **[PLESK]** tirer la branche `deploy` ;
2. **[PLESK]** cliquer sur Déployer ;
3. vérifier que le hook va jusqu'à `deploy:release` ;
4. retester `/up`, les sondes, WSS et les droits.

Ne jamais modifier directement le hook sur le serveur. L'activation du drainage
doit être un commit testé et publié dans la branche `deploy` par la CI.

## 18. Contrôle final des placeholders

**[ROOT]**

```bash
grep -rnE '^[^#]*(__TF_|<[A-Z0-9_]+>|À_RENSEIGNER_)' \
  /etc/tripleframes \
  /etc/systemd/system/tripleframes-* \
  /var/www/vhosts/tripleframes.fun/httpdocs/.env
```

La commande ne doit rien afficher. Un placeholder oublié peut produire une
configuration qui démarre en apparence tout en étant dangereuse.

Contrôler aussi :

```bash
systemctl is-enabled \
  tripleframes-worker@game \
  tripleframes-worker@default \
  tripleframes-reverb
systemctl is-active \
  tripleframes-worker@game \
  tripleframes-worker@default \
  tripleframes-reverb
```

Toutes les lignes doivent répondre `enabled`, puis `active`.

## 19. Diagnostic rapide

### `/up` répond 500

```bash
tail -n 100 /var/www/vhosts/tripleframes.fun/httpdocs/storage/logs/laravel*.log
journalctl -u tripleframes-worker@default -n 100 --no-pager
```

Vérifier en priorité `.env`, la connexion MySQL, Redis, les permissions de
`storage` et `bootstrap/cache`, puis rejouer avec l'utilisateur d'abonnement :

```bash
umask 027
cd /var/www/vhosts/tripleframes.fun/httpdocs
/opt/plesk/php/8.5/bin/php artisan about --only=environment
```

### `/login` répond 502

Consulter le journal nginx du domaine. Si l'erreur contient
`upstream sent too big header`, les directives `fastcgi_buffer_size` et
`fastcgi_buffers` n'ont pas été appliquées ou sont trop petites.

### Toutes les routes sauf les fichiers statiques répondent 404

Le bloc nginx `location /` avec `try_files ... /index.php` manque ou a été
refusé par Plesk.

### Les assets répondent 403

```bash
cd /var/www/vhosts/tripleframes.fun/httpdocs
find public \( -type f ! -perm -o=r \) -o \( -type d ! -perm -o=x \) -print
```

Corriger uniquement les fichiers/répertoires listés dans `public/`, puis revoir
les permissions produites par Plesk Git.

### Redis distant refuse la connexion

```bash
read -rp 'Hôte privé Redis : ' REDIS_HOST_TEST
read -rp 'Port Redis : ' REDIS_PORT_TEST
read -rp 'Utilisateur ACL Redis (vide si aucun) : ' REDIS_USER_TEST
read -rsp 'Mot de passe Redis : ' REDISCLI_AUTH; echo
export REDISCLI_AUTH
REDIS_TEST_ARGS=(-h "$REDIS_HOST_TEST" -p "$REDIS_PORT_TEST")
[ -z "$REDIS_USER_TEST" ] || REDIS_TEST_ARGS+=(--user "$REDIS_USER_TEST")
redis-cli "${REDIS_TEST_ARGS[@]}" ping
unset REDISCLI_AUTH REDIS_HOST_TEST REDIS_PORT_TEST REDIS_USER_TEST REDIS_TEST_ARGS
```

Vérifier le réseau privé/tunnel, le pare-feu du VPS Redis, l'hôte, le port, le
mot de passe ou l'ACL et la valeur du `.env`. Ne jamais afficher le mot de passe
avec `grep` dans une capture ou un ticket.

### Worker en boucle de redémarrage

```bash
systemctl status tripleframes-worker@game --no-pager
journalctl -u tripleframes-worker@game -n 100 --no-pager
```

Vérifier `User`, `Group`, `WorkingDirectory`, la présence de `vendor/`, la
lisibilité de `.env` par l'utilisateur d'abonnement et la connexion Redis.

### Reverb est actif mais WSS échoue

```bash
systemctl status tripleframes-reverb --no-pager
ss -ltnp | grep ':8081\b'
journalctl -u tripleframes-reverb -n 100 --no-pager
```

Vérifier ensuite le port du `proxy_pass`, `REVERB_SERVER_PORT`,
`REVERB_ALLOWED_ORIGINS`, la clé publique utilisée dans l'URL WSS et le
certificat du domaine.

### La tâche planifiée ne tourne pas

**[ROOT]**

```bash
crontab -u tripleframes -l
```

Vérifier le chemin absolu vers PHP et Artisan, le shell non chrooté, le fuseau
du serveur et le résultat du bouton Plesk **Exécuter maintenant**.

### Un cache Laravel appartient à `root`

Arrêter de lancer Artisan en `root`. Réparer une fois :

**[ROOT]**

```bash
chown tripleframes:psacln /var/www/vhosts/tripleframes.fun/httpdocs/bootstrap/cache/*.php
chmod 0640 /var/www/vhosts/tripleframes.fun/httpdocs/bootstrap/cache/*.php
```

Puis reconstruire uniquement en **[ABO]** avec `umask 027`.

## 20. Checklist finale courte

- [ ] DNS correct, certificat valide, redirection HTTP → HTTPS.
- [ ] Document root = `httpdocs/public` et code dans `httpdocs`.
- [ ] PHP 8.5 FPM via nginx, Apache proxy et cache nginx désactivés.
- [ ] Base et utilisateur MySQL dédiés.
- [ ] Plesk tire manuellement la branche `deploy`.
- [ ] `.env` en `600`, `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `APP_KEY` sauvegardée deux fois hors VPS.
- [ ] Redis distant authentifié, persistant et joint uniquement par réseau
  privé ou tunnel chiffré.
- [ ] Deux workers et Reverb actifs sous l'utilisateur d'abonnement.
- [ ] Redis et le port direct Reverb `8081` injoignables depuis Internet.
- [ ] Tâche `schedule:run` chaque minute.
- [ ] `/up`, `/login`, langue, sondes et WSS validés.
- [ ] Premier instantané SQL validé.
- [ ] Premier administrateur créé, 2FA activé, codes de secours archivés.
- [ ] Hook Plesk validé de bout en bout.
- [ ] Aucun placeholder `__TF_...__` ou `<...>` dans les fichiers actifs.
- [ ] Sauvegarde externe et restauration testées avant les vraies données.
- [ ] Drainage activé par un commit testé avant les vraies parties.
- [ ] Réglages réellement appliqués datés dans le journal de
  `ops/plesk/settings.md` sans y inscrire de domaine, d'IP ni de secret.

## 21. Références

Références propres au projet :

- `ops/mise-en-service.md` : checklist normative de première mise en service ;
- `ops/plesk/settings.md` : réglages Plesk détaillés ;
- `ops/deploy/hook.sh` : hook réellement exécuté ;
- `ops/nginx/additional-directives.conf` : configuration nginx ;
- `ops/redis/` : futur gabarit si Redis revient sur le VPS applicatif ;
- `ops/systemd/` : gabarits système des workers et de Reverb ;
- `docs/ops/repetition-vm.md` : répétition exhaustive et résultats observés.

Documentation Plesk consultée pour vérifier le parcours d'interface :

- [Déployer du contenu avec Git](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/websites-and-domains/website-content/deploying-content-using-git.75877/) ;
- [Dépôt Git, mode manuel et actions additionnelles](https://docs.plesk.com/en-US/obsidian/reseller-guide/website-management/git-support/using-a-local-repository.75825/) ;
- [Laravel Toolkit et racine publique](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/laravel-toolkit.80010/) ;
- [Tâches planifiées](https://docs.plesk.com/en-US/obsidian/administrator-guide/server-administration/scheduling-tasks.64993/) ;
- [PHP Plesk en ligne de commande](https://docs.plesk.com/en-US/obsidian/administrator-guide/76345/) ;
- [Composer avec le PHP de Plesk](https://support.plesk.com/hc/en-us/articles/12377596215703-How-to-run-Composer-with-Plesk-PHP).
