# Validation des réponses

Ce document est le **propriétaire unique de la règle de validation** de TripleFrames : ce qu'un joueur peut soumettre, à quel instant, combien de fois, comment sa saisie est normalisée et appariée, ce qui fait d'une chaîne une bonne réponse, comment une bonne réponse se verrouille, et comment se composent les quatre propositions du QCM. Il rédige en règles complètes les trois contrats dont il est propriétaire dans la feuille du 23/09 — **C10** (service de soumission et transaction de verrouillage), **C11** (composition du QCM), **C12** (`AnswerKeyNormalizer`, `validation_version`, sous-titre, `near_miss`) —, noms, signatures, charges et tests repris à la lettre.

Il ne possède **pas** : le schéma (`10`, seul propriétaire : toute donnée nouvelle est une exigence E10-xx), la règle de langue et la chaîne de repli d'affichage (`05`), le vivier, la PRF seedée et le registre des contextes de graine (`30`, contrats C2 et C3), les défauts et bornes des réglages (`50`, contrat C0), les canaux, les événements, le rattrapage, la clôture et la fin anticipée comme transitions (`60`, contrat C7), le palier retenu, les points, le bonus et le plancher du QCM comme calcul (`80`, contrat C13), les écrans (`90`), la place des tests et les sondes (`100`). Il les **consomme** et les cite ; il ne les redéfinit jamais.

Convention de renvoi, valable dans tout le document : « règle N » = `CLAUDE.md` §7 ; « principe N » = `00-overview.md` § Principes directeurs ; « décision N » = l'une des 19 décisions du 22/09 (`questions-ouvertes.md`) ; « DN du 23/09 » = décisions du 23/09 consignées dans `questions-ouvertes.md` ; « contrat Cn », « R-nn », « E10-nn », « A-nn » = feuille de contrats du 23/09, ses conflits résolus, ses exigences consolidées à `10` et ses amendements consolidés ; « 10 § x » = section du schéma ; « 10 An » = arbitrage n de 10 § 14, à ne pas confondre avec un amendement « A-nn ».

> **État réel du dépôt au moment d'écrire, vérifié au commit `d167a6a`.**
>
> - `app/Support/Catalog/AnswerKeyNormalizer.php` existe (171 lignes) et se déclare « provisoire quant à l'algorithme, définitif quant à l'adresse ». `normalize()` enchaîne ponctuation → espace, `Str::ascii()`, minuscules, `[^a-z0-9]` → espace, retrait d'un article de tête (liste privée `LEADING_ARTICLES`), troncature à `MAX_NORMALIZED_LENGTH = 200`. `prefixOf()`, `subtitleSeparators()` et `minPrefixLength()` existent. **Ni chiffres romains, ni `fold`, ni distance, ni constante de version.** Mesuré : `Str::ascii()` rend la chaîne **vide** pour `ガラスの果樹園`, `千と千尋の神隠し`, `기생충` et `Ｔｏｋｙｏ`, et « Rocky Ⅳ » devient `rocky` ; `tests/Feature/Catalog/ImportFilterTest.php` l.214 fige l'attente `''`.
> - `app/Support/Catalog/AnswerKeyProjector.php` (239 lignes) projette par différence, avec la précédence exacte > `prefix` et des préfixes dérivés des seuls titres. `recomputeAmbiguity()` compte les films `published` et pose `is_ambiguous` sur les seules clés `prefix`. `touchedMovieIds()` ne contient que le film projeté, pas ceux dont un préfixe vient d'être requalifié. Aucun cache n'existe.
> - Enums : `AnswerKeyKind` a cinq cas et `isExact()` vaut « pas `Prefix` » ; `GuessMatchKind` en a quatre (`title`, `alias`, `prefix`, `choice`) ; `RoundPlayerInputState` six, avec `isClosed()` = « pas `Open` » ; `InputDifficulty::choicesOpenTierIndex()` rend 1, `N` ou NULL ; `RoundIncidentReason` trois ; `Locale::MASK_VERSION = 1`, `en` puis `fr` au rang de repli.
> - Modèles : le scope `RoundPlayer::open()` filtre `input_state = open` ; `Round::isEarlyEndReached()` teste `isClosed()` sur les participants ; `Guess` cache `answer_key_*`, `submitted_normalized`, `tier_index` et `points_*` ; `RoundChoiceSet` cache `choice_1..4` deux fois ; `NearMiss` existe sans aucun écrivain. La table `round_choice_set` n'a pas de colonne `rendered_locale`.
> - Réglages : `RoomSettingsBounds` porte `attemptsPerSecond` 1-5 (défaut 1), `attemptsPerRound` 5-50 (défaut `defaultAttemptsPerRound(D)` = `ceil(D / ATTEMPTS_PER_ROUND_SECONDS_PER_ATTEMPT)` plafonné à `ATTEMPTS_PER_ROUND_SOFT_CAP`, soit `ceil(D / 2 s)` ≤ 30), `maxAnswerLength` 20-200 (défaut 100). `PlatformLimits::DEFAULT_TIER_GRACE_MS = 300`. Aucun `config/game.php`.
> - Fabriques : `GameFactory::VALIDATION_VERSION = 1`, « provisoire, pour 70 » ; docblock de `RoundFactory::withDecoys()` : « tirés et figés au lancement » ; `GuessFactory::viaPrefix(bool $wasAmbiguous)` et `forAnswerKey()`, qui pose `submitted_normalized` = forme de la clé ; `PlayerFactory::normalizeNickname()` provisoire.
> - Absents : `routes/game.php` (seuls `web`, `admin`, `settings`, `console` existent), `app/Events/`, les espaces `App\Support\Answers`, `App\Support\Draw`, `App\Support\Game`, `App\Support\Scoring`, tout contrôleur de jeu, le limiteur `answer` (`FortifyServiceProvider` ne déclare que `two-factor`, `login`, `passkeys`, `admin-import`), les commandes `answers:collisions` et `catalog:reproject`, `tests/Feature/Answer/`, tout fichier front de jeu.
> - `lang/` existe (`en`, `fr`, `en.json`, `fr.json`) ; `lang/{fr,en}/game.php` ne porte que `frame.alt`, `validation.php` aucun attribut `answer` ni `choice`. `tests/Pest.php` applique `RefreshDatabase`. Sur ces deux points, `CLAUDE.md` §6 et §8 sont en retard sur le dépôt. `phpunit.xml` force `QUEUE_CONNECTION=sync` et `CACHE_STORE=array`.
> - `voku/portable-ascii` 2.1.1 ; `Str::transliterate()` n'emprunte la branche `ext-intl` qu'avec `strict = true` ; `ext-intl` est absente.
> - Middlewares : `bootstrap/app.php` ajoute `SetLocale` en **fin** du groupe `web` et inscrit `EnsureUserHasRole` avant `SubstituteBindings` par `prependToPriorityList` ; aucun middleware de siège n'existe. Le noyau de Laravel range `ThrottleRequests` avant `SubstituteBindings` dans `$middlewarePriority`, donc avant tout middleware de route absent de cette liste (§ 8). `TrimStrings` est un middleware **global** qui nettoie par `Str::trim()`, laquelle retire aussi les caractères invisibles de bord (§ 10.5).
>
> Hormis le corps du normaliseur et du projecteur, tout ce qui suit est à construire.

---

## 1. Cadre

### 1.1 Notations

| Notation | Sens | Source |
|---|---|---|
| `T₁` | `round.started_at`, ouverture de la manche et du palier 1 | 10 § 7.4 |
| `Tᵢ` | `T₁ + round_tier.starts_at_offset_ms` du palier `i` ; `T_N` pour le dernier | 10 § 7.4 |
| `T_Q` | ouverture du QCM : `Tᵢ` pour `i = InputDifficulty::choicesOpenTierIndex(N)`, soit `T₁` en Facile, `T_N` en Normal, inexistant en Expert | 00 § Saisie, contrat C11 |
| `D` | `round.duration_ms`, somme des `round_tier.duration_ms` | 10 § 7.4 |
| `ended_at` | clôture : `T₁ + D` à l'échéance, instant de l'événement déclencheur en fin anticipée | contrat C7 § 4.6 |
| `receivedAt` | `ReceptionInstant::of($request)`, instant serveur capturé **une fois** à l'entrée de la requête | contrat C7 |
| `answeredAtMs` | `RoundClock::offsetMs($round, $receivedAt)`, entier en ms, calculé une fois | contrat C7 |
| `tier_grace_ms` | `game.tier_grace_ms`, figé au lancement | 10 § 7.2, contrat C0 |
| `s` | la saisie normalisée ; `K` et `O` les deux lectures du § 6.1 | contrat C10 |

### 1.2 Provenance de chaque valeur (règle 2)

Aucun littéral de jeu n'existe dans un contrôleur, une action, un FormRequest ou un composant de 70.

| Valeur | Source unique | Qui la règle |
|---|---|---|
| `attemptsPerSecond`, `attemptsPerRound`, `maxAnswerLength` | `game.settings_snapshot` (`RoomSettings`), défauts et bornes de `RoomSettingsBounds` (contrat C0 § 3.1) | l'hôte, onglet Avancé, **au J2 seulement** ; au J1, `attemptsPerSecond` et `maxAnswerLength` restent au défaut et `attemptsPerRound` suit `D` par la règle Simple (contrat C0 § 4.4, D34 du 23/09) |
| `input_difficulty`, `N` | `game.input_difficulty`, `game.frames_per_round` | l'hôte (onglet Simple), figé au lancement |
| `D`, décalages, durées et valeurs de palier | `round.duration_ms`, `round_tier.*`, matérialisés au lancement | l'hôte, figé |
| `tier_grace_ms` | `game.tier_grace_ms`, figé au lancement depuis `PlatformLimits::tierGraceMs()` (300 ms au défaut) | **personne** : constante d'instance, jamais un réglage d'hôte ni une clé du snapshot (10 § 7.5) ; arbitrage de rédaction du 23/09 : non surchargeable par configuration, son changement de défaut incrémente `scoring_version` |
| B_max, forme du bonus, plancher de palier du QCM, `scoring_version` | contrat C13 (`ScoringRules`, `PlatformLimits::speedBonusMaxPercent()`, D22 du 23/09) | personne |
| Séparateurs de sous-titre, longueur minimale d'une clé dérivée | `config('catalog.subtitle_separators')` (défaut `[' : ', ': ', ' - ', ' – ', ' — ']`), `config('catalog.min_prefix_length')` (défaut 4) | le déploiement, jamais une table (10 § 3.5) ; dans l'empreinte (§ 12) |
| Articles, règle romaine, chiffres stricts, `TOLERANCE_STEPS`, `MAX_TOLERANCE`, `NEAR_MISS_MARGIN` | constantes de version de `AnswerKeyNormalizer` et `AnswerRules` | le code, sous `AnswerRules::VERSION` |
| Longueur maximale d'une forme normalisée | `AnswerKeyNormalizer::MAX_NORMALIZED_LENGTH = 200`, largeur de colonne (10 § 1.3) | le schéma |
| Nombre de leurres (3) | cardinalité du schéma (`round.decoy_movie_id_1..3`, 10 § 7.4) | le schéma, pas un réglage |
| Locales, bits de profil, rangs de repli | `App\Enums\Locale` (`cases()`, `maskBit()`, `fallbackRank()`, `MASK_VERSION`) | le code |
| Graine | `game.draw_seed` | CSPRNG au lancement (contrat C3) |
| Fenêtre de mémoire du salon | `RoomMemoryWindow` sur `PlatformLimits::roomMemoryWindowDays()` / `roomMemoryWindowRounds()` (contrat C2) | configuration |
| Rétention du tampon et du compteur de quasi-justes (J2) | configuration de `100`, lignes du tableau de 10 § 11.1 (E10-63) | configuration |

**Pourquoi la tolérance n'est pas un réglage d'hôte.** Une bonne réponse est acceptée « quel que soit le joueur et quel que soit le salon » (05 § Acceptation multilingue des réponses) : la règle d'acceptation est une propriété de l'instance, pas du salon. Un hôte qui pourrait l'élargir ferait de la manche un jeu de vocabulaire et rendrait incomparable le journal de deux salons ; il ne pourrait pas non plus la rejouer, puisque `guess` fige la distance et jamais le seuil (10 § 7.2). Elle a donc le statut de B_max (D22 du 23/09) : constante de code, changée seulement par une nouvelle version de règle (§ 12).

### 1.3 Les quatre invariants de lecture de 10, appliqués ici

- **L1** : toute agrégation de `guess` joint `round` et exclut `cancelled`. Ici : la sonde de force brute (§ 13.2, J1).
- **L2** : aucune valeur servant à l'acceptation n'est mise en cache plus longtemps que la transaction qui peut la changer. Ici : **aucun cache au J1** ; les clés du film et le test d'homonymie sont relus à **chaque** soumission (§ 6.1, E10-16). L2 est tenu par construction, et l'incomplétude de `touchedMovieIds()` devient sans objet.
- **L3** : aucune valeur mesurée ou déclarée par le client n'entre dans la fenêtre d'acceptation, le palier ni le bonus. Ici : `receivedAt` est serveur ; le numéro de manche envoyé par le client (`round`) sert seulement à refuser une soumission adressée à une autre manche ; `answer` et `choice` sont jugés, jamais crus.
- **L4** : le temps de réponse et le nombre de requêtes d'un refus ne dépendent jamais de la proximité de la réponse. Ici : la séquence à travail constant (§ 7.4), qui est le cœur anti-triche de cette spec.

---

## 2. Les trois modes de saisie

| Difficulté (`input_difficulty`) | Texte libre | QCM | Palier d'ouverture du QCM | Route texte | Route clic |
|---|---|---|---|---|---|
| **Facile** (`easy`) | jamais | dès `T₁` | 1 | **409** toujours, rien n'est compté | recevable dès `T₁` |
| **Normal** (`normal`) | de `T₁` à la clôture | à `T_N`, en plus du texte | `N` | recevable | recevable dès `T_N` |
| **Expert** (`expert`) | de `T₁` à la clôture | jamais | — | recevable | **409** toujours |

Le palier d'ouverture ne s'écrit jamais `1` ni `N` en littéral : il se lit par `InputDifficulty::choicesOpenTierIndex(game.frames_per_round)`, seule source, partagée avec le plancher de 80 (contrat C13) et l'envoi de 60 (contrat C7 § 4.8). L'avertissement d'hôte « `T_N` tombe à 50 % de la manche à N = 2 et à 80 % à N = 5 » appartient à 50.

**Le QCM est à essai unique et définitif, par siège.** Une proposition cliquée ferme le QCM pour la manche, juste (`locked`) ou fausse (`qcm_wrong`) ; en Normal, un clic faux ferme **aussi** le texte libre, parce qu'il a consommé la seule information que le QCM vendait (00 § Saisie de la réponse). Les plafonds de tentatives ne concernent que le texte libre. La garantie est formulée **par siège et jamais par personne** : aucun mécanisme ne lie deux sièges à un même humain sans adresse IP, que 10 § 11.1 interdit en table de domaine (10 § 7.1, A-05, A-61) ; le résidu est au § 14.

**En Normal, le QCM reste ouvert après épuisement du texte libre** (D20 du 23/09). Un siège qui a épuisé ses tentatives de texte passe dans l'état **`text_exhausted`** (« texte épuisé, QCM attendu ») : il ne peut plus taper, il recevra les quatre propositions à `T_N`, et il n'est pas « saisie close » pour la fin anticipée (§ 3.3). En Facile (pas de texte) et en Expert (pas de QCM), l'épuisement ferme la saisie comme avant.

**En Normal, texte et QCM coexistent après `T_N`.** Le joueur qui n'a pas trouvé peut continuer à taper ou cliquer une fois ; le palier retenu d'un texte reçu juste après `T_N` peut encore être `N − 1` par la fenêtre de grâce, celui d'un clic jamais (§ 9.2). **Limite de l'essai unique, résidu assumé et signalé au porteur** : un siège `open` à qui il reste au moins quatre tentatives de texte peut taper une à une les quatre chaînes affichées — chaque leurre tapé est refusé en (c) du § 6.2 et coûte une tentative, la cible est acceptée en (a) — et se verrouiller à coup sûr au palier `N`, que le clic lui aurait aussi valu. L'essai unique ne vaut donc que pour le **clic** ; aucune règle d'aujourd'hui ne ferme le texte sur la recopie d'une proposition (§ 14, et l'option de règle au § Ce que cette spec ne décide pas).

---

## 3. La machine d'états de la saisie

### 3.1 États (`App\Enums\RoundPlayerInputState`, cast de `round_player.input_state`)

| État | Sens | `acceptsText()` | `acceptsChoice()` | `isClosed()` | Écrivain |
|---|---|---|---|---|---|
| `open` | saisie ouverte | vrai | vrai | faux | défaut SQL, création de la ligne à `T₁` par 60 |
| `text_exhausted` | **[nouveau, D20 du 23/09]** texte épuisé, QCM attendu ; Normal seulement | faux | vrai | **faux** | 70 |
| `attempts_exhausted` | plafond de texte atteint sans QCM à attendre | faux | faux | vrai | 70 |
| `locked` | bonne réponse verrouillée ; une ligne `guess` existe | faux | faux | vrai | 70 (`LockGuess`) |
| `qcm_wrong` | clic faux ; ferme aussi le texte | faux | faux | vrai | 70 |
| `revealed` | solo, « Voir la réponse » (D18 du 23/09) | faux | faux | vrai | 60 |
| `skipped` | solo, « Passer la manche » (D18 du 23/09) | faux | faux | vrai | 60 |

`text_exhausted` compte 14 caractères, sous la largeur `string(20)` de la colonne : **aucune migration** (E10-06). `isSoloOnly()` est inchangée.

### 3.2 Transitions

70 est l'écrivain unique de `round_player.input_state`, sauf `revealed` et `skipped`, qu'écrit 60 (contrat C10 § 4). Les états `attempts_exhausted`, `locked`, `qcm_wrong`, `revealed` et `skipped` sont **terminaux** pour la manche.

| Depuis | Événement | Condition | Vers | `input_closed_at` | Événement de domaine |
|---|---|---|---|---|---|
| `open` | texte accepté | Normal, Expert | `locked` | `receivedAt` | `AnswerAccepted` |
| `open` | texte refusé, `wrong_attempts + 1 < attemptsPerRound` | — | `open` | — | — |
| `open` | texte refusé, plafond atteint | Normal, hors cas terminal du QCM (QCM pas encore composé, ou composé et pas encore cliqué) | **`text_exhausted`** | NULL | — |
| `open` | texte refusé, plafond atteint | Normal, cas terminal : ouverture de `T_N` appliquée sans QCM (`round_tier(N).served_at IS NOT NULL` et `round.decoy_movie_id_1 IS NULL`, § 7.5) | `attempts_exhausted` | `receivedAt` | `InputClosed` |
| `open` | texte refusé, plafond atteint | Expert | `attempts_exhausted` | `receivedAt` | `InputClosed` |
| `open`, `text_exhausted` | clic juste | QCM ouvert | `locked` | `receivedAt` | `AnswerAccepted` |
| `open`, `text_exhausted` | clic faux | QCM ouvert | `qcm_wrong` | `receivedAt` | `InputClosed` |
| `text_exhausted` | QCM impossible à composer (cas terminal, § 10.7) | Normal | `attempts_exhausted` | instant théorique de composition | `InputClosed` |
| états que 60 admet (D18 du 23/09) | « Voir la réponse » / « Passer la manche » | solo | `revealed` / `skipped` | posé par 60 | — (60) |

Pourquoi `text_exhausted` n'émet rien : il ne clôt pas la saisie, la réévaluation de la fin anticipée n'a rien à apprendre ; l'émettre serait un signal de plus, sans destinataire.

### 3.3 Prédicats et fin anticipée

```php
// App\Enums\RoundPlayerInputState — contrat C10 § 2, R-28
public function acceptsText(): bool   { return $this === self::Open; }
public function acceptsChoice(): bool { return $this === self::Open || $this === self::TextExhausted; }
public function isClosed(): bool      { return ! in_array($this, [self::Open, self::TextExhausted], true); }
/** @return list<string> */
public static function notClosedValues(): array { return ['open', 'text_exhausted']; }
```

- `acceptsChoice()` est **le seul prédicat** employé par 60 pour choisir les destinataires de `seat.choices` (contrat C7 § 4.8) ; `acceptsText()` et `acceptsChoice()` sont les seuls employés par 70 en S4 et dans `LockGuess`.
- Le scope `RoundPlayer::open()` garde son nom et **élargit sa sémantique** à « saisie non close » : `whereIn('input_state', RoundPlayerInputState::notClosedValues())`.
- **Prédicat de fin anticipée** (E10-53, 10 § 7.7) : `COUNT(participants) ≥ 1` **et** `COUNT(participants dont input_state NOT IN ('open','text_exhausted')) = COUNT(participants)`. `Round::isEarlyEndReached()` ne change pas de code : il teste déjà `isClosed()`, dont la sémantique change. Le prédicat est réévalué par 60 (`SeatInputClosed`) après chaque verrouillage ou clôture de saisie, jamais par 70.
- **Conséquence voulue**, au réglage par défaut (`D` = 30 s, `N` = 3, `T_N` = 20 s, 1 tentative par seconde, 15 tentatives) : A se verrouille à 4 s ; B épuise ses 15 tentatives vers 15 s et passe `text_exhausted`. La manche **ne se clôt pas** : B attend le QCM. À 20 s, B reçoit les propositions ; s'il clique à 22 s, faux ou juste, tous les participants sont clos et la manche se clôt à 22 s ; s'il ne clique jamais, elle va au bout de `D`. Si la composition à `T_N` est terminale, B passe `attempts_exhausted` à 20 s et la manche se clôt à cet instant.

### 3.4 Invariants testés

- `input_state = 'locked'` **si et seulement si** une ligne `guess` existe pour le même couple (10 § 7.6, 10 A16) ;
- `text_exhausted` est inatteignable hors `game.input_difficulty = normal` ;
- `wrong_attempts ≤ attemptsPerRound` ;
- `revealed` et `skipped` sont inatteignables hors solo ;
- `input_state` n'est **jamais** envoyé qu'au siège lui-même : `qcm_wrong` révélerait une mauvaise réponse, que la règle interdit de diffuser (10 § 7.6, 00 § Le jeu en une manche).

---

## 4. Ce qui est accepté

### 4.1 Périmètre des clés d'un film

Les chaînes acceptées d'un film sont exactement ses lignes `answer_key` (10 § 3.5), projetées par `AnswerKeyProjector` et normalisées par le § 5.

| Nature (`AnswerKeyKind`) | Source | Soumise à collision | `toMatchKind()` |
|---|---|---|---|
| `title_original` | `movie.title_original`, inconditionnellement | non | `Title` |
| `title_latin` | `movie.title_original_latin`, inconditionnellement | non | `Title` |
| `title` | `movie_title` des **locales activées** | non | `Title` |
| `alias` | `alias` des **locales activées**, d'origine `tmdb` comme `curator` | non | `Alias` |
| `prefix` | partie **avant** le premier séparateur déclaré de chaque **titre** (`title_original`, `title_original_latin`, `movie_title` activés), jamais d'un alias | **oui** | `Prefix` |
| `subtitle` | **[nouveau, D23 du 23/09]** partie **après** ce séparateur, mêmes sources, jamais d'un alias | **oui** | `Subtitle` |

- `AnswerKeyKind::isCollisionChecked()` est vrai pour `Prefix` et `Subtitle` ; `isExact()` devient `! isCollisionChecked()` (sémantique restreinte, signalée par le contrat C12). `GuessMatchKind` reçoit le cas `Subtitle = 'subtitle'` (8 caractères, sous la largeur `string(10)` de `guess.match_kind`) pour que l'instantané dise quelle règle de collision s'appliquait. Aucune migration (E10-09).
- **Précédence sur `(movie_id, normalized)`** dans le projecteur : nature exacte > `prefix` > `subtitle`. Une chaîne à la fois titre et sous-titre reste un titre, donc toujours acceptée.
- **La locale du joueur n'entre dans aucune décision** (05 § Acceptation multilingue). `answer_key.source_locale` n'apparaît dans aucun `WHERE` de validation. Un joueur en FR peut taper le titre EN, et réciproquement ; changer de langue en pleine manche ne rend jamais juste une réponse refusée.
- **Titres en écriture non latine** : une saisie dans l'écriture d'origine s'apparie désormais de façon déterministe à `title_original`, parce que la translittération ne rend plus la chaîne vide (§ 5.2, A-58).

### 4.2 Préfixe et sous-titre : la règle de collision (décision 13, D23 du 23/09)

Une clé dérivée (`prefix` ou `subtitle`) du film de la manche est acceptée **sauf si un autre film `published` porte la même forme normalisée**, sous quelque nature que ce soit. Trois propriétés, toutes héritées de la décision 13 et étendues telles quelles au sous-titre :

1. **Catalogue publié entier, jamais le vivier du salon.** Mesurer sur le vivier révélerait combien d'épisodes d'une saga sont dans le tirage : accepter « Le Seigneur des Anneaux » apprendrait qu'un seul épisode y figure.
2. **À l'instant serveur de réception.** L'ambiguïté est celle que lit la lecture `O` du § 6.1, exécutée dans le traitement de la soumission. Un film publié en pleine manche rend un préfixe partagé refusable dès la soumission suivante.
3. **Jamais rétroactive.** `guess` fige l'instantané (§ 9.3) et rien ne le relit : un score attribué n'est jamais recalculé (00 § Cycle de vie).

Une clé dérivée n'existe que si sa forme normalisée compte au moins `config('catalog.min_prefix_length')` caractères et diffère de la forme du titre entier ; un sous-titre doit en plus différer du préfixe (§ 5.7). Le découpage se fait au **premier** séparateur, et à lui seul : dans « Star Wars : Épisode V - L'Empire contre-attaque », le sous-titre est `episode v l empire contre attaque`, jamais `empire contre attaque`. Le second découpage passe par un alias curé.

**Largesse assumée.** Un deux-points interne à un titre produit des clés dérivées larges : « Mission: Impossible » donne le préfixe `mission` et le sous-titre `impossible`, tous deux acceptés pour ce film tant qu'aucun autre film publié ne les porte. La règle de collision neutralise d'elle-même les sous-titres génériques (« Volume 1 », « Le Retour ») dès qu'un second film les porte ; tant qu'un seul les porte, ils désignent ce film sans ambiguïté et l'accepter ne donne la victoire qu'à qui a reconnu le film. Aucune colonne ne permet de supprimer une clé dérivée précise : le remède d'une largesse jugée excessive est un changement de `min_prefix_length`, donc une nouvelle version (§ 12).

### 4.3 Titre complet et alias : toujours acceptés

- Une clé **de nature exacte** du film de la manche est **toujours** acceptée, même si un autre film publié porte la même forme (homonyme, remake) : `prefix_was_ambiguous` vaut alors vrai dans l'instantané (E10-50). **L'année n'est jamais demandée au joueur** ; c'est la révélation qui l'affiche, discriminant des homonymes (05 § Chaîne de repli).
- **Un alias importé de TMDB est un alias** : la décision 13 et 10 § 3.5 le rangent dans la nature `alias`, jamais soumise à collision, et le tri par origine rouvrirait une décision verrouillée. Le risque réel — un alias TMDB générique comme « Star Wars » posé sur un seul épisode, qui validerait cet épisode alors que le préfixe serait refusé — est traité sans rouvrir la règle : le rapport de collisions liste toutes les formes **exactes** partagées par au moins deux films publiés, alias TMDB compris (§ 13.1), et la suppression de l'alias fautif passe par l'écran d'alias de 20 (saisie et suppression manuelles au J1, D24 du 23/09).
- « seigneur des anneaux 2 » n'est ni un titre, ni une clé dérivée, ni le produit d'aucune règle : il valide « Les Deux Tours » **si l'alias existe**, et seulement alors (A-07, A-46). La règle des chiffres stricts (§ 6.3) empêche qu'il soit accepté par tolérance pour le mauvais épisode.

### 4.4 Exemples

Catalogue supposé : « Le Seigneur des Anneaux : Les Deux Tours » (titre FR) / « The Lord of the Rings: The Two Towers » (titre EN) publié avec « La Communauté de l'Anneau » ; « Alien » (1979), « Aliens » et « Alien³ » publiés ; « Le Voyage de Chihiro » publié avec son `title_original` `千と千尋の神隠し`. Distances et tolérances selon le § 6.3.

| Saisie | Forme `s` | Clé visée | Issue | Règle |
|---|---|---|---|---|
| Le Seigneur des Anneaux : Les Deux Tours | `seigneur des anneaux les deux tours` | `title` FR | acceptée | (a) |
| The Two Towers | `two towers` | `subtitle` EN | acceptée si aucun autre film publié ne porte `two towers` | (b), D23 du 23/09 |
| les deux tours | `deux tours` | `subtitle` FR | idem | (b) |
| le seigneur des anneaux les deux tour | `seigneur des anneaux les deux tour` | `title` FR, distance 1, tolérance 3 | acceptée | (d) |
| Le Seigneur des Anneaux | `seigneur des anneaux` | `prefix`, porté aussi par « La Communauté » | **refusée**, corps neutre | (c), décision 13 |
| seigneur des anneaux 2 | `seigneur des anneaux 2` | aucune ; chiffres `[2]` ≠ `[]` du préfixe | **refusée**, sauf alias | (e) |
| aliens (manche « Alien³ ») | `aliens` | clé exacte d'« Aliens », autre film publié | **refusée** | (c) |
| aliens (manche « Alien ») | `aliens` | clé exacte d'« Aliens », autre film publié ; sans (c), la clé `alien` de la cible (5 caractères, tolérance 1, mêmes chiffres `[]`, distance 1) l'accepterait en (d) | **refusée** | (c) |
| alien 3 (manche « Aliens ») | `alien 3` | aucune : O ne trouve pas « Alien³ », dont la forme stockée est `alien3` (O compare la forme exacte, jamais la forme compacte) ; la seule clé de la cible, `aliens`, a pour chiffres `[]` ≠ `[3]`, donc aucun candidat en (d) | **refusée** | (e) |
| 千と千尋の神隠し | `qian toqian xun noshen yin shi` | `title_original` | acceptée | (a), § 5.2 |
| walle (manche « WALL·E ») | `walle` | `title` `wall e`, distance compacte 0 | acceptée | (d) |

### 4.5 Garde de devinabilité (E10-24)

Aucun film n'est publiable sans **au moins une clé exacte non vide**. Depuis la translittération du § 5.2, seul un titre fait uniquement de symboles peut encore normaliser en chaîne vide ; un tel film serait indevinable en Normal et en Expert. La condition de publication appartient à 10 § 4.3, son application au geste de publication de 20.

---

## 5. Normalisation (`App\Support\Catalog\AnswerKeyNormalizer`)

**Implémentation unique** (10 A5) : les clés et les saisies passent par la **même** fonction, les fabriques et le projecteur y délèguent. Deux normaliseurs divergeraient au premier changement d'algorithme et produiraient un catalogue complet sur lequel aucune réponse ne valide jamais. La fonction est **agnostique de la langue** : aucune locale en paramètre. Le verdict est identique en SQLite et en MySQL parce que l'alphabet est replié en PHP (10 A6) : aucune collation, aucun index fonctionnel.

### 5.1 `public static function normalize(string $text): string`

Étapes, dans cet ordre, et chacune pour une raison :

1. `preg_replace('/[\p{P}\p{S}]+/u', ' ', $text)` — ponctuation et symboles deviennent une **espace**, avant toute translittération. Sans cette passe, « WALL·E » donnerait `walle` quand le joueur qui tape « WALL E » donne `wall e` (mesuré). Une espace et non une suppression, parce que la forme compacte du § 5.8 rattrape l'écart inverse.
2. **`Str::transliterate($s)`**, avec ses défauts (inconnu → `?`, `strict = false`), **à la place de `Str::ascii()`** (§ 5.2).
3. `strtolower`.
4. `preg_replace('/[^a-z0-9]+/', ' ', …)` puis `trim` ; rendre `''` si rien ne subsiste. L'alphabet de sortie `[a-z0-9 ]` est celui de 10 A6, non rouvert.
5. **Chiffres romains**, jeton par jeton (§ 5.3).
6. Retrait d'**un seul** article de tête (§ 5.4).
7. `substr(…, 0, AnswerKeyNormalizer::MAX_NORMALIZED_LENGTH)` : troncature **symétrique** (§ 5.5).

Sortie : `[a-z0-9 ]`, espaces simples, au plus 200 caractères. Constantes conservées : `MAX_NORMALIZED_LENGTH = 200`, `DEFAULT_MIN_PREFIX_LENGTH`, `DEFAULT_SUBTITLE_SEPARATORS`, `LEADING_ARTICLES` ; `subtitleSeparators()` et `minPrefixLength()` sont inchangées.

### 5.2 Translittération sans `ext-intl`

`Str::transliterate()` délègue à `voku/portable-ascii` (`ASCII::to_transliterate`), pur PHP. Mesuré sur le dépôt : `ガラスの果樹園` → `garasunoguo shu yuan`, `千と千尋の神隠し` → `qian toqian xun noshen yin shi`, `기생충` → `gisaengcung`, `Ｔｏｋｙｏ` → `tokyo`, « Rocky Ⅳ » → `rocky 4`, avec les mêmes sorties que `Str::ascii()` sur le latin déjà testé (`ß`, `Œ`, `Æ`, `é`, `Ł`, `ō`). C'est ce qui rend vrai « titres en écriture non latine : toujours acceptés en réponse » (`questions-ouvertes.md` § Déjà tranché), qui était faux avec `Str::ascii()`.

Trois garde-fous :

- **`strict` reste à `false`.** La bibliothèque n'emprunte `transliterator_transliterate()` qu'avec `strict = true` ; avec les défauts, ajouter un jour `ext-intl` au serveur ne change aucune sortie. `ext-intl` n'est pas ajoutée (05 § Nombres, dates et durées).
- **Une mise à jour Composer qui change une sortie fait échouer les fixtures** (§ 5.6). Elle impose une nouvelle version de règle et une reprojection (§ 12), jamais une réécriture silencieuse des attentes.
- Les sorties sur les écritures CJK ne sont pas du pinyin ni du romaji de référence ; elles n'ont pas à l'être. Elles doivent seulement être **déterministes et identiques des deux côtés**, clés et saisies. La translittération affichée et saisie par les joueurs reste `movie.title_original_latin` (10 A4), qui produit sa propre clé `title_latin`.

### 5.3 Chiffres romains

Un jeton entièrement fait de `i`, `v`, `x`, valide en notation stricte `^(x{0,3})(ix|iv|v?i{0,3})$` et de valeur 1 à 39, est converti en arabe **si** sa longueur est d'au moins deux lettres, **ou si** c'est un jeton d'une lettre placé en **dernière** position d'une chaîne d'au moins deux jetons. `m`, `d`, `c` et `l` ne sont jamais convertis.

- Couvert : `final fantasy 7` (« VII »), `rocky 5` (« Rocky V »), `saw 10` (« Saw X »), `part 1`.
- Épargné : « X-Men » (`x men`), « I, Robot » (`i robot`), « Malcolm X » converti en `malcolm 10` — la conversion étant symétrique, une conversion « fausse » ne change aucun appariement.
- **Effets symétriques assumés** : « xXx » devient `30` ; le `v` de « Épisode V » placé au milieu d'un titre n'est pas converti. Un joueur qui tape « épisode 5 » a une suite de chiffres `[5]` contre `[]` et n'est pas toléré (§ 6.3) : le remède est un alias curé, pas une règle de plus.
- **Pas de conversion inverse, pas de nombres en lettres, pas de « & » / « and » / « et »** : ces formes dépendent de la langue, donc sont incompatibles avec une normalisation agnostique ; elles relèvent du dictionnaire d'alias (00 § Catalogue & curation). « & » disparaît comme ponctuation à l'étape 1.

### 5.4 Articles

Liste fermée `le, la, les, l, un, une, des, du, de, the, a, an` : l'**union des locales activées**, appliquée **sans notion de langue**, puisque la saisie n'a pas de langue déclarée et que la locale du joueur n'arbitre rien (05). Un seul article de tête est retiré, **jamais le dernier mot** (« Le » seul reste `le`), et **jamais après un séparateur** : retirer un article par segment casserait la saisie tapée sans le deux-points. Les articles d'autres langues de catalogue (`el`, `il`, `der`, `die`, `das`) sont exclus : « die » est un mot anglais (« Die Hard »). La liste est lue par `AnswerKeyNormalizer::leadingArticles(): list<string>` pour l'empreinte ; elle n'est pas en configuration, parce qu'un algorithme modifiable sans reprojection produit un index incohérent avec la règle qui l'a produit.

Ajouter une locale activée ajoute ses articles : c'est un changement de règle, donc une nouvelle version (§ 12) et une revalidation du calibrage (05 § Ajouter une troisième langue, étape 6).

### 5.5 Troncature et non-idempotence

- **Troncature symétrique à 200 caractères, assumée.** La translittération peut allonger une chaîne (`ß` → `ss`, `щ` → `shch`) au-delà de la largeur de colonne ; clés et saisies sont tronquées au même endroit, donc deux formes longues ne divergent jamais sur leur tronçon commun. « Aucune chaîne n'excède la colonne » remplace « aucune chaîne saisissable n'est jamais tronquée » (E10-12). La borne `maxAnswerLength` (au plus 200) se mesure sur la saisie **brute**, pas sur la forme normalisée (§ 8).
- **`normalize()` n'est pas idempotente** : « The The Thing » donne `the thing`, qui renormalisé donne `thing`. La fonction ne s'applique donc **jamais** à une forme déjà normalisée — ni dans le projecteur, ni dans `AnswerMatcher`, ni dans le rapport de collisions, ni dans le jugement d'un clic, qui normalise la chaîne affichée et non une forme stockée.

### 5.6 Sorties figées (fixtures v1)

`tests/Fixtures/answers/normalizer-v1.php` [nouveau] fige des paires entrée → `normalize()` et entrée → `fold()`. Les seize paires du contrat C12 en font partie, sans modification :

| Entrée | `normalize()` | Entrée | `normalize()` |
|---|---|---|---|
| `ガラスの果樹園` | `garasunoguo shu yuan` | `I, Robot` | `i robot` |
| `千と千尋の神隠し` | `qian toqian xun noshen yin shi` | `Final Fantasy VII` | `final fantasy 7` |
| `기생충` | `gisaengcung` | `Alien³` | `alien3` |
| `Ｔｏｋｙｏ` | `tokyo` | `Se7en` | `se7en` |
| `Rocky Ⅳ` | `rocky 4` | `Le Fabuleux Destin d'Amélie Poulain` | `fabuleux destin d amelie poulain` |
| `Rocky V` | `rocky 5` | `WALL·E` | `wall e` |
| `Saw X` | `saw 10` | `Straße` | `strasse` |
| `X-Men` | `x men` | `L'Été` | `ete` |

S'y ajoutent, mesurées : `Malcolm X` → `malcolm 10`, `xXx` → `30`, `The The Thing` → `the thing`, `Star Wars : Épisode V - L'Empire contre-attaque` → `star wars episode v l empire contre attaque`, et pour `fold()` : `José` → `jose`, `Le Chat` → `le chat`, `Jean-Luc_77` → `jean-luc_77`, `ß-Boy` → `ss-boy`, `  A  b  ` → `a b`. L'attente de `ImportFilterTest` l.214 est réécrite (`garasunoguo shu yuan`).

### 5.7 `prefixOf` et `subtitleOf` (D23 du 23/09)

- `public static function prefixOf(string $title): ?string` [existant] et `public static function subtitleOf(string $title): ?string` [nouveau] découpent à la position **la plus à gauche** parmi `config('catalog.subtitle_separators')`, un séparateur en position 0 étant ignoré.
- Le préfixe est la partie **avant** le séparateur, le sous-titre la partie **après**, jusqu'au bout de la chaîne.
- Chacun est normalisé par `normalize()` et retenu seulement si sa longueur est d'au moins `minPrefixLength()` et s'il diffère de `normalize($title)` ; le sous-titre doit aussi différer du préfixe.
- La fonction ne sait pas d'où vient sa chaîne : **c'est le projecteur qui ne l'applique jamais à un alias** (décision 13, D23 du 23/09).

Exemples mesurés : « The Lord of the Rings: The Two Towers » → préfixe `lord of the rings`, sous-titre `two towers` ; « Le Seigneur des Anneaux : Les Deux Tours » → `seigneur des anneaux`, `deux tours` ; « Star Wars : Épisode V - L'Empire contre-attaque » → `star wars`, `episode v l empire contre attaque` ; « Kill Bill : Volume 1 » → `kill bill`, `volume 1`.

**Projecteur** (`AnswerKeyProjector`, [modifié]) : `desiredKeys()` dérive aussi `subtitleOf()` de `title_original`, `title_original_latin` et des `movie_title` des locales activées, jamais d'un alias ; la précédence devient exacte > `prefix` > `subtitle` ; `recomputeAmbiguity()` pose `is_ambiguous` sur `key_kind IN ('prefix','subtitle')` ; le docblock « cache invalidé depuis `touchedMovieIds()` » est retiré, aucun cache n'existant au J1. L'avertissement nominatif que le back-office montre avant une publication s'étend aux sous-titres rendus ambigus (écran de 20).

### 5.8 `compact`, `digits`, `distance`

- `public static function compact(string $normalized): string` : retire les espaces.
- `public static function digits(string $normalized): list<int>` : la suite des entiers trouvés par `preg_match_all('/\d+/')`, **comparés comme entiers** (`007` = `7`).
- `public static function distance(string $normalizedA, string $normalizedB): int` = `min(levenshtein(a, b), levenshtein(compact(a), compact(b)))`, coûts 1/1/1. Sur un alphabet ASCII, octet = caractère ; `levenshtein()` natif est en C, sans dépendance, et parcourt toujours la matrice entière. La forme compacte absorbe sans règle spéciale `walle` contre `wall e` et `alien 3` contre `alien3`.

`distance()` est aussi la primitive publique que 20 emploie pour suggérer des candidats `movie_group` (« formes normalisées proches dans `answer_key` », 10 § 3.3) ; le seuil de suggestion appartient à 20.

### 5.9 `fold` (primitive consommée par 40)

`public static function fold(string $text): string` : `Str::transliterate`, puis `strtolower`, puis `\s+` → une espace, puis `trim`. **Sans** retrait de ponctuation (`-` et `_` conservés), **sans** article, **sans** chiffres romains, **sans** troncature ; idempotente et totale sur de l'UTF-8 valide. L'appelant refuse une forme vide ou plus longue que sa colonne.

- Pourquoi une seconde primitive : un pseudo qui perdrait son article (« Le Chat » contre « Chat ») ou son tiret entrerait en collision dans un salon, et une translittération qui allonge lèverait une erreur 1406 sur une colonne `string(20)`.
- `player.nickname_normalized` n'est **pas** `fold()` seul : c'est `NicknameNormalizer::normalize()` de 40, qui retire en plus tout ce qui n'est pas `[a-z0-9]` (contrat C5, E10-32). `saved_config.name_normalized` reste un sujet J2 de 50, sur l'idiome A6 de 10.
- `fold` **n'entre pas** dans `validation_version` : elle ne décide d'aucune acceptation. Ses fixtures vivent avec celles de `normalize`.

---

## 6. Appariement et tolérance (`App\Support\Answers\AnswerMatcher`)

### 6.1 Deux lectures inconditionnelles

`AnswerMatcher::match(Round $round, string $submittedNormalized): MatchResult` exécute **toujours**, pour toute saisie texte, dans cet ordre :

- **(K)** `SELECT id, normalized, key_kind, is_ambiguous FROM answer_key WHERE movie_id = :cible`, par `answer_key_movie_idx` : toutes les clés du film de la manche, dix à vingt lignes. La lecture ne filtre pas la disponibilité du film : un film dépublié en pleine manche garde ses clés (10 § 3.5) et sa manche reste jugeable.
- **(O)** `SELECT answer_key.movie_id FROM answer_key JOIN movie ON movie.id = answer_key.movie_id WHERE answer_key.normalized = :s AND movie.availability = 'published'`, par `answer_key_norm_movie_uq` puis clé primaire : les films publiés qui portent exactement `s`, sous toute nature.

Puis elle calcule **`distance(s, k)` pour toutes les clés `k` de K, sans sortie anticipée**, y compris celles que les filtres de l'étape (d) écarteront ensuite. Enfin elle appelle `AnswerMatcher::decide()`, fonction **pure** et sans base :

```php
public static function decide(
    string $submittedNormalized,
    int $targetMovieId,
    array $targetKeys,                          // list<AnswerKey> : le résultat de K
    array $publishedMovieIdsCarryingSubmitted,  // list<int> : le résultat de O
): MatchResult;
```

Pourquoi deux lectures toujours, et toutes les distances toujours : un refus pour préfixe ambigu qui ferait une lecture de plus qu'un refus franc deviendrait **chronométrable** et confirmerait la saga, exactement ce que la décision 13 cache (L4, E10-16). Le repli de cache de 10 § 3.5 (« relire `is_ambiguous` au seul moment où un préfixe apparie ») et la fréquence « une fois par appariement exact » de 10 § 12 sont remplacés par « à chaque soumission ».

### 6.2 Précédence du verdict texte

Notations : `s` la saisie normalisée ; `O≠` les films de O autres que la cible.

| Étape | Condition | Issue |
|---|---|---|
| (a) | `s` égale une clé **de nature exacte** de la cible (`title_original`, `title_latin`, `title`, `alias`) | **acceptée**, `edit_distance = 0`, `match_kind = key_kind->toMatchKind()`, `prefix_was_ambiguous = (O≠ non vide)` |
| (b) | sinon, `s` égale une clé **dérivée** (`prefix`, `subtitle`) de la cible **et** `O≠` est vide | **acceptée**, distance 0, `prefix_was_ambiguous = false` |
| (c) | sinon, `O≠` non vide : `s` désigne exactement un autre film publié, préfixe ou sous-titre ambigu compris | **refusée** |
| (d) | sinon, tolérance : candidats = clés exactes de la cible et clés dérivées à `is_ambiguous = false`, **à suite de chiffres identique** (§ 6.3) ; un candidat passe si `distance(s, k) ≤ AnswerRules::tolerance(strlen(compact(k)))` | **acceptée** si au moins un candidat passe ; on retient celui de **plus petite distance**, puis, à distance égale, la nature exacte, puis `prefix`, puis `subtitle`, puis `answer_key.id` croissant |
| (e) | sinon | **refusée** |

- **(a) avant (c)** : un titre complet ou un alias qui désigne le film de la manche est toujours accepté, homonyme publié ou non (décision 13).
- **(c) avant (d)** : la garde exacte. Une saisie égale à une clé d'un autre film publié est refusée **même sous le seuil de tolérance** de la cible ; sans elle, « aliens » serait accepté pour la manche « Alien » (1979), par la clé `alien` (5 caractères compacts, tolérance 1), aux mêmes chiffres `[]` et à distance 1, alors qu'« Aliens » est un autre film publié (§ 4.4).
- **(d) exclut les clés dérivées ambiguës** : sans cette exclusion, une faute de frappe sur un préfixe partagé passerait là où le préfixe exact est refusé. `is_ambiguous` ne sert **qu'ici** ; les étapes (b) et (c) lisent l'ambiguïté fraîche de O.
- Au rejeu, la décision ne dépend que de `(s, K, O)` : `decide()` est testable sans base.

### 6.3 Barème de tolérance et chiffres stricts

`App\Support\Answers\AnswerRules` [nouveau, `final`] :

```php
public const int VERSION = 1;
public const array TOLERANCE_STEPS = [4 => 0, 8 => 1, 15 => 2];
public const int MAX_TOLERANCE = 3;
public const int NEAR_MISS_MARGIN = 2;   // lue au J2 seulement (§ 11), déclarée dès le J1 pour l'empreinte v1
public const string ROMAN_RULE = 'ivx;1-39;len>=2|last-of->=2';            // noms de règle pour l'empreinte (§ 12)
public const string DIGITS_RULE = 'strict-int-sequence';
public const string SUBTITLE_RULE = 'after-first-separator;titles-only;collision';
public const string TRANSLITERATION = 'Str::transliterate;strict=false';
public static function tolerance(int $compactKeyLength): int;
public static function fingerprint(): string;
```

- **Distance maximale selon la longueur compacte de la clé visée** : ≤ 4 → 0 ; 5 à 8 → 1 ; 9 à 15 → 2 ; ≥ 16 → 3. Les titres courts (« Up », « Ran », « It ») restent stricts ; `tolerance()` rend la valeur du premier palier dont la borne couvre la longueur, sinon `MAX_TOLERANCE`.
- **La longueur est celle de la clé, jamais celle de la saisie** : une saisie longue et fausse n'achète aucune tolérance.
- **Chiffres stricts** : une clé n'est candidate que si `digits(s) === digits(k)`. Une suite de chiffres différente n'est **jamais** tolérée : « shrek 2 » contre « shrek », « rocky 4 » contre « rocky 5 », « toy story 2 » contre « toy story 3 », « alien 3 » contre « aliens » sont refusés, alors que leur distance compacte vaut 1 (mesuré). Sans cette règle, avec un seul épisode publié d'une saga, la suite serait acceptée pour le mauvais film.
- **Calibré sur le corpus FR + EN, jamais par langue** (05 § Acceptation multilingue) : un titre EN peut être à faible distance du titre FR d'un autre film. Le barème v1 est un barème conventionnel, **confirmé ou ajusté par le rapport de collisions avant la clôture du J1** (§ 13.1) ; tout ajustement est une nouvelle version (§ 12).

### 6.4 Résultat, nature et ambiguïté

`App\ValueObjects\Answers\MatchResult` [nouveau, `final readonly`] : `bool $accepted`, `string $submittedNormalized`, `?int $answerKeyId`, `?string $answerKeyNormalized`, `?GuessMatchKind $matchKind`, `int $editDistance`, `bool $prefixWasAmbiguous`.

- `match_kind` se dérive par `AnswerKeyKind::toMatchKind()` [nouveau] : `TitleOriginal`, `TitleLatin`, `Title` → `Title` ; `Alias` → `Alias` ; `Prefix` → `Prefix` ; `Subtitle` → `Subtitle`. La correspondance quitte la fabrique `GuessFactory::forAnswerKey()`, qui y délègue.
- **`guess.prefix_was_ambiguous`** (E10-50) : vrai **si et seulement si** la forme soumise était portée, à l'instant du match, par un autre film publié, c'est-à-dire un appariement exact d'une clé de nature exacte homonyme (étape (a) avec `O≠` non vide). Il vaut **toujours faux** pour un `prefix` ou un `subtitle` accepté, que la décision 13 n'accepte que non ambigu, et pour une acceptation par tolérance, que (c) aurait refusée si `O≠` était non vide. Telle qu'écrite dans 10 § 7.6, la colonne valait faux sur toute ligne ; elle journalise désormais l'homonymie.
- `edit_distance` vaut 0 en (a) et (b), la distance retenue en (d). Le seuil qui l'a acceptée se lit par `game.validation_version` (10 § 7.2).

### 6.5 Instant d'évaluation

L'ambiguïté et le verdict sont évalués **une fois**, par les lectures K et O de l'étape S6 (§ 7.4), dans le traitement de la soumission : c'est la réalisation de « l'instant serveur de réception » de la décision 13 à la granularité d'une requête. La transaction de verrouillage **ne réévalue pas** le verdict ; elle ne revérifie que la recevabilité et l'état de saisie (§ 9.1). Une publication validée entre S6 et le verrouillage, quelques millisecondes, ne change donc rien, et c'est voulu : la règle n'est jamais rétroactive.

---

## 7. Le chemin d'une soumission

### 7.1 Routes et requêtes

Déclarées dans `routes/game.php`, créé par le premier lot qui en a besoin et chargé par `require` depuis `routes/web.php` (contrats C7 § 2.4, C10 § 2) :

| Méthode, chemin | Nom | Contrôleur | Middleware |
|---|---|---|---|
| `POST /seat/{player:public_id}/answer` | `round.answer.store` | `App\Http\Controllers\Game\AnswerController::store(AnswerStoreRequest $request, Player $player): JsonResponse` | `web`, `seat.active` (60), `throttle:answer` |
| `POST /seat/{player:public_id}/choice` | `round.choice.store` | `App\Http\Controllers\Game\ChoiceController::store(ChoiceStoreRequest $request, Player $player): JsonResponse` | idem |

- Le siège est adressé par `player.public_id`, donc la même route sert le salon et le solo. Le `public_id` ne donne **aucun droit** : `seat.active` (`App\Http\Middleware\EnsureActiveSeat`, 60) exige que le `player_token` courant tienne ce siège, non expulsé, et que l'en-tête `X-Seat-Token` égale `player.active_seat_token` ; sinon 403, ou 409 `{ code: 'seat_superseded' }` (contrat C7, R-43). Il met le siège résolu **et sa partie courante** en mémoire sur la requête (contrat C10 § 2, `AnswerStoreRequest`), pour le limiteur et pour les FormRequest. L'ordre écrit dans la colonne « Middleware » n'est pas l'ordre d'exécution : Laravel trie la pile par sa liste de priorité, et `seat.active` ne précède `throttle:answer` que par l'inscription du § 8.
- Corps JSON, clés camelCase : `round.answer.store` → `{ round: int, answer: string }` ; `round.choice.store` → `{ round: int, choice: string }`. `round` est la `sequence_index` de la manche que le client croit ouverte (le `sequenceIndex` des charges de C7), **jamais** `round.id`. `choice` est l'une des quatre chaînes reçues, renvoyée à l'octet près, **jamais un index**.
- `App\Http\Requests\Game\AnswerStoreRequest` : `round` → `required|integer|min:1` ; `answer` → `required|string|max:{settings_snapshot.maxAnswerLength}`, lue sur la partie courante du siège ; sans partie courante, la règle prend `RoomSettingsBounds::MAX_ANSWER_LENGTH` et le contrôleur répond 409 `closed` (ci-dessous). `App\Http\Requests\Game\ChoiceStoreRequest` : `round` → `required|integer|min:1` ; `choice` → `required|string|max:255`, largeur des colonnes `choice_1..4` (10 § 7.8).
- Actions : `App\Actions\Game\SubmitTextAnswer::handle(Player $seat, Game $game, int $roundSequence, string $answer, CarbonImmutable $receivedAt): SubmissionVerdict` et `App\Actions\Game\SubmitChoice::handle(Player $seat, Game $game, int $roundSequence, string $choice, CarbonImmutable $receivedAt): SubmissionVerdict`. La partie `$game` est la **partie courante du siège**, c'est-à-dire sa partie « en cours » au sens du contrat C17 (`game.ended_at IS NULL` et `status IN ('running','paused')`), résolue et mise en mémoire par `seat.active` (60, contrat C10 § 2). Le paramètre `Game` des deux actions n'est jamais nul : **sans partie courante, le contrôleur répond 409 `closed` sans appeler l'action**, et rien n'est compté.

### 7.2 Instant de réception

`$receivedAt = ReceptionInstant::of($request)` est capturé **une fois**, à l'entrée de la requête, **avant tout verrou et tout travail de validation** ; `$answeredAtMs = RoundClock::offsetMs($round, $receivedAt)` est un entier calculé une fois (contrat C7, R-22). L'attente d'un `lockForUpdate` ne doit jamais faire changer de palier (principe 1). La source est le middleware `CaptureReceptionInstant` de 60 (60 § 2.2), placé en tête du groupe `web`, qui range `Date::now()` dans un attribut de requête que `ReceptionInstant::of()` relit ; aucune valeur envoyée par le client n'y entre (L3). `REQUEST_TIME_FLOAT` est écarté : il échappe à `Date::setTestNow()`, et aucun test à horloge figée ne prouverait la fenêtre du § 7.3. L'attente d'une requête dans la file de PHP-FPM sous saturation, que ce middleware ne compte pas, est mesurée au test de charge (D33 du 23/09), et le choix reste à confirmer au relevé du VPS (S2 du 23/09).

### 7.3 Fenêtre d'acceptation

Une soumission est **recevable** si et seulement si (contrat C10 § 4, R-20) :

- `round.status = 'running'` — elle le reste de `T₁` jusqu'à `RevealRound`, qui la passe `revealing` à `ended_at + tier_grace_ms` (contrat C7 § 4.6) ;
- **et** `0 ≤ answeredAtMs` **et** `receivedAt < (round.ended_at ?? round.started_at + round.duration_ms) + game.tier_grace_ms` ;
- **et** `round.sequence_index` égale le `round` de la requête.

Hors fenêtre, la réponse est **409 `closed`** et rien n'est compté. La recevabilité est **revérifiée sous le verrou** de `round` dans la transaction de verrouillage.

- **Pourquoi jusqu'à `D + tier_grace_ms`** : une réponse tapée à `D − 100 ms` et reçue à `D + 150 ms` est honnête, et la grâce existe précisément pour absorber ce hasard réseau (principe 4, 10 § 7.5). Elle n'ouvre **aucune fuite** parce que 60 ne diffuse le paquet de titres qu'à `ended_at + tier_grace_ms` : la clôture est affichée à `ended_at`, sans titre (contrat C7 § 4.6). Aucune soumission reçue après l'émission des titres n'est recevable.
- **Pourquoi `ended_at` en premier** : après une fin anticipée, la fenêtre se referme `tier_grace_ms` après l'événement déclencheur, pas à `D`.
- Une réponse reçue dans la fenêtre mais traitée après `RevealRound` — requête ralentie — trouve `revealing` et reçoit 409 : la révélation l'emporte, puisque les titres sont partis (règle 3).

### 7.4 Séquence à travail constant (invariant L4)

Ordre fixe, **sans aucune branche qui dépende de la proximité** de la réponse. Les étiquettes sont celles du contrat C10 § 4 ; les lignes suivent l'**ordre réel d'exécution**, où S1 précède S0 (§ 8 : le limiteur lit le siège que `seat.active` a résolu, et ne le peut que si `seat.active` s'exécute avant lui) :

| Étape | Contenu | Issue si l'étape échoue |
|---|---|---|
| S1 | siège, partie courante et jeton de siège (`seat.active`, 60), exécuté avant la liaison implicite (§ 8) | 403 / 409 `seat_superseded` |
| S0 | limiteur `answer` (§ 8), clé sur le siège résolu en S1 | 429 |
| S2 | FormRequest : forme, longueur **brute** ; sans partie courante, 409 `closed` rendu par le contrôleur sans appeler l'action (§ 7.1) | 422 / 409 |
| S3 | `CatchUpGame::handle($game, $receivedAt)` (60), puis lecture de `round` par `(game_id, sequence_index)`, statut et fenêtre (§ 7.3) | 409 |
| S4 | lecture de `round_player` par `round_player_round_player_uq` ; puis `acceptsText()` (texte, et difficulté ≠ Facile) ou `acceptsChoice()` et QCM ouvert (clic) | 409 |
| S5 | texte : `normalize()` | 422 si `''` |
| S6 | texte : lectures K et O, toutes les distances (§ 6.1) | — |
| S7 | texte : `decide()` (§ 6.2) | — |
| S8a | refus : dans un `DB::transaction`, les deux lectures inconditionnelles de l'état du QCM (§ 7.5), puis **exactement une** instruction `UPDATE` de `round_player`, puis sa relecture par clé primaire. J1 : **aucune autre écriture**. J2 : **exactement une** écriture de tampon de quasi-juste, jamais conditionnelle (§ 11) | — |
| S8b | acceptation : transaction de verrouillage (§ 9) | — |

- **S3 rattrape à l'instant de réception.** `CatchUpGame` exécute les transitions échues à `$receivedAt`, jamais au-delà : l'ouverture de `T_N` et la composition du QCM qui l'accompagne ont eu lieu avant que S4 ne juge un clic, et la soumission elle-même ne déclenche jamais une révélation postérieure à sa réception.
- **Interdits** : toute lecture conditionnelle ; tout cache dont l'état chaud ou froid dépendrait de la saisie ; toute sortie anticipée du calcul de distance ; tout envoi de message, tout journal, toute écriture qui dépendrait du verdict de proximité.
- Le travail de S6 dépend de la **longueur** de la saisie, que l'attaquant connaît, et de la longueur des clés du film, fixe pour la manche ; jamais de la proximité. Le coût est borné par `maxAnswerLength` × longueur des clés, sous la milliseconde au défaut.
- **Aucun cache au J1**, L2 tenu par construction. Si un cache apparaît un jour, il exige une nouvelle version de cette section : il devrait invalider aussi les films dont `recomputeAmbiguity()` a requalifié une clé, que `touchedMovieIds()` ne contient pas, et il ne devrait jamais rendre un refus plus rapide qu'un autre.
- Le test de la propriété se joue **sous `Queue::fake()`** : sous `QUEUE_CONNECTION=sync`, un job exécuté dans la requête ferait dépendre le travail du job de la proximité ; « aucune ligne » s'entend d'**aucune insertion**, l'unique `UPDATE` conditionnel de `round_player` étant la même écriture pour tout refus (E10-57).

### 7.5 Refus : l'instruction unique

```sql
UPDATE round_player
SET input_state = CASE WHEN wrong_attempts + 1 >= :cap THEN :exhaustedState ELSE input_state END,
    input_closed_at = CASE WHEN wrong_attempts + 1 >= :cap AND :exhaustedState = 'attempts_exhausted'
                           THEN :receivedAt ELSE input_closed_at END,
    wrong_attempts = wrong_attempts + 1,
    updated_at = :now
WHERE id = :id AND input_state = 'open' AND wrong_attempts < :cap
```

- Portable MySQL et SQLite : les affectations d'état **précèdent** l'incrément, parce que MySQL évalue de gauche à droite quand SQLite lit les valeurs d'origine. `:cap` = `settings_snapshot.attemptsPerRound`. L'instruction passe par `RoundPlayer::query()`, dont `updated_at` suit le format sub-seconde du modèle, et lie `:receivedAt` formaté `Y-m-d H:i:s.v` : un `DB::table()` perdrait les millisecondes en silence (10 § 1.2).
- `:exhaustedState` vaut `attempts_exhausted` en Expert, **et en Normal** dès que la transition d'ouverture de `T_N` a été appliquée sans QCM, soit `round_tier(N).served_at IS NOT NULL` **et** `round.decoy_movie_id_1 IS NULL` (composition terminale, § 10.7) ; sinon, en Normal, `text_exhausted`.
- **Ces deux valeurs sont lues dans la transaction de S8a, jamais sur la ligne `round` chargée en S3**, dans cet ordre : `SELECT decoy_movie_id_1 FROM round WHERE id = ? FOR SHARE`, puis `SELECT served_at FROM round_tier WHERE round_id = ? AND tier_index = :n`, puis l'`UPDATE`. Pourquoi : une soumission **reçue** avant `T_N` et **traitée** après le commit de `OpenTier(N)` — requête lente, ou job de `T_N` concurrent, puisque `CatchUpGame($receivedAt)` n'exécute jamais `T_N` pour elle (§ 7.4) — lirait en S3 un état périmé et écrirait `text_exhausted` **après** que la composition terminale a converti les sièges `text_exhausted` : le siège ne recevrait jamais de QCM, garderait le message « les propositions arrivent avec la dernière image » et bloquerait la fin anticipée jusqu'à `D`. Le verrou partagé sur `round` sérialise le refus avec `ComposeChoiceSets`, qui tient `round FOR UPDATE` dans la transaction d'`OpenTier` (60 § 6.3) : ou bien le refus passe avant et la composition convertit le siège, ou bien il passe après et lit l'état terminal. L'ordre de verrouillage `round` puis `round_player` est conservé (contrat C7 § 4.3). Les deux lectures sont exécutées **pour tout refus**, en toute difficulté : leur nombre ne dépend pas de la proximité, L4 tient. Écart à la lettre du contrat C10 § 4 (« `receivedAt ≥ T_N`, lu sur la ligne chargée en S3, sans requête supplémentaire »), **signalé au porteur**.
- Si aucune ligne n'est touchée (fermeture concurrente), la réponse est 409 `closed` ; le nombre de requêtes est le même.
- La relecture par clé primaire, toujours exécutée, fournit `inputState` et `attemptsLeft` exacts à la réponse. Si la ligne est passée `attempts_exhausted`, `InputClosed` est émis après commit ; la réévaluation de la fin anticipée par 60 dépend du **compteur**, jamais de la proximité. Une émission en double sous concurrence est absorbée par l'idempotence des écouteurs de 60.
- **Toute soumission fausse compte**, y compris la répétition exacte de la précédente : aucune saisie fausse n'est mémorisée (décision 19), donc aucune ne peut être reconnue comme déjà soumise.

### 7.6 Clic

- S4 : `acceptsChoice()`, difficulté ≠ Expert, `answeredAtMs ≥ starts_at_offset_ms` du palier `choicesOpenTierIndex(N)` et `round.decoy_movie_id_1 IS NOT NULL` (QCM composé). Un clic reçu **avant** l'ouverture du QCM est 409 `closed`, décidé par 70 **avant** tout appel à 80 ; en cas terminal, il n'y a pas de QCM et tout clic est 409.
- S5' : lecture de `round_choice_set` par `(round_id, round_player.choices_locale)`. Posée à la composition, ou par le cas défensif de `ChoicesPresenter` (§ 10.8), `choices_locale` existe dès que le siège a pu voir les propositions ; à défaut, aucune ligne ne correspond et la réponse est 422.
- Une chaîne **hors** des quatre propositions donne **422** (`game.choices.invalid`), non comptée, et la saisie reste ouverte : c'est une requête fabriquée, pas un choix.
- `choice === choice_1`, **égalité stricte**, sans jamais consulter `answer_key` (10 § 7.8) : transaction de verrouillage avec `MatchResult { accepted: true, answerKeyId: null, answerKeyNormalized: normalize(choice_1), submittedNormalized: normalize(choice), editDistance: 0, prefixWasAmbiguous: false, matchKind: Choice }`. Une correction de titre entre la composition et le clic ne change donc pas le verdict.
- Sinon : `UPDATE round_player SET input_state = 'qcm_wrong', input_closed_at = :receivedAt WHERE id = :id AND input_state IN ('open','text_exhausted')`, puis `InputClosed`. `wrong_attempts` n'est pas touché : les plafonds ne concernent que le texte.
- **Si cet `UPDATE` ne touche aucune ligne** — une bonne réponse texte concurrente a verrouillé le siège, ou un autre clic l'a fermé —, la réponse est 409 `closed` avec l'`inputState` **relu par clé primaire**, et `InputClosed` n'est pas émis : la base dit `locked` ou `qcm_wrong`, et la réponse ne doit jamais dire `rejected` ni rouvrir une réévaluation sur un état qu'elle n'a pas écrit.

### 7.7 Réponses HTTP

Une réponse HTTP a un destinataire unique : ses messages sont résolus côté serveur, dans la locale de la requête (05 § Erreurs, validation et messages à destinataire unique).

| Cas | Statut | Corps |
|---|---|---|
| Acceptée (texte ou clic) | 200 | `{ result: 'accepted', inputState: 'locked', lockRank, tierIndex, pointsTier, pointsBonus, pointsTotal }` |
| Refusée (texte faux, quelle qu'en soit la cause ; ou clic faux) | 200 | `{ result: 'rejected', inputState, attemptsLeft }` ; `attemptsLeft` = `attemptsPerRound − wrong_attempts` si `inputState = 'open'`, sinon 0 |
| Saisie ou manche close | 409 | `{ result: 'closed', inputState, message }`, `message` = `game.answer.closed` (lettre du contrat C10 § 3) ; quand l'`inputState` relu vaut `text_exhausted` (texte tapé par un siège qui attend le QCM), le client affiche le message d'état de `inputState` (§ 16), jamais `message` : ce 409 ne ferme pas le QCM attendu |
| Trop rapide | 429 | `{ message }` = `game.answer.too_fast`, résolu dans la locale de la requête grâce au rang de `SetLocale` (§ 8) ; non évaluée, non comptée |
| Vide après normalisation, trop longue, ou chaîne absente des quatre | 422 | erreurs de validation Laravel indexées sur `answer` ou `choice` : `game.answer.unreadable`, `validation.max.string` avec l'attribut `validation.attributes.answer`, `game.choices.invalid` ; non comptée |
| Siège non résolu ou supplanté | 403 / 409 `{ code: 'seat_superseded' }` | propriété de 60 et 40 |

`SubmissionVerdict` [nouveau, `final readonly`] : `SubmissionOutcome $outcome`, `RoundPlayerInputState $inputState`, `int $attemptsLeft`, `?int $lockRank`, `?TierScore $score` ; `toArray(): array` rend les corps ci-dessus, le contrôleur ajoutant `message` au cas `closed` ; `httpStatus(): int` rend 200 pour `Accepted` et `Rejected`, 409 pour `Closed`. `App\Enums\SubmissionOutcome` [nouveau] : `Accepted = 'accepted'`, `Rejected = 'rejected'`, `Closed = 'closed'`.

**Aucune réponse ne porte** de titre, d'alias, de `match_kind`, de forme normalisée ni de distance. Un refus pour préfixe ou sous-titre ambigu a **exactement le même corps** qu'un refus franc, et aucun texte de refus ne suggère la proximité : jamais de « presque », jamais de « pas tout à fait » (décision 13). La règle est expliquée une fois pour toutes dans l'écran d'aide (`game.help.prefix`), jamais en manche.

**Idempotence** : une seconde soumission d'un siège déjà verrouillé rend 409 `closed` avec `inputState: 'locked'`. `guess_round_player_uq` et `guess_round_rank_uq` sont des filets, pas des mécanismes.

---

## 8. Cadence, plafond et longueur

**Limiteur `answer`**, déclaré dans `App\Providers\FortifyServiceProvider::configureRateLimiting()`, où vivent tous les limiteurs nommés du dépôt :

```php
RateLimiter::for('answer', fn (Request $r): Limit => /* siège et partie mis en mémoire par seat.active */
    $seat === null
        ? Limit::none()
        : Limit::perSecond($snapshot?->attemptsPerSecond ?? RoomSettingsBounds::DEFAULT_ATTEMPTS_PER_SECOND)
            ->by("answer:{text|choice}:{$seat->id}")
            ->response(/* 429 JSON, game.answer.too_fast */));
```

- **Clé sur le siège, jamais sur l'IP ni sur le seul `public_id`** : aucune IP n'entre dans une donnée du domaine, et une clé sur le `public_id` laisserait un tiers qui le connaît vider le budget d'un autre. Le siège est lu dans la closure depuis la résolution mise en mémoire par `seat.active`, qui s'exécute avant le limiteur (ci-dessous) : un siège non résolu a déjà reçu 403 de S1, et `Limit::none()` n'est qu'un filet. `$snapshot` est le `settings_snapshot` de la partie courante mise en mémoire par `seat.active` ; sans partie courante, la cadence vaut `RoomSettingsBounds::DEFAULT_ATTEMPTS_PER_SECOND` et le contrôleur répond 409 (§ 7.1). La clé vit dans le cache, pas en base.
- **Ordre des middlewares, obligatoire.** Laravel range `ThrottleRequests` dans la liste de priorité du noyau (`$middlewarePriority`), avant `SubstituteBindings`, et trie toute pile de route selon cette liste : un middleware prioritaire remonte au-dessus d'un middleware prioritaire moins prioritaire déjà rencontré, un middleware absent de la liste garde sa place. Déclaré tel quel au § 7.1, `throttle:answer` s'exécuterait donc avant `SubstituteBindings`, avant `SetLocale` (ajouté en fin de groupe `web` par `bootstrap/app.php`) et avant `seat.active` : la closure ne verrait jamais de siège et rendrait toujours `Limit::none()` — aucune cadence, donc ni anti-spam (00 § Anti-spam), ni budget de force brute à 1/s (§ 13.2) —, et le corps 429 sortirait dans `APP_LOCALE`, contre la règle 4. `bootstrap/app.php` inscrit donc, **dans cet ordre**, `$middleware->prependToPriorityList(ThrottleRequests::class, SetLocale::class)` puis `$middleware->prependToPriorityList(ThrottleRequests::class, EnsureActiveSeat::class)`, sur le modèle de l'inscription existante d'`EnsureUserHasRole`. La pile réelle devient `…`, `SetLocale`, `EnsureActiveSeat`, `ThrottleRequests:answer`, `SubstituteBindings`, `…`. Deux conséquences :
  - `EnsureActiveSeat` s'exécute **avant la liaison implicite** : il résout le siège depuis le paramètre brut (`(string) $request->route('player')`, le `public_id`), jamais depuis un modèle lié ; le contrôleur reçoit toujours `Player $player` lié, `SubstituteBindings` passant ensuite ;
  - `SetLocale` ne dépend d'aucune liaison de route — il lit l'utilisateur, le cookie `locale` et la revendication du `player_token` —, et la locale est posée avant **tout** limiteur nommé, `game-read`, `game-write` et `frame-serve` de 60 compris.
  
  **Exigences entre specs, signalées au porteur** : le rang d'`EnsureActiveSeat` (lecture des paramètres bruts sur toutes ses routes) à 60, propriétaire de `seat.active` ; le rang de `SetLocale` à 05, son propriétaire. L'ordre S0 puis S1 de la lettre du contrat C10 § 4 est ainsi corrigé dans les faits (§ 7.4). Si le lot de 60 ou de 05 n'a pas posé ces lignes, L70-14 les pose.
- Deux budgets par siège, un pour le texte, un pour le clic. **Deux sièges d'une même personne doublent le budget** : résidu assumé, formulé « par siège » (10 § 7.1, A-08).
- **Au J1** le limiteur vaut toujours `attemptsPerSecond` = 1 par seconde (défaut de `RoomSettingsBounds`), l'onglet Avancé n'arrivant qu'au J2 (contrat C0 § 4.4).

**Plafond de tentatives en texte libre** : `settings_snapshot.attemptsPerRound`, défaut `defaultAttemptsPerRound(D)` = `ceil(D / 2 s)` plafonné à 30, bornes 5 à 50 (`RoomSettingsBounds`). Au J1, il suit `D` par la règle Simple (D34 du 23/09) : 5 à `D` = 10 s, 15 au défaut de 30 s, 30 à partir de 60 s. Un plafond fixe serait mort à `D` = 10 s, où la cadence en autorise 10, et punitif à 120 s (00 § Anti-spam). **Seuls les refus texte comptent** : 409, 422 et 429 ne consomment rien, un clic ne consomme rien.

**Longueur maximale** : `settings_snapshot.maxAnswerLength` (défaut 100, bornes 20 à 200), mesurée sur la saisie **brute** en caractères par la règle `max` du FormRequest ; au-delà, 422 non compté. La borne haute égale la largeur des formes normalisées : aucune saisie légale n'est tronquée avant translittération. Au J1, la valeur reste au défaut.

---

## 9. Verrouillage (`App\Actions\Game\LockGuess`)

### 9.1 La transaction

`LockGuess::handle(Round $round, RoundPlayer $roundPlayer, Game $game, MatchResult $match, GuessSource $source, CarbonImmutable $receivedAt, int $answeredAtMs): SubmissionVerdict` est **la seule écrivaine de `guess`**. Dans un seul `DB::transaction`, dans cet ordre (10 § 7.6, 10 A12, E10-51) :

1. `SELECT … FROM round WHERE id = ? FOR UPDATE`, puis revérification de la recevabilité (§ 7.3) ;
2. `SELECT … FROM round_player WHERE id = ? FOR UPDATE`, puis revérification de `acceptsText()` ou `acceptsChoice()` : un clic faux et une bonne réponse texte concurrents ne donnent **jamais** `locked` et `qcm_wrong` ensemble ;
3. `ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $answeredAtMs, $source)`, fonction pure de 80 ;
4. `UPDATE round SET found_count = found_count + 1`, puis `lock_rank := found_count` lu sous verrou ;
5. `INSERT guess` avec l'instantané complet (§ 9.3) ;
6. `UPDATE round_player SET input_state = 'locked', input_closed_at = :receivedAt` ;
7. `AnswerAccepted::dispatch()`, livré **après le commit**.

L'ordre de verrouillage `round` puis `round_player` est imposé à tout écrivain, dans l'ordre global room → player → game → round → round_player (contrat C7 § 4.3). **La transaction ne verrouille jamais `game`** (contrat C13 § 4.5). Si une revérification échoue, la réponse est 409 `closed` sans aucune écriture.

`lock_rank` est l'**ordre d'acquisition du verrou**, soit l'ordre d'arrivée en file (00 § Égalités). Il peut s'inverser avec `answered_at_ms` à quelques millisecondes près, puisque `receivedAt` est capturé avant le verrou ; le départage de 80 lit `answered_at_ms`, jamais `lock_rank`.

### 9.2 Palier et points : l'appel à 80

70 n'écrit **aucune** formule de palier ni de points. `ScoreCalculator::forGuess()` (contrat C13 § 4.1) résout tout depuis la partie figée : `game.tier_grace_ms`, `settings_snapshot.speedBonus`, B_max par `ScoringRules::speedBonusMaxPercent(game.frames_per_round, game.scoring_version)` et le **plancher de palier du QCM** par `ScoringRules::floorTierIndex(source, game.input_difficulty, game.frames_per_round, game.scoring_version)`, seule implémentation (R-18). Conséquence pour la saisie, à titre d'illustration au réglage par défaut (`N` = 3, `T_N` = 20 s, `tier_grace_ms` = 300 ms) :

- un **texte** reçu à `T_N + 100 ms` est crédité au palier `N − 1` : l'instant corrigé `max(0, answeredAtMs − tier_grace_ms)` tombe avant `T_N` ; c'est la grâce du principe 4 ;
- un **clic** reçu au même instant est crédité au palier `N`, avec `t = 0` : les propositions n'existaient pas avant `T_N` (00 § Saisie de la réponse ; plancher : R-18, contrat C13 § 4.1), et sans plancher la grâce vendrait le palier `N − 1` à un clic impossible avant la frontière.

Le rejeu relit `guess.source`, `game.input_difficulty` et `game.frames_per_round` (E10-48). Les quatre sorties de `TierScore` sont écrites telles quelles ; aucune autre écriture de ces colonnes n'existe, aucun recalcul n'a jamais lieu (contrat C13 § 4.2).

### 9.3 Instantané de règle dans `guess`

Toujours complet, pour que le journal conservé douze mois explique une acceptation sans relire aucune table vivante (décision 13, 10 § 7.6) :

| Colonne | Texte | Clic |
|---|---|---|
| `received_at` | `receivedAt` | `receivedAt` |
| `answered_at_ms` | `answeredAtMs` | `answeredAtMs` |
| `tier_index`, `points_tier`, `points_bonus`, `points_total` | `TierScore` de 80 | `TierScore` de 80, plancher compris |
| `lock_rank` | § 9.1 | § 9.1 |
| `source` | `text` | `choice` |
| `match_kind` | `key_kind->toMatchKind()` | `choice` |
| `answer_key_id` | clé retenue (`nullOnDelete`) | NULL |
| `answer_key_normalized` | forme de la clé retenue | `normalize(choice_1)` |
| `submitted_normalized` | `s` | `normalize(choice)` |
| `edit_distance` | 0 en (a) et (b), distance retenue en (d) | 0 |
| `prefix_was_ambiguous` | § 6.4 | faux |

Jamais de texte brut, jamais d'IP, jamais d'horodatage client. `guess.answer_key_id` en `nullOnDelete` : une reprojection qui supprime une clé ne fait jamais perdre un score, l'instantané restant autosuffisant.

### 9.4 Crochets vers 60 et 80

- **80** : la fonction pure est appelée à l'étape 3, une seule fois par bonne réponse.
- **60** : `App\Events\Game\AnswerAccepted` [nouveau] (`int $roundId`, `int $playerId`, `int $lockRank`) et `App\Events\Game\InputClosed` [nouveau] (`int $roundId`, `int $playerId`, `RoundPlayerInputState $state`, qui vaut `QcmWrong` ou `AttemptsExhausted`) implémentent `ShouldDispatchAfterCommit` et **jamais `ShouldBroadcast`** : ce sont des identifiants internes réservés aux écouteurs de 60. Ceux-ci appellent `SeatInputClosed::handle()` dans leur propre transaction, qui reprend `round FOR UPDATE`, émet `player.locked` (multijoueur seulement) et réévalue `Round::isEarlyEndReached()` (contrat C7, R-21). Comme chaque écriture qui clôt une saisie est suivie, après son commit, d'une réévaluation sous le verrou `round`, le dernier à clore voit toujours l'état complet.
- Le chemin de refus (une seule écriture, § 7.5) ne tient qu'un verrou **partagé** sur `round`, jamais le verrou exclusif, et ne peut donc pas appeler lui-même un crochet qui l'exige : c'est la raison des événements de domaine.

### 9.5 Ce que voient le siège et le salon

- **Le siège** reçoit ses points dans la réponse HTTP (destinataire unique) et, à la resynchronisation, dans `SelfState.input.locked` (§ 16). Il ne voit **jamais** le titre avant la révélation, ni ce que les autres ont tapé (principe 3).
- **Le salon** reçoit, par 60, `player.locked` = enveloppe + `{ sequenceIndex, publicId, lockRank }`, **rien d'autre** : ni points, ni `tierIndex`, ni chaîne (10 § 7.6). `InputClosed` n'est **jamais** diffusé : `qcm_wrong` révélerait une mauvaise réponse. Aucune charge n'expose `player.id`, `round.id`, `game.id` ni `answer_key.id` (10 § 1.1). Il n'existe pas de `seat.locked` au J1 (R-21).

---

## 10. QCM : leurres et composition (contrat C11)

### 10.1 Moment et instant théorique

Les trois leurres sont tirés **à la première composition**, jamais au lancement (E10-15) :

- `App\Actions\Game\ComposeChoiceSets::handle(Round $round, CarbonImmutable $at): bool` est appelée par 60 dans la transition qui ouvre le palier `choicesOpenTierIndex(N)` — `T₁` en Facile, `T_N` en Normal, jamais en Expert —, ou par `CatchUpGame` qui la remplace. **Ordre à `T₁` en Facile** : les lignes `round_player` naissent d'abord (E10-49), la composition ensuite, l'émission après commit (contrat C7 § 4.8).
- `$at` est l'**instant théorique** `round.started_at + starts_at_offset_ms(choicesOpenTierIndex(N))`, **jamais l'heure d'exécution** : les leurres ne dépendent pas du retard d'un job.
- La méthode prend `round FOR UPDATE` ; si la manche est déjà composée (`decoy_movie_id_1` non nul), elle ne fait rien et rend vrai. Elle rend vrai si les quatre propositions existent après l'appel, faux dans le cas terminal (§ 10.7). Elle est idempotente ; ne pas rejouer une étape d'ouverture déjà appliquée appartient à 60 (contrat C7 § 4.5).

Pourquoi à la composition et pas au lancement : un tirage au lancement laisserait le profil de titre évoluer jusqu'à `T_N` — un titre FR ajouté par le curateur en cours de partie — et produirait un QCM linguistiquement hétérogène, c'est-à-dire une fuite ; la requête de profil est une égalité indexée à l'instant de la composition (10 § 3.2). Le docblock de `RoundFactory::withDecoys()` devient « tirés à la première composition (`T₁` Facile, `T_N` Normal) ».

### 10.2 Candidats et exclusions (D21 du 23/09)

- Exclusions **toujours** appliquées, portées par `PoolScope::forDecoys($round, $at)` (contrat C2) : le film cible, les films de son `movie_group`, et les films des manches **déjà démarrées** de la partie (`started_at ≤ $at`, `T₁` franchi, définition de « joué » du contrat C2).
- **Jamais** les films des manches futures, programmées comprises : les exclure prouverait qu'un film vu en leurre n'est pas dans la suite du tirage, que le lancement garde secret (00 § Déroulé, étape 6).
- Chaque candidat est `published` et `clear` (`Movie::inPool()`), et la liste de chaque rang est triée par `movie.id` croissant.
- La non-répétition du salon, quand elle est active, **est conservée à tous les rangs** : un leurre que le salon a joué à la partie précédente ne peut pas être la cible, il serait éliminable de mémoire.

### 10.3 Échelle de tirage

| Rang | Ensemble (contrat C2) | Filtre de profil | Contexte de permutation (contrat C3) | Mode |
|---|---|---|---|---|
| R1 | vivier du salon : `PoolQuery::movies(PoolScope::forDecoys($round, $at))` — thèmes du snapshot, `levels_count ≥ N`, non-répétition si `noRepeatMovies` | `movie_projection.title_mask_version = Locale::MASK_VERSION` **et** `title_locale_mask` égal à celui de la cible ; si ce masque vaut 0, même `OriginalTitleForm` en plus | `DrawContext::decoys($sequenceIndex)` | normal |
| R2 | catalogue publié : `forDecoys($round, $at)->withThemeIds([])->withFramesPerRound(null)`, non-répétition conservée, moins les candidats déjà examinés en R1 | idem | `DrawContext::decoys($sequenceIndex)` | normal |
| R3 | vivier du salon | même `OriginalTitleForm` que la cible | `DrawContext::decoysOriginal($sequenceIndex)`, tirage **repris à zéro** | dégradé : `choices_use_original_title = true` pour tout le salon |
| R4 | catalogue publié, non-répétition conservée, moins les candidats examinés en R3 | idem | `DrawContext::decoysOriginal($sequenceIndex)` | dégradé |

- Les leurres retenus en R1 sont conservés quand R2 complète. On passe au mode dégradé (R3) **seulement** si R1 et R2 ne fournissent pas trois leurres, **ou** si la version du masque de la cible n'est pas la version courante : l'échec tombe du côté visible, jamais du côté silencieux (10 § 3.2).
- **Aucun rang ne lève la non-répétition** : D21 du 23/09 ne prévoit que le vivier du salon, le catalogue publié non-répétition conservée, puis le mode dégradé. Un rang « catalogue sans non-répétition » serait une extension de D21 du 23/09 que seul le porteur pourrait ajouter.
- **`App\Enums\OriginalTitleForm`** [nouveau] : `Latin = 'latin'`, `Transliterated = 'transliterated'`, `Native = 'native'` ; `static of(Movie $movie): self`. Test en PCRE, sans `ext-intl` : `title_original` est `Latin` s'il ne contient que `\p{Latin}\p{Common}\p{Inherited}` ; sinon `Transliterated` si `title_original_latin` existe, sinon `Native`. Pourquoi : quand les quatre chaînes sortent de `title_original`, l'écriture et la présence d'une translittération sont une dimension que le masque ne porte pas ; un seul titre en kanji parmi trois titres latins désignerait la cible.
- `App\Support\Answers\DecoyPicker::pick(Round $round, Game $game, CarbonImmutable $at): ?DecoyPick` porte l'échelle ; `App\ValueObjects\Answers\DecoyPick` [nouveau, `final readonly`] : `list<int> $movieIds` (exactement trois, dans l'ordre du tirage), `bool $useOriginalTitle`. NULL dans le cas terminal.

### 10.4 Tirage dans un rang

- **Uniforme, sans remise** : les candidats du rang, triés par `movie.id`, sont parcourus dans l'ordre de `SeededPrf::forGame($game)->permutation($context, n)`, `n` = taille du rang (contrat C3, R-17). `shuffle`, `mt_srand`, `random_int`, `array_rand`, `Arr::random`, `Collection::shuffle()` et `->random()` sont interdits sur ce chemin, qui dépend de la graine (contrat C3 § 4).
- Un candidat est **rejeté** si son `movie_group` est déjà pris par un leurre retenu, ou si l'une de ses chaînes rendues (par locale activée, dans le mode courant) donne par `normalize()` la même forme que celle de la cible ou d'un leurre retenu dans cette locale. Les quatre chaînes sont donc **deux à deux distinctes dans chaque locale**, et un clic n'est jamais ambigu.
- Le parcours s'arrête au troisième leurre retenu. Le tirage est **déterministe** pour une graine, une `sequence_index` et un état du catalogue à `$at` donnés.

### 10.5 Rendu des chaînes et langue effective

- **`App\Support\I18n\DisplayTitleResolver`** [nouveau, **partagé**] est l'**unique** implémentation de la chaîne de repli de 05 ; 60 l'emploie pour la révélation (contrat C7, `RevealMovie`), et `MovieTitleResolver` n'est pas créé (R-23). `resolve(Movie $movie, Locale $locale): ResolvedTitle` rend `movie_title` dans `$locale` (rang 1), sinon dans les autres locales activées par `fallbackRank()` (rang 2), sinon `original()` (rang 3). `original(Movie $movie): string` rend `title_original_latin` quand `title_original` n'est pas latin et qu'une translittération existe, sinon `title_original`. **Jamais un alias.** `App\ValueObjects\I18n\ResolvedTitle` [nouveau, `final readonly`] : `string $text`, `?Locale $locale` (NULL = titre original), `int $rank` (1, 2 ou 3).
- **Mode normal** : chaîne de la ligne `L` = `resolve(film, L)->text`. **Mode dégradé** : `original()` pour les quatre films et toutes les locales.
- `round_choice_set.rendered_locale` (E10-03) = la locale atteinte (rang 1 ou 2) de la ligne `L`, **NULL** au rang 3 ou en mode dégradé. Si, à la composition, les quatre films n'atteignent pas la même locale pour une même `L` (masque périmé), on bascule en mode dégradé.
- Chaque chaîne est écrite **nettoyée de ses bords par `Illuminate\Support\Str::trim()`**, la fonction même qu'applique le middleware **global** `TrimStrings` au corps du clic, et **jamais par `trim()` de PHP**. `Str::trim()` retire aussi les espaces Unicode et les caractères invisibles de bord (U+00A0, U+200B, U+200E, U+FEFF…) que `trim()` garde : un titre TMDB bordé d'une espace insécable ou d'une marque de direction, stocké par `trim()`, ne serait jamais égal à sa propre soumission nettoyée, et la bonne proposition recevrait un 422 `game.choices.invalid` — le joueur ne pourrait pas gagner.
- **Homogénéité** (05 § QCM) : les mêmes quatre films pour tout le salon ; seules la langue de rendu et l'ordre changent. Aucune traduction à la volée : un leurre est un titre réel d'un film réel du catalogue.

### 10.6 Écritures

Toutes dans la transaction de `ComposeChoiceSets` :

- `round.decoy_movie_id_1..3` et `round.choices_use_original_title` ;
- une ligne `round_choice_set` **par locale activée** (`Locale::cases()`), avec `choice_1` = chaîne du film cible, `choice_2..4` = chaînes des leurres dans l'ordre de `decoy_movie_id_1..3`, `rendered_locale`, `composed_at = $at` ;
- `round_player.choices_locale = player.locale` et `choices_composed_at = $at`, pour chaque ligne `round_player` de la manche dont `input_state ∈ {open, text_exhausted}`.

Une ligne par locale et non par joueur : 20 lignes par partie à deux locales au défaut, et la règle « tout renvoi rejoue les mêmes quatre chaînes » tenue réellement (10 § 7.8, 10 A11). Une composition qui échoue ne laisse aucune ligne partielle ; rappelée, elle recommence.

### 10.7 Cas terminal

Moins de trois leurres même après R4 :

- aucune ligne `round_choice_set` n'est écrite, les leurres restent NULL, et `ComposeChoiceSets` rend faux ;
- **en Normal** : les sièges `text_exhausted` passent `attempts_exhausted` (`input_closed_at` = `$at`), avec `InputClosed` ; tout épuisement ultérieur dans la manche mène à `attempts_exhausted` (§ 7.5), **y compris un épuisement reçu avant `T_N` mais traité après la composition terminale**, que les lectures de S8a voient ; le texte libre reste le seul mode et le QCM n'apparaît pas ;
- **en Facile** : 60 annule la manche avec `RoundIncidentReason::ChoicesUnavailable = 'choices_unavailable'` [nouveau cas, E10-07], par le mécanisme d'échec technique (remplacement par la réserve, contrat C3).

C'est l'**état `choices_unavailable`** de la manche (arbitrage de rédaction du 23/09). En Facile, il s'inscrit comme motif d'annulation ; **en Normal, il ne s'écrit dans aucune colonne** : il se lit `round_tier(N).served_at IS NOT NULL` et `round.decoy_movie_id_1 IS NULL`, et, côté siège, par `input.choices = null` et, pour un ancien siège `text_exhausted`, `inputState = attempts_exhausted`.

**Signal au siège (Normal).** Aucun événement nouveau : la liste de C7 § 2.3 est close, et `InputClosed` n'est jamais diffusé (§ 9.5). Le signal passe donc par la resynchronisation. Un client dont le siège est `open` ou `text_exhausted` et qui, après `tier.opened` pour `tierIndex = round.choicesAtTierIndex`, n'a pas reçu `seat.choices` pour ce `sequenceIndex` dans les `heartbeatIntervalMs` de la prop `realtime` relit `room.state`. Le délai n'est pas un minuteur de jeu (règle 8) : il absorbe seulement l'ordre d'émission de 60, qui pousse `tier.opened` **avant** `seat.choices` (60 § 6.3, étape 7), sans quoi chaque siège se resynchroniserait à chaque `T_N`. Le paquet rend `input.choices = null` et, pour un ancien siège `text_exhausted`, `inputState = attempts_exhausted`, que la page affiche par `game.answer.exhausted` ; l'écran ne promet plus de propositions après cette relecture. En solo, la resynchronisation à chaque `nextTransitionAt` (60 § 12.6) suffit. **Exigence entre specs adressée à 60**, propriétaire des déclencheurs de resynchronisation (60 § 12.6), signalée au porteur.

Probabilité au J1 : réelle pour un film de la voie d'exception sans titre dans une locale activée et d'une forme de titre original rare (`Native`, sans translittération). Surtout, **certaine** dans les dernières manches d'une partie jouée en fin de mémoire d'un salon, quand le vivier du salon approche `M`, **quel que soit le profil de titre**, puisque la non-répétition est conservée à tous les rangs (D21 du 23/09) : à `W = M`, dès la manche `M − 2`, en Facile ces manches sont annulées, en Normal elles se jouent sans QCM (30 § 10.3).

**Échec technique de la composition.** `ComposeChoiceSets` **calcule tout avant d'écrire** : tirage de `DecoyPicker` et rendu des chaînes de toutes les locales activées. Une exception levée dans cette phase de calcul, qui n'a rien écrit, est rapportée par `report()` — sans titre ni chaîne de proposition dans le contexte journalisé — et traitée **comme le cas terminal** : la méthode rend faux, et la manche continue en texte seul (Normal) ou est annulée puis remplacée (Facile). Sans ce repli, une donnée de catalogue qui fait lever le tirage annulerait `OpenTier` à chaque rejeu — job, `failed()` redispatché, puis chaque `CatchUpGame` de chaque requête (60 § 4.6) — et bloquerait la manche, puis la partie, jusqu'à la clôture d'une partie bloquée (60 § 14.4). Une exception de la **phase d'écriture** (base : connexion, verrou, contrainte) remonte et annule `OpenTier`, que le mécanisme d'échec de 60 rejoue : l'intercepter laisserait des lignes partielles.

### 10.8 Permutation par siège et rejeu identique

- **Permutation** : Durstenfeld du contrat C3, `SeededPrf::forGame($game)->permutation(DrawContext::qcmOrder($round->sequence_index, $player->public_id), 4)` ; la proposition affichée en position `j` est la chaîne d'index `perm[j]` de `[choice_1, choice_2, choice_3, choice_4]`. Recalculée à chaque envoi, **jamais stockée**, identique d'un envoi à l'autre, différente d'un siège à l'autre (E10-54). Contexte `draw:qcm:{sequenceIndex}:{playerPublicId}`, jamais `round_id` ni `player_id`.
- **`App\Support\Answers\ChoicesPresenter::forSeat(RoundPlayer $roundPlayer): ?ChoicesPayload`** rend la ligne de `choices_locale`, permutée pour ce siège, si `choices_composed_at` est non nul. **Sinon**, si la manche est composée (`round.decoy_movie_id_1` non nul) et que `acceptsChoice()` est vrai — cas défensif —, il pose **une seule fois** `choices_locale = player.locale` et `choices_composed_at = round_choice_set.composed_at`, par une écriture conditionnelle `WHERE choices_locale IS NULL`, relit la ligne et rend celle de la locale ainsi figée, qui ne bouge plus. Dans tous les autres cas, il rend NULL. Les deux colonnes étant toujours écrites ensemble (§ 10.6), c'est la seule lecture qui rende le cas défensif atteignable, conforme au contrat C7 § 3 (`SelfState.input` : « choices rejoué si `choices_composed_at` non nul ; sinon composé si l'instant est franchi et `acceptsChoice()` »).
- **`App\ValueObjects\Answers\ChoicesPayload`** [nouveau, `final readonly`] : `list<string> $choices` (4), `bool $useOriginalTitle`, `?Locale $lang` ; `toArray(): array{choices: list<string>, useOriginalTitle: bool, lang: string|null}`, où `lang` = `Locale::bcp47()` de `rendered_locale`, NULL si les chaînes sortent de `title_original`.
- **Charge ciblée** (règle 3, A-44) : `seat.choices` = enveloppe de 60 + `{ sequenceIndex }` + `ChoicesPayload`, un siège par message, **jamais** diffusée au salon. Aucune métadonnée par proposition, aucun identifiant de film, aucun index ni drapeau de la bonne réponse, aucune position conventionnelle. `choice_1..4` et `round.decoy_movie_id_*` ne sortent **que** par `ChoicesPresenter`.
- **Rejeu** : resynchronisation, rechargement, second onglet et changement de langue rejouent la ligne de `round_player.choices_locale`, figée à la composition, avec le même ordre et le même `lang`, lu dans `rendered_locale`. Deux tirages indépendants pour un même siège auraient la cible pour seul élément commun garanti ; un simple rechargement suffirait à l'isoler (05 § QCM, étape 7).
- **Destinataires** (60) : chaque `round_player` dont `acceptsChoice()` est vrai et dont le siège n'est ni parti ni expulsé, déconnecté compris (R-28). En solo, aucune diffusion : le QCM n'est livré que par `solo.state` (contrat C7 § 4.12).

### 10.9 Coût

Une fois par manche, jamais par joueur : égalité indexée `movie_projection_qcm_idx` et constructeur du vivier ; les titres ne sont lus que pour les candidats examinés, au plus quelques dizaines. Les titres lus pendant une composition peuvent être mis en mémoire pour la seule durée de la transaction.

---

## 11. Quasi-justes (`near_miss`) : règle complète, écriture au J2 (D24 du 23/09)

**Au J1** : aucune tâche, aucun message, aucun interrupteur, **aucune écriture**. La table reste vide et les alias se saisissent à la main dans l'écran de 20. Le report retire quatre à cinq heures de la taille du J1 (mesure de taille, jamais un budget, D36 du 23/09) et reste acquis sous D35 du 23/09 : rien de ce qui est marqué J2 ne remonte au J1 — amendé le 23/09. À 60 films, le curateur corrige les alias à la main, et le signal n'a de valeur qu'avec du trafic réel. Un interrupteur de configuration serait du code mort.

**Qualification** (J2) — une chaîne `s` est quasi-juste pour le film de la manche si :

- elle a été **refusée** en texte libre ;
- l'ensemble des autres films publiés portant `s` est vide ;
- il existe une clé `k` du film, de nature exacte ou dérivée non ambiguë, telle que `digits(s) = digits(k)` et `tolerance(k) < distance(s, k) ≤ tolerance(k) + AnswerRules::NEAR_MISS_MARGIN`.

La marge vise les fautes que la tolérance n'absorbe pas, jamais les saisies lointaines. `NEAR_MISS_MARGIN` est déclarée dès le J1 dans l'empreinte v1 (§ 12), sans autre lecteur : l'arrivée de la mécanique au J2 ne change aucune acceptation et ne doit pas changer la version.

**Alimentation, compatible L4** (J2) :

1. **Exactement une écriture par refus texte, jamais conditionnelle** : `s` est ajoutée au tampon de cache de la manche. Aucune qualification dans la requête : le tampon reçoit toute chaîne refusée, proche ou lointaine, pour que le travail ne dépende pas de la proximité (10 § 7.9). Durée de vie au plus `D + R` plus une marge ; le tampon ne porte **aucun identifiant de joueur**.
2. À la clôture de la manche (crochet fourni par 60 au J2), `App\Jobs\Answers\AggregateNearMisses` [nouveau, J2], file `default` — **jamais** la file `game` —, `ShouldBeEncrypted`, vide le tampon, dédoublonne par chaîne et qualifie.
3. Pour chaque chaîne qualifiée, il incrémente un compteur de cache par `(movie_id, sha256(s))`, de durée 90 jours, **sans identifiant de manche** : nombre de manches distinctes, nombre d'occurrences, meilleure distance.
4. **Au troisième incrément** — troisième couple (manche, chaîne) distinct —, la ligne `near_miss` est créée ou mise à jour : `distinct_rounds`, `occurrences`, `best_distance`, `first_seen_on` et `last_seen_on` au premier du mois.
5. La tâche intercepte toute exception et n'atterrit **jamais** dans `failed_jobs`, qui conserverait quatorze jours une tentative fausse (10 § 11.1), contre la décision 19.

**K-anonymat et rétention** : une ligne `occurrences = 1` serait la saisie d'une personne, que trois requêtes suffiraient à lui rattacher (10 § 7.9) ; d'où le seuil de trois manches et les dates mensuelles. Le tampon et le compteur sont deux lignes du tableau de rétention de 10 § 11.1 (E10-63) : « tampon de manche des refus : au plus `D + R` + marge, aucun identifiant de personne » ; « compteur de quasi-justes : 90 jours, sans identifiant de manche ». La table `near_miss` est purgée à 90 jours sur `last_seen_on`.

**Promotion** : le geste « promouvoir en alias » appartient à 20 ; il crée un `alias` d'origine `curator`, ce qui déclenche le projecteur (10 § 7.9). Les noms des clés de cache et les durées exactes sont fixés au lot J2 (§ Lots).

---

## 12. Version de la règle et rejouabilité (`validation_version`)

- **`game.validation_version = AnswerRules::VERSION`**, écrite au lancement par `OpenGame` (contrat C6, E10-39), colonne figée de la partie (E10-41). `GameFactory::VALIDATION_VERSION` est supprimée ; la fabrique lit `AnswerRules::VERSION`.
- **`AnswerRules::fingerprint(): string`** = SHA-256 d'un JSON canonique (clés triées) de : `VERSION`, séparateurs et longueur minimale lus dans `config/catalog.php`, articles (`leadingArticles()`), règle romaine, règle des chiffres, `TOLERANCE_STEPS`, `MAX_TOLERANCE`, règle du sous-titre, `NEAR_MISS_MARGIN`. Le test d'empreinte fige, **par version**, la valeur attendue : un changement de configuration ou de constante sans nouvelle version fait échouer la CI.
- **Représentation, pour que deux développeurs produisent la même empreinte.** Les règles sont des constantes chaînes d'`AnswerRules` : `ROMAN_RULE = 'ivx;1-39;len>=2|last-of->=2'`, `DIGITS_RULE = 'strict-int-sequence'`, `SUBTITLE_RULE = 'after-first-separator;titles-only;collision'`, `TRANSLITERATION = 'Str::transliterate;strict=false'`. Le JSON a pour clés `version`, `separators`, `minLength`, `articles`, `roman`, `digits`, `tolerance`, `maxTolerance`, `subtitle`, `nearMissMargin`, plus deux précisions de 70 à la liste du contrat C12, signalées : `maxNormalizedLength` (la troncature est une étape du normaliseur) et `transliteration`. Une chaîne de règle ne décrit pas l'algorithme, elle le **nomme** : un changement de code sans changement de nom est attrapé par les fixtures, un changement de paramètre par l'empreinte. Les attendus vivent dans `tests/Fixtures/answers/fingerprints.php`, indexés par version, à côté de `normalizer-v{n}.php`. **Seules l'entrée et les fixtures de `AnswerRules::VERSION` sont exécutées** ; celles des versions antérieures sont conservées, jamais exécutées ni réécrites, puisque le code n'implémente que la version courante.
- **Tout changement** d'étape du normaliseur, de liste d'articles, de règle romaine ou des chiffres, de barème de tolérance, de dérivation du sous-titre, de séparateurs, de longueur minimale, de marge de quasi-juste — et toute mise à jour de bibliothèque qui change une sortie des fixtures — **incrémente `AnswerRules::VERSION`, sans exception**. L'empreinte et les fixtures de la version précédente ne sont jamais réécrites : on en ajoute de nouvelles.
- **Ce que la version ne couvre pas** : le plancher de palier du QCM, qui relève de `scoring_version` (contrat C13, R-18), et `fold`, qui ne décide d'aucune acceptation.
- **La validation n'est jamais rejouée.** Contrairement au score, que `ScoreReplayer` recalcule, une acceptation est autosuffisante : `guess` fige la clé, les deux formes, la distance, la nature et l'ambiguïté. `validation_version` dit **sous quelle règle** la distance a été acceptée, pour qu'un litige puisse la lire. Le code n'implémente que la version courante. Le drainage (D32 du 23/09) garantit qu'aucune partie ne chevauche deux versions, **hors du résidu du déploiement non atomique** (D31 du 23/09, contrat C18-bis § 4) : Plesk dépose les fichiers avant le hook, donc si `deploy:guard` échoue, les fichiers sont déjà remplacés. Une partie encore en cours est alors jugée par le code de la nouvelle version, sur des clés `answer_key` que `catalog:reproject` n'a pas encore reprojetées, et `game.validation_version` ne décrit plus la règle appliquée à ses `guess` suivants. Résidu assumé (§ 14). Remède : la procédure de C18-bis — relancer `deploy:drain`, puis les actions de déploiement Plesk.
- **Reprojection obligatoire.** Tout changement du normaliseur est suivi de `catalog:reproject` (commande de 100, contrat C18-bis), qui reconstruit `answer_key` **par différence** : les identifiants des clés inchangées survivent, `guess.answer_key_id` passe à NULL pour une clé supprimée, et l'instantané reste autosuffisant. La commande est livrée avant le premier import de production (D1 du 23/09), ou dans le même déploiement que le lot du normaliseur, et s'insère dans le hook **avant `artisan deploy:release`**, donc avant la levée du drapeau de drainage : sans cet ordre, une partie lancée entre la levée et la reprojection comparerait une saisie normalisée en version n+1 à des clés de la version n, et aucune réponse touchée par le changement ne validerait. Exigence adressée à 100 (contrat C18-bis § 6), que 100 § 11.5 satisfait en jouant la reprojection à l'étape 7, avant `deploy:release` à l'étape 12. Elle n'écrit ni `movie`, ni `frame`, ni `movie_title`, ni `alias` : la règle 12 ne l'astreint pas à un instantané bloquant. Tant qu'aucun moteur n'existe, le hook tourne sans drainage (D37 du 23/09) et aucune partie ne peut chevaucher la reprojection : l'ordre ci-dessus s'impose dès que le drainage entre dans le hook, avant la première partie sur le VPS ; la livraison de la commande avant le premier import de production reste due. — amendé le 23/09

---

## 13. Calibrage

### 13.1 Rapport de collisions (`answers:collisions`)

`App\Console\Commands\AnswersCollisionsCommand` [nouveau], signature `answers:collisions {--json}`, **en lecture seule**, J1.

- Il liste (i) les formes **exactes** partagées par au moins deux films publiés, **alias TMDB compris** ; (ii) les couples de films publiés distincts dont deux clés, à suite de chiffres identique, sont à distance au plus `tolerance(kA) + tolerance(kB)`. Parmi eux, un couple à distance au plus `max(tolerance(kA), tolerance(kB))` est **direct** : une clé y est acceptée telle quelle pour l'autre film ; au-delà, jusqu'à la somme, c'est un **couple pivot**, qu'aucun titre réel n'exploite mais qu'une chaîne forgée à mi-chemin couvre pour les deux films (§ 13.2).
- Une ligne par couple : `{ normalizedA, movieA: {id, titleOriginal}, kindA, normalizedB, movieB, kindB, distance }`. La sortie table reprend ces noms de champs comme en-têtes et ne contient **aucune phrase** : aucun texte à traduire, les identifiants de film n'étant lus que par le porteur (le back-office adresse `movie` par son `id`, E10-11). Le caractère direct ou pivot se lit de `distance` et des longueurs des deux formes, sans champ de plus.
- Coût : comparaison des seules clés dont les longueurs compactes diffèrent d'au plus `2 × MAX_TOLERANCE`, la distance étant au moins l'écart de longueur. Quelques centaines de milliers de comparaisons au J1, un regroupement par longueur au-delà.
- **Outil du porteur, jamais sur le chemin du curateur** : au J1, aucun geste de curation ne dépend d'une commande artisan (D10 du 23/09) ; la suppression d'un alias fautif passe par l'écran d'alias de 20. L'affichage du rapport en back-office est un lot J2 de 20.

**Procédure de calibrage, avant la clôture du J1** (05 § Acceptation multilingue, A-47) : le porteur lance le rapport sur le catalogue publié réel. Chaque ligne (i) est soit corrigée par la curation (alias fautif supprimé), soit acceptée par écrit (homonyme légitime) ; chaque ligne (ii) directe, où une clé serait acceptée pour l'autre film, est soit corrigée, soit le signe que le barème est trop large ; les lignes pivots mesurent le résidu de force brute du § 13.2 et ne se corrigent que par le barème. Si le barème change, c'est la version 2 (§ 12). La même procédure est rejouée **à l'ajout d'une langue** (05 § Ajouter une troisième langue, étape 6).

### 13.2 Budget de force brute et sonde

Le couple (`attemptsPerRound`, taille du vivier) est un **budget de force brute** avant d'être une ergonomie (10 § 15) : un client scripté qui connaît le catalogue — appris en jouant, notamment en solo par « Voir la réponse » (10 § 7.10) — peut soumettre des titres sans regarder l'image, au palier 1, le mieux payé. La garde exacte et les chiffres stricts empêchent qu'un titre réel vaille pour un autre film ; la tolérance n'élargit donc ce budget qu'**à la marge**, comme 10 § 15 le nomme : une chaîne forgée à distance au plus la tolérance de deux clés proches de films distincts, sans être égale à aucune, passe (c) et couvre les deux en (d). Ce sont les couples pivots du rapport de collisions (§ 13.1).

**Ordre de grandeur, au réglage par défaut** (`D` = 30 s, `N` = 3, `d₁` = 10 s, `attemptsPerSecond` = 1, `attemptsPerRound` = 15) : au plus environ `d₁ × attemptsPerSecond` = 10 tentatives au palier 1 et 15 dans la manche. Face à l'ensemble `C` des candidats — le vivier du salon (thèmes, `N`) **moins la non-répétition**, c'est-à-dire les films que le salon n'a pas joués dans sa fenêtre de mémoire, moins les manches déjà démarrées de la partie, tous connus d'un script qui a joué les parties précédentes (10 § 15) —, la probabilité de trouver au palier 1 vaut environ `10 / |C|`. À la **première** partie d'un salon au J1, `|C|` ≈ 60 (décision 3, D10 du 23/09), soit ≈ 1/6 par manche au palier 1 et ≈ 1/4 sur la manche (`15 / |C|`). Mais `|C|` décroît d'environ `M` à chaque partie : vers la sixième partie de dix manches (A-66, 30 § 4.5), `|C|` tombe près de `M` et la probabilité **approche 1**. Au preset Hardcore (45 s, Expert), le plafond vaut 23 et, à `N` = 5 quand il est jouable, `d₁` = 9 s. Facile n'a pas de texte, donc pas de budget. À 500 films, les chiffres d'une première partie tombent sous 3 %.

**Décision du J1 : accepter et sonder** (défaut retenu Q70-5 : « accepter et sonder au J1, recalibrer avant l'onglet Avancé »). Deux mesures au J1 : le test de charge (D33 du 23/09, 100 § 16), dont les joueurs simulés soumettent à l'aveugle des titres du catalogue publié et exercent ce budget ; et **la sonde, branchée au J1** : `BruteForceProbe` est livré par L70-11 et exposé par 100 en rapport hebdomadaire non alertant (L100-7, 100 § 15), pour que le recalibrage d'avant l'onglet Avancé lise des parties réelles et pas seulement des joueurs simulés. Aucun changement de règle : les salons sont privés, `noindex`, entre amis (principe 3), la cadence reste à 1/s et le plafond à `ceil(D / 2 s)` ≤ 30 faute d'onglet Avancé, et toute coupe de plafond sur 60 films frapperait d'abord les joueurs honnêtes. **Avant l'ouverture de l'onglet Avancé (J2)**, le couple est recalibré avec 50 sur le vivier et la sonde réels : porter la cadence à 5/s quintuplerait le budget du palier 1, soit une quasi-certitude à 60 films. **La lecture de la sonde (L70-11) et le recalibrage (L70-13) portent sur la taille du vivier du salon, jamais sur celle du catalogue.**

**Sonde** (J1, L70-11) : `App\Support\Answers\BruteForceProbe` [nouveau, nom de 70] expose une requête — manches gagnées au palier 1, en texte libre, après plus de `K` tentatives fausses —, appelable par le job `App\Jobs\Ops\ReportBruteForce` de 100 avec `K` et le début de la fenêtre en paramètres (`:k`, `:since`) :

```sql
SELECT COUNT(*) FROM guess g
JOIN round r ON r.id = g.round_id AND r.status <> 'cancelled'
JOIN round_player rp ON rp.round_id = g.round_id AND rp.player_id = g.player_id
WHERE g.tier_index = 1 AND g.source = 'text' AND rp.wrong_attempts > :k AND r.started_at >= :since
```

- `K` est le seuil de la configuration des sondes de 100, **jamais noté `N`**, qui désigne `frames_per_round`. La jointure `round` et l'exclusion de `cancelled` appliquent L1.
- Aucune colonne ni aucun index nouveau : `guess_round_player_uq` et `round_player_round_player_uq` servent les jointures (10 § 15). 100 l'expose dès le J1 en **rapport hebdomadaire non alertant** (L100-7, 100 § 15) et fixe `K` et la fenêtre dans sa configuration des sondes.

---

## 14. Anti-triche : ce qui est fermé, ce qui est assumé

| Canal | État | Mécanisme |
|---|---|---|
| Bonne réponse dans une charge avant la révélation | **fermé** | aucune réponse HTTP ne porte de titre ni de `match_kind` ; `player.locked` ne porte que `publicId` et `lockRank` ; les quatre chaînes du QCM ne portent ni index ni drapeau (règle 3, § 7.7, § 10.8) |
| Oracle de proximité (« presque ») | **fermé** | refus au corps identique, texte neutre (§ 7.7) |
| Oracle de préfixe par le corps | **fermé** | refus ambigu = refus franc (décision 13) |
| Oracle de préfixe au chronomètre | **fermé** | deux lectures et toutes les distances à chaque soumission, une seule écriture par refus, aucun cache (L4, § 7.4) |
| Nombre d'épisodes d'une saga dans le tirage | **fermé** | ambiguïté mesurée sur le catalogue publié, jamais sur le vivier (§ 4.2) |
| Singularité linguistique du QCM | **fermé** | même profil de titre, bascule collective sur `title_original`, même forme de titre original (§ 10.3) |
| Leurre trahissant le tirage | **fermé** | jamais exclure une manche future ; non-répétition conservée (§ 10.2) |
| Intersection de deux tirages de QCM | **fermé** | composé une fois, rejoué à l'identique ; mêmes quatre films pour tout le salon (§ 10.8) |
| Ordre du QCM comparé entre écrans | **fermé** | permutation par siège dérivée de la PRF (§ 10.8) |
| Palier acheté par le client | **fermé** | instant serveur, grâce serveur, plancher du QCM (L3, § 9.2) |
| Réponse soumise après lecture des titres | **fermé** | titres à `ended_at + tier_grace_ms`, fenêtre fermée au même instant (§ 7.3) |
| Épuisement de budget par un tiers | **fermé** | limiteur clé sur le siège résolu, jamais sur le `public_id` (§ 8) |
| **Plusieurs sièges d'une même personne** | **assumé** | « essai unique » et plafonds valent **par siège** ; les deux sièges reçoivent les mêmes quatre propositions, donc un tricheur solitaire obtient quatre essais au QCM et double son budget texte ; aucun remède sans donnée personnelle (10 § 7.1) ; écrit aussi dans 60 |
| **Recopie des propositions en texte libre (Normal, après `T_N`)** | **assumé**, signalé au porteur | un siège qui garde au moins quatre tentatives de texte peut taper les quatre chaînes du QCM et se verrouiller à coup sûr au palier `N` ; l'essai unique ne vaut que pour le clic (§ 2) ; une règle de fermeture reste une option du porteur (§ Ce que cette spec ne décide pas) |
| **Force brute sur un catalogue appris** | **assumé au J1**, mesuré par le test de charge et sondé dès le J1 (L70-11, rapport hebdomadaire de 100) | § 13.2 ; probabilité croissante avec l'usure de la mémoire du salon ; couples pivots de la tolérance (§ 13.1) ; recalibrage avant l'onglet Avancé |
| **Partie jugée sous une version plus récente de la règle** | **assumé** | déploiement non atomique : si `deploy:guard` échoue dans le hook, les fichiers sont déjà remplacés (D31 du 23/09, contrat C18-bis § 4, § 12) ; remède : `deploy:drain`, puis les actions Plesk |
| **Leurre de complément hors thème** | **assumé** | quand le vivier du salon manque de films au même profil, un leurre tiré du catalogue (R2) peut être hors thème, donc éliminable d'un coup d'œil ; la plausibilité se dégrade, jamais la règle 3 |
| **Langue d'origine devinable en mode dégradé** | **assumé** | deux titres de même forme peuvent trahir des langues d'origine différentes (romaji contre pinyin) ; la forme est tenue, pas la langue |
| **Largesse des clés dérivées** | **assumé** | § 4.2 |
| **Empreinte des octets d'image apprise en solo** | hors 70 | résidu nommé par 60 (D16 du 23/09) |

---

## 15. Mode solo

Même chemin, mêmes plafonds, même limiteur, même règle d'acceptation : une règle propre au solo n'aurait aucune raison produit, et le solo est l'entraînement du jeu réel (10 § 7.10). Trois différences, toutes portées par 60 :

- **aucune diffusion** : ni `player.locked`, ni `seat.choices` ; le verdict passe par la réponse HTTP, le QCM par `solo.state` (contrat C7 § 4.12) ;
- les gestes « Voir la réponse » et « Passer la manche » écrivent `revealed` et `skipped` et **ne créent jamais de `guess`** (D18 du 23/09) ; après eux, les deux routes de 70 répondent 409 ;
- les leurres sont tirés dans le vivier catalogue du preset (thèmes vides, `N` éventuellement ramené par D19 du 23/09), sans clause de salon, `PoolScope::forDecoys()` reconstruisant le périmètre depuis `game.settings_snapshot` (contrat C2).

---

## 16. Front : contrat de données de la saisie

La présentation appartient à 90 ; 70 fixe les données, les états et les comportements qui touchent à la règle. Tous les fichiers sont dans le périmètre `WATCHED` (contrat C16 § 2.11) : aucune couleur littérale, aucune taille en `px`, aucune variante `dark:`.

- **`resources/js/types/answers.ts`** [nouveau, livré par L70-4], réexporté par `resources/js/types/index.ts`, **seule déclaration** de ces types côté client (R-27). Il ne contient que des déclarations, **sans aucun import**, et naît dans le premier lot de 70 sans dépendance : `resources/js/types/game-wire.ts` de 60 l'importe dès sa création (60 § 11.4), et un lot qui l'importerait avant qu'il existe échouerait à `tsc` :

```ts
export type InputState = 'open' | 'text_exhausted' | 'attempts_exhausted' | 'locked' | 'qcm_wrong' | 'revealed' | 'skipped';
export interface ChoicesPayload { choices: [string, string, string, string]; useOriginalTitle: boolean; lang: string | null }
export interface SeatInputView {
  inputState: InputState; attemptsLeft: number; choices: ChoicesPayload | null;
  locked: { lockRank: number; tierIndex: number; pointsTier: number; pointsBonus: number; pointsTotal: number } | null;
}
export type SubmissionResult =
  | { result: 'accepted'; inputState: 'locked'; lockRank: number; tierIndex: number; pointsTier: number; pointsBonus: number; pointsTotal: number }
  | { result: 'rejected'; inputState: InputState; attemptsLeft: number }
  | { result: 'closed'; inputState: InputState; message: string };
```

- **`App\ValueObjects\Answers\SeatInputView`** [nouveau, `final readonly`, livré par L70-9, qui dispose à la fois de `guess` (L70-6) et de `ChoicesPresenter` (L70-8)] : `static forSeat(RoundPlayer $roundPlayer): self`, `toArray(): array` ; intégrée par 60 à la resynchronisation comme `SelfState.input` (contrat C7), destinataire unique. `attemptsLeft` suit la formule du § 7.7 ; en Facile, où il n'y a pas de texte, le client ne l'affiche pas. `choices` = `ChoicesPresenter::forSeat()`. `locked` = `TierScore::toArray()` plus `lockRank`, lus sur la ligne `guess` du siège.
- **`resources/js/hooks/game/use-answer-submission.ts`** [nouveau] : `useHttp()` et les actions Wayfinder `@/actions/App/Http/Controllers/Game/AnswerController` et `ChoiceController`, jamais une URL en dur ; rend `{ submitText(text: string), submitChoice(choice: string), pending: boolean, last: SubmissionResult | null }`. Il présente l'en-tête `X-Seat-Token` fourni par le magasin de 60. Il ne normalise rien, ne compare rien et ne décide rien : le client ne juge pas (règle 1). Une erreur 429, 422 ou réseau laisse `last` inchangé et expose son message ; un 409 `seat_superseded` bascule la page en lecture seule (60, 90).
- **`resources/js/components/game/answer-input.tsx`** [nouveau] : props `{ state: InputState; attemptsLeft: number; maxLength: number; pending: boolean; onSubmit: (text: string) => void }`. Champ actif si et seulement si `state === 'open'` ; `Entrée` soumet ; soumission désactivée pendant `pending` ; aucune fermeture décidée par un minuteur client (règle 8). Le message d'erreur 422 est lié au champ par `aria-describedby`.
- **`resources/js/components/game/choice-grid.tsx`** [nouveau] : props `{ payload: ChoicesPayload; disabled: boolean; onChoose: (choice: string) => void }`, grille 2×2 (`grid grid-cols-2`, cibles `min-h-11 min-w-11`) ; le conteneur porte `lang={payload.lang ?? undefined}`. Aucune couleur, aucun ordre, aucune marque qui dépende d'autre chose que la charge : l'ordre affiché est celui reçu. `onChoose` renvoie la chaîne telle que reçue.
- **Table des messages d'état** : `resources/js/lib/game/input-state-keys.ts` [nouveau, nom de 70] exporte `Record<InputState, TranslationKey | null>`, sur le patron de `lib/admin-enum-keys.ts` ; jamais une clé composée par gabarit (contrat C15 § 2.7). `open` → NULL ; `text_exhausted` → `game.answer.text_exhausted` ; `attempts_exhausted` → `game.answer.exhausted` ; `locked` → `game.answer.locked` ; `qcm_wrong` → `game.choices.wrong` ; `revealed` et `skipped` → `game.answer.closed`.
- **Focus et annonces** (contrat C16 § 2.12) : à l'ouverture de manche, focus sur le champ, ou sur le premier bouton du QCM en Facile ; l'apparition du QCM en Normal, y compris pour un siège `text_exhausted`, est annoncée par `game.a11y.choices_shown` **sans déplacer le focus**.
- **États à rendre** : envoi en cours, refus, trop rapide, saisie close, supplanté, déconnexion (`ConnectionBanner` de 90). Le composant ne lit ni Echo ni horloge : il reçoit des props (contrat C16 § 2.9).
- **`maxLength`** vient de `settings_snapshot.maxAnswerLength`. **Exigence adressée à 60, signalée au porteur (écart au contrat C7)** : `GameStatePacket` porte `maxAnswerLength: number | null` (NULL hors partie), seule source de la prop, en salon comme en solo ; `answer-input.tsx` n'est rendu que pendant une manche, où la valeur est non nulle, ce qui garde la prop `maxLength: number` à la lettre du contrat C10. Aucun littéral de repli côté client (règle 2) ; la borne reste serveur (422), l'attribut n'est qu'un confort.
- **Message d'un 409** : quand l'`inputState` renvoyé vaut `text_exhausted`, la page affiche `game.answer.text_exhausted` (table des messages d'état), jamais le `message` du corps (§ 7.7).

---

## 17. Clés de traduction

Domaines `game` et `validation`, symétriques FR et EN, placeholders contractuels (contrat C15). 70 est le rédacteur de `game.answer.*`, `game.choices.*`, `validation.attributes.answer` et `validation.attributes.choice`, et fournit le texte de `game.help.prefix` (écran de 90).

| Clé | FR | EN |
|---|---|---|
| `game.answer.label` | Votre réponse | Your answer |
| `game.answer.placeholder` | Titre du film | Film title |
| `game.answer.submit` | Valider | Submit |
| `game.answer.rejected` | Ce n'est pas ça. | That's not it. |
| `game.answer.attempts_left` (`tChoice`, `:count`) | `{0} Plus aucune tentative\|{1} :count tentative restante\|[2,*] :count tentatives restantes` | `{0} No attempts left\|{1} :count attempt left\|[2,*] :count attempts left` |
| `game.answer.too_fast` | Une tentative à la fois. | One attempt at a time. |
| `game.answer.closed` | La saisie est close pour cette manche. | Answers are closed for this round. |
| `game.answer.unreadable` | Tapez au moins une lettre ou un chiffre. | Type at least one letter or digit. |
| `game.answer.exhausted` | Vous avez utilisé toutes vos tentatives pour cette manche. | You have used all your attempts for this round. |
| `game.answer.text_exhausted` | Plus de tentatives en texte libre : les propositions arrivent avec la dernière image. | No free-text attempts left: the choices will appear with the last frame. |
| `game.answer.locked` | Trouvé ! | Found it! |
| `game.choices.label` | Propositions | Choices |
| `game.choices.wrong` | Mauvaise proposition : la saisie est close pour cette manche. | Wrong choice: answers are closed for this round. |
| `game.choices.invalid` | Cette proposition n'existe pas. | This choice does not exist. |
| `game.help.prefix` | Quand plusieurs épisodes d'une saga sont jouables, le titre de la saga seul, ou un sous-titre partagé, ne suffit pas. | When several episodes of a saga are playable, the saga title alone, or a shared subtitle, is not enough. |
| `validation.attributes.answer` | réponse | answer |
| `validation.attributes.choice` | proposition | choice |

- **Aucun texte de refus ne suggère la proximité** (décision 13) : c'est la seule contrainte de fond sur ces textes, vérifiée en revue.
- `game.answer.rejected`, `attempts_left` et les messages d'état sont rendus **par le client** à partir des données de la réponse ; `game.answer.closed` et `game.answer.too_fast` sont résolus **par le serveur** dans la locale de la requête (destinataire unique, 05) — pour `too_fast`, seulement parce que `SetLocale` est inscrit avant `ThrottleRequests` dans la liste de priorité (§ 8), le corps 429 étant construit à l'intérieur du limiteur ; les 422 passent par le sac d'erreurs de validation.
- `game.help.prefix` reprend le texte de `questions-ouvertes.md` § Déjà tranché, étendu au sous-titre (A-63).

---

## 18. Arbitrages de rédaction

Décisions prises par cette spec dans l'espace que les contrats lui laissent libre ; aucune ne rouvre une décision.

1. **`CatchUpGame` est appelé avec `$receivedAt`** (§ 7.4) : le rattrapage exécute les transitions échues à l'instant de réception, jamais au-delà, pour que la fenêtre (§ 7.3) et le rattrapage lisent le même instant.
2. **Toutes les distances avant les filtres** (§ 6.1) : les clés écartées par les chiffres ou par `is_ambiguous` sont mesurées quand même, pour que le travail ne dépende ni des chiffres ni de l'ambiguïté.
3. **Relecture après l'instruction de refus** (§ 7.5) : une lecture par clé primaire, toujours exécutée, donne une réponse exacte sous concurrence ; l'émission éventuellement doublée de `InputClosed` est absorbée par les écouteurs idempotents de 60.
4. **Chaînes du QCM nettoyées de leurs bords par `Str::trim()`** (§ 10.5), la fonction du middleware global `TrimStrings`, jamais par `trim()` de PHP, qui garde les espaces Unicode et les caractères invisibles.
5. **`NEAR_MISS_MARGIN` déclarée au J1** (§ 11), dans l'empreinte v1, pour que l'arrivée de la mécanique au J2 ne change pas la version.
6. **La validation n'est jamais rejouée** (§ 12) ; seule la version courante est implémentée, le drainage (D32 du 23/09) garantissant, hors du résidu du déploiement non atomique (D31 du 23/09), qu'aucune partie ne chevauche deux versions.
7. **Critère du rapport de collisions** : distance au plus `tolerance(kA) + tolerance(kB)`, chiffres identiques, les couples directs (au plus `max(tolerance(kA), tolerance(kB))`) se distinguant des couples pivots (§ 13.1).
8. **Départage en (d)** : plus petite distance d'abord, puis nature, puis identifiant (§ 6.2).
9. **Instant d'évaluation = lectures de S6**, jamais réévalué dans `LockGuess` (§ 6.5).
10. **Sans partie courante** : la règle `max` se replie sur `RoomSettingsBounds::MAX_ANSWER_LENGTH`, la cadence sur `RoomSettingsBounds::DEFAULT_ATTEMPTS_PER_SECOND`, et le contrôleur répond 409 sans appeler l'action (§ 7.1, § 8).
11. **Noms internes de 70** : `App\Support\Answers\BruteForceProbe`, `resources/js/lib/game/input-state-keys.ts`, état de fabrique `AnswerKeyFactory::subtitle()`, constantes `AnswerRules::ROMAN_RULE`, `DIGITS_RULE`, `SUBTITLE_RULE`, `TRANSLITERATION`, fichier `tests/Fixtures/answers/fingerprints.php`.
12. **Textes FR et EN** des clés (§ 17).
13. **Ordre réel des middlewares** (§ 8) : `SetLocale` puis `EnsureActiveSeat` inscrits avant `ThrottleRequests` dans la liste de priorité ; S1 précède S0 (§ 7.4). Écart à l'ordre de la lettre du contrat C10 § 4, signalé au porteur et adressé à 60 et 05.
14. **État du QCM relu dans la transaction du refus** (§ 7.5) : `round FOR SHARE` puis `round_tier(N).served_at`, au lieu de `receivedAt ≥ T_N` lu en S3. Écart au contrat C10 § 4, signalé au porteur.
15. **Composition en deux phases** (§ 10.7) : calcul sans écriture, dont l'échec vaut cas terminal, puis écriture, dont l'échec remonte.
16. **Cas défensif de `ChoicesPresenter`** (§ 10.8) : déclenché par `decoy_movie_id_1` non nul, jamais par `choices_composed_at`, qu'il pose.
17. **Signal du cas terminal en Normal** (§ 10.7) : resynchronisation du siège faute de `seat.choices` dans les `heartbeatIntervalMs` suivant `tier.opened` du palier du QCM ; exigence à 60.

---

## Exigences adressées à 10

70 ne modifie jamais `10` ; il consomme ces exigences consolidées, que le rédacteur de `10` inscrit ou refuse en le signalant. **Aucune exigence nouvelle.**

- **E10-03** — colonne `round_choice_set.rendered_locale` `string(5)` nullable : rejeu identique de l'attribut `lang` du QCM (§ 10.5) ; migration additive portée par L70-8.
- **E10-06** — cas `RoundPlayerInputState::TextExhausted`, sans migration ; `isClosed()` faux pour lui ; 10 A16 : inatteignable hors Normal (§ 3).
- **E10-07** — cas `RoundIncidentReason::ChoicesUnavailable`, sans migration : annulation d'une manche Facile sans QCM (§ 10.7).
- **E10-09** — cas `AnswerKeyKind::Subtitle` et `GuessMatchKind::Subtitle`, sans migration ; liste close de 10 § 3.5 amendée (§ 4.1, § 5.7).
- **E10-12** — 10 § 1.3 : « aucune chaîne n'excède la colonne » (§ 5.5).
- **E10-15** — leurres tirés à la première composition, contextes `decoys` et `decoysOriginal` (§ 10.1).
- **E10-16** — `answer_key` relue à chaque soumission, aucun cache au J1 (§ 6.1).
- **E10-24** — garde de devinabilité : au moins une clé exacte non vide pour publier (§ 4.5).
- **E10-32** — `nickname_normalized` bâti sur `fold()` par 40, jamais sur `normalize()` (§ 5.9).
- **E10-39** — contenu de `validation_version` ; pas le plancher du QCM (§ 12).
- **E10-41** — `validation_version` parmi les colonnes figées de `game` (émetteur 50 ; consommée au § 12).
- **E10-48** — la fenêtre d'acceptation est écrite dans 70, le plancher dans 80 ; le rejeu lit `guess.source`, `input_difficulty`, `frames_per_round` (§ 7.3, § 9.2).
- **E10-49** — `round_player` créée à `T₁` (émetteur 60 ; consommée par l'ordre de composition du § 10.1).
- **E10-50** — sens de `guess.prefix_was_ambiguous` (§ 6.4).
- **E10-51** — la transaction relit `round_player FOR UPDATE` après `round FOR UPDATE`, ne verrouille jamais `game` (§ 9.1).
- **E10-53** — prédicat de fin anticipée `NOT IN ('open','text_exhausted')`, borne `COUNT ≥ 1` conservée (§ 3.3).
- **E10-54** — ordre du QCM par `qcmOrder(sequence_index, public_id)` (§ 10.8).
- **E10-57** — L4 : une écriture par refus ou aucune, jamais conditionnelle ; test sous `Queue::fake()` ; « aucune insertion » (§ 7.4).
- **E10-63** — deux lignes de rétention pour le tampon et le compteur de quasi-justes, J2 (§ 11).
- **E10-67** — 10 § 15 : normalisation, tolérance et quasi-juste « écrits dans 70 ».

---

## Amendements à d'autres documents

70 ne modifie ni `00`, ni `05`, ni `questions-ouvertes.md`, ni `CLAUDE.md`, ni `REPRISE.md` : l'application de ces amendements consolidés est un travail séparé, postérieur.

- **A-04** — 00 l.27 : fin anticipée quand « tous les participants ont leur saisie close », `text_exhausted` n'étant pas close.
- **A-05** — 00 l.50 : « essai unique par siège » ; en Normal, épuiser le texte laisse le QCM ouvert, poussé à `T_N`.
- **A-06** — 00 l.52 : leurres tirés à la première composition, échelle et exclusions de D21 du 23/09.
- **A-07** — 00 l.54 et l.56 : « seigneur des anneaux 2 » si l'alias existe ; règle du sous-titre ; chiffres jamais tolérés.
- **A-08** — 00 l.58 : « 1 tentative par seconde et par siège ».
- **A-21** — 00 l.172 et principe 4 : la poignée de main d'horloge sert l'affichage ; `tier_grace_ms` est une constante serveur appliquée à la réception.
- **A-41** — 05 l.123 : un seul `lang` pour les quatre chaînes, la locale effective atteinte.
- **A-43** — 05 l.171 : ne garder que « persistés à la première composition ».
- **A-44** — 05 l.179 : la charge ciblée porte les quatre chaînes, le seul drapeau `choices_use_original_title` et `lang`.
- **A-46** — 05 l.189 : exemples reformulés (« si l'alias existe », sous-titre).
- **A-47** — 05 l.199 et l.294 : calibrage multilingue par `answers:collisions`, rejoué à l'ajout d'une langue.
- **A-58** — `questions-ouvertes.md` l.303 : « titres non latins toujours acceptés » devient vrai par `Str::transliterate`.
- **A-61** — `questions-ouvertes.md` l.321 : essai unique par siège ; QCM ouvert après épuisement en Normal.
- **A-63** — `questions-ouvertes.md` l.331 : texte d'aide étendu au sous-titre (partie de 70).
- **A-70** — `CLAUDE.md` §2 : « saisie close », `text_exhausted`, sous-titre soumis à collision, chiffres jamais tolérés.
- **A-72** — `CLAUDE.md` §4 : commandes `answers:collisions` et `catalog:reproject`.
- **A-74** — `CLAUDE.md` §6 : lexique « texte épuisé, QCM attendu » → `text_exhausted`.
- **A-75** — `CLAUDE.md` §7 : règle 3, QCM poussé « y compris au siège dont le texte libre est épuisé » ; règle 7, « saisie close ».
- **A-77** — `CLAUDE.md` §9 : la dette « normalisation des réponses écrite nulle part » est levée.

**Alignements de code**, portés par les lots et non par un amendement : docblocks d'`AnswerKeyNormalizer` (retrait de « provisoire » et de la mention `Str::ascii()`), d'`AnswerKeyProjector` (retrait du cache L2), de `RoundFactory::withDecoys()` ; `GuessFactory::viaPrefix()` perd l'état « préfixe accepté et ambigu » et `forAnswerKey()` autorise `submitted_normalized ≠ clé` quand `edit_distance > 0` et délègue à `toMatchKind()` ; `GameFactory::VALIDATION_VERSION` supprimée ; `ImportFilterTest` l.214 ; `DemoCatalogueChainTest` FAIT 9 étendu au sous-titre ; scope `RoundPlayer::open()`.

**Exigences entre specs, signalées au porteur** (aucune ne modifie un autre document ; chacune attend l'accord de sa spec propriétaire) :

- **60** — `EnsureActiveSeat` inscrit avant `ThrottleRequests` dans la liste de priorité, lisant les paramètres de route bruts (§ 8 ; écart à l'ordre S0/S1 du contrat C10 § 4) ; `GameStatePacket.maxAnswerLength: number | null` (§ 16 ; écart au contrat C7) ; déclencheur de resynchronisation quand `seat.choices` manque après `tier.opened` du palier du QCM (§ 10.7). **Ordre des lots, pour un graphe sans cycle** : `resources/js/types/answers.ts` est livré par L70-4, dont L60-2 (création de `game-wire.ts`, qui l'importe) dépend donc ; `SeatInputView` est livré par L70-9, et non par L70-6, dont L60-12 dépend à ce titre ; L70-5, L70-6 et L70-8 ne dépendent pas de L60-11, qui les consomme — `served_at(N)` écrit dans la transaction de composition, écouteurs d'`AnswerAccepted` et d'`InputClosed`, appel de `ComposeChoiceSets` dans `OpenTier`, envoi de `seat.choices` et annulation en Facile relèvent de L60-11, le déclencheur du cas terminal du magasin de L60-9 ; `EnsureActiveSeat` est attendu de L60-4.
- **05** — `SetLocale` inscrit avant `ThrottleRequests` dans la liste de priorité (§ 8).
- **100** — `catalog:reproject` avant `deploy:release` dans le hook (§ 12), déjà satisfaite par 100 § 11.5.

---

## Lots d'implémentation

Chaque lot est livrable et testable seul ; ses heures comprennent la barre « terminé » (tests, FR et EN, états de chargement, d'erreur et de déconnexion, clavier) et le facteur 1,5 à 2. Les dépendances externes citent le contrat livré par l'autre spec, dont le lot porte le nom qu'elle lui donnera. Ces heures sont des mesures de taille, jamais un calendrier ni un budget à tenir (D36 du 23/09 : le développement est confié à l'IA). — amendé le 23/09

| Lot | Jalon | Objet | Dépend de | Heures |
|---|---|---|---|---|
| L70-1 | J1 | Normaliseur v1, primitives, `AnswerRules`, fixtures et empreinte | — | 4-6 |
| L70-2 | J1 | Sous-titre dérivé et projection (D23 du 23/09) | L70-1 | 3-4 |
| L70-3 | J1 | Appariement (`AnswerMatcher`) | L70-1, L70-2 | 3-5 |
| L70-4 | J1 | États de saisie (D20 du 23/09) et types du client | — | 2,5-3,5 |
| L70-5 | J1 | Soumission texte : route, fenêtre, refus à travail constant | L70-3, L70-4 ; 60 (L60-2, L60-4, L60-7) ; 50 (C6) | 4,5-6 |
| L70-14 | J1 | Limiteur `answer` et ordre des middlewares | L70-5 ; 60 (`EnsureActiveSeat`, L60-4) ; 05 (`SetLocale`) | 2-2,5 |
| L70-6 | J1 | Transaction de verrouillage | L70-5 ; 80 (C13) ; 60 (L60-7 au plus) | 3,5-5,5 |
| L70-7 | J1 | Résolveur de titre et tirage des leurres (D21 du 23/09) | 30 (C2, C3) | 4-6 |
| L70-8 | J1 | Composition, présentation, cas terminal, migration `rendered_locale` | L70-4, L70-7 ; aucun lot de 60 | 5-7 |
| L70-9 | J1 | Clic QCM et vue de saisie du siège | L70-6, L70-8 | 3,5-4,5 |
| L70-10 | J1 | Écran de saisie et grille QCM (front) | L70-5, L70-14, L70-9 ; 90 (C16) ; 60 (C7) ; 100 (L100-3) | 4,5-6,5 |
| L70-11 | J1 | Rapport de collisions et sonde de force brute | L70-3 | 3-4 |
| L70-12 | J2 | Alimentation de `near_miss` (D24 du 23/09) | L70-5 ; 60 ; 100 ; 20 | 4-6 |
| L70-13 | J2 | Recalibrage avant l'onglet Avancé | L70-11 ; 50 ; 100 (L100-7) | 2-3 |

### L70-1 — Normaliseur v1, primitives et version de règle

- **Jalon** : J1. **Dépendances** : aucune dans le code ; 100 livre `catalog:reproject` (contrat C18-bis) avant le premier import de production ou dans le même déploiement ; 40 [J1] consomme `fold` (contrat C5).
- **Fichiers** : `app/Support/Catalog/AnswerKeyNormalizer.php` [modifié : `normalize` par `Str::transliterate` et chiffres romains, `fold`, `subtitleOf` (corps seul, branché en L70-2), `compact`, `digits`, `distance`, `leadingArticles`, docblock] ; `app/Support/Answers/AnswerRules.php` [nouveau : constantes de version et chaînes de règle du § 12] ; `tests/Fixtures/answers/normalizer-v1.php` et `tests/Fixtures/answers/fingerprints.php` [nouveaux, ce dernier indexé par version] ; `database/factories/GameFactory.php` [modifié] ; `tests/Feature/Catalog/ImportFilterTest.php` [modifié, l.214].
- **Tests** :
  - `tests/Feature/Answer/NormalizerTest.php` : « les sorties figées de la version courante ne bougent pas » ; « translittère les écritures non latines et la pleine chasse sans ext-intl » ; « convertit les chiffres romains stricts de deux lettres et plus » ; « ne convertit un I, V ou X isolé qu'en dernier jeton » ; « ne retire qu'un article de tête et jamais le dernier mot » ; « tronque symétriquement les clés et les saisies à 200 caractères » ; « fold conserve articles, tirets et soulignés ».
  - `tests/Feature/Answer/ToleranceTest.php` : « garde stricts les titres de quatre caractères compacts ou moins » ; « mesure la distance aussi sur la forme compacte ».
  - `tests/Feature/Answer/ValidationVersionFingerprintTest.php` : « l'empreinte des paramètres de la version courante est figée » ; « seules l'empreinte et les fixtures de la version courante sont exécutées ».
- **Heures** : 4-6. **Variable d'ajustement** : aucune.

### L70-2 — Sous-titre dérivé et projection (D23 du 23/09)

- **Jalon** : J1. **Dépendances** : L70-1 ; reprojection par `catalog:reproject` (100) au déploiement.
- **Fichiers** : `app/Enums/AnswerKeyKind.php` [modifié : `Subtitle`, `isCollisionChecked()`, `toMatchKind()`, `isExact()`] ; `app/Enums/GuessMatchKind.php` [modifié : `Subtitle`] ; `app/Support/Catalog/AnswerKeyProjector.php` [modifié] ; `database/factories/AnswerKeyFactory.php` [modifié : état `subtitle()`] ; `database/factories/GuessFactory.php` [modifié] ; `tests/Feature/Schema/DemoCatalogueChainTest.php` [modifié : FAIT 9, aucun sous-titre issu d'un alias].
- **Tests** : `tests/Feature/Catalog/AnswerKeyProjectorTest.php` [nouveau] : « dérive le sous-titre des titres et jamais des alias » ; « recompte l'ambiguïté des sous-titres comme celle des préfixes » ; « une nature exacte l'emporte sur prefix, qui l'emporte sur subtitle ».
- **Heures** : 3-4. **Variable d'ajustement** : aucune.

### L70-3 — Appariement

- **Jalon** : J1. **Dépendances** : L70-1, L70-2.
- **Fichiers** : `app/Support/Answers/AnswerMatcher.php` [nouveau] ; `app/ValueObjects/Answers/MatchResult.php` [nouveau].
- **Tests** :
  - `tests/Feature/Answer/MatchPrecedenceTest.php` : « accepte toujours un titre complet homonyme d'un autre film publié » ; « refuse un préfixe porté par un autre film publié » ; « refuse une saisie égale à une clé d'un autre film publié même sous le seuil de tolérance » ; « évalue l'ambiguïté à l'instant de réception quand un film est publié en pleine manche ».
  - `tests/Feature/Answer/ToleranceTest.php` : « accepte une faute de frappe sous le barème » ; « refuse une suite de chiffres différente ».
  - `tests/Feature/Answer/SubtitleRuleTest.php` : « accepte un sous-titre non ambigu » ; « refuse un sous-titre porté par un autre film publié ».
- **Heures** : 3-5. **Variable d'ajustement** : aucune.

### L70-4 — États de saisie (D20 du 23/09) et types du client

- **Jalon** : J1. **Dépendances** : aucune ; 60 consomme les prédicats (contrat C7) et les types du client : `resources/js/types/game-wire.ts`, créé par L60-2, importe `@/types/answers` (60 § 11.4), qui doit donc exister avant lui.
- **Fichiers** : `app/Enums/RoundPlayerInputState.php` [modifié] ; `app/Models/RoundPlayer.php` [modifié : scope `open()`] ; `app/Models/Round.php` [docblock de `isEarlyEndReached()`] ; `resources/js/types/answers.ts` [nouveau : déclarations seules de `InputState`, `ChoicesPayload`, `SeatInputView` et `SubmissionResult` (§ 16), sans aucun import] ; `resources/js/types/index.ts` [modifié : réexportation de `./answers`].
- **Tests** :
  - `tests/Feature/Answer/InputStateTest.php` [nouveau] : « acceptsText n'est vrai que pour open » ; « acceptsChoice n'est vrai que pour open et text_exhausted » ; « isClosed est faux pour open et text_exhausted et vrai pour les cinq autres états » ; « le scope open retient les sièges open et text_exhausted ».
  - `tests/Feature/Answer/AttemptsTest.php` : « un siège text_exhausted ne compte pas comme saisie close pour la fin anticipée ».
  - Types du client : vérifiés par `npm run types:check` (CI) ; un fichier de déclarations n'a pas de comportement à tester sous Vitest, et la couverture des sept états par une clé de message reste dans L70-10.
- **Heures** : 2,5-3,5 (dont 0,5 h pour les types du client, venus de L70-10). **Variable d'ajustement** : aucune.

### L70-5 — Soumission texte : route, fenêtre, refus à travail constant

- **Jalon** : J1. **Dépendances** : L70-3, L70-4 ; 60 : L60-2 (`ReceptionInstant`, `RoundClock`), L60-4 (`seat.active` : siège et partie courante en mémoire), L60-7 (`CatchUpGame`) ; l'écriture de `served_at(N)` dans la transaction de composition (60 § 6.3, étapes 4 et 6) est livrée par L60-11, qui consomme ce lot : les tests de S8a posent `round_tier(N).served_at` et `round.decoy_movie_id_1` par fabriques (contrat C7) ; 50 : `OpenGame` écrit `validation_version` (contrat C6) ; 40 : `player_token` (contrat C4).
- **Fichiers** : `routes/game.php` [nouveau s'il n'existe pas, avec son `require` dans `routes/web.php`] ; `app/Http/Controllers/Game/AnswerController.php` (409 sans partie courante), `app/Http/Requests/Game/AnswerStoreRequest.php`, `app/Actions/Game/SubmitTextAnswer.php` (transaction de S8a : `round FOR SHARE`, `round_tier(N).served_at`, `UPDATE`), `app/ValueObjects/Answers/SubmissionVerdict.php`, `app/Enums/SubmissionOutcome.php`, `app/Events/Game/InputClosed.php` [nouveaux] ; `lang/{fr,en}/game.php` [modifié : `answer.*`, `help.prefix`] ; `lang/{fr,en}/validation.php` [modifié : attributs `answer`, `choice`].
- **Tests** :
  - `tests/Feature/Answer/AcceptanceWindowTest.php` : « accepte une réponse reçue à D + tier_grace_ms − 1 au dernier palier » ; « refuse comme manche close une réponse reçue à D + tier_grace_ms, sans la compter » ; « refuse une réponse sur une manche qui n'est pas running » ; « refuse une réponse dont la manche annoncée n'est pas la manche ouverte » ; « refuse une réponse reçue après ended_at + tier_grace_ms d'une manche close par fin anticipée » ; « répond saisie close sans appeler l'action quand le siège n'a pas de partie courante ».
  - `tests/Feature/Answer/AttemptsTest.php` : « chaque refus compte, même répété » ; « le plafond ferme le texte en Expert » ; « en Normal, après une composition terminale, l'épuisement du texte ferme la saisie en attempts_exhausted » ; « un épuisement reçu avant T_N et traité après une composition terminale ferme la saisie en attempts_exhausted » ; « en Facile la route texte répond saisie close sans compter » ; « une saisie vide après normalisation ou trop longue est refusée en 422 sans compter » ; « n'atteint jamais text_exhausted hors difficulté Normal » ; « wrong_attempts ne dépasse jamais attemptsPerRound » ; « après revealed ou skipped, la route texte répond 409 sans rien écrire ».
  - `tests/Feature/Answer/ConstantWorkRefusalTest.php` : « le refus d'une chaîne à distance 1 et celui d'une chaîne à distance 12 exécutent le même nombre de requêtes, dont exactement un UPDATE de round_player, et n'insèrent aucune ligne » (sous `Queue::fake()`) ; « un refus pour préfixe ambigu exécute le même nombre de requêtes qu'un refus franc ».
  - `tests/Feature/Answer/AnswerResponseTest.php` : « un refus a le même corps quelle que soit sa cause ».
  - `tests/Feature/Answer/ValidationVersionFingerprintTest.php` : « game.validation_version est écrite depuis AnswerRules::VERSION au lancement ».
- **Heures** : 4,5-6 (dont 1 h pour la transaction de S8a). **Variable d'ajustement** : aucune.

### L70-14 — Limiteur `answer` et ordre des middlewares

- **Jalon** : J1. **Dépendances** : L70-5 ; 60 : `EnsureActiveSeat` (L60-4) et son rang (§ 8) ; 05 : `SetLocale` et son rang (§ 8). Si leurs lots n'ont pas posé les deux inscriptions, ce lot les pose.
- **Fichiers** : `app/Providers/FortifyServiceProvider.php` [modifié : limiteur `answer`] ; `bootstrap/app.php` [modifié : `prependToPriorityList(ThrottleRequests::class, SetLocale::class)` puis `prependToPriorityList(ThrottleRequests::class, EnsureActiveSeat::class)`, si absents].
- **Tests** : `tests/Feature/Answer/AnswerThrottleTest.php` : « le limiteur answer est clé sur le siège, jamais sur l'IP » ; « une soumission trop rapide répond 429 traduit sans être comptée » ; « un tiers qui connaît le public_id d'un siège ne consomme pas son budget » ; « le limiteur answer voit le siège résolu par seat.active dans la pile réelle de middlewares » ; « le limiteur answer limite réellement une seconde soumission du même siège dans la seconde, middlewares du groupe web compris » ; « répond 429 dans la locale résolue de la requête et non dans APP_LOCALE ».
- **Heures** : 2-2,5 (dont 0,5 h pour l'ordre des middlewares). **Variable d'ajustement** : aucune.

### L70-6 — Transaction de verrouillage

- **Jalon** : J1. **Dépendances** : L70-5 ; 80 : `ScoreCalculator::forGuess`, `TierSchedule`, `TierScore` (contrat C13) ; 60 : L60-7 au plus, pour la signature figée de `SeatInputClosed` (contrat C7 § 2.5) ; les écouteurs d'`AnswerAccepted`, qui appellent `SeatInputClosed::handle()`, sont livrés par L60-11, qui dépend de ce lot (60 § 22 bis).
- **Fichiers** : `app/Actions/Game/LockGuess.php`, `app/Events/Game/AnswerAccepted.php` [nouveaux] ; `app/Actions/Game/SubmitTextAnswer.php` [modifié : branche d'acceptation].
- **Tests** :
  - `tests/Feature/Answer/LockGuessTest.php` : « input_state locked si et seulement si une ligne guess existe » ; « le guess porte l'instantané complet de la règle ».
  - `tests/Feature/Answer/MatchPrecedenceTest.php` : « ne recalcule jamais un guess existant ».
  - `tests/Feature/Answer/AnswerResponseTest.php` : « une acceptation ne contient ni titre ni nature d'appariement ».
  - `tests/Feature/Answer/ChoiceFloorTest.php` : « un texte reçu à T_N + 100 ms est crédité au palier N − 1 ».
  - `tests/Concurrency/Answer/LockTransactionTest.php` (groupe `locks-timing` par répertoire, MySQL) : « deux bonnes réponses simultanées reçoivent des lock_rank distincts sans erreur 1062 ».
- **Heures** : 3,5-5,5 (`SeatInputView` et son test passés à L70-9). **Variable d'ajustement** : aucune.

### L70-7 — Résolveur de titre et tirage des leurres (D21 du 23/09)

- **Jalon** : J1. **Dépendances** : 30 : `PoolQuery`, `PoolScope::forDecoys`, `SeededPrf`, `DrawContext::decoys` et `decoysOriginal` (contrats C2, C3). 60 consomme `DisplayTitleResolver` pour la révélation.
- **Fichiers** : `app/Support/I18n/DisplayTitleResolver.php`, `app/ValueObjects/I18n/ResolvedTitle.php`, `app/Enums/OriginalTitleForm.php`, `app/Support/Answers/DecoyPicker.php`, `app/ValueObjects/Answers/DecoyPick.php` [nouveaux].
- **Tests** :
  - `tests/Feature/Answer/DisplayTitleResolverTest.php` [nouveau] : « suit la locale du joueur, puis les autres locales activées par rang de repli, puis le titre original » ; « rend la translittération d'un titre original non latin quand elle existe » ; « ne rend jamais un alias » ; « classe la forme du titre original en latin, translittéré ou natif sans ext-intl ».
  - `tests/Feature/Answer/DecoyDrawTest.php` : « tire les leurres dans le vivier du salon au même profil de titre » ; « complète par le catalogue publié en conservant la non-répétition » ; « bascule tout le salon sur title_original à défaut de trois leurres au même profil » ; « en mode dégradé n'associe que des films de même forme de titre original » ; « exclut la cible, son movie_group et les films des manches déjà démarrées » ; « n'exclut jamais le film d'une manche future » ; « est déterministe pour une graine et un sequenceIndex donnés » ; « un masque de version périmée fait basculer en mode dégradé » ; « en solo, tire les leurres dans le vivier catalogue du preset, sans non-répétition de salon ».
- **Heures** : 4-6. **Variable d'ajustement** : aucune.

### L70-8 — Composition, présentation et cas terminal

- **Jalon** : J1. **Dépendances** : L70-4, L70-7 ; 10 : texte de § 7.8 amendé (E10-03) ; 60 : aucune — l'appel dans `OpenTier` (60 § 6.3, étape 4), l'envoi de `seat.choices` et l'annulation en Facile sont livrés par L60-11, qui consomme ce lot, et le déclencheur de resynchronisation du cas terminal (exigence du § 10.7) par le magasin de L60-9 ; les tests appellent `ComposeChoiceSets::handle()` et `ChoicesPresenter::forSeat()` directement (contrat C11).
- **Fichiers** : `database/migrations/2026_09_2x_xxxxxx_add_rendered_locale_to_round_choice_set_table.php` [nouveau : colonne `string(5)` nullable placée après `locale`, sans index, migration additive (E10-03)] ; `app/Actions/Game/ComposeChoiceSets.php` (calcul puis écriture, § 10.7), `app/Support/Answers/ChoicesPresenter.php`, `app/ValueObjects/Answers/ChoicesPayload.php` [nouveaux] ; `app/Enums/RoundIncidentReason.php` [modifié] ; `app/Models/RoundChoiceSet.php` [modifié : cast `Locale` nullable, `@property Locale|null $rendered_locale`, `#[Hidden]`] ; `database/factories/RoundChoiceSetFactory.php` [modifié] ; `database/factories/RoundFactory.php` [docblock].
- **Tests** :
  - `tests/Feature/Answer/ChoiceSetCompositionTest.php` : « compose une ligne par locale activée avec choice_1 égal au film cible » ; « les quatre chaînes sont deux à deux distinctes dans chaque locale » ; « pose choices_locale pour les sièges open et text_exhausted » ; « rejoue exactement les quatre mêmes chaînes après resynchronisation, changement de langue et second onglet » ; « la permutation dérive de draw:qcm:{sequenceIndex}:{publicId} et diffère entre deux sièges » ; « la charge ciblée ne contient que quatre chaînes, le drapeau et lang » ; « lang égale la locale effective atteinte et reste celle de la composition » ; « composer deux fois une manche ne crée aucune ligne de plus » ; « écrit des chaînes invariantes par Str::trim, y compris pour des titres qui finissent par U+00A0 ou commencent par U+200B » ; « le cas défensif de ChoicesPresenter pose choices_locale et choices_composed_at une seule fois quand la manche est composée ».
  - `tests/Feature/Answer/DecoyDrawTest.php` : « compose les mêmes leurres quand la transition est rattrapée en retard » ; « ne tire les leurres qu'à la première composition et jamais au lancement » ; « sans trois leurres possibles, ne compose rien et fait passer les sièges text_exhausted en attempts_exhausted » ; « une exception de tirage des leurres est traitée comme le cas terminal sans bloquer l'ouverture du palier ».
  - `tests/Feature/Schema/ModelSerializationTest.php` [existant] : `$choiceSet->toArray()` reste sans `choice_1..4` ; « ChoicesPayload ne porte aucun identifiant de film ».
- **Heures** : 5-7 (dont 0,5 h pour la migration et 0,5 h pour l'échec technique de la composition). **Variable d'ajustement** : aucune.

### L70-9 — Clic QCM et vue de saisie du siège

- **Jalon** : J1. **Dépendances** : L70-6 (`guess`, `TierScore`), L70-8 (`ChoicesPresenter`) ; consommé par L60-12, qui intègre `SeatInputView` au paquet de resynchronisation comme `SelfState.input`.
- **Fichiers** : `app/Http/Controllers/Game/ChoiceController.php`, `app/Http/Requests/Game/ChoiceStoreRequest.php`, `app/Actions/Game/SubmitChoice.php`, `app/ValueObjects/Answers/SeatInputView.php` [nouveaux : `forSeat()`, `toArray()`, `choices` = `ChoicesPresenter::forSeat()`, § 16] ; `routes/game.php` [modifié] ; `lang/{fr,en}/game.php` [modifié : `choices.*`].
- **Tests** :
  - `tests/Feature/Answer/ChoiceFloorTest.php` : « un clic QCM en Normal reçu à T_N + 100 ms est crédité au palier N » ; « un clic reçu avant l'ouverture du QCM est refusé comme saisie close » ; « le rejeu depuis guess.source et game.input_difficulty redonne tier_index et points_total ».
  - `tests/Feature/Answer/ChoiceSetCompositionTest.php` : « un clic est jugé par égalité stricte contre choice_1 sans lire answer_key » ; « une chaîne hors des quatre propositions est refusée en 422 sans fermer la saisie » ; « une proposition dont le titre source porte une espace insécable ou un caractère invisible en bord reste cliquable ».
  - `tests/Feature/Answer/AttemptsTest.php` : « le plafond en Normal passe en text_exhausted et le clic reste recevable à T_N » ; « après revealed ou skipped, la route clic répond 409 sans rien écrire ».
  - `tests/Concurrency/Answer/LockTransactionTest.php` : « un clic faux et une bonne réponse texte concurrents ne produisent jamais locked et qcm_wrong » ; « un clic faux concurrent d'un verrouillage répond saisie close sans émettre InputClosed ».
  - `tests/Feature/Answer/ChoiceSetCompositionTest.php` : « en Normal, après une composition terminale, SeatInputView::forSeat d'un siège anciennement text_exhausted rend attempts_exhausted sans propositions » (joué sur la vue ; sa lecture dans le paquet de resynchronisation appartient à 60, L60-12).
  - `tests/Feature/Schema/ModelSerializationTest.php` [existant] : « SeatInputView ne sort que les données du siège demandeur ».
- **Heures** : 3,5-4,5 (dont 0,5 h pour `SeatInputView`, venue de L70-6). **Variable d'ajustement** : aucune.

### L70-10 — Écran de saisie et grille QCM (front)

- **Jalon** : J1. **Dépendances** : L70-5, L70-14 et L70-9 (routes, donc helpers Wayfinder après `vp dev` ou `vp build`) ; 90 : `GameLayout`, `GameAnnouncer`, liste close, `WATCHED`, `radio-group` éventuel (contrat C16) ; 60 : magasin de jeu, `SelfState.input`, `seat.choices`, en-tête `X-Seat-Token`, `GameStatePacket.maxAnswerLength` (contrat C7, exigence du § 16) ; 100 : configuration de Vitest (L100-3, contrat C18 § 2.4 : bloc `test` de `vite.config.ts`, `tsconfig.json`, script `npm run test`).
- **Fichiers** : `resources/js/hooks/game/use-answer-submission.ts`, `resources/js/components/game/answer-input.tsx`, `resources/js/components/game/choice-grid.tsx`, `resources/js/lib/game/input-state-keys.ts` [nouveaux] ; `tests/Frontend/answer/input-state-keys.test.ts` [nouveau]. Les types de `resources/js/types/answers.ts` viennent de L70-4.
- **Tests** : `tests/Frontend/answer/input-state-keys.test.ts` : « associe une clé traduite ou aucune à chacun des sept états de saisie » ; couverture et symétrie des clés par `tests/Feature/I18n/TranslationCoverageTest.php` [existant, 05] ; périmètre `WATCHED` par le script de 90.
- **Terminé** : états envoi, refus, trop rapide, clos, supplanté, déconnecté rendus ; `Entrée` soumet ; focus et annonce du QCM selon le contrat C16 § 2.12 ; FR et EN complets ; cibles tactiles au moins `min-h-11`.
- **Heures** : 4,5-6,5 (les types du client passés à L70-4). **Variable d'ajustement** : aucune.

### L70-11 — Rapport de collisions et sonde de force brute

- **Jalon** : J1. **Dépendances** : L70-3 ; consommé par 100 : L100-7 (job `ReportBruteForce`, seuil `K`, rapport hebdomadaire non alertant), branché au J1 par le défaut retenu Q70-5. Le rapport de force brute de L100-7 vient **après** ce lot (D37 du 23/09) : le reste de L100-7, qui appartient au socle de production minimal, ne l'attend pas, et le lot pilote non plus. — amendé le 23/09
- **Fichiers** : `app/Console/Commands/AnswersCollisionsCommand.php`, `app/Support/Answers/BruteForceProbe.php` [nouveaux].
- **Tests** :
  - `tests/Feature/Answer/CollisionsCommandTest.php` : « liste les formes exactes partagées, alias TMDB compris, et les paires sous le seuil » ; « liste les couples pivots à distance au plus la somme des deux tolérances ».
  - `tests/Feature/Answer/BruteForceProbeTest.php` [nouveau] : « compte les manches gagnées au palier 1 en texte libre après plus de K tentatives fausses » ; « exclut les manches annulées ».
- **Terminé** comprend une exécution du rapport sur le catalogue publié et le calibrage du § 13.1 avant la clôture du J1.
- **Heures** : 3-4 (dont 1 h pour la sonde). **Variable d'ajustement** : le nombre de films du J1 (D10 du 23/09) change le volume du rapport, pas le lot.

### L70-12 — Alimentation de `near_miss` (D24 du 23/09)

- **Jalon** : J2. **Dépendances** : L70-5 ; 60 : crochet de clôture de manche ; 100 : configuration de rétention du tampon et du compteur (E10-63) ; 20 : écran de la file d'alias et promotion.
- **Fichiers** : `app/Actions/Game/SubmitTextAnswer.php` [modifié : écriture de tampon en S8a] ; `app/Jobs/Answers/AggregateNearMisses.php` [nouveau] ; `tests/Feature/Answer/ConstantWorkRefusalTest.php` [modifié : exactement une écriture de tampon par refus].
- **Tests** : `tests/Feature/Answer/NearMissTest.php` : « un refus fait exactement une écriture de tampon quelle que soit sa proximité » ; « aucune ligne near_miss avant le troisième couple manche-chaîne distinct » ; « le compteur ne porte aucun identifiant de manche ».
- **Heures** : 4-6. **Variable d'ajustement** : aucune.

### L70-13 — Recalibrage avant l'onglet Avancé

- **Jalon** : J2. **Dépendances** : L70-11 ; 50 (onglet Avancé, bornes) ; 100 : L100-7 (seuil `K`, rapport hebdomadaire non alertant de la sonde, tenu depuis le J1) ; parties réelles du J1 et résultats du test de charge (100 § 16).
- **Contenu** : rapports de la sonde (L70-11) et de collisions relus sur les données réelles, **mesurés sur la taille du vivier du salon** (§ 13.2) ; décision, avec 50, des bornes de `attemptsPerSecond` et `attemptsPerRound` ; si le barème de tolérance change, `AnswerRules::VERSION` 2, fixtures et empreinte v2, reprojection.
- **Fichiers** : si le barème change : `app/Support/Answers/AnswerRules.php` [modifié : `VERSION` = 2], `tests/Fixtures/answers/normalizer-v2.php` [nouveau] et l'entrée v2 de `tests/Fixtures/answers/fingerprints.php` [modifié] ; si les bornes changent : `app/Settings/RoomSettingsBounds.php` [modifié par 50].
- **Tests** : `tests/Feature/Answer/ValidationVersionFingerprintTest.php` complété d'une version si elle change ; matrice des réglages de 50 et 100 si les bornes changent.
- **Heures** : 2-3 (la sonde est livrée au J1 par L70-11). **Variable d'ajustement** : aucune.

**Total J1 : 42,5 à 60,5 h** (L70-1 à L70-11 et L70-14). **Total J2 : 6 à 9 h** (L70-12, L70-13). La seule compression déjà appliquée est le report de `near_miss` au J2 (D24 du 23/09) ; la sonde de force brute reste au J1 (défaut retenu Q70-5, confirmé par D35 du 23/09), branchée par 100 en L100-7, dont le rapport de force brute suit L70-11 (D37 du 23/09). Depuis D35 du 23/09 (J1 complet), les variables d'ajustement de développement (retardataires, recadreur minimal) sont sans objet ; le nombre de films du J1, réglé par le verdict du pilote (D10 du 23/09), relève de la curation et ne touche pas le périmètre de 70. — amendé le 23/09

---

## Ce que cette spec ne décide pas

| Sujet | Spec propriétaire |
|---|---|
| Colonnes, types, index, rétention (`rendered_locale`, cas d'enum, lignes de rétention du tampon et du compteur) | `10-catalogue-et-modele-de-donnees.md` — seul propriétaire du schéma |
| Chaîne de repli d'affichage, homogénéité linguistique comme règle, attribut `lang`, ajout d'une langue, `SetLocale` et son rang dans la liste de priorité (exigence du § 8) | `05-i18n-et-langues.md` |
| Écran d'alias, promotion d'une quasi-juste, avertissement nominatif étendu aux sous-titres, application de la garde de devinabilité à la publication, affichage du rapport de collisions (J2), seuil de suggestion des candidats `movie_group` | `20-back-office-curation.md` |
| Vivier, `PoolScope::forDecoys`, non-répétition, définition de « joué », PRF seedée et registre des contextes, vecteurs de référence de `decoysOriginal` et `qcmOrder` | `30-themes-vivier-et-tirage-des-variantes.md` |
| `NicknameNormalizer`, règle de pseudo, liste noire | `40-comptes-auth-sociale-et-avatars.md` [J1] |
| Défauts et bornes de `attemptsPerSecond`, `attemptsPerRound`, `maxAnswerLength` ; avertissement d'hôte sur `T_N` ; onglet Avancé ; borne basse de `maxAnswerLength` face aux titres longs (J2) | `50-salon-reglages-presets-et-lobby.md` |
| Canaux, événements, enveloppe, envoi ciblé de `seat.choices`, resynchronisation et ses déclencheurs (dont celui du cas terminal, exigence du § 10.7), `seat.active` et son rang avant `ThrottleRequests` (exigence du § 8), résolution de la partie courante du siège, `ReceptionInstant` et sa source (`CaptureReceptionInstant`, 60 § 2.2 ; attente en file de PHP-FPM mesurée au test de charge, choix à confirmer au relevé du VPS), `CatchUpGame`, transitions d'ouverture, de clôture et de révélation, réévaluation de la fin anticipée, mécanisme d'échec d'une transition, gestes solo, crochet de clôture pour `near_miss` (J2) | `60-moteur-de-partie-temps-reel-et-mode-solo.md` |
| Palier retenu, points, bonus, plancher de palier du QCM comme calcul, `scoring_version`, départage | `80-scoring-podium-et-fin-de-partie.md` |
| Mise en page de la saisie et de la grille, focus, annonces, écran d'aide, liste close des composants | `90-ecrans-etats-et-structure.md` |
| `catalog:reproject` et sa place dans le hook (avant `deploy:release`, exigence du § 12), place des tests et groupes, configuration de Vitest, sonde de force brute en rapport hebdomadaire du J1 (L100-7) et seuil `K`, test de charge (coût du chemin chaud à 240 soumissions par seconde au défaut ; mesure du budget de force brute au J1) | `100-qualite-tests-et-ci.md` |
| **Question ouverte, signalée au porteur** — `GameStatePacket.maxAnswerLength: number \| null`, demandé à 60 (§ 16) : écart au contrat C7 ; la borne reste serveur | `60`, avec accord du porteur |
| **Question ouverte, signalée au porteur** — écarts à la lettre du contrat C10 § 4 : ordre réel S1 puis S0 (§ 7.4, § 8) ; état du QCM relu dans la transaction du refus au lieu de `receivedAt ≥ T_N` (§ 7.5) | porteur, puis `60` |
| **Question ouverte, signalée au porteur** — fermer ou non la recopie des propositions en texte libre (Normal, après `T_N`) : option retenue par défaut, résidu assumé (§ 2, § 14) ; option de règle, « en Normal, après la composition, un refus texte dont la forme normalisée égale celle de l'une des quatre chaînes de `round_player.choices_locale` ferme la saisie en `qcm_wrong`, comme un clic faux », avec lecture inconditionnelle de `round_choice_set` après `T_N` pour tenir L4 | porteur, puis `70` |
| **Question ouverte** — recalibrage du couple (`attemptsPerRound`, vivier) et de la cadence avant l'onglet Avancé | `50` avec `70` (L70-13), au J2 |
| **Question ouverte** — ajustement éventuel du barème de tolérance après le rapport de collisions sur le catalogue réel (version 2) | `70`, avant la clôture du J1 |
| **Question ouverte** — noms des clés de cache, durées exactes et crochet d'agrégation de `near_miss` | `70` (L70-12) avec `60` et `100`, au J2 |
