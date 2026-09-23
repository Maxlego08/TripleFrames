# Back-office de curation

Ce document est le **propriétaire du back-office** de TripleFrames. Il décide qui peut faire quoi dans l'outil de curation, et comment : le modèle de rôles vu du back-office et sa **matrice de capacités ligne à ligne**, la porte `/admin` et sa seconde authentification, le premier administrateur, l'import TMDB vu du curateur, la file de curation, l'éditeur de la banque d'images et son recadreur, la **frame servable** (contrat C9) et son aperçu (contrat C9-bis), la **grille d'exclusion versionnée** et la passe de revue (contrat C14-bis), la publication et la dépublication, la correction des titres et des alias, les regroupements d'œuvres, la mesure du débit et le lot pilote, la modération par rôle, la file des demandes de retrait, les signaux de curation et l'inspection d'une partie. Il formule les exigences du journal `admin_action` et en écrit l'unique écrivain (contrat C14, dont `10` garde la liste et le schéma), et il possède la partie back-office du rapport de vivier (contrat C2 : la supervision « œuvres jouables à N »).

Ce document ne possède **aucune table** : toute donnée qu'il manipule est arbitrée par `10-catalogue-et-modele-de-donnees.md`, seul propriétaire du schéma, et chaque besoin nouveau y est adressé comme exigence. Il ne possède ni la règle de langue (`05`), ni le vivier, le tirage et le repli de niveau (`30`), ni le cycle de vie d'un compte, l'enrôlement Fortify, l'OAuth et la règle de signalement (`40`), ni la frappe du `serve_token` et les prédicats du moteur (`60`), ni la normalisation des réponses et la qualification d'une quasi-juste (`70`), ni le rejeu des points (`80`), ni les coquilles, le conteneur d'image `GameFrame` et les pages publiques (`90`), ni la CI, les sondes, les sauvegardes et le déploiement (`100`).

Convention de renvoi, valable dans tout le document : « règle N » désigne `CLAUDE.md` §7 ; « principe N » désigne `00-overview.md` § Principes directeurs ; « décision N » désigne une des 19 décisions du 22/09 consignées dans `questions-ouvertes.md` ; « DN du 23/09 » désigne une décision du porteur du 23/09, consignée dans `questions-ouvertes.md` § « Décisions du 23/09/2026 — jalon 1 » ; « contrat Cn », « E10-nn », « A-nn » et « R-nn » renvoient à la feuille de contrats partagés figée le 23/09 (contrats, exigences consolidées adressées à `10`, amendements consolidés, conflits résolus) ; « n° N » renvoie à la liste des contradictions de la pré-analyse, numérotée à partir de 0 ; « question N » désigne la question N de `REPRISE.md` § « À trancher par la spec 20 », dont les réponses sont récapitulées au § 14 ; « EN20-n » et « AN20-n » désignent les exigences, écarts et amendements **nouveaux** de cette spec, non consolidés dans la feuille de contrats et signalés au porteur (sections finales). Chaque section porte son jalon : **[J1]** première vraie partie entre invités, **[J2]** ouverture publique (`00` § Jalons).

> **État réel du dépôt au moment d'écrire — vérifié au commit `d167a6a`.**
>
> - `routes/admin.php` porte le groupe `['auth', 'verified', 'role:curator', 'admin.locale', 'admin.appearance']`, préfixe `/admin`, noms `admin.*`, et **huit routes** : `dashboard`, `catalog.index`, `catalog.show`, `import.index`, `import.show`, `import.discover`, `import.ids`, `import.resume` — autorisées route par route par `can:`, les trois écritures sous `throttle:admin-import` (12 par minute, `FortifyServiceProvider`). **Aucune route d'image, de revue, de publication, de correction ni d'accès.** Le commentaire d'en-tête renvoie la 2FA à « un middleware à ajouter ici ».
> - `EnsureUserHasRole` (alias `role`) est prioritaire sur `SubstituteBindings` ; `ForceAdminLocale` (alias `admin.locale`) force `fr` et le domaine `admin` seul ; **`ForceAdminAppearance`** (alias `admin.appearance`) force le thème **clair**, doublé de `useForcedAppearance('light')` dans `layouts/admin/admin-layout.tsx` — contraire à D8 du 23/09.
> - Policies : `MoviePolicy` (`viewAny`, `view` curator+, `create` toujours faux, **aucune méthode d'écriture**), `ImportRunPolicy` (`update` = curator+ et balayage en cours, `delete` faux), `SavedConfigPolicy`. **Ni `FramePolicy`, ni `FrameReviewPolicy`, ni `UserPolicy`.**
> - `App\Support\Curation\ExclusionGrid` : `CURRENT_VERSION = 1`, neuf items (dont `no_lead_face` aux niveaux 1-2), mais `LANG_PREFIX = 'curation.exclusion_grid.'` — un domaine qui n'existe pas —, aucune clé d'aide, **aucun drapeau de rétroactivité**. Aucune ligne `frame_review` n'existe : la restructurer est gratuit.
> - `AdminActionType` compte **15 cas** (ni `movie.published`, ni `frame.unpublished`, ni `frame.unsuspended`) ; `FirstAdminCommand` écrit `role.changed` avec `actor_name = 'console'` (constante locale `CONSOLE_ACTOR`), garde `--create` « provisoire », pose `email_verified_at` sur ses deux chemins, et ne connaît **aucun nom réel** : la colonne `users.real_name` n'existe pas.
> - `PlatformLimits::frameUploadMaxKilobytes()` = 1 536 existe ; **aucun plafond de recadrage**, et `config/game.php` n'existe pas (les accesseurs retombent sur leur défaut).
> - Aucune chaîne Imagick applicative : pas de `FrameGeometry`, pas de job `ProcessFrameImage`, pas de route servant `game/` ou `master/` au back-office. `ext-imagick` et `extensions: imagick` (job `ci`) sont déjà déclarés. `TmdbClient` a `images()` et `imageUrl()`, **ni `search()` ni `downloadImage()`** ; `TmdbBoundaryTest` le confine aux commandes et aux contrôleurs `Admin`.
> - `DashboardController::poolByFramesPerRound()` compte des **films** sur `range(2, 5)` écrit en dur et `CatalogIndexRequest::PLAYABLE_AT_MIN/MAX` ; la file « films non curés » montre les dix plus anciens brouillons, sans ordre de priorité ni réservation.
> - `database/data/tmdb-seed-list.txt` : onze sections commentées, **zéro identifiant**, usage documenté par la seule console. `catalog:import-ids --dry-run` ouvre une ligne `import_run` même en simulation.
> - `lang/fr/admin.php` (723 lignes) : neuf textes prescrivent une commande ou une variable d'environnement (`import.runs.worker_missing`, `import.disabled`, `catalog.import.skipped.duplicate`, `validation.ids.max`, `error.tmdb_disabled`, `tmdb.error.{not_configured, rate_limited, server_error, transport}`) ; quatre s'affichent déjà à un curateur (les deux premiers, `validation.ids.max`, `error.tmdb_disabled`), les cinq autres n'atteignent aujourd'hui que la sortie console et l'atteindront avec l'aperçu de collage. Quatre autres, déjà montrés au curateur, nomment la console ou un « worker » (`import.ids.list.hint`, `import.deferred_notice`, `import.toast.queued`, `import.runs.resume_unavailable_queued`). `movie.frames.editor_pending` annonce cette spec.
> - `ImportLauncher::openExclusively()` refuse tout second collage tant qu'un premier est en file (`started_at` nul) ou en cours ; `config/catalog.php` borne un envoi web à `import.paste_max_ids` = 50, commentaire à l'appui : un envoi dont le travail dépasserait le `--timeout` du worker serait tué en laissant un balayage `running`. Le limiteur `admin-import` (12 par minute et par utilisateur) est écrit pour les trois écritures, pas pour `admin.import.index`.
> - `MovieProjection::publishableLevelsMask()` calcule déjà le masque 1/3/5 sans littéral ; `Frame` ne déclare aucun `$touches` ; `movie_group.label` est un `string(120)` ; `frame.crop_seconds` un `unsignedSmallInteger`. `scripts/check-theme-tokens.mjs` n'a aucune entrée `hooks/admin` ni `lib/admin`.
> - `components/admin/admin-sidebar.tsx` laisse passer, sous le point d'arrêt mobile, le `SheetTitle` « Sidebar » et la `SheetDescription` anglaise en dur de `ui/sidebar` (dette n° 24 de `REPRISE.md`).
> - Base de développement : 3 films réels en `draft`, **0 frame curée**, vivier nul à tout N.

---

## 1. Périmètre, jalons et barre « terminé »

### 1.1 Ce que livre chaque jalon

| Sujet | J1 | J2 |
|---|---|---|
| Rôles et porte | trois rôles, matrice, policies, garde 2FA, premier admin par commande avec nom réel (corrigeable par la même commande) | écran de gestion des accès (correction du nom réel comprise, sous EN20-3), file « rôle privilégié sans 2FA », deux admins nominatifs |
| Import | `discover`, collage, **aperçu à blanc**, recherche et import unitaire, **liste d'amorçage en un bouton**, reprise | resynchronisation à l'écran, clore un balayage, limiteur TMDB partagé |
| Curation | file ordonnée, éditeur de banque, **recadreur 16:9 à plancher**, classement 1-5, aperçu en conditions de jeu, passe de revue, publication, titres, alias, `movie_group` manuel | réservation souple, candidats `movie_group` par distance, quasi-justes, rapport de collisions, thèmes (ajout d'une saga sans déploiement, S4 du 23/09, correction de la règle d'un thème livré et seuil de publication compris) |
| Images | voie TMDB seule ; route capture présente et **refusant** | voie capture, **si et seulement si** la licéité de l'acte de capture est arbitrée |
| Mesure | battement, temps de recadrage, tableau médiane/p90, verdict du lot pilote | — |
| Conformité | dépublication de curation (seul régime utile d'un site privé en `noindex`) | suspension conservatoire en un clic, retrait juridique, file des demandes de retrait, geste rétroactif de grille, modération des pseudos et copies provider |
| Observabilité | — | films jamais trouvés et incidents, inspecter une partie |

Raison du découpage par jalon (aucune coupe du J1, D35 du 23/09) : au J1 le site est privé, `noindex` intégral, sans aucun compte hors le porteur (`00` § Jalons, D1 et D4 du 23/09) ; les trois régimes de retrait et la file des demandes sont **bloquants avant le J2** (`00` § Ouverture, points 3 et 8), pas avant. Le reste du J2 est du confort de curation à plusieurs, qui n'a pas d'objet tant qu'un seul curateur existe — amendé le 23/09.

### 1.2 Le montage du J1 : curation en production, porteur seul

- **La curation naît en production** (D1 du 23/09) : domaine acheté avant la semaine 4, back-office déployé sur `<DOMAINE>`, worker de la file `default` monté par `100`. Aucun outil de transfert poste → production n'existe ni ne sera écrit : la provenance des revues (`frame_review.reviewer_id`) naît là où elle sert.
- **Le porteur est le seul compte privilégié du J1** (D4 du 23/09) : le premier admin, créé par commande après l'achat du domaine (§ 2.5), cure tout le J1. Aucun compte `curator` n'est créé avant l'écran de gestion des accès (J2). Les policies distinguent pourtant `curator` et `admin` dès le J1 : leur coût est nul, et c'est ce qui rend le J2 purement additif.
- **Aucune commande artisan sur le chemin du curateur** (décision 9, décision 10) : c'est un critère de **disqualification** du lot pilote (§ 10.4). Toute action de curation du J1 a son bouton (§ 13.1).
- **Le chemin critique du J1 est humain** (D36 du 23/09, qui révise la décision 3) : le développement est confié à l'IA, et l'enveloppe d'environ 10 h par semaine du porteur ne borne plus le développement ; elle couvre la curation, les décisions produit, les relectures et les gestes humains. Pour cette spec, ces gestes sont l'achat du domaine avant la semaine 4 (D1 du 23/09), le relevé puis le montage root du VPS (`100`), la création du premier admin (§ 2.5), la liste d'amorçage (§ 3.5), le lot pilote puis les 60 films du J1 en passe 1 (§ 10.3, § 10.4) et le déclenchement des déploiements manuels (D31 du 23/09). Les heures du § Lots sont des **mesures de taille**, jamais un calendrier ni un budget à tenir ; les lots de cette spec passent en tête de l'ordre d'implémentation « curation d'abord » (D37 du 23/09, § 10.3) — amendé le 23/09.

### 1.3 Le J1 livré complet, et ce qui ne se coupe jamais

- **Jamais coupé** : la barre « terminé » (tests verts, CI verte, textes du domaine `admin` complets, états de chargement, d'erreur et de déconnexion, **opérabilité clavier intégrale** du recadreur et de la revue — principe 8, `questions-ouvertes.md` « Terminé »).
- **Aucune coupe au J1** (D35 du 23/09) : tous les lots J1 de cette spec sont livrés au J1, écrans de confort (recherche TMDB, aperçu à blanc, liste d'amorçage en un bouton, `movie_group` manuel, page « premiers pas ») et chronométrage instrumenté du pilote (L20-17) compris. L'ancienne **coupe préparée d'avance** (`questions-ouvertes.md` § 8, corrigée par n° 2) — les **raccourcis de débit** et la **bande de visuels balayable** (§ 6.4, § 6.2 ; lot L20-11), et les **gestes de pointeur avancés** du recadreur, poignées d'angle, molette, pincement (§ 6.3 ; lot L20-9b) — est **sans objet** : la variable « recadreur minimal » ne s'applique plus, et ces deux lots sont livrés au J1. Ils restent distincts du socle L20-9a pour une seule raison : sans eux, l'outil est déjà intégralement opérable par ses boutons et son clavier (principe 8), si bien qu'ils marquent la frontière de la barre « terminé », et non plus une coupe. Le collage depuis le presse-papiers est **sans objet au J1**, puisqu'il ne sert que la capture, désactivée (§ 5.4) — amendé le 23/09.
- **Variables d'ajustement du J1** : il n'en reste qu'une, et elle porte sur la **curation**, pas sur le développement : le nombre de films du J1, réglé par le verdict du pilote (D10 du 23/09, § 10.4). Les retardataires ne sont plus une variable d'ajustement : **D17 du 23/09 est sans effet depuis D35 du 23/09**, `allowLateJoin` est livré et réglable au J1 comme tout réglage Simple (`50`) — amendé le 23/09.

---

## 2. Rôles, porte et autorisation

### 2.1 Trois rôles, un seuil par policy [J1]

`App\Enums\UserRole` [existant] : `player` ⊂ `curator` ⊂ `admin`, colonne `users.role` hors `#[Fillable]`, hiérarchie par `UserRole::atLeast()` (décision 9, `10` § 5.1). La ligne de partage n'est pas « qui touche au catalogue » mais **« qui engage le projet »** : le curateur importe, ajoute, recadre, classe, revoit, corrige, regroupe, publie et dépublie ; l'administrateur fait tout cela et **lui seul** modère, gère les accès, suspend, prononce un retrait juridique, décide d'une demande de retrait et inspecte une partie.

**Jamais de `Gate::before`** : il donnerait à l'admin la `saved_config` d'un tiers, strictement privée (`CLAUDE.md` §5, test existant « un administrateur est REFUSÉ sur la saved_config d'un tiers »). Chaque policy pose son seuil par `atLeast()` et elle est **vérifiée à chaque écriture**, par le middleware `can:` de la route, jamais seulement à l'affichage d'un bouton. Les booléens `abilities` envoyés aux écrans (§ 4.3) ne servent qu'à masquer un bouton : ils n'autorisent rien.

### 2.2 Matrice des capacités, ligne à ligne [J1 ; lignes J2 marquées]

Cette matrice est la table de vérité des écrans et de la famille de tests d'autorisation (§ 2.9) ; elle remplace la mention « matrice → 40 » de `10` § 15 (E10-67). Règles communes à **toutes** les lignes sous `/admin` : un invité est renvoyé vers la connexion ; un `player` reçoit **403 avant toute résolution de modèle** (`role:curator` prioritaire sur `SubstituteBindings`) ; un compte privilégié sans seconde authentification confirmée est renvoyé vers l'écran d'enrôlement (§ 2.4). « cur. » et « adm. » : réponse attendue pour un `curator` et un `admin` passés la porte.

| # | Geste ou écran | Route | Garde | cur. | adm. | Trace écrite | Jalon |
|---|---|---|---|---|---|---|---|
| 1 | Tableau de bord | `admin.dashboard` | `can:viewAny` `Movie` et `ImportRun` [existant] | 200 | 200 | — | J1 |
| 2 | Catalogue, fiche d'un film (tout état, retiré compris) | `admin.catalog.index`, `admin.catalog.show` | `MoviePolicy::viewAny`, `view` [existants] | 200 | 200 | — | J1 |
| 3 | File de curation ; film suivant (redirection, § 4.1) | `admin.curation.index` ; `admin.curation.next` | `MoviePolicy::viewAny` | 200 / 302 | 200 / 302 | — | J1 |
| 4 | Éditeur de la banque d'images | `admin.catalog.bank` | `MoviePolicy::curate` | 200 | 200 | — | J1 |
| 5 | Aperçu des octets `game` / `master` | `admin.catalog.frames.game`, `.master` | `FramePolicy::view` (C9-bis) | 200 | 200 | — | J1 |
| 6 | File de revue | `admin.review.index` | `can:create,App\Models\FrameReview` | 200 | 200 | — | J1 |
| 7 | Écran d'import, détail d'un balayage | `admin.import.index`, `admin.import.show` | `ImportRunPolicy` [existant] | 200 | 200 | — | J1 |
| 8 | Débit de curation et verdict du pilote | `admin.throughput.index` | `MoviePolicy::viewAny` | 200 | 200 | — (agrégat, jamais nominatif) | J1 |
| 9 | Premiers pas du curateur ; écran d'enrôlement 2FA | `admin.guide`, `admin.two_factor.required` | porte seule | 200 | 200 | — | J1 |
| 10 | Balayage `discover`, collage, reprise | `admin.import.discover`, `.ids`, `.resume` [existants] | `ImportRunPolicy::create` / `update` | 302 | 302 | `import_run` | J1 |
| 11 | Aperçu à blanc d'un collage ; liste d'amorçage | `admin.import.preview`, `admin.import.seed_list` | `ImportRunPolicy::create` | 302 | 302 | — ; `import_run` | J1 |
| 12 | Recherche TMDB ; import unitaire | `admin.import.search` (`throttle:admin-tmdb-search`, § 3.4), `admin.import.ids` | `ImportRunPolicy::viewAny` / `create` | 200 / 302 | idem | — ; `import_run` | J1 |
| 13 | Ajouter une variante depuis TMDB | `admin.catalog.frames.tmdb.store` | `can:create,App\Models\Frame,movie` (C9) | 302 | 302 | `frame.uploaded_by_id` | J1 |
| 14 | Ajouter une variante par capture | `admin.catalog.frames.capture.store` | `can:createFromCapture,App\Models\Frame,movie` | **403 tant que désactivée** | idem | `frame.uploaded_by_id` | route J1, voie J2 conditionnelle |
| 15 | Re-recadrer ; relancer un traitement | `admin.catalog.frames.crop.update`, `.retry` | `can:update,frame` (`FramePolicy::update`), `throttle:admin-frame` (C9 § 2) | 302 | 302 | `frame.unpublished` si l'image était publiée | J1 |
| 16 | Changer le niveau d'une image | `admin.catalog.frames.level.update` | `can:update,frame` (`FramePolicy::update`), `throttle:admin-curation` | 302 | 302 | `frame.unpublished` si publiée | J1 |
| 17 | Passer une revue (publie l'image si elle passe) | `admin.catalog.frames.review.store` | `FrameReviewPolicy::create` | 302 | 302 | ligne `frame_review` | J1 |
| 18 | Dépublier ou écarter une image | `admin.catalog.frames.unpublish` | `FramePolicy::unpublish` | 302 | 302 | `frame.unpublished` | J1 |
| 19 | Publier ou republier un film | `admin.catalog.publish` | `MoviePolicy::publish` | 302 | 302 | `movie.published` / `movie.republished` | J1 |
| 20 | Dépublier ou écarter un film | `admin.catalog.unpublish` | `MoviePolicy::unpublish` | 302 | 302 | `movie.unpublished` (motif obligatoire) | J1 |
| 21 | Cocher « contenu vérifié » | `admin.catalog.content_verified` | `MoviePolicy::verifyContent` | 302 | 302 | `movie.content_verified` (motif obligatoire) | J1 |
| 22 | Corriger ou retirer un titre ; ajouter ou retirer un alias | `admin.catalog.titles.update`, `.destroy` ; `admin.catalog.aliases.store`, `.destroy` | `MoviePolicy::curate` | 302 | 302 | `edited_by_id` / `created_by_id` | J1 |
| 23 | Regrouper deux films (`movie_group`) | `admin.catalog.group.update` | `MoviePolicy::curate` | 302 | 302 | `movie_group.created_by_id` | J1 |
| 24 | Battement de débit | `admin.catalog.heartbeat` | `MoviePolicy::curate` | 204 | 204 | `movie.curation_active_seconds` | J1 |
| 25 | Clore un balayage suspendu | `admin.import.abandon` | `ImportRunPolicy::update` | 302 | 302 | — | J2 |
| 26 | Resynchroniser un film | `admin.catalog.resync.show`, `.store` | `MoviePolicy::resync` | 200 / 302 | idem | `import_run` (`resync`) | J2 |
| 27 | Quasi-justes, rapport de collisions | `admin.near_misses.index`, `admin.near_misses.promote`, `admin.near_misses.dismiss`, `admin.collisions.index` (§ 9.5) | `MoviePolicy::viewAny` / `curate` | 200 / 302 | idem | alias `origin = curator` | J2 |
| 28 | Thèmes (liste, ajout d'une saga, libellés et ordre, correction de la règle, publication sous seuil), appartenance manuelle, difficulté corrigée | `admin.themes.index`, `admin.themes.store`, `admin.themes.update`, `admin.themes.publish`, `admin.catalog.themes.update`, `admin.catalog.difficulty.update` (§ 9.6) | `ThemePolicy::viewAny` / `create` / `update` / `publish`, `MoviePolicy::curate` | 200 / 302 | idem | `assigned_by_id` | J2 |
| 29 | Films jamais trouvés et incidents | `admin.incidents.index` | `MoviePolicy::viewAny` | 200 | 200 | — | J2 |
| 30 | Suspendre, lever (film, image) | `admin.catalog.suspend`, `.unsuspend`, `admin.catalog.frames.suspend`, `.unsuspend` | `MoviePolicy::suspend`/`unsuspend`, `FramePolicy::suspend`/`unsuspend` | **403** | 302 | `movie.suspended`, `movie.unsuspended`, `frame.suspended`, `frame.unsuspended` | J2 |
| 31 | Retrait juridique (film, image) ; relancer la suppression des fichiers | `admin.catalog.withdraw`, `admin.catalog.frames.withdraw`, `admin.catalog.frames.files.retry` | `MoviePolicy::withdraw`, `FramePolicy::withdraw` | **403** | 302 | `movie.withdrawn`, `frame.withdrawn` (motif obligatoire) | J2 |
| 32 | Demandes de retrait : file, fiche, enregistrement, décision | `admin.takedowns.index`, `admin.takedowns.show`, `admin.takedowns.store`, `admin.takedowns.decision` (§ 11.4) | `TakedownRequestPolicy::viewAny` / `view` / `create` / `decide` | **403** | 200 / 302 | `takedown.decided` + geste lié | J2 |
| 33 | Geste rétroactif de grille | `admin.exclusion_grid.retroactive.store` | `can:applyRetroactiveGrid,App\Models\Frame` (C14-bis) | **403** | 302 | `frame.grid_unpublished` par image | J2 |
| 34 | Gestion des accès ; correction du nom réel | `admin.access.index`, `admin.access.update` ; `admin.access.real_name.update` (§ 2.8) | `UserPolicy::viewAny`, `updateRole` ; `updateRealName` | **403** | 200 / 302 | `role.changed` ; `user.real_name_changed` si EN20-3 est inscrite | J2 (J1 : console) |
| 35 | Modération des pseudos et copies provider | `admin.moderation.index`, `admin.moderation.nickname.unmask`, `admin.moderation.avatar.unhide` (§ 11.5) | policies de `40` | **403** | 200 / 302 | `nickname.unmasked`, `avatar.unhidden` | J2 |
| 36 | Inspecter une partie | `admin.games.index`, `admin.games.show` | `GamePolicy::inspect` | **403** | 200 | — | J2 |
| 37 | Supprimer un film, une image, une revue, un balayage, une ligne de journal | **aucune route** | `delete` → faux partout | — | — | — | toujours |
| 38 | Modifier une revue ; lever `content_flag = blocked` ; sortir de `withdrawn` ; attribuer un rôle par inscription ou OAuth | **aucune route** | `FrameReviewPolicy::update` faux | — | — | — | toujours |
| 39 | Lire ou modifier la `saved_config` d'un tiers | hors `/admin` | `SavedConfigPolicy` [existant] | 403 | **403** | — | toujours |

### 2.3 La porte `/admin` [J1]

```php
Route::middleware(['auth', 'verified', 'role:curator', 'admin.2fa', 'admin.locale'])
    ->prefix('admin')->name('admin.')->group(…);
```

- **`admin.appearance` est retiré** du groupe, de `bootstrap/app.php` et du dépôt, avec `ForceAdminAppearance` et l'appel `useForcedAppearance('light')` de `admin-layout.tsx` (D8 du 23/09, C16 § 2.2) : le back-office suit l'apparence choisie ; seuls les cadres de revue et de prévisualisation passent sous les tokens sombres du jeu (§ 6.7). Ces retraits sont écrits **une seule fois**, par L90-1 (`90` § 2.2), propriétaire de C16 § 2.2 ; cette spec n'y ajoute que `admin.2fa` (L20-2).
- **Ordre** : `role` avant `admin.2fa`, pour qu'un `player` reçoive 403 et jamais une redirection d'enrôlement qui confirmerait l'existence de la porte ; `admin.2fa` rejoint `role` en tête de la liste de priorité, **avant `SubstituteBindings`**, pour la même raison d'énumération que `role` (`bootstrap/app.php`, commentaire existant).
- **Chaque route du groupe porte un `can:`**, sauf deux, nommées dans le test de balayage : `admin.guide` et `admin.two_factor.required`. Une route sans `can:` hors de cette liste fait échouer la suite.
- Les routes d'image et de revue vivent sous `->scopeBindings()`, `{movie}` et `{frame}` liés par `id` : une frame d'un autre film répond 404 (C9, E10-11 (b)). Les identifiants `movie.id` et `frame.id` ne sortent **que** vers le back-office.
- **Indexation** : tout le back-office reste `noindex, nofollow` **inconditionnellement**, y compris après la levée sélective du J2 (`questions-ouvertes.md` « Back-office en français seulement ») ; l'en-tête `X-Robots-Tag: noindex, nofollow` est posé par le middleware global `RobotsDirectives` de `90` (§ 5.2 : aucune route `admin.*` ne porte `RobotsDirectives::ROUTE_FLAG`) ; le `Disallow: /admin` de `robots.txt` est posé par `100`.

### 2.4 Seconde authentification des rôles privilégiés [J1 ; file au J2]

Le **mécanisme** est déjà fixé par `10` A16 (« rôle privilégié implique 2FA active : middleware + file de back-office, servis par `(role, last_login_at)` ») ; la **valeur** retenue est `curator` **et** `admin` (`questions-ouvertes.md`, confirmations attendues). Cette section ne décide que la redirection et le jalon de la file (n° 19).

- `App\Http\Middleware\EnsurePrivilegedTwoFactor` [nouveau], alias **`admin.2fa`**. Si l'utilisateur a `role->atLeast(Curator)` et `two_factor_confirmed_at === null`, la requête est redirigée vers `admin.two_factor.required` (`GET /admin/two-factor`, `TwoFactorRequiredController@show`, page `admin/two-factor-required`, route exclue de `admin.2fa` par `withoutMiddleware`). **Jamais un 403 muet** (question 1 de `REPRISE.md`) : la page explique en clés `admin.two_factor.*` pourquoi la porte est fermée et renvoie par un lien Wayfinder vers `security.edit`, où Fortify enrôle avec confirmation (`config/fortify.php` : `'confirm' => true`, donc `two_factor_confirmed_at` est bien posé). Une écriture interceptée reçoit une redirection 303, jamais une exécution.
- **Pourquoi au J1** : le compte du porteur est le plus privilégié du projet et il vit en production dès la semaine 4 (D1 du 23/09) ; Fortify fournit le TOTP sans dépendre de rien d'autre. Coût : un middleware et une page.
- **Ce que la garde ne juge pas** : la manière dont la session s'est ouverte. Elle suppose que tout chemin de connexion d'un compte à 2FA active passe le défi : Fortify au J1 ; au J2, l'OAuth est tenu par `40` (`00` : « un compte à 2FA active n'est jamais lié ni connecté sans second facteur », `CLAUDE.md` §8 sur `Auth::login()`). L'équivalence éventuelle d'une passkey est une question de `40` ; jusque-là la garde ne lit que `two_factor_confirmed_at`.
- **Perte du second facteur** : codes de secours Fortify ; au-delà, procédure d'exploitation de `100`, hors du chemin du curateur.
- **[J2] File « rôle privilégié sans 2FA »** : sur l'écran de gestion des accès (§ 2.8), `role IN ('curator','admin') AND two_factor_confirmed_at IS NULL AND anonymized_at IS NULL`, servie par `users_role_last_login_at_index` (`10` § 5.1). Au J1 elle est vide par construction : la garde empêche tout usage du back-office sans second facteur.

### 2.5 Premier administrateur [J1]

`admin:first-admin` [existant] est **la seule commande que le back-office exige jamais**, exécutée **une fois** par le porteur, en SSH, après l'achat du domaine (`00` : aucun compte de production avant le domaine définitif ; D1 du 23/09). Ce n'est pas un geste quotidien de curation.

- **Nom réel** (D12 du 23/09) : nouvelle option `{--real-name= : Nom réel, figé dans les preuves de revue et le journal}` ; sans elle, en session interactive, une invite `admin.console.first_admin.real_name_prompt` ; en session non interactive sans l'option, refus `admin.console.first_admin.real_name_required`, rien n'est écrit. La valeur passe par `RealNameValidationRules::realNameRules()` (§ 2.6).
- **Preuve d'adresse, tranchée une fois pour les deux chemins** (question 6 de `REPRISE.md`, n° 15) : **l'accès au shell vaut preuve**. L'opérateur de la console a plus de pouvoir qu'un administrateur ; exiger en plus un courriel de vérification ajouterait une dépendance à un SMTP sans rien prouver. La commande pose donc `email_verified_at` en création **comme** en promotion, et **`--create` devient définitif** pour le premier administrateur.
- **Journal** : la ligne `role.changed` passe par `AdminJournal::recordFromConsole(AdminActionType::RoleChanged, $user->id, null, $before, UserRole::Admin)`, `actor_id` NULL, `actor_name = AdminAction::CONSOLE_ACTOR` (constante **déplacée** depuis la commande, C14). Même transaction que le rôle.
- **Corriger le nom réel au J1** : c'est la seule voie, porteur seul (D4 du 23/09). Relancée sur le compte **déjà** administrateur avec `--real-name=`, la commande réécrit `real_name` s'il diffère (règles du § 2.6, message `admin.console.first_admin.real_name_updated`) au lieu de rendre « inchangé » ; **sans ligne `role.changed`**, puisque le rôle ne change pas (invariant 7 du § 2.7), et sans autre ligne : la liste fermée n'a aucun cas pour ce geste, et l'opérateur de la console a de toute façon plus de pouvoir qu'un administrateur. Sans l'option, l'idempotence existante est inchangée. Les instantanés déjà figés (`reviewer_name`, `actor_name`) ne sont jamais réécrits : la correction ne vaut que pour les gestes suivants. Au J2, l'écran de gestion des accès prend le relais (§ 2.8).
- **`--force`** est conservé comme **procédure de secours** (perte de tout accès administrateur), jamais comme voie d'attribution ordinaire : au J2, « tous les suivants par l'écran de gestion des accès » (`00` § Back-office) reste la seule voie. Au J1 il ne sert pas (D4 du 23/09).

### 2.6 Nom réel des comptes privilégiés [J1]

D12 du 23/09 : l'attribution de `curator` ou `admin` exige un **nom réel**, distinct du pseudo, et c'est lui que figent `frame_review.reviewer_name` et `admin_action.actor_name`.

- Colonne `users.real_name` (E10-02), `#[Hidden]`, hors `#[Fillable]` — `HandleInertiaRequests` sérialise l'utilisateur sur toutes les pages, écran de jeu compris.
- `App\Concerns\RealNameValidationRules::realNameRules()` [nouveau] : requis, chaîne rognée, 2 à 255 caractères, différent de `system` et de `console` sans tenir compte de la casse ; message `admin.validation.real_name`.
- Garde `User::saving` [ajout] : un rôle ≥ `curator` exige un `real_name` non vide ; elle ne se déclenche que si `role` ou `real_name` change, pour ne jamais bloquer un compte existant.
- **Instantanés** : `reviewer_name` et `actor_name` = `users.real_name` à l'instant du geste (E10-22) ; jamais `users.name`, jamais pré-rempli dans `users.name` ni dans `player.nickname`.
- **Anonymisation** : `users.real_name` est vidé, les instantanés sont **conservés** (lecture retenue de D12 du 23/09, R-42, `10` § 5.5). Le nom réel n'apparaît que sur des écrans du back-office : journal, revues, accès.
- **Code à aligner dans le même lot** (C14 inv. 13) : `UserFactory::role()` pose un `real_name` factice pour ≥ `curator`, `anonymized()` le vide ; `DemoAccountsSeeder`, `FrameReviewFactory::by()`, `AdminActionFactory::byActor()` et `DemoCatalogueSeeder` (élévation initiale par `recordFromConsole`) suivent.

### 2.7 Journal `admin_action` : liste fermée, acteurs réservés, écrivain unique [J1 ; gestes selon le jalon]

`10` possède le schéma et la liste (§ 8.3) ; cette spec en formule les exigences (E10-05) et en écrit l'unique écrivain. **21 cas**, dont 6 nouveaux, sans migration (`action string(40)`, `subject_type string(20)`, `subject_id` nullable existants) :

| Valeur | Sujet | Motif | Acteur admis | Écrit par | Jalon du geste |
|---|---|---|---|---|---|
| `role.changed` | user | facultatif | admin (nom réel) ; `console` pour le premier admin | 20 | J1 console, J2 écran |
| `movie.published` **[nouveau]** | movie | — | curator+ | 20 | J1 |
| `movie.unpublished` | movie | **obligatoire** | curator+ | 20 | J1 |
| `movie.republished` | movie | — | curator+ | 20 | J1 |
| `movie.content_verified` | movie | **obligatoire** | curator+ | 20 | J1 |
| `movie.suspended` | movie | facultatif | admin | 20 | J2 |
| `movie.unsuspended` | movie | facultatif | admin | 20 | J2 |
| `movie.withdrawn` | movie | **obligatoire** | admin | 20 | J2 |
| `frame.unpublished` **[nouveau]** | frame | facultatif | curator+ | 20 | J1 |
| `frame.grid_unpublished` **[nouveau]** | frame | **obligatoire** | admin | 20 | J2 |
| `frame.suspended` | frame | facultatif | admin | 20 | J2 |
| `frame.unsuspended` **[nouveau]** | frame | facultatif | admin | 20 | J2 |
| `frame.withdrawn` | frame | **obligatoire** | admin | 20 | J2 |
| `avatar.hidden` | user | — (`reports_count`) | `system` | 40 | J2 |
| `avatar.unhidden` | user | facultatif | admin | 40 | J2 |
| `nickname.masked` | player | — (`reports_count`) | `system` | 40 | J2 |
| `nickname.unmasked` | player | facultatif | admin | 40 | J2 |
| `nickname.banned` | player | facultatif (40 tranche) | admin | 40 | J2 |
| `takedown.decided` | takedown_request | **obligatoire** | admin | 20 | J2 |
| `site.closed` **[nouveau]** | **site** | **obligatoire** | `console` | 100 | fixé par 100 |
| `site.reopened` **[nouveau]** | **site** | facultatif | `console` | 100 | fixé par 100 |

Ajouts aux enums [C14] : `AdminActionSubject::Site = 'site'` ; méthodes `AdminActionType::requiresReason()`, `allowsConsoleActor()` (vrai pour `RoleChanged`, `SiteClosed`, `SiteReopened`), `labelKey()` = `'admin.enum.admin_action.'.str_replace('.', '_', $this->value)`. `retentionClass()` rend `Permanent` pour tous les cas (sujet `site` compris).

**Écrivain unique** `App\Support\Admin\AdminJournal` [nouveau] :

```php
public function record(User $actor, AdminActionType $action, ?int $subjectId, ?string $reason = null,
    ?TakedownRequest $takedownRequest = null, ?UserRole $roleBefore = null, ?UserRole $roleAfter = null): AdminAction;
public function recordFromConsole(AdminActionType $action, ?int $subjectId, ?string $reason = null,
    ?UserRole $roleBefore = null, ?UserRole $roleAfter = null): AdminAction;
public function recordAutomatic(AdminActionType $action, int $subjectId, int $reportsCount): AdminAction; // 40, J2
```

Invariants, gardés par `AdminAction::creating` et par `AdminJournal`, chacun testé :

1. `subject_type` est **dérivé** de `action->subject()`, jamais fourni ; `retention_class` aussi [existant].
2. `subject_id` est NULL **si et seulement si** le sujet est `site`.
3. Automatique ⟺ `actor_id` NULL **et** `actor_name = 'system'` ; seuls `avatar.hidden` et `nickname.masked` le sont.
4. `actor_name = 'console'` ⟹ `allowsConsoleActor()` **et** `actor_id` NULL.
5. Sinon `actor_id` non nul et `actor_name` = instantané rogné non vide de `users.real_name`, différent de `system` et `console` sans tenir compte de la casse.
6. `requiresReason()` ⟹ `trim(reason) ≠ ''` ; un motif fait au plus 500 caractères (colonne `string(500)`).
7. `role.changed` ⟹ `role_before` et `role_after` non nuls et différents ; toute autre action ⟹ les deux NULL.
8. `AdminJournal` exige `DB::transactionLevel() > 0` et lève une `LogicException` sinon : la ligne s'écrit **dans la transaction de l'état qu'elle justifie**, ou pas du tout. L'ajout seul est déjà garanti (`AppendOnlyBuilder`).
9. **Preuves sans ligne `admin_action`** : la publication d'une **image** est prouvée par sa propre ligne `frame_review` passante (§ 7.5) ; la levée d'une suspension de film restaure les images suspendues par la cascade sans ligne par image, la ligne `movie.unsuspended` les couvre (§ 11.2).
10. **Pourquoi `movie.published`** : l'état antérieur d'une levée de suspension se reconstitue depuis le journal, ce qui exige que **toute** transition de disponibilité d'un film y figure, première publication comprise (`10` A2).
11. Tout geste pris en exécution d'une demande de retrait porte `takedown_request_id` (`10` § 8.3).
12. Le drainage de déploiement n'écrit aucune ligne : ce n'est pas un geste sur un sujet (C18-bis).

**Motif écrit par le serveur.** Quand le serveur pose lui-même un motif, sans saisie (changement de niveau d'une image publiée, § 5.7 ; garde rejouée en échec à la levée d'une suspension, § 11.2), `admin_action.reason` et `availability_reason` reçoivent le **texte** résolu par `__('admin.frame.level.default_reason')` ou `__('admin.movie.unsuspend.guard_failed')`, jamais la clé : ce sont des motifs libres, relus tels quels sur la fiche et au journal, et le back-office est en français seul (§ 13.2) ; une clé stockée s'afficherait brute, contre « aucun message brut » (décision 9). Seules `frame.processing_error` et `frame.files_deleted_error` portent une clé de traduction (`10` § 4.1).

**Cas du J2 en attente.** La correction du nom réel à l'écran (§ 2.8) demande un 22ᵉ cas, `user.real_name_changed` (sujet `user`, motif facultatif, acteur admin), adressé à `10` en exigence nouvelle EN20-3. La liste du J1 reste à 21 cas, et le test qui la compte ne change qu'avec le lot qui livre l'écran, si `10` inscrit le cas.

Clés : `admin.enum.admin_action.*` (21 feuilles, points remplacés par `_`), `admin.enum.admin_action_subject.*` (6 feuilles).

### 2.8 Écran de gestion des accès [J2]

Page `admin/access/index`, `AccessController@index` et `@update`, `UserPolicy` [nouvelle] : `viewAny`, `updateRole(User $actor, User $target)` et `updateRealName(User $actor, User $target)` = `actor` admin **et** `target` non anonymisé ; `updateRealName` exige en plus `target` au moins `curator` (le nom réel n'existe que pour les comptes privilégiés, D12 du 23/09).

- **Contenu** : les comptes privilégiés non anonymisés (nom réel, `users.name`, e-mail, rôle, état de la 2FA, dernière connexion), la file « rôle privilégié sans 2FA » (§ 2.4), une recherche par e-mail exact pour promouvoir un compte existant, et l'**historique daté** des rôles lu dans `admin_action` (`role.changed`, `role_before`/`role_after`) — il n'existe aucune table `role_history` (`10` A15).
- **Attribuer ou retirer** `curator`/`admin` : motif facultatif ; nom réel **exigé** à l'attribution s'il manque (D12 du 23/09), saisi par l'administrateur dans le même formulaire ; e-mail vérifié exigé (le groupe porte `verified`). Action `App\Actions\Admin\ChangeUserRole` : une transaction, `lockForUpdate` sur les lignes `users` des administrateurs non anonymisés, ligne `role.changed` par `AdminJournal::record`.
- **Corriger le nom réel** d'un compte privilégié (`PATCH admin/access/{user}/real-name` → `admin.access.real_name.update`, `AccessController@updateRealName`, `RealNameUpdateRequest` : `real_name` par `RealNameValidationRules::realNameRules()`, `reason` ≤ 500 facultatif), admin seul (`UserPolicy::updateRealName`), faute de frappe comprise. Le geste ne vaut que pour les gestes **suivants** : les instantanés `reviewer_name` et `actor_name` déjà figés ne changent jamais (E10-22). Il est journalisé par le cas `user.real_name_changed` si `10` inscrit EN20-3 ; sinon il n'est pas livré, et la correction reste la voie console du § 2.5 — un nom qui signe les preuves ne change pas sans trace.
- **Dernier administrateur indéboulonnable** : un geste qui laisserait zéro administrateur non anonymisé est refusé (`admin.access.last_admin`). L'invariant vaut **pour tout chemin qui réduit le nombre d'administrateurs** : `40` l'applique à la suppression de compte en libre-service, qui force le rôle à `player` (`10` § 5.5).
- **Deux administrateurs nominatifs avant l'ouverture** (`00` § Gouvernance) : tant que le décompte est inférieur à deux, l'écran affiche `admin.access.second_admin_missing` ; c'est une condition du J2, pas une garde de code.

### 2.9 Policies et famille de tests d'autorisation [J1 ; méthodes J2 marquées]

| Policy | Méthode | Seuil et condition |
|---|---|---|
| `MoviePolicy` [modifiée] | `viewAny`, `view` [existants] | curator+ ; `view` quel que soit l'état |
| | `create` [existant] | toujours faux : la création est un acte d'import |
| | `curate` | curator+ **et** `availability ≠ withdrawn` |
| | `publish` | curator+ **et** `availability ∈ {draft, unpublished}` |
| | `unpublish` | curator+ **et** `availability ∈ {draft, published}` |
| | `verifyContent` | curator+ **et** `content_flag = unrated_pending` **et** `availability ≠ withdrawn` |
| | `resync` (J2) | curator+ **et** `import_source ≠ demo` **et** `tmdb_id` non nul **et** `availability ≠ withdrawn` |
| | `suspend` (J2) | admin **et** `availability ∉ {suspended, withdrawn}` |
| | `unsuspend` (J2) | admin **et** `availability = suspended` |
| | `withdraw` (J2) | admin **et** `availability ≠ withdrawn` (tout état source, `suspended` compris : un retrait suit souvent une suspension) |
| `FramePolicy` [nouvelle, C9] | `view` | curator+ **et** `availability ≠ withdrawn` (C9-bis) |
| | `create(User, Movie)` | curator+ **et** film ni `suspended` ni `withdrawn` |
| | `createFromCapture(User, Movie): Response` | refus motivé `admin.frame.capture.disabled` tant que `catalog.curation.capture_enabled` est faux ; sinon comme `create` |
| | `update` | curator+, **sans condition d'état**. Les refus d'état sont des erreurs de validation traduites, jamais des 403 : `admin.frame.recrop.not_ready` si `game_path` est NULL, `busy` si la frame est `pending`, `locked` si elle est `suspended` ou `withdrawn` (C9 § 3), et leurs équivalents pour « Relancer » et « Changer le niveau » (§ 5.6, § 5.7) |
| | `unpublish` | curator+ **et** `availability ∈ {draft, published}` |
| | `applyRetroactiveGrid(User)` (J2) | admin **et** `ExclusionGrid::isRetroactive(CURRENT_VERSION)` |
| | `suspend`, `unsuspend`, `withdraw` (J2) | admin, avec les mêmes états source que `MoviePolicy` : `availability ∉ {suspended, withdrawn}`, `= suspended`, `≠ withdrawn` |
| `FrameReviewPolicy` [nouvelle, C14-bis] | `create` / `update` / `delete` | curator+ / **toujours faux** / **toujours faux** |
| `ImportRunPolicy` [existante] | inchangée | `update` sert aussi « clore un balayage » (J2) |
| `UserPolicy` [nouvelle, J2] | `viewAny`, `updateRole`, `updateRealName` | admin (§ 2.8) |
| `TakedownRequestPolicy` [nouvelle, J2] | `viewAny`, `view`, `create`, `decide` | admin |
| `ThemePolicy` [nouvelle, J2] | `viewAny`, `create`, `update`, `publish` | curator+ ; `create` n'ajoute qu'une saga (§ 9.6) |
| `GamePolicy` [nouvelle, J2] | `inspect` | admin |

La garde d'ajout d'image nomme la classe en premier argument (`can:create,App\Models\Frame,movie`) : écrite `can:create,movie`, elle résoudrait `MoviePolicy::create()`, toujours fausse, et toute la voie TMDB répondrait 403 (C9, V-17).

**Famille de tests** (décision 9) : un jeu de données unique, `tests/Datasets/AdminRoutes.php`, décrit **chaque** route `admin.*` avec sa méthode, ses paramètres de fabrique et la réponse attendue par rôle ; `AuthorizationMatrixTest` le joue pour un invité, un `player`, un `curator` et un `admin`, et un test de balayage vérifie que le jeu couvre exactement `Route::getRoutes()` filtré sur `admin.*`. Les tests existants de `AuthorizationTest` restent en place ; les privilégiés des fabriques reçoivent `withTwoFactor()` par défaut, et un état `withoutTwoFactor()` sert les tests de la porte.

---

## 3. Import TMDB

### 3.1 Deux voies, filtres asymétriques — rappel, jamais redéfini [J1]

La règle vit dans `10` § 9.2 (décisions 11 et 12) et n'est pas réécrite ici : le filtre **de goût** (`config('catalog.import_filter')`, 500 votes, langues `{fr, en, ja}`, 1970 au défaut) ne gouverne que le balayage `discover` ; la **voie d'exception** (collage, recherche unitaire, liste d'amorçage) l'ignore et marque **toujours** `is_import_exception` avec ses trois motifs ; les filtres **de contenu** (`adult`, FR -18, US NC-17, US X, certification la plus récente par pays lue à l'appel de détail) ne sont contournables par **aucune** voie ni **aucun** rôle. Un film `withdrawn` bloque son réimport par sa ligne (`10` A10). Aucun appel TMDB n'a lieu pendant une partie (règle 6).

### 3.2 Balayage `discover` [J1, existant ratifié]

L'écran d'import existant est conservé : filtre pré-rempli depuis la configuration, visible et élargissable, `is_widened` figé à l'ouverture ; bornes de pages `catalog.import.pages_min`/`pages_max` ; exécution différée par `RunCatalogImport` ; reprise d'un balayage suspendu **par le bouton « Reprendre »** (`admin.import.resume`). Seuls ses messages changent (§ 13.1).

### 3.3 Collage : aperçu à blanc, refus durs, reprise [J1]

Le collage accepte la syntaxe de `TmdbIdentifierList` [existante] : identifiants nus, séparés par des espaces, des virgules ou des sauts de ligne, URL de fiche TMDB, commentaires après `#` ; doublons retirés, ordre conservé ; au plus `catalog.import.paste_max_ids` identifiants par envoi web.

- **Aperçu à blanc avant tout import** (n° 8, `questions-ouvertes.md` « Import par lot ») : bouton « Prévisualiser » → `admin.import.preview` (`ImportPreviewController@store`, mêmes règles que le collage via `App\Concerns\CatalogImportValidationRules` [existant]) → jeton `bin2hex(random_bytes(16))` → job `App\Jobs\Catalog\PreviewCatalogPaste` [nouveau], file `default`, qui appelle `catalog:import-ids --preview=<jeton>` (la voie console garde `TmdbClient` dans son périmètre autorisé, `TmdbBoundaryTest` inchangé).
- **Aucune ligne `import_run` en simulation** : sous `--preview` comme sous `--dry-run` [modifié], l'instance `ImportRun` passée à `MovieImporter` est construite en mémoire et **jamais sauvegardée** ; l'historique des balayages ne contient que des imports réels.
- **Résultat ligne par ligne** tenu en cache (`App\Support\Admin\PastePreview`, clé `admin:paste-preview:{userId}:{jeton}`, durée `catalog.import.preview_ttl_minutes`) : pour chaque identifiant, le sort `ImportDecision` [existant] (`simulated`, `duplicate`, `refused_content`, `refused_withdrawn`, `not_found`), le titre original, l'année, les motifs d'exception, la clé du motif de refus. L'écran l'interroge par `usePoll` sur la prop optionnelle `paste_preview` de `admin.import.index` jusqu'à complétude ; **les refus de contenu s'affichent comme lignes refusées** avec leur motif. Le chiffre opposable reste `import_run.total_refused_content`, écrit par l'import réel.
- L'aperçu est **indicatif** : le bouton « Importer ces films » ouvre ensuite le collage réel (`admin.import.ids`), qui rejoue toutes les gardes.
- **Reprise d'un collage interrompu** (question 20) : on **recolle la même liste**. Le dédoublonnage par `tmdb_id` (`10` § 9.2) rend l'opération idempotente ; la liste d'amorçage, elle, est un fichier versionné (§ 3.5). Aucune exigence à `10`.

### 3.4 Recherche et import unitaire [J1]

- `TmdbClient::search(string $query, int $page = 1): TmdbPage` [ajout], requête TMDB de recherche de films sans contenu adulte, première page seule. **Route dédiée** : `GET admin/import/search?q=` → `admin.import.search` (`App\Http\Controllers\Admin\ImportSearchController@index` [nouveau], dans le périmètre `Admin` de `TmdbBoundaryTest`, `can:viewAny,App\Models\ImportRun`, `throttle:admin-tmdb-search`), `q` de 2 à 100 caractères ; elle rend la page `admin/import/index` avec les props de `ImportController@index` (présentateur commun) plus `search_results`. Le limiteur `admin-tmdb-search` [nouveau] est par utilisateur, valeur `catalog.curation.rate_limits.search` (§ 13.7). **`admin.import.index` ne porte aucun limiteur** : c'est la route que l'aperçu de collage interroge par `usePoll` (§ 3.3), et `admin-import` (12 envois par minute, `FortifyServiceProvider`) est écrit pour les trois écritures qui ouvrent chacune une ligne `import_run` — l'y poser donnerait des 429 en plein aperçu et consommerait le quota des vrais imports.
- Chaque résultat affiche titre, titre original, année, langue originale, votes, et son état local : « déjà au catalogue » (avec sa disponibilité) ou « retiré — réimport bloqué ». « Importer » poste **un seul identifiant** sur `admin.import.ids` : c'est un collage, donc toujours marqué exception, même si le film satisfait le filtre (`10` § 9.2). Le compteur « entrés par exception » en grossit : résidu assumé, lisible par ses trois motifs.
- **Un seul collage ouvert à la fois** : `admin.import.ids` ouvre son `import_run` par `ImportLauncher::openExclusively(ImportRunKind::Paste, …)` [existant], dont le verrou refuse un second collage tant qu'un premier est en file ou en cours (deux curseurs sur le même quota TMDB, deux jeux de compteurs dont aucun ne prouverait rien). « Importer » pendant un collage ouvert — celui de la liste d'amorçage compris (§ 3.5) — reçoit donc le refus traduit `admin.error.import_already_running` [existant], et le bouton redevient utile à la fin du collage. Ce verrou n'est ni contourné ni dupliqué.

### 3.5 Liste d'amorçage [J1]

- Fichier `database/data/tmdb-seed-list.txt` [existant], chemin `catalog.import.seed_list_path` [nouveau], format du collage (§ 3.3). Sa constitution — environ 200 identifiants, canon Disney antérieur à 1970 d'abord, puis classiques non anglophones, relue une fois — est un **travail produit de 4 à 6 h** du porteur (`00` § Catalogue), pas un lot de développement, et il est sur le chemin critique humain du lot pilote (D36 du 23/09, § 10.3) — amendé le 23/09. L'en-tête du fichier est réécrit pour nommer le bouton ; la console reste un outil d'exploitation.
- **Bouton « Importer la liste d'amorçage »** (`admin.import.seed_list`, `ImportSeedListController@store`, `can:create,App\Models\ImportRun`, `throttle:admin-import` comme les trois écritures existantes, puisqu'il ouvre une ligne `import_run`) : le serveur lit le fichier versionné et retire les identifiants déjà au catalogue, films retirés compris (dédoublonnage par `tmdb_id`, `movie_tmdb_uq`). Il ouvre ensuite **un seul** collage par `ImportLauncher::openExclusively(ImportRunKind::Paste, …)`, portant les `catalog.import.paste_max_ids` premiers identifiants restants, dans l'ordre du fichier. Tant qu'un collage est ouvert (`ImportLauncher::hasOpenRun(ImportRunKind::Paste)`), le bouton est inactif, avec `admin.import.seed_list.busy` et un lien vers ce collage ; une fois le collage fini, un nouveau clic importe le lot suivant, et le bouton affiche le nombre d'identifiants restants (`admin.import.seed_list.remaining`, paramètre `:count`). **Jamais deux collages ouverts à la fois** : le verrou de `ImportLauncher` n'est ni contourné ni dupliqué (§ 3.4). Pourquoi par lots : `paste_max_ids` borne ce qu'un envoi web confie au worker, sous le `--timeout` de la file `default` (`config/catalog.php`, commentaire existant ; `100` § 10.4) — un collage de 200 identifiants pourrait être tué en cours de route et laisser un balayage `running` sans cause visible. **Idempotent** : un clic suivant reprend toujours au premier identifiant manquant. Un bouton « Prévisualiser le lot suivant » applique le § 3.3 aux mêmes identifiants.
- Bouton inactif, avec `admin.import.seed_list.empty`, tant que le fichier ne contient aucun identifiant (état actuel).
- Garde-fou nommé par l'en-tête du fichier : un identifiant erroné importe **silencieusement** un autre film. L'aperçu à blanc est donc le geste normal avant tout import de la liste, et la file de curation reste le dernier filet.

### 3.6 Étranglement du quota TMDB [J1 ratifié ; partage au J2]

`10` § 15 donne la politique à cette spec. **Ratification de l'existant** : espacement en code par `TmdbQuotaLimiter` à `catalog.import.requests_per_second` (35, délibérément sous la limite annoncée par TMDB), reprise depuis `import_run.last_request_at` ; `config/services.php › tmdb` : trois nouvelles tentatives sur 429, 5xx et transport (jamais 401), retrait exponentiel de base 500 ms plafonné à 8 s, `Retry-After` suivi jusqu'à 60 s ; au-delà, balayage **suspendu et reprenable d'un bouton**.

- **Au J1, la somme des appels reste sous le plafond par construction** : un seul worker `default` (D1 du 23/09, `100`) exécute les imports l'un après l'autre, et les appels interactifs (recherche, visuels de l'éditeur, téléchargement d'un original) sont cadencés par un seul humain. Un 429 sur un appel interactif produit `admin.tmdb.error.rate_limited_interactive` et un bouton « Réessayer », jamais une page d'erreur.
- **[J2]** dès que plusieurs curateurs ou plusieurs workers `default` existent : un limiteur **partagé en cache**, commun à tous les processus, plafonne la somme des balayages et des appels interactifs, ceux-ci passant en priorité.
- Un import tient la file `default` le temps de ses pages (au plus `pages_max` pages de détails) ; les jobs d'image attendent derrière : quelques secondes à quelques dizaines de secondes, résidu assumé au J1.

### 3.7 Resynchronisation depuis l'écran [J2]

Page `admin/catalog/resync` (`MovieResyncController@show` et `@store`, `MoviePolicy::resync`) : **écran de différences** bornées à la liste close de `10` § 9.3, film par film ou par lot depuis une sélection du catalogue (lot = job différé, résumé à l'écran). Le filtre d'import n'est **jamais** réappliqué ; titres et alias `origin = curator`, `movie_difficulty_override`, `group_id`, appartenances manuelles, `availability`, la coche de contenu, toutes les frames et revues sont intouchés. Une certification restrictive découverte bascule `content_flag` en `blocked` (sortie du vivier à la seconde, `10` A3) et **propose** « Dépublier » au curateur, motif pré-rempli — jamais une dépublication automatique, jamais une destruction de fichier. Un alias TMDB supprimé à la main réapparaît et l'écran le signale « réapparu ». Le catalogue de démonstration n'est jamais candidat.

### 3.8 Clore un balayage suspendu [J2]

`POST admin/import/run/{importRun}/abandon` → `admin.import.abandon` (`ImportAbandonController@store`, `ImportRunPolicy::update`) : confirmation, puis `status = failed` et `finished_at`, **sans motif ni ligne `admin_action`** — un import n'est pas un geste engageant (question 21). `ImportLauncher::BUSY_GRACE_MINUTES` reste en place : un balayage suspendu n'interdit déjà plus d'en lancer un autre, le bouton n'est qu'une hygiène.

---

## 4. File de curation et fiche film

### 4.1 File des films non curés [J1 ; réservation au J2]

Page `admin/curation/index` (`CurationQueueController@index`), service `App\Support\Curation\CurationQueue` [nouveau]. Périmètre : films `draft` hors `import_source = demo`.

- **Ordre** (question 13) : d'abord les films **entamés** (au moins une frame non écartée), du plus récemment touché au plus ancien — `MAX(frame.updated_at)` des frames du film, lu par le préfixe `movie_id` de `frame_movie_level_idx` : ajouter une image, la recadrer, la revoir ou changer son niveau remonte le film, sans dépendre d'un effet de bord (`Frame` ne déclare aucun `$touches`, et le battement du § 10.1 n'écrit que `curation_active_seconds`) —, pour que la reprise après interruption soit naturelle — l'état est en base ; puis les films non entamés par `vote_count` décroissant, `id` croissant. Le tri sur `movie_difficulty` est écarté tant que `30` n'a pas livré sa dérivation, nulle pour tout film réel (n° 17).
- **Filtres** : voie d'entrée (`discover` / exception), motif d'exception, drapeau de contenu — ils servent à composer le lot pilote (D11 du 23/09, § 10.3).
- **« Film suivant »** (`admin.curation.next`) redirige vers l'éditeur du premier film de la file autre que le film courant : c'est le geste de débit qui enchaîne deux films. **File vide** : la redirection ramène à la file, qui affiche `admin.curation.empty` et un lien vers l'import.
- **Réservation** : aucune au J1 (un seul curateur, D4 du 23/09). **[J2]** réservation souple tenue en cache (`curation:claim:{movieId}` → identifiant du curateur), prolongée par le battement (§ 10.1), expirée après `catalog.curation.claim_minutes` sans battement ; la file saute les films réservés par un autre et l'affiche. Aucune colonne.

### 4.2 Écarter un film [J1]

Un film **incurable** — aucun visuel TMDB exploitable, capture désactivée — doit sortir de la file sans état « écarté » dans le schéma (vérification, élément non couvert n° 5). Règle : **écarter = dépublier un film jamais publié**. `admin.catalog.unpublish` accepte un film `draft` ; il passe `unpublished`, `availability_reason` porte le motif (obligatoire, pré-rempli par `admin.movie.set_aside.default_reason` « Aucun visuel TMDB exploitable »), ligne `movie.unpublished`. `first_published_at` nul distingue un film écarté d'un film dépublié : c'est un usage de back-office de cette colonne, jamais un prédicat de jeu ni de vivier, qui demande à `10` d'amender sa phrase « horodatage, jamais un prédicat » (EN20-1). « Écarté » (`set_aside`) entre au lexique normatif (AN20-7). Liste « Films écartés » sur le tableau de bord. Rien n'est perdu : curer puis publier un film écarté est une **première** publication (`movie.published`). Au lot pilote, un film écarté compte comme un échec (D11 du 23/09, § 10.3).

### 4.3 Fiche film [J1]

La fiche existante (`admin.catalog.show`, huit blocs en lecture) reçoit ses gestes, chacun sous sa policy : lien « Curer » vers l'éditeur (§ 6), publier / dépublier / écarter (§ 8), coche de contenu (§ 4.4), titres et alias (§ 9), regroupement (§ 9.4) ; au J2 suspendre, lever, retirer, resynchroniser. La prop `abilities` (`{ curate, publish, unpublish, verifyContent, resync, suspend, unsuspend, withdraw }`, booléens calculés par `Gate::allows`) ne sert qu'à l'affichage. La clé `admin.movie.frames.editor_pending` est retirée.

### 4.4 Coche « contenu vérifié, pas de classification restrictive » [J1]

Question 12. Sur la fiche, pour un film `content_flag = unrated_pending` : bouton « Contenu vérifié » (`admin.catalog.content_verified`, `MovieContentVerifiedController@store`, `MoviePolicy::verifyContent`), boîte de confirmation, **motif obligatoire** (≤ 500). Action `App\Actions\Curation\VerifyMovieContent` : une transaction, `content_flag = clear`, `content_verified_by_id`, `content_verified_at`, ligne `movie.content_verified` avec motif. **Pas de décoche** en interface : le remède est la dépublication (curateur) ou la suspension (admin, J2). `blocked` n'est **jamais** levable en interface (décision 12 : filtre non contournable, par aucune voie ni aucun rôle).

---

## 5. Frame servable : géométrie, plancher, traitement — contrat C9

Cette section est la règle complète du contrat C9, dont cette spec est propriétaire ; `60`, `90`, `100` et `10` la consomment.

### 5.1 Format 16:9 et espace du master [J1]

D5 du 23/09. `App\Support\Frames\FrameGeometry` [nouveau], classe `final` de **constantes de format**, jamais surchargeables : changer l'une d'elles impose de recadrer toute la banque.

```php
final class FrameGeometry
{
    public const int ASPECT_WIDTH = 16;
    public const int ASPECT_HEIGHT = 9;
    public const int GAME_WIDTH = 1280;
    public const int GAME_HEIGHT = 720;
    public const int MASTER_WIDTH = 1920;          // largeur EXACTE du master
    public const int GAME_MAX_BYTES = 153_600;     // 150 Ko, plafond de 10 § 4.1
    public const int GAME_PAD_BYTES = 8_192;       // quantum de padding de 10 § 4.1

    public static function gameEncodeCeilingBytes(): int;                      // intdiv(GAME_MAX_BYTES, GAME_PAD_BYTES) * GAME_PAD_BYTES
    public static function paddedLength(int $encodedBytes): int;               // multiple de GAME_PAD_BYTES supérieur ou égal
    public static function masterHeightFor(int $sourceWidth, int $sourceHeight): int; // intdiv($sourceHeight * MASTER_WIDTH + intdiv($sourceWidth, 2), $sourceWidth)
    public static function maxCropWidth(int $masterHeight, PlatformLimits $limits): int; // min(plancher D6 du 23/09 en largeur, largeur du plus grand 16:9 dont la hauteur tient dans pct % de $masterHeight), multiple de 16 (§ 5.2)
    public static function defaultCrop(int $masterHeight, PlatformLimits $limits): CropRect; // plus grand cadre admis, centré
    public static function violation(CropRect $crop, int $masterHeight, PlatformLimits $limits): ?CropViolation;
}
```

- `App\Support\Frames\CropRect` [nouveau, `final readonly`] : `int $x, $y, $width, $height` ; `fromFrame(Frame)`, `fromArray(array)`, `toArray()`. `App\Support\Frames\CropViolation` [nouveau, enum string, pas un cast de schéma] : `aspect`, `too_wide`, `too_narrow`, `out_of_bounds`.
- **Ratio exact** : `crop_width % 16 = 0` et `crop_height × 16 = crop_width × 9`. Le dérivé mesure **exactement** 1280×720, sur-échantillonné si le cadre est plus étroit : des dimensions naturelles fixes suppriment l'empreinte par dimensions, quatrième surface d'anti-corrélation à côté de l'URL, de la taille servie et des en-têtes (C8 § 4.7). Le résidu « hachage des octets » est assumé (D16 du 23/09) et nommé par `60`.
- **Espace du master** : WebP de largeur **exactement** `MASTER_WIDTH`, hauteur `masterHeightFor()` ≤ 1920 ; source en paysage et large d'au moins `GAME_WIDTH`, sur-échantillonnée si elle est plus étroite que 1920. Le rectangle `crop_*` est exprimé dans cet espace et doit y tenir (E10-18). C'est cette largeur fixe qui rend le plancher **vérifiable par requête SQL, sans colonne neuve**.
- **Miroirs non autoritaires** : `resources/js/lib/frame-geometry.ts` [nouveau, inscrit à `WATCHED` par L90-1 avant sa création, R-36] exporte `FRAME_GEOMETRY = { aspectWidth: 16, aspectHeight: 9, gameWidth: 1280, gameHeight: 720, masterWidth: 1920 } as const` et `maxCropWidth`, `defaultCrop`, `cropViolation` ; le jeton `--aspect-frame: 16 / 9;` rejoint le `@theme` de `resources/css/app.css` et produit l'utilitaire `aspect-frame` ; la prop partagée `frameFormat` (C16 § 2.5, créée par L90-6a) lit `GAME_WIDTH`/`GAME_HEIGHT`. `FrameStoragePrefix::maxPixels()` et `maxKilobytes()` [existants] **délèguent** à `FrameGeometry`. Un test vérifie que les quatre sources disent la même chose ; son assertion sur `frameFormat` est ajoutée par L90-6a (§ Lots, L20-4).
- Le « 40 % de hauteur » du principe 5 se mesure clavier ouvert (D5 du 23/09) ; il appartient à `90`.

### 5.2 Plancher de recadrage [J1]

D6 du 23/09 : un backdrop brut redimensionné ne doit **jamais** être publiable (principe 3 ; principe 12, engagement 3 « recadrage systématique »), et l'engagement doit être **vérifiable**, pas déclaratif. La grille v1 n'est pas rouverte (§ 7.1).

- `PlatformLimits` [modifiée, famille « curation », C0 et R-07] : `frameCropMaxWidthPercent(): int` (défaut `DEFAULT_FRAME_CROP_MAX_WIDTH_PERCENT = 80`, bornes [70, 90]) et `frameCropMinWidthPx(): int` (défaut `DEFAULT_FRAME_CROP_MIN_WIDTH_PX = 640`, multiple de 16 dans [320, 1280]), clés `game.platform.frame_crop_max_width_percent` et `game.platform.frame_crop_min_width_px`, garde de bornes à chaque accesseur (`InvalidArgumentException` hors bornes). **Pas de clé dans `toArray()`** : c'est une prop joueur possédée par `50` ; le back-office compose sa propre prop `limits: AdminFrameLimits` = `{ frameCropMaxWidthPercent, frameCropMinWidthPx, frameUploadMaxKilobytes }` depuis les accesseurs (R-07). Les deux accesseurs, leurs constantes, leur garde de bornes et leurs clés de `config/game.php` sont livrés par L50-1 (`50` § 2.3, C0, R-07) ; `20` n'en déclare que les valeurs, et n'écrit jamais `PlatformLimits`.
- **Condition** : `crop_width × 100 ≤ frameCropMaxWidthPercent × 1920` **et** `crop_height × 100 ≤ frameCropMaxWidthPercent × masterHeight` **et** `crop_width ≥ frameCropMinWidthPx`. La **double borne** plafonne la surface couverte à `pct² ÷ 100` % du master — 64 % à 80 % au réglage par défaut, dans la fourchette 60-70 % de D6 du 23/09 — **quel que soit le ratio de la source**. Pourquoi deux bornes : la largeur seule ne garantit la surface que sur un master 16:9 ; sur un master plus large, la part couverte vaut `0,36 × r` (`r` = largeur ÷ hauteur) jusqu'à `r ≈ 2,22`, où elle atteint 80 %, puis ne décroît que lentement — 72 % sur un 2:1, encore 74 % sur un 2,39:1 de film en scope —, donc un master plus large retire **moins**, pas davantage (au réglage par défaut). Violation de hauteur : `too_wide` (le cadre dépasse la fraction admise, `admin.validation.crop.too_wide`). Si `maxCropWidth() < frameCropMinWidthPx`, la source est refusée (`source_aspect`).
- **Vérifié trois fois** : navigateur (miroir), FormRequest et contrôleur, puis job, sur le master réel. Le FormRequest ne connaît que la largeur ; la borne de hauteur est vérifiée par le contrôleur, sur la hauteur de master tirée des métadonnées TMDB (`masterHeightFor()`, § 5.3), puis par le job sur le master réel (§ 5.5, étape 4).
- **Calibrage au lot pilote** : la valeur se fixe **avant** la curation de masse, **au plus à 83** pour que `pct² ÷ 100` reste sous 70 % (D6 du 23/09). Les bornes de l'accesseur restent celles du contrat, [70, 90] (C0, C9) : au-delà de 83, la surface sortirait de la fourchette de D6 du 23/09, et cet écart des bornes au contrat est signalé au porteur (EN20-4) plutôt que corrigé en silence. Durcir le plancher après coup rend non conformes des images déjà publiées : la requête d'audit (§ 5.9) les liste, et elles se recadrent une à une ; l'assouplir est sans effet sur l'existant.
- **Sans effet sur le J1 courant** : un backdrop TMDB 16:9 donne le même cadre maximal qu'avec la seule borne de largeur (1536 × 864 au défaut).

### 5.3 Voie TMDB : la requête d'ajout [J1]

`POST admin/catalog/{movie}/frames/tmdb` → `admin.catalog.frames.tmdb.store`, `App\Http\Controllers\Admin\FrameTmdbController@store`, `App\Http\Requests\Admin\FrameTmdbStoreRequest`, `throttle:admin-frame`. Formulaire Inertia **sans fichier** : le navigateur n'envoie que la référence et le rectangle (voie TMDB conforme à n° 0 ; le serveur télécharge l'original).

| Champ | Type | Règle |
|---|---|---|
| `tmdb_file_path` | string ≤ 255 | `^/[A-Za-z0-9_-]+\.(jpg\|png)$` ; appartenance aux **backdrops** de `TmdbClient::images($movie->tmdb_id)` vérifiée **dans le contrôleur** (un FormRequest ne peut pas appeler `TmdbClient`) |
| `frame_level` | int | 1-5 (`FrameLevel`) |
| `crop_x`, `crop_y` | int ≥ 0 | — |
| `crop_width` | int | multiple de 16, dans [`frameCropMinWidthPx`, `floor(pct × 1920 / 100 / 16) × 16`] |
| `crop_height` | int | `= crop_width × 9 / 16` exactement |
| `crop_seconds` | int ≥ 0, facultatif | plafonné côté serveur à `catalog.curation.crop_seconds_max` |

Séquence : le contrôleur **refuse avant tout téléchargement** un visuel de moins de `GAME_WIDTH` de large ou en portrait (`admin.validation.frame_source.dimensions`, dimensions lues sur le `TmdbImage`), qui échouerait de toute façon au job en `source_too_small` ou `source_aspect` après avoir déposé des octets inutiles ; il calcule `masterHeight = FrameGeometry::masterHeightFor(width, height)` et vérifie que le cadre tient et respecte la double borne du § 5.2 (`FrameGeometry::violation`, `admin.validation.crop.*`) ; il télécharge l'original par `TmdbClient::downloadImage(string $filePath): string` [ajout] (octets `original`, taille plafonnée par `catalog.curation.tmdb_original_max_kilobytes`, échec → `admin.frame.tmdb.download_failed` ou `too_large`, **aucune frame créée**) ; il appelle `App\Actions\Curation\AddFrame::fromTmdb(Movie $movie, User $curator, FrameLevel $level, CropRect $crop, string $tmdbFilePath, string $originalBytes, ?int $cropSeconds): Frame`. **Dédoublonnage dans le film** (`10` § 4.1 : « le dédoublonnage se fait dans le film via le préfixe `movie_id` ») : `AddFrame` prend un `lockForUpdate` sur le film et refuse (`admin.frame.tmdb.duplicate`, aucune frame créée) s'il existe une frame du même film, ni `withdrawn` ni écartée (§ 8.4), portant le même `source_hash` **et** le même rectangle `crop_*`. Un double envoi du formulaire ne crée donc jamais deux variantes, qui compteraient double et masqueraient le badge « variante unique » (§ 6.6) ; une même source recadrée autrement reste permise, et la grille du § 6.2 marque ce visuel « déjà utilisé (niveau k) ». La frame naît `draft` et `pending`, `source_kind = tmdb`, `uploaded_by_id`, `source_hash` = SHA-256 des octets originaux téléchargés **par le serveur** (E10-17) ; les octets sont déposés **provisoirement** sous `master_path` — seul fichier écrit dans la requête — et le job `ProcessFrameImage` part `->afterCommit()`. Retour `back()` avec le flash `admin.frame.flash.queued`, **sans aucun chemin ni empreinte** dans la réponse. Aucun traitement Imagick n'a lieu dans une requête HTTP.

Les actions et le job ne reçoivent que des primitives et des octets, jamais un DTO `App\Support\Tmdb` : `TmdbBoundaryTest` reste inchangé (non-amendement explicite de C9).

### 5.4 Voie capture : spécifiée, désactivée [route J1 ; voie J2 conditionnelle]

Sans arbitrage de la licéité de l'acte de capture (décision 7, `00` § Ouverture point 9), **seule la voie TMDB est ouverte au J1** ; la capture « ne coûte qu'un drapeau ».

- `catalog.curation.capture_enabled` ← `env('CURATION_CAPTURE_ENABLED', false)`, variable **vide** dans `.env.example`. Faux : aucun bouton de téléversement ni de collage, une note `admin.frame.capture.disabled_notice` explique l'attente ; `POST admin/catalog/{movie}/frames/capture` (`admin.catalog.frames.capture.store`, `FrameCaptureController@store`, `throttle:admin-frame`, C9 § 2) répond **403** avec `admin.frame.capture.disabled`, rendu par `FramePolicy::createFromCapture` → `Response::deny('admin.frame.capture.disabled')` avant toute résolution de requête. **Double garde**, testée. Au J1 le contrôleur n'a **que** ce refus : `FrameCaptureStoreRequest`, `AddFrame::fromCapture` et la branche qui accepte un fichier restent **hors J1** (C9 § 1) et arrivent avec le lot L20-33.
- **Branche acceptante (J2, à l'arbitrage)** : multipart, mêmes champs que TMDB plus `source` — WebP selon `finfo`, ≤ `PlatformLimits::frameUploadMaxKilobytes()` Ko (1 536 au défaut, très sous `upload_max_filesize` pour que l'échec soit une erreur traduite et jamais un 419), largeur ∈ [1280, 1920], hauteur ≤ largeur, dimensions lues par `getimagesize` — et `source_timecode` `h:mm:ss` obligatoire (`admin.frame.capture.timecode`), converti en `source_timecode_ms`. Le navigateur fait le travail lourd : décodage `createImageBitmap`, normalisation sur canvas à 1920 px de large, encodage `toBlob` WebP à qualité descendante ; collage depuis le presse-papiers. `AddFrame::fromCapture(Movie, User, FrameLevel, CropRect, UploadedFile $source, int $timecodeMs, ?int $cropSeconds): Frame`, `source_hash` = SHA-256 des octets **reçus**, avant réencodage.
- **Décision du rédacteur, signalée au porteur (R-46)** : le navigateur envoie la source normalisée et le rectangle, **jamais un dérivé de jeu** ; le serveur dérive toujours le jeu du master et du rectangle. Motifs : D6 du 23/09 prime (un dérivé fabriqué par le navigateur ferait de `crop_width` une déclaration invérifiable sur les octets servis) ; une seule chaîne pour TMDB, capture et re-recadrage ; re-recadrage sans renvoi de fichier ; le serveur réencode de toute façon. Si le porteur retient la lettre de n° 0, seule cette branche change.
- **Avant d'ouvrir la voie** : la liste fermée des sources autorisées, fournie par le conseil ; toute attestation stockée par image serait une exigence à `10`. Et A7 tient : **aucun** champ de support, d'édition, d'appareil ni d'outil (§ 7.6).

### 5.5 Le job de traitement [J1]

`App\Jobs\Curation\ProcessFrameImage` [nouveau] : `implements ShouldQueue, ShouldBeUnique`, `__construct(public int $frameId)`, `uniqueId()` = l'identifiant de la frame, `onQueue('default')` — **jamais `game`** —, distribué `->afterCommit()`, une image par job, `$tries = 3`, `$backoff = [30, 120]`. Traitement par `App\Support\Frames\FrameImageProcessor::process(Frame $frame): void`, qui lève `FrameProcessingException` porteuse d'une `FrameProcessingFailure`.

1. `Imagick::setResourceLimit` posé **avant toute lecture**, depuis `catalog.curation.imagick.{memory_mb, map_mb, area_mpx, width_px, height_px, time_s}`.
2. **Refus durs** avant tout fichier : frame `withdrawn` → échec `withdrawn`, rien n'est écrit (`10` § 10) ; frame `published` → échec `published` : un job ne réécrit jamais une frame en jeu (n° 16).
3. **Normalisation du master**, sautée si le master est déjà un WebP de 1920 de large (pas de perte de génération au re-recadrage) : format lu par `finfo` (`source_format`), image statique (`getNumberImages() > 1` → `source_animated`), largeur ≥ 1280 (`source_too_small`), paysage et `maxCropWidth ≥ frameCropMinWidthPx` (`source_aspect`) ; `stripImage()` (EXIF, XMP, ICC), sRGB, redimensionnement à 1920 de large, WebP à `catalog.curation.webp.master_quality`, écrit **à la place** des octets provisoires sous le même `master_path`. Aucun original n'est conservé au-delà.
4. **Revalidation du rectangle** sur le master réel (`FrameGeometry::violation`, `crop_invalid`), découpe, redimensionnement à 1280×720 exactement, `stripImage()`, encodage WebP de `webp.game_quality_start` à `webp.game_quality_min` par pas de `webp.game_quality_step` jusqu'à passer sous `gameEncodeCeilingBytes()` (sinon `too_heavy`), puis `App\Support\Frames\WebpPadding::pad(string $webp): string` — octets NUL **après** le bloc RIFF, champ de taille RIFF intact, refus d'un en-tête qui n'est pas `RIFF…WEBP` — jusqu'au multiple de 8 192.
5. Écriture sous un **nouveau** nom `FrameStoragePrefix::Game->newPath()`. Bascule dans **une** transaction, `lockForUpdate` : si la frame est devenue `withdrawn` ou `published` entre-temps, abandon et suppression du fichier neuf ; sinon `game_path`, `game_bytes` (longueur paddée), `game_width = 1280`, `game_height = 720`, `published_hash = sha256(octets paddés)`, `processing_state = ready`, `processing_error = null`, et `MovieProjector::recompute` dans la même transaction. L'ancien fichier `game/` est supprimé **après** le commit.

**Frame `ready`** ⟺ WebP statique sRGB dépouillé, 1280×720, `game_bytes % 8192 = 0`, `game_bytes ≤ gameEncodeCeilingBytes()` (donc ≤ 153 600), `published_hash` = empreinte des octets servis. **Aucun LQIP** n'est produit et il n'existe aucun troisième préfixe (D7 du 23/09, E10-60).

### 5.6 Échecs et relance [J1]

`App\Enums\FrameProcessingFailure` [nouveau], cast de `frame.processing_error` (`string(120)` existante, E10-10) ; **la valeur de chaque cas est la clé de traduction complète** ; méthode `isRetryable(): bool`.

| Cas | Valeur | Rejouable |
|---|---|---|
| `SourceMissing` | `admin.frame.processing_error.source_missing` | non |
| `SourceUnreadable` | `admin.frame.processing_error.source_unreadable` | non |
| `SourceFormat` | `admin.frame.processing_error.source_format` | non |
| `SourceAnimated` | `admin.frame.processing_error.source_animated` | non |
| `SourceTooSmall` | `admin.frame.processing_error.source_too_small` | non |
| `SourceAspect` | `admin.frame.processing_error.source_aspect` | non |
| `CropInvalid` | `admin.frame.processing_error.crop_invalid` | non |
| `TooHeavy` | `admin.frame.processing_error.too_heavy` | non |
| `ResourceLimit` | `admin.frame.processing_error.resource_limit` | oui |
| `Withdrawn` | `admin.frame.processing_error.withdrawn` | non |
| `Published` | `admin.frame.processing_error.published` | non |
| `Unexpected` | `admin.frame.processing_error.unexpected` | oui |

Un échec pose `processing_state = failed` et une clé de l'enum, **jamais un message brut** : le détail technique va au journal applicatif. Les exceptions transitoires sont rejouées par la file puis finissent en `unexpected`. **« Relancer »** (`POST admin/catalog/{movie}/frames/{frame}/retry` → `admin.catalog.frames.retry`, `FrameRetryController@store`, `can:update,frame`, `throttle:admin-frame` (C9 § 2), `App\Actions\Curation\RetryFrameProcessing::handle(Frame, User): void`) n'est offert que si `isRetryable()` ; sinon `admin.frame.retry.not_retryable`, et l'écran propose re-recadrer ou écarter ; « Relancer » oppose aussi `admin.frame.recrop.locked` à une frame `suspended` ou `withdrawn` (§ 2.9). **Octets provisoires** : un échec **non rejouable** au **premier** traitement — `master_path` porte encore les octets originaux non dépouillés du § 5.3 — les supprime du disque **après** le commit de l'état `failed`, hors `withdrawn` dont les fichiers relèvent du § 11.3 ; `master_path` reste posé (colonne UNIQUE, jamais réaffectée), `game_path` reste NULL, donc « Re-recadrer » répond `not_ready` et l'image s'écarte (§ 8.4). Raison : « aucun original n'est conservé » (C9 invariant 5) ne doit pas dépendre du succès du job. Un échec rejouable garde les octets, que « Relancer » réutilise ; un échec sur un re-recadrage garde le master déjà normalisé. Code à aligner : `Frame::casts()` gagne `'processing_error' => FrameProcessingFailure::class`, `@property FrameProcessingFailure|null`, `AdminCatalogPresenter::movieFrame()` rend `->value`, `FrameFactory::processingFailed(FrameProcessingFailure $failure = FrameProcessingFailure::Unexpected)` remplace l'état actuel, dont la valeur est hors enum et dans le domaine interdit `curation`.

### 5.7 Re-recadrage et changement de niveau [J1]

- **Re-recadrer** (`PATCH admin/catalog/{movie}/frames/{frame}/crop` → `admin.catalog.frames.crop.update`, `FrameCropController@update`, `can:update,frame`, `throttle:admin-frame` — même limiteur que l'ajout, C9 § 2 : chaque re-recadrage distribue un job Imagick —, `FrameCropUpdateRequest` : `crop_*`, `crop_seconds`, `reason` ≤ 500 facultatif pré-rempli par `admin.frame.recrop.default_reason`) : **en place**, jamais une nouvelle ligne (n° 16, E10-19). Refus traduits : `admin.frame.recrop.not_ready` si `game_path` est NULL, `busy` si la frame est `pending`, `locked` si elle est `suspended` ou `withdrawn`. `App\Actions\Curation\RecropFrame::handle(Frame $frame, User $curator, CropRect $crop, ?string $reason, ?int $cropSeconds): void` : dans **une** transaction, si la frame est `published`, passage `unpublished` et ligne `frame.unpublished` ; `crop_*` réécrit, `processing_state = pending`, `MovieProjector::recompute` si la frame sortait du comptant ; job après commit. `crop_seconds` n'est écrit que s'il est encore NULL **et** que le film est encore en passe 1 (`availability = draft` et `first_published_at` NULL, même condition que le battement) : la mesure garde le **premier** recadrage et ne bouge plus après la terminaison (§ 10.1, § 10.3). La frame ne revient en jeu qu'après une revue sur ses **nouveaux** octets (§ 7.5). Une manche en cours bascule en substitution à la frappe suivante (chemin paresseux, C8 § 4.6).
- **Changer le niveau** (`PATCH admin/catalog/{movie}/frames/{frame}/level` → `admin.catalog.frames.level.update`, `FrameLevelController@update`, `FrameLevelUpdateRequest`, `App\Actions\Curation\ChangeFrameLevel`, `can:update,frame`, `throttle:admin-curation`) : refus traduit `admin.frame.level.locked` si la frame est `suspended` ou `withdrawn` (§ 2.9) ; sur une frame non publiée, écriture de `frame_level` et recalcul synchrone de la projection. **Sur une frame publiée, la frame sort de `published`** dans la même transaction (ligne `frame.unpublished` dont le motif est le texte de `admin.frame.level.default_reason`, § 2.7 ; `MovieProjector::recompute`, `10` § 3.2 règle 2) et repasse en revue (§ 7.3). Raison : la ligne `frame_review` ne porte pas le niveau et les items applicables en dépendent (`no_lead_face` aux niveaux 1 et 2) ; plutôt que d'inférer la validité d'une preuve, on la rejoue — une touche `Entrée` en revue (C9 § 8, libre).
- **Avertissement de couverture, pour les deux gestes** (n° 4) : sur une frame publiée, la confirmation reprend l'avertissement du § 8.4 (`admin.frame.unpublish.coverage_warning`, « Ce film deviendra incomplet : il restera jouable jusqu'à N = k, avec repli de niveau ») **avant tout envoi**, quand le geste casse la couverture 1/3/5 d'un film publié. Le geste reste permis et le film reste publié (E10-23).

### 5.8 Aperçu admin — contrat C9-bis [J1]

| Méthode | URI | Nom | Action | Garde |
|---|---|---|---|---|
| GET | `admin/catalog/{movie}/frames/{frame}/game` | `admin.catalog.frames.game` | `App\Http\Controllers\Admin\FrameImageController@game` | `can:view,frame` |
| GET | `admin/catalog/{movie}/frames/{frame}/master` | `admin.catalog.frames.master` | `…@master` | `can:view,frame` |

- Corps construit par `App\Support\Frames\FrameImageResponse::make(FrameStoragePrefix $prefix, string $relativePath): Response` [nouveau], **constructeur unique** des réponses d'image, partagé avec `GET /f/{serveToken}` de `60` (R-31). 200 : flux des octets, `Content-Type: image/webp`, `Content-Length`, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow`, `X-Content-Type-Options: nosniff`, `Cross-Origin-Resource-Policy: same-origin` ; **jamais** `Content-Disposition`, `Last-Modified`, `ETag`, `Accept-Ranges`, `Expires` ; jamais `Storage::response()` ni `BinaryFileResponse` (`10` § 10, E10-59). 404 corps vide, mêmes `Cache-Control` et `X-Robots-Tag`, si `! $prefix->owns($relativePath)` ou si le fichier manque — un fichier manquant est journalisé en avertissement, sans pseudo ni IP (canal de `100`). Sur l'aperçu, les cookies viennent de la pile `web` ; `FrameImageResponse` n'en pose jamais.
- `game` et `master` répondent 404 tant que `game_path` est NULL. Une frame d'un autre film répond 404 (`scopeBindings`), une frame `withdrawn` aussi (`FramePolicy::view`).
- **Seul second lecteur du disque `frames`** avec `/f/` (E10-61) ; `master/` n'est lisible que par `admin.catalog.frames.master` ; aucune URL joueur n'atteint `master/`. L'aperçu `game` sert **les mêmes octets** que ceux servis aux joueurs : c'est ce que la revue hache.
- **Distinct du service de jeu** (C8) : ces routes ne sont **jamais** adressées par un `serve_token`, ne partagent jamais l'espace `/f/`, et ne connaissent ni manche ni garde temporelle — leur seule garde est le rôle. Rien de ce qu'elles servent, ni identifiant, ni chemin, ni titre, n'atteint une surface joueur (règle 3, `10` § 1.1).
- **Props d'une frame** (`AdminMovieFrame`, `resources/js/types/admin.ts`, étendu) :

```ts
{
  id: number,                       // admin seulement (E10-11)
  frame_level: FrameLevel,
  availability: ContentAvailability,
  processing_state: 'pending' | 'ready' | 'failed',
  processing_error: string | null,  // valeur de FrameProcessingFailure (une clé)
  is_retryable: boolean,
  source_kind: 'tmdb' | 'capture',
  crop: { x: number, y: number, width: number, height: number },
  game_url: string | null,          // route admin.catalog.frames.game, null si game_path est NULL
  master_url: string | null,        // route admin.catalog.frames.master, null si game_path est NULL
  review_outdated: boolean,
}
```

  **Aucun chemin disque**, ni `source_hash`, ni `tmdb_file_path` hors de l'écran de revue ; `published_hash` n'est ajouté **qu'aux** props de revue (§ 7.4). Texte alternatif : `admin.frame.preview.alt` (`:level`).

### 5.9 Sondes [J1]

Écrites en tests (§ Lots) et exécutées en production par `100` : `COUNT(frame WHERE availability = 'published' AND published_review_id IS NULL) = 0` ; toute frame `published` a `published_hash` égal au `reviewed_hash` de sa revue désignée ; aucune frame `ready` hors 1280×720 ou hors padding ; **requête d'audit du plancher**, `COUNT(frame WHERE crop_width * 100 > :pct * 1920 OR crop_width < :min OR crop_width % 16 <> 0 OR crop_height * 16 <> crop_width * 9) = 0` avec les valeurs courantes de `PlatformLimits` (E10-18, E10-19). C'est cette requête qui rend l'engagement « image transformée » démontrable (A-36). Elle ne vérifie que la **largeur** : la hauteur du master n'est pas en base, et aucune colonne n'est demandée pour elle ; la borne de hauteur du § 5.2 est garantie par le contrôleur et revalidée par le job sur le master réel, et un audit complet relirait les masters sur le disque.

---

## 6. Éditeur de la banque d'images et recadreur

### 6.1 L'écran [J1]

Page `admin/catalog/bank`, `GET admin/catalog/{movie}/bank` → `admin.catalog.bank` (`FrameBankController@show`, `MoviePolicy::curate`). Trois zones et un pied :

- **Visuels TMDB** du film (§ 6.2), en grille de vignettes ;
- **Recadreur** (§ 6.3) sur le visuel choisi, avec le choix du niveau (§ 6.5) et le bouton d'envoi ; dessous, la **bande balayable** des visuels non encore utilisés (§ 6.2, lot L20-11, livré au J1 — amendé le 23/09) ;
- **Banque du film** : ses frames groupées par niveau, avec état (en traitement, en attente de revue, rejetée, en jeu, écartée, en échec), aperçu, re-recadrer, relancer, changer de niveau, écarter ; la couverture (§ 6.6) ; la prévisualisation en conditions de jeu (§ 6.7) ;
- **Pied** : « Publier le film » quand il est publiable (§ 8.1), « Film suivant » (§ 4.1), rappel des raccourcis.

Props : `movie` (identité, disponibilité, drapeau, couverture), `frames: AdminMovieFrame[]`, `limits: AdminFrameLimits`, `captureEnabled: boolean`, `sequencePreview` (§ 6.7), `abilities`, `unpublish_preview` en prop **optionnelle** servie au rechargement partiel (§ 8.4), et **`backdrops` en prop différée** (`Inertia::defer`) pour que la page s'affiche sans attendre TMDB ; chaque visuel (`AdminBackdrop`) porte `used_levels: FrameLevel[]`, les niveaux des frames du film, ni `withdrawn` ni écartées, qui en proviennent — calculés côté serveur, sans jamais exposer `frame.tmdb_file_path` dans `AdminMovieFrame` (C9 § 3). Tant qu'une frame du film est `pending`, la page se recharge partiellement (`usePoll`, `only: ['frames', 'movie', 'sequencePreview']`) toutes les `catalog.curation.poll_seconds` secondes : **le curateur n'attend jamais le job**, il continue sur le visuel suivant.

Le recadreur et la revue exigent une largeur de bureau : sous le point d'arrêt `lg`, la zone affiche `admin.bank.desktop_required` et le reste de la page demeure utilisable (liste, états, publication). Le mobile d'abord (principe 5) vise l'écran de jeu, pas l'outil de travail d'un curateur.

### 6.2 Visuels TMDB proposés [J1]

- Liste obtenue par `TmdbClient::images($movie->tmdb_id)` **dans `FrameBankController`**, mise en cache `catalog.curation.images_cache_minutes` par identifiant TMDB (le même ensemble sert à la vérification d'appartenance du § 5.3 ; une liste un peu ancienne est inoffensive, un chemin de fichier TMDB restant valide). **Backdrops seuls** : affiches et logos ne sont jamais candidats, la grille les exclut (`TmdbImageSet`).
- **Ordre** : visuels sans texte d'abord (`TmdbImage::isLanguageNeutral()`), ordre TMDB conservé à l'intérieur. Un visuel de moins de `GAME_WIDTH` de large ou en portrait reste affiché, désactivé, avec son motif (`admin.validation.frame_source.dimensions`).
- **Chargement direct depuis le serveur d'images de TMDB dans le navigateur du curateur**, URL produites par `TmdbClient::imageUrl()` (vignette `w300`, affichage `w1280`). « Aucun hotlink d'image » (`00` § tiers) vise les **surfaces joueur** : aucun écran de jeu ni aucun aperçu servi à un joueur ne pointe jamais vers TMDB, ce que garantit le confinement de `TmdbClient` (n° 9). Conséquence à écrire dans la page de confidentialité par `90` : l'IP d'un curateur, utilisateur nominatif, part chez TMDB. La voie TMDB n'utilise aucun canevas : l'attribut `crossOrigin` n'a d'objet que pour la capture (`CLAUDE.md` §8).
- Film sans `tmdb_id` (démonstration) ou TMDB muet : état vide `admin.bank.no_backdrops` ; TMDB en panne : `admin.bank.backdrops_failed` et « Réessayer » (rechargement partiel de `backdrops`) ; quota atteint sur cet appel interactif : `admin.tmdb.error.rate_limited_interactive` et « Réessayer » (§ 3.6).
- **Vignettes** : texte alternatif `admin.bank.backdrop_alt` (paramètres `:index`, `:count`, « Visuel :index sur :count ») ; un visuel déjà utilisé porte le badge textuel `admin.bank.backdrop_used` (paramètre `:levels`), jamais la seule couleur.
- **Bande balayable** [J1, lot L20-11] : sous le recadreur, la rangée horizontale des visuels **non encore utilisés** du film (`used_levels` vide), dans l'ordre de la grille. `[` et `]`, un glissement ou ses boutons « précédent » / « suivant » passent au visuel voisin **sans quitter le cadre** ; après chaque envoi, le visuel suivant de la bande s'ouvre dans le cadre. Le choix est calculé par une fonction pure (`resources/js/lib/admin/backdrop-strip.ts`), testée sans DOM (C18 § 2.4) ; clés `admin.bank.strip.*`. Livrée au J1 (D35 du 23/09 : plus aucune coupe) ; la grille reste l'autre voie d'accès aux mêmes visuels, au clavier comme à la souris — amendé le 23/09.

### 6.3 Le recadreur : interactions et rectangle par défaut [J1]

Composant `resources/js/components/admin/frame-cropper.tsx` [nouveau]. Le visuel s'affiche à sa taille `w1280`, texte alternatif `admin.cropper.image_alt` ; le rectangle est tenu **dans l'espace du master** (1920 de large, hauteur `masterHeightFor(width, height)` des métadonnées TMDB) et converti à l'affichage.

- **Rectangle par défaut** : `defaultCrop(masterHeight, limits)`, le plus grand cadre admis, centré (miroir de `FrameGeometry::defaultCrop`).
- **Socle, jamais coupé** (lot L20-9a) : **déplacer** en glissant l'intérieur ; **agrandir ou resserrer** par les boutons « Plus large », « Plus serré », « Centrer », « Cadre par défaut » et par le clavier (§ 6.4). Jamais au-delà du plancher : la borne haute est `maxCropWidth()`, la borne basse `frameCropMinWidthPx`.
- **Gestes de pointeur avancés** (lot L20-9b, livré au J1, D35 du 23/09) : poignées d'angle (ratio verrouillé, largeur arrondie au multiple de 16), molette et pincement. Ils n'ajoutent aucune capacité que les boutons et le clavier n'offrent déjà : l'alternative non gestuelle du principe 8 est complète sans eux, et c'est pourquoi ils restent hors du socle — amendé le 23/09.
- **Retour immédiat** : dimensions dans le master, part de la surface, violation éventuelle (`cropViolation`) nommée par `admin.validation.crop.*` ; envoi désactivé tant qu'une violation existe. Le serveur revalide tout (§ 5.2).
- **Temps de recadrage** : de l'ouverture du visuel dans le cadre à l'envoi, en secondes entières, posté en `crop_seconds` (§ 10.1).
- Après l'envoi, la frame apparaît dans la banque « en traitement » et le cadre passe au visuel suivant de la bande balayable (§ 6.2) — amendé le 23/09.

### 6.4 Clavier : l'opérabilité dans la barre, les raccourcis de débit hors de la barre [J1]

Distinction imposée par n° 2 et le principe 8 : l'**opérabilité** fait partie de la barre « terminé » ; les **raccourcis de débit** n'en font pas partie. Ils formaient la coupe préparée, sans objet depuis D35 du 23/09 : ils sont livrés au J1 (L20-11) — amendé le 23/09.

**Opérabilité (barre, jamais coupée)** :

- Ordre de tabulation : grille des visuels → cadre → niveau → envoi → banque → publication. Focus toujours visible (jeton `ring`).
- Grille des visuels : un seul arrêt de tabulation, flèches pour se déplacer (tabindex itinérant), `Entrée` ou `Espace` pour ouvrir le visuel dans le cadre.
- Cadre focalisé (`role="group"`, `aria-label` = `admin.cropper.region_label`, `aria-describedby` vers la description vivante des dimensions et de la part de surface) : flèches = déplacer d'un pas du cadre (`FrameGeometry::ASPECT_WIDTH`, 16 pixels du master), `Maj` + flèches = quatre pas ; `+` / `-` = élargir ou resserrer d'un pas de largeur, `Maj` = quatre pas ; `Origine` = cadre par défaut. Une violation est annoncée par une région `aria-live="polite"` propre au recadreur.
- Niveau : groupe de boutons radio standard (`radio-group` installé au J1 par L90-1, C16 § 2.9), flèches pour changer.
- Chaque action de la souris a son équivalent bouton : l'alternative non gestuelle du principe 8 est complète sans aucun raccourci.

**Raccourcis de débit (lot L20-11, livré au J1 — amendé le 23/09)** :

- Cadre focalisé : `1` à `5` = **classer et envoyer** en un geste (le niveau choisi est annoncé).
- `[` et `]` = visuel précédent et suivant sans quitter le cadre.
- Passe de revue : `Entrée` = « conforme, publier » (§ 7.4). **« Entrée publie » dans le recadreur est impossible** : la publication d'une image exige une revue sur le rendu final, produit par un job que le curateur n'attend pas (n° 2, A-24).
- Aucun raccourci n'agit dans un champ de saisie ; tous sont décrits sur la page « premiers pas » (§ 13.6). La correspondance (touche, cible, contexte) → action est une **fonction pure** (`resources/js/lib/admin/shortcut-map.ts`), testée par Vitest sans environnement DOM (C18 § 2.4) ; le hook ne fait que la brancher sur les événements.

### 6.5 Classement sur l'échelle 1-5 : le guide [J1]

`frame_level` est une propriété **stable** fixée en curation (`00` § Vocabulaire). Le niveau est **relatif au film**, jamais au catalogue : la difficulté d'un film est `movie_difficulty`, que `30` dérive. Guide normatif, rendu à côté du sélecteur (`admin.level.{1..5}.label` et `.guide`) et illustré sur la page « premiers pas », recalibré après le lot pilote :

| Niveau | Libellé | Ce que l'image montre |
|---|---|---|
| 1 | Très cryptique | Un détail, une texture, une matière, un second plan : rien qui se nomme sans avoir vu le film de près. **Aucun visage du personnage principal** (item `no_lead_face`). |
| 2 | Cryptique | Un fragment de décor ou d'accessoire caractéristique, une silhouette, un personnage secondaire. **Aucun visage du personnage principal.** |
| 3 | Intermédiaire | Un décor ou une situation que reconnaît qui a vu le film ; le personnage principal peut apparaître de dos, de loin ou en partie. |
| 4 | Lisible | Une scène marquante ; le personnage principal est visible, mais pas dans le plan le plus célèbre du film. |
| 5 | Évident | Le plan iconique, le personnage principal — l'image que tout le monde associe au film, sans jamais être son affiche. |

Le niveau se choisit **avant** l'envoi (aucun défaut pré-coché : un défaut serait un classement non décidé) ; il se change ensuite par le § 5.7.

### 6.6 Couverture, passes, variantes uniques, film incomplet [J1]

- **Indicateur par niveau** : variantes **jouables** (`movie_projection.level_{i}_variants`, prédicat unique de `10` § 3.2), plus les frames en traitement, en attente de revue, rejetées et en échec, lues sur `frame`.
- **Passe 1** (niveaux 1, 3, 5, une variante chacun) : suffit à publier (décision 10). **Passe 2** : deuxièmes variantes et niveaux 2 et 4, cible de 8 images par film (2 aux niveaux 1/3/5, 1 aux niveaux 2/4) — **objectif de curation, jamais condition de publication** (`00` § Catalogue). Le bandeau dit laquelle est en cours.
- **Variante unique** : badge sur un niveau 1, 3 ou 5 à `level_{i}_variants = 1`, en back-office seulement, jamais aux joueurs.
- **Film incomplet** (E10-23, n° 4) : un film `published` dont `levels_mask & MovieProjection::publishableLevelsMask()` diffère de `MovieProjection::publishableLevelsMask()` [existant, seule source du masque 1/3/5 ; aucun littéral 21 dans `app/`] **reste `published`**, jouable pour N ≤ `levels_count` avec repli de niveau (`FrameLevelCoverage::usesFallback()`, C1) ; le back-office l'affiche « incomplet », le compte au tableau de bord, et ne le dépublie jamais seul.

### 6.7 Prévisualisation en conditions de jeu [J1]

D8 du 23/09, A-23. Composant `resources/js/components/admin/game-conditions-preview.tsx` [nouveau] : `GameFrame` (C16 § 2.5) enveloppé dans `GameThemeScope` (C16 § 2.2), `src = game_url`, `alt = t('admin.frame.preview.alt', { level })`, libellés de chargement et d'indisponibilité en clés `admin.frame.preview.*`, `format` = prop partagée `frameFormat`. Deux largeurs : mobile, celle du viewport minimal déclaré par `90` (C16 § 2.3), simulée par une classe d'échelle (`w-90`, 22,5 rem — jamais une valeur en `px` dans un fichier surveillé), et bureau. Aucun portail n'est ouvert dans la portée sombre.

**Séquences par N** : pour chaque N de `RoomSettingsBounds::MIN_FRAMES_PER_ROUND` à `MAX_FRAMES_PER_ROUND`, la prop `sequencePreview` donne `{ playable, levels, usesFallback, frames: { level, game_url }[] }` calculé par `FrameLevelCoverage::select(N, masque)` (C1) sur deux masques : « en jeu » (frames servables) et « après revue » (servables plus `ready` en attente de revue). Une variante par niveau, la plus ancienne — le tirage réel, lui, suit la mémoire du salon (`30`). Le curateur voit ainsi ce que verra un salon à chaque N permis par `RoomSettingsBounds`, repli de niveau compris.

### 6.8 États [J1]

Chargement des visuels (`Skeleton` de la grille) ; visuels indisponibles (message et « Réessayer ») ; envoi en cours (bouton inactif, `aria-busy`) ; refus de validation (message sous le champ, `aria-describedby`) ; frame en traitement (indicateur, rechargement partiel) ; frame en échec (clé traduite, « Relancer » si rejouable) ; déconnexion ou erreur réseau d'une visite Inertia (toast `admin.common.offline`, formulaire conservé). Composants existants `admin-loading-state`, `admin-empty-state`, `admin-error-state`.

---

## 7. Grille d'exclusion et passe de revue — contrat C14-bis

### 7.1 Grille v1, item par item [J1]

Contenu fixé par `questions-ouvertes.md` « Grille d'exclusion » et transcrit dans `ExclusionGrid` : **inchangé** (D6 du 23/09 ne rouvre pas la grille). Clés `admin.exclusion_grid.v1.{slug}.label` et `.help` (forme retenue par R-47 : une clé PHP ne peut être à la fois feuille et nœud). Textes normatifs, domaine `admin`, français seul :

| # | Slug | Niveaux | `….label` | `….help` |
|---|---|---|---|---|
| 1 | `no_poster_or_cover` | 1-5 | Ni affiche ni jaquette | L'image est un photogramme du film : ni affiche, ni jaquette, ni visuel de dossier de presse, ni montage promotionnel. Un visuel composé (plusieurs plans, titre stylisé, fond uni) est refusé. |
| 2 | `no_title_card` | 1-5 | Pas de carton-titre | Aucun plan où s'affiche le titre du film, qu'il soit incrusté, peint dans le décor au moment du titre ou porté par un carton. |
| 3 | `no_studio_logo` | 1-5 | Pas de logo de studio | Aucun logo ni ouverture de studio, de distributeur ou de producteur, même partiel ou en arrière-plan. |
| 4 | `no_credits` | 1-5 | Pas de générique | Aucun plan de générique de début ou de fin, aucun nom d'acteur, de réalisateur ou de membre de l'équipe incrusté. |
| 5 | `no_identifying_text` | 1-5 | Aucun texte qui identifie le film | Aucun texte, dans aucune écriture, qui nomme ou identifie le film : titre, sous-titre, nom de saga, accroche, crédits. Un texte de décor sans rapport avec le titre (enseigne, panneau, journal) reste autorisé, sans quoi le corpus japonais et le corpus d'exception deviendraient impubliables. En cas de doute, refuser. |
| 6 | `no_watermark_or_copyright` | 1-5 | Aucun filigrane ni copyright incrusté | Aucun filigrane, aucune mention « © », aucune signature ni marque de site incrustée, même discrète dans un coin. |
| 7 | `no_broadcaster_or_trailer_overlay` | 1-5 | Aucune incrustation de bande-annonce ou de diffuseur | Aucun logo de chaîne ou de plateforme, bandeau, date de sortie, mention « bande-annonce » ou « prochainement », ni élément d'interface de lecteur vidéo. |
| 8 | `no_promotional_still_or_portrait` | 1-5 | Aucune photo de plateau ni portrait promotionnel | Pas de photo de tournage (équipe, matériel, coulisses) ni de portrait posé d'acteur hors du film : le droit à l'image de l'acteur est distinct du droit d'auteur sur l'œuvre. Seul un photogramme du film est admis. |
| 9 | `no_lead_face` | 1-2 | Pas de visage du personnage principal | Aux niveaux 1 et 2 seulement, le visage du personnage principal n'apparaît pas de manière reconnaissable. Règle de cryptage de la manche, pas de conformité : un plan iconique de niveau 5 le montre forcément. |

### 7.2 Versionnement et rétroactivité déclarée [J1 ; geste au J2]

`App\Support\Curation\ExclusionGrid` [existant, structure modifiée — gratuit, aucune revue n'existe] :

```php
private const string LANG_PREFIX = 'admin.exclusion_grid.';   // [renommé] depuis 'curation.exclusion_grid.' (n° 6)
/** @var array<int, array{retroactive: bool, items: list<array{slug: string, levels: list<int>}>}> */
private const array VERSIONS = [1 => ['retroactive' => false, 'items' => [/* 9 items existants, inchangés */]]];
public const int CURRENT_VERSION = 1;

public static function labelKey(string $slug, int $version = self::CURRENT_VERSION): string; // '…v{n}.{slug}.label'
public static function helpKey(string $slug, int $version = self::CURRENT_VERSION): string;  // '…v{n}.{slug}.help'
public static function isRetroactive(int $version): bool;
public static function addedSlugs(int $version): array;                                       // slugs(v) − slugs(v−1)
public static function retroactiveLevels(int $version = self::CURRENT_VERSION): array;        // list<FrameLevel> ; vide si non rétroactive
public static function decisionFor(array $answers, FrameLevel $level, int $version): ReviewDecision;
```

`versions()`, `isKnownVersion()`, `slugs()`, `slugsFor()`, `appliesTo()`, `isOutdated()` et `missingAnswers()` [existants] restent.

1. **Une version publiée est figée** : items, niveaux et drapeau. Toute modification impose une **nouvelle version** avec un nouveau slug ; `CURRENT_VERSION` vaut la plus grande clé. Un test verrouille l'empreinte SHA-256 de `VERSIONS[1]`. Raison : une revue passée cite ses items par slug ; réécrire un libellé déjà cité détruirait sa valeur de preuve (`10` § 4.2).
2. **Drapeau `retroactive`** (D13 du 23/09) : faux par défaut, vrai **seulement** pour une version qui ajoute un item pour **motif juridique**, cité en commentaire de la version avec la référence de demande s'il y en a une.
3. **Montée de version prospective par défaut** : rien ne sort du jeu ; la file « à re-revoir » regroupe les frames `published` dont `review_grid_version < CURRENT_VERSION` (`frame_grid_version_idx`, § 7.7). Une clarification de libellé ne vide pas le vivier et ne rebloque aucun lobby.

### 7.3 File de revue [J1]

Page `admin/review/index`, `GET admin/review` → `admin.review.index` (`FrameReviewQueueController@index`, `can:create,App\Models\FrameReview`), service `App\Support\Curation\ReviewQueue` [nouveau], seul porteur des trois prédicats ci-dessous, que `ReviewFrame` relit sous verrou (§ 7.5). Deux onglets et une liste :

- **À revoir** : frames `ready` ; ni la frame ni son film `suspended` ou `withdrawn` ; frame `draft` ou `unpublished`, **à l'exclusion des images écartées** (`unpublished` et `first_published_at` NULL, § 8.4) ; et **sans aucune revue**, passante ou rejetée, à la version courante sur `published_hash` dont le `reviewed_at` est postérieur ou égal à `COALESCE(frame.availability_changed_at, frame.created_at)`. Ce prédicat couvre, sans colonne neuve : les nouvelles images ; les brouillons recadrés (nouvelle empreinte) ; les images publiées puis recadrées ou **changées de niveau** — leur sortie de `published` est datée par `availability_changed_at`, alors que ni leurs octets ni la version ne changent dans le second cas ; les images **dépubliées**, que seule une revue republie (§ 8.4). Raison : une image sortie du jeu n'y revient que par une revue, donc elle doit apparaître ici ; une image écartée, elle, a été jugée et n'y revient jamais. Une revue passante écrit `reviewed_at` et `availability_changed_at` au **même** instant serveur (§ 7.5), d'où le « ou égal ».
- **À re-revoir** : frames `published` à `review_grid_version < CURRENT_VERSION` (§ 7.7).
- **Rejetées** : frames `ready`, `draft` ou `published`, ni écartées ni `withdrawn`, dont la dernière revue sur (`published_hash`, version courante), postérieure ou égale à `COALESCE(availability_changed_at, created_at)`, est **rejetée**, avec leurs items en défaut. Une revue rejetée fait donc passer une image de « À revoir » à « Rejetées ». Chaque ligne offre « Revoir » (même écran, § 7.4 ; une première revue peut être une erreur), « Re-recadrer » (nouvelle empreinte, retour à « À revoir ») et « Écarter » ou « Dépublier » (§ 8.4).

Ordre : regroupées **par film** (le contexte du film aide à juger `no_identifying_text` et `no_lead_face`), films dans l'ordre de leur plus ancienne image prête (premier prêt, premier revu), images par niveau croissant puis `id`. Compteurs des trois listes sur le tableau de bord.

### 7.4 Écran de revue [J1]

Une image à la fois, rendue **sur le rendu final tel que servi** — jamais sur l'aperçu de recadrage — par `game-conditions-preview` (§ 6.7), aux deux largeurs, sous les tokens sombres du jeu (`questions-ouvertes.md` « Grille d'exclusion », D8 du 23/09). À côté : le film (titre original, année), le niveau, les items **applicables à ce niveau** avec libellé et aide, et la **source déclarée** en lecture seule.

**Props de revue, par frame** (C14-bis § 3) : `published_hash` (`string(64)`, admin seulement), `game_url`, `frame_level`, `grid_version = CURRENT_VERSION`, `items: { slug, label_key, help_key }[]` = `slugsFor(level)`, `declared_source: { kind: 'tmdb' | 'capture', reference: string }` où la référence est `tmdb_file_path` ou le timecode `h:mm:ss`.

**Gestes** : « Conforme, publier » (bouton, et `Entrée` en raccourci de débit) répond `true` à **tous** les items affichés et déclare la source affichée — c'est une déclaration explicite item par item, les items étant sous les yeux du curateur ; « Non conforme » ouvre le choix des items en défaut (cases à cocher), puis « Rejeter ». L'image suivante prend le focus. L'auteur d'une image peut la revoir lui-même (porteur seul au J1) ; l'auteur et le vérificateur **peuvent** être deux personnes, la preuve le dit (`reviewer_name`).

### 7.5 Réponses, décision, publication d'une image [J1]

`POST admin/catalog/{movie}/frames/{frame}/review` → `admin.catalog.frames.review.store` (`App\Http\Controllers\Admin\FrameReviewController@store`, `App\Http\Requests\Admin\FrameReviewStoreRequest`, `throttle:admin-curation`), action `App\Actions\Curation\ReviewFrame` [nouveau] :

| Champ | Type | Règle |
|---|---|---|
| `grid_version` | int | doit valoir `CURRENT_VERSION`, sinon `admin.review.grid_version_outdated` |
| `reviewed_hash` | char(64) | doit égaler `frame.published_hash` **sous verrou**, sinon `admin.review.stale` |
| `answers` | objet `{ <slug>: bool }` | exactement les `slugsFor(level, version)` ; `true` = conforme |
| `declared_source_reference` | string ≤ 255 | doit égaler la référence affichée, sinon `admin.review.source_mismatch` |

Le client **n'envoie jamais** `decision`. Refus, tous relus **sous le verrou de la frame** et tous **sans ligne `frame_review`** :

- `admin.review.locked` si la frame ou son film est `suspended` ou `withdrawn`, ou si la frame n'est pas `ready` ;
- `admin.review.level_changed` [clé ajoutée à la famille `admin.review.*` de C14-bis] si l'ensemble des clés de `answers` diffère de `slugsFor(frame_level, version)` **relu sous le verrou** : le niveau a pu changer entre l'affichage et l'envoi (second onglet), et une revue qui ne répond pas aux items du niveau courant ne prouve rien — ce n'est ni une revue passante ni une revue rejetée ;
- `admin.review.already_reviewed` [clé ajoutée] si la frame, relue sous le verrou, n'appartient à aucune des trois listes du § 7.3, ou si la décision calculée est `rejected` alors qu'elle est déjà dans « Rejetées ». **Un double envoi n'écrit donc jamais deux preuves** : après un « Conforme, publier », la frame est `published` à la version courante et sort des listes ; après un « Rejeter », elle est dans « Rejetées », d'où seule une revue passante (« Revoir ») peut encore partir.

`decisionFor()` ne reçoit ainsi qu'un ensemble de clés exact, et rend `passed` si toutes valent `true`, `rejected` dès qu'une vaut `false` (C14-bis § 4, point 5).

**Transaction unique**, `lockForUpdate` sur la frame : insertion de la ligne `frame_review` (`reviewer_id`, `reviewer_name` = `users.real_name` (D12 du 23/09), `reviewer_role` = rôle courant, `grid_version`, `decision`, `reviewed_hash`, `declared_source_kind`, `declared_source_reference`, `answers` telles quelles, `reviewed_at` = maintenant serveur). **Revue passante = publication** : `published_review_id` pointe la ligne, `availability = published` (si elle ne l'était pas), `first_published_at` si NULL, `availability_changed_at` si l'état change — au **même** instant serveur que `reviewed_at`, dont le prédicat du § 7.3 dépend —, copies de file de travail `reviewed_at` et `review_grid_version`, `MovieProjector::recompute`. **Toute** transition d'une frame vers `published` insère ainsi sa propre ligne (E10-21) ; seule une levée admin la rétablit sans nouvelle revue, sous la condition du § 11.2. Une revue rejetée **laisse l'état inchangé**. Sur une frame déjà `published` (re-revue), un rejet ouvre immédiatement la confirmation de dépublication de l'image (§ 8.4), geste distinct et attribué, motif pré-rempli par la liste des items en défaut ; refuser cette confirmation laisse l'image en jeu, et la liste « Rejetées » l'affiche jusqu'à décision.

Une frame peut être publiée alors que son film est `draft` : c'est ainsi que se construit la passe 1, le film entrant au vivier à sa propre publication (§ 8.1).

### 7.6 Source déclarée ; confirmation de l'arbitrage A7 [J1]

La source est **déclarée au moment de la revue, jamais après** (`questions-ouvertes.md`, décision 7) : l'instantané `declared_source_kind` / `declared_source_reference` de la ligne de revue en est la preuve. Référence = `tmdb_file_path` pour la voie TMDB, timecode `h:mm:ss` pour une capture — affichée en lecture seule et confirmée par l'envoi.

**Cette spec confirme l'arbitrage A7 de `10`, sans réserve** : la trace d'une capture identifie l'**œuvre** (son timecode), jamais l'outil ni la méthode d'extraction ; il n'existe **aucun** champ de support, d'édition, d'appareil, de logiciel ou de format d'origine, ni dans le formulaire, ni dans la revue, ni dans le schéma, et aucune donnée EXIF n'est lue (`stripImage()`). Une colonne de support transformerait le dossier de conformité en aveu sur la licéité de l'acte de capture. La phrase périmée de `10` A7 est à retirer (E10-68) et le risque de `questions-ouvertes.md` l.380 à corriger (A-65).

### 7.7 File « à re-revoir » ; geste rétroactif [file J1 ; geste J2]

- **File** : § 7.3, onglet « À re-revoir ». Une re-revue passante insère une nouvelle ligne, repointe `published_review_id` et met à jour les copies de travail ; l'image ne quitte jamais le jeu.
- **[J2] Geste rétroactif** (D13 du 23/09) : `POST admin/exclusion-grid/retroactive` → `admin.exclusion_grid.retroactive.store` (`ExclusionGridRetroactiveController@store`, `ExclusionGridRetroactiveRequest` : `version` = `CURRENT_VERSION`, `reason` ≤ 500 obligatoire, `takedown_reference` char(12) facultatif), garde `can:applyRetroactiveGrid,App\Models\Frame`. N'est offert que si la version courante est rétroactive (`admin.exclusion_grid.retroactive.unavailable` sinon). Frames éligibles : `published` **et** `review_grid_version < v` **et** `frame_level ∈ retroactiveLevels(v)`. Effet : passage `unpublished`, ligne `frame.grid_unpublished` par image avec le motif et la demande liée ; **une transaction par film**, `lockForUpdate`, `MovieProjector::recompute` ; un film qui perd sa couverture reste `published` et s'affiche « incomplet » ; **idempotent** (un second passage ne trouve rien) ; retour en jeu par une nouvelle revue à la version courante. Écran : décompte des images visées par film et, **avant confirmation**, la liste des films publiés que le geste rendra incomplets, chacun avec son `N` jouable maximal après le geste (calcul du § 8.4, n° 4) ; confirmation `admin.exclusion_grid.retroactive.confirm`, résultat `….done`.

### 7.8 Immuabilité [J1]

`App\Policies\FrameReviewPolicy` [nouvelle] : `create` = curator+ ; `update` et `delete` = **toujours faux** (question 5). S'y ajoutent, existants, `WithoutTimestamps`, `AppendOnlyBuilder` et la garde `saving` du modèle ; aucune route ne modifie ni ne supprime une revue. Immuabilité par convention applicative outillée, jamais par déclencheur SQL (`10` § 4.2).

---

## 8. Publication et dépublication

### 8.1 Publier un film [J1]

**La publication est un geste explicite du curateur**, jamais un déclencheur : « un film est `published` dès qu'il couvre 1, 3 et 5 » décrit l'**éligibilité** (n° 3). Bouton « Publier le film » sur l'éditeur et la fiche, actif quand toutes les conditions tiennent, sinon inactif avec la condition manquante nommée.

`POST admin/catalog/{movie}/publish` → `admin.catalog.publish` (`MoviePublishController@store`, `MoviePublishRequest` : `ambiguity_digest` char(64)), `MoviePolicy::publish`, `throttle:admin-curation`. Action `App\Actions\Curation\PublishMovie` [nouveau], **une transaction**, `lockForUpdate` sur le film, projection recalculée d'abord :

1. **Gardes de transition**, chacune avec son message : `content_flag = clear` (`admin.movie.publish.content_not_clear`) ; `movie_projection.levels_mask & MovieProjection::publishableLevelsMask()` égal à `MovieProjection::publishableLevelsMask()` [existant ; aucun littéral 21 dans `app/`] (`….coverage_missing`, niveaux manquants en paramètre) ; **garde de devinabilité** : au moins une clé `answer_key` de nature exacte (`! isCollisionChecked()`) et de forme non vide (`….not_guessable`, E10-24, C12) ; empreinte d'aperçu identique à celle recalculée à l'instant (`….preview_stale`, § 8.2). Couverture et contenu sont des **gardes de transition**, pas des invariants (E10-23, A-26).
2. **Écritures** : `availability = published`, `availability_changed_at`, `availability_reason = null`, `first_published_at` si NULL (jamais réécrit), `curated_by_id` si NULL (l'auteur de la passe de curation est celui qui publie la première fois) ; ligne `movie.published` si c'était la première publication, `movie.republished` sinon (aucun motif) ; `AnswerKeyProjector::recomputeAmbiguity()` sur toutes les formes du film, **dans la même transaction** (`10` § 3.5 : projecteur synchrone, jamais un job).
3. Après commit : rien n'est poussé aux lobbies ouverts ; leur rapport de vivier se recalcule à la prochaine écriture de réglage et au lancement (C2 § 4).

Au J1, aucun cache de `answer_key` n'existe (E10-16) : il n'y a rien à invalider.

### 8.2 Avertissement nominatif d'ambiguïté [J1]

Décision 13 : le back-office affiche au curateur, **avant confirmation**, les formes que la publication rendra ambiguës et **à quels films elles appartenaient** ; ambiguïté mesurée sur le **catalogue publié entier**, jamais sur le vivier, jamais rétroactive.

- `App\Support\Catalog\AmbiguityPreview` [nouveau], **en lecture seule** (un projecteur qui écrirait avant confirmation rendrait un préfixe refusé puis accepté en pleine manche, invariant L2) :
  - `forPublication(Movie $movie): AmbiguityReport` — (a) les clés **soumises à collision** du film (`AnswerKeyKind::isCollisionChecked()` : `prefix` et, avec D23 du 23/09, `subtitle`) dont la forme est portée par un autre film publié ; (b) les clés soumises à collision d'**autres** films publiés dont ce film porte la forme, quelle qu'en soit la nature. Chaque ligne : forme normalisée, natures, films concernés (`id`, `title_original`, `release_year`).
  - `forText(Movie $movie, string $text, TextTarget $target): AmbiguityReport` — même calcul pour un titre (formes exacte, préfixe et sous-titre dérivés par `AnswerKeyNormalizer::prefixOf()` / `subtitleOf()`) ou un alias (forme exacte seule, un alias ne produisant jamais de forme dérivée) saisi sur un film publié (§ 9.1, § 9.2).
  - `AmbiguityReport::digest()` : SHA-256 de ses lignes triées.
- Servi à l'ouverture de la confirmation par **rechargement partiel** de la prop optionnelle `publication_preview` de la fiche ou de l'éditeur ; la confirmation liste les lignes, chacune rendue par `admin.movie.publish.preview.line` (paramètres `:form`, `:kinds`, `:movies` ; « « le seigneur des anneaux » — préfixe de ce film, porté aussi par : … »), et poste le `digest`. Si le catalogue a changé entre l'aperçu et le clic, la publication est refusée (`preview_stale`) et l'aperçu se réaffiche : l'avertissement n'est jamais périmé.
- Une liste vide s'affiche explicitement : `admin.movie.publish.preview.none` (« Aucune forme ne deviendra ambiguë. »).

### 8.3 Dépublier un film, republier, écarter [J1]

`POST admin/catalog/{movie}/unpublish` → `admin.catalog.unpublish` (`MovieUnpublishController@store`, `MovieUnpublishRequest` : `reason` string ≤ 500 **obligatoire**), `MoviePolicy::unpublish`, `throttle:admin-curation`. Action `App\Actions\Curation\UnpublishMovie` : une transaction, `availability = unpublished`, `availability_changed_at`, `availability_reason` = motif, ligne `movie.unpublished` avec motif, `AnswerKeyProjector::recomputeAmbiguity()` sur les formes du film (sa sortie du catalogue publié peut lever des ambiguïtés). **Les images ne suivent pas** : elles restent publiées, et c'est le film qui sort du vivier. Depuis `draft`, le même geste **écarte** (§ 4.2). Republier = § 8.1 (ligne `movie.republished`).

### 8.4 Dépublier ou écarter une image ; perte de couverture [J1]

`POST admin/catalog/{movie}/frames/{frame}/unpublish` → `admin.catalog.frames.unpublish` (`FrameUnpublishController@store`, `FrameUnpublishRequest` : `reason` ≤ 500 **facultatif**, C14), `FramePolicy::unpublish`, `throttle:admin-curation`. Action `App\Actions\Curation\UnpublishFrame` : depuis `published` ou `draft` (écarter une image jamais publiée), passage `unpublished`, `availability_changed_at`, ligne `frame.unpublished`, `MovieProjector::recompute` dans la même transaction. **Avant confirmation**, si le film est publié et que le geste casse sa couverture 1/3/5, l'écran le dit par `admin.frame.unpublish.coverage_warning` (paramètre `:max` ; « Ce film deviendra incomplet : il restera jouable jusqu'à N = :max, avec repli de niveau », ou sa variante `….coverage_warning_unplayable` si aucun N n'est plus jouable) ; le geste reste permis, le film reste publié (E10-23). **Calcul de k** (`App\Support\Curation\CoverageLossPreview` [nouveau], lecture seule), servi en prop optionnelle `unpublish_preview: { frame_id: number, playable_up_to: number | null }` au rechargement partiel qui ouvre la confirmation : le masque jouable est recalculé **sans** la frame visée (son bit retiré si elle est la seule variante jouable de son niveau, `level_{i}_variants = 1`), puis `k` = plus grand `N` de `RoomSettingsBounds::MIN_FRAMES_PER_ROUND` à `MAX_FRAMES_PER_ROUND` pour lequel `FrameLevelCoverage::select(N, masque)` n'est pas `null` (C1), `null` s'il n'y en a aucun. Le même calcul sert le re-recadrage et le changement de niveau d'une frame publiée (§ 5.7) et le geste rétroactif (§ 7.7). Republier une image = la repasser en revue (§ 7.5) : dépubliée, elle réapparaît dans « À revoir » (§ 7.3). Une image **écartée** (jamais publiée) n'y réapparaît pas : pour réutiliser son visuel, on ajoute une nouvelle variante, ce que le dédoublonnage du § 5.3 permet puisqu'il ignore les images écartées. Une image n'est **jamais** supprimée (`restrictOnDelete`) ; ses fichiers restent sur le disque tant qu'elle n'est pas retirée.

### 8.5 Effet sur une partie en cours [J1 paresseux ; J2 actif]

- **J1 : la dépublication de curation est paresseuse** (C8 § 4.6) : aucun job n'est distribué. Un film dépublié finit la partie en cours (ses images restent servables, le film sort seulement du vivier des lancements suivants) ; une image dépubliée est substituée à la frappe suivante, ou fait annuler la manche avec `frame_unavailable` si son jeton est déjà frappé (`OpenTier`, C8 § 4.3). Une manche en cours n'est jamais réécrite (`10` § 4.3).
- **J2 : les gestes admin sont actifs** — toute transition vers `suspended` ou `withdrawn` distribue `WithdrawContentFromLiveRounds(?int $movieId, ?int $frameId, RoundIncidentReason $reason)` **après commit** (C8, R-33, E10-08), qui annule et remplace la manche touchée.

### 8.6 Supervision « œuvres jouables à N » — contrat C2, partie back-office [J1]

- Source unique : `PoolReporter::catalogueWorksByFramesPerRound(): array<int, int>` (C2), vivier **catalogue** (thèmes vides, sans clause de salon), compté en **œuvres** (E10-64), N de `RoomSettingsBounds::MIN_FRAMES_PER_ROUND` à `MAX_FRAMES_PER_ROUND`. `DashboardController::poolByFramesPerRound()` est supprimé, avec son `range(2, 5)` écrit en dur (règle 2) ; `CatalogIndexRequest::PLAYABLE_AT_MIN/MAX` cèdent la place à `RoomSettingsBounds`.
- **Forme de prop** fixée ici : `stats.pool: { frames_per_round: number, works: number }[]`, dans l'ordre croissant de N. Libellés `admin.dashboard.pool.*` reformulés : « vivier catalogue » et « œuvres » (n° 24) ; la notice rappelle que c'est un **plafond** — un lobby, qui retire la non-répétition, affiche toujours un nombre inférieur ou égal.
- Le tableau de bord ajoute : films **prêts à publier** (draft, contenu `clear`, couverture 1/3/5), films publiés **incomplets**, images à revoir, à re-revoir, rejetées, en échec, films écartés.

---

## 9. Titres, alias, regroupements, thèmes

### 9.1 Titres [J1]

Question 17. Sur la fiche, un titre par locale **activée** (`App\Enums\Locale` : `fr`, `en`) ; les lignes d'autres locales de catalogue s'affichent en lecture seule (elles n'alimentent ni l'affichage ni `answer_key`).

- **Corriger** (`PUT admin/catalog/{movie}/titles/{locale}` → `admin.catalog.titles.update`, `MovieTitleController@update`, `MovieTitleUpdateRequest` : `title` rogné, 1 à 255 caractères), action `App\Actions\Curation\SaveMovieTitle` : crée ou réécrit la ligne `(movie_id, locale)` en **`origin = curator`**, `edited_by_id` — ce qui la protège de toute resynchronisation (`10` § 9.3) — puis, dans la même transaction, `MovieProjector::recompute` (`title_locale_mask`) et `AnswerKeyProjector::project($movie)` par différence.
- **Retirer** (`DELETE …` → `admin.catalog.titles.destroy`) : offert pour une ligne `curator` seulement. Une ligne `tmdb` se **corrige**, elle ne se supprime pas : la resynchronisation la recréerait. L'absence de ligne est une information (`10` § 3.4) : aucun titre n'est jamais recopié d'une langue à l'autre.
- `title_original` et `title_original_latin` ne se corrigent pas ici : métadonnées TMDB écrasables (`10` § 9.3) ; une erreur se corrige chez TMDB.
- Sur un film publié, l'aperçu `AmbiguityPreview::forText()` précède la confirmation (§ 8.2) ; sur un film non publié, aucune forme ne pèse dans le recompte.

### 9.2 Alias [J1]

- **Ajouter** (`POST admin/catalog/{movie}/aliases` → `admin.catalog.aliases.store`, `MovieAliasController@store`, `MovieAliasStoreRequest` : `locale` ∈ locales activées — seules elles entrent dans `answer_key` —, `alias` rogné, 1 à 255 caractères), action `App\Actions\Curation\AddAlias` : `origin = curator`, `created_by_id`, reprojection synchrone. L'écran avertit si la forme normalisée est déjà acceptée pour ce film (aucune unicité sur le texte, le dédoublonnage vit dans `answer_key`). Exemple : « seigneur des anneaux 2 » est un alias curé, pas une règle (D23 du 23/09).
- **Retirer** (`DELETE admin/catalog/{movie}/aliases/{alias}` → `admin.catalog.aliases.destroy`, `DeleteAlias`) : tout alias, TMDB compris — c'est le geste qui corrige une forme exacte partagée que le rapport de collisions a signalée (C12 § 3 : **aucun geste de curation ne dépend d'une commande**, D10 du 23/09). Un alias TMDB retiré revient à la resynchronisation suivante, qui le signale « réapparu » (§ 3.7).
- La fiche liste, en lecture seule, les formes acceptées du film (`answer_key` : forme, nature, ambiguïté) : c'est ce qui rend un alias vérifiable par un non-technicien.

### 9.3 Couverture des titres par langue [J1]

Question 15. La fiche affiche, par locale activée, présent / absent, lu dans `movie_projection.title_locale_mask` (fraîcheur : `title_mask_version = Locale::MASK_VERSION`). La **file « titres manquants »** est un filtre du catalogue, `missing_title=fr|en` : `title_locale_mask & bit = 0` (arithmétique entière portable) et version courante, films publiés d'abord. Un film se publie sans couverture complète (`00` § Catalogue) : la validation ne dépend jamais de l'affichage.

### 9.4 `movie_group` : geste manuel au J1, candidats par distance au J2

Question 16. `movie_group` est **manuel**, jamais alimenté par TMDB, jamais montré à un joueur ; sa seule conséquence est l'exclusion mutuelle dans un tirage (`30`) — à ne pas confondre avec `collection`, la saga, qui doit au contraire pouvoir tomber ensemble.

- **[J1] Regrouper** (`PATCH admin/catalog/{movie}/group` → `admin.catalog.group.update`, `MovieGroupController@update`, `MovieGroupUpdateRequest` : `with_movie_id` ou `group_id` ou `null`, `label` facultatif `max:120`), action `App\Actions\Curation\SetMovieGroup` : si aucun des deux films n'est groupé, création d'un `movie_group` (`label` pré-rempli « Titre A (année) / Titre B (année) » puis tronqué à 120 caractères par `Str::limit($label, 119, '…')`, éditable avant validation — la colonne est `string(120)` alors qu'un `title_original` peut en compter 255, et MySQL strict refuserait l'insertion ; `note` facultative, `created_by_id`) ; sinon rattachement au groupe existant ; « Retirer du groupe » pose `group_id = null`, et un groupe réduit à moins de deux films est supprimé dans la même transaction. Aucune ligne `admin_action` : ce n'est pas un geste engageant, `created_by_id` le trace.
- **[J1] Candidats exacts** sur la fiche : films portant une forme **exacte** de titre identique (`answer_key`, natures `title_original`, `title_latin`, `title`), lue par `answer_key_norm_movie_uq` — c'est le cas des homonymes et des remakes au même titre (Old Boy).
- **[J2] Candidats par proximité** (C12 : « suggestion de candidats `movie_group` par `distance()` ») : `App\Support\Curation\MovieGroupCandidates` [nouveau], calcul en PHP sur les formes de titre, à `AnswerKeyNormalizer::distance()` ≤ `AnswerRules::tolerance()` et même suite de chiffres, plus `collection_id` identique ; servi sur la fiche en prop **optionnelle** `group_candidates` de `admin.catalog.show` (`CatalogController@show` [modifié], garde existante `can:view,movie`), chargée à la demande par rechargement partiel : aucune route nouvelle, et le coût quadratique n'est payé que sur clic. Le back-office **suggère**, il ne regroupe jamais seul : le regroupement reste le geste du J1.

### 9.5 Quasi-justes et rapport de collisions [J2]

D24 du 23/09 : au J1, ni job ni message, la table `near_miss` reste vide et les alias se saisissent à la main (§ 9.2).

- **Quasi-justes** : page `admin/near-misses/index`, lignes par film triées par `distinct_rounds` décroissant ; « Promouvoir en alias » (`NearMissPromoteController@store`, `App\Actions\Curation\PromoteNearMiss`) crée un `alias` en `origin = curator` dans la locale choisie par le curateur et déclenche le projecteur (`10` § 7.9) ; « Écarter » pose `dismissed_at`, **sans auteur** (la table ne référence aucun compte). La qualification d'une quasi-juste appartient à `70`.
- **Rapport de collisions** : page `admin/collisions/index`, mêmes données que `answers:collisions` (C12, outil du porteur au J1), chaque ligne menant à la fiche pour retirer l'alias fautif.

| Méthode | URI | Nom | Action | Garde | Requête |
|---|---|---|---|---|---|
| GET | `admin/near-misses` | `admin.near_misses.index` | `NearMissController@index` | `can:viewAny,App\Models\Movie` | — |
| POST | `admin/catalog/{movie}/near-misses/{nearMiss}/promote` | `admin.near_misses.promote` | `NearMissPromoteController@store` | `can:curate,movie`, `throttle:admin-curation`, `scopeBindings` | `NearMissPromoteRequest` : `locale` ∈ locales activées, `alias` rogné 1 à 255, pré-rempli par la forme de la quasi-juste |
| POST | `admin/catalog/{movie}/near-misses/{nearMiss}/dismiss` | `admin.near_misses.dismiss` | `NearMissDismissController@store` | `can:curate,movie`, `throttle:admin-curation`, `scopeBindings` | — |
| GET | `admin/collisions` | `admin.collisions.index` | `CollisionsController@index` | `can:viewAny,App\Models\Movie` | — |

Pages `admin/near-misses/index` et `admin/collisions/index` ; clés `admin.near_misses.*` et `admin.collisions.*`.

### 9.6 Thèmes [J2]

Écrans d'édition (05 : « l'édition des libellés de thèmes » revient à `20`) ; la sémantique, l'évaluateur, la dérivation de la difficulté et la liste par défaut des sagas (S4 du 23/09, `30` § 12.4) appartiennent à `30`. `ThemePolicy`, curator+. Au J1 le sélecteur de thèmes est masqué sous le seuil de `30` : ces écrans n'ont pas d'objet.

- **Libellés, ordre, publication** : libellés par locale d'interface (`theme_label.label`, 80 caractères), `sort_order` ; publication d'un thème **seulement** s'il porte un libellé dans chaque locale activée (`10` § 3.7 ; refus `admin.themes.labels_missing`) **et** s'il atteint le seuil ci-dessous. Publier est un basculement de drapeau, sans recalcul (`30` § 13.1).
- **Seuil de publication** (`30` § 12.3 déclare la mesure et le seuil, et confie à cette spec leur affichage et la forme de leur application au geste) : l'écran affiche le nombre d'œuvres du thème seul, `PoolReporter::themeWorks(int $themeId): int` = `countWorks(PoolScope::themeProbe($themeId))`, vivier catalogue restreint à ce seul thème, **publié ou non**, au `RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND`, en œuvres (`30` § 3.4). **Forme retenue : un refus**, jamais un simple avertissement : publier est refusé (`admin.themes.too_small`, paramètre `:count`) tant que ce nombre est inférieur au seuil de `30`, `RoomSettingsBounds::DEFAULT_ROUNDS_COUNT` ; au réglage par défaut : 10 œuvres à `N` = 3. Pourquoi un refus : sous ce seuil, un salon qui choisirait ce thème seul au réglage par défaut serait bloqué (`PoolReport::blocked()`, `30` § 4.2, cause `themeKeys`), exactement le défaut que la naissance non publiée des thèmes du J2 veut éviter (`30` § 12.3) ; un avertissement le laisserait passer au premier clic distrait. C'est une erreur de validation traduite, jamais un 403 ; la mesure est relue dans la transaction du geste, jamais prise de l'écran. **Dépublier reste toujours permis**, quel que soit le compte : c'est le geste qui retire un thème du J1 trop maigre (`30` § 12.3).
- **Ajouter une saga** (S4 du 23/09 : liste « éditable en back-office sans déploiement » ; `30` § 12.4 : « ajout d'une saga sur une collection importée ») : choix d'une `collection` **déjà présente au catalogue** (créée par un import, jamais par un appel TMDB depuis cet écran), création d'un thème `theme_kind = saga`, `rule_value` = `collection.id` local, `is_published = false` (`30` § 12.3), `key` = `saga.` suivie de `Str::slug()` du libellé **anglais** (`en`, locale de repli de l'instance, `05`), article initial (`The`, `A`, `An`) retiré — convention des dix clés livrées par `30` § 12.4 (`saga.lord-of-the-rings`, `saga.back-to-the-future`) —, unique (`theme_key_uq` ; refus `admin.themes.key_taken`), et un libellé par locale activée saisi dans le même formulaire. L'unicité réelle d'une saga reste celle de sa `collection`, gardée ici par `ThemeStoreRequest` et, côté seeder, par la règle de `30` § 12.5 (aucun thème de saga n'est inséré si sa collection est déjà désignée, fût-ce sous une autre clé) ; la convention de clé fait seulement coïncider les clés de l'écran et du seeder pour une même saga. **Après commit**, envoi de `App\Jobs\Catalog\SyncThemeMembership` puis de `DeriveMovieDifficulty` (`30` § 13.2, § 14.2). Aucun seeder rejoué, aucun déploiement.
- **Corriger la règle d'un thème** (`30` § 12.5 : « corriger un thème livré après son insertion (un identifiant de société erroné) se fait en back-office, pas en modifiant le seeder », qui ne réécrit jamais une ligne présente ; `30` § 12.3 : Marvel Studios = 420 est « à vérifier ») : `rule_value` et `rule_negated` éditables pour **toute** nature, `rule_value` validé selon `theme_kind` et la sémantique de `30` § 12.1 — `tmdb_tag_id` entier positif pour `genre` et `studio`, année de début à quatre chiffres multiple de 10 pour `decade`, code de langue de deux lettres minuscules (forme de `movie.original_language`) pour `language`, cas de `MovieDifficulty` pour `difficulty`, et pour `saga` une `collection` présente et désignée par aucun autre thème de saga (champ `collection_id`). `theme_kind` ne change **jamais** : un thème qui changerait de nature serait un autre thème, que sa `key` nommerait faussement. **Après commit**, envoi de `SyncThemeMembership` (`30` § 13.2), plus `DeriveMovieDifficulty` pour une saga (`30` § 13.1, § 14.2).
- **Appartenance manuelle** d'un film (`movie_theme.manual_state` `added`/`removed`, `assigned_by_id`, `assigned_at`, écrite par un curateur seul) : dans la même transaction, `is_active = MovieTheme::resolveIsActive(is_auto, manual_state)`, seule dérivation admise (`30` § 13.1).
- **Difficulté corrigée** (`movie.movie_difficulty_override`, qui survit au réimport) : dans la même transaction, `ThemeEvaluator::syncMovie($movie, ThemeKind::Difficulty)` (`30` § 13.1) ; elle ne relance pas la dérivation.
- **Aucune ligne `admin_action`** pour ces gestes : la liste fermée n'a aucun cas de thème (C14), ce ne sont pas des gestes engageants, et `assigned_by_id` trace l'appartenance manuelle.

| Méthode | URI | Nom | Action | Garde | Requête |
|---|---|---|---|---|---|
| GET | `admin/themes` | `admin.themes.index` | `ThemeController@index` | `can:viewAny,App\Models\Theme` | — |
| POST | `admin/themes` | `admin.themes.store` | `ThemeController@store` | `can:create,App\Models\Theme`, `throttle:admin-curation` | `ThemeStoreRequest` : `collection_id` (existe dans `collection`, désignée par aucun thème de saga), `labels.{locale}` requis pour chaque locale activée, rogné, 1 à 80 |
| PATCH | `admin/themes/{theme}` | `admin.themes.update` | `ThemeController@update` | `can:update,theme`, `throttle:admin-curation` | `ThemeUpdateRequest` : `labels.{locale}` 1 à 80, `sort_order` 0 à 65 535, `collection_id` pour une saga seulement, `rule_value` pour toute autre nature, validé selon `theme_kind`, `rule_negated` booléen ; `theme_kind` jamais accepté |
| POST | `admin/themes/{theme}/publish` | `admin.themes.publish` | `ThemePublishController@store` | `can:publish,theme`, `throttle:admin-curation` | `ThemePublishRequest` : `is_published` booléen ; `true` refusé sous le seuil de publication (`admin.themes.too_small`) |
| PATCH | `admin/catalog/{movie}/themes` | `admin.catalog.themes.update` | `MovieThemeController@update` | `can:curate,movie`, `throttle:admin-curation` | `MovieThemeUpdateRequest` : `theme_id`, `manual_state` ∈ {`added`, `removed`, `null`} |
| PATCH | `admin/catalog/{movie}/difficulty` | `admin.catalog.difficulty.update` | `MovieDifficultyController@update` | `can:curate,movie`, `throttle:admin-curation` | `MovieDifficultyUpdateRequest` : `movie_difficulty_override` ∈ `MovieDifficulty` ou `null` |

Pages `admin/themes/index` et bloc « Thèmes » de la fiche ; clés `admin.themes.*`.

---

## 10. Mesure du débit et lot pilote

### 10.1 Instrumentation [J1]

Question 14, décision 10 : la mesure se fait **par instrumentation en base**, pas au chronomètre.

- **Temps actif par film** (`movie.curation_active_seconds`, le seul qui capte la recherche d'une image). Le hook `resources/js/hooks/admin/use-curation-heartbeat.ts` poste un battement (`useHttp()`) sur `POST admin/catalog/{movie}/heartbeat` → `admin.catalog.heartbeat` (`CurationHeartbeatController@store`, `MoviePolicy::curate`, `throttle:admin-heartbeat`, réponse 204) à chaque tick de `catalog.curation.heartbeat_seconds` secondes, **si et seulement si** la page du film (éditeur, fiche, revue d'une de ses images) est visible **et** qu'une saisie (clavier, pointeur) a eu lieu **depuis le tick précédent**. La décision est une fonction pure (`resources/js/lib/admin/heartbeat-gate.ts`), testée par Vitest sans DOM (C18 § 2.4). Double montage de `strictMode` : un seul minuteur par page, nettoyé au démontage.
- **Côté serveur**, `App\Actions\Curation\RecordCurationHeartbeat::handle(User, Movie, CarbonImmutable $now): int` lit en cache l'instant du dernier battement (`curation:beat:{userId}:{movieId}`, aucune colonne), puis le réécrit : écart `≤ idle_seconds` → incrément de l'écart ; écart plus long ou premier battement → incrément nul ; puis `increment()` atomique. Deux onglets sur le même film se partagent la clé : le total ne double pas. Un film de démonstration n'est jamais incrémenté.
- **Pourquoi la règle client est « une saisie depuis le tick précédent »** : combinée à la règle serveur, elle exclut **en entier** toute pause de plus de `idle_seconds` et compte en entier toute pause plus courte, à la reprise. La règle antérieure (« une saisie dans les `idle_seconds` dernières secondes ») laissait les battements courir une minute après la dernière saisie, si bien qu'une pause de cent secondes était comptée en entier : contraire à la décision 10, et le p90 du verdict en était faussé. Exemple au réglage par défaut (battement toutes les 15 s, fenêtre de 60 s) : dernière saisie juste avant le tick de `t = 0`, reprise à `t = 100`, battement suivant à `t = 105` : écart de 105 s > 60 s, incrément nul.
- **`catalog.curation.idle_seconds` vaut 60** (décision 10 : « pauses de plus de 60 s exclues » ; `10` § 3.1, colonne `curation_active_seconds`) et n'est jamais modifié pendant un lot pilote ; `catalog.curation.heartbeat_seconds` lui est **strictement inférieur**, sans quoi aucun écart ne serait jamais compté (§ 13.7).
- **Mesure de la passe 1, figée à la terminaison** : `RecordCurationHeartbeat` n'incrémente `movie.curation_active_seconds` que tant que le film n'a jamais été publié ni écarté (`availability = draft` **et** `first_published_at` NULL, usage de back-office couvert par EN20-1). Le temps mesuré est donc celui de la passe 1, figé à la première terminaison (§ 10.2) ; la passe 2, les visites de la fiche et les corrections ne le touchent plus, et le verdict du pilote ne bouge pas après coup (D10 du 23/09).
- **Temps de recadrage par image** (`frame.crop_seconds`) : posté par le recadreur (§ 6.3), plafonné à `catalog.curation.crop_seconds_max`, écrit une fois : un re-recadrage ne l'écrit que s'il est NULL et que le film est encore en passe 1 (§ 5.7).

### 10.2 Tableau du débit [J1]

Page `admin/throughput`, `ThroughputController@index`, service `App\Support\Curation\ThroughputReport` [nouveau]. **Agrégat seulement, jamais de classement nominatif des curateurs** : mesurer des bénévoles nommés serait un traitement de surveillance, et l'agrégat suffit à la décision. Films de démonstration exclus.

- **Terminaison d'un film** = sa **première** ligne `movie.published` ou `movie.unpublished` dans `admin_action` (journal en ajout seul, invariant 10 du § 2.7), datée par son `created_at` et lue par `admin_action_subject_idx (subject_type, subject_id, created_at)` ; **jamais** `availability_changed_at`, qui bouge à chaque changement d'état et recomposerait la fenêtre du pilote après coup. **Films terminés** = films réels ayant une telle ligne, pris dans l'ordre de leur terminaison. « Publié » au sens de la mesure : film dont cette première ligne est `movie.published` ; « écarté » : film dont elle est `movie.unpublished` (§ 4.2).
- Par population (tous les films terminés ; le lot pilote), **ventilé par voie** (`is_import_exception` faux = `discover`, vrai = exception) : nombre de films, temps actif total, **médiane et p90** du temps actif par film **publié**, médiane et p90 de `crop_seconds` par image **créée avant la terminaison de son film** (la passe 2 n'entre pas dans la mesure de la passe 1), nombre de films écartés et leurs motifs. Médiane et p90 calculés **en PHP** (rang le plus proche : p90 = valeur de rang `⌈0,9 × n⌉` dans l'ordre croissant) — MySQL n'a ni `MEDIAN` ni percentile portable, et aucune requête n'emploie `GROUP BY` sur une colonne non agrégée.
- **Projection** en heures (`cible × p90`) et en semaines (`heures ÷ catalog.curation.pilot.weekly_curation_hours`) pour les cibles du J1 et du volume (§ 10.4). Tant que `weekly_curation_hours` vaut 0 (non déclaré, § 10.4), la projection en semaines affiche `admin.throughput.hours_undeclared` au lieu d'un quotient : jamais une division par zéro.

### 10.3 Lot pilote : composition et protocole [J1]

Décision 10 et D11 du 23/09. **20 films réels** (au défaut de `pilot.size`) curés avec l'outil livré, **en production** (D1 du 23/09), par le porteur (D4 du 23/09).

- **Quand** (D37 du 23/09) : dès la livraison de **tous** les lots J1 de cette spec — L20-9b et L20-11 compris, livrés au J1 (D35 du 23/09), de sorte que le pilote mesure le débit de l'outil définitif — et de leurs prérequis — L90-1, L90-4 et L90-6a (`GameFrame`, `GameThemeScope`), L30-1 (`FrameLevelCoverage`, C1), L30-3 (`PoolReporter`, C2) et le **socle de production minimal de `100`, sans moteur** (mise en service du VPS, hook de déploiement sans drainage, tiers chaud et froid de sauvegarde actifs avant la première image curée, contrôle de lisibilité de la première sauvegarde — `100` § 13.5, point 1 —, sondes et supervision externe ; la restauration chronométrée, `100` § 13.5 point 2, vient avant la première partie) —, jamais avant : un film curé sans l'instrumentation de L20-17 aurait un temps actif nul, entrerait dans la fenêtre et fausserait le p90 comme l'arithmétique « 40 films restant après le pilote » de D10 du 23/09 — amendé le 23/09.
- **Ce que le pilote n'attend pas** (D37 du 23/09) : les dépendances artificielles qui le retardaient sont levées. Le hook de déploiement du socle de production fonctionne **sans drainage** tant qu'aucun moteur n'existe : `deploy:guard` est trivialement vrai tant que `60` n'est pas livré ; la purge du J1 part d'abord sur les seuls périmètres sans jeu ; le rapport de force brute vient après L70-11. Le pilote n'attend donc ni le moteur de partie de `60` (seule l'installation de Reverb et de Redis, dont le socle de production a besoin, le précède), ni le lobby et l'archivage des salons de `50`. Le drainage (D32 du 23/09) et le test de charge complet (D33 du 23/09) restent obligatoires avant la **première partie** sur le VPS, jamais avant la curation (`100`) — amendé le 23/09.
- **Date** : les heures du § Lots sont des mesures de taille, jamais un calendrier (D36 du 23/09) ; la date du pilote ne se déduit donc d'aucun rythme hebdomadaire de développement. Elle suit l'ordre « curation d'abord » (D37 du 23/09) et le chemin critique **humain** du § 1.2 : domaine acheté, relevé puis montage root du VPS, premier admin créé, liste d'amorçage constituée (§ 3.5). C'est `00` § Jalons qui tient cette date (AN20-8) — amendé le 23/09.
- **Composition stratifiée** (D11 du 23/09) : environ 15 films de la voie `discover` et 5 de la voie d'exception (`catalog.curation.pilot.composition.discover` et `.exception`), choisis par les filtres de la file (§ 4.1). Les mesures sont **ventilées par voie**. Un film incurable faute de visuels TMDB est **écarté** et compte comme un **échec** : il chiffre ce que la capture apporterait, qui reste désactivée au J1 (§ 5.4).
- **Fenêtre du pilote, par voie** : les `composition.discover` premiers films terminés de la voie `discover` et les `composition.exception` premiers films terminés de la voie d'exception, comptés chacun à partir du rang `pilot.first_rank.discover` ou `pilot.first_rank.exception` de sa voie (1 au défaut ; un rang se décale pour mesurer un second pilote après une re-livraison, ou pour sauter un film réel terminé à titre d'essai avant le lot). `pilot.size` vaut la somme des deux compositions (contrainte du § 13.7). La fenêtre n'est **pleine**, et `PilotVerdict` n'est rendu, que lorsque **les deux** sous-fenêtres le sont : sans cela, 18 films `discover` terminés avant 2 films d'exception rendraient un verdict sur une composition que D11 du 23/09 exclut. Le tableau affiche l'avancement par voie. Aucune colonne ne marque le pilote.
- **Protocole** : tout le chemin passe par le back-office ; **toute intervention en ligne de commande** nécessaire pendant le pilote est consignée par le porteur dans le compte rendu du pilote (hors base) — elle est, à elle seule, disqualifiante.
- Le tableau (§ 10.2) rend le **verdict** du § 10.4 dès que la fenêtre est pleine ; `App\Support\Curation\PilotVerdict` [nouveau] en est la valeur, affichée telle quelle, avec l'**instant** où la fenêtre s'est remplie. Une fois la fenêtre pleine, le verdict ne dépend d'**aucune écriture postérieure** : la terminaison est lue au journal en ajout seul (§ 10.2), le temps actif est figé à la terminaison et `crop_seconds` au premier recadrage (§ 10.1), et seules comptent les images créées avant la terminaison. Le porteur recopie le verdict daté dans le compte rendu du pilote, qui fait foi.

### 10.4 Seuils et réactions, fixés avant le lot [J1]

D10 du 23/09, appliquée à la lettre ; valeurs dans `catalog.curation.pilot.*`, **fixées avant le lot et non modifiées pendant**.

| Condition | Réaction |
|---|---|
| Temps actif total du pilote (films écartés compris) > `disqualify_hours` (10 h), **ou** une intervention en ligne de commande sur le chemin du curateur | **Outil disqualifié** : re-livré avant toute curation de masse (réserve de re-livraison de `questions-ouvertes.md` § 8, environ 6 h en mesure de taille, D36 du 23/09 — amendé le 23/09), puis nouveau pilote |
| `p90 × (j1_target_films − size)` — 40 films restant après le pilote au défaut — ≤ `reserve_hours` (36 h) | Le J1 reste à **`j1_target_films`** (60) films |
| Sinon | Nombre de films du J1 = `⌊reserve_hours ÷ p90⌋` |
| Toujours | **Cible de volume** projetée au p90 de la passe 1, `min(volume_cap, ⌊heures déclarées ÷ p90⌋)`, plafonnée à `volume_cap` (500) ; « heures déclarées » = `weekly_curation_hours × horizon_weeks`, **à déclarer avant le lot** — tant qu'ils sont nuls, le tableau l'affiche au lieu d'une cible |

« p90 » désigne le p90 du temps actif par film **publié** du pilote. La relecture, après le pilote, de l'arithmétique de curation du J1 (réserve, nombre de films, cible de volume) appartient à `00` § Jalons ; les heures de développement n'y entrent plus comme budget (D36 du 23/09) — amendé le 23/09.

---

## 11. Modération et conformité

### 11.1 Trois régimes, deux rôles [J1 : curation ; J2 : admin]

`questions-ouvertes.md` « Retrait sur demande », `00` § Ouverture point 3 :

| Régime | Qui | Réversible | Fichiers | Jalon |
|---|---|---|---|---|
| Dépublication de curation, motivée | curateur | oui | conservés | J1 (§ 8.3, § 8.4) |
| Suspension conservatoire, un clic, sans examen préalable | **admin seul** | oui | conservés | J2 |
| Retrait juridique | **admin seul** | **non, terminal** | supprimés, réimport bloqué | J2 |

Au J1 le site est privé et `noindex` : la dépublication suffit. **Sans suspension en un clic ni file des demandes, l'engagement de 7 jours ouvrés n'est pas publié** (`00` § Ouverture points 3 et 8 ; risque « engagement tenu par une personne seule ») : ces deux éléments sont des conditions du J2.

### 11.2 Suspension conservatoire et levée [J2]

- **Suspendre un film** (`POST admin/catalog/{movie}/suspend` → `admin.catalog.suspend`, `MovieSuspendController@store`, motif facultatif, demande liée facultative) : bouton sur la fiche, **une** confirmation, aucun examen. Action `App\Actions\Curation\SuspendMovie`, **une transaction** : film `suspended`, `availability_changed_at`, motif ; **seules les frames `published` suivent** en `suspended` (E10-23, n° 18), les autres gardent leur état et ne peuvent pas être publiées tant que le film est suspendu ; suppression explicite des `seen_frame` des frames concernées ; ligne `movie.suspended` (avec `takedown_request_id` si demande liée) ; `MovieProjector::recompute` et `AnswerKeyProjector::recomputeAmbiguity()`. Sortie du vivier **à la seconde** : un lobby peut se rebloquer à sa prochaine vérification. Après commit, `WithdrawContentFromLiveRounds($movieId, null, RoundIncidentReason::MovieSuspended)` (§ 8.5).
- **Suspendre une image** (`admin.catalog.frames.suspend`) : même schéma, ligne `frame.suspended`, job avec `$frameId`.
- **Lever** (`admin.catalog.unsuspend`, `admin.catalog.frames.unsuspend`) :
  - **État antérieur du film**, reconstitué depuis le journal par `App\Support\Curation\SuspensionHistory` [nouveau] : la **dernière** ligne parmi `movie.published`, `movie.republished` et `movie.unpublished` **antérieure à la suspension levée** — `published`/`republished` → `published`, `unpublished` → `unpublished`, aucune → `draft`. Les lignes `movie.suspended` et `movie.unsuspended` sont ignorées.
  - **Garde rejouée** pour un film qui revient en `published` : celle du § 8.1 sur le contenu `clear`, la couverture 1/3/5 et la devinabilité. La confirmation affiche d'abord `AmbiguityPreview::forPublication()` (décision 13 : le film rentre au catalogue publié, donc ses formes pèsent de nouveau) et poste son empreinte ; une empreinte périmée refuse le geste **sans rien écrire** et réaffiche l'aperçu (`preview_stale`, § 8.2), elle ne dégrade jamais la levée.
  - **Garde en échec** : dans la même transaction, la levée écrit `movie.unsuspended` **puis** `movie.unpublished`, dont le motif est le texte de `admin.movie.unsuspend.guard_failed` (§ 2.7), et le film passe `unpublished`. Raison : invariant 10 du § 2.7 — sans cette seconde ligne, une seconde suspension retrouverait le `movie.published` d'origine et rendrait le film publié sans geste de curateur.
  - **Frames suspendues par la cascade** : une frame `suspended` l'est « par la cascade » si sa **dernière** ligne `admin_action` de sujet `frame` n'est pas `frame.suspended`, ou si elle n'en a aucune (la cascade n'écrit aucune ligne par image, invariant 9 du § 2.7). Elles reviennent en `published` **seulement si** leur dernière revue passante porte sur le `published_hash` **et** la version de grille courants ; sinon en `unpublished`, ou en `draft` si `first_published_at` est NULL (E10-21, n° 18). Une frame suspendue individuellement — dernière ligne `frame.suspended`, fût-elle contemporaine de son `availability_changed_at` — ne se lève que par son propre geste (`frame.unsuspended`, même règle de restauration, refusé tant que son film est lui-même `suspended`), jamais par la levée du film.
  - **Une transaction**, `lockForUpdate` sur le film et ses frames : restauration des états, ligne `movie.unsuspended` ou `frame.unsuspended`, puis `MovieProjector::recompute` et `AnswerKeyProjector::recomputeAmbiguity()` sur les formes du film (`10` § 3.2 règle 2 et § 3.5 : toute transition qui fait entrer une frame au comptant ou un film au catalogue publié recalcule couverture et ambiguïté dans la même transaction, comme la suspension les a recalculées en sens inverse).

### 11.3 Retrait juridique, suppression des fichiers, réconciliation [J2]

- **Retirer** (`admin.catalog.withdraw`, `admin.catalog.frames.withdraw`, `MovieWithdrawRequest` : `reason` ≤ 500 **obligatoire**, demande liée, confirmation par saisie du titre original) : action `App\Actions\Curation\WithdrawMovie` / `WithdrawFrame`, une transaction : `withdrawn` (terminal), motif et horodatage sur la ligne (`10` A2), **toutes** les frames du film passent `withdrawn`, `seen_frame` supprimés, ligne `movie.withdrawn` ou `frame.withdrawn`, `MovieProjector::recompute` et `AnswerKeyProjector::recomputeAmbiguity()` dans la même transaction (`10` § 3.2 règle 2, § 3.5) ; après commit, `WithdrawContentFromLiveRounds(…, RoundIncidentReason::MovieWithdrawn)`, un job de suppression par frame et, pour un film, `DeriveMovieDifficulty` (`30` § 14.2 : une transition vers `withdrawn` change la population des déciles). La ligne `movie` survit : son `tmdb_id` unique **bloque le réimport** (`10` A10).
- **Suppression des fichiers** : `App\Jobs\Curation\DeleteWithdrawnFrameFiles` [nouveau], file `default`, une frame par job : supprime `game_path` et `master_path`, vérifie `Storage::disk('frames')->missing()` sur les deux, pose `files_deleted_at` ; échec → `files_deleted_error` (clé de traduction `admin.frame.files_error.*`) et bouton « Relancer la suppression » (`admin.catalog.frames.files.retry`). Les chemins ne passent jamais à NULL (`10` A9). Le job de traitement refuse durement une frame `withdrawn` (§ 5.5).
- **`takedown:reconcile`** [nouvelle commande, `App\Console\Commands\TakedownReconcileCommand`] : balaie `frame WHERE availability = 'withdrawn' AND (files_deleted_at IS NULL OR fichier présent)`, resupprime, journalise dans `purge_run` sous `withdrawn_files` ; **obligatoire après toute restauration**, avec la purge (`10` § 10) — procédure d'exploitation de `100`, jamais un geste de curateur. Sonde : `COUNT(frame WHERE availability = 'withdrawn' AND files_deleted_at IS NULL) = 0`.

### 11.4 File des demandes de retrait [J2]

Question 23. Page `admin/takedowns/index` et `admin/takedowns/show` (`TakedownController`, `TakedownRequestPolicy`, admin seul). La demande et sa preuve vivent dans `takedown_request` (`10` § 8.2) ; cette section est la file de traitement.

- **Entrées** : le formulaire public « signaler un contenu » (`takedown.store`, `90`) ; et l'**enregistrement d'une demande reçue par e-mail** (`POST admin/takedowns` → `admin.takedowns.store`, `App\Actions\Admin\RegisterTakedownRequest`) avec `received_at` = date de réception du courriel, **jamais** la date de saisie, et la locale de réponse choisie. `reference` char(12) aléatoire base32, jamais dérivée de l'identifiant.
- **Accusé de réception automatique** : dès la création, job `App\Jobs\Takedown\SendTakedownMessage` [nouveau] (file `default`) qui envoie l'accusé par `Notification::route('mail', …)->notifyNow((new TakedownAcknowledged($request))->locale($request->locale))` puis pose `acknowledged_at` et `status = acknowledged`. L'engagement est « sous 72 h » ; `100` sonde toute demande restée sans `acknowledged_at` au-delà d'un seuil plus court.
- **Compte à rebours en jours ouvrés** : `App\Support\Takedown\BusinessDays` [nouveau], `deadlineFor(CarbonImmutable $receivedAt): CarbonImmutable` = fin (23:59:59, `Europe/Paris`) du **7ᵉ jour ouvré qui suit** le jour de réception ; **jour ouvré = lundi à vendredi, hors jours fériés légaux de France métropolitaine** : 1ᵉʳ janvier, lundi de Pâques, 1ᵉʳ mai, 8 mai, Ascension, lundi de Pentecôte, 14 juillet, 15 août, 1ᵉʳ novembre, 11 novembre, 25 décembre — Pâques calculé en PHP pur (algorithme grégorien anonyme), sans dépendre d'`ext-calendar`. Le 7 est l'engagement public (principe 12), une constante de procédure et non un réglage. `90` reprend **la même définition** dans le texte public. File triée par échéance croissante ; échéance proche ou dépassée signalée par un texte (`admin.takedown.due_soon`, `….overdue`), jamais par la seule couleur.
- **Décision** (`POST admin/takedowns/{takedownRequest}/decision` → `admin.takedowns.decision`, `TakedownDecisionRequest`, `App\Actions\Admin\DecideTakedownRequest`) : `decision` ∈ `TakedownDecision` (`suspended`, `unpublished`, `withdrawn`, `rejected`, `out_of_scope`), `decision_reason` motivée (obligatoire), `scope_kind`, cible film ou image choisie par l'admin ; **une transaction** : le geste correspondant (§ 11.2, § 11.3, § 8.3) avec `takedown_request_id` sur sa ligne, la ligne `takedown.decided` (motif ≤ 500 : résumé de la décision), `decided_at`, `decided_by_id`, `status = decided`. Puis notification au demandeur dans **sa** locale → `notified_at`, `status = closed`.
- **Messages vers le demandeur, FR et EN** : gabarits `mail.takedown.acknowledged.*` et `mail.takedown.decided.*` dans le domaine **`mail`**, jamais `admin`, en symétrie de clés vérifiée en CI (`05` § back-office, exception normative) ; la locale est celle **stockée avec la demande**, jamais `App::getLocale()`, que `admin.locale` force à `fr`. Classes `App\Notifications\Takedown\TakedownAcknowledged` et `TakedownDecided`.
- Aucune IP, aucune page de suivi publique (défaut retenu). Le vidage de l'identité à échéance (`takedown_identity`) appartient à `10` et `100`.

### 11.5 Modération des pseudos et des copies provider [J2]

La **règle** — seuil de deux signaleurs distincts, masquage, chaîne de repli, notification, effet de `nickname.banned` — appartient à `40` ; cette spec fournit l'**écran** (n° 11 ; `questions-ouvertes.md` : « les écrans admin appartiennent à `20` ») et la ligne de matrice (§ 2.2, ligne 35). Page `admin/moderation/index`, admin seul : pseudos masqués (`player.nickname_masked_at`) et copies provider masquées (`users.avatar_provider_hidden_at`) avec leur `reports_count` et leur date ; « Lever le masquage » (`nickname.unmasked`, `avatar.unhidden`, motif facultatif) ; repérage d'un signaleur abusif par le décompte de ses signalements dans la fenêtre de rétention de `report`. Les avatars prédéfinis et les images de jeu ne sont **jamais** signalables (`10` § 8.1).

| Méthode | URI | Nom | Action | Garde | Requête |
|---|---|---|---|---|---|
| GET | `admin/moderation` | `admin.moderation.index` | `ModerationController@index` | policy de `40`, admin seul | — |
| POST | `admin/moderation/players/{player}/unmask` | `admin.moderation.nickname.unmask` | `ModerationNicknameController@store` | policy de `40`, admin seul, `throttle:admin-curation` | `ModerationReasonRequest` : `reason` ≤ 500, facultatif |
| POST | `admin/moderation/users/{user}/avatar/unhide` | `admin.moderation.avatar.unhide` | `ModerationAvatarController@store` | idem | idem |

`{player}` et `{user}` sont liés par `id`, identifiant qui ne sort que vers le back-office (E10-11 (b)). Le nom exact des méthodes de policy est fixé par la section J2 de `40`, que le jeu `AdminRoutes` reprend à la lettre ; chaque levée écrit sa ligne (`nickname.unmasked`, `avatar.unhidden`) par `AdminJournal::record`, dans la transaction de l'état.

---

## 12. Signaux de curation et inspection [J2]

### 12.1 Films jamais trouvés et incidents

Question 22. Page `admin/incidents/index` (`IncidentsController@index`, `MoviePolicy::viewAny`), spécifiée maintenant, livrée au J2 : avant les premières parties l'écran est vide par construction. **Agrégat par `movie_id`**, sur `catalog.curation.incidents_window_days` jours glissants : manches `completed` et, parmi elles, `found_count = 0` ; manches annulées par `cancel_reason` ; paliers substitués par `substitution_reason`. Requête par `round_movie_found_idx` (préfixe `movie_id`), **sans jamais joindre `round_player`, `guess` ni `player`** : aucune identité de joueur n'entre dans cette file (`10` § 7.4). Chaque ligne mène à la fiche du film ; la réaction (nouvelle variante, niveau revu, alias) est un geste de curation ordinaire.

### 12.2 Inspecter une partie

Page `admin/games/index` et `admin/games/show` (`GameInspectionController`, `GamePolicy::inspect`, **admin seul** : l'écran expose des données personnelles de joueurs, `questions-ouvertes.md` « Observabilité »). Recherche par code de salon (actif ou archivé, `room_code_idx`) ou par date ; pour une partie : réglages figés, métadonnées du tirage et versions, manches (film, statut, motif d'annulation), paliers (frame tirée, frame servie, substitution, `served_at`), participations (pseudo affiché tant qu'il n'est pas effacé, état de saisie, tentatives fausses comptées), bonnes réponses (instant, palier, points, nature de l'appariement, ambiguïté au moment du match), classement, et les écarts de rejeu `ScoreReplayer::mismatches(Game)` de `80` (le rejeu de chronologie appartient à `60`). **Partie en cours : rien d'une manche non révélée** (règle 3, principe 2 — l'administrateur peut être assis dans la partie inspectée, et le tirage est figé au lancement, manches de réserve comprises) : pour une partie dont `game.status` n'est pas terminal (`running`, `paused`), l'écran ne montre d'une manche que `sequence_index`, `round_number`, `status` et ses horaires tant qu'elle n'a pas atteint `revealing`, `completed` ou `cancelled`. Film, titres, frames tirées et servies, leurres (`decoy_movie_id_*`, `choices_use_original_title`), quatre chaînes du QCM et bonnes réponses d'une manche `pending` ou `running` ne sont **ni chargés ni sérialisés**, pas même masqués côté client. Aucun export, aucune copie des pseudos ailleurs ; la consultation n'est pas un geste de la liste fermée et ne s'écrit pas dans `admin_action` : elle est journalisée par le canal applicatif de `100`, sans donnée personnelle.

---

## 13. Ergonomie, langue et accessibilité du back-office

### 13.1 Un outil de non-technicien : aucune commande artisan [J1]

Décision 9 : aucune commande artisan sur le chemin quotidien du curateur, aucun message d'erreur brut, aucun JSON affiché, chaque échec traduit et rejouable d'un bouton. Au J1 :

- **Commandes existantes ou nouvelles, toutes hors du chemin du curateur** : `admin:first-admin` (une fois, § 2.5) ; `catalog:import-*` (alternative d'exploitation, et moteur des jobs) ; `answers:collisions` (outil du porteur, C12) ; `catalog:reproject`, `backup:snapshot`, `deploy:*`, `game:reschedule` (exploitation, `100` et `60`) ; au J2, `takedown:reconcile`.
- **Messages réécrits** (n° 7) — la consigne technique part au journal et aux sondes de `100`, le curateur reçoit ce qu'il peut faire : `admin.import.runs.worker_missing` (« le traitement d'arrière-plan ne répond pas ; l'administrateur est prévenu »), `admin.import.disabled`, `admin.error.tmdb_disabled`, `admin.tmdb.error.not_configured` (sans nom de variable), `admin.tmdb.error.{rate_limited, server_error, transport}` (« reprenez depuis le bouton Reprendre »), `admin.catalog.import.skipped.duplicate` (sans `--resync`), `admin.validation.ids.max` et `admin.import.ids.list.hint` (sans la console), `admin.import.deferred_notice`, `admin.import.toast.queued` et `admin.import.runs.resume_unavailable_queued` (« traitement d'arrière-plan » au lieu de « worker »). Un test balaie `lang/fr/admin.php` hors `admin.console.*` : aucun texte ne nomme la console, un worker, une commande ni une variable d'environnement.

### 13.2 Français seul, 100 % par clés ; messages vers un tiers en FR et EN [J1]

- Le back-office est en **français seulement**, et **100 %** de ses textes passent par le domaine `admin` (`05` § back-office) : grille comprise (`admin.exclusion_grid.*`, n° 6), jamais un domaine `curation`. `ForceAdminLocale` [existant] force `fr` et ne charge que `admin`, `common` non joint : toute clé d'écran d'administration vit dans `lang/fr/admin.php`, exclue de la symétrie de clés.
- **Pied du back-office** : `resources/js/components/admin/admin-footer.tsx` [nouveau] sur `admin.footer.{label, notice, terms, privacy, tmdb_attribution, tmdb_logo_alt}` (C15, C16), jamais le domaine `legal`, liens Wayfinder vers les pages légales de `90`.
- **Page d'erreur** `admin/error` (C15 § 2.3) quand `admin` était sélectionné avant l'exception, sur `admin.error.http.{status}.{title, description}` ; sinon la page `error` joueur.
- **Formatage** des nombres et dates **côté client** en `fr` (`resources/js/lib/admin-format.ts`, `Intl`) ; `App\Support\Format` est réservé aux e-mails et exports (A-48).
- **Exception de langue** : les seuls messages sortants vers un tiers — accusé et notification d'une demande de retrait — vivent dans `mail.*`, en FR **et** EN (§ 11.4).

### 13.3 Apparence [J1]

D8 du 23/09 : le back-office **suit l'apparence choisie** par le visiteur (principe 13) ; `ForceAdminAppearance` et son alias disparaissent (§ 2.3). **Seuls** les cadres de revue et de prévisualisation sont rendus sous les tokens sombres du jeu, par `GameThemeScope` (§ 6.7). Tokens partout, aucune couleur, taille en `px` ni variante `dark:` dans un fichier surveillé. Les répertoires `resources/js/hooks/admin/` et `resources/js/lib/admin/`, créés par cette spec, sont **absents** de la liste figée de C16 § 2.11 ; or la méta-vérification fait échouer tout fichier ni `WATCHED` ni `EXEMPT`, et `EXEMPT` ne fait que décroître. Le lot L20-9a les **ajoute donc à `WATCHED`** dans `scripts/check-theme-tokens.mjs`, dès son premier fichier, sans quoi `npm run check` échouerait ; cette extension de C16 § 2.11, propriété de `90`, lui est adressée et signalée au porteur (EN20-2).

### 13.4 Clavier, focus, coquille mobile [J1]

- Opérabilité intégrale du recadreur et de la revue (§ 6.4), focus visible, motifs ARIA standard (groupes radio, onglets de la file de revue, boîtes de dialogue de confirmation au focus piégé et rendu à l'élément déclencheur), messages de validation liés par `aria-describedby`, cibles d'au moins `min-h-11 min-w-11` sur les gestes répétitifs.
- **Dette n° 24** (question 24) : `admin-sidebar.tsx` compose **sa propre coquille mobile** avec son `<SheetContent>`, un `SheetTitle` sur `admin.a11y.nav_mobile` et une `SheetDescription` sur `admin.a11y.nav_mobile_description` (C16 § 2.9), sans jamais s'appuyer sur le `Sheet` intégré de `ui/sidebar` ni éditer `components/ui/*`.
- **Fermeture propre de toute feuille et de toute boîte de dialogue** (`90` § 2.5) : `SheetContent` et `DialogContent` rendent après leurs enfants un bouton au nom accessible « Close », en dur et en anglais, qu'aucune prop ne remplace ; un lecteur d'écran l'annoncerait en anglais sur un back-office français (règle 4). Toute `SheetContent` ou `DialogContent` du back-office — coquille mobile d'`admin-sidebar.tsx`, `publish-dialog.tsx` et `reason-dialog.tsx` (§ 8.2, § 8.3), confirmations des § 4.4, § 5.7, § 7.5, § 8.3, § 8.4 et toutes celles du J2 — masque donc ce bouton par `className="[&>button:last-child]:hidden"` et compose son propre `<SheetClose>` ou `<DialogClose>`, étiqueté `admin.a11y.close`. Tout `components/admin` étant `WATCHED`, le balayage statique de `ShellTest` (L90-7, « ne rend aucune SheetContent ni DialogContent sans masquer la fermeture générée en anglais ») fait sinon échouer la suite.

### 13.5 États de chargement, d'erreur et de déconnexion [J1]

Chaque écran de cette spec traite, avec ses clés : chargement (squelette ou indicateur, `aria-busy`), vide (message et action utile), erreur de serveur (message traduit et « Réessayer »), refus de validation (sous le champ), refus d'autorisation (page `admin/error`), déconnexion (toast `admin.common.offline`, formulaire conservé, rechargement proposé), job en attente ou en échec (§ 5.6), aperçu d'ambiguïté périmé (§ 8.2). Un état « le traitement d'arrière-plan ne répond pas » (`admin.bank.processing_stalled`) s'affiche dès qu'une frame reste `pending` plus de `catalog.curation.stale_pending_minutes` minutes (§ 13.7). Un 429 sur un appel TMDB interactif donne `admin.tmdb.error.rate_limited_interactive` et « Réessayer » (§ 3.6).

### 13.6 Page « premiers pas du curateur » [J1]

`GET admin/guide` → `admin.guide`, page `admin/guide`, domaine `admin`, **livrée avec l'outil** (`questions-ouvertes.md` « Back-office en français seulement ») et condition du lot pilote : les deux passes ; l'échelle 1-5 avec le guide du § 6.5 ; la grille v1 item par item (§ 7.1) ; le plancher de recadrage et pourquoi ; la revue et la source déclarée ; la publication et l'avertissement d'ambiguïté ; écarter un film ou une image ; les raccourcis ; **ce qu'il ne faut jamais faire** (publier une affiche, un plan de générique, une photo de plateau ; supprimer — impossible — ; corriger un titre par un alias).

### 13.7 Configuration du back-office [J1 ; clés J2 marquées]

Bloc `curation` et clés d'import de `config/catalog.php`. **Aucune n'est une valeur de jeu** : aucune ne vient de `room_settings`, aucune n'atteint une partie (règle 2 : chacune a son défaut et sa contrainte, jamais un littéral dans le code). **Aucune n'est lue de l'environnement**, hormis `capture_enabled` (§ 5.4) : toute modification passe donc par un commit, et le test de configuration ci-dessous est la garde. C9 § 8 laisse à cette spec les qualités WebP et les limites Imagick ; les autres valeurs sont choisies ici.

| Clé | Défaut | Contrainte vérifiée | Raison |
|---|---|---|---|
| `curation.capture_enabled` | `env('CURATION_CAPTURE_ENABLED', false)` | — | voie capture fermée au J1 (§ 5.4) |
| `curation.idle_seconds` | **60** | égale à 60 (décision 10, `10` § 3.1) | pauses de plus de 60 s exclues ; jamais modifiée pendant un lot pilote |
| `curation.heartbeat_seconds` | 15 | `< idle_seconds` | sinon aucun écart n'est jamais compté (§ 10.1) |
| `curation.rate_limits.heartbeat` | 12 par minute et par utilisateur | `≥ 2 × ⌈60 ÷ heartbeat_seconds⌉` | deux onglets ouverts sur la même page ne reçoivent jamais de 429 |
| `curation.rate_limits.frame` | 30 par minute et par utilisateur | `≥ 1` | limiteur `admin-frame` (ajout, re-recadrage, relance, C9 § 2) : double clic sur un geste qui télécharge un original ou distribue un job Imagick |
| `curation.rate_limits.curation` | 60 par minute et par utilisateur | `≥ 1` | limiteur `admin-curation` : au-dessus du débit de « Entrée = conforme, publier » |
| `curation.rate_limits.search` | 30 par minute et par utilisateur | `≥ 1` | limiteur `admin-tmdb-search` (§ 3.4) |
| `curation.crop_seconds_max` | 600 | `≤ 65 535` (`frame.crop_seconds` en `unsignedSmallInteger`, `10` § 4.1) | un cadre oublié ouvert ne fausse pas la médiane |
| `curation.tmdb_original_max_kilobytes` | 16 384 | `≥ 1` | l'original transite par la mémoire de la requête HTTP qui le télécharge (§ 5.3) |
| `curation.webp.master_quality` | 90 | dans [1, 100] | master de re-recadrage, jamais servi à un joueur |
| `curation.webp.game_quality_start` / `_min` / `_step` | 85 / 40 / 5 | `1 ≤ min ≤ start ≤ 100`, `step ≥ 1` | descente jusqu'à passer sous `gameEncodeCeilingBytes()` (§ 5.5) ; la revue sur le rendu final juge le résultat |
| `curation.imagick.memory_mb` / `map_mb` | 128 / 192 | `memory_mb + map_mb + 128 ≤ 512` | `MemoryMax` du worker `default` (512 M, `100` § 10.4), 128 Mo laissés à PHP |
| `curation.imagick.area_mpx` | 16 | `≥ 9` | un original 4K (8,3 Mpx) tient en mémoire |
| `curation.imagick.width_px` / `height_px` | 8 192 / 8 192 | `≥ MASTER_WIDTH` | refus d'une source absurde avant décodage |
| `curation.imagick.time_s` | 60 | `< 120` | `--timeout` du worker `default` (`100` § 10.4) : Imagick échoue avant que le worker ne tue le job |
| `curation.poll_seconds` | 3 | `≥ 1` | rechargement partiel de l'éditeur tant qu'une frame est `pending` (§ 6.1) |
| `curation.images_cache_minutes` | 1 440 | `≥ 1` | liste des visuels TMDB d'un film ; une liste ancienne est inoffensive (§ 6.2) |
| `curation.stale_pending_minutes` | 10 | `≥ 1` | au-delà de la durée d'un import qui tient la file (§ 3.6), sans fausse alerte |
| `curation.pilot.*` | § 10.3 et § 10.4 | `size = composition.discover + composition.exception` | fenêtre stratifiée du pilote |
| `import.preview_ttl_minutes` | 60 | `≥ 1` | durée de vie d'un aperçu à blanc (§ 3.3) |
| `import.seed_list_path` | `database/data/tmdb-seed-list.txt` | fichier lisible | liste d'amorçage (§ 3.5) |
| [J2] `curation.claim_minutes` | 15 | `× 60 > heartbeat_seconds` | réservation souple prolongée par le battement (§ 4.1) |
| [J2] `curation.incidents_window_days` | 30 | `≥ 1` | fenêtre glissante des incidents (§ 12.1) |

Les deux valeurs de `100` (`--timeout=120`, `MemoryMax=512M`) sont des valeurs de départ, recalibrées par D33 du 23/09 : le test de configuration les recopie avec leur renvoi, et le commit qui les change chez `100` met ce test à jour. Le lot pilote tourne sous ces valeurs de départ, puisque le test de charge complet précède la première partie et non la curation (D37 du 23/09) — amendé le 23/09.

---

## 14. Réponses aux 24 questions de `REPRISE.md` « À trancher par la spec 20 »

| # | Question | Réponse | Où | Jalon |
|---|---|---|---|---|
| 1 | 2FA des rôles privilégiés : où, et redirection ou 403 ? | Middleware `admin.2fa` du groupe `/admin` (mécanisme de `10` A16), redirection vers une page d'enrôlement qui mène à `security.edit`, jamais un 403 muet ; file « sans 2FA » à l'écran d'accès | § 2.4 | J1 ; file J2 |
| 2 | Écran de gestion des accès, `UserPolicy` | Écran admin, `updateRole` admin seul, historique par `admin_action`, dernier admin indéboulonnable sur tout chemin | § 2.8 | J2 |
| 3 | Suspension conservatoire et retrait juridique | Admin seul, cascade sur les frames `published`, `seen_frame` supprimés, journal permanent, annulation active, job de suppression, `takedown:reconcile` | § 11.2, § 11.3 | J2 |
| 4 | Modération des pseudos et copies provider | Règle à `40`, écran et gestes admin ici | § 11.5 | J2 |
| 5 | `FrameReviewPolicy` | `create` curator+, `update` et `delete` toujours faux | § 7.8 | J1 |
| 6 | Premier admin sans preuve d'adresse ? | Oui : l'accès au shell vaut preuve, sur les deux chemins ; `--create` définitif ; nom réel exigé | § 2.5 | J1 |
| 7 | Éditeur de la banque d'images | Voie TMDB seule au J1, capture spécifiée et refusée par double garde, presse-papiers sans objet au J1, grille de visuels ; bande balayable spécifiée et livrée au J1 dans le lot des raccourcis (L20-11, qui n'est plus une coupe possible depuis D35 du 23/09 — amendé le 23/09) ; dédoublonnage dans le film | § 5.3, § 5.4, § 6.2 | J1 ; capture J2 conditionnelle |
| 8 | Recadreur intégré | 16:9, master 1920, plancher de D6 du 23/09, rectangle par défaut = plus grand cadre admis centré, opérable au clavier, traitement différé sans attente | § 5, § 6.3, § 6.4 | J1 |
| 9 | Classement 1-5 et prévisualisation en conditions de jeu | Guide normatif relatif au film ; `GameFrame` dans `GameThemeScope`, deux largeurs, séquences par N avec repli | § 6.5, § 6.7 | J1 |
| 10 | Grille interactive, `frame_review`, source déclarée, file « à re-revoir » | Grille v1 item par item, drapeau `retroactive`, revue sur le rendu final, source confirmée à la revue, file prospective | § 7 | J1 ; geste rétroactif J2 |
| 11 | Workflow de publication, confirmation, motifs, avertissement nominatif | Geste explicite, gardes nommées, aperçu d'ambiguïté en lecture seule avec empreinte ; motif obligatoire pour dépublier un film, facultatif pour une image | § 8 | J1 |
| 12 | Coche « contenu vérifié » | Fiche film, curator+, confirmation, motif obligatoire, `movie.content_verified`, pas de décoche | § 4.4 | J1 |
| 13 | File ordonnée, réservation, reprise | Films entamés d'abord, puis votes décroissants ; aucune réservation au J1, réservation souple en cache au J2 ; reprise naturelle ; « écarter » un film incurable | § 4.1, § 4.2 | J1 ; réservation J2 |
| 14 | Mesure du débit, seuils et réaction | Battement posté seulement après une saisie, pauses de plus de 60 s exclues en entier, mesure de la passe 1 figée à la terminaison, `crop_seconds`, médiane et p90 en PHP, fenêtre stratifiée par voie, seuils de D10 du 23/09 fixés avant le lot | § 10 | J1 |
| 15 | Couverture des titres par langue, « titres manquants » | Indicateur sur la fiche, filtre du catalogue par `title_locale_mask` | § 9.3 | J1 |
| 16 | `movie_group` : candidats et geste | Geste manuel et candidats exacts au J1 ; candidats par distance et collection au J2 | § 9.4 | J1 / J2 |
| 17 | Titres, alias, `near_miss`, `origin = curator` | Correction en `curator`, alias ajoutés et retirés à l'écran ; promotion des quasi-justes au J2 (D24 du 23/09) | § 9.1, § 9.2, § 9.5 | J1 ; `near_miss` J2 |
| 18 | Resynchronisation depuis l'écran | Écran de différences, liste close de `10` § 9.3, proposition de dépublication | § 3.7 | J2 |
| 19 | Import unitaire par recherche | `TmdbClient::search()` sur une route dédiée à son propre limiteur, résultats marqués, import = collage d'un identifiant (exception), un seul collage ouvert à la fois | § 3.4 | J1 |
| 20 | Reprise d'un collage interrompu | Recoller la même liste (dédoublonnage) ; liste d'amorçage versionnée ; aucune exigence à `10` | § 3.3 | J1 |
| 21 | Abandon d'un balayage suspendu | Bouton « Clore », `ImportRunPolicy::update`, `failed` + `finished_at`, sans motif ni journal | § 3.8 | J2 |
| 22 | Films jamais trouvés et incidents | Agrégat par film sur fenêtre glissante, sans identité de joueur | § 12.1 | J2 |
| 23 | File des demandes de retrait | Jours ouvrés définis, accusé automatique, décision motivée liée au geste, messages FR/EN dans `mail` | § 11.4 | J2 |
| 24 | Coquille mobile de la barre latérale | Coquille composée dans `admin-sidebar.tsx`, titre et description traduits | § 13.4 | J1 |

---

## 15. Arbitrages du rédacteur

- **B1 — Voie capture : le serveur dérive toujours le dérivé de jeu** (R-46, signalé au porteur). Écarté : un dérivé envoyé par le navigateur (lettre de n° 0). Raison : D6 du 23/09 exige un plancher vérifiable côté serveur ; une chaîne unique. Sans effet sur le J1.
- **B2 — « Écarter » = `unpublished` sans `first_published_at`.** Écartés : un état nouveau (schéma), ou laisser la file se boucher de films impubliables qui faussent la mesure. Raison : les cinq états suffisent, `admin_action` trace, et c'est réversible (EN20-1).
- **B3 — Changer le niveau d'une image publiée la renvoie en revue.** Écarté : garder la revue si les items applicables sont identiques. Raison : `frame_review` ne porte pas le niveau ; rejouer une preuve coûte une touche, l'inférer affaiblit l'engagement 2.
- **B4 — « Conforme » répond `true` à tous les items affichés.** Écarté : une case par item à cocher une à une. Raison : le débit (`Entrée = conforme, publier`) sans perdre la déclaration item par item ; le rejet, lui, nomme ses items.
- **B5 — Aperçu d'ambiguïté en lecture seule, protégé par une empreinte.** Écarté : écrire puis annuler. Raison : invariant L2, et un avertissement périmé mentirait.
- **B6 — Limiteur TMDB partagé au J2 seulement.** Raison : un seul worker `default` au J1 sérialise les imports.
- **B7 — Page d'enrôlement admin plutôt qu'un toast dans les réglages.** Raison : aucune clé à écrire dans le domaine `account` de `40`, et une explication lisible.
- **B8 — Candidats `movie_group` exacts au J1, par distance au J2.** Raison : une lecture indexée couvre le cas fréquent (même titre) ; le calcul par distance est quadratique et n'a d'objet qu'à catalogue large.
- **B9 — Le pilote est une fenêtre de rangs, pas une colonne.** Raison : aucune donnée nouvelle, et un second pilote se mesure en décalant le rang.
- **B10 — Recadreur TMDB sans encodage navigateur.** Raison : n° 0 fait télécharger l'original par le serveur ; le canevas, `toBlob` et `crossOrigin` ne servent que la capture, ce qui allège le recadreur du J1.
- **B11 — Plancher à double borne, largeur et hauteur** (EN20-4). Écarté : la largeur seule du contrat C9. Raison : D6 du 23/09 vise une fraction de la **surface** ; la largeur seule la laisse monter à 80 % sur un master plus large que 16:9.
- **B12 — File « À revoir » datée par `availability_changed_at`.** Écarté : une colonne « à revoir ». Raison : une image publiée changée de niveau ne change ni d'octets ni de version ; seule la date de sa sortie du jeu la distingue, et elle existe déjà.
- **B13 — Liste d'amorçage importée lot par lot.** Écarté : un collage unique de toute la liste. Raison : le verrou de `ImportLauncher` interdit deux collages ouverts, et `paste_max_ids` borne un envoi web sous le `--timeout` de la file `default`.
- **B14 — Mesure du pilote figée à la terminaison, lue au journal.** Écarté : `availability_changed_at` et un temps actif qui court après publication. Raison : D10 du 23/09 raisonne sur le p90 de la **passe 1**, et un verdict qui bouge après coup ne fixe rien.

---

## Exigences adressées à 10

Consommées, consolidées dans la feuille de contrats ; `10` les inscrit ou les refuse en le signalant.

- **E10-02** — `users.real_name`, nullable, `#[Hidden]`, garde `User::saving`, vidée à l'anonymisation (§ 2.6).
- **E10-05** — `admin_action` : liste fermée à 21 cas, sujet `site`, acteurs réservés `system` et `console`, motifs obligatoires (§ 2.7).
- **E10-08** — cas `movie_suspended` de `RoundIncidentReason`, déclenché par la suspension admin (§ 8.5, § 11.2).
- **E10-09** — cas `subtitle` : l'avertissement nominatif couvre les sous-titres (§ 8.2).
- **E10-10** — `frame.processing_error` casté en `FrameProcessingFailure`, valeur = clé (§ 5.6).
- **E10-11 (b)** — le back-office adresse `movie` et `frame` par leur `id`, jamais une surface joueur (§ 2.3).
- **E10-16** — aucun cache `answer_key` au J1 : la publication n'a rien à invalider (§ 8.1).
- **E10-17** — `source_hash` : octets originaux téléchargés par le serveur (TMDB), octets reçus (capture) ; fichier provisoire sous `master_path` (§ 5.3, § 5.4).
- **E10-18** — géométrie : master de 1920 de large, `crop_*` 16:9 exact, dérivé 1280×720, plancher vérifiable par requête (§ 5.1, § 5.2, § 5.9).
- **E10-19** — re-recadrage en place, nouveau `game_path` par traitement, refus des frames `withdrawn` et `published` par le job, sondes (§ 5.5, § 5.7).
- **E10-21** — toute publication de frame insère sa revue ; seule exception, la levée admin sous condition (§ 7.5, § 11.2).
- **E10-22** — `reviewer_name` et `actor_name` = instantané du nom réel, conservés à l'anonymisation (§ 2.6).
- **E10-23** — cascade de suspension limitée aux frames `published`, levée conditionnelle, couverture 1/3/5 en garde de transition, film « incomplet » (§ 6.6, § 8.4, § 11.2).
- **E10-24** — garde de devinabilité appliquée au geste de publication (§ 8.1).
- **E10-27** — énumération de `PlatformLimits`, plafonds de recadrage compris (§ 5.2).
- **E10-59** — en-têtes du service d'image, aperçu admin compris (§ 5.8).
- **E10-60** — aucun LQIP : cadre fixe, aplat, indicateur (§ 5.5).
- **E10-61** — l'aperçu admin est le seul second lecteur du disque `frames` (§ 5.8).
- **E10-64** — vivier compté en œuvres, lu par la supervision (§ 8.6).
- **E10-67** — `10` § 15 l.1414 : la matrice des capacités passe à `20` (§ 2.2).
- **E10-68** — `10` A7 : retrait de la phrase périmée ; A7 confirmé par `20` (§ 7.6).
- **EN20-1 — exigence nouvelle, non consolidée, signalée au porteur**. (a) `10` § 4.3 : l'état `unpublished` s'applique aussi à un film ou une image **jamais publiés** (« écarté »). (b) `10` § 3.1 (colonne `first_published_at` de `movie`), § 4.1 (même colonne sur `frame`) et arbitrage A1 : « horodatage, jamais un prédicat » devient « horodatage jamais réécrit ; jamais un prédicat **de jeu ni de vivier** ». Le back-office s'en sert pour distinguer écarté (NULL) de dépublié (non NULL, § 4.2, § 7.3, § 8.4), pour borner la mesure de la passe 1 (§ 10.1) et, à la levée d'une suspension, pour choisir `draft` (E10-21, § 11.2). Aucune colonne, aucun cas d'enum, aucune migration. Sans cet amendement, `10` et cette spec se contredisent sans arbitrage.
- **EN20-3 — exigence nouvelle, non consolidée, signalée au porteur** [J2] — `10` § 8.3 : cas `user.real_name_changed` de la liste fermée `admin_action` (sujet `user`, motif facultatif, acteur admin, classe `permanent`), écrit par la correction du nom réel à l'écran de gestion des accès (§ 2.8). Refusée, le geste d'écran n'est pas livré et la correction reste la voie console du § 2.5.

## Exigences adressées aux specs sœurs et écarts aux contrats — non consolidés

Signalés au porteur ; un désaccord avec la feuille de contrats ne se corrige jamais en silence.

- **EN20-2** — `90`, contrat C16 § 2.11 : ajouter `resources/js/hooks/admin` et `resources/js/lib/admin` aux entrées `WATCHED` du J1, dès le lot L20-9a qui y crée ses premiers fichiers (§ 13.3). Sans cet ajout, la règle `[unclassified]` de la méta-vérification fait échouer `npm run check`. Le lot L20-9a modifie `scripts/check-theme-tokens.mjs` en ce sens ; `90` le ratifie ou propose un autre emplacement.
- **EN20-4** — contrat C9, invariant 3 et tableau de provenance, et C0 (`frameCropMaxWidthPercent`) : (a) la condition du plancher gagne la borne de hauteur `crop_height × 100 ≤ pct × masterHeight`, et la phrase « sur un master plus large, le changement de ratio retire déjà davantage » est retirée, parce qu'elle est fausse (§ 5.2) ; (b) les bornes [70, 90] de l'accesseur laissent une surface de 49 à 81 %, au-delà de la fourchette 60-70 % de D6 du 23/09 au-dessus de 83 : cette spec plafonne le calibrage à 83 (§ 5.2) et propose de ramener la borne haute du contrat à 83. D6 du 23/09, décision du porteur, prime sur la lettre du contrat. Sans effet sur le J1 courant (backdrops 16:9).

---

## Amendements à d'autres documents

Consommés, consolidés dans la feuille de contrats ; appliqués au corpus par un travail séparé.

- **A-02** — `00` l.97, l.171, principe 6 : aucun LQIP.
- **A-23** — `00` l.214 et l.222 : prévisualisation en conditions de jeu = `GameFrame` dans `GameThemeScope`.
- **A-24** — `00` l.215, l.326, principe 8 : chaîne de traitement réelle ; « Entrée publie » devient la passe de revue ; opérabilité clavier jamais coupée.
- **A-26** — `00` l.306 : « couvrant 1, 3 et 5 **à sa publication** ».
- **A-28** — `00` l.325 : disque `frames` en `serve => false`, route dédiée.
- **A-36** — principes 3, 5, 12 et 13 : plancher vérifiable, back-office qui suit l'apparence.
- **A-39** — `05` l.94 : le domaine `admin` porte `admin.exclusion_grid.*` ; ni `avatar` ni `curation`.
- **A-48** — `05` l.237 : formatage client du back-office.
- **A-52** — `questions-ouvertes.md` l.121, l.293, l.315, l.382 : dérivé produit par le serveur, noms `bin2hex`.
- **A-59** — `questions-ouvertes.md` l.308 : clés `….{slug}.label` et `.help`, drapeau `retroactive`.
- **A-62** — `questions-ouvertes.md` l.328 et l.330 : le back-office suit l'apparence.
- **A-65** — `questions-ouvertes.md` l.380 : retirer « support, édition ».
- **A-67** — `CLAUDE.md` §1 : au J1, le premier admin (le porteur, seul curateur) et des comptes de test jetables.
- **A-73** — `CLAUDE.md` §5 : `admin_action` avec l'auteur en **nom réel** ; back-office non forcé.
- **A-75** — `CLAUDE.md` §7, règle 9 : « couvrant 1, 3 et 5 à sa publication ».
- **A-76** — `CLAUDE.md` §8 : chaîne d'image réelle (sous réserve de R-46 pour la capture), `CURATION_CAPTURE_ENABLED=`.
- **A-80** — `REPRISE.md`, dette n° 24 : tranchée par la coquille mobile propre.

Découlant de résolutions de la pré-analyse sans amendement consolidé — **signalés, non consolidés** :

- **AN20-1** (n° 3) — `CLAUDE.md` §2 et `00` l.36, l.216 : « published dès qu'il couvre 1, 3 et 5 » décrit l'éligibilité ; la publication est un **geste explicite** du curateur.
- **AN20-2** (n° 9) — `00` l.416 : « aucun hotlink d'image » vise les surfaces joueur ; le back-office charge les visuels TMDB dans le navigateur du curateur, et `90` le dit dans la confidentialité.
- **AN20-3** (n° 11) — `00` l.217 : la file de signalement a sa **règle** dans `40` et son **écran** dans `20`.
- **AN20-4** (n° 2) — `questions-ouvertes.md` § 8 l.123 et l.125, et `00` § Jalons l.366 : la coupe préparée vise les raccourcis de débit, la bande balayable et les gestes de pointeur avancés du recadreur ; l'opérabilité clavier en sort ; le presse-papiers est sans objet tant que la capture est désactivée, et l'encodage navigateur disparaît de la voie TMDB (B10). La variable « recadreur minimal » valait donc **5 à 7 h** (lots L20-9b et L20-11), et non les 10 à 20 h qu'impliquait l'écart entre 25-35 h et 12-15 h. **Elle est sans objet depuis D35 du 23/09** : L20-9b et L20-11 sont livrés au J1, et `00` § Jalons ne l'applique plus ; seule reste vraie la frontière qu'elle traçait entre l'opérabilité, dans la barre « terminé », et le confort de débit, hors de la barre — amendé le 23/09.
- **AN20-7** — `00` § Vocabulaire et `CLAUDE.md` §6 : ligne « écarté | `set_aside` | film ou image `unpublished` jamais publiés (`first_published_at` NULL) : sorti de la file de curation sans être publié ; réversible pour un film par une première publication, pour une image par l'ajout d'une nouvelle variante » (§ 4.2, § 8.4).
- **AN20-8** — `00` § Jalons l.366 et `questions-ouvertes.md` l.83 et l.384 : le lot pilote n'est plus daté « en semaine 4 à 6 » ; il part à la livraison de tous les lots J1 de cette spec, de leurs prérequis et du socle de production minimal de `100` sans moteur (§ 10.3), dans l'ordre d'implémentation « curation d'abord » (D37 du 23/09). Sa date ne se calcule pas à partir des heures des sections « Lots », qui sont des mesures de taille et jamais un calendrier (D36 du 23/09) : `00` § Jalons la tient à partir du chemin critique humain (domaine, relevé puis montage root du VPS, premier admin, liste d'amorçage) — amendé le 23/09.
- **AN20-5** (n° 13, D10 du 23/09) — `questions-ouvertes.md` l.277 : les seuils du lot pilote sont ceux du § 10.4 ; l.58, l.76, l.385 : 130 h au lieu de 120.
- **AN20-6** — `REPRISE.md` § « À trancher par la spec 20 » : remplacée par un renvoi au § 14.

---

## Lots d'implémentation

Estimations en heures, **barre « terminé » incluse** (tests Pest et Vitest, textes du domaine `admin` — `mail` FR et EN là où il sert —, états de chargement, d'erreur et de déconnexion, parcours clavier vérifié), facteur 1,5 à 2 intégré (S3 du 23/09). Ces heures sont des **mesures de taille**, jamais un calendrier ni un budget à tenir : le développement est confié à l'IA, et l'enveloppe hebdomadaire du porteur ne le borne plus (D36 du 23/09) — amendé le 23/09. Un lot est livrable et testable seul. L'ordre suit l'ordre d'implémentation « curation d'abord » (D37 du 23/09) : les lots J1 de cette spec passent, avec leurs prérequis et le socle de production minimal de `100` sans moteur, avant le moteur de partie, pour que le lot pilote démarre le plus tôt possible pendant que le moteur se construit — amendé le 23/09. **Aucun film réel n'est curé en production avant la livraison de tous les lots J1** (L20-9b et L20-11 compris, livrés au J1 depuis D35 du 23/09 — amendé le 23/09) : le premier film réel terminé ouvre la fenêtre du lot pilote (§ 10.3), et un film curé sans l'instrumentation de L20-17 fausserait sa mesure. Avant cela, l'outil se vérifie en développement sur le catalogue de démonstration (`import_source = demo`, exclu de toute mesure) ; un film réel terminé à titre d'essai en production est sauté en fixant le `pilot.first_rank` de sa voie avant le lot.

| Lot | Jalon | Objet | Dépend de | Heures | Variable d'ajustement |
|---|---|---|---|---|---|
| L20-1 | J1 | Journal, nom réel, premier admin | — | 3-5 | — |
| L20-2 | J1 | Porte `/admin` : 2FA, apparence (D8 du 23/09), pied, erreur, coquille mobile, messages sans commande | L20-1 ; L90-1 (retraits de C16 § 2.2) ; L90-4 | 4-6 | — |
| L20-3 | J1 | Matrice des capacités, policies, famille de tests | L20-2 | 2-4 | — |
| L20-4 | J1 | Géométrie 16:9 et plancher à double borne | L50-1 ; L90-1 ; L100-3 | 2-4 | — |
| L20-5 | J1 | Chaîne Imagick, job de traitement, configuration de curation | L20-4 | 6-8 | — |
| L20-6 | J1 | Aperçu admin (C9-bis) | L20-5 | 1-2 | — |
| L20-7 | J1 | Ajout depuis TMDB, dédoublonnage ; route capture refusante | L20-5, L20-3 | 4-5 | — |
| L20-8 | J1 | Re-recadrage, relance, niveau, dépublication d'image, avertissement de couverture | L20-7, L20-1 ; L30-1 | 4-6 | — |
| L20-9a | J1 | Recadreur : cadre, boutons, opérabilité clavier, périmètre `WATCHED` | L20-4 ; L90-1 ; L100-3 | 4-5 | — |
| L20-9b | J1 | Recadreur : poignées d'angle, molette, pincement | L20-9a | 2-3 | — (« recadreur minimal » sans objet, D35 du 23/09) |
| L20-10 | J1 | Éditeur de la banque d'images | L20-7, L20-8, L20-9a ; L90-6a (`GameFrame`, `GameThemeScope`) ; L30-1 | 6-8 | — |
| L20-11 | J1 | Raccourcis de débit et bande balayable | L20-10, L20-12 ; L100-3 | 3-4 | — (« recadreur minimal » sans objet, D35 du 23/09) |
| L20-12 | J1 | Grille v1 et passe de revue | L20-6, L20-8, L20-10, L20-1 ; L90-6a (`GameFrame`, `GameThemeScope`) | 7-9 | — |
| L20-13 | J1 | Publication d'un film, écarter, contenu vérifié, avertissement d'ambiguïté | L20-12, L20-1 ; L70-2 (`subtitle`, C12) | 5-7 | — |
| L20-14 | J1 | Titres, alias, `movie_group` manuel | L20-13 | 3-5 | — |
| L20-15 | J1 | File de curation, fiche, tableau de bord et supervision | L20-13 ; L30-3 | 3-5 | — |
| L20-16 | J1 | Import : recherche, liste d'amorçage, aperçu à blanc | L20-2 | 4-6 | — |
| L20-17 | J1 | Débit et verdict du pilote | L20-10, L20-13, L20-15 ; L100-3 | 5-7 | nombre de films (D10 du 23/09), variable de curation |
| L20-18 | J1 | Page « premiers pas du curateur » | L20-12 | 1-2 | — |
| L20-19 | J2 | Écran de gestion des accès, correction du nom réel | L20-3 ; EN20-3 pour la correction | 6-8 | — |
| L20-20 | J2 | Suspension conservatoire et levée | L20-13 ; C8 J2 de `60` | 6-8 | — |
| L20-21 | J2 | Retrait juridique, suppression des fichiers, `takedown:reconcile` | L20-20 ; L30-10 (`DeriveMovieDifficulty`) | 5-7 | — |
| L20-22 | J2 | File des demandes de retrait et décision | L20-20, L20-21 ; `takedown.store` de `90` | 5-7 | — |
| L20-23 | J2 | Messages FR/EN au demandeur | L20-22 | 3-4 | — |
| L20-24 | J2 | Resynchronisation à l'écran, clore un balayage, limiteur TMDB partagé | L20-16 | 6-8 | — |
| L20-25 | J2 | Geste rétroactif de grille | L20-12, L20-8, L20-1 | 2-4 | — |
| L20-26 | J2 | Quasi-justes et rapport de collisions | L20-14 ; `near_miss` J2 de `70` | 3-5 | — |
| L20-27 | J2 | Candidats `movie_group` par distance et collection | L20-14 | 2-3 | — |
| L20-28 | J2 | Thèmes : ajout d'une saga, libellés, correction de règle, publication sous seuil, appartenance, difficulté | L20-3 ; L30-8 (`ThemeEvaluator`, `SyncThemeMembership`) ; L30-10 (`DeriveMovieDifficulty`) ; L30-11 (`themeWorks()`) | 7-10 | — |
| L20-29 | J2 | Films jamais trouvés et incidents | L20-15 ; parties réelles | 2-3 | — |
| L20-30 | J2 | Inspecter une partie | L20-3 ; `ScoreReplayer::mismatches()` de `80` | 4-6 | — |
| L20-31 | J2 | Modération des pseudos et copies provider | L20-3 ; lot J2 de `40` | 3-5 | — |
| L20-32 | J2 | Réservation souple multi-curateurs | L20-15, L20-17 | 2-3 | — |
| L20-33 | J2 | Voie capture | L20-7, L20-9a ; **arbitrage de licéité** | 6-8 | conditionnel |

**Taille J1 : 69 à 101 h**, tous lots livrés au J1 (D35 du 23/09). **Taille J2 : 62 à 89 h**, dont 6 à 8 h conditionnées à l'arbitrage de la capture (L20-19 porte en plus la correction du nom réel, conditionnée à EN20-3). Ce sont des mesures de taille, jamais un calendrier ni un budget à tenir (D36 du 23/09). Hors de ces tailles : la réserve de re-livraison après le pilote (environ 6 h de taille, `questions-ouvertes.md` § 8) et la constitution de la liste d'amorçage (4 à 6 h de travail produit du porteur, sur le chemin critique humain du J1, § 3.5). `00` § Jalons agrège les tailles des sections « Lots » de toutes les specs ; il n'en déduit aucune date — amendé le 23/09.

- **Ancienne variable « recadreur minimal »** (AN20-4) : elle valait **5 à 7 h**, soit L20-9b (2-3 h) et L20-11 (3-4 h), après réduction de la coupe de `questions-ouvertes.md` § 8 (25-35 h contre 12-15 h) — l'opérabilité clavier en sortait (n° 2), le presse-papiers est sans objet au J1, l'encodage navigateur disparaît de la voie TMDB (B10). **Elle est sans objet depuis D35 du 23/09** : les deux lots sont livrés au J1 et `00` § Jalons ne l'applique plus. Ils restent séparés de L20-9a parce que l'opérabilité exigée par le principe 8 est complète par les boutons et le clavier de L20-9a : ce découpage marque la frontière de la barre « terminé », et non plus une coupe — amendé le 23/09.
- **Écart à `00` § Jalons** : 69 à 101 h ici, contre 30 à 40 h attribuées au « back-office import + recadreur intégré » (25-35 h) et au « premier admin et policies minimales » (5 h). Le périmètre du J1 s'est élargi (journal et nom réel, porte 2FA, aperçu à blanc, liste d'amorçage, revue et grille versionnée, avertissement d'ambiguïté, instrumentation et verdict du pilote, chaîne Imagick serveur) ; cet écart de taille est l'entrée de la relecture de `00` § Jalons, qui ne le lit plus contre une enveloppe de développement (D36 du 23/09, AN20-8) — amendé le 23/09.

### L20-1 — Journal, nom réel, premier admin [J1]

- **Fichiers** : migration `add_real_name_to_users` ; `app/Enums/AdminActionType.php` et `AdminActionSubject.php` [modifiés] ; `app/Models/AdminAction.php` (`CONSOLE_ACTOR`, gardes) ; `app/Support/Admin/AdminJournal.php` [nouveau] ; `app/Concerns/RealNameValidationRules.php` [nouveau] ; `app/Models/User.php` (garde `saving`, `#[Hidden]`) ; `app/Console/Commands/FirstAdminCommand.php` ; fabriques `UserFactory`, `FrameReviewFactory`, `AdminActionFactory` ; seeders `DemoAccountsSeeder`, `DemoCatalogueSeeder` ; `lang/fr/admin.php` (`enum.admin_action.*`, `enum.admin_action_subject.*`, `validation.real_name`, `console.first_admin.{real_name_prompt, real_name_required, real_name_updated}`).
- **Tests** : `tests/Feature/Admin/AdminActionTypeTest.php` — « la liste fermée compte exactement vingt et un cas » ; « tout sujet movie, frame, takedown_request ou site est permanent » ; « system n'est accepté que pour avatar.hidden et nickname.masked » ; « console n'est accepté que pour role.changed, site.closed et site.reopened, avec actor_id nul » ; « un geste non automatique exige un actor_id » ; « un motif vide est refusé quand l'action l'exige » ; « subject_type est dérivé de l'action » ; « AdminJournal refuse d'écrire hors transaction » ; « chaque cas a sa clé admin.enum.admin_action ». `tests/Feature/Admin/RealNameTest.php` — « un compte ne peut porter curator ou admin sans nom réel » ; « system et console sont refusés comme nom réel » ; « real_name n'est jamais sérialisé » ; « actor_name et reviewer_name figent le nom réel à l'instant du geste » ; « l'anonymisation vide real_name et conserve les instantanés de revue et de journal » ; « les fabriques curator et admin produisent un compte valide sous la garde ». `tests/Feature/Admin/FirstAdminCommandTest.php` [existant, étendu] — « la commande exige un nom réel et le pose sur le compte » ; « la ligne role.changed porte console et un actor_id nul » ; « relancer la commande sur l'administrateur en place avec un autre nom réel le corrige sans écrire role.changed ».
- **Heures** : 3-5.

### L20-2 — Porte `/admin` [J1]

- **Fichiers** : `app/Http/Middleware/EnsurePrivilegedTwoFactor.php` [nouveau] ; `app/Http/Controllers/Admin/TwoFactorRequiredController.php` ; `bootstrap/app.php` (alias `admin.2fa` et sa priorité) ; `routes/admin.php` (ajout d'`admin.2fa` au groupe du § 2.3 et sa description dans le docblock du groupe) ; `resources/js/components/admin/admin-sidebar.tsx` (coquille mobile, fermeture propre du § 13.4) ; `resources/js/components/admin/admin-footer.tsx`, `resources/js/pages/admin/two-factor-required.tsx`, `resources/js/pages/admin/error.tsx` [nouveaux] ; `lang/fr/admin.php` (`two_factor.*`, `footer.*`, `a11y.nav_mobile*`, `a11y.close`, `error.http.*`, messages réécrits du § 13.1) ; `UserFactory` (`withTwoFactor()` par défaut pour les privilégiés, état `withoutTwoFactor()`). Le retrait du forçage clair — `ForceAdminAppearance`, l'alias `admin.appearance` dans `bootstrap/app.php` et `routes/admin.php`, `useForcedAppearance('light')` d'`admin-layout.tsx` — n'est **pas** écrit par ce lot : il appartient à L90-1, écrivain unique des retraits de C16 § 2.2 (`90` § 2.2).
- **Dépendances** : L20-1 ; L90-1, qui porte seul les retraits de C16 § 2.2, pour que la porte du § 2.3 s'écrive sur un groupe déjà débarrassé d'`admin.appearance` ; L90-4 (routes légales vers lesquelles pointe le pied).
- **Tests** : `tests/Feature/Admin/TwoFactorGateTest.php` — « un curateur sans double authentification confirmée est renvoyé vers l'écran d'enrôlement » ; « un administrateur sans double authentification confirmée est renvoyé vers l'écran d'enrôlement » ; « un compte privilégié à double authentification confirmée passe la porte » ; « un joueur reçoit 403 avant toute redirection d'enrôlement » ; « l'écran d'enrôlement reste accessible sans double authentification et mène à la sécurité du compte » ; « une écriture interceptée n'est jamais exécutée ». `tests/Feature/Admin/CuratorMessagesTest.php` — « aucun texte du domaine admin affiché à un curateur ne nomme la console, un worker, une commande ni une variable d'environnement ». `tests/Feature/Public/SiteFooterTest.php` (fichier de `90`, test ajouté par ce lot, dépendance inversée : L90-3 précède ce lot et ne peut l'écrire avant que le pied admin existe) › « envoie au back-office les clés du pied admin et aucune clé legal ». La preuve « laisse le back-office suivre l'apparence du visiteur » vit dans `tests/Feature/Public/ShellTest.php` de `90` (R-04), comme celle de la fermeture propre des feuilles et boîtes de dialogue (L90-7).
- **Heures** : 4-6.

### L20-3 — Matrice, policies, famille de tests [J1]

- **Fichiers** : `app/Policies/MoviePolicy.php` [modifiée], `FramePolicy.php`, `FrameReviewPolicy.php` [nouvelles] ; `tests/Datasets/AdminRoutes.php` [nouveau] ; routes nommées du § 2.2 déclarées au fil des lots.
- **Tests** : `tests/Feature/Admin/AuthorizationMatrixTest.php` — « chaque route du back-office répond selon la matrice pour un invité, un joueur, un curateur et un administrateur » ; « le jeu de données couvre exactement les routes admin enregistrées » ; « toute route admin porte une garde can hors l'écran d'enrôlement et la page de premiers pas » ; « aucune policy ne supprime un film, une image, une revue, un balayage ni une ligne de journal ». `tests/Feature/Curation/FrameTmdbStoreTest.php` › « la garde d'ajout passe par FramePolicy et jamais par MoviePolicy::create » (C9).
- **Heures** : 2-4.

### L20-4 — Géométrie 16:9 et plancher [J1]

- **Fichiers** : `app/Support/Frames/FrameGeometry.php`, `CropRect.php`, `CropViolation.php`, `WebpPadding.php` [nouveaux] ; `app/Support/Frames/FrameStoragePrefix.php` (délégation) ; `resources/js/lib/frame-geometry.ts` [nouveau] ; `resources/css/app.css` (`--aspect-frame`) ; `lang/fr/admin.php` (`validation.crop.*`). Ce lot ne touche pas `app/Settings/PlatformLimits.php` : ses deux accesseurs de recadrage viennent de L50-1.
- **Tests** : `tests/Feature/Curation/FrameGeometryTest.php` — « le ratio de jeu est 16:9 et le dérivé mesure 1280 × 720 » ; « le plafond d'encodage est le plus grand multiple de 8 192 sous 153 600 » ; « masterHeightFor est entier et déterministe » ; « FrameStoragePrefix délègue ses plafonds à FrameGeometry » ; « frame-geometry.ts, le jeton --aspect-frame et la prop frameFormat reflètent FrameGeometry » — ce lot en écrit la partie `frame-geometry.ts` et `--aspect-frame` ; l'assertion sur `frameFormat` est ajoutée au même test par L90-6a, qui crée la prop (C16 § 2.5, dépendance inversée : L90-6a dépend de ce lot). `tests/Feature/Curation/CropRectangleTest.php` — « un cadre hors 16:9 exact est refusé » ; « un cadre plus large que le plancher est refusé » ; « un cadre plus étroit que la largeur minimale est refusé » ; « un cadre qui déborde du master est refusé » ; « le cadre par défaut est le plus grand cadre admis, centré » ; « le plancher est lu dans PlatformLimits et suit la configuration » ; « sur un master plus large que 16:9, un cadre plus haut que la fraction admise de la hauteur est refusé » ; « la surface couverte ne dépasse jamais le carré de la fraction configurée, quel que soit le ratio du master ». La garde de bornes de `PlatformLimits` est prouvée par `PlatformLimitsTest` de `50` (R-04). Vitest `tests/Frontend/admin/frame-geometry.test.ts` — « le miroir client refuse les mêmes cadres que FrameGeometry ».
- **Dépendances** : L50-1 (accesseurs de la famille « curation » de `PlatformLimits`, `50` § 2.3, C0, R-07) ; L90-1 (inscription de `lib/frame-geometry.ts` au périmètre `WATCHED`, R-36 ; sans elle, la règle `[unclassified]` de L100-2 refuse le fichier) ; L100-3 (Vitest : bloc `test` de `vite.config.ts`, environnement `node`, C18 § 2.4).
- **Heures** : 2-4.

### L20-5 — Chaîne Imagick et job de traitement [J1]

- **Fichiers** : `app/Support/Frames/FrameImageProcessor.php`, `FrameProcessingException.php` [nouveaux] ; `app/Enums/FrameProcessingFailure.php` [nouveau] ; `app/Jobs/Curation/ProcessFrameImage.php` [nouveau] ; `app/Models/Frame.php` (cast) ; `config/catalog.php` (bloc `curation` : `capture_enabled`, `tmdb_original_max_kilobytes`, `crop_seconds_max`, `webp.*`, `imagick.*`, avec les défauts du § 13.7) ; `.env.example` (`CURATION_CAPTURE_ENABLED=`) ; `FrameFactory` (dérivé 1280×720 paddé, master 1920×1080, rectangle par défaut, `processingFailed(FrameProcessingFailure)`), `DemoCatalogueSeeder` ; `AdminCatalogPresenter::movieFrame()` ; `lang/fr/admin.php` (`frame.processing_error.*`).
- **Tests** : `tests/Feature/Curation/ProcessFrameImageTest.php` — « le dérivé est un WebP statique de 1280 × 720 sans métadonnées » ; « game_bytes est un multiple de 8 192 et ne dépasse jamais 147 456 » ; « le padding ne casse pas le décodage » ; « published_hash est l'empreinte des octets paddés » ; « un WebP animé échoue en source_animated » ; « une source de moins de 1 280 px échoue en source_too_small » ; « le job refuse une frame withdrawn sans écrire de fichier » ; « le job refuse de réécrire une frame published » ; « chaque échec pose failed et une clé FrameProcessingFailure » ; « Relancer n'est offert qu'à un échec rejouable » ; « chaque traitement écrit un nouveau game_path et supprime l'ancien » ; « le job part sur default et jamais sur game » ; « Imagick sait encoder le WebP » ; « un échec définitif au premier traitement ne laisse aucun original sur le disque » ; « un échec rejouable garde les octets que Relancer réutilise ». `tests/Feature/Curation/CurationConfigTest.php` — « les bornes croisées de la configuration de curation tiennent » (qualités WebP, limites Imagick contre `--timeout` et `MemoryMax` du worker `default`, `crop_seconds_max` sous 65 535). `tests/Feature/Curation/FrameProbesTest.php` — « aucune frame published sans published_review_id » ; « le published_hash de toute frame published égale le reviewed_hash de sa revue » ; « aucune frame ready hors 1280 × 720 ou hors padding » ; « la requête d'audit du plancher ne renvoie aucune frame ».
- **Heures** : 6-8.

### L20-6 — Aperçu admin [J1]

- **Fichiers** : `app/Support/Frames/FrameImageResponse.php`, `app/Http/Controllers/Admin/FrameImageController.php` [nouveaux] ; `routes/admin.php`.
- **Tests** : `tests/Feature/Curation/FrameImagePreviewTest.php` — « un joueur reçoit 403 et un invité est redirigé » ; « un curateur reçoit le dérivé avec no-store, noindex et nosniff » ; « l'aperçu ne pose ni Content-Disposition, ni Last-Modified, ni ETag » ; « une frame d'un autre film répond 404 » ; « une frame withdrawn répond 404 » ; « le master n'est servi que par admin.catalog.frames.master » ; « aucune route hors admin ne sert le préfixe master/ ».
- **Heures** : 1-2.

### L20-7 — Ajout depuis TMDB ; route capture refusante [J1]

- **Fichiers** : `app/Support/Tmdb/TmdbClient.php` (`downloadImage`) ; `app/Actions/Curation/AddFrame.php` (`fromTmdb` seul, dédoublonnage sous `lockForUpdate`) ; `app/Http/Controllers/Admin/FrameTmdbController.php` (refus des dimensions avant téléchargement, double borne) ; `app/Http/Controllers/Admin/FrameCaptureController.php` (**refus seul**, par `FramePolicy::createFromCapture` → `Response::deny('admin.frame.capture.disabled')`) ; `app/Http/Requests/Admin/FrameTmdbStoreRequest.php` ; `app/Providers/FortifyServiceProvider.php` (limiteur `admin-frame`, valeur `catalog.curation.rate_limits.frame`) ; `config/catalog.php` (`curation.rate_limits.frame`) ; `lang/fr/admin.php` (`frame.tmdb.*` dont `duplicate`, `frame.capture.{disabled, disabled_notice}`, `frame.flash.*`, `validation.frame_source.dimensions`, `validation.crop.*`). `FrameCaptureStoreRequest` et `AddFrame::fromCapture` relèvent de L20-33.
- **Tests** : `tests/Feature/Curation/FrameTmdbStoreTest.php` — « un visuel absent des backdrops du film est refusé » ; « source_hash est le SHA-256 des octets originaux téléchargés » ; « la frame naît draft et pending et le job part sur la file default après commit » ; « un échec de téléchargement ne crée aucune frame » ; « la réponse ne contient ni chemin disque ni hash » ; « un visuel trop étroit ou en portrait est refusé avant tout téléchargement » ; « un double envoi du même cadre ne crée qu'une frame » ; « une même source recadrée autrement crée une seconde variante ». `tests/Feature/Curation/FrameCaptureStoreTest.php` — « la voie capture désactivée refuse côté serveur » (seul test de ce fichier au J1). `tests/Feature/Tmdb/TmdbClientTest.php` [existant, étendu] — « le téléchargement d'un original refuse un fichier plus lourd que le plafond configuré ».
- **Heures** : 4-5 (la branche capture sortie, le dédoublonnage et le refus préalable entrés).

### L20-8 — Re-recadrage, relance, niveau, dépublication d'image [J1]

- **Fichiers** : `app/Actions/Curation/RecropFrame.php`, `RetryFrameProcessing.php`, `ChangeFrameLevel.php`, `UnpublishFrame.php` ; contrôleurs `FrameCropController`, `FrameRetryController`, `FrameLevelController`, `FrameUnpublishController` ; requêtes `FrameCropUpdateRequest`, `FrameLevelUpdateRequest`, `FrameUnpublishRequest` ; `app/Policies/FramePolicy.php` (`update` sans condition d'état) ; `app/Support/Curation/CoverageLossPreview.php` [nouveau] (calcul de `k` du § 8.4 par `FrameLevelCoverage::select`, prop `unpublish_preview`) ; `routes/admin.php` : limiteur `admin-frame` (créé par L20-7) sur re-recadrer et relancer (C9 § 2), `admin-curation` sur changer le niveau et dépublier ; `app/Providers/FortifyServiceProvider.php` (limiteur `admin-curation` [nouveau], valeur `catalog.curation.rate_limits.curation`) ; `config/catalog.php` (`curation.rate_limits.curation`) ; `lang/fr/admin.php` (`frame.recrop.*`, `frame.retry.*`, `frame.level.{locked, default_reason}`, `frame.unpublish.{coverage_warning, coverage_warning_unplayable}`).
- **Tests** : `tests/Feature/Curation/FrameRecropTest.php` — « recadrer une frame publiée la sort de published et écrit frame.unpublished dans la même transaction » ; « une frame recadrée ne revient en jeu qu'après une revue sur ses nouveaux octets » ; « un recadrage d'une frame en traitement est refusé comme occupé » ; « recadrer une frame suspendue ou retirée est refusé par une erreur traduite et jamais par un 403 » ; « recadrer une frame publiée qui porte seule un niveau 1, 3 ou 5 avertit avant confirmation » ; « un re-recadrage ne réécrit jamais un crop_seconds déjà posé ». `tests/Feature/Curation/FrameLevelTest.php` — « changer le niveau d'une frame publiée la renvoie en revue et recalcule la couverture dans la même transaction » ; « changer le niveau d'une frame non publiée ne touche pas sa disponibilité » ; « le motif écrit par le serveur est un texte et jamais une clé de traduction ». `tests/Feature/Curation/FrameUnpublishTest.php` — « dépublier une image écrit frame.unpublished et recalcule la projection » ; « un film publié qui perd sa couverture 1-3-5 reste publié et se signale incomplet » ; « écarter une image jamais publiée la passe unpublished sans jamais la supprimer » ; « l'avertissement de couverture nomme le plus grand N encore jouable ».
- **Heures** : 4-6.

### L20-9a — Recadreur : cadre, boutons, clavier [J1]

- **Fichiers** : `resources/js/components/admin/frame-cropper.tsx`, `level-picker.tsx` [nouveaux] ; `resources/js/hooks/admin/use-cropper-keyboard.ts`, `resources/js/lib/admin/crop-state.ts` [nouveaux] ; `scripts/check-theme-tokens.mjs` (ajout de `resources/js/hooks/admin` et `resources/js/lib/admin` à `WATCHED`, EN20-2) ; `lang/fr/admin.php` (`cropper.*` dont `image_alt` et `region_label`, `level.{1..5}.{label, guide}`). `level-picker.tsx` compose `radio-group`, que ce lot n'installe pas.
- **Tests** : Vitest `tests/Frontend/admin/crop-state.test.ts` — « les flèches déplacent le cadre d'un pas et Maj de quatre pas » ; « élargir ou resserrer garde le 16:9 et un multiple de 16 » ; « le cadre ne dépasse jamais le plancher ni ne sort du master » ; « la touche Origine rétablit le cadre par défaut » ; « le temps de recadrage court de l'ouverture du visuel à l'envoi ». `npm run check` passe : les deux répertoires nouveaux sont classés `WATCHED` (méta-vérification de C16 § 2.11). Parcours clavier vérifié à la main et consigné (barre « terminé »).
- **Dépendances** : L20-4 ; L90-1 (installe `radio-group`, C16 § 2.9) ; L100-3 (Vitest, C18 § 2.4).
- **Heures** : 4-5.

### L20-9b — Recadreur : gestes de pointeur avancés [J1]

- **Fichiers** : `frame-cropper.tsx` (poignées d'angle, molette, pincement) ; `resources/js/lib/admin/crop-state.ts` (redimensionnement par poignée, fonction pure).
- **Tests** : Vitest `tests/Frontend/admin/crop-state.test.ts` — « une poignée d'angle garde le 16:9, un multiple de 16 et les deux bornes du plancher ».
- **Heures** : 2-3. **Variable d'ajustement** : aucune depuis D35 du 23/09 (« recadreur minimal » sans objet) ; lot livré au J1. Les boutons et le clavier de L20-9a couvrent déjà les mêmes gestes : ce lot n'ajoute que le confort à la souris, hors de la barre « terminé » — amendé le 23/09.

### L20-10 — Éditeur de la banque d'images [J1]

- **Fichiers** : `app/Http/Controllers/Admin/FrameBankController.php` ; `resources/js/pages/admin/catalog/bank.tsx` ; `resources/js/components/admin/backdrop-grid.tsx`, `frame-bank-list.tsx`, `coverage-meter.tsx`, `game-conditions-preview.tsx` [nouveaux] ; `resources/js/types/admin.ts` (`AdminMovieFrame` étendu, `AdminFrameLimits`, `AdminBackdrop` avec `used_levels`, `AdminSequencePreview`, `AdminUnpublishPreview`) ; `config/catalog.php` (`curation.images_cache_minutes`, `poll_seconds`, `stale_pending_minutes`) ; `lang/fr/admin.php` (`bank.*` dont `backdrop_alt`, `backdrop_used`, `processing_stalled` et `desktop_required`, `frame.preview.*`, `tmdb.error.rate_limited_interactive`) ; `resources/js/pages/admin/catalog/show.tsx` (lien, retrait d'`editor_pending`).
- **Tests** : `tests/Feature/Curation/FrameBankTest.php` — « l'éditeur rend le film, sa banque, ses plafonds et ses séquences par N sans jamais attendre TMDB » ; « les visuels proposés sont les seuls backdrops, sans texte d'abord » ; « un visuel trop étroit ou en portrait est proposé désactivé avec son motif » ; « une panne de TMDB donne un état traduit et un bouton réessayer » ; « un 429 à l'ouverture de l'éditeur donne un message traduit et Réessayer » ; « une frame en attente au-delà du délai affiche l'état traitement en panne » ; « un visuel déjà utilisé porte les niveaux des frames qui en proviennent » ; « les séquences par N suivent FrameLevelCoverage et signalent le repli » ; « l'éditeur est refusé sur un film retiré » ; « aucune prop de l'éditeur ne porte un chemin disque ni une empreinte ».
- **Heures** : 6-8.

### L20-11 — Raccourcis de débit et bande balayable [J1]

- **Fichiers** : `resources/js/lib/admin/shortcut-map.ts` [nouveau] (fonction pure (touche, cible, contexte) → action, testée sans DOM, C18 § 2.4 ; le hook ne fait que la brancher) ; `resources/js/hooks/admin/use-throughput-shortcuts.ts` [nouveau] ; `resources/js/lib/admin/backdrop-strip.ts` [nouveau] (visuel suivant ou précédent non utilisé, fonction pure) ; `resources/js/components/admin/backdrop-strip.tsx` [nouveau] ; `frame-cropper.tsx`, `review-panel.tsx`, `pages/admin/catalog/bank.tsx` ; `lang/fr/admin.php` (`shortcuts.*`, `bank.strip.*`).
- **Tests** : Vitest `tests/Frontend/admin/throughput-shortcuts.test.ts` — « les touches 1 à 5 classent et envoient depuis le cadre » ; « aucun raccourci n'agit dans un champ de saisie » (la cible est un paramètre de la fonction pure, sans DOM) ; « la touche Entrée vaut conforme, publier dans la passe de revue ». Vitest `tests/Frontend/admin/backdrop-strip.test.ts` — « la bande passe au visuel suivant non utilisé après un envoi » ; « les touches crochets passent au visuel voisin sans quitter le cadre ».
- **Dépendances** : L20-10, L20-12 ; L100-3 (Vitest).
- **Heures** : 3-4. **Variable d'ajustement** : aucune depuis D35 du 23/09 (« recadreur minimal » sans objet) ; lot livré au J1, **avant** le lot pilote, dont la mesure porte ainsi sur le débit de l'outil définitif (§ 10.3). L'outil reste intégralement opérable au clavier sans lui, ce qui le laisse hors de la barre « terminé » — amendé le 23/09.

### L20-12 — Grille v1 et passe de revue [J1]

- **Fichiers** : `app/Support/Curation/ExclusionGrid.php` [structure modifiée] ; `app/Actions/Curation/ReviewFrame.php` ; `app/Http/Controllers/Admin/FrameReviewController.php`, `FrameReviewQueueController.php` ; `app/Http/Requests/Admin/FrameReviewStoreRequest.php` ; `app/Policies/FrameReviewPolicy.php` ; `app/Support/Curation/ReviewQueue.php` [nouveau] (les trois prédicats du § 7.3, relus sous verrou par `ReviewFrame`) ; `resources/js/pages/admin/review/index.tsx`, `resources/js/components/admin/review-panel.tsx` ; `lang/fr/admin.php` (`exclusion_grid.v1.*`, `review.*` dont `level_changed` et `already_reviewed`).
- **Tests** : `tests/Feature/Curation/ExclusionGridTest.php` — « chaque item de chaque version a ses clés label et help dans lang/fr/admin.php » ; « les clés vivent sous admin.exclusion_grid » ; « l'empreinte de la version 1 est figée » ; « la version 1 n'est pas rétroactive » ; « retroactiveLevels ne rend que les niveaux des items ajoutés » ; « decisionFor exige exactement les items applicables ». `tests/Feature/Curation/FrameReviewTest.php` — « une revue passante publie dans la même transaction et pointe published_review_id » ; « une empreinte périmée est refusée » ; « une version de grille périmée est refusée » ; « la décision est dérivée côté serveur » ; « frame_review n'est ni modifiable ni supprimable » ; « toute publication de frame insère sa propre ligne de revue » ; « reviewer_name est le nom réel » ; « une revue envoyée après un changement de niveau est refusée sans écrire de preuve » ; « une seconde revue passante sur les mêmes octets est refusée » ; « un double rejet n'écrit qu'une preuve ». `tests/Feature/Curation/ReviewQueueTest.php` — « la file à revoir ne montre que les images prêtes sans revue depuis leur dernier changement d'état » ; « une image publiée changée de niveau réapparaît à revoir » ; « une image dépubliée se republie par la file » ; « une image écartée n'apparaît jamais à revoir » ; « une revue rejetée range l'image dans les rejetées, d'où elle peut être revue » ; « la file à re-revoir ne montre que les images publiées sous une version antérieure » ; « une image suspendue ou d'un film retiré n'est jamais proposée » ; « la source déclarée affichée est tmdb_file_path ou le timecode ».
- **Dépendances** : L20-6, L20-8 (dépublication d'une image après un rejet, § 7.5), L20-10 (`game-conditions-preview`, § 7.4), L20-1 ; L90-6a (`GameFrame`, `GameThemeScope`).
- **Heures** : 7-9.

### L20-13 — Publication d'un film, écarter, contenu vérifié, ambiguïté [J1]

- **Fichiers** : `app/Actions/Curation/PublishMovie.php`, `UnpublishMovie.php`, `VerifyMovieContent.php` ; `app/Support/Catalog/AmbiguityPreview.php` [nouveau] ; contrôleurs `MoviePublishController`, `MovieUnpublishController`, `MovieContentVerifiedController` ; requêtes associées ; `resources/js/components/admin/publish-dialog.tsx`, `reason-dialog.tsx` (fermeture propre sur `admin.a11y.close`, § 13.4) ; `lang/fr/admin.php` (`movie.publish.*` dont `preview.none` et `preview.line`, `movie.unpublish.*`, `movie.set_aside.*`, `movie.content_verified.*`).
- **Tests** : `tests/Feature/Catalog/MoviePublicationTest.php` — « un film couvrant 1, 2 et 4 ne peut pas être publié » ; « un film au contenu non vérifié ou bloqué ne peut pas être publié » ; « un film sans aucune clé exacte non vide ne peut pas être publié » ; « la première publication écrit movie.published, pose first_published_at et curated_by_id, puis ne les réécrit jamais » ; « une republication écrit movie.republished » ; « la publication recalcule l'ambiguïté des formes du film dans la même transaction » ; « dépublier exige un motif, écrit movie.unpublished et laisse les images publiées » ; « écarter un brouillon le passe unpublished sans first_published_at ». `tests/Feature/Catalog/AmbiguityPreviewTest.php` — « l'aperçu nomme les préfixes et sous-titres que la publication rend ambigus et leurs films » ; « l'aperçu n'écrit rien » ; « un aperçu périmé fait refuser la publication » ; « l'ambiguïté se mesure sur le catalogue publié entier et jamais sur un vivier ». `tests/Feature/Catalog/ContentVerifiedTest.php` — « cocher le contenu vérifié exige un motif et écrit movie.content_verified dans la même transaction » ; « un contenu bloqué ne se lève par aucun geste ».
- **Heures** : 5-7.

### L20-14 — Titres, alias, `movie_group` manuel [J1]

- **Fichiers** : `app/Actions/Curation/SaveMovieTitle.php`, `DeleteMovieTitle.php`, `AddAlias.php`, `DeleteAlias.php`, `SetMovieGroup.php` ; contrôleurs `MovieTitleController`, `MovieAliasController`, `MovieGroupController` ; requêtes associées ; `resources/js/pages/admin/catalog/show.tsx` (blocs éditables) ; `lang/fr/admin.php` (`titles.*`, `aliases.*`, `group.*`).
- **Tests** : `tests/Feature/Catalog/MovieTitleTest.php` — « corriger un titre l'écrit en origin curator et reprojette answer_key dans la même transaction » ; « une ligne curator survit à la resynchronisation » ; « aucun titre n'est recopié d'une langue à l'autre » ; « seule une ligne curator se retire ». `tests/Feature/Catalog/AliasTest.php` — « ajouter un alias crée une clé exacte et jamais une forme dérivée » ; « retirer un alias TMDB supprime sa clé » ; « un alias d'une locale non activée est refusé ». `tests/Feature/Catalog/MovieGroupTest.php` — « regrouper deux films crée un groupe manuel et les y rattache » ; « un groupe réduit à un film disparaît » ; « les candidats exacts sont les films au titre normalisé identique » ; « le libellé pré-rempli de deux titres longs est tronqué à cent vingt caractères ».
- **Heures** : 3-5.

### L20-15 — File de curation, fiche, tableau de bord [J1]

- **Fichiers** : `app/Support/Curation/CurationQueue.php` [nouveau] ; `app/Http/Controllers/Admin/CurationQueueController.php` ; `DashboardController.php` (supervision par `PoolReporter`, compteurs) ; `CatalogIndexRequest.php` (`RoomSettingsBounds`, filtres `missing_title`, incomplet, écarté) ; `resources/js/pages/admin/curation/index.tsx`, `dashboard.tsx`, `catalog/index.tsx` ; `lang/fr/admin.php` (`curation.*` dont `empty`, `dashboard.pool.*` reformulés).
- **Tests** : `tests/Feature/Admin/DashboardTest.php` [existant, étendu] — « la supervision par N est celle du constructeur unique et lit ses bornes dans RoomSettingsBounds » (C2) ; « le tableau de bord compte les films prêts à publier, incomplets et écartés ». `tests/Feature/Admin/CurationQueueTest.php` — « la file montre d'abord les films entamés puis les autres par votes décroissants » ; « ajouter, recadrer ou revoir une image remonte son film dans la file » ; « la file exclut le catalogue de démonstration » ; « film suivant mène au premier film de la file autre que le courant » ; « film suivant sur une file vide ramène à la file avec un message traduit » ; « les filtres par voie servent la composition du pilote ». `tests/Feature/Admin/CatalogTest.php` [existant, étendu] — « le filtre des titres manquants lit title_locale_mask à la version courante ».
- **Heures** : 3-5.

### L20-16 — Import : recherche, liste d'amorçage, aperçu à blanc [J1]

- **Fichiers** : `app/Support/Tmdb/TmdbClient.php` (`search`) ; `app/Console/Commands/CatalogImportIdsCommand.php` (`--preview`, simulation sans `import_run`) ; `app/Jobs/Catalog/PreviewCatalogPaste.php`, `app/Support/Admin/PastePreview.php` [nouveaux] ; `app/Http/Controllers/Admin/ImportController.php` (présentateur de props partagé), `ImportSearchController.php`, `ImportPreviewController.php`, `ImportSeedListController.php` [nouveaux sauf le premier] ; `app/Providers/FortifyServiceProvider.php` (limiteur `admin-tmdb-search`, valeur `catalog.curation.rate_limits.search`) ; `routes/admin.php` (`admin.import.search`, `admin.import.preview`, `admin.import.seed_list`) ; `config/catalog.php` (`import.preview_ttl_minutes`, `import.seed_list_path`, `curation.rate_limits.search`) ; `database/data/tmdb-seed-list.txt` (en-tête) ; `resources/js/pages/admin/import/index.tsx` ; `lang/fr/admin.php` (`import.search.*`, `import.preview.*`, `import.seed_list.{empty, busy, remaining}`, `tmdb.error.rate_limited_interactive`, messages du § 13.1).
- **Tests** : `tests/Feature/Admin/PastePreviewTest.php` — « l'aperçu d'un collage n'écrit aucune ligne import_run » ; « l'aperçu rend le sort de chaque identifiant et nomme les refus de contenu » ; « l'aperçu expire et n'est lisible que par son auteur » ; « le sondage de l'aperçu ne reçoit jamais de 429 ». `tests/Feature/Admin/SeedListTest.php` — « la liste d'amorçage n'ouvre jamais deux collages à la fois et le clic suivant reprend au premier identifiant manquant » ; « un collage de la liste porte au plus paste_max_ids identifiants, dans l'ordre du fichier » ; « le bouton est inactif tant qu'un collage est ouvert » ; « une liste sans identifiant désactive le bouton ». `tests/Feature/Tmdb/TmdbSearchTest.php` — « la recherche marque les films déjà au catalogue et les films retirés » ; « importer un résultat ouvre un collage d'un seul identifiant, marqué exception » ; « importer pendant un collage ouvert est refusé par un message traduit » ; « la recherche a son propre limiteur, distinct de celui des imports » ; « un 429 de TMDB sur la recherche donne un message traduit et Réessayer ». `tests/Feature/Catalog/CatalogImportCommandTest.php` [existant, étendu] — « une simulation n'ouvre jamais de ligne import_run ».
- **Heures** : 4-6.

### L20-17 — Débit et verdict du pilote [J1]

- **Fichiers** : `app/Actions/Curation/RecordCurationHeartbeat.php` ; `app/Support/Curation/ThroughputReport.php`, `PilotVerdict.php` [nouveaux] ; `app/Http/Controllers/Admin/CurationHeartbeatController.php`, `ThroughputController.php` ; limiteur `admin-heartbeat` ; `config/catalog.php` (`curation.heartbeat_seconds`, `idle_seconds`, `pilot.*` dont `composition.{discover, exception}` et `first_rank.{discover, exception}`, `rate_limits.heartbeat`, défauts du § 13.7) ; `resources/js/lib/admin/heartbeat-gate.ts` [nouveau] (battement seulement après une saisie depuis le tick précédent, fonction pure), `resources/js/hooks/admin/use-curation-heartbeat.ts`, `resources/js/pages/admin/throughput.tsx` ; `lang/fr/admin.php` (`throughput.*` dont `hours_undeclared` et l'avancement par voie).
- **Tests** : `tests/Feature/Curation/CurationHeartbeatTest.php` — « un battement dans la fenêtre d'inactivité ajoute l'écart au temps actif » ; « une pause plus longue que la fenêtre n'ajoute rien » ; « une pause de cent secondes n'ajoute rien au temps actif » ; « deux onglets sur le même film ne doublent pas le temps » ; « un film de démonstration n'est jamais compté » ; « un battement sur un film déjà publié ou écarté n'ajoute rien au temps actif ». Vitest `tests/Frontend/admin/curation-heartbeat.test.ts` — « aucun battement n'est posté sur un tick sans saisie depuis le tick précédent ». `tests/Feature/Curation/CurationConfigTest.php` — « idle_seconds vaut soixante et heartbeat_seconds lui est inférieur » ; « le limiteur du battement tolère deux onglets » ; « la taille du pilote est la somme de ses deux compositions ». `tests/Feature/Curation/ThroughputReportTest.php` — « la médiane et le p90 suivent le rang le plus proche » ; « les mesures sont ventilées par voie d'entrée » ; « aucun curateur n'est nommé dans le rapport » ; « le pilote disqualifie au-delà de dix heures actives » ; « le J1 reste à soixante films tant que p90 fois quarante tient dans la réserve » ; « sinon le J1 compte réserve divisée par p90 films » ; « la cible de volume ne dépasse jamais le plafond configuré » ; « un film écarté du pilote compte comme un échec » ; « la terminaison d'un film est sa première ligne movie.published ou movie.unpublished » ; « le verdict attend que chaque voie ait son quota de films terminés » ; « le verdict du pilote ne change pas quand un film du pilote est enrichi en passe 2, recadré ou suspendu » ; « sans heures déclarées, la projection en semaines affiche un message et jamais un quotient ».
- **Dépendances** : L20-10, L20-13 (lignes `movie.published` et `movie.unpublished`), L20-15 ; L100-3 (Vitest).
- **Heures** : 5-7. **Variable d'ajustement** : le nombre de films du J1, décidé par le verdict (D10 du 23/09) ; c'est une variable de **curation**, la seule qui reste au J1 depuis D35 du 23/09 — amendé le 23/09.

### L20-18 — Premiers pas du curateur [J1]

- **Fichiers** : `routes/admin.php` (`admin.guide`) ; `resources/js/pages/admin/guide.tsx` ; `lang/fr/admin.php` (`guide.*`).
- **Tests** : `tests/Feature/Admin/GuidePageTest.php` — « la page de premiers pas rend l'échelle, la grille courante et les raccourcis depuis leurs clés ».
- **Heures** : 1-2.

### L20-19 — Écran de gestion des accès [J2]

- **Fichiers** : `app/Policies/UserPolicy.php` (`viewAny`, `updateRole`, `updateRealName`) ; `app/Actions/Admin/ChangeUserRole.php`, `CorrectRealName.php` [nouveaux] ; `app/Http/Controllers/Admin/AccessController.php` ; `RoleUpdateRequest`, `RealNameUpdateRequest` ; si EN20-3 est inscrite, `app/Enums/AdminActionType.php` (cas `UserRealNameChanged`) ; `resources/js/pages/admin/access/index.tsx` ; `lang/fr/admin.php` (`access.*`, `enum.admin_action.user_real_name_changed`).
- **Tests** : `tests/Feature/Admin/AccessTest.php` — « seul un administrateur change un rôle » ; « le dernier administrateur non anonymisé ne peut être rétrogradé » ; « chaque changement écrit role.changed avec les rôles avant et après » ; « l'attribution d'un rôle privilégié exige un nom réel » ; « la file des rôles privilégiés sans double authentification les liste tous » ; « seul un administrateur corrige un nom réel, et les instantanés déjà figés ne changent pas » ; « corriger un nom réel écrit user.real_name_changed dans la même transaction ».
- **Heures** : 6-8.

### L20-20 — Suspension conservatoire et levée [J2]

- **Fichiers** : `app/Actions/Curation/SuspendMovie.php`, `UnsuspendMovie.php`, `SuspendFrame.php`, `UnsuspendFrame.php` ; `app/Support/Curation/SuspensionHistory.php` ; contrôleurs et requêtes associés ; `MoviePolicy`, `FramePolicy` (méthodes J2) ; `lang/fr/admin.php` (`movie.suspend.*`, `frame.suspend.*`).
- **Tests** : `tests/Feature/Curation/SuspensionTest.php` — « suspendre un film ne suspend que ses images publiées et supprime leurs seen_frame dans la même transaction » ; « la suspension sort le film du vivier à la seconde » ; « la suspension distribue l'annulation active après commit » ; « la levée rétablit l'état antérieur reconstitué depuis le journal » ; « la levée recalcule la couverture et l'ambiguïté des formes dans la même transaction » ; « une levée en échec de garde écrit movie.unsuspended puis movie.unpublished » ; « une seconde suspension après une levée en échec de garde restaure unpublished, jamais published » ; « une levée vers published affiche l'aperçu d'ambiguïté et refuse une empreinte périmée sans rien écrire » ; « une image suspendue par la cascade ne revient en jeu que si sa revue porte sur ses octets et la version courants » ; « une image suspendue individuellement ne se lève que par son propre geste » ; « une image suspendue individuellement avant la suspension de son film n'est pas restaurée par la levée du film » ; « on ne suspend ni un film déjà suspendu ni un film retiré ». La preuve de l'annulation en manche vit dans `tests/Feature/Game/LiveWithdrawalTest.php` de `60` (R-04).
- **Heures** : 6-8.

### L20-21 — Retrait juridique et fichiers [J2]

- **Fichiers** : `app/Actions/Curation/WithdrawMovie.php`, `WithdrawFrame.php` ; `app/Jobs/Curation/DeleteWithdrawnFrameFiles.php` ; `app/Console/Commands/TakedownReconcileCommand.php` ; contrôleurs associés ; `lang/fr/admin.php` (`movie.withdraw.*`, `frame.files_error.*`).
- **Tests** : `tests/Feature/Curation/WithdrawalTest.php` — « un retrait est terminal et bloque le réimport par la ligne du film » ; « un retrait exige un motif et une confirmation saisie » ; « la suppression pose files_deleted_at après vérification de l'absence des deux fichiers » ; « les chemins d'une frame retirée ne passent jamais à NULL » ; « takedown:reconcile resupprime un fichier réapparu et journalise dans purge_run » ; « le retrait recalcule couverture et ambiguïté dans la même transaction » ; « le retrait d'un film envoie DeriveMovieDifficulty après commit ».
- **Heures** : 5-7.

### L20-22 — File des demandes de retrait [J2]

- **Fichiers** : `app/Policies/TakedownRequestPolicy.php` ; `app/Support/Takedown/BusinessDays.php` ; `app/Actions/Admin/RegisterTakedownRequest.php`, `DecideTakedownRequest.php` ; `app/Http/Controllers/Admin/TakedownController.php`, `TakedownDecisionController.php` ; requêtes ; `resources/js/pages/admin/takedowns/index.tsx`, `show.tsx` ; `lang/fr/admin.php` (`takedown.*`).
- **Tests** : `tests/Feature/Admin/TakedownQueueTest.php` — « l'échéance tombe à la fin du septième jour ouvré qui suit la réception » ; « les jours fériés de métropole et les fins de semaine ne comptent pas » ; « une demande saisie depuis un e-mail garde la date de réception du courriel » ; « la décision applique le geste lié et écrit takedown.decided avec la demande » ; « la file est triée par échéance » ; « un curateur n'accède à rien ».
- **Heures** : 5-7.

### L20-23 — Messages FR/EN au demandeur [J2]

- **Fichiers** : `app/Jobs/Takedown/SendTakedownMessage.php` ; `app/Notifications/Takedown/TakedownAcknowledged.php`, `TakedownDecided.php` ; `lang/fr/mail.php`, `lang/en/mail.php` (`takedown.*`).
- **Tests** : `tests/Feature/Admin/TakedownMessagesTest.php` — « l'accusé part dans la locale stockée avec la demande et jamais dans celle du back-office » ; « acknowledged_at et notified_at ne sont posés qu'après l'envoi » ; « les gabarits de retrait existent en français et en anglais » (symétrie prouvée par `TranslationCoverageTest` de `05`).
- **Heures** : 3-4.

### L20-24 — Resynchronisation, clore un balayage, limiteur partagé [J2]

- **Fichiers** : `app/Http/Controllers/Admin/MovieResyncController.php`, `ImportAbandonController.php` ; `app/Support/Catalog/TmdbQuotaLimiter.php` (partage en cache) ; `resources/js/pages/admin/catalog/resync.tsx` ; `lang/fr/admin.php` (`resync.*`, `import.abandon.*`).
- **Tests** : `tests/Feature/Catalog/ResyncScreenTest.php` — « l'écran de différences ne propose que la liste close des écrasables » ; « une certification restrictive bloque le film et propose une dépublication sans la prononcer » ; « un alias TMDB supprimé réapparaît signalé ». `tests/Feature/Admin/ImportTest.php` [existant, étendu] — « clore un balayage suspendu le passe failed sans ligne de journal ». `tests/Feature/Tmdb/TmdbQuotaTest.php` — « le limiteur partagé plafonne la somme des processus ».
- **Heures** : 6-8.

### L20-25 — Geste rétroactif de grille [J2]

- **Fichiers** : `app/Actions/Curation/ApplyRetroactiveGrid.php` ; `ExclusionGridRetroactiveController`, `ExclusionGridRetroactiveRequest` ; écran de confirmation ; `lang/fr/admin.php` (`exclusion_grid.retroactive.*`).
- **Tests** : `tests/Feature/Curation/GridRetroactiveTest.php` — « le geste est réservé à l'admin » ; « il n'est offert que si la version courante est rétroactive » ; « il dépublie seulement les frames publiées non re-revues aux niveaux concernés et écrit une ligne frame.grid_unpublished par frame » ; « il est idempotent » ; « un film qui perd sa couverture reste publié » ; « la confirmation liste les films que le geste rendra incomplets ».
- **Heures** : 2-4.

### L20-26 à L20-33 — Lots J2 restants

- **L20-26 Quasi-justes et collisions** (3-5 h).
  - **Fichiers** : `app/Http/Controllers/Admin/NearMissController.php`, `NearMissPromoteController.php`, `NearMissDismissController.php`, `CollisionsController.php` ; `app/Http/Requests/Admin/NearMissPromoteRequest.php` ; `app/Actions/Curation/PromoteNearMiss.php` ; `routes/admin.php` (quatre routes du § 9.5) ; `resources/js/pages/admin/near-misses/index.tsx`, `resources/js/pages/admin/collisions/index.tsx` ; `lang/fr/admin.php` (`near_misses.*`, `collisions.*`).
  - **Tests** : `tests/Feature/Catalog/NearMissPromotionTest.php` — « promouvoir une quasi-juste crée un alias curé et reprojette » ; « écarter une quasi-juste ne nomme aucun auteur » ; « une quasi-juste d'un autre film répond 404 ». `tests/Feature/Catalog/CollisionsScreenTest.php` — « l'écran rend les mêmes lignes que answers:collisions ».
- **L20-27 Candidats `movie_group`** (2-3 h).
  - **Fichiers** : `app/Support/Curation/MovieGroupCandidates.php` [nouveau] (distance par `AnswerKeyNormalizer::distance()` ≤ `AnswerRules::tolerance()`, chiffres identiques, `collection_id` identique) ; `app/Http/Controllers/Admin/CatalogController.php` (prop optionnelle `group_candidates` de la fiche) ; `resources/js/pages/admin/catalog/show.tsx` (bloc « candidats ») ; `resources/js/types/admin.ts` (`AdminGroupCandidate`) ; `lang/fr/admin.php` (`group.candidates.*`).
  - **Tests** : `tests/Feature/Catalog/MovieGroupCandidatesTest.php` — « un film à distance tolérée et à chiffres identiques est proposé » ; « une même collection est proposée sans jamais regrouper seule » ; « les candidats ne sont calculés qu'à la demande ».
- **L20-28 Thèmes** (7-10 h : seuil de publication et correction de règle par nature compris).
  - **Fichiers** : `app/Policies/ThemePolicy.php` [nouvelle] ; `app/Http/Controllers/Admin/ThemeController.php`, `ThemePublishController.php`, `MovieThemeController.php`, `MovieDifficultyController.php` ; `app/Http/Requests/Admin/ThemeStoreRequest.php`, `ThemeUpdateRequest.php`, `ThemePublishRequest.php`, `MovieThemeUpdateRequest.php`, `MovieDifficultyUpdateRequest.php` ; `app/Actions/Curation/AddSagaTheme.php`, `UpdateTheme.php` (règle validée par nature), `PublishTheme.php`, `SetMovieThemeMembership.php`, `CorrectMovieDifficulty.php` [nouveaux] ; `routes/admin.php` (six routes du § 9.6) ; `resources/js/pages/admin/themes/index.tsx` (nombre d'œuvres du thème seul), `resources/js/pages/admin/catalog/show.tsx` (bloc « Thèmes ») ; `lang/fr/admin.php` (`themes.*` dont `labels_missing`, `key_taken` et `too_small`).
  - **Dépendances** : L20-3 ; L30-8 (`ThemeEvaluator`, `SyncThemeMembership`) ; L30-10 (`DeriveMovieDifficulty`) ; L30-11 (`PoolScope::themeProbe()`, `PoolReporter::themeWorks()`, `30` § 12.3).
  - **Tests** : `tests/Feature/Catalog/ThemeEditingTest.php` — « un thème sans libellé dans chaque locale activée ne se publie pas » ; « un thème sous le seuil de publication ne se publie pas et se dépublie toujours » ; « corriger l'identifiant d'un thème studio livré relance son évaluation après commit » ; « une règle invalide pour la nature du thème est refusée et la nature ne change jamais » ; « la clé d'une saga ajoutée suit la convention des sagas livrées » ; « ajouter une saga sur une collection importée crée un thème non publié et dispatche SyncThemeMembership après commit » ; « changer la collection d'une saga dispatche SyncThemeMembership et DeriveMovieDifficulty après commit » ; « une saga ne se crée que sur une collection déjà au catalogue » ; « une appartenance manuelle survit au recalcul automatique » ; « une difficulté corrigée survit au réimport et resynchronise les thèmes de difficulté dans la même transaction ». La mesure d'un thème non publié est prouvée par `PoolQueryTest` de `30` (L30-11, R-04), jamais ici.
- **L20-29 Films jamais trouvés et incidents** (2-3 h).
  - **Fichiers** : `app/Http/Controllers/Admin/IncidentsController.php` ; `app/Support/Curation/IncidentReport.php` [nouveau] (agrégat par `movie_id` sur `round_movie_found_idx`) ; `routes/admin.php` (`admin.incidents.index`) ; `config/catalog.php` (`curation.incidents_window_days`) ; `resources/js/pages/admin/incidents/index.tsx` ; `lang/fr/admin.php` (`incidents.*`).
  - **Tests** : `tests/Feature/Admin/IncidentsTest.php` — « l'agrégat par film ne joint jamais round_player, guess ni player » ; « seules les manches terminées comptent comme jamais trouvées » ; « la fenêtre glissante suit la configuration ».
- **L20-30 Inspecter une partie** (4-6 h).
  - **Fichiers** : `app/Policies/GamePolicy.php` [nouvelle] ; `app/Http/Controllers/Admin/GameInspectionController.php` ; `app/Support/Admin/GameInspectionPresenter.php` [nouveau] (seul constructeur des props, qui n'interroge pas le film, les frames, les leurres ni les bonnes réponses d'une manche non révélée, § 12.2) ; `routes/admin.php` (`admin.games.index`, `admin.games.show`) ; `resources/js/pages/admin/games/index.tsx`, `show.tsx` ; `lang/fr/admin.php` (`games.*`).
  - **Tests** : `tests/Feature/Admin/GameInspectionTest.php` — « seul un administrateur inspecte une partie » ; « l'inspection rend les écarts de rejeu de ScoreReplayer » ; « l'inspection d'une partie en cours ne sérialise aucun film ni aucune frame d'une manche non révélée ».
- **L20-31 Modération** (3-5 h).
  - **Fichiers** : `app/Http/Controllers/Admin/ModerationController.php`, `ModerationNicknameController.php`, `ModerationAvatarController.php` ; `app/Http/Requests/Admin/ModerationReasonRequest.php` ; `routes/admin.php` (trois routes du § 11.5) ; `resources/js/pages/admin/moderation/index.tsx` ; `lang/fr/admin.php` (`moderation.*`). Les actions de levée et leurs policies viennent du lot J2 de `40`.
  - **Tests** : `tests/Feature/Admin/ModerationScreenTest.php` — « seul un administrateur lève un masquage et chaque levée écrit sa ligne » ; « un avatar prédéfini n'est jamais signalable ».
- **L20-32 Réservation souple** (2-3 h).
  - **Fichiers** : `app/Support/Curation/CurationClaim.php` [nouveau] (clé `curation:claim:{movieId}`) ; `app/Support/Curation/CurationQueue.php` et `app/Actions/Curation/RecordCurationHeartbeat.php` (prolongation) ; `config/catalog.php` (`curation.claim_minutes`) ; `resources/js/pages/admin/curation/index.tsx` ; `lang/fr/admin.php` (`curation.claimed_by`).
  - **Tests** : `tests/Feature/Admin/CurationClaimTest.php` — « la file saute un film réservé par un autre curateur » ; « une réservation expire sans battement ».
- **L20-33 Voie capture** (6-8 h, conditionnel à l'arbitrage de licéité).
  - **Fichiers** : `app/Http/Requests/Admin/FrameCaptureStoreRequest.php` [nouveau] ; `app/Actions/Curation/AddFrame.php` (`fromCapture`) ; `app/Http/Controllers/Admin/FrameCaptureController.php` (branche acceptante) ; `resources/js/lib/admin/capture-encoder.ts` [nouveau] (décodage `createImageBitmap`, normalisation à 1920 px, `toBlob` WebP à qualité descendante) ; presse-papiers et saisie du timecode dans `frame-cropper.tsx` ; `lang/fr/admin.php` (`frame.capture.timecode`, `validation.frame_source.{max, mimetypes}`).
  - **Tests** : `tests/Feature/Curation/FrameCaptureStoreTest.php` (sous `capture_enabled` vrai) — « un fichier de plus de 1 536 Ko échoue en erreur traduite et jamais en 419 » ; « source_hash est l'empreinte de la source reçue » ; « le timecode est obligatoire pour une capture ». Vitest `tests/Frontend/admin/capture-encoder.test.ts` — « l'encodeur descend la qualité jusqu'à passer sous le plafond d'entrée ».

---

## Ce que cette spec ne décide pas

| Sujet | Propriétaire |
|---|---|
| Le schéma de toute table citée, la liste fermée `admin_action` et ses classes, le tableau de rétention, l'identité d'une demande de retrait à échéance, la sonde `published_review_id` | `10-catalogue-et-modele-de-donnees.md` |
| La règle de langue, la liste close des domaines, la symétrie de clés, `mail` vérifié en CI | `05-i18n-et-langues.md` |
| `FrameLevelCoverage`, le constructeur du vivier et `PoolReporter`, le tirage, la substitution, l'exclusion mutuelle d'un `movie_group`, la dérivation de `movie_difficulty`, l'évaluateur de thèmes, les jobs `SyncThemeMembership` et `DeriveMovieDifficulty` (que les gestes de cette spec envoient), la mesure `PoolReporter::themeWorks()` et le seuil de publication d'un thème (`30` § 12.3 ; cette spec n'en fixe que l'affichage et la forme de refus, § 9.6), la liste par défaut des sagas et leurs identifiants TMDB | `30-themes-vivier-et-tirage-des-variantes.md` |
| Le cycle de vie de `users.role` vu du compte, l'enrôlement Fortify, l'**articulation passkey / 2FA**, l'OAuth sans contournement du défi, la règle de signalement et de masquage, **l'effet de `nickname.banned`**, la preuve du consentement provider, le refus du dernier admin à la suppression de compte, le nom des méthodes de policy de modération (§ 11.5) | `40-comptes-auth-sociale-et-avatars.md` (J2) |
| Les réglages, `PlatformLimits` comme classe et `config/game.php` — dont les accesseurs `frameCropMaxWidthPercent()` et `frameCropMinWidthPx()`, leurs clés et leur garde de bornes, livrés par L50-1, cette spec n'en déclarant que les valeurs —, la garde de vivier au lancement ; les bornes du contrat C0 pour `frameCropMaxWidthPercent` (EN20-4) | `50-salon-reglages-presets-et-lobby.md` |
| La frappe du `serve_token`, `ServeGuard`, la route `/f/{serveToken}`, `WithdrawContentFromLiveRounds`, le rejeu de chronologie, le résidu du hachage des octets | `60-moteur-de-partie-temps-reel-et-mode-solo.md` |
| La normalisation, le sous-titre dérivé, `answers:collisions`, la qualification et l'alimentation de `near_miss` | `70-validation-des-reponses.md` |
| Le rejeu des points (`ScoreReplayer`) et les faits du podium | `80-scoring-podium-et-fin-de-partie.md` |
| `GameFrame`, `GameThemeScope`, `AdminLayout`, le retrait du forçage clair (C16 § 2.2), la règle de fermeture propre des feuilles et boîtes de dialogue (§ 2.5 de `90`), la prop partagée `frameFormat`, les composants shadcn installés au J1 (`radio-group` compris), le périmètre `WATCHED` — dont l'inscription de `hooks/admin`, `lib/admin` (EN20-2) et `lib/frame-geometry.ts` —, le `X-Robots-Tag` du back-office (`RobotsDirectives`), les pages légales et « signaler un contenu » (dont le **texte public des jours ouvrés**, identique au § 11.4), la mention de l'IP du curateur vers TMDB dans la confidentialité | `90-ecrans-etats-et-structure.md` |
| Les workers — dont les valeurs `--timeout` et `MemoryMax` du worker `default` sous lesquelles la configuration Imagick est bornée (§ 13.7) —, la configuration Vitest, la sauvegarde — **dont le tier froid des dérivés publiés, sans lequel une image re-dérivée de TMDB change d'empreinte et doit repasser en revue** —, `catalog:reproject`, `backup:snapshot`, la procédure de restauration (qui rejoue `takedown:reconcile`), les sondes (accusé de réception en retard compris), `robots.txt`, la perte du second facteur du seul admin | `100-qualite-tests-et-ci.md` |
| **La liste fermée des sources autorisées pour une capture personnelle** (licéité de l'acte de capture) | conseil du porteur (décision 7) — bloque L20-33 |
| **R-46 et R-47**, écarts signalés à des résolutions par défaut ; **EN20-1 à EN20-4** et **AN20-1 à AN20-8**, exigences, écarts aux contrats et amendements non consolidés de cette spec | le porteur, en compte rendu de session |
| Le cas `user.real_name_changed` de la liste fermée (EN20-3), dont dépend la correction du nom réel à l'écran | `10-catalogue-et-modele-de-donnees.md`, sur signalement du porteur |
| **Les heures déclarées avant le lot pilote** (`weekly_curation_hours`, `horizon_weeks`) et la **liste d'amorçage** elle-même (environ 200 identifiants, relue une fois) | le porteur |
| L'agrégat des tailles du J1 (jamais un calendrier, D36 du 23/09), la date du lot pilote sur le chemin critique humain (AN20-8), l'arithmétique de curation et leur relecture après le pilote ; la variable « recadreur minimal » est sans objet depuis D35 du 23/09 (AN20-4) — amendé le 23/09 | `00-overview.md` § Jalons |
