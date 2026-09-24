# Reprise — TripleFrames

**Dernière session : 23/09/2026 — specs du jalon 1 écrites.** `20`, `30`, `50`, `60`, `70` et `80` sont complètes ; `40`, `90` et `100` ont leur section du jalon 1 ; `00`, `05`, `10`, `questions-ouvertes.md` et `CLAUDE.md` sont amendés. Aux 19 décisions du 22/09 s'ajoutent les décisions du 23/09 : **S1 à S4** pour le cadre, **D1 à D37** pour le fond. Le lot 10 (D35 à D37) a fixé le périmètre et le rythme : **jalon 1 complet, sans aucune coupe** ; **développement confié à l'IA** ; **ordre « curation d'abord »**. Aucune ligne de code métier n'a été écrite pendant cette session : la suite est l'implémentation, dans l'ordre donné en annexe.

Ce fichier dit :

- où en est le projet ;
- ce qui est verrouillé ;
- ce qui bloque, c'est-à-dire le chemin humain ;
- ce qui reste ouvert ;
- par quoi reprendre.

Il est réécrit à chaque fin de session. Il a été réécrit en entier à la fin de la session du 23/09 — amendé le 23/09.

---

## 1. Où en est le projet

**Phase : implémentation du jalon 1.** Toutes les specs nécessaires au jalon 1 sont écrites. La section « Jalon 2 — à écrire » de `40`, de `90` et de `100` attend l'après-jalon 1 (S1 du 23/09). Le schéma est en base. Modèles, factories et seeders existent. Le socle i18n est complet, l'import TMDB fonctionne contre l'API réelle et un début de panel admin est en place. Le lot 0 (dette du starter) est purgé.

### Documents

| Fichier | Rôle | État au 23/09 | Taille |
|---|---|---|---|
| `docs/specs/00-overview.md` | Vue d'ensemble : concept, réglages, vocabulaire, principes, exploitation, jalons, carte des specs | v3, à jour des 19 décisions et de D1 à D37 du 23/09. § Jalons est recalculé : taille du J1, chemin humain, ordre « curation d'abord » | 498 l., 157 Ko |
| `docs/specs/05-i18n-et-langues.md` | Seul propriétaire de la règle de langue | écrite le 22/09, amendée le 23/09 | 382 l., 59 Ko |
| `docs/specs/10-catalogue-et-modele-de-donnees.md` | **Seul propriétaire du schéma** : 35 tables de domaine + `users` altérée, rétention, purge | écrite le 22/09, amendée le 23/09. Les 35 tables sont inchangées ; au J1, 4 colonnes nouvelles (`users.real_name`, `player.kicked_at`, `player.solo_token_hash`, `round_choice_set.rendered_locale`) arrivent par des migrations additives portées par leurs lots, plus l'élargissement de `game_player.final_rank` (`10` § 12, n° 43 à 47 ; voie selon I-12) — amendé le 23/09 | 1 505 l., 311 Ko |
| `docs/specs/20-back-office-curation.md` | Rôles et matrice, import, frame servable, recadreur, grille, revue, publication, lot pilote, modération | écrite le 23/09, complète, jalons marqués. § 14 tranche les 24 questions du panel admin. Lots J1 : L20-1 à L20-18 | 1 350 l., 246 Ko |
| `docs/specs/30-themes-vivier-et-tirage-des-variantes.md` | Thèmes, vivier, tirage des films et des variantes | écrite le 23/09, complète. Lots J1 : L30-1 à L30-7 | 1 091 l., 147 Ko |
| `docs/specs/40-comptes-auth-sociale-et-avatars.md` | Identité, connexion, avatars | **partielle** : la section [J1] « identité invitée » est écrite (D2 du 23/09) ; J2 à écrire. Lots J1 : L40-1 à L40-7 | 870 l., 116 Ko |
| `docs/specs/50-salon-reglages-presets-et-lobby.md` | Salon, réglages, presets, lobby | écrite le 23/09, complète, onglet Avancé du J2 compris. Lots J1 : L50-1 à L50-9 | 1 933 l., 200 Ko |
| `docs/specs/60-moteur-de-partie-temps-reel-et-mode-solo.md` | Moteur de partie, temps réel, solo | écrite le 23/09 **sous hypothèse root** (S2 du 23/09). Lots J1 : L60-1 à L60-16 | 1 308 l., 218 Ko |
| `docs/specs/70-validation-des-reponses.md` | Saisie et validation des réponses | écrite le 23/09. Lots J1 : L70-1 à L70-11 et L70-14 ; L70-12 et L70-13 au J2 | 1 097 l., 155 Ko |
| `docs/specs/80-scoring-podium-et-fin-de-partie.md` | Score, podium, fin de partie | écrite le 23/09. Lots J1 : L80-1 à L80-7 | 1 139 l., 127 Ko |
| `docs/specs/90-ecrans-etats-et-structure.md` | Écrans, états, structure, pages publiques | **partielle** : la section J1 (pages publiques et socle de coquille de jeu, D3 du 23/09) est écrite ; J2 à écrire. Lots J1 : L90-1 à L90-9, dont L90-3b, L90-6a et L90-6b | 999 l., 162 Ko |
| `docs/specs/100-qualite-tests-et-ci.md` | Qualité, tests, CI, production | **partielle** : la section [J1] (socle minimal de production) est écrite sous hypothèse root (D30 et S2 du 23/09) ; J2 à écrire. Lots J1 : L100-1 à L100-14 | 1 044 l., 188 Ko |
| `docs/specs/questions-ouvertes.md` | Journal des décisions : 19 du 22/09, S1-S4 et D1-D37 du 23/09, « Laissé ouvert le 23/09 », déjà tranché, risques | questionnaire clos, sauf le nom de domaine | 517 l., 148 Ko |
| `CLAUDE.md` | Mémoire projet chargée automatiquement à chaque session | **versionné désormais** (D9 du 23/09) : la ligne a été retirée de `.gitignore` et le fichier est exclu d'oxfmt dans `vite.config.ts`. Amendé le 23/09 | 164 l., 48 Ko |

Les heures des sections « Lots d'implémentation » sont des **mesures de taille, jamais un calendrier** (D36 du 23/09). Les lots J1 des neuf specs mesurent **404,5 à 581,5 h brutes**. L'arithmétique vit dans `00` § Jalons, nulle part ailleurs.

**Commit.** Quand ce fichier a été rédigé, le travail documentaire du 23/09 n'était pas encore commité sur `develop`. Il comprend les neuf specs nouvelles, `CLAUDE.md`, les amendements, `.gitignore` et `vite.config.ts`. Premier geste de la session suivante : `git status`, puis commit si ce n'est pas fait.

### État du code

Le code n'a **pas changé pendant la session du 23/09**. Aucune ligne de code métier n'a été écrite. Seuls `.gitignore` et `vite.config.ts` ont été touchés, pour D9 du 23/09. Le dernier commit de code est `d167a6a` (« Admin »). Ce qui existe :

| Zone | Contenu | Écrit |
|---|---|---|
| `database/migrations/` | 37 migrations de domaine + les 5 du starter (42 fichiers, 35 tables de domaine) | 22/09 |
| `app/Models/` | 35 modèles de domaine + `User` amendé + un pivot typé | 22/09 |
| `app/Enums/`, `app/Settings/`, `app/Casts/`, `app/Support/` | 44 enums, `RoomSettings` avec ses bornes et limites, cast versionné, grille d'exclusion, préfixes de stockage | 22/09 |
| `database/factories/`, `database/seeders/` | 36 factories et 4 seeders. Catalogue de démonstration de 16 films jouables à N = 2 à 5 (78 `.webp` réels). `DemoCatalogueChainTest` prouve la chaîne des données, **pas encore une partie lancée** : c'est L100-14 qui le fera (étape 103) | 22/09 |
| `lang/{fr,en}`, `lang/{fr,en}.json`, `lang:types`, `lang:hash` | Socle i18n complet | 22/09 |
| `app/Console/Commands/` | `catalog:import-discover` et `catalog:import-ids`, sur une base commune `CatalogImportCommand` ; `admin:first-admin`, `lang:types`, `lang:hash` | 22-23/09 |
| `routes/admin.php`, `resources/js/pages/admin/` | Début de panel : tableau de bord, catalogue, import (`discover`, collage d'identifiants, reprise) | 23/09, avant la session de specs |
| `tests/Feature/` | Schema (172 tests de balayage), Admin, Architecture, Auth, Catalog, I18n, Settings, Tmdb | 22-23/09 |

**Catalogue réel de la base de dev.** Trois films sont importés depuis TMDB : Fight Club, Parasite et Le Labyrinthe de Pan. Tous sont en `draft` et `is_import_exception` (dont 2 pour `exception_for_language`). Aucune frame n'est curée, donc le vivier est vide à tout N. C'est normal : au jalon 1, la curation réelle naît **en production** (D1 du 23/09). Aucun film réel n'y est curé avant la **porte du pilote** (étape 56 de l'annexe, `20` § 10.3).

**Suite de tests, dernier état vérifié le 23/09.** `composer ci:check` est vert de bout en bout :

- Pint passé ;
- PHPStan niveau 7 : 0 erreur ;
- **Pest : 491 tests / 4 433 assertions** ;
- `npm run check` passé, script anti-couleur compris ;
- `tsc --noEmit` : 0 erreur ;
- `npm run build` OK ;
- `migrate` et `migrate:rollback` : 42/42.

**Contre-vérification du 23/09 au soir, avec un serveur Vite actif.** `php artisan test` a été relancé pendant que `npm run dev` tournait (`public/hot` présent, port 5173 à l'écoute). Résultat : **428 tests sur 491 passent, 63 échouent** (3 518 assertions). Les 63 échecs sont tous des rendus de page Inertia : 500, « Not a valid Inertia response » ou « The response is not a view ». La cause relevée est `Attempted request to [http://[::1]:5173/__inertia_ssr] without a matching fake` : avec `public/hot`, Inertia tente un rendu SSR vers le serveur Vite, et la suite refuse cette requête. Aucun code n'a changé depuis l'état vert. **Premier geste avant l'étape 6** : arrêter `npm run dev`, relancer `composer ci:check` et confirmer le vert. Si des échecs persistent, les traiter avant tout lot — amendé le 23/09.

### Dettes encore ouvertes, vérifiées dans le code le 23/09

`100` § 17 les tient à jour. Chacune est soldée par un lot de l'annexe.

| Dette | Soldée par |
|---|---|
| Aucun temps réel : `BROADCAST_CONNECTION=log`. `laravel/reverb`, `laravel-echo` et `pusher-js` ne sont pas installés. `predis/predis` n'est pas requis, et `.env.example` dit encore `REDIS_CLIENT=phpredis` | L60-1 (étape 10), jamais par `install:broadcasting` |
| `.env.example` incomplet. Manquent : les lignes Reverb et Redis (L60-1) ; `ACCOUNTS_*` (L40-7) ; indexation, drainage, sauvegarde, sonde, `REDIS_QUEUE_RETRY_AFTER` et `REVERB_ALLOWED_ORIGINS` (L100-4) ; OAuth (`40` J2). `FRAMES_DISK_ROOT` et les deux clés TMDB y sont déjà, vides | L60-1, L40-7, L100-4 (étapes 10, 14, 11) |
| `composer dev` ne lance ni Reverb ni la file `game` | L100-4, seul écrivain (étape 11) |
| Le back-office force toujours le thème clair (`ForceAdminAppearance`) | L90-1, retrait selon D8 du 23/09 (étape 12) |
| La suppression de compte `profile.destroy` est encore exposée ; l'inscription et les passkeys ne sont fermées nulle part | L40-7 (étape 14) |
| `symfony/polyfill-intl-normalizer` n'est pas une dépendance directe | L40-3 (étape 67) |
| `public/avatars/` n'existe pas | L40-5 (étape 63) |
| Socialite absent ; page `dashboard` du starter ; feuille mobile anglaise d'`AppLayout` (dette n° 24 côté joueur) | `40` J2, `90` J2 |

---

## 2. Ce qui est verrouillé — ne pas rouvrir

Le jeu est un blindtest de films et de dessins animés. Une manche = 1 film et `N` images (3 par défaut), de la plus cryptique à la plus évidente, sur une durée `D` (30 s par défaut). On joue en salon privé par code, ou en mode solo. Le premier qui trouve ne coupe pas la manche : il est verrouillé et les autres continuent. On marque des points par palier, plus un bonus de rapidité linéaire dont le `B_max` dépend de `N` (D22 du 23/09). Le serveur est autoritaire et le temps réel passe par Reverb.

**Rien n'est une constante.** `N` (2-5), `D` (10-120 s), `R` (3-20 s), le barème et le nombre de manches sont des réglages de salon. Un film a une **banque d'images classées sur 5 niveaux**, avec des variantes. Le tirage préfère une variante que le salon n'a pas vue : **axe salon uniquement**. Les règles détaillées sont dans `CLAUDE.md` §2 et dans les specs.

### Décisions du 22/09

Le détail est dans `questions-ouvertes.md`.

| # | Décision | Écart |
|---|---|---|
| 1 | Public et indexé dès la v1 | oui |
| 2 | Rien en v1, architecture neutre | oui |
| 3 | ~10 h/semaine — **révisée par D36 du 23/09** : l'enveloppe couvre la curation, les décisions, les relectures et les gestes humains ; elle ne borne plus le développement, confié à l'IA — amendé le 23/09 | révisée |
| 4 | Mentions légales : personne physique | non |
| 5 | **Nom de domaine à acheter — seule décision encore ouverte**, achat avant la semaine 4 (D1 du 23/09) | — |
| 6 | Dépôt privé, tous droits réservés | non |
| 7 | TMDB + captures personnelles, source tracée | non |
| 8 | Recadreur intégré au back-office | non |
| 9 | Rôles distincts curateur / admin | oui |
| 10 | Lot pilote de 20 films chronométré | non |
| 11 | ≥ 500 votes, FR/EN/JA, depuis 1970 + voie d'exception | oui |
| 12 | `adult` + FR -18 / US NC-17 exclus | non |
| 13 | Préfixe accepté sauf collision publiée | non |
| 14 | Pas de téléversement d'avatar en v1 | non |
| 15 | Liste des parties + 4 compteurs | non |
| 16 | VPS Plesk existant | remplace un arbitrage |
| 17 | Sous-traitants UE seulement | non |
| 18 | 24 h de perte maximum, autre fournisseur | non |
| 19 | 12 mois glissants, plafond jamais plancher | oui |

### Décisions du 23/09

Relevé seulement. Le détail et, pour les écarts, ce que la recommandation protégeait se trouvent dans `questions-ouvertes.md` § « Décisions du 23/09/2026 — jalon 1 ». La règle vit dans la spec de son domaine. On les cite « D5 du 23/09 », jamais « décision 5 ».

| # | Décision | Écart |
|---|---|---|
| S1 | Jalon 1 complet ; `40`, `90` et `100` scindées, section J1 seule | — |
| S2 | `60` et `100` [J1] sous hypothèse root ; relevé du VPS avant tout déploiement | — |
| S3 | Chaque spec finit par ses lots : dépendances, fichiers, tests Pest, taille | — |
| S4 | Une dizaine de sagas TMDB par seeder ; Pixar et Ghibli sont des studios | — |
| D1 | Domaine et VPS dès le J1 : curation née en production | non |
| D2 | `40` [J1] « identité invitée » écrite avant `50` | non |
| D3 | `90` J1 : pages publiques et socle de coquille de jeu, avant `60` | non |
| D4 | Le porteur, premier admin, est le seul curateur du J1 | non |
| D5 | Ratio 16:9, dérivé 1280×720 ≤ 150 Ko | non |
| D6 | Plancher de recadrage automatique, revalidé côté serveur | tranchée par le rédacteur |
| D7 | LQIP abandonné : cadre fixe, aplat au token | non |
| D8 | Le back-office suit l'apparence choisie | non |
| D9 | `CLAUDE.md` versionné | non |
| D10 | Seuils du pilote calés sur la réserve de 36 h | non |
| D11 | Pilote stratifié : ≈ 15 `discover` + ≈ 5 d'exception | non |
| D12 | Nom réel exigé pour `curator` et `admin` | non |
| D13 | Grille : drapeau `retroactive` pour motif juridique | non |
| D14 | Révélation = images de la manche déjà servies, titre et année | non |
| D15 | Expulsion : jeton refusé dans le salon (`kicked_at`) | non |
| D16 | Empreinte des octets : risque assumé | non |
| D17 | Retardataires comme variable d'ajustement — **sans effet depuis D35** : livrés au J1 | non |
| D18 | Solo : « Voir la réponse » / « Passer la manche », jamais de `guess` | non |
| D19 | Solo sur un des 4 presets, N jouable le plus proche | non |
| D20 | En Normal, QCM conservé après épuisement du texte libre | **oui (NR)** |
| D21 | Leurres : vivier du salon, puis catalogue publié, puis mode dégradé | non |
| D22 | `B_max = min(50 %, 100 % / (N − 1))`, en pourcentage entier | remplace un arbitrage |
| D23 | Sous-titre dérivé, soumis à collision | non |
| D24 | `near_miss` au J2 | non |
| D25 | Faits du podium : meilleure réponse, plus rapide, film que personne n'a trouvé | non |
| D26 | Pseudo en écriture latine seule | non |
| D27 | Avatars : pack animaux Kenney CC0, `preset-01`..`preset-24` | non |
| D28 | Vivier bloqué par la non-répétition : cause nommée, remède « nouveau salon » | non |
| D29 | Valeur affichée = valeur entière du palier courant | non |
| D30 | `100` [J1] = socle minimal de production | non |
| D31 | Déploiement manuel gardé (Plesk Git manuel, non atomique) | **oui (NR)** |
| D32 | Drainage borné par `deploy:drain` | **oui (NR)** |
| D33 | Test de charge complet avant la première partie | **oui (NR)** |
| D34 | Barème en Simple : suit N et D s'il n'est pas personnalisé | non |
| D35 | **J1 complet, aucune coupe** | **remplace un premier choix « C »** |
| D36 | **Développement confié à l'IA** : heures = mesures de taille ; chemin critique = chemin humain | **révise la décision 3** |
| D37 | **Ordre « curation d'abord »** : socle sans moteur, puis `20`, puis le pilote pendant que l'IA construit le moteur | tranchée par le rédacteur |

Conséquences qui gouvernent le travail :

- **Aucune variable d'ajustement de développement** (D35). Le « recadreur minimal » et la coupe des retardataires sont sans objet.
- **Le seul volume réglable est le nombre de films du J1.** Il vaut 60 par défaut et le verdict du pilote le fixe (D10).
- **La barre « terminé » n'est jamais touchée.**
- **Le drainage (D32) et le test de charge (D33) sont dus avant la première partie.** Ils ne le sont jamais avant la curation (D37).

---

## 3. Ce qui bloque — le chemin humain (D36 du 23/09)

Le développement ne borne plus rien : **ce qui fixe la date de la première vraie partie, ce sont les gestes du porteur.** L'IA ne doit jamais être ce qui les fait attendre.

| # | Geste du porteur | Bloque | Étape |
|---|---|---|---|
| 1 | **Acheter le domaine** (décision 5), **avant la semaine 4** (D1 du 23/09). Environ dix euros par an, seul achat du chemin critique. Le nom ne vit que dans le `.env` de production et les réglages Plesk ; le dépôt garde `<DOMAINE>` pour toujours (`100` § 7.2, `NoLiteralDomainTest` ; écart D-2 ci-dessous). Aucun compte de production ni aucune passkey avant l'achat ; aucune passkey au J1 | la mise en service (27), donc toute la curation | 1 |
| 2 | **Relever le VPS**, puis le **monter en root**. Le relevé : `ssh root@`, `free -m`, `nproc`, `uptime`, `ss -ltnp`, `/opt/plesk/php/8.3/bin/php -m`, abonnements servis, version de Plesk, **région UE**. Seuil défendable : 4 Go de RAM, 2 vCPU. Sans root, pas de Redis dédié, pas de workers systemd, pas de Reverb : **arrêt et question au porteur**. Le repli sans root (second VPS, contraire à la décision 16) est ouvert | la préparation des gabarits `ops/` (26) et la mise en service (27) | 2, 27 |
| 3 | **Constituer la liste d'amorçage** : environ 200 identifiants TMDB, relue une fois, 4 à 6 h hors réserve. Peut commencer tout de suite | la porte du pilote (54, 56) | 3 |
| 4 | **Vérifier la licence du pack d'avatars** Kenney au téléchargement et la consigner dans `public/avatars/LICENSE.md` (D27 du 23/09) | la clôture de L40-5 et la première partie (133), pas les lots suivants | 64 |
| 5 | **Choisir les fournisseurs UE du J1** : stockage objet de sauvegarde chez un fournisseur **distinct** de l'hébergeur du VPS, supervision externe, second canal d'alerte ; ouvrir les comptes | la sauvegarde active avant la première image curée (30), donc le pilote | 4 |
| 6 | **Nommer les sous-traitants UE (J2)** : un par catégorie branchée (hébergeur, SMTP, sauvegarde, supervision, second canal, suivi d'erreurs s'il est branché), plus le registrar | la page de confidentialité, donc l'ouverture du J2 | — |
| 7 | **Commander les textes légaux (J2)** (décision 4). Délai externe de 2 à 6 semaines, **à lancer pendant le J1**. Poser au même conseil la question de la **licéité de la capture** (liste fermée des sources autorisées) | l'ouverture du J2 et la voie capture (L20-33, J2) | 5 |

Les autres gestes du chemin humain :

- **Premier admin de production** : `admin:first-admin` avec le nom réel, puis second facteur (étape 28).
- **Chaque déploiement manuel** (D31 du 23/09) : étapes 29, 56 et 126, puis tous les correctifs. L'IA remet une note de déploiement à chaque fois.
- **Contrôle de lisibilité de la première sauvegarde** (étape 31).
- **Déclaration des heures du pilote** (étape 55).
- **Le lot pilote** puis **les films du J1** en passe 1 (étapes 57 à 61, réserve de 36 h).
- **Recette sur appareil réel** (étape 127).
- **Restauration chronométrée** et **séance de charge** (étapes 128 à 131).

Points secondaires, sans effet sur la date du J1 :

- **Personne de confiance et second administrateur réel** : c'est une condition du J2 (D4 du 23/09).
- **2FA de `curator` et `admin`** et **dormance à 24 mois puis 30 jours** : valeurs retenues, à confirmer dans `40` J2.
- **Seuil du taux de réussite** : 20 manches.

---

## 4. Questions encore ouvertes

**Règle d'exécution.** Quand une spec écrit une **lecture retenue**, l'IA l'applique sans attendre. Seul le lot concerné attend l'avis du porteur quand aucune lecture n'est retenue. Aucune des questions ci-dessous ne bloque l'étape 6.

| Question | Propriétaire | Quand, et ce qu'elle bloque |
|---|---|---|
| **Lot 9 non posé**, trois questions : effet de « bannir un pseudo » (`nickname.banned`), preuve du consentement aux données provider, avatar d'un invité qui crée un compte | `40` § 10.2, à poser avec options et recommandation | à l'écriture de `40` J2 ; rien au J1 |
| Confirmation de la 2FA de `curator` **et** `admin`, et de la dormance (24 mois + 30 jours) | `40` J2 | réversibles jusqu'à la garde `admin.2fa` (L20-2) |
| **R-46 — voie capture** : le serveur dérive toujours le dérivé de jeu du master et du rectangle ; le navigateur n'envoie qu'une source normalisée d'au plus 1 536 Ko et un rectangle. Tranché par le rédacteur, **à confirmer par le porteur** | `20` § 5.4 | sans effet au J1 (capture désactivée) |
| **R-47** — suffixe `.label` des clés de la grille (`admin.exclusion_grid.v{n}.{slug}.label` et `.help`) : écart de forme seulement | `20` § 7.1 | à confirmer ; L20-12 applique la forme de `20` |
| **Liste fermée des sources autorisées** pour une capture personnelle (licéité, décision 7) | conseil du porteur | bloque L20-33 (J2) ; au J1, voie TMDB seule |
| **N100-2** — passage du tier froid en **quotidien**. Proposé, non appliqué : il reste hebdomadaire jusqu'à accord | `100` § 13.3 | avant la première image curée (étape 30), idéalement |
| **Typographie française** commune à tous les dictionnaires (U+00A0 ou U+202F avant « : ; ! ? % » et dans « »), signalée par `80` § 15.1 | `05` | aucun lot du J1 |
| **Reformulation de `game.help.prefix`** : « jouables » y désigne le vivier au lexique de `00` | `90` point 3 ↔ `70` § 7.7, arbitrage du porteur | avant L70-10 et L90-7 |
| **Plancher de recadrage** : défaut 80 %, double borne, à calibrer au pilote et plafonné à 83 ; EN20-4 propose de ramener à 83 la borne haute du contrat | `20` § 5.2 | étape 59, avant la curation de masse |
| **Heures déclarées du pilote** (`weekly_curation_hours`, `horizon_weeks`) ; seuils de D10 à revérifier sans les modifier | `20` § 10.3-10.4 | étape 55, porte du pilote |
| Barème de tolérance v2 éventuel, après `answers:collisions` sur le catalogue réel | `70` § 13.1 | étape 132, avant la première partie |
| Nombre de processus `game` après D33 (signalé s'il dépasse un) ; plafond global de parties simultanées (J2) | `100`, `60` § 17.3 | après la séance de charge (131) |

Points ouverts signalés au porteur dans les sections « Ce que cette spec ne décide pas » des neuf specs. La plupart ont une lecture retenue, que l'IA applique.

- **`20`** :
  - EN20-1 à EN20-4 et AN20-1 à AN20-8, non consolidés ;
  - cas `user.real_name_changed` (EN20-3, vers `10`) ;
  - affichage du nom réel à son titulaire hors back-office : non retenu.
- **`30`** :
  - vérifier les dix identifiants de collections TMDB et Marvel Studios (420), avant L30-9, au J2 ;
  - réviser le seuil de 150 œuvres après le pilote ;
  - tension entre la règle 3 et D21 quand `W ≤ M + marge` ;
  - écart C3/C6 sur l'écriture de `game` ;
  - E10-N1, E10-N2, A-N1 à A-N5.
- **`40`** :
  - effacer `nickname_normalized` à l'archivage (exigence nouvelle à `10`) ;
  - ligne de cookie « … ou dernier changement de langue » ;
  - `ensure()` après les refus ;
  - précision d'A-67.
- **`50`**, points restés ouverts 0 à 16 (le 4 est tranché par D35) :
  - **n° 15, remède « nouveau salon » : aucune lecture retenue, décision produit à prendre avant L50-5** ;
  - n° 0, page unique `game/lobby` : à confirmer ;
  - n° 13 : cycle L50-2 ↔ L60-4 (voir I-1) ;
  - n° 14 : règle du retardataire citée par `60` § 13.7 — alignée (renvoi à `50` § 15.2, remplaçante comprise) ; point à clore dans `50` — amendé le 23/09 ;
  - les autres portent sur la forme des contrats (tests, placeholders, délai, capacité).
- **`60`** :
  - écarts du § 22 bis, dont (p) : `composer dev` à deux écouteurs, proposé et non appliqué ;
  - E10-N3, E10-N4, A-N6 à A-N8 ;
  - valeurs à confirmer au relevé du VPS.
- **`70`** :
  - `maxAnswerLength` dans `GameStatePacket` (écart C7) ;
  - écarts à C10 § 4 ;
  - **fermer ou non la recopie des propositions en texte libre** : la lecture retenue est de la laisser ouverte (résidu assumé) ;
  - au J2 : recalibrage (L70-13) et clés de `near_miss` (L70-12).
- **`80`** :
  - compter ou non une partie sans score dans « meilleur score » (`40` J2) ;
  - parité de `translateChoice`, rattachée à L100-3 (I-10).
- **`90`**, points 1 à 11 :
  - **toasts du lobby à retirer de `50` avant L50-4** ;
  - ligne de cookie `player_token` ;
  - extensions de C16 ;
  - `config/fortify.php` › `middleware` étendu d'`accounts.switches` ;
  - ligne `game.round.lone_player` ;
  - dette n° 24 (J2).
- **`100`** :
  - N100-2 ;
  - exigences aux specs sœurs, dont la garde d'instantané des imports (I-9) ;
  - personne de confiance.

L'ordre d'exécution relève aussi des **correctifs de dépendances** (I-1 à I-13, annexe § A.3). Ils sont déjà appliqués dans l'ordre, mais pas encore reportés dans les specs propriétaires. Trois attendent l'accord du porteur : **I-12** (élargir `final_rank` dans la migration de création, étape 25, en option), **I-13** (drainage livré en deux déploiements) et la **règle de branche pendant la curation** (§ A.2).

Deux écarts documentaires restent à corriger :

- **I-7** : `00` § Jalons et `20` § 10.3 placent la « restauration jouée » avant la curation. `100`, qui en est propriétaire, n'y met que le contrôle de lisibilité, et place la restauration chronométrée avant la première partie. Il faut suivre `100`.
- **D-2** : `00` et `questions-ouvertes.md` prévoient encore une recherche-remplacement de `<DOMAINE>` le jour de l'achat. `100` § 7.2 rend la garde permanente : il faut suivre `100`.

---

## 5. Prochaine action, dans l'ordre

L'ordre complet est en **annexe** : 134 étapes, humaines et IA. C'est la seule copie versionnée de l'ordre établi le 23/09. Le numéro d'une étape donne un ordre de démarrage, pas un calendrier. Une étape IA dont les prérequis sont livrés avance pendant qu'un geste humain antérieur attend.

**Tout de suite, en parallèle :**

- **Porteur (phase 0)** :
  - (1) acheter le domaine ;
  - (2) relever le VPS ;
  - (3) constituer la liste d'amorçage ;
  - (4) choisir le stockage de sauvegarde, la supervision et le second canal ;
  - (5) commander les textes légaux, non bloquant.
- **IA (phase A, étapes 6 à 25)**, sans domaine ni VPS :
  1. **Étape 6** — L100-1 : groupes Pest, suites, job CI `mysql-redis`.
  2. **Étape 7** — L100-2 : gardes du dépôt (`ZeroSecretTest`, `NoLiteralDomainTest`, `NoRealFixtureTest`, `LicenseTest`), méta-vérification des tokens.
  3. **Étape 8** — L100-3 : Vitest, `RoomSettingsMatrixTest`, correctif de `translateChoice` (I-10).
  4. **Étape 9** — L50-1 : `config/game.php`, `PlatformLimits` (`B_max` selon D22).
  5. **Étape 10** — L60-1 : Reverb, `predis`, `laravel-echo`, `pusher-js`, `EngineConstants`.
  6. **Étape 11** — L100-4 : environnement, `SITE_INDEXABLE`, `robots.txt`, `composer dev`.
  7. **Étapes 12 à 16** — L90-1 (tokens, retrait du forçage clair), L90-2 (`noindex`), L40-7 (comptes fermés en production), L90-3 (coquille publique), L90-4 (pages légales en squelette).
  8. **Étapes 17 et 18** — L20-1 (journal, nom réel, `admin:first-admin`), puis L20-2 (porte `/admin`, `admin.2fa`).
  9. **Étapes 19 et 20** — L30-7 (`PlatformDataSeeder`), puis L100-6 (hook **sans drainage**, `backup:snapshot`, `catalog:reproject`).
  10. **Étapes 21 et 22** — L20-4 (géométrie 16:9, plancher), puis L20-5 (chaîne Imagick).
  11. **Étapes 23 et 24** — premiers temps de L100-7 (sondes, battements) et de L100-8 (purge des seuls périmètres sans jeu).
  12. En fin de phase A, l'IA déclare au porteur **« prêt pour la mise en service »**.
- **Puis la phase B (26 à 56)** :
  - L'IA prépare les gabarits `ops/` (26).
  - Le porteur fait la mise en service root (27), crée le premier admin (28) et lance le premier déploiement (29).
  - Viennent ensuite la sauvegarde active (30), son contrôle de lisibilité (31) et la supervision (32).
  - Pendant ces gestes, l'IA livre **tous les lots J1 de `20`** et leurs prérequis (33 à 53).
  - L'étape 56 est la **porte du pilote**. Le porteur cure alors (57 à 61) pendant que l'IA construit le moteur (phase C, 62 à 125).
- **Phase D (126 à 134)** : déploiement du moteur, recette sur appareil réel, restauration chronométrée, test de charge, puis **première vraie partie**.

Tailles de chaque phase, en mesures de taille et non en calendrier :

| Phase | Taille |
|---|---|
| A | 68,5 à 102 h |
| B | 90,5 à 128,5 h |
| C | 237,5 à 339 h |
| D | 8 à 12 h |

**Règles pour l'IA**, § A.1 de l'annexe :

- un lot n'est livré que « terminé » ;
- le travail qui débloque un geste humain passe en premier ;
- chaque déploiement est déclenché par le porteur, avec une note de déploiement ;
- `backup:snapshot` avant toute écriture manuelle dans le catalogue (règle 12).

---

## 6. Pour relancer la session

Dire à Claude : **« Lis `docs/REPRISE.md`, puis implémente l'étape 6 de l'ordre des lots J1 (annexe). »** Avant la première étape, deux vérifications (§ 1) :

1. `git status` : le travail documentaire du 23/09 est-il commité ?
2. `composer ci:check`, serveur Vite arrêté : la suite est-elle verte ?

Ensuite, à chaque session : **« Implémente l'étape suivante de l'ordre des lots J1. »** Claude suit la section « Lots d'implémentation » de la spec du lot : fichiers, tests Pest nommés, dépendances. En fin de lot, il marque l'étape livrée dans l'annexe et met à jour ce fichier.

`CLAUDE.md` se charge tout seul, avec les règles de jeu, la stack, les commandes, les conventions et les pièges. Inutile de les redonner.

**Annexes du 23/09**, dans `docs/annexes/` : `contrats-j1.md` (feuille de contrats partagés figée pour la rédaction : noms, signatures, charges utiles ; résout les renvois « contrat Cn », « E10-nn », « A-nn », « R-nn ») et `contradictions-j1.md` (les 82 contradictions du corpus, citées « n° N »). Elles ne priment jamais sur une spec.

**Panel admin, aujourd'hui** :

1. `php artisan admin:first-admin` crée ou promeut le premier administrateur. La commande est interactive : le mot de passe ne passe jamais en argument.
2. Lancer `composer dev`.
3. Ouvrir `/admin`. On y trouve le tableau de bord, le catalogue et l'import (`discover`, collage d'identifiants, reprise).

À partir de L20-1 (étape 17), la commande exige le **nom réel** (`--real-name=`, D12 du 23/09), et `--create` devient définitif. En production, elle est exécutée **une fois**, par le porteur, en SSH, **après l'achat du domaine** (étape 28).

---

## Annexe — Ordre d'exécution des lots du jalon 1

Établi le 23/09/2026 en application de D35, D36 et D37 du 23/09, sans rouvrir aucune décision.

**Format.** Chaque étape se lit « étape → lot et objet → prérequis ». Les prérequis sont les numéros des étapes directement requises. Un `*` marque une dépendance ajoutée par l'ordre : correctif proposé, à reporter dans la spec. « P » désigne le porteur, « IA » l'IA. Le livrable de chaque lot est l'ensemble des tests nommés par sa spec, au vert.

### A.1 Règles d'exécution

1. **Une étape à la fois**, dans l'ordre des numéros. Les étapes de curation (57 à 61) courent en parallèle de la phase C.
2. **Un lot n'est livré que « terminé »** :
   - tests de la spec verts ;
   - `composer ci:check` vert, workflow MySQL compris ;
   - FR et EN complets ;
   - états de chargement, d'erreur et de déconnexion ;
   - parcours clavier.

   Les vérifications sur appareil réel reviennent au porteur.
3. **Le travail humain passe en premier.** Une anomalie du back-office, une re-livraison après le pilote, une note de déploiement ou un ajustement au relevé interrompt le lot en cours de la phase C.
4. **Certains lots se livrent en plusieurs temps** : L50-2 (I-1), L100-7, L100-8 et L100-10 (D37). Leur taille est comptée au premier temps.
5. **Chaque déploiement de production est un geste du porteur** (D31). L'IA remet une note : lots inclus, migrations, commandes manuelles, vérifications.
6. **`<DOMAINE>` ne quitte jamais le dépôt.** Aucun secret, aucune image réelle et aucun extrait de production n'y entrent.
7. **Règle 12** : `backup:snapshot` (code 0) avant toute écriture manuelle dans `movie`, `frame`, `movie_title`, `alias` ou `frame_review`.

### A.2 Étapes

**Phase 0 — gestes immédiats (P)**

1. P — acheter le domaine, avant la semaine 4 → —
2. P — relever le VPS ; confirmer root et région UE → —
3. P — liste d'amorçage, environ 200 identifiants TMDB (4-6 h) → —
4. P — stockage de sauvegarde (UE, autre fournisseur), supervision externe, second canal d'alerte → —
5. P — commander les textes légaux du J2 ; question de licéité de la capture (non bloquant) → —

**Phase A — socle et prérequis de la mise en service (IA ; 68,5 à 102 h)**

6. L100-1 — groupes Pest, suites, job `mysql-redis` → — — ✅ livrée le 24/09
7. L100-2 — gardes du dépôt, licence, méta-vérification des tokens → 6 — ✅ livrée le 24/09
8. L100-3 — Vitest, matrice des réglages, correctif `translateChoice` (I-10) → 6 — ✅ livrée le 24/09
9. L50-1 — `config/game.php`, `PlatformLimits` (D22) → — — ✅ livrée le 24/09
10. L60-1 — `EngineConstants`, Reverb, `predis`, `laravel-echo`, `pusher-js` → 9 — ✅ livrée le 24/09
11. L100-4 — environnement, indexation, artefacts, `composer dev` → 6, 10 — ✅ livrée le 24/09
12. L90-1 — anti-couleur, tokens, composants J1, retrait du forçage clair (D8) → 7 — ✅ livrée le 24/09
13. L90-2 — `noindex` intégral, `Referrer-Policy` → — — ✅ livrée le 24/09
14. L40-7 — `AccountSwitches` : inscription et passkeys fermées hors `local`/`testing` → — — ✅ livrée le 24/09
15. L90-3 — coquille publique, pied de page, attribution TMDB, domaine `legal` → 12, 14 — ✅ livrée le 24/09
16. L90-4 — pages légales et « signaler un contenu » en squelette → 13, 15 — ✅ livrée le 24/09
17. L20-1 — `admin_action`, nom réel (D12), `admin:first-admin` → — — ✅ livrée le 24/09
18. L20-2 — porte `/admin`, `admin.2fa`, coquille mobile → 12, 16, 17 — ✅ livrée le 24/09
19. L30-7 — scission de `PlatformDataSeeder` → — — ✅ livrée le 24/09
20. L100-6 — hook **sans ses étapes 3 et 12** (D37), `backup:snapshot`, élagage, `catalog:reproject` → 11 — ✅ livrée le 24/09
21. L20-4 — géométrie 16:9, plancher de recadrage à double borne → 8, 9, 12 — ✅ livrée le 24/09
22. L20-5 — chaîne Imagick, `ProcessFrameImage` (file `default`) → 21 — ✅ livrée le 24/09
23. L100-7 (1er temps) — planificateur, battements, sondes, journaux → 11, 22 — ✅ livrée le 24/09
24. L100-8 (1er temps) — purge des périmètres sans jeu → 23 — ✅ livrée le 24/09
25. (option, I-12, accord du porteur) — `final_rank` élargi dans la migration de création → 6 — ✅ livrée le 24/09

**Phase B — mise en service et back-office complet (90,5 à 128,5 h)**

26. L100-9 (préparation) — gabarits `ops/` ajustés au relevé, liste de contrôle de `100` § 11.6 → 2, 10, 11, 13, 14*, 17*, 18*, 19, 20, 23
27. P — mise en service (`100` § 11.6, étapes 1 à 5), montage root, `.env` de production hors dépôt → 1, 2, 26
28. P — premier admin de production, nom réel, second facteur, aucune passkey → 27
29. P — premier déploiement par le hook, sans drainage ; L100-9 terminé → 28
30. IA + P, L100-10 (1er temps) — sauvegardes chaude et froide actives avant la première image curée → 4, 20, 24, 29
31. P — contrôle de lisibilité, le lendemain de la première sauvegarde → 30
32. IA + P, L100-11 — supervision externe, une alerte volontaire par sonde → 4, 23, 29
33. L20-3 — matrice des capacités, policies → 18 — ✅ livrée le 24/09
34. L30-1 — `FrameLevelCoverage` → — — ✅ livrée le 24/09
35. L30-2 — constructeur du vivier (`PoolScope`, `PoolQuery`, `RoomMemoryWindow`) → 6, 9, 34 — ✅ livrée le 24/09
36. L30-3 — rapport de vivier en données → 9, 12, 35 — ✅ livrée le 24/09
37. L70-1 — normaliseur v1, `fold()`, `AnswerRules` → — — ✅ livrée le 24/09
38. L70-2 — sous-titre dérivé et projection (D23) → 37 — ✅ livrée le 24/09
39. L90-6a — `GameFrame`, `GameThemeScope`, `ConnectionBanner`, annonceur → 8, 12, 21 — ✅ livrée le 24/09
40. L20-6 — aperçu admin des images → 22
41. L20-7 — ajout depuis TMDB, dédoublonnage, route capture refusante → 22, 33
42. L20-8 — re-recadrage, niveau, dépublication d'image → 17, 34, 41
43. L20-9a — recadreur : cadre, boutons, clavier → 8, 12, 21
44. L20-9b — recadreur : poignées, molette, pincement (D35) → 43
45. L20-10 — éditeur de la banque d'images → 34, 39, 41, 42, 43
46. L20-12 — grille v1, passe de revue → 17, 39, 40, 42, 45
47. L20-11 — raccourcis de débit, bande balayable (D35) → 8, 45, 46
48. L20-13 — publication d'un film, contenu vérifié, ambiguïté → 17, 38, 46
49. L20-14 — titres, alias, `movie_group` manuel → 48
50. L20-15 — file de curation, fiche, tableau de bord → 36, 48
51. L20-16 — recherche TMDB, liste d'amorçage, aperçu à blanc, garde d'instantané des imports (I-9) → 18, 20*
52. L20-17 — débit et verdict du pilote → 8, 45, 48, 50
53. L20-18 — « premiers pas du curateur » → 46, 47*
54. IA — versionner la liste dans `database/data/tmdb-seed-list.txt` → 3, 51
55. P — déclarer les heures du pilote ; vérifier sans les modifier les seuils de D10 et la composition de D11 → 52
56. P — **porte du pilote** : déploiement manuel du back-office complet → 29, 30, 32, 33 à 53, 54, 55

**Curation (P, en parallèle de la phase C ; réserve de 36 h)**

57. P — importer la liste d'amorçage ; composer le pilote (≈ 15 `discover`, ≈ 5 d'exception) → 56
58. P — curer les 20 films du pilote entièrement par le back-office ; consigner toute ligne de commande (elle disqualifie) → 57
59. P — verdict de D10 : nombre de films du J1, cible de volume, calibrage du plancher (au plus 83), relecture de `00` § Jalons → 58
60. IA (si l'outil est disqualifié) — re-livraison **prioritaire**, puis déploiement et nouveau pilote (≈ 6 h) → 59
61. P — curer le reste des films du J1 en passe 1 (40 au défaut) → 59

**Phase C — pendant la curation (IA ; 237,5 à 339 h)**

62. L90-5 — pages d'erreur, composants d'état → 15, 18
63. L40-5 — registre des 24 avatars (Kenney CC0, D27) → —
64. P — licence du pack dans `public/avatars/LICENSE.md` → 63
65. L40-1 — jeton d'invité, refus de l'expulsé (`kicked_at`) → 63
66. L40-2 — langue portée par le jeton → 65
67. L40-3 — pseudo : forme canonique, écriture latine (D26) → 37, 63
68. L40-4 — liste noire des pseudos → 67
69. L40-6 — identité affichée, composants d'avatar → 12, 63, 67
70. L30-4 — `SeededPrf`, `DrawContext` → —
71. L30-5 — tirage des films et des variantes → 9, 34, 35, 70
72. L30-6 — substitution et remplacement → 71
73. L70-3 — `AnswerMatcher` → 37, 38
74. L70-11 — rapport de collisions, sonde de force brute → 73
75. L100-7 (2e temps) — `ReportBruteForce` → 23, 74
76. L70-4 — états de saisie (D20), `types/answers.ts` → —
77. L70-7 — résolveur de titre, tirage des leurres (D21) → 35, 70
78. L70-8 — composition du QCM, `rendered_locale` → 76, 77
79. L80-1 — règle de score, fonction pure → 8, 9
80. L80-2 — portées de lecture et rejeu → 79
81. L60-2 — horloge, instant de réception, enveloppe, limiteurs → 8, 10
82. L80-3 — départage, classement, `types/scoring.ts` → 69, 80, 81
83. L60-3 — canaux, garde `player`, événements → 65, 69, 81
84. L50-2 (1er temps) — éditeur Simple, présentateur, presets, `types/room-settings.ts` (I-1) → 9, 35, 36, 65, 81, 83
85. L60-4 — siège actif, second onglet, lobby → 69, 76, 82, 83, 84
86. L50-2 (2e temps) — routes sous `seat.active`, `BroadcastLobbyState` → 84, 85
87. L90-6b — chronologie cliente, annonces de manche → 39, 81, 82, 85*
88. L80-6 — textes, aide, `tierValueAt` (D29) → 8, 79, 87
89. L80-4 — `FinalizeGame` → 6, 82
90. L60-5 — matérialisation, programmation, `serve_token`, annulation → 8, 72, 83, 89
91. L60-6 — transitions de manche, pause → 77, 82, 89, 90
92. L80-5 — podium, récapitulatif, faits (D25) → 69, 77, 81, 89, 91
93. L60-7 — rattrapage, jobs de frontière, fin anticipée → 23, 76, 91
94. L60-8 — service d'image `/f/{serveToken}` → 21, 40, 65, 81, 90
95. L60-9 — client temps réel → 8, 39, 81, 83, 84, 85, 87
96. L60-10 — prédicat « partie en cours », `game:reschedule` → 89, 91, 93
97. L100-5 — drainage (D32), étapes 3 et 12 du hook → 11, 20, 96
98. L90-3b — bandeau de maintenance → 15, 97
99. L90-7 — coquille de jeu, forçage sombre, aide → 39, 98
100. L50-3a — code de salon, identifiant public de siège → 8, 12, 86
101. L50-3b — création, entrée, prise de siège → 15, 16, 63, 65, 66, 67, 68, 69, 81, 83, 100
102. L50-7a — lancement, garde de drainage → 10, 37, 71, 79, 83, 86, 90, 97, 101
103. L100-14 — bout en bout : partie de 10 manches sur le catalogue de démonstration → 90, 102
104. L70-5 — soumission texte, fenêtre, refus à travail constant → 65, 73, 76, 81, 85, 93, 102
105. L70-14 — limiteur `answer`, ordre des middlewares → 85, 104
106. L70-6 — transaction de verrouillage → 79, 93, 104
107. L70-9 — clic QCM, `SeatInputView` → 78, 106
108. L60-11 — crochets de saisie, QCM ciblé, fin de partie → 6, 78, 89, 92, 93, 104, 106, 107
109. L60-12 — resynchronisation de partie → 77, 78, 82, 85, 92, 93, 94, 107
110. L50-4 — page `game/lobby`, page unique du salon → 39, 83, 85, 95, 99, 101, 109
111. L50-6 — pouvoirs de l'hôte : expulsion (D15), transfert, départ → 65, 83, 93, 101, 110
112. L60-13 — présence, reprise, pouvoir d'hôte en partie → 85, 89, 93, 97, 111
113. L50-8 — échéances : archivage, lobby oublié, salon expiré → 23, 29, 83, 101, 112
114. L100-8 (2e temps, `stale_room`) + L100-7 (3e temps, `stale_lobby`) → 24, 113
115. L50-7b — gel, « Rejouer », concurrence du lancement → 6, 83, 89, 97, 102
116. L50-9 — retardataires (lot J1 ordinaire, D35) → 6, 91, 101, 102
117. L70-10 — écran de saisie, grille QCM → 8, 39, 95, 99, 104, 105, 107, 109
118. L80-7 — composants de classement, récapitulatif, podium → 8, 62, 69, 82, 88, 92, 95, 99
119. L60-14 — états de manche, de révélation et de pause → 15, 39, 95, 99, 109, 110, 112, 117, 118
120. L50-5 — formulaire Simple, presets, vivier, avertissements → 8, 12, 36, 86, 110
121. L60-15 — démarrage solo, page d'entrée (E10-N3) → 6, 15, 16, 36, 63, 65, 67, 86, 93, 97, 102
122. L100-8 (3e temps) — branche solo d'`orphan_player` → 24, 121
123. L60-16 — partie solo, gestes (D18, D19) → 94, 109, 112, 119, 121
124. L90-8 — accueil, champ de code → 15, 100, 101, 121
125. L90-9 — recette portrait et accessibilité (partie automatisée) → 99, 110, 117, 118, 119, 120, 123

**Phase D — avant la première vraie partie (8 à 12 h)**

126. P + IA — déploiement du moteur par la transition du drainage (I-13) ; ensuite, tout déploiement suit `100` § 11.4 → 62 à 125
127. P — recette de L90-9 sur téléphone réel (360 × 640, clavier ouvert) et lecteur d'écran réel → 125, 126
128. IA + P, L100-10 (2e temps) — restauration chronométrée sur cible jetable → 30, 96, 126
129. L100-12 — outillage de charge (scénarios A, A2, B ; `loadtest:forget`) → 29, 97, tous les lots J1 de `50`, `60` et `70`
130. P + IA — répétition à deux salons contre la production → 58, 126, 129
131. P + IA, L100-13 — séance de charge complète (D33) : 20 salons, environ 150 joueurs, voisins non dégradés → 30, 32, 129, 130
132. IA + P — clôture de L70-11 : `answers:collisions` sur le catalogue publié, calibrage de `70` § 13.1 → 61, 74
133. P — vérifier les conditions de la première partie (films publiés, drainage, restauration, charge, licence, recette, lots « terminés ») → 61, 64, 127, 128, 131, 132
134. P — **première vraie partie entre invités** → 133

**Règle de branche pendant la curation (proposée, à valider par le porteur).** Avant l'étape 126, aucun déploiement n'expose `room.create` ni `room.join` sans l'archivage (L50-8) et le second temps de la purge. Sinon, des pseudos d'invités resteraient en production sans échéance d'effacement. Les lots de la phase C restent donc sur `develop` et ne rejoignent `main`, donc la branche que tire Plesk, qu'à l'étape 126. Les correctifs du back-office et la re-livraison (étape 60) passent par une branche de correctif.

### A.3 Correctifs de dépendances (appliqués ci-dessus, à reporter dans les specs propriétaires)

| N° | Constat | Correctif |
|---|---|---|
| I-1 | Cycle L50-2 ↔ L60-4 | Scinder L50-2 en L50-2a (éditeur, présentateur, presets) et L50-2b (routes sous `seat.active`, dispatch) ; L60-4 et L60-9 dépendent de L50-2a |
| I-2 | L90-6b dépend de L60-4, qui déclare `RoundTimeline` | Amender le tableau de `90` |
| I-3 | `80` exige un commit commun à L60-2 et L80-3 | L80-3 suit L60-2 sans commit commun ; amender `80` § 20 |
| I-4 | L70-4 attribue à L60-2 l'import de `@/types/answers` | Lire L60-4 ; sans effet d'ordre |
| I-5 | L100-9 ne déclare ni L20-1, ni L20-2, ni L40-7 | Les ajouter à ses prérequis |
| I-6 | L100-7 dépend des sondes de frame sans nommer de lot | Nommer L20-5 |
| I-7 | Restauration « jouée » avant la curation (`00`, `20`) contre `100` | Suivre `100` : lisibilité avant la curation, restauration chronométrée avant la première partie |
| I-8 | L20-18 rend les raccourcis de L20-11 sans le déclarer | Ajouter L20-11 |
| I-9 | La garde d'instantané des `catalog:import*` lancés à la main n'a pas de lot | La rattacher à L20-16, avec L100-6 pour prérequis |
| I-10 | `translate-choice.test.ts` exige la parité de `translateChoice` | Correctif rattaché à L100-3 (+0,5 à 1 h) |
| I-11 | Des dépendances sont exprimées par contrat, sans identifiant de lot | Écrire les identifiants dans les tableaux (L30-5, L70-1, L80-1, L80-4, L100-7, L100-9, L40-1 à L40-6, etc.) |
| I-12 | La mise en service précède L80-4 : la voie additive s'imposerait pour `final_rank` | Option : élargir dans la migration de création avant la mise en service (étape 25), **accord du porteur** |
| I-13 | Le hook de L100-5 s'arrêterait à l'étape 3 lors du premier déploiement du drainage | Livrer L100-5 en deux déploiements (d'abord le drapeau et les commandes, puis les étapes 3 et 12), **accord du porteur** ; variante : arrêt attendu puis relance |

L'ordre a été vérifié mécaniquement : chacune des 134 étapes vient après toutes ses dépendances directes, et aucun cycle ne subsiste une fois L50-2 scindé. La somme des tailles vaut 404,5 à 581,5 h, soit le total brut de `00` § Jalons.
