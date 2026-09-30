# Répétition de la mise en service sur la VM Homestead

## Objet

Rejouer **de bout en bout**, sur la VM Homestead locale, la mise en service de production de TripleFrames telle que la décrivent `docs/specs/100-qualite-tests-et-ci.md` (§ 10 à § 16) et `ops/mise-en-service.md`, pour que le porteur puisse ensuite refaire **les mêmes gestes** sur le VPS Plesk, seul. Chaque étape exécutée sur la VM est consignée ici avec ses commandes exactes, son résultat et ce qui change sur le VPS.

Ce document ne décide rien : en cas d'écart, la spec fait foi (`100` § 10, § 11, § 13, § 15, § 16) ; les écarts découverts par la répétition sont listés dans la section « Écarts à reporter dans les specs et les gabarits », pour être reportés dans les specs propriétaires.

**Pour rejouer sur le VPS**, dans cet ordre : (1) réunir les « Prérequis du VPS Plesk » ; (2) suivre le tableau « Ordre de rejeu sur le VPS », qui n'est **pas** l'ordre des phases de la VM ; (3) pour chaque étape citée, lire sa ligne « **Sur le VPS Plesk :** » d'abord, puis son bloc, en appliquant les « Substitutions systématiques » ; (4) cocher en parallèle `ops/mise-en-service.md`, qui reste la liste de contrôle normative. Les étapes marquées « propre à Homestead » ou « propre à la répétition » ne se jouent jamais sur le VPS. Pour la VM elle-même (ce qui tourne, l'arrêter, la désinstaller) : section « État final », en fin de document.

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
- **`<clé>`** : chemin, sur le poste, de la clé privée SSH de la VM (copie du `key.txt` du porteur) ; toute commande `ssh` ou `scp` de la répétition s'écrit `-i <clé>`. Pendant la répétition, elles portaient aussi `-o UserKnownHostsFile=<scratchpad>/vm/known_hosts` (empreinte de la VM gardée hors du `~/.ssh` du poste) et `-o ConnectTimeout=15`. **`<scratchpad>`** : répertoire jetable du poste, hors dépôt ; son pendant sur la VM est `/home/vagrant/tripleframes-repetition/`. Ni l'un ni l'autre n'existe sur le VPS.
- **« Identique », « même commande », « mêmes gestes »** dans une ligne « Sur le VPS Plesk » : le même bloc, avec les **substitutions systématiques** du tableau ci-dessous ; tout autre changement est écrit dans la ligne.
- **Heures** : UTC, horloge de la VM (environ 1,2 s d'avance sur le poste), sauf mention « (poste) ».

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

### Substitutions systématiques pour le VPS

À appliquer à **tout** bloc de ce runbook rejoué sur le VPS ; elles ne sont pas répétées dans chaque ligne « Sur le VPS Plesk ».

| Sur la VM | Sur le VPS Plesk |
| --- | --- |
| `sudo …`, `ssh … 'sudo bash -s' < bloc.sh` ([root]) | la même ligne tapée dans la session root, sans `sudo` ni `set -e` |
| `sudo -u tripleframes -H bash`, `ssh … 'sudo -u tripleframes -H bash -s' < bloc.sh` ([abo]) | session SSH de l'utilisateur d'abonnement, ouverte par `umask 027` |
| `/var/www/vhosts/tripleframes-prod.test` | `/var/www/vhosts/<DOMAINE>` |
| `tripleframes-prod.test` (URL, `APP_URL`, `REVERB_ALLOWED_ORIGINS`, adresse de l'administrateur) | `<DOMAINE>` |
| `--resolve <hôte>:443:127.0.0.1`, `--cacert /etc/ssl/certs/ca.homestead.homestead.crt`, `-k`, `--ignore-certificate-errors` | rien : DNS public, certificat Let's Encrypt |
| `6390` (Redis), `8090` (Reverb) | ports libres relevés (`__TF_REDIS_PORT__`, `__TF_REVERB_PORT__`) |
| base et utilisateur MySQL `tripleframes_prod` | noms créés par Plesk (`DB_DATABASE`, `DB_USERNAME` du `.env`) |
| `MYSQL_PWD=… mysql --no-defaults --protocol=socket -u tripleframes_prod …` (root) | `mysql -u <DB_USERNAME> -p <DB_DATABASE>` en SSH de l'abonnement, mot de passe à l'invite (ou en root, `--no-defaults` gardé si `/root/.my.cnf` existe) |
| `/usr/local/bin/composer` | `composer.phar` relevé, toujours appelé par `"$PHP"` |
| unité `tripleframes-php-fpm` (master dédié) | `plesk-php84-fpm`, partagé par tous les abonnements en PHP 8.4 : jamais redémarré par TripleFrames |
| dépôt nu `~/git/tripleframes.git`, `~/incoming/*.bundle`, `git checkout -f deploy` | Plesk › `<DOMAINE>` › Git › « Tirer les mises à jour », puis « Déployer » |
| `crontab -u tripleframes` | Plesk › `<DOMAINE>` › Tâches planifiées |
| `/root/tripleframes-hors-machine/` (copie d'`APP_KEY`, clé privée `age`) | deux exemplaires **hors machine** (inventaire scellé, gestionnaire de secrets), jamais sur le serveur |
| `/srv/tripleframes-stockage-distant-simule/` | stockage objet UE d'un autre fournisseur, par `rclone` |
| `192.168.10.10` | adresse du VPS (jamais écrite dans le dépôt) |

## Prérequis

### De la répétition (VM)

- **[poste]** Clé privée SSH de la VM (fichier `key.txt` du porteur) ; `ssh -i <clé> vagrant@192.168.10.10` ouvre une session.
- **[poste]** Pour ouvrir le site dans un navigateur : ajouter, dans `C:\Windows\System32\drivers\etc\hosts` (Bloc-notes lancé en administrateur), la ligne `192.168.10.10 tripleframes-prod.test`, puis `ipconfig /flushdns`. Le certificat est signé par la CA Homestead, que Windows n'approuve pas : cliquer « Continuer », ou importer une fois la CA dans le magasin de l'utilisateur (`certutil -user -addstore Root ca.homestead.homestead.crt`, sans droits administrateur).
- **[poste]** Node et npm (construction des assets) ; k6 1.0 ou plus récent pour la phase « Charge ».
- **VM démarrée** (`vagrant up`), **sans `vagrant provision`** pendant la répétition : le script `clear-nginx.sh` de Homestead vide `/etc/nginx/sites-*`. Le fichier du vhost de répétition vit donc dans `/etc/tripleframes/nginx/` ; seul son lien `sites-enabled/zz-tripleframes-prod.test` disparaîtrait, à recréer (étape 1.10).
- Le porteur est prévenu avant la phase « Charge » : MySQL et le processeur de la VM sont partagés avec son développement.

### Du VPS Plesk (à réunir avant la première commande)

Rien de ce qui suit ne se contourne sur le serveur : sans l'un de ces points, on s'arrête avant de commencer (`ops/mise-en-service.md` § 0).

1. **Domaine acheté** (décision 5 ; `docs/REPRISE.md`, phase 0, point 1). Il fige `<DOMAINE>` partout à la fois : `APP_URL`, `REVERB_ALLOWED_ORIGINS`, nom de l'abonnement Plesk donc tous les chemins absolus, certificat, pages légales, RP ID des passkeys. Enregistrement DNS `A` (et `AAAA` si l'IPv6 est servie) vers le VPS, **propagé** avant la demande du certificat Let's Encrypt. Aucun compte de production ni aucune passkey avant.
2. **Accès root SSH** au VPS, une fois (Redis, unités systemd, paquets) ; région UE (décision 17). Sans root : arrêt et question au porteur (repli S2, second VPS).
3. **Fournisseurs choisis** (phase 0, point 4) :
    - stockage objet **en UE, chez un autre fournisseur que l'hébergeur**, bucket versionné, deux clés : **écriture seule** pour le VPS, lecture et suppression pour le poste (étape 3.5) ;
    - supervision externe qui reçoit le **battement** du tier chaud (interrupteur d'homme mort) et interroge `/up` et les cinq sondes, avec alerte par e-mail **et** par un second canal (`100` § 15, étape 32 du REPRISE) ;
    - gestionnaire de secrets et inventaire scellé : les **deux exemplaires hors machine** d'`APP_KEY`, de la clé privée `age` et des codes de secours du second facteur.
4. **Forge configurée** (écart n° 2) : dépôt distant, workflows `tests.yml` et `tests-mysql.yml` passés **en PHP 8.4** et verts sur `main` (écart n° 1), branche `deploy` publiée par le job `artifacts`, clé de déploiement de Plesk Git enregistrée **en lecture seule**.
5. **Code sur `main`** : les lots de `ops/mise-en-service.md` § 0 et `ops/` dans son dernier état (corrections de cette répétition comprises) ; pour la phase D, les correctifs BUG-P1 et BUG-P2 (`7996902`, `9ed326c`) ; avant la première image curée, L100-10 (`ops/backup/`, `backup:manifest`, `backup:verify`) — à défaut, les préfigurations des étapes 3.6 et 3.7.
6. **Poste** : Git Bash (ou WSL) et client SSH ; `age` et `age-keygen`, `rclone`, Node (`npx wscat`), k6 1.0 ou plus récent (binaire officiel, empreinte vérifiée) ; `php` et `composer` utilisables pour `composer ci:check` avant chaque poussée (voir « Impasses ») ; application d'authentification (TOTP) sur le téléphone.
7. **Secrets à portée de main**, dans le gestionnaire, jamais dans un fichier du dépôt : jeton TMDB v4 (lecture), mot de passe de l'administrateur (12 caractères au moins, majuscules et minuscules, chiffres, symboles, absent des fuites connues).
8. **Voisins du VPS** : liste des sites hébergés et de leurs propriétaires (relevé avant/après de l'étape 1.0, séance de charge annoncée à une heure creuse, étape 5.4).
9. **Logo TMDB** déposé (`docs/REPRISE.md` § 3, geste 10), au plus tard à la mise en service (étape 4.9 : sans lui, `/brand/tmdb.svg` répond 404).
10. **Décisions à prendre avant le `.env`** (« Écarts à reporter », partie C) : `APP_LOCALE` (n° 14), droits des journaux quotidiens (n° 13), tier froid hebdomadaire ou quotidien (N100-2, étape 3.7).

## Durées mesurées

Le 28/09/2026, à l'horloge de la VM (UTC). Les gestes ont été joués par des blocs envoyés d'un seul tenant et par des pilotes de navigateur : un porteur qui tape à la main ira plus lentement.

| Phase | Exécution | Contrôle et corrections | Contenu |
| --- | --- | --- | --- |
| 0 — Relevé | 11:05 → 11:23 (moins de 20 min) | — | lecture seule |
| 1 — Socle | 11:23 → 11:38 (15 min) | 11:59 → 12:01 (2 min) | `age`, utilisateur, PHP, base, Redis, unités, PHP-FPM, TLS, nginx, rotation |
| 2 — Déploiement | 12:10 → 12:50 (40 min, catalogue compris) | 13:11 → 13:17 (6 min, RV-10) | artefacts n° 1 à 3, `.env`, schéma, démarrage, vérifications, administrateur et second facteur, hook |
| 3 — Exploitation | 13:21 → 13:37 (16 min) | 13:58 → 14:00 (2 min) | sondes et alertes, purge, instantanés, sauvegardes, restauration |
| 4 — Partie | 14:04 → 14:53 (49 min) | 15:10 → 15:25 (15 min) | parties, résilience, solo, transition du drainage |
| 5 — Charge | 15:26 → 16:50 (1 h 24) | 17:25 → 17:50 (25 min) | référence des voisins, répétition à deux salons, séance A, A2 et B réduites, deux déploiements |
| 6 — Clôture | 18:00 → 18:40 (40 min) | relecture finale (étape 6.7) | BUG-P1, BUG-P2, artefact n° 8 |
| **Total** | **11:05 → 18:40 : 7 h 35 de bout en bout**, dont environ 4 h 20 de gestes et 50 min de corrections ; le reste en contrôles de phase | | |

| Geste | Durée mesurée |
| --- | --- |
| `composer install --no-dev` (cache vide) | 10 s |
| `npm run build` (poste) | 10 s |
| hook sans drainage, `[1/12]` à `[11/12]` | 1 à 6 s |
| hook complet, `[1/12]` à `[12/12]` | 7 s |
| procédure § 11.4 complète sans partie en cours (drainage, garde, « Déployer », hook, levée) | 23 à 24 s |
| `deploy:drain` avec une partie en cours | 92 s (fenêtre libre 18 s après la fin de la partie) |
| sonde `worker-game` au rouge après l'arrêt du worker | 80 s (seuil 90 s) ; retour au vert 5 s après le redémarrage |
| restauration chronométrée, de la décision au dernier contrôle vert | environ 32 s (vidage de 30 Ko) : **ne vaut que pour la VM** |
| redémarrage du Redis dédié → trois unités applicatives relevées | environ 5 s |
| reconnexion des navigateurs après un redémarrage de Reverb | 1 s en partie ; environ 16 à 20 s pour un lobby après `reverb:restart` |
| séance A à l'échelle D33 (20 salons) | saturation de la VM, arrêt au bout de 6 min 27 s |

**Estimation pour le VPS** (non mesurée, gestes tapés à la main) : jour de la mise en service (étapes 27 à 29 du REPRISE : relevé, Plesk, `.env`, gestes root, démarrage, administrateur, hook) une demi-journée, hors propagation DNS ; sauvegardes, supervision et alertes volontaires (étapes 30 à 32) 2 à 3 h sur deux jours (le contrôle de lisibilité se fait le lendemain) ; transition du drainage 30 min ; restauration chronométrée 1 h (plusieurs minutes pour la restauration elle-même) ; séance de charge 2 h à une heure creuse, plus les deux déploiements des limiteurs.

## Ordre de rejeu sur le VPS

L'ordre du VPS n'est **pas** celui des phases de la VM : sur la VM, le socle root précède le déploiement (étape 1.1), le catalogue de démonstration précède les sauvegardes (étape 4.1), la restauration précède la transition du drainage. Sur le VPS, l'ordre est celui de `ops/mise-en-service.md` puis de l'annexe de `docs/REPRISE.md` :

| # | Geste sur le VPS | Étapes de ce runbook (lignes « Sur le VPS Plesk ») | `ops/mise-en-service.md` | REPRISE |
| --- | --- | --- | --- | --- |
| 1 | Prérequis réunis | Prérequis (VPS) | § 0 | phase 0 |
| 2 | Relevé du VPS ; relevé « avant » des voisins | 0.1, 1.0 | § 1 | 26, 27 |
| 3 | Plesk : abonnement, PHP 8.4, base, certificat, Git ; commit « gabarits ajustés au relevé » ; premier « Déployer » | 1.3, 1.4, 1.5, 1.9, 2.5 | § 2 (étape 1) | 27 |
| 4 | `.env`, `APP_KEY` hors machine | 2.6 | § 3 (étape 2) | 27 |
| 5 | Dépendances, schéma, données de plateforme | 2.7 | § 4 (étape 3) | 27 |
| 6 | Root : `age`, Redis dédié, unités (activées, non démarrées), PHP-FPM, directives nginx, rotation, planificateur, pare-feu, contrôle des gabarits | 1.2, 1.6, 1.7 et 2.8, 1.8, 1.10, 1.10 bis, 1.11, 1.12 | § 5 (étape 4) | 27 |
| 7 | Démarrage, vérifications HTTP, sondes, `wss`, premier instantané ; relevé « après » | 2.9 à 2.13, 1.13 | § 6 (étape 5) | 27 |
| 8 | Premier administrateur et second facteur | 2.14, 2.15 | § 7 (étape 6) | 28 |
| 9 | `hook.env`, premier déploiement par le hook (sans drainage), contrôle final des gabarits | 2.16, 2.17, 3.12 | § 8, § 9 (étape 7) | 29 |
| 10 | Purge, instantané, clé `age`, stockage, tiers chaud et froid, tâches planifiées | 3.3 à 3.7 | — (L100-10) | 30 |
| 11 | Contrôle de lisibilité, le lendemain | 3.8 | — | 31 |
| 12 | Supervision, puis une alerte volontaire par sonde | 3.1, 3.2 | — (L100-11) | 32 |
| 13 | Curation : import, banque, revue, publication (**jamais** 4.1 ni 4.2) | 4.3, 4.5 à 4.7 | § 11 | 57 à 61 |
| 14 | Déploiement du moteur par la transition du drainage | 4.12, 4.13 | — (`100` § 11.4) | 126 |
| 15 | Recette sur appareils réels (partie, résilience, solo, correctifs) | 4.8 à 4.11, 4.14, 6.4, 6.5 | — | 127 |
| 16 | Restauration chronométrée sur cible jetable | 3.9 à 3.11 | — | 128 |
| 17 | Charge : référence, répétition à deux salons, séance, nettoyage, limiteurs relevés puis rétablis | 5.1 à 5.14 | — | 130, 131 |
| 18 | Conditions de la première partie, puis première vraie partie | — | — | 133, 134 |

**Ne se jouent pas sur le VPS** : les étapes d'artefact (2.1 à 2.4, et les parties « poste » de 2.16, 2.18, 4.4, 4.12, 5.7, 5.11, 6.2 : c'est la CI) ; les rattrapages propres à la répétition (2.18, 6.3 : sur le VPS, seul leur contrôle `stat` subsiste) ; le catalogue de démonstration (4.1, 4.2 : **interdit**) ; le voisin témoin et le garde-fou automatique (5.2, 5.3 : on mesure les vrais voisins et l'on surveille à la main) ; les correctifs de code (6.1 : ils arrivent par `main`).

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

- **Écarts** : voir « Écarts à reporter dans les specs et les gabarits », partie D, points 1 à 4.
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
export PATH="$(cygpath -u '<scratchpad>')/vm/build/php-stub:$PATH"
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
export PATH="$(cygpath -u '<scratchpad>')/vm/build/php-stub:$PATH"
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
export PATH="$(cygpath -u '<scratchpad>')/vm/build/php-stub:$PATH"
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

### Étape 4.12 — Transition du drainage : artefact d'activation, « Tirer » (plan D5, étape 126 du REPRISE)

- **But** : `100` § 11, transition I-13 : le commit d'activation retire le préfixe `# drain: ` des deux lignes du hook (étapes 3 `deploy:guard` et 12 `deploy:release`) ; il est livré par un déploiement joué selon la procédure **complète** du § 11.4. Ici, commit **dans le clone jetable seulement**, jamais dans le dépôt du porteur.
- **Commandes** — [poste], Git Bash, dans `<scratchpad>/vm/build/src` : édition des deux lignes de `ops/deploy/hook.sh` (préfixe `# drain: ` retiré, rien d'autre ; équivalent : `sed -i 's/^# drain: step 3 /step 3 /; s/^# drain: step 12 /step 12 /' ops/deploy/hook.sh`), puis :

```bash
bash -n ops/deploy/hook.sh && echo "bash -n OK"
git diff --stat                                  # ops/deploy/hook.sh | 4 ++--
git commit -qam "répétition : activation du drainage dans le hook (I-13, étapes 3 et 12), jamais poussé"
export PATH="$(cygpath -u '<scratchpad>')/vm/build/php-stub:$PATH"
cp public/build/manifest.json ../manifest-4.json
npm run build
cmp -s public/build/manifest.json ../manifest-4.json && echo "manifest identique au build n° 4"
refused="$(find public/build \( -iname '*.php' -o -iname '*.php[0-9]' -o -iname '*.phtml' -o -iname '*.pht' -o -iname '*.phps' -o -iname '*.phar' -o -name '.*' \) -print)"
[ -z "$refused" ] && echo "Verify Build Contents : OK" || echo "REFUSÉS : $refused"
bash -s < <scratchpad>/vm/deploiement/rv10-artifacts.sh      # bloc de l'étape 2.4
git diff --stat deploy~1 deploy
git bundle create ../deploy-5.bundle deploy
scp -i <clé> ../deploy-5.bundle vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/deploy-5.bundle
```

  Puis [vagrant] → [abo], « Tirer » **sans déployer** (`100` § 11.4, étape 2) :

```bash
H=/var/www/vhosts/tripleframes-prod.test
sudo install -o tripleframes -g tripleframes -m 0600 /home/vagrant/tripleframes-repetition/deploy-5.bundle "$H/incoming/deploy-5.bundle"
sudo -u tripleframes -H bash
umask 027
cd ~
git -C ~/git/tripleframes.git bundle verify -q ~/incoming/deploy-5.bundle && echo "bundle vérifié"
git -C ~/git/tripleframes.git fetch -q ~/incoming/deploy-5.bundle deploy:deploy
git -C ~/git/tripleframes.git log --oneline -2 deploy
grep -c '^step \(3\|12\) ' ~/tripleframes/ops/deploy/hook.sh     # 0 : le checkout n'a pas bougé
```

- **Attendu et vérification** (vers 14:38 UTC) : commit source `047b1ba` ; `npm run build` : manifest identique au build n° 4 ; `Verify Build Contents : OK` ; « Publié : deploy 9e5ed5a…, source 047b1ba… », parent `a775555`, différence limitée à `ops/deploy/hook.sh` (2 lignes) ; 115 fichiers sous `public/build/`, ni `.github/`, ni `vendor/`, ni `.env` ; « bundle vérifié » ; `deploy` = `9e5ed5a` au-dessus de `a775555` ; checkout de production inchangé (0 ligne active).
- **Écart** : le commit d'activation de la spec a **deux** moitiés — les deux lignes du hook **et** `deployHookDrainSteps()` qui rend `[]` dans `tests/Feature/Deploy/DeployHookTest.php`. La seconde n'a pas été faite dans le clone jetable : la modification d'un fichier de test a été refusée par le garde-fou de permissions de la session. Sans effet sur ce qui est déployé (les tests ne tournent pas sur le serveur), mais le vrai commit d'activation (étape 126) doit porter les deux moitiés, sinon `DeployHookTest` passe au rouge en CI (étapes 3 et 12 trouvées actives alors que la liste les dit absentes).
- **Sur le VPS Plesk :** le commit d'activation est fait par le porteur dans le dépôt (les deux moitiés), poussé, les deux workflows verts sur ce commit (§ 11.4, étape 1), le job `artifacts` publie `deploy` ; puis « Tirer les mises à jour » dans Plesk Git, **sans** « Déployer ».

### Étape 4.13 — Drainage pendant une partie, puis second déploiement par le hook (D31, D32)

- **But** : `100` § 11.3 et § 11.4, procédure complète : une partie en cours n'est ni coupée ni retardée ; pendant le drainage, tout lancement (multijoueur, « Rejouer », solo) est refusé avec `common.maintenance.launch_blocked`, le bandeau paraît à la réponse Inertia suivante ; la fenêtre libre s'ouvre après deux relevés nuls ; `deploy:guard` rend 0 ; le hook joue `[1/12]` à `[12/12]` et lève le drapeau.
- **Préparation** — [poste] : **salon A** `555FP9` (hôte `Camille`, invité `Robin` : le salon `V5GACS` refusait tout lancement, « Films jouables : 1 pour 3 manches — Lancement impossible : pas assez de films jouables avec ces réglages. Ce salon a déjà joué la plupart des films disponibles. Créer un nouveau salon (17 films jouables…) », mémoire du salon après 16 films joués) réglé N = 2, D = 20 s, R = 3 s, M = 3, Normal ; **salon B** `V2GV6M` au lobby (hôte `Zoé`, navigateur tiers ; invité `Yann`, contexte de navigation isolé du même navigateur), N = 2, D = 10 s, R = 3 s, M = 3 ; **onglet solo** (navigateur tiers) au podium de la partie 5. [abo] `deploy:guard` → **code 2** (aucune fenêtre).
- **Commandes** — [poste] : hôte A « Lancer la partie » (partie 6). Puis [abo], **dans `tmux`** (une coupure SSH ne doit pas tuer la commande ; à défaut, elle vaut abandon à l'échéance) :

```bash
sudo -u tripleframes -H bash          # sur le VPS : SSH de l'utilisateur d'abonnement
umask 027
cd ~/tripleframes
tmux has-session -t tf-drain 2>/dev/null && echo "tf-drain déjà ouverte (déploiement précédent) : lire ~/drain.log, puis tmux kill-session -t tf-drain"
tmux new-session -s tf-drain -c ~/tripleframes
# dans la session tmux :
/opt/plesk/php/8.4/bin/php artisan deploy:drain 2>&1 | tee -a ~/drain.log; echo "deploy:drain code=${PIPESTATUS[0]}" | tee -a ~/drain.log
# détacher : Ctrl-b puis d ; revenir : tmux attach -t tf-drain
```

  (Joué par le pilote sous la forme détachée équivalente : `tmux new-session -d -s tf-drain -c ~/tripleframes "/opt/plesk/php/8.4/bin/php artisan deploy:drain 2>&1 | tee -a ~/drain.log; echo \"deploy:drain code=\${PIPESTATUS[0]}\" | tee -a ~/drain.log; sleep 900"`.) Dans une seconde session [abo], pendant le drainage puis à la fenêtre :

```bash
cd ~/tripleframes
/opt/plesk/php/8.4/bin/php artisan deploy:guard; echo "deploy:guard code=$?"
cat ~/drain.log
```

  Gestes — [poste], pendant le drainage : hôte B « Lancer la partie » ; onglet solo : preset « Rapide », « Commencer l'entraînement » ; invité B : rechargement de la page ; à la fin de la partie 6, hôte A « Rejouer », puis rechargement. À la fenêtre, **dans la seconde session [abo], hors de `tmux`** (celle du bloc précédent, ouverte par `umask 027`) — « Déployer », puis fermeture de la session du drainage :

```bash
cd ~
# « Déployer » émulé sous umask 022, comme le bouton de Plesk (correction de la Clôture, étape 6.3) ; sur le VPS : le bouton
(umask 022 && git --git-dir=$HOME/git/tripleframes.git --work-tree=$HOME/tripleframes checkout -f deploy)
echo "public/ illisible des autres comptes : $(find $HOME/tripleframes/public ! -perm -o=r | wc -l)"   # 0
cd ~/tripleframes
grep -cE '^step (3|12) ' ops/deploy/hook.sh        # 2
bash ops/deploy/hook.sh; h=$?; echo "code du hook = $h"
/opt/plesk/php/8.4/bin/php artisan deploy:guard; echo "deploy:guard code=$?"
if [ "$h" -eq 0 ]; then /opt/plesk/php/8.4/bin/php artisan deploy:release; echo "deploy:release code=$?"; else echo "hook en échec : drapeau laissé en place (100 § 11.5), deploy:release seulement une fois l'état réparé"; fi
cat ~/drain.log                                      # sortie complète de deploy:drain, « deploy:drain code=0 » en dernière ligne
tmux kill-session -t tf-drain                        # [abo], dans la seconde session (hors tmux), une fois ~/drain.log lu
tmux ls 2>&1                                         # « no server running on /tmp/tmux-<uid>/default » (ou aucune ligne tf-drain)
exit
```

  `tmux kill-session` se tape **avant** `exit`, dans la session de l'utilisateur d'abonnement : le serveur `tmux` est propre à chaque utilisateur (`/tmp/tmux-<uid>/`). Tapé après `exit`, la ligne tourne sous `vagrant` sur la VM (« no server running ») ou ne s'exécute jamais sur le VPS (la session SSH est fermée) ; `tf-drain` resterait alors ouverte, et le `tmux new-session -s tf-drain` du déploiement suivant échouerait (« duplicate session: tf-drain »). La session `tmux` du drainage n'est pas fermée plus tôt : sa sortie reste lisible par `tmux attach -t tf-drain` jusqu'au bout du déploiement.

  **Correction du contrôle de la phase Charge (bloc corrigé après coup, non rejoué)** : `deploy:release` n'est plus tapé si le hook a échoué. Même défaut que le script détaché de l'étape 5.7 : `100` § 11.5 veut que le drapeau tienne après un échec du hook avant son étape 12, jusqu'à ce que l'état soit réparé (remède : relancer `deploy:drain`, puis les actions de déploiement Plesk ; `deploy:release` à la main en dernier recours). Le déroulé joué à 14:47 (hook en code 0) est inchangé.

  Puis [root] : bloc de sondes de l'étape 3.1, et `stat -c '%a %U %n' /var/www/vhosts/tripleframes-prod.test/tripleframes/bootstrap/cache/*.php`.
- **Attendu et vérification** (horloge du poste ; journal `vm/partie/logs/drainage.marks.jsonl`, captures `drain-*`) :

| Instant | Observé |
| --- | --- |
| 14:45:28 | partie 6 lancée (salon A) |
| 14:45:31 | `deploy:drain` : « Drainage commencé : aucune nouvelle partie ne peut plus être lancée. Attente de la fin des parties en cours. », tableau des parties (`multiplayer · running · 0/3`), « Parties encore en cours : 1 » |
| 14:45:34 | `deploy:guard` → « Garde refusée : parties en cours : 1. Rien ne doit être migré ni redémarré… », **code 1** |
| 14:45:36 | salon B « Lancer la partie » → **refusé** : « Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. Réessayez un peu plus tard. », bandeau affiché |
| 14:45:37 | solo « Commencer l'entraînement » → **refusé**, même message, bandeau |
| 14:45:38 | invité B rechargé : bandeau « Une mise à jour du site est en préparation : aucune nouvelle partie ne peut être lancée pour le moment. Les parties en cours continuent normalement. » |
| 14:45:57 | partie 6, manche 2 : **la partie continue** (`deploy:guard` code 1) |
| 14:46:43 | partie 6 terminée normalement (3 manches `completed`, podium) |
| 14:46:45 | salon A « Rejouer » → **refusé** (même message), le salon reste au podium ; rechargé : bandeau |
| 14:47:03 | `deploy:drain` : « Fenêtre libre ouverte jusqu'à 2026-09-28T15:17:02Z : aucune partie en cours. Vérifiez deploy:guard, puis cliquez « Déployer » dans Plesk. », **code 0** (92 s en tout, 18 s après la fin de la partie : deux relevés nuls) ; `deploy:guard` → « Garde franchie : fenêtre libre ouverte et aucune partie en cours. », **code 0** |
| 14:47:03 → 14:47:10 | hook, **7 s**, code 0 : `[1/12]` « Nothing to install, update or remove » ; `[2/12]` `optimize:clear --except=cache` ; **`[3/12]` `deploy:guard` « Garde franchie »** ; `[4/12]` « Aucune migration en attente » ; `[5/12]` « Nothing to migrate » ; `[6/12]` `PlatformDataSeeder … DONE` ; `[7/12]` « Films reprojetés par différence : 17 » ; `[8/12]` `optimize` ; `[9/12]` empreinte `b8e47251…` inchangée ; `[10/12]` « Broadcasting queue restart signal » ; `[11/12]` « Broadcasting Reverb restart signal » ; **`[12/12]` `deploy:release` « Drapeau de drainage levé : les lancements sont de nouveau permis. »** |
| 14:47:16 | PID des workers et de Reverb tous changés (258776/258137/258859 → 261297/261305/261302) ; `deploy:guard` → code **2** ; `deploy:release` → « Aucun drapeau de drainage : rien à lever », code 0 ; invité B rechargé : **plus de bandeau** |
| 14:48-14:50 | après rechargement des pages (voir l'écart) : « Rejouer » admis (salon A), « Lancer la partie » admis (salon B, partie 7, 3 manches `completed`), démarrage solo admis (partie 8, 8 manches passées, podium) |

  Anti-triche (étape 4.9) sur les parties 6 et 7 : 0 trame fautive (6 fenêtres et 132 trames pour la partie 6 ; 3 fenêtres pour la partie 7, une fois écartées les 15 occurrences venues de l'onglet solo du même navigateur, qui sont les récapitulatifs de podium des parties solo 5 et 8).

  Sondes après le déploiement (14:51) : cinq `ok`, `/up` 200, sans jeton et jeton faux 404, battements `game` et `default` frais ; caches de démarrage `config.php`, `events.php`, `routes-v7.php`, `lang-version.php` en `640` (`tripleframes`) ; **`packages.php` et `services.php` en `750`** : attendu, ils sont réécrits à chaque hook par `package:discover` (`Filesystem::replace`, qui applique `0777 − umask`, soit `750` sous l'umask `027`) et ne contiennent aucun secret (liste des paquets et fournisseurs de services) — déjà relevé à l'étape 2.18 ; seuls les quatre caches cités doivent être en `640`, et aucun fichier ne doit être lisible par « les autres » (dernier chiffre `0`). En base, 8 parties `completed`, aucune `running`. Reverb redémarré par le hook : les lobbies se reconnectent seuls ; la première tentative, une seconde après l'arrêt, tombe pendant le `RestartSec=2` de l'unité, la suivante réussit environ 16 s plus tard (délai de reconnexion du client) — aucune partie n'était en cours.
- **Écart — anomalie du produit (BUG-P2, journal des anomalies)** : après la levée du drapeau, les trois pages qui avaient vu le drainage (hôte A au podium, hôte B au lobby, solo au podium) gardent le bandeau et le motif « Réessayez un peu plus tard » avec **« Rejouer », « Lancer la partie » et « Commencer l'entraînement » désactivés**, jusqu'à un rechargement manuel : la prop `maintenance` n'est relue qu'à une réponse Inertia, et le bouton désactivé empêche justement la requête qui la relirait. Contournement pour le porteur : après chaque déploiement, prévenir les joueurs présents de recharger la page.
- **Observation (non reproduite)** : le premier rechargement de l'onglet de l'hôte B après le hook l'a laissé dans l'état « Vous jouez désormais dans un autre onglet… » (deux `seat.superseded` à 18 ms d'intervalle sur son canal de siège) ; un second rechargement l'a rétabli. Onglet piloté en arrière-plan par CDP (`bringToFront` juste avant) : probablement propre au pilote, à surveiller sur le VPS.
- **Impasses levées (pilote)** : (1) un onglet d'arrière-plan de Chrome headless ne rend plus d'image, et `page.click()` y attend sans fin (dépassement `Runtime.callFunctionOn`) : `bringToFront()` avant chaque geste ; (2) la création d'un salon est une visite Inertia (XHR + `pushState`), pas une navigation : attendre l'adresse `/r/<code>` plutôt que `waitForNavigation` (sinon le code lu vaut `new`, puis `/r/new/join` → 404) ; (3) salon `V5GACS` épuisé par sa mémoire (16 films joués sur 17) : nouveau salon, comme l'écran le propose.
- **Sur le VPS Plesk (déploiement) :** procédure du § 11.4 telle quelle : (1) workflows verts sur le commit source ; (2) Plesk › Git › « Tirer les mises à jour » ; (3) SSH de l'abonnement, `tmux`, `deploy:drain` jusqu'au code 0 (un code 1 = abandon à l'échéance, un code 2 = drainage déjà en cours : arrêter) ; (4) `deploy:guard` → 0 ; (5) Plesk › Git › « Déployer » (les fichiers sont déposés puis les actions additionnelles lancent `bash ops/deploy/hook.sh`) ; (6) lire la sortie dans Plesk, vérifier `[3/12]` et `[12/12]`, puis sondes et `stat -c '%a %U %n' bootstrap/cache/*.php` (quatre caches en `640`, `packages.php` et `services.php` en `750`) ; (7) dans la seconde session SSH de l'abonnement, **hors de `tmux`** et **avant** de la quitter : `cat ~/drain.log`, `tmux kill-session -t tf-drain`, `tmux ls` (plus de `tf-drain`), puis `exit` — sinon le déploiement suivant bute sur « duplicate session: tf-drain ». Tant que BUG-P2 n'est pas corrigé, annoncer aux joueurs présents de recharger la page après le déploiement.

### Étape 4.14 — Redis dédié redémarré hors partie, rattrapage `game:reschedule`

- **But** : plan P4 ; `60` (rattrapage des parties après une perte de Redis) : un redémarrage du Redis dédié, **hors partie**, ne laisse aucun service applicatif à terre.
- **Commandes** — [root] (`vm/partie/vm/p-redis.sh`) :

```bash
APP=/var/www/vhosts/tripleframes-prod.test/tripleframes; E="$APP/.env"
MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' $E)" mysql --no-defaults --protocol=socket -u tripleframes_prod tripleframes_prod -N \
    -e "SELECT COUNT(*) FROM game WHERE status IN ('running','paused')"          # 0 : aucune partie
for u in tripleframes-redis tripleframes-worker@game tripleframes-worker@default tripleframes-reverb; do printf '%s=%s ' $u "$(systemctl show -p MainPID --value $u)"; done; echo
systemctl restart tripleframes-redis
sleep 8
for u in tripleframes-redis tripleframes-worker@game tripleframes-worker@default tripleframes-reverb; do printf '%s=%s ' $u "$(systemctl show -p MainPID --value $u)"; done; echo
sudo -u tripleframes -H bash -c 'umask 027; cd ~/tripleframes && /opt/plesk/php/8.4/bin/php artisan game:reschedule; echo "game:reschedule code=$?"'
journalctl -u tripleframes-worker@game --since "-1min" --no-pager -o short-precise | tail -12
```

  Puis le bloc de sondes de l'étape 3.1.
- **Attendu et vérification** (14:52:56 UTC) : redémarrage de Redis en 0,25 s (arrêt 14:52:56,74, `Started` 14:52:56,98) ; **les deux workers** perdent leur connexion et sortent **en code 1** (`Stream is already at the end [tcp://127.0.0.1:6390]` ; `status=1/FAILURE` à 14:52:56,85 pour `game`, 14:52:57,33 pour `default`), puis sont **relevés par systemd 2 s plus tard** (`Scheduled restart job` à 14:52:58,89 et 14:52:59,35) ; **Reverb** s'arrête seul, sans message, **en code 0** (`tripleframes-reverb.service: Deactivated successfully` à 14:52:59,60, environ 3 s après le redémarrage de Redis), et c'est **`Restart=always`** qui le relance 2 s plus tard (`Scheduled restart job` 14:53:01,64, « Starting server on 127.0.0.1:8090 » 14:53:02,01 : ≈ 5 s au total) — avec `Restart=on-failure`, Reverb resterait à terre. Contrôle des sorties :

```bash
journalctl -u tripleframes-worker@game -u tripleframes-worker@default -u tripleframes-reverb --since "-2min" --no-pager -o short-precise \
    | grep -E 'exited|Deactivated|Scheduled restart|Starting server'
systemctl show -p Restart,RestartUSec tripleframes-reverb     # Restart=always, RestartUSec=2s
```

  PID tous changés (Redis 237325 → 261868, workers 261297/261305 → 261882/261887, Reverb 261302 → 261897) ; `game:reschedule` code 0 (rien à rattraper) ; cinq sondes `ok`, `/up` 200.
- **Sur le VPS Plesk :** même geste en root, **jamais pendant une partie** (une partie en cours perdrait ses jobs différés : c'est le cas que `game:reschedule` rattrape, `100` § 13.5 (g)) ; vérifier que les trois unités applicatives repartent seules (workers en ≈ 2 s après leur sortie en code 1 ; Reverb en ≈ 5 s, sortie en code 0 relevée par `Restart=always` : vérifier que l'unité recopiée à l'étape 4 b porte bien `Restart=always`, et non `on-failure`).

### Étape 4.15 — Corrections du contrôle de la phase Partie

- **Joué** le 28/09, 15:10-15:25 UTC. Runbook corrigé ; sur la VM, seul `/home/vagrant/tripleframes-repetition/outils/` reçoit deux scripts sans secret ; aucun service, aucune base, aucun fichier de l'abonnement modifiés. Aucun gabarit `ops/` ni code touché (`ops/systemd/tripleframes-reverb.service` porte déjà `Restart=always`, vérifié).
- **But** : lever les cinq constats du contrôle de la phase 4, chacun vérifié avant d'agir.
- **Commandes de vérification** — [root] :

```bash
sudo -u tripleframes -H bash -c 'tmux ls 2>&1; echo "tmux=$?"'
stat -c '%a %U %n' /var/www/vhosts/tripleframes-prod.test/tripleframes/bootstrap/cache/*.php
journalctl -u tripleframes-reverb --since "2026-09-28 14:52:50" --until "2026-09-28 14:53:10" --no-pager -o short-precise
journalctl -u tripleframes-worker@game -u tripleframes-worker@default --since "2026-09-28 14:52:50" --until "2026-09-28 14:53:05" \
    --no-pager -o short-precise | grep -E "exited|Scheduled|Started|Deactivated"
journalctl -u tripleframes-redis --since "2026-09-28 14:52:50" --until "2026-09-28 14:53:05" --no-pager -o short-precise \
    | grep -E "Stopp|Started|Deactivated"
journalctl _PID=261302 --no-pager -o short-precise | tail -5          # derniers messages de l'ancien Reverb
systemctl show -p Restart,RestartUSec tripleframes-reverb
```

  [poste], Git Bash : `ls -la <scratchpad>/vm/partie/ | grep profile`.
- **Constats vérifiés et corrections** :
    1. **`tmux kill-session` après `exit`** (étape 4.13) — vérifié : `tmux ls` sous `tripleframes` → « no server running on /tmp/tmux-1001/default » (la variante détachée du pilote a été fermée autrement ; la ligne du runbook, elle, n'aurait jamais tourné sous le bon utilisateur). Corrigé : bloc « Déployer » tapé dans la seconde session [abo] **hors de `tmux`**, `cat ~/drain.log`, `tmux kill-session -t tf-drain` et `tmux ls` **avant** `exit` ; garde `tmux has-session -t tf-drain` avant le `tmux new-session` ; point (7) ajouté à la ligne « Sur le VPS Plesk » de l'étape 4.13.
    2. **Reverb « en code 1 »** (étape 4.14, écart n° 27) — vérifié : Redis arrêté à 14:52:56,74, relancé à 14:52:56,98 ; workers `status=1/FAILURE` (14:52:56,85 et 14:52:57,33), relevés à 14:52:58,89 et 14:52:59,35 ; Reverb (PID 261302) **sans aucun message** après « Starting server » de 14:47:14, puis `Deactivated successfully` (code 0) à 14:52:59,60, `Scheduled restart job` à 14:53:01,64, « Starting server » à 14:53:02,01 (PID 261897) ; `Restart=always`, `RestartUSec=2s`. Corrigé : attendu de 4.14 (avec le `journalctl` de contrôle), ligne « Sur le VPS Plesk » et écart n° 27 (`Restart=always` nécessaire pour Reverb).
    3. **Profils Chrome de la phase 4 non effacés** (« Retour arrière ») — vérifié : `profile-hote`, `profile-invite`, `profile-tiers` présents (16:56, heure du poste). Corrigé : ajoutés à la commande `rm -rf` du poste et à la phrase qui la précède.
    4. **`packages.php` et `services.php` en `750`** (étape 4.13) — vérifié : quatre caches en `640`, ces deux-là en `750` (`tripleframes`). Corrigé : attendu de 4.13 complété (réécrits par `package:discover`, `0777 − umask`, sans secret, déjà relevé à l'étape 2.18) et contrôle `stat` ajouté au point (6) de la ligne « Sur le VPS Plesk ».
    5. **Contrôle « 0 secret » fait hors de la VM** (exécution de la phase 4) — vérifié par le rapport de l'exécutant : les valeurs avaient transité par un fichier temporaire du poste (`C:\Users\Admin\AppData\Local\Temp\secrets.<pid>`, supprimé aussitôt, absent aujourd'hui), contraire à la règle « secrets stockés uniquement sur la VM ». Consigné (RV-15 du journal, impasse ci-dessous) ; contrôle refait **sur la VM**, par le script ci-dessous : seuls deux nombres reviennent sur le poste.
- **Contrôle des secrets, forme retenue pour toute la suite** — script sans secret, posé dans le sous-dossier dédié (`<scratchpad>/vm/outils/compte-secrets.sh`, sha256 `90c556f8…`) :

```bash
#!/bin/bash
# Compte les lignes de l'entrée standard qui contiennent une valeur secrète de la répétition.
# À lancer en root SUR LA VM : ssh … 'sudo bash /home/vagrant/tripleframes-repetition/outils/compte-secrets.sh' < fichier
# Les valeurs ne quittent jamais la VM : liste en /dev/shm (0600), supprimée à la sortie ;
# seuls deux nombres reviennent (valeurs comparées, lignes fautives).
set -euo pipefail
umask 077
H=/var/www/vhosts/tripleframes-prod.test
E="$H/tripleframes/.env"
R=/etc/tripleframes/tripleframes-redis.conf
B="$H/.config/tripleframes/backup.env"
K=/root/tripleframes-hors-machine/backup-age-key.txt
L="$(mktemp /dev/shm/tf-secrets.XXXXXX)"
trap 'rm -f -- "$L"' EXIT

val() { sed -n "s/^$1=//p" "$2" | tail -1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"; }

# REVERB_APP_KEY n'y figure pas : clé PUBLIQUE du protocole Pusher, présente par construction
# dans l'URL wss (/app/<clé>) de chaque navigateur ; le secret est REVERB_APP_SECRET.
for k in APP_KEY DB_PASSWORD REDIS_PASSWORD REVERB_APP_SECRET MAIL_PASSWORD \
         AWS_SECRET_ACCESS_KEY TMDB_API_KEY TMDB_API_READ_ACCESS_TOKEN OPS_PROBE_TOKEN; do
    v="$(val "$k" "$E")"; v="${v#base64:}"
    [ "${#v}" -ge 8 ] && printf '%s\n' "$v" >> "$L"
done
[ -r "$B" ] && { v="$(val BACKUP_HEARTBEAT_URL "$B")"; [ "${#v}" -ge 8 ] && printf '%s\n' "$v" >> "$L"; }
awk '$1 == "requirepass" { gsub(/"/, "", $2); if (length($2) >= 8) print $2 }' "$R" >> "$L"
[ -r "$K" ] && grep -o '^AGE-SECRET-KEY-[A-Z0-9]*' "$K" >> "$L" || true

n="$(wc -l < "$L")"
c="$(grep -cF -f "$L" || true)"
echo "valeurs comparées=$n lignes fautives=$c"
```

  [poste], Git Bash (`<clé>` : voir « Conventions ») :

```bash
scp -i <clé> <scratchpad>/vm/outils/compte-secrets.sh vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/outils/compte-secrets.sh
ssh -i <clé> vagrant@192.168.10.10 'chmod 0755 /home/vagrant/tripleframes-repetition/outils/compte-secrets.sh'
# témoin positif, fabriqué et consommé sur la VM seulement (2 lignes secrètes sur 3) :
ssh -i <clé> vagrant@192.168.10.10 'sudo bash -c "sed -n \"s/^REDIS_PASSWORD=/x /p\" /var/www/vhosts/tripleframes-prod.test/tripleframes/.env; grep -o \"^AGE-SECRET-KEY-[A-Z0-9]*\" /root/tripleframes-hors-machine/backup-age-key.txt; echo sans secret" | sudo bash /home/vagrant/tripleframes-repetition/outils/compte-secrets.sh; ls /dev/shm | grep -c tf- || true'
cd C:/Users/Admin/Desktop/groupez/tripleframes
for f in docs/ops/repetition-vm.md <scratchpad>/vm/bugs.md <scratchpad>/journal-ecarts-impl.md <scratchpad>/vm/partie/logs/*; do
    printf '%s : ' "${f##*/}"
    ssh -i <clé> vagrant@192.168.10.10 'sudo bash /home/vagrant/tripleframes-repetition/outils/compte-secrets.sh' < "$f"
done
```

- **Attendu et vérification** : témoin positif → `valeurs comparées=8 lignes fautives=2`, puis `0` fichier `tf-*` restant dans `/dev/shm` ; pour le runbook, `bugs.md`, `journal-ecarts-impl.md`, les deux scripts et les 18 fichiers de `vm/partie/logs/` (dont `partie-1.jsonl`, `partie-2.jsonl`, `drainage-run.log`) → `lignes fautives=0`. Relevé final à 15:23 UTC, après toutes les corrections ci-dessus : 23 fichiers, 0 fautif, 0 fichier `tf-*` dans `/dev/shm`.
- **Écart (impasse levée)** : une première passe incluait `REVERB_APP_KEY` et trouvait 97 lignes dans `partie-1.jsonl` et 176 dans `partie-2.jsonl`. Diagnostic sans sortir la valeur, par une variante qui compte **par nom de clé** (`<scratchpad>/vm/outils/compte-secrets-par-cle.sh`, même invocation) : `REVERB_APP_KEY=97`, `=176`, toutes les autres clés à 0 ; ce sont les URL `wss://tripleframes-prod.test/app/<clé>` des enregistrements CDP. Cette clé est publique par construction (protocole Pusher, envoyée par chaque navigateur) : retirée de la liste, commentaire dans le script. Le secret de Reverb (`REVERB_APP_SECRET`) reste comparé.
- **Sur le VPS Plesk :** même principe pour tout document qui quitte le serveur (runbook du VPS, journaux, relevés, captures en texte) : la comparaison se fait **sur le VPS**, jamais sur le poste — script posé en root hors de l'abonnement (par exemple `/root/tripleframes-outils/compte-secrets.sh`, `0700`), `H=/var/www/vhosts/<DOMAINE>`, `R=/etc/tripleframes/tripleframes-redis.conf` inchangé, `K` = emplacement réel de la clé privée `age` s'il est sur le serveur (jamais : elle vit hors machine ; laisser la ligne, elle ne fait rien si le fichier est absent), et `ssh root@<VPS> 'bash /root/tripleframes-outils/compte-secrets.sh' < fichier`. Ajouter à la liste les secrets que la VM n'a pas, s'ils vivent ailleurs que dans le `.env` ou `backup.env` : mot de passe de la configuration `rclone` (clé d'écriture du stockage objet), jetons des adresses d'alerte de la supervision.

## Phase 5 — Charge

Jouée le 28/09/2026 à partir de 15:26 UTC, contre `https://tripleframes-prod.test` (déploiement n° 5, `9e5ed5a`). **Répétition de l'outillage et de la procédure** (`100` § 16, étapes 130 et 131 du REPRISE) : la VM (2 vCPU, 3,9 Go, charge émise depuis le poste, sur le même hôte physique) ne décide rien de D33, qui se joue sur le VPS. Les mesures ci-dessous sont **indicatives** et ne s'inscrivent jamais dans `100` § 16.6.

**Protection de la machine du porteur** (la VM héberge sa base de développement et ses sites `*.test`) : chaque exécution de k6 est accompagnée d'un **échantillonneur** sur la VM (une ligne toutes les 5 s : charge, mémoire, swap, file PHP-FPM, processeur et mémoire de chaque unité, Redis, MySQL, temps de réponse des voisins) et d'une **veille** sur le poste, qui arrête k6 par son API à la première saturation : charge sur 1 minute au-dessus de 2 × nproc (4) pendant plus de 60 s, échange mémoire soutenu, ou mémoire disponible sous 10 %. Une saturation est un **résultat**, consigné comme tel, jamais un incident à contourner.

**Où tourne quoi** : k6 sur le poste (Git Bash), dans `<scratchpad>/vm/k6/` (copie du scénario, enveloppe, `.data/`) ; rien dans le dépôt (`tests/Load/.data/` reste vide). Sur la VM, tout ce qui est propre à la séance vit sous `/home/vagrant/tripleframes-repetition/charge/`.

### Étape 5.1 — Préalables : état de la production, binaire k6, liste des titres

- **But** : `100` § 16.2 (préalables) et en-tête de `tests/Load/game-load.js` : sondes vertes, aucune partie ni drainage en cours, catalogue suffisant ; k6 officiel ; liste des titres publiés extraite **une fois**, en lecture, et jamais exposée par une route.
- **Commandes** — [root] : bloc de sondes de l'étape 3.1, tel quel. [poste], Git Bash (`<clé>` : voir « Conventions ») :

```bash
# Binaire k6 officiel (déjà téléchargé depuis les releases GitHub : même empreinte que la liste publiée).
curl -sSL https://github.com/grafana/k6/releases/download/v2.3.0/k6-v2.3.0-checksums.txt | grep windows-amd64.zip
sha256sum <scratchpad>/k6/k6.zip
<scratchpad>/k6/k6-v2.3.0-windows-amd64/k6.exe version
# Espace de travail : copie du scénario du dépôt, identique octet pour octet.
mkdir -p <scratchpad>/vm/k6/.data
cp tests/Load/game-load.js <scratchpad>/vm/k6/game-load.js
sha256sum tests/Load/game-load.js <scratchpad>/vm/k6/game-load.js
```

  Liste des titres — [abo], par l'entrée standard (`<scratchpad>/vm/charge/c1-titres.sh`), sortie redirigée sur le poste :

```bash
# [abo] Étape 5.1 — liste des titres publiés (lecture seule, utilisateur de la base dédié).
E=/var/www/vhosts/tripleframes-prod.test/tripleframes/.env
MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' "$E")" mysql --no-defaults --protocol=socket -u tripleframes_prod tripleframes_prod -N -B -e \
  "SELECT DISTINCT mt.title FROM movie_title mt JOIN movie m ON m.id = mt.movie_id WHERE m.availability = 'published' ORDER BY mt.title;"
```

```bash
ssh -i <clé> vagrant@192.168.10.10 'sudo -u tripleframes -H bash -s' < <scratchpad>/vm/charge/c1-titres.sh > <scratchpad>/vm/k6/.data/titles.txt
wc -l <scratchpad>/vm/k6/.data/titles.txt
```

  Enveloppe `<scratchpad>/vm/k6/enveloppe.js` (**propre à la répétition**) : k6 n'a ni option ni variable pour la résolution de noms, et Windows n'approuve pas la CA de Homestead. Elle réexporte le scénario tel quel :

```javascript
import * as scenario from './game-load.js';

const VM = '192.168.10.10';

export const options = Object.assign({}, scenario.options, {
    hosts: {
        'tripleframes-prod.test': VM,
        'tripleframes-voisin.test': VM,
        'atomsdle.test': VM,
    },
    insecureSkipTLSVerify: true,
});

export const setup = scenario.setup;
export const players = scenario.players;
export const neighbours = scenario.neighbours;
export const handleSummary = scenario.handleSummary;
```

- **Attendu et vérification** (15:29-15:32 UTC) : cinq unités actives, `/up` 200, cinq sondes `ok`, 404 sans jeton et jeton faux ; battements `game` 4,8 s et `default` 3,0 s ; Redis dédié 1,15 Mo sur 256 Mo ; **8 parties, toutes `completed`**, aucune en cours ; aucun drainage (`deploy:guard` 2 depuis l'étape 4.13) ; empreinte du zip `112276d4…` = celle de la liste publiée pour `k6-v2.3.0-windows-amd64.zip`, `k6.exe v2.3.0 (commit/e088784614, go1.26.8, windows/amd64)` ; copie du scénario identique (`0ec1da90…`) ; **27 titres** (17 œuvres publiées, titres français et anglais). Machine au repos : charge 0,12, 2 171 Mo disponibles, swap 27 Mo.
- **Écart** : le préalable « porteur prévenu, séance hors de son usage de la VM » n'a pas pu être vérifié de vive voix ; relevé à 15:27 : une connexion MySQL du poste sur la base de développement (`homestead@192.168.10.1`, `tripleframes`, active 2 s plus tôt), aucun autre usage visible. La séance a donc été jouée **sous garde-fou automatique** (étape 5.3), qui s'arrête avant toute gêne durable.
- **Sur le VPS Plesk :** k6 installé sur le poste du porteur (même binaire officiel, empreinte vérifiée), `k6 run` lancé **depuis la racine du dépôt** (`tests/Load/.data/`, ignoré par git) ; **aucune enveloppe** : DNS public et Let's Encrypt. **D'abord**, sur le poste, à la racine du dépôt : `mkdir -p tests/Load/.data` (le répertoire est ignoré par git et n'existe pas dans un clone : `tests/Load/` ne contient que `game-load.js` ; sans lui, la redirection des titres, `--console-output` et `handleSummary` échouent). La requête des titres se joue ensuite en SSH de l'abonnement (`mysql` avec l'utilisateur de la base Plesk, mot de passe tapé ou lu dans le `.env`), sortie redirigée dans `tests/Load/.data/titles.txt` du poste. Prévenir les autres occupants du VPS et choisir une heure creuse **à laquelle la référence des voisins sera prise** (§ 16.2).

### Étape 5.2 — Voisins mesurés : un site existant et un voisin témoin (propre à la répétition)

- **But** : critère 2 de `100` § 16.4 (« les voisins ne se dégradent pas »), mesuré **sans toucher** aux sites de développement du porteur. Deux voisins :
  - **`https://atomsdle.test/robots.txt`**, site existant de la VM (serveur par défaut de nginx) : fichier statique servi par nginx, **aucune écriture**, ni base, ni session, ni PHP. Les pages PHP des sites existants sont écartées : une page Laravel ouvre une session en base, donc écrirait dans une base du porteur ;
  - **`http://tripleframes-voisin.test/`**, voisin témoin créé pour la séance : une page PHP d'environ 10 ms de processeur, sans base, servie par un **second pool du même master PHP-FPM** que TripleFrames (utilisateur `tfvoisin`), comme deux abonnements Plesk sur la même version de PHP.
- **Commandes** — [root] (`<scratchpad>/vm/charge/c2-voisin.sh`, envoyé par `ssh … 'sudo bash -s' <`) :

```bash
set -euo pipefail
# [root] Étape 5.2 — voisin témoin (propre à la répétition) : un « abonnement voisin »
# servi par le même master PHP-FPM que TripleFrames, comme deux abonnements Plesk
# sur la même version de PHP. Page dynamique d'environ 10 ms de processeur, sans base.
echo "== avant : masters PHP-FPM existants et nginx"
for u in php8.1-fpm php8.3-fpm php8.4-fpm nginx tripleframes-php-fpm; do printf '%-22s MainPID=%s\n' $u "$(systemctl show -p MainPID --value $u)"; done
useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin --user-group tfvoisin
install -d -o root -g root -m 0755 /var/www/vhosts/tripleframes-voisin.test /var/www/vhosts/tripleframes-voisin.test/httpdocs
install -d -o root -g root -m 0755 /var/www/vhosts/system/tripleframes-voisin.test
cat > /var/www/vhosts/tripleframes-voisin.test/httpdocs/index.php <<'PHP'
<?php
// Voisin témoin de la séance de charge (répétition VM) : ~10 ms de processeur, aucune base.
$h = '';
for ($i = 0; $i < 30000; $i++) {
    $h = hash('sha256', $h.$i);
}
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'voisin ok ', substr($h, 0, 8), "\n";
PHP
chmod 0644 /var/www/vhosts/tripleframes-voisin.test/httpdocs/index.php

cat > /etc/tripleframes/php-fpm/pool.d/tripleframes-voisin.conf <<'EOF'
; Voisin témoin de la séance de charge (répétition VM seulement).
[tripleframes-voisin]
user = tfvoisin
group = tfvoisin
listen = /var/www/vhosts/system/tripleframes-voisin.test/php-fpm.sock
listen.owner = root
listen.group = vagrant
listen.mode = 0660
pm = ondemand
pm.max_children = 4
pm.max_requests = 500
pm.process_idle_timeout = 10s
chdir = /
php_admin_value[memory_limit] = 64M
php_admin_value[date.timezone] = UTC
php_admin_value[open_basedir] = /var/www/vhosts/tripleframes-voisin.test/:/tmp/
EOF
chmod 0644 /etc/tripleframes/php-fpm/pool.d/tripleframes-voisin.conf

cat > /etc/tripleframes/nginx/tripleframes-voisin.test.conf <<'EOF'
# Voisin témoin de la séance de charge (répétition VM seulement), HTTP seul.
server {
    listen 80;
    server_name tripleframes-voisin.test;
    root /var/www/vhosts/tripleframes-voisin.test/httpdocs;
    access_log off;
    error_log /var/www/vhosts/system/tripleframes-voisin.test/error_log;
    location = / {
        fastcgi_pass unix:/var/www/vhosts/system/tripleframes-voisin.test/php-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
    }
    location / {
        return 404;
    }
}
EOF
chmod 0644 /etc/tripleframes/nginx/tripleframes-voisin.test.conf

php-fpm8.4 -t -y /etc/tripleframes/php-fpm/php-fpm.conf
systemctl reload tripleframes-php-fpm
sleep 2
systemctl is-active tripleframes-php-fpm
ls -l /var/www/vhosts/system/tripleframes-voisin.test/php-fpm.sock
ln -s /etc/tripleframes/nginx/tripleframes-voisin.test.conf /etc/nginx/sites-enabled/zz-tripleframes-voisin.test
nginx -t && systemctl reload nginx
sleep 1
echo "== vérifications"
curl -s -w ' %{http_code} ttfb=%{time_starttransfer}\n' --resolve tripleframes-voisin.test:80:127.0.0.1 http://tripleframes-voisin.test/
curl -s -o /dev/null -w 'atomsdle robots %{http_code} ttfb=%{time_starttransfer}\n' -k --resolve atomsdle.test:443:127.0.0.1 https://atomsdle.test/robots.txt
curl -s -o /dev/null -w 'tripleframes /up %{http_code}\n' --cacert /etc/ssl/certs/ca.homestead.homestead.crt --resolve tripleframes-prod.test:443:127.0.0.1 https://tripleframes-prod.test/up
curl -s -o /dev/null -m 10 -w 'défaut http %{http_code}\n' http://127.0.0.1/
echo | openssl s_client -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -subject
echo "== après"
for u in php8.1-fpm php8.3-fpm php8.4-fpm nginx tripleframes-php-fpm; do printf '%-22s MainPID=%s\n' $u "$(systemctl show -p MainPID --value $u)"; done
```

  Puis, [poste], essai d'une minute de la phase `neighbours` (depuis `<scratchpad>/vm/k6/`) :

```bash
K6=<scratchpad>/k6/k6-v2.3.0-windows-amd64/k6.exe
cd <scratchpad>/vm/k6
"$K6" run --no-usage-report --quiet --address 127.0.0.1:6566 -e PHASE=neighbours \
    -e NEIGHBOUR_URLS=http://tripleframes-voisin.test/,https://atomsdle.test/robots.txt \
    -e NEIGHBOUR_MINUTES=1 -e NEIGHBOUR_INTERVAL_SECONDS=5 -e LOAD_DATA_DIR=.data enveloppe.js
```

- **Attendu et vérification** (15:33-15:36 UTC) : configuration PHP-FPM valide, `tripleframes-php-fpm` rechargé (`USR2`, même PID 237663), socket `srw-rw---- root vagrant` ; `nginx -t` réussi, nginx rechargé (même PID 973) ; `voisin ok … 200` en 12 ms ; `atomsdle robots 200` en 5 ms ; `/up` de TripleFrames 200 ; serveur par défaut inchangé (`défaut http 200`, `CN = atomsdle.test`) ; masters `php8.1-fpm`, `php8.3-fpm`, `php8.4-fpm` inchangés (735, 736, 737). Essai k6 : `tf_neighbour_failures count=0`, voisin témoin médiane 10,9 ms, `atomsdle` 0,5 ms (vus du poste, connexion réutilisée).
- **Sur le VPS Plesk :** **aucun voisin témoin** : on mesure les **vrais** sites voisins du VPS, leur page d'accueil (`NEIGHBOUR_URLS=https://<voisin 1>/,https://<voisin 2>/`, § 16.4), à faible cadence (10 s par défaut). Une page d'accueil de site vitrine ou WordPress en lecture ne crée rien de durable ; à vérifier avec leurs propriétaires si un voisin écrit à chaque visite (compteur, session en base).

### Étape 5.3 — Échantillonneur et garde-fou (propre à la répétition)

- **But** : relever pendant chaque exécution ce que k6 ne voit pas (`100` § 16.4 : file PHP-FPM, cgroups, Redis, mémoire et swap de la machine) et **protéger la VM** : arrêt de k6 à la première saturation.
- **Commandes** — [poste] : `<scratchpad>/vm/charge/echantillonneur.sh` (sans secret : mot de passe Redis par `REDISCLI_AUTH`, MySQL par `MYSQL_PWD`, lus sur la VM) copié sur la VM, et `<scratchpad>/vm/charge/veille.sh`, qui le lance par `ssh`, recopie ses lignes et arrête k6 par son API REST à la première ligne `STOP` :

```bash
scp -i <clé> <scratchpad>/vm/charge/echantillonneur.sh vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/charge/echantillonneur.sh
ssh -i <clé> vagrant@192.168.10.10 'bash -n /home/vagrant/tripleframes-repetition/charge/echantillonneur.sh && sudo bash /home/vagrant/tripleframes-repetition/charge/echantillonneur.sh essai 11'
```

  Relevé par ligne (CSV) : `load1`, `load5`, processeur occupé (`/proc/stat`), mémoire totale et disponible, swap utilisé et pages échangées depuis la ligne précédente (`/proc/vmstat`), statut du pool `tripleframes` par `cgi-fcgi` (`listen queue`, `max listen queue`, processus actifs et totaux, `max children reached`), processeur (en % d'un cœur, `cpu.stat`) et mémoire (`memory.current`) des cgroups `tripleframes-php-fpm`, `tripleframes-redis`, `tripleframes-worker@game`, `tripleframes-worker@default`, `tripleframes-reverb`, `mysql`, `nginx`, `php8.4-fpm` (pool des sites du porteur), mémoire et clients du Redis dédié, `Threads_connected` et `Threads_running` de MySQL (utilisateur dédié), temps jusqu'au premier octet, **vu de la VM**, du voisin témoin, d'`atomsdle.test/robots.txt` et du `/up` de TripleFrames. Règle d'arrêt (forme finale, après les deux faux positifs de l'écart ci-dessous), une seule ligne `STOP` : `load1` > 2 × nproc plus de 60 s d'affilée ; **au moins 256 pages (1 Mio) échangées** (entrée ou sortie, `pswpin` + `pswpout`) à chacun de 3 relevés de suite, ou swap + 100 Mo **alors que** la mémoire disponible est sous 20 % ; mémoire disponible < 10 %. Les colonnes `swap_in_pages` et `swap_out_pages` sont séparées.

  `echantillonneur.sh` (VM, root), recopié à l'identique (sha256 `8624e407…`, même empreinte sur le poste et sur la VM, relevée au contrôle de la phase) :

```bash
#!/bin/bash
# Échantillonneur et garde-fou de la séance de charge (répétition VM, 100 § 16.4).
# [root] Usage : bash echantillonneur.sh <étiquette> <durée en secondes>
#
# Une ligne CSV toutes les 5 s (sortie standard ET fichier sous
# /home/vagrant/tripleframes-repetition/charge/), puis, UNE fois, une ligne
# « STOP <motif> » si la machine sature :
#   - charge (load1) > 2 × nproc pendant plus de 60 s d'affilée ;
#   - échange mémoire soutenu : au moins 256 pages (1 Mio) échangées, en entrée
#     ou en sortie, à chacun de 3 relevés de suite (15 s) — quelques pages
#     froides relues ne sont pas une saturation —, ou plus de 100 Mo de swap consommés depuis le début
#     ALORS QUE la mémoire disponible est sous 20 % (une vidange ponctuelle de
#     pages froides au repos n'est pas une saturation) ;
#   - mémoire disponible sous 10 % (seuil de la sonde `load`).
# L'arrêt de k6 est fait par le poste, qui lit ces lignes (veille.sh).
# Aucun secret n'est écrit : mot de passe Redis par REDISCLI_AUTH, mot de passe
# MySQL par MYSQL_PWD, lus dans leurs fichiers, jamais affichés.
set -u
LABEL="${1:?étiquette}"
DUR="${2:?durée en secondes}"
STEP=5
NPROC="$(nproc)"
DIR=/home/vagrant/tripleframes-repetition/charge
OUT="$DIR/echantillons-$LABEL.csv"
APP=/var/www/vhosts/tripleframes-prod.test/tripleframes
SOCK=/var/www/vhosts/system/tripleframes-prod.test/php-fpm.sock
CA=/etc/ssl/certs/ca.homestead.homestead.crt
install -d -o vagrant -g vagrant -m 0755 "$DIR"

export REDISCLI_AUTH="$(awk '/^requirepass /{print $2}' /etc/tripleframes/tripleframes-redis.conf)"
export MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' "$APP/.env")"

UNITS=(tripleframes-php-fpm tripleframes-redis tripleframes-worker@game tripleframes-worker@default tripleframes-reverb mysql nginx php8.4-fpm)
SHORT=(fpm redis wgame wdefault reverb mysql nginx fpm84)
declare -a CG PREV_CPU
for i in "${!UNITS[@]}"; do
    CG[$i]="/sys/fs/cgroup$(systemctl show -p ControlGroup --value "${UNITS[$i]}")"
done

cpu_usec() { awk '$1=="usage_usec"{print $2}' "${CG[$1]}/cpu.stat" 2>/dev/null || echo 0; }
mem_mb() { local v; v="$(cat "${CG[$1]}/memory.current" 2>/dev/null || echo 0)"; echo $(( v / 1048576 )); }
proc_stat() { awk 'NR==1{b=$2+$3+$4+$7+$8; t=b+$5+$6; print b, t}' /proc/stat; }
vm_swp() { awk '$1=="pswpin"{i=$2} $1=="pswpout"{o=$2} END{print i, o}' /proc/vmstat; }
meminfo() { awk '$1=="MemTotal:"{t=$2} $1=="MemAvailable:"{a=$2} $1=="SwapTotal:"{st=$2} $1=="SwapFree:"{sf=$2} END{printf "%d %d %d\n", t/1024, a/1024, (st-sf)/1024}' /proc/meminfo; }
ttfb() { curl -s -o /dev/null -m 4 -w '%{http_code}:%{time_starttransfer}' "$@" 2>/dev/null || echo "000:NA"; }
fpm_status() {
    timeout 4 env SCRIPT_NAME=/fpm-status SCRIPT_FILENAME=/fpm-status REQUEST_METHOD=GET cgi-fcgi -bind -connect "$SOCK" 2>/dev/null \
        | awk -F': *' '$1=="listen queue"{q=$2} $1=="max listen queue"{mq=$2} $1=="active processes"{a=$2} $1=="total processes"{t=$2} $1=="max children reached"{mc=$2} END{if (t=="") print "NA,NA,NA,NA,NA"; else printf "%s,%s,%s,%s,%s\n", q, mq, a, t, mc}'
}
redis_stat() {
    redis-cli -p 6390 info memory 2>/dev/null | awk -F: '$1=="used_memory"{u=$2} END{printf "%d", u/1048576}'
    printf ','
    redis-cli -p 6390 info clients 2>/dev/null | awk -F: '$1=="connected_clients"{gsub(/\r/,"",$2); printf "%s", $2}'
}
mysql_stat() {
    timeout 4 mysql --no-defaults --protocol=socket -u tripleframes_prod -N -B \
        -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running')" 2>/dev/null \
        | awk '{v[$1]=$2} END{printf "%s,%s", (v["Threads_connected"]==""?"NA":v["Threads_connected"]), (v["Threads_running"]==""?"NA":v["Threads_running"])}'
}

header="ts_utc,load1,load5,cpu_busy_pct,mem_total_mb,mem_avail_mb,swap_used_mb,swap_in_pages,swap_out_pages"
header+=",fpm_listen_queue,fpm_max_listen_queue,fpm_active,fpm_total,fpm_max_children_reached"
for s in "${SHORT[@]}"; do header+=",cpu_${s}_pct,mem_${s}_mb"; done
header+=",redis_used_mb,redis_clients,mysql_threads_connected,mysql_threads_running"
header+=",ttfb_voisin_php,ttfb_atomsdle_static,ttfb_tripleframes_up,fpm_sock_backlog"
echo "$header" | tee "$OUT"

read -r pb pt < <(proc_stat)
read -r pin pout < <(vm_swp)
read -r _ _ swap0 < <(meminfo)
for i in "${!UNITS[@]}"; do PREV_CPU[$i]="$(cpu_usec "$i")"; done
prev_t="$(date +%s%N)"

hot=0; swp=0; stopped=0
end=$(( $(date +%s) + DUR ))
while [ "$(date +%s)" -lt "$end" ]; do
    sleep "$STEP"
    now="$(date +%s%N)"; dt_us=$(( (now - prev_t) / 1000 )); prev_t="$now"
    read -r l1 l5 _ < /proc/loadavg
    read -r b t < <(proc_stat)
    busy=$(awk -v db=$((b - pb)) -v dt=$((t - pt)) 'BEGIN{ if (dt>0) printf "%.0f", 100*db/dt; else print "NA"}'); pb=$b; pt=$t
    read -r mt ma su < <(meminfo)
    read -r sin sout < <(vm_swp); din=$(( sin - pin )); dout=$(( sout - pout )); pin=$sin; pout=$sout; dswp=$(( din + dout ))
    line="$(date -u +%FT%TZ),$l1,$l5,$busy,$mt,$ma,$su,$din,$dout,$(fpm_status)"
    for i in "${!UNITS[@]}"; do
        c="$(cpu_usec "$i")"
        line+=",$(awk -v d=$((c - PREV_CPU[$i])) -v dt=$dt_us 'BEGIN{printf "%.0f", 100*d/dt}'),$(mem_mb "$i")"
        PREV_CPU[$i]="$c"
    done
    line+=",$(redis_stat),$(mysql_stat)"
    line+=",$(ttfb --resolve tripleframes-voisin.test:80:127.0.0.1 http://tripleframes-voisin.test/)"
    line+=",$(ttfb -k --resolve atomsdle.test:443:127.0.0.1 https://atomsdle.test/robots.txt)"
    line+=",$(ttfb --cacert "$CA" --resolve tripleframes-prod.test:443:127.0.0.1 https://tripleframes-prod.test/up)"
    # File d'attente réelle du pool : sur un socket unix, la page de statut de PHP-FPM rend toujours
    # « listen queue: 0 » (mesure réservée aux sockets TCP) ; Recv-Q de ss = connexions en attente d'accept().
    line+=",$(ss -xlnH | awk -v s="$SOCK" '$5==s{print $3; f=1} END{if(!f) print "NA"}')"
    echo "$line" | tee -a "$OUT"

    # Garde-fou : une seule ligne STOP, l'échantillonnage continue (retour au calme).
    if awk -v l="$l1" -v n="$NPROC" 'BEGIN{exit !(l > 2*n)}'; then hot=$((hot + 1)); else hot=0; fi
    if [ "$dswp" -ge 256 ]; then swp=$((swp + 1)); else swp=0; fi
    if [ "$stopped" -eq 0 ]; then
        motif=""
        [ $(( hot * STEP )) -gt 60 ] && motif="charge load1=$l1 > $((2 * NPROC)) depuis plus de 60 s"
        [ "$swp" -ge 3 ] && motif="échange mémoire soutenu ($dswp pages au dernier relevé)"
        [ $(( su - swap0 )) -gt 100 ] && [ $(( ma * 5 )) -lt "$mt" ] && motif="swap +$(( su - swap0 )) Mo depuis le début, mémoire disponible $ma Mo < 20 %"
        [ $(( ma * 10 )) -lt "$mt" ] && motif="mémoire disponible $ma Mo < 10 %"
        if [ -n "$motif" ]; then
            stopped=1
            echo "STOP $(date -u +%FT%TZ) $motif" | tee -a "$OUT"
        fi
    fi
done
unset REDISCLI_AUTH MYSQL_PWD
```

  Veille (arrêt propre de k6 : `PATCH /v1/status` `stopped: true`, puis `handleSummary` écrit le résumé et la liste des salons) :

```bash
bash <scratchpad>/vm/charge/veille.sh <étiquette> <durée en s> 6565     # en arrière-plan, à côté de k6 (API sur 127.0.0.1:6565)
```

  `veille.sh` (poste, Git Bash), recopié à l'identique hormis la ligne `S=`, qui porte sur le poste le chemin réel du scratchpad (sha256 du fichier du poste `c72c29cf…`) ; `vm.sh` ne fait que `exec ssh -i <clé> -o ConnectTimeout=15 -o BatchMode=yes vagrant@192.168.10.10 "$@"` :

```bash
#!/usr/bin/env bash
# [poste] Veille de la séance de charge (répétition VM) : lance l'échantillonneur
# sur la VM, recopie ses lignes en local et, à la première ligne « STOP », arrête
# le k6 des joueurs par son API REST (arrêt propre : le résumé et la liste des
# salons sont écrits par handleSummary).
# Usage : bash veille.sh <étiquette> <durée en s> <port de l'API k6 à arrêter>
S=<scratchpad>
LABEL="${1:?étiquette}"; DUR="${2:?durée}"; PORT="${3:?port API k6}"
OUT="$S/vm/charge/echantillons-$LABEL.csv"
LOG="$S/vm/charge/arrets.log"
: > "$OUT"
bash "$S/vm/vm.sh" "sudo bash /home/vagrant/tripleframes-repetition/charge/echantillonneur.sh $LABEL $DUR" |
while IFS= read -r line; do
    printf '%s\n' "$line" >> "$OUT"
    case "$line" in
        STOP*)
            printf '%s [%s] %s -> arrêt de k6 (API 127.0.0.1:%s)\n' "$(date -u +%FT%TZ)" "$LABEL" "$line" "$PORT" >> "$LOG"
            curl -s -m 10 -X PATCH -H 'Content-Type: application/json' \
                -d '{"data":{"type":"status","id":"default","attributes":{"stopped":true}}}' \
                "http://127.0.0.1:$PORT/v1/status" >> "$LOG" 2>&1
            printf '\n' >> "$LOG"
            ;;
    esac
done
printf '%s [%s] fin de la veille\n' "$(date -u +%FT%TZ)" "$LABEL" >> "$LOG"
```

- **Attendu et vérification** (15:34 UTC) : syntaxe valide ; deux lignes d'essai au repos : charge 0,12, processeur 1 à 3 %, 2 171 Mo disponibles, swap 27 Mo sans échange, pool `tripleframes` 1 processus sans file, MySQL 1 258 Mo (instance partagée), `php8.4-fpm` 257 Mo, Redis dédié 1 Mo et 8 clients, voisin témoin 11 à 15 ms, `atomsdle` 5 ms, `/up` 19 à 22 ms.
- **Écart — deux faux positifs du garde-fou, règle corrigée** :
  1. **Au repos** (mesure de référence, étape 5.4, 15:38:27 UTC) : le noyau a vidé d'un coup 117 Mo de pages froides vers le swap (surtout de MySQL, 1 259 → 1 049 Mo résidents) alors que la mémoire disponible **montait** de 2 155 à 2 343 Mo ; la première règle (« swap + 100 Mo ») a écrit `STOP` sans aucune charge. Ce n'est pas une saturation : récupération de mémoire ordinaire (`swappiness` 60). Règle corrigée : « swap + 100 Mo » ne compte que sous 20 % de mémoire disponible.
  2. **Répétition à deux salons, premier essai** (étape 5.5, 15:44:11 UTC) : 16, 26 puis 3 pages **relues** du swap à trois relevés de suite (moins de 200 Kio en tout, 2,25 Go disponibles), au lancement des deux parties ; la veille a arrêté k6 au bout de 82 s. Règle corrigée : 256 pages (1 Mio) au moins à chacun des 3 relevés. Au passage, l'arrêt par l'API a été **vérifié** : `test run stopped from REST API`, code 103, `handleSummary` a bien écrit la liste des salons (`rooms-A-…txt`) et le résumé.
  - **Leçon pour le VPS** : le swap utilisé ne dit rien seul ; c'est le **débit** d'échange soutenu (`vmstat 5`, colonnes `si`/`so` durablement non nulles, par Mio) sous faible mémoire disponible qui signe la saturation.
  - Le script remplacé (`scp`) pendant qu'un échantillonneur tournait encore a été rechargé proprement : **ne jamais recopier un script `bash` en cours d'exécution** (`bash` le lit au fil de l'eau, et `scp` réécrit le même fichier) ; l'échantillonneur resté orphelin sur la VM après l'arrêt de la veille a été arrêté (`sudo pkill -f "^bash /home/vagrant/tripleframes-repetition/charge/echantillonneur.sh A-1"` : motif ancré, sans quoi `pkill -f` tue aussi le `bash -c` de la session `ssh` qui le tape, code 255).
- **Sur le VPS Plesk :** pas de garde-fou automatique écrit d'avance : le porteur surveille en direct dans une session root (`uptime`, `free -m`, `vmstat 5`, `systemctl status tripleframes-*`) et arrête k6 au clavier (`Ctrl+C` une fois : arrêt propre) sur les mêmes seuils. `veille.sh` reste facultative (propre à la répétition : `bash "$S/vm/vm.sh"` y deviendrait `ssh <root>@<VPS>`). L'échantillonneur, lancé en root dans `tmux` (`bash /root/tripleframes-charge/echantillonneur.sh <étiquette> <durée en s>`), affiche la même ligne `STOP`, qui vaut `Ctrl+C` sur k6. Il se recopie **avec ces substitutions** (sur la copie du VPS, jamais sur le bloc ci-dessus) :
  - `DIR=/root/tripleframes-charge` et `install -d -m 0700 "$DIR"` (au lieu de `/home/vagrant/…` et `-o vagrant -g vagrant -m 0755`) ; les CSV reviennent sur le poste par `scp` pour `resume.py` ;
  - `APP=/var/www/vhosts/<DOMAINE>/tripleframes` ; `SOCK=/var/www/vhosts/system/<DOMAINE>/php-fpm.sock` (à confirmer : `ss -xlnH | grep '<DOMAINE>'`) ; ligne `CA=` supprimée, et `--cacert "$CA"` comme les `--resolve …` retirés des trois lignes `ttfb` (DNS public, Let's Encrypt) ;
  - voisins : `http://tripleframes-voisin.test/` et `https://atomsdle.test/robots.txt` → pages d'accueil des deux vrais voisins (les adresses de `NEIGHBOUR_URLS`), `/up` → `https://<DOMAINE>/up` ; noms de colonnes gardés (`ttfb_voisin_php` = voisin 1, `ttfb_atomsdle_static` = voisin 2) : `resume.py` les lit ;
  - Redis : `6390` → `__TF_REDIS_PORT__` relevé (deux lignes) ; `/etc/tripleframes/tripleframes-redis.conf` inchangé ;
  - MySQL : `-u tripleframes_prod` → `DB_USERNAME` du `.env` (utilisateur de la base créé par Plesk) ; si le socket par défaut du client n'est pas celui du serveur, `--socket=<chemin>` relevé par `ss -xlp | grep mysqld` ;
  - unités (`UNITS`, huit, dans l'ordre des colonnes `SHORT`, que `resume.py` lit) : `tripleframes-php-fpm` → `plesk-php84-fpm`, **qui englobe les pools PHP 8.4 de tous les abonnements, voisins compris** : son processeur et sa mémoire ne sont **pas attribuables à TripleFrames** (la file du socket, `fpm_sock_backlog`, et la page de statut, elles, sont propres au pool) ; `php8.4-fpm` → un service des voisins (autre PHP de Plesk, `plesk-php83-fpm` par exemple, ou `apache2`/`httpd`) ; `mysql` → `mysql` ou `mariadb` selon le relevé. Chaque nom se vérifie avant la séance : `systemctl show -p ControlGroup --value <unité>` **non vide** (une unité absente donnerait le cgroup racine, donc la machine entière) ; cgroup v2 requis (`stat -fc %T /sys/fs/cgroup` → `cgroup2fs`), sinon les colonnes `cpu_*` et `mem_*` restent à 0 ;
  - page de statut : `cgi-fcgi` (paquet `libfcgi-bin`) et `pm.status_path = /fpm-status` posé (`ops/plesk/settings.md` § 3) ; sans elle, les cinq colonnes `fpm_*` valent `NA` et seule `fpm_sock_backlog` (Recv-Q de `ss`) mesure la file.

  **Correction du contrôle de la phase Charge** : `echantillonneur.sh`, `veille.sh`, `resume.py` (étape 5.4) et `analyse.sh` (étape 5.5) sont désormais recopiés dans ce runbook ; leurs seuls exemplaires étaient le scratchpad de la session et `/home/vagrant/tripleframes-repetition/charge/`, que le retour arrière supprime. Cette ligne disait l'échantillonneur recopiable « tel quel ».

### Étape 5.4 — Mesure de référence des voisins

- **But** : `100` § 16.2 et § 16.4 (critère 2) : p95 du temps jusqu'au premier octet de chaque voisin, pris **dans les 10 minutes qui précèdent** la séance, sans charge ; il sert de seuil (`NEIGHBOUR_BASELINE_P95_MS`, seuil = 1,2 × référence) aux exécutions suivantes.
- **Commandes** — [poste], Git Bash, deux commandes en parallèle (5 minutes, un relevé toutes les 5 s) :

```bash
S=<scratchpad>; K6=$S/k6/k6-v2.3.0-windows-amd64/k6.exe
cd $S/vm/k6
bash $S/vm/charge/veille.sh reference-1 300 6599 &
"$K6" run --no-usage-report --quiet --address 127.0.0.1:6566 -e PHASE=neighbours \
    -e NEIGHBOUR_URLS=http://tripleframes-voisin.test/,https://atomsdle.test/robots.txt \
    -e NEIGHBOUR_MINUTES=5 -e NEIGHBOUR_INTERVAL_SECONDS=5 -e LOAD_DATA_DIR=.data enveloppe.js
wait
python $S/vm/charge/resume.py $S/vm/charge/echantillons-reference-1.csv
```

  `resume.py` (poste, Python 3, bibliothèque standard seule), recopié à l'identique (sha256 `66842665…`) : il ne fait que lire un CSV de l'échantillonneur, éventuellement borné à une fenêtre (`python resume.py <csv> [début HH:MM:SS] [fin HH:MM:SS]`) :

```python
"""Résumé d'un fichier d'échantillons de la séance de charge (répétition VM).

Usage : python resume.py echantillons-<étiquette>.csv [début HH:MM:SS] [fin HH:MM:SS]
"""
import csv
import sys


def pct(values, q):
    values = sorted(values)
    if not values:
        return float('nan')
    index = max(0, min(len(values) - 1, int(-(-q * len(values) // 1)) - 1))
    return values[index]


def main():
    path = sys.argv[1]
    start = sys.argv[2] if len(sys.argv) > 2 else None
    end = sys.argv[3] if len(sys.argv) > 3 else None
    rows, stops = [], []
    with open(path, encoding='utf-8') as handle:
        for line in handle:
            if line.startswith('STOP'):
                stops.append(line.strip())
        handle.seek(0)
        reader = csv.DictReader(line for line in handle if not line.startswith('STOP'))
        for row in reader:
            clock = row['ts_utc'][11:19]
            if (start and clock < start) or (end and clock > end):
                continue
            rows.append(row)
    if not rows:
        print('aucune ligne')
        return
    print(f"lignes={len(rows)} de {rows[0]['ts_utc']} à {rows[-1]['ts_utc']}")
    for stop in stops:
        print(stop)

    def num(col):
        out = []
        for row in rows:
            try:
                out.append(float(row[col]))
            except (KeyError, ValueError):
                pass
        return out

    load1 = num('load1')
    hot = sum(1 for v in load1 if v > 4)
    print(f"load1 max={max(load1)} p95={pct(load1, .95)} ; relevés > 4 : {hot}")
    busy = num('cpu_busy_pct')
    print(f"processeur occupé % : moy={sum(busy) / len(busy):.0f} p95={pct(busy, .95):.0f} max={max(busy):.0f}")
    avail = num('mem_avail_mb')
    print(f"mémoire disponible Mo : min={min(avail):.0f} ; swap utilisé Mo : max={max(num('swap_used_mb')):.0f}")
    for col in ('swap_in_pages', 'swap_out_pages', 'swap_pages_delta'):
        if col in rows[0]:
            vals = num(col)
            print(f"{col} : total={sum(vals):.0f} relevés>0={sum(1 for v in vals if v > 0)}")
    for col in ('fpm_listen_queue', 'fpm_max_listen_queue', 'fpm_active', 'fpm_total', 'fpm_max_children_reached', 'fpm_sock_backlog'):
        vals = num(col)
        na = sum(1 for row in rows if row.get(col) == 'NA')
        print(f"{col} : max={max(vals) if vals else 'NA'}  (NA={na})")
    for unit in ('fpm', 'redis', 'wgame', 'wdefault', 'reverb', 'mysql', 'nginx', 'fpm84'):
        cpu = num(f'cpu_{unit}_pct')
        mem = num(f'mem_{unit}_mb')
        print(f"  {unit:9} cpu% moy={sum(cpu) / len(cpu):5.0f} max={max(cpu):4.0f}   mém Mo max={max(mem):5.0f}")
    print(f"redis Mo max={max(num('redis_used_mb')):.0f} clients max={max(num('redis_clients')):.0f} ; "
          f"mysql threads connectés max={max(num('mysql_threads_connected')):.0f} en cours max={max(num('mysql_threads_running')):.0f}")
    for col in ('ttfb_voisin_php', 'ttfb_atomsdle_static', 'ttfb_tripleframes_up'):
        codes, times = {}, []
        for row in rows:
            code, _, value = row[col].partition(':')
            codes[code] = codes.get(code, 0) + 1
            try:
                times.append(float(value) * 1000)
            except ValueError:
                pass
        print(f"{col} (vu de la VM) : codes={codes} p50={pct(times, .5):.1f} ms p95={pct(times, .95):.1f} ms max={max(times) if times else float('nan'):.1f} ms")


main()
```

- **Attendu et vérification** (15:37:16 → 15:42:17 UTC) : `tf_neighbour_failures count=0` ; vus du poste (k6), **p95 de référence : voisin témoin 17,23 ms** (médiane 11,2 ms), **`atomsdle` 1,58 ms** (médiane 1,0 ms) ; vus de la VM (échantillonneur) : voisin 15,5 ms, `atomsdle` 7,9 ms, `/up` de TripleFrames 28,7 ms au p95 ; machine : charge ≤ 0,3, processeur occupé 5 % en moyenne (41 % au plus, une tâche de fond), mémoire disponible ≥ 2 153 Mo, pool `tripleframes` sans file. Seuils des exécutions suivantes : `NEIGHBOUR_BASELINE_P95_MS=17.23,1.58`, soit p95 ≤ 21 ms et ≤ 2 ms.
- **Écart** : le voisin statique a une référence inférieure à 2 ms : à cette échelle, la gigue du réseau VirtualBox pèse autant que la machine, et le seuil de 1,2 × est plus fragile que pour une page dynamique. Le critère 2 se lit donc d'abord sur le voisin témoin (page PHP) et sur les mesures prises **de la VM**. Premier faux positif du garde-fou (étape 5.3).
- **Sur le VPS Plesk :** même commande depuis la racine du dépôt, `NEIGHBOUR_URLS` = pages d'accueil des vrais voisins, `NEIGHBOUR_MINUTES=10` (défaut), à la même heure que la séance ; noter les p95 rendus, qui deviennent `NEIGHBOUR_BASELINE_P95_MS` (dans l'ordre des adresses). `resume.py` se joue tel quel sur les CSV rapatriés du VPS, à un détail près : le seuil `v > 4` de la ligne `load1` (2 × `nproc` de la VM) devient 2 × `nproc` du VPS.

### Étape 5.5 — Répétition à deux salons contre la production (étape 130 du REPRISE)

- **But** : `100` § 16 et en-tête du scénario : jouer `PHASE=A` à deux salons (7 et 8 joueurs simulés, 15 en tout) avec les **limiteurs d'entrée par défaut**, montée d'une minute, palier de 5 minutes, soit **deux vagues** (4 salons : la non-répétition interdit de rejouer un salon) ; vérifier toute la chaîne (création, preset `classic`, entrée, lobby, lancement, poignée de main d'horloge, `wss` et canaux autorisés, images à leur `fetchNotBefore`, saisie, QCM, révélation, podium, vague suivante), les deux critères, la liste des salons et l'arrêt propre.
- **Commandes** — [poste], Git Bash : `<scratchpad>/vm/charge/seance.sh` lance en parallèle la veille (échantillonneur + garde-fou), la phase `neighbours` (seuils = référence de l'étape 5.4) et les joueurs :

```bash
#!/usr/bin/env bash
# [poste] Une exécution de la séance de charge (répétition VM) : veille + voisins + joueurs.
# Usage : bash seance.sh <étiquette> <minutes de veille et de voisins> <p95 réf. voisin 0> <p95 réf. voisin 1> -- <options -e des joueurs…>
S=<scratchpad>
K6="$S/k6/k6-v2.3.0-windows-amd64/k6.exe"
LABEL="$1"; MINUTES="$2"; P0="$3"; P1="$4"; shift 5
cd "$S/vm/k6" || exit 1
echo "début $(date -u +%FT%TZ)"
bash "$S/vm/charge/veille.sh" "$LABEL" $(( MINUTES * 60 )) 6565 &
VEILLE=$!
"$K6" run --no-usage-report --quiet --address 127.0.0.1:6566 -e PHASE=neighbours \
    -e NEIGHBOUR_URLS=http://tripleframes-voisin.test/,https://atomsdle.test/robots.txt \
    -e NEIGHBOUR_BASELINE_P95_MS="$P0,$P1" -e NEIGHBOUR_MINUTES="$MINUTES" -e NEIGHBOUR_INTERVAL_SECONDS=5 \
    -e LOAD_DATA_DIR=.data enveloppe.js > "$S/vm/charge/k6-neighbours-$LABEL.log" 2>&1 &
VOISINS=$!
"$K6" run --no-usage-report --quiet --address 127.0.0.1:6565 --console-output ".data/console-$LABEL.log" \
    -e K6_BASE_URL=https://tripleframes-prod.test -e LOAD_DATA_DIR=.data "$@" enveloppe.js \
    > "$S/vm/charge/k6-players-$LABEL.log" 2>&1
echo "joueurs : code $? à $(date -u +%FT%TZ)"
wait "$VOISINS"; echo "voisins : code $? à $(date -u +%FT%TZ)"
wait "$VEILLE"; echo "veille : fin à $(date -u +%FT%TZ)"
```

```bash
bash <scratchpad>/vm/charge/seance.sh A-2 20 17.23 1.58 -- -e PHASE=A -e ROOMS=2 -e RAMP_MINUTES=1 -e PLATEAU_MINUTES=5
```

  Puis [root], relevé serveur de la fenêtre (`<scratchpad>/vm/charge/analyse.sh`, copié sous `/home/vagrant/tripleframes-repetition/charge/` ; il ne rend que des nombres : parties, manches et bonnes réponses de la fenêtre, parties encore en cours, retards de diffusion du canal `game` par événement, autres messages `game.*`, niveaux du journal `laravel`, codes HTTP et erreurs d'amont du vhost, `NRestarts` et mémoire des unités, journal systemd des unités, OOM du noyau, pic mémoire et refus du Redis dédié) :

```bash
ssh -i <clé> vagrant@192.168.10.10 "sudo bash /home/vagrant/tripleframes-repetition/charge/analyse.sh '2026-09-28 15:45:51' '2026-09-28 15:57:50'"
python <scratchpad>/vm/charge/resume.py <scratchpad>/vm/charge/echantillons-A-2.csv
```

  `analyse.sh` (VM, root), recopié à l'identique (sha256 `39504cdc…`, même empreinte sur le poste et sur la VM) :

```bash
#!/bin/bash
# [root] Relevé serveur d'une exécution de la séance de charge (répétition VM, 100 § 16.4).
# Usage : bash analyse.sh '<début UTC AAAA-MM-JJ HH:MM:SS>' '<fin UTC AAAA-MM-JJ HH:MM:SS>'
# Sortie : des nombres seulement (aucune IP, aucun pseudo, aucun room_code, aucun secret).
set -u
SINCE="${1:?début}"; UNTIL="${2:?fin}"
APP=/var/www/vhosts/tripleframes-prod.test/tripleframes
LOGS=/var/www/vhosts/system/tripleframes-prod.test/logs
DAY="${SINCE%% *}"
S_ISO="${SINCE/ /T}"; U_ISO="${UNTIL/ /T}"
export MYSQL_PWD="$(sed -n 's/^DB_PASSWORD=//p' "$APP/.env")"
q() { mysql --no-defaults --protocol=socket -u tripleframes_prod tripleframes_prod -N -B -e "$1"; }

echo "== fenêtre $SINCE → $UNTIL (UTC)"
echo "== parties lancées dans la fenêtre, par statut"
q "SELECT mode, status, COUNT(*) FROM game WHERE started_at BETWEEN '$SINCE' AND '$UNTIL' GROUP BY mode, status;" 2>/dev/null \
  || q "SELECT status, COUNT(*) FROM game WHERE started_at BETWEEN '$SINCE' AND '$UNTIL' GROUP BY status;"
echo "== parties en cours (toutes) : running/paused"
q "SELECT COUNT(*) FROM game WHERE status IN ('running','paused');"
echo "== manches des parties de la fenêtre, par statut"
q "SELECT r.status, COUNT(*) FROM round r JOIN game g ON g.id = r.game_id WHERE g.started_at BETWEEN '$SINCE' AND '$UNTIL' GROUP BY r.status;"
echo "== bonnes réponses (guess) des parties de la fenêtre, par source"
q "SELECT gu.source, COUNT(*) FROM guess gu JOIN round r ON r.id = gu.round_id JOIN game g ON g.id = r.game_id WHERE g.started_at BETWEEN '$SINCE' AND '$UNTIL' GROUP BY gu.source;"
echo "== tentatives fausses comptées (round_player.wrong_attempts)"
q "SELECT COALESCE(SUM(rp.wrong_attempts),0), COUNT(*) FROM round_player rp JOIN round r ON r.id = rp.round_id JOIN game g ON g.id = r.game_id WHERE g.started_at BETWEEN '$SINCE' AND '$UNTIL';"

echo "== canal game : retard de diffusion (delayMs) par événement — n, p50, p95, max"
grep -h '"game.broadcast_delay"' "$APP/storage/logs/game-$DAY.log" 2>/dev/null \
  | awk -v s="$S_ISO" -v u="$U_ISO" '{
        match($0, /"datetime":"[^"]*"/); dt = substr($0, RSTART + 12, 19);
        if (dt < s || dt > u) next;
        match($0, /"event":"[^"]*"/); ev = substr($0, RSTART + 9, RLENGTH - 10);
        match($0, /"delayMs":[0-9-]+/); d = substr($0, RSTART + 10, RLENGTH - 10) + 0;
        print ev, d }' \
  | sort -k1,1 -k2,2n \
  | awk '{ v[$1, ++n[$1]] = $2 } END { for (e in n) { c = n[e]; p50 = v[e, int((c + 1) * 0.50)]; i95 = int(c * 0.95 + 0.999); if (i95 < 1) i95 = 1; printf "%-16s n=%d p50=%s p95=%s max=%s\n", e, c, p50, v[e, i95], v[e, c] } }' | sort
echo "== canal game : messages autres que broadcast_delay, resynchronized, round_opened, round_closed, finalized"
grep -h '"message":"game\.' "$APP/storage/logs/game-$DAY.log" 2>/dev/null \
  | awk -v s="$S_ISO" -v u="$U_ISO" '{ match($0, /"datetime":"[^"]*"/); dt = substr($0, RSTART + 12, 19); if (dt >= s && dt <= u) { match($0, /"message":"[^"]*"/); print substr($0, RSTART + 11, RLENGTH - 12) } }' \
  | sort | uniq -c | grep -vE ' game\.(broadcast_delay|resynchronized|round_opened|round_closed|finalized)$' || echo "   (aucun)"
echo "== journal laravel : niveaux dans la fenêtre"
grep -h '"level_name"' "$APP/storage/logs/laravel-$DAY.log" 2>/dev/null \
  | awk -v s="$S_ISO" -v u="$U_ISO" '{ match($0, /"datetime":"[^"]*"/); dt = substr($0, RSTART + 12, 19); if (dt >= s && dt <= u) { match($0, /"level_name":"[^"]*"/); l = substr($0, RSTART + 14, RLENGTH - 15); match($0, /"message":"[^"]*"/); print l, substr($0, RSTART + 11, (RLENGTH - 12 > 60 ? 60 : RLENGTH - 12)) } }' \
  | sort | uniq -c | sort -rn | head -12

echo "== nginx (vhost) : réponses par code HTTP dans la fenêtre"
awk -v s="$(date -u -d "$SINCE" +%s)" -v u="$(date -u -d "$UNTIL" +%s)" '
  BEGIN { split("Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec", m, " "); for (i = 1; i <= 12; i++) mon[m[i]] = i }
  { t = substr($4, 2); split(t, a, /[\/:]/); ts = mktime(a[3] " " mon[a[2]] " " a[1] " " a[4] " " a[5] " " a[6]);
    if (ts >= s && ts <= u) c[$9]++ }
  END { for (k in c) printf "%s=%d ", k, c[k]; print "" }' "$LOGS/access_ssl_log"
echo "== nginx (vhost) : erreurs d'amont dans la fenêtre"
awk -v s="${SINCE//-//}" -v u="${UNTIL//-//}" '{ t = $1 " " $2; if (t >= s && t <= u) print }' "$LOGS/error_log" \
  | grep -oE 'upstream timed out|connect\(\) to unix[^ ]* failed \([0-9]+: [^)]*\)|recv\(\) failed \([0-9]+: [^)]*\)|no live upstreams|upstream prematurely closed' | sort | uniq -c || true

echo "== unités : NRestarts, résultat, mémoire actuelle/plafond"
for u in tripleframes-php-fpm tripleframes-redis tripleframes-worker@game tripleframes-worker@default tripleframes-reverb; do
    printf '%-28s ' "$u"; systemctl show -p NRestarts,Result,MemoryCurrent,MemoryMax,MainPID "$u" | tr '\n' ' '; echo
done
echo "== journal systemd des unités tripleframes dans la fenêtre (arrêts, tueries, OOM)"
journalctl --since "$SINCE" --until "$UNTIL" -u 'tripleframes-*' --no-pager -o short-iso 2>/dev/null \
  | grep -E 'oom-kill|OOM|Killed|killed|exited|Failed|Scheduled restart|Main process' | cut -c1-200 || echo "   (aucune ligne)"
echo "== noyau : OOM dans la fenêtre"
journalctl -k --since "$SINCE" --until "$UNTIL" --no-pager 2>/dev/null | grep -ciE 'out of memory|oom-kill|oom_reaper' || true
echo "== Redis dédié : mémoire max, refus"
export REDISCLI_AUTH="$(awk '/^requirepass /{print $2}' /etc/tripleframes/tripleframes-redis.conf)"
redis-cli -p 6390 info memory | grep -E '^(used_memory_peak_human|maxmemory_human):'
redis-cli -p 6390 info stats | grep -E '^(rejected_connections|total_connections_received):'
unset REDISCLI_AUTH MYSQL_PWD
```

- **Attendu et vérification** — premier essai `A-1` (15:42:47 UTC) arrêté au bout de 82 s par un **faux positif du garde-fou** (étape 5.3, écart 2 ; ses deux parties, restées sans joueur, sont passées en `paused`, puis `interrupted` par l'échéance de 15 minutes à 15:59:38 et 15:59:48) ; essai `A-2`, **15:45:51 → 15:57:47 UTC, code 0, tous les seuils tenus** :

| Mesure | Relevé |
| --- | --- |
| Parcours | 4 salons créés (limiteur `room-create` par défaut) ; 15 sièges × 2 vagues ; 4 parties `completed` (10 manches chacune, 4 min 50 s : fins anticipées) ; `tf_games_joined` = `tf_games_ended` = 30 ; 300 révélations reçues, 0 manquante ; 204 bonnes réponses (168 en texte, 36 au QCM), 2 371 tentatives fausses comptées |
| Critère 1, client (k6) | 0 réponse 5xx, 0 erreur réseau, 0 échec de `wss` ou de souscription, 0 entrée refusée (10 entrées en 429 `room-join`, reprises après `Retry-After`) ; soumissions : **p95 154 ms, p99 233 ms**, max 327 ms ; retard des frontières `tier.opened` : **p95 105 ms, max 216 ms** (seuils 300 et 1 300) ; 0 frontière perdue ; 0 image en échec (images : p95 135 ms) |
| Critère 1, serveur | canal `game` : `tier.opened` n = 120, p95 100 ms, max 216 ms ; aucun autre message `game.*` ; journal `laravel` vide ; vhost : `200` 3 968, `204` 976, `429` 1 550, `409` 21, `404` 32 (images demandées un peu avant leur `fetchNotBefore`, reprises : 0 échec), `101` 30, **aucune 5xx, aucune erreur d'amont** ; pool `tripleframes` : **file d'attente 0**, jusqu'à 11 processus actifs sur 12 (`max children reached` : 2) ; Redis dédié : pic 1,8 Mo, 0 refus ; aucune OOM ; parties en cours en fin de fenêtre : seulement les deux de l'essai `A-1` (en pause) |
| Critère 2 | voisin témoin, vu du poste : p95 **15,1 ms** (seuil 21) ; `atomsdle` p95 1,05 ms (seuil 2) ; vus de la VM : voisin 14,0 ms, `atomsdle` 6,2 ms (références 15,5 et 7,9) |
| Machine | `load1` max 2,78 ; processeur occupé 9 % en moyenne (p95 22 %, max 50 %) ; mémoire disponible ≥ 2 189 Mo ; swap 192 Mo (récupération au repos, 581 pages relues en 20 min) ; `tripleframes-php-fpm` jusqu'à 29 % d'un cœur et 101 Mo ; MySQL 8 % ; workers, Reverb, Redis ≤ 3 % |

- **Écarts** :
  - **Recyclage horaire du worker `game`** : à 15:52:59, `Worker STOPPED Maximum run time exceeded` (`--max-time=3600`, code 0), relevé par `Restart=always` **2,2 s plus tard** (`RestartSec=2`) : pendant ce temps, aucune frontière de palier n'est exécutée. Ici sans effet (la partie 14 a été lancée à 15:52:59,4, son premier palier dû après la relève), mais toutes les heures, une frontière qui tombe dans ce trou part avec 2 à 2,5 s de retard, **au-delà du maximum du critère 1** (1 300 ms). À reporter dans `100` § 10.4 (voir « Écarts à reporter ») ; pour la séance, la relève suivante tombe à 16:53.
  - **Rythme du joueur coopératif au bord du limiteur** : 1 540 soumissions sur 4 232 (36 %) ont reçu `429` (« One attempt at a time. ») : le scénario soumet toutes les `1000 / attemptsPerSecond` ms exactement, et la fenêtre d'une seconde du limiteur `answer`, ouverte à la réception de la précédente, n'est pas toujours close à l'arrivée de la suivante. Sans effet sur les seuils (un 429 est un refus attendu), mais le débit « utile » mesuré en A est inférieur à celui qu'il paraît. À reporter pour L100-12 (marge de 10 % sur l'intervalle du joueur coopératif, comme le pilote de l'étape 4.9).
  - Moyenne de processeur **négative** pour les workers dans `resume.py` : leur cgroup est remis à zéro par le recyclage ; artefact de mesure, sans conséquence.
- **Sur le VPS Plesk :** même exécution **depuis la racine du dépôt**, sans enveloppe :

```bash
k6 run -e K6_BASE_URL=https://<DOMAINE> -e PHASE=A -e ROOMS=2 -e RAMP_MINUTES=1 -e PLATEAU_MINUTES=5 \
       --console-output tests/Load/.data/console-A-rep-1.log tests/Load/game-load.js
```

  (un nom de `--console-output` **neuf par exécution** : c'est le seul journal de reprise des codes de salon si k6 meurt avant `handleSummary`, en-tête du scénario ; un second essai de la répétition prend `console-A-rep-2.log`) et, dans un second terminal, `k6 run -e PHASE=neighbours -e NEIGHBOUR_URLS=… -e NEIGHBOUR_BASELINE_P95_MS=… -e NEIGHBOUR_MINUTES=20 tests/Load/game-load.js`. En root sur le VPS, pendant l'exécution : l'échantillonneur de l'étape 5.3 (avec ses substitutions), `vmstat 5` (colonnes `r`, `b`, `wa`, `si`, `so`), `systemctl status tripleframes-*`, le bloc de l'étape 3.1 et la file d'attente **réelle** du pool :

```bash
# [root] File réelle du pool (Recv-Q du socket en écoute = connexions en attente d'accept()) :
# sur un socket unix, « listen queue » de la page de statut reste à 0 (écart n° 29).
ss -xlnH | awk '$5=="/var/www/vhosts/system/<DOMAINE>/php-fpm.sock"{print $3}'
# Pool plein : « max children reached » croît (page de statut, ops/plesk/settings.md § 3).
SCRIPT_NAME=/fpm-status SCRIPT_FILENAME=/fpm-status REQUEST_METHOD=GET cgi-fcgi -bind -connect /var/www/vhosts/system/<DOMAINE>/php-fpm.sock \
  | grep -E '^(active processes|max active processes|max children reached):'
```

  Après l'exécution, `analyse.sh` sur sa fenêtre (`bash /root/tripleframes-charge/analyse.sh '<début UTC>' '<fin UTC>'`), recopié avec ces substitutions :
  - `APP=/var/www/vhosts/<DOMAINE>/tripleframes` ; `LOGS=/var/www/vhosts/system/<DOMAINE>/logs`, et les **noms des journaux nginx** relevés par `ls -l /var/www/vhosts/system/<DOMAINE>/logs/` (`access_ssl_log` et `error_log` sur la VM ; selon le mode de service de Plesk, le journal d'accès HTTPS de nginx peut s'appeler autrement, `proxy_access_ssl_log` par exemple) ; format `combined` supposé, à vérifier sur une ligne (`$4` = `[jj/Mmm/aaaa:hh:mm:ss`, `$6`-`$7` = méthode et URI, `$9` = code). Même relevé pour `debit.sh` (étape 5.12) ;
  - base : `-u tripleframes_prod tripleframes_prod` → `-u <DB_USERNAME> <DB_DATABASE>` du `.env` (base et utilisateur créés par Plesk) ; si le socket par défaut du client n'est pas celui du serveur, `--socket=<chemin>` relevé par `ss -xlp | grep mysqld` ;
  - Redis : `6390` → `__TF_REDIS_PORT__` relevé (deux lignes) ; `/etc/tripleframes/tripleframes-redis.conf` inchangé ;
  - boucle des unités : `tripleframes-php-fpm` → `plesk-php84-fpm`, **service partagé par les pools PHP 8.4 de tous les abonnements** : ses redémarrages et sa mémoire ne sont pas attribuables à TripleFrames ;
  - `mktime` d'awk : gawk sur la VM ; vérifier `awk 'BEGIN{print mktime("2026 09 28 00 00 00")}'` (un nombre, pas une erreur) ;
  - fuseau : le script suppose un serveur en UTC (la VM l'est). Relever `timedatectl` ; sinon, écrire ` UTC` après les deux bornes de `journalctl` (`--since "$SINCE UTC" --until "$UNTIL UTC"`) et donner au filtre de `error_log` (heure locale, sans décalage) les bornes en heure locale ; le filtre du journal d'accès (`mktime`, heure locale → instant) et les requêtes SQL (UTC) restent justes.

  Critère d'arrêt : une 5xx, un échec de `wss`, ou la machine qui sature. **Correction du contrôle de la phase Charge** : cette ligne citait seulement la commande `cgi-fcgi` du pool, dont la `listen queue` reste à 0 sur un socket unix, et donnait à cette répétition le même `--console-output` que la séance A de l'étape 5.8.

### Étape 5.6 — Nettoyage des salons de la répétition (`loadtest:forget`)

- **But** : `100` § 16.5 : oublier les salons synthétiques **avant leur archivage** (les pseudos `k6-…` prouvent qu'un siège est synthétique), sans toucher au catalogue ; une fois leurs parties terminées (une partie `paused` est « en cours » : la commande l'écarte).
- **Commandes** — [poste] : les listes écrites par `handleSummary` (`<scratchpad>/vm/k6/.data/rooms-A-<instant>.txt`) copiées sous des noms courts, puis déposées et jouées par `<scratchpad>/vm/charge/forget.sh` :

```bash
cp <scratchpad>/vm/k6/.data/rooms-A-2026-09-28T15-44-09-829Z.txt <scratchpad>/vm/charge/rooms-A-1.txt
cp <scratchpad>/vm/k6/.data/rooms-A-2026-09-28T15-57-47-134Z.txt <scratchpad>/vm/charge/rooms-A-2.txt
scp -i <clé> <scratchpad>/vm/charge/rooms-A-1.txt <scratchpad>/vm/charge/rooms-A-2.txt vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/charge/
ssh -i <clé> vagrant@192.168.10.10 'bash -s rooms-A-1.txt rooms-A-2.txt' < <scratchpad>/vm/charge/forget.sh
```

```bash
# [vagrant → abo] Nettoyage des salons synthétiques (100 § 16.5) : fichiers de codes déposés dans ~/incoming, puis --dry-run, puis réel.
set -uo pipefail
H=/var/www/vhosts/tripleframes-prod.test
for f in "$@"; do
    sudo install -o tripleframes -g tripleframes -m 0600 "/home/vagrant/tripleframes-repetition/charge/$f" "$H/incoming/$f"
done
sudo -u tripleframes -H bash -s -- "$@" <<'ABO'
umask 027
cd ~/tripleframes
PHP=/opt/plesk/php/8.4/bin/php
for f in "$@"; do
    echo "== $f : --dry-run"
    "$PHP" artisan loadtest:forget ~/incoming/"$f" --dry-run; echo "code=$?"
    echo "== $f : réel"
    "$PHP" artisan loadtest:forget ~/incoming/"$f"; echo "code=$?"
done
ABO
```

  Contrôle, [root] : `sudo bash /home/vagrant/tripleframes-repetition/outils/sqlp.sh "SELECT COUNT(*), MAX(id) FROM game; SELECT COUNT(*) FROM player WHERE nickname LIKE 'k6-%'; SELECT COUNT(*) FROM room WHERE archived_at IS NULL; SELECT COUNT(*) FROM movie; SELECT COUNT(*) FROM frame;"`.
- **Attendu et vérification** (16:07 UTC, après l'interruption des deux parties de `A-1` à 15:59) : pour chaque fichier, `--dry-run` → « Simulation : salons synthétiques qui seraient oubliés : 4. Rien n'a été supprimé. » (code 0), puis « Salons synthétiques oubliés (faits de partie, sièges et salon) : 4. » (code 0) ; les deux salons de la seconde vague de `A-1`, jamais joués (seul leur hôte y siège), sont oubliés aussi. Après : **8 parties** (celles de la phase 4, `MAX(id)` 8), **0 siège `k6-`**, 3 salons actifs (ceux de la phase 4), catalogue intact (17 films, 87 frames).
- **Sur le VPS Plesk :** pas de `~/incoming` (étape 1.3 : ni `incoming/` ni dépôt nu à la main sur le VPS) ; les listes vont sous le répertoire privé de l'abonnement, dans l'ordre suivant ; **avant 24 h** (archivage des salons) et après la fin de toutes les parties (15 minutes après un arrêt brutal de k6 : échéance des parties en pause).

```bash
# [abo] SSH de l'utilisateur d'abonnement : répertoire de dépôt des listes (une fois).
(umask 077 && mkdir -p ~/private/loadtest)
```

```bash
# [poste] Git Bash, racine du dépôt : listes de la phase (ici A), écrites par handleSummary.
scp tests/Load/.data/rooms-A-*.txt <utilisateur d'abonnement>@<VPS>:/var/www/vhosts/<DOMAINE>/private/loadtest/
```

```bash
# [abo] Pour chaque fichier : simulation, puis réel.
PHP=/opt/plesk/php/8.4/bin/php
cd ~/tripleframes
ls -l ~/private/loadtest/
"$PHP" artisan loadtest:forget ~/private/loadtest/<fichier> --dry-run
"$PHP" artisan loadtest:forget ~/private/loadtest/<fichier>
```

  Un fichier n'est supprimé (`rm ~/private/loadtest/<fichier>`) qu'une fois **tous** ses salons oubliés : « Salons écartés parce qu'une partie y est encore en cours » impose de relancer la commande plus tard avec le même fichier (étape 5.10). **Correction du contrôle de la phase Charge** : la version précédente de cette ligne copiait vers `incoming/` et lisait `~/incoming/<fichier>`, deux chemins qui n'existent pas sur le VPS (sur la VM, `~/incoming` avait été créé à la main pour simuler la forge, étape 1.3).

### Étape 5.7 — Déploiement n° 6 : limiteurs d'entrée relevés pour la séance (procédure § 11.4)

- **But** : en-tête du scénario, préalable 2 : les limiteurs `room-create` (10 créations par heure et par adresse) et `room-join` (10 entrées par minute, par adresse pour un visiteur encore sans jeton) sont clés sur l'**adresse** ; depuis un seul poste, la séance les dépasse de loin (phase A : 60 salons créés d'avance, 130 entrées par vague ; A2 et B : 20 salons, 220 entrées chacune ; le pot à cookies de k6 est vidé à chaque itération, donc chaque vague entre sans jeton). Ils se relèvent **par un déploiement** (`config/game.php` › `room` ; ni variable d'environnement, ni geste à chaud), puis se rétablissent par le déploiement suivant (étape 5.11). Depuis l'activation du drainage (étape 4.12), tout déploiement suit la procédure complète du § 11.4.
- **Commandes** — [poste], Git Bash, dans le clone jetable (`<scratchpad>/vm/build/src`, jamais le dépôt du porteur) : `config/game.php`, section `room`, les deux constantes remplacées par des valeurs de séance :

```php
    'room' => [
        // Séance de charge (100 § 16) : relevés le temps de la séance, depuis
        // une seule adresse (le poste), puis rétablis par le déploiement suivant.
        'creates_per_hour' => 300,
        'joins_per_minute' => 600,
    ],
```

```bash
git -c user.name="répétition" -c user.email="repetition@tripleframes-prod.test" commit -qam "répétition : limiteurs d'entrée relevés pour la séance de charge (100 § 16), jamais poussé"
export PATH="$(cygpath -u '<scratchpad>')/vm/build/php-stub:$PATH"
cp public/build/manifest.json ../manifest-5.json
npm run build
cmp -s public/build/manifest.json ../manifest-5.json && echo "manifest identique au build n° 5"
refused="$(find public/build \( -iname '*.php' -o -iname '*.php[0-9]' -o -iname '*.phtml' -o -iname '*.pht' -o -iname '*.phps' -o -iname '*.phar' -o -name '.*' \) -print)"
[ -z "$refused" ] && echo "Verify Build Contents : OK" || echo "REFUSÉS : $refused"
bash -s < <scratchpad>/vm/deploiement/rv10-artifacts.sh      # bloc de l'étape 2.4
git diff --stat deploy~1 deploy
git bundle create ../deploy-6.bundle deploy
scp -i <clé> ../deploy-6.bundle vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/deploy-6.bundle
ssh -i <clé> vagrant@192.168.10.10 'bash -s 6' < <scratchpad>/vm/charge/deploy-tirer.sh
```

  `deploy-tirer.sh` (« Tirer », sans déployer) :

```bash
# [vagrant → abo] Artefact n° N (argument) : dépôt dans incoming, puis « Tirer » (sans déployer).
set -euo pipefail
N="$1"
H=/var/www/vhosts/tripleframes-prod.test
sudo install -o tripleframes -g tripleframes -m 0600 "/home/vagrant/tripleframes-repetition/deploy-$N.bundle" "$H/incoming/deploy-$N.bundle"
sudo -u tripleframes -H bash -c "
umask 027
cd ~
git -C ~/git/tripleframes.git bundle verify -q ~/incoming/deploy-$N.bundle && echo 'bundle vérifié'
git -C ~/git/tripleframes.git fetch -q ~/incoming/deploy-$N.bundle deploy:deploy
git -C ~/git/tripleframes.git log --oneline -2 deploy
"
```

  Puis [abo] : drainage, garde, « Déployer », hook, levée — la procédure interactive de l'étape 4.13 (`tmux`, `deploy:drain` jusqu'au code 0, `deploy:guard`, `checkout -f deploy`, `bash ops/deploy/hook.sh`, `deploy:release`, `cat ~/drain.log`, `tmux kill-session -t tf-drain`), jouée ici sous sa forme détachée (`<scratchpad>/vm/charge/deploy-drainer-deployer.sh`, par `ssh … 'sudo -u tripleframes -H bash -s' <`) :

```bash
# [abo] Procédure 100 § 11.4 sans partie en cours : drainage détaché (tmux), garde, « Déployer », hook, levée.
# Forme détachée du pilote ; le porteur tape la forme interactive de l'étape 4.13.
set -uo pipefail
umask 027
PHP=/opt/plesk/php/8.4/bin/php
cd ~/tripleframes
tmux has-session -t tf-drain 2>/dev/null && { echo "tf-drain déjà ouverte : arrêt"; exit 1; }
: > ~/drain.log
tmux new-session -d -s tf-drain -c ~/tripleframes "$PHP artisan deploy:drain 2>&1 | tee -a ~/drain.log; echo \"deploy:drain code=\${PIPESTATUS[0]}\" | tee -a ~/drain.log; sleep 900"
for i in $(seq 1 120); do grep -q '^deploy:drain code=' ~/drain.log && break; sleep 2; done
cat ~/drain.log
grep -q '^deploy:drain code=0' ~/drain.log || { echo "drainage non abouti : arrêt, rien n'est déployé"; exit 1; }
"$PHP" artisan deploy:guard; g=$?; echo "deploy:guard code=$g"
[ "$g" -eq 0 ] || { echo "garde refusée : arrêt"; exit 1; }
cd ~
# « Déployer » émulé sous umask 022, comme le bouton de Plesk (correction de la Clôture, étape 6.3) ; sur le VPS : le bouton
(umask 022 && git --git-dir=$HOME/git/tripleframes.git --work-tree=$HOME/tripleframes checkout -f deploy)
echo "public/ illisible des autres comptes : $(find $HOME/tripleframes/public ! -perm -o=r | wc -l)"   # 0
cd ~/tripleframes
echo "checkout : $(git --git-dir=$HOME/git/tripleframes.git rev-parse --short HEAD)"
bash ops/deploy/hook.sh; h=$?; echo "code du hook = $h"; [ "$h" -eq 0 ] || { echo "hook en échec : drapeau laissé en place (100 § 11.5)"; tmux kill-session -t tf-drain; exit 1; }
"$PHP" artisan deploy:guard; echo "deploy:guard code=$?"
"$PHP" artisan deploy:release; echo "deploy:release code=$?"
tmux kill-session -t tf-drain
tmux ls 2>&1
stat -c '%a %U %n' bootstrap/cache/*.php
```

  **Correction du contrôle de la phase Charge (bloc corrigé après coup, non rejoué)** : la version jouée à 16:08 et 16:49 (`bash ops/deploy/hook.sh; echo "code du hook = $?"`, sans `set -e`) enchaînait `deploy:release` **quel que soit** le code du hook. Or `100` § 11.5 veut que le drapeau tienne après un échec du hook avant son étape 12 (fichiers déjà déposés par « Déployer », migrations peut-être pas jouées) et ne soit levé par `deploy:release` qu'**une fois l'état réparé** ; recopié tel quel, l'ancien script aurait rouvert les lancements sur un code à moitié déployé. Désormais, un hook en échec arrête le script **drapeau en place** (la session `tmux` du drainage, dont `deploy:drain` est déjà terminé, est seulement fermée ; `~/drain.log` reste lisible) : remède du § 11.5, puis `deploy:release` à la main. Les deux exécutions de la répétition ont eu un hook en code 0 : leur déroulé est inchangé. Même garde ajoutée à la forme interactive de l'étape 4.13. Syntaxe vérifiée (`bash -n`) sur le fichier corrigé du poste.

  Contrôle : `sudo -u tripleframes -H bash -c "cd ~/tripleframes && /opt/plesk/php/8.4/bin/php artisan config:show game.room"`, PID des unités, bloc de sondes de l'étape 3.1.
- **Attendu et vérification** (16:07-16:09 UTC) : commit source `699f8c8`, `npm run build` réussi, manifest identique au build n° 5, `Verify Build Contents : OK` ; « Publié : deploy `7e5736f`…, source `699f8c8`… », différence limitée à `config/game.php` (4 lignes ajoutées, 2 retirées) ; « bundle vérifié », `deploy` = `7e5736f` au-dessus de `9e5ed5a`. Déploiement de 16:08:13 à 16:08:37 : « Drainage commencé », « Fenêtre libre ouverte jusqu'à 16:38:30Z » (aucune partie en cours), `deploy:drain code=0`, `deploy:guard code=0`, hook `[1/12]` à `[12/12]` code 0 (« Nothing to install », garde franchie, « Aucune migration en attente », « Nothing to migrate », `PlatformDataSeeder` DONE, reprojection, `optimize`, empreinte `b8e47251…`, redémarrages, « Drapeau de drainage levé »), puis `deploy:guard` 2 et `deploy:release` « rien à lever » ; `tmux` : plus de session ; caches de démarrage en `640` (`packages.php`, `services.php` en `750`, attendu) ; `config:show game.room` → **`creates_per_hour` 300, `joins_per_minute` 600** ; workers et Reverb relancés (16:08:39-43) ; cinq sondes `ok`, `/up` 200.
- **Écart (relevé par le contrôle de la phase Charge) — le commit de séance joué ici aurait mis la CI au rouge** : `tests/Feature/Room/RoomRateLimitsTest.php` exige `config('game.room')` **égal** aux constantes `RoomRateLimits::DEFAULT_CREATES_PER_HOUR` et `DEFAULT_JOINS_PER_MINUTE` (10 et 10) ; des littéraux 300 et 600 dans `config/game.php` le font échouer. La VM ne l'a pas vu : sa chaîne d'artefacts (`rv10-artifacts.sh` : `read-tree`, `commit-tree`) ne joue aucun test, alors que sur la forge le job `ci` précède `build` et `artifacts` (`.github/workflows/tests.yml` : `needs: ci`, puis `needs: build`) : CI rouge, aucun artefact, limiteurs jamais relevés, et la séance s'arrête au premier `setup()` (429 sur `room-create`). Vérifié sur la VM, dans une copie jetable du clone de tests (`tests-s1` laissé intact ; SQLite en mémoire par `phpunit.xml`, aucune base touchée ; copie supprimée ensuite), [vagrant] (`<scratchpad>/vm/charge/controle/c5-limiteurs-test.sh`, par `ssh … 'bash -s' <`) :

```bash
# [vagrant] Contrôle de la phase Charge, constat 1 : quel commit de séance garde RoomRateLimitsTest vert ?
# Copie jetable du clone de tests (tests-s1 intact), sqlite :memory: (phpunit.xml), supprimée à la fin.
set -uo pipefail
R=/home/vagrant/tripleframes-repetition
C=$R/tests-c5
rm -rf "$C"
cp -a "$R/tests-s1" "$C"
cd "$C"
grep -n "DB_CONNECTION\|DB_DATABASE" phpunit.xml
echo "== (a) commit de séance du clone jetable : littéraux 300 et 600 dans config/game.php"
sed -i "s/'creates_per_hour' => RoomRateLimits::DEFAULT_CREATES_PER_HOUR,/'creates_per_hour' => 300,/; s/'joins_per_minute' => RoomRateLimits::DEFAULT_JOINS_PER_MINUTE,/'joins_per_minute' => 600,/" config/game.php
grep -n "creates_per_hour\|joins_per_minute" config/game.php
php vendor/bin/pest --colors=never tests/Feature/Room/RoomRateLimitsTest.php 2>&1 | tail -25; echo "code (a) = ${PIPESTATUS[0]}"
git checkout -q -- config/game.php
echo "== (b) commit de séance corrigé : constantes DEFAULT_* relevées, config/game.php inchangé"
sed -i 's/public const int DEFAULT_CREATES_PER_HOUR = 10;/public const int DEFAULT_CREATES_PER_HOUR = 300;/; s/public const int DEFAULT_JOINS_PER_MINUTE = 10;/public const int DEFAULT_JOINS_PER_MINUTE = 600;/' app/Support/Room/RoomRateLimits.php
grep -n "DEFAULT_CREATES_PER_HOUR =\|DEFAULT_JOINS_PER_MINUTE =" app/Support/Room/RoomRateLimits.php
git diff --stat
php vendor/bin/pest --colors=never tests/Feature/Room 2>&1 | tail -6; echo "code (b) = ${PIPESTATUS[0]}"
cd "$R" && rm -rf "$C" && test ! -e "$C" && echo "copie jetable supprimée"
```

  Résultat (28/09, 17:27 UTC) : (a) « déclare une clé game.room par débit d'entrée, et réciproquement » **échoue** (`'creates_per_hour' => 10` attendu, 300 obtenu), `Tests: 1 failed, 2 passed`, code 1 ; (b) `tests/Feature/Room` entier **vert**, `Tests: 2038 passed (17479 assertions)`, code 0 (les autres tests des limiteurs, `RoomCreationTest`, `SeatTakingTest` et `LateJoinTest`, posent leurs propres valeurs par `config()->set`) ; copie supprimée. Le geste de séance relève donc les **constantes**, jamais la configuration (écart n° 38).
- **Sur le VPS Plesk :** **commit de séance sur `main`** (le job `artifacts` ne tourne que sur un push vers `main` et ne publie que la pointe de `main` : une branche de travail ne produit aucun artefact `deploy`). Il modifie les constantes `DEFAULT_CREATES_PER_HOUR` (300) et `DEFAULT_JOINS_PER_MINUTE` (600) dans `app/Support/Room/RoomRateLimits.php`, **sans toucher `config/game.php`**, pour que `RoomRateLimitsTest` reste vert. Jouer `composer ci:check` sur le poste avant de pousser (tant que PHP y est bloqué, voir « Impasses », le job `ci` de la forge en est le seul juge : ne rien tirer avant qu'il soit vert), attendre les deux workflows verts et la ligne « Publié : deploy … » du job `artifacts`, puis suivre le § 11.4 à la lettre (Plesk › Git › « Tirer », SSH de l'abonnement dans `tmux` : `deploy:drain`, `deploy:guard`, Plesk › Git › « Déployer », sortie `[3/12]` et `[12/12]`, `cat ~/drain.log`, `tmux kill-session -t tf-drain`) ; contrôle `config:show game.room` → 300 et 600. Valeurs à choisir selon le nombre de salons et de vagues de la séance ; **le rétablissement est un déploiement dû avant la première vraie partie** (étape 5.11) : laisser 300 créations par heure et par adresse ouvrirait la création de salons en masse.

### Étape 5.8 — Séance à l'échelle D33, scénario A : 20 salons, 150 joueurs — **la VM sature, arrêt au bout de 6 min 27 s**

- **But** : `100` § 16.2 : 20 salons de 7 et 8 joueurs (150), preset `classic` (N = 3, D = 30 s, R = 8 s, M = 10), montée de 5 minutes, palier de 15 minutes tenu par des vagues de salons neufs (3 vagues, 60 salons créés d'avance) ; les deux critères de § 16.4, sous garde-fou.
- **Commandes** — [poste] : nouvelle référence des voisins, **dans les 10 minutes qui précèdent** (même commande qu'à l'étape 5.4, étiquette `reference-2`, 16:09-16:14 UTC : voisin témoin p95 13,48 ms, `atomsdle` 1,06 ms), puis :

```bash
bash <scratchpad>/vm/charge/seance.sh A-3 35 13.48 1.06 -- -e PHASE=A -e ROOMS=20 -e RAMP_MINUTES=5 -e PLATEAU_MINUTES=15
```

  Après l'arrêt, [root] : `analyse.sh '2026-09-28 16:14:22' '2026-09-28 16:21:00'` ; [poste] : `resume.py` par fenêtre (montée 16:14:22-16:18:59, jeu 16:19:00-16:20:50, retour au calme 16:21:00-16:22:25) ; arrêt anticipé de la phase `neighbours` et de la veille une fois le retour au calme relevé (`curl -X PATCH … http://127.0.0.1:6566/v1/status`, puis arrêt de la veille et de l'échantillonneur).
- **Déroulé observé** (UTC) :

| Instant | Observé |
| --- | --- |
| 16:14:22 → 16:14:32 | `setup()` : 60 salons créés en 10 s (limiteur relevé : aucun 429), preset `classic` appliqué |
| 16:14:32 → 16:19:00 | montée : 150 joueurs entrés au fil des 5 minutes, lobbies en battement et `wss` ; machine à 19 % de processeur en moyenne (p95 47 %), `load1` ≤ 1,6 ; pool `tripleframes` déjà à 12 processus sur 12 par moments (`max children reached` 3) ; voisins inchangés |
| 16:19:00 → 16:19:30 | les 20 parties démarrent (décompte, palier 1 : 150 images demandées, saisies) : processeur de 75 % à **100 %** |
| 16:19:30 → 16:20:49 | **saturation** : processeur 100 % à chaque relevé, `load1` de 5,6 à 12,0, 12 processus PHP sur 12 actifs en permanence ; `tripleframes-php-fpm` 129 % d'un cœur en moyenne (175 % au plus), MySQL 35 % (49 %), worker `game` 6 %, Reverb 1 % ; `/up` vu de la VM : médiane **1,08 s**, jusqu'à 3,2 s |
| 16:20:49 | **`STOP … charge load1=12.01 > 4 depuis plus de 60 s`** : la veille arrête k6 par son API (`test run stopped from REST API`, code 103) ; `handleSummary` écrit la liste des 60 salons |
| 16:21:00 → 16:22:25 | retour au calme immédiat : processeur 8 % en moyenne, voisins et `/up` revenus à leur niveau de référence ; `load1` décroît (moyenne glissante) : 3,7 à 16:22 |

- **Critère 1 — le jeu tient : NON, sur cette machine** (k6, sur 6 min 27 s dont 1 min 50 s de jeu) : soumissions **p95 2 049 ms, p99 3 324 ms** (seuils 250 et 1 000 : **échec**) ; images p95 2,6 s ; retard des frontières `tier.opened` **p95 483 ms** (seuil 300 : **échec**), max 849 ms (seuil 1 300 : tenu) ; canal `game` côté serveur : `tier.opened` n = 228, p95 512 ms, max 989 ms, `round.revealed` et `round.scheduled` p95 près de 700 ms. **Aucune 5xx** (nginx : `200` 9 142, `204` 3 803, `429` 734, `409` 32, `404` 63, `499` 65 — requêtes coupées par l'arrêt de k6), aucune erreur d'amont, aucun échec de `wss` ni de souscription, 0 frontière perdue, 474 révélations reçues et 0 manquante, 0 image en échec ; aucune unité redémarrée ni tuée, aucune OOM, Redis 2,2 Mo au plus, 0 refus ; mémoire disponible ≥ 2 210 Mo, aucun échange soutenu. **La machine manque de processeur, pas de mémoire.**
- **Critère 2 — les voisins ne se dégradent pas : NON** : voisin témoin (page PHP du même master PHP-FPM), vu de la VM pendant la saturation : médiane 52 ms, **p95 85 ms** (référence 12,7 ms, soit × 6,7) ; vu du poste sur toute l'exécution : **p95 77 ms** (seuil 16 : échec) ; voisin statique `atomsdle` vu de la VM : p95 13,2 ms (référence 5,3) ; vu du poste 1,57 ms (seuil 1, fragile : étape 5.4).
- **Files d'attente** : la page de statut du pool rend `listen queue: 0`, `max listen queue: 0` **et `listen queue len: 0`** pendant que ses 12 processus sont tous occupés et que `/up` attend plus d'une seconde : sur un **socket unix**, PHP-FPM ne mesure pas sa file sous Linux (mesure réservée aux sockets TCP). Seuls `max active processes: 12` et `max children reached: 3` la trahissent. L'échantillonneur relève désormais la file réelle par `ss -xlnH` (colonne `Recv-Q` du socket en écoute, `fpm_sock_backlog`) ; voir « Écarts à reporter ».
- **Décision** : conformément à la consigne (« arrêter dès que la VM sature, et le consigner comme résultat »), **la séance à l'échelle D33 s'arrête là** : les phases A2 (240 joueurs à 1 soumission par seconde) et B (1 200 requêtes par seconde) sont strictement plus lourdes que A, qui sature déjà la VM ; elles ne sont **pas** jouées à cette échelle. Leur outillage est répété à petite échelle (étape 5.9). Les 60 salons de A (20 parties passées en `paused` à l'arrêt, `interrupted` 15 minutes plus tard) sont oubliés à l'étape 5.10.
- **Ce que cela dit du VPS** : voir l'étape 5.13 (coût mesuré par requête, dimensionnement, plafonds). Rien de cette mesure n'entre dans `100` § 16.6.
- **Sur le VPS Plesk :** même commande depuis la racine du dépôt (`k6 run -e K6_BASE_URL=https://<DOMAINE> -e PHASE=A --console-output tests/Load/.data/console-A-1.log tests/Load/game-load.js`, voisins dans un second terminal ; `console-A-1.log` est propre à cette exécution, distinct de celui de la répétition à deux salons, `console-A-rep-1.log`, et une nouvelle séance A prend `console-A-2.log`), à l'heure de la référence ; le porteur surveille `vmstat 5` et `uptime` en root, avec l'échantillonneur et la file du socket (étapes 5.3 et 5.5), et arrête k6 par `Ctrl+C` (une fois) à la même règle ; relevé serveur ensuite par `analyse.sh` (étape 5.5, avec ses substitutions). Un arrêt par saturation n'est **pas** un échec à contourner : c'est le résultat de la séance, qui déclenche les réactions écrites d'avance (`100` § 16.4) puis une nouvelle séance.

### Étape 5.9 — Phases A2 et B à petite échelle (répétition de l'outillage, jamais une mesure D33)

- **But** : jouer au moins une fois contre la production les deux phases jamais exercées (salons **pleins**, soumission continue sans s'arrêter aux refus), à une échelle que la VM supporte : **A2** sur 2 salons pleins (24 joueurs à 1 soumission par seconde, rafale de 2 minutes) ; **B** sur 1 salon plein (12 joueurs à 5 soumissions par seconde, borne haute du réglage : 60 requêtes par seconde, rafale d'une minute), sous le même garde-fou. Référence des voisins : `reference-2`.
- **Commandes** — [poste] :

```bash
bash <scratchpad>/vm/charge/seance.sh A2-1 12 13.48 1.06 -- -e PHASE=A2 -e ROOMS=2 -e RAMP_MINUTES=1 -e BURST_MINUTES=2
bash <scratchpad>/vm/charge/seance.sh B-1  10 13.48 1.06 -- -e PHASE=B  -e ROOMS=1 -e RAMP_MINUTES=1 -e BURST_MINUTES=1
```

  puis `analyse.sh` sur chaque fenêtre (16:23:58-16:29:52 et 16:30:29-16:33:05) et `resume.py` sur la rafale ; la phase `neighbours` et la veille arrêtées par l'API une fois les joueurs finis.
- **Attendu et vérification** :

| Mesure | A2 réduite (24 joueurs, 16:23:58 → 16:29:50) | B réduite (12 joueurs, 16:30:29 → 16:33:03) |
| --- | --- | --- |
| Issue de k6 | code 99 (deux seuils franchis), partie menée à son terme | **arrêt par le garde-fou** à 16:33:04 (`load1` 4,81 > 4 depuis plus de 60 s), code 103 ; la rafale était déjà finie (16:32:35) : `load1` est une moyenne glissante qui retarde d'une minute |
| Soumissions | 3 039 (≈ 25 par seconde) : 107 acceptées, 958 refusées, 863 `409` (manche close), 1 111 `429` | 3 610 (≈ 55 par seconde) : 17 acceptées, 295 refusées, 359 `409`, **2 939 `429` (81 %)** |
| Latence des soumissions | **p95 305 ms** (seuil 250 : échec), p99 419 ms | p95 136 ms, p99 187 ms |
| Frontières `tier.opened` | **p95 360 ms** (seuil 300 : échec), max 507 ms ; canal `game` : p95 357, max 507 | p95 70 ms, max 70 ms |
| 5xx, `wss`, souscriptions, images | 0, 0, 0, 0 échec | 0, 0, 0, 0 échec — **l'excédent est refusé proprement en 429, sans 5xx** |
| Machine pendant la rafale | processeur 22 % en moyenne (44 % au plus), `load1` ≤ 0,5 ; **12 processus PHP sur 12** et jusqu'à **13 connexions en attente** sur le socket (`listen queue` de PHP-FPM : 0) | processeur 34 % en moyenne (61 %), **`load1` jusqu'à 7,6** ; 12 processus sur 12, 5 connexions en attente |
| Voisins | poste : voisin témoin p95 18,9 ms (seuil 16 : échec) ; VM : 15,4 ms (réf. 12,7) | poste : p95 37,9 ms (échec) ; VM : **87 ms** (réf. 12,7) |
| Unités, OOM, Redis | aucune relance, aucune OOM, 0 refus | idem |

- **Lecture** : même sans saturer le processeur, les **rafales synchrones** (tous les joueurs d'un salon soumettent au même battement d'une seconde) occupent les 12 processus du pool et remplissent la file du socket : la latence et le retard des frontières franchissent leurs seuils à 25 soumissions par seconde. En B, `load1` monte à 7,6 avec un processeur à moitié libre : des tâches **en attente d'entrées-sorties** (chaque requête écrit sa session en base, `SESSION_DRIVER=database`, avec `innodb_flush_log_at_trx_commit=1` sur le disque virtuel de la VM) — hypothèse cohérente avec les relevés, l'attente d'E/S n'étant pas mesurée par l'échantillonneur. Le voisin témoin se dégrade avec : il partage le **master** PHP-FPM et le disque.
- **Coût mesuré par requête** (toutes unités de l'application, processeur Ryzen 7 5700X de l'hôte) : **≈ 20 ms de processeur par requête** en A (22,0 ms à 2 salons, 20,5 ms à 20 salons ; dont PHP ≈ 14,7 ms et MySQL ≈ 4 ms), 19,7 ms en A2, **15,1 ms en B** où 81 % des réponses sont des 429 (un refus coûte encore ≈ 12 ms : il traverse Laravel, la session et `seat.active` avant le limiteur). Base du dimensionnement de l'étape 5.13.
- **Écart** : le critère d'arrêt sur `load1` réagit avec une minute de retard et sur l'attente d'E/S autant que sur le processeur ; il a arrêté B **après** la fin de sa rafale, donc coupé une partie qui se serait terminée seule (passée en `paused`, puis `interrupted`). Sans conséquence ici ; pour une séance réelle, lire aussi `vmstat 5` (colonnes `r`, `b`, `wa`).
- **Sur le VPS Plesk :** A2 et B se jouent **à l'échelle D33** (5 minutes chacune par défaut), seulement si A a tenu, chacune avec **son propre** `--console-output` (journal de reprise des codes de salon, jamais réutilisé ; correction du contrôle de la phase Charge : la version précédente de cette ligne faisait écrire B dans le journal de A2), voisins dans un second terminal comme à l'étape 5.8 :

```bash
k6 run -e K6_BASE_URL=https://<DOMAINE> -e PHASE=A2 --console-output tests/Load/.data/console-A2-1.log tests/Load/game-load.js
k6 run -e K6_BASE_URL=https://<DOMAINE> -e PHASE=B --console-output tests/Load/.data/console-B-1.log tests/Load/game-load.js
```

  La mesure de B décide de la **protection en amont de PHP** (`100` § 16.3) : à ≈ 12 ms de processeur par refus, 1 200 requêtes par seconde demandent à elles seules une quinzaine de vCPU de ce type.

### Étape 5.10 — Nettoyage des salons de la séance (`loadtest:forget`)

- **But** : `100` § 16.5 : oublier les 63 salons synthétiques de la séance (60 de A, 2 de A2, 1 de B) avant leur archivage, **après** la fin de leurs parties : les 20 parties de A et celle de B, coupées par l'arrêt de k6, sont passées en `paused` puis `interrupted` à l'échéance de 15 minutes sans joueur connecté (`EngineConstants::DEFAULT_PAUSE_TIMEOUT_MS`).
- **Commandes** — [poste] : listes copiées sous des noms courts, déposées, puis `forget.sh` (étape 5.6) ; une attente sur la base jusqu'à ce qu'aucune partie ne soit en cours :

```bash
cp <scratchpad>/vm/k6/.data/rooms-A-2026-09-28T16-20-49-674Z.txt  <scratchpad>/vm/charge/rooms-A-3.txt
cp <scratchpad>/vm/k6/.data/rooms-A2-2026-09-28T16-29-49-981Z.txt <scratchpad>/vm/charge/rooms-A2-1.txt
cp <scratchpad>/vm/k6/.data/rooms-B-2026-09-28T16-33-03-336Z.txt  <scratchpad>/vm/charge/rooms-B-1.txt
scp -i <clé> <scratchpad>/vm/charge/rooms-A-3.txt <scratchpad>/vm/charge/rooms-A2-1.txt <scratchpad>/vm/charge/rooms-B-1.txt vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/charge/
until ssh -i <clé> vagrant@192.168.10.10 'sudo bash /home/vagrant/tripleframes-repetition/outils/sqlp.sh "SELECT COUNT(*) FROM game WHERE status IN (\"running\",\"paused\");"' | tail -1 | grep -qx 0; do sleep 20; done
ssh -i <clé> vagrant@192.168.10.10 'bash -s rooms-A-3.txt rooms-A2-1.txt rooms-B-1.txt' < <scratchpad>/vm/charge/forget.sh
```

- **Attendu et vérification** : premier passage à 16:37 UTC (parties de A `interrupted` entre 16:36:22 et 16:36:56) : A → « serait oubliés : 60 », puis « oubliés : 60 » ; A2 → 2 et 2 ; **B → « Salons écartés parce qu'une partie y est encore en cours : 1. Relancez la commande une fois ces parties terminées. »**, 0 oublié, code 0 (sa partie était encore `paused`) ; second passage pour B à 16:48:53, après son interruption à 16:48:39 : 1, puis 1. Contrôle final (16:49 UTC) : **8 parties** (celles de la phase 4), **0 siège `k6-`**, 3 salons actifs, 17 films, 87 frames.
- **Sur le VPS Plesk :** les trois blocs de l'étape 5.6 (VPS), pour chaque phase jouée : `(umask 077 && mkdir -p ~/private/loadtest)` en SSH de l'abonnement ; `scp tests/Load/.data/rooms-<phase>-*.txt <utilisateur d'abonnement>@<VPS>:/var/www/vhosts/<DOMAINE>/private/loadtest/` depuis le poste ; `"$PHP" artisan loadtest:forget ~/private/loadtest/<fichier> --dry-run`, puis la même commande sans `--dry-run` (jamais `incoming/`, qui n'existe pas sur le VPS : correction du contrôle de la phase Charge) ; après un arrêt de k6 en pleine partie, attendre 15 minutes l'interruption des parties en pause **avant** `loadtest:forget` et **avant** le déploiement de rétablissement (une partie `paused` est « en cours » : `deploy:drain` l'attendrait).

### Étape 5.11 — Déploiement n° 7 : limiteurs rétablis (procédure § 11.4)

- **But** : rendre `room-create` et `room-join` à leurs valeurs par défaut (10 et 10) dès la fin de la séance, par un déploiement joué selon § 11.4, **avant toute vraie partie**.
- **Commandes** — [poste], dans le clone jetable : `git revert` du commit de séance, puis la chaîne de l'étape 5.7 à l'identique (numéro 7) ; [abo] : `deploy-drainer-deployer.sh` ; contrôles de l'étape 5.7 :

```bash
git -c user.name="répétition" -c user.email="repetition@tripleframes-prod.test" revert --no-edit HEAD
git diff --stat HEAD~2 HEAD                    # vide : la source redevient celle de l'artefact n° 5
export PATH="$(cygpath -u '<scratchpad>')/vm/build/php-stub:$PATH"
cp public/build/manifest.json ../manifest-6.json && npm run build && cmp -s public/build/manifest.json ../manifest-6.json && echo "manifest identique au build n° 6"
bash -s < <scratchpad>/vm/deploiement/rv10-artifacts.sh
git diff --stat deploy~1 deploy                # config/game.php seul
git diff --stat 9e5ed5a deploy                 # vide : même arbre que le déploiement n° 5
git bundle create ../deploy-7.bundle deploy
scp -i <clé> ../deploy-7.bundle vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/deploy-7.bundle
ssh -i <clé> vagrant@192.168.10.10 'bash -s 7' < <scratchpad>/vm/charge/deploy-tirer.sh
ssh -i <clé> vagrant@192.168.10.10 'sudo -u tripleframes -H bash -s' < <scratchpad>/vm/charge/deploy-drainer-deployer.sh
```

  Puis, [root], témoins des services existants (aucune écriture chez les voisins : fichiers statiques seulement ; `<scratchpad>/vm/charge/c-voisins-apres.sh`) :

```bash
# [root] Fin de phase Charge : services existants de la VM intacts (aucune écriture chez les voisins : fichiers statiques seulement).
for u in php8.1-fpm php8.3-fpm php8.4-fpm nginx mysql redis-server supervisor; do
    printf '%-14s %s MainPID=%s depuis %s\n' "$u" "$(systemctl is-active $u)" "$(systemctl show -p MainPID --value $u)" "$(systemctl show -p ActiveEnterTimestamp --value $u)"
done
redis-cli -p 6379 info server | grep -E '^process_id:'; redis-cli -p 6379 info keyspace | grep -E '^db'
for s in atomsdle bourse cashback checkout coaster mib plugins satisfactorydle votes; do
    printf '%-16s favicon %s\n' "$s.test" "$(curl -sk -o /dev/null -m 10 -w '%{http_code}' --resolve $s.test:443:127.0.0.1 https://$s.test/favicon.ico)"
done
curl -s -o /dev/null -m 10 -w 'défaut http %{http_code}\n' http://127.0.0.1/robots.txt
echo | openssl s_client -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -subject
uptime; free -m | sed -n 2,3p
```

- **Attendu et vérification** (16:37-16:50 UTC) : commit source `584ecdc` (revert), `git diff HEAD~2 HEAD` vide, manifest identique, `Verify Build Contents : OK` ; « Publié : deploy `7e4f04b`…, source `584ecdc`… », différence avec `7e5736f` limitée à `config/game.php`, **aucune différence d'arbre avec `9e5ed5a`** (déploiement n° 5) ; « bundle vérifié ». Déploiement 16:49:07 → 16:49:31 : fenêtre libre ouverte (aucune partie en cours), `deploy:drain` 0, `deploy:guard` 0, hook `[1/12]` à `[12/12]` code 0, `deploy:guard` 2 et « rien à lever » ensuite, plus de session `tmux`, caches en `640` (`packages.php`, `services.php` en `750`) ; checkout `7e4f04b` sans modification locale ; **`config:show game.room` → 10 et 10** ; cinq sondes `ok`, `/up` 200, battements frais, Redis dédié 1,1 Mo. Services existants : `php8.1-fpm`, `php8.3-fpm`, `php8.4-fpm`, nginx, MySQL, `redis-server`, supervisor **actifs depuis le 23/09, PID inchangés** (735, 736, 737, 973, 870, 743, 749) ; Redis partagé : 22 clés (inchangé) ; les 9 sites `*.test` servent leur `favicon.ico` en 200 ; serveur par défaut inchangé (`atomsdle.test`) ; machine au repos (`load1` 0,48, 2 362 Mo disponibles).
- **Sur le VPS Plesk :** `git revert` du commit de séance **sur `main`** (constantes rendues à 10 et 10, `RoomRateLimitsTest` vert), `composer ci:check` sur le poste (même réserve qu'à l'étape 5.7), poussé, les deux workflows verts, artefact publié (ligne « Publié : deploy … » du job `artifacts`), puis § 11.4 à la lettre ; contrôle `config:show game.room` → 10 et 10 en SSH de l'abonnement. La première vraie partie n'a lieu qu'**après** ce déploiement.

### Étape 5.12 — Relevé de la séance, **à titre indicatif (VM)** — jamais reporté dans `100` § 16.6

- **Commandes** — [root] : débit des soumissions lu dans le journal du vhost (`<scratchpad>/vm/charge/debit.sh`, des nombres seulement) :

```bash
# [root] Soumissions par seconde (POST /seat/<id>/answer|choice) dans le journal du vhost, par fenêtre : moyenne sur les secondes actives, p95, max.
L=/var/www/vhosts/system/tripleframes-prod.test/logs/access_ssl_log
for w in "A-2 15:45:51 15:57:47" "A-3 16:14:22 16:20:49" "A2-1 16:23:58 16:29:50" "B-1 16:30:29 16:33:03"; do
    set -- $w
    awk -v a="$2" -v b="$3" '$6=="\"POST" && $7 ~ /^\/seat\/[^\/]+\/(answer|choice)$/ { t=substr($4,14,8); if (t>=a && t<=b) c[t]++ }
        END { n=0; for (k in c) { v[++n]=c[k]; s+=c[k] }
              for (i=2;i<=n;i++){x=v[i];j=i-1;while(j>0&&v[j]>x){v[j+1]=v[j];j--}v[j+1]=x}
              i95=int(n*0.95+0.999); if(i95<1)i95=1;
              printf "%-5s secondes actives=%d total=%d moyenne=%.1f/s p95=%d/s max=%d/s\n", lab, n, s, (n?s/n:0), v[i95], v[n] }' lab="$1" "$L"
done
```

- **Tableau** (forme de `100` § 16.6 ; VM Homestead 2 vCPU Ryzen 7 5700X, 3,9 Go, charge émise depuis le même hôte, le 28/09/2026) :

| Rubrique de § 16.6 | Répétition à 2 salons (`A-2`) | A, 20 salons (`A-3`) | A2 réduite, 2 salons pleins | B réduite, 1 salon plein |
| --- | --- | --- | --- | --- |
| Salons / joueurs simulés | 4 (2 vagues) / 15 | 60 créés, 20 joués / 150 | 2 / 24 | 1 / 12 |
| Soumissions par seconde atteintes (moyenne / p95 / max) | 7,1 / 14 / 23 | **42 / 62 / 67** avant l'arrêt | 23,7 / 28 / 36 | 58 / 64 / 70 |
| Latence des soumissions p95 / p99 | 154 / 233 ms | **2 049 / 3 324 ms** | **305** / 419 ms | 136 / 187 ms |
| Retard des frontières `tier.opened` p95 / max (k6) | 105 / 216 ms | **483** / 849 ms | **360** / 507 ms | 70 / 70 ms |
| `max listen queue` de PHP-FPM | 0 (non mesurable sur socket unix) | 0 (idem) | 0 (idem) | 0 (idem) |
| File réelle du socket (`ss -xl`, `Recv-Q` max) | non relevée | non relevée | 13 | 5 |
| TTFB du voisin témoin, vu de la VM (référence → pendant, p95) | 15,5 → 14,0 ms | 12,7 → **85 ms** (saturation) | 12,7 → 15,4 ms | 12,7 → **87 ms** |
| TTFB du voisin témoin, vu du poste (seuil 1,2 × réf.) | 15,1 ≤ 21 ms | **77 > 16 ms** | **18,9 > 16 ms** | **37,9 > 16 ms** |
| Nombre de processus `game` | 1 | 1 | 1 | 1 |
| Plafonds (`MemoryMax` / `CPUWeight`) | gabarits inchangés | idem | idem | idem |
| Part des 429 / 5xx | 36 % des soumissions / 0 | 12 % / 0 | 37 % / 0 | **81 % / 0** |
| Issue | **les deux critères tenus** | **saturation, arrêt à 6 min 27 s** : critères 1 et 2 en échec | critère 1 en échec (latence, frontières) | excédent refusé en 429 sans 5xx ; arrêt par le garde-fou après la rafale |

- **Plafonds cgroup observés** (maximum relevé toutes exécutions confondues, `memory.current` toutes les 5 s ; plafond du gabarit entre parenthèses) : worker `game` **49 Mo** (192 Mo), worker `default` 43 Mo (512 Mo), **Reverb 48 Mo avec 150 connexions `wss`** (256 Mo), Redis dédié 23 Mo de cgroup pour 2,2 Mo de données (320 Mo), pool `tripleframes` ≈ **102 Mo pour 12 processus** (aucun plafond : pool de l'abonnement) ; aucune unité relancée par son plafond, aucune OOM, aucun refus Redis ; processeur au plus : PHP-FPM **175 % d'un cœur**, MySQL 49 %, worker `game` 9 %, Redis 5 %, nginx 4 %, Reverb 2 %. **La mémoire n'est jamais la contrainte ; le processeur l'est.**
- **Sur le VPS Plesk :** le vrai tableau se remplit dans `100` § 16.6 après la séance sur le VPS, par le porteur ; ce tableau-ci n'y entre jamais. `debit.sh` s'y rejoue en root avec `L=/var/www/vhosts/system/<DOMAINE>/logs/<journal d'accès HTTPS de nginx>` (nom relevé par `ls`, comme pour `analyse.sh`, étape 5.5) et les fenêtres de la séance du VPS ; elles s'écrivent en **heure locale du serveur** (`substr($4,14,8)` lit l'heure de `$time_local`), identique à l'UTC seulement si `timedatectl` l'indique.

### Étape 5.13 — Ce que la répétition implique pour le VPS réel

- **Les deux critères sont mesurables avec l'outillage livré**, et la procédure complète tient sur la VM : référence des voisins, séance, arrêt propre par l'API de k6 (liste des salons et résumé écrits), relevé serveur, `loadtest:forget` (après l'échéance de 15 minutes des parties en pause), limiteurs relevés puis rétablis par deux déploiements § 11.4. Ce qui reste propre à la VM : enveloppe `hosts`/TLS, voisin témoin, garde-fou automatique.
- **Coût par requête, base de dimensionnement** : ≈ 20 ms de processeur par requête de jeu (PHP ≈ 15 ms, MySQL ≈ 4 ms) sur un cœur de bureau rapide ; compter **25 à 40 ms sur un vCPU de VPS** (fréquence plus basse, cœur partagé). Ordres de grandeur à la charge cible :
  - **A** (150 joueurs) : ≥ 2 vCPU pleins au démarrage des parties sur la VM (saturée), ≈ 3 vCPU extrapolés de la répétition à 2 salons (0,3 vCPU pour 15 joueurs en jeu) ; pour tenir avec de la marge, **au moins 4 vCPU réellement disponibles pour TripleFrames**, voisins en plus ;
  - **A2** (240 soumissions par seconde, ≈ 290 requêtes par seconde avec images et battements) : ≈ 6 vCPU de ce type, **8 vCPU ou plus** ;
  - **B** (1 200 requêtes par seconde) : ≈ 15 à 18 vCPU rien que pour refuser en 429 à travers Laravel : **infaisable sur un VPS partagé** ; la protection **en amont de PHP** prévue par `100` § 16.3 (limitation nginx par adresse sur `/seat/`, à décider par `50`) est à poser **avant** la première partie, quelle que soit la taille du VPS.
- **Si le VPS a moins de ressources que ces ordres de grandeur** : A saturera comme ici ; réactions écrites d'avance (`100` § 16.4) — resserrer `pm.max_children` (voir ci-dessous) pour protéger les voisins, et **abaisser la charge cible** (plafond global de parties simultanées, point 13 du J2 de `100`, avancé si nécessaire) ; **s'il en a plus** : A doit tenir, A2 se joue, et B décide de la protection amont.
- **`pm.max_children = 12`, levier du critère 2 (le seul éprouvé ici)** : les 12 processus du pool ont pris jusqu'à 175 % d'un cœur et fait passer le voisin témoin de 13 à 85 ms. Sous Plesk, pas de `CPUWeight` systemd propre au pool, puisque les pools de même version de PHP partagent `plesk-php84-fpm`. Leviers à relever au VPS : `pm.max_children`, `process.priority` du pool (directives FPM supplémentaires) et, s'il est disponible, le composant cgroups de Plesk (limites par abonnement). (`process.priority` : priorité `nice` des processus du pool, appliquée seulement si le master tourne en root, ce qui est le cas de `plesk-php84-fpm` ; posée par la section `[php-fpm-pool-settings]` des directives PHP supplémentaires, même chemin que `pm.status_path`, `ops/plesk/settings.md` § 3, si Plesk l'accepte. Correction du contrôle de la phase Charge : cette ligne disait « seul `pm.max_children` borne TripleFrames », ce qui aurait écarté ces deux options avant le relevé du VPS.) Règle de départ proposée : 2 à 3 processus par vCPU que l'on accepte de céder à TripleFrames, puis mesurer.
- **La file PHP-FPM de § 16.4 ne se lit pas sur la page de statut** avec le socket unix de Plesk (`listen queue` toujours 0 sous Linux) : lire `Recv-Q` de `ss -xlnH` sur `/var/www/vhosts/system/<DOMAINE>/php-fpm.sock` (root), ou `max children reached` qui croît.
- **Frontières en retard sous contention** : le worker `game` (`CPUWeight=80`, `Nice=5`) passe après PHP-FPM (poids 100) quand le processeur manque : p95 des frontières 483 à 512 ms en A saturé, 360 ms en A2 réduite. Et le recyclage horaire du worker (`--max-time=3600`, `RestartSec=2`) laisse la file `game` sans consommateur ≈ 2,2 s par heure. Deux points à trancher dans `100` § 10.4 avant la séance du VPS (« Écarts à reporter »).
- **Entrées-sorties** : chaque requête écrit sa session en base (`SESSION_DRIVER=database`) ; sur un VPS à disque SSD local l'effet sera moindre que sur le disque virtuel de la VM, mais `vmstat 5` (colonne `wa`) est à regarder pendant B.
- **Mémoire** : aucun plafond approché (Reverb 48 Mo pour 150 `wss`, workers ≤ 49 Mo, Redis 2 Mo de données) : les plafonds des gabarits peuvent rester tels quels, ou être abaissés si le VPS est juste en mémoire.
- **Heure et voisins** : sur la VM, la séance a gêné les voisins pendant ≈ 2 minutes (saturation) ; sur le VPS, prévenir les propriétaires des sites voisins, choisir une heure creuse, garder la main sur `Ctrl+C`.
- **Sur le VPS Plesk :** cette étape entière est à relire **avant** la séance du VPS (étape 131 du REPRISE), avec le relevé de `nproc`, de la RAM et du type de processeur : si le VPS est en deçà des ordres de grandeur ci-dessus, abaisser `pm.max_children` et la charge cible **avant** de lancer A, et préparer la protection en amont de PHP (écart n° 33) avant B.

### Étape 5.14 — Contrôle « 0 secret » des documents de la phase

- **But** : étape 4.15 : aucun secret dans ce qui quitte la VM (runbook, journal des écarts, scripts, échantillons, journaux k6, résumés, listes de salons), comparaison faite **sur la VM**.
- **Commandes** — [poste], Git Bash :

```bash
for f in docs/ops/repetition-vm.md <scratchpad>/journal-ecarts-impl.md <scratchpad>/vm/charge/* <scratchpad>/vm/k6/*.js <scratchpad>/vm/k6/.data/*; do
    [ -f "$f" ] || continue
    printf '%s : ' "${f##*/}"
    ssh -i <clé> vagrant@192.168.10.10 'sudo bash /home/vagrant/tripleframes-repetition/outils/compte-secrets.sh' < "$f"
done
ssh -i <clé> vagrant@192.168.10.10 'ls /dev/shm | grep -c tf- || true'
```

- **Attendu et vérification** (16:58 UTC) : **76 fichiers, 0 ligne fautive** (8 valeurs comparées chaque fois), 0 fichier `tf-*` resté dans `/dev/shm`. Les résumés k6 ne portent pas les cookies des hôtes simulés (`handleSummary` retire `setup_data`) ; les journaux de console ne portent que des codes de salon.
- **Sur le VPS Plesk :** même contrôle, script posé en root sur le VPS (étape 4.15), pour les fichiers `tests/Load/.data/` du poste avant tout partage (ils restent ignorés par git).

## Phase 6 — Clôture : correctifs des anomalies du produit (BUG-P1, BUG-P2)

Anomalies de `vm/bugs.md` (phase 4, étapes 4.11 et 4.13) : deux défauts **du produit**, confirmés chacun par un test qui échoue, corrigés à la racine, commités sur `develop` (jamais poussés), puis redéployés sur la VM par la procédure complète du § 11.4 et vérifiés dans le navigateur. Les trois « observations sans défaut » de `bugs.md` (même œuvre tirée deux fois sur le catalogue de démonstration, logo TMDB absent, `game-<jour>.log` en `0644`) ne sont pas des défauts du produit et ne sont pas traitées ici.

### Étape 6.1 — Correctifs dans le dépôt, tests d'abord (poste)

- **But** : BUG-P1 — en solo, une saisie qui clôt la manche (bonne réponse, clic faux, tentatives épuisées) n'était suivie d'aucune lecture de `solo.state` : la manche close restait affichée, chrono compris, jusqu'au prochain instant de sondage de l'ancien paquet, souvent la garde de la manche suivante, dont le paquet ne porte plus la révélation. BUG-P2 — « Lancer la partie », « Rejouer » et la relance solo sont désactivés tant que la prop partagée `maintenance` vaut vrai ; le geste désactivé était la seule requête qui l'aurait relue, et la page restait bloquée après la levée du drapeau.
- **Correctifs** :
  - BUG-P1 (`resources/js/lib/game/store.ts`, `applySubmission`) : en solo, un verdict qui clôt la saisie (`locked`, `qcm_wrong`, `attempts_exhausted`) ou un 409 `closed` déclenche aussitôt une resynchronisation (`solo_poll`) ; le paquet relu porte la phase `closed` et `nextTransitionAt` = début de la révélation, relu à son tour. Rien ne change en multijoueur (`round.closed` et `round.revealed` le disent). `60` § 16.4 amendé (« une soumission de réponse compte comme un geste »).
  - BUG-P2 (`resources/js/lib/game/maintenance-refresh.ts`, `resources/js/hooks/game/use-maintenance-refresh.ts`, montés par `pages/game/lobby.tsx` et `pages/game/solo.tsx`) : tant que la page affiche le drapeau **et** que l'onglet tient le siège, rechargement partiel de la seule prop (`only: ['maintenance']`) toutes les `heartbeatIntervalMs` (prop `realtime`, 10 s) et au réveil de l'onglet ; aucune relecture au montage, aucune ne chevauche la précédente. Le rendu partiel présente `X-Seat-Token` (aucun jeton frappé) et ne reconstruit ni le paquet ni les réglages (props en fermetures). `50` § 8.1 et § 14, `60` § 16.4 amendés. Le refus serveur reste la seule garantie.
- **Commandes** — [poste], Git Bash, racine du dépôt :

```bash
# 1. Tests écrits d'abord, en échec sur le code d'avant :
npx vp test run tests/Frontend/game/store.test.ts               # 2 échecs : « expected [] to deeply equal [ 'solo_poll' ] »
npx vp test run tests/Frontend/game/maintenance-refresh.test.ts # échec : module lib/game/maintenance-refresh introuvable
# 2. Correctifs, puis :
npx vp test run tests/Frontend/game/store.test.ts tests/Frontend/game/maintenance-refresh.test.ts
php artisan test tests/Feature/Deploy/MaintenanceBannerTest.php
# 3. composer ci:check sur un arbre propre (HEAD + les seuls fichiers du correctif) : voir l'écart ci-dessous.
git worktree add --detach <scratchpad>/wt-cloture HEAD
#    copie des 12 fichiers du correctif, de vendor/ et node_modules/, du .env de développement ; puis dans la copie :
php artisan wayfinder:generate --with-form && npm run build && composer ci:check
# 4. Deux commits, fichiers nommés, jamais « git add -A » :
git commit -F <message BUG-P1> -- resources/js/lib/game/store.ts resources/js/hooks/game/use-solo-state.ts \
    tests/Frontend/game/store.test.ts docs/specs/60-moteur-de-partie-temps-reel-et-mode-solo.md
git add -- resources/js/lib/game/maintenance-refresh.ts resources/js/hooks/game/use-maintenance-refresh.ts \
    tests/Frontend/game/maintenance-refresh.test.ts
git commit -F <message BUG-P2> -- resources/js/lib/game/maintenance-refresh.ts resources/js/hooks/game/use-maintenance-refresh.ts \
    tests/Frontend/game/maintenance-refresh.test.ts resources/js/hooks/game/use-heartbeat.ts resources/js/pages/game/lobby.tsx \
    resources/js/pages/game/solo.tsx tests/Feature/Deploy/MaintenanceBannerTest.php \
    docs/specs/50-salon-reglages-presets-et-lobby.md docs/specs/60-moteur-de-partie-temps-reel-et-mode-solo.md
```

- **Attendu et vérification** (18:00-18:33 UTC) : tests en échec avant, verts après ; `composer ci:check` **vert** sur l'arbre propre : oxfmt, oxlint, jetons de thème, `tsc`, Vitest 24 fichiers / 95 tests, Pint, PHPStan 0 erreur, Pest **4 385 tests, 4 385 réussis** ; contenu des 12 fichiers commités identique octet pour octet à l'arbre testé (`git show <commit>:<fichier> | cmp`). Commits `7996902` (`:bug: BUG-P1 — Solo : aucune révélation après une réponse qui clôt la manche`) et `9ed326c` (`:bug: BUG-P2 — Après la levée du drapeau de drainage, lancement, « Rejouer » et relance solo restent désactivés jusqu'à un rechargement`). Tests ajoutés : deux dans `store.test.ts` (calendrier de sondage complet après une bonne réponse : relecture immédiate, `closed`, révélation, manche suivante programmée pendant que la révélation reste montrée ; table des verdicts qui relisent ou non, multijoueur exclu), cinq dans `maintenance-refresh.test.ts`, un dans `MaintenanceBannerTest.php` (rechargement partiel réel de `room.show` : drapeau relu vrai puis faux, ni `state` ni `seatToken` ni réglages reconstruits, jeton d'onglet inchangé ; les deux pages montent la relecture).
- **Écart — `composer ci:check` rouge dans le dépôt du porteur, pour des raisons étrangères au correctif** : le porteur travaille en parallèle (capture d'écran de curation : `FramePolicy`, `routes/admin.php`, `config/catalog.php`, `.env.example`…, non commités) ; la suite jouée dans son arbre a rendu 6 échecs (`AuthorizationMatrixTest` sur `admin.catalog.frames.capture.store`, `CurationConfigTest`, `FrameBankTest` `captureEnabled`, `DemoCatalogueChainTest`) et 5 erreurs de disque de test (`storage/framework/testing/disks/frames` : répertoires disparus pendant la lecture, suite jouée en même temps ailleurs). Levée : `ci:check` rejoué dans un arbre de travail jetable (`git worktree`) = `HEAD` + les seuls fichiers du correctif. Deux faux départs dans cet arbre, consignés : Wayfinder généré sans `--with-form` (erreurs `tsc` « Property 'form' does not exist »), puis suite sans `public/build/manifest.json` ni `.env` de développement (`ViteManifestNotFoundException`, `deploy.window_minutes` en chaîne vide) — la CI construit les assets avant les tests ; rejoué avec `npm run build` et le `.env` du poste (base de test SQLite en mémoire par `phpunit.xml`, aucune base touchée). Arbre supprimé ensuite (`Remove-Item -LiteralPath '\\?\…'` : `git worktree remove` échoue sur « Filename too long »), `git worktree prune`.
- **Sur le VPS Plesk :** rien à taper pour les correctifs eux-mêmes : ils arrivent par le chemin normal (`main` › workflows verts › job `artifacts` « Publié : deploy … » › Plesk « Tirer » › procédure du § 11.4). Un seul geste de contrôle, sur le téléphone du porteur : étapes 6.4 et 6.5.

### Étape 6.2 — Artefact n° 8 et déploiement par la procédure du § 11.4

- **But** : porter les deux correctifs sur la production de la VM par le chemin réel (artefact, « Tirer », drainage, garde, « Déployer », hook, levée).
- **Commandes** — [poste], Git Bash, dans le clone jetable (`<scratchpad>/vm/build/src`, jamais le dépôt du porteur) : les deux commits repris au-dessus de `584ecdc`, puis construction et publication (blocs des étapes 2.3, 2.4 et 5.7) :

```bash
git fetch -q "C:/Users/Admin/Desktop/groupez/tripleframes" develop:refs/remotes/porteur/develop
git -c user.name="répétition" -c user.email="repetition@tripleframes-prod.test" cherry-pick 7996902 9ed326c
export PATH="$(cygpath -u '<scratchpad>')/vm/build/php-stub:$PATH"; cmd //c "where php"   # le stub en tête
cp public/build/manifest.json ../manifest-7.json
npm run build
cmp -s public/build/manifest.json ../manifest-7.json && echo "manifest identique au build n° 7" || echo "manifest différent du build n° 7"
refused="$(find public/build \( -iname '*.php' -o -iname '*.php[0-9]' -o -iname '*.phtml' -o -iname '*.pht' -o -iname '*.phps' -o -iname '*.phar' -o -name '.*' \) -print)"
[ -z "$refused" ] && echo "Verify Build Contents : OK" || echo "REFUSÉS : $refused"
bash -s < <scratchpad>/vm/deploiement/rv10-artifacts.sh
git diff --stat deploy~1 deploy
git bundle create ../deploy-8.bundle deploy
scp -i <clé> ../deploy-8.bundle vagrant@192.168.10.10:/home/vagrant/tripleframes-repetition/deploy-8.bundle
ssh -i <clé> vagrant@192.168.10.10 'bash -s 8' < <scratchpad>/vm/charge/deploy-tirer.sh
```

  Puis [root], lecture seule : `SELECT status, COUNT(*) n FROM game GROUP BY status` par `sqlp.sh` (aucune partie `running` ni `paused`) ; enfin [abo], forme détachée de l'étape 5.7 : `ssh -i <clé> vagrant@192.168.10.10 'sudo -u tripleframes -H bash -s' < <scratchpad>/vm/charge/deploy-drainer-deployer.sh`.
- **Attendu et vérification** (18:33-18:35 UTC) : `cherry-pick` sans conflit (`58c26da`, `a8c93d6`) ; build réussi, **manifest différent** du build n° 7 (53 fichiers d'assets renommés), `Verify Build Contents : OK` ; « Publié : deploy `3102d55`…, source `a8c93d6`… » ; « bundle vérifié », `deploy` = `3102d55` au-dessus de `7e4f04b` ; aucune partie en cours (10 `completed`) ; déploiement de 18:34:20 à 18:34:43 : `deploy:drain code=0` (fenêtre libre aussitôt), `deploy:guard code=0`, checkout `3102d55`, hook `[1/12]` à `[12/12]` code 0 (« Drapeau de drainage levé »), `deploy:guard` 2, `deploy:release` « rien à lever », caches de démarrage en `640` (`packages.php`, `services.php` en `750`), workers et Reverb relancés.
- **Écart — double exécution involontaire** : une commande de contrôle mal écrite a relancé `deploy-drainer-deployer.sh` en arrière-plan (18:34:51-18:35:18) : second drainage, fenêtre libre à 18:35:06, même checkout `3102d55`, hook rejoué, drapeau levé (`deploy:release` : « Aucun drapeau »), `tmux` fermé, unités relancées à 18:35:14-18. Sans effet (même artefact, hook idempotent, aucune partie en cours), mais c'est un déploiement de plus que prévu : ne jamais lancer ce script depuis une commande de lecture.
- **Sur le VPS Plesk :** procédure du § 11.4 telle que décrite à l'étape 4.13 ; « Déployer » est le **bouton de Plesk**, jamais un `git checkout` tapé dans une session ouverte par `umask 027` (étape 6.3).

### Étape 6.3 — Rattrapage : assets neufs refusés en 403 (fichiers de `public/` nés en `640`)

- **Constat** (18:35:45 UTC, premier chargement de `/solo` après le déploiement) : page blanche ; `GET /build/assets/app-C9TFqL_k.js`, `solo-…js`, `use-round-stage-…js`… → **403**. `ls -l` : 53 fichiers `-rw-r----- tripleframes:tripleframes` (les 53 assets neufs du build n° 8) à côté de 60 fichiers `-rw-rw-r--` (inchangés depuis le build n° 5) ; nginx tourne sous `vagrant`, « autre » pour ces fichiers.
- **Cause** : la répétition émule « Déployer » par `git checkout -f deploy` **dans la session [abo] ouverte par `umask 027`** (RV-10, étapes 4.13 et 5.7) : tout fichier que le checkout crée naît en `640`, alors que `public/` doit être lisible du serveur web. Les déploiements n° 5 à 7 ne l'ont pas montré : leur manifest était identique (aucun asset créé). Le hook n'est pas en cause (son `umask 027` ne crée rien sous `public/`, comme son commentaire le dit).
- **Commandes** — [abo] (`ssh -i <clé> vagrant@192.168.10.10 'sudo -u tripleframes -H bash -s' < <scratchpad>/vm/cloture/public-lisible.sh`) :

```bash
# [abo] Rattrapage (répétition) : fichiers de public/ nés en 640 par un « Déployer » émulé sous umask 027.
# Seul public/ est touché : bootstrap/cache et storage restent en 640 (RV-10).
set -euo pipefail
cd ~/tripleframes
echo "avant : $(find public -type f ! -perm -o=r | wc -l) fichier(s), $(find public -type d ! -perm -o=x | wc -l) répertoire(s) illisibles des autres comptes"
find public -type f ! -perm -o=r -exec chmod o+r {} +
find public -type d ! -perm -o=x -exec chmod o+rx {} +
echo "après : $(find public -type f ! -perm -o=r | wc -l) fichier(s), $(find public -type d ! -perm -o=x | wc -l) répertoire(s)"
stat -c '%a %U:%G %n' public/build/manifest.json bootstrap/cache/config.php
```

  Puis [vagrant] : `curl -sk -o /dev/null -w '%{http_code}' --resolve tripleframes-prod.test:443:127.0.0.1 https://tripleframes-prod.test/build/assets/<fichier>` sur `app-*`, `solo-*`, `use-round-stage-*`.
- **Attendu et vérification** (18:36:50 UTC) : « avant : 54 fichier(s) » (53 assets et `manifest.json`), « après : 0 » ; `manifest.json` `644`, `config.php` toujours `640` ; les six assets contrôlés en 200 ; `/solo` rendu.
- **Correction pour la suite** : le « Déployer » émulé se tape désormais sous un sous-shell `umask 022` (comme le bouton de Plesk), le reste de la session gardant `027` ; forme corrigée dans `<scratchpad>/vm/charge/deploy-drainer-deployer.sh` (ancienne version gardée en `.avant-cloture`, `bash -n` OK) et dans les blocs des étapes 4.13 et 5.7 :

```bash
(umask 022 && git --git-dir=$HOME/git/tripleframes.git --work-tree=$HOME/tripleframes checkout -f deploy)
echo "public/ illisible des autres comptes : $(find $HOME/tripleframes/public ! -perm -o=r | wc -l)"   # 0
```

  Vérifié sur la VM dans un dépôt jetable (`mktemp -d`, [abo], session `umask 0027`) : fichier restauré par `checkout` en `644` sous le sous-shell, `640` sans lui. Non rejoué sur un déploiement réel (aucun artefact de plus).
- **Sur le VPS Plesk :** « Déployer » est fait par Plesk, hors de la session : pas de sous-shell à taper. **Contrôle à ajouter après chaque « Déployer »** (surtout le premier et tout déploiement qui change les assets) : `stat -c '%a %U:%G' public/build/manifest.json public/build/assets/* | sort | uniq -c` — tous lisibles du serveur web (`644`), et une page chargée sans 403 sur `/build/assets/`. Si Plesk déployait en `640` : ne **jamais** passer la session entière en `022` (les caches de démarrage redeviendraient lisibles) ; corriger `public/` seul par le bloc ci-dessus, puis ouvrir la question des permissions de déploiement de Plesk Git (écart n° 39).

### Étape 6.4 — Vérification de BUG-P1 dans le navigateur (solo)

- **But** : une saisie qui clôt la manche est suivie de la révélation, en bonne réponse comme en clic faux.
- **Commandes** — [poste] : trois Chrome headless (`<scratchpad>/vm/partie/launch-chrome.ps1`, PowerShell), puis Git Bash, `MSYS_NO_PATHCONV=1 node <scratchpad>/vm/driver/c1-solo-revelation.mjs` : navigateur **tiers** (fr), `/solo` › preset « Rapide » (N = 2, Facile, R = 5 s, M = 8) › « Commencer l'entraînement » ; manche 1 bon clic, manche 2 clic faux, manche 3 bon clic, le texte de la page relevé toutes les 250 ms ; manches 4 à 8 passées ; enregistrement réseau `vm/partie/logs/cloture-solo.jsonl`.
- **Attendu et vérification** (18:37:18-18:37:59 UTC ; partie solo n° 40, `completed` ; captures `cloture-p1-m{1,2,3}-*`) : « La réponse » (titre, titre original, année, « Ont trouvé ») affichée **≈ 0,5 s** après chacun des trois clics (506, 504, 505 ms, soit la deuxième lecture de 250 ms). Réseau, pour chaque manche : `POST /seat/…/choice` → 200 `accepted` (ou `rejected`) à +36-50 ms ; `GET /solo/state` **à +66-83 ms**, manche en phase `closed`, `nextTransitionAt` = début de la révélation ; `GET /solo/state` à +350 ms, phase `revealing` avec le film ; `GET` suivant à +3,3 s, manche suivante `scheduled` (garde de préchargement), la révélation restant montrée jusqu'à son terme. Avant correctif (phase 4) : aucune lecture avant +3,6 s, révélation jamais montrée.
- **Sur le VPS Plesk :** gestes de l'étape 4.11 sur le téléphone du porteur, après un déploiement portant `7996902` : bonne réponse, puis mauvaise proposition ; la révélation doit s'afficher à chaque fois, et le chrono s'arrêter dès la réponse.

### Étape 6.5 — Vérification de BUG-P2 dans le navigateur (drapeau levé sans rechargement)

- **But** : une page qui a vu le drapeau se débloque seule après sa levée, sans supplanter l'onglet ; la relance débloquée est admise.
- **Commandes** — [poste], Git Bash : `MSYS_NO_PATHCONV=1 node <scratchpad>/vm/driver/c2-drainage-releve.mjs` : **hôte** : salon neuf (`/r/new`, lobby) ; [abo] `deploy:drain` (aucune partie en cours : fenêtre libre, drapeau tenu) ; hôte : rechargement du lobby ; **tiers** : `/solo` (podium de la partie 40) ; relevé à +0 et +12 s ; [abo] `deploy:release` ; relevé toutes les 500 ms **sans rechargement** ; enregistrement `vm/partie/logs/cloture-drainage-tiers.jsonl`. Puis `MSYS_NO_PATHCONV=1 node <scratchpad>/vm/driver/c3-relance-sans-rechargement.mjs` : tiers, même onglet, preset « Rapide », « Commencer l'entraînement », manches passées jusqu'au podium.
- **Attendu et vérification** (18:38:15-18:39:47 UTC ; journal `vm/partie/logs/cloture-drainage.marks.jsonl`, captures `cloture-p2-*`) : salon `L7XW9V` ; `deploy:drain code=0` ; pendant le drainage, motif `common.maintenance.launch_blocked` affiché et boutons désactivés sur les deux pages, toujours à +12 s ; **après `deploy:release` : motif retiré du lobby en 6,0 s et relance solo active en 7,5 s, sans rechargement** (≤ `heartbeatIntervalMs` = 10 s) — « Lancer la partie » reste désactivé au lobby pour son seul autre motif (l'hôte est seul). Réseau du tiers : deux rechargements partiels `GET /solo` (XHR) à 10 s d'intervalle, props `errors` et `maintenance` seulement (`true`, puis `false`), puis plus aucun. Relance sans rechargement **admise** (partie solo n° 41, « Manche 1 sur 8 », puis `completed`) : l'onglet a gardé la main. `deploy:guard` 2 (aucun drapeau) ; 0 partie en cours à la fin.
- **Sur le VPS Plesk :** au prochain déploiement réel (§ 11.4) après `9ed326c`, laisser un onglet du porteur au podium solo pendant le drainage : le bouton se débloque dans les 10 s qui suivent `deploy:release` (étape 12 du hook), sans rechargement ; la note « demander aux joueurs présents de recharger » (écart n° 24) devient inutile.

### Étape 6.6 — État après la Clôture (1/2)

- **Commandes** — [root] : bloc de sondes de l'étape 3.1 (`ssh -i <clé> vagrant@192.168.10.10 'sudo bash -s' < <scratchpad>/vm/partie/vm/p-sondes.sh`) ; `systemctl is-active nginx mysql redis-server php8.4-fpm` ; [poste], PowerShell : fermeture des seuls Chrome de la répétition (processus `chrome.exe` dont la ligne de commande contient `scratchpad\vm\partie\profile-`).
- **Attendu et vérification** (18:40 UTC) : cinq unités `tripleframes-*` actives (workers 339084/339091, Reverb 339131, relancés par le second passage du hook ; Redis 261868 inchangé) ; `/up` 200 ; cinq sondes `ok`, 404 sans jeton ou jeton faux ; services existants de la VM actifs, non touchés ; 27 processus Chrome de la répétition fermés, aucun autre. Contrôle « 0 secret » de l'étape 5.14 (`compte-secrets.sh`, sur la VM) sur ce runbook, le journal des écarts, les pilotes `c*.mjs`, `public-lisible.sh`, `deploy-drainer-deployer.sh` et les enregistrements `cloture-*` : 14 fichiers, 0 ligne fautive, rien resté dans `/dev/shm`.
- **Sur le VPS Plesk :** bloc de sondes de l'étape 3.1 après tout déploiement.

### Étape 6.7 — Relecture de clôture (2/2) : runbook, gabarits `ops/`, CI

- **Joué** le 28/09, de 18:45 à 19:20 UTC. Sur la VM, **lecture seule** : relevé de l'état final, bloc « Vérifier » de l'« État final », sondes, contrôle « 0 secret » ; rien d'installé, d'arrêté ni de supprimé (la répétition reste en place, au choix du porteur).
- **But** : relire ce runbook de bout en bout comme le porteur qui le rejouera seul sur le VPS ; vérifier que `ops/` reflète ce qui a réellement fonctionné ; `composer ci:check` vert ; un commit des seuls fichiers de la répétition.
- **Runbook** : mode d'emploi en tête (« Pour rejouer sur le VPS ») ; conventions complétées (`<clé>`, `<scratchpad>`, sens de « identique », heures) ; sections nouvelles « Substitutions systématiques pour le VPS », « Prérequis du VPS Plesk », « Durées mesurées », « Ordre de rejeu sur le VPS » (l'ordre des phases de la VM n'est pas celui du VPS : curation avant le drainage, restauration après lui) ; « Écarts à reporter dans les specs et les gabarits » réorganisé (parties A à C ajoutées, constats numérotés gardés en D) ; « État final » réécrit (ce qui tourne, vérifier, arrêter, relancer, retour arrière réordonné et contrôlé, inventaire, états intermédiaires) ; ligne « Sur le VPS Plesk » ajoutée à l'étape 5.13 ; notations rendues exactes : `ssh -i <clé>` partout, `export PATH="$(cygpath -u '<scratchpad>')/vm/build/php-stub:$PATH"` au lieu d'un chemin élidé.
- **Gabarits `ops/`** : dix corrections (« Écarts à reporter », partie A), dont sept de cette relecture — `~/.config/tripleframes` créé à l'étape 2 et commande exacte de `hook.env` à l'étape 7 ; contrôle de lisibilité de `public/` après le premier et chaque « Déployer » ; `@@gtid_mode`, droits du HOME et témoin des voisins au relevé ; `PHP=` et `cd` en tête des blocs des étapes 3 et 5, relecture de la base avant `migrate` ; jeton des sondes par un fichier et non en argument de `curl` ; file réelle du pool par `ss -xlnH` (`ops/plesk/settings.md` § 3) ; motif de `Restart=always` dans l'unité Reverb ; s'y ajoutent des renvois vers ce runbook en tête de `ops/mise-en-service.md` et de `ops/plesk/settings.md`. Les trois autres (PHP 8.4, tampon fastcgi, `umask 027`) étaient déjà dans le dépôt, joués et prouvés aux phases 1 et 2.
- **Relevé de l'état final** — [root], lecture seule, `ssh -i <clé> vagrant@192.168.10.10 'sudo bash -s' < <scratchpad>/vm/cloture2-etat.sh` (unités, crontab, fichiers et utilisateurs, fichiers des comptes de la répétition hors de leurs racines, bases, paquet, ports, processus, `tmux`, checkout, services existants, tailles) : résultats dans « État final » ; seul reste imprévu, `/tmp/tmux-1001` (serveur `tmux` de `tripleframes`), désormais retiré par le retour arrière.
- **CI** — [poste], Git Bash, même méthode qu'à l'étape 6.1 (le porteur travaille en parallèle dans le dépôt : arbre de travail jetable = `HEAD` + les seuls fichiers de ce commit) :

```bash
git worktree add --detach <scratchpad>/wt-cloture2 HEAD
# copie des fichiers du commit (docs/ops/repetition-vm.md, docs/REPRISE.md, ops/mise-en-service.md,
# ops/plesk/settings.md, ops/systemd/tripleframes-reverb.service), de vendor/ et node_modules/ (robocopy), du .env du poste ;
# puis, dans l'arbre jetable :
php artisan wayfinder:generate --with-form && npm run build && composer ci:check
```

- **Attendu et vérification** (18:55 → 19:10 UTC) : `wayfinder:generate` et `npm run build` réussis ; `composer ci:check` **code 0** : oxfmt (296 fichiers, dont `ops/*.md`), oxlint (259 fichiers, aucun avertissement), jetons de thème, `tsc`, Vitest 24 fichiers / 95 tests, Pint, PHPStan 0 erreur, Pest **4 385 tests, 4 385 réussis** (101 341 assertions, dont `OpsTemplatesTest`, `DeployHookTest`, `NoLiteralDomainTest`). La dernière correction de `ops/mise-en-service.md` (jeton des sondes) et l'étape 6.7 elle-même, écrites pendant la suite, ont été recopiées ensuite dans l'arbre jetable et contrôlées seules : `npx vp fmt --check ops` (« All matched files use the correct format », 2 fichiers Markdown), puis `php artisan test --compact tests/Feature/Deploy/OpsTemplatesTest.php tests/Feature/Deploy/DeployHookTest.php tests/Feature/Architecture/NoLiteralDomainTest.php` : 11 tests verts, 1 574 assertions. `docs/**` n'est soumis à aucun contrôle de forme (exclu d'oxfmt). Arbre jetable supprimé ensuite, `git worktree prune`.
- **Contrôle « 0 secret »** (étape 4.15, sur la VM) : `compte-secrets.sh` sur ce runbook, les quatre fichiers `ops/` modifiés, `docs/REPRISE.md`, le journal des écarts et les fichiers `cloture2-*` du poste : **0 ligne fautive** (8 valeurs comparées chaque fois), rien resté dans `/dev/shm`.
- **Sur le VPS Plesk :** rien à taper : les gabarits corrigés arrivent par `main` (commit « gabarits ajustés au relevé » compris) ; relire les parties A à C des « Écarts à reporter » avant le relevé du VPS.

## Impasses rencontrées et contournements

- **PHP bloqué sur le poste** (relevé du 28/09) : `php.exe` est refusé par la stratégie de contrôle d'application de Windows (Device Guard). Or la construction des assets appelle `php artisan wayfinder:generate`. Contournement prévu : l'étape PHP du « runner CI » (composer, `wayfinder:generate`) se joue dans un répertoire jetable de la VM, l'étape Node (`npm ci`, `npm run build`) sur le poste. À lever sur le poste du porteur, qui ne peut plus lancer `composer dev` ni les tests non plus. En phase 1, les tests des gabarits (étape 1.1) ont été joués dans la VM, sur un clone jetable, pour la même raison. **Levé depuis** : à la Clôture (18:00 UTC puis 18:45), `php` (8.4.16) et `composer` s'exécutaient de nouveau sur le poste, et `composer ci:check` y a tourné (étapes 6.1 et 6.7). Le blocage étant venu de la stratégie de Windows, vérifier `php -v` avant tout geste qui en dépend (construction des assets, `composer ci:check` avant une poussée).
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
- **Pilote : rechargements involontaires** (phase 4, étape 4.8) : l'émulation `isMobile`/`hasTouch` de puppeteer recharge la page à chaque connexion CDP, donc supplante l'onglet (`seat.superseded`). Levée : `setViewport({ width: 390, height: 844 })` seul. Chrome headless sous Windows refuse une fenêtre de moins de 500 px de large.
- **Pilote : anti-spam** (phase 4, étape 4.9) : deux saisies à 0,6 s d'intervalle → `429 One attempt at a time.` (`attemptsPerSecond` = 1) : comportement attendu du produit ; le pilote attend 1,1 s entre deux saisies d'un même joueur.
- **Pilote : onglets d'arrière-plan et visites Inertia** (phase 4, étape 4.13) : `page.click()` attend sans fin dans un onglet d'arrière-plan (plus aucune image rendue) → `bringToFront()` avant chaque geste ; la création d'un salon est une visite Inertia (XHR + `pushState`) → attendre l'adresse `/r/<code>`, jamais `waitForNavigation`.
- **Salon épuisé par sa mémoire** (phase 4, étape 4.13) : après 16 films joués sur 17, le salon `V5GACS` refuse tout lancement (« Films jouables : 1 pour 3 manches ») et propose d'en créer un nouveau : comportement attendu (`noRepeatMovies`), nouveau salon créé.
- **Commit d'activation du drainage incomplet dans le clone jetable** (phase 4, étape 4.12) : la moitié « test » (`deployHookDrainSteps()` → `[]`) a été refusée par le garde-fou de permissions de la session (modification d'un fichier de test) ; seule la moitié « hook » est livrée par l'artefact n° 5, ce qui suffit au comportement déployé. Le vrai commit d'activation (étape 126) porte les deux.
- **Contrôles `tinker`/SQL depuis le poste** (phase 4) : le pilote lit la base par `ssh … 'sudo bash …/sqlp.sh "$(cat)"'`, la requête passant par l'entrée standard (aucun guillemet à échapper, aucun secret en argument).
- **Contrôle « 0 secret » fait sur le poste** (phase 4, relevé par le contrôle) : pour vérifier l'absence de secrets dans le runbook, l'exécutant a rapatrié leurs valeurs dans un fichier temporaire du poste (`C:\Users\Admin\AppData\Local\Temp\secrets.<pid>`, hors du scratchpad), supprimé aussitôt : contraire à la règle « secrets stockés uniquement sur la VM ». Levée (étape 4.15) : la comparaison se fait **sur la VM**, le fichier à contrôler envoyé sur l'entrée standard d'un script root (`compte-secrets.sh`) qui lit les valeurs dans le `.env`, `backup.env`, `tripleframes-redis.conf` et la clé `age`, les place en `/dev/shm` (`0600`, supprimé à la sortie) et ne rend que deux nombres. Au passage : `REVERB_APP_KEY` est publique (URL `wss://…/app/<clé>`), à exclure de la liste sous peine de faux positifs dans tout enregistrement de navigateur.
- **k6 et les noms `.test`** (phase 5, étape 5.1) : k6 n'a ni option de ligne de commande ni variable pour la résolution de noms, et le poste ne résout pas les `.test` ; la CA de Homestead n'est pas approuvée par Windows. Levée (répétition seulement) : enveloppe `enveloppe.js` qui réexporte le scénario tel quel avec `hosts` et `insecureSkipTLSVerify` (valables aussi pour `k6/websockets` : les `wss` passent). Le scénario lit `./.data/titles.txt` à côté de lui : copie du scénario dans le scratchpad, rien dans `tests/Load/.data/` du dépôt.
- **Garde-fou trop sensible au swap** (phase 5, étapes 5.3 à 5.5) : deux faux positifs (vidange de 117 Mo de pages froides au repos ; 45 pages relues au lancement des parties) ; le second a arrêté la première répétition à deux salons au bout de 82 s. Levée : seuil en débit (256 pages par relevé, trois relevés de suite) et « swap + 100 Mo » seulement sous 20 % de mémoire disponible ; répétition rejouée (`A-2`).
- **Script remplacé pendant qu'il tournait** (phase 5) : `scp` réécrit le fichier en place, et `bash` lit un script au fil de l'eau ; un échantillonneur resté orphelin sur la VM après l'arrêt de sa veille. Levée : ne recopier l'échantillonneur qu'entre deux exécutions ; arrêt de l'orphelin par `sudo pkill -f "^bash /home/vagrant/…/echantillonneur.sh <étiquette>"` — **motif ancré** : `pkill -f "echantillonneur.sh A-1"` sans ancre tue aussi le `bash -c` de la session `ssh` qui le tape (code 255).
- **`listen queue` de PHP-FPM toujours à 0** (phase 5, étape 5.8) : sur un socket unix, la page de statut ne mesure pas la file sous Linux (`listen queue len: 0`) ; la file réelle se lit par `ss -xlnH` (`Recv-Q` du socket en écoute) : jusqu'à 13 connexions en attente en A2 réduite pendant que la page de statut affichait 0.
- **Parties « en cours » après un arrêt de k6** (phase 5) : les parties coupées passent en `paused` et le restent 15 minutes (`pause_timeout_ms`) ; `loadtest:forget` les écarte (« Relancez la commande une fois ces parties terminées ») et `deploy:drain` les attendrait. Levée : attente sur la base (`until … COUNT(*) … IN ('running','paused') = 0`), puis nettoyage et déploiement de rétablissement.
- **`ci:check` rouge dans le dépôt du porteur** (phase 6, étape 6.1) : travaux du porteur en cours dans le même arbre (non commités) et suite jouée ailleurs en même temps (disque de test `storage/framework/testing/disks/frames` partagé). Levée : `git worktree` jetable = `HEAD` + les seuls fichiers du correctif, avec `vendor/`, `node_modules/`, `.env` du poste, `wayfinder:generate --with-form` et `npm run build` (la CI construit les assets avant les tests) ; suppression par `Remove-Item -LiteralPath '\?…'` (chemins trop longs pour `git worktree remove`), puis `git worktree prune`.
- **Assets neufs en 403 après le déploiement n° 8** (phase 6, étape 6.3) : « Déployer » émulé par un `checkout` sous `umask 027` ; levée par `chmod o+r` sur `public/` seul et checkout sous sous-shell `umask 022` ensuite (écart n° 39).
- **Script de déploiement relancé par erreur** (phase 6, étape 6.2) : une commande de lecture qui recopiait la ligne `ssh … < deploy-drainer-deployer.sh` l'a rejoué en arrière-plan ; sans effet (même artefact), mais à proscrire : lire `~/drain.log` et l'état par des commandes de lecture seules.

## Écarts à reporter dans les specs et les gabarits

Ce que la répétition a appris, en quatre parties : **A**, défauts des gabarits `ops/` corrigés (déjà dans le dépôt) ; **B**, valeurs à ajuster au relevé du VPS ; **C**, décisions à prendre par le porteur ; **D**, constats détaillés et numérotés (les « écarts n° X » cités par les étapes), chacun avec la spec propriétaire à amender. Le runbook ne décide rien : un constat de D n'entre dans une spec que par le report du porteur.

### A. Gabarits `ops/` corrigés par la répétition

| Défaut constaté | Correction | Fichiers | Étape, écart |
| --- | --- | --- | --- |
| PHP 8.3 dans le hook, les unités, la liste et les réglages, alors que le verrou exige PHP 8.4.1 | `/opt/plesk/php/8.4/bin/php`, « Version : 8.4 » | `ops/deploy/hook.sh`, `ops/systemd/tripleframes-{reverb,worker@}.service`, `ops/mise-en-service.md`, `ops/plesk/settings.md` (et `DeployHookTest`, `OpsTemplatesTest`, `tests/Load/game-load.js`) | 1.1, n° 1 |
| `/login` en 502 : en-têtes de PHP-FPM au-delà du tampon de 4 Ko de nginx | `fastcgi_buffer_size 32k;`, `fastcgi_buffers 16 16k;` ; contrôle `/login` → 200 | `ops/nginx/additional-directives.conf`, `ops/mise-en-service.md` (étape 5) | 2.10, n° 9 |
| `bootstrap/cache/config.php`, copie de tous les secrets, né lisible des autres comptes | `umask 027` en tête du hook et de toute session de l'abonnement ; `UMask=0027` des deux unités PHP ; contrôle `stat` → `640` | `ops/deploy/hook.sh`, les deux unités, `ops/mise-en-service.md` (étapes 3 et 5) | 2.18, n° 13 |
| `~/.config/tripleframes` jamais créé par la liste, alors que l'étape 7 y écrit `hook.env` (sur la VM, créé à la main, étape 1.3) | répertoire créé avec les racines privées ; commande exacte de `hook.env` | `ops/mise-en-service.md` (étapes 2 et 7) | 6.7 |
| Aucun contrôle des droits des fichiers servis après « Déployer » | `find public … \| wc -l` → `0` après le premier et chaque « Déployer » ; caches de démarrage en `640` | `ops/mise-en-service.md` (étapes 1 et 7) | 6.3, n° 39 |
| Relevé sans `@@gtid_mode`, sans droits du HOME, sans témoin des voisins | lignes ajoutées au relevé ; relevé avant/après des voisins | `ops/mise-en-service.md` § 1 | 1.0, 1.3, 1.13, n° 16 |
| Blocs des étapes 3 et 5 dépendant d'une variable `PHP` posée à l'étape 2 ; aucune relecture de la base avant `migrate` | `PHP=…` et `cd` en tête de chaque bloc ; `grep` du `.env` et `about` avant `migrate` | `ops/mise-en-service.md` (étapes 3 et 5) | 2.7 |
| `listen queue` de la page de statut présentée comme la mesure de la file du pool | file réelle par `ss -xlnH` (`Recv-Q`), engorgement par `max children reached` | `ops/plesk/settings.md` § 3 | 5.8, n° 29 |
| `Restart=always` de Reverb sans son motif écrit | commentaire : Reverb sort en code 0 après une perte de Redis | `ops/systemd/tripleframes-reverb.service` | 4.14, n° 27 |
| Jeton des sondes passé **en argument** de `curl` (`-H "X-Probe-Token: $TOKEN"`), lisible des voisins dans `/proc/<pid>/cmdline` le temps de la requête | `-H @<(printf 'X-Probe-Token: %s\n' "$TOKEN")`, forme jouée sur la VM (même motif que le battement, n° 17) | `ops/mise-en-service.md` (étape 5) | 2.11, 6.7 |

Rien d'autre dans `ops/` n'a dû changer : configuration Redis, unités, arguments des workers et plafonds ont tenu tels quels (paramètres `__TF_…__` remplacés), y compris sous saturation (aucune OOM, aucune unité relancée par son plafond, étape 5.12).

### B. Valeurs à ajuster au relevé du VPS

- Paramètres `__TF_…__` (ports de Redis et de Reverb, utilisateur et groupe de l'abonnement, chemin de déploiement), reportés par le commit « gabarits ajustés au relevé » **avant** le premier « Déployer » (n° 5).
- Plafonds : maxima relevés sur la VM 49 Mo (worker `game`, plafond 192), 43 Mo (`default`, 512), 48 Mo (Reverb avec 150 `wss`, 256), 23 Mo (Redis, 320) : les valeurs des gabarits peuvent rester, ou baisser si le VPS est juste en mémoire, en gardant `--memory` < `memory_limit` < `MemoryMax`.
- `pm.max_children` : 12 a multiplié par 6 le temps de réponse du voisin sur 2 vCPU (n° 32) ; départ proposé, 2 à 3 processus par vCPU cédé à TripleFrames, recalibré par la séance ; `process.priority` et composant cgroups de Plesk, retenus ou écartés au relevé.
- `memory_limit` du PHP CLI de l'abonnement (`PHP_ARGS` des workers) ; chemin de `composer.phar` ; `DB_HOST` (`localhost` par le socket, ou `127.0.0.1`) ; noms réels de la base et de l'utilisateur (préfixe de Plesk).
- `@@gtid_mode` (n° 16) ; fuseau du serveur (`timedatectl` : heures des tâches planifiées, fenêtres de `analyse.sh` et `debit.sh`) ; noms des journaux nginx de l'abonnement et chemin du socket du pool (étape 5.5).
- nginx sous Plesk : doublon refusé pour `fastcgi_buffer_size` ou `location /` (retirer la seule ligne en double) ; durée HSTS la plus courte offerte ; `open_basedir` avec `/proc/meminfo` et `/proc/cpuinfo`, sinon `OPS_LOAD_CPU_COUNT`.
- Plesk Git : droits des fichiers déposés (n° 39) ; répertoire courant, délai et `HOME` des actions additionnelles (en-tête du hook).
- Sauvegarde : options `rclone` du fournisseur (`--s3-no-check-bucket --s3-no-head --no-check-dest`), refus de `rclone lsf` avec la clé du VPS (écriture seule vérifiée).
- Présence d'un `/root/.my.cnf` (impasse « `mysql -u …` refusé ») ; Redis voisin sur 6379 ou non (section « Redis partagé » des blocs de contrôle).

### C. Décisions à prendre par le porteur

| Décision | Options | À trancher avant | Écart |
| --- | --- | --- | --- |
| `APP_LOCALE` de production | `en` (défaut de `.env.example`) ou `fr` | le `.env` (étape 27) | n° 14 |
| Droits des journaux quotidiens créés par PHP-FPM ou la tâche planifiée (`644`) | `'permission' => 0640` sur les canaux quotidiens de `config/logging.php`, ou statu quo (HOME en `0710`) | la mise en service | n° 13 |
| Cadence du tier froid | hebdomadaire (décision 18) ou quotidienne (N100-2, seule à tenir 24 h de perte pour la curation) | l'étape 30 | étape 3.7 |
| Forme du tier chaud (L100-10) | vidage par `backup:snapshot` ; `backup.env` lu sans `source` ; instantané local supprimé après l'envoi | L100-10 | n° 17 |
| Notification des tâches de sauvegarde | « sortie non notifiée » (`ops/plesk/settings.md` § 4) ou « seulement en cas d'erreur » | L100-10 | n° 22 |
| Trou horaire du worker `game` (`--max-time=3600`, `RestartSec=2`) | `RestartSec=100ms` sur l'instance `game`, recyclage laissé au seul déploiement, ou second processus décalé | la séance du VPS | n° 30 |
| Priorité du worker `game` sous contention | `CPUWeight` supérieur à celui de PHP-FPM et `Nice=0` pour `game`, `default` gardé bas | la séance du VPS | n° 31 |
| Protection en amont de PHP (B demande 15 à 18 vCPU rien que pour refuser en 429) | limitation nginx par adresse sur `/seat/…/answer` et `/seat/…/choice` (`50`) | la séance du VPS, au plus tard la première partie | n° 33 |
| Seuil du critère 2 sur un voisin statique | page dynamique de chaque voisin, ou plancher absolu (référence + 5 ms) | la séance du VPS | n° 35 |
| Rythme du joueur coopératif du scénario | marge de 10 % sur l'intervalle (A seulement) | la séance du VPS | n° 34 |
| `vm.overcommit_memory` (avertissement de Redis) | aucun réglage du noyau sans l'accord des voisins | — | n° 7 |
| `CURATION_CAPTURE_ENABLED` | le tableau de `ops/mise-en-service.md` et l'étape 2.6 suivent `main` (« vide : fermée ») ; le travail en cours du porteur (D38 du 28/09, non commité) inverse la règle (« vide ou absente = ouverte ; `false` la ferme ») : réaligner le tableau dans le commit qui livre D38 | le `.env` | — |

### D. Constats détaillés, numérotés

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

Constats de la phase 4 — Partie (28/09) :

23. **Solo : aucune révélation après une réponse qui clôt la manche** (BUG-P1, anomalie du produit) : le client solo ne relit pas `solo.state` après une saisie ; la lecture suivante, programmée d'avance, tombe après la garde de préchargement de la manche suivante. À reporter : `60` § 16.4 (« après chaque geste » doit couvrir une saisie qui clôt la manche, ou la réponse doit porter `fetchNotBefore`/le paquet en solo), et un test de bout en bout du calendrier de sondage solo (`100` § 2). **Corrigé à la Clôture** (étapes 6.1, 6.2 et 6.4, commit `7996902`) : le magasin se relit aussitôt après un verdict qui clôt la saisie ou un 409 `closed` en solo ; `60` § 16.4 amendé ; calendrier de sondage rejoué par `store.test.ts` ; vérifié sur la VM (révélation ≈ 0,5 s après le clic).
24. **Prop `maintenance` figée après la levée du drapeau** (BUG-P2, anomalie du produit) : les gestes désactivés sur `maintenance` (lobby, podium, relance solo) le restent jusqu'à un rechargement. À reporter : `100` § 11.3 (le bandeau « paraît à la réponse Inertia suivante » ; il faut dire aussi comment il disparaît), `50`/`90` (le geste ne doit pas être désactivé par une prop qu'il est seul à pouvoir rafraîchir), note de déploiement de l'étape 126 (« demander aux joueurs présents de recharger »). **Corrigé à la Clôture** (étapes 6.1, 6.2 et 6.5, commit `9ed326c`) : tant qu'elle vaut vrai, la page relit la prop par rechargement partiel toutes les `heartbeatIntervalMs` et au réveil de l'onglet ; `50` § 8.1 et § 14, `60` § 16.4 amendés ; vérifié sur la VM (déblocage en 6 à 7,5 s sans rechargement). Restent à reporter : `100` § 11.3 et `90` § 3.3 (« le bandeau disparaît à la relecture que la page provoque tant qu'il est affiché »), et la note de l'étape 126, devenue inutile.
25. **Commit d'activation du drainage** : deux moitiés indissociables (hook et `deployHookDrainSteps()`) ; la répétition n'a livré que la première (garde-fou de la session). Rien à changer dans la spec ; à respecter à l'étape 126.
26. **Reconnexion des lobbies après `reverb:restart`** : la première tentative du client tombe pendant le `RestartSec=2` de l'unité ; la reconnexion suivante arrive environ 16 s après (délai du client). Sans partie en cours (drainage), sans conséquence ; à noter dans la note de déploiement (« les lobbies retrouvent le temps réel en une vingtaine de secondes »). Pendant une partie (étape 4.10, `systemctl restart`), la reconnexion a pris 1 s.
27. **Redémarrage de Redis** : les deux workers sortent en code 1 (`Stream is already at the end …`) et sont relevés 2 s plus tard ; Reverb s'arrête seul, **en code 0** (« Deactivated successfully »), environ 3 s après, et `Restart=always` le relance 2 s plus tard (≈ 5 s au total) ; `game:reschedule` rattrape les parties s'il y en avait. Conforme ; à écrire dans `100` § 10.3 (le redémarrage de Redis relance les trois unités applicatives) et § 10.5 : **`Restart=always` est nécessaire pour Reverb** — il sort en 0 après une perte de Redis, et une unité en `Restart=on-failure` le laisserait à terre (aucune ligne `status=1/FAILURE` à chercher pour lui).
28. **Même œuvre publiée deux fois** (catalogue de démonstration + curation, films 2 et 17, sans `movie_group`) : tirées dans la même partie. Le back-office les propose au regroupement (onglet « Même œuvre ») ; rappel pour la curation du VPS : regrouper homonymes et remakes avant de publier.

Constats de la phase 5 — Charge (28/09) :

29. **File PHP-FPM invisible sur socket unix** (`100` § 16.4, critère 1, et § 10.6) : la page de statut du pool rend `listen queue`, `max listen queue` et `listen queue len` à **0** sous Linux avec un socket unix (celui de Plesk) — mesure réservée aux sockets TCP —, y compris pendant une saturation (12 processus sur 12, `/up` à plus d'une seconde). À reporter : mesurer l'attente par `Recv-Q` de `ss -xlnH` sur le socket en écoute (root) et par `max children reached` ; retirer la mention « `listen queue` et `max listen queue` de la page de statut » du critère ou la qualifier.
30. **Recyclage horaire du worker `game`** (`100` § 10.4) : `--max-time=3600` fait sortir le worker en code 0, relevé par `Restart=always` après `RestartSec=2` : ≈ 2,2 s sans consommateur sur la file `game` toutes les heures (relevé à 15:52:59 → 15:53:01). Une frontière de palier due dans ce trou part avec 2 à 2,5 s de retard, au-delà du maximum du critère 1 (`transitionMaxWaitMs` + `tierGraceMs` = 1 300 ms). À trancher (`100` § 10.4, avec `60`) : `RestartSec=100ms` sur l'instance `game` (drop-in), ou recyclage laissé au déploiement seul (`queue:restart`), ou second processus `game` décalé.
31. **Priorité du worker `game` sous contention** (`100` § 10.4, « `CPUWeight` des workers inférieur à celui des pools PHP-FPM ») : pensée pour Imagick (file `default`), la règle vaut aussi pour `game` (`CPUWeight=80`, `Nice=5`), qui est l'horloge des parties ; sous processeur saturé, les frontières ont pris du retard (p95 483 ms en A, 360 ms en A2 réduite, seuil 300). À trancher : `CPUWeight` du worker `game` **supérieur** à celui de PHP-FPM (et `Nice=0`), en gardant `default` bas.
32. **`pm.max_children` et voisins** (`100` § 10.6, § 16.4 critère 2) : sous Plesk, pas de `CPUWeight` systemd propre au pool, puisque les pools de même version de PHP partagent `plesk-php84-fpm`. Leviers à relever au VPS : `pm.max_children`, `process.priority` du pool (directives FPM supplémentaires) et, s'il est disponible, le composant cgroups de Plesk (limites par abonnement). À 12, le pool a pris 175 % d'un cœur et multiplié par 6 le temps de réponse du voisin témoin. À reporter : `pm.max_children` à fixer **d'après le nombre de vCPU relevé** (proposition : 2 à 3 par vCPU cédé à TripleFrames), recalibré par la séance ; `process.priority` et le composant cgroups, retenus ou écartés au relevé du VPS, jamais d'avance (correction du contrôle de la phase Charge : la version précédente de cet écart faisait de `pm.max_children` le seul levier).
33. **Coût par requête et charge cible** (`00` § Exploitation, `100` § 16) : ≈ 20 ms de processeur par requête de jeu, ≈ 12 ms par refus 429 (Laravel, session, `seat.active` avant le limiteur), mesurés sur un cœur Ryzen 7 5700X. Ordres de grandeur (cœurs de ce type) : A ≈ 3 vCPU, A2 ≈ 6 vCPU, **B ≈ 15 à 18 vCPU** : la protection en amont de PHP de § 16.3 est quasi certainement nécessaire sur le VPS ; à préparer (`50`, limitation nginx sur `/seat/…/answer|choice`) avant la séance du VPS plutôt qu'après son échec.
34. **Rythme du joueur coopératif du scénario** (L100-12, `tests/Load/game-load.js`) : l'intervalle vaut exactement `1000 / attemptsPerSecond` ms ; la fenêtre d'une seconde du limiteur `answer`, ouverte à la réception de la soumission précédente, n'est pas toujours close à l'arrivée de la suivante : 36 % de 429 en A à deux salons. Sans effet sur les seuils (429 attendu) mais le débit utile de A est surestimé. Proposition : marge de 10 % sur l'intervalle du joueur coopératif (A seulement ; A2 et B gardent la cadence exacte).
35. **Seuil voisin sur un fichier statique** (`100` § 16.4, critère 2) : une référence p95 de l'ordre de la milliseconde (1,06 ms) donne un seuil arrondi à 1 ms, franchi par la seule gigue réseau. À préciser : mesurer une **page dynamique** de chaque voisin, ou plancher absolu du seuil (par exemple 1,2 × référence, au moins référence + 5 ms).
36. **Arrêt de k6 en pleine partie** (`100` § 16.5) : les parties coupées restent `paused` 15 minutes ; `loadtest:forget` les écarte et `deploy:drain` les attend. À écrire dans § 16.5 : après un arrêt, attendre l'interruption avant le nettoyage et avant le déploiement de rétablissement des limiteurs.
37. **Limiteurs d'entrée pendant la séance** (`100` § 16.2, en-tête du scénario) : le relèvement par déploiement puis le rétablissement par un second déploiement ont été joués (§ 11.4 deux fois, 24 s chacun) ; à écrire dans `100` § 16.2 comme préalable et comme **geste de clôture obligatoire** de la séance, avec les valeurs (nombre de salons × vagues, entrées par vague : le pot à cookies de k6 est vidé à chaque itération, chaque vague entre sans jeton, donc par adresse).
38. **Le geste de séance doit garder la CI verte** (`100` § 16.2, préalable 2 de l'en-tête de `tests/Load/game-load.js`, relevé par le contrôle de la phase Charge) : le relèvement s'écrit en modifiant les constantes `RoomRateLimits::DEFAULT_CREATES_PER_HOUR` et `DEFAULT_JOINS_PER_MINUTE`, **jamais** des littéraux dans `config/game.php` (que `RoomRateLimitsTest` compare aux constantes : 1 test en échec, vérifié à l'étape 5.7) ; commit **sur `main`**, seule branche dont le job `artifacts` publie la pointe (`needs: ci` puis `needs: build`), `composer ci:check` avant de pousser, workflows verts et « Publié : deploy … » avant le § 11.4 ; rétablissement par `git revert` sur `main`, même chemin. À écrire tel quel dans § 16.2 et dans l'en-tête du scénario, dont la formule « (`game.room.*`, par un déploiement) » laisse croire qu'on édite la configuration. Angle mort de la répétition : sa chaîne d'artefacts ne joue aucun test.
39. **« Déployer » et les permissions des fichiers servis** (Clôture, étape 6.3 ; `100` § 11.4, `ops/mise-en-service.md` § 2, `ops/plesk/settings.md`) : un checkout fait dans une session `umask 027` (règle RV-10 de toute session [abo]) crée les assets en `640`, refusés en 403 par nginx ; la répétition émulait « Déployer » ainsi et ne l'a vu qu'au premier build qui change les assets (n° 8). Sur le VPS, « Déployer » est fait par Plesk Git, dont les permissions de fichiers ne sont pas relevées. À écrire dans la liste de contrôle de la mise en service et dans § 11.4 : après chaque « Déployer », `public/build/**` lisible du serveur web (`644`) et une page sans 403 sur `/build/assets/` ; ne jamais corriger en passant la session en `022`. Angle mort de la répétition : une chaîne d'artefacts qui ne change aucun asset ne prouve pas la lisibilité des fichiers servis.

## État final

Relevé en lecture seule le 28/09/2026 à 18:48 UTC (étape 6.7), après la Clôture. **La répétition reste en place** sur la VM : le porteur choisit de l'arrêter, de la relancer ou de la désinstaller par les blocs ci-dessous, tous en [root] sur la VM (`ssh -i <clé> vagrant@192.168.10.10`, puis `sudo -i`), tapés ligne à ligne. **Aucun de ces blocs ne se colle sur le VPS.**

### Ce qui tourne

- **Cinq unités** actives et **activées** (elles redémarrent avec la VM) : `tripleframes-php-fpm` (master PHP-FPM dédié : pools `tripleframes` et `tripleframes-voisin`), `tripleframes-redis` (`127.0.0.1:6390`), `tripleframes-worker@game`, `tripleframes-worker@default`, `tripleframes-reverb` (`127.0.0.1:8090`).
- **Crontab de `tripleframes`** : `schedule:run` chaque minute (un processus PHP permanent) ; tier chaud chaque jour à 03:10 UTC et tier froid le dimanche à 03:20 UTC, **si la VM tourne à ces heures** : chaque passage ajoute un instantané local, gardé 7 jours, et deux objets dans le stockage simulé.
- **nginx** : `https://tripleframes-prod.test` (lien `sites-enabled/zz-tripleframes-prod.test`) et le voisin témoin `http://tripleframes-voisin.test` (lien `zz-tripleframes-voisin.test`) ; serveur par défaut inchangé (`atomsdle.test`).
- **Application** : checkout de l'artefact n° 8, `deploy 3102d55` (source `a8c93d6` = `584ecdc` + BUG-P1 + BUG-P2), hook à douze étapes (drainage actif), aucun drapeau de drainage ; base `tripleframes_prod` : catalogue de 17 films publiés (16 de démonstration, 1 curé), 12 parties toutes `completed` et aucune en cours, 6 salons pas encore archivés (`room:archive-idle` les archivera à l'échéance), administrateur `admin@tripleframes-prod.test` au second facteur confirmé ; `/up` et cinq sondes vertes (relevé de 19:02 UTC).
- **Secrets de la répétition**, sur la VM seulement : `.env` de l'application (`0600`), `/etc/tripleframes/tripleframes-redis.conf` (`root:tfredis 0640`), `/root/tripleframes-hors-machine/` (copie d'`APP_KEY`, clé privée `age`). Sur le poste : `<scratchpad>/vm/admin-credentials.txt` (mot de passe, clé TOTP et codes de secours de l'administrateur).
- **Disque** : HOME de l'abonnement 240 Mo, Redis dédié 33 Mo, stockage simulé 388 Ko, `/home/vagrant/tripleframes-repetition/` 395 Mo (clones, *bundles*, relevés, outils de charge).
- **Ajouts système** : paquet `age` ; utilisateurs `tripleframes` (uid 1001), `tfredis`, `tfvoisin` ; lien `/opt/plesk/php/8.4/bin/php` → `/usr/bin/php8.4` ; `/etc/tripleframes/`, `/etc/logrotate.d/tripleframes-prod-test`.
- **Services existants de la VM** : intacts, PID inchangés depuis le 23/09 (nginx 973, `php8.1-fpm` 735, `php8.3-fpm` 736, `php8.4-fpm` 737, MySQL 870, `redis-server` 743, supervisor 749) ; Redis partagé : 22 clés ; base de développement `tripleframes` jamais écrite par la répétition (la migration de 13:19 est celle du porteur, étape 3.11) ; site `tripleframes.test` du porteur non touché.

### Vérifier

```bash
systemctl is-active tripleframes-php-fpm tripleframes-redis tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
systemctl is-enabled tripleframes-php-fpm tripleframes-redis tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
crontab -l -u tripleframes
ss -ltn | grep -E ':(6390|8090)\b'
curl -s -o /dev/null -w '/up %{http_code}\n' --cacert /etc/ssl/certs/ca.homestead.homestead.crt --resolve tripleframes-prod.test:443:127.0.0.1 https://tripleframes-prod.test/up
sudo -u tripleframes -H bash -c 'cd ~/tripleframes && /opt/plesk/php/8.4/bin/php artisan deploy:guard; echo "deploy:guard code=$?"'
sudo -u tripleframes -H bash -c 'git --git-dir=$HOME/git/tripleframes.git log --oneline -1 deploy'
```

Puis le bloc de l'étape 3.1 tel quel (sondes, battements, Redis dédié, témoin du Redis partagé, parties en cours).

- **Attendu** : `active` ×5 et `enabled` ×5 ; quatre lignes de crontab (`MAILTO=""`, `schedule:run`, 03:10, 03:20) ; `127.0.0.1:6390` et `127.0.0.1:8090` seulement ; `/up 200` ; `deploy:guard` « Garde refusée : aucune fenêtre libre ouverte… », **code 2** (aucun drainage en cours : normal hors déploiement) ; `3102d55 deploy: a8c93d6…` ; au bloc 3.1, cinq sondes `{"status":"ok"} 200`, 404 sans jeton ou jeton faux, battement `game` de moins de 90 s. Depuis le poste, dans un navigateur : `https://tripleframes-prod.test` (ligne du fichier `hosts` des « Prérequis »).
- **Sur le VPS Plesk :** le bloc de l'étape 3.1 et les sondes depuis la supervision ; aucun geste propre à la VM.

### Arrêter sans désinstaller

Plus aucun processus, aucune tâche, aucun port ; données, secrets et configuration restent en place, et un redémarrage de la VM ne relance rien.

```bash
crontab -l -u tripleframes > /root/tripleframes-crontab.txt && crontab -r -u tripleframes
systemctl disable --now tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
pkill -TERM -u tripleframes -f 'artisan schedule:run'; sleep 3; true
systemctl disable --now tripleframes-php-fpm tripleframes-redis
rm -f /etc/nginx/sites-enabled/zz-tripleframes-prod.test /etc/nginx/sites-enabled/zz-tripleframes-voisin.test
nginx -t && systemctl reload nginx
systemctl list-units 'tripleframes-*' --all --no-pager --no-legend
ss -ltn | grep -E ':(6390|8090)\b' || echo "6390 et 8090 libérés"
ps -u tripleframes -o pid,args --no-headers || echo "aucun processus de tripleframes"
```

- **Attendu** : la crontab sauvegardée dans `/root/tripleframes-crontab.txt` (aucun secret), puis vidée ; les cinq unités `inactive` et `disabled` (la liste ne rend plus que des lignes `inactive dead`, ou rien) ; ports libérés ; aucun processus ; `nginx -t` réussi et **rechargé** (même PID de master, jamais redémarré). Les noms `tripleframes-prod.test` et `tripleframes-voisin.test` tombent alors sur le serveur par défaut (`atomsdle.test`) : sans conséquence.
- **Ordre** : tâches d'abord (un `schedule:run` lancé sans Redis écrirait des erreurs au journal), workers et Reverb ensuite, Redis en dernier.

### Relancer

```bash
systemctl enable --now tripleframes-redis tripleframes-php-fpm
systemctl enable --now tripleframes-worker@game tripleframes-worker@default tripleframes-reverb
ln -s /etc/tripleframes/nginx/tripleframes-prod.test.conf /etc/nginx/sites-enabled/zz-tripleframes-prod.test
# facultatif : voisin témoin de la phase Charge
ln -s /etc/tripleframes/nginx/tripleframes-voisin.test.conf /etc/nginx/sites-enabled/zz-tripleframes-voisin.test
nginx -t && systemctl reload nginx
crontab -u tripleframes /root/tripleframes-crontab.txt && rm /root/tripleframes-crontab.txt
sudo -u tripleframes -H bash -c 'umask 027; cd ~/tripleframes && /opt/plesk/php/8.4/bin/php artisan game:reschedule'
```

- **Attendu** : puis le bloc « Vérifier » ci-dessus : cinq unités actives, `/up` 200, sondes vertes (la sonde `worker-game` retrouve son vert au premier battement, en moins de 30 s). `game:reschedule` en code 0, rien à rattraper s'il n'y avait aucune partie à l'arrêt.
- **Après un `vagrant provision`** (propre à Homestead) : les liens `zz-…` disparaissent de `sites-enabled/` ; les recréer par les deux `ln -s` ci-dessus, puis `nginx -t && systemctl reload nginx`.

### Retour arrière : désinstallation complète de la VM

Tout ce que la répétition a créé (inventaire ci-dessous), et rien d'autre. Ordre imposé : tâches et processus d'abord (sinon `userdel` refuse), liens nginx avant la suppression de `/etc/tripleframes` (sinon tout `nginx -t` suivant échouerait), voisin témoin avant le `rmdir` de `/var/www/vhosts`. À jouer ligne à ligne, et à lire en entier avant.

```bash
# 0. Témoins « avant » des services existants, à comparer au point 8.
for u in nginx php8.1-fpm php8.3-fpm php8.4-fpm mysql redis-server supervisor; do printf '%-12s %s %s\n' $u "$(systemctl is-active $u)" "$(systemctl show -p MainPID --value $u)"; done
redis-cli -p 6379 info keyspace | grep '^db'
mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tripleframes';"
# 1. Plus aucune tâche planifiée, aucune unité, aucun processus de l'abonnement.
crontab -r -u tripleframes
systemctl disable --now tripleframes-worker@game tripleframes-worker@default tripleframes-reverb tripleframes-php-fpm tripleframes-redis
# schedule:run tient toute sa minute (everyThirtySeconds) et backup-hot.sh peut tourner à 03:10 :
# sans cela, userdel refuse (« currently used by process », code 8) et laisse le HOME en place.
pkill -TERM -u tripleframes; sleep 3; pkill -KILL -u tripleframes; true
# 2. nginx : les deux liens « zz- » ensemble, puis rechargement (jamais restart).
rm -f /etc/nginx/sites-enabled/zz-tripleframes-prod.test /etc/nginx/sites-enabled/zz-tripleframes-voisin.test
nginx -t && systemctl reload nginx
# 3. Unités systemd et rotation des journaux du vhost.
rm -f /etc/systemd/system/tripleframes-redis.service /etc/systemd/system/tripleframes-reverb.service \
      /etc/systemd/system/tripleframes-php-fpm.service /etc/systemd/system/tripleframes-worker@.service
rm -rf /etc/systemd/system/tripleframes-worker@game.service.d /etc/systemd/system/tripleframes-worker@default.service.d
systemctl daemon-reload
systemctl reset-failed 'tripleframes-*' 2>/dev/null; true
rm -f /etc/logrotate.d/tripleframes-prod-test
# 4. Base et utilisateur de la répétition SEULEMENT : jamais la base « tripleframes » de développement.
mysql -e "DROP DATABASE IF EXISTS tripleframes_prod; DROP USER IF EXISTS 'tripleframes_prod'@'localhost';"
# 5. Utilisateurs système et leurs arborescences (HOME, voisin témoin, journaux du vhost, données Redis).
rm -rf "/tmp/tmux-$(id -u tripleframes)"
userdel -r tripleframes
rm -rf /var/www/vhosts/tripleframes-voisin.test /var/www/vhosts/system/tripleframes-voisin.test
userdel tfvoisin
rm -rf /var/www/vhosts/system/tripleframes-prod.test
rmdir /var/www/vhosts/system /var/www/vhosts
userdel tfredis
rm -rf /var/lib/tripleframes-redis
# 6. Faux chemin Plesk. Jamais « rm -rf /opt/plesk » : sur un vrai Plesk, cette ligne le détruirait.
# Le lien n'est retiré que s'il pointe vers le PHP de Homestead (sur Plesk, c'est un vrai binaire) ;
# rmdir échoue sans dommage sur un répertoire non vide.
[ "$(readlink /opt/plesk/php/8.4/bin/php)" = /usr/bin/php8.4 ] && rm /opt/plesk/php/8.4/bin/php && rmdir /opt/plesk/php/8.4/bin /opt/plesk/php/8.4 /opt/plesk/php /opt/plesk
# 7. Configuration, secrets, stockage simulé, espace de travail, paquet.
rm -rf /etc/tripleframes /root/tripleframes-secrets /root/tripleframes-hors-machine /root/tripleframes-crontab.txt
rm -rf /srv/tripleframes-stockage-distant-simule
rm -rf /home/vagrant/tripleframes-repetition
NEEDRESTART_MODE=l DEBIAN_FRONTEND=noninteractive apt-get remove -y age
# 8. Contrôle : plus rien de la répétition, services existants intacts.
systemctl list-unit-files 'tripleframes-*' --no-pager --no-legend
id tripleframes; id tfredis; id tfvoisin
mysql -N -e "SHOW DATABASES LIKE 'tripleframes%'; SELECT user, host FROM mysql.user WHERE user LIKE 'tripleframes%';"
ls -d /etc/tripleframes /var/www/vhosts /opt/plesk /var/lib/tripleframes-redis /srv/tripleframes-stockage-distant-simule /root/tripleframes-hors-machine /home/vagrant/tripleframes-repetition
ls /etc/nginx/sites-enabled/
ss -ltn | grep -E ':(6390|8090)\b' || echo "6390 et 8090 libres"
for u in nginx php8.1-fpm php8.3-fpm php8.4-fpm mysql redis-server supervisor; do printf '%-12s %s %s\n' $u "$(systemctl is-active $u)" "$(systemctl show -p MainPID --value $u)"; done
redis-cli -p 6379 info keyspace | grep '^db'
mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tripleframes';"
curl -s -o /dev/null -m 10 -w 'défaut http %{http_code}\n' http://127.0.0.1/
echo | openssl s_client -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -subject
```

- **Attendu au point 8** : aucune unité listée ; `id: 'tripleframes': no such user` (et de même pour `tfredis`, `tfvoisin`) ; seule la base `tripleframes` (développement) et aucun utilisateur MySQL `tripleframes%` ; `No such file or directory` pour chacun des sept chemins ; `sites-enabled/` sans aucun lien `zz-…` (le site `tripleframes.test` du porteur y reste) ; ports libres ; **mêmes PID** qu'au point 0 (nginx rechargé, jamais redémarré), mêmes clés dans le Redis partagé, même nombre de tables dans la base de développement ; serveur par défaut `défaut http 200`, `CN = atomsdle.test`. `userdel -r` peut signaler « mail spool … not found » : sans conséquence.
- **Poste** (Git Bash), une fois la VM rendue, pour les fichiers qui portent des secrets ou des cookies de répétition (identifiants de l'administrateur et clé TOTP, cookies de session du back-office et de la restauration, cookies `player_token` des trois profils de joueurs de la phase 4) :

```bash
rm -rf <scratchpad>/vm/admin-credentials.txt <scratchpad>/vm/driver/profile <scratchpad>/vm/driver/profile-restore <scratchpad>/vm/partie/profile-hote <scratchpad>/vm/partie/profile-invite <scratchpad>/vm/partie/profile-tiers
```

  Puis, ce runbook commité, `<scratchpad>/vm/` et `<scratchpad>/k6/` peuvent partir en entier (clone jetable, *bundles*, enregistrements, captures, copie de la clé SSH : le `key.txt` du porteur reste la référence). Si la ligne `192.168.10.10 tripleframes-prod.test` a été ajoutée au fichier `hosts` de Windows (« Prérequis »), la retirer (Bloc-notes en administrateur), puis `ipconfig /flushdns` ; si la CA de Homestead a été importée, `certutil -user -delstore Root "Homestead homestead Root CA"`.
- **Sur le VPS Plesk :** jamais ce bloc. Abandonner l'installation du VPS, c'est : Plesk › supprimer l'abonnement `<DOMAINE>` (fichiers, base, tâches planifiées, Git) ; puis, en root, les seuls gestes de l'étape 4 : `systemctl disable --now tripleframes-worker@game tripleframes-worker@default tripleframes-reverb tripleframes-redis`, suppression des unités `tripleframes-*` et de leurs drop-ins, `systemctl daemon-reload`, `rm -rf /etc/tripleframes /var/lib/tripleframes-redis`, `userdel tfredis`. **Jamais** `/opt/plesk`, jamais `plesk-php84-fpm`, jamais un Redis ou un site voisin.

#### Notes du retour arrière

- **Phase 5** : les deux liens `zz-…` sont retirés **ensemble**, avant le rechargement de nginx : un lien laissé vers `/etc/tripleframes/nginx/…`, supprimé plus bas, ferait échouer tout `nginx -t` suivant ; le voisin témoin est retiré avant le `rmdir` de `/var/www/vhosts`, qui échouerait sinon (répertoire non vide). Rien d'autre à défaire : les salons synthétiques sont déjà oubliés et les limiteurs rétablis (déploiement n° 7).
- **Relecture finale (étape 6.7)** : bloc réordonné (crontab et unités avant tout, liens nginx avant `/etc/tripleframes`) ; ajoutés : témoins avant et après, `systemctl reset-failed`, suppression de `/tmp/tmux-<uid>` (serveur `tmux` de `tripleframes`, relevé à 18:48), de la sauvegarde de crontab de « Arrêter », `DEBIAN_FRONTEND=noninteractive`, contrôle du point 8, nettoyage du fichier `hosts` et de la CA du poste, ligne « Sur le VPS Plesk ». Non joué : la répétition reste en place au choix du porteur.
- **Correction du contrôle de la phase 3** (28/09, 14:00 UTC, bloc documenté seulement) : `userdel -r tripleframes` suivait immédiatement `crontab -r`. Or un `schedule:run` de `tripleframes` est toujours vivant (relevé à 13:58 : PID 256741 et 256744), puisque le battement `everyThirtySeconds` le tient toute la minute (`ops/plesk/settings.md` § 4) ; à 03:10, `backup-hot.sh` peut aussi tourner. `userdel` aurait refusé (code 8), les lignes suivantes se seraient exécutées quand même, et le HOME serait resté en place, avec `.env`, instantanés, images, clé publique et `backup.env`. Ligne `pkill` insérée. Les éléments du poste étaient listés sans commande de suppression, alors que `vm/admin-credentials.txt` porte le mot de passe administrateur et la clé TOTP, et les profils `vm/driver/profile*` des cookies de session : commande ajoutée.
- **Correction du contrôle** (non jouée : bloc documenté seulement) : la ligne `rm -rf /etc/tripleframes /opt/plesk /root/tripleframes-secrets` a été scindée ; collée sur un VPS Plesk, elle aurait détruit Plesk, et la mention « propre à la VM » ne protège que le lecteur attentif. **Écart avec le correctif proposé** (`rm -f /opt/plesk/php/8.4/bin/php && rmdir …`) : sur un vrai Plesk, ce `rm -f` aurait supprimé le vrai binaire PHP 8.4 de tous les abonnements ; le lien n'est donc retiré que s'il pointe vers `/usr/bin/php8.4` (sur Plesk, `readlink` ne rend rien et la ligne s'arrête là). Ce bloc ne se colle **jamais** sur le VPS.

### Inventaire de ce que la répétition a créé

Par phase, sur la VM, sur le poste et dans le dépôt ; le bloc « Retour arrière » ci-dessus en retire tout ce qui est sur la VM, et la commande du poste ce qui porte un secret.

Créé par la phase 1 : paquet `age` ; utilisateurs `tripleframes` (HOME `/var/www/vhosts/tripleframes-prod.test`) et `tfredis` ; `/var/www/vhosts/system/tripleframes-prod.test/` ; `/opt/plesk/php/8.4/bin/php` (lien) ; base `tripleframes_prod` et utilisateur `tripleframes_prod@localhost` ; `/etc/tripleframes/` (Redis, `worker-*.env`, `php-fpm/`, `nginx/`, `tls/`) ; unités `tripleframes-redis`, `tripleframes-php-fpm`, `tripleframes-worker@.service` (+ drop-ins `game`, `default`), `tripleframes-reverb` ; `/var/lib/tripleframes-redis/` ; lien `/etc/nginx/sites-enabled/zz-tripleframes-prod.test` ; `/etc/logrotate.d/tripleframes-prod-test` ; `/root/tripleframes-secrets/` ; `/home/vagrant/tripleframes-repetition/` (relevés, *bundle*, clone `tests-s1` et son cache composer).

Créé par la phase 2 : dans le HOME de `tripleframes` (supprimé avec lui) : checkout de déploiement (`vendor/`, `.env`, caches), dépôt nu `git/` (branche `deploy`, quatre commits), `incoming/deploy-{1,2,3,4}.bundle`, `.config/tripleframes/hook.env`, `~/.cache/composer`, instantanés sous `private/tripleframes/snapshots/`, images sous `private/tripleframes/frames/` ; crontab de `tripleframes` ; `/root/tripleframes-hors-machine/` (copie d'`APP_KEY`) ; `/root/tripleframes-secrets/` **supprimé** (transit) ; dans `/home/vagrant/tripleframes-repetition/` : `ci/` (étape PHP du runner), `source-1.bundle`, `deploy-{1,2,3,4}.bundle`, `rv10-overlay.tar`, `wayfinder-1.tar.gz`, `outils/` (pilote `pty`, `sqlp.sh`), copies des fichiers corrigés ; base `tripleframes_prod` : comptes (dont trois de démonstration neutralisés), catalogue de démonstration, film 17. Sur le poste (scratchpad, hors dépôt) : clone `vm/build/src`, *bundles*, profil Chrome et pilote CDP, identifiants de l'administrateur (`vm/admin-credentials.txt`).

Créé par la phase 3 : `/root/tripleframes-hors-machine/backup-age-key.txt` (clé privée `age`, supprimée avec le répertoire) ; `/srv/tripleframes-stockage-distant-simule/` (stockage distant simulé : `hot/`, `cold/game/`) ; dans le HOME de `tripleframes` (supprimé avec lui) : `repetition-backup/` (préfigurations `backup-hot.sh`, `backup-cold.sh`), `.config/tripleframes/{backup.env,backup-recipient.txt,backup-cold-sent.txt}`, trois instantanés de plus, `tripleframes/storage/logs/backup.log` ; deux lignes de plus dans la crontab de `tripleframes` (03:10 et 03:20) ; dans `/home/vagrant/tripleframes-repetition/` : copies de `backup-hot.sh` et `backup-cold.sh`. **Déjà supprimé** par l'étape 3.10 : base et utilisateur `tripleframes_restore_tmp`, `restore-tmp-app/`, `private/restore-tmp/`, `/root/tripleframes-restauration/`, index Redis 8 et 9. Sur le poste (scratchpad) : scripts `vm/exploitation/`, pilote `vm/driver/restore-login.mjs`, profil Chrome `vm/driver/profile-restore/`, captures `30-*` et `31-*`.

Créé par la phase 4 — Partie : dans le HOME de `tripleframes` (supprimé avec lui) : `incoming/deploy-5.bundle`, commit `deploy` `9e5ed5a` du dépôt nu, `~/drain.log` (sortie de `deploy:drain`) ; en base `tripleframes_prod` (supprimée avec elle) : 8 parties, 3 salons (`V5GACS`, `555FP9`, `V2GV6M`) et leurs joueurs invités (pseudos de répétition `Camille`, `Robin`, `Zoé`, `Yann`, `Alex`), lignes `seen_frame`, `guess`, `round_*` ; dans `/home/vagrant/tripleframes-repetition/` : `deploy-5.bundle`, `outils/compte-secrets.sh` et `outils/compte-secrets-par-cle.sh` (étape 4.15, sans secret). Aucune session `tmux` laissée (`tf-drain` fermée, `tmux ls` sous `tripleframes` : « no server running », vérifié à 15:12 UTC). Sur le poste (scratchpad) : clone `vm/build/src` (commit `047b1ba`), `vm/build/deploy-5.bundle`, `vm/build/manifest-4.json`, pilote `vm/driver/p-*.mjs`, enregistrements et relevés `vm/partie/logs/`, captures `vm/partie/shots/`, profils Chrome `vm/partie/profile-{hote,invite,tiers}` (cookies de joueurs invités de la répétition, effacés par la commande du poste ci-dessous), journal des anomalies `vm/bugs.md`, scripts `vm/outils/compte-secrets{,-par-cle}.sh`.

Créé par la phase 5 — Charge : utilisateur système `tfvoisin` ; `/var/www/vhosts/tripleframes-voisin.test/` (page du voisin témoin) et `/var/www/vhosts/system/tripleframes-voisin.test/` (socket, journal) ; `/etc/tripleframes/php-fpm/pool.d/tripleframes-voisin.conf` et `/etc/tripleframes/nginx/tripleframes-voisin.test.conf` (supprimés avec `/etc/tripleframes`) ; lien `/etc/nginx/sites-enabled/zz-tripleframes-voisin.test` ; dans le HOME de `tripleframes` (supprimé avec lui) : `incoming/deploy-{6,7}.bundle`, `incoming/rooms-*.txt` (codes de salons synthétiques, déjà oubliés), commits `deploy` `7e5736f` et `7e4f04b` ; dans `/home/vagrant/tripleframes-repetition/` : `deploy-{6,7}.bundle`, `charge/` (échantillonneur, `analyse.sh`, fichiers d'échantillons CSV, listes de salons) ; en base : rien (67 salons synthétiques oubliés, 8 parties de la phase 4 seulement). Sur le poste (scratchpad, sans secret) : `vm/k6/` (copie du scénario, enveloppe, `.data/` : titres publiés, résumés, listes de salons, journaux de console), `vm/charge/` (scripts, échantillons, journaux k6, relevés), commits `699f8c8` et `584ecdc` du clone jetable, `vm/build/deploy-{6,7}.bundle`, `vm/build/manifest-{5,6}.json`.

Créé par la phase 6 — Clôture : dans le HOME de `tripleframes` (supprimé avec lui) : `incoming/deploy-8.bundle`, commit `deploy` `3102d55` ; en base `tripleframes_prod` (supprimée avec elle) : parties solo n° 40 et 41, salon `L7XW9V` (hôte `Camille`) ; dans `/home/vagrant/tripleframes-repetition/` : `deploy-8.bundle`. Sur le poste (scratchpad, sans secret) : commits `58c26da` et `a8c93d6` du clone jetable, `vm/build/deploy-8.bundle`, `vm/build/manifest-7.json`, `vm/build/build-8.log`, pilotes `vm/driver/c{0,1,2,3}-*.mjs`, `vm/cloture/public-lisible.sh`, `vm/charge/deploy-drainer-deployer.sh.avant-cloture`, enregistrements `vm/partie/logs/cloture-*`, captures `vm/partie/shots/cloture-*` ; dans le dépôt du porteur : les deux commits, rien d'autre (arbre de travail jetable supprimé, `git worktree prune`).

Créé par la relecture finale (étape 6.7) : rien sur la VM (relevé en lecture seule ; contrôle « 0 secret » par `compte-secrets.sh`, liste en `/dev/shm` supprimée à la sortie) ; sur le poste, `<scratchpad>/vm/cloture2-*` (relevé, texte de l'état final, script d'assemblage) et un arbre de travail jetable pour `composer ci:check`, supprimé ensuite ; dans le dépôt, un commit : ce runbook, les gabarits `ops/` corrigés et une ligne de `docs/REPRISE.md`.

### États intermédiaires consignés

Relevés de fin de phase, gardés tels quels pour la trace ; l'état courant est celui de « Ce qui tourne ».

**État à la fin de la phase Déploiement (28/09, 12:50 UTC)** : cinq unités `tripleframes-*` actives (`php-fpm`, `redis`, `worker@game`, `worker@default`, `reverb`), les trois dernières activées ; tâche planifiée posée ; `https://tripleframes-prod.test` servi (`/up` 200, `noindex` partout, HSTS court, `wss` de bout en bout, 6390 et 8090 fermés depuis le poste) ; cinq sondes vertes ; trois artefacts `deploy` (`0d33814`, `fe47155`, `e7cdb5c`) : un déploiement manuel (§ 11.6) puis deux par le hook, sans drainage ; administrateur `admin@tripleframes-prod.test` avec second facteur confirmé ; catalogue : 16 films de démonstration + 1 film curé (TMDB 129) publiés, vivier de 17 œuvres à N = 2 et 3 ; trois instantanés (`backup:snapshot` : 12:19, 12:34, 12:37). **Corrections du contrôle (13:11-13:17 UTC, RV-10)** : caches de démarrage et journal en `640`, unités PHP sous `Umask: 0027`, quatrième artefact `deploy` (`a775555`, hook avec `umask 027`) déployé par le hook, sans drainage ; cinq sondes vertes, `/up`, `/login`, `/` en 200, `wss` de bout en bout. **Restent** : transition du drainage (D5), phases 3 (exploitation, tier chaud, restauration), 4 (partie multijoueur et solo), 5 (charge), retour arrière.

**État à la fin de la phase Exploitation (28/09, 13:37 UTC)** : cinq unités `tripleframes-*` actives ; `/up` et cinq sondes vertes, chacune **vue au rouge** puis revenue (`worker-game` 503 à 80 s d'arrêt du worker, `purge` 503 sous suspension, 404 sans jeton, jeton faux ou sonde inconnue) ; trois exécutions complètes de la purge (`purge_run` : 6 lignes `completed` par exécution, 0 ligne supprimée) ; six instantanés `backup:snapshot` (12:19, 12:34, 12:37, 13:27, 13:30, 13:31) ; clé `age` (privée dans `/root/tripleframes-hors-machine/`, publique dans `~/.config/tripleframes/backup-recipient.txt`) ; stockage distant simulé `/srv/tripleframes-stockage-distant-simule/` : deux tiers chauds du jour (`hot/2026-09-28/`, 13:30:52 et 13:31:37), 4 objets froids (`cold/game/`) ; tâches planifiées du tier chaud (03:10 chaque jour) et du tier froid (03:20 le dimanche) posées dans la crontab de `tripleframes` ; contrôle de lisibilité vert ; **restauration chronométrée jouée sur cible jetable : environ 32 s** de la décision au dernier contrôle vert (connexion de l'administrateur, second facteur compris, sur la copie restaurée portant l'`APP_KEY` de l'exemplaire hors machine), cible supprimée ensuite (base, utilisateur, copie, racines, index Redis 8 et 9) ; contrôle final des gabarits conforme (écart n° 5). **Corrections du contrôle (13:58-14:00 UTC)** : `backup-hot.sh` réinstallé (battement par `curl -K -`, sha256 `224d93a2…`, `0750 tripleframes`), section du battement vérifiée seule contre des adresses factices en boucle locale ; tier chaud non rejoué ; aucune autre modification de la VM. **Restent** : transition du drainage (D5), phase 4 (partie multijoueur et solo), phase 5 (charge), retour arrière.

**État à la fin de la phase Partie (28/09, 14:53 UTC)** : cinq unités `tripleframes-*` actives (PID relevés après le redémarrage de Redis : Redis 261868, workers 261882/261887, Reverb 261897) ; `/up` et cinq sondes vertes ; **cinq artefacts `deploy`** : `0d33814` (mise en service manuelle), `fe47155`, `e7cdb5c`, `a775555` (hook sans drainage), **`9e5ed5a`** (commit d'activation du drainage, hook complet `[1/12]` à `[12/12]`, déployé après `deploy:drain` et `deploy:guard` : la transition I-13 est jouée) ; checkout de production sur `9e5ed5a`, hook avec les étapes 3 et 12 actives ; aucun drapeau de drainage (`deploy:guard` 2). Parties jouées : 8 (`game` 1 à 8), toutes `completed` — multijoueur 1, 2, 3 (salon `V5GACS`), 6 (`555FP9`, pendant le drainage), 7 (`V2GV6M`, après le hook) ; solo 4, 5, 8 ; film curé n° 17 joué en partie 3 (manche 3) et en solo (partie 4, manche 5). Contrôle anti-triche : 0 fuite (multijoueur : 41 fenêtres de manche, parties 1, 2, 3, 6 et 7 ; solo : 56 paquets hors révélation). Scores : les 30 lignes `guess` (24 multijoueur, 6 solo) recalculées par la formule de `80` § 4.2 à partir de leur `round_tier` : 30 conformes. Deux anomalies du produit consignées (BUG-P1 solo sans révélation après réponse, BUG-P2 boutons bloqués après la levée du drapeau). Trois salons non archivés (archivage par `room:archive-idle` à l'échéance). Sur le poste : trois Chrome headless fermés en fin de phase, profils `vm/partie/profile-{hote,invite,tiers}` conservés (cookies `player_token` de répétition seulement). **Corrections du contrôle (15:10-15:25 UTC, étape 4.15)** : runbook seul (fermeture de `tf-drain` avant `exit`, sortie en code 0 de Reverb relevée par `Restart=always`, `packages.php`/`services.php` en `750` attendus, profils de la phase 4 dans la commande de nettoyage du poste) ; contrôle des secrets refait **sur la VM** (`compte-secrets.sh`, 0 ligne fautive) ; aucune autre modification de la VM. **Restent** : phase 5 (charge), retour arrière.

**État à la fin de la phase Charge (28/09, 16:50 UTC)** : cinq unités `tripleframes-*` actives (Redis 261868, workers 326538/326545, Reverb 326550, relancés par le hook du déploiement n° 7) ; `/up` et cinq sondes vertes ; **sept artefacts `deploy`** : aux cinq de la phase 4 s'ajoutent `7e5736f` (limiteurs d'entrée relevés pour la séance) et **`7e4f04b`** (rétablis : même arbre que `9e5ed5a`), tous deux déployés par la procédure complète § 11.4 (drainage, garde, hook `[1/12]` à `[12/12]`, levée) ; checkout de production sur `7e4f04b`, `game.room` à 10 et 10 ; aucun drapeau de drainage. **Séance** : répétition à deux salons (`A-2`) **saine**, les deux critères tenus ; **scénario A à l'échelle D33 (20 salons, 150 joueurs) : la VM sature au démarrage des parties** (processeur 100 %, `load1` 12), critère 1 (latence p95 2 s, frontières p95 483 ms) et critère 2 (voisin témoin × 6) en échec, arrêt par le garde-fou au bout de 6 min 27 s, **aucune 5xx, aucune OOM, aucune unité relancée** ; A2 et B joués seulement à petite échelle (outillage), B refusant l'excédent en 429 sans 5xx ; coût mesuré ≈ 20 ms de processeur par requête. Base : 8 parties (celles de la phase 4), **0 siège `k6-`**, 67 salons synthétiques oubliés par `loadtest:forget` (4 + 4 de la répétition, 60 + 2 + 1 de la séance), catalogue intact (17 films, 87 frames). Services existants de la VM : PID inchangés depuis le 23/09, Redis partagé inchangé (22 clés), serveur par défaut inchangé. Restent en place pour le retour arrière : voisin témoin (`tfvoisin`, pool `tripleframes-voisin`, vhost `tripleframes-voisin.test`, inactif hors requête : `ondemand`), scripts et relevés de `/home/vagrant/tripleframes-repetition/charge/`, *bundles* `deploy-6` et `deploy-7`. **Corrections du contrôle de la phase Charge (28/09, 17:25-17:50 UTC)** : runbook seul dans le dépôt (outils de mesure recopiés aux étapes 5.3 à 5.5, commit de séance sur `main` par les constantes, listes de salons sous `~/private/loadtest`, un `--console-output` par exécution, drapeau laissé en place après un hook en échec, leviers Plesk du critère 2, écart n° 38) ; sur la VM, une copie jetable du clone de tests (`tests-c5`), jouée puis supprimée ; production, services et bases inchangés ; contrôle « 0 secret » de l'étape 5.14 rejoué sur ce runbook, le journal des écarts et les scripts du contrôle : 0 ligne fautive, rien resté dans `/dev/shm`. **Reste** : retour arrière (au choix du porteur).

**État à la fin de la Clôture, premier temps (28/09, 18:40 UTC)** : deux anomalies du produit corrigées dans le dépôt (`develop`, commits `7996902` BUG-P1 et `9ed326c` BUG-P2, non poussés ; `composer ci:check` vert sur un arbre propre, 4 385 tests Pest) ; **huit artefacts `deploy`** : aux sept de la phase 5 s'ajoute **`3102d55`** (source `a8c93d6` = `584ecdc` + les deux correctifs), déployé par la procédure complète du § 11.4 (et rejoué une fois par erreur, sans effet) ; checkout de production sur `3102d55` ; `public/` entièrement lisible du serveur web après rattrapage (53 assets neufs nés en `640`, écart n° 39) ; aucun drapeau de drainage ; cinq unités actives, `/up` et cinq sondes vertes. Vérifié dans le navigateur : révélation ≈ 0,5 s après une réponse qui clôt la manche en solo (bonne réponse et clic faux) ; relance solo et motif du lobby débloqués 6 à 7,5 s après `deploy:release`, sans rechargement, relance admise. Base : deux parties solo de plus (n° 40, 41, `completed`), un salon au lobby (`L7XW9V`, archivé à l'échéance par `room:archive-idle`). Chrome de la répétition fermés. **Reste** : retour arrière (au choix du porteur).
