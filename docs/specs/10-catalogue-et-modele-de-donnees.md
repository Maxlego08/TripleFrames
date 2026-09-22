# Catalogue et modèle de données

Ce document est le **propriétaire unique du schéma** de TripleFrames. Il décide quelles tables existent, quelles colonnes elles portent, de quel type, avec quelle nullabilité, quel défaut, quelles clés, quels index et quel comportement `ON DELETE`. Il décide aussi ce qui n'existe pas : une table de trop est supprimée ici et justifiée ici, une table manquante est ajoutée ici et justifiée ici.

> **Règle d'autorité, sans exception : si une colonne n'est pas dans ce document, elle n'existe pas.** Les dix specs qui suivent décrivent des comportements et **ne redéfinissent aucune table**. Quand l'une d'elles a besoin d'une donnée, elle formule une exigence et c'est ici qu'elle est arbitrée — c'est déjà ce que fait `05-i18n-et-langues.md`, qui ne possède aucune table.

Ce document ne possède **pas** : la formule du bonus de rapidité ni la chaîne de départage (`80`), l'algorithme de normalisation des réponses ni les seuils de tolérance (`70`), l'algorithme de tirage (`30`), le contrat d'événements temps réel (`60`), les écrans du back-office (`20`), la règle de langue (`05`). Il possède le **stockage** de leurs résultats et les **garanties de portabilité** qui vont avec. Convention de renvoi, valable partout : « règle N » désigne `CLAUDE.md` §7, « principe N » désigne `00-overview.md` § Principes directeurs, « décision N » désigne `questions-ouvertes.md`.

> **État réel du dépôt au moment d'écrire, vérifié.** `database/migrations/` contient **cinq** fichiers : `create_users_table` (users + password_reset_tokens + sessions), `create_cache_table`, `create_jobs_table`, `create_passkeys_table`, `add_two_factor_columns_to_users_table`. `app/Models/` contient **un seul** modèle, `User.php` — qui importe `Illuminate\Support\Carbon` et annote `@property Carbon|null` alors que `Date::use(CarbonImmutable::class)` est actif : **l'annotation est fausse au runtime**, et c'est le patron que trente-quatre modèles vont copier. `users` a `email` **NOT NULL** unique et `password` **NOT NULL**. `phpunit.xml` force sqlite `:memory:` et `tests/Pest.php` a `->use(RefreshDatabase::class)` **commenté** : 31 tests en erreur « no such table: users ». Tout ce qui suit est à construire ; le lot 0 du § 13.2 est à purger d'abord.

Le schéma compte **35 tables de domaine nouvelles** plus `users`, altérée par deux migrations. Il ne compte **ni** `avatars`, **ni** `room_settings` — les deux seules tables de la liste de départ de `00-overview.md` à être supprimées, justifications en **§ 14** (A14 et A15) — **ni** aucune table `plan`, que le hors-périmètre v1 interdit nommément (décision 2) et que remplace la colonne `users.plan`.

---

## 1. Conventions de schéma

### 1.1 Clés primaires — bigint partout, ULID nulle part en clé

**Toutes les tables prennent `$table->id()` (bigint unsigned auto-increment)**, à **une seule exception nommée** : `movie_projection`, dont la clé primaire est `movie_id` parce qu'elle est en 1:1 stricte avec `movie` et qu'une clé de substitution y ajouterait un index sans aucun usage (§ 3.2). Partout ailleurs, aucune exception, `users` comprise : elle l'est déjà, `passkeys.user_id` et `sessions.user_id` la référencent, et `resources/js/types/auth.ts` déclare `id: number`.

L'ULID est réservé à **deux usages, et aucun autre** : (1) les **noms de fichiers** hors images de jeu — `users.avatar_provider_path`, `data_export.path` —, parce qu'un chemin de fichier ne doit jamais être devinable ; (2) un **jeton opaque applicatif**, `player.active_seat_token` (§ 7.1), qui doit être non devinable et ne doit surtout pas être un identifiant de session Laravel. Nulle part ailleurs, et jamais en clé primaire.

**Les deux chemins de `frame` font exception en sens inverse** : `game_path` et `master_path` sont nommés par `bin2hex(random_bytes(16))` et **non** par un ULID, parce qu'un ULID est **trié dans le temps** — ses dix premiers caractères encodent l'instant de création et regrouperaient visiblement les images curées dans la même séance, c'est-à-dire celles d'un même film (§ 10, arbitrage A8).

Trois raisons de refuser l'ULID en clé primaire :

- **Coût d'index.** `Blueprint::ulid()` est un alias de `char($col, 26)`, soit **104 octets par entrée d'index utf8mb4 contre 8** pour un bigint. Sur `seen_frame`, `round_tier` et `guess` — les trois tables volumineuses — chaque index secondaire quadruplerait.
- **Coût du mélange.** Une clé étrangère vers une table ULID se déclare `foreignUlid()` (`char(26)`), pas `foreignId()` (`bigInteger`) ; l'erreur ne se voit qu'au `migrate`, et pas du tout en SQLite (typage dynamique). `HasUlids` génère en **minuscules** : une comparaison d'ULID est sensible à la casse en SQLite (BINARY) et insensible en MySQL (`utf8mb4_unicode_ci`).
- **L'argument « identifiant séquentiel exposé » ne tient pour aucune table**, parce que **aucun identifiant interne ne quitte le serveur**. Le client adresse un salon par son code, une manche par le canal du salon, et rien d'autre. Les identités publiques sont des clés naturelles opaques dédiées : `room.room_code` (`char(6)`, alphabet non ambigu), `takedown_request.reference` (`char(12)` aléatoire base32, **jamais dérivée de l'id** — un numéro séquentiel révélerait le nombre de demandes reçues), `player.public_id` (`char(12)` aléatoire base32, **jamais dérivé de l'id** non plus — un `player.id` auto-incrémenté divulguerait le volume de sièges créés sur l'instance et resterait corrélable d'une partie à l'autre, § 7.1), `round_tier.serve_token` (`char(32)`, seul identifiant d'image qui quitte le serveur, § 7.4), et le `player_token` signé dont seul le hash SHA-256 est en base.

### 1.2 Horodatages, `CarbonImmutable` et précision sub-seconde

`Date::use(CarbonImmutable::class)` est actif : **tout `@property` temporel s'annote `CarbonImmutable|null`, jamais `Carbon`.** Corriger `User.php` fait partie du lot 0.

**Deux familles de colonnes temporelles, jamais confondues.**

| Famille | Type | Tables porteuses | Règle |
|---|---|---|---|
| Instants de jeu | `timestamp(3)` | `round`, `guess`, `game`, `player`, `round_player`, `round_tier`, `round_choice_set` | Le modèle déclare `$dateFormat = 'Y-m-d H:i:s.v'` **et** la table déclare `$table->timestamps(3)` : un modèle qui porte un `$dateFormat` sub-seconde n'a **aucune** colonne datée de précision 0. |
| Tout le reste | `timestamp` | toutes les autres | Format par défaut. |

Deux pièges mesurés, et leur garde :

1. `Grammar::getDateFormat()` renvoie `'Y-m-d H:i:s'` pour **MySQL comme pour SQLite**, et c'est ce format qu'Eloquent applique à l'écriture. Une colonne `timestamp(3)` recevrait des millisecondes à zéro, silencieusement, des deux côtés, sans qu'aucun test ne le signale. `SQLiteGrammar::typeTimestamp()` ignore en outre la précision demandée. D'où `$dateFormat` au niveau modèle — et, parce que `$dateFormat` s'applique à **toutes** les colonnes datées du modèle, `timestamps(3)` sur la même table : sans cela, MySQL arrondit `created_at` à la seconde **supérieure** (mesuré) là où SQLite ne l'arrondit pas, et le journal d'une manche montre une ligne créée une seconde après sa réception.
2. `$dateFormat` ne s'applique **qu'aux écritures passant par Eloquent**. Règle à tenir : **aucun `DB::table()->insert/update/upsert` ne touche jamais une colonne sub-seconde.** Le battement de présence d'un salon s'écrit naturellement en `DB::table('player')->whereIn(...)->update(['last_seen_at' => now()])` et perd ses millisecondes sans erreur. Un test écrit puis relit `player.last_seen_at` et vérifie que la partie milliseconde est non nulle. La **lecture** est sûre : `asDateTime()` rattrape l'échec de `createFromFormat` par `Date::parse()`.

**Le calcul ne dépend jamais d'un horodatage.** Le palier retenu se décide sur des **entiers en millisecondes** : `round_tier.starts_at_offset_ms`, `round_tier.duration_ms`, `guess.answered_at_ms`. L'arithmétique entière est identique dans les deux moteurs et immune à tout fuseau. Les `timestamp(3)` sont la trace de record, jamais l'entrée du calcul.

**UTC partout** (`config('app.timezone') = 'UTC'`). Le type `timestamp` de MySQL est converti selon le fuseau de session — vérifié : `SET time_zone='+05:00'` décale la valeur de 5 h, un `datetime` ne bouge pas. Tant que `system_time_zone`, `time_zone` et `date.timezone` restent alignés sur UTC, `timestamp` est sans danger. **C'est une hypothèse de déploiement, pas une propriété acquise du schéma** : elle est vérifiée à la mise en service du VPS et inscrite au § 13.4. `explicit_defaults_for_timestamp = ON` : aucun défaut implicite, un défaut d'horodatage se déclare.

### 1.3 Nommage

**Tables de domaine au singulier**, alignées sur le lexique normatif de `00-overview.md`, déclarées par `#[Table('room')]` sur chaque modèle. Les tables du framework (`users`, `sessions`, `password_reset_tokens`, `passkeys`, `jobs`, `cache`) gardent leur nom : renommer `users` casserait `config/auth.php`, le broker de réinitialisation et la FK de `passkeys` pour un gain nul.

Colonnes, événements, routes et **noms d'index** en anglais. Une variante hors lexique est une erreur.

**Toute clé étrangère porte le suffixe `_id`, sans exception**, y compris quand le nom se lit déjà comme un participe (`uploaded_by_id`, `decided_by_id`).

**Quatre noms qui ne partagent jamais une colonne** : `input_difficulty` (salon), `movie_difficulty` (film), `frame_level` (image, 1-5), `frames_per_round` (`N`). `movie_difficulty` est un enum à **cas nommés** (`very_easy`…`very_hard`) et non un entier 1-5, précisément pour qu'aucune relecture ne le confonde avec `frame_level`.

**`avatar_preset` est un seul nom et une seule longueur** (`string(40)`) sur `users`, `player` et `game_player` (où elle se préfixe `display_`, le préfixe marquant le gel et non un autre domaine de valeurs). Aucune variante suffixée `_key` n'existe. Les trois colonnes sont lues par le **même** accesseur et comparées entre elles au gel du pseudo : une clé de 36 caractères stockable sur un siège et refusée en 1406 sur un compte serait exactement l'incohérence que le propriétaire du schéma existe pour empêcher. C'est le piège inverse de celui des quatre noms ci-dessus — **deux noms pour un seul domaine**, et il est tout aussi coûteux.

**Deux notions de « grâce », jamais le même mot seul.** `disconnect_grace_seconds` est un **réglage d'hôte** (15-180 s, § 6.1 et § 7.1) : le délai avant qu'un joueur déconnecté cesse de compter. `tier_grace_ms` est une **constante serveur** (±300 ms, § 7.2 et § 7.5) : la fenêtre de tolérance aux frontières de palier. Quatre ordres de grandeur les séparent ; le mot « grâce » ne s'emploie jamais seul, ni ici, ni dans `00-overview.md`, ni dans aucune spec aval. Un développeur qui lirait le réglage d'hôte dans le calcul du palier donnerait à l'hôte le contrôle du barème.

**`locale` est un nom pour deux domaines de valeurs disjoints** — le piège le plus coûteux du schéma :

| Nature | Type | Colonnes | Règle |
|---|---|---|---|
| Locale d'**interface** | `string(5)` | `users.locale`, `player.locale`, `round_player.choices_locale`, `round_choice_set.locale`, `takedown_request.locale`, `theme_label.locale` | Validée par `Locale::tryFrom()` avant toute écriture et avant `App::setLocale()`. |
| Locale de **catalogue** | `string(12)` | `movie_title.locale`, `alias.locale` | **Jamais castée par `App\Enums\Locale`, jamais déclarée en ENUM** : elle doit accepter `ja`, `ko`, `zh-Hant`, que la voie d'exception fait entrer. |

**`theme_label` est la seule des trois familles de contenu traduit en base à porter une locale d'interface** : son contenu est une chaîne d'**interface** éditable, affichée au joueur et obligatoire dans chaque locale activée pour publier le thème (`05`), pas une donnée de catalogue. Un `theme_label` en `ja` n'existera jamais, et la garde de publication compare de toute façon aux cas de l'enum ; non castée, la colonne accepterait silencieusement `frn` ou `en-GB` et le thème resterait éternellement non publiable sans message.

**Toute forme normalisée est `string(200)`** : `answer_key.normalized`, `guess.answer_key_normalized`, `guess.submitted_normalized`, `near_miss.normalized_text`. 200 = borne haute du réglage « longueur maximale d'une réponse », donc aucune chaîne saisissable n'est jamais tronquée. Deux cas sont bornés plus court, chacun parce que le produit borne le champ lui-même : `saved_config.name_normalized` (40) et `player.nickname_normalized` (20). Un unique `(normalized, movie_id)` pèse 808 octets, très en dessous des 3072 d'InnoDB DYNAMIC — mesuré accepté ; le refus 1071 n'apparaît qu'à 4 × `varchar(255)` = 4080 octets, également mesuré.

**Noms d'index explicites, toujours.** `Blueprint::createIndexName()` fabrique `table_col1_col2_type` ; MySQL refuse tout identifiant de plus de **64 caractères** (erreur 1059) là où SQLite en accepte 74. `seen_frame`, `round_tier`, `movie_certification` et `takedown_request` franchissent la borne à trois colonnes. Chaque index multi-colonnes passe son nom court en second argument.

### 1.4 Types portables SQLite / MySQL 8 — les pièges évités

| Piège | Fait mesuré | Règle du schéma |
|---|---|---|
| **Index partiel** | MySQL 8 n'en a **aucun** (erreur 1064 sur `WHERE`) ; SQLite en a | **Interdit.** L'unicité conditionnelle s'écrit par **colonne créneau nullable + UNIQUE ordinaire** : `users.email`, `room.room_code_active`, `saved_config.default_slot`. Les deux moteurs ignorent les NULL dans un UNIQUE (3 NULL acceptés des deux côtés, mesuré) — l'unicité conditionnelle est acquise **gratuitement**. |
| **Index sur expression** | MySQL exige `((lower(c)))`, SQLite `(lower(c))` — syntaxes mutuellement exclusives | **Interdit.** La normalisation se calcule **en PHP** et se range dans une colonne ordinaire indexée. |
| **Colonne générée** | SQLite refuse `ADD COLUMN … STORED` sur table non vide | **Interdit.** Toute valeur dérivée est une colonne ordinaire écrite par l'application, dans la même transaction que sa source. |
| **Index fulltext** | `compileFullText` **n'existe ni dans `SQLiteGrammar` ni dans la grammaire de base** ; `Blueprint::toSql()` teste `method_exists` et **saute la commande en silence** | **Interdit.** L'index serait créé en dev et absent en test, sans erreur ni avertissement — une divergence muette, pire qu'une exception. La validation passe par `answer_key`, jamais par une recherche plein texte. |
| **Index de préfixe de colonne** | `index(c(191))` MySQL seulement | **Interdit.** On borne la colonne (`string(200)`), pas l'index. |
| **Longueur de clé** | InnoDB DYNAMIC : 3072 octets. 4 × `varchar(255)` utf8mb4 = 4080 → refus 1071 ; 2 × = 2040 → OK | Jamais plus de trois colonnes `varchar` dans un UNIQUE. `linked_account (provider, provider_user_id)` = 20 + 191 → 844 o. |
| **COLLATION, casse et accents** | MySQL `utf8mb4_unicode_ci` : `'e' = 'é'` **et** `'E' = 'e'`. SQLite BINARY : sensible aux deux | **Aucune unicité ne porte sur du texte humain.** `alias.alias` et `movie_title.title` n'ont **aucune** contrainte d'unicité. `->collation()` est **interdite** : le modificateur `Collate` existe pourtant dans `SQLiteGrammar::$modifiers` — ce qui n'est pas portable ce sont les **valeurs** (`BINARY`/`NOCASE`/`RTRIM` contre `utf8mb4_*`). La portabilité vient de l'alphabet replié de la **donnée**, jamais d'une collation déclarée. |
| **JSON** | `json` natif en MySQL, `text` en SQLite ; `json_extract` existe des deux côtés mais n'est indexable ni portablement ni en SQLite | **Aucun `WHERE`, `ORDER BY`, `GROUP BY` ni index à l'intérieur d'un JSON** (§ 1.6). Précédent du dépôt : `passkeys.credential`. |
| **ENUM natif** | `$table->enum()` = ENUM natif MySQL / `varchar` + CHECK SQLite ; ajouter une valeur = ALTER en dev, reconstruction de table en test | **Interdit, sans exception.** `string(N)` + enum backed PHP dans `casts()`. Trois évolutions sont déjà annoncées (troisième nature d'avatar en v1.1, troisième fournisseur OAuth, troisième pays de certification) et un ALTER sur `movie` ou `frame` coûte un instantané bloquant. Le mode strict MySQL rejette déjà une chaîne hors borne, et le cast lève à l'hydratation. |
| **CHECK** | Respectées par les deux moteurs, mais `Blueprint` n'expose **aucune** API `check()` | **Aucune contrainte CHECK.** Les bornes vivent dans les enums et dans le value object validé côté serveur (règle 2). Liste des invariants ainsi déportés au § 14 (A16). |
| **AUTOINCREMENT** | Sans le mot-clé, SQLite **réutilise** les rowid libérés | `$table->id()` l'émet ; aucune clé primaire entière n'est jamais écrite à la main. |

Trois pièges non typologiques :

- **`ONLY_FULL_GROUP_BY`** est actif en dev, pas en test : toute requête agrégée nomme chaque colonne non agrégée. Une requête verte en SQLite peut échouer en MySQL. C'est une des raisons pour lesquelles le vivier ne fait aucun `GROUP BY` (§ 12).
- **`->after()`, `->comment()`, `->unsigned()`, `->useCurrentOnUpdate()`** n'existent pas dans `SQLiteGrammar::$modifiers`. L'ordre physique des colonnes de `users` **diffère déjà** entre test et dev (migration 2FA). Aucune règle ne dépend de l'ordre des colonnes, et `->comment()` ne porte jamais une information nécessaire.
- **`dropForeign('nom')` lève une exception en SQLite** : on passe toujours les colonnes.

### 1.5 `ON DELETE` — trois directions, et rien d'autre

1. **Possession → `cascadeOnDelete`**, vers le bas uniquement : `game → game_player / round`, `round → round_tier / round_player / round_choice_set / guess`, `movie → movie_projection / movie_title / alias / answer_key / movie_certification / movie_tmdb_tag / movie_theme / near_miss`, `theme → theme_label / movie_theme`, `users → linked_account / saved_config / user_consent / data_export`, `room → player / seen_frame`.
2. **Vers le catalogue et vers un fait déjà figé → `restrictOnDelete`** : `round.movie_id`, `round.decoy_movie_id_1..3`, `frame.movie_id`, `frame_review.frame_id`, `takedown_request.target_movie_id / target_frame_id`, `admin_action.takedown_request_id`, `game.room_id`, `round.room_id`, `game_player.player_id`, `round_player.player_id`, `guess.player_id`. **C'est ainsi que le périmètre interdit de la purge devient une propriété du schéma** et non une consigne de job.
3. **Auteur → `nullOnDelete`, colonne nullable** : `frame.uploaded_by_id`, `frame_review.reviewer_id`, `admin_action.actor_id`, `takedown_request.decided_by_id`, `movie.curated_by_id`, `movie.content_verified_by_id`, `movie_title.edited_by_id`, `alias.created_by_id`, `movie_theme.assigned_by_id`, `movie_group.created_by_id`, `import_run.actor_id`, `player.user_id`, `round_tier.frame_id`, `round_tier.served_frame_id`, `guess.answer_key_id`.

Trois conséquences à écrire noir sur blanc :

- **Aucune FK ne part de `users` vers un fait de partie.** Le lien joueur ↔ compte passe exclusivement par `player.user_id` (nullable), de sorte que l'anonymisation d'un compte est **structurellement incapable** de casser le podium des autres joueurs.
- **Les cascades de possession ne se déclenchent presque jamais en v1.** La suppression de compte est une anonymisation qui conserve la ligne `User` ; ni `movie` ni `frame` ne sont jamais supprimés. Les actions concernées suppriment donc **explicitement** `passkeys`, `linked_account`, `saved_config`, `data_export`, les `password_reset_tokens` de l'ancienne adresse et les `sessions`. La cascade reste déclarée comme filet, jamais comme mécanisme.
- **Un échec de contrainte est le comportement voulu, pas un accident.** Supprimer une `room` déclenche une cascade vers `player` que le `restrict` de `game_player` bloque : toute la transaction échoue plutôt que de détruire un podium. L'ordre de suppression de la purge en découle (§ 11.3).

Chaque colonne de FK porte un **`$table->index()` explicite** en plus de `constrained()` : une FK n'indexe rien en SQLite. Précédent du dépôt : `passkeys`. La redondance avec un UNIQUE dont la FK est le préfixe gauche est **voulue et sans exception** — la spec la pratique nommément sur `linked_account_user_idx` (§ 5.4) : une règle uniforme coûte un index et évite qu'un relecteur arbitre au cas par cas.

> **Les trois listes ci-dessus sont exhaustives _par motif_, pas globalement.** Une FK absente de ces listes n'est pas sans comportement : c'est sa **section de table** qui le déclare, et elle seule. Sept FK sont dans ce cas — `movie.collection_id`, `movie.group_id`, `movie.import_run_id`, les trois de `report` et `seen_frame.frame_id`.

**Deux références souples, sans contrainte de FK**, qui coupent les deux cycles du schéma : `room.host_player_id` (cycle `room ↔ player`) et `frame.published_review_id` (cycle `frame ↔ frame_review`). Toutes deux sont des `bigint unsigned` nullables indexés. Ce n'est **pas** une limitation d'outillage : les migrations SQLite ne sont pas transactionnelles (`supportsSchemaTransactions()` vaut `false`), donc une FK ajoutée par une troisième migration reconstruirait proprement la table. C'est un choix de conception — la colonne doit survivre à la disparition de sa cible sans faire échouer une transaction de jeu, et son écriture est de toute façon confinée à une action unique. Précédent du dépôt : `sessions.user_id`.

### 1.6 Ne jamais requêter dans le JSON

Cinq colonnes JSON existent, et **aucune n'est jamais interrogée** : `room.settings`, `setting_preset.settings`, `saved_config.settings`, `game.settings_snapshot` (les quatre portant le même value object `RoomSettings`) et `frame_review.answers` (réponses item par item, clées par slug de grille). Chacune est doublée d'une **colonne de version en clair** (`settings_version`, `grid_version`) : il faut savoir lire la charge utile avant de l'ouvrir, et dénombrer les lignes d'une version antérieure doit rester une requête.

Trois conséquences directes :

1. Tout réglage filtré, trié ou comparé **sort en colonne typée** : `room.room_code`, `status`, `capacity`, `frames_per_round`, `rounds_count`, `input_difficulty`, `allow_late_join` ; `game.mode`, `status`, `input_difficulty`, `rounds_count`, `frames_per_round`, `rounds_completed`.
2. La sélection de thèmes reste **dans** le JSON (`themeIds`) : elle est désérialisée en PHP puis passée en `IN (...)` à la requête de vivier. L'union est un OU, jamais une intersection.
3. Le **profil de disponibilité de titre** du QCM et la **couverture de niveaux d'images** ne peuvent donc pas être des clés JSON : ce sont des colonnes de `movie_projection` (§ 3.2).

### 1.7 Style de modèle imposé par ce dépôt

- Migrations en **classe anonyme**, `up()` / `down(): void` typés, PHPDoc « Run the migrations. » / « Reverse the migrations. », `dropIfExists` en ordre inverse. LF, 4 espaces, newline finale.
- Modèles : `#[Table('…')]`, `#[Fillable([...])]`, `#[Hidden([...])]`, `#[Appends([...])]`, `casts()` en **méthode protégée** typée `@return array<string, string>`, `/** @use HasFactory<XFactory> */`, **PHPDoc `@property` exhaustif** — obligatoire au niveau 7, et `database/` est dans les `paths` de PHPStan, donc migrations, factories et seeders y passent aussi.
- **Aucun `mixed`.** C'est ce qui rend le cast `'array'` impossible pour les réglages : `CastsAttributes<RoomSettings, RoomSettings>`, `get()` gérant explicitement le cas nul, documenté `@return TGet|null`.
- **Tables sans `updated_at`** (`guess`, `report`, `admin_action`, `user_consent`, `movie_tmdb_tag`) : le modèle déclare **`const UPDATED_AT = null;`**. Sans cela, `$timestamps = true` par défaut écrit une colonne inexistante — mesuré : `table guess has no column named updated_at` en SQLite, erreur 1054 en MySQL, donc **chaque bonne réponse du jeu échoue**. `$timestamps = false` est réservé aux tables sans aucune colonne conventionnelle, **nommément et exhaustivement `frame_review`** (qui n'a que `reviewed_at`) **et `seen_frame`** (qui n'a que `last_seen_at`) ; leur colonne temporelle est écrite explicitement à chaque écriture, jamais par `useCurrent()`. **Toute autre table du schéma porte soit `timestamps`, soit `created_at` + `const UPDATED_AT = null`, sans troisième cas** — y compris `movie_projection`, `round_choice_set`, `data_export` et `purge_run`, dont les colonnes datées métier (`recomputed_at`, `composed_at`, `expires_at`, `ran_at`) ne dispensent **pas** des colonnes conventionnelles : sans elles, `$timestamps = true` par défaut fait tomber l'erreur 1054 à la composition du QCM, **en pleine manche**. Un test Pest balaie les 35 modèles et échoue si l'un déclare des horodatages que sa table ne porte pas.
- **`#[Hidden]` est une règle de sécurité, pas de cosmétique.** `HandleInertiaRequests::share()` sérialise le modèle `User` **entier** sur toutes les pages, écran de jeu compris, et le SSR est activé : toute colonne ajoutée à `users` fuite par défaut. Liste complète aux § 5.1, § 6.2 et § 7.
- **`#[Fillable]` n'accueille jamais une colonne d'autorité** : ni `role`, ni `plan`, ni un consentement, ni un état de disponibilité.
- `DB::prohibitDestructiveCommands()` est actif en production : **toute migration est additive et rejouable en avant**, `down()` écrit mais jamais joué en production. Toute migration touchant `movie`, `frame`, `movie_title`, `alias` ou `frame_review` exige un **instantané bloquant préalable** — c'est pourquoi les valeurs volatiles n'y sont pas.
- Toute variable nouvelle (`FRAMES_DISK_ROOT`, clé TMDB, OAuth, Reverb) va dans `.env` **et** `.env.example` dans le même commit ; ajouter `ext-imagick` à `composer.json` impose d'ajouter `extensions:` au workflow CI dans le même commit.

### 1.8 Quatre invariants de lecture, écrits une fois

Ces quatre règles ne sont pas des consignes dispersées : elles sont citées par tout le reste du document et testées nommément.

| # | Invariant | Pourquoi |
|---|---|---|
| **L1** | **Toute agrégation de `guess` joint `round` et exclut `round.status = 'cancelled'`.** Score vivant, classement intermédiaire, gel du podium, les quatre compteurs d'historique. | `00-overview.md` impose qu'une manche annulée ne rapporte « aucun point », or `guess` est immuable et la ligne `round` n'est pas supprimée. Sans cet invariant, deux joueurs verrouillés au palier 2 d'une manche ensuite annulée gardent 400 points chacun. |
| **L2** | **Aucune valeur servant à décider de l'acceptation d'une réponse n'est mise en cache plus longtemps que la transaction qui peut la changer.** | Le projecteur `answer_key` est synchrone précisément pour que l'ambiguïté d'un préfixe soit juste **à l'instant serveur de réception** ; un cache « pour la durée de la manche » rouvrirait exactement la fenêtre que la synchronicité ferme. Mise en œuvre au § 3.5. |
| **L3** | **Aucune valeur mesurée ou déclarée par le client n'entre dans la sélection du palier ni dans le calcul du bonus.** La poignée de main d'offset d'horloge est un confort d'affichage. | Règle 1. Un client modifié qui déclarerait 600 ms de RTT s'achèterait 300 ms de palier supérieur à chaque frontière, soit quarante frontières par partie à N=5. La fenêtre de grâce du principe 4 est donc une constante **serveur**, figée au lancement dans `game.tier_grace_ms` (§ 7.5). |
| **L4** | **Le temps de réponse et le nombre de requêtes d'un refus ne dépendent jamais de la proximité de la réponse.** Un refus fait exactement le même travail, quelle que soit la distance mesurée. | Le produit impose un refus **neutre** — « jamais de *presque !*, qui confirmerait la saga » (décision 13). Une soumission quasi-juste qui lirait et écrirait en plus un compteur de k-anonymat, et insérerait une ligne `near_miss` un coup sur trois, rendrait cette proximité **mesurable au chronomètre** : le tricheur soumet « le seigneur des ann », compare à « zzzzzz », et une dichotomie sur les préfixes lui reconstitue le titre sans jamais regarder une image, au palier 1, le mieux payé. Mise en œuvre au § 7.9. |

---

## 2. Carte des tables

35 tables de domaine, plus `users` altérée. Une ligne par table, ce qu'elle possède, sa position vis-à-vis de la purge.

### Catalogue — 13 tables, **périmètre interdit de la purge**

| Table | Possède | Purge |
|---|---|---|
| `movie` | L'identité d'une œuvre : provenance d'import, conformité de contenu, régime de disponibilité, difficulté. **Aucune valeur dérivée.** | jamais |
| `movie_projection` | Toute valeur **dérivée** d'un film et requêtable : couverture de la banque d'images, profil de disponibilité de titre. Une ligne par film. | jamais |
| `movie_group` | Le lien manuel entre homonymes et remakes. Seule conséquence de jeu : un tirage n'en retient jamais deux. | jamais |
| `collection` | La saga TMDB, vocabulaire importé, consommé par les thèmes de saga. | jamais |
| `movie_title` | Le titre **affichable** d'un film dans une locale de catalogue. Une ligne par couple. | jamais |
| `alias` | Les variantes de titre **acceptées en réponse**, jamais affichées. | jamais |
| `answer_key` | La **projection** normalisée de toutes les chaînes acceptables et leur indicateur d'ambiguïté. Unique index consulté à chaque tentative. | jamais |
| `movie_certification` | La certification **retenue** par film et par pays, base unique du filtre de contenu. | jamais |
| `movie_tmdb_tag` | Les étiquettes TMDB brutes (genres, sociétés) d'un film, **seul support des règles automatiques de genre et de studio**. | jamais |
| `theme` | Le critère combinable par l'hôte et sa règle automatique. | jamais |
| `theme_label` | Le libellé traduit d'un thème, obligatoire dans chaque locale activée pour publier. | jamais |
| `movie_theme` | L'appartenance film ↔ thème, règle automatique **et** exception manuelle séparées. | jamais |
| `import_run` | La provenance d'import : filtre réellement appliqué, curseur de reprise, compteurs, état de quota. | jamais |

### Banque d'images — 2 tables, **périmètre interdit de la purge**

| Table | Possède | Purge |
|---|---|---|
| `frame` | Une variante d'image : niveau, source déclarée, deux dérivés sur disque, état de traitement, disponibilité, effacement de fichiers attesté. | jamais |
| `frame_review` | La preuve opposable qu'une image a été examinée sous une version datée de la grille par un curateur **nommé**. Ajout seul. | jamais |

### Comptes et identité — `users` altérée + 4 tables

| Table | Possède | Purge |
|---|---|---|
| `users` | Identité de connexion, rôle, langue, avatar à deux natures, consentements courants, pierre tombale d'anonymisation. | jamais (dormance ≠ purge) |
| `linked_account` | Un compte Discord ou Google rattaché, unique par (provider, id provider). | jamais |
| `user_consent` | L'**historique** daté des acceptations CGU et de la déclaration d'âge. Ajout seul. | jamais |
| `data_export` | Une archive d'export en libre-service : chemin, échéance, effacement. | à échéance |
| `saved_config` | Un jeu de réglages nommé appartenant à un compte, strictement privé. | jamais |

### Salon et réglages — 2 tables (+ `saved_config` ci-dessus)

| Table | Possède | Purge |
|---|---|---|
| `room` | Le salon : code court, hôte courant, état de cycle de vie, projection typée des réglages et objet de réglages complet. | par dépendance |
| `setting_preset` | Les quatre presets du site, sans propriétaire, ni modifiables ni supprimables. | jamais |

### Partie — 9 tables, **cœur du périmètre de la purge**

| Table | Possède | Purge |
|---|---|---|
| `player` | Un siège dans un salon (ou solo) : identité d'affichage, langue lisible hors HTTP, présence, jeton de reconnexion. | par dépendance |
| `game` | Une partie : tirage figé, réglages figés, versions de règle figées, horloge de vie, marqueur solo. | 12 mois |
| `game_player` | La participation d'un siège à une partie et les agrégats **figés au podium**. | 12 mois |
| `round` | Une manche : film tiré et figé, horloge, leurres du QCM, issue, incident. | 12 mois |
| `round_tier` | Un palier **matérialisé au lancement** : image tirée, image **servie**, offset, durée, valeur. | 12 mois |
| `round_player` | La participation d'un joueur à une manche : état de saisie, tentatives fausses comptées, langue de composition. | 12 mois |
| `round_choice_set` | Les **quatre chaînes** du QCM figées par manche et par locale. | 12 mois |
| `guess` | Une **bonne réponse et rien d'autre** : score figé au verrouillage, instantané de la règle appliquée. | 12 mois |
| `seen_frame` | La mémoire des variantes déjà montrées à un **salon**, sur un seul axe. | 90 j / 500 manches |

### Modération, conformité et exploitation — 5 tables

| Table | Possède | Purge |
|---|---|---|
| `near_miss` | La file d'alias : chaînes quasi-justes **agrégées** par film, au-dessus d'un seuil de k-anonymat. | 90 jours |
| `report` | Un signalement de joueur, limité par construction à deux cibles. | 12 mois |
| `takedown_request` | Une demande de retrait publique et sa décision motivée. | identité seule, à échéance |
| `admin_action` | Le journal en ajout seul de tout geste engageant : qui, quoi, sur quoi, pourquoi, quand. | par classe |
| `purge_run` | Le journal d'exécution de la purge, sonde de la seule panne à conséquence juridique. | 13 mois |

---

## 3. Catalogue

### 3.1 `movie` — l'identité d'une œuvre, et aucune valeur dérivée

Table **sanctuarisée** : toute migration la touchant exige un instantané bloquant préalable. C'est la raison de deux décisions structurantes — refus des ENUM natifs (ajouter une valeur = ALTER) et **sortie de toutes les valeurs dérivées vers `movie_projection`** (§ 3.2).

| Colonne | Type | Null | Défaut | Raison |
|---|---|---|---|---|
| `id` | bigint unsigned | non | — | § 1.1. Table la plus jointe du projet ; un `char(26)` coûterait 104 octets par entrée d'index contre 8. |
| `tmdb_id` | unsignedInteger | oui | `null` | Identité normative d'un film. Nullable pour le catalogue de démonstration, insérable sans clé TMDB ni réseau. |
| `import_source` | string(12) + `ImportSource` | non | `discover` | Voie d'entrée. `demo` exclut définitivement le film de la resynchronisation TMDB et des statistiques de débit. |
| `import_run_id` | bigint unsigned | oui | `null` | Relie le film au balayage ou au collage qui l'a fait entrer : son motif d'exception devient auditable. |
| `is_import_exception` | boolean | non | `false` | Nom imposé par la décision 11. **Non dérivable des trois motifs** : un film collé qui satisfait tout le filtre reste marqué entré par exception. |
| `exception_for_language` | boolean | non | `false` | Motif `language`. Trois booléens et non une valeur d'enum : un film cumule souvent plusieurs motifs et le back-office compte **par motif**. |
| `exception_for_vote_count` | boolean | non | `false` | Motif `vote_count`. |
| `exception_for_release_year` | boolean | non | `false` | Motif `release_year` : c'est lui qui porte tout l'âge d'or Disney antérieur à 1970. |
| `title_original` | string(255) | non | — | Invariant, jamais réécrit depuis une traduction, dernier maillon garanti de la chaîne de repli de `05`. |
| `title_original_latin` | string(255) | oui | `null` | Translittération latine fournie par TMDB. **Colonne et non ligne d'`alias`** : voir l'arbitrage A4. |
| `original_language` | string(8) | non | — | Critère du filtre d'import, dénominateur du décile de difficulté, `rule_value` du thème « cinéma international ». Indexée à ces trois titres. |
| `release_year` | smallint unsigned | oui | `null` | Critère d'import, discriminant d'homonyme affiché à la révélation, source des thèmes de décennie. L'année suffit : aucune règle ne lit une date complète. |
| `vote_count` | unsignedInteger | non | `0` | Notoriété brute TMDB, conservée telle quelle pour que le décile reste recalculable après chaque resynchronisation. |
| `adult` | boolean | non | `false` | Drapeau TMDB conservé comme **preuve** que le filtre de contenu a été appliqué, et comme entrée de l'écran de différences. La décision, elle, est portée par `content_flag`. |
| `collection_id` | bigint unsigned | oui | `null` | Saga TMDB. Cardinalité 0..1 côté TMDB, donc une colonne et **pas** un pivot. À ne jamais confondre avec `group_id`. |
| `group_id` | bigint unsigned | oui | `null` | Groupe manuel d'homonymes et de remakes. Jamais alimenté par TMDB, jamais montré à un joueur, survit au réimport. |
| `availability` | string(12) + `ContentAvailability` | non | `draft` | **Unique vérité de la présence au vivier.** Cinq valeurs, un seul axe : voir l'arbitrage A1. |
| `availability_changed_at` | timestamp | oui | `null` | Un retrait juridique doit être un état **horodaté** : la fiche du film doit se suffire devant une mise en demeure, sans jointure. |
| `availability_reason` | string(500) | oui | `null` | Motif du changement, exigé nommément pour le retrait juridique et utile pour une dépublication de curation. |
| `first_published_at` | timestamp | oui | `null` | Première mise en jeu, **jamais réécrite** par une republication. Horodatage, jamais un prédicat. |
| `content_flag` | string(16) + `ContentFlag` | non | `unrated_pending` | Filtre de contenu **jamais contournable** : il entre dans le prédicat de vivier et dans son index (§ 12, arbitrage A3). |
| `content_verified_by_id` | bigint unsigned | oui | `null` | Coche « contenu vérifié, pas de classification restrictive ». Sans elle, un film sans certification FR ni US connue reste non publiable. |
| `content_verified_at` | timestamp | oui | `null` | Horodatage de la même coche. Geste engageant, qui écrit aussi une ligne `admin_action`. |
| `movie_difficulty` | string(12) + `MovieDifficulty` | oui | `null` | Valeur **effective** dénormalisée (`override` sinon `derived`). Cas nommés, jamais un entier 1-5 : le lexique interdit de la confondre avec `frame_level`. |
| `movie_difficulty_derived` | string(12) + `MovieDifficulty` | oui | `null` | Dérivée de la notoriété **normalisée par décile de `original_language`** (décision 11) et pondérée par l'appartenance à une collection retenue en thème de saga. Réécrite librement. |
| `movie_difficulty_override` | string(12) + `MovieDifficulty` | oui | `null` | Correction manuelle d'un curateur. **Jamais écrite par un import, un réimport ou le calcul de décile** — c'est ce qui la fait survivre à la resynchronisation. |
| `curated_by_id` | bigint unsigned | oui | `null` | Auteur de la passe de curation. `nullOnDelete` : la traçabilité ne dépend pas de la survie d'un compte. |
| `curation_active_seconds` | unsignedInteger | non | `0` | Temps **actif** de curation par film (décision 10), accumulé par battements throttlés avec exclusion des pauses > 60 s. Valeur non reconstructible, donc elle reste sur `movie` et non dans la projection jetable. |
| `created_at` / `updated_at` | timestamp | non | — | Date d'entrée au catalogue, distincte de `first_published_at`. |

**Clés** — PK `id` · UNIQUE `movie_tmdb_uq (tmdb_id)`, colonne nullable donc plusieurs films `demo` sans `tmdb_id` coexistent · FK `collection_id → collection`, `group_id → movie_group`, `import_run_id → import_run`, `curated_by_id → users`, `content_verified_by_id → users`, toutes `nullOnDelete`. **Aucune FK sortante vers `game`, `round`, `room`, `player` ou `guess`** : c'est ce qui rend structurellement impossible qu'une purge de faits de partie atteigne le catalogue.

**Index** — `movie_tmdb_uq (tmdb_id)` · `movie_pool_idx (availability, content_flag, id)` · `movie_decile_idx (original_language, vote_count)`, qui sert aussi le thème « cinéma international » par préfixe gauche · `index(group_id)`, `index(collection_id)`, `index(import_run_id)`, explicites. **Pas d'index** sur `is_import_exception`, `movie_difficulty` ni `release_year` : 2 à 5 valeurs distinctes, MySQL ne les emprunterait pas ; l'appartenance par difficulté et par décennie est servie par `movie_theme`.

**Ce que `movie` ne contient pas, et pourquoi** : aucun agrégat de jeu (nombre de parties, films jamais trouvés) — ce serait un fait de partie soumis à la purge à 12 mois logé dans une table que la purge ne doit **jamais** atteindre ; aucune forme normalisée de titre (`answer_key` en est l'unique propriétaire) ; aucun chemin d'image ; aucune valeur dérivée de la banque d'images ou de la couverture de titres (`movie_projection`) ; aucun auteur de changement de disponibilité (`admin_action`, arbitrage A2) ; aucun champ TMDB que ne lit aucune règle — ni `vote_average`, ni `runtime`, ni synopsis, ni affiche, ni casting.

**Le retrait juridique ne supprime pas la ligne** : il pose `availability = 'withdrawn'` avec motif et horodatage, ce qui fait du `tmdb_id` unique le blocage de réimport, **sans table de bannissement** (arbitrage A10).

### 3.2 `movie_projection` — tout ce qui est dérivé, et rien d'autre

Une ligne par film, PK = `movie_id`. Elle existe pour **sortir les valeurs volatiles d'une table sanctuarisée** dont chaque migration coûte un instantané bloquant, et parce que ces valeurs ne peuvent vivre ni en JSON (jamais requêtable), ni en colonne générée (SQLite refuse un `STORED` ajouté par ALTER), ni en agrégat à la volée (`GROUP BY` sur `frame` à chaque frappe du lobby, sous `ONLY_FULL_GROUP_BY` en dev et pas en test).

| Colonne | Type | Null | Défaut | Raison |
|---|---|---|---|---|
| `movie_id` | bigint unsigned | non | — | PK. Exactement une ligne par film, **créée avec lui**. |
| `levels_mask` | unsignedTinyInteger | non | `0` | Masque 5 bits des niveaux couverts par au moins une variante **jouable**. C'est la moitié de la condition de publication d'un film, dont l'énoncé complet vit **au § 4.3 et nulle part ailleurs** ; elle se teste par `levels_mask & 21 = 21` (niveaux 1, 3 et 5) — arithmétique entière portable, là où `BIT_COUNT` existe en MySQL et pas en SQLite. |
| `levels_count` | unsignedTinyInteger | non | `0` | Nombre de niveaux **distincts** couverts. Éligibilité à un `N` : `levels_count >= N`. Seule colonne indexable du calcul de vivier. |
| `level_1_variants` … `level_5_variants` | unsignedTinyInteger ×5 | non | `0` | Compte de variantes jouables par niveau. Une valeur à 1 est le signal back-office « variante unique », **jamais** une contrainte de jeu. |
| `variants_total` | unsignedSmallInteger | non | `0` | Total, affiché face à la cible de 8 images par film sans sommer cinq colonnes. |
| `title_locale_mask` | unsignedSmallInteger | non | `0` | Profil de disponibilité de titre : un bit par locale **activée**, dans l'ordre ordinal de `App\Enums\Locale`. Rend le tirage des trois leurres à profil identique interrogeable par **égalité indexée**, sans agrégat sur `movie_title` à `T_N`. |
| `title_mask_version` | unsignedTinyInteger | **non, sans défaut** | — | Version de la disposition des bits, `Locale::MASK_VERSION`. Pas de défaut : un INSERT partiel échoue bruyamment en MySQL strict (1364) au lieu de produire un masque muet. |
| `recomputed_at` | timestamp | **non, sans défaut** | — | Date de fraîcheur. Une projection sans date est indébogable après restauration. Même raison de refuser un défaut. |
| `created_at` / `updated_at` | timestamp | non | — | `updated_at` double `recomputed_at` mais garde le modèle sur le chemin conventionnel (§ 1.7) : sans elles, `$timestamps = true` par défaut écrit une colonne inexistante à chaque reprojection. |

**Clés** — PK `movie_id` · FK `movie_id → movie` `cascadeOnDelete` : pure projection, elle peut disparaître avec son film sans perte d'information.
**Index** — `movie_projection_levels_idx (levels_count, movie_id)`, couvrant pour la branche « aucun thème sélectionné » du vivier · `movie_projection_qcm_idx (title_mask_version, title_locale_mask, movie_id)`.

**Qui écrit, et quand — trois règles, testées.**

1. **La ligne est créée dans la même transaction que la ligne `movie`, par l'action de création de film**, avec toutes ses colonnes à leur valeur calculée. **Aucun observateur ne crée jamais une ligne de projection** ; ils ne font que `update`. Sans cette règle, un `updateOrCreate` d'observateur insère la ligne avec les défauts de l'autre famille de colonnes — et `title_mask_version = 0` est précisément la valeur qui n'apparie jamais la version courante : le film serait jouable mais invisible au tirage des leurres, et tout le salon basculerait en silence sur `title_original`.
2. **Écriture synchrone, jamais un job de fond.** Toute transition faisant entrer ou sortir une frame du comptant (publication, dépublication, suspension, retrait, changement de `frame_level`) recalcule `levels_*` dans la **même** transaction ; toute création, suppression ou changement de locale d'une ligne `movie_title` recalcule `title_locale_mask` dans la **même** transaction et déclenche le projecteur `answer_key`. Un job de fond laisserait un film retiré encore comptant dans le vivier, alors que la cascade de retrait exige une sortie « à la seconde » — un retrait qui ne sort pas du vivier est un retrait qui ment. Coût : un `UPDATE` d'une ligne par transition, soit environ 1 500 pour la passe 1 du catalogue entier.
3. **`catalog:reproject` reconstruit tout**, crée les lignes manquantes **avant** toute mise à jour, et est **obligatoire après toute restauration**. Elle sert aussi de filet et de test.

**Prédicat unique de variante jouable**, cité partout ailleurs et jamais réécrit :

> `frame.availability = 'published'` **ET** `frame.processing_state = 'ready'` **ET** `frame.game_path IS NOT NULL`

Les trois conditions, ensemble. Il est utilisé à l'identique par (1) le comptage de `levels_mask` / `levels_count` / `level_i_variants`, (2) le tirage de variante au lancement, (3) `Frame::isServable()` au moment de signer l'URL. Sans la troisième condition, un job Imagick à moitié échoué (`ready` posé, écriture du dérivé échouée) produit un film qui **passe la garde de vivier et casse une manche** : la garde dont la raison d'être est d'empêcher une manche injouable en produirait l'annulation.

**Profil de titre et troisième locale.** `Locale::MASK_VERSION` est incrémentée à tout changement de l'ensemble des locales activées. Tant que `catalog:reproject` n'a pas tourné, **aucune ligne** ne satisfait `title_mask_version = :courante`, donc aucun leurre n'est trouvé et les quatre propositions basculent ensemble sur `title_original` pour tout le salon — la dégradation déjà spécifiée par `05`. Un masque périmé lu comme valide produirait un QCM linguistiquement hétérogène, c'est-à-dire une fuite de la bonne réponse : **l'échec tombe du côté visible, jamais du côté silencieux.**

### 3.3 `movie_group` et `collection` — « même œuvre » contre « même saga »

Les confondre interdirait deux Star Wars dans une partie — exactement le contraire du produit — et laisserait Old Boy 2003 et Old Boy 2013 tomber ensemble, ce qui rend la révélation incompréhensible.

**`movie_group`** · `id` · `label` string(120) NOT NULL — libellé interne de back-office (« Old Boy 2003 / 2013 »), **jamais affiché à un joueur, donc jamais localisé** : c'est ce qui le distingue d'un thème de saga · `note` string(500) nullable, justification courte du regroupement · `created_by_id` bigint nullable `nullOnDelete`, un groupe est toujours un geste manuel · `timestamps`. Aucun index au-delà de la PK : quelques dizaines de lignes, et l'accès se fait toujours depuis `movie.group_id`, qui porte le sien. **Jamais alimentée automatiquement** : le back-office signale des candidats (formes normalisées proches lues dans `answer_key`, ou `collection_id` identique) et un curateur tranche.

**`collection`** · `id` · `tmdb_id` unsignedInteger nullable, UNIQUE `collection_tmdb_uq` · `name` string(160) NOT NULL, nom TMDB **non localisé** — une collection n'est jamais affichée à un joueur, c'est le thème de saga et ses `theme_label` qui le sont · `timestamps`. Deux consommations seulement : `rule_value` d'un `theme` de kind `saga` (une dizaine de sagas retenues à la main, jamais « une collection = un thème ») et pondération de `movie_difficulty_derived`. **Pas de pivot `movie_collection`** : la cardinalité TMDB est 0..1.

### 3.4 `movie_title` et `alias` — l'affichage et la validation, jamais mélangés

**`movie_title`** — le titre affichable d'un film dans une langue de catalogue donnée.

| Colonne | Type | Null | Raison |
|---|---|---|---|
| `id` | bigint unsigned | non | Clé de ligne. |
| `movie_id` | bigint unsigned | non | FK `→ movie` `cascadeOnDelete`. |
| `locale` | string(12) | non | Locale de **catalogue** (§ 1.3). Jamais castée, jamais un ENUM : elle accepte `ja`, `ko`, `zh-Hant`. |
| `title` | string(255) | non | Titre affiché. **Aucune forme normalisée ici** : `answer_key` en est l'unique propriétaire. |
| `origin` | string(12) + `ContentOrigin` | non | `tmdb` ou `curator`. Sans elle, une resynchronisation écrase silencieusement la correction de titre qui est le travail quotidien du curateur. Une ligne `curator` n'est **jamais** réécrite par un réimport. |
| `edited_by_id` | bigint unsigned | oui | Auteur de la correction, `nullOnDelete`. |
| `timestamps` | timestamp | non | — |

UNIQUE `movie_title_movie_locale_uq (movie_id, locale)` — au plus un titre par couple, et il sert aussi la lecture du paquet de révélation en une requête par préfixe gauche. **Pas d'index isolé sur `locale`** : la couverture par langue se lit dans `movie_projection.title_locale_mask`, sans toucher cette table.

**L'absence d'une ligne EST l'information.** Aucun titre n'est jamais recopié d'une langue vers une autre, ni à l'import, ni à la curation, ni au tirage : c'est ainsi que la file « titres manquants » reste vraie et que le repli reste un affichage (`05`). Publier un film n'exige **aucune** couverture de titres, contrairement à un thème dont tous les libellés sont obligatoires — asymétrie assumée, les thèmes se comptant en dizaines et les films en centaines.

**`alias`** — les variantes acceptées en réponse, **à usage exclusif de validation**.

`id` · `movie_id` FK `cascadeOnDelete` · `locale` string(12) — plusieurs alias par couple ; seuls les alias des locales **activées** entrent dans `answer_key` · `alias` string(255) — **jamais affichée** : un alias FR n'est jamais proposé comme titre EN à la révélation · `origin` string(12) + `ContentOrigin`, défaut `curator` — distingue un alias TMDB d'un alias curé ou promu depuis `near_miss` · `created_by_id` nullable `nullOnDelete` · `timestamps`.

Index `alias_movie_idx (movie_id)`, pour la lecture en curation. **La validation ne passe jamais par cette table** : elle passe par `answer_key`. **Aucune unicité sur le texte** — en MySQL `utf8mb4_unicode_ci` « Amelie » et « Amélie » violeraient la contrainte, en SQLite BINARY elles coexistent : le même test passerait au vert d'un côté et au rouge de l'autre. Le dédoublonnage réel est `unique(normalized, movie_id)` sur `answer_key`.

**Un alias ne produit jamais de clé de nature `prefix`** (décision 13), et **la translittération latine n'est pas un alias** : elle vit sur `movie.title_original_latin` (arbitrage A4).

### 3.5 `answer_key` — la projection consultée à chaque tentative

Unique propriétaire des formes normalisées et des préfixes. `movie_title` et `alias` n'en portent aucune.

| Colonne | Type | Null | Défaut | Raison |
|---|---|---|---|---|
| `id` | bigint unsigned | non | — | Identifiant **stable** : `guess` le conserve douze mois dans son instantané de règle. |
| `movie_id` | bigint unsigned | non | — | FK `→ movie` `cascadeOnDelete`. Son index rend « toutes les chaînes acceptées du film de la manche » interrogeable en **une** requête. |
| `key_kind` | string(16) + `AnswerKeyKind` | non | — | `title_original`, `title_latin`, `title`, `alias`, `prefix`. **Seule la nature `prefix` est soumise à la règle de collision** : un titre complet ou un alias curé qui désigne le film de la manche est toujours accepté, homonyme publié ou non. |
| `source_locale` | string(12) | oui | `null` | Locale de catalogue d'origine, nulle pour `title_original` et `title_latin`. **Purement traçante** : elle n'entre dans aucune clause `WHERE` de validation, la locale du joueur ne participant à aucun arbitrage (`05`). |
| `normalized` | string(200) | non | — | Forme normalisée calculée **en PHP** et réduite à `[a-z0-9 ]`. Sur cet alphabet, `utf8mb4_unicode_ci` et BINARY rendent le **même verdict** : la portabilité cesse d'être une propriété du moteur pour devenir une propriété de la donnée. |
| `is_ambiguous` | boolean | non | `false` | Vrai seulement pour une clé `prefix` qu'un autre film `published` partage. Dénormalisé : calculer l'ambiguïté par agrégation à chaque tentative mettrait un `GROUP BY` sur le chemin le plus chaud du jeu. |
| `timestamps` | timestamp | non | — | Diagnostic de reprojection ; jamais lus par la validation. |

**Clés** — PK `id` · UNIQUE `answer_key_norm_movie_uq (normalized, movie_id)` : dédoublonnage, appariement exact, détection d'homonymie et recompte d'ambiguïté, tout par le même index, `normalized` en préfixe gauche · FK `movie_id → movie` `cascadeOnDelete` · référencée par `guess.answer_key_id` en `nullOnDelete`.
**Index** — `answer_key_norm_movie_uq` · `answer_key_movie_idx (movie_id)`, 10 à 20 lignes par film. **Pas d'index partiel** « `WHERE availability = published` » : MySQL 8 n'en a aucun — c'est exactement pourquoi l'ambiguïté est dénormalisée. **Pas d'index fulltext ni fonctionnel** (§ 1.4).

**Périmètre exact des clés d'un film** : `title_original` et `title_original_latin` **inconditionnellement**, sans filtre de locale ; `movie_title` et `alias` des seules locales **activées** ; plus les **préfixes dérivés des seuls titres — jamais d'un alias** — découpés au premier séparateur de `config('catalog.subtitle_separators')` et retenus au-delà de `config('catalog.min_prefix_length')`. Ces deux réglages vivent en configuration et non en table : une table éditable ferait d'un réglage de normalisation une donnée modifiable sans reprojection, donc un index silencieusement incohérent avec la règle qui l'a produit. Tout changement est un déploiement suivi de `catalog:reproject`.

**Précédence sur `(movie_id, normalized)`** : toute nature exacte l'emporte sur `prefix`, donc une chaîne à la fois alias et préfixe reste toujours acceptée.

**Reconstruction par différence, jamais par purge et réinsertion.** Une ligne qui existe encore garde son identifiant — `guess` conserve douze mois l'identifiant de la clé retenue, et une reconstruction destructive ferait danser tous les instantanés du journal à chaque publication.

**Recalcul de `is_ambiguous` : projecteur synchrone et borné, jamais un job de fond.** Publication, dépublication, suspension, retrait, ajout ou retrait d'un `movie_title` ou d'un `alias`, changement de `title_original` : pour **chaque valeur normalisée touchée** (quelques dizaines au plus), le projecteur recompte les films `published` la portant et pose `is_ambiguous` sur toutes les clés `prefix` égales. Le back-office affiche au curateur, **avant confirmation**, la liste des préfixes que la publication va rendre ambigus et à quels films ils appartenaient. Un job de fond laisserait une fenêtre pendant laquelle un préfixe ambigu resterait accepté et rendrait impossible cet avertissement nominatif.

**Invariant L2 appliqué.** Le résultat de `answer_key_movie_idx` est mis en cache **pour la durée de la manche**, et le projecteur **invalide explicitement les entrées de cache des `movie_id` touchés dans la même transaction** — il connaît déjà cet ensemble borné. Sans cette invalidation, publier « Les Deux Tours » à `t = 30 s` d'une manche à `D = 120 s` sur « La Communauté de l'Anneau » laisserait accepter le préfixe partagé pendant 90 s, avec `prefix_was_ambiguous = false` figé dans `guess`. Repli acceptable si l'invalidation ciblée se révèle fragile : mettre en cache les **chaînes** du film (qui ne changent que si **ses** titres changent) et relire `is_ambiguous` en base au seul moment où un préfixe apparie exactement — une lecture par `answer_key_norm_movie_uq`, sur une fraction des soumissions.

**L'ambiguïté se mesure sur le catalogue `published` entier, jamais sur le vivier du salon** — sinon accepter un préfixe révélerait combien d'épisodes de la saga sont dans le tirage — et **n'est jamais rétroactive** : `guess` porte l'instantané et rien ne le relit. Les clés d'un film dépublié ou retiré sont **conservées** ; seul leur poids dans le recompte disparaît, pour que republier soit un basculement de drapeau.

### 3.6 `movie_tmdb_tag` — l'étiquette TMDB brute, seul support des règles de genre et de studio

Sans elle, deux des cinq discriminants de thème n'ont **aucune donnée locale** : la décennie se lit sur `movie.release_year`, la langue sur `movie.original_language`, la saga sur `movie.collection_id`, la difficulté sur `movie.movie_difficulty` — mais le **genre** et le **studio** ne sont nommés nulle part côté film, seulement côté thème (`theme.rule_value`). Conséquences directes : `movie_theme.is_auto`, décrit comme « réécrit librement à chaque recalcul », ne serait calculable pour `genre.*` et `studio.*` à partir d'aucune donnée locale, et publier un nouveau thème de genre ou de studio exigerait un **re-balayage TMDB du catalogue entier** — alors que les thèmes d'animation Disney/Pixar/Ghibli et les thèmes de genre sont une famille de thèmes nommée au produit.

| Colonne | Type | Null | Défaut | Raison |
|---|---|---|---|---|
| `id` | bigint unsigned | non | — | § 1.1. |
| `movie_id` | bigint unsigned | non | — | FK `→ movie` `cascadeOnDelete` : pure métadonnée importée, elle peut disparaître avec son film sans perte d'information. |
| `tag_kind` | string(12) + `TmdbTagKind` | non | — | `genre` / `company`. **Deux natures dans une table** plutôt que `movie_genre` + `movie_company` : chaque table coûte un modèle, un PHPDoc exhaustif, une factory typée niveau 7 et une policy — l'argument déjà tenu en A15, appliqué symétriquement. |
| `tmdb_tag_id` | unsignedInteger | non | — | Identifiant TMDB **brut**, jamais un libellé : le nom d'un genre est du contenu traduit et n'a rien à faire ici. Le libellé affiché au joueur est un `theme_label`. |
| `created_at` | timestamp | non | — | **`const UPDATED_AT = null;`** — une étiquette ne se modifie pas, elle apparaît ou disparaît. |

**Clés** — PK `id` · FK `movie_id → movie` `cascadeOnDelete` · UNIQUE `movie_tmdb_tag_uq (movie_id, tag_kind, tmdb_tag_id)`.
**Index** — l'unique, plus l'index **couvrant** `movie_tmdb_tag_rule_idx (tag_kind, tmdb_tag_id, movie_id)`, qui sert l'unique requête chaude de la table : « tous les films portant ce genre / cette société », évaluée à la publication d'un thème et à chaque recalcul de `movie_theme.is_auto`.

Ces lignes sont des **métadonnées TMDB pures** : elles sont écrasées sans exception par une resynchronisation (§ 9.3), aucune exception manuelle ne vit ici — l'exception d'appartenance vit déjà dans `movie_theme.manual_state`.

### 3.7 `theme`, `theme_label`, `movie_theme`

**`theme`** · `id` · `key` string(64) UNIQUE `theme_key_uq` — identifiant technique stable et lisible (`genre.animation`, `decade.1990`, `studio.ghibli`, `saga.star-wars`, `language.international`, `difficulty.hard`), celui que les seeders et les tests nomment · `theme_kind` string(16) + `ThemeKind` — détermine l'interprétation de `rule_value` · `rule_value` string(64) nullable — discriminant de la règle automatique (genre TMDB, année de début de décennie, société, `collection_id`, code de langue, niveau de difficulté). Pour `theme_kind = genre` et `theme_kind = studio`, `rule_value` contient un **`tmdb_tag_id`** et la règle s'évalue localement, sans aucun appel réseau : `EXISTS (SELECT 1 FROM movie_tmdb_tag WHERE movie_id = m.id AND tag_kind = :kind AND tmdb_tag_id = :rule_value)`, servi par `movie_tmdb_tag_rule_idx` (§ 3.6). **Colonne typée et non du JSON, parce que publier une saga ne doit pas exiger un déploiement** · `rule_negated` boolean défaut `false` — rend gratuit le thème « cinéma international » (`original_language <> 'en'`) sans introduire ni liste ni expression à analyser · `is_published` boolean défaut `false` · `sort_order` unsignedSmallInteger défaut 0, ordre d'affichage indépendant de la langue · `timestamps`. Aucun index au-delà de `theme_key_uq` : quelques dizaines de lignes.

**Un thème n'est publiable que s'il porte un libellé dans chaque locale activée** (`05`), sans quoi le joueur verrait son identifiant technique. Le thème **ne porte aucun compteur de films** : le vivier dépend du couple (thèmes, `N`) et un compteur par thème ne s'additionne pas en union.

**`theme_label`** · `id` · `theme_id` FK `cascadeOnDelete` · `locale` string(5) + `Locale` — locale d'**interface** (§ 1.3) : un libellé de thème est une chaîne affichée au joueur, obligatoire dans chaque locale activée pour publier (`05`), jamais une locale de catalogue · `label` string(80) — éditable en back-office · `timestamps` · UNIQUE `theme_label_theme_locale_uq (theme_id, locale)`. Table **absente du lexique normatif** : je la nomme `theme_label` et demande son inscription au lexique, sur le motif `<entité>_<contenu>` déjà porté par `movie_title`. C'est la **troisième et dernière** famille de contenu traduite en base, avec `movie_title` et `alias` ; tout autre texte multilingue vit dans `lang/`.

**`movie_theme`** — l'appartenance, avec la règle et l'exception séparées.

| Colonne | Type | Null | Défaut | Raison |
|---|---|---|---|---|
| `id` | bigint unsigned | non | — | Clé simple et non primaire composite : la ligne porte un auteur et un horodatage, c'est un vrai modèle, et modifier une primaire composite reconstruit la table en SQLite. |
| `movie_id` | bigint unsigned | non | — | FK `→ movie` `cascadeOnDelete`. |
| `theme_id` | bigint unsigned | non | — | FK `→ theme` `cascadeOnDelete`. |
| `is_auto` | boolean | non | `false` | Résultat de la règle automatique. **Réécrit librement** à chaque recalcul et à chaque resynchronisation. |
| `manual_state` | string(8) + `ThemeMembershipState` | oui | `null` | `added` / `removed`. Écrit par un **curateur seul**, jamais par une passe automatique : c'est elle, et elle seule, qui fait survivre l'appartenance manuelle au réimport. |
| `is_active` | boolean | non | `false` | Appartenance **effective** dénormalisée (`manual_state` prime sur `is_auto`). Le vivier a besoin d'un prédicat indexé, pas d'un `OR` à deux branches évalué à chaque frappe du lobby. |
| `assigned_by_id` | bigint unsigned | oui | `null` | Auteur de l'exception manuelle, `nullOnDelete`. Nul pour une ligne purement automatique. |
| `assigned_at` | timestamp | oui | `null` | Date de l'exception, pour trier la file du back-office par ancienneté. |
| `timestamps` | timestamp | non | — | — |

UNIQUE `movie_theme_uq (movie_id, theme_id)`, qui sert aussi le sens inverse (« les thèmes de ce film ») en fiche de curation · index **couvrant** `movie_theme_pool_idx (theme_id, is_active, movie_id)` : la requête de vivier ne touche pas la table.

Une ligne existe dès que la règle matche **ou** qu'une exception existe ; une exception `removed` sur une ligne non automatique est conservée précisément pour qu'un recalcul ne la ressuscite pas. **L'appartenance au thème « difficulté » est projetée ici depuis `movie.movie_difficulty`** : c'est pourquoi `movie_difficulty` ne porte aucun index — le pivot **est** sa forme requêtable.

### 3.8 `movie_certification` — la base unique du filtre de contenu

`id` · `movie_id` FK `cascadeOnDelete` · `country` char(2) (FR, US en v1) · `certification` string(16) — **chaîne brute TMDB conservée telle quelle** (`18`, `-16`, `NC-17`, `X`, `R`) : la réinterpréter à l'import perdrait la preuve de ce que TMDB a réellement répondu · `released_on` date nullable — date de la sortie qui porte cette certification, ce qui fait de « la plus récente fait foi » une règle **vérifiable** : sans elle, Orange mécanique, classé X puis reclassé, serait exclu à tort · `is_restrictive` boolean défaut `false` — dénormalisé à l'écriture (FR -18, US NC-17, US X), c'est lui qui fait basculer `movie.content_flag` en `blocked` sans analyser une chaîne à chaque lecture · `read_at` timestamp NOT NULL — l'écran de différences d'une resynchronisation compare la nouvelle lecture à celle-ci · `timestamps`.

UNIQUE `movie_cert_movie_country_uq (movie_id, country)` : **une seule certification retenue par pays**. Aucun autre index : cette table n'est jamais lue sur un chemin chaud.

Une table plutôt que des colonnes sur `movie`, pour qu'ajouter un troisième pays soit **une ligne et non un ALTER** sur une table sanctuarisée. La résolution « la plus récente » se fait **en PHP** à la lecture de `release_dates`, jamais en SQL ; seule la gagnante est stockée.

Un film sans aucune ligne reste en `content_flag = 'unrated_pending'`, donc **non publiable** jusqu'à la coche de curateur. Découvrir une certification restrictive sur un film déjà curé bascule `content_flag` en `blocked` et **propose** une dépublication : jamais de dépublication automatique, jamais de destruction de fichier — seul un retrait juridique prononcé par un administrateur supprime des fichiers. **Et c'est précisément pourquoi `content_flag` entre dans le prédicat de vivier** (arbitrage A3) : entre le basculement en `blocked` et le passage du curateur — une semaine réaliste pour un bénévole à 10 h/semaine — le film sortirait sinon du filtre tout en restant tiré dans des salons publics.

---

## 4. Banque d'images

La **banque d'images** (`frame_bank`) n'est pas une table : c'est la relation `movie → frame`. **Il n'existe aucune colonne `frame_position`, ni aucune colonne d'ordre sur `frame`** : l'ordre naît du tirage et vit dans `round_tier.tier_index`.

### 4.1 `frame` — une variante d'image

| Colonne | Type | Null | Défaut | Raison |
|---|---|---|---|---|
| `id` | bigint unsigned | non | — | Jamais sérialisé vers un joueur : un identifiant séquentiel permettrait de regrouper les images d'un même film. `#[Hidden]`. |
| `movie_id` | bigint unsigned | non | — | FK `→ movie` **`restrictOnDelete`** : une frame n'est jamais détruite par la disparition d'un film, et un film n'est jamais détruit. |
| `frame_level` | unsignedTinyInteger + `FrameLevel` | non | — | Échelle **fermée** 1-5 qui pilote l'échantillonnage. Plusieurs variantes par niveau, donc **aucune unicité `(movie_id, frame_level)`**. |
| `availability` | string(12) + `ContentAvailability` | non | `draft` | **Même enum à cinq valeurs que `movie`**, un seul axe (arbitrage A1). Le prédicat de jeu est `availability = 'published'` — une **égalité**, donc indexable en tête d'index composite. |
| `availability_changed_at` | timestamp | oui | `null` | Date du dernier changement de régime. L'auteur et le motif vivent dans `admin_action`, jamais dupliqués ici. |
| `first_published_at` | timestamp | oui | `null` | Première mise en jeu, jamais réécrite. **Horodatage, jamais un prédicat.** |
| `published_review_id` | bigint unsigned | oui | `null` | **Référence souple** (§ 1.5) vers la ligne `frame_review` **exacte** qui autorise la publication. Rend la vérification de preuve une jointure par clé au lieu d'une comparaison d'empreintes, et rend un état `published` sans preuve **structurellement visible**. |
| `processing_state` | string(12) + `FrameProcessingState` | non | `pending` | La ligne précède le fichier final, le réencodage Imagick étant un job différé. Sans état, une frame non traitée pourrait entrer en jeu. |
| `processing_error` | string(120) | oui | `null` | **Clé de traduction** de l'échec de job, jamais un message brut : le back-office doit rester lisible par un non-technicien. |
| `source_kind` | string(8) + `FrameSourceKind` | non | — | `tmdb` ou `capture`. Colonne requêtable qui répond en minutes à « combien de vos images viennent de captures » et qui pilote le périmètre du tier froid de sauvegarde. |
| `tmdb_file_path` | string(255) | oui | `null` | Référence du visuel TMDB d'origine, **conservée pour toujours** : c'est elle qui rend l'image retéléchargeable au lieu d'être sauvegardée en octets. `#[Hidden]`. |
| `source_timecode_ms` | unsignedInteger | oui | `null` | Position **dans l'œuvre** pour une capture personnelle. Elle identifie l'œuvre, **pas la méthode d'extraction** — voir l'arbitrage A7. |
| `source_hash` | char(64) | non | — | SHA-256 calculé **par le serveur** sur les octets reçus. L'original non recadré n'est jamais conservé, seule son empreinte l'est. `#[Hidden]`. |
| `published_hash` | char(64) | oui | `null` | SHA-256 du fichier de jeu réellement servi. C'est lui qui lie une `frame_review` à des octets précis et qui **périme la revue après un re-recadrage**. `#[Hidden]`. |
| `crop_x`, `crop_y`, `crop_width`, `crop_height` | unsignedSmallInteger ×4 | non | — | Rectangle conservé, exprimé dans l'espace de la source de re-cadrage 1920 px, pour qu'un re-recadrage soit reproductible **sans l'original**. `#[Hidden]`. |
| `game_path` | string(64) | oui | `null` | Chemin **relatif** au disque `frames` sous le préfixe `game/`, nommé par `bin2hex(random_bytes(16))`. **Chemin strictement interne : il ne quitte jamais le serveur, ni en URL, ni en payload, ni en en-tête.** Ce que le client reçoit est un `round_tier.serve_token` (§ 7.4), lié à une manche et non à une image. Nul tant que le job n'a pas produit le dérivé. UNIQUE. `#[Hidden]`. |
| `master_path` | string(64) | oui | `null` | Chemin relatif sous `master/` (WebP 1920 px), réservé au back-office. **Nom indépendant** de celui de `game_path`, tiré du même CSPRNG (arbitrage A8). UNIQUE. `#[Hidden]`. |
| `game_bytes` | unsignedInteger | oui | `null` | Taille du dérivé servi, **après quantification** : le job de réencodage pousse le conteneur WebP au multiple de 8 Ko supérieur par des octets inertes en queue de RIFF (la taille RIFF borne l'image, les navigateurs les ignorent). Sans cela, `147 231` octets identifie une image aussi sûrement qu'un chemin, et le dictionnaire (chemin → titre) se reconstruit sur la **seule taille servie** (§ 10). C'est la taille paddée qui est servie, et c'est elle que le budget de préchargement de 150 Ko doit compter (principe 6). Tests : `game_bytes % 8192 = 0` et `game_bytes <= 153600`. |
| `game_width`, `game_height` | unsignedSmallInteger ×2 | oui | `null` | Dimensions du dérivé, bornées à 1280 px, vérifiées **côté serveur** après réencodage. |
| `files_deleted_at` | timestamp | oui | `null` | **Attestation** que les deux objets disque ont réellement disparu, écrite par le job **après** vérification `Storage::disk('frames')->missing()`. Voir l'arbitrage A9. |
| `files_deleted_error` | string(120) | oui | `null` | Clé de traduction de l'échec de suppression. |
| `uploaded_by_id` | bigint unsigned | oui | `null` | Auteur de l'ajout (décision 7 : traçabilité image par image). `nullOnDelete`. Suffixe `_id` comme **toute** clé étrangère (§ 1.3). |
| `reviewed_at` | timestamp | oui | `null` | Dernière revue passante, dénormalisée pour la file de re-revue. **File de travail seulement** : la garde de publication n'interroge jamais cette colonne. |
| `review_grid_version` | unsignedTinyInteger | oui | `null` | Version de grille de la dernière revue passante. Comparée à la version courante, elle produit la file « images à re-revoir » par une requête indexée. **File de travail seulement.** |
| `crop_seconds` | unsignedSmallInteger | oui | `null` | Temps de recadrage instrumenté **par image** (décision 10) : une ligne par image permet médiane et p90, un compteur cumulé non. |
| `created_at` / `updated_at` | timestamp | non | — | Date d'ajout (seconde moitié de la trace « auteur et date »), et mutation légitime — `frame` n'est pas une table journal. |

**Clés** — PK `id` · FK `movie_id → movie` `restrictOnDelete` · FK `uploaded_by_id → users` `nullOnDelete` · `published_review_id` **sans contrainte** (§ 1.5) · UNIQUE `frame_game_path_uq (game_path)` et `frame_master_path_uq (master_path)` : un fichier appartient à exactement une frame, les NULL multiples étant acceptés par les deux moteurs.

**Index** — `frame_movie_level_idx (movie_id, frame_level)` : variantes d'un film à un niveau, recalcul de couverture, cascade d'un retrait de film par son préfixe gauche · `frame_grid_version_idx (review_grid_version, availability)` : file « images à re-revoir » · `frame_source_kind_idx (source_kind, availability)` : manifeste du tier froid et réponse à une mise en demeure · `frame_processing_idx (processing_state)` : file des jobs d'image échoués · `frame_availability_idx (availability, movie_id)` · `frame_withdrawn_files_idx (availability, files_deleted_at)` : sonde de réconciliation des retraits · `frame_uploaded_by_idx (uploaded_by_id)` et `index(published_review_id)`, explicites.

**Aucun index sur `source_hash` ni `published_hash`** : le dédoublonnage se fait dans le film via le préfixe `movie_id`, le manifeste par balayage nocturne, et deux index de 256 octets de plus sur une table sanctuarisée ne se justifient pas.

**Ce que `frame` ne contient pas** : aucune colonne d'ordre ; aucune colonne EXIF (`stripImage` systématique, `ext-exif` absent) ; **aucune colonne de support, d'édition, d'appareil, de logiciel ou de format d'origine** (arbitrage A7) ; aucun chemin absolu, aucune URL, aucun titre dans un chemin.

**`Frame::isServable()`** — prédicat de **catalogue**, et rien de plus :

> `availability === Published` **ET** `processing_state === Ready` **ET** `game_path IS NOT NULL`

C'est le **même prédicat, mot pour mot**, que celui qui compte une variante jouable dans `movie_projection` (§ 3.2). Un prédicat de comptage plus permissif que le prédicat de service fabriquerait des films qui passent la garde de vivier et cassent une manche.

> **`isServable()` n'est pas le prédicat de sécurité du moteur, et le qualifier ainsi est la faille.** Il ne porte **aucune condition temporelle** et ne connaît **aucun demandeur** : un développeur qui le lit comme suffisant signe les `N` URL d'un coup à chaque resynchronisation — et la resynchronisation est déclenchable par le client à volonté, par un simple rechargement. Recharger à `t = 1 s` d'une manche à `N = 5` livrerait alors les cinq URL, dont celle du plan iconique, pour une réponse à 1,5 s au barème du palier 1.
>
> **Prédicat de signature, en trois parties, écrit ici une fois et cité aux § 7.4, § 10 et § 12 :**
>
> 1. `Frame::isServable()` — la frame est au catalogue et son dérivé existe ;
> 2. `RoundTier::isOpenForServing($now, $game->preload_lead_ms)` — `now >= round.started_at + round_tier.starts_at_offset_ms − game.preload_lead_ms`, **y compris pour `i = 1`** (§ 10) ;
> 3. **appartenance du demandeur à cette manche**, résolue par le siège — c'est le `round_tier.serve_token` qui porte le lien, jamais un chemin de fichier (§ 7.4).
>
> Les trois, ensemble, à chaque service. La condition 2 est celle qui manque le plus souvent, et c'est elle qui borne l'exploit.

### 4.2 `frame_review` — la preuve opposable

Table en **ajout seul**. Une frame accumule une ligne par passage de revue, jamais une mise à jour.

| Colonne | Type | Null | Raison |
|---|---|---|---|
| `id` | bigint unsigned | non | L'ordre des identifiants est l'historique. |
| `frame_id` | bigint unsigned | non | FK `→ frame` **`restrictOnDelete`** : une frame n'est jamais supprimée, donc la preuve n'est jamais orpheline. |
| `reviewer_id` | bigint unsigned | oui | Curateur, `nullOnDelete`. |
| `reviewer_name` | string(255) | **non** | **Instantané de `users.name` à l'instant de la revue.** L'engagement 2 du principe 12 est « une ligne de revue immuable attribuée à un curateur **nominatif** » : avec une seule clé étrangère, l'anonymisation d'un compte — suppression en libre-service ou dormance — réduit la preuve à « relecteur n° 42 ». Conservée, et **explicitement exclue de l'anonymisation**, sur la même base légale que `takedown_request.requester_name`. |
| `reviewer_role` | string(10) + `UserRole` | non | Rôle réellement porté **à l'instant de la revue**, estampillé sur la ligne : la preuve devient autoportante sans interroger l'historique des rôles. |
| `grid_version` | unsignedTinyInteger | non | Version de la grille d'exclusion appliquée. Une colonne de version et non un booléen, sinon la file de re-revue est incalculable. |
| `decision` | string(10) + `ReviewDecision` | non | `passed` / `rejected`. Requêté pour la file des images rejetées et pour la condition de publication. |
| `reviewed_hash` | char(64) | non | Empreinte des octets examinés. Elle lie la revue à un fichier précis : un re-recadrage change `published_hash` et **périme mécaniquement la revue, sans qu'aucune ligne ne soit modifiée**. |
| `declared_source_kind` | string(8) + `FrameSourceKind` | non | Instantané de la source déclarée **au moment de la revue** : une source déclarée après coup n'aurait aucune valeur de preuve. |
| `declared_source_reference` | string(255) | oui | Instantané de la référence déclarée (chemin de fichier TMDB, ou timecode textuel). **Jamais l'outil ni la méthode d'extraction.** |
| `answers` | json | non | Réponses item par item, clées par **slug d'item** de la grille, donc relisibles sans le code de l'ancienne version. Jamais interrogées en SQL (§ 1.6). |
| `reviewed_at` | timestamp | non | Seule colonne temporelle : l'absence d'`updated_at` est le **signal structurel** de l'immuabilité. `$timestamps = false`. |

**Clés** — PK `id` · FK `frame_id` `restrictOnDelete` · FK `reviewer_id` `nullOnDelete` · **aucune unicité** : plusieurs revues successives sont la règle.
**Index** — `frame_review_frame_idx (frame_id, id)` · `frame_review_version_idx (grid_version, decision)` · `frame_review_reviewer_idx (reviewer_id)`.

**Immuabilité par convention applicative outillée, pas par déclencheur SQL** : `$timestamps = false`, aucune `updated_at`, aucune `deleted_at`, une Policy refusant `update` et `delete`, et un garde sur l'évènement `saving` quand le modèle existe déjà. Un déclencheur s'écrit différemment en MySQL et en SQLite, donc ne serait jamais exercé par la suite de tests : ce serait une **immuabilité fictive**.

**Condition de publication d'une frame, et elle seule :** il existe une ligne `decision = 'passed'` dont `reviewed_hash = frame.published_hash` et `grid_version` = version courante, et `frame.published_review_id` désigne cette ligne. **La garde de publication interroge `frame_review`, jamais les copies `reviewed_at` / `review_grid_version` de `frame`**, qui sont une file de travail écrite par le seul observateur qui insère la ligne de revue, dans la même transaction. Sonde de production quotidienne, au même rang que la sonde de purge : `COUNT(frame WHERE availability = 'published' AND published_review_id IS NULL)` doit valoir **0**.

**La grille d'exclusion n'a pas de table.** Son contenu item par item est spécifié par `20` et **versionné en code** (`App\Support\Curation\ExclusionGrid`, constante entière `CURRENT_VERSION`, chaque version passée restant un tableau figé du dépôt). Une grille éditable en base permettrait de réécrire le libellé d'un item qu'une revue a déjà cité, ce qui détruirait sa valeur de preuve. Le schéma ne fournit que le **numéro de version** et le **stockage des réponses par slug**.

### 4.3 Suspension, dépublication et retrait : un seul axe, cinq états

| Valeur de `availability` | Qui la pose | Réversible | Effet |
|---|---|---|---|
| `draft` | création | — | Hors vivier. État initial d'une frame comme d'un film. |
| `published` | curateur | oui | **Seule valeur qui met en jeu.** Sur `movie`, exige **les deux ensemble** : `content_flag = 'clear'` **ET** `movie_projection.levels_mask & 21 = 21` (couverture des niveaux 1, 3 et 5 par au moins une variante jouable au sens du prédicat unique du § 3.2). L'éligibilité à un `N` donné, elle, n'est **jamais** stockée : elle se dérive de `levels_count >= N`. Sans la seconde moitié, un film couvrant 1, 2 et 4 se publierait et le prédicat de vivier ne le filtrerait pas — il ne teste que `availability`, `content_flag` et `levels_count >= N`, satisfait par n'importe quels `N` niveaux. Sur `frame`, exige une revue passante sur les octets courants. |
| `unpublished` | curateur | oui | Dépublication de curation, motivée. |
| `suspended` | **admin seul** | oui | Suspension conservatoire en un clic (engagement 4 du principe 12). |
| `withdrawn` | **admin seul** | **non, terminal** | Retrait juridique. Aucune transition n'en sort. |

**Cascade d'un retrait ou d'une suspension, dans une seule transaction** : la ligne `movie` ou `frame` change d'état ; les `frame` du film suivent ; les `seen_frame` des frames concernées sont **supprimés explicitement** ; une ligne `admin_action` est écrite en `retention_class = 'permanent'`. Le film sort du vivier **à la seconde**, ce qui peut rebloquer un lobby déjà débloqué. Pour un retrait juridique seulement, un job supprime ensuite les deux fichiers puis pose `files_deleted_at`.

**Une manche en cours n'est jamais réécrite.** `round_tier` garde son tirage figé ; la seule question posée à l'exécution est `Frame::isServable()` **au moment de signer l'URL**. Si elle est fausse, le moteur emprunte le chemin d'échec technique de `60` : substitution d'une variante du même niveau — tracée dans `round_tier.served_frame_id` (§ 7.4) —, sinon annulation de la manche sans points. Réécrire un tirage figé recalculerait un score différent au rejeu, ce que la matérialisation existe pour empêcher.

---

## 5. Comptes et identité

### 5.1 `users` — deux migrations ALTER, dans cet ordre, et rien d'autre

**Migration 1, `alter_users_email_and_password_nullable`** : exactement **deux `->nullable()->change()`**, et rien d'autre.
**Migration 2, `add_account_columns_to_users`** : **uniquement des ajouts**.

Ne jamais mêler `change()` et ajouts dans un même `Schema::table` : `change` est un `alterCommand`, donc SQLite reconstruit la table entière en recopiant **par nom de colonne** et en recréant les index, là où MySQL fait un `MODIFY` — deux chemins différents pour une seule migration. Les deux se placent **avant toute table de domaine**.

| Colonne | Type | Null | Défaut | Raison |
|---|---|---|---|---|
| `id` | bigint unsigned | non | — | Starter conservé : `passkeys.user_id` et `sessions.user_id` la référencent, `types/auth.ts` déclare `id: number`. |
| `name` | string(255) | non | — | Pseudo persistant du **compte**, NOT NULL hérité du starter : l'anonymisation le remplace par un jeton neutre stable au lieu de le vider. **Distinct de `player.nickname`**, le pseudo affiché **dans** un salon — deux faits différents, ce que le gel du pseudo pendant une partie confirme. Renommer casserait Fortify et `types/auth.ts`. |
| `email` | string(255) | **oui** ← ALTER | `null` | Discord peut ne retourner aucun e-mail, et l'anonymisation doit pouvoir l'effacer sans casser l'unicité. |
| `email_verified_at` | timestamp | oui | — | Inchangée. Preuve de vérification pour la liaison automatique OAuth ; jamais un test d'accès quand `email` est NULL. |
| `password` | string(255) | **oui** ← ALTER | `null` | Un compte créé par OAuth n'a pas de mot de passe. `EloquentUserProvider` et `AbstractHasher` renvoient déjà `false` sur un haché nul : aucune faille n'est ouverte. |
| `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` | Fortify | oui | — | Inchangées. `two_factor_confirmed_at` rend requêtable « rôle privilégié sans second facteur » sans nouvelle colonne. |
| `remember_token` | string(100) | oui | — | Starter. Vidée à l'anonymisation. |
| `role` | string(20) + `UserRole` | non | `player` | Décision 9 : trois rôles hiérarchiques, une colonne unique. `string` et non ENUM natif pour qu'un quatrième rôle ne coûte pas un ALTER sur la table la plus référencée. **Hors `#[Fillable]`** : élévation de privilège par requête. |
| `locale` | string(5) + `Locale` | non | `en` | `HasLocalePreference` doit renvoyer une locale activée **hors de toute requête HTTP** (les mails partent en file) : non nullable avec le repli d'instance en défaut, jamais résolue à la lecture. Seule colonne ajoutée à `#[Fillable]`. |
| `plan` | string(20) + `Plan` | non | `free` | Décision 2 : une colonne à valeur unique, pour qu'une frontière de confort future ait un domicile **sans table de facturation ni feature flag**. `#[Hidden]`. |
| `avatar_kind` | string(20) + `AvatarKind` | oui | `null` | Nature effective ; **NULL = repli initiales**. C'est elle qui rend « jamais par-dessus un avatar prédéfini explicitement choisi » décidable sans heuristique. |
| `avatar_preset` | string(**40**) | oui | `null` | **Clé stable** de l'avatar prédéfini, jamais un chemin de fichier : remplacer le pack de 24 fichiers doit être une migration de valeurs, pas une casse de données. **Même nom et même longueur que `player.avatar_preset` et `game_player.display_avatar_preset`** (§ 1.3) : le lexique ne connaît que `avatar_preset`, et une longueur plus courte ici ferait passer en SQLite une clé que MySQL strict refuserait en 1406 sur le compte alors que le siège l'accepte. |
| `avatar_provider_path` | string(191) | oui | `null` | Chemin **relatif** de la copie locale de la photo Discord/Google sur le disque `avatars`, nommé par ULID. Aucune URL distante n'est jamais servie au client. `#[Hidden]`. |
| `avatar_provider_hidden_at` | timestamp | oui | `null` | Masquage à deux signalements distincts. **Survit à la suppression du fichier**, bloque le re-téléchargement, levable par un admin seul : ce n'est pas un drapeau d'affichage. `#[Hidden]`. |
| `terms_accepted_at` | timestamp | oui | `null` | **Projection de la dernière ligne `user_consent`**, pour la garde d'affichage à la connexion. `#[Hidden]`. |
| `terms_version` | string(20) | oui | `null` | Idem. L'**historique** vit dans `user_consent` (§ 5.4). `#[Hidden]`. |
| `age_confirmed_at` | timestamp | oui | `null` | Déclaration d'âge minimum (15 ans), fait distinct de l'acceptation des CGU. Projection, historique dans `user_consent`. `#[Hidden]`. |
| `anonymized_at` | timestamp | oui | `null` | Pierre tombale : exclut le compte de la connexion, du décompte « dernier admin » et du balayage de dormance, alors que la ligne survit pour l'intégrité référentielle. `#[Hidden]`. |
| `last_login_at` | timestamp | oui | `null` | Pilote la dormance (24 mois, puis anonymisation 30 jours plus tard). Fenêtre volontairement distincte des 12 mois, d'où une colonne qui n'est **pas** `updated_at`. `#[Hidden]`. |
| `created_at` / `updated_at` | timestamp | oui | — | Starter. |

**Clés** — PK `id` · UNIQUE `users_email_unique (email)`, **index existant conservé tel quel par le `change()`** : il porte à lui seul l'unicité conditionnelle, MySQL 8 n'ayant aucun index partiel, et plusieurs pierres tombales à `email = NULL` cohabitent. Aucune FK sortante, aucune FK entrante depuis un fait de partie.
**Index** — `users_role_last_login_at_index (role, last_login_at)`, qui sert **trois** requêtes par sa colonne de tête : balayage de dormance (`role = 'player' AND anonymized_at IS NULL AND last_login_at < X`), garde du dernier admin (`role = 'admin' AND anonymized_at IS NULL`), file « rôle privilégié sans 2FA » (`role IN ('curator','admin') AND two_factor_confirmed_at IS NULL`).

### 5.2 Ce que l'ALTER casse, réparé dans le même lot

| Point | Réparation |
|---|---|
| `ProfileValidationRules::emailRules()` commence par `'required'` et est **partagé** par `CreateNewUser` et `ProfileUpdateRequest` | Le trait garde ce jeu pour la voie Fortify et gagne une **seconde méthode** tolérant l'absence d'e-mail, utilisée par le profil d'un compte OAuth. |
| `MustVerifyEmail` face au middleware `verified` | `User::hasVerifiedEmail()` renvoie `true` quand `email IS NULL`, et `sendEmailVerificationNotification()` y devient inopérant. **Le périmètre n'est pas « la suppression de son propre compte » mais toute route sous `verified`** — `routes/web.php:7` (le tableau de bord) et `routes/settings.php` : sans ce correctif, un compte Discord sans e-mail est renvoyé en boucle sur `/email/verify`, qui lui propose d'envoyer un e-mail à une adresse nulle. |
| `resources/js/types/auth.ts` déclare `email: string` et `avatar?: string` | Passe à **`email: string \| null`** et **`avatar: string \| null`** dans le même commit. `npm run types:check` reste vert avec les déclarations actuelles — ce sont des déclarations, pas des inférences : le mensonge ne se voit qu'à l'exécution. S'y ajoutent `defaultValue={auth.user.email ?? ''}` dans `settings/profile.tsx` (un `null` sur un input `defaultValue` le rend non contrôlé) et un garde d'affichage dans `user-info.tsx`. |
| `password_reset_tokens` (PK = l'e-mail, aucune FK), `sessions.user_id` (aucune FK), `passkeys` (cascade qui ne se déclenchera jamais) | Purgés **explicitement** par l'action d'anonymisation. |

### 5.3 Avatar : deux natures, un accesseur, et le piège Eloquent

La résolution vit dans **un accesseur serveur unique** renvoyant `App\Avatars\AvatarRef` (`kind`, `url`, `altKey`, `initials`), qui applique la chaîne : `avatar_kind = preset` et `avatar_preset` non nul → fichier statique de `public/` ; sinon `avatar_kind = provider`, `avatar_provider_path` non nul et `avatar_provider_hidden_at` nul → URL publique cacheable de la copie locale ; sinon initiales de `users.name`.

> **Deux objets, deux noms, et c'est une contrainte du framework, pas une préférence.** `HasAttributes::hasAttributeMutator()` exige que la méthode porte le type de retour exact `Illuminate\Database\Eloquent\Casts\Attribute` ; un `public function avatar(): AvatarRef` n'est pas reconnu comme mutateur, `mutateAttributeForArray()` retombe sur `getAvatarAttribute()` et **toute page authentifiée renvoie 500** — `HandleInertiaRequests::share()` sérialise `$request->user()` partout, écran de jeu compris. Et un `Attribute` qui renverrait `AvatarRef` produirait `"avatar":{"url":…}`, que `user-info.tsx` poserait en `src="[object Object]"`.
>
> Donc : **`public function avatarRef(): AvatarRef`** pour le domaine, jamais nommée `avatar()` ; et **`protected function avatar(): Attribute`** renvoyant `Attribute::get(fn () => $this->avatarRef()->url)`, de type `string|null` strict, exposée par `#[Appends(['avatar'])]`. Test Pest obligatoire : `User::factory()->create()->toArray()['avatar']` est une chaîne ou `null`, jamais autre chose.

Ajouter la **troisième nature en v1.1** coûte un cas d'enum, une colonne nullable neuve et une branche de l'accesseur : aucune ligne existante n'est réécrite, aucun écran n'est touché. **Aucune table ni colonne `avatar_upload` n'est créée par anticipation** (décision 14).

**Disque des avatars, et reformulation de l'interdiction de `CLAUDE.md` §8.** Les avatars sont **publics et cacheables** ; `ServeFile` émet `Cache-Control: no-store`, inadapté. Ils vivent donc sur un disque dédié `avatars` (driver `local`, `visibility` public, racine `storage/app/public/avatar`), exposé par `storage:link`, chemin relatif en base, nommage par ULID ; les 24 prédéfinis restent en statique dans `public/`. **La règle à graver n'est pas « jamais `storage:link` » mais « aucune frame sur le disque public, jamais ».**

### 5.4 `linked_account`, `user_consent`, `data_export`

**`linked_account`** · `id` · `user_id` FK `cascadeOnDelete` · `provider` string(20) + `OAuthProvider` — `string` et non ENUM pour qu'un troisième fournisseur ne coûte pas un ALTER, et parce que Discord n'est pas un driver Socialite natif : la valeur doit rester stable indépendamment du paquet communautaire · `provider_user_id` string(**191**) — borné pour que l'unicité composite (20 + 191) × 4 = 844 octets reste loin des 3072 · `provider_email` string(255) nullable — conservé pour expliquer une liaison passée, supprimé à la déliaison et à l'anonymisation, **jamais sérialisé vers le client** · `provider_email_verified` boolean défaut `false` — la liaison automatique n'est permise que sur un e-mail déclaré vérifié ; sans cette trace, on ne peut plus justifier une liaison après coup · `suggested_nickname` string(50) nullable — **une suggestion à valider, jamais une valeur appliquée**, d'où une colonne distincte de `users.name` · `provider_avatar_url` text nullable — source du téléchargement différé, lue par le seul job, **jamais servie au client** (pas de hotlink : URL instable et fuite de l'IP du joueur vers un tiers en pleine partie) · `timestamps`.

UNIQUE `linked_account_provider_uid_uq (provider, provider_user_id)` — un compte fournisseur lié à un seul `User` ; c'est l'index du chemin chaud du callback OAuth. UNIQUE `linked_account_user_provider_uq (user_id, provider)` — un `User` a au plus un compte par fournisseur. Index `linked_account_user_idx (user_id)` explicite.

**Aucune adresse IP, aucun jeton d'accès ni de rafraîchissement** : la v1 ne rappelle jamais l'API du fournisseur après le callback, hors téléchargement unique de la photo. **La déliaison supprime la ligne**, elle ne la marque pas : identifiant et e-mail provider disparaissent réellement, et l'avatar effectif redescend la chaîne de repli par recalcul de l'accesseur, sans écriture.

**`user_consent`** — table **nouvelle**, en ajout seul, exigée parce qu'une seule paire de colonnes porte le consentement **courant** et qu'une nouvelle version des CGU écrase la précédente : la colonne conservée à travers l'anonymisation prouverait alors la mauvaise version.

`id` · `user_id` FK `cascadeOnDelete` + index · `kind` string(20) + `ConsentKind` (`terms`, `age`) · `version` string(20) · `accepted_at` timestamp NOT NULL · `created_at` (`const UPDATED_AT = null`) · UNIQUE `user_consent_user_kind_version_uq (user_id, kind, version)`. **Hors périmètre de purge**, et **conservée à l'anonymisation** : une fois `users` vidé, elle ne porte plus aucun identifiant direct et reste la preuve d'une base légale.

**`data_export`** — table **nouvelle**, exigée par l'engagement « exporter ses parties en un clic, archive générée en job différé, URL signée valable 7 jours » (principe 12). Le fichier produit est le concentré de données personnelles le plus dense que le système fabrique ; sans table, il n'est référencé nulle part, survit à la suppression du compte, part dans chaque sauvegarde et n'apparaît dans aucun tableau de conservation.

`id` · `user_id` FK `cascadeOnDelete` + index · `path` string(64), nommé par ULID · `size_bytes` unsignedInteger nullable · `requested_at` timestamp NOT NULL · `completed_at` timestamp nullable · `expires_at` timestamp NOT NULL · `downloaded_at` timestamp nullable · `deleted_at` timestamp nullable · `timestamps` (§ 1.7) · index `data_export_expiry_idx (expires_at)`. Périmètre de purge `data_export`, piloté par `expires_at`, qui **supprime le fichier puis la ligne**. L'action d'anonymisation supprime les archives du compte **avant** de vider `users`.

### 5.5 Anonymisation — pierre tombale, liste close

| Geste | Colonnes / lignes |
|---|---|
| **Vidés** | `email`, `email_verified_at`, `password`, les trois colonnes 2FA, `remember_token`, les quatre colonnes d'avatar (**fichier local supprimé du disque** — c'est un visage, il ne s'anonymise pas), `role` forcé à `player`, `locale` ramenée au repli, `name` remplacé par un jeton neutre stable. |
| **Conservés** | `id`, `plan`, `anonymized_at` posé, les trois projections de consentement, `last_login_at`, et **toutes** les lignes `user_consent`. |
| **Supprimés en lignes** | `linked_account`, `saved_config`, `data_export` (fichiers compris), `passkeys`, `password_reset_tokens` de l'ancienne adresse, `sessions` du compte. |
| **Détachés et effacés** | `player.user_id` passé à NULL **et**, dans la même transaction, `player.nickname` + `player.player_token_hash` de **tous** les sièges du compte, plus `game_player.display_nickname` des parties correspondantes. |
| **Jamais touchés** | `frame_review.reviewer_id` **et `reviewer_name`**, `admin_action.actor_id` **et `actor_name`**, les faits de partie des autres joueurs. |

Conserver l'`id` rend les clés d'auteur **structurellement inorphelinables** sans jamais dépendre d'un `nullOnDelete` ; les consentements survivent parce qu'une fois l'e-mail et le nom effacés ils n'identifient plus personne ; et comme `game_player` et `guess` ne référencent **jamais** `users`, le podium et l'historique des autres joueurs restent intacts.

**L'effacement des identifiants d'invité du § 5.5 est le second des deux déclencheurs** — le premier étant `room.archived_at` (§ 11). Sans lui, une joueuse qui s'entraîne en solo sous un pseudo contenant son nom, puis supprime son compte, laisse ce pseudo douze mois dans une table décrite comme anonyme et dans chaque sauvegarde quotidienne. Test Pest nommé : après anonymisation d'un compte ayant joué en solo **et** dans un salon non archivé, aucune ligne `player` ni `game_player` du compte ne porte de pseudo ni de hash de jeton.

**Le parcours de suppression de compte affiche un écran spécifique** à tout compte portant au moins une ligne `frame_review` ou une ligne `admin_action` permanente, qui énonce ce qui est conservé et pourquoi.

---

## 6. Salon et réglages

### 6.1 `RoomSettings` — un value object, aucune table `room_settings`

Le lexique dit « détachable », pas « détachée ». **Il n'existe aucune table `room_settings`** : une table ajouterait une jointure sur l'objet le plus chaud et une question de cycle de vie (ligne partagée ou copiée ?) que rien ne réclame.

`App\Settings\RoomSettings`, `final readonly`, sérialisé par `App\Casts\RoomSettingsCast implements CastsAttributes<RoomSettings, RoomSettings>` dans **quatre** colonnes `json` : `room.settings`, `setting_preset.settings`, `saved_config.settings`, `game.settings_snapshot`. Chacune est doublée d'une colonne `settings_version` **en clair**.

**Le nom est `RoomSettings`, pas `GameSettings`.** `CLAUDE.md` §5 cite `GameSettings` : c'est à corriger dans le même lot. Le lexique fait loi, et `GameSettings` serait un piège actif — la partie fige sa **propre** copie au lancement, donc deux objets de sens différents porteraient le même nom sur deux tables voisines.

**Contenu** : `themeIds: list<int>`, `roundsCount`, `framesPerRound`, `tierDurations: list<int>` (`N` entrées, secondes entières, la dernière absorbant le reste), `tierPoints: list<int>` (`N` entrées, 0-1000), `revealDuration`, `inputDifficulty`, `capacity`, `allowLateJoin`, `speedBonus`, `noRepeatMovies`, `attemptsPerSecond`, `attemptsPerRound`, `maxAnswerLength`, `disconnectGraceSeconds`, `advanced: bool`.

**`D` n'est pas un champ** : `D = array_sum(tierDurations)`, une seule source. En onglet Avancé `D` **devient** la somme des paliers ; garder les deux ouvrirait une divergence silencieuse entre 40 s annoncés et 13 + 13 + 14 matérialisés, et `round_tier` se remplit alors par copie directe.

**Deux chemins de lecture, jamais confondus.**

| Chemin | Méthode | Comportement |
|---|---|---|
| **ENTRÉE** (formulaire) | `RoomSettings::fromInput()` | **Seul constructeur produisant une instance valide.** Valide bornes simples **et** bornes croisées 1, 2, 4 et 5 **ensemble**, et **refuse**, en renvoyant les erreurs indexées par champ. Les FormRequest des deux onglets délèguent entièrement via une règle `ValidRoomSettings`. |
| **CHARGEMENT** (`saved_config`, preset) | `RoomSettings::normalize($raw, $version)` | Applique la chaîne ordonnée de normaliseurs de `$version` à la version courante et **ne refuse jamais** : champ ajouté = défaut, champ retiré = abandonné, borne resserrée = écrêtage, thème disparu = retiré de la liste, paliers réégalisés si `N` a changé. Chaque modification est rendue **champ par champ** dans un rapport affiché à l'utilisateur. |

Un FormRequest champ par champ laisserait passer 10 s × 5 images ; un `final readonly` qui peut exister dans un état invalide ne vaut rien. Mais **refuser au chargement** rendrait 20 configurations inutilisables à la première borne resserrée, ce que la règle « jamais réécrite en base » interdit de corriger par migration.

> **La normalisation n'a pas lieu dans le cast, et c'est ce qui rend « jamais réécrite en base » tenable.** `Model::save()` appelle `mergeAttributesFromCachedCasts()`, qui rappelle `CastsAttributes::set()` sur l'objet mis en cache par `get()` : si `get()` normalisait, renommer une configuration réécrirait son JSON écrêté sans confirmation, et `settings_version` n'étant pas remontée, le chargement suivant rejouerait la chaîne sur des données déjà normalisées. Donc : **`get()` désérialise fidèlement à la version d'origine et ne modifie rien** ; `normalize()` est un appel **explicite** de l'action de chargement dans un lobby, dont le résultat n'est jamais réinjecté dans le modèle. Et **`set()` retourne les deux colonnes ensemble** — `['settings' => …, 'settings_version' => RoomSettings::VERSION]` — de sorte que l'état « JSON normalisé + version périmée » soit impossible. Test : charger une `saved_config` v1 sous une borne resserrée, appeler `$config->touch()`, vérifier que la colonne `settings` est **inchangée octet pour octet**.

**Où vivent les bornes, en un seul endroit côté serveur.**

- `App\Settings\RoomSettingsBounds`, `final readonly` : `M` 3-30 (défaut 10), `N` 2-5 (défaut 3), `D` 10-120 s (défaut 30), `R` 3-20 s (défaut 8), durée de palier ≥ 5 s, valeur de palier 0-1000 avec défaut `(N − i + 1) × 100`, capacité 2-12, tentatives/s 1-5, tentatives/manche 5-50 avec défaut `ceil(D / 2 s)` plafonné à 30, longueur de réponse 20-200 (défaut 100), **délai de déconnexion `disconnectGraceSeconds` 15-180 s (défaut 60)** — le réglage d'hôte, jamais la fenêtre de frontière de palier (§ 1.3) —, `speedBonus` défaut **activé**, `noRepeatMovies` défaut **activée**, `allowLateJoin` défaut **non**, `inputDifficulty` défaut **normal**, `advanced` défaut **faux**, `themeIds` défaut **liste vide** (tout le catalogue) — la branche sans thème est la plus fréquente du lobby (§ 12). Consommé par le validateur **et** partagé en prop Inertia, pour que les curseurs du client affichent exactement les mêmes min/max.

> **La liste est close :** tout champ de `RoomSettings` a un défaut déclaré ici, et un test parcourt les **seize** champs pour le vérifier. Un champ sans défaut rendrait `RoomSettings::normalize()` incapable de traiter le cas « champ ajouté = défaut » — et `speedBonus` comme `noRepeatMovies`, qui n'ont **aucune colonne de projection** pour les rattraper (contrairement à `allowLateJoin` et `inputDifficulty`), partiraient indéfinis sur tout salon créé sans preset. Le § 12 s'appuie pourtant explicitement sur le second pour justifier la clause `NOT EXISTS` dans les **deux** branches de la requête de vivier.

- `App\Settings\PlatformLimits`, `final readonly` : plafonds non réglables — `savedConfigsPerUser = 20`, `roomSeats = 12`, `avatarPresets = 24`, `historyWindowMonths = 12`, `successRateMinRounds = 20`, `speedBonusMaxFraction = 0.50`, **`tierGraceMs = 300`**, **`preloadLeadMs`** (1 500 à 2 500 ms, **jamais la durée d'un palier**), **`frameUploadMaxKilobytes = 1536`**. Construit depuis `config/`, jamais une colonne, jamais un littéral dans une migration ni un FormRequest. `B_max = 50 %` y vit et non dans `room_settings` : l'hôte n'a qu'un interrupteur on/off, pas un curseur.
  - `tierGraceMs` et `preloadLeadMs` sont ici **et non dans `RoomSettings`** pour la raison qui vaut déjà pour `speedBonusMaxFraction` : l'unique constructeur valide de `RoomSettings` est `fromInput()`, alimenté par le formulaire de l'hôte. Un hôte qui posterait `graceMs: 120000` s'achèterait le palier 1 pour toute la manche, et la borne ne tiendrait plus que par un validateur au lieu d'être **structurellement inatteignable**. Les trois autres porteurs du value object (`room`, `setting_preset`, `saved_config`) sont des objets d'hôte éditables et sauvegardables : y loger une constante serveur la rendrait réglable par la voie du JSON. Leur domicile est une colonne de `game`, écrite au lancement (§ 7.2). Deux tests : `RoomSettings::fromInput()` recevant une clé `graceMs`, `tierGraceMs` ou `preloadLeadMs` est **refusé** ; une partie lancée par un hôte hostile porte toujours les constantes de plateforme dans `game.tier_grace_ms` et `game.preload_lead_ms`.
  - `frameUploadMaxKilobytes = 1536` (décision 8) est **très en dessous** d'`upload_max_filesize = 2M`, pour qu'un dépassement soit toujours une erreur de validation traduite et jamais un 419 muet — dépasser `post_max_size` vide `$_POST`, donc le jeton CSRF. Le recadreur du back-office n'envoie de toute façon qu'un dérivé de 80 à 150 Ko : le plafond est une **garde**, pas une cible. C'est un plafond d'**entrée**, distinct des deux plafonds de **sortie** du § 10.

> **`PlatformLimits` n'est pas « sans version parce qu'aucune de ses valeurs n'est persistée ».** C'est faux, et pour trois colonnes au moins : `guess.points_bonus` est **écrit sous** `speedBonusMaxFraction`, `guess.tier_index` est **écrit sous** `tierGraceMs`, et un palier servi l'est sous `preloadLeadMs` — les trois conservés douze mois. Deux colonnes sur `game` portent donc la version de règle, et toute modification de l'une de ces valeurs incrémente `scoring_version` (§ 7.2).

**La borne croisée 3, `pool(thèmes, N) ≥ M`, n'est pas dans le value object** : elle dépend du catalogue, que l'objet ne voit pas. Elle est une **garde de lancement dédiée**, appelée à chaque écriture de réglage dans le lobby — pour afficher le compteur et bloquer — **et rejouée dans la transaction qui fige le tirage**, la seconde faisant seule autorité. Le vivier peut rétrécir entre les deux : un retrait juridique en sort un film à la seconde.

### 6.2 `room`

| Colonne | Type | Null | Défaut | Raison |
|---|---|---|---|---|
| `id` | bigint unsigned | non | — | Jamais exposée dans une URL ni une payload : un identifiant séquentiel public révélerait le volume de salons créés. `#[Hidden]`. |
| `room_code` | char(6) | non | — | Code court **permanent**, alphabet non ambigu, **normalisé en majuscules en PHP avant toute écriture et toute requête** (MySQL est insensible à la casse, SQLite en BINARY non). Conservé après archivage pour rester affichable dans l'historique. |
| `room_code_active` | char(6) | oui | `= room_code` | **Créneau d'unicité** : mis à NULL à l'archivage. C'est ce qui recycle le code **sans index partiel**. |
| `status` | string(20) + `RoomStatus` | non | `lobby` | `lobby` / `playing` / `archived`. **Cycle de vie du salon seulement** ; le statut d'une partie vit sur `game`. |
| `host_player_id` | bigint unsigned | oui | `null` | Hôte courant. **Référence souple sans contrainte de FK** (§ 1.5). Réécrite exclusivement par l'action de transfert d'hôte, qui s'exécute aussi au départ du dernier hôte et à l'archivage. Une lecture qui ne trouve pas de `player` correspondant déclenche un transfert, jamais une erreur. |
| `capacity` | unsignedTinyInteger | **non, sans défaut** | — | Projection typée : lue et comparée à l'effectif à chaque prise de siège, sans désérialiser un value object dont la version peut être antérieure. |
| `frames_per_round` | unsignedTinyInteger | **non, sans défaut** | — | Projection typée : `N` paramètre la requête de vivier à chaque changement de thèmes et au lancement. |
| `rounds_count` | unsignedTinyInteger | **non, sans défaut** | — | Projection typée. **Le nom est `rounds_count` partout**, jamais `rounds_planned` : `05` le cite nommément parmi les attributs de validation exposés au joueur. |
| `input_difficulty` | string(10) + `InputDifficulty` | **non, sans défaut** | — | Projection typée : lue par le serveur à chaque soumission de réponse. |
| `allow_late_join` | boolean | **non, sans défaut** | — | Projection typée : même garde d'entrée que `capacity`, même chemin chaud. |
| `settings` | json | non | — | Value object complet. Jamais requêté à l'intérieur. |
| `settings_version` | unsignedSmallInteger | **non, sans défaut** | — | **En colonne, pas dans le JSON** (§ 1.6). `#[Hidden]`. |
| `launched_at` | timestamp | oui | `null` | Instant du **premier** lancement jamais effectué dans ce salon : c'est lui, et non une jointure sur `game`, qui distingue la purge du lobby jamais lancé (2 h, périmètre `stale_lobby`, § 11.1) de l'archivage (24 h). |
| `last_activity_at` | timestamp | non | — | Pilote les deux échéances, comptées depuis la **dernière activité** et jamais en absolu : une partie légale de 30 manches à 120 s dure 70 minutes. |
| `archived_at` | timestamp | oui | `null` | Colonne pilote : l'archivage, et lui seul, recycle le code, ferme la fenêtre de rattachement tardif et efface les identifiants d'invité. |
| `timestamps` | timestamp | non | — | — |

**Clés** — PK `id` · UNIQUE `room_active_code_uq (room_code_active)` : unicité **seulement parmi les salons non archivés** · aucune FK sortante · FK entrante `player.room_id`, déclarée en ligne à la création de `player`, donc `room` migre avant `player`.
**Index** — `room_active_code_uq` : rejoindre par code ou par lien, **requête la plus fréquente du produit** · `room_code_idx (room_code)` : résoudre un lien de salon archivé pour afficher « salon expiré » plutôt qu'un 404 · `room_archived_activity_idx (archived_at, last_activity_at)` : balayage borné des deux jobs d'échéance · `room_host_player_idx (host_player_id)`.

**Les cinq colonnes de projection ne sont jamais écrites seules** : un **unique point d'écriture** applique le value object et la projection dans la même transaction, et un test vérifie que `projection == value object` sur toute ligne.

**Aucune de ces six colonnes ne porte de défaut**, et c'est le précédent que la spec pose déjà en § 3.2 pour `title_mask_version` et `recomputed_at`. Les défauts de jeu vivent dans `RoomSettingsBounds` et la version dans `RoomSettings::VERSION` ; les recopier dans une migration serait une **seconde source de vérité** qu'aucune migration additive ne pourrait ensuite resynchroniser — c'est la règle 2 appliquée à la lettre, et le § 6.1 l'écrit déjà noir sur blanc pour `PlatformLimits` (« jamais un littéral dans une migration ni un FormRequest »). Un INSERT partiel échoue alors **bruyamment** en MySQL strict (1364) au lieu de fabriquer un salon dont la projection ment sur son value object.

**Pas de compteur de sièges dénormalisé.** La prise de siège verrouille la ligne `room` (`lockForUpdate`) et **compte les `player`**. Un compteur qui dérive refuserait une entrée légitime pour toujours, alors que le produit garantit qu'aucun joueur n'est jamais expulsé par un réglage de capacité ; et SQLite sérialise les écritures, donc le verrou se comporte correctement des deux côtés. **Deux règles, dans cet ordre :**

1. **La reprise de siège précède toujours le comptage.** On résout d'abord `player_room_token_uq (room_id, player_token_hash)` ; un siège repris ne consomme aucune place et **ne traverse jamais la garde de capacité**.
2. Le comptage est `COUNT(*) FROM player WHERE room_id = ? AND connection_state <> 'left'`, servi par `player_room_state_idx`. **Compter toutes les lignes `player` compterait l'historique des sièges, pas l'effectif présent** — une ligne `player` n'est jamais supprimée avant l'archivage, donc un salon de capacité 4 dont quatre joueurs sont partis refuserait tout le monde pendant 24 h.

Test : capacité 2, deux joueurs entrent, l'un part, un troisième entre (accepté) ; le partant revient avec son jeton (accepté, sans doubler son siège).

`#[Hidden(['id', 'host_player_id', 'settings_version'])]` : **le client identifie un salon par son code et rien d'autre.** `#[RouteKey]` ne suffit pas — `resolveRouteBinding()` résout d'abord `room_code_active`, puis `room_code` parmi les archivés, pour produire le message explicite. **Aucune colonne de durée de manche** (`D` est la somme des paliers) et **aucune locale** : le salon n'a pas de langue, chaque joueur a la sienne.

### 6.3 `setting_preset` et `saved_config`

**`setting_preset`** · `id` · `key` string(30) UNIQUE `setting_preset_key_uq` (`classic`, `fast`, `hardcore`, `discovery`) — c'est elle que le seeder idempotent réconcilie et elle qui indexe le libellé traduit · `position` unsignedTinyInteger — ordre figé par le site, pour ne pas dépendre d'un tri alphabétique qui change de langue en langue · `settings` json · `settings_version` unsignedSmallInteger · `timestamps`. **Aucune FK : un preset n'a pas de propriétaire, c'est ce qui le rend accessible à un invité.** Aucun index sur `position` : quatre lignes.

**Aucune colonne de libellé et aucune table de libellé par locale** : contrairement aux `theme_label`, éditables en back-office, un preset est du contenu **livré avec le code** — nom et description sont des clés de `lang/`. Aucune route d'écriture, aucune policy accordant `update` ou `delete`, **y compris à un admin** ; le seeder est le seul écrivain. Le grisage d'un preset au vivier insuffisant est calculé au rendu, jamais stocké.

Valeurs normatives, reprises sans modification de `00-overview.md` et **testées** : Classique 30 s / 3 / normal / 10 / 8 s · Rapide 15 s / 2 / easy / 8 / 5 s · Hardcore 45 s / 5 / expert / 10 / 10 s · Découverte 60 s / 3 / easy / 5 / 15 s. Un test fait passer chacune par `RoomSettings::fromInput()` : un preset livré par le site ne doit **jamais** produire un salon inlançable (5 × 5 = 25 ≤ 45 pour Hardcore, 5 × 2 = 10 ≤ 15 pour Rapide).

**`saved_config`** · `id` · `user_id` FK `cascadeOnDelete` + index explicite — propriété **obligatoire et absolue** · `name` string(40), casse conservée pour l'affichage · `name_normalized` string(40), **normalisée en PHP**, porte l'unicité : sans elle, « Ma config » et « ma config » fusionnent en MySQL et coexistent en SQLite, donc le même test passe au vert d'un côté et au rouge de l'autre · `settings` json · `settings_version` unsignedSmallInteger · `default_slot` char(1) nullable (NULL = non, `'d'` = oui) · `timestamps`.

UNIQUE `saved_config_user_name_uq (user_id, name_normalized)` — 168 octets · UNIQUE `saved_config_user_default_uq (user_id, default_slot)` — « au plus une configuration par défaut par utilisateur », **la seule écriture portable** de cette garantie, MySQL 8 n'ayant aucun index partiel et un booléen plus une contrainte étant impossible. Le modèle expose `isDefault()` et `#[Appends(['is_default'])]`.

Plafond de **20 par utilisateur** : non exprimable en contrainte portable, appliqué par l'action de création en lisant `PlatformLimits::savedConfigsPerUser()`, **jamais un littéral**, avec un test. **Strictement privée, y compris d'un admin** : `SavedConfigPolicy` ne teste que la propriété, sans aucune clause de rôle, et le projet **s'interdit tout `Gate::before` global** — c'est la raison nommée de cette interdiction. Aucune colonne de visibilité, aucun jeton de partage, aucune table de partage.

**Nommément hors du périmètre de la purge**, et elle ne porte volontairement **aucune colonne pilote datée ni aucun index daté**, de sorte qu'un job de purge n'ait rien à balayer ici. Elle est en revanche supprimée à l'anonymisation, qui n'est pas une purge de rétention.

---

## 7. Partie

**Le journal opposable d'une partie**, et ce qui le compose exactement : `round.started_at` (origine unique) + `round_tier` (paliers matérialisés) + `game.settings_snapshot` + `game.scoring_version` / `validation_version` (la règle appliquée) + `game.tier_grace_ms` / `game.preload_lead_ms` (les deux constantes serveur figées au lancement, sans lesquelles un rejeu ne peut redonner ni le palier retenu ni la fenêtre de service) + `guess` (les gagnants) + `round_player` (**qui était là et n'a pas trouvé**) + `game.paused_at` / `total_paused_ms` (les trous d'horloge) + `round_choice_set` (les quatre chaînes du QCM). Rien d'autre, et rien de moins.

### 7.1 `player` — le siège

| Colonne | Type | Null | Défaut | Raison |
|---|---|---|---|---|
| `id` | bigint unsigned | non | — | **Jamais sérialisé.** `#[Hidden]`. |
| `public_id` | char(12) | non | — | Identité publique d'un siège : aléatoire base32, **jamais dérivée de l'id** (§ 1.1). C'est elle, et jamais `player.id`, qui voyage en charge utile, nomme un canal de présence, cible un `report` et adresse un geste d'hôte. Un auto-increment divulguerait le volume de sièges créés sur l'instance — la fuite que le § 1.1 refuse nommément pour `room.id` et `takedown_request.reference` — et resterait corrélable d'une partie à l'autre par quiconque a partagé un salon. UNIQUE. |
| `room_id` | bigint unsigned | oui | `null` | **Nullable parce qu'une partie solo n'a pas de salon** ; un siège solo reste un `player` pour porter locale, pseudo et avatar. FK `→ room` `cascadeOnDelete`. |
| `user_id` | bigint unsigned | oui | `null` | Rattachement tardif d'un invité à un compte, et détachement à l'anonymisation. **Jamais obligatoire** : la boucle de jeu ne passe pas par le compte (principe 10). FK `nullOnDelete`. |
| `nickname` | string(20) | oui | `null` | Pseudo 2-20, tel que le joueur l'a saisi, **accents et casse conservés** — c'est la chaîne affichée. **Aucune contrainte d'unicité ne porte sur cette colonne** (§ 1.4) : l'unicité par salon passe par `nickname_normalized`. Nullable parce qu'il est **effacé** à l'archivage du salon sans détruire la ligne. |
| `nickname_normalized` | string(20) | oui | `null` | Forme repliée du pseudo — minuscules, diacritiques translittérés, espaces compressés —, calculée **en PHP** par le même normaliseur que `answer_key` et **jamais affichée**. C'est elle qui porte l'unicité par salon, et c'est la seule façon portable de l'obtenir : `utf8mb4_0900_ai_ci` tient « José » et « jose » pour égaux, `BINARY` non (§ 1.4). Sans cette colonne, deux pseudos acceptés en test SQLite lèveraient une 1062 non traduite au moment de rejoindre un salon en production. Écrite dans la **même écriture** que `nickname`, effacée avec lui. |
| `nickname_masked_at` | timestamp | oui | `null` | Deux signalements distincts masquent le pseudo. **On marque au lieu d'écraser** : écraser deux pseudos par la même valeur neutre collisionnerait sur `UNIQUE (room_id, nickname_normalized)`. État **persistant**, hors purge. Chaque pose et chaque levée écrit une ligne `admin_action` **permanente** (`nickname.masked` / `nickname.unmasked`) portant `reports_count` : c'est elle, et non les lignes `report` purgées à 12 mois, qui justifie l'état — sans quoi, à 13 mois, un pseudo masqué n'a plus aucune justification en base (§ 8.3). |
| `player_token_hash` | string(64) | oui | `null` | **Hash SHA-256** du `player_token`, jamais le jeton en clair. Effacé à l'archivage, ce qui **ferme mécaniquement** la fenêtre de rattachement tardif. |
| `active_seat_token` | string(64) | oui | `null` | Un `player_token` = un siège : le second onglet frappe un nouveau jeton de siège, l'ancien passe en lecture seule, sans dépendre de Redis ni de la présence Reverb. **Ne contient jamais l'identifiant de session Laravel** : c'est un ULID applicatif frappé à la prise de siège. Un test l'interdit — `sessions` porte `ip_address` et `user_agent`, et une jointure par identifiant de session fabriquerait exactement la fuite que le principe 12 nie. |
| `locale` | string(5) | non | — | Langue d'interface lisible **hors requête HTTP** : un job de diffusion et un événement Reverb n'ont ni cookie ni `App::getLocale()` (`05`). |
| `avatar_kind` | string(20) + `AvatarKind` | oui | `null` | Même enum que `users` ; NULL = repli initiales. |
| `avatar_preset` | string(40) | oui | `null` | Clé stable du prédéfini, **même domaine de valeurs et même longueur que `users.avatar_preset`**, jamais un chemin. |
| `joined_at` | timestamp | non | — | Ordonne le transfert automatique du rôle d'hôte au joueur connecté le plus ancien. |
| `connection_state` | string(20) + `PlayerConnectionState` | non | `connected` | `connected` / `disconnected` / `left`. |
| `last_seen_at` | timestamp(3) | non | — | Dernier battement de présence ; alimente aussi `room.last_activity_at`. |
| `disconnected_at` | timestamp(3) | oui | `null` | Départ de `disconnectGraceSeconds`, qui est un **réglage d'hôte** 15-180 s, jamais une constante — et jamais `tier_grace_ms`, la fenêtre de frontière de palier (§ 1.3). |
| `left_at` | timestamp | oui | `null` | Passage à « parti ». Les points restent au classement ; seule la fin anticipée change. |
| `timestamps(3)` | timestamp(3) | non | — | § 1.2. |

**Clés** — PK `id` · UNIQUE `player_public_id_uq (public_id)` · FK `room_id` `cascadeOnDelete` · FK `user_id` `nullOnDelete` · UNIQUE `player_room_nickname_uq (room_id, nickname_normalized)` — **jamais sur `nickname` lui-même**, qui est du texte humain (§ 1.4) ; les NULL multiples étant acceptés, l'effacement à l'archivage et les sièges solo ne collisionnent pas · UNIQUE `player_room_token_uq (room_id, player_token_hash)` — un jeton = un siège.
**Index** — les trois uniques · `player_user_idx (user_id)` : liste de mes parties · `player_room_state_idx (room_id, connection_state)` : garde de capacité et transfert d'hôte · `player_solo_expiry_idx (room_id, last_seen_at)` : échéance d'effacement des sièges solo · `player_token_idx (player_token_hash, room_id)` : reprise d'un siège **solo** (où l'unique `(room_id, player_token_hash)` ne s'applique pas, les deux moteurs ignorant les NULL — § 1.4) et mise à jour de `locale` sur tous les sièges actifs d'un même jeton au changement de langue (`05`) ; sans lui, les deux sont un balayage complet de `player`.

> **Portée exacte de l'invariant « un jeton = un siège ».** Il est porté **en base pour un siège de salon seulement**. Pour un siège solo (`room_id IS NULL`), l'unicité est **inopérante par construction** : la reprise se fait par `player_token_idx` sur (`player_token_hash`, `room_id IS NULL`, `left_at IS NULL`), et l'action de création de partie solo **reprend le siège existant** au lieu d'en créer un second. Sans cette règle, un simple rechargement de page pendant un entraînement solo double le siège, orpheline la partie en cours et laisse un pseudo d'invité de plus à effacer — et le périmètre `orphan_player` du § 11.1 en accumule indéfiniment. Test : deux lancements solo successifs sous le même jeton produisent **exactement une** ligne `player`.

> **Ce qu'`active_seat_token` garantit, et ce qu'il ne garantit pas.** Il garantit qu'**un jeton = un siège** : le second onglet du même `player_token` frappe un nouveau jeton de siège et l'ancien passe en lecture seule. Il ne garantit **rien** sur les personnes. **Aucun mécanisme du schéma ne lie deux sièges à une même personne, et aucun ne le peut sans adresse IP, que le § 11.1 interdit en table de domaine** : rien n'empêche le même humain d'ouvrir une fenêtre privée, de saisir le code du salon et de prendre un second siège sous un autre `player_token` et un autre pseudo. Les deux sièges reçoivent les mêmes quatre propositions (règle voulue du § 7.8), donc le tricheur peut cliquer une proposition sur le siège B, lire son propre verdict, et jouer la bonne sur le siège A : « QCM à essai unique et définitif » devient quatre essais, et le budget de tentatives en texte libre double. **La garantie « essai unique » est donc une garantie PAR SIÈGE et jamais par personne**, et elle se reformule ainsi partout — ici, dans `00-overview.md` et dans `60`. Ce n'est pas la collusion hors jeu du principe 3 : c'est un cheat solitaire, qui ne demande aucun complice. Les seuls remèdes possibles sans donnée personnelle sont produit et non schéma — liste des sièges visible de tous, expulsion par l'hôte, entrée soumise à l'hôte — et relèvent de `50` et `60`. **Le point est écrit ici parce que, non écrit, il laisse croire le trou fermé.**

**Aucune adresse IP.** **Le rôle d'hôte n'est pas ici** : il vit sur `room.host_player_id`.

`#[Hidden(['id', 'user_id', 'player_token_hash', 'active_seat_token'])]` sur le modèle `Player` : le client adresse un siège par `public_id` et rien d'autre. Test : `Player::first()->toArray()` ne contient ni `id`, ni `player_token_hash`, ni `active_seat_token`.

**`player.created_at` n'est pas une colonne pilote de purge, et il n'existe aucun index daté qui l'y inviterait.** Un siège solo créé il y a 13 mois peut porter une partie jouée il y a un mois : le `restrict` de `game_player` ferait échouer le `DELETE`, et un job par **lots bornés triés du plus ancien au plus récent** resélectionnerait éternellement la même ligne — le périmètre ne progresserait plus jamais, silencieusement. Un siège se supprime **uniquement par dépendance** (§ 11).

**Deux déclencheurs d'effacement des identifiants d'invité, pas un** : `room.archived_at` pour un siège de salon, et `room_id IS NULL AND last_seen_at < now − 24 h` pour un siège solo, servi par `player_solo_expiry_idx`. Plus le troisième geste, distinct : l'anonymisation de compte (§ 5.5).

### 7.2 `game` — la partie

| Colonne | Type | Null | Défaut | Raison |
|---|---|---|---|---|
| `id` | bigint unsigned | non | — | Jamais expédié au client. |
| `room_id` | bigint unsigned | oui | `null` | **NULL en solo.** FK `→ room` **`restrictOnDelete`** : un salon portant une partie ne peut pas être supprimé avant elle — l'ordre de purge devient structurel. |
| `mode` | string(20) + `GameMode` | non | — | `multiplayer` / `solo`. **Figé à la création, aucun chemin ne le mute.** Les quatre compteurs excluent le solo par filtre, donc c'est une colonne requêtée. |
| `status` | string(20) + `GameStatus` | non | `running` | `running` / `paused` / `completed` / `interrupted`. **Aucun état `pending`** : la partie naît au lancement, quand le tirage est déjà figé. |
| `input_difficulty` | string(10) + `InputDifficulty` | non | — | Figé au lancement ; décide si un QCM existe pour la partie, donc un filtre et non une clé de snapshot. |
| `rounds_count` | unsignedTinyInteger | non | — | `M` figé (3-30). Même nom que sur `room`. |
| `frames_per_round` | unsignedTinyInteger | non | — | `N` figé (2-5) ; borne le nombre de lignes `round_tier`. |
| `rounds_completed` | unsignedTinyInteger | non | `0` | Le `k` de « interrompue à la manche `k` sur `M` ». Maintenu à chaque clôture, figé à `ended_at`. |
| `draw_seed` | string(64) | non | — | Graine enregistrée : films, variantes, départage à égalité et permutation du QCM par couple (manche, joueur) en dérivent tous. **`bin2hex(random_bytes(32))`, CSPRNG** — voir la règle de dérivation ci-dessous, qui est au même rang normatif que l'interdiction des ENUM natifs. `#[Hidden]` — l'expédier permettrait de recalculer tout le tirage. |
| `draw_pool_size` | unsignedSmallInteger | non | — | Taille du vivier au lancement : explique pourquoi `min(M + 3, |vivier|)` films seulement ont été tirés. **Information de curation, sans aucun usage client** — la connaître réduirait le champ des films possibles. `#[Hidden]`. |
| `tier_grace_ms` | unsignedSmallInteger | non | — | Fenêtre de grâce de **frontière de palier**, **constante serveur** figée au lancement depuis `PlatformLimits::tierGraceMs()` (300 par défaut), **jamais un réglage d'hôte** et **jamais dans `settings_snapshot`** (§ 6.1). Hors `#[Fillable]`. Toute modification de sa valeur incrémente `scoring_version`. À ne jamais confondre avec `disconnectGraceSeconds`, le réglage d'hôte (§ 1.3). |
| `preload_lead_ms` | unsignedSmallInteger | non | — | Avance maximale de signature d'une image sur l'ouverture de son palier, **constante serveur** figée au lancement depuis `PlatformLimits::preloadLeadMs()`. **Jamais un réglage d'hôte, jamais la durée d'un palier** — `dᵢ` est réglable de 5 à 120 s, une constante globale ne peut pas le valoir (§ 10). Hors `#[Fillable]`, couverte par `scoring_version` au même titre que `speedBonusMaxFraction`. |
| `settings_version` | unsignedSmallInteger | non | — | Version du value object figé, pour relire un snapshot de 11 mois sans migration de données. |
| `settings_snapshot` | json | non | — | Copie figée des réglages fins. Jamais interrogée en SQL. **`#[Hidden]`.** |
| `scoring_version` | unsignedSmallInteger | non | — | **Version de la règle de score**, écrite au lancement depuis une constante de code. Toute modification de `speedBonusMaxFraction`, de la forme du bonus, de la chaîne de départage, de `tierGraceMs` ou de `preloadLeadMs` l'incrémente. |
| `validation_version` | unsignedSmallInteger | non | — | **Version de la règle de validation** : normaliseur, seuil de Levenshtein, séparateurs de sous-titre, longueur minimale de préfixe. |
| `started_at` | timestamp(3) | non | — | Lancement, origine du journal. |
| `paused_at` | timestamp(3) | oui | `null` | Départ du compte à rebours de clôture (15 min sans joueur connecté). Nom volontairement différent de `room.archived_at`. |
| `total_paused_ms` | unsignedInteger | non | `0` | Cumul des pauses : sans lui, un rejeu ne peut pas expliquer un trou de trois heures entre deux manches. |
| `ended_at` | timestamp(3) | oui | `null` | Podium, terminée **ou** interrompue. **Colonne pilote unique** de la fenêtre de 12 mois. |
| `timestamps(3)` | timestamp(3) | non | — | § 1.2. |

**Clés** — PK `id` · FK `room_id → room` `restrictOnDelete`.
**Index** — `game_ended_idx (ended_at)` : purge par lots bornés et fenêtre glissante · `game_mode_ended_idx (mode, ended_at)` : les quatre compteurs, périmètre multijoueur uniquement · `game_room_started_idx (room_id, started_at)` : parties d'un salon, rejouer · **`game_started_idx (started_at)`** : périmètre de purge `stale_game`, sans lequel une partie dont le job de clôture n'a jamais tourné reste hors de l'index que la purge parcourt, **indéfiniment et sans qu'aucune sonde ne le voie** (§ 11).

> **Génération et dérivation de `draw_seed` — règle normative, au même rang que l'interdiction des ENUM natifs.** Le schéma protégeait la graine par `#[Hidden]` sans rien prescrire de sa **fabrication** ni de la fonction qui la **consomme**, et l'implémentation naturelle en PHP — `mt_srand(crc32($game->draw_seed))` suivi d'un `shuffle` — n'a qu'un état de **32 bits** : le tricheur observe le film de la manche 1 à la révélation, essaie les 2³² graines hors ligne en quelques minutes, retient celles qui la reproduisent, et **connaît les manches 2 à M ainsi que la variante tirée à chaque palier**, sans plus jamais regarder une image. Symétriquement, un `random_int()` non seedé casserait la rejouabilité que la matérialisation existe pour garantir. Donc :
>
> - `draw_seed = bin2hex(random_bytes(32))`, **CSPRNG**, jamais `uniqid()` ni un dérivé d'horodatage.
> - **Toute** valeur dérivée s'obtient par `hash_hmac('sha256', $context, $seed)`, où `$context` **nomme explicitement l'usage** — `"draw:movies"`, `"draw:variant:{roundId}:{tierIndex}"`, `"tiebreak:{roundId}"`, `"qcm:{roundId}:{playerId}"` —, suivie d'une réduction **sans biais** par rejection sampling. La même graine sert un usage **secret** (le tirage) et un usage **révélé** (la permutation du QCM, que chaque joueur observe) : une dérivation qui n'est pas une PRF exposerait le secret par ses sorties publiques.
> - `mt_srand`, `srand`, `shuffle()`, `Arr::shuffle()` et `Collection::shuffle()` sont **interdits** sur tout chemin dépendant de la graine.
>
> Deux tests : deux parties lancées à la même seconde sur le même vivier **ne tirent pas la même séquence** ; un rejeu avec la graine reproduit exactement `round.movie_id` et `round_tier.frame_id`.

**Pourquoi deux colonnes de version de règle.** `guess.points_bonus` est figé au verrouillage sous une fraction de bonus qui n'est persistée nulle part : si `speedBonusMaxFraction` passe de 0,50 à 0,40 en mars, l'outil de rejeu d'une partie de décembre recalcule 396 contre une ligne figée à 420, **et aucune donnée du journal ne permet de dire laquelle est juste**. Même trou côté validation : `guess.edit_distance` est stocké, jamais le seuil qui l'a acceptée. Le code de rejeu branche sur la version lue dans `game`.

**`ended_at` pilote à la fois l'affichage de la fenêtre de 12 mois et la purge.** C'est ce qui rend **explicite, et non accidentel**, le fait que le meilleur score d'un joueur puisse baisser : l'élément sort de la fenêtre et de la base au même instant, il ne peut pas y avoir de divergence. **Aucune colonne d'agrégat de profil n'existe nulle part** — une fenêtre glissante obligerait à **décrémenter** un agrégat stocké à chaque purge ; une requête n'a rien à décrémenter.

### 7.3 `game_player` — la participation et les agrégats figés

`id` · `game_id` FK `cascadeOnDelete` · `player_id` FK **`restrictOnDelete`** — un siège ne disparaît jamais sous un podium · `display_nickname` string(20) nullable — pseudo **gelé pour la durée de la partie** : un invité qui se connecte en cours de partie garde son affichage jusqu'au podium ; effacé à l'archivage du salon · `display_avatar_kind` string(20) + `AvatarKind` nullable et `display_avatar_preset` string(40) nullable — **on ne gèle jamais le chemin d'une copie provider**, pour qu'un masquage postérieur fasse redescendre la chaîne de repli · `first_round_number` unsignedTinyInteger nullable — manche d'entrée d'un retardataire · `status` string(20) + `GamePlayerStatus` défaut `playing` — issue **figée** de la partie (`playing` / `left` / `kicked`), la présence vive vivant sur `player.connection_state` · `timestamps`.

Cinq colonnes **nullables**, écrites **uniquement à `game.ended_at`** : `rounds_played` unsignedTinyInteger (manches réellement jouées par **ce** joueur, dénominateur du taux de réussite, jamais `M`) · `correct_answers` unsignedTinyInteger · `final_score` integer · `total_answer_time_ms` unsignedInteger · `final_rank` unsignedTinyInteger (NULL en solo, où l'historique affiche « — »).

UNIQUE `game_player_player_game_uq (player_id, game_id)`, qui sert aussi la liste de mes parties, `player_id` en tête · index `game_player_game_idx (game_id)`.

**Le score vivant n'est pas ici** : c'est `SUM(guess.points_total)` sur au plus 120 lignes déjà figées au verrouillage, **sous l'invariant L1**. Une colonne maintenue serait une seconde source de vérité qui dérive au premier rejeu de job ; rendre les colonnes nullables fait du gel un **événement vérifiable** plutôt qu'un état qu'on oublie de déclencher. « Partie jouée » au sens des compteurs = `rounds_played >= 1`.

**Aucune colonne `user_id`** : le propriétaire se lit par `player.user_id`, une seule source de vérité, et l'anonymisation le détache en une seule écriture.

### 7.4 `round` et `round_tier`

**`round`** · `id` · `game_id` FK `cascadeOnDelete` · `room_id` bigint nullable FK `→ room` `restrictOnDelete`, **dénormalisé depuis `game` au lancement et jamais modifié** : la non-répétition des films déjà joués par le salon est une requête du lobby, sur le chemin chaud du recalcul de vivier · `sequence_index` unsignedTinyInteger — position dans le tirage figé `1..min(M + 3, |vivier|)` · `round_number` unsignedTinyInteger nullable — numéro affiché `1..M`, **non unique**, car une manche annulée et son remplaçant partagent le même numéro : c'est la vérité de ce qui s'est passé, et renuméroter détruirait le journal · `movie_id` FK `→ movie` **`restrictOnDelete`** — **c'est la bonne réponse**, jamais sérialisée avant la révélation, `#[Hidden]` · `status` string(20) + `RoundStatus` défaut `pending` · `started_at`, `ended_at`, `reveal_ends_at` timestamp(3) nullables · `duration_ms` unsignedInteger — `D` de cette manche, dénormalisé pour éviter un `SUM` sur le chemin chaud · `found_count` unsignedTinyInteger défaut 0 · `decoy_movie_id_1..3` bigint nullables FK `→ movie` `restrictOnDelete`, `#[Hidden]` — trois colonnes plutôt qu'un pivot, la cardinalité étant fixée à trois par la règle et rien ne balayant jamais cet ensemble ; NULL en difficulté `expert` · `choices_use_original_title` boolean défaut `false` — mode dégradé décidé **une fois pour tout le salon**, `#[Hidden]` : diffusé à l'ouverture de la manche, il apprendrait que le film cible n'a **pas** de titre dans la locale du joueur, ce qui réduit un catalogue majoritairement traduit à une poignée de films **avant la première image**. Il n'est transmis qu'**avec** les quatre propositions, à `T_N` (ou `T₁` en Facile), jamais avant · `cancel_reason` string(30) + `RoundIncidentReason` nullable · `cancelled_at` timestamp(3) nullable · `timestamps(3)`.

UNIQUE `round_game_sequence_uq (game_id, sequence_index)` · index `round_game_status_idx (game_id, status)` : retrouver la manche ouverte à chaque resynchronisation · `round_room_started_movie_idx (room_id, started_at, movie_id)` : non-répétition **et** bornage des 500 dernières manches de `seen_frame`, **un seul index pour les deux usages** · `round_movie_found_idx (movie_id, found_count)` : file de curation des films jamais trouvés, filtrée sur `status = 'completed'`.

**`found_count` est un compteur vivant, pas une valeur figée à la clôture** — c'est lui qui alloue `lock_rank` (§ 7.6). Il n'est **pas** remis à zéro à l'annulation d'une manche : le remettre à zéro casserait l'invariant de l'allocateur pour rien, la requête de curation filtrant déjà `status = 'completed'` et l'invariant **L1** couvrant déjà les scores.

**Les incidents alimentent la même file de curation que les films jamais trouvés, et sous la même garantie d'anonymat.** `round.cancel_reason` et `round_tier.substitution_reason` s'agrègent par `movie_id` — « ce film a cassé 4 manches en 30 jours, toutes par image indisponible » — **sans jamais joindre `round_player`, `guess` ni `player`** : aucune identité de joueur n'entre dans cette file. La requête est back-office et peu fréquente ; elle emprunte `round_movie_found_idx` par son **préfixe gauche `movie_id`** et filtre `cancel_reason IS NOT NULL` sur les quelques lignes retournées. **Aucun index nouveau** : indexer une colonne nulle dans plus de 99 % des lignes d'une table à 12 mois de rétention coûterait plus que le balayage qu'il évite.

**L'horloge d'une manche ne se met jamais en pause.** Si le dernier joueur connecté part pendant une manche ouverte, la manche va au bout de `D` et se clôt ; la partie passe **ensuite** en `paused`. Une pause en cours de manche rendrait faux `started_at + starts_at_offset_ms` et obligerait à soustraire un cumul **par manche** à chaque calcul de palier — c'est-à-dire à rendre le palier dépendant d'une seconde source de vérité, exactement ce que la matérialisation existe pour empêcher.

**`round_tier`** — le palier matérialisé.

| Colonne | Type | Null | Mutable ? | Raison |
|---|---|---|---|---|
| `id` | bigint unsigned | non | non | — |
| `round_id` | bigint unsigned | non | non | FK `cascadeOnDelete`. |
| `tier_index` | unsignedTinyInteger | non | **jamais** | Rang d'affichage `1..N`, propriété **volatile de la manche** née du tirage. Il n'existe aucune colonne d'ordre sur `frame`. |
| `frame_id` | bigint unsigned | oui | **jamais** | **Variante tirée et figée au lancement.** FK `nullOnDelete` : seul endroit du domaine où une perte catalogue est tolérée, et elle ne coûte jamais un point. `#[Hidden]`. |
| `frame_level` | unsignedTinyInteger | non | **jamais** | Dénormalisé : prouve que l'échantillonnage a été respecté et garde le journal lisible si la frame disparaît. `#[Hidden]` — les niveaux réellement tirés trahissent le **repli de niveau**, donc la maigreur de la banque du film, signature exploitable par qui a énuméré le catalogue. |
| `serve_token` | char(32) | **oui** | `null` | `bin2hex(random_bytes(16))`, **écrit à l'ouverture du palier**, dans la même transaction que `served_frame_id` et `served_at` — donc jamais matérialisé au lancement, jamais existant avant l'instant d'ouverture. **Seul identifiant d'image qui quitte le serveur**, et il est lié à une **manche**, pas à une frame : deux manches distinctes portant la même frame produisent deux jetons différents, donc aucune paire (identifiant → titre) apprise en solo n'est réutilisable ailleurs (§ 7.10, § 10). UNIQUE `round_tier_serve_token_uq`. `#[Hidden]` — il est poussé par le message d'ouverture de palier, jamais par une sérialisation de modèle. |
| `served_frame_id` | bigint unsigned | oui | **une fois** | **La variante réellement affichée.** Écrite à l'ouverture du palier, FK `nullOnDelete`, index explicite. `#[Hidden]`. |
| `served_at` | timestamp(3) | oui | **une fois** | Instant d'affichage effectif. |
| `substitution_reason` | string(30) + `RoundIncidentReason` | oui | **une fois** | Non nulle seulement si `served_frame_id <> frame_id`. |
| `starts_at_offset_ms` | unsignedInteger | non | **jamais** | Instant d'ouverture matérialisé, en **décalage depuis `round.started_at`**. |
| `duration_ms` | unsignedInteger | non | **jamais** | Durée figée, **toujours multiple de 1000** : le réglage est en secondes entières, le stockage en millisecondes pour rester homogène avec `guess.answered_at_ms`. |
| `points` | unsignedSmallInteger | non | **jamais** | Valeur 0-1000 figée : le barème n'est plus déductible de la position, un rejeu ne peut pas recalculer un score différent. |
| `timestamps(3)` | timestamp(3) | non | — | Une valeur de temps ou de points qui bouge après le lancement est un bug détectable. |

UNIQUE `round_tier_round_index_uq (round_id, tier_index)` : lecture intégrale des paliers d'une manche, seul accès existant · UNIQUE `round_tier_serve_token_uq (serve_token)` : résolution de la route de service en une lecture de clé · index `round_tier_served_idx (served_frame_id)`.

> **Qui écrit `served_frame_id`, `served_at`, `substitution_reason`, `serve_token` et la ligne `seen_frame` — règle normative, et c'est la règle 1 appliquée.** Ces écritures appartiennent **exclusivement** à la transition serveur d'ouverture de palier (le job de frontière, ou le rattrapage synchrone qui le remplace si le job est en retard). **Jamais à la route de service d'image, qui est en lecture seule sans exception.** Avec le préchargement par palier, la première requête d'image d'un palier arrive `preload_lead_ms` **avant** son ouverture, et c'est elle qui découvre naturellement qu'une frame n'est plus servable : le montage est tentant, et il casse trois choses. (a) `served_at` serait en avance d'une fenêtre de préchargement, et le journal opposable mentirait sur l'instant d'affichage. (b) Un client qui ne demande jamais l'image laisserait `served_frame_id` nul sur un palier pourtant affiché aux autres. (c) Un client fabriquerait une ligne `seen_frame` sur une image **jamais montrée** — ce que le paragraphe suivant dit expressément vouloir empêcher — et **influencerait le tirage des parties suivantes du salon** en poussant une variante hors du « non vue par le salon » : une requête cliente déciderait d'un tirage. Une route qui rencontre une frame non servable **ne substitue pas** : elle refuse et signale la transition, qui seule décide. Test Pest : après `N` requêtes d'image anticipées et **aucune** frontière franchie, `round_tier.served_at` est nul et `seen_frame` est vide.

> **Ce qui est réécrivable, nommément : `served_frame_id`, `served_at` et `substitution_reason`, exactement une fois, à l'ouverture du palier, par le seul chemin de substitution.** Aucune autre colonne, et **aucune colonne de temps ni de points de `round_tier` n'est jamais touchée**. Sans ces trois colonnes, une substitution est muette : le journal affirme que la frame X a été montrée alors que Y l'a été, l'upsert `seen_frame` écrit Y, la partie suivante du salon préfère X comme « non vue » et remontre Y, et une demande de retrait portant sur Y quatre mois plus tard reçoit la réponse « jamais affichée » — `seen_frame` ayant été purgé à 90 jours quand `round_tier` vit 12 mois. Tests : après substitution, `SUM(duration_ms)` et chaque `points` sont inchangés, `frame_level` est identique, et toute ligne `seen_frame` d'un salon correspond à un `served_frame_id`.

**Ce qui reste calculé, et rien d'autre** : l'instant absolu = `round.started_at + starts_at_offset_ms`, et le palier retenu = la ligne dont la fenêtre `[offset, offset + duration)` contient la valeur corrigée du § 7.5. **Aucune colonne `opened_at` absolue** : elle ferait du job de frontière un **décideur**, alors qu'il ne fait que diffuser — un job en retard, rejoué ou perdu décale un affichage et jamais un score. Tests obligatoires : `duration_ms % 1000 = 0` et `SUM(duration_ms) = round.duration_ms`.

### 7.5 Sélection du palier — `tier_grace_ms` est une constante serveur figée sur la partie

`00-overview.md` pose une fenêtre de grâce de ±300 ms aux frontières de palier et une correction `min(RTT/2, 300 ms)`, présentées comme la contrepartie de la borne croisée 1. Une règle « le palier est la fenêtre qui contient `answered_at_ms` » et une fenêtre de grâce ne peuvent pas être vraies en même temps. Le nom `tier_grace_ms` est imposé par le § 1.3 : « délai de grâce » désigne déjà `disconnectGraceSeconds`, un réglage d'hôte de 15 à 180 s, soit quatre ordres de grandeur d'écart. **Arbitrage, et il est rejouable :**

> Le palier retenu est la ligne dont la fenêtre contient **`max(0, guess.answered_at_ms − game.tier_grace_ms)`**, où `tier_grace_ms` est **figée dans sa propre colonne de `game` au lancement, depuis `PlatformLimits::tierGraceMs()`** (§ 7.2), et n'est **jamais un réglage d'hôte**. Elle n'est **pas** dans `settings_snapshot` : les trois autres porteurs de `RoomSettings` (`room`, `setting_preset`, `saved_config`) sont des objets d'hôte éditables et sauvegardables, dont l'unique constructeur valide est `fromInput()` — y loger une constante serveur la rendrait réglable par la voie du JSON, et un hôte postant `tierGraceMs: 120000` s'achèterait le palier 1 pour toute la manche (§ 6.1). Le bonus de rapidité se calcule sur **la même valeur corrigée**, sinon le bonus et le palier désignent deux instants différents.

C'est l'invariant **L3** appliqué : aucune valeur mesurée ou déclarée par le client n'entre dans le calcul. Sans cette règle écrite, un clic à 9,95 s reçu à 10,15 s sur un palier de 5 s vaut 300 points au lieu de 400 — 100 points de hasard réseau, exactement ce que la fenêtre de grâce existe pour supprimer ; et si le moteur appliquait la grâce sans la journaliser, `guess` enregistrerait `answered_at_ms = 10150` avec `tier_index = 2` quand un rejeu sur les seules lignes `round_tier` recalculerait 3, **le journal rejouable contredisant le score attribué**.

Trois tests : `answered_at_ms` = borne + (`tier_grace_ms` − 1) → palier précédent ; borne + (`tier_grace_ms` + 1) → palier suivant ; rejeu de (`guess.answered_at_ms`, `round_tier`, `game.tier_grace_ms`, `game.settings_snapshot`, `game.scoring_version`) redonnant **exactement** `guess.tier_index` et `guess.points_total`.

### 7.6 `round_player` et `guess`

**`round_player`** — table **ajoutée** au périmètre, absente de la liste engageante, et c'est **la ligne qui manque au journal** si l'on se contente de `guess` et `round_tier` : `guess` n'a que des gagnants, donc sans elle on ne distingue pas « Bob était présent et a échoué » de « Bob n'était pas dans la manche » — or c'est le dénominateur du taux de réussite et la première phrase d'un joueur dans un litige.

`id` · `round_id` FK `cascadeOnDelete` · `player_id` FK **`restrictOnDelete`** · `input_state` string(20) + `RoundPlayerInputState` défaut `open` (`open`, `locked`, `qcm_wrong`, `attempts_exhausted`, `revealed`, `skipped`) · `input_closed_at` timestamp(3) nullable — en Normal un clic faux ferme **aussi** le texte libre, et cet instant doit être opposable · `wrong_attempts` unsignedTinyInteger défaut 0 — **les tentatives fausses sont comptées, jamais stockées**, ni texte ni ligne : une ligne par tentative représenterait 3 millions de lignes de texte libre par an, pour rien · `choices_locale` string(5) nullable — langue de **composition** du QCM, figée à la première composition · `choices_composed_at` timestamp(3) nullable · `timestamps(3)`.

UNIQUE `round_player_round_player_uq (round_id, player_id)` : lecture et écriture de l'état de saisie sur le chemin chaud de chaque tentative. La ligne est créée **à l'ouverture de la manche** pour chaque joueur présent et non parti ; un retardataire admis en obtient une à la manche suivante.

Invariants testés : `input_state = 'locked'` **si et seulement si** une ligne `guess` existe pour le même couple ; `revealed` et `skipped` inatteignables hors `game.mode = 'solo'`. **`input_state` n'est jamais diffusé pour un autre joueur** : `qcm_wrong` révélerait une mauvaise réponse, que la règle interdit de diffuser.

**`guess`** — une bonne réponse, et rien d'autre.

| Colonne | Type | Null | Raison |
|---|---|---|---|
| `id` | bigint unsigned | non | — |
| `round_id` / `player_id` | bigint unsigned | non | FK `cascadeOnDelete` / **`restrictOnDelete`**. |
| `received_at` | timestamp(3) | non | Instant **serveur** de réception, jamais l'instant d'envoi du client. Horodatage de record. |
| `answered_at_ms` | unsignedInteger | non | Millisecondes depuis `round.started_at`. **C'est cette valeur entière qui a déterminé le palier et le bonus** (§ 7.5), immune au format de date d'Eloquent et à tout fuseau. |
| `tier_index` | unsignedTinyInteger | non | Palier retenu, matérialisé : la chaîne de départage le consomme palier par palier, des mois après. |
| `lock_rank` | unsignedTinyInteger | non | Rang d'arrivée dans la manche, visible des autres. Voir l'allocation ci-dessous. |
| `source` | string(10) + `GuessSource` | non | `text` / `choice`. |
| `match_kind` | string(10) + `GuessMatchKind` | non | `title` / `alias` / `prefix` / `choice`. La règle de collision ne porte que sur les préfixes : l'instantané doit dire laquelle s'appliquait. |
| `answer_key_id` | bigint unsigned | oui | Clé retenue au moment du match. `nullOnDelete` : l'index est recalculé à chaque publication et ne doit **jamais** faire perdre un score. |
| `answer_key_normalized` | string(**200**) | non | Copie de la chaîne retenue : l'instantané doit rester autosuffisant douze mois plus tard. **200 et non 191** : `answer_key.normalized` fait 200, et une clé de 195 caractères passerait en SQLite (aucune contrainte de longueur, mesuré) et lèverait 1406 en MySQL strict (mesuré) — la transaction de verrouillage échouerait, le joueur ne recevrait aucun point pour une bonne réponse, et l'invariant « `locked` ⟺ `guess` » serait cassé en base. |
| `submitted_normalized` | string(200) | non | Forme normalisée de ce que le joueur a tapé. Le texte brut n'est pas conservé. |
| `edit_distance` | unsignedTinyInteger | non | Distance retenue. Le **seuil** qui l'a acceptée se lit par `game.validation_version`. |
| `prefix_was_ambiguous` | boolean | non | Ambiguïté mesurée sur le catalogue publié entier **à l'instant du match**. L'évaluation n'étant jamais rétroactive, elle se fige ici. |
| `points_tier` / `points_bonus` / `points_total` | unsignedSmallInteger ×3 | non | Les trois parts **figées au verrouillage**, jamais recalculées. |
| `created_at` | timestamp(3) | non | **`const UPDATED_AT = null;`** — la ligne ne bouge plus. |

UNIQUE `guess_round_player_uq (round_id, player_id)` : un joueur déjà verrouillé est rejeté en une lecture, sur le chemin chaud · UNIQUE `guess_round_rank_uq (round_id, lock_rank)`, **filet et non mécanisme**.

> **Allocation de `lock_rank`, sous verrou, dans la transaction de verrouillage.** `SELECT … FROM round WHERE id = ? FOR UPDATE` → `UPDATE round SET found_count = found_count + 1` → `lock_rank := found_count` → `INSERT guess` → `UPDATE round_player SET input_state = 'locked'`. **Les cinq opérations dans la même transaction.** Un `SELECT MAX(lock_rank)` suivi d'un `INSERT` n'est pas sérialisé : à 12 joueurs sur un palier à fort barème, deux bonnes réponses à quelques millisecondes d'intervalle lisent toutes deux 0, et la seconde heurte l'erreur 1062 — une bonne réponse perdue, un joueur éventuellement marqué `locked` **sans ligne `guess`**, et un plafond de tentatives consommé. Un verrou de ligne, au plus 12 fois par manche, sur le chemin qui doit de toute façon être atomique.

**Il n'existe aucune colonne `is_correct`** : la table ne contient que des bonnes réponses, par construction, et une colonne booléenne inviterait à y ranger les fausses. **Aucune IP, aucun horodatage client.** Avant la révélation, la diffusion ne porte que **`player.public_id`** (jamais `player.id`, § 7.1) et `lock_rank` : ni points, ni `tier_index`, ni chaîne — le badge « a trouvé » ne doit rien apprendre. Au plus 120 lignes par partie.

### 7.7 Fin anticipée d'une manche — le prédicat, en toutes lettres

`00-overview.md` dit « la manche se clôt à `D`, ou immédiatement si tous les joueurs connectés sont verrouillés ». Formulé sur l'effectif du **salon**, ce prédicat casse de deux façons : il est **vrai sur l'ensemble vide**, et son dénominateur inclut des joueurs qui ne peuvent structurellement pas être verrouillés dans cette manche.

> **Participants d'une manche** = les lignes `round_player` de cette manche dont le `player` a `connection_state = 'connected'` **ET** `left_at IS NULL`.
> **Fin anticipée si et seulement si** `COUNT(participants) >= 1` **ET** `COUNT(participants dont input_state <> 'open') = COUNT(participants)`.

La borne **`>= 1`** est la correction du cas vide et doit être lue comme telle, pas déduite. Conséquence à écrire aussi : **zéro participant connecté ne clôt jamais une manche** — elle va au bout de `D`, puis `game` passe en `paused` et c'est `paused_at` qui arme les 15 minutes.

Sans ces deux corrections : (a) deux joueurs qui perdent le réseau à `t = 3 s` d'une partie de 10 manches voient l'ensemble des connectés devenir vide, « tous verrouillés » devenir vrai, et les 7 manches restantes défiler en quelques secondes vers un podium 0-0 avant que la règle des 15 minutes n'ait la moindre occasion de s'appliquer ; (b) un retardataire admis « à la manche suivante » est `connected` dans `room` mais n'a pas de ligne `round_player` pour la manche en cours — le dénominateur vaut 4, le numérateur plafonne à 3, et la fin anticipée ne se déclenche **plus jamais** tant qu'un retardataire en attente est présent.

Lecture : au plus 12 lignes par `round_player_round_player_uq` (`round_id` en tête), puis jointure `player` par clé primaire. **Aucun index nouveau.** Deux tests Pest nommés : « tous les participants déconnectés → la manche se clôt à `D`, pas avant » et « un joueur connecté sans ligne `round_player` ne bloque pas la fin anticipée ».

### 7.8 `round_choice_set` — les quatre chaînes du QCM, figées

`05` exige que **tout renvoi rejoue exactement les mêmes quatre chaînes** et que le client renvoie la chaîne choisie, le serveur seul jugeant. Figer les trois **films** ne suffit pas : le jugement d'un clic passerait par `answer_key`, que le projecteur reconstruit **par différence** — donc en supprimant la clé normalisée d'un titre corrigé entre-temps.

`id` · `round_id` FK `cascadeOnDelete` · `locale` string(5) · `choice_1` … `choice_4` string(255) NOT NULL · `composed_at` timestamp(3) · `timestamps(3)` (§ 1.2 et § 1.7 : sans les colonnes conventionnelles, `$timestamps = true` par défaut fait tomber l'erreur 1054 **à la composition du QCM, en pleine manche**) · UNIQUE `round_choice_set_round_locale_uq (round_id, locale)`.

**`#[Hidden(['choice_1', 'choice_2', 'choice_3', 'choice_4'])]` sur le modèle, et `$hidden` au niveau modèle en plus de l'attribut** : `choice_1` **est la bonne réponse en clair**, et le § 1.7 pose que `#[Hidden]` est une règle de sécurité, pas de cosmétique. Un `toArray()` distrait dans une ressource de resynchronisation la publierait. Les quatre chaînes ne partent au client que par la ressource dédiée du QCM, **permutées**, à `T_N` (ou `T₁` en Facile). Test Pest : `$choiceSet->toArray()` ne contient aucune des quatre colonnes.

**Au plus une ligne par locale activée et par manche**, soit 2 lignes par manche et 20 par partie à deux locales — contre 480 lignes et ~60 Ko par partie pour une table `round_choice` par joueur, c'est-à-dire ~720 Mo sur 12 mois à 1 000 parties par mois. Le gain de volume qui motivait le refus d'une table par joueur est conservé, **et l'exigence de `05` est réellement tenue**.

- `choice_1` porte la chaîne du **film cible**, `choice_2..4` celles des trois leurres, dans l'ordre de `round.decoy_movie_id_1..3`. **La ligne n'est jamais sérialisée vers un client** ; la position du cible est connue du serveur seul.
- **L'ordre affiché reste dérivé** de `HMAC(game.draw_seed, round_id, player_id)` : rien n'est stocké par joueur, et l'ordre ne peut être ni deviné ni comparé entre deux écrans.
- `round_player.choices_locale` désigne la ligne utilisée par ce joueur. Un changement de langue en manche ne recompose jamais : les propositions repartent dans la **langue de composition**.
- **Règle de jugement** : une soumission `source = 'choice'` est jugée par **égalité stricte** contre `choice_1` de la ligne, et **ne consulte jamais `answer_key`**. Une correction de `movie_title` entre la composition et le clic ne change donc ni les quatre chaînes renvoyées à une resynchronisation, ni le verdict. Sans cette règle, le joueur qui clique la bonne case après une correction de titre est fermé pour la manche avec 0 point — et en Normal, un clic faux ferme aussi le texte libre.
- Le drapeau `choices_use_original_title` reste sur `round` : on dégrade les quatre propositions ensemble, jamais une seule.

### 7.9 `seen_frame` et `near_miss`

**`seen_frame`** · `id` · `room_id` bigint **NOT NULL** FK `cascadeOnDelete` — axe unique, donc **une partie solo n'écrit jamais ici** et ne pollue aucune mémoire · `frame_id` FK `cascadeOnDelete` · `last_seen_at` timestamp NOT NULL. `$timestamps = false`.

UNIQUE `seen_frame_room_frame_uq (room_id, frame_id)` : upsert à l'ouverture d'un palier et jointure gauche du tirage · index `seen_frame_last_seen_idx (last_seen_at)` : purge par lots bornés.

**Aucune colonne de joueur, aucun `player_id`, aucun `game_id`, aucun `round_id`** : l'axe joueur est retiré (jusqu'à 360 écritures par partie pour un simple départage), **tous voient la même image**, et le départage à égalité se fait par la graine de la partie. Pas de compteur d'occurrences : « la moins récemment vue » n'a besoin que de `last_seen_at`. **La ligne est upsertée à l'ouverture du palier**, jamais au lancement, et **sur `served_frame_id`**, pour qu'une manche annulée, une fin anticipée ou une substitution ne marquent jamais une image qui n'a pas été affichée. L'absence de ligne est le cas normal : le tirage n'est jamais bloqué.

> **L'axe salon est seul**, et `CLAUDE.md` §2 et §6 sont désormais alignés dessus (« aucun axe joueur », « `seen_frame` ne porte pas d'identifiant de joueur »). La règle vit ici : si une autre formulation réapparaît ailleurs, c'est cette spec qui fait foi.

**`near_miss`** · `id` · `movie_id` FK `cascadeOnDelete` — seul lien de la table, et il ne désigne aucune personne · `normalized_text` string(**200**), produit par le **même normaliseur** qu'`answer_key` · `occurrences` unsignedInteger défaut 1 · `distinct_rounds` unsignedInteger défaut 1 · `best_distance` unsignedTinyInteger · `first_seen_on` date · `last_seen_on` date · `dismissed_at` timestamp nullable, **sans auteur** : la table reste strictement vide de toute clé étrangère vers `users`.

UNIQUE `near_miss_movie_text_uq (movie_id, normalized_text)` · index `near_miss_last_seen_idx (last_seen_on)` : purge à 90 jours · `near_miss_movie_occ_idx (movie_id, occurrences)` : file d'alias triée.

**Deux gardes de k-anonymat, sans lesquelles la qualification « agrégée et sans aucune donnée personnelle » de la page de confidentialité est inexacte :**

> **Rien de `near_miss` ne s'exécute dans la requête de soumission — invariant L4.** Une tentative refusée dépose **au plus un message sur la file par défaut** (jamais la file `game`) ; le compteur de k-anonymat comme l'insertion de la ligne vivent **dans le job**. Le chemin de refus accomplit le **même travail, en même temps et en même nombre de requêtes**, quelle que soit la proximité de la réponse. Sans cette règle, une soumission jugée quasi-juste fait plus de travail qu'un refus franc — distance retenue, lecture **et** écriture du compteur, insertion un coup sur trois —, et l'écart de quelques millisecondes est mesurable : le tricheur soumet « le seigneur des ann », chronomètre, recommence avec « zzzzzz », et une dichotomie sur les préfixes lui reconstitue le titre **sans jamais regarder une image**, au palier 1, le mieux payé. C'est exactement l'information que le refus neutre existe pour supprimer, rendue mesurable par une table de conformité. Test Pest nommé : le refus d'une chaîne à distance 1 et celui d'une chaîne à distance 12 exécutent le **même nombre de requêtes SQL** et n'écrivent **aucune** ligne.

1. **Une ligne ne naît qu'au troisième couple (manche, chaîne) distinct.** En dessous, la chaîne est tenue en cache **par le job** et jetée. Une ligne `occurrences = 1` est la saisie d'**une** personne, et trois requêtes suffisent à la lui rattacher : `near_miss` donne (film, date) ; `round` filtré sur `(movie_id, started_at)` donne une seule manche ; `round_player` donne ses sièges, dont un avec `user_id` renseigné.
2. **`first_seen_on` et `last_seen_on` sont à granularité mensuelle** (date au 1er du mois). `distinct_rounds` remplace la date fine pour trier la file du curateur.

La table ne contredit pas l'interdiction de stocker une tentative fausse : ce n'est pas une ligne par tentative, c'est un compteur par (film, forme normalisée), purgé à 90 jours et non à 12 mois. **Aucun `player_id`, `room_id`, `game_id`, `round_id`, aucune IP, aucune locale.** La promotion d'une ligne crée un `alias` en `origin = 'curator'`, ce qui déclenche le projecteur `answer_key`.

### 7.10 Le mode solo, et pourquoi « voir la réponse » n'est pas un oracle

`game.mode = 'solo'`, `game.room_id` NULL, `player.room_id` NULL. « Voir la réponse » et « passer la manche » écrivent `round_player.input_state = 'revealed'` ou `'skipped'` et **ne créent jamais de ligne `guess`**. Quatre barrières structurelles :

1. `game.mode` est **figé à la création et aucun chemin ne le mute** : une partie multijoueur ne peut jamais acquérir l'action.
2. Un solo n'a ni salon, ni canal, ni second joueur : **aucune manche multijoueur n'est adressable depuis lui**.
3. Un solo **n'écrit pas `seen_frame`** : il ne renseigne rien sur la mémoire d'un salon.
4. **Le mode solo ne délivre jamais un identifiant d'image réutilisable dans une autre partie.** Les trois premières barrières empêchent le solo d'**adresser** une manche multijoueur ; elles ne l'empêchent pas d'en **apprendre le vocabulaire**. Avec un chemin de fichier stable en URL, le tricheur industrialise en solo — « voir la réponse » livre `N` paires (identifiant → titre) par manche, sans adversaire ni chrono, à la vitesse de la machine : au jalon 1 (60 films publiés, 180 frames) le dictionnaire est complet **en une soirée**, un userscript affiche ensuite le titre à `t = 0` dans toutes ses parties, et deux amis mutualisent leurs dictionnaires. D'où `round_tier.serve_token`, frappé **par manche** et non par frame (§ 7.4). Test Pest : deux manches distinctes portant la **même** frame produisent **deux `serve_token` différents**.

« Une manche révélée par voir la réponse n'est jamais comptée comme bonne réponse » devient ainsi une **propriété du schéma** plutôt qu'une règle de calcul : `correct_answers` compte des lignes `guess`, et `revealed` n'en produit aucune.

---

## 8. Modération et conformité

### 8.1 `report` — deux cibles, et deux signaleurs distincts

`id` · `target_type` string(16) + `ReportTarget` (`nickname` / `provider_avatar`) — **liste fermée à deux natures**, ce qui rend structurellement impossible de signaler un avatar prédéfini ou une image de jeu · `reporter_player_id` FK `→ player` `cascadeOnDelete` — le signaleur est désigné par son **siège**, un invité n'ayant pas de compte · `target_player_id` FK `→ player` `cascadeOnDelete` nullable — renseigné si et seulement si `target_type = 'nickname'` · `target_user_id` FK `→ users` `cascadeOnDelete` nullable — renseigné si et seulement si `target_type = 'provider_avatar'`, le masquage étant **global et non par salon** · `created_at` (`const UPDATED_AT = null`).

UNIQUE `report_reporter_player_uq (reporter_player_id, target_player_id)` · UNIQUE `report_reporter_user_uq (reporter_player_id, target_user_id)`.

> **Seuil unique de deux signaleurs DISTINCTS pour les deux cibles.** Les sources écrivent « un pseudo signalé deux fois » pour l'une et « deux joueurs distincts » pour l'autre : l'asymétrie de rédaction n'est pas une décision. Les deux uniques la ferment **en base** — deux lignes impliquent mécaniquement deux signaleurs. Sans l'unique côté pseudo, un seul joueur masquerait le pseudo d'un adversaire en cliquant deux fois, ce qui ferait du remède non punitif une arme de partie.

Index `report_target_user_idx (target_user_id)` · `report_target_player_idx (target_player_id)` · `report_created_idx (created_at)` : purge.

**Aucune adresse IP, aucun motif libre, aucune catégorie, aucune copie du fichier signalé.** « Aucune conservation de preuve » porte sur l'**image**, pas sur la ligne, qui doit survivre assez longtemps pour compter deux signaleurs.

**Aucune colonne `locale` non plus, et c'est un choix motivé.** `05` exige une locale stockée sur tout objet déclenchant un message sortant, parce que le middleware d'administration force `fr` : `takedown_request` en porte une, la voie publique n'ayant pas de compte. `report` est dans la situation inverse — le signaleur ne reçoit jamais rien, et le seul message sortant est la **notification de masquage**, adressée au titulaire du compte cible, dont la langue se lit dans `users.locale` via `target_user_id`. Un masquage de pseudo ne notifie personne. Ajouter une `locale` ici dupliquerait une valeur déjà disponible par jointure et ferait diverger la notification de la langue réelle du compte au premier changement. **L'état résultant vit ailleurs et survit** : `users.avatar_provider_hidden_at` et `player.nickname_masked_at`. Purger un signalement ne démasque jamais rien.

### 8.2 `takedown_request` — la preuve que l'engagement a été tenu

| Colonne | Type | Null | Raison |
|---|---|---|---|
| `id` | bigint unsigned | non | Jamais exposée au demandeur. |
| `reference` | char(12) | non | Numéro public, **aléatoire base32 et non dérivé de l'id** : un numéro séquentiel révélerait combien de demandes le service a reçues. UNIQUE. |
| `status` | string(20) + `TakedownStatus` | non | Colonne de file, filtrée et triée à chaque affichage. Défaut `received`. |
| `requester_name` | string(120) | non | Identité déclarée, sans quoi la demande n'est pas opposable. |
| `requester_email` | string(255) | non | Seul canal de l'accusé de réception et de la notification : la voie publique n'a pas de compte. |
| `requester_capacity` | string(20) + `RequesterCapacity` | non | Qualité déclarée (`rights_holder` / `agent` / `other`), filtrable. |
| `locale` | string(5) | non | Langue de réponse **stockée avec la demande** : le middleware d'administration force `fr`, donc `App::getLocale()` enverrait tout en français (`05`). |
| `claimed_scope` | text | oui | Portée telle que le demandeur l'a écrite, verbatim. Jamais interrogée. |
| `scope_kind` | string(10) + `TakedownScopeKind` | oui | `movie` / `frame` / `site`. **Portée réellement retenue par l'administrateur**, requêtable — aujourd'hui `claimed_scope` n'est que le texte du demandeur. |
| `body` | text | non | Pièce du dossier. Jamais interrogé. |
| `target_movie_id` | bigint unsigned | oui | Film identifié **au tri par l'administrateur**, pas par le demandeur. FK `restrictOnDelete`. |
| `target_frame_id` | bigint unsigned | oui | Image précise pour un retrait plus étroit qu'un film entier. FK `restrictOnDelete`. |
| `received_at` | timestamp | non | Point de départ des deux engagements (accusé sous 72 h, décision sous 7 jours ouvrés). |
| `acknowledged_at` | timestamp | oui | Preuve de l'accusé automatique, fait distinct de la décision. |
| `decision` | string(20) + `TakedownDecision` | oui | `suspended` / `unpublished` / `withdrawn` / `rejected` / `out_of_scope`. |
| `decision_reason` | text | oui | **Décision motivée**, exigée par l'engagement public. |
| `decided_at` / `decided_by_id` / `notified_at` | timestamp / bigint / timestamp | oui | Trois faits datés distincts. `decided_by_id` FK `nullOnDelete` — suffixe `_id` comme **toute** clé étrangère (§ 1.3). |
| `requester_anonymized_at` | timestamp | oui | Marque le vidage de l'identité à échéance (voir ci-dessous). |
| `timestamps` | timestamp | oui | La demande progresse dans sa file : ce n'est pas une table journal. |

UNIQUE `takedown_reference_uq (reference)` · index `takedown_status_idx (status, received_at)` : file et compte à rebours des 7 jours ouvrés · `takedown_movie_idx (target_movie_id)`.

**Aucune adresse IP**, malgré une voie publique anonyme : règle écrite et assumée, le garde-fou anti-abus étant un `throttle` nommé sur la route publique plus un captcha sans cookie, jamais une colonne.

> **Conservation : la preuve est permanente, l'identité du tiers ne l'est pas.** Écrire « conservation permanente » pour `requester_name` et `requester_email` — les coordonnées d'une personne qui n'a jamais eu de compte et les a fournies pour une seule demande — serait au-delà de toute durée justifiable, et c'est écrit noir sur blanc dans le tableau publié. Le périmètre de purge **`takedown_identity`** vide `requester_name`, `requester_email`, `claimed_scope` et `body` à l'échéance de prescription retenue par le conseil du porteur, **en conservant** la ligne, ses quatre dates, sa décision, son motif, sa portée et sa cible, et pose `requester_anonymized_at`. La preuve de l'engagement survit ; l'identité du tiers non.

Les gabarits FR et EN d'accusé et de notification vivent dans `lang/`, **jamais en base** (`05`).

### 8.3 `admin_action` — le journal en ajout seul

| Colonne | Type | Null | Raison |
|---|---|---|---|
| `id` | bigint unsigned | non | L'ordre des identifiants est l'ordre du journal. |
| `actor_id` | bigint unsigned | oui | FK `nullOnDelete`. En pratique toujours renseigné, l'anonymisation gardant la ligne `User`. |
| `actor_name` | string(255) | **non** | **Instantané de `users.name` à l'instant du geste**, même raison que `frame_review.reviewer_name` : sans lui, l'auteur d'un retrait juridique devient « n° 42 » dès qu'il supprime son compte. Exclu de l'anonymisation. **Pour les deux gestes automatiques — `avatar.hidden` et `nickname.masked`, déclenchés par le seuil de deux signaleurs distincts et non par une personne —, `actor_id` est NULL et `actor_name` porte la valeur réservée `'system'`** : sans elle, l'insertion est structurellement impossible sur une colonne NOT NULL, et une colonne nullable rendrait indistinguables « geste automatique » et « oubli d'écriture ». Un test vérifie qu'**aucune autre valeur d'action** n'écrit `'system'`. |
| `action` | string(40) + `AdminActionType` | non | Liste fermée : un champ libre rendrait le journal inexploitable au premier audit. |
| `subject_type` | string(20) + `AdminActionSubject` | non | `movie` / `frame` / `user` / `player` / `takedown_request`, en **alias court** et non en nom de classe : c'est cette colonne typée qui rend l'exemption de purge vérifiable par requête. |
| `subject_id` | bigint unsigned | oui | **Sans clé étrangère**, la cible étant polymorphe. La cible n'étant jamais détruite, la ligne ne pend jamais. |
| `takedown_request_id` | bigint unsigned | oui | FK `restrictOnDelete` + index. Écrit pour **tout geste pris en exécution d'une demande**. Sans lui, la seule jointure disponible est une corrélation d'horodatages entre `decided_at` et `created_at`, qui ne prouve rien quand deux demandes visent le même film la même semaine. |
| `retention_class` | string(12) + `AdminActionRetention` | non | `permanent` / `rolling_12m`, **écrite à l'insertion depuis l'enum d'action**. |
| `reason` | string(500) | oui | Motif, obligatoire applicativement pour un retrait juridique et une confirmation de contenu non classifié. |
| `reports_count` | unsignedTinyInteger | oui | **Nombre de signaleurs distincts figé au moment d'un masquage**, pour que le motif chiffré survive à la purge de `report`. |
| `role_before` / `role_after` | string(10) + `UserRole` | oui | Colonnes **typées et non JSON**, parce que « quel rôle portait cette personne à cet instant » est la seule requête d'audit qui doit s'écrire en SQL. Il n'existe **aucune table `role_history`** : elle dupliquerait `admin_action`, qui doit de toute façon consigner un changement de rôle. |
| `created_at` | timestamp | non | **`const UPDATED_AT = null;`** |

Index `admin_action_subject_idx (subject_type, subject_id, created_at)` · **`admin_action_retention_idx (retention_class, created_at)`** · `admin_action_actor_idx (actor_id, created_at)`.

**Gestes couverts** : `role.changed`, `movie.suspended` / `.unsuspended` / `.unpublished` / `.republished` / `.withdrawn` / `.content_verified`, `frame.suspended` / `.withdrawn`, `avatar.hidden` / `.unhidden`, `nickname.masked` / `.unmasked`, `nickname.banned`, `takedown.decided`.

**Classes de conservation** — `permanent` : tout geste dont le sujet est un `movie`, une `frame` ou une `takedown_request`, **plus `role.changed`** (parce que `frame_review.reviewer_id` ne prouve rien sans lui) **et les cinq gestes de masquage** `avatar.hidden`, `avatar.unhidden`, `nickname.masked`, `nickname.unmasked`, `nickname.banned`. `rolling_12m` : tout le reste.

> **Pourquoi les cinq gestes de masquage sont permanents.** Le masquage est un **état permanent levable par un administrateur seul** — `users.avatar_provider_hidden_at` et `player.nickname_masked_at`, tous deux déclarés hors purge. Ses deux seules justifications — la ligne `admin_action` et les lignes `report` — disparaîtraient avant lui : `report` est purgé à 12 mois, donc à 13 mois un pseudo masqué n'a **plus aucune justification en base**, et en mars 2027 le titulaire d'une photo masquée en février 2026 qui écrit pour contester trouve un administrateur incapable de motiver un refus comme de vérifier qu'il ne s'agissait pas d'un signalement abusif. **Une trace ne peut jamais être plus courte que l'état qu'elle justifie** — et c'est vrai du pseudo exactement comme de l'avatar, ce qui est la raison d'être de `nickname.masked` / `nickname.unmasked`, absents de la liste tant que le masquage automatique de pseudo n'avait ni geste ni acteur. Ces lignes ne portent qu'un identifiant de compte ou de siège, un horodatage, un motif et un compteur (`reports_count`).

**L'exemption de purge est une propriété du schéma, pas une clause `WHERE`** : l'index de purge étant `(retention_class, created_at)`, le balayage **ne rencontre structurellement jamais** une ligne permanente — elle est hors de l'index parcouru, et non exclue par une condition qu'un futur développeur pourrait oublier. C'est la panne dont le document qualifie la conséquence de juridique, pour un coût de 130 à 330 heures de recadrage.

---

## 9. Import TMDB

### 9.1 `import_run` — la provenance, et la reprise

Table **ajoutée** hors de la liste engageante, justifiée par trois besoins qu'aucune autre table ne porte : reprendre un balayage de 500+ films interrompu par le quota TMDB ou un `queue:restart` (un balayage non reprenable n'aboutit jamais) ; prouver qu'un balayage a été élargi (sans quoi le marquage d'exception devient arbitraire) ; faire survivre l'étranglement de quota à un redémarrage de worker.

`id` · `run_kind` string(12) + `ImportRunKind` (`discover` / `paste` / `resync`) — les trois n'ont ni le même filtre, ni le même droit d'écrasement, ni la même conséquence sur `is_import_exception` · `status` string(12) + `ImportRunStatus` défaut `running` · `actor_id` FK `nullOnDelete` · `filter_min_vote_count` unsignedInteger nullable · `filter_languages` string(64) nullable — chaîne jointe par virgules, **jamais interrogée** : affichage et rejeu, explicitement pas un critère de requête · `filter_min_release_year` smallint nullable · `is_widened` boolean défaut `false` — comparaison du filtre appliqué au filtre par défaut, **figée au démarrage** · `tmdb_page_cursor` unsignedInteger nullable · `last_request_at` timestamp nullable · `total_seen` / `total_imported` / `total_skipped` / `total_refused_content` unsignedInteger défaut 0 · `started_at` / `finished_at` timestamp nullables · `timestamps`. Index `(status)` : retrouver un balayage à reprendre au démarrage d'un worker.

`total_refused_content` est le nombre de lignes refusées par le filtre de **contenu** — chiffre qu'il faut pouvoir produire devant une mise en demeure autant qu'à l'écran d'aperçu d'un collage. **Aucune adresse IP, aucune donnée de joueur.**

`App\ValueObjects\Catalog\ImportFilter` (`minVoteCount`, `languages`, `minReleaseYear`) est construit depuis `config('catalog.import_filter')` pour les défauts, ou depuis les colonnes `filter_*` pour rejouer un balayage. Il expose `isWiderThanDefault()`, qui pose `is_widened`, et `exceptionMotivesFor()`, qui pose les trois booléens de `movie`. **Aucune persistance propre** : les colonnes typées d'`import_run` le portent. Les valeurs par défaut (500 votes, `{fr, en, ja}`, 1970 — décision 11) vivent en configuration, jamais en littéral dans une migration ni un FormRequest.

### 9.2 Les deux voies et leurs filtres asymétriques

| | Balayage `discover` | Voie d'exception (`paste`) |
|---|---|---|
| Filtre **de goût** (notoriété, langue, date) | appliqué | **ignoré entièrement** |
| Filtre **de contenu** (`adult`, FR -18, US NC-17, US X) | appliqué | **appliqué — jamais contournable, par aucune voie** |
| `is_import_exception` | `false`, sauf si `is_widened` | **`true` toujours**, même si le film satisfait tout le filtre |
| Trois motifs | posés par `ImportFilter::exceptionMotivesFor()` | idem, et cumulables |

**Séparation normative** (décision 11) : les filtres de goût sont contournables et **tracés** ; les filtres de contenu (décision 12) ne le sont **jamais**. `is_import_exception` n'est pas dérivable des trois motifs, d'où une quatrième colonne.

Trois booléens plutôt qu'un enum ou du JSON : un film cumule souvent plusieurs motifs, le back-office doit **compter par motif**, et un tableau JSON serait interdit de requête (§ 1.6).

**Import en deux temps** : `discover` filtré, puis appel de détail avec `append_to_response=release_dates`. **Les certifications ne sont jamais filtrées dans `discover`** — `certification.lte` n'accepte qu'un pays et écarte silencieusement les non classifiés — mais lues à l'appel de détail, résolues « la plus récente fait foi » en PHP, et seule la gagnante est stockée dans `movie_certification`.

**Déduplication** : `SELECT id, availability FROM movie WHERE tmdb_id = ?`, en lot de 200, servi par `movie_tmdb_uq`. Un film `withdrawn` heurte l'unique, le code lit `availability` et refuse, avec le motif, l'auteur et l'horodatage déjà consignés sur place. **Aucune table de bannissement** (arbitrage A10).

**Homonymes et remakes** : le back-office **suggère** des candidats de `movie_group` — formes normalisées proches lues dans `answer_key`, ou `collection_id` identique — et **ne regroupe jamais seul**. Le groupe survit au réimport.

**Quota TMDB** : la politique d'étranglement est un **limiteur de débit en code**, pas une table. Le schéma n'en porte que l'état reprenable (`last_request_at`, `tmdb_page_cursor`, compteurs). La liste d'amorçage d'environ 200 identifiants est un **fichier livré au dépôt**, collé dans la voie d'exception — ni table, ni colonne.

### 9.3 Resynchronisation manuelle — liste close de ce qui est écrasable

| Écrasable par une resynchronisation | **Jamais touché** |
|---|---|
| `title_original`, `title_original_latin`, `original_language`, `release_year`, `vote_count`, `adult`, `collection_id`, **les lignes `movie_tmdb_tag`** — métadonnées TMDB pures, sans exception manuelle possible, l'exception d'appartenance vivant dans `movie_theme.manual_state` | `movie_title` et `alias` dont `origin = 'curator'` |
| les lignes `movie_certification` | `movie_difficulty_override`, `group_id` |
| les seules lignes `movie_title` et `alias` dont `origin = 'tmdb'` | toute ligne `movie_theme` dont `manual_state` n'est pas nul |
| `movie_difficulty_derived`, les lignes `movie_theme` automatiques, `movie_projection`, `answer_key` — reprojetés librement | `is_import_exception` et ses trois motifs, `import_source`, `availability` et son motif, `content_verified_by_id` / `content_verified_at`, les colonnes de curation, tout `frame` et tout `frame_review` |

**Sans la colonne `origin` sur `movie_title` et `alias`, une resynchronisation détruit silencieusement la correction de titre qui est la tâche quotidienne du curateur** — c'est le défaut que cette liste existe pour empêcher.

**Le filtre d'import n'est jamais réappliqué** : un film marqué exception n'est jamais proposé au retrait pour sa langue, ses votes ou sa date. La relecture des certifications peut poser `content_flag = 'blocked'` et **proposer** une dépublication ; elle ne change jamais `availability` elle-même et ne supprime jamais un fichier. L'écran de différences compare la nouvelle lecture à `movie_certification.read_at`.

---

## 10. Stockage des images

**Disque `frames`, privé**, racine venue de `FRAMES_DISK_ROOT` — à ajouter à `.env` **et** `.env.example` —, **hors du chemin de déploiement**. **`'serve' => false`** : la route native de `FilesystemServiceProvider` n'est **pas enregistrée du tout** pour ce disque, et le § 10 ne s'appuie plus sur « les routes `storage.local` déjà enregistrées ». Le service passe exclusivement par une **route applicative dédiée** (ci-dessous). C'est ce qui rend un retrait effectif en minutes : argument de conformité autant qu'argument anti-triche (principe 12).

**Deux préfixes, deux noms aléatoires indépendants** — `App\Support\Frames\FrameStoragePrefix` :

| Préfixe | Contenu | Plafonds de **sortie** | Servable à un joueur |
|---|---|---|---|
| `game/` | Dérivé servi, WebP | **1280 px, 150 Ko** (taille paddée, § 4.1) | oui, et **uniquement lui** |
| `master/` | Source de re-cadrage, WebP | 1920 px | **jamais** |

Le plafond d'**entrée** est distinct des deux plafonds de sortie de ce tableau : c'est `PlatformLimits::frameUploadMaxKilobytes()` = 1 536 Ko (§ 6.1). **Les trois sont vérifiés côté serveur après réencodage Imagick.**

**La route de service refuse structurellement tout chemin ne commençant pas par `game/`** : le préfixe `master/` est non servable **par construction et non par convention**.

`game_path` et `master_path` portent **deux noms sans aucun lien entre eux**, tirés de `bin2hex(random_bytes(16))` et **non d'un ULID** : un ULID est trié dans le temps, ses dix premiers caractères encodent l'instant de création et regroupent donc visiblement les images curées dans la même séance, c'est-à-dire celles d'un même film. Le chemin s'éclate en deux niveaux de répertoires calculés sur un **condensat** du nom. Un nom unique partagé ferait qu'un service de jeu légitime révélerait le chemin de la source de re-cadrage. **Aucun répertoire par film, aucun titre, aucun `tmdb_id`, aucune année dans un chemin** (principe 2). Chemins **relatifs** en base, jamais une URL, jamais un chemin absolu.

> **Le chemin ne quitte jamais le serveur — la route `GET /f/{serve_token}`.** Vérifié : `FilesystemServiceProvider` enregistre `Route::get($uri.'/{path}')->where('path', '.*')`, donc le chemin `game/xx/yy/<nom>.webp` **est** le segment d'URL ; et `ServeFile::hasValidSignature()` n'appelle que `hasValidRelativeSignature()`, donc l'URL est un **porteur lié au chemin**, jamais à un joueur ni à une manche. Le montage natif obtient « non devinable » — il n'obtient **pas** « non réutilisable », et c'est la seconde propriété qui compte : le tricheur note dans l'onglet Réseau le chemin de l'image du palier 1, attend la révélation, enregistre la paire (chemin → titre), et industrialise en mode solo (§ 7.10).
>
> **Décision** : le service d'image passe par une route applicative dédiée **`GET /f/{serve_token}`** (signée, `throttle` nommé) et **jamais** par la route native. Elle résout `serve_token → round_tier → frame`, applique le **prédicat de signature en trois parties du § 4.1** — `Frame::isServable()` **ET** la garde temporelle **ET** l'appartenance du demandeur à la manche —, puis diffuse les octets. `frame.game_path` redevient un chemin interne.
>
> **Elle ne pose jamais `Content-Disposition`, `Last-Modified`, `ETag` ni aucun en-tête dérivé du fichier** ; elle ajoute `X-Robots-Tag: noindex` (déjà exigé par `00`) et conserve `no-store`. Fait mesuré à conserver explicitement : `FilesystemAdapter::response()` pose `Content-Length` et, faute de clé fournie, `Content-Disposition: inline; filename="<basename>"` — le nom du fichier repartirait donc dans un **en-tête** même si l'URL ne le contient plus ; et `ServeFile` ne pose **ni `Last-Modified` ni `ETag`**, la `StreamedResponse` remplaçant les en-têtes, tandis qu'une route bâtie sur `BinaryFileResponse` les rétablirait et re-fabriquerait l'empreinte, avec en prime l'instant de curation qui regroupe les frames d'un même film.
>
> **L'anti-corrélation porte sur trois surfaces, et non sur une** : l'**URL** (jeton par manche), la **taille servie** (`game_bytes` quantifiée au multiple de 8 Ko, § 4.1 — sans quoi le dictionnaire se reconstruit sur la seule taille, via le même atelier solo, sans jamais toucher à l'URL) et les **en-têtes**. Traiter la question comme un problème de nom de fichier n'en couvre qu'un tiers.

**Nuance d'exploitation** : la route dédiée émet `Cache-Control: no-store`, à peser pour le préchargement. C'est aussi pourquoi les **avatars** ne vivent pas ici (§ 5.3).

> **Bornage de la signature dans le temps, et c'est un changement de produit assumé.** `00-overview.md` fait pousser au client les URL signées des `N` variantes pendant la révélation précédente. Avec les cinq images en cache navigateur à `t = 0`, un joueur ouvre l'onglet Réseau, lit l'image de niveau 5 — le plan iconique — et répond à 0,8 s : **500 points plus bonus maximal sans avoir jamais vu le palier 1**, soit 5 000 points contre 1 000 pour un joueur honnête sur 10 manches. Le serveur reste autoritaire sur le **score** ; il ne l'est pas sur la **disponibilité de la réponse**, et le gradient de difficulté qui est le jeu lui-même est annulé.
>
> **Décision** : une URL du palier `i` n'est **ni signée ni servie** avant `round.started_at + round_tier[i].starts_at_offset_ms − game.preload_lead_ms`, **`i = 1` compris** — la fenêtre `P` de la manche 1 étant la seule exception, elle aussi bornée. La garde est écrite **une** fois, au § 4.1 (partie 2 du prédicat de signature), et citée aux § 7.4, § 10 et § 12.
>
> **`preload_lead_ms` est une constante serveur figée sur la partie, jamais `config('game.preload_lead_ms')` et jamais la durée d'un palier.** `dᵢ` est un **réglage** de 5 à 120 s, chaque palier ajustable en Avancé : une constante globale ne peut pas le valoir, et les deux pannes sont symétriques — à `lead < dᵢ` la poussée à l'ouverture du palier `i` est refusée par la route et le préchargement ne se produit **jamais** (LQIP à chaque palier, ce que la borne croisée 4 cherche à éviter) ; à `lead > dᵢ` (palier de 5 s, lead de 10 s) la garde est plus permissive que le texte et laisse remonter plusieurs paliers. Et à `lead = dᵢ` le tricheur obtient **un palier entier d'avance** — à N=3 par défaut, l'image de niveau 5 est lisible dès `t = 10 s` au lieu de 20 s, soit 200 points au lieu de 100 plus le bonus — tandis que l'image du **palier 1** reste lisible pendant **toute** la révélation précédente, jusqu'à `R = 20 s` de lecture gratuite, sans chrono, sur l'image la plus cryptique : le tricheur répond alors à `t ≈ 0,3 s`, c'est-à-dire au **score maximal possible de la manche**. D'où : colonne `game.preload_lead_ms` (§ 7.2), écrite au lancement depuis `PlatformLimits::preloadLeadMs()`, **borne recommandée 1 500 à 2 500 ms**.
>
> **Le résiduel, noir sur blanc** : un tricheur voit chaque image `preload_lead_ms` avant son palier ; c'est le prix du préchargement, et c'est précisément pourquoi cette valeur est une constante serveur **courte** et non la durée d'un palier. Le repli des clients lents reste le **LQIP**, jamais un élargissement de la fenêtre.
>
> Le préchargement devient **par palier** — l'URL du palier `i + 1` est poussée à l'ouverture du palier `i`, qui dure au minimum 5 s par la borne croisée 1, largement de quoi précharger 150 Ko. Trois tests : refus à `Tᵢ − lead − 1 ms`, acceptation à `Tᵢ − lead + 1 ms`, et rejeu relisant `preload_lead_ms` depuis `game`. `00-overview.md` § préchargement et `60` sont à corriger en conséquence (§ 13.2, lot 0, point 5).

**Ce qui n'est pas retéléchargeable, donc ce qui doit être sauvegardé en octets.** Le périmètre du **tier froid** est une **requête, pas une colonne** : `source_kind = 'capture'` (ses deux dérivés) **UNION** les `game_path` de toute frame `availability = 'published'`, servie par `frame_source_kind_idx`. Rien d'autre n'est sauvegardé en octets : un master d'origine TMDB se reconstruit en retéléchargeant `tmdb_file_path` et en rejouant `crop_x/y/width/height`. **C'est pourquoi `tmdb_file_path` et les quatre paramètres de recadrage ne sont jamais effacés après traitement** — ils remplacent des octets par une référence. Une colonne booléenne `backup_cold` dupliquerait `source_kind` et pourrait diverger d'elle après un re-recadrage.

**Retrait juridique : les chemins ne passent pas à NULL** (arbitrage A9). Le job supprime les deux objets, vérifie `Storage::disk('frames')->missing()` sur les deux chemins, puis pose `files_deleted_at`. La commande **`takedown:reconcile`**, obligatoire après toute restauration au même titre que la purge, balaie `frame WHERE availability = 'withdrawn' AND (files_deleted_at IS NULL OR fichier présent)`, resupprime et journalise dans `purge_run` sous le scope `withdrawn_files`. Le job de retraitement d'image **refuse durement** une frame `withdrawn` : une image retirée ne peut jamais être re-téléchargée ni re-recadrée depuis `tmdb_file_path`, et un test le vérifie. Sonde quotidienne : `COUNT(frame WHERE availability = 'withdrawn' AND files_deleted_at IS NULL)` doit valoir **0**.

---

## 11. Rétention et purge

### 11.1 Le tableau complet

| Donnée | Durée | Colonne pilote / déclencheur | Scope `purge_run` |
|---|---|---|---|
| Faits de partie : `game`, `game_player`, `round`, `round_tier`, `round_player`, `round_choice_set`, `guess` | **12 mois glissants** | `game.ended_at`, indexée. Suppression **feuille d'abord**. | `game_facts` |
| Parties dont le job de clôture n'a jamais tourné | 13 mois | `game.started_at` (`game_started_idx`) avec `ended_at IS NULL` → clôture forcée (`status = 'interrupted'`, `ended_at` posé), puis purge. | `stale_game` |
| Salons dont le job d'archivage n'a jamais tourné | 48 h | `room.last_activity_at` avec `archived_at IS NULL` → archivage forcé. | `stale_room` |
| **Lobby jamais lancé** | **2 h** sans activité | `room.launched_at IS NULL AND archived_at IS NULL AND last_activity_at < now − 2 h`, servi par `room_archived_activity_idx`. **Archivage forcé, jamais suppression** : le geste emprunte l'unique chemin d'archivage et hérite de ses trois effets (recyclage du `room_code`, effacement de `nickname` et du hash de `player_token`, fermeture du rattachement tardif). Supprimer la ligne se heurterait de toute façon au `restrict` de `game_player` dès qu'une partie existe — et un lobby jamais lancé n'en a aucune, ce qui rend la distinction invisible et donc dangereuse. C'est la **troisième** des trois échéances nommées différemment, avec la clôture de partie (15 min, `game.paused_at`) et l'archivage (24 h, `room.archived_at`). | `stale_lobby` |
| Identifiants d'invité : `player.nickname`, `player.player_token_hash`, `game_player.display_nickname` — **les trois dans la même transaction** | 24 h après la dernière activité | `room.archived_at`. L'archivage est l'unique événement qui efface ces trois colonnes, recycle le `room_code` et ferme le rattachement tardif. | — (action, pas purge) |
| Idem, **sièges solo** | 24 h | `room_id IS NULL AND last_seen_at < now − 24 h` (`player_solo_expiry_idx`). Un siège solo n'appartient à aucun salon : sans ce second déclencheur, son pseudo vit douze mois. | `orphan_player` |
| Lignes `player` et `room` | dépendante | Après que **toutes** les parties du salon sont sorties de leur fenêtre. `DELETE … WHERE NOT EXISTS (game_player) AND NOT EXISTS (round_player) AND NOT EXISTS (guess)`. **Jamais piloté par `player.created_at`.** | `orphan_player` |
| `seen_frame` | **90 jours OU 500 manches**, la borne atteinte en premier | `seen_frame.last_seen_at` ; la borne des 500 manches se calcule **au moment de la purge** depuis `round (room_id, started_at)` avec `ORDER BY started_at DESC LIMIT 1 OFFSET 499`. Aucune colonne compteur. Fenêtre **totalement indépendante** des 12 mois. | `seen_frame` |
| `near_miss` | 90 jours | `near_miss.last_seen_on` (une **date**, pas un horodatage). | `near_miss` |
| `report` | 12 mois | `report.created_at`. L'état résultant vit ailleurs et survit. | `report` |
| `admin_action` de classe `rolling_12m` | 12 mois | Index `(retention_class, created_at)`. Le balayage ne rencontre **structurellement** jamais une ligne permanente. | `admin_action` |
| `data_export` | 7 jours | `expires_at`. **Supprime le fichier puis la ligne.** | `data_export` |
| Identité d'une `takedown_request` | prescription retenue | `received_at` + `requester_anonymized_at`. Vide quatre colonnes, **conserve la ligne et sa preuve**. | `takedown_identity` |
| Fichiers d'une frame retirée | immédiat, réconcilié | `availability = 'withdrawn' AND files_deleted_at IS NULL`. Rejoué après **toute restauration**. | `withdrawn_files` |
| Comptes dormants de rôle `player` | 24 mois sans connexion, rappel, **anonymisation 30 j plus tard** | Index `(role, last_login_at)`. La colonne de tête exempte `curator` et `admin` ; `anonymized_at` non nul exclut les pierres tombales. Fenêtre volontairement différente de 12 pour qu'on ne les confonde jamais. | `dormant_account` |
| `sessions` | `session.lifetime` | Suppression **déterministe quotidienne** sur `last_activity`, **jamais le seul tirage** — `config/session.php` pose `'lottery' => [2, 100]`, et sur un site peu fréquenté hors des pics des lignes portant une IP survivent des semaines. | `framework_sessions` |
| `failed_jobs` | 14 jours | `queue:prune-failed`. Le payload sérialisé d'une notification de décision échouée contient `requester_email` et le corps de la décision. | `framework_failed_jobs` |
| `password_reset_tokens` | défaut Fortify | `auth:clear-resets`. | `framework_reset_tokens` |
| `purge_run` | 13 mois | `ran_at`. Auto-purgé par le même job — une fenêtre de plus que la plus longue qu'il atteste. | `purge_run` |
| Adresses IP | 30 jours, **en logs uniquement** | — | — |
| Sauvegardes | 30 jours | Hors base. **La purge est idempotente et se rejoue immédiatement après toute restauration, avant de rouvrir le trafic**, avec `takedown:reconcile`. | — |

> **Reformulation nécessaire de l'engagement publié.** « Aucune adresse IP en table de domaine » est vrai ; « aucune adresse IP en base » ne l'est pas. `SESSION_DRIVER=database` et la migration du starter créent `sessions` avec `ip_address(45)` et `user_agent(text)`. La page de confidentialité écrit donc : **« aucune adresse IP dans une table métier ; l'adresse IP de session vit quelques heures en base et 30 jours en journaux »**. C'est aussi pourquoi `player.active_seat_token` ne contient jamais l'identifiant de session (§ 7.1).

### 11.2 Le périmètre **interdit**, nommé table par table

| Famille | Tables | Pourquoi c'est une propriété de schéma |
|---|---|---|
| **Catalogue** | `movie`, `movie_projection`, `movie_group`, `collection`, `movie_title`, `alias`, `answer_key`, `movie_certification`, `movie_tmdb_tag`, `theme`, `theme_label`, `movie_theme`, `import_run` | Toute FK d'un fait de partie vers le catalogue est en **`restrictOnDelete`** (`round.movie_id`, `round.decoy_movie_id_1..3`) : la purge **ne peut pas** y propager. Et aucune de ces tables ne porte d'index daté qu'un balayage pourrait emprunter. Une purge naïve ici détruirait 130 à 330 heures de recadrage. |
| **Images** | `frame`, `frame_review` | `frame_review` est en ajout seul et `restrict` sur `frame` ; `frame` est en `restrict` sur `movie`. Un retrait juridique supprime les **fichiers** — jamais la ligne. |
| **Actifs du compte** | `saved_config`, `linked_account`, `user_consent`, `users`, `setting_preset` | « Compte compris » dans la règle des 12 mois signifie « **pas d'exemption pour les comptes** », jamais « le compte est supprimé à 12 mois ». Ces tables ne portent volontairement **aucune colonne pilote datée ni index daté**. Elles sont en revanche supprimées par l'anonymisation, qui est un autre geste. |
| **Conformité** | `takedown_request` (la ligne et sa décision), `admin_action` de classe `permanent` | Une demande purgée est une preuve détruite : « décision motivée sous 7 jours ouvrés » n'est démontrable que si la demande **et** la décision survivent. |

**Deux tests Pest nommés, à horloge figée**, qui sont la forme exécutable de cette section : « une `saved_config` de 18 mois existe toujours après purge » et « une `frame` de 13 mois survit à la purge ».

### 11.3 `purge_run` — la sonde

`id` · `scope` **string(32)** + `PurgeScope` — 32 et non 20 : deux valeurs du tableau du § 11.1 dépassent 20 caractères (`framework_failed_jobs`, 21 ; `framework_reset_tokens`, 22). MySQL en mode strict lèverait une **1406 à l'insertion** là où SQLite tronquerait en silence, donc la purge de ces deux périmètres échouerait en production sans qu'aucun test ne l'ait vu · `status` string(12) (`running` / `completed` / `failed`) · `started_at` timestamp NOT NULL · `finished_at` timestamp nullable (NULL = en cours **ou plantée**) · `rows_deleted` unsignedInteger défaut 0 · `batches` unsignedSmallInteger défaut 0 · `duration_ms` unsignedInteger nullable · `error` string(500) nullable · `ran_at` timestamp NOT NULL · `timestamps` (§ 1.7 : les colonnes datées métier ne dispensent pas des colonnes conventionnelles). Une ligne par (exécution, périmètre). Index `purge_run_scope_idx (scope, started_at)` : « dernière purge réussie par périmètre » · `purge_run_ran_idx (ran_at)` : auto-purge. **Aucune FK, aucune donnée personnelle.**

Table **ajoutée** hors de la liste engageante, exigée nommément par les risques ouverts et conçue par aucun des domaines, chacun la renvoyant aux autres. C'est la sonde de la seule panne du projet dont la conséquence est juridique : **une purge silencieusement arrêtée rend fausse une durée annoncée publiquement.**

**Ordre de suppression, imposé par les `restrictOnDelete` et non par une consigne** : `guess` → `round_choice_set` → `round_tier` → `round_player` → `round` → `game_player` → `game` → `player` → `room`. Toute tentative dans un autre ordre échoue — c'est voulu : une cascade `room → player` bloquée en aval par le `restrict` de `game_player` fait échouer toute la transaction plutôt que de détruire un podium.

**Le job est résilient ligne à ligne** : une transaction par ligne, compteur d'échecs, reprise au suivant, **jamais un lot entier annulé**. Un lot borné trié du plus ancien au plus récent qui bute sur un `restrict` resélectionnerait éternellement la même ligne et le périmètre ne progresserait plus jamais.

**Quatre sondes de production, distinctes de « la purge a tourné »** :

1. `COUNT(game WHERE ended_at IS NULL AND started_at < now − 24 h) = 0`
2. `COUNT(room WHERE archived_at IS NULL AND last_activity_at < now − 48 h) = 0`
3. `COUNT(frame WHERE availability = 'withdrawn' AND files_deleted_at IS NULL) = 0`
4. « Un périmètre de `purge_run` n'a supprimé aucune ligne depuis 48 h alors que des lignes sont éligibles »

---

## 12. Requêtes chaudes et index

Une ligne par requête réellement chaude. Toutes sont servies par un index déclaré ci-dessus ; aucune n'introduit d'index nouveau.

| Requête | Fréquence | Index | Coût |
|---|---|---|---|
| Rejoindre un salon : `WHERE room_code_active = ?` (code **normalisé en majuscules en PHP avant la requête**) | Requête la plus fréquente du produit | `room_active_code_uq` | 1 ligne |
| Lien de salon archivé, pour afficher « salon expiré » plutôt qu'un 404 | Chaque lien partagé hors du site après archivage | `room_code_idx` | quelques lignes |
| **Vivier, avec thèmes** : `COUNT(DISTINCT mt.movie_id) FROM movie_theme mt JOIN movie m JOIN movie_projection p WHERE mt.theme_id IN (…) AND mt.is_active = 1 AND m.availability = 'published' AND m.content_flag = 'clear' AND p.levels_count >= :n AND NOT EXISTS (…)` | À chaque changement de thèmes **ou de `N`** dans un lobby, anti-rebondi à 300 ms, par lobby ouvert ; **rejouée dans la transaction de lancement** | `movie_theme_pool_idx (theme_id, is_active, movie_id)` **couvrant**, puis `movie_pool_idx (availability, content_flag, id)`, puis `movie_projection` par clé primaire | pas de `GROUP BY`, pas d'agrégat sur `frame` |
| **Vivier, sans thème** — le cas par **défaut** : `COUNT(*) FROM movie_projection p JOIN movie m WHERE p.levels_count >= :n AND m.availability = 'published' AND m.content_flag = 'clear' AND NOT EXISTS (…)` | Idem | `movie_projection_levels_idx (levels_count, movie_id)`, couvrant côté projection | idem |
| **Non-répétition** (réglage **activé par défaut**), dans les **deux** branches : `AND NOT EXISTS (SELECT 1 FROM round r WHERE r.room_id = :room AND r.movie_id = m.id AND r.started_at >= :fenetre)` | idem | `round_room_started_movie_idx` | — |
| Toutes les chaînes acceptées du film de la manche : `SELECT id, normalized, key_kind, is_ambiguous FROM answer_key WHERE movie_id = ?` | **Chemin le plus chaud du jeu** : 240/s au défaut, 1 200/s à la borne haute du réglage | `answer_key_movie_idx` | 10-20 lignes, en cache **sous L2** |
| « Cette chaîne désigne-t-elle un autre film publié ? » : `WHERE normalized = ?` | Une fois par appariement exact, plus une fois par valeur touchée à chaque (dé)publication | `answer_key_norm_movie_uq`, préfixe gauche | 1-3 lignes |
| Garde d'écriture d'une tentative : `round_player(round_id, player_id)` puis `guess(round_id, player_id)` | À chaque tentative, **avant tout travail de validation** | `round_player_round_player_uq`, `guess_round_player_uq` | 2 lectures de clé |
| Tirage des trois leurres à profil de titre identique | **Une fois par manche**, jamais par joueur | `movie_projection_qcm_idx (title_mask_version, title_locale_mask, movie_id)` | égalité indexée |
| Resynchronisation : manche ouverte → paliers → état de saisie | Chaque reconnexion, rechargement et frontière — **pic simultané pour tout le salon** | `round_game_status_idx`, `round_tier_round_index_uq`, `round_player_round_player_uq` | ≤ 5 + 1 lignes. **Le paquet ne porte jamais plus d'une URL d'image** (§ 15) : lire les `N` lignes `round_tier` n'autorise pas à en signer `N` — le prédicat de signature du § 4.1 s'applique ligne par ligne, et sa partie temporelle est ce qui distingue « lire un palier » de « servir un palier ». |
| Tirage d'une variante : ce que le salon a déjà vu, et quand | Jusqu'à 150 lectures au lancement, puis un upsert par palier ouvert | `seen_frame_room_frame_uq` | jointure gauche |
| Servir une image de palier : le **prédicat de signature en trois parties** du § 4.1 (catalogue **ET** garde temporelle **ET** appartenance à la manche) | **1 fois par palier ouvert et par joueur connecté**, jamais `N` fois d'un coup | `round_tier_serve_token_uq` pour résoudre le jeton, puis **clé primaire** vers `frame` ; aucun index secondaire, les trois colonnes de catalogue testées sont sur la même ligne | 2 lectures de clé |
| Back-office : incidents techniques par film | À l'ouverture de l'écran de curation | `round_movie_found_idx`, **préfixe gauche** `movie_id` | — |
| Fin anticipée : participants contre non-`open` | À chaque verrouillage, départ et expiration de grâce | `round_player_round_player_uq` (`round_id` en tête) + `player` par clé | ≤ 12 lignes |
| Podium et classement intermédiaire | Une fois par révélation, plus une au podium | `game_player_game_idx`, `guess_round_rank_uq` | ≤ 12 + ≤ 120 |
| Historique et les quatre compteurs sur 12 mois | À chaque ouverture de l'écran, cache court | `player_user_idx` → `game_player_player_game_uq` → `game_mode_ended_idx (mode, ended_at)` | — |
| Callback OAuth : `WHERE provider = ? AND provider_user_id = ?` ; liaison auto : `WHERE email = ? AND email IS NOT NULL AND email_verified_at IS NOT NULL` | À chaque connexion sociale | `linked_account_provider_uid_uq`, `users_email_unique` | 1 ligne |
| Configuration par défaut à la création d'un salon : `WHERE user_id = ? AND default_slot IS NOT NULL` | À chaque création par un hôte connecté | `saved_config_user_default_uq` | 1 ligne |
| Dormance / dernier admin / rôle privilégié sans 2FA | Job mensuel ; avant tout changement de rôle ; écran d'accès | `users_role_last_login_at_index`, colonne de tête | — |
| Décile de notoriété par langue : `WHERE original_language = ? ORDER BY vote_count` | Job périodique et après chaque balayage | `movie_decile_idx` | — |
| Back-office : file de re-revue, supervision « X films jouables à N », file des demandes | À chaque ouverture des écrans | `frame_grid_version_idx (review_grid_version, availability)`, `movie_projection_levels_idx`, `takedown_status_idx` | — |
| Purge, chaque table par sa colonne pilote unique | Quotidienne, lots bornés, **file par défaut, jamais la file `game`** | `game_ended_idx`, `game_started_idx`, `seen_frame_last_seen_idx`, `near_miss_last_seen_idx`, `report_created_idx`, `admin_action_retention_idx`, `data_export_expiry_idx`, `frame_withdrawn_files_idx` | — |

**Deux points à ne pas perdre de vue.** (1) Le compteur affiché dans le lobby et le compte rejoué dans la transaction de lancement utilisent **la même requête, à la même fenêtre de non-répétition** — recommandation : la même que `seen_frame`, 90 jours ou les 500 dernières manches, pour n'avoir qu'une seule notion de « récemment vu par le salon » à expliquer et à purger. Sinon le lobby annonce 47 films jouables et la garde de lancement n'en trouve que 7, sans cause visible entre deux écrans. (2) Avec zéro thème — le **défaut** du réglage — un `IN` vide rendrait un vivier de 0 et bloquerait le lancement en nommant les thèmes comme réglage fautif, alors qu'aucun thème n'a été choisi : la branche sans thème est la plus fréquente du lobby et elle est écrite ci-dessus.

---

## 13. Migrations, factories et seeders

### 13.1 Ce que `RefreshDatabase` impose, et ce qu'il n'impose pas

**Correction factuelle à retenir** : `RefreshDatabase` ne migre qu'**une fois par processus** (`RefreshDatabaseState::$migrated`, PDO en mémoire mis en cache), puis ouvre une transaction par test. **L'ordre de migration se choisit donc pour la correction des clés étrangères, jamais pour la vitesse.**

Les migrations SQLite ne sont **pas** transactionnelles (`supportsSchemaTransactions()` vaut `false`) : le `PRAGMA foreign_keys = OFF` émis par `compileAlter` fonctionne, et une FK ajoutée après coup reconstruirait proprement la table. La règle reste néanmoins : **chaque FK se déclare en ligne dans le `Schema::create` de la table qui la porte**, et **aucun test n'appelle jamais de migration**. Les deux cycles sont coupés par une référence souple, pour la raison de conception du § 1.5 et non par contrainte d'outillage.

### 13.2 Ordre de migration

**Lot 0 — dette du starter, avant toute table de domaine. `composer ci:check` est rouge avant la première ligne de code métier.**

1. Décommenter `->use(RefreshDatabase::class)` dans `tests/Pest.php` — 31 erreurs « no such table: users ». **Aucune migration de cette spec n'est prouvable avant**, et les deux tests de survie à la purge ne peuvent pas exister.
2. `vendor/bin/pint` sur les 12 fichiers en échec (`single_blank_line_at_eof`) ; `--memory-limit=1G` inscrit dans le script composer de PHPStan.
3. Corriger le PHPDoc `Carbon → CarbonImmutable` de `User`, **avant d'écrire un seul autre modèle**.
4. **Volet documentaire : fait le 22/09, à ne pas refaire.** `CLAUDE.md` est déjà aligné sur cette spec (axe salon seul de `seen_frame`, `avatar_upload` hors v1, `guess` n'enregistrant que des bonnes réponses, dette documentaire §9, disques `frames` et `avatars`, `FRAMES_DISK_ROOT`, `'serve' => false`, nom `RoomSettings`, lexique complété), de même que `00-overview.md` et `05-i18n-et-langues.md`. Ce qui reste est une **vigilance permanente**, pas une tâche : à chaque spec suivante, vérifier qu'aucune ligne de `CLAUDE.md` ne prend le pas sur `10` — le schéma n'a qu'un propriétaire. Pour mémoire, les lignes qui avaient été corrigées : §2 et §6 sur `seen_frame` (« par salon et par joueur », « repli non vue par le joueur ») ; §2, §6 et §8 sur `avatar_upload` et la modération des avatars téléversés, **hors v1** ; §5 sur le nom `GameSettings` ; §8 sur `storage:link`, dont la règle exacte est « aucune frame sur le disque public » ; §6 sur `guess`, qui n'enregistre que des **bonnes réponses** (les tentatives fausses sont comptées par `round_player.wrong_attempts`, jamais stockées) ; §9 sur la dette documentaire, `05` et `10` étant écrites ; §3 sur les deux disques `frames` et `avatars` et la variable `FRAMES_DISK_ROOT` ; §6 pour y inscrire les dix noms de tables ajoutés par cette spec.
5. Corriger `00-overview.md` : **préchargement par palier** et borne croisée 4 (§ 10 de cette spec) ; **interdiction de `storage:link` reformulée** en « aucune frame sur le disque public » (ligne 314, § 5.3) ; **prédicat de fin anticipée** (§ 7.7) ; **trace d'une capture sans « support »** (§ A7, à répercuter aussi dans `questions-ouvertes.md` décision 7) ; **liste des tables et lexique** (§ A15, § 6.1). Corriger `05-i18n-et-langues.md` : la **translittération latine est une colonne de `movie`, jamais un alias** (§ A4).

**Lot 1 — `users`, deux migrations distinctes et dans cet ordre**
6. `alter_users_email_and_password_nullable` — deux `->nullable()->change()` et **rien d'autre**.
7. `add_account_columns_to_users` — **uniquement des ajouts**, plus l'index `(role, last_login_at)`.

**Lot 2 — racines de catalogue** (aucune FK sortante, ou vers `users` seul)
8. `movie_group` · 9. `collection` · 10. `theme` · 11. `theme_label` · 12. `import_run`

**Lot 3 — le film et ses dépendances**
13. `movie` · 14. `movie_projection` · 15. `movie_tmdb_tag` · 16. `movie_title` · 17. `alias` · 18. `answer_key` · 19. `movie_certification` · 20. `movie_theme`

**Lot 4 — images**
21. `frame` (`published_review_id` **sans contrainte**) · 22. `frame_review`

**Lot 5 — actifs du compte et réglages**
23. `setting_preset` · 24. `saved_config` · 25. `linked_account` · 26. `user_consent` · 27. `data_export`

**Lot 6 — salon et sièges** — c'est ici qu'est le premier cycle
28. `room` (`host_player_id` **sans contrainte**) · 29. `player` · 30. `seen_frame`

**Lot 7 — faits de partie, strictement en ordre de dépendance**
31. `game` · 32. `game_player` · 33. `round` · 34. `round_tier` · 35. `round_player` · 36. `round_choice_set` · 37. `guess`

**Lot 8 — conformité et exploitation**
38. `near_miss` · 39. `report` · 40. `takedown_request` · 41. `admin_action` · 42. `purge_run`

Trente-cinq tables de domaine, plus les deux `ALTER` de `users`.

### 13.3 Le contrat de fixture — une partie jouable sans clé TMDB ni réseau

Le schéma conditionne une partie jouable à une chaîne de **sept faits indépendants**, dont deux portent sur des octets réels sur disque. C'est cette spec qui crée la contrainte, donc c'est elle qui écrit le contrat.

Pour lancer une partie de 10 manches, la garde exige `pool ≥ 10` et le tirage matérialise `min(M + 3, |pool|) = 13` films. Chaque film jouable à `N = 3` demande environ **15 lignes et 3 fichiers `.webp`** : 1 `movie` + 1 `movie_projection` + 1 `movie_certification` (ou `content_flag = 'clear'` posé par une coche, ce qui impose de **seeder un utilisateur curateur avant le catalogue**) + 1 à 2 `movie_title` + 2 à 3 `answer_key` + au moins 1 `movie_theme` + au moins 1 `movie_tmdb_tag` + 3 `frame` + 3 `frame_review`. Soit ~210 lignes, **plus les 3 comptes de démonstration**, et 39 fichiers binaires.

**Cinq exigences, sans lesquelles la démonstration ne tient pas :**

1. **`Frame::factory()->published()` écrit réellement un fichier** — une image WebP minuscule générée par Imagick — sur le disque `frames`, **calcule `published_hash` sur les octets écrits**, puis crée la `frame_review` `passed` avec `reviewed_hash = $frame->published_hash` et `grid_version = ExclusionGrid::CURRENT_VERSION`, et pose `frame.published_review_id`. **Jamais deux hashs tirés indépendamment** : avec un `fake()->sha256()` de chaque côté, aucun film n'est publiable et le lobby affiche « 0 film » sur un catalogue de démonstration complet. Et sans fichier réel, la première manche signe une URL vers un objet absent : `isServable()` était vrai en base, le moteur n'emprunte pas le chemin de substitution, et l'image reste cassée 30 s.
2. **`Movie::factory()->playable(int $n = 3)`** compose l'ensemble et recalcule `movie_projection` **dans la même transaction**, avec `title_mask_version = Locale::MASK_VERSION`, et **pose au moins une ligne `movie_tmdb_tag`** — sans étiquette, aucun thème de genre ni de studio n'est testable sur le catalogue de démonstration, la règle automatique n'ayant rien à évaluer. Test : `Movie::factory()->create()` produit toujours une ligne `movie_projection`.
3. **`FRAMES_DISK_ROOT` dans `.env.example`** et une étape de création du répertoire dans `composer setup`, faute de quoi le seeder écrit dans un chemin inexistant.
4. **Un test Pest de bout en bout** : « un salon créé sur le catalogue de démonstration peut lancer une partie de 10 manches ». C'est ce test, et lui seul, qui prouve que la chaîne des sept faits tient.
5. **Trois comptes de démonstration, un par rôle** (`player`, `curator`, `admin`), créés **avant** le catalogue — le curateur porte `movie.content_verified_by_id` et `frame_review.reviewer_id` / `reviewer_name` / `reviewer_role`, l'admin porte les lignes `admin_action` permanentes — **et au moins un film `is_import_exception = true`** avec un motif posé (`exception_for_release_year`, le cas de l'âge d'or Disney antérieur à 1970), pris parmi les 13. Sans les trois rôles, la propriété **absolue** de `saved_config` face à un admin, l'exemption de purge des comptes privilégiés et la file « rôle privilégié sans 2FA » ne sont testables par aucun test. Sans le film d'exception, le filtre back-office « entrés par exception » et le comptage **par motif** n'ont aucune donnée, et le marquage devient silencieux — exactement ce que la décision 11 interdit.

Les films de démonstration portent `import_source = 'demo'` et `tmdb_id = NULL` : ils sont exclus de la resynchronisation TMDB et des deux statistiques de débit de curation. **Interdiction absolue d'une image de jeu réelle ou d'un extrait de base de production en fixtures** (`100`).

### 13.4 Hypothèse de déploiement à vérifier

Tout est aligné sur UTC aujourd'hui (`config('app.timezone')`, `system_time_zone`, `date.timezone`). Le type `timestamp` de MySQL étant converti selon le fuseau de session, **un serveur de production sur un autre fuseau décalerait tous les horodatages de partie**. À vérifier à la mise en service du VPS : ce n'est pas une propriété acquise du schéma.

Écart cible / réel à ne pas laisser dériver, **sans incidence sur le schéma** : le moteur suppose Redis et Reverb ; le dépôt a session, cache **et** queue sur le driver `database` d'un MySQL distant, sans `ext-redis` ni `predis`. **Le schéma ne doit surtout pas compenser** — Redis n'est jamais source de vérité du temps, `round.started_at` et `round_tier` le sont, et le job diffuse la frontière sans jamais la décider. Rappel : `BROADCAST_CONNECTION=log`, il n'existe aucun temps réel aujourd'hui, donc `round.started_at`, `round_tier` et `round_player` doivent suffire à reconstruire un état **sans aucun événement**.

---

## 14. Arbitrages

Les choix non évidents, l'option écartée, la raison. C'est ce qui fait la différence entre une spec et une liste de colonnes.

### A1 — Un seul axe de disponibilité, ou deux ?

**Écarté** : `published_at` nullable (curateur) **et** `availability ∈ {available, suspended, withdrawn}` (admin), deux axes orthogonaux, prédicat de jeu `published_at IS NOT NULL AND availability = 'available'`.
**Retenu** : un enum unique `ContentAvailability` à **cinq** valeurs sur `movie` **et** sur `frame`, plus `first_published_at` (horodatage jamais réécrit, jamais un prédicat).
**Raison** : l'enum à cinq valeurs porte déjà les trois régimes exigés (`unpublished` curateur réversible, `suspended` admin conservatoire, `withdrawn` admin terminal) plus `draft`, et il se teste par **égalité** — donc indexable en tête d'index composite, ce que `published_at IS NOT NULL` (prédicat de plage) interdisait. Le prédicat de jeu devient `availability = 'published'` : **une seule vérité, un seul mot**, et trois index qui citaient `published_at` sont réécrits sur `availability`. Trois booléens auraient en outre autorisé des états impossibles (retiré **et** disponible).

### A2 — L'auteur d'un changement de disponibilité : sur la ligne ou dans le journal ?

**Écarté** : `movie.availability_actor_id` en plus de `availability_changed_at` et `availability_reason`.
**Retenu** : la **ligne porte l'état, sa date et son motif** — la fiche d'un film doit se suffire devant une mise en demeure, sans jointure — et l'**auteur vit uniquement dans `admin_action`**. `availability_actor_id` est supprimée.
**Raison** : deux sources pour le même fait divergent. Chaque changement écrit obligatoirement une ligne `admin_action` en `retention_class = 'permanent'`, et c'est **elle** qui est la preuve attribuée — avec `actor_name` figé, puisqu'une clé étrangère seule ne survit pas à l'anonymisation.

### A3 — `content_flag` entre-t-il dans le prédicat de vivier ?

**Écarté** : `content_flag` comme drapeau de curation seulement, non indexé (« 3 valeurs distinctes, MySQL ne l'emprunterait pas »).
**Retenu** : `movie_pool_idx (availability, content_flag, id)`, prédicat `availability = 'published' AND content_flag = 'clear'`.
**Raison** : c'est le seul montage qui rende simultanément vraies les deux règles écrites — « la relecture de certification **propose** une dépublication, ne la prononce jamais » et « filtres de contenu, **jamais contournables, par aucune voie** » (décision 12, principe 12). Sans lui : TMDB renvoie une certification FR -18 plus récente le 12 janvier, `content_flag` bascule en `blocked`, le curateur bénévole traite la file le 20 — et pendant huit jours le système **sait** que le contenu est exclu et le sert quand même à des invités qui n'ont déclaré aucun âge. Le film garde son état de curation `published` : la proposition reste une proposition, mais il sort du vivier **à la seconde**. Deux tests nommés : un film `published` + `blocked` n'est jamais compté ni tiré ; idem `published` + `unrated_pending`.

### A4 — Où vit la translittération latine d'un titre non latin ?

**Écarté** : une ligne d'`alias` avec un drapeau « affichable ».
**Retenu** : `movie.title_original_latin`, colonne `string(255)` nullable, invariante comme `title_original`.
**Raison** : `05` dit à la fois « stockée en alias » et « un alias n'est **jamais** affiché » — et l'affichage l'utilise. La loger en alias exigerait un drapeau qui troue la règle, **plus** une exemption au filtre de locale activée, puisqu'un film japonais n'a aucune locale de catalogue activée. Elle est une propriété de `title_original`, pas un alias. **La contradiction disparaît une fois `05` corrigé — ses lignes 24 et 150 disent encore « stockée en alias »** (§ 13.2, lot 0, point 5) : `alias` reste une table de validation pure qui n'alimente **aucun** chemin de rendu, et `answer_key` inclut `title_original` **et** `title_original_latin` inconditionnellement.

### A5 — `answer_key` : projection unique ou colonnes normalisées sur `movie_title` et `alias` ?

**Écarté** : une colonne normalisée et une colonne préfixe sur chaque table de texte, `answer_key` n'étant qu'une vue.
**Retenu** : `answer_key` **unique propriétaire** des formes normalisées et des préfixes.
**Raison** : deux sites de normalisation divergent silencieusement au premier changement d'algorithme, et le chemin chaud n'interrogerait alors plus un seul index. `answer_key` est reconstructible par une seule commande, par **différence** — jamais par purge et réinsertion, `guess` conservant douze mois l'identifiant de la clé retenue.

### A6 — Comment rendre un index de texte identique dans les deux moteurs ?

**Écarté** : collation explicite par colonne (vocabulaires disjoints) ; index fonctionnel sur `lower()` (`((expr))` MySQL contre `(expr)` SQLite, mutuellement exclusives) ; colonne générée `STORED` (refusée par SQLite en ALTER sur table non vide).
**Retenu** : **normalisation en PHP** vers l'alphabet `[a-z0-9 ]`, rangée dans une colonne ordinaire indexée sans collation déclarée.
**Raison** : sur un alphabet ASCII déjà replié, `utf8mb4_unicode_ci` et BINARY rendent **exactement le même verdict d'égalité** — la portabilité cesse d'être une propriété du moteur pour devenir une propriété de la **donnée**. `ext-intl` étant absent, la translittération se fait par table explicite, ni `Normalizer` ni `Collator`. Même idiome pour `saved_config.name_normalized` et pour `room_code`, normalisé en majuscules en PHP avant toute écriture **et toute requête**. Précédent du dépôt : Fortify minuscule déjà l'e-mail avant stockage.

### A7 — Que trace-t-on d'une capture personnelle ?

**Écarté** : appliquer la décision 7 à la lettre (support + édition + timecode).
**Retenu** : `source_kind`, `tmdb_file_path`, `source_timecode_ms`, `source_hash`, `published_hash` — et **aucune colonne de support, d'édition, d'appareil, de logiciel ou de format d'origine**.
**Raison** : le timecode désigne un instant **dans l'œuvre** ; le support désignerait le **canal par lequel on se l'est procurée**, c'est-à-dire exactement la méthode d'extraction que le risque 9 interdit de consigner — une colonne de support transformerait le dossier de conformité en aveu écrit sur la licéité de l'acte de capture, alors que l'engagement porté publiquement est la traçabilité de l'**œuvre**. Aucune métadonnée EXIF n'est lue (`stripImage`, `ext-exif` absent). **Arbitrage à confirmer explicitement par `20`, et à répercuter dans `00-overview.md` § Catalogue et dans `questions-ouvertes.md` décision 7, qui écrivent encore « support »** (§ 13.2, lot 0, point 5).
**Corollaire** : `source_hash` est calculé **côté serveur** sur les octets reçus, jamais sur un original que le serveur ne voit jamais. Une empreinte calculée par le client est une déclaration invérifiable, donc sans valeur de preuve, et le principe 1 interdit de la traiter autrement.

### A8 — Nommage des fichiers d'images

**Écarté** : un ULID par frame, servi sous les deux préfixes ; un ULID par dérivé ; un répertoire par film.
**Retenu** : **deux noms aléatoires indépendants**, `bin2hex(random_bytes(16))`, un par dérivé, chemins éclatés sur un **condensat** du nom — et un chemin qui **ne quitte jamais le serveur**, le client n'adressant une image que par `round_tier.serve_token` (§ 10).
**Raison** : un nom partagé ferait qu'un service de jeu légitime révélerait le chemin de la source de re-cadrage, qui ne doit jamais être servie à un joueur. Et un **ULID ne convient pas ici** : il est trié dans le temps, ses dix premiers caractères encodent l'instant de création, donc un éclatement sur son préfixe regrouperait dans un même répertoire les images curées ensemble — c'est-à-dire celles d'un même film — et deux ULID voisins trahiraient la même séance de curation même après éclatement sur condensat.

### A9 — Retrait juridique : met-on les chemins à NULL ?

**Écarté** : `game_path` et `master_path` passés à NULL en même temps que `availability = 'withdrawn'`.
**Retenu** : **les chemins sont conservés**, plus `files_deleted_at` et `files_deleted_error`, plus la commande `takedown:reconcile`.
**Raison** : la servabilité ne dépend que de `availability`, donc conserver les chemins n'expose rien — tandis que les effacer rend les fichiers **innommables** : si le job de suppression échoue sur une frame (erreur disque), la fiche affiche « retiré le 4 juin, fichiers supprimés » alors que le WebP est toujours là et qu'**aucune requête ne peut plus le retrouver**. Pire après restauration du tier froid : les octets retirés reviennent, et la seule consigne post-restauration écrite est « rejouer la purge » — or **la purge ne touche jamais `frame`, par construction**. D'où un scope dédié et une sonde à zéro.

### A10 — Blocage du réimport après retrait : table de bannissement ou état du film ?

**Écarté** : une table `tmdb_import_block`.
**Retenu** : la ligne `movie` survit avec `availability = 'withdrawn'` et son `tmdb_id` unique.
**Raison** : une table de bannissement dupliquerait une vérité déjà portée par la ligne du film et pourrait diverger d'elle ; un état horodaté avec motif était de toute façon exigé sur `movie`, et la purge ne touche jamais le catalogue.

### A11 — Le QCM : matérialisé en lignes par joueur, ou figé par manche ?

**Écarté** : une table `round_choice`, 4 lignes par (manche, joueur) — 480 lignes et ~60 Ko par partie, soit ~720 Mo sur 12 mois à 1 000 parties/mois.
**Écarté aussi** : figer les trois **films** seulement et dériver tout le reste d'une graine.
**Retenu** : `round_choice_set`, **une ligne par (manche, locale activée)** — 20 lignes par partie à deux locales, 24 fois moins — plus l'ordre dérivé de `HMAC(draw_seed, round_id, player_id)`.
**Raison** : le gain de volume qui motivait le refus d'une table par joueur est conservé, **et** l'exigence de `05` — « tout renvoi rejoue exactement les mêmes quatre **chaînes** » — est réellement tenue. Figer les films seulement laissait le jugement d'un clic passer par `answer_key`, que le projecteur reconstruit par différence : une correction de titre entre la composition à `T_N` et le clic à `T_N + 4 s` ferme la manche du joueur à 0 point **pour avoir cliqué la bonne case**, et en Normal ferme aussi son texte libre. Le jugement se fait donc par **égalité stricte** contre `choice_1`, sans jamais consulter `answer_key`.

### A12 — `lock_rank` : unique index, verrou, ou colonne supprimée ?

**Écarté** : `SELECT MAX(lock_rank) + 1` protégé par un index unique — l'index ne sérialise pas un calcul concurrent, il transforme une course en **bonne réponse perdue**.
**Écarté aussi** : supprimer la colonne et dériver le rang par `ORDER BY answered_at_ms, id`, ce qui supprimerait la classe de panne.
**Retenu** : allocation sous `lockForUpdate` sur la ligne `round`, `round.found_count` devenant le **compteur vivant** qui alloue, l'unique restant comme **filet**.
**Raison** : le rang d'arrivée est **visible des autres joueurs pendant la manche** et doit être un fait matérialisé du journal, pas une lecture recalculée douze mois plus tard sur des colonnes dont l'ordre pourrait changer. Le verrou ne coûte rien : la transaction de verrouillage doit **de toute façon** être atomique (insertion du `guess` + passage de `round_player` à `locked`), et il y a au plus 12 verrous sériels par manche. Bénéfice collatéral : `found_count` juste à tout instant, donc « film jamais trouvé » sans `COUNT` sur `guess`.

### A13 — Une manche annulée : réécrire `guess`, ou l'exclure à la lecture ?

**Écarté** : supprimer les lignes `guess` d'une manche annulée, ou ajouter une colonne d'annulation sur `guess`.
**Retenu** : l'invariant de lecture **L1**, écrit une fois et testé.
**Raison** : les lignes `guess` sont la **trace de l'incident** et la table est immuable. Sans L1, deux joueurs verrouillés au palier 2 d'une manche ensuite annulée pour image indisponible gardent 400 points et une bonne réponse sur une manche que le produit déclare sans points, et le taux de réussite de l'historique hérite du même faux. Test nommé : une manche `cancelled` portant deux `guess` de 400 points ne modifie ni `final_score`, ni `correct_answers`, ni `rounds_played`, ni le rang.

### A14 — `room_settings` : table ou value object ?

**Écarté** : une table `room_settings` partagée par `room`, `setting_preset` et `saved_config`.
**Retenu** : `RoomSettings`, `final readonly`, sérialisé dans quatre colonnes `json` doublées d'une colonne de version en clair. **Aucune table `room_settings`.**
**Raison** : le lexique dit « détachable », pas « détachée ». Une table ajouterait une jointure sur l'objet le plus chaud et une question de cycle de vie (ligne partagée ou copiée ?) que rien ne réclame, alors que le cast rend les quatre porteurs interchangeables. **C'est la table de trop, supprimée en la justifiant.**

### A15 — Tables écartées : deux de la liste engageante, quatre qu'on aurait pu croire nécessaires

**De la liste engageante de `00-overview.md`** :

| Table | Pourquoi elle n'existe pas |
|---|---|
| `avatars` | Les 24 prédéfinis sont des **fichiers statiques** de `public/` identifiés par une clé stable ; la copie provider est un chemin sur `users` ; `avatar_upload` est hors v1 (décision 14). Rien ne reste à une table, dont le nom au pluriel est de surcroît **hors lexique**. |
| `room_settings` | Value object — arbitrage A14. |

**Jamais dans la liste, écartées pour mémoire** :

| Table | Pourquoi elle n'existe pas |
|---|---|
| `plan` | Colonne `users.plan` à valeur unique `free`. Le hors-périmètre interdit **nommément** une table `plan` (décision 2). |
| `frame_coverage` | Absorbée par `movie_projection`, avec les deux masques de titre. Deux projections concurrentes, écrites par deux observateurs, divergeraient : le lobby annoncerait « 47 films » pour un vivier réel de 31, le lancement passerait la garde et le tirage échouerait à la manche 32. |
| `role_history` | Projection d'`admin_action` (`role_before` / `role_after` typées) plus l'estampille `frame_review.reviewer_role`. Une table dédiée dupliquerait un journal qui doit de toute façon consigner le geste. |
| `played_movie` | Portée par `round.room_id` + `round_room_started_movie_idx`, qui sert **aussi** la borne des 500 manches de `seen_frame` : une table et un job de maintenance en moins pour une colonne de huit octets par manche. |

**Aucune table créée non plus** pour : rôle premium, facturation, feature flag, chat, réaction, fusion de comptes, partage de configuration, classement global, blocage de réimport (A10), grille d'exclusion (versionnée en code), liste noire de pseudos (ressource versionnée), table de modération d'avatar téléversé.

**Ajoutées, et justifiées** : `movie_projection`, `movie_tmdb_tag`, `movie_theme`, `theme_label`, `movie_certification`, `import_run`, `round_player`, `round_choice_set`, `user_consent`, `data_export`, `purge_run`. Chacune porte un besoin qu'aucune autre table ne porte, et chacune est justifiée à sa section. La liste de `00-overview.md` est « un point de départ engageant, pas une limite » — et le raisonnement vaut symétriquement dans les deux sens : chaque table coûte un modèle, un PHPDoc exhaustif, une factory typée niveau 7 et une policy.

### A16 — Invariants qu'aucune contrainte de base n'exprime

`Blueprint` n'expose aucune API `check()`, la syntaxe de nommage diffère entre MySQL et SQLite, et un `DB::statement()` brut ne serait pas rejoué à l'identique. Ces invariants sont donc portés par les enums, le value object, l'action unique d'écriture et des **tests Pest nommés** :

`frame_level` entre 1 et 5 · `frames_per_round` entre 2 et 5 · `capacity` entre 2 et 12 · exactement une cible non nulle sur `report` · sur `frame`, `availability = 'published'` implique une revue passante sur les octets courants (**plus la sonde `published_review_id IS NULL` à zéro**) · sur `movie`, **`availability = 'published'` implique `content_flag = 'clear'` ET `levels_mask & 21 = 21`** (§ 4.3), avec le test nommé : « un film couvrant 1, 2 et 4 ne peut pas être publié, et un film publié dont la dernière variante de niveau 3 est dépubliée déclenche le recalcul **synchrone** de `levels_mask` dans la même transaction » · **les trois colonnes de clé d'avatar prédéfini portent la même longueur déclarée** (`users.avatar_preset`, `player.avatar_preset`, `game_player.display_avatar_preset`, toutes `string(40)`) — test trivial, mais c'est exactement le genre de divergence qu'aucune contrainte de base ne rattrape · rôle privilégié implique 2FA active (middleware + file de back-office, servis par `(role, last_login_at)`) · plafond de 20 `saved_config` par utilisateur · capacité jamais réglable sous l'effectif présent · `duration_ms % 1000 = 0` et `SUM(duration_ms) = round.duration_ms` · `input_state = 'locked'` ⟺ une ligne `guess` · `revealed` / `skipped` inatteignables hors solo · `round_number` non unique, **volontairement**.

---

## 15. Ce que cette spec ne décide pas

| Sujet | Spec propriétaire |
|---|---|
| L'algorithme de **normalisation** des réponses : translittération des diacritiques **sans `ext-intl`**, articles par langue, chiffres romains/arabes, seuil de tolérance Levenshtein calibré sur le corpus multilingue, seuil qui qualifie une quasi-juste. La spec 10 n'est propriétaire que du **stockage** de son résultat et de la garantie d'équivalence SQLite/MySQL sur l'alphabet replié. | `70-validation-des-reponses.md` |
| La **formule exacte du bonus de rapidité** et la chaîne complète de départage du podium. Le schéma fournit les entrées figées (`guess.tier_index`, `answered_at_ms`, les trois colonnes de points, `game_player.total_answer_time_ms` et `final_rank`, `game.scoring_version`) mais n'écrit **aucune** règle de calcul. **À confirmer par `80`** : une manche `cancelled` ne compte pas dans `rounds_played`, donc ne pèse pas sur le taux de réussite. | `80-scoring-podium-et-fin-de-partie.md` |
| L'**algorithme de tirage** : répartition nominale par `N`, repli de niveau sans doublon, préférence « non vue par le salon », `min(M + 3, |pool|)`, jamais deux films d'un même `movie_group`. Le schéma stocke ses **entrées** (`draw_seed`, `draw_pool_size`, `movie_projection.levels_mask`, `seen_frame`) et ses **sorties** (`round`, `round_tier`), jamais ses règles. `App\ValueObjects\Catalog\FrameLevelCoverage` est le seul endroit où N=2 → 1,5 ; N=3 → 1,3,5 ; N=4 → 1,2,4,5 ; N=5 → 1..5 est écrit. | `30-themes-vivier-et-tirage-des-variantes.md` |
| Le **contrat d'événements Reverb** et la forme exacte des charges utiles ciblées. La spec 10 pose la contrainte de sérialisation — `#[Hidden]` sur `round.movie_id`, `decoy_movie_id_*`, **`round.choices_use_original_title`**, `round_tier.frame_id` / `served_frame_id` / **`frame_level`** / **`serve_token`**, **les quatre colonnes `round_choice_set.choice_1..4`**, `game.draw_seed`, `settings_snapshot`, **`game.draw_pool_size`**, `player.id` / `player_token_hash` / `active_seat_token`, les empreintes et chemins de `frame` ; ressources dédiées, **jamais de `toArray` distrait** (test : `$round->toArray()`, `$roundTier->toArray()` et `$choiceSet->toArray()` ne contiennent aucune de ces colonnes) — sans décrire les messages. **Contrainte supplémentaire posée ici** : le paquet de resynchronisation ne porte **jamais plus d'une URL d'image** — celle du palier courant, plus celle du palier suivant **si et seulement si** la garde temporelle du § 4.1 est déjà franchie. La **forme** du message reste propriété de `60` ; la borne ne l'est pas. Test Pest nommé : « une resynchronisation à `t = 1 s` d'une manche à N=5 ne renvoie qu'**une** URL ». | `60-moteur-de-partie-temps-reel-et-mode-solo.md` |
| Le **contenu item par item de la grille d'exclusion** et sa politique de versionnement. Volontairement **sans table**. Le schéma ne fournit que `frame_review.grid_version` et le stockage des réponses par slug. La **confirmation de l'arbitrage A7** (abandon du support et de l'édition) lui revient aussi. | `20-back-office-curation.md` |
| L'**« entraînement assisté »** du mode solo (« passer la manche », « voir la réponse ») : occurrence unique dans tout le corpus, **spécifié nulle part**. Le schéma porte le marqueur (`round_player.input_state`) et la garantie structurelle qu'il ne produit jamais de `guess`, mais la fonctionnalité doit être écrite avant d'être codée. | `60-moteur-de-partie-temps-reel-et-mode-solo.md` |
| Le **préchargement par palier** que le bornage de signature du § 10 impose, et la correction correspondante de `00-overview.md`. | `60-moteur-de-partie-temps-reel-et-mode-solo.md` |
| La **liste noire de pseudos** par langue activée : ressource versionnée consommée par la validation. Aucune table, aucune colonne. Le **filtrage des pseudos**, la **matrice de capacités** des trois rôles et la définition des quatre compteurs d'historique. | `40-comptes-auth-sociale-et-avatars.md` |
| Les **libellés et le texte** de chaque réglage, les messages de bornes croisées, et le **comportement** de la purge du lobby à 2 h (message affiché, moment du compte à rebours, recyclage du `room_code` vu du joueur). **La colonne pilote (`room.launched_at`), le périmètre `stale_lobby`, l'effet — archivage forcé, jamais suppression — et l'index restent ici** (§ 11.1). Le schéma n'expose par ailleurs que les colonnes et les `restrictOnDelete` qui imposent l'ordre. | `50-salon-reglages-presets-et-lobby.md` |
| Le **calibrage du couple (`attemptsPerRound`, `\|pool\|`)**, qui est un **budget de force brute** et non une simple ergonomie : `attemptsPerRound = ceil(D / 2 s)` plafonné à 30, avec une cadence réglable de 1 à 5 tentatives par seconde, face à un vivier publié de **60 films au jalon 1** (décision 3) que la non-répétition réduit encore — un client scripté qui soumet 30 titres tirés du catalogue a une probabilité non négligeable de tomber juste **sans regarder l'image**, au palier 1, le mieux payé, et la tolérance Levenshtein élargit chaque essai. La question est **nommée ici** pour qu'elle ne tombe pas entre deux specs : les seuils reviennent à `70`, les bornes à `50`, et le couple est à **recalibrer quand la taille réelle du vivier est connue** — il est bien plus contraignant au jalon 1 qu'à 500 films. **Aucune colonne nouvelle n'est nécessaire pour l'observer** : une jointure `round_player.wrong_attempts` × `guess.tier_index` donne déjà la sonde « manches gagnées au palier 1 après plus de dix tentatives ». | `70-validation-des-reponses.md` et `50-salon-reglages-presets-et-lobby.md` |
| La **règle de langue** : locales activées, négociation, `HasLocalePreference`, chaîne de repli d'affichage, homogénéité linguistique du QCM. Le schéma en porte le stockage et `Locale::MASK_VERSION`. | `05-i18n-et-langues.md` |
| La **politique d'étranglement du quota TMDB** (fenêtre, 429, back-off) : limiteur de débit en code. Le schéma n'en porte que l'état reprenable. La **liste d'amorçage** d'environ 200 identifiants est un fichier du dépôt. | `20-back-office-curation.md` |
| La place des tests nommés ici dans le pipeline, les seuils de la matrice de réglages, et l'exécution d'au moins un test de longueur de colonne **sur MySQL en CI** — SQLite n'applique aucune contrainte de `VARCHAR` et ne couvrira jamais une troncature. | `100-qualite-tests-et-ci.md` |

**Hors v1, écrit ici pour que ce ne soit pas une découverte** : la translittération latine d'un `movie_title` **localisé** (par opposition à celle de `title_original`) n'existe pas — les deux locales d'interface activées sont latines, donc le repli d'affichage ne peut atteindre une écriture non latine que par `title_original`. Activer une locale d'interface non latine en v1.1 demanderait une colonne sur `movie_title`.








