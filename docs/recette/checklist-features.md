# Checklist de recette — toutes les features à essayer

Générée le 29/09/2026 sur `develop` (commit `b1da851`), à partir du code livré. Elle couvre le jalon 1 complet, plus trois livraisons du 28/09 : la gestion des comptes (L20-19, D40), la voie capture (L20-33, D38) et l'exclusion des visuels TMDB porteurs d'une langue (D39). Chaque item a été relu contre le code : route, contrôleur, composant et clé de traduction. Les libellés entre guillemets sont ceux de `lang/fr`.

Ce document ne décide rien. Si un « Attendu » contredit une spec, la spec l'emporte, et l'écart est à noter : c'est soit une anomalie du code, soit une erreur de la liste.

**860 items**, dont **232 essentiels**.

## Mode d'emploi

- **Légende** : ⭐ essentiel, c'est-à-dire un parcours principal, à faire en premier ; ⚠️ cas limite, refus ou erreur ; sans marque, item normal.
- **Chaque item** donne trois choses : **Faire** (comment provoquer la situation), **Attendu** (ce qu'on doit voir) et **Réf.** (spec, paragraphe et fichier).
- **Parcours express** : ne faire d'abord que les items ⭐, section par section. Ils couvrent les parcours principaux.
- **Ordre conseillé** : § 0, puis 1, 3, 4, 5 et 6 (côté joueur), puis 2, 7, 8 et 9 (comptes, back-office, exploitation). Les essais qui abîment le catalogue de démo viennent en dernier, par exemple § 6.11 « Catalogue insuffisant » ou les états forcés par `tinker`. Pour tout remettre à neuf ensuite : `php artisan backup:snapshot` (code 0), puis `php artisan migrate:fresh --seed`.
- **Valeurs** : 30 s, 3 images, 8 s, 10 manches ou 300/200/100 points sont les **valeurs par défaut** des réglages, jamais des constantes.
- **Anomalie** : noter à côté de l'item son numéro, ce qu'on a vu à la place de l'attendu et, si possible, une capture.
- **Variables d'environnement** : après chaque changement de `.env`, lancer `php artisan config:clear`, puis relancer `composer dev` si la file, le cache ou Reverb sont concernés. Remettre la valeur d'origine après l'essai.
- **Règle 12** : `php artisan backup:snapshot` doit sortir en code 0 avant tout geste manuel qui écrit dans `movie`, `frame`, `movie_title`, `alias` ou `frame_review`. Cela vaut pour `tinker` comme pour `migrate:fresh`. Les gestes faits dans l'interface du back-office, sur le catalogue de démo, ne posent pas de problème.
- **Interdits pendant la recette** : `php artisan cache:clear` (il efface le drapeau de drainage, la suspension de purge, les battements et les verrous), `storage:link` et `install:broadcasting`.

### Consignes de terminal (PowerShell 5.1 ou Git Bash)

- Sous PowerShell, écrire `curl.exe`, jamais `curl` : c'est un alias d'`Invoke-WebRequest`.
- `php artisan tinker --execute="…"` marche dans les deux terminaux tant que la commande ne contient que des apostrophes et aucun `$`. Une commande qui contient un `$` ou des guillemets doubles à l'intérieur est écrite pour Git Bash, entourée d'apostrophes ; sous PowerShell, la coller dans `php artisan tinker` en mode interactif.
- Pour lire le code de sortie : `echo $LASTEXITCODE` sous PowerShell, `echo $?` sous Git Bash.
- Les accents passés en argument de console peuvent être altérés sous PowerShell : écrire les arguments en ASCII.

### Vérifications manuelles jamais faites (`REPRISE.md` § 3, geste 11)

Elles conditionnent la porte du pilote (étape 56) :

- (a) le back-office à 375 px et au clavier : § 7.2, § 8.12 et § 9.5 ;
- (b) le recadreur au clavier et à la souris, sur la vraie page de l'éditeur : § 8.3 ;
- (c) l'appui long sur une image de jeu sous iOS Safari, qui ne doit ouvrir aucun menu : § 4.2, sur appareil réel (rejoué à l'étape 127) ;
- (d) la voie capture de bout en bout (choisir, coller, minutage, envoi, traitement, revue) : § 8.5 à § 8.7 et § 8.10.

## Sommaire

| § | Section | Items | ⭐ |
|---|---|---:|---:|
| 0 | [Avant de commencer](#0-avant-de-commencer) | 3 | 3 |
| 1 | [Accueil, pages publiques et transverses](#1-accueil-pages-publiques-et-transverses) | 78 | 23 |
| 2 | [Comptes, connexion et réglages du compte](#2-comptes-connexion-et-réglages-du-compte) | 102 | 30 |
| 3 | [Salon : création, réglages, entrée et lobby](#3-salon--création-réglages-entrée-et-lobby) | 97 | 23 |
| 4 | [Partie multijoueur : déroulé d’une manche, saisie et score](#4-partie-multijoueur--déroulé-dune-manche-saisie-et-score) | 79 | 22 |
| 5 | [Partie multijoueur : lancement, révélation, podium, présence et robustesse](#5-partie-multijoueur--lancement-révélation-podium-présence-et-robustesse) | 95 | 28 |
| 6 | [Mode solo](#6-mode-solo) | 67 | 14 |
| 7 | [Back-office : accès, import TMDB et catalogue](#7-back-office--accès-import-tmdb-et-catalogue) | 110 | 40 |
| 8 | [Back-office : banque d’images, recadreur, capture, revue et publication](#8-back-office--banque-dimages-recadreur-capture-revue-et-publication) | 108 | 28 |
| 9 | [Administration des comptes, commandes artisan et exploitation](#9-administration-des-comptes-commandes-artisan-et-exploitation) | 121 | 21 |

## 0. Avant de commencer

Cette mise en route est commune à tout le document. Les prérequis de chaque section la supposent faite.

### 0.1 Mise en route

- [x] Allumer la VM Homestead (`vagrant up` dans le dossier Homestead). MySQL de dev tourne sur 192.168.10.10, base `tripleframes`. Sans la VM, aucune page ne s’affiche, car la session (et, sur le `.env` actuel, le cache et la file) vivent en base.
- [x] Mettre le `.env` du poste à niveau pour le temps réel. Le `.env` actuel date d’avant L60-1 : il contient `BROADCAST_CONNECTION=log`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database` et `REDIS_CLIENT=phpredis`, et n’a aucune ligne `REVERB_*`. Indispensable : poser `BROADCAST_CONNECTION=reverb` et recopier tout le bloc `REVERB_*` de `.env.example` (sans lui, `REVERB_SCHEME` vaut `https` par défaut), avec un `REVERB_APP_SECRET` non vide (par ex. `php -r "echo bin2hex(random_bytes(16));"`). Poser aussi `REDIS_CLIENT=predis` (l’extension phpredis n’existe pas sous Windows). Redis est facultatif en local : si un Redis est joignable depuis Windows, poser `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_QUEUE_RETRY_AFTER=960` et son `REDIS_HOST`/`REDIS_PORT` ; sinon garder `QUEUE_CONNECTION=database` et `CACHE_STORE=database`, la pile du test de bout en bout du 28/09. Ne jamais viser le Redis partagé de la VM. Finir par `php artisan config:clear`.
- [x] Les variables absentes du `.env` prennent leur valeur par défaut. `SITE_INDEXABLE` absente : toutes les réponses sont en noindex. `ACCOUNTS_*` absentes : inscription et liens de compte ouverts, en `local` seulement. `LEGAL_CONTACT_EMAIL` absente : aucune adresse de contact n’est affichée. `CURATION_CAPTURE_ENABLED` absente : la voie capture est ouverte. `OPS_PROBE_TOKEN` absent : les sondes répondent 404. Garder `APP_DEBUG=true` au quotidien et ne le passer à `false` que pour le groupe « Pages d’erreur » et les items qui le demandent. Retirer chaque variable essayée (ou la remettre à son état d’origine) après l’essai.
- [x] Si `develop` a bougé depuis la dernière installation, lancer `composer install` puis `npm install` (predis, Reverb et laravel-echo sont requis).
- [x] Règle 12, sauvegarde avant remise à zéro : `php artisan backup:snapshot` doit sortir en code 0. La commande appelle `mysqldump`, qui n’est pas dans le PATH du poste Windows. Deux solutions : installer le client MySQL et l’ajouter au PATH, ou vider la base depuis la VM (`vagrant ssh`, puis `mysqldump --single-transaction tripleframes | gzip > ~/tripleframes-AAAAMMJJ.sql.gz` avec les identifiants du `.env`). La base de dev ne contient aucune frame curée, seulement trois films TMDB en brouillon (Fight Club, Parasite, Le Labyrinthe de Pan), que le `migrate:fresh` efface.
- [x] Lancer `php artisan migrate:fresh --seed`. En `local`, il joue trois seeders : DemoAccountsSeeder (trois comptes), PlatformDataSeeder (les 4 presets « Classique », « Rapide », « Hardcore », « Découverte » et les thèmes) et DemoCatalogueSeeder (16 films publiés, jouables de N = 2 à 5). Les images sont écrites sous `storage/app/frames` quand `FRAMES_DISK_ROOT` est vide.
- [x] Lancer `composer dev`. Il démarre `artisan serve`, l’écouteur des files `game` puis `default`, Reverb sur le port 8080 et Vite. Tout se teste sur http://127.0.0.1:8000, pas sur `tripleframes.test`. Sous Windows, `artisan serve` ne traite qu’une requête à la fois : une page peut attendre la fin d’une autre. `artisan serve` se relance seul quand `.env` change, mais pas la file ni Reverb : relancer `composer dev` après tout changement de file, de cache, de Redis ou de Reverb. `composer dev` ne lance pas le planificateur : pour les tâches planifiées (battements des workers, archivage des salons inactifs, purge), lancer `php artisan schedule:work` dans un terminal de plus.
- [x] Contrôle de santé : `curl.exe http://127.0.0.1:8000/up` doit répondre 200 « Application up ». Cette route interroge la base, et Redis quand le cache ou la file passent par lui. Sous PowerShell, écrire toujours `curl.exe`, car `curl` y est un alias d’`Invoke-WebRequest`.
- [x] Comptes de démo, tous en mot de passe `password` avec adresse déjà vérifiée : `player@tripleframes.test` (joueur, « Joueur Démo », langue fr) ; `curator@tripleframes.test` (curateur, nom réel « Camille Démo-Curation », fr) ; `admin@tripleframes.test` (admin, nom réel « Alex Démo-Administration », langue **en**). On se connecte par « Se connecter » dans l’en-tête public (`/login`), lien affiché tant que l’inscription est ouverte, c’est-à-dire par défaut en local. À sa première visite de `/admin`, un curateur ou un admin est renvoyé vers l’enrôlement du second facteur : prévoir une application TOTP.
- [x] Catalogue de démo : 16 films dont les images sont des aplats gris identiques, donc rien à reconnaître ; on joue en tapant le titre. Titres FR / EN : Blanche-Neige et les Sept Nains / Snow White and the Seven Dwarfs ; Le Voyage de Chihiro / Spirited Away ; Mon voisin Totoro / My Neighbor Totoro ; Le Roi Lion / The Lion King ; Toy Story ; Le Monde de Nemo / Finding Nemo ; Ratatouille ; Matrix / The Matrix ; Jurassic Park ; Retour vers le futur / Back to the Future ; Shining / The Shining ; Pulp Fiction ; Inception ; Star Wars : Un nouvel espoir / Star Wars: A New Hope ; Star Wars : L’Empire contre-attaque / Star Wars: The Empire Strikes Back ; Dune : Deuxième partie / Dune: Part Two. « Star Wars » seul est un préfixe partagé par deux films publiés : il est refusé.
- [x] Pour avoir plusieurs joueurs : l’identité d’invité vit dans le cookie `player_token` (HttpOnly, 30 jours). Deux onglets du même navigateur tiennent le même siège, et le second passe en lecture seule. Il faut donc un magasin de cookies par joueur : navigateurs différents (Chrome, Firefox, Edge), profils Chrome distincts, ou une fenêtre privée. Attention : toutes les fenêtres privées d’un même navigateur partagent un seul magasin, tant que l’une d’elles reste ouverte. Pour remettre un invité à zéro, supprimer ses cookies pour 127.0.0.1.
- [x] Outils de développement du navigateur utiles tout au long de la liste : Réseau (en-têtes, « Hors connexion », « 3G lente »), Application (Cookies, Stockage local), Rendu (émulation de `prefers-color-scheme` et de `prefers-reduced-motion`), et barre d’appareils à 375 px de large pour le portrait mobile.
- [x] Journaux : `storage/logs/laravel.log` pour l’application et `storage/logs/game-AAAA-MM-JJ.log` pour le canal `game` (lignes JSON, sans pseudo ni IP). Pour les suivre en direct : `Get-Content storage\logs\laravel.log -Wait -Tail 50`. `php artisan pail` ne fonctionne pas sur ce poste : il exige l’extension `pcntl`, absente sous Windows. La sortie de la file et de Reverb s’affiche dans le terminal de `composer dev`.
- [x] Avant de lancer les tests, arrêter `composer dev` (au moins Vite) et vérifier que `public/hot` a disparu ; après un arrêt brutal, le supprimer à la main, sinon les rendus de page Inertia de la suite échouent. Lancer ensuite `composer test` (Pint, PHPStan puis Pest, 6 à 8 minutes ; le délai de Composer y est désactivé). En secours : `php -d memory_limit=1536M artisan test --exclude-group=mysql --exclude-group=locks-timing`.

### 0.2 Contrôles de mise en route

- [x] **Vérifier la configuration temps réel du `.env`** ⭐
  - Faire : Après la mise à niveau du `.env` (0.1), lancer `php artisan config:clear`, puis, dans Git Bash : `php artisan tinker --execute='dump(config("broadcasting.default"), config("queue.default"), config("cache.default"), config("database.redis.client"));'`.
  - Attendu : `"reverb"`, puis `"redis"` ou `"database"` pour la file et le cache selon le choix fait en 0.1, puis `"predis"`. Si la première valeur est `"log"`, aucun événement temps réel n'arrivera, et les sections 3 à 5 ne sont pas jouables.
  - Réf. : 100 § 2 · `.env.example` · CLAUDE.md § 8 (predis en ligne de base)
- [ ] **Prendre un instantané, puis semer la base de démonstration** ⭐
  - Faire : Lancer `php artisan backup:snapshot` et exiger le code 0 (règle 12). `mysqldump` doit être dans le PATH ; il manque sur le poste Windows au 28/09 : installer le client MySQL 8, ou vider la base depuis la VM Homestead (voir 0.1). Lancer ensuite `php artisan migrate:fresh --seed`.
  - Attendu : La commande affiche « Instantané écrit et vérifié : …snapshot-AAAAMMJJTHHMMSSZ.sql.gz » et rend le code 0. Le seed joue DemoAccountsSeeder, PlatformDataSeeder et DemoCatalogueSeeder. Il crée trois comptes de démo (mot de passe `password`), les 4 presets et 16 films publiés. La base de dev actuelle est effacée : autre compte administrateur, Fight Club, Parasite et Le Labyrinthe de Pan. Sans instantané au code 0, ne pas lancer `migrate:fresh`.
  - Réf. : règle 12 · 100 § 13.1 · DatabaseSeeder
- [x] **Lancer `composer dev` et vérifier ses quatre processus** ⭐
  - Faire : La VM Homestead étant démarrée, lancer `composer dev`. Ouvrir http://127.0.0.1:8000/up, puis créer un salon depuis `/r/new`, l'onglet Réseau filtré sur « WS ».
  - Attendu : Quatre processus démarrent, étiquetés server, queue, reverb et vite. La file écoute `game,default`, avec `--tries=1 --timeout=900`. `/up` répond 200 « Application up ». Dans le lobby, une connexion WebSocket vers le port 8080 (`/app/…`) passe au statut 101 et reste ouverte. On ouvre toujours le site par 127.0.0.1:8000, jamais par l'`APP_URL` du poste.
  - Réf. : CLAUDE.md § 4 · composer.json (script dev) · lib/game/echo.ts

## 1. Accueil, pages publiques et transverses

Cette section couvre l’accueil `/`, l’en-tête et le pied de page publics (attribution TMDB, bandeau de maintenance), les quatre pages légales livrées en squelette, le changement de langue FR ↔ EN, l’apparence claire, sombre ou système (le jeu restant forcé en sombre) et les pages d’erreur. Elle finit par les en-têtes HTTP d’indexation et de cache et par les routes techniques `/clock`, `/up`, `/robots.txt` et `/ops/probe`.

**Prérequis**

- La mise en route du § 0 est faite : VM allumée, `.env` à niveau, base seedée, `composer dev` lancé, `/up` à 200.
- Deux navigateurs, ou deux profils, à magasins de cookies distincts pour les items qui passent par un salon ; créer un salon depuis `/r/new` avec « Créer le salon » suffit.
- Outils de développement ouverts (Réseau, Application > Cookies et Stockage local, Rendu, barre d’appareils) et PowerShell avec `curl.exe`.
- Groupe « Pages d’erreur » : poser `APP_DEBUG=false` dans `.env` le temps du groupe, puis le remettre à `true`. En debug, la page du framework reste affichée à la place des pages traduites.
- Items de compte : connexion par « Se connecter » (inscription ouverte, défaut local). Items back-office : un compte curateur ou admin dont le second facteur est confirmé (voir la section back-office).

### 1.1 Accueil `/`

- [x] **Ouvrir l’accueil en invité** ⭐
  - Faire : Dans une fenêtre privée d’un navigateur réglé en français, ouvrir http://127.0.0.1:8000/.
  - Attendu : L’onglet s’intitule « Accueil - TripleFrames ». La page affiche le titre « Devinez le film, image après image », la phrase « Un blindtest de films et de dessins animés : chaque manche dévoile quelques images d’un même film, de la plus cryptique à la plus évidente. Trouvez son titre avant les autres. », le bouton « Créer un salon », le champ « Code du salon » avec le bouton « Rejoindre », et le bouton « Jouer en solo ». L’en-tête montre « TripleFrames » en texte (aucun logo), en local « Se connecter » et « Créer un compte », le sélecteur de langue « Français » et le bouton d’apparence ; le pied de page est complet. Aucun compte n’est demandé.
  - Réf. : 90 § 4.7 · welcome.tsx · common.home.*
- [x] **Suivre « Créer un salon »** ⭐
  - Faire : Sur `/`, cliquer « Créer un salon ».
  - Attendu : La page `/r/new` s’ouvre (onglet « Créer un salon - TripleFrames »), avec l’intro « Choisissez un pseudo et un avatar : vous réglerez la partie dans le salon. », le champ « Pseudo », le sélecteur « Choisissez un avatar » et le bouton « Créer le salon ». L’en-tête et le pied de page publics restent présents, et la page suit l’apparence choisie.
  - Réf. : 90 § 4.7, 50 § 6.2 · route room.create
- [ ] **Suivre « Jouer en solo »** ⭐
  - Faire : Sur `/`, cliquer « Jouer en solo ».
  - Attendu : La page `/solo/new` s’ouvre, titre « Jouer en solo ». Elle propose « Choisissez un preset » avec quatre presets (« Classique », « Rapide », « Hardcore », « Découverte »), le pseudo, l’avatar et le bouton « Commencer l’entraînement ».
  - Réf. : 90 § 4.7, 60 § 16.4 · route solo.create
- [x] **Rejoindre un salon existant par son code** ⭐
  - Faire : Navigateur A : créer un salon (`/r/new`, pseudo, « Créer le salon ») et noter le code affiché dans le lobby. Navigateur B (autre magasin de cookies) : sur `/`, saisir ce code dans « Code du salon », puis « Rejoindre ». Navigateur A : revenir à `/` et saisir son propre code.
  - Attendu : B arrive sur `/r/<CODE>/join`, page « Rejoindre le salon » avec pseudo, avatar et bouton « Entrer ». A, qui tient déjà un siège, retourne directement au lobby `/r/<CODE>`, sans formulaire.
  - Réf. : 90 § 4.7, 50 § 7.2 · router.visit room.show
- [x] **Code mal formé refusé sans requête** ⚠️
  - Faire : Onglet Réseau ouvert, saisir `abc` puis Entrée. Recommencer avec `ABCDE1` : le chiffre 1 n’appartient pas à l’alphabet des codes.
  - Attendu : Le message « Ce code de salon n’est pas valide. Vérifiez-le, puis réessayez. » s’affiche sous le champ. Le champ est marqué invalide (`aria-invalid`) et garde le focus. Aucune requête n’apparaît dans Réseau. Le message s’efface dès qu’on modifie la saisie.
  - Réf. : 90 § 4.7 · lib/game/room-code.ts · common.home.room_code_invalid
- [x] **Code bien formé mais inconnu, saisi en minuscules avec tiret et espace** ⚠️
  - Faire : Saisir `zz-zz zz`, puis « Rejoindre ».
  - Attendu : La visite part vers `/r/ZZZZZZ` : le code est normalisé en majuscules, tiret et espace retirés. Avec `APP_DEBUG=false`, la page « Page introuvable » s’affiche au statut 404. Avec `APP_DEBUG=true`, Inertia montre la 404 brute du framework dans une fenêtre modale, ce qui est normal en dev.
  - Réf. : 90 § 4.7, § 4.8 · RoomCode::normalize
- [x] **Réseau coupé pendant l’envoi du code** ⚠️
  - Faire : Dans Réseau, passer en « Hors connexion ». Saisir `ZZZZZZ`, puis « Rejoindre ». Repasser en ligne et renvoyer.
  - Attendu : Hors connexion, « Une erreur est survenue. » s’affiche sous le champ et le bouton redevient actif. Une fois en ligne, l’envoi aboutit normalement.
  - Réf. : 90 § 10 (Accueil, réseau coupé) · common.state.error
- [x] **Navigation en cours : second envoi ignoré** ⚠️
  - Faire : Dans Réseau, choisir « 3G lente ». Saisir `ZZZZZZ`, puis appuyer deux fois de suite sur Entrée.
  - Attendu : Un spinner apparaît dans « Rejoindre ». Le bouton est grisé mais garde le focus (`aria-disabled`, `aria-busy`). Réseau ne montre qu’une seule requête GET `/r/ZZZZZZ`.
  - Réf. : 90 § 10 (Accueil) · welcome.tsx
- [ ] **Parcours clavier de l’accueil**
  - Faire : Recharger `/`, puis parcourir la page avec Tab depuis le haut. Sur le premier arrêt, appuyer sur Entrée. Taper ensuite un code dans le champ et valider par Entrée.
  - Attendu : Le premier arrêt est le lien « Aller au contenu », visible seulement au focus ; Entrée amène le focus sur le contenu. L’ordre suit le document : « TripleFrames », puis en local « Se connecter » et « Créer un compte », le sélecteur de langue, l’apparence, « Créer un salon », le champ du code, « Rejoindre », « Jouer en solo », puis les liens du pied de page. Un anneau de focus est visible à chaque arrêt. Entrée dans le champ envoie le code.
  - Réf. : 90 § 2.4, § 4.7 · public-layout.tsx
- [ ] **Accueil en portrait 375 px**
  - Faire : Dans la barre d’appareils, largeur 375 px, recharger `/`.
  - Attendu : Aucun défilement horizontal. « Créer un salon » et « Jouer en solo » prennent toute la largeur ; le champ du code et « Rejoindre » partagent une ligne. Chaque cible fait au moins 44 px de haut. L’en-tête peut passer sur deux lignes en local, à cause des liens de compte, mais rien ne déborde.
  - Réf. : 90 § 2.4, § 8 (cibles ≥ 44 px)
- [x] **Liens de compte de l’en-tête en local**
  - Faire : En invité, regarder l’en-tête de `/`. Se connecter avec `player@tripleframes.test` / `password`, puis revenir sur `/`.
  - Attendu : En invité, l’en-tête affiche « Se connecter » et « Créer un compte ». Une fois connecté, ces deux liens sont remplacés par « Tableau de bord ».
  - Réf. : 90 § 2.4, 40 § 8.2 · public-header.tsx · accountsOpen
- [x] **Inscription fermée : aucun lien de compte** ⚠️
  - Faire : Poser `ACCOUNTS_REGISTRATION_OPEN=false` dans `.env`, lancer `php artisan config:clear`, recharger `/` en invité puis connecté. Retirer la ligne ensuite.
  - Attendu : Ni « Se connecter », ni « Créer un compte », ni « Tableau de bord » n’apparaissent, qu’on soit connecté ou non. C’est le comportement de la production au J1.
  - Réf. : 40 § 8.2 · AccountSwitches

### 1.2 En-tête, pied de page, attribution TMDB et bandeau de maintenance

- [ ] **Pied de page complet sur toutes les pages hors jeu** ⭐
  - Faire : Faire défiler jusqu’en bas de `/`, `/r/new`, `/solo/new`, `/legal/notice` et d’une page `/r/<CODE>/join`. Cliquer ensuite un lien du pied avec l’onglet Réseau ouvert.
  - Attendu : Chaque page montre, dans cet ordre, « Mentions légales », « Conditions générales d’utilisation », « Politique de confidentialité » et « Signaler un contenu », suivis de la mention « Ce produit utilise l’API de TMDB mais n’est ni approuvé ni certifié par TMDB. ». Le clic produit une visite Inertia (requête XHR avec `X-Inertia`), sans rechargement complet.
  - Réf. : 90 § 3.1 · site-footer.tsx (full)
- [ ] **Logo TMDB absent : la mention reste seule**
  - Faire : Sur `/`, regarder l’attribution et filtrer Réseau sur `tmdb.svg`.
  - Attendu : Seule la mention textuelle s’affiche, sans icône d’image cassée. La requête `/brand/tmdb.svg` répond 404, et c’est attendu tant que le porteur n’a pas déposé le logo (`public/brand/` ne contient que `LICENSE.md`).
  - Réf. : 90 § 3.2 · tmdb-attribution.tsx · public/brand/LICENSE.md
- [ ] **Pied de page replié dans l’écran de jeu** ⭐
  - Faire : Dans un lobby, cliquer « Informations légales » dans la ligne basse. Parcourir la feuille, fermer par Échap, rouvrir, puis cliquer « Mentions légales ». Garder un second joueur dans un autre navigateur.
  - Attendu : Une feuille monte du bas, titrée « Informations légales ». Elle contient les quatre liens, l’attribution TMDB et le bouton « Fermer », sans croix « Close » en anglais. Échap la ferme. Le lien s’ouvre dans un nouvel onglet ; l’onglet du lobby reste en place et le second joueur voit toujours le premier comme connecté.
  - Réf. : 90 § 2.5, § 3.1 · site-footer.tsx (collapsed)
- [ ] **Nom du site et barre de progression**
  - Faire : Depuis `/legal/terms`, cliquer « TripleFrames » dans l’en-tête. En « 3G lente », cliquer ensuite un lien du pied de page.
  - Attendu : Le clic sur « TripleFrames » ramène à `/`. En 3G lente, une fine barre de progression Inertia apparaît en haut de la page, à la couleur primaire du thème en vigueur (clair ou sombre).
  - Réf. : 90 § 2.4 · app.tsx (progress var(--primary))
- [ ] **Bandeau de maintenance pendant un drainage**
  - Faire : Sans partie en cours, lancer `php artisan deploy:drain --window=5` (la commande rend la main après environ 15 s). Recharger `/`, `/legal/notice`, `/r/new` et un lobby ouvert. Lancer ensuite `php artisan deploy:release` et recharger.
  - Attendu : La console affiche « Drainage commencé : aucune nouvelle partie ne peut plus être lancée. Attente de la fin des parties en cours. », puis « Fenêtre libre ouverte jusqu’à … : aucune partie en cours. … ». Sous l’en-tête, ou en tête de l’écran de jeu, le bandeau (icône de clé) dit « Une mise à jour du site est en préparation : aucune nouvelle partie ne peut être lancée pour le moment. Les parties en cours continuent normalement. », sans heure ni phase. Après `deploy:release` (« Drapeau de drainage levé : les lancements sont de nouveau permis. »), le bandeau disparaît.
  - Réf. : 90 § 3.3 · maintenance-banner.tsx · DeployDrainCommand
- [ ] **Pendant le drainage : lancement refusé, création permise** ⚠️
  - Faire : Pendant la fenêtre ouverte par `deploy:drain --window=5` : sur `/r/new`, créer un salon ; dans son lobby, regarder « Lancer la partie ». Sur `/solo/new`, choisir un preset, saisir un pseudo et cliquer « Commencer l’entraînement ». Finir par `php artisan deploy:release`.
  - Attendu : La création du salon aboutit. Dans le lobby, le lancement est indisponible, avec le motif « Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. Réessayez un peu plus tard. ». En solo, aucune partie ne démarre et le même message s’affiche sous « Choisissez un preset ».
  - Réf. : 90 § 3.3, § 10 · common.maintenance.launch_blocked · SoloRefusal
- [ ] **Bandeau à la réponse suivante seulement, second drainage refusé** ⚠️
  - Faire : Garder `/` ouvert sans recharger et lancer `php artisan deploy:drain --window=5`. Une fois la fenêtre ouverte, relancer la même commande puis `echo $LASTEXITCODE`. Naviguer ensuite vers une page du pied. Finir par `php artisan deploy:release`.
  - Attendu : Aucun bandeau n’apparaît tant qu’on ne navigue pas : il n’y a pas de diffusion en temps réel au J1. Le second drainage est refusé par « Un drainage existe déjà (phase : fenêtre libre, échéance …) : rien n’a été modifié. Attendez son issue, ou levez-le par deploy:release. », en code 2. Le bandeau apparaît à la navigation suivante.
  - Réf. : 90 § 3.3, 100 § 11.3 · C18-bis

### 1.3 Pages légales et « Signaler un contenu » (squelette J1)

- [ ] **Mentions légales** ⭐
  - Faire : Cliquer « Mentions légales » dans le pied de page (`/legal/notice`).
  - Attendu : L’onglet s’intitule « Mentions légales - TripleFrames ». Le bandeau « Texte provisoire, sans valeur contractuelle. » s’affiche. Les sections sont « Éditeur du site », « Directeur de la publication », « Hébergeur », « Propriété intellectuelle » et « Données sur les films », avec des marqueurs visibles « [À FOURNIR …] ». Aucune ligne « Dernière mise à jour le … » n’apparaît. En bas, le bloc « Contact » dit « Aucune adresse de contact n’est encore publiée. ». En français, la ligne « Ces textes ne sont disponibles qu’en français. » n’apparaît pas.
  - Réf. : 90 § 4.2-4.4 · LegalPageController · views/legal/notice.fr
- [ ] **Conditions générales d’utilisation** ⭐
  - Faire : Ouvrir `/legal/terms`.
  - Attendu : Le titre « Conditions générales d’utilisation » et le bandeau provisoire s’affichent. Les sections « Objet », « Jouer sans compte », « Pseudo », « Origine des images et retrait » et « Données personnelles » ont un contenu, la dernière avec un lien vers la politique de confidentialité. « Comptes », « Âge minimum », « Responsabilité », « Droit applicable » et « Version » affichent « [À FOURNIR] ». Nulle part le service n’est qualifié de « non commercial ».
  - Réf. : 90 § 4.4 · views/legal/terms.fr
- [ ] **Politique de confidentialité et tableau des cookies** ⭐
  - Faire : Ouvrir `/legal/privacy`.
  - Attendu : Les sections « Responsable du traitement », « Données traitées », « Base légale », « Destinataires », « Durées de conservation » ([À FOURNIR]), « Cookies et stockage local », « Mesure d’audience » et « Vos droits » ([À FOURNIR]) s’affichent. Le tableau compte 8 lignes : `tripleframes-session` (session, « 120 minutes, prolongées à chaque visite », durée lue dans `SESSION_LIFETIME`), `XSRF-TOKEN`, `remember_web_*`, `player_token`, `locale`, `appearance`, `sidebar_state` et le stockage local `appearance`. « Mesure d’audience » dit « Le site ne pratique aucune mesure d’audience. ».
  - Réf. : 90 § 4.6 · views/legal/privacy.fr
- [ ] **« Signaler un contenu » sans formulaire** ⭐
  - Faire : Cliquer « Signaler un contenu » dans le pied de page (`/report-content`).
  - Attendu : Le titre « Signaler un contenu » et le bandeau provisoire s’affichent, avec les sections « Objet », « Comment signaler » (« Écrivez à l’adresse indiquée dans le bloc « Contact » en bas de cette page. ») et « Procédure et délais » ([À FOURNIR : …]). La page ne contient ni formulaire, ni champ, ni bouton d’envoi, et ne promet aucun délai.
  - Réf. : 90 § 4.5 · views/legal/report.fr
- [ ] **Aucune route d’écriture pour le signalement** ⚠️
  - Faire : `curl.exe -si -X POST http://127.0.0.1:8000/report-content`
  - Attendu : Statut 405 (méthode non permise, en-tête `Allow: GET, HEAD`), jamais 200 ni une redirection : aucune demande ne peut être créée au J1.
  - Réf. : 90 § 4.1, § 4.5 · routes/legal.php
- [ ] **Liens internes du corps**
  - Faire : Sur `/legal/notice`, cliquer le lien « Signaler un contenu » dans le texte (section « Propriété intellectuelle »). Sur `/legal/terms`, cliquer « politique de confidentialité ». Garder Réseau ouvert.
  - Attendu : Chaque lien ouvre la bonne page par un chargement complet (requête de type document, pas XHR Inertia). C’est voulu : le corps est du HTML injecté.
  - Réf. : 90 § 4.2
- [ ] **Mention des CGU sous les formulaires d’entrée**
  - Faire : Sur `/r/new`, `/solo/new` et une page `/r/<CODE>/join`, repérer le lien sous le bouton d’envoi, taper un pseudo, puis cliquer ce lien.
  - Attendu : Le lien « En continuant, vous acceptez les conditions générales d’utilisation. » (suffixé « (s’ouvre dans un nouvel onglet) » pour les lecteurs d’écran) ouvre `/legal/terms` dans un nouvel onglet ; l’onglet d’origine garde le pseudo saisi. Aucun cookie n’est posé par ce lien.
  - Réf. : 90 § 10 (Pseudo et avatar) · seat-form.tsx · legal.terms_notice
- [ ] **Pages légales en anglais : habillage traduit, corps en français**
  - Faire : Sur `/legal/privacy`, choisir « English » dans le sélecteur de langue. Inspecter ensuite `<html>` et le conteneur du texte.
  - Attendu : Le titre devient « Privacy policy », le bandeau « Provisional text, with no contractual value. ». La ligne « These pages are only available in French. » apparaît, et le contact dit « No contact address has been published yet. ». Le pied de page passe en anglais, mais le corps reste en français. `<html lang="en">`, et le conteneur du corps porte `lang="fr"`.
  - Réf. : 90 § 4.2, 05 § Attribut lang · legal/show.tsx
- [ ] **Adresse de contact publiée, puis faite d’espaces** ⚠️
  - Faire : Poser `LEGAL_CONTACT_EMAIL=contact@exemple.test` dans `.env` et recharger `/legal/notice`. Remplacer ensuite par `LEGAL_CONTACT_EMAIL='   '` et recharger. Retirer la ligne à la fin.
  - Attendu : Avec l’adresse, on lit « Pour toute question sur ces pages, écrivez à contact@exemple.test. », l’adresse étant un lien `mailto:`. Avec des espaces seulement, on revient à « Aucune adresse de contact n’est encore publiée. », jamais « écrivez à . ».
  - Réf. : 90 § 4.3 · LegalPageController::contactEmail
- [ ] **Tableau des cookies en portrait et au clavier**
  - Faire : Ouvrir `/legal/privacy` à 375 px de large. Atteindre le tableau avec Tab, puis utiliser les flèches gauche et droite.
  - Attendu : La page ne défile jamais horizontalement ; seul le tableau défile, dans sa région. Tab y pose le focus (anneau visible) et les flèches font défiler le tableau.
  - Réf. : 90 § 4.2 (région role=region) · legal/show.tsx

### 1.4 Langue (FR ↔ EN)

- [ ] **Passer l’accueil en anglais** ⭐
  - Faire : Sur `/`, cliquer « Français » dans l’en-tête, puis choisir « English » dans le menu « Langue ». Garder Réseau ouvert.
  - Attendu : Tout bascule sans rechargement complet : « Guess the movie, frame by frame », « Create a room », « Room code », « Join », « Play solo », pied de page en anglais et « This product uses the TMDB API but is not endorsed or certified by TMDB. ». L’onglet devient « Home - TripleFrames » et le déclencheur affiche « English » ; `<html lang="en" dir="ltr">`. Réseau montre POST `/locale` → 302, puis un GET XHR partiel (`X-Inertia-Partial-Data: locale,translations`). La position de défilement est gardée. Le cookie `locale=en` est posé pour 1 an, sans HttpOnly, en SameSite Lax.
  - Réf. : 05 § Changement de langue · LocaleController · language-switcher.tsx
- [ ] **Persistance de la langue choisie** ⭐
  - Faire : Après le passage en anglais, recharger la page, puis ouvrir `/legal/terms` dans un nouvel onglet. Revenir à « Français ».
  - Attendu : La page rechargée et le nouvel onglet restent en anglais. Le retour à « Français » repasse toute l’interface en français, et le cookie `locale` vaut `fr`.
  - Réf. : 05 § Le cookie locale · LocaleCookie
- [ ] **Annonce du changement pour les lecteurs d’écran**
  - Faire : Changer de langue, attendre une seconde, puis inspecter dans l’onglet Éléments la région `role="status"` masquée (`sr-only`, `aria-live="polite"`), en bas de page.
  - Attendu : La région contient l’annonce dans la nouvelle langue : « Language changed to English. » ou « Langue changée : Français. ».
  - Réf. : 90 § 8 · common.language.changed · GameAnnouncer
- [ ] **Sélecteur de langue au clavier**
  - Faire : Amener le focus sur le sélecteur avec Tab, ouvrir le menu par Entrée, parcourir avec les flèches, puis appuyer sur Échap. Rouvrir le menu et valider une langue par Entrée.
  - Attendu : Le déclencheur est annoncé « Langue actuelle : Français ». Le menu, titré « Langue », propose « English » puis « Français », chaque langue écrite dans sa propre langue avec son attribut `lang`, et l’option active est cochée. Échap referme le menu sans rien changer et rend le focus au déclencheur. Entrée applique la langue choisie.
  - Réf. : 90 § 8 (motif menu button)
- [ ] **Aller-retour lent : état d’attente** ⚠️
  - Faire : Dans Réseau, choisir « 3G lente », choisir « English », puis rouvrir aussitôt le menu et tenter de cliquer « Français ».
  - Attendu : Pendant l’aller-retour, un spinner remplace l’icône du déclencheur (`aria-busy`) et les options sont grisées : le second choix est impossible. Le déclencheur, lui, n’est jamais désactivé et garde le focus.
  - Réf. : 90 § 8 (état aller-retour en cours)
- [ ] **Changer de langue sur une page d’entrée sans perdre la saisie** ⚠️
  - Faire : Sur `/r/new`, taper « Testeur » dans « Pseudo » et choisir un avatar, puis passer en « English ».
  - Attendu : Les libellés passent en anglais (titre « Create a room », bouton « Create room ») alors que le pseudo saisi et l’avatar choisi restent en place : la page n’est pas remontée.
  - Réf. : 05 § Changement de langue (preserveState)
- [ ] **Changer de langue en pleine partie (ligne basse du jeu)** ⭐
  - Faire : Dans un lobby, ou pendant une manche avec un second joueur, cliquer l’icône de langue en bas à gauche (annoncée « Langue actuelle : Français ») et choisir « English ».
  - Attendu : Les libellés passent en anglais sans rechargement ni reconnexion. Le chrono continue sans repartir de zéro, et le second joueur ne voit aucune déconnexion. `<html lang="en">`.
  - Réf. : 90 § 2.3, 05 § Changement de langue · game-layout.tsx
- [ ] **Première visite : langue négociée par Accept-Language** ⭐
  - Faire : `curl.exe -si -H 'Accept-Language: fr-CA,fr;q=0.9,en;q=0.8' http://127.0.0.1:8000/`. Dans un navigateur, fermer toutes les fenêtres privées, mettre l’anglais en tête des langues préférées, ouvrir `/` dans une fenêtre privée neuve, puis rétablir le français.
  - Attendu : curl reçoit `Set-Cookie: locale=fr` (un an, samesite=lax) et `<html lang="fr"`. En fenêtre privée avec l’anglais en tête, `/` s’affiche en anglais et le cookie `locale=en` est posé : la négociation est collante.
  - Réf. : 05 § Résolution (niveau 4) · SetLocale
- [ ] **Qualités, sous-étiquettes et langue non gérée** ⚠️
  - Faire : `curl.exe -si -H 'Accept-Language: en;q=0.4, fr-BE;q=0.8' http://127.0.0.1:8000/`, puis `curl.exe -si -H 'Accept-Language: de-DE, es;q=0.8' http://127.0.0.1:8000/`.
  - Attendu : Premier appel : `<html lang="fr"` et `Set-Cookie: locale=fr`, car fr-BE est ramené à fr et gagne par sa qualité. Second appel : `<html lang="en"` (repli d’instance) et aucun `Set-Cookie: locale=`.
  - Réf. : 05 § Résolution (niveaux 4 et 5)
- [ ] **Le cookie prime sur Accept-Language, et une valeur falsifiée est ignorée** ⚠️
  - Faire : `curl.exe -si -b 'locale=en' -H 'Accept-Language: fr' http://127.0.0.1:8000/`, puis `curl.exe -si -b 'locale=xx' -H 'Accept-Language: fr' http://127.0.0.1:8000/`. Dans le navigateur, éditer ensuite le cookie `locale` à `xx` (Application > Cookies) et recharger.
  - Attendu : Avec `locale=en`, la page est en anglais et aucun nouveau cookie `locale` n’est posé. Avec `locale=xx`, la valeur est ignorée : la langue est renégociée (`<html lang="fr"`) et `Set-Cookie: locale=fr` réécrit le cookie. Le navigateur fait de même : la page s’affiche dans sa langue et le cookie est réécrit.
  - Réf. : 05 § Résolution (niveau 2), § Le cookie locale
- [ ] **Langue restaurée par le player_token** ⚠️
  - Faire : Dans un navigateur réglé en français, passer en « English », puis créer un salon, ce qui pose le cookie `player_token`. Supprimer le seul cookie `locale` et recharger `/`.
  - Attendu : La page reste en anglais malgré le navigateur en français : la revendication de langue du `player_token` passe avant Accept-Language. Aucun cookie `locale` n’est reposé, la langue n’ayant pas été négociée.
  - Réf. : 05 § Résolution (niveau 3) · PlayerTokenLocale
- [ ] **La langue du compte prime sur le cookie**
  - Faire : Dans un navigateur en français avec `locale=fr`, se connecter avec `admin@tripleframes.test` / `password` (compte en anglais), puis ouvrir `/`. Choisir « Français », se déconnecter, et se reconnecter depuis un autre navigateur réglé en anglais.
  - Attendu : Connecté, `/` s’affiche en anglais malgré le cookie `fr`. Après le choix de « Français », la langue du compte vaut fr : la reconnexion depuis le navigateur anglais affiche les pages en français.
  - Réf. : 05 § Résolution (niveau 1), § Persistance · users.locale
- [ ] **Back-office toujours en français**
  - Faire : Connecté en curateur ou admin (second facteur confirmé), choisir « English » sur `/`, puis ouvrir `/admin`.
  - Attendu : Le back-office reste en français, avec `<html lang="fr">`, quel que soit le choix fait sur les pages joueur.
  - Réf. : 05 § Back-office en français seulement · ForceAdminLocale
- [ ] **Rechargement complet après une modification de traduction** ⚠️
  - Faire : `/` ouvert, modifier la valeur de `heading` sous `home` dans `lang/fr/common.php`, puis lancer `php artisan lang:hash`. Cliquer un lien du pied, puis revenir à l’accueil. Remettre le fichier dans son état d’origine et relancer `php artisan lang:hash` (le fichier `bootstrap/cache/lang-version.php` existe déjà sur ce poste).
  - Attendu : Réseau montre une réponse 409 avec `X-Inertia-Location`, suivie d’un rechargement complet de la page. Le nouveau texte s’affiche sur `/`. Sans `lang:hash`, l’empreinte figée dans `bootstrap/cache/lang-version.php` ne change pas et aucune 409 n’a lieu : c’est le piège d’un déploiement qui oublie `lang:hash`.
  - Réf. : 05 § Invalidation · LangVersion · HandleInertiaRequests::version

### 1.5 Apparence clair / sombre / système, jeu forcé sombre

- [ ] **Choisir Clair, Sombre ou Système sur l’accueil** ⭐
  - Faire : Sur `/`, cliquer le bouton d’apparence (annoncé « Apparence ») et choisir « Sombre », puis « Clair », puis « Système ». Garder Réseau ouvert.
  - Attendu : Le menu « Apparence » propose « Clair » (soleil), « Sombre » (lune) et « Système » (écran), avec l’option active cochée. Le thème change aussitôt, sans aucune requête, et l’icône du bouton suit le choix. Le cookie `appearance` (365 jours) et le stockage local `appearance` prennent la valeur choisie.
  - Réf. : 90 § 2.4 · appearance-toggle.tsx · use-appearance.tsx
- [ ] **Bouton d’apparence au clavier**
  - Faire : Amener le focus sur le bouton d’apparence avec Tab, ouvrir par Entrée, parcourir avec les flèches, fermer par Échap, puis rouvrir et valider « Sombre » par Entrée.
  - Attendu : Le bouton, icône seule de 44 px, est annoncé « Apparence ». Échap referme le menu sans rien changer et rend le focus au bouton ; Entrée applique l’option choisie.
  - Réf. : 90 § 2.4 (motif menu button), § 8
- [ ] **Persistance sans éclair blanc**
  - Faire : Choisir « Sombre », puis recharger avec Ctrl+Maj+R. Afficher le source de la page.
  - Attendu : La page s’affiche sombre dès le premier rendu, sans flash blanc. Le source contient `<html … class="dark">`.
  - Réf. : app.blade.php · HandleAppearance
- [ ] **« Système » suit le thème de l’OS en direct**
  - Faire : Choisir « Système ». Dans Rendu, basculer l’émulation `prefers-color-scheme` entre dark et light.
  - Attendu : Le thème de la page suit l’émulation en direct, sans rechargement.
  - Réf. : use-appearance.tsx (écoute du thème système)
- [ ] **Toutes les pages hors jeu suivent le choix**
  - Faire : Choisir « Sombre », puis visiter `/legal/privacy`, `/r/new`, `/solo/new` et une page `/r/<CODE>/join`.
  - Attendu : Toutes ces pages s’affichent en sombre, y compris les pages d’entrée `room/*`. (La page d’erreur traduite s’essaie dans le groupe « Pages d’erreur », avec `APP_DEBUG=false`.)
  - Réf. : 90 § 2.1 (pages room/* dans l’apparence du visiteur)
- [ ] **Réglage d’apparence du compte partagé avec l’en-tête**
  - Faire : Connecté avec `player@tripleframes.test`, ouvrir `/settings/appearance` et choisir « Sombre ». Ouvrir ensuite `/` et le menu d’apparence de l’en-tête.
  - Attendu : La page « Réglages d’apparence » propose « Clair », « Sombre » et « Système ». Après le choix de « Sombre », `/` s’affiche en sombre et le menu de l’en-tête coche « Sombre » : c’est la même préférence (cookie et stockage local).
  - Réf. : 90 § 2.4 · settings/appearance.tsx · account.appearance.*
- [ ] **Écran de jeu forcé en sombre** ⭐
  - Faire : Choisir « Clair », puis créer un salon. Inspecter `<html>` dans le lobby et regarder la ligne basse. Refaire l’essai avec « Commencer l’entraînement » depuis `/solo/new`.
  - Attendu : Le lobby `/r/<CODE>` et la page solo `/solo` s’affichent en sombre, avec `<html class="dark" data-appearance-forced="dark">`. La ligne basse ne contient que l’icône de langue et « Informations légales » : aucun bouton d’apparence. Les pages `/r/new` et `/solo/new` étaient restées claires.
  - Réf. : 90 § 2.2, § 2.3 · ForceGameAppearance · game-layout.tsx
- [ ] **Le forçage résiste au thème système** ⚠️
  - Faire : Sur le lobby, avec « Système » choisi auparavant, émuler `prefers-color-scheme: light` dans Rendu.
  - Attendu : Le lobby reste sombre.
  - Réf. : use-appearance.tsx (handleSystemThemeChange)
- [ ] **Quitter le jeu rend l’apparence choisie**
  - Faire : Choisir « Clair » sur `/`, entrer dans un lobby, cliquer « Quitter le salon », puis confirmer par « Quitter le salon » dans la boîte (« Quitter le salon ? Vous pourrez revenir avec le lien. »). Regarder le stockage local.
  - Attendu : Le retour à `/` se fait en clair. Le stockage local `appearance` vaut toujours `light` : le jeu n’a jamais écrit la préférence.
  - Réf. : 90 § 2.2 (moitié cliente) · use-forced-appearance.ts
- [ ] **Recharger le lobby sans éclair clair** ⚠️
  - Faire : Avec « Clair » choisi, recharger `/r/<CODE>` par F5.
  - Attendu : La page est sombre dès le premier rendu, sans passage par le clair.
  - Réf. : 90 § 2.2 (moitié serveur + attribut)
- [ ] **Back-office dans l’apparence choisie**
  - Faire : Choisir « Sombre » sur `/`, puis ouvrir `/admin` en curateur ou admin (second facteur confirmé). Revenir à « Clair » sur `/` et recharger `/admin`.
  - Attendu : Le back-office suit la préférence : sombre, puis clair. Il n’a pas son propre bouton d’apparence.
  - Réf. : D8 du 23/09 · 90 § 2.2
- [ ] **Mouvement réduit respecté** ⚠️
  - Faire : Dans Rendu, émuler `prefers-reduced-motion: reduce`. Ouvrir les menus de langue et d’apparence, puis la feuille « Informations légales » du jeu. Changer de langue en « 3G lente ».
  - Attendu : Les menus et la feuille s’ouvrent sans animation, et le spinner ne tourne pas.
  - Réf. : 90 § 8 (motion-reduce)

### 1.6 Pages d’erreur (avec `APP_DEBUG=false`)

- [ ] **En debug, la page du framework reste** ⚠️
  - Faire : Avec `APP_DEBUG=true`, ouvrir `/nimporte-quoi`. Poser ensuite `APP_DEBUG=false` dans `.env` pour tout le groupe.
  - Attendu : En debug, la page 404 minimale du framework s’affiche, pas la page traduite. C’est voulu : la trace sert au développeur.
  - Réf. : 90 § 4.8 · ErrorPageResponder (hors debug)
- [ ] **404 : URL inconnue** ⭐
  - Faire : Ouvrir `/nimporte-quoi`, puis cliquer « Retour à l’accueil ».
  - Attendu : Statut 404, onglet « Page introuvable - TripleFrames ». Un petit « 404 » (masqué aux lecteurs d’écran) précède le titre « Page introuvable » et le texte « Cette page n’existe pas ou n’existe plus. Vérifiez l’adresse, ou le code du salon si vous rejoigniez une partie. ». L’en-tête et le pied de page publics sont présents. Le bouton ramène à `/`.
  - Réf. : 90 § 4.8 · pages/error.tsx · common.error.not_found
- [ ] **404 : code de salon inconnu ou hors forme** ⭐
  - Faire : Ouvrir `/r/ZZZZZZ`, puis `/r/AB` et `/r/ab-cd-ef`.
  - Attendu : Les trois adresses affichent la page « Page introuvable » au statut 404, sans trace ni message technique. `ab-cd-ef` est normalisé en `ABCDEF`, puis déclaré inconnu.
  - Réf. : 90 § 4.8, 50 § 6.3 · Room::resolveRouteBinding
- [ ] **403 : joueur connecté sur le back-office** ⭐
  - Faire : Se connecter avec `player@tripleframes.test` / `password`, puis ouvrir `/admin`. Se déconnecter et rouvrir `/admin` en invité.
  - Attendu : Connecté en joueur : statut 403, page joueur « Accès refusé » avec « Vous n’avez pas l’autorisation d’ouvrir cette page. », en français (langue du compte), dans la coquille publique et non celle du back-office. En invité : redirection vers la page de connexion.
  - Réf. : 90 § 4.8 · role:curator · common.error.forbidden
- [ ] **419 sur une page publique : toast et retour** ⭐
  - Faire : Sur `/`, supprimer le cookie `tripleframes-session` (Application > Cookies), puis changer de langue. Recommencer le changement.
  - Attendu : On reste sur `/`, la langue ne change pas, et un toast affiche « La page a expiré faute d’activité récente. Recommencez votre dernière action. ». Le second essai aboutit.
  - Réf. : 90 § 4.8 (419 en visite Inertia) · ErrorPageResponder
- [ ] **419 dans l’écran de jeu : avis en texte, jamais de toast** ⚠️
  - Faire : Dans un lobby, supprimer le cookie `tripleframes-session`, cliquer « Quitter le salon », puis confirmer.
  - Attendu : Le joueur reste dans le lobby. En haut de l’écran de jeu, un encadré (rôle note) affiche « La page a expiré faute d’activité récente. Recommencez votre dernière action. », sans aucun toast. Le geste suivant aboutit.
  - Réf. : 90 § 2.3 · use-flash-notice.ts
- [ ] **419 sur un chargement complet ou une requête JSON** ⚠️
  - Faire : `curl.exe -si -X POST -d 'locale=fr' http://127.0.0.1:8000/locale`, puis la même commande avec `-H 'Accept: application/json'`.
  - Attendu : Premier appel : statut 419 et du HTML dont les données de page Inertia désignent le composant `error` avec `status` 419 (titre « Page expirée » une fois rendu). Second appel : statut 419 et JSON `{"message": …}`, sans page.
  - Réf. : 90 § 4.8 · ErrorPageResponder::expectsJson
- [ ] **429 : limiteur de lecture dépassé** ⭐
  - Faire : Dans PowerShell : `1..95 | ForEach-Object { curl.exe -s -o NUL -w '%{http_code} ' http://127.0.0.1:8000/clock }`. Dans la même minute, ouvrir `/r/new` dans une fenêtre privée neuve sans `player_token` : elle partage le compteur de l’adresse. Réessayer une minute plus tard.
  - Attendu : La boucle affiche environ 90 × `200` (90 lectures par minute par défaut), puis `429`. La fenêtre privée montre la page « Trop de demandes » (« Trop de demandes en peu de temps. Patientez quelques instants avant de réessayer. »), au statut 429 avec un en-tête `Retry-After`. Au bout d’une minute, `/r/new` s’ouvre de nouveau.
  - Réf. : 90 § 4.8, 60 § 10.3 · throttle:game-read (90/min par défaut)
- [ ] **503 : mode maintenance**
  - Faire : Sans partie en cours, lancer `php artisan down --retry=60`, puis ouvrir `/` et `/legal/notice`. Finir par `php artisan up`.
  - Attendu : Statut 503, page « Service indisponible » avec « Le site est momentanément indisponible, sans doute le temps d’une mise à jour. Réessayez un peu plus tard. ». Les en-têtes portent `Retry-After: 60` et `X-Robots-Tag: noindex, nofollow`. Après `up`, tout revient.
  - Réf. : 90 § 4.8, § 5.2 · common.error.service_unavailable
- [ ] **500 : base de données arrêtée** ⚠️
  - Faire : Depuis le dossier Homestead, lancer `vagrant ssh -c 'sudo systemctl stop mysql'`, puis recharger `/` et `/up`. Relancer ensuite MySQL avec `vagrant ssh -c 'sudo systemctl start mysql'`, puis relancer `composer dev` (la file en base aura échoué entre-temps).
  - Attendu : `/` affiche la page « Erreur du serveur » (« Une erreur inattendue est survenue de notre côté. Réessayez dans un instant. ») au statut 500. Si ce rendu échoue à son tour, par exemple parce que le cache est encore en base, c’est la page 500 brute du framework : jamais une page blanche. `/up` répond 500. `laravel.log` contient l’exception.
  - Réf. : 90 § 4.8 (jamais une seconde panne) · DiagnoseDependencies
- [ ] **Langue et apparence de la page d’erreur**
  - Faire : Avec le cookie `locale=en`, ouvrir `/nimporte-quoi`. Choisir ensuite « Sombre » puis « Clair » et ouvrir `/r/ZZZZZZ`, une route de jeu. Inspecter `<html>`.
  - Attendu : La première page affiche « Page not found » et « Back to home », avec `<html lang="en">`, dans l’apparence choisie. La seconde s’affiche en clair, sans `data-appearance-forced` : une erreur levée dans une route de jeu n’est jamais forcée en sombre.
  - Réf. : 90 § 4.8 (étapes 1 et 3)
- [ ] **Une requête JSON ne reçoit jamais la page** ⚠️
  - Faire : `curl.exe -si -H 'Accept: application/json' http://127.0.0.1:8000/nimporte-quoi`
  - Attendu : Statut 404 et `Content-Type: application/json` avec `{"message": …}`, sans HTML ni composant Inertia.
  - Réf. : 90 § 4.8 · shouldRenderJsonWhen
- [ ] **Page d’erreur en portrait et au clavier**
  - Faire : À 375 px de large, ouvrir `/nimporte-quoi` et parcourir avec Tab. Remettre `APP_DEBUG=true` à la fin du groupe.
  - Attendu : La page ne défile pas horizontalement. Tab atteint « Retour à l’accueil », une cible d’au moins 44 px.
  - Réf. : 90 § 4.8, § 8

### 1.7 En-têtes HTTP, cookies et indexation

- [ ] **noindex et Referrer-Policy sur toute réponse** ⭐
  - Faire : Dans Réseau, regarder les en-têtes des requêtes document `/`, `/legal/notice`, `/r/new`, `/solo/new`, `/r/<CODE>` et `/nimporte-quoi`. Afficher le source de `/`.
  - Attendu : Chaque réponse porte `X-Robots-Tag: noindex, nofollow` et `Referrer-Policy: strict-origin-when-cross-origin`, y compris la 404. Le source ne contient aucune balise `<meta name="robots">` : l’en-tête seul décide.
  - Réf. : 90 § 5.2, § 5.3 · RobotsDirectives
- [ ] **Vary: Accept-Language seulement là où la langue change le contenu** ⭐
  - Faire : Comparer l’en-tête `Vary` de `/`, `/legal/notice`, `/legal/terms`, `/legal/privacy` et `/report-content` avec celui de `/r/new` et `/solo/new`. Avec `APP_DEBUG=false`, regarder aussi `/nimporte-quoi`.
  - Attendu : Les cinq premières pages renvoient une seule ligne `Vary: X-Inertia, Accept-Language`. `/r/new`, `/solo/new` et la page d’erreur traduite ne renvoient que `Vary: X-Inertia` (en debug, la 404 du framework n’en porte aucun).
  - Réf. : 90 § 4.2, § 5.3 · VaryOnLanguage
- [ ] **Levée de l’indexation : quatre routes seulement** ⚠️
  - Faire : Poser `SITE_INDEXABLE=true` dans `.env` et lancer `php artisan config:clear`. Vérifier avec `curl.exe -sI` les adresses `/`, `/legal/notice`, `/legal/terms`, `/legal/privacy`, `/report-content`, `/r/new`, `/clock` et `/nimporte-quoi`. Retirer ensuite la ligne (absente du `.env` d’origine).
  - Attendu : `/` et les trois pages légales (200, GET ou HEAD) n’ont plus de `X-Robots-Tag`. `/report-content`, `/r/new`, `/clock` et la 404 gardent `noindex, nofollow`. En production au J1, la variable reste fausse.
  - Réf. : 90 § 5.2, 100 § 12 · config('app.indexable')
- [ ] **Cookies posés à la première visite, sans player_token avant un siège**
  - Faire : Dans une fenêtre privée neuve, ouvrir `/`, puis `/r/new`, `/solo/new` et une page `/r/<CODE>/join`, en regardant Application > Cookies. Cliquer ensuite « Créer le salon ».
  - Attendu : Avant le clic, on trouve seulement `tripleframes-session` (HttpOnly), `XSRF-TOKEN`, `locale` (en clair, 1 an, posé quand la langue du navigateur est fr ou en) et `appearance=system` (posé par la page, 365 jours, avec son miroir dans le stockage local). Aucun `player_token` : un GET n’en crée jamais. Après « Créer le salon », `player_token` apparaît (HttpOnly, 30 jours). Tous ces cookies figurent dans le tableau de la page de confidentialité.
  - Réf. : 90 § 4.6, 40 C4 I4.1
- [ ] **`no-store` sur l’état de jeu et les images**
  - Faire : En solo (`/solo`), filtrer Réseau sur `state`. Pendant une manche, filtrer ensuite sur `/f/`. En salon, regarder aussi `GET /r/<CODE>/state` quand il part (resynchronisation, par exemple après un rechargement).
  - Attendu : Les réponses `GET /solo/state` et `GET /r/<CODE>/state` portent `Cache-Control: no-store, private`. Les images `/f/<32 hexa>?expires=…&signature=…` portent `Content-Type: image/webp`, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow`, `X-Content-Type-Options: nosniff` et `Cross-Origin-Resource-Policy: same-origin`, sans `ETag`, `Last-Modified`, `Content-Disposition` ni `Set-Cookie`.
  - Réf. : 60 § 7.3, C8 · FrameImageResponse · RespondsWithSoloState · RoomStateController
- [ ] **Titre d’onglet sans code de salon** ⚠️
  - Faire : Regarder l’onglet du lobby `/r/<CODE>` et celui du solo.
  - Attendu : Les onglets s’intitulent « Salon - TripleFrames » et « Jouer en solo - TripleFrames ». Le code de salon et les titres de films n’y figurent jamais.
  - Réf. : 90 § 5.5

### 1.8 Routes techniques : `/clock`, `/up`, `/robots.txt`, `/ops/probe`

- [ ] **Horloge serveur `/clock`**
  - Faire : Dans une fenêtre privée neuve, ouvrir http://127.0.0.1:8000/clock et recharger plusieurs fois. Regarder Application > Cookies.
  - Attendu : La réponse est un JSON limité à `{"serverNow":"AAAA-MM-JJTHH:MM:SS.mmmZ"}` (UTC, millisecondes), dont la valeur avance à chaque rechargement. Les en-têtes portent `Cache-Control: no-store, private` et `X-Robots-Tag: noindex, nofollow`. Aucun `Set-Cookie`, et aucun cookie n’est créé.
  - Réf. : 60 § 2.4, § 10.1 · ClockController
- [ ] **Poignée de main d’horloge à l’ouverture d’un écran de jeu**
  - Faire : Ouvrir un lobby (ou `/solo`) avec Réseau filtré sur `clock`.
  - Attendu : Des `GET /clock` successifs (trois par défaut) partent au montage de la page, l’un après l’autre, tous en 200.
  - Réf. : 60 § 2.4 · lib/game/server-clock.ts · clockSamples
- [ ] **Santé `/up` et `robots.txt`**
  - Faire : Ouvrir `/up`, puis `/robots.txt`.
  - Attendu : `/up` répond 200 « Application up » avec `X-Robots-Tag: noindex, nofollow`. `/robots.txt` contient exactement `User-agent: *`, `Disallow: /admin` et `Disallow: /f/`, et jamais `Disallow: /`.
  - Réf. : 90 § 5.4, 100 § 12, § 15
- [ ] **Sondes `/ops/probe` fermées sans jeton** ⚠️
  - Faire : `curl.exe -si http://127.0.0.1:8000/ops/probe/worker-game`. Poser ensuite `OPS_PROBE_TOKEN=essai-local-123` dans `.env` et appeler la même adresse avec `-H 'X-Probe-Token: essai-local-123'`, puis avec un jeton faux et avec `/ops/probe/inconnue`. Lancer `php artisan schedule:work` (avec `composer dev` actif pour la file) et rappeler la sonde avec le bon jeton. Retirer le jeton à la fin.
  - Attendu : Sans jeton : 404 à corps vide, avec `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow` et aucun cookie. Avec le bon jeton : `503 {"status":"stale"}` tant que le battement du worker `game` n’a pas été écrit, puis `200 {"status":"ok"}` moins d’une minute après le lancement de `schedule:work` (battement toutes les 30 s), sans aucune autre donnée. Un jeton faux ou une sonde inconnue renvoie la même 404.
  - Réf. : 100 § 15 · EnsureProbeToken · ProbeResponse · WorkerHeartbeat

### 1.9 Hors périmètre (non livré, ne pas essayer)

- Formulaire « Signaler un contenu » (`POST /report-content`, `takedown.store`) et accusé de réception par e-mail : J2, car il faut le SMTP et la file de retrait du back-office. Au J1, la page est statique et sans champ.
- Textes légaux définitifs (éditeur, hébergeur, durées de conservation, vos droits, CGU complètes) et retrait du bandeau « Texte provisoire, sans valeur contractuelle. » : J2, textes commandés au conseil. Les marqueurs « [À FOURNIR …] » sont attendus.
- Logo officiel TMDB `public/brand/tmdb.svg` : à déposer par le porteur (geste 10). Tant qu’il manque, seule la mention textuelle s’affiche et la requête du logo répond 404 : ce n’est pas un défaut.
- Adresse de contact publiée en production (`LEGAL_CONTACT_EMAIL`) : attend l’achat du domaine `<DOMAINE>`.
- Levée de l’indexation en production, balise canonique, balises Open Graph et `hreflang` : J2. La variable `SITE_INDEXABLE` peut quand même s’essayer en local (item dédié).
- Page publique de suivi d’une demande de retrait : écartée, elle n’existera pas.
- Préchargement du dictionnaire à l’ouverture du menu de langue (05 § Changement de langue) : non livré côté client. Le repli prévu par la spec, lui, est livré : un état d’attente pendant l’unique aller-retour.
- Troisième langue d’interface et écritures de droite à gauche : hors v1 (`dir` vaut `ltr` partout).
- Diffusion en temps réel du bandeau de maintenance : pas prévue au J1. Le bandeau n’apparaît qu’à la réponse Inertia suivante.
- Bannière de consentement aux cookies et mesure d’audience : absentes par conception, puisque tous les cookies sont nécessaires ou mémorisent une préférence.
- Arrêt du service (`site:close`) : J2.
- Écrans de compte hérités du starter (`/login`, `/dashboard`, `/settings/*`) : sur mobile, la feuille de la barre latérale est en anglais et le pied de page tombe sous la ligne de flottaison. Dette assumée, réécriture au J2 (90 § 2.5) : ne pas la signaler comme une régression.

## 2. Comptes, connexion et réglages du compte

Cette section couvre les comptes au jalon 1, tels que livrés sur develop au 28/09 : connexion Fortify et ses limites, interrupteurs d'inscription et de passkeys, inscription, vérification d'e-mail, mot de passe oublié, confirmation du mot de passe, 2FA, passkeys, les trois écrans de réglages (profil, sécurité, apparence), le tableau de bord du starter, la langue du compte et celle des e-mails. En local, l'inscription et les passkeys sont OUVERTES par défaut ; le groupe « Interrupteurs » dit comment tester l'état fermé, qui sera celui de la production. Le back-office n'est pas couvert ici.

**Prérequis**

- `composer dev` lancé : l'application est servie sur http://127.0.0.1:8000. Base seedée par `php artisan migrate:fresh --seed` (si la base de dev porte déjà de la curation, `php artisan backup:snapshot` d'abord, code 0 exigé, règle 12). Le seed crée trois comptes de démonstration, tous avec le mot de passe `password`, une adresse déjà vérifiée et aucune 2FA : player@tripleframes.test (« Joueur Démo », langue fr), curator@tripleframes.test (« Curateur Démo », fr, nom réel « Camille Démo-Curation ») et admin@tripleframes.test (« Admin Démo », langue **en**, nom réel « Alex Démo-Administration »).
- Lire les e-mails : `MAIL_MAILER=log`. Chaque e-mail est écrit dans `storage/logs/laravel.log`, pendant la requête (les notifications de Fortify ne passent pas par la file). Le corps est décodé et lisible, mais un objet français apparaît encodé dans l'en-tête (`Subject: =?utf-8?Q?R=C3=A9initialisez_votre_mot_de_passe?=`). Copier les liens depuis la partie `Content-Type: text/plain` : dans la partie HTML, les `&` du lien sont écrits `&amp;`.
- Suivre le journal en direct : `php artisan pail` ne marche PAS sur ce poste (« The [pcntl] extension is required to run Pail. »). Utiliser `tail -f storage/logs/laravel.log` dans Git Bash, ou `Get-Content storage\logs\laravel.log -Wait -Tail 40` dans PowerShell.
- Deux navigateurs, ou un navigateur et une fenêtre privée : la session (`tripleframes-session`), la langue (cookie `locale`) et l'apparence (cookie `appearance`) vivent dans des cookies. Garder les DevTools à portée de main (Réseau pour les en-têtes, Application › Cookies).
- Pages d'erreur traduites (403, 404, 419, 429) : passer `APP_DEBUG=false` dans `.env` le temps de ces essais, puis remettre `true`. Avec `APP_DEBUG=true`, c'est la page anglaise du framework qui s'affiche, souvent dans une fenêtre superposée.
- Changer une variable de `.env` : `php artisan serve` redémarre seul et affiche « Environment modified. Restarting server... ». Lancer aussi `php artisan config:clear` par sécurité ; en cas de doute, relancer `composer dev`.
- Pour la 2FA, une application TOTP sur téléphone : Google Authenticator, Aegis, 1Password, Microsoft Authenticator…
- Pour les passkeys, au choix : Windows Hello, une clé de sécurité, ou l'authentificateur virtuel de Chrome (DevTools › ⋮ › More tools › WebAuthn › « Enable virtual authenticator environment », puis « Add »). Les passkeys ne marchent PAS sur 127.0.0.1 : voir le groupe « Passkeys ».
- Les gestes destructeurs (changer de mot de passe ou d'adresse, activer la 2FA, réinitialiser) se font sur le compte de test créé au groupe « Inscription ». Pour rendre `password` et leur état d'origine aux comptes de démonstration : `php artisan db:seed --class=DemoAccountsSeeder`. Il ne touche que `users` et `user_consent`, jamais le catalogue ; il ne désactive ni une 2FA ni une passkey : les retirer à la main.

### 2.1 Connexion et déconnexion

- [ ] **Écran de connexion en local** ⭐
  - Faire : Déconnecté, ouvrir /login.
  - Attendu : Titre « Connexion à votre compte », description « Saisissez votre adresse e-mail et votre mot de passe pour vous connecter ». En haut, si le navigateur gère WebAuthn : le bouton « Se connecter avec une passkey » et le séparateur « Ou continuez avec votre adresse e-mail » (affiché en capitales). Puis les champs « Adresse e-mail » (exemple « adresse@exemple.fr ») et « Mot de passe », le lien « Mot de passe oublié ? », la case « Se souvenir de moi », le bouton « Se connecter », et la ligne « Pas encore de compte ? Créer un compte ». Onglet « Connexion - TripleFrames ». Le pied de page légal est plus bas, sous la ligne de flottaison.
  - Réf. : 40 § 8.2 · FortifyServiceProvider::configureViews · pages/auth/login.tsx
- [ ] **Se connecter avec un compte de démonstration** ⭐
  - Faire : Sur /login, saisir player@tripleframes.test et `password`, puis « Se connecter ».
  - Attendu : Redirection vers /dashboard, fil d'Ariane « Tableau de bord ». Barre latérale : groupe « Plateforme » avec le lien « Tableau de bord » ; en pied, « Joueur Démo » avec ses initiales et un chevron. Le pied de page légal est sous le contenu.
  - Réf. : config/fortify.php (home = /dashboard) · routes/web.php · app-sidebar.tsx
- [ ] **Refuser des identifiants faux ou une adresse inconnue** ⚠️
  - Faire : Sur /login, saisir player@tripleframes.test avec le mot de passe `mauvais`, puis « Se connecter ». Recommencer avec inconnu@exemple.fr.
  - Attendu : On reste sur /login. Sous « Adresse e-mail » : « Ces identifiants ne correspondent à aucun compte. », le même message dans les deux cas. L'adresse reste saisie, le mot de passe est vidé. Aucune connexion.
  - Réf. : lang/fr/auth.php (failed)
- [ ] **Se connecter avec une adresse en majuscules**
  - Faire : Sur /login, saisir PLAYER@TRIPLEFRAMES.TEST et `password`.
  - Attendu : Connexion réussie : l'adresse est mise en minuscules avant la vérification.
  - Réf. : config/fortify.php (lowercase_usernames)
- [ ] **Afficher et masquer le mot de passe**
  - Faire : Sur /login, taper un mot de passe, puis cliquer deux fois l'icône œil à droite du champ.
  - Attendu : Le mot de passe passe en clair, puis redevient masqué. Le nom accessible du bouton alterne entre « Afficher le mot de passe » et « Masquer le mot de passe ». L'icône n'est pas atteignable au clavier (Tab la saute).
  - Réf. : components/password-input.tsx · lang/fr/account.php (fields)
- [ ] **Revenir à la page demandée après connexion**
  - Faire : Déconnecté, taper l'adresse /settings/profile, puis se connecter avec player@tripleframes.test.
  - Attendu : La première visite renvoie vers /login. Après la connexion, on arrive directement sur /settings/profile, et non sur /dashboard.
  - Réf. : routes/settings.php (auth) · Fortify LoginResponse (intended)
- [ ] **Pages invitées refusées à un compte connecté** ⚠️
  - Faire : Connecté, taper /login, puis /forgot-password, puis /register.
  - Attendu : Chaque adresse redirige vers /dashboard.
  - Réf. : Fortify (middleware guest)
- [ ] **Se déconnecter** ⭐
  - Faire : Connecté, sur /dashboard, cliquer le nom en pied de barre latérale, puis « Se déconnecter » dans le menu. Taper ensuite /dashboard.
  - Attendu : Le menu montre le nom, l'adresse, « Réglages » et « Se déconnecter ». La déconnexion ramène à l'accueil /. Retaper /dashboard renvoie vers /login.
  - Réf. : components/user-menu-content.tsx · Fortify LogoutResponse
- [ ] **Se souvenir de moi**
  - Faire : Se connecter en cochant « Se souvenir de moi ». Dans DevTools › Application › Cookies, supprimer le seul cookie `tripleframes-session`, puis recharger /dashboard. Refaire l'essai sans cocher la case.
  - Attendu : Case cochée : un cookie `remember_web_…` existe, et après suppression de la session on reste connecté. Case non cochée : après suppression de la session, on est renvoyé vers /login.
  - Réf. : Fortify · RecordLastLogin (reprise par jeton)
- [ ] **Parcours clavier de la connexion**
  - Faire : Ouvrir /login, puis enchaîner Tab. Cocher la case avec Espace. Dans le champ mot de passe, valider avec Entrée.
  - Attendu : Le focus est d'abord dans « Adresse e-mail » (focus automatique), puis va à « Mot de passe », « Se souvenir de moi », « Se connecter », « Mot de passe oublié ? » et « Créer un compte », avant le logo, le bouton passkey et le pied de page. Espace coche la case. Entrée envoie le formulaire. L'anneau de focus est visible à chaque étape.
  - Réf. : pages/auth/login.tsx (tabIndex)
- [ ] **Limitation de débit à la connexion** ⚠️
  - Faire : Avec `APP_DEBUG=false`, sur /login, envoyer 6 fois de suite en moins d'une minute un mauvais mot de passe pour player@tripleframes.test. Essayer ensuite une autre adresse. Attendre environ 1 minute et réessayer.
  - Attendu : Les 5 premiers envois répondent « Ces identifiants ne correspondent à aucun compte. ». Le 6e affiche la page « Trop de demandes » : « Trop de demandes en peu de temps. Patientez quelques instants avant de réessayer. », avec « Retour à l’accueil ». Une autre adresse n'est pas bloquée, car la clé est l'adresse plus l'IP. Au bout d'une minute, on peut réessayer. Le message `auth.throttle` (« Trop de tentatives de connexion… ») n'apparaît jamais : c'est le limiteur nommé `login` qui répond.
  - Réf. : FortifyServiceProvider::configureRateLimiting (login, 5/min) · 90 § 4.8
- [ ] **Page expirée (419) à l'envoi d'un formulaire** ⚠️
  - Faire : Avec `APP_DEBUG=false`, ouvrir /login. Dans DevTools › Application › Cookies, supprimer `XSRF-TOKEN` et `tripleframes-session`, puis envoyer le formulaire rempli.
  - Attendu : Retour sur /login avec un toast « La page a expiré faute d’activité récente. Recommencez votre dernière action. ». Un nouvel envoi aboutit.
  - Réf. : 90 § 4.8 · ErrorPageResponder
- [ ] **Écrans de compte jamais indexables**
  - Faire : DevTools › Réseau. Charger /login, /register, /settings/profile et /dashboard, puis lire les en-têtes de réponse.
  - Attendu : `X-Robots-Tag: noindex, nofollow` et `Referrer-Policy: strict-origin-when-cross-origin` sur chacune de ces pages.
  - Réf. : 90 § 5 · RobotsDirectives

### 2.2 Interrupteurs d'inscription et de passkeys

- [ ] **État par défaut en local : tout est ouvert** ⭐
  - Faire : Vérifier que `.env` ne déclare ni `ACCOUNTS_REGISTRATION_OPEN` ni `ACCOUNTS_PASSKEYS_ENABLED`. Déconnecté, ouvrir /, puis /login, puis /.well-known/passkey-endpoints. Se connecter et revenir sur /.
  - Attendu : Déconnecté, l'en-tête de l'accueil montre « Se connecter » et « Créer un compte », puis le sélecteur de langue (icône et « Français ») et le bouton d'apparence. /login montre « Créer un compte » et le bouton passkey. /.well-known/passkey-endpoints renvoie du JSON : `enroll` et `manage` valent http://127.0.0.1:8000/settings/security. Connecté, l'en-tête de l'accueil montre « Tableau de bord » à la place des deux liens.
  - Réf. : 40 § 8.2 · AccountSwitches (liste blanche local/testing) · public-header.tsx
- [ ] **Fermer l'inscription** ⭐
  - Faire : Ajouter `ACCOUNTS_REGISTRATION_OPEN=false` dans `.env`, puis `php artisan config:clear`. Recharger / déconnecté puis connecté, puis ouvrir /login et /register.
  - Attendu : Sur l'accueil, aucun lien de compte : ni « Se connecter » ni « Créer un compte », et pas non plus « Tableau de bord » une fois connecté. /login ne montre plus « Pas encore de compte ? Créer un compte », mais reste accessible par son adresse et permet de se connecter. /register renvoie 404 : « Page introuvable » avec `APP_DEBUG=false`, la page 404 du framework sinon.
  - Réf. : 40 § 8.2 · EnforceAccountSwitches · HandleInertiaRequests (accountsOpen)
- [ ] **Envoi d'inscription refusé une fois l'inscription fermée** ⚠️
  - Faire : Inscription ouverte, remplir /register sans l'envoyer. Passer `ACCOUNTS_REGISTRATION_OPEN=false`, puis envoyer le formulaire. Contrôler dans `php artisan tinker` : `User::where('email', '<adresse saisie>')->exists()`.
  - Attendu : L'envoi reçoit une réponse 404. La commande tinker répond `false` : aucun compte n'a été créé.
  - Réf. : EnforceAccountSwitches::REGISTRATION_ROUTES (register.store)
- [ ] **Fermer les passkeys** ⭐
  - Faire : Ajouter `ACCOUNTS_PASSKEYS_ENABLED=false` dans `.env`, puis `php artisan config:clear`. Déconnecté, ouvrir /login, /.well-known/passkey-endpoints et /passkeys/login/options. Se connecter, puis ouvrir /user/confirm-password, /settings/security et /user/passkeys/options.
  - Attendu : /login n'a plus « Se connecter avec une passkey » ni son séparateur. /.well-known/passkey-endpoints et /passkeys/login/options renvoient 404. Connecté : /user/confirm-password n'a plus « Confirmer avec une passkey », /settings/security n'a plus de section « Passkeys », et /user/passkeys/options renvoie 404.
  - Réf. : 40 § 8.2 · SecurityController (canManagePasskeys) · routes/settings.php
- [ ] **Route de passkey sous authentification ouverte en invité** ⚠️
  - Faire : Passkeys fermées, puis ouvertes : déconnecté, ouvrir /user/passkeys/options.
  - Attendu : Dans les deux états, la même redirection vers /login. Le code de retour n'apprend rien de l'état de l'interrupteur.
  - Réf. : 40 § 8.2 (rang dans la pile, E14-5)
- [ ] **Seuls `true` et `false` sont lus** ⚠️
  - Faire : Essayer successivement `ACCOUNTS_REGISTRATION_OPEN=0`, `=no`, `=` (vide), `=true`, puis `=FALSE`, en rechargeant /login à chaque fois après `php artisan config:clear`.
  - Attendu : Avec 0, no, vide ou true, le lien « Créer un compte » est présent : une valeur qui n'est ni true ni false vaut « non déclarée », donc ouverte en local. `false` ferme, quelle que soit sa casse (`FALSE`, `False`, et aussi `(false)`).
  - Réf. : AccountSwitches::read · config/accounts.php · env()
- [ ] **Les deux interrupteurs sont indépendants** ⚠️
  - Faire : Poser `ACCOUNTS_REGISTRATION_OPEN=false` sans ligne passkeys, recharger /login. Poser ensuite l'inverse.
  - Attendu : Premier cas : le lien d'inscription disparaît, le bouton passkey reste. Second cas : le bouton passkey disparaît, le lien reste. À la fin, retirer les deux lignes de `.env` pour revenir à l'état ouvert.
  - Réf. : 40 § 8.2

### 2.3 Inscription (ouverte en local)

- [ ] **Créer le compte de test** ⭐
  - Faire : Déconnecté, depuis l'accueil, cliquer « Créer un compte », ou ouvrir /register. Saisir Nom « Testeur Recette », Adresse e-mail testeur@exemple.fr, Mot de passe `motdepasse1` dans les deux champs, puis « Créer le compte ».
  - Attendu : Le formulaire s'intitule « Créer un compte », avec la description « Saisissez vos informations pour créer votre compte », les champs « Nom » (exemple « Nom complet »), « Adresse e-mail », « Mot de passe » et « Confirmer le mot de passe », aucune case à cocher, et le lien « Vous avez déjà un compte ? Se connecter ». Onglet « Inscription - TripleFrames ». Après l'envoi, on est connecté et renvoyé vers /email/verify, parce que l'adresse n'est pas vérifiée. Un e-mail de vérification apparaît dans `storage/logs/laravel.log`. Le nouveau compte est en anglais : voir l'item suivant.
  - Réf. : 40 § 8.1 · CreateNewUser · pages/auth/register.tsx
- [ ] **Un nouveau compte naît en anglais** ⚠️
  - Faire : Faire l'inscription précédente depuis une interface en français. Lire /email/verify, puis l'e-mail de vérification dans le journal.
  - Attendu : La page s'affiche en anglais : « Email verification », « Please verify your email address by clicking on the link we just emailed to you. », « Resend verification email », « Log out ». L'e-mail aussi : objet « Verify your email address ». Cause : `users.locale` vaut `en` par défaut (10 § 5.1), et la langue du compte prime sur le cookie `locale`. C'est le comportement actuel, à noter : l'ouverture des comptes est un sujet du J2. Pour la suite, ouvrir l'accueil / et choisir « Français » dans le sélecteur de langue de l'en-tête, ce qui écrit `users.locale = fr`.
  - Réf. : 10 § 5.1 · 05 § Négociation · User::$attributes · SetLocale
- [ ] **Refuser une adresse déjà utilisée** ⚠️
  - Faire : Sur /register, saisir player@tripleframes.test, avec un nom et un mot de passe valides.
  - Attendu : Sous « Adresse e-mail » : « La valeur du champ adresse e-mail est déjà utilisée. ». Aucun compte créé.
  - Réf. : ProfileValidationRules::emailRules
- [ ] **Refuser un mot de passe trop court** ⚠️
  - Faire : Sur /register, saisir `abc1234` (7 caractères) dans les deux champs de mot de passe.
  - Attendu : Sous « Mot de passe » : « Le champ mot de passe doit contenir au moins 8 caractères. ». C'est la règle locale ; la règle de production n'est active qu'en `APP_ENV=production`.
  - Réf. : PasswordValidationRules · AppServiceProvider::configureDefaults
- [ ] **Refuser une confirmation différente** ⚠️
  - Faire : Sur /register, saisir `motdepasse1` dans « Mot de passe » et `motdepasse2` dans « Confirmer le mot de passe ».
  - Attendu : Sous « Mot de passe » : « La confirmation du champ mot de passe ne correspond pas. ».
  - Réf. : PasswordValidationRules (confirmed)
- [ ] **Adresse enregistrée en minuscules à l'inscription**
  - Faire : Créer un second compte de test avec l'adresse Testeur2@Exemple.FR, puis ouvrir /settings/profile (accessible sans vérification).
  - Attendu : Le champ « Adresse e-mail » affiche testeur2@exemple.fr.
  - Réf. : config/fortify.php (lowercase_usernames) · RegisteredUserController

### 2.4 Vérification de l'adresse e-mail

- [ ] **Accès restreint tant que l'adresse n'est pas vérifiée** ⭐
  - Faire : Connecté avec le compte de test non vérifié, interface repassée en français par l'accueil, taper /dashboard, /settings/security, /settings/appearance, puis /settings/profile.
  - Attendu : Les trois premières adresses redirigent vers /email/verify : titre « Vérification de l’adresse e-mail », description « Vérifiez votre adresse e-mail en cliquant sur le lien que nous venons de vous envoyer. », bouton « Renvoyer l’e-mail de vérification », lien « Se déconnecter ». /settings/profile reste accessible et affiche « Votre adresse e-mail n’est pas vérifiée. Cliquez ici pour renvoyer l’e-mail de vérification. ».
  - Réf. : routes/settings.php (verified sur sécurité et apparence) · routes/web.php (dashboard)
- [ ] **Renvoyer l'e-mail de vérification** ⭐
  - Faire : Sur /email/verify, cliquer « Renvoyer l’e-mail de vérification », puis lire le journal.
  - Attendu : Message vert « Un nouveau lien de vérification a été envoyé à l’adresse e-mail fournie lors de votre inscription. ». Nouvel e-mail en français, car la langue du compte vient d'être réglée : objet « Vérifiez votre adresse e-mail » (encodé dans l'en-tête Subject), « Bonjour ! », « Cliquez sur le bouton ci-dessous pour vérifier votre adresse e-mail. », bouton « Vérifier l’adresse e-mail », « Si vous n’avez pas créé de compte, vous pouvez ignorer cet e-mail. », « Cordialement, ».
  - Réf. : 05 § E-mails · User::preferredLocale · lang/fr.json
- [ ] **Vérifier par le lien reçu** ⭐
  - Faire : Dans la partie texte du dernier e-mail du journal, copier le lien http://127.0.0.1:8000/email/verify/{id}/{hash}?expires=…&signature=… et l'ouvrir dans le navigateur connecté.
  - Attendu : Redirection vers /dashboard?verified=1. /settings/security et /settings/appearance deviennent accessibles, /settings/security après confirmation du mot de passe. Le lien vaut 60 minutes.
  - Réf. : Fortify VerifyEmailController
- [ ] **Lien ouvert sans être connecté** ⚠️
  - Faire : Demander un nouveau lien, se déconnecter, puis ouvrir le lien dans ce navigateur. Se connecter avec le compte de test.
  - Attendu : Le lien renvoie d'abord vers /login. Après la connexion, la vérification aboutit et l'on arrive sur /dashboard?verified=1.
  - Réf. : Fortify (auth + signed)
- [ ] **Lien altéré, puis lien déjà utilisé** ⚠️
  - Faire : Avec `APP_DEBUG=false`, modifier un caractère du paramètre `signature` d'un lien et l'ouvrir. Rouvrir ensuite un lien valide déjà utilisé.
  - Attendu : Lien altéré : page « Accès refusé », « Vous n’avez pas l’autorisation d’ouvrir cette page. ». Lien déjà utilisé : redirection vers /dashboard?verified=1, sans erreur.
  - Réf. : 90 § 4.8 · Fortify VerifyEmailController
- [ ] **Lien ouvert connecté avec un autre compte** ⚠️
  - Faire : Avec `APP_DEBUG=false`, demander un lien pour le compte de test non vérifié, se déconnecter, se connecter avec player@tripleframes.test, puis ouvrir le lien.
  - Attendu : Page « Accès refusé ». Le compte de test reste non vérifié.
  - Réf. : Fortify EmailVerificationRequest::authorize
- [ ] **Écran de vérification pour un compte déjà vérifié** ⚠️
  - Faire : Connecté avec player@tripleframes.test, taper /email/verify.
  - Attendu : Redirection vers /dashboard.
  - Réf. : Fortify EmailVerificationPromptController
- [ ] **Renvoi limité à 6 par minute** ⚠️
  - Faire : Avec `APP_DEBUG=false`, sur /email/verify d'un compte non vérifié, cliquer 7 fois « Renvoyer l’e-mail de vérification » en moins d'une minute.
  - Attendu : Au 7e clic, la page « Trop de demandes » s'affiche.
  - Réf. : Fortify routes (limiter verification 6,1)
- [ ] **Se déconnecter depuis l'attente de vérification**
  - Faire : Sur /email/verify, cliquer « Se déconnecter ».
  - Attendu : Déconnexion et retour à l'accueil /.
  - Réf. : pages/auth/verify-email.tsx

### 2.5 Mot de passe oublié et réinitialisation

- [ ] **Demander un lien de réinitialisation** ⭐
  - Faire : Déconnecté, sur /login, cliquer « Mot de passe oublié ? ». Saisir player@tripleframes.test, puis « Envoyer le lien de réinitialisation ». La demande seule ne change aucun mot de passe.
  - Attendu : Page /forgot-password : titre « Mot de passe oublié », description « Saisissez votre adresse e-mail pour recevoir un lien de réinitialisation », champ « Adresse e-mail », ligne « Ou revenez à la connexion ». Après l'envoi, message vert « Le lien de réinitialisation vous a été envoyé par e-mail. ». Un e-mail est écrit dans le journal.
  - Réf. : pages/auth/forgot-password.tsx · lang/fr/passwords.php (sent)
- [ ] **E-mail de réinitialisation en français** ⭐
  - Faire : Lire dans le journal l'e-mail envoyé à player@tripleframes.test (langue du compte : fr).
  - Attendu : Objet « Réinitialisez votre mot de passe » (encodé dans l'en-tête Subject). Corps : « Bonjour ! », « Vous recevez cet e-mail car une demande de réinitialisation de mot de passe a été faite pour votre compte. », bouton « Réinitialiser le mot de passe », « Ce lien de réinitialisation expirera dans 60 minutes. », « Si vous n’avez pas demandé de réinitialisation, vous pouvez ignorer cet e-mail. », « Cordialement, ». Le lien pointe vers http://127.0.0.1:8000/reset-password/…?email=player%40tripleframes.test.
  - Réf. : 05 § E-mails · lang/fr.json · config/auth.php (expire 60)
- [ ] **E-mail dans la langue du compte, pas celle de l'écran** ⭐
  - Faire : Interface en français, demander un lien pour admin@tripleframes.test, dont la langue est en. Lire l'e-mail.
  - Attendu : E-mail entièrement en anglais : objet « Reset your password », « Hello! », bouton « Reset Password », « This password reset link will expire in 60 minutes. ». L'écran, lui, reste en français.
  - Réf. : 05 § E-mails · User implements HasLocalePreference
- [ ] **Réinitialiser le mot de passe** ⭐
  - Faire : Demander un lien pour le compte de test, ouvrir le lien du journal, saisir `nouveaumdp1` dans « Mot de passe » et « Confirmer le mot de passe », puis « Réinitialiser le mot de passe ». Se connecter avec l'ancien mot de passe, puis avec le nouveau.
  - Attendu : Page titrée « Réinitialiser le mot de passe », description « Saisissez votre nouveau mot de passe ci-dessous », « Adresse e-mail » pré-remplie en lecture seule. Après l'envoi : retour sur /login, non connecté, avec en vert sous le formulaire « Votre mot de passe a été réinitialisé. ». L'ancien mot de passe est refusé, le nouveau accepté.
  - Réf. : ResetUserPassword · pages/auth/reset-password.tsx · lang/fr/passwords.php (reset)
- [ ] **Réutiliser un lien déjà servi** ⚠️
  - Faire : Rouvrir le lien de réinitialisation déjà utilisé et envoyer un nouveau mot de passe valide.
  - Attendu : Sous « Adresse e-mail » : « Ce jeton de réinitialisation est invalide. ». Le mot de passe ne change pas.
  - Réf. : lang/fr/passwords.php (token)
- [ ] **Adresse inconnue** ⚠️
  - Faire : Sur /forgot-password, saisir inconnu@exemple.fr.
  - Attendu : Sous « Adresse e-mail » : « Aucun compte ne correspond à cette adresse e-mail. ». Aucun e-mail dans le journal.
  - Réf. : lang/fr/passwords.php (user)
- [ ] **Deux demandes rapprochées** ⚠️
  - Faire : Demander deux liens pour la même adresse à moins de 60 secondes d'écart.
  - Attendu : La seconde demande affiche, sous le champ, « Veuillez patienter avant de réessayer. », et aucun second e-mail n'est envoyé.
  - Réf. : config/auth.php (passwords.users.throttle 60) · lang/fr/passwords.php (throttled)
- [ ] **Demande vide** ⚠️
  - Faire : Sur /forgot-password, cliquer « Envoyer le lien de réinitialisation » sans rien saisir.
  - Attendu : Le navigateur bloque l'envoi (champ obligatoire). Si on force l'envoi (attribut `required` retiré dans les DevTools) : « Le champ adresse e-mail est obligatoire. ».
  - Réf. : lang/fr/validation.php (required, attributes.email)
- [ ] **Nouveau mot de passe invalide à la réinitialisation** ⚠️
  - Faire : Sur un lien de réinitialisation valide, envoyer `abc` dans les deux champs, puis deux valeurs différentes de 8 caractères ou plus.
  - Attendu : « Le champ mot de passe doit contenir au moins 8 caractères. », puis « La confirmation du champ mot de passe ne correspond pas. », sous « Mot de passe ». Le jeton reste utilisable : un envoi correct aboutit ensuite.
  - Réf. : ResetUserPassword · PasswordValidationRules

### 2.6 Réglages : profil

- [ ] **Ouvrir les réglages** ⭐
  - Faire : Connecté, ouvrir /settings, ou choisir « Réglages » dans le menu utilisateur.
  - Attendu : Redirection vers /settings/profile, onglet « Réglages du profil - TripleFrames », fil d'Ariane « Réglages du profil ». En-tête « Réglages », « Gérez votre profil et les réglages de votre compte ». Navigation « Profil », « Sécurité », « Apparence », l'entrée courante surlignée. Section « Profil » : « Modifiez votre nom et votre adresse e-mail », champs « Nom » et « Adresse e-mail » pré-remplis, bouton « Enregistrer ». Aucune section « Supprimer le compte ».
  - Réf. : 40 § 8.3 · routes/settings.php · layouts/settings/layout.tsx
- [ ] **Modifier le nom du compte** ⭐
  - Faire : Sur /settings/profile, remplacer le nom par « Testeur Renommé », puis « Enregistrer ».
  - Attendu : Toast « Profil mis à jour. ». Le pied de la barre latérale montre le nouveau nom et ses nouvelles initiales.
  - Réf. : ProfileController::update · lang/fr.json (Profile updated.)
- [ ] **Changer d'adresse e-mail**
  - Faire : Avec le compte de test vérifié, remplacer l'adresse par testeur-bis@exemple.fr et « Enregistrer ». Ouvrir « Sécurité », revenir sur « Profil » et cliquer le lien de renvoi. Lire le journal, puis ouvrir le lien.
  - Attendu : Toast « Profil mis à jour. », puis la mention « Votre adresse e-mail n’est pas vérifiée. Cliquez ici pour renvoyer l’e-mail de vérification. ». Aucun e-mail n'est parti au changement lui-même. « Sécurité » redirige vers /email/verify. Le clic affiche « Un nouveau lien de vérification a été envoyé à votre adresse e-mail. », et un e-mail part vers la nouvelle adresse. Le lien la rend vérifiée.
  - Réf. : ProfileController::update (email_verified_at = null) · pages/settings/profile.tsx
- [ ] **Refuser une adresse déjà prise** ⚠️
  - Faire : Sur /settings/profile, saisir curator@tripleframes.test, puis « Enregistrer ».
  - Attendu : Sous « Adresse e-mail » : « La valeur du champ adresse e-mail est déjà utilisée. ». Rien n'est enregistré, et le menu garde l'ancienne adresse.
  - Réf. : ProfileUpdateRequest · ProfileValidationRules
- [ ] **Refuser un nom trop long** ⚠️
  - Faire : Sur /settings/profile, coller un nom de 256 caractères, puis « Enregistrer ».
  - Attendu : Sous « Nom » : « Le champ nom ne doit pas contenir plus de 255 caractères. ». Rien n'est enregistré.
  - Réf. : ProfileValidationRules::nameRules (max:255)
- [ ] **Adresse en majuscules saisie dans le profil** ⚠️
  - Faire : Avec le compte de test, remplacer l'adresse par Testeur-Maj@Exemple.FR et « Enregistrer ». Se déconnecter, puis se reconnecter en tapant testeur-maj@exemple.fr.
  - Attendu : Contrairement à l'inscription, l'adresse est enregistrée telle quelle, majuscules comprises (visible dans le champ et le menu). La connexion en minuscules marche quand même, la comparaison MySQL ignorant la casse. Écart à signaler si gênant.
  - Réf. : ProfileController::update · config/fortify.php (lowercase_usernames, inscription et connexion seules)
- [ ] **Réenregistrer sans changer d'adresse** ⚠️
  - Faire : Compte vérifié : changer seulement le nom, puis « Enregistrer ».
  - Attendu : L'adresse reste vérifiée : aucune mention « Votre adresse e-mail n’est pas vérifiée. », et « Sécurité » reste accessible.
  - Réf. : ProfileController::update (isDirty email)
- [ ] **Le nom réel n'apparaît jamais côté compte** ⭐
  - Faire : Se connecter avec curator@tripleframes.test, ouvrir /settings/profile, puis afficher le code source (Ctrl+U) et chercher « Camille ». Refaire l'essai avec admin@tripleframes.test en cherchant « Alex ».
  - Attendu : Le champ « Nom » vaut « Curateur Démo » (ou « Admin Démo »). Le code source, y compris le bloc `<script data-page="app" type="application/json">` qui porte `auth.user`, ne contient aucune occurrence du nom réel.
  - Réf. : 40 § 8.4 · User #[Hidden] (real_name) · D12 du 23/09
- [ ] **Compte privilégié hors du back-office**
  - Faire : Se connecter avec curator@tripleframes.test (sans 2FA), puis parcourir /dashboard, /settings/profile, /settings/security et /settings/appearance.
  - Attendu : Tout s'ouvre comme pour un joueur : aucune demande de 2FA, aucun lien vers /admin dans la barre latérale. La porte 2FA ne concerne que /admin (section back-office).
  - Réf. : config/fortify.php (home) · EnsurePrivilegedTwoFactor (groupe /admin seul)

### 2.7 Réglages : sécurité, confirmation et mot de passe

- [ ] **Confirmation du mot de passe à l'entrée de Sécurité** ⭐
  - Faire : Juste après une connexion, cliquer « Sécurité ». Saisir le mot de passe du compte, puis « Confirmer le mot de passe ».
  - Attendu : Passage par /user/confirm-password : titre « Confirmer le mot de passe », description « Cette zone de l’application est protégée. Confirmez votre mot de passe pour continuer. », champ « Mot de passe ». Au-dessus, passkeys ouvertes et navigateur compatible : « Confirmer avec une passkey » et le séparateur « Ou confirmez avec votre mot de passe ». Après validation, arrivée sur /settings/security (onglet « Réglages de sécurité - TripleFrames »).
  - Réf. : routes/settings.php (RequirePassword) · pages/auth/confirm-password.tsx
- [ ] **Mauvais mot de passe à la confirmation** ⚠️
  - Faire : Sur /user/confirm-password, saisir `mauvais`.
  - Attendu : Sous le champ : « Le mot de passe fourni est incorrect. ». On reste sur la page.
  - Réf. : lang/fr.json (The provided password was incorrect.)
- [ ] **Durée de validité de la confirmation** ⚠️
  - Faire : Revenir sur « Sécurité » dans la foulée. Poser ensuite `AUTH_PASSWORD_TIMEOUT=30` dans `.env`, lancer `php artisan config:clear`, reconfirmer, attendre 30 s et recharger /settings/security. Garder la ligne pour l'item suivant, puis la retirer.
  - Attendu : Aucune redemande dans la foulée : la confirmation vaut 3 h par défaut. Avec 30 s, le rechargement renvoie vers /user/confirm-password.
  - Réf. : config/auth.php (password_timeout)
- [ ] **Confirmation expirée en cours de geste** ⚠️
  - Faire : Avec `AUTH_PASSWORD_TIMEOUT=30`, confirmer, rester sur /settings/security sans recharger pendant 35 s, puis cliquer « Activer la 2FA ». Reconfirmer, attendre encore 35 s, puis « Ajouter une passkey » et « Enregistrer la passkey » (hôte préparé comme au groupe « Passkeys »). Retirer la ligne de `.env` à la fin.
  - Attendu : « Activer la 2FA » mène à /user/confirm-password, puis ramène sur /settings/security : il faut recliquer. L'enregistrement de passkey affiche sous le formulaire « Password confirmation required. », en anglais, non traduit : écart à la règle 4 à signaler.
  - Réf. : RequirePassword (réponse JSON 423 codée en dur) · Fortify (password.confirm)
- [ ] **Changer son mot de passe** ⭐
  - Faire : Avec le compte de test, dans la section « Modifier le mot de passe », remplir « Mot de passe actuel », « Nouveau mot de passe » et « Confirmer le mot de passe », puis « Enregistrer ». Se déconnecter et se reconnecter.
  - Attendu : Description de la section : « Utilisez un mot de passe long et aléatoire pour protéger votre compte ». Après l'envoi : toast « Mot de passe mis à jour. » et les trois champs vidés. La reconnexion marche avec le nouveau mot de passe et échoue avec l'ancien.
  - Réf. : SecurityController::update · lang/fr.json (Password updated.)
- [ ] **Les autres sessions restent ouvertes** ⚠️
  - Faire : Connecté au compte de test dans deux navigateurs, changer le mot de passe dans le premier, puis recharger /dashboard dans le second.
  - Attendu : Le second navigateur reste connecté : aucune invalidation des autres sessions au J1. À signaler si ce n'est pas voulu.
  - Réf. : SecurityController::update (aucun AuthenticateSession)
- [ ] **Mot de passe actuel faux** ⚠️
  - Faire : Saisir un « Mot de passe actuel » faux et un nouveau mot de passe valide, puis « Enregistrer ».
  - Attendu : Sous « Mot de passe actuel » : « Le mot de passe est incorrect. ». Les trois champs sont vidés et le focus revient dans « Mot de passe actuel ». Le mot de passe n'a pas changé.
  - Réf. : PasswordUpdateRequest (current_password) · pages/settings/security.tsx
- [ ] **Nouveau mot de passe invalide** ⚠️
  - Faire : Avec le bon mot de passe actuel, envoyer un nouveau mot de passe de 7 caractères, puis une confirmation différente.
  - Attendu : « Le champ mot de passe doit contenir au moins 8 caractères. », puis « La confirmation du champ mot de passe ne correspond pas. », sous « Nouveau mot de passe ». Les trois champs sont vidés à chaque erreur.
  - Réf. : PasswordValidationRules
- [ ] **Limite de 6 changements par minute** ⚠️
  - Faire : Avec `APP_DEBUG=false`, envoyer 7 fois le formulaire de mot de passe en moins d'une minute, bon ou mauvais.
  - Attendu : Le 7e envoi affiche la page « Trop de demandes ».
  - Réf. : routes/settings.php (throttle:6,1)

### 2.8 Double authentification (2FA)

- [ ] **Démarrer l'activation de la 2FA** ⭐
  - Faire : Compte de test, sur /settings/security, section « Authentification à deux facteurs ». Cliquer « Activer la 2FA ».
  - Attendu : Avant le clic : description « Gérez l’authentification à deux facteurs de votre compte » et le texte « Une fois l’authentification à deux facteurs activée, un code sécurisé vous sera demandé à la connexion… ». Le clic ouvre une fenêtre « Activer l’authentification à deux facteurs », description « Pour terminer l’activation, scannez le QR code ou saisissez la clé de configuration dans votre application d’authentification ». Elle contient un QR code, le bouton « Continuer », le séparateur « ou saisissez la clé de configuration à la main », et la clé avec un bouton de copie dont l'icône devient une coche après la copie. C'est vers cette section que renvoie la page « 2FA requise » du back-office (section back-office).
  - Réf. : components/manage-two-factor.tsx · two-factor-setup-modal.tsx · config/fortify.php (confirm)
- [ ] **Confirmer la 2FA avec un code** ⭐
  - Faire : Scanner le QR (ou coller la clé) dans l'application TOTP, cliquer « Continuer », saisir le code à 6 chiffres, puis « Confirmer ».
  - Attendu : Étape « Vérifier le code d’authentification », « Saisissez le code à 6 chiffres de votre application d’authentification », avec 6 cases et les boutons « Retour » et « Confirmer ». « Confirmer » reste grisé tant que les 6 chiffres ne sont pas saisis. Un bon code ferme la fenêtre. La section affiche alors « Un code sécurisé et aléatoire vous sera demandé à la connexion… », le bouton « Désactiver la 2FA » et une carte « Codes de récupération ».
  - Réf. : Fortify ConfirmedTwoFactorAuthenticationController
- [ ] **Code faux à la confirmation** ⚠️
  - Faire : À l'étape de vérification, saisir 000000 puis « Confirmer ». Cliquer ensuite « Retour ».
  - Attendu : Sous les cases : « Le code d’authentification à deux facteurs fourni est invalide. », et les cases sont vidées. « Retour » ramène au QR code. La 2FA n'est pas active.
  - Réf. : lang/fr.json · two-factor-setup-modal.tsx
- [ ] **Abandonner la configuration** ⚠️
  - Faire : Après « Activer la 2FA », fermer la fenêtre sans confirmer. Recharger ensuite la page. Se déconnecter et se reconnecter.
  - Attendu : Sans recharger, un bouton « Poursuivre la configuration » rouvre la même fenêtre. Après rechargement, la configuration est abandonnée et le bouton redevient « Activer la 2FA ». La reconnexion ne demande aucun code.
  - Réf. : InteractsWithTwoFactorState::ensureStateIsValid · SecurityController::edit
- [ ] **Afficher, masquer et régénérer les codes de récupération** ⭐
  - Faire : Avec la 2FA active, cliquer « Afficher les codes de récupération ». Noter un code, cliquer « Régénérer les codes », puis « Masquer les codes de récupération ».
  - Attendu : La carte dit « Les codes de récupération vous permettent de retrouver l’accès à votre compte si vous perdez votre appareil 2FA. ». L'affichage montre 8 codes, la mention « Chaque code de récupération ne sert qu’une fois et disparaît après usage. » et le bouton « Régénérer les codes ». La régénération remplace les 8 codes : noter un code de la nouvelle liste. « Masquer les codes de récupération » replie la liste.
  - Réf. : components/two-factor-recovery-codes.tsx
- [ ] **Défi 2FA à la connexion** ⭐
  - Faire : Se déconnecter, se reconnecter avec le compte de test (adresse et mot de passe), puis saisir le code de l'application et « Continuer ».
  - Attendu : Passage par /two-factor-challenge, onglet « Authentification à deux facteurs - TripleFrames » : titre « Code d’authentification », « Saisissez le code fourni par votre application d’authentification. », 6 cases, bouton « Continuer », et la ligne « ou vous pouvez vous connecter avec un code de récupération ». Un bon code mène à /dashboard.
  - Réf. : pages/auth/two-factor-challenge.tsx · Fortify
- [ ] **Code faux au défi, puis limite** ⚠️
  - Faire : Au défi, saisir 000000 et « Continuer ». Avec `APP_DEBUG=false`, recommencer jusqu'au 6e envoi en moins d'une minute.
  - Attendu : « Le code d’authentification à deux facteurs fourni est invalide. », et les cases sont vidées. Au 6e envoi dans la minute, la page « Trop de demandes » s'affiche.
  - Réf. : FortifyServiceProvider (limiter two-factor, 5/min)
- [ ] **Se connecter avec un code de récupération** ⭐
  - Faire : Au défi, cliquer « vous connecter avec un code de récupération ». Saisir le code noté, puis « Continuer ». Dans Sécurité, réafficher les codes. Se déconnecter et réessayer le même code.
  - Attendu : Le défi passe en mode « Code de récupération », « Confirmez l’accès à votre compte en saisissant l’un de vos codes de récupération. », champ « Saisissez un code de récupération », lien « vous connecter avec un code d’authentification ». Le code valide connecte. Dans la liste, ce code a été remplacé par un nouveau. Le réutiliser donne « Le code de récupération fourni est invalide. ».
  - Réf. : Fortify TwoFactorAuthenticatedSessionController · lang/fr.json
- [ ] **Défi ouvert sans connexion en cours** ⚠️
  - Faire : Déconnecté, taper directement /two-factor-challenge.
  - Attendu : Redirection vers /login.
  - Réf. : Fortify TwoFactorLoginRequest
- [ ] **Saisie des 6 chiffres** ⚠️
  - Faire : Dans les cases du défi ou de la confirmation, taper des lettres, puis coller un code de 6 chiffres copié.
  - Attendu : Les lettres sont ignorées. Le collage remplit les 6 cases d'un coup.
  - Réf. : input-otp (REGEXP_ONLY_DIGITS)
- [ ] **Désactiver la 2FA**
  - Faire : Sur /settings/security, cliquer « Désactiver la 2FA ». Se déconnecter et se reconnecter.
  - Attendu : La section revient au bouton « Activer la 2FA » et la carte des codes disparaît. La reconnexion ne passe plus par le défi.
  - Réf. : Fortify TwoFactorAuthenticationController::destroy
- [ ] **QR code en thème sombre** ⚠️
  - Faire : Choisir « Sombre » dans /settings/appearance, puis relancer « Activer la 2FA ». Scanner le QR avec l'application.
  - Attendu : Le QR code est affiché inversé, clair sur fond sombre. Vérifier que l'application le lit ; sinon, la clé saisie à la main doit toujours marcher, et le QR illisible est à signaler.
  - Réf. : two-factor-setup-modal.tsx (filter invert en sombre)
- [ ] **QR code, clé et codes de récupération indisponibles** ⚠️
  - Faire : Avec le compte de test, sur /settings/security, bloquer dans l’onglet Réseau les URL contenant `two-factor-qr-code` et `two-factor-secret-key`, puis cliquer « Activer la 2FA ». Débloquer, fermer la fenêtre, puis cliquer « Poursuivre la configuration ». Une fois la 2FA active, bloquer `two-factor-recovery-codes`, recharger la page et cliquer « Afficher les codes de récupération ».
  - Attendu : La fenêtre « Activer l’authentification à deux facteurs » s’ouvre sans QR code, avec un encadré d’erreur qui liste « Le QR code n’a pas pu être chargé. » et « La clé de configuration n’a pas pu être chargée. ». Après déblocage, « Poursuivre la configuration » recharge le QR code et la clé. Quand les codes sont bloqués, la carte « Codes de récupération » affiche « Les codes de récupération n’ont pas pu être chargés. ». Aucune page d’erreur.
  - Réf. : hooks/use-two-factor-auth.ts · two-factor-setup-modal.tsx · account.two_factor.errors.*

### 2.9 Passkeys (ouvertes en local)

- [ ] **Piège d'hôte connu : 127.0.0.1 contre APP_URL** ⚠️
  - Faire : Avec l'`APP_URL=http://tripleframes.test` actuel, sur http://127.0.0.1:8000/login, cliquer « Se connecter avec une passkey ».
  - Attendu : Aucune connexion. Sous le bouton s'affiche, en anglais, « Passkeys can't be used on 127.0.0.1. For local development, use localhost. ». C'est le piège connu de CLAUDE.md § 8, pas un bug de fond ; en revanche, ce message non traduit est un écart à la règle 4, à signaler.
  - Réf. : config/fortify.php (passkeys.relying_party_id) · CLAUDE.md § 8 · @laravel/passkeys
- [ ] **Préparer l'hôte pour tester les passkeys** ⭐
  - Faire : Dans `.env`, poser `APP_URL=http://localhost:8000`, lancer `php artisan config:clear` et relancer `composer dev`. Naviguer ensuite sur http://localhost:8000, pas sur 127.0.0.1, et se reconnecter (les cookies sont propres à l'hôte). Remettre l'`APP_URL` d'origine à la fin du groupe.
  - Attendu : Le site s'affiche normalement sur localhost:8000, et /.well-known/passkey-endpoints y annonce http://localhost:8000/settings/security.
  - Réf. : config/fortify.php (relying_party_id et allowed_origins tirés d'APP_URL)
- [ ] **Enregistrer une passkey** ⭐
  - Faire : Compte de test, sur /settings/security, section « Passkeys ». Cliquer « Ajouter une passkey », garder ou changer le nom proposé, puis « Enregistrer la passkey » et valider l'invite du navigateur.
  - Attendu : Avant l'ajout : « Gérez vos passkeys pour une connexion sans mot de passe » et l'état vide « Aucune passkey » / « Ajoutez une passkey pour vous connecter sans mot de passe ». Le formulaire montre « Nom de la passkey », pré-rempli (par exemple « Chrome sur Windows »), l'aide « Un nom vous aidera à reconnaître cette passkey plus tard. », et les boutons « Enregistrer la passkey » (grisé si le nom est vide, puis « Enregistrement… ») et « Annuler ». Une fois validée, la passkey apparaît avec son nom, un éventuel badge d'authentificateur et « Ajoutée il y a … ».
  - Réf. : components/passkey-register.tsx · manage-passkeys.tsx
- [ ] **Enregistrer deux fois le même appareil** ⚠️
  - Faire : Avec une passkey déjà enregistrée, recommencer « Ajouter une passkey » › « Enregistrer la passkey » avec le même authentificateur.
  - Attendu : Le navigateur refuse. Sous le formulaire : « This device is already registered as a passkey. », en anglais, non traduit (à signaler). Aucune seconde passkey n'apparaît.
  - Réf. : GenerateRegistrationOptions (excludeCredentials) · @laravel/passkeys
- [ ] **Se connecter par passkey** ⭐
  - Faire : Se déconnecter, puis sur /login cliquer « Se connecter avec une passkey » et valider l'invite. Revenir ensuite sur /settings/security.
  - Attendu : Le bouton affiche « Authentification… », puis on arrive sur /dashboard sans mot de passe. Dans Sécurité, la passkey porte « / Dernière utilisation il y a … ». Au J1, une connexion par passkey ne passe pas le défi TOTP, même 2FA active : sujet du J2, pas un bug.
  - Réf. : components/passkey-verify.tsx · 40 § 8.2 (Ouverture)
- [ ] **Confirmer le mot de passe par passkey**
  - Faire : Forcer une nouvelle confirmation (nouvelle connexion ou `AUTH_PASSWORD_TIMEOUT` court), ouvrir « Sécurité » et cliquer « Confirmer avec une passkey ».
  - Attendu : Le bouton affiche « Confirmation… », puis on arrive sur /settings/security sans avoir saisi de mot de passe.
  - Réf. : pages/auth/confirm-password.tsx · PasskeyConfirmationController
- [ ] **Retirer une passkey**
  - Faire : Sur /settings/security, cliquer « Retirer » à côté de la passkey. Essayer d'abord « Annuler », puis recommencer et confirmer. Se déconnecter et tenter la connexion avec cette passkey, restée dans l'authentificateur.
  - Attendu : Dialogue « Supprimer la passkey », « Voulez-vous vraiment supprimer la passkey « <nom> » ? ». « Annuler » ferme sans rien changer. « Supprimer la passkey » affiche « Suppression… », puis la liste revient à « Aucune passkey ». La tentative de connexion échoue avec, sous le bouton, « Passkey not recognized. It may have been removed from your account. », en anglais, non traduit (à signaler).
  - Réf. : components/passkey-item.tsx · VerifyPasskey
- [ ] **Annuler l'invite du navigateur** ⚠️
  - Faire : Cliquer « Enregistrer la passkey » ou « Se connecter avec une passkey », puis annuler l'invite système.
  - Attendu : Sous le formulaire ou le bouton : « The passkey operation was cancelled. », en anglais, non traduit (à signaler). Aucune passkey créée, aucune connexion.
  - Réf. : @laravel/passkeys (UserCancelledError)
- [ ] **Limite de 10 essais de passkey par minute** ⚠️
  - Faire : Déconnecté sur http://localhost:8000/login, cliquer 11 fois « Se connecter avec une passkey » en moins d'une minute, en annulant l'invite à chaque fois.
  - Attendu : Au 11e clic, l'invite ne s'ouvre pas et le message « Too Many Attempts. » s'affiche sous le bouton, en anglais (à signaler). Au bout d'une minute, le bouton refonctionne.
  - Réf. : FortifyServiceProvider (limiter passkeys, 10/min)
- [ ] **Navigateur sans WebAuthn** ⚠️
  - Faire : Dans Firefox, ouvrir about:config et passer `security.webauth.webauthn` à false (ou prendre tout navigateur sans WebAuthn). Ouvrir /login, puis, une fois connecté, /user/confirm-password et /settings/security. Rétablir la préférence ensuite.
  - Attendu : /login n’affiche ni « Se connecter avec une passkey » ni son séparateur. La confirmation du mot de passe ne propose pas « Confirmer avec une passkey ». Dans la section « Passkeys », le bouton « Ajouter une passkey » est remplacé par « Les passkeys ne sont pas prises en charge par ce navigateur. ».
  - Réf. : 40 § 8.2 · components/passkey-register.tsx · account.passkeys.unsupported

### 2.10 Apparence

- [ ] **Choisir le thème du compte** ⭐
  - Faire : Connecté, ouvrir /settings/appearance, puis cliquer successivement « Sombre » et « Clair ». Recharger la page en sombre.
  - Attendu : Titre « Réglages d’apparence », « Modifiez l’apparence de l’interface pour votre compte », trois boutons « Clair », « Sombre », « Système ». Chaque clic bascule immédiatement toute la coquille : barre latérale, contenu, pied de page. Le rechargement en sombre se fait sans éclair blanc.
  - Réf. : pages/settings/appearance.tsx · HandleAppearance · use-appearance.tsx
- [ ] **Mode Système**
  - Faire : Choisir « Système », puis changer le thème de Windows (Paramètres › Personnalisation › Couleurs › Mode).
  - Attendu : L'interface suit le thème du système en direct, sans recharger.
  - Réf. : use-appearance.tsx (matchMedia)
- [ ] **Même réglage que le bouton d'apparence public**
  - Faire : Choisir « Sombre » dans les réglages, puis ouvrir l'accueil / et /login. Changer ensuite l'apparence avec le bouton « Apparence » de l'en-tête public, et revenir sur /settings/appearance.
  - Attendu : L'accueil et les écrans de connexion sont sombres. Le changement fait depuis l'en-tête public est reflété par le bouton actif des réglages.
  - Réf. : 90 § 2.4 · components/public/appearance-toggle.tsx
- [ ] **Réglage propre au navigateur, pas au compte** ⚠️
  - Faire : Choisir « Sombre » dans un navigateur. Se connecter au même compte dans un autre navigateur.
  - Attendu : Le second navigateur garde son propre thème : le choix vit dans le cookie `appearance` et le `localStorage`, pas en base, malgré le libellé « …pour votre compte ». À noter si c'est gênant.
  - Réf. : HandleAppearance · lang/fr/account.php (appearance.description)
- [ ] **Le salon reste toujours sombre**
  - Faire : Choisir « Clair », ouvrir /r/new, puis créer un salon.
  - Attendu : /r/new est clair. Le lobby /r/{code} est sombre malgré le réglage : c'est le seul forçage du site.
  - Réf. : 90 § 2.2 · ForceGameAppearance

### 2.11 Langue du compte (FR ↔ EN)

- [ ] **Changer la langue en invité, depuis l'accueil** ⭐
  - Faire : Déconnecté, sur /, ouvrir le sélecteur de langue de l'en-tête (icône suivie de « Français »), puis choisir « English ». Rouvrir le menu et presser Échap. Ouvrir /login. Revenir ensuite sur / et choisir « Français ».
  - Attendu : Le menu s'intitule « Langue » et propose « English » et « Français », chacun écrit dans sa langue. Échap referme le menu sans rien changer. Les écrans de compte n'ont pas de sélecteur de langue : on passe par l'accueil. /login s'affiche en anglais : « Log in to your account », « Email address », « Forgot your password? », « Remember me », « Log in », « Sign up ». Onglet « Log in - TripleFrames ». Retour au français au second choix.
  - Réf. : 05 § Changement de langue · 90 § 8 · LocaleController · components/language-switcher.tsx
- [ ] **La langue d'un compte le suit d'un navigateur à l'autre** ⭐
  - Faire : Connecté avec player@tripleframes.test, sur /, choisir « English », puis ouvrir /settings/profile. Dans une fenêtre privée restée en français, se connecter avec le même compte. Remettre « Français » à la fin.
  - Attendu : /settings/profile est en anglais : « Settings », « Profile », « Security », « Appearance ». Dans la fenêtre privée, l'interface passe en anglais dès la connexion, car `users.locale` prime sur le cookie `locale`.
  - Réf. : 05 § Résolution (ligne 1) · SetLocale::fromUser
- [ ] **La déconnexion rend la langue du navigateur** ⚠️
  - Faire : Dans un navigateur réglé en français, se connecter avec admin@tripleframes.test (langue en). Se déconnecter.
  - Attendu : Pendant la session, l'interface est en anglais : « Dashboard », puis « Log out » dans le menu. Après la déconnexion, l'accueil revient en français, la langue du cookie.
  - Réf. : SetLocale · DemoAccountsSeeder (admin en)
- [ ] **Les e-mails suivent la langue choisie par le compte**
  - Faire : Passer player@tripleframes.test en « English » depuis l'accueil, se déconnecter, remettre l'interface en français, puis demander un lien sur /forgot-password pour player@tripleframes.test. Remettre ensuite le compte en français.
  - Attendu : L'e-mail est en anglais (objet « Reset your password »), alors que l'écran de la demande était en français.
  - Réf. : 05 § E-mails · User::preferredLocale
- [ ] **Messages d'erreur dans la langue active**
  - Faire : Interface en anglais, sur /login, saisir un mot de passe faux.
  - Attendu : « These credentials do not match our records. ».
  - Réf. : lang/en/auth.php

### 2.12 Tableau de bord, coquille, mobile et clavier

- [ ] **Tableau de bord du starter**
  - Faire : Connecté, ouvrir /dashboard.
  - Attendu : La page du starter est conservée au J1 comme cible après connexion : fil d'Ariane « Tableau de bord », trois tuiles à motif et un grand cadre à motif, aucun contenu de jeu. Pied de page légal en dessous. Aucun lien vers le back-office, même pour un admin.
  - Réf. : 90 § 2.1 · routes/web.php (dashboard)
- [ ] **Replier la barre latérale**
  - Faire : Sur /dashboard ou /settings/profile, en bureau, cliquer le bouton à gauche du fil d'Ariane, puis presser Ctrl+B. Recharger la page.
  - Attendu : Le bouton a pour nom accessible « Replier ou déplier la barre latérale ». La barre se replie en icônes et se redéplie, au clic comme au raccourci. L'état est conservé au rechargement (cookie `sidebar_state`).
  - Réf. : components/app-sidebar-header.tsx · ui/sidebar.tsx
- [ ] **Portrait mobile à 375 px**
  - Faire : DevTools, mode appareil en 375 × 667. Ouvrir /login, /register, /settings/profile, /settings/security (la fenêtre 2FA ouverte) et /dashboard.
  - Attendu : Aucun défilement horizontal. Les formulaires de connexion tiennent dans la largeur. Dans les réglages, « Profil », « Sécurité » et « Apparence » passent au-dessus du contenu, suivis d'un séparateur. La barre latérale est masquée : le bouton d'en-tête ouvre un panneau avec « Tableau de bord » et le menu utilisateur (titre lu par lecteur d'écran « Sidebar », en anglais : dette connue n° 24). La fenêtre 2FA et son QR tiennent dans l'écran.
  - Réf. : 90 § 2.5 (coquille du starter gardée au J1)
- [ ] **Pied de page légal sur les écrans de compte**
  - Faire : Sur /login et /settings/profile, faire défiler jusqu'en bas, puis cliquer « Mentions légales ».
  - Attendu : Liens « Mentions légales », « Conditions générales d’utilisation », « Politique de confidentialité », « Signaler un contenu », plus l'attribution TMDB, traduits. Sur les écrans de connexion, le pied est sous la ligne de flottaison : c'est une dette connue. Le lien mène à la page légale.
  - Réf. : 90 § 2.4 et § 3.1 · components/public/site-footer.tsx

### 2.13 Traces et neutralité du compte

- [ ] **Date de dernière connexion**
  - Faire : Dans `php artisan tinker`, noter `User::firstWhere('email','player@tripleframes.test')->only('last_login_at','updated_at')`. Se connecter avec ce compte, relancer la commande. Refaire un essai refusé (mauvais mot de passe), puis, sur le compte de test à 2FA, s'arrêter au défi avant de saisir le code.
  - Attendu : Après une connexion aboutie, `last_login_at` prend l'heure courante et `updated_at` ne bouge pas. Une connexion refusée ne change rien. L'étape du défi n'écrit rien : la date n'est posée qu'une fois le code accepté. La même date est visible au back-office (« Dernière connexion »), traité dans une autre section.
  - Réf. : RecordLastLogin · 10 § 5.1 · 20 § 2.8
- [ ] **Se connecter ne touche pas au siège d'invité**
  - Faire : Déconnecté, ouvrir /r/new, saisir un « Pseudo », choisir un avatar, puis « Créer le salon ». Dans un nouvel onglet du même navigateur, se connecter avec player@tripleframes.test, puis recharger l'onglet du salon. Se déconnecter et recharger encore.
  - Attendu : On retrouve chaque fois le même siège, avec le même pseudo, sans formulaire de pseudo et sans second siège. Le cookie `player_token` est inchangé par la connexion comme par la déconnexion.
  - Réf. : 40 § 3.8 (I4.6)
- [ ] **Un compte connecté prend un siège comme un invité**
  - Faire : Connecté avec curator@tripleframes.test, ouvrir /r/new.
  - Attendu : Le champ « Pseudo » est vide : rien n'est pré-rempli, ni « Curateur Démo » ni le nom réel. Il faut saisir un pseudo et choisir un avatar comme un invité.
  - Réf. : 40 § 2.4 et § 8.4 (I4.10, I5.11)

### 2.14 Hors périmètre (non livré, ne pas essayer)

- Connexion sociale Discord et Google (Socialite, routes `oauth.*`) : jalon 2, rien n'est installé.
- Suppression de compte : au J1, la route `profile.destroy` et le composant sont retirés (40 § 8.3). Elle revient au J2 sous forme d'anonymisation. Ne pas chercher le bouton.
- Acceptation des CGU et déclaration d'âge à l'inscription, puis ré-acceptation : J2. Le formulaire d'inscription n'a volontairement aucune case.
- Avatar de compte (photo Discord/Google, prédéfini choisi sur le compte, téléversement en v1.1) : J2 et v1.1. Le compte n'affiche que ses initiales.
- Pseudo persistant, pré-remplissage du siège depuis le compte, rattachement d'un siège invité à un compte : J2 (I4.10).
- Historique « Mes parties » sur 12 mois et ses 4 compteurs, export des données, configurations sauvegardées vues du compte, dormance des comptes : J2.
- Ouverture de l'inscription et des passkeys en production, et règle 2FA ↔ passkey : J2. Au J1, une connexion par passkey ne passe pas le défi TOTP.
- Fermeture par défaut hors local (environnement autre que local/testing, variables non déclarées) : déconseillée sur le poste, car un `APP_ENV` autre que local fait lever la garde `FRAMES_DISK_ROOT`, vide en local. Le cas est couvert par `AccountSwitchesTest`.
- Règles de mot de passe de production (12 caractères, majuscules et minuscules, chiffre, symbole, absence de fuite) : actives seulement en `APP_ENV=production`.
- Interdiction de désactiver la 2FA pour un curateur ou un admin : aucune garde au J1. La porte /admin, l'écran « 2FA requise », l'annuaire des comptes, la gestion des accès et la correction du nom réel (L20-19) relèvent de la section back-office.
- Coquille définitive des écrans de compte (sortie de la barre latérale du starter, retrait du tableau de bord) et textes anglais lus par lecteur d'écran dans la barre latérale mobile (« Sidebar », « Toggle sidebar ») : dette n° 24, 40 et 90 au J2.
- Crochet « créer un compte » après le podium : J2, absent au J1.
- Commande `site:close`, qui coupera l'inscription : J2.

## 3. Salon : création, réglages, entrée et lobby

Cette section couvre tout ce qui précède le lancement d’une partie multijoueur. On crée un salon (`/r/new`), on le rejoint par le code ou le lien (`/r/<CODE>/join`), puis on passe au lobby (`/r/<CODE>`) : réglages Simple, presets, vivier et gestes (retrait, transfert d’hôte, départ). S’y ajoutent la présence, la capacité, les retardataires, le drainage, l’expiration et les limites de débit. La section s’arrête au clic « Lancer la partie », refus compris. Le déroulé de la partie et le podium relèvent d’autres sections.

**Prérequis**

- Base de dev à jour, VM Homestead démarrée (la base MySQL est sur 192.168.10.10). Lancer `php artisan backup:snapshot` (code 0 exigé, règle 12), puis `php artisan migrate:fresh --seed`. On obtient le catalogue de démo (16 films jouables pour N = 2 à 5), les 4 presets et les comptes de démo.
- Mise en route du § 0 faite : `.env` à niveau pour le temps réel (Reverb), base seedée, `composer dev` lancé.
- Redis joignable sur 127.0.0.1:6379, puis `composer dev` lancé. Il démarre le serveur sur http://127.0.0.1:8000, la file `game,default`, Reverb et Vite. Sans la file, les non-hôtes ne voient pas les réglages changer et le balayage de présence ne tourne pas.
- Trois identités invitées distinctes, puisque l’identité vit dans le cookie `player_token` : A = fenêtre normale (l’hôte), B = fenêtre privée du même navigateur, C = un autre navigateur (Firefox ou Edge). Deux fenêtres privées de Chrome partagent leurs cookies : elles ne font jamais deux joueurs. Les trois identités partagent aussi l’adresse 127.0.0.1, donc le budget de création de salons.
- `<CODE>` désigne le code de 6 signes affiché dans le lobby. Pseudos d’exemple : Alice (A), Bruno (B), Chloé (C).
- Pour voir les pages d’erreur traduites (404, 429), mettre `APP_DEBUG=false` dans `.env` puis relancer `composer dev`. En debug, c’est la page d’erreur de Laravel qui s’affiche.
- Garder les outils de développement ouverts : onglet Réseau pour certains items, mode appareil (iPhone SE, 375 px) pour le portrait. Langue de départ : Français.

### 3.1 Créer un salon (/r/new)

- [ ] **Ouvrir le formulaire de création** ⭐
  - Faire : En A, sur `/`, cliquer « Créer un salon » (ou aller directement à `/r/new`).
  - Attendu : La page « Créer un salon » s’ouvre dans la coquille publique (en-tête, pied de page), à l’apparence du visiteur. Elle contient :
    - l’intro « Choisissez un pseudo et un avatar : vous réglerez la partie dans le salon. » ;
    - le champ « Pseudo », avec l’aide « Entre 2 et 20 caractères, unique dans le salon. » ;
    - la grille « Choisissez un avatar » de 24 animaux (Ours → Zèbre), l’un présélectionné ;
    - le bouton « Créer le salon » ;
    - la mention « En continuant, vous acceptez les conditions générales d’utilisation. ».
    Aucun réglage de partie ici. Titre de l’onglet : « Créer un salon - TripleFrames ».
  - Réf. : 50 § 6.1-6.2 · RoomController@create · pages/room/create.tsx · seat-form.tsx
- [ ] **Créer le salon aux réglages par défaut** ⭐
  - Faire : Saisir « Alice », choisir l’avatar « Hibou », cliquer « Créer le salon ».
  - Attendu : Pendant l’envoi, le bouton est désactivé et porte un indicateur. On arrive ensuite sur `/r/<CODE>` : 6 signes parmi A–Z et 2–9, jamais I, O, 0 ni 1. La page « Salon » est en thème sombre. Elle affiche l’en-tête « Vous êtes l’hôte : vous réglez et lancez la partie. » et « Joueurs (1 sur 12) », avec Alice badgée « Hôte » et « Vous ». Les réglages sont tous aux valeurs par défaut : 10 manches, 3 images, 30 s, révélation 8 s, Normal, 12 places, « Retardataires » désactivé.
  - Réf. : 50 § 6.4 · CreateRoom · RoomSettings::defaults · TransferHost::to
- [ ] **Lien des CGU ouvert dans un nouvel onglet**
  - Faire : Sur `/r/new`, saisir un pseudo, puis cliquer la mention « En continuant, vous acceptez les conditions générales d’utilisation. ».
  - Attendu : `/legal/terms` s’ouvre dans un NOUVEL onglet. L’onglet d’origine garde le pseudo saisi et l’avatar choisi.
  - Réf. : 40 § 2.1 · seat-form.tsx · legal.terms_notice
- [ ] **Avatar mémorisé par le navigateur**
  - Faire : Après avoir créé le salon avec « Hibou », revenir à `/r/new` dans la même fenêtre A.
  - Attendu : « Hibou » est présélectionné, grâce à la revendication d’avatar du jeton. Dans une identité neuve, c’est « Ours » qui l’est.
  - Réf. : 40 § 6 · AvatarPresetCatalog::suggest · PresentsSeatForm
- [ ] **Compte connecté traité comme un invité au J1**
  - Faire : Se connecter avec player@tripleframes.test / password, puis créer un salon depuis `/r/new`.
  - Attendu : Le formulaire et le lobby sont les mêmes que pour un invité : pseudo et avatar saisis, aucune section de configurations sauvegardées.
  - Réf. : 50 § 6.4 · CreateRoom ($user sans effet au J1) · TakeSeat (user_id jamais écrit) · props configs = null
- [ ] **Choisir un avatar au clavier**
  - Faire : Sur `/r/new`, aller par Tab jusqu’à la grille « Choisissez un avatar », parcourir avec les flèches, puis appuyer sur Tab. Refaire l’essai sur la page d’entrée d’un salon où « Hibou » est déjà pris, avec un lecteur d’écran ou l’inspecteur d’accessibilité.
  - Attendu : La grille ne compte qu’un arrêt de tabulation. Les flèches déplacent le focus et cochent l’avatar focalisé, avec un anneau de focus visible et le point de l’option cochée visible sans couleur. Tab sort vers le bouton suivant. Chaque tuile fait au moins 44 px. L’option prise s’annonce « Hibou » suivi de « déjà choisi dans ce salon », et reste cochable.
  - Réf. : 40 § 6 (I5.8, I5.9) · components/game/avatar-picker.tsx

### 3.2 Pseudo et avatar : refus du serveur (création comme entrée)

- [ ] **Pseudo vide** ⚠️
  - Faire : Sur `/r/new`, laisser « Pseudo » vide et cliquer « Créer le salon ».
  - Attendu : « Le champ pseudo est obligatoire. » s’affiche sous le champ. Le message est traduit par le serveur ; aucune bulle du navigateur n’apparaît. Le focus revient sur « Pseudo », l’avatar choisi reste coché et aucun salon n’est créé.
  - Réf. : 40 § 5.9 · StoreRoomRequest · seat-form.tsx (noValidate)
- [ ] **Pseudo trop court ou trop long** ⚠️
  - Faire : Saisir « A » et envoyer. Essayer ensuite de taper 21 caractères.
  - Attendu : Pour « A » : « Le pseudo doit compter entre 2 et 20 caractères. ». Le champ bloque la frappe au-delà de 20 caractères.
  - Réf. : ValidNickname KEY_LENGTH · NicknameNormalizer MIN/MAX
- [ ] **Écriture non latine** ⚠️
  - Faire : Saisir « Иван » (cyrillique), puis envoyer.
  - Attendu : « Le pseudo ne peut utiliser que l’alphabet latin, accents compris, des chiffres, des espaces, « - » et « _ ». »
  - Réf. : 40 § 5.3 (D26 du 23/09) · ValidNickname KEY_SCRIPT
- [ ] **Émoji ou symbole** ⚠️
  - Faire : Saisir « Bob 😀 », puis envoyer.
  - Attendu : « Ce pseudo contient un caractère non autorisé : symbole, émoji ou caractère invisible. »
  - Réf. : ValidNickname KEY_CHARACTERS
- [ ] **Ni lettre ni chiffre** ⚠️
  - Faire : Saisir « -_- », puis envoyer.
  - Attendu : « Le pseudo doit contenir au moins une lettre ou un chiffre. »
  - Réf. : ValidNickname KEY_ALNUM
- [ ] **Trop long une fois replié** ⚠️
  - Faire : Saisir « ßßßßßßßßßßß » (11 fois ß), puis envoyer.
  - Attendu : « Ce pseudo est trop long une fois ses lettres spéciales développées (ß, æ, œ…). Raccourcissez-le. »
  - Réf. : ValidNickname KEY_NORMALIZED_LENGTH
- [ ] **Liste noire (noms réservés, leet)** ⚠️
  - Faire : Essayer tour à tour « Admin », « 4dm1n », « modo » et « Badminton ».
  - Attendu : Chaque essai donne le même message neutre, « Ce pseudo n’est pas disponible. Choisissez-en un autre. », qui ne cite jamais le mot en cause. « Badminton » est refusé lui aussi : c’est un faux positif assumé (sous-chaîne « admin »), pas un bug.
  - Réf. : 40 § 5.7 · NicknameBlocklist (SUBSTRING_MIN_LENGTH, LEET_*) · resources/moderation/nicknames/reserved.txt
- [ ] **Espaces et accents normalisés**
  - Faire : Saisir «   Zoé   Martin  » (espaces multiples), puis créer le salon.
  - Attendu : Le pseudo est accepté et s’affiche « Zoé Martin » dans la liste des sièges. Les bords sont coupés, les espaces intérieurs réduits à un seul, et l’accent est conservé.
  - Réf. : 40 § 5.2 · NicknameNormalizer::canonical

### 3.3 Lobby : code, lien et partage

- [ ] **Code et lien de partage** ⭐
  - Faire : Dans le lobby de A, regarder le bloc en tête, puis cliquer dans le champ du lien.
  - Attendu : Le libellé « Code » est suivi du code en grand. Dessous, « Partagez ce lien ou ce code avec vos amis. » surmonte un champ en lecture seule qui contient `http://127.0.0.1:8000/r/<CODE>`. Un clic dans le champ sélectionne tout le lien.
  - Réf. : 50 § 6.5, § 8.1 · share-code.tsx
- [ ] **Copier le lien**
  - Faire : Cliquer « Copier le lien », puis coller dans la barre d’adresse d’une autre fenêtre.
  - Attendu : « Lien copié » apparaît avec une coche, jamais sous forme de toast. Le texte collé est exactement le lien du salon.
  - Réf. : share-code.tsx · room.lobby.link_copied
- [ ] **Bouton Partager selon le navigateur**
  - Faire : Regarder le bloc de partage sous Chrome ou Edge (Windows), puis sous Firefox desktop. Sous Chrome, cliquer « Partager » et fermer la feuille sans rien choisir.
  - Attendu : Sous Chrome et Edge, « Partager » est présent et ouvre la feuille de partage du système. La fermer sans choix n’affiche aucune erreur. Sous Firefox desktop, le bouton est absent, faute de `navigator.share`.
  - Réf. : share-code.tsx
- [ ] **Le code n’apparaît ni dans le titre ni pour les moteurs**
  - Faire : Regarder le titre d’onglet du lobby. Dans Réseau, lire les en-têtes de réponse de `/r/<CODE>`, `/r/<CODE>/join` et `/r/new`.
  - Attendu : Le titre est « Salon - TripleFrames », sans le code. Chaque réponse porte `X-Robots-Tag: noindex, nofollow` et `Referrer-Policy: strict-origin-when-cross-origin`.
  - Réf. : 50 § 6.5 · 90 § 5 · RobotsDirectives

### 3.4 Entrer dans un salon (second joueur)

- [ ] **Ouvrir le lien sans siège** ⭐
  - Faire : En B (fenêtre privée), coller le lien `/r/<CODE>`.
  - Attendu : Redirection vers `/r/<CODE>/join` : page « Rejoindre le salon », à l’apparence du visiteur (non forcée en sombre). On n’y voit ni pseudo ni liste de joueurs. La page affiche, dans l’ordre :
    - le champ « Pseudo », avec « Entre 2 et 20 caractères, unique dans le salon. » ;
    - la grille d’avatars, où « Hibou » porte « déjà choisi dans ce salon » et « Ours » est présélectionné ;
    - le bouton « Entrer » et la mention des CGU.
  - Réf. : 50 § 7.2 · RoomController@show (303) · RoomEntryController@show · pages/room/join.tsx
- [ ] **Prendre un siège** ⭐
  - Faire : En B, saisir « Bruno » et cliquer « Entrer ».
  - Attendu : B arrive sur `/r/<CODE>` en thème sombre, avec l’en-tête « En attente du lancement par l’hôte. ». Son siège porte « Vous », celui d’Alice porte « Hôte ». Côté A, sans recharger, Bruno apparaît et le compteur passe à « Joueurs (2 sur 12) ».
  - Réf. : 50 § 7.3 · TakeSeat · seat.joined
- [ ] **Entrée par le code depuis l’accueil**
  - Faire : En C, sur `/`, saisir le code dans « Code du salon » en minuscules, avec un espace ou un tiret (ex. « k7p-q2m »), puis cliquer « Rejoindre ». Recommencer avec « ABC ».
  - Attendu : Le code normalisé mène à la page « Rejoindre le salon ». « ABC » affiche sous le champ « Ce code de salon n’est pas valide. Vérifiez-le, puis réessayez. », sans aucune requête dans l’onglet Réseau.
  - Réf. : 90 § 4.7 · welcome.tsx · lib/game/room-code.ts
- [ ] **Pseudo déjà pris : unicité repliée** ⚠️
  - Faire : En C, sur la page d’entrée, essayer « bruno », « BRUNO », puis « Bru-no ». Recommencer avec « Zoe » dans un salon qui compte déjà « Zoé ».
  - Attendu : Chaque essai affiche sous le champ « Ce pseudo est déjà pris dans ce salon. ». La casse, les accents, les espaces, les tirets et les soulignés ne distinguent pas deux pseudos.
  - Réf. : 40 § 5.5 · TakeSeat S5 · NicknameNormalizer::normalize
- [ ] **Avatar déjà choisi : permis** ⚠️
  - Faire : En C, choisir « Hibou » (marqué « déjà choisi dans ce salon »), saisir « Chloé », puis cliquer « Entrer ».
  - Attendu : L’entrée est acceptée : deux sièges portent le même avatar. Seul le pseudo doit être unique.
  - Réf. : 40 § 6 (I5.8) · avatar-picker.tsx
- [ ] **Page d’entrée pour qui tient déjà un siège**
  - Faire : En B (déjà assis), ouvrir `/r/<CODE>/join`, puis recharger `/r/<CODE>`.
  - Attendu : Redirection vers `/r/<CODE>` : B garde son siège. Aucun second siège n’est créé, aucun formulaire ne s’affiche.
  - Réf. : 50 § 7.2 · RoomEntryController@show
- [ ] **URL tolérante (casse et tiret)** ⚠️
  - Faire : En A, taper à la main `/r/` suivi du code en minuscules coupé par un tiret (ex. `/r/k7pq-2m`).
  - Attendu : Le même salon s’ouvre. Le lien de partage affiché reste la forme canonique, en majuscules.
  - Réf. : 50 § 6.3 · RoomCode::ROUTE_PATTERN · Room::resolveRouteBinding

### 3.5 Réglages Simple (hôte)

- [ ] **Champs livrés au J1, dans l’ordre** ⭐
  - Faire : En A (hôte), parcourir la section « Réglages » du lobby.
  - Attendu : Les champs apparaissent dans cet ordre :
    1. « Nombre de manches » (« Une manche, un film. »), curseur de 3 à 30.
    2. « Images par manche », boutons 2, 3, 4 et 5.
    3. « Durée d’une manche » (« Répartie à parts égales entre les images. »).
    4. « Durée de la révélation », de 3 s à 20 s.
    5. « Difficulté de saisie », 3 options.
    6. « Places », de 2 à 12.
    7. L’interrupteur « Retardataires ».
    Aucun champ « Thèmes », aucun « Réglages avancés ». Chaque curseur affiche ses bornes à ses deux extrémités.
  - Réf. : 50 § 3.1, § 20.1 · room-settings-form.tsx · RoomSettingsBounds
- [ ] **Écriture au relâchement du curseur** ⭐
  - Faire : Onglet Réseau ouvert, glisser à la souris « Nombre de manches » de 10 à 15, relâcher, puis recharger la page.
  - Attendu : Une seule requête `PATCH /r/<CODE>/settings` part, au relâchement, jamais à chaque pas. Après rechargement, la valeur 15 est conservée.
  - Réf. : 50 § 8.3 · RoomSettingsController · UpdateRoomSettings
- [ ] **Borne croisée D ≥ 5 s × N, côté client** ⭐
  - Faire : Cocher tour à tour 2, 3, 4 et 5 dans « Images par manche », en regardant la borne gauche du curseur « Durée d’une manche ».
  - Attendu : La borne basse vaut 10 s (N = 2), 15 s (N = 3), 20 s (N = 4) et 25 s (N = 5). La borne haute reste 120 s. Impossible de glisser sous la borne basse.
  - Réf. : CLAUDE § 7 règle 2 · 50 § 4.3 · lib/room-settings.ts minRoundDuration
- [ ] **D remonté quand N augmente**
  - Faire : Cocher N = 2, glisser la durée à 10 s, puis cocher N = 4. Recharger ensuite la page.
  - Attendu : La durée passe d’elle-même à 20 s, dans le même envoi. Sous le curseur apparaît « Durée portée à 20 s, le minimum pour ce nombre d’images. ». Après rechargement : N = 4, 20 s.
  - Réf. : 50 § 4.3 · framesPerRoundChange · room.settings.roundDuration.raised
- [ ] **Instant des propositions en Normal**
  - Faire : Aux réglages par défaut, lire le texte sous l’option Normal. Passer ensuite à N = 2 et D = 10 s.
  - Attendu : Par défaut : « Les propositions apparaissent à 20 s, soit 67 % de la manche. ». Avec N = 2 et D = 10 s : « … à 5 s, soit 50 % de la manche. ». Pendant le glissement de la durée, le texte suit la valeur tenue.
  - Réf. : 50 § 4.5 · choicesAtPercent · room.settings.inputDifficulty.choices_at
- [ ] **Changer la difficulté de saisie**
  - Faire : Choisir « Facile : propositions dès la première image », recharger, puis choisir « Expert : texte libre uniquement ».
  - Attendu : Chaque choix est enregistré dès le clic et survit au rechargement. Le texte d’instant des propositions reste affiché sous l’option Normal seule, même quand une autre option est cochée.
  - Réf. : 50 § 3.1 · InputDifficulty · room-settings-form.tsx (description de l’option normal)
- [ ] **Avertissement de révélation courte**
  - Faire : Glisser « Durée de la révélation » à 4 s, puis à 5 s.
  - Attendu : À 4 s (comme à 3 s), un encart affiche « Révélation courte : 5 s sont recommandées pour laisser le temps de lire la réponse. ». À 5 s, il disparaît. L’avertissement ne bloque rien.
  - Réf. : 50 § 4.5 · RoomSettings::warnings · settings-warnings.tsx
- [ ] **Avertissement de manche longue**
  - Faire : Glisser « Durée d’une manche » à 61 s, puis à 60 s. Combiner ensuite avec une révélation de 3 s.
  - Attendu : À 61 s : « Manche de plus de 60 s : un joueur qui trouve tôt attendra longtemps. ». À 60 s : aucun avertissement. Les deux avertissements peuvent s’afficher ensemble.
  - Réf. : RoomSettingsBounds::LONG_ROUND_WARNING_DURATION · room.warnings.long_round
- [ ] **Places jamais sous l’effectif présent**
  - Faire : Avec A, B et C assis, regarder la borne gauche de « Places » et essayer de descendre sous 3.
  - Attendu : La borne basse vaut 3, le nombre de joueurs présents ; la borne haute vaut 12. Impossible de descendre plus bas.
  - Réf. : 50 § 10 · room-settings-form.tsx capacityMin
- [ ] **Interrupteur Retardataires**
  - Faire : Activer « Retardataires », recharger, puis le désactiver.
  - Attendu : L’aide dit « Autoriser l’arrivée en cours de partie, à la manche suivante, sans aucun point. ». L’état est enregistré au clic et survit au rechargement. Il est désactivé par défaut.
  - Réf. : 50 § 15.4 (D35 du 23/09) · allowLateJoin
- [ ] **Réglages au clavier**
  - Faire : Au clavier seul :
    1. Tab jusqu’au curseur « Nombre de manches », puis flèches, Page Préc./Suiv., Début/Fin, et une flèche tenue enfoncée.
    2. Tab sur « Images par manche » puis « Difficulté de saisie » : flèches.
    3. Espace sur « Retardataires ».
  - Attendu : Les curseurs bougent au clavier et une seule requête part au relâchement de la touche, même touche tenue. Chaque groupe radio ne compte qu’un arrêt de tabulation, et les flèches changent la valeur. Espace bascule l’interrupteur. Le focus reste visible et n’est jamais perdu pendant un envoi.
  - Réf. : 50 § 8.1 (clavier) · room-settings-form.tsx SettingSlider
- [ ] **Bornes croisées et champs J2 refusés par le serveur (outils de dev)** ⚠️
  - Faire : Sous Firefox, onglet Réseau :
    1. Faire un changement de réglage.
    2. Sur la requête `PATCH /r/<CODE>/settings`, choisir « Modifier et renvoyer ».
    3. Envoyer le corps `{"framesPerRound":5,"roundDuration":10}`, puis le corps `{"tierPoints":[1000,0,0]}`.
    4. Recharger le lobby.
  - Attendu : Rien n’est écrit : après rechargement, N et D sont inchangés. Les réponses suivies (GET Inertia) portent :
    - `props.errors.roundDuration` = « Le réglage durée d’une manche doit être compris entre 25 et 120 secondes pour 5 images par manche. » ;
    - `props.errors.tierPoints` = « Le réglage valeurs des paliers ne peut pas être modifié depuis cet onglet. ».
  - Réf. : 50 § 3.1, § 4.1 · RoomSettingsEditor::simple · RoomSettings::fromInput
- [ ] **Garde de capacité côté serveur (outils de dev)** ⚠️
  - Faire : Avec 3 joueurs présents et « Places » à 12, renvoyer sous Firefox (« Modifier et renvoyer ») la requête de réglages avec le corps `{"capacity":2}`.
  - Attendu : Refus : la réponse suivie porte `props.errors.capacity` = « Le salon compte déjà 3 joueurs : les places ne peuvent pas descendre en dessous. ». Les places restent à 12 et personne n’est expulsé.
  - Réf. : 50 § 10 · RoomCapacityGuard
- [ ] **Rapport « Réglages ajustés » (facultatif, édition locale de config)** ⚠️
  - Faire : 1. Dans `config/game.php`, passer `'room_seats'` à `6` (local seulement), dans un salon dont les places valent 12 et qui compte au plus 6 joueurs.
    2. Changer « Nombre de manches ».
    3. Remettre ensuite `PlatformLimits::DEFAULT_ROOM_SEATS` dans le fichier.
  - Attendu : Chez l’hôte seul, un encart « Réglages ajustés » affiche « « Places » a été ramené dans ses limites. », et « Places » vaut 6. Les non-hôtes ne voient pas l’encart, seulement la nouvelle valeur.
  - Réf. : 50 § 2.6, § 3.2 · RoomSettingsEditor::simple (CHANGE_CLAMPED) · settings-changes.tsx

### 3.6 Presets (hôte)

- [ ] **Liste des presets** ⭐
  - Faire : En A, regarder la section « Presets », au-dessus des réglages. En B, regarder au même endroit.
  - Attendu : Chez A, 4 boutons dans cet ordre : « Classique » (« Le réglage de référence, équilibré pour une partie entre amis. »), « Rapide », « Hardcore », « Découverte ». Chacun porte sa description, sans aucun chiffre. Chez B (non-hôte), il n’y a pas de section Presets.
  - Réf. : 50 § 5 · preset-picker.tsx · SettingPresetCatalog::positionFor
- [ ] **Appliquer Rapide** ⭐
  - Faire : En A, cliquer « Rapide ». Regarder aussi chez B.
  - Attendu : Les réglages passent à 8 manches, 2 images, 15 s, révélation 5 s, « Facile », 12 places, sans aucun avertissement. B voit les mêmes valeurs sans recharger.
  - Réf. : 50 § 5.1 · ApplyRoomPreset · RoomPresetController
- [ ] **Appliquer Hardcore, Découverte, Classique**
  - Faire : Cliquer tour à tour « Hardcore », « Découverte », puis « Classique ».
  - Attendu : - Hardcore : 10 manches, 5 images, 45 s, révélation 10 s, Expert.
    - Découverte : 5 manches, 3 images, 60 s, révélation 15 s, Facile. Pas d’avertissement de manche longue, car 60 s n’est pas au-dessus du seuil.
    - Classique : retour aux valeurs par défaut (10 manches, 3 images, 30 s, 8 s, Normal).
  - Réf. : 10 § 6.3 · SettingPresetCatalog::inputFor
- [ ] **Un preset remet places et retardataires à leur défaut**
  - Faire : Descendre « Places » à 4 et activer « Retardataires », puis cliquer « Classique ».
  - Attendu : « Places » revient à 12 et « Retardataires » repasse désactivé : le preset repart des défauts pour tout ce qu’il ne chiffre pas. Aucun encart « Réglages ajustés » n’apparaît.
  - Réf. : 50 § 5.3 · ApplyRoomPreset · SettingPresetCatalog::settingsFor (fromInput)
- [ ] **Presets grisés, toujours cliquables** ⚠️
  - Faire : Jouer une partie complète au preset « Rapide » (8 manches, voir la section partie), puis cliquer « Rejouer » sur le podium. Regarder les presets au lobby, puis cliquer « Classique ».
  - Attendu : « Classique » et « Hardcore » (10 manches) sont grisés, avec une icône et « Pas assez de films pour ce preset. ». « Rapide » (8 manches) et « Découverte » (5) ne le sont pas. Cliquer « Classique » l’applique quand même, et le lobby montre alors le blocage du vivier.
  - Réf. : 50 § 5.3 · RoomSettingsPresenter::presets

### 3.7 Vivier : compteur, blocage et remèdes

- [ ] **Compteur de films jouables** ⭐
  - Faire : Regarder, sous les réglages, la ligne du vivier chez A et chez B.
  - Attendu : Les deux lisent « Films jouables : 16 pour 10 manches. ».
  - Réf. : 50 § 9.2 · pool-status.tsx · PoolReporter
- [ ] **Blocage par le nombre de manches et son remède**
  - Faire : En A, glisser « Nombre de manches » à 30. Cliquer ensuite le remède proposé.
  - Attendu : Le compteur lit « Films jouables : 16 pour 30 manches. ». Un encart « Lancement impossible : pas assez de films jouables avec ces réglages. » donne la cause « Il y a plus de manches que de films jouables. ». Chez A, un bouton « Jouer 16 manches (16 films jouables) » apparaît, et « Lancer la partie » est désactivé avec le même motif. Après le clic : 16 manches, blocage levé (un vivier égal au nombre de manches suffit).
  - Réf. : 50 § 9.2, § 9.4 · 30 § 4.3 · PoolReporter (roundsCount)
- [ ] **Blocage vu par un non-hôte**
  - Faire : Pendant le blocage précédent, regarder chez B.
  - Attendu : B voit le même compteur, le même encart et la même cause, mais aucun bouton de remède.
  - Réf. : 50 § 8.1 · pool-status.tsx (remedies = null)
- [ ] **Non-répétition et remède « nouveau salon » (D28)**
  - Faire : Après une partie « Rapide » complète puis « Rejouer » (8 films joués), glisser « Nombre de manches » à 10.
  - Attendu : Le compteur lit « Films jouables : 8 pour 10 manches. ». Le blocage donne deux causes, dans cet ordre : « Ce salon a déjà joué la plupart des films disponibles. », puis « Il y a plus de manches que de films jouables. ». Remèdes proposés à l’hôte : « Créer un nouveau salon (16 films jouables avec ces réglages) » et « Jouer 8 manches (8 films jouables) ». Aucun bouton « Autoriser les films déjà joués ».
  - Réf. : 50 § 9.3 (D28 du 23/09) · RoomSettingsPresenter::state · PoolReporter
- [ ] **Suivre le remède « Créer un nouveau salon »**
  - Faire : Dans l’état précédent, cliquer « Créer un nouveau salon (16 films jouables avec ces réglages) », puis créer le salon.
  - Attendu : Le clic mène à `/r/new` (formulaire « Créer un salon »). Le nouveau salon, à mémoire vide, affiche « Films jouables : 16 pour 10 manches. ».
  - Réf. : 50 § 9.3 · pool-status.tsx (lien room.create)
- [ ] **Catalogue vide : aucun remède** ⚠️
  - Faire : 1. Lancer `php artisan backup:snapshot`, puis `php artisan migrate:fresh`, puis `php artisan db:seed --class=PlatformDataSeeder`.
    2. Créer un salon et regarder le lobby.
    3. Restaurer par `php artisan backup:snapshot` puis `php artisan migrate:fresh --seed`.
  - Attendu : Le compteur lit « Films jouables : 0 pour 10 manches. ». Le blocage affiche « Le catalogue ne compte pas encore assez de films pour une partie. », sans aucun remède, et « Lancer la partie » est désactivé. Les 4 presets sont grisés avec « Pas assez de films pour ce preset. ».
  - Réf. : 50 § 9.2 · pool-status.tsx (room.pool.no_remedy)
- [ ] **Remède « Passer à 4 images par manche » avec une banque réduite** ⚠️
  - Faire : En curateur, dépublier l’image de niveau 2 de 7 films de démonstration : sur `/admin/catalog/<id>/bank`, « Dépublier » sur la carte du niveau 2, puis « Dépublier l’image » (c’est la préparation de l’item D19 du solo). Dans un salon neuf, regarder les presets, appliquer « Hardcore », puis cliquer le premier remède. Rétablir ensuite les 7 images par « Conforme, publier » dans /admin/review.
  - Attendu : « Hardcore » est grisé avec « Pas assez de films pour ce preset : jouable avec 4 images par manche. » ; les trois autres presets ne le sont pas. Après « Hardcore », le lobby affiche « Films jouables : 9 pour 10 manches. » et les causes « Trop peu de films ont assez d’images pour ce nombre d’images par manche. », puis « Il y a plus de manches que de films jouables. ». L’hôte voit deux remèdes : « Passer à 4 images par manche (16 films jouables) » et « Jouer 9 manches (9 films jouables) ». Le premier met « Images par manche » à 4 et lève le blocage (« Films jouables : 16 pour 10 manches. »).
  - Réf. : 50 § 5.3, § 9.2 · PoolReporter (LowerFramesPerRound) · RoomSettingsPresenter::presets
- [ ] **Deux films regroupés comptent pour une seule œuvre**
  - Faire : En curateur, sur la fiche de « Toy Story », onglet « Même œuvre », saisir dans « Regrouper avec un autre film » l’identifiant catalogue de « Ratatouille » (lu dans l’onglet « Identité » de sa fiche), cliquer « Regrouper », puis confirmer par « Regrouper ». Ouvrir un lobby neuf, régler « Nombre de manches » à 15 et lancer avec B. Dans `php artisan tinker` interactif, taper `App\Models\Game::latest('id')->first()->rounds()->with('movie')->get()->pluck('movie.title_original')->all()`. Finir par « Retirer du groupe ».
  - Attendu : Le lobby affiche « Films jouables : 15 pour 10 manches. », puis « Films jouables : 15 pour 15 manches. », sans blocage. Le tirage compte 15 films et contient « Toy Story » ou « Ratatouille », jamais les deux : l’exclusion mutuelle dans un même tirage est la seule conséquence d’un groupe. Après le retrait du groupe, un lobby neuf revient à 16.
  - Réf. : 30 § 1.2 · 20 § 9.4 · movie_group · PoolReporter (countWorks)

### 3.8 Vue d’un non-hôte et diffusion en direct

- [ ] **Lecture seule pour les non-hôtes** ⭐
  - Faire : En B, parcourir le lobby et tenter de bouger un curseur ou de cocher une option.
  - Attendu : On lit « Seul l’hôte peut modifier les réglages. » et l’en-tête « En attente du lancement par l’hôte. ». Les réglages sont visibles mais inactifs. Il n’y a ni section Presets, ni bouton « Lancer la partie », ni geste sur les sièges. « Quitter le salon » et « Aide » restent disponibles.
  - Réf. : 50 § 8.1 · room-settings-form.tsx (editable=false) · RoomPolicy
- [ ] **Réglages changés vus en direct** ⭐
  - Faire : En A, cocher N = 4. Observer B sans recharger (facultatif : lecteur d’écran actif chez B).
  - Attendu : Chez B, les valeurs passent à 4 images en quelques secondes au plus : la diffusion est anti-rebondie et passe par la file `game`. Un lecteur d’écran annonce « L’hôte a modifié les réglages. ».
  - Réf. : 50 § 8.2, § 8.3 · BroadcastLobbyState · use-lobby-state.ts
- [ ] **Rattrapage après une coupure** ⚠️
  - Faire : 1. En B, passer le Réseau des outils de dev sur « Hors ligne ».
    2. En A, changer « Nombre de manches » et appliquer un preset.
    3. Remettre B en ligne.
  - Attendu : Une fois B revenu en ligne, ses réglages, son compteur de vivier et son éventuel blocage se mettent à jour d’eux-mêmes, sans rechargement manuel.
  - Réf. : 50 § 8.2 (rechargement partiel settings, presets)

### 3.9 Gestes de salon : retirer, nommer hôte, quitter

- [ ] **Retirer un joueur (hôte)** ⭐
  - Faire : 1. En A, sur la ligne de Bruno, cliquer « Retirer du salon ».
    2. Vérifier que le focus est sur « Annuler », puis fermer par Échap.
    3. Rouvrir, puis confirmer par « Retirer du salon ».
  - Attendu : La boîte « Retirer du salon » affiche « Retirer Bruno ? Ce joueur ne pourra pas revenir dans ce salon. ». Échap la ferme sans effet. Après confirmation, la ligne de Bruno porte « Retiré », sans plus aucun bouton, et le compteur baisse (« Joueurs (1 sur 12) »).
  - Réf. : 50 § 11.3 (D15 du 23/09) · KickController · KickSeat · seat-actions.tsx
- [ ] **Ce que voit le joueur retiré** ⭐
  - Faire : Observer la fenêtre de B au moment du retrait.
  - Attendu : Un bref avis « L’hôte vous a retiré du salon. » s’affiche. La fenêtre bascule ensuite vers « Rejoindre le salon », qui dit « L’hôte vous a retiré de ce salon : vous ne pouvez pas y revenir. » et propose un lien « Accueil ». Ni formulaire, ni mention des CGU.
  - Réf. : 50 § 8.2 (seat.kicked) · RoomEntryController (entry kicked) · game.seat.kicked
- [ ] **Jeton expulsé refusé ensuite** ⚠️
  - Faire : En B, recharger `/r/<CODE>`, puis ouvrir `/r/<CODE>/join`. En C (autre identité), essayer d’entrer sous « Bruno », puis sous « Bruno2 ».
  - Attendu : B retombe toujours sur la page « vous ne pouvez pas y revenir », sans formulaire. En C, « Bruno » donne « Ce pseudo est déjà pris dans ce salon. » : le pseudo d’un joueur expulsé reste réservé. « Bruno2 » entre normalement.
  - Réf. : 50 § 7.3 S3, S5 · JoinRefusal::Kicked
- [ ] **Retirer un siège parti** ⚠️
  - Faire : 1. B clique « Quitter le salon » et confirme.
    2. En A, sur la ligne de Bruno (« Parti »), cliquer « Retirer du salon » et confirmer.
    3. En B, rouvrir `/r/<CODE>`.
  - Attendu : La ligne de Bruno passe de « Parti » à « Retiré », sans bouton. B ne retrouve pas son siège : il arrive sur « Rejoindre le salon » avec « L’hôte vous a retiré de ce salon : vous ne pouvez pas y revenir. ».
  - Réf. : 50 § 11.3 · seat-actions.tsx (retrait d’un siège parti) · KickSeat
- [ ] **Nommer un autre hôte** ⭐
  - Faire : En A, sur la ligne de Bruno (connecté), cliquer « Nommer hôte », puis confirmer.
  - Attendu : La boîte affiche « Confier le rôle d’hôte à Bruno ? ». Après confirmation, le badge « Hôte » passe sur Bruno.
    - Chez A : « En attente du lancement par l’hôte. » et « Seul l’hôte peut modifier les réglages. », sans presets ni bouton de lancement.
    - Chez B : « Vous êtes l’hôte : vous réglez et lancez la partie. » et tous les contrôles.
  - Réf. : 50 § 11.4 · HostTransferController · HandOverHost · host.changed
- [ ] **Gestes proposés selon l’état du siège** ⚠️
  - Faire : En hôte, regarder sa propre ligne, puis celle d’un siège « Déconnecté » ou « Parti ». En non-hôte, regarder toutes les lignes.
  - Attendu : Sur sa propre ligne, aucun geste. Sur un siège déconnecté ou parti, seulement « Retirer du salon », jamais « Nommer hôte ». Un non-hôte ne voit aucun bouton, sur aucune ligne.
  - Réf. : seat-list.tsx · seat-actions.tsx
- [ ] **Quitter le salon (non-hôte)** ⭐
  - Faire : En B, cliquer « Quitter le salon », puis confirmer.
  - Attendu : La boîte affiche « Quitter le salon ? Vous pourrez revenir avec le lien. ». Après confirmation, B arrive sur l’accueil `/`. Chez A, Bruno porte « Parti » et le compteur baisse.
  - Réf. : 50 § 11.4 · LeaveRoomController · LeaveRoom
- [ ] **Revenir par le lien après un départ**
  - Faire : En B, rouvrir `/r/<CODE>`.
  - Attendu : B arrive directement dans le lobby, sans formulaire et sous le même pseudo. Le badge « Parti » disparaît chez A (au premier battement de B) et le compteur remonte.
  - Réf. : 50 § 7.3 S3 (reprise) · 60 § 13.1
- [ ] **L’hôte quitte : transfert immédiat**
  - Faire : En A (hôte), avec B et C connectés, cliquer « Quitter le salon » et confirmer. Rouvrir ensuite le lien en A.
  - Attendu : Le rôle passe aussitôt au siège connecté le plus ancien, B : badge « Hôte » et contrôles d’hôte chez B. De retour, A est un simple joueur ; l’ancien hôte ne récupère pas le rôle.
  - Réf. : 50 § 11.2 · TransferHost::automatic
- [ ] **L’hôte seul quitte puis revient** ⚠️
  - Faire : Dans un salon où A est seul, cliquer « Quitter le salon » et confirmer. Rouvrir `/r/<CODE>` en A, attendre une dizaine de secondes, puis recharger la page.
  - Attendu : Au retour, A retrouve son siège, mais le salon n’a pas d’hôte à ce premier affichage : aucun badge « Hôte », l’en-tête dit « En attente du lancement par l’hôte. » et les réglages sont en lecture seule. Après le rechargement (A est reconnecté par son battement), la réparation d’hôte au rendu lui rend le rôle : badge « Hôte » et « Vous êtes l’hôte : vous réglez et lancez la partie. ». Si le rôle ne revient pas, le noter.
  - Réf. : 50 § 11.1-11.2 · LeaveRoom · RoomController::repairHost · TransferHost::automatic

### 3.10 Présence et onglets

- [ ] **Battement de présence**
  - Faire : En A, onglet Réseau ouvert, rester une trentaine de secondes dans le lobby.
  - Attendu : Un `POST /r/<CODE>/heartbeat` part toutes les 10 s environ et répond 204.
  - Réf. : 60 § 13.1 · RoomHeartbeatController · use-heartbeat.ts · EngineConstants heartbeat_interval_ms
- [ ] **Siège déconnecté puis parti**
  - Faire : Fermer l’onglet de B, sans « Quitter », puis observer A pendant 2 minutes. Rouvrir ensuite le lien en B.
  - Attendu : Environ 25 s après le dernier battement, Bruno porte « Déconnecté » et compte encore dans « Joueurs ». Environ 60 s plus tard (délai avant « parti », 60 s par défaut), il porte « Parti » et le compteur baisse. Au retour de B, les badges disparaissent.
  - Réf. : 60 § 13.2 · SweepSeatPresence · EngineConstants disconnect_after_ms · disconnectGraceSeconds
- [ ] **Réparation d’hôte quand l’hôte disparaît** ⭐
  - Faire : Avec A (hôte) et B connectés, fermer l’onglet de A et observer B.
  - Attendu : Pendant environ 25 s + 60 s, Alice garde « Hôte » avec « Déconnecté » : la simple déconnexion ne transfère pas le rôle. Quand elle passe « Parti », le badge « Hôte » va à Bruno. B obtient les presets et les réglages éditables, ainsi que « Lancer la partie », désactivé avec « Il faut au moins 2 joueurs connectés. ».
  - Réf. : 50 § 11.1-11.2 · SweepSeatPresence · TransferHost::automatic
- [ ] **Hors ligne : bandeau et contrôles d’hôte**
  - Faire : En A (hôte), passer le Réseau des outils de dev sur « Hors ligne », puis le remettre en ligne.
  - Attendu : Hors ligne, le bandeau « Vous êtes hors ligne. Le jeu ne s’interrompt pas pour autant : vérifiez votre connexion. » s’affiche. Réglages, presets, remèdes, « Lancer la partie » et gestes sur les sièges sont désactivés ; « Quitter le salon » reste cliquable. De retour en ligne, le bandeau disparaît et les contrôles reviennent.
  - Réf. : 50 § 8.1 (déconnexion) · connection-banner.tsx · use-lobby-state canWrite
- [ ] **Second onglet du même joueur**
  - Faire : En A (hôte), ouvrir `/r/<CODE>` dans un second onglet de la même fenêtre. Revenir au premier onglet, puis le recharger.
  - Attendu : Le second onglet prend la main. Le premier affiche aussitôt « Vous jouez désormais dans un autre onglet : celui-ci n’affiche plus que la partie. Rechargez la page pour y reprendre la main. », et ses contrôles d’hôte sont inactifs. Recharger le premier onglet lui rend la main ; c’est alors le second qui passe en lecture seule.
  - Réf. : 60 § 12.7 · ClaimSeatTab · seat.superseded · read-only-notice

### 3.11 Capacité et salon complet

- [ ] **Salon complet**
  - Faire : En A, régler « Places » à 2, avec A et B assis. En C, ouvrir le lien.
  - Attendu : La page « Rejoindre le salon » affiche « Ce salon est complet. » et un lien « Accueil », sans formulaire ni mention des CGU.
  - Réf. : 50 § 7.2 (entry full) · JoinRefusal::Full
- [ ] **Complet entre l’affichage et l’envoi** ⚠️
  - Faire : 1. Mettre les places à 2, avec A seul.
    2. En C, ouvrir le formulaire d’entrée sans l’envoyer.
    3. En B, entrer dans le salon.
    4. En C, saisir un pseudo et cliquer « Entrer ».
  - Attendu : C reste sur la page d’entrée, qui affiche désormais « Ce salon est complet. » sans formulaire. Aucun siège n’est créé.
  - Réf. : 50 § 7.3 S4 (sous verrou) · RoomEntryController@store
- [ ] **Reprise au-delà de la capacité** ⚠️
  - Faire : Places à 2, A et B assis. B quitte, C entre (2 sur 2), puis B rouvre le lien.
  - Attendu : B reprend son siège sans refus. Le lobby affiche « Joueurs (3 sur 2) ». Le curseur « Places » a pour borne basse 2, la capacité courante, et non 3.
  - Réf. : 50 § 7.3 S3 · § 10 · room-settings-form.tsx capacityMin

### 3.12 Retardataires (entrée pendant une partie)

- [ ] **Retardataires activés : entrée à la manche suivante**
  - Faire : En A, activer « Retardataires », puis lancer avec B. Pendant la manche 1, ouvrir le lien en C, saisir « Chloé » et cliquer « Entrer ».
  - Attendu : La page d’entrée affiche « Une partie est en cours : vous entrerez à la manche suivante, sans aucun point. » au-dessus du formulaire. Après « Entrer », C voit la partie avec « Vous entrez dans la partie à la manche 2. », sans image ni saisie pour la manche en cours. A et B voient Chloé apparaître dans les joueurs.
  - Réf. : 50 § 15 (D35 du 23/09) · TakeSeat S7 · game.round.waiting_next
- [ ] **Retardataires désactivés : attente de la partie suivante**
  - Faire : Même scénario, avec « Retardataires » désactivé.
  - Attendu : La page d’entrée affiche « Une partie est en cours : vous attendrez dans le salon et jouerez la suivante. ». Après « Entrer », C lit « Une partie est en cours : vous jouerez la prochaine. ». Après « Rejouer », C est au lobby comme les autres.
  - Réf. : 50 § 15.3 · RoomEntryController (in_progress) · room.lobby.waiting_next_game
- [ ] **Retardataires activés mais plus aucune manche à venir** ⚠️
  - Faire : Avec « Retardataires » activé et le nombre de manches à 3, ouvrir le lien en C pendant la dernière manche, ou sur le podium.
  - Attendu : La page affiche le message d’attente « Une partie est en cours : vous attendrez dans le salon et jouerez la suivante. », et non celui de l’entrée à la manche suivante.
  - Réf. : RoomEntryController::lateJoinOpen · Round::lateJoinableAt

### 3.13 « Lancer la partie » : conditions et refus

- [ ] **Seul dans le salon** ⭐
  - Faire : En A, seul dans un salon neuf, regarder le bouton de lancement.
  - Attendu : « Lancer la partie » est désactivé. Sous lui, le motif « Il faut au moins 2 joueurs connectés. » lui est lié.
  - Réf. : 50 § 8.1 · RoomSettingsBounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH · lobby.tsx
- [ ] **Lancement nominal** ⭐
  - Faire : Avec B connecté, cliquer « Lancer la partie ».
  - Attendu : Le bouton devient « Lancement… » avec un indicateur. A et B passent ensuite à l’état de partie dans la même page, sans changer d’URL (suite : section partie).
  - Réf. : 50 § 12 · LaunchController · LaunchGame
- [ ] **Le second joueur se déconnecte** ⚠️
  - Faire : Avec A et B, fermer l’onglet de B et attendre environ 25 s en regardant A.
  - Attendu : B passe « Déconnecté ». Le bouton se désactive de nouveau, avec « Il faut au moins 2 joueurs connectés. ».
  - Réf. : 50 § 12.2 L6 · lobby.tsx (connectedSeats)
- [ ] **Plusieurs motifs à la fois** ⚠️
  - Faire : Seul dans le salon, glisser « Nombre de manches » à 30.
  - Attendu : Sous le bouton désactivé, les deux motifs s’affichent en liste : « Lancement impossible : pas assez de films jouables avec ces réglages. » et « Il faut au moins 2 joueurs connectés. ».
  - Réf. : lobby.tsx (motives)
- [ ] **Vivier devenu insuffisant pendant que le lobby est ouvert** ⚠️
  - Faire : Mettre A (hôte) et B dans un lobby, dans deux fenêtres distinctes, et régler « Nombre de manches » à 16 (« Films jouables : 16 pour 16 manches. »). Dans un autre navigateur, en curateur, dépublier « Inception » depuis sa fiche (« Dépublier le film », avec un motif). Sans recharger ni changer d’onglet chez A, puisqu’un retour sur l’onglet relit l’état, cliquer « Lancer la partie ». Republier « Inception » à la fin.
  - Attendu : Aucune partie ne démarre. Chez A, un encart dans la page affiche « Seulement 15 films jouables pour 16 manches. », sans toast. Chez A et chez B, le compteur passe à « Films jouables : 15 pour 16 manches. » avec le blocage ; A voit le remède « Jouer 15 manches (15 films jouables) », et « Lancer la partie » se désactive. Avant le clic, le bouton n’était pas grisé : le vivier du lobby ne suit pas le catalogue en direct, et c’est le serveur qui tranche.
  - Réf. : 50 § 12.2 · RoomRefusal::PoolInsufficient · LaunchController::refused

### 3.14 Drainage de déploiement

- [ ] **Bandeau et création toujours possible** ⭐
  - Faire : 1. Dans un terminal, lancer `php artisan deploy:drain --window=10`. Il affiche « Drainage commencé : aucune nouvelle partie ne peut plus être lancée. Attente de la fin des parties en cours. », puis « Fenêtre libre ouverte jusqu’à … » au bout de quelques secondes, faute de partie en cours.
    2. Ouvrir `/r/new` et créer un salon.
    3. Entrer en B par le lien.
  - Attendu : Sur `/r/new`, sur la page d’entrée et dans le lobby s’affiche le bandeau « Une mise à jour du site est en préparation : aucune nouvelle partie ne peut être lancée pour le moment. Les parties en cours continuent normalement. ». La création et l’entrée restent possibles.
  - Réf. : 50 § 14 (D32 du 23/09) · 100 § 11 · DeployDrain · maintenance-banner.tsx
- [ ] **Lancement désactivé pendant le drainage** ⭐
  - Faire : Dans ce lobby (A hôte, B connecté), regarder « Lancer la partie ».
  - Attendu : Le bouton est désactivé, avec le motif « Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. Réessayez un peu plus tard. ».
  - Réf. : 50 § 8.1 · lobby.tsx (maintenance)
- [ ] **Levée du drapeau sans rechargement (BUG-P2)** ⭐
  - Faire : Sans toucher au lobby, lancer `php artisan deploy:release`, puis attendre une dizaine de secondes.
  - Attendu : Le terminal affiche « Drapeau de drainage levé : les lancements sont de nouveau permis. ». Dans les 10 s environ (ou au retour sur l’onglet), le bandeau disparaît et « Lancer la partie » redevient cliquable, sans aucun rechargement manuel.
  - Réf. : 50 § 8.1 (amendé le 28/09, BUG-P2) · use-maintenance-refresh.ts
- [ ] **Lobby ouvert avant le drainage : refus du serveur** ⚠️
  - Faire : 1. Ouvrir un lobby (A et B) AVANT de lancer `php artisan deploy:drain --window=10`.
    2. Sans recharger, cliquer « Lancer la partie ».
    3. Finir par `php artisan deploy:release`.
  - Attendu : Le bouton était encore actif. Le clic affiche dans la page l’encart « Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. Réessayez un peu plus tard. », sans toast. Le bandeau apparaît et le bouton se désactive avec ce motif. Aucune partie n’est lancée.
  - Réf. : 50 § 12.2 L7 · RoomRefusal::Draining · LaunchController

### 3.15 Salon expiré et liens invalides

- [ ] **Salon archivé : page « Salon expiré »**
  - Faire : 1. Fermer tous les onglets du salon.
    2. Lancer `php artisan tinker --execute="App\Models\Room::where('room_code','<CODE>')->update(['last_activity_at' => now()->subHours(25)]);"`, puis `php artisan room:archive-idle`.
    3. Quelques secondes plus tard, ouvrir `/r/<CODE>`, puis `/r/<CODE>/join`.
  - Attendu : Les deux adresses mènent à la page « Salon expiré », en sombre. Elle affiche « Ce salon a été fermé après une période d’inactivité. Créez-en un nouveau pour rejouer. », un bouton « Créer un salon » et un bouton « Accueil », sans autre information sur le salon. Dans Réseau : statut 410, précédé d’une redirection 303 pour `/join`.
  - Réf. : 50 § 16.2-16.3 · ArchiveIdleRooms · ArchiveRoom · pages/game/room-expired.tsx
- [ ] **Archivage anticipé d’un lobby jamais lancé (2 h)** ⚠️
  - Faire : 1. Tous onglets fermés, reculer `last_activity_at` de 3 h par la même commande tinker (`subHours(3)`), puis lancer `php artisan room:archive-idle` : d’abord sur un salon qui n’a jamais lancé de partie.
    2. Refaire le geste sur un salon revenu au lobby après une partie et « Rejouer ».
  - Attendu : Le salon jamais lancé mène à « Salon expiré ». Celui qui a déjà lancé une partie reste ouvert : il ne sera archivé qu’à 24 h d’inactivité.
  - Réf. : 50 § 16.2 · RoomExpiry LOBBY_IDLE_MINUTES / ROOM_IDLE_MINUTES · ArchiveRoom (lobbyOnly)
- [ ] **Expiration avec un onglet ouvert** ⚠️
  - Faire : Garder le lobby ouvert en A et archiver le salon d’un seul geste : `php artisan tinker --execute="app(App\Actions\Room\ArchiveRoom::class)->handle(App\Models\Room::where('room_code','<CODE>')->firstOrFail(), now());"`.
  - Attendu : Sans aucune action de A, son onglet bascule seul sur « Salon expiré ».
  - Réf. : 50 § 8.2 (room.archived) · § 16.3 · ArchiveRoom
- [ ] **Code inconnu ou mal formé** ⚠️
  - Faire : Avec `APP_DEBUG=false`, ouvrir `/r/ZZZZZZ` (code bien formé mais inconnu), puis `/r/IIIIII` (lettre hors alphabet).
  - Attendu : Page « Page introuvable » avec « Cette page n’existe pas ou n’existe plus. Vérifiez l’adresse, ou le code du salon si vous rejoigniez une partie. » et « Retour à l’accueil ». Statut 404.
  - Réf. : 50 § 6.3 · Room::resolveRouteBinding · ErrorPageResponder

### 3.16 Langue, thème, portrait mobile, aide

- [ ] **FR ↔ EN sur la création et l’entrée**
  - Faire : Sur `/r/new`, choisir « English » dans le sélecteur de langue de l’en-tête, envoyer un pseudo vide, puis revenir en « Français ».
  - Attendu : En anglais : « Create a room », bouton « Create room », « By continuing, you accept the terms of use. », et le message d’erreur du pseudo en anglais. Tout repasse en français au retour, sans perte de la saisie.
  - Réf. : 05 · CLAUDE § 7 règle 4 · translations:room,legal
- [ ] **FR ↔ EN dans le lobby, par joueur**
  - Faire : En B, passer en anglais par le sélecteur de langue en icône, en bas à gauche du lobby. Regarder A.
  - Attendu : B voit « Room », « Players (2 of 12) », « Copy link » et les réglages en anglais. Il garde son siège, sans changement d’URL ni rechargement visible. A reste en français : la langue est propre à chaque joueur.
  - Réf. : 05 · 90 § 8 · game-layout.tsx (LanguageSwitcher iconOnly)
- [ ] **Thème : pages publiques suivies, lobby forcé sombre**
  - Faire : Sur `/r/new`, choisir « Apparence » → « Clair », puis créer un salon. Ouvrir ensuite `/r/<CODE>/join` dans une autre identité réglée sur « Clair ».
  - Attendu : `/r/new` et la page d’entrée sont en clair. Le lobby (comme « Salon expiré ») reste toujours sombre, sans flash clair au chargement. De retour sur l’accueil, le thème clair revient.
  - Réf. : 90 § 2.2 · game.appearance · GameLayout (forcé sombre)
- [ ] **Portrait 375 px**
  - Faire : En mode appareil iPhone SE (375 px), parcourir `/r/new`, la page d’entrée, puis un lobby avec un pseudo de 20 « W ». Ouvrir la boîte « Retirer du salon ».
  - Attendu : - Aucun défilement horizontal.
    - Sur `/r/new`, les avatars s’affichent sur 3 colonnes.
    - Le lobby défile à l’intérieur, et la barre du bas (langue, pied de page replié) reste visible.
    - Le pseudo long est tronqué par « … » sans élargir la ligne.
    - Les presets tiennent sur une colonne.
    - La boîte de dialogue tient dans l’écran.
    - Les cibles font au moins 44 px.
  - Réf. : CLAUDE § 7 règle 10 · 90 § 2.3 · seat-list.tsx (contain-inline-size)
- [ ] **Aide « Réponses et points » selon N**
  - Faire : Dans le lobby, cliquer « Aide », lire la ligne du bonus, puis fermer. Passer ensuite à N = 4, puis à N = 5, en rouvrant l’aide à chaque fois.
  - Attendu : La feuille s’intitule « Réponses et points ». Le bonus y vaut « jusqu’à 50 % de la valeur de l’image » à N = 3 (défaut), 33 % à N = 4 et 25 % à N = 5.
  - Réf. : 80 § 3.3 (D22 du 23/09) · game-help.tsx · PlatformLimits speedBonusMaxPercent

### 3.17 Limites de débit

- [ ] **Création : 10 salons par heure et par adresse** ⚠️
  - Faire : Avec `APP_DEBUG=false`, créer 11 salons de suite depuis `/r/new` en moins d’une heure. Les envois refusés et les salons créés en B ou C comptent aussi : même adresse 127.0.0.1. Pour réinitialiser, lancer `php artisan cache:clear` (en local seulement, jamais en production).
  - Attendu : Le 11e envoi affiche la page « Trop de demandes » avec « Trop de demandes en peu de temps. Patientez quelques instants avant de réessayer. ». Aucun salon n’est créé.
  - Réf. : 50 § 17.3 · RoomRateLimits (creates_per_hour) · throttle:room-create
- [ ] **Entrée : 10 envois par minute et par jeton** ⚠️
  - Faire : Avec `APP_DEBUG=false`, sur la page d’entrée, envoyer 11 fois de suite en moins d’une minute un pseudo invalide (ex. « A »).
  - Attendu : Les 10 premiers envois affichent l’erreur du pseudo. Le 11e donne la page « Trop de demandes ». Une identité sans jeton est comptée sur l’adresse IP.
  - Réf. : 50 § 17.3 · RoomRateLimits (joins_per_minute) · throttle:room-join
- [ ] **Écritures de jeu : 30 par minute et par jeton** ⚠️
  - Faire : Avec `APP_DEBUG=false`, en hôte, cliquer alternativement « Rapide » et « Classique » une trentaine de fois en moins d’une minute. Rouvrir ensuite le lien du salon.
  - Attendu : Une fois le budget dépassé (battements compris), la page « Trop de demandes » remplace le lobby. En rouvrant le lien, on retrouve le lobby et le siège d’hôte.
  - Réf. : 60 § 10.3 · EngineConstants (game_writes_per_minute) · ErrorPageResponder

### 3.18 Hors périmètre (non livré, ne pas essayer)

- Onglet « Réglages avancés » (durée des paliers, points par palier, bonus de rapidité, « Pas de film déjà joué », tentatives, longueur de réponse, « Délai avant « parti » ») : prévu au J2 (L50-10, `ADVANCED_TAB_AVAILABLE = false`). Le serveur refuse `advanced: true` et ces champs (`not_editable`).
- Sélecteur de thèmes (« Thèmes ») : masqué au J1 (J2, L50-11, seuil de 150 œuvres). Il n’y a donc pas non plus de cause « thèmes », de remède « Retirer les thèmes » ni d’avis « thème retiré du site ».
- Remède « Autoriser les films déjà joués » : retiré du rapport tant que l’onglet Avancé n’existe pas (D28 du 23/09).
- Avertissements « Un palier tardif rapporte autant ou plus… » et « Aucun palier ne rapporte de point… », et rapports « redécoupé », « remis à durée égale », « barème personnalisé remplacé », « réglage avancé remplacé » : ils ne naissent que d’un barème ou de paliers réglés dans l’onglet Avancé (J2). Les rapports « valeur par défaut », « retiré », « illisible », « thème enlevé » et « relevé au nombre de joueurs » ne naissent que d’une évolution du schéma des réglages : impossibles à provoquer à la main.
- Configurations de salon sauvegardées et configuration par défaut d’un compte : J2 (L50-12, L50-13).
- Connexion Discord/Google et photo du provider : J2. Téléversement d’avatar : v1.1.
- Signalement d’un pseudo et masquage après deux signalements : aucune route livrée sur `develop`.
- Remède « Passer à N images par manche » et motif de grisage « Pas assez de films pour ce preset : jouable avec N images par manche. » : livrés, mais non reproductibles avec le catalogue de démo, dont les 16 films couvrent les 5 niveaux (vivier identique à tout N). À vérifier sur le catalogue réel après le pilote.
- Refus serveur sans geste d’interface pour les provoquer, couverts par les tests automatisés :
- « Les réglages ont été mis à jour : vérifiez-les, puis relancez. » (changement de version du schéma) ;
- « Il faut au moins 2 joueurs connectés pour lancer. » et « Seulement … films jouables pour … manches. » (le bouton est déjà désactivé côté client) ;
- « Ce salon a expiré. » ;
- « Le lancement a échoué. Réessayez dans un instant. » (échec technique) ;
- « Vous ne pouvez pas vous retirer vous-même : quittez le salon. » ;
- « Ce joueur n’est pas connecté. » ;
- un geste d’hôte fait par un non-hôte (403).
- « Rejouer » et le podium, le déroulé des manches, la saisie, le score, et les gestes d’hôte depuis la feuille « Joueurs » en cours de partie : couverts par les sections partie et podium.

## 4. Partie multijoueur : déroulé d’une manche, saisie et score

Cette section couvre l’intérieur d’une manche multijoueur : les paliers qui s’ouvrent, le chrono piloté par le serveur, l’image, la valeur du palier, la saisie en texte libre et ses règles de validation, le QCM selon la difficulté, le joueur verrouillé, le score, la fin anticipée et le cas du joueur seul connecté. Le lancement, la révélation, le podium, les déconnexions, le second onglet et le solo sont traités dans d’autres sections. Chaque recette de validation cite de vrais titres du catalogue de démo, dont le verdict a été vérifié sur le normaliseur et l’apparieur réels.

**Prérequis**

- Base de démo : `php artisan backup:snapshot` (code 0 exigé), puis `php artisan migrate:fresh --seed`. `backup:snapshot` appelle `mysqldump`, absent du PATH de ce poste Windows : lancez-le là où il existe (par exemple dans la VM Homestead) ou installez les outils clients MySQL. On obtient 16 films publiés, tous jouables de 2 à 5 images par manche : Blanche-Neige et les Sept Nains, Le Voyage de Chihiro, Mon voisin Totoro, Le Roi Lion, Toy Story, Le Monde de Nemo, Ratatouille, Matrix, Jurassic Park, Retour vers le futur, Shining, Pulp Fiction, Inception, Star Wars : Un nouvel espoir, Star Wars : L’Empire contre-attaque, Dune : Deuxième partie.
- `composer dev` doit tourner : il sert http://127.0.0.1:8000 et lance les files game et default ainsi que Reverb. Sans ces files, aucun palier ne s’ouvre. Ne lancez pas la suite Pest en même temps.
- Deux joueurs : une fenêtre normale et une fenêtre privée du même navigateur, car l’identité invitée vit dans le cookie player_token. Pour un troisième joueur, prenez un autre navigateur : deux fenêtres privées d’un même navigateur partagent le même cookie.
- Salon de test : sur /r/new, pseudo, avatar, puis « Créer le salon ». Le second joueur ouvre /r/<code>/join et clique « Entrer ». L’hôte règle le salon puis clique « Lancer la partie », ce qui exige 2 joueurs connectés. Un salon neuf a les réglages par défaut (ceux du preset Classique) : 10 manches, 3 images, 30 s, « Normal », révélation de 8 s. Pour aller plus vite : « Durée de la révélation » au minimum, et le bouton « Manche suivante » de l’hôte pendant la révélation.
- Les images de démo sont des aplats gris identiques. Le changement de palier ne se voit donc pas dans le cadre : on le vérifie par « … points en jeu », par l’attribut alt (F12 > Éléments) et par l’onglet Réseau.
- Connaître le film en cours : ouvrez `php artisan tinker` en mode interactif et collez `$m = App\Models\Round::query()->where('status','running')->latest('id')->first()?->movie; [$m?->title_original, $m?->titles->pluck('title')->all(), $m?->aliases->pluck('alias')->all()]`. Rejouez la ligne (flèche haut, puis Entrée) une fois l’image affichée. Cette ligne contient `$m` : ne la passez pas en `--execute="…"` sous PowerShell, qui interpréterait la variable (voir les consignes de terminal en tête du document).
- Pour les recettes de validation, utilisez un salon en « Expert : texte libre uniquement » avec 16 manches : on voit tout le catalogue et aucun QCM ne gêne. Un salon ne rejoue pas ses films (mémoire du salon) : créez un nouveau salon pour chaque nouvelle série.
- Les outils de développement (F12) servent aux cas limites : Réseau (requêtes /seat/…/answer, /seat/…/choice et /f/…), Console (« Copier en tant que fetch » ; lisez la réponse dans l’onglet Réseau) et Éléments.
- Pour le seul test d’ambiguïté : compte curateur curator@tripleframes.test, mot de passe `password`, connexion par /login. Enrôlez le second facteur si /admin vous renvoie vers l’écran d’enrôlement.
- Les tests sur vrai téléphone (appui long iOS, clavier virtuel) ne sont pas jouables sur `composer dev` tel quel, qui n’écoute que sur 127.0.0.1 : ils se font à la recette sur appareil réel (étape 127 de l’annexe), ou sur un serveur exposé au réseau local.

### 4.1 Ouverture de la manche et déroulé des paliers

- [ ] **Lire la ligne d’état à l’ouverture de la manche** ⭐
  - Faire : Lancez un salon aux réglages par défaut avec deux joueurs. Pendant le décompte, regardez le cadre, puis attendez l’ouverture de la manche.
  - Attendu : Pendant le décompte, le cadre affiche « La manche commence dans N secondes ». À l’ouverture, en haut : « Manche 1 sur 10 », « 300 points en jeu » (valeur par défaut du palier 1, sans bonus), le chrono en secondes (« 30 s », qui décompte) et une fine barre qui se vide. L’image apparaît. Dessous, le champ (texte indicatif « Titre du film ») a déjà le focus, avec le bouton « Valider » et « 15 tentatives restantes » (15 par défaut à 30 s).
  - Réf. : 60 § 2.5 ; 90 § 7.2, § 7.3 · round-scene.tsx, game-stage.tsx, answer-input.tsx
- [ ] **Voir les paliers s’ouvrir et la valeur baisser** ⭐
  - Faire : Pendant une manche par défaut (3 images, 30 s), suivez la ligne d’état. En parallèle, gardez un œil sur l’attribut alt de l’<img> (F12 > Éléments) et sur l’onglet Réseau filtré sur « f/ ».
  - Attendu : « 300 points en jeu », puis « 200 points en jeu » quand le chrono affiche 20 s, puis « 100 points en jeu » à 10 s : trois paliers égaux de 10 s par défaut. L’alt passe de « Image 1 sur 3 de la manche en cours » à « Image 2 sur 3 de la manche en cours » puis « Image 3 sur 3… », au même instant que la valeur. Une requête GET /f/<jeton>?… part environ 2 s avant chaque changement de palier (préchargement).
  - Réf. : 60 § 6, § 7.1 ; 90 § 7.3 · round-timeline.ts, frame-loader.ts
- [ ] **Comparer le chrono entre deux joueurs**
  - Faire : Placez côte à côte la fenêtre normale et la fenêtre privée pendant une manche.
  - Attendu : Les deux chronos affichent la même seconde, à une seconde près. La valeur du palier change au même moment dans les deux fenêtres : l’horloge affichée est celle du serveur, resynchronisée.
  - Réf. : 60 § 2.4, § 2.6 · use-round-clock.ts
- [ ] **Vérifier la durée des paliers pour d’autres nombres d’images**
  - Faire : Réglez « Images par manche » sur 5 et « Durée d’une manche » sur 25 s, puis lancez. Recommencez avec le preset « Hardcore » (5 images, 45 s), puis avec 4 images et 20 s.
  - Attendu : Avec 5 images et 25 s : 500, 400, 300, 200 puis 100 points en jeu, avec un changement à 20, 15, 10 et 5 s restantes. Avec Hardcore : un palier toutes les 9 s (36, 27, 18 et 9 s restantes). Avec 4 images : 400, 300, 200, 100, un palier toutes les 5 s. La durée est toujours répartie à parts égales.
  - Réf. : 50 § 3 · RoomSettingsBounds::defaultTierDurations, defaultTierPoints
- [ ] **Contrôler le reste de la division sur le dernier palier** ⚠️
  - Faire : Réglez 3 images et « Durée d’une manche » sur 31 s, puis lancez.
  - Attendu : Les paliers durent 10, 10 puis 11 s : on passe à 200 points à 21 s restantes et à 100 points à 11 s restantes.
  - Réf. : RoomSettingsBounds::defaultTierDurations
- [ ] **Laisser la manche aller jusqu’à la durée D** ⭐
  - Faire : Laissez une manche aller au bout sans que personne ne trouve : n’envoyez rien, ou seulement des réponses fausses.
  - Attendu : À 0 s, la valeur du palier disparaît ; dès que le serveur clôt la manche, le chrono disparaît à son tour. Le champ est retiré et remplacé par « Fin de la manche : la réponse arrive. », puis la révélation arrive environ 0,3 s plus tard. Ce n’est jamais le chrono du navigateur qui ferme la saisie.
  - Réf. : 60 § 2.6, § 9.1 · game-stage.tsx, CloseRound
- [ ] **Vérifier les annonces pour lecteur d’écran**
  - Faire : Activez NVDA ou le Narrateur Windows, ou surveillez dans l’inspecteur la région role="status" en bas de la page de jeu. Jouez une manche par défaut en Normal.
  - Attendu : Chaque annonce n’est lue qu’une fois :
    - « Nouvelle image, 2 sur 3. » à 20 s restantes ;
    - « Mi-manche. » à 15 s ;
    - « Nouvelle image, 3 sur 3. » avec « Les propositions sont affichées. » à 10 s ;
    - « Dernier quart de la manche. » à 7,5 s ;
    - « Plus que 3 secondes. » ;
    - « Manche terminée. » à la clôture.
    Deux annonces à moins d’une seconde d’écart sont lues ensemble.
  - Réf. : 90 § 7.4 · use-round-announcements.ts, game-announcer.tsx

### 4.2 Image de jeu : cadre, protection et chargement

- [ ] **Garder un cadre 16:9 fixe**
  - Faire : Pendant une manche, redimensionnez la fenêtre : étroite et haute, puis large et basse.
  - Attendu : Le cadre reste en 16:9 et prend la plus grande taille qui tient dans la zone, centré. Il n’est jamais rogné ni déformé, et aucune barre de défilement n’apparaît. Avant l’arrivée de l’image, le cadre occupe déjà sa place (aplat et indicateur de chargement) : rien ne saute.
  - Réf. : 90 § 7.1, § 7.2 · game-frame.tsx, round-scene.tsx
- [ ] **Vérifier que l’image est servie en local, jamais par son URL signée**
  - Faire : F12 : inspectez l’<img> du cadre, puis ouvrez une requête /f/… dans l’onglet Réseau.
  - Attendu : Le src de l’<img> est une URL blob:…, jamais /f/… ni un chemin de fichier. La réponse /f/ est un 200 image/webp avec les en-têtes Cache-Control: no-store, private et X-Robots-Tag: noindex, nofollow.
  - Réf. : 60 § 7.3 à 7.6 ; CLAUDE.md § 8 · FrameImageResponse, frame-loader.ts
- [ ] **Tester l’anti-copie sur ordinateur**
  - Faire : Faites un clic droit sur l’image, essayez de la glisser vers le bureau, puis de la sélectionner.
  - Attendu : Aucun menu contextuel ne s’ouvre, l’image ne se glisse pas et ne se sélectionne pas.
  - Réf. : 90 § 7.1 · game-frame.tsx
- [ ] **Tester l’appui long sur iOS (geste 11 c)**
  - Faire : Sur un iPhone avec Safari en pleine manche, faites un appui long sur l’image. Refaites-le sur Android avec Chrome. À faire à la recette sur appareil réel, `composer dev` n’étant pas joignable depuis le téléphone (voir les prérequis).
  - Attendu : Aucun menu ne s’ouvre : ni « Enregistrer l’image », ni « Copier », ni aperçu. Aucune sélection n’apparaît.
  - Réf. : REPRISE § 3 geste 11 c ; 90 § 7.1 · game-frame.tsx
- [ ] **Vérifier qu’une URL d’image copiée ne sert pas hors de la partie** ⚠️
  - Faire : Pendant une manche, copiez dans Réseau l’URL complète d’une requête /f/…. (1) Ouvrez-la tout de suite dans un navigateur qui ne joue pas cette partie. (2) Rouvrez-la depuis le navigateur du joueur quelques minutes plus tard, une fois la manche révélée.
  - Attendu : (1) 404 à corps vide, avec Cache-Control: no-store, private et X-Robots-Tag: noindex, nofollow : bien que signée, l’URL n’est servie qu’aux sièges de la partie. Un autre joueur de la même partie, lui, la charge : le jeton est lié à la manche, pas au joueur. (2) 403 : la signature expire 5 s après la fin de la révélation de la manche. Entre la fin de la révélation et ces 5 s, la réponse est un 404.
  - Réf. : 60 § 7.2, § 7.5 · ServeGuard, ServeUrl::expiresAt
- [ ] **Vérifier qu’une image indisponible n’arrête pas le chrono** ⚠️
  - Faire : Avant le début d’une manche : F12 > Réseau > « Bloquer l’URL de requête » avec le motif */f/*. Jouez la manche, puis levez le blocage.
  - Attendu : Le cadre affiche l’aplat et l’indicateur de chargement, puis, après quelques essais (environ 2 s), « Image indisponible. » avec une icône. Le chrono, la valeur du palier et la saisie continuent normalement. Une fois le blocage levé, les images reviennent aux paliers ou à la manche suivants.
  - Réf. : 90 § 7.1 · frame-loader.ts, game-frame.tsx

### 4.3 Texte libre : bonnes réponses (recettes du catalogue de démo)

- [ ] **Envoyer le titre français exact** ⭐
  - Faire : Salon en Expert avec 16 manches. Quand tinker annonce « The Lion King », tapez « Le Roi Lion » puis Entrée (ou « Valider »).
  - Attendu : Le champ disparaît, remplacé par le panneau « Trouvé ! » et les points gagnés. Aucun titre n’est affiché avant la révélation.
  - Réf. : 70 § 4.1, § 6.2 (a) · AnswerMatcher
- [ ] **Envoyer le titre anglais ou le titre original** ⭐
  - Faire : Selon le film en jeu, tapez :
    - « The Lion King » ;
    - « Spirited Away » ou « Sen to Chihiro no Kamikakushi » ;
    - « Tonari no Totoro » ;
    - « Snow White and the Seven Dwarfs ».
    Collez aussi le titre japonais « 千と千尋の神隠し » ou « となりのトトロ ».
  - Attendu : Chaque saisie est acceptée (« Trouvé ! »), y compris le titre original japonais collé tel quel et sa translittération latine.
  - Réf. : 70 § 4.1, § 5.2 · AnswerKeyProjector, AnswerKeyNormalizer
- [ ] **Faire accepter toutes les langues, quelle que soit celle du joueur**
  - Faire : Le joueur B passe en « English » avec l’icône de langue en bas de l’écran de jeu. Quand Finding Nemo est en jeu, B tape « Le Monde de Nemo » et A (en français) tape « Finding Nemo ».
  - Attendu : Les deux réponses sont acceptées : la langue du joueur ne compte dans aucune décision.
  - Réf. : 05 § Acceptation multilingue ; 70 § 4.1
- [ ] **Envoyer les alias curés**
  - Faire : Tapez un alias du film en jeu :
    - « Chihiro » ou « Totoro » ;
    - « Nemo » ;
    - « Origine » (Inception) ;
    - « La Matrice » (The Matrix) ;
    - « Histoire de jouets » (Toy Story) ;
    - « Le Parc jurassique » ;
    - « L’Enfant lumière » (The Shining) ;
    - « La Guerre des étoiles » (A New Hope) ;
    - « Blanche Neige » ou « Snow White ».
  - Attendu : Chaque alias est accepté (« Trouvé ! »).
  - Réf. : 70 § 4.3
- [ ] **Varier la casse, les accents, la ponctuation et les articles**
  - Faire : Selon le film en jeu, tapez :
    - « LE ROI LION !!! » ou « Lion King » ;
    - « Voyage de Chihiro » (sans article) ;
    - « Monde de Némo » ;
    - « l'enfant lumiere » ou « L'ENFANT-LUMIÈRE » (Shining) ;
    - « Matrix » (The Matrix) ;
    - « blanche neige » (Blanche-Neige et les Sept Nains).
  - Attendu : Toutes ces saisies sont acceptées. Casse, accents, apostrophes droites ou courbes, tirets et ponctuation sont ignorés, ainsi qu’un seul article de tête (le, la, les, l’, un, une, des, du, de, the, a, an).
  - Réf. : 70 § 5.1, § 5.4
- [ ] **Mélanger chiffres romains et arabes**
  - Faire : Retour vers le futur en jeu : tapez « Retour vers le futur I ». Star Wars: A New Hope en jeu : tapez « La guerre des etoiles episode 4 ».
  - Attendu : Les deux sont acceptés. « I » en fin de titre vaut 1 : on retombe sur l’alias « Retour vers le futur 1 ». « Épisode IV » de l’alias vaut « episode 4 ».
  - Réf. : 70 § 5.3
- [ ] **Faire passer des fautes de frappe tolérées**
  - Faire : Selon le film en jeu, tapez « Jurassik Park », « Ratatouile », « Pulp Fixion », « Shinning », « Matrx », « voyage de chihirro » ou « My Neighbour Totoro ». Tapez aussi « pulpfiction » et « toystory ».
  - Attendu : Toutes ces saisies sont acceptées. La tolérance dépend de la longueur du titre visé, espaces non comptées : 0 faute jusqu’à 4 lettres, 1 jusqu’à 8, 2 jusqu’à 15, 3 au-delà. Les espaces sont ignorés.
  - Réf. : 70 § 6.3 · AnswerRules::TOLERANCE_STEPS
- [ ] **Vérifier la sévérité sur les titres courts et les inversions** ⚠️
  - Faire : Tapez « Nemmo » (Finding Nemo), « Dunne » (Dune: Part Two) et « Toy Stroy » (Toy Story). Tapez ensuite « Toy Storry ».
  - Attendu : « Nemmo », « Dunne » et « Toy Stroy » sont refusés avec « Ce n’est pas ça. ». « Nemo » et « Dune » font 4 lettres : aucune tolérance. Une inversion de deux lettres compte pour 2 fautes, au-delà de la tolérance de 1 de « toystory ». « Toy Storry » (une lettre en trop) est accepté.
  - Réf. : 70 § 6.3
- [ ] **Vérifier qu’un nombre différent n’est jamais toléré** ⚠️
  - Faire : Tapez, selon le film en jeu :
    - « Le Roi Lion 2 » ou « Le Roi Lion II » ;
    - « Toy Story 2 » ;
    - « Jurassic Park III » ;
    - « Retour vers le futur 2 » ;
    - « Dune 2 » ;
    - « Blanche-Neige et les 7 nains ».
  - Attendu : Toutes ces saisies sont refusées, alors qu’elles sont à une lettre du bon titre : la tolérance ne s’applique jamais quand les nombres diffèrent. « 7 » n’est jamais rapproché de « sept ».
  - Réf. : 70 § 6.3 · AnswerMatcher::decide (d)
- [ ] **Envoyer le titre d’un autre film**
  - Faire : The Lion King en jeu : tapez « Toy Story ». Star Wars: A New Hope en jeu : tapez « L’Empire contre-attaque ».
  - Attendu : « Ce n’est pas ça. », et une tentative est décomptée. Rien n’indique qu’il s’agit du titre d’un autre film.
  - Réf. : 70 § 6.2 (c)

### 4.4 Texte libre : préfixes, sous-titres et ambiguïté

- [ ] **Envoyer un préfixe qu’aucun autre film ne partage**
  - Faire : Dune: Part Two en jeu : tapez « Dune ».
  - Attendu : Accepté : aucun autre film publié ne porte « Dune ».
  - Réf. : 70 § 4.2 (D23 du 23/09)
- [ ] **Envoyer un sous-titre seul**
  - Faire : Tapez :
    - « Deuxième partie » ou « Part Two » (Dune) ;
    - « Un nouvel espoir » ou « A New Hope » ;
    - « The Empire Strikes Back » ou « L’Empire contre-attaque » (Empire).
  - Attendu : Chaque saisie est acceptée pour son propre film.
  - Réf. : 70 § 4.2, § 5.7 (D23 du 23/09)
- [ ] **Envoyer un préfixe de saga partagé** ⭐
  - Faire : Pendant l’un des deux Star Wars, tapez « Star Wars », puis « Star Warz ». Au lobby suivant, ouvrez « Aide ».
  - Attendu : Les deux saisies sont refusées avec « Ce n’est pas ça. », le même message que pour toute autre erreur. « Star Wars » désigne deux films publiés, et une faute sur une forme ambiguë n’est jamais tolérée. Le titre complet « Star Wars : Un nouvel espoir » reste accepté. L’aide le dit : « Quand le site compte plusieurs films d’une même saga, le titre de la saga seul, ou un sous-titre partagé, ne suffit pas. »
  - Réf. : 70 § 4.2, § 6.2 (c) et (d) ; 90 § 7.7 · game-help.tsx
- [ ] **Vérifier qu’un alias ne donne ni préfixe ni sous-titre** ⚠️
  - Faire : Star Wars: A New Hope en jeu : tapez « Episode IV ».
  - Attendu : Refusé. « Épisode IV » n’est que la fin de l’alias « La Guerre des étoiles : Épisode IV », et un alias ne produit jamais de préfixe ni de sous-titre.
  - Réf. : 70 § 4.2 (décision 13)
- [ ] **Mesurer l’ambiguïté sur le catalogue publié, à la réception** ⚠️
  - Faire : 1. En curateur, ouvrez /admin/catalog, puis la fiche de « Star Wars: The Empire Strikes Back ».
    2. Cliquez « Dépublier le film », remplissez « Motif de la dépublication » et confirmez.
    3. Lancez une partie. Quand A New Hope est en jeu, tapez « Star Wars », puis (manche suivante ou autre siège) « Star Warz ».
    4. Revenez sur la fiche et cliquez « Republier le film ».
  - Attendu : Après la dépublication, « Star Wars » est accepté pour A New Hope dès la soumission suivante, même en pleine manche : le préfixe n’appartient plus qu’à un film publié. « Star Warz » passe aussi, la faute redevenant tolérée. Le dialogue de republication affiche l’avertissement « Formes rendues ambiguës », avec la ligne « star wars ». Après republication, « Star Wars » est de nouveau refusé, et un « Trouvé ! » déjà obtenu n’est jamais repris.
  - Réf. : 70 § 6.1, § 6.5 ; 20 § 8 · AnswerMatcher (lecture O), UnpublishMovie
- [ ] **Alias curé qui reprend un préfixe partagé**
  - Faire : En curateur, sur la fiche de « Star Wars: A New Hope », onglet « Titres et alias », cliquer « Ajouter un alias », choisir « Français », saisir `Star Wars`, cliquer « Vérifier », puis « Ajouter l’alias ». Dans un salon Expert de 16 manches, taper « Star Wars » pendant A New Hope, puis pendant The Empire Strikes Back. Retirer l’alias à la fin (« Retirer », puis « Retirer l’alias »).
  - Attendu : Pendant A New Hope : « Trouvé ! ». Un alias curé qui désigne le film de la manche est toujours accepté, même quand un autre film publié porte la même forme. Pendant L’Empire contre-attaque : « Ce n’est pas ça. », car le préfixe y reste ambigu. Sur la fiche d’A New Hope, « Formes acceptées » montre une ligne « star wars » de nature Alias, marquée « Toujours acceptée ».
  - Réf. : 70 § 4.2, § 6.2 · CLAUDE § 2 (titre complet ou alias curé toujours accepté)

### 4.5 Texte libre : refus, tentatives et garde-fous

- [ ] **Envoyer une mauvaise réponse et suivre le compteur** ⭐
  - Faire : Tapez « zzz » puis Entrée. Recommencez plusieurs fois, à plus d’une seconde d’intervalle.
  - Attendu : Sous le champ : « Ce n’est pas ça. » et « 14 tentatives restantes » (15 par défaut à 30 s). Le texte envoyé reste sélectionné : la frappe suivante le remplace. Le compteur perd un à chaque refus, avec le singulier à « 1 tentative restante ».
  - Réf. : 70 § 7.5, § 16 · answer-input.tsx
- [ ] **Vérifier que le refus est neutre, sans « presque »**
  - Faire : Pendant Toy Story, comparez le retour de « Toy Stroy » (presque juste) et de « zzz ». Dans Réseau, ouvrez la réponse JSON de /seat/…/answer.
  - Attendu : Le même texte « Ce n’est pas ça. » dans les deux cas. Le corps ne contient que result: "rejected", inputState et attemptsLeft : ni distance, ni forme normalisée, ni titre.
  - Réf. : 70 § 7.7, § 14 · SubmissionVerdict
- [ ] **Vérifier qu’une erreur ne coûte aucun point**
  - Faire : Au palier 1, envoyez 4 ou 5 réponses fausses, puis la bonne avant que le chrono n’affiche 20 s.
  - Attendu : Les points sont ceux du palier 1 (300 plus le bonus, par défaut). Le bonus ne dépend que de l’instant de réception, jamais du nombre d’erreurs.
  - Réf. : 80 § 2.6
- [ ] **Épuiser ses tentatives en Expert** ⭐
  - Faire : Salon en « Expert : texte libre uniquement », 2 images par manche, « Durée d’une manche » à 10 s, soit 5 tentatives par défaut. Envoyez 5 réponses fausses en appuyant sur Entrée une fois par seconde (le texte reste sélectionné).
  - Attendu : Après la 5e : « Vous avez utilisé toutes vos tentatives pour cette manche. », le champ est grisé et le compteur disparaît. La manche continue pour les autres joueurs.
  - Réf. : 70 § 3.2, § 8 · SubmitTextAnswer
- [ ] **Tester la limite d’une tentative par seconde**
  - Faire : Envoyez une réponse fausse, puis appuyez de nouveau sur Entrée dès que « Ce n’est pas ça. » s’affiche.
  - Attendu : « Une tentative à la fois. » s’affiche en rouge avec une icône d’alerte (réponse 429 dans Réseau). Ce refus n’est pas compté : le nombre de tentatives restantes ne bouge pas. La limite (1 par seconde par défaut) vaut pour ce siège seulement : l’autre joueur n’est pas freiné.
  - Réf. : 70 § 8 · limiteur answer (FortifyServiceProvider)
- [ ] **Envoyer une saisie vide ou sans lettre ni chiffre** ⚠️
  - Faire : Appuyez sur Entrée avec le champ vide, puis envoyez « !!! ».
  - Attendu : Champ vide : rien ne part. « !!! » : « Tapez au moins une lettre ou un chiffre. » en rouge sous le champ, qui est marqué invalide (422). Aucune tentative n’est décomptée.
  - Réf. : 70 § 7.4 (S5) · SubmitTextAnswer
- [ ] **Tester la longueur maximale de la réponse** ⚠️
  - Faire : Collez un texte de 150 caractères dans le champ. Ensuite, avec F12, retirez l’attribut maxlength de l’<input> et envoyez au moins 101 caractères.
  - Attendu : Le collage est tronqué à 100 caractères, la longueur maximale par défaut. Sans l’attribut, le serveur répond « Le champ réponse ne doit pas contenir plus de 100 caractères. » (422), affiché en rouge sous le champ, et aucune tentative n’est décomptée.
  - Réf. : 70 § 8 · AnswerStoreRequest
- [ ] **Envoyer une réponse après la clôture de la manche** ⚠️
  - Faire : Dans Réseau, faites un clic droit sur une requête /seat/…/answer, puis « Copier en tant que fetch ». Une fois la manche finie (révélation), collez-la dans la Console, exécutez-la et lisez la réponse dans Réseau.
  - Attendu : Réponse 409 avec result: "closed", inputState et message: "La saisie est close pour cette manche.". Rien n’est compté ni verrouillé.
  - Réf. : 70 § 7.3 · AcceptanceWindow, AnswerController
- [ ] **Envoyer une réponse au siège d’un autre joueur** ⚠️
  - Faire : Relevez dans Réseau de A l’identifiant de son siège dans l’URL /seat/<idA>/answer. Dans la fenêtre de B, copiez en tant que fetch une requête /seat/<idB>/answer de B, remplacez <idB> par <idA> dans l’URL et exécutez-la dans la Console de B pendant une manche.
  - Attendu : Réponse 403 : l’identifiant de siège ne donne aucun droit, seul le cookie du joueur qui tient le siège en donne. Chez A, rien ne change : ni message, ni tentative décomptée.
  - Réf. : 70 § 7.1 · EnsureActiveSeat
- [ ] **Vérifier en base qu’une erreur est comptée, jamais stockée** ⚠️
  - Faire : Pendant une manche où B ne saisit rien, A envoie « zzz », « yyy » et « xxx » (plus d’une seconde entre chaque envoi), puis la bonne réponse. Dans `php artisan tinker` interactif : `$rp = App\Models\RoundPlayer::query()->latest('updated_at')->first(); [$rp->wrong_attempts, $rp->input_state->value, App\Models\Guess::where('round_id', $rp->round_id)->where('player_id', $rp->player_id)->count()]`.
  - Attendu : Tinker affiche `[3, "locked", 1]`. Les trois erreurs ne sont qu’un compteur. La seule ligne `guess` est la bonne réponse, qui ne garde que sa forme normalisée. Le texte « zzz » n’est écrit nulle part.
  - Réf. : CLAUDE § 2 (tentatives fausses comptées, jamais stockées) · RoundPlayer::wrong_attempts · Guess

### 4.6 QCM selon la difficulté de saisie

- [ ] **Voir le QCM dès la première image en Facile** ⭐
  - Faire : Choisissez « Facile : propositions dès la première image » (ou le preset « Rapide »), puis lancez.
  - Attendu : Aucun champ texte. Dès l’ouverture, quatre boutons en grille 2×2, avec le focus sur le premier ; « Chargement… » s’affiche tant qu’ils ne sont pas arrivés. Les quatre titres sont distincts, en français pour un joueur FR (par exemple « Le Roi Lion », « Mon voisin Totoro »). Un seul est juste, et rien ne le distingue visuellement.
  - Réf. : 70 § 2, § 10 · choice-grid.tsx, round-input.tsx
- [ ] **Cliquer la bonne proposition en Facile** ⭐
  - Faire : Au palier 1, cliquez la bonne proposition (vérifiée avec tinker).
  - Attendu : « Trouvé ! » avec les points du palier 1 : par défaut (3 images), 300 plus le bonus, 450 au plus. Avec le preset Rapide (2 images) : 200 plus le bonus, soit 300 au plus.
  - Réf. : 80 § 4.3 · SubmitChoice
- [ ] **Constater l’essai unique après un mauvais clic** ⭐
  - Faire : En Facile, cliquez une proposition fausse, puis essayez d’en cliquer une autre.
  - Attendu : « Mauvaise proposition : la saisie est close pour cette manche. » sous la grille. Les quatre boutons sont grisés et ne réagissent plus.
  - Réf. : 70 § 2 · SubmitChoice
- [ ] **Passer du texte libre aux propositions en Normal** ⭐
  - Faire : Au lobby, lisez sous l’option « Normal : texte libre, puis propositions à la dernière image » la ligne d’information. Lancez avec les réglages par défaut (« Normal », 3 images, 30 s), tapez dans le champ, puis attendez que le chrono affiche 10 s.
  - Attendu : Au lobby : « Les propositions apparaissent à 20 s, soit 67 % de la manche. » (20 s écoulées, soit 10 s au chrono qui décompte). En jeu, le champ est seul jusqu’au dernier palier. À 10 s restantes, la grille de quatre propositions apparaît sous le champ, sans voler le focus : la frappe en cours continue. Le texte libre reste utilisable en plus du clic.
  - Réf. : 70 § 2 ; 50 § 4.5 ; 90 § 7.5 · room-settings.ts choicesAtPercent
- [ ] **Vérifier qu’un clic en Normal est crédité au dernier palier**
  - Faire : En Normal par défaut, cliquez la bonne proposition dès qu’elle apparaît, puis ouvrez la réponse JSON dans Réseau.
  - Attendu : « Trouvé ! » avec 150 points au plus (100 plus un bonus de 50 % maximum). La réponse porte tierIndex: 3, même si le clic arrive dans les 0,3 s qui suivent l’ouverture du palier (plancher du QCM).
  - Réf. : 80 § 4.3, § 4.5 · ScoringRules::floorTierIndex
- [ ] **Vérifier qu’un mauvais clic ferme aussi le texte en Normal**
  - Faire : En Normal, au dernier palier, cliquez une proposition fausse, puis essayez de taper.
  - Attendu : Sous le champ : « Mauvaise proposition : la saisie est close pour cette manche. ». Le champ et la grille restent inactifs jusqu’à la fin de la manche.
  - Réf. : 70 § 2, § 3.2
- [ ] **Épuiser le texte en Normal et attendre les propositions (D20)** ⭐
  - Faire : Salon en Normal, 5 images, « Durée d’une manche » à 25 s : 13 tentatives par défaut, dernière image à 5 s restantes. Épuisez les 13 tentatives avant 5 s (Entrée une fois par seconde, dès l’ouverture), puis attendez.
  - Attendu : Après la 13e : « Plus de tentatives en texte libre : les propositions arrivent avec la dernière image. », et le champ est grisé. À 5 s, la grille apparaît et un clic reste possible : juste, il donne « Trouvé ! » avec 100 points plus le bonus (125 au plus) ; faux, il affiche « Mauvaise proposition : la saisie est close pour cette manche. ».
  - Réf. : 70 § 2, § 3.2 (D20 du 23/09) · SubmitTextAnswer::exhaustedState
- [ ] **Vérifier l’absence de propositions en Expert** ⭐
  - Faire : Salon en « Expert : texte libre uniquement ». Jouez une manche jusqu’à la dernière image.
  - Attendu : Aucune grille à aucun palier, seulement le champ texte.
  - Réf. : 70 § 2
- [ ] **Comparer la langue et l’ordre des propositions entre deux joueurs**
  - Faire : Avant le lancement, B choisit « English » ; A reste en français. Salon en Facile. Comparez les deux grilles.
  - Attendu : A voit les titres français, B les titres anglais (« The Lion King », « Spirited Away »…). L’ordre des quatre boutons est propre à chaque siège, donc en général différent d’un joueur à l’autre. Dans F12, le conteneur des boutons porte lang="fr" ou lang="en".
  - Réf. : 70 § 10.5, § 10.8 · ChoicesPresenter
- [ ] **Recharger ou changer de langue une fois les propositions affichées** ⚠️
  - Faire : Propositions à l’écran, passez de Français à English avec l’icône de langue, puis rechargez la page (F5).
  - Attendu : Les quatre propositions gardent exactement le même texte, la même langue et le même ordre, parce que la langue de composition est figée. Le reste de l’interface passe en anglais. À la manche suivante, les propositions arrivent en anglais.
  - Réf. : CLAUDE.md règle 3 ; 70 § 10.8
- [ ] **Envoyer une proposition inventée** ⚠️
  - Faire : 1. Copiez en tant que fetch la requête /seat/…/choice d’une manche précédente.
    2. Dans le corps JSON, mettez round au numéro de la manche en cours (sa position de tirage, égale au numéro tant qu’aucune manche n’a été remplacée) et choice à « Titre inventé ».
    3. Exécutez-la dans la Console pendant que la grille courante est ouverte et pas encore cliquée.
  - Attendu : 422, dont errors.choice contient « Cette proposition n’existe pas. ». Rien n’est écrit : la grille reste cliquable.
  - Réf. : 70 § 7.6 (S5') · SubmitChoice
- [ ] **Arriver au bout du catalogue avec le QCM** ⚠️
  - Faire : Dans un salon neuf : Facile, 16 manches (tout le catalogue de démo), 2 images, 10 s, révélation au minimum ; l’hôte clique « Manche suivante » à chaque révélation. Notez les films des manches déjà jouées. Refaites l’essai en Normal si besoin.
  - Attendu : Aucune proposition n’est un film d’une manche déjà jouée dans cette partie. À partir de la manche 14 sur 16, il reste moins de trois autres films non joués : le QCM ne peut plus être composé. En Facile, ces manches affichent « Manche annulée à la suite d’un incident : elle ne compte pas. ». En Normal, la manche continue en texte seul et aucune proposition n’arrive à la dernière image.
  - Réf. : 70 § 10.2, § 10.7 · DecoyPicker, PoolScope::forDecoys, OpenTier
- [ ] **Utiliser le QCM au clavier**
  - Faire : En Facile, à l’ouverture de la manche, utilisez Tab et Maj+Tab, puis Entrée ou Espace.
  - Attendu : Le focus est déjà sur la première proposition. Tab parcourt les quatre dans l’ordre visuel, et Entrée ou Espace clique. Les boutons font au moins 44 px.
  - Réf. : 90 § 7.5 · choice-grid.tsx
- [ ] **Propositions en titres originaux (mode dégradé)** ⚠️
  - Faire : En curateur, sur la fiche de « The Lion King », onglet « Titres et alias », ligne Français : cliquer « Retirer », puis « Retirer le titre ». Créer un salon neuf en Facile, 16 manches, 2 images, 10 s ; A reste en français, B passe en English. Quand tinker annonce « The Lion King » (avant la manche 14), comparer les deux grilles. Rétablir ensuite le titre par « Saisir » (« Le Roi Lion »).
  - Attendu : Pour cette manche seulement, les quatre propositions sont des titres originaux en alphabet latin : « The Lion King » et trois autres (par exemple « Toy Story », « The Matrix », « Inception »). A et B voient les mêmes chaînes, chacun dans l’ordre propre à son siège. Les autres manches gardent les titres traduits (« Le Monde de Nemo » chez A, « Finding Nemo » chez B). Aucune manche n’est annulée.
  - Réf. : 70 § 10.5 · DecoyPicker (rangs R3 et R4) · round.choices_use_original_title

### 4.7 Joueur verrouillé et fil « a trouvé »

- [ ] **Lire le panneau du joueur qui a trouvé** ⭐
  - Faire : Le joueur A trouve au palier 1.
  - Attendu : À la place du champ : « Trouvé ! » (icône et couleur de succès), « Gagné : 424 points » par exemple, « dont 124 de bonus de rapidité », puis « Rang d’arrivée : 1er ». Ni le titre ni la réponse tapée ne s’affichent. L’image, la valeur du palier et le chrono continuent.
  - Réf. : 60 § 8.4 ; 80 § 7.3 · round-input.tsx (LockedPanel)
- [ ] **Vérifier ce que les autres voient** ⭐
  - Faire : Regardez l’écran du joueur B juste après que A a trouvé.
  - Attendu : Dans la bande des joueurs, le siège de A passe en tête avec le badge « A trouvé 1er ». Aucun titre, aucune réponse et aucun point de A ne sont visibles. La saisie de B reste ouverte.
  - Réf. : 60 § 8.4, § 11.5 · round-players.tsx
- [ ] **Vérifier que la première bonne réponse ne coupe pas la manche** ⭐
  - Faire : A trouve au palier 1, B ne répond pas.
  - Attendu : La manche continue jusqu’au bout du chrono pour tous. Les paliers 2 et 3 s’ouvrent, et A voit toujours les images, la valeur et le chrono, sans pouvoir répondre.
  - Réf. : CLAUDE.md § 2 ; 60 § 9.2
- [ ] **Faire trouver un second joueur**
  - Faire : B trouve au palier 2, après A.
  - Attendu : B voit « Rang d’arrivée : 2e » et les points du palier 2 (200 plus le bonus, 300 au plus) : le rang n’ajoute aucun point. La bande affiche « A trouvé 1er » puis « A trouvé 2e ».
  - Réf. : 80 § 4.2 · LockGuess
- [ ] **Vérifier qu’un échec d’un autre joueur reste invisible** ⚠️
  - Faire : B clique une proposition fausse (en Facile) ou épuise ses tentatives (en Expert).
  - Attendu : Rien ne change sur l’écran de A : ni badge ni message au sujet de B.
  - Réf. : 70 § 3.4, § 9.5 · InputClosed (jamais diffusé)
- [ ] **Recharger la page après avoir trouvé** ⚠️
  - Faire : A a trouvé ; il appuie sur F5 pendant la manche.
  - Attendu : A retrouve le panneau « Trouvé ! » avec les mêmes points et le même rang, sans champ de saisie. La manche reprend où elle en est.
  - Réf. : 60 § 12 ; 70 § 16 · SeatViewPresenter

### 4.8 Score : valeur du palier et bonus de rapidité

- [ ] **Lire le bonus maximal dans l’Aide selon le nombre d’images**
  - Faire : Au lobby, ouvrez le bouton « Aide » (feuille « Réponses et points ») en réglant « Images par manche » sur 2, 3, 4 puis 5.
  - Attendu : « Bonus de rapidité : jusqu’à 50 % de la valeur de l’image… » pour 2 et 3 images, 33 % pour 4, 25 % pour 5. Le bouton « Aide » n’apparaît jamais pendant une manche en salon. En solo, au contraire, il reste accessible pendant le décompte et la révélation (§ 6.2).
  - Réf. : 80 § 3.1 (D22 du 23/09) ; 90 § 7.7 · game-help.tsx
- [ ] **Répondre vite au palier 1** ⭐
  - Faire : Réglages par défaut. Envoyez la bonne réponse environ 2 s après l’apparition de l’image, quand le chrono affiche 28 s.
  - Attendu : « Gagné : » environ 424 points (300 plus un bonus d’environ 124). Toute bonne réponse au palier 1 rapporte entre 300 et 450. Le calcul : bonus = 50 % × 300 × part restante du palier, arrondi à l’entier inférieur, sur l’instant de réception moins 0,3 s.
  - Réf. : 80 § 4.2, § 4.5 · ScoreCalculator
- [ ] **Répondre tard dans le palier 1, puis au palier 3** ⭐
  - Faire : Envoyez une bonne réponse vers 21 s restantes (fin du palier 1). Dans une autre manche, répondez vers 8 s restantes (palier 3).
  - Attendu : Environ 319 points (300 plus un faible bonus) dans le premier cas, environ 141 points au palier 3 (100 + 41). Au palier 2, on obtient entre 200 et 300. Le bonus décroît jusqu’à 0 à la fin de chaque palier, et la ligne « dont … de bonus de rapidité » disparaît quand il vaut 0.
  - Réf. : 80 § 4.5
- [ ] **Lire le palier crédité dans la réponse du serveur**
  - Faire : Après une bonne réponse, ouvrez dans Réseau la réponse de /seat/…/answer (ou /choice).
  - Attendu : Le corps contient result: "accepted", inputState: "locked", lockRank, tierIndex, pointsTier, pointsBonus et pointsTotal. tierIndex est le palier ouvert à la réception moins 0,3 s, et pointsTier la valeur annoncée par « … points en jeu » pour ce palier.
  - Réf. : 70 § 7.7 ; 80 § 4 · SubmissionVerdict, TierScore
- [ ] **Vérifier B_max selon le nombre d’images**
  - Faire : Envoyez une bonne réponse rapide avec le preset « Rapide » (2 images, 15 s, Facile), puis avec 4 images et 20 s, puis avec « Hardcore » (5 images, 45 s, Expert).
  - Attendu : Rapide : entre 200 et 300 au palier 1, environ 275 à 2 s. 4 images : entre 400 et 532 (33 %). Hardcore : entre 500 et 625 (25 %), environ 600 à 2 s.
  - Réf. : 80 § 3.1 · PlatformLimits::speedBonusMaxPercent
- [ ] **Vérifier qu’attendre ne rapporte jamais plus** ⚠️
  - Faire : Envoyez une bonne réponse juste avant, puis (dans une autre manche) juste après le passage de « 300 points en jeu » à « 200 points en jeu ».
  - Attendu : Dans les deux cas, environ 300 points. Juste avant : 300 plus un bonus proche de 0. Juste après : soit encore le palier 1 (une réponse reçue dans les 0,3 s qui suivent le changement vaut le palier précédent), soit 200 plus un bonus proche de 100. Au barème par défaut, attendre l’image suivante ne rapporte jamais plus, et le crédit ne descend pas sous la valeur affichée à l’envoi, à la latence réseau près.
  - Réf. : 80 § 3.3, § 4.5 ; 90 § 7.3 (D29 du 23/09)
- [ ] **Retrouver ces points au classement de la révélation**
  - Faire : À la révélation qui suit une bonne réponse, comparez avec le panneau « Trouvé ! ».
  - Attendu : Le classement ajoute exactement les points de « Gagné : … », par exemple « +424 sur cette manche ».
  - Réf. : 80 § 7 · standings-table.tsx

### 4.9 Fin anticipée et joueur seul connecté

- [ ] **Clore la manche quand tout le monde a trouvé** ⭐
  - Faire : A et B trouvent tous les deux au palier 1.
  - Attendu : Dès la deuxième bonne réponse, le chrono et la valeur disparaissent ; les panneaux « Trouvé ! » restent. La révélation arrive presque aussitôt (environ 0,3 s), sans attendre la fin du chrono.
  - Réf. : 60 § 9.2 · SeatInputClosed, CloseRound
- [ ] **Clore la manche quand l’un trouve et l’autre épuise ses tentatives**
  - Faire : Salon en Expert, 2 images, 10 s. A trouve ; B envoie ses 5 réponses fausses.
  - Attendu : La manche se clôt à la 5e réponse fausse de B.
  - Réf. : 60 § 9.2 ; 70 § 3.3
- [ ] **Clore la manche quand l’un trouve et l’autre clique faux**
  - Faire : Salon en Facile. A trouve ; B clique une proposition fausse.
  - Attendu : La manche se clôt immédiatement.
  - Réf. : 60 § 9.2 ; 70 § 3.2
- [ ] **Vérifier qu’un texte épuisé ne clôt pas la manche** ⚠️
  - Faire : Salon en Normal, 5 images, 25 s. A trouve ; B épuise ses 13 tentatives avant la dernière image.
  - Attendu : La manche ne se clôt pas : elle attend la dernière image. Elle se clôt dès que B clique, juste ou faux, sinon à 0 s.
  - Réf. : 70 § 3.3 (D20 du 23/09)
- [ ] **Rester seul connecté** ⭐
  - Faire : Pendant une manche, B ouvre « Joueurs », clique « Quitter le salon » et confirme dans la boîte « Quitter le salon ».
  - Attendu : Chez A, au-dessus de la bande des joueurs : « Vous êtes le seul joueur connecté. ». Le siège de B reste dans la bande, grisé, avec l’icône de départ. La partie reste multijoueur : aucun bouton « Voir la réponse » ni « Passer la manche ».
  - Réf. : 60 § 9.3 · round-players.tsx
- [ ] **Trouver en étant seul connecté**
  - Faire : Toujours seul, A trouve.
  - Attendu : La manche se clôt aussitôt : A était le seul participant connecté.
  - Réf. : 60 § 9.3 ; CLAUDE.md règle 7

### 4.10 Écran de manche : portrait, bureau, clavier, thème et langue

- [ ] **Jouer une manche en portrait à 375 px** ⭐
  - Faire : F12 > mode appareil : 375 × 667 (iPhone SE), puis 360 × 640. Jouez une manche en Normal, puis une en Facile.
  - Attendu : Aucun défilement de page pendant la manche. De haut en bas :
    - la ligne d’état (manche, points en jeu, chrono, barre) ;
    - l’image 16:9 en pleine largeur ;
    - le champ et « Valider », ou la grille 2×2 ;
    - la bande des joueurs, qui défile horizontalement, avec le bouton « Joueurs » ;
    - la ligne basse (langue, pied de page).
    Écart connu à signaler (E125-3) : clavier ouvert, en Normal avec le QCM, l’image peut descendre sous 40 % de la hauteur.
  - Réf. : 90 § 7.2 · round-scene.tsx
- [ ] **Ouvrir la feuille « Joueurs » pendant la manche**
  - Faire : Touchez « Joueurs », puis fermez avec « Fermer ». Rouvrez-la et fermez avec Échap.
  - Attendu : La feuille « Joueurs » affiche « Les sièges du salon. La partie continue pendant ce temps. », la liste des sièges et « Quitter le salon ». Le chrono continue derrière, et l’écran de manche revient intact à la fermeture.
  - Réf. : 90 § 7.5 · round-players.tsx
- [ ] **Élargir l’écran sur bureau**
  - Faire : Élargissez la fenêtre au-delà de 1024 px pendant une manche.
  - Attendu : La bande des joueurs devient une colonne à droite ; rien d’autre ne change.
  - Réf. : 90 § 7.2 (règle 10)
- [ ] **Jouer au clavier seul**
  - Faire : À l’ouverture d’une manche en Normal ou Expert, tapez sans cliquer et validez avec Entrée. Laissez passer un changement de palier. Trouvez la bonne réponse.
  - Attendu : Le focus est dans le champ dès l’ouverture, et Entrée envoie. Un changement de palier ne déplace jamais le focus. Après le verrouillage, le focus passe au titre « Trouvé ! ».
  - Réf. : 90 § 7.5 · answer-input.tsx, round-input.tsx
- [ ] **Enchaîner les essais sur un vrai téléphone**
  - Faire : À la recette sur appareil réel, envoyez deux réponses fausses de suite avec la touche d’envoi du clavier, puis avec « Valider ».
  - Attendu : Le clavier virtuel reste ouvert, et sa touche d’action est « Envoyer ». Il n’y a ni correction automatique ni majuscule automatique dans le champ.
  - Réf. : 70 § 16 ; 90 § 7.2 · answer-input.tsx
- [ ] **Vérifier que le thème sombre est forcé**
  - Faire : Passez le système (ou le site public) en apparence claire, puis ouvrez une partie.
  - Attendu : L’écran de manche reste sombre, quelle que soit l’apparence choisie.
  - Réf. : 90 § 2.2 · layouts/game/game-layout.tsx
- [ ] **Changer de langue en pleine manche**
  - Faire : Pendant une manche, avec un texte en cours dans le champ, passez en « English » avec l’icône de langue en bas à gauche. Revenez ensuite en « Français ».
  - Attendu : Sans rechargement, l’interface affiche « Round 1 of 10 », « 300 points at stake », « Film title », « Submit », et « That’s not it. » après une erreur. Le chrono ne repart pas de zéro, le texte tapé reste dans le champ et la manche continue. L’annonce est lue dans la nouvelle langue : « Language changed to English. », puis « Langue changée : Français. » au retour.
  - Réf. : 05 ; 90 § 8 · language-switcher.tsx

### 4.11 Hors périmètre (non livré, ne pas essayer)

- Onglet Avancé (J2) : barème par palier, bonus de rapidité désactivable, durées de palier inégales, tentatives par seconde ou par manche, longueur maximale. Au J1, ces valeurs restent à leur défaut, le bonus est toujours actif, et le serveur refuse `advanced: true` (onglet non livré).
- Mode sans score et palier à 0 points : ils demandent un barème éditable, prévu au J2.
- Quasi-justes (near_miss) : alimentés seulement au J2. Aucun message du type « presque » n’existe, et c’est voulu.
- Sélecteur de thèmes : masqué au J1. On ne peut pas restreindre une partie à une saga ou à un film pour viser une recette ; passez par la commande tinker.
- QCM en titres originaux (mode dégradé) : livré, mais impossible à provoquer avec le catalogue de démo, dont tous les films ont un titre FR et EN.
- Signaler une image depuis le jeu : n’existe pas, car une image de jeu est un contenu admin. Seule existe la page publique « signaler un contenu ».
- Tests sur téléphone réel avec `composer dev` : le serveur n’écoute que sur 127.0.0.1 ; l’appui long iOS et le clavier virtuel se jouent à la recette sur appareil réel (étape 127).

## 5. Partie multijoueur : lancement, révélation, podium, présence et robustesse

Cette section suit une partie en salon, du clic sur « Lancer la partie » jusqu'au « Rejouer ». Elle couvre le décompte, l'enchaînement des manches, la révélation, « Manche suivante », le classement, le podium et ses faits marquants, puis la non-répétition. S'y ajoutent les gestes de l'hôte en cours de partie, les arrivées tardives, la présence et la pause, la resynchronisation, le changement de langue, l'affichage (portrait, desktop, sombre, clavier) et ce que l'onglet Réseau doit montrer ou taire. La validation fine des réponses est traitée dans une autre section.

**Prérequis**

- Mise en route du § 0 faite : `.env` à niveau pour le temps réel (Reverb), base seedée, `composer dev` lancé.
- Rafraîchir la base : lancer `php artisan backup:snapshot` (code 0 exigé, règle 12 ; `mysqldump` doit être dans le PATH), puis `php artisan migrate:fresh --seed`. Attention : `migrate:fresh` efface les trois films importés de TMDB dans la base de dev (Fight Club, Parasite, Le Labyrinthe de Pan). On obtient le catalogue de démonstration : 16 films, tous jouables de 2 à 5 images par manche.
- Lancer `composer dev`. Il démarre le serveur sur http://127.0.0.1:8000, l'écouteur des files `game` puis `default`, Reverb et Vite. Toujours ouvrir le site par http://127.0.0.1:8000.
- Prévoir une identité invitée par contexte de navigation, car le siège vit dans le cookie `player_token`. Par exemple : A = Chrome normal (l'hôte), B = une fenêtre de navigation privée de Chrome, C = Edge ou Firefox. Deux onglets d'un même contexte tiennent le MÊME siège : c'est le cas « second onglet ».
- Salon de départ : A ouvre `/r/new`, saisit un pseudo, choisit un avatar et clique « Créer le salon ». B ouvre le lien obtenu par « Copier le lien » (ou `/r/<code>/join`), saisit un pseudo et clique « Entrer ».
- Les images de démonstration sont des aplats gris identiques : impossible de reconnaître le film. Pour trouver à coup sûr, lire le titre de la manche en cours dans un terminal : `php artisan tinker --execute="echo App\Models\Round::where('status', 'running')->latest('id')->first()?->movie?->titles->pluck('title')->implode(' / ');"`. Autre solution : jouer en difficulté Facile (une chance sur quatre).
- Pour des cycles courts, régler « Nombre de manches » à 3 (le minimum) avant de lancer. Les valeurs citées plus bas sont les valeurs par défaut des réglages : 10 manches, 3 images, 30 s par manche, 8 s de révélation, 300/200/100 points. Le décompte de lancement (5 s), le passage à « déconnecté » (environ 25 s sans signal) et la clôture d'une pause (15 min) sont des constantes du moteur.
- Garder les DevTools ouverts : onglet Réseau (filtres « WS » et « Fetch/XHR ») et mode appareil à 375 × 667 pour le portrait.

### 5.1 Lancement de la partie

- [ ] **Lancer une partie à deux joueurs** ⭐
  - Faire : A (hôte) et B sont sur `/r/<code>`, tous deux connectés. A clique « Lancer la partie ».
  - Attendu : Le bouton affiche « Lancement… » avec un indicateur. Sur les deux écrans, sans changement d'URL ni rechargement complet, le lobby cède la place à l'écran de partie. « Chargement… » peut passer un instant. Le cadre d'image affiche ensuite « La manche commence dans 5 secondes », qui décompte, sous « Manche 1 sur 10 » (par défaut). À zéro, l'image et le chrono apparaissent au même instant chez A et chez B.
  - Réf. : 50 § 12 · 60 § 5.2 · LaunchController · game/lobby.tsx · game-stage.tsx
- [ ] **Bouton « Lancer la partie » grisé, avec son motif**
  - Faire : Au lobby, fermer l'onglet de B et regarder A pendant environ 25 s, puis rouvrir le lien chez B.
  - Attendu : Tant qu'un seul siège est connecté, « Lancer la partie » est grisé, avec dessous « Il faut au moins 2 joueurs connectés. ». Dès que B revient, le motif disparaît et le bouton redevient actif. B ne voit jamais ce bouton : il lit « En attente du lancement par l’hôte. ».
  - Réf. : 50 § 8.1, § 12 · lobby.tsx · RoomSettingsBounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH
- [ ] **Réglages figés du lancement au « Rejouer »**
  - Faire : Après le lancement, parcourir les écrans de A et de B, feuille « Joueurs » comprise.
  - Attendu : Personne ne voit plus « Réglages », « Presets », le compteur de films, « Lancer la partie » ni le bouton « Aide ». Aucun réglage n'est modifiable pendant la partie. Les réglages ne réapparaissent qu'après « Rejouer », avec les mêmes valeurs.
  - Réf. : 00 § Réglages figés · 50 § 12.7 · lobby.tsx
- [ ] **Tirage figé au lancement**
  - Faire : Pendant la manche 2, lire le titre avec la commande tinker des prérequis. Recharger A et B (F5), puis relire le titre. Jouer la partie jusqu'au bout.
  - Attendu : Le titre ne change pas après le rechargement, et la révélation montre bien ce film. Au podium, le « Récapitulatif des films » ne contient aucun film en double.
  - Réf. : 60 § 5.1 · 30 § 5 · MaterializeDraw
- [ ] **Double clic sur « Lancer la partie »** ⚠️
  - Faire : Au lobby, double-cliquer très vite sur « Lancer la partie ».
  - Attendu : Une seule partie démarre (un seul décompte, « Manche 1 sur 10 ») et aucun message d'erreur n'apparaît.
  - Réf. : 50 § 12.5 · LaunchController (not_in_lobby idempotent)

### 5.2 Enchaînement d'une manche

- [ ] **Image, valeur et chrono changent ensemble à chaque palier** ⭐
  - Faire : Regarder la manche 1 aux réglages par défaut (3 images, 30 s), avec A et B côte à côte.
  - Attendu : La ligne d'état affiche « Manche 1 sur 10 », « 300 points en jeu » et les secondes restantes (« 30 s »), au-dessus d'une barre qui se vide. Quand le chrono passe à 20 s, l'image change et la valeur passe à « 200 points en jeu » au même instant. À 10 s, elle passe à « 100 points en jeu ». Les deux navigateurs affichent la même seconde, à une seconde près. Un changement d'image ne déplace pas le focus.
  - Réf. : 60 § 2.5 · 90 § 7.3 (D29) · round-scene.tsx · store.ts (visibleTierValue)
- [ ] **Barème par défaut à 5 images**
  - Faire : Au lobby, passer « Images par manche » à 5 (la durée de 30 s par défaut suffit), puis lancer.
  - Attendu : La manche affiche successivement « 500 points en jeu », puis 400, 300, 200 et 100, avec 5 images différentes. Si la manche va à son terme, la révélation montre les 5 images.
  - Réf. : 00 § Barème · 80 § 2 · D34 · round-scene.tsx
- [ ] **Le premier qui trouve ne coupe pas la manche** ⭐
  - Faire : B saisit le bon titre (lu par tinker) pendant la première image et clique « Valider ». A ne répond pas.
  - Attendu : Chez B, le panneau « Trouvé ! » s'affiche, avec « Rang d’arrivée : 1er ». Chez A, B passe en tête de la bande des joueurs avec le badge « A trouvé » et « 1er », sans titre ni points. Le chrono de A continue : la manche va jusqu'à son terme (30 s par défaut) tant que A n'a pas trouvé.
  - Réf. : 60 § 8.4 · 00 § Le jeu en une manche · round-players.tsx · round-input.tsx
- [ ] **Fin anticipée quand tout le monde a trouvé**
  - Faire : A et B trouvent tous deux pendant la première image.
  - Attendu : La manche se termine aussitôt, sans attendre la fin du chrono. La valeur et les secondes disparaissent, et chacun garde son panneau « Trouvé ! » jusqu'à l'arrivée de la révélation, qui suit presque immédiatement. La révélation ne montre qu'une image, celle du seul palier ouvert.
  - Réf. : 60 § 9.2 · SeatInputClosed · game-stage.tsx
- [ ] **Manche jouée jusqu'au bout sans réponse**
  - Faire : Personne ne répond pendant toute une manche.
  - Attendu : À 0 s, la valeur et les secondes disparaissent, et « Fin de la manche : la réponse arrive. » s'affiche brièvement sous l'image. La révélation suit, avec « Personne n’a trouvé » et les 3 images (par défaut).
  - Réf. : 60 § 9.1 · CloseRound · game-stage.tsx
- [ ] **Seul joueur connecté : un simple libellé**
  - Faire : En pleine partie, fermer l'onglet de B et regarder A pendant environ 25 s.
  - Attendu : Au-dessus de la bande apparaît « Vous êtes le seul joueur connecté. ». B reste dans la bande, grisé, avec une icône de déconnexion. Aucun bouton « Voir la réponse » ni « Passer la manche » (gestes réservés au solo), et la partie continue normalement.
  - Réf. : 60 § 9.3 · round-players.tsx

### 5.3 Révélation et « Manche suivante »

- [ ] **Contenu de la révélation** ⭐
  - Faire : Laisser se terminer une manche où B a trouvé, et regarder les deux écrans.
  - Attendu : Sous le titre « Salon », l'écran défile et affiche dans l'ordre : « Manche 1 sur 10 » ; « La réponse » ; le titre français (ex. « Le Voyage de Chihiro ») ; « Titre original : … » seulement s'il diffère (translittération latine pour un film japonais, ex. « Sen to Chihiro no Kamikakushi » ; aucune ligne pour « Toy Story ») ; « Sortie : <année> » ; la grille des images déjà servies. Viennent ensuite « Ont trouvé » (rang « 1er », avatar, pseudo, « N points », « Image 1 », durée en secondes) et le tableau « Classement ». Puis « La manche commence dans 8 secondes » (R par défaut), qui décompte, le bouton « Manche suivante » chez l'hôte seul, et enfin « Ce produit utilise l’API de TMDB mais n’est ni approuvé ni certifié par TMDB. ». Aucune icône d'image cassée, même tant que `public/brand/tmdb.svg` n'est pas déposé.
  - Réf. : 60 § 9.5 (D14) · round-reveal.tsx · tmdb-attribution.tsx
- [ ] **Seules les images des paliers ouverts sont révélées**
  - Faire : Comparer la révélation d'une manche close par anticipation au palier 1 avec celle d'une manche allée à son terme.
  - Attendu : On voit 1 image dans le premier cas et 3 (par défaut) dans le second. Il n'y a jamais de cadre pour un palier qui ne s'est pas ouvert.
  - Réf. : 60 § 9.5 (D14) · ServeGuard (revealing)
- [ ] **La durée de la révélation suit le réglage R**
  - Faire : Au lobby, régler « Durée de la révélation » à 20 (le maximum) et lancer. Recommencer ensuite à 3 (le minimum).
  - Attendu : Le décompte sous la révélation part d'environ 20 s, puis d'environ 3 s. À 3, le lobby affichait l'avertissement « Révélation courte : 5 s sont recommandées pour laisser le temps de lire la réponse. ».
  - Réf. : 50 § 4.5 · 60 § 5.3 · RevealRound
- [ ] **« Manche suivante » par l'hôte** ⭐
  - Faire : Pendant une révélation, quand le décompte affiche environ 7 s, A clique « Manche suivante » (essayer aussi un double clic).
  - Attendu : Le bouton affiche un indicateur le temps de la requête, et un second clic n'envoie rien. Sur les deux écrans, le décompte tombe à environ 3 secondes, puis la manche suivante démarre au même instant chez A et chez B. Une manche en cours n'est jamais raccourcie : le bouton n'existe que pendant la révélation.
  - Réf. : 60 § 5.4 · NextRoundController · AdvanceToNextRound · next-round-button.tsx · use-next-round.ts
- [ ] **Seul l'hôte voit « Manche suivante »**
  - Faire : Pendant une révélation, regarder l'écran de B (non-hôte).
  - Attendu : B n'a aucun bouton « Manche suivante », seulement le décompte.
  - Réf. : 60 § 5.4 · lobby.tsx (revealAction)
- [ ] **« Manche suivante » quand il reste moins de 3 s** ⚠️
  - Faire : A clique « Manche suivante » quand le décompte affiche 2 ou 1 seconde.
  - Attendu : Rien ne change : le décompte continue et aucun message d'erreur n'apparaît.
  - Réf. : 60 § 5.4 · AdvanceToNextRound (Unchanged)
- [ ] **Dernière manche : passage au podium**
  - Faire : Pendant la révélation de la dernière manche, observer l'écran, puis A clique « Manche suivante ».
  - Attendu : Aucune ligne « La manche commence dans … » n'apparaît sous la dernière révélation. Après le clic, le podium arrive environ 3 s plus tard sur les deux écrans. Sans clic, il arrive à la fin de R.
  - Réf. : 60 § 5.4, § 14.5 · round-reveal.tsx

### 5.4 Classement entre les manches

- [ ] **Tableau « Classement » de la révélation** ⭐
  - Faire : Après une manche où seul B a trouvé, lire le tableau sur un écran large (fenêtre de bureau).
  - Attendu : La légende est « Classement », avec les colonnes « Rang », « Joueur », « Points », « Cette manche », « Bonnes réponses » et « Temps cumulé ». B est « 1er », avec ses points, « +N » pour cette manche, 1 bonne réponse et sa durée. A est « 2e », avec 0. Les points de B sont ceux qu'affiche « Ont trouvé ».
  - Réf. : 80 § 8, § 9 · standings-table.tsx
- [ ] **Places partagées**
  - Faire : Laisser passer la manche 1 sans aucune bonne réponse.
  - Attendu : A et B sont tous deux « 1er », avec la mention « ex æquo ». Il n'y a jamais de tirage au sort.
  - Réf. : 80 § 8.2, § 8.3 · Ranking
- [ ] **Classement en portrait**
  - Faire : En mode appareil 375 px, avec un joueur au pseudo de 20 caractères larges (ex. « WWWWWWWWWWWWWWWWWWWW »), afficher une révélation.
  - Attendu : Chaque ligne tient sur deux rangées. La première porte le rang, l'avatar, le pseudo et « N points ». La seconde porte « +N sur cette manche », « N bonne réponse » et « Temps cumulé : X s ». Le pseudo long est tronqué par des points de suspension, sans aucun défilement horizontal.
  - Réf. : 80 § 20 · standings-table.tsx

### 5.5 Fin de partie et podium

- [ ] **Arrivée du podium** ⭐
  - Faire : Jouer une partie de 3 manches jusqu'au bout.
  - Attendu : À la fin de la dernière révélation, les deux écrans passent au podium sans navigation. On voit le titre « Podium » (qui reçoit le focus) et « Partie terminée · 3 manches ». Suivent le bouton « Rejouer » chez A (ou « En attente de l’hôte pour rejouer. » chez B), le classement final (« N manches jouées » sous chaque pseudo, sans colonne « Cette manche »), les faits marquants et le « Récapitulatif des films ». La liste des sièges et « Quitter le salon » restent dessous.
  - Réf. : 80 § 11 · 60 § 14.5 · podium.tsx · lobby.tsx
- [ ] **Faits marquants (D25)** ⭐
  - Faire : Jouer une partie où au moins un joueur trouve et où une manche reste sans bonne réponse.
  - Attendu : On lit « Meilleure réponse : <pseudo>, « <titre> » (+<points>) » et « Trouvé le plus vite : « <titre> », par <pseudo> en <durée> ». Les deux lignes restent même quand elles désignent la même réponse. S'y ajoute « 1 film que personne n’a trouvé » (au pluriel : « N films que personne n’a trouvés »).
  - Réf. : 80 § 11.5 (D25) · podium-highlights.tsx
- [ ] **Partie sans aucune bonne réponse**
  - Faire : Jouer une partie de 3 manches sans jamais répondre.
  - Attendu : « Aucune bonne réponse dans cette partie » remplace les deux premiers faits, suivi de « 3 films que personne n’a trouvés ». Tous les joueurs sont « 1er », avec « ex æquo ».
  - Réf. : 80 § 11.5 · podium-highlights.tsx
- [ ] **Récapitulatif des films**
  - Faire : Lire le « Récapitulatif des films » en surveillant l'onglet Réseau.
  - Attendu : Chaque manche a son entrée : « Manche 1 », le titre dans la langue du joueur, puis une ligne avec le titre original (s'il diffère) et l'année. Suit « Trouvé par 1 joueur », « Trouvé par 2 joueurs » ou « Personne n’a trouvé ». C'est du texte seul : aucune vignette, et aucune requête `/f/` pendant que le podium est affiché.
  - Réf. : 80 § 11.4 · round-recap.tsx
- [ ] **Podium identique après rechargement**
  - Faire : Sur le podium, recharger A (F5), puis ouvrir le lien du salon dans un nouvel onglet chez B.
  - Attendu : Le podium est identique (rangs, points, faits marquants, récapitulatif) et le reste jusqu'au « Rejouer ».
  - Réf. : 60 § 14.5 · 80 § 11 · RoomStateController · CurrentGame::forState
- [ ] **Podium d’une partie aux manches annulées sans remplacement** ⚠️
  - Faire : Mener jusqu’au podium la partie de l’item « Arriver au bout du catalogue avec le QCM » (Facile, 16 manches, 2 images, 10 s), dont les manches 14 à 16 sont annulées faute de propositions.
  - Attendu : L’en-tête affiche « Partie terminée · 16 manches ». Dans le « Récapitulatif des films », les entrées « Manche 14 », « Manche 15 » et « Manche 16 » ne portent que « Manche annulée », sans titre ni « Trouvé par … » : ces manches n’ont jamais été révélées. Sous chaque pseudo, le nombre de « manches jouées » exclut les manches annulées, qui ne rapportent aucun point, comme le dit l’aide : « Une manche annulée à la suite d’un incident ne compte pas : ni points, ni manche jouée. ».
  - Réf. : 80 § 11.4 · round-recap.tsx · game.recap.cancelled

### 5.6 « Rejouer » et non-répétition des films

- [ ] **« Rejouer » ramène tout le salon au lobby** ⭐
  - Faire : Sur le podium, A clique « Rejouer ».
  - Attendu : Le bouton affiche un indicateur, puis les deux écrans reviennent au lobby « Salon » sans changement d'URL ; chez A, le focus revient sur ce titre. A lit « Vous êtes l’hôte : vous réglez et lancez la partie. », B lit « En attente du lancement par l’hôte. ». A retrouve les réglages de la partie précédente, de nouveau modifiables, et le bouton « Aide » est revenu.
  - Réf. : 50 § 13 · ReplayController · ReplayRoom · replay-button.tsx · use-lobby-state.ts
- [ ] **Lancement bloqué par la non-répétition (D28)** ⭐
  - Faire : Jouer une partie complète de 10 manches (par défaut) sans manche annulée, puis A clique « Rejouer ». Lire le lobby chez A et chez B.
  - Attendu : Tous deux lisent « Films jouables : 6 pour 10 manches. » et l'encadré « Lancement impossible : pas assez de films jouables avec ces réglages. », avec les causes « Ce salon a déjà joué la plupart des films disponibles. » et « Il y a plus de manches que de films jouables. ». A seul voit deux solutions : « Créer un nouveau salon (16 films jouables avec ces réglages) » et « Jouer 6 manches (6 films jouables) ». « Autoriser les films déjà joués » n'est jamais proposé au J1. « Lancer la partie » est grisé, avec le motif « Lancement impossible : pas assez de films jouables avec ces réglages. ».
  - Réf. : 50 § 9.2, § 13 · 30 § 4 · PoolReporter · RoomSettingsPresenter::state · pool-status.tsx
- [ ] **Solution « Jouer 6 manches », puis partie sans film déjà joué**
  - Faire : A clique « Jouer 6 manches (6 films jouables) », puis « Lancer la partie », et joue jusqu'au podium.
  - Attendu : Le blocage disparaît, « Nombre de manches » vaut 6 et la partie se lance. Aucun film du récapitulatif précédent ne revient.
  - Réf. : 50 § 9.2 · 30 § 3.5 · RoomMemoryWindow
- [ ] **Solution « Créer un nouveau salon »**
  - Faire : Dans le salon bloqué, A clique « Créer un nouveau salon (16 films jouables avec ces réglages) ».
  - Attendu : La page `/r/new` (« Créer un salon ») s'ouvre. Le nouveau salon repart d'une mémoire vide : 16 films jouables.
  - Réf. : 50 § 9.2 (D28) · pool-status.tsx
- [ ] **Catalogue épuisé par des parties courtes** ⚠️
  - Faire : Enchaîner dans le même salon des parties de 3 manches avec « Rejouer ».
  - Attendu : Aucun film ne revient d'une partie à l'autre. Après 5 parties (15 films joués), le lobby affiche « Films jouables : 1 pour 3 manches. », la seule cause « Ce salon a déjà joué la plupart des films disponibles. » et une seule solution : « Créer un nouveau salon (16 films jouables avec ces réglages) ».
  - Réf. : 30 § 4 · PoolReporter
- [ ] **Double clic sur « Rejouer »** ⚠️
  - Faire : Sur le podium, A double-clique très vite sur « Rejouer ».
  - Attendu : Le salon revient une seule fois au lobby, sans aucun message d'erreur.
  - Réf. : 50 § 13 · ReplayRoom (R4 idempotent)
- [ ] **Sièges après « Rejouer »**
  - Faire : Rejouer après une partie où B a été retiré et où C est entré en cours de partie (retardataires désactivés).
  - Attendu : Dans la liste du lobby, B reste marqué « Retiré » (ou « Parti » s'il avait quitté). C devient un siège de lobby ordinaire : il est compté dans « Joueurs (n sur capacité) » et jouera la partie suivante.
  - Réf. : 50 § 13, § 15.3 · ReplayRoom · SeatViewPresenter::lobbySeats

### 5.7 Gestes de salon pendant la partie

- [ ] **Feuille « Joueurs » pendant une manche** ⭐
  - Faire : En pleine manche, cliquer « Joueurs » dans la bande des joueurs, chez A puis chez B, puis « Fermer ». Rouvrir la feuille et appuyer sur Échap.
  - Attendu : La feuille « Joueurs » affiche « Les sièges du salon. La partie continue pendant ce temps. », puis la liste « Joueurs (n sur capacité) » avec les badges « Hôte » et « Vous ». Chez A seulement, chaque autre siège propose « Nommer hôte » et « Retirer du salon ». Tout le monde a « Quitter le salon ». Le chrono continue derrière la feuille, que « Fermer » et Échap referment.
  - Réf. : 50 § 11.1 · round-players.tsx · seat-list.tsx · seat-actions.tsx
- [ ] **Transférer le rôle d'hôte en pleine partie** ⭐
  - Faire : A ouvre « Joueurs » et clique « Nommer hôte » sur B. La boîte « Nommer hôte » demande « Confier le rôle d’hôte à B ? » : confirmer par « Nommer hôte ».
  - Attendu : Dans la feuille « Joueurs », le badge « Hôte » passe sur B chez tous. À la révélation suivante, B a « Manche suivante » et A ne l'a plus. Au podium, B a « Rejouer » et A lit « En attente de l’hôte pour rejouer. ». La partie n'est jamais interrompue.
  - Réf. : 50 § 11.4 · 60 § 13.5 · HostTransferController · HandOverHost
- [ ] **Pas de « Nommer hôte » pour un siège déconnecté** ⚠️
  - Faire : Fermer l'onglet de B, attendre environ 25 s, puis A ouvre « Joueurs ».
  - Attendu : Le siège de B porte le badge « Déconnecté » et ne propose plus que « Retirer du salon ».
  - Réf. : 50 § 11.4 · seat-actions.tsx
- [ ] **Retirer un joueur en pleine manche** ⭐
  - Faire : A ouvre « Joueurs » et clique « Retirer du salon » sur B. La boîte demande « Retirer B ? Ce joueur ne pourra pas revenir dans ce salon. » : confirmer par « Retirer du salon ».
  - Attendu : Chez B, « L’hôte vous a retiré du salon. » peut s'afficher un instant. B arrive ensuite sur la page « Rejoindre le salon », dans l'apparence du visiteur (pas en sombre forcé). Il y lit « L’hôte vous a retiré de ce salon : vous ne pouvez pas y revenir. », sans formulaire, avec un lien « Accueil ». Chez A, B disparaît de la bande et porte « Retiré » dans la feuille. À la révélation suivante, B reste au classement avec ses points et le badge « Expulsé par l’hôte ».
  - Réf. : 50 § 11.3 (D15) · 60 § 13.4 · KickController · use-game-state.ts · room/join.tsx
- [ ] **Le joueur retiré ne peut pas revenir** ⚠️
  - Faire : Dans le navigateur de B, rouvrir `/r/<code>` puis `/r/<code>/join`.
  - Attendu : B tombe toujours sur la page « Rejoindre le salon », avec le même refus et sans formulaire.
  - Réf. : 50 § 7.2 · RoomController::show · RoomEntryController (kicked)
- [ ] **Quitter le salon en pleine partie**
  - Faire : B ouvre « Joueurs » et clique « Quitter le salon ». La boîte demande « Quitter le salon ? Vous pourrez revenir avec le lien. » : confirmer par « Quitter le salon ».
  - Attendu : B arrive sur l'accueil. Chez A, B reste dans la bande avec une icône de départ (« Parti » dans la feuille). Au classement suivant, B garde ses points, avec le badge « A quitté la partie ».
  - Réf. : 50 § 11.4 · LeaveRoom · standings-table.tsx
- [ ] **Revenir après être parti**
  - Faire : B rouvre le lien du salon pendant une manche commencée après son départ (pas la dernière).
  - Attendu : B retrouve la partie sans formulaire. Dans cette manche, il voit l'image mais n'a pas de saisie : il lit « Vous entrez dans la partie à la manche N. », N étant la manche suivante. Il joue normalement à partir de cette manche N. Ses points acquis sont conservés, et le badge « A quitté la partie » disparaît du classement. S'il revient pendant la manche même de son départ, il peut encore y répondre pour le temps qui reste.
  - Réf. : 60 § 13.6 · RecordHeartbeat · game-stage.tsx
- [ ] **Départ de l'hôte : transfert automatique, sans pause**
  - Faire : En pleine manche, A (hôte) clique « Quitter le salon » et confirme.
  - Attendu : Le rôle passe au siège connecté le plus ancien (B) : badge « Hôte » sur B dans la feuille, et « Manche suivante » chez B à la révélation. Les manches continuent sans pause. Si A revient par le lien, il ne récupère pas le rôle.
  - Réf. : 50 § 11.1 · TransferHost::automatic · 60 § 13.5

### 5.8 Arrivées pendant une partie

- [ ] **Retardataire admis (« Retardataires » activé)** ⭐
  - Faire : Au lobby, A active « Retardataires », puis lance la partie. Pendant la manche 1, C ouvre le lien, choisit un pseudo et un avatar, puis clique « Entrer ».
  - Attendu : La page « Rejoindre le salon » annonce « Une partie est en cours : vous entrerez à la manche suivante, sans aucun point. ». Après « Entrer », C arrive sur l'écran de partie, en sombre, sans image ni saisie ; le cadre affiche « Vous entrez dans la partie à la manche 2. ». C voit le chrono, la bande et la révélation (sans images). À la manche 2, C a l'image et la saisie comme les autres.
  - Réf. : 50 § 15 · 60 § 13.7 · RoomEntryController · game-stage.tsx
- [ ] **Retardataire dans le classement**
  - Faire : Lire le classement de la révélation 1, puis celui du podium.
  - Attendu : À la révélation 1, C est en dernier, avec « — » (lu « Sans rang » par un lecteur d'écran) et « Entrée à la manche 2 ». Au podium, C garde « Entrée à la manche 2 » et affiche son nombre de « manches jouées ».
  - Réf. : 80 § 8.2, § 8.5 · Ranking · standings-table.tsx
- [ ] **Arrivée pendant une partie sans retardataires (défaut)**
  - Faire : « Retardataires » est désactivé (défaut). Pendant une manche, C entre par le lien.
  - Attendu : La page d'entrée annonce « Une partie est en cours : vous attendrez dans le salon et jouerez la suivante. ». Ensuite, C lit « Une partie est en cours : vous jouerez la prochaine. », sans image ni saisie. C n'apparaît pas dans la bande des joueurs, mais figure dans la feuille « Joueurs ». Le même message reste affiché au podium. Après « Rejouer », C est un siège du lobby et joue la partie suivante.
  - Réf. : 50 § 15.3 · game-stage.tsx · lobby.tsx
- [ ] **Arrivée pendant la dernière manche** ⚠️
  - Faire : Avec « Retardataires » activé, C ouvre le lien pendant la dernière manche.
  - Attendu : La page d'entrée annonce « Une partie est en cours : vous attendrez dans le salon et jouerez la suivante. » : il ne reste aucune manche à rejoindre. Une fois entré, C lit « Une partie est en cours : vous jouerez la prochaine. ».
  - Réf. : 50 § 15.2 · RoomEntryController::lateJoinOpen
- [ ] **Entrée pendant le podium** ⚠️
  - Faire : Pendant que le podium est affiché (avant « Rejouer »), C ouvre le lien du salon, saisit un pseudo et clique « Entrer ».
  - Attendu : La page d'entrée annonce « Une partie est en cours : vous attendrez dans le salon et jouerez la suivante. ». C voit le podium de la partie qui vient de finir, sous « Une partie est en cours : vous jouerez la prochaine. », avec « En attente de l’hôte pour rejouer. ». Au « Rejouer » de A, C passe au lobby avec les autres.
  - Réf. : 50 § 7.2, § 15.3 · RoomEntryController (in_progress) · CurrentGame::forState · lobby.tsx
- [ ] **Aucune image demandée par un siège en attente** ⚠️
  - Faire : Chez C, retardataire en attente de sa manche, surveiller l'onglet Réseau (filtre Fetch/XHR).
  - Attendu : Aucune requête `/f/…` ne part avant la manche d'entrée de C ; elles commencent à cette manche.
  - Réf. : 60 § 13.7 · use-game-state.ts (isMemberOfRound)

### 5.9 Présence, déconnexion et pause

- [ ] **Déconnexion d'un joueur en pleine manche** ⭐
  - Faire : Fermer l'onglet de B pendant une manche et regarder A pendant environ 25 s.
  - Attendu : B reste dans la bande, grisé, avec une icône de déconnexion (« Déconnecté » dans la feuille « Joueurs »). La partie n'attend pas B. Si A avait déjà trouvé, la manche se termine dès que B passe à « déconnecté ».
  - Réf. : 60 § 13.2 · SweepSeatPresence · round-players.tsx
- [ ] **Retour dans le délai**
  - Faire : B rouvre le lien moins d'une minute après être passé à « déconnecté ».
  - Attendu : B redevient connecté chez tous. Il retrouve la manche en cours au palier affiché, avec son état de saisie (« Trouvé ! » s'il avait trouvé).
  - Réf. : 60 § 13.1 · RecordHeartbeat
- [ ] **Passage à « Parti » après le délai**
  - Faire : B reste absent plus d'environ 85 s : environ 25 s avant « déconnecté », puis 60 s de « Délai avant « parti » » (valeur fixe au J1, réglable seulement dans l'onglet Avancé du J2).
  - Attendu : B passe à « Parti » (icône de départ). Le classement affiche « A quitté la partie », points conservés. Si B était l'hôte, le rôle passe à A.
  - Réf. : 60 § 13.2 · SweepSeatPresence · TransferHost
- [ ] **Hôte déconnecté : il reste hôte, et le jeu ne l'attend pas**
  - Faire : Fermer l'onglet de A (hôte) en pleine partie et regarder B pendant 2 minutes.
  - Attendu : Les manches continuent normalement. Pendant environ 85 s, A garde le badge « Hôte » dans la feuille tout en étant déconnecté, et B n'a pas « Manche suivante ». Ensuite, le rôle passe à B.
  - Réf. : 60 § 13.5 · TransferHost::automatic (jamais à la simple déconnexion)
- [ ] **Pause quand plus aucun joueur de la partie n'est connecté** ⭐
  - Faire : Lancer une partie A + B. C entre pendant la partie (« Retardataires » désactivé) et devient donc spectateur. Fermer les onglets de A et de B, puis regarder C jusqu'à la fin de la révélation suivante (environ 1 min).
  - Attendu : Les manches vont au bout de leur durée sans joueur. À la fin d'une révélation, C voit, sous « Une partie est en cours : vous jouerez la prochaine. », l'encadré « Partie en pause », « Plus aucun joueur n’était connecté : la partie reprend dès que l’un d’eux revient. » et « Sans retour d’un joueur, elle s’arrêtera à HH:MM. » (15 min plus tard, heure locale). Aucun chrono ne tourne, et le rôle d'hôte finit par passer à C. Avec « Retardataires » activé, un C admis compte comme joueur présent et empêche la pause.
  - Réf. : 60 § 14.1 · EndReveal (anySeatPresent) · PauseGame · game-paused.tsx
- [ ] **Reprise d'une partie en pause** ⭐
  - Faire : Pendant la pause, A rouvre le lien du salon.
  - Attendu : Chez A et chez C, « La manche commence dans 5 secondes » s'affiche, puis la manche suivante démarre, et A y joue. A n'est plus hôte si le rôle était passé à C.
  - Réf. : 60 § 14.2 · ResumeGame · RecordHeartbeat
- [ ] **Fin d'une pause au bout de 15 minutes** ⚠️
  - Faire : Laisser la partie en pause 15 minutes sans rouvrir A ni B ; C reste ouvert.
  - Attendu : À l'heure annoncée, C voit le podium « Partie interrompue à la manche k sur 10 », et l'hôte (C) a « Rejouer ». Si A revient après cette échéance, il trouve ce même podium.
  - Réf. : 60 § 14.3 · InterruptPausedGame · podium.tsx
- [ ] **Hors ligne : bandeau, puis rattrapage** ⭐
  - Faire : Chez B, en pleine manche : DevTools → Réseau → « Hors connexion » pendant 15 s, puis revenir à « Pas de limitation ».
  - Attendu : Le bandeau « Vous êtes hors ligne. Le jeu ne s’interrompt pas pour autant : vérifiez votre connexion. » apparaît, et le chrono continue. Au retour, le bandeau disparaît et B affiche la même image, le même chrono et le même palier que A.
  - Réf. : 90 § 7.6 · connection-banner.tsx · use-game-state.ts
- [ ] **Reverb arrêté : bandeau de reconnexion** ⚠️
  - Faire : Dans PowerShell, arrêter Reverb : `Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -like '*reverb:start*' | ForEach-Object { Stop-Process -Id $_.ProcessId }`. Observer, puis relancer `php artisan reverb:start` dans un autre terminal.
  - Attendu : Au bout de quelques secondes, tout le monde voit « Connexion perdue, reconnexion en cours… Le jeu ne s’interrompt pas pendant ce temps. ». Chez l'hôte, « Manche suivante », « Rejouer », « Nommer hôte » et « Retirer du salon » sont grisés, mais « Quitter le salon » reste actif. La partie continue côté serveur. Une fois Reverb relancé, le bandeau disparaît et l'écran se recale sur l'état du serveur (manche, révélation ou podium).
  - Réf. : 50 § 8.1 · 60 § 12.6 · echo.ts (connectionStateOf) · use-lobby-state.ts (canWrite)

### 5.10 Resynchronisation, second onglet et langue

- [ ] **Recharger en pleine manche** ⭐
  - Faire : Pendant la 2e image, recharger B (F5) alors qu'il a déjà trouvé.
  - Attendu : Après le rechargement : même manche, même image, « 200 points en jeu », chrono aligné sur A à une seconde près, panneau « Trouvé ! » conservé et bande « A trouvé » intacte.
  - Réf. : 60 § 12 · RoomStateController · GameStateBuilder
- [ ] **Recharger pendant la révélation**
  - Faire : Recharger B pendant une révélation.
  - Attendu : B retrouve la même révélation (titre, « Ont trouvé », classement, images), avec un décompte cohérent avec celui de A.
  - Réf. : 60 § 12, § 9.5
- [ ] **Onglet passé en arrière-plan**
  - Faire : B passe sur un autre onglet pendant 1 minute, puis revient.
  - Attendu : L'écran se remet aussitôt sur la manche ou la révélation en cours, sans rejouer ce qui s'est passé entre-temps.
  - Réf. : 60 § 12.6 · use-game-state.ts (visibilitychange)
- [ ] **Second onglet du même joueur** ⭐
  - Faire : En pleine manche, ouvrir `/r/<code>` dans un nouvel onglet du navigateur de B. Revenir sur l'ancien onglet, puis le recharger.
  - Attendu : Le nouvel onglet prend la main. L'ancien affiche, avec un cadenas, « Vous jouez désormais dans un autre onglet : celui-ci n’affiche plus que la partie. Rechargez la page pour y reprendre la main. ». Il montre toujours la partie, mais sa saisie est inactive. Recharger l'ancien onglet lui rend la main, et c'est alors le nouveau qui affiche l'avis.
  - Réf. : 60 § 12.7 · ClaimSeatTab · SeatSuperseded · read-only-notice.tsx
- [ ] **Second onglet de l'hôte** ⚠️
  - Faire : Faire le même geste chez A, pendant une révélation puis au podium.
  - Attendu : Dans l'onglet supplanté, « Manche suivante » puis « Rejouer » sont grisés ; l'onglet actif les a.
  - Réf. : 60 § 12.7 · lobby.tsx (canWrite)
- [ ] **Onglet supplanté resté seul** ⚠️
  - Faire : B ouvre un second onglet, puis le ferme en gardant l'ancien (supplanté). Attendre environ 25 s.
  - Attendu : Chez A, B passe à « déconnecté » : l'onglet en lecture seule n'envoie plus de signal de présence. Recharger l'ancien onglet de B le rend actif et le reconnecte.
  - Réf. : 60 § 12.7 · use-lobby-state.ts (battement)
- [ ] **Changer de langue en pleine manche** ⭐
  - Faire : Chez B, cliquer le bouton de langue en bas à gauche (nommé « Langue actuelle : Français »), puis choisir « English » dans le menu « Langue ».
  - Attendu : Les libellés passent en anglais sans rechargement ni changement d'URL (« Round 1 of 10 », « 200 points at stake »…). L'image et le chrono ne sont pas interrompus, et A reste en français.
  - Réf. : 05 · LocaleController · language-switcher.tsx
- [ ] **Propositions du QCM rejouées à l'identique** ⭐
  - Faire : Jouer en « Facile » (ou en « Normal » à la dernière image) pour avoir les 4 propositions affichées en français. B passe en « English », recharge (F5), puis ouvre un second onglet.
  - Attendu : B voit toujours les mêmes quatre propositions, dans le même ordre et dans la langue d'origine (titres français). Elles ne sont jamais recomposées.
  - Réf. : 00 règle 3 · 70 § 10 · ComposeChoiceSets · ChoicesPresenter
- [ ] **QCM composé après un changement de langue**
  - Faire : En difficulté « Normal », B passe en « English » pendant la première image. Attendre la dernière image.
  - Attendu : Les quatre propositions de B arrivent en titres anglais (ex. « Spirited Away »), celles de A en titres français.
  - Réf. : 70 § 10.1 · LocaleController (player.locale)
- [ ] **Révélation déjà affichée, puis révélation suivante**
  - Faire : Changer de langue pendant une révélation, puis attendre la révélation suivante.
  - Attendu : Les libellés changent (« The answer », « Standings »…), mais le titre déjà affiché reste dans l'ancienne langue. La révélation suivante montre le titre anglais, et « Original title: … » s'il diffère.
  - Réf. : 60 § 9.5 · 05 § exceptions · round-reveal.tsx
- [ ] **Podium dans l'autre langue**
  - Faire : Sur le podium, passer de FR à EN, puis revenir.
  - Attendu : On lit « Final standings », « Game over · 3 rounds » et « Play again ». Les titres du récapitulatif et des faits marquants changent aussitôt de langue, dans un sens comme dans l'autre.
  - Réf. : 80 § 11.4, § 15 · round-recap.tsx · podium-highlights.tsx

### 5.11 Anti-triche visible dans l'onglet Réseau

- [ ] **Aucun titre dans le temps réel avant la révélation** ⭐
  - Faire : Réseau → filtre « WS » → connexion `app/…` → onglet « Messages ». Suivre une manche en difficulté « Expert », titre connu par tinker.
  - Attendu : `round.scheduled`, `tier.opened`, `player.locked` et `round.closed` ne contiennent ni titre, ni alias, ni année. `player.locked` ne porte que `sequenceIndex`, `publicId` et `lockRank` (plus l'enveloppe `v`, `serverNow`, `gameRef`), sans points ni palier. Le titre n'apparaît qu'avec `round.revealed`.
  - Réf. : 00 règle 3 · 60 § 11.7 · PlayerLocked · RoundScheduled · TierOpened · RoundClosed
- [ ] **Propositions sans indice de la bonne réponse** ⭐
  - Faire : Même écoute en difficulté « Facile », puis cliquer une proposition.
  - Attendu : `seat.choices` (canal `private-seat.…`) porte 4 chaînes, sans index ni marqueur de la bonne. La requête `POST /seat/<id>/choice` envoie la chaîne cliquée, jamais un numéro.
  - Réf. : 60 § 8.3 · SeatChoicesOffered · ChoiceController
- [ ] **En-têtes du service d'image** ⭐
  - Faire : Filtre « Fetch/XHR » : ouvrir une requête `/f/<32 caractères hexa>?expires=…&signature=…`, onglet En-têtes. Inspecter aussi l'`<img>` du cadre.
  - Attendu : Statut 200, avec `Content-Type: image/webp`, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow`, `X-Content-Type-Options: nosniff` et `Cross-Origin-Resource-Policy: same-origin`. Aucun `Set-Cookie`, `ETag`, `Last-Modified` ni `Content-Disposition`. L'URL ne contient ni chemin ni nom de fichier, et l'`<img>` pointe vers une URL `blob:`.
  - Réf. : 60 § 7.3, § 7.4 · FrameServeController · FrameImageResponse · frame-loader.ts
- [ ] **Lien d'image inutilisable après la révélation** ⭐
  - Faire : Dans une manche qu'on laisse aller à son terme, sans « Manche suivante », copier l'URL complète d'une requête `/f/` (Copier → Copier l'URL). L'ouvrir dans un onglet du même navigateur pendant la révélation, juste après sa fin, puis 10 s plus tard.
  - Attendu : Pendant la révélation, l'image s'affiche. Juste après sa fin, on obtient une 404 à corps vide. Environ 5 s après la fin prévue de la révélation, on obtient une 403 (signature expirée).
  - Réf. : 60 § 7.2, § 7.5 (D14) · ServeGuard · ServeUrl
- [ ] **Lien d'image inutilisable sans siège dans la partie**
  - Faire : En pleine manche, coller la même URL dans un navigateur qui n'a aucun siège dans cette partie (ex. une fenêtre InPrivate d'Edge).
  - Attendu : 404 à corps vide, exactement comme pour un lien inconnu.
  - Réf. : 60 § 7.2 (appartenance) · ServeGuard::membershipAllows
- [ ] **Lien du palier suivant inutilisable avant son heure** ⚠️
  - Faire : Régler « Durée d’une manche » à 120 s. Dans un message `tier.opened`, relever l'URL de `next.url` (retirer les `\` qui échappent les `/` dans le JSON). L'ouvrir aussitôt, préfixée par http://127.0.0.1:8000, puis de nouveau dans les 2 s qui précèdent le palier suivant.
  - Attendu : 404 tant que le palier n'est pas à moins d'environ 2 s de son ouverture, puis l'image.
  - Réf. : 60 § 7.1, § 7.2 · TierImageRefPresenter (fetchNotBefore)
- [ ] **Signature modifiée** ⚠️
  - Faire : Changer un caractère du paramètre `signature` d'une URL `/f/` valide et l'ouvrir.
  - Attendu : Statut 403.
  - Réf. : routes/game.php (signed:relative)
- [ ] **Joueur retiré : plus d'image** ⚠️
  - Faire : Copier une URL `/f/` chez B, faire retirer B par A pendant la même manche, puis rouvrir l'URL dans le navigateur de B.
  - Attendu : 404.
  - Réf. : 60 § 7.2 (kicked) · ServeGuard::membershipAllows
- [ ] **Rien dans les réponses HTTP ni dans l'état**
  - Faire : En pleine manche (en « Expert », ou avant la dernière image en « Normal »), chercher le titre en cours dans tout le panneau Réseau (Ctrl+F). Ouvrir `/r/<code>/state` dans un onglet de B, puis depuis un navigateur sans siège.
  - Attendu : Aucune réponse HTTP ne contient le titre ou ses alias : ni la page, ni le JSON de `/r/<code>/state`. Sans siège, `/r/<code>/state` répond 403.
  - Réf. : 00 règle 3 · 60 § 12.1 · RoomStateController
- [ ] **Aucun appel à TMDB pendant la partie**
  - Faire : Filtrer l'onglet Réseau sur « tmdb » pendant toute une partie.
  - Attendu : Aucune requête vers TMDB, ni API ni image. Le seul logo TMDB demandé vient de `/brand/`.
  - Réf. : 00 règle 6 · tmdb-attribution.tsx
- [ ] **Image protégée contre la copie**
  - Faire : Pendant une manche, faire un clic droit sur l'image, essayer de la glisser, puis de la sélectionner.
  - Attendu : Aucun menu contextuel ne s'ouvre, l'image ne se glisse pas et rien ne se sélectionne.
  - Réf. : 90 § 7.1 · game-frame.tsx
- [ ] **Pages de salon non indexables**
  - Faire : Lire les en-têtes de la réponse du document `/r/<code>` et le titre de l'onglet du navigateur.
  - Attendu : On trouve `X-Robots-Tag: noindex, nofollow` et `Referrer-Policy: strict-origin-when-cross-origin`. L'onglet s'intitule « Salon - TripleFrames » et ne contient jamais le code.
  - Réf. : 90 § 5 · 50 § 6.5 · RobotsDirectives
- [ ] **Débit du service d’image** ⚠️
  - Faire : Régler « Durée d’une manche » à 120 s. Pendant une manche, copier l’URL complète d’une requête `/f/…`, puis, dans la même minute, lancer dans PowerShell : `1..61 | % { curl.exe -s -o NUL -w "%{http_code} " "<URL>" }`.
  - Attendu : curl reçoit 60 réponses 404, puis 429. Il ne porte pas le cookie du joueur, et l’URL signée n’est servie qu’aux sièges de la partie. Le limiteur `frame-serve` (60 par minute par défaut, compté par jeton ou, à défaut, par adresse) passe après la vérification de la signature et avant la garde. Le navigateur du joueur, compté sur son propre jeton, continue d’afficher ses images.
  - Réf. : 60 § 7.3, § 10.3 · FortifyServiceProvider (frame-serve) · EngineConstants

### 5.12 Affichage : portrait, desktop, sombre, clavier, aide

- [ ] **Manche en portrait à 375 px** ⭐
  - Faire : Passer B en mode appareil 375 × 667 pendant une manche à 4 joueurs ou plus.
  - Attendu : La page ne défile pas. De haut en bas : la ligne d'état, l'image 16:9 (la plus grande qui tienne), la saisie, la bande des joueurs avec le bouton « Joueurs » (qui défile horizontalement), puis la ligne basse avec l'icône de langue et « Informations légales ». Les zones tactiles mesurent au moins 44 px, et tirer vers le bas ne recharge pas la page.
  - Réf. : 90 § 7.2 · 00 règle 10 · round-scene.tsx · game-layout.tsx
- [ ] **Révélation, podium et lobby en portrait**
  - Faire : Toujours à 375 px, afficher une révélation, le podium, puis le lobby après « Rejouer ».
  - Attendu : Le contenu défile à l'intérieur de l'écran. Le classement garde sa présentation sur deux rangées, sans défilement horizontal.
  - Réf. : 90 § 2.3 · standings-table.tsx
- [ ] **Desktop, 1024 px et plus**
  - Faire : Élargir la fenêtre à 1280 px pendant une manche.
  - Attendu : La bande des joueurs devient une colonne à droite de l'image. Rien d'autre ne change : mêmes éléments, dans le même ordre.
  - Réf. : 90 § 7.2 · 00 règle 10 · round-players.tsx
- [ ] **Thème sombre forcé en jeu** ⭐
  - Faire : Dans A, choisir « Clair » dans le menu « Apparence » de l'accueil, puis ouvrir le salon (`/r/<code>`). Dans C, sans siège dans ce salon, régler aussi « Clair » sur l'accueil, puis ouvrir `/r/<code>/join`.
  - Attendu : Chez A, la page du salon est sombre dès le premier affichage, sans éclair clair, en lobby, en manche, en révélation et au podium, et n'offre aucun choix d'apparence. Chez C, la page « Rejoindre le salon » et l'accueil restent en clair.
  - Réf. : 90 § 2.2 · ForceGameAppearance · game-layout.tsx
- [ ] **Navigation au clavier**
  - Faire : Sans souris : aller par Tab jusqu'à « Joueurs », valider par Entrée, puis Échap. Suivre le focus à la révélation, au podium et après « Rejouer ». Activer « Manche suivante » et « Rejouer » par Entrée.
  - Attendu : Un anneau de focus reste visible, et Échap ferme la feuille. Le focus va sur l'en-tête de la révélation (« La réponse » et le titre), sur « Podium » au podium, puis sur « Salon » après « Rejouer ». Un changement d'image ne le déplace jamais. « Manche suivante » et « Rejouer » s'activent au clavier.
  - Réf. : 90 § 7.5 · round-reveal.tsx · podium.tsx · lobby.tsx
- [ ] **Aide du jeu**
  - Faire : Au lobby, cliquer « Aide », lire la feuille, la faire défiler au clavier, puis « Fermer ». Passer « Images par manche » à 4, puis à 5, en rouvrant l'aide à chaque fois. Lancer la partie.
  - Attendu : La feuille « Réponses et points » (« Ce qui est accepté comme réponse, et comment les points sont comptés. ») contient la règle de la saga et cinq lignes de barème. On y lit « Bonus de rapidité : jusqu’à 50 % de la valeur de l’image… » à 3 images (défaut), 33 % à 4 et 25 % à 5. « Fermer » et Échap la referment. Pendant la partie (manche, révélation, podium), le bouton « Aide » n'existe pas ; il revient après « Rejouer ».
  - Réf. : 90 § 7.7 · game-help.tsx · PlatformLimits::speedBonusMaxPercent (D22)
- [ ] **Annonces pour lecteur d'écran**
  - Faire : Activer le Narrateur Windows (Ctrl+Win+Entrée), ou inspecter la zone `div[role=status]`, pendant une manche, une reconnexion et un transfert d'hôte.
  - Attendu : Aux réglages par défaut, on entend « Nouvelle image, 2 sur 3. », « Mi-manche. », « Dernier quart de la manche. », « Plus que 3 secondes. » et « Manche terminée. ». Après une coupure, « Connexion rétablie. ». Lors d'un transfert, les autres entendent « B est maintenant l’hôte. » et le nouvel hôte « Vous êtes l’hôte : vous réglez et lancez la partie. ». Après un rechargement, rien de passé n'est annoncé.
  - Réf. : 90 § 7.4 · game-announcer.tsx · use-round-announcements.ts · use-lobby-state.ts
- [ ] **Liens légaux sans quitter la partie**
  - Faire : En pleine partie, cliquer « Informations légales » (en bas à droite), puis un lien.
  - Attendu : La feuille liste « Mentions légales », « Conditions générales d’utilisation », « Politique de confidentialité », « Signaler un contenu » et la mention TMDB. Le lien s'ouvre dans un nouvel onglet, et la partie continue dans l'onglet d'origine.
  - Réf. : 90 § 3.1 · site-footer.tsx

### 5.13 Robustesse du serveur et exploitation

- [ ] **File de jobs arrêtée : le client ne décide rien, le serveur rattrape** ⚠️
  - Faire : En pleine manche, arrêter l'écouteur de files : `Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -like '*queue:*' | ForEach-Object { Stop-Process -Id $_.ProcessId }`. Suivre la manche dans l'onglet Réseau (Fetch/XHR) jusqu'à la manche suivante. Relancer ensuite `php artisan queue:listen --queue=game,default --tries=1 --sleep=1 --timeout=900`, puis `php artisan game:reschedule`.
  - Attendu : Le navigateur ne passe jamais seul à l'étape suivante : aux frontières (palier, fin de manche, fin de révélation), des requêtes `GET /r/<code>/state` partent, et c'est le serveur qui rattrape l'étape échue puis la diffuse à tous. La partie continue donc presque au même rythme. L'écran peut rester quelques secondes (au plus une dizaine) sur « 0 s » avant la révélation ; un F5 déclenche le rattrapage aussitôt. Une fois l'écouteur relancé et `game:reschedule` exécuté, les transitions repartent par les jobs, sans relecture.
  - Réf. : 00 règle 8 · 60 § 2.6, § 4.4, § 17.5 · CatchUpGame · store.ts (watchdog, next_transition)
- [ ] **Pendant une maintenance, la partie continue et « Rejouer » est refusé** ⚠️
  - Faire : Pendant une partie, lancer `php artisan deploy:drain --timeout=30 --window=5` dans un autre terminal ; la commande attend la fin des parties. Recharger A (F5) et jouer jusqu'au podium. Lancer ensuite `php artisan deploy:release` et attendre environ 10 s sans recharger.
  - Attendu : Après le rechargement, le bandeau « Une mise à jour du site est en préparation : aucune nouvelle partie ne peut être lancée pour le moment. Les parties en cours continuent normalement. » apparaît, et la partie va à son terme. Au podium, « Rejouer » est grisé avec « Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. Réessayez un peu plus tard. » ; au lobby, « Lancer la partie » porterait le même motif. Après `deploy:release`, le bandeau disparaît en environ 10 s sans rechargement, et « Rejouer » redevient actif (BUG-P2).
  - Réf. : 100 § 11.3 (D32) · 50 § 13 · ReplayRoom (R6) · use-maintenance-refresh.ts · maintenance-banner.tsx
- [ ] **Manche annulée après un incident d'image** ⚠️
  - Faire : Au lobby, régler « Durée d’une manche » à 120 s (paliers de 40 s). Pendant la première image, ouvrir `php artisan tinker` et taper `$r = App\Models\Round::where('status', 'running')->latest('id')->first();`, puis `$r->movie->frames->each(fn ($f) => Storage::disk('frames')->move($f->game_path, $f->game_path.'.bak'));`. Attendre la 2e image, puis rétablir les fichiers avec `$r->movie->frames->each(fn ($f) => Storage::disk('frames')->move($f->game_path.'.bak', $f->game_path));`. Ne pas oublier cette dernière étape.
  - Attendu : Juste avant la 2e image, « Image indisponible. » peut s'afficher un instant. À l'ouverture de la 2e image, tous les écrans affichent, à la place de l'image, « Manche annulée à la suite d’un incident : elle ne compte pas. », puis « La manche commence dans 5 secondes ». Une manche de remplacement démarre sous le même numéro (« Manche k sur M »). Les points gagnés dans la manche annulée ne comptent pas au classement suivant.
  - Réf. : 60 § 15.2 · MintTierServeToken · CancelRound · game-stage.tsx
- [ ] **Salon archivé pendant qu'on le regarde** ⚠️
  - Faire : Sur le podium, lancer d'une seule ligne PowerShell (l'écouteur de files doit tourner) : `php artisan tinker --execute="App\Models\Room::where('room_code', '<CODE>')->update(['last_activity_at' => now()->subDays(2)]);"; php artisan room:archive-idle`.
  - Attendu : Toutes les pages ouvertes du salon passent sur « Salon expiré » (HTTP 410, en sombre) : « Ce salon a été fermé après une période d’inactivité. Créez-en un nouveau pour rejouer. », avec les boutons « Créer un salon » et « Accueil ». Rouvrir le lien donne la même page. Si rien ne se passe, un signal de présence a remis l'activité à jour entre les deux commandes : relancer la ligne.
  - Réf. : 50 § 16 · ArchiveIdleRooms (file default) · ArchiveRoom · use-game-state.ts (room.archived) · room-expired.tsx
- [ ] **Dépublier un film tiré par la partie en cours**
  - Faire : Lancer une partie de 3 manches. Pendant la manche 1, dans `php artisan tinker` interactif, taper `App\Models\Game::latest('id')->first()->rounds()->orderBy('sequence_index')->with('movie')->get()->map(fn ($r) => [$r->sequence_index, $r->movie->title_original])->all()` et noter le film de rang 3. En curateur, le dépublier depuis sa fiche (« Dépublier le film », avec un motif). Jouer jusqu’au podium, puis le republier.
  - Attendu : La manche 3 se joue normalement avec ce film : les images sont servies, sans « Image indisponible. » ni annulation, puis son titre est révélé et il figure au récapitulatif. C’est le contrat affiché par la boîte de dépublication : « Ses images restent publiées, et une partie en cours le termine normalement. ».
  - Réf. : 20 § 8.3 · admin.movie.unpublish.description · MaterializeDraw (tirage figé)
- [ ] **Dépublier en back-office l’image d’un palier à venir** ⚠️
  - Faire : Régler « Durée d’une manche » à 120 s (3 images, soit 3 paliers de 40 s). Pendant la 1re image d’une manche, chrono au-dessus de 80 s, lire le film avec tinker ; si c’est Snow White (deux variantes au niveau 5), attendre la manche suivante. En curateur, dans la banque de ce film, cliquer « Dépublier » sur l’image du niveau 5, puis « Dépublier l’image ». Rétablir ensuite l’image par « Conforme, publier » dans /admin/review.
  - Attendu : La boîte de dépublication avertit : « Ce film deviendra incomplet : il restera jouable jusqu’à N = 4, avec repli de niveau. ». Au plus tard à l’ouverture prévue de la 2e image, puisque le jeton de la 3e image est frappé un cran à l’avance et qu’aucune autre variante n’existe au niveau 5, tous les écrans affichent « Manche annulée à la suite d’un incident : elle ne compte pas. ». Suivent « La manche commence dans 5 secondes » et une manche de remplacement sous le même numéro.
  - Réf. : 60 § 6.2, § 15.2 · MintTierServeToken · Frame::isServable · CancelRound

### 5.14 Hors périmètre (non livré, ne pas essayer)

- Onglet « Avancé » des réglages (points par palier, interrupteur du bonus de rapidité, « Pas de film déjà joué », « Délai avant « parti » », tentatives) : prévu au J2, lot L50-10 (`RoomSettingsEditor::ADVANCED_TAB_AVAILABLE = false`). Au J1, le délai avant « parti » reste donc à 60 s et la non-répétition est toujours active.
- Remède « Autoriser les films déjà joués » : retiré du rapport de vivier tant que l'onglet Avancé n'est pas livré (D28 ; J2, L50-10). Au J1, seul « Créer un nouveau salon » répond à la non-répétition.
- Partie sans score (tous les paliers à 0) et masquage de la valeur qui va avec : ne se règle que dans l'onglet Avancé, et le masquage arrive au J2 (L60-17).
- Sélecteur de thèmes au lobby : masqué au J1 (`themes` vaut `null`).
- Annulation immédiate d'une manche en cours quand un admin suspend ou retire un film : prévue au J2 (L60-17, `60` § 15.4). Au J1, une dépublication n'agit qu'au moment où l'image doit être servie (`60` § 15.3).
- Signalement d'un pseudo ou d'une photo depuis la feuille « Joueurs », et masquage après deux signalements : J2 (`40` § 10). Aucun bouton de signalement en jeu au J1.
- Configurations de salon sauvegardées, historique, compteurs de profil, connexion Discord/Google : prévus au J2 (`configs` vaut `null` au J1).
- Recette sur un vrai téléphone (360 × 640 clavier ouvert, appui long sur iOS Safari) : le serveur local n'écoute que sur 127.0.0.1. Elle est prévue à la recette sur appareil réel (étape 127, geste 11 c du § 3 de REPRISE).
- Test de charge complet (D33) et maintenance réelle sur le VPS : phase D, avant la première partie en production.
- Parcours Playwright outillés des écrans de jeu : J2 (`100`, L100-16).
- Classements globaux, chat, partage, badges, statistiques avancées : hors v1.

## 6. Mode solo

Le mode d’entraînement solo de bout en bout : la page d’entrée `/solo/new` (un des quatre presets, un pseudo, un avatar), puis la partie sur `/solo`, sans salon ni temps réel. On y essaie aussi les gestes « Voir la réponse », « Passer la manche » et « Manche suivante », le podium et la relance, la reprise après un rechargement ou depuis un autre onglet, puis la pause et l’interruption. Suivent le drainage de déploiement, l’effacement d’un siège inactif et le catalogue insuffisant (D19).

**Prérequis**

- `composer dev` est lancé : il sert sur http://127.0.0.1:8000 avec l’écouteur des files game puis default, Reverb et Vite. La VM Homestead (MySQL, 192.168.10.10) est démarrée. Si `.env` porte `QUEUE_CONNECTION=redis` ou `CACHE_STORE=redis`, il faut aussi un Redis joignable.
- Base seedée : lancer `php artisan backup:snapshot` et exiger le code 0 (règle 12), puis `php artisan migrate:fresh --seed`. On obtient 16 films de démo publiés, jouables de 2 à 5 images par manche.
- Les images de démo sont des aplats gris identiques, sans aucun indice. Pour trouver, tapez un de ces titres, en FR ou en EN : Blanche-Neige et les Sept Nains, Le Voyage de Chihiro, Mon voisin Totoro, Le Roi Lion, Toy Story, Le Monde de Nemo, Ratatouille, Matrix, Jurassic Park, Retour vers le futur, Shining, Pulp Fiction, Inception, Star Wars : Un nouvel espoir, Star Wars : L’Empire contre-attaque, Dune : Deuxième partie. On peut aussi passer par les propositions ou par « Voir la réponse ».
- L’identité invitée vit dans le cookie `player_token`. Une fenêtre privée ou un second navigateur donne un nouveau joueur, sans siège solo. Deux onglets ou deux fenêtres du même profil partagent le même siège.
- DevTools du navigateur : onglet Réseau (mode « Hors ligne », blocage d’URL de requête), Application › Cookies, et mode appareil en 375 × 667 portrait.
- Un second terminal à la racine du dépôt, pour `php artisan deploy:drain` / `deploy:release`, `php artisan purge:run --sync` et `php artisan tinker`.
- Pour le groupe « Catalogue insuffisant », à jouer en dernier : un compte curateur au second facteur confirmé (curator@tripleframes.test / password ; activer le second facteur sur `/settings/security`, voir la section back-office). Pour revenir ensuite au catalogue complet : `php artisan backup:snapshot`, puis `php artisan migrate:fresh --seed`.

### 6.1 Entrée dans le solo (/solo/new)

- [ ] **Ouvrir le solo depuis l’accueil** ⭐
  - Faire : Dans une fenêtre privée, ouvrir `/`, puis cliquer « Jouer en solo ».
  - Attendu : On arrive sur `/solo/new` : l’onglet s’intitule « Jouer en solo - TripleFrames » et la page « Jouer en solo ». Sous le titre : « Entraînez-vous seul sur le catalogue : choisissez un preset, un pseudo et un avatar. ». Le groupe « Choisissez un preset » montre quatre tuiles dans cet ordre : Classique, Rapide, Hardcore, Découverte, chacune avec sa description (Classique : « Le réglage de référence, équilibré pour une partie entre amis. »). Classique est cochée. Avec le catalogue de démo complet, aucune tuile ne porte d’avertissement. Viennent ensuite le champ « Pseudo », avec l’aide « Entre 2 et 20 caractères. » (sans mention d’unicité), puis « Choisissez un avatar » : 24 avatars, Ours présélectionné pour un visiteur neuf. En bas, le bouton « Commencer l’entraînement » et, sous lui, le lien « En continuant, vous acceptez les conditions générales d’utilisation. ».
  - Réf. : 60 § 16.4 · SoloGameController::create · pages/room/solo.tsx · components/room/solo-preset-field.tsx · seat-form.tsx
- [ ] **Aller sur /solo sans siège solo** ⚠️
  - Faire : Dans une fenêtre privée neuve, taper `/solo` dans la barre d’adresse. Ouvrir ensuite DevTools › Application › Cookies.
  - Attendu : On est redirigé vers `/solo/new`, formulaire vide. Aucun cookie `player_token` n’a été posé : un GET ne frappe jamais de jeton.
  - Réf. : 60 § 16.4 · SoloGameController::show · C4 I4.1
- [ ] **Ouvrir le lien des CGU sans perdre la saisie**
  - Faire : Sur `/solo/new`, taper un pseudo et choisir un avatar, puis cliquer le lien « En continuant, vous acceptez les conditions générales d’utilisation. ».
  - Attendu : La page des CGU s’ouvre dans un nouvel onglet. L’onglet du solo garde le pseudo saisi, l’avatar choisi et le preset coché.
  - Réf. : 90 § 10 · components/room/seat-form.tsx · legal.terms_notice
- [ ] **Faire refuser le pseudo** ⚠️
  - Faire : Sur `/solo/new`, envoyer successivement : un pseudo vide, « A », « Иван », « admin » et « 🎬🎬 ». Après chaque envoi, regarder DevTools › Cookies.
  - Attendu : Les messages sous le champ sont, dans l’ordre : « Le champ pseudo est obligatoire. », « Le pseudo doit compter entre 2 et 20 caractères. », « Le pseudo ne peut utiliser que l’alphabet latin, accents compris, des chiffres, des espaces, « - » et « _ ». », « Ce pseudo n’est pas disponible. Choisissez-en un autre. » et « Ce pseudo contient un caractère non autorisé : symbole, émoji ou caractère invisible. ». Après chaque refus, le focus revient au champ « Pseudo », et le preset et l’avatar choisis restent en place. Aucun cookie `player_token` n’est créé et aucune partie ne démarre.
  - Réf. : 40 § 5.9 · SoloStartRequest · App\Rules\ValidNickname · lang/fr/validation.php
- [ ] **Même pseudo dans deux navigateurs**
  - Faire : Dans deux navigateurs différents (ou une fenêtre normale et une fenêtre privée), démarrer un solo avec le même pseudo « Alex ».
  - Attendu : Les deux démarrages sont acceptés : le solo n’exige aucune unicité du pseudo.
  - Réf. : 60 § 16.2 · C5 · room.solo.nickname_hint
- [ ] **Choisir un preset au clavier et à la souris**
  - Faire : Sur `/solo/new`, faire Tab jusqu’aux presets, utiliser les flèches ↑ ↓ ← →, puis Tab. Cliquer ensuite dans le texte d’une tuile, pas sur le rond.
  - Attendu : Le groupe ne compte qu’un seul arrêt de tabulation : le focus arrive sur la tuile cochée, les flèches déplacent la sélection d’une tuile à l’autre, et Tab sort du groupe. Un clic n’importe où dans la tuile la coche. La tuile cochée est mise en évidence par sa bordure et son fond.
  - Réf. : 60 § 16.4 · components/room/solo-preset-field.tsx
- [ ] **Avatar suggéré par l’identité invitée** ⚠️
  - Faire : Dans un navigateur où l’on a déjà rejoint ou créé un salon avec un avatar précis (par exemple Panda), ouvrir `/solo/new`.
  - Attendu : Panda est présélectionné. Le pseudo reste vide, et aucun avatar n’est marqué « déjà choisi dans ce salon ».
  - Réf. : 60 § 16.4 · PresentsSeatForm::avatarProps · AvatarPresetCatalog::suggest · C4 I4.5
- [ ] **Jouer en solo en étant connecté à un compte**
  - Faire : Se connecter avec player@tripleframes.test / password, puis ouvrir `/solo/new` et démarrer.
  - Attendu : Le formulaire est le même (preset, pseudo, avatar) et la partie démarre normalement : au J1, le solo ne requiert ni n’utilise le compte.
  - Réf. : Règle 11 · 60 § 16.2 · SoloSeat (jeton seul)

### 6.2 Première partie en Classique (déroulé d’une manche)

- [ ] **Démarrer une partie Classique** ⭐
  - Faire : Sur `/solo/new`, taper le pseudo « Testeur », laisser Classique cochée et cliquer « Commencer l’entraînement ».
  - Attendu : Pendant l’envoi, le bouton est désactivé et affiche un indicateur, et aucun second envoi ne part. On arrive sur `/solo`, forcé en thème sombre. Le décompte « La manche commence dans 5 secondes » (5 s par défaut) s’affiche, puis « Manche 1 sur 10 », « 300 points en jeu » (barème par défaut), le temps restant affiché « 30 s » (la phrase « Temps restant : 30 » n’est lue que par les lecteurs d’écran) et la première image, un aplat gris. On ne voit ni bande des joueurs ni bouton « Joueurs », et jamais « Vous êtes le seul joueur connecté. ».
  - Réf. : 60 § 16.2, § 16.4 · StartSoloGame · pages/game/solo.tsx · game-stage.tsx
- [ ] **Voir passer les paliers** ⭐
  - Faire : Pendant une manche, ne pas répondre et observer l’écran jusqu’à la fin du temps.
  - Attendu : L’image change toutes les 10 s par défaut (30 s répartis sur 3 images), et la valeur affichée passe à « 200 points en jeu », puis à « 100 points en jeu » (barème par défaut). Le chrono décroît de façon continue et la saisie reste ouverte.
  - Réf. : 60 § 2, § 6 · round-scene.tsx
- [ ] **Envoyer une réponse fausse en texte libre**
  - Faire : Taper « zzz » dans le champ (placeholder « Titre du film ») puis « Valider ». Renvoyer aussitôt, en moins d’une seconde, avec Entrée. Enfin, envoyer « ?? ».
  - Attendu : La première réponse donne « Ce n’est pas ça. » et le compteur « 14 tentatives restantes » (15 par défaut pour une manche de 30 s). Le texte envoyé reste sélectionné. Le double envoi rapide donne « Une tentative à la fois. ». « ?? » donne « Tapez au moins une lettre ou un chiffre. ».
  - Réf. : 70 § 17 · answer-input.tsx · throttle:answer
- [ ] **Trouver le film et voir la fin anticipée** ⭐
  - Faire : Pendant la 1re image, taper le bon titre, en FR ou en EN (voir la liste des prérequis ; au besoin, faire d’abord « Voir la réponse » sur une manche pour repérer les titres).
  - Attendu : Le panneau affiche brièvement « Trouvé ! », « Gagné : … points » (au plus 450 à la 1re image avec le barème par défaut : 300 plus 50 % de bonus), « dont … de bonus de rapidité » et « Rang d’arrivée : 1er ». Le champ et les deux gestes disparaissent. La manche se clôt aussitôt, sans attendre la fin des 30 s, et la révélation arrive en une seconde environ.
  - Réf. : 60 § 16.4 (BUG-P1) · round-input.tsx LockedPanel
- [ ] **Lire la révélation** ⭐
  - Faire : Observer l’écran qui suit une manche close.
  - Attendu : On lit « Manche 1 sur 10 », « La réponse », puis le titre dans la langue de l’interface, « Titre original : … » s’il diffère et « Sortie : <année> ». Suivent les images montrées pendant la manche (liste que les lecteurs d’écran nomment « Images de la manche »), puis « Ont trouvé » avec votre pseudo, les points et « Image 1 », ou « Personne n’a trouvé ». Viennent ensuite le « Classement » (votre ligne, rang « — », colonne « Cette manche »), « La manche commence dans … secondes », le bouton « Manche suivante » et la mention « Ce produit utilise l’API de TMDB mais n’est ni approuvé ni certifié par TMDB. ». Le focus est posé sur la réponse. Au bout de 8 s par défaut, la manche 2 démarre d’elle-même.
  - Réf. : 60 § 9.5 · round-reveal.tsx · standings-table.tsx · Scoreboard (rang nul en solo)
- [ ] **Voir les propositions à la dernière image (Normal)** ⭐
  - Faire : Dans une manche Classique, ne rien répondre jusqu’à la 3e image (20 s par défaut). Choisir ensuite une proposition : la bonne dans une manche, une fausse dans la suivante.
  - Attendu : À la 3e image, le bloc « Propositions » apparaît sous le champ texte avec 4 titres, sans voler le focus. La bonne proposition donne « Trouvé ! ». Une fausse affiche « Mauvaise proposition : la saisie est close pour cette manche. », la manche se clôt aussitôt et la révélation indique « Personne n’a trouvé ».
  - Réf. : 70 § 16 · 60 § 8.3 · choice-grid.tsx
- [ ] **Laisser finir le temps sans réponse**
  - Faire : Laisser courir une manche Classique jusqu’au bout sans rien envoyer.
  - Attendu : « Fin de la manche : la réponse arrive. » s’affiche, puis la révélation, avec « Personne n’a trouvé ».
  - Réf. : 60 § 9.1 · game.round.time_up
- [ ] **Épuiser le texte avant la dernière image (Normal)** ⚠️
  - Faire : Dans une manche Classique, dès la 1re image, envoyer 15 réponses fausses en appuyant sur Entrée environ une fois par seconde (le texte reste dans le champ), avant la 3e image qui arrive à 20 s par défaut.
  - Attendu : Le message « Plus de tentatives en texte libre : les propositions arrivent avec la dernière image. » s’affiche. La manche ne se clôt pas, et « Voir la réponse » et « Passer la manche » restent affichés. À la 3e image, les 4 propositions arrivent et restent jouables.
  - Réf. : D20 du 23/09 · 70 § 16 · game-stage.tsx (inputOpen)
- [ ] **Ouvrir l’aide du barème**
  - Faire : Pendant le décompte d’une manche ou pendant une révélation, cliquer « Aide », puis « Fermer ». Chercher aussi le bouton pendant qu’une image est ouverte.
  - Attendu : La feuille « Réponses et points » s’ouvre, avec « Ce qui est accepté comme réponse, et comment les points sont comptés. ». Pour 3 images par manche, elle dit « Bonus de rapidité : jusqu’à 50 % de la valeur de l’image, dégressif jusqu’à l’image suivante. … ». « Fermer » la referme. Pendant qu’une image est ouverte, le bouton « Aide » n’apparaît pas.
  - Réf. : 90 § 7.7 · game-help.tsx · PlatformLimits::speedBonusMaxPercent

### 6.3 Gestes d’entraînement assisté (D18)

- [ ] **Voir les deux gestes sous la saisie** ⭐
  - Faire : Observer l’écran pendant le décompte, pendant une manche en cours sans avoir répondu, puis pendant la révélation.
  - Attendu : Pendant une manche en cours, deux boutons s’affichent côte à côte sur une ligne sous la saisie : « Voir la réponse » (icône œil) et « Passer la manche » (icône avance rapide). Ils sont absents pendant le décompte et pendant la révélation.
  - Réf. : 60 § 16.5 · components/game/solo-round-actions.tsx · game-stage.tsx
- [ ] **Voir la réponse** ⭐
  - Faire : Pendant la 1re image d’une manche, cliquer « Voir la réponse ».
  - Attendu : La manche se clôt tout de suite, puis la révélation normale se déroule (8 s par défaut en Classique). « Ont trouvé » indique « Personne n’a trouvé ». Aucun point n’est gagné : dans le classement de la révélation, la colonne « Cette manche » vaut +0 et le total ne bouge pas.
  - Réf. : 60 § 16.5 · SoloRoundController::reveal · RevealSoloAnswer
- [ ] **Passer la manche** ⭐
  - Faire : Pendant une manche en cours, cliquer « Passer la manche ».
  - Attendu : Aucune révélation ne s’affiche. Un court décompte « La manche commence dans 3 secondes » (préchargement plus marge, par défaut) précède la manche suivante, « Manche 2 sur 10 » par exemple.
  - Réf. : 60 § 16.5 · SoloRoundController::skip · SkipSoloRound
- [ ] **Écourter la révélation par « Manche suivante »** ⭐
  - Faire : Au début d’une révélation, cliquer « Manche suivante ». Recommencer sur la révélation de la dernière manche d’une partie.
  - Attendu : La révélation reste affichée environ 3 s (par défaut) : le compte « La manche commence dans … secondes » tombe à 3 s environ, puis la manche suivante démarre. Sur la dernière manche, le podium arrive au bout de ces 3 s environ. S’il reste déjà moins de 3 s de révélation, le clic ne change rien. Le bouton n’existe que pendant une révélation, jamais pendant une manche en cours.
  - Réf. : 60 § 5.4, § 16.5 · SoloRoundController::next · AdvanceToNextRound · next-round-button.tsx
- [ ] **Voir les gestes disparaître quand la saisie est close**
  - Faire : Dans une manche, trouver le film. Dans une autre, en Rapide ou en Classique à la 3e image, choisir une mauvaise proposition.
  - Attendu : Dans les deux cas, « Voir la réponse » et « Passer la manche » disparaissent dès que la saisie est close.
  - Réf. : 60 § 16.5 · game-stage.tsx (inputOpen)
- [ ] **Double-cliquer sur un geste** ⚠️
  - Faire : Double-cliquer rapidement sur « Passer la manche ».
  - Attendu : Le bouton cliqué affiche un indicateur. Les deux boutons restent inertes pendant l’envoi, sans perdre le focus. Une seule manche est passée, pas deux, et aucun message d’erreur ne s’affiche.
  - Réf. : use-solo-gestures.ts · solo-round-actions.tsx
- [ ] **Vérifier qu’un geste n’enregistre jamais de bonne réponse**
  - Faire : Jouer une partie jusqu’au podium en utilisant « Voir la réponse » sur certaines manches et en trouvant réellement d’autres.
  - Attendu : Au podium, « Bonnes réponses » ne compte que les manches réellement trouvées. Dans le « Récapitulatif des films », les manches révélées par le geste indiquent « Personne n’a trouvé ».
  - Réf. : 60 § 16.5 · 10 § 7.10 (aucun guess)
- [ ] **Passer toutes les manches jusqu’au podium** ⚠️
  - Faire : Démarrer Découverte (5 manches), puis cliquer « Passer la manche » à chaque manche.
  - Attendu : Après la 5e manche passée, le podium s’affiche directement avec « Partie terminée · 5 manches » (terminée, pas interrompue), « Aucune bonne réponse dans cette partie » et « 5 films que personne n’a trouvés ». Le récapitulatif liste les 5 titres, chacun avec « Personne n’a trouvé ».
  - Réf. : 60 § 14.5, § 16.5 · SkipSoloRound → FinalizeGame(Completed)

### 6.4 Les autres presets et difficultés de saisie

- [ ] **Jouer en Rapide (Facile)**
  - Faire : Relancer (ou démarrer) avec le preset Rapide.
  - Attendu : On voit « Manche 1 sur 8 », avec 2 images pour 15 s par manche. Il n’y a aucun champ texte : le bloc « Propositions » et ses 4 titres sont là dès la 1re image. La valeur passe de « 200 points en jeu » à « 100 points en jeu » (barème par défaut à 2 images). L’aide dit « jusqu’à 50 % ». La révélation dure 5 s.
  - Réf. : SettingPresetCatalog (fast) · round-input.tsx (easy)
- [ ] **Jouer en Hardcore (Expert)**
  - Faire : Relancer avec Hardcore, puis ouvrir « Aide » pendant le décompte.
  - Attendu : On voit « Manche 1 sur 10 », avec 5 images de 9 s (45 s) et des valeurs de 500 à 100 points (barème par défaut). La saisie est en texte libre seul, et aucune proposition n’apparaît jamais. L’aide dit « jusqu’à 25 % ». La révélation dure 10 s.
  - Réf. : SettingPresetCatalog (hardcore) · PlatformLimits::speedBonusMaxPercent
- [ ] **Épuiser les tentatives en Expert** ⚠️
  - Faire : En Hardcore, envoyer 23 réponses fausses (le nombre par défaut pour 45 s), environ une par seconde.
  - Attendu : Le message « Vous avez utilisé toutes vos tentatives pour cette manche. » s’affiche et les gestes disparaissent. La manche se clôt aussitôt, puis la révélation indique « Personne n’a trouvé ».
  - Réf. : 70 § 16 · RoomSettingsBounds (ceil(D / 2 s)) · CLAUDE.md § 2 (Expert : épuisement = saisie close)
- [ ] **Jouer en Découverte (Facile)**
  - Faire : Relancer avec Découverte.
  - Attendu : On voit « Manche 1 sur 5 », avec 3 images de 20 s (60 s) et des valeurs de 300, 200 et 100 points (barème par défaut). Les propositions sont là dès la 1re image, et la révélation dure 15 s.
  - Réf. : SettingPresetCatalog (discovery)

### 6.5 Fin de partie, podium et relance

- [ ] **Lire le podium solo** ⭐
  - Faire : Terminer une partie, en jouant ou à l’aide des gestes.
  - Attendu : Sous le titre de page « Jouer en solo », le focus se pose sur le titre « Podium », suivi de « Partie terminée · 10 manches ». Juste dessous vient la relance : « Choisissez un preset », les 4 tuiles (Classique cochée) et « Commencer l’entraînement ». Le classement a pour colonnes Rang, Joueur, Points, Bonnes réponses et Temps cumulé ; le rang vaut « — » (annoncé « Sans rang »). Suivent les faits marquants (« Meilleure réponse : … », « Trouvé le plus vite : … », ou « Aucune bonne réponse dans cette partie ») et le « Récapitulatif des films », manche par manche, avec « Trouvé par 1 joueur » ou « Personne n’a trouvé ». Plus bas : « En solo, aucune mémoire ne retient les films déjà vus : l’entraînement puise dans tout le catalogue, et un même film peut revenir. », puis le bouton « Aide ».
  - Réf. : 60 § 16.4, § 16.7 · 80 · podium.tsx · standings-table.tsx · podium-highlights.tsx
- [ ] **Relancer depuis le podium** ⭐
  - Faire : Sur le podium, cocher Rapide et cliquer « Commencer l’entraînement ».
  - Attendu : La nouvelle partie démarre sur la même page et l’URL reste `/solo`. On voit le décompte, puis « Manche 1 sur 8 ». Ni pseudo ni avatar ne sont redemandés.
  - Réf. : 60 § 16.4, § 16.6 · components/game/solo-relaunch.tsx
- [ ] **Recharger sur le podium et revenir sur /solo/new**
  - Faire : Sur le podium, faire F5, puis taper `/solo/new` dans la barre d’adresse.
  - Attendu : F5 réaffiche le podium de la dernière partie, relance comprise. `/solo/new` redirige vers `/solo` : un joueur qui a déjà un siège solo ne revoit jamais le formulaire d’entrée.
  - Réf. : 60 § 16.4 · SoloGameController::create/show
- [ ] **Relancer depuis un formulaire resté ouvert : une seule partie en cours** ⚠️
  - Faire : Dans une fenêtre privée, ouvrir `/solo/new` dans deux onglets A et B. Démarrer dans A avec le pseudo « Alpha », puis, pendant que A joue, envoyer le formulaire de B avec le pseudo « Beta ». Lancer ensuite `php artisan tinker --execute="dump(App\Models\Game::latest('id')->take(2)->get(['id','mode','status'])->toArray());"`.
  - Attendu : B arrive sur `/solo` avec une nouvelle partie à « Manche 1 sur 10 ». De retour sur A, on lit l’avis « Vous jouez désormais dans un autre onglet : … ». Tinker montre la dernière partie `solo` en `running` et la précédente en `interrupted`. Au podium, le pseudo reste « Alpha » : le siège est repris tel quel et le pseudo de B est ignoré.
  - Réf. : 60 § 16.2, § 16.6 · SoloStartRequest::requiresIdentity · StartSoloGame::interruptGamesOf
- [ ] **Retrouver l’avatar du solo en créant un salon** ⚠️
  - Faire : Dans une fenêtre privée neuve, démarrer un premier solo avec l’avatar Hibou, puis ouvrir `/r/new` dans la même fenêtre.
  - Attendu : Hibou est présélectionné dans « Choisissez un avatar » : le jeton a été re-signé avec l’avatar choisi.
  - Réf. : 60 § 16.2 étape 8 · C4 I4.5 · RoomController::create

### 6.6 Reprise, autre onglet, pause et présence

- [ ] **Recharger en pleine manche** ⭐
  - Faire : Pendant la 2e image d’une manche Classique, après deux réponses fausses, faire F5.
  - Attendu : On retrouve la même manche et la même image, avec le chrono à l’heure du serveur (il ne repart pas de 30 s) et le compteur « 13 tentatives restantes » (par défaut). Aucune nouvelle partie n’est créée et aucun avis de lecture seule ne s’affiche. Si l’on avait trouvé, « Trouvé ! » est conservé.
  - Réf. : 60 § 12, § 16.4 · SoloStateController · use-solo-state.ts
- [ ] **Reprendre la main depuis un second onglet**
  - Faire : Pendant une partie dans l’onglet A, ouvrir `/solo` dans un onglet B du même navigateur, puis revenir sur A. Recharger ensuite A.
  - Attendu : B affiche la partie et permet de jouer. De retour sur A, on lit « Vous jouez désormais dans un autre onglet : celui-ci n’affiche plus que la partie. Rechargez la page pour y reprendre la main. », et la saisie, les gestes et la relance y sont désactivés. Après le rechargement de A, c’est A qui a la main, et B passe à son tour en lecture seule dès qu’on y revient.
  - Réf. : 60 § 12.7 · ClaimSeatTab · ReadOnlyNotice · game.seat.superseded
- [ ] **Faire refuser un geste depuis un onglet supplanté** ⚠️
  - Faire : Placer côte à côte deux FENÊTRES (pas deux onglets) du même profil de navigateur, les deux visibles. En A, jouer une manche. Ouvrir `/solo` dans B, puis, sans toucher à B, cliquer aussitôt « Passer la manche » dans A, avant le changement d’image suivant.
  - Attendu : Sous les boutons de A s’affiche « Cet onglet n’a plus la main : vous jouez dans un autre onglet. Rechargez la page pour la reprendre ici. ». La manche n’est pas passée : B continue la même manche. A affiche ensuite l’avis « Vous jouez désormais dans un autre onglet : … » et ses gestes sont grisés.
  - Réf. : 60 § 12.7 · seat.active (409 seat_superseded) · use-solo-gestures.ts · game.errors.seat_superseded
- [ ] **Voir la pause, puis la reprise** ⚠️
  - Faire : Pendant le décompte d’une manche Classique, bloquer dans DevTools › Réseau les requêtes contenant `solo/heartbeat` (blocage d’URL de requête). Ne plus rien envoyer ni cliquer, car un geste vaut battement. Laisser courir la manche, sa révélation et, au besoin, la manche suivante, jusqu’à l’écran de pause (environ 40 s à 1 min 20). Débloquer alors l’URL et attendre 10 s. Changer d’onglet puis revenir (ou recharger la page).
  - Attendu : La manche va au bout des 30 s (par défaut) sans se clore plus tôt, puis la révélation se déroule. À la fin d’une révélation survenue plus de 25 s (par défaut) après le blocage, l’écran affiche « Partie en pause », « Plus aucun joueur n’était connecté : la partie reprend dès que l’un d’eux revient. » et « Sans retour d’un joueur, elle s’arrêtera à <heure>. », soit 15 min après la pause par défaut. Après le déblocage et le retour sur l’onglet, la partie reprend : « La manche commence dans 5 secondes », puis la manche suivante. Point de vigilance : si, sans changer d’onglet ni recharger, la page reste sur « Partie en pause » alors que la partie a repris (le client n’y relit l’état qu’à l’heure d’arrêt), noter l’écart.
  - Réf. : 60 § 13.3, § 14.1, § 14.2, § 16.6 · SoloHeartbeatController · RecordHeartbeat → ResumeGame · game-paused.tsx
- [ ] **Fermer l’onglet et revenir avant 15 minutes**
  - Faire : Fermer l’unique onglet `/solo` au milieu d’une manche, puis rouvrir `/solo` au bout de 1 à 2 minutes.
  - Attendu : La partie n’est pas terminée et aucun podium ne s’affiche. La manche quittée est allée au bout de son temps, et parfois une manche de plus, si le siège était encore compté présent à la fin de la révélation. Ces manches sont jouées sans vous. La partie reprend avec « La manche commence dans 5 secondes », puis la manche suivante. Si « Partie en pause » reste affiché plus de 10 s après la réouverture, changer d’onglet puis revenir. Si la reprise n’apparaît qu’alors, noter l’écart (reprise non relue par la page).
  - Réf. : 60 § 16.6 · EndReveal → PauseGame · ResumeGame
- [ ] **Fermer l’onglet et revenir après plus de 15 minutes** ⚠️
  - Faire : Fermer l’unique onglet `/solo` en cours de partie, attendre plus de 15 minutes (délai par défaut), puis rouvrir `/solo`.
  - Attendu : Le podium s’affiche avec « Partie interrompue à la manche k sur 10 », k étant le nombre de manches jouées, y compris celles courues sans vous. La relance est disponible.
  - Réf. : 60 § 14.3, § 16.6 · InterruptPausedGame · podium.tsx
- [ ] **Passer hors ligne en pleine manche**
  - Faire : Pendant une manche, passer DevTools › Réseau sur « Hors ligne » une dizaine de secondes (moins de 25 s), puis revenir sans limitation.
  - Attendu : Le bandeau « Vous êtes hors ligne. Le jeu ne s’interrompt pas pour autant : vérifiez votre connexion. » s’affiche. Les gestes sont grisés, et le chrono affiché continue. Au retour, le bandeau disparaît et l’écran se recale sur le serveur : l’image suivante, ou la révélation si le temps est écoulé.
  - Réf. : 90 § 7.6 · connection-banner.tsx · use-game-state.ts
- [ ] **Rendre /solo/state injoignable, navigateur en ligne** ⚠️
  - Faire : Pendant une partie, bloquer dans DevTools › Réseau les requêtes contenant `solo/state` jusqu’au changement d’image suivant, puis débloquer.
  - Attendu : Le bandeau « Vous êtes hors ligne. … » s’affiche bien que le navigateur soit en ligne, et les gestes sont grisés. Environ 10 s après le déblocage (par défaut), l’état est relu et le bandeau disparaît.
  - Réf. : 60 § 16.4 (option unreachable) · use-solo-state.ts
- [ ] **Supprimer le cookie pendant la partie** ⚠️
  - Faire : Sur `/solo`, supprimer le cookie `player_token` dans DevTools › Application › Cookies, puis attendre une dizaine de secondes sans rien toucher.
  - Attendu : Le battement ou la relecture reçoit un 403, et la page quitte d’elle-même la partie pour `/solo/new`, où le formulaire est vide (Ours présélectionné).
  - Réf. : 60 § 16.4 · SoloSeat · use-solo-state.ts (onExit)

### 6.7 Langue, thème, portrait et clavier

- [ ] **Thème sombre forcé en partie**
  - Faire : Sur `/solo/new`, choisir « Apparence » › « Clair », puis démarrer une partie. Essayer aussi « Sombre » et « Système » sur `/solo/new`.
  - Attendu : `/solo/new` suit l’apparence choisie (clair, sombre ou système). `/solo` est toujours sombre, même en « Clair ».
  - Réf. : 90 § 2.1 · GameLayout (useForcedAppearance) · PublicLayout
- [ ] **Page d’entrée en anglais**
  - Faire : Sur `/solo/new`, ouvrir « Langue » › English, puis envoyer un pseudo d’un seul caractère.
  - Attendu : On lit « Play solo », « Practise on your own on the catalogue: pick a preset, a nickname and an avatar. », « Choose a preset », les presets Classic, Fast, Hardcore et Discovery, l’aide « Between 2 and 20 characters. » et le bouton « Start training ». Le refus du pseudo s’affiche en anglais.
  - Réf. : 05 · lang/en/room.php (solo, presets) · SetLocale
- [ ] **Changer de langue en pleine partie**
  - Faire : Pendant une manche, cliquer l’icône de langue en bas à gauche, puis English.
  - Attendu : L’interface passe en anglais : « Round 1 of 10 », « Show the answer », « Skip this round », puis « Next round » à la révélation. La partie continue sans redémarrer : même manche, même chrono.
  - Réf. : 05 · GameLayout (LanguageSwitcher) · lang/en/game.php
- [ ] **Garder les propositions identiques après un changement de langue** ⚠️
  - Faire : En Rapide et en français, noter les 4 propositions affichées, puis passer en English pendant la même manche.
  - Attendu : L’interface est en anglais, mais les 4 propositions restent exactement les mêmes chaînes, rejouées sans être recomposées.
  - Réf. : Règle 3 · 70 (round_choice_set.rendered_locale)
- [ ] **Faire accepter un titre dans l’autre langue**
  - Faire : En interface française, répondre avec le titre anglais d’un film de démo, par exemple « Spirited Away ».
  - Attendu : La réponse est acceptée : « Trouvé ! ».
  - Réf. : CLAUDE.md § 2 · 70
- [ ] **Jouer une manche en portrait 375 px** ⭐
  - Faire : Passer DevTools en mode appareil 375 × 667 portrait, puis jouer une manche Classique, d’abord en FR, puis en EN.
  - Attendu : Pendant la manche, la page ne défile pas. On voit l’image en 16:9, le chrono, la valeur, le champ et, sur une seule ligne, « Voir la réponse » et « Passer la manche ». En EN, les libellés longs passent à la ligne dans leur bouton, sans déborder. Les cibles font au moins 44 px et il n’y a aucun défilement horizontal.
  - Réf. : Règle 10 · 90 § 10 (Solo) · solo-round-actions.tsx
- [ ] **Lire révélation et podium en portrait 375 px**
  - Faire : Toujours à 375 px, laisser venir une révélation, puis le podium.
  - Attendu : La révélation et le podium défilent à l’intérieur de leur zone, sans défilement horizontal. Les tuiles de la relance tiennent sur une seule colonne, et « Commencer l’entraînement » occupe toute la largeur.
  - Réf. : 90 § 2.3 · game-stage.tsx (ScrollArea) · solo-preset-field.tsx
- [ ] **Jouer au clavier**
  - Faire : Pendant une manche, n’utiliser que Tab et Entrée. Observer ensuite le focus à la révélation, puis au podium.
  - Attendu : L’ordre de tabulation est : champ, « Valider », « Voir la réponse », « Passer la manche ». Entrée envoie la réponse. À la révélation, le focus va sur « La réponse » ; au podium, il va sur « Podium ».
  - Réf. : 90 § 10 · round-reveal.tsx · podium.tsx

### 6.8 Drainage de déploiement

- [ ] **Bloquer le démarrage pendant un drainage**
  - Faire : Dans le second terminal, lancer `php artisan deploy:drain --timeout=10`. Dans une fenêtre privée, recharger `/solo/new`, remplir le formulaire et cliquer « Commencer l’entraînement ». Terminer avec `php artisan deploy:release` (depuis un autre terminal si `deploy:drain` attend encore), car Ctrl+C sur `deploy:drain` ne lève pas le drapeau.
  - Attendu : Le terminal affiche « Drainage commencé : aucune nouvelle partie ne peut plus être lancée. Attente de la fin des parties en cours. ». Sans autre partie en cours, il affiche ensuite « Fenêtre libre ouverte jusqu’à … » ; sinon « Parties encore en cours : <n>. … ». La page porte le bandeau « Une mise à jour du site est en préparation : aucune nouvelle partie ne peut être lancée pour le moment. Les parties en cours continuent normalement. ». L’envoi est refusé sous les presets par « Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. Réessayez un peu plus tard. », et le focus va sur la tuile cochée. Aucune partie n’est créée et aucun cookie `player_token` n’est posé. `deploy:release` répond « Drapeau de drainage levé : les lancements sont de nouveau permis. ».
  - Réf. : 60 § 16.2 étape 1, § 17.3 · SoloRefusal::Draining · DeployDrainCommand · DeployReleaseCommand
- [ ] **Voir une partie en cours continuer pendant le drainage**
  - Faire : Démarrer une partie Découverte, puis lancer `php artisan deploy:drain --timeout=30`. Continuer à jouer jusqu’au podium, en accélérant au besoin avec « Passer la manche ». Cliquer alors « Commencer l’entraînement ».
  - Attendu : La partie se déroule normalement jusqu’au podium : paliers, gestes, révélations. Le terminal affiche « Parties encore en cours : <n>. Relevé suivant dans quelques secondes. », où n compte aussi les parties en pause d’essais précédents. Il affiche ensuite « Fenêtre libre ouverte jusqu’à … » dans les 30 s qui suivent la fin de la dernière partie en cours (relevé toutes les 15 s). Si ce n’est pas le cas à l’échéance, le terminal affiche « Drainage abandonné : … » et lève le drapeau. Pendant le drainage, la relance est refusée sous les presets par « Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. … », puis le bandeau de maintenance apparaît et le bouton se grise. Terminer par `php artisan deploy:release`.
  - Réf. : 60 § 17.1, § 17.3 · DeployDrainCommand (poll_seconds 15) · solo-relaunch.tsx
- [ ] **Voir la relance se réactiver sans rechargement**
  - Faire : Pendant un drainage (`php artisan deploy:drain --timeout=10`), recharger `/solo` sur le podium. Lancer ensuite `php artisan deploy:release` et ne plus toucher la page.
  - Attendu : Tant que le drapeau tient, on voit le bandeau de maintenance, le bouton « Commencer l’entraînement » grisé et, dessous, « Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. Réessayez un peu plus tard. ». Dans les 10 s environ après `deploy:release` (par défaut), le bandeau disparaît et le bouton redevient actif, sans rechargement.
  - Réf. : 60 § 16.4 (BUG-P2) · use-maintenance-refresh.ts

### 6.9 Siège solo effacé après inactivité

- [ ] **Faire effacer un siège solo inactif depuis 24 h** ⚠️
  - Faire : Terminer jusqu’au podium une partie démarrée avec le pseudo « Testeur », puis fermer l’onglet `/solo` : sinon, le battement rafraîchit l’activité. Lancer `php artisan tinker --execute="App\Models\Player::whereNull('room_id')->where('nickname', 'Testeur')->latest('id')->first()->forceFill(['last_seen_at' => now()->subHours(25)])->save();"`, puis `php artisan purge:run --sync`. Rouvrir `/solo` dans le même navigateur.
  - Attendu : La commande affiche « Purge de rétention exécutée. Périmètres : … Lignes traitées : … ». `/solo` redirige vers `/solo/new`, où le pseudo est redemandé (champ vide). L’avatar choisi reste présélectionné, puisqu’il vient du jeton.
  - Réf. : 10 § 7.1 · 100 § 14 · OrphanPlayerHandler · RetentionWindows::SOLO_SEAT_IDLE_MINUTES
- [ ] **Voir la purge épargner un siège dont la partie n’est pas finie** ⚠️
  - Faire : Fermer l’onglet `/solo` au milieu d’une partie du pseudo « Testeur ». Lancer aussitôt la même commande tinker (activité reculée de 25 h), puis `php artisan purge:run --sync`. Consulter le journal de l’application dans `storage/logs/`, puis rouvrir `/solo`.
  - Attendu : Le siège n’est pas effacé. Le journal porte « Effacement d’un siège solo refusé : une de ses parties n’est pas figée. ». `/solo` réaffiche la partie, reprise ou en pause, et ne renvoie pas vers `/solo/new`.
  - Réf. : OrphanPlayerHandler::purge (refus défensif : partie non figée)

### 6.10 Robustesse et anti-triche

- [ ] **Faire tourner le solo sans worker de file ni Reverb** ⚠️
  - Faire : Arrêter `composer dev`. Lancer seulement `php artisan serve` et `npm run dev`, sans `queue:listen` ni `reverb:start`, puis jouer une partie Rapide jusqu’au podium. Relancer ensuite `composer dev`.
  - Attendu : La partie avance quand même : paliers, révélations, manches suivantes et podium sont rattrapés par les lectures de `/solo/state`. Quand l’écouteur de file repart, les jobs restés en file n’ont aucun effet.
  - Réf. : 60 § 4.4, § 16.1 · RespondsWithSoloState (CatchUpGame)
- [ ] **Vérifier que la réponse n’apparaît pas avant la révélation** ⚠️
  - Faire : En Hardcore (aucune proposition), pendant une manche, ouvrir DevTools › Réseau et garder la réponse d’une requête `solo/state`. À la révélation, chercher le titre révélé dans cette réponse, puis dans celle qui porte la révélation.
  - Attendu : Avant la révélation, la réponse ne contient ni le titre, ni les alias, ni aucun index de bonne réponse. Le titre n’apparaît que dans le paquet de la révélation.
  - Réf. : Règle 3 · 60 § 11.7, § 12
- [ ] **Vérifier qu’une URL d’image n’est pas réutilisable** ⚠️
  - Faire : Pendant une manche, copier depuis DevTools › Réseau l’URL d’une image `/f/…`. L’ouvrir dans un nouvel onglet du même navigateur pendant la manche, puis dans une fenêtre privée, puis de nouveau après la fin de la révélation.
  - Attendu : L’URL ne porte qu’un jeton et une signature (`/f/<32 hex>?expires=…&signature=…`), sans titre ni chemin de fichier. Elle sert l’image au même joueur jusqu’à la fin de la révélation. Dans la fenêtre privée, ou après la révélation, on obtient une 404 (ou une 403 si la signature a expiré), jamais l’image.
  - Réf. : 60 § 7.2, § 7.3, § 16.8 · ServeGuard · FrameServeController
- [ ] **Appeler /solo/state sans siège** ⚠️
  - Faire : Dans une fenêtre privée neuve, taper `/solo/state` dans la barre d’adresse.
  - Attendu : Réponse 403. Avec `APP_DEBUG=true` (valeur locale), c’est la page d’erreur 403 du framework. Avec `APP_DEBUG=false`, c’est la page « Accès refusé » (« Vous n’avez pas l’autorisation d’ouvrir cette page. »).
  - Réf. : 60 § 16.4 · SoloStateController · ErrorPageResponder (hors debug)
- [ ] **Constater qu’un siège de salon et le siège solo sont indépendants** ⚠️
  - Faire : Dans un même navigateur, créer ou rejoindre un salon `/r/<code>`, puis ouvrir `/solo/new`, démarrer un solo et revenir sur `/r/<code>` dans la foulée.
  - Attendu : `/solo/new` redemande un pseudo, puisque le siège solo est distinct du siège de salon. Le solo se joue normalement. De retour sur `/r/<code>`, on est toujours dans le salon avec son propre pseudo, et aucun geste solo n’y apparaît.
  - Réf. : 10 § 7.10 barrière 1 · SoloSeat · seat.active

### 6.11 Catalogue insuffisant (à jouer en dernier)

- [ ] **Voir le nombre d’images ramené d’office (D19)** ⚠️
  - Faire : En curateur, sur `/admin/catalog/<id>/bank` de 7 films différents, cliquer « Dépublier » sur la carte de l’image de niveau 2, puis « Dépublier l’image » dans la boîte « Dépublier l’image ». Ensuite, dans une fenêtre privée, ouvrir `/solo/new` (un navigateur qui a déjà un siège solo est renvoyé vers `/solo` : y recharger le podium). Cocher Hardcore et cliquer « Commencer l’entraînement ».
  - Attendu : Chaque dépublication affiche « Image dépubliée : elle sort du jeu, et une revue pourra l’y remettre. ». La tuile Hardcore porte l’icône d’alerte et « Pas assez de films pour ce preset : jouable avec 4 images par manche. », mais reste cochable. La partie démarre à 4 images par manche avec l’encadré « Le catalogue ne permet pas encore le preset Hardcore à 5 images par manche : cette partie se joue à 4 images par manche. ». L’aide dit « jusqu’à 33 % ».
  - Réf. : D19 du 23/09 · 60 § 16.3 · SoloPresets::resolve · game.solo.frames_adjusted
- [ ] **Voir où s’affiche l’annonce D19** ⚠️
  - Faire : Dans la partie Hardcore ajustée, observer l’encadré pendant le décompte, pendant une image ouverte, puis pendant la révélation. Recharger ensuite `/solo`.
  - Attendu : L’encadré est visible pendant le décompte et la révélation, et absent tant qu’une image est ouverte : la scène ne défile jamais. Après le rechargement, il ne revient plus, car c’est une annonce lue une seule fois.
  - Réf. : 60 § 16.4 · pages/game/solo.tsx (liveScene) · SoloGameController::SETTINGS_NOTICE
- [ ] **Faire refuser un preset injouable** ⚠️
  - Faire : En curateur, sur `/admin/catalog/<id>` de 7 films, cliquer « Dépublier le film », saisir un motif dans « Motif de la dépublication », puis cliquer « Dépublier le film ». Dans une fenêtre privée, ouvrir `/solo/new`, cocher Classique et envoyer.
  - Attendu : Chaque dépublication affiche « Film dépublié : il sort du vivier des parties lancées désormais. ». Classique et Hardcore portent « Pas assez de films pour ce preset. », et Rapide est présélectionné, comme premier preset jouable. L’envoi sur Classique est refusé sous les presets par « Le catalogue ne compte pas encore assez de films pour ce preset, même avec moins d’images par manche. Choisissez-en un autre. », avec le focus sur la tuile Classique. Aucun cookie `player_token` n’est créé. Rapide et Découverte démarrent normalement.
  - Réf. : 60 § 16.2 étape 3, § 16.3 · SoloRefusal::PoolTooSmall · solo-preset-field.tsx (initialPreset)
- [ ] **Voir le même refus à la relance** ⚠️
  - Faire : Dans un navigateur qui a un podium solo, recharger `/solo`, cocher Classique et cliquer « Commencer l’entraînement ».
  - Attendu : Le même message s’affiche sous les presets, et le focus va sur la tuile cochée. Le podium de la partie précédente reste affiché et aucune partie n’est créée.
  - Réf. : 60 § 16.2 · solo-relaunch.tsx
- [ ] **Refus traduit en anglais** ⚠️
  - Faire : Dans la même situation, passer en English et renvoyer Classique.
  - Attendu : Le message est « The catalogue does not have enough films for this preset yet, even with fewer frames per round. Choose another one. ».
  - Réf. : Règle 4 · lang/en/game.php (errors.pool_too_small)
- [ ] **Catalogue vide : aucun preset jouable** ⚠️
  - Faire : Lancer `php artisan backup:snapshot` (code 0 exigé), puis `php artisan migrate:fresh` et `php artisan db:seed --class=PlatformDataSeeder` (ou dépublier au moins 12 films). Ouvrir `/solo/new` et envoyer le preset proposé. Pour revenir au catalogue complet : `php artisan backup:snapshot`, puis `php artisan migrate:fresh --seed`.
  - Attendu : Les 4 tuiles portent « Pas assez de films pour ce preset. », et Classique est présélectionné. L’envoi est refusé par « Le catalogue ne compte pas encore assez de films pour ce preset, même avec moins d’images par manche. Choisissez-en un autre. ». Il n’y a ni erreur 500 ni partie créée.
  - Réf. : 60 § 16.3 · SoloPresets::options · DatabaseSeeder

### 6.12 Hors périmètre (non livré, ne pas essayer)

- Formulaire de réglages en solo (durée, nombre d’images, thèmes, barème) : par conception, le solo ne propose que les quatre presets du site (D19 du 23/09).
- Choix de thèmes en solo : le sélecteur de thèmes est masqué au J1, et les presets jouent sur tout le catalogue.
- Mémoire des films déjà vus et non-répétition en solo : volontairement absentes (60 § 16.7). Un film qui revient n’est pas un bug.
- Bouton « Quitter » ou « Abandonner » en solo : il n’existe pas. Quitter, c’est fermer l’onglet : pause, puis interruption à 15 min par défaut. La relance n’est proposée que sur le podium.
- Historique des parties solo et 4 compteurs de profil (rang « — » dans l’historique) : ils dépendent des comptes du J2 (40 J2).
- Rattachement du siège solo à un compte connecté : aucun au J1 ; le compte ne débloque rien en solo avant le J2.
- Suppression des lignes `player` et des participations d’un siège solo effacé (branche « dépendante » du périmètre orphan_player) : J2, lot L100-17. Au J1, seuls le pseudo, sa forme repliée et les hash du jeton sont effacés.
- Annulation active d’une manche sur suspension ou retrait juridique d’un film : J2 (60 § 15.4).
- Plafond global de parties simultanées, solo compris : aucun au J1 ; éventuellement au J2, après le test de charge.
- Temps réel (Reverb, canaux) en solo : aucun par conception ; le solo vit de lectures de `/solo/state`.
- Classements globaux et partage d’un score solo : hors v1.
- À ne pas chercher à provoquer à la main : les refus 409 « Ce geste n’est possible que pendant une manche en cours, avant d’avoir répondu. » et « La manche suivante ne peut être avancée que pendant la révélation. », l’échec technique « Le lancement a échoué. Réessayez dans un instant. » et le limiteur 429 du démarrage. Ils sont livrés et couverts par les tests automatisés, mais on ne les obtient que par une course à la milliseconde ou une panne provoquée de la base.

## 7. Back-office : accès, import TMDB et catalogue

Cette section couvre la porte `/admin` (rôles, double authentification obligatoire, pages d’erreur), la coquille du back-office (navigation, thème, écran de 375 px, clavier), le tableau de bord, la page « Premiers pas », l’import TMDB (balayage, collage, aperçu à blanc, recherche, liste d’amorçage, reprise), le catalogue, la fiche film (titres, alias, même œuvre, publication, dépublication, écart, contenu vérifié), la file de curation et le débit. Elle compare aussi le curateur et l’administrateur sur ces écrans. La banque d’images, le recadreur, la revue, l’annuaire et les accès relèvent d’autres sections.

**Prérequis**

- Base fraîche : `php artisan backup:snapshot` (code 0 exigé, règle 12 ; s’il échoue, on s’arrête), puis `php artisan migrate:fresh --seed`. On obtient trois comptes de démo au mot de passe `password` : player@tripleframes.test (« Joueur Démo »), curator@tripleframes.test (« Curateur Démo ») et admin@tripleframes.test (« Admin Démo », compte en langue EN). S’y ajoutent 16 films de démonstration publiés, sans identifiant TMDB. Les mêmes deux commandes remettent la base à cet état après les essais.
- `composer dev` lancé, site ouvert sur http://127.0.0.1:8000. Mise en route du § 0 faite. La file `default` exécute les imports et les aperçus ; sans elle, un balayage reste « En file ».
- `TMDB_API_KEY` ou `TMDB_API_READ_ACCESS_TOKEN` renseigné dans `.env`, faute de quoi l’import est désactivé. Après toute modification de `.env` : `php artisan config:clear`, puis relancer `composer dev`.
- Une application TOTP sur téléphone. Aucun compte de démo n’a de double authentification confirmée : la porte est fermée au premier accès de chaque compte privilégié, et l’enrôlement est à refaire après chaque `migrate:fresh --seed`.
- Pour voir les pages d’erreur traduites (403, 404, 419, 429), passer `APP_DEBUG=false`, puis `php artisan config:clear`. Sinon, c’est la page de débogage de Laravel qui s’affiche. Remettre `true` ensuite.
- Certains cas limites posent un état par `php artisan tinker --execute="…"` : ces commandes écrivent dans `movie`. Prendre d’abord un instantané (`php artisan backup:snapshot`, règle 12), puis remettre la base à neuf par `migrate:fresh --seed` en fin de recette. Écrire `App\Models\Movie` avec une seule barre oblique inverse : la syntaxe marche dans PowerShell comme dans Git Bash.
- Deux navigateurs, ou une fenêtre privée : l’un pour le curateur, l’autre pour l’admin ou le joueur. Deux onglets d’un même navigateur suffisent pour les scénarios « deux onglets ».
- Outils de développement du navigateur : mode appareil à 375 × 667, onglet Réseau (mode « Hors ligne », en-têtes de réponse), onglet Application (cookies).

### 7.1 Porte /admin et double authentification

- [ ] **Invité renvoyé vers la connexion** ⭐
  - Faire : Dans une fenêtre privée non connectée, ouvrir http://127.0.0.1:8000/admin, puis /admin/catalog/1.
  - Attendu : Redirection vers /login, écran « Connexion à votre compte ». Aucun contenu du back-office n’apparaît.
  - Réf. : 20 § 2.3 · routes/admin.php (auth) · EnsureUserHasRole
- [ ] **Joueur refusé à la porte** ⭐
  - Faire : Avec APP_DEBUG=false, se connecter avec player@tripleframes.test / password. Ouvrir /admin, /admin/two-factor, /admin/catalog/1, puis /admin/catalog/999999.
  - Attendu : Les quatre URL donnent un 403 sur la page d’erreur joueur (coquille publique, sans barre latérale) : « Accès refusé » / « Vous n’avez pas l’autorisation d’ouvrir cette page. », avec le bouton « Retour à l’accueil ». L’identifiant inconnu donne aussi 403, jamais 404, ce qui empêche d’énumérer les films. Il n’y a jamais de redirection vers l’écran d’enrôlement.
  - Réf. : 20 § 2.3 · EnsureUserHasRole · bootstrap/app.php (priorité avant SubstituteBindings)
- [ ] **Refus du joueur dans sa langue** ⚠️
  - Faire : Toujours en joueur, avec APP_DEBUG=false, passer la langue du site en English (sélecteur « Langue »), puis rouvrir /admin.
  - Attendu : La page affiche « Access denied » / « You are not allowed to open this page. ». Le refus de rôle suit la langue du visiteur, pas le français du back-office.
  - Réf. : 90 § 4.8 · ErrorPageResponder
- [ ] **Curateur sans 2FA renvoyé vers l’enrôlement** ⭐
  - Faire : Se connecter avec curator@tripleframes.test / password, puis ouvrir /admin.
  - Attendu : Redirection vers /admin/two-factor, dans la coquille du back-office et en français. La page s’intitule « Activez la double authentification pour entrer ». On y voit l’encart « Pourquoi cette porte » et la carte « Marche à suivre » (5 étapes numérotées), puis les boutons « Ouvrir la sécurité du compte » et « J’ai confirmé : entrer dans le back-office ».
  - Réf. : 20 § 2.4 · EnsurePrivilegedTwoFactor · two-factor-required.tsx
- [ ] **Toute URL admin mène à l’enrôlement tant que la 2FA manque** ⚠️
  - Faire : Avec ce curateur sans 2FA, ouvrir /admin/catalog, /admin/guide, /admin/import, puis /admin/catalog/999999. Revenir ensuite sur /admin/two-factor et cliquer « J’ai confirmé : entrer dans le back-office » sans avoir activé la 2FA.
  - Attendu : Chaque URL revient sur /admin/two-factor, y compris l’identifiant inconnu, qui ne donne jamais de 404. Le bouton ramène lui aussi à cette page.
  - Réf. : 20 § 2.3, § 2.4 · bootstrap/app.php (admin.2fa avant SubstituteBindings)
- [ ] **Activer la 2FA et entrer** ⭐
  - Faire : Cliquer « Ouvrir la sécurité du compte » (/settings/security) et confirmer le mot de passe sur l’écran « Confirmer le mot de passe ». Cliquer « Activer la 2FA », scanner le QR code, cliquer « Continuer », saisir le code à 6 chiffres, puis « Continuer ». Ranger les codes de récupération, puis revenir sur /admin/two-factor.
  - Attendu : La page affiche maintenant « Double authentification confirmée » et un seul bouton, « Entrer dans le back-office », qui mène au tableau de bord /admin.
  - Réf. : 20 § 2.4 · security.edit (RequirePassword, Fortify confirm)
- [ ] **Compte admin anglophone : back-office en français**
  - Faire : Se connecter avec admin@tripleframes.test (compte en EN), activer la 2FA comme ci-dessus, puis ouvrir /admin.
  - Attendu : La page de sécurité du compte est en anglais (« Enable 2FA »). /admin/two-factor puis tout /admin sont en français, avec `<html lang="fr">`.
  - Réf. : 20 § 13.2 · ForceAdminLocale
- [ ] **Reconnexion avec défi 2FA**
  - Faire : Se déconnecter du compte curateur. Ouvrir /admin, ce qui redirige vers /login, puis se connecter avec le curateur.
  - Attendu : L’écran « Authentification à deux facteurs » demande le code. Après la saisie du code puis « Continuer », /admin s’ouvre directement : c’était l’URL visée.
  - Réf. : 20 § 2.4 · Fortify (two-factor challenge)
- [ ] **Back-office non indexable** ⚠️
  - Faire : Dans l’onglet Réseau, inspecter les en-têtes de la réponse de /admin, puis ceux d’une page d’erreur. Ouvrir aussi /robots.txt.
  - Attendu : Chaque réponse porte l’en-tête `X-Robots-Tag: noindex, nofollow`. robots.txt contient `Disallow: /admin`.
  - Réf. : 90 § 5.2 · RobotsDirectives · public/robots.txt

### 7.2 Coquille du back-office : navigation, thème, 375 px, clavier

- [ ] **Barre latérale sur ordinateur** ⭐
  - Faire : Connecté en curateur (2FA active), ouvrir /admin en fenêtre large, puis la fiche d’un film.
  - Attendu : En haut : la marque « TripleFrames » / « Back-office ». Le groupe « Curation » liste, dans l’ordre : Tableau de bord, File de curation, Catalogue, Revue, Import, Débit, Premiers pas. En bas : un avatar à initiales, le pseudo « Curateur Démo », le badge « Curateur » et le lien « Retour au site » (vers /dashboard). L’entrée active est surlignée : sur une fiche film, c’est « Catalogue », jamais « Tableau de bord ».
  - Réf. : 20 § 13.4 · admin-sidebar.tsx · admin-nav.tsx · admin-user-panel.tsx
- [ ] **En-tête : fil d’Ariane et repli de la barre**
  - Faire : Ouvrir /admin/catalog et faire défiler. Cliquer le bouton de l’en-tête (nom accessible « Navigation du back-office ») ou taper Ctrl+B, puis recharger la page.
  - Attendu : Le fil d’Ariane affiche « Tableau de bord › Catalogue » et l’en-tête reste collé en haut. La barre se replie en icônes, avec une infobulle au survol, et reste repliée après le rechargement (cookie `sidebar_state`).
  - Réf. : admin-header.tsx · ui/sidebar · HandleInertiaRequests (sidebarOpen)
- [ ] **Pied de page légal et attribution TMDB**
  - Faire : Descendre en bas de n’importe quel écran admin, puis cliquer chacun des trois liens.
  - Attendu : On trouve les liens « Mentions légales », « Conditions générales d’utilisation » et « Politique de confidentialité », qui ouvrent les pages légales du site, puis le texte « Ce produit utilise l’API de TMDB mais n’est ni approuvé ni certifié par TMDB. ». Tant que public/brand/tmdb.svg n’est pas déposé (geste 10), il n’y a ni logo ni icône d’image cassée.
  - Réf. : 20 § 13.2 · admin-footer.tsx
- [ ] **Thème non forcé (D8)** ⭐
  - Faire : Sur /settings/appearance, choisir « Sombre », puis rouvrir /admin, /admin/catalog et une fiche film. Refaire le test avec « Clair », puis avec « Système » en changeant le thème de l’OS.
  - Attendu : Tout le back-office suit le choix. Fond, cartes, tableaux, badges et boîtes de dialogue restent lisibles dans les deux thèmes, sans couleur figée. Aucun écran de cette section n’est forcé en clair ou en sombre.
  - Réf. : 20 § 13.3 · 90 § 2.2 · admin-layout.tsx
- [ ] **Menu mobile à 375 px (geste 11 a)** ⭐
  - Faire : En mode appareil 375 × 667, sur /admin, toucher le bouton de l’en-tête.
  - Attendu : La barre latérale est masquée. Le bouton ouvre depuis la gauche une feuille (titre accessible « Menu du back-office ») qui contient la marque, les entrées, le panneau du compte et un bouton « Fermer » en bas. Aucun « Close » ni « Sidebar » en anglais n’est lu.
  - Réf. : 20 § 13.4 · admin-sidebar.tsx
- [ ] **Fermeture de la feuille mobile**
  - Faire : À 375 px, ouvrir la feuille puis toucher « Catalogue ». La rouvrir et appuyer sur Échap. La rouvrir encore et toucher « Fermer ».
  - Attendu : Un lien suivi ferme la feuille et change de page. Échap et « Fermer » ferment la feuille, et le focus revient au bouton de l’en-tête.
  - Réf. : 20 § 13.4 · admin-sidebar.tsx
- [ ] **Aucun défilement horizontal de page à 375 px (geste 11 a)** ⭐
  - Faire : À 375 px, parcourir /admin/two-factor (compte sans 2FA), /admin, /admin/catalog, une fiche film (tous les onglets), /admin/import (collage rempli, puis une recherche), /admin/import/run/{id}, /admin/curation, /admin/throughput et /admin/guide.
  - Attendu : La page ne défile jamais horizontalement. Les tableaux larges et la liste des 8 onglets de la fiche défilent dans leur propre bloc. Les boutons des gestes (Film suivant, gestes de la fiche, boîtes de dialogue) restent assez hauts pour être touchés au doigt.
  - Réf. : 20 § 13.4 · règle 10
- [ ] **Parcours clavier et lien d’évitement (geste 11 a)** ⭐
  - Faire : Sur /admin, cliquer dans la barre d’adresse puis appuyer sur Tab. Appuyer sur Entrée sur ce premier élément, puis continuer à tabuler dans toute la page, à 1280 px puis à 375 px.
  - Attendu : Le premier Tab fait apparaître le lien « Contenu principal », et Entrée place le focus dans le contenu. Chaque lien et chaque bouton a un anneau de focus visible. Tout est atteignable et activable au clavier, feuille mobile comprise.
  - Réf. : 20 § 13.4 · admin-layout.tsx
- [ ] **Boîtes de dialogue au clavier**
  - Faire : Sur une fiche film publiée, atteindre « Dépublier le film » au clavier et appuyer sur Entrée. Tabuler dans la boîte, puis appuyer sur Échap.
  - Attendu : Le focus reste piégé dans la boîte. On y trouve le bouton croix (nom accessible « Fermer ») et « Annuler ». Échap ferme la boîte et le focus revient sur « Dépublier le film ».
  - Réf. : 20 § 13.4 · reason-dialog.tsx

### 7.3 Tableau de bord

- [ ] **Tableau de bord après le seed** ⭐
  - Faire : Ouvrir /admin juste après `migrate:fresh --seed`.
  - Attendu : Titre « Tableau de bord de curation », avec le bouton « Film suivant ». Les tuiles à 0 sont bien affichées. Blocs attendus :
    - « Disponibilité » : Brouillon 0, Publié 16, Dépublié 0, Suspendu 0, Retiré 0, « Total au catalogue » 16.
    - « Curation des films » : Prêts à publier 0, Publiés incomplets 0, Écartés 0.
    - « Images à traiter » : À revoir 0, À re-revoir 0, Rejetées 1 (l’image rejetée du catalogue de démo), Traitement en échec 0.
    - « Drapeau de contenu » : Contenu vérifié 16, Classification à vérifier 0, Bloqué 0.
    - « Entrées par exception » : Entrés par exception 3, Langue originale hors filtre 2, Sous le seuil de notoriété 0, Sortie avant l’année minimale 1.
    - « Vivier catalogue, par nombre d’images » : N = 2 à N = 5, 16 œuvres chacun, avec l’avis « Vivier catalogue : ni thème, ni non-répétition. C’est un plafond… ».
    - « Couverture d’images » : Films couvrant 1, 3 et 5 : 16 ; Films sans aucune image : 0.
    Messages des blocs vides : « Aucun brouillon en attente de curation. » (avec « Films non publiables tant que le contenu n’est pas vérifié : 0 »), « Aucun film n’a été écarté. » et « Aucun balayage n’a encore été lancé. ».
  - Réf. : 20 § 8.6 · DashboardController · dashboard.tsx
- [ ] **Liens des tuiles**
  - Faire : Cliquer « Voir la liste : Prêts à publier », « Voir la liste : Publiés incomplets », « Voir la liste : Écartés », « Ouvrir la file de revue », « Ouvrir la file de curation », « Voir tous les films écartés » et « Voir l’écran d’import ».
  - Attendu : Chaque lien mène à la liste annoncée :
    - les trois « Voir la liste » : /admin/catalog?curation_status=ready_to_publish, puis =incomplete et =set_aside ;
    - « Voir tous les films écartés » : /admin/catalog?curation_status=set_aside ;
    - « Ouvrir la file de revue » : /admin/review ;
    - « Ouvrir la file de curation » : /admin/curation ;
    - « Voir l’écran d’import » : /admin/import.
  - Réf. : 20 § 8.6 · dashboard.tsx
- [ ] **« Film suivant » avec une file vide** ⭐
  - Faire : Juste après le seed, quand la file est vide, cliquer « Film suivant » en haut du tableau de bord.
  - Attendu : Arrivée sur /admin/curation avec le toast « Aucun autre film n’attend dans la file de curation. Importez de nouveaux films pour la remplir. ».
  - Réf. : 20 § 4.1 · CurationQueueController@next
- [ ] **Compteurs après import et gestes**
  - Faire : Après un import et l’écart d’un film (voir plus bas), revenir sur /admin.
  - Attendu : « Brouillon » augmente. « Tête de la file de curation » liste au plus 10 brouillons importés. « Films écartés » montre le film avec sa colonne « Motif » et la date « Écarté le ». « Derniers balayages » montre au plus 5 balayages, avec leur état et leurs 4 compteurs.
  - Réf. : 20 § 8.6 · DashboardController
- [ ] **Vivier compté en œuvres**
  - Faire : Regrouper deux films publiés en « même œuvre » (groupe « Fiche film : même œuvre »), ou dépublier un film publié, puis revenir sur /admin.
  - Attendu : Chaque tuile N = 2 à N = 5 baisse de 1 : deux films regroupés comptent pour une seule œuvre.
  - Réf. : 30 § 1.2 · PoolReporter
- [ ] **Sans clé TMDB** ⚠️
  - Faire : Vider TMDB_API_KEY et TMDB_API_READ_ACCESS_TOKEN, lancer `php artisan config:clear`, relancer `composer dev`, puis ouvrir /admin. Restaurer les clés après l’essai.
  - Attendu : Un encart s’affiche. Son titre : « Aucune clé TMDB n’est configurée sur le serveur : les deux formulaires sont désactivés. L’administrateur du site doit la renseigner. ». Son texte : « Aucune clé TMDB n’est configurée : l’import est indisponible. Le jeu, lui, n’appelle jamais TMDB. ». Le reste du tableau de bord fonctionne.
  - Réf. : 20 § 13.1 · TmdbClient::isConfigured

### 7.4 Premiers pas du curateur

- [ ] **Page guide et sommaire** ⭐
  - Faire : Cliquer « Premiers pas » dans la barre latérale (/admin/guide), puis chaque entrée du « Sommaire ».
  - Attendu : Titre « Premiers pas du curateur ». Le sommaire mène aux 9 sections : Les deux passes ; L’échelle de 1 à 5 ; Le plancher de recadrage, et pourquoi ; La grille d’exclusion, version 1 ; La revue et la source déclarée ; La publication et l’avertissement d’ambiguïté ; Écarter un film ou une image ; Raccourcis de débit ; Ce qu’il ne faut jamais faire.
  - Réf. : 20 § 13.6 · GuideController · guide.tsx
- [ ] **Valeurs du plancher lues dans la configuration**
  - Faire : Lire la section « Le plancher de recadrage, et pourquoi ».
  - Attendu : On lit que le cadre « ne reprend jamais plus de 80 % de la largeur ni de la hauteur du visuel : il en couvre au plus 64 % de la surface ». Il « ne descend pas non plus sous 640 pixels de large ». Ces valeurs viennent de PlatformLimits, pas d’un texte figé.
  - Réf. : 20 § 5.2, § 13.6 · PlatformLimits
- [ ] **Liens d’action du guide**
  - Faire : Cliquer « Ouvrir la file de curation » en haut de la page, puis « Ouvrir la passe de revue » dans la section sur la revue.
  - Attendu : Le premier lien mène à /admin/curation, le second à /admin/review.
  - Réf. : guide.tsx
- [ ] **Déconnexion réseau sur le guide** ⚠️
  - Faire : Sur /admin/guide, passer l’onglet Réseau en « Hors ligne ». Cliquer « Ouvrir la file de curation » en haut de la page, deux fois de suite.
  - Attendu : Un toast s’affiche une seule fois : « Connexion perdue : l’écran demandé n’a pas pu s’ouvrir. Vérifiez votre réseau, puis réessayez. ». La page reste sur le guide.
  - Réf. : 20 § 13.6 · guide.tsx

### 7.5 Import TMDB : écran et balayage discover

- [ ] **Écran d’import et asymétrie des deux voies** ⭐
  - Faire : Ouvrir /admin/import (entrée « Import »).
  - Attendu : Titre « Import du catalogue ». La carte « Ce que chaque voie fait du filtre » vient avant les formulaires : le balayage APPLIQUE le filtre de notoriété, le collage l’IGNORE, et les filtres de CONTENU ne sont contournables par aucune des deux voies. Suivent, dans l’ordre :
    - l’avis « Les deux voies sont DIFFÉRÉES… » ;
    - les cartes « Balayage discover » et « Collage d’identifiants » ;
    - « Rechercher un film sur TMDB » ;
    - « Liste d’amorçage » ;
    - « Journal des balayages », avec « Aucun balayage enregistré. ».
  - Réf. : 20 § 3.1, § 3.2 · import/index.tsx
- [ ] **Valeurs par défaut du balayage** ⭐
  - Faire : Lire le formulaire « Balayage discover » sans rien toucher.
  - Attendu : Aucun avertissement d’élargissement ne s’affiche. Valeurs attendues :
    - « Seuil de votes » : 500, avec l’aide « Défaut du site : 500. Zéro est un filtre légitime… » ;
    - « Langues originales » : Français, Anglais et ja cochés ; ko, it, es, de, zh, ru et sv décochés ;
    - « Année minimale » : 1970, avec l’aide « Défaut du site : 1970. Descendre sous cette année élargit le filtre. » ;
    - « Pages TMDB » : 1, avec l’aide « Entre 1 et 5 par envoi. Une page rend une vingtaine de fiches. ».
  - Réf. : config/catalog.php import_filter · ImportController::defaults
- [ ] **Lancer un balayage par défaut** ⭐
  - Faire : Cliquer « Lancer le balayage » avec 1 page.
  - Attendu : Redirection vers /admin/import/run/{id}, avec le toast « Balayage ouvert : il est confié au traitement d’arrière-plan. Son détail suit son avancement. ». L’état passe de « En file » à « En cours ». Tant qu’il est « En file », le détail affiche déjà l’encart « Ce balayage attend depuis plus d’une minute… » : sur cet écran, il n’y a pas de délai de grâce. La ligne « Balayage en cours : cet écran se rafraîchit tout seul. » s’affiche et les compteurs montent sans recharger. Les films entrés portent le badge de voie « Balayage », sans badge « Exception ».
  - Réf. : 20 § 3.2 · ImportDiscoverController · RunCatalogImport · import/show.tsx
- [ ] **Balayage élargi et tracé**
  - Faire : Attendre 5 minutes après le balayage précédent. Passer « Seuil de votes » à 100, ou cocher « ko », ou mettre « Année minimale » à 1960, puis lancer.
  - Attendu : Avant l’envoi, l’avertissement « Ce balayage ÉLARGIT le filtre par défaut… » s’affiche. Le détail indique « Ce filtre est plus large que le défaut du site : le balayage est marqué élargi. », et le journal montre le badge « Filtre élargi ». Chaque film entré hors du filtre par défaut porte « Exception » et son motif : « Sous le seuil de notoriété », « Langue originale hors filtre » ou « Sortie avant l’année minimale ».
  - Réf. : 20 § 3.2 · ImportDiscoverRequest · ImportFilter::isWiderThanDefault · décision 11
- [ ] **Année minimale dans le futur refusée** ⚠️
  - Faire : Saisir 2030 dans « Année minimale », puis cliquer « Lancer le balayage ».
  - Attendu : Sous le champ : « Le champ année minimale ne doit pas être supérieur à 2026. ». Rien n’est lancé et le journal ne change pas.
  - Réf. : CatalogImportValidationRules::minYearRules
- [ ] **Pages TMDB hors bornes** ⚠️
  - Faire : Saisir 6 dans « Pages TMDB », puis cliquer « Lancer le balayage ».
  - Attendu : Le navigateur bloque l’envoi, la valeur maximale étant 5. Aucun balayage n’est ouvert.
  - Réf. : ImportDiscoverRequest (pagesRules, catalog.import.pages_max)
- [ ] **Aucune langue cochée** ⚠️
  - Faire : Décocher toutes les langues, puis lancer le balayage.
  - Attendu : Le balayage part avec les langues par défaut, visibles dans « Filtre appliqué » du détail (fr, en, ja). Il n’y a ni erreur ni marque d’élargissement.
  - Réf. : ImportDiscoverRequest::prepareForValidation
- [ ] **Second balayage pendant qu’un premier est ouvert** ⚠️
  - Faire : Moins de 5 minutes après un lancement, revenir sur /admin/import et relancer un balayage.
  - Attendu : Le toast « Un balayage de cette nature est déjà ouvert. Attendez qu’il finisse, ou reprenez-le depuis son détail. » s’affiche. Aucune ligne n’est ajoutée au journal.
  - Réf. : 20 § 3.4 · ImportLauncher::openExclusively (BUSY_GRACE_MINUTES)

### 7.6 Import TMDB : collage, aperçu à blanc, recherche, liste d’amorçage

- [ ] **Aperçu à blanc d’un collage** ⭐
  - Faire : Dans « Identifiants ou URL TMDB », coller ces quatre lignes : `550`, `https://www.themoviedb.org/movie/496243`, `# essai` et `550`. Cliquer « Prévisualiser ».
  - Attendu : Le toast « Aperçu lancé : le sort de chaque identifiant s’affiche ci-dessous dès qu’il est lu. Rien n’est importé. » s’affiche. Le panneau « Aperçu à blanc » montre des lignes grisées de chargement (texte lu seulement par les lecteurs d’écran), et son compteur passe de « 0 identifiants lus sur 2 » à « 2 identifiants lus sur 2 » : le doublon est retiré, le commentaire ignoré. Les deux lignes ont le sort « Serait importé » et le badge « Entrera par exception ». La ligne de Parasite ajoute le motif « Langue originale hors filtre ». Le journal des balayages ne change pas.
  - Réf. : 20 § 3.3 · ImportPreviewController · paste-preview-panel.tsx
- [ ] **Importer depuis l’aperçu (voie d’exception)** ⭐
  - Faire : Dans le panneau d’aperçu terminé, cliquer « Importer ces films ».
  - Attendu : Le détail du balayage s’ouvre : nature « Collage d’identifiants », avec l’avis « Ce filtre a été ENREGISTRÉ mais NON APPLIQUÉ… ». Les films importés portent la voie « Collage » et le badge « Exception », Fight Club compris, alors qu’il satisfait le filtre.
  - Réf. : 20 § 3.3 · 10 § 9.2 · ImportIdsController
- [ ] **Import direct d’un collage**
  - Faire : Coller `1417`, puis cliquer « Importer ces identifiants ».
  - Attendu : Même redirection et même toast « Balayage ouvert… ». Le film entre en brouillon, par exception.
  - Réf. : ImportIdsController
- [ ] **Refus de validation du collage** ⚠️
  - Faire : Cliquer « Importer ces identifiants » avec le champ vide. Recommencer avec `abc`, puis avec 51 nombres (1 à 51, un par ligne). Refaire les trois essais avec « Prévisualiser ».
  - Attendu : Les messages, dans l’ordre des essais :
    - « Collez au moins un identifiant ou une URL TMDB. » ;
    - « Aucun identifiant lisible dans ce collage : attendez un nombre nu ou une URL TMDB par ligne. » ;
    - « Un envoi accepte au plus 50 identifiants : scindez la liste en plusieurs envois. ».
    Ils s’affichent sous le champ pour l’import et sous « Prévisualiser » pour l’aperçu. Le texte collé reste en place.
  - Réf. : CatalogImportValidationRules::identifiersRules · ImportIdsRequest
- [ ] **Doublon et identifiant inconnu** ⚠️
  - Faire : Prévisualiser `550` (déjà importé) et `999999999`, puis les importer pour de vrai.
  - Attendu : Dans l’aperçu, 550 a le sort « Déjà au catalogue » et le motif « Ignoré : ce film est déjà au catalogue. », avec un lien vers la fiche. 999999999 a le sort « Inconnu de TMDB » et le motif « Ignoré : TMDB ne connaît pas cet identifiant. ». À l’import, ils font monter le compteur « Ignorés », pas « Refusés par le filtre de contenu ».
  - Réf. : ImportOutcome::countersFor · admin.catalog.import.skipped.*
- [ ] **Filtre de contenu non contournable** ⭐
  - Faire : Chercher un film classé NC-17 aux États-Unis, par exemple « Showgirls » (1995) ou « La Vie d’Adèle » (2013). Le prévisualiser par son identifiant, puis l’importer. Si TMDB ne le classe plus ainsi, essayer un autre titre.
  - Attendu : Dans l’aperçu : « Refusé — filtre de contenu », avec le motif « Refusé : classification NC-17 en US (décision 12). ». À l’import, « Refusés par le filtre de contenu » vaut 1 et le film n’entre pas au catalogue. Aucun formulaire ne propose de lever ce filtre.
  - Réf. : 20 § 3.1 · ContentGate · RestrictiveCertifications
- [ ] **L’aperçu reste privé à son auteur**
  - Faire : Laisser un aperçu terminé côté curateur, puis ouvrir /admin/import dans l’autre navigateur avec le compte admin.
  - Attendu : L’admin ne voit pas le panneau « Aperçu à blanc » du curateur.
  - Réf. : 20 § 3.3 · PastePreview (clé par auteur)
- [ ] **Recherche TMDB** ⭐
  - Faire : Dans « Titre recherché », saisir `Le Roi Lion`, puis cliquer « Rechercher ».
  - Attendu : L’URL devient /admin/import/search?q=… et le texte « N résultats sur TMDB pour « Le Roi Lion » » s’affiche. Le tableau a les colonnes Titre, Année, Langue originale, Votes et Au catalogue, plus une colonne d’actions sans titre visible. L’avis « Le suivi en direct des balayages et de l’aperçu est suspendu pendant la recherche… » s’affiche en haut. « Fermer la recherche » ramène à /admin/import.
  - Réf. : 20 § 3.4 · ImportSearchController · import-search-panel.tsx
- [ ] **État local des résultats et import unitaire**
  - Faire : Chercher `The Lion King`, puis `Fight Club` (déjà importé). Cliquer « Importer » sur « The Lion King » (1994).
  - Attendu : Fight Club porte « Déjà au catalogue — Brouillon » et un bouton « Ouvrir la fiche ». The Lion King (1994) est « Absent du catalogue » : c’est normal, les films de démo n’ont pas d’identifiant TMDB. « Importer » ouvre un collage d’un seul identifiant, et le film est marqué « Exception ».
  - Réf. : 20 § 3.4
- [ ] **Recherche sans résultat et recherche trop courte** ⚠️
  - Faire : Chercher `zzqqxxwvv`, puis essayer de chercher `a`.
  - Attendu : Pour `zzqqxxwvv` : « TMDB ne connaît aucun film sous ce titre. Essayez le titre original, ou une autre graphie. ». Pour `a`, le navigateur bloque l’envoi (2 caractères minimum) ; l’aide « Entre 2 et 100 caractères… » est visible sous le champ.
  - Réf. : ImportSearchRequest (2 à 100 caractères)
- [ ] **Un seul collage ouvert à la fois** ⚠️
  - Faire : Arrêter la file (voir « Le traitement d’arrière-plan ne répond pas ») et lancer un collage, qui reste « En file ». Sur /admin/import, regarder les boutons « Importer » de la recherche et « Importer ces films » de l’aperçu. Puis forcer un second collage par « Importer ces identifiants ».
  - Attendu : Les boutons « Importer » et « Importer ces films » sont inactifs, avec le texte « Un collage est déjà ouvert : l’import d’un résultat redevient possible à sa fin. ». Le second collage reçoit le toast « Un balayage de cette nature est déjà ouvert… ».
  - Réf. : 20 § 3.4 · ImportLauncher · SeedList (busy_run_id)
- [ ] **Liste d’amorçage vide (état livré)**
  - Faire : Regarder la carte « Liste d’amorçage », le fichier du dépôt étant laissé tel quel.
  - Attendu : Le texte dit « La liste d’amorçage ne contient encore aucun identifiant : le bouton s’activera quand elle sera constituée. ». « Prévisualiser le lot suivant » et « Importer la liste d’amorçage » sont inactifs.
  - Réf. : 20 § 3.5 · SeedList · database/data/tmdb-seed-list.txt
- [ ] **Liste d’amorçage remplie localement** ⭐
  - Faire : Ajouter les lignes `129`, `8392` et `1417` à la fin de database/data/tmdb-seed-list.txt, sans commiter, puis recharger /admin/import. Cliquer « Prévisualiser le lot suivant ». Arrêter la file, cliquer « Importer la liste d’amorçage », puis revenir sur /admin/import. Relancer la file, puis attendre la fin du collage. Retirer les trois lignes après l’essai.
  - Attendu : La carte affiche « 3 identifiants dans la liste », puis « N identifiants restent à importer » (sans compter ceux déjà au catalogue). Viennent ensuite « Le prochain lot porte N identifiants, dans l’ordre de la liste. » et l’avertissement sur les refus. L’import redirige vers le détail du collage. De retour sur l’import, file arrêtée : « Un collage est en cours : le lot suivant pourra partir à sa fin. », avec le lien « Suivre le collage en cours ». Une fois le collage fini : « Chaque identifiant de la liste d’amorçage est déjà au catalogue : il n’y a plus rien à importer. ».
  - Réf. : 20 § 3.5 · ImportSeedListController · seed-list-panel.tsx

### 7.7 Détail d’un balayage, reprise et états du traitement

- [ ] **Contenu du détail d’un balayage** ⭐
  - Faire : Dans « Journal des balayages », cliquer « Détail » sur un balayage discover.
  - Attendu : Titre « Balayage n° X », sous-titre « Résumé », bouton « Retour à l’import ». La carte « Résumé » porte les badges de nature et d’état (plus « Filtre élargi » s’il y a lieu), puis Auteur (« Curateur Démo »), Démarré le, Terminé le et Durée. Viennent ensuite :
    - « Filtre appliqué » (Seuil de votes, Langues originales, Année minimale) ;
    - « Compteurs » (Fiches vues, Films importés, Ignorés, Refusés par le filtre de contenu), avec la sous-partie « Reprise » (Position du curseur, Dernier appel TMDB, bouton « Reprendre ») ;
    - « Films entrés par ce balayage ».
  - Réf. : 20 § 3.2 · ImportController@show · import/show.tsx
- [ ] **Reprendre un balayage suspendu** ⭐
  - Faire : Laisser se terminer un balayage discover d’1 page : il reste « En cours », son budget de pages étant consommé. Cliquer « Reprendre », depuis son détail ou depuis l’encart « Balayage n° X » au-dessus du journal.
  - Attendu : Avant la reprise, le détail affiche toujours « En cours » et « Balayage en cours : cet écran se rafraîchit tout seul. ». Après le clic, le toast « Balayage remis dans la file : il reprendra là où il s’était arrêté. » s’affiche. Le curseur avance (jusqu’à 5 pages par reprise), d’autres films entrent et les compteurs continuent de monter.
  - Réf. : 20 § 3.2 · ImportResumeController · ImportRunPolicy::update
- [ ] **Reprise impossible** ⚠️
  - Faire : Ouvrir le détail d’un collage, puis celui d’un balayage « Terminé » ou « Échoué ». Inspecter le bouton « Reprendre » dans l’arbre d’accessibilité ou avec un lecteur d’écran.
  - Attendu : « Reprendre » est inactif et sa raison est annoncée. Pour un collage : « Un collage n’est pas reprenable : la liste collée n’est stockée nulle part. Re-collez-la. ». Pour un balayage terminé ou échoué : « Ce balayage est terminé : le reprendre relancerait un curseur déjà consommé. ».
  - Réf. : admin-import-run-table.tsx (resumeBlockedBy)
- [ ] **Le traitement d’arrière-plan ne répond pas** ⚠️
  - Faire : Arrêter `composer dev`, puis relancer seulement `php artisan serve` et `npm run dev`, sans file. Lancer un balayage : on arrive sur son détail. Revenir sur /admin/import et attendre plus d’une minute. Enfin, relancer `php artisan queue:listen --queue=game,default --tries=1 --sleep=1 --timeout=900`.
  - Attendu : Le détail affiche l’état « En file » et, dès l’ouverture, l’encart « Ce balayage attend depuis plus d’une minute : le traitement d’arrière-plan ne répond pas. L’administrateur est prévenu ; le balayage partira de lui-même dès la reprise du traitement. ». Dans le journal, ce même texte n’apparaît sous l’état qu’après 60 s. « Reprendre » reste inactif (« Ce balayage n’a pas encore démarré… »). Une fois la file relancée, le balayage démarre seul et le message disparaît.
  - Réf. : 20 § 13.1 · admin.import.runs.worker_missing · import/show.tsx
- [ ] **Coupure réseau pendant un balayage** ⚠️
  - Faire : Lancer un balayage de 5 pages. Pendant qu’il tourne, couper l’accès Internet (Wi-Fi) sans toucher au réseau de la VM Homestead ni à Redis. Attendre qu’il s’arrête, rétablir Internet, puis ouvrir son détail.
  - Attendu : Le balayage reste « En cours » avec son curseur : il est suspendu mais reprenable. « Reprendre » le relance là où il s’était arrêté, sans doubler les compteurs.
  - Réf. : 20 § 3.6 · CatalogImportCommand::reportTmdbFailure
- [ ] **Clé TMDB invalide** ⚠️
  - Faire : Mettre `TMDB_API_KEY=invalide` et vider le jeton, lancer `php artisan config:clear`, puis relancer `composer dev`. Lancer un balayage, faire une recherche, puis prévisualiser un collage. Restaurer la clé après l’essai.
  - Attendu : Le balayage finit « Échoué », avec « Terminé le » renseigné. La recherche affiche « TMDB n’a pas pu répondre à cette recherche. Réessayez dans un instant ; si l’échec persiste, signalez-le à l’administrateur du site. » et un bouton « Réessayer ». L’aperçu affiche « L’aperçu n’a pas pu aller au bout : TMDB n’a pas répondu… » et « Réessayer l’aperçu ». Aucune page d’erreur brute n’apparaît.
  - Réf. : 20 § 13.1 · ImportSearchController (FAILED) · PastePreview::fail
- [ ] **Import désactivé sans clé** ⚠️
  - Faire : Vider les deux clés TMDB, lancer `php artisan config:clear`, puis relancer `composer dev`. Ouvrir /admin/import, puis le détail d’un balayage discover suspendu.
  - Attendu : /admin/import affiche l’encart « Aucune clé TMDB n’est configurée sur le serveur : les deux formulaires sont désactivés… ». Tous les champs et boutons de balayage, collage, aperçu, recherche et liste d’amorçage sont inactifs. Sur le détail, « Reprendre » est inactif.
  - Réf. : 20 § 13.1 · ImportController::screenProps

### 7.8 Catalogue : liste, recherche, filtres, tri

- [ ] **Liste du catalogue** ⭐
  - Faire : Ouvrir /admin/catalog.
  - Attendu : Titre « Catalogue des films », avec le badge « Lecture seule ». Viennent la carte « Filtres », la carte « Entrées par exception, par motif » (4 tuiles et l’avis de portée), puis le compteur « 16 film(s) au filtre courant ». Le tableau a les colonnes Titre original, Année, Langue, Votes, Disponibilité, Contenu, Voie d’entrée, Niveaux, Variantes et Entré le, avec un bouton « Ouvrir » par ligne. Il est trié par défaut du plus récent au plus ancien. Les films de démo montrent « Publié », « Contenu vérifié », « Démonstration » et « 5 / 5 ». « Snow White and the Seven Dwarfs », « 千と千尋の神隠し » (sous-titre « Sen to Chihiro no Kamikakushi ») et « となりのトトロ » portent « Exception » et leur motif.
  - Réf. : 20 § 4.3 · CatalogController@index · catalog/index.tsx
- [ ] **Recherche libre** ⭐
  - Faire : Dans « Recherche », taper `Star`, puis cliquer « Filtrer ». Recommencer avec `Sen to`, avec `550` (après l’import de Fight Club), puis avec `Roi Lion`.
  - Attendu : Résultats attendus :
    - `Star` donne les 2 Star Wars ;
    - `Sen to` trouve 千と千尋の神隠し par sa translittération ;
    - `550` trouve Fight Club par son identifiant TMDB ;
    - `Roi Lion` ne trouve rien, car seuls le titre original et sa translittération sont cherchés : « Aucun film » / « Aucun film ne correspond à ces filtres. Effacez-les pour revoir tout le catalogue. », avec un bouton « Tout effacer ».
  - Réf. : CatalogController::filtered
- [ ] **Filtres de statut et de voie** ⭐
  - Faire : Essayer chaque liste déroulante, puis cliquer « Filtrer » :
    - Disponibilité (Brouillon, Publié, Dépublié, Suspendu, Retiré) ;
    - Drapeau de contenu (Contenu vérifié, Bloqué, Classification à vérifier) ;
    - Voie d’entrée (Balayage, Collage, Démonstration) ;
    - Jouable à N images (N = 2 et plus … N = 5 et plus) ;
    - Entrés par exception (Toutes les exceptions, Motif : langue originale, Motif : notoriété, Motif : année de sortie) ;
    - État de curation (Prêts à publier, Publiés incomplets, Écartés) ;
    - Titre manquant.
    Terminer par « Tout effacer ».
  - Attendu : Le tableau et le compteur suivent chaque filtre, et l’URL porte les paramètres. Un rechargement conserve les filtres. « Tout effacer » ramène au catalogue complet.
  - Réf. : CatalogIndexRequest · admin-catalog-filters.tsx
- [ ] **Facettes d’exception mesurées hors de leur propre filtre**
  - Faire : Filtrer « Entrés par exception » sur « Motif : langue originale ».
  - Attendu : Le tableau ne montre que 千と千尋の神隠し et となりのトトロ. Les 4 tuiles restent à 3 / 2 / 0 / 1 : elles ignorent la seule facette « exception », comme le dit l’avis de portée.
  - Réf. : CatalogController::facets · décision 11
- [ ] **Tri par colonne**
  - Faire : Cliquer l’en-tête « Année », le recliquer, puis trier par « Votes » avec un filtre posé.
  - Attendu : Au premier clic, l’ordre est décroissant avec une flèche ; au second, il est croissant. Le filtre est conservé. Au clavier, l’en-tête s’annonce « Trier par Année ».
  - Réf. : admin-movie-table.tsx · admin-catalog-query.ts
- [ ] **Pagination**
  - Faire : Avec plus de 25 films (par exemple après un balayage d’1 page), atteindre « Suivant » au clavier et appuyer sur Entrée.
  - Attendu : La page affiche « Page 2 sur N » et « 26 à X sur Y », avec les boutons « Précédent » et « Suivant ». Les filtres et le tri sont conservés. Pendant le chargement, le tableau pâlit sans disparaître.
  - Réf. : catalog/index.tsx (aria-busy) · AdminPagination
- [ ] **File « titre manquant »**
  - Faire : Retirer le titre français d’un film de démo (voir la fiche), puis filtrer « Titre manquant » sur « Sans titre en français ».
  - Attendu : Le film apparaît, les films publiés venant en tête.
  - Réf. : 20 § 9.3 · title_locale_mask

### 7.9 Fiche film : lecture, titres, alias, formes acceptées

- [ ] **Bandeau de la fiche** ⭐
  - Faire : Dans le catalogue, cliquer « Ouvrir » sur « Star Wars: A New Hope ».
  - Attendu : En tête, le titre original et la notice « Les images du film se curent dans l’éditeur de la banque d’images… », avec les boutons « Curer les images » et « Retour au catalogue ». La carte « Disponibilité » montre les badges « Publié » et « Contenu vérifié », puis les champs Changé le, Motif du changement, Première mise en jeu, Contenu vérifié par (« Curateur Démo ») et Contenu vérifié le. Elle affiche aussi l’encart « Publiable : contenu vérifié ET niveaux 1, 3 et 5 couverts. », avec « Couvre les niveaux 1, 3 et 5 : Oui ». Sous « Gestes sur le film », un seul bouton : « Dépublier le film ».
  - Réf. : 20 § 4.3 · CatalogController@show · catalog/show.tsx
- [ ] **Les 8 onglets**
  - Faire : Parcourir les onglets Identité, Titres et alias, Étiquettes TMDB, Projection, Thèmes, Banque d’images, Même œuvre et Import. Au clavier, se placer sur la liste d’onglets et utiliser les flèches.
  - Attendu : Contenu attendu, onglet par onglet :
    - Identité : « Identifiant catalogue », puis « Aucun identifiant TMDB » pour un film de démo. Pour un film importé, c’est son identifiant, cliquable (nom accessible « Ouvrir sur themoviedb.org »), qui s’ouvre dans un nouvel onglet.
    - Projection : « Niveaux couverts 5 / 5 ».
    - Thèmes : un tableau Thème / Règle automatique / Exception manuelle / Appartenance effective, sans aucun bouton.
    - Banque d’images : 5 lignes « Publié » / « Prête » et le bouton « Ouvrir l’éditeur de la banque d’images ».
    - Import : cartes « Provenance » (« Balayage d’origine : Aucun balayage rattaché. » pour un film de démo) et « Curation ».
    Les flèches changent d’onglet.
  - Réf. : catalog/show.tsx
- [ ] **Classification retenue d’un film de démo**
  - Faire : Ouvrir la fiche de « Dune: Part Two », onglet « Étiquettes TMDB », puis celle de « Inception ».
  - Attendu : Dune: Part Two affiche sous « Classifications retenues » une ligne « France », sans badge « Restrictive ». Dans sa carte Disponibilité, « Contenu vérifié par » vaut « Contenu jamais vérifié » : son contenu est déclaré sûr par la classification, pas par une coche. Inception affiche « Aucune classification lue. ».
  - Réf. : 20 § 4.4 · 10 § 3.8 · DemoCatalogueSeeder (certified)
- [ ] **Corriger un titre avec aperçu** ⭐
  - Faire : Onglet « Titres et alias », ligne Français : cliquer « Corriger ». Saisir un nouveau titre, cliquer « Vérifier », modifier le texte, cliquer de nouveau « Vérifier », puis « Enregistrer le titre ».
  - Attendu : La boîte « Corriger le titre — Français » contient le champ « Titre affiché ». Avant l’aperçu, elle affiche « Vérifiez l’aperçu avant d’enregistrer… ». Après une modification, l’enregistrement reste inactif et la boîte affiche « Le texte a changé depuis l’aperçu : vérifiez de nouveau avant d’enregistrer. ». L’aperçu montre « Forme acceptée : « … » », puis, sur un film publié, « Aucune forme ne deviendra ambiguë. » ou les formes touchées. À l’enregistrement, le toast « Titre enregistré : il est affiché et accepté comme réponse. » s’affiche. L’origine devient « Curateur », « Corrigé par » vaut « Curateur Démo » et « Formes acceptées » est mis à jour.
  - Réf. : 20 § 9.1 · MovieTitleController · text-gesture-dialog.tsx
- [ ] **Retirer puis ressaisir un titre de curateur**
  - Faire : Sur une ligne d’origine « Curateur » (tous les titres de démo le sont), cliquer « Retirer », puis confirmer « Retirer le titre ». Cliquer ensuite « Saisir » sur la ligne vidée.
  - Attendu : La boîte « Retirer le titre — Français » affiche son avertissement. Après confirmation, le toast « Titre retiré. » s’affiche, la ligne affiche « Aucun titre » et la couverture passe à « Absent ». Aucun titre n’est recopié d’une autre langue. « Saisir » ouvre ensuite la boîte « Saisir le titre — Français ».
  - Réf. : 20 § 9.1 · DeleteMovieTitle
- [ ] **Titre TMDB : corrigeable, pas retirable** ⚠️
  - Faire : Ouvrir la fiche d’un film importé depuis TMDB, onglet « Titres et alias ».
  - Attendu : Les lignes d’origine « TMDB » n’ont que « Corriger », jamais « Retirer ». L’aide indique « Un titre TMDB se corrige, il ne se retire pas : la resynchronisation suivante le recréerait. ».
  - Réf. : 20 § 9.1
- [ ] **Texte sans lettre ni chiffre** ⚠️
  - Faire : Dans « Corriger », saisir `!!!`, puis cliquer « Vérifier ».
  - Attendu : L’aperçu affiche « Ce texte ne contient ni lettre ni chiffre : il ne sera jamais accepté comme réponse. ».
  - Réf. : CatalogController::textPreview
- [ ] **Ajouter un alias** ⭐
  - Faire : Sur la fiche de « Pulp Fiction », cliquer « Ajouter un alias ». Choisir « Français » dans « Langue de l’alias », saisir `Pulp`, cliquer « Vérifier », puis « Ajouter l’alias ».
  - Attendu : La boîte « Ajouter l’alias » propose le choix « Choisir une langue » (Français, Anglais). L’aperçu affiche « Forme acceptée : « pulp » » et « Aucune forme ne deviendra ambiguë. ». Après l’ajout, le toast « Alias ajouté : il est accepté comme réponse. » s’affiche. Une nouvelle ligne apparaît, d’origine « Curateur », avec « Ajouté par » = « Curateur Démo », et « pulp » entre dans « Formes acceptées ».
  - Réf. : 20 § 9.2 · MovieAliasController · AddAlias
- [ ] **Alias redondant** ⚠️
  - Faire : Sur « Toy Story », dans « Ajouter un alias », vérifier `toy story`.
  - Attendu : L’aperçu avertit « Cette forme est déjà acceptée pour ce film (…) : l’ajouter n’accepte rien de plus. ».
  - Réf. : 20 § 9.2 · text_preview.already_accepted
- [ ] **Préfixe ambigu promu par un alias** ⚠️
  - Faire : Sur « Star Wars: A New Hope », lire « Formes acceptées », puis vérifier l’alias `Star Wars`.
  - Attendu : La forme « star wars » (Préfixe) est marquée « Refusée seule : un autre film publié porte cette forme ». L’aperçu dit « Cette forme est aujourd’hui dérivée d’un titre de ce film (préfixe) et refusée seule : un autre film publié la porte. En alias, elle deviendra exacte et sera toujours acceptée pour ce film. ».
  - Réf. : 20 § 9.2 · 70 · AnswerKey
- [ ] **Retirer un alias**
  - Faire : Cliquer « Retirer » sur un alias, puis « Retirer l’alias ».
  - Attendu : La boîte dit « « … » ne sera plus accepté comme réponse, sauf si un titre ou un autre alias du film donne la même forme. ». Pour un alias TMDB, elle ajoute « Cet alias vient de TMDB : la prochaine resynchronisation le fera réapparaître. ». Après confirmation, le toast « Alias retiré : il n’est plus accepté comme réponse. » s’affiche.
  - Réf. : 20 § 9.2 · DeleteAlias
- [ ] **Battement de débit (temps actif)**
  - Faire : Sur la fiche d’un brouillon importé (pas un film de démo), onglet visible, bouger la souris et taper pendant environ 1 min en surveillant l’onglet Réseau. Recharger, puis ouvrir l’onglet « Import ». Refaire l’essai sur un film de démo.
  - Attendu : Un `POST /admin/catalog/{id}/heartbeat` part environ toutes les 15 s (réponse 204), seulement après une saisie et jamais quand l’onglet est masqué. Aucun toast ne s’affiche. « Temps actif de curation (secondes) » augmente sur le brouillon. Il reste à 0 sur un film de démo ou déjà publié.
  - Réf. : 20 § 10.1 · CurationHeartbeatController · RecordCurationHeartbeat
- [ ] **Lire la fiche d’un film importé de TMDB**
  - Faire : Après l’import de `550` (Fight Club) par collage, ouvrir sa fiche et parcourir les onglets « Identité », « Étiquettes TMDB », « Projection », « Banque d’images » et « Import ».
  - Attendu : Identité : « Identifiant TMDB » 550, lien vers themoviedb.org, puis « Titre original », « Langue originale », « Année de sortie », « Votes TMDB », « Marqué « adulte » par TMDB », « Saga TMDB », « Difficulté effective » et « Difficulté dérivée ». Étiquettes TMDB : « Classifications retenues » avec les pays que TMDB fournit (France, États-Unis ; colonnes Pays, Classification, Sortie, Lue le), sans badge « Restrictive », puis « Genres » et « Sociétés » en identifiants TMDB bruts, sans libellé. Projection : aucun niveau couvert. Banque d’images : « Aucune image : la banque de ce film est vide. ». Import : « Voie d’entrée » Collage, « Balayage d’origine » renseigné, « Entré par exception », et « Curé par » à « Jamais curé ».
  - Réf. : 20 § 4.3, § 4.4 · catalog/show.tsx · admin.movie.identity / certifications / tags

### 7.10 Fiche film : même œuvre (movie_group)

- [ ] **Regrouper un candidat au même titre** ⭐
  - Faire : Importer « The Lion King » (1994) par la recherche TMDB. Ouvrir sa fiche, onglet « Même œuvre ». Cliquer « Regrouper » sur le candidat, garder ou modifier « Libellé du groupe », puis confirmer « Regrouper ».
  - Attendu : Au départ, la fiche affiche « Ce film n’appartient à aucun groupe. » et « Candidats : même titre », qui liste « The Lion King (1994) » (le film de démo). La boîte « Regrouper les deux films » dit « Ce film et The Lion King (1994) ne tomberont plus dans une même partie. ». Après confirmation, le toast « Groupe créé : les deux films ne tomberont plus dans une même partie. » s’affiche. La carte montre Libellé, Regroupé par, Regroupé le et Films du groupe. Dans l’onglet Identité, « Groupe d’homonymes » affiche le libellé, et le candidat passe à « Déjà dans ce groupe ».
  - Réf. : 20 § 9.4 · MovieGroupController · movie-group-panel.tsx
- [ ] **Regroupement manuel par identifiant**
  - Faire : Sur « Back to the Future », dans « Regrouper avec un autre film », saisir l’« Identifiant catalogue de l’autre film » (lu dans l’onglet Identité d’un autre film publié). Cliquer « Regrouper », puis confirmer.
  - Attendu : La même confirmation s’ouvre, avec un libellé pré-rempli « Titre A (année) / Titre B (année) », modifiable. Le toast « Groupe créé… » s’affiche ; si l’un des deux films avait déjà un groupe, c’est « Film rattaché au groupe. ». Sur le tableau de bord, le vivier N = 2 à 5 perd 1 œuvre.
  - Réf. : 20 § 9.4 · SetMovieGroup
- [ ] **Refus du regroupement manuel** ⚠️
  - Faire : Dans « Regrouper avec un autre film », essayer successivement :
    - le champ vide ;
    - l’identifiant du film lui-même ;
    - `999999` ;
    - l’identifiant d’un film déjà dans le même groupe ;
    - deux films déjà dans deux groupes différents.
  - Attendu : Aucune confirmation ne s’ouvre, et chaque essai affiche son message sous le champ, dans l’ordre :
    - « Saisissez l’identifiant catalogue de l’autre film. » ;
    - « Un film ne se regroupe pas avec lui-même. » ;
    - « Aucun film du catalogue ne porte cet identifiant. » ;
    - « Ces deux films sont déjà dans le même groupe : rien à regrouper. » ;
    - « Les deux films appartiennent déjà à deux groupes différents : retirez d’abord l’un d’eux de son groupe. ».
  - Réf. : SetMovieGroup::pairRefusal · CatalogController::manualGroupCandidate
- [ ] **Retirer du groupe**
  - Faire : Sur un film d’un groupe de 2, cliquer « Retirer du groupe », puis confirmer « Retirer du groupe ».
  - Attendu : La boîte « Retirer ce film du groupe » précise « Un groupe réduit à un seul film disparaît. ». Après confirmation, le toast « Film retiré du groupe. » s’affiche, et les deux fiches affichent de nouveau « Ce film n’appartient à aucun groupe. ».
  - Réf. : 20 § 9.4

### 7.11 Fiche film : publier, dépublier, écarter, contenu vérifié

- [ ] **Dépublier un film publié (motif obligatoire)** ⭐
  - Faire : Sur « Star Wars: The Empire Strikes Back », cliquer « Dépublier le film ». Essayer de valider avec le motif vide, puis saisir un motif dans « Motif de la dépublication » et cliquer « Dépublier le film ».
  - Attendu : Le texte d’aide « Saisissez un motif : il est inscrit tel quel au journal d’administration et sur la fiche du film. » est toujours affiché sous le champ. Tant que le motif est vide, le bouton d’envoi reste inactif. Après l’envoi, le toast « Film dépublié : il sort du vivier des parties lancées désormais. » s’affiche. Le badge passe à « Dépublié », « Motif du changement » reprend le motif saisi, et le geste proposé devient « Republier le film ». Sur le tableau de bord, Publié passe à 15 et Dépublié à 1.
  - Réf. : 20 § 8.3 · MovieUnpublishController · reason-dialog.tsx
- [ ] **Republier avec l’avertissement d’ambiguïté** ⭐
  - Faire : Sur ce même film dépublié, cliquer « Republier le film », lire l’aperçu, puis confirmer « Republier le film ».
  - Attendu : Sous « Formes rendues ambiguës », des lignes grisées de chargement s’affichent d’abord (« Calcul de l’avertissement d’ambiguïté… » pour les lecteurs d’écran). Vient ensuite la ligne « « star wars » — préfixe de ce film, porté aussi par : Star Wars: A New Hope (1977), préfixe ». Après confirmation, le toast « Film republié : il revient au vivier des parties lancées désormais. » s’affiche. « Première mise en jeu » ne change pas.
  - Réf. : 20 § 8.1, § 8.2 · PublishMovie · publish-dialog.tsx
- [ ] **Aperçu d’ambiguïté périmé** ⚠️
  - Faire : Dépublier les deux Star Wars. Dans l’onglet A, ouvrir « Republier le film » sur The Empire Strikes Back : la boîte indique « Aucune forme ne deviendra ambiguë. » ; la laisser ouverte. Dans l’onglet B, republier A New Hope. Revenir à l’onglet A et confirmer.
  - Attendu : En tête de la boîte, le refus s’affiche : « Le catalogue a changé depuis l’affichage de l’avertissement : rien n’a été publié. Relisez l’avertissement mis à jour, puis confirmez de nouveau. ». L’aperçu se recalcule et montre la ligne « star wars ». Une nouvelle confirmation publie le film.
  - Réf. : 20 § 8.2 · PublishMovie::guard (preview_stale)
- [ ] **Publication bloquée, conditions nommées** ⭐
  - Faire : Ouvrir la fiche d’un brouillon importé sans aucune image, puis cliquer « Publier le film ».
  - Attendu : Le bouton est inactif et rien ne se passe au clic. Sous « Pas encore publiable : » figure « Niveaux sans image en jeu : 1 · 3 · 5. Chacun des niveaux 1, 3 et 5 doit avoir au moins une image publiée par une revue conforme. ». S’y ajoute « Le contenu du film n’est pas vérifié… » si le drapeau vaut « Classification à vérifier ». L’encart de la carte Disponibilité commence par « Non publiable : … », selon le cas.
  - Réf. : 20 § 8.1 · PublishMovie::conditions
- [ ] **Écarter un brouillon** ⭐
  - Faire : Sur un brouillon importé, cliquer « Écarter le film », garder le motif pré-rempli, puis confirmer « Écarter le film ».
  - Attendu : Le champ « Motif de la mise à l’écart » est pré-rempli avec « Aucune image exploitable ». Après confirmation, le toast « Film écarté : il sort de la file de curation. » s’affiche et le badge devient « Dépublié », avec le motif. Le film quitte /admin/curation. Il apparaît dans « Films écartés » du tableau de bord et dans le filtre catalogue « Écartés ». Le geste proposé redevient « Publier le film » : ce serait une première publication.
  - Réf. : 20 § 4.2 · MovieUnpublishController
- [ ] **Cocher « contenu vérifié »** ⭐
  - Faire : Trouver un brouillon « Classification à vérifier » (filtre « Drapeau de contenu »). À défaut, lancer `php artisan tinker --execute="App\Models\Movie::where('tmdb_id', 550)->update(['content_flag' => 'unrated_pending'])"`. Sur sa fiche, cliquer « Contenu vérifié », remplir « Ce que vous avez vérifié », puis cliquer « Confirmer la vérification ».
  - Attendu : La boîte « Contenu vérifié, pas de classification restrictive » exige un motif. Après confirmation, le toast « Contenu vérifié : cette condition de publication est levée. » s’affiche. Le badge passe à « Contenu vérifié », avec « Contenu vérifié par » (« Curateur Démo ») et « Contenu vérifié le » renseignés. Le bouton disparaît, et aucun geste ne permet de décocher.
  - Réf. : 20 § 4.4 · MovieContentVerifiedController · VerifyMovieContent
- [ ] **Contenu bloqué : aucun geste pour le lever** ⚠️
  - Faire : Lancer `php artisan tinker --execute="App\Models\Movie::where('tmdb_id', 1417)->update(['content_flag' => 'blocked'])"` (un brouillon importé), puis ouvrir sa fiche.
  - Attendu : Sous « Gestes sur le film » : « Contenu bloqué par une classification restrictive : ce film n’entrera jamais au vivier, et aucun geste ne lève ce blocage. ». Il n’y a pas de bouton « Contenu vérifié ». « Publier le film » reste inactif, avec la ligne sur le contenu ; seul « Écarter le film » reste possible.
  - Réf. : 20 § 4.4 · décision 12 · MoviePolicy::verifyContent
- [ ] **Affichage d’un film suspendu ou retiré** ⚠️
  - Faire : Lancer `php artisan tinker --execute="App\Models\Movie::where('title_original', 'The Shining')->update(['availability' => 'suspended'])"`, puis ouvrir sa fiche. Faire de même avec `'withdrawn'` sur « Pulp Fiction », ouvrir sa fiche, puis /admin/catalog/{id}/bank de ce film (APP_DEBUG=false).
  - Attendu : Film suspendu : badge « Suspendu » et mention « Aucun geste de curation n’est possible sur ce film dans son état actuel. ». « Curer les images » et « Corriger » restent présents. Film retiré : la fiche reste lisible, mais on n’y trouve ni « Curer les images », ni boutons Corriger / Retirer / Ajouter un alias / Regrouper. Son éditeur répond par la page admin « Accès refusé ». Le tableau de bord compte 1 Suspendu et 1 Retiré.
  - Réf. : MoviePolicy (curate, publish, unpublish) · 10 § 3.1
- [ ] **Refus de policy en deux onglets (page admin/error)** ⚠️
  - Faire : Avec APP_DEBUG=false, ouvrir la même fiche publiée dans deux onglets. Dans l’onglet B, dépublier. Dans l’onglet A, ouvrir « Dépublier le film », saisir un motif et confirmer.
  - Attendu : La page « Accès refusé » du back-office s’affiche (coquille admin, en français) : « Cet écran ou ce geste est réservé à un autre rôle… », avec les boutons « Page précédente » et « Retour au tableau de bord ». Aucune seconde dépublication n’est enregistrée.
  - Réf. : 20 § 13.2 · pages/admin/error.tsx · can:unpublish
- [ ] **Trace au journal admin_action**
  - Faire : En admin, ouvrir /admin/users, puis la fiche du compte curateur, et noter les compteurs. En curateur, publier, dépublier ou écarter un film, ou vérifier son contenu, et lancer un import. Rouvrir ensuite la fiche du compte curateur.
  - Attendu : Dans le bloc « Preuves signées », « Lignes du journal d’administration » augmente de 1 par geste de publication, dépublication, écart ou vérification. « Balayages d’import lancés » augmente de 1 par import. Corriger un titre, un alias ou un groupe n’écrit rien au journal. Aucune liste des gestes de catalogue n’est affichée par film.
  - Réf. : 20 § 2.7 · UserDirectoryController@show · AdminJournal

### 7.12 File de curation

- [ ] **File vide après le seed** ⭐
  - Faire : Ouvrir /admin/curation avant tout import.
  - Attendu : La page affiche « Aucun autre film n’attend dans la file de curation. Importez de nouveaux films pour la remplir. », avec le bouton « Ouvrir l’import ». « Reste à curer, par voie d’entrée » affiche 0 et 0 (Voie du balayage, Voie d’exception) : les 16 films de démo sont exclus.
  - Réf. : 20 § 4.1 · CurationQueue
- [ ] **Ordre et colonnes de la file** ⭐
  - Faire : Après quelques imports, rouvrir /admin/curation.
  - Attendu : La page affiche « N film(s) dans la file au filtre courant ». Colonnes : Rang, Titre original, Année, Votes, Voie, Contenu, Niveaux, Variantes et Avancement, puis les actions « Curer » (vers l’éditeur de la banque) et « Fiche ». L’avancement vaut « Non entamé », ou « Entamé » avec « Touché le … ». Les films entamés viennent d’abord, puis les autres par votes décroissants. Les totaux par voie comptent toute la file.
  - Réf. : 20 § 4.1 · curation/index.tsx
- [ ] **Filtres de la file (strates du pilote)**
  - Faire : Filtrer « Voie d’entrée » sur « Entrés par exception », puis sur « Balayage ». Essayer « Motif d’exception » et « Drapeau de contenu », puis cliquer « Tout effacer ».
  - Attendu : La liste se restreint sans changer d’ordre, et les totaux par voie restent calculés sur toute la file. Un filtre sans résultat affiche « Aucun film de la file ne correspond à ces filtres. Effacez-les pour revoir toute la file. ».
  - Réf. : 20 § 4.1 · CurationQueueRequest
- [ ] **« Film suivant » et strate** ⭐
  - Faire : Avec un filtre posé, cliquer « Film suivant ». Recommencer avec un filtre qui ne garde qu’un seul film, en cliquant « Film suivant » depuis l’éditeur de ce film.
  - Attendu : Dans le premier cas, l’éditeur de la banque du premier film de la file s’ouvre, filtres reportés dans l’URL. Dans le second, retour à la file avec le toast « Aucun autre film de cette sélection n’attend dans la file. Effacez les filtres pour revoir toute la file. ».
  - Réf. : 20 § 4.1 · CurationQueueController@next
- [ ] **Sortie de file après écart**
  - Faire : Écarter un film de la file depuis sa fiche, puis revenir sur /admin/curation.
  - Attendu : Le film n’est plus listé, et le total de sa voie a baissé de 1.
  - Réf. : 20 § 4.2

### 7.13 Débit et lot pilote

- [ ] **Écran de débit initial** ⭐
  - Faire : Ouvrir /admin/throughput (entrée « Débit ») avant tout écart ou publication d’un film réel.
  - Attendu : Titre « Débit de curation et lot pilote », avec le bouton « Rafraîchir ». Le message « Aucun film réel n’est encore terminé : un film l’est à sa première publication, ou quand il est écarté. » s’affiche avec le bouton « Ouvrir la file de curation ». Carte « Lot pilote » :
    - « Voie du balayage » : « 0 sur 15 films terminés » ;
    - « Voie d’exception » : « 0 sur 5 films terminés » ;
    - chacune avec le badge « En cours » et « Comptés à partir du rang 1 de la voie » ;
    - puis « Le verdict attend que chaque voie ait son quota de films terminés. ».
    Les mesures valent « Sans mesure ». « Films écartés et motifs » affiche « Aucun film terminé n’a été écarté. ». Aucun curateur n’est nommé.
  - Réf. : 20 § 10.2 à 10.4 · ThroughputReport · throughput.tsx
- [ ] **Un film écarté compte comme terminé (échec)** ⭐
  - Faire : Écarter un brouillon importé par collage, puis cliquer « Rafraîchir » sur /admin/throughput.
  - Attendu : « Films écartés et motifs » affiche une ligne (Film, Voie, Motif, Écarté le), avec le badge « Pilote » si le film entre dans la fenêtre. La voie d’exception passe à « 1 sur 5 films terminés ». Les mesures indiquent « Films terminés » 1 et « Écartés » 1.
  - Réf. : 20 § 10.3 · D11 du 23/09
- [ ] **Rafraîchir hors ligne** ⚠️
  - Faire : Passer l’onglet Réseau en « Hors ligne », puis cliquer « Rafraîchir ».
  - Attendu : Le toast « Connexion perdue : rien n’a été envoyé et votre saisie est conservée… » s’affiche, avec l’encart « Le tableau du débit n’a pas pu être rechargé. » / « Les chiffres affichés sont ceux du dernier chargement réussi. » et son bouton « Réessayer ». Les chiffres du dernier chargement restent affichés.
  - Réf. : 20 § 13.5 · throughput.tsx
- [ ] **Mesures et projection après la première publication d’un film réel**
  - Faire : Publier le film importé (passe 1 de la section banque d’images, en curant avec l’éditeur ouvert et actif pour que le temps actif soit mesuré). Ouvrir /admin/throughput et cliquer « Rafraîchir ».
  - Attendu : Le message « Aucun film réel n’est encore terminé… » disparaît, et la voie d’exception compte un film terminé de plus. Dans « Tous les films terminés », la ligne « Voie d’exception » affiche Films terminés 1 et Publiés 1, avec « Temps actif total », « Médiane par film publié » et « p90 par film publié » renseignés, « Images recadrées » (le nombre d’images ajoutées avant la publication) et les durées de recadrage. La projection affiche un tableau Cible / Films / Heures / Semaines (lignes « Jalon 1 » et « Volume ») : les semaines sont « Sans mesure », et le tableau est suivi de « Heures hebdomadaires de curation non déclarées : aucune projection en semaines. Elles se déclarent avant le lot pilote. ». Avant toute publication, la projection disait « Pas de projection sans film publié. ».
  - Réf. : 20 § 10.1 à 10.4 · ThroughputReport · pages/admin/throughput.tsx
- [ ] **Verdict du lot pilote (quotas réduits en local)** ⚠️
  - Faire : Dans `config/catalog.php`, sur le poste seulement, sous `curation.pilot`, mettre `'composition' => ['discover' => 1, 'exception' => 1]` et `'size' => 2`. Écarter un brouillon importé par balayage et un brouillon importé par collage (sauf si chaque voie a déjà un film terminé), puis cliquer « Rafraîchir » sur /admin/throughput. Remettre ensuite 15, 5 et 20.
  - Attendu : Chaque voie affiche « 1 sur 1 films terminés » et « Quota atteint ». La carte « Verdict du pilote » apparaît, avec « Fenêtre du pilote remplie le … Ce verdict ne bouge plus : … ». Elle affiche « Temps actif dans la limite » et « Le temps actif total du pilote, …, tient sous le seuil de disqualification de … » (10 h par défaut), puis la mention sur les interventions hors du back-office. Tuiles : « Films du pilote » 2, « Films écartés, comptés comme échecs » 2, et « p90 du temps actif par film publié » à « Sans mesure ». « Films du jalon 1 » dit « Aucun film publié dans le pilote : sans p90, … », et « Cible de volume » dit « Heures de curation non déclarées : aucune cible de volume. … ».
  - Réf. : 20 § 10.3, § 10.4 · D10, D11 du 23/09 · config/catalog.php (pilot)

### 7.14 Erreurs, limiteurs et déconnexion

- [ ] **Film ou balayage inconnu pour un curateur** ⚠️
  - Faire : Avec APP_DEBUG=false et un curateur à 2FA active, ouvrir /admin/catalog/999999, puis /admin/import/run/999999.
  - Attendu : C’est la page d’erreur joueur « Page introuvable » qui s’affiche (coquille publique, langue du visiteur), pas la page admin. C’est le comportement livré et documenté (écart E62-6, en attente de l’avis du porteur).
  - Réf. : 20 § 13.2 (E62-6) · ErrorPageResponder
- [ ] **Limiteur des écritures d’import** ⚠️
  - Faire : Avec APP_DEBUG=false, cliquer « Prévisualiser » sur un collage valide 13 fois en moins d’une minute.
  - Attendu : Au 13e envoi, la page d’erreur joueur « Trop de demandes » s’affiche (écart E62-6). Au bout d’une minute, l’envoi repasse.
  - Réf. : FortifyServiceProvider (admin-import, 12/min)
- [ ] **Page expirée pendant un geste (419)** ⚠️
  - Faire : Avec APP_DEBUG=false, sur une fiche film, ouvrir « Dépublier le film » et saisir un motif. Dans Outils de développement › Application › Cookies, supprimer le cookie `XSRF-TOKEN`, puis confirmer.
  - Attendu : Retour sur la même fiche, avec le toast « La page a expiré faute d’activité récente. Recommencez votre dernière action. » (message du site joueur, écart E62-6). Rien n’est enregistré. Un second essai passe.
  - Réf. : 20 § 13.2 (E62-6) · ErrorPageResponder (419)
- [ ] **Déconnexion pendant un geste sur la fiche** ⚠️
  - Faire : Sur une fiche, ouvrir « Dépublier le film » et saisir un motif. Passer l’onglet Réseau en « Hors ligne », puis confirmer.
  - Attendu : Le toast « Connexion perdue : rien n’a été envoyé et votre saisie est conservée. Vérifiez votre réseau, puis réessayez. » s’affiche. Rien n’a changé côté serveur.
  - Réf. : 20 § 13.5 · catalog/show.tsx
- [ ] **Déconnexion sur l’écran d’import** ⚠️
  - Faire : Coller des identifiants, passer en « Hors ligne », puis cliquer « Importer ces identifiants » deux fois.
  - Attendu : Le même toast « Connexion perdue… » s’affiche une seule fois. Le texte collé reste dans le champ.
  - Réf. : import/index.tsx
- [ ] **Réimport d’un film retiré refusé** ⚠️
  - Faire : En fin de recette, lancer `php artisan tinker --execute="App\Models\Movie::where('tmdb_id', 550)->update(['availability' => 'withdrawn', 'availability_reason' => 'Essai de recette'])"`. Chercher « Fight Club » sur /admin/import, prévisualiser `550`, puis l’importer.
  - Attendu : La recherche affiche « Retiré — réimport bloqué » et « Ouvrir la fiche ». L’aperçu donne « Refusé — film retiré », avec « Refusé : film retiré du catalogue par la curation. Motif : Essai de recette ». À l’import, le compteur « Ignorés » monte, pas « Refusés par le filtre de contenu ».
  - Réf. : 20 § 3.4 · ImportOutcome::refusedWithdrawn
- [ ] **Sorties de la page admin/error** ⚠️
  - Faire : Sur une page « Accès refusé » du back-office obtenue plus haut, cliquer « Page précédente ». Ouvrir ensuite cette même URL dans un nouvel onglet et recliquer « Page précédente ».
  - Attendu : Le premier clic ramène à l’écran précédent. Dans le nouvel onglet, sans historique, le bouton mène au tableau de bord.
  - Réf. : pages/admin/error.tsx

### 7.15 Curateur et administrateur sur ces écrans

- [ ] **Navigation selon le rôle** ⭐
  - Faire : Comparer, dans deux navigateurs, la barre latérale du curateur et celle de l’admin.
  - Attendu : Chez le curateur : pas de groupe « Administration », badge « Curateur ». Chez l’admin : un groupe « Administration » en plus (Comptes, Accès), badge « Administrateur ». Toutes les pages et tous les gestes de cette section sont identiques pour les deux rôles. Au J1, l’admin n’a aucun bouton en plus sur la fiche : ni suspension, ni retrait.
  - Réf. : 20 § 2.2 · admin-nav.tsx (minRole) · CatalogController@show (abilities)
- [ ] **Curateur refusé sur les écrans admin seuls** ⚠️
  - Faire : En curateur, avec APP_DEBUG=false, taper /admin/users, /admin/access, puis /admin/users/999999.
  - Attendu : Les trois URL donnent un 403 « Accès refusé » sur la page d’erreur joueur, y compris l’identifiant inconnu : jamais de 404.
  - Réf. : routes/admin.php (role:admin) · 20 § 2.8
- [ ] **Journal d’import partagé entre comptes**
  - Faire : L’admin lance un balayage d’1 page. Le curateur ouvre /admin/import, puis le détail de ce balayage, et clique « Reprendre ».
  - Attendu : Le curateur voit le balayage avec l’Auteur « Admin Démo », peut l’ouvrir et le reprendre : la policy ne pose aucune condition sur l’auteur.
  - Réf. : ImportRunPolicy::view, update

### 7.16 Hors périmètre (non livré, ne pas essayer)

- Suspension conservatoire et levée d’un film ou d’une image (la « mise hors jeu immédiate en un clic ») : J2, lot L20-20. Il n’existe ni route ni bouton, même pour l’admin. L’état « Suspendu » ne s’obtient que par tinker, pour vérifier l’affichage.
- Retrait juridique d’un film ou d’une image, et suppression des fichiers : J2 (L20-21, L20-24). L’état « Retiré » ne s’obtient que par tinker.
- Resynchronisation TMDB depuis l’écran (écran de différences) : J2 (§ 3.7). Au J1, aucun geste ne fait passer un film à « Bloqué ».
- Clore un balayage suspendu (`admin.import.abandon`) : J2 (§ 3.8). Au J1, un balayage suspendu depuis plus de 5 min n’empêche simplement plus d’en lancer un autre.
- Reprendre un collage interrompu depuis l’écran : jamais, puisque la liste collée n’est stockée nulle part. On recolle la liste.
- Réservation ou verrou d’un film entre curateurs : J2 (§ 4.1). Le battement `catalog.heartbeat` ne mesure que le temps actif et ne verrouille rien.
- Candidats de regroupement par distance de titre : J2 (§ 9.4). Au J1, seuls les titres identiques et la saisie manuelle d’un identifiant existent.
- Thèmes au back-office (appartenance manuelle, sagas, libellés) et correction manuelle de la difficulté : J2 (§ 9.6). L’onglet « Thèmes » de la fiche est en lecture seule.
- Quasi-justes et rapport de collisions à l’écran : J2 (§ 9.5).
- Films jamais trouvés, incidents, inspection d’une partie, demandes de retrait, modération des pseudos et des photos : J2 (§ 11, § 12).
- Écran listant les lignes `admin_action` des gestes de catalogue : non livré. Seuls existent le compteur « Lignes du journal d’administration » sur la fiche d’un compte (admin) et l’historique des accès.
- Supprimer un film, une image, une revue ou un balayage, décocher « Contenu vérifié », lever « Bloqué » : jamais, par conception (aucune route).
- Back-office en anglais : jamais (français seul, décision 9).
- Pages `admin/error` pour le 404 de liaison, le 429 des limiteurs et le 419 : non livrées, question ouverte au porteur (E62-6). La page joueur s’affiche à la place, ce que la checklist vérifie tel quel.
- Limiteur TMDB partagé entre plusieurs workers : J2 (§ 3.6).
- Banque d’images, recadreur, voie capture, file et passe de revue, annuaire des comptes et gestion des accès : couverts par d’autres sections de la checklist.

## 8. Back-office : banque d’images, recadreur, capture, revue et publication

Cette section couvre l’éditeur de la banque d’un film (/admin/catalog/{id}/bank). On y teste les visuels TMDB proposés (ceux porteurs d’une langue sont écartés), le recadreur 16:9 à la souris et au clavier, la voie capture, le traitement différé, les niveaux et variantes, la prévisualisation par N et les gestes sur une image. Viennent ensuite la file de revue (/admin/review) et la publication d’un film selon sa couverture 1/3/5. Les nombres cités (80 % de largeur maximale, 640 px de largeur minimale, 1 536 Ko, sondage toutes les 3 s, alerte de traitement bloqué après 10 min) sont les réglages par défaut de la plateforme.

**Prérequis**

- Démarrer la VM Homestead et vérifier que Redis répond, puis lancer `composer dev` (serveur sur http://127.0.0.1:8000, écouteur des files game puis default, Reverb, Vite). Les images sont traitées sur la file `default` : sans cet écouteur, une image reste « En traitement ».
- Base à jour : lancer `php artisan backup:snapshot` (code 0 exigé, règle 12), puis `php artisan migrate:fresh --seed`. On obtient 16 films de démonstration publiés, avec les niveaux 1 à 5 couverts et une variante par niveau. Seul « Snow White and the Seven Dwarfs » a une seconde variante aux niveaux 1 et 5, plus une image de niveau 2 rejetée en revue (point en défaut : « Ni affiche ni jaquette »). Aucun film de démonstration n’a d’identifiant TMDB.
- Comptes de démonstration, tous avec le mot de passe `password`, à ouvrir sur /login : curator@tripleframes.test (nom réel « Camille Démo-Curation »), admin@tripleframes.test (nom réel « Alex Démo-Administration », compte réglé en anglais) et player@tripleframes.test. La porte /admin exige une double authentification confirmée. Au premier passage, cliquer « Ouvrir la sécurité du compte », activer puis confirmer la double authentification avec une application TOTP, et cliquer « J’ai confirmé : entrer dans le back-office ».
- Voie TMDB, pour les items qui l’exigent : mettre une clé dans `.env` (`TMDB_API_READ_ACCESS_TOKEN` ou `TMDB_API_KEY`). Ouvrir ensuite /admin/import, section « Collage d’identifiants », coller `550`, cliquer « Importer ces identifiants » et attendre la fin du balayage (file default). Le film importé arrive en brouillon, sans image.
- Pour que les items ne se gênent pas, chacun vise un film de démonstration différent : The Lion King (lecture et captures), Snow White (lecture), The Shining (échecs de traitement), Finding Nemo, The Matrix, Ratatouille, Jurassic Park, Toy Story, Back to the Future, Inception, Star Wars: A New Hope, Pulp Fiction, Dune: Part Two. `php artisan migrate:fresh --seed` remet tout à neuf.
- Images de test, dans un dossier local : une capture d’écran en paysage d’au moins 1 280 px de large (Impr. écran sur un film en plein écran, 1920 × 1080), une image de moins de 1 280 px de large, une photo en portrait, un fichier texte renommé en `.png` et, si possible, un GIF animé en paysage. Générer aussi, dans Git Bash : `php -r '$i=new Imagick();$i->newPseudoImage(3840,2160,"plasma:");$i->writeImage("4k.png");'` (environ 40 Mo), puis `php -r '$i=new Imagick();$i->newImage(1920,1080,"gray");$i->addNoiseImage(Imagick::NOISE_RANDOM);$i->writeImage("bruit.png");'`, et la même commande en 1920,1920 pour `bruit-carre.png`.
- Navigateur de bureau récent (Chrome, Edge ou Firefox), fenêtre d’au moins 1 024 px de large : sous le point d’arrêt `lg`, le recadreur et le panneau de revue sont masqués. Garder les outils de développement ouverts : émulation 375 px, onglet Réseau (mode « Hors connexion », limitation « Slow 3G », blocage de requêtes) et inspecteur pour les champs cachés.
- Après toute modification de `.env`, lancer `php artisan config:clear`, puis relancer `composer dev`. Les pages d’erreur traduites (« Accès refusé », « Page introuvable ») ne s’affichent qu’avec `APP_DEBUG=false` ; avec `APP_DEBUG=true`, c’est la page d’erreur brute de Laravel.
- Règle 12 : les gestes faits par l’écran sur le catalogue de démonstration sont sans risque, et `php artisan migrate:fresh --seed` le remet à neuf. Toute écriture manuelle au catalogue par `tinker` (items du groupe 7) exige d’abord `php artisan backup:snapshot` (code 0).

### 8.1 Accès à l’éditeur de la banque

- [ ] **Ouvrir l’éditeur depuis la fiche film** ⭐
  - Faire : En curateur, aller sur /admin/catalog, ouvrir « The Lion King » et cliquer « Curer les images ». L’onglet « Banque d’images » de la fiche mène au même écran par son bouton « Ouvrir l’éditeur de la banque d’images ».
  - Attendu : L’URL devient /admin/catalog/{id}/bank et l’onglet du navigateur affiche « Banque d’images - TripleFrames ». Fil d’Ariane : « Tableau de bord », « Catalogue », « Banque d’images ». L’en-tête montre le titre « The Lion King », la description « Ajoutez des images depuis les visuels TMDB du film ou par une capture, classez-les de 1 à 5… » et le bouton « Retour à la fiche du film ». Suivent les badges « Publié » et « Contenu vérifié », puis « Année de sortie : 1994 ». Cinq cartes, dans cet ordre : « Visuels TMDB du film », « Recadrer et classer », « Banque du film », « Prévisualisation en conditions de jeu », « Publication du film ».
  - Réf. : 20 § 6.1 · FrameBankController@show, pages/admin/catalog/bank.tsx
- [ ] **Refuser l’éditeur au visiteur et au joueur** ⚠️
  - Faire : Dans une fenêtre privée, sans être connecté, ouvrir /admin/catalog/1/bank. Se connecter ensuite en player@tripleframes.test, puis rouvrir /admin/catalog/1/bank et /admin/catalog/999999/bank.
  - Attendu : Sans session : renvoi vers /login. En joueur, les deux adresses donnent le même 403, si bien qu’un film réel ne se distingue pas d’un film inconnu. Avec APP_DEBUG=false, la page d’erreur du site affiche « Accès refusé » et « Vous n’avez pas l’autorisation d’ouvrir cette page. ».
  - Réf. : 20 § 2.3 · routes/admin.php (role:curator avant SubstituteBindings)
- [ ] **Ouvrir la banque d’un film inconnu en curateur** ⚠️
  - Faire : En curateur, ouvrir /admin/catalog/999999/bank.
  - Attendu : Réponse 404 et page « Page introuvable » (avec APP_DEBUG=false). Aucune page de banque vide n’est rendue.
  - Réf. : 20 § 6.1 · liaison {movie}, can:curate,movie
- [ ] **Ouvrir l’éditeur en administrateur, toujours en français**
  - Faire : Se connecter en admin@tripleframes.test (compte réglé en anglais), puis ouvrir la banque d’un film de démonstration.
  - Attendu : L’écran est le même que pour le curateur. Tout le back-office reste en français (« Banque d’images », « Recadrer et classer », « Ajouter à la banque »…), quelle que soit la langue du compte.
  - Réf. : 20 § 13.2 · ForceAdminLocale
- [ ] **Voir la page s’afficher sans attendre TMDB**
  - Faire : Sur le film importé depuis TMDB, recharger l’éditeur avec l’onglet Réseau ouvert.
  - Attendu : La page s’affiche tout de suite. Dans « Visuels TMDB du film », trois lignes fantômes occupent d’abord la place (le texte « Chargement des visuels TMDB… » est réservé aux lecteurs d’écran). La grille arrive ensuite par une seconde requête (prop différée `backdrops`).
  - Réf. : 20 § 6.1 · Inertia::defer backdrops, AdminLoadingState

### 8.2 Visuels TMDB proposés (D39)

- [ ] **Afficher la grille des visuels d’un film importé** ⭐
  - Faire : Ouvrir la banque du film importé (550).
  - Attendu : La carte « Visuels TMDB du film » porte la description « Seuls sont proposés les visuels auxquels TMDB n’attache aucune langue : les autres peuvent contenir du texte… ». La grille ne montre que des fonds d’écran (jamais d’affiche ni de logo), dans l’ordre de TMDB. Chaque vignette a pour texte alternatif « Visuel i sur N ».
  - Réf. : 20 § 6.2 · components/admin/backdrop-grid.tsx
- [ ] **Compter les visuels écartés pour leur langue** ⭐
  - Faire : Sur le même film, lire la ligne au-dessus de la grille. La comparer à la fiche du film sur themoviedb.org, rubrique des fonds d’écran, filtrée par langue.
  - Attendu : La ligne dit « N visuels écartés : TMDB leur attache une langue, ils peuvent contenir du texte. » (au singulier : « 1 visuel écarté : TMDB lui attache une langue, il peut contenir du texte. »). N est le nombre de fonds d’écran qui portent une langue sur TMDB. Aucun d’eux n’apparaît dans la grille ni dans la bande.
  - Réf. : D39 du 28/09 · 20 § 6.2 · admin.bank.backdrops_excluded
- [ ] **Ouvrir la banque d’un film de démonstration (sans identifiant TMDB)**
  - Faire : Ouvrir la banque de « The Lion King ».
  - Attendu : À la place de la grille : « Aucun visuel TMDB à proposer : TMDB n’en fournit aucun pour ce film, ou le film n’a pas d’identifiant TMDB (catalogue de démonstration). ». Aucun compte de visuels écartés n’apparaît, et la bande « Visuels non utilisés » est absente.
  - Réf. : 20 § 6.2 · admin.bank.no_backdrops
- [ ] **Parcourir la grille au clavier**
  - Faire : Faire Tab jusqu’à la grille, puis utiliser les flèches, Origine et Fin. Presser Entrée sur une vignette, puis Espace sur une autre.
  - Attendu : La grille ne prend qu’un seul arrêt de tabulation. Les flèches déplacent le focus, qui reste visible. Entrée ou Espace ouvre le visuel dans le cadre : la vignette reçoit le badge « Ouvert dans le cadre » et une bordure, et le focus reste dans la grille.
  - Réf. : 20 § 6.4 · lib/admin/grid-navigation.ts
- [ ] **Voir un visuel trop étroit ou en portrait, grisé** ⚠️
  - Faire : Si le film a un fond d’écran de moins de 1 280 px de large, le repérer dans la grille (sinon, importer un film ancien qui en a un). Le focaliser, puis cliquer dessus.
  - Attendu : La vignette est grisée mais reste focalisable. Elle porte le motif « Ce visuel est en portrait, fait moins de 1280 pixels de large, ou ne laisse place à aucun cadre admis : il ne peut pas donner une image de jeu. Choisissez un autre visuel du film. ». Le clic n’ouvre rien, et ce visuel n’est pas dans la bande.
  - Réf. : 20 § 6.2 · FrameGeometry::acceptsSource, BACKDROP_REFUSAL
- [ ] **Voir l’état « TMDB non configuré »** ⚠️
  - Faire : Vider `TMDB_API_READ_ACCESS_TOKEN` et `TMDB_API_KEY` dans `.env`, lancer `php artisan config:clear`, relancer `composer dev`, puis `php artisan cache:forget admin:tmdb-backdrops:550`. Rouvrir la banque du film importé.
  - Attendu : La carte affiche « Import TMDB désactivé : aucune clé TMDB n’est configurée sur le serveur. Le jeu, lui, n’appelle jamais TMDB. », sans bouton « Réessayer ». Le reste de la page fonctionne. Remettre la clé ensuite.
  - Réf. : 20 § 6.2 · BACKDROPS_NOT_CONFIGURED
- [ ] **Voir une panne TMDB et réessayer** ⚠️
  - Faire : Remplacer la clé TMDB par une valeur fausse (dans chacune des variables renseignées), lancer `php artisan config:clear`, relancer `composer dev` et `php artisan cache:forget admin:tmdb-backdrops:550`, puis rouvrir la banque. Remettre ensuite la bonne clé (config:clear, relance) et cliquer « Réessayer ».
  - Attendu : Avec la clé fausse, la carte affiche « Les visuels du film n’ont pas pu être obtenus auprès de TMDB (service indisponible ou connexion interrompue). Réessayez dans un instant. » et un bouton « Réessayer », sans page d’erreur. « Réessayer » recharge la seule grille, qui réapparaît.
  - Réf. : 20 § 6.2, § 6.8 · BACKDROPS_FAILED
- [ ] **Voir un film dont tous les visuels portent une langue** ⚠️
  - Faire : Si un film sur TMDB n’a que des fonds d’écran marqués d’une langue, l’importer par collage et ouvrir sa banque.
  - Attendu : État vide : « Aucun visuel TMDB sans texte à proposer : TMDB attache une langue à tous les visuels de ce film. », suivi du compte « N visuels écartés… ».
  - Réf. : D39 du 28/09 · admin.bank.no_backdrops_all_text
- [ ] **Faire refuser par le serveur un visuel porteur d’une langue** ⚠️
  - Faire : Ouvrir un visuel proposé et choisir d’abord son niveau : chaque nouveau rendu de l’écran rétablit les champs cachés, donc aucune image ne doit être en traitement (sondage). Dans l’inspecteur, remplacer la valeur du champ caché `tmdb_file_path` par le chemin (`/xxxx.jpg`) d’un fond d’écran à langue du même film, relevé sur themoviedb.org. Cliquer aussitôt « Ajouter à la banque ».
  - Attendu : Sous le cadre : « Ce visuel peut contenir du texte : TMDB lui attache une langue. Choisissez un visuel sans texte, ou envoyez une capture. ». Aucune image n’est créée et rien ne part dans la file.
  - Réf. : D39 du 28/09 · 20 § 5.3 · FrameTmdbController (admin.frame.tmdb.with_text)
- [ ] **Faire refuser par le serveur un chemin étranger au film** ⚠️
  - Faire : Refaire la même manipulation en mettant dans `tmdb_file_path` d’abord `/inexistant.jpg`, puis `abc`.
  - Attendu : Les deux fois : « Ce visuel ne fait pas partie des visuels de ce film proposés par TMDB : choisissez-en un dans la grille. Une affiche ou un logo ne devient jamais une image de jeu. ». Aucune image n’est créée.
  - Réf. : 20 § 5.3 · FrameTmdbStoreRequest (regex), admin.frame.tmdb.not_a_backdrop

### 8.3 Recadreur 16:9 : souris, boutons, clavier (geste 11 b)

- [ ] **Vérifier le cadre par défaut d’un visuel 16:9** ⭐
  - Faire : Ouvrir dans « Recadrer et classer » un visuel 16:9 de la grille (ou une capture 1920 × 1080, groupe 5).
  - Attendu : Le visuel s’affiche avec un cadre centré et un voile hors du cadre. On lit « Cadre de 1 536 × 864 pixels, soit 64 % de la surface du visuel. C’est le cadre le plus large que le plancher de recadrage admet. ». « Plus large » est inactif.
  - Réf. : 20 § 5.2, § 6.3 · FrameGeometry::defaultCrop, D6 du 23/09
- [ ] **Utiliser les huit boutons du recadreur** ⭐
  - Faire : Cliquer plusieurs fois « Plus serré », puis les quatre flèches (« Déplacer le cadre vers la gauche », « … vers le haut », « … vers le bas », « … vers la droite »), puis « Centrer » et « Cadre par défaut ». Pousser le cadre jusqu’à un bord.
  - Attendu : Chaque clic change le cadre d’un pas (16 px dans l’espace du master de 1 920 px), et les dimensions et le pourcentage se mettent à jour. Au bord, la flèche correspondante devient inactive tout en restant focalisable. « Centrer » recentre le cadre, et « Cadre par défaut » le ramène à 1 536 × 864.
  - Réf. : 20 § 6.3 · L20-9a · frame-cropper.tsx
- [ ] **Atteindre le cadre le plus serré admis**
  - Faire : Resserrer jusqu’à la butée, avec « Plus serré » ou Maj + « - ».
  - Attendu : On lit « Cadre de 640 × 360 pixels, soit 11 % de la surface du visuel. C’est le cadre le plus serré admis : l’image de jeu ne peut pas être davantage agrandie. ». « Plus serré » devient inactif, et le cadre ne descend jamais sous 640 px.
  - Réf. : 20 § 5.2 · PlatformLimits::frameCropMinWidthPx (640 par défaut)
- [ ] **Déplacer le cadre à la souris**
  - Faire : Resserrer un peu le cadre, puis faire glisser son intérieur dans toutes les directions, jusqu’à dépasser les bords du visuel.
  - Attendu : Le curseur prend la forme de déplacement et le cadre suit le pointeur. Il reste toujours entier à l’intérieur du visuel.
  - Réf. : 20 § 6.3 · L20-9a
- [ ] **Redimensionner par les poignées d’angle**
  - Faire : Tirer chacune des quatre poignées carrées, vers l’extérieur puis vers l’intérieur, y compris au-delà du coin opposé.
  - Attendu : Le coin opposé reste fixe, le ratio 16:9 est verrouillé et la largeur avance par multiples de 16. Le cadre ne dépasse ni 1 536 px, ni les bords, ni la butée de 640 px, et ne se retourne jamais.
  - Réf. : 20 § 6.3 · L20-9b · crop-state.ts resizeFromCorner
- [ ] **Tester Ctrl + molette et la molette seule**
  - Faire : Cliquer dans le cadre pour le sélectionner, puis faire Ctrl + molette vers le haut, puis vers le bas. Faire ensuite la molette seule sur le cadre. Cliquer hors du cadre et refaire Ctrl + molette.
  - Attendu : Sur le cadre sélectionné, Ctrl + molette vers le haut l’élargit et vers le bas le resserre (environ 10 % par cran), sans zoomer la page. La molette seule fait défiler la page. Hors d’un cadre sélectionné, Ctrl + molette reste le zoom du navigateur.
  - Réf. : 20 § 6.3 · cropWheelStep
- [ ] **Pincer sur le pavé tactile ou l’écran tactile** ⚠️
  - Faire : Le cadre sélectionné, pincer à deux doigts sur le pavé tactile (Chrome, Edge ou Firefox), ou sur un écran tactile, les deux doigts posés sur le cadre.
  - Attendu : L’écartement des doigts règle la largeur autour du centre. Lever un doigt clôt le geste sans faire sauter le cadre. Sur Safari macOS, le pincement zoome la page : c’est attendu.
  - Réf. : 20 § 6.3 · cropPinchCommand
- [ ] **Déplacer le cadre au clavier** ⭐
  - Faire : Faire Tab jusqu’au cadre (anneau de focus visible), presser les flèches, puis Maj + flèches.
  - Attendu : Une flèche déplace le cadre de 16 px du master, et Maj + flèche de 4 pas (64 px). La page ne défile pas pendant ces gestes. Au bord, le bouton fléché du même sens devient inactif.
  - Réf. : 20 § 6.4 · lib/admin/crop-state.ts cropKeyCommand
- [ ] **Vérifier la lecture des touches + - = _ et Maj (E43-2)** ⭐
  - Faire : Le cadre focalisé, sur un clavier AZERTY : presser « = » (un pas plus large), puis « + » (Maj + =). Presser ensuite « - » (touche 6), Maj + touche 6, puis « _ » (touche 8). Essayer enfin « + » et « - » du pavé numérique, avec et sans Maj.
  - Attendu : « = » élargit d’un pas et « + » de 4 pas. « - » resserre d’un pas, Maj + touche 6 de 4 pas, et « _ » (touche 8) d’un pas. Au pavé numérique, chaque touche vaut un pas, et 4 avec Maj. Noter si cette lecture convient (point ouvert E43-2).
  - Réf. : 20 § 6.4 · E43-2 · cropKeyCommand
- [ ] **Rétablir le cadre par Origine et laisser Ctrl + + au navigateur**
  - Faire : Le cadre focalisé et déplacé, presser Origine. Presser ensuite Ctrl + « + », puis Ctrl + « 0 ».
  - Attendu : Origine rétablit le cadre par défaut, centré. Ctrl + « + » zoome la page sans toucher au cadre : aucune combinaison avec Ctrl, Alt ou Méta n’est un geste du recadreur.
  - Réf. : 20 § 6.4 · cropKeyCommand
- [ ] **Ouvrir les deux aides du recadreur**
  - Faire : Survoler, puis focaliser au clavier, le bouton « ? » et le bouton clavier placés sous les commandes du cadre.
  - Attendu : Le « ? » affiche « Au clavier, une fois le cadre sélectionné : les flèches le déplacent ; « + » ou « = » l’élargit, « - » le resserre ; avec Maj, chaque geste compte 4 pas ; Origine rétablit le cadre par défaut. À la souris… ». Le bouton clavier affiche « Raccourcis : Page précédente et Page suivante changent de visuel ; 1 à 5 classent et ajoutent l’image depuis le cadre sélectionné. [ et ] restent disponibles. ».
  - Réf. : 20 § 6.4 · admin.cropper.instructions, admin.shortcuts.cropper_hint
- [ ] **Voir l’échec d’affichage d’un visuel TMDB et réessayer** ⚠️
  - Faire : Dans l’onglet Réseau, bloquer le domaine `image.tmdb.org`, puis ouvrir un visuel pas encore affiché. Débloquer le domaine et cliquer « Réessayer » dans le cadre.
  - Attendu : Le cadre affiche « Le visuel n’a pas pu être affiché » et « Le serveur d’images de TMDB n’a pas répondu, ou la connexion est interrompue. Réessayez ; si l’échec persiste, choisissez un autre visuel du film. », avec « Réessayer ». Une fois le domaine débloqué, le visuel s’affiche et le cadre se manipule.
  - Réf. : 20 § 6.3, § 6.8 · frame-cropper.tsx
- [ ] **Faire revalider le plancher par le serveur (D6)** ⚠️
  - Faire : Ouvrir un visuel TMDB et choisir d’abord son niveau (un nouveau rendu rétablirait les champs cachés). Dans l’inspecteur, mettre `crop_width`=1920, `crop_height`=1080, `crop_x`=0 et `crop_y`=0, puis cliquer aussitôt « Ajouter à la banque ». Recommencer avec 320 / 180, puis avec `crop_height`=800 sur une largeur de 1536, puis avec `crop_x`=1000 sur le cadre par défaut.
  - Attendu : Sous le cadre, dans l’ordre : « Le cadre couvre une trop grande part du visuel : resserrez-le. Une image de jeu ne reprend jamais le visuel presque entier. », « Le cadre est trop serré : élargissez-le, l’image de jeu serait trop agrandie. », « Le cadre doit être exactement au format 16:9. » et « Le cadre dépasse du visuel : ramenez-le entièrement à l’intérieur. ». Aucune image n’est créée.
  - Réf. : 20 § 5.2 · FrameCropValidationRules, FrameGeometry::violation

### 8.4 Niveau, ajout d’une image TMDB et bande des visuels

- [ ] **Voir qu’aucun niveau n’est pré-coché** ⭐
  - Faire : Ouvrir un visuel sans toucher au groupe « Niveau de l’image », puis cliquer « Ajouter à la banque ». Survoler ensuite l’icône d’information d’un niveau.
  - Attendu : Aucune des cinq options (« 1 Très cryptique » … « 5 Évident ») n’est cochée. Le bouton est grisé, le clic ne fait rien, et la page affiche « Choisissez un niveau avant d’ajouter l’image : aucun n’est coché d’avance. ». L’info-bulle donne le guide du niveau, par exemple pour 1 : « Un détail, une texture, une matière, un second plan : rien qui se nomme sans avoir vu le film de près. Aucun visage du personnage principal. ».
  - Réf. : 20 § 6.5 · components/admin/level-picker.tsx
- [ ] **Ajouter une image TMDB à la banque** ⭐
  - Faire : Sur un visuel ouvert, cocher « 3 Intermédiaire » (au clic, ou aux flèches dans le groupe), puis cliquer « Ajouter à la banque ».
  - Attendu : Le bouton passe à « Ajout en cours… », puis un toast dit « Image ajoutée : elle est en traitement et apparaîtra dans la banque du film dès qu’elle sera prête. ». Le visuel suivant de la bande s’ouvre dans le cadre, qui garde le focus, sans niveau coché, et la région d’état annonce « Image ajoutée. Visuel k sur N ouvert dans le cadre. ». Dans la grille, le visuel envoyé porte « Déjà utilisé (niveaux 3) ».
  - Réf. : 20 § 5.3, § 6.3 · FrameTmdbController@store, AddFrame::fromTmdb
- [ ] **Classer et envoyer d’une touche (1 à 5)**
  - Faire : Le cadre d’un visuel focalisé, presser « 5 » au pavé numérique. Sur un autre visuel, presser la touche « 1 » de la rangée du haut sans Maj (elle rend « & » en AZERTY). Focaliser enfin une option du groupe de niveaux et presser « 2 ».
  - Attendu : Le niveau se coche, la région d’état annonce « Niveau 5 — Évident : ajout de l’image à la banque. », puis l’envoi part comme par le bouton. La touche « & / 1 » classe au niveau 1. Depuis le groupe de niveaux, un bouton ou un champ, aucun chiffre ne déclenche d’envoi.
  - Réf. : 20 § 6.4 · L20-11 · lib/admin/shortcut-map.ts
- [ ] **Parcourir la bande « Visuels non utilisés »**
  - Faire : Sous le recadreur, cliquer « Visuel précédent » et « Visuel suivant ». Depuis le cadre, presser Page précédente et Page suivante, puis « [ » et « ] » (AltGr + ( et AltGr + ) en AZERTY). Faire glisser une vignette de la bande sur au moins 48 px à l’horizontale, puis presser Page suivante sur le dernier visuel de la bande.
  - Attendu : Le visuel voisin s’ouvre dans le cadre, et la ligne « Visuel i sur n de la bande » se met à jour. Au bord, le bouton de ce côté devient inactif. Une touche pressée au bord annonce « Aucun visuel non utilisé après celui-ci. » (ou « … avant celui-ci. »). Les visuels déjà utilisés ou grisés ne sont jamais dans la bande.
  - Réf. : 20 § 6.2 · lib/admin/backdrop-strip.ts, backdrop-strip.tsx
- [ ] **Ouvrir un visuel déjà utilisé et épuiser la bande** ⚠️
  - Faire : Ouvrir depuis la grille un visuel marqué « Déjà utilisé ». Ajouter ensuite, l’un après l’autre, les visuels restants de la bande jusqu’au dernier.
  - Attendu : Pour le visuel déjà utilisé, la bande dit « Le visuel ouvert a déjà servi : il n’est pas dans la bande. ». Après le dernier ajout, le cadre se ferme, le focus revient à la grille et l’annonce dit « Image ajoutée. Plus aucun visuel non utilisé ne peut s’ouvrir dans le cadre : choisissez un visuel dans la grille pour une autre variante. ». La bande affiche alors « Aucun visuel n’attend dans la bande… ».
  - Réf. : 20 § 6.2 · admin.bank.strip.*
- [ ] **Tirer une seconde variante d’un même visuel avec un autre cadre**
  - Faire : Rouvrir depuis la grille un visuel déjà envoyé au niveau 3. Déplacer ou resserrer le cadre, choisir le niveau 5, puis ajouter.
  - Attendu : L’ajout est accepté : une même source recadrée autrement est une nouvelle variante. Le badge devient « Déjà utilisé (niveaux 3 · 5) ».
  - Réf. : 20 § 5.3 · AddFrame::hasTwin (source_hash + rectangle)
- [ ] **Faire refuser un doublon (même visuel, même cadre)** ⚠️
  - Faire : Rouvrir un visuel déjà envoyé, garder le cadre par défaut, choisir un niveau et ajouter.
  - Attendu : Sous le cadre : « Cette image existe déjà dans la banque du film : même visuel, même cadre. Déplacez ou redimensionnez le cadre pour créer une autre variante. ». Aucune nouvelle image n’apparaît dans la banque.
  - Réf. : 20 § 5.3 · admin.frame.tmdb.duplicate
- [ ] **Vérifier que le cadre reste figé pendant un envoi** ⚠️
  - Faire : Dans l’onglet Réseau, activer la limitation « Slow 3G ». Cliquer « Ajouter à la banque », puis, pendant l’envoi, cliquer un autre visuel de la grille ou de la bande.
  - Attendu : Pendant l’envoi, le cadre ne change pas : l’autre visuel ne s’ouvre pas, la bande est inactive et le cadre ne se manipule plus. À la réponse, l’enchaînement normal reprend.
  - Réf. : 20 § 6.3 · openVisual (sending)
- [ ] **Fermer un visuel**
  - Faire : Ouvrir un visuel au clavier depuis la grille, puis cliquer « Fermer ce visuel ».
  - Attendu : Le cadre disparaît au profit de « Choisissez un visuel dans la grille, ou envoyez une capture, pour l’ouvrir dans le cadre. », et le focus revient dans la grille.
  - Réf. : 20 § 6.1 · closeVisual

### 8.5 Voie capture de bout en bout (D38, geste 11 d)

- [ ] **Repérer le point d’entrée de la capture** ⭐
  - Faire : Ouvrir la banque de « The Lion King » dans une fenêtre large.
  - Attendu : Au-dessus du recadreur, un encadré « Envoyer une capture » dit « Une capture est une image du film que vous avez prise vous-même. Son minutage dans le film est obligatoire : il signe la source que votre revue déclarera. ». Il propose le bouton « Choisir une image » et le texte « ou collez-la depuis le presse-papiers (Ctrl + V) ».
  - Réf. : 20 § 5.4, § 6.3 · components/admin/capture-source-picker.tsx
- [ ] **Choisir une image au format valide** ⭐
  - Faire : Cliquer « Choisir une image » et sélectionner la capture 1920 × 1080.
  - Attendu : « Préparation de l’image… » s’affiche brièvement, puis la capture s’ouvre dans le même cadre (1 536 × 864, 64 %), qui prend le focus. Apparaissent le badge « Capture » et le champ « Minutage dans le film », avec l’indication « Heures, minutes et secondes : par exemple 0:12:34. ». Aucun niveau n’est coché, et la région d’état dit « Capture ouverte dans le cadre : choisissez son niveau et saisissez son minutage. ». Le bouton de fermeture s’appelle « Abandonner la capture ».
  - Réf. : 20 § 6.3 · bank.tsx openCapture, lib/admin/capture-encoder.ts
- [ ] **Coller une capture avec Ctrl + V** ⭐
  - Faire : Faire Impr. écran sur un film en plein écran. Revenir à l’éditeur et presser Ctrl + V hors de tout champ.
  - Attendu : La capture collée s’ouvre dans le cadre comme une image choisie, avec un minutage vide et aucun niveau coché.
  - Réf. : 20 § 6.3 · handlePaste, pickClipboardImage
- [ ] **Vérifier que le minutage est obligatoire et bien formé** ⭐
  - Faire : Sur une capture ouverte, choisir un niveau, laisser le minutage vide et cliquer « Ajouter à la banque ». Saisir ensuite tour à tour « 12:34 », « 0:60:00 », « 0:5:00 » et « abc », en quittant le champ après chacun.
  - Attendu : Le bouton reste inactif. La tentative d’envoi, puis chaque sortie d’un champ rempli, affiche sous le champ « Saisissez le minutage en heures, minutes et secondes, par exemple 0:12:34 : les minutes et les secondes vont de 00 à 59. ». Rien ne s’affiche pendant la frappe, et aucune requête ne part.
  - Réf. : 20 § 5.4 · parseTimecode, TIMECODE_PATTERN
- [ ] **Envoyer une capture** ⭐
  - Faire : Saisir « 0:12:34 », cocher « 1 Très cryptique », puis cliquer « Ajouter à la banque ».
  - Attendu : Un toast dit « Image ajoutée : elle est en traitement et apparaîtra dans la banque du film dès qu’elle sera prête. ». Le cadre se ferme, le focus revient à « Choisir une image », la région d’état dit « Capture ajoutée : elle part en traitement. », et aucun visuel ne s’ouvre de lui-même. Dans « Banque du film », une carte apparaît au niveau 1 avec « Source : capture personnelle » et « En traitement ».
  - Réf. : 20 § 5.4 · FrameCaptureController@store, AddFrame::fromCapture
- [ ] **Vérifier les formes de minutage admises**
  - Faire : Envoyer trois captures différentes, aux minutages « 1:02:03 », «  0:00:05  » (avec des espaces autour) et « 00:12:34 ». Ouvrir ensuite leur revue dans /admin/review, une fois leur traitement fini.
  - Attendu : Les trois envois sont acceptés. En revue, la source déclarée se lit « 1:02:03 », « 0:00:05 » et « 0:12:34 » : les heures n’ont pas de zéro de tête.
  - Réf. : 20 § 5.4 · FrameCaptureStoreRequest::timecodeMs, ReviewQueue::timecode
- [ ] **Vérifier la normalisation par le navigateur (R-46)**
  - Faire : Onglet Réseau ouvert, choisir `4k.png` (environ 40 Mo, bien au-delà des 2 Mo que PHP admet), saisir un minutage, choisir un niveau et envoyer. Inspecter la requête POST vers /admin/catalog/{id}/frames/capture.
  - Attendu : Aucun « 419 page expirée » ni refus de poids. La requête est un envoi multipart dont le champ `source` est `capture.webp` (type image/webp, 1 920 px de large, au plus 1 536 Ko). L’accompagnent `source_timecode`, `frame_level`, `crop_x`, `crop_y`, `crop_width`, `crop_height` et `crop_seconds`. Le fichier d’origine n’est jamais envoyé.
  - Réf. : 20 § 5.4 · R-46 · capture-encoder.ts normalizeCapture
- [ ] **Faire refuser une capture trop petite ou en portrait** ⚠️
  - Faire : Choisir l’image de moins de 1 280 px de large, puis la photo en portrait.
  - Attendu : Rien ne s’ouvre. Sous le bouton : « Cette image est en portrait, fait moins de 1 280 pixels de large, ou ne laisse place à aucun cadre admis : elle ne peut pas donner une image de jeu. Choisissez une capture plus grande, en paysage. ».
  - Réf. : 20 § 5.4 · admin.frame.capture.ui.too_small
- [ ] **Faire refuser un fichier illisible** ⚠️
  - Faire : Choisir le fichier texte renommé en `.png`.
  - Attendu : Le message « Cette image n’a pas pu être lue : choisissez un autre fichier d’image, ou copiez-la de nouveau. » s’affiche, et aucune capture ne s’ouvre.
  - Réf. : 20 § 5.4 · admin.frame.capture.ui.unreadable
- [ ] **Faire refuser une capture trop lourde dès le navigateur** ⚠️
  - Faire : Choisir `bruit-carre.png` (1920 × 1920, bruit aléatoire).
  - Attendu : Après la préparation : « Même compressée au plus bas, cette image dépasse 1 536 Ko, le poids maximal d’un envoi : choisissez une capture moins chargée. ». Rien ne part au serveur.
  - Réf. : 20 § 5.4 · encodeUnderCeiling (frameUploadMaxKilobytes = 1 536 par défaut)
- [ ] **Essayer un navigateur qui n’encode pas le WebP (Safari)** ⚠️
  - Faire : Sur Safari macOS, choisir une capture valide.
  - Attendu : Le message « Ce navigateur ne sait pas préparer l’image au format attendu : utilisez un navigateur récent, sur ordinateur. » s’affiche, et rien n’est envoyé.
  - Réf. : 20 § 5.4 · admin.frame.capture.ui.unsupported
- [ ] **Choisir une image animée (GIF ou WebP animé)** ⚠️
  - Faire : Choisir un GIF animé en paysage de plus de 1 280 px de large, puis l’envoyer avec un minutage et un niveau.
  - Attendu : Le navigateur n’en garde qu’une image fixe (la première), qui s’ouvre et part normalement. Le refus serveur « Cette capture est une image animée… » ne vise qu’un envoi forgé : l’écran ne peut pas le produire.
  - Réf. : 20 § 5.4 · canevas de normalizeCapture, FrameCaptureStoreRequest::notAnimated
- [ ] **Presser un raccourci 1 à 5 sans minutage**
  - Faire : Sur une capture ouverte au minutage vide, le cadre focalisé, presser « 3 ». Saisir ensuite un minutage valide, revenir sur le cadre et presser « 3 ».
  - Attendu : Le premier appui coche le niveau 3 et annonce « Niveau 3 — Intermédiaire choisi. L’ajout attend le minutage de la capture. », avec le refus affiché sous le champ, et rien n’est envoyé. Une fois le minutage valide, « 3 » envoie la capture. Taper des chiffres dans le champ de minutage ne déclenche jamais de raccourci.
  - Réf. : 20 § 6.3 · classify(), canSend
- [ ] **Tenter de quitter une capture par la bande** ⚠️
  - Faire : Sur un film importé, ouvrir une capture. Presser « ] » puis Page suivante depuis le cadre. Cliquer ensuite « Visuel suivant » sous la bande, ou faire glisser la bande.
  - Attendu : Depuis le cadre, les touches ne font rien : la capture reste ouverte, et Page suivante fait défiler la page. « Visuel suivant » ou un glissement laissent aussi la capture ouverte, et la région d’état annonce « La capture reste ouverte : choisissez un visuel dans la grille, ou abandonnez la capture, pour passer à un visuel TMDB. ».
  - Réf. : 20 § 6.3 · stepVisual (strip_locked), bank.tsx handleCropperShortcut
- [ ] **Abandonner ou remplacer une capture**
  - Faire : Ouvrir une capture et cliquer « Abandonner la capture ». En ouvrir une deuxième, puis cliquer un visuel dans la grille ou une vignette de la bande (film importé). En ouvrir une troisième et coller une autre image (Ctrl + V).
  - Attendu : « Abandonner la capture » ferme le cadre et rend le focus à « Choisir une image ». Un visuel de la grille ou une vignette de la bande remplace la capture. Une nouvelle capture remplace la précédente avec un formulaire vierge : ni niveau, ni minutage, ni erreur.
  - Réf. : 20 § 6.3 · openVisual, holdCaptureUrl, OpenedVisual
- [ ] **Vérifier les collages que l’écran doit ignorer** ⚠️
  - Faire : a) Copier une plage de cellules dans un tableur et la coller dans le champ de minutage. b) Ouvrir une boîte « Changer le niveau de l’image » et coller une image. c) Coller une image pendant un envoi en « Slow 3G ». d) Coller une image avec une fenêtre de 375 px de large.
  - Attendu : a) Le texte arrive dans le champ, sans ouvrir de capture. b), c) et d) : rien ne se passe, ni capture ouverte ni erreur.
  - Réf. : 20 § 6.3 · handlePaste (isEditableTarget, gesture, sending, bouton masqué)
- [ ] **Faire refuser une capture en double** ⚠️
  - Faire : Envoyer une capture avec le cadre par défaut. Choisir de nouveau le même fichier sans toucher au cadre, avec un minutage et un niveau, puis l’envoyer.
  - Attendu : Sous le cadre : « Cette image existe déjà dans la banque du film : même capture, même cadre. Déplacez ou redimensionnez le cadre pour créer une autre variante. ». Aucune nouvelle carte n’apparaît.
  - Réf. : 20 § 5.4 · admin.frame.capture.duplicate

### 8.6 Interrupteur CURATION_CAPTURE_ENABLED

- [ ] **Fermer la voie capture**
  - Faire : Mettre `CURATION_CAPTURE_ENABLED=false` dans `.env`, lancer `php artisan config:clear` et relancer `composer dev`. Recharger la banque d’un film, du film importé sans image si possible. Presser Ctrl + V avec une image dans le presse-papiers.
  - Attendu : La description devient « Ajoutez des images depuis les visuels TMDB du film, classez-les de 1 à 5… », et la note « L’envoi de captures est fermé sur ce site : seuls les visuels TMDB du film peuvent devenir des images de jeu. » apparaît. « Choisir une image » a disparu, et le cadre vide dit « Choisissez un visuel dans la grille pour l’ouvrir dans le cadre. ». Une banque vide dit « Aucune image : ajoutez-en une depuis les visuels TMDB du film. ». Ctrl + V ne fait rien.
  - Réf. : 20 § 5.4 · config/catalog.php curation.capture_enabled, FrameBankController (captureEnabled)
- [ ] **Vérifier que le serveur refuse aussi la route capture** ⚠️
  - Faire : Garder un onglet de l’éditeur ouvert, voie ouverte, sans le recharger. Fermer la voie comme ci-dessus. Dans cet ancien onglet, choisir une capture, la classer, la minuter, puis l’envoyer.
  - Attendu : La requête reçoit un 403. Avec APP_DEBUG=false, l’éditeur laisse place à la page du back-office « Accès refusé », avec « Cet écran ou ce geste est réservé à un autre rôle. Si vous pensez devoir y accéder, adressez-vous à l’administrateur. ». Avec APP_DEBUG=true, la page d’erreur de Laravel montre le motif sous forme de clé brute `admin.frame.capture.disabled`, non traduite. Après rechargement, aucune image n’a été créée.
  - Réf. : 20 § 5.4 · FramePolicy::createFromCapture (CAPTURE_DISABLED)
- [ ] **Voir le refus de visuel à langue adapté à la voie fermée** ⚠️
  - Faire : Voie capture fermée, refaire la manipulation du groupe 2 : champ caché `tmdb_file_path` remplacé par le chemin d’un fond d’écran à langue du film importé.
  - Attendu : Sous le cadre : « Ce visuel peut contenir du texte : TMDB lui attache une langue. Choisissez un visuel sans texte. ». Le message ne propose plus d’envoyer une capture.
  - Réf. : D39 du 28/09 · FrameTmdbController (admin.frame.tmdb.with_text_no_capture)
- [ ] **Rouvrir la voie avec une valeur vide**
  - Faire : Remettre `CURATION_CAPTURE_ENABLED=` (vide), ou supprimer la ligne, puis lancer config:clear et relancer `composer dev`.
  - Attendu : « Choisir une image » et le collage reviennent : une valeur vide ou absente vaut « ouverte ».
  - Réf. : D38 du 28/09 · 20 § 5.4

### 8.7 Traitement différé ProcessFrameImage (file default)

- [ ] **Suivre une image de « En traitement » à « En attente de revue »** ⭐
  - Faire : Ajouter une capture à « The Lion King » sans recharger la page, en regardant la carte, le tableau de couverture et le terminal de `composer dev`.
  - Attendu : La carte affiche d’abord « En traitement » et « Rendu final indisponible : l’image n’est pas encore traitée, ou son chargement a échoué. », sous l’avis « Des images sont en traitement : cet écran se met à jour de lui-même, inutile d’attendre pour continuer. ». La colonne « En traitement » du niveau vaut 1. Quelques secondes plus tard, sans rechargement (sondage toutes les 3 s par défaut), la carte passe à « En attente de revue » avec son rendu final, et la colonne « En attente de revue » augmente. Le terminal montre ProcessFrameImage exécuté.
  - Réf. : 20 § 5.5, § 6.1 · ProcessFrameImage, usePoll
- [ ] **Lire l’état de traitement sur la fiche film**
  - Faire : Pendant puis après le traitement, ouvrir la fiche du film, onglet « Banque d’images ».
  - Attendu : Le tableau a pour colonnes « Niveau », « Disponibilité », « Traitement » et « Erreur ». L’image y est « Brouillon » et « En attente », puis « Prête », avec « Aucun » dans la colonne « Erreur ».
  - Réf. : 20 § 4.3 · admin.enum.frame_processing
- [ ] **Voir l’alerte « traitement bloqué »** ⚠️
  - Faire : Arrêter `composer dev`, puis lancer à part `php artisan serve`, `php artisan reverb:start` et `npm run dev`, sans écouteur de file. Ajouter une image et attendre 10 minutes. Lancer ensuite `php artisan queue:listen --queue=game,default --tries=1 --timeout=900`.
  - Attendu : L’image reste « En traitement ». Au bout de 10 minutes, l’alerte rouge « Le traitement d’arrière-plan ne répond pas : une image attend son rendu depuis trop longtemps. Vous pouvez continuer à curer ; si l’attente se prolonge, prévenez l’administrateur du site. » apparaît en haut de la page. Une fois l’écouteur relancé, l’image est traitée et l’alerte disparaît d’elle-même.
  - Réf. : 20 § 13.5 · FrameBankSnapshot::processingStalled (stale_pending_minutes = 10 par défaut)
- [ ] **Provoquer un échec non rejouable (image trop chargée)** ⚠️
  - Faire : Envoyer `bruit.png` (1920 × 1080, bruit aléatoire) comme capture, avec un minutage et le niveau 4. À défaut, lancer `php artisan backup:snapshot`, puis `php artisan tinker --execute="App\Models\Frame::factory()->for(App\Models\Movie::where('title_original','The Shining')->first())->level(App\Enums\FrameLevel::Level4)->processingFailed(App\Enums\FrameProcessingFailure::TooHeavy)->create();"`.
  - Attendu : Le navigateur accepte l’image, puis le traitement échoue. La carte passe à « En échec » avec « Même à la qualité la plus basse admise, l’image dépasse le poids maximal d’une image de jeu : si elle a déjà été traitée, re-recadrez-la… ». Ni « Relancer » ni « Re-recadrer » ne sont proposés, seulement « Changer de niveau » et « Écarter ». La colonne « En échec » vaut 1.
  - Réf. : 20 § 5.5, § 5.6 · FrameProcessingFailure::TooHeavy
- [ ] **Relancer un échec rejouable** ⚠️
  - Faire : Lancer `php artisan backup:snapshot` (règle 12), puis `php artisan tinker --execute="App\Models\Frame::factory()->for(App\Models\Movie::where('title_original','The Shining')->first())->level(App\Enums\FrameLevel::Level4)->processingFailed()->create();"`. Ouvrir la banque de « The Shining » et cliquer « Relancer » sur l’image en échec.
  - Attendu : Avant : « En échec » et « Le traitement de l’image a échoué pour une raison imprévue, et l’incident est consigné : relancez le traitement. », avec le bouton « Relancer ». Après, un toast dit « Traitement relancé : l’image apparaîtra dans la banque du film dès qu’elle sera prête. », et la carte passe par « En traitement » puis « En attente de revue ».
  - Réf. : 20 § 5.6 · FrameRetryController, RetryFrameProcessing (frames.retry)
- [ ] **Voir l’échec du rechargement automatique** ⚠️
  - Faire : Pendant qu’une image est « En traitement » (écouteur arrêté), passer l’onglet Réseau en « Hors connexion » quelques secondes, puis revenir en ligne. Cliquer « Réessayer » sous « Banque du film » si le message est encore là.
  - Attendu : Sous la banque : « La mise à jour automatique de la banque a échoué. », avec « Réessayer ». Aucun toast ne s’empile à chaque tick. Revenu en ligne, le message disparaît au premier rechargement réussi, qu’il vienne du sondage ou de « Réessayer ».
  - Réf. : 20 § 6.8 · usePoll onNetworkError
- [ ] **Perdre la connexion pendant un envoi** ⚠️
  - Faire : Préparer un ajout (capture minutée et classée), passer « Hors connexion », puis cliquer « Ajouter à la banque ».
  - Attendu : Un seul toast : « Connexion perdue : rien n’a été envoyé et votre saisie est conservée. Vérifiez votre réseau, puis réessayez. ». Le cadre, le niveau et le minutage restent en place. De retour en ligne, le même clic envoie.
  - Réf. : 20 § 6.8 · admin.common.offline

### 8.8 Banque du film : états, niveaux, variantes et gestes

- [ ] **Lire la banque groupée par niveau** ⭐
  - Faire : Ouvrir la banque de « Snow White and the Seven Dwarfs ».
  - Attendu : Cinq sections, de « Niveau 1 — Très cryptique » à « Niveau 5 — Évident ». Aux niveaux 1 et 5, deux cartes : « Image 1 du niveau 1 », « Image 2 du niveau 1 »… Chaque carte montre son rendu final, un badge d’état et « Source : visuel TMDB ». Le badge vaut « En jeu », sauf pour « Image 2 du niveau 2 », qui porte « Rejetée en revue ». Chaque carte a ses gestes ; pour l’image rejetée : « Re-recadrer », « Changer de niveau » et « Écarter ».
  - Réf. : 20 § 6.1 · components/admin/frame-bank-list.tsx, FrameCurationState
- [ ] **Lire la couverture, les passes et les variantes uniques** ⭐
  - Faire : Comparer la section « Couverture par niveau » de « Snow White and the Seven Dwarfs » et celle de « Toy Story ».
  - Attendu : Colonnes : « Niveau », « Jouables / cible », « En traitement », « En attente de revue », « Rejetées », « En échec ». Snow White : 2 / 2 aux niveaux 1 et 5, 1 / 2 et « Variante unique » au niveau 3, 1 / 1 aux niveaux 2 et 4, et 1 rejetée au niveau 2. Son bandeau dit « Passe 2 en cours : une deuxième variante aux niveaux 1, 3 et 5, et une variante aux niveaux 2 et 4. C’est un objectif de curation, jamais une condition de publication. ». Toy Story : « Variante unique » aux niveaux 1, 3 et 5.
  - Réf. : 20 § 6.6 · components/admin/coverage-meter.tsx, FrameBankSnapshot::coverage
- [ ] **Changer le niveau d’une image hors jeu**
  - Faire : Sur une capture « En attente de revue » de « The Lion King », cliquer « Changer de niveau ». Choisir un autre niveau, puis « Enregistrer le niveau ».
  - Attendu : La boîte « Changer le niveau de l’image » présélectionne le niveau actuel et affiche « Choisissez un niveau différent du niveau actuel. », avec « Enregistrer le niveau » inactif. Après l’envoi, un toast dit « Niveau de l’image enregistré. », et l’image passe dans la section de son nouveau niveau.
  - Réf. : 20 § 5.7 · FrameLevelController, ChangeFrameLevel
- [ ] **Changer le niveau d’une image en jeu** ⭐
  - Faire : Sur « Finding Nemo », image du niveau 3 (« En jeu »), cliquer « Changer de niveau », choisir « 4 Lisible » et enregistrer.
  - Attendu : La boîte dit « Cette image est en jeu : changer son niveau la fait sortir du jeu jusqu’à une nouvelle revue… ». Après « Vérification de la couverture du film… », elle affiche, avant tout envoi, l’alerte « Ce film deviendra incomplet : il restera jouable jusqu’à N = 4, avec repli de niveau. ». Un toast dit ensuite « Niveau de l’image enregistré : elle sort du jeu et repasse en revue. », et l’image est au niveau 4, « En attente de revue ».
  - Réf. : 20 § 5.7, § 8.4 · CoverageLossPreview
- [ ] **Re-recadrer une image en jeu** ⭐
  - Faire : Sur « The Matrix », image du niveau 2 (« En jeu »), cliquer « Re-recadrer ». Déplacer le cadre, puis cliquer « Enregistrer le nouveau cadre ».
  - Attendu : La boîte « Re-recadrer l’image » montre d’abord un bloc fantôme, puis la source de recadrage (un aplat gris de 1 920 px pour la démonstration) avec le cadre actuel. On y lit « Cette image est en jeu : le nouveau cadre la fait sortir du jeu jusqu’à une revue de son nouveau rendu. ». Le champ « Motif de la sortie du jeu » est pré-rempli par « Image re-recadrée : elle sort du jeu jusqu’à une revue de son nouveau rendu. ». Après l’envoi, un toast dit « Nouveau cadre enregistré : l’image est en traitement et reviendra en revue dès qu’elle sera prête. », puis la carte passe par « En traitement » et « En attente de revue ».
  - Réf. : 20 § 5.7 · FrameCropController, RecropFrame, use-master-image
- [ ] **Voir l’échec de chargement de la source de recadrage** ⚠️
  - Faire : Dans l’onglet Réseau, bloquer les URL qui contiennent `/master`. Cliquer « Re-recadrer » sur une image prête, puis débloquer et cliquer « Réessayer » dans la boîte.
  - Attendu : La boîte affiche « La source de recadrage n’a pas pu être chargée : réessayez ; si l’échec persiste, écartez l’image et ajoutez de nouveau son visuel. » avec « Réessayer », et « Enregistrer le nouveau cadre » reste inactif. Une fois l’URL débloquée, « Réessayer » affiche le recadreur.
  - Réf. : 20 § 5.7 · frame-gesture-dialog.tsx RecropForm (master_failed)
- [ ] **Faire refuser un re-recadrage d’une image en traitement** ⚠️
  - Faire : Écouteur de file arrêté, ouvrir la banque de « The Lion King » dans deux onglets. Dans l’onglet B, re-recadrer une capture en attente de revue. Dans l’onglet A, sans recharger, cliquer « Re-recadrer » sur la même image, puis « Enregistrer le nouveau cadre ».
  - Attendu : Dans la boîte de l’onglet A : « Cette image est en cours de traitement : attendez qu’il se termine, puis refaites votre geste. ». Aucun nouveau traitement n’est lancé.
  - Réf. : 20 § 5.7 · RecropFrame::refusal (admin.frame.recrop.busy)
- [ ] **Dépublier une image en jeu** ⭐
  - Faire : Sur « Ratatouille », image du niveau 2 (« En jeu »), cliquer « Dépublier ». Laisser le champ « Motif (facultatif) » vide et cliquer « Dépublier l’image ».
  - Attendu : La boîte « Dépublier l’image » dit « L’image sort du jeu ; une nouvelle revue pourra l’y remettre. ». Aucune alerte de couverture : les niveaux 1, 3 et 5 restent couverts. Un toast dit « Image dépubliée : elle sort du jeu, et une revue pourra l’y remettre. ». La carte devient « En attente de revue », sans « Dépublier » ni « Écarter », et l’image figure dans /admin/review, onglet « À revoir ».
  - Réf. : 20 § 8.4 · FrameUnpublishController, UnpublishFrame
- [ ] **Écarter une image jamais publiée**
  - Faire : Sur une capture « En attente de revue » de « The Lion King », jamais publiée, cliquer « Écarter », puis « Écarter l’image ».
  - Attendu : La boîte « Écarter l’image » dit « L’image ne sera jamais proposée en revue. Pour réutiliser son visuel, ajoutez-en une nouvelle variante. ». Un toast dit « Image écartée : elle ne sera pas proposée en revue. Pour réutiliser son visuel, ajoutez une nouvelle variante. ». La carte devient « Écartée », sans aucun geste, et n’apparaît pas dans « À revoir ». Pour un visuel TMDB, le badge « Déjà utilisé » perd ce niveau, et l’ajout du même cadre redevient possible.
  - Réf. : 20 § 8.4 · unpublishKind (set_aside), AddFrame::hasTwin
- [ ] **Annuler une boîte de geste**
  - Faire : Ouvrir « Changer de niveau » sur une image, puis la fermer par « Annuler ». La rouvrir et la fermer par le bouton « Fermer » (croix), puis par Échap.
  - Attendu : Rien n’est envoyé, l’image est inchangée, et le focus revient chaque fois sur le bouton qui a ouvert la boîte.
  - Réf. : 20 § 13.4 · FrameGestureDialog onReturnFocus
- [ ] **Perdre la vérification de couverture d’une boîte** ⚠️
  - Faire : Passer l’onglet Réseau « Hors connexion », puis cliquer « Dépublier » sur une image en jeu de « Jurassic Park ». Revenir en ligne, cliquer « Réessayer » dans la boîte, puis « Annuler ».
  - Attendu : La boîte affiche « La couverture du film n’a pas pu être vérifiée : réessayez avant de confirmer. », et « Dépublier l’image » reste inactif. Le toast « Connexion perdue… » peut aussi apparaître. En ligne, « Réessayer » débloque l’envoi.
  - Réf. : 20 § 8.4 · frame-gesture-dialog.tsx CoverageNotice
- [ ] **Ouvrir les aperçus « game » et « master » d’une image**
  - Faire : Onglet Réseau filtré sur « frames/ », recharger la banque et ouvrir dans un nouvel onglet une URL /admin/catalog/{movie}/frames/{frame}/game, puis la même en remplaçant « game » par « master ». Remplacer ensuite {movie} par l’identifiant d’un autre film. Ouvrir enfin l’URL dans une fenêtre privée connectée en joueur.
  - Attendu : « game » sert un WebP de 1280 × 720, « master » un WebP de 1 920 px de large. En-têtes : `Cache-Control: no-store, private` et `X-Robots-Tag: noindex, nofollow`, sans ETag ni Last-Modified. Avec un autre film : 404. En joueur : 403. Sur un rendu de la banque, le clic droit n’ouvre pas de menu.
  - Réf. : 20 § 5.8 · FrameImageController (frames.game / frames.master), FrameImageResponse
- [ ] **Vérifier qu’une image jamais traitée n’a pas d’aperçu** ⚠️
  - Faire : Écouteur arrêté, ajouter une image. Relever son identifiant avec `php artisan tinker --execute="echo App\Models\Frame::query()->latest('id')->value('id');"`, puis ouvrir ses URL /game et /master.
  - Attendu : Les deux URL répondent 404 sans corps : les octets d’origine, non traités, ne se montrent jamais.
  - Réf. : 20 § 5.8 · game_path NULL → FrameImageResponse::notFound

### 8.9 Prévisualisation en conditions de jeu et repli de niveau

- [ ] **Prévisualiser un film complet à chaque N** ⭐
  - Faire : Sur « Toy Story », carte « Prévisualisation en conditions de jeu », cliquer tour à tour « N = 2 » à « N = 5 ».
  - Attendu : « N = 5 » est ouvert d’abord, avec « Niveaux joués : 1 · 2 · 3 · 4 · 5. ». N = 4 donne « 1 · 2 · 4 · 5 », N = 3 « 1 · 3 · 5 » et N = 2 « 1 · 5 ». Chaque palier (« Palier 1 — niveau 1 »…) montre le rendu « Sur un téléphone » et « Sur un ordinateur », dans le cadre sombre du jeu, même en thème clair.
  - Réf. : 20 § 6.7 · FrameBankSnapshot::sequences, game-conditions-preview.tsx, FrameLevelCoverage
- [ ] **Voir le repli de niveau et la différence entre les masques**
  - Faire : Sur « Jurassic Park », dépublier l’image du niveau 4 : aucune alerte de couverture, les niveaux 1, 3 et 5 restant couverts. Dans la prévisualisation, choisir « N = 4 », puis « N = 5 », en basculant « Images comptées » entre « En jeu » et « Après revue ».
  - Attendu : « En jeu » : N = 4 affiche « Repli de niveau : ce film serait joué avec les niveaux 1 · 2 · 3 · 5. » et quatre paliers, et N = 5 « Pas jouable à N = 5 : il faut au moins 5 niveaux couverts. ». « Après revue » : N = 4 affiche « Niveaux joués : 1 · 2 · 4 · 5. » et N = 5 redevient jouable. L’indication dit « « Après revue » ajoute aux images en jeu celles qui attendent leur revue. ».
  - Réf. : 20 § 6.7 · FrameLevelCoverage::select / usesFallback
- [ ] **Prévisualiser une banque vide**
  - Faire : Ouvrir la banque du film importé, encore sans image.
  - Attendu : « N = 2 » est ouvert et affiche « Pas jouable à N = 2 : il faut au moins 2 niveaux couverts. », sans palier. La banque dit « Aucune image : ajoutez-en une depuis les visuels TMDB du film, ou par une capture. », et le bandeau de couverture « Passe 1 en cours : une variante jouable à chacun des niveaux 1, 3 et 5 suffit à rendre le film publiable. ».
  - Réf. : 20 § 6.6, § 6.7

### 8.10 File de revue /admin/review

- [ ] **Lire l’état initial de la file de revue** ⭐
  - Faire : Ouvrir le menu « Revue » (/admin/review). Pour voir les compteurs initiaux, le faire juste après `migrate:fresh --seed`.
  - Attendu : Titre « Passe de revue ». Onglets « À revoir (n) », « À re-revoir (0) » et « Rejetées (1) », avec n = 0 juste après le seed. Une liste vide dit « Aucune image à revoir : chaque image prête a été jugée. », et « À re-revoir » dit « Aucune image à re-revoir : chaque image en jeu a été revue sous la grille courante. ». « Rejetées » regroupe sous « Snow White and the Seven Dwarfs » 1937 l’image « Niveau 2 — Cryptique », avec « En défaut : Ni affiche ni jaquette ». Son panneau de revue s’affiche à droite.
  - Réf. : 20 § 7.3 · FrameReviewQueueController, ReviewQueue
- [ ] **Lire le panneau de revue d’une image** ⭐
  - Faire : Une fois traitées une capture de niveau 1 et une image TMDB de niveau 3, ouvrir « À revoir » et cliquer chacune dans la liste.
  - Attendu : Les images sont regroupées sous leur film. Le panneau a pour titre « Image de niveau 1 — {titre} » et montre le rendu final aux deux largeurs, dans le cadre sombre. Suivent « Film », « Année de sortie », puis « Niveau » avec son guide. La « Source déclarée » est en lecture seule : « Capture personnelle, à cet instant du film » avec « 0:12:34 », ou « Visuel TMDB » avec « /xxxx.jpg ». La section « Grille d’exclusion, version 1 » liste 9 points au niveau 1 (dont « Pas de visage du personnage principal ») et 8 au niveau 3.
  - Réf. : 20 § 7.1, § 7.4, § 7.6 · components/admin/review-panel.tsx, ExclusionGrid v1
- [ ] **Publier une image par « Conforme, publier »** ⭐
  - Faire : Sur l’image affichée, cliquer « Conforme, publier ».
  - Attendu : Le bouton passe à « Envoi en cours… », puis un toast dit « Revue conforme : l’image est publiée. ». Le compteur « À revoir » baisse, et l’image suivante s’affiche avec le focus sur son titre. Dans la banque, l’image passe « En jeu », et sa colonne « Jouables / cible » augmente.
  - Réf. : 20 § 7.5 · FrameReviewController, ReviewFrame
- [ ] **Publier par la touche Entrée**
  - Faire : Juste après une revue, le titre de l’image suivante a le focus : presser Entrée. Ailleurs, cliquer dans le panneau hors d’un bouton, puis presser Entrée. Focaliser ensuite un bouton de la liste et presser Entrée, puis ouvrir « Non conforme » et presser Entrée sur le panneau.
  - Attendu : Entrée sur le titre ou sur le panneau vaut « Conforme, publier ». Sur un bouton ou une case, Entrée garde son effet habituel (un bouton de la liste sélectionne son image). En mode « Non conforme », Entrée ne publie jamais. Le panneau rappelle « Raccourci : Entrée, hors d’un bouton ou d’une case, vaut « Conforme, publier ». ».
  - Réf. : 20 § 6.4 · useThroughputShortcuts (screen review)
- [ ] **Rejeter une image non conforme** ⭐
  - Faire : Cliquer « Non conforme » et tenter « Rejeter » sans rien cocher. Cocher « Pas de logo de studio », puis cliquer « Rejeter ».
  - Attendu : Des cases apparaissent, avec « Cochez chaque point en défaut, puis rejetez l’image. ». Sans case cochée, « Rejeter » est inactif et la page affiche « Cochez au moins un point en défaut pour rejeter l’image. » ; « Annuler » revient en arrière. Après le rejet, un toast dit « Revue non conforme enregistrée : l’image rejoint les rejetées. ». L’image apparaît dans « Rejetées » avec « En défaut : Pas de logo de studio », et en « Rejetée en revue » dans la banque.
  - Réf. : 20 § 7.4, § 7.5 · ExclusionGrid::decisionFor
- [ ] **Utiliser les gestes de l’onglet « Rejetées »**
  - Faire : Dans « Rejetées », cliquer l’image « Niveau 2 — Cryptique » de Snow White pour l’afficher. Essayer « Re-recadrer dans la banque ». Revenir, cliquer « Écarter » et confirmer « Écarter l’image ».
  - Attendu : Le panneau dit « Rejetée à la dernière revue, en défaut : Ni affiche ni jaquette. Seule une revue conforme peut encore partir. » et n’offre que « Conforme, publier ». « Re-recadrer dans la banque » ouvre l’éditeur du film. La boîte « Écarter l’image » s’ouvre avec le champ « Motif (facultatif) » pré-rempli par « Rejetée en revue, en défaut : Ni affiche ni jaquette. ». Après confirmation, l’image quitte « Rejetées ».
  - Réf. : 20 § 7.3 · pages/admin/review/index.tsx QueueItem, review-unpublish-dialog.tsx
- [ ] **Vérifier la preuve de revue : nom réel, horodatage, version, immuabilité**
  - Faire : Après une revue en curateur, lancer `php artisan tinker --execute="dump(App\Models\FrameReview::query()->latest('id')->first()->only(['reviewer_name','reviewer_role','grid_version','decision','declared_source_kind','declared_source_reference','reviewed_at']));"`. Refaire une revue en administrateur et relancer la commande. En administrateur, ouvrir /admin/users, puis la fiche du curateur.
  - Attendu : En curateur, reviewer_name vaut « Camille Démo-Curation » (le nom réel, jamais le pseudo), reviewer_role vaut curator et grid_version 1 ; la décision, la source ou le minutage déclarés et reviewed_at sont remplis. En administrateur : « Alex Démo-Administration » et admin. Aucun écran ne propose de modifier ni de supprimer une revue. Dans « Preuves signées », « Revues d’image » augmente de 1 par revue.
  - Réf. : 20 § 7.5, § 7.8 · ReviewFrame::record, D12 du 23/09
- [ ] **Faire refuser une revue dont le niveau a changé** ⚠️
  - Faire : Onglet A : dans « À revoir », afficher une image de niveau 1 ou 2. Onglet B : dans la banque, passer cette image au niveau 3. Onglet A, sans recharger : cliquer « Conforme, publier ».
  - Attendu : Sous les boutons, un encadré rouge dit « Le niveau de l’image a changé depuis son affichage, et les points de la grille avec lui : aucune revue n’a été enregistrée. Revoyez-la à son nouveau niveau. ». Le panneau montre ensuite le niveau 3 et ses 8 points. Entre les niveaux 3, 4 et 5, les points sont identiques et le serveur ne refuse rien.
  - Réf. : 20 § 7.5 · admin.review.level_changed
- [ ] **Faire refuser une revue sur un rendu périmé** ⚠️
  - Faire : Onglet A : afficher une image de « À revoir ». Onglet B : la re-recadrer et attendre « En attente de revue ». Onglet A : cliquer « Conforme, publier ».
  - Attendu : Un toast dit « L’image a changé depuis son affichage, un nouveau rendu l’a remplacée : aucune revue n’a été enregistrée. Revoyez-la sur son rendu actuel. ». Le panneau montre ensuite le nouveau rendu.
  - Réf. : 20 § 7.4, § 7.5 · admin.review.stale
- [ ] **Faire refuser un double envoi de revue** ⚠️
  - Faire : Ouvrir la même image de « À revoir » dans deux onglets. Cliquer « Conforme, publier » dans le premier, puis dans le second.
  - Attendu : Le second reçoit « Cette image a déjà été jugée, ou n’attend plus de revue : aucune nouvelle revue n’a été enregistrée. », et l’image quitte sa liste. Une seule preuve est écrite (« Revues d’image » +1).
  - Réf. : 20 § 7.5 · admin.review.already_reviewed
- [ ] **Faire refuser la revue d’une image en traitement** ⚠️
  - Faire : Écouteur arrêté. Onglet A : afficher une image de « À revoir ». Onglet B : la re-recadrer (elle reste « En traitement »). Onglet A : cliquer « Conforme, publier ».
  - Attendu : Un toast dit « Cette image ne peut pas être revue : elle est en traitement ou en échec, ou bien elle ou son film est suspendu ou retiré par un administrateur. ». L’image quitte la liste jusqu’à la fin de son traitement, et aucune preuve n’est écrite.
  - Réf. : 20 § 7.5 · admin.review.locked, ReviewQueue::isEligible
- [ ] **Perdre la connexion pendant une revue** ⚠️
  - Faire : Afficher une image, passer « Hors connexion », puis cliquer « Conforme, publier ».
  - Attendu : Un seul toast : « Connexion perdue : rien n’a été envoyé et votre saisie est conservée. Vérifiez votre réseau, puis réessayez. ». L’image reste affichée, sans preuve écrite. De retour en ligne, le même clic publie.
  - Réf. : 20 § 13.5 · pages/admin/review/index.tsx (networkError)
- [ ] **Ouvrir la revue à 375 px** ⚠️
  - Faire : Dans les outils de développement, mode appareil 375 × 667 en portrait, ouvrir /admin/review, onglet « Rejetées » (ou toute liste non vide).
  - Attendu : À la place du panneau : « La revue demande un écran large, celui d’un ordinateur : l’image se juge sur son rendu final aux deux largeurs. Les listes, et les gestes sur une image rejetée, restent utilisables ici. ». Les onglets, les listes, « Re-recadrer dans la banque » et « Écarter » restent utilisables, sans défilement horizontal de la page.
  - Réf. : 20 § 6.1, § 13.4 · admin.review.desktop_required

### 8.11 Couverture 1/3/5 et publication du film

- [ ] **Voir « Publier le film » inactif, avec les conditions nommées** ⭐
  - Faire : Sur le film importé, sans image en jeu, descendre à « Publication du film ».
  - Attendu : L’alerte dit « Non publiable : les niveaux 1, 3 et 5 ne sont pas tous couverts (le contenu, lui, est vérifié). », ou « Non publiable : ni le contenu vérifié, ni les niveaux 1, 3 et 5 couverts. ». « Publier le film » est grisé mais focalisable. Dessous : « Pas encore publiable : », puis « Niveaux sans image en jeu : 1 · 3 · 5. Chacun des niveaux 1, 3 et 5 doit avoir au moins une image publiée par une revue conforme. ». Une image « En attente de revue » ne compte pas.
  - Réf. : 20 § 8.1 · PublishMovie::conditions, publish-dialog.tsx PublishButton
- [ ] **Faire la passe 1 et publier un film importé** ⭐
  - Faire : Ajouter une image aux niveaux 1, 3 et 5, puis les passer en « Conforme, publier » dans /admin/review. Revenir à la banque et cliquer « Publier le film ». Lire la section « Formes rendues ambiguës » de la boîte, puis confirmer par « Publier le film ».
  - Attendu : Avant la publication, la couverture dit « Passe 2 en cours… » (1 / 2 et « Variante unique » aux niveaux 1, 3 et 5), et l’alerte « Publiable : contenu vérifié ET niveaux 1, 3 et 5 couverts. ». La boîte affiche « Calcul de l’avertissement d’ambiguïté… », puis « Aucune forme ne deviendra ambiguë. » ou les formes et les films concernés. Un toast dit « Film publié : il entre au vivier des parties lancées désormais. ». Le badge passe à « Publié » et le bouton disparaît.
  - Réf. : 20 § 8.1, § 8.2 · MoviePublishController, AmbiguityPreview
- [ ] **Lever le blocage « contenu non vérifié »**
  - Faire : Si le film importé porte « Classification à vérifier », lire la condition sous le bouton. Sur la fiche, cliquer « Contenu vérifié », remplir « Ce que vous avez vérifié », puis « Confirmer la vérification ». Revenir à la banque.
  - Attendu : Avant : « Le contenu du film n’est pas vérifié comme libre de toute classification restrictive : un film « à vérifier » se débloque par « Contenu vérifié » sur sa fiche ; un film bloqué ne se publie jamais. ». Après, un toast dit « Contenu vérifié : cette condition de publication est levée. », et le bouton de la banque devient actif si les niveaux 1, 3 et 5 sont en jeu.
  - Réf. : 20 § 4.4, § 8.1 · admin.movie.publish.content_not_clear
- [ ] **Dépublier puis republier un film de démonstration**
  - Faire : Sur la fiche de « Inception », cliquer « Dépublier le film », remplir « Motif de la dépublication » et confirmer. Ouvrir sa banque, cliquer « Republier le film », puis « Republier le film » dans la boîte.
  - Attendu : Un toast dit « Film dépublié : il sort du vivier des parties lancées désormais. ». Les images restent « En jeu ». La banque propose « Republier le film », dans une boîte au même titre. À la fin, un toast dit « Film republié : il revient au vivier des parties lancées désormais. ».
  - Réf. : 20 § 8.3 · MoviePolicy::publish (draft ou unpublished)
- [ ] **Lire l’avertissement d’ambiguïté en republiant une saga**
  - Faire : Dépublier « Star Wars: A New Hope » depuis sa fiche (motif obligatoire). Dans sa banque, cliquer « Republier le film » et lire la section « Formes rendues ambiguës » avant de confirmer.
  - Attendu : La section commence par « Un préfixe ou un sous-titre porté par plusieurs films publiés n’est plus accepté seul comme réponse, pour aucun d’eux… ». Elle liste au moins une ligne du type « « star wars » — préfixe de ce film, porté aussi par : Star Wars: The Empire Strikes Back (1980), préfixe » (forme normalisée). « Republier le film » reste inactif tant que l’aperçu n’est pas affiché.
  - Réf. : 20 § 8.2 · AmbiguityPreview::forPublication, ambiguityLineText
- [ ] **Voir l’échec du calcul d’ambiguïté** ⚠️
  - Faire : Sur un film publiable non publié, passer l’onglet Réseau « Hors connexion », puis cliquer « Publier le film » (ou « Republier le film »). Revenir en ligne et cliquer « Réessayer » dans la boîte.
  - Attendu : La boîte affiche « L’avertissement d’ambiguïté n’a pas pu être calculé : réessayez avant de confirmer. » avec « Réessayer », et l’envoi reste inactif. En ligne, « Réessayer » affiche l’aperçu et débloque l’envoi.
  - Réf. : 20 § 8.2 · publish-dialog.tsx (preview.failed)
- [ ] **Voir la republication bloquée par la couverture (sans TMDB)** ⚠️
  - Faire : Dépublier le film « Pulp Fiction » depuis sa fiche. Dans sa banque, dépublier l’image du niveau 3.
  - Attendu : Aucune alerte de couverture n’apparaît dans la boîte, puisque le film n’est pas publié. L’alerte du pied devient « Non publiable : les niveaux 1, 3 et 5 ne sont pas tous couverts (le contenu, lui, est vérifié). ». « Republier le film » est grisé, avec « Pas encore publiable : » et « Niveaux sans image en jeu : 3… ». Le bandeau de couverture repasse à « Passe 1 en cours… », sans alerte « Film publié incomplet ».
  - Réf. : 20 § 8.1 · CoverageLossPreview (film non publié), PublishMovie::conditions
- [ ] **Rendre incomplet un film publié, puis le réparer** ⭐
  - Faire : Sur « Toy Story », publié, cliquer « Dépublier » sur l’image du niveau 3, lire l’avertissement et confirmer. Regarder la couverture, la prévisualisation et le tableau de bord /admin. Repasser ensuite l’image en « Conforme, publier » dans « À revoir ».
  - Attendu : Avant l’envoi : « Ce film deviendra incomplet : il restera jouable jusqu’à N = 4, avec repli de niveau. ». Le film reste ensuite « Publié », sous l’alerte « Film publié incomplet : l’un des niveaux 1, 3 ou 5 n’a plus de variante jouable. Il reste publié, jouable jusqu’à N = 4 avec repli de niveau. ». En masque « En jeu », N = 5 n’est plus jouable et N = 3 affiche « Repli de niveau… » sans le niveau 3. La tuile « Publiés incomplets » du tableau de bord augmente de 1. Après la revue, tout revient.
  - Réf. : 20 § 6.6, § 8.4 · E10-23, CoverageLossPreview, CurationStatus
- [ ] **Rendre un film publié injouable à tout N** ⚠️
  - Faire : Sur « Back to the Future », publié, dépublier une à une les images des niveaux 3, 2 et 4, puis celle du niveau 5.
  - Attendu : Le niveau 3 annonce « … jouable jusqu’à N = 4… ». Les niveaux 2 et 4 n’affichent aucune alerte : N baisse sans tomber à zéro. Le niveau 5 annonce « Ce film ne sera plus jouable à aucun nombre d’images par manche : il reste publié, mais n’entrera dans aucun tirage tant que de nouvelles images ne seront pas publiées. ». La banque affiche ensuite « Film publié incomplet : il n’est plus jouable à aucun nombre d’images par manche… ». Rétablir par la revue, ou par `migrate:fresh --seed`.
  - Réf. : 20 § 8.4 · admin.frame.unpublish.coverage_warning_unplayable
- [ ] **Vérifier le pied d’un film déjà publié**
  - Faire : Ouvrir la banque de « Dune: Part Two », publié et complet.
  - Attendu : L’alerte « Publiable : contenu vérifié ET niveaux 1, 3 et 5 couverts. » s’affiche, sans bouton « Publier le film ». Le pied montre aussi le rappel « Raccourcis de débit » (description et trois lignes), puis « Film suivant » et « Retour à la fiche du film ».
  - Réf. : 20 § 6.1, § 8.1 · abilities.publish
- [ ] **Passer au film suivant depuis le pied**
  - Faire : Cliquer « Film suivant » depuis la banque d’un film de démonstration, avant puis après l’import TMDB.
  - Attendu : Sans film en attente (base tout juste seedée), on arrive sur /admin/curation avec le toast « Aucun autre film n’attend dans la file de curation. Importez de nouveaux films pour la remplir. ». Avec le film importé en brouillon, on arrive dans son éditeur.
  - Réf. : 20 § 4.1, § 6.1 · CurationQueueController@next

### 8.12 Affichage : thème, portrait 375 px, clavier

- [ ] **Basculer le back-office en thème clair puis sombre**
  - Faire : Choisir « Sombre » sur /settings/appearance, ouvrir la banque puis la revue. Refaire l’essai avec « Clair ».
  - Attendu : Le back-office suit l’apparence choisie : aucun thème n’est imposé. Dans les deux cas, la prévisualisation en conditions de jeu et le rendu de la revue restent dans le cadre sombre du jeu. Aucun texte n’est illisible, et le focus reste visible.
  - Réf. : 20 § 13.3 · D8 du 23/09, GameThemeScope
- [ ] **Ouvrir l’éditeur en portrait 375 px (geste 11 a)**
  - Faire : Dans les outils de développement, appareil 375 × 667 en portrait, ouvrir /admin/catalog/{id}/bank. Parcourir la page, puis ouvrir « Changer de niveau » et « Re-recadrer » sur une image.
  - Attendu : « Recadrer et classer » n’affiche que « Le recadreur demande un écran large, celui d’un ordinateur. Le reste de l’écran — la banque, les états, la couverture — reste utilisable ici. », sans « Choisir une image ». La banque tient sur une colonne, et le tableau de couverture défile dans son cadre, sans défilement horizontal de la page. « Changer de niveau », « Dépublier » et « Publier le film » fonctionnent. La boîte « Re-recadrer l’image » affiche le même avertissement d’écran large et ne propose pas « Enregistrer le nouveau cadre ».
  - Réf. : 20 § 6.1, § 13.4 · admin.bank.desktop_required
- [ ] **Parcourir l’éditeur au seul clavier**
  - Faire : Depuis le haut de la page, parcourir l’éditeur d’un film importé avec Tab et Maj + Tab seulement, en ouvrant un visuel par Entrée.
  - Attendu : Après la navigation et « Retour à la fiche du film » de l’en-tête, l’ordre suit l’écran : grille (un seul arrêt), « Choisir une image », cadre, commandes du cadre, niveaux, « Ajouter à la banque », « Fermer ce visuel », bande, gestes de la banque, bascules de prévisualisation, « Publier le film », « Film suivant », puis « Retour à la fiche du film ». Le focus reste toujours visible, et aucun geste n’exige la souris. Signaler si « Film suivant » doit être le dernier arrêt (§ 6.4) : dans le code, « Retour à la fiche du film » le suit.
  - Réf. : 20 § 6.4, § 13.4 · bank.tsx (pied)

### 8.13 Hors périmètre (non livré, ne pas essayer)

- Suspension conservatoire et retrait juridique d’un film ou d’une image (J2, spec 20 § 11.2-11.3) : aucune route au J1. On ne peut donc pas provoquer l’état « Suspendue ou retirée », le message « Ce film est suspendu par un administrateur : aucune image ne peut y être ajoutée. », ni les refus `admin.frame.recrop.locked`, `admin.frame.level.locked` et `admin.review.locked` pour cause de suspension.
- Geste rétroactif de la grille et grille v2 (J2, § 7.7) : avec la seule grille v1, « À re-revoir » reste vide. Sont inatteignables : le badge « À re-revoir : la grille a changé depuis sa revue », le badge « Rejetée en re-revue : reste en jeu jusqu’à décision », le toast « Revue conforme : l’image reste en jeu… » et la boîte de dépublication qui suit le rejet d’une image en jeu.
- Refus serveur que l’écran ne produit jamais, puisqu’il envoie toujours une requête conforme : capture de plus de 1 536 Ko, non WebP ou animée (`admin.validation.frame_source.*`), minutage mal formé côté serveur, niveau absent, `grid_version_outdated`, `source_mismatch`, `answers_invalid`, `retry.not_retryable`, `recrop.not_ready`. Ne pas les chercher à la main.
- Pannes TMDB qu’on ne reproduit pas à volonté : quota atteint (« Quota TMDB atteint : patientez quelques secondes, puis réessayez. »), échec ou poids excessif au téléchargement de l’original (`admin.frame.tmdb.download_failed`, `too_large`).
- Refus de publication `preview_stale` (catalogue modifié entre l’aperçu d’ambiguïté et le clic) et `not_guessable` (film sans titre lisible) : aucun film de démonstration ne réunit les données nécessaires.
- Limiteurs `admin-frame` (30 écritures par minute par défaut) et `admin-curation` (60 par minute) : la page « Trop de demandes » ne s’obtient que par une rafale irréaliste à la main.
- Plancher de recadrage (80 % et 640 px) et plafond d’envoi (1 536 Ko) : réglages de plateforme de `config/game.php`, lus par `PlatformLimits`, sans variable d’environnement. Ils ne se changent pas pendant la recette.
- Tier froid des captures (`backup:manifest --cold`, EL33-4) et liste fermée des sources autorisées par le conseil : non livrés, sans effet sur l’écran.

## 9. Administration des comptes, commandes artisan et exploitation

Cette section couvre deux choses. D’abord les écrans de l’administrateur seul, livrés en avance le 28/09 (D40, L20-19) : l’annuaire /admin/users, la fiche d’un compte /admin/users/{id} et la gestion des accès /admin/access (rôles, nom réel, historique lu dans le journal admin_action). Ensuite les commandes artisan du projet, le planificateur, la file et les sondes /ops/probe/{probe}, toutes essayées en local sur le poste : pour chacune un essai sans risque et, en « limite », les gestes qui écrivent ou détruisent.

**Prérequis**

- `mysqldump` dans le PATH : il est absent du poste Windows au 28/09 (vérifié). Installer le client MySQL 8, ou lancer les commandes depuis la VM Homestead. Sans lui, `php artisan backup:snapshot` échoue (code 1), et aucun geste qui l’exige ne se joue, `migrate:fresh` compris (règle 12).
- Base fraîche : `php artisan backup:snapshot` (code 0 exigé), puis `php artisan migrate:fresh --seed`, qui joue DemoAccountsSeeder, PlatformDataSeeder et DemoCatalogueSeeder. Attention : la base de dev actuelle n’est pas seedée. Elle porte un autre compte administrateur et trois films importés (Fight Club, Parasite, Le Labyrinthe de Pan), et admin@tripleframes.test n’y est pas administrateur. `migrate:fresh` efface tout cela.
- Essais `catalog:import*` : `TMDB_API_KEY` ou `TMDB_API_READ_ACCESS_TOKEN` renseigné dans `.env`. Sans clé, ces commandes s’arrêtent sur le message de clé absente avant tout autre contrôle (par exemple, un collage sans identifiant ne dit pas « Aucun identifiant lisible… »).
- Comptes de démo, tous avec le mot de passe `password`, adresse vérifiée et sans 2FA : player@tripleframes.test (« Joueur Démo », sans nom réel, interface en français), curator@tripleframes.test (« Curateur Démo », nom réel « Camille Démo-Curation », français), admin@tripleframes.test (« Admin Démo », nom réel « Alex Démo-Administration », interface en ANGLAIS). Le seed pose leur « Dernière connexion » à l’instant du seed.
- `composer dev` tourne : serveur sur http://127.0.0.1:8000, écouteur de file `game,default`, Reverb et Vite. Ouvrir un second terminal à la racine du projet pour les commandes. Code de sortie : `echo $LASTEXITCODE` sous PowerShell, `echo $?` sous Git Bash.
- Second facteur confirmé pour admin@tripleframes.test : sans lui, toute page /admin renvoie vers l’écran d’enrôlement. Il le faut aussi pour curator@tripleframes.test dans les essais où le curateur entre au back-office. Chemin : /settings/security, bouton « Activer la 2FA » (« Enable 2FA » pour le compte admin, qui est en anglais). Scanner le QR code avec une application TOTP, « Continuer », saisir le code à 6 chiffres, puis « Confirmer ». À la connexion suivante, le code est demandé à l’écran « Authentification à deux facteurs ».
- Deux navigateurs, ou un navigateur et une fenêtre privée, pour tenir deux comptes à la fois : la session vit dans un cookie. Deux onglets du même compte suffisent pour les essais de croisement.
- Pages d’erreur traduites (403, 404, 429) : elles ne s’affichent qu’avec `APP_DEBUG=false` dans `.env`. Le poste est en `true`, qui affiche à la place la page d’erreur du framework. Le serveur de `composer dev` redémarre seul quand `.env` change. Remettre `true` après l’essai.
- Le `.env` du poste est en `BROADCAST_CONNECTION=log`, `QUEUE_CONNECTION=database` et `CACHE_STORE=database` (vérifié). Cela suffit pour cette section : le drapeau de drainage, la suspension de purge et les battements vivent dans le cache partagé en base. Le temps réel des essais de lobby exige en revanche un `.env` aligné sur `.env.example` (voir § 0).
- Sous PowerShell, employer `curl.exe` et non l’alias `curl`. Écrire les arguments de console en ASCII : un accent passé en argument peut être altéré.
- Interdits pendant la recette : `php artisan cache:clear` (il efface le drapeau de drainage, la suspension de purge, les battements et les verrous), `storage:link` et `install:broadcasting`. Aucune commande n’écrit dans `movie`, `frame`, `movie_title`, `alias` ou `frame_review` sans un `backup:snapshot` préalable au code 0.

### 9.1 Back-office administrateur : entrée et navigation

- [ ] **Entrer au back-office en administrateur et voir le groupe Administration** ⭐
  - Faire : Se connecter sur /login avec admin@tripleframes.test / password (bouton « Se connecter »), saisir le code TOTP au défi, puis ouvrir /admin.
  - Attendu : Le tableau de bord s’ouvre en français. La barre latérale porte, sous le groupe de curation, un second groupe « Administration » avec « Comptes » (/admin/users) et « Accès » (/admin/access). Le pied de la barre affiche le rôle « Administrateur » et le lien « Retour au site ».
  - Réf. : 20 § 2.8 (Navigation) · admin-sidebar.tsx · admin-user-panel.tsx
- [ ] **Constater que la porte reste fermée à un administrateur sans double authentification** ⚠️
  - Faire : Juste après le seed, avant d’avoir confirmé la 2FA du compte admin, se connecter en admin@tripleframes.test puis ouvrir /admin/users.
  - Attendu : Redirection 303 vers /admin/two-factor. L’onglet s’intitule « Double authentification requise » ; la page titre « Activez la double authentification pour entrer » et propose le bouton « Ouvrir la sécurité du compte ». L’annuaire ne s’affiche jamais.
  - Réf. : 20 § 2.4 · EnsurePrivilegedTwoFactor · admin.two_factor.*
- [ ] **Vérifier qu’un curateur ne voit pas le groupe Administration** ⭐
  - Faire : Dans une fenêtre privée, se connecter en curator@tripleframes.test (2FA confirmée), puis ouvrir /admin.
  - Attendu : Le curateur voit ses écrans de curation, mais aucun groupe « Administration », ni « Comptes » ni « Accès ». Le pied de la barre indique « Curateur ».
  - Réf. : 20 § 2.8 (minRole admin) · D40 du 28/09

### 9.2 Annuaire des comptes — /admin/users

- [ ] **Ouvrir l’annuaire des comptes** ⭐
  - Faire : En administrateur, cliquer « Comptes » dans le groupe « Administration ».
  - Attendu : Titre « Annuaire des comptes », badge « Lecture seule », et la description « Tous les comptes du site, joueurs compris. Cet écran ne modifie rien : … ». Quatre tuiles sur une base fraîchement seedée : « Comptes actifs » 3, « Joueurs » 1, « Curateurs » 1, « Administrateurs » 1. Puis une carte « Recherche et filtres ». Puis une carte « Comptes » qui annonce « 3 compte(s) au filtre courant », avec les colonnes Pseudo, Adresse, Nom réel, Rôle, Double authentification, Dernière connexion, Créé le, et un bouton « Ouvrir » par ligne. Aucun bouton d’écriture. Tri par défaut : date de création, décroissant.
  - Réf. : 20 § 2.8 · UserDirectoryController@index · pages/admin/users/index.tsx
- [ ] **Chercher un compte par pseudo, adresse ou nom réel** ⭐
  - Faire : Saisir « Camille » dans « Recherche », puis cliquer « Filtrer ». Recommencer avec « curator@ », puis avec « Démo ». Recharger la page.
  - Attendu : « Camille » ne trouve que Curateur Démo (par son nom réel), et « curator@ » le trouve par son adresse. « Démo » trouve les 3 comptes (par leur pseudo). L’URL porte `q=…` avec les autres filtres. Après rechargement, la recherche reste appliquée et le champ reste rempli. Le texte d’exemple du champ est « Adresse, pseudo, nom réel ou identifiant ».
  - Réf. : 20 § 2.8 · UserDirectoryRequest
- [ ] **Chercher un compte par son identifiant**
  - Faire : Relever l’« Identifiant » d’un compte sur sa fiche. Saisir ce nombre seul dans « Recherche », puis cliquer « Filtrer ».
  - Attendu : Le compte qui porte cet identifiant apparaît, ainsi que tout compte dont l’adresse, le pseudo ou le nom réel contient ces chiffres. L’aide sous le champ le dit : « … une saisie entièrement numérique cherche aussi l’identifiant du compte. »
  - Réf. : 20 § 2.8 · UserDirectoryController::filtered
- [ ] **Filtrer par rôle**
  - Faire : Choisir successivement « Rôle » = Joueur, Curateur, puis Administrateur, en cliquant « Filtrer » à chaque fois.
  - Attendu : Sur une base fraîche, une seule ligne à chaque fois, avec le badge du rôle et « 1 compte(s) au filtre courant ». Les quatre tuiles gardent les totaux globaux : elles ne suivent pas le filtre.
  - Réf. : 20 § 2.8
- [ ] **Obtenir un annuaire vide avec le filtre « Anonymisés »** ⚠️
  - Faire : Choisir « État du compte » = Anonymisés, puis cliquer « Filtrer ». Cliquer ensuite « Tout effacer ».
  - Attendu : Aucun compte anonymisé n’existe au J1. L’état vide affiche « Aucun compte », le texte « Aucun compte ne correspond à cette recherche. Effacez les filtres pour revoir tout l’annuaire. » et un bouton « Tout effacer ». Ce bouton ramène à /admin/users sans paramètre, avec tous les comptes.
  - Réf. : 20 § 2.8 · admin.users.empty.*
- [ ] **Trier l’annuaire**
  - Faire : Choisir « Tri » = Pseudo et « Sens » = Croissant, puis cliquer « Filtrer ». Recommencer avec « Dernière connexion » et « Décroissant ».
  - Attendu : Par pseudo croissant : Admin Démo, Curateur Démo, Joueur Démo. Par dernière connexion décroissante : le compte connecté le plus récemment vient en tête. L’URL porte `sort` et `direction`, et les autres filtres sont conservés.
  - Réf. : 20 § 2.8 · UserDirectoryRequest::SORTS
- [ ] **Lire les badges de double authentification**
  - Faire : Regarder la colonne « Double authentification » de l’annuaire : admin enrôlé, curateur non enrôlé, joueur.
  - Attendu : Admin : « Double authentification active », badge neutre. Curateur sans 2FA : « Sans double authentification » en rouge. Joueur : « Sans double authentification » en badge neutre, pas rouge, car ce n’est pas une alerte pour un joueur.
  - Réf. : 20 § 2.4, § 2.8 · admin-badges.tsx (TwoFactorBadge)
- [ ] **Voir un compte à adresse non vérifiée** ⚠️
  - Faire : Dans une fenêtre privée, créer un compte sur /register (bouton « Créer le compte ») sans cliquer le lien de vérification. L’inscription est ouverte en local. En administrateur, recharger /admin/users. Variante sans inscription : `php artisan tinker --execute="App\Models\User::factory()->unverified()->create();"` (dans ce cas, la dernière connexion vaut « Jamais »).
  - Attendu : Une nouvelle ligne apparaît avec le badge « Adresse non vérifiée » à côté de l’adresse. « Comptes actifs » passe à 4 et « Joueurs » à 2. Par l’inscription, « Dernière connexion » vaut l’instant de l’inscription, qui connecte le compte.
  - Réf. : 20 § 2.8 · RecordLastLogin · UnverifiedEmailBadge
- [ ] **Paginer au-delà de 25 comptes** ⚠️
  - Faire : Créer 30 comptes de test en local : `php artisan tinker --execute="App\Models\User::factory()->count(30)->create();"`. Recharger /admin/users, puis cliquer « Suivant ».
  - Attendu : 25 lignes par page. La pagination affiche « Précédent » / « Suivant », « 1 à 25 sur … » et « Page 1 sur 2 ». La page 2 garde la recherche, les filtres et le tri. Les comptes créés par la fabrique affichent « Jamais » en dernière connexion.
  - Réf. : 20 § 2.8 (PER_PAGE = 25) · AdminPagination
- [ ] **Observer l’attente d’un filtre sur réseau lent**
  - Faire : Dans les DevTools, onglet Réseau, activer un débit lent (« Slow 4G »), puis cliquer « Filtrer ».
  - Attendu : Le tableau reste affiché mais s’estompe pendant le chargement, sans être démonté (aria-busy). Le focus ne saute pas. Le tableau redevient net à la réponse.
  - Réf. : pages/admin/users/index.tsx (aria-busy)

### 9.3 Fiche d’un compte — /admin/users/{id}

- [ ] **Ouvrir la fiche du curateur** ⭐
  - Faire : Dans l’annuaire, cliquer « Ouvrir » sur la ligne de Curateur Démo.
  - Attendu : Titre « Curateur Démo » (le pseudo) et bouton « Retour à l’annuaire ». Carte « Rôle et accès » : badges Curateur et 2FA, boutons « Changer le rôle » et « Corriger le nom réel ». Carte « Identité » : Identifiant, Pseudo du compte, Nom réel « Camille Démo-Curation », Adresse, Adresse vérifiée le, Langue d’interface « Français », Créé le, Dernière connexion. Carte « Sécurité » : « Double authentification confirmée le » (une date, ou « Sans double authentification »), « Clés d’accès enregistrées » 0, « Comptes liés » « Aucun ». Carte « Consentements » : « Version des CGU acceptée » 1.0 et ses dates. Carte « Preuves signées » : « Revues d’image » non nul, « Lignes du journal d’administration » 0, « Balayages d’import lancés » 0. Carte « Historique des accès » : une ligne « Changement de rôle », « Joueur → Curateur », signée « Alex Démo-Administration », motif « Ouverture des droits de curation sur le catalogue de démonstration. ». En bas, la mention « Les configurations de salon sauvegardées par ce compte restent privées, y compris d’un administrateur : elles n’apparaissent sur aucun écran du back-office. »
  - Réf. : 20 § 2.8 · UserDirectoryController@show · pages/admin/users/show.tsx
- [ ] **Ouvrir sa propre fiche d’administrateur**
  - Faire : Dans l’annuaire, cliquer « Ouvrir » sur Admin Démo.
  - Attendu : Encadré « C’est votre compte : votre propre rôle ne se change pas ici. Un autre administrateur peut le faire. ». Pas de bouton « Changer le rôle », seulement « Corriger le nom réel ». Langue d’interface : « Anglais ». « Lignes du journal d’administration » : 2. L’historique porte « Joueur → Administrateur », signé « Accès direct au serveur (premier administrateur) », motif « Compte d’administration initial de l’instance. ».
  - Réf. : 20 § 2.8 · EL19-1
- [ ] **Ouvrir la fiche d’un joueur**
  - Faire : Dans l’annuaire, cliquer « Ouvrir » sur Joueur Démo.
  - Attendu : Nom réel : « Aucun ». Seul « Changer le rôle » est proposé : il n’y a pas de « Corriger le nom réel », car le nom réel n’existe que pour un compte privilégié. Historique vide : « Aucun changement consigné pour ce compte. ».
  - Réf. : 20 § 2.8 · UserPolicy::updateRealName
- [ ] **Vérifier qu’aucun secret ne quitte le serveur**
  - Faire : Sur la fiche d’un compte enrôlé en 2FA, afficher la source de la page (Ctrl+U). Chercher `two_factor_secret`, `recovery_codes`, `remember_token`, `saved_config` et `$2y$` (préfixe d’un mot de passe haché). Refaire la même recherche sur /admin/users et sur /admin/access?email=curator@tripleframes.test.
  - Attendu : Aucune de ces chaînes n’apparaît sur les trois écrans. Seuls sont servis des dates (2FA confirmée le…), des nombres (clés d’accès) et des noms de fournisseurs.
  - Réf. : 20 § 2.8 (jamais sérialisé) · AdminAccountPresenter
- [ ] **Ouvrir la fiche d’un identifiant inconnu** ⚠️
  - Faire : Avec APP_DEBUG=false, en admin@tripleframes.test, ouvrir /admin/users/999999.
  - Attendu : Réponse 404. C’est la page d’erreur du SITE, pas celle du back-office, dans la langue du compte : « Page not found » pour admin@tripleframes.test, qui est en anglais (« Page introuvable » pour un compte en français). La liaison de l’identifiant échoue avant le forçage du français du back-office. À signaler si l’on attend la page française du back-office.
  - Réf. : 20 § 2.8 · ErrorPageResponder · ordre des middlewares (SubstituteBindings avant admin.locale)
- [ ] **Voir la dernière connexion se mettre à jour**
  - Faire : Noter la « Dernière connexion » de Joueur Démo sur sa fiche. Dans une fenêtre privée, se connecter en player@tripleframes.test, puis recharger la fiche côté admin.
  - Attendu : « Dernière connexion » vaut maintenant l’instant de la connexion. Dans l’annuaire trié par « Dernière connexion » décroissant, Joueur Démo passe en tête.
  - Réf. : 10 § 5.1 · RecordLastLogin · EL19-5
- [ ] **Vérifier que l’étape du défi 2FA n’écrit pas la dernière connexion** ⚠️
  - Faire : Dans une fenêtre privée, saisir l’adresse et le mot de passe de curator@tripleframes.test (2FA active) et s’arrêter à l’écran « Authentification à deux facteurs ». Recharger la fiche du curateur côté admin. Saisir ensuite le code et recharger de nouveau.
  - Attendu : Tant que le code n’est pas saisi, « Dernière connexion » ne bouge pas. Elle prend l’instant de la connexion une fois le défi réussi.
  - Réf. : RecordLastLogin (événement Login à la connexion aboutie seulement)

### 9.4 Gestion des accès — /admin/access

- [ ] **Ouvrir l’écran des accès** ⭐
  - Faire : En administrateur, cliquer « Accès » dans le groupe « Administration ».
  - Attendu : Titre « Gestion des accès ». Encadré rouge « Un second administrateur nominatif manque » : « Le projet compte 1 administrateur(s). Deux administrateurs nominatifs sont exigés avant l’ouverture publique du site… ». Carte « Comptes privilégiés » : les administrateurs d’abord, puis les curateurs par nom réel, colonnes Nom réel, Pseudo, Adresse, Rôle, Double authentification, Dernière connexion, Gestes. Sur sa propre ligne : « Votre compte » et « Corriger le nom réel ». Carte « Rôle privilégié sans double authentification » : si Curateur Démo n’est pas enrôlé, lien « Camille Démo-Curation », badge Curateur et « Dernière connexion : … » ; sinon « Aucun : tous les comptes privilégiés ont confirmé leur double authentification. ». Carte « Promouvoir un compte existant ». Carte « Historique des rôles et des noms réels » : « Les 50 derniers changements, du plus récent au plus ancien. L’historique complet d’un compte se lit sur sa fiche. », avec les deux lignes du seed (colonnes Date, Compte, Geste, Changement, Signé par, Motif).
  - Réf. : 20 § 2.8 · AccessController@index · pages/admin/access/index.tsx
- [ ] **Chercher une adresse inconnue ou partielle**
  - Faire : Dans « Adresse exacte du compte », saisir « inconnu@exemple.test », puis cliquer « Chercher ». Recommencer avec « player@tripleframes » (adresse partielle), puis avec « player » (sans @).
  - Attendu : Pour les deux premières saisies : « Aucun compte actif ne porte l’adresse « … ». ». La recherche est exacte, jamais partielle. L’URL porte `?email=…` et le champ reste rempli. Pour « player », le navigateur bloque l’envoi (champ de type e-mail) et aucune requête ne part.
  - Réf. : 20 § 2.8 · AccessIndexRequest · AccessController::candidate
- [ ] **Promouvoir le joueur en curateur, avec son nom réel** ⭐
  - Faire : Chercher « player@tripleframes.test » et cliquer « Changer le rôle » sur la ligne trouvée. Dans la boîte « Changer le rôle de Joueur Démo », choisir « Nouveau rôle » = Curateur. Saisir « Dominique Essai » dans « Nom réel de la personne » et un motif dans « Motif (facultatif), inscrit au journal ». Cliquer « Changer le rôle ».
  - Attendu : Dès que Curateur est choisi, l’aide dit « Un curateur cure, revoit et publie ; … », et le champ du nom réel apparaît avec l’aide « Exigé pour un rôle privilégié : il signera ses revues et ses gestes au journal. Jamais un pseudo. ». Le bouton reste inactif tant que le nom est vide. Après l’envoi : toast « Rôle changé et inscrit au journal. », la boîte se ferme. Joueur Démo apparaît dans « Comptes privilégiés » (Curateur, « Sans double authentification » en rouge) et dans la file « Rôle privilégié sans double authentification ». L’historique porte en tête « Changement de rôle », « Joueur → Curateur », signé « Alex Démo-Administration », avec le motif saisi. Dans l’annuaire, « Joueurs » baisse de 1 et « Curateurs » monte de 1.
  - Réf. : 20 § 2.8 · ChangeUserRole · RoleUpdateRequest · D12 du 23/09
- [ ] **Voir refuser un nom réel invalide à la promotion** ⚠️
  - Faire : Promouvoir en Curateur un compte sans nom réel, par exemple le compte créé sur /register une fois son adresse vérifiée. Saisir « console » comme nom réel et envoyer, puis « System », puis « A ».
  - Attendu : Pour « console » et « System » (la casse est ignorée), sous le champ : « Ce nom est réservé au journal d’administration : saisissez le nom réel de la personne. ». Pour « A » : « Le champ nom réel doit contenir au moins 2 caractères. ». Le rôle ne change pas et le journal ne reçoit aucune ligne.
  - Réf. : 20 § 2.6 · RealNameValidationRules
- [ ] **Vérifier qu’un rôle inchangé ne peut pas être envoyé** ⚠️
  - Faire : Ouvrir « Changer le rôle » sur un curateur, laisser « Curateur » sélectionné, puis cliquer « Changer le rôle ».
  - Attendu : L’aide dit « Choisissez un rôle différent du rôle actuel. ». Le bouton est grisé (aria-disabled) et le clic n’envoie rien.
  - Réf. : 20 § 2.8 (invariant 7) · role-change-dialog.tsx
- [ ] **Faire refuser par le serveur un rôle déjà attribué (deux onglets)** ⚠️
  - Faire : Ouvrir /admin/access dans deux onglets du compte admin, avec un joueur sans nom réel affiché dans les deux (par la recherche d’adresse). Onglet 1 : le promouvoir en Curateur avec un nom réel. Onglet 2, sans recharger : ouvrir « Changer le rôle » sur le même compte, choisir Curateur, saisir un nom réel, puis envoyer.
  - Attendu : Sous « Nouveau rôle » : « Ce compte porte déjà ce rôle. ». Aucune seconde ligne au journal. Le refus est relu sous verrou par le serveur, jamais un 403.
  - Réf. : 20 § 2.8 · ChangeUserRole::refuseIfInvalid · admin.access.errors.unchanged
- [ ] **Voir refuser un rôle privilégié à une adresse non vérifiée** ⚠️
  - Faire : Chercher l’adresse du compte créé sur /register et resté non vérifié. Cliquer « Changer le rôle », choisir Curateur, saisir un nom réel valide, puis envoyer.
  - Attendu : Avant l’envoi, un encadré rouge : « L’adresse de ce compte n’est pas vérifiée : un rôle privilégié lui sera refusé tant qu’elle ne l’est pas. ». Après l’envoi, sous « Nouveau rôle » : « L’adresse de ce compte n’est pas vérifiée : un rôle privilégié ne peut pas lui être attribué. ». Rien ne change. Pour débloquer, il faut vérifier l’adresse : le lien est écrit dans storage/logs/laravel.log (`MAIL_MAILER=log` ; l’e-mail part pendant la requête, sans passer par la file) et s’ouvre dans la fenêtre où ce compte est connecté. La même promotion passe ensuite.
  - Réf. : 20 § 2.8 · admin.access.errors.email_unverified
- [ ] **Constater que le compte promu doit enrôler sa 2FA**
  - Faire : Dans une fenêtre privée, se connecter en player@tripleframes.test (désormais curateur), puis ouvrir /admin.
  - Attendu : Redirection vers /admin/two-factor (« Activez la double authentification pour entrer »). Une fois la 2FA confirmée, le compte entre au back-office en curateur, sans groupe « Administration ». Côté admin, il quitte la file « Rôle privilégié sans double authentification ».
  - Réf. : 20 § 2.4, § 2.8
- [ ] **Rétrograder un curateur en joueur** ⭐
  - Faire : Dans « Comptes privilégiés », cliquer « Changer le rôle » sur Joueur Démo, choisir « Joueur », puis envoyer. Ouvrir ensuite sa fiche, cliquer « Changer le rôle » et choisir Curateur, sans envoyer.
  - Attendu : Aide affichée : « Le compte perd tout accès au back-office. Son nom réel est conservé, et ses preuves déjà signées restent intactes. ». Toast « Rôle changé et inscrit au journal. ». La ligne disparaît des comptes privilégiés et le focus revient sur la liste. La fiche garde « Nom réel » = Dominique Essai. Quand on choisit de nouveau Curateur, aucun champ de nom réel n’est redemandé.
  - Réf. : 20 § 2.8 · EL19-2
- [ ] **Constater que le compte rétrogradé perd l’accès**
  - Faire : Avec APP_DEBUG=false, dans la fenêtre privée où Joueur Démo était entré au back-office, recharger /admin après sa rétrogradation.
  - Attendu : Refus 403, page d’erreur du site « Accès refusé » (« Vous n’avez pas l’autorisation d’ouvrir cette page. »). Le back-office lui est fermé dès la requête suivante.
  - Réf. : 20 § 2.3 (role:curator)
- [ ] **Nommer un second administrateur**
  - Faire : Sur la ligne de Curateur Démo, cliquer « Changer le rôle », choisir Administrateur, puis envoyer. Le nom réel existe déjà : aucun champ n’est demandé.
  - Attendu : L’encadré « Un second administrateur nominatif manque » disparaît. Les deux administrateurs sont listés en tête, par nom réel : Alex… puis Camille…. Dans l’annuaire, « Administrateurs » passe à 2. Côté curateur, le groupe « Administration » apparaît au rechargement.
  - Réf. : 20 § 2.8 (NOMINATIVE_ADMINS = 2) · 00 § Gouvernance
- [ ] **Corriger le nom réel d’un autre compte privilégié** ⭐
  - Faire : Sur la ligne de Curateur Démo, cliquer « Corriger le nom réel ». Remplacer le nom pré-rempli par « Camille Démo-Curation Corrigée », ajouter un motif, puis cliquer « Corriger le nom réel ».
  - Attendu : La boîte « Corriger le nom réel de Curateur Démo » affiche l’encadré « La correction ne vaut que pour les gestes suivants : les revues et les lignes du journal déjà signées gardent l’ancien nom. ». Tant que le nom est inchangé, le bouton est grisé et l’aide dit « Saisissez un nom différent du nom actuel. ». Après l’envoi : toast « Nom réel corrigé et inscrit au journal. ». L’historique porte « Nom réel corrigé », changement « Aucun », signé « Alex Démo-Administration », avec le motif.
  - Réf. : 20 § 2.8 · CorrectRealName · EN20-3 · D40 du 28/09
- [ ] **Voir refuser une correction de nom réservé ou identique** ⚠️
  - Faire : Dans « Corriger le nom réel », saisir « Console » et envoyer. Saisir ensuite le nom actuel suivi d’une espace. Pour le refus serveur du nom identique : ouvrir /admin/access dans deux onglets. Onglet 1 : corriger le nom d’un curateur en « X Y ». Onglet 2, sans recharger : ouvrir « Corriger le nom réel » sur le même compte (l’ancien nom est pré-rempli), saisir « X Y », puis envoyer.
  - Attendu : « Console » est refusé : « Ce nom est réservé au journal d’administration : saisissez le nom réel de la personne. ». Le nom actuel suivi d’une espace est rogné, donc identique : le bouton reste grisé. Dans l’onglet 2, sous le champ : « Ce nom est déjà le nom réel du compte. ». Aucune ligne n’est ajoutée au journal.
  - Réf. : 20 § 2.8 · CorrectRealName · admin.access.errors.real_name_unchanged
- [ ] **Corriger son propre nom réel**
  - Faire : Sur sa propre ligne (« Votre compte »), cliquer « Corriger le nom réel », saisir un nouveau nom, puis envoyer.
  - Attendu : Le nom est corrigé. La ligne « Nom réel corrigé » de l’historique est signée de l’ANCIEN nom (« Alex Démo-Administration »), celui sous lequel le geste a été fait. Les gestes suivants sont signés du nouveau nom.
  - Réf. : 20 § 2.8 (signée à l’instant du geste) · CorrectRealName
- [ ] **Vérifier qu’un administrateur ne peut pas toucher son propre rôle** ⚠️
  - Faire : Chercher sa propre adresse dans « Promouvoir un compte existant », puis ouvrir sa propre fiche.
  - Attendu : La ligne affiche « Votre compte » à la place de « Changer le rôle ». La fiche montre l’encadré « C’est votre compte… ». Aucun chemin de l’interface ne permet de se rétrograder. Les refus serveur « Vous ne pouvez pas changer votre propre rôle : un autre administrateur doit le faire. » et « C’est le dernier administrateur du projet : … » ne sont atteignables que par une requête forgée ; la suite Pest les couvre.
  - Réf. : 20 § 2.8 · EL19-1 · admin.access.errors.self / last_admin
- [ ] **Faire se croiser deux administrateurs** ⚠️
  - Faire : Prérequis : Curateur Démo est administrateur, 2FA confirmée, et APP_DEBUG=false. Fenêtre B (Curateur Démo) : ouvrir la fiche d’Admin Démo, cliquer « Changer le rôle », choisir Curateur, sans envoyer. Fenêtre A (Admin Démo) : rétrograder Curateur Démo en Curateur. Revenir dans B et envoyer.
  - Attendu : L’envoi de B est refusé par une page 403 « Accès refusé » : B n’est plus administrateur. Admin Démo reste administrateur, et l’historique ne porte que la ligne du geste de A. L’encadré « Un second administrateur nominatif manque » réapparaît.
  - Réf. : 20 § 2.8 (verrous, acteur relu sous verrou) · ChangeUserRole

### 9.5 Refus, robustesse, langue, thème et mobile

- [ ] **Faire refuser les écrans administrateur à un curateur** ⭐
  - Faire : Avec APP_DEBUG=false, en curator@tripleframes.test (curateur, 2FA ou non), taper directement /admin/users, /admin/access, /admin/users/1 et /admin/users/999999.
  - Attendu : Les quatre adresses rendent le même refus 403 : page d’erreur du site en français, « Accès refusé » / « Vous n’avez pas l’autorisation d’ouvrir cette page. ». Même réponse pour l’identifiant inexistant : aucun 404 ne trahit l’existence d’un compte. Pas de redirection vers l’enrôlement 2FA, car la porte « admin » passe avant.
  - Réf. : 20 § 2.8 (seconde porte role:admin) · routes/admin.php
- [ ] **Faire refuser l’annuaire à un joueur**
  - Faire : Avec APP_DEBUG=false, en player@tripleframes.test (joueur), ouvrir /admin/users.
  - Attendu : Refus 403 « Accès refusé », sans redirection vers l’enrôlement 2FA.
  - Réf. : 20 § 2.3 (role:curator)
- [ ] **Accéder à l’annuaire sans être connecté**
  - Faire : Dans une fenêtre privée non connectée, ouvrir /admin/users.
  - Attendu : Redirection vers /login, écran « Connexion à votre compte ».
  - Réf. : 20 § 2.3 (auth)
- [ ] **Perdre le réseau pendant un geste d’accès** ⚠️
  - Faire : Ouvrir « Changer le rôle » sur un compte et choisir un autre rôle. Dans les DevTools, onglet Réseau, passer en « Offline », puis cliquer « Changer le rôle ».
  - Attendu : Toast « Connexion perdue : rien n’a été envoyé et votre saisie est conservée. Vérifiez votre réseau, puis réessayez. ». La boîte reste ouverte avec la saisie, et rien ne change côté serveur. De retour en ligne, l’envoi passe.
  - Réf. : 20 § 13.5 · admin.common.offline
- [ ] **Mener un geste d’accès au clavier seul**
  - Faire : Sur /admin/access, atteindre « Changer le rôle » d’une ligne avec Tab, l’ouvrir avec Entrée, parcourir la boîte avec Tab, puis fermer avec Échap. Recommencer en rétrogradant un curateur en joueur.
  - Attendu : Le focus reste piégé dans la boîte, dont le bouton de fermeture s’annonce « Fermer ». Échap ferme la boîte et rend le focus au bouton d’origine. Quand la ligne disparaît après la rétrogradation, le focus revient sur la liste des comptes privilégiés, avec un anneau visible. Les boutons font au moins 44 px de haut.
  - Réf. : 20 § 13.4 · role-change-dialog.tsx
- [ ] **Vérifier que le back-office reste en français pour un compte en anglais**
  - Faire : Connecté en admin@tripleframes.test, dont la langue est l’anglais, ouvrir / (qui s’affiche en anglais), puis /admin/users. Changer de langue sur le site public par le sélecteur « Changer de langue », puis revenir sur /admin/access.
  - Attendu : Les écrans /admin/* restent entièrement en français, sans aucune clé brute, quelle que soit la langue du compte ou du site.
  - Réf. : 20 § 13.2 · ForceAdminLocale · décision 9
- [ ] **Afficher les écrans administrateur en thème sombre**
  - Faire : Sur /settings/appearance, choisir « Sombre », puis ouvrir /admin/users, une fiche et /admin/access. Revenir ensuite sur « Système ».
  - Attendu : Le back-office suit le thème choisi, sans forçage (D8 du 23/09). Les tuiles, les badges (dont le rouge « Sans double authentification ») et les encadrés restent lisibles.
  - Réf. : 90 § 2.2 · D8 du 23/09
- [ ] **Afficher les écrans administrateur à 375 px de large**
  - Faire : Dans les DevTools, activer le mode appareil à 375 px de large. Ouvrir /admin/users, une fiche, /admin/access et une boîte « Changer le rôle ».
  - Attendu : La page ne défile jamais horizontalement : les tableaux défilent dans leur carte. Le bouton de menu de l’en-tête (nom accessible « Navigation du back-office ») ouvre une feuille titrée « Menu du back-office », refermable par « Fermer ». La boîte de dialogue tient dans l’écran et défile en interne. Les boutons sont touchables (44 px ou plus).
  - Réf. : REPRISE § 3 geste 11 (a) · 20 § 13.4 · admin-header.tsx

### 9.6 Commandes de console sans risque

- [ ] **Lister les commandes du projet**
  - Faire : Lancer `php artisan list`.
  - Attendu : Les commandes du projet apparaissent, décrites en français : admin:first-admin, answers:collisions, backup:prune-snapshots, backup:snapshot, catalog:import-discover, catalog:import-ids, catalog:reproject, deploy:drain, deploy:guard, deploy:release, game:reschedule, lang:hash, lang:types, loadtest:forget, purge:resume, purge:run, purge:suspend, room:archive-idle et room:derivations-fixture. Il n’y a pas de catalog:import.
  - Réf. : app/Console/Commands
- [ ] **Lire la planification** ⭐
  - Faire : Lancer `php artisan schedule:list`.
  - Attendu : Six tâches, en UTC : `* * * * * 30s` WorkerHeartbeat (game) ; `* * * * *` WorkerHeartbeat (default) ; `*/10 * * * *` php artisan room:archive-idle ; `10 2 * * *` RunRetentionPurge ; `50 2 * * *` php artisan backup:prune-snapshots ; `10 4 * * 1` ReportBruteForce. Code 0.
  - Réf. : 100 § 10.7 · routes/console.php
- [ ] **Interroger la garde de déploiement hors fenêtre** ⭐
  - Faire : Sans drainage en cours, lancer `php artisan deploy:guard`.
  - Attendu : « ERROR Garde refusée : aucune fenêtre libre ouverte. Lancez deploy:drain jusqu’à la fenêtre libre, puis relancez le déploiement. », code 2. Aucun état n’est modifié.
  - Réf. : 100 § 11.3 · DeployGuardCommand
- [ ] **Rattraper les parties quand aucune n’est en cours**
  - Faire : Sans partie en cours, lancer `php artisan game:reschedule`.
  - Attendu : Aucune sortie, code 0. La commande est idempotente.
  - Réf. : 60 § 17.5 · GameRescheduleCommand
- [ ] **Lire le rapport de collisions des réponses**
  - Faire : Lancer `php artisan answers:collisions`, puis `php artisan answers:collisions --json`.
  - Attendu : Un tableau aux colonnes normalizedA, movieA.id, movieA.titleOriginal, kindA, normalizedB, movieB.id, movieB.titleOriginal, kindB, distance. Il peut être vide sur le catalogue de démonstration : le préfixe « star wars », dérivé des deux côtés, n’y figure pas. `--json` rend la même liste (`[]` si elle est vide). Code 0, rien n’est écrit.
  - Réf. : 70 § 13.1 · L70-11 · AnswersCollisionsCommand
- [ ] **Vérifier et régénérer les types de traduction**
  - Faire : Lancer `php artisan lang:types --check`, puis `php artisan lang:types`, puis `git status`.
  - Attendu : `--check` affiche « Types de traduction à jour. », code 0. Sans l’option : « resources/js/types/translations.d.ts généré. ». `git status` ne montre aucune différence.
  - Réf. : 05 · LangTypesCommand
- [ ] **Vérifier la fixture des dérivations de réglages**
  - Faire : Lancer `php artisan room:derivations-fixture --check`.
  - Attendu : « tests/Fixtures/room/derivations.json est à jour. », code 0, rien n’est écrit. La commande est désactivée en production.
  - Réf. : 50 · RoomDerivationsFixtureCommand
- [ ] **Lister les travaux échoués**
  - Faire : Lancer `php artisan queue:failed`.
  - Attendu : « No failed jobs found. » sur une base propre, sinon un tableau des travaux échoués. Code 0.
  - Réf. : 100 § 10 · file database
- [ ] **Demander un instantané seulement s’il reste une migration en attente**
  - Faire : Lancer `php artisan backup:snapshot --if-pending` sur une base à jour.
  - Attendu : « Aucune migration en attente : aucun instantané n’est nécessaire. », code 0, aucun fichier écrit. Ce cas n’a pas besoin de `mysqldump`.
  - Réf. : 100 § 13.1 · BackupSnapshotCommand
- [ ] **Élaguer les instantanés**
  - Faire : Lancer `php artisan backup:prune-snapshots`.
  - Attendu : « Élagage des instantanés terminé. Instantanés supprimés : 0. », code 0. Le nombre est plus élevé seulement s’il existe des instantanés de plus de 7 jours dans storage/app/snapshots (BACKUP_SNAPSHOT_KEEP_DAYS, bornée entre 1 et 30 jours). Un instantané récent n’est jamais supprimé.
  - Réf. : 100 § 13.1 · BackupPruneSnapshotsCommand
- [ ] **Reprojeter le catalogue de façon idempotente**
  - Faire : Lancer `php artisan catalog:reproject` deux fois, puis `php artisan catalog:reproject --movie=1 --movie=2`.
  - Attendu : À chaque passage : « Reprojection terminée. Films reprojetés par différence : 16. » (catalogue de démonstration seul). Puis « … : 2. ». Code 0. La commande n’écrit que movie_projection et answer_key, jamais les cinq tables de la règle 12.
  - Réf. : 10 § 3.2 · CatalogReprojectCommand
- [ ] **Passer un identifiant illisible à la reprojection** ⚠️
  - Faire : Lancer `php artisan catalog:reproject --movie=abc`.
  - Attendu : « Reprojection terminée. Films reprojetés par différence : 0. », code 0. Une faute de frappe ne reprojette jamais tout le catalogue.
  - Réf. : CatalogReprojectCommand::requestedIds

### 9.7 Premier administrateur — admin:first-admin

- [ ] **Relancer la commande sur l’administrateur en place** ⭐
  - Faire : Lancer `php artisan admin:first-admin admin@tripleframes.test`.
  - Attendu : « Le compte admin@tripleframes.test est déjà administrateur : rien à faire. », code 0. Aucune ligne n’est ajoutée au journal.
  - Réf. : 20 § 2.5 · FirstAdminCommand (idempotence)
- [ ] **Voir refuser un second administrateur sans --force**
  - Faire : Lancer `php artisan admin:first-admin curator@tripleframes.test --real-name="Camille Demo"`, puis `php artisan admin:first-admin inconnu@exemple.test`.
  - Attendu : Dans les deux cas : « Un administrateur existe déjà (Admin Démo). Employez --force pour en promouvoir un second. », code 1, rien n’est modifié. Le refus du doublon passe avant toute recherche de compte.
  - Réf. : 20 § 2.5
- [ ] **Voir refuser une adresse inconnue sans --create**
  - Faire : Lancer `php artisan admin:first-admin inconnu@exemple.test --force`.
  - Attendu : Avertissement « Un administrateur existe déjà (Admin Démo) : promotion forcée. », puis « Aucun compte pour inconnu@exemple.test. Cette commande promeut un compte existant ; employez --create pour le créer, ou inscrivez-vous d’abord normalement. », code 1. Aucun compte créé.
  - Réf. : 20 § 2.5 · FirstAdminCommand::draftAccount
- [ ] **Voir refuser une adresse mal formée** ⚠️
  - Faire : Lancer `php artisan admin:first-admin pas-une-adresse`.
  - Attendu : « Adresse e-mail invalide. », suivi de « Le champ adresse e-mail doit être une adresse e-mail valide », code 1.
  - Réf. : FirstAdminCommand::resolveEmail
- [ ] **Se faire demander l’adresse en mode interactif**
  - Faire : Dans un vrai terminal, lancer `php artisan admin:first-admin` sans argument, puis répondre par une adresse vide.
  - Attendu : L’invite « Adresse e-mail du compte à promouvoir » s’affiche. Une réponse vide donne « Adresse e-mail invalide. » et le détail de validation, code 1.
  - Réf. : FirstAdminCommand::resolveEmail
- [ ] **Forcer sans nom réel en mode non interactif** ⚠️
  - Faire : Lancer `php artisan admin:first-admin player@tripleframes.test --force --no-interaction`, Joueur Démo étant joueur à cet instant.
  - Attendu : « Un administrateur existe déjà (Admin Démo) : promotion forcée. », puis « Nom réel requis : relancez avec --real-name="Prénom Nom". Rien n’a été modifié. », code 1, même si le compte porte déjà un nom réel. Dans l’annuaire, Joueur Démo reste Joueur.
  - Réf. : 20 § 2.5 · D12 du 23/09
- [ ] **Refuser --create en mode non interactif** ⚠️
  - Faire : Lancer `php artisan admin:first-admin inconnu@exemple.test --create --force --no-interaction`.
  - Attendu : Avertissement de promotion forcée, puis « Aucun compte pour inconnu@exemple.test, et la session n’est pas interactive : rien n’a été créé. », code 1. Le mot de passe ne s’obtient que par une invite masquée : aucune option --password n’existe.
  - Réf. : 20 § 2.5
- [ ] **Voir refuser un nom réel réservé** ⚠️
  - Faire : Lancer `php artisan admin:first-admin admin@tripleframes.test --real-name=console`.
  - Attendu : « Nom réel invalide. », puis en liste « Ce nom est réservé au journal d’administration : saisissez le nom réel de la personne. », code 1. Rien n’est modifié.
  - Réf. : 20 § 2.6 · RealNameValidationRules
- [ ] **Corriger le nom réel de l’administrateur en place par la console** ⚠️
  - Faire : Lancer `php artisan admin:first-admin admin@tripleframes.test --real-name="Alex Demo Console"` (en ASCII), puis ouvrir /admin/access. Remettre ensuite le nom d’origine par « Corriger le nom réel » à l’écran.
  - Attendu : « Nom réel de admin@tripleframes.test corrigé. Les revues et les lignes de journal déjà signées gardent l’ancien nom. », code 0. Le nom réel change à l’écran, mais l’historique ne reçoit AUCUNE ligne : la console n’est pas un acteur admis pour user.real_name_changed (EN20-3).
  - Réf. : 20 § 2.5 · EN20-3
- [ ] **Forcer la promotion d’un second administrateur par la console** ⚠️
  - Faire : Avec un seul administrateur en place, lancer `php artisan admin:first-admin player@tripleframes.test --force --real-name="Testeur Console"`, puis ouvrir /admin/access. Défaire ensuite à l’écran : « Changer le rôle » → Joueur.
  - Attendu : Avertissement de promotion forcée, puis « Joueur Démo (player@tripleframes.test) est désormais administrateur. », code 0. L’historique porte « Changement de rôle », « <rôle précédent> → Administrateur », signé « Accès direct au serveur (premier administrateur) », motif « Aucun ». Le nom réel devient « Testeur Console », sans ligne « Nom réel corrigé ». L’encadré « second administrateur » disparaît, et le compte rejoint la file « sans double authentification ».
  - Réf. : 20 § 2.5, § 2.7 (acteur console) · AdminJournal::recordFromConsole
- [ ] **Créer un compte administrateur de façon interactive** ⚠️
  - Faire : Dans un vrai terminal, lancer `php artisan admin:first-admin nouvel@exemple.test --create --force`. À la question « Aucun compte pour nouvel@exemple.test. Le créer maintenant ? », répondre non. Relancer et répondre oui, puis renseigner « Nom affiché », « Mot de passe », « Confirmation du mot de passe » et « Nom réel (il signe les revues et le journal d’administration) ».
  - Attendu : Réponse non : « Abandon : rien n’a été modifié. », code 1. Réponse oui : les mots de passe ne s’affichent pas à la saisie. Puis « Compte nouvel@exemple.test créé. » et « <nom> (nouvel@exemple.test) est désormais administrateur. », code 0. L’adresse est posée vérifiée : l’accès au shell vaut preuve.
  - Réf. : 20 § 2.5 · FirstAdminCommand::draftAccount

### 9.8 Instantané, import TMDB et reprojection

- [ ] **Prendre un instantané bloquant** ⭐
  - Faire : Lancer `php artisan backup:snapshot`, sans option.
  - Attendu : Avec `mysqldump` disponible : « Instantané écrit et vérifié : …storage/app/snapshots/snapshot-AAAAMMJJTHHMMSSZ.sql.gz », code 0. Sans `mysqldump`, comme sur le poste Windows au 28/09 : « Instantané impossible ou invérifiable : aucun fichier n’a été conservé. Ne lancez ni la migration ni le geste prévu tant que cette commande n’a pas réussi. », suivi d’un diagnostic, code 1. Aucun fichier ni `.partial` ne reste.
  - Réf. : 100 § 13.1 · règle 12 · BackupSnapshotCommand
- [ ] **Simuler l’import d’un identifiant TMDB** ⭐
  - Faire : Lancer `php artisan catalog:import-ids 550 --dry-run -v`.
  - Attendu : Une barre de progression et la ligne « #550 : simulated ». Puis « Simulation : aucune ligne écrite, pas même le balayage. » et un tableau Balayage | Vus | Importés | Écartés | Refusés (contenu) : « simulation (paste) », 1, 1, 0, 0. Code 0. Rien n’apparaît dans /admin/import ni dans /admin/catalog.
  - Réf. : 20 § 3.3 · CatalogImportIdsCommand
- [ ] **Lancer un collage sans identifiant** ⚠️
  - Faire : Lancer `php artisan catalog:import-ids`, sans argument.
  - Attendu : « Aucun identifiant lisible : passez-les en arguments ou en --file=<chemin>. Les URL TMDB de la forme https://www.themoviedb.org/movie/1234-un-slug sont acceptées. », code 1. Aucun appel TMDB.
  - Réf. : 20 § 3.3
- [ ] **Combiner simulation et reprise** ⚠️
  - Faire : Lancer `php artisan catalog:import-ids 550 --dry-run --resume`.
  - Attendu : « Une simulation ne reprend aucun balayage : --dry-run et --preview ne se combinent pas avec --resume. », code 1.
  - Réf. : 20 § 3.3 · admin.console.import.simulation_resume
- [ ] **Passer un jeton d’aperçu invalide** ⚠️
  - Faire : Lancer `php artisan catalog:import-ids 550 --preview=abc --actor=1`.
  - Attendu : « Aperçu refusé : --preview attend un jeton de 32 caractères hexadécimaux et --actor l’identifiant numérique de son auteur. », code 1. Rien n’est écrit.
  - Réf. : 20 § 3.3 · admin.console.import.preview_invalid
- [ ] **Reprendre un collage quand il n’y en a aucun**
  - Faire : Lancer `php artisan catalog:import-ids 550 --resume` alors qu’aucun collage n’est en cours.
  - Attendu : « Aucun collage à reprendre. », code 0. Aucun appel TMDB, rien n’est écrit.
  - Réf. : 20 § 3.3 · CatalogImportIdsCommand
- [ ] **Simuler un import depuis un fichier**
  - Faire : Écrire un fichier texte avec une URL TMDB par ligne, par exemple `https://www.themoviedb.org/movie/550-fight-club`. Lancer `php artisan catalog:import-ids --file=<chemin> --dry-run`.
  - Attendu : L’URL est lue comme l’identifiant 550. Le tableau affiche « simulation (paste) » avec Vus 1. Code 0, rien n’est écrit.
  - Réf. : 20 § 3.3
- [ ] **Simuler un balayage discover**
  - Faire : Lancer `php artisan catalog:import-discover --dry-run --pages=1`.
  - Attendu : Une barre de progression, « Simulation : aucune ligne écrite, pas même le balayage. », puis un tableau « simulation (discover) » avec le nombre de films vus sur une page. Code 0.
  - Réf. : 20 § 3.2 · CatalogImportDiscoverCommand
- [ ] **Élargir le filtre de notoriété**
  - Faire : Lancer `php artisan catalog:import-discover --dry-run --pages=1 --min-votes=100`.
  - Attendu : Avertissement « Filtre ÉLARGI par rapport au défaut du site : les films entrés par ce balayage seront marqués `is_import_exception`, avec leurs motifs. », puis la simulation.
  - Réf. : 20 § 9.1 · décision 11
- [ ] **Reprendre un balayage discover quand il n’y en a aucun**
  - Faire : Lancer `php artisan catalog:import-discover --resume` alors qu’aucun balayage discover n’est en cours.
  - Attendu : « Aucun balayage `discover` à reprendre. », code 0.
  - Réf. : 20 § 3.2
- [ ] **Importer réellement un film (écrit dans le catalogue)** ⚠️
  - Faire : D’abord `php artisan backup:snapshot`, code 0 exigé : en local, la commande d’import n’en prend pas elle-même. Puis `php artisan catalog:import-ids 550 -v`, `php artisan catalog:reproject`, puis de nouveau `php artisan catalog:import-ids 550 -v`.
  - Attendu : Premier import : un tableau « #<n> (paste) » avec Importés 1, code 0. Le film apparaît en « Brouillon » dans /admin/catalog, entré par exception. La reprojection annonce 17 films. Le second import affiche « #550 : duplicate » et compte le film en « Écartés », sans doublon.
  - Réf. : règle 12 · 20 § 3.3 · ImportSnapshotGuard
- [ ] **Voir refuser un instantané sur une base non MySQL** ⚠️
  - Faire : Dans PowerShell : `$env:DB_CONNECTION='sqlite'; php artisan backup:snapshot; echo $LASTEXITCODE; Remove-Item Env:DB_CONNECTION`. La variable du processus prime sur `.env`, qui n’est pas modifié.
  - Attendu : « Instantané impossible : la connexion par défaut emploie le pilote sqlite, et seul un vidage MySQL est pris en charge. Aucun instantané n’a été pris. », code 1. Aucun fichier n’apparaît dans storage/app/snapshots.
  - Réf. : 100 § 13.1 · BackupSnapshotCommand (SUPPORTED_DRIVERS)
- [ ] **Lancer un import sans clé TMDB** ⚠️
  - Faire : Vider `TMDB_API_KEY` et `TMDB_API_READ_ACCESS_TOKEN` dans `.env`, lancer `php artisan config:clear`, puis `php artisan catalog:import-ids 550 --dry-run` et `php artisan catalog:import-discover --dry-run --pages=1`. Restaurer les clés à la fin.
  - Attendu : Les deux commandes répondent « Aucune clé TMDB configurée : posez TMDB_API_READ_ACCESS_TOKEN ou TMDB_API_KEY dans .env. L’import est désactivé, rien d’autre ne l’est. », code 1, sans appel à TMDB ni écriture. Le reste du site fonctionne.
  - Réf. : CatalogImportCommand::assertConfigured · 100 (CI à zéro secret)
- [ ] **Combiner un aperçu avec --resync** ⚠️
  - Faire : Lancer `php artisan catalog:import-ids 550 --preview=0123456789abcdef0123456789abcdef --actor=1 --resync`.
  - Attendu : « Aperçu refusé : --preview ne se combine ni avec --resync ni avec --resume. », code 1. Rien n’est écrit et TMDB n’est pas appelé.
  - Réf. : 20 § 3.3 · admin.console.import.preview_exclusive
- [ ] **Resynchroniser un film importé par la console (--resync)** ⚠️
  - Faire : Après l’import de `550`, corriger son titre français sur la fiche (origine « Curateur »). Lancer `php artisan backup:snapshot` (code 0 exigé), puis `php artisan catalog:import-ids 550 --resync -v`. Ouvrir ensuite /admin/import et la fiche du film.
  - Attendu : La console affiche la ligne « #550 : resynchronized » et un tableau « #<n> (resync) » avec Importés 1, code 0. Le journal des balayages montre la nature « Resynchronisation ». Sur la fiche, le titre corrigé par le curateur reste en place, comme la disponibilité, le groupe, les motifs d’exception et les images : la resynchronisation n’écrase que la liste close des champs TMDB.
  - Réf. : 20 § 9.3 · CatalogImportIdsCommand (--resync) · MovieImporter

### 9.9 Drainage de déploiement, effet dans le jeu et rattrapage

- [ ] **Drainer sans partie en cours** ⭐
  - Faire : Lancer `php artisan deploy:drain` sans aucune partie en cours.
  - Attendu : « Drainage commencé : aucune nouvelle partie ne peut plus être lancée. Attente de la fin des parties en cours. ». Environ 15 s plus tard, après deux relevés nuls : « Fenêtre libre ouverte jusqu’à <instant UTC> : aucune partie en cours. Vérifiez deploy:guard, puis cliquez « Déployer » dans Plesk. ». Code 0.
  - Réf. : 100 § 11.3 · D32 du 23/09 · DeployDrainCommand
- [ ] **Voir le bandeau de maintenance sur le site public** ⭐
  - Faire : Pendant le drainage ou la fenêtre libre, recharger / en français, puis passer en anglais (« Changer de langue »).
  - Attendu : Bandeau FR : « Une mise à jour du site est en préparation : aucune nouvelle partie ne peut être lancée pour le moment. Les parties en cours continuent normalement. ». Bandeau EN : « A site update is being prepared: no new game can be started for now. Games in progress carry on as usual. ». Ni heure, ni phase, ni nombre de parties.
  - Réf. : 90 § 3.3 · maintenance-banner.tsx · common.maintenance.banner
- [ ] **Constater que le lancement est bloqué dans le lobby** ⭐
  - Faire : Créer un salon sur /r/new (« Créer le salon ») : la création reste permise pendant le drainage. Puis, pendant le drainage, recharger la page du lobby vue de l’hôte.
  - Attendu : Le bandeau apparaît en tête de l’écran de jeu. « Lancer la partie » est inactif, avec le motif « Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. Réessayez un peu plus tard. ». Le serveur refuse aussi tout lancement.
  - Réf. : 50 § 8.1 · OpenGame · pages/game/lobby.tsx
- [ ] **Constater que le solo est bloqué pendant le drainage**
  - Faire : Pendant le drainage, dans une fenêtre privée neuve (sans siège solo), ouvrir /solo/new. Choisir un preset, un pseudo et un avatar, puis « Commencer l’entraînement ».
  - Attendu : Le bandeau de maintenance est en tête de page. Aucune partie n’est créée. Sous le groupe des presets s’affiche « Une mise à jour du site est en préparation : impossible de lancer une partie pour le moment. Réessayez un peu plus tard. ».
  - Réf. : 60 · StartSoloGame · pages/room/solo.tsx
- [ ] **Constater que « Rejouer » est bloqué sur le podium**
  - Faire : Terminer une partie, puis lancer `php artisan deploy:drain` et recharger le podium vu de l’hôte.
  - Attendu : « Rejouer » est inactif, avec le même motif de maintenance. Les autres joueurs voient toujours le podium.
  - Réf. : 50 § 13 · ReplayRoom
- [ ] **Franchir la garde dans la fenêtre libre**
  - Faire : Pendant la fenêtre ouverte par deploy:drain, lancer `php artisan deploy:guard`.
  - Attendu : « Garde franchie : fenêtre libre ouverte et aucune partie en cours. », code 0.
  - Réf. : 100 § 11.3, § 11.5
- [ ] **Relancer le drainage alors qu’un drapeau existe** ⚠️
  - Faire : Pendant la fenêtre, relancer `php artisan deploy:drain`.
  - Attendu : « Un drainage existe déjà (phase : fenêtre libre, échéance <instant>) : rien n’a été modifié. Attendez son issue, ou levez-le par deploy:release. », code 2.
  - Réf. : 100 § 11.3 (invariant 1)
- [ ] **Lever le drapeau et voir les lancements se réactiver sans rechargement** ⭐
  - Faire : Laisser ouvert un lobby, un podium ou l’écran de relance solo avec le bouton bloqué. Lancer `php artisan deploy:release`.
  - Attendu : « Drapeau de drainage levé : les lancements sont de nouveau permis. », code 0. En une dizaine de secondes (intervalle de battement de 10 s par défaut), « Lancer la partie », « Rejouer » ou la relance solo redeviennent actifs sans recharger la page (BUG-P2 corrigé). Le bandeau de / disparaît au chargement suivant.
  - Réf. : 100 § 11.3 · maintenance-refresh.ts · BUG-P2
- [ ] **Lever le drapeau quand il n’existe pas**
  - Faire : Relancer `php artisan deploy:release`.
  - Attendu : « Aucun drapeau de drainage : rien à lever. », code 0.
  - Réf. : DeployReleaseCommand
- [ ] **Passer une durée invalide au drainage** ⚠️
  - Faire : Lancer `php artisan deploy:drain --timeout=0`, puis `php artisan deploy:drain --window=abc`.
  - Attendu : « Durée refusée pour --timeout : un nombre entier de minutes, au moins 1, est attendu. Rien n’a été modifié. », puis la même chose pour --window. Code 2. Aucun bandeau n’apparaît.
  - Réf. : 100 § 11.3 · admin.console.deploy.invalid_minutes
- [ ] **Laisser expirer la fenêtre libre** ⚠️
  - Faire : Lancer `php artisan deploy:drain --window=1`. Attendre un peu plus d’une minute après l’ouverture de la fenêtre, puis lancer `php artisan deploy:guard` et recharger /.
  - Attendu : Le drapeau expire seul : la garde répond « Garde refusée : aucune fenêtre libre ouverte. … » (code 2), le bandeau disparaît et les lancements sont de nouveau permis. Sans option, la fenêtre dure 30 min (DEPLOY_WINDOW_MINUTES).
  - Réf. : 100 § 11.3 (TTL)
- [ ] **Drainer pendant une partie en cours** ⚠️
  - Faire : Lancer une partie solo (/solo/new) et la laisser tourner. Lancer `php artisan deploy:drain --timeout=1`, puis `php artisan deploy:guard` dans un autre terminal.
  - Attendu : Le drainage affiche d’abord le tableau de game:reschedule (mode, status, startedAt, roundsCompleted, roundsCount), puis « Parties encore en cours : 1. Relevé suivant dans quelques secondes. ». La partie continue normalement (paliers, saisie) et n’est jamais coupée. La garde répond « Garde refusée : parties en cours : 1. … », code 1. À l’échéance d’une minute : « Drainage abandonné : … ne déployez pas, et relancez deploy:drain le moment venu. », code 1, et le drapeau est levé. Si la partie finit avant l’échéance, la fenêtre s’ouvre à la place.
  - Réf. : 100 § 11.3 (invariants 3 à 6) · C18-bis
- [ ] **Rattraper une partie privée de sa file (game:reschedule)** ⚠️
  - Faire : Au lieu de `composer dev`, lancer séparément `php artisan serve`, `php artisan reverb:start`, `npm run dev` et, dans son propre terminal, `php artisan queue:listen --queue=game,default --tries=1 --timeout=900`. Démarrer une partie, puis arrêter seulement le terminal de la file (Ctrl+C) au milieu d’une manche. Attendre au-delà de la durée du palier, lancer `php artisan game:reschedule`, puis relancer la file.
  - Attendu : Sans file, l’image reste au palier courant au-delà de sa durée : le client n’ouvre aucun palier (règle 8). game:reschedule affiche le tableau des parties en cours (mode, status, startedAt, roundsCompleted, roundsCount), code 0. Au rechargement, la partie retrouve son état théorique. Une fois la file relancée, elle va jusqu’au bout. Relancée sans partie échue, la commande n’écrit rien.
  - Réf. : 60 § 17.5 · C17 · GameRescheduleCommand

### 9.10 Purge de rétention et archivage des salons

- [ ] **Suspendre la purge**
  - Faire : Lancer `php artisan purge:suspend` deux fois de suite.
  - Attendu : Premier passage : « Purge de rétention suspendue : aucun périmètre ne s’exécute jusqu’à purge:resume, et la sonde purge reste en alerte. », code 0. Second passage : « La purge de rétention est déjà suspendue : rien n’a été modifié. », code 0.
  - Réf. : 100 § 14 · PurgeSuspendCommand
- [ ] **Voir la purge refusée tant qu’elle est suspendue** ⚠️
  - Faire : Pendant la suspension, lancer `php artisan purge:run --sync`, puis `php artisan purge:run`.
  - Attendu : Les deux fois : « Purge de rétention suspendue : … », code 1. Rien n’est exécuté ni déposé.
  - Réf. : 100 § 14
- [ ] **Lever la suspension de la purge**
  - Faire : Lancer `php artisan purge:resume` deux fois de suite.
  - Attendu : Premier passage : « Suspension levée : la purge repart à sa prochaine exécution. », code 0. Second passage : « La purge de rétention n’est pas suspendue : rien n’a été modifié. », code 0.
  - Réf. : 100 § 14 · PurgeResumeCommand
- [ ] **Exécuter la purge dans le processus (supprime des données échues)** ⚠️
  - Faire : D’abord `php artisan backup:snapshot` (code 0). Puis `php artisan purge:run --sync`.
  - Attendu : « Purge de rétention exécutée. Périmètres : 6. Lignes traitées : <n>. », 0 ligne sur une base neuve, code 0. Les six périmètres sont stale_room, orphan_player, framework_sessions, framework_failed_jobs, framework_reset_tokens et purge_run : aucun ne touche le catalogue. En cas d’échec : « Périmètres en échec ou avec des lignes en échec : … », code 1.
  - Réf. : 100 § 14 · PurgeScope::implemented
- [ ] **Déposer la purge sur la file default** ⚠️
  - Faire : Après un instantané, lancer `php artisan purge:run` sans option et regarder le terminal de la file de `composer dev`. Relancer aussitôt une seconde fois.
  - Attendu : « Purge de rétention déposée sur la file default : un worker l’exécutera. », code 0. La file traite App\Jobs\Retention\RunRetentionPurge, jamais sur la file game. Si la première n’est pas encore terminée, le second passage répond « Une purge de rétention tient déjà le verrou d’unicité du job … : aucune nouvelle purge déposée. », code 1.
  - Réf. : 100 § 14 · RunRetentionPurge (unique)
- [ ] **Déposer le balayage des salons inactifs (archive des salons échus)** ⚠️
  - Faire : Après un instantané, lancer `php artisan room:archive-idle` et regarder le terminal de la file.
  - Attendu : Aucune sortie, code 0. La file traite App\Jobs\Room\ArchiveIdleRooms sur default et écrit une ligne purge_run pour le périmètre stale_lobby. Les salons échus sont archivés et leurs pseudos effacés : lobby jamais lancé et inactif depuis 2 h, ou tout salon inactif depuis 24 h.
  - Réf. : 50 § 16.2 · RoomArchiveIdleCommand · RoomExpiry
- [ ] **Archiver un lobby rendu inactif et voir « Salon expiré »** ⚠️
  - Faire : Créer un salon sur /r/new et noter son code. FERMER tous les onglets de ce salon, sinon le battement (toutes les 10 s par défaut) réécrit l’activité. Vieillir ensuite l’activité : `php artisan tinker --execute="App\Models\Room::where('room_code', 'CODE')->update(['last_activity_at' => now()->subHours(3)]);"`. Après un instantané, lancer `php artisan room:archive-idle`, attendre que la file l’ait traité, puis rouvrir /r/CODE.
  - Attendu : Réponse 410, page « Salon expiré » : « Ce salon a été fermé après une période d’inactivité. Créez-en un nouveau pour rejouer. », avec les boutons « Créer un salon » et « Accueil ». Les pseudos du salon sont effacés à l’archivage.
  - Réf. : 50 § 16.3 · L50-8 · RoomController (410)
- [ ] **Vérifier l’effacement des identifiants d’invité à l’archivage** ⚠️
  - Faire : Après l’item « Archiver un lobby rendu inactif et voir « Salon expiré » », lancer dans Git Bash : `php artisan tinker --execute='$r = App\Models\Room::where("room_code", "CODE")->first(); dump(App\Models\Player::where("room_id", $r->id)->pluck("nickname")->all(), App\Models\Player::where("room_id", $r->id)->whereNotNull("player_token_hash")->count());'`.
  - Attendu : La commande affiche une liste de `null`, une par siège (partis et expulsés compris), puis `0`. Le pseudo, sa forme repliée et le hash du jeton sont effacés dès l’archivage, sans attendre la purge de rétention. Rouvrir le lien avec l’ancien cookie mène toujours à « Salon expiré ».
  - Réf. : CLAUDE § 2 (identifiants d’invité effacés à l’archivage) · 50 § 16.2 · ArchiveRoom

### 9.11 Planificateur et file de travaux

- [ ] **Jouer une passe du planificateur**
  - Faire : Lancer `php artisan schedule:run` et regarder le terminal de la file de `composer dev`. Si la minute est un multiple de 10, room:archive-idle part aussi : il archive les salons échus.
  - Attendu : Les tâches dues s’exécutent : WorkerHeartbeat (game) et (default), plus room:archive-idle aux minutes multiples de 10. La commande reste active jusqu’à une minute pour la tâche à 30 s. La file traite les deux WorkerHeartbeat. Code 0.
  - Réf. : 100 § 10.7
- [ ] **Faire tourner le planificateur en continu**
  - Faire : Dans un terminal dédié, lancer `php artisan schedule:work` pendant quelques minutes, puis l’arrêter (Ctrl+C). `composer dev` ne lance pas de planificateur.
  - Attendu : Les battements partent toutes les 30 s (game) et chaque minute (default), et la file les traite. Les sondes worker-game et worker-default passent au vert (voir le groupe des sondes). Tant qu’il tourne, l’archivage des salons (toutes les 10 min) et la purge de 02:10 UTC s’appliquent à la base de développement.
  - Réf. : 100 § 10.7, § 15
- [ ] **Déclencher le rapport de force brute**
  - Faire : Lancer `php artisan schedule:test` et choisir la ligne App\Jobs\Ops\ReportBruteForce. Ouvrir ensuite storage/logs/game-<date du jour>.log.
  - Attendu : Le job passe par la file default. Une ligne JSON `ops.brute_force.report` apparaît avec k 10, window_days 7, since, until et count (0 sur une base de développement), sans aucune donnée de joueur. Le rapport n’est pas alertant.
  - Réf. : 70 § 13.2 · 100 § 15 · ReportBruteForce
- [ ] **Provoquer un travail échoué, le lire, puis l’oublier** ⚠️
  - Faire : Lancer `php artisan tinker --execute="dispatch(function () { throw new RuntimeException('essai de recette'); });"`. Regarder le terminal de la file, lancer `php artisan queue:failed`, puis `php artisan queue:forget <ID affiché>`.
  - Attendu : La file marque le travail en échec après une seule tentative (--tries=1). queue:failed le liste sur la file default, avec son identifiant. queue:forget le supprime, et queue:failed revient à « No failed jobs found. ». Les travaux échoués anciens sont aussi purgés par le périmètre framework_failed_jobs.
  - Réf. : 100 § 10 · composer dev (queue:listen --tries=1)

### 9.12 Sondes d’exploitation — /ops/probe/{probe}

- [ ] **Constater que les sondes répondent 404 sans jeton configuré** ⭐
  - Faire : Avec `OPS_PROBE_TOKEN` absent ou vide (c’est l’état du `.env` du poste), lancer `curl.exe -i -H "X-Probe-Token: nimporte" http://127.0.0.1:8000/ops/probe/integrity`.
  - Attendu : HTTP 404, corps vide, `Cache-Control: no-store, private`. Un jeton non configuré ferme toutes les sondes, avec ou sans en-tête.
  - Réf. : 100 § 15 · EnsureProbeToken
- [ ] **Interroger la sonde integrity avec le bon jeton** ⭐
  - Faire : Ajouter `OPS_PROBE_TOKEN=jeton-local-de-recette` dans `.env` (le serveur de composer dev redémarre seul ; sinon, le relancer). Lancer `curl.exe -i -H "X-Probe-Token: jeton-local-de-recette" http://127.0.0.1:8000/ops/probe/integrity`. Retirer la variable après la recette.
  - Attendu : Sur une base saine : HTTP 200 et `{"status":"ok"}`, sans autre donnée. Sinon : 503 et `{"status":"stale"}`. Le motif est alors écrit dans storage/logs/laravel.log (« Sonde d’exploitation en alerte. »), jamais dans la réponse.
  - Réf. : 100 § 15 · ProbeController · IntegrityProbe
- [ ] **Vérifier que jeton absent, jeton faux et sonde inconnue répondent pareil**
  - Faire : Avec le jeton configuré, appeler /ops/probe/integrity sans en-tête, puis avec `X-Probe-Token: faux`, puis /ops/probe/inconnue avec le bon jeton.
  - Attendu : Trois réponses 404 à corps vide, strictement identiques : on ne peut pas sonder l’existence des sondes. Les cinq sondes connues sont worker-game, worker-default, load, integrity et purge.
  - Réf. : 100 § 15 · ProbeResponse::notFound · OpsProbe
- [ ] **Faire passer les sondes des workers au vert puis au rouge**
  - Faire : Sans planificateur, appeler /ops/probe/worker-game et /ops/probe/worker-default avec le jeton. Lancer ensuite `php artisan schedule:work` (file active), attendre environ une minute et rappeler. Arrêter enfin schedule:work et rappeler worker-game après 90 s.
  - Attendu : Sans planificateur : 503 `{"status":"stale"}` (aucun battement). Après une minute de schedule:work : 200 `{"status":"ok"}` pour les deux. Après l’arrêt : worker-game repasse en 503 au-delà de 90 s, worker-default au-delà de 10 min.
  - Réf. : 100 § 15 · Heartbeat · config/ops.php
- [ ] **Interroger la sonde load sur le poste Windows** ⚠️
  - Faire : Appeler /ops/probe/load avec le jeton.
  - Attendu : 503 `{"status":"stale"}` sur Windows, où la charge est illisible (sys_getloadavg indisponible). Le journal dit « charge illisible ». C’est normal sur ce poste : la même sonde lit /proc sur la VM et sur le VPS Linux.
  - Réf. : 100 § 15 · LoadProbe · SystemLoad
- [ ] **Suivre la sonde purge d’un bout à l’autre**
  - Faire : Appeler /ops/probe/purge sur une base neuve. Après un instantané, lancer `php artisan purge:run --sync` puis `php artisan room:archive-idle` (traité par la file), et rappeler. Lancer `php artisan purge:suspend` et rappeler, puis `php artisan purge:resume` et rappeler.
  - Attendu : Base neuve : 503, faute d’exécution terminée depuis 48 h. Après la purge et le balayage des salons : 200. Suspendue : 503 tant que la suspension dure. Après reprise : 200.
  - Réf. : 100 § 14, § 15 · PurgeProbe
- [ ] **Lire les en-têtes d’une réponse de sonde**
  - Faire : Lancer `curl.exe -i` avec le jeton sur n’importe quelle sonde.
  - Attendu : `Cache-Control: no-store, private` et `X-Robots-Tag: noindex, nofollow`. Aucun cookie de session n’est posé : la route est hors du groupe web.
  - Réf. : 100 § 15 · routes/ops.php · RobotsDirectives
- [ ] **Dépasser le limiteur des sondes** ⚠️
  - Faire : Sous PowerShell, lancer `1..31 | % { curl.exe -s -o NUL -w "%{http_code} " -H "X-Probe-Token: jeton-local-de-recette" http://127.0.0.1:8000/ops/probe/integrity }` en moins d’une minute.
  - Attendu : Les 30 premiers appels répondent 200 ou 503, le 31ᵉ répond 429. Le limiteur ops-probe autorise 30 appels par minute et par adresse IP ; il passe avant la vérification du jeton, donc il borne aussi les essais de jeton.
  - Réf. : 100 § 15 · FortifyServiceProvider (ops-probe)

### 9.13 Traductions et nettoyage du test de charge

- [ ] **Écrire puis effacer l’empreinte des traductions**
  - Faire : Lancer `php artisan lang:hash`, puis `php artisan lang:hash --clear`.
  - Attendu : « Empreinte des traductions écrite : <empreinte> », et bootstrap/cache/lang-version.php est créé. Avec --clear : « Empreinte des traductions supprimée : elle sera recalculée à la volée. ». Sur le poste, toujours finir par --clear : un fichier laissé en place fige la version Inertia, et une traduction modifiée ne force plus de rechargement.
  - Réf. : 05 · LangHashCommand · LangVersion
- [ ] **Donner à loadtest:forget une liste de codes absente** ⚠️
  - Faire : Lancer `php artisan loadtest:forget absent.txt`.
  - Attendu : « Liste des codes illisible : absent.txt. Rien n’a été supprimé. », code 1.
  - Réf. : 100 § 16.5 · LoadTestForgetCommand
- [ ] **Simuler l’oubli d’un salon au pseudo réel**
  - Faire : Créer un salon sur /r/new avec un pseudo ordinaire. Écrire son code dans un fichier texte (une ligne), puis lancer `php artisan loadtest:forget <fichier> --dry-run`.
  - Attendu : « Salons écartés parce qu’au moins un de leurs sièges ne porte pas de pseudo synthétique : 1. Rien n’y a été supprimé. », puis « Simulation : salons synthétiques qui seraient oubliés : 0. Rien n’a été supprimé. », code 0.
  - Réf. : 100 § 16.5
- [ ] **Simuler l’oubli d’un salon synthétique**
  - Faire : Créer un salon dont l’hôte prend le pseudo « k6-1 », sans lancer de partie. Mettre son code dans le fichier, plus une ligne « ZZZZZZ » et une ligne « # commentaire ». Lancer `php artisan loadtest:forget <fichier> --dry-run`.
  - Attendu : « Codes sans salon actif : 1 (code inconnu, ou salon déjà archivé : … ». La ligne commentée est ignorée. Puis « Simulation : salons synthétiques qui seraient oubliés : 1. Rien n’a été supprimé. », code 0. Le salon est toujours là.
  - Réf. : 100 § 16.5 (préfixe k6-)
- [ ] **Oublier réellement un salon synthétique** ⚠️
  - Faire : Par prudence, `php artisan backup:snapshot` (code 0) d’abord. Lancer `php artisan loadtest:forget <fichier>` sans --dry-run, puis rouvrir /r/<code du salon k6-1> avec APP_DEBUG=false.
  - Attendu : « Salons synthétiques oubliés (faits de partie, sièges et salon) : 1. », code 0. Le salon n’existe plus : 404 « Page introuvable ». Les salons à siège réel ou à partie en cours restent intacts. Le journal applicatif ne reçoit que des nombres.
  - Réf. : 100 § 16.5 · L100-12
- [ ] **Voir écarté un salon synthétique en cours de partie** ⚠️
  - Faire : Créer un salon dont l’hôte prend le pseudo « k6-1 », faire entrer un second navigateur sous « k6-2 », puis lancer la partie. Écrire le code du salon dans un fichier et lancer, pendant la partie, `php artisan loadtest:forget <fichier> --dry-run`.
  - Attendu : « Salons écartés parce qu’une partie y est encore en cours : 1. Relancez la commande une fois ces parties terminées. », puis « Simulation : salons synthétiques qui seraient oubliés : 0. Rien n’a été supprimé. », code 0. La partie continue sans interruption.
  - Réf. : 100 § 16.5 · LoadTestForgetCommand (IN_PROGRESS)

### 9.14 Hors périmètre (non livré, ne pas essayer)

- `php artisan catalog:import` : n’existe pas comme commande (c’est la classe abstraite commune aux deux voies), bien que CLAUDE.md la cite. Employer catalog:import-ids ou catalog:import-discover.
- `backup:manifest` et `backup:verify` (manifeste des images, tier chaud chiffré vers le stockage objet) : absentes de app/Console/Commands.
- `catalog:themes` : J2 (spec 30).
- `php artisan pail` : inutilisable sur ce poste, faute d’ext-pcntl. Lire storage/logs/laravel.log et storage/logs/game-<date>.log.
- Gestes de modération de l’administrateur, sans écran ni route au J1 : suspension conservatoire et retrait juridique d’un film ou d’une image, masquage ou rétablissement d’un pseudo ou d’une photo, décision de retrait, fermeture ou réouverture du site. Leurs actions de journal sont définies, mais rien ne les déclenche depuis le back-office.
- Écran listant tout le journal admin_action : seuls existent l’historique des accès (/admin/access, 50 lignes) et l’historique d’une fiche de compte.
- Suppression ou anonymisation d’un compte : aucune route (profile.destroy retiré, UserPolicy sans delete), cycle de vie du compte en J2 (40). Le filtre « Anonymisés » reste donc vide.
- Réinitialiser le second facteur d’un compte privilégié (perte du téléphone au-delà des codes de secours) : ni écran ni commande livrés, cela relève de l’exploitation.
- Connexion OAuth Discord/Google, comptes liés et promotion d’un compte sans adresse (EL19-7) : J2.
- Mention de l’annuaire (adresse de chaque compte lue au back-office) dans la page de confidentialité : J2 (EL19-4).
- Refus serveur « son propre rôle » et « dernier administrateur » : injoignables depuis l’interface (boutons masqués), couverts par la suite Pest.
- Preuve MySQL du verrou des administrateurs (groupe locks-timing, EL19-8) : non écrite, faute de base MySQL de test.
- Limiteur admin-curation des gestes d’accès (60 par minute et par compte) : pas raisonnablement éprouvable à la main.
- Hook de déploiement Plesk réel, restauration chronométrée et séance de charge k6 : phase de production sur le VPS, hors du poste local.
- `cache:clear`, `storage:link` et `install:broadcasting` : à ne jamais lancer, ni en recette ni ailleurs.
