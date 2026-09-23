# Feuille de contrats partagés — specs du jalon 1 de TripleFrames

> **Annexe archivée le 23/09/2026.** Feuille de contrats partagés, figée pour la rédaction des specs du jalon 1 et versée au dépôt pour que ses renvois restent résolubles : « contrat Cn », « E10-nn » (exigences adressées à `10`, appliquées le 23/09), « A-nn » (amendements du corpus, appliqués le 23/09), « R-nn » (conflits résolus). **Elle ne fait pas autorité** : en cas d’écart, la spec propriétaire du sujet prime (`docs/specs/`), puis `10` pour le schéma. « Dnn » renvoie désormais à `docs/specs/questions-ouvertes.md` § Décisions du 23/09/2026 ; « n° N » à `docs/annexes/contradictions-j1.md` ; `decisions-2309.md`, `synthesis.json` et `pre/` étaient des pièces de travail de la session, non versées.

## En-tête

**Objet.** Cette feuille fixe, une seule fois pour toutes les specs écrites le 23/09, les noms, signatures, charges utiles, invariants et tests qui franchissent la frontière d'une spec. Elle fusionne les huit groupes de contrats rédigés en parallèle : G50, G30, G40, G60, G20, G70, G80, G90 (05/90/100). Elle ne décide rien de ce qui reste propre à une spec : voir la section 8 de chaque contrat.

**Statut.** Figé pour la rédaction du 23/09.

**Règle d'usage.**
- Un rédacteur de spec reprend ces noms **à la lettre** : classes, méthodes, routes, événements, clés de traduction, colonnes, codes et noms de tests.
- Un désaccord **se signale** au porteur ; il ne se corrige jamais en silence dans une spec.
- Ordre de préséance appliqué pour trancher : décisions du 23/09 (`decisions-2309.md`) > `10` > `05` > `00` > code existant ; à défaut, le propriétaire du contrat. Chaque arbitrage est tracé dans « Conflits résolus » (R-01 à R-47). R-46 et R-47 sont des écarts à une résolution de `synthesis.json` : ils sont **signalés au porteur**.

**Conventions de cette feuille.**
- « Dnn » renvoie à `decisions-2309.md`. « Sn » renvoie au cadre de la session dans le même fichier.
- « n° N » renvoie à `synthesis.json › contradictions_to_fix[N]`, **numérotation à partir de 0**, identique à `pre/contradictions.txt` et à `decisions-2309.md` (« n° 14 → D4 »). Quatre groupes numérotaient à partir de 1 ; leurs renvois sont corrigés ici (R-01).
- « Q… » renvoie à `synthesis.json › dropped_questions` (défauts retenus).
- « 10 § x » renvoie au schéma, dont `10` est le **seul propriétaire**.
- Marqueurs : **[existant]** (conservé tel quel), **[modifié]** (le code existe et change), **[nouveau]**, **[renommé]** (toujours justifié), **[retiré]**. Ces cinq marqueurs se lisent **par rapport au dépôt**.
- **[brouillon]** : nom proposé par la rédaction d'un groupe, **absent du dépôt**, puis abandonné ou changé à la fusion. Il n'y a rien à retirer ni à migrer dans le code : le rédacteur emploie seulement le nom retenu.
- « E10-nn » renvoie à la liste consolidée des exigences adressées à `10` ; « A-nn » à la liste consolidée des amendements de `00`, `05`, `questions-ouvertes`, `CLAUDE.md` et `REPRISE`. Les sections 6 des contrats ne répètent plus ces textes : elles y renvoient et ne gardent en clair que les exigences **entre specs**.
- `RoomSettings::VERSION` **reste à 1**. Aucune décision du 23/09 n'ajoute un champ, n'en retire ni n'en change le sens : D17, D22, D28 et D34 sont sans schéma.
- Noms de tests : **phrase française au présent**, minuscule initiale, sans point final, `it()` ou `test()` au choix (R-02). Les tests existants ne sont pas renommés.

---

## Index des contrats

| # | Contrat | Propriétaire | Consommateurs | Jalon |
|---|---|---|---|---|
| C0 | `RoomSettings`, `RoomSettingsBounds`, `PlatformLimits`, `config/game.php` | 50 | 20, 30, 40, 60, 70, 80, 90, 100 | J1 ; onglet Avancé et chargement de `saved_config` au J2 |
| C1 | `FrameLevelCoverage` (répartition nominale, repli de niveau) | 30 | 20, 30, 100, fabriques et seeders | J1 |
| C2 | Constructeur unique du vivier et rapport de vivier en données | 30 | 20, 50, 60, 70, 90, 100 | J1 ; sélecteur de thèmes au J2 |
| C3 | Résultat de tirage, PRF seedée, substitution, solo, interface des leurres | 30 | 50, 60, 70, 80, 100 | J1 |
| C4 | `player_token` | 40 [J1] | 05, 50, 60, 70, 90, 100 | J1 ; rattachement siège → compte au J2 |
| C5 | Règle de pseudo et registre d'avatars | 40 [J1] | 05, 50, 60, 70, 80, 90, 100 | J1 ; masquage et branche provider au J2 |
| C6 | Transaction de lancement, « Rejouer », garde de drainage | 50 | 30, 60, 70, 80, 90, 100 | J1 |
| C7 | Canaux et contrat d'événements Reverb, resynchronisation | 60 | 40, 50, 70, 80, 90, 100 | J1 |
| C8 | Frappe du `serve_token` et route `GET /f/{serveToken}` | 60 | 10, 20, 30, 90, 100 | J1 ; annulation active au J2 (R-33) |
| C9 | Frame servable (ratio, master, dérivé, plancher, traitement, empreintes) | 20 | 10, 60, 90, 100 | J1 ; voie capture hors J1 |
| C9-bis | Route d'aperçu admin | 20 | 60, 90, 100 | J1 |
| C10 | Service de soumission et transaction de verrouillage | 70 | 50, 60, 80, 90, 100 | J1 ; écriture quasi-juste au J2 |
| C11 | Composition du QCM (`round_choice_set`) | 70 | 05, 30, 60, 90 | J1 |
| C12 | `AnswerKeyNormalizer`, `validation_version`, sous-titre, `near_miss` | 70 | 10, 20, 40, 50, 100 | J1 ; `near_miss` et écran des collisions au J2 |
| C13 | `ScoreCalculator`, `Ranking`, `FinalizeGame` | 80 | 20, 40, 50, 60, 70, 90, 100 | J1 ; affichage sans score, `waitingPays`, `mismatches` au J2 |
| C14 | Liste fermée `admin_action`, acteurs réservés, nom réel | 10 (liste) ; 20 (exigences, écrivain) | 20, 40, 100 | J1 ; gestes selon le tableau |
| C14-bis | Grille d'exclusion versionnée `ExclusionGrid` | 20 | 05, 10, 100 | J1 ; geste rétroactif au J2 |
| C15 | Domaines et clés de traduction | 05 | 20, 30, 40, 50, 60, 70, 80, 90, 100 | J1 ; familles réservées au J2 |
| C16 | Coquilles, conteneur d'image, annonceur `aria-live` | 90 | 20, 40, 50, 60, 70, 80, 100 | J1 |
| C17 | Prédicat « partie en cours » | 60 | 50, 100 | J1 |
| C18 | Groupes Pest, jobs CI, nommage des tests, matrice des réglages | 100 | 20, 30, 40, 50, 60, 70, 80, 90 | J1 |
| C18-bis | Drainage et garde de déploiement | 100 | 50, 60, 90 | J1 |
| Annexe | Indexation (recommandation, non figée) | 90 / 100 | — | J1 : `noindex` intégral |

Contrats ajoutés par rapport à `synthesis.json › shared_contracts` : C9-bis et C14-bis (rédigés par G20), C18-bis (rédigé par G90/100).

---

## C0 — `RoomSettings`, `RoomSettingsBounds`, `PlatformLimits` et `config/game.php`

### 1. Propriétaire, consommateurs, jalon

- **Propriétaire** : 50.
- **Consommateurs** :
  - 30 : thèmes, N, M, non-répétition, marge, fenêtre de mémoire, seuil du sélecteur ;
  - 60 : durées, R, difficulté de saisie, délai de déconnexion, retardataires, `tierGraceMs`, `preloadLeadMs` ;
  - 70 : `attemptsPerSecond`, `attemptsPerRound`, `maxAnswerLength`, `inputDifficulty` ;
  - 80 : `tierPoints`, `speedBonus`, B_max(N), M, N ;
  - 90 : bornes, libellés, états ;
  - 100 : matrice de tests, validité des presets ;
  - 20 : bornes de N pour la supervision ; plafonds de recadrage (R-07) ;
  - 40 : `savedConfigsPerUser`, `avatarPresets`, `roomSeats`.
- **Jalon 1** :
  - le value object, les bornes, `PlatformLimits` et `config/game.php` ;
  - l'onglet Simple, qui est le seul au J1 ;
  - `RoomSettingsEditor::simple()`, règle D34 comprise, testée au J1 même si ses branches `reset` et `equalized` ne sont atteignables qu'au J2 ;
  - le point d'écriture unique et le gel.
- **Jalon 2** :
  - `RoomSettingsEditor::advanced()` et l'onglet Avancé ;
  - le chargement de `saved_config` (codes `raised`, `overwritten`) ;
  - le sélecteur de thèmes visible, dès que le seuil de C2 est franchi.

### 2. Noms exacts

**PHP**

- `App\Settings\RoomSettings` **[modifié]**
  - Inchangés : `VERSION = 1`, `FIELDS`, `INPUT_ROUND_DURATION = 'roundDuration'`, les constantes `CHANGE_*` et `WARNING_*` existantes, `defaults()`, `fromInput(array $raw): self`, `validate(array $raw): array`, `normalize(array $raw, int $version, ?array $availableThemeIds = null): array`, `fromStorage(array $raw, int $version): self`, `roundDuration(): int`, `tierStartOffsetMs(int $tierIndex): int`, `warnings(): array`, `toPayload(): array`, `toJson(): string`, `equals(self $other): bool`.
  - **Nouvelles constantes** de rapport, qui ferment la liste des codes de changement :
    - `CHANGE_EQUALIZED = 'equalized'` et `CHANGE_RESET = 'reset'` (D34) ;
    - `CHANGE_RAISED = 'raised'` (J2, n° 44) ;
    - `CHANGE_OVERWRITTEN = 'overwritten'` (J2, 00 l.88).
  - Docblock l.32-33 corrigé : `fromInput()` refuse les bornes croisées 1 et 2 ; les bornes 4 et 5 sont des avertissements de `warnings()` (n° 39).
  - Docblock l.27 : `speedBonusMaxFraction` devient `speedBonusMaxPercent` (C13).
- `App\Settings\RoomSettingsBounds` **[modifié]**
  - Toutes les constantes existantes sont conservées, `clampFramesPerRound()` compris (jamais employé par B_max, R-05).
  - **Nouvelle constante** `MIN_CONNECTED_PLAYERS_TO_LAUNCH = 2`. Aujourd'hui, 00 l.27 l'écrit en toutes lettres.
  - **Nouvelle méthode** `public static function toClient(): array`, décrite en § 3.4.
  - Docblock de `RECOMMENDED_MIN_REVEAL_DURATION` : le motif devient la seule accessibilité (`aria-live`, reprise de focus). R n'est plus une fenêtre de préchargement **dédiée** (n° 48) : seul le palier 1 de la manche suivante devient servable, à `T₁(k+1) − preload_lead_ms`, dans les `preload_lead_ms` finales de R (enchaînement de C7 § 4.14, `T₁(k+1) = reveal_ends_at(k)`), ce que garantit le garde-fou `MIN_REVEAL_DURATION × 1000 > MAX_PRELOAD_LEAD_MS` (§ 4.8). Le seuil recommandé ne relève que de l'accessibilité.
- `App\Settings\PlatformLimits` **[modifié]**
  - Classe `final readonly`, accesseurs statiques. Aucune méthode ne reçoit un `User`, un `Plan` ou un `Player`.
  - **Famille « confort »** (surchargeable via `game.platform.*`) :
    - `savedConfigsPerUser(): int` = 20
    - `roomSeats(): int` = 12
    - `avatarPresets(): int` = 24
    - `historyWindowMonths(): int` = 12
    - `successRateMinRounds(): int` = 20
    - `frameUploadMaxKilobytes(): int` = 1536
  - **Famille « règle figée sur `game` au lancement »** (surchargeable, rejouée depuis la colonne) :
    - `tierGraceMs(): int` = 300
    - `preloadLeadMs(): int` = 2000, **écrêtée dans [`MIN_PRELOAD_LEAD_MS` 1500, `MAX_PRELOAD_LEAD_MS` 2500]** [modifié : aujourd'hui les bornes sont déclarées mais pas appliquées].
  - **Famille « règle non persistée »** (constante de code, **jamais lue en configuration**, liée à la version de score de 80) :
    - `public const int SPEED_BONUS_MAX_PERCENT_CAP = 50;` **[nouveau]**
    - `public const int FULL_PERCENT = 100;` **[nouveau]** — unité (100 %), pas une valeur de jeu ; c'est aussi la base du calcul du bonus en C13.
    - `public static function speedBonusMaxPercent(int $framesPerRound): int` **[renommé]**. Il remplace `speedBonusMaxFraction(): float` et `DEFAULT_SPEED_BONUS_MAX_FRACTION`, qui sont supprimés, ainsi que le paramètre de constructeur `float $speedBonusMaxFraction` et la clé `toArray()['speedBonusMaxFraction']` (D22, n° 64). La clé de configuration `game.platform.speed_bonus_max_fraction` n'existe plus.
    - Corps normatif : si `$framesPerRound ∉ [RoomSettingsBounds::MIN_FRAMES_PER_ROUND, MAX_FRAMES_PER_ROUND]`, `\InvalidArgumentException` (jamais d'écrêtage, R-05) ; sinon `min(self::SPEED_BONUS_MAX_PERCENT_CAP, intdiv(self::FULL_PERCENT, $framesPerRound - 1))`, soit 2→50, 3→50, 4→33, 5→25.
  - **Famille « tirage et mémoire »** (surchargeable ; son effet est matérialisé en `round` ou recalculé identiquement ; valeurs déclarées par 30, boundary map n° 20) :
    - `drawSubstituteMargin(): int` = 3 **[nouveau]** (n° 21), borne ≥ 0 ;
    - `roomMemoryWindowDays(): int` = 90 **[nouveau]**, borne ≥ 1 ;
    - `roomMemoryWindowRounds(): int` = 500 **[nouveau]**, borne ≥ 1. Avec la précédente, source unique de la non-répétition, de la préférence de variante et de la purge `seen_frame` (C2 `RoomMemoryWindow`, 10 § 11.1 et § 12) ;
    - `themeSelectorMinPool(): int` = 150 **[nouveau]**, borne ≥ 0 : domicile figé ici, valeur déclarée par 30 (Q30-3), revue après le lot pilote.
  - **Famille « lobby »** : `lobbyBroadcastDebounceMs(): int` = 300 **[nouveau]** (00 l.115, règle 2).
  - **Famille « curation »** (R-07, surchargeable) :
    - `frameCropMaxWidthPercent(): int` = 80 **[nouveau]**, constante `DEFAULT_FRAME_CROP_MAX_WIDTH_PERCENT = 80`, bornes [70, 90] ;
    - `frameCropMinWidthPx(): int` = 640 **[nouveau]**, constante `DEFAULT_FRAME_CROP_MIN_WIDTH_PX = 640`, multiple de 16 dans [320, 1280].
  - Hors bornes, le constructeur lève `InvalidArgumentException` (marge, fenêtre, seuil, plafonds de recadrage). **Chaque accesseur statique applique la même garde** (`InvalidArgumentException` hors bornes ; écrêtage pour `preloadLeadMs` seul), par exemple en déléguant à une instance `fromConfig()` mémoïsée : les consommateurs appellent les accesseurs statiques, qui lisent aujourd'hui `Config::integer` sans garde (`PlatformLimits.php` l.201-204), et une configuration hors bornes ne doit contourner la garde sur aucun chemin.
  - `fromConfig(): self` et `toArray(): array` sont **[modifiés]** (§ 3.4).
  - Docblock de classe (l.15-28) **réécrit** : `speedBonusMaxFraction` disparaît au profit de `speedBonusMaxPercent(N)`, constante de code dont le changement incrémente `scoring_version` ; `tierGraceMs` et `preloadLeadMs` sont figés par partie dans `game.tier_grace_ms` et `game.preload_lead_ms`, et un changement de leur défaut incrémente aussi `scoring_version` (10 § 7.2 l.764-768, C13 § 4.7). Le constructeur porte les **quinze** valeurs sans argument listées ci-dessus (six de confort, deux figées, quatre de tirage, une de lobby, deux de curation).
- `App\Settings\SettingPresetCatalog` **[existant]** : `settingsFor()`, `inputFor()`, `positionFor()`. Les chiffres sont repris sans modification (00 l.100).
- `App\Casts\RoomSettingsCast` **[existant]**.
- `App\Enums\InputDifficulty` (dont `choicesOpenTierIndex(int $n): ?int`) et `App\Enums\SettingPresetKey` **[existants]**.
- `App\Settings\RoomSettingsEditor` **[nouveau]** : fonction pure qui transforme une charge postée par onglet en entrée complète de `fromInput()`.
  ```php
  final readonly class RoomSettingsEditor
  {
      /** Clés postées par l'onglet Simple, seul onglet au J1. */
      public const array SIMPLE_KEYS = ['themeKeys', 'roundsCount', 'framesPerRound', 'roundDuration',
          'revealDuration', 'inputDifficulty', 'capacity', 'allowLateJoin', 'advanced'];
      /** J2 : D = Σ tierDurations, donc `roundDuration` n'est pas accepté. */
      public const array ADVANCED_KEYS = ['themeKeys', 'roundsCount', 'framesPerRound', 'tierDurations',
          'tierPoints', 'revealDuration', 'inputDifficulty', 'capacity', 'allowLateJoin', 'speedBonus',
          'noRepeatMovies', 'attemptsPerSecond', 'attemptsPerRound', 'maxAnswerLength',
          'disconnectGraceSeconds', 'advanced'];
      /**
       * @param array<string, mixed> $posted
       * @param array<string, int> $publishedThemeIdsByKey  clé de thème publiée → id, fourni par PoolQuery::publishedThemeIdsByKey() (C2)
       * @return array{input: array<string, mixed>, changes: array<string, string>}
       * @throws \Illuminate\Validation\ValidationException
       */
      public static function simple(RoomSettings $current, array $posted, array $publishedThemeIdsByKey): array;
      /** J2. Même signature. */
      public static function advanced(RoomSettings $current, array $posted, array $publishedThemeIdsByKey): array;
  }
  ```
- `App\Actions\Room\WriteRoomSettings` **[nouveau]** : `public function handle(Room $room, RoomSettings $settings, CarbonImmutable $now): void`. C'est le **seul écrivain** de `room.settings`, de `settings_version` et des cinq projections (`capacity`, `frames_per_round`, `rounds_count`, `input_difficulty`, `allow_late_join`) ; il pose aussi `last_activity_at = $now`.
  - Il exige une transaction ouverte, la ligne `room` verrouillée par l'appelant, `room.status = lobby` et `$settings->sourceVersion === RoomSettings::VERSION`. Sinon il lève une `LogicException`.
  - Appelants : création du salon, `UpdateRoomSettings`, `ApplyRoomPreset`, `LaunchGame` (branche `settings_outdated`, C6) et, au J2, le chargement de configuration.
- `App\Actions\Room\UpdateRoomSettings` et `App\Actions\Room\ApplyRoomPreset` **[nouveaux]** : verrou `room`, autorité d'hôte, statut `lobby`, éditeur, `fromInput()`, garde de capacité, puis `WriteRoomSettings`.
- `App\Support\Room\RoomSettingsPresenter` **[nouveau]** :
  - `public static function view(RoomSettings $settings): array` rend `RoomSettingsView` (§ 3.4) ;
  - `public static function state(Room $room, CarbonImmutable $now): array` rend `RoomSettingsState` ; le vivier y est `PoolReporter::report(PoolScope::forRoom($room, $room->settings, $now), M)->toArray()` (C2), dont le remède `disable_no_repeat` est retiré tant que l'onglet Avancé n'est pas livré (D28, R-11).

**Configuration** — `config/game.php` **[nouveau]**

- Section `platform` : lue **exclusivement** par `PlatformLimits`. Clés (quinze) : `saved_configs_per_user`, `room_seats`, `avatar_presets`, `history_window_months`, `success_rate_min_rounds`, `frame_upload_max_kilobytes`, `tier_grace_ms`, `preload_lead_ms`, `draw_substitute_margin`, `room_memory_window_days`, `room_memory_window_rounds`, `theme_selector_min_pool`, `lobby_broadcast_debounce_ms`, `frame_crop_max_width_percent`, `frame_crop_min_width_px`.
  - Chaque valeur vaut `PlatformLimits::DEFAULT_*` : la constante reste la source unique, sans `env()`.
  - **Aucune clé `speed_bonus_*`.**
- Section `engine` : propriété de 60 (`EngineConstants`, C7). 50 ne la lit pas.
- **Aucune section `operations`** [brouillon, R-08] : la borne et le TTL du drainage vivent dans `config/deploy.php` (C18-bis).
- Le premier lot qui crée le fichier pose les sections `platform` et `engine`.

**Routes** (`routes/game.php` **[nouveau fichier]**, `require` depuis `web.php`)

- `room.settings.update` : `PATCH /r/{room}/settings`. La clé `advanced` du corps choisit `simple()` ou `advanced()` ; absente, elle vaut l'onglet courant.
- `room.settings.preset` : `POST /r/{room}/settings/preset`, corps `{ preset: SettingPresetKey }`.
- `{room}` est résolu par `room_code` (`Room::resolveRouteBinding` **[existant]**).

**Front**

- `resources/js/types/room-settings.ts` **[nouveau]** : `InputDifficulty`, `RoomSettingsFieldKey`, `RoomSettingsView`, `RoomSettingsWarningCode`, `RoomSettingsChangeCode`, `Bound`, `RoomSettingsBoundsPayload`, `PlatformLimitsPayload`, `RoomSettingsState`, `RoomRefusalCode` (C6). Les types du vivier (`PoolReport`, `PoolFault`, `PoolRemedyKind`, `PoolRemedy`) sont importés de `types/pool.ts` (C2), jamais redéclarés (R-11, R-27).
- `resources/js/lib/room-settings.ts` **[nouveau]** : dérivations client pour le retour immédiat (découpage égal, barème par défaut, `attemptsPerRound` par défaut, D minimal, avertissements). Elles sont calculées **uniquement** depuis `RoomSettingsBoundsPayload`, sans aucun littéral. Le serveur reste seul juge.
- Les deux fichiers entrent dans le périmètre `WATCHED` dès leur création (C16 § 2.11, R-36).

**Clés de traduction**

- `room.settings.{clé}.label` et `room.settings.{clé}.help` pour chaque clé de `SIMPLE_KEYS ∪ ADVANCED_KEYS`. Le segment reprend la clé camelCase du champ.
- `room.settings.inputDifficulty.option.{easy|normal|expert}`.
- `room.settings.change.{defaulted|dropped|clamped|resized|pruned|coerced|equalized|reset|raised|overwritten}` (`:attribute`).
- `room.warnings.{short_reveal|long_round|non_decreasing_points|all_tiers_zero}` (`:seconds`).
- Vivier (codes de C2, R-11) : `room.pool.counter` (`:playable`, `:required`), `room.pool.blocked`, `room.pool.no_remedy` (vivier bloqué sans cause réglable, catalogue insuffisant), `room.pool.cause.{themeKeys|framesPerRound|roundsCount|noRepeatMovies}`, `room.pool.remedy.{open_new_room|disable_no_repeat|clear_themes|lower_frames_per_round|reduce_rounds_count}` (`:value`, `:count`). Une clé par cas des deux enums de C2, dans chaque locale activée, testée.
- `validation.room_settings.not_editable` **[nouveau]**, `validation.room_settings.theme_keys` **[nouveau]**, `validation.room_settings.capacity_below_headcount` **[nouveau]** (`:count`).
- `validation.attributes.themeKeys` **[nouveau]**.
- **Retirés** (n° 41) : `validation.attributes.frames_per_round` et `validation.attributes.rounds_count`.

### 3. Formes de données

**3.1 Les seize champs** (clés camelCase du stockage ; entiers ; durées en secondes entières)

| # | Champ | Type | Sens | Onglet | Édition J1 | Défaut (source) | Bornes (source) | Projection | Lu par |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `themeIds` (client : `themeKeys`) | `list<int>` | union OU ; `[]` = tout le catalogue | Simple | accepté par le serveur ; sélecteur masqué sous `themeSelectorMinPool()` | `[]` `defaultThemeIds()` | ids de thèmes publiés (règle de C2) | — | 30 |
| 2 | `roundsCount` | int | M | Simple | oui | 10 `DEFAULT_ROUNDS_COUNT` | 3–30 | `room.rounds_count`, `game.rounds_count` | 30, 60, 80 |
| 3 | `framesPerRound` | int | N | Simple | oui | 3 | 2–5 | `room.frames_per_round`, `game.frames_per_round` | 30, 60, 70, 80 |
| 4 | `tierDurations` | `list<int>` s | dᵢ, avec D = Σ | Avancé | **dérivé** de `roundDuration` | `defaultTierDurations(N, D)` | chaque dᵢ ∈ [5, 120 − 5(N−1)] ; Σ ∈ [max(10, 5N), 120] ; N entrées | → `round_tier.duration_ms` | 60, 80 |
| 5 | `tierPoints` | `list<int>` | valeur du palier i | Avancé | **dérivé** (D34) | `defaultTierPoints(N)` = (N−i+1)×100 | 0–1000 ; N entrées | → `round_tier.points` | 60, 80 |
| 6 | `revealDuration` | int s | R | Simple | oui | 8 | 3–20 | — | 60 |
| 7 | `inputDifficulty` | `InputDifficulty` | saisie easy / normal / expert | Simple | oui | normal | enum | `room.`/`game.input_difficulty` | 60, 70 |
| 8 | `capacity` | int | sièges | Simple | oui | `roomSeats()` | 2–`roomSeats()`, et ≥ effectif (garde hors VO) | `room.capacity` | 50 |
| 9 | `allowLateJoin` | bool | retardataires | Simple | oui ; **variable d'ajustement D17** : si la coupe s'applique, la clé sort de `SIMPLE_KEYS` et la valeur reste à `false` | false | — | `room.allow_late_join` | 50, 60 |
| 10 | `speedBonus` | bool | interrupteur du bonus | Avancé | non (défaut) | true | — | — | 80 |
| 11 | `noRepeatMovies` | bool | non-répétition | Avancé | non (défaut) | true | — | — | 30 |
| 12 | `attemptsPerSecond` | int | cadence | Avancé | non (défaut) | 1 | 1–5 | — | 70 |
| 13 | `attemptsPerRound` | int | plafond en texte libre | Avancé | **dérivé** (D34) | `defaultAttemptsPerRound(D)` | 5–50 | — | 70 |
| 14 | `maxAnswerLength` | int | longueur maximale | Avancé | non (défaut) | 100 | 20–200 | — | 70 |
| 15 | `disconnectGraceSeconds` | int s | délai avant « parti » (lobby et partie) | Avancé | non (défaut) | 60 | 15–180 | — | 60, 50 |
| 16 | `advanced` | bool | onglet retenu | sélecteur d'onglet | **toujours `false`** ; `true` est refusé `not_editable` | false | — | — | 50 |

`roundDuration` (D) est une **clé d'entrée seulement** de l'onglet Simple ; elle n'est jamais stockée.

**En solo**, `capacity`, `allowLateJoin` et `noRepeatMovies` sont sans effet. Le snapshot garde leur valeur, et le sort de `disconnectGraceSeconds` en solo appartient à 60.

**3.2 Clés postées** : camelCase exactement.
- Onglet Simple : `SIMPLE_KEYS`.
- Toute autre clé : `validation.room_settings.not_editable`, sous la clé de champ.
- `themeKeys` est traduit en `themeIds` par l'éditeur. Une clé inconnue ou non publiée donne `validation.room_settings.theme_keys`.
- Les erreurs de `fromInput()` sur `themeIds` sont réindexées sous `themeKeys`.
- Le sac d'erreurs utilise les mêmes clés, avec `tierDurations.{i}` et `tierPoints.{i}` pour les erreurs par palier [existant].
- Le snake_case est réservé aux colonnes.

**3.3 Règle Simple / Avancé** (D34, normative, dans `RoomSettingsEditor::simple`)

Notations : `N₀ = current.framesPerRound`, `D₀ = current.roundDuration()`, `N₁ = posted.framesPerRound ?? N₀`, `D₁ = posted.roundDuration ?? D₀`. **Le serveur n'ajuste jamais D** : le client remonte D au minimum `5 s × N` et l'annonce, et le serveur refuse toute entrée invalide.

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

- Les autres champs de l'onglet Avancé sont **conservés** : « conserver et signaler » (Q-50-8).
- Une valeur non entière est transmise telle quelle, et `fromInput()` la refuse.
- Un preset pré-remplit **les seize champs** avec `SettingPresetCatalog::settingsFor($key)` : les thèmes reviennent à `[]` et la capacité à `roomSeats()`. Au J2, les champs avancés écrasés sont rapportés `overwritten`.
- Le même calcul redérive les champs dérivés quand D19 ramène d'office le N d'un preset solo (C7 § 4.12).

**3.4 Charges utiles**

- `RoomSettingsView` (client) : les seize clés de `toPayload()`, avec `themeIds` remplacé par `themeKeys: list<string>` (`theme.key`, dans l'ordre de `themeIds`). Unités : secondes entières.
  - C'est la **seule** divergence entre stockage et client. Elle est **[renommée]** à la frontière parce que 10 § 1.1 interdit d'exposer un identifiant interne. Le stockage reste en `themeIds` (10 § 1.6).
- `RoomSettingsState` :
  ```
  { settings: RoomSettingsView, warnings: RoomSettingsWarningCode[], pool: PoolReport }
  ```
  - `pool` est exactement `PoolReport::toArray()` de C2 (R-11), `disable_no_repeat` filtré au J1.
  - **Diffusé au salon**, contenu identique pour tous, sans aucune chaîne traduite, par l'événement `settings.changed` et, au « Rejouer », `room.replayed` (C7, R-13, R-14).
  - Diffusion coalescée sur `lobbyBroadcastDebounceMs()` et **relue au moment d'émettre**, par le job `BroadcastLobbyState` de C7 § 2.5 (file `game`).
  - Nom d'événement, canal et enveloppe : propriété de 60.
- Rapport de changements : `changes: Record<RoomSettingsFieldKey, RoomSettingsChangeCode>`. Il est **ciblé** vers l'auteur, dans la réponse HTTP, en flash `settingsChanges`. Il ne part jamais au salon.
- `RoomSettingsBounds::toClient()` :
  ```
  { byFramesPerRound: { "2"|"3"|"4"|"5": toArray(N) },
    derivation: { tierPointsUnit, attemptsPerRoundSecondsPerAttempt, attemptsPerRoundSoftCap },
    warningThresholds: { recommendedMinRevealDuration, longRoundWarningDuration } }
  ```
  Toutes les valeurs sont des entiers. Sans ce découpage par N, le retour immédiat serait impossible quand N change.
- `PlatformLimits::toArray()` :
  ```
  { savedConfigsPerUser, roomSeats, avatarPresets, historyWindowMonths, successRateMinRounds,
    speedBonusMaxPercent: { "2":50, "3":50, "4":33, "5":25 } }
  ```
  - **Retirés** : `speedBonusMaxFraction`, `tierGraceMs`, `preloadLeadMs` et `frameUploadMaxKilobytes`. Aucun écran joueur n'en a besoin.
  - **Jamais ajoutés** : les deux plafonds de recadrage. 20 compose sa propre prop depuis les accesseurs (R-07).
  - C'est une prop de page, pas une prop partagée globale.

### 4. Invariants et garanties

1. **Un seul objet, liste close** de seize champs. Toute instance persistée sort de `fromInput()`, `normalize()` ou `defaults()`, avec `sourceVersion = VERSION`. `fromStorage()` est réservé au cast. Aucune instance n'est construite à la main.
2. **Point d'écriture unique** : `WriteRoomSettings`, sous transaction et verrou `room`, uniquement en statut `lobby`. Sur toute ligne, `projection == value object`.
3. **Bornes croisées** :
   - bornes 1 et 2 : refusées par `fromInput()`, erreurs indexées par champ, message résolu dans la langue de l'hôte ;
   - borne 3 : garde de vivier (30 calcule, 50 déclenche, affiche et rejoue au lancement, boundary map n° 14) ;
   - bornes 4 et 5 : `warnings()`, jamais bloquantes (n° 39) ;
   - capacité ≥ `COUNT(player holdingSeat)` : vérifiée hors du value object, sous verrou, avec `validation.room_settings.capacity_below_headcount`.
4. **J1** : toute ligne `room` porte `advanced = false`, et les champs 10 à 15 à leur défaut ou à leur valeur dérivée.
5. **Gel** :
   - `room.settings` est immuable de `room.status = playing` jusqu'au « Rejouer » (C6) ;
   - `game.settings_snapshot` et les colonnes figées de `game` sont immuables pour toujours ;
   - en partie, 60, 70 et 80 lisent `game.settings_snapshot`, les colonnes de `game` et `round_tier`, **jamais `room.settings`** ;
   - `round_tier.points` et `duration_ms` sont égaux au snapshot par construction.
6. **Constantes d'instance** :
   - aucun accesseur de `PlatformLimits` n'est paramétré par un compte, un plan ou un siège ;
   - B_max n'est jamais lu en configuration : il est lié à la version de score de 80, et le rejeu est exact grâce à `game.frames_per_round` et `scoring_version` ;
   - `tierGraceMs` et `preloadLeadMs` sont figés sur `game` au lancement ;
   - la marge est matérialisée par le nombre de lignes `round` ;
   - la fenêtre de mémoire est une seule valeur, lue à l'identique par le lobby, le lancement et la purge.
7. **Unités** : réglages en secondes entières ; B_max en pourcentage entier, calculé par `intdiv` ; instants et durées du moteur en millisecondes entières (60).
8. **Garde-fous** testés :
   - `MIN_REVEAL_DURATION × 1000 > MAX_PRELOAD_LEAD_MS` ;
   - `2 × tierGraceMs() < MIN_TIER_DURATION × 1000` ;
   - `MIN_CAPACITY ≥ MIN_CONNECTED_PLAYERS_TO_LAUNCH` ;
   - `roomSeats() ≥ MIN_CAPACITY` ;
   - `roomSeats() ≤ avatarPresets()` (exigence de 40, C5 I5.7).
9. **Règle 3** : aucune de ces charges ne porte de film, de graine ni d'identifiant interne (thèmes par clé, salon par code).

### 5. Provenance

| Valeur | Source unique | Surcharge |
|---|---|---|
| Défauts et bornes des seize champs, D min, dᵢ max | `RoomSettingsBounds` | code |
| Capacité maximale et par défaut | `PlatformLimits::roomSeats()` | `game.platform.room_seats` |
| Seuils d'avertissement | `RECOMMENDED_MIN_REVEAL_DURATION`, `LONG_ROUND_WARNING_DURATION` | code |
| 2 joueurs connectés | `MIN_CONNECTED_PLAYERS_TO_LAUNCH` | code |
| B_max(N) | `speedBonusMaxPercent(N)` | **jamais** |
| `tier_grace_ms`, `preload_lead_ms` | `PlatformLimits` → colonnes de `game` | config, figée par partie |
| Marge, fenêtre de mémoire, anti-rebond, seuil du sélecteur | `PlatformLimits` (valeurs déclarées par 30 pour marge, fenêtre et seuil) | config |
| Plafonds de recadrage | `PlatformLimits` (valeurs déclarées par 20) | config |
| Presets | `SettingPresetCatalog::inputFor()` | code |
| Version | `RoomSettings::VERSION` | code |

### 6. Exigences et amendements

- **À 10** (texte seulement : aucune colonne, aucun cas d'enum, aucun index) : E10-11 (charge client en `themeKeys`), E10-26, E10-27, E10-29, E10-38, E10-56.
- **Amendements** : A-01, A-09, A-10, A-12, A-13 (00) ; A-37 (05) ; A-60 (questions-ouvertes) ; A-68, A-73 (CLAUDE.md).
- **À 30** : fournir `PoolQuery::publishedThemeIdsByKey()` (R-12) ; exposer la liste des thèmes proposables par `key`, **sans `id`** ; lire la marge et la fenêtre dans `PlatformLimits`.
- **À 20** : lire les plafonds de recadrage par les accesseurs de `PlatformLimits`, jamais par `toArray()` (R-07).

### 7. Tests Pest

- `tests/Feature/Room/RoomSettingsContractTest.php` :
  - « déclare un défaut pour chacun des seize champs dans l'ordre de FIELDS »
  - « refuse les clés graceMs, tierGraceMs, preloadLeadMs, speedBonusMaxPercent et speedBonusMaxFraction » (partagé avec C13, qui ne le duplique pas)
  - « produit une charge utile dont les clés sont exactement FIELDS, en camelCase et dans l'ordre »
  - « garde VERSION à 1 tant que FIELDS est inchangé »
  - Les bornes croisées sont prouvées par `tests/Feature/Room/RoomSettingsMatrixTest.php` sur le jeu de données de C18 (R-04).
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
  - « ne laisse au J1 aucune ligne room avec advanced vrai ni un champ avancé hors de son défaut »
  - « diffuse l'état des réglages en données, sans identifiant interne ni chaîne traduite »
- `tests/Feature/Room/PresetValidityTest.php` (fusion avec C18, R-04) :
  - « construit chaque preset livré par le chemin d'entrée de l'hôte sans erreur »
  - « garde chaque preset livré lançable sur le catalogue de démonstration »
- `tests/Feature/Room/PlatformLimitsTest.php` :
  - « fixe speedBonusMaxPercent à 50, 50, 33 et 25 pour N = 2 à 5 sous la version de score 1 »
  - « refuse un N hors bornes pour speedBonusMaxPercent au lieu de l'écrêter »
  - « ignore toute configuration de B_max »
  - « n'a aucun accesseur paramétré par un utilisateur, un plan ou un siège » (réflexion)
  - « déclare une clé game.platform par accesseur configurable, et réciproquement »
  - « écrête preloadLeadMs dans [1500, 2500], sous la révélation minimale »
  - « garde deux tier_grace_ms sous la durée minimale de palier »
  - « n'offre jamais moins d'avatars prédéfinis que de sièges »
  - « refuse une marge, une fenêtre, un seuil ou un plafond de recadrage hors bornes »
  - « un accesseur statique refuse une configuration hors bornes »
  - « expose en prop exactement les clés du contrat, B_max en pourcentage entier par N »
- `tests/Feature/Room/SettingsFreezeTest.php` :
  - « fige settings_snapshot égal à room.settings et les colonnes de game égales au snapshot »
  - « porte les constantes de plateforme sur une partie lancée par un hôte hostile »

### 8. Libre pour le rédacteur de 50

- Les textes FR et EN de toutes les clés.
- La composition des écrans et l'ARIA (avec 90).
- Le déclenchement de l'anti-rebond (quelles écritures le programment), avec deux contraintes : jamais derrière les jobs Imagick de la file `default`, et 60 arbitre la file. 60 l'a arbitrée : job `App\Jobs\Game\BroadcastLobbyState` sur la file `game` (C7 § 2.5), que 50 dispatche.
- Le détail de l'onglet Avancé et du chargement de configuration (J2).
- Les noms des contrôleurs, les limiteurs nommés, la présentation du rapport de changements.
- Le recalcul du compteur à l'entrée dans le lobby.

---

## Vocabulaire nouveau du groupe 30 (à inscrire au lexique, A-25 et A-74)

Nouvel espace de noms : `App\Support\Draw\` (vivier, tirage, PRF). Il existe aujourd'hui `App\Support\Catalog\` et `App\ValueObjects\Catalog\`, mais aucun service de vivier ni de tirage.

| Produit (FR) | Code (EN) | Définition |
|---|---|---|
| œuvre | `work` | Unité de compte du vivier : un film sans `group_id`, ou un `movie_group` entier (ses films présents au vivier comptent pour 1). |
| vivier catalogue | catalogue pool (`PoolScope::catalogue()`) | Fonction de (thèmes, N) seulement : aucune clause de salon. |
| vivier du salon | room pool (`PoolScope::forRoom()`) | Vivier catalogue moins la non-répétition des films déjà joués par le salon. |
| mémoire du salon | room memory (`RoomMemoryWindow`) | Fenêtre commune à la non-répétition des films **et** à la préférence de variante (`seen_frame`). |
| manche de réserve | margin round | Manche tirée au lancement au-delà de `M` (`round_number` NULL), qui sert au remplacement. |

---

## C1 — `FrameLevelCoverage` (répartition nominale et repli de niveau)

### 1. Propriétaire, consommateurs, jalon
- Propriétaire : 30. Consommateurs : 30 (tirage, C3), 20 (indicateur « jouable à N », signal « repli » d'un film publié devenu incomplet), 100 (tests), fabriques et seeders.
- Jalon : **J1**.

### 2. Noms exacts
- `App\ValueObjects\Catalog\FrameLevelCoverage` **[nouveau]**, `final readonly`, méthodes statiques pures, sans base de données ni configuration. C'est le seul endroit du dépôt où la table N → niveaux est écrite (10 § 15 l.1409).

```php
namespace App\ValueObjects\Catalog;

use App\Enums\FrameLevel;

final readonly class FrameLevelCoverage
{
    /** @return list<FrameLevel> ordre croissant ; lève InvalidArgumentException hors [MIN_FRAMES_PER_ROUND, MAX_FRAMES_PER_ROUND] */
    public static function nominal(int $framesPerRound): array;

    /** @return list<FrameLevel>|null ordre croissant, N éléments distincts ; null si le masque couvre moins de N niveaux */
    public static function select(int $framesPerRound, int $levelsMask): ?array;

    /** Vrai si select() existe et diffère de nominal() : le film est joué avec repli de niveau. */
    public static function usesFallback(int $framesPerRound, int $levelsMask): bool;

    /** @param iterable<FrameLevel> $levels */
    public static function maskOf(iterable $levels): int;

    /** @return list<FrameLevel> niveaux présents dans le masque, ordre croissant */
    public static function levelsIn(int $levelsMask): array;
}
```

- Enum réutilisé tel quel : `App\Enums\FrameLevel` (`Level1..Level5`, `bit()`) **[existant]**. Aucun cas ajouté.
- **Suppressions dans le même lot** : `Database\Factories\MovieFactory::expectedFrameLevels()` **[retiré]**. Ses appelants (`MovieFactory::playable()`, `DemoCatalogueSeeder::frames()`, `DemoCatalogueChainTest`) appellent `FrameLevelCoverage::nominal()`. Son docblock l'annonce déjà « provisoire » (n° 21).
- Aucune route, aucun canal, aucun événement, aucune clé de traduction, aucun fichier TS.

### 3. Formes de données
- La table nominale, écrite une seule fois en `match` explicite : `2 → [1, 5]`, `3 → [1, 3, 5]`, `4 → [1, 2, 4, 5]`, `5 → [1, 2, 3, 4, 5]`.
- Algorithme de `select(N, mask)`. Il est déterministe, sans graine et sans doublon :
  1. `A` = niveaux présents dans `mask` (bits `FrameLevel::bit()`), triés croissants. Si `|A| < N`, renvoyer `null`.
  2. Parcourir **en ordre lexicographique** les combinaisons `S ⊆ A` de taille `N`, triées croissantes. Il y en a au plus `C(5, N) ≤ 10`.
  3. Coût `cost(S) = Σᵢ |S[i] − nominal(N)[i]|`, les niveaux étant lus comme entiers.
  4. Renvoyer la **première** combinaison de coût minimal. Les égalités sont donc départagées vers le niveau le plus cryptique.
  5. `tier_index = i` ⟺ `frame_level = S[i−1]`. Le niveau le plus cryptique disponible est toujours au palier 1.
- Table de cas normative. Elle sert aussi de jeu de données au test :

| N | masque (niveaux) | `select` |
|---|---|---|
| 3 | 1,2,3,4,5 | 1,3,5 |
| 3 | 1,2,4,5 | **1,2,5** (égalité de coût 1 avec 1,4,5 ; le plus cryptique gagne) |
| 3 | 1,2,3,5 | 1,3,5 |
| 3 | 2,3,4 | 2,3,4 |
| 2 | 1,3,5 | 1,5 |
| 2 | 1,2,3 | 1,3 |
| 2 | 2,3 | 2,3 |
| 4 | 1,2,3,4,5 | 1,2,4,5 |
| 4 | 1,2,3,5 | 1,2,3,5 |
| 4 | 1,3,5 | `null` |
| 5 | 1,2,3,4 | `null` |

### 4. Invariants et garanties
- `select(N, m) !== null` ⟺ `popcount(m) ≥ N` ⟺ `MovieProjection::supportsFramesPerRound(N)` (`levels_count ≥ N`) **[existant]**. L'éligibilité n'est jamais stockée.
- Monotonie : `select(N, m) !== null` ⇒ `select(N', m) !== null` pour tout `N' < N` dans les bornes.
- `nominal(N) == select(N, 31)` pour tout N. `nominal(MAX_FRAMES_PER_ROUND) == FrameLevel::cases()`.
- Un masque hors `[0, maskOf(FrameLevel::cases())]` et un N hors bornes lèvent `InvalidArgumentException`. Jamais d'écrêtage silencieux.
- Le masque 1/3/5 (`MovieProjection::publishableLevelsMask()` **[existant]**) n'entre **pas** dans `select` : c'est une garde de **transition** vers `published`, pas une condition de jeu (n° 4).

### 5. Provenance des valeurs
- Bornes de N : `RoomSettingsBounds::MIN_FRAMES_PER_ROUND` / `MAX_FRAMES_PER_ROUND`.
- Bits : `FrameLevel::bit()`.
- Table nominale : constante de **règle de produit**, écrite ici et nulle part ailleurs. Ce n'est pas une valeur de jeu réglable, donc pas une violation de la règle 2.

### 6. Exigences et amendements
- **À 10** : E10-67 (10 § 15 l.1409 : les deux signatures `nominal(int)` / `select(int, int)`). Aucune colonne, aucun index.
- **Amendements** : A-69 (CLAUDE.md § 2, repli de niveau, n° 21).

### 7. Tests Pest — `tests/Feature/Draw/FrameLevelCoverageTest.php`
- « la répartition nominale est celle de 00 pour chaque N dans les bornes »
- « nominal refuse un N hors des bornes de RoomSettingsBounds »
- « select rend la répartition nominale quand la banque la couvre »
- « select se replie sur les niveaux disponibles les plus proches sans doublon » (jeu de données = table ci-dessus)
- « select départage une égalité de coût vers le niveau le plus cryptique »
- « select rend null sous N niveaux distincts, exactement comme supportsFramesPerRound »
- « l'éligibilité est monotone en N »
- « le nombre de cas de FrameLevel égale la borne haute de N »
- `tests/Feature/Architecture/DrawBoundaryTest.php` › « la table nominale n'est écrite que dans FrameLevelCoverage » (MovieFactory n'a plus `expectedFrameLevels`)

### 8. Libre pour le rédacteur de 30
- La formulation pédagogique du repli dans la spec.
- Les exemples illustrés.
- La présentation du signal « repli » côté 20 : 30 ne fournit que `usesFallback()`.

---

## C2 — Constructeur unique du vivier et rapport de vivier en données

### 1. Propriétaire, consommateurs, jalon
- Propriétaire : 30.
- Consommateurs :
  - 50 : compteur du lobby, garde de lancement, grisage des presets, validation et normalisation de `themeIds`, traduction `themeKeys` → `themeIds` ;
  - 60 : vivier du solo, D19 ;
  - 70 : périmètres des leurres, D21 ;
  - 20 : supervision « X œuvres jouables à N » ;
  - 90 : rendu ;
  - 100 : tests.
- Jalon **J1** : constructeur, branches avec et sans thème, non-répétition, compte en œuvres, rapport, N jouable le plus proche, variante catalogue. `themeSelectorVisible()` est au **J2** : le sélecteur n'est pas livré au J1 (Q30-4).

### 2. Noms exacts

```php
namespace App\Support\Draw;

/** [nouveau] Entrées du prédicat de vivier, figées : un même objet sert la garde et le tirage. */
final readonly class PoolScope
{
    /** @param list<int> $themeIds demandés (non filtrés) @param list<int> $excludedMovieIds @param list<int> $excludedGroupIds */
    private function __construct(
        public array $themeIds,
        public ?int $framesPerRound,            // null = aucune clause de N (complément des leurres seulement)
        public ?int $roomId,                    // axe salon ; null en solo et pour le vivier catalogue
        public ?\Carbon\CarbonImmutable $memorySince, // non nul ssi roomId non nul
        public ?\Carbon\CarbonImmutable $playedUntil, // = $now ; non nul ssi roomId non nul : borne haute de « joué » (T₁ franchi)
        public bool $noRepeatMovies,            // clause de non-répétition active (exige roomId)
        public array $excludedMovieIds,
        public array $excludedGroupIds,
    ) {}

    public static function forRoom(\App\Models\Room $room, \App\Settings\RoomSettings $settings, \Carbon\CarbonImmutable $now): self;
    public static function forGame(\App\Models\Game $game, \Carbon\CarbonImmutable $now): self;   // depuis game.settings_snapshot + game.room_id
    public static function forDecoys(\App\Models\Round $round, \Carbon\CarbonImmutable $now): self; // D21, voir § 3
    /** @param list<int> $themeIds */
    public static function catalogue(array $themeIds, ?int $framesPerRound): self;
    /** @param list<int> $themeIds */
    public function withThemeIds(array $themeIds): self;
    public function withFramesPerRound(?int $framesPerRound): self;
    public function withoutNoRepeat(): self;
    /** @param list<int> $movieIds @param list<int> $groupIds */
    public function excluding(array $movieIds, array $groupIds): self;
}

/** [nouveau] LE constructeur de requête du vivier. Aucun autre prédicat de vivier n'existe dans app/. */
final readonly class PoolQuery
{
    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\Movie> movie ⋈ movie_projection, trié par movie.id croissant */
    public function movies(PoolScope $scope): \Illuminate\Database\Eloquent\Builder;
    public function countWorks(PoolScope $scope): int;
    /** @return list<PoolCandidate> triés par movieId croissant */
    public function candidates(PoolScope $scope): array;
    /** @return list<int> ids de thèmes is_published, croissants — alimente RoomSettings::normalize($raw, $v, $availableThemeIds) */
    public function publishedThemeIds(): array;
    /** [nouveau, R-12] @return array<string, int> theme.key → theme.id des thèmes is_published, triés par sort_order puis key — alimente RoomSettingsEditor (C0) ; les clés seules sortent vers le client */
    public function publishedThemeIdsByKey(): array;
}

/** [nouveau] */
final readonly class PoolCandidate { public function __construct(public int $movieId, public ?int $groupId, public int $levelsMask) {} }

/** [nouveau] Fenêtre unique de la mémoire du salon. */
final readonly class RoomMemoryWindow
{
    public static function since(int $roomId, \Carbon\CarbonImmutable $now): \Carbon\CarbonImmutable;
}

/** [nouveau] */
final readonly class PoolReporter
{
    public function __construct(private PoolQuery $pool) {}
    public function report(PoolScope $scope, int $roundsCount): PoolReport;          // exige scope->framesPerRound !== null
    public function nearestPlayableFramesPerRound(PoolScope $scope, int $roundsCount): ?int;
    public function themeSelectorVisible(): bool;                                     // J2
    /** @return array<int, int> N => œuvres, N de MIN à MAX_FRAMES_PER_ROUND, vivier catalogue sans thème */
    public function catalogueWorksByFramesPerRound(): array;
}

/** [nouveau] */
final readonly class PoolReport implements \Illuminate\Contracts\Support\Arrayable
{
    /** @param list<\App\Enums\PoolFault> $causes @param list<PoolRemedy> $remedies */
    public function __construct(
        public int $count, public int $framesPerRound, public int $roundsCount,
        public array $causes, public array $remedies,
        public ?int $nearestPlayableFramesPerRound, public bool $themesPruned,
    ) {}
    public function blocked(): bool; // $count < $roundsCount
    /** @return array{count: int, framesPerRound: int, roundsCount: int, blocked: bool, causes: list<string>, remedies: list<array{kind: string, value: int|null, count: int}>, nearestPlayableFramesPerRound: int|null, themesPruned: bool} */
    public function toArray(): array;
}

/** [nouveau] */
final readonly class PoolRemedy implements \Illuminate\Contracts\Support\Arrayable
{
    public function __construct(public \App\Enums\PoolRemedyKind $kind, public ?int $value, public int $count) {}
}

/** [nouveau] Garde défensive du tirage. */
final class PoolTooSmallException extends \RuntimeException
{
    public function __construct(public readonly int $works, public readonly int $roundsCount) {}
}
```

- **Enums [nouveaux]** :
  - `App\Enums\PoolFault: string` : `NoRepeatMovies = 'noRepeatMovies'`, `ThemeKeys = 'themeKeys'` [brouillon de 30 : `ThemeIds = 'themeIds'`, R-11 : le code voyage vers le client et y désigne le champ `themeKeys` de `RoomSettingsView`], `FramesPerRound = 'framesPerRound'`, `RoundsCount = 'roundsCount'`. Chaque valeur est une clé de `RoomSettingsEditor::SIMPLE_KEYS ∪ ADVANCED_KEYS`, ce qui est testé.
  - `App\Enums\PoolRemedyKind: string` : `OpenNewRoom = 'open_new_room'`, `DisableNoRepeat = 'disable_no_repeat'`, `ClearThemes = 'clear_themes'`, `LowerFramesPerRound = 'lower_frames_per_round'`, `ReduceRoundsCount = 'reduce_rounds_count'`.
  - Aucun des deux ne caste une colonne, donc aucune exigence de schéma.
- Scope existant **réutilisé** dans `PoolQuery` : `Movie::inPool()` (`availability = published` ET `content_flag = clear`) **[existant]**.
- **TS [nouveau]** : `resources/js/types/pool.ts`. Miroir de `toArray()` : `PoolFault`, `PoolRemedyKind`, `PoolRemedy`, `PoolReport`, en unions de littéraux. Seule déclaration de ces types côté client (R-27) ; entre dans `WATCHED` (R-36).
- Aucune route, aucun canal, aucun événement propre à 30. Le rapport voyage dans les messages de lobby (contenu : 50 via `RoomSettingsState`, canaux et enveloppe : 60).
- **Clés de traduction** : 30 n'en possède aucune. Les clés sont nommées par 50 (C0) : `room.pool.cause.<PoolFault>` et `room.pool.remedy.<PoolRemedyKind>`. Règle normative : une clé par cas, dans chaque locale activée, testée.
- **Supprimés** dans le lot correspondant :
  - `DashboardController::poolByFramesPerRound()` est remplacé par `PoolReporter::catalogueWorksByFramesPerRound()` (n° 26, lot de 20) ;
  - `CatalogIndexRequest::PLAYABLE_AT_MIN/MAX` sont remplacés par `RoomSettingsBounds` (n° 26) ;
  - `demoPool()` de `DemoCatalogueChainTest` est remplacé par `PoolQuery::movies()`, masque 21 retiré (n° 4).

### 3. Formes de données
- **Prédicat du vivier.** Les conditions s'appliquent toutes en ET, aucune n'est optionnelle sauf mention :
  1. `movie.availability = 'published'` ET `movie.content_flag = 'clear'` (`Movie::inPool()`).
  2. Si `framesPerRound !== null` : `movie_projection.levels_count >= N`. Jointure interne `movie_projection` **toujours** présente, tables non aliasées. **Pas** de `levels_mask & 21` (n° 4).
  3. Thèmes : `effective = themeIds ∩ publishedThemeIds()`.
     - Si `effective = []` : branche sans thème, **jamais** un `IN ()`.
     - Sinon : `EXISTS movie_theme(movie_id = movie.id, theme_id IN effective, is_active = true)`. Union OU.
     - `themesPruned = (themeIds ≠ [] ET themeIds ⊄ publishedThemeIds())`.
  4. Si `noRepeatMovies` : `NOT EXISTS round r (r.room_id = :roomId AND r.movie_id = movie.id AND r.started_at >= :memorySince AND r.started_at <= :playedUntil)`. **« Joué » = manche démarrée ⟺ `started_at IS NOT NULL AND started_at <= :now`** (T₁ franchi), quel que soit son statut, annulée comprise (n° 25). `started_at` étant écrit dès la programmation (C6 O9, C7 § 4.14), une manche programmée dont T₁ n'est pas atteint n'est pas jouée, pas plus qu'une manche de réserve jamais démarrée.
     - **Résidu nommé dans 30** : une manche restée `pending` après son T₁ parce que la partie a été close d'office (`stale_game` ; `FinalizeGame` laisse les `pending` intactes, C13 § 4.5) compte comme jouée. Le filtre `status <> 'pending'` l'écarterait mais sortirait la requête de l'index `round_room_started_movie_idx` ; il n'est pas retenu.
  5. `movie.id NOT IN excludedMovieIds` ET (`movie.group_id IS NULL` OU `NOT IN excludedGroupIds`).
- **Compte en œuvres** : `COUNT(DISTINCT CASE WHEN movie.group_id IS NULL THEN movie.id END) + COUNT(DISTINCT movie.group_id)`. Portable, sans `GROUP BY` (n° 23).
- **`RoomMemoryWindow::since(room, now)`** = `max(now − PlatformLimits::roomMemoryWindowDays() jours, t)` [brouillon de 30 : `roomMemoryDays`, R-06]. `t` est le `started_at` de la `roomMemoryWindowRounds()`-ième manche démarrée la plus récente du salon (même prédicat « joué » : `started_at <= now` ; `ORDER BY started_at DESC, id DESC`, offset `roomMemoryWindowRounds() − 1`, index `round_room_started_movie_idx` **[existant]**), ou −∞ s'il y en a moins. C'est la borne atteinte en premier.
- **Constructeurs de périmètre** :
  - `forRoom` : `themeIds`, N et `noRepeatMovies` lus dans `RoomSettings`. `roomId = room.id`. `memorySince = RoomMemoryWindow::since(room.id, now)`, calculé **une fois** ; `playedUntil = now`.
  - `forGame` : mêmes champs, lus dans `game.settings_snapshot` et `game.room_id`. Solo : `roomId` nul, `noRepeatMovies` faux.
  - `catalogue` : `roomId` nul, `memorySince` et `playedUntil` nuls, `noRepeatMovies` faux, aucune exclusion.
  - `forDecoys(round, now)` : `forGame(round.game, now)` plus les exclusions **toujours** posées par D21. Sont exclus le film cible (`round.movie_id`), son `movie_group`, et les films des manches **démarrées** de la partie (`started_at <= now`, T₁ franchi). Les manches futures, programmées comprises, ne sont **jamais** exclues, sinon cela trahirait le tirage. `now` est l'instant **théorique** de composition passé par 70 (C11 § 2), jamais l'heure d'exécution du job.
  - Le complément « catalogue publié, non-répétition conservée » de D21 s'écrit `forDecoys($round, $now)->withThemeIds([])->withFramesPerRound(null)`.
  - Le classement par profil de titre, l'ordre vivier du salon → complément → mode dégradé et le tirage des trois leurres restent à **70** (C11).
- **`PoolReport::toArray()`** (clés camelCase, entiers et booléens seulement). C'est la forme unique au lobby, au refus de lancement et en solo (R-11) :

```json
{
  "count": 4,
  "framesPerRound": 5,
  "roundsCount": 10,
  "blocked": true,
  "causes": ["framesPerRound", "roundsCount"],
  "remedies": [
    {"kind": "lower_frames_per_round", "value": 3, "count": 47},
    {"kind": "reduce_rounds_count", "value": 4, "count": 4}
  ],
  "nearestPlayableFramesPerRound": 3,
  "themesPruned": false
}
```

- **Sémantique des champs.** Si le vivier n'est pas bloqué, `causes = []`, `remedies = []` et `nearestPlayableFramesPerRound = null`. S'il est bloqué, les causes et remèdes suivent cet **ordre fixe**. Chaque remède débloque **à lui seul** :

| Cause (`PoolFault`) | Condition | Remède(s) produit(s) |
|---|---|---|
| `noRepeatMovies` | `scope.noRepeatMovies` ET `countWorks(scope->withoutNoRepeat()) ≥ M` | `open_new_room` {value: null, count}, puis `disable_no_repeat` {value: null, count} (D28 : au J1, le présentateur de 50 retire `disable_no_repeat`) |
| `themeKeys` | `effective ≠ []` ET `countWorks(scope->withThemeIds([])) ≥ M` | `clear_themes` {value: null, count} |
| `framesPerRound` | `nearestPlayableFramesPerRound ≠ null` | `lower_frames_per_round` {value: N', count} |
| `roundsCount` | `count ≥ RoomSettingsBounds::MIN_ROUNDS_COUNT` | `reduce_rounds_count` {value: count, count} |

- `causes` vide alors que le vivier est bloqué signifie : aucun réglage ne débloque à lui seul, le catalogue est insuffisant. Le texte correspondant appartient à 50 (`room.pool.no_remedy`).
- **`nearestPlayableFramesPerRound`** = le plus grand `N' ∈ [MIN_FRAMES_PER_ROUND, N − 1]` tel que `countWorks(scope->withFramesPerRound(N')) ≥ M`, `null` sinon. Le vivier étant monotone décroissant en N, c'est équivalent à une recherche par |ΔN| croissant et il n'existe jamais de N' > N jouable. Le même calcul sert au grisage des presets (50) et au N imposé d'office en solo (D19 : Hardcore passe à N = 3 au J1).
- **Diffusion** : le rapport est **diffusé au salon, identique pour tous** (00 l.116), dans `RoomSettingsState`. Il ne contient aucune donnée de joueur, aucun titre, aucun identifiant interne (`themesPruned` est un booléen, jamais une liste d'ids) et rien du tirage. Il ne révèle aucune bonne réponse. En solo, il est rendu à la seule page du joueur.
- **`catalogueWorksByFramesPerRound()`** : `{2: n, 3: n, 4: n, 5: n}`, soit `countWorks(PoolScope::catalogue([], N))`, un appel par N, sans `GROUP BY`. 20 en fixe la forme de prop et le libellé. `admin.dashboard.pool.movies` et `admin.dashboard.pool.scope_notice` (`lang/fr/admin.php`, sous `dashboard`, à ne pas confondre avec l'autre `scope_notice` de `catalog`) sont reformulés en « vivier catalogue » et « œuvres » (lot de 20, n° 24).
- **`themeSelectorVisible()`** = `countWorks(PoolScope::catalogue([], RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND)) ≥ PlatformLimits::themeSelectorMinPool()`. Recalculé au rendu, la réapparition est automatique. Purement d'affichage : le serveur accepte et valide `themeKeys` même quand le sélecteur est masqué.

### 4. Invariants et garanties
- **Même fonction partout.** Le compteur du lobby, la garde rejouée dans la transaction de lancement, le tirage (C3), le solo, la supervision et les leurres appellent tous `PoolQuery`. Aucune autre requête de vivier n'existe dans `app/`.
- **Même fenêtre pour la garde et le tirage.** L'action d'ouverture de partie (`OpenGame`, C6) capture **un seul** `$now`, construit **un seul** `PoolScope`, puis appelle successivement `PoolReporter::report($scope, M)` et `GameDrawer::draw($scope, …)` (C3) sous le verrou du salon. La seconde lecture fait seule autorité, le compteur du lobby n'est qu'indicatif (10 § 6.1 l.651).
- Aucun verrou sur les tables de catalogue. Un retrait ou une suspension validés après la lecture sont absorbés par 60 : substitution ou annulation.
- Aucun `GROUP BY`, aucun agrégat sur `frame`, aucune lecture dans un JSON (`themeIds` est désérialisé en PHP, puis passé en `IN`). Même résultat en SQLite et en MySQL.
- Unité unique, les œuvres : `count` du rapport = `game.draw_pool_size` = `DrawResult::$poolSize` (C3).
- Monotonie : `countWorks(scope avec N+1) ≤ countWorks(scope avec N)`.
- Un événement de catalogue (publication, retrait) **n'est pas poussé** aux lobbies ouverts. Le rapport est recalculé à chaque écriture de réglage (déclencheurs et anti-rebond : 50) et rejoué au lancement, qui renvoie le même rapport en cas de refus.
- La non-répétition porte sur le **film**, jamais sur le groupe (CLAUDE.md § 6 : la seule conséquence de `movie_group` est l'exclusion mutuelle dans un tirage).
- Aucune clause de salon dans les viviers catalogue et solo : `noRepeatMovies` y est ignoré, et le code `noRepeatMovies` ne peut jamais y apparaître.
- `publishedThemeIdsByKey()` et `publishedThemeIds()` décrivent le même ensemble ; seules les clés quittent le serveur (10 § 1.1).

### 5. Provenance des valeurs

| Valeur | Source |
|---|---|
| `themeIds`, N, M, `noRepeatMovies` | `room_settings` : `RoomSettings` de `room.settings` au lobby, `game.settings_snapshot` pour `forGame` et `forDecoys`, preset du site en solo (D19). |
| Bornes de N, `MIN_ROUNDS_COUNT`, N par défaut du seuil | `RoomSettingsBounds`. |
| 90 jours / 500 manches | `PlatformLimits::roomMemoryWindowDays()` / `roomMemoryWindowRounds()` (C0, R-06). |
| Seuil du sélecteur (150) | `PlatformLimits::themeSelectorMinPool()` (C0). |
| États de catalogue | `ContentAvailability::Published`, `ContentFlag::Clear`. |

- Aucun littéral de jeu dans `App\Support\Draw`.

### 6. Exigences et amendements
- **À 50** (repris dans C0, déjà intégré) : les accesseurs `roomMemoryWindowDays()`, `roomMemoryWindowRounds()`, `themeSelectorMinPool()` et `drawSubstituteMargin()` avec leurs bornes (≥ 1, ≥ 1, ≥ 0, ≥ 0). Aucune n'entre dans un calcul rejoué (le tirage est matérialisé), donc aucune n'incrémente `scoring_version` ni `validation_version`. Pas d'exposition dans `toArray()`.
- **À 50, ailleurs** :
  - la règle de validation d'entrée « `themeKeys` ⊂ clés de `PoolQuery::publishedThemeIdsByKey()` » ;
  - la fourniture de `publishedThemeIds()` à `RoomSettings::normalize()` ;
  - l'ordre d'appel de la transaction de lancement décrit au § 4 et en C3 § 3 ;
  - le grisage des presets par `report(PoolScope::forRoom($room, SettingPresetCatalog::settingsFor($key), $now), M)`.
- **Signalement à 50 et à 10, résolu** : `RoomSettings::toPayload()` expédiait `theme.id` au client, contre 10 § 1.1. Le client reçoit désormais `themeKeys` (C0 § 3.4, E10-11) ; 30 fonctionne sur les ids côté serveur.
- **À 10** : E10-23 (A16), E10-27 (énumération de `PlatformLimits`), E10-37 (`draw_pool_size`), E10-56 (fenêtre `seen_frame`), E10-64 (§ 12 vivier).
- **Amendements** : A-13, A-25, A-26, A-32, A-33 (00) ; A-66 (questions-ouvertes) ; A-69, A-74, A-75 (CLAUDE.md).

### 7. Tests Pest
- `tests/Feature/Draw/PoolQueryTest.php` :
  - « le vivier compte des œuvres : deux films d'un même movie_group comptent pour un »
  - « le vivier ne teste que levels_count >= N, jamais le masque 1-3-5 »
  - « une sélection de thèmes vide prend la branche sans thème »
  - « les thèmes se combinent en union »
  - « un thème dépublié est élagué, signalé par themesPruned, et ne produit jamais un IN vide »
  - « un film suspendu, retiré, bloqué ou non vérifié est hors du vivier »
  - « la non-répétition retire les films démarrés dans la fenêtre du salon, manches annulées comprises »
  - « une manche de réserve jamais démarrée ne compte pas comme jouée »
  - « une manche programmée dont T₁ n'est pas atteint ne compte pas comme jouée »
  - « le vivier est monotone décroissant en N »
  - « les périmètres catalogue et solo ne portent aucune clause de salon »
  - « aucune requête de vivier n'émet de GROUP BY » (capture `DB::listen`)
  - « la liste des thèmes publiés par clé et la liste des ids décrivent le même ensemble »
- `tests/Feature/Draw/PoolQueryMysqlTest.php`, groupe `mysql` au niveau du fichier (R-03) :
  - « le compte en œuvres est identique sur MySQL »
- `tests/Feature/Draw/RoomMemoryWindowTest.php` :
  - « la fenêtre est la plus récente des deux bornes : jours ou N-ième manche démarrée »
  - « moins de N manches démarrées : seule la borne en jours s'applique »
- `tests/Feature/Draw/PoolReportTest.php` :
  - « un vivier bloqué par la seule non-répétition nomme noRepeatMovies et propose un nouveau salon »
  - « un vivier bloqué par N nomme framesPerRound et propose le N jouable le plus proche »
  - « le N jouable le plus proche est le plus grand N inférieur atteignant M, null sinon »
  - « réduire M n'est proposé que si le vivier atteint le minimum de manches »
  - « un vivier non bloqué n'a ni cause ni remède »
  - « le rapport ne contient que des entiers, des booléens et des codes »
  - « chaque cas de PoolFault est une clé postable de RoomSettingsEditor »
  - « les unions de resources/js/types/pool.ts couvrent exactement les cas des deux enums »
  - « le sélecteur de thèmes n'est visible qu'au-dessus du seuil configuré » (J2)
- `tests/Feature/Draw/DecoyScopeTest.php` :
  - « forDecoys exclut la cible, son groupe et les manches démarrées, jamais les manches futures »
  - « forGame reconstruit le vivier du salon depuis l'instantané figé »
- `tests/Feature/Admin/DashboardTest.php` › « la supervision par N est celle du constructeur unique et lit ses bornes dans RoomSettingsBounds » (lot de 20)
- Clés par cas d'enum : test à la charge de 50 dans son lot i18n (`tests/Feature/I18n/TranslationCoverageTest.php`, extension du test des constructeurs de clés énumérables).

### 8. Libre pour le rédacteur de 30
- La forme SQL exacte : `EXISTS` ou jointure plus `DISTINCT`, du moment qu'elle est sans `GROUP BY` et donne le même résultat.
- La mise en cache éventuelle de `publishedThemeIds()` pendant une requête.
- La valeur 150, révisable par configuration.
- La rédaction de la fuite résiduelle par élimination quand `œuvres ≤ M + marge` (tout le vivier est tiré, le compte est public).
- L'évaluateur de thèmes, la dérivation de `movie_difficulty`, la liste d'une dizaine de sagas (S4) et les décennies livrées (1930-2020 sans trou, n° 27) : tous au J2, hors de ce contrat.

---

## C3 — Résultat de tirage, PRF seedée à contextes nommés, substitution, solo, interface des leurres

### 1. Propriétaire, consommateurs, jalon
- Propriétaire : 30.
- Consommateurs :
  - 50 : génère la graine et appelle le tirage dans la transaction de lancement (C6) ;
  - 60 : matérialise `round` et `round_tier`, substitue au moment de la frappe, remplace une manche annulée, mode solo ;
  - 70 : leurres (D21), ordre du QCM ;
  - 80 : sémantique de `sequence_index` / `round_number` ;
  - 100 : tests.
- Jalon : **J1** en entier. Le QCM existe dès le J1 en Facile et en Normal.

### 2. Noms exacts

```php
namespace App\Support\Draw;

/** [nouveau] Registre FERMÉ des contextes de dérivation. Aucune autre chaîne de contexte n'existe. */
final readonly class DrawContext
{
    private function __construct(public string $value) {}
    public static function movies(): self;                                                   // 'draw:movies'
    public static function workMember(int $sequenceIndex): self;                             // 'draw:work-member:{s}'
    public static function variant(int $sequenceIndex, int $tierIndex): self;                // 'draw:variant:{s}:{t}'
    public static function substitute(int $sequenceIndex, int $tierIndex): self;             // 'draw:substitute:{s}:{t}'
    public static function decoys(int $sequenceIndex): self;                                 // 'draw:decoys:{s}'           (usage : 70, rangs R1-R2)
    public static function decoysOriginal(int $sequenceIndex): self;                         // 'draw:decoys:{s}:original'  (usage : 70, rangs R3-R4) [ajouté, R-17]
    public static function qcmOrder(int $sequenceIndex, string $playerPublicId): self;       // 'draw:qcm:{s}:{publicId}'   (usage : 70)
}

/** [nouveau] PRF HMAC-SHA256 en mode compteur, réduction sans biais par rejet. */
final readonly class SeededPrf
{
    public const int SEED_BYTES = 32;
    public function __construct(#[\SensitiveParameter] private string $seed) {} // exactement 64 caractères [0-9a-f], sinon InvalidArgumentException
    public static function generateSeed(): string;               // bin2hex(random_bytes(self::SEED_BYTES))
    public static function forGame(\App\Models\Game $game): self; // new self($game->draw_seed)
    public function index(DrawContext $context, int $count): int; // uniforme dans [0, count), 1 ≤ count ≤ 2^31
    /** @return list<int> permutation de 0..count−1 */
    public function permutation(DrawContext $context, int $count): array;
}

/** [nouveau] */
final readonly class VariantCandidate
{
    public function __construct(public int $frameId, public \App\Enums\FrameLevel $frameLevel, public ?\Carbon\CarbonImmutable $lastSeenAt) {}
}

/** [nouveau] Entrées figées d'un tirage : drawFrom() est une fonction pure (ni base, ni configuration, ni horloge). */
final readonly class DrawInput
{
    /** @param list<PoolCandidate> $candidates @param array<int, list<VariantCandidate>> $variantsByMovie */
    public function __construct(
        public array $candidates, public array $variantsByMovie, public ?\Carbon\CarbonImmutable $memorySince,
        public int $framesPerRound, public int $roundsCount, public int $margin,
    ) {}
}

/** [nouveau] */
final readonly class GameDrawer
{
    public function __construct(private PoolQuery $pool, private VariantChooser $variants) {}
    /** Charge DrawInput depuis la base (même PoolScope que la garde), marge = PlatformLimits::drawSubstituteMargin(). @throws PoolTooSmallException */
    public function draw(PoolScope $scope, int $roundsCount, SeededPrf $prf): DrawResult;
    /** @throws PoolTooSmallException */
    public function drawFrom(DrawInput $input, SeededPrf $prf): DrawResult;
}

/** [nouveau] Jamais sérialisé vers un client. */
final readonly class DrawResult
{
    /** @param list<DrawnRound> $rounds */
    public function __construct(public array $rounds, public int $poolSize, public int $roundsCount) {}
}
final readonly class DrawnRound
{
    /** @param list<DrawnTier> $tiers */
    public function __construct(public int $sequenceIndex, public ?int $roundNumber, public int $movieId, public array $tiers) {}
}
final readonly class DrawnTier
{
    public function __construct(public int $tierIndex, public int $frameId, public \App\Enums\FrameLevel $frameLevel) {}
}

/** [nouveau] Choix d'une variante, au lancement comme en substitution. */
final readonly class VariantChooser
{
    /** @param list<VariantCandidate> $candidates variantes jouables d'un (film, niveau) */
    public function choose(array $candidates, ?\Carbon\CarbonImmutable $memorySince, SeededPrf $prf, DrawContext $context): ?int;
    /** @param list<int> $excludedFrameIds @return int|null frame.id de même niveau, null = aucune (60 annule) */
    public function substitute(\App\Models\Round $round, \App\Models\RoundTier $tier, array $excludedFrameIds, \Carbon\CarbonImmutable $now): ?int;
}

/** [nouveau] Le « film suivant du tirage ». */
final readonly class ReplacementRoundChooser
{
    public function next(\App\Models\Game $game): ?\App\Models\Round;
}
```

- **Marge** : `PlatformLimits::drawSubstituteMargin()`, clé `game.platform.draw_substitute_margin`, défaut 3, borne ≥ 0 (C0, n° 21). `DemoCatalogueSeeder::DRAW_MARGIN` et `GameFactory::DRAW_MARGIN` **[retirés]** ; le FAIT 2 de `DemoCatalogueChainTest` et `GameFactory` lisent `PlatformLimits`.
- **Renommages de contexte**, par rapport aux exemples de 10 § 7.2, à la résolution du n° 57 et aux propositions de G70 (R-17) :
  - `{roundId}` devient partout `{sequenceIndex}`. Unique par partie (`round_game_sequence_uq`), il rend le tirage calculable **avant** l'insertion des lignes, donc testable en fonction pure et rejouable sans identifiant de base.
  - `tiebreak:{roundId}` est **retiré** (n° 66) : l'égalité entre variantes se départage dans `draw:variant`, et aucune égalité de score n'est tranchée par la graine (C13).
  - `qcm:{roundId}:{playerId}` devient `draw:qcm:{sequenceIndex}:{playerPublicId}` : `player.public_id` est l'identité de siège stable (10 § 7.1).
  - `draw:decoys:{roundId}` et `draw:decoys:{roundId}:original` (G70) deviennent `DrawContext::decoys($s)` et `DrawContext::decoysOriginal($s)`.
- Aucune route, aucun canal, aucun événement, aucune clé de traduction, aucun fichier TS.

### 3. Formes de données et algorithmes (normatifs)
- **PRF** :
  - Bloc `b = 0, 1, 2…` : `hash_hmac('sha256', $context->value.'#'.$b, $seed, true)`. La clé est `game.draw_seed` tel que stocké (hexadécimal, 10 § 7.2).
  - Chaque bloc est découpé par `unpack('N8')` en 8 mots de 32 bits, consommés dans l'ordre.
  - `uniform(bound)` : `limit = 2³² − (2³² mod bound)`, on rejette tout mot `≥ limit`, on renvoie `mot mod bound`.
  - `index(ctx, count)` : premier `uniform(count)` du flux de `ctx`.
  - `permutation(ctx, count)` : Durstenfeld sur `[0..count−1]`, `i` de `count−1` à 1, `j = uniform(i+1)`, échange de `a[i]` et `a[j]`.
- **Vecteurs de référence**, calculés sur cet algorithme et figés dans le test. Une implémentation qui diverge est fausse. Graine `000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f` :
  - `permutation(movies(), 10)` = `[0,6,3,8,4,1,7,2,9,5]`
  - `index(variant(1,1), 3)` = `2`
  - `index(substitute(2,3), 2)` = `0`
  - `permutation(decoys(4), 5)` = `[4,3,1,2,0]`
  - `index(workMember(1), 2)` = `1`
  - Le rédacteur de 30 calcule et fige en plus un vecteur pour `decoysOriginal` et un pour `qcmOrder` ; aucun n'est inventé ici.
- **Tirage des films (`drawFrom`)** :
  1. Les œuvres sont construites depuis `candidates` (triés par `movieId`). Clé d'œuvre = `groupId`, sinon le film seul. Les œuvres sont ordonnées par leur plus petit `movieId`, leurs membres triés par id. `W = |œuvres|`.
  2. Si `W < M`, lever `PoolTooSmallException`.
  3. `K = min(M + margin, W)`.
  4. `perm = permutation(movies(), W)`. On parcourt les œuvres dans l'ordre de `perm`. Pour une œuvre à plusieurs membres, le membre retenu est `members[index(workMember(s), |members|)]`, où `s` est la position qu'elle occuperait.
  5. Pour le film retenu, on calcule le masque réel depuis ses variantes jouables (`variantsByMovie`, prédicat unique de 10 § 3.2), puis `FrameLevelCoverage::select(N, masque)`. Si le résultat est `null` (projection périmée), l'œuvre est **sautée**, journalisée sur le canal `game` (100), et le parcours continue.
  6. Arrêt à `K` films retenus. `sequenceIndex = 1..K` dans l'ordre retenu. `roundNumber = sequenceIndex` si `≤ M`, **`null` pour la réserve**. Moins de `M` retenus : `PoolTooSmallException`.
- **Paliers** : pour le palier `i` d'une manche `s`, `level = select(N, masque)[i−1]`, puis `frameId = VariantChooser::choose(variantes du film à ce niveau, memorySince, prf, variant(s, i))`. Le résultat n'est jamais `null`, par construction du masque.
- **`choose`** :
  - Une variante dont `lastSeenAt` est nul **ou** antérieur à `memorySince` est « non vue ». Sans cette règle, une ligne `seen_frame` encore présente parce que la purge du jour n'a pas tourné changerait le tirage. Le tirage ne dépend donc pas de l'heure de la purge.
  - Si au moins une variante est non vue, le groupe d'égalité est l'ensemble des non vues. Sinon, c'est l'ensemble des variantes de `lastSeenAt` minimal.
  - Le groupe est trié par `frameId`, puis on renvoie `groupe[index(ctx, |groupe|)]`. Aucun axe joueur.
- **`substitute`** :
  - Candidats : variantes du film `round.movie_id`, **au niveau `tier.frame_level` et à lui seul**, qui satisfont le prédicat unique **et dont le fichier est présent sur le disque `frames`** (exigence de 60, C8), moins `tier.frame_id` et `$excludedFrameIds`.
  - Mémoire : `seen_frame` du salon `game.room_id` avec `RoomMemoryWindow::since(room, now)`. Aucune mémoire en solo.
  - Contexte : `substitute(round.sequence_index, tier.tier_index)`.
  - `null` signifie que 60 annule la manche (`no_variant_available`).
  - 60 l'appelle **au moment de la frappe du `serve_token` du palier** : à l'ouverture du palier `i−1`, ou à la programmation de la manche pour `i = 1` (n° 47, C8). Si le fichier du candidat retourné manque sur le disque, 60 rappelle la méthode en l'ajoutant à `$excludedFrameIds`. La boucle est bornée par le nombre de candidats.
- **`ReplacementRoundChooser::next`** : la première manche de la partie, par `sequence_index` croissant, qui a `round_number IS NULL`, `status = pending`, `started_at IS NULL`, et dont le film satisfait encore `Movie::inPool()`. Renvoie `null` quand la réserve est épuisée : la partie continue avec une manche de moins (00 l.96). Un palier devenu non servable dans une manche de réserve passe par `substitute` à la frappe.
- **Matérialisation**, écrite par 60 (`App\Actions\Game\MaterializeDraw`, C6, R-15) dans la transaction de lancement :
  - **les `K` manches**, réserve comprise, avec `round.sequence_index`, `round.round_number` (NULL pour la réserve, qui reçoit au remplacement le numéro de la manche annulée), `round.movie_id`, `round.room_id = game.room_id` et `status = pending` ;
  - `N` lignes `round_tier` par manche (`tier_index`, `frame_id`, `frame_level`) ;
  - `game.draw_pool_size = DrawResult::$poolSize` et `game.draw_seed` = la graine générée.
- Les instants (`starts_at_offset_ms`, `duration_ms`, entiers en ms) et les `points` ne sont **pas** dans `DrawResult` : 60 et 80 les dérivent de `settings_snapshot`.
- **Ordre d'appel imposé à la transaction de lancement** (repris à l'identique dans C6 § 3, étapes L1-L2 et O2-O8) :
  1. verrou du salon ;
  2. `$now` unique ;
  3. `$scope = PoolScope::forRoom(...)`, ou `PoolScope::catalogue(...)` en solo après l'ajustement de D19 ;
  4. `PoolReporter::report($scope, M)` ; s'il est bloqué, refus avec le rapport ;
  5. `$seed = SeededPrf::generateSeed()` ;
  6. `GameDrawer::draw($scope, M, new SeededPrf($seed))` ;
  7. écriture de `game`, puis matérialisation par 60.
- **Mode solo (sans mémoire)** : périmètre `catalogue` ; `noRepeatMovies` ignoré ; `memorySince` nul ; tous les `lastSeenAt` nuls, donc le départage se fait par la graine seule ; `substitute` et `next` sans mémoire. Rien n'est écrit dans `seen_frame` (60). Tout le reste est identique au multijoueur.
- **Interface des leurres (D21)** : 70 appelle, pour la première composition (T₁ en Facile, T_N en Normal) :
  - `PoolQuery::movies(PoolScope::forDecoys($round, $at))` pour le vivier du salon, `$at` étant l'instant théorique de composition (C11 § 2) ;
  - puis `PoolQuery::movies(PoolScope::forDecoys($round, $at)->withThemeIds([])->withFramesPerRound(null))` pour le complément (non-répétition conservée, D21) ;
  - **aucun** rang ne lève la non-répétition : après R4, c'est le cas terminal de C11 (D21).
  - Chaque requête est triée par `movie.id`. 70 y ajoute ses clauses `movie_projection.title_mask_version` / `title_locale_mask` et parcourt chaque rang dans l'ordre de `SeededPrf::forGame($game)->permutation($context, n)`, avec `$context = DrawContext::decoys($round->sequence_index)` pour R1-R2 et `DrawContext::decoysOriginal($round->sequence_index)` pour R3-R4 (R-17).
  - L'ordre du QCM par siège utilise `permutation(DrawContext::qcmOrder($round->sequence_index, $player->public_id), 4)`.
  - La règle d'enchaînement, le mode dégradé et la composition restent à 70.

### 4. Invariants et garanties
- **Déterminisme** : `drawFrom` est une fonction pure de (graine, candidats ordonnés, variantes par film, `memorySince`, N, M, marge). Deux appels identiques donnent un `DrawResult` identique. Aucun rejeu de production ne recalcule le tirage : il relit `round` et `round_tier`.
- **Secret et sécurité** :
  - graine CSPRNG de 32 octets ; `mt_srand`, `srand`, `rand`, `mt_rand`, `random_int`, `array_rand`, `shuffle`, `str_shuffle`, `crc32`, `Arr::shuffle`, `Arr::random`, `Collection::shuffle()` et `->random()` sont interdits dans `App\Support\Draw` et dans tout chemin qui dépend de la graine (leurres et QCM de 70 compris) ;
  - `random_bytes` n'est autorisé que dans `SeededPrf::generateSeed()` : `OpenGame` (C6) appelle cette méthode, jamais `bin2hex(random_bytes(32))` en ligne ;
  - la graine est `#[\SensitiveParameter]` et une propriété privée.
- **Règle 3** : `DrawResult`, `DrawnRound`, `DrawnTier`, `SeededPrf`, `DrawInput`, `VariantCandidate` et `DrawContext` n'implémentent ni `Arrayable`, ni `Jsonable`, ni `JsonSerializable`. Seuls `PoolReport` et `PoolRemedy` sont sérialisables. `frame_id`, `frame_level` (qui trahit le repli), `movie_id` et `draw_pool_size` ne quittent jamais le serveur (10 § 15, `#[Hidden]`).
- **Unicité** : au plus un film par `movie_group` dans les `K` films ; aucun film en double ; `K = min(M + marge, W)` avec `W ≥ M`. À `W = M`, il n'y a pas de réserve et un échec technique se résout en annulation.
- **Non-blocage des variantes** : chaque palier tiré a au moins une variante par construction. Une variante déjà vue vaut mieux qu'une manche annulée (00 l.37).
- **Substitution** : même niveau, jamais un autre. Elle est idempotente : un job de frappe rejoué redonne la même variante, puisque la mémoire du salon ne change que sur les frames d'autres films. `served_frame_id` et `substitution_reason` sont écrits une fois par 60. Aucune colonne de temps ni de points n'est touchée.
- **Cohérence avec la garde** : `DrawResult::$poolSize === PoolReporter::report($scope, M)->count` avec le même `PoolScope` dans la même transaction.
- La mémoire du salon lue au lancement couvre les `K × N` paliers. Les `seen_frame` écrits pendant la partie ne modifient jamais un palier déjà tiré.

### 5. Provenance des valeurs

| Valeur | Source |
|---|---|
| Marge (3) | `PlatformLimits::drawSubstituteMargin()` |
| N, M | `game.frames_per_round` / `game.rounds_count` (ou `RoomSettings` au lancement) |
| Fenêtre de mémoire | `RoomMemoryWindow` / `PlatformLimits::roomMemoryWindow*()` |
| Graine | `game.draw_seed` (CSPRNG) |
| `SEED_BYTES = 32` | Constante de sécurité de 10 § 7.2 |
| Chaînes de contexte | `DrawContext` (registre fermé) |
| Prédicat de variante jouable | 10 § 3.2 / `Frame::isServable()` **[existant]** |
| Niveaux | `FrameLevelCoverage` (C1) |

- Aucun littéral de jeu.

### 6. Exigences et amendements
- **À 10** : E10-15, E10-25, E10-40, E10-45, E10-54, E10-55. **Aucune colonne, aucun index, aucun cas d'enum de colonne demandé à 10.**
- **Amendements** : A-11, A-32 (00) ; A-49 (05) ; A-51, A-63 (questions-ouvertes) ; A-69 (CLAUDE.md).
- **Code, dans le lot de 30** :
  - commentaires « repli non vue par le joueur » de `DemoCatalogueSeeder` l.99 et `DemoCatalogueChainTest` l.675 corrigés en « non vue par le salon → vue la moins récemment par le salon → graine » (n° 20) ;
  - docblock de `RoundFactory` l.179 corrigé vers « tirés à la première composition » (n° 57, lot de 70).

### 7. Tests Pest
- `tests/Feature/Draw/SeededPrfTest.php` :
  - « les vecteurs de référence sont figés »
  - « la réduction par rejet est sans biais » : 60 000 graines déterministes `hash('sha256', 'seed'.$k)`, `permutation(movies(), 3)`, χ² < 20,52 (5 ddl, p = 0,001)
  - « une graine qui n'est pas 64 caractères hexadécimaux est refusée »
  - « generateSeed produit 64 caractères hexadécimaux »
- `tests/Feature/Draw/GameDrawTest.php` :
  - « le tirage rend min(M + marge, œuvres) films et au plus un par movie_group »
  - « deux parties lancées à la même seconde sur le même vivier ne tirent pas la même séquence »
  - « un rejeu à graine et entrées figées reproduit exactement films et variantes »
  - « les manches de réserve n'ont pas de numéro »
  - « tier_index suit les niveaux croissants de FrameLevelCoverage »
  - « préférence de variante : non vue par le salon, puis vue la moins récemment, puis la graine »
  - « une ligne seen_frame hors fenêtre est lue comme non vue »
  - « le solo ignore la mémoire du salon et la non-répétition »
  - « un vivier sous M lève PoolTooSmallException »
  - « une projection périmée fait sauter l'œuvre sans bloquer le tirage »
  - « draw_pool_size égale le compte du rapport de garde »
- `tests/Feature/Draw/SubstitutionTest.php` :
  - « une variante de substitution garde le même niveau et exclut la variante fautive »
  - « sans variante de même niveau, la substitution rend null et jamais un autre niveau »
  - « la substitution écarte une variante dont le fichier manque sur le disque »
  - « le remplaçant est la première manche de réserve encore au catalogue »
  - « une réserve épuisée rend null »
- `tests/Feature/Architecture/DrawBoundaryTest.php` :
  - « aucun chemin dépendant de la graine n'emploie shuffle, mt_rand, srand, random_int, array_rand ni crc32 » (arch `not->toUse` plus un balayage source de `->shuffle(` / `Arr::random`, `App\Support\Draw` et `App\Support\Answers` compris)
  - « seuls PoolReport et PoolRemedy sont sérialisables dans App\Support\Draw »
- `tests/Feature/Schema/DemoCatalogueChainTest.php` (réécrit) : `demoPool` passe par `PoolQuery`, sans masque 21 ; FAIT 2 lit `PlatformLimits::drawSubstituteMargin()`.

### 8. Libre pour le rédacteur de 30
- La stratégie de chargement de `DrawInput` : par lots dans l'ordre de `perm`, ou tout le vivier. Le chargement complet coûte au plus environ 500 films et 4 000 variantes, une fois par lancement.
- Le budget de requêtes du lancement.
- Le format du journal d'une œuvre sautée (canal `game`, 100).
- La rédaction des fuites résiduelles assumées : repli de niveau déductible de la difficulté visuelle, élimination quand tout le vivier est tiré, dictionnaire visuel appris en solo (D16, nommé par 60).
- Le découpage en lots et les heures (S3).
- Tout ce qui relève de 60 (écriture de `seen_frame` sur `served_frame_id`, annulation, motifs d'incident) et de 70 (enchaînement et profil des leurres, composition du QCM).

---

## Préambule du groupe 40

Sources : D2 (40 scindée, section [J1] écrite maintenant, cookie HttpOnly chiffré), D12 (nom réel des comptes privilégiés), D15 (jeton d'un expulsé refusé, `player.kicked_at`), D26 (pseudo en écriture latine seule), D27 (pack d'animaux Kenney CC0, `preset-01`..`preset-24`). Contradictions traitées : n° 11, 29, 34, 36 et 74.

Vérifié dans le dépôt le 23/09 :
- `ext-intl` est absente, mais `Normalizer::normalize(…, FORM_C)` fonctionne grâce à `symfony/polyfill-intl-normalizer`. Ce paquet n'arrive que de façon transitive, par `symfony/console` → `symfony/string`.
- `preg_match('/\p{Latin}/u')` fonctionne sans `intl`.
- Passées par `Str::ascii` ou `Str::transliterate`, toutes les lettres des plages admises donnent un résultat ASCII non vide, `ª` → `a` et `º` → `o` compris. Aucune ne donne plus de 2 caractères (Æ, Þ, ß, Ĳ, Œ, ŋ→ng). `µ` est exclu parce qu'il n'est pas de script latin (`\p{Latin}` faux ; `Str::ascii` le vide).
- Le schéma impose déjà `UNIQUE (room_id, nickname_normalized)` (`player_room_nickname_uq`) sur **tous** les sièges du salon, y compris ceux qui sont partis.

---

## C4 — `player_token`

### 1. Propriétaire, consommateurs, jalon
- **Propriétaire** : 40, section [J1] « identité invitée » (D2). 10 ne garde que `player.player_token_hash`.
- **Consommateurs** :
  - 05 : niveau 3 de la résolution de langue, `LocaleController` ;
  - 50 : création du salon, prise et reprise de siège, expulsion ;
  - 60 : garde `player`, autorisation des canaux, resynchronisation, route `/f/{serveToken}`, siège solo ;
  - 70 : via la garde et le middleware de siège de 60 ;
  - 90 : tableau des cookies de la page de confidentialité ;
  - 100 : rotation d'`APP_KEY`, sondes.
- **Jalon** :
  - J1 pour tout ce qui suit ;
  - J2 (40-[J2]) : rattachement d'un siège à un compte à la connexion. Il s'appuie sur l'invariant I4.6, déjà testé au J1.

### 2. Noms exacts

| Nom | Statut | Rôle |
|---|---|---|
| `App\Support\Identity\PlayerToken` (`app/Support/Identity/PlayerToken.php`) | **[nouveau]** | Value object `final readonly` du jeton |
| `App\Support\Identity\PlayerTokenCookie` | **[nouveau]** (miroir de `App\Support\I18n\LocaleCookie`) | Seule source des attributs du cookie, et seul lecteur et écrivain |
| `App\Support\Identity\PlayerTokenManager` | **[nouveau]**, lié en `singleton` sans état (mémo porté par la requête) | Lecture, frappe paresseuse, re-signature, résolution du siège |
| `App\Support\I18n\CookiePlayerTokenLocale` | **[nouveau]**, implémente l'interface existante `PlayerTokenLocale` | Niveau 3 réel |
| `App\Support\I18n\NullPlayerTokenLocale` | [existant] | Plus lié dans le conteneur (suppression laissée libre) |
| `App\Providers\AppServiceProvider::registerLocalization()` | [modifié] | `bind(PlayerTokenLocale::class, CookiePlayerTokenLocale::class)` et `singleton(PlayerTokenManager::class)` |
| `App\Http\Controllers\Locale\LocaleController::update()` | [modifié] | Re-signature et mise à jour de `player.locale` |
| `App\Models\Player` | [modifié] | Scope `heldByToken`, `wasKicked()`, colonne `kicked_at` |

```php
namespace App\Support\Identity;

final readonly class PlayerToken
{
    public const int VERSION = 1;               // version de la charge utile
    public const int TID_BYTES = 32;            // tid = bin2hex(random_bytes(32)), 64 hex minuscules
    public const string TID_PATTERN = '/^[0-9a-f]{64}$/';

    private function __construct(#[\SensitiveParameter] private string $tid, public ?Locale $locale, public ?string $avatar) {}

    public static function mint(Locale $locale, ?string $avatar = null): self;
    /** @param array<array-key, mixed> $claims */
    public static function fromClaims(array $claims): ?self;      // null si v ≠ VERSION, tid hors motif ou type faux
    /** @return array{v: int, tid: string, locale: string|null, avatar: string|null} */
    public function toClaims(): array;
    public function withLocale(Locale $locale): self;             // même tid
    public function withAvatar(?string $avatar): self;            // même tid ; lève InvalidArgumentException hors AvatarPresetCatalog
    public function hash(): string;                               // hash('sha256', $tid), 64 hex
    public function sameIdentityAs(self $other): bool;            // hash_equals sur tid
}

final class PlayerTokenCookie
{
    public const string NAME = 'player_token';
    public const int LIFETIME = 60 * 24 * 30;   // minutes, glissante
    public static function make(PlayerToken $token): \Symfony\Component\HttpFoundation\Cookie;
    public static function queue(PlayerToken $token): void;
    public static function read(\Illuminate\Http\Request $request): ?PlayerToken; // valeur déjà déchiffrée par EncryptCookies
}

final class PlayerTokenManager
{
    public const string REQUEST_ATTRIBUTE = 'tripleframes.player_token';
    public function current(Request $request): ?PlayerToken;                  // ne frappe jamais, ne repose jamais le cookie ; sans exception si absent ou invalide
    public function ensure(Request $request): PlayerToken;                    // current() ?? mint ; repose le cookie (glissement)
    public function resign(Request $request, PlayerToken $token): PlayerToken;// LogicException si le tid diffère de current()
    public function seatIn(Request $request, Room $room): ?Player;            // siège du jeton dans ce salon, expulsé EXCLU
    public function wasKickedFrom(Request $request, Room $room): bool;
}

// App\Models\Player
#[Scope] protected function heldByToken(Builder $query, PlayerToken $token): void; // where player_token_hash = $token->hash()
public function wasKicked(): bool;                                                // kicked_at !== null
```

Autres noms :
- **Routes** : aucune route nouvelle. `locale.update` existe déjà. Les routes qui appellent `ensure()` appartiennent à leurs propriétaires : `room.store` et `room.join` (50), `solo.store` (60).
- **Clé de traduction** : `room.join.kicked`. Le nom est figé ici, le texte appartient à 50.
- **Événements et canaux** : aucun. Le jeton ne voyage jamais sur Reverb.
- **Lecteur du hash pour 60** (exigence de 60) : `PlayerTokenManager::current($request)?->hash()` ; rien d'autre n'est ajouté (R-30).

### 3. Formes de données
- **Charge utile du cookie `player_token`.** C'est du JSON, chiffré et authentifié par `EncryptCookies` avec `APP_KEY` (préfixe de nom de cookie Laravel compris) :
  - `v` : int, vaut `1` ;
  - `tid` : string, 64 caractères hexadécimaux ;
  - `locale` : `"en"` | `"fr"` | `null` ;
  - `avatar` : `"preset-NN"` | `null`.

  Le pseudo n'y figure jamais, pas plus que le siège, le salon ou un consentement.
- **Attributs du cookie** :
  - nom `player_token`, `path=/`, `domain` nul (hôte seul, donc aucune dépendance à `<DOMAINE>`) ;
  - `HttpOnly`, `SameSite=Lax` ;
  - `Secure` quand `App::isProduction()` ;
  - durée 30 jours glissants, jamais dans `encryptCookies(except: …)`.
- **Au décodage** :
  - une `locale` inconnue de `Locale::tryFrom()` devient `null` ;
  - un `avatar` hors `AvatarPresetCatalog` devient `null`.

  Dans les deux cas, le jeton reste valide : retirer une langue ou changer de pack d'avatars ne détruit aucun siège.
- **Ce qui ne quitte jamais le serveur** : `tid`, `hash()`, `player_token_hash`, `active_seat_token` (sauf vers l'onglet qui vient de le frapper, en prop `seatToken`, C7), `player.id`. Ils n'apparaissent dans aucune prop Inertia partagée, aucun message Reverb, diffusé ou ciblé, aucune URL et aucune ligne de journal. Le client adresse un siège par `public_id` seulement (10 § 1.1). Le jeton ne porte aucune donnée de manche, donc rien qui touche la règle 3.

### 4. Invariants et garanties
- **I4.1 Frappe paresseuse.** Seul `ensure()` frappe un jeton, et seuls l'appellent les gestes qui prennent ou reprennent un siège : `room.store`, `room.join`, `solo.store`. Un GET (accueil, lien de salon), `locale.update` et la connexion n'en frappent jamais.
- **I4.2 Idempotence par requête.** Le jeton courant est mémorisé dans `$request->attributes[REQUEST_ATTRIBUTE]`. Plusieurs `ensure()` et `current()` dans une même requête rendent le même jeton, et un seul `Set-Cookie` part (le `queue` d'un même nom remplace le précédent).
- **I4.3 Hash.** `player.player_token_hash = hash('sha256', tid)`, calculé sur la chaîne hexadécimale ASCII. On ne hache jamais la valeur chiffrée du cookie, qui change à chaque re-signature. Le hash est posé à la création du siège et effacé à l'archivage (10 § 11.1).
- **I4.4 Invariance du siège.** Aucun chemin ne change le `tid`. `withLocale` et `withAvatar` le conservent, et `resign()` refuse un autre `tid`. Un jeton = un siège par salon (`player_room_token_uq`) ; en solo, la reprise se fait par `player_token_idx`, règle de 60.
- **I4.5 Glissement et réalignement.** Chaque `ensure()` repose le cookie avec 30 jours pleins et réaligne la revendication `locale` sur la locale effective de la requête (résolue par `SetLocale`). Toute écriture de `player.avatar_preset` par un geste du joueur re-signe le jeton avec cet `avatar`.
- **I4.6 Connexion neutre.** Connexion, déconnexion, inscription, passkey et invalidation de session ne lisent ni n'écrivent le cookie `player_token` : l'identité ne passe pas par la session PHP.
- **I4.7 Jeton invalide = absent.** Sont traités comme absents, silencieusement, sans erreur ni journal :
  - un MAC faux ;
  - une valeur venant d'un autre cookie ou d'une autre clé ;
  - un JSON illisible (`json_decode`, profondeur 4, `JSON_THROW_ON_ERROR` capturé) ;
  - un `v` inconnu ou un `tid` hors motif.

  Le geste de siège suivant frappe un jeton neuf. Toute future version N+1 relit N tant qu'un siège frappé sous N peut exister (au moins 48 h, filet `stale_room`).
- **I4.8 Langue** (05, n° 36) :
  - `CookiePlayerTokenLocale::fromRequest()` = `PlayerTokenManager::current($request)?->locale`.
  - `LocaleController::update`, si un jeton existe : il re-signe avec `withLocale` (même `tid`), puis `Player::query()->heldByToken($t)->holdingSeat()->update(['locale' => …])` via le builder Eloquent (jamais `DB::table`, précision en millisecondes). Aucun état de jeu n'est touché, et aucun QCM n'est recomposé.
  - Sans jeton, aucune frappe.
  - Le geste est idempotent.
- **I4.9 Expulsion (D15).** Le geste d'expulsion (50) s'exécute dans une transaction sous `lockForUpdate` du salon :
  - `connection_state = left`, `left_at = kicked_at = même instant serveur (ms)` ;
  - si une partie tourne, `game_player.status = kicked`, points conservés.

  Ensuite :
  - `seatIn()` rend `null` pour ce siège et `wasKickedFrom()` rend `true` ;
  - la prise de siège refuse avec `room.join.kicked` **avant** tout comptage, jamais par une 1062 ;
  - `kicked_at` n'est jamais remis à `NULL` : pas de réadmission ;
  - le refus tombe de lui-même à l'archivage, qui efface le hash ;
  - un second geste d'expulsion ne fait rien.

  **Résolution du siège par tous les consommateurs** (R-30) : garde et canaux de 60, `/f/`, resynchronisation, middleware de siège actif et soumission de 70 identifient le siège **exclusivement** par le hash du jeton courant (`PlayerTokenManager::current($request)?->hash()`), **en excluant toujours `kicked_at` non nul**. Pour un salon donné, la forme est `seatIn()` ; pour l'appartenance à une partie (`/f/`) et pour le solo (sans salon), c'est le scope `Player::heldByToken()` combiné à `whereNull('kicked_at')`. Un autre jeton (fenêtre privée) reste possible : c'est le résidu déjà écrit en 10 § 7.1.
- **I4.10 Au J1** : aucune écriture de `player.user_id`. Un compte connecté prend un siège comme un invité (pseudo saisi, prédéfini choisi).
- **I4.11 Clé.** Une rotation d'`APP_KEY` invalide tous les jetons. Elle se fait seulement avec `APP_PREVIOUS_KEYS` et hors partie en cours (drainage D32, C18-bis).

### 5. Provenance des valeurs

| Valeur | Provenance |
|---|---|
| `tid` sur 32 octets | `PlayerToken::TID_BYTES`, constante de format, CSPRNG `random_bytes`, jamais un ULID ni un horodatage |
| `v` | `PlayerToken::VERSION`, constante de version |
| 30 jours | `PlayerTokenCookie::LIFETIME`, constante (précédent : `LocaleCookie::LIFETIME`). Ce n'est pas une valeur de jeu ; 90 la publie |
| Chiffrement et MAC | `EncryptCookies`, `config('app.key')`, `app.previous_keys` |
| `Secure` | `App::isProduction()`, comme `LocaleCookie` |
| `locale` | Locale effective de la requête (`SetLocale`, ordre de 05) |
| `avatar` | Dernier prédéfini choisi ; domaine = `AvatarPresetCatalog::keys()` |
| `kicked_at` | Horloge serveur, à l'instant du geste |

Aucune valeur ne vient de `room_settings`, et aucune valeur de jeu n'est touchée (règle 2).

### 6. Exigences et amendements
- **À 10** : E10-01 (`player.kicked_at`), E10-11 (§ 1.1 l.29 : forme du jeton à 40 [J1]), E10-33 (hash du `tid`), E10-34 (`#[Hidden]`), E10-67 (§ 15 ligne 40).
- **Code** : `PlayerFactory` gagne l'état `kicked()` (D15).
- **Amendements** : A-14, A-18, A-32, A-34 (00) ; A-38 (05) ; A-64 (questions-ouvertes) ; A-73, A-74 (CLAUDE.md) ; A-79 (REPRISE).
- **Aux autres contrats** :
  - **60** : la garde `player`, les callbacks de canaux, `/f/` et la resynchronisation résolvent le siège selon I4.9 ; `Broadcast::routes()` et `/f/` restent sous une pile qui contient `EncryptCookies` ; le client expulsé déjà abonné cesse de recevoir le flux du salon, mécanisme propriété de 60 (C7 § 4.13 : `seat.kicked` + client coopératif, résidu nommé).
  - **50** : le geste d'expulsion suit I4.9 ; la reprise suit `seatIn()` puis `wasKickedFrom()`.
  - **90** : ligne de cookie « `player_token`, siège d'invité et reprise, langue et avatar, 30 jours après la dernière prise de siège, HttpOnly, chiffré, strictement nécessaire ».
  - **100** : procédure de rotation de clé (I4.11).

### 7. Tests Pest nommés
- `tests/Feature/Identity/PlayerTokenTest.php` :
  - « ne frappe aucun player_token sur une requête qui ne prend aucun siège »
  - « ne frappe qu'un player_token par requête, quel que soit le nombre d'appels à ensure »
  - « ne stocke que le SHA-256 du tid dans player_token_hash »
  - « écrit le cookie player_token chiffré, HttpOnly, SameSite=Lax, sur le chemin / pour 30 jours »
  - « fait glisser l'expiration du cookie et réaligne la revendication de langue à chaque prise ou reprise de siège »
  - « traite comme absent un cookie altéré, illisible, étranger ou d'une version future »
  - « conserve le tid quand sa revendication de langue ou d'avatar n'est plus connue »
  - « refuse de re-signer un jeton sous un autre tid »
  - « laisse le player_token intact à la connexion, à la déconnexion et à l'inscription »
  - « re-signe le player_token avec la nouvelle langue et le même tid »
  - « passe chaque siège tenu par le jeton à la nouvelle langue, et rien d'autre »
  - « ne frappe pas de player_token quand un invité qui n'en a pas change de langue »
- `tests/Feature/Identity/KickedSeatTest.php` :
  - « masque un siège expulsé à seatIn tandis que wasKickedFrom le signale »
  - « n'oublie le refus que lorsque l'archivage efface le hash du jeton »
- `tests/Feature/I18n/SetLocaleTest.php` (ajouts) :
  - « restaure la langue d'un invité depuis le player_token quand le cookie locale a disparu »
  - « préfère le cookie locale à la revendication du player_token »
- `tests/Feature/Architecture/PlayerTokenBoundaryTest.php` :
  - « n'exempte jamais le cookie player_token du chiffrement »
  - « ne lit et n'écrit le cookie player_token que par PlayerTokenCookie »
  - « frappe des tid de 64 caractères hexadécimaux minuscules qui ne se répètent jamais »
- Exigés des consommateurs :
  - 50, `tests/Feature/Room/KickTest.php` : « refuse le jeton d'un siège expulsé dans le même salon jusqu'à l'archivage » et « laisse un jeton expulsé prendre un siège dans un autre salon » ;
  - 50, `tests/Feature/Room/LobbyPayloadTest.php` : « n'envoie jamais au client le player_token, son tid ni son hash » ;
  - 60 : couvert par `tests/Feature/Game/ChannelAuthorizationTest.php` › « un siège expulsé est refusé sur les deux canaux jusqu'à l'archivage » (C7, dédoublonné, R-04).

### 8. Ce qui reste libre pour 40
- La rédaction et les exemples.
- La suppression ou la conservation de `NullPlayerTokenLocale`.
- Les détails d'encodage JSON au-delà des quatre revendications.
- Le texte de `room.join.kicked` (50).
- Le nom et le principal de la garde (60, figés en C7).
- Tout le rattachement invité → compte, qui relève de 40-[J2].

---

## C5 — Règle de pseudo et registre d'avatars

### 1. Propriétaire, consommateurs, jalon
- **Propriétaire** : 40 [J1].
- **Consommateurs** :
  - 50 : formulaires de création et d'entrée, unicité sous verrou, présélection ;
  - 60 : démarrage solo, gel `game_player.display_*` au lancement (via `OpenGame`, C6), `SeatView` (C7) ;
  - 80 : identité sur le podium et les faits D25 ;
  - 90 : sélecteur, composant d'avatar, règle `alt` ;
  - 100 : relevé des licences ;
  - 05 : domaines de clés ;
  - 70 : fournit `fold()` (C12).
- **Jalon** :
  - J1 : tout ce qui suit ;
  - J2 : masquage effectif, branche provider d'un siège, pré-remplissage depuis le compte.

### 2. Noms exacts

| Nom | Statut |
|---|---|
| `App\Rules\ValidNickname implements Illuminate\Contracts\Validation\ValidationRule` | **[nouveau]** (dossier `app/Rules/` nouveau) |
| `App\Concerns\PlayerIdentityValidationRules` (trait, modèle : `ProfileValidationRules`) | **[nouveau]** |
| `App\Support\Identity\NicknameNormalizer` | **[nouveau]** |
| `App\Support\Identity\NicknameBlocklist` | **[nouveau]** |
| `App\Support\Identity\PlayerIdentity` (`final readonly`) | **[nouveau]** |
| `App\Avatars\AvatarPresetCatalog` | **[nouveau]**, à côté d'`AvatarRef` |
| `App\Avatars\AvatarRef` | [modifié] : valeurs `ALT_KEY_*` **[renommées]** (n° 34), `presetUrl()` extraite |
| `resources/moderation/nicknames/{en,fr}.txt`, `reserved.txt` | **[nouveaux]**, un fichier par cas de `Locale` |
| `public/avatars/preset-01.webp` … `preset-24.webp`, `public/avatars/LICENSE.md` | **[nouveaux]** |
| `resources/js/types/player.ts` (réexporté par `types/index.ts`) | **[nouveau]**, seule déclaration de `PlayerIdentity` et `AvatarData` côté client (R-27), dans `WATCHED` |

```php
final class NicknameNormalizer {
    public const int MIN_LENGTH = 2; public const int MAX_LENGTH = 20; public const int MAX_RAW_BYTES = 256;
    public static function canonical(string $raw): string;       // forme AFFICHÉE et stockée dans player.nickname
    public static function normalize(string $canonical): string; // preg_replace('/[^a-z0-9]+/', '', AnswerKeyNormalizer::fold($canonical))
}
final class NicknameBlocklist {
    public const string DIRECTORY = 'moderation/nicknames';      // resource_path()
    public const string RESERVED_FILE = 'reserved';
    public const int SUBSTRING_MIN_LENGTH = 5;
    public const array LEET_PRIMARY = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't'];
    public const array LEET_SECONDARY = ['0' => 'o', '1' => 'l', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't'];
    public static function blocks(string $canonical): bool;
    /** @return list<string> */ public static function files(): array;
}
final class ValidNickname implements ValidationRule {
    public const string ALLOWED_PATTERN = '/^[A-Za-z0-9\x{00AA}\x{00BA}\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{017F} _-]+$/u';
    public const string KEY_LENGTH = 'validation.nickname.length';            // :attribute :min :max
    public const string KEY_SCRIPT = 'validation.nickname.script';            // :attribute
    public const string KEY_CHARACTERS = 'validation.nickname.characters';
    public const string KEY_ALNUM = 'validation.nickname.alnum';
    public const string KEY_NORMALIZED_LENGTH = 'validation.nickname.normalized_length';
    public const string KEY_BLOCKED = 'validation.nickname.blocked';
    public const string KEY_TAKEN = 'validation.nickname.taken';              // émise par 50, jamais par la règle
    public const array MESSAGE_KEYS = [/* les sept */];
    public function validate(string $attribute, mixed $value, Closure $fail): void;
}
trait PlayerIdentityValidationRules {
    /** @return array<int, ValidationRule|string> */ protected function nicknameRules(): array;      // ['required', 'string', new ValidNickname]
    /** @return array<int, mixed> */                 protected function avatarPresetRules(): array;  // ['required', 'string', Rule::in(AvatarPresetCatalog::keys())]
    protected function prepareNickname(mixed $raw): mixed;  // is_string ? NicknameNormalizer::canonical($raw) : $raw
}
final class AvatarPresetCatalog {
    public const string KEY_FORMAT = 'preset-%02d';
    public const string LABEL_KEY_PREFIX = 'common.avatar.preset.';
    /** @return list<string> */ public static function keys(): array;   // i = 1..PlatformLimits::avatarPresets()
    public static function has(string $key): bool;
    public static function labelKey(string $key): string;
    /** @param list<string> $taken */ public static function suggest(?string $preferred, array $taken): string;
    /** @return list<array{key: string, url: string, labelKey: string}> */ public static function options(): array;
}
final readonly class PlayerIdentity {
    public static function fromSeat(Player $seat): self;
    public static function fromGamePlayer(GamePlayer $participation): self;  // exige player:id,public_id,nickname_masked_at chargé
    /** @return array{publicId: string, nickname: string|null, masked: bool, avatar: array{kind: string|null, url: string|null, altKey: string, initials: string}} */
    public function toArray(): array;
}
// AvatarRef : ALT_KEY_PRESET = 'common.avatar.alt.preset', ALT_KEY_PROVIDER = 'common.avatar.alt.provider',
//             ALT_KEY_INITIALS = 'common.avatar.alt.initials' ; public static function presetUrl(string $key): string
```

Champs de formulaire (50 et 60) : `nickname` (string) et `avatar` (clé). Chaque FormRequest appelle `merge(['nickname' => $this->prepareNickname(...)])` dans `prepareForValidation()`, puis applique `nicknameRules()` et `avatarPresetRules()`.

Clés de traduction :
- `lang/{en,fr}/validation.php` : `nickname.{length, script, characters, alnum, normalized_length, blocked, taken}` et `attributes.avatar` [nouvelle] ; `attributes.nickname` (40, C15).
- `lang/{en,fr}/common.php` : `avatar.alt.{preset, provider, initials}`, `avatar.picker.{label, taken}`, `avatar.preset.preset-01` … `preset-24`, soit 48 libellés FR/EN.

### 3. Formes de données
- **`PlayerIdentity`** (TypeScript : `PlayerIdentity`, `AvatarData` dans `types/player.ts`) :

  ```ts
  type AvatarData = { kind: 'preset' | 'provider' | null; url: string | null; altKey: string; initials: string }; // = AvatarRef::toArray() [existant]
  type PlayerIdentity = {
    publicId: string /* char(12) base32 */;
    nickname: string | null;
    masked: boolean;
    avatar: AvatarData;
  };
  ```

  - **Diffusion** : au salon, identique pour tous, embarquée dans les charges de lobby, de manche et de podium (50, 60, 80) : `SeatView` de C7 l'étend (R-29) et le podium de C13 l'embarque. Elle ne contient aucune donnée de manche, donc rien pour la règle 3.
  - **Au J1** : `masked` vaut toujours `false` et `nickname` n'est jamais `null`.
  - **Jamais sérialisés** : `id`, `room_id`, `user_id`, `nickname_normalized`, `kicked_at`, le hash, `active_seat_token`. Elle n'est construite que par `PlayerIdentity`, jamais par `Player::toArray()`.
- **Props de la page d'entrée** (réponse HTTP au seul demandeur ; les noms de props appartiennent à 50) :
  - `AvatarPresetCatalog::options()` ;
  - les avatars pris : `list<string>` des `avatar_preset` des sièges `holdingSeat()` ;
  - la suggestion `suggest(current()?->avatar, pris)` ;
  - `{min, max}` depuis `NicknameNormalizer`. Le front n'écrit jamais `2` ni `20` en dur et n'embarque jamais la liste noire.

### 4. Invariants et garanties
- **I5.1 Forme canonique** (`canonical`) :
  - Normalisation NFC (`Normalizer::FORM_C`, polyfill Symfony), puis `trim()` aux extrémités et chaque suite d'U+0020 intérieure réduite à une seule espace.
  - Une entrée de plus de `MAX_RAW_BYTES` n'est pas transformée et échoue sur la longueur.
  - C'est la forme stockée dans `player.nickname` (accents et casse conservés).
- **I5.2 Ordre de validation.** Un seul message, le premier échec l'emporte :
  1. `mb_strlen` ∉ [2, 20] → `length` ;
  2. caractère hors `ALLOWED_PATTERN` (D26) :
     - s'il s'agit d'une lettre ou d'un chiffre (`\p{L}` ou `\p{N}` : kana, kanji, cyrillique, grec, hangul, pleine chasse, `µ`) → `script`. `ª` et `º`, lettres de script latin du Latin-1 supplément (D26), sont **admis** ;
     - sinon (invisibles, contrôles, marques combinantes restantes, autres espaces, symboles, émojis) → `characters` ;
  3. `normalize()` vide → `alnum` ;
  4. `strlen(normalize()) > 20` → `normalized_length`, jamais une 1406 ;
  5. liste noire → `blocked`, sans jamais citer le mot ;
  6. unicité → `taken` (50).
- **I5.3 Forme normalisée** : `normalize()` = `fold()` de 70, puis suppression de tout ce qui n'est pas `[a-z0-9]` (espaces, `-`, `_`, résidus). Aucun retrait d'article, aucune conversion de chiffres. Exemples : « Jean-Luc » = « jean luc » = « JEAN_LUC » → `jeanluc` ; « Le Boss » ≠ « Boss ». La fonction est pure, déterministe et idempotente. C'est elle qui écrit `player.nickname_normalized` (n° 29), jamais `AnswerKeyNormalizer::normalize()` ni `fold()` seul (R-39).
- **I5.4 Unicité par salon.**
  - Elle porte sur **tous** les sièges du salon, partis et expulsés compris, jusqu'à l'archivage : c'est la portée réelle de `player_room_nickname_uq`.
  - 50 la vérifie sous le verrou du salon, après la reprise.
  - Une `UniqueConstraintViolationException` résiduelle est traduite en `taken`, jamais en 1062 brute.
  - Pas d'unicité en solo.
- **I5.5 Pas de rétroactivité.** La règle s'applique à la création d'un siège et à toute réécriture de pseudo que 50 autoriserait. Un siège repris n'est jamais revalidé : une liste noire enrichie n'éjecte personne.
- **I5.6 Liste noire.** Ressource versionnée, aucune table (10 A15). Elle applique l'**union** des fichiers de toutes les locales activées et de `reserved.txt`, quelle que soit la langue du joueur.
  - Format des fichiers : UTF-8, LF, une entrée par ligne, `#` pour les commentaires. En-tête obligatoire : `# source:`, `# license:`, `# retrieved: AAAA-MM-JJ`.
  - Chaque entrée est compilée par `normalize()`.
  - `blocks()` est vrai s'il existe une variante V dans {`LEET_PRIMARY`, `LEET_SECONDARY`} × {brute, lettres répétées réduites à une seule} telle qu'une entrée compilée k vérifie au moins l'une des conditions suivantes :
    - `strlen(k) ≥ SUBSTRING_MIN_LENGTH` et k est une sous-chaîne de la forme compacte ;
    - k est l'un des jetons `preg_split('/[^a-z]+/')` de la forme repliée ;
    - k est égal à la forme compacte entière.
  - Effet visé : protéger « Conan » et « Leçon » de l'effet Scunthorpe.
  - Un fichier manquant pour une locale activée lève une exception bruyante.
  - `reserved.txt` contient au minimum : admin, administrateur, administrator, moderateur, moderator, modo, curateur, curator, system, systeme, support, staff, officiel, official, tripleframes, hote, host.
- **I5.7 Registre d'avatars** (D27) :
  - clés `preset-01`..`preset-24` stables, jamais un chemin ;
  - fichiers WebP 256×256 d'au plus 20 Ko, lisibles à 32 px sur le thème sombre ;
  - `LICENSE.md` : source, auteur, licence CC0 revérifiée au téléchargement, URL, date, correspondance clé → fichier d'origine ;
  - changer de pack = changer les fichiers et les libellés, sans migration ;
  - `PlatformLimits::roomSeats() ≤ PlatformLimits::avatarPresets()` (garde-fou testé en C0).
- **I5.8 Présélection** (`suggest`), déterministe :
  1. `$preferred` s'il appartient au catalogue et n'est pas pris ;
  2. sinon la première clé libre dans l'ordre de `keys()` ;
  3. sinon (impossible sous I5.7) `$preferred` valide ou `keys()[0]`.

  Un doublon reste permis ; l'écran marque les avatars pris avec `common.avatar.picker.taken`.
- **I5.9 Accessibilité** (n° 34). À côté d'un pseudo affiché, l'image est décorative (`alt=""`). Ailleurs, `alt` = `t(altKey)`. Le libellé d'un prédéfini ne sert que dans le sélecteur.
- **I5.10 Masquage** (règle J2, forme J1, n° 74). Un siège masqué rend `nickname: null`, `masked: true` et `avatar.initials = AvatarRef::FALLBACK_INITIAL`, pour que les initiales ne trahissent pas le pseudo. Le client affiche un libellé neutre et un ordinal dérivé au rendu (`common.player.masked`, C15). Un masquage s'applique aussi à l'affichage gelé.
- **I5.11** (D12) : `users.name` n'est jamais recopié dans `player.nickname`, et en particulier pas au J1 ; `users.real_name` non plus (C14).

### 5. Provenance des valeurs

| Valeur | Provenance |
|---|---|
| 2 et 20 | `NicknameNormalizer::MIN_LENGTH` / `MAX_LENGTH` : constantes de schéma liées à `player.nickname`/`nickname_normalized string(20)` (10 § 7.1). Ce ne sont pas des réglages de jeu |
| 256 octets | `NicknameNormalizer::MAX_RAW_BYTES`, garde |
| Écritures admises | `ValidNickname::ALLOWED_PATTERN` (D26) : ASCII, U+00AA et U+00BA, U+00C0–U+00FF sauf × et ÷, U+0100–U+017F |
| Liste noire | Fichiers versionnés `resources/moderation/nicknames/*.txt` |
| Seuil de sous-chaîne, tables leet | Constantes d'algorithme de `NicknameBlocklist`, jamais en configuration (précédent `LEADING_ARTICLES`) |
| Nombre de prédéfinis | `PlatformLimits::avatarPresets()` (`game.platform.avatar_presets`, défaut 24) |
| URL d'un prédéfini | `AvatarRef::presetUrl()` (`avatars.preset_base`, `avatars.preset_extension`, défauts existants) |
| 256 px, 20 Ko | Exigence de fichier (00 l.204), vérifiée par test, jamais lue à l'exécution |

### 6. Exigences et amendements
- **À 10** : E10-32 (`nickname_normalized`), E10-35 (unicité, partis et expulsés compris), E10-67 (§ 15 l.1414).
- **Code** : `PlayerFactory::normalizeNickname()` délègue à `NicknameNormalizer::normalize()` ; `avatarPreset()` tire dans `AvatarPresetCatalog::keys()` (n° 29, D27) ; `PlayerFactory::NICKNAME_MAX_LENGTH` est supprimée au profit de `NicknameNormalizer::MAX_LENGTH` ; `UserFactory::withPresetAvatar()` tire aussi dans `AvatarPresetCatalog::keys()` au lieu de `sprintf('preset-%02d', …)`.
- **Amendements** : A-15, A-22 (00) ; A-37, A-39 (05) ; A-71, A-73, A-74 (CLAUDE.md).
- **Aux autres contrats** :
  - **70** expose `AnswerKeyNormalizer::fold(string $text): string` avec ces propriétés : public et statique, minuscules, translittération ASCII sans `ext-intl`, espaces compressés et bords coupés, aucun retrait d'article, aucune conversion de chiffres, aucune troncature, idempotent, total sur de l'UTF-8 valide (C12).
  - **50** : I5.4 et I5.5 ; le gel `display_*` au lancement est fait par `OpenGame` (C6 O6).
  - **60** : `SeatView` étend `PlayerIdentity` (C7, R-29).
  - **90** : les composants proposés `player-avatar.tsx` et `avatar-picker.tsx` entrent dans sa liste close ; il applique I5.9.
  - **100** : relevé des licences tierces (pack Kenney, sources des listes noires ; une source CC-BY impose une attribution) et un test de longueur de colonne `player.nickname` sur MySQL.

### 7. Tests Pest nommés
- `tests/Feature/Identity/NicknameValidationTest.php` :
  - « accepte les pseudos latins de 2 à 20 caractères avec chiffres, espaces, tirets et soulignés »
  - « compose un accent décomposé avant de le valider »
  - « rogne les extrémités et réduit les espaces intérieures dans le pseudo affiché »
  - « refuse un pseudo de moins de 2 ou de plus de 20 caractères »
  - « refuse cyrillique, grec, kana, kanji, hangul, lettres pleine chasse et signe micro avec le message d'écriture »
  - « accepte les indicateurs ordinaux ª et º, lettres latines du Latin-1 supplément »
  - « refuse les caractères sans chasse, de contrôle, combinants, symboles et émojis avec le message de caractères »
  - « refuse un pseudo fait seulement d'espaces, de tirets et de soulignés »
  - « refuse avec un message traduit un pseudo dont la forme repliée dépasse 20 caractères »
  - « ne rend qu'un message, dans l'ordre longueur, écriture, caractères, alphanumérique, longueur normalisée, liste noire »
- `tests/Feature/Identity/NicknameNormalizerTest.php` :
  - « replie casse, accents, espaces, tirets et soulignés en une seule forme normalisée »
  - « garde articles et chiffres dans la forme normalisée »
  - « rend une forme ASCII non vide pour chaque lettre latine admise »
- `tests/Feature/Identity/NicknameBlocklistTest.php` :
  - « livre une liste noire avec en-tête de source et de licence pour chaque locale activée et pour les noms réservés »
  - « refuse une entrée de chaque langue activée quelle que soit la locale de la requête »
  - « refuse les graphies leet, à lettres répétées et séparées d'une entrée longue »
  - « ne refuse une entrée courte que comme mot entier »
  - « refuse les noms réservés comme admin et curator »
  - « ne cite jamais le mot refusé dans le message »

  Les entrées offensantes sont lues dans les fichiers, jamais écrites en dur dans les tests.
- `tests/Feature/Identity/AvatarPresetTest.php` :
  - « déclare exactement autant de clés de prédéfini que PlatformLimits::avatarPresets() »
  - « livre chaque prédéfini en WebP de 256 px d'au plus 20 Ko »
  - « livre la licence du pack d'avatars à côté de ses fichiers »
  - « nomme chaque prédéfini dans chaque locale activée »
  - « suggère l'avatar préféré s'il est libre, sinon le premier libre dans l'ordre du catalogue »
  - « refuse une clé d'avatar hors du catalogue »
  - « résout chaque clé alt d'AvatarRef dans le domaine common »
  - La garde « jamais moins de prédéfinis que de sièges » vit dans `PlatformLimitsTest` (C0, R-04).
- `tests/Feature/Identity/PlayerIdentityTest.php` :
  - « sérialise l'identité d'un siège avec son public_id, son pseudo et son avatar seulement »
  - « ne laisse jamais fuiter le pseudo ni ses initiales d'un siège masqué »
- `tests/Feature/Schema/ModelSerializationTest.php` (ajout) : « ne sérialise jamais nickname_normalized ni kicked_at d'un siège »
- `tests/Feature/I18n/TranslationCoverageTest.php` : le test existant `carries every key built by an enumerable key constructor` s'étend à `AvatarPresetCatalog::labelKey()` et à `ValidNickname::MESSAGE_KEYS` (C15 § 7).
- Exigés de 50, `tests/Feature/Room/SeatTakingTest.php` :
  - « refuse un pseudo déjà pris dans le salon, sièges partis et expulsés compris »
  - « traduit une collision de pseudo qui atteint l'index unique au lieu de laisser fuiter une 1062 »
  - « ne revalide jamais le pseudo d'un siège repris »

### 8. Ce qui reste libre pour 40
- Le contenu et les sources des listes noires, sous licence compatible et tracée.
- Des entrées réservées supplémentaires.
- Le choix des 24 animaux, leurs libellés FR/EN et le nom exact du pack Kenney, sous CC0 revérifiée.
- La rédaction des sept messages.
- La valeur de `SUBSTRING_MIN_LENGTH` : 5 par défaut, ajustable avant implémentation avec exemples testés, jamais en configuration.
- L'ajout de variantes à `blocks()` : autorisé, le retrait non.
- Tout le J2 : masquage, bannissement, branche provider des sièges, pré-remplissage depuis le compte, texte du libellé neutre.
- Le libellé et la mise en page du sélecteur (90).

---

## C6 — Transaction de lancement, « Rejouer » et garde de drainage

### 1. Propriétaire, consommateurs, jalon
- **Propriétaire** : 50.
- **Consommateurs** :
  - 30 : garde et tirage appelés dans la transaction ;
  - 60 : matérialisation, programmation de la manche 1, solo via `OpenGame`, prédicat « partie en cours » ;
  - 70 : `validation_version` ;
  - 80 : `scoring_version` ; `FinalizeGame`, seul écrivain de `ended_at`, conditionne « Rejouer » ;
  - 90 : bandeau de maintenance et messages de refus ;
  - 100 : `deploy:drain`, hook, tests.
- **Jalon** : tout au J1. D1 place la production sur le VPS dès le J1, donc D31 et D32 y valent aussi.

### 2. Noms exacts
- `App\Actions\Room\LaunchGame` **[nouveau]** : `public function handle(Room $room, Player $requester): LaunchOutcome`.
- `App\Actions\Room\ReplayRoom` **[nouveau]** : `public function handle(Room $room, Player $requester): ?RoomRefusal`. `null` signifie « fait » ou « déjà fait ».
- `App\Actions\Game\OpenGame` **[nouveau]** : **seul code qui insère dans `game`**. Le solo de 60 (`StartSoloGame`) l'appelle aussi.
  ```php
  /** @param \Illuminate\Database\Eloquent\Collection<int, Player> $seats */
  public function handle(GameMode $mode, ?Room $room, RoomSettings $settings,
                         \Illuminate\Database\Eloquent\Collection $seats, CarbonImmutable $now): LaunchOutcome;
  ```
- `App\ValueObjects\Room\LaunchOutcome` **[nouveau]**, `final readonly` :
  - propriétés : `?Game $game`, `?RoomRefusal $refusal`, `array<string,int> $replace`, `?PoolReport $pool` (C2, R-11), `array<string,string> $changes` ;
  - constructeurs nommés : `launched(Game $game): self` et `refused(RoomRefusal $r, array $replace = [], ?PoolReport $pool = null, array $changes = []): self` ;
  - `isLaunched(): bool`.
- `App\Enums\RoomRefusal: string` **[nouveau]** :
  - cas `NotHost = 'not_host'`, `RoomArchived = 'room_archived'`, `NotInLobby = 'not_in_lobby'`, `SettingsOutdated = 'settings_outdated'`, `NotEnoughPlayers = 'not_enough_players'`, `Draining = 'draining'`, `PoolInsufficient = 'pool_insufficient'`, `GameNotEnded = 'game_not_ended'` ;
  - `messageKey(): string` renvoie `'common.maintenance.launch_blocked'` pour `Draining` (clé unique du refus de drainage, R-09) et `'room.refusal.'.$this->value` pour les autres cas.
- **Drapeau de drainage** : lu par `App\Support\Deploy\DeployDrain::isDraining()` (C18-bis, propriété de 100) **[remplace `App\Support\Operations\DrainFlag`, brouillon de 50 abandonné, R-08]**. 50 ne pose ni ne lève jamais le drapeau.
- `App\Policies\RoomPolicy` **[nouveau]**, annoncée par 00 l.324 :
  - `launch(?User $user, Room $room, ?Player $seat): bool`, `replay(...)`, `updateSettings(...)` et `advanceRound(?User $user, Room $room, ?Player $seat): bool` (geste « manche suivante » de C7 § 2.4) ;
  - vrai si et seulement si `$seat` appartient au salon et `room.host_player_id = $seat->id` ;
  - **aucune clause de rôle** : un admin n'a aucun geste sur un salon, et il n'y a pas de `Gate::before`.
- `App\Models\Game` **[modifié]** : ajouter `FROZEN_COLUMNS` et une garde `updating` qui lève si l'une de ces colonnes change. La liste :
  `mode`, `input_difficulty`, `rounds_count`, `frames_per_round`, `draw_seed`, `draw_pool_size`, `tier_grace_ms`, `preload_lead_ms`, `settings_version`, `settings_snapshot`, `scoring_version`, `validation_version`, `started_at`, `room_id`.
  - **`settings_snapshot` se compare par valeur décodée, jamais par `isDirty()` sur la chaîne brute.** Sous MySQL 8, une colonne `json` est relue sous forme normalisée (clés triées, séparateurs `": "` et `", "`) ; dès que l'objet est lu, `Model::save()` la réécrit par `RoomSettingsCast::set()` (`mergeAttributesFromCachedCasts()`), en JSON compact dans l'ordre de `FIELDS` : la chaîne diffère alors de l'originale et `isDirty('settings_snapshot')` devient vrai. Sans cette règle, la garde lèverait sur toute sauvegarde de `game` précédée d'une lecture du snapshot (`FinalizeGame`, maintenance de `rounds_completed`).
  - Mécanisme retenu : `App\Casts\RoomSettingsCast` [modifié] implémente `Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes` (`compare()` = `fromStorage()` des deux valeurs puis `RoomSettings::equals()`), ce qui rend `isDirty()` fondé sur la valeur pour les quatre colonnes porteuses. À défaut, la garde compare elle-même `RoomSettings::fromStorage(json_decode($this->getRawOriginal('settings_snapshot'), true), …)->equals($this->settings_snapshot)`.
  - Signalé à 10 et à 100 : l'identité octet pour octet que prouve `RoomSettingsCastTest` ne vaut que sous SQLite, où la colonne est du texte.
- **Routes** (`routes/game.php`) :
  - `room.launch` : `POST /r/{room}/launch` ;
  - `room.replay` : `POST /r/{room}/replay` ;
  - contrôleurs proposés : `App\Http\Controllers\Room\LaunchController::store` et `ReplayController::store`.
- **Clés** : `room.refusal.{not_host|room_archived|not_in_lobby|settings_outdated|not_enough_players|pool_insufficient|game_not_ended}`. Paramètres : `:min` pour `not_enough_players` ; `:playable` et `:required` pour `pool_insufficient`. Le refus de drainage emploie `common.maintenance.launch_blocked` (C15, C18-bis) ; `room.refusal.draining` **n'existe pas** (R-09).
- **TS** : `RoomRefusalCode` dans `resources/js/types/room-settings.ts`.
- **Appels vers 30 et 60** (noms figés par C2, C3, C7 ; R-15) :
  - 30 : `App\Support\Draw\PoolScope`, `PoolReporter` (`report()`), `PoolReport`, `SeededPrf` (`generateSeed()`), `GameDrawer` (`draw()`), `DrawResult` ;
  - 60 : `App\Actions\Game\MaterializeDraw` **[nouveau, nom proposé par 50 et adopté, propriété de 60]** : `public function handle(Game $game, DrawResult $result): void` ; et `App\Actions\Game\ScheduleRound::handle(Round $round, CarbonImmutable $startsAt): void` (C7).
- **Versions** : `game.scoring_version = App\Support\Scoring\ScoringRules::VERSION` (C13) et `game.validation_version = App\Support\Answers\AnswerRules::VERSION` (C12). Elles remplacent `GameFactory::SCORING_VERSION` et `VALIDATION_VERSION`, supprimées.

### 3. Séquence normative et charges utiles

**`LaunchGame`**, dans `DB::transaction` :

| Étape | Action |
|---|---|
| L1 | `Room::whereKey(id)->lockForUpdate()` : **premier verrou**, ordre imposé room → player → game → round → round_player. |
| L2 | `$now = Date::now()`, **pris après le verrou**, en millisecondes. |
| L3 | Si `host_player_id` ne désigne aucun siège non parti, lancer l'action de transfert d'hôte. Ensuite, si l'hôte ≠ `$requester`, refuser `not_host` (403). |
| L4 | Statut `archived` → `room_archived`. Statut `playing` → `not_in_lobby` : le contrôleur redirige vers `room.show` **sans erreur**, ce qui rend le double clic idempotent. |
| L5 | Si `settings_version ≠ RoomSettings::VERSION` : `normalize(raw, version, PoolQuery::publishedThemeIds())`, puis `WriteRoomSettings`, puis refus `settings_outdated` avec `changes`. **C'est la seule branche de refus qui écrit.** |
| L6 | `$seats` = sièges avec `connection_state ∈ {connected, disconnected}` (un expulsé est `left`, D15). Si le nombre de sièges `connected` est inférieur à `MIN_CONNECTED_PLAYERS_TO_LAUNCH` → `not_enough_players`. |
| L7 | `OpenGame(Multiplayer, $room, $room->settings, $seats, $now)`. Un refus est renvoyé tel quel. |
| L8 | `room.status = playing`, `launched_at ??= $now`, `last_activity_at = $now`. |
| L9 | **Après validation de la transaction** : diffusion au salon de `game.launched` (C7, R-14). |

**`OpenGame`** :

| Étape | Action |
|---|---|
| O0 | Assertions : transaction ouverte ; `(mode = solo) ⇔ room = null` ; `sourceVersion = VERSION` ; sièges non vides (exactement un en solo). |
| O1 | Si `DeployDrain::isDraining()` → `draining` (message `common.maintenance.launch_blocked`). |
| O2 | **Appel 30-A** : `$scope = PoolScope::forRoom($room, $settings, $now)` (en solo : `PoolScope::catalogue($settings->themeIds, $settings->framesPerRound)` après l'ajustement D19 fait par 60), puis `$report = PoolReporter::report($scope, $settings->roundsCount)`. Le compte est en œuvres. |
| O3 | Si `$report->blocked()` → `pool_insufficient` (`:playable` = `count`, `:required` = `roundsCount`) avec `$report`. Ce refus est aussi diffusé au salon après la transaction par `settings.changed` (`RoomSettingsState` recalculé, R-14). |
| O4 | `$seed = SeededPrf::generateSeed()`. |
| O5 | INSERT `game` (§ 5). |
| O6 | INSERT `game_player` pour chaque siège : `status = playing`, `first_round_number = 1`, `display_nickname` et `display_avatar_*` gelés depuis `player`. |
| O7 | **Appel 30-B** : `GameDrawer::draw($scope, $settings->roundsCount, new SeededPrf($seed))`, **sur le même `$scope`** et dans la même transaction. Il rend min(M + `drawSubstituteMargin()`, playable) œuvres × N paliers `{tier_index, frame_id, frame_level}`. |
| O8 | **Appel 60-C** : `MaterializeDraw::handle($game, $result)` : `round` + `round_tier`, dans la transaction, avec `serve_token` à NULL. |
| O9 | **Appel 60-D** : `ScheduleRound::handle($round1, $now->addMilliseconds(EngineConstants::launchCountdownMs()))` : `round.started_at` et frappe du jeton du palier 1 (C8). Les lignes `round_player` naissent à T₁, pas ici (R-16). Les jobs de frontière sont **enregistrés après validation de la transaction**. |

**`ReplayRoom`** :

| Étape | Action |
|---|---|
| R1 à R3 | Comme L1 à L3. |
| R4 | Statut `archived` → `room_archived`. Statut `lobby` → `null` (idempotent). |
| R5 | Dernière partie du salon (`game_room_started_idx`) : si `ended_at` est NULL → `game_not_ended`. |
| R6 | `DeployDrain::isDraining()` → `draining` (D32). |
| R7 | `room.status = lobby`, `last_activity_at = $now`. |
| R8 | Après validation : diffusion de `room.replayed`, qui porte `RoomSettingsState` recalculé, puisque la non-répétition a réduit le vivier (C7, R-14). |

**Sens de `RoomStatus::Playing`** : il couvre toute la période qui va du lancement au « Rejouer » de l'hôte, podium compris. Les réglages sont donc verrouillés jusque-là (00 l.106 et l.120).

**Réponses HTTP**, à destinataire unique donc résolues dans la langue de la requête :
- `not_host` : 403 ;
- autres refus : `back()->withErrors(['room' => __($refusal->messageKey(), $replace)])`, plus `settingsChanges` en flash pour `settings_outdated`.

Aucune charge (réponse ou diffusion) ne contient la graine, un film, `draw_pool_size`, `game.id` ou `room.id`.

### 4. Invariants et garanties
1. **Tout ou rien** : une seule transaction pour `game`, `game_player`, `round`, `round_tier`, la programmation de la manche 1 et `room.status`. Seule exception : le refus `settings_outdated`, qui valide la normalisation.
2. **Autorité relue sous verrou** : hôte, statut, drapeau et vivier. La policy HTTP ne suffit jamais.
3. **Même état pour la garde et le tirage** : même constructeur (C2), même `PoolScope`, même transaction. `draw_pool_size = count`, en œuvres (n° 23). Le compteur du lobby utilise la même requête et la même fenêtre.
4. **Aucun effet externe avant la validation de la transaction** : jobs et diffusions après validation. Aucun appel réseau pendant la transaction : ni TMDB (règle 6), ni Reverb.
5. **Au plus une partie à `ended_at` NULL par salon.** Un double clic crée une seule partie.
6. **Instant unique** : `started_at` = `$now` et, au premier lancement, `launched_at` = `last_activity_at` = `$now`. `timestamp(3)`, jamais une heure client.
7. **Colonnes figées de `game`** immuables après l'INSERT, garanties par la garde `updating`. `OpenGame` est le seul créateur de `game`, solo compris.
8. **Drapeau de drainage** (C18-bis) :
   - il bloque **exactement** `OpenGame` (multijoueur **et** solo) et `ReplayRoom` ;
   - il n'arrête ni une partie en cours, ni une écriture de réglages, ni une entrée dans un salon, ni une reprise après pause ;
   - il expire de lui-même par son TTL ;
   - comme il est lu dans la transaction, un lancement concurrent de sa pose est couvert par la règle des deux relevés de `deploy:drain` et par `deploy:guard` (C18-bis).

### 5. Provenance des colonnes écrites

| Colonne | Valeur | Source |
|---|---|---|
| `game.room_id` / `mode` / `status` | salon (NULL en solo) / appelant / `running` | — |
| `input_difficulty`, `rounds_count`, `frames_per_round` | snapshot | `room_settings` |
| `settings_snapshot` + `settings_version` | `room.settings` (version = `VERSION`) | `room_settings` |
| `tier_grace_ms` / `preload_lead_ms` | `tierGraceMs()` / `preloadLeadMs()` | `PlatformLimits` |
| `draw_seed` / `draw_pool_size` | `SeededPrf::generateSeed()` / `PoolReport::$count` | 30 |
| `scoring_version` / `validation_version` | `ScoringRules::VERSION` / `AnswerRules::VERSION` | constantes de version (80 / 70) |
| `started_at` | `$now` | horloge serveur |
| `round.duration_ms`, `round_tier.duration_ms`, `.points`, `.starts_at_offset_ms` | `roundDuration()×1000`, `tierDurations[i−1]×1000`, `tierPoints[i−1]`, `tierStartOffsetMs(i)` | snapshot |
| Nombre de lignes `round` | min(M + `drawSubstituteMargin()`, playable) | `PlatformLimits` + 30 |
| Seuil de 2 joueurs | `MIN_CONNECTED_PLAYERS_TO_LAUNCH` | `RoomSettingsBounds` |
| Décompte avant la manche 1 | `EngineConstants::launchCountdownMs()` | 60 |
| Borne et TTL du drapeau | `config/deploy.php` | 100 |

### 6. Exigences et amendements
- **À 10** (aucune colonne, aucun cas d'enum de schéma, aucun index) : E10-28, E10-30, E10-37, E10-41, E10-42.
- **Amendements** : A-11, A-14 (00) ; A-56 (questions-ouvertes) ; A-70 (CLAUDE.md § 2).
- **À 30** : le constructeur s'appelle dans une transaction ouverte, sans en ouvrir une ni rien verrouiller, et sans écrire. Il a une variante solo sans non-répétition. Il fournit le diagnostic de `PoolReport`.
- **À 60** : `MaterializeDraw` et `ScheduleRound` s'exécutent dans la transaction, jobs après validation. Le prédicat « partie en cours » (C17) est cohérent avec la garde de `OpenGame`. 60 nomme les événements et les canaux de `game.launched`, `settings.changed` et `room.replayed` (C7).
- **À 80** : `FinalizeGame` est le seul écrivain de `ended_at`. Le calcul lit `speedBonusMaxPercent(game.frames_per_round)`.
- **À 90** : le bandeau lit la prop partagée `maintenance` (C18-bis), jamais l'heure ni la phase.
- **À 100** : `deploy:drain`, `deploy:guard`, `deploy:release` et le hook seuls posent et lèvent le drapeau, et fixent `config/deploy.php`.

### 7. Tests Pest
- `tests/Feature/Room/LaunchGameTest.php` :
  - « crée une partie, une participation par siège tenu, min(M + marge, vivier) manches de N paliers, et passe le salon en playing »
  - « refuse un siège qui n'est pas l'hôte courant »
  - « ne crée qu'une partie sur un double clic et redirige le second sans erreur »
  - « refuse un salon archivé avec room_archived »
  - « refuse sous deux sièges connectés avec not_enough_players »
  - « rejoue la garde de vivier : vivier réduit depuis le lobby → pool_insufficient avec le rapport en données, aucune ligne écrite »
  - « normalise des réglages de version antérieure, rapporte les changements et refuse settings_outdated »
  - « refuse de lancer pendant le drainage avec le message de maintenance et accepte après expiration du drapeau »
  - « ne pose launched_at qu'au premier lancement »
  - « écrit draw_pool_size égal au nombre d'œuvres du vivier rejoué »
  - « annule toute la transaction si la matérialisation échoue »
  - « n'émet aucun job ni diffusion avant la validation de la transaction »
  - « ne renvoie ni graine, ni film, ni identifiant interne »
  - « n'appelle jamais TMDB »
- `tests/Feature/Room/ReplayRoomTest.php` :
  - « ramène le salon en lobby après le podium et déverrouille les réglages »
  - « refuse game_not_ended tant que la partie n'est pas figée »
  - « refuse de rejouer pendant le drainage »
  - « refuse un non-hôte »
  - « diffuse le vivier réduit par la non-répétition »
- `tests/Feature/Room/RoomPolicyTest.php` :
  - « refuse la manche suivante à un non-hôte »
- `tests/Concurrency/Room/LaunchConcurrencyTest.php` (groupe `locks-timing` par répertoire, R-03) :
  - « ne crée qu'une partie pour deux lancements concurrents »
  - « sérialise une écriture de réglages concurrente d'un lancement »
- `tests/Feature/Architecture/GameCreationBoundaryTest.php` :
  - « n'insère dans game que depuis OpenGame, fabriques exceptées »
  - « ne pose ni ne lève le drapeau de drainage hors des commandes de déploiement »
  - « lève si une colonne figée de game est modifiée »
- `tests/Feature/Architecture/GameFrozenColumnsMysqlTest.php`, groupe `mysql` au niveau du fichier (R-03) :
  - « relire settings_snapshot puis enregistrer game ne déclenche pas la garde des colonnes figées »
- Le refus de démarrer un solo pendant le drainage est prouvé une seule fois, par `tests/Feature/Game/SoloTest.php` (C7, R-04). Le cycle du drapeau (expiration, levée) est prouvé par les tests `Deploy` de C18-bis.

### 8. Libre pour le rédacteur de 50
- Les textes des refus.
- Le code HTTP exact des refus métier, autres que 403.
- Les noms des contrôleurs et le limiteur de `room.launch` et `room.replay`.
- La mise en scène du refus de vivier dans le lobby.
- Le nom de l'action de transfert d'hôte.
- Laissé aux specs voisines : la valeur de la borne de drainage (100), le décompte avant la manche 1 et le transport de l'état après lancement (60), le choix des réglages du solo sur la base de D19 (60).

---

## Préambule du groupe 60

> **Sources normatives appliquées** : décisions D14, D15, D16, D18, D19, D20, D29, D31, D32 et S2 ; résolutions des contradictions n° 1, 46, 47, 48, 49, 50, 51, 52, 53, 55, 56, 57, 59, 65, 67, 74 et 79 ; défauts retenus des questions écartées Q60-6 (aucun plafond global de parties au J1) et Q60-9 (entrée libre, triche à plusieurs sièges assumée).
> **Conventions** : **[existant]** désigne un nom déjà présent dans le dépôt et réutilisé tel quel. **[nouveau]** désigne un nom créé par cette feuille. **Aucun renommage** de code existant n'est proposé par 60.
> **Contrats consommés, cités par leur numéro et jamais redéfinis ici** : C4 (`player_token`), C5 (`PlayerIdentity`), C0 (`RoomSettings`, `PlatformLimits`), C6 (transaction de lancement), C2 et C3 (vivier, N jouable le plus proche, substitution), C9 (frame servable), C10 et C11 (soumission, QCM), C13 (`ScoreCalculator`, `Scoreboard`, `FinalizeGame`), C18 (groupes Pest) et C18-bis (drapeau de drainage, commandes `deploy:*`).
> **Hypothèse S2** : ce qui touche l'exécution (Reverb, Redis, workers) est écrit sous hypothèse root et marqué « à confirmer au relevé du VPS », à relever avant tout déploiement ; le test de charge complet (D33) précède la première partie du J1 (A-55).

---

## C7 — Canaux et contrat d'événements Reverb, resynchronisation

### 1. Propriétaire, consommateurs, jalon
- **Propriétaire** : 60.
- **Consommateurs** :
  - 50 : contenu en données des messages de lobby, pages de salon, prise de siège, expulsion, transfert d'hôte ;
  - 70 : écouteurs de fin de saisie, émission ciblée du QCM, middleware de siège actif, rattrapage avant jugement ;
  - 80 : révélation, classement, fin de partie ;
  - 90 : pages `game/*`, hooks, états à rendre, `GameAnnouncer` ;
  - 40 : garde `player` au-dessus du `player_token` ;
  - 100 : variables d'environnement, file `game`, montage Reverb, tests groupés.
- **Jalon** : J1 en entier (lobby, partie multijoueur, solo).
- **Reste au J2, sur le même transport** :
  - `nickname: null` pour un pseudo masqué (règle de 40-J2) ;
  - contenu Avancé de `settings.changed` (50).

### 2. Noms exacts

**2.1 Canaux** (aucun canal en solo : 10 § 7.10, barrière 2)

| Nom Echo | Nom Pusher | Classe d'autorisation | Nature |
|---|---|---|---|
| `room.{roomKey}` | `presence-room.{roomKey}` | `App\Broadcasting\RoomPresenceChannel` [nouveau] | Diffusion au salon (lobby et partie) |
| `seat.{publicId}` | `private-seat.{publicId}` | `App\Broadcasting\SeatPrivateChannel` [nouveau] | Envoi ciblé à un siège |

- **`roomKey`** vient de `App\Support\Realtime\ChannelNames::roomKey(Room $room): string` [nouveau]. Sa valeur est `substr(hash_hmac('sha256', 'tf:room-channel:'.$room->id, (string) config('app.key')), 0, 32)`.
  - Jamais `room_code`, recyclé à l'archivage (10 § 6.2), sans quoi un onglet resté abonné recevrait le salon suivant.
  - Jamais `room.id` en clair.
  - Aucune colonne.
- **Noms de canaux** : `ChannelNames::room(Room $room): string` rend `'room.'.roomKey`, et `ChannelNames::seat(Player $seat): string` rend `'seat.'.$seat->public_id` [nouveau].
- **`gameRef`** vient de `App\Support\Realtime\GameRef::for(Game $game): string` [nouveau]. Sa valeur est `substr(hash_hmac('sha256', 'tf:game-ref:'.$game->id, (string) config('app.key')), 0, 16)`. C'est l'identifiant public d'une partie, sans colonne, et il sépare les parties successives d'un même salon (« Rejouer »).

**2.2 Autorisation des invités (garde `player`)**

- `config/auth.php` reçoit le garde `'player' => ['driver' => 'player-token']` [nouveau]. `web` reste le garde par défaut, et Fortify ne voit jamais `player`.
- `AppServiceProvider::boot()` déclare `Auth::viaRequest('player-token', fn (Request $request): ?SeatPrincipal => …)`. Ce garde ne lit que `app(PlayerTokenManager::class)->current($request)?->hash()` (C4, R-30), jamais le cookie brut.
- `App\Support\Realtime\SeatPrincipal` [nouveau] est une `final class` qui implémente `Illuminate\Contracts\Auth\Authenticatable` :
  - `__construct(public readonly string $tokenHash)` ;
  - `bindSeat(Player $seat): void` et `boundSeat(): ?Player` ;
  - `getAuthIdentifierName(): string` rend `'public_id'` ;
  - `getAuthIdentifier(): ?string` et `getAuthIdentifierForBroadcasting(): ?string` rendent le `public_id` du siège lié, ou `null` tant qu'aucun siège n'est lié. **Jamais `tokenHash`** ;
  - `getAuthPasswordName()`, `getAuthPassword()`, `getRememberToken()` et `getRememberTokenName()` rendent `''` ; `setRememberToken($value): void` ne fait rien.
  - `Player` **n'implémente pas** `Authenticatable`.
- `routes/channels.php` [nouveau] est enregistré par `->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'throttle:game-read']])` dans `bootstrap/app.php` :
  ```php
  Broadcast::channel('room.{roomKey}', RoomPresenceChannel::class, ['guards' => ['player']]);
  Broadcast::channel('seat.{publicId}', SeatPrivateChannel::class, ['guards' => ['player']]);
  ```
- `RoomPresenceChannel::join(SeatPrincipal $principal, string $roomKey): array|false` accepte s'il existe un `player` `p` tel que :
  - `p.player_token_hash = $principal->tokenHash` ;
  - `p.room_id` est non nul ;
  - `room.archived_at` est nul ;
  - `p.kicked_at` est nul (D15) ;
  - `hash_equals(ChannelNames::roomKey(p.room), $roomKey)`.

  Elle appelle alors `bindSeat(p)` et rend `['publicId' => p.public_id]`. L'état de connexion est indifférent : la présence Reverb ne fait jamais foi.
- `SeatPrivateChannel::join(SeatPrincipal $principal, string $publicId): bool` exige `p.public_id = $publicId`, le même hash de jeton, `p.room_id` non nul, un salon non archivé et `p.kicked_at` nul.
- Point d'authentification : `/broadcasting/auth`, chemin fixe du framework et route sans nom. C'est le `authEndpoint` par défaut d'Echo, hors Wayfinder comme `/up`.
- **Membre de présence** : `user_id` = `player.public_id` et `user_info` = `{ "publicId": string }`, **rien d'autre**.

**2.3 Événements** [nouveaux : dossier `app/Events/`]

Deux bases abstraites portent le transport :
- `App\Events\Game\RoomBroadcast`, construite par `__construct(Room $room, ?Game $game, array $payload)`, avec `broadcastOn(): PresenceChannel` ;
- `App\Events\Game\SeatBroadcast`, construite par `__construct(Player $seat, ?Game $game, array $payload)`, avec `broadcastOn(): PrivateChannel`. Elle lève une `LogicException` si `$seat->room_id === null`.

Toutes deux :
- implémentent `ShouldBroadcastNow` et `ShouldDispatchAfterCommit` ;
- déclarent `final public function broadcastWith(): array`, qui rend l'enveloppe suivie de la charge ;
- déclarent `abstract public function broadcastAs(): string`.

La charge est **précalculée sous le verrou** par la transition. `serverNow` est pris **à l'émission**.

| `broadcastAs` | Classe `App\Events\Game\…` | Canal | Émetteur et instant | Charge, hors enveloppe |
|---|---|---|---|---|
| `seat.joined` | `SeatJoined` | salon | Prise de siège (50) ; admission d'un retardataire | `{ seat: SeatView }` |
| `seat.updated` | `SeatUpdated` | salon | Transition de `connection_state` (60), expulsion D15 (50), masquage (40-J2) | `{ seat: SeatView }` |
| `host.changed` | `HostChanged` | salon | Action de transfert (50) | `{ hostPublicId: string, previousHostPublicId: string\|null }` |
| `settings.changed` | `SettingsChanged` | salon | Écriture de réglages anti-rebondie (50, par `BroadcastLobbyState`, § 2.5) ; refus `pool_insufficient` au lancement (50, C6 O3) | `RoomSettingsState` de C0 : `{ settings: RoomSettingsView, warnings: RoomSettingsWarningCode[], pool: PoolReport }` (R-13) |
| `room.replayed` | `RoomReplayed` [ajouté, R-14] | salon | `ReplayRoom` (50), après commit | `RoomSettingsState` recalculé |
| `game.launched` | `GameLaunched` | salon | Transaction de lancement (50), après commit | `{ mode: 'multiplayer', roundsCount: int, framesPerRound: int, inputDifficulty: 'easy'\|'normal'\|'expert', revealDurationMs: int, speedBonus: bool, seats: SeatView[] }` |
| `room.archived` | `RoomArchived` | salon | Action d'archivage (50) | `{}` |
| `round.scheduled` | `RoundScheduled` | salon | `ScheduleRound` : manche 1 au lancement, manche k+1 par `RevealRound(k)` au début de la révélation de k, à `startsAt = reveal_ends_at(k)` (§ 4.14), reprise, remplacement, « manche suivante » | `{ round: RoundTimeline, image: TierImageRef }` (palier 1) |
| `tier.opened` | `TierOpened` | salon | `OpenTier` à `Tᵢ`, `i` = 1..N | `{ sequenceIndex: int, roundNumber: int, tierIndex: int, opensAt: IsoMs, next: TierImageRef\|null }` |
| `player.locked` | `PlayerLocked` | salon | `SeatInputClosed`, appelé par l'écouteur de 60 de l'événement de domaine `AnswerAccepted` de C10 (R-21) | `{ sequenceIndex: int, publicId: string, lockRank: int }` |
| `round.closed` | `RoundClosed` | salon | `CloseRound`, à `D` ou à la fin anticipée | `{ sequenceIndex: int, roundNumber: int, endedAt: IsoMs, revealStartsAt: IsoMs, revealEndsAt: IsoMs }` |
| `round.revealed` | `RoundRevealed` | salon | `RevealRound`, à `ended_at + tier_grace_ms` | `{ sequenceIndex: int, roundNumber: int, revealEndsAt: IsoMs, movie: RevealMovie, images: TierImageRef[], finders: RoundFinder[], leaderboard: Leaderboard }` (R-23, R-25) |
| `round.cancelled` | `RoundCancelled` | salon | `CancelRound` | `{ sequenceIndex: int, roundNumber: int }` — aucun motif, aucun titre |
| `game.paused` | `GamePaused` | salon | `EndReveal` sans aucun participant connecté | `{ pausedAt: IsoMs, interruptsAt: IsoMs }` |
| `game.resumed` | `GameResumed` | salon | Retour d'un siège pendant la pause | `{ resumedAt: IsoMs }`, suivi de `round.scheduled` |
| `game.ended` | `GameEnded` | salon | Écouteur de 60 de `GameFinalized` (C13), après le gel (fin normale ou interruption) | `{ podium: Podium }` (R-44 : issue, manches jouées et prévues sont dans `Podium`) |
| `seat.choices` | `SeatChoicesOffered` | **siège** | `OpenTier` au palier d'apparition du QCM (T₁ en Facile, T_N en Normal) | `{ sequenceIndex: int }` + `ChoicesPayload` de C11 : `{ choices: [string, string, string, string], useOriginalTitle: bool, lang: string\|null }` |
| `seat.superseded` | `SeatSuperseded` | **siège** | `ClaimSeatTab`, quand un jeton de siège actif précédent existait | `{}` : le client se resynchronise |
| `seat.kicked` | `SeatKicked` | **siège** | Expulsion (50), après commit | `{}` |

**Liste close pour le J1** : dix-neuf événements. Tout nouvel événement amende 60 et entre dans `EventPayloadTest`. Le J1 ne compte **aucun événement de résultat propre** (pas de `seat.locked`, R-21) : les points du joueur verrouillé passent par la réponse HTTP de 70, qui a un destinataire unique, et par `self.input.locked` à la resynchronisation.

**2.4 Routes** (`routes/game.php` [nouveau], `require`-é par `routes/web.php`)

`{room}` est le `room_code` résolu par `Room::resolveRouteBinding()` [existant]. Le préfixe de chemin suit la page de salon de 50 ; le préfixe retenu est `/r/{room}`. Seuls les noms de route font contrat.

| Nom | Méthode et chemin | Contrôleur et méthode (`App\Http\Controllers\Game\`) | Middleware | Réponse |
|---|---|---|---|---|
| `room.state` | GET `/r/{room}/state` | `RoomStateController@show` | `throttle:game-read` | JSON `GameStatePacket` |
| `room.heartbeat` | POST `/r/{room}/heartbeat` | `RoomHeartbeatController@store` | `throttle:game-write` | 204 |
| `room.round.next` | POST `/r/{room}/round/next` | `NextRoundController@store` | `seat.active`, `throttle:game-write`, policy `RoomPolicy::advanceRound` (50, C6), relue sous verrou dans `AdvanceToNextRound` | 204 ; 409 hors révélation |
| `solo.store` | POST `/solo` | `SoloGameController@store` (FormRequest `App\Http\Requests\Game\SoloStartRequest` : `preset` ∈ `SettingPresetKey`) | `throttle:game-write` | Redirection vers `solo.show` |
| `solo.show` | GET `/solo` | `SoloGameController@show` : `Inertia::render('game/solo', ['state' => …, 'seatToken' => …, 'settingsNotice' => …])` | — | Inertia |
| `solo.state` | GET `/solo/state` | `SoloStateController@show` | `throttle:game-read` | JSON `GameStatePacket` |
| `solo.heartbeat` | POST `/solo/heartbeat` | `SoloHeartbeatController@store` | `throttle:game-write` | 204 |
| `solo.reveal` | POST `/solo/round/reveal` | `SoloRoundController@reveal` (D18 « Voir la réponse ») | `seat.active`, `throttle:game-write` | JSON `GameStatePacket` |
| `solo.skip` | POST `/solo/round/skip` | `SoloRoundController@skip` (D18 « Passer la manche ») | idem | idem |
| `solo.next` | POST `/solo/round/next` | `SoloRoundController@next` (raccourcit R) | idem | idem |
| `clock.show` | GET `/clock` | `ClockController@show` | `throttle:game-read`, sans session ni cookie : même `->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class])` que `frame.serve` (C8 § 2) | `{ "serverNow": IsoMs }` |

- Les routes de soumission `round.answer.store` et `round.choice.store` appartiennent à C10 (même fichier `routes/game.php`, même middleware `seat.active`).
- **Middleware `seat.active`** : `App\Http\Middleware\EnsureActiveSeat` [nouveau], avec son alias dans `bootstrap/app.php`. Il résout le siège par le hash du jeton courant (C4 I4.9, R-30 : siège du salon `{room}`, siège solo, ou siège lié `{player:public_id}` qui doit être tenu par ce jeton et non expulsé), puis compare l'en-tête `X-Seat-Token` à `player.active_seat_token` et répond **409** `{ "code": "seat_superseded" }` en cas d'écart (R-43). Il met le siège résolu en mémoire sur la requête pour 70 (limiteur `answer`).
- **Limiteurs** [nouveaux, dans `FortifyServiceProvider::configureRateLimiting()`] : `game-read`, `game-write` et `frame-serve` (C8). Tous sont clés sur le hash du `player_token`, avec repli sur l'IP (cache, jamais une table de domaine). `answer` est à C10.

**2.5 Actions et services**

- **Consommés par d'autres specs, noms figés** :
  - `App\Actions\Game\MaterializeDraw::handle(Game $game, DrawResult $result): void` — appelé par 50 dans la transaction de lancement (C6 O8) et par `StartSoloGame` ; écrit `round` et `round_tier` (C3 § 3) [nom proposé par 50, adopté, R-15] ;
  - `App\Actions\Game\ScheduleRound::handle(Round $round, CarbonImmutable $startsAt): void` — appelé par 50 dans la transaction de lancement pour la manche 1 (C6 O9), et par `StartSoloGame` ; frappe le jeton du palier 1 (C8) ;
  - `App\Actions\Game\SeatInputClosed::handle(Round $lockedRound, RoundPlayer $roundPlayer, ?Guess $guess, CarbonImmutable $now): void` — appelé par les écouteurs de 60 des événements de domaine `AnswerAccepted` et `InputClosed` de C10, dans une transaction qui reprend `round FOR UPDATE` (R-21) ; émet `player.locked` si `$guess` n'est pas nul, **en multijoueur seulement** (en solo, aucune diffusion : § 4.12, et `SeatBroadcast` lève sans salon), et réévalue `Round::isEarlyEndReached()` ;
  - `App\Actions\Game\CatchUpGame::handle(Game $game, CarbonImmutable $now): void` — rattrapage synchrone, appelé par 70 avant tout jugement, par les resynchronisations et par `game:reschedule` ;
  - `App\Actions\Game\ClaimSeatTab::handle(Player $seat, ?string $presentedSeatToken): string` — appelé au rendu de toute page de salon (50/90) et de `game/solo` ;
  - `App\Support\Game\GameStateBuilder::build(?Game $game, Player $seat, CarbonImmutable $now, ?string $presentedSeatToken): array` — forme `GameStatePacket`. `$game` est nul hors partie (lobby, solo pas encore lancé) : le salon se lit alors par `$seat->room`, et `gameRef`, `status`, `round` et `podium` suivent les valeurs nulles ou vides déjà prévues au § 3 ;
  - `App\Support\Game\RoundClock::offsetMs(Round $round, CarbonImmutable $instant): int`, calculé par `intdiv((int) $instant->format('Uu') - (int) $round->started_at->format('Uu'), 1000)`, et `RoundClock::currentTierIndex(Round $round, CarbonImmutable $instant): ?int`. **Seule formule** de décalage, 70 compris (R-22) ;
  - `App\Support\Game\ReceptionInstant::of(Request $request): CarbonImmutable` [nom proposé par 70, adopté, R-22] : instant serveur capturé **une fois** à l'entrée de la requête, avant tout verrou ; la source (middleware ou horodatage de requête) est fixée par 60 avec 100 ;
  - `App\Support\I18n\DisplayTitleResolver` (C11) : **seul résolveur** de la chaîne de repli d'affichage de 05 ; `MovieTitleResolver::displayTitle()` n'existe pas (R-23) ;
  - `App\Support\Realtime\GameWire` (`const int VERSION = 1`, `envelope(?Game $game, CarbonImmutable $now): array`) et `App\Support\Realtime\WireTime::iso(CarbonImmutable $instant): string`, qui rend `$instant->utc()->format('Y-m-d\TH:i:s.v\Z')`.
- **Internes** : noms donnés au rédacteur, décomposition libre (§ 8) :
  - `App\Actions\Game\{OpenTier, MintTierServeToken (C8), CloseRound, RevealRound, EndReveal, CancelRound, PauseGame, ResumeGame, AdvanceToNextRound, StartSoloGame, RevealSoloAnswer, SkipSoloRound}` ;
  - les jobs `App\Jobs\Game\{AdvanceRound, InterruptPausedGame, SweepSeatPresence, WithdrawContentFromLiveRounds (C8)}`, tous en `onQueue('game')`, sans jamais de `release()` ;
  - **nom figé, consommé par 50** : le job [nouveau] `App\Jobs\Game\BroadcastLobbyState` (`__construct(public int $roomId)`, `onQueue('game')`, `ShouldBeUniqueUntilProcessing` par salon, `delay(PlatformLimits::lobbyBroadcastDebounceMs())`). 50 le dispatche après chaque écriture de réglages (C0 § 3.4). À l'exécution, il relit `RoomSettingsPresenter::state($room, now)` puis émet `SettingsChanged`, et ne fait rien si le salon n'est plus en `lobby`. C'est l'arbitrage de file demandé par C0 § 8 ;
  - l'enum hors schéma `App\Support\Game\RoundStep` (`OpenTier`, `Close`, `Reveal`, `EndReveal`) ;
  - les écouteurs de `AnswerAccepted`, `InputClosed` (C10) et `GameFinalized` (C13), idempotents, noms libres.
- **Réglages** : `App\Settings\EngineConstants` [nouveau, `final readonly`, construit depuis `config('game.engine.*')`], détaillé au § 5.

**2.6 Front** (kebab-case, tout [nouveau], sous `lib/game/` et `hooks/game/` pour entrer dans le périmètre `WATCHED` de C16, R-36)

- `resources/js/lib/game/echo.ts` : Echo instancié paresseusement côté client, `broadcaster: 'reverb'`, configuré **à l'exécution** depuis la prop partagée `realtime` et `window.location` (n° 79), jamais depuis `VITE_REVERB_*`.
- `resources/js/types/game-wire.ts` : types ci-dessous (dans `WATCHED`).
- `resources/js/lib/game/wire.ts` : `GAME_WIRE_VERSION = 1` et `parseIsoMs()`.
- `resources/js/lib/game/server-clock.ts` : poignée de main, avec `clockSamples` échantillons de `clock.show` et le décalage médian recalé sur chaque `serverNow`.
- Le palier courant et sa valeur (D29) sont calculés par `resources/js/lib/game/round-timeline.ts` de C16, seule implémentation ; aucun `round-clock.ts` n'est créé (R-35).
- `resources/js/lib/game/store.ts` : magasin externe idempotent sur (`gameRef`, `sequenceIndex`, `tierIndex`, événement).
- `resources/js/lib/game/frame-loader.ts` : voir C8.
- `resources/js/hooks/game/use-game-channel.ts`, `use-game-state.ts`, `use-round-clock.ts`, `use-heartbeat.ts` : tous en `useSyncExternalStore`, idempotents sous React Compiler et en mode strict.
- **Prop partagée `realtime`** : ajoutée dans `HandleInertiaRequests::share()` et augmentée dans `types/global.d.ts`, de forme `{ key: string; host: string | null; port: number | null; scheme: 'http' | 'https' | null; heartbeatIntervalMs: number; clockSamples: number }`. `null` signifie « prendre `window.location` ».

**2.7 Clés de traduction** (domaine `game`, FR et EN symétriques, textes rédigés par 60 sauf mention ; préfixes enregistrés en C15, R-38)

- [existante] `game.frame.alt` (`:index`, `:total`).
- [nouvelles] :
  - `game.round.tier_value` (`:points`, D29 ; forme figée par C15, texte par 80) ;
  - `game.round.time_up`, `game.round.cancelled` ;
  - `game.round.lone_player` (un seul joueur connecté en multijoueur, **jamais** « solo », n° 65) [brouillon de 60 : `game.lone_player.notice`] ;
  - `game.pause.interrupts_at` (`:time`, formaté par le client) ;
  - `game.seat.superseded`, `game.seat.kicked` ;
  - `game.host.next_round` ;
  - `game.solo.reveal_answer`, `game.solo.skip_round`, `game.solo.next_round`, `game.solo.no_room_memory` ;
  - `game.solo.frames_adjusted` (`:preset`, `:requested`, `:applied`, D19) ;
  - `game.errors.seat_superseded`, `game.errors.not_revealing`, `game.errors.pool_too_small`.
- [brouillon, abandonnées] `game.connection.{lost,resyncing}` : le bandeau de connexion est `ConnectionBanner` de C16, sur `common.connection.*` ; `game.errors.draining` : le refus de drainage emploie `common.maintenance.launch_blocked` (R-09).

### 3. Formes de données

**Enveloppe**, présente dans chaque événement et en tête du paquet de resynchronisation :

```ts
type IsoMs = string; // 'YYYY-MM-DDTHH:mm:ss.sssZ', UTC, millisecondes (05 : instants ISO-8601 UTC)
interface WireEnvelope { v: 1; serverNow: IsoMs; gameRef: string | null } // gameRef null : événement de lobby hors partie
```

- Durées et décalages : **entiers en ms**.
- Instants : `IsoMs`.
- Instant absolu d'un palier : `startsAt + startsAtOffsetMs`.

**Types partagés** (`resources/js/types/game-wire.ts` ; les types d'autres contrats sont importés, jamais redéclarés, R-27) :

```ts
import type { AvatarData, PlayerIdentity } from '@/types/player';          // C5
import type { TierWindow, RoundFinder, Leaderboard, Podium } from '@/types/scoring'; // C13
import type { InputState, ChoicesPayload, SeatInputView } from '@/types/answers';    // C10, C11
import type { RoomSettingsState } from '@/types/room-settings';             // C0

interface SeatView extends PlayerIdentity {   // PlayerIdentity::toArray() (C5) + état de siège (R-29)
  isHost: boolean; connection: 'connected' | 'disconnected' | 'left'; kicked: boolean; firstRoundNumber: number | null }
  // En partie : pseudo et avatar GELÉS (PlayerIdentity::fromGamePlayer, display_*) ; au lobby : PlayerIdentity::fromSeat. nickname null = masqué ou effacé.
interface RoundTimeline { sequenceIndex: number; roundNumber: number; roundsCount: number; startsAt: IsoMs;
  durationMs: number; tiers: TierWindow[]; choicesAtTierIndex: number | null } // tiers : { tierIndex, startsAtOffsetMs, durationMs, points } = round_tier (D29) ; choicesAtTierIndex = InputDifficulty::choicesOpenTierIndex(N) [existant]
interface TierImageRef { tierIndex: number; url: string; fetchNotBefore: IsoMs } // url = ServeUrl (C8) ; fetchNotBefore = Tᵢ − preload_lead_ms
type LocaleCode = 'fr' | 'en';                 // Locale::cases()
interface RevealTitle { text: string; lang: string } // R-24 : lang = Locale::bcp47() de la locale atteinte (rangs 1-2) ; au rang 3, original_language, suffixé '-Latn' si la translittération est servie
interface RevealMovie { titles: Record<LocaleCode, RevealTitle>; originalTitle: string; originalTitleLatin: string | null; originalLanguage: string; year: number | null }
  // titles = DisplayTitleResolver::resolve() pour CHAQUE Locale::cases() ; originalTitle = movie.title_original ; originalLanguage = movie.original_language ; year = movie.release_year
  // RevealMovie est aussi le « paquet de titres » du récapitulatif de C13 (TitlePacket = RevealMovie).
// InputState, ChoicesPayload (C10, C11) ; TierWindow, RoundFinder, Leaderboard, Podium (C13) : importés, jamais redéfinis ici.
// RoomSettingsState (C0) porte RoomSettingsView et PoolReport (C2).
```

**Paquet de resynchronisation.** C'est à la fois la prop initiale `state` de toute page de jeu et la réponse de `room.state` et de `solo.state`. Il sort d'un seul constructeur, `GameStateBuilder` :

```ts
interface GameStatePacket extends WireEnvelope {
  mode: 'multiplayer' | 'solo';
  channels: { room: string; seat: string } | null;         // null en solo et sans partie
  status: 'running' | 'paused' | 'completed' | 'interrupted' | null;
  roundsCount: number | null; roundsCompleted: number | null; framesPerRound: number | null;
  inputDifficulty: 'easy' | 'normal' | 'expert' | null;
  seats: SeatView[];                                        // solo : [soi]
  pause: { pausedAt: IsoMs; interruptsAt: IsoMs } | null;
  round: RoundState | null;                                 // en cours (running|revealing), sinon programmée (pending avec startsAt), sinon null
  self: SelfState;
  leaderboard: Leaderboard;                                 // C13, portée Publishable : GELÉ à la dernière manche révélée (n° 67), jamais les points d'autrui de la manche en cours
  podium: Podium | null;                                    // si completed|interrupted
  nextTransitionAt: IsoMs | null;                           // prochaine transition programmée OU prochaine garde de palier (Tᵢ − preload_lead_ms) : cadence de sondage du solo, filet du multijoueur (R-34)
}
interface RoundState extends RoundTimeline {
  phase: 'scheduled' | 'running' | 'closed' | 'revealing' | 'cancelled'; // closed = [endedAt, revealStartsAt) ; phase dérivée : en base, round.status reste running (R-20)
  currentTierIndex: number | null;
  images: TierImageRef[];            // au plus UNE URL par palier dont la garde est franchie ; en révélation : les paliers OUVERTS jusqu'à revealEndsAt (D14)
  locked: { publicId: string; lockRank: number }[];
  endedAt: IsoMs | null; revealStartsAt: IsoMs | null; revealEndsAt: IsoMs | null;
  reveal: { movie: RevealMovie; finders: RoundFinder[] } | null; // non nul seulement si serverNow ≥ revealStartsAt
}
interface SelfState {                         // R-26
  publicId: string; seatActive: boolean;      // X-Seat-Token présenté === player.active_seat_token
  isHost: boolean; member: boolean;           // member = game_player éligible pour la manche courante (voir C8)
  participates: boolean;                      // une ligne round_player existe
  input: SeatInputView | null;                // C10 : { inputState, attemptsLeft, choices, locked } ; null ssi !participates.
                                              // choices rejoué si choices_composed_at non nul ; sinon composé si l'instant est franchi ET acceptsChoice()
  ownScore: number;                           // C13 Scoreboard::seatScore(), portée Own, manche en cours comprise
}
```

**Exemple** : `tier.opened` au palier 2 d'une manche à N = 3, réglages par défaut.

```json
{ "v": 1, "serverNow": "2026-09-23T14:05:13.004Z", "gameRef": "3f9a0c1d2e4b5a69",
  "sequenceIndex": 4, "roundNumber": 4, "tierIndex": 2, "opensAt": "2026-09-23T14:05:13.000Z",
  "next": { "tierIndex": 3, "url": "/f/9c1e…d2?expires=1790172346&signature=…", "fetchNotBefore": "2026-09-23T14:05:21.000Z" } }
```

`expires` suit la formule de C8 § 3 : `started_at` = 14:05:03.000Z (palier 2 ouvert à 14:05:13.000Z, dᵢ = 10 s), donc `(started_at + D + tier_grace_ms + R) + serveUrlExpiryMarginMs` = 14:05:41.300Z + 5 s = 14:05:46.300Z, soit 1790172346 après la troncature à la seconde de `URL::temporarySignedRoute()`.

**Diffusé ou ciblé.** Tout ce qui figure au tableau 2.3 avec le canal « salon » est diffusé. `seat.choices`, `seat.superseded` et `seat.kicked` sont ciblés. Le paquet de resynchronisation est une réponse HTTP à destinataire unique.

**Jamais dans une charge, qu'elle soit diffusée, ciblée ou de resynchronisation (règle 3, 10 § 1.1)** :
- avant `revealStartsAt` : ni `movie_id`, ni année, ni titre ni alias du film de la manche **hors des quatre chaînes du QCM** (`seat.choices`, `SelfState.input.choices`). Dans ces chaînes, la cible ne porte ni index, ni drapeau, ni position conventionnelle, ni métadonnée par proposition, et l'ordre est permuté par siège (05 l.179, C11) ;
- à aucun moment : `round.id`, `game.id`, `room.id`, `player.id`, `frame.id`, `frame_level`, `served_frame_id`, `game_path`, `draw_seed`, `draw_pool_size`, `choice_1` en position identifiable, `input_state` d'autrui, `active_seat_token` (sauf la prop `seatToken` de l'onglet qui vient de le frapper) ;
- avant la révélation : les points et le `tier_index` d'autrui ;
- la durée **du film** (principe 2 ; `D`, `dᵢ` et les points de palier sont des réglages publics et transitent) ;
- toute phrase formatée côté serveur, à l'unique exception des quatre chaînes du QCM et des titres de la révélation, qui sont des données (05).

### 4. Invariants et garanties
1. **Le serveur est autoritaire.** Le client affiche la chronologie reçue, recalée par le décalage d'horloge. La poignée de main n'est qu'un confort (L3). Aucun minuteur client ne décide (n° 74) : la valeur de palier D29 est un simple affichage.
2. **`startsAt` d'une manche** ne change que tant que `round.status = pending`. `round.scheduled` peut alors être réémis pour le même `sequenceIndex`, et le dernier reçu, au `serverNow` le plus grand, l'emporte. Dès `T₁`, `started_at` est immuable.
3. **Exclusion mutuelle.** Toute transition de manche, la transaction de verrouillage de 70 et `SeatInputClosed` s'exécutent sous `SELECT … FROM round WHERE id = ? FOR UPDATE` ; ordre de verrouillage room → player → game → round → round_player. Redis ne porte aucun verrou de cohérence et n'est jamais source de vérité du temps.
4. **Émission après commit** (`ShouldDispatchAfterCommit`). Une transaction annulée n'émet rien.
5. **Idempotence** (n° 56). Les écritures de transition sont idempotentes et **toujours exécutées** ; le rattrapage applique toutes les étapes échues **dans l'ordre chronologique**. « Toujours exécutées » ne vise que les étapes **encore valides** : une étape `OpenTier(i)` est **périmée** (ni `served_at`, ni `seen_frame`, ni frappe du palier i+1, ni composition du QCM, ni diffusion) si `round.status ∉ {pending, running}`, ou si `round.ended_at` est non nul avec `started_at + starts_at_offset_ms(i) ≥ ended_at` (C8 § 4.3). Seule la diffusion se périme : après rattrapage, on n'émet que l'état courant. Un job réveillé tôt (troncature de `availableAt()` à la seconde) **attend en processus**, au plus `transitionMaxWaitMs`, et **ne se relâche jamais** (`--tries=1`).
6. **Instants de clôture** (R-20).
   - Clôture à `D` : `ended_at = started_at + D`, instant théorique.
   - Fin anticipée : `ended_at` = instant serveur de l'événement déclencheur.
   - `reveal_ends_at = ended_at + tier_grace_ms + R`.
   - `CloseRound` écrit `ended_at` et émet `round.closed` à `ended_at`, mais **`round.status` reste `running`** ; `RevealRound` passe `round.status = revealing` et émet `round.revealed` à `ended_at + tier_grace_ms`, sous verrou.
   - La fenêtre d'acceptation de C10 est `round.status = running` ET `receivedAt < (round.ended_at ?? round.started_at + D) + tier_grace_ms` : aucune soumission reçue après l'émission des titres n'est acceptable (n° 59).
7. **Fin anticipée.** Le prédicat est `Round::isEarlyEndReached()` [existant], amendé par D20 (`text_exhausted` n'est **pas** clos). Il est réévalué par `SeatInputClosed` après chaque verrouillage ou clôture de saisie (écouteurs de `AnswerAccepted` et `InputClosed`, R-21), après chaque transition de présence et après chaque expulsion. Comme chaque écriture qui clôt une saisie est suivie, après son commit, d'une réévaluation sous le verrou `round`, le dernier à clore voit toujours l'état complet.
8. **QCM** (D20, n° 57).
   - À l'ouverture du palier `InputDifficulty::choicesOpenTierIndex(N)`, soit T₁ en Facile et T_N en Normal (jamais en Expert), `OpenTier` compose ou rejoue via `ComposeChoiceSets` (C11) et, **en multijoueur seulement**, émet `seat.choices` à **chaque** `round_player` de la manche dont `input_state->acceptsChoice()` est vrai (`open` **et** `text_exhausted`, R-28) et dont le siège n'est ni parti ni expulsé. En solo, le QCM n'est livré que par `solo.state` (`SelfState.input.choices`), sans aucune diffusion (§ 4.12).
   - **Ordre à T₁** : `OpenTier(1)` crée d'abord les lignes `round_player` (E10-49), puis compose le QCM (Facile), puis émet après commit. Dans l'ordre inverse, aucun siège ne recevrait `choices_locale` ni `seat.choices`.
   - Un siège parti puis revenu obtient le QCM par la resynchronisation.
   - Les mêmes quatre chaînes sont rejouées en toute circonstance (05).
9. **Second onglet.**
   - Toute visite de page de salon ou de `game/solo` qui **ne présente pas** le jeton de siège actif (`X-Seat-Token`) frappe un nouveau `active_seat_token` (ULID, 10 § 7.1). Elle émet alors `seat.superseded` si un jeton précédent existait, et le passe en prop `seatToken`, **jamais** dans `state`.
   - Le jeton est gardé **en mémoire** par l'onglet, jamais dans localStorage.
   - Toute écriture le présente.
10. **Présence.**
    - Seuls les battements HTTP écrivent `player.last_seen_at`, par Eloquent (10 § 1.2).
    - `connected → disconnected` après `disconnectAfterMs` sans battement ; `disconnected → left` après `disconnectGraceSeconds`.
    - Chaque transition émet `seat.updated`. La présence Reverb ne fait jamais foi.
11. **Pause** (10 § 7.4).
    - Elle n'intervient qu'entre deux manches (fin de révélation sans participant connecté).
    - La manche suivante déjà programmée est **déprogrammée** (`started_at` remis à NULL), donc son palier 1 n'est plus servi.
    - La reprise la reprogramme à `now + launchCountdownMs`.
12. **Solo.**
    - Même moteur, mêmes jobs, mêmes écritures, **zéro diffusion** (aucune instance de `RoomBroadcast` ni de `SeatBroadcast`), ni `seen_frame`.
    - Le client tire `solo.state` au montage, à chaque `nextTransitionAt`, à chaque `fetchNotBefore` et après chaque geste.
    - D18 :
      - « Voir la réponse » pose `revealed`. La fin anticipée clôt la manche, puis la révélation normale de durée `R` suit, à 0 point.
      - « Passer la manche » pose `skipped`. La manche passe directement à `completed` avec `reveal_ends_at = ended_at` ; la suivante est programmée à `now + preload_lead_ms + nextRoundMarginMs` ; le titre reste au récapitulatif.
      - Aucun des deux gestes ne crée de `guess`, et tous deux sont refusés hors solo (10 § 7.10).
    - D19 : le N jouable le plus proche (`PoolReporter::nearestPlayableFramesPerRound(PoolScope::catalogue($themeIds, N), M)`, C2) s'applique d'office, les champs dérivés sont redérivés selon la règle Simple (C0 § 3.3, D34), et la page reçoit la prop `settingsNotice: { preset, requestedFramesPerRound, appliedFramesPerRound } | null`.
    - Au plus une partie solo en cours par siège ; la partie naît par `OpenGame(Solo, null, …)` (C6), donc le drainage la refuse (C18-bis).
13. **Résidus assumés**, écrits dans 60 :
    - la triche à plusieurs sièges (garantie « essai unique » **par siège** seulement, n° 51 et Q60-9) ;
    - un expulsé resté abonné continue de recevoir les diffusions, aucune n'apprenant la réponse avant la révélation. Le protocole Pusher n'a pas de désinscription côté serveur, et le client coopératif quitte les canaux sur `seat.kicked` ;
    - une URL de palier transmise avant sa garde (`round.scheduled`, `tier.opened.next`) est inutilisable avant `fetchNotBefore`, le serveur la refusant (C8) : c'est le jeton frappé au palier précédent (n° 47), pas une fuite (R-34).
14. **Enchaînement des manches.**
    - `RevealRound(k)` appelle `ScheduleRound(k+1, reveal_ends_at(k))` s'il reste une manche à jouer : `T₁(k+1) = reveal_ends_at(k)`. Le palier 1 de k+1 devient donc servable dans les `preload_lead_ms` finales de R, et lui seul (C0 § 2, garde-fou `MIN_REVEAL_DURATION × 1000 > MAX_PRELOAD_LEAD_MS`).
    - `EndReveal(k)` s'exécute à `reveal_ends_at(k)` et **précède, à instant égal**, `OpenTier(k+1, 1)`, dans le job comme dans le rattrapage. S'il ne trouve aucun participant connecté, il déprogramme k+1 (`started_at` remis à NULL, § 4.11) et pose la pause. Après la dernière manche, il appelle `FinalizeGame` (C13).
    - La formule `maxNaturalDurationMs()` de C17 suppose cet enchaînement sans intervalle.

### 5. Provenance des valeurs (règle 2 : aucune valeur de jeu en dur)

| Valeur | Source |
|---|---|
| `N`, `tierDurations`, `tierPoints`, `R`, `inputDifficulty`, `speedBonus`, `disconnectGraceSeconds`, `M` | `RoomSettings` figé dans `game.settings_snapshot` (C0), matérialisé dans `round_tier` (offsets, durées, points) |
| `tier_grace_ms`, `preload_lead_ms` | Colonnes de `game`, figées au lancement depuis `PlatformLimits` [existant] |
| Décompte de lancement (remplace `P`, n° 48) | `EngineConstants::launchCountdownMs()`, clé `game.engine.launch_countdown_ms`, défaut 5 000 |
| Marge de « manche suivante » et de « passer » | `nextRoundMarginMs`, clé `game.engine.next_round_margin_ms`, défaut 1 000 |
| Battement | `heartbeatIntervalMs`, clé `game.engine.heartbeat_interval_ms`, défaut 10 000 |
| Seuil de déconnexion | `disconnectAfterMs`, clé `game.engine.disconnect_after_ms`, défaut 25 000 |
| Clôture après pause (00 l.135) | `pauseTimeoutMs`, clé `game.engine.pause_timeout_ms`, défaut 900 000 |
| Attente en processus d'un job réveillé tôt | `transitionMaxWaitMs`, clé `game.engine.transition_max_wait_ms`, défaut 1 000 |
| Échantillons d'horloge | `clockSamples`, clé `game.engine.clock_samples`, défaut 3 |
| Débits `game-read` / `game-write` | `gameReadsPerMinute` / `gameWritesPerMinute`, clés `game.engine.*`, défaut 30 / 30 |
| Marge d'expiration d'URL, débit `frame-serve` | C8 |
| `roomKey`, `gameRef` | HMAC de `APP_KEY` (contextes `tf:room-channel:`, `tf:game-ref:`) |
| `v` | `GameWire::VERSION` (constante de version), miroir de `GAME_WIRE_VERSION` en TS |
| Clé publique Reverb, hôte, port et schéma client | Prop `realtime`, lue depuis la config Reverb au runtime (n° 79) |

- `EngineConstants` n'est **ni** une limite de confort (neutralité de plan), **ni** une règle de score. Aucune de ses valeurs n'entre dans `scoring_version`.
- `config/game.php` [nouveau] porte les sections `platform` (C0) et `engine` (60). Le premier lot qui crée le fichier pose les deux.

**Exigences d'exécution adressées à 100** (hypothèse S2, « à confirmer au relevé du VPS ») :
- Reverb en écoute sur `127.0.0.1`, publié par nginx ;
- connexion de file `game` sur Redis via predis (n° 46), avec `block_for` nul ou ≤ 1 s, `--sleep` ≤ 1 s et `--tries=1`, servie par **un** processus `tripleframes-worker@game` (socle D1 et D30 : deux workers systemd, `game` et `default`). Le nombre de processus `game` est un paramètre de 100, fixé après le test de charge D33 et **signalé au porteur** s'il dépasse un, car il modifie le socle de D1 (unités, `MemoryMax` sur un VPS partagé) ;
- `composer dev` complété de `php artisan reverb:start` et de `queue:listen --queue=game,default` ;
- dans `.env.example` : `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` (vide), `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`, et **à la place de `VITE_REVERB_*`** les variables optionnelles `REVERB_CLIENT_HOST`, `REVERB_CLIENT_PORT` et `REVERB_CLIENT_SCHEME` (noms à confirmer par 100) ;
- `REDIS_CLIENT=predis`.

### 6. Exigences et amendements
- **À 10** : E10-01, E10-06, E10-31, E10-46, E10-49, E10-53, E10-65, E10-67.
- **Amendements** : A-01, A-03, A-04, A-05, A-17, A-20, A-21, A-27, A-29 (00) ; A-44, A-45 (05) ; A-53, A-54 (questions-ouvertes) ; A-70, A-71, A-75, A-76 (CLAUDE.md).
- **Aux specs sœurs** :
  - **70** : `RoundPlayerInputState::acceptsChoice()` (C10) sert de prédicat d'envoi ; appeler `CatchUpGame` avant tout jugement ; émettre `AnswerAccepted` et `InputClosed` après commit (écoutés par 60) ; clore la fenêtre d'acceptation selon § 4.6 ; appliquer `seat.active` ; calculer `answered_at_ms` par `RoundClock::offsetMs()` sur `ReceptionInstant::of()`.
  - **80** : un classement « à la dernière manche révélée », hors manche `running` et hors manches annulées (n° 67) ; `RoundFinder`, `Leaderboard`, `Podium` ; `GameFinalized` après commit, qui déclenche `game.ended`.
  - **50** : émettre `seat.joined`, `seat.updated`, `host.changed`, `settings.changed`, `room.replayed`, `game.launched`, `room.archived` et `seat.kicked` avec les charges ci-dessus ; appeler `MaterializeDraw` et `ScheduleRound` dans la transaction de lancement ; appeler `ClaimSeatTab` au rendu des pages de salon.
  - **40** : aucune fonction nouvelle : `PlayerTokenManager::current($request)?->hash()` suffit (R-30).
  - **90** : les pages `game/*` consomment `state`, `seatToken`, `realtime` et `settingsNotice`.

### 7. Tests Pest nommés
- **`tests/Feature/Game/ChannelAuthorizationTest.php`**
  - « un invité porteur du player_token d'un siège du salon rejoint le canal de présence »
  - « le membre de présence ne porte que le public_id du siège, jamais le hash du jeton »
  - « un jeton sans siège dans ce salon est refusé sur le canal de présence »
  - « le canal privé d'un siège refuse le jeton d'un autre siège »
  - « un siège expulsé est refusé sur les deux canaux jusqu'à l'archivage » (couvre l'exigence de 40)
  - « un salon archivé refuse toute souscription »
  - « deux salons successifs portant le même room_code ont deux clés de canal différentes »
- **`tests/Feature/Game/EventPayloadTest.php`**
  - « chaque événement porte v, serverNow au format ISO-8601 UTC à la milliseconde et gameRef »
  - « aucune charge d'événement ni aucun paquet ne contient de clé d'identifiant interne »
  - « aucune charge émise avant revealStartsAt ne contient un titre, un alias ou une forme normalisée du film de la manche hors des quatre propositions du QCM »
  - « la position de la cible parmi les quatre propositions suit la permutation qcmOrder du siège »
  - « player.locked ne porte que sequenceIndex, publicId et lockRank » (couvre le test de C10, dédoublonné)
  - « seat.choices part sur le canal privé du siège et jamais sur le canal du salon »
  - « en Facile le QCM est poussé à T₁, en Normal à T_N, en Expert jamais »
  - « à T_N en Normal, le QCM est poussé au siège dont le texte libre est épuisé »
  - « la clôture est émise à ended_at et les titres à ended_at + tier_grace_ms »
  - « une transaction annulée n'émet aucun événement »
  - « round.scheduled est réémis pour une manche pending reprogrammée et jamais après T₁ »
  - « chaque titre de la révélation porte l'attribut lang de la locale atteinte »
  - « la liste des événements diffusés est exactement la liste close du J1 »
- **`tests/Feature/Game/ResyncPacketTest.php`**
  - « une resynchronisation à t = 1 s d'une manche à N=5 ne renvoie qu'une URL » (nom de 10 § 15, conservé)
  - « dans la fenêtre de préchargement, le paquet porte au plus une URL par palier dont la garde est franchie »
  - « pendant la révélation, le paquet porte les URL des seuls paliers ouverts, puis aucune après reveal_ends_at »
  - « nextTransitionAt annonce la prochaine garde de palier »
  - « une resynchronisation en cours de manche ne modifie aucun score d'autrui »
  - « le QCM déjà composé est rejoué à l'identique après un changement de langue »
  - « l'onglet supplanté reçoit seatActive faux »
- **`tests/Feature/Game/SeatTakeoverTest.php`**
  - « un chargement complet frappe un nouveau jeton de siège et émet seat.superseded »
  - « une visite présentant le jeton actif ne re-frappe pas »
  - « une écriture présentant un jeton supplanté est refusée en 409 »
- **`tests/Concurrency/Game/EarlyEndHookTest.php`** (groupe `locks-timing` par répertoire, R-03)
  - « le crochet de fin de saisie émet player.locked et clôt la manche quand tous les participants ont leur saisie close »
  - « un siège en text_exhausted empêche la fin anticipée » (D20)
  - « la révélation inclut toute réponse acceptée avant ended_at + tier_grace_ms »
  - « deux derniers verrouillages concurrents clôturent la manche une seule fois »
- **`tests/Feature/Game/SoloTest.php`**
  - « voir la réponse clôt en revealed et ouvre une révélation de durée R sans guess »
  - « passer la manche clôt en skipped et programme la suivante sans révélation »
  - « revealed et skipped sont refusés hors solo »
  - « une partie solo n'émet aucun événement de diffusion »
  - « un preset au N injouable est ramené au N jouable le plus proche et la page l'annonce »
  - « un second lancement solo sous le même jeton reprend le siège »
  - « refuse de démarrer une partie solo pendant un drainage, sans partie créée » (seule preuve de ce refus, R-04)
- **`tests/Feature/Game/PauseLifecycleTest.php`**
  - « sans participant connecté en fin de révélation, la partie passe en pause et déprogramme la manche suivante »
  - « la pause programme l'interruption à paused_at + pauseTimeoutMs »
  - « le retour d'un siège reprend la partie et reprogramme la manche après le décompte »
- **`tests/Feature/Game/EngineConstantsTest.php`**
  - « le décompte de lancement couvre MAX_PRELOAD_LEAD_MS plus la marge »
  - « le seuil de déconnexion vaut au moins deux battements »
  - « MIN_REVEAL_DURATION en ms dépasse MAX_PRELOAD_LEAD_MS »
  - « transitionMaxWaitMs couvre la troncature à la seconde »
- **`tests/Feature/Game/WireVersionTest.php`**
  - « GAME_WIRE_VERSION côté TS égale GameWire::VERSION »

### 8. Laissé au rédacteur de 60
- La décomposition interne des actions, des jobs et des écouteurs. Seuls sont figés les noms consommés par d'autres specs (§ 2.5) et les écrivains uniques de C8.
- La stratégie de balayage de présence (job par salon ou par siège), à condition de passer par la file `game`, sans ordonnanceur à la minute.
- Les valeurs par défaut de `EngineConstants`, à ajuster après le test de charge (D33) sans changer les noms.
- L'ordre d'affichage côté client, les états visuels et les annonces `aria-live`, qui relèvent de 90.
- Le texte des clés.
- La politique de nouvelle tentative de `frame-loader` après un 404 dû au décalage d'horloge.

---

## C8 — Frappe du `serve_token` et route `GET /f/{serveToken}`

### 1. Propriétaire, consommateurs, jalon
- **Propriétaire** : 60.
- **Consommateurs** :
  - 10 : amendements de § 4.1, § 7.4 et § 10 ;
  - 20 : gestes de suspension et de retrait qui déclenchent l'annulation active ; `FrameImageResponse` partagé (C9) ;
  - 90 : conteneur d'image, cadre fixe de D7 ;
  - 30 : choisisseur de substitution ;
  - 100 : en-têtes testés, `robots.txt` avec `Disallow: /f/`.
- **Jalon** : J1 pour la frappe, la route et le prédicat. L'annulation active (`WithdrawContentFromLiveRounds`, cas `movie_suspended`) arrive au **J2**, avec les gestes de suspension et de retrait de 20 qui seuls la déclenchent (R-33). L'aperçu admin est au J1 (C9-bis).

### 2. Noms exacts
- **Route** : `frame.serve`, `GET /f/{serveToken}` avec `->where('serveToken', '[0-9a-f]{32}')`, servie par `App\Http\Controllers\Game\FrameServeController@show(Request $request, string $serveToken): Symfony\Component\HttpFoundation\Response` [nouveau].
  - Middleware : `signed:relative` et `throttle:frame-serve`, avec `->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, AddQueuedCookiesToResponse::class])`, donc ni session ni `Set-Cookie` ; `EncryptCookies` reste dans la pile (C4).
    - `PreventRequestForgery` doit sortir : sur un GET, il pose le cookie `XSRF-TOKEN` en lisant `$request->session()->token()`, et `Request::session()` lève sans session (le framework le retire lui-même de `/broadcasting/auth`). `AddQueuedCookiesToResponse` doit sortir : `SetLocale`, dans le groupe `web`, met en file le cookie `locale` quand il négocie la langue.
    - Solution équivalente admise : sortir `frame.serve` et `clock.show` du groupe `web` avec une pile explicite (`EncryptCookies`, `SubstituteBindings`, `throttle`), à condition que le `player_token` reste lisible.
  - **Nom du paramètre** : `{serveToken}` est le paramètre de route (signature du contrôleur) ; `serve_token` ne désigne que la colonne `round_tier.serve_token`. La prose de 00, 10 et CLAUDE.md qui écrit `/f/{serve_token}` désigne cette même route.
  - L'URL est **produite par le serveur** et n'est jamais reconstruite par Wayfinder.
- **Prédicat de service** : `App\Support\Game\ServeGuard` [nouveau, `final readonly`] expose :
  - `allows(RoundTier $tier, ?string $requesterTokenHash, CarbonImmutable $now): bool` ;
  - ses trois parties, publiques pour les tests : `catalogueAllows(RoundTier $tier): bool`, `timeAllows(RoundTier $tier, CarbonImmutable $now): bool`, `membershipAllows(RoundTier $tier, ?string $requesterTokenHash): bool`.

  **Seul prédicat**, appelé par la route et par `GameStateBuilder` pour choisir les URL du paquet. `$requesterTokenHash` = `PlayerTokenManager::current($request)?->hash()` (C4, R-30).
- **URL** : `App\Support\Game\ServeUrl::for(RoundTier $tier): string` [nouveau] appelle `URL::temporarySignedRoute('frame.serve', $expiresAt, ['serveToken' => $tier->serve_token], absolute: false)`.
- **Réponse en octets** : `App\Support\Frames\FrameImageResponse::make(FrameStoragePrefix::Game, $servedFrame->game_path)` (C9) **[remplace `FrameBytesResponse::stream()`, brouillon de 60, R-31]** : constructeur unique des réponses d'image, partagé avec l'aperçu admin de 20.
- **Écrivains uniques** [nouveaux] :
  - `App\Actions\Game\MintTierServeToken::handle(RoundTier $tier, CarbonImmutable $now): void` écrit `serve_token`, `served_frame_id` et `substitution_reason` ;
  - `App\Actions\Game\OpenTier::handle(RoundTier $tier, CarbonImmutable $now): void` écrit `served_at` et fait l'upsert de `seen_frame`.
- **Annulation active** (J2, R-33) : le job `App\Jobs\Game\WithdrawContentFromLiveRounds` [nouveau, `onQueue('game')`] est construit par `__construct(public readonly ?int $movieId, public readonly ?int $frameId, public readonly RoundIncidentReason $reason)`.
- **Enum** : `RoundIncidentReason` [existant : `frame_unavailable`, `no_variant_available`, `movie_withdrawn`] reçoit le cas [nouveau, J2] `MovieSuspended = 'movie_suspended'` (n° 52). Colonne `string(30)`, sans migration.
- **Réutilisés** [existants] : `Frame::isServable()`, `RoundTier::isOpenForServing()`, `RoundTier::servingOpensAt()`, `FrameStoragePrefix::Game->owns()` et `FrameStoragePrefix::DISK`.
- **Aperçu admin** : routes distinctes de 20, `admin.catalog.frames.game` et `admin.catalog.frames.master` (C9-bis, R-32). Elles ne sont **jamais** adressées par `serve_token` et ne partagent jamais l'espace `/f/`. `/f/` ne sert **jamais** `master/`.
- **Front** : `resources/js/lib/game/frame-loader.ts` [nouveau] (sous `lib/game/`, R-36).
  - Il charge par `fetch(url, { credentials: 'same-origin', cache: 'no-store' })`, jamais avant `fetchNotBefore`.
  - Il produit un blob, une URL d'objet, puis appelle `decode()` avant l'échange d'image, et révoque l'URL d'objet au changement de manche.
  - L'URL d'objet est le `src` de `GameFrame` (C16, R-37). Le rendu (cadre 16:9 fixe D7, blocage de `contextmenu` et `dragstart`, `alt = game.frame.alt`) relève de 90.

### 3. Formes de données
- **Aucune charge JSON.** Le client ne reçoit que `TierImageRef` (C7). Le jeton est opaque (`char(32)`, `bin2hex(random_bytes(16))`) et **lié à une manche**, pas à une frame.
- **Réponse 200** (produite par `FrameImageResponse`, C9) :
  - corps = octets de `servedFrame.game_path` sur le disque `frames` ;
  - `Content-Type: image/webp` ;
  - `Content-Length` = taille du fichier, qui vaut `frame.game_bytes`, un multiple de 8 192 ;
  - `Cache-Control: no-store, private` ;
  - `X-Robots-Tag: noindex, nofollow` ;
  - `X-Content-Type-Options: nosniff` ;
  - `Cross-Origin-Resource-Policy: same-origin`.
- **Absents, garantis** : `Content-Disposition`, `Last-Modified`, `ETag`, `Accept-Ranges`, `Expires`, `Set-Cookie`. La réponse n'est jamais un `BinaryFileResponse`, ni `Storage::response()`.
- **Refus du prédicat, jeton inconnu ou fichier absent** : **404, corps vide**, mêmes en-têtes `Cache-Control` et `X-Robots-Tag`, **réponse identique quelle que soit la cause**. Un fichier absent est en plus journalisé en avertissement par `FrameImageResponse`, sans pseudo ni IP (canal fixé par 100).
- **Signature invalide ou expirée** : 403 (middleware `signed`). **Débit dépassé** : 429.
- **Expiration de la signature** : `(round.reveal_ends_at ?? started_at + duration_ms + tier_grace_ms + R) + serveUrlExpiryMarginMs`, recalculée à chaque émission. **L'URL n'est jamais stockée.**

### 4. Invariants et garanties
1. **Instant de frappe** (n° 47).
   - Le jeton du palier 1 est frappé dans `ScheduleRound`, dans la transaction qui écrit `started_at`.
   - Le jeton du palier `i ≥ 2` est frappé dans `OpenTier(i−1)`.
   - La frappe est idempotente : si `serve_token` n'est pas NULL, rien n'est écrit.
   - Le jeton n'est jamais régénéré, et il est UNIQUE (`round_tier_serve_token_uq` [existant]).
2. **Substitution décidée à la frappe** (n° 47). La frappe retient `frame_id` si `Frame::isServable()` est vrai **et** si le fichier existe sur le disque `frames`.
   - Sinon, elle prend la variante de `VariantChooser::substitute()` (C3 : même `frame_level`, servable, fichier présent, PRF déterministe), avec `substitution_reason = frame_unavailable`.
   - Sinon, elle appelle `CancelRound(no_variant_available)` et ne frappe aucun jeton.
   - `served_frame_id` et `substitution_reason` s'écrivent **une seule fois**.
3. **À `Tᵢ`**, `OpenTier` :
   - ne fait **rien** si l'étape est périmée : `round.status ∉ {pending, running}`, ou `round.ended_at` non nul avec `started_at + starts_at_offset_ms(i) ≥ ended_at` (fin anticipée, annulation). Ni `served_at`, ni `seen_frame`, ni frappe du palier i+1, ni composition du QCM, ni diffusion : un palier jamais ouvert n'est jamais marqué servi (10 § 7.9, prédicat D14). « Toujours exécutées » (C7 § 4.5) ne vise que les étapes valides ;
   - revérifie `servedFrame->isServable()`, et si c'est faux appelle `CancelRound(frame_unavailable)` (jamais de seconde substitution) ;
   - écrit `served_at` = **instant théorique** `started_at + starts_at_offset_ms`, même si le job est en retard ;
   - fait l'upsert de `seen_frame(room_id, served_frame_id, last_seen_at = served_at)` en multijoueur seulement (10 § 7.9).
4. **Prédicat en trois parties**, évalué à **chaque** service (10 § 4.1, amendé par D14 et n° 47).
   - (1) **Catalogue** : `servedFrame` est non nul, `isServable()` est vrai et `FrameStoragePrefix::Game->owns(game_path)`.
   - (2) **Temps** :
     - si `round.status` ∈ {`pending`, `running`} : `game.status = running`, `started_at` non nul **et** `isOpenForServing($now, $game->preload_lead_ms)`, palier 1 compris, **sans exception pour la manche 1** (n° 48) ;
     - si `revealing` : `served_at` non nul **et** `$now < reveal_ends_at` (D14 : jamais un palier non ouvert, même dans la fenêtre de préchargement) ;
     - si `completed` ou `cancelled` : refus.
   - (3) **Appartenance**, par `game_player` (n° 47, R-30). Il faut un `requesterTokenHash` non nul **et** l'existence d'une ligne `game_player gp` jointe à `player p` telle que :
     - `gp.game_id = round.game_id` et `p.player_token_hash = :hash` ;
     - `p.kicked_at` est nul et `gp.status <> 'kicked'` ;
     - `gp.first_round_number` est NULL ou `≤ round.round_number`.

     Un retardataire en attente est donc refusé sur la manche en cours.
5. **La route est en lecture seule** : aucune écriture, aucune transition, aucun rattrapage (10 § 7.4). Coût : environ 5 lectures par clé, sans index nouveau.
6. **Suspension ou retrait admin** (n° 52, J2). Le geste de 20 dispatche `WithdrawContentFromLiveRounds` **après commit**, et le job agit comme suit.
   - Film suspendu ou retiré : toute manche `running`, ou `pending` programmée, de ce film est annulée (`movie_suspended` ou `movie_withdrawn`) puis remplacée par le film de réserve suivant (`ReplacementRoundChooser`, C3).
   - Frame seule : la manche est annulée (`frame_unavailable`) si la frame est la `served_frame` d'un palier déjà frappé ; sinon, la prochaine frappe substitue.
   - Pendant une révélation, la partie (1) du prédicat refuse les URL. Le paquet déjà émis n'est pas rappelé, et une resynchronisation omet ces URL.
   - La dépublication de curation (J1 : dépublication d'un film, re-recadrage d'une frame publiée, C9), elle, reste **paresseuse** : aucun dispatch.
7. **Anti-corrélation sur quatre surfaces** : l'URL (jeton par manche), la taille (quantification à 8 Ko), les en-têtes et les dimensions naturelles fixes 1280×720 (C9).
8. **Résidus nommés dans 60** :
   - chaque image est visible `preload_lead_ms` avant son palier (10 § 10) ;
   - **les octets servis sont identiques d'une manche à l'autre**, donc un hachage du blob est un identifiant de **contenu** réutilisable, appris en solo : risque assumé (D16). La barrière 4 devient « aucun identifiant **d'adressage** réutilisable » ;
   - une image préchargée avant une mise en pause reste connue du client.

   Le repli d'un client lent est le cadre fixe de D7, **jamais** un élargissement de fenêtre ni un décalage du chrono.

### 5. Provenance des valeurs

| Valeur | Source |
|---|---|
| `preload_lead_ms`, `tier_grace_ms` | Colonnes de `game`, depuis `PlatformLimits` [existant] |
| `R` | `game.settings_snapshot->revealDuration` |
| Marge d'expiration | `EngineConstants::serveUrlExpiryMarginMs()`, clé `game.engine.serve_url_expiry_margin_ms`, défaut 5 000 |
| Débit `frame-serve` | `EngineConstants::frameServePerMinute()`, clé `game.engine.frame_serve_per_minute`, défaut 60, par hash de jeton |
| Longueur du jeton | `bin2hex(random_bytes(16))`, soit 32 caractères hexadécimaux (10 § 7.4) |
| Plafonds de sortie et quantification | `FrameGeometry` (C9), auquel délègue `FrameStoragePrefix::Game` |

### 6. Exigences et amendements
- **À 10** : E10-08, E10-20, E10-47, E10-58, E10-59, E10-60, E10-66.
- **Amendements** : A-28 (00) ; A-52 (questions-ouvertes) ; A-76 (CLAUDE.md § 8).
- **Aux specs sœurs** :
  - **20** : dispatcher `WithdrawContentFromLiveRounds` après commit de toute transition vers `suspended` ou `withdrawn` (J2) ; servir l'aperçu par `FrameImageResponse` (C9-bis).
  - **30** : le choisisseur de substitution exclut toute frame dont le fichier est absent (C3).
  - **100** : `robots.txt` avec `Disallow: /f/`.

### 7. Tests Pest nommés
- **`tests/Feature/Game/FrameServeTest.php`**
  - « le palier i est refusé à Tᵢ − preload_lead_ms − 1 ms »
  - « le palier i est servi à Tᵢ − preload_lead_ms + 1 ms »
  - « la garde relit preload_lead_ms depuis game et non depuis la configuration »
  - « le palier 1 de la manche 1 n'a aucune fenêtre d'exception »
  - « un palier jamais ouvert n'est pas servi pendant la révélation d'une manche close par fin anticipée »
  - « les paliers ouverts restent servis jusqu'à reveal_ends_at puis sont refusés »
  - « le palier 1 d'une manche déprogrammée par une pause n'est plus servi »
  - « un retardataire admis à la manche suivante est refusé sur la manche en cours »
  - « un siège expulsé est refusé »
  - « une frame devenue non servable est refusée à chaque service »
  - « la route ne sert jamais un chemin hors du préfixe game/ »
  - « tous les refus du prédicat rendent la même réponse 404 vide »
  - « une signature invalide ou expirée est refusée »
  - « la réponse porte no-store, noindex, nosniff et same-origin, sans Content-Disposition, Last-Modified, ETag, Accept-Ranges ni Set-Cookie »
  - « répond sans session et sans aucun Set-Cookie, même quand la langue est négociée »
  - « Content-Length vaut game_bytes, multiple de 8 192 »
  - « la route n'exécute aucune écriture »
- **`tests/Feature/Game/ServeTokenMintTest.php`**
  - « le jeton du palier 1 est frappé à la programmation, celui du palier i à l'ouverture du palier i−1 »
  - « la frappe est idempotente »
  - « une frame non servable ou absente du disque est substituée à la frappe avec frame_unavailable »
  - « sans variante servable, la frappe annule la manche avec no_variant_available »
  - « served_at vaut l'instant théorique même quand le job est en retard »
  - « après N requêtes d'image anticipées et aucune frontière franchie, served_at est nul et seen_frame est vide » (10 § 7.4, conservé)
  - « deux manches distinctes portant la même frame produisent deux serve_token différents » (10 § 7.10, conservé)
  - « seen_frame est écrit sur served_frame_id à l'ouverture, jamais à la frappe, jamais en solo »
  - « un palier dont l'ouverture suit une fin anticipée ou une annulation n'écrit ni served_at ni seen_frame »
- **`tests/Feature/Game/LiveWithdrawalTest.php`** (J2)
  - « suspendre le film d'une manche en cours l'annule avec movie_suspended et programme un remplaçant »
  - « suspendre une frame déjà frappée annule la manche avec frame_unavailable »
- **`tests/Feature/Architecture/TierServingWritersTest.php`**
  - « seules MintTierServeToken et OpenTier écrivent serve_token, served_frame_id, substitution_reason, served_at et seen_frame »

### 8. Laissé au rédacteur de 60
- L'implémentation du flux (dans `FrameImageResponse`, avec 20), à condition que les en-têtes soient exacts.
- La politique de nouvelle tentative de `frame-loader`.
- Le détail de la journalisation d'un fichier absent.
- La valeur par défaut de `frameServePerMinute`, recalibrable après D33.

---

## Préambule du groupe 20

Ce que ce groupe applique, sans le rediscuter :
- **Décisions :** D5 (16:9), D6 (plancher de recadrage), D7 (pas de LQIP), D8 (le back-office suit l'apparence choisie), D12 (nom réel), D13 (rétroactivité déclarée par version), D16 (résidu de l'empreinte des octets), D4 et D1.
- **Résolutions de contradictions :** n° 0, 1, 4, 5, 6, 12, 15, 16 et 18.

Conventions : champs postés en *snake_case* dans le back-office, comme `ImportDiscoverRequest` et `AdminCatalogPresenter`. `PlatformLimits::toArray()` garde son *camelCase*. Les exigences de 20 (E1 à E14 dans sa rédaction) sont reportées dans les listes consolidées (E10-xx, A-xx), avec leur étiquette d'origine.

---

## C9 — Frame servable (ratio, master, dérivé, plancher, traitement, empreintes)

### 1. Propriétaire, consommateurs, jalon
- **Propriétaire :** 20.
- **Consommateurs :**
  - 60 : service `GET /f/{serveToken}`, préchargement, révélation D14 ;
  - 90 : cadre d'image fixe D7, aperçu admin, prop partagée `frameFormat` ;
  - 100 : sondes, CI Imagick, sauvegarde du tier froid ;
  - 10 : schéma.
- **Jalon :**
  - J1 : voie TMDB, job, plancher, aperçu admin ;
  - voie capture : route et refus serveur au J1 ; la branche qui accepte un fichier reste **hors J1** (D11, 00 l.408).

### 2. Noms exacts

**PHP, nouveaux :**

`App\Support\Frames\FrameGeometry` [nouveau], classe `final` de constantes de format. Ces constantes ne sont **jamais surchargeables** par configuration : changer l'une d'elles impose de recadrer toute la banque.

```php
final class FrameGeometry
{
    public const int ASPECT_WIDTH = 16;
    public const int ASPECT_HEIGHT = 9;
    public const int GAME_WIDTH = 1280;
    public const int GAME_HEIGHT = 720;
    public const int MASTER_WIDTH = 1920;          // largeur EXACTE du master (voir invariant 2)
    public const int GAME_MAX_BYTES = 153_600;     // 150 Ko, plafond de 10 § 4.1
    public const int GAME_PAD_BYTES = 8_192;       // quantum de padding de 10 § 4.1

    public static function gameEncodeCeilingBytes(): int;                      // intdiv(GAME_MAX_BYTES, GAME_PAD_BYTES) * GAME_PAD_BYTES = 147 456
    public static function paddedLength(int $encodedBytes): int;               // multiple de GAME_PAD_BYTES supérieur ou égal
    public static function masterHeightFor(int $sourceWidth, int $sourceHeight): int; // intdiv($sourceHeight * MASTER_WIDTH + intdiv($sourceWidth, 2), $sourceWidth)
    public static function maxCropWidth(int $masterHeight, PlatformLimits $limits): int; // min(plancher D6, plus grand 16:9 qui tient en hauteur), multiple de 16
    public static function defaultCrop(int $masterHeight, PlatformLimits $limits): CropRect; // plus grand cadre admis, centré
    public static function violation(CropRect $crop, int $masterHeight, PlatformLimits $limits): ?CropViolation;
}
```

Autres classes nouvelles :
- `App\Support\Frames\CropRect` [nouveau, `final readonly`] :
  - propriétés `int $x`, `int $y`, `int $width`, `int $height` ;
  - `static fromFrame(Frame $frame): self` ;
  - `static fromArray(array $data): self` ;
  - `toArray(): array{x: int, y: int, width: int, height: int}`.
- `App\Support\Frames\CropViolation` [nouveau, enum string, pas un cast de schéma] : `aspect`, `too_wide`, `too_narrow`, `out_of_bounds`.
- `App\Support\Frames\WebpPadding` [nouveau] :
  - `static pad(string $webp): string` ;
  - ajoute des octets NUL **après** le bloc RIFF, sans toucher au champ de taille RIFF (10 § 4.1) ;
  - refuse un en-tête qui n'est pas `RIFF…WEBP`.
- `App\Support\Frames\FrameImageProcessor` [nouveau] :
  - `process(Frame $frame): void` ;
  - lève `App\Support\Frames\FrameProcessingException` [nouveau], qui porte une `FrameProcessingFailure`.
- `App\Support\Frames\FrameImageResponse` [nouveau] :
  - `static make(FrameStoragePrefix $prefix, string $relativePath): Symfony\Component\HttpFoundation\Response` ;
  - constructeur **unique** des réponses d'image, partagé avec la route `/f/{serveToken}` de 60 (R-31) ;
  - 200 : flux des octets, en-têtes exacts (§ 3, identiques pour `/f/` et l'aperçu) ;
  - 404 corps vide, mêmes `Cache-Control` et `X-Robots-Tag`, si `! $prefix->owns($relativePath)` ou si le fichier manque ; un fichier manquant est journalisé en avertissement (canal fixé par 100), sans pseudo ni IP.

Enum de schéma :
- `App\Enums\FrameProcessingFailure` [nouveau, cast de `frame.processing_error`, `string(120)` existante] ;
- la **valeur de chaque cas est la clé de traduction complète**, puisque 10 § 4.1 exige que la colonne porte une clé ;
- méthode `isRetryable(): bool`.

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

Job, actions et politique :
- `App\Jobs\Curation\ProcessFrameImage` [nouveau] :
  - `implements ShouldQueue, ShouldBeUnique` ;
  - `__construct(public int $frameId)` ; `uniqueId(): string` = l'identifiant de la frame ;
  - `onQueue('default')`, **jamais** `game` ; distribué `->afterCommit()`.
- `App\Actions\Curation\AddFrame` [nouveau] :
  - `fromTmdb(Movie $movie, User $curator, FrameLevel $level, CropRect $crop, string $tmdbFilePath, string $originalBytes, ?int $cropSeconds): Frame` ;
  - `fromCapture(Movie $movie, User $curator, FrameLevel $level, CropRect $crop, UploadedFile $source, int $timecodeMs, ?int $cropSeconds): Frame`.
- `App\Actions\Curation\RecropFrame::handle(Frame $frame, User $curator, CropRect $crop, ?string $reason, ?int $cropSeconds): void` [nouveau].
- `App\Actions\Curation\RetryFrameProcessing::handle(Frame $frame, User $curator): void` [nouveau].
- `App\Policies\FramePolicy` [nouveau] :
  - `view(User $user, Frame $frame): bool` ;
  - `create(User $user, Movie $movie): bool` ;
  - `createFromCapture(User $user, Movie $movie): Response` ;
  - `update(User $user, Frame $frame): bool` ;
  - `applyRetroactiveGrid(User $user): bool` (voir C14-bis).

Contrôleurs et requêtes [nouveaux] :
- `App\Http\Controllers\Admin\FrameTmdbController@store` et `FrameCaptureController@store` ;
- `FrameCropController@update` et `FrameRetryController@store` ;
- `App\Http\Requests\Admin\FrameTmdbStoreRequest`, `FrameCaptureStoreRequest`, `FrameCropUpdateRequest`.

Ajouts à l'existant :
- `App\Support\Tmdb\TmdbClient::downloadImage(string $filePath): string` [ajout] :
  - récupère les octets `original` ;
  - taille plafonnée par `catalog.curation.tmdb_original_max_kilobytes`.
- `PlatformLimits` (C0, R-07) : accesseurs `frameCropMaxWidthPercent()` (80, bornes [70, 90]) et `frameCropMinWidthPx()` (640, multiple de 16 dans [320, 1280]), constantes `DEFAULT_FRAME_CROP_MAX_WIDTH_PERCENT` et `DEFAULT_FRAME_CROP_MIN_WIDTH_PX`, clés `game.platform.frame_crop_max_width_percent` et `game.platform.frame_crop_min_width_px`, garde de bornes au constructeur. **Pas** de clé dans `toArray()`.
- `FrameStoragePrefix::maxPixels()` et `::maxKilobytes()` [existants] **délèguent** désormais à `FrameGeometry`, pour qu'il n'existe qu'une seule source.
- `Frame::casts()` : `'processing_error' => FrameProcessingFailure::class` [ajout].

Routes :
- Groupe `routes/admin.php` existant. D8 lui **retire** le middleware `admin.appearance` (`ForceAdminAppearance` est supprimé, C16).
- Toutes ces routes sont sous `->scopeBindings()`, avec `{movie}` et `{frame}` liés par `id` (E10-11).

| Méthode | URI | Nom | Garde |
|---|---|---|---|
| POST | `admin/catalog/{movie}/frames/tmdb` | `admin.catalog.frames.tmdb.store` | `can:create,App\Models\Frame,movie`, `throttle:admin-frame` |
| POST | `admin/catalog/{movie}/frames/capture` | `admin.catalog.frames.capture.store` | `can:createFromCapture,App\Models\Frame,movie`, `throttle:admin-frame` |
| PATCH | `admin/catalog/{movie}/frames/{frame}/crop` | `admin.catalog.frames.crop.update` | `can:update,frame`, `throttle:admin-frame` |
| POST | `admin/catalog/{movie}/frames/{frame}/retry` | `admin.catalog.frames.retry` | `can:update,frame`, `throttle:admin-frame` |

- La garde d'ajout nomme la classe `Frame` en premier argument : le middleware `can` résout la policy d'après ce premier argument, donc `FramePolicy::create(User, Movie)` et `createFromCapture(User, Movie)`. Écrite `can:create,movie`, elle résoudrait `MoviePolicy::create()` [existant], qui refuse toujours (la création d'un film est un acte d'import) : toute la voie TMDB répondrait 403.
- Le limiteur [nouveau] `admin-frame` vit dans `FortifyServiceProvider::configureRateLimiting()`.

**Configuration (nouveau bloc `curation` de `config/catalog.php`) :**
- `capture_enabled` ← `env('CURATION_CAPTURE_ENABLED', false)`. À ajouter **vide** dans `.env.example`.
- `tmdb_original_max_kilobytes`.
- `crop_seconds_max`.
- `webp.master_quality`, `webp.game_quality_start`, `webp.game_quality_min`, `webp.game_quality_step`.
- `imagick.memory_mb`, `imagick.map_mb`, `imagick.area_mpx`, `imagick.width_px`, `imagick.height_px`, `imagick.time_s`.

**Front :**
- `resources/js/lib/frame-geometry.ts` [nouveau, dans `WATCHED`, R-36] :
  - exporte `FRAME_GEOMETRY = { aspectWidth: 16, aspectHeight: 9, gameWidth: 1280, gameHeight: 720, masterWidth: 1920 } as const` ;
  - exporte les aides `maxCropWidth`, `defaultCrop` et `cropViolation`, miroirs non autoritaires de la version PHP.
- Jeton de thème [nouveau] `--aspect-frame: 16 / 9;` dans le `@theme` de `resources/css/app.css`. Il produit l'utilitaire `aspect-frame`, que consomment le cadre fixe de 90 (`GameFrame`, D7, R-37) et l'aperçu admin.
- Prop partagée `frameFormat: { width: 1280, height: 720 }` (C16), lue depuis `FrameGeometry::GAME_WIDTH` / `GAME_HEIGHT`.
- `resources/js/types/admin.ts` : `AdminMovieFrame` est **étendu** (voir § 3).

**Clés de traduction (domaine `admin`, FR seul) :**

| Famille | Feuilles |
|---|---|
| `admin.frame.processing_error.*` | les 12 feuilles de l'enum ci-dessus |
| `admin.validation.crop.*` | `aspect`, `too_wide`, `too_narrow`, `out_of_bounds` |
| `admin.validation.frame_source.*` | `max`, `mimetypes`, `dimensions` |
| `admin.frame.tmdb.*` | `not_a_backdrop`, `download_failed`, `too_large` |
| `admin.frame.capture.*` | `disabled`, `timecode` |
| `admin.frame.recrop.*` | `busy`, `not_ready`, `locked`, `default_reason` |
| `admin.frame.retry.not_retryable` | — |
| `admin.frame.flash.*` | `queued`, `recrop_queued`, `retry_queued` |
| `admin.frame.preview.alt` | paramètre `:level` |

- Les clés existantes `admin.enum.frame_processing.{pending,ready,failed}` sont réutilisées.

### 3. Formes de données

**`admin.catalog.frames.tmdb.store`**, formulaire Inertia sans fichier. Retour : `back()` avec le flash `admin.frame.flash.queued`, sans aucun chemin ni hash dans la réponse.

| Champ | Type | Règle |
|---|---|---|
| `tmdb_file_path` | string ≤ 255 | `^/[A-Za-z0-9_-]+\.(jpg\|png)$`. Appartenance aux **backdrops** de `TmdbClient::images($movie->tmdb_id)` vérifiée **dans le contrôleur** : un FormRequest ne peut pas utiliser `TmdbClient` (TmdbBoundaryTest). |
| `frame_level` | int | 1–5 (`FrameLevel`) |
| `crop_x`, `crop_y` | int ≥ 0 | — |
| `crop_width` | int | multiple de 16, dans [`frameCropMinWidthPx`, `floor(pct × 1920 / 100 / 16) × 16`] |
| `crop_height` | int | `= crop_width × 9 / 16` exactement |
| `crop_seconds` | int ≥ 0, facultatif | plafonné côté serveur à `catalog.curation.crop_seconds_max` |

- Le contrôleur calcule `masterHeight = FrameGeometry::masterHeightFor(width, height)` à partir du `TmdbImage` et contrôle que le cadre tient dans le master. En cas d'échec, il lève une `ValidationException` sur `admin.validation.crop.out_of_bounds`.
- Il télécharge ensuite l'original et appelle `AddFrame::fromTmdb()`.

**`admin.catalog.frames.capture.store`**, multipart :
- Mêmes champs que la voie TMDB, plus :
  - `source` : fichier WebP selon `finfo`, ≤ `PlatformLimits::frameUploadMaxKilobytes()` Ko, largeur ∈ [1280, 1920], hauteur ≤ largeur, dimensions lues par `getimagesize` (fonction du cœur de PHP, sans gd) ;
  - `source_timecode` : `h:mm:ss`, obligatoire, converti en `source_timecode_ms`.
- Tant que `catalog.curation.capture_enabled` est faux, la route répond **403** avec `admin.frame.capture.disabled`. C'est la double garde : l'interface ne montre pas le bouton, et le serveur refuse quand même.
- **Écart à la résolution n° 0, tracé en R-46 et signalé au porteur.** Le navigateur envoie la source normalisée et le rectangle, conformément à n° 0, mais **n'envoie aucun dérivé de jeu**. Le serveur dérive toujours le dérivé du master et du rectangle. Raisons :
  1. **D6 prime** : un dérivé produit par le navigateur rendrait le plancher invérifiable côté serveur, puisque `crop_width` en base ne prouverait plus rien des octets servis (la requête d'audit de l'invariant 3 deviendrait creuse) ;
  2. une seule chaîne de traitement pour TMDB, la capture et le re-recadrage ;
  3. le re-recadrage se fait sans renvoyer de fichier ;
  4. le serveur réencode de toute façon.
  - La branche est hors J1 : si le porteur retient la lettre de n° 0, seule cette branche change (le dérivé reçu est revalidé, réencodé et paddé, et le plancher reste vérifié sur le rectangle).

**`admin.catalog.frames.crop.update`** :
- Champs : `crop_*`, `crop_seconds`, `reason` (string ≤ 500, facultatif, pré-rempli depuis `admin.frame.recrop.default_reason`).
- Refus :
  - `admin.frame.recrop.not_ready` si `game_path` est NULL ;
  - `admin.frame.recrop.busy` si la frame est `pending` ;
  - `admin.frame.recrop.locked` si elle est `suspended` ou `withdrawn`.

**Props admin d'une frame (`AdminMovieFrame`, étendu) :**

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

- Le `published_hash` est ajouté **uniquement** aux props de l'écran de revue (C14-bis).
- **Aucun chemin disque**, ni `source_hash`, ni `tmdb_file_path` hors de l'écran de revue.
- Le formulaire d'ajout reçoit `limits: AdminFrameLimits` [nouveau type dans `types/admin.ts`, R-07] = `{ frameCropMaxWidthPercent: number, frameCropMinWidthPx: number, frameUploadMaxKilobytes: number }`, composé par le contrôleur depuis les accesseurs de `PlatformLimits`, jamais depuis `toArray()`.

**Réponse d'image** (`FrameImageResponse`, 200) : `Content-Type: image/webp`, `Content-Length`, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow`, `X-Content-Type-Options: nosniff`, `Cross-Origin-Resource-Policy: same-origin` ; jamais `Content-Disposition`, `Last-Modified`, `ETag`, `Accept-Ranges`, `Expires`. Les en-têtes **produits par `FrameImageResponse`** sont identiques pour `/f/` et l'aperçu. `Set-Cookie` est absent **sur `/f/` seulement** (pile sans session ni file de cookies, C8 § 2) ; sur l'aperçu, les cookies de session viennent de la pile admin, jamais de `FrameImageResponse`.

**Charge joueur :** C9 n'émet aucun message. Le joueur ne reçoit que les octets servis par la route de 60 (`image/webp`, 1280×720, `Content-Length` = `game_bytes`). Rien ne révèle la réponse avant la révélation. Aucun identifiant de frame, chemin, hash ou rectangle ne sort vers une surface joueur (10 § 1.1).

### 4. Invariants et garanties
1. **Ratio 16:9 exact (D5).**
   - Condition : `crop_width % 16 = 0` et `crop_height × 16 = crop_width × 9`.
   - Le dérivé mesure **exactement** 1280×720 ; il est sur-échantillonné si le cadre est plus étroit.
   - Des dimensions naturelles fixes suppriment l'empreinte par dimensions. C'est une quatrième surface d'anti-corrélation, à côté de l'URL, de la taille servie et des en-têtes (C8).
   - Le résidu « hachage des octets » reste assumé (D16) et c'est 60 qui le nomme.
2. **Espace du master.**
   - Le master est un WebP de **largeur exactement `MASTER_WIDTH`**, de hauteur `masterHeightFor()` ≤ 1920.
   - La source doit être en paysage et large d'au moins `GAME_WIDTH`. Si elle est plus étroite que 1920, elle est sur-échantillonnée.
   - Le rectangle est exprimé dans cet espace et doit y tenir.
   - Cette largeur fixe est ce qui rend le plancher D6 vérifiable **par requête SQL sans colonne neuve** (voir 6).
3. **Plancher D6, vérifié trois fois : navigateur, FormRequest et contrôleur, puis job.**
   - Condition : `crop_width × 100 ≤ frameCropMaxWidthPercent × 1920` et `crop_width ≥ frameCropMinWidthPx`.
   - Avec 80 %, le cadre couvre au plus 64 % de la surface d'un master 16:9, soit la fourchette 60-70 % de D6.
   - Sur un master plus large, le changement de ratio retire déjà davantage.
   - Si `maxCropWidth() < frameCropMinWidthPx`, la source est refusée avec `source_aspect`.
4. **Frame `ready`**, toutes conditions réunies :
   - fichier WebP statique, en sRGB, **dépouillé par `stripImage()`** (EXIF, XMP et ICC compris) ;
   - `game_width = 1280`, `game_height = 720` ;
   - `game_bytes` = longueur du fichier, `game_bytes % 8192 = 0`, `game_bytes ≤ 147 456` (donc ≤ 153 600) ;
   - `published_hash = sha256(octets paddés)`.
   - L'encodage descend la qualité de `game_quality_start` à `game_quality_min` par paliers de `game_quality_step`, jusqu'à passer sous `gameEncodeCeilingBytes()`. Sinon l'échec est `too_heavy`.
5. **`source_hash` (résolution n° 0).**
   - Voie `tmdb` : SHA-256 des octets `original` que **le serveur** télécharge dans la requête d'ajout.
   - Voie `capture` : SHA-256 des octets reçus, avant tout réencodage.
   - Posé à l'insertion, **jamais réécrit**. Changer de source crée une nouvelle frame.
   - Les octets reçus sont déposés **provisoirement** sous `master_path` : c'est le seul fichier écrit dans la requête. Ils sont remplacés par le master normalisé au premier traitement réussi. Aucun original n'est conservé au-delà.
   - Aucun traitement Imagick n'a lieu dans une requête HTTP.
6. **Job.**
   - Une image par job, file `default`, unique par frame, `afterCommit`.
   - `Imagick::setResourceLimit` est posé avant toute lecture, avec les valeurs `catalog.curation.imagick.*`.
   - Le master est normalisé, puis le cadre est **revalidé** sur le master réel, puis l'image est découpée, redimensionnée, encodée et paddée.
   - La nouvelle version est écrite sous un **nouveau** nom `FrameStoragePrefix::Game->newPath()`.
   - La bascule finale se fait dans une transaction, sous `lockForUpdate`. Si la frame est devenue `withdrawn` ou `published` entre-temps, le job abandonne et supprime le fichier neuf.
   - L'ancien fichier `game/` est supprimé après le commit.
   - Le job recalcule aussi `MovieProjector::recompute` dans la même transaction.
   - La normalisation du master est sautée si le master est déjà un WebP large de 1920. Cela évite la perte de génération au re-recadrage.
7. **Refus durs :**
   - frame `withdrawn` : échec `withdrawn`, aucun fichier écrit (10 § 10) ;
   - frame `published` : échec `published`. Un job ne réécrit jamais une frame en jeu (résolution n° 16).
8. **Échec.**
   - `processing_state = failed` et `processing_error` ∈ `FrameProcessingFailure`. Jamais de message brut : le détail va au journal technique seulement.
   - « Relancer » n'est offert que si `isRetryable()`.
   - Les exceptions transitoires sont d'abord rejouées par la file, puis finissent en `unexpected`.
9. **Re-recadrage d'une frame `published`.**
   - Dans **une** transaction : passage en `unpublished`, écriture d'une ligne `frame.unpublished` (C14), `crop_*` réécrit, `processing_state = pending`.
   - Le job n'est distribué qu'après le commit.
   - Une manche en cours bascule en substitution à la frappe suivante, selon le chemin paresseux de 60 (C8 § 4.6).
10. **Autres garanties.**
    - `Frame::isServable()` est inchangé : `published` ET `ready` ET `game_path` non nul.
    - La publication exige `ready` et une revue portant sur `published_hash` (C14-bis).
    - **Aucun LQIP** n'est produit (D7) et il n'existe aucun troisième préfixe.

### 5. Provenance de chaque valeur

| Valeur | Source | Surchargeable |
|---|---|---|
| 16:9, 1280×720, largeur de master 1920 | `FrameGeometry` (constante de format) | non : changer impose de recadrer toute la banque |
| 153 600 / 8 192 / 147 456 | `FrameGeometry` (147 456 est **calculé**, jamais écrit en littéral) | non |
| Plancher de largeur, 80 % | `PlatformLimits::frameCropMaxWidthPercent()` | configuration, bornes [70, 90], calibré au lot pilote |
| Largeur minimale du cadre, 640 px | `PlatformLimits::frameCropMinWidthPx()` | configuration |
| Plafond d'entrée, 1 536 Ko | `PlatformLimits::frameUploadMaxKilobytes()` [existant] | configuration |
| Pas du cadre, 16 | dérivé de `FrameGeometry::ASPECT_WIDTH` | non |
| Qualités WebP, limites Imagick, plafond de l'original TMDB, plafond de `crop_seconds` | `config('catalog.curation.*')` | configuration |
| Voie capture | `config('catalog.curation.capture_enabled')` | variable d'environnement, faux par défaut |

- Aucune de ces valeurs n'est une valeur de jeu : aucune ne vient de `room_settings`.

### 6. Exigences et amendements
- **À 10** : E10-02, E10-10, E10-11, E10-17, E10-18, E10-19, E10-21, E10-23, E10-60, E10-61 (G20 E1-E4, E10-E12).
- **Amendements** : A-02, A-24, A-28, A-36 (00) ; A-52 (questions-ouvertes) ; A-76 (CLAUDE.md § 8) ; G20 E14.
- **Non-amendement explicite :** `tests/Feature/Architecture/TmdbBoundaryTest.php` reste inchangé. Le téléchargement et l'appel `images()` vivent dans `App\Http\Controllers\Admin`. Les actions et le job ne reçoivent que des primitives et des octets, jamais un DTO `App\Support\Tmdb`.
- **À 100** : `extensions: imagick` dans le `setup-php` du **nouveau** workflow `tests-mysql.yml` (R-40). `ext-imagick` au `composer.json` et `extensions: imagick` dans le job `ci` de `tests.yml` sont **[existants]**.
- **Code à aligner :**
  - `FrameFactory` : dérivé 1280×720 réel, un aplat mis en cache statique, paddé ; master 1920×1080 ; rectangle par défaut `FrameGeometry::defaultCrop(1080, …)`. Le 1920×1080 actuel viole le plancher.
  - `FrameFactory::processingFailed(FrameProcessingFailure $failure = FrameProcessingFailure::Unexpected)` : l'état actuel écrit `'curation.frame_processing.reencode_failed'`, valeur hors enum et dans le domaine interdit `curation`, que le cast ferait lever.
  - `App\Models\Frame` : `@property FrameProcessingFailure|null $processing_error` (aujourd'hui `string|null`).
  - `AdminCatalogPresenter::movieFrame()` rend `$frame->processing_error?->value` (clé de traduction, type de retour `string|null` inchangé).
  - `DemoCatalogueSeeder` suit la fabrique.

### 7. Tests Pest

**`tests/Feature/Curation/FrameGeometryTest.php`**
- « le ratio de jeu est 16:9 et le dérivé mesure 1280 × 720 »
- « le plafond d'encodage est le plus grand multiple de 8 192 sous 153 600 »
- « masterHeightFor est entier et déterministe »
- « FrameStoragePrefix délègue ses plafonds à FrameGeometry »
- « frame-geometry.ts, le jeton --aspect-frame et la prop frameFormat reflètent FrameGeometry »

**`tests/Feature/Curation/CropRectangleTest.php`**
- « un cadre hors 16:9 exact est refusé »
- « un cadre plus large que le plancher est refusé »
- « un cadre plus étroit que la largeur minimale est refusé »
- « un cadre qui déborde du master est refusé »
- « le cadre par défaut est le plus grand cadre admis, centré »
- « le plancher est lu dans PlatformLimits et suit la configuration »
- La garde de bornes de `PlatformLimits` est prouvée par `PlatformLimitsTest` (C0, R-04).

**`tests/Feature/Curation/FrameTmdbStoreTest.php`**
- « un visuel absent des backdrops du film est refusé »
- « la garde d'ajout passe par FramePolicy et jamais par MoviePolicy::create »
- « source_hash est le SHA-256 des octets originaux téléchargés »
- « la frame naît draft et pending et le job part sur la file default après commit »
- « un échec de téléchargement ne crée aucune frame »
- « la réponse ne contient ni chemin disque ni hash »

**`tests/Feature/Curation/FrameCaptureStoreTest.php`**
- « la voie capture désactivée refuse côté serveur »
- « un fichier de plus de 1 536 Ko échoue en erreur traduite et jamais en 419 »
- « source_hash est l'empreinte de la source reçue »
- « le timecode est obligatoire pour une capture »

**`tests/Feature/Curation/ProcessFrameImageTest.php`**
- « le dérivé est un WebP statique de 1280 × 720 sans métadonnées »
- « game_bytes est un multiple de 8 192 et ne dépasse jamais 147 456 »
- « le padding ne casse pas le décodage »
- « published_hash est l'empreinte des octets paddés »
- « un WebP animé échoue en source_animated »
- « une source de moins de 1 280 px échoue en source_too_small »
- « le job refuse une frame withdrawn sans écrire de fichier »
- « le job refuse de réécrire une frame published »
- « chaque échec pose failed et une clé FrameProcessingFailure »
- « Relancer n'est offert qu'à un échec rejouable »
- « chaque traitement écrit un nouveau game_path et supprime l'ancien »
- « le job part sur default et jamais sur game »
- « Imagick sait encoder le WebP » : échoue en CI si le format manque

**`tests/Feature/Curation/FrameRecropTest.php`**
- « recadrer une frame publiée la sort de published et écrit frame.unpublished dans la même transaction »
- « une frame recadrée ne revient en jeu qu'après une revue sur ses nouveaux octets »

**`tests/Feature/Curation/FrameProbesTest.php`**
- « aucune frame published sans published_review_id »
- « le published_hash de toute frame published égale le reviewed_hash de sa revue »
- « aucune frame ready hors 1280 × 720 ou hors padding »
- « la requête d'audit du plancher ne renvoie aucune frame »

### 8. Libre pour le rédacteur de 20
- Ergonomie du recadreur : pas de zoom, raccourcis, pilotage clavier (opérabilité intégrale, principe 8).
- Valeurs de qualité WebP et des limites Imagick.
- Valeur calibrée du plancher, dans [70, 90].
- Temporisation et nombre d'essais du job.
- Cache éventuel de `images()`.
- Libellés.
- Changement de `frame_level` d'une frame publiée.
- Ordre de la file des échecs.
- Écrans du back-office.
- L'implémentation du flux de `FrameImageResponse` (avec 60).

---

## C9-bis — Route d'aperçu admin

### 1. Propriétaire, consommateurs, jalon
- **Propriétaire :** 20.
- **Consommateurs :**
  - 90 : `GameFrame` réutilisé en aperçu dans `GameThemeScope` (D8, C16) ;
  - 60 : partage `FrameImageResponse` ;
  - 100 : tests d'indexation.
- **Jalon :** J1.

### 2. Noms exacts

| Méthode | URI | Nom | Action | Garde |
|---|---|---|---|---|
| GET | `admin/catalog/{movie}/frames/{frame}/game` | `admin.catalog.frames.game` | `App\Http\Controllers\Admin\FrameImageController@game` [nouveau] | `can:view,frame` |
| GET | `admin/catalog/{movie}/frames/{frame}/master` | `admin.catalog.frames.master` | `…@master` | `can:view,frame` |

- Ces deux routes **remplacent** la proposition de 60 (`admin.frames.preview`, R-32).
- `FramePolicy::view` : `UserRole::atLeast(Curator)` ET `availability ≠ withdrawn`.
- Les routes sont sous `scopeBindings()` : une frame d'un autre film répond 404.
- Texte alternatif : `admin.frame.preview.alt`.

### 3. Forme de la réponse
- Corps : les octets du fichier, construits par `FrameImageResponse::make(FrameStoragePrefix::Game|Master, $path)`.
- En-têtes **exacts** : ceux de C9 § 3 (`Content-Type: image/webp`, `Content-Length`, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow`, `X-Content-Type-Options: nosniff`, `Cross-Origin-Resource-Policy: same-origin`).
- **Jamais** `Content-Disposition`, `Last-Modified`, `ETag` ni `Accept-Ranges`.
- Jamais `Storage::response()` ni `BinaryFileResponse` (10 § 10).
- `game` et `master` répondent 404 tant que `game_path` est NULL : le master n'est pas normalisé avant le premier traitement réussi.

### 4. Invariants
- Seul second lecteur du disque `frames`, avec `/f/{serveToken}`.
- `master/` n'est lisible que par `admin.catalog.frames.master`.
- Aucune URL joueur n'atteint `master/`.
- L'aperçu `game` sert **les mêmes octets** que ceux servis aux joueurs : c'est ce que la revue hache.

### 5. Provenance
Aucune valeur propre à cette route.

### 6. Exigences et amendements
- **À 10** : E10-11 (identifiants dans le back-office), E10-61 (second lecteur du disque).
- **Amendements** : A-36 (00, principe 13, D8). Suppression de `App\Http\Middleware\ForceAdminAppearance` et de l'alias `admin.appearance` (C16).

### 7. Tests Pest

**`tests/Feature/Curation/FrameImagePreviewTest.php`**
- « un joueur reçoit 403 et un invité est redirigé »
- « un curateur reçoit le dérivé avec no-store, noindex et nosniff »
- « l'aperçu ne pose ni Content-Disposition, ni Last-Modified, ni ETag »
- « une frame d'un autre film répond 404 »
- « une frame withdrawn répond 404 »
- « le master n'est servi que par admin.catalog.frames.master »
- « aucune route hors admin ne sert le préfixe master/ »

### 8. Libre
- Limiteur éventuel.
- Rendu de l'écran de revue.
- Tailles mobile et desktop simulées.

---

## Préambule du groupe 70

Ce groupe fige trois contrats : C10 (soumission et verrouillage), C11 (composition du QCM) et C12 (normaliseur et version de la règle de validation).

**Conventions de lecture.**
- Aucun renommage de classe n'est demandé. Deux sémantiques sont élargies et signalées : le scope `RoundPlayer::open()` et `AnswerKeyKind::isExact()`.
- Les noms qui appartenaient à un autre groupe (« nom attendu ») sont remplacés ici par les noms figés par ce groupe (R-17, R-19, R-22, R-23) : la charge utile et la règle définies par 70 ne changent pas.

**Décisions appliquées.**
- D20, D21, D23 et D24.
- Contradictions n° 29, 50, 51, 55, 57, 58, 59, 61, 62 et 63, avec leur résolution.
- Résolutions retenues des questions écartées de 70 (Q70-1 : seuil + garde exacte + chiffres stricts ; Q70-2 : statu quo ; Q70-5 : accepter et sonder ; Q70-7 : alias curé).

---

## C10 — Service de soumission et transaction de verrouillage

### 1. Propriétaire, consommateurs, jalon

| | |
|---|---|
| Propriétaire | **70** |
| Consommateurs | **60** (identification du siège, rattrapage synchrone, écouteurs « a trouvé » et fin anticipée, relecture de la saisie à la resynchronisation) · **80** (fonction pure palier + points appelée dans la transaction) · **90** (écran de saisie, états, messages) · **50** (bornes `attemptsPerSecond`, `attemptsPerRound`, `maxAnswerLength`, sonde de force brute) · **100** (tests, groupe `locks-timing`) |
| Jalon | **J1** pour tout le contrat. Au J1, le limiteur vaut toujours 1/s et le plafond vaut `ceil(D/2 s)` ≤ 30 : l'onglet Avancé n'arrive qu'au J2. L'écriture « quasi-juste » d'un refus n'existe qu'au **J2** (D24). |

### 2. Noms exacts

**PHP**

| FQCN | Statut | Signature et rôle |
|---|---|---|
| `App\Http\Controllers\Game\AnswerController` | [nouveau] | `store(AnswerStoreRequest $request, Player $player): JsonResponse` |
| `App\Http\Controllers\Game\ChoiceController` | [nouveau] | `store(ChoiceStoreRequest $request, Player $player): JsonResponse` |
| `App\Http\Requests\Game\AnswerStoreRequest` | [nouveau] | règles `round` : `required\|integer\|min:1` ; `answer` : `required\|string\|max:{settings_snapshot.maxAnswerLength}`. Le siège et la partie sont résolus par le middleware `seat.active` de 60, mis en mémoire sur la requête. |
| `App\Http\Requests\Game\ChoiceStoreRequest` | [nouveau] | règles `round` : `required\|integer\|min:1` ; `choice` : `required\|string\|max:255` |
| `App\Actions\Game\SubmitTextAnswer` | [nouveau] | `handle(Player $seat, Game $game, int $roundSequence, string $answer, CarbonImmutable $receivedAt): SubmissionVerdict` |
| `App\Actions\Game\SubmitChoice` | [nouveau] | `handle(Player $seat, Game $game, int $roundSequence, string $choice, CarbonImmutable $receivedAt): SubmissionVerdict` |
| `App\Actions\Game\LockGuess` | [nouveau] | `handle(Round $round, RoundPlayer $roundPlayer, Game $game, MatchResult $match, GuessSource $source, CarbonImmutable $receivedAt, int $answeredAtMs): SubmissionVerdict`. C'est la seule écrivaine de `guess`. |
| `App\Support\Answers\AnswerMatcher` | [nouveau] | `match(Round $round, string $submittedNormalized): MatchResult` fait les deux lectures inconditionnelles puis appelle `decide()`. `public static function decide(string $submittedNormalized, int $targetMovieId, array $targetKeys, array $publishedMovieIdsCarryingSubmitted): MatchResult` est pure, sans base ; `$targetKeys` est une `list<AnswerKey>` et le dernier paramètre une `list<int>`. |
| `App\ValueObjects\Answers\MatchResult` | [nouveau], `final readonly` | `bool $accepted`, `string $submittedNormalized`, `?int $answerKeyId`, `?string $answerKeyNormalized`, `?GuessMatchKind $matchKind`, `int $editDistance`, `bool $prefixWasAmbiguous` |
| `App\ValueObjects\Answers\SubmissionVerdict` | [nouveau], `final readonly` | `SubmissionOutcome $outcome`, `RoundPlayerInputState $inputState`, `int $attemptsLeft`, `?int $lockRank`, `?TierScore $score`. Méthodes `toArray(): array` et `httpStatus(): int`. |
| `App\ValueObjects\Answers\SeatInputView` | [nouveau], `final readonly` | Vue de la saisie destinée au seul siège, intégrée par 60 à la resynchronisation (`SelfState.input`, C7) : `static forSeat(RoundPlayer $roundPlayer): self`, `toArray(): array` |
| `App\Enums\SubmissionOutcome` | [nouveau] | `Accepted = 'accepted'`, `Rejected = 'rejected'`, `Closed = 'closed'` |
| `App\Enums\RoundPlayerInputState` | [existant], **+1 cas (D20)** | `TextExhausted = 'text_exhausted'` (14 caractères ≤ `string(20)`). Méthodes nouvelles : `acceptsText(): bool` (vrai pour `Open` seulement) et `acceptsChoice(): bool` (vrai pour `Open` et `TextExhausted`), seul prédicat employé par 60 (R-28). `static notClosedValues(): list<string>` rend `['open', 'text_exhausted']`. `isClosed()` devient `! in_array($this, [Open, TextExhausted])`. `isSoloOnly()` [existant] inchangée. |
| `App\Models\RoundPlayer::open()` (scope) | [existant], **sémantique élargie** | `whereIn('input_state', RoundPlayerInputState::notClosedValues())`, soit « saisie non close ». Le nom est conservé. |
| `App\Events\Game\AnswerAccepted` | [nouveau] | `implements ShouldDispatchAfterCommit`. Propriétés `int $roundId`, `int $playerId`, `int $lockRank`. **Jamais `ShouldBroadcast`** : ce sont des identifiants internes réservés aux écouteurs de 60, qui appellent `SeatInputClosed` (C7, R-21). |
| `App\Events\Game\InputClosed` | [nouveau] | `implements ShouldDispatchAfterCommit`. Propriétés `int $roundId`, `int $playerId`, `RoundPlayerInputState $state`, qui vaut `QcmWrong` ou `AttemptsExhausted`. Même restriction. |
| Plancher de palier du QCM | **C13** | `App\Support\Scoring\ScoringRules::floorTierIndex(GuessSource, InputDifficulty, int, int $version)` : seule implémentation, appliquée à l'intérieur de `ScoreCalculator::forGuess()` ; `AnswerRules::floorTierIndex()` **n'existe pas** (R-18). |
| Limiteur `answer` | [nouveau] | Déclaré dans `App\Providers\FortifyServiceProvider::configureRateLimiting()` [existant] : `RateLimiter::for('answer', fn (Request $r): Limit => …)` |
| Fonction de score | **C13** | Appel figé : `ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $answeredAtMs, $source): TierScore` (R-19). `App\ValueObjects\Scoring\TierScore { int $tierIndex, int $pointsTier, int $pointsBonus, int $pointsTotal }`. |
| Instant de réception | **C7** | `App\Support\Game\ReceptionInstant::of(Request $request): CarbonImmutable`, capturé à l'entrée de la requête (R-22). |
| Décalage dans la manche | **C7** | `App\Support\Game\RoundClock::offsetMs(Round $round, CarbonImmutable $instant): int` (R-22). |
| Rattrapage synchrone | **C7** | `App\Actions\Game\CatchUpGame::handle(Game $game, CarbonImmutable $now): void`. |

**Routes** (`routes/game.php`, créé par le premier lot qui en a besoin et chargé par `require` depuis `routes/web.php`)

| Méthode, chemin | Nom | Middleware |
|---|---|---|
| `POST /seat/{player:public_id}/answer` | `round.answer.store` | `web`, `seat.active` de 60 (jeton tenant ce siège + `active_seat_token`, R-43), `throttle:answer` |
| `POST /seat/{player:public_id}/choice` | `round.choice.store` | idem |

Le siège est adressé par `player.public_id`, donc la même route sert le salon et le solo. Le `public_id` ne donne aucun droit : `seat.active` exige que le jeton courant tienne ce siège, non expulsé.

**Front** (fichiers en kebab-case, tous nouveaux ; présentation propriété de 90, contrat de données fixé ici ; tous dans `WATCHED`, R-36)
- `resources/js/types/answers.ts`, réexporté par `resources/js/types/index.ts` : types `InputState`, `SubmissionResult`, `SeatInputView`, `ChoicesPayload` (C11). Seule déclaration de ces types côté client (R-27).
- `resources/js/hooks/game/use-answer-submission.ts` : `useHttp()` + Wayfinder `@/actions/App/Http/Controllers/Game/AnswerController` et `ChoiceController` → `{ submitText(text: string), submitChoice(choice: string), pending: boolean, last: SubmissionResult | null }`.
- `resources/js/components/game/answer-input.tsx` : props `{ state: InputState; attemptsLeft: number; maxLength: number; pending: boolean; onSubmit: (text: string) => void }`.

**Événements diffusés par 60 à partir de ceux de C10** : `player.locked` (canal de présence du salon). Aucun événement de résultat ciblé au J1 (pas de `seat.locked`, R-21).

**Clés de traduction** (domaine `game`, symétriques FR/EN)
- `game.answer.label`, `game.answer.placeholder`, `game.answer.submit`
- `game.answer.rejected`, `game.answer.attempts_left` (pluriel `tChoice`), `game.answer.too_fast`, `game.answer.closed`, `game.answer.unreadable`, `game.answer.exhausted`, `game.answer.text_exhausted`, `game.answer.locked`
- `validation.attributes.answer`, `validation.attributes.choice`

### 3. Formes de données

**Requêtes** (JSON, clés camelCase)
- `round.answer.store` : `{ round: int, answer: string }`
- `round.choice.store` : `{ round: int, choice: string }`

`round` est la `sequence_index` de la manche que le client croit ouverte (le `sequenceIndex` des charges de C7), jamais `round.id`. `choice` est l'une des quatre chaînes reçues, renvoyée à l'octet près. Jamais un index.

**Réponses HTTP.** Destinataire unique, donc les messages sont résolus côté serveur dans la locale de la requête (05).

| Cas | Statut | Corps |
|---|---|---|
| Acceptée (texte ou clic) | 200 | `{ result: 'accepted', inputState: 'locked', lockRank: int, tierIndex: int, pointsTier: int, pointsBonus: int, pointsTotal: int }` |
| Refusée (texte faux, quelle qu'en soit la cause ; ou clic faux) | 200 | `{ result: 'rejected', inputState: InputState, attemptsLeft: int }`. `attemptsLeft` vaut `attemptsPerRound − wrong_attempts` si `inputState = 'open'`, sinon `0`. |
| Saisie ou manche close (voir § 4) | 409 | `{ result: 'closed', inputState: InputState, message: string }` (`game.answer.closed`) |
| Trop rapide (limiteur) | 429 | `{ message: string }` (`game.answer.too_fast`). Non évaluée, non comptée. |
| Saisie vide après normalisation, trop longue, ou chaîne absente des quatre propositions | 422 | Erreurs de validation Laravel indexées sur `answer` ou `choice` (`game.answer.unreadable`, `game.choices.invalid`). Non comptée. |
| Siège non résolu ou supplanté | 403 / 409 `{ code: 'seat_superseded' }` | Propriété de 60 (`seat.active`) et 40 |

`InputState` = `'open' | 'text_exhausted' | 'attempts_exhausted' | 'locked' | 'qcm_wrong' | 'revealed' | 'skipped'`. Cette valeur n'est jamais envoyée qu'au **siège lui-même**.

**Aucune réponse ne porte** de titre, d'alias, de `match_kind`, de forme normalisée ni de distance. Un refus pour préfixe ou sous-titre ambigu a exactement le même corps qu'un refus franc.

**Vue de saisie du siège (`SeatInputView`)**. Envoi ciblé : `SelfState.input` de la resynchronisation du seul siège (C7). Clé `inputState` alignée sur la réponse HTTP (R-26) :
```
{ inputState: InputState, attemptsLeft: int,
  choices: ChoicesPayload | null,                                  // C11, rejouée à l'identique
  locked: { lockRank: int, tierIndex: int, pointsTier: int, pointsBonus: int, pointsTotal: int } | null }
```

**Diffusé au salon** par 60 à partir de `AnswerAccepted` : `player.locked` = enveloppe de 60 + `{ sequenceIndex, publicId: string(12), lockRank: int }`. **Rien d'autre** : ni points, ni `tierIndex`, ni chaîne (10 § 7.6). `InputClosed` n'est **jamais** diffusé, car `qcm_wrong` révélerait une mauvaise réponse (10 § 7.6). Aucune charge n'expose `player.id`, `round.id`, `game.id` ni `answer_key.id` (10 § 1.1).

### 4. Invariants et garanties

**Instant (L3).**
- `receivedAt = ReceptionInstant::of($request)` est capturé **une fois**, à l'entrée de la requête, avant tout verrou et tout travail de validation (C7).
- `answeredAtMs = RoundClock::offsetMs($round, $receivedAt)`, un entier calculé une seule fois (R-22).
- Aucune valeur mesurée ou déclarée par le client sur le temps (horodatage, RTT, décalage d'horloge) n'entre dans la fenêtre d'acceptation, le palier ni le bonus (L3). Le numéro de manche déclaré (`round`) sert seulement à refuser une soumission adressée à une autre manche ; `answer` et `choice` sont jugés, jamais crus.

**Fenêtre d'acceptation (n° 59, R-20).** Une soumission est recevable si et seulement si :
- `round.status = 'running'` (il reste `running` de `T₁` jusqu'à `RevealRound`, C7 § 4.6) ;
- et `0 ≤ answeredAtMs` et `receivedAt < (round.ended_at ?? round.started_at + round.duration_ms) + game.tier_grace_ms` ;
- et `round.sequence_index = round` (valeur de la requête).

Hors fenêtre, la réponse est 409 `closed` et rien n'est compté. La recevabilité est **revérifiée sous le verrou de `round`** dans la transaction. Avant de lire `round.status`, 70 appelle `CatchUpGame` (60), qui exécute une frontière passée non encore traitée.

**Plancher de palier du QCM (n° 59).** Le palier et le bonus sont calculés par `ScoreCalculator::forGuess()` (C13), qui applique `ScoringRules::floorTierIndex(source, game.input_difficulty, game.frames_per_round, game.scoring_version)`. Sémantique (C13 § 4.1) :
- `effectiveMs = max(answeredAtMs − tier_grace_ms, 0, starts_at_offset_ms(floorTierIndex))` ;
- palier = la ligne `round_tier` qui contient `effectiveMs` ;
- `t = effectiveMs − starts_at_offset_ms(palier)`.

Un clic reçu avant l'ouverture du palier du QCM (`InputDifficulty::choicesOpenTierIndex(N)`) est 409 `closed`, décidé par 70 avant tout appel à 80. Le rejeu relit `guess.source`, `game.input_difficulty` et `game.frames_per_round`.

**Machine d'états de `round_player.input_state` (D20).** Écrivain unique : 70, sauf `revealed` et `skipped` qu'écrit 60 (D18).

| Depuis | Événement | Condition | Vers | `input_closed_at` | Événement émis |
|---|---|---|---|---|---|
| `open` | texte accepté | Normal, Expert | `locked` | `receivedAt` | `AnswerAccepted` |
| `open` | texte refusé, `wrong_attempts + 1 < attemptsPerRound` | — | `open` | — | — |
| `open` | texte refusé, plafond atteint | **Normal**, QCM pas encore composé | **`text_exhausted`** | NULL | — |
| `open` | texte refusé, plafond atteint | Normal, QCM en cas terminal (`receivedAt ≥ T_N` et `round.decoy_movie_id_1 IS NULL`) | `attempts_exhausted` | `receivedAt` | `InputClosed` |
| `open` | texte refusé, plafond atteint | Expert | `attempts_exhausted` | `receivedAt` | `InputClosed` |
| `open`, `text_exhausted` | clic juste | QCM ouvert | `locked` | `receivedAt` | `AnswerAccepted` |
| `open`, `text_exhausted` | clic faux | QCM ouvert | `qcm_wrong` (ferme aussi le texte) | `receivedAt` | `InputClosed` |
| `text_exhausted` | QCM impossible à composer (C11, cas terminal) | Normal | `attempts_exhausted` | instant de composition | `InputClosed` |

Règles complémentaires :
- En **Facile**, la route texte répond toujours 409 `closed`, sans rien compter. En **Expert**, la route QCM répond toujours 409.
- `text_exhausted` n'est **jamais** « saisie close ». Prédicat de fin anticipée : `input_state NOT IN ('open','text_exhausted')`. Un siège `text_exhausted` reçoit bien le QCM à `T_N`.
- Invariants testés :
  - `locked` ⟺ une ligne `guess` existe ;
  - `text_exhausted` est inatteignable hors `input_difficulty = normal` ;
  - `wrong_attempts ≤ attemptsPerRound` ;
  - `revealed` et `skipped` sont inatteignables hors solo.

**Séquence à travail constant (invariant L4).** Ordre fixe, sans aucune branche qui dépende de la proximité :

| Étape | Contenu | Issue si l'étape échoue |
|---|---|---|
| S0 | limiteur `answer` | 429 |
| S1 | siège et jeton de siège (`seat.active`, 60) | 403 / 409 |
| S2 | FormRequest (forme, longueur brute via `max:`) | 422 |
| S3 | `CatchUpGame` (60), puis lecture de `round` par `(game_id, sequence_index)`, statut et fenêtre | 409 |
| S4 | lecture de `round_player` par `round_player_round_player_uq`, puis `acceptsText()` ou `acceptsChoice()` et QCM ouvert | 409 |
| S5 (texte) | `normalize()` | 422 si `''` |
| S6 (texte) | voir ci-dessous | — |
| S7 (texte) | décision (§ Précédence) | — |
| S8a | refus : exactement **une** instruction `UPDATE` conditionnelle de `round_player`. J1 : aucune autre écriture. J2 : **exactement une** écriture de tampon de quasi-juste, jamais conditionnelle (C12) | — |
| S8b | acceptation : transaction de verrouillage | — |

Détail de S6. Deux lectures **toujours** exécutées :
- (K) `SELECT id, normalized, key_kind, is_ambiguous FROM answer_key WHERE movie_id = :cible` via `answer_key_movie_idx` ;
- (O) `SELECT answer_key.movie_id FROM answer_key JOIN movie ON movie.id = answer_key.movie_id WHERE answer_key.normalized = :s AND movie.availability = 'published'` via `answer_key_norm_movie_uq` + clé primaire.

Puis le calcul de **toutes** les distances de K, sans sortie anticipée. **Aucun cache au J1** : L2 est tenu par construction (n° 58).

Interdits :
- toute lecture conditionnelle ;
- tout écart entre cache chaud et cache froid ;
- tout envoi de message qui dépendrait du verdict de proximité.

**Précédence du verdict texte** (`AnswerMatcher::decide`). `s` = saisie normalisée ; `O≠` = films publiés autres que la cible dans le résultat de O.

| Étape | Condition | Issue |
|---|---|---|
| (a) | `s` égale une clé de nature exacte de la cible (`title_original`, `title_latin`, `title`, `alias`) | accepté, `edit_distance = 0`, `match_kind = key_kind->toMatchKind()`, `prefix_was_ambiguous = (O≠ non vide)` |
| (b) | sinon, `s` égale une clé dérivée (`prefix`, `subtitle`) de la cible et `O≠` est vide | accepté, distance 0, `prefix_was_ambiguous = false` |
| (c) | sinon, `O≠` non vide (`s` désigne exactement un autre film publié, préfixe ou sous-titre ambigu compris) | **refus** |
| (d) | sinon, tolérance sur les clés exactes de la cible et ses clés dérivées `is_ambiguous = false`, **à suite de chiffres identique** (C12) : `d = distance(s, k)`, accepté si `d ≤ AnswerRules::tolerance(strlen(compact(k)))`. Égalité départagée ainsi : nature exacte, puis `prefix`, puis `subtitle`, puis `answer_key.id` croissant | accepté si un candidat passe |
| (e) | sinon | **refus** |

Au rejeu, la décision ne dépend que de `(s, K, O)`.

**Refus : l'instruction unique** (portable MySQL/SQLite ; les affectations d'état **précèdent** l'incrément, parce que MySQL évalue de gauche à droite et SQLite sur les valeurs d'origine) :
```
UPDATE round_player
SET input_state = CASE WHEN wrong_attempts + 1 >= :cap THEN :exhaustedState ELSE input_state END,
    input_closed_at = CASE WHEN wrong_attempts + 1 >= :cap AND :exhaustedState = 'attempts_exhausted' THEN :receivedAt ELSE input_closed_at END,
    wrong_attempts = wrong_attempts + 1,
    updated_at = :now
WHERE id = :id AND input_state = 'open' AND wrong_attempts < :cap
```
- `:exhaustedState` vaut `attempts_exhausted` en Expert, **et en Normal** quand `receivedAt ≥ T_N` et `round.decoy_movie_id_1 IS NULL` (composition terminale de C11, lue sur la ligne `round` déjà chargée en S3 après `CatchUpGame`, sans requête supplémentaire : L4 tenu). Sinon, en Normal, il vaut `text_exhausted`.
- Si aucune ligne n'est touchée (fermeture concurrente), la réponse est 409 `closed`. Le nombre de requêtes est le même.
- Toute soumission fausse compte, y compris une répétition exacte de la précédente : aucune saisie fausse n'est mémorisée (décision 19).
- Quand le refus ferme la saisie (`attempts_exhausted`), `InputClosed` est émis après commit ; la réévaluation de fin anticipée par 60 dépend du compteur, jamais de la proximité (L4 tenu).

**Clic.**
- S5' : lecture de `round_choice_set` par `(round_id, round_player.choices_locale)`.
- Une chaîne hors des quatre propositions donne 422, non comptée, et la saisie reste ouverte.
- `choice === choice_1` (égalité **stricte**, `answer_key` jamais consulté, 10 § 7.8) mène à la transaction de verrouillage avec `MatchResult { answerKeyId: null, answerKeyNormalized: normalize(choice_1), submittedNormalized: normalize(choice), editDistance: 0, prefixWasAmbiguous: false, matchKind: Choice }`.
- Sinon : `UPDATE round_player SET input_state='qcm_wrong', input_closed_at=:receivedAt WHERE id=:id AND input_state IN ('open','text_exhausted')`, puis `InputClosed`.

**Transaction de verrouillage (`LockGuess`, 10 § 7.6 + A12).** Dans un seul `DB::transaction`, dans cet ordre :
1. `SELECT … FROM round WHERE id=? FOR UPDATE`, puis revérification de la recevabilité ;
2. `SELECT … FROM round_player WHERE id=? FOR UPDATE`, puis revérification de `acceptsText()` ou `acceptsChoice()` (un clic faux et une bonne réponse texte concurrents ne donnent jamais `locked` + `qcm_wrong`) ;
3. `ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $answeredAtMs, $source)` (pur, C13) ;
4. `UPDATE round SET found_count = found_count + 1` ; `lock_rank := found_count` lu sous verrou ;
5. `INSERT guess` avec l'instantané complet ;
6. `UPDATE round_player SET input_state='locked', input_closed_at=:receivedAt` ;
7. `AnswerAccepted::dispatch()`, livré après le commit, écouté par 60 (R-21).

L'**ordre de verrouillage `round` puis `round_player`** est imposé à tout écrivain (C7 § 4.3). La transaction ne verrouille jamais `game` (C13 § 4.5). Si la revérification échoue, la réponse est 409 `closed` sans aucune écriture.

`lock_rank` est l'ordre d'acquisition du verrou (00 l.137). Il peut s'inverser avec `answered_at_ms` à quelques millisecondes près ; le départage de 80 lit `answered_at_ms`.

**Instantané `guess`**. Toujours complet :
- `received_at`, `answered_at_ms`, `tier_index`, `lock_rank`, `source`, `match_kind` ;
- `answer_key_id` (NULL pour un clic), `answer_key_normalized`, `submitted_normalized` ;
- `edit_distance`, `prefix_was_ambiguous` ;
- `points_tier`, `points_bonus`, `points_total`.

Jamais de texte brut, jamais d'IP.

**Idempotence.** Une seconde soumission d'un siège déjà verrouillé rend 409 `closed` avec `inputState: 'locked'`. `guess_round_player_uq` et `guess_round_rank_uq` servent de filet, pas de mécanisme.

**Limiteur.**
- `Limit::perSecond(settings_snapshot.attemptsPerSecond)->by("answer:{text|choice}:{player.id}")`, avec `->response()` qui rend 429 JSON traduit.
- Le siège est lu **dans la closure** depuis la résolution mise en mémoire par `seat.active` (60). La clé ne dépend donc ni de l'IP, ni du seul `public_id` (sinon un tiers pourrait vider le budget d'un autre). Si le siège n'est pas résolu : `Limit::none()`, et le refus vient ensuite de S1.
- Deux sièges d'une même personne doublent le budget : c'est un résidu assumé, formulé « par siège » (n° 51).

**Crochets.**
- 80 : la fonction pure est appelée à l'étape 3.
- 60 : écouteurs de `AnswerAccepted` (`SeatInputClosed` : diffusion `player.locked` + réévaluation de `Round::isEarlyEndReached()`) et de `InputClosed` (`SeatInputClosed` : réévaluation seule), dans une transaction qui reprend le verrou `round` (R-21).

**Solo.** Même chemin, mêmes plafonds, même limiteur. Aucune diffusion.

### 5. Provenance des valeurs

| Valeur | Source |
|---|---|
| `attemptsPerSecond`, `attemptsPerRound`, `maxAnswerLength`, `speedBonus` | `game.settings_snapshot` (`RoomSettings`, bornes dans `RoomSettingsBounds`) |
| `input_difficulty`, `N` | colonnes `game.input_difficulty`, `game.frames_per_round` |
| `D`, offsets, durées, valeurs de palier | `round.duration_ms`, `round_tier.*` (matérialisés) |
| `tier_grace_ms` | `game.tier_grace_ms`, figé au lancement depuis `PlatformLimits::tierGraceMs()` |
| `B_max`, forme du bonus, plancher QCM, `scoring_version` | C13 (`PlatformLimits::speedBonusMaxPercent()`, D22 ; `ScoringRules`) |
| Précédence, tolérance, version | constantes de version `AnswerRules` (C12), couvertes par `validation_version` |

Aucun littéral de jeu dans le contrôleur, l'action ou le FormRequest.

### 6. Exigences et amendements
- **À 10** : E10-06, E10-16, E10-48, E10-50, E10-51, E10-53, E10-57.
- **Amendements** : A-04, A-05, A-08, A-21 (00) ; A-61 (questions-ouvertes) ; A-70, A-74 (CLAUDE.md).
- **Aux specs voisines** :

| Spec | Exigence |
|---|---|
| **60** | (a) `round.status` reste `running` jusqu'à `RevealRound` ; le paquet de titres ne part pas avant `ended_at + tier_grace_ms` ; l'affichage de clôture à `ended_at` est permis, sans titre (n° 59, C7 § 4.6). (b) Pousser le QCM aux sièges `acceptsChoice()`, soit `open` **et** `text_exhausted` (D20). (c) Fournir `ReceptionInstant`, `CatchUpGame`, le middleware `seat.active` et l'ordre de verrouillage. (d) Écouter `AnswerAccepted` et `InputClosed`. |
| **80** | `forGuess()` applique le plancher avec la sémantique du § 4 ; elle est la même au rejeu. |
| **50** | Aucune borne nouvelle. La sonde de force brute (C12 § 8) sert au recalibrage avant l'onglet Avancé (Q70-5). |
| **100** | Classer les cas concurrents de verrouillage dans `tests/Concurrency/Answer/` (MySQL). |

### 7. Tests Pest nommés

`tests/Feature/Answer/AcceptanceWindowTest.php`
- « accepte une réponse reçue à D + tier_grace_ms − 1 au dernier palier »
- « refuse comme manche close une réponse reçue à D + tier_grace_ms, sans la compter »
- « refuse une réponse sur une manche qui n'est pas running »
- « refuse une réponse dont la manche annoncée n'est pas la manche ouverte »
- « refuse une réponse reçue après ended_at + tier_grace_ms d'une manche close par fin anticipée »

`tests/Feature/Answer/ChoiceFloorTest.php`
- « un clic QCM en Normal reçu à T_N + 100 ms est crédité au palier N »
- « un texte reçu à T_N + 100 ms est crédité au palier N − 1 »
- « un clic reçu avant l'ouverture du QCM est refusé comme saisie close »
- « le rejeu depuis guess.source et game.input_difficulty redonne tier_index et points_total »

`tests/Feature/Answer/ConstantWorkRefusalTest.php`
- « le refus d'une chaîne à distance 1 et celui d'une chaîne à distance 12 exécutent le même nombre de requêtes, dont exactement un UPDATE de round_player, et n'insèrent aucune ligne » (sous `Queue::fake()`)
- « un refus pour préfixe ambigu exécute le même nombre de requêtes qu'un refus franc »

`tests/Feature/Answer/MatchPrecedenceTest.php`
- « accepte toujours un titre complet homonyme d'un autre film publié »
- « refuse un préfixe porté par un autre film publié »
- « refuse une saisie égale à une clé d'un autre film publié même sous le seuil de tolérance »
- « évalue l'ambiguïté à l'instant de réception quand un film est publié en pleine manche »
- « ne recalcule jamais un guess existant »

`tests/Feature/Answer/AttemptsTest.php`
- « chaque refus compte, même répété »
- « le plafond ferme le texte en Expert »
- « le plafond en Normal passe en text_exhausted et le clic reste recevable à T_N »
- « un siège text_exhausted ne compte pas comme saisie close pour la fin anticipée »
- « en Normal, après une composition terminale, l'épuisement du texte ferme la saisie en attempts_exhausted »
- « en Facile la route texte répond saisie close sans compter »
- « une saisie vide après normalisation ou trop longue est refusée en 422 sans compter »

`tests/Feature/Answer/LockGuessTest.php`
- « input_state locked si et seulement si une ligne guess existe »
- « le guess porte l'instantané complet de la règle »

`tests/Concurrency/Answer/LockTransactionTest.php` (groupe `locks-timing` par répertoire, R-03)
- « deux bonnes réponses simultanées reçoivent des lock_rank distincts sans erreur 1062 »
- « un clic faux et une bonne réponse texte concurrents ne produisent jamais locked et qcm_wrong »

`tests/Feature/Answer/AnswerThrottleTest.php`
- « le limiteur answer est clé sur le siège, jamais sur l'IP »
- « une soumission trop rapide répond 429 traduit sans être comptée »
- « un tiers qui connaît le public_id d'un siège ne consomme pas son budget »

`tests/Feature/Answer/AnswerResponseTest.php`
- « un refus a le même corps quelle que soit sa cause »
- « une acceptation ne contient ni titre ni nature d'appariement »
- La forme de `player.locked` est prouvée par `EventPayloadTest` (C7, R-04).

`tests/Feature/Schema/ModelSerializationTest.php` [existant] : ajouter « SeatInputView ne sort que les données du siège demandeur ».

### 8. Libre pour le rédacteur de 70
- Le texte FR et EN des clés, à une condition : un refus ne suggère **jamais** la proximité (pas de « presque », pas de « pas tout à fait »).
- Le découpage interne en méthodes privées.
- La mise en page de `answer-input.tsx`, en accord avec 90.
- Le chiffrage des lots.
- L'ordre d'écriture des sections.

---

## C11 — Composition du QCM (`round_choice_set`)

### 1. Propriétaire, consommateurs, jalon

| | |
|---|---|
| Propriétaire | **70** : tirage des leurres, composition, permutation, jugement du clic (C10) |
| Consommateurs | **60** (appel à la transition d'ouverture du palier du QCM, envoi ciblé, rejeu à la resynchronisation, annulation dans le cas terminal, titres de la révélation par `DisplayTitleResolver`) · **90** (grille 2×2, attribut `lang`) · **05** (homogénéité) · **30** (constructeur du vivier, PRF) |
| Jalon | **J1** |

### 2. Noms exacts

| FQCN / nom | Statut | Signature |
|---|---|---|
| `App\Actions\Game\ComposeChoiceSets` | [nouveau] | `handle(Round $round, CarbonImmutable $at): bool`. Rend vrai si les quatre propositions existent après l'appel (composées maintenant ou déjà), faux dans le cas terminal. Idempotent. |
| `App\Support\Answers\DecoyPicker` | [nouveau] | `pick(Round $round, Game $game, CarbonImmutable $at): ?DecoyPick` (NULL dans le cas terminal). `ComposeChoiceSets::handle($round, $at)` lui transmet `$at` = instant **théorique** `round.started_at + starts_at_offset_ms(choicesOpenTierIndex(N))`, jamais l'heure d'exécution, et `PoolScope::forDecoys($round, $at)` est construit avec cet instant : les leurres ne dépendent pas du retard d'un job. |
| `App\ValueObjects\Answers\DecoyPick` | [nouveau], `final readonly` | `list<int> $movieIds` (exactement 3, dans l'ordre du tirage), `bool $useOriginalTitle` |
| `App\Support\Answers\ChoicesPresenter` | [nouveau] | `forSeat(RoundPlayer $roundPlayer): ?ChoicesPayload` : ligne de `choices_locale`, permutée pour ce siège |
| `App\ValueObjects\Answers\ChoicesPayload` | [nouveau], `final readonly` | `list<string> $choices` (4), `bool $useOriginalTitle`, `?Locale $lang` ; `toArray(): array{choices: list<string>, useOriginalTitle: bool, lang: string\|null}` |
| `App\Support\I18n\DisplayTitleResolver` | [nouveau], **partagé** | `resolve(Movie $movie, Locale $locale): ResolvedTitle` ; `original(Movie $movie): string`. **Unique implémentation** de la chaîne de repli de 05 ; 60 l'emploie pour la révélation (C7 `RevealMovie`) ; `MovieTitleResolver` n'est pas créé (R-23). |
| `App\ValueObjects\I18n\ResolvedTitle` | [nouveau], `final readonly` | `string $text`, `?Locale $locale` (NULL = `title_original`), `int $rank` (1, 2 ou 3) |
| `App\Enums\OriginalTitleForm` | [nouveau] | `Latin = 'latin'`, `Transliterated = 'transliterated'`, `Native = 'native'` ; `static of(Movie $movie): self`. Test en PCRE, sans `ext-intl` : `title_original` est `Latin` s'il ne contient que `\p{Latin}\p{Common}\p{Inherited}` ; sinon `Transliterated` si `title_original_latin` existe, sinon `Native`. |
| `App\Enums\RoundIncidentReason` | [existant], **+1 cas** | `ChoicesUnavailable = 'choices_unavailable'` (≤ `string(30)`) |
| Contextes de graine | **C3** (registre fermé) | `DrawContext::decoys($sequenceIndex)` (rangs R1-R2), `DrawContext::decoysOriginal($sequenceIndex)` (rangs R3-R4), `DrawContext::qcmOrder($sequenceIndex, $player->public_id)` (permutation) (R-17) |
| PRF seedée | **C3** | `App\Support\Draw\SeededPrf` : `forGame($game)`, `permutation(DrawContext, int)` |
| Vivier | **C2** | `App\Support\Draw\PoolQuery::movies()` sur `PoolScope::forDecoys($round, $at)` et ses variantes (voir § 4) |
| Événement ciblé | **C7** | `seat.choices` sur le canal privé du siège (`player.public_id`) |
| Front | [nouveau] | `resources/js/components/game/choice-grid.tsx`, props `{ payload: ChoicesPayload; disabled: boolean; onChoose: (choice: string) => void }`. Le conteneur porte `lang={payload.lang ?? undefined}`. Type `ChoicesPayload` dans `resources/js/types/answers.ts`. |
| Clés | [nouvelles] | `game.choices.label` (étiquette du groupe), `game.choices.wrong`, `game.choices.invalid` (422) ; préfixe `game.choices.*` enregistré en C15 (R-38) |

### 3. Formes de données

**Charge ciblée**, un siège par message, jamais diffusée au salon. Envoyée par 60 à l'ouverture du palier du QCM (`seat.choices`), et rejouée par `SeatInputView.choices` :
```
{ ...enveloppe de 60 (v, serverNow, gameRef), sequenceIndex,
  choices: [string, string, string, string],   // permutées pour CE siège
  useOriginalTitle: bool,                        // round.choices_use_original_title
  lang: string | null }                          // Locale::bcp47() de la locale EFFECTIVE atteinte ; null si les chaînes sortent de title_original
```
C'est la règle de 05 l.179 amendée (n° 55) : les quatre chaînes, ce seul drapeau et un attribut `lang`. **Aucune** métadonnée par proposition, aucun identifiant de film, aucun index ni drapeau de la bonne réponse. `choice_1..4` et `round.decoy_movie_id_*` ne sortent **que** par `ChoicesPresenter`.

**Lignes écrites**, toutes dans une seule transaction :
- `round.decoy_movie_id_1..3` et `round.choices_use_original_title` ;
- une ligne `round_choice_set` **par locale activée** (`Locale::cases()`), avec :
  - `choice_1` = chaîne du film cible ;
  - `choice_2..4` = chaînes des leurres, dans l'ordre de `decoy_movie_id_1..3` ;
  - `rendered_locale` (**colonne demandée**, E10-03) ;
  - `composed_at = $at` ;
- `round_player.choices_locale = player.locale` et `choices_composed_at = $at`, pour chaque ligne `round_player` de la manche dont `input_state ∈ {open, text_exhausted}`.

### 4. Invariants et garanties

**Instant de composition (n° 57).**
- À la **première composition**, dans la transition de 60 qui ouvre le palier `InputDifficulty::choicesOpenTierIndex(N)` : `T₁` en Facile, `T_N` en Normal. `CatchUpGame` peut jouer le même rôle.
- Jamais au lancement, jamais en Expert.
- `ComposeChoiceSets` prend `round FOR UPDATE`. Si la manche est déjà composée, il ne fait rien et rend vrai.

**Candidats et exclusions (D21).**
- Exclusions **toujours** appliquées (ensemble E, porté par `PoolScope::forDecoys`) : la cible, les films de son `movie_group`, et les films des manches **déjà démarrées** de la partie (`started_at <= $at`, T₁ franchi, C2). **Jamais** les films des manches futures, programmées comprises : les exclure trahirait le tirage.
- Chaque candidat est `published` + `clear`, trié par `movie.id` croissant.

**Échelle de tirage.**

| Rang | Ensemble de candidats (C2) | Filtre de profil | Contexte de permutation (C3) | Mode |
|---|---|---|---|---|
| R1 | vivier du salon : `PoolScope::forDecoys($round, $at)` (thèmes du snapshot, `levels_count ≥ N`, non-répétition si `noRepeatMovies`) | `title_mask_version = Locale::MASK_VERSION` **et** `title_locale_mask` égal à celui de la cible ; si ce masque vaut 0, même `OriginalTitleForm` en plus | `decoys(s)` | normal |
| R2 | catalogue publié : `forDecoys(...)->withThemeIds([])->withFramesPerRound(null)` (non-répétition conservée), moins les candidats déjà examinés | idem | `decoys(s)` | normal |
| R3 | vivier du salon | même `OriginalTitleForm` que la cible | `decoysOriginal(s)`, tirage **repris à zéro** | dégradé (`choices_use_original_title = true`, pour tout le salon) |
| R4 | catalogue publié, non-répétition conservée | idem | `decoysOriginal(s)` | dégradé |

- On passe au mode dégradé (R3) seulement si R1 et R2 ne fournissent pas trois leurres, **ou** si la version du masque de la cible n'est pas la version courante : l'échec tombe du côté visible (10 § 3.2).
- Aucun rang ne lève la non-répétition du salon : D21 ne prévoit que le vivier du salon, le catalogue publié « non-répétition conservée », puis le mode dégradé. Un rang R5 « catalogue sans non-répétition » serait une extension de D21 ; il n'existe pas, et seul le porteur pourrait l'ajouter.

**Tirage dans un rang** (R-17).
- Uniforme, sans remise : les candidats du rang, triés par `movie.id`, sont parcourus dans l'ordre de `SeededPrf::forGame($game)->permutation($context, n)`, `n` = taille du rang.
- Un candidat est **rejeté** si son `movie_group` est déjà pris par un leurre retenu, ou si l'une de ses chaînes rendues (par locale activée, dans le mode courant) donne, par `normalize()`, la même forme que celle de la cible ou d'un leurre retenu dans cette locale.
- Les quatre chaînes sont donc deux à deux distinctes dans chaque locale.

**Rendu d'une chaîne.**
- Mode normal : `DisplayTitleResolver::resolve(film, L)`, soit `movie_title[L]`, puis les autres locales activées par `fallbackRank`, puis `original()`. `original()` rend `title_original_latin` quand `title_original` n'est pas latin et qu'une translittération existe, sinon `title_original`. **Jamais un alias.**
- Mode dégradé : `original()` pour les quatre films et toutes les locales.
- `rendered_locale` de la ligne L = la locale atteinte (rang 1 ou 2), ou NULL au rang 3 ou en mode dégradé.
- Si, à la composition, les quatre films n'atteignent pas la même locale pour une même L (masque périmé), on bascule en mode dégradé.

**Homogénéité (05).** Les mêmes quatre films pour tout le salon ; seules la langue de rendu et l'ordre changent. Aucune traduction à la volée.

**Permutation.**
- Durstenfeld de C3 : `SeededPrf::forGame($game)->permutation(DrawContext::qcmOrder($round->sequence_index, $player->public_id), 4)`.
- Recalculée à chaque envoi, jamais stockée, identique d'un envoi à l'autre.
- `shuffle`, `mt_srand` et `Collection::shuffle` sont interdits (10 § 7.2, C3 § 4).

**Rejeu identique (règle 3).**
- Resynchronisation, rechargement, second onglet et changement de langue rejouent la ligne de `round_player.choices_locale`, figée à la composition, avec le même ordre et le même `lang`, lu dans `rendered_locale`.
- Cas défensif : si `choices_locale` est NULL au moment d'un renvoi alors que les propositions existent, il est posé une fois à `player.locale` et ne bouge plus.

**Destinataires.** 60 pousse `seat.choices` à chaque `round_player` dont `acceptsChoice()` est vrai et dont le siège n'est ni parti ni expulsé (déconnecté compris, R-28).

**Cas terminal (R1 à R4 insuffisants).**
- Aucune ligne `round_choice_set` n'est écrite, les leurres restent NULL, et la méthode rend faux.
- En Normal : les sièges `text_exhausted` passent `attempts_exhausted`, avec `InputClosed` ; toute exhaustion ultérieure dans la manche mène à `attempts_exhausted`.
- En Facile : 60 annule la manche avec `RoundIncidentReason::ChoicesUnavailable`.

**Coût.** Égalité indexée `movie_projection_qcm_idx` + constructeur du vivier. Les titres ne sont lus que pour les candidats examinés. Une fois par manche, jamais par joueur.

### 5. Provenance des valeurs

| Valeur | Source |
|---|---|
| Locales activées, bits, rangs de repli | `App\Enums\Locale` (`cases()`, `maskBit()`, `fallbackRank()`, `MASK_VERSION`) [existant] |
| Graine | `game.draw_seed` |
| Thèmes, non-répétition | `settings_snapshot.themeIds`, `settings_snapshot.noRepeatMovies` ; fenêtre de non-répétition = `PlatformLimits::roomMemoryWindow*()` (C0, C2) |
| N, difficulté de saisie | `game.frames_per_round`, `game.input_difficulty` |
| Instant de composition | `round_tier.starts_at_offset_ms` du palier `choicesOpenTierIndex(N)` |
| Nombre de leurres | 3, cardinalité fixée par le schéma (`decoy_movie_id_1..3`, 10 § 7.4), pas un réglage |

### 6. Exigences et amendements
- **À 10** : E10-03 (colonne `rendered_locale`), E10-07 (`choices_unavailable`), E10-15 (moment et contextes du tirage des leurres), E10-54 (contexte de l'ordre du QCM).
- **Code** : docblock de `RoundFactory::withDecoys()` : « tirés à la première composition (`T₁` Facile, `T_N` Normal) », et non « au lancement » (n° 57).
- **Amendements** : A-06 (00 l.52) ; A-41, A-43, A-44 (05).
- **Aux specs voisines** :

| Spec | Exigence |
|---|---|
| **30** | Satisfaite par C2 et C3 : `PoolQuery::movies()` sur `PoolScope::forDecoys()` et ses variantes ; contextes `decoys`, `decoysOriginal`, `qcmOrder` au registre. |
| **60** | Appeler `ComposeChoiceSets` dans la transition d'ouverture du palier du QCM. Après le commit, pousser `seat.choices` (`ChoicesPresenter::forSeat`) aux destinataires définis au § 4. Rejouer à la resynchronisation. Annuler la manche en Facile dans le cas terminal. |
| **90** | `choice-grid.tsx` : aucune couleur ni ordre qui dépende d'autre chose que la charge. Attribut `lang` sur le conteneur. |

### 7. Tests Pest nommés

`tests/Feature/Answer/DecoyDrawTest.php`
- « tire les leurres dans le vivier du salon au même profil de titre »
- « complète par le catalogue publié en conservant la non-répétition »
- « bascule tout le salon sur title_original à défaut de trois leurres au même profil »
- « en mode dégradé n'associe que des films de même forme de titre original »
- « exclut la cible, son movie_group et les films des manches déjà démarrées »
- « n'exclut jamais le film d'une manche future »
- « est déterministe pour une graine et un sequenceIndex donnés »
- « compose les mêmes leurres quand la transition est rattrapée en retard »
- « ne tire les leurres qu'à la première composition et jamais au lancement »
- « un masque de version périmée fait basculer en mode dégradé »
- « sans trois leurres possibles, ne compose rien et fait passer les sièges text_exhausted en attempts_exhausted »

`tests/Feature/Answer/ChoiceSetCompositionTest.php`
- « compose une ligne par locale activée avec choice_1 égal au film cible »
- « les quatre chaînes sont deux à deux distinctes dans chaque locale »
- « pose choices_locale pour les sièges open et text_exhausted »
- « rejoue exactement les quatre mêmes chaînes après resynchronisation, changement de langue et second onglet »
- « la permutation dérive de draw:qcm:{sequenceIndex}:{publicId} et diffère entre deux sièges »
- « la charge ciblée ne contient que quatre chaînes, le drapeau et lang »
- « lang égale la locale effective atteinte et reste celle de la composition »
- « un clic est jugé par égalité stricte contre choice_1 sans lire answer_key »
- « une chaîne hors des quatre propositions est refusée en 422 sans fermer la saisie »
- « composer deux fois une manche ne crée aucune ligne de plus »

`tests/Feature/Schema/ModelSerializationTest.php` [existant] : garder `$choiceSet->toArray()` sans `choice_1..4` et ajouter « ChoicesPayload ne porte aucun identifiant de film ».

### 8. Libre pour le rédacteur de 70
- La mise en cache des titres lus pendant une composition, dans la seule transaction.
- Le détail des requêtes de chaque rang, s'il respecte les index nommés.
- L'éventuel indice visuel « titres originaux » (propriété de 90 ; aucune clé n'est figée ici).

---

## C12 — `AnswerKeyNormalizer` (normalize, fold, distance), `validation_version`, sous-titre, `near_miss`

### 1. Propriétaire, consommateurs, jalon

| | |
|---|---|
| Propriétaire | **70** |
| Consommateurs | **10** (stockage des formes, projecteur `answer_key`) · **20** (projecteur appelé par la publication, avertissement nominatif étendu aux sous-titres, rapport de collisions, candidats `movie_group` par distance) · **40** (`NicknameNormalizer` bâti sur `fold`) · **50** (`validation_version` au lancement) · **100** (`catalog:reproject`, empreinte en CI) |
| Jalon | **J1** : `normalize` + `Str::transliterate`, chiffres romains, `fold`, `distance`, tolérance, sous-titre (D23), `AnswerRules::VERSION`, `answers:collisions` en console, **outil du porteur, jamais sur le chemin du curateur** (D10). **J2** : alimentation de `near_miss` (D24) et affichage du rapport de collisions en back-office (20). |

### 2. Noms exacts

**`App\Support\Catalog\AnswerKeyNormalizer`** [existant ; même adresse, corps modifié]

| Méthode | Statut | Signature |
|---|---|---|
| `normalize` | [existant], corps modifié | `public static function normalize(string $text): string` |
| `fold` | [nouveau] | `public static function fold(string $text): string` |
| `prefixOf` | [existant] | `public static function prefixOf(string $title): ?string` |
| `subtitleOf` | [nouveau] (D23) | `public static function subtitleOf(string $title): ?string` |
| `compact` | [nouveau] | `public static function compact(string $normalized): string` |
| `digits` | [nouveau] | `public static function digits(string $normalized): list<int>` |
| `distance` | [nouveau] | `public static function distance(string $normalizedA, string $normalizedB): int` |
| `leadingArticles` | [nouveau] (lecture pour l'empreinte) | `public static function leadingArticles(): list<string>` |
| `subtitleSeparators`, `minPrefixLength` | [existants], inchangés | — |

Constantes existantes conservées : `MAX_NORMALIZED_LENGTH = 200`, `DEFAULT_MIN_PREFIX_LENGTH`, `DEFAULT_SUBTITLE_SEPARATORS`, `LEADING_ARTICLES`.

**`App\Support\Answers\AnswerRules`** [nouveau, `final`]
- `public const int VERSION = 1;` — écrit dans `game.validation_version` par `OpenGame` (C6).
- `public const array TOLERANCE_STEPS = [4 => 0, 8 => 1, 15 => 2];` et `public const int MAX_TOLERANCE = 3;`
- `public const int NEAR_MISS_MARGIN = 2;` (J2)
- `public static function tolerance(int $compactKeyLength): int`
- `public static function fingerprint(): string` : SHA-256 d'un JSON canonique de VERSION, séparateurs et longueur minimale (`config/catalog.php`), articles, règle romaine, règle des chiffres, `TOLERANCE_STEPS`, `MAX_TOLERANCE`, règle du sous-titre et `NEAR_MISS_MARGIN`.
- **Pas de `floorTierIndex()`** : le plancher du QCM appartient à `ScoringRules` (C13) et relève de `scoring_version`, pas de `validation_version` (R-18).

**Enums**
- `App\Enums\AnswerKeyKind` [existant] : **+ `Subtitle = 'subtitle'`** (D23, `string(16)`). Méthodes nouvelles `isCollisionChecked(): bool` (vrai pour `Prefix` et `Subtitle`) et `toMatchKind(): GuessMatchKind`. `isExact()` devient `! isCollisionChecked()` (sémantique restreinte, signalée).
- `App\Enums\GuessMatchKind` [existant : `title`, `alias`, `prefix`, `choice`] : **+ `Subtitle = 'subtitle'`** (`string(10)`). Nouveau cas, pour que l'instantané dise quelle règle de collision s'appliquait.

**Projecteur `App\Support\Catalog\AnswerKeyProjector`** [existant, modifié]
- `desiredKeys()` dérive aussi `subtitleOf()` des **titres** : `title_original`, `title_original_latin` et `movie_title` des locales activées. **Jamais d'un alias.**
- Précédence : exacte > `prefix` > `subtitle`.
- `recomputeAmbiguity()` pose `is_ambiguous` sur `key_kind IN ('prefix','subtitle')`.
- Le docblock « cache invalidé depuis `touchedMovieIds()` » est retiré : aucun cache au J1.

**Commandes et tâches**
- `App\Console\Commands\AnswersCollisionsCommand` [nouveau], signature `answers:collisions {--json}`, en lecture seule. **J1.**
- `App\Jobs\Answers\AggregateNearMisses` [nouveau, **J2**], file `default`, `ShouldBeEncrypted`.

**Fixtures** : `tests/Fixtures/answers/normalizer-v1.php` [nouveau], paires entrée → `normalize()` et entrée → `fold()`.

**Clé de traduction** : `game.help.prefix` [brouillon de 70 : `game.help.prefix_rule`, R-38 ; clé figée par C15, texte fourni par 70]. Texte de questions-ouvertes l.331 étendu au sous-titre : « quand plusieurs épisodes d'une saga sont jouables, le titre de la saga seul, ou un sous-titre partagé, ne suffit pas ». L'écran appartient à 90.

### 3. Formes de données

**`normalize(string): string`**. Même fonction pour les clés et pour les saisies (A5). Étapes dans cet ordre :
1. `preg_replace('/[\p{P}\p{S}]+/u', ' ', $text)` [existant ; conserve « WALL·E » → `wall e`] ;
2. **`Str::transliterate($s)`** (défauts : inconnu → `?`), à la place de `Str::ascii()` (n° 61). `voku/portable-ascii`, sans `ext-intl`, alphabet de sortie inchangé, A6 non rouvert ;
3. `strtolower` ;
4. `preg_replace('/[^a-z0-9]+/', ' ')`, `trim` ; rendre `''` si rien ne subsiste ;
5. **chiffres romains**, jeton par jeton : un jeton entièrement fait de `i`, `v`, `x`, valide en notation stricte `^(x{0,3})(ix|iv|v?i{0,3})$` et de valeur 1 à 39, est converti en arabe **si** sa longueur est ≥ 2, **ou si** c'est un jeton d'une lettre placé en **dernière** position d'une chaîne de 2 jetons ou plus. `m`, `d`, `c` et `l` ne sont jamais convertis. La conversion est symétrique, donc sans effet sur l'appariement quand elle est « fausse » (`malcolm 10`) ;
6. retrait d'**un seul** article de tête de la liste fermée `le, la, les, l, un, une, des, du, de, the, a, an` (union des locales activées, appliquée **sans langue**), jamais sur le dernier mot ;
7. `substr(…, 0, 200)` : troncature **symétrique**, assumée (n° 62).

Sortie : `[a-z0-9 ]`, espaces simples, ≤ 200 caractères. La fonction **n'est pas idempotente** (« The The Thing ») et ne doit jamais être réappliquée à une forme normalisée.

**Sorties figées (fixtures v1, mesurées).**

| Entrée | Sortie |
|---|---|
| `ガラスの果樹園` | `garasunoguo shu yuan` (remplace l'attente `''` d'`ImportFilterTest` l.214) |
| `千と千尋の神隠し` | `qian toqian xun noshen yin shi` |
| `기생충` | `gisaengcung` |
| `Ｔｏｋｙｏ` | `tokyo` |
| `Rocky Ⅳ` | `rocky 4` |
| `Rocky V` | `rocky 5` |
| `Saw X` | `saw 10` |
| `X-Men` | `x men` |
| `I, Robot` | `i robot` |
| `Final Fantasy VII` | `final fantasy 7` |
| `Alien³` | `alien3` |
| `Se7en` | `se7en` |
| `Le Fabuleux Destin d'Amélie Poulain` | `fabuleux destin d amelie poulain` |
| `WALL·E` | `wall e` |
| `Straße` | `strasse` |
| `L'Été` | `ete` |

**`fold(string): string`**
- `Str::transliterate`, puis `strtolower`, puis `\s+` → une espace, puis `trim`.
- Sans retrait de ponctuation (`-` et `_` conservés), sans article, sans chiffres romains, **sans troncature** : l'appelant (40, 50) refuse une forme vide ou plus longue que sa colonne (n° 29).
- `fold` **n'entre pas** dans `validation_version`. Ses fixtures vivent avec celles de `normalize`.
- `player.nickname_normalized` n'est **pas** `fold()` seul : c'est `NicknameNormalizer::normalize()` de C5, qui retire en plus tout ce qui n'est pas `[a-z0-9]` (R-39). `saved_config.name_normalized` n'est pas fixé ici : sujet J2, propriété de 50, qui suit l'idiome A6 de 10 (alphabet `[a-z0-9 ]`) sans le rouvrir.

**`prefixOf` et `subtitleOf` (D23).**
- Découpage à la position la plus à gauche parmi `config('catalog.subtitle_separators')`.
- Le préfixe est la partie **avant** le séparateur, le sous-titre la partie **après** (jusqu'au bout de la chaîne).
- Chacun est normalisé et retenu seulement si sa longueur est ≥ `minPrefixLength()` et s'il diffère de `normalize($title)`. Le sous-titre doit aussi différer du préfixe.
- Exemples :
  - « The Lord of the Rings: The Two Towers » → sous-titre `two towers` ;
  - « Le Seigneur des Anneaux : Les Deux Tours » → sous-titre `deux tours` ;
  - « Star Wars : Episode V - L'Empire contre-attaque » → sous-titre `episode v l empire contre attaque`.

**`compact`, `digits`, `distance`.**
- `compact` retire les espaces.
- `digits` rend la suite des entiers de `preg_match_all('/\d+/')`, comparés comme entiers.
- `distance(a, b) = min(levenshtein(a, b), levenshtein(compact(a), compact(b)))`, avec des coûts 1/1/1.

**Tolérance** (utilisée par C10, étape d).
- Une clé `k` est candidate seulement si `digits(s) === digits(k)` (**chiffres stricts** : « shrek 2 » contre « shrek », « rocky 4 » contre « rocky 5 », « alien 3 » contre « aliens » sont refusés).
- Distance maximale `AnswerRules::tolerance(strlen(compact(k)))` : ≤ 4 → 0 ; 5 à 8 → 1 ; 9 à 15 → 2 ; ≥ 16 → 3.

**Rapport `answers:collisions`** (sortie table, ou JSON avec `--json`).
- Une ligne par couple : `{ normalizedA, movieA: {id, titleOriginal}, kindA, normalizedB, movieB, kindB, distance }`.
- Il liste (i) les formes **exactes** partagées par au moins deux films publiés, alias TMDB compris (réponse à Q70-2 sans rouvrir la décision 13), et (ii) les couples de films publiés distincts à distance ≤ tolérance, à chiffres identiques.
- `answers:collisions` est un **outil d'exploitation du porteur** (calibrage du barème, avant la clôture du jalon), hors du chemin de curation. Au J1, aucun geste de curation ne dépend d'une commande artisan (D10, n° 7) : la suppression d'un alias fautif passe par l'écran d'alias de 20 (saisie et suppression manuelles, D24). L'affichage du rapport en back-office reste au J2.

**`near_miss` (J2 seulement, D24).** La règle est complète, la mécanique n'arrive qu'au J2. Au **J1** : aucune tâche, aucun message, aucun interrupteur, et la table reste vide ; les alias se saisissent à la main.
- **Qualification.** Refus texte où l'ensemble des autres films publiés portant `s` est vide, et où il existe une clé `k` de la cible (exacte, ou dérivée non ambiguë) telle que `digits(s) = digits(k)` et `tolerance(k) < distance(s, k) ≤ tolerance(k) + NEAR_MISS_MARGIN`.
- **Alimentation, compatible L4.**
  - Exactement une écriture par refus texte, jamais conditionnelle : `s` est ajouté au tampon de cache de la manche. La durée de vie est au plus `D + R` + une marge, et le tampon ne porte aucun identifiant de joueur.
  - À la clôture de la manche, `AggregateNearMisses` vide le tampon et dédoublonne.
  - Il incrémente ensuite un compteur de cache par `(movie_id, sha256(s))` de 90 jours, **sans identifiant de manche**.
  - Au troisième incrément, la ligne `near_miss` est créée ou mise à jour (`distinct_rounds`, `best_distance`, dates au premier du mois).
  - La tâche intercepte toute exception et n'atterrit jamais dans `failed_jobs`.

### 4. Invariants et garanties
- **Implémentation unique (A5).** `AnswerKeyFactory` et le projecteur délèguent au normaliseur. `PlayerFactory::normalizeNickname()` délègue à `NicknameNormalizer::normalize()` (C5), bâti sur `fold`. `SavedConfigFactory::normalizeName()` reste en l'état jusqu'au J2 (50, idiome A6).
- **Agnostique de la langue.** Aucune locale en paramètre. `answer_key.source_locale` n'apparaît dans aucun `WHERE` de validation (05 l.197).
- **Portabilité.** L'alphabet est replié en PHP ; le verdict est le même en SQLite et en MySQL (A6). Aucune collation, aucun index fonctionnel.
- **Déterminisme.** Tout changement d'étape, de liste, de barème, de règle romaine ou des chiffres, de dérivation du sous-titre, de séparateurs ou de longueur minimale incrémente `AnswerRules::VERSION`, **sans exception** (pas de réécriture de l'empreinte v1). Le plancher du QCM relève de `ScoringRules::VERSION` (C13).
- **Changement de librairie.** Une mise à jour Composer qui change une sortie de translittération fait échouer les fixtures ; elle impose une nouvelle version et une reprojection.
- **`game.validation_version = AnswerRules::VERSION`** est écrit au lancement par `OpenGame` (C6). `GameFactory::VALIDATION_VERSION` est supprimé et la fabrique lit `AnswerRules::VERSION`.
- **Reprojection obligatoire.** Tout changement du normaliseur est suivi de `catalog:reproject` (100, commande [nouvelle] déclarée en C18-bis § 2). Le projecteur travaille par différence, donc les identifiants stables survivent. `guess.answer_key_id` est en `nullOnDelete` et l'instantané reste autosuffisant.
- **Garde de devinabilité.** Aucun film n'est publiable sans au moins une clé exacte non vide (après `Str::transliterate`, cela ne concerne plus qu'un titre fait uniquement de symboles).

### 5. Provenance des valeurs

| Valeur | Source |
|---|---|
| Séparateurs, longueur minimale d'une clé dérivée (préfixe **et** sous-titre) | `config('catalog.subtitle_separators')`, `config('catalog.min_prefix_length')` [existants], inclus dans l'empreinte |
| Articles, règle romaine (1 à 39, ≥ 2 lettres ou dernier jeton), chiffres stricts, `TOLERANCE_STEPS`, `MAX_TOLERANCE`, `NEAR_MISS_MARGIN` | constantes de version (`AnswerKeyNormalizer`, `AnswerRules`), couvertes par `VERSION` et l'empreinte |
| Longueur des formes | `MAX_NORMALIZED_LENGTH = 200` (largeur de colonne, 10 § 1.3) |
| Rétention J2 (tampon, compteur) | configuration de 100, avec des lignes au tableau de 10 § 11.1 (E10-63) |

Aucun réglage d'hôte ici : la validation est identique pour tous les salons (00 principe 10).

### 6. Exigences et amendements
- **À 10** : E10-09, E10-12, E10-24, E10-32, E10-39, E10-63, E10-67.
- **Amendements** : A-07 (00) ; A-46, A-47 (05) ; A-58 (questions-ouvertes) ; A-70, A-77 (CLAUDE.md).
- **Aux specs voisines** :

| Spec | Exigence |
|---|---|
| **100** | Livrer `catalog:reproject` **avant** le premier import de production (D1), ou dans le même déploiement que le lot du normaliseur. Brancher le test d'empreinte en CI. |
| **20** | Avertissement nominatif étendu aux sous-titres rendus ambigus. Affichage du rapport de collisions au J2. Suggestion de candidats `movie_group` par `distance()`. Garde de devinabilité appliquée au geste de publication. |
| **40** | `NicknameNormalizer` bâti sur `fold()` : refus d'une forme vide ou de plus de 20 caractères (D26 reste la règle de caractères). Satisfait par C5. |

**Alignement des fabriques** (code, n° 58)
- `GuessFactory::viaPrefix()` perd l'état « préfixe accepté et ambigu ».
- `forAnswerKey()` autorise `submitted_normalized ≠ clé` quand `edit_distance > 0`, et délègue à `AnswerKeyKind::toMatchKind()`.
- `DemoCatalogueChainTest` (FAIT 9) est étendu au sous-titre.

### 7. Tests Pest nommés

`tests/Feature/Answer/NormalizerTest.php`
- « les sorties figées de la version courante ne bougent pas » (fixtures v1)
- « translittère les écritures non latines et la pleine chasse sans ext-intl »
- « convertit les chiffres romains stricts de deux lettres et plus »
- « ne convertit un I, V ou X isolé qu'en dernier jeton »
- « ne retire qu'un article de tête et jamais le dernier mot »
- « tronque symétriquement les clés et les saisies à 200 caractères »
- « fold conserve articles, tirets et soulignés »

`tests/Feature/Answer/ToleranceTest.php`
- « accepte une faute de frappe sous le barème »
- « garde stricts les titres de quatre caractères compacts ou moins »
- « refuse une suite de chiffres différente »
- « mesure la distance aussi sur la forme compacte »

`tests/Feature/Answer/ValidationVersionFingerprintTest.php`
- « l'empreinte des paramètres de la version courante est figée »
- « game.validation_version est écrite depuis AnswerRules::VERSION au lancement »

`tests/Feature/Catalog/AnswerKeyProjectorTest.php` [nouveau]
- « dérive le sous-titre des titres et jamais des alias »
- « recompte l'ambiguïté des sous-titres comme celle des préfixes »
- « une nature exacte l'emporte sur prefix, qui l'emporte sur subtitle »

`tests/Feature/Answer/SubtitleRuleTest.php`
- « accepte un sous-titre non ambigu »
- « refuse un sous-titre porté par un autre film publié »

`tests/Feature/Answer/CollisionsCommandTest.php`
- « liste les formes exactes partagées, alias TMDB compris, et les paires sous le seuil »

Tests existants modifiés :
- `tests/Feature/Catalog/ImportFilterTest.php` : attente l.214 réécrite (`garasunoguo shu yuan`).
- `tests/Feature/Schema/DemoCatalogueChainTest.php` : FAIT 9 étendu (aucun sous-titre issu d'un alias).

J2 : `tests/Feature/Answer/NearMissTest.php` avec
- « un refus fait exactement une écriture de tampon quelle que soit sa proximité »
- « aucune ligne near_miss avant le troisième couple manche-chaîne distinct »
- « le compteur ne porte aucun identifiant de manche »

### 8. Libre pour le rédacteur de 70
- La liste complète des fixtures, au-delà des seize figées ci-dessus.
- L'optimisation de `answers:collisions` (regroupement par longueur).
- L'ajustement du barème de tolérance **avant la clôture du J1**, par une nouvelle version, sur la foi du rapport.
- Le branchement de la sonde de force brute (requête « manches gagnées au palier 1 après plus de `K` tentatives », `K` = seuil de la configuration des sondes de 100, jamais noté `N`, qui désigne `frames_per_round` ; jointure `round_player.wrong_attempts` × `guess.tier_index`, **jointure `round` et exclusion de `status = 'cancelled'`** (10 § 1.8 L1)).
- Pour le J2 : noms des clés de cache, durées de vie exactes et nom de la tâche de `near_miss`.

---

## C13 — `ScoreCalculator`, `Ranking` et `FinalizeGame`

> Sources normatives appliquées : D18, D20, D22, D25, D29 ; contradictions n° 45, 51, 64, 65, 66, 67 ; question écartée Q80-1 (récapitulatif en texte seul) ; boundary map (« Palier retenu et valeur d'une réponse » → 80 ; « Quatre compteurs » → 40 ; « Contenu de la révélation » → 60 ; « Inspecter une partie » → 20). Les renvois de contrats de la rédaction de 80 sont corrigés (R-41).

### 1. Propriétaire, consommateurs, jalon

| Élément | Propriétaire | Consommateurs | Jalon |
|---|---|---|---|
| `ScoreCalculator` + `ScoringRules` (palier retenu, plancher du QCM, points, version) | 80 | 70 (transaction de verrouillage), 60 (rejeu de chronologie), 100 (matrice de tests), 20 (rejeu, J2) | J1 |
| B_max en pourcentage entier fonction de N, dans `PlatformLimits` | 80 pour la valeur et la règle ; signature et noms figés en C0 (propriétaire de `PlatformLimits`) | 50, 90 (texte d'aide), 100 | J1 |
| `Ranking` (chaîne de départage) + `Scoreboard` (classement intermédiaire, score du siège, podium) | 80 | 60 (charges de révélation, de fin et de resynchronisation), 90 (écrans), 40 (J2, lecture des agrégats) | J1 |
| `FinalizeGame` (gel) + `GameFinalized` | 80 | 60 (fin normale, clôture à 15 min, reprise d'une partie bloquée, `game.ended`), 100 (clôture forcée `stale_game`), 50 (retour au lobby, « Rejouer »), 40 (J2, cache des compteurs) | J1 |
| `ScoreReplayer` | 80 | 100 (test 10 § 7.5), 20 (écran « inspecter une partie », J2) | J1 pour `replay()`, J2 pour `mismatches()` |
| Mode sans score et mode sans gradient | 80 | 50, 90 | Calcul et tests en J1 ; affichage au J2, avec l'onglet Avancé |
| `ScoringRules::waitingPays()` | 80 | 50 (avertissement éventuel) | J2 |
| Valeur de palier affichée en manche (D29) | 80 (fonction `tierValueAt`, texte de la clé), 90 (module et écran) | 60, 90 | J1 |

### 2. Noms exacts

#### 2.1 PHP — espace `App\Support\Scoring` [répertoire nouveau]

```php
namespace App\Support\Scoring;

/** [nouveau] Constante de version de la règle de score, écrite au lancement dans game.scoring_version. */
final class ScoringRules
{
    public const int VERSION = 1;

    /** Ordre normatif de la chaîne de départage (épinglé par le test d'empreinte). @var list<string> */
    public const array TIE_BREAK_CHAIN = [
        'score_desc',
        'correct_answers_desc',
        'total_answer_time_ms_asc',
        'tier_finds_desc',          // paliers 1, 2, …, N−1
    ];

    /** @throws UnsupportedScoringVersion */
    public static function assertSupported(int $version): void;

    /** B_max en pourcentage ENTIER pour N ; v1 = PlatformLimits::speedBonusMaxPercent($framesPerRound). */
    public static function speedBonusMaxPercent(int $framesPerRound, int $version = self::VERSION): int;

    /** Plancher de palier, SEULE implémentation (R-18) : Text → 1 ; Choice → $difficulty->choicesOpenTierIndex($n) ; Choice en Expert → \LogicException. */
    public static function floorTierIndex(
        \App\Enums\GuessSource $source,
        \App\Enums\InputDifficulty $difficulty,
        int $framesPerRound,
        int $version = self::VERSION,
    ): int;

    /** Mode « sans score » : array_sum($settings->tierPoints) === 0. */
    public static function isScoreless(\App\Settings\RoomSettings $settings): bool;

    /** J2 : vrai si, pour un barème donné, P_i + 0 < P_{i+1} + intdiv(P_{i+1} × B_max(N), 100) pour un i. @param list<int> $tierPoints */
    public static function waitingPays(array $tierPoints, int $framesPerRound): bool;
}

/** [nouveau] */
final class UnsupportedScoringVersion extends \DomainException {}

/** [nouveau] Fonction pure : aucune E/S, aucune horloge, aucun aléa. */
final class ScoreCalculator
{
    public static function score(
        int $answeredAtMs,
        \App\ValueObjects\Scoring\TierSchedule $tiers,
        int $tierGraceMs,
        bool $speedBonus,
        int $speedBonusMaxPercent,
        int $scoringVersion,
        int $floorTierIndex = 1,
    ): \App\ValueObjects\Scoring\TierScore;

    /**
     * Seul point d'entrée des appelants (70, rejeu) : il résout toutes les entrées depuis la partie figée.
     * tierGraceMs = $game->tier_grace_ms ; speedBonus = $game->settings_snapshot->speedBonus ;
     * pct = ScoringRules::speedBonusMaxPercent($game->frames_per_round, $game->scoring_version) ;
     * floor = ScoringRules::floorTierIndex($source, $game->input_difficulty, $game->frames_per_round, $game->scoring_version).
     */
    public static function forGuess(
        \App\Models\Game $game,
        \App\ValueObjects\Scoring\TierSchedule $tiers,
        int $answeredAtMs,
        \App\Enums\GuessSource $source,
    ): \App\ValueObjects\Scoring\TierScore;
}

/** [nouveau] Chaîne de départage, fonction pure. */
final class Ranking
{
    /**
     * @param  list<\App\ValueObjects\Scoring\PlayerTally>  $tallies
     * @return list<\App\ValueObjects\Scoring\Standing>   ordre d'affichage normatif (§ 4.4)
     */
    public static function rank(array $tallies, int $framesPerRound, int $scoringVersion): array;
}

/** [nouveau] Côté lecture : totaux depuis la base et charges utiles en données (formes au § 3). */
final class Scoreboard
{
    /** @return list<\App\ValueObjects\Scoring\PlayerTally> */
    public static function tallies(\App\Models\Game $game, \App\Enums\ScoreScope $scope): array;

    /** Portée Publishable. $revealedRound doit être en revealing ou completed, sinon \LogicException. @return LeaderboardPayload */
    public static function leaderboard(\App\Models\Game $game, ?\App\Models\Round $revealedRound = null): array;

    /** Lève \LogicException si round.status ∉ {revealing, completed}. @return list<RoundFinderPayload> */
    public static function roundFinders(\App\Models\Round $round): array;

    /** Portée Own, destinataire unique. @return SeatScorePayload  — { ownScore: int } (R-26) */
    public static function seatScore(\App\Models\Game $game, \App\Models\Player $player): array;

    /** Lit UNIQUEMENT les agrégats figés ; lève \LogicException si game.ended_at est NULL. @return PodiumPayload */
    public static function podium(\App\Models\Game $game): array;
}

/** [nouveau] */
final class ScoreReplayer
{
    public static function replay(\App\Models\Guess $guess): \App\ValueObjects\Scoring\TierScore;          // J1
    /** @return list<array{sequenceIndex: int, publicId: string, stored: TierScore, replayed: TierScore}> */
    public static function mismatches(\App\Models\Game $game): array;                                      // J2 (écran de 20)
}
```

`LeaderboardPayload`, `RoundFinderPayload`, `SeatScorePayload` et `PodiumPayload` sont des `@phpstan-type` déclarés sur `Scoreboard`. Leurs formes figurent au § 3.

#### 2.2 PHP — objets valeur `App\ValueObjects\Scoring` [répertoire nouveau]

```php
final readonly class TierWindow {           // [nouveau]
    public function __construct(public int $tierIndex, public int $startsAtOffsetMs, public int $durationMs, public int $points) {}
    public static function fromRoundTier(\App\Models\RoundTier $tier): self;
    public function contains(int $offsetMs): bool;    // [offset, offset + duration)
    /** @return array{tierIndex: int, startsAtOffsetMs: int, durationMs: int, points: int} */
    public function toArray(): array;
}
final readonly class TierSchedule {         // [nouveau]
    /** @param list<TierWindow> $tiers  indices 1..N contigus, offset₁ = 0, offsetᵢ₊₁ = offsetᵢ + durationᵢ, durée > 0 ; sinon \InvalidArgumentException */
    public function __construct(public array $tiers) {}
    public static function fromRound(\App\Models\Round $round): self;              // lit round_tier trié par tier_index : SEULE source en production
    public static function fromSettings(\App\Settings\RoomSettings $settings): self; // fabriques, tests et parité client ; jamais sur le chemin de score
    public function count(): int;               // N
    public function durationMs(): int;          // D en ms
    public function tier(int $tierIndex): TierWindow;
    public function containing(int $offsetMs): TierWindow;   // \InvalidArgumentException hors [0, D)
}
final readonly class TierScore {            // [nouveau]
    public function __construct(public int $tierIndex, public int $pointsTier, public int $pointsBonus, public int $pointsTotal) {}
    public static function fromGuess(\App\Models\Guess $guess): self;
    public function equals(self $other): bool;
    /** @return array{tierIndex: int, pointsTier: int, pointsBonus: int, pointsTotal: int} */
    public function toArray(): array;
}
final readonly class PlayerTally {          // [nouveau] — jamais sérialisé tel quel
    /** @param array<int, int> $findsByTier  clés 1..N */
    public function __construct(
        public int $gamePlayerId,              // interne : sert au tri stable seulement
        public string $publicId,
        public \App\Enums\GamePlayerStatus $status,
        public ?int $firstRoundNumber,
        public int $roundsPlayed,
        public int $correctAnswers,
        public int $score,
        public int $totalAnswerTimeMs,
        public array $findsByTier,
    ) {}
}
final readonly class Standing {             // [nouveau]
    public function __construct(public PlayerTally $tally, public ?int $rank, public bool $shared) {}
}
```

`RoundTier::containsOffsetMs()` [existant] est **[modifié]** : il délègue à `TierWindow::fromRoundTier($this)->contains()`, pour qu'une seule formule de fenêtre existe.

#### 2.3 PHP — gel, événement, énumération, portées

```php
namespace App\Actions\Game;   // répertoire nouveau
final readonly class FinalizeGame      // [nouveau]
{
    /** Retourne true si CET appel a gelé la partie, false si elle l'était déjà (aucune écriture). */
    public function handle(\App\Models\Game $game, \App\Enums\GameStatus $outcome, \Carbon\CarbonImmutable $endedAt): bool;

    /** min($now, max(game.started_at, game.paused_at, round.started_at + round.duration_ms [status ≠ pending], round.reveal_ends_at, round.cancelled_at)). */
    public static function lastKnownActivity(\App\Models\Game $game, \Carbon\CarbonImmutable $now): \Carbon\CarbonImmutable;
}

namespace App\Events\Game;    // répertoire nouveau
final class GameFinalized implements \Illuminate\Contracts\Events\ShouldDispatchAfterCommit   // [nouveau], jamais diffusé
{
    use \Illuminate\Foundation\Events\Dispatchable;
    public function __construct(public readonly int $gameId, public readonly \App\Enums\GameStatus $outcome) {}
}

namespace App\Enums;
enum ScoreScope   // [nouveau] (énumération pure, non persistée)
{
    case Own;          // manches running, revealing, completed — lu par le siège qui a répondu, et par lui seul
    case Publishable;  // manches revealing, completed — tout score montré à un AUTRE siège
    case Settled;      // manches completed — agrégats figés seulement
    /** @return list<RoundStatus> */
    public function roundStatuses(): array;
}
```

Portées [nouvelles] (attribut `#[Scope]`, même patron que `Guess::counted()` [existant]) :
- `Guess::inScoreScope(Builder $query, ScoreScope $scope)` : `whereIn('round_id', Round::whereIn('status', $scope->roundStatuses())->select('id'))`. `ScoreScope::Own` y est équivalent à `counted()`, qui est conservé (L1).
- `RoundPlayer::inScoreScope(Builder $query, ScoreScope $scope)` : même sous-requête.

**`App\Settings\PlatformLimits`** : définition normative en C0 (R-05). Pour 80 : `speedBonusMaxPercent(int $framesPerRound): int` = `min(SPEED_BONUS_MAX_PERCENT_CAP, intdiv(FULL_PERCENT, N − 1))` → 50, 50, 33, 25 ; `\InvalidArgumentException` hors bornes de N ; constantes `SPEED_BONUS_MAX_PERCENT_CAP = 50` (plafond d'instance, jamais lu en configuration) et `FULL_PERCENT = 100` (unité) ; `toArray()` expose `speedBonusMaxPercent` indexé par N (`{"2":50,"3":50,"4":33,"5":25}`) ; `DEFAULT_SPEED_BONUS_MAX_FRACTION`, `speedBonusMaxFraction()`, son paramètre de constructeur, sa clé `toArray()` et la clé de configuration `game.platform.speed_bonus_max_fraction` sont supprimés. Aucune méthode ne prend un `User`, un `Plan` ou un compte.

**[modifié] — code existant**
- `database/factories/GameFactory.php` : `SCORING_VERSION` est supprimé, la fabrique lit `ScoringRules::VERSION` (et `AnswerRules::VERSION`, `PlatformLimits::drawSubstituteMargin()`, C12 et C3).
- `database/factories/GuessFactory.php` : état [nouveau] `scoredAt(int $answeredAtMs, GuessSource $source = GuessSource::Text)`, qui appelle `ScoreCalculator::forGuess()` sur la manche et la partie rattachées. `atTier()` et `withSpeedBonus()` sont conservés.
- `app/Models/Guess.php` : docblock l.25 corrigé en « au plus `roomSeats()` × min(M + `drawSubstituteMargin()`, |vivier|) lignes » ; l.55-56, « `makeVisible()` après `reveal_ends_at` » devient « publiable dès `round.status = revealing`, via `Scoreboard`, jamais par `toArray()` ».
- `app/Settings/RoomSettings.php` l.27 : `speedBonusMaxFraction` devient `speedBonusMaxPercent` (C0).

#### 2.4 TypeScript [nouveau]

- `resources/js/types/scoring.ts` (dans `WATCHED`) exporte les types miroirs du § 3 : `TierWindow`, `TierScore`, `SeatScore`, `LeaderboardRow`, `Leaderboard`, `RoundFinder`, `RecapEntry`, `PodiumStanding`, `PodiumHighlights`, `Podium`, et `type TitlePacket = RevealMovie` (importé de `types/game-wire.ts`, R-24). Seule déclaration de ces types (R-27).
- La valeur de palier affichée (D29) est la fonction pure `tierValueAt(tiers: readonly TierWindow[], elapsedMs: number): number | null` : `points` du palier dont la fenêtre contient `elapsedMs`, `null` hors de `[0, D)` ; elle ignore la grâce et le bonus. `elapsedMs` vient de l'horloge resynchronisée (60), jamais de l'arrivée d'un événement de frontière. Elle vit dans `resources/js/lib/game/round-timeline.ts` (C16), aux côtés de `currentTier()` ; `resources/js/lib/tier-value.ts` n'est pas créé (R-35).

#### 2.5 Routes, canaux, événements
- **Aucune route nommée** n'est créée par C13. Le podium et le classement passent par les événements et la resynchronisation de C7 (60), l'historique par 40 (J2).
- **Aucun canal ni aucun événement diffusé** n'est créé par C13. 80 fournit des **blocs de données** que C7 insère dans ses messages : `round.revealed` (`finders`, `leaderboard`), `game.ended` (`podium`) et le paquet de resynchronisation (`leaderboard`, `podium`, `self.ownScore`). **C7 fait foi pour les noms et l'enveloppe.** Il n'existe pas de `seat.locked` au J1 (R-21).
- `GameFinalized` est un événement **de domaine**, non diffusé, qui porte un identifiant interne côté serveur seulement.

#### 2.6 Clés de traduction (domaine `game`, FR/EN symétriques, pluriels par `tChoice`, nombres mis en forme par le client)

| Clé | Placeholders | Jalon |
|---|---|---|
| `game.round.tier_value` (D29 ; forme figée par C15, texte par 80) | `:points` | J1 |
| `game.score.points` (tChoice) | `:count` | J1 |
| `game.score.bonus` · `game.score.gained` · `game.score.total` | `:points` | J1 |
| `game.leaderboard.title` · `.rank_shared` · `.unranked` · `.status.left` · `.status.kicked` | — | J1 |
| `game.leaderboard.ordinal.one` · `.two` · `.few` · `.other` (rendu par `Intl.PluralRules({type:'ordinal'})` côté client) | `:rank` | J1 |
| `game.leaderboard.correct_answers` (tChoice) · `game.leaderboard.late_joiner` | `:count` · `:round` | J1 |
| `game.leaderboard.answer_time` · `game.leaderboard.round_delta` | `:duration` · `:points` | J1 |
| `game.podium.title` · `game.podium.completed` · `game.podium.interrupted` | — · `:m` · `:k`, `:m` | J1 |
| `game.podium.rounds_played` (tChoice) | `:count` | J1 |
| `game.podium.highlights.best_answer` · `.fastest_find` · `.none` | `:nickname`, `:title`, `:points` · `:nickname`, `:title`, `:duration` · — | J1 |
| `game.podium.highlights.unfound` (tChoice) | `:count` | J1 |
| `game.recap.title` · `.round` · `.cancelled` · `.nobody` · `.found_by` (tChoice) | — · `:number` · — · — · `:count` | J1 |
| `game.help.scoring.tier_values` · `.speed_bonus` · `.tie_break` · `.no_penalty` · `.cancelled_round` [brouillon de 80 : `game.rules.scoring.*`, R-38] | `:percent` pour `speed_bonus` | J1 |
| `game.podium.scoreless` · `game.help.scoring.scoreless` | — | J2 |

Le serveur ne compose jamais de phrase à partir de ces clés : les pseudos, titres, points et durées transitent en données (05).

### 3. Formes de données (JSON, champ par champ)

Règles communes :
- les entiers restent des entiers ;
- les instants sont en ISO-8601 UTC avec millisecondes ;
- les durées sont en ms ;
- **aucun** `game.id`, `game_player.id`, `player.id`, `round.id`, `guess.id`, `movie_id`, `answer_key_*`, `submitted_normalized`, `match_kind` ni `source` ;
- les sièges sont désignés par `player.public_id` et les manches par `round_number`.

**`TierWindow`** (inclus par C7 dans `RoundTimeline.tiers` ; public, les valeurs de palier sont connues du salon) :
`{ tierIndex: int(1..N), startsAtOffsetMs: int, durationMs: int, points: int(0..1000) }`

**`TierScore`**, **ciblé** : réponse HTTP de soumission (C10) et `SeatInputView.locked` à la resynchronisation :
`{ tierIndex: int, pointsTier: int(0..1000), pointsBonus: int(0..500), pointsTotal: int(0..1500) }`

**`SeatScore`**, **ciblé** (resynchronisation HTTP du seul siège, `SelfState.ownScore` de C7) :
`{ ownScore: int /* portée Own */ }` — le détail de la manche en cours du siège est porté par `SeatInputView.locked` de C10 (R-26).

**`Leaderboard`**, **diffusé au salon** à partir du passage de la manche en `revealing` (`round.revealed`), et présent dans la resynchronisation de tout siège :
```
{
  scoreless: bool,
  roundNumber: int | null,        // manche révélée à l'origine du calcul ; null hors révélation
  rows: [ {
    publicId: string,
    rank: int | null,             // null : solo, ou roundsPlayed = 0
    rankShared: bool,
    score: int,                   // portée Publishable
    correctAnswers: int,
    roundsPlayed: int,
    totalAnswerTimeMs: int,
    roundDelta: int,              // points de la manche révélée, 0 sinon
    status: "playing" | "left" | "kicked",
    firstRoundNumber: int | null
  } ]                             // TOUTES les lignes game_player, sans plafond (n° 67)
}
```
Pas de pseudo ni d'avatar dans ce bloc : le client les lit dans `seats: SeatView[]` de C7 (identité gelée `display_*`).

**`RoundFinder[]`**, **diffusé**, uniquement pour une manche en `revealing` ou `completed`, trié par `lockRank` :
`[{ publicId: string, lockRank: int, tierIndex: int, answeredAtMs: int, pointsTier: int, pointsBonus: int, pointsTotal: int }]`

**`TitlePacket`** = `RevealMovie` de C7 (R-24) : `{ titles: Record<LocaleCode, { text, lang }>, originalTitle, originalTitleLatin, originalLanguage, year }`. Le récapitulatif la réutilise telle quelle ; le client pose `lang` sur chaque fragment.

**`Podium`**, **diffusé au salon** (`game.ended`) après le commit du gel, puis rejoué **à l'identique** dans toute resynchronisation jusqu'à l'archivage du salon (en solo, par prop HTTP) :
```
{
  gameStatus: "completed" | "interrupted",
  mode: "multiplayer" | "solo",
  roundsCompleted: int,          // k (= game.rounds_completed)
  roundsCount: int,              // M
  framesPerRound: int,           // N
  scoreless: bool,
  endedAt: string,               // ISO-8601 UTC ms
  standings: [ {
    ...PlayerIdentity,           // PlayerIdentity::fromGamePlayer() (C5, R-29) : publicId, nickname (display_nickname gelé), masked, avatar
    status: "playing" | "left" | "kicked",
    firstRoundNumber: int | null,
    rank: int | null,            // = game_player.final_rank
    rankShared: bool,
    finalScore: int, correctAnswers: int, roundsPlayed: int, totalAnswerTimeMs: int
  } ],
  recap: [ {                     // manches completed par round_number croissant, plus les annulations NON remplacées ; jamais une manche pending
    roundNumber: int,
    outcome: "completed" | "cancelled",
    titles: TitlePacket | null,  // null si cancelled
    foundCount: int,
    finders: RoundFinder[]
  } ],
  highlights: {                  // D25
    bestAnswer: { publicId: string, roundNumber: int, tierIndex: int, answeredAtMs: int, pointsTotal: int } | null,
    fastestFind: { publicId: string, roundNumber: int, answeredAtMs: int } | null,
    unfoundRoundNumbers: int[]
  }
}
```
Texte seul, sans URL d'image (Q80-1).

**Aucun bloc de C13 ne transite avant la révélation**, à la seule exception de `TierScore` et `SeatScore`, qui sont ciblés vers leur propriétaire. Avant `revealing`, un tiers ne reçoit que `publicId` et `lockRank` (`player.locked` de C7, 10 § 7.6).

### 4. Invariants et garanties

#### 4.1 Calcul d'une bonne réponse (`ScoringRules::VERSION = 1`)
1. `corrected = max(0, answeredAtMs − tierGraceMs)`.
2. `selected = tiers->containing(corrected)`. Si `selected.tierIndex < floorTierIndex`, alors `selected = tiers->tier(floorTierIndex)`. C'est le plancher du QCM : en Normal, un clic reçu dans la grâce de `T_N` n'achète jamais le palier N−1.
3. `P = selected.points`, `d = selected.durationMs`, `t = max(0, corrected − selected.startsAtOffsetMs)`. On a toujours `t ≤ d − 1`.
4. `pointsTier = P`.
5. `pointsBonus = (speedBonus && P > 0 && pct > 0) ? intdiv(P × pct × (d − t), PlatformLimits::FULL_PERCENT × d) : 0`. La division entière arrondit par défaut, sans aucun flottant : à `t = 0`, le bonus vaut exactement `intdiv(P × pct, 100)`, et il ne dépasse jamais cette valeur.
6. `pointsTotal = pointsTier + pointsBonus`. Au plafond (P = 1 000, pct = 50), cela donne 1 500, qui tient en `unsignedSmallInteger`.

Propriétés garanties et testées :
- **pureté et déterminisme** : mêmes entrées, même sortie ;
- **L3** : aucune valeur client ne figure dans la signature ;
- texte libre et clic QCM se notent à l'identique hors plancher ;
- `B_max(N)` vaut 50, 50, 33 et 25 % ;
- **au barème par défaut, attendre la frontière suivante ne rapporte jamais strictement plus, pour tout N** (D22). Les cas N = 3 (300 = 200 + 100, barème par défaut du produit) et N = 5 (500 = 400 + 100) donnent une **égalité exacte** à la frontière 1→2, à t = 0 ; N = 2 et N = 4 restent strictement décroissants.

Préconditions (R-20) : `0 ≤ answeredAtMs < tiers->durationMs() + tierGraceMs` (fenêtre d'acceptation de C10, dont la borne haute suit `ended_at` en cas de fin anticipée), sinon `\InvalidArgumentException` ; la recherche `containing()` porte sur `corrected`, toujours dans `[0, D)`. Un clic QCM avant l'ouverture des propositions est refusé **par 70** avant tout appel. Une version inconnue lève `UnsupportedScoringVersion`.

#### 4.2 Écriture au verrouillage (contrat avec C10)
- 70 appelle `ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $answeredAtMs, $source)` **une seule fois** par bonne réponse, dans la transaction de verrouillage et avant l'`INSERT guess`.
- `answeredAtMs` = `RoundClock::offsetMs($round, ReceptionInstant::of($request))`, capturé à l'entrée de la requête, avant tout verrou.
- Les quatre sorties sont écrites **telles quelles** dans `guess.tier_index`, `points_tier`, `points_bonus` et `points_total`. Aucune autre écriture de ces colonnes n'existe, et aucun recalcul n'a jamais lieu.
- 70 renvoie `TierScore::toArray()` (plus `lockRank`) **au seul siège**.

#### 4.3 Trois lectures du score, jamais confondues (n° 67, L1)

| Portée | Manches | Qui la lit |
|---|---|---|
| `Own` | running, revealing, completed | Le siège lui-même, en ciblé. |
| `Publishable` | revealing, completed | Tout ce qu'un autre siège voit : classement, `finders`, resynchronisation d'un tiers. |
| `Settled` | completed | `FinalizeGame` seulement. |

Les trois excluent `cancelled` (L1). Les points et le palier d'une manche deviennent publics **dès `round.status = revealing`**, jamais avant (n° 65). La révélation ne « fige » aucun score : elle clôt la saisie et rend publiable.

#### 4.4 Chaîne de départage (`Ranking::rank`)
1. `score` décroissant.
2. `correctAnswers` décroissant.
3. `totalAnswerTimeMs` croissant, où `totalAnswerTimeMs = Σ guess.answered_at_ms` **brut** de la portée.
4. `findsByTier[1]`, puis `[2]`, …, jusqu'à `[N−1]`, chacun décroissant ; `tier_index` inclut le plancher du QCM.
5. Place partagée, en classement de compétition : 1, 2, 2, 4.

- Seuls les sièges avec `roundsPlayed ≥ 1` reçoivent un rang. Les autres ont `rank = null` et sont placés après.
- En solo, `rank = null` partout.
- Ordre d'affichage : rang croissant, puis `game_player.id` croissant, en interne et jamais exposé.
- **Aucune égalité de score n'est jamais tranchée par la graine**, ni par `hash_hmac`, `random_*` ou `shuffle` (n° 66).
- Les sièges partis et expulsés restent classés avec leurs points (00 l.127, l.134 ; n° 45).
- Le classement n'a pas de plafond de lignes (n° 67).

#### 4.5 Gel (`FinalizeGame::handle`)
- **Seule écrivaine**, dans `app/`, de `game.ended_at`, du statut final de la partie (`completed` ou `interrupted`), de la valeur finale de `game.rounds_completed`, et des cinq agrégats de `game_player`.
- **Une** transaction. La ligne `game` est prise en `lockForUpdate`. Si `ended_at IS NOT NULL`, l'appel retourne `false` sans rien écrire : il est **idempotent**. `$outcome` doit valoir `Completed` ou `Interrupted`, sinon `\InvalidArgumentException`.
- **Clôture d'office.** Une manche `revealing` passe en `completed`. Une manche `running` passe en `completed` si `started_at + duration_ms ≤ $endedAt`, avec `round.ended_at` posé à cet instant s'il est nul. Sinon, `\LogicException`, car l'horloge d'une manche ne se met jamais en pause. Les manches `pending` restent intactes.
- **Filtre unique** `Settled`, pour chaque `game_player` :
  - `rounds_played` = nombre de lignes `round_player` du siège ;
  - `correct_answers` = nombre de `guess` ;
  - `final_score` = `Σ points_total` ;
  - `total_answer_time_ms` = `Σ answered_at_ms` ;
  - `final_rank` = `Ranking::rank(...)`, forcé à NULL en solo ou si `rounds_played = 0`.
- Après le gel, les quatre premiers agrégats sont **non nuls**, à zéro compris. Invariant testé : `correct_answers ≤ rounds_played`.
- **Une manche `cancelled` n'entre jamais dans `rounds_played`** ni dans aucun agrégat (confirmation demandée par 10 § 15).
- En solo, les manches `revealed` et `skipped` comptent comme jouées et jamais comme bonnes réponses (D18).
- `rounds_completed = COUNT(round WHERE status = completed)`. 60 le maintient à chaque passage en `completed`, `FinalizeGame` le recalcule et l'écrase. Le `k` affiché vaut cette valeur.
- `GameFinalized` est émis **après commit**, uniquement quand l'appel retourne `true`. C'est l'**unique déclencheur** des effets de fin : `game.ended` (60), cache des compteurs (40, J2). Il ne ramène **pas** le salon en `lobby` : le salon reste `playing`, podium compris, jusqu'au « Rejouer » de l'hôte, que `ended_at` non nul rend possible (C6 R5, R-45). Ses écouteurs sont idempotents et tolèrent une partie déjà purgée.
- Le gel n'écrit rien sur `room`, `paused_at` ni `total_paused_ms` : ce sont les données de 50 et 60.
- **Ordre de verrouillage** : `game` puis `round`, jamais l'inverse. La transaction de verrouillage de 70 ne verrouille jamais `game`.

`$endedAt` est fourni par l'appelant :

| Appelant | Valeur de `$endedAt` |
|---|---|
| Fin normale (60) | Instant serveur de la transition de fin de la dernière révélation. |
| Clôture à 15 min (60) | `paused_at` + `EngineConstants::pauseTimeoutMs()` (instant prévu, pas l'instant d'exécution du job). |
| Reprise d'une partie bloquée (60) et clôture forcée `stale_game` (100) | `FinalizeGame::lastKnownActivity($game, now)`. |

#### 4.6 Podium
- `Scoreboard::podium()` lit **uniquement** les agrégats figés. `highlights` et `recap` se dérivent en lecture des `guess` et `round` en portée `Settled`.
- `bestAnswer` : tri `points_total` décroissant, puis `answered_at_ms` croissant, puis `round_number` croissant, puis `lock_rank` croissant. En mode sans score, ce tri redonne mécaniquement la réponse la plus rapide (D25).
- `fastestFind` : tri `answered_at_ms` croissant, puis `round_number`, puis `lock_rank`.
- `unfoundRoundNumbers` : manches `completed` avec `found_count = 0`, le même filtre que la file de curation.
- Le podium reste consultable jusqu'à l'archivage du salon (24 h). Au-delà, seule subsiste la ligne d'historique de 40.

#### 4.7 Version de la règle
`ScoringRules::VERSION` est écrite au lancement dans `game.scoring_version` (C6). **Elle s'incrémente à tout changement de :**
- la table `B_max(N)` ;
- la forme ou l'arrondi du bonus ;
- la règle de sélection du palier (application de la grâce, plancher du QCM) ;
- la chaîne de départage ;
- la définition d'un agrégat figé ou de `rounds_completed` ;
- le défaut de `tierGraceMs` ou de `preloadLeadMs` (10 § 7.2 l.764-768 et § 6.1 l.649), même si ces valeurs sont figées par partie dans `game.tier_grace_ms` et `game.preload_lead_ms` et relues telles quelles au rejeu.

Elle ne s'incrémente pas pour les `highlights`, qui ne sont pas des scores.

Toute incrémentation conserve la branche de la version précédente : le test d'empreinte de chaque version reste vert, et `ScoreReplayer::replay()` d'une partie de version v redonne ses points d'origine.

### 5. Provenance de chaque valeur (règle 2)

| Valeur | Source unique |
|---|---|
| Offsets, durées et valeurs de palier | `round_tier.starts_at_offset_ms`, `duration_ms` et `points`, matérialisés au lancement (60). **Jamais** relus depuis `settings_snapshot` sur le chemin de score. |
| `tier_grace_ms` | `game.tier_grace_ms`, lui-même tiré de `PlatformLimits::tierGraceMs()` au lancement. |
| `speedBonus` | `game.settings_snapshot->speedBonus` (`room_settings`, onglet Avancé, défaut `RoomSettingsBounds::DEFAULT_SPEED_BONUS`). |
| B_max (%) | `ScoringRules::speedBonusMaxPercent(game.frames_per_round, game.scoring_version)`, qui délègue à `PlatformLimits::speedBonusMaxPercent()` (`SPEED_BONUS_MAX_PERCENT_CAP`, constante d'instance). |
| Plancher de palier | `InputDifficulty::choicesOpenTierIndex(game.frames_per_round)` [existant], via `ScoringRules::floorTierIndex()` ; instant d'ouverture du QCM détenu par 70. |
| Version | `game.scoring_version`, écrite depuis `ScoringRules::VERSION`. |
| Chaîne de départage | `ScoringRules::TIE_BREAK_CHAIN`, sous version. |
| Mode sans score | `game.settings_snapshot->tierPoints`. |
| 100 (base du pourcentage), 1 000 (ms par seconde) | Unités, pas des valeurs de jeu : `PlatformLimits::FULL_PERCENT`, et conversion de `TierSchedule::fromSettings()`. |
| Délai de clôture à 15 min | `EngineConstants::pauseTimeoutMs()` (60). 80 ne le lit pas, il reçoit `$endedAt`. |

### 6. Exigences et amendements
- **À 10** : E10-04 (seule demande de type de colonne), E10-14, E10-27, E10-36, E10-38, E10-40, E10-43, E10-44, E10-48, E10-52, E10-62, E10-67. Aucune colonne nouvelle, aucun cas d'énumération persistant et aucun index nouveau ne sont demandés. Les requêtes passent par `round_game_status_idx`, `guess_round_player_uq`, `round_player_round_player_uq` et `game_player_game_idx`.
- **Amendements** : A-03, A-10, A-16, A-19, A-25, A-35 (00) ; A-60 (questions-ouvertes) ; A-68, A-70, A-74 (CLAUDE.md). Aucun amendement de 05.
- **Aux autres contrats** :
  - C6 (50) : `OpenGame` écrit `game.scoring_version = ScoringRules::VERSION`.
  - C0 (50) : noms et signature de `PlatformLimits::speedBonusMaxPercent()` ; `fromInput()` refuse la clé `speedBonusMaxPercent` (clé inconnue = refus dur).
  - C10 (70) : appel décrit au § 4.2.
  - C7 (60) : blocs du § 3 et appelants de `FinalizeGame` du § 4.5.

### 7. Tests Pest qui prouvent le contrat

**`tests/Feature/Scoring/ScoreCalculatorTest.php`**
- `test('une réponse reçue à borne + (tier_grace_ms − 1) retient le palier précédent')`
- `test('une réponse reçue à borne + (tier_grace_ms + 1) retient le palier suivant')`
- `test('le bonus est un plancher entier : P = 100, B_max = 50 %, d = 5 000 ms, t = 1 700 ms donne 33')`
- `test('le bonus vaut exactement intdiv(P × B_max, 100) à t = 0 et ne le dépasse jamais')`
- `test('un palier à 0 rapporte 0, bonus compris')`
- `test('bonus désactivé : seule la valeur du palier compte')`
- `test('B_max vaut 50, 50, 33 et 25 % pour N = 2, 3, 4 et 5')`
- `test('au barème par défaut, attendre la frontière suivante ne rapporte jamais plus, pour tout N et toute durée légale')`
- `test('aux cas N = 3 et N = 5, le début du palier 2 rapporte exactement la valeur du palier 1')`
- `test('un clic QCM en Normal reçu dans la grâce de T_N est crédité au palier N avec t = 0')`
- `test('texte libre et clic QCM se notent à l’identique hors plancher')`
- `test('une version de règle inconnue est refusée')`
- `test('un answered_at_ms hors de [0, D + tier_grace_ms) est refusé')`
- `test('points_total = points_tier + points_bonus et tient en unsignedSmallInteger au plafond')`

**`tests/Feature/Scoring/ScoringRulesTest.php`**
- `test('empreinte de la version 1 : table B_max, chaîne de départage, échantillons d’arrondi')`
- `test('la fabrique de partie écrit ScoringRules::VERSION dans scoring_version')`
- Les tests « B_max jamais lu en configuration », « aucune résolution par compte ni par plan », « B_max en pourcentage entier dans toArray » et « fromInput refuse speedBonusMaxPercent » vivent une seule fois en C0 (`PlatformLimitsTest`, `RoomSettingsContractTest`, R-04).

**`tests/Feature/Scoring/ScoreReplayTest.php`**
- `test('le rejeu de (answered_at_ms, round_tier, tier_grace_ms, settings_snapshot, scoring_version) redonne exactement tier_index et points_total')` : 10 § 7.5, avec cas N = 5 et clic QCM en Normal.
- `test('un changement de configuration de tier_grace_ms après la partie ne change pas le rejeu')`

**`tests/Feature/Scoring/RankingTest.php`**
- `test('ordre : score, puis bonnes réponses, puis temps cumulé, puis trouvailles aux paliers 1 à N − 1')`
- `test('à score égal, le siège qui a le plus de bonnes réponses passe devant')`
- `test('place partagée en classement de compétition 1, 2, 2, 4')`
- `test('aucune graine n’intervient : deux parties aux graines différentes et aux totaux identiques donnent le même classement')`
- `test('partis et expulsés restent classés avec leurs points')`
- `test('un siège sans manche jouée n’a pas de rang')`
- `test('mode sans score : le classement suit la chaîne à partir des bonnes réponses')`

**`tests/Feature/Scoring/LeaderboardVisibilityTest.php`**
- `test('la resynchronisation d’un tiers pendant une manche où A est verrouillé laisse le score de A inchangé')`
- `test('points et palier d’une manche deviennent publics dès revealing, jamais avant')`
- `test('roundFinders refuse une manche running')`
- `test('aucun bloc TierScore, SeatScore, Leaderboard ni RoundFinder ne contient d’identifiant interne, de titre ni de nature d’appariement ; seul Podium.recap porte des titres, après le gel')`

**`tests/Feature/Scoring/FinalizeGameTest.php`**
- `test('le gel écrit ended_at, le statut, rounds_completed et les cinq agrégats dans une seule transaction')`
- `test('un second appel ne réécrit rien et ne réémet pas GameFinalized')`
- `test('une manche cancelled portant deux guess de 400 points ne modifie ni final_score, ni correct_answers, ni rounds_played, ni le rang')` (10 A13)
- `test('correct_answers ≤ rounds_played pour chaque siège, clôture d’office comprise')`
- `test('partie interrompue à la manche k : rounds_completed = k et agrégats gelés')`
- `test('partie interrompue avant toute manche close : agrégats à zéro, aucun rang')`
- `test('retardataire : rounds_played ne compte que ses manches')`
- `test('en solo, final_rank reste nul et les manches revealed ou skipped comptent comme jouées, jamais comme bonnes réponses')`
- `test('une partie bloquée est close à sa dernière activité connue')`
- `test('le gel refuse une manche running dont la durée n’est pas écoulée')`

**`tests/Concurrency/Scoring/FinalizeGameConcurrencyTest.php`** (groupe `locks-timing` par répertoire, R-03)
- `test('deux appels concurrents ne gèlent qu’une fois')`

**`tests/Feature/Scoring/PodiumTest.php`**
- `test('le podium ne lit que les agrégats figés')`
- `test('meilleure réponse : points puis vitesse ; en mode sans score, la plus rapide')`
- `test('film que personne n’a eu : manches completed à found_count = 0')`
- `test('le récapitulatif ne montre jamais une manche pending et montre une annulation non remplacée sans titre')`
- `test('le récapitulatif porte un paquet de titres par locale activée et aucun identifiant interne')`
- `test('le podium d’une partie interrompue porte k et M')`

**`tests/Feature/Architecture/ScoringWritersTest.php`**
- `test('dans app/, seul FinalizeGame écrit game.ended_at et les agrégats de game_player')`
- `test('toute écriture de guess.points_* passe par ScoreCalculator')`
- `test('aucune classe de App\Support\Scoring n’emploie draw_seed, hash_hmac, random_int ni shuffle')`

### 8. Ce qui reste libre pour le rédacteur de 80
- La rédaction des textes FR/EN des clés du § 2.6 : seuls les chemins et les placeholders sont figés.
- Le découpage interne de `Scoreboard` (requêtes, agrégation en PHP ou en SQL) et un éventuel cache court, à condition de respecter les portées du § 4.3.
- La présentation du détail `pointsTier` / `pointsBonus` à l'écran : la décision revient à 90, les données sont fournies.
- Le texte d'aide expliquant barème, bonus et départage (`game.help.scoring.*`), y compris la mention des cas N = 3 et N = 5 à égalité.
- La forme du rapport de `ScoreReplayer::mismatches()` pour l'écran de 20 (J2).
- L'adoption par 50 d'un avertissement fondé sur `waitingPays()` pour les barèmes personnalisés (J2).
- Le traitement textuel du titre d'un film suspendu ou retiré après sa manche dans le récapitulatif. Le défaut proposé le conserve : texte seul, sans image.
- Le mécanisme qui déclenche la reprise d'une partie bloquée (60) et l'ordonnancement de `stale_game` (100). Seule l'action appelée est figée.
- Le nom de l'état de fabrique complémentaire à `scoredAt()` et la matrice exacte de la propriété « attendre ne paie jamais » (pas d'échantillonnage), à condition que les bornes viennent de `RoomSettingsBounds`.
- Les sections « Arbitrages », « Lots d'implémentation » (heures, dont D22 ≈ 2 h, D25 ≈ 3 h, D29 ≈ 1 h) et « Ce que cette spec ne décide pas ».

---

## C14 — Liste fermée `admin_action`, acteurs réservés, nom réel (D12)

### 1. Propriétaire, consommateurs, jalon
- **Propriétaires :**
  - 10 pour le schéma et la liste (boundary map n° 9 : les autres specs formulent des exigences, jamais un ajout direct) ;
  - 20, qui formule les exigences, **écrit** `AdminJournal`, `FirstAdminCommand` et les gestes de catalogue.
- **Consommateurs :**
  - 40 (J2) : masquages, bannissement ;
  - 100 : `site:close` et `site:reopen`.
- **Jalon :**
  - J1 : cas, gardes, `AdminJournal`, `users.real_name`, commande du premier admin ;
  - gestes selon le tableau ci-dessous.

### 2. Noms exacts

`App\Enums\AdminActionType` [existant, 15 cas] : **21 cas**, dont 6 nouveaux.

| Valeur | Cas PHP | Sujet | Motif | Acteur admis | Écrit par | Jalon du geste |
|---|---|---|---|---|---|---|
| `role.changed` | `RoleChanged` | user | facultatif | admin (nom réel), ou `console` pour le 1er admin | 20 | J1 console, J2 écran |
| `movie.published` **[nouveau]** | `MoviePublished` | movie | — | curator+ | 20 | J1 |
| `movie.unpublished` | `MovieUnpublished` | movie | **obligatoire** | curator+ | 20 | J1 |
| `movie.republished` | `MovieRepublished` | movie | — | curator+ | 20 | J1 |
| `movie.content_verified` | `MovieContentVerified` | movie | **obligatoire** | curator+ | 20 | J1 |
| `movie.suspended` | `MovieSuspended` | movie | facultatif | admin | 20 | J2 |
| `movie.unsuspended` | `MovieUnsuspended` | movie | facultatif | admin | 20 | J2 |
| `movie.withdrawn` | `MovieWithdrawn` | movie | **obligatoire** | admin | 20 | J2 |
| `frame.unpublished` **[nouveau]** | `FrameUnpublished` | frame | facultatif | curator+ | 20 | J1 |
| `frame.grid_unpublished` **[nouveau]** (D13) | `FrameGridUnpublished` | frame | **obligatoire** | admin | 20 | J2 |
| `frame.suspended` | `FrameSuspended` | frame | facultatif | admin | 20 | J2 |
| `frame.unsuspended` **[nouveau]** | `FrameUnsuspended` | frame | facultatif | admin | 20 | J2 |
| `frame.withdrawn` | `FrameWithdrawn` | frame | **obligatoire** | admin | 20 | J2 |
| `avatar.hidden` | `AvatarHidden` | user | — (`reports_count`) | `system` | 40 | J2 |
| `avatar.unhidden` | `AvatarUnhidden` | user | facultatif | admin | 40 | J2 |
| `nickname.masked` | `NicknameMasked` | player | — (`reports_count`) | `system` | 40 | J2 |
| `nickname.unmasked` | `NicknameUnmasked` | player | facultatif | admin | 40 | J2 |
| `nickname.banned` | `NicknameBanned` | player | facultatif (provisoire : 40 tranche) | admin | 40 | J2 |
| `takedown.decided` | `TakedownDecided` | takedown_request | **obligatoire** | admin | 20 | J2 |
| `site.closed` **[nouveau]** | `SiteClosed` | **site** | **obligatoire** | `console` | 100 | fixé par 100 |
| `site.reopened` **[nouveau]** | `SiteReopened` | **site** | facultatif | `console` | 100 | fixé par 100 |

Ajouts aux enums :
- `App\Enums\AdminActionSubject` [existant] : cas [nouveau] `Site = 'site'`, avec `subject_id` NULL.
- Méthodes [nouvelles] de `AdminActionType` :
  - `requiresReason(): bool`, qui suit la colonne « Motif » ;
  - `allowsConsoleActor(): bool`, vrai pour `RoleChanged`, `SiteClosed` et `SiteReopened` ;
  - `labelKey(): string`, qui rend `'admin.enum.admin_action.'.str_replace('.', '_', $this->value)`.
- `subject()` [existant] couvre les nouveaux cas.
- `retentionClass()` [existant] : sujet `Site` → `Permanent`. Tous les cas sont donc permanents, comme aujourd'hui.
- `isAutomatic()` [existant] est inchangée.

Constantes :
- `App\Models\AdminAction::CONSOLE_ACTOR = 'console'` : **déplacée** depuis `FirstAdminCommand::CONSOLE_ACTOR`, qui est supprimée ; `FirstAdminCommandTest` est mis à jour.
- `AdminAction::SYSTEM_ACTOR` [existant] est inchangée.

Écrivain unique `App\Support\Admin\AdminJournal` [nouveau] :

```php
public function record(User $actor, AdminActionType $action, ?int $subjectId, ?string $reason = null,
    ?TakedownRequest $takedownRequest = null, ?UserRole $roleBefore = null, ?UserRole $roleAfter = null): AdminAction;
public function recordFromConsole(AdminActionType $action, ?int $subjectId, ?string $reason = null,
    ?UserRole $roleBefore = null, ?UserRole $roleAfter = null): AdminAction;
public function recordAutomatic(AdminActionType $action, int $subjectId, int $reportsCount): AdminAction; // 40, J2
```

Nom réel (D12) :
- **Colonne [nouvelle]** `users.real_name` (E10-02).
- Trait `App\Concerns\RealNameValidationRules::realNameRules(): array` [nouveau].
- Garde `User::saving` [ajout].
- `admin:first-admin` gagne `{--real-name=}` et une invite.

Clés de traduction :

| Famille | Feuilles |
|---|---|
| `admin.enum.admin_action.*` | 21 feuilles, les points remplacés par `_` |
| `admin.enum.admin_action_subject.*` | 6 feuilles |
| `admin.validation.real_name` | — |
| `admin.console.first_admin.*` | `real_name_prompt`, `real_name_required` |

### 3. Formes de données
- Ligne `admin_action` : colonnes de 10 § 8.3 inchangées (`subject_id` déjà nullable, `action` en `string(40)`, `subject_type` en `string(20)`).
- Aucune charge joueur : `actor_name` et `users.real_name` ne sortent **jamais** vers une surface joueur.
- `users.real_name` est `#[Hidden]`, car `HandleInertiaRequests` sérialise l'utilisateur partout.
- Le back-office les compose explicitement dans un présentateur.

### 4. Invariants (garde `AdminAction::creating` et `AdminJournal`)
1. `subject_type` est **dérivé** de `action->subject()`, jamais fourni. Il en va de même pour `retention_class` [existant].
2. `subject_id` est NULL si et seulement si le sujet est `site`.
3. Acteur automatique : `isAutomatic()` ⟺ (`actor_id` NULL ET `actor_name = 'system'`).
4. Acteur console : `actor_name = 'console'` ⟹ `allowsConsoleActor()` ET `actor_id` NULL.
5. Dans tous les autres cas :
   - `actor_id` est non nul ;
   - `actor_name` = **instantané de `users.real_name`**, rogné, non vide, différent de `system` et de `console` sans tenir compte de la casse.
6. Motif : si `requiresReason()`, alors `trim(reason) ≠ ''`.
7. Changement de rôle : `role.changed` ⟹ `role_before` et `role_after` non nuls et différents. Pour toute autre action, les deux sont NULL.
8. Transaction :
   - `AdminJournal` exige `DB::transactionLevel() > 0`, sinon il lève une `LogicException` ;
   - la ligne s'écrit dans la **même transaction** que l'état qu'elle justifie ;
   - l'ajout seul est [existant] (`AppendOnlyBuilder`).
9. Nom réel :
   - un rôle ≥ `curator` exige un `real_name` non vide ;
   - la garde se déclenche quand `role` ou `real_name` change, pour ne pas bloquer un compte existant ;
   - `frame_review.reviewer_name` prend le même instantané ;
   - le nom réel n'est jamais pré-rempli dans `users.name` ni dans `player.nickname` ;
   - à l'anonymisation d'un compte, `users.real_name` est vidé ; les instantanés `reviewer_name` et `actor_name` sont **conservés** (lecture retenue de D12 « conservé après anonymisation », conforme à 10 l.489, R-42).
10. Preuves sans ligne `admin_action` :
    - la publication d'une **frame** est prouvée par sa propre ligne `frame_review` passante (C14-bis, invariant 6) ;
    - la levée d'une suspension de film restaure les frames suspendues par la cascade sans ligne par frame : la ligne `movie.unsuspended` les couvre.
11. Pourquoi `movie.published` : l'état antérieur d'une levée se **reconstitue depuis le journal**. Cela exige que toute transition de disponibilité d'un film y figure, première publication comprise (A2).
12. Première administration :
    - `admin:first-admin` exige un nom réel ;
    - la ligne `role.changed` porte `actor_name = 'console'` et `actor_id` NULL ;
    - l'accès au shell vaut preuve d'adresse sur **les deux chemins**, création et promotion (n° 15) : `--create` devient définitif pour le premier admin.
13. **Code à aligner** (sans quoi la garde `User::saving` lève à la création et les preuves figent encore `users.name`) :
    - `DemoCatalogueSeeder` : l'élévation initiale passe par `recordFromConsole`, les suivantes sont attribuées à l'admin, qui porte un nom réel ;
    - `UserFactory::role()` pose un `real_name` factice non vide quand le rôle est ≥ `curator` (donc `curator()` et `admin()`) ; `anonymized()` vide `real_name` ;
    - `DemoAccountsSeeder::account()` pose `real_name` pour le curateur et l'admin de démonstration ;
    - `FrameReviewFactory::by()` fige `$reviewer->real_name` dans `reviewer_name` ;
    - `AdminActionFactory::byActor()` fige `$actor->real_name` dans `actor_name`, et son docblock (l.27, « instantané de `users.name` ») est corrigé.
14. Le drainage de déploiement (C18-bis) n'écrit **aucune** ligne `admin_action` : ce n'est pas un geste sur un sujet.

### 5. Provenance
- `console` et `system` sont des constantes du modèle.
- Les listes « motif obligatoire » et « console admise » sont des méthodes de l'enum.
- Aucune configuration.

### 6. Exigences et amendements
- **À 10** : E10-02, E10-05, E10-22 (G20 E7, E8, E9).
- **Amendements** : A-67 (CLAUDE.md § 1, D4) ; A-73 (CLAUDE.md § 5 : « admin_action : auteur (**nom réel**), action, sujet, motif, horodatage », D12).

### 7. Tests Pest

**`tests/Feature/Admin/AdminActionTypeTest.php`**
- « la liste fermée compte exactement vingt et un cas »
- « tout sujet movie, frame, takedown_request ou site est permanent »
- « system n'est accepté que pour avatar.hidden et nickname.masked »
- « console n'est accepté que pour role.changed, site.closed et site.reopened, avec actor_id nul »
- « un geste non automatique exige un actor_id »
- « un motif vide est refusé quand l'action l'exige »
- « subject_type est dérivé de l'action »
- « AdminJournal refuse d'écrire hors transaction »
- « chaque cas a sa clé admin.enum.admin_action »

**`tests/Feature/Admin/RealNameTest.php`**
- « un compte ne peut porter curator ou admin sans nom réel »
- « system et console sont refusés comme nom réel »
- « real_name n'est jamais sérialisé »
- « actor_name et reviewer_name figent le nom réel à l'instant du geste »
- « l'anonymisation vide real_name et conserve les instantanés de revue et de journal »
- « les fabriques curator et admin produisent un compte valide sous la garde »

**`tests/Feature/Admin/FirstAdminCommandTest.php`** [existant]
- « la commande exige un nom réel et le pose sur le compte »
- « la ligne role.changed porte console et un actor_id nul »

### 8. Libre
- Écrans du journal.
- Longueur et contenu d'un motif (≤ 500).
- Présentation des acteurs `system` et `console`.
- Motif de `nickname.banned` : c'est 40 qui tranche, par exigence à 10.

---

## C14-bis — Grille d'exclusion versionnée `ExclusionGrid` (D13)

### 1. Propriétaire, consommateurs, jalon
- **Propriétaire :** 20.
- **Consommateurs :**
  - 10 : `frame_review.grid_version` et `answers` ;
  - 100 : test d'empreinte ;
  - 05 : domaine `admin`.
- **Jalon :**
  - J1 : clés, drapeau, forme des réponses, revue ;
  - J2 : geste rétroactif.

### 2. Noms exacts

`App\Support\Curation\ExclusionGrid` [existant], dont la **structure est modifiée**. Le changement est gratuit : aucune ligne `frame_review` n'existe encore.

```php
private const string LANG_PREFIX = 'admin.exclusion_grid.';   // [renommé] depuis 'curation.exclusion_grid.' (n° 6)
/** @var array<int, array{retroactive: bool, items: list<array{slug: string, levels: list<int>}>}> */
private const array VERSIONS = [1 => ['retroactive' => false, 'items' => [/* 9 items existants, inchangés */]]];
public const int CURRENT_VERSION = 1;

public static function labelKey(string $slug, int $version = self::CURRENT_VERSION): string; // '…v{n}.{slug}.label'
public static function helpKey(string $slug, int $version = self::CURRENT_VERSION): string;  // [nouveau] '…v{n}.{slug}.help'
public static function isRetroactive(int $version): bool;                                     // [nouveau]
public static function addedSlugs(int $version): array;                                       // [nouveau] slugs(v) − slugs(v−1), liste
public static function retroactiveLevels(int $version = self::CURRENT_VERSION): array;        // [nouveau] liste de FrameLevel ; vide si non rétroactive
public static function decisionFor(array $answers, FrameLevel $level, int $version): ReviewDecision; // [nouveau]
```

- `versions()`, `isKnownVersion()`, `slugs()`, `slugsFor()`, `appliesTo()`, `isOutdated()` et `missingAnswers()` [existants] restent inchangées.
- **Écart de forme à la résolution n° 6, tracé en R-47 et signalé au porteur** (partagé par 90, C15 § 2.6) : les clés deviennent `admin.exclusion_grid.v{n}.{slug}.label` et `admin.exclusion_grid.v{n}.{slug}.help`, et non `….{slug}` plus `.help`. Dans un tableau de langue PHP, une clé ne peut pas être à la fois une feuille et un nœud. Sans effet de fond.

Revue :

| Méthode | URI | Nom | Action |
|---|---|---|---|
| POST | `admin/catalog/{movie}/frames/{frame}/review` | `admin.catalog.frames.review.store` | `App\Http\Controllers\Admin\FrameReviewController@store` [nouveau] |

- `App\Http\Requests\Admin\FrameReviewStoreRequest` [nouveau].
- `App\Policies\FrameReviewPolicy` [nouveau] : `create` = curator+ ; `update` et `delete` = `false`.

Geste rétroactif (J2) :

| Méthode | URI | Nom | Action |
|---|---|---|---|
| POST | `admin/exclusion-grid/retroactive` | `admin.exclusion_grid.retroactive.store` | `App\Http\Controllers\Admin\ExclusionGridRetroactiveController@store` [nouveau] |

- `App\Http\Requests\Admin\ExclusionGridRetroactiveRequest` [nouveau].
- Garde `can:applyRetroactiveGrid,App\Models\Frame` : admin ET `isRetroactive(CURRENT_VERSION)`.

Clés de traduction :

| Famille | Feuilles |
|---|---|
| `admin.exclusion_grid.v1.<slug>.label` et `.help` | 9 items |
| `admin.review.*` | `stale`, `grid_version_outdated`, `source_mismatch`, `locked` |
| `admin.exclusion_grid.retroactive.*` | `unavailable`, `confirm`, `done` |

### 3. Formes de données

**Props de revue, par frame :**
- `published_hash` (`string(64)`, admin seulement) ;
- `game_url` ;
- `frame_level` ;
- `grid_version` = `CURRENT_VERSION` ;
- `items: { slug, label_key, help_key }[]` = `slugsFor(level)` ;
- `declared_source: { kind: 'tmdb' | 'capture', reference: string }`, où la référence est `tmdb_file_path` ou le timecode `h:mm:ss`, affichée en lecture seule et **confirmée** (A7 confirmé : aucun champ de support, d'édition ni d'outil).

**POST de revue :**

| Champ | Type | Règle |
|---|---|---|
| `grid_version` | int | doit valoir `CURRENT_VERSION`, sinon `admin.review.grid_version_outdated` |
| `reviewed_hash` | char(64) | doit égaler `frame.published_hash` sous verrou, sinon `admin.review.stale` |
| `answers` | objet `{ <slug>: bool }` | exactement les `slugsFor(level, version)` ; `true` = conforme |
| `declared_source_reference` | string ≤ 255 | doit égaler la référence affichée, sinon `admin.review.source_mismatch` |

- Le client **n'envoie jamais** `decision`.

**POST rétroactif (J2) :**
- `version` : int, égal à `CURRENT_VERSION` ;
- `reason` : string ≤ 500, obligatoire ;
- `takedown_reference` : char(12), facultatif.

### 4. Invariants
1. **Une version publiée est figée** : items, niveaux et drapeau `retroactive`. Une modification impose un nouveau slug dans une nouvelle version, et `CURRENT_VERSION` vaut la plus grande clé. Un test d'empreinte SHA-256 de `VERSIONS[1]` la verrouille.
2. **Drapeau `retroactive`.** Il vaut faux par défaut et ne passe à vrai que pour une version qui ajoute un item pour **motif juridique**. Ce motif est cité en commentaire de la version, avec la référence de demande s'il y en a une.
3. **Montée de version prospective par défaut.** Rien ne sort du jeu. La file « à re-revoir » regroupe les frames `availability = 'published'` et `review_grid_version < CURRENT_VERSION`, servies par `frame_grid_version_idx` [existant].
4. **Geste rétroactif (J2) :**
   - frames éligibles : `published` ET `review_grid_version < v` ET `frame_level ∈ retroactiveLevels(v)` ;
   - effet : passage en `unpublished`, puis `AdminJournal::record(admin, FrameGridUnpublished, frame.id, reason, takedown)` ;
   - une transaction par film, sous `lockForUpdate` ;
   - `MovieProjector::recompute` est appelé ;
   - un film publié garde `published` même s'il perd sa couverture 1/3/5 (n° 4), et le back-office le signale « incomplet » ;
   - le geste est **idempotent** : un second passage ne trouve plus rien ;
   - le retour en jeu passe par une nouvelle revue à la version courante.
5. **Décision.**
   - `decisionFor()` rend `passed` si et seulement si l'ensemble des clés est exact et que toutes valent `true` ; sinon `rejected`. Les réponses sont stockées telles quelles dans `frame_review.answers`.
   - `reviewer_name` = `users.real_name` (D12), `reviewer_role` = rôle courant, `reviewed_at` = maintenant serveur.
6. **Revue passante = publication**, dans **une** transaction :
   - insertion de la ligne `frame_review` ;
   - `published_review_id` pointe vers elle ;
   - passage en `availability = published` ;
   - `first_published_at` si NULL, et `availability_changed_at` ;
   - `reviewed_at` et `review_grid_version` : copies de file de travail ;
   - `MovieProjector::recompute`.
   - **Toute** transition d'une frame vers `published` insère ainsi sa propre ligne. Seule une levée admin (`frame.unsuspended` ou `movie.unsuspended`) la rétablit sans nouvelle revue, et seulement si la dernière revue passante porte sur le `published_hash` **et** la version courants. À défaut, la frame retourne en `unpublished`, ou en `draft` si `first_published_at` est NULL (n° 18).
   - Une revue rejetée laisse l'état inchangé.
   - La revue est refusée (`admin.review.locked`) si la frame ou son film est `suspended` ou `withdrawn`, ou si la frame n'est pas `ready`.

### 5. Provenance
- Versions, items et drapeau : code versionné.
- Aucune table, aucune configuration.

### 6. Exigences et amendements
- **À 10** : E10-21, E10-23, E10-68 (G20 E5, E6 ; 10 A7).
- **Amendements** : A-39 (05, domaine `admin`, G20 E13) ; A-59, A-65 (questions-ouvertes).

### 7. Tests Pest

**`tests/Feature/Curation/ExclusionGridTest.php`**
- « chaque item de chaque version a ses clés label et help dans lang/fr/admin.php »
- « les clés vivent sous admin.exclusion_grid »
- « l'empreinte de la version 1 est figée »
- « la version 1 n'est pas rétroactive »
- « retroactiveLevels ne rend que les niveaux des items ajoutés »
- « decisionFor exige exactement les items applicables »

**`tests/Feature/Curation/FrameReviewTest.php`**
- « une revue passante publie dans la même transaction et pointe published_review_id »
- « une empreinte périmée est refusée »
- « une version de grille périmée est refusée »
- « la décision est dérivée côté serveur »
- « frame_review n'est ni modifiable ni supprimable »
- « toute publication de frame insère sa propre ligne de revue »
- « reviewer_name est le nom réel »

**`tests/Feature/Curation/GridRetroactiveTest.php`** (J2)
- « le geste est réservé à l'admin »
- « il n'est offert que si la version courante est rétroactive »
- « il dépublie seulement les frames publiées non re-revues aux niveaux concernés et écrit une ligne frame.grid_unpublished par frame »
- « il est idempotent »
- « un film qui perd sa couverture reste publié »

### 8. Libre
- Rédaction des libellés et des aides de la grille v1.
- Ordre et regroupement de la file de revue.
- Raccourcis clavier de la revue (« Entrée = conforme, publier »).
- Écran du geste rétroactif.
- Taille des lots.

---

## Préambule du groupe 05 / 90 / 100

Conventions de ce groupe :
- « règle N » renvoie à `CLAUDE.md` §7 et « principe N » à `00-overview.md`.
- Les autres contrats sont cités par leur numéro (C0 réglages, C4 `player_token`, C5 pseudo et avatars, C7 canaux et événements, C8 `serve_token`, C9 frame servable, C11 QCM, C17 prédicat « partie en cours »).

Ce groupe ne crée **aucune table, aucune colonne et aucun cas d'enum de schéma**. Ses seuls amendements de `10` sont textuels.

---

## C15 — Domaines et clés de traduction

### 1. Propriété
- **Propriétaire** : `05`, qui porte les amendements ci-dessous.
- **Consommateurs** : 20, 30, 40, 50, 60, 70, 80, 90, 100.
- **Jalon** : J1. Les familles marquées J2 sont réservées : leur nom est figé ici, mais elles ne sont pas écrites au J1.

### 2. Noms exacts

**2.1 Mécanique existante, inchangée**
- `App\Support\I18n\TranslationDomains` [existant] :
  - `BASE = 'common'`, `ADMIN = 'admin'` ;
  - `KNOWN = ['common','game','room','account','legal','mail','admin']` ;
  - `need(string ...$domains): void` lève sur un domaine inconnu ;
  - `selected(): list<string>` rend `['admin']` seul dès que `admin` est demandé.
- `App\Http\Middleware\SelectTranslationDomains` [existant], alias `translations`, s'emploie ainsi : `->middleware('translations:game,room,legal')`.
- `App\Http\Middleware\ForceAdminLocale` [existant], alias `admin.locale`.
- `App\Support\I18n\Translations::flatten(Locale, list<string>): array<string,string>` [existant].
- `App\Console\Commands\LangTypesCommand` (`lang:types`, `--check`) et `LangHashCommand` (`lang:hash`) [existants].
- Côté client : `resources/js/lib/i18n.ts` (`t`, `tChoice`, `translate`, `translateChoice`), `resources/js/hooks/use-translations.ts` (`useTranslations(): Translator`) et `resources/js/types/translations.d.ts` (généré, jamais édité) [existants].

**2.2 Liste close des domaines : inchangée, sept domaines.**

Aucun nouveau domaine :
- pas de `avatar` (n° 34) ;
- pas de `curation` (n° 6) ;
- pas de `deploy`.

**2.3 Règle « `legal` déclaré sur toute route joueur » (n° 68, D3)**

Toute route qui rend une page Inertia **joueur** déclare `legal`, parce que le pied de page est présent sur tous les écrans. Le back-office ne reçoit jamais `legal` : il porte ses propres clés `admin.footer.*`.

| Nom de page Inertia | Coquille (C16) | Domaines déclarés (`common` joint d'office) | Lieu de la déclaration |
|---|---|---|---|
| `welcome` | `PublicLayout` | `legal` | `routes/web.php` [existant] |
| `legal/*` | `PublicLayout` | `legal` | `routes/legal.php` [nouveau, 90] |
| `error` | `PublicLayout` | `legal` | rendu d'exception de `bootstrap/app.php`, par `app(TranslationDomains::class)->need('legal')` |
| `auth/*` | `AuthLayout` | `account`, `legal` | `config/fortify.php` : `'middleware' => ['web', 'translations:account,legal']` [modifié] |
| `settings/*` | `AppLayout` + `SettingsLayout` | `account`, `legal` | les deux groupes de `routes/settings.php` passent à `translations:account,legal` [modifié] |
| `dashboard` [existant] | `AppLayout` (cas explicite, C16 § 2.1) | `account`, `legal` | groupe `auth`, `verified` de `routes/web.php` : `translations:account,legal` [modifié]. Page du starter conservée au J1, cible de `fortify.home` ; son retrait éventuel relève de 40-J2 |
| `game/*` | `GameLayout` | `game`, `room`, `legal` | groupe de `routes/game.php` (50/60) |
| `room/*`, si 50 en crée | `PublicLayout` | `room`, `legal` | `routes/game.php` |
| `admin/*` | `AdminLayout` | `admin` seul | `ForceAdminLocale` [existant] |

Rendu d'erreur :
- l'erreur est rendue par la page `admin/error` (20, domaine `admin`) si `admin` a été sélectionné avant l'exception ;
- sinon par `error` (domaines `common` et `legal`).

Conséquence : un joueur refusé par `role:curator` voit une page d'erreur joueur, puisque `role` s'exécute avant `admin.locale`.

**2.4 Propriété des préfixes de clés [nouveau]**

Ce tableau évite les collisions entre rédacteurs parallèles. Chaque préfixe a un seul rédacteur de texte. Lignes marquées « [ajout, R-38] » : préfixes introduits par les contrats et enregistrés à la fusion.

| Préfixe | Rédacteur | Jalon |
|---|---|---|
| `common.nav.*`, `common.action.*`, `common.state.*`, `common.language.*`, `common.appearance.*`, `common.error.*`, `common.maintenance.*`, `common.connection.*`, `common.home.*` | 90 | J1 |
| `common.avatar.*` | 40 (texte) ; forme des clés figée ici | J1 |
| `common.player.*` (pseudo masqué) | 90, sur la règle de 40 | J2 |
| `common.closure.*` (page de fermeture) | 90 | J2 |
| `game.frame.*`, `game.a11y.*`, `game.help.*` | 90 ; texte de `game.help.prefix` fourni par 70, de la famille `game.help.scoring.*` par 80 | J1 |
| `game.round.*` (chrono, palier, statuts, affichage `game.round.lone_player` à deux joueurs connectés) | 60, sauf `game.round.tier_value` : forme figée ici (D29), texte par 80 | J1 |
| `game.seat.*` (second onglet, expulsion), `game.pause.*`, `game.host.*`, `game.errors.*` [ajout, R-38] | 60 | J1 |
| `game.reveal.*`, `game.solo.*` | 60 | J1 |
| `game.answer.*` (saisie, refus neutre, tentatives) | 70 | J1 |
| `game.choices.*` (QCM) [ajout, R-38] | 70 | J1 |
| `game.leaderboard.*`, `game.podium.*`, `game.recap.*`, `game.score.*` [`game.score.*` : ajout, R-38] | 80 | J1 |
| `room.*` (dont `room.presets.*` [existant], `room.settings.*`, `room.warnings.*`, `room.pool.*`, `room.refusal.*`, `room.join.kicked`) | 50 | J1 |
| `account.*` | 40 | J2 (écrans du starter existants) |
| `legal.*` | 90 | J1 |
| `mail.*` | 20 (retrait), 40 (compte) | J2 |
| `validation.room_settings.*` [existant], `validation.attributes.<champ de réglage>` | 50 | J1 |
| `validation.nickname.*`, `validation.attributes.nickname`, `validation.attributes.avatar` | 40 | J1 |
| `validation.attributes.answer`, `validation.attributes.choice` [ajout, R-38] | 70 | J1 |
| `admin.*` | 20 | J1 |
| `admin.exclusion_grid.*`, `admin.footer.*` | 20 ; forme figée ici | J1 |
| `admin.console.first_admin.*` [existant] | 20 | J1 |
| `admin.console.deploy.*` | 100 (C18-bis) | J1 |

**2.5 Clés nouvelles figées, avec leurs placeholders**

Le texte est libre ; les placeholders sont contractuels.

| Clé | Placeholders / forme | Jalon |
|---|---|---|
| `common.avatar.alt.preset`, `common.avatar.alt.provider`, `common.avatar.alt.initials` | aucun (à côté d'un pseudo, l'image est décorative, `alt=""`, règle de 40) | J1 |
| `common.avatar.preset.{presetKey}`, `presetKey` ∈ `preset-01`…`preset-24` | aucun ; 24 clés FR et 24 clés EN | J1 |
| `common.error.{forbidden,not_found,page_expired,too_many_requests,server_error,service_unavailable}.{title,description}`, `common.error.back_home` | aucun | J1 |
| `common.maintenance.banner`, `common.maintenance.launch_blocked` | aucun ; `launch_blocked` est **l'unique** message du refus de drainage (C6, C7, C18-bis, R-09) | J1 |
| `common.connection.reconnecting`, `common.connection.offline`, `common.connection.restored` | aucun ; seules clés du bandeau de connexion (R-38) | J1 |
| `common.language.changed` | `:language` | J1 |
| `common.nav.menu_description` | aucun (SheetDescription de la coquille mobile) | J1 |
| `common.appearance.{label,light,dark,system}` | aucun (bascule d'apparence de l'en-tête public) | J1 |
| `game.round.tier_value` (D29) | pluriel `tChoice(key, points, { points })` ; `:points` est l'entier formaté par `Intl.NumberFormat(locale)` | J1 |
| `game.frame.loading`, `game.frame.unavailable` | aucun | J1 |
| `game.a11y.halfway`, `game.a11y.last_quarter`, `game.a11y.choices_shown`, `game.a11y.round_ended` | aucun | J1 |
| `game.a11y.seconds_left` | pluriel, `:count` | J1 |
| `game.a11y.tier_opened` | `:index`, `:total` | J1 |
| `game.help.prefix` | aucun | J1 |
| `game.help.scoring.{tier_values,speed_bonus,tie_break,no_penalty,cancelled_round}` ; `.scoreless` au J2 | `:percent` pour `speed_bonus` (C13) ; `game.help.scoring` est un nœud, jamais une feuille (R-38) | J1 |
| `legal.footer.report`, `legal.footer.label`, `legal.footer.sheet_description`, `legal.new_tab`, `legal.provisional`, `legal.contact.unavailable`, `legal.tmdb.logo_alt`, `legal.tmdb.attribution`, `legal.report.title` | aucun | J1 |
| `admin.exclusion_grid.v{n}.{slug}.label`, `admin.exclusion_grid.v{n}.{slug}.help` | aucun ; neuf slugs pour v1 | J1 |
| `admin.footer.{label,notice,terms,privacy,tmdb_attribution,tmdb_logo_alt}` | aucun ; FR seul | J1 |
| `common.player.masked` | `:ordinal` (n° 74) | J2 |
| `common.closure.*` | libre | J2 |

**2.6 Renommages et retraits**
- `App\Avatars\AvatarRef` **[renommé, n° 34]** : les constantes `ALT_KEY_PRESET`, `ALT_KEY_PROVIDER` et `ALT_KEY_INITIALS` passent de `avatar.alt.*` à `common.avatar.alt.*`.
- `App\Support\Curation\ExclusionGrid` **[renommé, n° 6]** : voir C14-bis (préfixe `admin.exclusion_grid.`, suffixe `.label`, `helpKey()`).
- Clés **[brouillon]** remplacées par la fusion (R-38), aucune n'existe dans `lang/` : `game.help.prefix_rule` → `game.help.prefix` ; `game.rules.scoring.*` → `game.help.scoring.*` ; `game.lone_player.notice` → `game.round.lone_player`. Clés abandonnées : `game.connection.*`, `game.errors.draining`, `room.refusal.draining`.
- Retraits du J1 :
  - `common.home.{deploy,description,documentation,intro,title,tutorials}` et `common.nav.{repository,documentation}`, textes et liens du starter ;
  - `validation.attributes.{frames_per_round,rounds_count}`, libellés snake_case sans consommateur (n° 41).
- `App\Http\Middleware\VaryOnLanguage` [existant] : docblock et commentaire de `routes/web.php` corrigés (n° 69). Les routes `legal.*` et `takedown.create` portent `->defaults(VaryOnLanguage::ROUTE_FLAG, true)`.

**2.7 Clés construites à l'exécution**

Une clé composée par gabarit (`` t(`common.avatar.preset.${k}`) ``) est **interdite**, puisque `t()` est typé.
- Toute famille énumérable passe par une table `Record<Valeur, TranslationKey>`, sur le patron de `resources/js/lib/admin-enum-keys.ts` [existant] (cas : `PoolFault`, `PoolRemedyKind`, `RoomRefusal`, `InputState`, avatars prédéfinis).
- Côté serveur, par une méthode `…Key()`, sur le patron de `SettingPresetKey::labelKey()` [existant].

### 3. Formes de données
- **Props partagées existantes, inchangées** :
  - `locale: string` ;
  - `locales: {value, label, bcp47, dir}[]` ;
  - `translations: Record<TranslationKey, string>`, plat, limité aux domaines déclarés.
- Un dictionnaire est un fichier statique du dépôt : il ne transporte **aucune donnée de manche**, donc jamais une réponse (règle 3).
- Un message à destinataire unique est résolu côté serveur. Exemple : le refus de lancement `common.maintenance.launch_blocked`.
- Un message diffusé part en données, et le client formate :
  - `Intl.NumberFormat` et `Intl.DateTimeFormat` dans la locale du joueur ;
  - `fr` forcé au back-office (n° 10).

### 4. Invariants
- Les sept domaines forment une liste close. `need()` lève sur un domaine inconnu. `admin` n'est jamais mêlé à un domaine joueur.
- Symétrie FR/EN des clés et des placeholders pour tous les domaines sauf `admin` ; `mail` est compris.
- Aucune chaîne littérale comme clé dans le code du projet. `lang/*.json` reste réservé au framework et à Fortify.
- Toute clé construite par du code livré est couverte par le test d'énumération (§ 7).
- `translations.d.ts` est régénéré, et la CI refuse toute dérive (`lang:types --check`).

### 5. Provenance
- Les locales viennent de `App\Enums\Locale`.
- Les domaines viennent de `TranslationDomains::KNOWN`.
- Le nombre de clés `common.avatar.preset.*` vaut `PlatformLimits::avatarPresets()`, soit 24.
- Les clés de grille viennent de `ExclusionGrid::versions()` × `slugs($v)`.
- Aucune valeur de jeu n'est en jeu dans ce contrat.

### 6. Exigences et amendements
- **À 10** : aucune.
- **Amendements** : A-37, A-39, A-40, A-42, A-48, A-50 (05) ; A-74 (CLAUDE.md § 6, ligne de lexique « domaine de traduction : liste close de sept ; `legal` sur toute route joueur »).
- **Rappels portés par d'autres contrats** : A-38 (05 l.36, l.55 et l.307, C4) ; A-44 (05 l.179, C11) ; A-46 (05 l.189, C12).

### 7. Tests Pest nommés
- `tests/Feature/I18n/TranslationDomainDeclarationTest.php` :
  - « déclare le domaine legal sur toute route joueur ». Balayage de `Route::getRoutes()` : routes GET ou HEAD du groupe `web`, noms hors `admin.*`, exclusions fermées et nommées dans le test (`well-known.passkeys`, `storage.local`, `frame.serve`, `clock.show`, et par URI la route sans nom `/broadcasting/auth` du framework) ;
  - « n'envoie que le domaine admin au back-office » ;
  - « n'appelle que des clés des domaines déclarés pour son préfixe de page ». Balayage statique : `pages/<préfixe>/**` plus les répertoires de composants rattachés (`components/game` et `components/room` à `game/*` ; `components/public` limité à `common` et `legal` ; `components/state` limité à `common`) ;
  - « rend les pages d'erreur joueur avec le domaine legal et les erreurs du back-office avec le domaine admin ».
- `tests/Feature/I18n/TranslationCoverageTest.php` (extension) :
  - `carries every key built by an enumerable key constructor` [existant, nom conservé] ajoute les clés `ExclusionGrid::labelKey/helpKey` de toutes les versions, les clés `AvatarPresetCatalog`, les trois constantes `AvatarRef::ALT_KEY_*`, les six erreurs HTTP, `ValidNickname::MESSAGE_KEYS`, `RoomRefusal::messageKey()` et une clé par cas de `PoolFault` et `PoolRemedyKind`.
  - Les libellés des 24 prédéfinis sont prouvés par `AvatarPresetTest` (C5) et les clés de grille par `ExclusionGridTest` (C14-bis) : pas de doublon ici (R-04).
- `tests/Feature/Public/LegalPagesTest.php` :
  - « rend le corps légal dans un fragment français quelle que soit la locale du visiteur » ;
  - « garde le lang de html d'une page légale sur la locale du visiteur » ;
  - « fait varier chaque page légale sur Accept-Language ».

### 8. Libre pour les rédacteurs
- Le texte de chaque clé.
- Les sous-clés sous chaque préfixe possédé, hors clés figées en 2.5.
- La forme exacte de `admin/error`.
- L'ordre des clés dans les fichiers.

---

## C16 — Coquilles, conteneur d'image et annonceur `aria-live`

### 1. Propriété
- **Propriétaire** : `90`, dans le socle du J1 (D3).
- **Consommateurs** :
  - 20 : aperçu admin (D8), `admin-sidebar`, `admin-footer` ;
  - 50 : lobby, salon expiré ;
  - 60 : manche, révélation, solo ;
  - 70 : saisie et QCM ;
  - 80 : podium, fonction `tierValueAt` (D29) ;
  - 40 : écrans de compte (J2) ;
  - 100 : périmètre CI.
- **Jalon** : J1.

### 2. Noms exacts

**2.1 Switch de `resources/js/app.tsx` [modifié]**, dans cet ordre :
```tsx
case name === 'welcome':
case name === 'error':
case name.startsWith('legal/'):   return PublicLayout;
case name.startsWith('game/'):    return GameLayout;
case name.startsWith('admin/'):   return AdminLayout;
case name.startsWith('auth/'):    return AuthLayout;
case name.startsWith('settings/'):return [AppLayout, SettingsLayout];
case name === 'dashboard':        return AppLayout;   // page existante du starter, cible de fortify.home
default:                          return PublicLayout;
```

Règles de répartition :
- Toute page nommée `game/*` est forcée en sombre, et elle seule.
- **Le lobby est sous `game/`.** Aucune bascule de thème ne se produit entre le lobby et la première manche.
- Les pages de 50 déjà prévues sont `game/lobby` et `game/room-expired` ; celle de 60 est `game/solo`.
- Le reste suit la préférence du visiteur.

**2.2 Forçage sombre en trois moitiés, et retrait du forçage clair (D8)**
- [nouveau] `App\Http\Middleware\ForceGameAppearance`, alias `game.appearance` :
  - il appelle `View::share('appearance', 'dark')` et `View::share('appearanceForced', true)` ;
  - il est posé sur le groupe de `routes/game.php` qui rend les pages `game/*`.
- Deuxième moitié : l'attribut Blade `data-appearance-forced` de `resources/views/app.blade.php` [existant]. Seul son commentaire est corrigé.
- Troisième moitié : `useForcedAppearance('dark')` (`resources/js/hooks/use-forced-appearance.ts`, [existant]), appelé par `GameLayout`.
- **Retraits (D8)** :
  - `App\Http\Middleware\ForceAdminAppearance` [existant, retiré], l'alias `admin.appearance` dans `bootstrap/app.php` et son usage dans `routes/admin.php` ;
  - l'appel `useForcedAppearance('light')` dans `resources/js/layouts/admin/admin-layout.tsx`.
- Le back-office suit désormais l'apparence choisie.
- [nouveau] `resources/js/components/game/game-theme-scope.tsx` : `GameThemeScope({ children, className }: { children: ReactNode; className?: string })`.
  - Il rend `<div className={cn('dark bg-background text-foreground', className)}>`, une portée sombre **locale** par redéfinition des tokens de `.dark` dans `app.css`.
  - Usage réservé aux cadres de revue et de prévisualisation du back-office (20). Aucun portail (Dialog, Sheet) n'y est ouvert.
- **Aucun SSR (n° 70)** : `config/inertia.php` passe à `'ssr' => ['enabled' => false]`.

**2.3 `GameLayout` [nouveau], `resources/js/layouts/game/game-layout.tsx`**

Export par défaut `GameLayout({ children }: GameLayoutProps)`. Le type `GameLayoutProps = { children: ReactNode }` rejoint `resources/js/types/ui.ts`.

Ce que la coquille contient, de haut en bas :
- `MaintenanceBanner` ;
- `<main id="game-main" className="min-h-0 flex-1">` ;
- une ligne basse contenant **exactement** `LanguageSwitcher iconOnly` [existant] et le déclencheur de `SiteFooter variant="collapsed"` ;
- `GameAnnouncer`, une seule région.

Comportement :
- Hauteur : variable CSS `--game-viewport-height`, tenue par `resources/js/hooks/game/use-visual-viewport.ts` depuis `window.visualViewport`. Le repli est `100dvh`, par la classe `h-[var(--game-viewport-height,100dvh)]`.
- Le « 40 % de hauteur » du principe 5 se mesure sur cette hauteur, clavier ouvert (D5). Viewport minimal déclaré : 360 × 640.
- `resources/js/hooks/game/use-overscroll-lock.ts` pose `overscroll-behavior-y: none` sur `<html>` et `<body>` pendant le montage. Il suit le patron du compteur de module de `useForcedAppearance`, pour résister au double montage de `strictMode`.
- Ni barre latérale, ni fil d'Ariane.

**2.4 `PublicLayout` [nouveau], `resources/js/layouts/public/public-layout.tsx`**

Il se compose de :
- `components/public/public-header.tsx` : nom du site en texte, `LanguageSwitcher` avec libellé visible, et `components/public/appearance-toggle.tsx` (clés `common.appearance.*`) ;
- `MaintenanceBanner` ;
- `<main>` ;
- `SiteFooter variant="full"`.

Les liens de connexion et d'inscription relèvent des interrupteurs de 40 ; ils sont absents en production au J1.

`AuthLayout` et `AppLayout`, deux fichiers du starter, reçoivent aussi `SiteFooter variant="full"`.

**2.5 Conteneur d'image (D7, D8) [nouveau]**

Composant `resources/js/components/game/game-frame.tsx` :
```ts
export type FrameFormat = { width: number; height: number };
export type GameFrameProps = {
  src: string | null;        // URL fournie par le serveur, jamais reconstruite par Wayfinder (R-37) : URL d'objet produite par frame-loader à partir de TierImageRef.url (C7, C8), ou game_url de l'aperçu admin (C9) ; null = rien de servable
  alt: string;               // déjà traduit par l'appelant (game.frame.alt en jeu ; admin.frame.preview.alt en aperçu)
  loadingLabel: string;      // déjà traduit (game.frame.loading en jeu)
  unavailableLabel: string;  // déjà traduit (game.frame.unavailable en jeu)
  format: FrameFormat;       // prop partagée `frameFormat`
  className?: string;
};
```

Aucun appel à `t()` et aucune dépendance à Inertia : c'est ce qui rend le composant réutilisable dans l'aperçu admin.

Cadre et états :
- Le cadre est **neutre, à dimensions fixes** : utilitaire `aspect-frame` (jeton `--aspect-frame: 16 / 9` de C9), largeur pleine (R-37). Aucun style en ligne.
- `<img width={format.width} height={format.height} decoding="async">` en `object-contain`.
- **Aucun LQIP, aucun flou.**
- Sans image chargée, le cadre affiche un aplat `bg-muted`, un `Spinner` et `aria-busy`.
- En cas d'échec, l'aplat reste et `unavailableLabel` s'affiche.
- Au changement de `src`, l'image précédente reste jusqu'au `load` de la suivante : aucun flash d'aplat entre deux paliers.
- Un cadre neuf est monté par manche (`key` fourni par 60).

Anti-recherche inversée :
- `draggable={false}` ;
- `onContextMenu` et `onDragStart` sont empêchés ;
- classes `select-none` et `[-webkit-touch-callout:none]`.

Prop partagée [nouvelle] :
- `HandleInertiaRequests::share()` ajoute `'frameFormat' => ['width' => int, 'height' => int]`.
- Les valeurs viennent de `FrameGeometry::GAME_WIDTH` / `GAME_HEIGHT` (C9), en 16:9 (D5).
- Le type est augmenté dans `resources/js/types/global.d.ts`.

**2.6 Annonceur et chronologie cliente [nouveaux]**
- **Magasin, `resources/js/lib/game/announcer.ts`** :
  - constantes `ANNOUNCER_MERGE_WINDOW_MS = 1000`, `FINAL_ANNOUNCEMENT_CAP_MS = 5000`, `HALFWAY_DIVISOR = 2`, `LAST_QUARTER_DIVISOR = 4`, `LAST_TENTH_DIVISOR = 10` ;
  - `announce(message: string): void` ;
  - `subscribeAnnouncements(listener: () => void): () => void` ;
  - `currentAnnouncement(): readonly string[]` ;
  - `resetAnnouncer(): void`.
- **Région, `resources/js/components/game/game-announcer.tsx`** :
  - `GameAnnouncer()` rend **une seule** région par page de jeu : `<div role="status" aria-live="polite" aria-atomic="true" className="sr-only">` ;
  - un `<span>` par message fusionné ;
  - lecture par `useSyncExternalStore`.
- **Chronologie, `resources/js/lib/game/round-timeline.ts`** — **seule** implémentation client du palier courant et de sa valeur (D29, R-35) :
```ts
import type { RoundTimeline } from '@/types/game-wire';   // charge de C7
import type { TierWindow } from '@/types/scoring';        // C13
export type LiveRoundTimeline = {   // [brouillon] « RoundTimeline » de la rédaction de 90, pour ne pas masquer le type de C7 (R-35)
  key: string;                 // `${gameRef}:${sequenceIndex}`, clé opaque, jamais un identifiant interne
  startedAtMs: number;         // parseIsoMs(RoundTimeline.startsAt), epoch ms UTC (instant serveur)
  durationMs: number;          // round.duration_ms (D)
  tiers: readonly TierWindow[];
  closed: boolean;             // vrai dès l'événement serveur de clôture (round.closed, C7)
};
export function toLiveTimeline(gameRef: string, round: RoundTimeline, closed: boolean): LiveRoundTimeline;
export function currentTier(t: LiveRoundTimeline, serverNowMs: number): TierWindow | null;
export function tierValueAt(tiers: readonly TierWindow[], elapsedMs: number): number | null; // fonction de 80 (C13) : points du palier contenant elapsedMs, null hors [0, D), sans grâce ni bonus
export function announcementThresholds(t: LiveRoundTimeline): ReadonlyArray<{ id: string; atMs: number; kind: 'halfway'|'last_quarter'|'last_tenth'|'tier' ; tierIndex?: number }>;
```
- **Hook, `resources/js/hooks/game/use-round-announcements.ts`** : `useRoundAnnouncements(timeline: LiveRoundTimeline | null, serverNow: () => number): void`.
- **Affichage de la valeur du palier (D29)** : `currentTier()` / `tierValueAt()` sont la seule source. 60 rend `tChoice('game.round.tier_value', tier.points, { points: fmt(tier.points) })`.

**2.7 Pied de page [nouveau]**
- `resources/js/components/public/site-footer.tsx` : `SiteFooter({ variant }: { variant: 'full' | 'collapsed' })`.
  - Liens, par Wayfinder : `legal.notice`, `legal.terms`, `legal.privacy` et `takedown.create`.
  - Il intègre `TmdbAttribution`.
- `resources/js/components/public/tmdb-attribution.tsx` : `TmdbAttribution()` affiche `legal.tmdb.attribution` et `<img src="/brand/tmdb.svg" alt={t('legal.tmdb.logo_alt')}>`. Il est réutilisé sur l'écran de révélation (60).
- **Variante `collapsed`** : une ligne ; un bouton `legal.footer.label` ouvre un `Sheet side="bottom"`.
  - Titre `legal.footer.label`, description `legal.footer.sheet_description` en `sr-only`.
  - Liens `target="_blank" rel="noopener"`, avec `legal.new_tab` en `sr-only`.
  - Jamais de visite Inertia : la partie n'est jamais quittée.
- **Routes de 90 (`routes/legal.php`, [nouveau], requis par `web.php`)** :
  - `GET /legal/notice` → `legal.notice` ;
  - `GET /legal/terms` → `legal.terms` ;
  - `GET /legal/privacy` → `legal.privacy` ;
  - `GET /report-content` → `takedown.create` : page statique au J1, sans formulaire ;
  - J2 : `POST /report-content` → `takedown.store`, avec `throttle:takedown`.
- Pied du back-office : `resources/js/components/admin/admin-footer.tsx` (20), sur les clés `admin.footer.*`.

**2.8 Composants d'état [nouveaux]**

Chacun reçoit des chaînes déjà traduites.

| Fichier | Signature |
|---|---|
| `resources/js/components/state/loading-state.tsx` | `LoadingState({ label })` |
| `resources/js/components/state/empty-state.tsx` | `EmptyState({ title, description?, action? })` |
| `resources/js/components/state/error-state.tsx` | `ErrorState({ title, description?, onRetry?, retryLabel? })` |
| `resources/js/components/state/read-only-notice.tsx` | `ReadOnlyNotice({ message })` |
| `resources/js/components/game/connection-banner.tsx` | `ConnectionBanner({ state }: { state: 'connected' \| 'reconnecting' \| 'offline' })` |
| `resources/js/components/public/maintenance-banner.tsx` | `MaintenanceBanner()` |

- `ConnectionBanner` est monté par la page de 60, avec l'état fourni par 60 ; ses textes sont `common.connection.*` (R-38).
- `MaintenanceBanner` lit la prop `maintenance` de C18-bis.

**2.9 Liste close des composants de présentation**
- **Installés** (`components/ui/*`) : alert, avatar, badge, breadcrumb, button, card, checkbox, collapsible, dialog, dropdown-menu, icon, input-otp, input, label, navigation-menu, placeholder-pattern, select, separator, sheet, sidebar, skeleton, sonner, spinner, table, tabs, textarea, toggle-group, toggle, tooltip.
- **À installer au J1**, par la CLI shadcn en style new-york, jamais édités ensuite : `slider`, `switch`, `progress`, `scroll-area`, `radio-group`.
- **À installer au J2** : `popover`, `command`.
- **Refusés** : `form`, qui doublerait `<Form>` d'Inertia (n° 42) ; toute autre bibliothèque d'interface ou d'animation.
- Les composants de `components/{game,room,public,state}` ne composent que :
  - ces primitives ;
  - les composants propres listés en 2.3 à 2.8, plus `player-avatar.tsx` et `avatar-picker.tsx` (C5), `answer-input.tsx` (C10) et `choice-grid.tsx` (C11) ;
  - les icônes lucide ;
  - les tokens.
- **Composants découplés** : un composant de `components/game/` ne lit ni Echo ni horloge. Il reçoit des props, et souscriptions comme horloges vivent dans `hooks/game/` et `lib/game/`.
- **Règle de coquille mobile (dette REPRISE n° 24)** :
  - ne jamais s'appuyer sur le `Sheet` mobile intégré de `ui/sidebar` ;
  - toute coquille mobile compose son propre `<SheetContent>` avec un `SheetTitle` et une `SheetDescription` traduits ;
  - côté joueur, les clés sont `common.nav.menu` [existante] et `common.nav.menu_description` ;
  - côté back-office (20), `admin.a11y.nav_mobile` et `admin.a11y.nav_mobile_description`.

**2.10 Tokens**
- Ajouts du J1 : `--success` et `--success-foreground` sur `:root` et `.dark`, plus `--color-success` et `--color-success-foreground` dans `@theme` (`resources/css/app.css`), pour le badge « a trouvé » ; `--aspect-frame: 16 / 9` (C9).
- L'urgence du chrono n'est jamais portée par la seule couleur.
- **Marques tierces (n° 72)** : fichiers officiels statiques dans `public/brand/` (J1 : `tmdb.svg`, avec `public/brand/LICENSE.md`), rendus par `<img>`, jamais en SVG en ligne dans un fichier surveillé.

**2.11 Périmètre `WATCHED` de `scripts/check-theme-tokens.mjs` [existant]**

Les entrées existantes restent :
- `pages/admin`, `components/admin`, `layouts/admin` ;
- `lib/admin-catalog-query.ts`, `lib/admin-enum-keys.ts`, `lib/admin-format.ts`, `lib/roles.ts` ;
- `hooks/use-forced-appearance.ts`, `types/admin.ts`.

Ajouts du J1, tous sous `resources/js/`, dès leur création :
- `pages/game`, `pages/room`, `pages/legal`, `pages/error.tsx` ;
- `pages/welcome.tsx`, à sa réécriture en accueil ;
- `layouts/game`, `layouts/public` ;
- `components/game`, `components/room`, `components/public`, `components/state` ;
- `hooks/game`, `lib/game` ;
- `components/language-switcher.tsx`, `app.tsx` ;
- [ajout de fusion, R-36] fichiers nominatifs créés par les autres contrats hors de ces répertoires : `types/game-wire.ts`, `types/answers.ts`, `types/scoring.ts`, `types/pool.ts`, `types/player.ts`, `types/room-settings.ts`, `lib/room-settings.ts`, `lib/frame-geometry.ts`.

**Méta-vérification [nouveau]** :
- Tout fichier `.ts` ou `.tsx` sous `resources/js` est soit sous un chemin `WATCHED`, soit dans `EXEMPT`, sinon la règle `[unclassified]` fait échouer le script.
- `EXEMPT` est la liste nominative des fichiers du starter non surveillés au commit de gel. Elle **ne fait que décroître** : un fichier en sort quand il est réécrit, aucun n'y entre.
- Sont exemptés en permanence : `components/ui/**`, `routes/**`, `actions/**`, `wayfinder/**` et `types/translations.d.ts`.
- Seule exception hors script, non balayée car en Blade : le style anti-flash en ligne de `app.blade.php`.

**2.12 Règles de focus**
- À la révélation, le focus va au titre de révélation (`tabIndex={-1}`).
- À l'ouverture de manche, il va au champ de saisie, ou au premier bouton du QCM en Facile.
- L'apparition du QCM en Normal (à `T_N`, et aussi pour l'état « texte épuisé, QCM attendu », D20) est annoncée par `game.a11y.choices_shown` **sans déplacer le focus**.
- Au podium, le focus va au titre du podium.
- Cibles tactiles `min-h-11 min-w-11`, QCM en `grid grid-cols-2`.

### 3. Formes de données
- **Prop partagée `frameFormat`** : `{ width: int, height: int }`. Elle est globale et identique pour tous, sans aucune donnée de manche.
- **Prop partagée `maintenance: boolean`** : voir C18-bis.
- **`LiveRoundTimeline`** est une structure **cliente**, construite par `toLiveTimeline()` depuis `RoundTimeline` de `round.scheduled` (C7), **diffusé au salon**.
  - La charge de 60 porte, pour chaque palier, `tierIndex`, `startsAtOffsetMs`, `durationMs` et `points` (`TierWindow`), plus `startsAt` (ISO-8601 UTC) et `durationMs` : l'exigence de 90 est satisfaite par C7.
  - `RoundTimeline` ne porte jamais `frame_id`, `frame_level`, `movie_id` ni `round.id`. Les URL d'images voyagent à part, en `TierImageRef` : une URL transmise avant sa garde porte `fetchNotBefore` et reste refusée par le serveur jusqu'à `Tᵢ − preload_lead_ms` (C8, R-34).
  - Ces champs ne révèlent rien : le barème est un réglage public figé au lancement.
- `GameFrame` ne reçoit qu'une URL, déjà filtrée par `ServeGuard` (C8), ni chemin, ni identifiant (10 § 1.1).
- Les annonces restent dans le navigateur : rien ne part au serveur.

### 4. Invariants
- Chaque page `game/*` porte les trois moitiés du forçage sombre.
- Aucune page hors `game/*` n'est forcée. Le back-office n'est jamais forcé : seul un sous-arbre `GameThemeScope` y est sombre (D8).
- **Une seule** région `aria-live` par page de jeu.
- Seuils d'annonce, en ms entières :
  - mi-manche : `D − ⌊D/2⌋` ;
  - dernier quart : `D − ⌊D/4⌋` ;
  - dernier dixième : `D − min(⌊D/10⌋, FINAL_ANNOUNCEMENT_CAP_MS)` ;
  - chaque palier : `startsAtOffsetMs` pour `i ≥ 2`.
- La **fin** n'est annoncée qu'au passage de `closed` à vrai, donc sur l'événement serveur, jamais par minuteur client (règle 1 et règle 8 reformulée, n° 74). La fin anticipée « saisie close » passe par le même chemin.
- Fusion des annonces :
  - une fenêtre de `ANNOUNCER_MERGE_WINDOW_MS` s'ouvre à la première annonce en attente ;
  - toute annonce reçue avant sa fermeture la rejoint ;
  - deux annonces séparées d'exactement 1 000 ms ne fusionnent pas.
- Un seuil déjà passé au montage (resynchronisation, arrivée en cours de manche) n'est jamais annoncé rétroactivement.
- Chaque seuil est annoncé une seule fois par couple (`timeline.key`, `id`). Le registre des seuils déjà annoncés vit au niveau du module, pour résister au double montage.
- L'annonceur, la chronologie et `game.round.tier_value` **ne décident rien** : ils n'écrivent rien, ne soumettent rien et n'influencent aucun score.
- Aucun fichier `WATCHED` ne contient de couleur littérale, de valeur en `px` ni de variante `dark:`.
- Aucun SSR.

### 5. Provenance

| Valeur | Source |
|---|---|
| Ratio et dimensions | `FrameGeometry` (C9), via `frameFormat` et le jeton `--aspect-frame` |
| `D`, offsets, durées et valeurs de palier | `round` et `round_tier`, matérialisés au lancement depuis `room_settings` (C0) |
| Horloge | `serverNow()` resynchronisée de 60 (`lib/game/server-clock.ts`) |
| Diviseurs, plafond de 5 s, fenêtre de 1 s | **constantes de présentation du principe 8**, dans le seul `lib/game/announcer.ts` |
| Tokens | `resources/css/app.css` |

Les constantes de présentation ne sont pas des valeurs de jeu au sens de la règle 2 : elles ne touchent ni palier, ni score, ni chrono. Aucune valeur de jeu n'est écrite en dur.

### 6. Exigences et amendements
- **À 10** : aucune colonne ; textes E10-13 (aucun SSR) et E10-60 (aplat au token, jamais un LQIP, D7).
- **Amendements** : A-02, A-23, A-30, A-32, A-36 (00) ; A-51, A-62 (questions-ouvertes) ; A-71, A-73, A-75 (CLAUDE.md) ; A-80 (REPRISE, dette n° 24 tranchée par la règle de coquille mobile).

### 7. Tests nommés

**Pest**
- `tests/Feature/Public/ShellTest.php` :
  - « rend le lobby en sombre quelle que soit l'apparence du visiteur »
  - « garde le tableau de bord du starter dans AppLayout »
  - « laisse le back-office suivre l'apparence du visiteur »
  - « ne marque jamais une page publique comme d'apparence forcée »
  - « garde le rendu côté serveur désactivé »
- `tests/Feature/Public/SiteFooterTest.php` :
  - « envoie les clés du pied de page à toute page joueur »
  - « envoie au back-office les clés du pied admin et aucune clé legal »
- `tests/Feature/Public/SharedPropsTest.php` : « partage le format d'image fixe en deux entiers »
- `tests/Feature/Public/ComponentListTest.php` :
  - « n'installe que la liste close des composants de présentation »
  - « n'installe jamais l'enveloppe react-hook-form »
- `tests/Feature/Public/ThemeTokensPerimeterTest.php` : « surveille chaque répertoire joueur créé depuis le jalon 1 »

**Vitest**, sous `tests/Frontend/`, voir C18
- `tests/Frontend/game/round-timeline.test.ts` :
  - « rend le palier dont la fenêtre contient l'instant serveur »
  - « rend null avant le début et après D »
  - « tierValueAt rend la valeur entière du palier courant, sans grâce ni bonus » (D29, fonction de 80)
  - « calcule en millisecondes entières les seuils de mi-manche, de dernier quart et de dernier dixième plafonné »
- `tests/Frontend/game/announcer.test.ts` :
  - « fusionne en une seule les annonces séparées de moins d'une seconde »
  - « garde séparées deux annonces distantes d'exactement une seconde »
  - « ne rejoue jamais un seuil déjà annoncé après un remontage »
  - « saute les seuils déjà passés à la resynchronisation »
  - « n'annonce la fin que lorsque le serveur clôt la manche »

### 8. Libre pour 90 et les consommateurs
- Mise en page interne des écrans de 50, 60, 70 et 80, dans le respect des composants et tokens ci-dessus.
- Taille d'affichage de l'aperçu admin (20).
- Présence et contenu du `Skeleton` dans l'aplat.
- Forme de la page `error` et des pages `legal/*`, sous ces contraintes :
  - corps en partiel Blade FR `resources/views/legal/{page}.fr.blade.php` dans `<div lang="fr">` ;
  - contenu venu du dépôt seulement ;
  - bandeau `legal.provisional` piloté par `config/legal.php`.
- Valeurs `oklch` de `--success`.

---

## C17 — Prédicat « partie en cours »

### 1. Propriétaire, consommateurs, jalon
- **Propriétaire** : 60.
- **Consommateurs** : 100 (`deploy:guard`, `deploy:drain`, hook du déploiement manuel D31), 50 (garde de lancement et de « Rejouer » sous drapeau de drainage, via `OpenGame`), 60 lui-même (démarrage solo sous drapeau).
- **Jalon** : J1 (D1, D31, D32).

### 2. Noms exacts
- **Scope** [nouveau] sur `App\Models\Game` [existant] : `#[Scope] protected function inProgress(Builder $query): void`, soit `whereNull('ended_at')->whereIn('status', [GameStatus::Running, GameStatus::Paused])`.
- **`App\Support\Game\GamesInProgress`** [nouveau, `final`] :
  - `count(): int` ;
  - `summary(): list<array{mode: string, status: string, startedAt: string, roundsCompleted: int, roundsCount: int}>`, pour la console du porteur, sans aucune donnée de joueur ;
  - `maxNaturalDurationMs(): int`.
- **Commande** [nouvelle] `App\Console\Commands\GameRescheduleCommand`, signature `game:reschedule`. Elle applique `CatchUpGame` à chaque partie en cours, puis redispatche de façon idempotente le prochain job échu.
- **Enums [existants]** : `GameStatus` (`running`, `paused`, `completed`, `interrupted`) et `GameMode` (`multiplayer`, `solo`).
- **Lecteur du drapeau** : `App\Support\Deploy\DeployDrain::isDraining()` (C18-bis, R-08).

### 3. Formes de données
- Il n'y a **pas de charge client**.
- Forme SQL : `SELECT … FROM game WHERE ended_at IS NULL AND status IN ('running','paused')`, servie par `game_ended_idx (ended_at)` [existant, aucun index nouveau].
- **Solo compris** : le déploiement n'est pas atomique (D31), donc Composer, les migrations, `optimize` et `queue:restart` cassent aussi les requêtes et les jobs d'une partie solo.
- **Les salons au lobby ne comptent pas** : Echo se reconnecte seul, puis le client se resynchronise.

### 4. Invariants et garanties
1. `ended_at` est non nul **si et seulement si** `status` ∈ {`completed`, `interrupted`}. L'unique écrivain est `FinalizeGame` (C13), y compris pour la clôture de `stale_game` (n° 67).
2. **Terminaison bornée.** Toute partie en cours a au moins un job programmé sur la file `game` : une frontière, une clôture, une fin de révélation, ou `InterruptPausedGame` à `paused_at + pauseTimeoutMs`.
3. **Durée naturelle maximale sans pause** : `(MAX_ROUNDS_COUNT + drawSubstituteMargin) × (MAX_ROUND_DURATION + MAX_REVEAL_DURATION) × 1000 + (MAX_ROUNDS_COUNT + drawSubstituteMargin) × tier_grace_ms + (1 + drawSubstituteMargin) × launchCountdownMs`, soit environ 77,5 min aux bornes actuelles. La formule suppose l'enchaînement sans intervalle de C7 § 4.14 (`T₁(k+1) = reveal_ends_at(k)`). Chaque pause ajoute au plus `pauseTimeoutMs`. Le calcul se fait par `maxNaturalDurationMs()` depuis `RoomSettingsBounds`, `PlatformLimits` et `EngineConstants`, sans aucun littéral.
4. **Le drapeau de drainage** (C18-bis) bloque :
   - le lancement multijoueur et « Rejouer » (50) ;
   - `StartSoloGame` (60, via `OpenGame`), avec l'erreur traduite `common.maintenance.launch_blocked`, et aucune partie n'est créée.

   Il ne bloque **jamais** une reprise après pause, une reconnexion, un retardataire admis ni une manche déjà programmée.
5. **`game:reschedule`** est idempotent. `deploy:drain` l'appelle avant d'attendre, et il est obligatoire après toute restauration de Redis : une partie dont les jobs ont été perdus retrouve sa terminaison bornée au lieu de bloquer le drainage jusqu'à l'échéance.

### 5. Provenance des valeurs
- Statuts : `GameStatus` [existant].
- Durée de pause : `EngineConstants::pauseTimeoutMs()`.
- Bornes de durée : `RoomSettingsBounds` [existant], `PlatformLimits::tierGraceMs()` et `drawSubstituteMargin()` (C0), `EngineConstants::launchCountdownMs()` (C7).
- **La borne d'attente de `deploy:drain` appartient à 100**, qui la dérive de `maxNaturalDurationMs()` (C18-bis, R-10).

### 6. Exigences et amendements
- **À 10** : E10-36 (rappel dans § 7.2 : « `ended_at IS NULL` ⟺ statut non terminal ; seule l'action de gel l'écrit »). Aucune colonne ni aucun index.
- **Amendements** : A-31 (00 l.345) ; A-56 (questions-ouvertes l.296).
- **À 100** : lire le drapeau par `DeployDrain` et appeler `game:reschedule` dans `deploy:drain` (satisfait par C18-bis).
- **À 50** : la garde de lancement et de « Rejouer » lit ce drapeau (satisfait par C6).

### 7. Tests Pest nommés
- **`tests/Feature/Game/GamesInProgressTest.php`**
  - « une partie running ou paused est en cours, solo compris »
  - « une partie completed ou interrupted n'est pas en cours »
  - « ended_at est non nul si et seulement si le statut est terminal »
  - « un salon au lobby sans partie ne compte pas comme partie en cours »
  - « toute partie en cours a au moins un job programmé sur la file game »
  - « la reprise d'une partie en pause reste permise pendant un drainage »
  - « game:reschedule redonne un job à une partie en cours qui n'en a plus et reste idempotent »
  - « maxNaturalDurationMs se dérive des bornes sans littéral »
  - Le refus du solo pendant le drainage est prouvé par `SoloTest` (C7, R-04).

### 8. Laissé au rédacteur de 60
- La forme de la sortie console de `summary()`.
- Le moment exact où `game:reschedule` s'insère dans la documentation d'exploitation.

**Hors de 60** : la borne de temps du drainage, le stockage et le nom du drapeau, le bandeau (90) et les commandes `deploy:*` (100).

---

## C18 — Groupes Pest, jobs CI, nommage des tests et matrice des réglages

### 1. Propriété
- **Propriétaire** : `100`, section [J1] (D30).
- **Consommateurs** : 20, 30, 40, 50, 60, 70, 80, 90.
- **Jalon** : J1.

### 2. Noms exacts

**2.1 Groupes, liste close**

| Groupe | Contenu | Où il tourne |
|---|---|---|
| défaut (aucun groupe) | tout test qui passe sur SQLite `:memory:`, cache `array`, file `sync`, diffusion `null` | partout |
| `mysql` | longueurs de `VARCHAR` (1406), longueurs d'index (1071), `ONLY_FULL_GROUP_BY`, collation, comptes portables | job MySQL uniquement |
| `locks-timing` | `lockForUpdate`, allocation concurrente (`lock_rank`), verrous Redis, jobs de frontière, battement de cœur | job MySQL + Redis uniquement |

- Un test `mysql` se déclare **au niveau du fichier** par `pest()->group('mysql');` et appelle `beforeEach(fn () => requireMysql());`. Un fichier par défaut n'emploie jamais `->group('mysql')` sur un test isolé (R-03).
- Un test `locks-timing` vit dans **`tests/Concurrency/<Domaine>/<Sujet>Test.php` [nouveau]**, où le groupe s'applique par répertoire. Inventaire au J1 : `Room/LaunchConcurrencyTest.php` (C6), `Game/EarlyEndHookTest.php` (C7), `Answer/LockTransactionTest.php` (C10), `Scoring/FinalizeGameConcurrencyTest.php` (C13).
- Tout test `mysql` ou `locks-timing` **échoue, et ne se saute jamais**, hors de sa base.

**2.2 `tests/Pest.php` [modifié]**
```php
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');           // [existant, décommenté]
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->group('locks-timing')->in('Concurrency'); // [nouveau]
pest()->beforeEach(fn () => Http::preventStrayRequests())->in('Feature', 'Concurrency'); // étendu
pest()->beforeEach(fn () => requireMysql())->in('Concurrency');                          // [nouveau]
function requireMysql(): void { expect(DB::connection()->getDriverName())->toBe('mysql'); } // [nouveau]
```
`DatabaseTruncation`, et non une transaction : deux connexions doivent voir les écritures l'une de l'autre.

**2.3 `phpunit.xml` [modifié]**
- Ajout de la suite `<testsuite name="Concurrency"><directory>tests/Concurrency</directory></testsuite>`.
- `force="true"` sur `TMDB_API_KEY` et `TMDB_API_READ_ACCESS_TOKEN`, puis sur toute clé externe ajoutée (OAuth, SMTP, sauvegarde).
- **Jamais** `force` sur `DB_*`, `CACHE_STORE` ni `QUEUE_CONNECTION`, que le job MySQL surcharge.

**2.4 Scripts**

| Fichier | Script | Contenu |
|---|---|---|
| `composer.json` | `test` | `@php artisan config:clear`, `@lint:check`, `@types:check`, `@php artisan test --exclude-group=mysql,locks-timing` |
| `composer.json` | `ci:check` | `npm run check`, `npm run types:check`, `npm run test`, `@test` |
| `composer.json` | `test:mysql` | `@php artisan test --group=mysql,locks-timing` (usage local contre une base MySQL de test) |
| `package.json` | `test` [nouveau] | `vp test run` |

- `vite.config.ts` reçoit le bloc `test: { include: ['tests/Frontend/**/*.test.ts'], environment: 'node' }`.
- `tsconfig.json` ajoute `tests/Frontend/**/*.ts` à `include`.
- Vitest n'utilise aucun environnement DOM au J1.

**2.5 Jobs CI**
- **`.github/workflows/tests.yml`, job `ci` [existant]** :
  - déclenché sur `pull_request` et `push: main` ;
  - `setup-php` avec `extensions: imagick` **[existant]**, comme `ext-imagick` au `composer.json` **[existant]** ;
  - `composer setup` puis `composer ci:check`, donc SQLite, sans les deux groupes, Vitest compris.
- **Même fichier, job `artifacts` [nouveau, J1 par D1]** :
  - `needs: ci`, avec `if: github.event_name == 'push' && github.ref == 'refs/heads/main'` ;
  - `permissions: contents: write` **sur ce seul job**, avec le jeton automatique seulement ;
  - `npm ci` puis `npm run build`, et poussée de la branche **`deploy`** (source et `public/build`, sans `vendor`) ;
  - le build ne fige aucune variable dépendant de l'hôte : Echo se configure à l'exécution (C7, n° 79).
- **`.github/workflows/tests-mysql.yml` [nouveau], job `mysql-redis`** :
  - déclencheurs :
    - `push: main` ;
    - `schedule` nocturne ;
    - `workflow_dispatch` ;
    - `pull_request` filtré par chemins : `database/**`, `app/Models/**`, `app/Enums/**`, `app/Settings/**`, `config/database.php`, `tests/Concurrency/**`, `tests/Feature/Schema/**`, et le fichier du workflow ;
  - services : `mysql:8.0` avec `MYSQL_ALLOW_EMPTY_PASSWORD: yes` et une base `tripleframes_test`, plus `redis:7` ;
  - `setup-php` avec `extensions: imagick` (R-40, à poser dans ce nouveau workflow) ;
  - variables : `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_DATABASE=tripleframes_test`, `DB_USERNAME=root`, `DB_PASSWORD=` ;
  - `CACHE_STORE` reste `array`. Les tests `locks-timing` choisissent leur store Redis et vident leur base Redis de test avant chaque test ;
  - exécution : `composer setup`, puis `php artisan test`, soit **la suite entière et les deux groupes**.
- Le porteur ne déclenche un déploiement (C18-bis) que si les **deux** workflows sont verts sur le commit poussé dans `deploy`.

**2.6 Arborescence et nommage, liste close des domaines de test**
- `tests/Feature/<Domaine>/<Sujet>Test.php` : `<Sujet>` en PascalCase ; nom de test en **phrase française au présent, minuscule initiale, sans point final**, par `it()` ou `test()`, cité à l'identique par les specs (R-02 : `10` § 15 nomme ses tests en français ; les tests existants en anglais ne sont pas renommés).
- `tests/Unit/<Domaine>/<Sujet>Test.php` : seulement pour le code pur, sans façade ni conteneur.
- `tests/Concurrency/<Domaine>/<Sujet>Test.php` : voir 2.1.
- Jeux de données dans `tests/Datasets/<Nom>.php`, supports dans `tests/Support/<Domaine>/<Classe>.php` (espace de noms `Tests\Support\…`), fixtures dans `tests/Fixtures/<Source>/…`.
- Front : `tests/Frontend/<domaine-kebab>/<sujet-kebab>.test.ts`.

| Domaine | Spec |
|---|---|
| `Architecture` | 100 (tests d'architecture de toutes les specs) |
| `Deploy` | 100 |
| `Retention` | 10 et 100 |
| `Admin` | 20 |
| `Catalog` | 20 et 10 (et 70 pour `AnswerKeyProjectorTest`) |
| `Curation` | 20 |
| `Tmdb` | 20 |
| `Draw` | 30 |
| `Identity` | 40 [J1] |
| `Auth`, `Settings` | 40 (Fortify, existants) |
| `Account` | 40 [J2] |
| `Room` | 50 |
| `Game` | 60 |
| `Answer` | 70 (R-03 : jamais `Answers`) |
| `Scoring` | 80 |
| `Public` | 90 |
| `I18n` | 05 |
| `Schema` | 10 |

Un nouveau domaine s'ajoute à ce tableau avant son premier test.

**2.7 Jeu de données partagé de la matrice des réglages [nouveau]**
- Objet de cas `tests/Support/Room/RoomSettingsCase.php`, `final readonly` :
  ```php
  public function __construct(
      public string $label,          // stable, ex. "N3·D14·R5·points:default"
      public array $input,           // charge postée : clés camelCase de RoomSettings::FIELDS + 'roundDuration'
      public array $refusedFields,   // list<string> : champs attendus en erreur dans RoomSettings::validate()
      public array $warnings,        // list<string> : codes RoomSettings::WARNING_* attendus si accepté
  ) {}
  public function accepted(): bool;
  ```
- Générateur `tests/Support/Room/RoomSettingsMatrix.php` :
  - `cases(): iterable<string, array{RoomSettingsCase}>` ;
  - `accepted(): iterable<string, array{RoomSettings}>`.
- Jeux nommés, dans `tests/Datasets/RoomSettingsMatrix.php` : `room_settings.matrix`, `room_settings.accepted`, `room_settings.presets` (`SettingPresetKey::cases()`).
- **Axes**, tous dérivés de `RoomSettingsBounds` ; les seuls littéraux sont les décalages ±1 :
  - `framesPerRound` : de `MIN_FRAMES_PER_ROUND − 1` à `MAX_FRAMES_PER_ROUND + 1` ;
  - `roundDuration` : { `minRoundDuration(N) − 1`, `minRoundDuration(N)`, `DEFAULT_ROUND_DURATION`, `LONG_ROUND_WARNING_DURATION`, `LONG_ROUND_WARNING_DURATION + 1`, `MAX_ROUND_DURATION`, `MAX_ROUND_DURATION + 1` }, dédupliqués ;
  - `revealDuration` : { `MIN − 1`, `MIN`, `RECOMMENDED_MIN_REVEAL_DURATION − 1`, `RECOMMENDED_MIN_REVEAL_DURATION`, `MAX`, `MAX + 1` } ;
  - `tierPoints` : { `defaultTierPoints(N)`, son inverse (non décroissant), tout `MIN_TIER_POINTS`, tout `MAX_TIER_POINTS`, défaut avec le palier 1 à `MIN − 1`, défaut avec le palier 1 à `MAX + 1` } ;
  - une famille `advanced` : `dᵢ = MIN_TIER_DURATION − 1`, `Σdᵢ > MAX_ROUND_DURATION`, `dᵢ = maxTierDuration(N) + 1`, taille de liste ≠ N. Ses verdicts suivent C0, y compris le forçage de `advanced` au J1.
- Produit complet `N × D × R × barème` : environ 1 000 à 1 500 cas.
- **Déterministe** : aucun Faker, aucun tirage aléatoire.
- **Le générateur ne réimplémente pas la validation.** Chaque valeur d'axe porte son verdict attendu, écrit à la main à partir des bornes nommées. L'interaction de deux verdicts, par exemple N hors bornes combiné à une borne de D, suit la règle écrite par 50 et est encodée une seule fois dans le générateur.

### 3. Formes de données
Sans objet pour les messages. Variables de job listées en 2.5. Aucune donnée de production, aucune image réelle en fixture.

### 4. Invariants
- La suite par défaut n'exige ni réseau (`preventStrayRequests`), ni secret, ni MySQL, ni Redis.
- Les tests `locks-timing` :
  - ne dépendent jamais de l'horloge réelle : `Date::setTestNow()` ou `travelTo()`, `Sleep::fake()`, millisecondes entières (10 § 1.2) ;
  - utilisent `Queue::fake()` pour le coût constant L4 (n° 63).
- Les noms de groupe forment une liste close.
- Un test appartient à un seul domaine.
- Les jeux de données sont déterministes, et leurs étiquettes stables.

### 5. Provenance
- Toutes les valeurs de la matrice viennent de `RoomSettingsBounds` et `PlatformLimits` ; le seul littéral est ±1.
- Aucun secret dans les workflows, hors du jeton automatique du job `artifacts`.

### 6. Exigences et amendements
- **À 10** : aucune. Le tableau de placement de ses tests nommés (§ 15 l.1419) est écrit par 100 selon 2.1 et 2.6 (E10-67).
- **Amendements** : A-30 (00 l.329, Vitest configuré au J1) ; A-57 (questions-ouvertes l.299) ; A-72, A-76, A-77, A-78 (CLAUDE.md et D9).

### 7. Tests Pest nommés
- `tests/Feature/Architecture/TestGroupsTest.php` :
  - « n'emploie que les groupes Pest connus »
  - « fait échouer hors MySQL tout test du groupe mysql » (balayage : tout fichier qui déclare `group('mysql')` appelle `requireMysql()`)
  - « ne place un test locks-timing que sous tests/Concurrency »
- `tests/Feature/Architecture/ZeroSecretTest.php` :
  - « ne référence aucun secret dans aucun workflow, hors jeton automatique »
  - « ne persiste aucun identifiant hors du job artifacts »
  - « livre vide chaque clé sensible de .env.example »
  - « force à vide chaque identifiant externe dans phpunit.xml »
- `tests/Feature/Architecture/NoLiteralDomainTest.php` : « écrit <DOMAINE> au lieu de tout hôte littéral dans les specs, la configuration et .env.example », avec une liste d'autorisation commentée (n° 78).
- `tests/Feature/Architecture/NoRealFixtureTest.php` : « ne suit aucune image, aucun dump ni aucune archive hors de la liste d'autorisation ». Autorisés : icônes de `public/`, `public/brand/*` avec `LICENSE.md`, `public/avatars/*.webp` avec `LICENSE.md`.
- `tests/Feature/Room/RoomSettingsMatrixTest.php` (fichier du domaine de 50, jeu de données de 100) :
  - « refuse exactement les champs déclarés pour chaque combinaison aux bornes »
  - « lève exactement les avertissements déclarés pour chaque combinaison acceptée »
- `tests/Feature/Room/PresetValidityTest.php` : fusionné avec C0 (R-04).
- `tests/Feature/Schema/ColumnLengthTest.php`, groupe `mysql` au niveau du fichier : « fait tenir la plus longue valeur d'enum dans chaque colonne castée en enum ».
- Exemples de consommation, propriété de 60 et 80 :
  - `tests/Feature/Game/RoundTierMaterializationTest.php` : « matérialise des décalages de palier entiers dont la somme vaut D pour chaque combinaison acceptée » sur `room_settings.accepted` ;
  - `tests/Feature/Scoring/SpeedBonusTest.php` : « garde B_max en pourcentage entier pour chaque N accepté ».

### 8. Libre pour 100
- Heure du job nocturne.
- Versions épinglées des actions.
- Stratégies de cache et `concurrency`.
- Audit de dépendances non bloquant.
- Forme de l'historique de la branche `deploy`.
- Répartition exacte de la famille `advanced` au J1, selon C0.

---

## C18-bis — Drainage et garde de déploiement (D31, D32)

### 1. Propriété
- **Propriétaire** : `100`, pour le drapeau, les commandes et la procédure (boundary map n° 40).
- **Consommateurs** :
  - `90` : bandeau ;
  - `50` : garde de lancement et de « Rejouer » (`OpenGame`, `ReplayRoom`) ;
  - `60` : démarrage du solo (via `OpenGame`).
- **Dépendance** : C17 (prédicat « partie en cours », `maxNaturalDurationMs()`, `game:reschedule`).
- **Jalon** : J1 (D1, D31, D32).

### 2. Noms exacts
- [nouveau] `App\Enums\DrainPhase: string`, avec `case Draining = 'draining'` et `case Window = 'window'`.
- [nouveau] `App\ValueObjects\Deploy\DrainState`, `final readonly` :
  - `__construct(public DrainPhase $phase, public CarbonImmutable $startedAt, public CarbonImmutable $expiresAt)` ;
  - `toCache(): array{phase: string, startedAt: string, expiresAt: string}` ;
  - `static fromCache(mixed $raw): ?self`.
- [nouveau] `App\Support\Deploy\DeployDrain` — **seul** drapeau de drainage du dépôt ; remplace `App\Support\Operations\DrainFlag` proposé par 50 (R-08) :
  - `start(int $timeoutMinutes): DrainState` pose le drapeau par `Cache::add` et lève `App\Support\Deploy\DrainAlreadyRunning` s'il existe déjà ;
  - `openWindow(int $windowMinutes): DrainState` ;
  - `state(): ?DrainState` ;
  - `isDraining(): bool`, vrai dans **les deux** phases ;
  - `release(): void`, idempotent ;
  - `static defaultTimeoutMinutes(): int`.
- [nouveau] `App\Console\Commands\DeployDrainCommand`, signature `deploy:drain {--timeout= : minutes} {--window= : minutes}`.
- [nouveau] `App\Console\Commands\DeployGuardCommand`, signature `deploy:guard`.
- [nouveau] `App\Console\Commands\DeployReleaseCommand`, signature `deploy:release`.
- [nouveau] `App\Console\Commands\BackupSnapshotCommand`, signature `backup:snapshot {--if-pending : ne rien faire sans migration en attente}` (règle 12, CLAUDE.md § 7) : instantané **bloquant** de la base avant toute migration ou commande qui touche `movie`, `frame`, `movie_title`, `alias` ou `frame_review`. Codes de sortie : 0 (instantané écrit et vérifié, ou rien à faire sous `--if-pending`), 1 (échec d'écriture ou de vérification). Destination et chiffrement : procédure de sauvegarde de 100 (à confirmer au relevé du VPS, S2). « Migration en attente » = ce que rapporte le migrateur de Laravel (`migrate:status --pending`), jamais une liste tenue à la main.
- [nouveau] `App\Console\Commands\CatalogReprojectCommand`, signature `catalog:reproject {--movie=* : identifiants à reprojeter, tous par défaut}` : recalcule `movie_projection` et `answer_key` par `MovieProjector` et `AnswerKeyProjector`, **par différence** (les identifiants stables survivent), idempotente, code 0 en succès. Obligatoire avant le premier import de production et après tout changement de `AnswerRules::VERSION` (C12 § 4). Elle n'écrit ni `movie`, ni `frame`, ni `movie_title`, ni `alias` : la règle 12 ne l'exige pas d'un instantané.
- [nouveau] `config/deploy.php` :
  - `drain_timeout_minutes`, depuis `env('DEPLOY_DRAIN_TIMEOUT_MINUTES')` ; `null` donne `defaultTimeoutMinutes()` ;
  - `drain_margin_minutes`, défaut 20 ;
  - `window_minutes`, depuis `env('DEPLOY_WINDOW_MINUTES', 30)` ;
  - `poll_seconds`, défaut 15 ;
  - `cache_key`, défaut `'deploy:drain'`.
- `.env.example` reçoit `DEPLOY_DRAIN_TIMEOUT_MINUTES=` et `DEPLOY_WINDOW_MINUTES=30`.
- Prop partagée [nouvelle] : `'maintenance' => fn (): bool => app(DeployDrain::class)->isDraining()`, typée dans `global.d.ts`.
- Clés :
  - `common.maintenance.banner` et `common.maintenance.launch_blocked` (C15), cette dernière étant le message unique de tout refus de drainage (`RoomRefusal::Draining`, solo, R-09) ;
  - sortie console en `admin.console.deploy.*`, français, par **clés littérales** pour que le balayage serveur les voie : `started`, `waiting` (`:count`), `window_open` (`:until`), `abandoned`, `already_running` (`:phase`, `:until`), `guard_ok`, `guard_games_in_progress` (`:count`), `guard_no_window`, `released`, `nothing_to_release`.

### 3. Formes de données
- **Drapeau** : une entrée du cache par défaut, Redis `appendonly` en production.
  - Clé : `config('deploy.cache_key')`.
  - Valeur : `{phase, startedAt (ISO-8601 UTC), expiresAt (ISO-8601 UTC)}`.
  - TTL : `expiresAt − now`, en secondes entières, au moins 1.
- **Joueurs** : seulement `maintenance: boolean` dans les props Inertia.
  - Ni heure, ni phase, ni compte de parties.
  - Aucune diffusion Reverb au J1. Le bandeau apparaît à la réponse Inertia suivante, et le refus de lancement est la seule garantie.
- **Journal** : nombres et instants seulement, jamais un pseudo ni un `room_code`. **Aucune ligne `admin_action`** : la liste fermée appartient à 10, et le drainage n'est pas un geste sur un sujet.

### 4. Invariants
- **`deploy:drain`** :
  - si un drapeau existe, il sort en code 2 sans rien modifier ;
  - sinon il pose le drapeau en phase `draining`, avec `expiresAt = now + timeout` ;
  - il appelle `game:reschedule` (C17) **avant** d'attendre, pour qu'aucune partie aux jobs perdus ne bloque le drainage jusqu'à l'échéance (R-10) ;
  - il interroge `GamesInProgress::count()` toutes les `poll_seconds`, par `Sleep::for()`, qui se simule en test ;
  - quand il obtient **deux relevés nuls consécutifs**, il bascule en phase `window` (`expiresAt = now + window`), annonce la fenêtre et sort en code 0 ;
  - si l'échéance arrive avec au moins une partie en cours, il appelle `release()` et sort en code 1 (abandon).
- **Mort de la commande** : le TTL fait expirer le drapeau à l'échéance, ce qui reproduit exactement la sémantique d'abandon. Le mécanisme ne dépend ni de `pcntl` ni d'un signal.
- **`deploy:guard`** :
  - code 0 si et seulement si le prédicat vaut 0 **et** la phase est `window` ;
  - code 1 si au moins une partie est en cours ;
  - code 2 hors fenêtre.
- **`deploy:release`** : retire le drapeau quelle que soit sa phase, toujours en code 0.
- **Seuls écrivains du drapeau** : `deploy:drain`, `deploy:release` et le hook (qui appelle ces commandes). Aucun code applicatif ne le pose ni ne le lève (testé en C6).
- **Garde de lancement.** Toute action qui crée une partie, ou qui y ramène, appelle `isDraining()` et refuse avec `common.maintenance.launch_blocked` (message à destinataire unique, résolu côté serveur) :
  - lancement multijoueur (`room.launch`, `OpenGame`) ;
  - « Rejouer » (`room.replay`), refusé pendant le drainage **quel que soit son effet** (D32) ;
  - démarrage d'une partie solo (`solo.store`, `OpenGame`).
- **Aucune partie en cours n'est jamais coupée ni retardée.** Le drainage n'agit que sur les lancements.
- **Course.** Un lancement validé juste avant la pose du drapeau est attendu par la règle des deux relevés. Le résidu restant est rattrapé par `deploy:guard` dans le hook.
- **Résidu assumé (D31, non atomique).** Plesk Git en mode manuel dépose les fichiers **avant** les actions additionnelles. Un échec de garde dans le hook ne peut donc arrêter que les étapes suivantes (migrations, redémarrages), jamais la mise à jour des fichiers.
  - Remède écrit : relancer `deploy:drain`, puis relancer les actions de déploiement Plesk.
  - Si le hook échoue avant `deploy:release`, le drapeau tient jusqu'au TTL de la fenêtre.

### 5. Provenance
- `defaultTimeoutMinutes()` vaut `(int) ceil(GamesInProgress::maxNaturalDurationMs() / 60 000) + config('deploy.drain_margin_minutes')`, soit ≈ 78 + 20 = 98 minutes aux bornes actuelles (R-10 : dérivé du prédicat de 60, marge de réserve comprise, au lieu du seul produit M × (D + R)).
- La marge couvre au moins la durée d'interruption d'une partie en pause fixée par 60 : `drain_margin_minutes × 60 000 ≥ EngineConstants::pauseTimeoutMs()`, testé.
- Aucune valeur de jeu en dur. Fenêtre, fréquence de relevé et clé viennent de `config/deploy.php`.

### 6. Procédure, hook, exigences et amendements

Procédure du porteur, tant que le déploiement est manuel (D31) :
1. Lancer `deploy:drain`, jusqu'à la fenêtre (code 0).
2. Vérifier `deploy:guard` à la main (code 0).
3. Cliquer « Déployer » dans Plesk.
4. Le hook s'exécute.

**Ordre du hook**, avec `set -e` et PHP invoqué par `/opt/plesk/php/8.3/bin/php` :
1. `composer install --no-dev --optimize-autoloader`, d'abord : un garde qui ne peut pas démarrer n'est pas un garde ;
2. `artisan optimize:clear` ;
3. `artisan deploy:guard` ;
4. `artisan backup:snapshot --if-pending` (règle 12) : la commande ne fait rien et sort en 0 si le migrateur ne signale aucune migration en attente ; sinon elle prend l'instantané, le vérifie et sort en 0, ou sort en code non nul, ce qui arrête le hook (`set -e`) avant toute migration ;
5. `artisan migrate --force` ;
6. `artisan db:seed --class=PlatformDataSeeder --force` : l'étape ne **réconcilie** que `setting_preset` (le seeder en est le seul écrivain, 10 § 6.3). Thèmes, libellés `theme_label` et sagas de S4 sont **insérés s'ils manquent et jamais réécrits** (insert-if-absent), pour que les éditions faites en back-office (20) survivent au déploiement (S4, 10 l.694). Le seeder actuel réécrit `is_published`, `sort_order` et les libellés à chaque passage : il est scindé (exigence au lot de 30, ci-dessous) ;
7. `artisan optimize` ;
8. `artisan lang:hash` ;
9. `artisan queue:restart` ;
10. `artisan reverb:restart` ;
11. `artisan deploy:release`.

- **À 10** : aucune.
- **Amendements** : A-31 (00 l.345) ; A-56 (questions-ouvertes l.296) ; A-71, A-72 (CLAUDE.md § 3 et § 4).
- **À 70** (C12) : `catalog:reproject` s'insère dans ce hook au plus tard au déploiement du lot du normaliseur (100 décide de sa place exacte).
- **À 30** : scinder `PlatformDataSeeder` en une réconciliation des presets (`setting_preset`, seul écrivain) et un amorçage des thèmes, libellés et sagas S4 en insert-if-absent ; test « rejouer le seeder de plateforme ne réécrit ni un thème, ni un libellé, ni une saga édités en back-office ».

### 7. Tests Pest nommés
- `tests/Feature/Deploy/DeployDrainCommandTest.php` :
  - « ouvre une fenêtre libre après deux relevés consécutifs sans partie en cours »
  - « abandonne et lève le drapeau quand l'échéance passe avec une partie en cours »
  - « laisse le drapeau expirer de lui-même si la commande meurt »
  - « refuse de lancer un second drainage »
  - « appelle game:reschedule avant d'attendre »
  - « dérive sa borne par défaut de maxNaturalDurationMs et d'une marge qui couvre une pause »
- `tests/Feature/Deploy/DeployGuardCommandTest.php` :
  - « échoue tant qu'une partie est en cours »
  - « échoue hors d'une fenêtre libre »
  - « passe dans une fenêtre libre sans partie en cours »
- `tests/Feature/Deploy/DeployReleaseCommandTest.php` : « lève le drapeau quelle que soit sa phase »
- `tests/Feature/Deploy/BackupSnapshotCommandTest.php` :
  - « ne fait rien et réussit sans migration en attente sous --if-pending »
  - « sort en échec quand l'instantané ne peut être écrit ou vérifié »
- `tests/Feature/Catalog/CatalogReprojectCommandTest.php` :
  - « reprojette par différence et conserve les identifiants stables de answer_key »
  - « est idempotente »
- `tests/Feature/Deploy/MaintenanceBannerTest.php` : « partage le drapeau de drainage en booléen avec toute page joueur »
- Propriété de 50 : `LaunchGameTest` › « refuse de lancer pendant le drainage… » et `ReplayRoomTest` › « refuse de rejouer pendant le drainage » (C6).
- Propriété de 60 : `SoloTest` › « refuse de démarrer une partie solo pendant un drainage, sans partie créée » (C7).

### 8. Libre
- Texte des clés.
- Présentation de la sortie console.
- Valeurs par défaut de `window_minutes` et `poll_seconds`, si 100 les justifie.
- Forme exacte du refus côté 50 (sac d'erreurs ou message flash), du moment qu'elle utilise la clé ci-dessus.
- Page de fermeture du service `site:close` : J2, hors de ce contrat.

---

## Annexe — frontière voisine non figée ici (indexation)

Ce point est une recommandation, pas un gel. Il est cité pour qu'aucun rédacteur parallèle ne le double.

| Spec | Part recommandée |
|---|---|
| 90 | tableau route par route ; drapeau de route `->defaults('indexable', true)` sur le patron de `VaryOnLanguage::ROUTE_FLAG` ; middleware d'en-têtes (`X-Robots-Tag: noindex, nofollow` par défaut, `Referrer-Policy: strict-origin-when-cross-origin`) |
| 100 | variable de production `SITE_INDEXABLE`, fausse par défaut ; `robots.txt` réduit à `Disallow: /admin` et `Disallow: /f/` ; tests des deux états |
| 60 | la route `/f/{serveToken}` émet elle-même `X-Robots-Tag` en plus de `no-store` (fait par `FrameImageResponse`, C9) |

Au J1 (D30), le site est intégralement `noindex`.

---

## Conflits résolus

Chaque entrée donne la divergence entre groupes, la résolution appliquée dans les chapitres ci-dessus et sa raison. Ordre de préséance : décisions du 23/09 > `10` > `05` > `00` > code existant ; à défaut, le propriétaire du contrat.

**R-01 — Numérotation des contradictions (transversal).**
- Divergence : G20, G30, G40 et G80 citent `contradictions_to_fix` à partir de 0 ; G50, G60, G70 et G90 à partir de 1 (G50 « n° 65 » pour B_max, qui est l'entrée 64).
- Résolution : numérotation à partir de 0 partout ; renvois de G50, G60, G70 et G90 décrémentés d'une unité. Exemples : B_max n° 64, fenêtre d'acceptation n° 59, charge du QCM n° 55, `serve_token` n° 47, préchargement n° 48, SSR n° 70.
- Raison : `decisions-2309.md` (« n° 14 → D4, n° 36 → D2, n° 53 → D7 et D14 ») et `pre/contradictions.txt` numérotent à partir de 0.

**R-02 — Langue des noms de tests (C18 × C4, C5, C15, C16, C18-bis × 10).**
- Divergence : C18 (100) impose « phrase anglaise » et G40/G90 nomment en anglais ; G20, G30, G50, G60, G70, G80 nomment en français ; le dépôt mêle les deux.
- Résolution : phrase française au présent, minuscule initiale, sans point, `it()` ou `test()` ; les noms anglais de G40 et G90 sont traduits dans cette feuille ; les tests existants ne sont pas renommés.
- Raison : `10` § 15 nomme ses tests en français (« une resynchronisation à t = 1 s… »), et `10` prime sur le propriétaire de C18.

**R-03 — Placement des tests `mysql` et `locks-timing`, domaine `Answer` (C18 × C2, C6, C7, C10, C13).**
- Divergence : C18 exige `tests/Concurrency/<Domaine>/` pour `locks-timing` et un groupe `mysql` déclaré au niveau du fichier ; G50, G60, G70 et G80 placent leurs cas concurrents sous `tests/Feature/` avec `->group(...)`, G30 marque un test isolé `->group('mysql')` ; G70 écrit `tests/Feature/Answers/` alors que la liste close dit `Answer`.
- Résolution : cas concurrents extraits vers `tests/Concurrency/{Room,Game,Answer,Scoring}/…` ; test MySQL de 30 déplacé dans `PoolQueryMysqlTest.php` ; domaine `Answer` pour 70.
- Raison : C18 est propriétaire de la liste close des groupes et domaines ; aucun rang supérieur ne le contredit.

**R-04 — Tests en double (C0, C5, C6, C7, C10, C13, C15, C17, C18, C18-bis).**
- Divergence : même preuve écrite deux ou trois fois : validité des presets (50 et 100), bornes croisées (`RoomSettingsContractTest` et `RoomSettingsMatrixTest`), B_max hors configuration (50 et 80), refus du solo pendant le drainage (50, 60, 100), refus de lancement pendant le drainage (50 et 100), forme de `player.locked` (60 et 70), canaux refusés à l'expulsé (40 et 60), libellés des prédéfinis et clés de grille (40/20 et 05), `DrainFlagTest` (50) et tests `Deploy` (100), garde « prédéfinis ≥ sièges » (40 et 50).
- Résolution : une seule preuve, dans le fichier du propriétaire du comportement ; les autres chapitres y renvoient.
- Raison : consigne de fusion « nettoyé des doublons » ; propriétaire du contrat.

**R-05 — B_max : noms des constantes et N hors bornes (C0 × C13).**
- Divergence : G50 écrit `SPEED_BONUS_MAX_PERCENT_CAP` et `FULL_PERCENT` avec un corps qui **écrête** N par `clampFramesPerRound()` ; G80 écrit `SPEED_BONUS_MAX_PERCENT` et `PERCENT_BASE` et **lève** hors bornes.
- Résolution : noms de G50 (`SPEED_BONUS_MAX_PERCENT_CAP`, `FULL_PERCENT`) ; comportement de G80 (`\InvalidArgumentException`, jamais d'écrêtage) ; la formule du bonus de C13 emploie `PlatformLimits::FULL_PERCENT`.
- Raison : 50 est propriétaire de la classe (C0) ; un écrêtage silencieux masquerait un N invalide et fausserait un rejeu que D22 veut exact (même principe que C1 : « jamais d'écrêtage silencieux »).

**R-06 — Fenêtre de mémoire du salon : noms (C0 × C2).**
- Divergence : `roomMemoryWindowDays/Rounds` et `game.platform.room_memory_window_*` (G50) contre `roomMemoryDays/Rounds` et `room_memory_*` (G30).
- Résolution : noms de G50 ; valeurs (90 jours, 500 manches) et bornes (≥ 1) déclarées par 30.
- Raison : boundary map n° 20 donne les **valeurs** à 30, la classe `PlatformLimits` et `config/game.php` appartiennent à 50 (C0).

**R-07 — Plafonds de recadrage de 20 dans `PlatformLimits` (C0 × C9).**
- Divergence : G20 ajoute `frameCropMaxWidthPercent()` et `frameCropMinWidthPx()` avec deux clés dans `toArray()` et un type `Pick<PlatformLimits, …>` incluant `frameUploadMaxKilobytes` ; G50 fige `toArray()` (clés exactes testées) et en retire `frameUploadMaxKilobytes`, sans connaître les ajouts de 20.
- Résolution : les deux accesseurs entrent dans `PlatformLimits` (famille « curation », constructeur à quinze valeurs, clés `game.platform.frame_crop_*`, gardes de bornes) mais **pas** dans `toArray()` ; 20 compose sa prop `limits: AdminFrameLimits` depuis les accesseurs.
- Raison : C0 (50) est propriétaire de la forme de `toArray()`, prop joueur ; le besoin de 20 est satisfait sans l'élargir.

**R-08 — Drapeau de drainage en double (C6 × C17 × C18-bis).**
- Divergence : G50 crée `App\Support\Operations\DrainFlag` (clé `game:drain`, `raise/lower/isRaised/until`, section `game.operations`) ; G100 crée `App\Support\Deploy\DeployDrain` (clé `deploy:drain`, phases `draining`/`window`, `config/deploy.php`) ; G60 déclare que stockage et nom du drapeau sont hors de 60.
- Résolution : `DeployDrain` seul ; `DrainFlag` et la section `operations` de `config/game.php`, brouillons de 50 absents du dépôt, sont abandonnés ; `OpenGame` et `ReplayRoom` lisent `DeployDrain::isDraining()` ; le bandeau lit la prop booléenne `maintenance`.
- Raison : boundary map n° 40 (« deploy:guard, bandeau » → 100) ; D32 décrit la commande et la fenêtre que seul `DeployDrain` modélise.

**R-09 — Clé du refus de drainage (C6 × C7 × C18-bis × C15).**
- Divergence : `room.refusal.draining` (50), `game.errors.draining` (60), `common.maintenance.launch_blocked` (100/90).
- Résolution : une seule clé, `common.maintenance.launch_blocked` ; `RoomRefusal::Draining->messageKey()` la rend ; les deux autres clés n'existent pas.
- Raison : propriétaire C18-bis (100) ; `common` est chargé sur toutes les pages, ce qui rend le message disponible au lobby comme au solo.

**R-10 — Borne d'attente du drainage et reprogrammation (C17 × C18-bis).**
- Divergence : 100 calcule `defaultTimeoutMinutes()` = ⌈M_max × (D_max + R_max) / 60⌉ + marge, sans les manches de réserve, les grâces ni le décompte ; 60 fournit `maxNaturalDurationMs()` (≈ 77,5 min) et exige que `deploy:drain` appelle `game:reschedule` avant d'attendre, ce que 100 n'écrit pas.
- Résolution : `defaultTimeoutMinutes()` = ⌈`maxNaturalDurationMs()` / 60 000⌉ + `drain_margin_minutes` (≈ 98 min) ; `deploy:drain` appelle `game:reschedule` d'abord ; test « la marge couvre `pauseTimeoutMs` ».
- Raison : C17 (60) écrit explicitement que 100 dérive la borne de sa fonction ; la formule de 60 est un sur-ensemble plus prudent.

**R-11 — Rapport de vivier : `PoolState` (50) contre `PoolReport` (30) (C0, C6 × C2).**
- Divergence : champs (`playable`/`required`/`culprits` contre `count`/`roundsCount`/`causes`), codes de remède (`new_room`, `use_frames_per_round` contre `open_new_room`, `lower_frames_per_round`), forme `{code, value}` contre `{kind, value, count}` ; code de cause thématique `themeKeys` (50) contre `themeIds` (30).
- Résolution : `PoolReport::toArray()` de 30 est la seule forme (lobby, refus de lancement, solo) ; le cas de thème devient `PoolFault::ThemeKeys = 'themeKeys'` ; les clés de 50 s'alignent sur les codes de 30 (`room.pool.cause.*`, `room.pool.remedy.*`) ; `LaunchOutcome::$pool` est un `?PoolReport` ; le présentateur de 50 retire `disable_no_repeat` au J1 (D28) ; types TS dans `types/pool.ts`.
- Raison : C2 appartient à 30 (boundary map n° 14 : « calcul et rapport en données à 30 ») ; mais un code qui désigne un champ côté client doit porter le nom client, et `10` § 1.1 impose `themeKeys` à la frontière (C0).

**R-12 — Liste des thèmes publiés par clé (C0 × C2).**
- Divergence : `RoomSettingsEditor::simple()` exige `array<string, int> $publishedThemeIdsByKey` « fourni par 30 » ; C2 n'expose que `publishedThemeIds(): list<int>`.
- Résolution : ajout de `PoolQuery::publishedThemeIdsByKey(): array<string, int>` à C2.
- Raison : exigence explicite de 50 à 30, sans contradiction avec un rang supérieur.

**R-13 — Charge de `settings.changed` (C0 × C7).**
- Divergence : 60 écrit `{ settings: RoomSettingsPayload (= toPayload()), warnings: string[], pool: PoolReport }` ; 50 écrit `RoomSettingsState { settings: RoomSettingsView, warnings: RoomSettingsWarningCode[], pool: PoolState }`.
- Résolution : `settings.changed` porte `RoomSettingsState` de C0, avec `RoomSettingsView` (thèmes en `themeKeys`) et `PoolReport`.
- Raison : `10` § 1.1 interdit d'exposer `theme.id` ; 50 fixe le contenu des messages de lobby, 60 le transport (boundary map n° 23).

**R-14 — Diffusion des changements de statut du salon (C6 × C7).**
- Divergence : 50 diffuse `{ roomStatus: 'playing' }` et `{ roomStatus: 'lobby' }` ; la liste close de 60 n'a que `game.launched` et aucun événement de retour au lobby ; 50 veut aussi diffuser un refus `pool_insufficient`.
- Résolution : lancement → `game.launched` (charge de 60) ; « Rejouer » → nouvel événement `room.replayed` (`RoomReplayed`, charge `RoomSettingsState`), ajouté à la liste close de 60 ; refus `pool_insufficient` → `settings.changed` avec l'état recalculé.
- Raison : 60 possède le transport et la liste close (boundary map n° 23), mais le « Rejouer » de D32 a besoin d'un signal que la liste n'avait pas.

**R-15 — Séquence de lancement et noms des appels vers 30 et 60 (C6 × C2 × C3 × C7).**
- Divergence : 50 propose `PoolReport` (comme requête), `Drawer`, `MaterializeDraw` et génère la graine par `bin2hex(random_bytes(32))` ; 30 impose `PoolScope`, `PoolReporter::report()`, `SeededPrf::generateSeed()`, `GameDrawer::draw()` sur le même `PoolScope` ; 60 ne nomme pas l'action de matérialisation.
- Résolution : étapes O2-O9 de C6 réécrites avec les noms de 30 ; `MaterializeDraw::handle(Game, DrawResult)` adoptée comme nom figé, propriété de 60 ; `ScheduleRound($round1, $now + launchCountdownMs())`.
- Raison : 30 est propriétaire de C2 et C3 (random_bytes réservé à `generateSeed()`) ; le nom proposé par 50 comble un vide de 60 sans contredire quiconque.

**R-16 — Instant de création des `round_player` (C6 × C7).**
- Divergence : 50 (O9) crée les lignes `round_player` à la programmation de la manche 1 ; 60 les crée à `T₁` pour chaque siège non parti éligible.
- Résolution : à `T₁` (60) ; O9 ne crée que `started_at` et le jeton du palier 1.
- Raison : n° 47 (« présent » = non parti, jugé à l'ouverture) ; 60 est propriétaire du moteur.

**R-17 — Contextes de dérivation des leurres et du QCM (C3 × C11).**
- Divergence : 70 emploie `draw:decoys:{roundId}`, `draw:decoys:{roundId}:original`, `qcm:{roundId}:{playerId}` et un flux « qui continue » entre rangs, sur une PRF nommée `SeededStream` ; 30 fige un registre fermé indexé par `sequenceIndex` et `publicId`, sans contexte « original ».
- Résolution : registre de 30, complété par `DrawContext::decoysOriginal($s)` = `draw:decoys:{s}:original` ; PRF `SeededPrf` ; chaque rang est parcouru dans l'ordre de `permutation($context, n)`, R1-R2 sur `decoys(s)`, R3-R4 sur `decoysOriginal(s)` (le rang R5 « sans non-répétition » d'une première rédaction est retiré, hors de D21) ; ordre du QCM par `permutation(qcmOrder(s, publicId), 4)`.
- Raison : C3 appartient à 30 (registre fermé, calculable avant l'insertion des lignes, aucun identifiant de base) ; la permutation par rang conserve l'uniformité sans remise voulue par 70.

**R-18 — Plancher de palier du QCM en double (C10, C12 × C13).**
- Divergence : `AnswerRules::floorTierIndex()` (70, couvert par `validation_version`) et `ScoringRules::floorTierIndex()` (80, couvert par `scoring_version`).
- Résolution : `ScoringRules::floorTierIndex()` seul, appliqué dans `ScoreCalculator::forGuess()` ; l'empreinte de `AnswerRules` ne couvre plus le plancher ; 70 garde le refus d'un clic reçu avant l'ouverture du QCM, décidé par `InputDifficulty::choicesOpenTierIndex()`.
- Raison : boundary map n° 27 (« Palier retenu et valeur d'une réponse » → 80).

**R-19 — Appel de la fonction de score (C10 × C13).**
- Divergence : 70 appelle `ScoreCalculator::score(answeredAtMs:, tiers:, tierGraceMs:, floorTierIndex:, speedBonus:, scoringVersion:)` ; 80 fait de `forGuess($game, $tiers, $answeredAtMs, $source)` le seul point d'entrée des appelants, avec un paramètre `speedBonusMaxPercent` absent de la liste de 70.
- Résolution : 70 appelle `ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $answeredAtMs, $source)`.
- Raison : 80 est propriétaire de la fonction (boundary map n° 27) ; `forGuess` résout tout depuis la partie figée, ce qui garantit l'identité avec le rejeu.

**R-20 — Fenêtre d'acceptation, statut de manche et précondition du score (C10 × C13 × C7).**
- Divergence : 80 exige `0 ≤ answeredAtMs < D` sous peine d'exception ; 70 accepte jusqu'à `D + tier_grace_ms` ; 60 borne à `ended_at + tier_grace_ms` et introduit une phase `closed` entre `ended_at` et la révélation sans dire le statut en base.
- Résolution : recevable ssi `round.status = running` ET `0 ≤ answeredAtMs` ET `receivedAt < (ended_at ?? started_at + D) + tier_grace_ms` ; `CloseRound` écrit `ended_at` sans changer le statut, `RevealRound` passe à `revealing` ; la précondition de 80 devient `answeredAtMs < D + tierGraceMs`, la recherche du palier portant sur l'instant corrigé.
- Raison : n° 59 (recevable jusqu'à `D + tier_grace_ms`, titres diffusés à `D + tier_grace_ms`) ; la précondition de 80 aurait levé sur toute réponse légale reçue dans la grâce finale.

**R-21 — Crochet de fin anticipée et événement « a trouvé » (C10 × C7 × C13).**
- Divergence : 60 fait appeler `SeatInputClosed` par 70 **dans** sa transaction, verrou `round` tenu ; 70 émet des événements de domaine `AnswerAccepted` et `InputClosed` après commit ; 70 et 80 évoquent un `seat.locked` ciblé que la liste de 60 exclut.
- Résolution : les événements de 70 sont conservés ; les écouteurs de 60 appellent `SeatInputClosed::handle()` dans leur propre transaction, qui reprend `round FOR UPDATE` ; aucun `seat.locked` au J1.
- Raison : le chemin de refus de 70 (une seule instruction `UPDATE`, invariant L4) ne tient pas le verrou `round` et ne peut pas appeler un crochet qui l'exige ; chaque écriture qui clôt une saisie étant suivie, après son commit, d'une réévaluation sous verrou, le dernier à clore voit toujours l'état complet. La liste close appartient à 60.

**R-22 — Calcul de `answered_at_ms` et instant de réception (C10 × C7).**
- Divergence : 70 calcule `getTimestampMs()` − `getTimestampMs()` et propose `ReceptionInstant::of()` comme nom de 60 ; 60 fixe `RoundClock::offsetMs()` sur les microsecondes.
- Résolution : `RoundClock::offsetMs($round, ReceptionInstant::of($request))` ; `ReceptionInstant` adopté comme nom de 60.
- Raison : 60 est propriétaire de l'horloge ; une seule formule évite un écart d'arrondi entre verrouillage et rejeu.

**R-23 — Résolveur de titre (C7 × C11).**
- Divergence : `MovieTitleResolver::displayTitle(): string` (60) contre `DisplayTitleResolver::resolve(): ResolvedTitle` et `original()` (70) ; 60 écrit lui-même qu'« un seul des deux noms survit ».
- Résolution : `App\Support\I18n\DisplayTitleResolver` seul.
- Raison : `05` l.123 exige un attribut `lang` sur tout fragment obtenu par repli, ce que seul `ResolvedTitle` (locale, rang) permet de calculer.

**R-24 — Paquet de titres de la révélation et du récapitulatif (C7 × C13 × 05).**
- Divergence : `RevealMovie { titles: Record<'fr'|'en', string>, … }` (60) perd la locale atteinte ; `TitlePacket` par défaut de 80 (`localized` nullable, `originalLanguage`), « C08 fait foi ».
- Résolution : `RevealMovie` de 60 garde son nom et devient `titles: Record<LocaleCode, { text, lang }>` plus `originalLanguage` ; `TitlePacket = RevealMovie` dans `types/scoring.ts`.
- Raison : `05` l.123 (fragment de repli porte son `lang`) prime sur la forme du propriétaire ; 60 reste propriétaire du contenu de la révélation (boundary map n° 34).

**R-25 — Noms des blocs de score dans les charges (C7 × C13).**
- Divergence : 60 écrit `roundResults: RoundResultRow[]`, `leaderboard: LeaderboardRow[]`, `PodiumPayload` ; 80 définit `RoundFinder[]`, un objet `Leaderboard { scoreless, roundNumber, rows }` et `Podium`.
- Résolution : noms et formes de 80 dans les charges de 60 (`round.revealed.finders`, `leaderboard: Leaderboard`, `game.ended.podium: Podium`, `GameStatePacket.leaderboard`, `RoundState.reveal.finders`).
- Raison : 60 écrit lui-même « définis par G80, importés, jamais redéfinis ici ».

**R-26 — État du siège à la resynchronisation (C7 × C10 × C13).**
- Divergence : `SelfState { inputState, attemptsLeft, choices, result, scoreTotal }` (60), `SeatInputView { state, attemptsLeft, choices, locked }` (70), `SeatScore { ownScore, ownRound }` (80) : trois noms pour les mêmes données, `ownRound` doublant `locked`.
- Résolution : `SelfState = { publicId, seatActive, isHost, member, participates, input: SeatInputView | null, ownScore }` ; clé `state` de 70 renommée `inputState` (comme sa réponse HTTP) ; `SeatScore` réduit à `{ ownScore }`.
- Raison : chaque donnée a un propriétaire (70 la saisie, 80 le score, 60 le paquet) ; « nettoyé des doublons ».

**R-27 — Types TypeScript déclarés deux fois (C0, C2, C5, C7, C10, C11, C13).**
- Divergence : `InputStateValue` (60) et `InputState` (70) ; `ChoicesPayload` en double avec `lang: string` (60) et `string | null` (70) ; `TierTimeline` (60) et `TierWindow` (80) ; `AvatarRefData` (60) et `AvatarData` (40) ; `PoolCulprit`/`PoolRemedy`/`PoolState` (50) et les types de `pool.ts` (30).
- Résolution : un seul fichier déclarant par type, celui du propriétaire (`answers.ts`, `scoring.ts`, `player.ts`, `pool.ts`) ; `game-wire.ts` et `room-settings.ts` importent.
- Raison : propriétaire du contrat ; `lang` peut être nul quand les chaînes sortent de `title_original` (C11).

**R-28 — Prédicat d'envoi du QCM et destinataires (C7 × C10 × C11).**
- Divergence : 60 demande `acceptsChoices()`, 70 définit `acceptsChoice()` ; 70 pousse aux sièges « connectés », 60 à tout siège « ni parti ni expulsé ».
- Résolution : `acceptsChoice()` ; destinataires = sièges ni partis ni expulsés, déconnectés compris.
- Raison : 70 possède l'enum ; 60 possède l'envoi et n° 47 définit « présent » comme « non parti ».

**R-29 — `SeatView` et `PlayerIdentity` (C7 × C5 × C13).**
- Divergence : `SeatView` de 60 n'a pas `masked` et reconstruit l'avatar ; 40 impose que l'identité ne soit construite que par `PlayerIdentity` ; le podium de 80 recopie pseudo et avatar.
- Résolution : `SeatView extends PlayerIdentity` plus l'état de siège ; les `standings` du podium embarquent `PlayerIdentity::fromGamePlayer()`.
- Raison : C5 (40) propriétaire de l'identité affichée (boundary map n° 4) ; le masquage J2 doit s'appliquer partout sans refonte.

**R-30 — Résolution du siège par le jeton (C4 × C7 × C8 × C10).**
- Divergence : 40 écrit que tous les consommateurs résolvent le siège « uniquement par `seatIn()` », qui exige un salon ; 60 résout l'appartenance de `/f/` par une jointure `game_player` et le solo n'a pas de salon ; 60 demande à 40 une fonction de lecture du hash.
- Résolution : identification exclusivement par `PlayerTokenManager::current($request)?->hash()`, `kicked_at` toujours exclu ; `seatIn()` pour un salon donné, `Player::heldByToken()` + `whereNull('kicked_at')` pour `/f/`, le solo et `seat.active` ; aucune fonction nouvelle chez 40.
- Raison : l'intention de 40 (jamais d'autre identifiant, expulsé refusé) est tenue ; la lettre était inapplicable au solo et à `/f/`.

**R-31 — Constructeur des réponses d'image (C8 × C9 × C9-bis).**
- Divergence : `FrameBytesResponse::stream()` qui lève hors préfixe (60) contre `FrameImageResponse::make()` qui répond 404 (20) ; `X-Robots-Tag: noindex, nofollow` (60) contre `noindex` (20).
- Résolution : `App\Support\Frames\FrameImageResponse::make(FrameStoragePrefix, string)` seul ; 404 uniforme ; `noindex, nofollow` pour `/f/` et l'aperçu ; journalisation d'un fichier absent dans `make()`.
- Raison : 20 est propriétaire de la frame servable et de l'espace `App\Support\Frames` ; l'en-tête le plus strict ne coûte rien à l'aperçu.

**R-32 — Route d'aperçu admin (C8 × C9-bis).**
- Divergence : `admin.frames.preview` `GET /admin/frames/{frame}/preview/{variant}` (proposition de 60) contre `admin.catalog.frames.game|master` (20).
- Résolution : routes de 20.
- Raison : 60 écrivait lui-même « propriété de 20 pour le nom et le chemin ».

**R-33 — Jalon de l'annulation active (C8 × C14).**
- Divergence : 60 place `WithdrawContentFromLiveRounds` et `movie_suspended` au J1 ; les gestes qui seuls le déclenchent (`movie.suspended`, `movie.withdrawn`, `frame.suspended`, `frame.withdrawn`) sont au J2 chez 20.
- Résolution : job, cas d'incident et `LiveWithdrawalTest` au J2 ; au J1, dépublication et re-recadrage restent paresseux.
- Raison : un job sans déclencheur au J1 serait du code mort ; 20 est propriétaire du jalon de ses gestes.

**R-34 — URL d'un palier transmise avant sa garde (C7, C8 × C16).**
- Divergence : 90 exige que la charge de début de manche ne porte « jamais le `serve_token` d'un palier non encore servable » ; 60 envoie l'URL du palier 1 dans `round.scheduled` et celle du palier i+1 dans `tier.opened(i)`, avec `fetchNotBefore`, et borne la resynchronisation aux gardes franchies.
- Résolution : forme de 60 conservée ; la phrase de 90 est reformulée (l'URL précoce reste refusée par `ServeGuard` jusqu'à `Tᵢ − preload_lead_ms`) ; `nextTransitionAt` inclut la prochaine garde pour qu'un client resynchronisé récupère l'URL manquante.
- Raison : n° 47 (jeton du palier i frappé à l'ouverture de i−1) ; la barrière est temporelle côté serveur, pas le secret de l'URL.

**R-35 — Implémentations multiples de D29 et nom `RoundTimeline` (C7 × C13 × C16).**
- Divergence : trois modules pour la valeur du palier courant (`lib/round-clock.ts` de 60, `lib/tier-value.ts` de 80, `lib/game/round-timeline.ts` de 90) ; deux types `RoundTimeline` de formes différentes (charge de 60, structure cliente de 90).
- Résolution : un module, `lib/game/round-timeline.ts` (90), qui exporte `currentTier()` et `tierValueAt()` (signature de 80) ; la structure cliente devient `LiveRoundTimeline`, construite par `toLiveTimeline()` ; le type de charge garde le nom `RoundTimeline`.
- Raison : 90 possède le socle d'écran et son chemin est dans `WATCHED` ; 80 garde la définition de la fonction ; 60 garde le nom de la charge.

**R-36 — Emplacement des fichiers front et périmètre `WATCHED` (C0, C2, C5, C7, C9, C10, C13 × C16).**
- Divergence : 60 et 70 créent `lib/*.ts` et `hooks/*.ts` à la racine, et plusieurs contrats créent `types/*.ts` ou `lib/*.ts` hors des répertoires surveillés ; la méta-vérification de 90 ferait échouer tout fichier non classé, et `EXEMPT` ne peut que décroître.
- Résolution : fichiers de jeu sous `lib/game/` et `hooks/game/` ; `types/{game-wire,answers,scoring,pool,player,room-settings}.ts`, `lib/room-settings.ts` et `lib/frame-geometry.ts` ajoutés nominativement à `WATCHED`.
- Raison : C16 (90) propriétaire du périmètre (boundary map n° 41).

**R-37 — Source et ratio de `GameFrame` (C16 × C8 × C9).**
- Divergence : 90 décrit `src` comme « URL construite par Wayfinder » alors que 60 l'interdit ; 90 fixe le ratio par un style en ligne tiré de `frameFormat`, 20 crée le jeton `--aspect-frame`.
- Résolution : `src` = URL fournie par le serveur (URL d'objet de `frame-loader`, ou `game_url` en aperçu) ; conteneur en `aspect-frame`, `frameFormat` réservé aux attributs `width`/`height` de `<img>`.
- Raison : C8 (60) possède la production d'URL ; une seule source de ratio, `FrameGeometry`, déclinée en jeton testé.

**R-38 — Clés de traduction en conflit (C7, C10, C11, C12, C13 × C15).**
- Divergence : `game.help.prefix_rule` (70) contre `game.help.prefix` (05) ; `game.rules.scoring.*` (80) contre `game.help.scoring` (05, feuille) ; `game.connection.{lost,resyncing}` (60) contre `common.connection.*` (90) ; `game.lone_player.notice` (60) alors que C15 range « mode solo à deux » sous `game.round.*` ; préfixes `game.choices.*`, `game.score.*`, `game.seat.*`, `game.pause.*`, `game.host.*`, `game.errors.*` et `validation.attributes.{answer,choice}` absents du tableau de propriété.
- Résolution : `game.help.prefix` ; `game.help.scoring.*` (nœud) ; `common.connection.*` seul ; `game.round.lone_player` ; préfixes manquants ajoutés au tableau de C15 avec leur rédacteur.
- Raison : C15 (05) propriétaire des clés ; boundary map n° 31 (texte d'aide : écran à 90, textes par 70 et 80).

**R-39 — Définition de `nickname_normalized` (C5 × C12).**
- Divergence : 70 demande à `10` d'écrire `nickname_normalized = fold()` ; 40 définit `NicknameNormalizer::normalize()` = `fold()` puis suppression de tout ce qui n'est pas `[a-z0-9]`.
- Résolution : définition de 40 ; `saved_config.name_normalized` n'est pas fixé par cette feuille (sujet J2 de 50, idiome A6 de 10 inchangé).
- Raison : boundary map n° 26 (règle de pseudo et `NicknameNormalizer` à 40, sur la primitive de 70).

**R-40 — Extension Imagick en CI (C9 × C18).**
- Divergence : 20 exige `ext-imagick` au `composer.json` et `extensions: imagick` en CI dans le même commit (CLAUDE.md § 8) ; les jobs de C18 n'en parlent pas.
- Résolution : `ext-imagick` au `composer.json` et `extensions: imagick` dans le job `ci` de `tests.yml` sont **déjà présents** dans le dépôt ; seul le nouveau workflow `tests-mysql.yml` reçoit `extensions: imagick`.
- Raison : CLAUDE.md § 8 ; sans elle, le test « Imagick sait encoder le WebP » échoue en CI.

**R-41 — Renvois de contrats erronés dans la rédaction de 80.**
- Divergence : 80 cite « C07 » (lancement), « C08 » (événements), « C11 » (soumission), « C1 » (`PlatformLimits`), « C19 » (groupes Pest).
- Résolution : C6, C7, C10, C0, C18.
- Raison : index de cette feuille, aligné sur `synthesis.json › shared_contracts`.

**R-42 — Nom réel et anonymisation (C14 × D12).**
- Divergence apparente : D12 dit le nom réel « conservé après anonymisation » ; 20 vide `users.real_name` à l'anonymisation.
- Résolution : `users.real_name` vidé ; les instantanés `frame_review.reviewer_name` et `admin_action.actor_name` conservés ; test ajouté.
- Raison : lecture de D12 conforme à `10` l.489 (l'instantané est « explicitement exclu de l'anonymisation »), qui est la seule chose que la preuve exige.

**R-43 — Route de soumission et middleware de siège (C10 × C7).**
- Divergence : 70 adresse le siège par `{player:public_id}` dans l'URL ; `seat.active` de 60 ne compare que `X-Seat-Token` et suppose un salon ou le solo.
- Résolution : `seat.active` vérifie aussi que le siège lié `{player}` est tenu par le jeton courant et non expulsé, puis met le siège en mémoire pour le limiteur `answer`.
- Raison : sans cette clause, un tiers connaissant un `public_id` pourrait soumettre au nom d'un autre siège ; 60 propriétaire du middleware, 70 de la route.

**R-44 — Charge de `game.ended` (C7 × C13).**
- Divergence : 60 diffuse `{ outcome, roundsCompleted, roundsCount, podium }`, champs que `Podium` de 80 porte déjà (`gameStatus`, `roundsCompleted`, `roundsCount`).
- Résolution : `game.ended` porte `{ podium: Podium }`.
- Raison : « nettoyé des doublons » ; 80 est propriétaire du contenu du podium.

**R-45 — Retour du salon au lobby à la fin de partie (C13 × C6).**
- Divergence : 80 fait de `GameFinalized` le déclencheur de « `room → lobby` (50) » ; 50 garde `room.status = playing` jusqu'au « Rejouer » de l'hôte, podium compris.
- Résolution : `GameFinalized` ne touche pas au salon ; il rend « Rejouer » possible (`ended_at` non nul) ; seul `ReplayRoom` repasse en `lobby`.
- Raison : D32 (« Rejouer » geste d'hôte, refusé pendant le drainage) et 00 l.106/l.120 (réglages verrouillés jusqu'à lui) ; 50 est propriétaire du cycle de vie du salon (boundary map n° 24).

**R-46 — Voie capture : dérivé de jeu produit par le serveur (C9 × n° 0). Signalé au porteur.**
- Divergence : la résolution n° 0 (`needs_user_decision = false`, donc à appliquer selon `decisions-2309.md` l.85) fait envoyer par le navigateur « la source normalisée + le rectangle + le dérivé de jeu » ; C9 fait envoyer la source et le rectangle, et le serveur dérive le jeu du master.
- Résolution : C9 garde sa chaîne unique (dérivé toujours produit par le job depuis le master et le rectangle) ; tout le reste de n° 0 est appliqué (source normalisée ≤ 1920 px et ≤ `frameUploadMaxKilobytes`, rectangle, `source_hash` sur la source reçue, E10-17). A-24, A-52 et A-76 portent la mention « sous réserve de R-46 » pour la seule voie capture ; la voie TMDB est conforme à n° 0.
- Raison : **D6 prime sur une résolution par défaut** : le plancher est « revalidé côté serveur » et « vérifiable par requête » ; un dérivé fabriqué par le navigateur ferait de `crop_width` en base une déclaration invérifiable sur les octets servis. S'y ajoutent une seule chaîne pour TMDB, capture et re-recadrage, et un re-recadrage sans renvoi de fichier. La branche est hors J1 : si le porteur retient la lettre de n° 0, seule cette branche change.

**R-47 — Clés de la grille : suffixe `.label` (C14-bis, C15 × n° 6). Signalé au porteur.**
- Divergence : la résolution n° 6 nomme les clés `admin.exclusion_grid.v{n}.{slug}` plus `.help` ; C14-bis écrit `admin.exclusion_grid.v{n}.{slug}.label` et `.help`.
- Résolution : suffixe `.label` retenu ; A-59 aligné.
- Raison : structure des tableaux de langue PHP, où une clé ne peut pas être à la fois une feuille (`{slug}`) et un nœud (`{slug}.help`). Écart de forme seulement, sans effet de fond : le domaine (`admin`), le préfixe versionné et la présence d'une aide par item sont ceux de n° 6.

### Appels inter-contrats vérifiés sans divergence

| Appelant → appelé | Signature vérifiée | Chapitres |
|---|---|---|
| 20 affiche le vivier de 30 | `PoolReporter::catalogueWorksByFramesPerRound(): array<int, int>`, N de `MIN` à `MAX_FRAMES_PER_ROUND`, compté en œuvres ; remplace `DashboardController::poolByFramesPerRound()` ; libellés `admin.dashboard.pool.*` reformulés en « vivier catalogue » et « œuvres » par 20 | C2 |
| 40 bâtit le pseudo sur la primitive de 70 | `AnswerKeyNormalizer::fold(string): string`, propriétés exigées par 40 identiques à celles écrites par 70 (sans troncature, sans article, sans chiffres convertis) | C5, C12 |
| 70 tire ses leurres dans le vivier de 30 | `PoolQuery::movies(PoolScope::forDecoys($round, $at))` et ses variantes `withThemeIds([])`, `withFramesPerRound(null)` ; `withoutNoRepeat()` ne sert qu'au diagnostic de `PoolReporter`, jamais aux leurres | C2, C3, C11 |
| 60 substitue par 30 à la frappe | `VariantChooser::substitute(Round, RoundTier, list<int>, CarbonImmutable): ?int` ; `ReplacementRoundChooser::next(Game): ?Round` | C3, C8 |
| 60 et 50 lisent la fin de partie de 80 | `FinalizeGame::handle(Game, GameStatus, CarbonImmutable): bool` ; `GameFinalized` après commit ; `ended_at` non nul conditionne `ReplayRoom` | C6, C7, C13 |
| 100 lit le prédicat de 60 | `GamesInProgress::count()` et `maxNaturalDurationMs()` ; `game:reschedule` | C17, C18-bis |
| 60 et 90 lisent le format de 20 | `FrameGeometry::GAME_WIDTH` / `GAME_HEIGHT` → prop `frameFormat` ; jeton `--aspect-frame` | C9, C16 |

---

## Exigences consolidées adressées à 10

`10` est le seul propriétaire du schéma : ces exigences sont des demandes, que le rédacteur de `10` inscrit ou refuse en le signalant. Les dix premières touchent une colonne ou un cas d'enum ; les suivantes sont textuelles. Bilan : **quatre changements de colonne** (trois colonnes nouvelles, un type élargi), **six familles de cas d'enum** sans migration, aucun index nouveau.

### Colonnes et cas d'enum

**E10-01 — `player.kicked_at`**
- Nature : colonne [nouvelle] `timestamp(3)`, **nullable**, placée après `left_at`, **aucun index** (lue via `player_room_token_uq`).
- Migration : additive, `add_kicked_at_to_player_table`. Modèle : cast `datetime`, `@property CarbonImmutable|null`, hors `#[Fillable]`, `#[Hidden]` (voir E10-34). Jamais remise à NULL ; supprimée avec la ligne.
- Section : 10 § 7.1. Motif : D15. Émetteurs : 40 (C4), 60 (C7, C8).

**E10-02 — `users.real_name`**
- Nature : colonne [nouvelle] `string(255)`, **nullable**, aucun index ; `#[Hidden]`, hors `#[Fillable]`.
- Migration : additive, `add_real_name_to_users`. Règles : rôle ≥ `curator` ⟹ non vide (garde `User::saving`) ; vidée à l'anonymisation (les instantanés E10-22 sont conservés, R-42) ; ajouter l'invariant à A16.
- Sections : 10 § 5.1, § 5.5, A16. Motif : D12. Émetteur : 20 (C14, G20 E7).

**E10-03 — `round_choice_set.rendered_locale`**
- Nature : colonne [nouvelle] `string(5)`, **nullable**, aucun index. Sens : locale effective des quatre chaînes de la ligne ; NULL si elles sortent de `title_original` (rang 3 ou mode dégradé).
- Migration : additive. Table de faits purgeable, hors du périmètre de l'instantané bloquant.
- Section : 10 § 7.8. Motif : n° 55 et règle 3 (rejeu identique du `lang`). Émetteur : 70 (C11).

**E10-04 — `game_player.final_rank`**
- Nature : type élargi de `unsignedTinyInteger` à **`unsignedSmallInteger`**, nullabilité inchangée.
- Migration : amendement de la migration de création `2026_09_22_100027_create_game_player_table`, pas encore déployée.
- Raison : `game_player` n'est pas borné (retardataires en rotation, partis classés) ; plus de 255 sièges classés lèveraient l'erreur 1264 au gel.
- Section : 10 § 7.3. Motif : n° 67 (classement sans plafond). Émetteur : 80 (C13, G80 E7).

**E10-05 — `admin_action` : liste fermée à 21 cas et sujet `site`**
- Nature : enum `AdminActionType` + 6 cas (`movie.published`, `frame.unpublished`, `frame.grid_unpublished`, `frame.unsuspended`, `site.closed`, `site.reopened`) ; enum `AdminActionSubject` + `site`. Colonnes existantes `action string(40)`, `subject_type string(20)`, `subject_id` nullable : **aucune migration**.
- Invariants à écrire : `subject_type` dérivé de l'action ; `subject_id` NULL ssi sujet `site` ; acteur réservé `console` (`actor_id` NULL, seulement `role.changed` et `site.*`), acteur `system` réservé aux deux gestes automatiques ; motifs obligatoires selon C14 ; tous les cas en rétention permanente.
- Section : 10 § 8.3. Motifs : n° 5, D13. Émetteurs : 20 (C14, G20 E9), 100 (`site.*`).

**E10-06 — `round_player.input_state` : cas `text_exhausted`**
- Nature : cas d'enum `RoundPlayerInputState::TextExhausted = 'text_exhausted'` (14 caractères ≤ `string(20)`), **aucune migration**. `isClosed()` faux pour lui.
- Texte : liste de 10 § 7.6 l.855 ; A16 : « `text_exhausted` inatteignable hors `input_difficulty = normal` ». Le prédicat de fin anticipée est E10-53.
- Motif : D20. Émetteurs : 70 (C10), 60 (C7).

**E10-07 — `round.cancel_reason` : cas `choices_unavailable`**
- Nature : cas `RoundIncidentReason::ChoicesUnavailable` (≤ `string(30)`), **aucune migration**. J1 : annulation d'une manche en Facile quand aucun QCM n'est composable.
- Section : 10 § 7.4 (liste des motifs). Motif : D21. Émetteur : 70 (C11).

**E10-08 — `RoundIncidentReason` : cas `movie_suspended`**
- Nature : cas `RoundIncidentReason::MovieSuspended` (≤ `string(30)`), **aucune migration** ; **J2**, avec les gestes de suspension (R-33).
- Section : 10 § 7.4 (liste des motifs). Motif : n° 52. Émetteur : 60 (C8).

**E10-09 — `answer_key.key_kind` et `guess.match_kind` : cas `subtitle`**
- Nature : cas `AnswerKeyKind::Subtitle` (`string(16)`) et `GuessMatchKind::Subtitle` (`string(10)`), **aucune migration** ; reprojection par `catalog:reproject`.
- Texte : liste close de 10 § 3.5 (l.345, 348, 354, 356) : seules `prefix` et `subtitle` sont soumises à la règle de collision ; sous-titres dérivés des seuls titres, jamais d'un alias ; précédence exacte > `prefix` > `subtitle` ; `is_ambiguous` porte sur les deux natures dérivées ; § 7.6 l.872 pour `match_kind`.
- Motif : D23. Émetteur : 70 (C12).

**E10-10 — `frame.processing_error`**
- Nature : cast `FrameProcessingFailure`, liste fermée de douze cas dont la **valeur est la clé de traduction** complète ; colonne existante `string(120)`, **aucune migration**.
- Section : 10 § 4.1. Motif : C9. Émetteur : 20 (G20 E3).

### Amendements textuels

**E10-11 — § 1.1 (l.29) et § 6.1 : identifiants aux frontières.**
- (a) La charge client des réglages expose `themeKeys` (`theme.key`), jamais `themeIds` ; le stockage reste en `themeIds` (§ 1.6). Émetteur : 50 (C0).
- (b) Exception écrite : le back-office (curator+) adresse `movie` et `frame` par leur `id`, jamais une surface joueur. Émetteur : 20 (G20 E10).
- (c) « La forme du `player_token` appartient à 40 [J1] ». Émetteur : 40 (C4). Motifs : D2, n° 36.

**E10-12 — § 1.3 l.74.** « Aucune chaîne n'excède la colonne » remplace « aucune chaîne saisissable n'est jamais tronquée ». Motif : n° 62. Émetteur : 70 (C12).

**E10-13 — § 1.7 l.134 et docblock de `User.php`.** « Aucun SSR en v1. » Motif : n° 70. Émetteur : 90 (C16).

**E10-14 — § 1.8 L1.** Ajouter la lecture « publiable » : tout score montré à un autre siège se calcule sur les manches `revealing`/`completed` (`ScoreScope::Publishable`). Motif : n° 67. Émetteur : 80 (G80 E4).

**E10-15 — § 3.2 l.281, § 7.2 l.782, § 7.8, § 12 l.1194.** Les leurres sont tirés à la **première composition** (`T₁` en Facile, `T_N` en Normal), jamais au lancement ; contextes `DrawContext::decoys()` et `decoysOriginal()` ; § 12 : « une fois par manche, à la première composition ». Motifs : n° 57, D21. Émetteurs : 70 (C11), 30 (C3).

**E10-16 — § 3.5 l.362, § 12 l.1191-1192.** `answer_key` est relue **à chaque soumission**, aucun cache au J1 ; la ligne « `WHERE normalized = ?` » devient « à chaque soumission » ; la phrase de repli « une fraction des soumissions » est retirée. Motifs : n° 58, L4. Émetteur : 70 (C10, C12).

**E10-17 — § 4.1 `source_hash`.** Voie `tmdb` : SHA-256 des octets `original` téléchargés par le serveur ; voie `capture` : SHA-256 de la source reçue avant réencodage ; « original jamais conservé » devient « fichier provisoire déposé sous `master_path`, remplacé par le master normalisé au premier traitement réussi ». Motif : n° 0. Émetteur : 20 (G20 E1).

**E10-18 — § 4.1 et § 10 : géométrie.** Master WebP de largeur **exactement** 1920 (source paysage, largeur ≥ 1280) ; `crop_*` exprimé dans cet espace, 16:9 exact, largeur multiple de 16 ; `game_width`/`game_height` exactement 1280×720 ; plancher D6 vérifiable par la requête d'audit `crop_width × 100 ≤ pct × 1920`, sans colonne neuve. Motifs : D5, D6. Émetteur : 20 (G20 E2).

**E10-19 — § 4.1 et § 4.2 : re-recadrage.** En place : sortie de `published` (`frame.unpublished`) avant le job ; nouveau `game_path` à chaque traitement ; le job refuse une frame `withdrawn` ou `published`. Sondes : `published_hash = reviewed_hash` de `published_review_id` ; frames `ready` conformes (1280×720, padding) ; audit du plancher. Motifs : n° 16, D6. Émetteur : 20 (G20 E4).

**E10-20 — § 4.1 l.475-476 : prédicat de service.** Partie (2) : pour `pending`/`running`, `game.status = running` exigé, sans exception pour la manche 1 ; pour `revealing`, `served_at` non nul **et** `now < reveal_ends_at`, jamais un palier non ouvert. Partie (3) : appartenance par `game_player` (hash du jeton, `kicked_at` nul, `status ≠ kicked`, `first_round_number` NULL ou ≤ `round_number`). Motifs : D14, n° 47, n° 48. Émetteur : 60 (C8).

**E10-21 — § 4.2 : publication d'une frame.** Toute transition d'une frame vers `published` insère sa propre ligne `frame_review` passante ; seule exception : la levée admin (`frame.unsuspended`, `movie.unsuspended`), et seulement si la dernière revue passante porte sur le `published_hash` et la version de grille courants ; sinon retour en `unpublished`, ou `draft` si `first_published_at` est NULL. Motifs : A2, n° 5, n° 18. Émetteur : 20 (G20 E5, C14-bis).

**E10-22 — § 4.2 l.489 et § 8.3 : instantanés nominatifs.** `frame_review.reviewer_name` et `admin_action.actor_name` = instantané de `users.real_name` (et non de `users.name`), conservés à l'anonymisation. Motif : D12. Émetteur : 20 (G20 E8).

**E10-23 — § 4.3 et A16 : cascade, levée et couverture.** La cascade de suspension ne suspend que les frames `published` ; règle de levée d'E10-21 ; A16 « published ⇒ `levels_mask & 21 = 21` » devient une **garde de transition** vers `published` ; un film publié qui perd sa couverture 1/3/5 reste `published`, jouable pour N ≤ `levels_count` avec repli de niveau, signalé « incomplet » ; aucune dépublication automatique ; le vivier ne teste que `levels_count ≥ N`. Motifs : n° 4, n° 18. Émetteurs : 20 (G20 E6), 30 (C2).

**E10-24 — § 4.3 : garde de devinabilité.** Condition de publication d'un film : « au moins une clé exacte non vide » ; appliquée par le geste de publication de 20. Motif : boundary map n° 30. Émetteur : 70 (C12).

**E10-25 — § 4.3 l.520 : substitution.** La variante de substitution est choisie par `VariantChooser::substitute()` (30) au moment de la frappe du jeton du palier (60), au même niveau seulement, fichier présent sur le disque. Motif : n° 47. Émetteurs : 30 (C3), 60 (C8).

**E10-26 — § 6.1 l.632 : bornes croisées.** Bornes 1 et 2 refusées par `fromInput()` ; borne 3 à la garde de vivier ; bornes 4 et 5 avertissements de `warnings()`, jamais bloquantes. Motif : n° 39. Émetteur : 50 (C0).

**E10-27 — § 6.1 l.645-649 et l.651 : énumération de `PlatformLimits`.**
- `speedBonusMaxPercent(N) = min(SPEED_BONUS_MAX_PERCENT_CAP, intdiv(FULL_PERCENT, N − 1))` = 50, 50, 33, 25 ; pourcentage entier, **non surchargeable**, exception hors bornes ; `speedBonusMaxFraction = 0.50` supprimé.
- Ajouts : `drawSubstituteMargin()` (3), `roomMemoryWindowDays()` (90), `roomMemoryWindowRounds()` (500), `themeSelectorMinPool()` (150), `lobbyBroadcastDebounceMs()` (300), `frameCropMaxWidthPercent()` (80, [70, 90]), `frameCropMinWidthPx()` (640) ; `preloadLeadMs()` écrêtée dans [1500, 2500].
- « Construit depuis `config/` » devient « `config/game.php › platform`, sauf B_max » ; ajouter « constantes d'instance, jamais résolues par compte ni par plan ».
- Le test de refus de § 6.1 couvre aussi les clés `speedBonusMaxPercent` et `speedBonusMaxFraction`.
- Motifs : D22, n° 64, n° 21. Émetteurs : 50 (C0), 80 (G80 E1), 30 (C2, C3), 20 (C9).

**E10-28 — § 6.2 `room.status` et `launched_at`.** `playing` va du lancement au « Rejouer » de l'hôte, podium compris ; `launched_at` n'est posé qu'au premier lancement, par `LaunchGame`. Motif : D32. Émetteur : 50 (C6).

**E10-29 — § 6.2 l.664.** L'attribut exposé est `roundsCount` (camelCase), jamais `rounds_count`. Motif : n° 41. Émetteur : 50 (C0).

**E10-30 — § 6.2 l.669, § 11.1 l.1129, § 15 l.1415.** « Purge du lobby » devient « archivage anticipé du lobby ». Motif : n° 40. Émetteur : 50 (C6).

**E10-31 — § 7.1 l.717 : canaux.** « `public_id` nomme le canal **privé** du siège ; le canal de présence du salon est nommé par une clé HMAC dérivée de `room.id`, sans colonne. » Motif : vérification de 60, recyclage de `room_code` (§ 6.2). Émetteur : 60 (C7).

**E10-32 — § 7.1 l.721 : formes normalisées.** `nickname_normalized` = `NicknameNormalizer::normalize()` (40), bâti sur `AnswerKeyNormalizer::fold()` (70), séparateurs retirés, jamais le normaliseur d'`answer_key` ; la validation refuse une forme vide ou de plus de 20 caractères. `saved_config.name_normalized` n'est pas touchée : A6 (alphabet `[a-z0-9 ]`) reste la règle, sujet J2 de 50. Motifs : n° 29, R-39. Émetteurs : 40 (C5), 70 (C12).

**E10-33 — § 7.1 l.723.** « SHA-256 du `tid` du `player_token` (forme : 40 [J1]), jamais de la valeur du cookie. » Motif : n° 36. Émetteur : 40 (C4).

**E10-34 — § 7.1 l.744.** `#[Hidden]` de `Player`, aligné sur le code (n° 43) : `id`, `room_id`, `user_id`, `user`, `player_token_hash`, `active_seat_token`, `nickname_normalized`, `kicked_at` ; le test cité l.744 couvre la liste entière. Motifs : n° 43, 10 l.721 (« jamais affichée »), D15. Émetteur : 40 (C4).

**E10-34bis — § 6.2 l.661 : pose de l'hôte.** « Réécrite exclusivement par l'action de transfert » reste vrai à la création : la création du salon pose l'hôte **en appelant l'action de transfert dans la même transaction** (n° 43). Émetteur : 50 (C6).

**E10-35 — § 7.1 : unicité du pseudo.** Elle porte sur tous les sièges du salon, partis et expulsés compris, jusqu'à l'archivage (portée réelle de `player_room_nickname_uq`). Motif : C5 I5.4. Émetteur : 40 (C5).

**E10-36 — § 7.2 l.761 et l.773 : fin de partie.** `rounds_completed = COUNT(round completed)`, maintenu par 60, recalculé au gel ; `ended_at` écrite **exclusivement** par `FinalizeGame` ; rappel : « `ended_at IS NULL` ⟺ statut non terminal ». Motif : n° 67. Émetteurs : 80 (G80 E11), 60 (C17).

**E10-37 — § 7.2 l.763 : `draw_pool_size`.** Compté en œuvres ; `#[Hidden]` conservé par hygiène ; justification « réduirait le champ » retirée. Motifs : n° 23, n° 28. Émetteurs : 50 (C6), 30 (C2).

**E10-38 — § 7.2 l.764-768, l.787 et § 6.1 l.649 : `scoring_version`.** Écrite depuis `App\Support\Scoring\ScoringRules::VERSION` ; `points_bonus` écrit sous `speedBonusMaxPercent(N)`, constante de code ; déclencheurs d'incrément = C13 § 4.7 (table B_max, forme ou arrondi du bonus, sélection du palier dont grâce et plancher QCM, chaîne de départage, définition d'un agrégat, défaut de `tierGraceMs` ou de `preloadLeadMs`, règle de l.764-765 et l.768 inchangée) ; l'exemple « 0,50 → 0,40 » est réécrit en pourcentage entier. Motifs : D22, n° 64. Émetteurs : 80 (G80 E2), 50 (C0).

**E10-39 — § 7.2 l.769 : `validation_version`.** Écrite depuis `App\Support\Answers\AnswerRules::VERSION` ; couvre la translittération (fixtures), les articles, la règle romaine, les chiffres stricts, le barème de tolérance, la dérivation du sous-titre, les séparateurs et la longueur minimale, la marge de quasi-juste ; **pas** le plancher du QCM, couvert par `scoring_version` (R-18). Motif : n° 61. Émetteur : 70 (C12).

**E10-40 — § 7.2 l.762 et l.782 : graine.** La liste d'exemples de contextes est remplacée par le registre fermé `DrawContext` et l'algorithme PRF de C3 (clé = `draw_seed` hexadécimal, message = `contexte#bloc`, mots `N8`, rejet) ; `tiebreak:{roundId}` retiré ; « départage à égalité » devient « départage à égalité **des variantes** » ; aucune égalité de score n'est tranchée par la graine ; les deux tests sont reformulés (graines différentes à la même seconde ; rejeu à entrées figées). Motif : n° 66. Émetteurs : 30 (C3), 80 (G80 E8).

**E10-41 — § 7.2 et A16 : partie figée.** Lister les colonnes figées de `game`, immuables après l'INSERT (`mode`, `input_difficulty`, `rounds_count`, `frames_per_round`, `draw_seed`, `draw_pool_size`, `tier_grace_ms`, `preload_lead_ms`, `settings_version`, `settings_snapshot`, `scoring_version`, `validation_version`, `started_at`, `room_id`) ; A16 : « au plus une partie à `ended_at` NULL par `room_id` », « colonnes typées de `game` = `settings_snapshot` », « toute ligne `game` naît de `OpenGame` ». Émetteur : 50 (C6).

**E10-42 — § 7.3 : naissance de `game_player`.** Créé au lancement pour chaque siège non parti (`connected` ou `disconnected`), `display_*` gelés, `first_round_number = 1`. Émetteur : 50 (C6).

**E10-43 — § 7.3 l.795-799 : agrégats.** Filtre unique `completed` ; invariant `correct_answers ≤ rounds_played` ; `final_rank` NULL en solo **ou** si `rounds_played = 0` ; les quatre autres agrégats non nuls après le gel ; écrits exclusivement par `FinalizeGame` ; une manche `cancelled` n'entre dans aucun agrégat. Motif : n° 67. Émetteur : 80 (G80 E3).

**E10-44 — § 7.3 l.799, § 7.6 l.885, § 12 l.1200 : bornes.** Borne de `guess` : `roomSeats() × min(M + drawSubstituteMargin(), |vivier|)`, soit 396 aux défauts ; `game_player` n'est pas borné à 12 (les partis restent classés). Motif : n° 65. Émetteur : 80 (G80 E6).

**E10-45 — § 7.4 : manches de réserve.** `sequence_index` de 1 à `min(M + drawSubstituteMargin(), œuvres)` ; les manches de réserve sont matérialisées au lancement (`round_number` NULL, `pending`, jamais démarrées tant qu'elles ne remplacent rien) et reçoivent au remplacement le numéro de la manche annulée. Émetteur : 30 (C3).

**E10-46 — § 7.4 l.813 : pause.** Une manche `pending` programmée est déprogrammée (`started_at` remis à NULL) à la mise en pause ; son palier 1 n'est plus servi. Motifs : n° 47, n° 48. Émetteur : 60 (C7).

**E10-47 — § 7.4 l.824 et l.835, docblock de `RoundTier`, `RoundTierFactory::served()` : écrivains du palier.** `serve_token`, `served_frame_id` et `substitution_reason` écrits **à la frappe** (ouverture du palier `i−1`, ou programmation pour `i = 1`) par `MintTierServeToken` seul ; `served_at` = instant **théorique** `started_at + starts_at_offset_ms`, écrit à `Tᵢ` par `OpenTier`, même si le job est en retard ; `seen_frame` upserté à `Tᵢ` sur `served_frame_id`, en multijoueur seulement. Motif : n° 47. Émetteur : 60 (C8).

**E10-48 — § 7.5 l.845 et l.849 : renvois.** La fenêtre d'acceptation (`status = running` ET `receivedAt < (ended_at ?? started_at + D) + tier_grace_ms`) est écrite dans 70, le plancher du QCM dans 80 ; le test de rejeu lit aussi `guess.source`, `game.input_difficulty` et `game.frames_per_round`. Motifs : n° 59, D22. Émetteurs : 70 (C10), 80 (G80 E9).

**E10-49 — § 7.6 l.857 : naissance de `round_player`.** Créée à `T₁` pour chaque siège non parti (connecté ou déconnecté), non expulsé, éligible dans `game_player`. Motif : n° 47. Émetteur : 60 (C7).

**E10-50 — § 7.6 l.877 et docblock de `Guess` : `prefix_was_ambiguous`.** « Vrai si et seulement si la forme soumise était portée, à l'instant du match, par un autre film publié (appariement exact d'une clé de nature exacte homonyme) ; toujours faux pour un `prefix` ou un `subtitle` accepté. » Motif : n° 58. Émetteur : 70 (C10).

**E10-51 — § 7.6 l.883 : transaction de verrouillage.** Elle relit `round_player FOR UPDATE` après `round FOR UPDATE` ; ordre de verrouillage global room → player → game → round → round_player ; elle ne verrouille jamais `game`. Motif : n° 58. Émetteurs : 70 (C10), 60 (C7), 80 (C13).

**E10-52 — § 7.6 et docblock de `Guess` : publicité des points.** Points et `tier_index` publiables dès `round.status = revealing` (via `Scoreboard`, jamais `toArray()`), et non « après `reveal_ends_at` ». Motif : n° 65. Émetteur : 80 (G80 E10).

**E10-53 — § 7.7 l.892 : fin anticipée.** « Tous les participants ont leur saisie close » : `COUNT(participants dont input_state NOT IN ('open','text_exhausted'))` égale le nombre de participants, **et** `COUNT(participants) ≥ 1` (borne conservée, 10 § 7.7 l.892). Motifs : D20, n° 50. Émetteurs : 70 (C10), 60 (C7).

**E10-54 — § 7.8 l.911 et A11 l.1351 : ordre du QCM.** L'ordre dérive de `SeededPrf::forGame(game)->permutation(DrawContext::qcmOrder(sequence_index, player.public_id), 4)` (C3), contexte `draw:qcm:{sequenceIndex}:{playerPublicId}`, jamais de `round_id` ni de `player_id` ; `HMAC(draw_seed, round_id, player_id)` et `qcm:{roundId}:{playerId}` disparaissent des **deux** passages. Émetteurs : 30 (C3), 70 (C11).

**E10-55 — § 7.9 : mémoire périmée.** Une ligne `seen_frame` antérieure à `RoomMemoryWindow::since()` est lue comme absente ; le tirage ne dépend pas de l'heure de la purge. Émetteur : 30 (C3).

**E10-56 — § 11.1 l.1133 (ligne `seen_frame`) et § 12 l.1209 : fenêtre unique.** La borne « 90 jours OU 500 manches » cite `RoomMemoryWindow::since()` et `PlatformLimits::roomMemoryWindowDays()` / `roomMemoryWindowRounds()`, source unique partagée par la non-répétition, la préférence de variante et la purge. Émetteurs : 50 (C0), 30 (C2).

**E10-57 — § 7.9 l.932 : L4.** « Exactement une écriture par refus, ou aucune, jamais conditionnelle à la proximité » ; test sous `Queue::fake()` ; « aucune ligne » s'entend d'**aucune insertion** : l'unique `UPDATE` conditionnel de `round_player` (compteur de tentatives, S8a de C10) est la même écriture pour tout refus. Motifs : n° 63, D24. Émetteur : 70 (C10).

**E10-58 — § 7.10 l.946 : barrière 4.** « Aucun identifiant **d'adressage** réutilisable » ; le résidu (hachage des octets appris en solo) est nommé dans 60. Motif : D16. Émetteur : 60 (C8).

**E10-59 — § 10 l.1098 : en-têtes.** Service d'image : `X-Content-Type-Options: nosniff`, `Cross-Origin-Resource-Policy: same-origin`, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow` ; jamais `Accept-Ranges`, `Content-Disposition`, `Last-Modified`, `ETag`, `Expires`, ni `Set-Cookie` sur `/f/` (l'aperçu admin porte les cookies de sa pile) ; 404 identique quelle que soit la cause. Émetteurs : 60 (C8), 20 (C9).

**E10-60 — § 10 l.1106-1112 (dont l.1108, l.1110) : préchargement et repli.** L'exception de la manche 1 pendant `P` est supprimée ; la marge de préchargement est `preload_lead_ms`, jamais `dᵢ` ; aucun LQIP : le repli d'un client lent est un cadre fixe 16:9, un aplat au token et un indicateur. Motifs : n° 48, D7. Émetteurs : 60 (C8), 20 (G20 E12), 90 (C16).

**E10-61 — § 10 : second lecteur du disque.** La route d'aperçu admin (`admin.catalog.frames.game|master`) est le seul second lecteur du disque `frames` ; `FrameImageResponse` est partagé avec `/f/{serveToken}` ; `master/` n'est lu que par l'aperçu. Motif : n° 1. Émetteur : 20 (G20 E11).

**E10-62 — § 11.1 l.1127 et § 11.3 sonde n° 1 : `stale_game`.** La clôture forcée passe par `FinalizeGame(Interrupted, lastKnownActivity())` avant la purge ; la réparation d'une partie signalée par la sonde n° 1 passe par la même action. Motif : n° 67. Émetteur : 80 (G80 E5).

**E10-63 — § 11.1 et § 7.9 l.934 : rétention des quasi-justes (J2).** Deux lignes au tableau : « tampon de manche des refus (cache) : au plus `D + R` + marge, aucun identifiant de personne » ; « compteur k-anonyme de quasi-justes (cache) : 90 jours, sans identifiant de manche » ; § 7.9, « tenue en cache » précisé en conséquence. Motif : D24. Émetteur : 70 (C12).

**E10-64 — § 12, lignes « Vivier » et point (1).** Vivier compté en œuvres (`COUNT(DISTINCT CASE …) + COUNT(DISTINCT group_id)`, sans `GROUP BY`) ; thèmes intersectés avec les thèmes publiés, branche sans thème si l'intersection est vide ; aucun masque 21 dans le vivier ; la recommandation de fenêtre l.1209 devient la règle `RoomMemoryWindow` ; « joué = `started_at` non nul et ≤ l'instant de lecture (T₁ franchi), annulée comprise », avec le résidu `stale_game` nommé dans 30. Motifs : n° 23, n° 4, n° 25. Émetteur : 30 (C2).

**E10-65 — § 12 l.1195 et § 15 l.1410 : URL de resynchronisation.** « Au plus une URL par palier dont la garde est franchie ; pendant la révélation, les URL des paliers **ouverts**, jusqu'à `reveal_ends_at` » ; le test nommé de t = 1 s est conservé. Motifs : n° 49, D14. Émetteur : 60 (C7).

**E10-66 — § 12 l.1197 : coût du service d'image.** Environ 5 lectures par clé, aucun index nouveau. Motif : n° 47. Émetteur : 60 (C8).

**E10-67 — § 15 : propriété et placement.**
- l.1407 : normalisation, tolérance et quasi-juste « écrits dans 70 » (émetteur 70) ;
- l.1408 : retirer « À confirmer par 80 » : une manche `cancelled` est hors `rounds_played` (80, G80 E12) ;
- l.1409 : `FrameLevelCoverage::nominal(int)` / `select(int, int)` (30) ;
- l.1412 : l'« entraînement assisté » est spécifié par 60 (D18, D19) ;
- l.1414 : la ligne 40 porte pseudo, liste noire, avatars et forme du jeton ; la matrice des rôles passe à 20 (40 ; n° 11, D2) ;
- l.1419 : le placement des tests nommés est écrit par 100 selon C18.

**E10-68 — A7.** Retirer la phrase périmée (00 et questions-ouvertes n'écrivent plus « support ») ; 20 confirme A7. Motif : n° 12. Émetteur : 20 (C14-bis).

---

## Amendements consolidés à 00, 05, questions-ouvertes, CLAUDE.md et REPRISE

Format : fichier et emplacement · texte cible · motif · émetteur(s). Les numéros de ligne sont ceux relevés par les groupes au commit `d167a6a` ; le rédacteur qui applique l'amendement retrouve le passage par son contenu si la ligne a bougé.

### `docs/specs/00-overview.md`

**A-01** — l.7, l.17, l.75, l.97, l.118, l.171, l.437 · `P` devient le **décompte de lancement** (constante serveur `EngineConstants::launchCountdownMs()`) ; `R` n'est plus une fenêtre de préchargement ; tous les paliers sont servables dès `Tᵢ − preload_lead_ms`, sans exception pour la manche 1 ; l.97, borne 4 (« R < 5 s ») : motif d'accessibilité seul (`aria-live`, reprise de focus). · n° 48 · 60, 50.

**A-02** — l.97, l.171 et principe 6 l.383 · Retirer le LQIP : le repli d'un client lent est un cadre fixe 16:9, un aplat au token de thème et un indicateur de chargement. · D7 · 90, 20 (G20 E12).

**A-03** — l.20-21 · La révélation montre les N images servies, le titre dans la langue du joueur, le titre original s'il diffère et l'année ; « affiche » et « précharge la manche suivante » sont retirés ; « fige les scores » devient « clôt la saisie ; rend publiables points et paliers de la manche ». · D14, n° 53, n° 65 · 60, 80.

**A-04** — l.27 · Fin anticipée = « tous les participants ont leur saisie close » (`text_exhausted` n'est pas close) ; l'affichage « mode solo » à deux joueurs devient le libellé `game.round.lone_player` (un seul joueur connecté), sans effet sur `game.mode`, le rang ni les compteurs. · n° 50, n° 65, D20 · 60, 70, 80.

**A-05** — l.50 · « Essai unique **par siège** » ; « en Normal, épuiser le texte libre laisse le QCM ouvert, poussé à `T_N` ». · n° 51, D20 · 60, 70.

**A-06** — l.52 · « Leurres tirés à la première composition, dans le vivier du salon puis dans le catalogue publié (non-répétition conservée), puis mode dégradé `title_original` pour tout le salon ; jamais le film cible, son `movie_group`, les films des manches démarrées, ni aucune manche future. » · D21 · 70.

**A-07** — l.54 et l.56 · « seigneur des anneaux 2 » est accepté « si l'alias existe » ; « The Two Towers » et « Les Deux Tours » restent vrais par la règle du sous-titre ; ajouter : « le sous-titre (partie après le premier séparateur) est accepté sauf s'il désigne aussi un autre film publié, même règle que le préfixe » et « une suite de chiffres différente n'est jamais tolérée ». · D23, Q70-1, Q70-7, n° 60 · 70.

**A-08** — l.58 · « 1 tentative par seconde et **par siège** ». · n° 51 · 70.

**A-09** — l.68-86 (tableau des réglages) et l.366 · Ajouter une colonne de jalon ; `allowLateJoin` est la 3ᵉ variable d'ajustement du J1 (réglage masqué, défaut non) ; pour R, retirer « fenêtre de préchargement de la suivante ». · D17, n° 48 · 50.

**A-10** — l.90 et l.178 · `B_max = min(50 %, 100 % / (N − 1))`, en pourcentage entier (50, 50, 33, 25 %), constante d'instance, jamais surchargeable ; mode sans gradient réécrit : le bonus classe la promptitude **à l'intérieur** de chaque palier, la rapidité globale ne départage qu'à points et bonnes réponses égaux, et attendre la frontière y paie, d'où l'avertissement. · D22, n° 65 · 50, 80.

**A-11** — l.96 et l.117 · « Marge `PlatformLimits::drawSubstituteMargin()` (3), comptée en œuvres » ; « min(M + marge, |vivier|) œuvres ». · n° 21, n° 23 · 30, 50.

**A-12** — l.100 · « Jamais inlançable » s'entend des bornes du value object ; le vivier est traité par le grisage et le N jouable le plus proche ; au J1, avec un catalogue en passe 1, Hardcore (N = 5) reste grisé. · — · 50.

**A-13** — l.104 et l.116 · `noRepeatMovies` rejoint les réglages fautifs (quatrième cause) ; le remède du J1 est « nouveau salon », l'interrupteur de l'onglet Avancé s'ajoute au J2. · n° 44, D28 · 50, 30.

**A-14** — l.106, l.120 et l.134 · « Rejouer » est un geste d'hôte, refusé pendant le drainage, et les réglages restent verrouillés jusqu'à lui ; l.134 : « expulsion (points conservés ; le jeton de l'expulsé est refusé dans ce salon jusqu'à l'archivage, sans réadmission) ». · D32, D15 · 50, 40.

**A-15** — l.113 · « Lettres latines (latin de base, Latin-1, Latin étendu-A), chiffres ASCII, espace, `-`, `_` ; toute autre écriture refusée avec un message traduit. » · D26 · 40.

**A-16** — l.119 · « Meilleure manche » devient « meilleure réponse de la partie » (plus « film le plus rapidement trouvé » et « film que personne n'a eu »). · D25 · 80.

**A-17** — l.123 · Solo : l'un des quatre presets du site ; N ramené d'office au N jouable le plus proche, annoncé à l'écran ; deux gestes d'entraînement « Voir la réponse » (révélation normale à 0 point) et « Passer la manche » (sans révélation, titre au récapitulatif), jamais de `guess`. · D18, D19 · 60.

**A-18** — l.129 et l.263 (lexique) · « (cookie/localStorage) » devient « (cookie `player_token` HttpOnly chiffré, jamais `localStorage`) » ; lexique : « cookie HttpOnly chiffré : identifiant opaque (seul son SHA-256 est en base), langue et avatar prédéfini d'invité ; jamais le pseudo ». · D2, n° 36 · 40.

**A-19** — l.137 · Départage : ajouter « jamais la graine ». · n° 66 · 80.

**A-20** — l.169 · « Un canal de présence par salon (clé HMAC dérivée de l'identifiant interne) et un canal privé par siège (`public_id`) ». · C7 · 60.

**A-21** — l.172 et principe 4 l.381 · La poignée de main d'horloge sert à l'affichage seulement ; `tier_grace_ms` est une constante serveur appliquée d'un seul côté, à la réception. · n° 50 · 60, 70.

**A-22** — l.204 · « Pack d'animaux Kenney CC0, clés `preset-01`..`preset-24`, licence tracée dans `public/avatars/LICENSE.md` ». · D27 · 40.

**A-23** — l.214 et l.222 · « Prévisualisation en conditions de jeu » = `GameFrame` dans `GameThemeScope` ; le pied de page est replié en jeu (ligne compacte et feuille, liens en nouvel onglet). · D8, Q90-4 · 90.

**A-24** — l.215, l.326 et principe 8 l.385 · Chaîne de traitement réelle (voie TMDB : le serveur télécharge l'original ; voie capture, hors J1 : le navigateur envoie la source normalisée et le rectangle, sous réserve de R-46 ; master 1920 de large ; dérivé 1280×720 produit par le job) ; « Entrée publie » devient la passe de revue (« Entrée = conforme, publier ») ; l'opérabilité clavier n'est jamais coupée, seuls les raccourcis de débit sont coupables. · n° 0, n° 2 · 20 (G20 E14).

**A-25** — lexique (l.281 et ajouts) · « Vivier » : vivier catalogue (thèmes, N) et vivier du salon (moins la non-répétition), compté en œuvres ; ajouter `work` (œuvre), `catalogue pool`, `room pool`, `room memory` ; « gel du podium » → `finalize` (`FinalizeGame`, `GameFinalized`) ; « fait marquant » → `highlight`. · n° 24, D25 · 30, 80.

**A-26** — l.306 · « Couvrant 1, 3 et 5 **à sa publication** » (garde de transition, pas un invariant de jeu). · n° 4 · 30.

**A-27** — l.319 · Echo configuré à l'exécution (prop partagée `realtime` et `window.location`) ; variables `VITE_REVERB_*` retirées. · n° 79 · 60.

**A-28** — l.325 · Disque `frames` en `serve => false`, route dédiée `/f/{serveToken}` (paramètre de route ; `serve_token` reste la colonne), noms en `bin2hex(random_bytes(16))`, `no-store` et `X-Robots-Tag` émis par la route. · n° 1 · 60, 20.

**A-29** — l.328 · « Écritures de transition toujours exécutées, seule la diffusion se périme ; un job réveillé tôt attend en processus (< 1 s), jamais de relâchement. » · n° 56 · 60.

**A-30** — l.329 · `tabs` et `table` sont installés, `form` est exclu ; composants à installer par jalon (J1 : `slider`, `switch`, `progress`, `scroll-area`, `radio-group` ; J2 : `popover`, `command`) ; Vitest configuré au J1. · n° 42 · 90, 100.

**A-31** — l.345 · « Déploiement refusé tant qu'une partie est en cours » devient « déploiement manuel déclenché par le porteur dans une fenêtre libre obtenue par drainage borné (`deploy:drain`), prédicat « partie en cours » de 60, solo compris ; déploiement non atomique assumé ». · D31, D32 · 60, 100.

**A-32** — l.363 · Liste des specs du J1 : ajouter 30 (vivier, tirage, variantes, repli, non-répétition, `movie_group`, substitution, interface des leurres ; évaluateur de thèmes, difficulté et sélecteur au J2), 40 [J1] (identité invitée), 90 J1 (pages publiques **et** socle de coquille de jeu) et **100 [J1]** (socle minimal de production : Redis dédié, workers `game`/`default`, Reverb derrière nginx, déploiement tiré manuel, sauvegarde chaude hors VPS, sondes, `noindex` intégral, CI à zéro secret) ; estimation « ≈ 6 parties de 10 manches » au lieu de « 3 à 4 ». · n° 22, D1, D2, D3, D30, S1, n° 25 · 30, 40, 90, 100.

**A-33** — l.370 · Le « seuil déclaré » est `PlatformLimits::themeSelectorMinPool()` (150), mesuré sur le vivier catalogue sans thème au N par défaut, réapparition automatique. · Q30-3 · 30.

**A-34** — l.435 · Retirer « tableau de rétention » de la ligne 40 (le tableau appartient à 10). · n° 11 · 40.

**A-35** — l.439 · « Partie abandonnée » devient « partie interrompue » ; ajouter « gel idempotent, faits du podium ». · n° 65, D25 · 80.

**A-36** — principes 3, 5, 12 (engagement 3) et 13 · Principe 5 : les 40 % de hauteur se mesurent clavier ouvert ; principes 3 et 12 (engagement 3) : plancher de recadrage vérifiable par requête ; principe 13 : le back-office suit l'apparence choisie, seuls les cadres de revue et d'aperçu passent sous les tokens sombres du jeu, l'outillage couvre tout répertoire joueur créé avec méta-vérification, exception fermée des marques tierces dans `public/brand/` ; principe 8 : la fin de manche est annoncée à l'événement serveur. · D5, D6, D8, n° 72 · 20 (G20 E14), 90.

**A-36bis** — l.471 · Le montage (a) (domaine définitif acheté avant la semaine 4, socle minimal de production dès le J1) est **retenu le 23/09** ; (b) et (c) sont retirés. La règle « aucune passkey ni compte de production avant le domaine définitif » reste vraie. · D1 · 100.

**A-36ter** — l.473 · « à relever avant l'écriture de `60`, pas avant `100` » devient « à relever avant tout déploiement ; `60` et `100` [J1] s'écrivent sous hypothèse root, points dépendants marqués « à confirmer au relevé du VPS » ». · S2 · 60, 100.

### `docs/specs/05-i18n-et-langues.md`

**A-37** — l.100 · Les champs postés sont les clés camelCase de `RoomSettings::FIELDS` plus `roundDuration` (et `themeKeys`) ; un libellé d'attribut pour chacun (`roundsCount`, `framesPerRound`…), aucun libellé snake_case sans consommateur ; nommer `validation.nickname.*` et `validation.attributes.avatar`. · n° 41, D26 · 50, 40, 90.

**A-38** — l.36, l.55 et l.307 · Niveau 3 : « cookie `locale` absent ou expiré alors que le jeton est présent » ; « le jeton porte l'identifiant opaque et l'avatar prédéfini ; le siège se retrouve par le hash » ; « forme du `player_token` » renvoie à 40 [J1]. · n° 36, D2 · 40.

**A-39** — l.94 · `common` : « navigation, boutons, états génériques, erreurs HTTP, bandeaux, apparence, avatars (textes alternatifs et libellés des prédéfinis) » ; `account` : « Fortify, profil, comptes liés, écrans de choix d'avatar du compte » ; `admin` porte `admin.exclusion_grid.*` et `admin.enum.admin_action.*` ; liste close réaffirmée : ni `avatar`, ni `curation`. · n° 34, n° 6 · 40, 90, 20 (G20 E13).

**A-40** — l.111 · Une page de jeu embarque `common`, `game`, `room` et `legal` ; toute page joueur embarque `legal` ; le back-office reçoit `admin` seul, avec son pied de page sur `admin.footer.*`. · n° 68, D3 · 90.

**A-41** — l.123 · Le `lang` du QCM est unique pour l'ensemble des quatre chaînes (locale effective atteinte). · n° 55 · 70.

**A-42** — l.141 et l.253 · Pages légales : `<html lang>` suit la locale du visiteur, le corps FR est rendu dans `<div lang="fr">`, l'habillage passe par `legal.*` ; `Vary: Accept-Language` étendu aux pages légales et à « signaler un contenu », « cachables » retiré ; « signaler un contenu » en partiel Blade FR, `noindex` permanente. · n° 69, Q90-8 · 90.

**A-43** — l.171 · Retirer l'alternative « dérivés d'une graine … enregistrée au lancement » ; ne garder que « persistés à la première composition ». · n° 57 · 70.

**A-44** — l.179 · La charge ciblée du QCM : les quatre chaînes, le seul drapeau `choices_use_original_title` et un attribut `lang` égal à la locale effective atteinte (NULL si `title_original`). · n° 55 · 70, 60, 90.

**A-45** — l.180 · Le paquet de révélation inclut les URL des paliers ouverts, valides jusqu'à la fin de la révélation ; chaque titre du paquet porte la locale atteinte pour son attribut `lang`. · D14, R-24 · 60.

**A-46** — l.189 · Exemples : « seigneur des anneaux 2 » accepté « si l'alias existe » ; « The Two Towers » et « Les Deux Tours » vrais par la règle du sous-titre. · D23, Q70-7, n° 60 · 70, 90.

**A-47** — l.199 et l.294 · Le calibrage multilingue se fait par `answers:collisions`, rejoué à l'ajout d'une langue. · Q70-1 · 70.

**A-48** — l.237 · Le back-office formate côté client (`resources/js/lib/admin-format.ts`, `Intl`, locale `fr`) ; `App\Support\Format` réservé aux e-mails et exports (J2). · n° 10 · 90.

**A-49** — l.309 et l.313 · Le « corpus de leurres disponible » devient la règle de D21, qui renvoie à 70 et à `PoolScope::forDecoys`. · D21 · 30.

**A-50** — nouvelle section · « Propriété des préfixes de clés » : tableau de C15 § 2.4. · C15 · 90.

### `docs/specs/questions-ouvertes.md`

**A-51** — l.78 · 30 entre au J1 (périmètre de A-32) ; 40 [J1] (identité invitée) aussi ; la section J1 de 90 couvre pages publiques et socle de coquille de jeu ; **100 [J1]** (socle minimal de production : Redis dédié, workers `game`/`default`, Reverb derrière nginx, déploiement tiré manuel, sauvegarde chaude hors VPS, sondes, `noindex` intégral, CI à zéro secret) est comprise dans le J1. · n° 22, D2, D3, D30, S1 · 30, 40, 90, 100.

**A-52** — l.121, l.293, l.315, l.382 · `serve => false`, route dédiée, noms en `bin2hex`, `no-store` émis par la route ; § 8 l.121 : « dérivé seul » et « ULID » corrigés (le serveur dérive le jeu du master ; pour la seule voie capture, sous réserve de R-46). · n° 1, n° 0, R-46 · 60, 20.

**A-53** — l.290 · Echo configuré à l'exécution ; `VITE_REVERB_*` retirées. · n° 79 · 60.

**A-54** — l.292 · « Écritures toujours exécutées, seule la diffusion se périme ; attente en processus < 1 s. » · n° 56 · 60.

**A-55** — l.294 · « Test de charge avant mise en production » devient « avant la première partie du J1 » (complet : 20 salons, ≈ 150 joueurs, deux critères). · D33 · décisions du 23/09 (60 et 100 en sont les consommateurs).

**A-55bis** — l.268 · « **À relever cette semaine, avant l'écriture de `60` et avant tout achat de brique d'infrastructure.** » devient « **À relever avant tout déploiement et avant tout achat de brique d'infrastructure.** `60` et `100` [J1] s'écrivent sous hypothèse root (S2). » · S2 · décisions du 23/09 (60 et 100).

**A-56** — l.296 · « Refusé tant qu'une partie est en cours » devient la procédure de drainage borné (`deploy:drain`, `deploy:guard`, `deploy:release`, hook), sous la plume de 100. · D31, D32 · 50, 60, 100.

**A-57** — l.299 · Jobs CI : `ci`, `artifacts` (branche `deploy`), `mysql-redis` ; suite `Concurrency`. · C18 · 100.

**A-58** — l.303 · Vrai désormais par `Str::transliterate` : une saisie dans l'écriture d'origine est appariée de façon déterministe. · n° 61 · 70.

**A-59** — l.308 · Clés de la grille sous `admin.exclusion_grid.v{n}.{slug}.label` et `.help` (suffixe `.label` : écart de forme à n° 6, R-47) ; drapeau `retroactive` par version. · D13, n° 6, R-47 · 20.

**A-60** — l.320 · `B_max = min(50 %, 100 % / (N − 1))`, en pourcentage entier. · D22 · 50, 80.

**A-61** — l.321 · « Essai unique par siège » ; en Normal, le QCM reste ouvert après épuisement du texte. · n° 51, D20 · 70.

**A-62** — l.328 et l.330 · Le back-office suit l'apparence (D8) ; périmètre `WATCHED` et méta-vérification. · D8, n° 72 · 90.

**A-63** — l.331 · « Marge de 3 films » devient « marge `PlatformLimits::drawSubstituteMargin()` (3), comptée en œuvres » ; le texte d'aide du préfixe s'étend au sous-titre. · n° 21, D23 · 30, 70.

**A-64** — l.340-356 (tableau d'ordre et raisons) · 40 [J1] passe avant 50 ; 90 J1 (pages publiques et socle de coquille de jeu) passe avant 60 ; 100 [J1] est écrite maintenant, sous hypothèse S2, et perd la mention « en dernier » ; ligne 12 (l.354) : « section [J1] écrite sous hypothèse root ; relevé du VPS avant tout déploiement » ; les sections J2 de 40, 90 et 100 restent « à écrire (jalon 2) » ; la raison « ne conditionne pas » est retirée. · D2, D3, D30, S1, S2 · 40, 90, 100.

**A-65** — l.380 · Retirer la mention « support, édition ». · n° 12 · 20.

**A-66** — l.384 · « ≈ 6 parties de 10 manches » au lieu de « 3 à 4 ». · n° 25 · 30.

### `CLAUDE.md`

**A-67** — § 1 · Les comptes du J1 se réduisent au premier admin (le porteur, seul curateur au J1) et aux comptes de test jetables ; retirer « les comptes de curation ». · D4 · 20.

**A-68** — § 2 (barème) · « `B_max` = 50 % … vit dans le value object de limites » devient « B_max(N) = min(50 %, 100 %/(N − 1)), en pourcentage entier, constante d'instance de `PlatformLimits`, jamais surchargeable ». · D22, n° 64 · 50, 80.

**A-69** — § 2 (catalogue, vivier, tirage) · Repli de niveau : « combinaison de N niveaux disponibles d'écart total minimal à la répartition nominale, départage vers le plus cryptique, `FrameLevelCoverage::select` » ; « le vivier est fonction du couple (thèmes, N) » devient « vivier catalogue (thèmes, N) ; vivier du salon = moins la non-répétition ; compté en œuvres » ; tirage : citer `DrawContext` et « une ligne `seen_frame` hors fenêtre compte comme non vue ». · n° 21, n° 24 · 30.

**A-70** — § 2 (partie et validation) · « La manche s'arrête… dès que tous les joueurs connectés sont verrouillés » devient « … dès que tous les participants ont leur saisie close » ; « à 2 joueurs, prévoir l'affichage « mode solo » » renvoie à 00 l.27 (`lone_player`, cosmétique) ; difficulté Normal : « texte épuisé, QCM attendu » (`text_exhausted`) ; validation : sous-titre soumis à collision, suite de chiffres jamais tolérée ; « Réglages figés du lancement au podium » devient « … du lancement au « Rejouer » de l'hôte ». · n° 50, n° 65, D20, D23, D32 · 60, 70, 80, 50.

**A-71** — § 3 · Dépendance **directe** `symfony/polyfill-intl-normalizer` (pur PHP, aucune extension) ; Echo configuré à l'exécution, `VITE_REVERB_*` retirées ; liste des composants shadcn (tabs et table installés, form exclu, installation par jalon) ; production : Plesk Git en mode manuel, non atomique assumé. · D26, n° 79, n° 42, D31 · 40, 60, 90, 100.

**A-72** — § 4 · `composer test` exclut les groupes `mysql` et `locks-timing` ; ajouter `npm run test`, `composer test:mysql`, `deploy:drain`, `deploy:guard`, `deploy:release`, `game:reschedule`, `answers:collisions`, `backup:snapshot` et `catalog:reproject` (C18-bis § 2, nouvelles). · C18, C18-bis · 100, 60, 70.

**A-73** — § 5 · Réglages : `RoomSettingsEditor`, `WriteRoomSettings` seul écrivain, `config/game.php › platform` lu par `PlatformLimits` seul ; nouveaux dossiers `app/Support/Identity/`, `app/Rules/`, `resources/moderation/` ; `admin_action` : « auteur (**nom réel**), action, sujet, motif, horodatage » ; switch de `app.tsx` et coquilles (`game/*` → `GameLayout` forcé sombre ; `welcome`, `legal/*`, `error` et le cas par défaut → `PublicLayout` ; `dashboard` et `settings/*` → `AppLayout` ; back-office non forcé). · D12, D2, D8 · 50, 40, 20, 90.

**A-74** — § 6 (lexique) · Ajouter : expulsé → `kicked` (`player.kicked_at`, `game_player.status = kicked`) ; liste noire de pseudos → `NicknameBlocklist` ; texte épuisé, QCM attendu → `text_exhausted` ; œuvre → `work`, vivier catalogue / du salon → `catalogue pool` / `room pool`, mémoire du salon → `room memory` ; gel du podium → `finalize`, fait marquant → `highlight` ; domaine de traduction : liste close de sept, `legal` sur toute route joueur. · D15, D20, n° 24, D25 · 40, 70, 30, 80, 90.

**A-75** — § 7 · Règle 3 : le QCM est poussé « y compris au siège dont le texte libre est épuisé » ; règle 5 : périmètre `WATCHED` ; règle 7 : « tous les participants dont la saisie est close » ; règle 8 : « aucun minuteur client ne décide » ; règle 9 : « couvrant 1, 3 et 5 à sa publication ». · D20, n° 72, n° 50, n° 74, n° 4 · 60, 90, 70, 30.

**A-76** — § 8 · `serve_token` frappé à l'ouverture du palier précédent (ou à la programmation pour le palier 1), servable jusqu'à la fin de la révélation pour les paliers ouverts ; images de jeu et uploads : voie TMDB par le serveur, voie capture (hors J1) sans dérivé envoyé par le navigateur sous réserve de R-46, 16:9, master de 1920 de large ; « ajouter `ext-imagick` impose `extensions:` au workflow » reste vrai pour tout nouveau workflow (`tests-mysql.yml`), `ext-imagick` et `extensions: imagick` du job `ci` étant déjà en place ; `/f/{serve_token}` s'écrit `/f/{serveToken}` (paramètre de route ; `serve_token` reste la colonne) ; `CURATION_CAPTURE_ENABLED=` dans `.env.example` ; Vitest configuré, suite `Concurrency`. · n° 47, D14, D5, R-40, R-46 · 60, 20 (G20 E14), 100.

**A-77** — § 9 · La dette « normalisation des réponses écrite nulle part » est levée à l'écriture de 70 ; tableau des domaines de test (C18 § 2.6). · — · 70, 100.

**A-78** — `.gitignore` et `vite.config.ts` (D9) · Dans le même commit que le retrait de `/CLAUDE.md` du `.gitignore`, ajouter `'CLAUDE.md'` à `fmt.ignorePatterns` de `vite.config.ts`. · D9 · 100.

### `docs/REPRISE.md`

**A-79** — l.134 · Ordre d'écriture : 40 [J1] avant 50 ; 90 J1 avant 60 ; 100 [J1] écrite maintenant sous hypothèse S2 ; les sections J2 de 40, 90 et 100 viennent après le J1. · D2, D3, D30, S1 · 40, 90, 100.

**A-80** — dette n° 24 · Tranchée par la règle de coquille mobile de C16 § 2.9 (`SheetContent` propre, `SheetTitle` et `SheetDescription` traduits). · boundary map n° 36 · 90.

**A-81** — l.71 · « **Relever le VPS** — **cette semaine, avant l'écriture de `60`**, pas avant `100` » devient « **Relever le VPS** — avant tout déploiement ; `60` et `100` [J1] s'écrivent sous hypothèse root (S2) ». · S2 · 60, 100.

---

## Glossaire des noms nouveaux

Une ligne par nom introduit par cette feuille, avec le contrat qui le définit. Les noms existants modifiés figurent dans « Ajouts aux classes existantes ».

### Classes PHP

| Nom | Contrat | Définition |
|---|---|---|
| `App\Actions\Curation\AddFrame` | C9 | Crée une frame `draft`/`pending` depuis un visuel TMDB ou une capture et distribue son traitement. |
| `App\Actions\Curation\RecropFrame` | C9 | Re-recadre une frame en place, en la sortant de `published` si besoin. |
| `App\Actions\Curation\RetryFrameProcessing` | C9 | Relance le traitement d'une frame dont l'échec est rejouable. |
| `App\Actions\Game\CancelRound` | C7 | Annule une manche avec un `RoundIncidentReason` et appelle le remplacement (interne à 60). |
| `App\Actions\Game\CatchUpGame` | C7 | Rattrapage synchrone des transitions échues d'une partie, dans l'ordre chronologique. |
| `App\Actions\Game\ClaimSeatTab` | C7 | Frappe un nouveau jeton d'onglet actif et supplante l'onglet précédent. |
| `App\Actions\Game\CloseRound` | C7 | Écrit `ended_at` à `D` ou à la fin anticipée et émet `round.closed` (interne). |
| `App\Actions\Game\ComposeChoiceSets` | C11 | Tire les leurres et compose les quatre propositions du QCM, une fois par manche. |
| `App\Actions\Game\EndReveal` | C7 | Termine la révélation ; pause (déprogramme la manche suivante) ou gel final ; précède `OpenTier(k+1, 1)` à instant égal (interne, C7 § 4.14). |
| `App\Actions\Game\FinalizeGame` | C13 | Gel idempotent d'une partie, seul écrivain de `ended_at` et des agrégats. |
| `App\Actions\Game\LockGuess` | C10 | Transaction de verrouillage d'une bonne réponse, seule écrivaine de `guess`. |
| `App\Actions\Game\MaterializeDraw` | C6, C7 | Écrit `round` et `round_tier` depuis un `DrawResult` dans la transaction de lancement. |
| `App\Actions\Game\MintTierServeToken` | C8 | Frappe le `serve_token` d'un palier et décide la substitution, une seule fois. |
| `App\Actions\Game\OpenGame` | C6 | Seul créateur de `game` (multijoueur et solo) : garde, tirage, matérialisation, programmation. |
| `App\Actions\Game\OpenTier` | C8 | Ouvre un palier à `Tᵢ` : `served_at` théorique, `seen_frame`, frappe du palier suivant, QCM. |
| `App\Actions\Game\PauseGame` / `ResumeGame` | C7 | Met en pause entre deux manches et reprend au retour d'un siège (internes). |
| `App\Actions\Game\RevealRound` | C7 | Passe la manche en `revealing`, émet les titres à `ended_at + tier_grace_ms` et programme la manche suivante à `reveal_ends_at` (interne, C7 § 4.14). |
| `App\Actions\Game\AdvanceToNextRound` | C7 | Raccourcit la révélation sur geste de l'hôte ou en solo (interne). |
| `App\Actions\Game\RevealSoloAnswer` / `SkipSoloRound` | C7 | Gestes solo « Voir la réponse » et « Passer la manche » (D18, internes). |
| `App\Actions\Game\ScheduleRound` | C7 | Programme une manche (`started_at`) et frappe le jeton de son palier 1. |
| `App\Actions\Game\SeatInputClosed` | C7 | Crochet de fin de saisie : émet `player.locked` et réévalue la fin anticipée. |
| `App\Actions\Game\StartSoloGame` | C7 | Démarre ou reprend la partie solo d'un siège via `OpenGame` (interne). |
| `App\Actions\Game\SubmitChoice` / `SubmitTextAnswer` | C10 | Traitent un clic de QCM ou une saisie libre et rendent un `SubmissionVerdict`. |
| `App\Actions\Room\ApplyRoomPreset` | C0 | Applique un preset du site aux seize champs d'un salon en lobby. |
| `App\Actions\Room\LaunchGame` | C6 | Transaction de lancement multijoueur sous verrou du salon. |
| `App\Actions\Room\ReplayRoom` | C6 | Geste d'hôte « Rejouer » : ramène le salon en lobby après le gel. |
| `App\Actions\Room\UpdateRoomSettings` | C0 | Écrit une modification de réglages postée par l'hôte. |
| `App\Actions\Room\WriteRoomSettings` | C0 | Seul écrivain de `room.settings`, de sa version et des cinq projections. |
| `App\Avatars\AvatarPresetCatalog` | C5 | Registre des 24 avatars prédéfinis : clés, URL, libellés, suggestion. |
| `App\Broadcasting\RoomPresenceChannel` | C7 | Autorisation du canal de présence `room.{roomKey}`. |
| `App\Broadcasting\SeatPrivateChannel` | C7 | Autorisation du canal privé `seat.{publicId}`. |
| `App\Concerns\PlayerIdentityValidationRules` | C5 | Règles partagées de pseudo et d'avatar des FormRequest. |
| `App\Concerns\RealNameValidationRules` | C14 | Règles du nom réel des comptes privilégiés. |
| `App\Console\Commands\AnswersCollisionsCommand` | C12 | `answers:collisions` : rapport des formes partagées et des paires sous le seuil. |
| `App\Console\Commands\DeployDrainCommand` | C18-bis | `deploy:drain` : pose le drapeau, attend la fin des parties, ouvre la fenêtre. |
| `App\Console\Commands\DeployGuardCommand` | C18-bis | `deploy:guard` : code 0 seulement en fenêtre libre sans partie en cours. |
| `App\Console\Commands\DeployReleaseCommand` | C18-bis | `deploy:release` : lève le drapeau quelle que soit sa phase. |
| `App\Console\Commands\BackupSnapshotCommand` | C18-bis | `backup:snapshot` : instantané bloquant avant migration (règle 12), `--if-pending`. |
| `App\Console\Commands\CatalogReprojectCommand` | C18-bis, C12 | `catalog:reproject` : reprojection idempotente de `movie_projection` et `answer_key`, par différence. |
| `App\Console\Commands\GameRescheduleCommand` | C17 | `game:reschedule` : rattrape et redonne un job à toute partie en cours. |
| `App\Enums\DrainPhase` | C18-bis | Phase du drapeau : `draining` ou `window`. |
| `App\Enums\FrameProcessingFailure` | C9 | Douze causes d'échec de traitement, valeur = clé de traduction. |
| `App\Enums\OriginalTitleForm` | C11 | Forme du titre original : `latin`, `transliterated`, `native`. |
| `App\Enums\PoolFault` | C2 | Réglage fautif d'un vivier bloqué : `noRepeatMovies`, `themeKeys`, `framesPerRound`, `roundsCount`. |
| `App\Enums\PoolRemedyKind` | C2 | Remède proposé : `open_new_room`, `disable_no_repeat`, `clear_themes`, `lower_frames_per_round`, `reduce_rounds_count`. |
| `App\Enums\RoomRefusal` | C6 | Huit motifs de refus de lancement ou de « Rejouer », avec leur clé de message. |
| `App\Enums\ScoreScope` | C13 | Portée de lecture du score : `Own`, `Publishable`, `Settled`. |
| `App\Enums\SubmissionOutcome` | C10 | Issue d'une soumission : `accepted`, `rejected`, `closed`. |
| `App\Events\Game\AnswerAccepted` | C10 | Événement de domaine après commit d'un verrouillage, écouté par 60. |
| `App\Events\Game\InputClosed` | C10 | Événement de domaine après fermeture de saisie (`qcm_wrong`, `attempts_exhausted`). |
| `App\Events\Game\GameFinalized` | C13 | Événement de domaine après le gel, déclencheur unique des effets de fin. |
| `App\Events\Game\RoomBroadcast` / `SeatBroadcast` | C7 | Bases abstraites des diffusions au salon et des envois ciblés au siège. |
| `App\Events\Game\{SeatJoined, SeatUpdated, HostChanged, SettingsChanged, RoomReplayed, GameLaunched, RoomArchived, RoundScheduled, TierOpened, PlayerLocked, RoundClosed, RoundRevealed, RoundCancelled, GamePaused, GameResumed, GameEnded, SeatChoicesOffered, SeatSuperseded, SeatKicked}` | C7 | Les dix-neuf événements Reverb du J1 (voir `broadcastAs` ci-dessous). |
| `App\Http\Controllers\Admin\{FrameTmdbController, FrameCaptureController, FrameCropController, FrameRetryController}` | C9 | Ajout TMDB, ajout par capture, re-recadrage, relance. |
| `App\Http\Controllers\Admin\FrameImageController` | C9-bis | Aperçu admin des octets `game` et `master`. |
| `App\Http\Controllers\Admin\FrameReviewController` | C14-bis | Revue d'une frame selon la grille courante, qui publie si elle passe. |
| `App\Http\Controllers\Admin\ExclusionGridRetroactiveController` | C14-bis | Geste admin rétroactif d'une version de grille (J2). |
| `App\Http\Controllers\Game\{AnswerController, ChoiceController}` | C10 | Réception d'une saisie libre et d'un clic de QCM. |
| `App\Http\Controllers\Game\{RoomStateController, RoomHeartbeatController, NextRoundController, SoloGameController, SoloStateController, SoloHeartbeatController, SoloRoundController, ClockController}` | C7 | Resynchronisation, battements, geste d'hôte, solo et horloge. |
| `App\Http\Controllers\Game\FrameServeController` | C8 | Service des octets d'image par `serve_token`. |
| `App\Http\Controllers\Room\{LaunchController, ReplayController}` | C6 | Contrôleurs proposés de `room.launch` et `room.replay`. |
| `App\Http\Middleware\EnsureActiveSeat` | C7 | Alias `seat.active` : siège tenu par le jeton, non expulsé, onglet actif. |
| `App\Http\Middleware\ForceGameAppearance` | C16 | Alias `game.appearance` : force le thème sombre sur les pages `game/*`. |
| `App\Http\Requests\Admin\{FrameTmdbStoreRequest, FrameCaptureStoreRequest, FrameCropUpdateRequest}` | C9 | Validation des ajouts et du re-recadrage. |
| `App\Http\Requests\Admin\{FrameReviewStoreRequest, ExclusionGridRetroactiveRequest}` | C14-bis | Validation de la revue et du geste rétroactif. |
| `App\Http\Requests\Game\{AnswerStoreRequest, ChoiceStoreRequest}` | C10 | Validation de forme d'une saisie et d'un clic. |
| `App\Http\Requests\Game\SoloStartRequest` | C7 | Choix du preset au démarrage du solo. |
| `App\Jobs\Answers\AggregateNearMisses` | C12 | Agrégation k-anonyme des quasi-justes à la clôture de manche (J2). |
| `App\Jobs\Curation\ProcessFrameImage` | C9 | Traitement Imagick d'une frame, file `default`, unique par frame. |
| `App\Jobs\Game\{AdvanceRound, InterruptPausedGame, SweepSeatPresence}` | C7 | Jobs de frontière, de clôture de pause et de présence, file `game`. |
| `App\Jobs\Game\BroadcastLobbyState` | C7, C0 | Diffusion anti-rebondie de `settings.changed`, file `game`, unique par salon jusqu'au traitement. |
| `App\Jobs\Game\WithdrawContentFromLiveRounds` | C8 | Annulation active des manches d'un contenu suspendu ou retiré (J2). |
| `App\Policies\FramePolicy` | C9 | Voir, créer, recadrer une frame ; geste rétroactif. |
| `App\Policies\FrameReviewPolicy` | C14-bis | Créer une revue ; jamais la modifier ni la supprimer. |
| `App\Policies\RoomPolicy` | C6 | Gestes d'hôte : lancer, rejouer, modifier les réglages, passer à la manche suivante. |
| `App\Rules\ValidNickname` | C5 | Règle de pseudo : longueur, écriture latine, caractères, forme normalisée, liste noire. |
| `App\Settings\EngineConstants` | C7 | Constantes du moteur lues dans `config('game.engine.*')`. |
| `App\Settings\RoomSettingsEditor` | C0 | Fonction pure : charge postée par onglet → entrée complète de `fromInput()`. |
| `App\Support\Admin\AdminJournal` | C14 | Seul écrivain de `admin_action`, dans la transaction du geste. |
| `App\Support\Answers\AnswerMatcher` | C10 | Deux lectures inconditionnelles puis décision pure du verdict texte. |
| `App\Support\Answers\AnswerRules` | C12 | Version, barème de tolérance, marge de quasi-juste et empreinte de la validation. |
| `App\Support\Answers\ChoicesPresenter` | C11 | Rend les quatre propositions permutées pour un siège. |
| `App\Support\Answers\DecoyPicker` | C11 | Tirage des trois leurres selon l'échelle R1-R4, à l'instant théorique de composition. |
| `App\Support\Deploy\DeployDrain` | C18-bis | Seul drapeau de drainage (cache, TTL, phases). |
| `App\Support\Deploy\DrainAlreadyRunning` | C18-bis | Exception : un drainage est déjà en cours. |
| `App\Support\Draw\DrawContext` | C3 | Registre fermé des contextes de dérivation de la graine. |
| `App\Support\Draw\{DrawInput, DrawResult, DrawnRound, DrawnTier}` | C3 | Entrées figées et résultat du tirage, jamais sérialisés. |
| `App\Support\Draw\GameDrawer` | C3 | Tirage des films et des variantes sur un `PoolScope`. |
| `App\Support\Draw\{PoolCandidate, PoolScope}` | C2 | Candidat du vivier et entrées figées du prédicat. |
| `App\Support\Draw\PoolQuery` | C2 | Constructeur unique de la requête du vivier et des thèmes publiés. |
| `App\Support\Draw\{PoolReport, PoolRemedy, PoolReporter}` | C2 | Rapport de vivier en données et son calcul. |
| `App\Support\Draw\PoolTooSmallException` | C2 | Garde défensive : vivier sous M au tirage. |
| `App\Support\Draw\ReplacementRoundChooser` | C3 | Désigne la manche de réserve qui remplace une manche annulée. |
| `App\Support\Draw\RoomMemoryWindow` | C2 | Borne basse de la mémoire du salon (jours ou N-ième manche). |
| `App\Support\Draw\SeededPrf` | C3 | PRF HMAC-SHA256 à rejet, seule source d'aléa dérivé de la graine. |
| `App\Support\Draw\{VariantCandidate, VariantChooser}` | C3 | Variante candidate et choix au tirage comme en substitution. |
| `App\Support\Frames\{CropRect, CropViolation}` | C9 | Rectangle de recadrage et cause de refus. |
| `App\Support\Frames\FrameGeometry` | C9 | Constantes de format 16:9, 1280×720, master 1920, plafonds et padding. |
| `App\Support\Frames\{FrameImageProcessor, FrameProcessingException}` | C9 | Chaîne Imagick et son exception typée. |
| `App\Support\Frames\FrameImageResponse` | C9 | Constructeur unique des réponses d'image (`/f/` et aperçu). |
| `App\Support\Frames\WebpPadding` | C9 | Padding NUL après le bloc RIFF jusqu'au multiple de 8 192. |
| `App\Support\Game\GamesInProgress` | C17 | Prédicat « partie en cours », résumé console et durée maximale. |
| `App\Support\Game\GameStateBuilder` | C7 | Constructeur unique du paquet de resynchronisation. |
| `App\Support\Game\ReceptionInstant` | C7, C10 | Instant serveur de réception capturé à l'entrée de la requête. |
| `App\Support\Game\RoundClock` | C7 | Décalage en ms dans une manche et palier courant. |
| `App\Support\Game\RoundStep` | C7 | Enum hors schéma des étapes d'une manche (interne). |
| `App\Support\Game\{ServeGuard, ServeUrl}` | C8 | Prédicat en trois parties et URL signée d'un palier. |
| `App\Support\I18n\CookiePlayerTokenLocale` | C4 | Niveau 3 de la résolution de langue depuis le `player_token`. |
| `App\Support\I18n\DisplayTitleResolver` | C11 | Seule chaîne de repli d'affichage d'un titre (05). |
| `App\Support\Identity\{NicknameNormalizer, NicknameBlocklist}` | C5 | Forme canonique et normalisée du pseudo ; liste noire. |
| `App\Support\Identity\PlayerIdentity` | C5 | Seule sérialisation de l'identité affichée d'un siège. |
| `App\Support\Identity\{PlayerToken, PlayerTokenCookie, PlayerTokenManager}` | C4 | Jeton d'invité, son cookie et sa gestion par requête. |
| `App\Support\Realtime\{ChannelNames, GameRef, GameWire, SeatPrincipal, WireTime}` | C7 | Noms de canaux, référence publique de partie, enveloppe, principal du garde `player`, format d'instant. |
| `App\Support\Room\RoomSettingsPresenter` | C0 | Vue client des réglages et état diffusé au salon. |
| `App\Support\Scoring\{ScoringRules, ScoreCalculator, Ranking, Scoreboard, ScoreReplayer, UnsupportedScoringVersion}` | C13 | Règle et version du score, calcul pur, départage, lectures, rejeu, exception. |
| `App\ValueObjects\Answers\{ChoicesPayload, DecoyPick, MatchResult, SeatInputView, SubmissionVerdict}` | C10, C11 | Charges et résultats de la saisie et du QCM. |
| `App\ValueObjects\Catalog\FrameLevelCoverage` | C1 | Répartition nominale N → niveaux et repli déterministe. |
| `App\ValueObjects\Deploy\DrainState` | C18-bis | État sérialisé du drapeau de drainage. |
| `App\ValueObjects\I18n\ResolvedTitle` | C11 | Titre résolu avec sa locale et son rang de repli. |
| `App\ValueObjects\Room\LaunchOutcome` | C6 | Issue d'un lancement : partie créée ou refus avec rapport. |
| `App\ValueObjects\Scoring\{TierWindow, TierSchedule, TierScore, PlayerTally, Standing}` | C13 | Palier, chronologie, score d'une réponse, totaux et rang d'un siège. |

### Ajouts aux classes existantes

| Nom | Contrat | Définition |
|---|---|---|
| `PlatformLimits::{speedBonusMaxPercent, drawSubstituteMargin, roomMemoryWindowDays, roomMemoryWindowRounds, themeSelectorMinPool, lobbyBroadcastDebounceMs, frameCropMaxWidthPercent, frameCropMinWidthPx}`, `SPEED_BONUS_MAX_PERCENT_CAP`, `FULL_PERCENT` | C0 | Constantes d'instance nouvelles (B_max par N, tirage, mémoire, lobby, recadrage). |
| `RoomSettings::CHANGE_{EQUALIZED, RESET, RAISED, OVERWRITTEN}` | C0 | Codes de changement ajoutés au rapport. |
| `RoomSettingsBounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH`, `toClient()` | C0 | Seuil de 2 connectés ; bornes par N pour le client. |
| `RoundPlayerInputState::TextExhausted`, `acceptsText()`, `acceptsChoice()`, `notClosedValues()` | C10 | État « texte épuisé, QCM attendu » et prédicats de saisie. |
| `RoundIncidentReason::{ChoicesUnavailable, MovieSuspended}` | C11, C8 | Annulation faute de QCM (J1) ; film suspendu en cours de partie (J2). |
| `AnswerKeyKind::Subtitle`, `isCollisionChecked()`, `toMatchKind()` ; `GuessMatchKind::Subtitle` | C12 | Sous-titre dérivé, soumis à collision. |
| `AnswerKeyNormalizer::{fold, subtitleOf, compact, digits, distance, leadingArticles}` | C12 | Primitive de repli, sous-titre, forme compacte, chiffres, distance, articles. |
| `AdminActionType` + 6 cas, `requiresReason()`, `allowsConsoleActor()`, `labelKey()` ; `AdminActionSubject::Site` ; `AdminAction::CONSOLE_ACTOR` | C14 | Liste fermée à 21 cas, sujet `site`, acteur `console`. |
| `ExclusionGrid::{helpKey, isRetroactive, addedSlugs, retroactiveLevels, decisionFor}` | C14-bis | Grille versionnée à drapeau rétroactif. |
| `Game::FROZEN_COLUMNS`, scope `inProgress` | C6, C17 | Colonnes figées ; parties `running`/`paused` à `ended_at` NULL. |
| `Guess::inScoreScope`, `RoundPlayer::inScoreScope` | C13 | Filtres par portée de score. |
| `Player::heldByToken`, `Player::wasKicked()` | C4 | Siège du jeton ; siège expulsé. |
| `TmdbClient::downloadImage()` | C9 | Téléchargement plafonné des octets `original`. |
| `GuessFactory::scoredAt()`, `PlayerFactory::kicked()` | C13, C4 | États de fabrique. |
| `RoomSettingsCast` implémente `ComparesCastableAttributes` | C6 | Comparaison par valeur des quatre colonnes porteuses, pour que la garde des colonnes figées ne lève pas sous MySQL. |
| `FrameFactory::processingFailed(FrameProcessingFailure $failure)` | C9 | État d'échec aligné sur l'enum (la valeur actuelle est hors enum). |
| `RoomPolicy::advanceRound()` | C6, C7 | Geste d'hôte « manche suivante ». |

### Routes, canaux, événements, gardes, limiteurs

| Nom | Contrat | Définition |
|---|---|---|
| `room.settings.update` · `PATCH /r/{room}/settings` | C0 | Modification des réglages par l'hôte (onglet Simple au J1). |
| `room.settings.preset` · `POST /r/{room}/settings/preset` | C0 | Application d'un preset du site. |
| `room.launch` · `POST /r/{room}/launch` | C6 | Lancement d'une partie. |
| `room.replay` · `POST /r/{room}/replay` | C6 | « Rejouer » : retour au lobby. |
| `room.state` · `GET /r/{room}/state` | C7 | Paquet de resynchronisation d'un salon. |
| `room.heartbeat` · `POST /r/{room}/heartbeat` | C7 | Battement de présence. |
| `room.round.next` · `POST /r/{room}/round/next` | C7 | Geste d'hôte « manche suivante ». |
| `round.answer.store` · `POST /seat/{player:public_id}/answer` | C10 | Soumission d'une saisie libre. |
| `round.choice.store` · `POST /seat/{player:public_id}/choice` | C10 | Soumission d'un clic de QCM. |
| `solo.store`, `solo.show`, `solo.state`, `solo.heartbeat`, `solo.reveal`, `solo.skip`, `solo.next` | C7 | Démarrage, page, resynchronisation, battement et gestes du solo. |
| `clock.show` · `GET /clock` | C7 | Instant serveur pour la poignée de main. |
| `frame.serve` · `GET /f/{serveToken}` | C8 | Octets d'image d'un palier, signés et gardés. |
| `admin.catalog.frames.{tmdb.store, capture.store, crop.update, retry}` | C9 | Ajout, capture, re-recadrage, relance. |
| `admin.catalog.frames.{game, master}` | C9-bis | Aperçu admin des octets. |
| `admin.catalog.frames.review.store` | C14-bis | Revue d'une frame. |
| `admin.exclusion_grid.retroactive.store` | C14-bis | Geste rétroactif (J2). |
| `legal.notice`, `legal.terms`, `legal.privacy`, `takedown.create` ; `takedown.store` (J2) | C16 | Pages légales et « signaler un contenu ». |
| `room.{roomKey}` / `presence-room.{roomKey}` | C7 | Canal de présence d'un salon, clé HMAC. |
| `seat.{publicId}` / `private-seat.{publicId}` | C7 | Canal privé d'un siège. |
| `seat.joined`, `seat.updated`, `host.changed`, `settings.changed`, `room.replayed`, `game.launched`, `room.archived`, `round.scheduled`, `tier.opened`, `player.locked`, `round.closed`, `round.revealed`, `round.cancelled`, `game.paused`, `game.resumed`, `game.ended` | C7 | Diffusions au salon (charges au tableau C7 § 2.3). |
| `seat.choices`, `seat.superseded`, `seat.kicked` | C7 | Envois ciblés au siège. |
| Garde `player` (driver `player-token`) | C7 | Authentifie un invité par le hash de son jeton pour les canaux. |
| Alias `seat.active`, `game.appearance` | C7, C16 | Middleware de siège actif ; forçage sombre du jeu. |
| Limiteurs `answer`, `game-read`, `game-write`, `frame-serve`, `admin-frame` ; `takedown` (J2) | C10, C7, C8, C9, C16 | Débits nommés. |
| Commandes `answers:collisions`, `deploy:drain`, `deploy:guard`, `deploy:release`, `backup:snapshot`, `catalog:reproject`, `game:reschedule` ; option `admin:first-admin --real-name` | C12, C18-bis, C17, C14 | Commandes artisan nouvelles ou étendues. |

### Configuration, cookies, props, fichiers

| Nom | Contrat | Définition |
|---|---|---|
| `config/game.php › platform.*` (quinze clés) | C0 | Surcharges de `PlatformLimits`, lues par elle seule. |
| `config/game.php › engine.*` (`launch_countdown_ms`, `next_round_margin_ms`, `heartbeat_interval_ms`, `disconnect_after_ms`, `pause_timeout_ms`, `transition_max_wait_ms`, `clock_samples`, débits, `serve_url_expiry_margin_ms`, `frame_serve_per_minute`) | C7, C8 | Constantes du moteur lues par `EngineConstants`. |
| `config/deploy.php` (`drain_timeout_minutes`, `drain_margin_minutes`, `window_minutes`, `poll_seconds`, `cache_key`) | C18-bis | Réglages du drainage. |
| `config/catalog.php › curation.*` | C9 | Voie capture, plafonds, qualités WebP, limites Imagick. |
| `CURATION_CAPTURE_ENABLED`, `DEPLOY_DRAIN_TIMEOUT_MINUTES`, `DEPLOY_WINDOW_MINUTES`, `REVERB_CLIENT_{HOST,PORT,SCHEME}` | C9, C18-bis, C7 | Variables d'environnement nouvelles, vides ou par défaut dans `.env.example`. |
| Cookie `player_token` | C4 | Jeton d'invité chiffré, HttpOnly, 30 jours glissants. |
| Clé de cache `deploy:drain` | C18-bis | Drapeau de drainage. |
| Props partagées `realtime`, `frameFormat`, `maintenance` | C7, C16, C18-bis | Configuration Echo, format d'image fixe, drapeau de maintenance. |
| Props de page `state`, `seatToken`, `settingsNotice` ; flash `settingsChanges` | C7, C0 | Paquet initial, jeton d'onglet, annonce D19, rapport de changements. |
| `routes/game.php`, `routes/channels.php`, `routes/legal.php` | C0, C7, C16 | Fichiers de routes nouveaux. |
| `resources/moderation/nicknames/{en,fr,reserved}.txt` | C5 | Listes noires versionnées. |
| `public/avatars/preset-01..24.webp`, `public/avatars/LICENSE.md`, `public/brand/tmdb.svg`, `public/brand/LICENSE.md` | C5, C16 | Actifs statiques tracés. |
| Branche `deploy`, workflow `tests-mysql.yml` | C18 | Artefact construit en CI ; job MySQL + Redis. |
| `tests/Concurrency/`, `tests/Frontend/`, `tests/Datasets/RoomSettingsMatrix.php`, `tests/Support/Room/{RoomSettingsCase, RoomSettingsMatrix}.php`, `tests/Fixtures/answers/normalizer-v1.php` | C18, C12 | Suites et supports de test nouveaux. |
| Groupes Pest `mysql`, `locks-timing` | C18 | Liste close des groupes hors défaut. |

### Front (TypeScript)

| Nom | Contrat | Définition |
|---|---|---|
| `types/room-settings.ts` : `RoomSettingsView`, `RoomSettingsState`, `RoomSettingsFieldKey`, `RoomSettingsWarningCode`, `RoomSettingsChangeCode`, `Bound`, `RoomSettingsBoundsPayload`, `PlatformLimitsPayload`, `RoomRefusalCode`, `InputDifficulty` | C0, C6 | Réglages vus du client. |
| `types/pool.ts` : `PoolReport`, `PoolFault`, `PoolRemedyKind`, `PoolRemedy` | C2 | Rapport de vivier. |
| `types/player.ts` : `PlayerIdentity`, `AvatarData` | C5 | Identité affichée d'un siège. |
| `types/game-wire.ts` : `IsoMs`, `WireEnvelope`, `SeatView`, `RoundTimeline`, `TierImageRef`, `LocaleCode`, `RevealTitle`, `RevealMovie`, `GameStatePacket`, `RoundState`, `SelfState` | C7 | Transport temps réel et resynchronisation. |
| `types/answers.ts` : `InputState`, `SubmissionResult`, `SeatInputView`, `ChoicesPayload` | C10, C11 | Saisie et QCM. |
| `types/scoring.ts` : `TierWindow`, `TierScore`, `SeatScore`, `LeaderboardRow`, `Leaderboard`, `RoundFinder`, `RecapEntry`, `PodiumStanding`, `PodiumHighlights`, `Podium`, `TitlePacket` | C13 | Score, classement, podium. |
| `types/admin.ts` : `AdminFrameLimits` ; `AdminMovieFrame` étendu | C9 | Plafonds du recadreur ; props d'une frame en back-office. |
| `lib/room-settings.ts` | C0 | Dérivations client non autoritaires des réglages. |
| `lib/frame-geometry.ts` (`FRAME_GEOMETRY`, `maxCropWidth`, `defaultCrop`, `cropViolation`) | C9 | Miroir client de `FrameGeometry`. |
| `lib/game/{echo, wire, server-clock, store, frame-loader}.ts` | C7, C8 | Echo à l'exécution, version du fil, horloge, magasin, chargeur d'images. |
| `lib/game/round-timeline.ts` : `LiveRoundTimeline`, `toLiveTimeline`, `currentTier`, `tierValueAt`, `announcementThresholds` | C16, C13 | Chronologie cliente, valeur du palier (D29), seuils d'annonce. |
| `lib/game/announcer.ts` | C16 | Magasin de l'annonceur `aria-live`. |
| `hooks/game/{use-game-channel, use-game-state, use-round-clock, use-heartbeat}.ts` | C7 | Souscriptions et horloge en `useSyncExternalStore`. |
| `hooks/game/use-answer-submission.ts` | C10 | Soumission d'une saisie ou d'un clic. |
| `hooks/game/{use-visual-viewport, use-overscroll-lock, use-round-announcements}.ts` | C16 | Hauteur clavier ouvert, verrou de défilement, annonces. |
| `layouts/game/game-layout.tsx` (`GameLayout`, `GameLayoutProps`), `layouts/public/public-layout.tsx` (`PublicLayout`) | C16 | Coquilles du jeu et des pages publiques. |
| `components/game/{game-frame, game-theme-scope, game-announcer, connection-banner, answer-input, choice-grid}.tsx` (`GameFrameProps`, `FrameFormat`) | C16, C10, C11 | Conteneur d'image, portée sombre, annonceur, bandeau, saisie, QCM. |
| `components/game/{player-avatar, avatar-picker}.tsx` | C5, C16 | Avatar affiché et sélecteur (proposés par 40). |
| `components/public/{site-footer, tmdb-attribution, public-header, appearance-toggle, maintenance-banner}.tsx` | C16 | Pied de page, attribution, en-tête, apparence, bandeau. |
| `components/state/{loading-state, empty-state, error-state, read-only-notice}.tsx` | C16 | États génériques. |
| Jeton `--aspect-frame`, utilitaire `aspect-frame` ; jetons `--success`, `--success-foreground` | C9, C16 | Ratio 16:9 ; couleur « a trouvé ». |

### Familles de clés de traduction

| Préfixe | Contrat | Définition |
|---|---|---|
| `room.settings.*`, `room.warnings.*`, `room.pool.{counter, blocked, no_remedy, cause.*, remedy.*}`, `room.refusal.*`, `room.join.kicked` | C0, C2, C6, C4 | Réglages, avertissements, vivier, refus de lancement, refus d'un jeton expulsé. |
| `validation.room_settings.{not_editable, theme_keys, capacity_below_headcount}`, `validation.attributes.{themeKeys, avatar, answer, choice}` | C0, C5, C10 | Messages et attributs de validation nouveaux. |
| `validation.nickname.{length, script, characters, alnum, normalized_length, blocked, taken}` | C5 | Sept messages de la règle de pseudo. |
| `common.avatar.{alt.*, picker.*, preset.preset-NN}` | C5 | Avatars : textes alternatifs, sélecteur, 24 libellés. |
| `common.error.*`, `common.maintenance.{banner, launch_blocked}`, `common.connection.*`, `common.language.changed`, `common.nav.menu_description`, `common.appearance.*` ; J2 : `common.player.masked`, `common.closure.*` | C15, C16, C18-bis | Erreurs HTTP, maintenance, connexion, langue, navigation mobile, apparence. |
| `game.round.{tier_value, time_up, cancelled, lone_player}`, `game.pause.*`, `game.seat.*`, `game.host.*`, `game.solo.*`, `game.errors.*` | C7, C15 | Manche, pause, siège, hôte, solo, erreurs du moteur. |
| `game.answer.*`, `game.choices.*` | C10, C11 | Saisie, refus neutre, tentatives ; QCM. |
| `game.score.*`, `game.leaderboard.*`, `game.podium.*`, `game.recap.*` | C13 | Score, classement, podium, récapitulatif. |
| `game.frame.{loading, unavailable}`, `game.a11y.*`, `game.help.{prefix, scoring.*}` | C16, C12, C13 | Cadre d'image, annonces, textes d'aide. |
| `legal.{footer.*, new_tab, provisional, contact.unavailable, tmdb.*, report.title}` | C15, C16 | Pied de page et pages légales. |
| `admin.frame.*`, `admin.validation.{crop.*, frame_source.*}` | C9 | Traitement, recadrage, capture, aperçu. |
| `admin.enum.admin_action.*`, `admin.enum.admin_action_subject.*`, `admin.validation.real_name`, `admin.console.first_admin.{real_name_prompt, real_name_required}` | C14 | Journal et nom réel. |
| `admin.exclusion_grid.v{n}.{slug}.{label, help}`, `admin.exclusion_grid.retroactive.*`, `admin.review.*` | C14-bis | Grille versionnée et revue. |
| `admin.footer.*`, `admin.a11y.nav_mobile{,_description}` | C15, C16 | Pied du back-office et coquille mobile. |
| `admin.console.deploy.*` | C18-bis | Sortie console du drainage. |

### Lexique (termes produit nouveaux)

| Produit (FR) | Code (EN) | Définition |
|---|---|---|
| œuvre | `work` | Film sans groupe, ou `movie_group` entier ; unité de compte du vivier (C2). |
| vivier catalogue / vivier du salon | catalogue pool / room pool | Vivier (thèmes, N) ; le même moins la non-répétition du salon (C2). |
| mémoire du salon | room memory | Fenêtre commune à la non-répétition et à la préférence de variante (C2). |
| manche de réserve | margin round | Manche tirée au-delà de M, sans numéro, qui remplace une annulée (C3). |
| texte épuisé, QCM attendu | `text_exhausted` | Siège en Normal qui a épuisé ses tentatives texte et attend le QCM (C10, D20). |
| expulsé | `kicked` | Siège parti par geste d'hôte, jeton refusé dans ce salon jusqu'à l'archivage (C4, D15). |
| gel du podium | `finalize` | Écriture idempotente de la fin de partie et des agrégats (C13). |
| fait marquant | `highlight` | Meilleure réponse, trouvaille la plus rapide, film que personne n'a eu (C13, D25). |
| drainage | drain | Blocage borné des lancements avant un déploiement manuel (C18-bis, D32). |


---

## Journal de vérification

Trois contradicteurs ont contre-relu cette feuille le 23/09, sous trois angles : « décisions », « code » et « cohérence ». Chaque constat a été vérifié à sa source avant d'être corrigé : `decisions-2309.md`, `synthesis.json`, le corpus, le code du dépôt et `vendor/`. Les constats sont numérotés dans l'ordre où ils ont été reçus.

| # | Contrat visé | Verdict | Raison, en une ligne |
|---|---|---|---|
| V-01 | C9 voie capture × n° 0 (A-24, A-52, A-76) | appliqué | L'écart est réel et n'était pas tracé. Il est conservé, car D6 (plancher revalidé et vérifiable côté serveur) prime sur une résolution par défaut. Il est tracé en R-46 et signalé au porteur ; A-24, A-52 et A-76 portent « sous réserve de R-46 ». |
| V-02 | A-55 × S2 | appliqué | La phrase n'existe pas à q-o l.294 : A-55 est réduit à D33. Ajouts : A-55bis (q-o l.268), A-36ter (00 l.473), A-81 (REPRISE l.71). La l.354 de q-o est couverte par A-64. |
| V-03 | A-32, A-51, A-64, A-79 | appliqué | 100 [J1] est ajoutée au J1 (D30, S1) et 90 J1 passe avant 60 (D3). A-36bis inscrit le montage (a) (D1). |
| V-04 | C13 § 4.7, E10-38 | appliqué | 10 l.764-768 et § 6.1 exigent l'incrément sur `tierGraceMs` et `preloadLeadMs`, et aucune décision ne dit le contraire. C13 est réaligné et le membre fautif est retiré de E10-38. |
| V-05 | C7 § 5, processus `game` | appliqué | « Au moins 2 processus » contredisait D1 et D30. Un seul processus `game` ; leur nombre est fixé par 100 après D33 et signalé au porteur. |
| V-06 | C18-bis hook × S4 | appliqué | `PlatformDataSeeder` réécrit bien `is_published`, `sort_order` et les libellés. L'étape 6 est limitée à `setting_preset`, l'amorçage passe en insert-if-absent, avec une exigence au lot de 30. |
| V-07 | C13 § 4.1 et § 8, égalité | appliqué | Calcul vérifié : à N = 3, 300 = 200 + 100 à la frontière 1→2. N = 2 et N = 4 restent strictement décroissants. Test ajouté. |
| V-08 | C11 rang R5 × D21 | appliqué | D21 ne lève jamais la non-répétition. R5 est retiré (C3, C11, R-17, glossaire, appels vérifiés) ; le cas terminal vient après R4. |
| V-09 | C5 `ALLOWED_PATTERN` | appliqué | Vérifié en PHP : `ª` et `º` sont `\p{Latin}` et se translittèrent en `a` et `o`, donc admis. `µ` reste exclu (script non latin). |
| V-10 | C14-bis clés `.label` × n° 6 | appliqué | L'écart est réel et n'était pas tracé : R-47 (contrainte des tableaux de langue PHP, écart de forme). A-59 est aligné. |
| V-11 | C12 `answers:collisions` au J1 | appliqué | Une commande artisan sur le chemin du curateur disqualifie le pilote (D10). Elle devient un outil du porteur ; la suppression d'un alias passe par l'écran de 20. |
| V-12 | E10-34 × n° 43 | appliqué | `Player.php` l.93 masque aussi `room_id` et `user` : E10-34 est aligné. E10-34bis ajouté pour la pose de l'hôte par l'action de transfert. |
| V-13 | C2 définition de « joué » | appliqué | `started_at` est écrit dès la programmation. « Joué » devient `started_at ≤ now` (T₁ franchi), avec un champ `playedUntil` et un test ; E10-64 est aligné. |
| V-14 | E10-32 et C12, `saved_config.name_normalized` | appliqué | `fold()` sort de l'alphabet `[a-z0-9 ]` d'A6. La clause est retirée (sujet J2 de 50, A6 inchangé) ; R-39 et C12 § 4 sont alignés. |
| V-15 | C0, docblock de `RECOMMENDED_MIN_REVEAL_DURATION` | appliqué | Le palier 1 suivant est servable pendant la fin de R. Formulation réécrite, liée au garde-fou et à C7 § 4.14. |
| V-16 | E10-54 × 10 A11 | appliqué | A11 l.1351 porte la même formule retirée : E10-54 vise désormais § 7.8 l.911 et A11. |
| V-17 | C9, garde `can:create,movie` | appliqué | `MoviePolicy::create()` rend toujours faux, et le middleware `can` résout la policy par son premier argument. Garde corrigée en `can:create,App\Models\Frame,movie` ; test ajouté. |
| V-18 | C8 § 2 et C7 `clock.show`, pile sans session | appliqué | Vérifié dans `vendor/` : sur un GET, `PreventRequestForgery` lit `session()->token()`, et `SetLocale` met un cookie en file. Les deux middlewares sont retirés ; test ajouté. |
| V-19 | C14, nom réel dans les fabriques | appliqué | `UserFactory`, `DemoAccountsSeeder`, `FrameReviewFactory::by()` et `AdminActionFactory::byActor()` vérifiés. Bloc « Code à aligner » et test ajoutés. |
| V-20 | C15, C16, page `dashboard` | appliqué | Option (b) : `dashboard` passe sur `AppLayout`, avec `account` et `legal` déclarés. `/broadcasting/auth` et `clock.show` entrent dans les exclusions du test. |
| V-21 | C6, `FROZEN_COLUMNS` × colonne `json` | appliqué | `RoomSettingsCast` n'implémente pas la comparaison. Comparaison par valeur (`ComparesCastableAttributes`) et test du groupe `mysql` ajoutés ; l'identité octet pour octet est signalée « SQLite seulement ». |
| V-22 | C18-bis, `backup:snapshot` et `catalog:reproject` | appliqué | Ces commandes sont absentes de `app/Console/Commands/`. Déclarées [nouveau] (100), avec `--if-pending`, codes de sortie, tests, glossaire et A-72. |
| V-23 | C9, cast `FrameProcessingFailure` | appliqué | `FrameFactory`, `Frame` et le présentateur vérifiés. La méthode réelle est `AdminCatalogPresenter::movieFrame()`, pas `frame()`. |
| V-24 | C0, docblock de `PlatformLimits` | appliqué | Docblock réécrit, mais aligné sur 10 (V-04) : un changement du défaut de `tierGraceMs` ou de `preloadLeadMs` **incrémente** `scoring_version`. |
| V-25 | C9 § 3, `Set-Cookie` de l'aperçu | appliqué | L'aperçu vit dans la pile `web`, avec session. `Set-Cookie` n'est absent que sur `/f/` ; E10-59 est aligné. |
| V-26 | C9 § 6, C18 § 2.5, R-40, A-76 : Imagick en CI | appliqué | `composer.json` l.13 et `tests.yml` l.28 déclarent déjà Imagick : marqués [existant]. Seul `tests-mysql.yml` le reçoit. |
| V-27 | Marqueurs sur des noms absents du dépôt | appliqué | Marqueur [brouillon] créé et appliqué aux sept noms cités, plus `game.rules.scoring.*`, la section `operations` et `game.connection.*`. |
| V-28 | C2, chemin des clés de vivier | appliqué | Vérifié dans `lang/fr/admin.php` : le chemin est `admin.dashboard.pool.*`. |
| V-29 | C7 `room.round.next` × `RoomPolicy` | appliqué | `RoomPolicy::advanceRound()` est ajoutée en C6 et citée en C7 ; test « refuse la manche suivante à un non-hôte ». |
| V-30 | C5, fabriques dupliquant format et longueur | appliqué | `UserFactory::withPresetAvatar()` et `PlayerFactory::NICKNAME_MAX_LENGTH` vérifiés, puis ajoutés au code à aligner. |
| V-31 | C8, nom du paramètre `{serveToken}` | appliqué | `{serveToken}` est figé (signature du contrôleur) et `serve_token` désigne la colonne. Occurrences remplacées, avec une note pour la prose de 00, 10 et CLAUDE.md. |
| V-32 | C7 § 3, titres dans une charge ciblée | appliqué | Exception des quatre chaînes du QCM écrite (05 l.179). Test renommé, test de permutation ajouté. |
| V-33 | C7 § 4.5 × C8 § 4.3, `OpenTier` après une fin anticipée | appliqué | Règle de péremption écrite dans les deux contrats ; test ajouté à `ServeTokenMintTest`. |
| V-34 | C10 × C11, épuisement après une composition terminale | appliqué | Nouvelle ligne de la machine d'états et règle de `:exhaustedState`, lue sur la ligne `round` déjà chargée (L4 tenu). Test ajouté. |
| V-35 | C10 `ConstantWorkRefusalTest` × E10-57 | appliqué | L'`UPDATE` de S8a est une écriture de domaine. Test renommé ; E10-57 dit « aucune insertion ». |
| V-36 | C7, enchaînement des manches | appliqué | Invariant C7 § 4.14 : `RevealRound` programme la suite, `EndReveal` précède `OpenTier(k+1, 1)`. Répercuté sur la ligne `round.scheduled`, le glossaire, C0 et C17. |
| V-37 | C11 `DecoyPicker::pick` sans instant | appliqué | Paramètre `$at` (instant théorique) et `forDecoys($round, $at)` ; test de rattrapage tardif. |
| V-38 | C10, L3 | appliqué | Reformulé : seules les valeurs temporelles du client sont exclues ; `round` sert à refuser une autre manche. |
| V-39 | C7 `GameStateBuilder::build` | appliqué | Signature en `?Game $game` ; hors partie, le salon se lit par `$seat->room`. |
| V-40 | C7 `room.round.next` × `RoomPolicy` (doublon de V-29) | appliqué | Même correctif que V-29. |
| V-41 | C13, égalité N = 3 (doublon de V-07) | appliqué | Même correctif que V-07. |
| V-42 | C7 § 3, `expires` de l'exemple | appliqué | Recalculé : 14:05:46.300Z donne 1790172346, tronqué à la seconde comme le fait `temporarySignedRoute()` (et non 1790172347). Formule citée. |
| V-43 | E10-53, borne `COUNT ≥ 1` | appliqué | 10 l.892 porte cette borne : elle est conservée. |
| V-44 | C12 § 8, sonde de force brute | appliqué | Seuil renommé `K` ; jointure `round` et exclusion de `cancelled` (L1). |
| V-45 | C13 `LeaderboardVisibilityTest` × `Podium` | appliqué | Test borné aux blocs d'avant le gel ; seul `Podium.recap` porte des titres. |
| V-46 | C2, « joué » par `status <> 'pending'` | appliqué (repli) | Le prédicat temporel de V-13 est retenu pour garder l'index. Le résidu `stale_game` est écrit explicitement pour 30, repli que prévoyait le constat. |
| V-47 | C0, gardes des accesseurs statiques | appliqué | `PlatformLimits.php` l.201-204 lit la configuration sans garde. Chaque accesseur statique applique désormais la garde ; test ajouté. |
| V-48 | C7 § 4.8, ordre à T₁ en Facile | appliqué | D'abord les `round_player`, puis la composition, puis l'émission. |
| V-49 | C7, émissions en solo | appliqué | `player.locked` et `seat.choices` en multijoueur seulement ; en solo, tout passe par `solo.state`. |
| V-50 | C0 × C7, job d'anti-rebond | appliqué | `App\Jobs\Game\BroadcastLobbyState` (file `game`, unique par salon jusqu'au traitement) est nommé en C7 § 2.5 et cité en C0 et au glossaire. |

**Aucun constat n'est rejeté.** Deux sont appliqués sous une autre forme que le correctif proposé : V-01 (écart conservé et tracé, parce que D6 prime) et V-46 (repli sur le résidu écrit). V-24 et V-42 sont appliqués avec un contenu corrigé.
