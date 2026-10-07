# Thèmes, vivier et tirage des variantes

Ce document est le **propriétaire unique de l'algorithme** qui décide quels films une partie peut tirer, lesquels elle tire, et quelle image elle montre à chaque palier. Il possède : la répartition nominale des niveaux par `N` et le repli de niveau (`FrameLevelCoverage`, contrat C1) ; le constructeur unique du vivier, son unité de compte, la non-répétition des films sur l'axe salon et le rapport de vivier en données (contrat C2) ; la graine, la PRF à contextes nommés, le tirage des films et des variantes figé au lancement, la préférence « non vue par le salon », la substitution d'une variante du même niveau, le choix du film de remplacement, le delta du mode solo pour ces seuls points et l'interface que `70` consomme pour tirer ses leurres (contrat C3) ; la taxonomie des thèmes, l'évaluateur d'appartenance, la liste par défaut des sagas, et la mesure et le seuil de publication d'un thème (§ 12.3), **au jalon 1 depuis D43 du 01/10** ; au jalon 2, la dérivation de `movie_difficulty` et le seuil de masquage du sélecteur de thèmes — amendé le 01/10 (D43 du 01/10).

Il ne possède **aucune table** : le schéma appartient à `10` (règle d'autorité de `10`, en tête), et toute donnée nouvelle est une exigence adressée à `10`. Il ne possède ni le déclenchement du calcul de vivier, son anti-rebond, son texte et la transaction de lancement (`50`, contrats C0 et C6), ni l'écriture de `round`, `round_tier` et `seen_frame`, la détection d'une image non servable, l'annulation d'une manche et les réglages du solo (`60`), ni la règle des leurres et la composition du QCM (`70`, contrat C11), ni les écrans des thèmes, des exceptions manuelles et de la difficulté (`20`), ni le rendu du lobby et du sélecteur (`90`). Il ne produit **aucune chaîne formatée** : seulement des entiers, des booléens, des codes, des identifiants qui ne quittent jamais le serveur et, au J2, les libellés de thème tels qu'ils sont stockés en `theme_label`, qui sont des données (règle 4).

Conventions de renvoi, valables dans tout le document : « règle N » désigne `CLAUDE.md` §7 ; « principe N » désigne `00-overview.md` § Principes directeurs ; « décision N » désigne une des 19 décisions du 22/09 (`questions-ouvertes.md`) ; « DN du 23/09 » et « SN du 23/09 » désignent les décisions et le cadre de session du 23/09, consignés dans `questions-ouvertes.md` § « Décisions du 23/09/2026 — jalon 1 » ; « contrat Cn » désigne la feuille de contrats partagés du 23/09, dont les noms, signatures, charges utiles et tests sont repris ici **à la lettre**, et « R-nn » un conflit résolu de cette même feuille ; « E10-nn » et « A-nn » désignent les exigences à `10` et les amendements consolidés de cette feuille ; « n° N » désigne la contradiction N de la pré-analyse du 23/09, **numérotée à partir de 0** (`synthesis.json › contradictions_to_fix[N]`, comme dans la feuille de contrats), et sa résolution retenue ; « carte de propriété n° N » une ligne de sa carte des propriétaires, « question Qnn » une question qu'elle a écartée et dont la résolution par défaut est retenue. Ordre de préséance : décisions du 23/09 > `10` > `05` > `00` > code existant.

> **État réel du dépôt au moment d'écrire, vérifié au commit `d167a6a`.**
> - **Aucun code de vivier, de tirage ni de PRF** : `app/Support/Draw/` n'existe pas ; aucune occurrence de `PoolQuery`, `SeededPrf`, `GameDrawer` ni `VariantChooser` dans `app/`, `database/` ou `tests/`. `tests/Feature/Draw/` n'existe pas.
> - **`FrameLevelCoverage` est absent** : `app/ValueObjects/Catalog/` ne contient que `ExceptionMotives.php` et `ImportFilter.php`. La table nominale vit, « provisoire et non normative », dans `MovieFactory::expectedFrameLevels()` (l.463), appelée par `MovieFactory::playable()` (l.441), `DemoCatalogueSeeder::frames()` (l.347) et `DemoCatalogueChainTest` (l.193).
> - **La marge de tirage est un littéral de fixture** : `DemoCatalogueSeeder::DRAW_MARGIN = 3` (l.80) et `GameFactory::DRAW_MARGIN = 3` (l.60). `config/game.php` n'existe pas ; `PlatformLimits` porte neuf accesseurs (dont `speedBonusMaxFraction`) et **aucun** accesseur de marge, de fenêtre de mémoire ni de seuil de sélecteur.
> - **Trois prédicats de vivier divergents** : `demoPool()` de `DemoCatalogueChainTest` (l.83-91) ajoute `(p.levels_mask & 21) = 21` ; `DashboardController::poolByFramesPerRound()` compte des **films** par un `groupBy('movie_projection.levels_count')` sur `range(2, 5)` littéral ; `CatalogIndexRequest::PLAYABLE_AT_MIN/MAX` recopient les bornes de `N`.
> - **Un axe joueur survit en commentaire** : « repli non vue par le joueur » dans `DemoCatalogueSeeder` l.99 et `DemoCatalogueChainTest` l.675.
> - **Thèmes** : `ThemeKind` a six cas (`genre`, `decade`, `studio`, `saga`, `language`, `difficulty`) ; `MovieTheme::resolveIsActive()` est la seule écriture admise d'`is_active`. `PlatformDataSeeder` publie 11 genres, 3 studios (sociétés TMDB 2, 3, 10342), les décennies `[1930, 1970, 1980, 1990, 2000, 2010, 2020]` (trou 1940-1960), « cinéma international » (`en` nié) et 5 thèmes de difficulté, **aucune saga** (son docblock, l.37-41, l'interdit expressément), et réécrit `is_published`, `sort_order` (séquentiel, `$index + 1`, l.112) et les libellés à chaque passage. L'évaluateur d'appartenance n'existe qu'en provisoire dans `DemoCatalogueSeeder::ruleMatches()`, où un thème nié sans `rule_value` contient **tout** le catalogue.
> - **Ni `catalog:themes` ni `backup:snapshot`** (constat du 23/09 ; au 01/10, `backup:snapshot` est livré par L100-6 et `catalog:themes` est livré par L30-8 le 01/10, avec `catalog:company-names` — amendé le 01/10 (D43 du 01/10)) : `app/Console/Commands/` ne contient que les commandes d'import (`CatalogImport*`), `FirstAdminCommand`, `LangHashCommand` et `LangTypesCommand` ; `config/catalog.php` n'a pas de section `difficulty`.
> - **Aucune appartenance ni difficulté pour un film réel** : `MovieImporter` écrit `movie_tmdb_tag` et `collection_id` (en réutilisant une ligne `collection` par `tmdb_id`, l.626-643) mais ni `movie_theme` ni `movie_difficulty_derived`. Selon `docs/REPRISE.md` (23/09), la base de dev porte 3 films importés, tous `draft`, donc un vivier nul à tout `N`.
> - **Ce qui existe et se réutilise tel quel** : `Movie::inPool()` (`published` + `clear`), `MovieProjection::supportsFramesPerRound()` et `publishableLevelsMask()`, `Frame::isServable()` (prédicat unique de variante jouable), `FrameLevel::bit()`, `InputDifficulty::choicesOpenTierIndex()`, `RoomSettings::normalize($raw, $version, $availableThemeIds)`, et les index `movie_pool_idx`, `movie_projection_levels_idx`, `movie_theme_pool_idx`, `movie_tmdb_tag_rule_idx`, `movie_decile_idx`, `frame_movie_level_idx`, `seen_frame_room_frame_uq`, `round_room_started_movie_idx`, `round_game_sequence_uq`, tous présents dans les migrations. `RefreshDatabase` est actif dans `tests/Pest.php`.

---

## 1. Notations, vocabulaire et découpage par jalon

### 1.1 Notations

`N` = `frames_per_round`, `M` = `roundsCount`, tous deux lus dans `room_settings` (défauts et bornes : `RoomSettingsBounds`, contrat C0). **`W`** = nombre d'œuvres du vivier au moment du tirage. **`marge`** = `PlatformLimits::drawSubstituteMargin()` (défaut 3, borne basse 0, contrat C0 ; borne haute `MAX_DRAW_SUBSTITUTE_MARGIN = 255 − RoomSettingsBounds::MAX_ROUNDS_COUNT`, soit 225 aux bornes actuelles, pour que `round.sequence_index`, `unsignedTinyInteger`, contienne toujours `K` — sans elle, une marge configurée au-delà sur un catalogue de plus de 255 œuvres ferait échouer `MaterializeDraw` sous MySQL strict, donc tout lancement ; borne haute portée par `50` § 2.3 (`PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN`), testée en L50-1 ; le code livré écrit `255` sous le nom `PlatformLimits::MAX_ROUND_SEQUENCE_INDEX`, et la garde de `pauseTimeoutMs` du moteur ajoute une seconde borne, `PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE` = `⌊EngineConstants::DEFAULT_PAUSE_TIMEOUT_MS ÷ EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS⌋ − RoomSettingsBounds::MAX_ROUNDS_COUNT`, soit 150 aux défauts : la marge admise est bornée par `min(MAX_DRAW_SUBSTITUTE_MARGIN, MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE)`, une seule garde au constructeur de `PlatformLimits`, `50` § 2.7 — amendé le 25/09 (E9-5, E10-2, E10-8)). **`K`** = `min(M + marge, W)`, nombre de manches matérialisées au lancement. `s` = `sequenceIndex`, position dans le tirage figé, `1..K`. `i` = `tier_index`, `1..N`.

Aucun de ces nombres n'est une constante : les exemples chiffrés de ce document sont donnés **au réglage par défaut** (`M` = 10, `N` = 3, marge = 3) et le disent.

### 1.2 Vocabulaire propre à cette spec

Lexique normatif de `00` § Vocabulaire, complété par les termes du glossaire de la feuille de contrats — A-25 et A-74 pour œuvre, vivier catalogue, vivier du salon et mémoire du salon ; lexique du glossaire (contrat C3) pour manche de réserve — et par un terme propre à cette spec, repli de niveau, proposé au lexique par l'amendement A-N5 (non consolidé, § Amendements).

| Produit (FR) | Code (EN) | Définition |
|---|---|---|
| œuvre | `work` | Unité de compte du vivier : un film sans `group_id`, ou un `movie_group` entier dont les films présents au vivier comptent pour **un**. |
| vivier catalogue | catalogue pool (`PoolScope::catalogue()`) | Fonction de (thèmes, `N`) seulement, sans aucune clause de salon. |
| vivier du salon | room pool (`PoolScope::forRoom()`) | Vivier catalogue moins la non-répétition des films déjà joués par le salon. C'est lui que garde la borne croisée 3. |
| mémoire du salon | room memory (`RoomMemoryWindow`) | Fenêtre unique, commune à la non-répétition des films **et** à la préférence de variante (`seen_frame`). |
| manche de réserve | margin round | Manche tirée au lancement au-delà de `M` (`round_number` NULL), qui ne sert qu'au remplacement. |
| repli de niveau | level fallback | Choix de `N` niveaux disponibles qui diffèrent de la répartition nominale (`FrameLevelCoverage::usesFallback()`). |

« Vivier » ne désigne **jamais** les images d'un film (`00` § Vocabulaire) ; la banque d'images est `frame_bank`. Quatre notions ne partagent jamais un nom (`00` § Le jeu en une manche, `10` § 1.3) : `input_difficulty`, `movie_difficulty`, `frame_level`, `frames_per_round`. Cette spec en manipule trois ; `input_difficulty` n'y entre que par l'instant de composition des leurres (§ 10).

### 1.3 Ce que « joué » veut dire

**Une manche est jouée si et seulement si `round.started_at IS NOT NULL AND round.started_at <= :now`**, c'est-à-dire si son instant `T₁` est franchi, **quel que soit son statut, manche annulée comprise** (contrat C2 § 3, n° 25). Pourquoi l'instant et pas la seule présence de `started_at` : `started_at` est écrit dès la **programmation** de la manche (contrat C6 étape O9), avant `T₁` ; une manche programmée dont `T₁` n'est pas atteint n'a montré aucune image. Pourquoi l'annulation ne l'exclut pas : un film dont le palier 1 a été montré puis annulé a été vu. Deux conséquences :

- une **manche de réserve jamais démarrée n'est pas jouée** : c'est ce qui fait tenir environ six parties de 10 manches sans répétition sur les 60 films du jalon 1, et non « 3 à 4 » (amendements A-32 et A-66) ;
- une manche **déprogrammée à la pause** (`started_at` remis à NULL, E10-46) n'est pas jouée.

**Résidu assumé, écrit ici parce qu'il est choisi** : une manche restée `pending` après son `T₁` dans une partie close d'office (clôture forcée `stale_game`, ou partie bloquée close par `game:reschedule`, `60` § 14.4 ; toutes deux par `FinalizeGame`, E10-62) compte comme jouée alors qu'aucune image n'a peut-être été servie. Le filtre `status <> 'pending'` l'écarterait, mais sortirait la clause de l'index `round_room_started_movie_idx (room_id, started_at, movie_id)` qui la sert : le coût d'un film exceptionnellement retiré du vivier du salon (compté comme joué) pour la durée de la fenêtre (§ 3.5) ne justifie pas de dégrader la requête la plus fréquente du lobby.

**Second résidu assumé** : une manche annulée sans qu'aucune image n'ait été servie garde son `started_at`, puisque `CancelRound` (`60` § 15.2) ne pose que `status`, `cancel_reason` et `cancelled_at`, et se sert de ce `T₁` prévu pour programmer le remplaçant. C'est le cas d'une manche annulée **avant** son `T₁` (frappe du palier 1 impossible à la programmation, `no_variant_available`, contrat C8) ou **à** son `T₁` même (palier 1 refusé à l'ouverture, `frame_unavailable`). Elle compte comme jouée dès que ce `T₁` est franchi, bien qu'aucune image n'ait été vue ; la justification « un film dont le palier 1 a été montré puis annulé a été vu » ne la couvre pas. L'exclure exigerait de lire `cancel_reason` et `cancelled_at`, hors de l'index `round_room_started_movie_idx`. Le coût est un film à banque défaillante sorti du vivier du salon pour la fenêtre — film que le signal d'incident de `20` fait de toute façon reprendre en curation.

### 1.4 Découpage par jalon

Le découpage par jalon est celui de la question Q30-4, retenue par défaut : ce qui est marqué J2 reste au J2, et la barre « terminé » n'est jamais touchée (`00` § Jalons et budget-temps). D35 du 23/09 (J1 complet, aucune coupe des lots marqués J1) ne le rouvre pas : rien de ce qui est marqué J2 ne remonte au J1, et la colonne « Pourquoi » ci-dessous tient sans argument de temps, les lots J2 n'ayant aucun lecteur au J1. — amendé le 23/09. **D43 du 01/10 le rouvre pour les thèmes**, par décision du porteur : L30-8, L30-9 et la mesure d'un thème (L30-11a, scindée de L30-11) remontent au J1, parce que le back-office des thèmes, la fiche film et le collage (`20` L20-28) en deviennent les lecteurs au J1 ; la dérivation de la difficulté (L30-10), le seuil du sélecteur et les thèmes proposables (L30-11) restent au J2 — amendé le 01/10 (D43 du 01/10).

| Jalon | Contenu | Pourquoi |
|---|---|---|
| **J1** | `FrameLevelCoverage` ; constructeur du vivier, branches **avec et sans** thème, compte en œuvres, non-répétition, rapport de vivier, `N` jouable le plus proche ; graine, PRF, tirage des films et des variantes, préférence de variante, substitution, remplacement ; solo sans mémoire ; interface des leurres ; scission du seeder de plateforme ; **évaluateur d'appartenance, son job et `catalog:themes` ; décennies 1930-2020 sans trou, studios dont Marvel et DC, thème des animés, douze sagas par défaut ; mesure d'un thème avant publication (`themeProbe`, `themeWorks`, § 12.3) — amendé le 01/10 (D43 du 01/10)**. | La borne croisée 3 est due au J1 (« toutes les bornes croisées côté serveur », `00` § Jalons et budget-temps) et le lancement tire films et variantes (`00` § Déroulé d'une partie). Le back-office des thèmes, la fiche film et le choix de thèmes au collage sont au J1 (D43 du 01/10). |
| **J2** | Dérivation de `movie_difficulty` et thèmes de difficulté peuplés ; seuil et visibilité du sélecteur ; liste des thèmes proposables — amendé le 01/10 (D43 du 01/10). | Le sélecteur de thèmes est masqué au J1 (`00` § Jalons et budget-temps) : difficulté dérivée et sélecteur n'y ont aucun lecteur. |

**État du J1 à écrire noir sur blanc** : ~~aucun film réel n'appartient à un thème (l'importeur ne calcule aucune appartenance) et aucun n'a de difficulté~~ — amendé le 01/10 (D43 du 01/10) : les films réels appartiennent à leurs thèmes dès leur import (évaluation synchrone, § 13.2) et ceux importés avant L30-8 par le rattrapage `catalog:themes` ; aucun n'a encore de difficulté dérivée, si bien que les thèmes `difficulty.*` ne contiennent que les corrections manuelles, livrées au J2. Le serveur accepte `themeKeys` (contrat C0, champ n° 1) : un hôte qui poste la clé d'un thème publié trop maigre obtient un vivier bloqué, cause `themeKeys`, remède `clear_themes` (§ 4.3). C'est inoffensif et cohérent — la règle ne change pas, seul l'écran manque. La file des films non curés de `20` trie donc sur `vote_count` tant que la dérivation de difficulté n'est pas livrée (n° 17).

---

## 2. Couverture de niveaux — `FrameLevelCoverage` (contrat C1) [J1]

`App\ValueObjects\Catalog\FrameLevelCoverage`, `final readonly`, méthodes statiques pures, sans base ni configuration. **C'est le seul endroit du dépôt où la table `N` → niveaux est écrite** (`10` § 15, E10-67) ; `MovieFactory::expectedFrameLevels()` est supprimée dans le même lot et ses appelants appellent `nominal()`.

```php
public static function nominal(int $framesPerRound): array;                   // list<FrameLevel>, croissant ; InvalidArgumentException hors bornes de N
public static function bands(int $framesPerRound): array;                     // list<list<FrameLevel>> : la plage de chaque palier (§ 2.1 bis)
public static function sequences(int $framesPerRound, int $levelsMask): ?array; // list<list<FrameLevel>>|null : séquences admissibles (§ 2.2)
public static function select(int $framesPerRound, int $levelsMask): ?array;  // list<FrameLevel>|null : séquence de référence ; null si le masque couvre moins de N niveaux
public static function usesFallback(int $framesPerRound, int $levelsMask): bool;
public static function maskOf(iterable $levels): int;                         // iterable<FrameLevel>
public static function levelsIn(int $levelsMask): array;                      // list<FrameLevel>, croissant
```

### 2.1 Répartition nominale

Écrite une fois, en `match` explicite : `2 → [1, 5]`, `3 → [1, 3, 5]`, `4 → [1, 2, 4, 5]`, `5 → [1, 2, 3, 4, 5]` (`00` § Le jeu en une manche). C'est une **constante de règle de produit**, pas une valeur de jeu réglable : elle ne viole pas la règle 2, pas plus que la définition d'une décennie. Les bornes de `N` sont lues dans `RoomSettingsBounds::MIN_FRAMES_PER_ROUND` / `MAX_FRAMES_PER_ROUND`, les bits dans `FrameLevel::bit()`.

### 2.1 bis Plages de niveaux par palier (D45 du 01/10)

Chaque palier pioche son image dans une **plage** de niveaux, écrite une fois dans `bands()` — amendé le 01/10 (D45 du 01/10) :

| N | palier 1 | palier 2 | palier 3 | palier 4 | palier 5 |
|---|---|---|---|---|---|
| 2 | 1–2 | 4–5 | | | |
| 3 | 1–2 | 2–4 | 4–5 | | |
| 4 | 1 | 2–3 | 4 | 5 | |
| 5 | 1 | 2 | 3 | 4 | 5 |

Chaque plage contient le niveau nominal de son rang (§ 2.1). La répartition nominale reste la **référence** du coût de repli et de l'aperçu du back-office ; elle n'est plus la seule séquence jouée.

### 2.2 Séquences admissibles et repli de niveau — l'algorithme

Formulation exécutable, **sans graine** (le choix parmi les séquences appartient au tirage, § 6.3) — amendé le 01/10 (D45 du 01/10) :

1. `A` = niveaux présents dans le masque, croissants. Si `|A| < N`, renvoyer `null`.
2. Parcourir **en ordre lexicographique** les combinaisons `S ⊆ A` de taille `N`, triées croissantes (au plus `C(5, N) ≤ 10`) : les niveaux d'une séquence sont donc **strictement croissants**, jamais deux fois le même.
3. **Séquences admissibles** (`sequences()`) : celles dont chaque `S[i]` appartient à `bands(N)[i−1]`. S'il n'y en a aucune, le film est en **repli de niveau** et la seule séquence admissible est `select()`.
4. **Séquence de référence** (`select()`) : parmi les admissibles dans les plages s'il y en a, sinon parmi toutes les combinaisons, la **première** de coût minimal, `cost(S) = Σᵢ |S[i] − nominal(N)[i]|` : une égalité se départage vers le niveau **le plus cryptique**.
5. `tier_index = i` ⟺ `frame_level` est le `i`-ème niveau de la séquence tirée.

Pourquoi le plus cryptique à égalité : le palier le mieux payé doit rester le plus difficile ; départager vers le plus évident rendrait un film à banque maigre plus rentable qu'un film complet. Pourquoi un repli sans graine : il est une propriété de la banque du film ; le rendre aléatoire ferait varier d'une partie à l'autre les niveaux montrés pour un film qui n'a pas de quoi remplir ses plages, sans que le curateur puisse le prévoir.

### 2.3 Table de cas normative

Elle sert aussi de jeu de données au test « select se replie sur les niveaux disponibles les plus proches sans doublon ». La colonne « repli » est `usesFallback()` — amendé le 01/10 (D45 du 01/10).

| N | niveaux du masque | `select` | repli | lecture |
|---|---|---|---|---|
| 3 | 1,2,3,4,5 | 1,3,5 | non | nominal ; huit séquences admissibles, de 1,2,4 à 2,4,5 |
| 3 | 1,2,4,5 | **1,2,5** | non | égalité de coût 1 avec 1,4,5 ; le plus cryptique gagne |
| 3 | 1,2,3,5 | 1,3,5 | non | nominal |
| 3 | 2,3,4 | 2,3,4 | non | le palier 1 montre un niveau 2, dans sa plage |
| 2 | 1,3,5 | 1,5 | non | nominal |
| 2 | 1,2,3 | 1,3 | **oui** | aucun niveau 4 ni 5 |
| 2 | 2,3 | 2,3 | **oui** | |
| 4 | 1,2,3,4,5 | 1,2,4,5 | non | nominal ; 1,3,4,5 aussi admissible |
| 4 | 1,2,3,5 | 1,2,3,5 | **oui** | un seul niveau de passe 2 suffit à N=4, mais sans niveau 4 |
| 4 | 1,3,5 | `null` | non | passe 1 seule |
| 5 | 1,2,3,4 | `null` | non | |

### 2.4 Invariants

- `select(N, m) !== null` ⟺ `popcount(m) ≥ N` ⟺ `MovieProjection::supportsFramesPerRound(N)` (`levels_count ≥ N`). **L'éligibilité n'est jamais stockée** (`10` § 4.3).
- Monotonie : `select(N, m) !== null` ⇒ `select(N', m) !== null` pour tout `N' < N` dans les bornes.
- `nominal(N) == select(N, 31)` pour tout `N` ; `nominal(MAX_FRAMES_PER_ROUND) == FrameLevel::cases()`.
- `select(N, m) ∈ sequences(N, m)` ; `sequences(N, m) === null` ⟺ `select(N, m) === null` ; `usesFallback(N, m)` ⟺ jouable et aucune séquence dans les plages — amendé le 01/10 (D45 du 01/10).
- Un masque hors `[0, maskOf(FrameLevel::cases())]` ou un `N` hors bornes lève `InvalidArgumentException`. **Jamais d'écrêtage silencieux** : un `N` invalide qui passerait pour un autre fausserait un tirage que la matérialisation existe pour rendre rejouable.

### 2.5 Publication, éligibilité, passe 1 / passe 2 et film devenu incomplet

**Le masque 1-3-5 (`MovieProjection::publishableLevelsMask()`) n'entre pas dans `select` ni dans le vivier.** C'est une garde de **transition** vers `published`, posée par le geste de publication de `20`, jamais une condition de jeu (E10-23, n° 4 ; amendements A-26 et A-75 pour « couvrant 1, 3 et 5 **à sa publication** »). Conséquences :

- **Passe 1 seule** (niveaux 1, 3, 5, donc `levels_count = 3`) : le film est jouable à `N` = 2 et 3, jamais à 4 ni 5. **Au J1, le vivier à `N` = 4 et 5 vaut 0** tant que la passe 2 n'a pas commencé : le preset Hardcore (`N` = 5) est grisé avec `N` jouable le plus proche = 3 (amendement A-12), et le solo Hardcore joue à `N` = 3 (D19 du 23/09, § 4.4).
- **Passe 2** : un seul niveau 2 **ou** 4 ajouté rend le film jouable à `N` = 4 (table § 2.3 : `1,2,3,5` en repli, faute de niveau 4 ; `1,3,4,5` dans les plages) ; les deux ensemble le rendent jouable à `N` = 5. Aucune règle de jeu n'en est affectée : la passe 2 élargit l'éligibilité, elle ne la conditionne pas (décision 10, `00` § Fonctionnalités v1).
- **Film publié devenu incomplet** (dernière variante d'un niveau 1, 3 ou 5 dépubliée) : il **reste `published`**, reste au vivier pour tout `N ≤ levels_count`, et se joue avec repli de niveau ; **aucune dépublication automatique**. Pourquoi : dépublier sans geste humain ferait disparaître un film d'un lobby sans cause visible, et un curateur qui corrige une variante ne doit pas perdre le film entier. Un niveau 1 manquant rend son palier 1 **plus facile** (`2,3,4,5` à `N` = 3 → niveau 2 au palier 1, dans sa plage depuis D45 du 01/10) : c'est pourquoi `20` signale le film « incomplet » dès que `levels_mask & publishableLevelsMask() ≠ publishableLevelsMask()` (E10-23, `20` § 6.6), et montre le repli de chaque `N` par `usesFallback()` (contrat C1), seule chose que cette spec lui fournit. Les deux prédicats diffèrent et ne se substituent pas l'un à l'autre : un film `1,2,3,5` est en repli à `N` = 4 sans être incomplet ; un film `2,3,4,5` est incomplet sans être en repli à `N` = 3.

---

## 3. Le vivier — constructeur unique (contrat C2) [J1 ; thèmes effectifs au J1 depuis D43 du 01/10, sélecteur au J2]

### 3.1 Deux viviers, une fonction

`00` définit le vivier comme fonction du couple (thèmes, `N`) alors que la non-répétition le rend aussi fonction du salon (n° 24). Les deux notions sont donc nommées et servies par **la même requête** :

- **vivier catalogue** (thèmes, `N`) : supervision back-office, seuil du sélecteur, solo ;
- **vivier du salon** (thèmes, `N`, salon) : compteur du lobby, garde et tirage du lancement, leurres ;
- **réserve non publiée des leurres** (`PoolScope::asDecoyReserve()`) : le seul rang R6 de dernier recours des leurres (`70` § 10.3), films `draft` ou `unpublished` et `clear` — ni compté, ni tiré comme film de manche — amendé le 02/10 (D53 du 02/10).

`App\Support\Draw\PoolQuery` est **le** constructeur de requête du vivier : **aucun autre prédicat de vivier n'existe dans `app/`**. Pourquoi une seule fonction : le lobby qui annonce 47 films et la garde de lancement qui n'en trouve que 7, sans cause visible entre deux écrans, est exactement la panne que `10` § 12 point (1) décrit ; trois requêtes divergent déjà dans le dépôt (encadré).

```php
final readonly class PoolQuery
{
    public function movies(PoolScope $scope): Builder;   // Builder<Movie>, movie ⋈ movie_projection, trié par movie.id croissant ;
                                                         // sélectionne movie.* seulement (->select('movie.*')), tables non aliasées
    public function countWorks(PoolScope $scope): int;    // refuse la réserve des leurres (D53 du 02/10)
    public function candidates(PoolScope $scope): array; // list<PoolCandidate>, triés par movieId croissant ; refuse la réserve des leurres (D53 du 02/10)
    public function publishedThemeIds(): array;          // list<int> des thèmes is_published, croissants
    public function publishedThemeIdsByKey(): array;     // array<string, int> theme.key → theme.id, triés par sort_order puis key
    public function effectiveThemeIds(PoolScope $scope): array; // list<int> : themeIds ∩ publishedThemeIds(), croissants ; [] sans lecture si themeIds = []
    public function themesPruned(PoolScope $scope): bool;       // § 3.3 clause 3 ; lu par PoolReporter pour PoolReport::$themesPruned
}
final readonly class PoolCandidate { public function __construct(public int $movieId, public ?int $groupId, public int $levelsMask) {} }
```

`publishedThemeIds()` alimente `RoomSettings::normalize($raw, $version, $availableThemeIds)` ; `publishedThemeIdsByKey()` alimente `RoomSettingsEditor` (contrat C0, R-12). Les deux décrivent le même ensemble, et **seules les clés quittent le serveur** (`10` § 1.1, E10-11) : `theme.id` n'apparaît dans aucune charge utile. Chacune est une lecture de quelques dizaines de lignes (table `theme`, sans index hors `theme_key_uq`, `10` § 3.7), **mémorisée pour la durée de l'instance** de `PoolQuery` et jamais au-delà (par `once()` de Laravel, indexé par l'instance (`WeakMap`), la classe restant `final readonly` : PHPStan niveau 7 refuse une propriété `readonly` non initialisée affectée hors du constructeur, et le dépôt n'admet aucun `@phpstan-ignore` ; une seule lecture de `theme` — `id`, `key`, `sort_order` — alimente les deux méthodes, qui décrivent donc le même ensemble par construction, et le tri par `sort_order` puis `key` se fait en PHP, octet par octet (`strcmp`), pour ne pas dépendre de la collation MySQL — amendé le 25/09 (E35-1)) : `PoolQuery` est lié `scoped` dans le conteneur, donc une instance par requête HTTP ou par job, que partagent `PoolReporter` et `GameDrawer` (question laissée libre par le contrat C2 § 8). Pourquoi pas de cache partagé : il faudrait l'invalider à chaque geste de publication, et une dépublication doit valoir **à la requête suivante**, ce que la portée `scoped` garantit sans invalidation. Pourquoi mémoriser tout de même : sans cela, chaque compte qui porte des thèmes relirait `theme` (§ 4.3, § 6.2), et la garde et le tirage d'un même lancement lisent ainsi le même ensemble de thèmes publiés.

`movies()` ne sélectionne que `movie.*` : `movie_projection` porte ses propres `created_at` et `updated_at`, et une jointure sans `select` hydraterait des `Movie` aux horodatages de la projection. Les appelants (`70` pour les leurres) filtrent sur `movie_projection.*` sans le sélectionner.

`effectiveThemeIds()` et `themesPruned()` sont un **ajout pur** au contrat C2, qui ne donnait à `PoolQuery` que les cinq premières méthodes : aucun nom ni paramètre existant ne change. Pourquoi : le test de L30-2 « un thème dépublié est élagué, signalé par themesPruned… » exige le signal avant L30-3, et la règle de la clause 3 (intersection avec les publiés, `[]` sans lecture, exception J2 de `themeProbe()`) ne doit être écrite qu'à un endroit. `PoolReporter` les lit pour `PoolReport::$themesPruned` et pour la condition `effective ≠ []` de la cause `themeKeys` (§ 4.3). — amendé le 25/09 (E35-2)

### 3.2 `PoolScope` : les entrées figées du prédicat

```php
final readonly class PoolScope
{
    private function __construct(
        public array $themeIds,                  // list<int> demandés, non filtrés
        public ?int $framesPerRound,             // null = aucune clause de N (complément des leurres seulement)
        public ?int $roomId,                     // axe salon ; null en solo et pour le vivier catalogue
        public ?CarbonImmutable $memorySince,    // non nul ssi roomId non nul
        public ?CarbonImmutable $playedUntil,    // = $now ; non nul ssi roomId non nul
        public bool $noRepeatMovies,             // clause active (exige roomId)
        public array $excludedMovieIds,          // list<int>
        public array $excludedGroupIds,          // list<int>
        public bool $themesUnpublishedIncluded = false, // J1 depuis D43 du 01/10 (L30-11a) : vrai pour themeProbe() seul (§ 3.3 clause 3, § 12.3)
        public bool $decoyReserve = false,             // D53 du 02/10 : vrai pour asDecoyReserve() seul (§ 3.3 clause 1, 70 § 10.3)
    ) {}
    public static function forRoom(Room $room, RoomSettings $settings, CarbonImmutable $now): self;
    public static function forGame(Game $game, CarbonImmutable $now): self;
    public static function forDecoys(Round $round, CarbonImmutable $now): self;
    public static function catalogue(array $themeIds, ?int $framesPerRound): self;
    public static function themeProbe(int $themeId): self;   // J1 depuis D43 du 01/10 (L30-11a) : mesure d'un thème, publié ou non, supervision seule (§ 12.3)
    public function withThemeIds(array $themeIds): self;
    public function withFramesPerRound(?int $framesPerRound): self;
    public function withoutNoRepeat(): self;                 // diagnostic du rapport (§ 4.3) et rang R5 des leurres (D53 du 02/10)
    public function asDecoyReserve(): self;                  // D53 du 02/10 : rang R6 des leurres seul (70 § 10.3)
    public function excluding(array $movieIds, array $groupIds): self;
}
```

Un `PoolScope` est construit **une fois** et passé tel quel : c'est ce qui garantit que la garde et le tirage lisent la même fenêtre (§ 6.1).

| Constructeur | Thèmes, `N`, non-répétition | Salon | Usage |
|---|---|---|---|
| `forRoom($room, $settings, $now)` | lus dans `$settings` (`room.settings` au lobby, preset pour le grisage) | `roomId = room.id` ; `memorySince = RoomMemoryWindow::since(room.id, $now)`, calculé **une fois** ; `playedUntil = $now` | lobby, garde, tirage multijoueur, presets grisés |
| `forGame($game, $now)` | lus dans `game.settings_snapshot` | `game.room_id` ; solo : `roomId` nul, `noRepeatMovies` faux | base de `forDecoys` |
| `forDecoys($round, $now)` | `forGame($round->game, $now)` | idem | leurres (§ 10), avec les exclusions de D21 du 23/09 |
| `catalogue($themeIds, $N)` | fournis | aucun ; `noRepeatMovies` faux ; aucune exclusion | supervision, sélecteur, solo |
| `themeProbe($themeId)` [J1 depuis D43 du 01/10] | `[$themeId]`, **sans intersection avec les thèmes publiés** (`themesUnpublishedIncluded` vrai) ; `N` = `RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND` ; `noRepeatMovies` faux | aucun ; aucune exclusion | mesure d'un thème avant sa publication par `20` (§ 12.3), jamais un salon ni un tirage |
| `forDecoys(...)->asDecoyReserve()` [D53 du 02/10] | `[]`, `N` nul, `noRepeatMovies` faux ; clause de catalogue « `draft` ou `unpublished`, `clear` » (`decoyReserve` vrai) | celui de `forDecoys` ; exclusions de `forDecoys` conservées | rang R6 de dernier recours des leurres (`70` § 10.3), jamais un salon, un tirage de films ni un compteur — amendé le 02/10 |

Les deux ajouts `themeProbe()` et `themesUnpublishedIncluded` étendent le contrat C2 sans en changer aucun nom ni aucun paramètre existant (paramètre final à valeur par défaut). Ils sont propres au J2, que C2 § 8 laisse à cette spec (« l'évaluateur de thèmes … au J2, hors de ce contrat »), et sont **signalés au porteur** — amendé le 01/10 (D43 du 01/10) : livrés au J1 par L30-11a, sans changer de forme. Pourquoi une entrée dédiée plutôt qu'un appel à `catalogue([$themeId], N)` : pour un thème non publié, la clause 3 intersecte avec les thèmes publiés, retombe sur la branche sans thème et compterait **tout** le catalogue.

**Formes fixées par le code livré**, que le contrat C2 laissait libres — amendé le 25/09 (E35-4) :

- le constructeur privé garde ses invariants et lève `InvalidArgumentException`, **jamais d'écrêtage** : `N` non nul dans les bornes de `RoomSettingsBounds` ; `memorySince` et `playedUntil` non nuls si et seulement si `roomId` l'est ; `noRepeatMovies` exige `roomId` ; `decoyReserve` exige `themeIds = []`, `N` nul, `noRepeatMovies` faux, `themesUnpublishedIncluded` faux et au moins un film exclu (la cible) — amendé le 02/10 (D53 du 02/10) : la réserve non publiée ne devient jamais un vivier de salon, et `PoolQuery::countWorks()` et `candidates()` la refusent ;
- `excluding()` **ajoute** aux exclusions déjà portées (union triée, sans doublon), pour que `forDecoys()` puis un appelant cumulent sans perdre la cible ;
- `forGame()` d'une partie solo délègue à `catalogue()` sur l'instantané (thèmes, `N`) : aucune clause de salon, `noRepeatMovies` ignoré (§ 9), aucune lecture de `round` à la construction ;
- `forDecoys()` fait deux lectures (films des manches démarrées de la partie, `started_at <= $now`, statut indifférent ; `group_id` de la cible), plus celles de `forGame()`.

### 3.3 Le prédicat, clause par clause

Toutes les clauses s'appliquent en ET ; aucune n'est optionnelle sauf mention.

1. **Catalogue** : `movie.availability = 'published'` ET `movie.content_flag = 'clear'`, par le scope existant `Movie::inPool()`. `content_flag` y est parce qu'un film `published` + `blocked` doit sortir du vivier à la seconde, sans attendre le curateur (`10` A3, décision 12). **Seule exception** — amendé le 02/10 (D53 du 02/10) : `decoyReserve` vrai (`PoolScope::asDecoyReserve()`) ⇒ `movie.availability IN ('draft', 'unpublished')` ET `movie.content_flag = 'clear'`, par `Movie::inDecoyReserve()` (même index `movie_pool_idx`) ; jamais `suspended` ni `withdrawn`, et le filtre de contenu n'est contourné par aucune voie. Ce périmètre ne sert que le rang R6 des leurres (`70` § 10.3) : un film non publié n'y est qu'un titre proposé, jamais une cible.
2. **Éligibilité à `N`** (si `framesPerRound` non nul) : `movie_projection.levels_count >= N`. La jointure interne sur `movie_projection` est **toujours** présente, tables non aliasées. **Aucun `levels_mask & 21`** (§ 2.5, n° 4).
3. **Thèmes** : `effective = themeIds ∩ publishedThemeIds()` ; `themeIds = []` ⇒ `effective = []` **sans lire les thèmes publiés** (le cas par défaut, le seul que l'écran du J1 produise, ne paie aucune lecture de `theme`).
   - `effective = []` : **branche sans thème**, jamais un `IN ()` — un `IN` vide rendrait un vivier de 0 et accuserait les thèmes alors qu'aucun n'a été choisi, sur le cas **par défaut** du réglage (`10` § 12 point (2)).
   - sinon : `EXISTS movie_theme (movie_id = movie.id, theme_id IN effective, is_active = true)`. **Union (OU)**, jamais intersection (`00` § Réglages du salon, `10` § 1.6 point 2) : l'hôte qui coche « Pixar » et « Ghibli » veut les films de l'un **ou** de l'autre.
   - `themesPruned = (themeIds ≠ [] ET themeIds ⊄ publishedThemeIds())`. Un thème dépublié alors qu'il figure encore dans les réglages d'un salon ouvert est **élagué à la lecture**, signalé par ce booléen, et ses lignes `movie_theme` actives ne filtrent plus rien. Pourquoi à la lecture et pas dans `room.settings` : les réglages ne sont réécrits que par `WriteRoomSettings` (contrat C0) et `normalize()` n'élague qu'au chargement ; le vivier doit être juste dès la requête suivante.
   - **Seule exception, J1 depuis D43 du 01/10** : `themesUnpublishedIncluded` vrai (`PoolScope::themeProbe()`, § 12.3) ⇒ `effective = themeIds`, **sans lire les thèmes publiés**, et `themesPruned` faux. Aucun salon, aucun tirage ni aucun leurre ne construit ce périmètre : il ne sert qu'à mesurer un thème avant sa publication.
4. **Non-répétition** (si `noRepeatMovies`) : `NOT EXISTS round (round.room_id = :roomId AND round.movie_id = movie.id AND round.started_at >= :memorySince AND round.started_at <= :playedUntil)`. C'est la définition de « joué » du § 1.3. Elle porte sur le **film**, jamais sur le `movie_group` : la seule conséquence d'un groupe est l'exclusion mutuelle dans un même tirage (`CLAUDE.md` § 6).
5. **Exclusions** : `movie.id NOT IN excludedMovieIds` ET (`movie.group_id IS NULL` OU `movie.group_id NOT IN excludedGroupIds`). Chaque clause est **omise** quand sa liste est vide, pour la même raison que le `IN ()` de la clause 3.

Les viviers catalogue et solo, et la mesure d'un thème (`themeProbe`), ne portent **aucune clause de salon** : `noRepeatMovies` y est ignoré, et le code `noRepeatMovies` ne peut jamais apparaître dans leur rapport.

### 3.4 L'unité de compte : l'œuvre

Le vivier compte des **œuvres**, pas des films (n° 23, E10-64) :

```sql
COUNT(DISTINCT CASE WHEN movie.group_id IS NULL THEN movie.id END) + COUNT(DISTINCT movie.group_id)
```

Pourquoi : le tirage ne retient jamais deux films d'un même `movie_group` (`questions-ouvertes.md` § Homonymes et remakes). Compté en films, un vivier égal à `M` qui contient Old Boy 2003 et Old Boy 2013 passe la garde et ne remplit que `M − 1` manches — la panne que `10` A15 voulait éviter en refusant `frame_coverage`. L'expression est portable SQLite / MySQL, **sans `GROUP BY`** (`ONLY_FULL_GROUP_BY` est actif en dev et pas en test, `10` § 1.4). Elle perd le caractère couvrant de `movie_pool_idx`, `group_id` n'y figurant pas : c'est négligeable à quelques centaines de films.

Une seule unité partout : `PoolReport::$count` = `game.draw_pool_size` = `DrawResult::$poolSize` (E10-37). La marge, `K` et la borne croisée 3 s'expriment en œuvres.

### 3.5 Non-répétition et mémoire du salon

```php
final readonly class RoomMemoryWindow
{
    public static function since(int $roomId, CarbonImmutable $now): CarbonImmutable;
}
```

`since(room, now) = max(now − roomMemoryWindowDays() jours, t)`, où `t` est le `started_at` de la `roomMemoryWindowRounds()`-ième manche **jouée** la plus récente du salon (même prédicat `started_at <= now`, `ORDER BY started_at DESC, id DESC`, décalage `roomMemoryWindowRounds() − 1`, servi par `round_room_started_movie_idx`), ou `now − roomMemoryWindowDays()` seul s'il y en a moins. **La borne atteinte en premier gagne.** Défauts : 90 jours et 500 manches, déclarés par cette spec et portés par `PlatformLimits::roomMemoryWindowDays()` / `roomMemoryWindowRounds()` (bornes ≥ 1, surchargeables par `config/game.php › platform`, contrat C0, R-06).

**Une seule fenêtre pour trois usages** — la non-répétition des films (§ 3.3 clause 4), la préférence de variante (§ 7) et la purge de `seen_frame` (`10` § 11.1) — parce qu'une seule notion de « récemment vu par le salon » s'explique et se purge (recommandation de `10` § 12, point (1), devenue règle : E10-56, E10-64). Aucune de ces valeurs n'entre dans un calcul rejoué — le tirage est matérialisé —, donc aucune n'incrémente `scoring_version` ni `validation_version`.

Pourquoi le salon et jamais le joueur : la mémoire est portée par `round.room_id`, dénormalisée au lancement (`10` § 7.4). **Un nouveau salon repart d'une mémoire vide**, ce qui fait du remède « nouveau salon » un vrai remède et non un contournement (§ 4.5, D28 du 23/09).

### 3.6 Forme SQL, index et portabilité

Forme retenue (la forme exacte est laissée libre par le contrat C2, à résultat égal et sans `GROUP BY`) : **`EXISTS` pour les thèmes, `NOT EXISTS` pour la non-répétition**, plutôt qu'une jointure plus `DISTINCT`. Pourquoi : `candidates()` et `movies()` n'ont alors jamais de doublon à dédoublonner, et `COUNT(DISTINCT …)` reste exact sans dépendre de la cardinalité de `movie_theme`.

```sql
SELECT COUNT(DISTINCT CASE WHEN movie.group_id IS NULL THEN movie.id END)
     + COUNT(DISTINCT movie.group_id) AS works
FROM movie
INNER JOIN movie_projection ON movie_projection.movie_id = movie.id
WHERE movie.availability = 'published' AND movie.content_flag = 'clear'           -- movie_pool_idx
  AND movie_projection.levels_count >= :n                                          -- si N non nul
  AND EXISTS (SELECT 1 FROM movie_theme                                            -- si effective ≠ [] ; movie_theme_pool_idx
              WHERE movie_theme.movie_id = movie.id
                AND movie_theme.theme_id IN (:effective) AND movie_theme.is_active = 1)
  AND NOT EXISTS (SELECT 1 FROM round                                              -- si noRepeatMovies ; round_room_started_movie_idx
                  WHERE round.room_id = :room AND round.movie_id = movie.id
                    AND round.started_at >= :since AND round.started_at <= :until)
  AND movie.id NOT IN (:excludedMovies)                                            -- si non vide
  AND (movie.group_id IS NULL OR movie.group_id NOT IN (:excludedGroups))          -- si non vide
```

`themeIds` est désérialisé en PHP puis passé en `IN` : **jamais de lecture dans le JSON** (`10` § 1.6). Aucun agrégat sur `frame`, aucun `GROUP BY` : le même résultat en SQLite et en MySQL, prouvé par un test du groupe `mysql` (contrat C18). `candidates()` sélectionne `movie.id`, `movie.group_id`, `movie_projection.levels_mask` sous le même `WHERE`, triés par `movie.id`.

**Instants liés au format de la colonne** — amendé le 25/09 (E35-3). `RoomMemoryWindow`, la clause 4 de `PoolQuery` et `PoolScope::forDecoys()` lient `now`, `memorySince` et `playedUntil` par `(new Round)->fromDateTime($at)`, soit le format exact de `round.started_at` (`timestamp(3)`, `Y-m-d H:i:s.v`, `#[DateFormat]` de `Round`), jamais par la liaison par défaut de la grammaire, qui tronque à la seconde. Pourquoi : sous SQLite la comparaison est textuelle, et `'…10:00:00.000' <= '…10:00:00'` est faux ; une manche démarrée à la seconde pile, ou quelques millisecondes avant `now`, ne serait pas « jouée », dans les deux moteurs. C'est la condition pour que « joué ⟺ `started_at <= now` » (§ 1.3) soit exact à la milliseconde.

### 3.7 Consommateurs et invariants

| Consommateur | Appel | Spec |
|---|---|---|
| Compteur du lobby | `PoolReporter::report(PoolScope::forRoom($room, $room->settings, $now), M)` | 50 (déclenchement, anti-rebond, diffusion) |
| Garde et tirage du lancement | même `PoolScope`, une fois, dans la transaction (§ 6.1) | 50 (contrat C6) |
| Grisage des presets | `report(PoolScope::forRoom($room, $preset = SettingPresetCatalog::settingsFor($key), $now), $preset->roundsCount)` : le `N` et le `M` sont ceux du **preset** (chaque preset porte les siens, `00` § Réglages du salon : Rapide à 8 manches, Découverte à 5), la mémoire est celle du salon ; passer le `M` du salon griserait ou dégriserait un preset à tort | 50 |
| Solo | `PoolScope::catalogue(...)` après l'ajustement D19 du 23/09 | 60 |
| Leurres | `PoolQuery::movies(PoolScope::forDecoys($round, $at))`, son complément, et le dernier recours (`withoutNoRepeat()`, `asDecoyReserve()`, D53 du 02/10 — amendé le 02/10) | 70 (§ 10) |
| Supervision « œuvres jouables à N » | `PoolReporter::catalogueWorksByFramesPerRound()` | 20 (affichage) |
| Mesure d'un thème avant publication [J1 depuis D43 du 01/10] | `PoolReporter::themeWorks($themeId)` | 20 (affichage, geste de publication, § 12.3) |

- **Aucun verrou sur les tables de catalogue.** Un retrait ou une suspension validés après la lecture sont absorbés par `60` : substitution ou annulation (§ 8). Dans la transaction de lancement, la cohérence entre la garde et le tirage ne vient pas d'un verrou mais de l'instantané cohérent de la transaction (§ 6.5).
- **Un événement de catalogue n'est pas poussé aux lobbies ouverts** : publication et retrait changent le vivier sans diffusion. Le rapport est recalculé à chaque écriture de réglage et **rejoué au lancement, qui fait seule autorité** (`10` § 6.1) et renvoie le même rapport en cas de refus. Pourquoi : un retrait est rare, et diffuser un recalcul à tous les lobbies ouverts à chaque publication coûterait un fan-out pour un cas que la garde rejouée couvre déjà.
- **Monotonie** : `countWorks(scope avec N+1) ≤ countWorks(scope avec N)`, puisque `levels_count >= N+1` implique `levels_count >= N`.
- Aucun littéral de jeu dans `App\Support\Draw` : bornes dans `RoomSettingsBounds`, marge, fenêtre et seuil dans `PlatformLimits`, états dans `ContentAvailability` et `ContentFlag`.

---

## 4. Garde de lancement et rapport de vivier [J1]

### 4.1 Répartition des rôles sur la borne croisée 3

La borne `vivier(thèmes, N) ≥ M` n'est pas dans `RoomSettings` : elle dépend du catalogue, que le value object ne voit pas (`10` § 6.1). **Cette spec calcule et produit un rapport en données ; `50` déclenche, anti-rebondit, diffuse, affiche et rejoue la garde dans la transaction de lancement** (contrats C0 et C6, carte de propriété n° 14). Le rapport ne contient **aucune chaîne** (règle 4) : `50` rend les clés `room.pool.cause.<PoolFault>` et `room.pool.remedy.<PoolRemedyKind>` dans la langue de chaque joueur.

### 4.2 `PoolReport` : forme et sémantique

```php
final readonly class PoolReporter
{
    public function __construct(private PoolQuery $pool) {}
    public function report(PoolScope $scope, int $roundsCount): PoolReport;             // exige scope->framesPerRound !== null (InvalidArgumentException sinon) ; M pris tel quel
    public function nearestPlayableFramesPerRound(PoolScope $scope, int $roundsCount): ?int; // même garde de N
    public function themeSelectorVisible(): bool;                                        // J2 (§ 15)
    public function catalogueWorksByFramesPerRound(): array;                             // array<int, int>, N de MIN à MAX_FRAMES_PER_ROUND
    public function themeWorks(int $themeId): int;                                       // J1 depuis D43 du 01/10 (L30-11a) : countWorks(PoolScope::themeProbe($themeId)) (§ 12.3)
}
final readonly class PoolReport implements Arrayable
{
    public function __construct(public int $count, public int $framesPerRound, public int $roundsCount,
        public array $causes, public array $remedies, public ?int $nearestPlayableFramesPerRound, public bool $themesPruned) {}
        // InvalidArgumentException si non bloqué avec une cause, un remède ou un N proposé (§ 4.3)
    public function blocked(): bool;   // $count < $roundsCount
    public function toArray(): array;
}
final readonly class PoolRemedy implements Arrayable { public function __construct(public PoolRemedyKind $kind, public ?int $value, public int $count) {} }
final class PoolTooSmallException extends \RuntimeException { public function __construct(public readonly int $works, public readonly int $roundsCount) {} }
```

Enums nouveaux, qui ne castent aucune colonne (aucune exigence de schéma) : `App\Enums\PoolFault` (`NoRepeatMovies = 'noRepeatMovies'`, `ThemeKeys = 'themeKeys'`, `FramesPerRound = 'framesPerRound'`, `RoundsCount = 'roundsCount'`) et `App\Enums\PoolRemedyKind` (`OpenNewRoom = 'open_new_room'`, `DisableNoRepeat = 'disable_no_repeat'`, `ClearThemes = 'clear_themes'`, `LowerFramesPerRound = 'lower_frames_per_round'`, `ReduceRoundsCount = 'reduce_rounds_count'`). **Chaque valeur de `PoolFault` est une clé postable de `RoomSettingsEditor`** : un code qui voyage vers le client y désigne le champ fautif sous son nom client (`themeKeys` et non `themeIds`, R-11). Miroir client unique : `resources/js/types/pool.ts` (`PoolFault`, `PoolRemedyKind`, `PoolRemedy`, `PoolReport`), jamais redéclaré ailleurs, dans le périmètre `WATCHED` de `90` (R-27, R-36).

**Gardes du rapport** — amendé le 25/09 (E36-1). `report()` et `nearestPlayableFramesPerRound()` lèvent `InvalidArgumentException` pour un périmètre sans `N`, seule garde d'entrée ; le constructeur de `PoolReport` refuse un vivier non bloqué qui porterait une cause, un remède ou un `N` proposé (§ 4.3). **`M` n'est pas borné** : un resserrement de borne incrémente `RoomSettings::VERSION` (`10` § 6.1, `50` § 2.1 règle 4), le cast relit l'ancienne version sans écrêter (`RoomSettings::fromStorage`) et le lobby appelle `report()` sur ces réglages bruts (`50` § 2.6) ; seule l'étape L5 du lancement (`50` § 12.2) normalise. Une garde de `M` ferait échouer `RoomSettingsPresenter::state()`, chaque `BroadcastLobbyState` et le grisage des presets de tout salon ouvert pendant le déploiement : le rapport se calcule, le lancement normalise. Effet de bord connu, transitoire : pour un `M` périmé au-dessus d'une nouvelle `MAX_ROUNDS_COUNT` et un vivier dans `]MAX, M[`, le remède `reduce_rounds_count` porte `value = count > MAX`, valeur non postable ; laissé en l'état (lecture littérale du § 4.3), signalé au porteur (§ Ce que cette spec ne décide pas).

`PoolReport::toArray()` est **la forme unique** au lobby, au refus de lancement et en solo : clés camelCase, entiers, booléens et codes seulement. Exemple au réglage par défaut, quand l'hôte passe de `N` = 3 à `N` = 5 et fait tomber le vivier de 47 à 4 œuvres (`00` § Déroulé d'une partie) :

```json
{
  "count": 4, "framesPerRound": 5, "roundsCount": 10, "blocked": true,
  "causes": ["framesPerRound", "roundsCount"],
  "remedies": [
    {"kind": "lower_frames_per_round", "value": 3, "count": 47},
    {"kind": "reduce_rounds_count", "value": 4, "count": 4}
  ],
  "nearestPlayableFramesPerRound": 3,
  "themesPruned": false
}
```

Il ne contient aucune donnée de joueur, aucun titre, aucun identifiant interne (`themesPruned` est un booléen, jamais une liste d'identifiants) et rien du tirage : il ne révèle aucune bonne réponse (règle 3).

### 4.3 Causes et remèdes

Vivier non bloqué : `causes = []`, `remedies = []`, `nearestPlayableFramesPerRound = null`. Vivier bloqué : causes et remèdes dans **cet ordre fixe**, et **chaque remède débloque à lui seul**. Pourquoi « à lui seul » : un remède qui ne suffit pas renvoie l'hôte à un second refus, et le message nommant le réglage fautif (`00` § Réglages du salon) deviendrait une devinette.

| Cause (`PoolFault`) | Condition | Remède(s), dans l'ordre |
|---|---|---|
| `noRepeatMovies` | `scope.noRepeatMovies` ET `countWorks(scope->withoutNoRepeat()) ≥ M` | `open_new_room` {value: null, count}, puis `disable_no_repeat` {value: null, count} |
| `themeKeys` | `effective ≠ []` ET `countWorks(scope->withThemeIds([])) ≥ M` | `clear_themes` {value: null, count} |
| `framesPerRound` | `nearestPlayableFramesPerRound ≠ null` | `lower_frames_per_round` {value: N', count} |
| `roundsCount` | `count ≥ RoomSettingsBounds::MIN_ROUNDS_COUNT` | `reduce_rounds_count` {value: count, count} |

- `count` d'un remède = le vivier obtenu en l'appliquant ; pour `reduce_rounds_count`, `value = count` parce que réduire `M` au vivier courant est le remède le plus proche du réglage de l'hôte (`00` § Réglages du salon : « réduire `M` est un remède aussi légitime qu'élargir les thèmes »).
- **Bloqué avec `causes = []`** : aucun réglage ne débloque à lui seul, le catalogue est insuffisant. Le texte appartient à `50` (`room.pool.no_remedy`).
- **Coût** : non bloqué, un seul `countWorks`. Bloqué, au plus un compte par cause testée plus `N − MIN_FRAMES_PER_ROUND` comptes pour le `N` jouable le plus proche, soit six requêtes au pire sans thème, toutes indexées, derrière l'anti-rebond de `50`. Avec thèmes (`effective ≠ []`), une lecture de `publishedThemeIds()` de plus, une seule fois par instance de `PoolQuery` (§ 3.1) : sept au pire. La fenêtre de mémoire, lue une fois à la construction du `PoolScope` (§ 3.2), n'est pas comptée ici.

### 4.4 `N` jouable le plus proche : presets grisés et solo (D19 du 23/09)

`nearestPlayableFramesPerRound(scope, M)` = le **plus grand** `N' ∈ [MIN_FRAMES_PER_ROUND, N − 1]` tel que `countWorks(scope->withFramesPerRound(N')) ≥ M`, `null` sinon. Le vivier étant monotone décroissant en `N` (§ 3.7), c'est équivalent à une recherche par |ΔN| croissant, et **il n'existe jamais de `N' > N` jouable** : la proposition est unique et toujours vers le bas. Le même calcul sert au grisage des presets (`50`, `00` § Réglages du salon) et au `N` imposé d'office en solo (D19 du 23/09 : Hardcore passe à `N` = 3 au J1 ; application et annonce à l'écran par `60`).

### 4.5 Vivier bloqué par la seule non-répétition (D28 du 23/09)

La non-répétition **retire** des films du vivier et la garde bloque : c'est le comportement décidé (`00` § Réglages du salon et § Déroulé d'une partie, `10` § 12), conservé (question Q30-1 écartée comme rouvrant un acquis). Au J1, 60 films publiés et `M` = 10 au réglage par défaut donnent environ **six parties** sans répétition dans un même salon (§ 1.3), puis un blocage. En général, pour `F` œuvres publiées, un salon joue `⌊F / M⌋` parties sans répétition, puisque seules les manches démarrées entrent dans sa mémoire : six à `F` = 60, quatre à `F` = 40 si la variable « nombre de films » de D10 du 23/09 réduit le J1. Le cas terminal des leurres (§ 10.3) devient possible à la dernière de ces parties quand son vivier vaut moins de `M` + 3, et certain en fin de partie quand il vaut `M` (cas de `F` multiple de `M`). Aucune règle de cette spec ne change avec `F` : seuls ces effectifs changent. Le rapport nomme alors **`noRepeatMovies`** comme cause, en données, et propose **`open_new_room`** : un nouveau salon a une mémoire vide (§ 3.5). **Au J1, le présentateur de `50` retire `disable_no_repeat`** des remèdes, l'interrupteur vivant dans l'onglet Avancé, livré au J2 (D28 du 23/09 ; amendement A-13) ; cette spec produit toujours les deux remèdes, pour que le J2 ne demande aucun changement de calcul.

### 4.6 Fraîcheur, diffusion et garde défensive

- Le rapport est diffusé **au salon, identique pour tous** (`00` § Déroulé d'une partie), dans `RoomSettingsState` (contrat C0) ; nom d'événement, canal et enveloppe appartiennent à `60`. En solo, il n'est rendu qu'à la page du joueur.
- `draw_pool_size` reste `#[Hidden]` **par hygiène** : il n'apprend rien que le compteur du lobby n'ait dit quelques secondes plus tôt ; la justification « la connaître réduirait le champ des films possibles » tombe (E10-37).
- **`PoolTooSmallException`** est une garde défensive du tirage, jamais un chemin nominal. Après un rapport non bloqué, seule une **projection périmée** sur au moins `W − M + 1` œuvres peut la lever (§ 6.3, œuvre sautée), parce que la garde et le tirage lisent le même instantané de la transaction (§ 6.5) : c'est une incohérence de données, que `catalog:reproject` répare, pas un réglage. La transaction de lancement est annulée ; l'exception n'est **jamais** traduite en `pool_insufficient`, dont le rapport affirmerait le contraire. La réponse faite à l'hôte relève de `50`.

---

## 5. Graine et PRF à contextes nommés (contrat C3) [J1]

### 5.1 Graine

`SeededPrf::generateSeed()` = `bin2hex(random_bytes(SeededPrf::SEED_BYTES))`, `SEED_BYTES = 32` (constante de sécurité de `10` § 7.2), soit 64 caractères hexadécimaux. **C'est le seul appel à `random_bytes` dans `App\Support\Draw` et `App\Support\Answers`, et la seule source de la graine** : `OpenGame` (contrat C6, étape O4) l'appelle, jamais `bin2hex(random_bytes(32))` en ligne. Les secrets CSPRNG qui ne dérivent pas de la graine — `serve_token` (`bin2hex(random_bytes(16))`, `MintTierServeToken`, `60` § 6.2), `tid` du `player_token` (`bin2hex(random_bytes(32))`, `40` § 3.1), code de salon (`random_int`, `50` § 6.3), `active_seat_token` (ULID applicatif, `60`) — sont hors de cette règle, même quand leur chemin appelle aussi la PRF (la frappe du `serve_token` appelle `VariantChooser::substitute()`, § 8.1) : ils n'entrent dans aucun tirage et n'ont pas à être rejouables. La graine est stockée dans `game.draw_seed` (`#[Hidden]`) et n'est **jamais** sérialisée.

Pourquoi un CSPRNG de 256 bits et une PRF : l'implémentation naïve (`mt_srand(crc32($seed))` puis `shuffle`) n'a qu'un état de 32 bits ; le tricheur qui observe le film de la manche 1 à la révélation essaie les 2³² graines hors ligne, retient celles qui la reproduisent et **connaît les manches 2 à `M` et la variante de chaque palier** (`10` § 7.2). Et la même graine sert un usage secret (le tirage) et un usage révélé (la permutation du QCM, que chaque joueur observe) : seule une PRF empêche les sorties publiques d'exposer le secret.

### 5.2 PRF

```php
final readonly class SeededPrf
{
    public const int SEED_BYTES = 32;
    public function __construct(#[\SensitiveParameter] private string $seed) {} // exactement 64 caractères [0-9a-f], sinon InvalidArgumentException
    public static function generateSeed(): string;
    public static function forGame(Game $game): self;                           // new self($game->draw_seed)
    public function index(DrawContext $context, int $count): int;               // uniforme dans [0, count), 1 ≤ count ≤ 2^31
    public function permutation(DrawContext $context, int $count): array;       // list<int>, permutation de 0..count−1
}
```

Algorithme **normatif** (E10-40) :

- bloc `b = 0, 1, 2…` : `hash_hmac('sha256', $context->value.'#'.$b, $seed, true)`, la clé étant `game.draw_seed` **tel que stocké** (chaîne hexadécimale) ;
- chaque bloc est découpé par `unpack('N8')` en 8 mots de 32 bits, consommés dans l'ordre ;
- `uniform(bound)` : `limit = 2³² − (2³² mod bound)` ; tout mot `≥ limit` est **rejeté** ; sinon renvoyer `mot mod bound`. Pourquoi le rejet : un `mod` nu favorise les petites valeurs dès que `bound` ne divise pas 2³², biais minuscule mais mesurable sur des milliers de tirages ;
- `index(ctx, count)` = premier `uniform(count)` du flux de `ctx` ;
- `permutation(ctx, count)` = Durstenfeld sur `[0..count−1]`, `i` de `count − 1` à 1, `j = uniform(i + 1)`, échange de `a[i]` et `a[j]`, les tirages consommés successivement dans le flux de `ctx`.

**Domaines livrés** — amendé le 28/09 (E70-2). `index` lève `InvalidArgumentException` hors de `[1, 2³¹]`. `permutation` accepte `count ≥ 0` : `count = 0` rend `[]` sans rien consommer (permutation de l'ensemble vide : un rang de leurres vide chez `70` n'a pas à être traité à part), `count = 1` rend `[0]`, un `count` négatif lève. La borne 2³¹ de `permutation` n'est que celle héritée de `uniform`, **sans garantie d'usage** : bien avant elle, `range()` et le tableau épuisent la mémoire, et `count` est une taille de vivier.

### 5.3 Registre fermé `DrawContext`

```php
final readonly class DrawContext
{
    private function __construct(public string $value) {}
    public static function movies(): self;                                              // 'draw:movies'
    public static function workMember(int $sequenceIndex): self;                        // 'draw:work-member:{s}'
    public static function variant(int $sequenceIndex, int $tierIndex): self;           // 'draw:variant:{s}:{t}'
    public static function substitute(int $sequenceIndex, int $tierIndex): self;        // 'draw:substitute:{s}:{t}'
    public static function decoys(int $sequenceIndex): self;                            // 'draw:decoys:{s}'           (70, rangs R1-R2)
    public static function decoysOriginal(int $sequenceIndex): self;                    // 'draw:decoys:{s}:original'  (70, rangs R3-R4)
    public static function decoysAffinity(int $sequenceIndex): self;                    // 'draw:decoys:{s}:affinity'  (70 § 10.3 bis, D44)
    public static function decoysAffinityOriginal(int $sequenceIndex): self;            // 'draw:decoys:{s}:affinity-original' (idem, dégradé)
    public static function decoysLastResort(int $sequenceIndex): self;                  // 'draw:decoys:{s}:last-resort' (70 § 10.3, rangs R5-R6, D53)
    public static function decoysLastResortOriginal(int $sequenceIndex): self;          // 'draw:decoys:{s}:last-resort-original' (idem, dégradé)
    public static function qcmOrder(int $sequenceIndex, string $playerPublicId): self;  // 'draw:qcm:{s}:{publicId}'   (70)
}
```

**Aucune autre chaîne de contexte n'existe** : le constructeur est privé. Deux écarts à la liste d'exemples de `10` § 7.2, qui n'étaient que des exemples (E10-40, E10-54) :

- **`{roundId}` devient `{sequenceIndex}`**, et `{playerId}` devient `{playerPublicId}`. `sequence_index` est unique par partie (`round_game_sequence_uq`) et connu **avant** l'insertion des lignes : le tirage devient une fonction pure, testable sans base et rejouable sans identifiant attribué par la base ; `player.public_id` est l'identité de siège stable (`10` § 7.1).
- **Le contexte `tiebreak:{roundId}` est retiré** (contradiction n° 66). Il n'avait aucun usage assigné et se lisait comme un départage de **score**. La seule égalité que la graine départage est l'égalité **de variantes**, dans `draw:variant` (§ 7) et `draw:substitute` (§ 8) ; **aucune égalité de score n'est tranchée par la graine** — la chaîne de départage appartient à `80` (amendement A-19).

**Gardes des fabriques** — amendé le 28/09 (E70-2) : `InvalidArgumentException` pour `sequenceIndex < 1`, `tierIndex < 1` et un `playerPublicId` vide, **jamais d'écrêtage** — un index nul désignerait un flux que le tirage matérialisé n'a jamais lu. Aucun nom, paramètre ni vecteur du contrat C3 ne change ; la réflexion de `SeededPrfTest` prouve la classe finale et `readonly`, le constructeur privé et les sept fabriques exactement. Amendé le 01/10 (D44 du 01/10) : **neuf fabriques**, `decoysAffinity` et `decoysAffinityOriginal` ajoutées pour les groupes d'affinité des leurres (`70` § 10.3 bis), mêmes gardes, chaînes distinctes de toutes les autres. Amendé le 02/10 (D53 du 02/10) : **onze fabriques**, `decoysLastResort` et `decoysLastResortOriginal` ajoutées pour le dernier recours R5-R6 des leurres (`70` § 10.3), mêmes gardes, chaînes distinctes de toutes les autres.

### 5.4 Vecteurs de référence

Calculés sur l'algorithme du § 5.2 et figés dans `SeededPrfTest` : **une implémentation qui diverge est fausse**. Graine `000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f`.

| Appel | Résultat | Origine |
|---|---|---|
| `permutation(DrawContext::movies(), 10)` | `[0,6,3,8,4,1,7,2,9,5]` | contrat C3 |
| `index(DrawContext::variant(1, 1), 3)` | `2` | contrat C3 |
| `index(DrawContext::substitute(2, 3), 2)` | `0` | contrat C3 |
| `permutation(DrawContext::decoys(4), 5)` | `[4,3,1,2,0]` | contrat C3 |
| `index(DrawContext::workMember(1), 2)` | `1` | contrat C3 |
| `permutation(DrawContext::decoysOriginal(4), 5)` | `[0,1,3,4,2]` | figé par cette spec |
| `permutation(DrawContext::qcmOrder(1, 'K7Q2M9XR4T6W'), 4)` | `[2,3,0,1]` | figé par cette spec |
| `permutation(DrawContext::qcmOrder(1, 'P3N8D5HB2C9F'), 4)` | `[1,0,3,2]` | figé par cette spec (deux sièges, deux ordres) |
| `permutation(DrawContext::decoysAffinity(4), 5)` | `[2,3,1,0,4]` | figé le 01/10 (D44 du 01/10) |
| `permutation(DrawContext::decoysAffinityOriginal(4), 5)` | `[4,1,0,2,3]` | figé le 01/10 (D44 du 01/10) |
| `permutation(DrawContext::decoysLastResort(4), 5)` | `[4,1,2,0,3]` | figé le 02/10 (D53 du 02/10) |
| `permutation(DrawContext::decoysLastResortOriginal(4), 5)` | `[1,4,2,3,0]` | figé le 02/10 (D53 du 02/10) |

Les deux `public_id` d'exemple sont dans l'alphabet base32 de `SeatPublicId::ALPHABET` (ex-`PlayerFactory::PUBLIC_ID_ALPHABET`, déplacé par L50-3a — amendé le 28/09 (E100-3)). Les cinq vecteurs du contrat ont été recalculés avant d'être repris ici.

### 5.5 Interdits

Dans `App\Support\Draw` **et** dans tout chemin qui dépend de la graine (leurres et ordre du QCM de `70` compris) : `mt_srand`, `srand`, `rand`, `mt_rand`, `random_int`, `array_rand`, `shuffle`, `str_shuffle`, `crc32`, `Arr::shuffle`, `Arr::random`, `Collection::shuffle()` et `->random()`. Un `random_int()` non seedé casserait la rejouabilité que la matérialisation existe pour garantir ; un `shuffle` seedé retomberait dans l'état de 32 bits. `DrawResult`, `DrawnRound`, `DrawnTier`, `SeededPrf`, `DrawInput`, `VariantCandidate` et `DrawContext` n'implémentent ni `Arrayable`, ni `Jsonable`, ni `JsonSerializable` : **seuls `PoolReport` et `PoolRemedy` sont sérialisables** dans `App\Support\Draw` (règle 3).

**Garde livrée, plus stricte que cette liste** — amendé le 28/09 (E70-3). `DrawBoundaryTest` interdit en plus `lcg_value` et `openssl_random_pseudo_bytes` (seconde source CSPRNG, concurrente de `generateSeed()`) ; tout nom de l'espace `Random\` et `Randomizer` (API d'aléa native de PHP 8.2, dont `Random\Engine\Mt19937`, graine de 32 bits : exactement l'état que ce paragraphe veut empêcher), à l'import, à l'instanciation et en nom qualifié ; les méthodes `->shuffleArray(`, `->shuffleBytes(` et `->pickArrayKeys(` ; les statiques `::shuffle(` et `::random(` sous **tout** alias de classe, donc `Str::random(` aussi. Le balayage lit le code au tokeniseur (commentaires exclus), compare les noms de fonction sans casse et sous forme pleinement qualifiée, et voit les callables en chaîne comme de première classe ; l'arch `not->toUse` pose une expectation **par espace de noms**, une expectation sur un tableau d'espaces restant inerte.

---

## 6. Tirage des films au lancement [J1]

### 6.1 Ordre d'appel dans la transaction de lancement

La transaction appartient à `50` (contrat C6, étapes L1-L2 et O0-O9) ; cette spec en reprend l'ordre des appels qui la concernent, **sur un seul `$now` et un seul `PoolScope`**, dans la numérotation de C6 :

1. en multijoueur, verrou du salon (`Room::lockForUpdate()`, C6 L1, premier verrou de l'ordre global) ; **en solo, aucun verrou de salon** : il n'y a pas de salon, et `StartSoloGame` (`60`) appelle `OpenGame` directement (C6 O0, `(mode = solo) ⇔ room = null`) ;
2. `$now` unique, celui que reçoit `OpenGame`, pris **après** le verrou en multijoueur (C6 L2) ;
3. `$scope = PoolScope::forRoom($room, $settings, $now)` ; en solo, `PoolScope::catalogue($settings->themeIds, $settings->framesPerRound)` **après** l'ajustement D19 du 23/09 fait par `60` (C6 O2) ;
4. `$report = PoolReporter::report($scope, M)` ; bloqué → refus `pool_insufficient` avec le rapport (C6 O2-O3) ;
5. `$seed = SeededPrf::generateSeed()` (C6 O4) ;
6. INSERT de `game` par `OpenGame` (`50`, C6 O5), avec `draw_seed = $seed` et `draw_pool_size = $report->count`, puis `game_player` (C6 O6) ;
7. `$result = GameDrawer::draw($scope, M, new SeededPrf($seed))`, **sur le même `$scope`** (C6 O7) ;
8. matérialisation par `60` : `MaterializeDraw::handle($game, $result)` (C6 O8), qui n'écrit que `round` et `round_tier`.

**Écart signalé au porteur** : le contrat C3 § 3 (« ordre d'appel imposé ») place l'écriture de `game` **après** le tirage et fait écrire `draw_seed` et `draw_pool_size` par la matérialisation ; C6 § 3 et § 5, `50` et `60` § 5.1 font l'inverse. **C6 fait foi**, `50` étant propriétaire de la transaction (R-15 : « étapes O2-O9 de C6 réécrites avec les noms de 30 ») et ces deux colonnes étant figées dès l'INSERT (E10-41, `Game::FROZEN_COLUMNS`). Aucune conséquence sur le tirage : la graine est générée avant l'INSERT et le compte vient du rapport, calculé sur le même `PoolScope` que le tirage.

Pourquoi un seul `PoolScope` : la fenêtre de mémoire se calcule une fois ; deux appels à `RoomMemoryWindow::since()` à quelques millisecondes d'écart pourraient chevaucher une frontière de la fenêtre et faire diverger le compte de la garde et le tirage. Le constructeur de vivier s'appelle **dans** une transaction ouverte, **sans en ouvrir une, sans rien verrouiller et sans écrire** (contrat C6 § 6). **Aucun appel TMDB** sur ce chemin (règle 6).

### 6.2 Chargement de `DrawInput` et budget de requêtes

```php
final readonly class DrawInput
{
    public function __construct(public array $candidates,        // list<PoolCandidate>, triés par movieId
        public array $variantsByMovie,                              // array<int, list<VariantCandidate>>
        public ?CarbonImmutable $memorySince, public int $framesPerRound, public int $roundsCount, public int $margin) {}
}
final readonly class VariantCandidate { public function __construct(public int $frameId, public FrameLevel $frameLevel, public ?CarbonImmutable $lastSeenAt) {} }
final readonly class GameDrawer
{
    public function __construct(private PoolQuery $pool, private VariantChooser $variants) {}
    public function draw(PoolScope $scope, int $roundsCount, SeededPrf $prf): DrawResult;   // charge DrawInput ; @throws PoolTooSmallException
    public function drawFrom(DrawInput $input, SeededPrf $prf): DrawResult;                  // fonction pure ; @throws PoolTooSmallException
}
```

**Stratégie retenue : chargement complet, en deux requêtes** (le contrat C3 laissait le choix entre un chargement par lots dans l'ordre de la permutation et le vivier entier).

1. `PoolQuery::candidates($scope)` — une requête.
2. Les variantes jouables de tous les candidats, en une requête : `frame` filtré par le **prédicat unique de variante jouable** (`availability = 'published'` ET `processing_state = 'ready'` ET `game_path IS NOT NULL`, `10` § 3.2 ; porté par la portée Eloquent `Frame::servable()`, jumelle SQL de `Frame::isServable()` au même verdict ligne à ligne, colonnes qualifiées pour rester juste sous la jointure, valeurs par les énumérations — seule écriture du prédicat, que lisent aussi `MovieProjector::recompute()` et `VariantChooser::substitute()` ; amendé le 28/09 (E71-3, E72-1)), `frame.movie_id IN (candidats)`, servi par `frame_movie_level_idx`, trié par `(movie_id, frame_level, id)`, avec **jointure gauche** sur `seen_frame` pour `(room_id = scope.roomId, frame_id)` par `seen_frame_room_frame_uq` qui fournit `lastSeenAt`. Sans salon (solo, catalogue), pas de jointure et `lastSeenAt` nul partout.

Pourquoi le chargement complet : `drawFrom` doit rester une fonction pure de ses entrées (§ 6.5), ce qu'un chargement paresseux guidé par la permutation interdirait ; le coût est borné par le catalogue (au J1, 60 films et 180 variantes ; à la cible, quelques centaines de films et quelques milliers de variantes), une fois par lancement. `marge` est lue dans `PlatformLimits::drawSubstituteMargin()` par `draw()`, jamais par `drawFrom()`. **Budget de requêtes de cette spec sur un lancement non bloqué** : sans thème, `RoomMemoryWindow` 1, `report()` 1, `draw()` 2 — quatre requêtes indexées, dans la transaction. Avec thèmes (`themeIds ≠ []`), une lecture de `publishedThemeIds()` de plus, faite une fois par l'instance `scoped` de `PoolQuery` que partagent `report()` et `candidates()` (§ 3.1) : cinq requêtes.

**Gardes d'entrée** — amendé le 28/09 (E71-1), même régime que `PoolScope` (§ 3.2) et `PoolReporter` (§ 4.2) : `InvalidArgumentException`, jamais d'écrêtage ; aucun nom ni paramètre du contrat C3 ne change. Le constructeur de `DrawInput` exige des candidats triés par `movieId` **strictement** croissant (contrat « triés par movieId » de `PoolQuery::candidates()`, sans doublon), `N` dans `[MIN_FRAMES_PER_ROUND, MAX_FRAMES_PER_ROUND]`, `M` dans `[MIN_ROUNDS_COUNT, MAX_ROUNDS_COUNT]` — le tirage ne suit que l'étape L5 du lancement, qui normalise, à la différence du rapport, que le lobby appelle sur des réglages bruts (§ 4.2) — et la marge dans `[0, PlatformLimits::MAX_DRAW_SUBSTITUTE_MARGIN]`, lue comme constante, sans configuration, pour que `drawFrom` reste pur ; les trois bornes ensemble gardent `K ≤ PlatformLimits::MAX_ROUND_SEQUENCE_INDEX`. `GameDrawer::draw()` refuse un périmètre sans `N` (`PoolScope::catalogue([], null)`, complément des leurres seulement), comme `PoolReporter::report()`.

### 6.3 `drawFrom` pas à pas

```
works  = candidats groupés par (groupId ?? film seul), ordonnés par leur plus petit movieId ; membres triés par movieId
W      = |works| ; si W < M : PoolTooSmallException(W, M)
K      = min(M + margin, W)
perm   = prf.permutation(DrawContext::movies(), W)
pour chaque p de perm, tant que |retenus| < K :
    s      = |retenus| + 1
    œuvre  = works[p]
    film   = |œuvre| = 1 ? œuvre[0] : œuvre[prf.index(DrawContext::workMember(s), |œuvre|)]
    séqs   = FrameLevelCoverage::sequences(N, maskOf(niveaux des variantsByMovie[film]))
    si séqs = null : journaliser l'œuvre sautée ; continuer
    pour i de 1 à N :
        permis  = { séq[i−1] : séq ∈ séqs }
        frame   = VariantChooser::choose(variantes de film aux niveaux permis, memorySince, prf, DrawContext::variant(s, i))
        séqs    = { séq ∈ séqs : séq[i−1] = niveau(frame) }
        paliers += DrawnTier(i, frame, niveau(frame))
    retenus += DrawnRound(s, s ≤ M ? s : null, film, paliers)
si |retenus| < M : PoolTooSmallException(|retenus|, M)
renvoyer DrawResult(retenus, poolSize = W, roundsCount = M)
```

- **Les niveaux d'un palier** (D45 du 01/10 — amendé le 01/10) : le palier `i` choisit parmi les variantes de **tous** les niveaux qu'une séquence encore possible place en `i`, puis ne garde que les séquences qui passent par le niveau choisi. La préférence « non vue par le salon » porte donc sur toute la plage, et le niveau montré en découle ; les niveaux restent strictement croissants et chaque palier reste dans sa plage, sauf repli.
- **Une œuvre à plusieurs membres** tire son film par `workMember(s)` : un groupe entier ne compte qu'une fois et un seul de ses films entre au tirage — c'est l'exclusion mutuelle de `movie_group`, obtenue par construction plutôt que par un rejet.
- **Le masque est recalculé depuis les variantes chargées**, jamais lu dans `movie_projection` : si la projection est périmée (le vivier a compté le film, ses variantes réelles ne couvrent plus `N` niveaux), l'œuvre est **sautée** et le parcours continue. Le journal est émis sur le canal `game` (propriété de `100`) sous le libellé `draw.work_skipped`, avec `movie_id`, `N`, le masque projeté et le masque réel, **sans aucune donnée de joueur** (forme livrée — amendé le 28/09 (E71-2) : niveau `warning`, anomalie de données que `catalog:reproject` répare ; contexte exactement `movie_id`, `frames_per_round`, `projected_levels_mask`, `levels_mask`, ni titre ni donnée de joueur ; une œuvre à plusieurs membres dont le membre tiré a une projection périmée est sautée entière, sans essai d'un autre membre) ; c'est un effet de bord qui n'entre dans aucun calcul, donc qui ne rompt pas la pureté.
- **`sequenceIndex = 1..K`** dans l'ordre retenu ; **`roundNumber = sequenceIndex` si `≤ M`, `null` pour la réserve** (E10-45).
- Les instants (`starts_at_offset_ms`, `duration_ms`) et les `points` **ne sont pas** dans `DrawResult` : `60` et `80` les dérivent de `settings_snapshot` (contrat C6 § 5).

```php
final readonly class DrawResult { public function __construct(public array $rounds, public int $poolSize, public int $roundsCount) {} } // list<DrawnRound> ; jamais sérialisé
final readonly class DrawnRound { public function __construct(public int $sequenceIndex, public ?int $roundNumber, public int $movieId, public array $tiers) {} } // list<DrawnTier>
final readonly class DrawnTier  { public function __construct(public int $tierIndex, public int $frameId, public FrameLevel $frameLevel) {} }
```

### 6.4 Matérialisation (propriété de `60`)

`App\Actions\Game\MaterializeDraw` (contrat C6 étape O8) écrit, dans la transaction de lancement : **les `K` manches**, réserve comprise, avec `sequence_index`, `round_number` (NULL pour la réserve, qui reçoit au remplacement le numéro de la manche annulée), `movie_id`, `room_id = game.room_id` et `status = pending` ; `N` lignes `round_tier` par manche (`tier_index`, `frame_id`, `frame_level`). **Elle n'écrit aucune colonne de `game`** : `game.draw_seed` et `game.draw_pool_size` sont posés à l'INSERT par `OpenGame` (C6 O5 et § 5 : `SeededPrf::generateSeed()` / `PoolReport::$count`) et figés (E10-41, `Game::FROZEN_COLUMNS`) ; l'invariant `DrawResult::$poolSize === game.draw_pool_size` (§ 6.5) n'est vérifié par aucun code d'exécution : il est prouvé par `GameDrawTest` (L30-5 : `DrawResult::$poolSize` égal au `count` du rapport du même `PoolScope`) et par `LaunchGameTest` de `50` (« écrit draw_pool_size égal au nombre d'œuvres du vivier rejoué », L50-7a). `serve_token` reste NULL (frappé par `60`, contrat C8). **Aucun rejeu de production ne recalcule le tirage** : il relit `round` et `round_tier` (`10` § 7.4).

### 6.5 Invariants

- **Déterminisme** : `drawFrom` est une fonction pure de (graine, candidats ordonnés, variantes par film, `memorySince`, `N`, `M`, marge) — ni base, ni configuration, ni horloge. Deux appels identiques donnent un `DrawResult` identique. Le test de `10` § 7.2 (« un rejeu reproduit `round.movie_id` et `round_tier.frame_id` ») se formule donc **à entrées figées**, puisque la curation et la purge changent le vivier et la mémoire d'un jour à l'autre (point non couvert par la pré-analyse, désormais écrit).
- **Unicité** : au plus un film par `movie_group` parmi les `K`, aucun film en double, `K = min(M + marge, W)` avec `W ≥ M`. **À `W = M`, il n'y a pas de réserve** : un échec technique se résout en annulation sans remplacement (`00` § Réglages du salon, borne croisée 3).
- **Non-blocage des variantes** : chaque palier tiré a au moins une variante, par construction du masque (§ 7.3).
- **Cohérence avec la garde** : `DrawResult::$poolSize === PoolReporter::report($scope, M)->count` `=== game.draw_pool_size`, même `PoolScope`, même transaction. Cette égalité, sans aucun verrou sur le catalogue (§ 3.7), repose sur l'**instantané cohérent** de REPEATABLE READ, niveau d'isolation par défaut d'InnoDB, que le dépôt n'abaisse jamais (`config/database.php` ne le fixe pas ; le `transaction_isolation` du MySQL de production est **à confirmer au relevé du VPS**, S2 du 23/09) : la première lecture non verrouillante de la transaction, au plus tard `RoomMemoryWindow`, fixe l'instantané que liront `report()` puis `draw()`, et une publication ou une suspension validée entre les deux n'y apparaît pas. SQLite, en test, sérialise la transaction. Abaisser l'isolation (READ COMMITTED) rendrait `PoolTooSmallException` atteignable après un rapport non bloqué, et `draw_pool_size` pourrait différer du compte réel du tirage.
- **Secret** : `frame_id`, `frame_level` (qui trahit le repli), `movie_id` et `draw_pool_size` ne quittent jamais le serveur (`#[Hidden]`, `10` § 15).
- **Aucune pondération** (difficulté, notoriété, ancienneté) : le tirage est uniforme sur les œuvres. Rien dans le corpus ne demande de pondération, et elle exigerait des statistiques qu'aucune table ne porte.

### 6.6 Exemple au réglage par défaut

Salon neuf, catalogue du J1 en passe 1 (60 films, aucun groupe), `N` = 3, `M` = 10, marge = 3. Garde : `W` = 60 ≥ 10. `K` = min(13, 60) = 13 : dix manches numérotées, trois de réserve. Après la partie, seules les dix manches démarrées sont jouées : le lobby suivant affiche `W` = 50. À la sixième partie, `W` = 10 = `M` : lancement accepté, `K` = 10, aucune réserve. À la septième, `W` = 0 : bloqué, cause `noRepeatMovies`, remède `open_new_room` (§ 4.5).

---

## 7. Choix des variantes [J1]

### 7.1 Règle de préférence

```php
final readonly class VariantChooser
{
    public function choose(array $candidates, ?CarbonImmutable $memorySince, SeededPrf $prf, DrawContext $context): ?int; // list<VariantCandidate> d'un palier, niveaux de sa plage mêlés
    public function substitute(Round $round, RoundTier $tier, array $excludedFrameIds, CarbonImmutable $now): ?int;  // § 8
}
```

Ordre de repli complet (`00` § Le jeu en une manche, `CLAUDE.md` § 2) : **non vue par le salon → vue la moins récemment par le salon → départage par la graine.**

1. Une variante est **non vue** si `lastSeenAt` est nul **ou antérieur à `memorySince`** (E10-55). Pourquoi la seconde moitié : une ligne `seen_frame` encore présente parce que la purge du jour n'a pas tourné changerait sinon le tirage ; **le tirage ne dépend pas de l'heure de la purge**. « Antérieur » est **strict** : une ligne à `memorySince` pile est dans la fenêtre, donc vue — même borne incluse que la non-répétition (`started_at >= memorySince`, § 3.3, clause 4) — amendé le 28/09 (E71-2).
2. S'il existe au moins une variante non vue, le **groupe d'égalité** est l'ensemble des non vues ; sinon, l'ensemble des variantes de `lastSeenAt` minimal.
3. Le groupe est trié par `frameId`, puis on renvoie `groupe[prf.index(context, |groupe|)]`.

**Niveaux mêlés** — amendé le 01/10 (D45 du 01/10), remplace la garde d'E71-1 : `choose()` accepte des candidates de plusieurs niveaux, celles d'une plage ; c'est l'appelant (§ 6.3, § 8.1) qui borne les niveaux permis.

**Aucun axe joueur** : tous les joueurs voient la même image, `seen_frame` ne porte aucune colonne de joueur, et stocker « vu par le joueur » coûterait jusqu'à 360 écritures par partie pour un simple départage (`10` § 7.9). Les deux commentaires qui parlent encore d'un « repli non vue par le joueur » (encadré) sont corrigés dans le lot L30-5 (n° 20).

### 7.2 Lecture de la mémoire ; écriture et purge hors de cette spec

- **Lecture** : une fois, au lancement, pour les `K × N` paliers (§ 6.2), et à chaque substitution (§ 8.1). Les `seen_frame` écrits **pendant** la partie ne modifient jamais un palier déjà tiré : la mémoire lue au lancement est figée dans `round_tier.frame_id`.
- **Écriture** : `60` upserte `seen_frame` à l'ouverture effective du palier, sur `served_frame_id`, **en multijoueur seulement**, par la seule transition d'ouverture et jamais par la route de service (E10-47, `10` § 7.4). Une manche annulée, une fin anticipée ou une substitution ne marquent donc jamais une image non affichée.
- **Purge** : `10` § 11.1 et `100`, sur la fenêtre unique de `RoomMemoryWindow` (E10-56). Une purge en retard ne change aucun tirage (point 1 du § 7.1).
- **Cascade d'un retrait** : `20` supprime explicitement les `seen_frame` des frames concernées (`10` § 4.3). Sans effet sur le tirage, qui n'aurait de toute façon plus tiré ces frames.

### 7.3 Non-blocage

Le tirage de variante **n'est jamais bloqué** : une variante déjà vue vaut mieux qu'une manche annulée (`00` § Le jeu en une manche). `choose()` ne renvoie `null` que sur une liste vide, ce que le tirage des films exclut par construction du masque (§ 6.3) ; au lancement, le résultat n'est donc jamais nul. Mémoire vide ou purgée : toutes les variantes sont non vues, le départage se fait par la graine seule.

---

## 8. Substitution et remplacement [J1]

La **règle de choix** appartient à cette spec ; la **détection**, l'**instant**, l'**écriture** de `served_frame_id` et `substitution_reason` et l'**annulation** appartiennent à `60` (carte de propriété n° 15, E10-25).

### 8.1 Variante de substitution

`VariantChooser::substitute($round, $tier, $excludedFrameIds, $now)` :

- **candidats** — amendé le 01/10 (D45 du 01/10) : variantes du film `round.movie_id`, **aux niveaux de la plage `bands(game.frames_per_round)[tier.tier_index − 1]`, plus `tier.frame_level`** (un palier tiré en repli garde son niveau), **strictement entre** le niveau du palier précédent et celui du palier suivant (le niveau de l'image **servie** quand le voisin a été substitué), qui satisfont le prédicat unique de variante jouable, **dont le fichier est présent sur le disque `frames`** (exigence de `60`, contrat C8), moins `tier.frame_id` et moins `$excludedFrameIds` ;
- **mémoire** : `seen_frame` du salon `game.room_id` avec `memorySince = RoomMemoryWindow::since(room, $now)` ; **aucune mémoire en solo** ;
- **choix** : la règle du § 7.1, contexte `DrawContext::substitute(round.sequence_index, tier.tier_index)` ;
- **résultat** : un `frame.id` d'un niveau permis, ou `null` — et `null` signifie que `60` annule la manche (`no_variant_available`).

Pourquoi **jamais hors de la plage ni hors de l'ordre** : le palier matérialisé porte une durée, une valeur en points et une place dans l'échelle de cryptivité ; servir un niveau 5 au palier 1 donnerait la réponse au palier le mieux payé. `round_tier.frame_level` reste le niveau **tiré** ; le niveau servi se lit sur `served_frame_id`. Une substitution ne touche **aucune** colonne de temps ni de points (`10` § 7.4).

La présence sur disque est testée **par `substitute()` lui-même, avant le choix**, sur les seuls candidats des niveaux permis (quelques appels `exists()` sur un chemin rare) : le résultat reste une fonction des entrées, et une variante au fichier manquant n'est jamais proposée.

**Forme livrée** — amendé le 28/09 (E72-2, E90-2) :

- **« Fichier présent » = `Frame::hasGameFile()`** : `game_path` possédé par `FrameStoragePrefix::Game` (`owns()`, la garde même de `/f/`, partie 1 du prédicat de service de C8) **et** `exists()` sur le disque `frames`, une `FilesystemException` valant absence. Sans la garde de préfixe, une candidate au chemin hors de `game/` serait choisie puis refusée en 404 toute la manche. C'est le **même prédicat** que lit `60` à la frappe pour retenir la variante tirée (`Frame::isServable()` et `hasGameFile()`, livré par L60-5), et qui revérifie la candidate rendue par `substitute()` avant de l'écrire : une seule écriture de la présence.
- **Ordre** : une requête des voisins du palier, puis une requête de candidates (portée `Frame::servable()`, film, niveaux permis, exclusions, tri par `id`, jointure gauche `seen_frame` du salon quand il y en a un) → filtre de présence → liste vide : `null` **sans lire la mémoire** → `RoomMemoryWindow::since(room_id, $now)` → `choose()` dans `DrawContext::substitute(s, i)`, graine `SeededPrf::forGame($round->game)`.
- **Salon** lu sur `game.room_id` par `round->game`, déjà nécessaire pour la graine ; solo ⇔ `room_id` nul (même règle que `PoolScope::forGame`) : ni `seen_frame` ni `round` lus.
- **`tier.frame_id` nul** (frame supprimée, `nullOnDelete`) : aucune exclusion pour elle, le niveau dénormalisé `tier.frame_level` reste permis.
- **Garde** (ajout pur) : un palier d'une autre manche (`tier.round_id ≠ round.id`) lève `InvalidArgumentException`. `60` l'appelle **au moment de la frappe du `serve_token` du palier** — à l'ouverture du palier `i − 1`, ou à la programmation de la manche pour `i = 1` (contrat C8) ; si le fichier du candidat retourné disparaît entre le choix et la frappe, `60` rappelle la méthode en l'ajoutant à `$excludedFrameIds`, boucle bornée par le nombre de candidats. **Idempotence** : `served_frame_id` et `substitution_reason` sont écrits une seule fois par `60` ; un job de frappe rejoué ne recalcule rien.

### 8.2 Film de remplacement

```php
final readonly class ReplacementRoundChooser
{
    public function next(Game $game): ?Round;
}
```

`next($game)` = la première manche de la partie, par `sequence_index` croissant, qui a `round_number IS NULL`, `status = pending`, `started_at IS NULL`, **et dont le film satisfait encore `Movie::inPool()`**. Pourquoi cette dernière clause : un film de réserve peut avoir été suspendu, retiré ou basculé `blocked` entre le lancement et son usage (point non couvert par la pré-analyse) ; le prendre recréerait l'incident qu'on remplace. Un palier devenu non servable dans une manche de réserve passe par `substitute()` à la frappe, comme toute manche. **Réserve épuisée : `null`, et la partie continue avec une manche de moins** (`00` § Réglages du salon, borne croisée 3). `60` pose le `round_number` de la manche annulée sur la remplaçante et la programme.

### 8.3 Partage avec `60`

| Sujet | Cette spec | `60` |
|---|---|---|
| Quelle variante remplace | règle, contexte, mémoire, présence disque | appel à la frappe, écriture unique, motif |
| Quel film remplace | `ReplacementRoundChooser::next()` | annulation (`RoundIncidentReason`), numérotation, programmation |
| Retrait actif en cours de partie | — | job d'annulation active, jalon 2 (R-33) |

---

## 9. Mode solo [J1]

Le solo tire exactement comme le multijoueur, à trois différences près, toutes conséquences de décisions prises : un solo n'a pas de salon (`game.room_id` NULL, `10` § 7.10) et `seen_frame.room_id` est NOT NULL.

- **Périmètre** : `PoolScope::catalogue($settings->themeIds, $settings->framesPerRound)`, sans clause de salon ; `noRepeatMovies` est ignoré (le snapshot garde sa valeur, contrat C0 § 3.1).
- **Mémoire** : `memorySince` nul, tous les `lastSeenAt` nuls ; le choix de variante se fait **par la graine seule** ; `substitute()` et `next()` sans mémoire. Rien n'est écrit dans `seen_frame` (`60`).
- **`N`** : si le `N` du preset choisi n'est pas jouable, `nearestPlayableFramesPerRound(PoolScope::catalogue($settings->themeIds, N), M)` fournit le `N` jouable le plus proche, que `60` applique d'office et annonce (D19 du 23/09). Si aucun `N` n'est jouable, le rapport est bloqué et `60` refuse le démarrage.

Conséquence assumée, nommée par `60` : sans mémoire, un joueur qui s'entraîne revoit les mêmes images et peut se constituer un **dictionnaire visuel** ; le `serve_token` frappé par manche empêche de réutiliser une **adresse** (`10` § 7.10, barrière 4 reformulée par D16 du 23/09, E10-58), pas d'apprendre des **octets** (§ 11).

---

## 10. Interface des leurres (consommée par `70`, contrat C11) [J1]

La règle des leurres — profil de titre, échelle des rangs, mode dégradé, composition, cas terminal — **appartient à `70`** (D21 du 23/09 ; contrat C11). Cette spec ne fournit que le vivier, la PRF et les contextes (amendement A-49 : le « corpus de leurres disponible » de `05` § Ce que cette spec ne décide pas devient cette interface).

### 10.1 Ce que cette spec fournit

À la **première composition** du QCM — `T₁` en Facile, `T_N` en Normal, jamais au lancement, jamais en Expert (E10-15) —, `70` appelle, avec `$at` = l'instant **théorique** de composition (`round.started_at + starts_at_offset_ms` du palier `InputDifficulty::choicesOpenTierIndex(N)`), jamais l'heure d'exécution du job :

| Rang de `70` | Requête | Contexte de permutation |
|---|---|---|
| R1, R3 | `PoolQuery::movies(PoolScope::forDecoys($round, $at))` — vivier du salon : thèmes du snapshot, `levels_count ≥ N`, non-répétition si active | R1 : `DrawContext::decoys(s)` ; R3 : `decoysOriginal(s)` |
| R2, R4 | `PoolQuery::movies(PoolScope::forDecoys($round, $at)->withThemeIds([])->withFramesPerRound(null))` — catalogue publié, **non-répétition conservée** | R2 : `decoys(s)` ; R4 : `decoysOriginal(s)` |
| R5 [D53 du 02/10] | `PoolQuery::movies(PoolScope::forDecoys($round, $at)->withThemeIds([])->withFramesPerRound(null)->withoutNoRepeat())` — catalogue publié **sans non-répétition**, exclusions conservées | profil normal : `decoysLastResort(s)` ; dégradé : `decoysLastResortOriginal(s)` |
| R6 [D53 du 02/10] | `PoolQuery::movies(PoolScope::forDecoys($round, $at)->asDecoyReserve())` — films `draft` ou `unpublished`, `clear`, exclusions conservées | idem |

**Groupes d'affinité** — amendé le 01/10 (D44 du 01/10) : avant R1 et avant R3, `70` § 10.3 bis cherche les trois leurres parmi les films apparentés à la cible (même `collection_id`, puis thème studio, saga ou manuel, puis genre), dans la requête de R2 — catalogue publié, non-répétition et exclusions conservées —, contextes `decoysAffinity(s)` et `decoysAffinityOriginal(s)` ; un groupe n'est retenu que s'il fournit seul les trois leurres. Cette spec ne fournit toujours que le vivier, la PRF et les contextes.

Chaque requête est triée par `movie.id` ; `70` y ajoute ses clauses `movie_projection.title_mask_version` / `title_locale_mask` et parcourt chaque rang dans l'ordre de `SeededPrf::forGame($game)->permutation($context, n)`. L'ordre du QCM par siège est `permutation(DrawContext::qcmOrder(s, player.public_id), 4)` (E10-54). ~~**`withoutNoRepeat()` ne sert qu'au diagnostic du rapport, jamais aux leurres**~~ — amendé le 02/10 (D53 du 02/10) : le porteur a ajouté l'extension de D21 du 23/09 que cette phrase lui réservait. `withoutNoRepeat()` sert le diagnostic du rapport (§ 4.3) et le seul rang R5 de dernier recours des leurres, après tous les rangs qui conservent la non-répétition, là seulement où il n'y aurait eu aucun QCM (`70` § 10.3).

### 10.2 Exclusions portées par `forDecoys`

`forDecoys($round, $at)` = `forGame($round->game, $at)->excluding(...)` avec, **toujours** :

- le film cible `round.movie_id` ;
- son `movie_group` (deux « Old Boy » dans un même QCM se départageraient par l'année seule) ;
- les films des manches **démarrées** de la partie (`started_at <= $at`, § 1.3). Ils sont déjà exclus par la non-répétition quand elle est active ; l'exclusion explicite les écarte aussi en solo et, au J2, quand l'hôte a coupé la non-répétition.

**Jamais les films des manches futures, programmées comprises** : les exclure retirerait du QCM exactement les films que le tirage a retenus, et un joueur attentif lirait le tirage dans ce qui manque aux leurres (D21 du 23/09). Pourquoi `$at` théorique : un job de composition rattrapé en retard doit composer les **mêmes** leurres, sinon deux compositions possibles auraient pour seul élément commun garanti la bonne réponse.

### 10.3 Le cas terminal est plus probable au J1

Le cas terminal — moins de trois leurres après R4, puis, depuis D53 du 02/10, après le dernier recours R5-R6 — est réglé par `70` (contrat C11 § 4) — amendé le 02/10. En **Facile**, `60` annule la manche (`choices_unavailable`, E10-07) par le mécanisme d'échec technique. En **Normal**, le QCM n'apparaît pas : les sièges `text_exhausted` passent `attempts_exhausted` à l'instant théorique de composition, et toute exhaustion ultérieure dans la manche mène à `attempts_exhausted` — l'état « texte épuisé, QCM attendu » de D20 du 23/09 n'a plus d'objet dans cette manche. **Il est nettement plus probable au J1**, pour deux raisons mécaniques qu'il faut connaître avant la première vraie partie :

1. **Fin de mémoire d'un salon.** Quand le vivier du salon vaut `W` < `M` + 3 — trois étant le nombre de leurres, cardinalité fixée par le schéma (`10` § 7.4) et non la marge —, ce qui arrive à la dernière partie avant blocage (§ 6.6), les candidats aux leurres de la manche `k`, tous rangs confondus, se réduisent aux films non démarrés hors cible, soit `W − k` (la réserve jamais démarrée comprise). À `W = M`, il en reste moins de trois **dès la manche `M − 2`** (la 8ᵉ au réglage par défaut) : le cas terminal y est **certain**, quel que soit le profil de titre. En Facile, les trois dernières manches sont annulées sans remplaçant (réserve vide) ; en Normal, elles se jouent en texte libre seul, sans état « QCM attendu ». Avant même le cas terminal, chaque leurre de cette partie est la réponse d'une manche future (§ 11). Au J2, le même cas ne se produit qu'aux salons qui épuisent leur mémoire, bien plus rarement sur quelques centaines de films.
2. **Petits effectifs par profil.** Sur 60 films, un film cible dont le profil de titre est rare (titre original non latin, profil de locales incomplet) trouve moins facilement trois pairs, même en mode dégradé où seule la forme du titre original doit coïncider.

~~Aucun remède côté cette spec : D21 du 23/09 interdit de lever la non-répétition~~ — amendé le 02/10 (D53 du 02/10) : le dernier recours de `70` § 10.3 lève la non-répétition (R5), puis puise dans les films non publiés `clear` (R6), toujours hors cible, `movie_group` et manches démarrées. Le cas 1 n'est donc plus certain : en fin de mémoire, les films déjà joués par le salon dans ses parties précédentes redeviennent des leurres (faibles), et la file de curation en ajoute. Le cas 2 se raréfie de même. Le remède « nouveau salon » (§ 4.5) reste celui du vivier.

---

## 11. Fuites résiduelles assumées

Écrites ici parce qu'elles sont choisies, pas oubliées.

| Fuite | Pourquoi elle reste | Borne |
|---|---|---|
| **Repli de niveau déductible** de la lisibilité de l'image : `frame_level` est `#[Hidden]`, mais un palier 1 anormalement lisible trahit une banque maigre. | Refuser le repli rendrait le film injouable à ce `N` ; masquer l'image est impossible. | Signal « incomplet » de `20` et affichage du repli par `N` (`usesFallback()`, § 2.5), pour que le curateur complète la banque. |
| **Élimination quand `W ≤ M + marge`** : tout le vivier est tiré, le compte est public (`00` § Déroulé d'une partie). En fin de mémoire d'un salon, un joueur qui retient les films déjà joués connaît les films restants. | Relever la borne de lancement au-delà de `M` rouvrirait un arbitrage verrouillé (`questions-ouvertes.md` § Arbitrages non rouverts). | Le jeu se joue entre amis (principe 3) ; « nouveau salon » remet le vivier plein. |
| **Leurres pris dans le tirage quand `W ≤ M + marge`** : `K = W`, donc tout le vivier du salon est tiré, et tout leurre du rang R1 (vivier du salon, § 10.1) est le film d'une manche future ou de la réserve ; R2 n'y ajoute des films hors tirage que si les thèmes ou `N` restreignent le vivier du salon par rapport au catalogue publié sous non-répétition. À `W = M` — dernière partie d'un salon avant blocage au J1 (§ 6.6), sans thème et catalogue entier jouable à `N` = 3 —, les trois leurres de **chaque** QCM sont, **dès la manche 1**, des bonnes réponses de manches futures, poussées au client avant leur révélation : un joueur qui note les propositions connaît les réponses à venir, sans savoir à quelle manche chacune appartient. | Exclure les manches futures des leurres trahirait le tirage et est interdit par D21 du 23/09 ; lever la non-répétition pour trouver d'autres leurres l'était aussi (§ 10.1) ; depuis D53 du 02/10, elle n'est levée qu'au dernier recours, quand R1-R4 échouent, jamais pour remplacer des leurres que R1 fournit — amendé le 02/10. **Tension entre la règle 3 et D21 du 23/09, signalée au porteur** : D21 du 23/09 prime (décisions du 23/09 en tête de la préséance). | La seule parade compatible avec D21 du 23/09 est le remède « nouveau salon » (§ 4.5), qui remet la mémoire à zéro ; le cas ne concerne que les parties à `W ≤ M + marge`. |
| **Groupe commun des propositions visible** — amendé le 01/10 (D44 du 01/10) : quand un groupe d'affinité fournit les trois leurres (`70` § 10.3 bis), les quatre propositions sont toutes des Toy Story, toutes des Marvel ou toutes de science-fiction ; le joueur apprend la saga, le studio ou le genre de la cible avant la révélation. | Demande explicite du porteur : des leurres apparentés rendent le QCM jouable, là où des titres sans rapport l'éliminent d'un coup d'œil. La règle « un seul groupe fournit les trois leurres » garantit que le groupe commun **n'identifie pas la cible** parmi les quatre : mélanger saga et studio la placerait dans la paire de la saga, une chance sur deux. La **cohérence de groupe** (`70` § 10.3 bis) empêche l'élimination : aucun leurre n'est d'un groupe plus prioritaire que celui retenu (jamais un Toy Story parmi des Pixar). **Résidus** : les effectifs de cohérence sont comptés sur l'éligibilité et non sur la validité du parcours, et l'échelle R1-R4 de repli, inchangée, peut tirer un leurre qui aurait eu son propre groupe — rare dès que les genres sont peuplés, le repli ne servant qu'à une cible sans groupe suffisant. | Aucun drapeau ni aucune clé de thème dans la charge (§ 13.3) ; l'appartenance se lit dans les titres, comme pour un joueur qui connaît la saga, jamais dans les données. |
| **Leurres de dernier recours éliminables** — amendé le 02/10 (D53 du 02/10) : un leurre de R5 (film déjà joué par le salon sous non-répétition) ou de R6 (film non publié) ne peut pas être la cible ; un joueur qui retient l'historique du salon ou connaît le catalogue publié peut l'écarter. | Sans eux, la manche n'aurait aucun QCM (annulée en Facile, texte seul en Normal) : un QCM à leurres faibles vaut mieux. | Rangs placés **après** tous les autres (`70` § 10.3) : ils ne servent que là où R1-R4 échouent ; le catalogue publié n'est pas affiché aux joueurs. |
| **Dictionnaire visuel appris en solo** : hachage des octets servis. | D16 du 23/09 : risque assumé ; aucun identifiant d'**adressage** n'est réutilisable (E10-58). | Nommée et bornée par `60`. |
| **Taille du vivier** connue de tous au lobby. | Le compteur est une décision produit (`00` § Déroulé d'une partie). | `draw_pool_size` reste `#[Hidden]` par hygiène (§ 4.6). |

---

## 12. Taxonomie des thèmes [J1 depuis D43 du 01/10 ; difficulté au J2]

### 12.1 Six natures, une règle simple

`ThemeKind` a six cas, **sans cas nouveau** : `rule_value` (`string(64)` nullable, `10` § 3.7) s'interprète selon la nature.

| `theme_kind` | `rule_value` | Entrée du film | Correspondance |
|---|---|---|---|
| `genre` | `tmdb_tag_id` de nature `genre` | lignes `movie_tmdb_tag` | `EXISTS movie_tmdb_tag (movie_id, tag_kind = 'genre', tmdb_tag_id = rule_value)` |
| `studio` | **liste** de `tmdb_tag_id` de nature `company`, joints par des virgules (D43 du 01/10) | lignes `movie_tmdb_tag` | `EXISTS movie_tmdb_tag (movie_id, tag_kind = 'company', tmdb_tag_id IN (liste))` — union des sociétés |
| `decade` | année de début | `movie.release_year` | `release_year ∈ [rule_value, rule_value + 10)` — dix ans, par définition de la décennie |
| `saga` | `collection.id` **local** | `movie.collection_id` | égalité |
| `language` | code de langue | `movie.original_language` | égalité exacte des chaînes |
| `difficulty` | valeur de `MovieDifficulty` | `movie.movie_difficulty` (effective) | égalité |

**Sémantique de la négation et du nul, normative** (elle diverge volontairement de l'évaluateur provisoire du seeder de démonstration) :

- `rule_value` NULL : le thème **n'a aucune règle automatique** ; il ne contient que ses ajouts manuels, **même nié**. Pourquoi : dans l'évaluateur provisoire, un thème nié sans règle contient tout le catalogue — un thème vide par intention deviendrait un thème universel par accident.
- **Entrée nulle** (`release_year`, `collection_id` ou `movie_difficulty` nul) : le film **ne satisfait jamais la règle, niée ou non**. Pourquoi : une métadonnée manquante est une lacune d'import, pas une propriété ; « pas dans les années 1990 » ne doit pas avaler les films dont on ignore l'année. Les natures `genre` et `studio` n'ont pas d'entrée nulle (un film sans étiquette a un ensemble vide, et la négation s'applique), ni `language` (`original_language` est NOT NULL).
- `rule_negated` inverse le résultat dans les autres cas. C'est ce qui rend gratuit « cinéma international » (`original_language <> 'en'`) sans liste ni expression à analyser (`10` § 3.7).

**Règle studio multi-valeurs** — amendé le 01/10 (D43 du 01/10). Pour `theme_kind = studio` **seulement**, `rule_value` est une liste d'identifiants de société TMDB, entiers positifs en décimal, joints par des virgules sans espace, sans doublon, triés croissants à l'écriture (`"429,128064,184898"`) ; un seul identifiant reste une liste d'un élément (`"420"`), si bien que les thèmes studio déjà livrés ne changent pas. Le film appartient au thème s'il porte **l'une quelconque** de ces sociétés (union) ; la négation s'applique à l'union. Bornes, gardées par la requête de `20` § 9.6 et par le seeder : **au plus 8 identifiants** (`ThemeRules::STUDIO_MAX_COMPANIES`) **et au plus 64 caractères** au total, largeur de la colonne (`10` § 3.7), aucune migration — les identifiants TMDB atteignent déjà sept chiffres, si bien que la longueur se valide pour elle-même, jamais déduite du seul nombre. Pourquoi : aucune société TMDB seule ne couvre DC (429 pour les films anciens, 128064 de 2017 à 2022, 184898 depuis 2025) ni Disney (2 ; 6125 pour Frozen et Moana ; 171656 pour Le Roi lion), relevé du porteur du 01/10 ; une seule société par thème laissait le choix entre un thème faux et trois thèmes « DC ». Les autres natures gardent une valeur unique.

**Évaluation locale, sans aucun appel réseau** (règle 6) : le genre et le studio se lisent sur `movie_tmdb_tag`, servis par `movie_tmdb_tag_rule_idx`.

### 12.2 Thèmes que la règle simple ne couvre pas (question Q30-2)

Le schéma ne porte qu'un discriminant par thème, par choix (`10` § 3.7) ; le complément décidé est l'**exception manuelle** qui survit au réimport (`questions-ouvertes.md` § Thèmes). Aucune table de règles composées, aucun cas d'enum nouveau :

- **Animés japonais** = thème de **langue** `ja`, complété par des **retraits manuels** des films japonais en prise de vue réelle, peu nombreux ;
- **Marvel** = thème **studio** sur la société TMDB Marvel Studios (420), jamais un thème de saga : ses films se répartissent sur plusieurs `collection` TMDB ;
- **DC** = thème **studio** sur trois sociétés, `429,128064,184898` (règle multi-valeurs, § 12.1) ; Batman (1989), qu'aucune société DC ne crédite, passe par un **ajout manuel** — amendé le 01/10 (D43 du 01/10) ;
- **Disney** = thème studio sur la société « Walt Disney Pictures », complété par des **ajouts manuels**, les classiques antérieurs à 1970 étant crédités à d'autres sociétés TMDB ; amendé le 01/10 (D43 du 01/10) : la règle cible est `2,6125,171656` (Walt Disney Pictures, Walt Disney Animation Studios, Walt Disney Feature Animation), posée **par le porteur en back-office** sur le thème déjà livré (§ 12.3), jamais par le seeder ni par une migration ;
- **Pixar et Ghibli sont des thèmes studio, jamais des sagas** (S4 du 23/09, contradiction n° 27).

Un thème purement manuel se déclare par `rule_value` NULL, dans **n'importe quelle** nature : sa nature ne sert alors qu'au préfixe de sa clé et à son bloc de `sort_order` (§ 12.5). Il se crée en back-office (`20` § 9.6, D43 du 01/10) ; le coût de cette voie est un geste de curation par film concerné, ou une sélection de thèmes au collage (`20` § 3.3), jamais une commande artisan (D10 du 23/09) — amendé le 01/10 (D43 du 01/10).

### 12.3 Thèmes livrés

Livrés par `PlatformDataSeeder` (§ 12.5), libellé obligatoire dans chaque locale activée (`05`, `Theme::hasEveryLocaleLabel()`). **Règle unique de publication à l'insertion** : les thèmes déjà livrés au J1 (genres, trois studios, décennies 1930 et 1970-2020, « cinéma international », difficulté) restent publiés ; **tout thème ajouté par le lot L30-9 ou créé en back-office naît non publié** — décennies 1940-1960, `studio.marvel`, `studio.dc`, `language.anime` et les sagas du § 12.4 (amendé le 01/10, D43 du 01/10). Pourquoi : à son insertion, un thème ajouté peut ne compter aucun film curé (la voie d'exception fait entrer un à un les films antérieurs à 1970, et aucun film Marvel n'est garanti au catalogue) ; publié vide, il s'afficherait au sélecteur pour bloquer tout salon qui le choisirait seul (cause `themeKeys`, § 4.3). Sa publication est un geste de `20`, en back-office, **sans déploiement** (S4 du 23/09), une fois qu'il couvre assez d'œuvres publiées pour être lancé seul au réglage par défaut. **Mesure et seuil, déclarés ici** parce que seule cette spec peut les calculer sans ouvrir un second prédicat de vivier (§ 3.1) :

- **Mesure** : `PoolReporter::themeWorks(int $themeId): int` = `countWorks(PoolScope::themeProbe($themeId))`, soit le vivier catalogue restreint à **ce seul thème, publié ou non**, au `RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND`, en œuvres. `themeProbe` est la seule entrée du constructeur qui ne s'intersecte pas avec les thèmes publiés (§ 3.2, § 3.3 clause 3), réservée à la supervision. Elle lit les lignes `movie_theme` actives que l'évaluateur écrit pour tout thème, publié ou non (§ 13.1).
- **Seuil** : `RoomSettingsBounds::DEFAULT_ROUNDS_COUNT` — « lancé seul au réglage par défaut » veut dire `W ≥ M` au `M` et au `N` par défaut. Aucune valeur propre : le seuil suit le défaut de `M` s'il change.
- **Partage** : `20` affiche la mesure sur l'écran des thèmes et l'applique au geste de publication ; la forme de cette application (refus ou avertissement, texte) appartient à `20` § 9.6, qui retient un **avertissement**, jamais un refus (D65 du 07/10 — amendé le 07/10 ; D43 du 01/10 retenait un refus) : le seuil devient indicatif, et un thème sous le seuil se publie. Au J1, avec environ 60 films publiés, presque aucun thème n'atteint le seuil : la publication des thèmes livrés ou créés est attendue tard au J1, et ce n'est pas un défaut. Les thèmes du J1 restent publiés parce qu'ils le sont déjà en production (le seeder ne réécrit jamais une ligne présente, § 12.5) et que leurs règles couvrent les films de la voie `discover`, qui forment l'essentiel du catalogue ; un thème du J1 trop maigre se dépublie par le même geste de `20`.

| Famille | Clés | `rule_value` | Jalon |
|---|---|---|---|
| Genres | `genre.animation`, `.action`, `.adventure`, `.comedy`, `.drama`, `.fantasy`, `.horror`, `.science-fiction`, `.thriller`, `.crime`, `.family` | 16, 28, 12, 35, 18, 14, 27, 878, 53, 80, 10751 | existants |
| Studios | `studio.disney`, `studio.pixar`, `studio.ghibli`, **`studio.marvel`**, **`studio.dc`** | 2, 3, 10342, **420**, **`429,128064,184898`** | Marvel et DC au J1 depuis D43 du 01/10, nés non publiés ; Disney corrigé en back-office vers `2,6125,171656` |
| Décennies | `decade.1930` … `decade.2020`, **sans trou** | année de début | 1940, 1950, 1960 au J1 depuis D43 du 01/10, nées non publiées |
| Langue | `language.international` (nié), **`language.anime`** | `en`, **`ja`** | animés au J1 depuis D43 du 01/10, né non publié |
| Difficulté | `difficulty.very_easy` … `difficulty.very_hard` | valeur de `MovieDifficulty` | existants |

- **Décennies 1930 à 2020 sans trou** (contradiction n° 27) : la voie d'exception fait entrer l'âge d'or Disney 1937-1967 et les classiques antérieurs à 1970 (`00` § Fonctionnalités v1) ; un trou de 1940 à 1960 laisserait ces films sans décennie. Le docblock du seeder (« `1930` couvre l'âge d'or ») est corrigé.
- **`language.anime` a une raison de plus de naître non publié** : tant que le curateur n'a pas retiré les films japonais en prise de vue réelle, la règle `ja` seule y range Les Sept Samouraïs ; un thème « Animés japonais » publié ainsi serait faux à l'écran. Sa publication est un geste de `20`, après les retraits.
- Libellés : décennies « Années 1940 » / « 1940s » (motif existant) ; animés « Animés japonais » / « Japanese animation » ; Marvel « Marvel Studios » dans les deux locales, comme les autres noms propres de studio ; DC « DC » dans les deux locales — amendé le 01/10 (D43 du 01/10).
- Les identifiants de société TMDB sont des **identifiants publics**, jamais un extrait de base de production. ~~Marvel Studios = 420 est à vérifier~~ — amendé le 01/10 (D43 du 01/10) : **vérifiés par le porteur le 01/10**, avec sa clé et hors CI : Marvel Studios = 420 ; DC = 429 (« DC »), 128064 (« DC Films ») et 184898 (« DC Studios ») ; Walt Disney Animation Studios = 6125 ; Walt Disney Feature Animation = 171656. Justice League (2017) n'a aucune collection TMDB et Batman (1989) aucune société DC : ces films passent par des ajouts manuels.
- **Correction de `studio.disney`** (D43 du 01/10) : livré et publié sur 2 seul, il ne capte ni Frozen ni Moana (6125). Le seeder ne réécrit jamais une ligne présente (§ 12.5) et **aucune migration de données ne réécrit sa règle** : le porteur la corrige en back-office vers `2,6125,171656`, geste journalisé `theme.updated` (`20` § 9.6), qui relance son évaluation après commit.
- Le seeder **nomme dans `tmdb_company`** (`10` § 3.6 bis), en insertion si absente, les sociétés qu'il désigne et celles de la correction Disney — 2, 3, 10342, 420, 429, 128064, 184898, 6125, 171656 —, noms littéraux relevés le 01/10, sans appel TMDB ; un nom déjà présent n'est pas réécrit. Le back-office les nomme ainsi avant tout rattrapage `catalog:company-names` — amendé le 01/10 (D43 du 01/10).

### 12.4 Sagas par défaut (S4 du 23/09)

Une dizaine de **vraies collections TMDB**, jamais « une collection = un thème » (`questions-ouvertes.md` § Thèmes de saga). Choix du rédacteur : sagas de films **et** de dessins animés, reconnaissables en français comme en anglais, chacune une collection TMDB unique. Amendé le 01/10 (D43 du 01/10) : **douze**, liste par défaut, Avatar et Iron Man ajoutés à la demande du porteur ; toute autre saga (Avengers 86311, Spider-Man MCU 531241, The Dark Knight 263, Matrix 2344…) se crée en back-office depuis la fiche d'un film de sa collection, une fois celle-ci importée (`20` § 9.6).

| Clé | Libellé FR | Libellé EN | Collection TMDB (`collection.tmdb_id`) | `collection.name` (littéral du seeder) |
|---|---|---|---|---|
| `saga.star-wars` | Star Wars | Star Wars | 10 | Star Wars Collection |
| `saga.harry-potter` | Harry Potter | Harry Potter | 1241 | Harry Potter Collection |
| `saga.lord-of-the-rings` | Le Seigneur des Anneaux | The Lord of the Rings | 119 | The Lord of the Rings Collection |
| `saga.james-bond` | James Bond | James Bond | 645 | James Bond Collection |
| `saga.indiana-jones` | Indiana Jones | Indiana Jones | 84 | Indiana Jones Collection |
| `saga.back-to-the-future` | Retour vers le futur | Back to the Future | 264 | Back to the Future Collection |
| `saga.jurassic-park` | Jurassic Park | Jurassic Park | 328 | Jurassic Park Collection |
| `saga.toy-story` | Toy Story | Toy Story | 10194 | Toy Story Collection |
| `saga.pirates-of-the-caribbean` | Pirates des Caraïbes | Pirates of the Caribbean | 295 | Pirates of the Caribbean Collection |
| `saga.shrek` | Shrek | Shrek | 2150 | Shrek Collection |
| `saga.avatar` | Avatar | Avatar | 87096 | Avatar Collection |
| `saga.iron-man` | Iron Man | Iron Man | 131292 | Iron Man Collection |

- ~~Les dix `tmdb_id` et les dix `name` sont à vérifier~~ — amendé le 01/10 (D43 du 01/10) : **les douze `tmdb_id` et les douze `name` sont vérifiés** par le porteur le 01/10, par un appel TMDB `collection/{id}` hors CI ; une valeur fausse ne casserait rien (le thème resterait vide) mais rattacherait la saga à la mauvaise collection. Le nom français de TMDB (« Iron Man - Saga ») n'est jamais un libellé : les libellés du tableau sont écrits à la main. Plusieurs collections portent des films annoncés sans date (645, 328, 87096) : sans effet tant qu'ils ne sont pas importés.
- `rule_value` désigne un `collection.id` **local** : le seeder crée d'abord la ligne `collection` par son `tmdb_id` si elle manque, puis le thème qui la désigne. `collection.name` (`string(160)` NOT NULL, nom TMDB **non localisé**, jamais affiché à un joueur, `10` § 3.3) est le **littéral** du tableau : **le seeder n'appelle jamais TMDB**, parce qu'il tourne dans le hook de déploiement (contrat C18-bis, étape 6) et dans la suite de tests à zéro secret (`CLAUDE.md` § 8, règle 6). Une ligne `collection` déjà créée par `MovieImporter` n'est pas réécrite (insertion si absente, § 12.5), et `MovieImporter::collectionId()` ne réécrit pas non plus le nom d'une ligne existante : un film importé plus tard tombe dans la bonne saga sans autre geste.
- **Les sagas naissent non publiées** (`is_published = false`), comme tout thème ajouté par L30-9 ou créé en back-office (§ 12.3 ; amendé le 01/10, D43 du 01/10). Raison propre aux sagas : une saga compte quelques films, qui n'entrent au vivier qu'une fois curés ; publiée avant eux, elle bloquerait tout salon qui la choisirait seule. Sa publication est un geste de `20`, en back-office, **sans déploiement** (`questions-ouvertes.md` § Libellés de thèmes).
- La liste est **éditable en back-office** (libellés, publication, ordre ; **création d'un thème de toute nature**, saga sur une collection importée comprise, et thème manuel, D43 du 01/10), et le seeder ne réécrit jamais ce qui a été édité (§ 12.5). Le porteur peut étendre la liste sans spec ni déploiement — amendé le 01/10 (D43 du 01/10).

### 12.5 Seeder de plateforme : réconcilier les presets, amorcer les thèmes [J1]

Le hook de déploiement rejoue `PlatformDataSeeder` à chaque déploiement (contrat C18-bis, étape 6). Tel qu'écrit, il réécrit `is_published`, `sort_order` et les libellés : **une édition faite en back-office serait effacée au déploiement suivant**, ce qui contredit « publier une saga n'exige pas un déploiement » (S4 du 23/09). Il est donc **scindé** (exigence du contrat C18-bis au lot de cette spec) :

- **Réconciliation** de `setting_preset` par `key` (`updateOrCreate`), seule table dont ce seeder reste l'écrivain unique (`10` § 6.3) ;
- **Amorçage en insertion si absent** de `collection` (par `tmdb_id`), `theme` (par `key`) et `theme_label` (par `(theme_id, locale)`) : une ligne présente n'est **jamais réécrite**, quel que soit son contenu. **Un thème de nature `saga` n'est pas inséré si sa `collection` est déjà désignée par un thème `saga`** (`rule_value` = `collection.id` local), fût-ce sous une autre clé — cas d'une saga créée en back-office par `20` § 9.6 sous un autre libellé anglais (`saga.lotr` et non `saga.lord-of-the-rings` ; la clé dérive du libellé **anglais**, `20` § 9.6 — amendé le 01/10, D43 du 01/10, qui corrige ici « libellé français ») : la ligne existante l'emporte, ni son libellé ni ses appartenances ne sont touchés, et aucun job n'est dispatché pour elle (§ 13.2). Pourquoi : `20` n'admet qu'un thème de saga par collection (`ThemeStoreRequest` : « désignée par aucun thème de saga ») ; deux sagas sur une même collection afficheraient deux fois le même choix au sélecteur. **Même règle pour un thème `studio`** (amendé le 01/10, D43 du 01/10) : il n'est pas inséré si l'une de ses sociétés est déjà désignée par un thème studio, fût-ce dans une liste et sous une autre clé (`PlatformDataSeeder::isDesignatedByStudio()`, pendant de la garde de saga) ; `20` n'admet qu'un thème studio par société (§ 12.1).

Conséquences, écrites pour qu'elles ne surprennent pas : corriger un thème livré après son insertion (un identifiant de société erroné) se fait en back-office, pas en modifiant le seeder ; la garde « un libellé dans chaque locale activée » ne s'applique qu'à l'**insertion** d'un thème, avant toute écriture — compléter les libellés d'un thème existant pour une nouvelle locale reste l'étape 3 de la procédure de `05` § Ajouter une troisième langue. Sur un thème déjà présent, le seeder ajoute donc les libellés **absents** que sa définition fournit (amorçage par `(theme_id, locale)`), ne réécrit jamais un libellé présent et ne lève pas pour une locale que la définition ne fournit pas. — amendé le 25/09 (E19-4)

**`sort_order` par blocs de famille**, pour qu'une insertion ultérieure ne décale rien : `bloc × 100 + rang`, avec genre = 1, studio = 2, décennie = 3 (rang = `(année − 1930) / 10 + 1`), langue = 4, difficulté = 5, saga = 6. Pourquoi : l'ordre séquentiel actuel, figé à l'insertion, placerait les décennies ajoutées au J2 à la même position que des thèmes déjà insérés ; l'ordre d'affichage est `sort_order` puis `key`. La règle vit dans une seule méthode, `PlatformDataSeeder::sortOrderFor(string $key): int`. Noms publics ajoutés par le code livré — amendé le 25/09 (E19-3) : `PlatformDataSeeder::SORT_ORDER_BLOCK = 100`, largeur d'un bloc et seuil `sort_order < 100` de la migration ci-dessous ; `PlatformDataSeeder::deliveredThemeKeys(): list<string>`, chaque clé livrée par le seeder, que la migration parcourt. `sortOrderFor()` lève `InvalidArgumentException` pour une clé non livrée, sauf une décennie, dont le rang se calcule par la formule indépendamment de la liste livrée (`decade.1940` → 302, prêt pour L30-9), et pour tout rang hors `1..99`.

**Réalignement unique des thèmes déjà insérés.** Les 27 thèmes posés par le seeder actuel portent un `sort_order` séquentiel (1 à 27, réécrit à chaque passage, `PlatformDataSeeder` l.112). La version scindée ne réécrivant jamais une ligne présente, une base amorcée avant L30-7 — la production dès que le hook a tourné, D1 du 23/09 faisant naître la curation en production — les garderait, et tout thème ajouté ensuite (`204` pour Marvel, `302` à `304` pour les décennies, `402` pour les animés, `601` et suivants pour les sagas) s'afficherait après **tous** eux, décennies 1940-1960 comprises. Le lot L30-7 livre donc une **migration de données idempotente** qui pose `sortOrderFor(key)` sur chaque thème dont la clé est livrée par le seeder et dont `sort_order < 100`. C'est la seule réécriture de `theme` admise hors du back-office : jouée une fois, au déploiement de L30-7, avant que l'écran d'ordre de `20` n'existe (J2 ; J1 depuis D43 du 01/10, livré après L30-7 — amendé le 01/10), elle n'efface aucune édition. Elle ne touche aucune table de la règle 12 ; l'étape 4 du hook prend de toute façon l'instantané dès qu'une migration est en attente (contrat C18-bis). Fichier livré : `database/migrations/2026_09_24_300001_realign_platform_theme_sort_order.php`, hors de la série `2026_09_2x_1000NN` des migrations de schéma de `10` § 13.2, pour n'en préempter aucun numéro ; elle écrit par `DB::table('theme')` sans toucher `updated_at` (ce n'est pas une édition), et son `down()` ne fait rien : rétablir l'ordre séquentiel replacerait les thèmes livrés ensuite derrière tous les autres. — amendé le 25/09 (E19-3)

---

## 13. Appartenance film ↔ thème : l'évaluateur [J1 depuis D43 du 01/10]

### 13.1 Écriture d'une ligne `movie_theme`

`App\Support\Catalog\ThemeEvaluator` est **le seul évaluateur** du dépôt ; `DemoCatalogueSeeder::linkThemes()` et `ruleMatches()` lui délèguent et disparaissent. Aucun contrat ne fixe ses noms (le contrat C2 § 8 les laisse à cette spec) ; ils sont fixés ici, pour que `MovieImporter`, `MovieDifficultyDeriver`, le seeder de plateforme et le lot de `20` (L20-28, J1 depuis D43 du 01/10) les citent à la lettre — amendé le 01/10 :

```php
final readonly class ThemeEvaluator
{
    /** @param array<string, true> $tagKeys clés "tag_kind:tmdb_tag_id" des lignes movie_tmdb_tag du film */
    public function matches(Theme $theme, Movie $movie, array $tagKeys): bool; // règle pure du § 12.1, sans base
    public function syncMovie(Movie $movie, ?ThemeKind $kind = null, ?array $tagKeys = null): void;
        // tous les thèmes, ou ceux d'une nature ; dans la transaction de l'appelant ;
        // $tagKeys fourni par l'importeur depuis l'appel de détail, lu en base sinon (amendé le 01/10)
    public function syncTheme(Theme $theme): int;                              // tout le catalogue ; nombre de lignes changées
}
final class SyncThemeMembership implements ShouldQueueAfterCommit, ShouldBeUniqueUntilProcessing // App\Jobs\Catalog ; file par défaut ; après commit porté par la classe ; verrou d'unicité borné (uniqueFor) ; amendé le 01/10 (D43 du 01/10)
{
    public function __construct(public readonly int $themeId) {}            // uniqueId() = l'id du thème ; handle() relit le thème en tête ; thème introuvable (garde défensive : `20` n'offre aucune suppression) : ne fait rien
}
final class DeriveMovieDifficulty implements ShouldQueue, ShouldBeUnique {}  // App\Jobs\Catalog ; file par défaut ; sans argument, unique par classe
```

**Unicité jusqu'au traitement, et non jusqu'à la fin** — amendé le 01/10 (D43 du 01/10). `ShouldBeUnique` tiendrait le verrou d'unicité pendant tout `syncTheme()` : une règle corrigée pendant le passage enverrait un second job, **jeté en silence**, et l'appartenance resterait calculée sur l'ancienne règle. Avec `ShouldBeUniqueUntilProcessing`, le verrou tombe au début du traitement ; deux corrections rapprochées n'empilent au plus qu'un job en attente, et `handle()` relit le thème au début, donc évalue la règle en vigueur à son démarrage.

`catalog:themes` n'a aucune option. Appels de `20` : la correction manuelle de difficulté (J2) appelle `syncMovie($movie, ThemeKind::Difficulty)` dans sa transaction ; la création d'un thème ou le changement de sa règle dispatche `SyncThemeMembership` après commit et, **à partir de L30-10 seulement**, si `theme_kind = saga`, `DeriveMovieDifficulty` (§ 14.2) — amendé le 01/10 (D43 du 01/10). Une ligne s'écrit par une seule méthode privée, idempotente, que `syncMovie()` et `syncTheme()` partagent :

- `is_auto` = résultat de la règle (§ 12.1), **réécrit librement** à chaque évaluation ;
- `is_active = MovieTheme::resolveIsActive(is_auto, manual_state)` — `manual_state` prime ; aucune autre dérivation d'`is_active` n'est admise. **`manual_state` est relu sous verrou**, jamais pris d'une lecture antérieure (amendé le 01/10, D43 du 01/10) : chaque écriture se fait dans une transaction courte qui relit la ligne en `lockForUpdate` avant de calculer `is_active` — ou, de façon équivalente, par une seule instruction `UPDATE … SET is_auto = ?, is_active = CASE manual_state WHEN 'added' THEN 1 WHEN 'removed' THEN 0 ELSE ? END`. Sans cela, un `syncTheme()` qui a lu la ligne avant qu'un curateur pose `removed` réécrirait `is_active` vrai sur un film sorti exprès du thème (mise à jour perdue) ;
- une ligne **existe** dès que la règle matche **ou** qu'une exception existe ; une ligne avec `is_auto` faux et `manual_state` nul est **supprimée**, par un `DELETE … WHERE is_auto = 0 AND manual_state IS NULL` conditionnel, jamais d'après un modèle lu plus tôt (amendé le 01/10) ; une exception `removed` sur une ligne non automatique est **conservée**, pour qu'une évaluation ne la ressuscite pas (`10` § 3.7) ;
- `manual_state`, `assigned_by_id` et `assigned_at` ne sont **jamais** écrits par l'évaluateur : ils appartiennent au geste de curation de `20`, qui applique la même règle d'`is_active` dans la même transaction.

**Écrivain de l'exception manuelle** — amendé le 01/10 (D43 du 01/10). Une seule méthode écrit `manual_state`, `assigned_by_id` et `assigned_at` : `App\Actions\Curation\SetMovieThemeMembership::apply()` (`20` § 9.6), appelée par le geste de la fiche film **et** par le collage avec thèmes (`20` § 3.3). Elle verrouille la ligne `movie_theme` (ou la crée), recalcule `is_active` par `resolveIsActive()`, et rejoue **une fois** une collision d'insertion sur `movie_theme_uq`, comme l'évaluateur. Le collage l'appelle dans la transaction de l'import du film, **après** `syncMovie()`, avec `manual_state = added`, `assigned_by_id = import_run.actor_id` et l'instant de l'import — seulement si la policy `curate` de cet auteur autorise encore ce film, relue pour chaque film (un auteur supprimé ou rétrogradé ne pose plus rien, amendé le 01/10) —, sous deux règles de non-écrasement : **un `removed` n'est jamais changé en `added`** (un curateur qui a sorti un film d'une saga l'a fait exprès ; le film est compté à part dans le rapport du balayage), et **une ligne déjà active par la seule règle** (`is_auto` vrai, `manual_state` nul) **n'est pas touchée** — un `added` superflu figerait l'appartenance, qu'une correction ultérieure de la règle ne lèverait plus. Forme livrée (amendé le 01/10, D43 du 01/10) : le collage passe par `SetMovieThemeMembership::applyPasteAddition(Movie, Theme, ?int $actorId, CarbonImmutable $at): string`, qui partage l'écriture privée d'`apply()` et rend `applied`, `kept_removed` (le `removed` conservé, à compter au rapport) ou `already_active` (ligne active, par la règle ou par un ajout antérieur : rien n'est écrit) ; l'insertion seule est isolée dans un point de sauvegarde, la lecture verrouillée restant dans la transaction de l'appelant.

Tous les thèmes sont évalués, **publiés ou non** : publier un thème devient un basculement de drapeau instantané, sans recalcul. Un thème non publié ne filtre aucun vivier de salon, de tirage ni de leurres, le vivier l'élaguant (§ 3.3 clause 3) ; ses lignes ne sont lues que par sa mesure avant publication (`themeProbe`, § 12.3).

### 13.2 Déclencheurs

| Événement | Évaluation | Où |
|---|---|---|
| Import d'un film, resynchronisation (collection, année, langue, étiquettes réécrites, `10` § 9.3) | **synchrone**, tous les thèmes pour ce film, dans la transaction qui écrit le film ; les étiquettes viennent de l'appel de détail, sans relecture en base | appel ajouté à `MovieImporter` ; puis, pour un collage portant des thèmes, leurs exceptions `added` (§ 13.1) — amendé le 01/10 (D43 du 01/10) |
| Changement de difficulté effective (dérivation § 14, correction manuelle de `20`) | synchrone, thèmes de nature `difficulty` pour ce film | même transaction |
| Création d'un thème, changement de `rule_value` ou `rule_negated` (`theme_kind` est immuable, `20` § 9.6) | job `App\Jobs\Catalog\SyncThemeMembership`, **file par défaut**, unique par thème **jusqu'au début de son traitement** (§ 13.1) | dispatché après commit par le geste de `20` — amendé le 01/10 (D43 du 01/10) |
| Insertion d'un thème par `PlatformDataSeeder` (amorçage, § 12.5) | job `SyncThemeMembership` pour **chaque thème inséré** ; en plus, `DeriveMovieDifficulty` si un thème de saga est inséré (à partir de L30-10, § 14.2) | dispatché après commit par le seeder, donc par l'étape 6 du hook de déploiement (contrat C18-bis) ; rien pour une ligne déjà présente ni pour une saga non insérée parce que sa collection est déjà désignée (§ 12.5) |
| Rattrapage complet | commande `catalog:themes` : instantané `backup:snapshot`, puis dérivation de difficulté (§ 14, à partir de L30-10), **puis** évaluation de tous les thèmes | outil du porteur, joué à la main, jamais sur le chemin du curateur (D10 du 23/09) ni dans le hook |

Pourquoi synchrone par film : un film importé qui n'appartiendrait à ses thèmes qu'après un job laisserait une fenêtre où il manque au vivier d'un thème qu'il satisfait. Pourquoi un job par thème : réévaluer un thème touche tout le catalogue, et ne doit jamais retenir la requête du curateur ni passer sur la file `game`. Pourquoi le seeder dispatche : les thèmes qu'il amorce par L30-9 (décennies 1940-1960, Marvel, DC, animés, sagas, § 12.3) arrivent **après** le rattrapage de L30-8 et ne passent par aucun geste de `20` ; sans ce dispatch, ils naîtraient sans aucune appartenance — amendé le 01/10 (D43 du 01/10).

**Fenêtre assumée entre un import et la création d'un thème** — amendé le 01/10 (D43 du 01/10). Un film importé dans une transaction encore ouverte n'est pas vu par le `chunkById` d'un `SyncThemeMembership` lancé après le commit d'un thème nouveau, et le `syncMovie()` de cet import a lu la liste des thèmes avant ce commit : le film peut manquer le thème. La fenêtre dure le temps d'une transaction d'import, avec un seul curateur au J1 ; **`catalog:themes` la rattrape**, et l'écran des thèmes de `20` affiche l'appartenance réelle. Pour ne pas l'élargir, la liste des thèmes n'est **jamais** mise en cache pour la durée d'un balayage : au plus une lecture par appel de `syncMovie()`.

**Rattrapages, joués une fois chacun à la main par le porteur après le déploiement du lot** : avant L30-8, aucun film réel n'a d'appartenance ni de difficulté (§ 1.4) ; `catalog:themes` est joué au déploiement de **L30-8 (J1 depuis D43 du 01/10 — amendé le 01/10)**, sans quoi chaque thème afficherait zéro film réel, puis **rejoué au déploiement de L30-10**, qui y ajoute la dérivation : sans ce second passage, les thèmes de difficulté resteraient vides pour les films réels déjà importés jusqu'au prochain `import_run`, qui peut ne pas venir.

**Règle 12 — instantané avant `catalog:themes`.** Dès L30-10, `catalog:themes` écrit `movie.movie_difficulty_derived` et `movie.movie_difficulty` (§ 14.1) : c'est une commande qui touche `movie`. Elle appelle donc **en tête** `backup:snapshot` (contrat C18-bis), **sans `--if-pending`**, et sort en code non nul **sans rien écrire** si l'instantané échoue ou n'est pas vérifié (code de sortie non nul de `backup:snapshot`). La garde est posée dès la création de la commande (L30-8), où elle ne réécrit encore que `movie_theme` : une commande de rattrapage sur tout le catalogue ne change pas de contrat de sécurité d'un lot à l'autre, et son coût est un instantané de plus par passage manuel. Elle **n'entre jamais dans le hook de déploiement**, dont l'étape 4 (`backup:snapshot --if-pending`) ne couvre que les migrations en attente et ne prendrait aucun instantané pour elle. Le job `DeriveMovieDifficulty`, lui, n'est pas une commande ni un script au sens de la règle 12 : il appartient au chemin d'import ordinaire, comme `MovieImporter` qui écrit `movie` à chaque balayage, ne part qu'en fin d'`import_run`, de resynchronisation, de geste sur une saga, d'insertion d'une saga par le seeder ou de retrait (§ 14.2), et ne réécrit que des colonnes dérivées, jamais `movie_difficulty_override`.

Deux chemins concurrents (un import et un job de thème, ou un job et un geste de curation) écrivent la même ligne sous l'unique `movie_theme_uq` : la méthode d'écriture est idempotente, relit la ligne sous verrou, et une collision d'insertion est rejouée une fois, de chaque côté (§ 13.1). ~~Aucune écriture `admin_action` : l'évaluation n'est pas un geste engageant, et la liste fermée de `admin_action` n'a aucun cas de thème (contrat C14).~~ Amendé le 01/10 (D43 du 01/10) : aucune écriture `admin_action` **pour l'évaluation automatique**, qui n'est pas un geste du back-office (D41 du 30/09 ne vise que les écritures de `routes/admin.php`) ; les gestes de thème de `20` § 9.6 écrivent leurs cas (`10` § 8.3).

### 13.3 Gardes de jeu [J1 depuis D43 du 01/10]

Deux gardes, écrites ici parce que les films réels ont désormais des appartenances dès le J1 — amendé le 01/10 (D43 du 01/10) :

- **Aucune appartenance d'un film** — thème, saga, collection, société — ne figure dans une charge utile de manche, de palier ou de resynchronisation **avant la révélation**, ciblée ou diffusée (règle 3) : une clé `saga.iron-man` désigne presque un titre. Les clés de thème choisies par l'hôte restent diffusées au salon (`SettingsChanged`, `themeKeys`) : c'est un réglage du salon, qui ne dit pas à quel film appartient une manche. Une assertion des tests de charge utile de `60` refuse toute clé `theme`, `themes`, `collection` ou `collection_id` dans les événements `Round*`.
- **Les appartenances orientent les leurres sans sortir du serveur** — amendé le 01/10 (D44 du 01/10) : `DecoyPicker` lit `movie_theme` et `collection_id` pour composer un QCM apparenté (`70` § 10.3 bis) ; seules les chaînes des titres sont livrées, jamais une clé de thème ni de collection.
- **Un thème ne modifie jamais `answer_key` ni l'ambiguïté d'un préfixe** : celle-ci se mesure sur le **catalogue publié entier**, jamais sur le vivier filtré par thème (`70`, `CLAUDE.md` § 2) ; créer, corriger ou publier un thème ne reprojette rien.

---

## 14. Difficulté intrinsèque dérivée [J2]

`movie_difficulty` n'a qu'un consommateur, le thème « difficulté » (`00` § Le jeu en une manche) ; sa dérivation, qu'aucune spec ne revendiquait, appartient à cette spec au titre de la taxonomie (carte de propriété n° 17). Principe décidé (`questions-ouvertes.md` § `movie_difficulty`) : **notoriété normalisée par décile de `original_language`**, pondérée par l'appartenance à une saga retenue, corrigeable en curation. Sans cette normalisation, le filtre d'import (décision 11) et la voie d'exception classeraient La Vie est belle ou Le Labyrinthe de Pan en « très difficile ».

### 14.1 Algorithme

`App\Support\Catalog\MovieDifficultyDeriver::derive(): int` (nombre de films changés) :

1. **Population** `P` = films dont `import_source ≠ 'demo'` et `availability ≠ 'withdrawn'`. Les films de démonstration ne sont ni comptés ni recalculés : leur `vote_count` est tiré au hasard par la fabrique, et leur difficulté est posée à la main pour garder les thèmes de démonstration déterministes (point non couvert par la pré-analyse).
2. **Seaux** : un par `original_language` ; toute langue qui compte moins de `catalog.difficulty.min_language_sample` films dans `P` rejoint un **seau commun**. Pourquoi : un décile calculé sur deux films coréens ne veut rien dire.
3. **Décile** d'un film de `vote_count` `v` dans un seau de taille `n` : `r` = nombre de films du seau de `vote_count < v` (strictement), `d = intdiv(10 × r, n) + 1` ∈ `1..10`. Arithmétique entière, sans flottant ; deux films à égalité de votes ont le même décile.
4. **Saga** : si `collection_id` est désigné par un thème de nature `saga` (publié ou non — une saga figurant au § 12.4 est « retenue »), `d = min(10, d + catalog.difficulty.saga_decile_bonus)`.
5. **Classe** : `d ∈ {9, 10}` → `very_easy` ; `{7, 8}` → `easy` ; `{5, 6}` → `medium` ; `{3, 4}` → `hard` ; `{1, 2}` → `very_hard`. Deux déciles par cas nommé : c'est la conséquence structurelle de cinq cas et de dix déciles, pas une valeur réglable.
6. **Écriture**, film par film, seulement si `movie_difficulty_derived` change, par une seule instruction qui relit l'override en base : `UPDATE movie SET movie_difficulty_derived = :d, movie_difficulty = COALESCE(movie_difficulty_override, :d) WHERE id = :id`. **Jamais une valeur d'override lue plus tôt** : la lecture initiale est faite hors transaction et peut précéder de plusieurs secondes une correction manuelle de `20` ; écrire `override ?? derived` depuis cette lecture écraserait la correction (mise à jour perdue) et laisserait faux les thèmes de difficulté du film jusqu'à la dérivation suivante. Puis, dans la même transaction, la valeur effective est relue et, si elle a changé, les thèmes de difficulté du film sont réévalués (`ThemeEvaluator::syncMovie($movie, ThemeKind::Difficulty)`, § 13.2). `movie_difficulty_override` n'est **jamais** écrite ici : c'est ce qui la fait survivre au réimport (`10` § 3.1).

Lecture en une requête, triée par `(original_language, vote_count, id)` et servie par `movie_decile_idx` ; le calcul est fait en PHP, **sans `GROUP BY`**. C'est un écart au texte de `10` § 12 (« `WHERE original_language = ? ORDER BY vote_count` », « job périodique et après chaque balayage ») : une lecture unique évite une requête par langue pour des seaux qui se fusionnent de toute façon (point 2), et un décile ne change qu'avec `P` ou `vote_count`, donc aux seuls événements du § 14.2 — un déclenchement périodique n'apporterait rien. Exigence E10-N2, non consolidée.

### 14.2 Configuration et déclencheurs

Deux clés nouvelles de `config/catalog.php`, section `difficulty` (configuration de catalogue, aucune colonne) : `min_language_sample` (défaut 20, borne `≥ 1`) et `saga_decile_bonus` (défaut 2, soit une classe, bornes `[0, 9]`). `MovieDifficultyDeriver` est leur **seul lecteur**. Hors bornes, il lève `InvalidArgumentException` au démarrage de la dérivation, **jamais d'écrêtage silencieux** (règle 2 : défaut **et** bornes ; même garde que `PlatformLimits` hors bornes, contrat C0 — le trait `CatalogImportValidationRules` borne déjà ses clés de `config/catalog.php`, mais par `max(1, …)`, écrêtage qu'on ne reprend pas ici : une difficulté faussée en silence ne se verrait qu'à l'écran des thèmes). Pourquoi ces bornes : un bonus négatif donnerait `d ≤ 0`, qu'aucune classe du point 5 ne couvre, et un bonus au-delà de 9 rendrait `very_easy` toute saga quel que soit son décile ; un échantillon nul ou négatif n'a pas de sens. Elles ne touchent aucune partie jouée : le tirage est matérialisé, et la difficulté n'entre dans un tirage que par l'appartenance aux thèmes au moment du lancement.

Job `App\Jobs\Catalog\DeriveMovieDifficulty`, **file par défaut**, unique, sans argument (§ 13.1) : dispatché après commit
- à la fin de chaque `import_run` et de chaque resynchronisation ;
- après la création d'un thème de saga ou le changement de sa collection par `20` (`20` § 9.6 : aucune suppression de thème ; un thème se dépublie), et après l'**insertion d'un thème de saga par `PlatformDataSeeder`** (§ 13.2) ;
- après toute transition d'`availability` **vers ou depuis `withdrawn`** (geste de `20`), qui change la population `P` et donc les déciles des autres films de la même langue.

`catalog:themes` joue la dérivation en tête, juste après l'instantané (§ 13.2). Jamais de déclenchement périodique (E10-N2). La correction manuelle (`20`) ne relance pas la dérivation : elle réécrit la valeur effective et appelle `syncMovie($movie, ThemeKind::Difficulty)`, dans sa transaction.

---

## 15. Sélecteur de thèmes et thèmes proposables [J2 ; mesure d'un thème au J1, D43 du 01/10]

Le sélecteur reste masqué au J1 : `themeSelectorVisible()` et `ProposableThemes` restent au J2 (L30-11), et la page du lobby continue de recevoir un sélecteur masqué. Seule la mesure d'un thème (`themeWorks()`, § 12.3) passe au J1, par L30-11a, pour l'écran des thèmes de `20` — amendé le 01/10 (D43 du 01/10).

### 15.1 Seuil de masquage (question Q30-3)

`PoolReporter::themeSelectorVisible()` = `countWorks(PoolScope::catalogue([], RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND)) ≥ PlatformLimits::themeSelectorMinPool()`.

- **Mesure** : le vivier **catalogue**, sans thème ni non-répétition, au `N` par défaut, en œuvres. Pourquoi : « état de jalon, jamais une règle de jeu » (`00` § Jalons et budget-temps) — la visibilité ne dépend ni du salon, ni de ses réglages.
- **Valeur** : 150 œuvres, déclarée par cette spec, portée par `PlatformLimits::themeSelectorMinPool()` (borne ≥ 0, surchargeable par `config/game.php › platform`, contrat C0 ; amendement A-33). Pourquoi 150 au réglage par défaut : avec `M` = 10, un thème seul couvrant au moins un quinzième du catalogue devient lançable ; sous ce seuil, la plupart des thèmes seuls bloqueraient. Revue après le lot pilote, par configuration.
- **Recalculée au rendu** : la réapparition est automatique, sans déploiement.
- **Purement d'affichage** : le serveur accepte et valide `themeKeys` même sélecteur masqué (contrat C0, champ n° 1).

### 15.2 Thèmes proposables

`App\Support\Draw\ProposableThemes::all()` rend la liste des thèmes **publiés**, triée par `sort_order` puis `key`, chacun sous la forme `{ key, labels: { <locale activée>: libellé } }` — `App\ValueObjects\Catalog\ProposableTheme`. **Sans `theme.id`** (`10` § 1.1, contrat C0 § 6) et avec les libellés de **toutes** les locales activées, en données : chaque joueur rend celui de sa langue, et un changement de langue n'exige aucun rechargement de la liste (`05`, règle 4). Sa place dans les props de page et son rendu appartiennent à `50` et `90`.

---

## 16. Où vit chaque chiffre

| Valeur | Source unique | Surcharge |
|---|---|---|
| Bornes et défaut de `N`, `M`, `MIN_ROUNDS_COUNT`, `N` par défaut du seuil | `RoomSettingsBounds` | code |
| `N`, `M`, thèmes, `noRepeatMovies` d'une partie | `room.settings` au lobby, `game.settings_snapshot` en partie, preset du site en solo | réglage d'hôte |
| Table nominale `N` → niveaux | `FrameLevelCoverage::nominal()` | constante de règle de produit |
| Marge de tirage (3 ; bornes `[0, min(MAX_DRAW_SUBSTITUTE_MARGIN, MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE)]`, soit `[0, 150]` aux défauts, `50` § 2.3 et § 2.7 — amendé le 25/09 (E10-2, E10-8)) | `PlatformLimits::drawSubstituteMargin()` | `game.platform.draw_substitute_margin` |
| Fenêtre de mémoire (90 jours / 500 manches) | `PlatformLimits::roomMemoryWindowDays()` / `roomMemoryWindowRounds()` | `game.platform.room_memory_window_*` |
| Seuil du sélecteur (150) | `PlatformLimits::themeSelectorMinPool()` | `game.platform.theme_selector_min_pool` |
| Seuil de publication d'un thème (`M` par défaut, mesuré au `N` par défaut, § 12.3) | `RoomSettingsBounds::DEFAULT_ROUNDS_COUNT` / `DEFAULT_FRAMES_PER_ROUND` | code (suit les défauts) |
| `SEED_BYTES = 32` | `SeededPrf` (`10` § 7.2) | constante de sécurité |
| Chaînes de contexte | `DrawContext` (registre fermé) | code |
| Prédicat de variante jouable | `10` § 3.2, `Frame::isServable()` | schéma |
| Largeur d'une décennie (10 ans) | définition | — |
| Échantillon minimal de langue (20, borne `≥ 1`), bonus de saga (2 déciles, bornes `[0, 9]`) | `config/catalog.php › difficulty`, lu par `MovieDifficultyDeriver` seul, `InvalidArgumentException` hors bornes | configuration |
| Deux déciles par classe de difficulté | `MovieDifficultyDeriver` | conséquence de cinq cas et dix déciles |
| Identifiants TMDB des thèmes livrés et des sagas, noms littéraux des douze `collection` et des sociétés nommées dans `tmdb_company` (amendé le 01/10, D43 du 01/10) | `PlatformDataSeeder` (jamais d'appel TMDB) | back-office après insertion |
| Plafonds d'une règle studio (8 identifiants, 64 caractères — largeur de colonne, `10` § 3.7) | `ThemeRules::STUDIO_MAX_COMPANIES` ; colonne | code (amendé le 01/10, D43 du 01/10) |
| `sort_order` des thèmes livrés (`bloc × 100 + rang`) | `PlatformDataSeeder::sortOrderFor()`, largeur de bloc `PlatformDataSeeder::SORT_ORDER_BLOCK` — amendé le 25/09 (E19-3) | back-office après insertion |

Aucun littéral de jeu dans `App\Support\Draw` ni dans `App\Support\Catalog\{ThemeEvaluator, MovieDifficultyDeriver}`.

---

## 17. Arbitrages

**B1 — Non-répétition : retrait ou préférence ?** Écarté : classer les films au lieu de les retirer (« préférence, jamais blocage »). Retenu : retrait et blocage nommé, remède « nouveau salon » (D28 du 23/09). Raison : la préférence réécrivait cinq passages verrouillés (`00` § Réglages du salon et § Déroulé d'une partie, `10` § 12 et § 6.1) ; l'impasse n'en est pas une, un nouveau salon ayant une mémoire vide.

**B2 — Unité du vivier : films ou œuvres ?** Écarté : `COUNT(DISTINCT movie.id)`. Retenu : œuvres. Raison : § 3.4 — la garde passerait à `W = M` et le tirage échouerait.

**B3 — Contexte de graine par identifiant de ligne ou par position ?** Écarté : `{roundId}`. Retenu : `{sequenceIndex}`. Raison : tirage calculable avant l'insertion, testable en fonction pure, rejouable sans base.

**B4 — Repli de niveau seedé ou déterministe ?** Écarté : départage des égalités de coût par la graine. Retenu : ordre lexicographique, donc le plus cryptique. Raison : les niveaux tirés pour un film ne doivent pas varier d'une partie à l'autre ; le palier le mieux payé reste le plus cryptique.

**B5 — Chargement du tirage : paresseux ou complet ?** Retenu : complet, en deux requêtes. Raison : pureté de `drawFrom` et coût borné (§ 6.2).

**B6 — Thèmes ajoutés au J2 livrés publiés ?** Écarté : publiés à l'insertion comme les thèmes du J1 ; écarté aussi : une exception réservée aux sagas et aux animés, qui laissait naître publiés Marvel et les décennies 1940-1960 alors qu'ils peuvent être tout aussi vides. Retenu : **tout thème ajouté par L30-9 naît non publié**, règle unique (§ 12.3). Raison : un thème ajouté peut ne compter aucun film curé à l'ouverture du J2 (§ 12.3, § 12.4), et le thème des animés est de surcroît faux tant que les retraits manuels ne sont pas faits ; publier est un geste de `20` sans déploiement. Amendé le 01/10 (D43 du 01/10) : la règle s'étend aux thèmes créés en back-office et à `studio.dc` ; les thèmes de L30-9 arrivent au J1.

**B7 — Seuil du sélecteur : global, par thème ou manuel ?** Retenu : global, configuré, mesuré sur le vivier catalogue (Q30-3). Raison : lecture littérale de « un seuil déclaré » et « réapparaît quand il est franchi » (`00` § Jalons et budget-temps), sans requête de plus au lobby.

**B8 — Difficulté : quintiles ou déciles ?** Retenu : déciles, regroupés deux à deux. Raison : la décision dit « décile » ; le regroupement donne les cinq cas nommés sans trahir la normalisation, et le bonus de saga s'exprime à la granularité fine.

**B9 — Un studio qui vit sous plusieurs sociétés TMDB : une règle multi-valeurs, plusieurs thèmes, ou des ajouts manuels ?** (D43 du 01/10 — amendé le 01/10) Écarté : un thème par société (trois thèmes « DC » au sélecteur) ; écarté : une société principale complétée d'ajouts manuels (une centaine de gestes pour Disney, à refaire pour chaque film importé) ; écarté : une table de règles composées (`10` § 3.7 n'admet qu'un discriminant typé par thème). Retenu : pour la seule nature `studio`, une liste d'identifiants dans la même colonne, union, bornée à 8 identifiants et 64 caractères (§ 12.1). Raison : aucune migration, une seule requête indexée par `IN`, et le relevé du porteur du 01/10 montre que ni DC ni Disney ne tiennent dans une société.

---

## Exigences adressées à 10

Consolidées dans la feuille de contrats du 23/09, **sauf E10-N1 et E10-N2**, nouvelles, non consolidées et **signalées au porteur** dans le compte rendu de fin de session, comme R-46 et R-47 de la feuille (règle d'usage : un écart à la feuille se signale, il ne se corrige jamais en silence). Cette spec ne demande **aucune colonne, aucun index, aucun cas d'enum de colonne** : textes seulement.

| Exigence | Objet, en une ligne |
|---|---|
| E10-07 | Consommée via `70` : cas `choices_unavailable`, issue du cas terminal des leurres en Facile (§ 10.3). |
| E10-11 (a) | La charge client des réglages expose `themeKeys`, jamais `themeIds` ; seules les clés de thème quittent le serveur (§ 3.1). |
| E10-15 | Leurres tirés à la première composition (`T₁` Facile, `T_N` Normal), contextes `decoys()` / `decoysOriginal()` (§ 10). |
| E10-23 | A16 « published ⇒ `levels_mask & 21 = 21` » devient une garde de transition ; le vivier ne teste que `levels_count ≥ N` ; film incomplet signalé, jamais dépublié d'office (§ 2.5). |
| E10-25 | Variante de substitution choisie par `VariantChooser::substitute()` à la frappe, même niveau, fichier présent (§ 8.1). |
| E10-27 | Énumération de `PlatformLimits` : `drawSubstituteMargin()` (3), `roomMemoryWindowDays()` (90), `roomMemoryWindowRounds()` (500), `themeSelectorMinPool()` (150). |
| E10-37 | `draw_pool_size` compté en œuvres, `#[Hidden]` par hygiène, justification « réduirait le champ » retirée (§ 4.6). |
| E10-40 | Registre fermé `DrawContext` et algorithme PRF ; `tiebreak:{roundId}` retiré ; aucune égalité de score tranchée par la graine (§ 5). |
| E10-41 | Consommée : `draw_seed` et `draw_pool_size` posés à l'INSERT de `game` par `OpenGame` et figés ; `MaterializeDraw` ne les écrit pas (§ 6.1, § 6.4). |
| E10-45 | Manches de réserve matérialisées au lancement, `round_number` NULL, jamais démarrées tant qu'elles ne remplacent rien (§ 6.3). |
| E10-46 | Consommée : une manche déprogrammée à la pause (`started_at` NULL) n'est pas jouée (§ 1.3). |
| E10-47 | Consommée : `seen_frame` upserté à `Tᵢ` sur `served_frame_id`, en multijoueur seulement (§ 7.2). |
| E10-54 | Ordre du QCM par `permutation(DrawContext::qcmOrder(sequence_index, public_id), 4)` (§ 10.1). |
| E10-55 | Une ligne `seen_frame` antérieure à `RoomMemoryWindow::since()` est lue comme absente (§ 7.1). |
| E10-56 | Fenêtre unique `RoomMemoryWindow` pour la non-répétition, la préférence de variante et la purge `seen_frame` (§ 3.5). |
| E10-58 | Consommée : barrière 4 reformulée en « aucun identifiant d'adressage réutilisable » ; le dictionnaire visuel appris en solo reste un résidu nommé par `60` (§ 9, § 11). |
| E10-62 | Consommée : clôture forcée `stale_game` et clôture d'une partie bloquée par `game:reschedule` (`60` § 14.4), toutes deux par `FinalizeGame`, sources du résidu du § 1.3. |
| E10-64 | § 12 : vivier en œuvres, thèmes intersectés avec les publiés, aucun masque 21, fenêtre `RoomMemoryWindow`, « joué » = `started_at ≤ now`, résidu des parties closes d'office (`stale_game`, partie bloquée) nommé ici (§ 1.3, § 3). |
| E10-67 | § 15 l.1409 : `FrameLevelCoverage::nominal(int)` / `select(int, int)` (§ 2). |
| **E10-N1 — exigence nouvelle, non consolidée** | `10` § 3.3 l.309 : « Deux consommations seulement » de `collection` devient « trois » — `rule_value` des thèmes de saga, pondération de `movie_difficulty_derived` (§ 14.1), **et** suggestion des candidats au regroupement `movie_group` par `20` (contradiction n° 27). Texte seul. |
| **E10-N2 — exigence nouvelle, non consolidée** | `10` § 12, ligne « Décile de notoriété par langue » (l.1205) : « lecture unique triée par `(original_language, vote_count, id)`, servie par `movie_decile_idx` ; déclenchée par événement (fin d'`import_run`, resynchronisation, geste ou insertion d'un thème de saga, transition vers ou depuis `withdrawn`), jamais périodique » (§ 14.1, § 14.2). Texte seul. |

---

## Amendements à d'autres documents

Cette spec ne modifie aucun autre document : elle cite les amendements dont elle dépend, et leur application au corpus est un travail séparé, postérieur.

**Consolidés dans la feuille de contrats**, dont cette spec est émettrice ou dépendante :

| Amendement | Document | Objet |
|---|---|---|
| A-11 | `00` l.96, l.117 | Marge `drawSubstituteMargin()` (3), comptée en œuvres ; `min(M + marge, W)` œuvres tirées, `W` étant le vivier en œuvres. |
| A-12 | `00` l.100 | Au J1, catalogue en passe 1 : Hardcore grisé, `N` jouable le plus proche. |
| A-13 | `00` l.104, l.116 | `noRepeatMovies` quatrième cause ; remède J1 « nouveau salon », interrupteur Avancé au J2. |
| A-19 | `00` l.137 | Départage des scores : « jamais la graine ». |
| A-25 | `00` lexique | Vivier catalogue / du salon, œuvre, mémoire du salon. |
| A-26 | `00` l.306 | « Couvrant 1, 3 et 5 à sa publication ». |
| A-32 | `00` l.363 | 30 au J1 (vivier, tirage, variantes, repli, non-répétition, `movie_group`, substitution, leurres) ; thèmes au J2 ; « ≈ 6 parties de 10 manches ». |
| A-33 | `00` l.370 | Seuil déclaré = `themeSelectorMinPool()` (150), vivier catalogue sans thème au `N` par défaut. |
| A-49 | `05` l.309, l.313 | Le « corpus de leurres disponible » devient la règle de D21 du 23/09, renvoyant à `70` et à `PoolScope::forDecoys`. |
| A-51 | `questions-ouvertes.md` l.78 | 30 entre au J1. |
| A-63 | `questions-ouvertes.md` l.331 | « Marge `drawSubstituteMargin()` (3), comptée en œuvres. » |
| A-66 | `questions-ouvertes.md` l.384 | « ≈ 6 parties de 10 manches ». |
| A-69 | `CLAUDE.md` § 2 | Repli de niveau par `FrameLevelCoverage::select` ; vivier catalogue et vivier du salon en œuvres ; `DrawContext` ; `seen_frame` hors fenêtre lue comme non vue. |
| A-74 | `CLAUDE.md` § 6 | Lexique : œuvre, vivier catalogue / du salon, mémoire du salon. |
| A-75 | `CLAUDE.md` § 7 | Règle 9 : « couvrant 1, 3 et 5 à sa publication ». |

**Nouveaux, non consolidés** : A-N1 à A-N4 portent la résolution de la contradiction n° 27 et de la pré-analyse, qu'aucun amendement consolidé ne couvre ; A-N5 inscrit au lexique un terme propre à cette spec. Comme E10-N1 et E10-N2, ils sont **signalés au porteur** dans le compte rendu de fin de session, comme R-46 et R-47 de la feuille.

| Amendement | Document | Texte cible |
|---|---|---|
| A-N1 | `00` l.280, lexique « Thème » | « Critère de sélection (genre, décennie, studio, saga, langue originale, difficulté). » |
| A-N2 | `00` l.304, lexique « Difficulté du film » | « Dérivée de la notoriété par décile de langue originale, corrigeable en curation ; consommée par le thème « difficulté » et par rien d'autre. » |
| A-N3 | `questions-ouvertes.md` l.305 | « Thèmes de saga : une dizaine de collections TMDB, liste par défaut dans `30` § 12.4, livrée par seeder, éditable en back-office. Pixar, Ghibli et Marvel sont des thèmes studio. » |
| A-N4 | `questions-ouvertes.md` l.347 | Colonne « Attend encore » de `30` : « — » (S4 du 23/09). Absorbable par A-64. |
| A-N5 | `00` lexique et `CLAUDE.md` § 6 | Ajouter « repli de niveau → `level fallback` (`FrameLevelCoverage::usesFallback()`) : choix de `N` niveaux disponibles qui diffèrent de la répartition nominale ». Le terme est déjà employé par `10` (§ 7.4, § 15) et `CLAUDE.md` § 2 sans entrée de lexique. |

---

## Lots d'implémentation

| Lot | Jalon | Objet | Dépend de | Heures |
|---|---|---|---|---|
| L30-1 | J1 | `FrameLevelCoverage` et retrait de la table nominale des fabriques | — | 2-3 |
| L30-2 | J1 | `PoolScope`, `PoolQuery`, `PoolCandidate`, `RoomMemoryWindow` | L30-1 ; L50-1 (C0, `config/game.php`, famille « tirage et mémoire ») ; L100-1 (C18, groupe `mysql`) | 5-7 |
| L30-3 | J1 | `PoolReporter`, `PoolReport`, `PoolRemedy`, enums, `PoolTooSmallException`, `types/pool.ts` | L30-2 ; C0 (`RoomSettingsEditor`) ; L90-1 (C16, périmètre `WATCHED`) | 4-6 |
| L30-4 | J1 | `SeededPrf`, `DrawContext` | — | 2-3 |
| L30-5 | J1 | `GameDrawer`, `DrawInput`, `DrawResult`, `VariantChooser::choose`, réécriture du test de chaîne | L30-1, L30-2, L30-4 ; L50-1 (`drawSubstituteMargin()`) | 6-8 |
| L30-6 | J1 | `VariantChooser::substitute`, `ReplacementRoundChooser` | L30-5 ; disque `frames` (C8, C9) | 3-4 |
| L30-7 | J1 | Scission de `PlatformDataSeeder` (presets réconciliés, thèmes amorcés), réalignement de `sort_order` | — | 3-4 |
| L30-8 | **J1 (D43 du 01/10)** | `ThemeEvaluator`, job par thème, `catalog:themes` et son instantané, appel dans l'importeur ; noms des sociétés (`tmdb_company`, `10` § 3.6 bis) écrits à l'import et rattrapés par `catalog:company-names` | L30-7 ; L100-6 (`backup:snapshot`, C18-bis, livré) | 7-10 |
| L30-9 | **J1 (D43 du 01/10)** | Thèmes livrés : décennies sans trou, Marvel, **DC** (règle studio multi-valeurs), animés, **douze** sagas et leurs collections, sociétés nommées, évaluation à l'amorçage, films de démonstration | L30-7, L30-8 | 5-6 |
| L30-10 | J2 | `MovieDifficultyDeriver`, job, configuration bornée, dérivation dans `catalog:themes` | L30-8 | 5-7 |
| **L30-11a** | **J1 (D43 du 01/10)** | Mesure d'un thème : `PoolScope::themeProbe()`, `themesUnpublishedIncluded`, `PoolReporter::themeWorks()` | L30-3, L30-8 | 1-2 |
| L30-11 | J2 | `themeSelectorVisible()`, `ProposableThemes` — amendé le 01/10 : la mesure d'un thème en est sortie (L30-11a) | L30-11a, L30-9 ; L50-1 (`themeSelectorMinPool()`) | 2-3 |

La barre « terminé » est incluse dans chaque fourchette (facteur 1,5 à 2, S3 du 23/09) : tests Pest verts sous SQLite **et**, pour les fichiers du groupe `mysql`, sous MySQL ; PHPStan niveau 7 sans erreur ; Pint ; aucune chaîne en dur (cette spec n'émet que des codes, les clés `room.pool.*` et leur couverture FR/EN appartiennent à `50`) ; libellés FR et EN complets pour tout thème livré. Aucun lot de cette spec ne porte d'écran : les états de chargement, d'erreur, de déconnexion et le parcours clavier du compteur de vivier et du sélecteur relèvent de `50` et `90`. **Aucune variable d'ajustement** : le découpage de cette spec est celui du jalon (thèmes, difficulté et sélecteur au J2, § 1.4 ; amendé le 01/10, D43 du 01/10 : thèmes au J1, difficulté et sélecteur au J2) ; chaque lot du J1 est sur le chemin du premier lancement, et D35 du 23/09 (J1 complet) rend sans objet toute variable d'ajustement de développement. Le nombre de films du J1, réglé par le verdict du pilote (D10 du 23/09), relève de la curation : il ne change aucun lot de cette spec, seulement les effectifs du § 4.5 (parties par salon, probabilité du cas terminal des leurres). — amendé le 23/09

### L30-1 — `FrameLevelCoverage` [J1]

- **Dépendances** : aucune.
- **Crée** : `app/ValueObjects/Catalog/FrameLevelCoverage.php` ; `tests/Feature/Draw/FrameLevelCoverageTest.php` ; `tests/Feature/Architecture/DrawBoundaryTest.php`.
- **Modifie** : `database/factories/MovieFactory.php` (suppression de `expectedFrameLevels()`, `playable()` appelle `nominal()`) ; `database/seeders/DemoCatalogueSeeder.php` (`frames()`) ; `tests/Feature/Schema/DemoCatalogueChainTest.php` (appelants) ; `database/factories/RoundTierFactory.php` (`nominalLevel()`, troisième copie de la table livrée après le commit `d167a6a`, lit `FrameLevelCoverage::nominal($framesPerRound)[$tierIndex − 1]` ; un `N` hors bornes, ou un palier hors de `1..N` sans niveau explicite, lève au lieu de retomber sur `N` = 3 ou sur `Level1`) — amendé le 25/09 (E34-1).
- **Tests** — `tests/Feature/Draw/FrameLevelCoverageTest.php` : « la répartition nominale est celle de 00 pour chaque N dans les bornes » ; « nominal refuse un N hors des bornes de RoomSettingsBounds » ; « select rend la répartition nominale quand la banque la couvre » ; « select se replie sur les niveaux disponibles les plus proches sans doublon » (jeu de données = table du § 2.3) ; « select départage une égalité de coût vers le niveau le plus cryptique » ; « select rend null sous N niveaux distincts, exactement comme supportsFramesPerRound » ; « l'éligibilité est monotone en N » ; « le nombre de cas de FrameLevel égale la borne haute de N » ; « un masque hors bornes lève InvalidArgumentException » ; « usesFallback est vrai exactement quand select diffère de nominal ». `tests/Feature/Architecture/DrawBoundaryTest.php` : « la table nominale n'est écrite que dans FrameLevelCoverage » (forme livrée — amendé le 25/09 (E34-2, E34-4) : balayage du code, commentaires exclus, de `app/`, `bootstrap/`, `config/`, `database/`, `routes/`, `tests/` et `resources/js/`, cas `self::`/`static::`/`FrameLevel::LevelN` ramenés à leur entier ; deux formes reconnues, virgule finale admise : une entrée `N => [niveaux nominaux de N]` (ou `N: […]` en TypeScript ; clé `default` reconnue pour la répartition de `N` = 3) partout, et la liste `1,2,4,5` dans `app/`, `database/` et `resources/js/` seulement ; exclusions nommées : `FrameLevelCoverage`, son oracle `FrameLevelCoverageTest` et le fichier de garde ; quatre témoins négatifs littéraux (table PHP multi-lignes, `match` en `self::`/`static::`, `default` pleinement qualifié, objet TypeScript multi-lignes) qui doivent tous être signalés ; absence de tout jeton `expectedFrameLevels`).
- **Estimation** : 2 à 3 h.

### L30-2 — Constructeur unique du vivier [J1]

- **Dépendances** : L30-1 ; **L50-1**, qui crée `config/game.php` (sections `platform` et `engine`, contrat C0 § 2 : « le premier lot qui crée le fichier pose les sections `platform` et `engine` ») et la famille « tirage et mémoire » de `PlatformLimits` (`drawSubstituteMargin()`, `roomMemoryWindowDays()`, `roomMemoryWindowRounds()`, `themeSelectorMinPool()`), dont les tests restent à `50` ; **L100-1**, qui pose `requireMysql()` et le groupe `mysql` dans `tests/Pest.php` (contrat C18). Ce lot ne crée ni ne modifie ces fichiers : s'ils manquent, c'est l'ordre des lots qui est faux, pas le périmètre de celui-ci — l'estimation ne comprend donc aucun travail de `50` ni de `100`.
- **Crée** : `app/Support/Draw/{PoolScope, PoolQuery, PoolCandidate, RoomMemoryWindow}.php` ; les quatre fichiers de tests ci-dessous ; `tests/Support/Draw/PoolFixtures.php` (fixtures communes : film jouable à banque réelle, partie, manche datée, appartenance à un thème ; crée de vraies `frame` publiées sur le disque `frames` simulé et laisse le projecteur calculer `levels_mask` / `levels_count`, sans compteur de projection écrit à la main ; réutilisées par L30-3, réutilisables par L30-5) — amendé le 25/09 (E35-5).
- **Modifie** : `app/Providers/AppServiceProvider.php` (liaison `scoped` de `PoolQuery`, § 3.1).
- **Tests** — `tests/Feature/Draw/PoolQueryTest.php` : « le vivier compte des œuvres : deux films d'un même movie_group comptent pour un » ; « le vivier ne teste que levels_count >= N, jamais le masque 1-3-5 » ; « une sélection de thèmes vide prend la branche sans thème » ; « les thèmes se combinent en union » ; « un thème dépublié est élagué, signalé par themesPruned, et ne produit jamais un IN vide » ; « un film suspendu, retiré, bloqué ou non vérifié est hors du vivier » ; « la non-répétition retire les films démarrés dans la fenêtre du salon, manches annulées comprises » ; « une manche de réserve jamais démarrée ne compte pas comme jouée » ; « une manche programmée dont T₁ n'est pas atteint ne compte pas comme jouée » ; « le vivier est monotone décroissant en N » ; « les périmètres catalogue et solo ne portent aucune clause de salon » ; « aucune requête de vivier n'émet de GROUP BY » (capture `DB::listen`) ; « la liste des thèmes publiés par clé et la liste des ids décrivent le même ensemble » ; « une sélection de thèmes vide ne lit pas les thèmes publiés » (capture `DB::listen`) ; « les thèmes publiés ne sont lus qu'une fois par instance de PoolQuery et une dépublication vaut à l'instance suivante » ; « movies n'hydrate que les colonnes de movie ». `tests/Feature/Draw/PoolQueryMysqlTest.php` (groupe `mysql` au niveau du fichier, `pest()->group('mysql')` et `beforeEach(fn () => requireMysql())`, exclu de `composer test` et joué par le job CI `mysql-redis`) : « le compte en œuvres est identique sur MySQL » (vérifie aussi que `sql_mode` porte `ONLY_FULL_GROUP_BY` et rejoue la non-répétition à ± 1 ms — amendé le 25/09 (E35-6)). `tests/Feature/Draw/RoomMemoryWindowTest.php` : « la fenêtre est la plus récente des deux bornes : jours ou N-ième manche démarrée » ; « moins de N manches démarrées : seule la borne en jours s'applique ». `tests/Feature/Draw/DecoyScopeTest.php` : « forDecoys exclut la cible, son groupe et les manches démarrées, jamais les manches futures » ; « forGame reconstruit le vivier du salon depuis l'instantané figé ».
- **Estimation** : 5 à 7 h.

### L30-3 — Rapport de vivier [J1]

- **Dépendances** : L30-2 ; `RoomSettingsEditor::SIMPLE_KEYS` / `ADVANCED_KEYS` (contrat C0), pour le test des clés postables ; **L90-1**, script anti-couleur et périmètre `WATCHED` (contrat C16, R-36), où `resources/js/types/pool.ts` s'inscrit dès sa création — sans lui, la méta-vérification de `90` refuserait le fichier comme non classé. Le lot de `20` qui remplace `DashboardController::poolByFramesPerRound()` et `CatalogIndexRequest::PLAYABLE_AT_*` (n° 26) consomme `catalogueWorksByFramesPerRound()` livré ici.
- **Crée** : `app/Support/Draw/{PoolReporter, PoolReport, PoolRemedy, PoolTooSmallException}.php` ; `app/Enums/{PoolFault, PoolRemedyKind}.php` ; `resources/js/types/pool.ts` (inscrit au périmètre `WATCHED`) ; `tests/Feature/Draw/PoolReportTest.php`.
- **Tests** — `tests/Feature/Draw/PoolReportTest.php` : « un vivier bloqué par la seule non-répétition nomme noRepeatMovies et propose un nouveau salon » ; « un vivier bloqué par N nomme framesPerRound et propose le N jouable le plus proche » ; « le N jouable le plus proche est le plus grand N inférieur atteignant M, null sinon » ; « réduire M n'est proposé que si le vivier atteint le minimum de manches » ; « un vivier non bloqué n'a ni cause ni remède » ; « le rapport ne contient que des entiers, des booléens et des codes » ; « chaque cas de PoolFault est une clé postable de RoomSettingsEditor » ; « les unions de resources/js/types/pool.ts couvrent exactement les cas des deux enums » ; « un vivier bloqué par les seuls thèmes nomme themeKeys et propose de vider les thèmes » ; « les causes et les remèdes suivent l'ordre fixe et chaque remède débloque à lui seul » ; « un vivier bloqué qu'aucun réglage ne débloque rend des causes vides ». Le test de sélecteur du même fichier arrive avec L30-11. Assertions complémentaires, sans intitulé ajouté ni modifié — amendé le 25/09 (E36-1, E36-2, E36-3) : « les unions de resources/js/types/pool.ts… » vérifie aussi que les champs des types `PoolReport` et `PoolRemedy` sont exactement les clés de `toArray()` et qu'aucune union n'est élargie par autre chose que des littéraux ; « le rapport ne contient que des entiers… » porte sur un rapport à quatre causes, cinq remèdes et un thème élagué, et vérifie qu'aucune clé de thème ni aucun titre ne figure dans la charge ; « un vivier non bloqué n'a ni cause ni remède » compte un seul `countWorks` (`DB::listen`) et prouve le refus du constructeur de `PoolReport` ; « le N jouable le plus proche… » prouve le refus d'un périmètre sans `N` et le calcul tel quel de `M` = `MAX_ROUNDS_COUNT` + 1 et `MIN_ROUNDS_COUNT` − 1 (§ 4.2), et exerce `catalogueWorksByFramesPerRound()`, livré ici sans test propre : sa supervision est testée par `20` (`DashboardTest`).
- **Estimation** : 4 à 6 h.

### L30-4 — Graine et PRF [J1]

- **Dépendances** : aucune.
- **Crée** : `app/Support/Draw/{SeededPrf, DrawContext}.php` ; `tests/Feature/Draw/SeededPrfTest.php`.
- **Modifie** : `tests/Feature/Architecture/DrawBoundaryTest.php`.
- **Tests** — `tests/Feature/Draw/SeededPrfTest.php` : « les vecteurs de référence sont figés » (les huit vecteurs du § 5.4) ; « la réduction par rejet est sans biais » (60 000 graines déterministes `hash('sha256', 'seed'.$k)`, `permutation(movies(), 3)`, χ² < 20,52 à 5 degrés de liberté, p = 0,001) ; « une graine qui n'est pas 64 caractères hexadécimaux est refusée » ; « generateSeed produit 64 caractères hexadécimaux » ; « les sept contextes du registre produisent sept chaînes distinctes ». `tests/Feature/Architecture/DrawBoundaryTest.php` : « aucun chemin dépendant de la graine n'emploie shuffle, mt_rand, srand, random_int, array_rand ni crc32 » (arch `not->toUse` plus un balayage source couvrant **toute** la liste du § 5.5 : `->shuffle(`, `Arr::random`, `Arr::shuffle(`, `->random(`, `rand(`, `str_shuffle(`, en plus des fonctions nommées dans l'intitulé ; l'un et l'autre limités nommément à `App\Support\Draw` et `App\Support\Answers`, jamais à `App\Actions\Game`, où `MintTierServeToken` frappe légitimement son jeton par `random_bytes` (§ 5.1) ; dans ces deux espaces, `random_bytes` n'est admis que dans `SeededPrf::generateSeed()` ; balayage et arch étendus par le code livré, § 5.5, amendé le 28/09 (E70-3)) ; « seuls PoolReport et PoolRemedy sont sérialisables dans App\Support\Draw ».
- **Estimation** : 2 à 3 h.

### L30-5 — Tirage des films et des variantes [J1]

- **Dépendances** : L30-1, L30-2, L30-4 ; `drawSubstituteMargin()` (contrat C0, lot L50-1). L'appel dans la transaction de lancement appartient au lot de `50` (contrat C6) et la matérialisation au lot de `60` ; ce lot se prouve sans eux, sur `DrawResult`.
- **Crée** : `app/Support/Draw/{GameDrawer, DrawInput, DrawResult, DrawnRound, DrawnTier, VariantCandidate, VariantChooser}.php` (`choose` seulement) ; `tests/Feature/Draw/GameDrawTest.php`.
- **Modifie** : `database/seeders/DemoCatalogueSeeder.php` (retrait de `DRAW_MARGIN`, commentaire l.99 corrigé en « non vue par le salon → vue la moins récemment par le salon → graine ») ; `database/factories/GameFactory.php` (retrait de `DRAW_MARGIN`, lecture de `PlatformLimits`) ; `tests/Feature/Schema/DemoCatalogueChainTest.php` (réécrit : `demoPool` passe par `PoolQuery::movies()` sans masque 21, FAIT 2 lit `PlatformLimits::drawSubstituteMargin()`, commentaire l.675 corrigé).
- **Livré** — amendé le 28/09 (E71-4, E71-5) : `GameFactory` pose `draw_pool_size = M + PlatformLimits::drawSubstituteMargin()` et `draw_seed = SeededPrf::generateSeed()`, seule source de la graine (§ 5.1), au lieu de `bin2hex(random_bytes(32))` en ligne. FAIT 2 de `DemoCatalogueChainTest` perd ses deux littéraux de marge et devient « FAIT 2 — le vivier dépasse la marge de tirage : min(M + marge, œuvres) matérialise bien M + marge manches » : il compte en œuvres (`countWorks`, unité de `K`), lit la marge dans `PlatformLimits` **et rejoue le tirage réel** (`GameDrawer::draw()`, graine fixe) — `M + marge` manches, exactement `M` numérotées ; la lettre ne demandait que la lecture de la marge, le rejeu prouve que le catalogue de démonstration se tire, pas seulement qu'il se compte.
- **Tests** — `tests/Feature/Draw/GameDrawTest.php` : « le tirage rend min(M + marge, œuvres) films et au plus un par movie_group » ; « deux parties lancées à la même seconde sur le même vivier ne tirent pas la même séquence » ; « un rejeu à graine et entrées figées reproduit exactement films et variantes » ; « les manches de réserve n'ont pas de numéro » ; « tier_index suit les niveaux croissants de FrameLevelCoverage » ; « préférence de variante : non vue par le salon, puis vue la moins récemment, puis la graine » ; « une ligne seen_frame hors fenêtre est lue comme non vue » ; « le solo ignore la mémoire du salon et la non-répétition » ; « un vivier sous M lève PoolTooSmallException » ; « une projection périmée fait sauter l'œuvre sans bloquer le tirage » ; « draw_pool_size égale le compte du rapport de garde » (compare `DrawResult::$poolSize` au `count` du rapport sur le même `PoolScope` ; la colonne `game.draw_pool_size`, écrite par `OpenGame`, est prouvée par `50`, L50-7a). `tests/Feature/Schema/DemoCatalogueChainTest.php` : tous les FAITS existants restent verts.
- **Estimation** : 6 à 8 h.

### L30-6 — Substitution et remplacement [J1]

- **Dépendances** : L30-5 ; disque `frames` et préfixe `game/` (contrats C8 et C9). L'appel à la frappe et l'annulation appartiennent au lot de `60`.
- **Crée** : `VariantChooser::substitute()` ; `app/Support/Draw/ReplacementRoundChooser.php` ; `tests/Feature/Draw/SubstitutionTest.php`.
- **Modifie**, hors de la liste d'origine — amendé le 28/09 (E71-3, E71-6, E72-1, E72-4) : `app/Models/Frame.php` (portée `servable()`, seule écriture du prédicat de variante jouable, § 6.2) et ses lecteurs `app/Support/Catalog/MovieProjector.php` et `app/Support/Draw/GameDrawer.php`, dont les copies en SQL disparaissent ; `tests/Feature/Schema/DemoCatalogueChainTest.php` (FAIT 4 confronte la portée à `Frame::isServable()` frame par frame, sans copie du prédicat ; aucun FAIT modifié) ; docblocks de `app/Models/Game.php`, `app/Models/Round.php` et `database/factories/RoundFactory.php` (`1..min(M + PlatformLimits::drawSubstituteMargin(), W)` au lieu de la marge littérale `M + 3`) et de `app/Support/Draw/RoomMemoryWindow.php` (appelée aussi par `substitute()` à chaque substitution). `Frame::hasGameFile()`, lu par `substitute()` (§ 8.1), est livré par L60-5 (E90-2), qui y replie la vérification privée de ce lot.
- **Tests** — `tests/Feature/Draw/SubstitutionTest.php` : « une variante de substitution garde le même niveau et exclut la variante fautive » ; « sans variante de même niveau, la substitution rend null et jamais un autre niveau » ; « la substitution écarte une variante dont le fichier manque sur le disque » ; « le remplaçant est la première manche de réserve encore au catalogue » ; « une réserve épuisée rend null » ; « la substitution rend la même variante pour des entrées identiques » ; « en solo la substitution ne lit aucune mémoire et se départage par la graine ».
- **Estimation** : 3 à 4 h.

### L30-7 — Scission du seeder de plateforme [J1]

- **Dépendances** : aucune. Condition du hook de déploiement du J1 (contrat C18-bis, étape 6).
- **Modifie** : `database/seeders/PlatformDataSeeder.php` (presets réconciliés ; `theme`, `theme_label` et `collection` en insertion si absent ; `sort_order` par blocs de famille par `sortOrderFor()`, § 12.5 ; garde de libellés à l'insertion seulement). Aucune saga n'est livrée par ce lot : la liste du § 12.4 appartient à L30-9, et `PlatformDataSeeder::sagaThemes()` rend `[]` au J1. Le mécanisme d'insertion des sagas est pourtant posé dès ce lot : la définition d'un thème porte `collection: {tmdb_id, name}|null` et `published` ; à l'insertion d'une saga, la `collection` est prise ou créée par `tmdb_id` (`firstOrCreate`, jamais réécrite, `name` littéral), et le thème n'est pas inséré si un thème `saga` désigne déjà cette collection. Ce chemin n'est exercé que par les tests de L30-9 ; le dispatch de `SyncThemeMembership` reste à L30-9. — amendé le 25/09 (E19-1)
- **Crée** : `database/migrations/2026_09_24_300001_realign_platform_theme_sort_order.php` (horodatage hors de la série de schéma de `10` § 13.2, E19-3 ; migration de données idempotente : `sort_order = PlatformDataSeeder::sortOrderFor(key)` pour chaque clé livrée dont `sort_order < 100`, § 12.5) ; `tests/Feature/Draw/PlatformDataSeederTest.php`.
- **Tests** — `tests/Feature/Draw/PlatformDataSeederTest.php` : « rejouer le seeder de plateforme ne réécrit ni un thème, ni un libellé, ni une saga édités en back-office » (au J1 sur un thème et un libellé ; l'assertion sur une saga livrée s'ajoute avec L30-9) ; « rejouer le seeder de plateforme réconcilie les quatre presets » ; « un thème livré absent est inséré au passage suivant du seeder » ; « un thème livré naît avec son libellé dans chaque locale activée » ; « un thème livré naît dans le bloc de sa famille » ; « un thème livré inséré après coup se range dans le bloc de sa famille, y compris sur une base amorcée par l'ancien seeder » (base amorcée par des `sort_order` séquentiels, migration jouée, puis insertion). Précisions du code livré — amendé le 25/09 (E19-2, E19-4) : le dernier test `require` le fichier trouvé par `glob('*_realign_platform_theme_sort_order.php')` et appelle `up()` deux fois (idempotence prouvée), dans la transaction du test ; c'est une exception assumée à `10` § 13.1 (« aucun test n'appelle jamais de migration »), dont la règle vise l'ordre des migrations de **schéma** sous `RefreshDatabase`, alors que celle-ci est une migration de **données** sans colonne, et l'intitulé de cette spec, propriétaire du lot, l'exige. « un thème livré absent est inséré au passage suivant du seeder » couvre aussi un libellé absent sur un thème **présent** : le libellé renaît, le libellé de l'autre locale et la ligne `theme` restent identiques, et `theme_label` compte exactement une ligne par thème livré et par locale.
- **Déploiement** : la migration de réalignement passe par l'étape 5 du hook, après l'instantané de l'étape 4 (contrat C18-bis) ; aucune commande manuelle.
- **Estimation** : 3 à 4 h.

### L30-8 — Évaluateur d'appartenance [J1 depuis D43 du 01/10]

- **Dépendances** : L30-7 ; **L100-6**, commande `backup:snapshot` (contrat C18-bis, livrée), appelée en tête de `catalog:themes` (§ 13.2, règle 12). Les écrans d'exceptions manuelles et de publication de `20` (L20-28, J1 depuis D43 du 01/10) consomment ce lot par les noms du § 13.1 — amendé le 01/10.
- **Crée** : `app/Support/Catalog/ThemeEvaluator.php` ; `app/Jobs/Catalog/SyncThemeMembership.php` (`ShouldBeUniqueUntilProcessing`, § 13.1) ; `app/Console/Commands/CatalogThemesCommand.php` (`catalog:themes`, instantané en tête) ; `tests/Feature/Draw/ThemeEvaluatorTest.php`. Amendé le 01/10 (D43 du 01/10) : la migration `create_tmdb_company_table`, `app/Models/TmdbCompany.php` et sa fabrique (`10` § 3.6 bis) ; `app/Console/Commands/CatalogCompanyNamesCommand.php` (`catalog:company-names {--dry-run} {--limit=}` : lit les sociétés des étiquettes `movie_tmdb_tag` sans ligne `tmdb_company`, appelle `TmdbClient::company()`, n'écrit que `tmdb_company`, sous `TmdbQuotaLimiter`, code non nul sans clé TMDB ; hors règle 12) ; `tests/Feature/Catalog/{CatalogThemesCommandTest, SyncThemeMembershipTest, MovieImporterThemesTest, CatalogCompanyNamesCommandTest}.php`.
- **Modifie** : `app/Support/Catalog/MovieImporter.php` (évaluation synchrone à l'import et à la resynchronisation ; amendé le 01/10 : écriture de `tmdb_company` depuis `production_companies[].name` de l'appel de détail) ; `app/Support/Tmdb/TmdbMovie.php` (noms des sociétés, amendé le 01/10) ; `app/Support/Tmdb/TmdbClient.php` (`company()`, amendé le 01/10) ; `database/seeders/DemoCatalogueSeeder.php` (`linkThemes()` et `ruleMatches()` remplacés par l'évaluateur) ; tests de la purge et de `loadtest:forget` (`tmdb_company` au périmètre interdit, `10` § 11.2 ; leur code, liste blanche des tables effacées, n'a rien à changer — amendé le 01/10).
- **Tests ajoutés le 01/10** (D43 du 01/10) : « un thème studio contient les films de l'une quelconque de ses sociétés » ; « une écriture de l'évaluateur relit l'exception sous verrou et ne réactive jamais un film retiré » ; « une correction de règle pendant une synchronisation n'est jamais perdue » (job unique jusqu'au traitement) ; « catalog:themes n'écrit aucune ligne movie_theme si l'instantané échoue » ; « l'import écrit le nom de chaque société de production » ; « catalog:company-names ne rappelle jamais TMDB pour une société déjà nommée et échoue sans clé plutôt que de la réclamer » (avec `Http::fake`) ; et, au groupe `locks-timing`, « un geste de curation pendant une synchronisation de thème garde son exception ». Forme livrée (amendé le 01/10) : l'intitulé de `catalog:company-names` est scindé dans `CatalogCompanyNamesCommandTest` en « la commande ne rappelle jamais TMDB pour une société déjà nommée » et « la commande échoue sans clé TMDB plutôt que de la réclamer » ; « l'import écrit le nom de chaque société de production » vit dans `MovieImporterTest` ; « catalog:themes est idempotente » et « catalog:themes n'écrit aucune ligne movie_theme si l'instantané échoue » vivent dans `CatalogThemesCommandTest`, pas dans `ThemeEvaluatorTest` ; « une correction de règle pendant une synchronisation n'est jamais perdue » dans `SyncThemeMembershipTest`.
- **Tests** — `tests/Feature/Draw/ThemeEvaluatorTest.php` : « un thème de genre ou de studio s'évalue sur movie_tmdb_tag sans appel réseau » ; « un thème de décennie couvre les dix années qui commencent à rule_value » ; « un thème de langue compare original_language à l'identique » ; « un thème de saga compare collection_id à rule_value » ; « un thème de difficulté suit la difficulté effective » ; « rule_negated inverse la règle, jamais sur une entrée nulle » ; « un thème sans rule_value ne contient que ses ajouts manuels, même nié » ; « une exception manuelle prime sur la règle par resolveIsActive » ; « une ligne automatique qui cesse de matcher est supprimée, une exception removed est conservée » ; « une resynchronisation réécrit is_auto sans toucher manual_state » ; « un thème non publié est évalué et sa publication ne demande aucun recalcul » ; « l'évaluation par film et l'évaluation par thème produisent les mêmes lignes » ; « catalog:themes est idempotente » ; « catalog:themes n'écrit rien si l'instantané préalable échoue » ; « le seeder de démonstration délègue à l'évaluateur unique ».
- **Déploiement** : `catalog:themes` (instantané compris, pris par la commande elle-même) joué **à la main par le porteur**, une fois, après le déploiement de ce lot (§ 13.2) ; jamais dans le hook. Amendé le 01/10 (D43 du 01/10) : `backup:snapshot` manuel d'abord (code 0 exigé), puis `catalog:themes`, puis `catalog:company-names` avec la clé TMDB de production, jamais en CI.
- **Estimation** : 7 à 10 h (amendé le 01/10, D43 du 01/10 : `tmdb_company` et `catalog:company-names` compris ; 6 à 8 h avant).

### L30-9 — Thèmes livrés et sagas par défaut [J1 depuis D43 du 01/10]

- **Amendé le 01/10 (D43 du 01/10)** : le lot livre aussi `studio.dc` (`429,128064,184898`, règle multi-valeurs du § 12.1, `sort_order` 205 ; Marvel 204), `saga.avatar` et `saga.iron-man` (611, 612), les noms des sociétés désignées dans `tmdb_company` (§ 12.3), la garde « société déjà désignée » symétrique de celle des sagas (§ 12.5) et une fabrique `ThemeFactory::studio()` qui accepte une liste ; le catalogue de démonstration gagne un film de la société 420, un de la société 128064, trois films de 1940 à 1969 et un film rattaché à la collection Toy Story **livrée** (`collection.tmdb_id` 10194, jamais une collection de démonstration sans `tmdb_id`), `DEMO_MOVIE_COUNT` relevé en conséquence. Tests ajoutés : « DC est livré sur ses trois sociétés et naît non publié » ; « les douze sagas sont livrées avec leur collection et leurs libellés dans chaque locale » ; « une société déjà désignée par un thème studio n'est pas désignée une seconde fois » ; « chaque thème inséré dispatche sa synchronisation et le rejeu n'en dispatche aucune » (`PlatformDataSeederTest` ; l'après-commit est porté par `ShouldQueueAfterCommit` et prouvé par `SyncThemeMembershipTest`, amendé le 01/10). **Déploiement** : `backup:snapshot` manuel par le porteur **avant** le déploiement de ce lot, le hook dispatchant une synchronisation par thème inséré (§ 13.2 ; `REPRISE.md`).
- **Dépendances** : L30-7, L30-8 ; ~~vérification préalable des onze identifiants TMDB et des dix noms de collection (§ 12.3, § 12.4) par le porteur, avec sa clé, hors CI~~ — faite le 01/10 (§ 12.3, § 12.4).
- **Modifie** : `database/seeders/PlatformDataSeeder.php` (décennies 1940-1960, `studio.marvel`, `language.anime`, dix sagas (douze, `studio.dc` et les noms de `tmdb_company` compris, depuis D43 du 01/10 — amendé le 01/10) et leurs `collection` avec `name` littéral, **tout thème ajouté né non publié** (§ 12.3), saga non insérée si sa collection est déjà désignée par un thème de saga (§ 12.5) ; dispatch après commit de `SyncThemeMembership` pour chaque thème inséré (§ 13.2) ; aucun appel TMDB ; docblock des décennies corrigé, et docblock de classe (l.37-41, « aucun thème de `theme_kind = saga` … jamais depuis un fichier du dépôt ») réécrit : les sagas de S4 du 23/09 sont livrées non publiées, avec leur `collection`) ; `database/seeders/DemoCatalogueSeeder.php` (trois films de démonstration sortis entre 1940 et 1969, une étiquette de société Marvel sur un film existant (amendé le 01/10, D43 du 01/10 : cinq films ajoutés — trois de 1940 à 1969, Iron Man sur la société 420 et la collection livrée 131292, Wonder Woman sur 128064 —, des films réels plutôt qu'une étiquette fausse sur un film existant, `DEMO_MOVIE_COUNT` à 21 ; les films Star Wars et Toy Story rattachés aux collections livrées), `DEMO_MOVIE_COUNT` relevé en conséquence, pour que ces thèmes, publiés en développement, ne soient pas vides) ; `tests/Feature/Draw/PlatformDataSeederTest.php`.
- **Crée** : `tests/Feature/Draw/PlatformThemesTest.php`.
- **Tests** — `tests/Feature/Draw/PlatformThemesTest.php` : « les décennies livrées vont de 1930 à 2020 sans trou » ; « Pixar, Ghibli et Marvel sont des thèmes studio, jamais des sagas » ; « le thème des animés est un thème de langue sur ja » ; « chaque thème ajouté au J2 naît non publié et les thèmes du J1 restent publiés » ; « chaque saga par défaut désigne sa collection par tmdb_id et lui donne le nom littéral du seeder » ; « chaque thème livré porte un libellé dans chaque locale activée ». Forme livrée (amendé le 01/10, D43 du 01/10) : « chaque thème ajouté par L30-9 naît non publié et les thèmes déjà livrés restent publiés », « les douze sagas sont livrées avec leur collection et leurs libellés dans chaque locale » (qui couvre l'intitulé des sagas par défaut), « chaque thème livré porte un libellé non vide dans chaque locale activée », plus « Marvel est livré sur la société 420 et naît non publié », « DC est livré sur ses trois sociétés et naît non publié » et « studio.disney reste livré sur la seule société 2 : sa correction est un geste du back-office ». `PlatformDataSeederTest` : l'assertion « ni une saga » porte désormais sur une saga livrée ; « le seeder de plateforme n'émet aucune requête HTTP » (`Http::preventStrayRequests()`) ; « un thème livré inséré par le seeder reçoit ses appartenances sans geste manuel » ; « une collection déjà créée par l'importeur n'est pas réécrite » ; « n'insère pas une saga livrée dont la collection est déjà désignée par une saga créée en back-office ». `DemoCatalogueChainTest` › FAIT 8 reste vert (chaque thème publié non-saga a un film actif). Reste de L30-7 — amendé le 25/09 (E19-1, E19-4) : ce lot remplit `sagaThemes()`, qui rend `[]` au J1, sur le mécanisme d'insertion déjà posé par L30-7 ; et si ses définitions de thèmes deviennent injectables (elles sont `private static` au J1 et fournissent toutes les deux locales), il prouve la moitié non couverte de la garde de libellés : elle lève à l'insertion, et **seulement** à l'insertion (rappeler `assertEveryLocaleLabel()` sur un thème présent ne casse aujourd'hui aucun test).
- **Déploiement** : ~~aucune commande manuelle~~ — amendé le 01/10 (D43 du 01/10) : `backup:snapshot` manuel avant le déploiement (ci-dessus, `REPRISE.md` § 3, geste 12) ; l'amorçage par le hook (étape 6) déclenche l'évaluation des thèmes insérés.
- **Estimation** : 5 à 6 h (amendé le 01/10, D43 du 01/10 : DC, deux sagas, sociétés nommées ; 4 à 5 h avant).

### L30-10 — Difficulté dérivée [J2]

- **Dépendances** : L30-8. Le geste de correction manuelle de `20` consomme ce lot (`syncMovie($movie, ThemeKind::Difficulty)`, § 13.1) ; les gestes de saga et de retrait de `20` y ajoutent le dispatch de `DeriveMovieDifficulty` (§ 14.2).
- **Crée** : `app/Support/Catalog/MovieDifficultyDeriver.php` ; `app/Jobs/Catalog/DeriveMovieDifficulty.php` ; `tests/Feature/Draw/DifficultyDerivationTest.php`.
- **Modifie** : `config/catalog.php` (section `difficulty`, deux clés bornées) ; `app/Jobs/Catalog/RunCatalogImport.php` (dispatch en fin de balayage) ; `app/Console/Commands/CatalogThemesCommand.php` (dérivation **après** l'instantané, avant l'évaluation) ; `database/seeders/PlatformDataSeeder.php` (dispatch de `DeriveMovieDifficulty` après l'insertion d'un thème de saga, § 13.2).
- **Tests** — `tests/Feature/Draw/DifficultyDerivationTest.php` : « un film est classé par décile de notoriété dans sa propre langue originale » ; « deux films à vote_count égal reçoivent la même classe » ; « une langue sous l'échantillon minimal rejoint le seau commun » ; « l'appartenance à une saga retenue rapproche du plus facile sans dépasser very_easy » ; « l'override prime et survit à une nouvelle dérivation » ; « un film de démonstration n'est ni compté ni recalculé » ; « un film retiré n'entre pas dans la distribution » ; « un changement de difficulté effective met à jour les thèmes de difficulté dans la même transaction » ; « deux dérivations successives sur les mêmes données donnent le même résultat » ; « la dérivation refuse une configuration de difficulté hors bornes » ; « une correction manuelle concurrente de la dérivation n'est jamais écrasée » ; « catalog:themes prend un instantané vérifié avant d'écrire dans movie et n'écrit rien sans lui » ; « l'insertion d'une saga par le seeder relance la dérivation ».
- **Déploiement** : `catalog:themes` (instantané compris) rejoué **à la main par le porteur**, une fois, après le déploiement de ce lot (§ 13.2) : sans ce passage, les thèmes de difficulté restent vides pour les films réels déjà importés jusqu'au prochain `import_run`.
- **Estimation** : 5 à 7 h.

### L30-11a — Mesure d'un thème [J1, scindé de L30-11 par D43 du 01/10]

- **Dépendances** : L30-3 ; L30-8, dont l'évaluateur écrit les appartenances des thèmes non publiés que la mesure lit. L'affichage de la mesure et son application au geste de publication appartiennent à `20` (L20-28).
- **Crée** : `PoolReporter::themeWorks()`.
- **Modifie** : `app/Support/Draw/PoolScope.php` (`themeProbe()` et paramètre final `themesUnpublishedIncluded`, § 3.2) ; `app/Support/Draw/PoolQuery.php` (exception de la clause 3, § 3.3) ; docblock de `PoolReporter` (`themeSelectorVisible()` reste J2).
- **Tests** — `tests/Feature/Draw/PoolQueryTest.php` : « la mesure d'un thème non publié ne compte que ses films » ; « aucun autre périmètre que la mesure d'un thème ne compte un thème non publié » (intitulé livré, amendé le 01/10).
- **Estimation** : 1 à 2 h — amendé le 01/10 (D43 du 01/10).

### L30-11 — Sélecteur et thèmes proposables [J2]

- **Dépendances** : L30-3, L30-9, **L30-11a** (amendé le 01/10) ; `themeSelectorMinPool()` (contrat C0, lot L50-1). Le rendu du sélecteur appartient à `50` et `90`.
- **Crée** : `PoolReporter::themeSelectorVisible()` ; `app/Support/Draw/ProposableThemes.php` ; `app/ValueObjects/Catalog/ProposableTheme.php` ; `tests/Feature/Draw/ProposableThemesTest.php`. ~~`PoolReporter::themeWorks()`~~, ~~`PoolScope::themeProbe()`~~ : sortis vers L30-11a (D43 du 01/10).
- **Tests** — `tests/Feature/Draw/PoolReportTest.php` : « le sélecteur de thèmes n'est visible qu'au-dessus du seuil configuré ». `tests/Feature/Draw/ProposableThemesTest.php` : « la liste proposable ne contient que des thèmes publiés, par clé, sans identifiant interne » ; « la liste est ordonnée par sort_order puis key » ; « chaque thème proposable porte un libellé par locale activée » ; « le sélecteur réapparaît au franchissement du seuil sans déploiement ».
- **Estimation** : 2 à 3 h (amendé le 01/10 ; 3 à 4 h avant la scission).

**Total jalon 1 : 25 à 35 h** (L30-1 à L30-7). **Total jalon 2 : 18 à 24 h** (L30-8 à L30-11). Amendé le 01/10 (D43 du 01/10) : **total jalon 1 : 38 à 53 h** (L30-1 à L30-9 et L30-11a) ; **total jalon 2 : 7 à 10 h** (L30-10, L30-11). Ces heures sont des **mesures de taille**, jamais un calendrier ni un budget à tenir : le développement est confié à l'IA, et l'enveloppe d'environ 10 h par semaine du porteur ne le borne plus (D36 du 23/09). `00` § Jalons ne comptait aucun poste pour `30` ; la consolidation de la taille du J1 appartient à `00`, à partir des sections « Lots » de toutes les specs. — amendé le 23/09

---

## Ce que cette spec ne décide pas

| Sujet | Spec propriétaire |
|---|---|
| Tables, colonnes, index, énumérations de colonnes, rétention et purge de `seen_frame` ; le texte de `10` § 3.3 sur les consommations de `collection` (E10-N1) et celui de `10` § 12 sur la lecture et le déclenchement des déciles (E10-N2) | `10-catalogue-et-modele-de-donnees.md` |
| Écrans des thèmes (libellés, publication, ordre, ajout d'une saga ; création de toute nature, thèmes manuels, saga depuis la fiche film et thèmes choisis au collage, D43 du 01/10), affichage de la mesure `themeWorks()` et forme de son application au geste de publication d'un thème (refus ou avertissement ; mesure et seuil déclarés au § 12.3), exceptions manuelles d'appartenance, correction manuelle de la difficulté, supervision « œuvres jouables à N » et son libellé, signal « film incomplet », groupement `movie_group`, garde de publication 1-3-5 ; dispatch de `SyncThemeMembership` et `DeriveMovieDifficulty` par ses gestes sur les thèmes et par la transition vers ou depuis `withdrawn` (§ 13.1, § 14.2) ; la traçabilité éventuelle des gestes sur les thèmes, que la liste fermée de `admin_action` (contrat C14) ne couvre pas — amendé le 01/10 (D43 du 01/10) : cinq cas inscrits à `10` § 8.3 | `20-back-office-curation.md` |
| Déclenchement du calcul de vivier, anti-rebond, diffusion de `RoomSettingsState`, texte des causes et remèdes, retrait de `disable_no_repeat` au J1, grisage des presets à l'écran, validation de `themeKeys`, transaction de lancement (ordre O0-O9 de C6, qui fait foi sur l'ordre de C3 § 3 — écart signalé au porteur, § 6.1) et réponse faite à l'hôte sur `PoolTooSmallException` ; borne haute de `drawSubstituteMargin()` (`MAX_DRAW_SUBSTITUTE_MARGIN`, `50` § 2.3, et `MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE`, garde-fou croisé de `50` § 2.7 — amendé le 25/09 (E10-2)) | `50-salon-reglages-presets-et-lobby.md` |
| Matérialisation (`MaterializeDraw`), écriture de `seen_frame`, frappe du `serve_token`, détection d'une image non servable, annulation et numérotation d'une manche remplacée, retrait actif en partie (J2), réglages et gestes du solo (D18 et D19 du 23/09), résidu du dictionnaire visuel (D16 du 23/09), canaux et événements | `60-moteur-de-partie-temps-reel-et-mode-solo.md` |
| Règle des leurres (profil de titre, rangs, mode dégradé, cas terminal), composition et permutation du QCM | `70-validation-des-reponses.md` |
| Chaîne de départage des scores (jamais la graine), sémantique de `round_number` au podium | `80-scoring-podium-et-fin-de-partie.md` |
| Rendu du compteur de vivier, du message de blocage et du sélecteur de thèmes ; périmètre `WATCHED` | `90-ecrans-etats-et-structure.md` |
| Canal de journal `game`, place du groupe `mysql` en CI, commande `catalog:reproject` dans le hook de déploiement ; destination, chiffrement et vérification de l'instantané `backup:snapshot` ; procédure manuelle `catalog:themes` (instantané compris) après le déploiement de L30-8 et de L30-10 ; niveau d'isolation du MySQL de production, à confirmer au relevé du VPS (§ 6.5) | `100-qualite-tests-et-ci.md` |
| ~~**Ouvert — porteur** : vérification des dix identifiants et des dix noms de collection TMDB (§ 12.4) et de Marvel Studios (420, § 12.3), avec sa clé, hors CI~~ — **fermé le 01/10** (D43 du 01/10) : douze collections, Marvel, DC et les sociétés Disney vérifiées (§ 12.3, § 12.4) | — |
| **Geste du porteur** (D43 du 01/10) : corriger en back-office la règle de `studio.disney` vers `2,6125,171656` après la livraison de L20-28 (§ 12.3) | porteur |
| **Ouvert — porteur** : révision du seuil de 150 œuvres et de l'échantillon minimal de langue après le lot pilote | porteur (configuration) |
| **Ouvert — porteur (E36-1)** : remède `reduce_rounds_count` d'un salon dont le `M` périmé dépasse une `MAX_ROUNDS_COUNT` resserrée, pour un vivier dans `]MAX, M[` : `value = count > MAX`, valeur non postable, jusqu'à la normalisation L5 du lancement (§ 4.2) ; plafonner `value` ou garder la lecture littérale du § 4.3 (ajouté le 25/09) | porteur |
| **Ouvert — porteur (E36-1)** : la garde de `N` du constructeur de `PoolScope` lève hors des bornes (§ 3.2, E35-4) ; un resserrement des bornes de `N` ferait donc échouer `RoomSettingsPresenter::state()` sur un salon à version de réglages périmée, comme l'aurait fait une garde de `M` ; tolérer un `N` périmé au lobby ou garder la garde (ajouté le 25/09) | porteur |
| **Signalé au porteur** : tension entre la règle 3 et D21 du 23/09 quand `W ≤ M + marge` — les leurres y sont des réponses de manches futures (§ 11) ; écart entre C3 § 3 et C6 § 3 sur l'écriture de `game` (§ 6.1) ; extension J2 du contrat C2 par `PoolScope::themeProbe()` et `PoolReporter::themeWorks()` (§ 3.2, § 12.3), que `20` § 9.6 ne consomme pas encore (amendé le 01/10 : livrée au J1 par L30-11a, consommée par `20` § 9.6, D43 du 01/10) ; E10-N1, E10-N2, A-N1 à A-N5 | porteur |
| **Fermé** par D21 du 23/09 (contrat C11 § 4, V-08, R-17) : aucun rang de leurres ne lève la non-répétition ; en ajouter un exigerait une nouvelle décision du porteur | — |
