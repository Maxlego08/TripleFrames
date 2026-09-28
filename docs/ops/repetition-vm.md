# Répétition de la mise en service sur la VM Homestead

## Objet

Rejouer **de bout en bout**, sur la VM Homestead locale, la mise en service de production de TripleFrames telle que la décrivent `docs/specs/100-qualite-tests-et-ci.md` (§ 10 à § 16) et `ops/mise-en-service.md`, pour que le porteur puisse ensuite refaire **les mêmes gestes, dans le même ordre**, sur le VPS Plesk. Chaque étape exécutée sur la VM est consignée ici avec ses commandes exactes, son résultat et ce qui change sur le VPS.

Ce document ne décide rien : en cas d'écart, la spec fait foi (`100` § 10, § 11, § 13, § 15, § 16) ; les écarts découverts par la répétition sont listés en fin de document, pour être reportés dans les specs propriétaires.

- **Machine** : VM Vagrant Homestead du poste du porteur, Ubuntu 22.04, adresse privée `192.168.10.10` (adresse par défaut de Homestead, jamais celle du VPS), utilisateur `vagrant` avec `sudo` sans mot de passe.
- **La VM sert aussi au développement** : base `tripleframes` de dev, Redis partagé sur 6379, sites `*.test`, pools PHP-FPM, supervisor. **Rien de cela n'est touché** : tout ce que la répétition crée porte un nom propre (tableau des correspondances ci-dessous).
- **Répétition, pas production** : aucune donnée réelle de joueur, aucun vrai domaine, aucune passkey, aucun compte hors l'administrateur de répétition.

## Conventions

- **Secrets** : jamais écrits ici. Les commandes portent des jetons : `<APP_KEY>`, `<DB_PASSWORD>`, `<REDIS_PASSWORD>`, `<REVERB_APP_ID>`, `<REVERB_APP_KEY>`, `<REVERB_APP_SECRET>`, `<OPS_PROBE_TOKEN>`, `<ADMIN_PASSWORD>`, `<TMDB_TOKEN>`. Sur la VM, chaque secret est généré sur place (`openssl rand`) et n'existe que dans le `.env` de l'application (`0600`), dans `/etc/tripleframes/tripleframes-redis.conf` (`root:tfredis 0640`) ou dans `/root/tripleframes-hors-machine/` (`0700`, simulation des deux exemplaires hors machine). Sur le VPS : générés dans le terminal, collés dans l'éditeur, jamais passés en argument d'une commande.
- **`<DOMAINE>`** : le nom de domaine réel du VPS. Sur la VM, il vaut `tripleframes-prod.test` (TLD réservé `.test`).
- **Qui exécute** :
  - **[poste]** : Windows du porteur (Git Bash ou PowerShell, précisé à chaque bloc).
  - **[root]** : `sudo …` sur la VM ; **session root** sur le VPS.
  - **[abo]** : `sudo -u tripleframes -H …` sur la VM ; **session SSH de l'utilisateur d'abonnement** sur le VPS. **Toute session [abo] commence par `umask 027`** (RV-10) : un `artisan optimize` tapé à la main recrée `bootstrap/cache/config.php`, copie de tous les secrets du `.env`, avec l'umask de la session (`0002` sur la VM, `022` d'ordinaire sous Plesk).
- **« Propre à Homestead »** : tout geste qui n'existe que parce que la VM n'est pas un Plesk est signalé ainsi, avec son équivalent Plesk.
- **Format d'une étape** : titre ; **But** ; **Commandes** (exactes, telles que tapées) ; **Attendu et vérification** ; **Écart** éventuel ; **Sur le VPS Plesk :** ce qui change.

### Correspondances VM ↔ VPS

| Objet | VM (répétition) | VPS Plesk |
| --- | --- | --- |
| Nom d'hôte | `tripleframes-prod.test` | `<DOMAINE>` |
| Utilisateur d'abonnement / groupe | `tripleframes` / `tripleframes` | utilisateur de l'abonnement / `psacln` |
| Racine de l'abonnement (HOME) | `/var/www/vhosts/tripleframes-prod.test` | `/var/www/vhosts/<DOMAINE>` |
| Chemin de déploiement | `/var/www/vhosts/tripleframes-prod.test/tripleframes` | `/var/www/vhosts/<DOMAINE>/tripleframes` |
| Racine du document | `…/tripleframes/public` | idem |
| Racines privées (`0700`) | `…/private/tripleframes/frames`, `…/private/tripleframes/snapshots` | idem sous `<DOMAINE>` |
| Dépôt Git local | `…/git/tripleframes.git` (nu), alimenté par un *bundle* | dépôt de Plesk Git, tiré de la forge (branche `deploy`) |
| PHP de l'abonnement | `/opt/plesk/php/8.4/bin/php`, lien vers `/usr/bin/php8.4` (propre à Homestead) | `/opt/plesk/php/8.4/bin/php` |
| composer | `/usr/local/bin/composer` | `composer.phar` de Plesk (chemin relevé) |
| PHP-FPM | unité `tripleframes-php-fpm.service`, master dédié, pool `tripleframes` (propre à Homestead) | pool de l'abonnement, réglé dans l'interface |
| Vhost nginx | `/etc/tripleframes/nginx/tripleframes-prod.test.conf`, lié depuis `/etc/nginx/sites-enabled/zz-tripleframes-prod.test`, inclus en dernier (propre à Homestead) | généré par Plesk |
| Directives nginx additionnelles | `/etc/tripleframes/nginx/additional-directives.conf`, inclus par le vhost | champ « Directives nginx supplémentaires » |
| Certificat | signé par la CA Homestead, `/etc/tripleframes/tls/` | Let's Encrypt géré par Plesk |
| Rotation des journaux du vhost | `/etc/logrotate.d/tripleframes-prod-test` (propre à Homestead) | `<DOMAINE>` › Journaux › Gérer la rotation des journaux |
| Base / utilisateur MySQL | `tripleframes_prod` / `tripleframes_prod@localhost` | créés par Plesk |
| Redis dédié | `tripleframes-redis`, `127.0.0.1:6390`, utilisateur `tfredis` | idem, port relevé |
| Reverb | `tripleframes-reverb`, `127.0.0.1:8090` | idem, port relevé |
| Planificateur | `crontab -u tripleframes` | tâche planifiée Plesk |
| Exemplaires « hors machine » | `/root/tripleframes-hors-machine/` (simulation) | inventaire scellé + gestionnaire de secrets du porteur |
| Stockage de sauvegarde | `/srv/tripleframes-stockage-distant-simule/` (simulation) | stockage objet UE, autre fournisseur (à choisir) |
| Construction des assets | clone jetable sur le poste (et étape PHP dans `/home/vagrant/tripleframes-repetition/ci/`, voir « Impasses ») | jobs `build` et `artifacts` de la CI |

## Prérequis

- **[poste]** Clé privée SSH de la VM (fichier `key.txt` du porteur) ; `ssh -i <clé> vagrant@192.168.10.10` ouvre une session.
- **[poste]** Pour ouvrir le site dans un navigateur : ajouter, dans `C:\Windows\System32\drivers\etc\hosts` (Bloc-notes lancé en administrateur), la ligne `192.168.10.10 tripleframes-prod.test`, puis `ipconfig /flushdns`. Le certificat est signé par la CA Homestead, que Windows n'approuve pas : cliquer « Continuer », ou importer une fois la CA dans le magasin de l'utilisateur (`certutil -user -addstore Root ca.homestead.homestead.crt`, sans droits administrateur).
- **[poste]** Node et npm (construction des assets) ; k6 1.0 ou plus récent pour la phase « Charge ».
- **VM démarrée** (`vagrant up`), **sans `vagrant provision`** pendant la répétition : le script `clear-nginx.sh` de Homestead vide `/etc/nginx/sites-*`. Le fichier du vhost de répétition vit donc dans `/etc/tripleframes/nginx/` ; seul son lien `sites-enabled/zz-tripleframes-prod.test` disparaîtrait, à recréer (étape 1.10).
- Le porteur est prévenu avant la phase « Charge » : MySQL et le processeur de la VM sont partagés avec son développement.

## Phase 0 — Relevé de la VM (lecture seule)

### Étape 0.1 — Relevé du système, des services et des outils

- **But** : l'équivalent du relevé du VPS (`100` § 10.1, `ops/mise-en-service.md` § 1) : ce qui existe, ce qui manque, ce qu'il ne faut pas toucher.
- **Commandes** — [poste], Git Bash :

```bash
ssh -i <clé> vagrant@192.168.10.10
```

Puis, dans la session :

```bash
cat /etc/os-release | head -4; systemctl --version | head -1
stat -fc %T /sys/fs/cgroup/; cat /sys/fs/cgroup/cgroup.controllers
nproc; free -m; df -h /; uptime
timedatectl
sudo ss -ltnp
systemctl list-units --type=service --no-pager | grep -iE 'php|nginx|mysql|redis|supervisor|memcache|beanstalk|mailpit|docker'
sudo supervisorctl status; sudo docker ps
php8.3 -v; php8.3 -m; php8.3 -r 'echo ini_get("memory_limit"), " ", ini_get("date.timezone"), PHP_EOL;'
php8.4 -v; php8.4 -m; php8.4 -r 'echo ini_get("memory_limit"), " ", ini_get("date.timezone"), PHP_EOL;'
php8.4 -r 'print_r(Imagick::queryFormats("WEBP"));'
ls /etc/php/8.3/fpm/pool.d/ /etc/php/8.4/fpm/pool.d/
nginx -v; grep -vE '^\s*#|^\s*$' /etc/nginx/nginx.conf; ls -la /etc/nginx/sites-enabled/ /etc/nginx/conf.d/
redis-server --version; ldd "$(command -v redis-server)" | grep libsystemd
sudo mysql -e "SELECT VERSION(), @@explicit_defaults_for_timestamp, @@global.time_zone, @@session.time_zone, @@system_time_zone, @@max_connections; SHOW DATABASES;"
for c in git composer node npm tmux curl cgi-fcgi mysqldump age rclone jq; do printf '%-10s %s\n' "$c" "$(command -v "$c" || echo ABSENT)"; done
apt-cache policy age rclone | grep -E '^[a-z]|Candidate|Installed'
dpkg -l needrestart unattended-upgrades | grep ^ii
sudo ufw status
curl -sS -o /dev/null -w 'packagist %{http_code}\n' https://repo.packagist.org/packages.json
curl -sS -o /dev/null -w 'tmdb %{http_code}\n' https://api.themoviedb.org/3/configuration
id tripleframes; id tfredis; ls -d /opt/plesk /etc/tripleframes /var/www/vhosts
mount | grep vboxsf
```

Et, dans **une copie** du dépôt (jamais sa copie de travail) : `php8.3 /usr/local/bin/composer check-platform-reqs --lock --no-dev`, puis la même commande sous `php8.4`.

- **Attendu et vérification** — relevé du 28/09/2026, 11:05 UTC :

| Point | Relevé |
| --- | --- |
| Système | Ubuntu 22.04.3, noyau 5.15, systemd 249 ; `cgroup2fs`, contrôleurs `cpu`, `memory`, `io`, `pids` |
| RAM, vCPU, disque | 3 912 Mo (environ 2,4 Go disponibles), 2 vCPU, 73 Go libres — sous le seuil de 4 Go de `100` § 10.1 |
| Heure | UTC partout (système, MySQL, PHP), NTP actif |
| PHP | 8.3.1 et 8.4.16 (CLI et FPM), extensions `imagick` (WEBP), `pdo_mysql`, `mbstring`, `openssl`, `sodium`, `intl`, `pcntl`, `posix`, `redis` |
| Plateforme du verrou | **refusée sous 8.3** (`symfony/clock` exige `php >=8.4.1`), acceptée sous 8.4 |
| PHP-FPM existants | `php8.1-fpm`, `php8.3-fpm`, `php8.4-fpm` (pools `www`) |
| nginx | 1.18, `user vagrant`, 10 sites `*.test` |
| MySQL | 8.0.35, `explicit_defaults_for_timestamp=1`, `max_connections=151` |
| Redis | 6.0.16 sur 127.0.0.1 et 192.168.10.10:6379 (partagé), binaire lié à `libsystemd` |
| Ports libres retenus | 6390 (Redis dédié), 8090 (Reverb) |
| Outils | composer 2.10, git 2.34, `mysqldump`, `cgi-fcgi`, `tmux` présents ; `age`, `rclone`, `jq` absents |
| apt | `needrestart` et `unattended-upgrades` actifs |
| Pare-feu | `ufw` inactif |
| Réseau sortant | packagist, GitHub, TMDB joignables |
| Noms de la répétition | tous libres |

- **Écarts** : voir « Écarts à reporter dans les specs », points 1 à 4.
- **Sur le VPS Plesk :** relevé de `ops/mise-en-service.md` § 1, en root, avec en plus `plesk version`, l'utilisateur et le répertoire privé de l'abonnement, et `/opt/plesk/php/8.4/bin/php -m` (et non 8.3, écart n° 1). Résultats consignés dans `100` § 10.1, jamais une IP, un nom de domaine ni un secret.

## Phase 1 — Socle

Jouée le 28/09/2026 de 11:23 à 11:38 UTC. Équivalent de `ops/mise-en-service.md` étape 1 (abonnement, base) et étape 4 (gestes root : Redis, unités, nginx, pare-feu), et du lot L100-9. Les services applicatifs (workers, Reverb) sont **installés, ni activés ni démarrés** : activés en phase 2 une fois le `.env` complet et `migrate` joué, démarrés après `optimize`.

**Ordre VM ≠ ordre VPS.** Sur la VM, le socle root précède le déploiement (le checkout n'existe pas encore) : les gabarits sont recopiés depuis un checkout jetable de `develop` (étape 1.1). Sur le VPS, l'ordre est celui de `ops/mise-en-service.md` : Plesk (abonnement, base, Git, premier « Déployer ») → `.env` → `composer install`, `migrate` → **puis** les gestes root, gabarits recopiés depuis le checkout de déploiement `/var/www/vhosts/<DOMAINE>/tripleframes`.

**Comment les blocs ont été joués.** Sur la VM, chaque bloc [root] a été envoyé d'un seul tenant par `ssh … 'sudo bash -s' < bloc.sh`, précédé de `set -euo pipefail` (arrêt à la première erreur). Dans une session interactive (`sudo -i` sur la VM, session root sur le VPS), taper les lignes une à une, **sans** `set -e`, qui fermerait la session à la première erreur.

**Corrections du contrôle (28/09, 11:59-12:01 UTC).** Le contrôle de la phase 1 a relevé neuf constats (dont un effet de bord du contrôle lui-même, sans impact : index Git du clone jetable réécrit par root, rendu à `vagrant` par `chown vagrant:vagrant …/tests-s1/.git/index`). Les blocs des étapes ci-dessous sont désormais les commandes **correctes**, à rejouer telles quelles (sur la VM comme sur le VPS). Chaque étape touchée porte en plus un paragraphe « Correction du contrôle » : état fautif, commandes de rattrapage réellement jouées sur la VM (un seul envoi `sudo bash -s`, `set -euo pipefail`), vérification. Étapes touchées : 1.0, 1.1, 1.3, 1.7, 1.10, 1.10 bis (nouvelle), 1.13, « Retour arrière ».

### Étape 1.0 — Relevé « avant » des services existants (témoin de non-perturbation)

- **But** : prouver après coup qu'aucun voisin n'a été touché : PID des services, codes HTTP de chaque site, Redis partagé, base de développement.
- **Commandes** — [vagrant] :

```bash
mkdir -p /home/vagrant/tripleframes-repetition/releves
cd /home/vagrant/tripleframes-repetition/releves
OUT=avant-socle.txt   # apres-socle.txt à l'étape 1.13
{
echo "== date"; date -u +%FT%TZ
echo "== PID des services existants"
for u in nginx php8.1-fpm php8.3-fpm php8.4-fpm mysql redis-server supervisor memcached beanstalkd mailpit; do
  printf '%-14s %s %s\n' "$u" "$(systemctl is-active $u 2>/dev/null)" "$(systemctl show -p MainPID --value $u 2>/dev/null)"
done
echo "== sites nginx"; ls /etc/nginx/sites-enabled/
echo "== codes HTTP des sites *.test (boucle locale)"
for f in /etc/nginx/sites-enabled/*; do
  n=$(grep -m1 -oE 'server_name\s+[^;]+' "$f" | awk '{print $2}' | sed 's/^\.//')
  c80=$(curl -s -o /dev/null -m 10 -w '%{http_code}' --resolve "$n:80:127.0.0.1" "http://$n/")
  c443=$(curl -sk -o /dev/null -m 10 -w '%{http_code}' --resolve "$n:443:127.0.0.1" "https://$n/")
  printf '%-40s %s %s\n' "$n" "$c80" "$c443"
done
echo "== serveur par défaut"; curl -s -o /dev/null -m 10 -w '%{http_code} ' http://127.0.0.1/; curl -sk -o /dev/null -m 10 -w '%{http_code}\n' https://127.0.0.1/
echo | openssl s_client -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -subject
echo "== Redis 6379"; redis-cli -p 6379 info server | grep -E 'redis_version|process_id|uptime_in_seconds'; redis-cli -p 6379 info keyspace
echo "== base tripleframes (dev)"
sudo mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tripleframes'; SELECT COUNT(*), MAX(id) FROM tripleframes.migrations;"
sudo mysql -N -e "SHOW DATABASES" | tr '\n' ' '; echo
echo "== supervisor"; sudo supervisorctl status
echo "== ports"; sudo ss -ltn | awk 'NR>1{print $4}' | sort -u | tr '\n' ' '; echo
} > "$OUT" 2>&1
```

- **Attendu et vérification** (relevé de 11:23 UTC) : nginx, `php8.1-fpm`, `php8.3-fpm`, `php8.4-fpm`, MySQL, Redis actifs (PID 973, 735, 736, 737, 870, 743) ; 10 sites, tous en 200 sauf `coaster.test` (302) et `mib.test` (503, déjà en panne avant la répétition) ; Redis 6379 : `db0:keys=22` ; base `tripleframes` : 45 tables, 46 migrations ; supervisor sans programme ; ports en écoute : `22`, `25`, `80`, `443`, `3306` (toutes interfaces), `1025` et `8025` (mailpit), `127.0.0.1:33060`, `127.0.0.1:6379` et `192.168.10.10:6379`, `127.0.0.1:11211`, `127.0.0.1:11300`, `127.0.0.53:53`. Serveur par défaut : `atomsdle.test` (premier fichier de `sites-enabled/`, aucun `default_server`) — section absente du fichier de 11:23, ajoutée par le contrôle ; attendu `200 200` et `subject=… CN = atomsdle.test`.
- **Correction du contrôle** : le fichier `avant-socle.txt` de 11:23 a été produit par une variante du bloc non consignée (sections `== date` et `== ports`, ligne `uptime_in_seconds`, et une ligne horodatée sur la base de dev après les migrations), alors que le bloc écrit ici ne produisait ni date, ni ports : rejoué tel quel sur le VPS, le relevé « avant » n'aurait capturé aucun port, et la conclusion « seul port nouveau » de 1.13 serait devenue invérifiable. Le bloc ci-dessus est aligné sur le fichier réel, plus la section « serveur par défaut » ; la comparaison de 1.13 neutralise date, `uptime` et horodatages. Rien à rattraper sur la VM : le fichier de 11:23 contient déjà les ports.
- **Sur le VPS Plesk :** même relevé, **indispensable** : ce sont les sites de production des voisins. Remplacer la liste des unités par celles du relevé (`plesk-php8x-fpm`, `nginx`, `mysql` ou `mariadb`, `sw-engine`, `psa`, un Redis voisin s'il existe) et la boucle des sites par les domaines hébergés (`plesk bin site --list`), en `https://<site>/` réel, et la section « serveur par défaut » par l'adresse du serveur : `curl -sk -o /dev/null -w '%{http_code}\n' https://<IP du VPS>/` et `echo | openssl s_client -connect <IP du VPS>:443 2>/dev/null | openssl x509 -noout -subject` (serveur par défaut de Plesk, qui ne doit pas changer). Relevé refait à l'étape 1.13.

### Étape 1.1 — Correction préalable : PHP 8.4 dans les gabarits, et checkout de référence

- **But** : lever le constat bloquant n° 1 (le verrou exige PHP ≥ 8.4.1) dans les gabarits avant de les recopier, et prouver la correction par les tests des gabarits. Le checkout jetable ainsi créé sert ensuite de **source des gabarits** pour les étapes 1.6 à 1.10.
- **Correction** (dépôt, non commitée à ce stade) : `/opt/plesk/php/8.3/bin/php` → `/opt/plesk/php/8.4/bin/php` dans `ops/deploy/hook.sh`, `ops/systemd/tripleframes-reverb.service`, `ops/systemd/tripleframes-worker@.service`, `ops/mise-en-service.md` (4 lignes), `ops/plesk/settings.md` (3 lignes, dont « Version : 8.4 »), `tests/Feature/Deploy/DeployHookTest.php` (`deployHookPhp()`), `tests/Feature/Deploy/OpsTemplatesTest.php` (`opsTemplatePhp()`), `tests/Load/game-load.js` (2 commentaires). **Non faits ici** : les deux workflows (`php-version: '8.3'`, écart n° 1), `composer.json` et les specs.
- **Commandes** — [poste], Git Bash (le PHP du poste est bloqué, voir « Impasses » : les tests tournent dans la VM) :

```bash
cd C:/Users/Admin/Desktop/groupez/tripleframes
git bundle create <scratchpad>/develop.bundle develop
git diff --name-only -- ops tests/Feature/Deploy tests/Load > <scratchpad>/overlay-s1.txt
wc -l < <scratchpad>/overlay-s1.txt   # 8 attendu ; sinon arrêt, rien ne part
tar --force-local -cf <scratchpad>/overlay-s1.tar -T <scratchpad>/overlay-s1.txt
scp -i <clé> <scratchpad>/develop.bundle <scratchpad>/overlay-s1.tar vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/
```

Puis [vagrant], dans un répertoire jetable (cache de composer compris : rien n'est écrit ailleurs dans `/home/vagrant`) :

```bash
R=/home/vagrant/tripleframes-repetition
export COMPOSER_HOME=$R/.composer COMPOSER_CACHE_DIR=$R/.composer/cache
git clone -q --branch develop $R/develop.bundle $R/tests-s1
cd $R/tests-s1
tar -xf $R/overlay-s1.tar
git status --short
cp .env.example .env
php8.4 /usr/local/bin/composer install --no-interaction --prefer-dist --no-progress
php8.4 artisan key:generate --no-interaction
php8.4 vendor/bin/pest tests/Feature/Deploy/DeployHookTest.php tests/Feature/Deploy/OpsTemplatesTest.php tests/Feature/Architecture/NoLiteralDomainTest.php
```

- **Attendu et vérification** : `git status` liste les 8 fichiers corrigés ; Pest : `DeployHookTest` 3/3, `OpsTemplatesTest` 6/6, `NoLiteralDomainTest` 2/2 — **11 tests verts, 1 485 assertions** (commit source `f76969d` + correction).
- **Écart** : le checkout de référence des gabarits est `/home/vagrant/tripleframes-repetition/tests-s1` (develop `f76969d` + correction non commitée), et non le checkout de déploiement.
- **Correction du contrôle** : la commande jouée à 11:27 était `git diff --name-only` **sans filtre de chemin** ; le porteur travaille en parallèle sur des fichiers suivis (`.gitignore`, `resources/moderation/nicknames/reserved.txt`) qu'elle aurait pu embarquer dans le clone de la VM ou dans l'artefact. La liste obtenue ne contenait que les 8 fichiers attendus (vérifié à 12:00 : `git diff --name-only -- ops tests/Feature/Deploy tests/Load | wc -l` → 8, et `tar --force-local -df <scratchpad>/overlay-s1.tar` dans le dépôt : identique à l'arbre de travail) : rien à rattraper. Le filtre de chemin et le contrôle `wc -l` valent aussi pour la phase 2.
- **Sur le VPS Plesk :** aucune commande. La correction arrive par le dépôt (commit sur `main`, workflows passés en 8.4 et verts, branche `deploy` reconstruite) **avant** le premier « Déployer » ; root recopie les gabarits depuis `/var/www/vhosts/<DOMAINE>/tripleframes`. Sur le serveur : composant PHP 8.4 de Plesk et son extension `imagick` (paquet `plesk-php84-imagick`, nom à confirmer), et non 8.3.

### Étape 1.2 — Paquet `age` (chiffrement des sauvegardes)

- **But** : poser l'outil de chiffrement du tier chaud (`100` § 13.4), utilisé en phase 3. Seul paquet installé par la répétition.
- **Commandes** — [root] :

```bash
NEEDRESTART_MODE=l DEBIAN_FRONTEND=noninteractive apt-get install --no-install-recommends -y age
command -v age age-keygen; age --version
for u in nginx php8.1-fpm php8.3-fpm php8.4-fpm mysql redis-server; do printf '%s %s\n' $u "$(systemctl show -p MainPID --value $u)"; done
```

- **Attendu et vérification** : `age 1.0.0-1ubuntu0.1` installé (1 paquet, 0 mis à jour) ; `needrestart` en mode liste n'a rien redémarré (PID inchangés : 973, 735, 736, 737, 870, 743).
- **Sur le VPS Plesk :** même commande, en root. `NEEDRESTART_MODE=l` est **obligatoire** sur une machine qui sert des voisins : sans lui, `needrestart` peut redémarrer des services après l'installation. Si le relevé montre un système RHEL/Alma : `dnf install age` (EPEL).

### Étape 1.3 — Utilisateur d'abonnement et arborescence

- **But** : l'équivalent de l'abonnement Plesk : un utilisateur système dédié, jamais root, son espace web, ses racines privées **hors du chemin de déploiement** (`FRAMES_DISK_ROOT`, `BACKUP_SNAPSHOT_DIR`), ses journaux, son dépôt Git.
- **Commandes** — [root] :

```bash
id tripleframes 2>/dev/null && echo "tripleframes existe déjà : arrêt"
install -d -o root -g root -m 0755 /var/www/vhosts /var/www/vhosts/system
useradd --create-home --home-dir /var/www/vhosts/tripleframes-prod.test --shell /bin/bash --user-group tripleframes
chgrp vagrant /var/www/vhosts/tripleframes-prod.test
chmod 0710 /var/www/vhosts/tripleframes-prod.test
H=/var/www/vhosts/tripleframes-prod.test
install -d -o tripleframes -g tripleframes -m 0700 "$H/private" "$H/private/tripleframes" \
    "$H/private/tripleframes/frames" "$H/private/tripleframes/snapshots" \
    "$H/git" "$H/incoming" "$H/.config" "$H/.config/tripleframes"
install -d -o tripleframes -g tripleframes -m 0755 "$H/tripleframes"
install -d -o root -g root -m 0755 /var/www/vhosts/system/tripleframes-prod.test
install -d -o root -g tripleframes -m 0750 /var/www/vhosts/system/tripleframes-prod.test/logs
ln -s /var/www/vhosts/system/tripleframes-prod.test/logs "$H/logs"
sudo -u tripleframes -H git init -q --bare --initial-branch=deploy "$H/git/tripleframes.git"
```

Vérifications :

```bash
id tripleframes; getent passwd tripleframes; passwd -S tripleframes
ls -la "$H" "$H/private" "$H/private/tripleframes" /var/www/vhosts/system/tripleframes-prod.test
sudo -u tripleframes -H bash -lc 'echo HOME=$HOME; command -v mysqldump git'
stat -c '%A %U:%G' "$H"
sudo -u www-data ls "$H/tripleframes"   # Permission denied attendu
```

- **Attendu et vérification** : `uid=1001(tripleframes) gid=1001(tripleframes)`, shell `/bin/bash`, mot de passe verrouillé (`L` : accès par `sudo -u` seulement) ; HOME `drwx--x--- tripleframes:vagrant` ; `www-data` → `Permission denied` ; `private/`, `private/tripleframes/{frames,snapshots}`, `git/`, `incoming/`, `.config/tripleframes/` en `drwx------ tripleframes` ; `tripleframes/` (chemin de déploiement, vide) en `0755` ; `logs` → `/var/www/vhosts/system/tripleframes-prod.test/logs` (`root:tripleframes 0750`) ; `HOME` défini, `mysqldump` et `git` accessibles. Garde de préfixe : `…/private/tripleframes/frames` ne commence pas par `…/tripleframes` (chemin de déploiement).
- **Propre à Homestead** : HOME en `tripleframes:vagrant 0710` : `vagrant`, utilisateur et groupe des processus nginx de Homestead, tient le rôle de `psaserv` (seul le groupe du serveur web traverse). Résidu : les pools `php8.1-fpm` et `php8.3-fpm` du porteur tournent aussi sous `vagrant` et traversent donc eux aussi (sur Plesk, les pools des autres abonnements tournent sous leur propre utilisateur, hors de `psaserv`) ; `incoming/` ne sert qu'à recevoir les *bundles* Git (simulation de la forge) ; le dépôt nu `git/tripleframes.git` simule le dépôt de Plesk Git.
- **Correction du contrôle** (11:59 UTC) : HOME posé en `0711` à 11:32. Tout utilisateur local traversait jusqu'au chemin de déploiement, y compris `www-data`, qui fait tourner le pool `php8.4-fpm` du porteur : après `optimize` (phase 2), `bootstrap/cache/config.php`, créé en `0664` par l'umask `0002` de `tripleframes`, lui aurait livré `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD` et `REVERB_APP_SECRET`. Rattrapage, [root] : `chgrp vagrant /var/www/vhosts/tripleframes-prod.test && chmod 0710 /var/www/vhosts/tripleframes-prod.test`. Vérifié : `drwx--x--- tripleframes:vagrant` ; `sudo -u www-data ls …/tripleframes` et `sudo -u nobody ls …/tripleframes` → `Permission denied` ; `tripleframes` et `vagrant` traversent ; `https://tripleframes-prod.test/` toujours `HTTP/2 404` avec `strict-transport-security: max-age=300`.
- **Sur le VPS Plesk :** Sites Web et domaines › Ajouter un abonnement `<DOMAINE>`, utilisateur système dédié (nom relevé → `__TF_SUBSCRIPTION_USER__`, groupe `psacln` → `__TF_SUBSCRIPTION_GROUP__`), racine du document `tripleframes/public` ; Paramètres d'hébergement › Accès SSH : `/bin/bash` (non chrooté). Plesk crée `/var/www/vhosts/<DOMAINE>` (`utilisateur:psaserv 0710`), `private/`, `logs` (→ `/var/www/vhosts/system/<DOMAINE>/logs`) ; le vérifier au relevé (`stat -c '%A %U:%G' /var/www/vhosts/<DOMAINE>` → `drwx--x--- <utilisateur>:psaserv`) et ne jamais l'ouvrir en `0711`. Racines privées et répertoire du hook, en SSH de l'abonnement (commandes de `ops/mise-en-service.md` étape 2) : `(umask 077 && mkdir -p /var/www/vhosts/<DOMAINE>/private/tripleframes/frames /var/www/vhosts/<DOMAINE>/private/tripleframes/snapshots ~/.config/tripleframes)`. Git : Plesk › Git › dépôt distant (la forge, branche `deploy`), chemin de déploiement `tripleframes/`, mode manuel, sans actions additionnelles ; ni `incoming/` ni dépôt nu à la main.

### Étape 1.4 — PHP de l'abonnement par chemin absolu

- **But** : que hook, unités et planificateur invoquent `/opt/plesk/php/8.4/bin/php` **tels qu'écrits dans le dépôt**.
- **Commandes** — [root] (**propre à Homestead** : seul geste « faux Plesk » de la répétition) :

```bash
install -d -o root -g root -m 0755 /opt/plesk /opt/plesk/php /opt/plesk/php/8.4 /opt/plesk/php/8.4/bin
ln -s /usr/bin/php8.4 /opt/plesk/php/8.4/bin/php
readlink -f /opt/plesk/php/8.4/bin/php; /opt/plesk/php/8.4/bin/php -v | head -1
sudo -u tripleframes -H /opt/plesk/php/8.4/bin/php -r 'echo ini_get("memory_limit"), " ", ini_get("date.timezone"), " ", extension_loaded("imagick") ? "imagick" : "-", " ", extension_loaded("pcntl") ? "pcntl" : "-", PHP_EOL;'
```

- **Attendu et vérification** : `/usr/bin/php8.4`, `PHP 8.4.16 (cli)` ; `-1 UTC imagick pcntl` (`memory_limit` du CLI illimité : les workers le fixent par `PHP_ARGS`).
- **Sur le VPS Plesk :** rien à créer : `/opt/plesk/php/8.4/bin/php` existe dès que le composant PHP 8.4 est installé (Outils et paramètres › Mises à jour › Ajouter/Supprimer des composants, ou `plesk installer add --components php8.4`). Vérifier `/opt/plesk/php/8.4/bin/php -m | grep -E 'imagick|pdo_mysql|mbstring|openssl|sodium|intl|pcntl'` et noter son `memory_limit` (`PHP_ARGS` des workers).

### Étape 1.5 — Base MySQL dédiée et utilisateur limité à cette base

- **But** : base `tripleframes_prod`, utilisateur aux droits limités à elle seule (sans `PROCESS` : `backup:snapshot` tient avec `--no-tablespaces`), mot de passe généré sur la machine, jamais en argument d'une commande.
- **Commandes** — [root] :

```bash
umask 077
install -d -o root -g root -m 0700 /root/tripleframes-secrets
test ! -e /root/tripleframes-secrets/socle.env
DB_PASSWORD="$(openssl rand -hex 24)"
printf 'DB_PASSWORD=%s\n' "$DB_PASSWORD" > /root/tripleframes-secrets/socle.env
printf "CREATE DATABASE tripleframes_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER 'tripleframes_prod'@'localhost' IDENTIFIED BY '%s';\nGRANT ALL PRIVILEGES ON tripleframes_prod.* TO 'tripleframes_prod'@'localhost';\n" "$DB_PASSWORD" | mysql
unset DB_PASSWORD
```

Vérifications (le mot de passe passe par `MYSQL_PWD`, jamais par la ligne de commande ; `--no-defaults` écarte le `~/.my.cnf` de root, voir « Impasses ») :

```bash
mysql -e "SHOW GRANTS FOR 'tripleframes_prod'@'localhost'; SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='tripleframes_prod';"
F=/root/tripleframes-secrets/socle.env
MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' "$F")" mysql --no-defaults --protocol=socket -u tripleframes_prod -N -e "SELECT CURRENT_USER(), DATABASE(); SHOW DATABASES;" tripleframes_prod
MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' "$F")" mysql --no-defaults --protocol=socket -u tripleframes_prod -N -e "SELECT COUNT(*) FROM tripleframes.migrations;"
DBP="$(sed -n 's/^DB_PASSWORD=//p' "$F")" sudo --preserve-env=DBP -u tripleframes /opt/plesk/php/8.4/bin/php -r '$p=new PDO("mysql:host=localhost;dbname=tripleframes_prod", "tripleframes_prod", getenv("DBP")); echo $p->query("SELECT CONCAT(CURRENT_USER(), \" \", @@version, \" \", @@session.time_zone)")->fetchColumn(), PHP_EOL;'
```

- **Attendu et vérification** : `GRANT USAGE ON *.*` + `GRANT ALL PRIVILEGES ON tripleframes_prod.*` et rien d'autre ; base en `utf8mb4` / `utf8mb4_unicode_ci` (défaut de `config/database.php`) ; l'utilisateur ne voit que `tripleframes_prod` (et les deux schémas système) ; lecture de la base de dev **refusée** (`ERROR 1142 … SELECT command denied`) ; PDO par le socket (`DB_HOST=localhost`) : `tripleframes_prod@localhost 8.0.35… SYSTEM`.
- **Secret** : `<DB_PASSWORD>` n'existe que dans `/root/tripleframes-secrets/socle.env` (`root 0600`, répertoire `0700`), d'où la phase 2 le recopie dans le `.env` ; ce fichier de transit est supprimé une fois le `.env` écrit.
- **Écart** : sur Homestead, l'extension d'authentification par défaut est `mysql_native_password` ; un MySQL 8 standard crée l'utilisateur en `caching_sha2_password`, que `pdo_mysql` de PHP 8.4 gère.
- **Sur le VPS Plesk :** Sites Web et domaines › `<DOMAINE>` › Bases de données › Ajouter une base : nom et utilisateur (Plesk peut imposer un préfixe : noter les noms réels pour `DB_DATABASE`/`DB_USERNAME`), mot de passe par le bouton « Générer », **accès local seulement** (pas de connexions distantes). Pas de fichier de transit : le mot de passe est collé directement dans le `.env` (étape 2 de la mise en service). Vérifier ensuite, en SSH de l'abonnement, la connexion PDO ci-dessus (`DB_HOST=localhost` par le socket, sinon `127.0.0.1` ; le noter au relevé) et le refus sur une autre base.

### Étape 1.6 — Instance Redis dédiée

- **But** : `100` § 10.3 : instance propre au jeu, en boucle locale, mot de passe, commandes destructrices renommées, `noeviction`, `appendonly`, plafonnée par cgroup. Le Redis partagé (6379, `/etc/redis/`) n'est jamais touché.
- **Commandes** — [root], ouverture puis a) de l'étape 4 de `ops/mise-en-service.md`, depuis le checkout de référence (étape 1.1) :

```bash
cd /home/vagrant/tripleframes-repetition/tests-s1
ss -ltn | grep -qE ':(6390|8090)\b' && echo "port occupé : arrêt" || echo "6390 et 8090 libres"
useradd --system --user-group --no-create-home --home-dir /var/lib/tripleframes-redis --shell /usr/sbin/nologin tfredis
install -d -o root -g root -m 0755 /etc/tripleframes
install -o root -g tfredis -m 0640 ops/redis/tripleframes-redis.conf /etc/tripleframes/tripleframes-redis.conf
sed -i 's/__TF_REDIS_PORT__/6390/' /etc/tripleframes/tripleframes-redis.conf
umask 077
REDIS_PASSWORD="$(openssl rand -hex 32)"
printf 'REDIS_PASSWORD=%s\n' "$REDIS_PASSWORD" >> /root/tripleframes-secrets/socle.env
TF_REDIS_PASSWORD="$REDIS_PASSWORD" awk '{ gsub(/__TF_REDIS_PASSWORD__/, ENVIRON["TF_REDIS_PASSWORD"]); print }' \
    /etc/tripleframes/tripleframes-redis.conf > /etc/tripleframes/tripleframes-redis.conf.new
unset REDIS_PASSWORD
install -o root -g tfredis -m 0640 /etc/tripleframes/tripleframes-redis.conf.new /etc/tripleframes/tripleframes-redis.conf
rm -f /etc/tripleframes/tripleframes-redis.conf.new
grep -c '^requirepass [0-9a-f]\{64\}$' /etc/tripleframes/tripleframes-redis.conf
install -o root -g root -m 0644 ops/systemd/tripleframes-redis.service /etc/systemd/system/tripleframes-redis.service
systemd-analyze verify /etc/systemd/system/tripleframes-redis.service && echo "verify OK"
systemctl daemon-reload && systemctl enable --now tripleframes-redis
systemctl show tripleframes-redis -p MemoryMax -p CPUWeight -p User -p ActiveState -p SubState
```

Vérifications (bloc de `ops/mise-en-service.md`, mot de passe lu dans la configuration par root, jamais affiché) :

```bash
redis-cli -p 6390 ping
export REDISCLI_AUTH="$(awk '/^requirepass /{print $2}' /etc/tripleframes/tripleframes-redis.conf)"
redis-cli -p 6390 ping
redis-cli -p 6390 flushall
redis-cli -p 6390 flushdb
redis-cli -p 6390 keys '*'
redis-cli -p 6390 config get maxmemory
redis-cli -p 6390 debug object x
redis-cli -p 6390 info persistence | grep aof_enabled
redis-cli -p 6390 info memory | grep -E '^maxmemory:|^maxmemory_policy'
redis-cli -p 6390 info server | grep -E 'redis_version|tcp_port|config_file'
unset REDISCLI_AUTH
ss -ltnp | grep -E ':6390\b'
redis-cli -h 192.168.10.10 -p 6390 ping
journalctl -u tripleframes-redis --no-pager -o cat | grep -E 'WARNING|Ready'
ls -la /var/lib/tripleframes-redis
redis-cli -p 6379 info server | grep process_id; redis-cli -p 6379 info keyspace
```

- **Attendu et vérification** (obtenu) : sans mot de passe `NOAUTH Authentication required` ; avec, `PONG` ; `flushall`, `flushdb`, `keys`, `config`, `debug` → `ERR unknown command` ; `aof_enabled:1` ; `maxmemory:268435456`, `maxmemory_policy:noeviction` ; Redis 6.0.16, port 6390, `config_file:/etc/tripleframes/tripleframes-redis.conf` ; écoute `127.0.0.1:6390` seulement, refus sur `192.168.10.10:6390` ; unité `active (running)`, `Status: "Ready to accept connections"`, `User=tfredis`, `MemoryMax=335544320` (320 Mo), `CPUWeight=80` ; `/var/lib/tripleframes-redis` en `0700 tfredis` avec `appendonly.aof` ; Redis partagé : même PID (743), mêmes 22 clés.
- **Avertissements au démarrage, notés sans action** : `vm.overcommit_memory is set to 0` (réglage du noyau pour toute la machine : aucune modification sans décision du porteur) ; `supervised by systemd - you MUST set appropriate values for TimeoutStartSec and TimeoutStopSec` (Redis 6 l'écrit toujours ; les délais par défaut de systemd, 90 s, suffisent pour un AOF de 256 Mo).
- **Sur le VPS Plesk :** identique, en root, `cd /var/www/vhosts/<DOMAINE>/tripleframes` (checkout de déploiement). Si `redis-server` est absent : `NEEDRESTART_MODE=l apt-get install --no-install-recommends -y redis-server`, puis `systemctl disable --now redis-server` **seulement si** aucun voisin ne l'utilisait au relevé. Port relevé libre (≠ 6379, ≠ port de Reverb). Mot de passe : `openssl rand -hex 32` dans le terminal, collé à la main (`nano /etc/tripleframes/tripleframes-redis.conf`, ligne `requirepass`) puis dans `REDIS_PASSWORD` du `.env` ; pas de fichier de transit.

### Étape 1.7 — Unités des workers et de Reverb (installées, ni activées ni démarrées)

- **But** : `100` § 10.4 et § 10.5 : deux workers (`game`, `default`) et Reverb sous l'utilisateur d'abonnement, PHP par chemin absolu, plafonds de cgroup, `Restart=always`.
- **Commandes** — [root], b) de l'étape 4 (boucle en tête de `ops/systemd/tripleframes-worker@.service`) :

```bash
cd /home/vagrant/tripleframes-repetition/tests-s1
install -d -o root -g root -m 0755 /etc/tripleframes
install -o root -g root -m 0644 "ops/systemd/tripleframes-worker@.service" \
    "/etc/systemd/system/tripleframes-worker@.service"
for i in game default; do
    install -o root -g root -m 0644 "ops/systemd/worker-${i}.env" \
        "/etc/tripleframes/worker-${i}.env"
    install -D -o root -g root -m 0644 \
        "ops/systemd/tripleframes-worker@${i}.service.d/limits.conf" \
        "/etc/systemd/system/tripleframes-worker@${i}.service.d/limits.conf"
done
install -o root -g root -m 0644 ops/systemd/tripleframes-reverb.service \
    /etc/systemd/system/tripleframes-reverb.service
sed -i -e 's#__TF_SUBSCRIPTION_USER__#tripleframes#' \
       -e 's#__TF_SUBSCRIPTION_GROUP__#tripleframes#' \
       -e 's#__TF_DEPLOY_PATH__#/var/www/vhosts/tripleframes-prod.test/tripleframes#' \
    "/etc/systemd/system/tripleframes-worker@.service" /etc/systemd/system/tripleframes-reverb.service
systemctl daemon-reload
for u in tripleframes-worker@game.service tripleframes-worker@default.service tripleframes-reverb.service; do
    printf '%s : ' "$u"; systemd-analyze verify "$u" && echo "verify OK"
done
systemctl show tripleframes-worker@game tripleframes-worker@default tripleframes-reverb -p Id -p UnitFileState -p ActiveState -p MemoryMax -p CPUWeight -p User -p Nice -p LimitNOFILE -p Restart
```

- **Attendu et vérification** : `verify OK` pour les trois ; `disabled`, `inactive` ; `User=tripleframes`, `Restart=always` ; worker `game` : `MemoryMax=201326592` (192 Mo), `CPUWeight=80`, `Nice=5` ; worker `default` : 512 Mo, `CPUWeight=20`, `Nice=5` ; Reverb : 256 Mo, `CPUWeight=80`, `LimitNOFILE=4096`. `ExecStart` : `/opt/plesk/php/8.4/bin/php $PHP_ARGS artisan queue:work redis --queue=%i $WORKER_ARGS` et `/opt/plesk/php/8.4/bin/php artisan reverb:start`.
- **Écart** : plafonds des gabarits gardés tels quels alors que la VM a 3,9 Go (sous le seuil de 4 Go) : ce sont des plafonds, et la séance de charge de la VM ne décide rien. L'activation (`systemctl enable`) est jouée en phase 2, juste après le `.env` complet, `composer install` et `migrate` (position de l'étape 4 b) du VPS) ; le démarrage, après `optimize`.
- **Correction du contrôle** (11:59 UTC) : les trois unités avaient été activées à 11:33. Sur un `WorkingDirectory` vide, avec `Restart=always` et `StartLimitIntervalSec=0`, tout redémarrage de la VM de développement du porteur les aurait fait boucler toutes les 2 s, puis démarrer avant `optimize` dès l'arrivée de `vendor/` ; le runbook ne compensait que par une consigne (« ne pas redémarrer la VM »), désormais retirée. Rattrapage, [root] : `systemctl disable tripleframes-worker@game tripleframes-worker@default tripleframes-reverb` → trois `Removed /etc/systemd/system/multi-user.target.wants/…`. Vérifié : `UnitFileState=disabled`, `ActiveState=inactive`, `systemd-analyze verify` toujours OK pour les trois ; seules `tripleframes-php-fpm` et `tripleframes-redis` restent dans `multi-user.target.wants/`.
- **Sur le VPS Plesk :** identique, en root, depuis `/var/www/vhosts/<DOMAINE>/tripleframes`, **suivi** de `systemctl enable tripleframes-worker@game tripleframes-worker@default tripleframes-reverb` (étape 4 b) : sur le VPS, cette étape vient après le `.env` (étape 2) et `migrate` (étape 3), l'activation y est donc sûre ; démarrage à l'étape 5, après `optimize`. Utilisateur et groupe = ceux de l'abonnement (`psacln`), chemin `/var/www/vhosts/<DOMAINE>/tripleframes` — idéalement déjà écrits dans le dépôt par le commit « gabarits ajustés au relevé » (`ops/mise-en-service.md` § 1), auquel cas le `sed` ne remplace rien. Plafonds revus à la baisse **avant** la séance de charge si le VPS a moins de 4 Go.

### Étape 1.8 — PHP-FPM : pool dédié de l'abonnement

- **But** : `100` § 10.6 et `ops/plesk/settings.md` § 3 : pool sous l'utilisateur d'abonnement, `pm.max_children` borné (12), statut du pool jamais publié, `open_basedir` de Plesk plus `/proc/meminfo` et `/proc/cpuinfo`.
- **Commandes** — [root] (**propre à Homestead** : un master PHP-FPM dédié, pour ne jamais recharger `php8.4-fpm`, qui sert les sites du porteur ; le socket reprend le chemin de Plesk) :

```bash
install -d -o root -g root -m 0755 /etc/tripleframes/php-fpm /etc/tripleframes/php-fpm/pool.d

cat > /etc/tripleframes/php-fpm/php-fpm.conf <<'EOF'
; TripleFrames — master PHP-FPM 8.4 dédié (répétition Homestead seulement ;
; sur le VPS, c'est le pool de l'abonnement Plesk, réglé dans l'interface).
[global]
pid = /run/tripleframes-php-fpm/php-fpm.pid
error_log = syslog
syslog.ident = tripleframes-php-fpm
log_level = notice
daemonize = no
include = /etc/tripleframes/php-fpm/pool.d/*.conf
EOF

cat > /etc/tripleframes/php-fpm/pool.d/tripleframes.conf <<'EOF'
; Pool de l'abonnement : valeurs de ops/plesk/settings.md § 3.
[tripleframes]
user = tripleframes
group = tripleframes
listen = /var/www/vhosts/system/tripleframes-prod.test/php-fpm.sock
listen.owner = root
listen.group = vagrant
listen.mode = 0660
pm = ondemand
pm.max_children = 12
pm.max_requests = 500
pm.process_idle_timeout = 10s
pm.status_path = /fpm-status
chdir = /
catch_workers_output = yes
decorate_workers_output = no
php_admin_value[upload_max_filesize] = 2M
php_admin_value[post_max_size] = 8M
php_admin_value[memory_limit] = 128M
php_admin_value[date.timezone] = UTC
php_admin_flag[opcache.validate_timestamps] = on
php_admin_value[open_basedir] = /var/www/vhosts/tripleframes-prod.test/:/tmp/:/proc/meminfo:/proc/cpuinfo
EOF
chmod 0644 /etc/tripleframes/php-fpm/php-fpm.conf /etc/tripleframes/php-fpm/pool.d/tripleframes.conf

cat > /etc/systemd/system/tripleframes-php-fpm.service <<'EOF'
# TripleFrames — master PHP-FPM 8.4 dédié (répétition Homestead seulement).
# Sur le VPS : aucun fichier, le pool de l'abonnement est géré par Plesk.
[Unit]
Description=TripleFrames - PHP-FPM 8.4 dédié (répétition)
After=network.target

[Service]
Type=notify
ExecStart=/usr/sbin/php-fpm8.4 --nodaemonize --fpm-config /etc/tripleframes/php-fpm/php-fpm.conf
ExecReload=/bin/kill -USR2 $MAINPID
RuntimeDirectory=tripleframes-php-fpm
RuntimeDirectoryMode=0755
Restart=on-failure

[Install]
WantedBy=multi-user.target
EOF
chmod 0644 /etc/systemd/system/tripleframes-php-fpm.service

php-fpm8.4 -t -y /etc/tripleframes/php-fpm/php-fpm.conf
systemd-analyze verify /etc/systemd/system/tripleframes-php-fpm.service && echo "verify OK"
systemctl daemon-reload
systemctl enable --now tripleframes-php-fpm
```

Vérifications : statut du pool lu en root **sur le socket** (commande de `ops/plesk/settings.md` § 3), réglages effectifs par un script jetable placé hors du chemin de déploiement, masters existants inchangés :

```bash
SCRIPT_NAME=/fpm-status SCRIPT_FILENAME=/fpm-status REQUEST_METHOD=GET cgi-fcgi -bind -connect /var/www/vhosts/system/tripleframes-prod.test/php-fpm.sock | grep -E '^(pool|process manager|max children|listen queue|idle|active)'
P=/var/www/vhosts/tripleframes-prod.test/sonde-fpm.php
cat > "$P" <<'PHP'
<?php
echo 'user=', posix_getpwuid(posix_geteuid())['name'], PHP_EOL;
foreach (['memory_limit', 'upload_max_filesize', 'post_max_size', 'date.timezone', 'open_basedir', 'opcache.validate_timestamps'] as $k) {
    echo $k, '=', ini_get($k), PHP_EOL;
}
echo 'meminfo=', is_readable('/proc/meminfo') ? 'lisible' : 'illisible', PHP_EOL;
echo 'cpuinfo=', substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor'), ' vCPU', PHP_EOL;
echo 'hors_basedir=', @file_get_contents('/etc/hostname') === false ? 'refusé' : 'LU', PHP_EOL;
echo 'imagick=', extension_loaded('imagick') ? 'oui' : 'non', PHP_EOL;
PHP
chown tripleframes:tripleframes "$P"; chmod 0644 "$P"
SCRIPT_NAME=/sonde-fpm.php SCRIPT_FILENAME="$P" REQUEST_METHOD=GET cgi-fcgi -bind -connect /var/www/vhosts/system/tripleframes-prod.test/php-fpm.sock | tail -n +3
rm -f "$P"
for u in php8.1-fpm php8.3-fpm php8.4-fpm; do printf '%s %s\n' $u "$(systemctl show -p MainPID --value $u)"; done
```

- **Attendu et vérification** (obtenu) : `configuration file … test is successful`, `verify OK`, unité `active (running)`, `Ready to handle connections` ; socket `srw-rw---- root vagrant` ; statut : `pool: tripleframes`, `process manager: ondemand`, `listen queue: 0` ; script : `user=tripleframes`, `memory_limit=128M`, `upload_max_filesize=2M`, `post_max_size=8M`, `date.timezone=UTC`, `open_basedir=…`, `opcache.validate_timestamps=1`, `meminfo=lisible`, `cpuinfo=2 vCPU`, `hors_basedir=refusé`, `imagick=oui` ; masters existants : PID 735, 736, 737 inchangés.
- **Écart** : socket placé comme chez Plesk (`/var/www/vhosts/system/<hôte>/php-fpm.sock`) plutôt que sous `/run/` (plan § 2.4), pour que la commande `cgi-fcgi` de `ops/plesk/settings.md` § 3 se rejoue telle quelle. `listen.group = vagrant` : groupe des processus nginx de Homestead (`psaserv` sur Plesk).
- **Sur le VPS Plesk :** aucun fichier, aucune unité. Sites Web et domaines › `<DOMAINE>` › Paramètres PHP : version **8.4**, « Application FPM servie par nginx » ; `pm`, `pm.max_children`, `pm.max_requests`, `upload_max_filesize`, `post_max_size`, `opcache.validate_timestamps`, `date.timezone`, `open_basedir` (ajouter `:/proc/meminfo:/proc/cpuinfo`) selon `ops/plesk/settings.md` § 3 ; `pm.process_idle_timeout = 10s` et `pm.status_path = /fpm-status` dans la section `[php-fpm-pool-settings]` des directives PHP supplémentaires. Vérification : la même commande `cgi-fcgi` sur `/var/www/vhosts/system/<DOMAINE>/php-fpm.sock` (paquet `libfcgi-bin`) ; le script de sonde peut être rejoué tel quel, hors du chemin de déploiement.

### Étape 1.9 — Certificat TLS

- **But** : HTTPS pour `tripleframes-prod.test` (prérequis de `SESSION_SECURE_COOKIE`, de HSTS et du `wss`).
- **Commandes** — [root] (**propre à Homestead** : certificat de 90 jours signé par la CA locale de Homestead, sans écrire d'état à côté de la CA — `-set_serial` aléatoire, jamais `-CAcreateserial` ni `openssl ca`) :

```bash
head -1 /etc/ssl/certs/ca.homestead.homestead.key | grep -q ENCRYPTED && echo "clé de CA chiffrée : arrêt"
install -d -o root -g root -m 0755 /etc/tripleframes/tls
cd /etc/tripleframes/tls
(umask 077 && openssl req -new -newkey rsa:2048 -nodes \
    -keyout tripleframes-prod.test.key \
    -subj "/CN=tripleframes-prod.test" \
    -out tripleframes-prod.test.csr)
printf '%s\n' 'subjectAltName=DNS:tripleframes-prod.test' \
    'basicConstraints=critical,CA:FALSE' \
    'keyUsage=critical,digitalSignature,keyEncipherment' \
    'extendedKeyUsage=serverAuth' > tripleframes-prod.test.ext
openssl x509 -req -in tripleframes-prod.test.csr \
    -CA /etc/ssl/certs/ca.homestead.homestead.crt \
    -CAkey /etc/ssl/certs/ca.homestead.homestead.key \
    -set_serial "0x$(openssl rand -hex 16)" -days 90 -sha256 \
    -extfile tripleframes-prod.test.ext \
    -out tripleframes-prod.test.crt
chmod 0644 tripleframes-prod.test.crt; chmod 0600 tripleframes-prod.test.key
openssl verify -CAfile /etc/ssl/certs/ca.homestead.homestead.crt tripleframes-prod.test.crt
openssl x509 -in tripleframes-prod.test.crt -noout -subject -issuer -enddate -ext subjectAltName
ls /etc/ssl/certs/ | grep -c '\.srl$'
```

- **Attendu et vérification** : `tripleframes-prod.test.crt: OK` ; émetteur `Homestead homestead Root CA`, expiration au 27/12/2026, SAN `DNS:tripleframes-prod.test` ; clé `0600 root` ; aucun fichier `.srl` créé à côté de la CA (`0`).
- **Sur le VPS Plesk :** aucune commande. `<DOMAINE>` › Certificats SSL/TLS › Let's Encrypt : certificat pour `<DOMAINE>` (et `www.<DOMAINE>` si servi), renouvellement automatique ; Paramètres d'hébergement : « Redirection permanente 301 de HTTP vers HTTPS » ; HSTS par l'extension SSL It! : durée la plus courte proposée, **sans** `includeSubDomains` ni `preload` (noter la durée dans `ops/plesk/settings.md` § 1). Rien à faire côté Reverb (§ 10.5).

### Étape 1.10 — Vhost nginx et directives additionnelles

- **But** : `100` § 10.5 et § 10.8 : racine `tripleframes/public`, PHP par le pool de l'abonnement, `/app/` publié vers Reverb en boucle locale (et lui seul : `/apps/` n'est pas publié), aucun cache de page.
- **Commandes** — [root] : directives recopiées depuis le checkout de référence, puis vhost (**propre à Homestead**, écrit à la manière de Plesk en mode « nginx seul », sans `location /`, qui vient des directives), activé par un lien placé **en dernier** dans `sites-enabled/` (`zz-…`) :

```bash
install -d -o root -g root -m 0755 /etc/tripleframes/nginx
cd /home/vagrant/tripleframes-repetition/tests-s1
install -o root -g root -m 0644 ops/nginx/additional-directives.conf /etc/tripleframes/nginx/additional-directives.conf
sed -i 's/__TF_REVERB_PORT__/8090/' /etc/tripleframes/nginx/additional-directives.conf

cat > /etc/tripleframes/nginx/tripleframes-prod.test.conf <<'EOF'
# TripleFrames — vhost de la répétition (propre à Homestead).
# Sur le VPS, Plesk génère ce fichier : seul le contenu de
# additional-directives.conf vient du dépôt (champ « Directives nginx
# supplémentaires »). Aucun « location / » ici : il vient des directives.
server {
    listen 80;
    server_name tripleframes-prod.test;
    access_log /var/www/vhosts/system/tripleframes-prod.test/logs/access_log;
    error_log /var/www/vhosts/system/tripleframes-prod.test/logs/error_log;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name tripleframes-prod.test;

    ssl_certificate /etc/tripleframes/tls/tripleframes-prod.test.crt;
    ssl_certificate_key /etc/tripleframes/tls/tripleframes-prod.test.key;

    # HSTS court, sans includeSubDomains ni preload (J1).
    add_header Strict-Transport-Security "max-age=300" always;

    root /var/www/vhosts/tripleframes-prod.test/tripleframes/public;
    index index.php index.html;
    charset utf-8;
    client_max_body_size 8m;

    access_log /var/www/vhosts/system/tripleframes-prod.test/logs/access_ssl_log;
    error_log /var/www/vhosts/system/tripleframes-prod.test/logs/error_log;

    # Fichiers cachés refusés (sauf .well-known).
    location ~ /\.(?!well-known/) {
        deny all;
    }

    # Service direct des fichiers statiques (option Plesk) : une location à
    # expression régulière, que « ^~ /app/ » des directives doit battre.
    location ~* \.(?:css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|txt)$ {
        try_files $uri =404;
        access_log off;
    }

    # PHP par le pool de l'abonnement.
    location ~ \.php$ {
        try_files $fastcgi_script_name =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/var/www/vhosts/system/tripleframes-prod.test/php-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Directives nginx supplémentaires de l'abonnement (ops/nginx/additional-directives.conf).
    include /etc/tripleframes/nginx/additional-directives.conf;
}
EOF
chmod 0644 /etc/tripleframes/nginx/tripleframes-prod.test.conf

ln -s /etc/tripleframes/nginx/tripleframes-prod.test.conf /etc/nginx/sites-enabled/zz-tripleframes-prod.test
nginx -t && systemctl reload nginx
```

- **Propre à Homestead** : aucun site ne déclare `default_server`, donc le premier `server` lu sur `*:80` et `*:443` est le serveur par défaut (requêtes par IP, par `localhost`, noms inconnus, clients TLS sans SNI) ; or `nginx.conf` inclut `conf.d/*.conf` **avant** `sites-enabled/*`. Le lien est donc nommé `zz-…` pour être inclus **après** les sites du porteur, et jamais placé dans `conf.d/`. Un `vagrant provision` retire ce lien (le fichier reste dans `/etc/tripleframes/nginx/`) : à recréer par la même commande `ln -s`, puis `nginx -t && systemctl reload nginx`.

Vérifications, en [vagrant] sur la VM (aucune modification de `/etc/hosts` : `--resolve`), puis depuis le poste :

```bash
H=tripleframes-prod.test; CA=/etc/ssl/certs/ca.homestead.homestead.crt
R="--resolve $H:80:127.0.0.1 --resolve $H:443:127.0.0.1"
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' $R "http://$H/up"
curl -s -D - -o /dev/null --cacert $CA $R "https://$H/" | grep -iE '^(HTTP|strict-transport)'
curl -s -o /dev/null -w '/app/x.js %{http_code}\n' --cacert $CA $R "https://$H/app/x.js"
curl -s -o /dev/null -w '/apps/x %{http_code}\n' --cacert $CA $R "https://$H/apps/x"
curl -s -o /dev/null -w '/.env %{http_code}\n' --cacert $CA $R "https://$H/.env"
curl -s -o /dev/null -w '/fpm-status %{http_code}\n' --cacert $CA $R "https://$H/fpm-status"
sudo tail -3 /var/www/vhosts/system/$H/logs/error_log
systemctl show -p MainPID --value nginx
# serveur par défaut : inchangé (atomsdle.test), jamais tripleframes-prod.test
curl -s -o /dev/null -m 10 -w 'défaut http %{http_code}\n' http://127.0.0.1/
curl -sk -o /dev/null -m 10 -w 'défaut https %{http_code}\n' https://127.0.0.1/
echo | openssl s_client -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -subject
echo | openssl s_client -connect 127.0.0.1:443 -servername inconnu.test 2>/dev/null | openssl x509 -noout -subject
```

```powershell
curl.exe -s -k -o NUL -w "poste https / : %{http_code}`n" --resolve tripleframes-prod.test:443:192.168.10.10 https://tripleframes-prod.test/
curl.exe -s -k -o NUL -w "poste https IP : %{http_code}`n" https://192.168.10.10/
```

- **Attendu et vérification** (obtenu, avant tout déploiement) : `nginx -t` réussi ; `301 https://tripleframes-prod.test/up` ; `HTTP/2 404` (racine vide) avec `strict-transport-security: max-age=300` ; `/app/x.js` → **502** (proxy vers Reverb, pas encore démarré : la location `^~ /app/` a battu la regex des statiques, journal `connect() failed … upstream`) ; `/apps/x` → 404 (non publié) ; `/.env` → 403 (`access forbidden by rule`) ; `/fpm-status` → 404 (tombera dans l'application après déploiement, jamais publié) ; master nginx inchangé (PID 973, `reload` seulement) ; serveur par défaut : `défaut http 200`, `défaut https 200`, `subject=O = Vagrant, C = UN, CN = atomsdle.test` sans SNI comme pour `inconnu.test` ; depuis le poste : 404 en HTTPS sur `tripleframes-prod.test`, 200 par l'IP (`atomsdle.test`). Les 10 sites existants rendent les mêmes codes qu'au relevé (étape 1.13).
- **Correction du contrôle** (11:59 UTC) : lié à 11:35 depuis `/etc/nginx/conf.d/tripleframes-prod.test.conf`, le vhost était devenu le serveur par défaut de `*:80` et `*:443` à la place d'`atomsdle.test` : `http://127.0.0.1/` → `301 https://127.0.0.1/`, `https://127.0.0.1/` → 404, certificat `CN = tripleframes-prod.test` pour tout client sans SNI ou avec un nom inconnu, `https://192.168.10.10/` → 404 depuis le poste. Après la phase 2, l'application de production aurait répondu à tout `Host` arbitraire. Le relevé 1.0/1.13, qui ne testait les sites que par leur nom, ne le voyait pas (section « serveur par défaut » ajoutée). Rattrapage, [root] : `rm /etc/nginx/conf.d/tripleframes-prod.test.conf && ln -s /etc/tripleframes/nginx/tripleframes-prod.test.conf /etc/nginx/sites-enabled/zz-tripleframes-prod.test && nginx -t && systemctl reload nginx`. Vérifié : défaut `200 200`, `CN = atomsdle.test` (sans SNI et avec `inconnu.test`), `CN = tripleframes-prod.test` avec son propre nom ; `tripleframes-prod.test` : `301 https://tripleframes-prod.test/up`, `HTTP/2 404` avec `strict-transport-security: max-age=300`, `/app/x.js` 502, `/.env` 403 ; depuis le poste : 200 par l'IP, 404 par le nom ; master nginx inchangé (PID 973).
- **Sur le VPS Plesk :** aucun fichier de vhost. `<DOMAINE>` › Paramètres d'Apache et de nginx : mode proxy **désactivé** (nginx seul), service direct des fichiers statiques **activé**, cache nginx **désactivé** ; « Directives nginx supplémentaires » : coller le contenu de `ops/nginx/additional-directives.conf`, port de Reverb remplacé (Plesk refuse un texte invalide ; s'il répond « duplicate location "/" », retirer le seul bloc `location /`). Puis la vérification « langue et cache » (étape 5 de la mise en service) dès que l'application répond. Rien à activer à la main, jamais d'édition d'un fichier de vhost (Plesk les régénère). Plesk garde son propre serveur par défaut (page par défaut de Plesk, ou le site désigné par IP dans Outils et paramètres › Adresses IP) : ne jamais y désigner `<DOMAINE>`. Le relevé avant/après (étapes 1.0 et 1.13) y ajoute `curl -sk https://<IP du VPS>/` et le sujet du certificat servi sans SNI, qui ne doivent pas changer.

### Étape 1.10 bis — Rotation des journaux du vhost (30 jours au plus)

- **But** : `100` § 10.9 : rotation de 30 jours au plus des journaux nginx de l'abonnement, seul lieu des adresses IP ; reprise par l'étape 4 f) de `ops/mise-en-service.md` et `ops/plesk/settings.md` § 5. Les journaux `access_log`, `access_ssl_log` et `error_log` du vhost vivent sous `/var/www/vhosts/system/tripleframes-prod.test/logs/`, hors de `/var/log/nginx`, donc hors du `logrotate` de Homestead (`/etc/logrotate.d/nginx` ne couvre que `/var/log/nginx/*.log`).
- **Commandes** — [root] (**propre à Homestead** : sur le VPS, Plesk fait tourner ces journaux) :

```bash
cat > /etc/logrotate.d/tripleframes-prod-test <<'EOF'
/var/www/vhosts/system/tripleframes-prod.test/logs/*_log {
    daily
    rotate 30
    maxage 30
    compress
    delaycompress
    missingok
    notifempty
    sharedscripts
    postrotate
        [ -s /run/nginx.pid ] && kill -USR1 "$(cat /run/nginx.pid)"
    endscript
}
EOF
chmod 0644 /etc/logrotate.d/tripleframes-prod-test
logrotate -d /etc/logrotate.d/tripleframes-prod-test
logrotate -d -f /etc/logrotate.d/tripleframes-prod-test 2>&1 | grep -E 'rotating log|error'
```

- **Attendu et vérification** (obtenu à 11:59 UTC) : essai à blanc : `rotating pattern: /var/www/vhosts/system/tripleframes-prod.test/logs/*_log  after 1 days (30 rotations)`, les trois journaux considérés (`log does not need rotating` à la première lecture) ; essai forcé à blanc : `rotating log …/error_log, log->rotateCount is 30`, aucune erreur. Le `postrotate` envoie `USR1` au master nginx : réouverture des journaux, sans redémarrage (le `logrotate` de Homestead fait déjà de même chaque jour pour `/var/log/nginx`, par `invoke-rc.d nginx rotate`). Le répertoire `logs` (`root:tripleframes 0750`) n'est pas inscriptible par le groupe : `logrotate` n'exige pas de directive `su`.
- **Correction du contrôle** : étape absente de la phase 1 (journaux créés à 11:35 sans aucune rotation) ; jouée à 11:59 UTC, commandes ci-dessus.
- **Sur le VPS Plesk :** aucun fichier. `<DOMAINE>` › Journaux › Gérer la rotation des journaux : rotation activée, **par temps, quotidienne**, 30 fichiers au plus, compression ; date consignée dans le journal de `ops/plesk/settings.md` § 5 (étape 4 f) de la mise en service). Contrôle quelques jours plus tard, en root : `ls /var/www/vhosts/system/<DOMAINE>/logs/` porte des archives compressées, et `find /var/www/vhosts/system/<DOMAINE>/logs/ -type f -mtime +30` ne rend rien.

### Étape 1.11 — Planificateur : préparé, posé en phase 2

- **But** : `100` § 10.7 : `schedule:run` chaque minute sous l'utilisateur d'abonnement, sortie non notifiée.
- **Commande** (à jouer en phase 2, **juste après** l'écriture complète du `.env` et `composer install`) — [root] :

```bash
printf '%s\n' 'MAILTO=""' '* * * * * /opt/plesk/php/8.4/bin/php /var/www/vhosts/tripleframes-prod.test/tripleframes/artisan schedule:run' | crontab -u tripleframes -
crontab -l -u tripleframes
```

- **Pourquoi pas maintenant** (plan, S10) : `.env.example` porte `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_PORT=6379` et `REDIS_PASSWORD=null`. Un `schedule:run` qui tomberait entre une copie de `.env.example` et son remplissage écrirait battements, verrous et jobs dans le **Redis partagé** du porteur. Posée après D2, la tâche ne peut lire qu'un `.env` complet (port 6390). Relevé à 11:38 : `no crontab for tripleframes`, ni `cron.allow` ni `cron.deny` (cron ouvert à l'utilisateur).
- **Sur le VPS Plesk :** `<DOMAINE>` › Tâches planifiées › Ajouter une tâche : « Exécuter une commande », `/opt/plesk/php/8.4/bin/php /var/www/vhosts/<DOMAINE>/tripleframes/artisan schedule:run`, cron `* * * * *`, notification : **ne pas notifier**. Même contrainte d'ordre : l'étape 4 e) de `ops/mise-en-service.md` vient après l'étape 2 (`.env`).

### Étape 1.12 — Contrôle des gabarits recopiés et isolement réseau

- **But** : § 9 de `ops/mise-en-service.md` (aucun paramètre `__TF_…__` ni `<DOMAINE>` laissé sur le serveur, écart dépôt ↔ serveur relu) et preuve que Redis n'est pas joignable de l'extérieur.
- **Commandes** — [root] :

```bash
grep -rnE '^[^#]*(__TF_|<DOMAINE>)' /etc/tripleframes /etc/systemd/system/tripleframes-* && echo "RESTE DES PARAMÈTRES" || echo "aucun paramètre restant"
cd /home/vagrant/tripleframes-repetition/tests-s1
diff -I '^requirepass ' ops/redis/tripleframes-redis.conf /etc/tripleframes/tripleframes-redis.conf
for f in tripleframes-redis.service tripleframes-reverb.service tripleframes-worker@.service; do
    echo "## $f"; diff "ops/systemd/${f}" "/etc/systemd/system/${f}"
done
for i in game default; do
    echo "## $i"
    diff "ops/systemd/worker-${i}.env" "/etc/tripleframes/worker-${i}.env"
    diff "ops/systemd/tripleframes-worker@${i}.service.d/limits.conf" \
        "/etc/systemd/system/tripleframes-worker@${i}.service.d/limits.conf"
done
echo "## additional-directives.conf"; diff ops/nginx/additional-directives.conf /etc/tripleframes/nginx/additional-directives.conf
```

[poste], PowerShell :

```powershell
Test-NetConnection 192.168.10.10 -Port 6390 -WarningAction SilentlyContinue | Select-Object TcpTestSucceeded
```

- **Attendu et vérification** (obtenu) : « aucun paramètre restant » (le `.env` n'existe pas encore : il entre dans ce contrôle en phase 2) ; seules diffèrent les lignes paramétrées : `port 6390`, `User=`/`Group=tripleframes`, `WorkingDirectory=/var/www/vhosts/tripleframes-prod.test/tripleframes` (unités worker et Reverb), `proxy_pass http://127.0.0.1:8090;` ; unité Redis, fichiers `worker-*.env` et drop-ins `limits.conf` identiques au dépôt ; depuis le poste : `TcpTestSucceeded : False` sur 6390 (443 : `True`). Le port 8090 se vérifie en phase 3, une fois Reverb démarré.
- **Écart** : les gabarits du dépôt ne sont pas encore « ajustés au relevé » (port, utilisateur, groupe, chemin restent des `__TF_…__`) : le `diff` montre donc ces lignes, et non les seules lignes `<DOMAINE>` qu'attend la liste de contrôle. Normal pour une répétition sans commit.
- **Sur le VPS Plesk :** mêmes commandes, `cd /var/www/vhosts/<DOMAINE>/tripleframes`, en ajoutant le `.env` au `grep` (`… /var/www/vhosts/<DOMAINE>/tripleframes/.env`) ; après le commit « gabarits ajustés au relevé », seules diffèrent les lignes où `<DOMAINE>` est remplacé. Les directives nginx se relisent dans l'interface de Plesk (pas de `diff` possible). Isolement : `Test-NetConnection <IP du VPS> -Port <port Redis>` et `-Port <port Reverb>` → `False` ; Plesk › Pare-feu : aucune règle n'ouvre ces ports (étape 4 d).

### Étape 1.13 — Relevé « après » et comparaison

- **But** : prouver qu'aucun service existant n'a été perturbé.
- **Commandes** — [vagrant] : le bloc de l'étape 1.0 **à l'identique**, avec `OUT=apres-socle.txt`, puis :

```bash
cd /home/vagrant/tripleframes-repetition/releves
norm() { grep -vE 'uptime|^20[0-9]{2}-' "$1" | sed '/^== date/,+1d; s/,avg_ttl=[0-9]*//'; }
diff <(norm avant-socle.txt) <(norm apres-socle.txt)
systemctl list-units 'tripleframes-*' --all --no-pager --no-legend
systemctl list-unit-files 'tripleframes-*' --no-pager --no-legend
```

- **Attendu et vérification** (rejoué à 12:01 UTC, après les corrections du contrôle) : seules différences : `zz-tripleframes-prod.test` dans `sites-enabled` et sa ligne `tripleframes-prod.test 301 404` ; la section « serveur par défaut » (`200 200`, `CN = atomsdle.test`), absente du fichier de 11:23 ; `tripleframes_prod` dans `SHOW DATABASES` ; `127.0.0.1:6390` dans les ports (seul port nouveau). PID de nginx, des trois PHP-FPM, de MySQL, de Redis, de supervisor, memcached, beanstalkd, mailpit inchangés ; 10 sites du porteur aux mêmes codes ; Redis 6379 : même PID, 22 clés ; base de dev : 45 tables, 46 migrations ; unités : `tripleframes-php-fpm` et `tripleframes-redis` actives et `enabled`, `tripleframes-worker@game`, `tripleframes-worker@default`, `tripleframes-reverb` inactives et `disabled`.
- **Correction du contrôle** : le fichier `apres-socle.txt` de 11:37 avait été produit par le bloc incomplet de l'étape 1.0 (ni date, ni ports) et comparé par un filtre ad hoc ; il est conservé sous `apres-socle-1137-bloc-incomplet.txt`, et le relevé a été refait avec le bloc corrigé.
- **Sur le VPS Plesk :** même comparaison avec le relevé de l'étape 1.0 : les sites des voisins répondent avec les mêmes codes, le serveur par défaut de l'IP est inchangé (même code, même certificat sans SNI), aucun master PHP-FPM ni MySQL redémarré, seuls ports nouveaux ceux de Redis (et de Reverb une fois démarré), en `127.0.0.1`.

## Phase 2 — Déploiement

Jouée le 28/09/2026 à partir de 12:10 UTC. Équivalent de `ops/mise-en-service.md` étapes 2, 3, 5 (`.env`, dépendances et schéma, démarrage et vérifications), 6 (premier administrateur) et 7 (premier déploiement par le hook), et de `100` § 11.6. Blocs joués comme en phase 1 (`ssh … 'sudo bash -s' < bloc.sh` pour [root], `ssh … 'sudo -u tripleframes -H bash -s' < bloc.sh` pour [abo], précédés de `set -euo pipefail`) ; sur le VPS, taper les lignes une à une, **sans** `set -e`.

**Reporté du socle (contrôle de la phase 1), à jouer dans cet ordre** (joué : point 1 à l'étape 2.1, points 2 et 3 à l'étape 2.8) **:**

1. **Source de l'artefact n° 1 (D1) = `f76969d` + RV-1.** La correction PHP 8.4 (étape 1.1) n'est pas commitée et ne le sera qu'en fin de répétition ; un clone de `develop` porterait `readonly PHP=/opt/plesk/php/8.3/bin/php`, absent de la VM, et le hook échouerait dès son étape 1 (et le contrôle de `ops/mise-en-service.md` § 9 divergerait). [poste], Git Bash, juste après le clone :

   ```bash
   cd C:/Users/Admin/Desktop/groupez/tripleframes
   git diff --name-only -- ops tests/Feature/Deploy tests/Load | wc -l                  # 8
   tar --force-local -df <scratchpad>/vm/overlay-s1.tar && echo "overlay = arbre de travail"
   git clone -q --branch develop --single-branch . <scratchpad>/vm/build/src
   tar --force-local -xf <scratchpad>/vm/overlay-s1.tar -C <scratchpad>/vm/build/src
   git -C <scratchpad>/vm/build/src commit -qam "répétition : PHP 8.4 (RV-1), jamais poussé"
   git -C <scratchpad>/vm/build/src grep -n 'plesk/php/8\.3' -- ops || echo "aucun 8.3 dans ops/"
   ```

   Ce commit reste dans le clone jetable, jamais dans le dépôt du porteur ; le commit `deploy` est composé à partir de lui, et l'étape PHP du « runner » (Wayfinder, dans la VM) part du *bundle* de ce même clone. Workflows (`tests.yml` l. 34 et 82, `tests-mysql.yml` l. 97) et `composer.json` (`"php": "^8.3"`) restent au porteur (écart n° 1). **Sur le VPS Plesk :** rien de tel : la correction est commitée sur `main`, les workflows passés en 8.4 sont verts, et la CI construit `deploy` à partir de ce commit.
2. **Activation des unités** : juste après le `.env` complet, `composer install` et `migrate`, [root] : `systemctl enable tripleframes-worker@game tripleframes-worker@default tripleframes-reverb` (attendu : `UnitFileState=enabled`, `ActiveState=inactive`) ; démarrage (`systemctl start …`) après `optimize` et `lang:hash`, comme l'étape 5 de `ops/mise-en-service.md`.
3. **Planificateur** : étape 1.11, au même moment que l'activation des unités.

### Étape 2.1 — Artefact n° 1 : clone propre du commit source (poste)

- **But** : partir de l'état **commité** de `develop` (jamais de la copie de travail du porteur, où tourne peut-être `composer dev`), plus la correction RV-1 (PHP 8.4), comme le ferait la CI sur un commit de `main`.
- **Commandes** — [poste], Git Bash (`<scratchpad>` = répertoire jetable du poste) :

```bash
cd C:/Users/Admin/Desktop/groupez/tripleframes
git rev-parse --short develop                                                        # f76969d
git diff --name-only -- ops tests/Feature/Deploy tests/Load | wc -l                  # 8
tar --force-local -df <scratchpad>/vm/overlay-s1.tar && echo "overlay = arbre de travail"
mkdir -p <scratchpad>/vm/build
git clone -q --branch develop --single-branch . <scratchpad>/vm/build/src
tar --force-local -xf <scratchpad>/vm/overlay-s1.tar -C <scratchpad>/vm/build/src
git -C <scratchpad>/vm/build/src status --short
git -C <scratchpad>/vm/build/src commit -qam "répétition : PHP 8.4 (RV-1), jamais poussé"
git -C <scratchpad>/vm/build/src grep -n 'plesk/php/8\.3' -- ops || echo "aucun 8.3 dans ops/"
git -C <scratchpad>/vm/build/src remote remove origin
for f in ops/deploy/hook.sh ops/systemd/tripleframes-worker@.service artisan; do
    printf '%s CR=%s\n' $f "$(git -C <scratchpad>/vm/build/src show HEAD:$f | tr -cd '\r' | wc -c)"
done
```

- **Attendu et vérification** (obtenu à 12:10 UTC) : 8 fichiers, overlay identique à l'arbre de travail ; `status` du clone : les 8 fichiers en `M` ; commit `e396899` (parent `f76969d`) ; « aucun 8.3 dans ops/ » ; plus aucun dépôt distant dans le clone (**aucune poussée possible vers le dépôt du porteur**) ; `CR=0` pour le hook, l'unité et `artisan` (`.gitattributes` : `eol=lf` ; un script en CRLF casserait `bash` et le shebang sur le serveur).
- **Écart** : source de l'artefact = `f76969d` + RV-1 (`e396899`, commit du clone seulement). Le filtre de chemin du `git diff` écarte le travail parallèle du porteur (`.gitignore`, `resources/moderation/…`).
- **Sur le VPS Plesk :** rien sur le serveur. C'est la CI : un commit sur `main` (RV-1 commitée, workflows passés en PHP 8.4 et verts) déclenche les jobs `build` puis `artifacts` (`.github/workflows/tests.yml`).

### Étape 2.2 — Étape PHP du « runner » : fichiers Wayfinder (VM, répertoire jetable)

- **But** : le greffon Wayfinder de Vite appelle `php artisan wayfinder:generate --with-form` ; `resources/js/{actions,routes,wayfinder}` ne sont pas suivis par git. Le PHP du poste étant bloqué (voir « Impasses »), l'étape PHP du job `build` (`cp .env.example .env`, `composer install --no-dev`, `key:generate`) se joue dans la VM, sur le même commit source.
- **Commandes** — [poste], Git Bash :

```bash
git -C <scratchpad>/vm/build/src bundle create <scratchpad>/vm/build/source-1.bundle develop
scp -i <clé> <scratchpad>/vm/build/source-1.bundle vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/source-1.bundle
```

Puis [vagrant] :

```bash
R=/home/vagrant/tripleframes-repetition
export COMPOSER_HOME=$R/.composer COMPOSER_CACHE_DIR=$R/.composer/cache
test ! -e $R/ci
git clone -q --branch develop $R/source-1.bundle $R/ci
cd $R/ci
git log --oneline -1
cp .env.example .env
sed -i 's/^REDIS_PORT=6379$/REDIS_PORT=6399/' .env
php8.4 /usr/local/bin/composer install --no-dev --prefer-dist --no-interaction --no-progress
php8.4 artisan key:generate --no-interaction
php8.4 artisan wayfinder:generate --with-form
tar -czf $R/wayfinder-1.tar.gz resources/js/actions resources/js/routes resources/js/wayfinder
```

Retour sur le [poste] :

```bash
scp -i <clé> vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/wayfinder-1.tar.gz <scratchpad>/vm/build/
tar --force-local -xzf <scratchpad>/vm/build/wayfinder-1.tar.gz -C <scratchpad>/vm/build/src
```

- **Attendu et vérification** : `e396899` ; `Application key set successfully` ; `Generated actions …`, `Generated routes …` ; archive de 220 fichiers (124 `actions`, 94 `routes`, 2 `wayfinder`), ignorés par git (`git check-ignore`).
- **Propre à Homestead** : la ligne `sed … REDIS_PORT=6399` : sur un exécuteur de CI, aucun Redis n'existe ; ici, `127.0.0.1:6379` est le Redis partagé du porteur, et un port fermé garantit que rien ne peut y écrire.
- **Sur le VPS Plesk :** rien. C'est le job `build` de la CI (`Prepare Environment`, `Install PHP Dependencies`), puis le greffon Wayfinder pendant `npm run build`.

### Étape 2.3 — Étape Node : `npm ci`, `npm run build`, contrôle du contenu (poste)

- **Commandes** — [poste], Git Bash. Le greffon Wayfinder appelle `php` : un `php.cmd` qui sort en 0 est placé **en tête** du `PATH` (les fichiers sont déjà là, étape 2.2) ; dans le `PATH` de Git Bash, écrire `/c/Users/…` et jamais `C:/…` (le `:` sépare les entrées du `PATH`) :

```bash
mkdir -p <scratchpad>/vm/build/php-stub
printf '@exit /b 0\r\n' > <scratchpad>/vm/build/php-stub/php.cmd
cd <scratchpad>/vm/build/src
npm ci --no-audit --no-fund
export PATH="/c/…/<scratchpad>/vm/build/php-stub:$PATH"
cmd //c "where php"          # php.cmd du répertoire jetable en premier
npm run build
refused="$(find public/build \( -iname '*.php' -o -iname '*.php[0-9]' -o -iname '*.phtml' -o -iname '*.pht' -o -iname '*.phps' -o -iname '*.phar' -o -name '.*' \) -print)"
[ -z "$refused" ] && echo "Verify Build Contents : OK" || echo "REFUSÉS : $refused"
```

- **Attendu et vérification** (12:12 UTC) : `added 266 packages` ; `✓ built in 9.94s` ; `Verify Build Contents : OK` ; `public/build` : 115 fichiers, 2 Mo (`manifest.json`, `fonts-manifest.json`, `assets/`).
- **Sur le VPS Plesk :** rien. Jobs `build` (`npm ci`, `npm run build`, `Upload Build`) et `artifacts` (`Verify Build Contents`). **Jamais** de `npm` ni de `vp build` sur le VPS (CPU des voisins).

### Étape 2.4 — Commit `deploy` (job `artifacts`) et *bundle*

- **But** : reproduire à l'identique le job `artifacts` : index temporaire, arbre du commit source, `.github/` retiré, `public/build` ajouté de force, parent = commit `deploy` précédent (aucun au premier artefact).
- **Commandes** — [poste], Git Bash, dans `<scratchpad>/vm/build/src`. Le premier bloc **se joue comme un script** (`bash -s < artifacts.sh`, lancé dans `<scratchpad>/vm/build/src`), jamais collé dans une session interactive : sur un arbre identique au commit `deploy` précédent, il sort en `exit 0` sans rien publier, comme le job `Publish Deploy Commit` de la CI (`.github/workflows/tests.yml`), et `exit` fermerait un shell interactif.

```bash
set -euo pipefail
SOURCE_SHA="$(git rev-parse develop)"
export GIT_AUTHOR_NAME="artifacts (répétition)" GIT_AUTHOR_EMAIL="artifacts@tripleframes-prod.test"
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME" GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
export GIT_INDEX_FILE="$(pwd)/../deploy.index"
rm -f "$GIT_INDEX_FILE"
git read-tree "$SOURCE_SHA"
git rm -r --cached --quiet --ignore-unmatch -- .github
git add --force -- public/build
git ls-files --error-unmatch -- public/build/manifest.json > /dev/null
tree="$(git write-tree)"
unset GIT_INDEX_FILE
parent_args=()
if parent="$(git rev-parse -q --verify refs/heads/deploy)"; then
    if [ "$(git rev-parse "$parent^{tree}")" = "$tree" ]; then
        echo "Arbre identique au commit deploy $parent : rien à publier."
        exit 0
    fi
    parent_args=(-p "$parent")
else
    echo "Branche deploy absente : premier artefact, sans parent."
fi
commit="$(git commit-tree "$tree" "${parent_args[@]}" -m "deploy: $SOURCE_SHA")"
git update-ref refs/heads/deploy "$commit"
echo "Publié : deploy $commit, source $SOURCE_SHA."
```

Puis, dans le même répertoire :

```bash
git ls-tree -r --name-only deploy | grep -c '^public/build/'                                   # 115
git ls-tree -r --name-only deploy | grep -cE '^(\.github|vendor|node_modules)/|^\.env$'        # 0
git bundle create <scratchpad>/vm/build/deploy-1.bundle deploy
scp -i <clé> <scratchpad>/vm/build/deploy-1.bundle vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/deploy-1.bundle
```

- **Attendu et vérification** : `deploy 0d33814`, message `deploy: e396899…`, sans parent ; 115 fichiers sous `public/build/`, ni `.github/`, ni `vendor/`, ni `node_modules/`, ni `.env` ; *bundle* de 7,6 Mo.
- **Écart** : le *bundle* + `scp` tient lieu de forge (aucune n'est configurée, écart n° 2) ; l'auteur du commit n'est pas `github-actions[bot]`.
- **Correction du contrôle** (28/09, 13:13 UTC, RV-10) : la version d'abord écrite ici n'était ni celle jouée (`d1-artifacts.sh`) ni celle de la CI : sur un arbre identique, elle affichait « rien à publier » puis créait quand même un commit `deploy` vide de différence. Bloc remplacé par le script réellement joué ; rejoué tel quel pour l'artefact n° 4 (étape 2.18) : « Publié : deploy a775555…, source 1716d50… » (parent `e7cdb5c`), puis relancé aussitôt : « Arbre identique au commit deploy a775555… : rien à publier. », code 0, `deploy` inchangé.
- **Sur le VPS Plesk :** rien : le job `artifacts` pousse `deploy` sur la forge.

### Étape 2.5 — « Tirer » et premier « Déployer », sans hook

- **But** : `100` § 11.6, étape 1 : les fichiers arrivent dans le chemin de déploiement, sans `vendor/`, sans `.env`.
- **Commandes** — [vagrant] puis [abo] :

```bash
H=/var/www/vhosts/tripleframes-prod.test
sudo install -o tripleframes -g tripleframes -m 0600 /home/vagrant/tripleframes-repetition/deploy-1.bundle "$H/incoming/deploy-1.bundle"
sudo -u tripleframes -H bash
cd ~
git -C ~/git/tripleframes.git bundle verify -q ~/incoming/deploy-1.bundle && echo "bundle vérifié"
git -C ~/git/tripleframes.git fetch -q ~/incoming/deploy-1.bundle deploy:deploy
git -C ~/git/tripleframes.git log --oneline -1 deploy
git --git-dir=$HOME/git/tripleframes.git --work-tree=$HOME/tripleframes checkout -f deploy
test ! -e ~/tripleframes/vendor && test ! -e ~/tripleframes/.env && echo "ni vendor/ ni .env : attendu"
test -f ~/tripleframes/public/build/manifest.json && echo "public/build présent"
```

- **Attendu et vérification** (12:14 UTC) : `bundle vérifié`, `0d33814 deploy: e396899…`, arbre déposé (fichiers `tripleframes:tripleframes`), « ni vendor/ ni .env », `public/build` présent.
- **Impasse** : `git bundle verify` exige un dépôt (`need a repository to verify a bundle`) et `git -C <dépôt nu> fetch incoming/…` lit le chemin relativement au dépôt : chemins absolus (`~/incoming/…`) et `-C ~/git/tripleframes.git`.
- **Constat** : l'arbre `deploy` embarque tout le dépôt hors `.github/` (`docs/`, `tests/`, `design-test/`, `.superdesign/`, `CLAUDE.md`), hors de la racine du document `public/` : rien n'en est servi.
- **Sur le VPS Plesk :** Plesk › `<DOMAINE>` › Git : « Tirer les mises à jour » (branche `deploy`), puis « Déployer » (mode manuel, **sans** actions additionnelles à ce stade). Vérification en SSH de l'abonnement : `ls /var/www/vhosts/<DOMAINE>/tripleframes` (ni `vendor/` ni `.env`).

### Étape 2.6 — `.env` de production, `APP_KEY`, copie hors machine

- **But** : `100` § 10.10 et § 11.6 étape 2 ; `ops/mise-en-service.md` étape 2 : `.env` en `0600`, hors dépôt, secrets générés sur la machine et jamais affichés ; copie immédiate d'`APP_KEY` hors machine.
- **Commandes** — [poste], transfert du jeton TMDB (lecture seule du `.env` du poste, jamais affiché) :

```bash
cd C:/Users/Admin/Desktop/groupez/tripleframes
grep '^TMDB_API_READ_ACCESS_TOKEN=' .env | tr -d '\r' | ssh -i <clé> vagrant@192.168.10.10 \
    'sudo sh -c "umask 077; test ! -e /root/tripleframes-secrets/tmdb.env && cat > /root/tripleframes-secrets/tmdb.env"'
```

Puis [root], un seul bloc (les valeurs passent par l'environnement d'`awk`, jamais par un argument de processus) :

```bash
umask 077
H=/var/www/vhosts/tripleframes-prod.test
APP=$H/tripleframes
ENVF=$APP/.env
PHP=/opt/plesk/php/8.4/bin/php
test ! -e "$ENVF"
install -d -o root -g root -m 0700 /root/tripleframes-hors-machine
sudo -u tripleframes -H bash -c "umask 077 && cp $APP/.env.example $ENVF"
setenv() {
    TFK="$1" TFV="${!2}" awk 'BEGIN { k = ENVIRON["TFK"]; v = ENVIRON["TFV"]; done = 0 }
        !done && ($0 ~ ("^" k "=") || $0 ~ ("^# " k "=")) { print k "=" v; done = 1; next }
        { print }
        END { if (!done) print k "=" v }' "$ENVF" > "$ENVF.new"
    cat "$ENVF.new" > "$ENVF"
    rm -f "$ENVF.new"
}
APP_KEY="$("$PHP" -r 'echo "base64:".base64_encode(random_bytes(32));')"
REVERB_APP_ID="$(openssl rand -hex 16)"
REVERB_APP_KEY="$(openssl rand -hex 16)"
REVERB_APP_SECRET="$(openssl rand -hex 32)"
OPS_PROBE_TOKEN="$(openssl rand -hex 32)"
DB_PASSWORD="$(sed -n 's/^DB_PASSWORD=//p' /root/tripleframes-secrets/socle.env)"
REDIS_PASSWORD="$(sed -n 's/^REDIS_PASSWORD=//p' /root/tripleframes-secrets/socle.env)"
TMDB_API_READ_ACCESS_TOKEN="$(sed -n 's/^TMDB_API_READ_ACCESS_TOKEN=//p' /root/tripleframes-secrets/tmdb.env)"
test -n "$DB_PASSWORD" && test -n "$REDIS_PASSWORD" && test -n "$TMDB_API_READ_ACCESS_TOKEN"
APP_ENV=production; APP_DEBUG=false; APP_URL=https://tripleframes-prod.test
LOG_STACK=daily; LOG_DAILY_DAYS=14; SITE_INDEXABLE=false
ACCOUNTS_REGISTRATION_OPEN=; ACCOUNTS_PASSKEYS_ENABLED=; LEGAL_CONTACT_EMAIL=
DB_CONNECTION=mysql; DB_HOST=localhost; DB_PORT=3306
DB_DATABASE=tripleframes_prod; DB_USERNAME=tripleframes_prod
SESSION_DRIVER=database; SESSION_SECURE_COOKIE=true
FRAMES_DISK_ROOT=$H/private/tripleframes/frames
BACKUP_SNAPSHOT_DIR=$H/private/tripleframes/snapshots
CACHE_STORE=redis; QUEUE_CONNECTION=redis; REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1; REDIS_PORT=6390; REDIS_QUEUE_RETRY_AFTER=960
BROADCAST_CONNECTION=reverb
REVERB_HOST=127.0.0.1; REVERB_PORT=8090; REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1; REVERB_SERVER_PORT=8090
REVERB_CLIENT_HOST=; REVERB_CLIENT_PORT=; REVERB_CLIENT_SCHEME=
REVERB_ALLOWED_ORIGINS=tripleframes-prod.test; REVERB_MAX_REQUEST_SIZE=524288
DEPLOY_DRAIN_TIMEOUT_MINUTES=; DEPLOY_WINDOW_MINUTES=30; BACKUP_SNAPSHOT_KEEP_DAYS=7
OPS_LOAD_CPU_COUNT=; CURATION_CAPTURE_ENABLED=
for k in APP_ENV APP_KEY APP_DEBUG APP_URL LOG_STACK LOG_DAILY_DAYS SITE_INDEXABLE \
    ACCOUNTS_REGISTRATION_OPEN ACCOUNTS_PASSKEYS_ENABLED LEGAL_CONTACT_EMAIL \
    DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
    SESSION_DRIVER SESSION_SECURE_COOKIE FRAMES_DISK_ROOT BACKUP_SNAPSHOT_DIR \
    CACHE_STORE QUEUE_CONNECTION REDIS_CLIENT REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_QUEUE_RETRY_AFTER \
    BROADCAST_CONNECTION REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET \
    REVERB_HOST REVERB_PORT REVERB_SCHEME REVERB_SERVER_HOST REVERB_SERVER_PORT \
    REVERB_CLIENT_HOST REVERB_CLIENT_PORT REVERB_CLIENT_SCHEME REVERB_ALLOWED_ORIGINS REVERB_MAX_REQUEST_SIZE \
    DEPLOY_DRAIN_TIMEOUT_MINUTES DEPLOY_WINDOW_MINUTES BACKUP_SNAPSHOT_KEEP_DAYS \
    OPS_PROBE_TOKEN OPS_LOAD_CPU_COUNT TMDB_API_READ_ACCESS_TOKEN CURATION_CAPTURE_ENABLED; do
    setenv "$k" "$k"
done
printf 'APP_KEY=%s\n' "$APP_KEY" > /root/tripleframes-hors-machine/app-key.env
unset APP_KEY REVERB_APP_SECRET OPS_PROBE_TOKEN DB_PASSWORD REDIS_PASSWORD TMDB_API_READ_ACCESS_TOKEN
rm -f /root/tripleframes-secrets/socle.env /root/tripleframes-secrets/tmdb.env
rmdir /root/tripleframes-secrets
```

Vérifications [root] (secrets masqués) :

```bash
stat -c '%a %U:%G %n' "$ENVF" /root/tripleframes-hors-machine/app-key.env
grep -vE '^\s*(#|$)' "$ENVF" | sed -E 's/^(APP_KEY|DB_PASSWORD|REDIS_PASSWORD|REVERB_APP_ID|REVERB_APP_KEY|REVERB_APP_SECRET|OPS_PROBE_TOKEN|TMDB_API_READ_ACCESS_TOKEN)=.+/\1=<masqué>/'
grep -cE '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$' "$ENVF"                                        # 1
grep -cE '^(REVERB_APP_SECRET|OPS_PROBE_TOKEN|REDIS_PASSWORD)=[0-9a-f]{64}$' "$ENVF"           # 3
grep -cE '^(REVERB_APP_ID|REVERB_APP_KEY)=[0-9a-f]{32}$' "$ENVF"                               # 2
cmp -s <(grep '^APP_KEY=' "$ENVF") /root/tripleframes-hors-machine/app-key.env && echo "copie hors machine = .env"
diff <(awk '/^requirepass /{print $2}' /etc/tripleframes/tripleframes-redis.conf) <(sed -n 's/^REDIS_PASSWORD=//p' "$ENVF") >/dev/null && echo "REDIS_PASSWORD = requirepass"
grep -rnE '^[^#]*(__TF_|<DOMAINE>)' "$ENVF" && echo "RESTE DES PARAMÈTRES" || echo "aucun paramètre restant dans le .env"
```

- **Attendu et vérification** (12:15 UTC) : `.env` en `600 tripleframes:tripleframes`, copie `600 root:root` ; les lignes du tableau de `ops/mise-en-service.md` étape 2 (dont `APP_ENV=production`, `APP_DEBUG=false`, `SITE_INDEXABLE=false`, `ACCOUNTS_REGISTRATION_OPEN=` et `ACCOUNTS_PASSKEYS_ENABLED=` vides, `REVERB_CLIENT_*` vides tous les trois, `SESSION_SECURE_COOKIE=true` ajoutée en fin de fichier) ; formats `1`, `3`, `2` ; « copie hors machine = .env » ; « REDIS_PASSWORD = requirepass » ; « aucun paramètre restant ». Fichier de transit du socle supprimé.
- **Laissés à la décision du porteur, inchangés** (hors du tableau de § 10.10) : `LOG_LEVEL=debug`, `APP_LOCALE=en`, `MAIL_MAILER=log`. `TMDB_API_KEY` reste vide (le jeton v4 suffit).
- **Sur le VPS Plesk :** SSH de l'abonnement, `cd /var/www/vhosts/<DOMAINE>/tripleframes`, `(umask 077 && cp .env.example .env)`, puis `nano .env` en suivant le tableau de `ops/mise-en-service.md` étape 2 (décommenter les lignes `# DB_…`, ajouter `SESSION_SECURE_COOKIE=true`). Chaque secret est généré dans le terminal (`"$PHP" -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'` pour `APP_KEY`, `openssl rand -hex 16` pour `REVERB_APP_ID` et `REVERB_APP_KEY`, `openssl rand -hex 32` pour `REVERB_APP_SECRET` et `OPS_PROBE_TOKEN`) et collé dans l'éditeur ; `DB_PASSWORD` = celui généré par Plesk ; `REDIS_PASSWORD` = celui de `requirepass` ; `<TMDB_TOKEN>` collé depuis le gestionnaire de secrets ; `APP_URL=https://<DOMAINE>`, `REVERB_ALLOWED_ORIGINS=<DOMAINE>`. **`APP_KEY` recopiée aussitôt dans les deux exemplaires hors machine.** Mêmes vérifications (sans `sudo`, en SSH de l'abonnement ; la comparaison avec `requirepass` en root).

### Étape 2.7 — `composer install`, schéma, données de plateforme

- **Commandes** — [abo] :

```bash
umask 027
PHP=/opt/plesk/php/8.4/bin/php
cd /var/www/vhosts/tripleframes-prod.test/tripleframes
"$PHP" /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress
grep -E '^DB_(CONNECTION|HOST|DATABASE|USERNAME)=' .env          # relire la base AVANT migrate
"$PHP" artisan about --only=environment
"$PHP" artisan db:show
"$PHP" artisan migrate --force
"$PHP" artisan migrate:status | grep -c Ran
"$PHP" artisan db:seed --class=PlatformDataSeeder --force
```

- **Attendu et vérification** (12:16 UTC) : `composer` en 10 s, `package:discover` sans erreur (l'application démarre avec le `.env` de production : `FRAMES_DISK_ROOT` accepté) ; `DB_DATABASE=tripleframes_prod` ; `Environment … production`, `Debug Mode … OFF`, `PHP 8.4.16` ; `db:show` : `tripleframes_prod`, `Tables … 0` ; 47 migrations `DONE` ; `PlatformDataSeeder … DONE`. Base vide : aucun instantané requis (règle 12, rien à protéger).
- **Écart (corrigé après coup, RV-10)** : joué à 12:16 **sans** la ligne `umask 027`, ajoutée par le contrôle de la phase : l'umask de l'utilisateur d'abonnement de la VM vaut `0002` (utilisateur Ubuntu), et tout fichier créé par la session naissait lisible par le groupe et les autres. Rattrapage à l'étape 2.18.
- **Sur le VPS Plesk :** identique en SSH de l'abonnement, bloc ouvert par `umask 027` (l'umask de l'utilisateur d'abonnement, `022` d'ordinaire sous Plesk, ne compte plus), `cd /var/www/vhosts/<DOMAINE>/tripleframes`, composer par `"$PHP" <chemin de composer.phar relevé>` (jamais `composer` nu : son shebang désignerait le PHP du système).

### Étape 2.8 — Activation des unités et planificateur (root)

- **But** : report du socle, points 2 et 3 : activation **après** un `.env` complet, `composer install` et `migrate` ; démarrage à l'étape 2.9.
- **Commandes** — [root] :

```bash
systemctl enable tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
systemctl show tripleframes-worker@game tripleframes-worker@default tripleframes-reverb -p Id -p UnitFileState -p ActiveState
printf '%s\n' 'MAILTO=""' '* * * * * /opt/plesk/php/8.4/bin/php /var/www/vhosts/tripleframes-prod.test/tripleframes/artisan schedule:run' | crontab -u tripleframes -
crontab -l -u tripleframes
```

- **Attendu et vérification** : trois `Created symlink … multi-user.target.wants/…` ; `UnitFileState=enabled`, `ActiveState=inactive` ; la crontab affiche les deux lignes.
- **Sur le VPS Plesk :** `systemctl enable …` identique (étape 4 b) ; la tâche planifiée se crée dans Plesk (étape 1.11 de ce document, `ops/plesk/settings.md` § 4).

### Étape 2.9 — `optimize`, `lang:hash`, démarrage des unités

- **Commandes** — [abo] puis [root] :

```bash
umask 027
PHP=/opt/plesk/php/8.4/bin/php
cd /var/www/vhosts/tripleframes-prod.test/tripleframes
"$PHP" artisan optimize
"$PHP" artisan lang:hash
stat -c '%a %n' bootstrap/cache/config.php                      # 640
```

```bash
systemctl start tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
systemctl --no-pager status tripleframes-redis tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
systemctl show tripleframes-worker@game tripleframes-worker@default tripleframes-redis tripleframes-reverb -p Id -p MemoryMax -p CPUWeight -p User -p ActiveState
ss -ltnp | grep -E ':(6390|8090)\b'
```

- **Attendu et vérification** (12:16 UTC) : `config`, `events`, `routes`, `views` `DONE` ; `Empreinte des traductions écrite : …` ; quatre unités `active (running)` ; `MemoryMax` 192 Mo (`game`), 512 Mo (`default`), 320 Mo (Redis), 256 Mo (Reverb), `CPUWeight` 80/20/80/80, `User=tripleframes` (Redis : `tfredis`) ; écoute `127.0.0.1:6390` (redis-server) et `127.0.0.1:8090` (php, Reverb) seulement.
- **Constat (corrigé par le contrôle, RV-10)** : joué à 12:16 **sans** `umask 027`, `bootstrap/cache/config.php` — copie de **tous** les secrets du `.env` : `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `REVERB_APP_SECRET`, `OPS_PROBE_TOKEN`, jeton TMDB — est né en `0664` (umask `0002` de l'utilisateur Ubuntu), comme `events.php`, `routes-v7.php` et `lang-version.php`, alors que le `.env` est en `0600`. Ce qu'écrivait d'abord ce paragraphe (« seul le HOME `0710` empêche les autres comptes de le lire ») était **faux** : le HOME est en `0710 tripleframes:vagrant`, et `vagrant` — compte de développement du porteur, utilisateur de nginx et des pools `php8.1-fpm`/`php8.3-fpm` de ses sites — le traversait et **lisait** le fichier (`sudo -u vagrant cat …/bootstrap/cache/config.php` : lu ; `.env` : refusé ; `www-data` et `nobody` : refusés). Chaque passage du hook (étape 8, `optimize`) le recréait avec les mêmes droits. Sur Plesk, même risque sous l'umask `022` (`0644`) : le HOME `0710 …:psaserv` y laisse passer le serveur web. Correction : `umask 027` en tête de ce bloc, de l'étape 2.7 et du hook, vérification `stat` ci-dessus ; rattrapage à l'étape 2.18.
- **Sur le VPS Plesk :** identique, `umask 027` compris (`"$PHP"` en SSH de l'abonnement, `systemctl` en root) ; `stat -c '%a %n' bootstrap/cache/config.php` → `640`.

### Étape 2.10 — Vérifications HTTP de l'étape 5

- **Commandes** — [vagrant] (sur le VPS : depuis n'importe où, sans `--resolve`) :

```bash
H=tripleframes-prod.test; CA=/etc/ssl/certs/ca.homestead.homestead.crt
R="--resolve $H:80:127.0.0.1 --resolve $H:443:127.0.0.1"
curl -s -o /dev/null -w '%{http_code}\n' --cacert $CA $R "https://$H/up"
curl -s -D - -o /dev/null --cacert $CA $R "https://$H/" | grep -iE '^(HTTP|x-robots-tag|strict-transport-security|cache-control|vary)'
curl -s -D - --cacert $CA $R "https://$H/robots.txt" | grep -iE '^HTTP|Disallow|User-agent'
for p in /register /login /legal/notice /admin /f/0123456789abcdef /fpm-status /apps/x /.env; do
    printf '%-24s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --cacert $CA $R "https://$H$p")"
done
curl -s --cacert $CA $R -H 'Accept-Language: fr' "https://$H/legal/notice" | grep -oE 'contact\.unavailable":"[^"]*"'
curl -s -D - --cacert $CA $R -H 'Accept-Language: fr' "https://$H/" | grep -oE '^(age|x-cache|x-proxy-cache):.*|<html[^>]*>'
curl -s -D - --cacert $CA $R -H 'Accept-Language: en' "https://$H/" | grep -oE '^(age|x-cache|x-proxy-cache):.*|<html[^>]*>'
```

- **Attendu et vérification** (12:17 UTC, puis 12:18 après correction) : `/up` 200 ; `/` : `HTTP/2 200`, `x-robots-tag: noindex, nofollow`, `strict-transport-security: max-age=300`, `cache-control: no-cache, private`, `vary: X-Inertia, Accept-Language`, cookies `secure` ; `robots.txt` statique (`Disallow: /admin`, `Disallow: /f/`) ; `/register` 404 (inscription fermée) ; `/login` **200** (voir l'écart) ; `/legal/notice` 200 avec la mention « Aucune adresse de contact n'est encore publiée » (`LEGAL_CONTACT_EMAIL` vide) ; `/admin` 302 vers `/login` ; `/f/…` 404 avec `x-robots-tag` ; `/fpm-status` 404 (tombe dans l'application, jamais publié) ; `/apps/x` 404 ; `/.env` 403 ; `<html lang="fr"` puis `<html lang="en"`, aucune ligne `Age`, `X-Cache`, `X-Proxy-Cache`.
- **Écart (corrigé dans le dépôt)** : `/login` répondait **502** ; journal d'erreurs du vhost : `upstream sent too big header while reading response header from upstream`. Cause : l'en-tête `Link` des assets préchargés (`AddLinkHeadersForPreloadedAssets`, 3,5 Ko sur `/login`) plus les deux cookies (≈ 0,9 Ko) dépassent le tampon par défaut de nginx (`fastcgi_buffer_size` = 4 Ko). Mesuré depuis le manifeste : jusqu'à 5,1 Ko de `Link` (banque d'images du back-office, 44 fichiers), 4,1 Ko au lobby : sans correction, le back-office et l'écran de jeu répondraient 502. Diagnostic, [root] (réponse brute de PHP-FPM, longueur de chaque en-tête) :

```bash
P=/var/www/vhosts/tripleframes-prod.test/tripleframes/public
env SCRIPT_FILENAME=$P/index.php SCRIPT_NAME=/index.php REQUEST_URI=/login DOCUMENT_URI=/index.php DOCUMENT_ROOT=$P \
    REQUEST_METHOD=GET HTTP_HOST=tripleframes-prod.test HTTPS=on SERVER_PORT=443 SERVER_NAME=tripleframes-prod.test \
    REMOTE_ADDR=127.0.0.1 QUERY_STRING= \
    cgi-fcgi -bind -connect /var/www/vhosts/system/tripleframes-prod.test/php-fpm.sock \
    | awk '/^\r?$/{exit} {n+=length($0)+2; print length($0), substr($0,1,40)} END{print "total en-têtes :", n}'
```

(obtenu : `Link` 3 516 octets, total 4 657). Correction : `fastcgi_buffer_size 32k;` et `fastcgi_buffers 16 16k;` ajoutés en tête de `ops/nginx/additional-directives.conf` (hérités par la location PHP que génère Plesk), ligne de contrôle `/login` → 200 ajoutée à `ops/mise-en-service.md` étape 5 ; tests des gabarits verts dans la VM (`OpsTemplatesTest`, `DeployHookTest`, `NoLiteralDomainTest` : 11 tests, 1 494 assertions). Réappliqué sur la VM, [root] :

```bash
cd /home/vagrant/tripleframes-repetition/tests-s1          # gabarit corrigé recopié ici
install -o root -g root -m 0644 ops/nginx/additional-directives.conf /etc/tripleframes/nginx/additional-directives.conf.new
sed -i 's/__TF_REVERB_PORT__/8090/' /etc/tripleframes/nginx/additional-directives.conf.new
mv /etc/tripleframes/nginx/additional-directives.conf.new /etc/tripleframes/nginx/additional-directives.conf
diff ops/nginx/additional-directives.conf /etc/tripleframes/nginx/additional-directives.conf
nginx -t && systemctl reload nginx
```

  Résultat : seule la ligne `proxy_pass` diffère ; master nginx inchangé (PID 973) ; `/login`, `/`, `/legal/notice`, `/forgot-password` en 200.
- **Sur le VPS Plesk :** coller le gabarit **corrigé** dans « Directives nginx supplémentaires ». Si Plesk refuse l'enregistrement pour une directive en double, sa propre valeur suffit si elle est d'au moins 16k : retirer les deux lignes. Contrôle : `curl -s -o /dev/null -w '%{http_code}\n' https://<DOMAINE>/login` → 200 (nouvelle ligne de l'étape 5). Puis la vérification « langue et cache », obligatoire après toute modification des directives.

### Étape 2.11 — Sondes

- **Commandes** — [root] sur la VM (jeton lu dans le `.env`, passé à `curl` par un fichier, jamais en argument) :

```bash
H=tripleframes-prod.test; R="--resolve $H:443:127.0.0.1"; CA=/etc/ssl/certs/ca.homestead.homestead.crt
TOKEN="$(sed -n 's/^OPS_PROBE_TOKEN=//p' /var/www/vhosts/tripleframes-prod.test/tripleframes/.env)"
for probe in worker-game worker-default load integrity purge; do
    printf '%-15s ' "$probe"
    curl -s -w ' %{http_code}' --cacert $CA $R -H @<(printf 'X-Probe-Token: %s\n' "$TOKEN") "https://$H/ops/probe/$probe"; echo
done
curl -s -o /dev/null -w 'sans jeton %{http_code}\n' --cacert $CA $R "https://$H/ops/probe/load"
curl -s -o /dev/null -w 'jeton faux %{http_code}\n' --cacert $CA $R -H 'X-Probe-Token: faux' "https://$H/ops/probe/load"
unset TOKEN
```

Puis [abo] : `"$PHP" artisan purge:run --sync` et `"$PHP" artisan schedule:list`, et la sonde `purge` rejouée après le passage suivant de `room:archive-idle`.

- **Attendu et vérification** (12:18-12:20 UTC) : `worker-game`, `worker-default`, `load`, `integrity` : `{"status":"ok"} 200` ; `purge` : `{"status":"stale"} 503` sur base neuve (attendu) ; `purge:run --sync` : « Périmètres : 6. Lignes traitées : 0. », code 0 ; après le passage de `room:archive-idle` (cadence `*/10`) : `purge` → `{"status":"ok"}` ; sans jeton et jeton faux : 404. `load` vert : `/proc/meminfo` et `/proc/cpuinfo` lisibles sous l'`open_basedir` du pool (`OPS_LOAD_CPU_COUNT` reste vide).
- **Sur le VPS Plesk :** le bloc de `ops/mise-en-service.md` étape 5 (`read -rs TOKEN`, jeton collé sans écho), depuis le poste ou le serveur, en `https://<DOMAINE>/ops/probe/…`.

### Étape 2.12 — Reverb de bout en bout (`wss`)

- **Commandes** — [root] sur la VM (poignée de main HTTP/1.1 à travers nginx, trois cas) :

```bash
H=tripleframes-prod.test; R="--resolve $H:443:127.0.0.1"
KEY="$(sed -n 's/^REVERB_APP_KEY=//p' /var/www/vhosts/tripleframes-prod.test/tripleframes/.env)"
for o in "https://$H" "https://evil.example"; do
    curl -sk --http1.1 $R --max-time 3 -D - -o - -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
        -H 'Sec-WebSocket-Version: 13' -H "Sec-WebSocket-Key: $(openssl rand -base64 16)" -H "Origin: $o" \
        "https://$H/app/$KEY?protocol=7&client=js&version=8.4.0" | tr -c '[:print:]\n' '.' | grep -oE '^HTTP/1.1 [0-9]{3} [A-Za-z ]+|"event":"[^"]+"|"message\\":\\"[^\]+'
done
unset KEY
```

Depuis le [poste] (Node 25, sans `wscat` : TLS vers `192.168.10.10` avec le SNI du site, clé d'application lue dans la prop publique `realtime` de la page) : `node <scratchpad>/vm/deploiement/wss-poste.mjs` puis `node … 192.168.10.10 https://evil.example`. PowerShell : `Test-NetConnection 192.168.10.10 -Port 6390` et `-Port 8090`.

- **Attendu et vérification** (12:21 UTC) : origine du site → `HTTP/1.1 101 Switching Protocols` puis `pusher:connection_established` ; origine étrangère → `101` puis `pusher:error` code 4009 « Origin not allowed » ; clé fausse → `pusher:error` 4001 « Application does not exist » ; depuis le poste, mêmes résultats par nginx ; prop `realtime` : `host`, `port`, `scheme` à `null` (le navigateur vise `window.location`, port 443) ; `TcpTestSucceeded` : `False` sur 6390 et 8090, `True` sur 443.
- **Sur le VPS Plesk :** `npx wscat -o https://<DOMAINE> -c "wss://<DOMAINE>/app/<REVERB_APP_KEY>?protocol=7&client=js&version=8.4.0"` depuis le poste (étape 5), attendu `pusher:connection_established` ; `Test-NetConnection <IP du VPS> -Port <port Redis>` et `-Port <port Reverb>` → `False`.

### Étape 2.13 — Premier instantané réel (`backup:snapshot`)

- **Commandes** — [abo] :

```bash
PHP=/opt/plesk/php/8.4/bin/php
cd /var/www/vhosts/tripleframes-prod.test/tripleframes
D=/var/www/vhosts/tripleframes-prod.test/private/tripleframes/snapshots
"$PHP" artisan backup:snapshot; echo "code=$?"
ls -l "$D"
gzip -t "$D"/snapshot-*.sql.gz && echo "gzip -t : OK"
zcat "$D"/snapshot-*.sql.gz | tail -n 1
"$PHP" artisan backup:snapshot --if-pending; echo "code=$?"
```

- **Attendu et vérification** (12:19 UTC) : « Instantané écrit et vérifié : …/snapshot-20260928T121959Z.sql.gz », code 0 ; fichier `-rw------- tripleframes` (9,8 Ko) ; `gzip -t : OK` ; `-- Dump completed on 2026-09-28 12:19:59` ; `--if-pending` : « Aucune migration en attente », code 0, rien d'écrit. **Premier vidage `mysqldump` réel** (report de L100-6) : il passe sans le privilège `PROCESS`.
- **Sur le VPS Plesk :** identique, chemin `/var/www/vhosts/<DOMAINE>/private/tripleframes/snapshots`.

### Étape 2.14 — Premier administrateur (`admin:first-admin`)

- **But** : `100` § 11.6 étape 6, `ops/mise-en-service.md` étape 6 : le seul rôle qu'aucun écran n'attribue ; nom réel exigé ; mot de passe par invites masquées, **jamais en argument**.
- **Commande** — [abo], session **interactive** (terminal réel) :

```bash
PHP=/opt/plesk/php/8.4/bin/php
cd /var/www/vhosts/tripleframes-prod.test/tripleframes
"$PHP" artisan admin:first-admin admin@tripleframes-prod.test --create --name=porteur --real-name="Porteur Répétition"
```

Réponses aux invites : « Aucun compte pour admin@tripleframes-prod.test. Le créer maintenant ? » → `y` puis Entrée ; « Mot de passe » → `<ADMIN_PASSWORD>` ; « Confirmation du mot de passe » → `<ADMIN_PASSWORD>`. En production, la règle de mot de passe exige 12 caractères au moins, majuscules et minuscules, chiffres, symboles, et un mot de passe absent des fuites connues (`uncompromised()` interroge `api.pwnedpasswords.com` : joignable depuis la VM, `curl … /range/ABCDE` → 200).

Vérification [root] (lecture par l'utilisateur dédié, mot de passe lu dans le `.env`) :

```bash
E=/var/www/vhosts/tripleframes-prod.test/tripleframes/.env
MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' $E)" mysql --no-defaults --protocol=socket -u tripleframes_prod tripleframes_prod \
    -e "SELECT id,name,email,role,real_name,email_verified_at IS NOT NULL AS verifie,two_factor_confirmed_at FROM users; SELECT id,action,actor_name,actor_id,subject_type,subject_id FROM admin_action;"
```

- **Attendu et vérification** (12:26 UTC) : « Compte admin@tripleframes-prod.test créé. », « porteur (admin@tripleframes-prod.test) est désormais administrateur. », code 0 ; ligne `users` : `role=admin`, `real_name=Porteur Répétition`, adresse vérifiée (l'accès au shell vaut preuve), second facteur non encore confirmé ; `admin_action` : une ligne `role.changed`, acteur `console`, `actor_id` NULL.
- **Propre à la répétition** : l'invite est jouée par un petit pilote de terminal (`/home/vagrant/tripleframes-repetition/outils/first-admin-pty.py`, module `pty` de Python) qui attend chaque invite et reçoit le mot de passe sur son entrée standard (`sed -n 's/^PASSWORD=//p' admin-credentials.txt | ssh … 'python3 …/first-admin-pty.py'`) ; Laravel Prompts exige un vrai terminal. Le mot de passe, généré sur le poste, n'existe que dans le fichier d'identifiants du poste (hors dépôt, hors VM).
- **Sur le VPS Plesk :** le porteur tape la commande et les réponses lui-même, en SSH de l'abonnement, `admin@<DOMAINE>` et son vrai nom ; **jamais avant l'achat du domaine**, aucune passkey au J1. Mot de passe issu du gestionnaire de secrets.

### Étape 2.15 — Première connexion et enrôlement du second facteur

- **But** : la garde `admin.2fa` ferme `/admin` tant que le TOTP n'est pas confirmé (`20` § 2.4) ; codes de secours rangés hors machine (`100` § 11.9).
- **Gestes** — [poste], navigateur sur `https://tripleframes-prod.test` :
  1. `/login` : adresse et `<ADMIN_PASSWORD>` → `/dashboard`.
  2. `/admin` → renvoi vers `/admin/two-factor` (« Double authentification requise », marche à suivre en cinq étapes) ; « Ouvrir la sécurité du compte ».
  3. `/settings/security` exige la confirmation du mot de passe (`/user/confirm-password`) ; puis « Enable 2FA » : QR code et clé manuelle (16 caractères base32) ; « Continue » ; code à 6 chiffres de l'application d'authentification ; « Confirm ».
  4. Huit codes de secours affichés : les ranger hors machine.
  5. Déconnexion, reconnexion : mot de passe, puis `/two-factor-challenge` (code TOTP) ; `/admin` ouvre le tableau de bord de curation (catalogue vide : 0 film dans chaque état).
- **Attendu et vérification** (12:27-12:30 UTC) : captures `03-admin-garde-2fa`, `04-confirmation-mot-de-passe`, `05-securite-du-compte`, `06-2fa-dialogue-secret-masque`, `07-2fa-confirme` (codes masqués), `08-defi-2fa`, `09-admin-tableau-de-bord` ; en base : `two_factor_secret` et `two_factor_recovery_codes` non nuls, `two_factor_confirmed_at = 2026-09-28 12:29:07`.
- **Propre à la répétition** : Chrome headless piloté par CDP (`puppeteer-core`, `--host-resolver-rules="MAP tripleframes-prod.test 192.168.10.10"`, `--ignore-certificate-errors`), code TOTP calculé (RFC 6238, SHA-1, 30 s) depuis la clé manuelle. Clé TOTP et codes de secours rangés dans le fichier d'identifiants du poste (tient lieu des deux exemplaires hors machine). Constat : l'écran de sécurité du compte est en anglais (langue de l'interface = `APP_LOCALE=en` pour un navigateur sans préférence), le back-office en français (forcé, `05`).
- **Sur le VPS Plesk :** mêmes gestes dans le navigateur du porteur, application d'authentification du téléphone ; codes de secours **imprimés** et rangés en deux exemplaires avec `APP_KEY` et la clé privée de sauvegarde.

### Étape 2.16 — `hook.env` et artefact n° 2

- **But** : `ops/mise-en-service.md` étape 7 : le hook lit le chemin de `composer.phar` dans `~/.config/tripleframes/hook.env` (sans secret). Artefact n° 2 = source n° 1 + correction RV-6 (tampon fastcgi, étape 2.10), pour que le checkout de déploiement porte le gabarit corrigé.
- **Commandes** — [poste], Git Bash :

```bash
cd C:/Users/Admin/Desktop/groupez/tripleframes
cp ops/nginx/additional-directives.conf <scratchpad>/vm/build/src/ops/nginx/additional-directives.conf
cp ops/mise-en-service.md <scratchpad>/vm/build/src/ops/mise-en-service.md
git -C <scratchpad>/vm/build/src status --short          # les deux fichiers en M
git -C <scratchpad>/vm/build/src commit -qam "répétition : tampon fastcgi des directives nginx (RV-6), jamais poussé"
cd <scratchpad>/vm/build/src
export PATH="/c/…/<scratchpad>/vm/build/php-stub:$PATH"
cp public/build/manifest.json ../manifest-1.json
npm run build
cmp -s public/build/manifest.json ../manifest-1.json && echo "manifest identique au build n° 1"
```

Puis le contrôle `Verify Build Contents` et le bloc du job `artifacts` de l'étape 2.4 (qui prend cette fois `deploy` comme parent), `git bundle create <scratchpad>/vm/build/deploy-2.bundle deploy`, `scp` vers `/home/vagrant/tripleframes-repetition/`. Sur la VM, [vagrant] puis [abo] :

```bash
H=/var/www/vhosts/tripleframes-prod.test
sudo install -o tripleframes -g tripleframes -m 0600 /home/vagrant/tripleframes-repetition/deploy-2.bundle "$H/incoming/deploy-2.bundle"
sudo -u tripleframes -H bash
(umask 077 && printf 'COMPOSER_PHAR=%s\n' /usr/local/bin/composer > ~/.config/tripleframes/hook.env)
cat ~/.config/tripleframes/hook.env; stat -c '%a %U' ~/.config/tripleframes/hook.env
git -C ~/git/tripleframes.git bundle verify -q ~/incoming/deploy-2.bundle && echo "bundle vérifié"
git -C ~/git/tripleframes.git fetch -q ~/incoming/deploy-2.bundle deploy:deploy
git -C ~/git/tripleframes.git log --oneline -2 deploy
```

- **Attendu et vérification** (12:30 UTC) : commit source `d5e7a75` ; `✓ built`, « manifest identique au build n° 1 » (construction reproductible : aucun asset ne change) ; `Verify Build Contents : OK` ; `deploy fe47155`, parent `0d33814`, différence limitée à `ops/mise-en-service.md` et `ops/nginx/additional-directives.conf` ; `hook.env` : `COMPOSER_PHAR=/usr/local/bin/composer`, `600 tripleframes` ; « Tirer » en avance rapide (refspec sans `+`) : `fe47155` au-dessus de `0d33814`.
- **Sur le VPS Plesk :** `hook.env` identique en SSH de l'abonnement, chemin de `composer.phar` relevé ; la CI publie `deploy` ; « Tirer les mises à jour » dans Plesk Git, puis renseigner **Actions de déploiement additionnelles** = `bash ops/deploy/hook.sh` (`ops/plesk/settings.md` § 6) ; relever le répertoire courant et le délai de ces actions (le hook force son répertoire, `HOME` doit être défini).

### Étape 2.17 — Premier déploiement par le hook, sans drainage

- **But** : `100` § 11.4 **sans ses étapes 3 et 4** (D37 du 23/09, I-13 : le hook est livré avec les lignes `# drain:` inactives) ; sortie `[1/12]` à `[11/12]`, sans 3 ni 12.
- **Commandes** — [root] (relevé des PID) puis [abo] :

```bash
for u in tripleframes-worker@game tripleframes-worker@default tripleframes-reverb; do systemctl show -p MainPID --value $u; done
```

```bash
cd ~
git --git-dir=$HOME/git/tripleframes.git --work-tree=$HOME/tripleframes checkout -f deploy
grep -c fastcgi_buffer_size ~/tripleframes/ops/nginx/additional-directives.conf
cd ~/tripleframes && bash ops/deploy/hook.sh; echo "code du hook = $?"
PHP=/opt/plesk/php/8.4/bin/php
"$PHP" artisan deploy:guard; echo "deploy:guard code=$?"
"$PHP" artisan deploy:release; echo "deploy:release code=$?"
```

Puis les vérifications des étapes 2.10 et 2.11 (`/up`, `/login`, `/`, cinq sondes) et `journalctl -u tripleframes-worker@game -u tripleframes-worker@default -u tripleframes-reverb --since "12:30" --no-pager -o cat | tail`.

- **Attendu et vérification** (12:30:57 → 12:31:03 UTC, 6 s) : `[1/12]` composer « Nothing to install, update or remove » ; `[2/12]` `optimize:clear --except=cache` (`config`, `compiled`, `events`, `routes`, `views`) ; **pas de `[3/12]`** ; `[4/12]` « Aucune migration en attente : aucun instantané n'est nécessaire » ; `[5/12]` « Nothing to migrate » ; `[6/12]` `PlatformDataSeeder … DONE` ; `[7/12]` « Reprojection terminée. Films reprojetés par différence : 0 » ; `[8/12]` `optimize` ; `[9/12]` empreinte inchangée (`b8e47251…`) ; `[10/12]` « Broadcasting queue restart signal » ; `[11/12]` « Broadcasting Reverb restart signal » ; **pas de `[12/12]`** ; code 0. PID des trois unités tous changés (243130/243131/243132 → 244551/244563/244560 : systemd les a relevées, `Restart=always`), quatre unités `active`. **Vérifications de la transition I-13** (commandes livrées par L100-5, présentes sur le serveur) : `deploy:guard` → « Garde refusée : aucune fenêtre libre ouverte… », **code 2** ; `deploy:release` → « Aucun drapeau de drainage : rien à lever », **code 0**. `/up`, `/login`, `/` : 200 ; cinq sondes `ok` ; journaux des workers : battements `WorkerHeartbeat (game)` et `(default)` en `DONE` ; journal de l'application : deux avertissements attendus seulement (sonde `purge` avant le premier balayage, garde refusée).
- **Écart** : la transition du drainage (commit d'activation des deux lignes `# drain:`, second déploiement par la procédure complète du § 11.4) n'est **pas** jouée ici : plan D5, après la partie de la phase 4 (étape 126 du REPRISE).
- **Écart (corrigé, RV-10)** : le hook des artefacts n° 2 et n° 3 ne fixait pas l'umask ; lancé sous l'umask `0002` de la session, son étape 8 (`optimize`) recréait `bootstrap/cache/config.php` en `0664`, lisible par `vagrant` (étape 2.9). Le hook porte désormais `umask 027` juste après `set -euo pipefail` ; livré par l'artefact n° 4 (étape 2.18), qui prouve que le hook recrée le fichier en `640` quel que soit l'umask de la session.
- **Sur le VPS Plesk :** « Déployer » dans Plesk Git : les fichiers sont déposés **puis** l'action additionnelle lance le hook ; lire la sortie dans l'interface (ou le journal de déploiement de Plesk). En cas d'échec : remède du § 11.5 (réparer, puis relancer les actions de déploiement). Même contrôle `deploy:guard` (2) / `deploy:release` (0) en SSH de l'abonnement.

### Étape 2.18 — Rattrapage : droits des caches de démarrage et des journaux (RV-10, propre à la répétition)

- **Joué** le 28/09, 13:11-13:15 UTC, **après** l'étape 4.7 (correction du contrôle de la phase Déploiement) ; rangé ici parce qu'il corrige les étapes 2.7, 2.9 et 2.17.
- **But** : aucune copie des secrets du `.env` lisible par un autre compte. Constat (étape 2.9) : `bootstrap/cache/{config,events,routes-v7,lang-version}.php` en `664`, `packages.php` et `services.php` en `775`, lus par `vagrant` ; journal `storage/logs/laravel-2026-09-28.log` en `644`, parce que les trois unités PHP tournaient sous `Umask: 0022` (défaut de systemd). Correction des gabarits du dépôt : `umask 027` dans `ops/deploy/hook.sh` (juste après `set -euo pipefail`, vérifié par `DeployHookTest`), `UMask=0027` dans la section `[Service]` de `ops/systemd/tripleframes-worker@.service` et de `tripleframes-reverb.service` (vérifié par `OpsTemplatesTest`), `umask 027` et contrôle `stat` → `640` aux étapes 3 et 5 de `ops/mise-en-service.md`. Tests des gabarits dans la VM (clone `tests-s1`, PHP 8.4) : `OpsTemplatesTest`, `DeployHookTest`, `NoLiteralDomainTest` 11/11 (1 573 assertions), Pint propre, `bash -n` du hook ; rouges sans les deux lignes (« UMask doit être déclarée une fois », `'umask 027'` attendu), verts avec.
- **Commandes** — 1) [abo], rattrapage immédiat des fichiers existants :

```bash
APP=/var/www/vhosts/tripleframes-prod.test/tripleframes
cd "$APP"
umask
stat -c '%a %n' bootstrap/cache/*.php storage/logs/*.log
chmod 0640 bootstrap/cache/*.php storage/logs/*.log
stat -c '%a %n' bootstrap/cache/*.php storage/logs/*.log
```

2) [root], contrôle d'accès, puis `/up`, `/login`, `/` et les cinq sondes (bloc de l'étape 2.11) :

```bash
APP=/var/www/vhosts/tripleframes-prod.test/tripleframes
sudo -u vagrant cat "$APP/bootstrap/cache/config.php" > /dev/null && echo "vagrant LIT config.php" || echo "vagrant : refusé (attendu)"
sudo -u www-data cat "$APP/bootstrap/cache/config.php" > /dev/null 2>&1 && echo "www-data LIT" || echo "www-data : refusé (attendu)"
sudo -u vagrant cat "$APP/.env" > /dev/null 2>&1 && echo "vagrant LIT .env" || echo "vagrant .env : refusé (attendu)"
H=tripleframes-prod.test; R="--resolve $H:443:127.0.0.1"; CA=/etc/ssl/certs/ca.homestead.homestead.crt
for p in /up /login /; do printf '%-8s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' --cacert $CA $R "https://$H$p")"; done
```

3) [root], unités recopiées depuis le gabarit corrigé (même geste que l'étape 1.7, pour ces deux fichiers seulement ; `tests-s1` a reçu les gabarits corrigés), sans redémarrage :

```bash
cd /home/vagrant/tripleframes-repetition/tests-s1
grep -c '^UMask=0027$' ops/systemd/tripleframes-worker@.service ops/systemd/tripleframes-reverb.service
install -o root -g root -m 0644 "ops/systemd/tripleframes-worker@.service" \
    "/etc/systemd/system/tripleframes-worker@.service"
install -o root -g root -m 0644 ops/systemd/tripleframes-reverb.service \
    /etc/systemd/system/tripleframes-reverb.service
sed -i -e 's#__TF_SUBSCRIPTION_USER__#tripleframes#' \
       -e 's#__TF_SUBSCRIPTION_GROUP__#tripleframes#' \
       -e 's#__TF_DEPLOY_PATH__#/var/www/vhosts/tripleframes-prod.test/tripleframes#' \
    "/etc/systemd/system/tripleframes-worker@.service" /etc/systemd/system/tripleframes-reverb.service
systemctl daemon-reload
for f in tripleframes-worker@.service tripleframes-reverb.service; do
    echo "--- diff $f"; diff "ops/systemd/$f" "/etc/systemd/system/$f" || true
done
for u in tripleframes-worker@game.service tripleframes-worker@default.service tripleframes-reverb.service; do
    printf '%s : ' "$u"; systemd-analyze verify "$u" && echo "verify OK"
done
systemctl show tripleframes-worker@game tripleframes-worker@default tripleframes-reverb -p Id -p UMask -p ActiveState -p NeedDaemonReload
```

4) Artefact n° 4 et troisième déploiement par le hook (procédure des étapes 2.16 et 2.17) : [poste], Git Bash, les six fichiers corrigés (`ops/deploy/hook.sh`, les deux unités, `ops/mise-en-service.md`, `tests/Feature/Deploy/DeployHookTest.php`, `tests/Feature/Deploy/OpsTemplatesTest.php`) copiés du dépôt dans le clone jetable :

```bash
cd C:/Users/Admin/Desktop/groupez/tripleframes
for f in ops/deploy/hook.sh ops/systemd/tripleframes-worker@.service ops/systemd/tripleframes-reverb.service \
    ops/mise-en-service.md tests/Feature/Deploy/DeployHookTest.php tests/Feature/Deploy/OpsTemplatesTest.php; do
    cp "$f" "<scratchpad>/vm/build/src/$f"
done
git -C <scratchpad>/vm/build/src diff --stat                     # six fichiers, 31 insertions, 3 suppressions
cd <scratchpad>/vm/build/src
git commit -qam "répétition : umask 027 du hook et des unités (RV-10), jamais poussé"
export PATH="/c/…/<scratchpad>/vm/build/php-stub:$PATH"
cp public/build/manifest.json ../manifest-3.json
npm run build
cmp -s public/build/manifest.json ../manifest-3.json && echo "manifest identique au build précédent"
refused="$(find public/build \( -iname '*.php' -o -iname '*.php[0-9]' -o -iname '*.phtml' -o -iname '*.pht' -o -iname '*.phps' -o -iname '*.phar' -o -name '.*' \) -print)"
[ -z "$refused" ] && echo "Verify Build Contents : OK" || echo "REFUSÉS : $refused"
bash -s < <scratchpad>/vm/deploiement/rv10-artifacts.sh          # bloc du job artifacts de l'étape 2.4
bash -s < <scratchpad>/vm/deploiement/rv10-artifacts.sh          # rejeu : rien à publier, code 0
git bundle create <scratchpad>/vm/build/deploy-4.bundle deploy
scp -i <clé> <scratchpad>/vm/build/deploy-4.bundle vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/deploy-4.bundle
```

Puis [root] :

```bash
H=/var/www/vhosts/tripleframes-prod.test
install -o tripleframes -g tripleframes -m 0600 /home/vagrant/tripleframes-repetition/deploy-4.bundle "$H/incoming/deploy-4.bundle"
for u in tripleframes-worker@game tripleframes-worker@default tripleframes-reverb; do
    printf '%s PID=%s ' $u "$(systemctl show -p MainPID --value $u)"; grep Umask /proc/$(systemctl show -p MainPID --value $u)/status
done
```

Puis [abo] (session laissée **volontairement** à l'umask `0002`, pour prouver que le hook fixe le sien) :

```bash
cd ~
umask
git -C ~/git/tripleframes.git bundle verify -q ~/incoming/deploy-4.bundle && echo "bundle vérifié"
git -C ~/git/tripleframes.git fetch -q ~/incoming/deploy-4.bundle deploy:deploy
git -C ~/git/tripleframes.git log --oneline -2 deploy
git --git-dir=$HOME/git/tripleframes.git --work-tree=$HOME/tripleframes checkout -f deploy
grep -n '^umask 027$' ~/tripleframes/ops/deploy/hook.sh
stat -c '%a %i %y %n' ~/tripleframes/bootstrap/cache/config.php
cd ~/tripleframes && bash ops/deploy/hook.sh; echo "code du hook = $?"
stat -c '%a %i %y %n' bootstrap/cache/*.php storage/logs/*.log
```

Enfin [root] : PID et umask des unités relevées par `Restart=always`, puis le bloc 2) ci-dessus, les cinq sondes (étape 2.11) et la poignée de main `wss` (étape 2.12) :

```bash
for u in tripleframes-worker@game tripleframes-worker@default tripleframes-reverb; do
    p="$(systemctl show -p MainPID --value $u)"
    printf '%-28s PID=%s %s %s\n' $u "$p" "$(systemctl is-active $u)" "$(grep Umask /proc/$p/status)"
done
systemctl is-active tripleframes-redis tripleframes-php-fpm
```

- **Attendu et vérification** : 1) umask de la session `0002` ; avant : `664` (quatre caches), `775` (`packages.php`, `services.php`), `644` (journal) ; après : `640` partout. 2) `cat: …/config.php: Permission denied` puis « vagrant : refusé », « www-data : refusé », « vagrant .env : refusé » ; `/up`, `/login`, `/` → 200 ; cinq sondes `{"status":"ok"} 200`. 3) `diff` limité aux lignes `User`, `Group`, `WorkingDirectory` ; `verify OK` ×3 ; `UMask=0027`, `ActiveState=active`, `NeedDaemonReload=no` ; les processus en cours gardent `Umask: 0022` jusqu'à leur relève. 4) source `1716d50`, `deploy a775555` (parent `e7cdb5c`, six fichiers) ; manifest identique ; `Verify Build Contents : OK` ; rejeu « Arbre identique … rien à publier », code 0 ; hook `[1/12]` à `[11/12]` sans 3 ni 12, **code 0** (13:14:04 → 13:14:05) ; `config.php` **recréé** (inode 6042521 → 6031919, horodatage 13:14:05) en **`640`**, comme `events.php`, `routes-v7.php`, `lang-version.php` ; `packages.php`, `services.php` en `750` (réécrits par `package:discover` en `0777 − umask`) ; PID relevés (246534/246542/246539 → 250181/250188/250193), **`Umask: 0027`** pour les trois, cinq unités actives ; `vagrant` refusé ; `/up`, `/login`, `/` en 200 ; cinq sondes `ok` ; `wss` : `101` + `pusher:connection_established` (origine du site), `pusher:error` « Origin not allowed » (origine étrangère).
- **Limite, à relever sur le VPS** : `UMask=0027` couvre les workers et Reverb, et `umask 027` le hook et les sessions de l'abonnement ; un journal quotidien **créé** d'abord par PHP-FPM ou par la tâche planifiée (`schedule:run`) naît encore avec leur umask (`022` : `0644`). Le HOME `0710` en borne la lecture au groupe du serveur web. Contrôle au relevé du VPS : `stat -c '%a %n' storage/logs/*.log` le lendemain d'un déploiement. Remède possible, **non appliqué** (décision du porteur) : `'permission' => 0640` sur le canal `daily` de `config/logging.php`.
- **Sur le VPS Plesk :** rien à rattraper si les gabarits corrigés sont ceux recopiés à l'étape 4 b et que les étapes 3 et 5 de `ops/mise-en-service.md` ouvrent leur session par `umask 027` : `stat -c '%a %n' bootstrap/cache/config.php` → `640` dès l'étape 5, puis après chaque « Déployer ». Si le relevé montre `644` : `chmod 0640 bootstrap/cache/*.php storage/logs/*.log` en SSH de l'abonnement.

## Phase 3 — Exploitation

Jouée le 28/09/2026 de 13:21 à 13:37 UTC. Équivalent de `100` § 13 (sauvegardes et restauration), § 14 (purge) et § 15 (sondes), et de la part « jouée » de L100-10 ; **avant** la phase 4 (partie) et la transition du drainage (D5). Blocs joués comme aux phases 1 et 2 (`ssh … 'sudo bash -s' < bloc.sh` pour [root], `ssh … 'sudo -u tripleframes -H bash -s' < bloc.sh` pour [abo]) ; sur le VPS, taper les lignes une à une.

**Ce qui n'existe pas encore dans le dépôt (écart n° 3, L100-10 non livré)** : `ops/backup/backup-hot.sh`, `ops/backup/backup-cold.sh`, `backup:manifest` et `backup:verify`. La répétition joue donc des **préfigurations** de ces deux scripts, écrites ici en entier (étapes 3.6 et 3.7) et posées **hors du dépôt**, dans `/var/www/vhosts/tripleframes-prod.test/repetition-backup/` ; le manifeste est calculé par `find` + `sha256sum`, le périmètre du tier froid par une requête `tinker`, et l'étape (e) de la restauration (`backup:verify`) par une boucle `sha256sum` qui en applique la définition (§ 13.5 : « code 0 si et seulement si chaque frame `published` a son fichier de jeu présent et de condensat égal à `published_hash` »). Sur le VPS, ce sont les livrables de L100-10 qui tourneront ; à défaut, ces préfigurations servent telles quelles en mode `rclone`.

**Simulations propres à la répétition** : la clé privée de sauvegarde vit dans `/root/tripleframes-hors-machine/` (sur le VPS : **jamais sur le serveur**, deux exemplaires hors machine) et root y tient le rôle du **poste** pour tout déchiffrement ; le « stockage distant » est un répertoire local, `/srv/tripleframes-stockage-distant-simule/` (sur le VPS : stockage objet UE d'un autre fournisseur, à choisir) ; aucune supervision externe n'existe (le battement du tier chaud n'est pas envoyé, les alertes sont lues dans le journal de l'application).

**Corrections du contrôle (28/09, 13:58-14:00 UTC).** Le contrôle de la phase 3 a relevé six constats. Les blocs des étapes ci-dessous sont désormais les commandes **correctes**, à rejouer telles quelles. Chaque étape touchée porte un paragraphe « Correction du contrôle » : état fautif, commandes réellement jouées sur la VM, vérification. Étapes touchées : 3.5 (valeurs de `backup.env` entre apostrophes), 3.6 (battement par `curl -K -`, script réinstallé sur la VM), 3.7 (ligne « Sur le VPS Plesk »), 3.10 à 3.12 (puce « But »), écarts n° 17 et n° 22 (nouveau), « Retour arrière ».

### Étape 3.1 — Sondes, battement du worker `game`, Redis dédié

- **But** : `100` § 15 : `/up`, les cinq sondes à jeton, l'âge réel des battements dans le cache, l'état du Redis dédié ; témoins des voisins (Redis partagé) ; aucune partie en cours avant les gestes de la phase.
- **Commandes** — [root] (jeton lu dans le `.env`, passé à `curl` par un fichier, jamais en argument ; mot de passe Redis par `REDISCLI_AUTH`) :

```bash
set -uo pipefail
# [root] Étape 3.1 — sondes, battement du worker game, Redis dédié.
echo "== $(date -u +%FT%TZ)"
H=tripleframes-prod.test; R="--resolve $H:443:127.0.0.1"; CA=/etc/ssl/certs/ca.homestead.homestead.crt
APP=/var/www/vhosts/tripleframes-prod.test/tripleframes
for u in tripleframes-php-fpm tripleframes-redis tripleframes-worker@game tripleframes-worker@default tripleframes-reverb; do
    printf '%-28s %s PID=%s\n' "$u" "$(systemctl is-active $u)" "$(systemctl show -p MainPID --value $u)"
done
printf '%-15s %s\n' '/up' "$(curl -s -o /dev/null -w '%{http_code}' --cacert $CA $R "https://$H/up")"
TOKEN="$(sed -n 's/^OPS_PROBE_TOKEN=//p' "$APP/.env")"
for probe in worker-game worker-default load integrity purge; do
    printf '%-15s ' "$probe"
    curl -s -w ' %{http_code}' --cacert $CA $R -H @<(printf 'X-Probe-Token: %s\n' "$TOKEN") "https://$H/ops/probe/$probe"; echo
done
printf '%-15s %s\n' 'sans jeton' "$(curl -s -o /dev/null -w '%{http_code}' --cacert $CA $R "https://$H/ops/probe/load")"
printf '%-15s %s\n' 'jeton faux' "$(curl -s -o /dev/null -w '%{http_code}' --cacert $CA $R -H 'X-Probe-Token: faux' "https://$H/ops/probe/load")"
unset TOKEN
echo "== battements dans le cache (Redis dédié, index du cache)"
export REDISCLI_AUTH="$(awk '/^requirepass /{print $2}' /etc/tripleframes/tripleframes-redis.conf)"
CDB="$(sed -n 's/^REDIS_CACHE_DB=//p' "$APP/.env")"; CDB="${CDB:-1}"
now_ms=$(( $(date +%s%N) / 1000000 ))
redis-cli -p 6390 -n "$CDB" --scan --pattern '*ops:heartbeat:*' | sort | while read -r k; do
    v="$(redis-cli -p 6390 -n "$CDB" get "$k" | tr -dc '0-9')"
    printf '%-60s âge=%s ms\n' "$k" "$(( now_ms - v ))"
done
echo "== Redis dédié"
redis-cli -p 6390 ping
redis-cli -p 6390 info server | grep -E '^(redis_version|tcp_port|uptime_in_seconds|process_id):'
redis-cli -p 6390 info memory | grep -E '^(used_memory_human|maxmemory_human|maxmemory_policy):'
redis-cli -p 6390 info persistence | grep -E '^(aof_enabled|aof_last_write_status|aof_last_bgrewrite_status):'
redis-cli -p 6390 info clients | grep -E '^connected_clients:'
redis-cli -p 6390 info keyspace | grep -E '^db'
unset REDISCLI_AUTH
echo "== Redis partagé 6379 (témoin, jamais modifié)"
redis-cli -p 6379 info server | grep -E '^process_id:'; redis-cli -p 6379 info keyspace | grep -E '^db'
echo "== parties en cours (tripleframes_prod)"
E="$APP/.env"
MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' $E)" mysql --no-defaults --protocol=socket -u tripleframes_prod tripleframes_prod -N -e \
  "SELECT 'game', status, COUNT(*) FROM game GROUP BY status; SELECT 'room non archivé', COUNT(*) FROM room WHERE archived_at IS NULL; SELECT 'purge_run', scope, status, COUNT(*), MAX(started_at) FROM purge_run GROUP BY scope, status ORDER BY scope;"
```

- **Attendu et vérification** (13:24:52 UTC) : cinq unités `active` ; `/up` 200 ; `worker-game`, `worker-default`, `load`, `integrity`, `purge` : `{"status":"ok"} 200` ; sans jeton et jeton faux : 404 ; battements `tripleframes-database-tripleframes-cache-ops:heartbeat:game` âgé de 21,8 s (seuil 90 s) et `…:default` de 50,7 s (seuil 600 s) ; Redis 6.0.16 sur 6390, `PONG`, 1,08 Mo utilisés sur 256 Mo, `noeviction`, `aof_enabled:1`, dernières écritures AOF `ok`, 8 clients, index 1 (cache) seul occupé ; Redis partagé : PID 743, 22 clés (inchangé depuis 11:23) ; aucune partie, aucun salon non archivé ; `purge_run` : dernière exécution de chaque périmètre `completed`.
- **Sur le VPS Plesk :** les cinq sondes se lisent depuis le poste ou la supervision (`read -rs TOKEN`, jeton collé sans écho, `https://<DOMAINE>/ops/probe/…`, bloc de `ops/mise-en-service.md` étape 5) ; les sections « battements » et « Redis » se jouent en root sur le serveur, `-p <port Redis relevé>` ; la section « Redis partagé » vise le Redis voisin **s'il existe** au relevé, sinon elle se retire ; la requête finale, en SSH de l'abonnement.

### Étape 3.2 — Alertes volontaires : chaque sonde passe au rouge, puis revient au vert

- **But** : prouver que les sondes **alertent** (une sonde qui ne passe jamais au rouge ne prouve rien) : worker `game` arrêté (battement > 90 s), purge suspendue (l'interrupteur d'incident **déclenche** l'alerte, `100` § 14), jeton absent, faux ou sonde inconnue (404 identiques).
- **Commandes** — [root] (vérifier d'abord, étape 3.1, qu'aucune partie n'est en cours : arrêter le worker `game` pendant une partie la figerait) :

```bash
set -uo pipefail
# [root] Étape 3.2 — alertes volontaires : chaque sonde doit passer au rouge, puis revenir au vert.
H=tripleframes-prod.test; R="--resolve $H:443:127.0.0.1"; CA=/etc/ssl/certs/ca.homestead.homestead.crt
APP=/var/www/vhosts/tripleframes-prod.test/tripleframes
PHP=/opt/plesk/php/8.4/bin/php
TOKEN="$(sed -n 's/^OPS_PROBE_TOKEN=//p' "$APP/.env")"
probe() { curl -s -w ' %{http_code}' --cacert $CA $R -H @<(printf 'X-Probe-Token: %s\n' "$TOKEN") "https://$H/ops/probe/$1"; }
t() { date -u +%T; }

echo "== A) worker game arrêté"
echo "$(t) worker-game : $(probe worker-game)"
systemctl stop tripleframes-worker@game
echo "$(t) systemctl stop tripleframes-worker@game -> $(systemctl is-active tripleframes-worker@game)"
for i in $(seq 1 18); do
    r="$(probe worker-game)"; echo "$(t) worker-game : $r"
    case "$r" in *503) break;; esac
    sleep 10
done
systemctl start tripleframes-worker@game
echo "$(t) systemctl start tripleframes-worker@game -> $(systemctl is-active tripleframes-worker@game)"
for i in $(seq 1 12); do
    r="$(probe worker-game)"; echo "$(t) worker-game : $r"
    case "$r" in *200) break;; esac
    sleep 5
done

echo "== B) purge suspendue"
echo "$(t) purge : $(probe purge)"
sudo -u tripleframes -H bash -c "umask 027; cd $APP && $PHP artisan purge:suspend; echo \"purge:suspend code=\$?\""
echo "$(t) purge : $(probe purge)"
sudo -u tripleframes -H bash -c "umask 027; cd $APP && $PHP artisan purge:run --sync; echo \"purge:run --sync (suspendue) code=\$?\""
sudo -u tripleframes -H bash -c "umask 027; cd $APP && $PHP artisan purge:resume; echo \"purge:resume code=\$?\""
echo "$(t) purge : $(probe purge)"

echo "== C) jeton"
printf '%s sans jeton %s ; jeton faux %s ; sonde inconnue %s\n' "$(t)" \
    "$(curl -s -o /dev/null -w '%{http_code}' --cacert $CA $R "https://$H/ops/probe/purge")" \
    "$(curl -s -o /dev/null -w '%{http_code}' --cacert $CA $R -H 'X-Probe-Token: faux' "https://$H/ops/probe/purge")" \
    "$(curl -s -o /dev/null -w '%{http_code}' --cacert $CA $R -H @<(printf 'X-Probe-Token: %s\n' "$TOKEN") "https://$H/ops/probe/inconnue")"
unset TOKEN
echo "== journal de l'application (motifs d'alerte, dernières lignes)"
tail -n 400 "$APP"/storage/logs/laravel-*.log | grep -E 'WARNING|ERROR' | tail -n 8 | cut -c1-220
```

- **Attendu et vérification** (13:25:17 → 13:26:43 UTC) : A) worker arrêté à 13:25:18, sonde `ok` jusqu'à 13:26:28 puis **`{"status":"stale"} 503` à 13:26:38** (80 s après l'arrêt, dernier battement 97,9 s plus tôt) ; redémarré à 13:26:38, sonde **`ok` à 13:26:43** (les battements accumulés dans la file sont dépilés aussitôt) ; journal : « Sonde d'exploitation en alerte. {"probe":"worker-game","failures":["game : dernier battement il y a 97883 ms, au-delà de 90 s"]} ». B) `purge:suspend` code 0 (avertissement « la sonde purge reste en alerte ») ; sonde `purge` **503** ; `purge:run --sync` **refusé, code 1** (« Purge de rétention suspendue ») ; `purge:resume` code 0 ; sonde `purge` **200**. C) sans jeton, jeton faux, sonde inconnue : **404** tous trois.
- **Sur le VPS Plesk :** mêmes gestes (`systemctl` en root, `purge:*` en SSH de l'abonnement), **hors de toute partie**, et le vrai critère est ailleurs : chaque alerte doit **arriver** chez le porteur par la supervision externe, par e-mail **et** par le second canal (`100` § 15, étape 32 du REPRISE) ; relever les heures de réception. Sans supervision, repli dégradé écrit comme tel (`100` § 15).

### Étape 3.3 — Purge de rétention et `purge_run`

- **But** : `100` § 14 : exécution synchrone (celle de la restauration, § 13.5 f), dépôt sur la file `default` (celui du planificateur, 02:10 UTC), verrou d'unicité, une ligne `purge_run` par (exécution, périmètre) même à zéro ligne.
- **Commandes** — [abo] :

```bash
set -uo pipefail
# [abo] Étape 3.3 — purge de rétention : exécution synchrone, puis dépôt sur la file default.
umask 027
PHP=/opt/plesk/php/8.4/bin/php
cd /var/www/vhosts/tripleframes-prod.test/tripleframes
date -u +%T
"$PHP" artisan schedule:list | grep -E 'RunRetentionPurge|prune-snapshots|room:archive-idle|WorkerHeartbeat|ReportBruteForce'
"$PHP" artisan purge:run --sync; echo "purge:run --sync code=$?"
date -u +%T
"$PHP" artisan purge:run; echo "purge:run (file default) code=$?"
"$PHP" artisan purge:run; echo "purge:run (second dépôt immédiat) code=$?"
```

Puis [root] :

```bash
set -uo pipefail
# [root] Étape 3.3 — contrôle de purge_run et de la sonde purge.
APP=/var/www/vhosts/tripleframes-prod.test/tripleframes
E="$APP/.env"
date -u +%T
MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' $E)" mysql --no-defaults --protocol=socket -u tripleframes_prod tripleframes_prod -e \
  "SELECT id, scope, status, rows_deleted, ran_at, started_at, finished_at, duration_ms, error FROM purge_run WHERE started_at >= NOW() - INTERVAL 10 MINUTE AND scope <> 'stale_lobby' ORDER BY id;"
journalctl -u tripleframes-worker@default --since "-5min" --no-pager -o cat | grep -E 'RunRetentionPurge' | tail -n 4
H=tripleframes-prod.test; R="--resolve $H:443:127.0.0.1"; CA=/etc/ssl/certs/ca.homestead.homestead.crt
TOKEN="$(sed -n 's/^OPS_PROBE_TOKEN=//p' "$E")"
printf 'purge : '; curl -s -w ' %{http_code}\n' --cacert $CA $R -H @<(printf 'X-Probe-Token: %s\n' "$TOKEN") "https://$H/ops/probe/purge"
unset TOKEN
```

- **Attendu et vérification** (13:27:06-13:27:12 UTC) : planificateur : battements (30 s et 1 min), `room:archive-idle` (`*/10`), `RunRetentionPurge` **02:10**, `backup:prune-snapshots` **02:50**, rapport de force brute le lundi 04:10 ; `purge:run --sync` : « Périmètres : 6. Lignes traitées : 0. », code 0 ; `purge:run` : « déposée sur la file default », code 0 ; second dépôt immédiat : « tient déjà le verrou d'unicité… aucune nouvelle purge déposée », **code 1** ; `purge_run` : deux séries de six lignes `completed` (`stale_room`, `orphan_player`, `framework_sessions`, `framework_failed_jobs`, `framework_reset_tokens`, `purge_run`), `rows_deleted = 0`, `error` NULL, 1 à 15 ms ; journal du worker `default` : `RunRetentionPurge … 25.01ms DONE` ; sonde `purge` `ok`. Zéro ligne supprimée : attendu sur une base de quelques heures (aucune session au-delà de sa durée de vie, aucun siège solo inactif depuis 24 h).
- **Sur le VPS Plesk :** identique en SSH de l'abonnement ; la requête de contrôle, en SSH de l'abonnement aussi (`mysql -u <utilisateur> -p <base>`, mot de passe à l'invite).

### Étape 3.4 — Instantané de la règle 12 (`backup:snapshot`)

- **But** : `100` § 13.1 : instantané local, non chiffré, vérifié ; `--if-pending` sans migration ; élagage.
- **Commandes** — [abo] :

```bash
set -uo pipefail
# [abo] Étape 3.4 — instantané de la règle 12, contrôle, élagage.
umask 027
PHP=/opt/plesk/php/8.4/bin/php
cd /var/www/vhosts/tripleframes-prod.test/tripleframes
D=/var/www/vhosts/tripleframes-prod.test/private/tripleframes/snapshots
"$PHP" artisan backup:snapshot; echo "backup:snapshot code=$?"
F="$(ls -1 "$D"/snapshot-*.sql.gz | tail -n 1)"
stat -c '%a %U:%G %s %n' "$F"
gzip -t "$F" && echo "gzip -t : OK"
zcat "$F" | tail -n 1
printf 'CREATE TABLE : %s ; tables à données (INSERT distincts) : %s\n' "$(zcat "$F" | grep -c '^CREATE TABLE')" "$(zcat "$F" | grep -oE '^INSERT INTO `[a-z_]+`' | sort -u | wc -l)"
for t in sessions cache cache_locks jobs job_batches failed_jobs password_reset_tokens; do
    printf '%s:%s ' "$t" "$(zcat "$F" | grep -c "^INSERT INTO \`$t\`")"
done; echo
"$PHP" artisan backup:snapshot --if-pending; echo "backup:snapshot --if-pending code=$?"
"$PHP" artisan backup:prune-snapshots; echo "backup:prune-snapshots code=$?"
ls -l "$D"
```

- **Attendu et vérification** (13:27:45 UTC) : « Instantané écrit et vérifié : …/snapshot-20260928T132745Z.sql.gz », **code 0** ; `600 tripleframes:tripleframes`, 30 315 octets ; `gzip -t : OK` ; dernière ligne `-- Dump completed on 2026-09-28 13:27:45` ; 45 `CREATE TABLE`, données de 20 tables ; **aucun `INSERT`** dans les sept tables exclues (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`) ; `--if-pending` : « Aucune migration en attente », code 0 ; élagage : 0 supprimé (tous les instantanés ont moins de 7 jours), code 0.
- **Sur le VPS Plesk :** identique, chemin `/var/www/vhosts/<DOMAINE>/private/tripleframes/snapshots`.

### Étape 3.5 — Clé de sauvegarde, stockage distant, configuration du script

- **But** : `100` § 13.4 : chiffrement **asymétrique** (`age`) ; la clé publique sur le serveur, la clé privée **jamais** ; stockage où la machine peut **déposer** sans lister, lire ni supprimer ; configuration hors dépôt dans `~/.config/tripleframes/backup.env` (`0600`).
- **Commandes** — [root] :

```bash
set -euo pipefail
# [root] Étape 3.5 — clé de sauvegarde (age), stockage distant simulé, configuration de l'abonnement.
H=/var/www/vhosts/tripleframes-prod.test
K=/root/tripleframes-hors-machine/backup-age-key.txt
test ! -e "$K"
(umask 077 && age-keygen -o "$K" 2> /dev/null)
stat -c '%a %U:%G %n' "$K"
grep -c '^AGE-SECRET-KEY-1' "$K"
# Clé publique seule, lisible par l'abonnement (elle n'est pas secrète).
age-keygen -y "$K" > "$H/.config/tripleframes/backup-recipient.txt"
chown tripleframes:tripleframes "$H/.config/tripleframes/backup-recipient.txt"
chmod 0640 "$H/.config/tripleframes/backup-recipient.txt"
grep -c '^age1[0-9a-z]\{58\}$' "$H/.config/tripleframes/backup-recipient.txt"

# Stockage distant SIMULÉ (propre à la répétition) : dépôt sans liste pour l'abonnement.
Rm=/srv/tripleframes-stockage-distant-simule
test ! -e "$Rm"
install -d -o root -g tripleframes -m 1730 "$Rm" "$Rm/hot" "$Rm/cold" "$Rm/cold/game"
stat -c '%a %U:%G %n' "$Rm" "$Rm/hot" "$Rm/cold" "$Rm/cold/game"

# Configuration du script (aucun secret sur la VM ; sur le VPS : clé d'écriture seule dans rclone.conf, adresse de battement).
sudo -u tripleframes -H bash -c 'umask 077 && cat > ~/.config/tripleframes/backup.env' <<'EOF'
# Sauvegarde hors machine (100 § 13.2-13.4). Aucun secret dans ce fichier sur la VM.
BACKUP_REMOTE_MODE=local
BACKUP_REMOTE_DIR=/srv/tripleframes-stockage-distant-simule
BACKUP_RCLONE_REMOTE=
BACKUP_AGE_RECIPIENTS_FILE=/var/www/vhosts/tripleframes-prod.test/.config/tripleframes/backup-recipient.txt
BACKUP_HEARTBEAT_URL=
EOF
stat -c '%a %U:%G %n' "$H/.config/tripleframes/backup.env"
# Contrôles d'accès du dépôt simulé.
sudo -u tripleframes ls "$Rm" > /dev/null 2>&1 && echo "tripleframes LISTE le dépôt (non attendu)" || echo "tripleframes : liste refusée (attendu)"
sudo -u vagrant ls "$Rm" > /dev/null 2>&1 && echo "vagrant LISTE le dépôt (non attendu)" || echo "vagrant : liste refusée (attendu)"
sudo -u tripleframes cat "$K" > /dev/null 2>&1 && echo "tripleframes LIT la clé privée (non attendu)" || echo "tripleframes : clé privée refusée (attendu)"
```

- **Attendu et vérification** (13:29 UTC) : clé privée `600 root:root`, une ligne `AGE-SECRET-KEY-1…` ; clé publique `age1…` (62 caractères) dans `~/.config/tripleframes/backup-recipient.txt` (`0640 tripleframes`) ; dépôt simulé et ses sous-répertoires `1730 root:tripleframes` ; `backup.env` en `600 tripleframes` ; l'abonnement **ne liste pas** le dépôt, `vagrant` non plus ; l'abonnement **ne lit pas** la clé privée.
- **Propre à la répétition** : clé privée générée **sur la VM**, sous `/root/tripleframes-hors-machine/` (simulation des deux exemplaires hors machine) ; dépôt `1730` (écriture et traversée pour le groupe, aucune liste, bit collant) : **approximation** d'une clé d'écriture seule — l'abonnement peut encore relire ou supprimer ses **propres** objets, ce qu'un vrai stockage objet lui refuse (clé sans droit de lecture ni de suppression, bucket versionné).
- **Sur le VPS Plesk :**
  1. **Stockage** (porteur, console du fournisseur) : bucket en UE, chez un fournisseur **distinct** de l'hébergeur, **versionné** (verrouillage d'objet si offert) ; cycle de vie : objets courants de `hot/` expirés à 30 jours, versions non courantes à 30 jours, `cold/` jamais expiré ; **deux clés** : celle du VPS limitée au **dépôt d'objets** sur ce bucket (ni lecture, ni liste, ni suppression), et celle du poste du porteur, capable de lire (restauration) et de supprimer (élagage des orphelins, J2).
  2. **Clé `age`** : générée **sur le poste**, jamais sur le VPS : `age-keygen -o backup-age-key.txt` (le fichier est la clé privée : deux exemplaires hors machine, inventaire scellé et gestionnaire de secrets, **avec `APP_KEY` et les codes de secours du second facteur**, `100` § 13.4) ; `age-keygen -y backup-age-key.txt` affiche la clé publique `age1…`, seule à partir sur le serveur : SSH de l'abonnement, `(umask 027 && nano ~/.config/tripleframes/backup-recipient.txt)`, une ligne.
  3. **`rclone`** (root, une fois) : `NEEDRESTART_MODE=l apt-get install --no-install-recommends -y rclone` (ou binaire officiel, version relevée) ; puis, en SSH de l'abonnement, `rclone config` (remote de type S3 du fournisseur, clé **d'écriture seule** du VPS) → `~/.config/rclone/rclone.conf` en `0600` ; vérifier que la clé ne liste pas : `rclone lsf <remote>:<bucket>` doit **échouer** (refus d'accès).
  4. **`backup.env`**, SSH de l'abonnement, `(umask 077 && nano ~/.config/tripleframes/backup.env)` : `BACKUP_REMOTE_MODE=rclone`, `BACKUP_RCLONE_REMOTE='<remote>:<bucket>'`, `BACKUP_AGE_RECIPIENTS_FILE='/var/www/vhosts/<DOMAINE>/.config/tripleframes/backup-recipient.txt'`, `BACKUP_HEARTBEAT_URL='<adresse de battement de la supervision>'` (secret : jamais ailleurs que dans ce fichier). **Valeurs entre apostrophes : le fichier est lu par bash** (`. "$CONF"`). Sans apostrophes, une adresse qui contient `&` (l'URL « push » d'Uptime Kuma, `…/api/push/<jeton>?status=up&msg=OK&ping=`) passe l'affectation en arrière-plan : la variable reste vide, le journal dit « battement non configuré » chaque nuit et la supervision alerte à tort. Un `;` ou un `$(` dans la valeur serait, lui, exécuté.
- **Correction du contrôle** (28/09, 14:00 UTC) : le point 4 ci-dessus donnait les valeurs sans apostrophes. Démonstration, [abo], adresse factice :

```bash
set -uo pipefail
T="$(mktemp)"
printf '%s\n' 'BACKUP_HEARTBEAT_URL=http://exemple.invalid/api/push/x?status=up&msg=OK&ping=' > "$T"
( . "$T"; wait; printf 'sans apostrophes : [%s]\n' "${BACKUP_HEARTBEAT_URL:-}" )
printf '%s\n' "BACKUP_HEARTBEAT_URL='http://exemple.invalid/api/push/x?status=up&msg=OK&ping='" > "$T"
( . "$T"; printf 'entre apostrophes : [%s]\n' "${BACKUP_HEARTBEAT_URL:-}" )
rm -f "$T"
```

  Résultat : `sans apostrophes : []`, puis `entre apostrophes : [http://exemple.invalid/api/push/x?status=up&msg=OK&ping=]`. Le `backup.env` de la VM n'est pas touché : ses valeurs (mode, chemins, battement vide) ne portent aucun caractère spécial. Script joué : `scratchpad/vm/exploitation/c3-test-backup-env.sh`. Lecture sans `source`, proposée pour L100-10 : écart n° 17.

### Étape 3.6 — Tier chaud : vidage, manifeste du disque `frames`, chiffrement, dépôt

- **But** : `100` § 13.2 : chaque jour, vidage (structure de toutes les tables, données hors des sept tables exclues), manifeste du disque `frames` (condensat, taille, chemin relatif de chaque fichier), chiffrement pour la clé publique, dépôt sous `hot/<AAAA-MM-JJ>/`, battement **en cas de succès seulement**.
- **Préfiguration** — `…/repetition-backup/backup-hot.sh` (hors dépôt ; le livrable de L100-10 est `ops/backup/backup-hot.sh`). Choix de la préfiguration, **proposé pour L100-10** : le vidage **réutilise `backup:snapshot`** (même contenu que § 13.2, gzip et ligne de fin vérifiés en PHP, mot de passe jamais en argument) au lieu d'un second appel à `mysqldump` écrit en shell ; un seul nouvel instantané exigé, sinon arrêt sans envoi ni battement.

```bash
#!/usr/bin/env bash
# Tier chaud quotidien (spec 100 § 13.2) — PRÉFIGURATION jouée par la
# répétition VM, hors dépôt : le livrable est ops/backup/backup-hot.sh (L100-10),
# qui appellera `php artisan backup:manifest` là où ce script calcule le
# manifeste lui-même.
#
# Utilisateur d'abonnement, tâche planifiée à 03:10 UTC (après la purge de
# 02:10 et l'élagage des instantanés de 02:50). Aucun secret en argument :
# destination et battement dans ~/.config/tripleframes/backup.env (0600) ;
# l'adresse de battement atteint curl par l'entrée standard (-K -).
#
# 1. vidage : `backup:snapshot` (structure de toutes les tables, données hors
#    des sept tables exclues, gzip vérifié en PHP) — même contenu que § 13.2 ;
# 2. manifeste du disque frames : condensat SHA-256, taille, chemin relatif ;
# 3. chiffrement pour la clé publique (age), envoi sous hot/<AAAA-MM-JJ>/ ;
# 4. battement sortant vers la supervision, en cas de succès SEULEMENT.
set -euo pipefail
umask 077

readonly PHP=/opt/plesk/php/8.4/bin/php
readonly APP="${TF_APP:-$HOME/tripleframes}"
readonly CONF="$HOME/.config/tripleframes/backup.env"

log() { printf '%s backup-hot %s\n' "$(date -u +%FT%TZ)" "$*"; }
envval() { sed -n "s/^$1=//p" "$APP/.env" | tail -n 1; }

# shellcheck source=/dev/null
. "$CONF"
: "${BACKUP_REMOTE_MODE:?}" "${BACKUP_AGE_RECIPIENTS_FILE:?}"
test -s "$BACKUP_AGE_RECIPIENTS_FILE"

upload() { # upload <fichier local> <clé distante>
    case "$BACKUP_REMOTE_MODE" in
        local) # stockage distant SIMULÉ (répétition VM)
            mkdir -p "$BACKUP_REMOTE_DIR/$(dirname "$2")"
            cp --no-clobber "$1" "$BACKUP_REMOTE_DIR/$2"
            cmp -s "$1" "$BACKUP_REMOTE_DIR/$2" ;;
        rclone) # stockage objet réel, clé d'écriture seule : aucune lecture préalable
            rclone copyto --no-check-dest --s3-no-check-bucket --s3-no-head "$1" "$BACKUP_RCLONE_REMOTE/$2" ;;
        *) log "mode d'envoi inconnu : $BACKUP_REMOTE_MODE"; return 1 ;;
    esac
}

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DAY="$(date -u +%F)"
SNAPDIR="$(envval BACKUP_SNAPSHOT_DIR)"
FRAMES="$(envval FRAMES_DISK_ROOT)"
test -d "$SNAPDIR" && test -d "$FRAMES"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

log "début ($STAMP)"

# 1. Vidage, par l'instantané de la règle 12 (nouveau fichier exigé, un seul).
ls -1 "$SNAPDIR" | sort > "$WORK/avant"
if ! ( cd "$APP" && "$PHP" artisan backup:snapshot --no-ansi ) > "$WORK/snapshot.out" 2>&1; then
    cat "$WORK/snapshot.out"; log "échec de backup:snapshot : rien n'est envoyé, aucun battement"; exit 1
fi
ls -1 "$SNAPDIR" | sort > "$WORK/apres"
mapfile -t NEW < <(comm -13 "$WORK/avant" "$WORK/apres" | grep -E '^snapshot-[0-9]{8}T[0-9]{6}Z\.sql\.gz$')
[ "${#NEW[@]}" -eq 1 ] || { log "instantané introuvable ou ambigu"; exit 1; }
DUMP="$SNAPDIR/${NEW[0]}"
log "vidage : ${NEW[0]} ($(stat -c %s "$DUMP") octets)"

# 2. Manifeste du disque frames (préfigure `backup:manifest`).
( cd "$FRAMES" && find . -type f -printf '%P\0' | sort -z | while IFS= read -r -d '' p; do
      printf '%s\t%s\t%s\n' "$(sha256sum -- "$p" | cut -d' ' -f1)" "$(stat -c %s -- "$p")" "$p"
  done ) > "$WORK/manifest.tsv"
log "manifeste : $(wc -l < "$WORK/manifest.tsv") fichiers"
gzip -9n < "$WORK/manifest.tsv" > "$WORK/manifest.tsv.gz"

# 3. Chiffrement pour la clé publique, puis envoi.
age -R "$BACKUP_AGE_RECIPIENTS_FILE" -o "$WORK/db-$STAMP.sql.gz.age" "$DUMP"
age -R "$BACKUP_AGE_RECIPIENTS_FILE" -o "$WORK/frames-manifest-$STAMP.tsv.gz.age" "$WORK/manifest.tsv.gz"
upload "$WORK/db-$STAMP.sql.gz.age" "hot/$DAY/db-$STAMP.sql.gz.age"
upload "$WORK/frames-manifest-$STAMP.tsv.gz.age" "hot/$DAY/frames-manifest-$STAMP.tsv.gz.age"
log "envoyé : hot/$DAY/db-$STAMP.sql.gz.age ($(stat -c %s "$WORK/db-$STAMP.sql.gz.age") octets), hot/$DAY/frames-manifest-$STAMP.tsv.gz.age"

# 4. Battement (interrupteur d'homme mort de la supervision), succès seulement.
if [ -n "${BACKUP_HEARTBEAT_URL:-}" ]; then
    # Adresse secrète : jamais dans l'argv (liste des processus lisible des voisins) ;
    # printf est interne à bash, curl lit l'adresse dans sa configuration (-K -).
    printf 'url = "%s"\n' "$BACKUP_HEARTBEAT_URL" | curl -fsS -m 10 --retry 3 -o /dev/null -K -
    log "battement envoyé"
else
    log "battement non configuré (BACKUP_HEARTBEAT_URL vide)"
fi
log "terminé"
```

- **Commandes** — [poste], Git Bash, puis [root] (pose), puis [abo] (premier passage) :

```bash
scp -i <clé> <scratchpad>/vm/exploitation/backup-hot.sh <scratchpad>/vm/exploitation/backup-cold.sh vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/
```

```bash
H=/var/www/vhosts/tripleframes-prod.test
install -d -o tripleframes -g tripleframes -m 0750 $H/repetition-backup
for f in backup-hot.sh backup-cold.sh; do
    install -o tripleframes -g tripleframes -m 0750 /home/vagrant/tripleframes-repetition/$f $H/repetition-backup/$f
    bash -n $H/repetition-backup/$f && echo "$f : bash -n OK"
done
```

```bash
umask 027
bash ~/repetition-backup/backup-hot.sh; echo "backup-hot code=$?"
```

- **Attendu et vérification** (13:30:52-13:30:53 UTC) : « vidage : snapshot-20260928T133052Z.sql.gz (30334 octets) » ; « manifeste : 174 fichiers » (87 fichiers de jeu, 87 masters) ; « envoyé : hot/2026-09-28/db-20260928T133052Z.sql.gz.age (30534 octets), hot/2026-09-28/frames-manifest-20260928T133052Z.tsv.gz.age » ; « battement non configuré » ; **code 0**, en une seconde. Dans le dépôt simulé : `hot/2026-09-28/` (`0700 tripleframes`), deux objets `0600`.
- **Écart** : battement de la supervision non envoyé (aucune supervision : `BACKUP_HEARTBEAT_URL` vide) ; la sonde « sauvegarde de moins de 26 h » (`100` § 15) n'est donc pas prouvée par la répétition.
- **Correction du contrôle** (28/09, 13:59-14:00 UTC) : la version jouée à 13:30 (sha256 `89ad0d38…`) passait l'adresse de battement **en argument** de `curl` (`curl -fsS -m 10 --retry 3 -o /dev/null "$BACKUP_HEARTBEAT_URL"`). Or la liste des processus d'un VPS mutualisé est lisible des voisins (`/proc/<pid>/cmdline`, sans `hidepid`). Un voisin qui relève l'adresse peut envoyer le battement lui-même et masquer une sauvegarde en échec : l'interrupteur d'homme mort, seule alerte de sauvegarde de § 15, ne protège plus rien. C'est la raison pour laquelle `100` § 13.1 écarte `MYSQL_PWD`. Le bloc ci-dessus est désormais le correct : `printf` (interne à bash, donc aucun processus) écrit `url = "<adresse>"` sur l'entrée standard de `curl -K -`. Avec `pipefail`, un échec de `curl` arrête toujours le script. Réinstallation, [poste], Git Bash, puis [root] :

```bash
scp -i <clé> <scratchpad>/vm/exploitation/backup-hot.sh vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/backup-hot.sh
```

```bash
set -euo pipefail
# [root] Correction du contrôle (phase 3) — réinstallation de backup-hot.sh (battement par curl -K -).
H=/var/www/vhosts/tripleframes-prod.test
install -o tripleframes -g tripleframes -m 0750 /home/vagrant/tripleframes-repetition/backup-hot.sh $H/repetition-backup/backup-hot.sh
bash -n $H/repetition-backup/backup-hot.sh && echo "backup-hot.sh : bash -n OK"
stat -c '%a %U:%G %n' $H/repetition-backup/backup-hot.sh
sha256sum /home/vagrant/tripleframes-repetition/backup-hot.sh $H/repetition-backup/backup-hot.sh
grep -n 'curl' $H/repetition-backup/backup-hot.sh
```

  Vérification, [abo] : la section 4 du script **installé** est jouée seule (ni vidage, ni envoi), contre des adresses factices en boucle locale :

```bash
set -uo pipefail
umask 027
B=/var/www/vhosts/tripleframes-prod.test/repetition-backup/backup-hot.sh
T="$(mktemp -d)"
{ echo 'set -euo pipefail'
  echo 'log() { printf "%s backup-hot %s\n" "$(date -u +%FT%TZ)" "$*"; }'
  sed -n '/^# 4\. Battement/,/^log "terminé"$/p' "$B"; } > "$T/battement.sh"
ss -ltn | grep -qE '127\.0\.0\.1:1809[89]\b' && { echo "port 18098 ou 18099 occupé : arrêt"; exit 1; }
# (a) succès, adresse à « & » (forme d'une URL push) ; (b) réponse 404 : échec du script, pas de « battement envoyé ».
(cd "$T" && exec python3 -m http.server 18099 --bind 127.0.0.1) > "$T/http.log" 2>&1 &
HP=$!
sleep 1
BACKUP_HEARTBEAT_URL='http://127.0.0.1:18099/?status=up&msg=OK&ping=' bash "$T/battement.sh"; echo "(a) succès : code=$?"
BACKUP_HEARTBEAT_URL='http://127.0.0.1:18099/absent' bash "$T/battement.sh"; echo "(b) 404 : code=$?"
kill "$HP"
echo "requêtes reçues :"; grep -oE '"GET [^"]*" [0-9]+' "$T/http.log"
# (c) argv pendant l'envoi : écouteur qui ne répond jamais, lecture de /proc/<pid>/cmdline de curl.
nc -l 127.0.0.1 18098 > /dev/null &
NP=$!
sleep 0.5
BACKUP_HEARTBEAT_URL='http://127.0.0.1:18098/jeton-secret-de-test' bash "$T/battement.sh" > "$T/c.out" 2>&1 &
BP=$!
sleep 1
for p in $(pgrep -u tripleframes -x curl); do printf '(c) argv de curl (pid %s) : ' "$p"; tr '\0' ' ' < "/proc/$p/cmdline"; echo; done
printf '(c) processus dont l argv contient le jeton : %s\n' "$(grep -lF -f <(printf 'jeton-secret-de-test\n') /proc/[0-9]*/cmdline 2>/dev/null | wc -l)"
kill "$NP" 2>/dev/null
wait "$BP"; echo "(c) sans réponse : code=$?"; cat "$T/c.out"
rm -rf "$T"
ss -ltn | grep -cE '127\.0\.0\.1:1809[89]\b'
```

  Résultat : `bash -n OK` ; les deux copies en `224d93a2…`, celle de l'abonnement en `750 tripleframes:tripleframes`. (a) « battement envoyé », code 0, requête reçue intacte, `&` compris (`"GET /?status=up&msg=OK&ping= HTTP/1.1" 200`). (b) `curl: (22) … 404`, code 22, sans « battement envoyé ». (c) argv de `curl` pendant l'envoi : `curl -fsS -m 10 --retry 3 -o /dev/null -K -`, et **0** processus dont l'argv contient l'adresse ; code 52 à la coupure de l'écouteur. Ports 18098 et 18099 libérés (le `0` final ; ce `grep -c` rend le code 1). Le tier chaud n'est **pas** rejoué : il aurait laissé un instantané local de plus (écart n° 17). Scripts joués : `scratchpad/vm/exploitation/c3-reinstall-hot.sh` et `c3-test-battement.sh`.
- **Sur le VPS Plesk :** le script livré par L100-10, depuis le checkout (`bash /var/www/vhosts/<DOMAINE>/tripleframes/ops/backup/backup-hot.sh`) ; à défaut, cette préfiguration, posée hors du checkout (`~/bin/`, `0750`), en mode `rclone` (options `--s3-no-check-bucket --s3-no-head --no-check-dest` **à confirmer selon le fournisseur** : aucune lecture préalable, que la clé interdit). Premier passage en SSH de l'abonnement ; la supervision doit recevoir le battement (relever l'heure). Contrôle côté stockage depuis le **poste** (clé de lecture) : `rclone lsf <remote poste>:<bucket>/hot/<AAAA-MM-JJ>/`.

### Étape 3.7 — Tier froid, puis tâches planifiées

- **But** : `100` § 13.3 : chaque dérivé publié envoyé **une seule fois**, chiffré, adressé par son condensat ; puis les deux tâches planifiées (tier chaud quotidien à 03:10 UTC, tier froid au moins hebdomadaire).
- **Préfiguration** — `…/repetition-backup/backup-cold.sh` (hors dépôt ; livrable de L100-10 : `ops/backup/backup-cold.sh`, qui lira `backup:manifest --cold`) :

```bash
#!/usr/bin/env bash
# Tier froid (spec 100 § 13.3) — PRÉFIGURATION jouée par la répétition VM,
# hors dépôt : le livrable est ops/backup/backup-cold.sh (L100-10), qui lira
# `php artisan backup:manifest --cold` là où ce script interroge la base.
#
# Périmètre = une requête : le fichier de jeu (`game_path`) de chaque frame
# `published`. Chaque dérivé est vérifié (SHA-256 = `published_hash`), chiffré
# pour la clé publique et envoyé UNE SEULE FOIS sous
# cold/game/<published_hash>.webp.age ; la liste locale des condensats déjà
# envoyés remplace la lecture du stockage, que la clé d'écriture seule interdit.
set -euo pipefail
umask 077

readonly PHP=/opt/plesk/php/8.4/bin/php
readonly APP="${TF_APP:-$HOME/tripleframes}"
readonly CONF="$HOME/.config/tripleframes/backup.env"
readonly SENT="$HOME/.config/tripleframes/backup-cold-sent.txt"

log() { printf '%s backup-cold %s\n' "$(date -u +%FT%TZ)" "$*"; }
envval() { sed -n "s/^$1=//p" "$APP/.env" | tail -n 1; }

# shellcheck source=/dev/null
. "$CONF"
: "${BACKUP_REMOTE_MODE:?}" "${BACKUP_AGE_RECIPIENTS_FILE:?}"
test -s "$BACKUP_AGE_RECIPIENTS_FILE"

upload() { # upload <fichier local> <clé distante>
    case "$BACKUP_REMOTE_MODE" in
        local)
            mkdir -p "$BACKUP_REMOTE_DIR/$(dirname "$2")"
            cp --no-clobber "$1" "$BACKUP_REMOTE_DIR/$2"
            cmp -s "$1" "$BACKUP_REMOTE_DIR/$2" ;;
        rclone)
            rclone copyto --no-check-dest --s3-no-check-bucket --s3-no-head "$1" "$BACKUP_RCLONE_REMOTE/$2" ;;
        *) log "mode d'envoi inconnu : $BACKUP_REMOTE_MODE"; return 1 ;;
    esac
}

FRAMES="$(envval FRAMES_DISK_ROOT)"
test -d "$FRAMES"
touch "$SENT"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Périmètre du tier froid (préfigure `backup:manifest --cold`) : « <condensat> <chemin relatif> ».
( cd "$APP" && "$PHP" artisan tinker --no-ansi --execute='
foreach (App\Models\Frame::query()->where("availability", "published")->whereNotNull("game_path")->orderBy("id")->get(["published_hash", "game_path"]) as $f) {
    echo $f->published_hash, " ", $f->game_path, PHP_EOL;
}' ) > "$WORK/cold.txt"
grep -qvE '^[0-9a-f]{64} game/[0-9a-z/]+\.webp$' "$WORK/cold.txt" && { log "périmètre illisible"; exit 1; }

total=0; deja=0; envoyes=0; defauts=0
while read -r hash path; do
    total=$((total + 1))
    if grep -qx "$hash" "$SENT"; then deja=$((deja + 1)); continue; fi
    if [ ! -f "$FRAMES/$path" ] || [ "$(sha256sum < "$FRAMES/$path" | cut -d' ' -f1)" != "$hash" ]; then
        defauts=$((defauts + 1)); log "manquant ou altéré : $path"; continue
    fi
    age -R "$BACKUP_AGE_RECIPIENTS_FILE" -o "$WORK/$hash.webp.age" "$FRAMES/$path"
    upload "$WORK/$hash.webp.age" "cold/game/$hash.webp.age"
    rm -f "$WORK/$hash.webp.age"
    printf '%s\n' "$hash" >> "$SENT"
    envoyes=$((envoyes + 1))
done < "$WORK/cold.txt"

log "frames publiées : $total ; déjà envoyées : $deja ; envoyées : $envoyes ; manquantes ou altérées : $defauts"
[ "$defauts" -eq 0 ]
```

- **Commandes** — [abo] :

```bash
umask 027
bash ~/repetition-backup/backup-cold.sh; echo "backup-cold code=$?"
bash ~/repetition-backup/backup-cold.sh; echo "backup-cold (rejeu) code=$?"
wc -l < ~/.config/tripleframes/backup-cold-sent.txt; stat -c '%a %n' ~/.config/tripleframes/backup-cold-sent.txt
```

Puis [root], tâches planifiées de l'abonnement et rejeu de la ligne du tier chaud dans l'environnement minimal de cron :

```bash
set -euo pipefail
# [root] Étape 3.7 — tâches planifiées des tiers chaud et froid, puis rejeu du tier chaud dans l'environnement de cron.
H=/var/www/vhosts/tripleframes-prod.test
B=$H/repetition-backup
L=$H/tripleframes/storage/logs/backup.log
crontab -l -u tripleframes > /tmp/tf-cron.$$
grep -q 'backup-hot.sh' /tmp/tf-cron.$$ && { echo "déjà posé : arrêt"; rm -f /tmp/tf-cron.$$; exit 1; }
cat >> /tmp/tf-cron.$$ <<EOF
10 3 * * * umask 027; /bin/bash $B/backup-hot.sh >> $L 2>&1
20 3 * * 0 umask 027; /bin/bash $B/backup-cold.sh >> $L 2>&1
EOF
crontab -u tripleframes /tmp/tf-cron.$$
rm -f /tmp/tf-cron.$$
crontab -l -u tripleframes
# Rejeu de la ligne du tier chaud dans l'environnement minimal de cron (PATH=/usr/bin:/bin, sh).
sudo -u tripleframes env -i HOME=$H LOGNAME=tripleframes USER=tripleframes PATH=/usr/bin:/bin SHELL=/bin/sh \
    /bin/sh -c "umask 027; /bin/bash $B/backup-hot.sh >> $L 2>&1"; echo "code (environnement de cron)=$?"
tail -n 6 "$L"
stat -c '%a %U:%G %n' "$L"
```

- **Attendu et vérification** (13:30:53 et 13:31:37 UTC) : premier passage : « frames publiées : 85 ; déjà envoyées : 81 ; envoyées : 4 ; manquantes ou altérées : 0 », code 0 ; rejeu : « déjà envoyées : 85 ; envoyées : 0 », code 0 ; liste locale : 4 condensats, `600`. **Lecture** : 85 frames publiées, **4 fichiers distincts** — les 82 frames du catalogue de démonstration partagent un seul fichier (même image de bruit, même `published_hash`), les 3 frames curées ont chacune le leur ; l'adressage par condensat envoie donc 4 objets (`cold/game/<condensat>.webp.age`, 8 Ko à 115 Ko), et « déjà envoyées » compte aussi les frames dont l'objet vient de partir dans le même passage. Crontab : les deux lignes d'origine (`MAILTO=""`, `schedule:run`), plus `10 3 * * *` (tier chaud) et `20 3 * * 0` (tier froid, dimanche) ; rejeu sous `env -i … PATH=/usr/bin:/bin` : code 0, second jeu d'objets `hot/2026-09-28/…T133137Z…` ; `storage/logs/backup.log` en `640`.
- **Écart** : tier froid planifié **hebdomadaire** (règle de la décision 18) ; le passage **quotidien**, qui seul tient les 24 h de perte pour la curation, reste la proposition N100-2, à trancher par le porteur (`100` § 13.3) : il suffit alors de remplacer `20 3 * * 0` par `20 3 * * *`.
- **Sur le VPS Plesk :** Sites Web et domaines › `<DOMAINE>` › Tâches planifiées › Ajouter une tâche › « Exécuter une commande », utilisateur de l'abonnement, deux tâches, à consigner dans `ops/plesk/settings.md` § 4 par L100-10 (qui n'y porte aujourd'hui que le nom et la fréquence) : `umask 027; /bin/bash /var/www/vhosts/<DOMAINE>/tripleframes/ops/backup/backup-hot.sh >> /var/www/vhosts/<DOMAINE>/tripleframes/storage/logs/backup.log 2>&1` chaque jour à 03:10 UTC, et `umask 027; /bin/bash /var/www/vhosts/<DOMAINE>/tripleframes/ops/backup/backup-cold.sh >> /var/www/vhosts/<DOMAINE>/tripleframes/storage/logs/backup.log 2>&1` chaque dimanche à 03:20 UTC (ou chaque jour, si N100-2 est accepté) ; heures converties dans le fuseau du serveur relevé par `timedatectl`, dans lequel Plesk lit les heures. Notification : celle de `ops/plesk/settings.md` § 4 (**sortie non notifiée**) ; « seulement en cas d'erreur » est proposé comme écart n° 22, à reporter dans `settings.md`. **Le jour même**, bouton « Exécuter maintenant » de la tâche du tier chaud, ou, en SSH de l'abonnement, rejeu dans l'environnement minimal de cron (comme sur la VM) :

```bash
env -i HOME="$HOME" LOGNAME="$USER" USER="$USER" PATH=/usr/bin:/bin SHELL=/bin/sh /bin/sh -c 'umask 027; /bin/bash /var/www/vhosts/<DOMAINE>/tripleframes/ops/backup/backup-hot.sh >> /var/www/vhosts/<DOMAINE>/tripleframes/storage/logs/backup.log 2>&1'; echo $?
```

  Attendu : code 0, puis objets du jour visibles depuis le poste (`rclone lsf <remote poste>:<bucket>/hot/<AAAA-MM-JJ>/`). Un binaire hors de `/usr/bin:/bin` (`age` ou `rclone` posé à la main, par exemple) se voit ainsi **avant** la première nuit, et non après une nuit sans sauvegarde. Vérifier aussi le lendemain matin : `tail storage/logs/backup.log`, battement reçu par la supervision, objets du jour présents (depuis le poste).
- **Correction du contrôle** (28/09, 14:00 UTC, bloc documenté seulement, rien à rattraper sur la VM) : la ligne « Sur le VPS Plesk » décidait « Notification Plesk : seulement en cas d'erreur », contre `ops/plesk/settings.md` § 4 (« sortie non notifiée »), alors que le runbook ne décide rien. Elle présentait aussi les deux lignes de commande comme déjà « consignées dans `ops/plesk/settings.md` », qui n'y porte que le nom et la fréquence (§ 4 : « Les deux tâches de sauvegarde naissent avec L100-10 […], qui les consigne ici »). Enfin, elle abandonnait le rejeu dans l'environnement de cron fait sur la VM : sur le VPS, un binaire mal placé ne se serait vu qu'après une nuit sans sauvegarde. Les trois points sont réécrits ci-dessus.

### Étape 3.8 — Contrôle de lisibilité du dernier tier chaud (§ 13.5, point 1)

- **But** : « téléchargement du dernier tier chaud sur le poste, déchiffrement, test d'intégrité, comparaison du manifeste au nombre de frames » — et, ajouté ici, chaque objet froid déchiffré au bon condensat.
- **Commandes** — [root], **tient lieu du poste** (clé privée) :

```bash
set -euo pipefail
# [root, tient lieu du poste] Étape 3.8 — contrôle de lisibilité du dernier tier chaud (100 § 13.5, point 1).
umask 077
Rm=/srv/tripleframes-stockage-distant-simule
K=/root/tripleframes-hors-machine/backup-age-key.txt
W=/root/tripleframes-lisibilite
test ! -e "$W"; mkdir -m 0700 "$W"
# « Téléchargement » : dernier jour, dernier vidage et son manifeste (même horodatage).
DAY="$(ls -1 "$Rm/hot" | sort | tail -n 1)"
DB="$(ls -1 "$Rm/hot/$DAY" | grep -E '^db-.*\.sql\.gz\.age$' | sort | tail -n 1)"
STAMP="${DB#db-}"; STAMP="${STAMP%.sql.gz.age}"
cp "$Rm/hot/$DAY/$DB" "$Rm/hot/$DAY/frames-manifest-$STAMP.tsv.gz.age" "$W/"
echo "dernier tier chaud : hot/$DAY/$DB"
# Déchiffrement par la clé privée (hors machine), intégrité.
age -d -i "$K" -o "$W/db.sql.gz" "$W/$DB"
age -d -i "$K" -o "$W/manifest.tsv.gz" "$W/frames-manifest-$STAMP.tsv.gz.age"
gzip -t "$W/db.sql.gz" && echo "gzip -t vidage : OK"
gzip -t "$W/manifest.tsv.gz" && echo "gzip -t manifeste : OK"
printf 'fins de vidage : %s ; dernière ligne : %s\n' "$(zcat "$W/db.sql.gz" | grep -c '^-- Dump completed')" "$(zcat "$W/db.sql.gz" | tail -n 1)"
printf 'CREATE TABLE : %s\n' "$(zcat "$W/db.sql.gz" | grep -c '^CREATE TABLE')"
# Manifeste contre nombre de frames du vidage (lignes de l'INSERT de la table frame).
frames_dump="$(zcat "$W/db.sql.gz" | grep '^INSERT INTO `frame` ' | awk '{ n += gsub(/\),\(/, "") + 1 } END { print n + 0 }')"
game_manifest="$(zcat "$W/manifest.tsv.gz" | awk -F'\t' '$3 ~ /^game\//' | wc -l)"
master_manifest="$(zcat "$W/manifest.tsv.gz" | awk -F'\t' '$3 ~ /^master\//' | wc -l)"
echo "frames dans le vidage : $frames_dump ; fichiers de jeu au manifeste : $game_manifest ; masters : $master_manifest"
[ "$frames_dump" -eq "$game_manifest" ] && echo "manifeste = nombre de frames : OK" || echo "ÉCART manifeste / frames"
# Tier froid : chaque objet se déchiffre, et son condensat est son nom.
ok=0; ko=0
for o in "$Rm"/cold/game/*.webp.age; do
    h="$(basename "$o" .webp.age)"
    if [ "$(age -d -i "$K" "$o" | sha256sum | cut -d' ' -f1)" = "$h" ]; then ok=$((ok + 1)); else ko=$((ko + 1)); fi
done
echo "tier froid : $ok objets lisibles au bon condensat, $ko en défaut"
rm -rf "$W"
test ! -e "$W" && echo "copie déchiffrée supprimée"
```

- **Attendu et vérification** (13:31 UTC) : dernier tier chaud `hot/2026-09-28/db-20260928T133137Z.sql.gz.age` ; `gzip -t` OK pour le vidage et le manifeste ; **2 lignes de fin de vidage** (deux passes : structure, puis données), la dernière `-- Dump completed on 2026-09-28 13:31:38` ; 45 `CREATE TABLE` ; **87 frames dans le vidage = 87 fichiers de jeu au manifeste** (et 87 masters) ; tier froid : 4 objets lisibles au bon condensat, 0 en défaut ; copie déchiffrée supprimée.
- **Sur le VPS Plesk :** sur le **poste**, jamais sur le serveur : `rclone copy <remote poste>:<bucket>/hot/<AAAA-MM-JJ>/ <répertoire local>` (et `cold/game/`), puis les mêmes commandes avec `age -d -i <chemin de la clé privée>` (Git Bash + `age` pour Windows, ou tout poste Linux ; voir « Impasses » : le PHP du poste est bloqué par Device Guard, vérifier qu'`age.exe` ne l'est pas **avant** d'en avoir besoin). Joué **le lendemain de la première sauvegarde réelle, avant de poursuivre la curation** (étape 30 du REPRISE). Copies déchiffrées supprimées ensuite (elles portent des données personnelles).

### Étape 3.9 — Restauration chronométrée sur une cible jetable (§ 13.5, point 2)

- **But** : le déroulé normatif (a) → (h) de `100` § 13.5, sur une cible **jetable** : base `tripleframes_restore_tmp` et son utilisateur limité à elle seule, racines `…/private/restore-tmp/{frames,snapshots,incoming}`, copie de l'application `…/restore-tmp-app` avec son propre `.env` — **`APP_KEY` lue dans l'exemplaire hors machine** (c'est ce qui prouve que la clé conservée est la bonne), index Redis inutilisés (8 et 9), diffusion `log` (jamais vers le Reverb de production), sans jeton TMDB ni jeton de sonde —, puis connexion de l'administrateur **second facteur compris**. Chronométrée de la décision de restaurer au dernier contrôle vert.
- **Garde** : l'utilisateur MySQL de la cible n'a de droits que sur `tripleframes_restore_tmp` ; aucune commande de la copie ne peut atteindre la base de production. Noms choisis pour ne jamais commencer par le chemin de déploiement (`FRAMES_DISK_ROOT` et `BACKUP_SNAPSHOT_DIR` y seraient refusés) ni par celui de la copie.
- **Commandes** — partie 1, [root] ((a) « téléchargement » et déchiffrement — root tient lieu du poste —, base et utilisateur jetables, racines, fichiers remis à l'abonnement, copie de l'application depuis l'artefact `deploy`, `.env` de la cible) :

```bash
set -euo pipefail
# [root] Étape 3.9, partie 1 — restauration chronométrée : (a) téléchargement et
# déchiffrement (root tient lieu du poste), préparation de la cible jetable.
umask 077
H=/var/www/vhosts/tripleframes-prod.test
Rm=/srv/tripleframes-stockage-distant-simule
K=/root/tripleframes-hors-machine/backup-age-key.txt
W=/root/tripleframes-restauration
T=$H/private/restore-tmp
A=$H/restore-tmp-app
mark() { printf '%s %s\n' "$(date -u +%T.%3N)" "$*" | tee -a "$W/chrono.log"; }

test ! -e "$W" && test ! -e "$T" && test ! -e "$A"
mkdir -m 0700 "$W" "$W/cold"
mark "T0 décision de restaurer"

# (a) Dernier tier chaud et tous les objets froids, déchiffrés par la clé privée.
DAY="$(ls -1 "$Rm/hot" | sort | tail -n 1)"
DB="$(ls -1 "$Rm/hot/$DAY" | grep -E '^db-.*\.sql\.gz\.age$' | sort | tail -n 1)"
STAMP="${DB#db-}"; STAMP="${STAMP%.sql.gz.age}"
age -d -i "$K" -o "$W/db.sql.gz" "$Rm/hot/$DAY/$DB"
age -d -i "$K" -o "$W/manifest.tsv.gz" "$Rm/hot/$DAY/frames-manifest-$STAMP.tsv.gz.age"
gzip -t "$W/db.sql.gz"; gzip -t "$W/manifest.tsv.gz"
for o in "$Rm"/cold/game/*.webp.age; do
    h="$(basename "$o" .webp.age)"
    age -d -i "$K" -o "$W/cold/$h.webp" "$o"
    [ "$(sha256sum < "$W/cold/$h.webp" | cut -d' ' -f1)" = "$h" ]
done
mark "(a) déchiffré : hot/$DAY/$DB, manifeste, $(ls "$W/cold" | wc -l) objets froids"

# Cible jetable : base et utilisateur dédiés, limités à cette base.
RST_PASSWORD="$(openssl rand -hex 24)"
printf 'DB_PASSWORD=%s\n' "$RST_PASSWORD" > "$W/rst.env"
printf "CREATE DATABASE tripleframes_restore_tmp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER 'tripleframes_restore_tmp'@'localhost' IDENTIFIED BY '%s';\nGRANT ALL PRIVILEGES ON tripleframes_restore_tmp.* TO 'tripleframes_restore_tmp'@'localhost';\n" "$RST_PASSWORD" | mysql
mysql -N -e "SHOW GRANTS FOR 'tripleframes_restore_tmp'@'localhost';"

# Racines jetables sous le répertoire privé, et « envoi » des fichiers déchiffrés à l'abonnement.
install -d -o tripleframes -g tripleframes -m 0700 "$T" "$T/frames" "$T/snapshots" "$T/incoming" "$T/incoming/cold"
install -o tripleframes -g tripleframes -m 0600 "$W/db.sql.gz" "$W/manifest.tsv.gz" "$T/incoming/"
install -o tripleframes -g tripleframes -m 0600 "$W"/cold/*.webp "$T/incoming/cold/"

# Copie de l'application : l'artefact deploy courant, jamais le checkout de production.
install -d -o tripleframes -g tripleframes -m 0750 "$A"
sudo -u tripleframes -H bash -c "umask 027; git --git-dir=$H/git/tripleframes.git archive deploy | tar -x -C $A"
sudo -u tripleframes -H bash -c "umask 077; cp $H/tripleframes/.env $A/.env"

# .env de la cible : APP_KEY lue dans l'exemplaire hors machine, base, racines, index Redis inutilisés.
ENVF="$A/.env"
setenv() {
    TFK="$1" TFV="$2" awk 'BEGIN { k = ENVIRON["TFK"]; v = ENVIRON["TFV"]; done = 0 }
        !done && ($0 ~ ("^" k "=") || $0 ~ ("^# " k "=")) { print k "=" v; done = 1; next }
        { print }
        END { if (!done) print k "=" v }' "$ENVF" > "$ENVF.new"
    cat "$ENVF.new" > "$ENVF"; rm -f "$ENVF.new"
}
setenv APP_KEY "$(sed -n 's/^APP_KEY=//p' /root/tripleframes-hors-machine/app-key.env)"
setenv APP_URL "http://127.0.0.1:18080"
setenv DB_DATABASE tripleframes_restore_tmp
setenv DB_USERNAME tripleframes_restore_tmp
setenv DB_PASSWORD "$RST_PASSWORD"
setenv FRAMES_DISK_ROOT "$T/frames"
setenv BACKUP_SNAPSHOT_DIR "$T/snapshots"
setenv REDIS_DB 8
setenv REDIS_CACHE_DB 9
setenv BROADCAST_CONNECTION log
setenv SESSION_SECURE_COOKIE false
setenv OPS_PROBE_TOKEN ""
setenv TMDB_API_READ_ACCESS_TOKEN ""
unset RST_PASSWORD
stat -c '%a %U:%G %n' "$ENVF"
grep -E '^(APP_URL|DB_DATABASE|DB_USERNAME|FRAMES_DISK_ROOT|BACKUP_SNAPSHOT_DIR|REDIS_DB|REDIS_CACHE_DB|REDIS_PORT|BROADCAST_CONNECTION|SESSION_SECURE_COOKIE|QUEUE_CONNECTION|CACHE_STORE)=' "$ENVF"
cmp -s <(grep '^APP_KEY=' "$ENVF") /root/tripleframes-hors-machine/app-key.env && echo "APP_KEY = exemplaire hors machine"
cmp -s <(grep '^APP_KEY=' "$ENVF") <(grep '^APP_KEY=' "$H/tripleframes/.env") && echo "APP_KEY = production"
mark "cible jetable prête (base, racines, copie de l'application, .env)"
```

Partie 2, [abo] ((b) à (g), puis serveur HTTP temporaire en boucle locale pour (h)) :

```bash
set -euo pipefail
# [abo] Étape 3.9, partie 2 — restauration chronométrée : (b) à (g) sur la cible jetable, puis serveur temporaire pour (h).
umask 027
PHP=/opt/plesk/php/8.4/bin/php
H=/var/www/vhosts/tripleframes-prod.test
T=$H/private/restore-tmp
A=$H/restore-tmp-app
mark() { printf '%s %s\n' "$(date -u +%T.%3N)" "$*"; }
cd "$A"

# Garde : la copie vise la base jetable, jamais la production.
grep -qx 'DB_DATABASE=tripleframes_restore_tmp' .env && grep -qx 'DB_USERNAME=tripleframes_restore_tmp' .env
"$PHP" /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
"$PHP" artisan about --only=environment | grep -E 'Environment|Debug Mode|PHP Version'
mark "dépendances installées"

# (b) Import du vidage (mot de passe par un fichier d'options 0600, jamais en argument).
DEF="$(umask 077 && mktemp)"; trap 'rm -f "$DEF"' EXIT
printf '[client]\nuser=%s\npassword=%s\nprotocol=socket\n' tripleframes_restore_tmp "$(sed -n 's/^DB_PASSWORD=//p' .env)" > "$DEF"
zcat "$T/incoming/db.sql.gz" | mysql --defaults-extra-file="$DEF" tripleframes_restore_tmp
mysql --defaults-extra-file="$DEF" -N tripleframes_restore_tmp -e "SELECT CONCAT('tables=', COUNT(*)) FROM information_schema.tables WHERE table_schema='tripleframes_restore_tmp'; SELECT CONCAT('films=', COUNT(*)) FROM movie; SELECT CONCAT('frames publiées=', COUNT(*)) FROM frame WHERE availability='published'; SELECT CONCAT('users=', COUNT(*)) FROM users; SELECT CONCAT('sessions=', COUNT(*)) FROM sessions;"
mark "(b) vidage importé"

# (c) Dépôt de chaque dérivé publié à son game_path (objet froid adressé par condensat).
n=0
while read -r hash path; do
    ( umask 077 && mkdir -p "$T/frames/$(dirname "$path")" )
    cp "$T/incoming/cold/$hash.webp" "$T/frames/$path"; chmod 0600 "$T/frames/$path"; n=$((n + 1))
done < <(mysql --defaults-extra-file="$DEF" -N tripleframes_restore_tmp -e "SELECT published_hash, game_path FROM frame WHERE availability='published' AND game_path IS NOT NULL ORDER BY id")
mark "(c) $n dérivés publiés déposés"

# (d) Aucune migration en attente.
"$PHP" artisan migrate:status --pending --no-ansi | tail -n 3
mark "(d) migrate:status"

# (d bis) Reprojection obligatoire après toute restauration.
"$PHP" artisan catalog:reproject --no-ansi
mark "(d bis) catalog:reproject"

# (e) Équivalent de backup:verify (non livré, L100-10) : chaque frame publiée a son fichier de jeu au bon condensat.
ok=0; ko=0
while read -r hash path; do
    if [ -f "$T/frames/$path" ] && [ "$(sha256sum < "$T/frames/$path" | cut -d' ' -f1)" = "$hash" ]; then ok=$((ok + 1)); else ko=$((ko + 1)); fi
done < <(mysql --defaults-extra-file="$DEF" -N tripleframes_restore_tmp -e "SELECT published_hash, game_path FROM frame WHERE availability='published' ORDER BY id")
echo "vérification : $ok fichiers au bon condensat, $ko manquants ou altérés"
[ "$ko" -eq 0 ]
# Et contre le manifeste du tier chaud : chaque (condensat, chemin) publié y figure.
absent="$(mysql --defaults-extra-file="$DEF" -N tripleframes_restore_tmp -e "SELECT published_hash, game_path FROM frame WHERE availability='published'" \
    | awk -F'\t' 'NR == FNR { m[$1 " " $3] = 1; next } !(($1 " " $2) in m) { n++ } END { print n + 0 }' <(zcat "$T/incoming/manifest.tsv.gz") -)"
echo "absents du manifeste du tier chaud : $absent"
[ "$absent" -eq 0 ]
mark "(e) vérification des fichiers de jeu"

# (f) Purge rejouée (une sauvegarde ne ressuscite jamais des données purgées).
"$PHP" artisan purge:run --sync --no-ansi
mark "(f) purge:run --sync"

# (g) Rattrapage des parties (obligatoire après restauration de Redis ; sans effet ici).
"$PHP" artisan game:reschedule --no-ansi
mark "(g) game:reschedule"

# (h) préparation : serveur HTTP temporaire, boucle locale seulement (joint par un tunnel SSH).
"$PHP" artisan lang:hash --no-ansi > /dev/null
setsid nohup "$PHP" artisan serve --host=127.0.0.1 --port=18080 --no-reload > "$A/storage/logs/serve.log" 2>&1 < /dev/null &
for i in $(seq 1 20); do
    c="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:18080/up || true)"; [ "$c" = 200 ] && break; sleep 0.5
done
echo "/up de la cible : $c"
mark "(h) serveur temporaire prêt sur 127.0.0.1:18080"
```

Partie 3, [poste], Git Bash : tunnel SSH vers le serveur temporaire, puis navigateur. Le tunnel reste ouvert dans son propre terminal :

```bash
ssh -i <clé> -o ExitOnForwardFailure=yes -N -L 127.0.0.1:18080:127.0.0.1:18080 vagrant@192.168.10.10
```

Dans le navigateur : `http://127.0.0.1:18080/login` → adresse et `<ADMIN_PASSWORD>` → `/two-factor-challenge` → code TOTP de l'application d'authentification → `/dashboard` → `/admin`.

- **Attendu et vérification** — chronologie (horloge de la VM pour (a)-(g), en avance d'environ 1,2 s sur celle du poste ; horloge du poste pour (h)) :

| Instant (UTC) | Étape | Résultat |
| --- | --- | --- |
| 13:34:17.498 | **T0**, décision de restaurer | — |
| 13:34:17.520 | (a) déchiffrement | dernier tier chaud (`…T133137Z`), manifeste, 4 objets froids, chacun au bon condensat |
| 13:34:17.684 | cible jetable prête | `GRANT ALL PRIVILEGES ON tripleframes_restore_tmp.*` seulement ; `.env` `600` ; « APP_KEY = exemplaire hors machine » **et** « APP_KEY = production » |
| 13:34:23.186 | dépendances | `composer install --no-dev` (cache) ; `production`, débogage désactivé, PHP 8.4.16 |
| 13:34:24.049 | (b) import | 45 tables, 17 films, 85 frames publiées, 4 comptes, 0 session (table exclue du vidage) |
| 13:34:24.258 | (c) dépôt | 85 dérivés publiés déposés à leur `game_path` (`0600`, répertoires `0700`) |
| 13:34:24.440 | (d) | « No pending migrations » |
| 13:34:24.732 | (d bis) | « Reprojection terminée. Films reprojetés par différence : 17 » |
| 13:34:24.861 | (e) équivalent de `backup:verify` | 85 fichiers au bon condensat, 0 manquant ou altéré ; 0 absent du manifeste du tier chaud |
| 13:34:25.076 | (f) | « Purge de rétention exécutée. Périmètres : 6. Lignes traitées : 0. » |
| 13:34:25.280 | (g) | `game:reschedule`, code 0, aucune partie à rattraper |
| 13:34:26.209 | serveur temporaire | `/up` de la cible 200 |
| 13:34:40.396 (poste) | tunnel ouvert | `/up` par le tunnel 200 |
| 13:34:45.423 (poste) | (h) mot de passe | renvoi vers `/two-factor-challenge` |
| 13:34:47.099 (poste) | (h) second facteur | `/dashboard` |
| 13:34:48.406 (poste) | **(h) dernier contrôle vert** | `/admin` **200** : tableau de bord de curation de la cible, 17 films publiés, vivier 17/17/16/16 œuvres à N = 2/3/4/5, 2 images rejetées, balayages n° 1 et n° 2 — identique à la production ; bandeau « Aucune clé TMDB n'est configurée » (voulu) |

  **Durée mesurée : environ 32 s** de T0 au dernier contrôle vert (T0 ≈ 13:34:16,3 à l'horloge du poste), dont environ 14 s de travail machine ((a)→serveur prêt : 8,7 s ; connexion : 5,2 s) et le reste en délais entre commandes (ouverture du tunnel et du navigateur). **Volume** : vidage de 30 Ko compressé (45 tables, 17 films, 87 frames, 4 comptes), 4 objets froids (≈ 280 Ko) pour 85 fichiers de jeu. Captures `30-restauration-defi-2fa`, `31-restauration-admin`. **Ce chiffre ne vaut que pour la VM** : il ne s'inscrit pas dans `100` § 13.6, qui attend la mesure du VPS (téléchargement depuis le stockage objet, `age` et `scp` depuis le poste, saisie humaine du code : plusieurs minutes attendues).
- **Écarts** : (e) joué par la boucle `sha256sum` et non par `backup:verify` (non livré) ; (h) par un navigateur automatisé (CDP, profil jetable), code TOTP calculé depuis la clé du fichier d'identifiants du poste ; `APP_URL=http://127.0.0.1:18080` et `SESSION_SECURE_COOKIE=false` (le serveur temporaire parle HTTP en boucle locale, derrière le tunnel chiffré).
- **Sur le VPS Plesk :**
  - (a) sur le **poste** : `rclone copy` du dernier `hot/<jour>/` et de `cold/game/` (clé de **lecture** du poste), déchiffrement `age -d -i <clé privée>` ; envoi des fichiers déchiffrés : `scp db.sql.gz manifest.tsv.gz <utilisateur>@<VPS>:/var/www/vhosts/<DOMAINE>/private/restore-tmp/incoming/` et `scp cold/*.webp …/incoming/cold/` (répertoires créés d'abord en SSH de l'abonnement : `(umask 077 && mkdir -p ~/private/restore-tmp/{frames,snapshots,incoming/cold})`) ; copies du poste supprimées ensuite.
  - Base : Plesk › `<DOMAINE>` › Bases de données › Ajouter : `tripleframes_restore_tmp` (Plesk peut préfixer : noter les noms réels), **nouvel utilisateur** dédié, mot de passe « Générer », accès local seulement.
  - Copie de l'application, SSH de l'abonnement : `umask 027; mkdir ~/restore-tmp-app && git --git-dir=<dépôt de Plesk Git, chemin relevé> archive deploy | tar -x -C ~/restore-tmp-app` ; `.env` : `(umask 077 && cp ~/tripleframes/.env ~/restore-tmp-app/.env)`, puis `nano` pour les lignes de la partie 1 (`APP_URL`, `DB_*`, `FRAMES_DISK_ROOT`, `BACKUP_SNAPSHOT_DIR`, `REDIS_DB=8`, `REDIS_CACHE_DB=9`, `BROADCAST_CONNECTION=log`, `SESSION_SECURE_COOKIE=false`, `OPS_PROBE_TOKEN=` et `TMDB_API_READ_ACCESS_TOKEN=` vides) et **`APP_KEY` collée depuis l'exemplaire hors machine**, jamais recopiée du `.env` de production.
  - (b) à (g) : partie 2 telle quelle, en SSH de l'abonnement, avec `H=/var/www/vhosts/<DOMAINE>`, le chemin de `composer.phar` relevé, et les noms réels de la base et de l'utilisateur jetables (garde de tête et fichier d'options, qui lit le mot de passe dans le `.env` de la copie : jamais en argument) ; `protocol=socket` remplacé par `host=127.0.0.1` si la base ne se joint pas par le socket (relevé, étape 1.5). **Au relevé du VPS, `SELECT @@gtid_mode;`** : si `ON`, le vidage porte `SET @@GLOBAL.GTID_PURGED`, que l'utilisateur de l'abonnement ne peut pas rejouer, et (b) échoue (voir « Écarts », n° 16).
  - (h) : `php artisan serve` dans la session SSH de l'abonnement (port libre relevé), tunnel depuis le poste `ssh -N -L 127.0.0.1:18080:127.0.0.1:<port> <utilisateur>@<VPS>`, navigateur du porteur, code TOTP **du téléphone**. En restauration **réelle** (perte du VPS), la cible n'est pas jetable : `php artisan down` jusqu'à (f) incluse, puis `up` (`100` § 13.5).
  - Durée relevée et inscrite dans `100` § 13.6, avec la date et le volume.

### Étape 3.10 — Suppression de la cible jetable

- **But** : `100` § 13.5, « le tout supprimé ensuite » : serveur temporaire, index Redis 8 et 9, base et utilisateur jetables, copie de l'application, racines temporaires et copies déchiffrées.
- **Commandes** — [root] (la chronologie root est lue avant suppression) :

```bash
set -uo pipefail
# [root] Étape 3.10 — suppression de la cible jetable.
H=/var/www/vhosts/tripleframes-prod.test
echo "== chronologie (a)-(c) côté root"; cat /root/tripleframes-restauration/chrono.log
echo "== serveur temporaire"
pgrep -a -u tripleframes -f -- '18080' | cut -c1-140
pids="$(pgrep -u tripleframes -f -- '18080' | tr '\n' ' ')"
[ -n "$pids" ] && kill $pids
sleep 1
pgrep -u tripleframes -f -- '18080' > /dev/null && echo "serveur encore actif" || echo "serveur temporaire arrêté"
ss -ltn | grep -qE '127\.0\.0\.1:18080\b' && echo "18080 encore en écoute" || echo "18080 libéré"
echo "== index Redis de la cible (8 et 9) : clés supprimées une à une (FLUSHDB est renommée)"
export REDISCLI_AUTH="$(awk '/^requirepass /{print $2}' /etc/tripleframes/tripleframes-redis.conf)"
for db in 8 9; do
    n="$(redis-cli -p 6390 -n $db dbsize)"
    redis-cli -p 6390 -n $db --scan | xargs -r -d '\n' redis-cli -p 6390 -n $db unlink > /dev/null
    echo "index $db : $n clés avant, $(redis-cli -p 6390 -n $db dbsize) après"
done
redis-cli -p 6390 info keyspace | grep -E '^db'
unset REDISCLI_AUTH
echo "== base et utilisateur jetables"
mysql -e "DROP DATABASE tripleframes_restore_tmp; DROP USER 'tripleframes_restore_tmp'@'localhost';"
mysql -N -e "SHOW DATABASES LIKE 'tripleframes%'; SELECT user FROM mysql.user WHERE user LIKE 'tripleframes%';"
echo "== fichiers jetables"
rm -rf "$H/restore-tmp-app" "$H/private/restore-tmp" /root/tripleframes-restauration
for p in "$H/restore-tmp-app" "$H/private/restore-tmp" /root/tripleframes-restauration; do test -e "$p" && echo "RESTE $p" || echo "supprimé $p"; done
ls -la "$H" "$H/private"
```

Et, sur le [poste], fermer le tunnel (Ctrl+C dans son terminal) et le navigateur.

- **Attendu et vérification** (13:35 UTC) : serveur temporaire (`artisan serve` et son `php -S 127.0.0.1:18080`) arrêté, port libéré ; index Redis 8 : 0 clé, index 9 : 5 clés (sessions de connexion, limiteurs) supprimées une à une, puis absents de `info keyspace` (seul l'index 1 de la production reste) ; base et utilisateur jetables supprimés (`SHOW DATABASES LIKE 'tripleframes%'` : `tripleframes`, `tripleframes_prod`) ; `restore-tmp-app`, `private/restore-tmp` et la copie déchiffrée de root supprimés.
- **Sur le VPS Plesk :** `pkill` du serveur temporaire en SSH de l'abonnement ; base et utilisateur supprimés dans Plesk › Bases de données ; `rm -rf ~/restore-tmp-app ~/private/restore-tmp` ; nettoyage des index 8 et 9 en root (`--scan` + `unlink`, `FLUSHDB` étant renommée) ; copies déchiffrées du poste supprimées.

### Étape 3.11 — Production intacte, langue et cache, isolement, témoins des voisins

- **But** : prouver que la restauration n'a rien touché : production, langue et cache de `100` § 15, ports fermés depuis le poste, PID des voisins inchangés.
- **Commandes** — [root] : le bloc de l'étape 3.1, puis :

```bash
set -uo pipefail
# [root] Étape 3.11 — production intacte après la restauration, langue et cache, témoins des voisins.
H=tripleframes-prod.test; R="--resolve $H:443:127.0.0.1"; CA=/etc/ssl/certs/ca.homestead.homestead.crt
E=/var/www/vhosts/tripleframes-prod.test/tripleframes/.env
MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' $E)" mysql --no-defaults --protocol=socket -u tripleframes_prod tripleframes_prod -N -e \
  "SELECT 'films publiés', COUNT(*) FROM movie WHERE availability='published'; SELECT 'frames publiées', COUNT(*) FROM frame WHERE availability='published'; SELECT 'users', COUNT(*) FROM users; SELECT 'admin 2FA confirmée', COUNT(*) FROM users WHERE role='admin' AND two_factor_confirmed_at IS NOT NULL;"
echo "== langue et cache"
for l in fr en; do
    printf '%s : ' "$l"; curl -s -D - -o /tmp/tf-lc.$$ --cacert $CA $R -H "Accept-Language: $l" "https://$H/" | grep -ciE '^(age|x-cache|x-proxy-cache):' | tr -d '\n'
    printf ' en-tête(s) de cache ; %s\n' "$(grep -oE '<html[^>]*lang="[a-z]+"' /tmp/tf-lc.$$ | grep -oE 'lang="[a-z]+"')"
done
rm -f /tmp/tf-lc.$$
echo "== témoins des voisins"
for u in nginx php8.1-fpm php8.3-fpm php8.4-fpm mysql redis-server; do printf '%-12s %s %s\n' $u "$(systemctl is-active $u)" "$(systemctl show -p MainPID --value $u)"; done
sudo mysql -N -e "SELECT COUNT(*), MAX(id) FROM tripleframes.migrations;"
sudo mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tripleframes';"
ss -ltn | awk 'NR>1{print $4}' | sort -u | tr '\n' ' '; echo
```

Puis, [poste], PowerShell :

```powershell
foreach ($p in 6390,8090,443) { '{0} {1}' -f $p, (Test-NetConnection 192.168.10.10 -Port $p -WarningAction SilentlyContinue).TcpTestSucceeded }
```

- **Attendu et vérification** (13:35:55 UTC) : cinq unités actives, `/up` et cinq sondes `ok`, 404 sans jeton ou jeton faux ; production inchangée : 17 films publiés, 85 frames publiées, 4 comptes, administrateur au second facteur confirmé ; `purge_run` : trois exécutions complètes par périmètre ; langue et cache : `lang="fr"` puis `lang="en"`, **aucun** en-tête `Age`, `X-Cache` ou `X-Proxy-Cache` ; voisins : PID inchangés (nginx 973, `php8.1-fpm` 735, `php8.3-fpm` 736, `php8.4-fpm` 737, MySQL 870, Redis partagé 743, 22 clés) ; ports : seuls 6390 et 8090 en plus du relevé « avant », tous deux en boucle locale ; depuis le poste : `6390 False`, `8090 False`, `443 True`.
- **Constat (hors répétition)** : la base de développement `tripleframes` compte **47** migrations (46 au relevé de 11:23) : `2026_09_27_100045_add_solo_token_hash_to_player_table`, lot 3, table `player` recréée à **13:19:20 UTC**, avant le début de cette phase (13:21:29) ; au même moment, l'application de développement du porteur (`/home/sites/tripleframes`, `APP_ENV=local`) écrivait dans son journal (13:18:49 et jusqu'à 13:36). Aucune commande de la répétition ne vise cette base (lectures seulement, par `sudo mysql`) : migration jouée par l'environnement de développement du porteur.
- **Sur le VPS Plesk :** identique (sondes et « langue et cache » depuis le poste, témoins des voisins en root selon le relevé de l'étape 1.0).

### Étape 3.12 — Contrôle final des gabarits recopiés (`ops/mise-en-service.md` § 9)

- **But** : `ops/mise-en-service.md` § 9 : aucun paramètre `__TF_…__` ni `<DOMAINE>` restant, gabarits installés identiques au dépôt hors lignes paramétrées.
- **Commandes** — [root], le bloc de § 9 joué tel quel, plus les directives nginx :

```bash
# [root] Étape 3.12 — contrôle final des gabarits (ops/mise-en-service.md § 9), joué tel quel.
grep -rnE '^[^#]*(__TF_|<DOMAINE>)' /etc/tripleframes /etc/systemd/system/tripleframes-* /var/www/vhosts/tripleframes-prod.test/tripleframes/.env && echo "RESTE DES PARAMÈTRES" || echo "aucune ligne : tout paramètre est remplacé"
cd /var/www/vhosts/tripleframes-prod.test/tripleframes
echo "--- redis"; diff -I '^requirepass ' ops/redis/tripleframes-redis.conf /etc/tripleframes/tripleframes-redis.conf
for f in tripleframes-redis.service tripleframes-reverb.service tripleframes-worker@.service; do
    echo "--- $f"; diff "ops/systemd/${f}" "/etc/systemd/system/${f}"
done
for i in game default; do
    echo "--- worker-$i"; diff "ops/systemd/worker-${i}.env" "/etc/tripleframes/worker-${i}.env"
    diff "ops/systemd/tripleframes-worker@${i}.service.d/limits.conf" "/etc/systemd/system/tripleframes-worker@${i}.service.d/limits.conf"
done
echo "--- directives nginx"; diff ops/nginx/additional-directives.conf /etc/tripleframes/nginx/additional-directives.conf
true
```

- **Attendu et vérification** (13:36 UTC) : « aucune ligne : tout paramètre est remplacé » (`.env` compris) ; seules diffèrent les lignes paramétrées : `port 6390`, `User`/`Group` `tripleframes`, `WorkingDirectory`, `proxy_pass http://127.0.0.1:8090` ; unité Redis, `worker-*.env` et `limits.conf` identiques au dépôt (checkout de l'artefact n° 4, gabarits RV-10 compris).
- **Écart** : écart n° 5 (gabarits non « ajustés au relevé » dans le dépôt : le `diff` montre les paramètres `__TF_…__`, pas seulement `<DOMAINE>`).
- **Sur le VPS Plesk :** identique, `cd /var/www/vhosts/<DOMAINE>/tripleframes` ; après le commit « gabarits ajustés au relevé », le `diff` ne doit plus montrer que les lignes `<DOMAINE>` (et rien pour la ligne `requirepass`, ignorée).

## Phase 4 — Partie

### Catalogue (joué pendant la phase Déploiement, 28/09, 12:34-12:50 UTC)

**Ordre VM ≠ ordre VPS.** Sur la VM, le catalogue a été posé juste après le premier déploiement par le hook, **avant** le tier chaud de sauvegarde (phase 3) : c'est une répétition. **Sur le VPS, aucune image n'est curée avant l'étape 30 du REPRISE** (tier chaud et froid actifs avant la première image curée, `100` § 11.6, `ops/mise-en-service.md` § 11), et **aucun catalogue de démonstration n'y est jamais posé**. La réconciliation des presets et thèmes, elle, est faite par le hook à chaque déploiement (étape 6, `PlatformDataSeeder`) : relevé après l'étape 2.17, 4 presets (`classic`, `discovery`, `fast`, `hardcore`), 27 thèmes, 54 libellés, 1 saga.

### Étape 4.1 — Catalogue de démonstration (propre à la répétition — **INTERDIT SUR LE VPS**)

- **But** : un vivier suffisant pour jouer une partie au preset `classic` (16 films publiés, tous éligibles jusqu'à `N = 5`), ce que deux films curés à la main ne donnent pas. `DemoCatalogueSeeder` refuse tout environnement hors `local`/`testing` et ses fabriques exigent Faker (dépendance de développement, absente d'un `--no-dev`) : il passe par un **outillage séparé**, supprimé aussitôt.
- **Commandes** — [abo], un seul bloc :

```bash
PHP=/opt/plesk/php/8.4/bin/php
APP=/var/www/vhosts/tripleframes-prod.test/tripleframes
O=/var/www/vhosts/tripleframes-prod.test/repetition-outillage
cd "$APP"
"$PHP" artisan backup:snapshot; echo "backup:snapshot code=$?"          # règle 12, AVANT le geste
test ! -e "$O"
(umask 077 && mkdir "$O")
git --git-dir="$HOME/git/tripleframes.git" archive deploy | tar -x -C "$O"
(umask 077 && sed 's/^APP_ENV=production$/APP_ENV=local/' .env > "$O/.env")
cd "$O"
"$PHP" /usr/local/bin/composer install --no-interaction --no-progress   # AVEC les dépendances de dev (Faker)
"$PHP" artisan about --only=environment | grep -E 'Environment|Debug'
"$PHP" artisan db:seed --class=DemoAccountsSeeder
"$PHP" artisan db:seed --class=DemoCatalogueSeeder
cd "$APP"
"$PHP" artisan catalog:reproject
rm -rf "$O"
test ! -e "$O" && echo "outillage supprimé"
```

- **Attendu et vérification** (12:34:32 → 12:34:49 UTC) : instantané `snapshot-20260928T123433Z.sql.gz` écrit et vérifié ; outillage en `local`, débogage désactivé ; `DemoAccountsSeeder … DONE`, `DemoCatalogueSeeder … DONE` ; « Films reprojetés par différence : 16 » ; outillage supprimé (son `.env`, copie des secrets, avec lui). En base : 16 films `published`, `import_source = demo` ; 83 frames, dont 82 `published` et 1 `draft` rejetée par le seeder (frame 83, film 1 : c'est la première des deux lignes « Rejetées (2) » de l'étape 4.6) ; 166 fichiers sous la racine `frames` (préfixes `game/` et `master/`), en `0600 tripleframes` ; sonde `integrity` verte.
- **Contrôle** — [root], lecture par l'utilisateur dédié (**avant** l'étape 4.3) :

```bash
E=/var/www/vhosts/tripleframes-prod.test/tripleframes/.env
MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' $E)" mysql --no-defaults --protocol=socket -u tripleframes_prod tripleframes_prod \
    -e "SELECT availability, COUNT(*) FROM frame GROUP BY availability;"
```

  Attendu : `published 82`, `draft 1`. Rejoué après l'étape 4.6 (film 17 curé), il donne `published 85`, `draft 2` (relevé le 28/09 vers 13:10 UTC, avec `frame_review` : `passed 85`, `rejected 2`).
- **Correction du contrôle** (RV-10) : ce paragraphe annonçait « 83 frames publiées » ; rejoué, le contrôle aurait donné 82 et paru en échec.
- **Nom de l'outillage** : `…/repetition-outillage` et non `…/tripleframes-…` : un chemin préfixé par le chemin de déploiement serait refusé comme racine (`FRAMES_DISK_ROOT`, `BACKUP_SNAPSHOT_DIR`) et prêterait à confusion.
- **Sur le VPS Plesk :** **jamais.** Il pose trois comptes au mot de passe connu (`DemoAccountsSeeder::PASSWORD`), dont un **second administrateur**, et des images de bruit. Le catalogue de production naît de la seule curation (étape 4.3 et suivantes), après l'étape 30.

### Étape 4.2 — Neutralisation des comptes de démonstration (propre à la répétition)

- **But** : `DemoAccountsSeeder` a créé `player@`, `curator@` et `admin@tripleframes.test` (mot de passe connu, le dernier administrateur, journalisé `role.changed` par la console) ; la VM est joignable sur le réseau privé du poste. Leur mot de passe est remplacé par une valeur aléatoire jamais conservée (les comptes restent : ils signent les preuves de revue du catalogue de démonstration).
- **Commandes** — [abo] :

```bash
PHP=/opt/plesk/php/8.4/bin/php
cd /var/www/vhosts/tripleframes-prod.test/tripleframes
"$PHP" artisan tinker --execute="App\Models\User::whereIn('email', ['player@tripleframes.test', 'curator@tripleframes.test', 'admin@tripleframes.test'])->get()->each(function (\$u) { \$u->forceFill(['password' => Illuminate\Support\Str::password(64)])->save(); echo \$u->email, ' : mot de passe connu remplacé', PHP_EOL; });"
```

Contrôle — [abo], une connexion `admin@tripleframes.test` avec le mot de passe de démonstration **public** du dépôt (`DemoAccountsSeeder::PASSWORD`, `password`), par `curl` avec le jeton XSRF du cookie :

```bash
H=tripleframes-prod.test
curl -sk --resolve $H:443:127.0.0.1 -c /tmp/tf-cj -b /tmp/tf-cj -o /tmp/tf-login.html https://$H/login
xsrf=$(grep -oE 'XSRF-TOKEN\s+[^ ]+' /tmp/tf-cj | awk '{print $2}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
curl -sk --resolve $H:443:127.0.0.1 -c /tmp/tf-cj -b /tmp/tf-cj -o /dev/null -w '%{http_code} -> %{redirect_url}\n' -H "X-XSRF-TOKEN: $xsrf" -H 'Accept: text/html' --data-urlencode 'email=admin@tripleframes.test' --data-urlencode 'password=password' https://$H/login
rm -f /tmp/tf-cj /tmp/tf-login.html
```

- **Attendu et vérification** (12:35 UTC, rejoué le 28/09 à 13:17 UTC) : `302 -> https://tripleframes-prod.test/login` (échec d'authentification, renvoi vers le formulaire), jamais `/dashboard` ; un jeton XSRF manquant donnerait `419`, pas `302`. Les trois mots de passe ne vérifient plus `password` (`password_verify` faux pour les trois comptes).
- **Correction du contrôle** (RV-10) : ce contrôle n'était décrit qu'en prose ; bloc ajouté, rejoué tel qu'écrit à 13:17 UTC (`scratchpad/vm/deploiement/rv10-f-neutralise-controle.sh`). Le jeu de 12:35 (`p2-neutralise.sh`) ne différait que par le libellé de la ligne de sortie et la capture du code HTTP du premier `GET /login`.

- **Sur le VPS Plesk :** sans objet (étape 4.1 interdite).

### Étape 4.3 — Import d'un film par identifiant TMDB (voie manuelle)

- **But** : `20` § 3.3 : aperçu à blanc, puis collage réel d'un identifiant (le film entre « par exception ») ; traitement différé par le worker `default` (`RunCatalogImport`), aucun appel TMDB pendant la requête.
- **Gestes** — [poste], navigateur, connecté en administrateur (TOTP) : `/admin/import` › « Collage d'identifiants » › `129` (Le Voyage de Chihiro) › « Prévisualiser » → « 129 · 千と千尋の神隠し · 2001 · Serait importé · Entrera par exception » ; puis « Importer ces identifiants » → `/admin/import/run/<n>`.
- **Impasse (RV-7, corrigée dans le dépôt)** : le premier collage (balayage n° 1, 12:36 UTC) est passé **« Échoué »** en 14 ms, sans avoir démarré (`started_at` nul, compteurs à 0), sans rien au journal. Cause : `PreviewCatalogPaste` et `RunCatalogImport` appellent tous deux `Artisan::call('catalog:import-ids', …)`, et un worker `queue:work` (processus long) sert **la même instance de commande** aux deux appels ; le jeton de l'aperçu survivait, l'import se croyait simulation et sortait en `simulation_resume`. Invisible sur le poste (`composer dev` lance `queue:listen`, un processus par job) comme en test. Reproduction, [abo] (ne crée aucun balayage : le collage n° 999999 n'existe pas) :

```bash
PHP=/opt/plesk/php/8.4/bin/php
cd /var/www/vhosts/tripleframes-prod.test/tripleframes
"$PHP" artisan tinker --execute="
use Illuminate\Support\Facades\Artisan;
\$t = bin2hex(random_bytes(16));
echo '1) import seul, collage inexistant : code=', App\Support\Catalog\ImportSnapshotGuard::ordinaryPath(fn () => Artisan::call('catalog:import-ids', ['ids' => ['129'], '--resume' => true, '--run' => 999999])), ' ', trim(Artisan::output()), PHP_EOL;
echo '2) aperçu : code=', Artisan::call('catalog:import-ids', ['ids' => ['129'], '--preview' => \$t, '--actor' => '1']), PHP_EOL;
echo '3) même appel que 1) dans le même processus : code=', App\Support\Catalog\ImportSnapshotGuard::ordinaryPath(fn () => Artisan::call('catalog:import-ids', ['ids' => ['129'], '--resume' => true, '--run' => 999999])), ' ', trim(Artisan::output()), PHP_EOL;
"
```

  Obtenu : `1) code=0 Aucun collage à reprendre` ; `2) code=0` ; `3) code=1 ERROR Une simulation ne reprend aucun balayage…`. Correctif : `CatalogImportCommand::resetInvocationState()`, appelée par `initialize()` avant chaque exécution (remet `interrupted`, `simulation`, et dans `CatalogImportIdsCommand` l'auteur et le jeton de l'aperçu), plus deux tests de non-régression (`PastePreviewTest` › « l'import qui suit un aperçu dans le même processus importe vraiment », `CatalogImportCommandTest` › « un aperçu ne laisse aucun état… ») : **rouges avant le correctif, verts après** (34 tests, 401 assertions sur les quatre fichiers d'import ; Pint et PHPStan niveau 7 propres), joués dans la VM sur le clone `tests-s1`. Livré par l'étape 4.4.
- **Après le correctif** (balayage n° 2, 12:44 UTC) : aperçu, puis collage → « Terminé », fiches vues 1, films importés 1, durée 0 s. Film n° 17 : `tmdb_id = 129`, `draft`, `import_source = paste`, `is_import_exception = 1`, 18 954 votes TMDB, translittération `Sen to Chihiro no Kamikakushi`. Captures `10-admin-import`, `11-import-apercu`, `12-import-balayage`, `13-film-fiche`.
- **Sur le VPS Plesk :** mêmes gestes, après l'étape 30. Si un collage passe « Échoué » sans avoir démarré, lire le journal du worker : `journalctl -u tripleframes-worker@default --since "-10min" -o cat` (et `storage/logs`) ; avec le correctif, ce cas n'est plus attendu.

### Étape 4.4 — Artefact n° 3 et second déploiement par le hook (correctif RV-7)

- **Commandes** : celles des étapes 2.16 et 2.17, avec les quatre fichiers du correctif (`app/Console/Commands/CatalogImportCommand.php`, `app/Console/Commands/CatalogImportIdsCommand.php`, `tests/Feature/Admin/PastePreviewTest.php`, `tests/Feature/Catalog/CatalogImportCommandTest.php`) copiés dans le clone jetable et commités (« répétition : état d'invocation des commandes d'import remis à zéro (RV-7), jamais poussé »), `npm run build` (manifest identique), commit `deploy` de parent `fe47155`, *bundle* `deploy-3`, « Tirer », « Déployer », `bash ops/deploy/hook.sh`.
- **Attendu et vérification** (12:43:18 → 12:43:23 UTC) : source `804890f`, `deploy e7cdb5c` (4 fichiers, 98 lignes) ; hook `[1/12]` à `[11/12]` sans 3 ni 12, code 0 ; `[7/12]` « Films reprojetés par différence : 16 » ; PID des trois unités changés, quatre unités actives.
- **Constat** : `catalog:reproject` annonce « Films reprojetés par différence : 16 » à **chaque** passage (trois passages consécutifs relevés), catalogue inchangé : le nombre est celui des films parcourus, pas des films modifiés (l'idempotence porte sur les clés de réponse, qui ne sont pas réécrites). Sur le VPS, ce nombre égalera la taille du catalogue à chaque déploiement : ce n'est pas un signe de changement.
- **Sur le VPS Plesk :** procédure du § 11.4 (déploiement ordinaire) ; le drainage ne s'y ajoutera qu'après l'étape 126.

### Étape 4.5 — Banque d'images : trois visuels TMDB aux niveaux 1, 3 et 5 (recadreur)

- **Gestes** — [poste], `/admin/catalog/17/bank` (« Curer les images » depuis la fiche) : la grille « Visuels TMDB du film » (124 visuels, les visuels marqués « Peut contenir du texte » en dernier) ; clic sur un visuel → il s'ouvre dans le cadre (« le plus grand cadre admis, centré » : 1536 × 864 sur un visuel de 1920 × 1080, `crop_x=192`, `crop_y=108`) ; cocher le niveau ; « Ajouter à la banque » ; le visuel suivant s'ouvre de lui-même. Trois ajouts : niveau 1, 3, 5.
- **Attendu et vérification** (12:45-12:46 UTC) : chaque ajout passe « En attente de revue » en moins de 4 s ; journal du worker `default` : `App\Jobs\Curation\ProcessFrameImage … DONE` (700, 683, 413 ms) ; en base, frames `processing_state = ready`, `source_kind = tmdb` ; fichiers de jeu `game/<2>/<2>/<32 hex>.webp`, **WebP 1280 × 720**, tailles **multiples de 8 192 octets** (81 920, 65 536, 114 688 : ≤ 150 Ko, rembourrées), `0600 tripleframes`, noms aléatoires. Le téléchargement du visuel et le réencodage Imagick ont lieu sur le serveur (worker `default`, jamais la file `game`) ; le navigateur ne fournit que le rectangle. Mémoire du worker `default` après traitement : ≈ 100 Mo (plafond 512 Mo). Captures `14-banque-vide`, `15-cadre-niveau-{1,3,5}`.
- **Sur le VPS Plesk :** mêmes gestes ; la VM doit joindre `image.tmdb.org` (vérifié au relevé) et le poste aussi (le cadre charge le visuel depuis TMDB).

### Étape 4.6 — Passe de revue, rejet, nouvelle image de niveau 1

- **Gestes** — [poste], `/admin/review` (menu « Revue ») : chaque image se juge sur son **rendu final** (téléphone et ordinateur, cadre sombre du jeu) contre la grille d'exclusion, version 1, du niveau.
  1. Niveau 1 : le visuel montrait **le visage de l'héroïne** → « Non conforme », case du point en défaut, « Rejeter ».
  2. Niveaux 3 (le train, Sans-Visage) et 5 (Chihiro et Haku) : « Conforme, publier ».
  3. Retour à la banque : un visuel de second plan (les esprits au bain, sans personnage principal, sans texte) ajouté au niveau 1 ; revu sur son rendu final ; « Conforme, publier ».
- **Attendu et vérification** (12:47-12:49 UTC) : file « À revoir » vidée, « Rejetées (2) » (celle-ci et une du catalogue de démonstration) ; `frame_review` : quatre lignes signées `Porteur Répétition`, rôle `admin`, grille version 1, source déclarée `tmdb` égale au `tmdb_file_path` de la frame, empreinte revue égale à `published_hash` ; trois `passed` (niveaux 1, 3, 5, frames `published`, `published_review_id` posé) et une `rejected` (frame 84, restée `draft`). Captures `16-revue-file`, `17-revue-niveau-{3,5}`, `18-revue-non-conforme`, `19-revue-conformes`, `20-grille-visuels`, `21-candidat-{4,71}`, `22-revue-niveau-1-bis`, `23-revue-file-vide`.
- **Écart (répétition, pas le produit)** : la ligne de revue **immuable** du rejet (frame 84) nomme le mauvais point en défaut : `no_poster_or_cover: false` au lieu de `no_lead_face: false` — le pilote CDP a coché la première case de la grille au lieu de celle du visage. L'image est bien rejetée ; la preuve, elle, ne se corrige pas (ligne immuable, par conception) et reste en l'état dans la base de répétition. Sur le VPS, le porteur coche lui-même la case.
- **Sur le VPS Plesk :** mêmes gestes, au clavier du porteur (Entrée = « Conforme, publier » hors d'un bouton ou d'une case).

### Étape 4.7 — Publication et entrée au vivier

- **Gestes** — [poste], fiche du film (`/admin/catalog/17`) : « Publiable : contenu vérifié ET niveaux 1, 3 et 5 couverts » ; « Publier le film » → dialogue (« Le film entre au vivier… », « Aucune forme ne deviendra ambiguë ») → « Publier le film ».
- **Vérification** — [abo] :

```bash
PHP=/opt/plesk/php/8.4/bin/php
cd /var/www/vhosts/tripleframes-prod.test/tripleframes
"$PHP" artisan tinker --execute="
\$q = app(App\Support\Draw\PoolQuery::class);
foreach ([2, 3, 4, 5] as \$n) {
    \$s = App\Support\Draw\PoolScope::catalogue([], \$n);
    \$ids = \$q->movies(\$s)->pluck('movie.id')->all();
    echo 'N=', \$n, ' : vivier ', \$q->countWorks(\$s), ' oeuvres ; film 17 (TMDB 129) ', in_array(17, \$ids) ? 'PRÉSENT' : 'absent', PHP_EOL;
}
"
```

- **Attendu et vérification** (12:49:41 UTC) : film `published`, `first_published_at` posé ; `admin_action` `movie.published`, acteur `Porteur Répétition` ; vivier catalogue sans thème : **N = 2 et N = 3 : 17 œuvres, film présent** ; N = 4 et N = 5 : 16 œuvres, film absent (attendu : trois niveaux couverts ne suffisent pas à quatre images distinctes ; passe 2 de curation) ; cinq sondes vertes. Captures `24-film-publiable`, `25-publier-dialogue`, `26-film-publie`.
- **Sur le VPS Plesk :** mêmes gestes et même contrôle `tinker` (lecture seule).

### Parties (phase Partie, 28/09, à partir de 14:04 UTC)

Jouées au navigateur contre `https://tripleframes-prod.test`, pile de production de la VM (Redis dédié, workers systemd, Reverb derrière nginx en `wss`), **depuis le déploiement n° 4** (`a775555`). Contrôles en base : lecture seule par l'utilisateur dédié, jamais par `root` de MySQL. Aucun geste sur les services existants de la VM.

### Étape 4.8 — Poste : trois navigateurs indépendants (propre à la répétition)

- **But** : trois joueurs sans compte, chacun avec son propre cookie `player_token` : **hôte** en français, **invité** en anglais (pour vérifier que le QCM est composé dans la langue de chaque joueur, envoi ciblé), **tiers** en français (solo, second salon du drainage). Écran portrait 390 × 844.
- **Commandes** — [poste], PowerShell : trois Chrome headless à profils jetables, chacun avec son port de pilotage CDP :

```powershell
$S = '<scratchpad>\vm\partie'
$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
foreach ($p in @(@{n='hote';port=9561;l='fr-FR,fr'}, @{n='invite';port=9562;l='en-US,en'}, @{n='tiers';port=9563;l='fr-FR,fr'})) {
  $prof = Join-Path $S ("profile-" + $p.n)
  New-Item -ItemType Directory -Force $prof | Out-Null
  $argline = "--headless=new --remote-debugging-port=$($p.port) `"--user-data-dir=$prof`" `"--host-resolver-rules=MAP tripleframes-prod.test 192.168.10.10`" --ignore-certificate-errors --no-first-run --no-default-browser-check --disable-background-timer-throttling --disable-backgrounding-occluded-windows --disable-renderer-backgrounding --window-size=390,844 --accept-lang=$($p.l) about:blank"
  Start-Process -FilePath $chrome -ArgumentList $argline -WindowStyle Hidden
}
```

  Pilotage par `puppeteer-core` (scripts `vm/driver/p-*.mjs` du scratchpad, hors dépôt) ; un **enregistreur** (`p-recorder.mjs`) attaché aux trois navigateurs écrit en JSONL chaque trame WebSocket reçue ou envoyée et le corps de chaque réponse `Document`/`XHR`/`Fetch` du site (en-têtes `X-Robots-Tag` et `Cache-Control` compris) : c'est la matière du contrôle anti-triche. **Oracle de test** (répétition seulement) : les titres du film de chaque manche sont lus en base, en lecture seule, juste après le lancement, pour que le pilote puisse répondre juste ; aucun client ne les reçoit.
- **Impasses levées** : (1) `Start-Process -ArgumentList @(…)` n'entoure pas de guillemets un argument qui contient une espace (`--host-resolver-rules=MAP …`) : ligne d'arguments unique, guillemets échappés par l'accent grave ; (2) Chrome headless sous Windows n'ouvre pas de fenêtre de moins de 500 px de large : la largeur de 390 px est posée par `page.setViewport({ width: 390, height: 844 })` ; (3) **ne pas** émuler `isMobile`/`hasTouch` : puppeteer recharge la page à chaque connexion qui change ces drapeaux, et chaque rechargement fait supplanter l'onglet (`seat.superseded`, relevé 7 fois au lobby avant correction) — sans conséquence pour le produit (reprise immédiate de l'onglet rechargé), mais le pilote ne doit pas recharger sans le vouloir.
- **Sur le VPS Plesk :** deux ou trois vrais appareils (le téléphone du porteur et un second navigateur en navigation privée suffisent), `https://<DOMAINE>`, certificat Let's Encrypt : aucun drapeau. L'oracle n'existe pas : le porteur joue avec les films qu'il a curés.

### Étape 4.9 — Partie multijoueur complète à deux invités (Simple, rapide)

- **But** : la boucle de jeu entière, deux invités, réglages Simple au plus court : **N = 2, D = 10 s, R = 3 s, M = 3, Normal** ; création, entrée par code, lancement, mauvaise réponse, bonne réponse, verrouillage (« a trouvé » visible de l'autre **sans le titre**), QCM au dernier palier, révélation (D14), podium, « Rejouer » ; puis contrôle qu'**aucune trame reçue avant la révélation d'une manche ne contient le titre** de son film.
- **Gestes** — [poste] :
  1. **Hôte** : `/r/new` › pseudo `Camille`, avatar « Chien » › « Créer le salon » → `/r/V5GACS` (lobby, code affiché, « Lancer la partie » grisé : « Il faut au moins 2 joueurs connectés »).
  2. **Invité** (anglais) : accueil › champ « Room code » › `v5gacs` (en minuscules : normalisé) › « Join » → `/r/V5GACS/join` › pseudo `Robin`, avatar « Monkey » › « Join » → lobby en lecture seule (« Only the host can change the settings »).
  3. **Hôte**, onglet Simple : « Images par manche » = 2 ; « Durée d'une manche » : curseur au minimum (touche Début) = 10 s ; « Durée de la révélation » : minimum = 3 s ; « Nombre de manches » : minimum = 3 ; « Difficulté de saisie » = Normal. Chaque geste part seul (`PATCH /r/V5GACS/settings`) et s'affiche chez l'invité (`settings.changed`).
  4. **Hôte** : « Lancer la partie » → décompte de 5 s chez les deux.
  5. Manche 1 : l'invité tape `Titanic` (faux), puis, **au moins 1 s après**, le titre anglais ; l'hôte tape le titre français entre les deux.
  6. Manche 2 : personne ne tape ; à `T_N` (5 s) les quatre propositions apparaissent ; l'invité choisit la bonne (en anglais), l'hôte une mauvaise (en français).
  7. Manche 3 : personne ne répond ; la manche va à `D`.
  8. Podium ; **hôte** : « Rejouer ».
- **Attendu et vérification** (partie n° 2 de la base, 14:14:32 → 14:15:44 UTC ; captures `vm/partie/shots/g1-*`) :

| Moment | Observé |
| --- | --- |
| Lobby | réglages relus chez l'invité en anglais (« Choices appear at 5 s, 50% of the round ») ; avertissement « Révélation courte : 5 s sont recommandées » ; « Films jouables : 17 pour 3 manches » |
| Manche 1, faux | `POST /seat/<id>/answer` → `{"result":"rejected","inputState":"open","attemptsLeft":4}` ; « That's not it. · 4 attempts left » |
| Manche 1, hôte juste à 0,38 s | « Trouvé ! Gagné : 298 points, dont 98 de bonus de rapidité, rang d'arrivée 1er » ; chez l'invité : « Camille · Found it · 1st », **sans le titre** |
| Manche 1, invité juste à 1,78 s | 270 points (200 + 70) ; tous les joueurs connectés verrouillés → **fin anticipée** (`game.round_closed`, cause `early_end`) ; révélation : **1 image** (le palier 2, jamais ouvert, n'est pas montré : `round_tier` 2 `served_at` nul), titre dans la langue du joueur, titre original, année, « Ont trouvé » avec palier et temps, classement intermédiaire |
| Manche 2, `T_N` | propositions **localisées** : hôte `Le Roi Lion · Star Wars : Un nouvel espoir · Retour vers le futur · Le Voyage de Chihiro` ; invité `Spirited Away · Star Wars: A New Hope · The Lion King · Back to the Future` (`round_player.choices_locale` = `fr` / `en`) ; invité juste → 150 (100 + 50) ; hôte faux → « Mauvaise proposition : la saisie est close » (`qcm_wrong`) ; plus aucune saisie ouverte → fin anticipée ; révélation : 2 images |
| Manche 3 | personne : fin à `D` (cause `duration`), « Personne n'a trouvé » |
| Podium | Robin 420, Camille 298 ; « Meilleure réponse », « Trouvé le plus vite », récapitulatif des films ; chez l'invité « Waiting for the host to play again » |
| « Rejouer » | les deux au lobby, réglages conservés ; « Films jouables : 14 pour 3 manches » (mémoire du salon) |

  Contrôles — [root], lecture par l'utilisateur dédié :

```bash
E=/var/www/vhosts/tripleframes-prod.test/tripleframes/.env
q() { MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' $E)" mysql --no-defaults --protocol=socket -u tripleframes_prod tripleframes_prod -e "$1"; }
q "SELECT id, status, tier_grace_ms, rounds_completed, started_at, ended_at FROM game ORDER BY id;"
q "SELECT r.game_id, r.round_number, r.status, r.found_count,
          (SELECT GROUP_CONCAT(CONCAT(rt.tier_index,'=',rt.points,IF(rt.served_at IS NULL,' non servi',' servi')) SEPARATOR ' ; ') FROM round_tier rt WHERE rt.round_id=r.id) paliers
   FROM round r WHERE r.round_number IS NOT NULL ORDER BY r.id;"
q "SELECT r.game_id, r.round_number, p.nickname, gu.answered_at_ms, gu.tier_index, gu.source, gu.points_tier, gu.points_bonus, gu.points_total
   FROM guess gu JOIN round r ON r.id=gu.round_id JOIN player p ON p.id=gu.player_id ORDER BY gu.id;"
q "SELECT game_id, display_nickname, correct_answers, final_score, final_rank FROM game_player ORDER BY id;"
grep -hE '"game\.(round_closed|finalized|broadcast_failed|transition_failed_twice)"' \
    /var/www/vhosts/tripleframes-prod.test/tripleframes/storage/logs/game-$(date -u +%F).log | cut -c1-200
```

  Relevé : partie 2 `completed`, 3 manches `completed` ; `guess` : `381 ms → 200 + 98`, `1 781 ms → 200 + 70`, `5 232 ms (QCM) → 100 + 50` — conforme à `80` § 4.2 : instant corrigé `max(0, t − tier_grace_ms)` (300 ms), bonus `⌊50 % × valeur × (1 − t/d)⌋` (98 = ⌊100 × (1 − 81/5 000)⌋ ; 70 = ⌊100 × (1 − 1 481/5 000)⌋ ; 50 au plein à 232 − 300 < 0) ; `game_player` : Robin 420 rang 1, Camille 298 rang 2 ; journal `game` : `round_closed` `early_end`, `early_end`, `duration`, `finalized` `completed`, `game.broadcast_delay` de 12 à 56 ms, aucun `broadcast_failed` ni `transition_failed_twice`.

  **Contrôle anti-triche** — [poste], sur l'enregistrement (`p-analyse.mjs`, scratchpad) : pour chaque client et chaque manche, toutes les trames reçues entre le lancement et la trame `round.revealed` de la manche (diffusions Reverb, réponses de `room.state`, pages Inertia, réponses aux saisies) sont décodées (JSON Pusher à `data` imbriquée, page Inertia `<script data-page>`), et chaque chaîne est comparée à **toutes** les formes du film de la manche (titres de chaque locale, alias, titre original et translittération). Seules sont écartées les quatre propositions du QCM (`seat.choices`, envoi ciblé), vérifiées à part. **Résultat : 0 trame fautive** sur 12 fenêtres (2 parties × 3 manches × 2 clients, 27 à 57 trames chacune) ; témoin positif : la trame `round.revealed` de chaque manche contient bien le titre (le détecteur voit ce qu'il cherche) ; les 9 jeux de propositions reçus ne portent que `v, serverNow, gameRef, sequenceIndex, choices, useOriginalTitle, lang` (aucun indice, index ni drapeau de la bonne réponse), et la bonne réponse y occupe les positions 0, 1, 2 et 3 selon les manches.
- **Écarts** :
  - **Pilote, première partie (n° 1, 14:13)** : la seconde saisie de l'invité, envoyée 0,6 s après la première, a reçu `429 {"message":"One attempt at a time."}` (anti-spam `attemptsPerSecond` = 1 par défaut) ; comportement attendu du produit (la tentative n'est pas décomptée : `wrong_attempts` = 1), défaut du pilote, corrigé par une attente d'1,1 s ; partie menée à son terme sans lui, podium puis « Rejouer » : c'est la partie n° 2 qui fait foi.
  - Le pseudo `Hôte` est refusé à la création (« Ce pseudo n'est pas disponible ») : pseudo réservé, attendu.
  - `GET /brand/tmdb.svg` → **404** à chaque révélation (et sur l'accueil) : logo officiel TMDB non déposé, **geste n° 10 du porteur** (`docs/REPRISE.md`, dû au plus tard à la mise en service) ; l'attribution texte est bien affichée.
  - `storage/logs/game-2026-09-28.log` créé en `0644` par PHP-FPM (première écriture du jour au lancement) : confirme le point resté ouvert de l'écart n° 13 (option `'permission' => 0640` des canaux quotidiens) ; le journal `game` ne porte ni pseudo ni `room_code` (seulement `gameRef`).
- **Sur le VPS Plesk :** mêmes gestes sur deux appareils ; mêmes requêtes en lecture (SSH de l'abonnement, `mysql` avec l'utilisateur de la base Plesk) ; le contrôle anti-triche se fait une fois dans les outils de développement du navigateur (onglet Réseau › WS › Messages, puis « Rechercher » le titre avant la révélation), sans pilote.

### Étape 4.10 — Résilience : worker `game` et Reverb redémarrés en pleine manche, reconnexions

- **But** : `100` § 11 (motif du drainage) et `60` § 12.6 : un redémarrage du worker `game` en pleine manche ne fausse ni le calendrier ni un score (un job par frontière de palier, instants matérialisés dans `round_tier`) ; un redémarrage de Reverb déconnecte tous les joueurs, qui se reconnectent et se resynchronisent (`room.state`) ; un joueur qui perd le réseau, ou qui recharge sa page après `T_N`, retrouve la manche et **les mêmes quatre propositions** (règle 3).
- **Préparation** — [poste], même salon, après « Rejouer » : **hôte**, onglet Simple : « Durée d'une manche » = 20 s (paliers de 10 s, propositions à 10 s), « Nombre de manches » = 10 (pour que le film curé ait de bonnes chances d'être tiré : vivier de 14 films non vus du salon). « Lancer la partie ».
- **Commandes** — [root], pendant la partie (horloge de la VM en avance d'environ 1,3 s sur le poste) :

```bash
# Manche 1, à ~8,5 s (juste avant la frontière du palier 2, à 10 s) :
for u in tripleframes-worker@game tripleframes-worker@default tripleframes-reverb; do printf '%s=%s ' $u "$(systemctl show -p MainPID --value $u)"; done; echo
systemctl restart tripleframes-worker@game
for u in tripleframes-worker@game tripleframes-worker@default tripleframes-reverb; do printf '%s=%s ' $u "$(systemctl show -p MainPID --value $u)"; done; echo
# Manche 2, à ~3 s (palier 1) :
systemctl restart tripleframes-reverb
# Après la partie :
journalctl -u tripleframes-worker@game --since "14:22:15" --until "14:22:30" -o short-precise --no-pager
journalctl -u tripleframes-reverb --since "14:22:25" --until "14:22:35" -o short-precise --no-pager
grep -h '"sequenceIndex":1,"tierIndex":2' /var/www/vhosts/tripleframes-prod.test/tripleframes/storage/logs/game-$(date -u +%F).log | tail -1 | cut -c1-200
```

  Gestes — [poste] : manche 1, l'hôte répond au palier 1 (3 s), l'invité au palier 2 (11,5 s, après le redémarrage) ; manche 2, l'hôte répond pendant la reconnexion (3,6 s), l'invité choisit au QCM ; manche 3, l'hôte passe **hors ligne 5 s** (outils de développement › Réseau › « Hors connexion » ; ici `Network.emulateNetworkConditions`), l'invité **recharge sa page** à 12 s, après l'arrivée des propositions, puis répond ; manches 4 à 10 : réponses rapides des deux (fin anticipée).
- **Attendu et vérification** (partie n° 3, 14:22:05 → 14:23:30 UTC ; captures `g2-*`) :

| Moment | Observé |
| --- | --- |
| Redémarrage du worker `game` (manche 1) | `systemctl restart` rendu en 208 ms ; journal : `Worker STOPPED Interrupted` à 20.894, nouveau worker démarré à 20.907 (PID 250917 → 258776), puis `AdvanceRound … DONE` pour l'ouverture du palier 2 (due à 21.796, heure VM) ; `tier.opened` du palier 2 diffusé avec `broadcast_delay` **74 ms** (33 ms d'ordinaire) ; propositions reçues par l'invité ; invité au palier 2 : 142 points (100 + 42) ; fin anticipée normale |
| Redémarrage de Reverb (manche 2) | rendu en 212 ms (`Gracefully terminating connections`, `Starting server on 127.0.0.1:8090`, PID 250193 → 258859) ; côté navigateurs : `ws-closed` à 28.966, **nouvelle connexion 1,0 s plus tard**, réabonnement (`/broadcasting/auth` 200), puis `GET /r/V5GACS/state` (resynchronisation) ; la réponse de l'hôte, envoyée pendant la reconnexion, est acceptée (257 points) ; bandeau « Connection restored. » chez l'invité ; propositions (`seat.choices`) reçues à `T_N` |
| Hôte hors ligne 5 s (manche 3) | bandeau « Vous êtes hors ligne. Le jeu ne s'interrompt pas pour autant : vérifiez votre connexion. », puis « Connexion rétablie. » et `GET /r/V5GACS/state` au retour en ligne ; la manche continue ; propositions de l'hôte présentes après le retour |
| Invité rechargé après `T_N` (manche 3) | propositions **identiques, dans le même ordre**, avant et après le rechargement (`Dune: Part Two · The Shining · Spirited Away · Finding Nemo`) ; l'onglet rechargé reprend le siège (`seat.superseded` reçu par l'ancienne instance) ; bonne réponse acceptée |
| Partie | 10 manches `completed`, 20 bonnes réponses ; le film curé (film 17, TMDB 129) tiré à la **manche 3**, images de la banque curée servies (palier 1 = frame 87, niveau 1 ; palier 2 = frame 86, niveau 5), trouvé par les deux |

  Contrôles — [root] : les requêtes de l'étape 4.9 sur la partie 3, et le recalcul du bonus de chaque `guess` par la formule de `80` § 4.2 : **20 bonus sur 20 conformes** (dont les deux réponses de la manche 1 encadrant le redémarrage) ; totaux 2 757 et 2 441 égaux à la somme des manches ; `round_tier.served_at` du palier 2 de la manche 1 = instant théorique (`started_at` + 10 000 ms) : le retard du job ne touche aucun instant matérialisé. Anti-triche (étape 4.9) : **0 trame fautive** sur 20 fenêtres (1 484 trames), resynchronisations comprises ; 8 coïncidences écartées et expliquées : la trame `round.revealed` de la manche 1 (film 2) porte les mêmes titres que le film de la manche 3 (film 17). Réponses `/f/<jeton>` : `200`, `X-Robots-Tag: noindex, nofollow`.
- **Écart (propre au catalogue de démonstration)** : le film de démonstration n° 2 et le film curé n° 17 sont **la même œuvre** (« Le Voyage de Chihiro »), publiés tous deux sans `movie_group` : tirés dans la même partie (manches 1 et 3). Le back-office le signale bien (`/admin/catalog/17`, onglet « Même œuvre » : « Candidats : même titre — Regrouper ») ; la répétition ne les a pas regroupés. Sur le VPS : pas de catalogue de démonstration ; pour un homonyme ou un remake, consulter cet onglet avant de publier.
- **Sur le VPS Plesk :** mêmes gestes en root (`systemctl restart tripleframes-worker@game`, `systemctl restart tripleframes-reverb`), à ne jouer que pendant une **partie de test**, jamais pendant une partie de joueurs (un redémarrage de Reverb déconnecte tout le monde) ; le hors ligne se simule dans les outils de développement du navigateur ou en coupant le Wi-Fi du téléphone.

### Étape 4.11 — Partie solo

- **But** : `60` § 16 : démarrage, sondage de `solo.state` (aucun événement), gestes d'entraînement (« Voir la réponse », « Passer la manche », « Manche suivante »), podium, relance ; aucune fuite du titre avant la révélation.
- **Gestes** — [poste], navigateur **tiers** : `/solo/new` › preset « Rapide » (N = 2, D = 7 + 8 s, Facile : propositions dès `T₁`, R = 5 s, M = 8) › pseudo `Alex` › avatar « Panda » › « Commencer l'entraînement » → `/solo`. Première partie (n° 4) : bonne réponse puis observation ; seconde (n° 5), relancée depuis le podium (preset « Rapide », « Commencer l'entraînement ») : m1 bonne proposition, m2 mauvaise, m3 « Voir la réponse », m4 « Passer la manche », m5 rien (fin à `D`) puis « Manche suivante » pendant la révélation, m6 à m8 bonnes propositions.
- **Attendu et vérification** (14:27-14:31 UTC ; captures `solo-*`, `solo2-*`) : parties 4 et 5 `completed` ; propositions dès `T₁` ; « Voir la réponse » → révélation 0,45 s après le geste, 0 point, aucune ligne `guess` ; « Passer la manche » → manche suivante sans révélation ; « Manche suivante » pendant la révélation → manche suivante ; podium « Sans rang » (solo), récapitulatif, « En solo, aucune mémoire ne retient les films déjà vus… » ; relance depuis le podium. Film curé n° 17 tiré à la manche 5 de la partie 4. Anti-triche solo (`p-analyse-solo.mjs`, scratchpad) : 66 paquets `solo.state`/gestes examinés, **0 fuite** dans les 56 paquets hors révélation (`scheduled` 18, `running` 30, `closed` 8), titre présent dans les 10 paquets `revealing` (témoin).
- **Écart — anomalie du produit (BUG-P1, consignée au journal des anomalies de la répétition)** : en solo, une réponse qui clôt la manche (bonne réponse, ou mauvaise proposition) **n'affiche jamais la révélation** : l'écran garde la manche close (« Trouvé ! Gagné : 283 points »), **le chrono continue de décompter**, puis passe directement au décompte de la manche suivante environ 4 s plus tard. Cause relevée sur l'enregistrement : aucune lecture de `solo.state` après la réponse ; la lecture suivante, programmée d'avance (`nextTransitionAt`), tombe après la garde de préchargement de la manche suivante, qui est alors rendue en `scheduled`. La révélation n'est vue que par « Voir la réponse » (le geste répond par le paquet), à `D`, ou par hasard du calendrier. Scores et anti-triche non touchés. Non corrigé dans cette phase.
- **Sur le VPS Plesk :** mêmes gestes sur le téléphone du porteur, après correction de BUG-P1 ; vérifier que la révélation s'affiche après une bonne réponse.

## Phase 5 — Charge

_À remplir à l'exécution. Répétition de l'outillage et de la procédure seulement : la VM (2 vCPU, 3,9 Go, charge émise depuis le même hôte physique) ne dit rien de la décision D33, qui se joue sur le VPS._

## Impasses rencontrées et contournements

- **PHP bloqué sur le poste** (relevé du 28/09) : `php.exe` est refusé par la stratégie de contrôle d'application de Windows (Device Guard). Or la construction des assets appelle `php artisan wayfinder:generate`. Contournement prévu : l'étape PHP du « runner CI » (composer, `wayfinder:generate`) se joue dans un répertoire jetable de la VM, l'étape Node (`npm ci`, `npm run build`) sur le poste. À lever sur le poste du porteur, qui ne peut plus lancer `composer dev` ni les tests non plus. En phase 1, les tests des gabarits (étape 1.1) ont été joués dans la VM, sur un clone jetable, pour la même raison.
- **`mysql -u … ` refusé malgré le bon mot de passe** (phase 1, étape 1.5) : `ERROR 1045 Access denied … (using password: YES)`. Cause : Homestead pose un `/root/.my.cnf` (`user=root`, `password=…`, `host=127.0.0.1`) ; `sudo mysql` se connecte donc en root **par TCP avec mot de passe** (et non par le socket, contrairement au relevé du plan), et le mot de passe du fichier d'options l'emporte sur `MYSQL_PWD`. Levée : `mysql --no-defaults --protocol=socket -u tripleframes_prod` pour tout contrôle sous l'utilisateur dédié. Sur le VPS, vérifier de même qu'aucun `~/.my.cnf` de root ne fausse un contrôle.
- **`tar` de Git Bash et les chemins Windows** (phase 1, étape 1.1) : `tar -cf C:/…/overlay.tar` échoue (`Cannot connect to C: resolve failed` : `C:` est lu comme un hôte distant). Levée : `tar --force-local`.
- **`redis-cli … keys *` sans guillemets** (phase 1, étape 1.6) : l'astérisque est développé par le shell en noms de fichiers du répertoire courant ; la commande reste refusée (`unknown command`), mais écrire `keys '*'`.
- **Vhost devenu serveur par défaut de nginx** (phase 1, étape 1.10, relevé par le contrôle) : un lien dans `/etc/nginx/conf.d/`, choisi pour survivre à `vagrant provision`, est inclus **avant** `sites-enabled/` ; sans `default_server` nulle part, le vhost de répétition captait toute requête par IP, par `localhost` ou pour un nom inconnu, et tout client TLS sans SNI. Levée : lien `sites-enabled/zz-tripleframes-prod.test` (dernier inclus), à recréer après un `provision`, et section « serveur par défaut » dans les relevés avant/après. Sur le VPS : ne jamais désigner `<DOMAINE>` comme site par défaut de l'IP.
- **Unités activées avant le déploiement** (phase 1, étape 1.7, relevé par le contrôle) : sur la VM, le socle précède le checkout ; activées, les unités auraient bouclé au premier redémarrage. Levée : `systemctl disable`, activation reportée en phase 2 après `migrate`.
- **Workflows restés en PHP 8.3** (phase 1, étape 1.1) : la modification de `.github/workflows/tests.yml` et `tests-mysql.yml` a été refusée par le garde-fou de permissions de la session (fichiers de CI). Laissée au porteur (écart n° 1).
- **`PATH` de Git Bash et chemins Windows** (phase 2, étape 2.3) : `export PATH="C:/…/php-stub:$PATH"` ne place pas le répertoire en tête (le `:` de `C:` coupe l'entrée en deux) et le greffon Wayfinder appelle encore le `php.exe` bloqué (`bloqué par la stratégie Device Guard`, construction en échec). Levée : `/c/Users/…` dans le `PATH`, contrôle `cmd //c "where php"`.
- **Chemins convertis par Git Bash** (phase 2, pilote CDP) : un argument `/admin` devient `C:/Program Files/Git/admin`. Levée : `MSYS_NO_PATHCONV=1` devant la commande.
- **`git bundle verify` hors dépôt** (phase 2, étape 2.5) : `need a repository to verify a bundle`. Levée : `git -C ~/git/tripleframes.git bundle verify ~/incoming/…` (chemins absolus).
- **`/login` en 502** (phase 2, étape 2.10) : en-têtes de réponse de PHP-FPM au-delà du tampon de 4 Ko de nginx. Levée : `fastcgi_buffer_size 32k; fastcgi_buffers 16 16k;` dans `ops/nginx/additional-directives.conf` (écart n° 9).
- **Invite masquée de `admin:first-admin`** (phase 2, étape 2.14) : Laravel Prompts exige un vrai terminal ; une session `ssh` sans terminal ne peut pas y répondre. Levée (répétition seulement) : pilote `pty` de Python, mot de passe sur l'entrée standard. Sur le VPS, le porteur tape.
- **Clé TOTP invisible dans le texte du dialogue** (phase 2, étape 2.15) : la clé manuelle est la valeur d'un champ en lecture seule, pas du texte. Levée : lecture de `input.value` dans le dialogue.
- **Collage TMDB « Échoué » sans démarrer** (phase 4, étape 4.3) : état d'un aperçu resté dans l'instance de commande d'un worker `queue:work`. Diagnostic : journal du worker (job `DONE` en 14 ms), ligne `import_run` (`started_at` nul), lecture de `RunCatalogImport` (`closeAsFailed` sur code non nul), puis reproduction en un seul processus `tinker`. Un premier essai de diagnostic (`backup:snapshot`, puis l'appel du job sur le collage n° 1, déjà échoué : « Aucun collage à reprendre ») ne reproduisait pas. Levée : correctif RV-7 et second déploiement par le hook (étapes 4.3, 4.4).
- **Copie des secrets lisible par `vagrant`** (phase 2, étapes 2.7, 2.9 et 2.17, relevée par le contrôle) : `bootstrap/cache/config.php` né en `0664` sous l'umask `0002` de l'utilisateur d'abonnement de la VM, lu par `vagrant` (nginx et pools de développement du porteur) à travers le HOME `0710 tripleframes:vagrant` ; journaux en `0644` sous l'`Umask: 0022` des unités. Levée (RV-10, étape 2.18) : `umask 027` dans le hook et en tête des sessions [abo], `UMask=0027` dans les deux unités PHP, `chmod 0640` des fichiers existants, artefact n° 4 déployé par le hook.
- **`tinker --execute` dans une ligne `ssh '…'`** (phase 3, étape 3.7) : trois niveaux de guillemets (PowerShell ou Git Bash, `ssh`, `bash -c`) ont rendu un `PARSE ERROR` de PsySH. Levée : tout bloc part comme **script par l'entrée standard** (`ssh … 'sudo -u tripleframes -H bash -s' < bloc.sh`), le PHP de `tinker` entre apostrophes dans le script (`backup-cold.sh`). Sur le VPS, en session interactive, le même bloc se colle tel quel.
- **L100-10 absent** (phase 3) : ni `ops/backup/`, ni `backup:manifest`, ni `backup:verify`. Levée (répétition seulement) : préfigurations `backup-hot.sh` et `backup-cold.sh` écrites en entier aux étapes 3.6 et 3.7, posées hors du dépôt ; (e) de la restauration joué par une boucle `sha256sum` qui applique la définition de `backup:verify` (écart n° 3).
- **Se connecter à une cible restaurée qui n'a pas de site** (phase 3, étape 3.9 (h)) : la copie jetable n'a ni vhost, ni certificat, ni pool. Levée : `php artisan serve --host=127.0.0.1` dans la copie, joint depuis le poste par un tunnel SSH (`ssh -N -L 127.0.0.1:18080:127.0.0.1:18080 …`) ; `.env` de la cible en `APP_URL=http://127.0.0.1:18080` et `SESSION_SECURE_COOKIE=false` (HTTP en boucle locale, derrière le tunnel chiffré). Aucun geste root, aucun site Plesk à créer : rejouable tel quel sur le VPS.
- **Horloges du poste et de la VM** (phase 3, chronométrage) : la VM avance d'environ 1,2 s sur le poste. La chronologie de l'étape 3.9 note l'horloge de chaque machine ; le code TOTP (fenêtre de 30 s) n'en est pas affecté.

## État final

_À remplir en fin de répétition : unités actives, sondes, déploiements joués, durée de restauration, relevés de charge indicatifs, ce qui reste en place sur la VM._

**État à la fin de la phase Déploiement (28/09, 12:50 UTC)** : cinq unités `tripleframes-*` actives (`php-fpm`, `redis`, `worker@game`, `worker@default`, `reverb`), les trois dernières activées ; tâche planifiée posée ; `https://tripleframes-prod.test` servi (`/up` 200, `noindex` partout, HSTS court, `wss` de bout en bout, 6390 et 8090 fermés depuis le poste) ; cinq sondes vertes ; trois artefacts `deploy` (`0d33814`, `fe47155`, `e7cdb5c`) : un déploiement manuel (§ 11.6) puis deux par le hook, sans drainage ; administrateur `admin@tripleframes-prod.test` avec second facteur confirmé ; catalogue : 16 films de démonstration + 1 film curé (TMDB 129) publiés, vivier de 17 œuvres à N = 2 et 3 ; trois instantanés (`backup:snapshot` : 12:19, 12:34, 12:37). **Corrections du contrôle (13:11-13:17 UTC, RV-10)** : caches de démarrage et journal en `640`, unités PHP sous `Umask: 0027`, quatrième artefact `deploy` (`a775555`, hook avec `umask 027`) déployé par le hook, sans drainage ; cinq sondes vertes, `/up`, `/login`, `/` en 200, `wss` de bout en bout. **Restent** : transition du drainage (D5), phases 3 (exploitation, tier chaud, restauration), 4 (partie multijoueur et solo), 5 (charge), retour arrière.

**État à la fin de la phase Exploitation (28/09, 13:37 UTC)** : cinq unités `tripleframes-*` actives ; `/up` et cinq sondes vertes, chacune **vue au rouge** puis revenue (`worker-game` 503 à 80 s d'arrêt du worker, `purge` 503 sous suspension, 404 sans jeton, jeton faux ou sonde inconnue) ; trois exécutions complètes de la purge (`purge_run` : 6 lignes `completed` par exécution, 0 ligne supprimée) ; six instantanés `backup:snapshot` (12:19, 12:34, 12:37, 13:27, 13:30, 13:31) ; clé `age` (privée dans `/root/tripleframes-hors-machine/`, publique dans `~/.config/tripleframes/backup-recipient.txt`) ; stockage distant simulé `/srv/tripleframes-stockage-distant-simule/` : deux tiers chauds du jour (`hot/2026-09-28/`, 13:30:52 et 13:31:37), 4 objets froids (`cold/game/`) ; tâches planifiées du tier chaud (03:10 chaque jour) et du tier froid (03:20 le dimanche) posées dans la crontab de `tripleframes` ; contrôle de lisibilité vert ; **restauration chronométrée jouée sur cible jetable : environ 32 s** de la décision au dernier contrôle vert (connexion de l'administrateur, second facteur compris, sur la copie restaurée portant l'`APP_KEY` de l'exemplaire hors machine), cible supprimée ensuite (base, utilisateur, copie, racines, index Redis 8 et 9) ; contrôle final des gabarits conforme (écart n° 5). **Corrections du contrôle (13:58-14:00 UTC)** : `backup-hot.sh` réinstallé (battement par `curl -K -`, sha256 `224d93a2…`, `0750 tripleframes`), section du battement vérifiée seule contre des adresses factices en boucle locale ; tier chaud non rejoué ; aucune autre modification de la VM. **Restent** : transition du drainage (D5), phase 4 (partie multijoueur et solo), phase 5 (charge), retour arrière.

## Écarts à reporter dans les specs

Constats de la phase Plan (28/09), à confirmer par l'exécution :

1. **PHP 8.4, et non 8.3.** Le verrou `composer.lock` exige PHP ≥ 8.4.1 en production (Symfony 8.1) comme en développement (Pest 5, PHPUnit 13) ; sous 8.3, `composer install --no-dev` échoue et les deux workflows sont rouges. À reporter : `ops/` (hook, unités, liste de contrôle, réglages Plesk), `DeployHookTest`, `OpsTemplatesTest`, `.github/workflows/*.yml`, `100` § 10.1 (`plesk-php84-imagick`), § 10.4, § 10.5, § 11.5, `CLAUDE.md` § 3, `composer.json` (`"php"`), `questions-ouvertes.md`. **Confirmé par la phase 1** : corrigés dans le dépôt (non commités) `ops/deploy/hook.sh`, les deux unités, `ops/mise-en-service.md`, `ops/plesk/settings.md`, `DeployHookTest`, `OpsTemplatesTest`, `tests/Load/game-load.js` (11 tests verts sous PHP 8.4) ; **restent** les deux workflows, `composer.json` (et `composer update --lock` sous 8.4), `100`, `CLAUDE.md`, `questions-ouvertes.md`, `docs/annexes/contrats-j1.md` (ordre du hook). L'artefact de la phase 2 doit porter cette correction, sans quoi le hook appellerait un `/opt/plesk/php/8.3/bin/php` absent.
2. **Aucune forge configurée** (`git remote` vide) : la branche `deploy`, les workflows et Plesk Git supposent une forge ; à créer avant la mise en service (`100` § 11.1, § 11.2).
3. **L100-10 non livré** : `ops/backup/`, `backup:manifest` et `backup:verify` n'existent pas ; le tier chaud et l'étape (e) de la restauration (`100` § 13.2, § 13.5) ne sont répétés qu'à la main.
4. **Catalogue de démonstration** : refusé hors `local`/`testing` et dépendant de Faker (dev), il ne peut pas servir sur une installation de production ; la répétition passe par un outillage séparé, **interdit sur le VPS**.

Constats de la phase 1 (28/09) :

5. **Gabarits pas encore « ajustés au relevé »** : port, utilisateur, groupe et chemin restent des `__TF_…__` dans le dépôt ; le `diff` de `ops/mise-en-service.md` § 9 montre donc ces lignes et non les seules lignes `<DOMAINE>`. Attendu par la liste de contrôle (commit « gabarits ajustés au relevé » à l'étape 1 du VPS) ; rien à corriger, mais ce commit est **nécessaire** pour que le contrôle final du VPS soit lisible.
6. **Planificateur et `.env.example`** : `.env.example` pointe le cache et les files sur `127.0.0.1:6379` sans mot de passe. Sur une machine dont le 6379 est un Redis voisin, tout processus de l'application lancé sur un `.env` recopié mais pas encore rempli (tâche planifiée, unité activée démarrée par un redémarrage) écrirait chez le voisin. L'ordre de `ops/mise-en-service.md` (étape 2 `.env` avant l'étape 4) protège ; à dire explicitement dans `100` § 11.6 : **aucune tâche planifiée, ni unité activée ou démarrée, avant un `.env` complet**.
7. **Avertissements de Redis au démarrage** : `vm.overcommit_memory = 0` (réglage du noyau, machine entière : décision du porteur, jamais prise ici) et « TimeoutStartSec / TimeoutStopSec » (Redis 6 sous `supervised systemd` ; les 90 s par défaut de systemd suffisent). À noter dans `100` § 10.3 comme attendus.
8. **Authentification MySQL** : Homestead crée les comptes en `mysql_native_password` ; un MySQL 8 standard (Plesk) en `caching_sha2_password`, que `pdo_mysql` et `mysqldump` de PHP 8.4 gèrent — sans conséquence, à vérifier au relevé du VPS.

Constats de la phase 2 (28/09) :

9. **Tampon fastcgi de nginx** (corrigé dans `ops/`) : l'en-tête `Link` d'`AddLinkHeadersForPreloadedAssets` (jusqu'à 5,1 Ko sur le back-office, 4,1 Ko au lobby) et les deux cookies dépassent les 4 Ko par défaut : 502 « upstream sent too big header ». `fastcgi_buffer_size 32k` et `fastcgi_buffers 16 16k` ajoutés aux directives, ligne `/login` → 200 ajoutée à l'étape 5 de `ops/mise-en-service.md`. À reporter : `100` § 10.5 (bloc des directives), `ops/plesk/settings.md` § 2 si Plesk impose sa valeur.
10. **Import TMDB en échec après un aperçu, en production seulement** (corrigé dans le code, RV-7) : `catalog:import-ids` gardait l'état d'un aperçu d'un `Artisan::call` à l'autre dans un worker `queue:work`. À reporter : `20` § 3.3 (le geste « aperçu puis import » est le chemin nominal) et `100` § 2 (la file `sync` des tests et `queue:listen` du poste ne rejouent pas la vie d'un worker long : tout job qui appelle une commande par `Artisan::call` doit être testé en deux appels successifs).
11. **Compte rendu de `catalog:reproject`** : « Films reprojetés par différence : N » compte les films parcourus, pas les films modifiés (N = taille du catalogue à chaque déploiement). Libellé à préciser (`admin.console.reproject.done`) ou compteur à changer ; aucun défaut de données.
12. **Catalogue de démonstration** (complète le n° 4) : il crée aussi un **second administrateur** (`admin@tripleframes.test`, journalisé `role.changed` par la console), à côté de celui d'`admin:first-admin`. Neutralisé sur la VM (étape 4.2).
13. **Droits des caches de démarrage** (corrigé dans `ops/`, RV-10) : `bootstrap/cache/config.php` (tous les secrets) naissait en `0664` sous l'umask `0002` d'un utilisateur Ubuntu (`0644` sous le `022` habituel de Plesk) ; le HOME `0710` ne protégeait **pas** : son groupe (serveur web) le traversait, et `vagrant` le lisait sur la VM. Corrigé : `umask 027` en tête de `ops/deploy/hook.sh` (`DeployHookTest`), `UMask=0027` dans les unités des workers et de Reverb (`OpsTemplatesTest`), `umask 027` et contrôle `stat` → `640` aux étapes 3 et 5 de `ops/mise-en-service.md`. À reporter : `100` § 10.2 (droits des fichiers de l'application), § 10.4, § 10.5 (unités) et § 11.5 (hook). Reste ouvert : journal quotidien créé d'abord par PHP-FPM ou la tâche planifiée (umask `022`) ; option `'permission' => 0640` du canal `daily`, à décider.
14. **Langue des écrans de compte** : `APP_LOCALE=en` (défaut de `.env.example`, « décision du porteur ») rend en anglais la connexion, la sécurité du compte et l'enrôlement du second facteur pour un navigateur sans préférence ; le back-office reste en français. À trancher avant la mise en service (`APP_LOCALE=fr` ?).
15. **Ordre des prérequis** : la répétition a curé avant le tier chaud ; sur le VPS, l'ordre de `100` § 11.6 (tier chaud avant la première image curée) s'applique sans exception.

Constats de la phase 3 (28/09) :

16. **GTID et restauration par l'utilisateur de l'abonnement** : `backup:snapshot` ne passe pas `--set-gtid-purged=OFF`. Sur un MySQL en `gtid_mode=ON`, chaque passe du vidage porterait `SET @@SESSION.SQL_LOG_BIN` et `SET @@GLOBAL.GTID_PURGED`, que l'utilisateur d'une base Plesk (sans privilège d'administration) ne peut pas rejouer : l'étape (b) de la restauration échouerait, le tier chaud reprenant ce vidage (n° 17). Non reproductible sur la VM (`gtid_mode=OFF`, `log_bin=0`). À relever sur le VPS (`SELECT @@gtid_mode;`) ; si `ON` : ajouter `--set-gtid-purged=OFF` aux deux passes (`100` § 13.1, « Forme livrée » ; `BackupSnapshotCommand`) et rejouer la restauration.
17. **Forme du tier chaud (proposition pour L100-10)** : la préfiguration réutilise `backup:snapshot` pour le vidage (contenu identique à § 13.2, vérification en PHP, mot de passe jamais en argument) plutôt qu'un second `mysqldump` en shell ; elle lit `BACKUP_SNAPSHOT_DIR` et `FRAMES_DISK_ROOT` dans le `.env` (valeurs non guillemetées) ; manifeste au format `condensat<TAB>taille<TAB>chemin relatif`, objets `hot/<AAAA-MM-JJ>/db-<instant>.sql.gz.age` et `frames-manifest-<instant>.tsv.gz.age` ; journal dans `storage/logs/backup.log` (hors rotation de Laravel : destination et rotation à décider). À reporter : `100` § 13.2, L100-10. Ajouts du contrôle de la phase 3 :
    - **L100-10 : aucun secret en argument, battement compris** (`curl -K -`, adresse écrite par `printf` sur l'entrée standard) : la liste des processus d'un VPS mutualisé est lisible des voisins, et une adresse de battement relevée permet de masquer une sauvegarde en échec (correction de l'étape 3.6).
    - **L100-10 : lire `backup.env` par `sed -n 's/^CLE=//p'` plutôt que par `source`, ou exiger des valeurs entre apostrophes** : lu par `. "$CONF"`, une valeur non guillemetée qui contient `&` (URL « push ») laisse la variable vide, et un `;` ou un `$(` serait exécuté (correction de l'étape 3.5).
    - **Effet de bord à trancher par L100-10** : chaque passage laisse un instantané local en clair, gardé `BACKUP_SNAPSHOT_KEEP_DAYS` jours, soit environ 7 vidages de plus en permanence (jusqu'à 1,4 Go au volume de § 13.2), alors que `100` § 13.1 limite ces instantanés aux migrations et aux gestes de console. La VM le montre : 3 instantanés de plus en 5 minutes (13:27, 13:30, 13:31), et un de plus chaque nuit à 03:10. Remède proposé : `rm -f -- "$DUMP"` dans `backup-hot.sh` après les deux `upload` réussis (les instantanés de la règle 12 n'en dépendent pas) ; ou vidage écrit hors de `BACKUP_SNAPSHOT_DIR`.
18. **Cible jetable de la restauration** (`100` § 13.5, à préciser) : `.env` de la copie à `BROADCAST_CONNECTION=log` (sinon la copie diffuserait vers le Reverb de **production** avec sa clé d'application), `OPS_PROBE_TOKEN` et jeton TMDB vides, utilisateur MySQL **dédié**, limité à la base jetable (aucune commande de la copie ne peut toucher la production), index Redis 8 et 9 nettoyés par `--scan` + `unlink` (`FLUSHDB` est renommée) ; accès pour (h) par `php artisan serve` en boucle locale et tunnel SSH, `APP_URL=http://127.0.0.1:<port>`, `SESSION_SECURE_COOKIE=false`.
19. **Tier froid et catalogue de démonstration** : les 82 frames publiées du catalogue de démonstration partagent un seul fichier (un seul `published_hash`) ; l'adressage par condensat n'envoie donc que 4 objets pour 85 frames. Conforme à § 13.3 (« une seule fois ») ; sur le VPS, chaque frame curée a son propre fichier.
20. **Temps de restauration de la VM** : environ 32 s, de la décision au dernier contrôle vert, pour un vidage de 30 Ko et 4 objets froids (≈ 14 s de travail machine). Mesure **indicative**, jamais inscrite dans `100` § 13.6, qui attend la restauration jouée sur le VPS avant la première partie (téléchargement depuis le stockage objet, déchiffrement sur le poste, `scp`, saisie humaine).
21. **Non prouvé par la répétition** : le battement du tier chaud vers la supervision (sonde « sauvegarde de moins de 26 h ») et la réception des alertes par e-mail et second canal (L100-11) ; la clé d'écriture seule et le versionnage du stockage objet (le dépôt simulé `1730` n'empêche pas l'abonnement de supprimer ses propres objets) ; `rclone` (non installé sur la VM).
22. **Notification des tâches de sauvegarde** (proposition, à reporter dans `ops/plesk/settings.md` § 4 si le porteur l'accepte) : § 4 impose « sortie non notifiée » à toutes les tâches de l'abonnement, à raison pour `schedule:run` (un courriel par minute). Pour les deux tâches de sauvegarde, la notification Plesk « seulement en cas d'erreur » donnerait un second signal, en plus du battement. À confirmer au relevé : ce que Plesk tient pour une erreur (code de sortie non nul ou sortie d'erreur), la sortie étant redirigée vers `backup.log`.

## Retour arrière

Liste de ce que la répétition a créé sur la VM, **à compléter par chaque phase**. Désinstallation complète, [root], dans cet ordre (propre à la VM : rien de cela ne se joue sur le VPS, où l'on supprime l'abonnement dans Plesk et les fichiers root de l'étape 4).

Créé par la phase 1 : paquet `age` ; utilisateurs `tripleframes` (HOME `/var/www/vhosts/tripleframes-prod.test`) et `tfredis` ; `/var/www/vhosts/system/tripleframes-prod.test/` ; `/opt/plesk/php/8.4/bin/php` (lien) ; base `tripleframes_prod` et utilisateur `tripleframes_prod@localhost` ; `/etc/tripleframes/` (Redis, `worker-*.env`, `php-fpm/`, `nginx/`, `tls/`) ; unités `tripleframes-redis`, `tripleframes-php-fpm`, `tripleframes-worker@.service` (+ drop-ins `game`, `default`), `tripleframes-reverb` ; `/var/lib/tripleframes-redis/` ; lien `/etc/nginx/sites-enabled/zz-tripleframes-prod.test` ; `/etc/logrotate.d/tripleframes-prod-test` ; `/root/tripleframes-secrets/` ; `/home/vagrant/tripleframes-repetition/` (relevés, *bundle*, clone `tests-s1` et son cache composer).

Créé par la phase 2 : dans le HOME de `tripleframes` (supprimé avec lui) : checkout de déploiement (`vendor/`, `.env`, caches), dépôt nu `git/` (branche `deploy`, quatre commits), `incoming/deploy-{1,2,3,4}.bundle`, `.config/tripleframes/hook.env`, `~/.cache/composer`, instantanés sous `private/tripleframes/snapshots/`, images sous `private/tripleframes/frames/` ; crontab de `tripleframes` ; `/root/tripleframes-hors-machine/` (copie d'`APP_KEY`) ; `/root/tripleframes-secrets/` **supprimé** (transit) ; dans `/home/vagrant/tripleframes-repetition/` : `ci/` (étape PHP du runner), `source-1.bundle`, `deploy-{1,2,3,4}.bundle`, `rv10-overlay.tar`, `wayfinder-1.tar.gz`, `outils/` (pilote `pty`, `sqlp.sh`), copies des fichiers corrigés ; base `tripleframes_prod` : comptes (dont trois de démonstration neutralisés), catalogue de démonstration, film 17. Sur le poste (scratchpad, hors dépôt) : clone `vm/build/src`, *bundles*, profil Chrome et pilote CDP, identifiants de l'administrateur (`vm/admin-credentials.txt`).

Créé par la phase 3 : `/root/tripleframes-hors-machine/backup-age-key.txt` (clé privée `age`, supprimée avec le répertoire) ; `/srv/tripleframes-stockage-distant-simule/` (stockage distant simulé : `hot/`, `cold/game/`) ; dans le HOME de `tripleframes` (supprimé avec lui) : `repetition-backup/` (préfigurations `backup-hot.sh`, `backup-cold.sh`), `.config/tripleframes/{backup.env,backup-recipient.txt,backup-cold-sent.txt}`, trois instantanés de plus, `tripleframes/storage/logs/backup.log` ; deux lignes de plus dans la crontab de `tripleframes` (03:10 et 03:20) ; dans `/home/vagrant/tripleframes-repetition/` : copies de `backup-hot.sh` et `backup-cold.sh`. **Déjà supprimé** par l'étape 3.10 : base et utilisateur `tripleframes_restore_tmp`, `restore-tmp-app/`, `private/restore-tmp/`, `/root/tripleframes-restauration/`, index Redis 8 et 9. Sur le poste (scratchpad) : scripts `vm/exploitation/`, pilote `vm/driver/restore-login.mjs`, profil Chrome `vm/driver/profile-restore/`, captures `30-*` et `31-*`.

```bash
systemctl disable --now tripleframes-worker@game tripleframes-worker@default tripleframes-reverb tripleframes-redis tripleframes-php-fpm
rm -f /etc/systemd/system/tripleframes-redis.service /etc/systemd/system/tripleframes-reverb.service \
      /etc/systemd/system/tripleframes-php-fpm.service /etc/systemd/system/tripleframes-worker@.service
rm -rf /etc/systemd/system/tripleframes-worker@game.service.d /etc/systemd/system/tripleframes-worker@default.service.d
systemctl daemon-reload
rm -f /etc/nginx/sites-enabled/zz-tripleframes-prod.test && nginx -t && systemctl reload nginx
rm -f /etc/logrotate.d/tripleframes-prod-test
crontab -r -u tripleframes 2>/dev/null
# schedule:run tient toute sa minute (everyThirtySeconds) et backup-hot.sh peut tourner à 03:10 :
# sans cela, userdel refuse (« currently used by process », code 8) et laisse le HOME en place.
pkill -TERM -u tripleframes; sleep 3; pkill -KILL -u tripleframes; true
mysql -e "DROP DATABASE IF EXISTS tripleframes_prod; DROP USER IF EXISTS 'tripleframes_prod'@'localhost';"
userdel -r tripleframes
rm -rf /var/www/vhosts/system/tripleframes-prod.test && rmdir /var/www/vhosts/system /var/www/vhosts
userdel tfredis; rm -rf /var/lib/tripleframes-redis
# jamais « rm -rf /opt/plesk » : sur un vrai Plesk, cette ligne le détruirait.
# Le lien n'est retiré que s'il pointe vers le PHP de Homestead (sur Plesk, c'est un vrai binaire) ;
# rmdir échoue sans dommage sur un répertoire non vide.
[ "$(readlink /opt/plesk/php/8.4/bin/php)" = /usr/bin/php8.4 ] && rm /opt/plesk/php/8.4/bin/php && rmdir /opt/plesk/php/8.4/bin /opt/plesk/php/8.4 /opt/plesk/php /opt/plesk
rm -rf /etc/tripleframes /root/tripleframes-secrets /root/tripleframes-hors-machine
rm -rf /srv/tripleframes-stockage-distant-simule
rm -rf /home/vagrant/tripleframes-repetition
NEEDRESTART_MODE=l apt-get remove -y age
```

Puis, [poste], Git Bash, une fois la VM rendue (identifiants de l'administrateur, clé TOTP comprise, et cookies de session des profils Chrome) :

```bash
rm -rf <scratchpad>/vm/admin-credentials.txt <scratchpad>/vm/driver/profile <scratchpad>/vm/driver/profile-restore
```

- **Correction du contrôle de la phase 3** (28/09, 14:00 UTC, bloc documenté seulement) : `userdel -r tripleframes` suivait immédiatement `crontab -r`. Or un `schedule:run` de `tripleframes` est toujours vivant (relevé à 13:58 : PID 256741 et 256744), puisque le battement `everyThirtySeconds` le tient toute la minute (`ops/plesk/settings.md` § 4) ; à 03:10, `backup-hot.sh` peut aussi tourner. `userdel` aurait refusé (code 8), les lignes suivantes se seraient exécutées quand même, et le HOME serait resté en place, avec `.env`, instantanés, images, clé publique et `backup.env`. Ligne `pkill` insérée. Les éléments du poste étaient listés sans commande de suppression, alors que `vm/admin-credentials.txt` porte le mot de passe administrateur et la clé TOTP, et les profils `vm/driver/profile*` des cookies de session : commande ajoutée.

- **Correction du contrôle** (non jouée : bloc documenté seulement) : la ligne `rm -rf /etc/tripleframes /opt/plesk /root/tripleframes-secrets` a été scindée ; collée sur un VPS Plesk, elle aurait détruit Plesk, et la mention « propre à la VM » ne protège que le lecteur attentif. **Écart avec le correctif proposé** (`rm -f /opt/plesk/php/8.4/bin/php && rmdir …`) : sur un vrai Plesk, ce `rm -f` aurait supprimé le vrai binaire PHP 8.4 de tous les abonnements ; le lien n'est donc retiré que s'il pointe vers `/usr/bin/php8.4` (sur Plesk, `readlink` ne rend rien et la ligne s'arrête là). Ce bloc ne se colle **jamais** sur le VPS.
