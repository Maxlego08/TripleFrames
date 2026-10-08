# Salon, réglages, presets et lobby

Ce document **possède** le salon, de sa création à son archivage. Il possède aussi le **contrat `room_settings`** (contrat C0 : `RoomSettings`, `RoomSettingsBounds`, `PlatformLimits`, `config/game.php`), que `30`, `40`, `60`, `70`, `80`, `90` et `100` consomment, ainsi que la **transaction de lancement, « Rejouer » et la garde de drainage** (contrat C6). Il décide :
- les onglets Simple et Avancé, deux vues d'un seul objet de réglages ;
- le tableau complet des seize champs, les bornes croisées côté serveur et côté client, le découpage du temps, le barème et ses avertissements ;
- les presets du site et les configurations sauvegardées ;
- la création du salon, son code et son lien, l'entrée et la prise de siège ;
- le lobby temps réel en données, le vivier tel qu'il est affiché et la capacité ;
- les pouvoirs de l'hôte (expulsion, transfert), le lancement, « Rejouer », les retardataires ;
- les échéances du salon vues du joueur et les policies du salon.

Il ne possède **aucune table** : le schéma appartient à `10`, qui en est le seul propriétaire ; toute donnée citée ici renvoie à `10` ou à une exigence de la feuille de contrats. Il ne possède ni le calcul du vivier ni le tirage (`30`), ni la forme du `player_token` ni la règle de pseudo (`40` [J1]), ni les canaux, les événements et le moteur de partie (`60`), ni la validation des réponses (`70`), ni le score et le podium (`80`), ni la composition des écrans (`90`), ni le drainage et la CI (`100`).

**Conventions de renvoi, valables dans tout le document.**
- « règle N » désigne `CLAUDE.md` §7 et « principe N » désigne `00-overview.md` § Principes directeurs.
- « décision N » désigne l'une des 19 décisions du 22/09 (`questions-ouvertes.md`) et « DN du 23/09 » l'une des décisions du 23/09, consignées dans la section « Décisions du 23/09/2026 — jalon 1 » de `questions-ouvertes.md`.
- « 10 § x » renvoie au schéma.
- « contrat Cn », « E10-nn », « A-nn » et « R-nn » renvoient à la feuille de contrats partagés figée le 23/09. **Les contrats C0 et C6 sont rédigés en entier ici et y font désormais foi.**
- Marqueurs de code : **[existant]**, **[modifié]**, **[nouveau]**, lus par rapport au dépôt au commit `d167a6a`. **[complété]** désigne un fichier [nouveau] d'un lot antérieur de cette spec, étendu par un lot suivant.
- Jalons : **[J1]** première vraie partie entre invités, **[J2]** ouverture publique. Ce qui n'est pas marqué vaut pour les deux.

> **État réel du dépôt au moment d'écrire, vérifié le 23/09.**
> - **Réglages.** `app/Settings/RoomSettings.php` existe : `VERSION = 1`, seize `FIELDS` en camelCase, `fromInput()`, `validate()`, `normalize()`, `fromStorage()`, `warnings()`, `toPayload()`, codes `CHANGE_{DEFAULTED,DROPPED,CLAMPED,RESIZED,PRUNED,COERCED}` et `WARNING_*`. Son docblock (l.32-33) affirme à tort que les bornes croisées 4 et 5 sont refusées : `validate()` ne refuse que les bornes 1 et 2. `fromInput()` ne dérive `tierDurations`, `tierPoints` et `attemptsPerRound` que si la clé est **absente**.
> - **Bornes et limites.** `RoomSettingsBounds` existe avec ses constantes et `toArray(N)`, mais sans `toClient()` ni `MIN_CONNECTED_PLAYERS_TO_LAUNCH`. `PlatformLimits` porte neuf valeurs, dont `speedBonusMaxFraction` (**flottant**, surchargeable par `game.platform.speed_bonus_max_fraction`). Ses accesseurs lisent `Config::integer()` sans aucune garde de bornes (l.201-204). **`config/game.php` n'existe pas.** `SettingPresetCatalog` et `RoomSettingsCast` existent ; le cast n'implémente pas la comparaison par valeur.
> - **Modèles.** `Room` : `#[Fillable([])]`, `#[Hidden(['id','host_player_id','settings_version'])]`, `resolveRouteBinding()` (code actif, puis code archivé le plus récent), `normalizeCode()` = `strtoupper(trim())`. `Player` : scope `holdingSeat`, `#[Hidden]` sur `id`, `room_id`, `user_id`, `player_token_hash`, `active_seat_token` et `user`, **aucune colonne `kicked_at`**. `SavedConfig` et `SettingPreset` existent ; `Game` n'a ni `FROZEN_COLUMNS` ni garde `updating`. Les enums `RoomStatus`, `PlayerConnectionState`, `GamePlayerStatus`, `SettingPresetKey`, `InputDifficulty` et `PurgeScope` (cas `stale_lobby` compris) existent.
> - **Générateurs.** L'alphabet du code de salon et celui de `player.public_id` ne vivent que dans des fabriques (`RoomFactory::CODE_ALPHABET`, `PlayerFactory::PUBLIC_ID_ALPHABET`) : aucun générateur de production n'existe dans `app/`.
> - **Policies.** Trois policies existent : `ImportRunPolicy`, `MoviePolicy` et `SavedConfigPolicy`, cette dernière en `view`, `update` et `delete` par propriété. Aucune ne porte sur le salon, et `RoomPolicy` n'existe pas.
> - **Absents.** `routes/game.php`, `app/Http/Controllers/Room/`, `app/Http/Requests/Room/`, `app/Actions/Room/`, Reverb, Echo et `predis`. `.env.example` porte `BROADCAST_CONNECTION=log`, `QUEUE_CONNECTION=database` et `REDIS_CLIENT=phpredis`.
> - **Traductions.** `lang/{fr,en}/room.php` ne portent que `room.presets.*`. `lang/*/validation.php` porte le bloc `room_settings` (douze clés) et des `attributes` en camelCase **doublés** de `frames_per_round`, `rounds_count` et `room_code`, dont aucun n'a de consommateur.
> - **Front.** Le switch de `resources/js/app.tsx` ne connaît ni `game/*` ni `room/*`. `components/ui/tabs.tsx` et `table.tsx` existent ; `slider`, `switch`, `radio-group` et `scroll-area` manquent.
> - **Tests.** Contrairement à `CLAUDE.md` §8, `RefreshDatabase` est **actif** dans `tests/Pest.php`. Aucun test `tests/Feature/Room/` n'existe. `TranslationCoverageTest` porte déjà « keeps every room preset nameable in both languages ».
>
> Tout ce qui suit est à construire sur ce socle : rien n'est à défaire, sauf les trois libellés snake_case et le flottant de `B_max`.

---

## 1. Vocabulaire et invariants repris

Lexique normatif de `00` § Vocabulaire, à la lettre : salon `room`, code `room_code`, hôte `host`, joueur `player`, invité `guest`, réglages `room_settings`, preset `setting_preset`, configuration sauvegardée `saved_config`, onglets `simple` / `advanced`, vivier `pool`, palier `tier`, partie `game`, manche `round`. Les termes ajoutés par la feuille de contrats sont repris tels quels : œuvre `work`, vivier catalogue et vivier du salon, mémoire du salon, expulsé `kicked`, drainage.

Quatre précisions propres à ce document :

- **Siège.** Une ligne `player` d'un salon. L'**effectif présent** est `Player::holdingSeat()` (`connection_state <> 'left'`) [existant]. Compter toutes les lignes compterait l'historique des sièges, que rien ne supprime avant l'archivage (10 § 6.2).
- **Autorité d'hôte.** Elle est portée par un **siège** (`room.host_player_id`), jamais par un compte ni par un rôle : l'hôte est le plus souvent un invité.
- **Deux « grâces », jamais le même mot seul** (10 § 1.3). `disconnectGraceSeconds` est un réglage d'hôte (délai avant « parti »). `tier_grace_ms` est une constante serveur de frontière de palier, qui n'est **jamais** dans `room_settings`.
- **camelCase / snake_case.** Les clés du value object, des charges et des formulaires sont en camelCase ; le snake_case est réservé aux colonnes. Un champ posté en snake_case est une clé inconnue, donc un refus dur (A-37, E10-29).

---

## 2. Le contrat `room_settings` (contrat C0)

### 2.1 Un objet, seize champs, deux chemins

`App\Settings\RoomSettings` [modifié] reste `final readonly`, versionné, sérialisé par `App\Casts\RoomSettingsCast` dans les quatre colonnes JSON de 10 § 6.1. Il n'existe aucune table `room_settings` (10 § A14).

**Règles, toutes normatives :**

1. **Liste close de seize champs** (`FIELDS`, ordre de sérialisation). `D` n'est **pas** un champ : `D = Σ tierDurations` (10 § 6.1). `roundDuration` est une **clé d'entrée seulement** de l'onglet Simple ; elle n'est jamais stockée.
2. **Toute instance persistée sort de `fromInput()`, de `normalize()` ou de `defaults()`**, avec `sourceVersion = VERSION`. `fromStorage()` est réservé au cast, et aucune instance n'est construite à la main. Pourquoi : un `final readonly` qui peut exister dans un état invalide ne vaut rien, et le cast doit relire fidèlement une ligne ancienne sans la normaliser (10 § 6.1).
3. **Deux chemins, jamais confondus.**
   - `fromInput()` refuse et rend des erreurs indexées par champ : bornes simples et bornes croisées **1 et 2**.
   - `normalize($raw, $version, $availableThemeIds)` ne refuse jamais et rend `changes` champ par champ.
   - Les bornes 4 et 5 sont des **avertissements** rendus par `warnings()`, jamais bloquants (§ 4). Le docblock l.32-33 est corrigé en ce sens (E10-26).
4. **`VERSION` reste à 1.** Aucune décision du 23/09 n'ajoute, ne retire ni ne change le sens d'un champ : D17, D22, D28 et D34 du 23/09 sont sans schéma.
   - `VERSION` s'incrémente dès qu'un champ est ajouté, retiré ou change de sens, **et dès qu'une borne de `RoomSettingsBounds` se resserre**, avec son pas dans `upgrade()`.
   - Sans cette seconde clause, un salon ouvert avant un déploiement garderait une valeur devenue hors bornes et la figerait au lancement : la branche `settings_outdated` de la transaction de lancement (§ 12.2, étape L5) ne se déclenche que sur un écart de version.
   - Cette seconde clause est propre à ce document : elle n'est ni dans le contrat C0 ni dans 10 § 6.1, et elle rendra faux, au premier resserrement, le test contractuel « garde VERSION à 1 tant que FIELDS est inchangé ». L'écart est signalé au porteur (points restés ouverts, n° 8).
   - **Les bornes lues dans `PlatformLimits` ne passent pas par `VERSION`.** Aujourd'hui, seule la borne haute de `capacity` (`roomSeats()`) est dans ce cas : elle se resserre par configuration, sans commit. Son rattrapage est écrit au § 10 ; il n'a pas lieu au lancement, puisque `capacity` n'a aucun effet en partie hors de la prise de siège.
5. **Nouvelles constantes de rapport**, qui ferment la liste des codes de changement :
   - `CHANGE_EQUALIZED = 'equalized'` et `CHANGE_RESET = 'reset'` (D34 du 23/09) ;
   - `CHANGE_RAISED = 'raised'` et `CHANGE_OVERWRITTEN = 'overwritten'`, tous deux [J2].
6. **Docblocks** : `speedBonusMaxFraction` devient `speedBonusMaxPercent` (contrat C13). Les constantes serveur n'y sont toujours pas, et les poster reste un refus dur. Le docblock de `VERSION` (« incrémentée dès qu'un champ est ajouté, retiré ou change de sens ») reçoit « et dès qu'une borne de `RoomSettingsBounds` se resserre » (règle 4).

### 2.2 Les seize champs

Entiers partout, durées en **secondes entières**. Les nombres cités sont des **défauts ou des bornes** ; aucun n'est une constante de jeu, et chacun nomme sa source.

| # | Champ | Onglet | Défaut (source) | Bornes (source) | Effet | Projection sur `room` / `game` | Édition |
|---|---|---|---|---|---|---|---|
| 1 | `themeIds` (client : `themeKeys`) | Simple | `[]` = tout le catalogue (`defaultThemeIds()`) | ids de thèmes publiés (contrat C2) | union OU ; restreint le vivier | — | [J1] accepté par le serveur, sélecteur masqué ; [J2] sélecteur visible au-dessus du seuil (§ 9.5) |
| 2 | `roundsCount` (`M`) | Simple | 10 (`DEFAULT_ROUNDS_COUNT`) | 3–30 (`MIN/MAX_ROUNDS_COUNT`) | longueur de la partie, doit tenir dans le vivier | `rounds_count` | [J1] |
| 3 | `framesPerRound` (`N`) | Simple | 3 (`DEFAULT_FRAMES_PER_ROUND`) | 2–5 (`MIN/MAX_FRAMES_PER_ROUND`) | paliers, niveaux échantillonnés, barème, vivier, budget réseau | `frames_per_round` | [J1] |
| — | `roundDuration` (`D`, entrée seule) | Simple | 30 (`DEFAULT_ROUND_DURATION`) | `[minRoundDuration(N), MAX_ROUND_DURATION]`, soit `[max(10, 5 × N), 120]` | découpé en paliers égaux | jamais stockée | [J1] |
| 4 | `tierDurations` | Avancé | `defaultTierDurations(N, D)` : égaux, le dernier absorbe le reste | chaque `dᵢ ∈ [MIN_TIER_DURATION, maxTierDuration(N)]` ; `Σ ∈ [minRoundDuration(N), MAX_ROUND_DURATION]` ; `N` entrées | durée de chaque palier ; `D = Σ` | `round_tier.duration_ms` au lancement | [J1] dérivé de `roundDuration` ; [J2] édité |
| 5 | `tierPoints` | Avancé | `defaultTierPoints(N)` = `(N − i + 1) × TIER_POINTS_UNIT` | 0–1000 (`MIN/MAX_TIER_POINTS`), `N` entrées | valeur du palier `i` | `round_tier.points` | [J1] dérivé selon D34 du 23/09 ; [J2] édité |
| 6 | `revealDuration` (`R`) | Simple | 8 (`DEFAULT_REVEAL_DURATION`) | 3–20 (`MIN/MAX_REVEAL_DURATION`) | révélation entre deux manches | — | [J1] |
| 7 | `inputDifficulty` | Simple | `normal` (`DEFAULT_INPUT_DIFFICULTY`) | `easy` / `normal` / `expert` (enum) | mode de saisie, instant d'apparition du QCM | `input_difficulty` | [J1] |
| 8 | `capacity` | Simple | `PlatformLimits::roomSeats()` | `[MIN_CAPACITY, roomSeats()]`, **et** jamais abaissée sous l'effectif présent (garde hors du value object, § 10) | nombre de sièges | `capacity` | [J1] |
| 9 | `allowLateJoin` | Simple | `false` (`DEFAULT_ALLOW_LATE_JOIN`) | booléen | entrée après le lancement, à la manche suivante (§ 15) | `allow_late_join` | [J1], livré et réglable comme tout réglage Simple (D17 du 23/09 sans effet depuis D35 du 23/09 — amendé le 23/09) |
| 10 | `speedBonus` | Avancé | `true` | booléen | interrupteur du bonus ; `B_max(N)` vit dans `PlatformLimits` (§ 2.3) | — | [J2] |
| 11 | `noRepeatMovies` | Avancé | `true` | booléen | non-répétition des films déjà joués par le salon | — | [J2] |
| 12 | `attemptsPerSecond` | Avancé | 1 | 1–5 | cadence de la saisie libre | — | [J2] |
| 13 | `attemptsPerRound` | Avancé | `defaultAttemptsPerRound(D)` = `ceil(D / ATTEMPTS_PER_ROUND_SECONDS_PER_ATTEMPT)`, plafonné à `ATTEMPTS_PER_ROUND_SOFT_CAP` | 5–50 | plafond de tentatives en texte libre | — | [J1] dérivé selon D34 du 23/09 ; [J2] édité |
| 14 | `maxAnswerLength` | Avancé | 100 | 20–200 | longueur maximale d'une réponse | — | [J2] |
| 15 | `disconnectGraceSeconds` | Avancé | 60 | 15–180 | délai avant « parti », au lobby comme en partie | — | [J2] |
| 16 | `advanced` | sélecteur d'onglet | `false` | booléen | onglet retenu | — | [J1] toujours `false` ; [J2] |

Toutes les bornes et tous les défauts de la colonne « Défaut » viennent de `RoomSettingsBounds`, sauf la capacité, qui vient de `PlatformLimits::roomSeats()`.

**Au J1**, toute ligne `room` porte `advanced = false`. Les champs 10, 11, 12, 14 et 15 y sont à leur défaut, et les champs 4, 5 et 13 à leur valeur dérivée. C'est un invariant testé : un champ avancé posté à la main est refusé (§ 3.1).

**En solo**, `capacity`, `allowLateJoin` et `noRepeatMovies` sont sans effet : le snapshot garde leur valeur, mais aucun code ne les lit. Le sort de `disconnectGraceSeconds` en solo appartient à `60` (§ 19).

### 2.3 Constantes de plateforme, hors du value object

`App\Settings\PlatformLimits` [modifié] reste `final readonly` à accesseurs statiques.
- **Aucun accesseur n'est paramétré par un `User`, un plan ou un siège** : ce sont des constantes d'instance, jamais résolues par compte (décision 2, neutralité de plan).
- **Chaque accesseur statique applique la garde de bornes du constructeur**, en déléguant à une instance construite par `fromConfig()`, mémoïsée **dans le conteneur** (`app()->scoped(PlatformLimits::class, …)`) et jamais dans une propriété statique de classe : une propriété statique survivrait d'un test à l'autre dans le même processus Pest, et un test qui pose `config()->set()` lirait la valeur mémoïsée par un test précédent. Une configuration hors bornes ne contourne ainsi la garde sur aucun chemin. Ce n'est pas le cas aujourd'hui (l.201-204).
- **Tests.** Tout test qui change `game.platform.*` en cours de test passe par l'aide `platformLimitsConfigure()` de `tests/Pest.php`, qui oublie l'instance du conteneur (et celle d'`EngineConstants`, dont la garde lit la marge de tirage), jamais par un `config()->set()` seul : une fois l'instance `scoped` résolue, ce dernier est ignoré en silence. L'aide vit dans `tests/Pest.php` et non dans `PlatformLimitsTest`, pour qu'un fichier de test ultérieur joué seul la trouve — amendé le 25/09 (E9-8).

| Famille | Accesseur | Défaut | Bornes | Surcharge |
|---|---|---|---|---|
| Confort | `savedConfigsPerUser()` | 20 | ≥ 1 | `game.platform.saved_configs_per_user` |
| Confort | `roomSeats()` | 12 | `[RoomSettingsBounds::MIN_CAPACITY, avatarPresets()]` | `game.platform.room_seats` |
| Confort | `avatarPresets()` | 24 | ≥ 1 | `game.platform.avatar_presets` |
| Confort | `historyWindowMonths()` | 12 | `[1, DEFAULT_HISTORY_WINDOW_MONTHS]` : une surcharge ne peut que raccourcir la fenêtre, jamais dépasser le plafond public de 12 mois (décision 19, `CLAUDE.md` §2 : « un plafond, jamais un plancher ») | `game.platform.history_window_months` |
| Confort | `successRateMinRounds()` | 20 | ≥ 1 | `game.platform.success_rate_min_rounds` |
| Confort | `frameUploadMaxKilobytes()` | 1536 | `[1, DEFAULT_FRAME_UPLOAD_MAX_KILOBYTES]` : toujours sous `upload_max_filesize` (2 Mo), sans quoi un envoi trop gros finirait en 419 muet au lieu d'une erreur traduite (`CLAUDE.md` §8) | `game.platform.frame_upload_max_kilobytes` |
| Règle figée sur `game` au lancement | `tierGraceMs()` | 300 | `2 × tierGraceMs() < MIN_TIER_DURATION × 1000` | **jamais** |
| Règle figée sur `game` au lancement | `preloadLeadMs()` | 2000 | `[MIN_PRELOAD_LEAD_MS, MAX_PRELOAD_LEAD_MS]` = [1500, 2500] | **jamais** |
| Règle non persistée | `speedBonusMaxPercent(int $framesPerRound)` | 50, 50, 33, 25 pour N = 2 à 5 | N dans les bornes, sinon `InvalidArgumentException`, **jamais d'écrêtage** | **jamais** |
| Tirage et mémoire (valeurs déclarées par `30`) | `drawSubstituteMargin()` | 3 | `[0, min(MAX_DRAW_SUBSTITUTE_MARGIN, MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE)]`, une seule garde sous la plus basse des deux bornes, pour que le message d'une marge fautive annonce la borne qui gouverne (150 aux défauts) — amendé le 25/09 (E10-2, E10-8). `MAX_DRAW_SUBSTITUTE_MARGIN = MAX_ROUND_SEQUENCE_INDEX − RoomSettingsBounds::MAX_ROUNDS_COUNT` (225 aux bornes actuelles) : `round.sequence_index` est un `unsignedTinyInteger` (10 § 7.4), et une marge au-delà ferait échouer `MaterializeDraw` sous MySQL strict, donc tout lancement, sur un catalogue de plus de 255 œuvres (borne signalée par 30 § 1.1). `MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE = ⌊EngineConstants::DEFAULT_PAUSE_TIMEOUT_MS ÷ EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS⌋ − RoomSettingsBounds::MAX_ROUNDS_COUNT` (150 aux défauts) : borne de fait sous la garde de clôture après pause du moteur (60 § 19.1), lue aux **défauts** du moteur et non à sa configuration, puisque la garde du moteur lit elle-même cette marge ; une surcharge du moteur reste jugée par sa propre garde | `game.platform.draw_substitute_margin` |
| Tirage et mémoire | `roomMemoryWindowDays()` | 90 | ≥ 1 | `game.platform.room_memory_window_days` |
| Tirage et mémoire | `roomMemoryWindowRounds()` | 500 | ≥ 1 | `game.platform.room_memory_window_rounds` |
| Tirage et mémoire | `themeSelectorMinPool()` | 150 | ≥ 0 | `game.platform.theme_selector_min_pool` |
| Lobby | `lobbyBroadcastDebounceMs()` | 300 | `[0, MAX_LOBBY_BROADCAST_DEBOUNCE_MS]` = [0, 1000] | `game.platform.lobby_broadcast_debounce_ms` |
| Curation (valeurs déclarées par `20`) | `frameCropMaxWidthPercent()` | 80 | [70, 90] | `game.platform.frame_crop_max_width_percent` |
| Curation | `frameCropMinWidthPx()` | 640 | multiple de 16 dans [320, 1280] | `game.platform.frame_crop_min_width_px` |

**`B_max` en fonction de `N` (D22 du 23/09).**
- Il est défini par `public const int SPEED_BONUS_MAX_PERCENT_CAP = 50;` et `public const int FULL_PERCENT = 100;` [nouveaux]. Le corps est `min(SPEED_BONUS_MAX_PERCENT_CAP, intdiv(FULL_PERCENT, N − 1))`.
- C'est un **pourcentage entier**, calculé par `intdiv` pour que le rejeu soit exact.
- Il n'est **jamais lu en configuration**, **jamais dans `room_settings`** : l'hôte n'a qu'un interrupteur (`speedBonus`), jamais un curseur.
- Sont supprimés : `speedBonusMaxFraction()`, `DEFAULT_SPEED_BONUS_MAX_FRACTION`, le paramètre flottant du constructeur, la clé `toArray()['speedBonusMaxFraction']` et la clé de configuration `game.platform.speed_bonus_max_fraction`.
- La formule du bonus appartient à `80` (contrat C13).

**`tierGraceMs` et `preloadLeadMs` sont des constantes d'instance non surchargeables** (arbitrage du rédacteur en chef du 23/09 sur les constantes d'instance, joint à la feuille de contrats : il a la même force qu'elle et reste en dessous des décisions du porteur ; il étend à ces deux valeurs le régime de `B_max`, D22 du 23/09).
- Elles sont figées par partie dans `game.tier_grace_ms` et `game.preload_lead_ms` (10 § 7.2). Seul un changement de leur défaut **dans le code** est possible, et il incrémente `ScoringRules::VERSION`, donc `game.scoring_version` (10 § 7.2).
- Leurs accesseurs ne lisent **aucune** clé de configuration. Une clé `game.platform.tier_grace_ms` ou `game.platform.preload_lead_ms` posée par un déploiement est **ignorée**, et un test le prouve.
- Pourquoi : une surcharge par configuration changerait le palier retenu, la fenêtre de service et donc le score, sans qu'aucune version de règle ne le trace.
- Cet arbitrage précise la ligne « surchargeable » de la feuille de contrats pour ces deux valeurs. Leur domicile et leur gel sur `game` restent inchangés.

**Noms retenus par L50-1 là où le contrat C0 se tait** — amendé le 25/09 (E9-5, E10-2, E21-6). Le contrat ne nomme que les accesseurs, les `DEFAULT_*`, `MAX_DRAW_SUBSTITUTE_MARGIN`, `MAX_LOBBY_BROADCAST_DEBOUNCE_MS`, `SPEED_BONUS_MAX_PERCENT_CAP` et `FULL_PERCENT`. Le code livré ajoute :
- `MAX_ROUND_SEQUENCE_INDEX = 255`, fait de schéma (`round.sequence_index`, 10 § 7.4) dont dérive `MAX_DRAW_SUBSTITUTE_MARGIN` ;
- `MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE` (public, posé par L60-1), **constante et non méthode** : la réflexion de `PlatformLimitsTest` compte toute méthode statique sans argument rendant un `int` comme un accesseur configurable ; `intdiv()` n'étant pas admis dans une expression constante, la division entière s'y écrit par le reste ;
- `MIN_FRAME_CROP_MAX_WIDTH_PERCENT` et `MAX_FRAME_CROP_MAX_WIDTH_PERCENT` (70, 90), `MIN_FRAME_CROP_MIN_WIDTH_PX` et `MAX_FRAME_CROP_MIN_WIDTH_PX` (320, 1280), `FRAME_CROP_WIDTH_MULTIPLE_PX` (16). Cette dernière redit `FrameGeometry::ASPECT_WIDTH` de C9, égalité assertée par `CropRectangleTest` ; le prochain lot qui retouche `PlatformLimits` peut faire lire l'une par l'autre ;
- les bornes basses « ≥ 1 » et « ≥ 0 » en constantes privées ;
- `PlatformLimits::current(): self`, l'instance du conteneur, qui sert `toArray()` en prop de page et les signatures de C9 qui reçoivent un `PlatformLimits $limits`.

Le constructeur prend ses quinze arguments dans l'ordre du tableau ci-dessus, `speedBonusMaxPercent` exclu.

**`config/game.php` [nouveau].**
- **Section `platform`**, lue **exclusivement** par `PlatformLimits`. Elle compte **treize** clés : `saved_configs_per_user`, `room_seats`, `avatar_presets`, `history_window_months`, `success_rate_min_rounds`, `frame_upload_max_kilobytes`, `draw_substitute_margin`, `room_memory_window_days`, `room_memory_window_rounds`, `theme_selector_min_pool`, `lobby_broadcast_debounce_ms`, `frame_crop_max_width_percent` et `frame_crop_min_width_px`.
  - Chaque valeur vaut `PlatformLimits::DEFAULT_*`, sans `env()` : la constante reste la source unique.
  - **Aucune clé `speed_bonus_*`, `tier_grace_ms` ni `preload_lead_ms`.**
  - Le constructeur de `PlatformLimits` porte toujours les **quinze** valeurs sans argument du tableau : les deux constantes non surchargeables y entrent par leur constante, jamais par la configuration.
- **Section `engine`** : propriété de `60` (`EngineConstants`, contrat C7 § 5). `50` ne la lit pas. L50-1, premier lot à créer le fichier, l'a posée **vide** (`'engine' => []`, avec un commentaire qui renvoie à `EngineConstants`) ; L60-1 l'a remplie de ses onze clés, chacune à sa constante `EngineConstants::DEFAULT_*`, sans `env()`. Écrire les onze valeurs en littéraux avant `EngineConstants` aurait créé des clés sans lecteur et une seconde source de vérité — amendé le 25/09 (E9-1).
- **Section `room`** [nouvelle, propriété de `50`] : les deux limiteurs d'entrée du § 17.3, lus exclusivement par `App\Support\Room\RoomRateLimits`.
- **Aucune section `operations`** : la borne et le TTL du drainage vivent dans `config/deploy.php` (contrat C18-bis).

### 2.4 `RoomSettingsBounds`

`RoomSettingsBounds` [modifié] conserve toutes ses constantes, y compris `clampFramesPerRound()`, qui ne sert jamais au calcul de `B_max`.

**Ajouts :**

- **`public const int MIN_CONNECTED_PLAYERS_TO_LAUNCH = 2;`** Seuil de lancement multijoueur (00 § Le jeu en une manche). Pour jouer seul, on passe par le mode solo.
- **`public static function toClient(): array`**, forme unique de la prop `bounds`. Toutes ses valeurs sont des entiers :
  ```
  { byFramesPerRound: { "<N>": toArray(N) pour N de MIN à MAX_FRAMES_PER_ROUND },
    derivation: { tierPointsUnit, attemptsPerRoundSecondsPerAttempt, attemptsPerRoundSoftCap },
    warningThresholds: { recommendedMinRevealDuration, longRoundWarningDuration } }
  ```
  Le découpage par `N` rend possible le retour immédiat quand `N` change : un seul jeu de bornes, celui du `N` courant, laisserait le client sans `minRoundDuration(N')` pour le `N'` visé.
- **Docblock de `RECOMMENDED_MIN_REVEAL_DURATION`** : son seul motif est désormais l'accessibilité (annonce `aria-live`, reprise de focus).
  - `R` n'est plus une fenêtre de préchargement dédiée. Seul le palier 1 de la manche suivante devient servable, dans les `preload_lead_ms` finales de `R` (contrat C7 § 4.14).
  - Le garde-fou `MIN_REVEAL_DURATION × 1000 > MAX_PRELOAD_LEAD_MS` le garantit.

### 2.5 Point d'écriture unique et gel

`App\Actions\Room\WriteRoomSettings` [nouveau], `handle(Room $room, RoomSettings $settings, CarbonImmutable $now): void`. C'est le **seul écrivain** de `room.settings`, de `room.settings_version` et des cinq projections (`capacity`, `frames_per_round`, `rounds_count`, `input_difficulty`, `allow_late_join`). Il pose aussi `last_activity_at = $now`.

**Préconditions**, chacune levant une `LogicException` :
- une transaction est ouverte ;
- `$settings->sourceVersion === RoomSettings::VERSION` ;
- si la ligne existe, elle est **verrouillée par l'appelant** et `room.status = lobby` ;
- à la création, le modèle n'est pas encore persisté et naît en `lobby`.

**Appelants**, liste close :
- `CreateRoom` (§ 6) ;
- `UpdateRoomSettings` et `ApplyRoomPreset` (§ 3, § 5) ;
- `LaunchGame`, branche `settings_outdated` (§ 12) ;
- [J2] `LoadSavedConfig` (§ 18).

**Invariants :**

1. **`projection == value object`** sur toute ligne `room`, vérifié par test après chaque écriture (10 § 6.2).
2. **Toute autre écriture de `room`** (`last_activity_at`, `host_player_id`, `status`, `launched_at`, archivage) passe par une **mise à jour ciblée** `Room::query()->whereKey($id)->update([...])`, jamais par `save()` sur une instance dont `settings` a été lu.
   - Pourquoi : `Model::save()` rappelle le cast sur l'objet mis en cache (10 § 6.1). Une sauvegarde distraite réécrirait donc la charge de réglages hors de l'écrivain unique.
   - `RoomSettingsCast` [modifié] implémente en outre `ComparesCastableAttributes`, par `fromStorage()` des deux valeurs puis `equals()` (contrat C6). `isDirty()` devient ainsi fondé sur la valeur, pour les quatre colonnes porteuses.
3. **Gel.**
   - `room.settings` est immuable de `room.status = playing` jusqu'au « Rejouer » de l'hôte, podium compris (§ 12.7, A-14).
   - `game.settings_snapshot` et les colonnes figées de `game` sont immuables pour toujours.
   - En partie, `60`, `70` et `80` lisent `game.settings_snapshot`, les colonnes de `game` et `round_tier`, **jamais `room.settings`**.

`App\Actions\Room\UpdateRoomSettings` et `App\Actions\Room\ApplyRoomPreset` [nouveaux] suivent la même séquence, dans une transaction :
1. `Room::whereKey()->lockForUpdate()` ;
2. `$now` pris après le verrou ;
3. autorité d'hôte relue sous verrou (§ 17.1) ;
4. `status = lobby`, sinon refus `not_in_lobby` (§ 12.5) ;
5. éditeur (§ 3) ou preset (§ 5) ;
6. `fromInput()` ;
7. garde de capacité (§ 10) ;
8. `WriteRoomSettings` ;
9. après validation de la transaction, dispatch de `BroadcastLobbyState` (§ 8.3).

### 2.6 Charges utiles

`App\Support\Room\RoomSettingsPresenter` [nouveau] :
- **`view(RoomSettings $s): array`** rend `RoomSettingsView` : les seize clés de `toPayload()`, avec `themeIds` remplacé par `themeKeys: list<string>`.
  - `themeKeys` contient les `theme.key` dans l'ordre de `themeIds`, par `PoolQuery::publishedThemeIdsByKey()` inversé (contrat C2). Un id de thème dépublié est **omis**, et le rapport de vivier le signale par `themesPruned`, que le lobby affiche à tous (§ 9.2).
  - C'est la **seule** divergence entre le stockage et le client : 10 § 1.1 interdit d'exposer un identifiant interne (E10-11). Le stockage reste en `themeIds`.
- **`state(Room $room, CarbonImmutable $now): array`** rend `RoomSettingsState` :
  ```
  { settings: RoomSettingsView, warnings: RoomSettingsWarningCode[], pool: PoolReport }
  ```
  - `pool` est exactement `PoolReporter::report(PoolScope::forRoom($room, $room->settings, $now), M)->toArray()` (contrat C2).
  - Tant que l'onglet Avancé n'est pas livré (constante `RoomSettingsEditor::ADVANCED_TAB_AVAILABLE = false` au J1, `true` au lot L50-10), le remède `disable_no_repeat` en est retiré (D28 du 23/09).
  - Cette charge est **diffusée au salon**, identique pour tous, **sans aucune chaîne traduite**.

**Rapport de changements.**
- Sa forme est celle du contrat C0 § 3.4, à la lettre : `changes: Record<RoomSettingsFieldKey, RoomSettingsChangeCode>`. Élargir la clé à `string` aplatirait le type en `Record<string, …>` et supprimerait le typage des clés du rapport.
- Seul `normalize()` pourrait y mettre une clé hors de `RoomSettingsFieldKey`, avec `dropped`, pour un champ retiré par une version ultérieure. Aucun n'existe à `VERSION = 1` ; l'élargissement éventuel de la forme est signalé au porteur (points restés ouverts, n° 9).
- Il est **ciblé** vers l'auteur, dans la réponse HTTP, par `Inertia::flash('settingsChanges', $changes)`. **Il ne part jamais au salon.**
- Le flash est posé à chaque écriture acceptée, **même vide** (`[]`, un preset au J1) : l'événement `flash` d'Inertia ne part que si la clé existe, et un rapport vide efface côté client le rapport précédent. Le JSON d'un rapport vide est un tableau, lu comme `{}` ; le client type le flash `Partial<Record<RoomSettingsFieldKey, RoomSettingsChangeCode>>` — amendé le 28/09 (E86-3, E86-5).
- Les clés `themeIds` qu'il contient sont réécrites en `themeKeys`.
- Le libellé d'un champ retiré par une version ultérieure reste au dictionnaire tant qu'une charge de version antérieure peut être chargée.

**`PlatformLimits::toArray()`** est une prop de page, jamais une prop partagée globale :
```
{ savedConfigsPerUser, roomSeats, avatarPresets, historyWindowMonths, successRateMinRounds,
  speedBonusMaxPercent: { "2": 50, "3": 50, "4": 33, "5": 25 } }
```
La table `speedBonusMaxPercent` est calculée pour chaque `N` des bornes, jamais écrite à la main. `tierGraceMs`, `preloadLeadMs`, `frameUploadMaxKilobytes` et les plafonds de recadrage n'y figurent pas (R-07) : aucun écran joueur n'en a besoin.

**Types client**, `resources/js/types/room-settings.ts` [nouveau, dans `WATCHED`] :
```ts
import type { PoolReport } from '@/types/pool';                 // contrat C2, jamais redéclaré
export type InputDifficulty = 'easy' | 'normal' | 'expert';
export type RoomSettingsFieldKey = 'themeKeys' | 'roundsCount' | 'framesPerRound' | 'roundDuration'
  | 'tierDurations' | 'tierPoints' | 'revealDuration' | 'inputDifficulty' | 'capacity' | 'allowLateJoin'
  | 'speedBonus' | 'noRepeatMovies' | 'attemptsPerSecond' | 'attemptsPerRound' | 'maxAnswerLength'
  | 'disconnectGraceSeconds' | 'advanced';
export type RoomSettingsView = { themeKeys: string[]; roundsCount: number; framesPerRound: number;
  tierDurations: number[]; tierPoints: number[]; revealDuration: number; inputDifficulty: InputDifficulty;
  capacity: number; allowLateJoin: boolean; speedBonus: boolean; noRepeatMovies: boolean;
  attemptsPerSecond: number; attemptsPerRound: number; maxAnswerLength: number;
  disconnectGraceSeconds: number; advanced: boolean };
export type RoomSettingsWarningCode = 'short_reveal' | 'long_round' | 'non_decreasing_points' | 'all_tiers_zero';
export type RoomSettingsChangeCode = 'defaulted' | 'dropped' | 'clamped' | 'resized' | 'pruned' | 'coerced'
  | 'equalized' | 'reset' | 'raised' | 'overwritten';
export type Bound = { min: number; max: number };
export type RoomSettingsBoundsForN = Record<'roundsCount' | 'framesPerRound' | 'roundDuration' | 'tierDuration'
  | 'tierPoints' | 'revealDuration' | 'capacity' | 'attemptsPerSecond' | 'attemptsPerRound' | 'maxAnswerLength'
  | 'disconnectGraceSeconds', Bound>;
export type RoomSettingsBoundsPayload = { byFramesPerRound: Record<string, RoomSettingsBoundsForN>;
  derivation: { tierPointsUnit: number; attemptsPerRoundSecondsPerAttempt: number; attemptsPerRoundSoftCap: number };
  warningThresholds: { recommendedMinRevealDuration: number; longRoundWarningDuration: number } };
export type PlatformLimitsPayload = { savedConfigsPerUser: number; roomSeats: number; avatarPresets: number;
  historyWindowMonths: number; successRateMinRounds: number; speedBonusMaxPercent: Record<string, number> };
export type RoomSettingsState = { settings: RoomSettingsView; warnings: RoomSettingsWarningCode[]; pool: PoolReport };
export type RoomRefusalCode = 'not_host' | 'room_archived' | 'not_in_lobby' | 'settings_outdated'
  | 'not_enough_players' | 'draining' | 'pool_insufficient' | 'game_not_ended';
```
Les clés de `byFramesPerRound` et de `speedBonusMaxPercent` sont des chaînes d'entier. **Aucun type n'énumère les valeurs de `N`** : ce serait une seconde source de ses bornes.

### 2.7 Garde-fous testés

Chacun est prouvé par un test (lot L50-1, sauf le sixième) :
- `MIN_REVEAL_DURATION × 1000 > MAX_PRELOAD_LEAD_MS` ;
- `2 × tierGraceMs() < MIN_TIER_DURATION × 1000` ;
- `MIN_CAPACITY ≥ MIN_CONNECTED_PLAYERS_TO_LAUNCH` ;
- `roomSeats() ≥ MIN_CAPACITY` ;
- `roomSeats() ≤ avatarPresets()` (exigence de `40`, contrat C5 I5.7) ;
- `drawSubstituteMargin() ≤ MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE`, soit `⌊pauseTimeoutMs ÷ launchCountdownMs⌋ − MAX_ROUNDS_COUNT` aux défauts du moteur (60 § 19.1, § 2.3) — amendé le 25/09 (E10-2).

Correspondance avec les tests livrés — amendé le 25/09 (E9-2, E9-8, E10-2) :
- les garde-fous 3 à 5 n'ont pas chacun leur intitulé : « n'offre jamais moins d'avatars prédéfinis que de sièges » prouve la chaîne entière `MIN_CONNECTED_PLAYERS_TO_LAUNCH ≤ MIN_CAPACITY ≤ roomSeats() ≤ avatarPresets()`, constructeur et configuration compris ; aucun intitulé n'est ajouté à la liste de L50-1 ;
- ce test et « garde preloadLeadMs dans [1500, 2500]… » acceptent aussi leurs bornes fermées (`MIN_PRELOAD_LEAD_MS`, `MAX_PRELOAD_LEAD_MS`, `roomSeats = MIN_CAPACITY`, `roomSeats = DEFAULT_AVATAR_PRESETS`), pour qu'une garde rendue exclusive échoue ;
- le sixième est posé au constructeur de `PlatformLimits` par L60-1, faute d'`EngineConstants` en L50-1. Il est prouvé par la borne haute de « refuse une marge, une fenêtre, un seuil ou un plafond de recadrage hors bornes » (plus grande marge admise exactement `min(225, 150)`, donc jamais plus sévère que nécessaire) et par « une marge de tirage admise par PlatformLimits ne fait jamais échouer la garde de pauseTimeoutMs aux défauts » d'`EngineConstantsTest` (60 § 20).

**Règle 3.** Aucune charge de ce chapitre ne porte de film, de graine ni d'identifiant interne : les thèmes voyagent par clé, le salon par code, les sièges par `public_id`.

---

## 3. Onglets Simple / Avancé et règle D34 du 23/09

### 3.1 Clés postées

`App\Settings\RoomSettingsEditor` [nouveau], `final readonly`, est une **fonction pure** : il transforme une charge postée par onglet en entrée complète de `fromInput()`, et rend `{ input, changes }`.

```php
public const array SIMPLE_KEYS = ['themeKeys', 'roundsCount', 'framesPerRound', 'roundDuration',
    'revealDuration', 'inputDifficulty', 'capacity', 'allowLateJoin', 'advanced'];
public const array ADVANCED_KEYS = ['themeKeys', 'roundsCount', 'framesPerRound', 'tierDurations',
    'tierPoints', 'revealDuration', 'inputDifficulty', 'capacity', 'allowLateJoin', 'speedBonus',
    'noRepeatMovies', 'attemptsPerSecond', 'attemptsPerRound', 'maxAnswerLength',
    'disconnectGraceSeconds', 'advanced'];                            // [J2] : pas de roundDuration
public const bool ADVANCED_TAB_AVAILABLE = false;                   // true au lot L50-10 [J2]
/** @param array<string, int> $publishedThemeIdsByKey  PoolQuery::publishedThemeIdsByKey() (contrat C2) */
public static function simple(RoomSettings $current, array $posted, array $publishedThemeIdsByKey): array;
public static function advanced(RoomSettings $current, array $posted, array $publishedThemeIdsByKey): array; // [J2]
```

**Règles de l'entrée :**
- La clé `advanced` du corps choisit `simple()` ou `advanced()`. Absente, elle vaut l'onglet courant (`current.advanced`).
- **Toute clé hors de la liste de l'onglet**, et `advanced: true` tant que `ADVANCED_TAB_AVAILABLE` est faux, sont refusées par `validation.room_settings.not_editable`, indexée sous la clé du champ.
  - Pourquoi au J1 : `fromInput()` accepte les seize champs. Sans ce refus, un client scripté pourrait poser un barème que l'interface ne montre pas, et la règle D34 du 23/09 cesserait d'être la seule vérité du J1.
- `themeKeys` est traduit en `themeIds` par `$publishedThemeIdsByKey`. Une clé inconnue ou non publiée donne `validation.room_settings.theme_keys`.
- Les erreurs de `fromInput()` sur `themeIds` sont **réindexées** sous `themeKeys`. Les erreurs par palier restent `tierDurations.{i}` et `tierPoints.{i}` [existant].
- Une valeur non entière est transmise telle quelle, et `fromInput()` la refuse : l'éditeur ne coerce rien.
- `ADVANCED_TAB_AVAILABLE` est une **constante de code**, pas un drapeau de configuration : elle change par un commit du lot L50-10, jamais à l'exécution. Ce n'est donc pas le système de « feature flags » que le hors-périmètre de 00 interdit.
- **Retardataires (D35 du 23/09).** `allowLateJoin` est une clé de `SIMPLE_KEYS` comme les autres : livrée au J1, éditable par l'hôte, défaut `false` (§ 15.4) — amendé le 23/09.

**Aucune règle `App\Rules\ValidRoomSettings` n'est créée**, contrairement à 10 § 6.1 (« Les FormRequest des deux onglets délèguent entièrement via une règle `ValidRoomSettings` »).
- Pourquoi : une entrée Simple ne se valide qu'une fois composée avec l'état courant (règle D34 du 23/09), et la capacité se compare à l'effectif présent (§ 10). Ces deux lectures n'ont de valeur que sous le verrou du salon. Appliquer `fromInput()` à la charge postée brute, avant le verrou et sans composition, refuserait toute requête portant `themeKeys` (clé inconnue, `unknown_field`) et validerait une entrée partielle contre les défauts au lieu de l'état courant.
- `UpdateRoomSettingsRequest` ne valide que la forme du corps : un tableau, dont `advanced` est un booléen facultatif. `ApplyRoomPresetRequest` valide `preset` ∈ `SettingPresetKey`.
- La validation qui fait autorité est celle de l'action, sous verrou : `RoomSettingsEditor`, puis `fromInput()`, puis la garde de capacité (§ 2.5). L'amendement de 10 § 6.1 est signalé au porteur (section « Amendements »).

### 3.2 Règle Simple (D34 du 23/09)

Notations : `N₀ = current.framesPerRound`, `D₀ = current.roundDuration()`, `N₁ = posted.framesPerRound ?? N₀`, `D₁ = posted.roundDuration ?? D₀`.

```
input = current.toPayload() privé de themeIds et de tierDurations
      ⊕ posted (hors themeKeys)
      ⊕ { themeIds: map(posted.themeKeys) ?? current.themeIds,
          advanced: false,
          roundDuration: D₁,                                // fromInput dérive le découpage égal (N₁, D₁)
          tierPoints: (N₁ ≠ N₀ ∨ current.tierPoints = defaultTierPoints(N₀)) ? defaultTierPoints(N₁) : current.tierPoints,
          attemptsPerRound: current.attemptsPerRound = defaultAttemptsPerRound(D₀) ? defaultAttemptsPerRound(D₁) : current.attemptsPerRound }
changes:
  tierDurations ↦ 'equalized'  si current.tierDurations ≠ defaultTierDurations(N₀, D₀)
  tierPoints    ↦ 'reset'      si N₁ ≠ N₀ ∧ current.tierPoints ≠ defaultTierPoints(N₀)
```

- **Une valeur dérivée suit `N` et `D` tant qu'elle n'est pas personnalisée**, c'est-à-dire tant qu'elle vaut encore le défaut dérivé de l'ancien couple. Sinon elle est conservée.
  - Changer `N` change forcément la taille de `tierPoints` : le barème est alors réinitialisé, et l'interface l'annonce (`reset`).
  - Le cas ambigu (une valeur personnalisée égale par hasard au défaut) est sans conséquence : la valeur suit ce que l'hôte avait de toute façon réglé.
- **Les autres champs de l'onglet Avancé sont conservés et signalés**, jamais réinitialisés. C'est la lecture littérale de « deux vues du même objet de réglages » (00 § Vocabulaire du projet).
- **Seul ajout au calcul du contrat** : une capacité non postée supérieure à `PlatformLimits::roomSeats()` est ramenée à ce plafond et rapportée `capacity ↦ 'clamped'` (§ 10, plafond de plateforme abaissé). Ce n'est jamais un choix de l'éditeur : c'est la borne du value object, abaissée par configuration.
- **Le serveur n'ajuste jamais `D`.** Quand `N` augmente, c'est le client qui remonte `D` au minimum `minRoundDuration(N₁)` et qui l'annonce (§ 4.3). Toute entrée invalide est ensuite refusée par `fromInput()`, sans ajustement silencieux (règle 1, « jamais de comportement silencieux »).
- **Au J1**, `advanced` vaut toujours `false`, et `tierPoints` et `attemptsPerRound` valent toujours leur défaut dérivé : ils suivent donc toujours `N` et `D`. Les branches `reset` et `equalized` ne sont atteignables qu'au J2, **mais elles sont testées dès le J1**.
- Le même calcul redérive les champs dérivés quand D19 du 23/09 ramène d'office le `N` d'un preset solo (contrat C7 § 4.12).

### 3.3 Onglet Avancé [J2]

`RoomSettingsEditor::advanced()` a la même signature et la même traduction des thèmes.

- `roundDuration` n'y est pas accepté : `D` **devient** `Σ tierDurations` (10 § 6.1). Le poster donne `not_editable`.
- **L'éditeur Avancé ne dérive rien.** Quand `N` change, le client poste les deux listes redimensionnées : paliers égaux sur `max(D₀, minRoundDuration(N₁))` et barème par défaut, avec leur annonce. Une liste de mauvaise taille est refusée (`list_size`) : le serveur n'invente aucune durée.
- **Rapport** : `tierPoints ↦ 'reset'` quand `N₁ ≠ N₀` et que le barème courant était personnalisé.
- **Bascule Avancé → Simple.** Le client poste `advanced: false`, et c'est `simple()` qui s'applique. Les paliers sont réégalisés (`equalized` s'ils étaient inégaux), et tout le reste est conservé.
  - L'onglet Simple affiche alors un bandeau `room.settings.advanced_active`, qui liste les réglages avancés différents de leur défaut dérivé.
  - Pourquoi : l'hôte ne perd pas un barème réglé à la main (00 § Vocabulaire du projet : « deux vues du même objet de réglages » ; 00 § Réglages du salon : seules les durées sont réégalisées).
- **Présentation.** L'onglet est intégré sur desktop, et s'ouvre en feuille plein écran sur mobile (`Sheet` à `SheetTitle` et `SheetDescription` traduits, contrat C16 § 2.9). Aucune règle de jeu ne dépend de l'appareil. Onglets au motif ARIA tabs (principe 8), composant `tabs` [existant]. Leurs textes sont au § 20.6.

---

## 4. Bornes croisées

### 4.1 Où chacune est évaluée

| # | Borne | Nature | Client (retour immédiat) | Écriture (`fromInput`) | Lancement | Clé et paramètres |
|---|---|---|---|---|---|---|
| 1 | `D ≥ 5 s × N`, et `D` dans ses bornes | **bloquante** | oui : `D` remonté et annoncé (§ 4.3) | refus sous `roundDuration` | — (valeur figée déjà valide) | `validation.room_settings.round_duration` (`:attribute`, `:min`, `:max`, `:frames`) [existant] |
| 2 | chaque `dᵢ ∈ [MIN_TIER_DURATION, maxTierDuration(N)]`, `Σ dᵢ` dans les bornes de `D`, `N` entrées | **bloquante** | oui [J2] | refus sous `tierDurations` et `tierDurations.{i}` | — | `tier_duration` (`:tier`, `:min`, `:max`), `sum_between`, `duration_mismatch`, `list_size` [existants] |
| 3 | `vivier(thèmes, N) ≥ M`, compté en œuvres | **bloquante au lancement seulement** | compteur et message (§ 9) | jamais : une écriture qui fait tomber le vivier sous `M` est **acceptée** et affichée | garde rejouée dans la transaction, **seule à faire autorité** (§ 12.3, O3) | `room.pool.*`, refus `room.refusal.pool_insufficient` (`:playable`, `:required`) |
| 4 | `R < RECOMMENDED_MIN_REVEAL_DURATION` | avertissement | oui | `warnings()` → `short_reveal` | non bloquant | `room.warnings.short_reveal` (`:seconds`) |
| 5 | `D > LONG_ROUND_WARNING_DURATION` ; barème non strictement décroissant ; barème entièrement à 0 | avertissements cumulables | oui | `warnings()` → `long_round`, `non_decreasing_points`, `all_tiers_zero` | non bloquants | `room.warnings.{long_round (:seconds), non_decreasing_points, all_tiers_zero}` |

Trois raisons fondent ce partage.
- **Bornes 1 et 2 dans le value object** : sans elles, 10 s × 5 images donnent 2 s par palier, et le palier attribué devient du hasard réseau (principe 4). Un FormRequest champ par champ les laisserait passer.
- **Borne 3 hors du value object** : elle dépend du catalogue, qui peut rétrécir entre l'écriture et le lancement. Un retrait juridique sort un film à la seconde (10 § 6.1).
- **Bornes 4 et 5 avertissantes** : 3 s de révélation restent légales (00 § Réglages du salon) ; les avertir suffit (A-01).

Ce partage corrige 10 § 6.1, qui refusait aussi les bornes 4 et 5 (E10-26).

**Interaction des verdicts** (règle attendue par la matrice de `100`, contrat C18 § 2.7, encodée une seule fois dans son générateur). C'est la règle du code actuel, `RoomSettings::validate()` [existant], rendue normative :
- **Cumul sans court-circuit.** `validate()` cumule les refus de tous les champs ; le refus d'un champ n'en masque aucun autre.
- **`N` borné pour les bornes croisées.** Un `framesPerRound` hors bornes est refusé sous `framesPerRound`, et les bornes 1 et 2 sont évaluées avec `n̂ = RoomSettingsBounds::clampFramesPerRound(framesPerRound)`. Un `framesPerRound` absent ou non entier compte pour `DEFAULT_FRAMES_PER_ROUND`. Un `N` hors bornes n'entraîne donc un refus de `roundDuration`, `tierDurations` ou `tierPoints` que si leur valeur viole aussi les bornes calculées pour `n̂`, taille de liste comprise. Au réglage par défaut des bornes, N = 6 et D = 25 ne sont refusés que sous `framesPerRound` ; N = 6 et D = 20 le sont sous `framesPerRound` et sous `roundDuration`.
- **Un `roundDuration` refusé n'est jamais comparé à `Σ tierDurations`** : aucun `duration_mismatch` ne s'y ajoute.
- **Une liste de paliers ou de points de mauvaise taille ne produit que `list_size`** sous son champ, sans erreur par palier.
- **Les avertissements ne portent que sur une entrée acceptée.** `warnings()` est une méthode de l'instance, qui n'existe qu'après `fromInput()` réussi : une entrée refusée ne porte aucun avertissement.

### 4.2 Langue des messages

- **Bornes 1 et 2**, et toute erreur d'écriture : **réponse HTTP à l'hôte seul**, résolue côté serveur dans la langue de sa requête. `RoomSettings::translate()` résout `:attribute` par `validation.attributes.<champ>` [existant] (05 § Erreurs, validation et messages à destinataire unique).
- **Borne 3 et avertissements** : ils sont **diffusés en données** (codes, entiers), puis rendus par chaque client dans sa propre langue.
  - Le « même message pour tous » de 00 § Déroulé d'une partie désigne **le même contenu**, pas la même langue.

### 4.3 Retour immédiat côté client

`resources/js/lib/room-settings.ts` [nouveau, dans `WATCHED`] calcule **uniquement** depuis `RoomSettingsBoundsPayload`, sans aucun littéral de jeu :
- `minRoundDuration(bounds, n)` ;
- `defaultTierDurations(bounds, n, d)` ;
- `defaultTierPoints(bounds, n)` ;
- `defaultAttemptsPerRound(bounds, d)` ;
- `crossBoundErrors(bounds, input)` pour les bornes 1 et 2 ;
- `warnings(bounds, view)` ;
- `choicesAtPercent(view)`.

Le serveur reste seul juge.
- **Quand l'hôte augmente `N`** et que `D < minRoundDuration(N)`, le client remonte `D` à ce minimum dans le même envoi. Il l'annonce par `room.settings.roundDuration.raised` (`:seconds`), dans la région `aria-live` de la page (`GameAnnouncer`, contrat C16).
- Le curseur de `D` affiche le **minimum effectif**, soit 25 s à N = 5 au réglage par défaut des bornes (00 § Réglages du salon).
- **Parité serveur / client.** Le fichier `tests/Fixtures/room/derivations.json` est committé et ne contient que des entiers et des codes : aucune chaîne à afficher, les avertissements y figurant par leur code. Il porte — amendé le 28/09 (E120-1) :
  - `bounds` = `toClient()` ;
  - `cases`, une liste de cas `{ n, d, r, tierDurations, tierPoints, attemptsPerRound, warnings }`. Chacun est une entrée Simple `(N, D, R)` passée par le chemin de l'hôte : `RoomSettingsEditor::simple()` depuis les défauts, puis `fromInput()`. `r` y figure parce que `warnings()` dépend de `R`, dont le défaut n'est pas dans `RoomSettingsBoundsPayload` ;
  - `warningCases`, une liste `{ revealDuration, tierDurations, tierPoints, warnings }` passée par `fromInput()` : barèmes non décroissants ou à zéro, paliers inégaux. L'onglet Simple ne les atteint pas au J1, mais `warnings()` du client doit les rendre comme le serveur.

  Il est produit par `php artisan room:derivations-fixture` (`App\Console\Commands\RoomDerivationsFixtureCommand` [nouveau], désactivée en `production` par `isEnabled()`). La commande n'est lancée qu'à la main, après un changement voulu de `RoomSettingsBounds`, et son diff est relu dans la PR. Son option `--check` compare le rendu au fichier versionné sans rien écrire : c'est elle que le test Pest emploie — amendé le 28/09 (E120-1). Un test Pest vérifie que le serveur rend exactement ces valeurs ; un test Vitest vérifie que `lib/room-settings.ts` les rend aussi. Une divergence casse l'un des deux. **Aucun test n'écrit le fichier** : un test qui le régénérerait avant de le comparer serait tautologique.

### 4.4 Découpage du temps

- **En Simple**, les paliers sont égaux, en secondes entières, et **le dernier absorbe le reste** : c'est `defaultTierDurations(N, D)` [existant]. Au réglage par défaut, 30 s / 3 donnent 10 / 10 / 10. Avec les bornes par défaut, 40 s / 3 donnent 13 / 13 / 14, et 15 s / 2 donnent 7 / 8.
- **En Avancé** [J2], chaque durée est ajustable et `D` devient leur somme. Un dépassement de borne est **bloquant, pas averti** (borne 2).
- **Réégalisation annoncée.** Repasser d'Avancé à Simple réégalise les paliers et rapporte `equalized`. Charger un preset ou une configuration par-dessus un réglage avancé l'écrase et rapporte `overwritten` [J2] (§ 5.3, § 18.3). Aucun comportement silencieux.
- La matérialisation convertit en millisecondes au lancement (`round_tier.duration_ms = dᵢ × 1000`, `starts_at_offset_ms = tierStartOffsetMs(i)`, contrat C6 § 12.4). Le calcul du palier reste en entiers (10 § 1.2).

### 4.5 Barème et avertissements

- **Défaut** : la valeur du palier `i` sur `N` vaut `(N − i + 1) × TIER_POINTS_UNIT`. Au réglage par défaut, cela donne 300 / 200 / 100 à N = 3 et 500 / 400 / 300 / 200 / 100 à N = 5.
- Chaque valeur est éditable dans `[MIN_TIER_POINTS, MAX_TIER_POINTS]` [J2].
- **Barème non strictement décroissant.**
  - Il est autorisé et averti (`non_decreasing_points`) : un palier tardif qui vaut autant ou plus qu'un palier précédent paie l'attente.
  - Le mode « sans gradient » (tous les paliers au maximum) déclenche le même avertissement. Le bonus y classe la promptitude **à l'intérieur** de chaque palier, et attendre la frontière y paie (A-10).
- **Barème entièrement à 0** : c'est le mode « sans score » assumé (`all_tiers_zero`). Le podium bascule sur la chaîne de départage (`80`).
- **`B_max(N)`** est affiché dans l'aide du barème depuis `limits.speedBonusMaxPercent[N]` (texte `game.help.scoring.speed_bonus`, rédigé par `80`). Il n'est jamais réglable.
- **Aide de la difficulté Normal.** En Normal, le QCM apparaît à `T_N`. Le client affiche `room.settings.inputDifficulty.choices_at` (`:percent`, `:seconds`), avec `seconds = Σ_{i<N} dᵢ` et `percent = round(100 × seconds / D)`.
  - Au réglage par défaut, `T_N` tombe à 20 s, soit 67 % de la manche. Avec des paliers égaux, il tombe à 50 % à N = 2 et à 80 % à N = 5 (00 § Le jeu en une manche).
  - C'est une information, pas un avertissement du value object.

### 4.6 Calibrage `(attemptsPerRound, |pool|)`

10 § 15 nomme ce couple : c'est un budget de force brute.
- **Bornes, fixées ici** : `attemptsPerRound ∈ [MIN_ATTEMPTS_PER_ROUND, MAX_ATTEMPTS_PER_ROUND]`, défaut dérivé `defaultAttemptsPerRound(D)` ; `attemptsPerSecond ∈ [MIN, MAX_ATTEMPTS_PER_SECOND]` ; `|pool| ≥ M` (borne 3).
- **Au J1**, `attemptsPerRound` n'est pas éditable : il suit `D` (D34 du 23/09).
- Les **seuils** (sonde « manches gagnées au palier 1 après plus de dix tentatives », recalibrage après le lot pilote) appartiennent à `70`. Les bornes de 00 sont reprises sans modification tant que `70` n'a pas mesuré.

---

## 5. Presets du site

### 5.1 Les quatre presets, repris sans modification

Les cinq chiffres de chaque preset sont ceux de `SettingPresetCatalog::inputFor()` [existant], identiques à 00 § Réglages du salon et à 10 § 6.3. Tout le reste descend des défauts de `RoomSettingsBounds` pour le `N` demandé, via `RoomSettings::fromInput()`.

| Clé | Position | `D` | `N` | Saisie | `M` | `R` | Dérivés, aux bornes par défaut |
|---|---|---|---|---|---|---|---|
| `classic` | 1 | 30 | 3 | `normal` | 10 | 8 | paliers 10/10/10 ; barème 300/200/100 ; 15 tentatives |
| `fast` | 2 | 15 | 2 | `easy` | 8 | 5 | paliers 7/8 ; barème 200/100 ; 8 tentatives |
| `hardcore` | 3 | 45 | 5 | `expert` | 10 | 10 | paliers 9 × 5 ; barème 500 à 100 ; 23 tentatives |
| `discovery` | 4 | 60 | 3 | `easy` | 5 | 15 | paliers 20/20/20 ; barème 300/200/100 ; 30 tentatives |

Dans chaque preset, les thèmes valent `[]`, la capacité `roomSeats()`, `allowLateJoin` `false`, et les champs avancés leur défaut.

- **Accessibles à tous, invités compris.** Ils ne sont ni modifiables ni supprimables, **y compris par un admin**. Le seeder est le seul écrivain de `setting_preset` (10 § 6.3).
- Leurs libellés vivent dans `room.presets.<clé>.{label,description}` [existant], sans aucun chiffre. Le client affiche les chiffres depuis les réglages rendus.

### 5.2 « Jamais inlançable »

Cette promesse de 00 s'entend **des bornes du value object** (A-12) : `PresetValidityTest` fait passer chaque preset par le chemin d'entrée de l'hôte, sans erreur. Le vivier, lui, est un état d'exécution, traité par le grisage et par le `N` jouable le plus proche.

**Conséquence au J1, à connaître** : avec un catalogue en passe 1 (niveaux 1, 3 et 5 seulement), `levels_count` vaut 3. Le vivier est donc vide à N = 4 et N = 5, et **Hardcore reste grisé**, avec « jouable à 3 images par manche », tant qu'aucun film n'a fait sa passe 2. Ce n'est pas un bug.

### 5.3 Application et grisage

`POST /r/{room}/settings/preset` (`room.settings.preset`), corps `{ preset: SettingPresetKey }`, suit l'action `ApplyRoomPreset` (§ 2.5).
- L'action pré-remplit **les seize champs** avec `SettingPresetCatalog::settingsFor($key)` : les thèmes reviennent à `[]`, et la capacité à `roomSeats()`, plafond que la garde de capacité ne refuse jamais (§ 10).
- [J2] Chaque champ de `ADVANCED_KEYS` qui n'est pas dans `SIMPLE_KEYS`, personnalisé dans l'état courant et changé par le preset, est rapporté `overwritten`. « Personnalisé » veut dire différent de son défaut dérivé de `(N₀, D₀)` pour les champs dérivés, et de son défaut constant pour les autres.
- Les champs de l'onglet Simple que le preset change (thèmes, capacité, retardataires) sont visibles à l'écran et ne sont pas rapportés. Seuls les réglages invisibles le sont : sans ce rapport, l'écrasement serait silencieux.
- Le serveur **n'interdit pas** d'appliquer un preset grisé : le lobby montre alors le blocage du vivier et ses remèdes (§ 9). Le grisage est une aide, et c'est la garde de lancement qui fait autorité.

**Grisage**, calculé **au rendu** du lobby et jamais stocké (10 § 6.3) :
- `grayed = PoolReporter::report(PoolScope::forRoom($room, SettingPresetCatalog::settingsFor($key), $now), M_preset)->blocked()` ;
- `nearestPlayableFramesPerRound` est lu dans le même rapport.

Le calcul emploie **le même constructeur, la même non-répétition du salon et la même fenêtre** que la garde de lancement (contrat C2). Sinon, un preset affiché jouable se bloquerait au lancement. Prop de page : `presets: { key, grayed, nearestPlayableFramesPerRound }[]`, triée par `position`.

### 5.4 En solo

D19 du 23/09 : le joueur choisit l'un des quatre presets. Si son `N` n'est pas jouable, le `N` jouable le plus proche s'applique d'office, par `RoomSettingsEditor::simple()` (§ 3.2), et l'écran l'annonce. Le flux appartient à `60`.

---

## 6. Création du salon, code et lien

### 6.1 Salon d'abord

« Créer un salon » **crée le salon tout de suite**, avec les réglages par défaut. Tous les réglages se font ensuite dans le lobby, en direct.
- Pourquoi : un seul écran à construire et à tester, et le code se partage pendant que l'hôte règle (00 § Réglages du salon : « un débutant lance en 10 secondes »).
- L'archivage anticipé du lobby absorbe les salons créés puis abandonnés (§ 16).

[J2] Un hôte connecté qui a une configuration par défaut voit son salon créé avec elle, normalisée au chargement (§ 18.3).

### 6.2 Routes et pages

| Route | Méthode et chemin | Contrôleur (`App\Http\Controllers\Room\`) | Middleware | Réponse |
|---|---|---|---|---|
| `room.create` | GET `/r/new` | `RoomController@create` | `translations:room,legal`, `throttle:game-read` | Inertia `room/create` (`PublicLayout`) |
| `room.store` | POST `/r` | `RoomController@store` (`App\Http\Requests\Room\StoreRoomRequest`) | `throttle:room-create` | 303 → `room.show` |

- **Page `room/create`**, une seule prop : `nickname: { min, max }`, par `NicknameNormalizer::MIN_LENGTH` et `MAX_LENGTH`. Le front n'écrit jamais ces bornes en dur. **Aucun sélecteur d'avatar** : l'hôte ne saisit que son pseudo, l'avatar est attribué par le serveur à la prise de siège (§ 7.3, S6) et se change au lobby (§ 8.1) ; la prop `avatars` est retirée (D55 du 02/10 — amendé le 02/10).
- **Mention des CGU** : sous le bouton d'envoi, le texte `legal.terms_notice` **sert lui-même de texte** au lien Wayfinder vers `legal.terms`, sans second libellé (clé et page livrées par `90`, composition de 90 § 10 ; exigence de 90 aux specs voisines, demandée par 40 § 2.1). La clé est rédigée pour tenir seule sous le bouton comme pour servir de texte au lien (E16-5) : un texte suivi d'un second lien répéterait les mêmes mots — amendé le 28/09 (E101-5 ; composition à confirmer par le porteur, points restés ouverts, n° 22). Le lien s'ouvre en nouvel onglet (`target="_blank" rel="noopener"`, suffixé de `legal.new_tab` en `sr-only`, comme les liens du pied replié de 90 § 3.1) : une visite dans le même onglet perdrait le pseudo déjà saisi (amendé le 02/10). **Rien n'est stocké** : le jeton ne porte aucun consentement (40 § 2.1), et l'acceptation horodatée des CGU n'existe qu'avec les comptes (`40`, J2).
- **`StoreRoomRequest`** utilise le trait `PlayerIdentityValidationRules` (contrat C5) : `prepareForValidation()` canonicalise le pseudo, puis la requête applique `nicknameRules()` seul ; un champ `avatar` envoyé est ignoré (D55 du 02/10 — amendé le 02/10).
- `room.create` est déclarée **avant** `room.show`, et `{room}` est contraint au motif du code (§ 6.3) : `new` ne peut pas être un code.

### 6.3 Code de salon

`App\Support\Room\RoomCode` [nouveau] est **le seul générateur de production**. Les fabriques en deviennent lectrices, selon le même motif que `SettingPresetCatalog` : un chiffrage normatif n'habite pas `database/`, dont `faker` est en `require-dev`.

| Élément | Valeur | Provenance |
|---|---|---|
| `ALPHABET` | `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (32 signes, ni I, ni O, ni 0, ni 1) | 00 § Vocabulaire (« 6 caractères non ambigus ») ; déplacé de `RoomFactory::CODE_ALPHABET` |
| `LENGTH` | 6 | `room.room_code char(6)` (10 § 6.2) |
| `generate(?Closure $draw = null): string` | au plus `MAX_ATTEMPTS` candidats de `LENGTH` tirages `random_int(0, 31)`, et le premier absent de `room_code_active` est rendu (une lecture `exists` par candidat) ; au-delà, `RuntimeException`. `$draw` (source de candidats) est réservé aux tests, `null` en production : l'aléa CSPRNG ne se force pas, et il faut une collision pour prouver le recyclage. Un candidat injecté non canonique lève `LogicException` — amendé le 28/09 (E100-1) | CSPRNG ; hors de tout chemin seedé (contrat C3, qui ne s'applique qu'à `App\Support\Draw`) |
| `normalize(string): string` | majuscules ASCII seules ; espace, tabulation, sauts de ligne et de page, retour chariot et tiret-moins retirés. Rien d'Unicode, pour la parité du miroir client : `ß`, un espace insécable, un tiret typographique ou un signe pleine chasse restent en place, et le code est mal formé — amendé le 28/09 (E100-2) | saisie tolérante ; `Room::normalizeCode()` [modifié] délègue |
| `isWellFormed(string): bool` | `LENGTH` signes de `ALPHABET` après normalisation | un code mal formé répond 404 **sans requête** |
| `ROUTE_PATTERN` | `'[A-Za-z0-9 -]{6,12}'` | motif **tolérant** du paramètre `{room}` : la casse, les espaces et les tirets d'un lien saisi à la main passent le routeur, et la validation stricte a lieu à la liaison |
| `MAX_ATTEMPTS` | 8 | garde de boucle, pas une valeur de jeu |

- **Motif de route.** `Route::pattern('room', RoomCode::ROUTE_PATTERN)` est déclaré en tête de `routes/game.php`, avant toute route qui porte `{room}`, contrats C7 et C10 compris. Un motif strict tiré de `ALPHABET` renverrait 404 **avant** la liaison pour tout lien saisi en minuscules ou avec un tiret, ce qui contredirait `normalize()`. `Room::resolveRouteBinding()` [modifié] applique `normalize()` puis `isWellFormed()`, et répond 404 sans requête si le code est mal formé. `new` (trois signes) n'atteint jamais le motif.
- **Le code est recyclé à l'archivage, et par lui seul** (10 § 6.2 et § 11.1 ; 00 § Vocabulaire du projet ; contrat C7 § 2.1). Le générateur rend le premier de ses candidats absent de `room_code_active` (une lecture de clé par candidat, au plus `MAX_ATTEMPTS`). L'absence est lue, pas réservée : la course est tranchée par `room_active_code_uq` à l'insertion (« Course », ci-dessous) — amendé le 28/09 (E100-1). L'archivage, qui remet ce créneau à NULL, est l'unique événement qui rend un code réattribuable.
  - Tant qu'aucun salon actif ne porte le code, un vieux lien mène à la page « salon expiré » du salon archivé le plus récent (`room_code_idx`, seconde lecture de `resolveRouteBinding()`).
  - **Résidu assumé** : après recyclage, un lien antérieur mène au salon actif qui porte désormais ce code. À un instant donné, la probabilité qu'un code archivé soit porté par un salon actif est de l'ordre de (salons actifs) / 32⁶. Ce lien n'ouvre rien de plus qu'un code saisi au hasard (entrée libre, § 7.1), et `game-read` borne l'énumération (§ 17.3).
  - L'espace des codes (32⁶, environ 10⁹) rend ce tirage quasi immédiat.
- **Course.** Une violation de `room_active_code_uq` à l'insertion relance la transaction de création, dans la limite de `MAX_ATTEMPTS`. Au-delà, une `RuntimeException` est journalisée : c'est l'échec bruyant d'un état impossible.
- `App\Support\Room\SeatPublicId` [nouveau] joue le même rôle pour `player.public_id`. Son alphabet (base32 sans I, L, O ni U) et sa longueur (12, soit `char(12)`) sont déplacés de `PlayerFactory`. Il est **consommé aussi par `60`** pour le siège solo.
- **Miroir client** (exigence de 90 aux specs voisines, 90 § 4.7). `resources/js/lib/game/room-code.ts` [nouveau, sous `lib/game/` pour entrer dans `WATCHED`, contrat C16 § 2.11] exporte `ROOM_CODE = { alphabet, length } as const`, miroir **non autoritaire** de `RoomCode::ALPHABET` et `LENGTH` (patron du miroir `frame-geometry.ts`, 20 § 5.1), et deux fonctions qui ne lisent que ces deux constantes, sans autre littéral :
  - `normalizeRoomCode(code: string): string`, même règle que `normalize()` ;
  - `isWellFormedRoomCode(code: string): boolean`, même règle que `isWellFormed()`.

  Pourquoi : le champ de code de l'accueil (`90`) refuse un code mal formé **sans requête**, sans écrire ailleurs la longueur ni l'alphabet. Le serveur reste seul juge : un code bien formé peut répondre 404, et la liaison de route refait la normalisation et le contrôle de forme.
  - **Parité prouvée par un jeu de cas partagé**, `tests/Fixtures/room/room-codes.json` [nouveau], committé, écrit à la main, jamais par un test : une liste `{ input, normalized, wellFormed }` (minuscules, espaces, tirets, I, O, 0 et 1, longueurs fausses). Un test Pest vérifie que `RoomCode` rend exactement ces valeurs, un test Vitest que `room-code.ts` les rend aussi, et un test Pest que `ROOM_CODE` reflète `ALPHABET` et `LENGTH`. Une divergence casse l'un des trois, comme pour les dérivations des réglages (§ 4.3).

### 6.4 `CreateRoom`

`App\Actions\Room\CreateRoom` [nouveau], `handle(Request $request, string $nickname, Locale $locale, ?User $user): Room` — sans paramètre d'avatar : `TakeSeat` attribue celui du créateur (§ 7.3, S6 ; D55 du 02/10 — amendé le 02/10). **C'est l'un des deux seuls gestes qui frappent un jeton**, avec `room.join` (contrat C4 I4.1). Aucun contrôleur n'appelle `PlayerTokenManager::ensure()` : c'est la prise de siège (§ 7.3) qui frappe, après tout refus et juste avant d'écrire le siège. 40 § 2.1 étape 4, propriétaire du jeton, l'emporte sur la lettre de la signature d'origine (`handle(PlayerToken $token, …)`, jeton frappé par le contrôleur avant l'action) : une prise de siège refusée ne pose aucun `Set-Cookie` — amendé le 28/09 (E101-1). Tout se passe dans une transaction :

1. `$now` ;
2. `$settings = RoomSettings::defaults()` ([J2] ou la configuration par défaut normalisée, § 18.3) ;
3. un `Room` non persisté : `room_code = room_code_active = RoomCode::generate()`, `status = lobby` ;
4. `WriteRoomSettings::handle($room, $settings, $now)`, qui insère la ligne avec sa projection ;
5. `TakeSeat` pour le créateur (§ 7.3), avec `repairHost: false` et sans garde de capacité à franchir ;
6. `TransferHost::to($room, $seat, $now)` : **la création pose l'hôte par l'action de transfert, dans la même transaction** (E10-34bis). `room.host_player_id` n'a qu'un écrivain.

Après validation : `SeatJoined` (sans autre destinataire que le créateur, l'émission est inoffensive). Le contrôleur redirige vers `room.show`.

### 6.5 Le lien et l'indexation

- **Lien de partage** = l'URL du lobby, `/r/{code}`. Le client la construit par Wayfinder (`show.url({ room: code })`, préfixée de `window.location.origin`), jamais par concaténation. Un bouton copie le lien (`room.lobby.copy_link`) ; un bouton `navigator.share` (`room.lobby.share`) est proposé quand l'API existe.
- **Aucun préfixe de locale** : le lien se partage entre amis de langues différentes (05 § Pas de préfixe de locale). Le salon n'a pas de langue ; chaque joueur a la sienne.
- **Toute URL portant un `room_code` reste `noindex` en permanence.** Aucune route de ce document ne porte le drapeau d'indexabilité de `90`.
- **Aucun `room_code` dans un `<title>`** ni dans une balise Open Graph : le titre du lobby est `room.lobby.title`, sans code.
- `Referrer-Policy: strict-origin-when-cross-origin` est posée globalement (`90`/`100`), pour qu'un lien partagé ne fuite pas son code.

---

## 7. Entrée et prise de siège

### 7.1 Entrée libre

**Le code ou le lien suffit**, dans la limite des sièges. L'hôte ne valide pas chaque arrivée.
- Le jeu se joue entre amis, sur la confiance (principe 3).
- Une antichambre exigerait un état de siège que `PlayerConnectionState` n'a pas, pour un remède incomplet.
- **Résidu assumé** : la triche à plusieurs sièges (un humain, deux sièges sous deux jetons, quatre essais au QCM) ne se ferme pas sans donnée personnelle (10 § 7.1). La garantie « essai unique » vaut **par siège**. Les seuls remèdes produit sont la **liste des sièges visible de tous** et l'**expulsion** (§ 11.3). `60` écrit le même résidu.

### 7.2 Routes et pages

| Route | Méthode et chemin | Contrôleur | Middleware | Réponse |
|---|---|---|---|---|
| `room.show` | GET `/r/{room}` | `RoomController@show` | `translations:game,room,legal`, `throttle:game-read` (`game.appearance` retiré, D56 du 02/10 — amendé le 02/10) | voir la liste ci-dessous |
| `room.entry` | GET `/r/{room}/join` | `RoomEntryController@show` | `translations:room,legal`, `throttle:game-read` | Inertia `room/join` (`PublicLayout`), ou 303 → `room.show` pour un salon archivé ou un porteur de siège |
| `room.join` | POST `/r/{room}/join` | `RoomEntryController@store` (`App\Http\Requests\Room\JoinRoomRequest`) | `throttle:room-join` | 303 → `room.show` |

`{room}` est le `room_code`, résolu par `Room::resolveRouteBinding()` [existant]. Un code inconnu ou mal formé répond 404 (page `error` de `90`).

**Deux formulaires postent sur `room.join`** (D55 du 02/10 — amendé le 02/10) : la page `room/join` (lien partagé, pseudo seul) et la carte « Rejoindre » de l'**accueil** (`90` § 4.7), qui envoie le **code et le pseudo** en un seul envoi, l'URL étant construite par Wayfinder depuis le code normalisé par le miroir client (§ 6.3). Depuis l'accueil :
- **code inconnu** (ou mal formé côté serveur) → page `error` 404, comme aujourd'hui ; le pseudo saisi est perdu ;
- **`Kicked` et `Full`** reviennent en `errors.room` sur la page qui a posté, donc l'accueil (`back()`) ; la validation du pseudo précède la prise de siège, si bien qu'un pseudo mal formé d'un visiteur expulsé montre l'erreur de pseudo avant le refus `kicked` ;
- un visiteur qui **tient déjà un siège** dans ce salon est repris sous son ancien siège, **le pseudo saisi est ignoré** (`JoinRoomRequest::requiresIdentity()`, S3, comportement inchangé) ;
- les états `late_join` et `in_progress` ne sont plus annoncés avant l'envoi : le visiteur arrive au lobby, dont l'état « en attente de la partie suivante » ou « retardataire » le dit (§ 8.1, § 15.3).

**`room.show` rend**, dans cet ordre :
1. un salon archivé → `game/room-expired`, statut **410** (§ 16.3) ;
2. aucun siège non expulsé pour ce jeton (`PlayerTokenManager::seatIn()`, contrat C4) → 303 vers `room.entry` ;
3. sinon : `ClaimSeatTab::handle($seat, X-Seat-Token)` (contrat C7 § 4.9), puis la page **`game/lobby`** (§ 8.1), **dans tout statut non archivé**, `lobby` comme `playing`, podium compris, avec `state` construit **sur la partie** quand le salon est en `playing` (`$game` de 60 § 12.2, § 8.1) et sans partie au lobby.
   - Le salon multijoueur est **une seule page, du lobby au podium** (90 § 2.1, propriétaire de la structure de l'écran de jeu au J1 ; contrat C16 § 2.1, qui nomme `game/lobby` et `game/room-expired` pour `50`). Manche, révélation, podium et retour au lobby après « Rejouer » sont des **états** de cette page, tirés de `state` (`GameStatePacket`) et du magasin de `60`, jamais des pages distinctes.
   - Pourquoi : une visite Inertia entre deux états démonterait la page, donc la souscription Echo, l'horloge resynchronisée et le registre de l'annonceur, et ferait frapper un nouveau jeton d'onglet par `ClaimSeatTab` (90 § 2.1).
   - **Aucune page `game/room`.** 60 § 10.1 le confirme ; ce document ferme ainsi l'écart (m) de 60 § 22 bis, qu'il lui revenait d'arbitrer avec `90` (points restés ouverts, n° 0).

Pourquoi deux routes, `room.show` et `room.entry` :
- Le lobby (`game/lobby`, `GameLayout`) et le formulaire d'entrée (`room/join`, `PublicLayout`) sont deux pages de coquilles et de domaines de traduction différents (`game,room,legal` contre `room,legal`). Le motif d'origine — `game.appearance` forçait le sombre et ne devait toucher qu'une page `game/*` — est sans objet depuis D56 du 02/10 : tout le site est sombre et le middleware est retiré ; la séparation des deux routes reste — amendé le 02/10.
- **Un GET ne frappe jamais de jeton** (contrat C4 I4.1).

**Page `room/join`**, props : `room: { code }`, `entry`, `nickname: { min, max }`. Le formulaire ne porte **que le pseudo** : la prop `avatars` et le sélecteur sont retirés, l'avatar est attribué par le serveur à la prise de siège (§ 7.3, S6) et se change au lobby (§ 8.1) (D55 du 02/10 — amendé le 02/10).
- Aucun pseudo, aucun `public_id`, aucun avatar pris : un visiteur sans siège ne voit pas qui est dans le salon.
- **Mention des CGU** sous le bouton d'envoi, identique à celle de `room/create` (§ 6.2) : le texte `legal.terms_notice` sert de texte au lien Wayfinder vers `legal.terms`, en nouvel onglet, sans rien stocker (90 § 10) — amendé le 28/09 (E101-5). Elle n'accompagne que le formulaire : les états `kicked` et `full`, qui n'en ont pas, ne l'affichent pas.
- `entry` vaut, dans cet ordre de priorité :

| `entry` | Condition | Formulaire | Message |
|---|---|---|---|
| `kicked` | `PlayerTokenManager::wasKickedFrom()` | aucun | `room.join.kicked` |
| `full` | effectif présent ≥ `room.capacity` | aucun | `room.join.full` |
| `late_join` | `playing`, partie en cours, `allow_late_join`, une manche numérotée `pending` reste à démarrer, remplaçante comprise (§ 15) | oui | `room.join.late_join` |
| `in_progress` | `playing` dans tout autre cas (retardataires fermés, podium affiché) | oui | `room.join.in_progress` |
| `open` | `lobby` | oui | — |

  Cet état est **indicatif**. La prise de siège le recalcule sous verrou.

### 7.3 `TakeSeat` : la séquence normative

`App\Actions\Room\TakeSeat` [nouveau], `handle(Room $room, Request $request, ?string $nickname, Locale $locale, bool $repairHost = true): Player|JoinRefusal`. Le pseudo est nul pour une reprise, que `JoinRoomRequest` ne valide pas ; **aucun paramètre d'avatar** : `TakeSeat` l'attribue elle-même sous le verrou du salon (S6 ; D55 du 02/10 — amendé le 02/10). Elle est appelée par `CreateRoom` (avec `repairHost: false`) et par `room.join`. Elle s'exécute dans une transaction, sous l'ordre de verrouillage global `room → player → game → round → round_player` (E10-51).

**Elle frappe elle-même le jeton, jamais le contrôleur** — amendé le 28/09 (E101-1) :
- S3 lit le jeton par `PlayerTokenManager::current()`, sans frappe ; à la reprise, `ensure()` fait glisser le cookie ;
- un jeton neuf n'est frappé par `ensure()` qu'après S4 et S5, juste avant l'INSERT de S6 ;
- la re-signature avec l'avatar attribué a lieu après la validation (S10, amendé le 02/10).

Un refus (salon archivé, expulsé, complet, pseudo pris) ne pose donc aucun `Set-Cookie` (40 § 2.1 étape 4).

| Étape | Action |
|---|---|
| S1 | `Room::whereKey()->lockForUpdate()`, puis `$now` pris après le verrou. |
| S2 | Salon archivé → refus `JoinRefusal::Archived` : le contrôleur répond 303 vers `room.show`, sans erreur, qui rend « salon expiré » (410). |
| S3 | **Reprise avant tout comptage.** Siège du jeton dans ce salon (`Player::heldByToken($token)`, `room_id`). S'il est **expulsé** → refus `JoinRefusal::Kicked` (`room.join.kicked`), **avant** tout comptage et jamais par une 1062 (D15 du 23/09). S'il existe et n'est pas expulsé → **rendu tel quel, sans aucune écriture** : ni revalidation du pseudo (contrat C5 I5.5), ni garde de capacité (10 § 6.2). La séquence s'arrête là. Le retour à `connected` d'un siège déconnecté ou parti est l'affaire du battement de présence (`60`). |
| S4 | Effectif présent = `COUNT(player holdingSeat)` (`player_room_state_idx`). S'il est ≥ `room.capacity` → refus `JoinRefusal::Full` (`room.join.full`). |
| S5 | `nickname_normalized = NicknameNormalizer::normalize($nickname)` (contrat C5). Si un siège de ce salon porte déjà cette forme, **partis et expulsés compris** → `validation.nickname.taken` sous `nickname` (contrat C5 I5.4, E10-35). |
| S6 | INSERT `player` : `public_id = SeatPublicId::generate()`, `room_id`, `nickname` (forme canonique), `nickname_normalized`, `player_token_hash = $token->hash()`, `locale` = locale effective de la requête, `avatar_kind` et `avatar_preset` **attribués par le serveur sous le verrou du salon** (ci-dessous), `joined_at = last_seen_at = $now`, `connection_state = connected`. |
| S7 | Salon en `playing` : admission d'un retardataire (§ 15) ou attente de la partie suivante. En `lobby` : rien. |
| S8 | Si `$repairHost` est vrai et que `host_player_id` n'a pas de cible valide (nul, siège parti, expulsé ou absent) → `TransferHost::automatic()` (§ 11.2). `CreateRoom` passe `repairHost: false` : à la création, `host_player_id` est encore nul, et sans ce drapeau S8 poserait l'hôte une première fois avant le `TransferHost::to()` de `CreateRoom` (§ 6.4), soit deux écritures d'hôte (E10-34bis). |
| S9 | `room.last_activity_at = $now`, par mise à jour ciblée. |
| S10 | **Après validation** : `SeatJoined { seat: SeatView }` au salon (contrat C7). Le jeton est re-signé avec l'avatar attribué (`PlayerTokenManager::resign()`, `withAvatar()`, contrat C4 I4.5 ; amendé le 02/10). |

- **Attribution de l'avatar en S6** (D55 du 02/10 — amendé le 02/10 ; règle : `40` § 6.4 et § 11.4). Les clés prises sont lues sous le verrou du salon par `App\Support\Room\TakenAvatars::of($room)` (seul calcul, partagé avec le geste du lobby et la prop `avatars`, § 8.1) : `avatar_preset` de tout siège `holdingSeat()`, **prédéfini de repli des sièges `upload`/`provider` compris**. Puis :
  1. compte connecté dont `users.avatar_kind = upload` et dont l'image est visible → `avatar_kind = upload`, avec un prédéfini de repli `suggest(users.avatar_preset ?? préféré du jeton, pris)` résolu par `SeatAvatar::resolve()` ;
  2. sinon `avatar_kind = preset`, `avatar_preset = AvatarPresetCatalog::suggest(préféré du jeton, pris)`.

  Aucun champ `avatar` n'est lu dans la requête. La garde `roomSeats() ≤ avatarPresets()` (§ 2.7) garantit un prédéfini libre tant que l'effectif ne dépasse pas la capacité ; au-delà (retours de sièges, ci-dessous), `suggest()` rend un doublon, assumé.
- **Collision résiduelle.** Une `UniqueConstraintViolationException` est interceptée et suivie d'une relecture.
  - Si un siège de ce jeton existe désormais dans le salon (double envoi), c'est une reprise.
  - Sinon, c'est `validation.nickname.taken`. **Jamais une 1062 brute** (10 § 7.1).
  - **Résidu nommé** : cette collision (course hors verrou, impossible sous le verrou du salon en MySQL) survient après la frappe de S6, si bien que le `Set-Cookie` du jeton frappé part avec le refus `taken`. Le docblock de `TakeSeat` l'écrit — amendé le 28/09 (E101-1).
- **Refus** : `JoinRefusal` [nouveau], enum backed (`Archived = 'archived'`, `Kicked = 'kicked'`, `Full = 'full'`), rendu **en données** par le retour `Player|JoinRefusal`, jamais par exception. Le pseudo pris reste une `ValidationException` sous `nickname` (S5) — amendé le 28/09 (E101-1).
  - `Kicked` et `Full` : le contrôleur répond `back()->withErrors(['room' => __($refusal->messageKey())])`, où `messageKey(): ?string` rend `room.join.<valeur>`.
  - `Archived` : redirection 303 vers `room.show`, sans erreur, qui rend la page « salon expiré » (410, § 16.3). `messageKey()` rend `null` pour ce cas : aucun message ne double la page.
- **Conséquences assumées**, écrites pour ne pas être découvertes :
  - Le pseudo d'un siège parti reste réservé jusqu'à l'archivage. Un joueur qui change d'appareil ne reprend pas son pseudo dans le même salon ; le texte de `validation.nickname.taken`, rédigé par `40`, le dit.
  - Un siège repris ne consomme aucune place. L'effectif peut donc **dépasser** la capacité : capacité 2, deux entrées, un départ, une troisième entrée, puis le retour du partant, soit trois sièges tenus (test de 10 § 6.2). La capacité n'est alors réglable qu'à la hausse jusqu'à ce que l'effectif redescende, et toute autre écriture de réglages reste acceptée (§ 10).
  - **Au J1, aucun siège n'est rattaché à un compte** : `player.user_id` n'est jamais écrit (contrat C4 I4.10). Un compte connecté prend un siège comme un invité. — Amendé par D49 du 01/10 (la prise de siège d'un compte connecté écrit `player.user_id`, `40` § 11.4) et par D55 du 02/10 (le compte saisit son pseudo comme un invité, et le serveur lui attribue son image téléversée visible, S6) — amendé le 02/10.

---

## 8. Le lobby temps réel

### 8.1 La page `game/lobby`

Elle est sous `GameLayout` (contrat C16 : le lobby est sous `game/`, et aucune bascule de thème ne se produit avant la première manche). C'est la **page unique du salon, du lobby au podium** (§ 7.2, 90 § 2.1) : `50` en compose l'état de lobby ; les états de manche, de révélation et de podium affichent les composants de `60` et de `80`, dans la structure de `90`, selon l'état du magasin de `60`. Props :

```ts
type LobbyPageProps = {
  room: { code: string };
  state: GameStatePacket;              // contrat C7 : GameStateBuilder::build($game, $seat, $now, $presented) — $game NULL au lobby ;
                                       // en playing, podium compris : partie en cours du siège, à défaut dernière partie du salon (60 § 12.2)
  seatToken: string;                   // contrat C7 : ClaimSeatTab
  settings: RoomSettingsState;         // § 2.6, recalculé à chaque rendu
  bounds: RoomSettingsBoundsPayload;   // RoomSettingsBounds::toClient()
  limits: PlatformLimitsPayload;       // PlatformLimits::toArray()
  presets: { key: 'classic' | 'fast' | 'hardcore' | 'discovery'; grayed: boolean; nearestPlayableFramesPerRound: number | null }[];
  launch: { minConnected: number };    // RoomSettingsBounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH
  editor: { advancedAvailable: boolean; themeSelectorVisible: boolean; lateJoinAvailable: boolean };
  themes: { key: string; labels: Record<LocaleCode, string> }[] | null;  // [J2], non nul seulement si le sélecteur est visible
  configs: { key: string; name: string; isDefault: boolean }[] | null;   // [J2], null pour un invité
  avatars: {                           // D55 du 02/10, closure Inertia : rechargeable par only: ['avatars']
    options: AvatarPresetOption[];     // AvatarPresetCatalog::options()
    taken: string[];                   // clés tenues par les AUTRES sièges holdingSeat(), replis compris (TakenAvatars::of($room, $self))
    current: string;                   // 'account' si l'avatarRef du siège est une image upload/provider visible, sinon sa clé avatar_preset
    account: { url: string } | null;   // SeatAvatar::accountOption(seatAccount($seat, $user)), null pour un invité, une image masquée ou un compte qui n’est pas celui du siège (player.user_id)
  };
};
```

- **Au J1**, `editor` vaut `{ advancedAvailable: false, themeSelectorVisible: false, lateJoinAvailable: true }`. `lateJoinAvailable` vaut `true` au J1 comme au J2 : l'interrupteur `allowLateJoin` est livré au J1 (D35 du 23/09, § 15.4) — amendé le 23/09.
- **`$game`** est résolu par `RoomController@show` selon 60 § 12.2 : la partie en cours du siège ; à défaut, la dernière partie du salon (`game_room_started_idx`) tant que `room.status = playing`, podium compris ; NULL au lobby. Pourquoi : un rechargement en cours de manche ou sur le podium doit recevoir dans sa prop initiale la manche, le classement et le podium, puisque le montage ne déclenche aucune resynchronisation (60 § 12.6 : « le paquet est déjà la prop `state` »).
- **Le vivier est recalculé à chaque rendu** : l'entrée dans le lobby, un rechargement, le rechargement partiel qui suit un « Rejouer », et celui qui suit une resynchronisation au lobby après une reconnexion d'Echo ou un retour de visibilité ou en ligne (§ 8.2).
- Les libellés de thèmes [J2] voyagent **pour chaque locale activée**, comme les titres de la révélation : un changement de langue sans rechargement n'a rien à redemander.
- Les **sièges** viennent de `state.seats` (`SeatView`, contrat C7), triés par `joined_at` croissant par `GameStateBuilder`. `state.self.isHost` décide de la vue.
- **`avatars`** (D55 du 02/10 — amendé le 02/10) est calculée côté serveur, jamais dérivée de `state.seats` : `SeatView` ne porte pas la clé d'un prédéfini, et le prédéfini de repli d'un siège `upload`/`provider` n'y est jamais visible, alors qu'il compte comme pris. `current` applique la même règle de visibilité que `Player::avatarRef()` : une image de compte masquée, supprimée ou déliée fait redescendre `current` au prédéfini de repli, et la tuile « Mon avatar » disparaît (`account` nul).

**Changement d'avatar au lobby** (D55 du 02/10 — amendé le 02/10). Chaque siège, hôte compris, peut changer **son propre** avatar par le sélecteur `avatar-picker.tsx` (`40` § 7.4), **tant que `room.status = lobby`** : avant le lancement et après « Rejouer » (§ 13). Pendant une partie et sur le podium, le sélecteur n'est pas montré et le serveur refuse. Ce n'est pas un geste d'hôte. **Amendé le 06/10, à la demande du porteur** : le choix se fait par les **flèches ‹ › du siège du joueur dans la salle d'attente** (`cinema-seat-map.tsx`, libellés `room.lobby.avatar.previous` / `next`), qui envoient aussitôt l'avatar libre voisin — en boucle, « Mon avatar » d'abord s'il est offert, les clés tenues par un autre siège sautées (`cycleLobbyAvatar()` de `lib/game/lobby-avatars.ts`), un seul envoi en vol, refus annoncé ; le sélecteur n'est plus dans la boîte « Configurer », et `lobby-avatar-picker.tsx` est retiré. Route, action et refus ci-dessous inchangés. **Amendé encore le 06/10** : un clic sur l'avatar du joueur ouvre la grille « Votre avatar » (`avatar-dialog.tsx`, `avatar-picker.tsx`, clés prises relues à l'ouverture), où chaque tuile cochée s'applique aussitôt ; flèches et grille passent par `useSeatAvatar` (`hooks/game/use-seat-avatar.ts`) : affichage **optimiste** dérivé de la prop `avatars` (`lobbyAvatarData()`), envoi **regroupé** 350 ms après le dernier geste, un seul en vol, et réponse **légère** (`only: ['avatars']`) ; un refus est annoncé et l'affichage revient à la valeur du serveur. Un **429** du limiteur `game-write` (changements trop rapprochés) ne mène jamais à la page d'erreur : le choix est abandonné et un toast d'erreur visuel (`components/game/game-toast.tsx`, sans région `aria-live` — l'annonceur reste la seule de la page, qui reçoit le même texte) dit `room.lobby.avatar.too_fast` (« réessayez dans une minute ») ; même traitement pour la relecture des clés prises à l'ouverture de la grille.
- **Route** `room.avatar.update`, POST `/r/{room}/avatar`, `App\Http\Controllers\Room\SeatAvatarController@update`, FormRequest `App\Http\Requests\Room\ChangeSeatAvatarRequest` (champ `avatar` = clé du catalogue ou `account`, par `PlayerIdentityValidationRules::seatAvatarRules()`), middleware `seat.active` et `throttle:game-write`, policy `RoomPolicy::changeAvatar` (§ 17.1).
- **Action** `App\Actions\Room\ChangeSeatAvatar`, dans une transaction, **verrou du salon puis verrou du siège** (même ordre que `LeaveRoom` et `KickSeat`) :
  1. salon hors `lobby` → refus `RoomRefusal::NotInLobby` : 303 `back()` avec `errors.avatar` = `room.refusal.not_in_lobby` ;
  2. choix identique à l'avatar effectif → rien n'est écrit (idempotent) ;
  3. clé prédéfinie tenue par un autre siège `holdingSeat()` (`TakenAvatars::of($room, $seat)`, prédéfinis de repli des sièges `upload`/`provider` compris) → `ValidationException` sous `avatar`, `room.lobby.avatar_taken` ;
  4. `account` : résolu par `SeatAvatar::resolve()` (image visible exigée, repli `suggest(users.avatar_preset ?? préféré, pris)`) ;
  5. écriture de `player.avatar_kind` et `player.avatar_preset`, `room.last_activity_at = $now` ;
  6. après validation : `SeatUpdated { seat: SeatView }` au salon (`SeatViewPresenter::lobby()`, § 8.2), puis re-signature de la revendication `avatar` du `player_token` (`PlayerTokenManager::resign()`, `withAvatar()`, contrat C4 I4.5).

  Succès : 303 `back()` (repli `room.show`).
- **Unicité.** L'avatar est **unique entre sièges tenus**, garanti par le seul verrou du salon dans `TakeSeat` (§ 7.3, S6) et `ChangeSeatAvatar` ; **aucun index unique** en base, qui serait faux pour un siège `left` et pour les replis. **Doublons résiduels assumés**, documentés et non corrigés :
  - un siège qui revient de `left` par `RecordHeartbeat` (`60` § 3) alors que son prédéfini a été pris entre-temps : le retour reste sans écriture ;
  - un retardataire admis en partie (§ 15) face à l'identité gelée (`game_player.display_avatar_preset`) d'un siège parti, qui reste affichée dans la manche et au podium ;
  - un effectif au-delà de la taille du catalogue (retours de sièges au-delà de la capacité) : `suggest()` rend un doublon.

  Dans ces trois cas, le pseudo, unique par salon, reste le discriminant. Le sélecteur marque pris l'avatar de l'autre siège : chacun des deux peut sortir du doublon en choisissant un avatar libre au lobby.
- **Fraîcheur de `taken`.** La prop est relue avec `settings` et `presets` (§ 8.2) ; un `seat.joined` ou `seat.updated` ne la recharge pas. Un choix devenu pris entre deux relectures est refusé par le serveur, puis la page relit `avatars` (`only: ['avatars']`).

**Ce que voit l'hôte** :
- le formulaire Simple, éditable ([J2] plus l'onglet Avancé) ;
- les presets ;
- le compteur de vivier, avec les remèdes cliquables ;
- les avertissements ;
- la liste des sièges, avec pour chacun « Retirer du salon » et « Nommer hôte » ;
- le sélecteur de son propre avatar, hors partie (D55 du 02/10) ;
- « Lancer la partie » et « Quitter le salon ».

**Ce que voient les autres** :
- les mêmes réglages en lecture seule (`room.lobby.read_only`) ;
- le même compteur et le même message de blocage, **sans** bouton de remède ;
- la liste des sièges, le sélecteur de son propre avatar hors partie (D55 du 02/10) et « Quitter le salon ».

Tous voient le code, le lien de partage et le nombre de joueurs (`room.lobby.players`, `:count`, `:capacity`).

**Le bouton « Lancer la partie »** est désactivé, avec son motif affiché, quand le vivier est bloqué, quand moins de `launch.minConnected` sièges sont connectés, ou quand la prop partagée `maintenance` est vraie. C'est une aide d'affichage : le serveur relit tout sous verrou (§ 12). **« Rejouer »** l'est de même pendant un drainage (prop `maintenance`), avec le motif `common.maintenance.launch_blocked` lié par `aria-describedby`. **Tant que la prop vaut vrai**, la page la relit par un rechargement partiel de la seule prop (`only: ['maintenance']`) toutes les `heartbeatIntervalMs` (prop `realtime`) et au réveil de l'onglet, depuis l'onglet qui tient le siège seulement (sinon le rendu reprendrait la main, 60 § 12.7) : sur le podium, le geste désactivé était la seule requête qui l'aurait relue, et la page restait bloquée après la levée du drapeau jusqu'à un rechargement manuel — amendé le 28/09 (BUG-P2 de la répétition VM ; même relecture sur `game/solo`, 60 § 16.4). Au retour au lobby, un focus perdu avec l'état de partie démonté revient au titre `h1` (`tabIndex=-1`), sans être volé à un contrôle qui l'a gardé — amendé le 28/09 (E115-4, E115-6).

**États obligatoires**, barre « terminé » :
- **chargement** — amendé le 28/09 (E120-3) :
  - `processing` des boutons ;
  - la section des réglages est occupée (`aria-busy`) pendant un envoi, et un seul envoi est en vol. Un geste fait pendant l'envoi est mis en file, fusionné avec les suivants, puis part seul à la réponse ; un refus (validation, 409, réseau, annulation) abandonne la file et rend l'affichage au serveur ;
  - les contrôles **ne sont jamais désactivés par l'envoi** et gardent le focus : Radix retire de la tabulation une poignée désactivée. Seuls les désactivent le rôle (non-hôte), l'onglet supplanté et la déconnexion ;
- **erreur** : erreurs de validation liées au champ par `aria-describedby` ; refus de réglages, de gestes d'hôte, de lancement ou de « Rejouer » (erreur `room`, § 12.5) rendus **dans la page** en `Alert` au rôle `note` (`room.refusal.*`, `room.lobby.cannot_kick_self`, `room.errors.launch_failed` ou `common.maintenance.launch_blocked`) et annoncés par `announce()` ; **jamais de toast** : aucun `Toaster` n'est monté sous `GameLayout`, pour qu'une page de jeu n'ait qu'une région `aria-live` (90 § 2.3, contrat C16 § 4), si bien qu'un toast ne s'afficherait pas ;
- **changement d'avatar** (D55 du 02/10 — amendé le 02/10) : le choix part par un bouton de confirmation (`room.lobby.avatar.apply`), jamais à chaque changement de valeur — la primitive coche l'option qu'elle focalise aux flèches ; les tuiles ne sont jamais désactivées par l'envoi ; tant que le sélecteur est ouvert, la prop `avatars` est relue (`only: ['avatars']`) à chaque changement d'avatar, de présence ou d'expulsion d'un autre siège reçu du salon ; envoi en cours (`processing`, un seul en vol) ; refus « déjà pris » (`room.lobby.avatar_taken`) et refus « partie en cours » (`room.refusal.not_in_lobby`) sous `errors.avatar`, rendus dans la page en `Alert` au rôle `note` et annoncés par `announce()`, jamais en toast ; avatars pris marqués par texte (`common.avatar.picker.taken`), non sélectionnables ;
- **déconnexion** : `ConnectionBanner` de `90`, contrôles d'hôte désactivés tant que l'état n'est pas `connected` ;
- **onglet supplanté** : réponse 409 `seat_superseded` du middleware `seat.active` (contrat C7), interceptée par le rappel `onHttpException` de chaque requête du lobby ; le lobby passe en lecture seule (`ReadOnlyNotice`). Sans cette interception, Inertia ouvrirait sa fenêtre d'erreur brute (§ 12.5).

**Clavier et accessibilité (principe 8)** :
- curseurs `slider` exposant valeur, bornes et pas ;
- `N`, la difficulté de saisie et le sélecteur d'avatar en `radio-group` ;
- cibles d'au moins 44 px ;
- changements d'hôte, changements de réglages vus par un non-hôte (`room.lobby.settings_updated`) et `D` remonté annoncés dans l'unique région `aria-live` (`GameAnnouncer`).

Composants [nouveaux], sous `resources/js/components/room/` : `room-settings-form.tsx`, `preset-picker.tsx`, `pool-status.tsx`, `settings-warnings.tsx`, `settings-changes.tsx`, `seat-list.tsx`, `seat-actions.tsx`, `share-code.tsx`, `replay-button.tsx` ; le lobby compose aussi `avatar-picker.tsx` de `40` (`components/game/`, liste close de `90`), alimenté par la prop `avatars` (D55 du 02/10 — amendé le 02/10). Ils ne composent que la liste close de `90` (contrat C16 § 2.9) et ne lisent ni Echo ni horloge. Les souscriptions vivent dans `resources/js/hooks/game/use-lobby-state.ts` [nouveau], en `useSyncExternalStore` sur le magasin de `60` (`lib/game/store.ts`), idempotent sous React Compiler et le mode strict.

### 8.2 Messages émis par `50`

Ce document fixe le **contenu en données** de chaque message de lobby. `60` en fixe le nom, le canal, l'enveloppe et le transport (contrat C7). Toutes ces émissions ont lieu **après validation de la transaction** (`ShouldDispatchAfterCommit`).

| Événement (contrat C7) | Canal | Émis par | Charge |
|---|---|---|---|
| `seat.joined` | salon | `TakeSeat` (création, entrée, admission d'un retardataire) | `{ seat: SeatView }` |
| `seat.updated` | salon | `KickSeat`, `LeaveRoom`, `ChangeSeatAvatar` (D55 du 02/10 — amendé le 02/10 ; les transitions de présence appartiennent à `60`) | `{ seat: SeatView }` |
| `host.changed` | salon | `TransferHost` | `{ hostPublicId, previousHostPublicId }` |
| `settings.changed` | salon | `BroadcastLobbyState` (anti-rebond, § 8.3), après toute écriture de réglages et après un refus `pool_insufficient` | `RoomSettingsState` |
| `room.replayed` | salon | `ReplayRoom` | `RoomSettingsState` recalculé |
| `game.launched` | salon | `LaunchGame` | charge de `60` |
| `room.archived` | salon | `ArchiveRoom` | `{}` |
| `seat.kicked` | **siège** | `KickSeat` | `{}` |

**Réactions du client du lobby :**

| Message ou déclencheur | Réaction |
|---|---|
| `game.launched` | **Aucune visite** : le magasin de `60` passe à l'état de manche, dans la même page `game/lobby` (§ 7.2). |
| `room.replayed` | **Aucune visite** : le magasin de `60` repasse à l'état de lobby, et `settings` est pris dans la charge (`RoomSettingsState`). Le client relit ensuite `room.state` (motif `replayed`), puis recharge les props qui ne voyagent pas dans l'événement par `router.reload({ only: ['settings', 'presets', 'avatars'] })` (`avatars` ajoutée par D55 du 02/10 — amendé le 02/10), sous l'en-tête `X-Seat-Token`, jamais depuis un onglet supplanté. La relecture vient de ce que `room.replayed` ne porte aucun siège : ceux que garde le magasin sont les sièges gelés de la partie, qui omettent un siège entré sans participation. « Joueurs (n sur c) » et `room.lobby.need_players` seraient alors faux, alors que le paquet relu porte les sièges du salon. Un paquet de relecture qui ramène lui-même au lobby n'en déclenche pas une seconde — amendé le 28/09 (E110-8). |
| Reconnexion d'Echo, retour de visibilité ou en ligne, en état de lobby (y compris après un paquet qui ramène au lobby) | Après la resynchronisation `room.state` de `60` (60 § 12.6), `router.reload({ only: ['settings', 'presets', 'avatars'] })` sous l'en-tête `X-Seat-Token` (amendé le 02/10). Pourquoi : `GameStatePacket` ne porte pas `RoomSettingsState` (60 § 12.1), et un `settings.changed` ou un `room.replayed` manqué pendant la coupure n'est jamais rejoué ; sans ce rechargement, le compteur de vivier, le blocage et les presets grisés resteraient périmés jusqu'au déclencheur suivant. |
| `room.archived` | Visite de `room.show`, qui rend « salon expiré » : le joueur quitte le salon, une nouvelle page est attendue. |
| `seat.kicked` | Le client quitte les canaux, puis visite `room.show`, qui redirige vers `room/join` en état `kicked`. |

**Toute requête du lobby présente l'en-tête `X-Seat-Token`** : visites, rechargements partiels et écritures. Le jeton d'onglet est gardé en mémoire, jamais dans `localStorage` (contrat C7 § 4.9). Sans cet en-tête, un rechargement de `room.show` supplanterait son propre onglet.

**Réévaluation de la fin anticipée après un départ ou une expulsion** (contrat C7 § 4.7 ; l'effet sur la manche appartient à `60`, boundary map n° 24, qui prescrit cet appel en 60 § 13.4 et § 19.6). Après validation de sa transaction, `KickSeat` ou `LeaveRoom` cherche la manche `running` de la partie en cours où le siège a une ligne `round_player`. S'il y en a une, l'action ouvre une **seconde** transaction, reprend `round … lockForUpdate()` et appelle `App\Actions\Game\SeatInputClosed::handle($round, $roundPlayer, null, $now)`, `$now` étant le `left_at` écrit. Avec `$guess` nul, aucun `player.locked` n'est émis. **Aucun événement de domaine n'est ajouté** : c'est l'appel direct de la signature figée du contrat C7 § 2.5. Le geste de `50` ne tient donc jamais le verrou de manche dans sa propre transaction, et le moteur réévalue sous le sien.

### 8.3 Diffusion anti-rebondie

Un curseur ne doit pas produire une rafale d'événements (00 § Déroulé d'une partie).

1. **L'écriture est immédiate**, et c'est la **diffusion** qui est coalescée par salon.
2. Après validation de chaque écriture de réglages, l'action dispatche `App\Jobs\Game\BroadcastLobbyState` (nom et forme de `60`, contrat C7 § 2.5) :
   - `DB::afterCommit(fn () => BroadcastLobbyState::dispatch($room->id)->delay($availableAt))`, avec `$availableAt = $now->addMilliseconds(PlatformLimits::lobbyBroadcastDebounceMs())->ceilSecond()` calculé dans la transaction, `$now` pris après le verrou ;
   - file `game`, unique par salon jusqu'à son traitement.

   **Le dispatch entier est différé à la validation**, et non la seule mise en file par `->afterCommit()` sur le job — amendé le 28/09 (E86-6) :
   - `->afterCommit()` ne diffère que la mise en file, alors que le verrou d'unicité est pris à la destruction du `PendingDispatch`, donc **dans** la transaction ;
   - un job déjà en attente pouvait alors être traité avant la validation d'une écriture qui avait sauté son dispatch (verrou tenu) : il relisait l'état validé sans elle, et plus rien n'était en file ;
   - avec l'enveloppe, la prise du verrou suit toujours la validation.

   La formule est écrite une fois, dans `UpdateRoomSettings::dispatchLobbyBroadcast()` [nom libre]. L'appellent `UpdateRoomSettings`, `ApplyRoomPreset` et, après la transaction de lancement, `LaunchController` (`settings_outdated`, `pool_insufficient`), où le dispatch est immédiat faute de transaction ouverte (E86-2).

   **Jamais `->delay(<entier>)`** : Laravel lit un entier en **secondes** (`InteractsWithTime::availableAt()`, vérifié dans `vendor/`), et 300 deviendrait cinq minutes. `availableAt()` tronque en outre tout instant à la seconde (`getTimestamp()`, relevé aussi par l'encadré d'état réel de `60`) : l'arrondi à la seconde supérieure garantit une fenêtre effective comprise entre `lobbyBroadcastDebounceMs()` et `lobbyBroadcastDebounceMs()` + 1 s, jamais plus courte. La lettre du contrat C7 § 2.5 (`delay(PlatformLimits::lobbyBroadcastDebounceMs())`) est signalée au porteur (points restés ouverts, n° 11).
3. Toute écriture qui survient pendant la fenêtre ne dispatche rien, puisque le verrou d'unicité est tenu. Le job **relit l'état au moment d'émettre** (`RoomSettingsPresenter::state()`) : le dernier état est toujours celui qui part.
4. Une écriture postérieure au début du traitement dispatche un nouveau job : la cohérence est finale, sans numéro de révision stocké.
5. Le job ne fait rien si le salon n'est plus en `lobby`.
6. **Jamais sur la file `default`** : derrière un traitement Imagick, cette fenêtre deviendrait plusieurs secondes.
7. En développement, `composer dev` écoute les files `game` et `default` selon l'exigence de `60` à `100` (60 § 19.5) ; une file lente peut y dépasser la fenêtre, ce qui est accepté.

L'auteur n'attend pas la diffusion : sa réponse HTTP (redirection `back()`) recharge les props `settings` du lobby, et le flash `settingsChanges` porte son rapport. La diffusion qui lui revient ensuite est idempotente.

Le client envoie un curseur **à la validation du geste**, jamais à chaque pas, par `router.patch()` avec `preserveState`, `preserveScroll` et l'en-tête `X-Seat-Token`. Le geste est validé à `onValueCommit` au pointeur, et au clavier au relâchement de la touche ou à la perte du focus : Radix appelle `onValueCommit` à chaque touche, et une flèche tenue épuiserait le limiteur `game-write`, partagé avec les battements — amendé le 28/09 (E120-3).

Les autres champs de l'onglet Simple (`N`, difficulté, retardataires) écrivent aussi au geste par `router.patch()`, et `<Form>` sert les presets : un clic, un envoi, le bouton cliqué portant la clé (`CLAUDE.md` §5) — amendé le 28/09 (E120-2). Deux raisons :
- aucun bouton d'envoi n'existe, puisque l'écriture est immédiate ;
- le corps JSON garde entiers et booléens, là où un `FormData` enverrait `framesPerRound` en chaîne et un booléen faux par absence.

---

## 9. Le vivier au lobby

Le calcul appartient à `30` (contrat C2) : prédicat unique, compte en **œuvres**, fenêtre de mémoire du salon, `N` jouable le plus proche. Ce chapitre fixe ses **déclencheurs**, son **affichage** et le **blocage**.

### 9.1 Déclencheurs

Le rapport est recalculé :
- à **chaque écriture de réglages**, par la diffusion anti-rebondie, quels que soient les champs touchés : thèmes, `N`, `M` ;
- à **chaque rendu** du lobby, rechargement partiel compris, dont celui qui suit une resynchronisation au lobby (§ 8.2) ;
- au **refus `pool_insufficient`** du lancement, qui diffuse l'état recalculé (§ 12.3, O3) ;
- au **« Rejouer »**, puisque la non-répétition réduit le vivier (§ 13).

**Un événement de catalogue n'est pas poussé aux lobbies ouverts** (contrat C2) : publier ou retirer un film ne diffuse rien. Le prochain déclencheur rattrape le compteur, et la garde de lancement fait seule autorité.

### 9.2 Compteur et blocage pour tous

- **Compteur** : `room.pool.counter` (`:playable` = `pool.count`, `:required` = `pool.roundsCount`), rendu par chaque client.
- **Blocage** : si `pool.blocked`, le lancement est **bloqué pour tout le monde**, avec le même contenu pour tous (`room.pool.blocked`). Le message **nomme le réglage fautif**, une ligne par cas de `pool.causes`, dans l'ordre fixe de `30` (contrat C2 § 3 : `noRepeatMovies`, `themeKeys`, `framesPerRound`, `roundsCount`) : `room.pool.cause.<PoolFault>`.
- Passer de N = 3 à N = 5 peut faire tomber le vivier de 47 œuvres à 4. L'hôte doit comprendre que ce n'est pas un problème de thèmes.
- **`causes` vide** alors que le vivier est bloqué : `room.pool.no_remedy`. Aucun réglage ne débloque à lui seul, le catalogue est insuffisant.
- **Thème élagué** : si `pool.themesPruned`, le lobby affiche à tous `room.pool.themes_pruned`, sans placeholder, que le vivier soit bloqué ou non. Pourquoi : 30 § 3.3 (clause 3) élague à la lecture un thème dépublié resté dans les réglages ; sans ce message, l'hôte verrait un vivier élargi sans comprendre pourquoi (« jamais de comportement silencieux »). Au J1, où le sélecteur est masqué, le cas n'est atteignable que par une clé de thème postée à la main (30 § 1.4).

**Remèdes** (`pool.remedies`, contrat C2). Chacun débloque à lui seul (30 § 4.3 ; pour `open_new_room`, une fois les réglages reportés dans le nouveau salon, voir sa ligne), porte `:count` et n'est cliquable que par l'hôte ; `lower_frames_per_round` et `reduce_rounds_count` portent aussi `:value`, qui vaut `null` pour les trois autres (contrat C2 § 3). Les placeholders sont donc définis clé par clé (§ 20.3), écart de forme à C0 § 2 signalé au porteur (points restés ouverts, n° 10) :

| `PoolRemedyKind` | Clé | Geste proposé |
|---|---|---|
| `open_new_room` | `room.pool.remedy.open_new_room` | lien vers `room.create` : un nouveau salon a une mémoire vide. Il naît aux réglages par défaut (§ 6.1) ; `:count` est le vivier **aux réglages courants** sans la non-répétition (30 § 4.3), que l'hôte réapplique dans le nouveau salon, et le texte le dit (§ 20.3) |
| `disable_no_repeat` | `room.pool.remedy.disable_no_repeat` | [J2] `PATCH` `{ advanced: true, noRepeatMovies: false }` ; **retiré au J1** par le présentateur (D28 du 23/09) |
| `clear_themes` | `room.pool.remedy.clear_themes` | `PATCH` `{ themeKeys: [] }` ; **rendu dès le J1** : le serveur accepte `themeKeys` au J1, et une clé de thème postée produit la cause `themeKeys` et ce remède (30 § 1.4) |
| `lower_frames_per_round` | `room.pool.remedy.lower_frames_per_round` | `PATCH` `{ framesPerRound: value }`, `D` restant valide puisque `minRoundDuration` décroît avec `N` |
| `reduce_rounds_count` | `room.pool.remedy.reduce_rounds_count` | `PATCH` `{ roundsCount: value }` ; réduire `M` est aussi légitime qu'élargir les thèmes (00 § Réglages du salon) |

### 9.3 Non-répétition au J1 (D28 du 23/09)

La non-répétition est activée par défaut et non modifiable au J1, puisqu'elle est dans l'Avancé. Avec les 60 films publiés en passe 1 visés par le J1 (nombre réglé par le verdict du lot pilote, D10 du 23/09, seul ajustement du J1 restant, qui porte sur la curation, D35 du 23/09 — amendé le 23/09) et au réglage par défaut (`M` = 10), un salon qui rejoue se bloque au bout de six parties environ.

- Quand elle est la **seule** cause, le message la nomme (`noRepeatMovies`, en données) et propose **un nouveau salon**. Au J2, il propose en plus l'interrupteur de l'onglet Avancé.
- Aucun réglage ne change d'onglet, et le tableau de 00 reste intact (A-13).
- « Jamais de comportement silencieux » interdit de bloquer sans nommer la vraie cause.

### 9.4 Vivier exactement égal à `M`

Aucun avertissement. La marge de substitution se tire dans la limite du vivier et ne bloque jamais un lancement (A-11). Un vivier égal à `M` rend seulement impossible le remplacement d'une manche annulée.

### 9.5 Sélecteur de thèmes

- **[J1]** Le sélecteur n'est pas livré. Le serveur accepte néanmoins `themeKeys` : c'est un état de jalon, jamais une règle de jeu (00 § Jalons et budget-temps).
- **[J2]** Il est visible si `PoolReporter::themeSelectorVisible()` (contrat C2, seuil `PlatformLimits::themeSelectorMinPool()`, mesuré sur le vivier catalogue sans thème au `N` par défaut). Recalculé au rendu, il réapparaît de lui-même quand le seuil est franchi.
- Liste des thèmes proposables : `App\Support\Draw\ProposableThemes::all()` (`30`), qui rend `{ key, labels }` avec les libellés de chaque locale activée, sans aucun `id` (10 § 1.1). C'est la prop `themes` du lobby, telle quelle.

---

## 10. Capacité bornée par l'effectif présent

- **La capacité n'est jamais abaissée sous l'effectif présent** (00 § Réglages du salon ; 10 § A16 ; contrat C0 § 4, invariant 3). La garde vit **hors du value object**, qui ne voit pas l'effectif. Elle est évaluée **sous le verrou du salon**, dans `UpdateRoomSettings` et `ApplyRoomPreset`, et ne refuse qu'une écriture qui **baisse** la capacité sous l'effectif :
  `new.capacity < current.capacity ∧ new.capacity < COUNT(player holdingSeat) ∧ new.capacity < PlatformLimits::roomSeats()` → `validation.room_settings.capacity_below_headcount` (`:count`) sous `capacity`.
  - Pourquoi « baisse » : l'effectif peut légitimement dépasser la capacité (retour d'un siège parti, § 7.3), et l'éditeur réécrit toujours la capacité courante (`input = current.toPayload() ⊕ posted`, § 3.2). Une garde qui comparerait la seule valeur résultante à l'effectif refuserait alors **toute** écriture, y compris un changement de `N`, de `M` ou de `D` et les remèdes de vivier, sous un champ que l'hôte n'a pas touché.
  - Pourquoi la troisième clause : la garde ne refuse jamais le plafond `roomSeats()`, plus haute valeur que le value object accepte ; le refuser ne laisserait aucune capacité valide.
  - Cette lecture précise l'invariant 3 de C0 § 4 sans le changer pour un hôte qui règle la capacité ; elle est signalée au porteur (points restés ouverts, n° 12).
- **Aucun joueur n'est jamais expulsé par un changement de capacité.** L'expulsion reste un geste explicite de l'hôte (§ 11.3).
- Côté client, le minimum du curseur vaut `min(capacity.max, max(capacity.min, min(effectif présent, settings.settings.capacity)))`, `capacity` étant lu dans `bounds.byFramesPerRound[String(settings.settings.framesPerRound)]`. La borne par `capacity.max` (le plafond `roomSeats()`) évite `min > max` quand un plafond de plateforme abaissé passe sous une capacité ancienne — amendé le 28/09 (E120-7). L'effectif est lu dans `state.seats` (sièges dont `connection ≠ 'left'`).
- **Plafond de plateforme abaissé.** La borne haute de `capacity` est `PlatformLimits::roomSeats()`, surchargeable par `game.platform.room_seats` sans commit, donc hors de `VERSION` (§ 2.1, règle 4). Si elle descend sous la capacité stockée d'un salon ouvert, `RoomSettingsEditor::simple()` ramène la capacité **non postée** à `roomSeats()` et la rapporte `clamped` ; un preset la pose de toute façon à `roomSeats()`. Ce rattrapage n'est pas un geste de l'hôte : la garde ne le refuse jamais (troisième clause), l'effectif peut ensuite dépasser la capacité comme au § 7.3, et aucun joueur n'est expulsé. Sans lui, `fromInput()` refuserait sous `capacity` toute écriture de réglages de ce salon.
- [J2] Au chargement d'une configuration, la capacité est **relevée** à l'effectif présent, dans la limite de `roomSeats()`, et rapportée `raised`. Ce n'est jamais un refus (§ 18.3).
- **Aucun compteur dénormalisé** (10 § 6.2).

---

## 11. Pouvoirs de l'hôte

### 11.1 L'hôte est un siège

`room.host_player_id` désigne le siège hôte. C'est une référence souple, sans clé étrangère (10 § 1.5).
- Une lecture qui ne trouve pas de cible valide déclenche un transfert, **jamais une erreur**. Une cible est invalide si elle est nulle, partie, expulsée ou absente.
- Toute autorité d'hôte est **relue sous le verrou du salon** à chaque écriture. La policy HTTP ne suffit jamais (§ 17).

**Pouvoirs de l'hôte**, liste close :

| Geste | En lobby | En partie |
|---|---|---|
| écrire les réglages et appliquer un preset | oui | non : réglages figés (§ 12.7) |
| lancer | oui | — |
| expulser | oui | oui |
| transférer le rôle | oui | oui |
| « manche suivante » | — | oui, pendant la révélation seulement : il raccourcit `R`, jamais `D` (policy `advanceRound`, effet propre à `60`) |
| « Rejouer » | — | oui, podium affiché |

Il n'existe **ni** état « prêt », **ni** dissolution du salon par l'hôte, **ni** chat ou réaction (00 § Hors périmètre v1). Chaque joueur peut quitter (§ 11.4).

**« Aucune commande de l'hôte ne touche une manche en cours. »** Toucher une manche, c'est modifier son horloge, ses paliers ou des scores. L'expulsion n'en fait rien : c'est un **départ forcé**, dont l'effet sur les participants est celui de tout départ (00 § Cycle de vie et cas limites). Le moteur en tire la réévaluation de la fin anticipée (§ 8.2).

### 11.2 `TransferHost`

`App\Actions\Room\TransferHost` [nouveau]. C'est **l'unique écrivain** de `room.host_player_id` (10 § 6.2, E10-34bis). Tous ses gestes exigent une transaction ouverte et le salon verrouillé par l'appelant.

| Méthode | Appelée par | Règle |
|---|---|---|
| `to(Room $room, Player $seat, CarbonImmutable $now): void` | `CreateRoom` ; transfert manuel (`HandOverHost`, § 11.4) | La cible appartient au salon, n'est pas expulsée, et est `connected` (pour la création : le siège vient de naître). |
| `automatic(Room $room, CarbonImmutable $now): ?Player` | départ de l'hôte (`LeaveRoom`) ; lecture sans cible (`TakeSeat` S8, `LaunchGame` L3, `ReplayRoom` R3, rendu de `room.show`) ; passage de l'hôte à `left` par la présence (`60`, exigence ci-dessous) | Cible = siège `connected` non expulsé de `joined_at` le plus ancien (puis `id`), l'hôte courant exclu. À défaut, le siège non parti (`disconnected`) le plus ancien. À défaut, `NULL`. |
| `clear(Room $room, CarbonImmutable $now): void` | `ArchiveRoom` | `host_player_id = NULL`. |

**Règles de fond**, toutes issues de 00 § Cycle de vie et cas limites :
- **Transfert automatique au départ, jamais à la déconnexion.** Un hôte `disconnected` reste hôte pendant `disconnectGraceSeconds`. Le délai du lobby est le même qu'en partie : une seule notion de délai, et non deux horloges.
- **L'ancien hôte ne récupère pas le rôle** à son retour. Il ne le retrouve que si le rôle est vacant et qu'il est alors le plus ancien connecté.
- **À deux joueurs**, le joueur restant devient hôte, avec des réglages verrouillés jusqu'au « Rejouer » ; l'interface de `60` le lui dit.
- Tout changement effectif émet `host.changed { hostPublicId, previousHostPublicId }` après validation. Un transfert vers `NULL` n'émet rien : il n'y a personne à prévenir, ou c'est l'archivage.

**Exigence à `60`** : quand la présence fait passer le siège hôte à `left`, `60` appelle `TransferHost::automatic()` dans la même transaction.

### 11.3 Expulsion (D15 du 23/09)

Route `room.players.kick`, `POST /r/{room}/players/{target}/kick`. `{target}` est le `public_id` du siège **visé**, résolu **dans ce salon** (404 sinon).
- **Le paramètre ne s'appelle jamais `{player}`** : `seat.active` (contrat C7 § 2.4, R-43) lit `{player}` comme le siège du **demandeur** et exige qu'il soit tenu par le jeton courant. Le jeton de l'hôte ne tenant jamais le siège de la cible, toute expulsion serait refusée, et D15 du 23/09 serait inopérant.
- Le siège de l'hôte est résolu par `{room}` (`PlayerTokenManager::seatIn()`, contrat C4).

Action `App\Actions\Room\KickSeat` [nouveau], `handle(Room $room, Player $host, Player $target): void`, dans une transaction :

1. verrou du salon, puis `$now` ;
2. autorité d'hôte relue (`not_host`, 403) ;
3. la cible est l'hôte lui-même → `back()->withErrors(['room' => __('room.lobby.cannot_kick_self')])`, jamais un 422 brut (§ 12.5) ; l'interface ne l'offre jamais ;
4. verrou de la ligne `player` de la cible, **relue dans ce salon** (404 sinon) ;
5. la cible, **lue sur cette ligne verrouillée**, est déjà expulsée → **aucune écriture** (idempotent). L'instance reçue est chargée par le contrôleur avant le verrou du salon : deux expulsions concurrentes s'y sérialisent, et la seconde, lisant `kicked_at` nul sur son instance périmée, réécrirait `kicked_at` à un instant postérieur (contre C4 I4.9). Aucune écriture n'a lieu avant ce test, et l'ordre `room → player` est inchangé — amendé le 28/09 (E111-2 : étapes 4 et 5 permutées) ;
6. `connection_state = left`, `left_at = kicked_at = $now` (même instant, en millisecondes, contrat C4 I4.9, E10-01) ;
7. **dernière partie du salon, relue en `lockForUpdate`** après le verrou du siège (ordre global `room → player → game`, E10-51). Si son `ended_at` est NULL **sous ce verrou** : `game_player.status = kicked`, **points conservés**, par mise à jour ciblée. Sinon, aucune écriture sur `game_player` : l'issue figée par `FinalizeGame` (qui gèle sous le verrou de `game`, 80 § 10) n'est jamais réécrite, et sans ce verrou un gel concurrent validé entre la lecture de `ended_at` et l'écriture serait réécrit après coup ;
8. `room.last_activity_at = $now`.

**Après validation** : `seat.updated` au salon (`SeatView` avec `kicked: true`) et `seat.kicked` au seul siège expulsé ; puis la réévaluation de la fin anticipée (§ 8.2).

**Effets durables :**
- **Le jeton expulsé est refusé dans ce salon jusqu'à l'archivage.** `seatIn()` rend `null`, et la prise de siège refuse par `room.join.kicked` avant tout comptage (§ 7.3).
- **Pas de réadmission** : `kicked_at` n'est jamais remis à `NULL`. Le refus tombe de lui-même à l'archivage, qui efface le hash du jeton.
- Un autre jeton (fenêtre privée) reste possible : c'est le résidu de 10 § 7.1.
- Un expulsé resté abonné continue de recevoir les diffusions, dont aucune n'apprend la réponse avant la révélation. Ce résidu est écrit par `60` (contrat C7 § 4.13).
- **Les partis et les expulsés restent classés avec leurs points** (`80`).

### 11.4 Départ volontaire et transfert manuel

- **Départ** : `room.leave`, `POST /r/{room}/leave`, action `App\Actions\Room\LeaveRoom` [nouveau], `handle(Room $room, Player $seat): void`. Sous verrou du salon puis du siège, `$now` pris après les verrous :
  - `connection_state = left`, `left_at = $now` ;
  - dernière partie du salon relue en `lockForUpdate` après le verrou du siège, comme à l'étape 7 de `KickSeat` : si son `ended_at` est NULL sous ce verrou, `game_player.status = left`, points conservés ; sinon, aucune écriture sur `game_player` ;
  - si c'était l'hôte, `TransferHost::automatic()` ;
  - **idempotent** : un siège déjà `left` (ou expulsé) n'est pas réécrit, `left_at` reste l'instant du premier départ, aucun message ; un salon archivé : rien — amendé le 28/09 (E111-3).

  Après validation : `host.changed` le cas échéant, puis `seat.updated`, dont la vue est composée après le transfert (le partant n'y est plus `isHost`) : les deux messages s'accordent dans quelque ordre que le client les applique — amendé le 28/09 (E111-3). Puis la réévaluation de la fin anticipée (§ 8.2). Réponse : 303 vers `home`. Le partant peut revenir par le lien : c'est une reprise, dont l'effet en partie appartient à `60`.
- **Transfert manuel** : `room.host.transfer`, `POST /r/{room}/host`, corps `{ publicId }`. La confirmation se fait côté client (`room.lobby.transfer_confirm`). Action `App\Actions\Room\HandOverHost` [nouveau] (nom libre pour `50`, contrat C6 § 8), `handle(Room $room, Player $host, string $targetPublicId): void`, dans une transaction :
  1. verrou du salon, puis `$now` ;
  2. autorité d'hôte relue sous verrou (`not_host`, 403 ; § 11.1) ;
  3. cible résolue par `public_id` **dans ce salon**, puis verrou de sa ligne `player` (ordre `room → player`) ; une cible absente répond 404 ;
  4. cible = hôte courant → **aucune écriture** (idempotent) ; cible non `connected` ou expulsée → `ValidationException` sous `publicId` (`room.lobby.transfer_unavailable`) ;
  5. `TransferHost::to($room, $cible, $now)` ;
  6. `room.last_activity_at = $now`, par mise à jour ciblée (source d'activité, § 16.1).

  Après validation : `host.changed`. Réponse : `back()` (303).

---

## 12. Lancement (contrat C6)

### 12.1 Noms

- `App\Actions\Room\LaunchGame` [nouveau] : `public function handle(Room $room, Player $requester): LaunchOutcome`.
- `App\Actions\Game\OpenGame` [nouveau] : **seul code qui insère dans `game`**. Le solo de `60` (`StartSoloGame`) l'appelle aussi.
  ```php
  /** @param \Illuminate\Database\Eloquent\Collection<int, Player> $seats */
  public function handle(GameMode $mode, ?Room $room, RoomSettings $settings,
                         \Illuminate\Database\Eloquent\Collection $seats, CarbonImmutable $now): LaunchOutcome;
  ```
- `App\ValueObjects\Room\LaunchOutcome` [nouveau], `final readonly`, de propriétés `?Game $game`, `?RoomRefusal $refusal`, `array<string,int> $replace`, `?PoolReport $pool` et `array<string,string> $changes`. Constructeurs `launched(Game $game): self` et `refused(RoomRefusal $r, array $replace = [], ?PoolReport $pool = null, array $changes = []): self`, et `isLaunched(): bool`.
- `App\Enums\RoomRefusal: string` [nouveau] : `NotHost = 'not_host'`, `RoomArchived = 'room_archived'`, `NotInLobby = 'not_in_lobby'`, `SettingsOutdated = 'settings_outdated'`, `NotEnoughPlayers = 'not_enough_players'`, `Draining = 'draining'`, `PoolInsufficient = 'pool_insufficient'`, `GameNotEnded = 'game_not_ended'`. `messageKey()` rend `'common.maintenance.launch_blocked'` pour `Draining` et `'room.refusal.'.$this->value` pour les autres. **`room.refusal.draining` n'existe pas** (R-09).
- **Drapeau de drainage** : il est lu par `App\Support\Deploy\DeployDrain::isDraining()` (contrat C18-bis, propriété de `100`). `50` ne pose ni ne lève jamais le drapeau.
- **Appels vers `30`** : `PoolScope`, `PoolReporter::report()`, `PoolReport`, `SeededPrf::generateSeed()`, `GameDrawer::draw()`, `DrawResult` (contrats C2, C3).
- **Appels vers `60`** : `App\Actions\Game\MaterializeDraw::handle(Game $game, DrawResult $result): void` et `App\Actions\Game\ScheduleRound::handle(Round $round, CarbonImmutable $startsAt): void` (contrat C7).
- **Versions** :
  - `game.scoring_version = App\Support\Scoring\ScoringRules::VERSION` (contrat C13) ;
  - `game.validation_version = App\Support\Answers\AnswerRules::VERSION` (contrat C12).

  Elles remplacent `GameFactory::SCORING_VERSION` et `VALIDATION_VERSION`, supprimées : la fabrique lit les constantes de code.
- **Routes** (`routes/game.php`), sous `seat.active` et `throttle:game-write` :
  - `room.launch`, `POST /r/{room}/launch`, contrôleur `App\Http\Controllers\Room\LaunchController::store` ;
  - `room.replay`, `POST /r/{room}/replay`, contrôleur `App\Http\Controllers\Room\ReplayController::store`.

### 12.2 `LaunchGame`, dans `DB::transaction`

| Étape | Action |
|---|---|
| L1 | `Room::whereKey(id)->lockForUpdate()` : **premier verrou**, ordre imposé `room → player → game → round → round_player`. |
| L2 | `$now = Date::now()`, **pris après le verrou**, en millisecondes. |
| L3 | **Statut `archived` → `room_archived` d'abord, sans écriture.** Ensuite, si `host_player_id` ne désigne aucun siège non parti (`TransferHost::hasValidHost()`) : `TransferHost::automatic()`. Ensuite, si l'hôte ≠ `$requester` → `not_host` (403). Le refus du salon archivé passe en tête parce que l'archivage vide l'hôte (§ 16.2) : la réparation écrirait sinon un hôte sur un salon archivé, et `room_archived` ne serait atteignable que par l'hôte réparé — amendé le 28/09 (E102-1 ; écart d'ordre à la lettre de C6 § 3, points restés ouverts, n° 18). |
| L4 | Statut `playing` → `not_in_lobby` : le contrôleur redirige vers `room.show` **sans erreur**, ce qui rend le double clic idempotent. Le statut `archived` est refusé en tête de L3 — amendé le 28/09 (E102-1). |
| L5 | Si `settings_version ≠ RoomSettings::VERSION` : `normalize(raw, version, PoolQuery::publishedThemeIds())`, où `raw` = `json_decode($room->getRawOriginal('settings'), true)` ; puis `WriteRoomSettings` ; puis refus `settings_outdated` avec `changes`. **C'est la seule branche de refus qui écrit.** Après validation, le contrôleur dispatche `BroadcastLobbyState` (§ 8.3), comme après O3 : sans lui, seul l'hôte verrait les réglages normalisés (par `settingsChanges`), et les autres sièges garderaient la vue périmée jusqu'au déclencheur suivant, contre la promesse du § 8.2 (« après toute écriture de réglages »). |
| L6 | `$seats` = sièges avec `connection_state ∈ {connected, disconnected}`, ce qui exclut l'expulsé, `left` (D15 du 23/09). Si le nombre de sièges `connected` est inférieur à `MIN_CONNECTED_PLAYERS_TO_LAUNCH` → `not_enough_players` (`:min`). |
| L7 | `OpenGame(Multiplayer, $room, $room->settings, $seats, $now)`. Un refus est renvoyé tel quel. |
| L8 | `room.status = playing`, `launched_at ??= $now`, `last_activity_at = $now`, par mise à jour ciblée. |
| L9 | **Après validation de la transaction** : `game.launched` au salon (charge de `60`, contrat C7). |

### 12.3 `OpenGame`

| Étape | Action |
|---|---|
| O0 | Assertions : transaction ouverte ; `(mode = solo) ⇔ room = null` ; `sourceVersion = VERSION` ; sièges non vides (exactement un en solo). |
| O1 | Si `DeployDrain::isDraining()` → `draining` (message `common.maintenance.launch_blocked`). |
| O2 | `$scope = PoolScope::forRoom($room, $settings, $now)` (en solo : `PoolScope::catalogue($settings->themeIds, $settings->framesPerRound)`, après l'ajustement D19 du 23/09 fait par `60`), puis `$report = PoolReporter::report($scope, $settings->roundsCount)`. Le compte est en œuvres. |
| O3 | Si `$report->blocked()` → `pool_insufficient` (`:playable` = `count`, `:required` = `roundsCount`), avec `$report`. Le contrôleur dispatche aussi `BroadcastLobbyState` après la transaction : l'état recalculé part au salon par `settings.changed`, et tout le monde voit le même blocage. |
| O4 | `$seed = SeededPrf::generateSeed()` : jamais `random_bytes` en ligne (contrat C3). |
| O5 | INSERT `game` (§ 12.4). |
| O6 | INSERT `game_player` pour chaque siège : `status = playing`, `first_round_number = 1`, `display_nickname` et `display_avatar_*` gelés depuis `player` (E10-42). |
| O7 | `GameDrawer::draw($scope, $settings->roundsCount, new SeededPrf($seed))`, **sur le même `$scope`** et dans la même transaction. Rend `min(M + drawSubstituteMargin(), vivier)` œuvres × `N` paliers. |
| O8 | `MaterializeDraw::handle($game, $result)` : `round` et `round_tier`, dans la transaction. |
| O9 | `ScheduleRound::handle($round1, $now->addMilliseconds(EngineConstants::launchCountdownMs()))`. Les lignes `round_player` naissent à `T₁`, pas ici (R-16), et les jobs de frontière sont enregistrés **après validation**. |

### 12.4 Provenance des colonnes écrites

| Colonne | Valeur | Source |
|---|---|---|
| `game.room_id` / `mode` / `status` | salon (NULL en solo) / appelant / `running` | — |
| `input_difficulty`, `rounds_count`, `frames_per_round` | snapshot | `room_settings` |
| `settings_snapshot` + `settings_version` | `$settings` reçu par `OpenGame` (version = `VERSION`) : `room.settings` en multijoueur (L7), preset ajusté par D19 du 23/09 en solo (60 § 16.2, § 16.3) | `room_settings` |
| `tier_grace_ms` / `preload_lead_ms` | `tierGraceMs()` / `preloadLeadMs()` | `PlatformLimits`, constantes non surchargeables (§ 2.3) |
| `draw_seed` / `draw_pool_size` | `SeededPrf::generateSeed()` / `PoolReport::$count` | `30` |
| `scoring_version` / `validation_version` | `ScoringRules::VERSION` / `AnswerRules::VERSION` | constantes de version (`80` / `70`) |
| `started_at` | `$now` | horloge serveur |
| `round.duration_ms`, `round_tier.duration_ms`, `.points`, `.starts_at_offset_ms` | `roundDuration() × 1000`, `tierDurations[i−1] × 1000`, `tierPoints[i−1]`, `tierStartOffsetMs(i)` | snapshot |
| Nombre de lignes `round` | `min(M + drawSubstituteMargin(), vivier)` | `PlatformLimits` + `30` |
| Seuil de joueurs connectés | `MIN_CONNECTED_PLAYERS_TO_LAUNCH` | `RoomSettingsBounds` |
| Décompte avant la manche 1 | `EngineConstants::launchCountdownMs()` | `60` |

`draw_pool_size` est compté en œuvres, et reste `#[Hidden]` par hygiène (E10-37). Sa valeur n'est pas secrète : le compteur du lobby l'affiche déjà.

### 12.5 Réponses HTTP

Elles ont un destinataire unique, et sont donc résolues dans la langue de la requête.
- `not_host` : 403.
- `not_in_lobby` : 303 vers `room.show`, sans erreur. `room.show` rend la même page `game/lobby`, dans son état de partie (§ 7.2).
- Lancement réussi : 303 vers `room.show`.
- Autres refus : `back()->withErrors(['room' => __($refusal->messageKey(), $replace)])`, plus `settingsChanges` en flash pour `settings_outdated`.
- **Échec technique.** Toute exception levée dans la transaction de lancement ou de « Rejouer » (`PoolTooSmallException` de `30`, échec de `MaterializeDraw` ou de `ScheduleRound`, `QueryException`, délai d'attente de verrou dépassé) annule tout (invariant 1, § 12.6). Elle est journalisée sur le canal `game` de `100` (§ 10.9), sans donnée personnelle. La réponse est `back()->withErrors(['room' => __('room.errors.launch_failed')])` : un texte traduit, jamais une erreur 500 brute en anglais (règle 4). « Rejouer » reçoit le même message (formulation à relire par le porteur, points restés ouverts, n° 20). L'exception n'est **jamais** traduite en `pool_insufficient`, dont le rapport affirmerait le contraire (30 § 4.6) ; aucun événement n'est émis, le salon garde son statut (`lobby` pour un lancement, `playing` pour un « Rejouer ») et l'hôte peut recommencer. `RoomRefusal` n'est pas étendu : ce n'est pas un refus de règle.
  - **Journal** : lignes `room.launch_failed` et `room.replay_failed` [libellés libres]. Leur contexte est `{ exception, code, file, line, previous, roomPlaying }` pour un lancement, `roomLobby` au lieu de `roomPlaying` pour un « Rejouer » : classe et code de l'exception, fichier relatif à la racine du projet, ligne, classe de la cause. **Jamais le message ni la trace**, car une `QueryException` cite ses valeurs, pseudo gelé compris ; `report()` n'est pas appelé, le journal par défaut garderait le message — amendé le 28/09 (E102-3, E102-7, E115-3).
  - **Échec après la validation.** Une exception levée par un rappel `afterCommit` (poussée du job de la manche 1 sur une file en panne, par exemple) remonte **après** le COMMIT : la partie est née, ou le salon est revenu au lobby. Le contrôleur relit alors le statut du salon, par une relecture qui ne lève jamais. `playing` après un lancement, ou `lobby` après un « Rejouer », répond 303 vers `room.show` **sans erreur**, comme un geste réussi ; sinon, `room.errors.launch_failed`. Les rappels suivants ne partent pas, et la manche 1, `pending` et programmée sans job, est rattrapée par `CatchUpGame` (60 § 4.4) — amendé le 28/09 (E102-7, E115-3).

**Aucun geste de `50` ne répond 409 ni 422 à une visite Inertia.** Inertia n'interprète un 409 qu'avec un en-tête `x-inertia-location` ou `x-inertia-redirect` ; sans lui, comme pour un 422 brut, il ouvre sa fenêtre d'erreur (`handleNonInertiaResponse()`, `@inertiajs/core` 3.7, vérifié dans `node_modules/`). Un refus de règle passe donc par `back()->withErrors()` ou par une redirection 303. Seul `seat.active` emploie 409 (`seat_superseded`, contrat C7), que le client du lobby intercepte par `onHttpException` pour passer en lecture seule (§ 8.1).

**Aucune charge** (réponse ou diffusion) ne contient la graine, un film, `game.id`, `room.id` ni `draw_pool_size` en tant que tel.

### 12.6 Invariants

1. **Tout ou rien** : une seule transaction porte `game`, `game_player`, `round`, `round_tier`, la programmation de la manche 1 et `room.status`. Seule exception : le refus `settings_outdated`, qui valide la normalisation.
2. **Autorité relue sous verrou** : hôte, statut, drapeau et vivier. La policy HTTP ne suffit jamais.
3. **Même état pour la garde et le tirage** : même constructeur (contrat C2), même `PoolScope`, même transaction. Le compteur du lobby utilise la même requête et la même fenêtre ; il n'est qu'indicatif.
4. **Aucun effet externe avant validation** : les jobs et les diffusions partent après. Aucun appel réseau pendant la transaction, ni TMDB (règle 6), ni Reverb.
5. **Au plus une partie à `ended_at` NULL par salon** (E10-41). Un double clic crée une seule partie.
6. **Instant unique** : `started_at` = `$now` et, au premier lancement, `launched_at` = `last_activity_at` = `$now`. Précision `timestamp(3)`, jamais une heure client.
7. **Colonnes figées de `game`**, immuables après l'INSERT.

### 12.7 Gel des réglages

- **`RoomStatus::Playing` couvre toute la période qui va du lancement au « Rejouer » de l'hôte, podium compris** (E10-28, A-14, A-70). Les réglages sont verrouillés jusque-là : `UpdateRoomSettings` et `ApplyRoomPreset` refusent par `not_in_lobby`, qui répond 303 vers `room.show` sans erreur, comme au § 12.5 (la page `game/lobby` montre alors l'état de partie), et `WriteRoomSettings` lève hors `lobby`.
- **`FinalizeGame` ne ramène pas le salon au lobby** (R-45). Il rend « Rejouer » possible en posant `ended_at`.
- **`App\Models\Game` [modifié]** reçoit `FROZEN_COLUMNS` et une garde `updating` qui lève si l'une de ces colonnes change : `mode`, `input_difficulty`, `rounds_count`, `frames_per_round`, `draw_seed`, `draw_pool_size`, `tier_grace_ms`, `preload_lead_ms`, `settings_version`, `settings_snapshot`, `scoring_version`, `validation_version`, `started_at`, `room_id` (E10-41).
  - **`settings_snapshot` se compare par valeur décodée**, jamais par `isDirty()` sur la chaîne brute. Sous MySQL 8, une colonne `json` est relue sous forme normalisée : après une lecture, `save()` la réécrirait par le cast, et la garde lèverait sur toute sauvegarde légitime de `game`.
  - La comparaison passe par `RoomSettingsCast` [modifié], qui implémente `ComparesCastableAttributes` (§ 2.5).
  - L'identité octet pour octet que prouve `RoomSettingsCastTest` ne vaut que sous SQLite, où la colonne est du texte.

---

## 13. « Rejouer » (contrat C6)

`App\Actions\Room\ReplayRoom` [nouveau] : `public function handle(Room $room, Player $requester): ?RoomRefusal`. `null` signifie « fait » ou « déjà fait ».

| Étape | Action |
|---|---|
| R1 à R3 | Comme L1 à L3 : le salon archivé est refusé `room_archived` en tête de R3, avant toute réparation d'hôte — amendé le 28/09 (E115-1 ; même écart d'ordre qu'E102-1, points restés ouverts, n° 18). |
| R4 | Statut `lobby` → `null` (idempotent). R4 précède la garde de drainage R6 : un « Rejouer » déjà fait rend `null` même sous drapeau, sans écriture ni diffusion — amendé le 28/09 (E115-1 ; lecture du § 14 à confirmer par le porteur, points restés ouverts, n° 19). |
| R5 | Dernière partie du salon (`game_room_started_idx`, `started_at` puis `id` décroissants), **relue `FOR UPDATE` après le verrou du salon** (ordre `room → game`) : un gel concurrent est attendu, jamais lu périmé. Si `ended_at` est NULL → `game_not_ended`. Un salon `playing` sans aucune partie, état impossible puisque le lancement pose les deux dans la même transaction, n'est pas refusé : le geste le ramène au lobby, seule sortie d'un état incohérent — amendé le 28/09 (E115-1). |
| R6 | `DeployDrain::isDraining()` → `draining` (D32 du 23/09). |
| R7 | `room.status = lobby`, `last_activity_at = $now`. |
| R8 | Après validation : `room.replayed` avec `RoomSettingsState` recalculé, puisque la non-répétition a réduit le vivier. |
| R9 | Salon revenu au lobby : les marques d'avatar des sièges rattachés en cours de partie (`40` § 13.2) sont consommées par `ClaimSeatForAccount::applyPendingAvatars()`, dans une **seconde transaction** qui reprend le verrou du salon et relit `status = lobby` — jamais après le verrou de la partie de R5 (ordre `room → player → game`) ; `seat.updated` après sa validation — amendé le 07/10 (D66 du 07/10). |

- **« Rejouer » est un geste d'hôte**, proposé sur le podium par `components/room/replay-button.tsx`, composant affiché par l'état podium de `game/lobby` (écran de `90`, contenu de `60` et `80`). Les non-hôtes voient `room.replay.waiting`.
- Les échecs techniques de la transaction suivent le § 12.5.
- Les réglages redeviennent modifiables, et **le lancement suivant repasse par la garde de vivier** (00 § Déroulé d'une partie). L'avatar de chaque siège redevient modifiable au lobby (§ 8.1 ; D55 du 02/10 — amendé le 02/10).
- Les sièges partis restent partis, les expulsés restent expulsés.
- Un siège entré pendant la partie sans participation (§ 15) devient un siège de lobby ordinaire.

---

## 14. Drainage (D32 du 23/09)

Le drapeau est posé et levé par les seules commandes `deploy:*` de `100` (contrat C18-bis). Pour `50` :

- Il bloque **exactement** `OpenGame` (lancement multijoueur, et solo par `60`) et `ReplayRoom`, avec l'unique message `common.maintenance.launch_blocked`. « Rejouer » est refusé **quel que soit son effet**.
- Il ne bloque **jamais** :
  - une écriture de réglages ;
  - une entrée dans un salon ;
  - une expulsion ou un transfert ;
  - une partie en cours, une reprise après pause, un retardataire admis (contrat C17).
- Le lobby affiche le bandeau de `90` (prop partagée `maintenance`) et désactive « Lancer la partie ». **Le refus serveur est la seule garantie** : la prop n'est rafraîchie qu'à la réponse Inertia suivante — que la page provoque elle-même tant que la prop vaut vrai (§ 8.1, BUG-P2).
- Un lancement validé juste avant la pose du drapeau est attendu par la règle des deux relevés de `deploy:drain` ; le résidu est rattrapé par `deploy:guard`.
- **Ordre de livraison (D37 du 23/09).** La garde naît avec le lancement (L50-7a), qui dépend de `DeployDrain` (L100-5). Le drainage est donc obligatoire avant la **première partie** sur le VPS, jamais avant la curation : tant qu'aucun moteur ni aucun lancement n'existe, aucune partie ne peut être en cours, et le hook de déploiement de `100` fonctionne sans drainage (`deploy:guard` trivialement vrai) — amendé le 23/09.

---

## 15. Retardataires (livrés au J1, D35 du 23/09 — amendé le 23/09)

### 15.1 Règle produit

- **Salon ouvert aux retardataires** (`allow_late_join`, lu en projection, 10 § 6.2) : l'entrée se fait **à la manche suivante uniquement**, avec un score initial de 0.
- **Salon fermé** : message explicite, et attente de la fin de partie. Le nouvel arrivant **prend quand même un siège**, qui compte dans la capacité. Il n'a aucune participation et joue la partie suivante, au « Rejouer ».

### 15.2 Admission (`TakeSeat`, étape S7)

1. Partie en cours = dernière partie du salon à `ended_at` NULL. S'il n'y en a pas (podium), ou si `allow_late_join` est faux → **attente** : aucune ligne `game_player`.
   - La dernière partie est relue **`FOR UPDATE` après l'écriture du siège** (ordre `room → player → game`), et `ended_at` NULL est lu sous ce verrou. Pourquoi : une partie **interrompue** (gel en pause, clôture forcée) garde des manches numérotées `pending` (80 § 10.3). Sans ce verrou, une admission concurrente de son gel insérerait une participation dans une partie figée, sans agrégats, et le podium lèverait au rendu. C'est le patron de l'étape 7 de `KickSeat` et de R5 — amendé le 28/09 (E116-1 ; à confirmer par le porteur, points restés ouverts, n° 21).
2. Sinon, on cherche la **prochaine manche dans l'ordre de jeu** (« manche suivante à jouer », 60 § 1.2 ; 60 § 13.7 renvoie la règle à ce document) : `round` de la partie, `round_number` non nul, `status = pending`, `started_at` nul ou postérieur à `$now`, triée par `round_number` croissant, puis `sequence_index` croissant. Le moteur joue les manches par `round_number` : un tri par `sequence_index` donnerait la manche `k + 1` à un retardataire alors que la remplaçante de `k`, de `sequence_index` plus grand, se joue d'abord.
   - La candidate est lue **par une lecture verrouillante** (`Round::lateJoinableAt($now)` avec `lockForUpdate()`, après `game` dans l'ordre global), qui la lit dans sa dernière version validée. Le prédicat est ensuite **relu** en PHP sur la ligne rendue (`Round::isLateJoinableAt()`) — amendé le 28/09 (E116-2).
   - Pourquoi une lecture verrouillante plutôt que « lecture, puis verrou » : sous `REPEATABLE READ` (InnoDB), une lecture simple lirait l'instantané pris à S3. Une remplaçante numérotée par un `CancelRound` validé entre-temps y serait invisible, et le retardataire manquerait la remplaçante, qui se joue d'abord.
   - `started_at` postérieur s'entend strictement : à `T₁` pile, la manche est jouée (60 § 1.2). La même définition sert l'état indicatif `late_join` de la page d'entrée, en lecture simple.
   - Si `OpenTier(1)` de `60` l'a ouverte entre-temps, on passe à la suivante. La boucle est bornée par le nombre de manches.
3. Aucune candidate → attente.
4. Sinon, INSERT `game_player` : `status = playing`, `first_round_number` = son `round_number`, `display_*` gelés.
5. `60` crée sa ligne `round_player` à `T₁` de cette manche. Le prédicat de service de `/f/` refuse au retardataire les images de la manche en cours (`first_round_number ≤ round_number`, E10-20).

Les manches de réserve encore sans numéro (`round_number` NULL, E10-45) ne sont jamais une cible. Une manche de remplacement porte le numéro de la manche annulée, reste `pending` et se joue avant les suivantes : tant qu'elle n'a pas démarré, **elle est la cible**, et `first_round_number` prend son numéro. La manche annulée, en statut `cancelled`, est exclue par le filtre `status = pending`. Le verrou de la manche sérialise l'admission avec son ouverture ; sans lui, un retardataire admis à la milliseconde de `T₁` n'aurait ni la manche courante ni sa ligne.

### 15.3 Affichage

- `seat.joined` porte `firstRoundNumber` (`SeatView`, contrat C7).
- `game/lobby`, dans son état de partie, montre au siège en attente `room.lobby.waiting_next_game` (sans participation) ou l'état « retardataire en attente » (`member` faux jusqu'à sa manche, contrat C7).
- Le drainage ne bloque jamais une admission (contrat C17).

### 15.4 Livraison au J1 (D35 du 23/09) — amendé le 23/09

D17 du 23/09 faisait des retardataires la troisième variable d'ajustement du J1, à couper si l'estimation dérapait (A-09). Elle est **sans effet depuis D35 du 23/09** : le J1 est livré complet, sans aucune coupe, et les variables d'ajustement de développement sont sans objet. Donc :
- L50-9 est un lot J1 ordinaire ;
- `allowLateJoin` est une clé de `SIMPLE_KEYS`, visible et réglable par l'hôte au J1 comme tout réglage Simple, défaut `false` (`DEFAULT_ALLOW_LATE_JOIN`) ;
- `editor.lateJoinAvailable` vaut `true` (§ 8.1) ;
- l'étape S7 admet selon le § 15.2 dès que le salon est ouvert aux retardataires.

---

## 16. Échéances vues du joueur

### 16.1 Trois échéances, trois noms

10 § 11.1 les nomme, et ce document fixe leur comportement visible. Toutes se comptent depuis **la dernière activité**, jamais en absolu : une partie légale de 30 manches à 120 s dure 70 minutes.

| Échéance | Déclencheur | Propriétaire |
|---|---|---|
| **Clôture de partie** | 15 min sans joueur connecté (`game.paused_at`) | `60` |
| **Archivage anticipé du lobby** | lobby jamais lancé (`launched_at` NULL), `last_activity_at < now − RoomExpiry::LOBBY_IDLE_MINUTES` (2 h), périmètre `stale_lobby` | `50`, seul exécutant du périmètre, dont il écrit la ligne `purge_run` (§ 16.2) ; `100` le surveille ; **archivage forcé, jamais suppression** (E10-30) |
| **Archivage du salon** | `last_activity_at < now − RoomExpiry::ROOM_IDLE_MINUTES` (24 h) | `50` |
| Filet `stale_room` | 48 h (`RetentionWindows::STALE_ROOM_HOURS`, déjà livrée par `100` et lue par la sonde n° 2 — amendé le 25/09, E24-7), pour un job d'archivage qui n'a jamais tourné | purge de `10` / `100`, gestionnaire `StaleRoomHandler` livré à l'étape 114 (L100-8, 2e temps), qui appelle `ArchiveRoom` — amendé le 28/09 (E114-4) |

`App\Support\Room\RoomExpiry` [nouveau] porte :
- `LOBBY_IDLE_MINUTES = 120` et `ROOM_IDLE_MINUTES = 1440`, valeurs de 10 § 11.1 ;
- `SWEEP_EVERY_MINUTES = 10`, cadence du balayage, diviseur de 60 et au moins 2 (le verrou d'unicité du job dure une cadence moins une minute, § 16.2) ;
- `BATCH_SIZE = 100`, taille du lot borné ;
- `lobbyIdleBefore($now)`, `roomIdleBefore($now)` et `sweepCron()` (`*/10 * * * *`, bâtie sur `SWEEP_EVERY_MINUTES`), seules sources des bornes des deux passes et de la cadence planifiée — amendé le 28/09 (E113-2, E113-3, E113-7).

Ce ne sont pas des valeurs de jeu : ce sont des durées de conservation, publiées par `90` depuis ces constantes et jamais recopiées. `RetentionWindows` de `100` lit ces durées ici, jamais une seconde constante (100 § 14).

**Sources d'activité**, qui écrivent `room.last_activity_at` :
- création et prise de siège ;
- toute écriture de réglages (`WriteRoomSettings`) ;
- lancement et « Rejouer » ;
- expulsion, transfert, départ ;
- le **battement de présence** de `60`, qui alimente `room.last_activity_at` (10 § 7.1).

### 16.2 `ArchiveRoom` et le balayage

**Amendé par D62 du 06/10** : l'archivage n'efface plus que `player.player_token_hash` ; pseudo, forme normalisée et `game_player.display_nickname` survivent, anonymisés 12 mois après la dernière activité du siège par le périmètre `guest_nickname` (`10` § 11.1). Les étapes ci-dessous qui nomment l'effacement du pseudo se lisent à cette aune.

`App\Actions\Room\ArchiveRoom` [nouveau], `handle(Room $room, CarbonImmutable $idleBefore, bool $lobbyOnly = false): bool`. Dans une transaction, sous verrou du salon, avec `$now` pris après le verrou :

1. **Le critère est relu sous verrou.** Salon déjà archivé, `last_activity_at ≥ $idleBefore`, ou (`$lobbyOnly` et `launched_at` non nul) → `false`, sans écriture. Pourquoi : entre la sélection du balayage et le verrou, un joueur a pu entrer ou battre, et archiver un salon redevenu actif effacerait les pseudos de joueurs présents.
2. Dernière partie à `ended_at` NULL → `false` et journal : **défensif**. La terminaison bornée de `60` (contrat C17) rend ce cas impossible, et la sonde n° 1 de 10 § 11.3 le verrait.
3. `status = archived`, `archived_at = $now`, `room_code_active = NULL`, puis `TransferHost::clear()`.
4. Tous les sièges du salon : `nickname`, `nickname_normalized` et `player_token_hash` passent à `NULL`, par une mise à jour Eloquent.
5. `game_player.display_nickname = NULL` pour les parties du salon.
6. **Les trois effacements dans la même transaction** (10 § 11.1).

Après validation : `room.archived`. **Aucune ligne n'est supprimée** : les lignes `room` et `player` partent plus tard, par dépendance (10 § 11.1, `orphan_player`).

**Balayage.**
- La commande `room:archive-idle` (`App\Console\Commands\RoomArchiveIdleCommand`) est planifiée toutes les `SWEEP_EVERY_MINUTES` dans `routes/console.php`, par `Schedule` (`->cron(RoomExpiry::sweepCron())`). Elle dépose le job et rend 0, **sans aucune sortie** : aucune clé `admin.console.*` n'est prévue (règle 4), et la trace d'un passage est sa ligne `purge_run` — amendé le 28/09 (E113-3).
- Elle dispatche `App\Jobs\Room\ArchiveIdleRooms` sur la **file `default`**, **jamais `game`**.
- **Unicité du job** — amendé le 28/09 (E113-2, E113-7). Le job est `ShouldBeUnique`, avec `$tries = 1`, et son verrou d'unicité dure **strictement moins qu'une cadence** : `$uniqueFor = (SWEEP_EVERY_MINUTES − 1) × 60`. Posé au dépôt, un verrou orphelin (worker tué, file arrêtée) est donc toujours expiré au dépôt suivant, et l'archivage reste au plus une cadence après l'échéance. Une file arrêtée accumule au plus un balayage par cadence, rejoués de façon idempotente.
- **Instant et curseur** — amendé le 28/09 (E113-2) :
  - `$now` est pris une fois par passage, **à la seconde**, comme les colonnes pilotes ;
  - chaque passe avance par un **curseur `(last_activity_at, id)`**, jamais par resélection : un salon que l'action refuse (redevenu actif, partie non figée) resterait sinon éligible, et la passe ne progresserait plus ;
  - les lots font `BATCH_SIZE` salons, sans plafond du nombre de lots : « lots bornés » s'entend de leur taille.
- Deux passes, par lots bornés du plus ancien au plus récent, sur `room_archived_activity_idx` (`archived_at`, `last_activity_at`) :
  1. **`stale_lobby`**, par `ArchiveRoom($room, $now − LOBBY_IDLE_MINUTES, lobbyOnly: true)`. Ce balayage est le **seul exécutant** du périmètre (10 § 11.1) : `RetentionPurger` ne l'exécute jamais (100 § 14, test « n'exécute jamais stale_lobby, confié au balayage de 50 »), puisqu'une exécution quotidienne de plus ferait deux exécutants d'un même périmètre et porterait la fenêtre annoncée de 2 h à 26 h si le balayage s'arrêtait. Il journalise **une ligne `purge_run` par passage**, de scope `PurgeScope::StaleLobby` [existant] et de `status = completed`, **même à zéro salon archivé** : l'absence de ligne est une panne, et sans elle la sonde `purge` de `100` (§ 15) ne distinguerait pas un balayage arrêté d'un balayage sans travail. `rows_deleted` porte le nombre de salons archivés, conformément au compteur générique du journal. La ligne suit la sémantique du moteur de purge (10 § 11.3), et « `status = completed` » en décrit le cas nominal — amendé le 28/09 (E113-2) :
     - elle est écrite `running` avant le premier lot, avec `started_at = ran_at =` l'instant du passage ;
     - elle passe ensuite `completed`, même avec des salons en échec : ceux-ci sont comptés, et `error` porte la classe de la dernière exception, jamais son message ;
     - elle passe `failed` sur une exception levée hors d'un salon.
  2. **archivage à 24 h**, par `ArchiveRoom($room, $now − ROOM_IDLE_MINUTES)` : c'est une action, pas une purge (10 § 11.1), et elle ne journalise aucune ligne `purge_run`. Ses salons en échec sont comptés et journalisés (classe et code) ; une exception hors d'un salon fait échouer le job, la passe `stale_lobby` ayant déjà écrit sa ligne — amendé le 28/09 (E113-2). Le filet `stale_room` à 48 h, lui, est un gestionnaire du `RetentionPurger` de `100`, qui appelle la même action `ArchiveRoom`, unique chemin d'archivage (100 § 14).
- **`purge:suspend` n'est pas lu par le balayage.** C'est l'interrupteur d'incident de `RetentionPurger` (100 § 14), non celui de ce balayage, et suspendre l'archivage garderait des identifiants d'invité au-delà de la durée annoncée. La sonde `purge` reste de toute façon en alerte pendant une suspension — amendé le 28/09 (E113-2 ; question au porteur, points restés ouverts, n° 26).
- Le planificateur tourne par le `cron` de `100`. **L'archivage effectif intervient au plus `SWEEP_EVERY_MINUTES` après l'échéance** : `90` publie l'échéance augmentée de cet intervalle, pour que la durée annoncée reste un plafond vrai (décision 19).

### 16.3 Vu du joueur

- **Aucun compte à rebours dans le lobby.** Le battement de présence nourrit `last_activity_at`, donc un lobby où un joueur est connecté n'expire jamais. L'archivage anticipé ne frappe que des lobbies vides, où personne ne verrait de compte à rebours.
  - Corollaire assumé : un onglet ouvert maintient le salon, et les identifiants d'invité, en vie tant qu'il bat (00 : « jamais en absolu »).
- **Lien de salon expiré.** `room.show` rend `game/room-expired` (statut 410), avec `room.expired.title`, `room.expired.description`, un lien vers `room.create` et un lien vers l'accueil. Aucune autre information sur le salon.
- **Recyclage du code** : un vieux lien mène à cette page tant qu'aucun salon actif n'a reçu le même code ; après recyclage, il mène au nouveau salon, résidu assumé au § 6.3.
- Un client encore ouvert qui reçoit `room.archived`, ou dont l'autorisation de canal est refusée, visite `room.show` (le second cas relève de `60`).
- **Rattachement tardif** d'une partie d'invité à un compte (J2, `40`) : il est possible jusqu'à l'archivage, et plus jamais ensuite.

---

## 17. Autorisation

### 17.1 `RoomPolicy`

`App\Policies\RoomPolicy` [nouveau], découverte automatiquement pour `Room`. Chaque méthode reçoit `?User $user`, parce que l'hôte est le plus souvent un invité. Le **siège** est résolu par `PlayerTokenManager::seatIn($request, $room)` (contrat C4, expulsé exclu).

| Méthode | Vrai si et seulement si |
|---|---|
| `updateSettings(?User $user, Room $room, ?Player $seat)` | `$seat` appartient au salon et `room.host_player_id = $seat->id` |
| `launch(...)`, `replay(...)`, `advanceRound(...)`, `kick(...)`, `transferHost(...)` | même clause |
| `leave(?User $user, Room $room, ?Player $seat)` | `$seat` appartient au salon |
| `changeAvatar(?User $user, Room $room, ?Player $seat)` | même clause que `leave` : son propre siège, jamais un geste d'hôte ; la garde `lobby` est relue sous verrou par `ChangeSeatAvatar` (§ 8.1 ; D55 du 02/10 — amendé le 02/10) |
| `loadConfig(?User $user, Room $room, ?Player $seat)` [J2] | même clause que `updateSettings`, et `$user` non nul |
| `saveConfig(?User $user, Room $room, ?Player $seat)` [J2] | `$user` non nul et `$seat` appartient au salon : tout porteur de siège connecté à un compte, pas seulement l'hôte (§ 18.2) |

- Les contrôleurs appellent `Gate::authorize('<geste>', [$room, $seat])`. Chaque action **relit l'autorité sous verrou**.
- **Aucune clause de rôle** : un admin n'a aucun geste sur un salon, et **il n'existe pas de `Gate::before`**. « Inspecter une partie » est un écran de back-office en lecture (`20`), jamais un geste de salon.
- Toute écriture du lobby passe aussi par le middleware `seat.active` de `60` (contrat C7) : un onglet supplanté reçoit 409 `seat_superseded`.

### 17.2 `SavedConfigPolicy` [J2]

Elle reste `view`, `update` et `delete` **par propriété seule**, sans clause de rôle, et **refusée à un admin** sur la configuration d'un tiers (10 § 6.3, test existant). Le plafond est porté par l'action de création, pas par la policy (§ 18.2).

### 17.3 Limiteurs nommés

| Limiteur | Clé | Valeur | Routes |
|---|---|---|---|
| `room-create` | adresse IP (cache, jamais une table de domaine) | `RoomRateLimits::createsPerHour()`, défaut 10, `game.room.creates_per_hour` | `room.store` |
| `room-join` | hash du `player_token`, repli sur l'IP | `RoomRateLimits::joinsPerMinute()`, défaut 10, `game.room.joins_per_minute` | `room.join` |
| `game-read` / `game-write` | contrat C7 | contrat C7 | GET du salon / écritures du salon, `room.avatar.update` compris (D55 du 02/10 — amendé le 02/10) |

- Ces limiteurs sont déclarés dans `FortifyServiceProvider::configureRateLimiting()`.
- `App\Support\Room\RoomRateLimits` [nouveau] suit le patron de `PlatformLimits` : constantes `DEFAULT_*`, accesseurs statiques, garde ≥ 1. Il n'a **pas** d'instance mémoïsée : ses deux accesseurs lisent et gardent la configuration à chaque appel, ce qui tient la garde sur tout chemin sans liaison de conteneur. Preuve : `tests/Feature/Room/RoomRateLimitsTest.php` (L50-1) — amendé le 25/09 (E9-6).
- Ce ne sont **ni** des limites de confort **ni** des valeurs de jeu, mais des gardes anti-abus : elles ne sont jamais résolues par compte.
- L'énumération des codes par GET se heurte à `game-read`, face à un espace de 32⁶ codes.

---

## 18. Configurations sauvegardées [J2]

### 18.1 Principe

- **Réservées aux comptes.** Un compte peut enregistrer un jeu de réglages sous un nom, le recharger, le renommer, le supprimer, et en désigner un par défaut, appliqué à la création d'un salon (00 § Réglages du salon).
- **Strictement privées**, y compris d'un admin : aucune visibilité, aucun partage.
- L'impossibilité de sauvegarder est affichée à l'invité comme une **raison assumée de créer un compte** (`room.configs.guest_hint`), jamais comme un blocage (principe 10).
- Les configurations vivent tant que le compte vit ; elles sont hors du périmètre de la purge et supprimées à l'anonymisation (10 § 6.3, § 11.2).

### 18.2 Routes et règles

| Route | Méthode et chemin | Règle |
|---|---|---|
| `room.configs.store` | POST `/r/{room}/configs` | Corps `{ name, overwrite? }`. **Enregistre `room.settings` tel qu'il est, jamais une charge postée** : aucun réglage invalide ne peut entrer par cette voie. Tout porteur de siège du salon connecté à un compte peut enregistrer. |
| `saved-configs.index` | GET `/settings/configs` | Écran de gestion (`90`), `AppLayout` et `SettingsLayout`. |
| `saved-configs.update` | PATCH `/settings/configs/{savedConfig}` | Renommer. |
| `saved-configs.destroy` | DELETE `/settings/configs/{savedConfig}` | Supprimer. |
| `saved-configs.default` | PUT / DELETE `/settings/configs/{savedConfig}/default` | Désigner ou retirer la configuration par défaut. |
| `room.settings.load` | POST `/r/{room}/settings/load` | Corps `{ config }`. Hôte seul, `seat.active` (§ 18.3). |

Toutes ces routes exigent `auth`.

- **Nom.** De 1 à 40 caractères affichés (`mb_strlen` après `trim`), casse conservée.
- **Forme normalisée.** `name_normalized = App\Support\Room\SavedConfigName::normalize($name)`. On applique `AnswerKeyNormalizer::fold()` (contrat C12), on remplace tout ce qui n'est pas `[a-z0-9 ]` par une espace, puis on compresse les espaces et on coupe les bords. C'est l'alphabet de 10 § A6, et la forme ne dépasse jamais 40 caractères.
  - Forme vide → `room.configs.errors.name_empty`.
  - Forme déjà portée par une autre configuration du compte → `room.configs.errors.name_taken`, sauf `overwrite: true` explicite, confirmé côté client (`room.configs.overwrite_confirm`).
- **Adresse.** Une configuration s'adresse par sa forme normalisée, **dans le périmètre du compte connecté** : la liaison `{savedConfig}` résout `user_id = auth()->id()` et `name_normalized = valeur`. Aucun identifiant interne ne quitte le serveur (10 § 1.1), et la configuration d'un tiers n'est même pas résoluble (404). La policy est vérifiée en plus, à chaque écriture.
- **Plafond.** `PlatformLimits::savedConfigsPerUser()`, jamais un littéral. Il est appliqué par `App\Actions\Room\SaveRoomConfig` sous `users FOR UPDATE`, puis comptage : deux enregistrements concurrents au plafond moins un ne dépassent jamais. Au-delà → `room.configs.errors.limit` (`:max`).
- **Défaut.** Déplacer le défaut vide l'ancien créneau `default_slot` puis pose le nouveau, dans une transaction. `saved_config_user_default_uq` garantit l'unicité (10 § 6.3).

### 18.3 Charger = proposer, jamais appliquer en silence

`App\Actions\Room\LoadSavedConfig`, sous le verrou du salon, par l'hôte, en `lobby` :

1. `normalize(raw, settings_version, PoolQuery::publishedThemeIds())` **en mémoire** :
   - un réglage ajouté depuis prend sa valeur par défaut (`defaulted`) ;
   - une valeur hors bornes est écrêtée (`clamped`) ;
   - un thème retiré est enlevé (`pruned`) ;
   - les paliers sont réégalisés si `N` a changé (`resized`).
2. **Capacité relevée** à l'effectif présent, dans la limite de `roomSeats()` → `raised`.
3. Chaque champ avancé personnalisé que le chargement remplace → `overwritten` (même définition qu'au § 5.3).
4. `WriteRoomSettings`, puis diffusion anti-rebondie.
5. Rapport `changes` champ par champ, dans le flash `settingsChanges`, avec un code par champ. Quand plusieurs codes s'appliquent à un même champ, la priorité est : code de `normalize()`, puis `raised`, puis `overwritten`.

- **La ligne `saved_config` n'est jamais réécrite en base** par une évolution des réglages : `get()` ne normalise rien, et le résultat de `normalize()` n'est jamais réinjecté dans le modèle (10 § 6.1). L'utilisateur peut réenregistrer sous le même nom pour figer la version normalisée.
- **Configuration par défaut à la création** : `CreateRoom` applique la même normalisation, puis flashe son rapport. La capacité n'a rien à relever, puisque le seul siège est celui du créateur.
- Si le vivier devient insuffisant, le lobby le montre et nomme le réglage fautif (§ 9).

---

## 19. Mode solo : ce que ce document en dit

Le flux appartient à `60` (00 § Carte des specs : « delta du mode solo »). `50` fixe seulement ceci :

- Le solo porte un `RoomSettings` complet, puisque `game.settings_snapshot` est obligatoire. Il vient **d'un des quatre presets** (D19 du 23/09), sans formulaire.
- **Champs sans effet en solo** : `capacity`, `allowLateJoin` et `noRepeatMovies`. Le vivier solo n'a aucune clause de salon (`PoolScope::catalogue`, contrat C2), et un solo n'écrit jamais `seen_frame` (10 § 7.10). Le sort de `disconnectGraceSeconds` en solo appartient à `60`.
- Le `N` imposé d'office est appliqué par `RoomSettingsEditor::simple()` (§ 3.2). Réduire `N` ne viole jamais la borne 1, donc `D` reste inchangé.
- La partie naît par `OpenGame(Solo, null, …)` : le drainage la refuse (§ 14).

---

## 20. Textes et traductions

Domaine `room` (05 § Dictionnaires serveur), rédigé par `50` (contrat C15 § 2.4). Les deux langues sont symétriques, clés et placeholders compris, et vérifiées en CI.
- **Les nombres ne sont jamais écrits dans un texte** : ils passent en placeholders et sont formatés par le client (`Intl.NumberFormat`).
- Les clés énumérables sont construites par une table `Record<Valeur, TranslationKey>` côté client, jamais par un gabarit (contrat C15 § 2.7).
- `lang/{fr,en}/room.php` et `validation.php` sont complétés **dans le lot qui introduit la clé**.

### 20.1 Libellés et aides des réglages (`room.settings.<champ>.{label,help}`)

| Champ | `label` FR | `help` FR | `label` EN | `help` EN |
|---|---|---|---|---|
| `themeKeys` | Thèmes | Un film compte s'il appartient à l'un des thèmes choisis. Aucun thème : tout le catalogue. | Themes | A movie counts if it belongs to any chosen theme. No theme: the whole catalogue. |
| `roundsCount` | Nombre de manches | Une manche, un film. | Rounds | One round, one movie. |
| `framesPerRound` | Images par manche | De la plus cryptique à la plus évidente : plus d'images, plus de paliers. | Frames per round | From the most cryptic to the most obvious: more frames, more tiers. |
| `roundDuration` | Durée d'une manche | Répartie à parts égales entre les images. | Round duration | Split equally between the frames. |
| `revealDuration` | Durée de la révélation | Pause entre deux manches, le temps de découvrir la réponse. | Reveal duration | Pause between rounds, time to see the answer. |
| `inputDifficulty` | Difficulté de saisie | Comment les joueurs répondent. | Answer mode | How players answer. |
| `capacity` | Places | Nombre maximum de joueurs, jamais sous le nombre de joueurs présents. | Seats | Maximum number of players, never below the players present. |
| `allowLateJoin` | Retardataires | Autoriser l'arrivée en cours de partie, à la manche suivante, sans aucun point. | Late arrivals | Allow joining mid-game, from the next round, with no points yet. |
| `advanced` | Réglages avancés | Détail des paliers, barème et limites. | Advanced settings | Tier detail, scoring and limits. |
| `tierDurations` | Durée des paliers | La manche dure la somme des paliers. | Tier durations | The round lasts the sum of the tiers. |
| `tierPoints` | Points par palier | Points gagnés si la réponse tombe dans ce palier. | Points per tier | Points earned when the answer lands in this tier. |
| `speedBonus` | Bonus de rapidité | Un bonus qui décroît à l'intérieur de chaque palier. | Speed bonus | A bonus that decreases within each tier. |
| `noRepeatMovies` | Pas de film déjà joué | Écarte les films joués récemment dans ce salon. | No repeated movies | Leaves out movies recently played in this room. |
| `attemptsPerSecond` | Tentatives par seconde | Cadence maximale des réponses en texte libre. | Attempts per second | Maximum rate of free-text answers. |
| `attemptsPerRound` | Tentatives par manche | Nombre maximum de réponses en texte libre dans une manche. | Attempts per round | Maximum number of free-text answers in a round. |
| `maxAnswerLength` | Longueur maximale d'une réponse | En caractères. | Maximum answer length | In characters. |
| `disconnectGraceSeconds` | Délai avant « parti » | Temps laissé à un joueur déconnecté pour revenir. | Time before “left” | Time given to a disconnected player to come back. |

### 20.2 Autres clés de réglages

| Clé | Placeholders | FR | EN |
|---|---|---|---|
| `room.settings.inputDifficulty.option.easy` | — | Facile : propositions dès la première image | Easy: choices from the first frame |
| `room.settings.inputDifficulty.option.normal` | — | Normal : texte libre, puis propositions à la dernière image | Normal: free text, then choices on the last frame |
| `room.settings.inputDifficulty.option.expert` | — | Expert : texte libre uniquement | Expert: free text only |
| `room.settings.inputDifficulty.choices_at` | `:percent`, `:seconds` | Les propositions apparaissent à :seconds s, soit :percent % de la manche. | Choices appear at :seconds s, :percent% of the round. |
| `room.settings.roundDuration.raised` | `:seconds` | Durée portée à :seconds s, le minimum pour ce nombre d'images. | Round duration raised to :seconds s, the minimum for this number of frames. |
| `room.settings.advanced_active` | — | Des réglages avancés sont actifs : | Advanced settings are active: |
| `room.settings.change.defaulted` | `:attribute` | « :attribute » a pris sa valeur par défaut. | “:attribute” was set to its default. |
| `room.settings.change.dropped` | `:attribute` | « :attribute » n'existe plus et a été retiré. | “:attribute” no longer exists and was removed. |
| `room.settings.change.clamped` | `:attribute` | « :attribute » a été ramené dans ses limites. | “:attribute” was brought back within its limits. |
| `room.settings.change.resized` | `:attribute` | « :attribute » a été redécoupé pour le nouveau nombre d'images. | “:attribute” was re-split for the new number of frames. |
| `room.settings.change.pruned` | `:attribute` | « :attribute » : un thème retiré du site a été enlevé. | “:attribute”: a theme no longer on the site was removed. |
| `room.settings.change.coerced` | `:attribute` | « :attribute » était illisible et a repris sa valeur par défaut. | “:attribute” was unreadable and reset to its default. |
| `room.settings.change.equalized` | `:attribute` | « :attribute » : les paliers ont été remis à durée égale. | “:attribute”: tiers were reset to equal durations. |
| `room.settings.change.reset` | `:attribute` | « :attribute » : le barème personnalisé a été remplacé par le barème par défaut. | “:attribute”: the custom scoring was replaced by the default one. |
| `room.settings.change.raised` | `:attribute` | « :attribute » a été relevé au nombre de joueurs présents. | “:attribute” was raised to the number of players present. |
| `room.settings.change.overwritten` | `:attribute` | « :attribute » : votre réglage avancé a été remplacé. | “:attribute”: your advanced setting was replaced. |
| `room.warnings.short_reveal` | `:seconds` | Révélation courte : :seconds s sont recommandées pour laisser le temps de lire la réponse. | Short reveal: :seconds s are recommended to leave time to read the answer. |
| `room.warnings.long_round` | `:seconds` | Manche de plus de :seconds s : un joueur qui trouve tôt attendra longtemps. | Round longer than :seconds s: a player who finds early will wait a long time. |
| `room.warnings.non_decreasing_points` | — | Un palier tardif rapporte autant ou plus qu'un palier précédent : attendre peut payer. | A later tier is worth as much as or more than an earlier one: waiting may pay off. |
| `room.warnings.all_tiers_zero` | — | Aucun palier ne rapporte de point : partie sans score, le classement suivra le départage. | No tier is worth any points: a scoreless game, the ranking follows the tie-breakers. |

Dans `room.settings.change.*`, `:attribute` reçoit côté client le libellé traduit du champ (`room.settings.<champ>.label`). Dans `room.warnings.short_reveal` et `room.warnings.long_round`, `:seconds` reçoit le seuil lu dans `bounds.warningThresholds` ; `non_decreasing_points` et `all_tiers_zero` n'ont pas de seuil, donc pas de placeholder. Les placeholders sont ainsi définis clé par clé, écart de forme à C0 § 2 (qui donne `:seconds` à toute la famille) signalé au porteur (points restés ouverts, n° 10).

### 20.3 Vivier, refus, entrée, lobby, échéances

| Clé | Placeholders | FR | EN |
|---|---|---|---|
| `room.pool.counter` | `:playable`, `:required` | Films jouables : :playable pour :required manches. | Playable movies: :playable for :required rounds. |
| `room.pool.blocked` | — | Lancement impossible : pas assez de films jouables avec ces réglages. | Cannot start: not enough playable movies with these settings. |
| `room.pool.no_remedy` | — | Le catalogue ne compte pas encore assez de films pour une partie. | The catalogue does not hold enough movies for a game yet. |
| `room.pool.cause.themeKeys` | — | Les thèmes choisis restreignent trop le choix. | The chosen themes narrow the choice too much. |
| `room.pool.cause.framesPerRound` | — | Trop peu de films ont assez d'images pour ce nombre d'images par manche. | Too few movies have enough frames for this number of frames per round. |
| `room.pool.cause.roundsCount` | — | Il y a plus de manches que de films jouables. | There are more rounds than playable movies. |
| `room.pool.cause.noRepeatMovies` | — | Ce salon a déjà joué la plupart des films disponibles. | This room has already played most of the available movies. |
| `room.pool.themes_pruned` | — | Un thème choisi n'est plus proposé sur le site : il ne filtre plus rien. | A chosen theme is no longer offered: it no longer filters anything. |
| `room.pool.remedy.open_new_room` | `:count` | Créer un nouveau salon (:count films jouables avec ces réglages) | Open a new room (:count playable movies with these settings) |
| `room.pool.remedy.disable_no_repeat` | `:count` | Autoriser les films déjà joués (:count films jouables) | Allow movies already played (:count playable movies) |
| `room.pool.remedy.clear_themes` | `:count` | Retirer les thèmes (:count films jouables) | Clear the themes (:count playable movies) |
| `room.pool.remedy.lower_frames_per_round` | `:value`, `:count` | Passer à :value images par manche (:count films jouables) | Switch to :value frames per round (:count playable movies) |
| `room.pool.remedy.reduce_rounds_count` | `:value`, `:count` | Jouer :value manches (:count films jouables) | Play :value rounds (:count playable movies) |
| `room.refusal.not_host` | — | Seul l'hôte peut faire cela. | Only the host can do this. |
| `room.refusal.room_archived` | — | Ce salon a expiré. | This room has expired. |
| `room.refusal.not_in_lobby` | — | Une partie est déjà en cours dans ce salon. | A game is already running in this room. |
| `room.refusal.settings_outdated` | — | Les réglages ont été mis à jour : vérifiez-les, puis relancez. | The settings were updated: check them, then start again. |
| `room.refusal.not_enough_players` | `:min` | Il faut au moins :min joueurs connectés pour lancer. | At least :min connected players are needed to start. |
| `room.refusal.pool_insufficient` | `:playable`, `:required` | Seulement :playable films jouables pour :required manches. | Only :playable playable movies for :required rounds. |
| `room.refusal.game_not_ended` | — | La partie n'est pas encore terminée. | The game is not over yet. |
| `room.errors.launch_failed` | — | Le lancement a échoué. Réessayez dans un instant. | Starting the game failed. Please try again in a moment. |
| `room.join.kicked` | — | L'hôte vous a retiré de ce salon : vous ne pouvez pas y revenir. | The host removed you from this room: you cannot come back. |
| `room.join.full` | — | Ce salon est complet. | This room is full. |
| `room.join.in_progress` | — | Une partie est en cours : vous attendrez dans le salon et jouerez la suivante. | A game is in progress: you will wait in the room and play the next one. |
| `room.join.late_join` | — | Une partie est en cours : vous entrerez à la manche suivante, sans aucun point. | A game is in progress: you will join from the next round, with no points yet. |
| `room.join.title` / `room.join.submit` | — | Rejoindre le salon / Entrer | Join the room / Join |
| `room.create.title` / `room.create.intro` / `room.create.submit` | — | Créer un salon / Choisissez un pseudo : vous réglerez la partie et votre avatar dans le salon. / Créer le salon | Create a room / Pick a nickname: you will set up the game and your avatar in the room. / Create room |
| `room.identity.nickname_label` | — | Pseudo | Nickname |
| `room.identity.nickname_hint` | `:min`, `:max` | Entre :min et :max caractères, unique dans le salon. | Between :min and :max characters, unique in the room. |
| `room.lobby.title` | — | Salon | Room |
| `room.lobby.avatar_taken` | — | Un autre joueur a déjà cet avatar. | Another player already has this avatar. |
| `room.lobby.code_label` / `copy_link` / `link_copied` / `share_hint` | — | Code / Copier le lien / Lien copié / Partagez ce lien ou ce code avec vos amis. | Code / Copy link / Link copied / Share this link or code with your friends. |
| `room.lobby.share` | — | Partager | Share |
| `room.lobby.players` | `:count`, `:capacity` | Joueurs (:count sur :capacity) | Players (:count of :capacity) |
| `room.lobby.host_badge` / `you` | — | Hôte / Vous | Host / You |
| `room.lobby.seat.disconnected` / `left` / `kicked` | — | Déconnecté / Parti / Retiré | Disconnected / Left / Removed |
| `room.lobby.kick` / `kick_confirm` | — / `:nickname` | Retirer du salon / Retirer :nickname ? Ce joueur ne pourra pas revenir dans ce salon. | Remove from room / Remove :nickname? This player won't be able to come back to this room. |
| `room.lobby.cannot_kick_self` | — | Vous ne pouvez pas vous retirer vous-même : quittez le salon. | You cannot remove yourself: leave the room instead. |
| `room.lobby.transfer` / `transfer_confirm` | — / `:nickname` | Nommer hôte / Confier le rôle d'hôte à :nickname ? | Make host / Hand the host role to :nickname? |
| `room.lobby.transfer_unavailable` | — | Ce joueur n'est pas connecté. | This player is not connected. |
| `room.lobby.host_changed` | `:nickname` | :nickname est maintenant l'hôte. | :nickname is now the host. |
| `room.lobby.you_are_host` | — | Vous êtes l'hôte : vous réglez et lancez la partie. | You are the host: you set up and start the game. |
| `room.lobby.read_only` | — | Seul l'hôte peut modifier les réglages. | Only the host can change the settings. |
| `room.lobby.settings_updated` | — | L'hôte a modifié les réglages. | The host changed the settings. |
| `room.lobby.waiting_for_host` | — | En attente du lancement par l'hôte. | Waiting for the host to start. |
| `room.lobby.waiting_next_game` | — | Une partie est en cours : vous jouerez la prochaine. | A game is in progress: you will play the next one. |
| `room.lobby.launch` / `launching` | — | Lancer la partie / Lancement… | Start game / Starting… |
| `room.lobby.need_players` | `:min` | Il faut au moins :min joueurs connectés. | At least :min connected players are needed. |
| `room.lobby.leave` / `leave_confirm` | — | Quitter le salon / Quitter le salon ? Vous pourrez revenir avec le lien. | Leave room / Leave the room? You can come back with the link. |
| `room.lobby.presets_title` / `settings_title` / `changes_title` | — | Presets / Réglages / Réglages ajustés | Presets / Settings / Settings adjusted |
| `room.lobby.preset_grayed` | `:frames` | Pas assez de films pour ce preset : jouable avec :frames images par manche. | Not enough movies for this preset: playable with :frames frames per round. |
| `room.lobby.preset_unplayable` | — | Pas assez de films pour ce preset. | Not enough movies for this preset. |
| `room.replay.action` / `waiting` | — | Rejouer / En attente de l'hôte pour rejouer. | Play again / Waiting for the host to play again. |
| `room.expired.title` / `description` / `create` / `home` | — | Salon expiré / Ce salon a été fermé après une période d'inactivité. Créez-en un nouveau pour rejouer. / Créer un salon / Accueil | Room expired / This room was closed after a period of inactivity. Create a new one to play again. / Create a room / Home |

Chaque cellule « a / b » représente plusieurs clés, dans l'ordre indiqué.

**D55 du 02/10 — amendé le 02/10.** `room.create.intro` et `room.solo.intro` (`60` § 16.4) ne parlent plus d'avatar. `room.lobby.avatar_taken` est le refus du geste `room.avatar.update` (clé serveur) ; le refus hors lobby réemploie `room.refusal.not_in_lobby`. Le sélecteur du lobby réemploie `common.avatar.picker.label` et `common.avatar.picker.taken` (`40` § 6.7) ; ses libellés propres (titre, aide, envoi) vivent sous `room.lobby.avatar.*`, clés posées par le lot client et nées en FR et EN dans le même commit. Le champ de pseudo de l'accueil a ses clés sous `common.home.*` (`90` § 4.7), l'accueil ne chargeant que `common` et `legal`.

Les deux clés `room.identity.*` donnent au champ de pseudo de `room/create` et `room/join` son libellé visible et son aide, que ce tableau ne donnait pas. Aucune clé existante ne convenait : `validation.attributes.nickname` n'est pas expédié au client, et `common.avatar.picker.label` est la légende du sélecteur d'avatar, désormais au lobby. Les bornes restent des props (§ 6.2), formatées par `Intl.NumberFormat` — amendé le 28/09 (E101-4 ; formulation au porteur, points restés ouverts, n° 23).

### 20.4 Validation

| Clé | Placeholders | FR | EN |
|---|---|---|---|
| `validation.room_settings.not_editable` | `:attribute` | Le réglage :attribute ne peut pas être modifié depuis cet onglet. | The :attribute setting cannot be changed from this tab. |
| `validation.room_settings.theme_keys` | `:attribute` | Le réglage :attribute contient un thème inconnu ou retiré du site. | The :attribute setting contains an unknown or withdrawn theme. |
| `validation.room_settings.capacity_below_headcount` | `:count` | Le salon compte déjà :count joueurs : les places ne peuvent pas descendre en dessous. | The room already has :count players: seats cannot go below that. |
| `validation.attributes.themeKeys` | — | thèmes | themes |
| `validation.attributes.publicId` | — | joueur | player |

`validation.attributes.publicId` nomme la cible d'un transfert manuel (§ 11.4) dans le message d'un corps sans cible, validé par `HandOverHostRequest` — amendé le 28/09 (E111-3 ; formulation au porteur, points restés ouverts, n° 23). `validation.attributes.preset` (« preset », FR et EN) est livrée par `60` (L60-15, 60 § 19.4) ; elle nomme aussi le champ `preset` de `ApplyRoomPresetRequest`, qui retombait jusque-là sur le mot brut — amendé le 28/09 (E86-3, E121-7).

**Retraits** (A-37 : aucun libellé snake_case sans consommateur) : `validation.attributes.frames_per_round`, `validation.attributes.rounds_count` et `validation.attributes.room_code`. Le code de salon voyage dans l'URL et n'est jamais un champ posté : un code mal formé répond 404 (§ 7.2), et le champ de code de l'accueil (`90`) navigue vers `/r/{code}` par Wayfinder. Si un formulaire poste un jour un code, le champ s'appellera `roomCode`. `validation.attributes.nickname` reste, avec ses consommateurs (`StoreRoomRequest`, `JoinRoomRequest`).

### 20.5 [J2] Configurations

| Clé | Placeholders | FR | EN |
|---|---|---|---|
| `room.configs.title` / `save` / `name_label` / `load` / `default` / `set_default` / `rename` / `delete` | — | Mes configurations / Enregistrer ces réglages / Nom / Charger / Par défaut / Utiliser par défaut / Renommer / Supprimer | My configurations / Save these settings / Name / Load / Default / Use as default / Rename / Delete |
| `room.configs.overwrite_confirm` / `delete_confirm` | `:name` | Remplacer « :name » ? / Supprimer « :name » ? | Replace “:name”? / Delete “:name”? |
| `room.configs.guest_hint` | — | Créez un compte pour enregistrer vos réglages. | Create an account to save your settings. |
| `room.configs.errors.limit` | `:max` | Vous avez atteint la limite de :max configurations. | You have reached the limit of :max configurations. |
| `room.configs.errors.name_taken` | — | Une configuration porte déjà ce nom. | A configuration already has this name. |
| `room.configs.errors.name_empty` | — | Le nom doit contenir au moins une lettre ou un chiffre. | The name must contain at least one letter or digit. |

### 20.6 [J2] Onglets et feuille mobile

Textes du § 3.3 (onglets au motif ARIA tabs, feuille plein écran sur mobile), introduits par le lot L50-10 :

| Clé | Placeholders | FR | EN |
|---|---|---|---|
| `room.settings.tabs.simple` | — | Simple | Simple |
| `room.settings.tabs.advanced` | — | Avancé | Advanced |
| `room.settings.advanced_sheet.title` | — | Réglages avancés | Advanced settings |
| `room.settings.advanced_sheet.description` | — | Paliers, barème et limites de saisie. | Tiers, scoring and answer limits. |

Ces textes sont normatifs sur leur **sens** et leurs **placeholders**. Leur formulation peut être relue par le porteur sans amender cette spec, tant que la symétrie tient.

---

## 21. Récapitulatif des routes et des pages

**Routes**, toutes dans `routes/game.php` [nouveau], `require`-é depuis `routes/web.php`, sauf `saved-configs.*` [J2], qui vit dans `routes/settings.php`.

| Nom | Méthode et chemin | Middleware | Jalon |
|---|---|---|---|
| `room.create` | GET `/r/new` | `translations:room,legal`, `throttle:game-read` | J1 |
| `room.store` | POST `/r` | `throttle:room-create` | J1 |
| `room.show` | GET `/r/{room}` | `translations:game,room,legal`, `throttle:game-read` (`game.appearance` retiré, D56 du 02/10 — amendé le 02/10) | J1 |
| `room.entry` | GET `/r/{room}/join` | `translations:room,legal`, `throttle:game-read` | J1 |
| `room.join` | POST `/r/{room}/join` | `throttle:room-join` | J1 |
| `room.settings.update` | PATCH `/r/{room}/settings` | `seat.active`, `throttle:game-write` | J1 |
| `room.settings.preset` | POST `/r/{room}/settings/preset` | idem | J1 |
| `room.launch` | POST `/r/{room}/launch` | idem | J1 |
| `room.replay` | POST `/r/{room}/replay` | idem | J1 |
| `room.players.kick` | POST `/r/{room}/players/{target}/kick` (jamais `{player}`, § 11.3) | idem | J1 |
| `room.host.transfer` | POST `/r/{room}/host` | idem | J1 |
| `room.leave` | POST `/r/{room}/leave` | idem | J1 |
| `room.avatar.update` | POST `/r/{room}/avatar` (§ 8.1, D55 du 02/10 — amendé le 02/10) | idem | J1 |
| `room.settings.load` | POST `/r/{room}/settings/load` | `auth`, `seat.active`, `throttle:game-write` | J2 |
| `room.configs.store` | POST `/r/{room}/configs` | `auth`, `seat.active`, `throttle:game-write` | J2 |
| `saved-configs.{index,update,destroy,default}` | `/settings/configs…` | `auth`, `translations:account,room,legal` | J2 |

`{room}` est contraint au motif tolérant `RoomCode::ROUTE_PATTERN`, déclaré par `Route::pattern()` en tête de `routes/game.php` (§ 6.3). Les URL sont **toujours** produites par Wayfinder.

**Pages.**

| Page | Coquille | Jalon |
|---|---|---|
| `room/create`, `room/join` | `PublicLayout`, domaines `room` et `legal` | J1 |
| `game/lobby` : page unique du salon, du lobby au podium, rendue par `room.show` dans tout statut non archivé (§ 7.2 ; 90 § 2.1 ; contrat C16 § 2.1) | `GameLayout`, domaines `game`, `room` et `legal` | J1 |
| `game/room-expired` | `GameLayout`, domaines `game`, `room` et `legal` | J1 |

Aucune page `game/room` n'est rendue par ce document (points restés ouverts, n° 0).

---

## Exigences adressées à 10

Aucune colonne, aucun index et aucun cas d'enum nouveaux ne sont demandés par ce document. Il **consomme** les exigences consolidées suivantes :

- **E10-01** — `player.kicked_at`, `timestamp(3)` nullable, `#[Hidden]` (D15 du 23/09) : refus du jeton expulsé (§ 11.3).
- **E10-11 (a)** — la charge client des réglages expose `themeKeys`, jamais `themeIds` ; le stockage reste en `themeIds` (§ 2.6).
- **E10-20** — prédicat de service de `/f/`, partie (3) : appartenance par `game_player`, `first_round_number` NULL ou ≤ `round_number` (§ 15.2).
- **E10-26** — bornes 1 et 2 refusées par `fromInput()`, borne 3 à la garde, bornes 4 et 5 en avertissements (§ 4).
- **E10-27** — énumération de `PlatformLimits` : `B_max(N)` en pourcentage entier, non surchargeable. **Précision de ce document** : `tierGraceMs` et `preloadLeadMs` ne sont pas non plus lus en configuration (§ 2.3).
- **E10-28** — `room.status = playing` du lancement au « Rejouer », podium compris ; `launched_at` posé au premier lancement seulement (§ 12).
- **E10-29** — l'attribut exposé est `roundsCount`, jamais `rounds_count` (§ 1).
- **E10-30** — « purge du lobby » devient « archivage anticipé du lobby » (§ 16).
- **E10-34** — liste `#[Hidden]` de `Player` alignée sur le code, `kicked_at` compris (§ 7.2, § 8).
- **E10-34bis** — la création pose l'hôte par l'action de transfert, dans la même transaction (§ 6.4).
- **E10-35** — unicité du pseudo sur tous les sièges du salon, partis et expulsés compris (§ 7.3).
- **E10-36** — `ended_at` écrite exclusivement par `FinalizeGame` ; `ended_at IS NULL` ⟺ statut non terminal (§ 13, R5).
- **E10-37** — `draw_pool_size` compté en œuvres ; justification « réduirait le champ » retirée (§ 12.4).
- **E10-38** — `scoring_version` écrite depuis `ScoringRules::VERSION` ; B_max en pourcentage entier (§ 12.1).
- **E10-41** — colonnes figées de `game`, une seule partie à `ended_at` NULL par salon, toute ligne `game` née de `OpenGame` (§ 12.6, § 12.7).
- **E10-42** — `game_player` créé au lancement pour chaque siège non parti, `display_*` gelés, `first_round_number = 1` (§ 12.3).
- **E10-45** — manches de réserve à `round_number` NULL, qui reçoivent au remplacement le numéro de la manche annulée (§ 15.2).
- **E10-49** — `round_player` créé à `T₁`, jamais au lancement (§ 12.3, O9).
- **E10-51** — ordre de verrouillage global `room → player → game → round → round_player` (§ 7.3, § 11.3, § 12.2).
- **E10-56** — fenêtre unique de mémoire du salon, `RoomMemoryWindow::since()` (§ 9, via le contrat C2).
- **E10-64** — vivier compté en œuvres, branche sans thème, « joué » = `started_at ≤ now` (§ 9, via le contrat C2).

---

## Amendements à d'autres documents

Ce document ne modifie ni `00`, ni `05`, ni `10`, ni `questions-ouvertes.md`, ni `CLAUDE.md`, ni `REPRISE.md`. L'application des amendements est un travail séparé.

**Amendements consolidés dont il dépend :**

- **A-01** (00 l.97) — borne 4 : motif d'accessibilité seul ; `R` n'est plus une fenêtre de préchargement.
- **A-09** (00 l.68-86 et l.366) — colonne de jalon au tableau des réglages ; `allowLateJoin`, troisième variable d'ajustement du J1 (ce second point est sans effet depuis D35 du 23/09 : réglage Simple livré au J1, § 15.4 — amendé le 23/09).
- **A-10** (00 l.90 et l.178) — `B_max = min(50 %, 100 % / (N − 1))`, en pourcentage entier ; mode sans gradient réécrit.
- **A-11** (00 l.96 et l.117) — marge `drawSubstituteMargin()`, comptée en œuvres.
- **A-12** (00 l.100) — « jamais inlançable » s'entend des bornes ; Hardcore grisé au J1.
- **A-13** (00 l.104 et l.116) — `noRepeatMovies`, quatrième réglage fautif ; remède « nouveau salon » au J1.
- **A-14** (00 l.106, l.120 et l.134) — « Rejouer », geste d'hôte refusé pendant le drainage ; expulsion avec jeton refusé.
- **A-37** (05 l.100) — champs postés en camelCase, `themeKeys` ; aucun libellé snake_case sans consommateur.
- **A-56** (questions-ouvertes l.296) — procédure de drainage borné.
- **A-60** (questions-ouvertes l.320) — `B_max` en pourcentage entier.
- **A-68** (`CLAUDE.md` §2) — `B_max(N)`, constante d'instance de `PlatformLimits`.
- **A-70** (`CLAUDE.md` §2) — réglages figés « du lancement au « Rejouer » de l'hôte ».
- **A-73** (`CLAUDE.md` §5) — `RoomSettingsEditor`, `WriteRoomSettings` seul écrivain, `config/game.php › platform` lu par `PlatformLimits` seul.

**Amendements non consolidés, signalés au porteur :**

- **00 l.135** — « Lobby jamais lancé purgé après 2 h » devient « archivé par anticipation après 2 h d'inactivité (archivage forcé, jamais suppression) ». La résolution de la contradiction visait 00 **et** 10, mais seule la partie `10` (E10-30) figure à la liste consolidée.
- **E10-27 / 10 § 6.1 l.645-649** — ajouter « `tierGraceMs` et `preloadLeadMs` : constantes de code, non surchargeables par configuration ». C'est l'effet de l'arbitrage du rédacteur en chef du 23/09 sur les constantes d'instance (§ 2.3).
- **`CLAUDE.md` §8** — « Suite de tests cassée : `RefreshDatabase` commenté » est périmé : il est actif dans `tests/Pest.php`. Ce point relève de `100`.
- **10 § 6.1 l.632** — « Les FormRequest des deux onglets délèguent entièrement via une règle `ValidRoomSettings` » devient « l'action d'écriture valide sous le verrou du salon l'entrée composée par `RoomSettingsEditor`, puis `fromInput()` ; aucune règle `ValidRoomSettings` » (§ 3.1). E10-26 porte sur la même ligne mais ne couvre que les bornes.
- **10 § 6.1** (chemin CHARGEMENT, « borne resserrée = écrêtage ») — ajouter : « un resserrement d'une borne de `RoomSettingsBounds` incrémente `RoomSettings::VERSION` » (§ 2.1, règle 4). Les bornes lues dans `PlatformLimits` en sont exclues et rattrapées à l'écriture (§ 10).

---

## Lots d'implémentation

Chaque estimation inclut la barre « terminé » : tests verts, textes FR et EN, états de chargement, d'erreur et de déconnexion, parcours clavier, avec le facteur 1,5 à 2 intégré. Ces heures sont des **mesures de taille**, jamais un calendrier ni un budget à tenir : le développement est confié à l'IA, et le chemin critique du J1 est humain (D36 du 23/09) — amendé le 23/09. Les lots sont rangés par objet ; l'ordre de livraison suit leurs dépendances et l'ordre « curation d'abord » (D37 du 23/09 ; « Dépendances à lire avant d'ordonner », sous le tableau) — amendé le 23/09.

| Lot | Jalon | Objet | Dépend de | Heures |
|---|---|---|---|---|
| L50-1 | J1 | Socle : `PlatformLimits`, `config/game.php`, `RoomSettingsBounds::toClient()`, constantes de rapport, constantes de `RoomSettingsEditor` | — | 4–6 |
| L50-2 | J1 | Éditeur Simple, point d'écriture unique, presets et présentateur (serveur), types client des réglages | L50-1 ; `30` L30-2 et L30-3 (`PoolQuery`, `PoolReporter`, `types/pool.ts`) ; `40` L40-1 (`PlayerTokenManager`) ; `60` L60-2 (limiteur `game-write`), L60-3 (`SettingsChanged`) et L60-4 (`seat.active`, `BroadcastLobbyState`), dépendance croisée avec L60-4 (points restés ouverts, n° 13) | 6–8 |
| L50-3a | J1 | Code de salon, identifiant public de siège, motif de route et miroir client du code | L50-2 (`routes/game.php`) ; `90` L90-1 (`lib/game` dans `WATCHED`) ; `100` L100-3 (Vitest) | 2–3 |
| L50-3b | J1 | Création, entrée et prise de siège, pages `room/create` et `room/join` | L50-3a ; `40` [J1] (identité, avatars, jeton) ; `90` L90-3 (`PublicLayout`) et L90-4 (clé `legal.terms_notice`, route `legal.terms`) ; `60` L60-2 (limiteur `game-read`) et L60-3 (`SeatJoined`) | 5–7 |
| L50-4 | J1 | Page `game/lobby`, page unique du salon : sièges, partage, abonnement temps réel, états | L50-3b ; `60` L60-3 (canaux), L60-4 (`GameStateBuilder` au lobby, `ClaimSeatTab`, `seat.active`), L60-9 (magasin, client temps réel) et L60-12 (branche de partie de `GameStateBuilder`, § 8.1) ; `90` L90-7 (`GameLayout` ; `ForceGameAppearance` retiré par D56 du 02/10 — amendé le 02/10), `ConnectionBanner`, `GameAnnouncer` | 5,5–7,5 |
| L50-5 | J1 | Formulaire Simple, presets, vivier et avertissements (client) | L50-2, L50-4 ; `30` (`types/pool.ts`) ; `90` (`slider`, `switch`, `radio-group` installés) ; `100` L100-3 (Vitest) | 6,5–8 |
| L50-6 | J1 | Pouvoirs de l'hôte : expulsion, transfert manuel, départ, `RoomPolicy` | L50-3b, L50-4 ; `40` L40-1 (migration `add_kicked_at_to_player_table`, `Player::wasKicked()`, état `PlayerFactory::kicked()`, `PlayerTokenManager::seatIn()` et `wasKickedFrom()`) ; `60` L60-3 (`SeatUpdated`, `HostChanged`, `SeatKicked`) et L60-7 (`SeatInputClosed`) | 6–8 |
| L50-7a | J1 | Lancement et drainage | L50-2, L50-3b ; `30` (tirage) ; `60` L60-1 (`EngineConstants`), L60-3 (`GameLaunched`) et L60-5 (`MaterializeDraw`, `ScheduleRound`) ; `70` (`AnswerRules::VERSION`) ; `80` (`ScoringRules::VERSION`) ; `100` L100-5 (`DeployDrain`) | 6–8 |
| L50-7b | J1 | Gel, « Rejouer », concurrence du lancement | L50-7a ; `60` L60-3 (`RoomReplayed`) ; `80` (`FinalizeGame`) ; `100` L100-1 (suite `Concurrency`, `requireMysql()`) et L100-5 | 4–6 |
| L50-8 | J1 | Échéances : archivage, archivage anticipé du lobby, salon expiré | L50-3b ; `60` L60-3 (`RoomArchived`) et L60-13 (battement) ; `100` (cron `schedule:run`) | 3–5 |
| L50-9 | J1 | Retardataires (lot J1 ordinaire, D35 du 23/09 — amendé le 23/09) | L50-3b, L50-7a ; `60` L60-6 (`round_player` à `T₁`, E10-20 ; ordre de jeu, 60 § 1.2) ; `100` L100-1 (suite `Concurrency`) | 3–5 |
| L50-10 | J2 | Onglet Avancé | J1 livré | 7–8 |
| L50-11 | J2 | Sélecteur de thèmes | L50-10 ; `30` (`themeSelectorVisible()`, `ProposableThemes::all()`) | 2,5–3,5 |
| L50-12 | J2 | Configurations sauvegardées : enregistrer, renommer, supprimer, défaut | J1 livré ; `40`-J2 (comptes de production) ; `70` (`fold()`) | 6–8 |
| L50-13 | J2 | Chargement d'une configuration, configuration par défaut à la création | L50-12, L50-10 | 4–5 |

**Dépendances à lire avant d'ordonner.**
- **Ordre « curation d'abord » (D37 du 23/09) — amendé le 23/09.** Seul L50-1 est sur le chemin du lot pilote : L20-4 et L30-2 en dépendent. Les autres lots de `50` se livrent pendant la curation. Deux dépendances de `100` envers `50` ne retardent plus le pilote :
  - la mise en service du VPS (L100-9) et son hook de déploiement (L100-6) se livrent sans drainage tant qu'aucun moteur n'existe ; L100-5 (`DeployDrain`), dont dépendent L50-7a et L50-7b, s'ajoute avant la première partie sur le VPS (§ 14) ;
  - la purge de L100-8 part d'abord sur les seuls périmètres sans jeu ; le périmètre `stale_room`, qui appelle `ArchiveRoom`, suit L50-8.
- **Constantes de l'éditeur et rapport de vivier.** L30-3 lit `RoomSettingsEditor::SIMPLE_KEYS` et `ADVANCED_KEYS` (test « chaque cas de PoolFault est une clé postable de RoomSettingsEditor ») : ces constantes, avec `ADVANCED_TAB_AVAILABLE`, sont posées par L50-1, qui ne dépend de rien. L50-2, qui écrit `simple()` et `advanced()`, dépend de L30-3 (`PoolReporter`, `types/pool.ts`). Aucun cycle : L50-1 → L30-2 → L30-3 → L50-2.
- **Types client des réglages.** `resources/js/types/room-settings.ts` (types seuls) naît en L50-2, pas en L50-4 : le fil de `60` l'importe pour typer `settings.changed` et `room.replayed` (60 § 11.4, contrat C7 § 2.3), et L50-4 dépend de L60-9. Tout lot de `60` qui porte cet import dépend donc de L50-2 ; si l'import reste dans `types/game-wire.ts` dès L60-2, dont L50-2 dépend pour `game-write`, le cycle ne se rompt qu'en posant l'import au plus tôt en L60-9 (signalé à `60`, points restés ouverts, n° 16).
- **Page du salon après la resynchronisation de partie.** L50-4 dépend de L60-12, qui écrit la branche de partie de `GameStateBuilder` : `room.show` rend `game/lobby` en `playing` avec `state` construit sur la partie (§ 8.1). L60-12 dépendant de lots de `70` et de `80`, L50-4, puis L50-5 et L50-6, se livrent après eux ; aucun de ces lots ne dépend de L50-4, et le graphe reste sans cycle.
- **`RoomSettingsMatrixTest`** (contrat C18 § 2.7) est écrit par le lot L100-3 de `100`, avec son jeu de données, et non par L50-1 : il exerce `RoomSettings::validate()` [existant] selon la règle d'interaction du § 4.1. Le compter dans les deux lots créerait une dépendance circulaire (L50-1 attendant le jeu de données de `100`) et des heures en double. Le premier test de `PresetValidityTest` parcourt `SettingPresetKey::cases()` sans ce jeu de données.
- **Dépendance croisée L50-2 ↔ L60-4.** L60-4 dépend du `RoomSettingsPresenter` de L50-2 ; L50-2 dépend de L60-4 pour `seat.active` sur ses routes et pour le dispatch de `BroadcastLobbyState`. `60` ne compte que le second (60 § 22 bis). Ordre retenu : L50-2 livre d'abord l'éditeur, le présentateur et les actions ; ses routes sous `seat.active` et son dispatch se ferment après L60-4, et L50-2 n'est pas déclaré terminé avant (points restés ouverts, n° 13).
- **« L50-7 » dans une autre spec** désigne L50-7a et L50-7b ensemble ; `OpenGame`, que consomme le solo de `60`, est dans L50-7a.
- **« L50-3 » dans une autre spec** désigne L50-3a et L50-3b ensemble (90 L90-8, 60 L60-3). Le lot est scindé pour rester sous 8 h une fois ajoutés le miroir client du code et la mention des CGU ; `lib/game/room-code.ts` est dans L50-3a, les routes `room.create`, `room.show` et `room.entry` dans L50-3b.

### L50-1 — Socle des réglages et des limites (J1, 4–6 h)

**Fichiers :**
- `config/game.php` [nouveau] : sections `platform` (treize clés), `engine` (posée vide, remplie par L60-1, § 2.3 — amendé le 25/09, E9-1) et `room` (deux clés) ;
- `app/Settings/PlatformLimits.php` [modifié] : `speedBonusMaxPercent()`, constantes (dont `MAX_DRAW_SUBSTITUTE_MARGIN` et les noms retenus du § 2.3), `current()`, familles, gardes par accesseur, bornes hautes de `historyWindowMonths()` et `frameUploadMaxKilobytes()`, `toArray()` ;
- `app/Providers/AppServiceProvider.php` [modifié] : `scoped(PlatformLimits::class, …)` (§ 2.3) ;
- `tests/Pest.php` [modifié] : aide `platformLimitsConfigure()` (§ 2.3) — amendé le 25/09 (E9-8) ;
- `app/Settings/RoomSettingsBounds.php` [modifié] : `MIN_CONNECTED_PLAYERS_TO_LAUNCH`, `toClient()`, docblock ;
- `app/Settings/RoomSettings.php` [modifié] : quatre `CHANGE_*`, docblocks (dont celui de `VERSION`, § 2.1) ;
- `app/Settings/RoomSettingsEditor.php` [nouveau] : constantes `SIMPLE_KEYS`, `ADVANCED_KEYS` et `ADVANCED_TAB_AVAILABLE` seulement (§ 3.1), pour que le test des clés postables de `30` (L30-3) ne dépende que de ce lot ;
- `app/Support/Room/RoomRateLimits.php` [nouveau] ;
- retrait de tout appel à `speedBonusMaxFraction`.

**Tests :**
- `tests/Feature/Room/PlatformLimitsTest.php` :
  - « fixe speedBonusMaxPercent à 50, 50, 33 et 25 pour N = 2 à 5 sous la version de score 1 » (la version est lue dans `GameFactory::SCORING_VERSION`, seul domicile existant, jusqu'à ce que L80-1 la remplace par `ScoringRules::VERSION` dans ce test — amendé le 25/09, E9-3)
  - « refuse un N hors bornes pour speedBonusMaxPercent au lieu de l'écrêter »
  - « ignore toute configuration de B_max »
  - « ignore toute configuration de tier_grace_ms et de preload_lead_ms »
  - « n'a aucun accesseur paramétré par un utilisateur, un plan ou un siège »
  - « déclare une clé game.platform par accesseur configurable, et réciproquement »
  - « garde preloadLeadMs dans [1500, 2500], sous la révélation minimale » (le nom de la feuille disait « écrête » ; il n'y a plus rien à écrêter, § 2.3)
  - « garde deux tier_grace_ms sous la durée minimale de palier »
  - « n'offre jamais moins d'avatars prédéfinis que de sièges » (garde-fous 3 à 5 du § 2.7, bornes fermées acceptées — amendé le 25/09, E9-2 et E9-8)
  - « refuse une marge, une fenêtre, un seuil ou un plafond de recadrage hors bornes », borne haute de la marge comprise : `min(MAX_DRAW_SUBSTITUTE_MARGIN, MAX_DRAW_SUBSTITUTE_MARGIN_UNDER_PAUSE)` acceptée, un de plus refusé, depuis que L60-1 a posé le sixième garde-fou (§ 2.7) — amendé le 25/09 (E10-2)
  - « refuse une fenêtre d'historique au-delà du plafond de douze mois »
  - « refuse un plafond de téléversement au-delà de sa valeur par défaut »
  - « un accesseur statique refuse une configuration hors bornes »
  - « expose en prop exactement les clés du contrat, B_max en pourcentage entier par N »
- `tests/Feature/Room/RoomSettingsContractTest.php` :
  - « déclare un défaut pour chacun des seize champs dans l'ordre de FIELDS »
  - « refuse les clés graceMs, tierGraceMs, preloadLeadMs, speedBonusMaxPercent et speedBonusMaxFraction »
  - « produit une charge utile dont les clés sont exactement FIELDS, en camelCase et dans l'ordre »
  - « garde VERSION à 1 tant que FIELDS est inchangé » (écrit à la lettre, sans instantané des bornes : points restés ouverts, n° 8 — amendé le 25/09, E9-7)
  - « expose au client les bornes de chaque N, sans chaîne et en entiers »
- `tests/Feature/Room/PresetValidityTest.php` : « construit chaque preset livré par le chemin d'entrée de l'hôte sans erreur » (parcourt `SettingPresetKey::cases()`)
- `tests/Feature/Room/RoomRateLimitsTest.php` [ajouté, § 17.3] : « déclare une clé game.room par débit d'entrée, et réciproquement », « refuse un débit d'entrée nul ou négatif » — amendé le 25/09 (E9-6)
- `RoomSettingsMatrixTest` (« refuse exactement les champs déclarés pour chaque combinaison aux bornes », « lève exactement les avertissements déclarés pour chaque combinaison acceptée ») n'est **pas** dans ce lot : il est écrit par L100-3 sur la règle d'interaction du § 4.1.

### L50-2 — Éditeur Simple, écriture unique, presets (serveur) (J1, 6–8 h)

**Fichiers :**
- `app/Settings/RoomSettingsEditor.php` [complété] : `simple()`, et `advanced()` qui lève tant que `ADVANCED_TAB_AVAILABLE` est faux ;
- `app/Actions/Room/{WriteRoomSettings,UpdateRoomSettings,ApplyRoomPreset}.php` [nouveaux] ;
- `app/Enums/RoomRefusal.php` [nouveau, avancé de L50-7a] et `app/ValueObjects/Room/SettingsWriteOutcome.php` [nouveau, nom libre] : les deux actions d'écriture rendent leurs refus `not_host` et `not_in_lobby` en données, dans le vocabulaire de C6, et la parité du miroir `RoomRefusalCode` se prouve dès ce lot. Les clés `room.refusal.*` restent à L50-7a — amendé le 28/09 (E84-2) ;
- `app/Support/Room/RoomSettingsPresenter.php` [nouveau] ;
- `resources/js/types/room-settings.ts` [nouveau, types seuls, dans `WATCHED`, § 2.6] : livré ici et non avec la page du lobby, parce que le fil de `60` l'importe (dépendances à lire avant d'ordonner) ;
- `app/Casts/RoomSettingsCast.php` [modifié : `ComparesCastableAttributes`] ;
- `app/Policies/RoomPolicy.php` [nouveau, méthode `updateSettings`] ;
- `app/Http/Controllers/Room/{RoomSettingsController,RoomPresetController}.php` et `app/Http/Requests/Room/{UpdateRoomSettingsRequest,ApplyRoomPresetRequest}.php` [nouveaux], ces derniers ne validant que la forme du corps (§ 3.1) ;
- `routes/game.php` [nouveau] et `routes/web.php` [modifié] ;
- `lang/{fr,en}/validation.php` [modifiés] : trois clés `room_settings`, `attributes.themeKeys`, retrait des trois libellés snake_case.

**Tests :**
- `tests/Feature/Room/RoomSettingsEditorTest.php` :
  - « en Simple, redérive attemptsPerRound resté au défaut de l'ancien D »
  - « en Simple, conserve un attemptsPerRound personnalisé quand D change »
  - « en Simple, fait suivre un barème par défaut quand N change, sans rapport »
  - « en Simple, réinitialise un barème personnalisé quand N change et le rapporte reset »
  - « en Simple, réégalise des paliers inégaux et le rapporte equalized »
  - « ne modifie jamais D ni N de lui-même »
  - « refuse toute clé hors SIMPLE_KEYS et advanced: true avec not_editable »
  - « traduit themeKeys en themeIds et refuse une clé inconnue ou dépubliée »
- `tests/Feature/Room/RoomSettingsWriteTest.php` :
  - « garde les cinq projections égales au value object après chaque écriture »
  - « refuse toute écriture de réglages hors du statut lobby »
  - « refuse une capacité sous le nombre de sièges tenus »
  - « accepte une écriture qui ne baisse pas la capacité quand l'effectif la dépasse »
  - « ramène une capacité devenue supérieure à roomSeats sans refuser l'écriture »
  - « ne laisse au J1 aucune ligne room avec advanced vrai ni un champ avancé hors de son défaut »
  - « diffuse l'état des réglages en données, sans identifiant interne ni chaîne traduite »
  - « dispatche une seule diffusion anti-rebondie par salon après validation, sur la file game »
  - « programme la diffusion anti-rebondie en millisecondes arrondies à la seconde supérieure, jamais en secondes »
  - « rend le rapport de changements à l'auteur seul, jamais au salon »
- `tests/Feature/Room/PresetOptionsTest.php` :
  - « applique les seize champs du preset, thèmes vidés et capacité au plafond »
  - « grise un preset dont le vivier du salon est insuffisant et propose le N jouable le plus proche »
  - « calcule le grisage avec la non-répétition et la fenêtre du salon »

### L50-3a — Code de salon, identifiant public de siège, miroir client (J1, 2–3 h)

**Fichiers :**
- `app/Support/Room/{RoomCode,SeatPublicId}.php` [nouveaux] ;
- `app/Models/Room.php` [modifié : `normalizeCode()` délègue, motif vérifié avant toute requête ; le docblock de `resolveRouteBinding()`, qui décrit déjà le recyclage à l'archivage, reste juste] ;
- `routes/game.php` [complété : `Route::pattern('room', RoomCode::ROUTE_PATTERN)` en tête, § 6.3] ;
- `database/factories/{RoomFactory,PlayerFactory}.php` [modifiés : lecteurs des générateurs] ;
- `resources/js/lib/game/room-code.ts` [nouveau, § 6.3] : `ROOM_CODE`, `normalizeRoomCode()` et `isWellFormedRoomCode(code: string): boolean`, dérivés des seules constantes `alphabet` et `length`, miroir de `RoomCode::ALPHABET` et `LENGTH` ; sous `lib/game/` pour entrer dans `WATCHED` (contrat C16 § 2.11) ; le champ de code de l'accueil (`90`) emploie les deux fonctions ;
- `tests/Fixtures/room/room-codes.json` [nouveau, écrit à la main, jamais par un test] ;
- `tests/Frontend/room/room-code.test.ts` [nouveau].

**Tests :**
- `tests/Feature/Room/RoomCodeTest.php` :
  - « tire six caractères dans l'alphabet non ambigu »
  - « retrouve un salon quelle que soit la casse ou les espaces saisis »
  - « le miroir client isWellFormedRoomCode accepte et refuse exactement les codes que RoomCode::isWellFormed() accepte et refuse » (lit le jeu partagé, ne l'écrit jamais)
  - « le miroir client room-code.ts reflète ALPHABET et LENGTH de RoomCode »
- Vitest, `tests/Frontend/room/room-code.test.ts` : « normalise et reconnaît chaque cas du jeu partagé comme le serveur »

### L50-3b — Création, entrée, prise de siège (J1, 5–7 h)

**Fichiers :**
- `app/Actions/Room/{CreateRoom,TakeSeat,TransferHost}.php` [nouveaux ; `TakeSeat` avec `repairHost` ; `TransferHost::to()` et `automatic()`] ;
- `app/Enums/JoinRefusal.php` [nouveau] ;
- `app/Http/Controllers/Room/{RoomController,RoomEntryController}.php` et `app/Http/Requests/Room/{StoreRoomRequest,JoinRoomRequest}.php` [nouveaux] ;
- `app/Providers/FortifyServiceProvider.php` [modifié : `room-create`, `room-join`] ;
- `resources/js/pages/room/{create,join}.tsx` [nouveaux], mention des CGU sous le bouton d'envoi comprise (§ 6.2, § 7.2 ; clé et route livrées par L90-4) ;
- `lang/{fr,en}/room.php` [modifiés : `room.create.*`, `room.join.*`].

**Vérifié à la main dans la liste « terminé »** (aucun DOM en Pest ni en Vitest au J1, contrat C18 § 2.4) : mention `legal.terms_notice` sous le bouton d'envoi de `room/create` et de `room/join`, lien vers `legal.terms` ouvert en nouvel onglet, pseudo saisi intact au retour (amendé le 02/10) ; aucune mention dans les états `kicked` et `full`.

**Tests :**
- `tests/Feature/Room/RoomCreationTest.php` :
  - « crée le salon avec les réglages par défaut et fait du créateur l'hôte »
  - « pose l'hôte par l'action de transfert dans la transaction de création »
  - « n'émet qu'un seul host.changed à la création »
  - « frappe un player_token à la création et jamais à l'affichage du formulaire »
  - « écrit les cinq projections égales au value object dès la création »
  - « limite le nombre de salons créés par adresse »
- `tests/Feature/Room/RoomCodeTest.php` [complété, fichier de L50-3a] :
  - « n'attribue jamais un code porté par un salon actif et recycle le code d'un salon archivé »
  - « résout /r/abcdef comme /r/ABCDEF »
  - « répond 404 à un code mal formé sans interroger la base »
- `tests/Feature/Room/SeatTakingTest.php` :
  - « refuse un pseudo déjà pris dans le salon, sièges partis et expulsés compris »
  - « traduit une collision de pseudo qui atteint l'index unique au lieu de laisser fuiter une 1062 »
  - « ne revalide jamais le pseudo d'un siège repris »
  - « reprend le siège du jeton avant tout comptage de capacité »
  - « compte l'effectif présent et non l'historique des sièges »
  - « refuse un salon complet avec un message traduit »
  - « laisse entrer dans un salon en partie et fait attendre la partie suivante quand les retardataires sont fermés »
  - « re-signe le player_token avec l'avatar attribué » (amendé le 02/10, D55 du 02/10 ; les tests d'attribution et d'unicité sont au lot L50-14)
  - « écrit la langue effective de la requête sur le siège »
- `tests/Feature/Room/RoomEntryPageTest.php` :
  - « redirige un visiteur sans siège vers la page d'entrée publique »
  - « n'expose aux visiteurs sans siège ni pseudo ni identifiant interne »
  - « annonce le salon complet, la partie en cours et le refus d'un expulsé »
  - « redirige sans erreur vers la page de salon expiré quand on rejoint un salon archivé »
  - « ne force jamais le thème sombre sur les pages d'entrée et de création » (réécrit par `90` L90-10 : pages d'entrée et de création sombres quel que soit le cookie, D56 du 02/10 — amendé le 02/10)

### L50-4 — Page du salon `game/lobby` (J1, 5,5–7,5 h)

**Fichiers :**
- `resources/js/pages/game/lobby.tsx` [nouveau] : page unique du salon, état de lobby composé par `50`, états de partie délégués aux composants de `60` et `80` selon le magasin (§ 7.2, § 8.1) ; refus rendus en `Alert` au rôle `note` et annoncés, jamais en toast (§ 8.1) ;
- `resources/js/components/room/{seat-list,share-code}.tsx` [nouveaux] ;
- `resources/js/components/room/pool-status.tsx` [nouveau, avancé de L50-5] : compteur, blocage nommant le réglage fautif, `room.pool.themes_pruned` et les cinq remèdes, hôte seul. Le bouton « Lancer la partie » de ce lot est désactivé avec son motif quand le vivier est bloqué (§ 8.1), et le blocage doit nommer sa cause pour tous (§ 9.2) — amendé le 28/09 (E110-1) ;
- `resources/js/hooks/game/use-lobby-state.ts` [nouveau] : réactions du § 8.2, dont le rechargement partiel `settings` et `presets` après une resynchronisation au lobby ;
- `RoomController@show` [complété : rendu de `game/lobby` dans tout statut non archivé, `$game` résolu selon 60 § 12.2, réparation d'hôte] ;
- `lang/{fr,en}/room.php` : `room.lobby.*` hors gestes d'hôte, limité aux clés qu'appelle le code du lot (les clés des composants de L50-5 arrivent avec eux), et `room.pool.*` [avancé de L50-5] — amendé le 28/09 (E110-1, E110-2) ;
- `tests/Feature/I18n/TranslationCoverageTest.php` : la part de l'extension de L50-5 qui porte sur `PoolFault` et `PoolRemedyKind` [avancée de L50-5] — amendé le 28/09 (E110-1).

`resources/js/types/room-settings.ts` est consommé ici, livré par L50-2.

**Tests :**
- `tests/Feature/Room/LobbyPageTest.php` :
  - « rend le lobby au siège du jeton avec l'état des réglages, les bornes par N et les presets »
  - « frappe un jeton d'onglet au rendu et le passe en prop seatToken »
  - « recalcule le vivier à chaque rendu du lobby »
  - « rend game/lobby au siège du jeton quand le salon est en partie, podium compris, sans changer de page, avec un state qui porte la partie »
  - « un rechargement partiel de settings et presets sous X-Seat-Token recalcule le vivier sans frapper de nouveau jeton d'onglet »
  - « ne met jamais le code du salon dans le titre de la page »
- `tests/Feature/Room/LobbyPayloadTest.php` :
  - « n'envoie jamais au client le player_token, son tid ni son hash »
  - « n'envoie aucun identifiant interne de salon, de siège ni de thème dans les props du lobby »
- `tests/Feature/Public/ShellTest.php` (fichier de `90`, test ajouté par ce lot, dépendance inversée signalée par 90 § L90-7) : « rend le lobby en sombre quelle que soit l'apparence du visiteur » (preuves de forçage retirées par `90` L90-10, D56 du 02/10 — amendé le 02/10)

### L50-5 — Formulaire Simple, presets, vivier, avertissements (client) (J1, 6,5–8 h)

**Fichiers :**
- `resources/js/components/room/{preset-picker,settings-warnings,settings-changes}.tsx` [nouveaux] ; `room-settings-form.tsx` [complété] : né en L50-9 avec son seul interrupteur `allowLateJoin` (E116-6), il reçoit ici les autres champs Simple — amendé le 28/09 (E116-6, E120-10) ;
- `pool-status.tsx` : **livré par L50-4**, rien à compléter. Il rend les cinq `PoolRemedyKind`, dont `clear_themes` (`PATCH { themeKeys: [] }`), atteignable au J1 par une clé de thème postée (30 § 1.4) — seul `disable_no_repeat` est retiré au J1, par le présentateur —, et le message `room.pool.themes_pruned` quand `pool.themesPruned` est vrai (§ 9.2) — amendé le 28/09 (E110-1, E120-7) ;
- `resources/js/lib/room-settings.ts` [nouveau] ;
- `resources/js/hooks/game/use-lobby-state.ts` et `resources/js/pages/game/lobby.tsx` [complétés, fichiers de L50-4] : le rapport de changements est lu du flash `settingsChanges` (`router.on('flash')`), effacé au départ de toute visite qui écrit (`router.on('start')`), annoncé s'il n'est pas vide — amendé le 28/09 (E120-6) ;
- `tests/Fixtures/room/derivations.json` [nouveau] et `app/Console/Commands/RoomDerivationsFixtureCommand.php` [nouveau : `room:derivations-fixture`, désactivée en `production`, lancée à la main, option `--check`, § 4.3 — amendé le 28/09, E120-1] ;
- `tests/Frontend/room/room-settings.test.ts` [nouveau] ;
- `lang/{fr,en}/room.php` : `room.settings.*` (sauf `room.settings.advanced_active`, J2, L50-10), `room.warnings.*`, presets du lobby (`room.lobby.{presets_title, changes_title, preset_grayed, preset_unplayable}`). `room.pool.*` est livré par L50-4 — amendé le 28/09 (E110-1, E120-5).

**Tests :**
- `tests/Feature/Room/RoomSettingsDerivationParityTest.php` : « le jeu de dérivations partagé avec le client correspond au serveur » (il lit le fixture et ne l'écrit jamais)
- `tests/Feature/I18n/TranslationCoverageTest.php`, extension du test existant « carries every key built by an enumerable key constructor » : une clé par code `CHANGE_*` et `WARNING_*` (lus par réflexion sur `RoomSettings`), un libellé et une aide par clé de `SIMPLE_KEYS ∪ ADVANCED_KEYS`, et une option par cas d'`InputDifficulty` [ajout]. La part `PoolFault` / `PoolRemedyKind` est livrée par L50-4 — amendé le 28/09 (E110-1, E120-5). `room.pool.themes_pruned`, clé fixe, est couverte par les tests existants de symétrie FR/EN et d'existence des clés appelées.
- Vitest, `tests/Frontend/room/room-settings.test.ts` :
  - « dérive comme le serveur chaque cas du jeu partagé »
  - « remonte D au minimum du nouveau N et l'annonce »
  - « lève les avertissements aux seuils du serveur »

### L50-6 — Pouvoirs de l'hôte (J1, 6–8 h)

**Fichiers :**
- `app/Actions/Room/{KickSeat,LeaveRoom,HandOverHost}.php` [nouveaux] ;
- `TransferHost` [complété] ;
- `app/Policies/RoomPolicy.php` [complété : `kick`, `transferHost`, `leave`] ;
- `app/Http/Controllers/Room/{KickController,HostTransferController,LeaveRoomController}.php` [nouveaux] ; route `room.players.kick` sur `{target}` ;
- `resources/js/components/room/seat-actions.tsx` [nouveau] ;
- `lang` : gestes d'hôte, et `validation.attributes.publicId` (§ 20.4) ;
- hors liste, noms libres — amendé le 28/09 (E111-3, E111-7) :
  - `app/Actions/Room/SeatDeparture.php` [nouveau] : `markParticipation()` (étape 7 du § 11.3, reprise par le § 11.4) et `reevaluateAfterCommit()` (réévaluation de la fin anticipée, § 8.2), partagés par l'expulsion et le départ, puis par le balayage de présence de `60` ;
  - `app/Http/Requests/Room/HandOverHostRequest.php` [nouveau] : `publicId` requis, chaîne ;
  - `SeatViewPresenter::ofSeat()` [ajout, fichier de `60`] : vue de `seat.updated`, en partie si le siège y a une participation, au lobby sinon ;
  - `tests/Support/Room/HostGestures.php` [nouveau].

La migration `add_kicked_at_to_player_table`, `Player::wasKicked()` et l'état `PlayerFactory::kicked()` appartiennent au lot L40-1 de `40`, dont ce lot dépend.

**Tests :**
- `tests/Feature/Room/KickTest.php` :
  - « refuse le jeton d'un siège expulsé dans le même salon jusqu'à l'archivage »
  - « laisse un jeton expulsé prendre un siège dans un autre salon »
  - « passe le siège expulsé en parti avec left_at et kicked_at au même instant »
  - « conserve les points et marque kicked la participation d'une partie en cours »
  - « refuse à l'hôte de s'expulser lui-même »
  - « ne fait rien sur un second geste d'expulsion »
  - « émet seat.updated au salon et seat.kicked au seul siège expulsé, après validation »
  - « expulse un siège tiers sous seat.active sans exiger que le jeton de l'hôte tienne la cible »
  - « ne réécrit jamais une participation figée par un gel concurrent »
  - « réévalue la fin anticipée de la manche en cours après validation, sans tenir le verrou de manche dans la transaction d'expulsion »
- `tests/Feature/Room/HostTransferTest.php` :
  - « transfère le rôle au joueur connecté le plus ancien quand l'hôte part »
  - « ne rend pas le rôle à l'ancien hôte qui revient »
  - « conserve l'hôte déconnecté tant qu'il n'est pas parti »
  - « répare un hôte sans cible à la lecture au lieu de lever une erreur »
  - « ne transfère le rôle à la demande de l'hôte qu'à un joueur connecté »
  - « ne fait rien quand l'hôte se désigne lui-même »
  - « émet host.changed avec les deux public_id après validation »
- `tests/Feature/Room/LeaveRoomTest.php` :
  - « passe le siège en parti et libère sa place »
  - « transfère le rôle quand l'hôte quitte »
  - « marque left la participation d'une partie en cours et conserve les points »
  - « réévalue la fin anticipée de la manche en cours après un départ volontaire »
- `tests/Feature/Room/RoomPolicyTest.php` :
  - « n'accorde aucun geste de salon à un administrateur qui n'est pas l'hôte »
  - « relit l'autorité d'hôte sous verrou à chaque écriture »

Le prédicat de fin anticipée lui-même est prouvé par `EarlyEndTest` de `60` (« départ ou expulsion », L60-13).

### L50-7a — Lancement et drainage (J1, 6–8 h)

**Fichiers :**
- `app/Actions/Room/LaunchGame.php` et `app/Actions/Game/OpenGame.php` [nouveaux] ;
- `app/ValueObjects/Room/LaunchOutcome.php` [nouveau] ; `app/Enums/RoomRefusal.php` est livré par L50-2 (E84-2), et ce lot n'en ajoute que les clés — amendé le 28/09 (E102-4) ;
- `database/factories/GameFactory.php` [modifié : constantes de version supprimées ; le test « fixe speedBonusMaxPercent… » de `PlatformLimitsTest` lit alors `ScoringRules::VERSION`, bascule attribuée à L80-1 — amendé le 25/09, E9-3 ; déjà livré par L80-1, rien à faire dans ce lot — amendé le 28/09, E102-4] ;
- `app/Http/Controllers/Room/LaunchController.php` [nouveau], échec technique compris (§ 12.5) ;
- `RoomPolicy` [complété : `launch`, `advanceRound`] ;
- `lang` : `room.refusal.*`, `room.errors.launch_failed`.

**Tests :**
- `tests/Feature/Room/LaunchGameTest.php` :
  - « crée une partie, une participation par siège tenu, min(M + marge, vivier) manches de N paliers, et passe le salon en playing »
  - « refuse un siège qui n'est pas l'hôte courant »
  - « ne crée qu'une partie sur un double clic et redirige le second sans erreur »
  - « refuse un salon archivé avec room_archived »
  - « refuse sous deux sièges connectés avec not_enough_players »
  - « rejoue la garde de vivier : vivier réduit depuis le lobby → pool_insufficient avec le rapport en données, aucune ligne écrite »
  - « normalise des réglages de version antérieure, rapporte les changements et refuse settings_outdated »
  - « diffuse au salon les réglages normalisés après un refus settings_outdated »
  - « refuse de lancer pendant le drainage avec le message de maintenance et accepte après expiration du drapeau »
  - « ne pose launched_at qu'au premier lancement »
  - « écrit draw_pool_size égal au nombre d'œuvres du vivier rejoué »
  - « annule toute la transaction si la matérialisation échoue »
  - « rend une erreur traduite et laisse le salon en lobby quand le tirage lève PoolTooSmallException »
  - « n'émet aucun job ni diffusion avant la validation de la transaction »
  - « ne renvoie ni graine, ni film, ni identifiant interne »
  - « n'appelle jamais TMDB »
- `tests/Feature/Room/RoomPolicyTest.php` : « refuse la manche suivante à un non-hôte »
- `tests/Feature/Room/PresetValidityTest.php` : « garde chaque preset livré lançable sur le catalogue de démonstration »
- `TranslationCoverageTest` (extension) : `RoomRefusal::messageKey()` pour chaque cas.

### L50-7b — Gel, « Rejouer », concurrence du lancement (J1, 4–6 h)

**Fichiers :**
- `app/Actions/Room/ReplayRoom.php` [nouveau] ;
- `app/Models/Game.php` [modifié : `FROZEN_COLUMNS`, garde `updating`] ;
- `app/Http/Controllers/Room/ReplayController.php` [nouveau] ;
- `RoomPolicy` [complété : `replay`] ;
- `resources/js/components/room/replay-button.tsx` [nouveau] ;
- `lang` : `room.replay.*` ;
- `tests/Concurrency/.gitkeep` [supprimé] : posé par L100-1 pour que la suite `Concurrency` ait un répertoire, il se retire dans le commit du premier test de `tests/Concurrency`, `LaunchConcurrencyTest` si aucun lot antérieur n'en a écrit — amendé le 25/09 (E6-4). Déjà retiré par L80-4, premier test `locks-timing` (E89-3) — amendé le 28/09 (E115-6) ;
- hors liste — amendé le 28/09 (E115-2, E115-3, E115-6) :
  - `app/Http/Controllers/Room/Concerns/AnswersRoomGesture.php` [nouveau, nom libre] : `backToRoom()`, `rereadStatus()` et `journalGestureFailure()`, extraits de `LaunchController` (retouché, comportement inchangé) et partagés avec `ReplayController` ;
  - `tests/Feature/Answer/DecoyDrawTest.php` et `tests/Feature/Game/FrameServeTest.php` : deux fixtures qui réécrivaient une colonne figée de `game` par `save()` la posent désormais en base par requête directe, sans changer d'intitulé ni d'assertion ;
  - `tests/Feature/Room/RoomPolicyTest.php` [complété] : « Rejouer » parmi les gestes relus sous verrou et refusés à l'administrateur.

**Tests :**
- `tests/Feature/Room/ReplayRoomTest.php` :
  - « ramène le salon en lobby après le podium et déverrouille les réglages »
  - « refuse game_not_ended tant que la partie n'est pas figée »
  - « refuse de rejouer pendant le drainage »
  - « refuse un non-hôte »
  - « diffuse le vivier réduit par la non-répétition »
- `tests/Feature/Room/SettingsFreezeTest.php` :
  - « fige settings_snapshot égal à room.settings et les colonnes de game égales au snapshot »
  - « porte les constantes de plateforme sur une partie lancée par un hôte hostile »
- `tests/Concurrency/Room/LaunchConcurrencyTest.php` (groupe `locks-timing` par répertoire) :
  - « ne crée qu'une partie pour deux lancements concurrents »
  - « sérialise une écriture de réglages concurrente d'un lancement »
- `tests/Feature/Architecture/GameCreationBoundaryTest.php` :
  - « n'insère dans game que depuis OpenGame, fabriques exceptées »
  - « ne pose ni ne lève le drapeau de drainage hors des commandes de déploiement »
  - « lève si une colonne figée de game est modifiée »
- `tests/Feature/Architecture/GameFrozenColumnsMysqlTest.php` (groupe `mysql`) : « relire settings_snapshot puis enregistrer game ne déclenche pas la garde des colonnes figées »

### L50-8 — Échéances (J1, 3–5 h)

**Fichiers :**
- `app/Support/Room/RoomExpiry.php`, `app/Actions/Room/ArchiveRoom.php`, `app/Jobs/Room/ArchiveIdleRooms.php` et `app/Console/Commands/RoomArchiveIdleCommand.php` [nouveaux] ;
- `TransferHost` [complété : `clear()`] ;
- `routes/console.php` [modifié] ;
- `resources/js/pages/game/room-expired.tsx` [nouveau] ;
- `lang` : `room.expired.*` ;
- `RoomExpiry::{lobbyIdleBefore, roomIdleBefore, sweepCron}` (§ 16.1) — amendé le 28/09 (E113-6) ;
- hors liste : `app/Models/PurgeRun.php` [modifié : `ERROR_LENGTH` et `MAX_BATCHES` publics, `describeFailure()` et `summarizeFailure()` statiques] et `app/Support/Retention/RetentionPurger.php` [retouché, comportement inchangé]. Les deux écrivains de `purge_run` lisent ainsi un seul jeu de règles ; le cycle `running` → `completed` / `failed` reste écrit par les deux, et tout changement de 10 § 11.3 les touche ensemble — amendé le 28/09 (E113-7).

**Tests :**
- `tests/Feature/Room/RoomArchiveTest.php` :
  - « archive un salon au-delà de l'échéance d'inactivité et libère son code actif »
  - « efface pseudo, forme normalisée, hash du jeton et pseudo gelé dans une seule transaction »
  - « archive par anticipation un lobby jamais lancé et journalise stale_lobby »
  - « journalise stale_lobby à chaque passage du balayage, même sans salon archivé »
  - « ne touche pas un salon dont un joueur bat encore »
  - « ne supprime jamais une ligne room à l'archivage »
  - « émet room.archived après validation »
  - « affiche la page de salon expiré en 410 pour un lien archivé »
  - « balaie sur la file default et jamais sur la file game »
  - [ajouté, titre sans marqueur] « refuse d'archiver un salon dont la dernière partie n'est pas figée, sans bloquer le balayage » — amendé le 28/09 (E113-5, E113-6)
- `tests/Feature/Room/RoomEntryPageTest.php` [complété, fichier de L50-3b] : la page `game/room-expired` existe désormais — amendé le 28/09 (E113-5, E113-6).

**Ce qui attend ce lot, côté `100`** — amendé le 25/09 (E23-1, E23-2, E24-4, E24-7, E24-9). La purge de L100-8 est livrée sur les seuls périmètres sans jeu ; trois pièces de `100` n'attendent que `ArchiveRoom` et le balayage de ce lot, et suivent ce lot sans en faire partie. **Toutes trois sont livrées à l'étape 114** (L100-8 2e temps, L100-7 3e temps : `StaleRoomHandler`, son test, la vérification `stale_lobby` de la sonde) — amendé le 28/09 (E114-4) :
- le gestionnaire `stale_room`, qui implémente `PurgeHandler` (contrat étendu par L100-8 : `nextBatch()`, `purge()`, `connection()`), entre dans `PurgeHandlers::CLASSES` et dans `PurgeScope::implemented()`, lit la fenêtre dans `RetentionWindows::STALE_ROOM_HOURS` (déjà livrée) et appelle `ArchiveRoom`, unique chemin d'archivage (§ 16.2) ;
- son test de `RetentionPurgeTest`, « archive un salon oublié depuis 48 h par l'action d'archivage de 50, jamais par suppression », et le jeu de lignes du périmètre dans `tests/Support/Retention/RetentionRows.php`, sans lequel `PurgePerimeterTest` échoue dès que `stale_room` entre dans `implemented()` ;
- la vérification `stale_lobby` de la sonde `purge` (L100-7), qui lit la ligne `purge_run` que ce balayage écrit à chaque passage (§ 16.2).

### L50-9 — Retardataires (J1, 3–5 h ; lot J1 ordinaire, D35 du 23/09 — amendé le 23/09)

**Fichiers :**
- `TakeSeat` [complété : admission, § 15.2] ;
- `RoomEntryController` [complété : état `late_join`] ;
- `resources/js/components/room/room-settings-form.tsx` [nouveau] : section « Réglages » et son seul interrupteur `allowLateJoin`, rendu si `editor.lateJoinAvailable`. Le fichier naît ici et non en L50-5, livré après ce lot ; L50-5 le complète sans le recréer — amendé le 28/09 (E116-6, E116-10) ;
- `lang` : `room.join.late_join`, `room.settings.allowLateJoin.{label,help}` et `room.lobby.settings_title` ; `room.lobby.waiting_next_game` est déjà livrée par L50-4 — amendé le 28/09 (E116-6, E116-9) ;
- hors liste — amendé le 28/09 (E116-3, E116-5, E116-7, E116-9) :
  - `app/Models/Round.php` : scope `lateJoinableAt($now)` et `isLateJoinableAt()`, définition unique partagée par la prise de siège (sous verrou) et l'état indicatif de la page d'entrée ;
  - `resources/js/pages/room/join.tsx` : état `late_join` ;
  - `resources/js/pages/game/lobby.tsx` : attente de la partie suivante = siège absent des sièges de la partie, ou présent avec `firstRoundNumber` nul (vue de lobby reçue en partie).

**Tests :**
- `tests/Feature/Room/LateJoinTest.php` :
  - « admet un retardataire à la prochaine manche numérotée non démarrée, avec une participation à 0 point »
  - « admet le retardataire à la manche de remplacement quand elle est la prochaine à jouer »
  - « fait attendre la partie suivante quand aucune manche numérotée ne reste à démarrer »
  - « ne donne jamais au retardataire la manche en cours »
  - « n'admet personne en partie quand les retardataires sont fermés »
- `tests/Concurrency/Room/LateJoinConcurrencyTest.php` : « sérialise l'admission avec l'ouverture de la manche sous le verrou de la manche »

D17 du 23/09 est sans effet depuis D35 du 23/09 : ces fichiers et ces tests sont tous livrés au J1, sans variante de coupe (§ 15.4) — amendé le 23/09.

### L50-14 — Entrée au pseudo seul, avatar attribué et changé au lobby (J1, D55 du 02/10 — amendé le 02/10)

**Fichiers :**
- `app/Support/Room/TakenAvatars.php` [nouveau] : seul calcul des clés prises (`of(Room, ?Player $except)`), sièges `holdingSeat()`, replis compris ; consommé par `TakeSeat`, `ChangeSeatAvatar` et la prop `avatars` ; le calcul dupliqué de `RoomEntryController` est retiré ;
- `app/Actions/Room/{TakeSeat,CreateRoom}.php`, `app/Actions/Game/StartSoloGame.php` [modifiés : paramètre d'avatar retiré, attribution serveur sous verrou, § 7.3 S6, `60` § 16.2] ; `app/Avatars/{SeatAvatar,AvatarPresetCatalog}.php` [modifiés : repli d'une image de compte évitant les clés prises] ;
- `app/Http/Requests/Room/{StoreRoomRequest,JoinRoomRequest}.php`, `app/Http/Requests/Game/SoloStartRequest.php` [modifiés : aucun champ `avatar`] ; props `avatars` retirées de `room/create`, `room/join` et `room/solo` ;
- `app/Actions/Room/ChangeSeatAvatar.php`, `app/Http/Controllers/Room/SeatAvatarController.php`, `app/Http/Requests/Room/ChangeSeatAvatarRequest.php` [nouveaux] ; `RoomPolicy::changeAvatar` ; route `room.avatar.update` dans `routes/game.php` ;
- `RoomController@show` [complété : prop `avatars` en closure] ;
- `resources/js/pages/welcome.tsx` [modifié : carte « Rejoindre » code + pseudo, `90` § 4.7] ; `resources/js/pages/room/{create,join,solo}.tsx` [modifiés : pseudo seul] ; `resources/js/pages/game/lobby.tsx` et `use-lobby-state.ts` [modifiés : sélecteur, relecture de `avatars`] ;
- `lang/{fr,en}/room.php` (`room.lobby.avatar_taken`, `room.lobby.avatar.*`, intros) et `lang/{fr,en}/common.php` (`common.home.*` du pseudo), puis `php artisan lang:types`.

**Tests** (intitulés définitifs à compléter par le lot, phrases françaises, `tests/Feature/Room/`) : attribution à la prise de siège (`suggest()` du jeton s'il est libre, sinon le premier libre ; l'image téléversée visible d'un compte ; repli d'un compte évitant les clés prises) ; changement au lobby (succès, idempotence, `seat.updated`, re-signature) ; refus d'une clé tenue par un autre siège, repli d'un siège `upload` compris ; refus hors lobby, partie et podium ; refus d'un onglet supplanté (409) ; création, entrée et solo sans prop `avatars` et sans validation d'un champ `avatar` ; entrée depuis l'accueil (reprise sous l'ancien pseudo, `kicked` et `full` en `errors.room`).

**Vérifié à la main** : carte « Rejoindre » de l'accueil (code + pseudo, mention des CGU), sélecteur du lobby (avatars pris marqués par texte, refus annoncés, absent pendant une partie et sur le podium, de retour après « Rejouer »).

### L50-10 — Onglet Avancé (J2, 7–8 h)

**Fichiers :**
- `RoomSettingsEditor::advanced()` et `ADVANCED_TAB_AVAILABLE = true` ;
- `components/room/{advanced-settings-form,advanced-active-banner}.tsx` [nouveaux] et la clé `room.settings.advanced_active` (§ 20.2), que L50-5 n'a pas introduite, faute d'appelant au J1. Les curseurs de paliers et de points peuvent réutiliser `SettingSlider` de `room-settings-form.tsx` (à exporter) et `crossBoundErrors()`, dont la borne 2 est déjà écrite côté client — amendé le 28/09 (E120-5, E120-10) ;
- onglets `tabs` et feuille mobile, avec les clés du § 20.6 ;
- remède `disable_no_repeat` rendu au présentateur ;
- rapport `overwritten` des presets.

**Tests :**
- `tests/Feature/Room/RoomSettingsAdvancedTest.php` :
  - « en Avancé, prend D comme somme des paliers et refuse roundDuration »
  - « en Avancé, refuse une liste de paliers ou de points de mauvaise taille sans rien dériver »
  - « rapporte reset quand un changement de N remplace un barème personnalisé »
  - « au retour en Simple, réégalise les paliers, conserve les autres réglages avancés et le rapporte »
  - « rapporte overwritten pour chaque réglage avancé personnalisé qu'un preset écrase »
  - « propose l'interrupteur de non-répétition parmi les remèdes une fois l'onglet livré »

### L50-11 — Sélecteur de thèmes (J2, 2,5–3,5 h)

**Fichiers :** prop `themes`, `components/room/theme-picker.tsx` [nouveau]. Le remède `clear_themes` est déjà rendu depuis le J1 (L50-4, E110-1), et les clés `room.settings.themeKeys.{label,help}` sont livrées par L50-5 — amendé le 28/09 (E120-10). Au J1, `RoomController@show` pose `editor.themeSelectorVisible = false` en dur, faute de `PoolReporter::themeSelectorVisible()` : ce lot le lit (E110-5).

**Tests :**
- `tests/Feature/Room/ThemeSelectorTest.php` :
  - « montre le sélecteur de thèmes au-dessus du seuil du vivier catalogue et le masque en dessous »
  - « livre les libellés de chaque thème proposable dans chaque locale activée, sans identifiant »

### L50-12 — Configurations sauvegardées (J2, 6–8 h)

**Fichiers :**
- `app/Support/Room/SavedConfigName.php` et `app/Actions/Room/SaveRoomConfig.php` [nouveaux] ;
- actions de renommage, de suppression et de défaut ;
- routes `room.configs.store` et `saved-configs.*` ;
- `RoomPolicy` [complété : `saveConfig`] ;
- page de gestion (écran de `90`) ;
- `lang` : `room.configs.*`.

**Tests :**
- `tests/Feature/Room/SavedConfigTest.php` :
  - « enregistre les réglages courants du salon sous un nom, jamais une charge postée »
  - « refuse une configuration au-delà du plafond de plateforme, même sous deux requêtes concurrentes »
  - « refuse un nom déjà pris à la casse et aux accents près, sauf remplacement confirmé »
  - « ne désigne qu'une configuration par défaut par compte »
  - « refuse la configuration d'un tiers, y compris à un administrateur »
  - « réserve l'enregistrement aux comptes et l'annonce à l'invité comme raison de créer un compte »
  - « refuse l'enregistrement à un invité et à un visiteur sans siège »

### L50-13 — Chargement et configuration par défaut (J2, 4–5 h)

**Fichiers :** `app/Actions/Room/LoadSavedConfig.php` [nouveau], `CreateRoom` [complété], `RoomPolicy` [complété : `loadConfig`], route `room.settings.load`.

**Tests :**
- `tests/Feature/Room/SavedConfigLoadTest.php` :
  - « normalise en mémoire au chargement et laisse la ligne inchangée octet pour octet »
  - « relève la capacité à l'effectif présent et le rapporte raised »
  - « rapporte champ par champ chaque normalisation »
  - « applique la configuration par défaut à la création d'un salon par un compte »
  - « refuse le chargement à un non-hôte »

### Totaux

- **Jalon 1** : L50-1 à L50-9 (L50-3 scindé en L50-3a et L50-3b, L50-7 en L50-7a et L50-7b), **51 à 71,5 h**, L50-9 compris : aucune coupe ne s'applique (D35 du 23/09) — amendé le 23/09. Plus L50-14 (D55 du 02/10), lot J1 ajouté après coup et non chiffré — amendé le 02/10.
- **Jalon 2** : L50-10 à L50-13, **19,5 à 24,5 h**.
- La hausse du J1 sur la version précédente (49 à 69 h) vient du miroir client du code et de sa parité, de la mention des CGU, de la page du salon construite sur la partie et rechargée après une resynchronisation, et du remède `clear_themes` avec le message de thème élagué, avancés au J1 ; ce dernier retire 0,5 h au J2.

La ligne « salon onglet Simple et bornes croisées serveur 12 h » de 00 § Jalons et budget-temps sous-estimait ce périmètre : création, prise de siège, lobby temps réel, expulsion, transfert, lancement, « Rejouer », retardataires, échéances, textes FR et EN, et tests. Ces totaux sont des mesures de taille, jamais un calendrier ni un budget à tenir (D36 du 23/09) : `00` § Jalons en tire la taille du J1 à partir des sections « Lots » de toutes les specs ; ce document ne la recalcule pas — amendé le 23/09.

---

## Ce que cette spec ne décide pas

| Sujet | Spec propriétaire |
|---|---|
| Le schéma : colonnes, index, contraintes, périmètres de purge, dont `stale_room` à 48 h et la sonde n° 2 | `10-catalogue-et-modele-de-donnees.md` |
| La matrice de capacités des trois rôles, l'écran « inspecter une partie », le lot pilote | `20-back-office-curation.md` |
| Le prédicat du vivier, le compte en œuvres, la fenêtre de mémoire du salon, le `N` jouable le plus proche, le seuil et la mesure du sélecteur de thèmes, le tirage, la graine, la substitution | `30-themes-vivier-et-tirage-des-variantes.md` |
| La forme du `player_token`, la règle de pseudo, la liste noire, le registre des avatars ; au J2, le rattachement d'un siège à un compte, l'anonymisation qui supprime les `saved_config` | `40-comptes-auth-sociale-et-avatars.md` |
| Les canaux, les noms d'événements et leur enveloppe, le transport, la présence (`connected → disconnected → left`), le battement, la resynchronisation, le second onglet, le **contenu des états de manche, de révélation et de pause de la page `game/lobby`**, l'effet d'un départ ou d'une expulsion sur la manche (`SeatInputClosed`, appelé par `50` selon 60 § 13.4), l'admission des retardataires côté moteur, la pause et la clôture à 15 min, le flux du solo | `60-moteur-de-partie-temps-reel-et-mode-solo.md` |
| La validation des réponses, les seuils du couple `(attemptsPerRound, \|pool\|)` et la sonde de force brute | `70-validation-des-reponses.md` |
| La formule du bonus, le texte d'aide du barème (`game.help.scoring.*`), le gel, le podium, le classement des partis et des expulsés | `80-scoring-podium-et-fin-de-partie.md` |
| La structure de l'écran de jeu au J1 (page unique du salon, boundary map n° 35), la composition visuelle des écrans, les coquilles, la liste close des composants, l'accueil et son champ de code, la page de gestion des configurations, la publication des durées de conservation, les règles d'indexation, les pages légales, la clé `legal.terms_notice` et la composition de la mention des CGU, l'annonceur `announce()` et l'absence de `Toaster` sous `GameLayout` | `90-ecrans-etats-et-structure.md` |
| Le drapeau de drainage et les commandes `deploy:*`, le `cron` du planificateur, le gestionnaire du périmètre `stale_room` (`RetentionPurger`) et la surveillance de `stale_lobby`, le canal de journal `game`, le jeu de données de la matrice des réglages et `RoomSettingsMatrixTest`, les groupes Pest, la CI | `100-qualite-tests-et-ci.md` |

**Points restés ouverts, signalés au porteur :**

0. **Structure de la page de salon, arrêtée ici.** Le salon multijoueur est une page unique, `game/lobby`, du lobby au podium (§ 7.2, § 8.2, § 21), conformément au contrat figé C16 § 2.1, à 90 § 2.1 (propriétaire de la structure de l'écran de jeu au J1, boundary map n° 35) et à 60 § 10.1. Ce choix ferme l'écart (m) de 60 § 22 bis, que `60` laissait à `50` d'arbitrer avec `90`. `game.launched` et `room.replayed` ne déclenchent aucune visite. À confirmer par le porteur, puisque la feuille de contrats n'a jamais nommé `game/room`.
1. **Crochets avec `60`.** L'appel direct de `SeatInputClosed` après le commit d'un départ ou d'une expulsion (§ 8.2) est celui que prescrivent 60 § 13.4 et § 19.6 ; l'appel de `TransferHost::automatic()` par la présence (`SweepSeatPresence`) quand l'hôte passe à `left` (§ 11.2) est celui de 60 § 13.2. Le contrat C7 § 2.5 ne nomme comme appelants de `SeatInputClosed` que les écouteurs de `60` : l'appel par `50` est une lecture de sa signature figée, à confirmer par le porteur.
2. **Adresse d'une `saved_config` par `name_normalized`** [J2] : sans schéma, mais elle fait d'une colonne `#[Hidden]` une clé de route dans le périmètre du propriétaire. À confirmer par `10` ; l'alternative est une colonne `public_id`, qui serait une exigence nouvelle.
3. **Publication de l'échéance d'archivage** augmentée de l'intervalle de balayage (§ 16.2) : texte de la page de confidentialité, par `90`.
4. **Coupe D17 du 23/09 : tranchée.** Le porteur a retenu un J1 complet, sans aucune coupe (D35 du 23/09) : D17 est sans effet, et les retardataires sont livrés au J1 (§ 15.4) — amendé le 23/09.
5. **Amendements non consolidés** listés plus haut : 00 l.135, E10-27, `CLAUDE.md` §8, 10 § 6.1 l.632 (`ValidRoomSettings`), 10 § 6.1 (resserrement de borne et `VERSION`).
6. **Exécutant unique de `stale_lobby`** : le balayage de `50`, qui écrit sa ligne `purge_run` à chaque passage, même à zéro (§ 16.2) ; `RetentionPurger` ne l'exécute jamais et `PurgeScope::implemented()` ne le contient pas (100 § 14). Précision « même à zéro » demandée par `100`, reprise ici ; à relire ensemble à la fusion.
7. **Nom de test renommé.** C0 § 7 nomme « écrête preloadLeadMs dans [1500, 2500], sous la révélation minimale ». `preloadLeadMs` n'étant plus lu en configuration (arbitrage du rédacteur en chef du 23/09, § 2.3), il n'y a plus rien à écrêter, et L50-1 nomme le test « garde preloadLeadMs dans [1500, 2500], sous la révélation minimale ». À valider par le porteur.
8. **Seconde clause de la règle 4 (§ 2.1)** : un resserrement de borne de `RoomSettingsBounds` incrémente `VERSION`. Elle rend faux, au premier resserrement, le test de C0 § 7 « garde VERSION à 1 tant que FIELDS est inchangé », repris à la lettre en L50-1. Proposition : renommer le test « garde VERSION à 1 tant que FIELDS et les bornes de RoomSettingsBounds sont inchangés », avec un instantané des bornes dans le test. L50-1 a livré le test à la lettre, sans instantané : la proposition reste à valider par le porteur — amendé le 25/09 (E9-7).
9. **Forme du rapport de changements** (§ 2.6) : `normalize()` peut rapporter `dropped` sous la clé d'un champ retiré par une version ultérieure, hors de `RoomSettingsFieldKey`. Faut-il élargir la forme `Record<RoomSettingsFieldKey, RoomSettingsChangeCode>` du contrat C0 § 3.4 ?
10. **Placeholders définis clé par clé** pour `room.pool.remedy.*` (`:count` partout, `:value` pour `lower_frames_per_round` et `reduce_rounds_count` seulement) et pour `room.warnings.*` (`:seconds` pour `short_reveal` et `long_round` seulement), écart de forme à C0 § 2, qui donne les mêmes placeholders à toute la famille (§ 9.2, § 20.2).
11. **Délai de `BroadcastLobbyState`** : la lettre du contrat C7 § 2.5, `delay(PlatformLimits::lobbyBroadcastDebounceMs())`, programmerait la diffusion en **secondes**. `50` dispatche avec un instant, comme 60 § 11.2 (écart (n) de 60 § 22 bis), et l'arrondit à la seconde supérieure (§ 8.3) : la fenêtre effective n'est jamais plus courte que `lobbyBroadcastDebounceMs()`, là où 60 § 11.2 décrit une fenêtre de 0 à 1 s sans cet arrondi. Le code livré s'écarte aussi de `->afterCommit()`, lettre de C7 § 2.5 et de 60 § 11.2 : il enveloppe le dispatch entier dans `DB::afterCommit()`, pour que le verrou d'unicité soit pris après la validation (§ 8.3) — amendé le 28/09 (E86-6).
12. **Garde de capacité** (§ 10) : elle ne refuse qu'une capacité **abaissée** sous l'effectif présent, jamais le plafond `roomSeats()`. C'est une lecture de l'invariant 3 de C0 § 4 (« capacité ≥ `COUNT(player holdingSeat)` »), qui, prise à la lettre, refuserait toute écriture de réglages dès que l'effectif dépasse la capacité par le retour d'un siège parti (§ 7.3, test de 10 § 6.2).
13. **Dépendance croisée L50-2 ↔ L60-4.** L60-4 dépend du `RoomSettingsPresenter` de L50-2 ; L50-2 dépend de L60-4 non seulement pour le dispatch de `BroadcastLobbyState` (seul point que 60 § 22 bis signale), mais aussi pour `seat.active`, que portent ses routes. Ordre proposé : L50-2 livre d'abord l'éditeur, le présentateur et les actions, puis ferme ses routes et son dispatch après L60-4 (section « Lots »). Appliqué : L50-2a à l'étape 84, L60-4 à l'étape 85, L50-2b (routes, contrôleurs, `RoomPolicy::updateSettings`, dispatch) à l'étape 86 — amendé le 28/09 (E84-1).
14. **Règle du retardataire citée par `60` : alignée.** 60 § 13.7 résumait l'ancienne règle de ce document (« par `sequence_index` croissant, remplaçantes exclues »), alors que 60 § 1.2 joue les manches par `round_number`. La règle arrêtée ici (§ 15.2) suit l'ordre de jeu : `round_number` croissant, remplaçante comprise tant qu'elle n'a pas démarré. 60 § 13.7 est aligné : il renvoie à la règle du § 15.2 (`round_number` croissant, remplaçante comprise tant qu'elle n'a pas démarré) — amendé le 23/09.
15. **Remède « nouveau salon » et promesse « débloque à lui seul ».** 30 § 4.3 compte `open_new_room` aux réglages courants sans la non-répétition, alors que le geste crée un salon aux réglages par défaut (§ 6.1). Le texte le dit désormais (« avec ces réglages », § 20.3), mais la promesse « chaque remède débloque à lui seul » ne vaut pour ce remède qu'une fois les réglages reportés à la main dans le nouveau salon ; depuis le preset `fast` (N = 2, M = 8, § 5.1), le nouveau salon naît à N = 3 et M = 10 au réglage par défaut, et peut s'y bloquer sur `framesPerRound` ou `roundsCount` alors que `:count` promettait assez de films. L'alternative, créer le nouveau salon avec les réglages courants, relève du produit : à trancher par le porteur. **Toujours ouvert au 28/09** : aucune décision n'est consignée, et L50-4 comme L50-5 ont livré le remède tel quel, lien vers `room.create` et texte « … avec ces réglages ». L'alternative toucherait `CreateRoom` et la page `room/create` — amendé le 28/09 (E120-8).
16. **Import de `RoomSettingsState` par le fil de `60`.** `types/room-settings.ts` naît en L50-2 (section « Lots ») ; 60 § 11.4 l'importe dans `types/game-wire.ts`, que L60-2 crée, alors que L50-2 dépend de L60-2 (`game-write`). À `60` de poser cet import au plus tôt dans le lot qui type `settings.changed` et `room.replayed` pour le magasin (L60-9), qui dépend alors de L50-2.
17. **Salon à version de réglages périmée après un resserrement de borne** (E36-1, signalé au porteur le 24/09 par L30-3 ; code de L50-2 livré inchangé, sans tolérance ajoutée à la garde de `N`, et sans effet tant que `VERSION = 1` — amendé le 28/09, E84-4). Le lobby calcule le vivier sur les réglages **bruts** du salon (`RoomSettingsPresenter::state()`, § 2.6 ; grisage des presets ; `BroadcastLobbyState`), et seule l'étape L5 du lancement normalise (§ 12.2). `PoolReporter` prend désormais `M` tel quel, pour que le rapport se calcule sur un `M` hors des nouvelles bornes. Deux questions restent au porteur, non tranchées ici :
    - la garde de `N` de `PoolScope` (L30-2) lève hors bornes : un resserrement des bornes de `N` ferait donc échouer `state()` et chaque diffusion du lobby de tout salon ouvert pendant le déploiement ;
    - pour un `M` périmé au-dessus d'une nouvelle `MAX_ROUNDS_COUNT` et un vivier compris entre les deux, le remède `reduce_rounds_count` porte une valeur que l'éditeur refuserait (30 § 4.3 fixe `value = count` sans plafond), jusqu'à la normalisation du lancement.

**Points ajoutés le 28/09, relevés à l'implémentation de la phase C** (journal des écarts, entrées E84 à E123). Le code livré est décrit dans le corps de la spec ; chaque point attend une décision ou une confirmation du porteur, sans être tranché ici.

18. **Salon archivé refusé avant la réparation d'hôte** (E102-1, E115-1). Le lancement (L3, § 12.2) et « Rejouer » (R3, § 13) refusent `room_archived` en tête, sans écriture, puis réparent l'hôte. La lettre de C6 § 3 place la réparation d'abord : elle écrirait un hôte sur un salon que l'archivage a vidé du sien (§ 16.2), et `room_archived` ne serait atteignable que par l'hôte réparé. Par HTTP, `seat.active` intercepte déjà ce cas, sauf archivage entre la policy et le verrou. À confirmer, et à reporter dans C6 § 3.
19. **« Rejouer » déjà fait pendant un drainage** (E115-1). R4 (`lobby` → `null`) précède R6 (drainage) : un double clic sous drapeau rend `null`, sans message. Le § 14 dit « refusé quel que soit son effet » ; lu ici comme « même s'il ne crée aucune partie », un geste déjà accompli n'ayant plus d'effet à refuser. Si la phrase voulait l'inverse, le second clic recevrait le message de maintenance.
20. **Message d'échec technique de « Rejouer »** (E115-3). Le § 12.5 nomme `room.errors.launch_failed` pour les deux gestes : l'hôte lit alors « Le lancement a échoué » sur le podium. Une clé `room.errors.replay_failed` serait plus juste ; elle n'est pas ajoutée faute de spec.
21. **Verrou de la partie à l'admission d'un retardataire** (E116-1). S7 relit la dernière partie `FOR UPDATE` avant la candidate (§ 15.2), contre le gel concurrent d'une partie interrompue. À confirmer, si la lettre du § 15.2 voulait exclure ce verrou.
22. **Composition de la mention des CGU** (E101-5). Le texte `legal.terms_notice` sert de texte au lien vers `legal.terms` (§ 6.2, § 7.2), ce que 90 § 10 permet. L'autre voie est un texte suivi d'un lien libellé `legal.footer.terms`.
23. **Formulations** des clés ajoutées par la phase C : `room.identity.nickname_label` et `room.identity.nickname_hint` (§ 20.3, E101-4), `validation.attributes.publicId` (§ 20.4, E111-3).
24. **Prop `state` de `room.show` construite sans rattrapage** (E109-2). Seules `room.state` et `solo.state` appellent `CatchUpGame` avant de construire le paquet (60 § 12.1). Un job de frontière en retard fait donc décrire à `room.show` l'état en base (phase `scheduled` au lieu de `running`, par exemple) jusqu'à la première resynchronisation du magasin. Ajouter le rattrapage à `room.show` (et à `solo.show`) rendrait la prop initiale toujours échue, au prix d'écritures sur un GET de page.
25. **403 `not_host` d'un hôte déchu entre l'affichage et le clic** (E110-5, E111-7). Il suit le traitement ordinaire (page `error`), pour le lancement comme pour les gestes d'hôte, alors que `host.changed` retire aussitôt les gestes. L'intercepter par une relecture garderait l'hôte déchu sur la page du salon.
26. **`purge:suspend` et le balayage `stale_lobby`** (E113-2). L'interrupteur d'incident de `RetentionPurger` ne suspend pas le balayage de ce document (§ 16.2). Doit-il le suspendre aussi ? La sonde `purge` est de toute façon en alerte pendant une suspension.
27. **Preuve MySQL des sérialisations du salon** (E113-6). Aucun test `tests/Concurrency` (`locks-timing`) n'est nommé pour la sérialisation de l'archivage, du battement et de la prise de siège sous le verrou du salon ; SQLite ne la prouve que par injection de course. À inscrire dans une passe `locks-timing` si voulu.
28. **Passations de tests d'identité sans lot** (E101-6, E111-7). La route de test `…/resign` de `PlayerTokenTest` (changement d'avatar hors siège) et l'en-tête de `NicknameBlocklistTest` (rebranchement sur les FormRequest de `50`) attendaient un geste de changement d'avatar ou de pseudo en L50-6, qui n'en contient aucun. Reste à nommer le lot qui livrera ce geste, ou une passe de dette de tests. — **Fermé pour l'avatar par D55 du 02/10** : le geste `room.avatar.update` (§ 8.1, lot L50-14) est celui sur lequel rebrancher la route de test `…/resign` ; le changement de pseudo hors prise de siège reste sans lot (amendé le 02/10).
29. **Durée d'effacement des sièges solo lue dans `RoomExpiry`** (E122-3). `RetentionWindows::SOLO_SEAT_IDLE_MINUTES` lit `RoomExpiry::ROOM_IDLE_MINUTES` (une durée annoncée, deux déclencheurs) : changer l'échéance d'archivage du salon changerait donc celle des sièges solo. Si les deux durées doivent pouvoir diverger, la constante de `100` devient un littéral propre.
30. **Forme de la prop `presets`** (E123-11). Elle ne porte que `nearestPlayableFramesPerRound` (§ 5.3), nul pour un preset jouable tel quel : la relance solo de `game/solo` n'a aucune source du `N` d'un preset non grisé et affiche le `B_max` du `N` de la dernière partie. Si la prop est enrichie du `N` de chaque preset, la forme du § 5.3 change, avec 60 § 16.4 et 90 § 7.7.
