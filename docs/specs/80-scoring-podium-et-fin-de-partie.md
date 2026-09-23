# Scoring, podium et fin de partie

Ce document est le **propriétaire unique de la règle de score** de TripleFrames. Il décide combien vaut une bonne réponse, à quel palier elle est créditée, comment les points se cumulent, quand un score devient visible des autres joueurs, comment deux joueurs se départagent, comment une partie se fige — terminée ou interrompue — et ce que montrent le classement intermédiaire, le podium, le récapitulatif et ses faits marquants. Il possède le **contrat C13** en entier (`ScoringRules`, `ScoreCalculator`, `Ranking`, `Scoreboard`, `ScoreReplayer`, `FinalizeGame`, `GameFinalized`), la **valeur** de B_max par `N` (D22 du 23/09), la fonction client **`tierValueAt`** (D29 du 23/09) et le **texte FR/EN** des familles `game.score.*`, `game.leaderboard.*`, `game.podium.*`, `game.recap.*`, `game.help.scoring.*` et de la clé `game.round.tier_value`.

Il ne possède **aucune table** : chaque colonne lue ou écrite ici appartient à `10-catalogue-et-modele-de-donnees.md`, et une donnée nouvelle serait une exigence adressée à `10` (§ 18). Il ne possède ni la classe `PlatformLimits` ni le value object `RoomSettings` (`50`, contrat C0), ni les événements, canaux, paquet de resynchronisation et transitions de manche (`60`, contrat C7), ni la fenêtre d'acceptation et la transaction de verrouillage (`70`, contrat C10), ni les coquilles et la liste close des composants (`90`, contrat C16), ni les quatre compteurs d'historique (`40`, jalon 2). Il fournit des **blocs de données** que ces specs transportent et affichent, et les composants de classement et de podium qui les rendent.

Convention de renvoi, valable dans tout le document : « règle N » désigne `CLAUDE.md` §7 ; « principe N » désigne `00-overview.md` § Principes directeurs ; « décision N » désigne une des 19 décisions du 22/09 (`questions-ouvertes.md`) ; « DN du 23/09 » désigne une décision du 23/09, consignée dans `questions-ouvertes.md` § Décisions du 23/09/2026 ; « contrat Cn », « E10-nn », « A-nn » et « R-nn » renvoient à la feuille de contrats du 23/09, et « note N du 23/09 » (ou « note N du rédacteur en chef ») à l'un des arbitrages qui la complètent ; « n° N » renvoie à la contradiction N de la pré-analyse du 23/09 (numérotée à partir de 0) et « Q80-n » à une question de cette pré-analyse close par son défaut retenu ; « 10 § x » renvoie au schéma ; « § x » seul renvoie à ce document.

> **État réel du dépôt au moment d'écrire, vérifié au commit `d167a6a`.** Aucun code de score n'existe : ni `app/Support/Scoring/`, ni `app/ValueObjects/Scoring/`, ni `app/Actions/Game/`, ni `app/Events/` (`app/Actions/` ne contient que `Fortify/`), ni `config/game.php`, ni `routes/game.php`, ni `resources/js/lib/game/`, ni `resources/js/components/game/`, ni `resources/js/types/scoring.ts`, ni `tests/Feature/Scoring/`, ni `tests/Concurrency/`. `BROADCAST_CONNECTION=log` : le classement et le podium n'ont aujourd'hui aucun transport. Ce qui existe, et que cette spec lit ou modifie :
>
> - `app/Settings/PlatformLimits.php` porte `DEFAULT_SPEED_BONUS_MAX_FRACTION = 0.50` en `const float` (l.48) et `speedBonusMaxFraction(): float`, lue par `Config::float('game.platform.speed_bonus_max_fraction')` (l.145-151). B_max est donc aujourd'hui **flottant, surchargeable par configuration et persisté nulle part** — exactement le trou que D22 du 23/09 ferme. `toArray()` l'expose au client en flottant (l.194) ; les accesseurs lisent la configuration sans garde (l.201-204).
> - `app/Settings/RoomSettings.php` : `tierPoints`, `speedBonus`, `tierStartOffsetMs()`, et `warnings()` qui émet `non_decreasing_points` dès qu'un palier vaut **au moins** le précédent, puis `all_tiers_zero` (l.490-518). Son docblock l.27 cite encore `speedBonusMaxFraction`.
> - `app/Settings/RoomSettingsBounds.php` : `MIN_TIER_POINTS = 0`, `MAX_TIER_POINTS = 1000`, `TIER_POINTS_UNIT = 100`, `defaultTierPoints()`, `DEFAULT_SPEED_BONUS = true`, `MIN_TIER_DURATION = 5`, `maxTierDuration()`. `SettingPresetCatalog` : aucun preset ne fixe `tierPoints` ; Hardcore est à `N = 5` et 45 s.
> - `app/Models/Guess.php` : `tier_index` et les trois parts de points en `#[Hidden]` ; scope `counted()` qui n'exclut que `cancelled`, donc **compte une manche `running`** ; docblock l.25 « au plus 120 lignes par partie » et l.55-56 « `makeVisible()` explicite après `reveal_ends_at` », faux tous les deux (§ 7) ; l.39 « la fraction de bonus par `game.scoring_version` », antérieur à D22 du 23/09 ; docblock de `counted()` (l.131-135) « **Toute agrégation de `guess` passe par ici** — score vivant, classement intermédiaire, gel du podium, les quatre compteurs d'historique », qui prescrit l'inverse du § 7.
> - `app/Models/GamePlayer.php` et la migration `2026_09_22_100027_create_game_player_table.php` : cinq agrégats nullables, `final_rank` en `unsignedTinyInteger` ; `avatarRef()` résolu sur les colonnes gelées `display_*` ; docblock l.26-27 « Le score vivant n'est PAS ici : c'est `SUM(guess.points_total)` sous l'invariant L1 ({@see Guess::counted()}) », qui renvoie lui aussi toute lecture de score à `counted()`.
> - `app/Models/Game.php` (`scoring_version`, `rounds_completed` défaut 0, `ended_at`, `tier_grace_ms`, `settings_snapshot` en `#[Hidden]`), `Round.php` (scopes `notCancelled()` et `completed()`), `RoundTier.php` (`containsOffsetMs()`, l.146).
> - Enums : `GameStatus` (4 cas), `RoundStatus` (5), `GamePlayerStatus` (3), `GuessSource` (`text`, `choice`), `RoundPlayerInputState` (6 cas, sans `text_exhausted`, que `70` ajoute), `InputDifficulty::choicesOpenTierIndex()`.
> - `database/factories/GameFactory.php` : `SCORING_VERSION = 1`, « provisoire nommé » en attente de cette spec (l.49) ; ses états `completed()` et `interrupted()` posent `ended_at` sans aucun agrégat. `GuessFactory` : `atTier()` (bonus 0) et `withSpeedBonus(int)`. `GamePlayerFactory::finished(...)`.
> - `lang/{fr,en}/game.php` : la seule clé `frame.alt`. Aucun des onze fichiers `lang/fr/*.php` ne contient d'espace insécable (U+00A0) : « : » y est précédé d'une espace ordinaire (U+0020). `resources/js/lib/i18n.ts` (propriété de 05, C15 § 2.1) : `t()` et `tChoice()` ; `translateChoice` **écrase** un `count` fourni (`{ ...replacements, count }`) là où `Translator::choice` le conserve (`vendor/laravel/framework/src/Illuminate/Translation/Translator.php` l.252).
> - Outillage de test : `vite.config.ts` n'a aucun bloc `test`, `package.json` aucun script `test`, `phpunit.xml` aucune suite `Concurrency`, et ni `tests/Concurrency/`, ni `tests/Frontend/`, ni `tests/Datasets/` n'existent : tout cela est posé par C18 (100 [J1]).
> - `tests/Feature/I18n/TranslationCoverageTest.php` extrait les paramètres d'une ligne par `:(?!:)([A-Za-z][A-Za-z0-9_]*)` : une lettre ASCII collée derrière `:rank` serait lue comme un autre paramètre (§ 15.1).
>
> Tout ce qui suit est à construire, à l'exception des quelques lignes de code existant marquées « modifié ».

---

## 1. Frontières

### 1.1 Ce que cette spec possède

| Élément | Consommateurs | Jalon |
|---|---|---|
| `ScoreCalculator` et `ScoringRules` : palier retenu, plancher du QCM, points, version | 70 (transaction de verrouillage), 60 (rejeu de chronologie), 100 (tests), 20 (rejeu, J2) | J1 |
| La **valeur et la règle** de B_max(N) ; nom et signature de `PlatformLimits::speedBonusMaxPercent()` figés par C0 | 50, 90 (texte d'aide), 100 | J1 |
| `Ranking` (chaîne de départage) et `Scoreboard` (lectures, classement intermédiaire, score du siège, podium) | 60 (charges de révélation, de fin et de resynchronisation), 90, 40 (J2) | J1 |
| `FinalizeGame` (gel) et `GameFinalized` | 60 (fin normale, annulation sans manche restante, clôture à 15 min — par `InterruptPausedGame` ou par `ResumeGame` sur un battement tardif —, passage de la dernière manche solo, relance d'un solo en cours, reprise d'une partie bloquée), 100 (clôture forcée `stale_game`), 50 (« Rejouer »), 40 (J2, cache des compteurs) | J1 |
| `ScoreReplayer` | 100 (test de 10 § 7.5), 20 (écran « inspecter une partie », J2) | `replay()` J1, `mismatches()` J2 |
| Mode sans score, mode sans gradient | 50, 90 | calcul et tests J1 ; affichage J2 |
| `ScoringRules::waitingPays()` | 50 (avertissement éventuel) | J2 |
| `tierValueAt` et le texte de `game.round.tier_value` (D29 du 23/09) | 60, 90 | J1 |
| Composants de classement, de récapitulatif et de podium (§ 20, L80-7) | 60 (pages `game/*`) | J1 |

### 1.2 Ce qu'elle consomme, sans jamais le redéfinir

| Contrat | Propriétaire | Ce que 80 en lit |
|---|---|---|
| C0 | 50 | `RoomSettings` figé dans `game.settings_snapshot` (`tierPoints`, `speedBonus`) ; `RoomSettingsBounds` (bornes du barème et des durées) ; `PlatformLimits::speedBonusMaxPercent()`, `FULL_PERCENT`, `SPEED_BONUS_MAX_PERCENT_CAP`, `tierGraceMs()` ; `toArray()['speedBonusMaxPercent']` pour le texte d'aide. |
| C7 | 60 | Les transitions qui appellent 80 (`RevealRound`, `EndReveal`, `CancelRound` sans manche restante, `InterruptPausedGame`, `ResumeGame` sur un battement tardif, `SkipSoloRound` sur la dernière manche, `StartSoloGame` sur un solo en cours, reprise d'une partie bloquée) ; les noms et l'enveloppe des messages qui transportent les blocs de 80 (`round.revealed`, `game.ended`, `GameStatePacket`) ; `RevealMovie`, qui est le `TitlePacket` du récapitulatif ; `RoundClock::offsetMs()`. |
| C10 | 70 | L'appel unique de `ScoreCalculator::forGuess()` dans `LockGuess` ; la fenêtre d'acceptation ; le refus d'un clic reçu avant l'ouverture du QCM ; `answeredAtMs` calculé une fois à l'entrée de la requête. |
| C5 | 40 [J1] | `PlayerIdentity::fromGamePlayer()`, seule sérialisation de l'identité affichée d'un siège au podium. |
| C6 | 50 | `OpenGame` écrit `game.scoring_version` ; `ReplayRoom` exige `ended_at` non nul (règle R5). |
| C11 | 70 | `DisplayTitleResolver`, par l'intermédiaire de `RevealMovie`. |
| C16 | 90 | `lib/game/round-timeline.ts`, où vit `tierValueAt` ; liste close des composants ; règles de focus ; composants d'état. |
| C17 | 60 | « `ended_at` non nul ⟺ statut terminal », dont `FinalizeGame` est l'unique écrivain. |
| C18 | 100 | Domaine de test `Scoring` ; groupe `locks-timing` par répertoire `tests/Concurrency/` (suite `Concurrency` de `phpunit.xml`, `DatabaseTruncation` et `requireMysql()` dans `tests/Pest.php`, workflow `tests-mysql.yml`) ; jeu de données `room_settings.accepted` (`tests/Datasets/RoomSettingsMatrix.php`) ; bloc `test` de `vite.config.ts`, script `npm run test` et `tests/Frontend/**/*.ts` dans `tsconfig.json`. Tout est absent au commit `d167a6a`. |

### 1.3 Entrées figées : ce que le calcul lit, et rien d'autre

Le calcul d'une bonne réponse ne lit que des **entiers figés au lancement ou au verrouillage** (10 § 1.2 : « le calcul ne dépend jamais d'un horodatage »). C'est ce qui rend le rejeu exact et le journal opposable (10 § 7, principe 1).

| Valeur | Source unique | Jamais |
|---|---|---|
| Offsets, durées et valeurs de palier | `round_tier.starts_at_offset_ms`, `duration_ms`, `points`, matérialisés au lancement par `MaterializeDraw` (60) | `settings_snapshot` sur le chemin de score ; `room.settings` |
| Instant de la réponse | `guess.answered_at_ms` = `RoundClock::offsetMs($round, ReceptionInstant::of($request))` (C7, C10) | une valeur mesurée ou déclarée par le client (invariant L3, 10 § 1.8) |
| Grâce de frontière | `game.tier_grace_ms`, figé au lancement depuis `PlatformLimits::tierGraceMs()` (300 au défaut) | `PlatformLimits` au rejeu ; `disconnectGraceSeconds`, réglage d'hôte sans rapport (10 § 1.3) |
| Interrupteur du bonus | `game.settings_snapshot->speedBonus` (défaut `RoomSettingsBounds::DEFAULT_SPEED_BONUS`, activé ; éditable en onglet Avancé au J2) | — |
| B_max (%) | `ScoringRules::speedBonusMaxPercent(game.frames_per_round, game.scoring_version)`, qui délègue en v1 à `PlatformLimits::speedBonusMaxPercent()` | la configuration ; un compte ; un plan |
| Plancher de palier | `InputDifficulty::choicesOpenTierIndex(game.frames_per_round)` via `ScoringRules::floorTierIndex()`, selon `guess.source` et `game.input_difficulty` | — |
| Version de la règle | `game.scoring_version`, écrite depuis `ScoringRules::VERSION` | la constante courante, pour une partie ancienne |
| Chaîne de départage | `ScoringRules::TIE_BREAK_CHAIN`, sous version | la graine (§ 8.3) |
| Mode sans score | `game.settings_snapshot->tierPoints` | — |
| 100 (base du pourcentage), 1 000 (ms par seconde) | Unités, pas des valeurs de jeu : `PlatformLimits::FULL_PERCENT` et la conversion de `TierSchedule::fromSettings()` | — |
| Délai de clôture après pause | `EngineConstants::pauseTimeoutMs()` (60), lu par l'appelant qui fournit `$endedAt` à 80 | — |

### 1.4 Exigences adressées aux specs sœurs

| Spec | Exigence | Source |
|---|---|---|
| 50 | `OpenGame` écrit `game.scoring_version = ScoringRules::VERSION` ; `fromInput()` refuse la clé `speedBonusMaxPercent` (clé inconnue = refus dur). | contrat C13 § 6, C6, C0 |
| 50 | L50-1 écrit le changement B_max de `PlatformLimits` — `speedBonusMaxPercent()`, `FULL_PERCENT`, `SPEED_BONUS_MAX_PERCENT_CAP`, suppression de la fraction, clé `toArray()`, docblocks de `PlatformLimits` et de `RoomSettings` — et les trois cas B_max de `PlatformLimitsTest` (§ 16). 80 en fixe la valeur et la règle (§ 3.1) ; il n'écrit ni ce code ni ces tests. | contrat C13 § 1 et § 7, C0 |
| 50 | Aligner `PlatformLimits` et `config/game.php` sur la note 2 du rédacteur en chef du 23/09 : `tierGraceMs` et `preloadLeadMs` **ne sont pas surchargeables par configuration** (§ 3.5). C0 § 2 les range encore dans une famille « surchargeable » et liste `tier_grace_ms` et `preload_lead_ms` parmi les quinze clés `game.platform.*` ; L50-1 l'applique (treize clés `game.platform.*`, test `ignore toute configuration de tier_grace_ms et de preload_lead_ms`). | note 2 du 23/09 |
| 60 | Insérer les blocs du § 9 et du § 11 dans `round.revealed`, `game.ended` et `GameStatePacket`, sous les noms de C7. `GameStateBuilder` passe à `Scoreboard::leaderboard()` la manche en `revealing` s'il y en a une, `null` sinon. | contrat C7 |
| 60 | Appeler `FinalizeGame` dans les six cas du § 10.5 qui lui reviennent — fin normale, annulation sans manche restante, clôture après pause (`InterruptPausedGame` ou battement tardif de `ResumeGame`), passer la dernière manche solo, relance d'un solo en cours, reprise d'une partie bloquée —, avec le `$outcome` et le `$endedAt` de ce tableau. **Un appelant qui tient déjà un verrou `round` ne l'appelle qu'après son commit, ou prend `game` avant `round`** : l'ordre de verrouillage global est room → player → game → round → round_player (C7 § 4.3). | contrat C13 § 4.5 |
| 60 | (a) `types/game-wire.ts` (L60-2) importe `TierWindow`, `RoundFinder`, `Leaderboard` et `Podium` de `types/scoring.ts` (60 § 11.4), qui importe en retour `RevealMovie` (C13 § 2.4, R-24) : l'import mutuel, en `import type` seul, passe `tsc`, mais aucun des deux fichiers ne compile sans l'autre — **L60-2 et L80-3 entrent dans le même commit**, et 60 § 22 bis ne compte pas cette dépendance. (b) La construction PHP de `RevealMovie` posée par L60-6 pour `RevealRound` est appelable hors de cette transition, et `Scoreboard::podium()` la réutilise pour `recap[].titles` : un seul constructeur du paquet, sinon révélation et récapitulatif pourraient diverger (R-24) ; 60 § 22 bis ne compte que L60-2. (c) L'assistant client de rendu des titres de `RevealMovie` (titre retenu, titre original s'il diffère, année, attribut `lang`), qu'aucun lot de 60 ne range aujourd'hui, est livré par L60-9 : l'écran de révélation (L60-14) et le récapitulatif (L80-7, § 11.4) l'emploient tous deux, et le placer en L60-14 ferait un cycle avec L80-7. | **exigence nouvelle, non consolidée** |
| 70 | Appeler `ScoreCalculator::forGuess()` une seule fois par bonne réponse, à l'étape 3 de `LockGuess`, et écrire ses quatre sorties telles quelles. | contrat C10 |
| 90 | Inscrire nominativement `standings-table.tsx`, `round-recap.tsx`, `podium-highlights.tsx` et `podium.tsx` (L80-7) dans la liste des composants propres autorisés de C16 § 2.9, qui énumère les composants qu'un composant de `components/game/` peut composer. | **exigence nouvelle, non consolidée** |
| 05 (propriétaire de `resources/js/lib/i18n.ts`, C15 § 2.1) | Aligner `translateChoice` sur `Translator::choice` : un `count` fourni dans les remplacements est **conservé**, `count` n'est injecté que s'il est absent ; test de parité `conserve un count fourni, comme Translator::choice`, dans `tests/Frontend/i18n/translate-choice.test.ts` (fichier créé par L100-3). Sans ce correctif, aucun nombre formaté par `Intl.NumberFormat` ne peut passer par `:count` (§ 15.1). C15 § 2.1 déclare ce fichier « inchangé » : 80 ne le modifie pas, et l'écart est **signalé au porteur**. | **exigence nouvelle, non consolidée** |
| 60 (J2) | Masquer `game.round.tier_value` quand la partie est sans score (`Leaderboard.scoreless`, reflet de `ScoringRules::isScoreless(game.settings_snapshot)`, § 14). | **exigence nouvelle, non consolidée** |
| 90 (J2) | Masquer la ligne `game.help.scoring.speed_bonus` de l'écran d'aide quand `speedBonus` est faux dans le contexte affiché (§ 15.2). | **exigence nouvelle, non consolidée** |

---

## 2. Le barème des paliers

### 2.1 Valeur par défaut, bornes, provenance

La valeur par défaut du palier `i` sur `N` est `P_i = (N − i + 1) × RoomSettingsBounds::TIER_POINTS_UNIT`, calculée par `RoomSettingsBounds::defaultTierPoints(N)` : au réglage par défaut, `N = 3` donne 300 / 200 / 100 ; `N = 5` donne 500 / 400 / 300 / 200 / 100 (00 § Scoring, 10 § 6.1). Chaque valeur est éditable dans `[MIN_TIER_POINTS, MAX_TIER_POINTS]`, soit 0 à 1 000, dans l'onglet Avancé (50, **jalon 2**).

**Au jalon 1, l'onglet Simple est le seul** : le barème vaut toujours `defaultTierPoints(N)`, redérivé quand `N` change (D34 du 23/09, contrat C0 § 3.3), et `speedBonus` reste à son défaut, activé (C0 § 3.1). **Toute partie du jalon 1 est donc au barème par défaut, bonus actif** — c'est ce qui rend la propriété du § 3.3 vraie pour chacune d'elles.

### 2.2 Barème figé et journalisé

Au lancement, `MaterializeDraw` (60) écrit pour chaque palier une ligne `round_tier` dont `points` vaut `settings_snapshot.tierPoints[i − 1]` ; la colonne n'est plus jamais modifiée (10 § 7.4). **Le calcul lit `round_tier` par `TierSchedule::fromRound()`, et jamais `settings_snapshot` ni `room.settings`** : le barème n'est plus déductible de la position, une resynchronisation ou un rejeu ne recalcule jamais un score différent (principe 1, 00 § Déroulé d'une partie). `TierSchedule::fromSettings()` existe pour les fabriques, les tests et la parité client ; il n'apparaît jamais sur le chemin de score. Un réglage modifié après le lancement n'a aucun effet : les réglages sont gelés jusqu'au « Rejouer » de l'hôte (C0 § 4, D32 du 23/09).

### 2.3 Palier à 0

Une bonne réponse reçue sur un palier à 0 est **acceptée** (70), verrouille le siège, crée une ligne `guess`, compte dans `correct_answers`, dans les trouvailles par palier et dans le temps cumulé, et rapporte **0 point, bonus compris** (00 § Scoring). Le bonus est une fraction du palier : zéro fois une fraction vaut zéro. Trouver reste utile, parce que la chaîne de départage place le nombre de bonnes réponses juste après le score (§ 8).

### 2.4 Barème non strictement décroissant et mode sans gradient

Un barème non strictement décroissant est **autorisé et averti** par le code `non_decreasing_points` (code et texte : 50). 80 le calcule à l'identique, sans aucune correction.

**Sans gradient** — tous les paliers égaux, par exemple tous à 1 000 —, la valeur de palier ne distingue plus rien. Le bonus classe alors la promptitude **à l'intérieur de chaque palier**, puisqu'il repart de B_max à chaque ouverture, et la rapidité globale ne départage qu'à points et bonnes réponses égaux, par le temps cumulé (§ 8). Attendre la frontière y paie : tous paliers au plafond `MAX_TIER_POINTS` (1 000), la fin d'un palier vaut 1 000 et l'ouverture du suivant `1 000 + intdiv(1 000 × B_max(N), 100)`, soit 1 500 à `N = 2` et `N = 3`, 1 330 à `N = 4` et 1 250 à `N = 5` (§ 3.1). C'est précisément ce que l'avertissement signale (A-10). Avec le bonus désactivé en plus, le score devient proportionnel au nombre de bonnes réponses, et la chaîne de départage devient le classement réel (00 § Réglages du salon).

### 2.5 Mode sans score

`ScoringRules::isScoreless(RoomSettings $settings)` est vrai si et seulement si `array_sum($settings->tierPoints) === 0` (les valeurs étant ≥ 0, c'est « tous les paliers à 0 »). Le calcul ne change pas : chaque bonne réponse vaut 0, `final_score = 0` est **stocké**, jamais laissé nul, et la chaîne de départage commence de fait aux bonnes réponses (§ 8). `Leaderboard.scoreless` et `Podium.scoreless` valent vrai, et l'affichage met en tête bonnes réponses puis temps cumulé au lieu d'un podium 0-0-0 (00 § Réglages du salon).

Jalon : calcul et tests au **J1** (`RankingTest`, `PodiumTest`) ; affichage au **J2** (L80-8), avec l'onglet Avancé, seul chemin qui atteint ce mode.

### 2.6 Cumul, absence de pénalité, comparabilité

Le score d'un siège est `Σ guess.points_total` de ses bonnes réponses, sous la portée de lecture voulue (§ 7). Chaque terme est déjà entier : le cumul n'arrondit rien. **Aucune pénalité** pour une mauvaise réponse, qui n'est jamais stockée, seulement comptée (00 § Le jeu en une manche, décision 19). **Aucune colonne de score maintenue** : une colonne serait une seconde source de vérité qui dérive au premier job rejoué (10 § 7.3). Les scores ne se comparent qu'à l'intérieur d'une partie — 30 manches à 2 images ne valent pas 10 manches à 5 images — : aucun classement global, aucun score normalisé d'une partie à l'autre (00 § Scoring, hors périmètre v1).

---

## 3. Le bonus de rapidité

### 3.1 B_max fonction de N, en pourcentage entier (D22 du 23/09)

```php
// App\Settings\PlatformLimits (contrat C0) — famille « règle non persistée », jamais lue en configuration
public const int SPEED_BONUS_MAX_PERCENT_CAP = 50;
public const int FULL_PERCENT = 100;   // unité (100 %), pas une valeur de jeu
public static function speedBonusMaxPercent(int $framesPerRound): int;
```

`speedBonusMaxPercent(N) = min(SPEED_BONUS_MAX_PERCENT_CAP, intdiv(FULL_PERCENT, N − 1))`.

| `N` | 2 | 3 | 4 | 5 |
|---|---|---|---|---|
| B_max | 50 % | 50 % | 33 % | 25 % |

- **Pourcentage entier, calcul par `intdiv`**, jamais un flottant : un rejeu doit redonner exactement `points_total` (10 § 7.5).
- Hors de `[RoomSettingsBounds::MIN_FRAMES_PER_ROUND, MAX_FRAMES_PER_ROUND]`, la méthode lève `\InvalidArgumentException` et **n'écrête jamais** : un `N` écrêté masquerait une partie invalide et fausserait le rejeu (R-05).
- `DEFAULT_SPEED_BONUS_MAX_FRACTION`, `speedBonusMaxFraction()`, le paramètre de constructeur `float $speedBonusMaxFraction`, la clé `toArray()['speedBonusMaxFraction']` et la clé de configuration `game.platform.speed_bonus_max_fraction` sont **supprimés**. `toArray()` expose `speedBonusMaxPercent` indexé par `N` : `{"2":50,"3":50,"4":33,"5":25}` (C0 § 3.4).
- `ScoringRules::speedBonusMaxPercent(int $framesPerRound, int $version = self::VERSION)` est le seul point d'appel du calcul ; en version 1, il délègue à `PlatformLimits::speedBonusMaxPercent()`.

**Pourquoi une fonction de `N`.** La décision « Bonus de rapidité » (`questions-ouvertes.md` § Déjà tranché) fixait B_max à 50 % au motif qu'« à 100 %, attendre le palier suivant deviendrait payant ». Ce motif ne tenait qu'à `N ≤ 3`. Au barème par défaut, avec 50 % partout, la fin du palier 1 vaut 400 et l'ouverture du palier 2 vaut 300 + 150 = 450 à `N = 4` ; à `N = 5`, 500 contre 400 + 200 = 600. Le preset Hardcore (`N = 5`) expose le cas dès que le catalogue rend `N = 5` jouable. Au J1, D19 du 23/09 le ramène d'office à `N = 3` tant que la passe 2 de curation ne rend pas assez de films éligibles à cinq images (30) ; or l'hôte ne peut pas couper le bonus au J1 (§ 2.1) : la règle devait être juste avant que ce `N` ne devienne atteignable. D22 du 23/09 rend le motif vrai pour tout `N` sans toucher au réglage par défaut (`N = 3`), au barème par défaut ni aux presets.

### 3.2 Forme, arrondi et bornes

Version 1, pour une bonne réponse retenue au palier de valeur `P` et de durée `d` (en ms), à l'instant `t` compté depuis l'ouverture de ce palier (§ 4.2) :

```
pointsBonus = (speedBonus ∧ P > 0 ∧ pct > 0) ? intdiv(P × pct × (d − t), PlatformLimits::FULL_PERCENT × d) : 0
pointsTotal = P + pointsBonus
```

C'est la forme linéaire arbitrée, `B_max × (1 − t/d)`, écrite en entiers : `P × pct / 100 × (d − t) / d`.

- **Arrondi par défaut (plancher), sans aucun flottant.** À `t = 0`, le bonus vaut exactement `intdiv(P × pct, 100)`, son maximum ; il ne le dépasse jamais. Pourquoi l'entier : `floor(100 × 0.5 × (1 − 1700/5000))` vaut 32 quand la valeur exacte `intdiv(100 × 50 × 3300, 100 × 5000)` vaut 33. Un point d'écart entre le calcul en direct et un rejeu écrit plus tard suffit à perdre un litige de score, et la pré-analyse a mesuré 1 409 écarts d'un point de ce type sur un échantillon de valeurs légales (7 valeurs de palier, 6 durées, chaque milliseconde de `t`).
- **À la dernière milliseconde d'un palier** (`t = d − 1`), le bonus vaut `intdiv(P × pct, 100 × d)`, soit **0 pour toute valeur légale** : `P × pct ≤ MAX_TIER_POINTS × SPEED_BONUS_MAX_PERCENT_CAP = 50 000`, alors que `FULL_PERCENT × d ≥ 100 × MIN_TIER_DURATION × 1 000 = 500 000`. Ce fait porte la preuve du § 3.3 et la définition de `waitingPays()` (§ 3.4).
- **Bornes** : `pointsBonus ∈ [0, 500]` (`MAX_TIER_POINTS × SPEED_BONUS_MAX_PERCENT_CAP / FULL_PERCENT`), `pointsTotal ∈ [0, 1 500]`, qui tient dans `unsignedSmallInteger` (10 § 7.6). Toute hausse future de `MAX_TIER_POINTS` ou du plafond exige une revue du schéma par `10`.
- **Produit intermédiaire** : au plus `1 000 × 50 × 115 000 ≈ 5,75 × 10⁹` (`d` maximal = `maxTierDuration(2)` = 115 s), largement dans un entier PHP 64 bits.
- **Bonus désactivé, palier à 0 ou `pct = 0`** : `pointsBonus = 0`, seule la valeur du palier compte (00 § Scoring).

### 3.3 « Attendre ne paie jamais strictement plus » — propriété garantie au barème par défaut

**Énoncé.** Au barème par défaut, pour tout `N` de `MIN_FRAMES_PER_ROUND` à `MAX_FRAMES_PER_ROUND` et toute durée de manche légale, pour deux instants corrigés `c₁ < c₂` d'une même manche, `score(c₁) ≥ score(c₂)`. Attendre ne rapporte **jamais strictement plus**.

**Preuve, en trois temps.**

1. À l'intérieur d'un palier, le score ne croît jamais quand `t` augmente : le numérateur `d − t` décroît et `intdiv` est croissant.
2. À la frontière `i → i + 1`, la fin du palier `i` vaut `P_i` (bonus nul à la dernière milliseconde, § 3.2) et l'ouverture du palier `i + 1` vaut `P_{i+1} + intdiv(P_{i+1} × pct, 100)`. Avec `k = N − i`, le barème par défaut donne `P_i = (k + 1) × 100` et `P_{i+1} = k × 100` ; la condition `P_i ≥ P_{i+1} + k × pct` équivaut à `k × pct ≤ 100`. Le pire cas est la frontière 1 → 2, où `k = N − 1` : `(N − 1) × pct ≤ 100`, exactement ce que garantit `pct = min(50, intdiv(100, N − 1))`.
3. En enchaînant 1 et 2, la propriété vaut pour deux instants quelconques.

| `N` | B_max | Frontière 1 → 2 au barème par défaut | Verdict |
|---|---|---|---|
| 2 | 50 % | 200 contre 100 + 50 = 150 | strictement décroissant |
| 3 | 50 % | 300 contre 200 + 100 = 300 | **égalité exacte** |
| 4 | 33 % | 400 contre 300 + 99 = 399 | strictement décroissant |
| 5 | 25 % | 500 contre 400 + 100 = 500 | **égalité exacte** |

**Égalités exactes, à dire telles quelles.** À `N = 3` (le réglage par défaut du produit) et à `N = 5` (Hardcore), répondre à la dernière milliseconde du palier 1 rapporte **exactement** autant qu'à l'ouverture du palier 2 : attendre n'y gagne rien, sans jamais y perdre. Les autres frontières sont strictement décroissantes (à `N = 5` : 400 contre 375, 300 contre 250, 200 contre 125). Exemple au preset Hardcore (45 s en cinq paliers de 9 s, `tier_grace_ms` au défaut de 300) : une réponse reçue à 9 299 ms (instant corrigé 8 999, palier 1) vaut 500 ; reçue à 9 300 ms (corrigé 9 000, palier 2, `t = 0`), elle vaut 400 + 100 = 500. L'aide le dit (`game.help.scoring.speed_bonus`, § 15).

**Portée.** La propriété est une propriété **du barème par défaut** ; elle compare des instants corrigés, côté serveur (§ 4.2). Elle n'est pas garantie pour un barème personnalisé (§ 3.4).

### 3.4 Barèmes personnalisés et `waitingPays()` (jalon 2)

```php
/** J2 : vrai si, pour un i < N, P_i + 0 < P_{i+1} + intdiv(P_{i+1} × B_max(N), 100). @param list<int> $tierPoints */
public static function waitingPays(array $tierPoints, int $framesPerRound): bool;
```

Le terme `P_i + 0` est exact, pas une approximation : le bonus est nul à la dernière milliseconde de tout palier légal (§ 3.2). La fonction est fausse pour tout barème par défaut (test). Elle **n'est pas** couverte par le code `non_decreasing_points` : à `N = 3`, le barème 300 / 250 / 100 est strictement décroissant, donc non averti aujourd'hui, et pourtant 250 + 125 = 375 > 300. L'adoption d'un avertissement fondé sur `waitingPays()` revient à 50, avec l'onglet Avancé (J2).

### 3.5 Constantes d'instance, jamais résolues par compte

B_max, `tierGraceMs` et `preloadLeadMs` sont des **constantes d'instance** : aucun accesseur de `PlatformLimits` ne prend un `User`, un plan ou un siège (C0 § 4, principe 10, décision 2). B_max n'est **jamais lu en configuration** (C0). Par la note 2 du rédacteur en chef du 23/09, postérieure à C0 § 2, **`tierGraceMs` et `preloadLeadMs` ne le sont pas non plus** : seul un changement de leur défaut **dans le code** est possible, et il incrémente `ScoringRules::VERSION` (§ 6.1). Une surcharge de configuration est ignorée ou refusée au démarrage (test tenu par 50 dans `PlatformLimitsTest`, § 16).

Raison : `guess.points_bonus` est écrit sous B_max, `guess.tier_index` sous `tierGraceMs`, et un palier est servi sous `preloadLeadMs` — trois faits conservés douze mois (10 § 6.1). Une surcharge de configuration changerait la règle sans changer la version, et le journal opposable contredirait `guess` sans qu'aucune donnée ne dise lequel est juste (10 § 7.2). `tierGraceMs` et `preloadLeadMs` restent figés par partie dans `game.tier_grace_ms` et `game.preload_lead_ms` : le rejeu relit la colonne, jamais `PlatformLimits`.

---

## 4. La fonction pure unique : palier retenu et points

Une seule fonction, écrite par 80, rend **ensemble** le palier retenu et les points. 70 l'appelle dans la transaction de verrouillage, le rejeu l'appelle à l'identique. Deux formules — le palier choisi par 70, le bonus calculé par 80 — finiraient par désigner deux instants différents (10 § 7.5 : « le bonus se calcule sur la même valeur corrigée »).

### 4.1 Noms exacts

```php
namespace App\Support\Scoring;   // répertoire nouveau

final class ScoringRules
{
    public const int VERSION = 1;
    /** @var list<string> ordre normatif, épinglé par le test d'empreinte */
    public const array TIE_BREAK_CHAIN = ['score_desc', 'correct_answers_desc', 'total_answer_time_ms_asc', 'tier_finds_desc'];
    /** @throws UnsupportedScoringVersion */
    public static function assertSupported(int $version): void;
    public static function speedBonusMaxPercent(int $framesPerRound, int $version = self::VERSION): int;
    /** Text → 1 ; Choice → $difficulty->choicesOpenTierIndex($n) ; Choice en Expert → \LogicException. SEULE implémentation. */
    public static function floorTierIndex(\App\Enums\GuessSource $source, \App\Enums\InputDifficulty $difficulty, int $framesPerRound, int $version = self::VERSION): int;
    public static function isScoreless(\App\Settings\RoomSettings $settings): bool;
    /** J2 */ public static function waitingPays(array $tierPoints, int $framesPerRound): bool;
}

final class UnsupportedScoringVersion extends \DomainException {}

/** Fonction pure : aucune E/S, aucune horloge, aucun aléa. */
final class ScoreCalculator
{
    public static function score(int $answeredAtMs, \App\ValueObjects\Scoring\TierSchedule $tiers, int $tierGraceMs,
        bool $speedBonus, int $speedBonusMaxPercent, int $scoringVersion, int $floorTierIndex = 1): \App\ValueObjects\Scoring\TierScore;

    /** Seul point d'entrée des appelants (70, rejeu) : résout toutes les entrées depuis la partie figée. */
    public static function forGuess(\App\Models\Game $game, \App\ValueObjects\Scoring\TierSchedule $tiers,
        int $answeredAtMs, \App\Enums\GuessSource $source): \App\ValueObjects\Scoring\TierScore;
}

/** Chaîne de départage, fonction pure. */
final class Ranking
{
    /**
     * @param  list<\App\ValueObjects\Scoring\PlayerTally>  $tallies
     * @return list<\App\ValueObjects\Scoring\Standing>   ordre d'affichage normatif (§ 8.2)
     */
    public static function rank(array $tallies, int $framesPerRound, int $scoringVersion): array;
}

/** Côté lecture : totaux depuis la base et charges utiles en données (formes aux § 9 et § 11). */
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
```

`LeaderboardPayload`, `RoundFinderPayload`, `SeatScorePayload` et `PodiumPayload` sont des `@phpstan-type` déclarés sur `Scoreboard` (contrat C13 § 2.1) ; leurs formes figurent aux § 9.2, § 9.3, § 9.4 et § 11.2. Un `array` nu ne typerait rien au niveau 7 de PHPStan, et une clé absente passerait en silence (`CLAUDE.md` §5).

`forGuess()` résout : `tierGraceMs = $game->tier_grace_ms` ; `speedBonus = $game->settings_snapshot->speedBonus` ; `pct = ScoringRules::speedBonusMaxPercent($game->frames_per_round, $game->scoring_version)` ; `floor = ScoringRules::floorTierIndex($source, $game->input_difficulty, $game->frames_per_round, $game->scoring_version)`. Garde interne : `$tiers->count() === $game->frames_per_round`, sinon `\LogicException`.

Objets valeur, espace `App\ValueObjects\Scoring` (répertoire nouveau), tous `final readonly` :

- `TierWindow(int $tierIndex, int $startsAtOffsetMs, int $durationMs, int $points)` : `fromRoundTier(RoundTier)`, `contains(int $offsetMs)` sur `[offset, offset + duration)`, `toArray()`. **Seule formule de fenêtre** : `RoundTier::containsOffsetMs()` (existant) est modifié pour lui déléguer.
- `TierSchedule(list<TierWindow> $tiers)` : indices `1..N` contigus, `offset₁ = 0`, `offsetᵢ₊₁ = offsetᵢ + durationᵢ`, durées > 0, sinon `\InvalidArgumentException`. `fromRound(Round)` lit `round_tier` trié par `tier_index` — **seule source en production** ; `fromSettings(RoomSettings)` pour fabriques, tests et parité client. `count()`, `durationMs()` (= `D` en ms), `tier(int)`, `containing(int $offsetMs)` (`\InvalidArgumentException` hors `[0, D)`).
- `TierScore(int $tierIndex, int $pointsTier, int $pointsBonus, int $pointsTotal)` : `fromGuess(Guess)`, `equals()`, `toArray()`.
- `PlayerTally` et `Standing` : § 8.

### 4.2 Algorithme, version 1

1. `corrected = max(0, answeredAtMs − tierGraceMs)` — le palier retenu est celui de l'image affichée à l'instant serveur de réception, corrigé de la grâce de frontière (10 § 7.5, 00 § Le jeu en une manche).
2. `selected = tiers->containing(corrected)`. Si `selected.tierIndex < floorTierIndex`, alors `selected = tiers->tier(floorTierIndex)` (plancher du QCM, § 4.3).
3. `P = selected.points`, `d = selected.durationMs`, `t = max(0, corrected − selected.startsAtOffsetMs)`. Toujours `0 ≤ t ≤ d − 1`.
4. `pointsTier = P`.
5. `pointsBonus` selon la formule du § 3.2.
6. `pointsTotal = pointsTier + pointsBonus`.

Propriétés garanties et testées : pureté et déterminisme (mêmes entrées, même sortie) ; aucune valeur client dans la signature (L3) ; texte libre et clic QCM se notent à l'identique hors plancher ; B_max vaut 50, 50, 33 et 25 % ; la propriété du § 3.3.

### 4.3 Plancher du QCM

`ScoringRules::floorTierIndex()` est la **seule** implémentation du plancher (R-18 ; `AnswerRules::floorTierIndex()` n'existe pas). Texte libre : 1, donc sans effet. Clic QCM : `InputDifficulty::choicesOpenTierIndex(N)`, soit 1 en Facile (sans effet) et `N` en Normal. Clic en Expert : `\LogicException`, puisque 70 refuse toute route QCM en Expert avant d'appeler 80.

Pourquoi : en Normal, les quatre propositions n'apparaissent qu'à `T_N`. Un clic reçu dans les `tier_grace_ms` qui suivent `T_N` a un instant corrigé dans le palier `N − 1` ; sans plancher, voir les propositions achèterait le palier précédent, mieux payé. La grâce existe pour absorber le trajet réseau d'une réponse composée **avant** la frontière ; un clic QCM ne peut pas l'avoir été. Le clic est donc crédité au palier `N` avec `t = 0`, bonus maximal. Une réponse en texte libre reçue au même instant garde, elle, le bénéfice de la grâce. Un clic reçu **avant** l'ouverture du QCM est refusé par 70 (409 `closed`), sans jamais atteindre 80 (C10).

### 4.4 Préconditions

- `0 ≤ answeredAtMs < tiers->durationMs() + tierGraceMs`, sinon `\InvalidArgumentException`. C'est la fenêtre d'acceptation de C10, dont la borne haute suit `round.ended_at` en cas de fin anticipée (R-20) ; `corrected` est alors toujours dans `[0, D)`.
- `scoringVersion` inconnue : `UnsupportedScoringVersion` (`assertSupported()` est appelé en tête de `score()`).
- `floorTierIndex ∉ [1, N]` ou `speedBonusMaxPercent ∉ [0, FULL_PERCENT]` ou `tierGraceMs < 0` : `\InvalidArgumentException`.

### 4.5 Exemples chiffrés, au réglage par défaut

`N = 3`, `D = 30 s` en trois paliers de 10 s (300 / 200 / 100), bonus actif, B_max = 50 %, `tier_grace_ms` = 300.

| `answered_at_ms` reçu | Instant corrigé | Palier | `t` | `points_tier` | `points_bonus` | `points_total` |
|---|---|---|---|---|---|---|
| 150 (clic en Facile) | 0 | 1 | 0 | 300 | 150 | 450 |
| 2 000 | 1 700 | 1 | 1 700 | 300 | 124 | 424 |
| 9 000 | 8 700 | 1 | 8 700 | 300 | 19 | 319 |
| 10 299 | 9 999 | 1 | 9 999 | 300 | 0 | 300 |
| 10 300 | 10 000 | 2 | 0 | 200 | 100 | 300 |
| 10 301 | 10 001 | 2 | 1 | 200 | 99 | 299 |
| 20 150, texte, Normal | 19 850 | 2 | 9 850 | 200 | 1 | 201 |
| 20 150, clic QCM, Normal | 19 850 → plancher | 3 | 0 | 100 | 50 | 150 |
| 22 000 | 21 700 | 3 | 1 700 | 100 | 41 | 141 |

Les lignes 10 299 et 10 300 sont l'égalité exacte du § 3.3 à `N = 3`. Les lignes 2 000, 9 000 et 22 000 sont l'exemple de 00 § Scoring (« à 2 s, palier 300 + bonus fort ; à 9 s, 300 + bonus faible ; à 22 s, 100 + bonus »), désormais chiffré.

### 4.6 Texte libre et clic QCM

Hors plancher, une réponse en texte libre et un clic QCM se notent **exactement** de la même façon : aucune spec ne module les points selon la source, et un malus exigerait un réglage que `RoomSettings` n'a pas. Propriété connue, acceptée et non corrigée par le score : en Facile, le QCM est ouvert dès `T₁`, et un clic au hasard à `t = 0` a une espérance de 25 % × 450 au réglage par défaut. Le QCM étant à essai unique et définitif (`questions-ouvertes.md` § Déjà tranché, règle appliquée par 70), c'est un vrai pari, et Facile est le mode d'accueil.

---

## 5. Écriture au verrouillage et non-rétroactivité

- 70 appelle `ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $answeredAtMs, $source)` **une seule fois** par bonne réponse, à l'étape 3 de `LockGuess`, sous le verrou `round`, après revérification de la recevabilité et **avant** l'`INSERT guess` (C10).
- `answeredAtMs` est calculé une fois, par `RoundClock::offsetMs($round, ReceptionInstant::of($request))`, à l'entrée de la requête et avant tout verrou (C7, C10). Aucune valeur mesurée ou déclarée par le client n'y entre (L3).
- Les quatre sorties sont écrites **telles quelles** dans `guess.tier_index`, `points_tier`, `points_bonus` et `points_total`. Aucune autre écriture de ces colonnes n'existe dans `app/` (test d'architecture, § 16), et **aucun recalcul n'a jamais lieu** : un score attribué n'est jamais recalculé (`CLAUDE.md` §2, décision 13).
- 70 renvoie `TierScore::toArray()` plus `lockRank` **au seul siège**, dans la réponse HTTP 200 de sa soumission (C10).

Conséquences, toutes écrites pour ne pas être redécouvertes :

| Situation | Effet sur le score | Pourquoi |
|---|---|---|
| Reconnexion | Aucune manche close ne rapporte de points rétroactifs (00 § Cycle de vie) | Seul `LockGuess` écrit, et seulement dans la fenêtre d'acceptation. |
| Retardataire | Score initial 0, aucune ligne avant `first_round_number` | Il n'a de ligne `round_player` qu'à partir de sa manche d'entrée (10 § 7.6). |
| Réglage, titre ou alias modifié, film publié ou dépublié après le verrouillage | Aucun | Barème figé (§ 2.2) ; validation jamais rétroactive (décision 13). |
| Substitution de variante (C8) | Aucun | Durées et points de `round_tier` inchangés par construction (10 § 7.4). |
| Manche annulée après des verrouillages | Points exclus de toute lecture ; lignes `guess` conservées | Invariant L1 : les `guess` restent la trace de l'incident, jamais supprimées ni réécrites (10 A13). |
| « Manche suivante » de l'hôte pendant la révélation | Aucun | Elle raccourcit `R`, jamais `D` (00 § Cycle de vie). |
| Expulsion ou départ | Points conservés, siège toujours classé | L'expulsion est un départ forcé (n° 45) ; § 8.5. |

---

## 6. Version de la règle et rejeu

### 6.1 `ScoringRules::VERSION` et `game.scoring_version`

`ScoringRules::VERSION = 1` est écrite au lancement dans `game.scoring_version` par `OpenGame` (C6). Elle **remplace** `GameFactory::SCORING_VERSION`, supprimée : la fabrique lit `ScoringRules::VERSION`.

**Elle s'incrémente à tout changement de** (contrat C13 § 4.7, E10-38) :
- la table B_max(N) ;
- la forme ou l'arrondi du bonus ;
- la règle de sélection du palier, application de la grâce et plancher du QCM compris ;
- la chaîne de départage ;
- la définition d'un agrégat figé ou de `rounds_completed` ;
- le **défaut** de `tierGraceMs` ou de `preloadLeadMs`, même si ces valeurs sont figées par partie dans `game.tier_grace_ms` et `game.preload_lead_ms` et relues telles quelles au rejeu (10 § 7.2 et § 6.1).

Elle **ne s'incrémente pas** pour les faits marquants du podium (`highlights`, § 11.5), qui ne sont pas des scores, ni pour un texte, un affichage ou une valeur d'`EngineConstants` (C7 § 5).

### 6.2 Branches conservées

Toute méthode de `ScoringRules` qui reçoit `$version` branche sur elle. **La branche d'une version publiée n'est jamais modifiée** : une nouvelle règle est une nouvelle branche, et le test d'empreinte de chaque version reste vert. Une version inconnue lève `UnsupportedScoringVersion`.

### 6.3 Rejeu

```php
final class ScoreReplayer
{
    public static function replay(\App\Models\Guess $guess): \App\ValueObjects\Scoring\TierScore;   // J1
    /** @return list<array{sequenceIndex: int, publicId: string, stored: TierScore, replayed: TierScore}> */
    public static function mismatches(\App\Models\Game $game): array;                                // J2
}
```

- `replay()` = `ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $guess->answered_at_ms, $guess->source)`, en ne lisant que des faits figés : `answered_at_ms`, `round_tier`, `game.tier_grace_ms`, `settings_snapshot->speedBonus`, `frames_per_round`, `input_difficulty`, `guess.source`, `scoring_version` (10 § 7.5, E10-48). Il doit redonner **exactement** `TierScore::fromGuess($guess)`. Il est dû au J1, parce que le test de 10 § 7.5 l'exige.
- `mismatches()` (J2) rejoue chaque `guess` de la partie, **manches annulées comprises** — le journal doit être cohérent même là où les points ne comptent pas —, et rend les seuls écarts, triés par `sequence_index` puis `lock_rank`. Une liste vide dit « journal cohérent ». Consommateur : l'écran « inspecter une partie » de 20 (J2), dont la mise en forme appartient à 20.

### 6.4 Empreinte de la version 1

`ScoringRulesTest` épingle la table B_max `{2:50, 3:50, 4:33, 5:25}`, `TIE_BREAK_CHAIN` et des échantillons d'arrondi (les lignes du § 4.5 et l'exemple « `P = 100`, B_max = 50 %, `d = 5 000`, `t = 1 700` donne 33 »). Une modification qui change l'un d'eux fait échouer l'empreinte : elle ne passe qu'avec une incrémentation de `VERSION` et une nouvelle branche.

---

## 7. Trois lectures du score, et ce qui devient public quand

### 7.1 `ScoreScope`

```php
namespace App\Enums;
enum ScoreScope   // énumération pure, non persistée
{
    case Own;          // manches running, revealing, completed — lue par le siège qui a répondu, et par lui seul
    case Publishable;  // manches revealing, completed — tout score montré à un AUTRE siège
    case Settled;      // manches completed — agrégats figés seulement
    /** @return list<\App\Enums\RoundStatus> */
    public function roundStatuses(): array;
}
```

| Portée | Manches | Qui la lit |
|---|---|---|
| `Own` | `running`, `revealing`, `completed` | Le siège lui-même, en ciblé (`SelfState.ownScore`, C7). |
| `Publishable` | `revealing`, `completed` | Tout ce qu'un autre siège voit : classement, `finders`, resynchronisation d'un tiers. |
| `Settled` | `completed` | `FinalizeGame` (agrégats) et `Scoreboard::podium()` (récapitulatif et faits marquants, après le gel, § 11.1) ; aucun autre lecteur. |

Les trois excluent `cancelled` (invariant L1, 10 § 1.8). **Les points et le palier d'une manche deviennent publics dès `round.status = revealing`, jamais avant** (E10-52, n° 65). La révélation ne « fige » aucun score — les points sont figés au verrouillage — : elle clôt la saisie (60, 70) et rend publiable (A-03).

Pourquoi trois lectures et non L1 seul : `Guess::counted()` n'exclut que `cancelled` et compte donc une manche `running`. Un classement recalculé pendant une manche et montré à un tiers publierait les points d'un joueur verrouillé, donc son palier et sa vitesse, alors que les onze autres cherchent encore (E10-14, n° 67).

### 7.2 Ce qui transite, et quand

| Donnée | Pendant la manche `k` (`running`, fermeture comprise) | Dès `revealing` de `k` | Après le gel |
|---|---|---|---|
| `TierScore` du siège pour `k` | Ciblé : réponse HTTP de soumission (70), `SeatInputView.locked` à la resynchronisation | idem | — |
| `ownScore` | Ciblé, portée `Own`, manche `k` comprise | idem | — |
| « a trouvé » d'autrui | Diffusé : `publicId` et `lockRank` seulement (`player.locked`, C7) | — | — |
| Points et palier d'autrui pour `k` | **Jamais** | Diffusés : `round.revealed.finders` et `.leaderboard` | Dans `Podium.recap[].finders` |
| `Leaderboard` | Figé à la dernière manche révélée (`k − 1`) dans toute resynchronisation | Diffusé, `k` compris | — |
| `Podium` | — | — | Diffusé par `game.ended`, rejoué à l'identique par toute resynchronisation jusqu'à l'archivage |

**Aucun bloc de 80 ne transite avant la révélation**, à trois exceptions près : `TierScore` et `SeatScore`, ciblés vers leur propriétaire (règle 3), et `TierWindow`, public, diffusé dans `RoundTimeline.tiers` dès `round.scheduled` (barème figé au lancement, connu du salon, C13 § 3, C16 § 3) ; `tierValueAt` (§ 14) en dépend. Aucun bloc ne porte `game.id`, `game_player.id`, `player.id`, `round.id`, `guess.id`, `movie_id`, `answer_key_*`, `submitted_normalized`, `match_kind` ni `source` ; les sièges sont désignés par `player.public_id`, les manches par `round_number` (10 § 1.1). Le seul bloc qui porte des titres est `Podium.recap`, après le gel.

### 7.3 Le joueur verrouillé voit ses points tout de suite

Ses trois parts lui reviennent immédiatement par la réponse HTTP de sa soumission, puis par `SelfState.input.locked` et `SelfState.ownScore` à toute resynchronisation (00 § Écran du joueur verrouillé, C7, C10). Il n'existe **aucun** événement de résultat ciblé au J1, et en particulier aucun `seat.locked` (R-21) : la réponse HTTP a déjà un destinataire unique.

### 7.4 Mise en œuvre des lectures

- Toute lecture de score passe par `Scoreboard`. Aucune autre agrégation de `guess` n'existe dans `app/` ; les quatre compteurs de 40 (J2) lisent les agrégats figés, pas `guess`.
- Chaque lecture emploie **une** sous-requête de manches : `round.game_id = ? AND round.status IN (ScoreScope::roundStatuses())`, servie par `round_game_status_idx`. Les agrégats sont groupés par `player_id` (et par `tier_index` pour les trouvailles), sans colonne non agrégée, donc sûrs sous `ONLY_FULL_GROUP_BY` (10 § 1.4), puis repliés en PHP en `PlayerTally`. Les scopes `Guess::inScoreScope(Builder, ScoreScope)` et `RoundPlayer::inScoreScope(Builder, ScoreScope)` (nouveaux, même patron que `Guess::counted()`, conservé) portent la même liste de statuts pour les autres lecteurs ; cette liste ne vit que dans `ScoreScope::roundStatuses()`. `ScoreScope::Own` y est équivalent à `counted()`, une manche `pending` ne portant jamais de `guess`.
- Volume : au plus `roomSeats() × min(M + drawSubstituteMargin(), |vivier|)` lignes `guess` par partie — 156 au réglage par défaut (`roomSeats()` = 12, `M` = 10 plus une marge de 3), 396 au plafond `MAX_ROUNDS_COUNT` = 30 (12 × 33) —, et `game_player` n'est pas borné à 12, les partis restant classés (E10-44). **Aucun cache au J1** : un calcul par révélation, un au podium, plus les resynchronisations ; un cache serait une seconde source de vérité pour un gain nul.
- `Guess::toArray()` ne sert jamais à publier un score : les colonnes sont `#[Hidden]` et le restent. Le docblock de `Guess` est corrigé : l.25 devient « au plus `roomSeats()` × min(M + `drawSubstituteMargin()`, |vivier|) lignes » ; l.39, « la fraction de bonus » devient « le pourcentage B_max(N) » (D22 du 23/09) ; l.55-56, « `makeVisible()` après `reveal_ends_at` » devient « publiable dès `round.status = revealing`, via `Scoreboard`, jamais par `toArray()` ».
- Le docblock de `Guess::counted()` (l.131-135), qui fait passer « toute agrégation » par ce scope, est réécrit : « Invariant L1 seul. Aucune lecture de score ne l'appelle directement : `Scoreboard` lit sous `ScoreScope` — `Own` pour le siège, `Publishable` pour tout autre siège, `Settled` au gel et au podium —, et les quatre compteurs lisent les agrégats figés de `game_player`. » Celui de `GamePlayer` (l.26-27) devient : « Le score vivant n'est pas ici : il se lit par `Scoreboard` sous `ScoreScope::Own` ou `Publishable` ; les cinq colonnes nullables sont écrites par `FinalizeGame` seul. » Laissés tels quels, ils prescriraient à un développeur d'agréger sous `counted()`, donc de publier les points d'un joueur verrouillé pendant la manche (E10-14, n° 67).

---

## 8. Chaîne de départage et rang

### 8.1 La chaîne

`Ranking::rank(list<PlayerTally> $tallies, int $framesPerRound, int $scoringVersion): list<Standing>` ordonne, dans cet ordre (`ScoringRules::TIE_BREAK_CHAIN`, 00 § Cycle de vie) :

1. `score` décroissant ;
2. `correctAnswers` décroissant ;
3. `totalAnswerTimeMs` croissant, où `totalAnswerTimeMs = Σ guess.answered_at_ms` **brut** de la portée ;
4. `findsByTier[1]`, puis `[2]`, …, jusqu'à `[N − 1]`, chacun décroissant ; `tier_index` inclut le plancher du QCM ;
5. place partagée.

```php
final readonly class PlayerTally {   // jamais sérialisé tel quel
    /** @param array<int, int> $findsByTier clés 1..N */
    public function __construct(public int $gamePlayerId /* interne : tri stable seulement */, public string $publicId,
        public \App\Enums\GamePlayerStatus $status, public ?int $firstRoundNumber, public int $roundsPlayed,
        public int $correctAnswers, public int $score, public int $totalAnswerTimeMs, public array $findsByTier) {}
}
final readonly class Standing { public function __construct(public PlayerTally $tally, public ?int $rank, public bool $shared) {} }
```

### 8.2 Rang de compétition, place partagée, siège sans rang

- **Rang de compétition** : 1, 2, 2, 4. Deux sièges égaux sur toute la chaîne partagent le rang (`shared = true`), et le rang suivant saute d'autant.
- **Seuls les sièges ayant joué au moins une manche reçoivent un rang** (`roundsPlayed ≥ 1`). Les autres — parti avant sa première manche, retardataire pas encore entré, partie interrompue avant toute manche close — ont `rank = null` et sont placés après. Un rang partagé de 1 pour tous afficherait « 1ᵉʳ » dans l'historique d'une partie que personne n'a jouée.
- **En solo, `rank = null` partout.** `Ranking::rank()` ignore le mode de la partie : il ne classe que des tallies, et sa signature ne reçoit pas `game.mode`. Ce sont donc `Scoreboard::leaderboard()` et `FinalizeGame` qui forcent `rank = null` et `shared = false` sur chaque `Standing` quand `game.mode = solo` ; `Scoreboard::podium()` lit `final_rank`, déjà nul (C13 § 4.5).
- **Forme d'un rang nul à l'écran** : le glyphe « — », décoratif et neutre en langue (`aria-hidden="true"`), doublé du texte accessible `game.leaderboard.unranked` (« Sans rang » / « Unranked », § 15.2) en `sr-only`. C'est la seule forme d'un rang nul, en solo comme pour un siège sans manche jouée ; jamais « — » écrit en dur sans son texte accessible (règle 4, principe 8).
- **Ordre d'affichage** : rang croissant, puis `game_player.id` croissant — interne, jamais exposé —, les sièges sans rang en dernier.

### 8.3 Jamais la graine

**Aucune égalité de score n'est jamais tranchée par la graine**, ni par `hash_hmac`, `random_*` ou `shuffle` (n° 66, E10-40) : le départage est déterministe et jamais aléatoire (00 § Cycle de vie). Deux parties aux graines différentes et aux totaux identiques donnent le même classement (test). Le « départage par la graine » de 00 § Le jeu en une manche est celui des **variantes**, propriété de 30. Deux réceptions dans la même milliseconde sont ordonnées par l'arrivée en file (`lock_rank`, 70), mais la chaîne lit `answered_at_ms`, jamais `lock_rank`.

### 8.4 Pourquoi cet ordre

- **Les bonnes réponses avant le temps** : sinon un joueur ayant trouvé deux films vite passerait devant un joueur en ayant trouvé huit, défaut qui frappe aussi bien le mode sans score que les égalités ordinaires (300 + 100 contre 200 + 200, 00 § Cycle de vie).
- **Le temps brut, pas le temps corrigé** : tous les sièges sont mesurés sur la même horloge de réception serveur. La correction de grâce retire la même constante à chaque bonne réponse — `Σ corrigé = Σ brut − tier_grace_ms × bonnes réponses`, et les bonnes réponses sont déjà égales à ce rang de la chaîne —, sauf sous `tier_grace_ms`, où le plancher à 0 créerait des égalités artificielles.
- **Les trouvailles de 1 à `N − 1`, pas jusqu'à `N`** : la somme des trouvailles sur les `N` paliers vaut `correctAnswers`, déjà égal ; la dernière composante s'en déduit.
- **Pas `lock_rank`** : c'est l'ordre d'acquisition du verrou, qui peut s'inverser avec `answered_at_ms` à quelques millisecondes près (C10).

### 8.5 Sièges particuliers

- **Partis et expulsés** restent classés avec leurs points, marqués `left` ou `kicked` (00 § Cycle de vie, n° 45, D15 du 23/09).
- **Retardataire** : classé sur ses seules manches ; `firstRoundNumber` porte sa manche d'entrée, affichée par `game.leaderboard.late_joiner` quand elle dépasse 1.
- **Un seul joueur connecté en multijoueur** : l'affichage `game.round.lone_player` (60) est **cosmétique**. `game.mode` reste `multiplayer`, le siège restant est classé et la partie compte dans ses compteurs (A-04).
- **Aucun plafond de lignes** (n° 67) : toutes les lignes `game_player` sont classées. La capacité de 12 borne les sièges simultanés, pas le classement, que les partis et les retardataires peuvent allonger. Le repli visuel des lignes « parti » appartient à 90.

---

## 9. Classement intermédiaire

### 9.1 Quand, et par qui

- **À la révélation** : `RevealRound` (60), sous le verrou `round`, passe la manche en `revealing` puis appelle `Scoreboard::roundFinders($round)` et `Scoreboard::leaderboard($game, $round)`, dont les blocs partent dans `round.revealed` après commit (C7). Le calcul se fait donc une fois par manche révélée, en portée `Publishable`, manche révélée comprise.
- **À la resynchronisation** : `GameStateBuilder` (60) appelle `Scoreboard::leaderboard($game, $revealing)`, où `$revealing` est la manche en `revealing` s'il y en a une, `null` sinon. Pendant une manche `running`, le classement d'un tiers est donc **figé à la dernière manche révélée** (n° 67) : jamais les points d'autrui de la manche en cours.
- `Scoreboard::leaderboard()` lève `\LogicException` si `$revealedRound` n'est ni `revealing` ni `completed`. `Scoreboard::roundFinders()` lève `\LogicException` pour une manche hors `{revealing, completed}` — donc aussi pour une manche annulée.

### 9.2 Forme du bloc `Leaderboard`

Diffusé au salon à partir du passage en `revealing` (`round.revealed`) et présent dans la resynchronisation de tout siège (C7). Forme figée par le contrat C13 § 3 :

```
{
  scoreless: bool,                // ScoringRules::isScoreless(game.settings_snapshot)
  roundNumber: int | null,        // manche révélée à l'origine du calcul ; null hors révélation
  rows: [ {
    publicId: string,
    rank: int | null,             // null : solo, ou roundsPlayed = 0
    rankShared: bool,
    score: int,                   // portée Publishable
    correctAnswers: int,
    roundsPlayed: int,
    totalAnswerTimeMs: int,
    roundDelta: int,              // Σ points_total du siège sur la manche révélée, 0 sinon
    status: "playing" | "left" | "kicked",
    firstRoundNumber: int | null
  } ]                             // TOUTES les lignes game_player, sans plafond, dans l'ordre du § 8.2
}
```

Le bloc ne porte **ni pseudo ni avatar** : le client les lit dans `seats: SeatView[]` de C7, identité gelée `display_*`. Les entiers restent des entiers ; le client formate (05 § Nombres, dates et durées).

### 9.3 Forme du bloc `RoundFinder[]`

Diffusé, uniquement pour une manche `revealing` ou `completed`, trié par `lockRank` :

```
[ { publicId: string, lockRank: int, tierIndex: int, answeredAtMs: int, pointsTier: int, pointsBonus: int, pointsTotal: int } ]
```

La présentation du détail `pointsTier` / `pointsBonus` à l'écran appartient à 90 ; les données sont fournies.

### 9.4 Forme du bloc `SeatScore`

Ciblé, jamais diffusé : resynchronisation HTTP du seul siège, où il alimente `SelfState.ownScore` de C7 (contrat C13 § 3) :

```
{ ownScore: int }                 // portée Own, manche en cours comprise
```

Le détail de la manche en cours du siège (palier, trois parts) n'y figure pas : il est porté par `SeatInputView.locked` de C10 (R-26), qui reprend `TierScore`.

### 9.5 Changement de langue

Le classement ne contient aucune chaîne : il bascule immédiatement au changement de langue, et aucun score n'est recalculé (05 § Changement de langue). Seule la révélation déjà affichée reste figée, ce qui n'est pas l'affaire de 80.

---

## 10. Fin de partie : l'action de gel

### 10.1 Seule écrivaine

```php
namespace App\Actions\Game;   // répertoire nouveau
final readonly class FinalizeGame
{
    /** true si CET appel a gelé la partie, false si elle l'était déjà (aucune écriture). */
    public function handle(\App\Models\Game $game, \App\Enums\GameStatus $outcome, \Carbon\CarbonImmutable $endedAt): bool;
    /** min($now, max(game.started_at, game.paused_at, round.started_at + round.duration_ms [status ≠ pending], round.reveal_ends_at, round.cancelled_at)) */
    public static function lastKnownActivity(\App\Models\Game $game, \Carbon\CarbonImmutable $now): \Carbon\CarbonImmutable;
}

namespace App\Events\Game;    // répertoire nouveau
final class GameFinalized implements \Illuminate\Contracts\Events\ShouldDispatchAfterCommit   // jamais diffusé
{
    use \Illuminate\Foundation\Events\Dispatchable;
    public function __construct(public readonly int $gameId, public readonly \App\Enums\GameStatus $outcome) {}
}
```

`FinalizeGame` est, dans `app/`, la **seule écrivaine** de `game.ended_at`, du statut final de la partie (`completed` ou `interrupted`), de la valeur finale de `game.rounds_completed` et des cinq agrégats de `game_player` (E10-36, E10-43, C17). Raison : les agrégats sont nullables pour que le gel soit un **événement vérifiable** plutôt qu'un état qu'on oublie de déclencher (10 § 7.3), et `ended_at` pilote la fenêtre de 12 mois et la purge (10 § 7.2, § 11.1) — un second écrivain pourrait poser `ended_at` sans agrégats, ou le poser à « maintenant » et prolonger une conservation annoncée publiquement.

### 10.2 Déroulé

1. `$outcome ∉ {Completed, Interrupted}` : `\InvalidArgumentException`, avant toute écriture.
2. **Une** transaction. La ligne `game` est relue en `lockForUpdate`. Si `ended_at IS NOT NULL`, l'appel rend `false` sans rien écrire : il est **idempotent**, et le premier appelant gagne (une fin normale et une clôture après pause concurrentes ne gèlent qu'une fois).
3. Clôture d'office des manches non terminales (§ 10.3), sous verrou `round`, **après** le verrou `game`.
4. Lecture `Scoreboard::tallies($game, ScoreScope::Settled)`, puis `Ranking::rank()`.
5. Écriture, pour chaque `game_player`, des cinq agrégats du § 10.4.
6. Écriture de `game.status = $outcome`, `game.ended_at = $endedAt`, `game.rounds_completed = COUNT(round WHERE status = completed)`.
7. `GameFinalized::dispatch($game->id, $outcome)`, livré **après commit** ; rendre `true`.

Le gel n'écrit rien sur `room`, ni sur `paused_at` ou `total_paused_ms`, ni sur `game_player.status` : ce sont les données de 50 et de 60. Il n'écrit aucune colonne figée de `game` (C6).

### 10.3 Clôture d'office des manches

- Une manche `revealing` passe en `completed`.
- Une manche `running` passe en `completed` si `started_at + duration_ms ≤ $endedAt`, avec `round.ended_at` posé, s'il est nul, à `started_at + duration_ms` — l'instant théorique de clôture à `D` (C7 § 4.6). Sinon, `\LogicException` : l'horloge d'une manche ne se met jamais en pause (10 § 7.4), et une partie ne se gèle pas au milieu d'une manche dont la durée court encore.
- Les manches `pending`, de réserve ou programmées, restent intactes : elles n'ont aucune ligne `round_player` et ne sont jamais montrées (§ 11.4).
- Les manches `cancelled` restent annulées.

### 10.4 Les agrégats figés, définition exacte

Filtre **unique** : portée `Settled`, c'est-à-dire les manches `completed` après la clôture d'office. Un seul filtre pour les quatre premiers agrégats, sinon une manche restée `running` au moment du gel compterait une bonne réponse sans compter la manche jouée, et le taux de réussite de 40 pourrait dépasser 100 % (n° 67).

| Colonne | Définition | En solo | Garanties |
|---|---|---|---|
| `game_player.rounds_played` | Nombre de lignes `round_player` du siège sur les manches `completed` | Les manches dont la ligne `round_player` du siège porte `input_state` = `revealed` ou `skipped` comptent (D18 du 23/09) ; la manche elle-même est `completed` (10 § 7.10) — `revealed` et `skipped` sont des cas de `RoundPlayerInputState`, jamais de `RoundStatus` | Jamais `M` ; un retardataire ne compte que ses manches ; **une manche `cancelled` n'y entre jamais** (confirmation demandée par 10 § 15, E10-67) |
| `game_player.correct_answers` | Nombre de lignes `guess` du siège sur les manches `completed` | `revealed` et `skipped` n'en produisent jamais (10 § 7.10) | `correct_answers ≤ rounds_played`, testé |
| `game_player.final_score` | `Σ points_total` | idem | 0 en mode sans score, jamais nul |
| `game_player.total_answer_time_ms` | `Σ answered_at_ms` brut | idem | 0 sans bonne réponse |
| `game_player.final_rank` | Rang de `Ranking::rank()` sur les tallies `Settled` | NULL | NULL si `rounds_played = 0` ; `unsignedSmallInteger` (E10-04) |
| `game.rounds_completed` | `COUNT(round WHERE status = completed)` | idem | Le `k` affiché ; maintenu par 60 à chaque passage en `completed`, recalculé et écrasé ici |

Après le gel, les quatre premiers agrégats sont **non nuls**, zéro compris. « Partie jouée » au sens des compteurs = `rounds_played ≥ 1` (10 § 7.3).

### 10.5 Appelants et instant de fin

| Appelant | `$outcome` | `$endedAt` |
|---|---|---|
| Fin normale : `EndReveal` de la dernière manche (60) | `Completed` | `reveal_ends_at` de la dernière manche (60 § 9.6), tel qu'éventuellement avancé par « Manche suivante » (60 § 5.4) : l'instant serveur de la transition de fin de la dernière révélation, jamais l'instant d'exécution du job. |
| Annulation d'une manche sans manche restante : `CancelRound` (60 § 15.2 ; vivier égal à `M` ou réserve épuisée, 00 § Réglages du salon, borne croisée 3) | `Completed` | `round.cancelled_at` de cette manche, instant de la transition d'annulation. |
| Clôture après pause : `InterruptPausedGame`, ou `ResumeGame` sur un battement tardif (60 § 14.2) | `Interrupted` | `paused_at + EngineConstants::pauseTimeoutMs()` (15 min au défaut de 60) — l'instant prévu, jamais l'instant d'exécution du job ni celui du battement : les deux chemins écrivent le même instant, et le premier gagne (§ 10.2). |
| Passer la dernière manche solo : `SkipSoloRound` (60 § 16.5) | `Completed` | `reveal_ends_at` de cette manche (= `min(now, started_at + D)`, posé par le geste) : aucune révélation n'a lieu, la manche est déjà `completed`, et la fin de partie coïncide avec sa clôture. |
| Relance d'un solo pendant une partie solo en cours : `StartSoloGame` (60 § 16.6) | `Interrupted` | L'instant `now` de la transaction de relance, **après** clôture de la manche `running` comme par « Passer la manche » : aucune manche `running` ne reste, donc aucune `\LogicException` du § 10.3. |
| Reprise d'une partie bloquée (60) | `Interrupted` | `FinalizeGame::lastKnownActivity($game, now)`. |
| Clôture forcée du périmètre `stale_game` (100, 10 § 11.1), et réparation d'une partie signalée par la sonde n° 1 de 10 § 11.3 | `Interrupted` | `FinalizeGame::lastKnownActivity($game, now)`. |

**Pourquoi la dernière activité connue, et pas « maintenant ».** `ended_at` pilote la fenêtre de 12 mois. Une partie bloquée depuis treize mois que l'on fermerait à `now` repartirait pour douze mois de conservation, et la durée publiée sur la page de confidentialité deviendrait fausse (E10-62, n° 67). `min($now, …)` protège du cas d'une fin de révélation programmée dans le futur.

Le **mécanisme** qui déclenche la reprise d'une partie bloquée (60) et l'ordonnancement de `stale_game` (100) ne sont pas décidés ici : seule l'action appelée est figée. Le drainage de déploiement ne bloque jamais le gel ; au contraire, `deploy:drain` attend que les parties en cours passent par lui (C17, C18-bis).

### 10.6 Partie interrompue, ou terminée avant `M` manches closes

Une partie interrompue est gelée comme une partie terminée : mêmes agrégats, même chaîne, même podium, avec `gameStatus = "interrupted"`, `roundsCompleted = k` et `roundsCount = M`, affichés « interrompue à la manche `k` sur `M` » (00 § Cycle de vie, `questions-ouvertes.md` § Déjà tranché, « Partie interrompue »). Elle compte comme partie jouée pour chaque siège dont `rounds_played ≥ 1`.

**Cas `k = 0`** — interrompue avant toute manche close : agrégats gelés à zéro, `rounds_played = 0` partout, **aucun rang**, et personne ne compte la partie comme jouée. Le podium « interrompue à la manche 0 sur `M` » est émis par `game.ended` comme tout podium (§ 10.7 ; en solo, livré par la prop `state.podium`, § 11.1) — des sièges peuvent être connectés —, et reste lisible à une reconnexion jusqu'à l'archivage. Ce cas ne naît jamais d'une pause, qui suppose une révélation achevée (C7 § 4.11) : il ne survient que par la reprise d'une partie bloquée, par `stale_game` ou par la relance d'un solo avant sa première manche (§ 10.5).

**Partie `completed` avec `k < M`** — une annulation non remplacée (vivier égal à `M`, réserve épuisée) fait finir la partie en `completed` avec moins de `M` manches closes, et `k = 0` si toutes les manches ont été annulées. Mêmes agrégats, même chaîne, `gameStatus = "completed"` ; `game.podium.completed` reçoit `:m = M`, et le récapitulatif porte une entrée `cancelled` par annulation non remplacée (§ 11.4), de sorte que l'en-tête et la liste comptent le même nombre de manches. À `k = 0`, aucun siège n'a de rang et personne ne compte la partie comme jouée.

### 10.7 `GameFinalized` et ses effets

`GameFinalized` est émis **après commit**, et seulement quand l'appel rend `true`. C'est l'**unique déclencheur** des effets de fin : l'émission de `game.ended` par l'écouteur de 60, le cache des compteurs de 40 (J2). Il ne ramène **pas** le salon au lobby : le salon reste `playing`, podium compris, jusqu'au « Rejouer » de l'hôte, que `ended_at` non nul rend possible (C6 R5, R-45, D32 du 23/09). Ses écouteurs sont idempotents et tolèrent une partie déjà purgée — une clôture `stale_game` est suivie de la purge dans la même passe.

### 10.8 Verrous et concurrence

L'ordre est **`game` puis `round`, jamais l'inverse**, dans l'ordre global room → player → game → round → round_player (C7 § 4.3, E10-51). La transaction de verrouillage de 70 ne verrouille jamais `game` : elle ne peut pas croiser le gel. Un appelant de 60 qui tient déjà un verrou `round` appelle `FinalizeGame` après son commit, ou a pris `game` en premier (§ 1.4). Deux appels concurrents ne gèlent qu'une fois (test `locks-timing`, § 16).

---

## 11. Podium et récapitulatif

### 11.1 Lecture et diffusion

`Scoreboard::podium(Game $game): array` lit **uniquement les agrégats figés** pour le classement, et lève `\LogicException` si `ended_at` est nul. `recap` et `highlights` se dérivent en lecture des `guess` et des `round` en portée `Settled`. Le bloc est diffusé au salon par `game.ended` après le commit du gel (C7, `{ podium: Podium }`, R-44), puis **rejoué à l'identique** dans toute resynchronisation jusqu'à l'archivage du salon ; en solo, il n'est livré que par la prop HTTP `state.podium` (C7 § 4.12).

### 11.2 Forme du bloc `Podium`

Forme figée par le contrat C13 § 3 :

```
{
  gameStatus: "completed" | "interrupted",
  mode: "multiplayer" | "solo",
  roundsCompleted: int,          // k (= game.rounds_completed)
  roundsCount: int,              // M
  framesPerRound: int,           // N
  scoreless: bool,
  endedAt: string,               // ISO-8601 UTC, millisecondes
  standings: [ {
    ...PlayerIdentity,           // PlayerIdentity::fromGamePlayer() (C5) : publicId, nickname (display_nickname gelé), masked, avatar
    status: "playing" | "left" | "kicked",
    firstRoundNumber: int | null,
    rank: int | null,            // = game_player.final_rank
    rankShared: bool,
    finalScore: int, correctAnswers: int, roundsPlayed: int, totalAnswerTimeMs: int
  } ],
  recap: [ {
    roundNumber: int,
    outcome: "completed" | "cancelled",
    titles: TitlePacket | null,  // null si cancelled ; TitlePacket = RevealMovie de C7
    foundCount: int,
    finders: RoundFinder[]
  } ],
  highlights: {                  // D25 du 23/09
    bestAnswer: { publicId: string, roundNumber: int, tierIndex: int, answeredAtMs: int, pointsTotal: int } | null,
    fastestFind: { publicId: string, roundNumber: int, answeredAtMs: int } | null,
    unfoundRoundNumbers: int[]
  }
}
```

Texte seul, **sans aucune URL d'image** (§ 11.4). `rankShared` se dérive à la lecture : vrai si un autre classement porte le même `final_rank` non nul.

### 11.3 Classement final avec avatars

`standings` embarque `PlayerIdentity::fromGamePlayer()` (C5, R-29) : pseudo et avatar **gelés** dans `game_player.display_*` pour la durée de la partie — un invité qui se connecte en cours de partie garde l'affichage du classement en direct, et le podium le montre tel qu'il était (00 § Cycle de vie, 10 § 7.3). L'avatar est `AvatarRef::toArray()` : prédéfini ou initiales au J1 ; la branche copie provider et le masquage appartiennent à 40 (J2). Ordre : celui du § 8.2. Chaque ligne porte `roundsPlayed`, le « nombre de manches jouées par chacun » de 00 § Déroulé d'une partie. Les avatars passent par `player-avatar.tsx` (C5), jamais par le pipeline des images de jeu.

### 11.4 Récapitulatif localisé, en texte seul

**Composition.** Pour chaque `round_number` porté par une manche `completed` ou `cancelled`, en ordre croissant :
- s'il existe une manche `completed` de ce numéro, une entrée `outcome = "completed"`, avec `titles` = `RevealMovie` de C7 (un titre par locale activée, `DisplayTitleResolver`), `foundCount = round.found_count` et `finders = Scoreboard::roundFinders($round)` ;
- sinon — annulation **non remplacée** —, **une** entrée `outcome = "cancelled"`, avec `titles = null`, `foundCount = 0` et `finders = []`.

Une manche annulée puis remplacée n'apparaît donc qu'une fois, sous son remplaçant, qui partage son numéro (10 § 7.4). **Jamais une manche `pending`** : les manches de réserve non jouées révéleraient une partie du tirage, que `draw_pool_size` et `draw_seed` cachent justement (10 § 7.2). Jamais le titre d'une manche annulée : elle n'a jamais été révélée, et `round.cancelled` ne porte ni motif ni titre (C7).

**Localisation.** `TitlePacket` porte le titre de **chaque** locale activée, avec son attribut `lang`, plus `originalTitle`, `originalTitleLatin`, `originalLanguage` et `year` (C7, R-24) : chaque client choisit le sien, et deux joueurs d'un même salon peuvent voir deux titres différents (00 § Langues, 05 § QCM « À la révélation et après »). Le récapitulatif le rend exactement comme l'écran de révélation rend `RevealMovie`, par le même assistant client (60, livré par L60-9, § 1.4) : titre retenu, titre original s'il diffère, année. Au changement de langue, il bascule immédiatement, les titres de toutes les locales étant déjà là.

**Texte seul** (Q80-1) : aucune vignette. Une image par manche exigerait de servir des octets après `reveal_ends_at`, ce que D14 du 23/09 exclut — les URL des paliers ouverts ne restent servables que jusqu'à la fin de la révélation (C8) — et que refuse le budget de service de 10 § 12 ; elle contredirait aussi le principe 12 (« aucun visuel téléchargeable ») ; l'affiche est exclue du schéma (10 § 3.1).

**Film dépublié, suspendu ou retiré après sa manche** : son titre reste au récapitulatif, en texte, sans image. C'est la vérité de la partie jouée, et aucun octet d'image n'est servi.

### 11.5 Faits marquants du podium (`highlights`, D25 du 23/09)

Trois faits marquants de toute la partie, dérivés en lecture des `guess` et `round` en portée `Settled` :

| Fait | Règle | Nul quand |
|---|---|---|
| `bestAnswer` — « meilleure réponse de la partie » | Tri `points_total` décroissant, puis `answered_at_ms` croissant, puis `round_number` croissant, puis `lock_rank` croissant ; premier élément | Aucune bonne réponse |
| `fastestFind` — « film le plus rapidement trouvé » | Tri `answered_at_ms` croissant, puis `round_number`, puis `lock_rank` | Aucune bonne réponse |
| `unfoundRoundNumbers` — « film que personne n'a eu » | Manches `completed` à `found_count = 0`, par `round_number` croissant : **le même filtre que la file de curation des films jamais trouvés** (10 § 7.4) | Liste vide |

- En mode sans score, tous les `points_total` valent 0 : le tri de `bestAnswer` redonne mécaniquement la réponse la plus rapide, le repli voulu par D25 du 23/09.
- Le tri est total : `round_number` et `lock_rank` identifient une bonne réponse parmi les manches `completed`.
- Le titre d'un fait marquant se lit dans l'entrée du récapitulatif de même `roundNumber`, qui est toujours `completed`.
- `bestAnswer` et `fastestFind` peuvent désigner la même réponse ; les deux restent montrés.
- Les faits marquants ne sont pas des scores : ils ne sont pas couverts par `scoring_version` (§ 6.1).

### 11.6 Partie sans score

`Podium.scoreless` vrai : même classement (la chaîne commence de fait aux bonnes réponses, § 2.5) ; l'écran met en tête bonnes réponses et temps cumulé et affiche `game.podium.scoreless` au lieu des scores (J2, L80-8).

### 11.7 Durée de vie du podium

Le podium reste consultable **jusqu'à l'archivage du salon**, 24 h après la dernière activité (10 § 6.2), par la resynchronisation ; en solo, jusqu'à l'effacement du siège solo (10 § 7.1). Au-delà, `display_nickname` est effacé (10 § 11.1) et seule subsiste la ligne d'historique du joueur (40, J2), **sans faits marquants ni pseudo d'autrui** (décision 19 : l'historique n'affiche jamais le pseudo des autres joueurs). L'anonymisation d'un compte ne casse jamais le podium des autres : aucune clé étrangère ne part de `users` vers un fait de partie (10 § 1.5). Hors v1 : image récapitulative, partage, badges, classement global (00 § Hors périmètre v1).

### 11.8 Exemple, au réglage par défaut

Partie à `N = 3` et `M = 10`, interrompue après deux manches closes ; deux sièges. Manche 1 : A trouve à 2 000 ms (palier 1, 424 points), B à 12 000 ms (instant corrigé 11 700, palier 2, 200 + 83 = 283). Manche 2 : personne ne trouve.

```json
{ "gameStatus": "interrupted", "mode": "multiplayer", "roundsCompleted": 2, "roundsCount": 10, "framesPerRound": 3,
  "scoreless": false, "endedAt": "2026-09-23T20:31:04.000Z",
  "standings": [
    { "publicId": "k7q2m5x4b3c6", "nickname": "Alice", "masked": false,
      "avatar": { "kind": "preset", "url": "/avatars/preset-07.webp", "altKey": "common.avatar.alt.preset", "initials": "AL" },
      "status": "playing", "firstRoundNumber": 1, "rank": 1, "rankShared": false,
      "finalScore": 424, "correctAnswers": 1, "roundsPlayed": 2, "totalAnswerTimeMs": 2000 },
    { "publicId": "p3w6n5t4r2z7", "nickname": "Bob", "masked": false,
      "avatar": { "kind": null, "url": null, "altKey": "common.avatar.alt.initials", "initials": "BO" },
      "status": "left", "firstRoundNumber": 1, "rank": 2, "rankShared": false,
      "finalScore": 283, "correctAnswers": 1, "roundsPlayed": 2, "totalAnswerTimeMs": 12000 } ],
  "recap": [
    { "roundNumber": 1, "outcome": "completed",
      "titles": { "titles": { "fr": { "text": "Le Voyage de Chihiro", "lang": "fr" }, "en": { "text": "Spirited Away", "lang": "en" } },
                  "originalTitle": "千と千尋の神隠し", "originalTitleLatin": "Sen to Chihiro no Kamikakushi",
                  "originalLanguage": "ja", "year": 2001 },
      "foundCount": 2,
      "finders": [ { "publicId": "k7q2m5x4b3c6", "lockRank": 1, "tierIndex": 1, "answeredAtMs": 2000, "pointsTier": 300, "pointsBonus": 124, "pointsTotal": 424 },
                   { "publicId": "p3w6n5t4r2z7", "lockRank": 2, "tierIndex": 2, "answeredAtMs": 12000, "pointsTier": 200, "pointsBonus": 83, "pointsTotal": 283 } ] },
    { "roundNumber": 2, "outcome": "completed", "titles": { "…": "…" }, "foundCount": 0, "finders": [] } ],
  "highlights": { "bestAnswer": { "publicId": "k7q2m5x4b3c6", "roundNumber": 1, "tierIndex": 1, "answeredAtMs": 2000, "pointsTotal": 424 },
                  "fastestFind": { "publicId": "k7q2m5x4b3c6", "roundNumber": 1, "answeredAtMs": 2000 },
                  "unfoundRoundNumbers": [2] } }
```

---

## 12. Mode solo

Le solo est une partie ordinaire à un joueur (`game.mode = solo`, `questions-ouvertes.md` § Déjà tranché, « Mode solo ») : **même calculateur, même chaîne, même gel**. Ce qui change :

- `final_rank` reste NULL ; le rang s'affiche par le glyphe « — » décoratif (`aria-hidden="true"`), doublé du texte accessible `game.leaderboard.unranked` en `sr-only` (§ 8.2).
- « Voir la réponse » (`round_player.input_state = revealed`) clôt la manche par la fin anticipée et ouvre la révélation normale de durée `R`, **à 0 point** ; « Passer la manche » (`round_player.input_state = skipped`) enchaîne sans révélation, le titre restant au récapitulatif (D18 du 23/09, C7 § 4.12). **Aucun des deux ne produit jamais de `guess`** : les deux comptent comme manches jouées, jamais comme bonnes réponses (10 § 7.10), et leurs manches figurent dans `unfoundRoundNumbers`, `found_count` y valant 0.
- B_max suit le `N` effectivement joué, `game.frames_per_round` : quand D19 du 23/09 ramène d'office le `N` d'un preset au `N` jouable le plus proche, c'est ce `N` qui fixe le bonus.
- Classement, score et podium ne sont jamais diffusés : ils partent par `solo.state` et la prop `state` de `game/solo` (C7 § 4.12).
- Le solo est exclu des quatre compteurs (filtre de 40 sur `game.mode`, J2).

---

## 13. Agrégats pour l'historique et les quatre compteurs (jalon 2)

Les quatre compteurs, leurs requêtes, leur seuil d'affichage et leur écran appartiennent à 40 (10 § 15, J2). 80 en possède la **matière première** : la sémantique des agrégats figés du § 10.4. Garanties que 40 peut tenir pour acquises, et que les tests de 80 prouvent :

| Compteur de 40 | Ce qu'il lit | Garantie de 80 |
|---|---|---|
| Parties jouées | `game_player` des parties `multiplayer` dont `ended_at` est dans la fenêtre et `rounds_played ≥ 1` | Une partie interrompue compte dès qu'une manche a été close ; une manche annulée ne compte jamais. |
| Bonnes réponses | `Σ correct_answers` | Une manche révélée par « voir la réponse » n'en est jamais une. |
| Meilleur score | `MAX(final_score)`, avec `game.ended_at`, `rounds_count` et `frames_per_round` de la partie | Scores non nuls après le gel ; comparables à l'intérieur d'une partie seulement (§ 2.6). |
| Taux de réussite | `Σ correct_answers ÷ Σ rounds_played`, affiché à partir de `PlatformLimits::successRateMinRounds()` | `correct_answers ≤ rounds_played` ; dénominateur = manches réellement jouées, jamais `M`. |

**Aucun agrégat de profil n'est stocké** : les compteurs sont une requête sur les agrégats figés au podium ; une fenêtre glissante obligerait à décrémenter un agrégat stocké à chaque purge (décision 15, décision 19, 10 § 7.2). Le cache court des compteurs est invalidé par `GameFinalized` (40, J2).

---

## 14. Valeur du palier affichée en manche (D29 du 23/09)

L'écran de manche affiche la **valeur entière du palier courant, sans bonus**, calculée côté client ; c'est un **simple affichage, le serveur décide** (règle 1, C7 § 4.1).

```ts
// resources/js/lib/game/round-timeline.ts (module de 90, C16) — fonction de 80
export function tierValueAt(tiers: readonly TierWindow[], elapsedMs: number): number | null;
```

- Rend `points` du palier dont la fenêtre `[startsAtOffsetMs, startsAtOffsetMs + durationMs)` contient `elapsedMs`, `null` hors de `[0, D)`. Elle **ignore la grâce et le bonus**.
- `tiers` vient de `RoundTimeline.tiers` (C7), c'est-à-dire `round_tier` : les valeurs de palier sont des réglages publics, figés au lancement, qui ne révèlent rien (C16 § 3).
- `elapsedMs` vient de l'horloge resynchronisée de 60 (`serverNow()` moins `startsAt`), **jamais de l'arrivée d'un événement de frontière**, qui peut être en retard (00 § Stack, « le job diffuse la frontière, il ne la décide pas »).
- Rendu par 60 : `tChoice('game.round.tier_value', tier.points, { points: fmt(tier.points) })`, `fmt` étant `Intl.NumberFormat(locale)` (C15, C16). Masqué quand la manche est close ; au J2, masqué aussi quand tous les paliers valent 0 (mode sans score) — masquage porté par 60 (J2), § 1.4.
- `resources/js/lib/tier-value.ts` n'est pas créé ; `currentTier()` et `tierValueAt()` sont la seule implémentation client (R-35).

**Écarts possibles avec les points attribués**, à connaître et à ne pas corriger : (a) dans les `tier_grace_ms` qui suivent une frontière, l'écran montre déjà le palier suivant alors que le serveur crédite le précédent, mieux payé — l'écart joue en faveur du joueur ; (b) un clic envoyé juste avant une frontière et reçu plus de `tier_grace_ms` après elle est crédité au palier suivant ; (c) le bonus, jamais affiché, s'ajoute toujours. Afficher des « points potentiels » avec un bonus décroissant aurait rendu (b) systématique et ajouté une animation continue que le principe 13 déconseille.

---

## 15. Textes FR / EN

### 15.1 Règles de rendu

- **Données, jamais phrases** : le serveur ne compose aucune phrase à partir de ces clés ; pseudos, titres, points et durées transitent en données et le client les met en forme (05 § Erreurs et § Nombres). Les entiers sont formatés par `Intl.NumberFormat(locale)` ; `:duration` reçoit des millisecondes formatées en secondes à une décimale par `Intl.NumberFormat(locale, { style: 'unit', unit: 'second', maximumFractionDigits: 1 })`.
- **`:title` n'est jamais interpolé en texte brut** : le client découpe la ligne autour du paramètre et y insère le fragment du `TitlePacket` dans un élément portant son attribut `lang`. Sinon un titre obtenu par repli serait prononcé dans la langue du joueur (05 § Attribut `lang`).
- **Ordinaux** : la catégorie vient de `new Intl.PluralRules(locale, { type: 'ordinal' }).select(rank)` ; une catégorie autre que `one`, `two` ou `few` retombe sur `other`. Les quatre clés sont nommées par une table de constantes typées `TranslationKey` (jamais une clé construite par gabarit, que la vérification des clés appelées ne verrait pas). **Le suffixe ordinal est séparé de `:rank` par U+2060 (WORD JOINER)**, invisible et insécable : la vérification de symétrie des paramètres lit `:(?!:)([A-Za-z][A-Za-z0-9_]*)` et verrait sinon `:rankst` en anglais et `:ranker` en français comme deux paramètres différents. En français, `Intl.PluralRules` ne rend que `one` et `other` ; `two` et `few` existent quand même, pour la symétrie des clés.
- **`:count`** : tant que `translateChoice` écrase un `count` fourni, le nombre d'une clé à `:count` s'affiche brut. Les petites valeurs (`correct_answers`, `rounds_played`, `found_by`, `unfound`) n'en souffrent pas ; `game.score.points`, qui peut dépasser 9 999 (au plafond, `MAX_ROUNDS_COUNT` = 30 manches × 1 500), attend le correctif de parité demandé à 05 (§ 1.4) pour passer `{ count: fmt(n) }`. 80 ne modifie pas `resources/js/lib/i18n.ts` (C15 § 2.1).
- **Typographie française** : celle des dictionnaires existants de `lang/fr/` — espace ordinaire avant « : » et avant « % », comme dans les cellules du § 15.2 ; guillemets « » autour d'un titre en français, “ ” en anglais. Une règle d'espace insécable, si le porteur la veut, relève de `05` et s'applique à tous les domaines à la fois, jamais au seul domaine `game` (§ 21) : un domaine qui s'en écarterait ferait diverger la typographie d'un écran à l'autre.
- `game.podium.completed` n'a pas de pluriel : `M ≥ RoomSettingsBounds::MIN_ROUNDS_COUNT = 3`.

### 15.2 Clés, textes et placeholders

Domaine `game` (C15), fichiers `lang/fr/game.php` et `lang/en/game.php`, symétriques. Dans les quatre lignes ordinales, `:rank` et son suffixe sont séparés par le caractère **invisible** U+2060, présent dans les cellules ci-dessous (un copier-coller le conserve) ; écrit visiblement, la cellule FR `one` se lit `:rank` ⟨U+2060⟩ `er`.

| Clé | Placeholders | FR | EN | Jalon |
|---|---|---|---|---|
| `game.round.tier_value` | `:points` (pluriel sur `points`) | `:points point en jeu\|:points points en jeu` | `:points point at stake\|:points points at stake` | J1 |
| `game.score.points` | `:count` | `:count point\|:count points` | `:count point\|:count points` | J1 |
| `game.score.bonus` | `:points` | `dont :points de bonus de rapidité` | `including :points speed bonus` | J1 |
| `game.score.gained` | `:points` | `Gagné : :points` | `Earned: :points` | J1 |
| `game.score.total` | `:points` | `Total : :points` | `Total: :points` | J1 |
| `game.leaderboard.title` | — | `Classement` | `Standings` | J1 |
| `game.leaderboard.rank_shared` | — | `ex æquo` | `tied` | J1 |
| `game.leaderboard.unranked` | — | `Sans rang` | `Unranked` | J1 |
| `game.leaderboard.column.rank` | — | `Rang` | `Rank` | J1 |
| `game.leaderboard.column.player` | — | `Joueur` | `Player` | J1 |
| `game.leaderboard.column.score` | — | `Points` | `Points` | J1 |
| `game.leaderboard.column.correct_answers` | — | `Bonnes réponses` | `Correct answers` | J1 |
| `game.leaderboard.column.answer_time` | — | `Temps cumulé` | `Total time` | J1 |
| `game.leaderboard.column.round_delta` | — | `Cette manche` | `This round` | J1 |
| `game.leaderboard.status.left` | — | `A quitté la partie` | `Left the game` | J1 |
| `game.leaderboard.status.kicked` | — | `Expulsé par l'hôte` | `Removed by the host` | J1 |
| `game.leaderboard.ordinal.one` | `:rank` | `:rank⁠er` | `:rank⁠st` | J1 |
| `game.leaderboard.ordinal.two` | `:rank` | `:rank⁠e` | `:rank⁠nd` | J1 |
| `game.leaderboard.ordinal.few` | `:rank` | `:rank⁠e` | `:rank⁠rd` | J1 |
| `game.leaderboard.ordinal.other` | `:rank` | `:rank⁠e` | `:rank⁠th` | J1 |
| `game.leaderboard.correct_answers` | `:count` | `:count bonne réponse\|:count bonnes réponses` | `:count correct answer\|:count correct answers` | J1 |
| `game.leaderboard.late_joiner` | `:round` | `Entrée à la manche :round` | `Joined at round :round` | J1 |
| `game.leaderboard.answer_time` | `:duration` | `Temps cumulé : :duration` | `Total time: :duration` | J1 |
| `game.leaderboard.round_delta` | `:points` | `+:points sur cette manche` | `+:points this round` | J1 |
| `game.podium.title` | — | `Podium` | `Final standings` | J1 |
| `game.podium.completed` | `:m` | `Partie terminée · :m manches` | `Game over · :m rounds` | J1 |
| `game.podium.interrupted` | `:k`, `:m` | `Partie interrompue à la manche :k sur :m` | `Game interrupted at round :k of :m` | J1 |
| `game.podium.rounds_played` | `:count` | `:count manche jouée\|:count manches jouées` | `:count round played\|:count rounds played` | J1 |
| `game.podium.highlights.best_answer` | `:nickname`, `:title`, `:points` | `Meilleure réponse : :nickname, « :title » (+:points)` | `Best answer: :nickname, “:title” (+:points)` | J1 |
| `game.podium.highlights.fastest_find` | `:nickname`, `:title`, `:duration` | `Trouvé le plus vite : « :title », par :nickname en :duration` | `Fastest find: “:title”, by :nickname in :duration` | J1 |
| `game.podium.highlights.none` | — | `Aucune bonne réponse dans cette partie` | `No correct answer in this game` | J1 |
| `game.podium.highlights.unfound` | `:count` | `:count film que personne n'a trouvé\|:count films que personne n'a trouvés` | `:count film nobody found\|:count films nobody found` | J1 |
| `game.recap.title` | — | `Récapitulatif des films` | `Films recap` | J1 |
| `game.recap.round` | `:number` | `Manche :number` | `Round :number` | J1 |
| `game.recap.cancelled` | — | `Manche annulée` | `Round cancelled` | J1 |
| `game.recap.nobody` | — | `Personne n'a trouvé` | `Nobody found it` | J1 |
| `game.recap.found_by` | `:count` | `Trouvé par :count joueur\|Trouvé par :count joueurs` | `Found by :count player\|Found by :count players` | J1 |
| `game.help.scoring.tier_values` | — | `Chaque image vaut des points ; avec le barème par défaut, la première, la plus cryptique, rapporte le plus. C'est l'image affichée à l'instant où le serveur reçoit votre réponse qui compte.` | `Each image is worth points; with the default scoring, the first, most cryptic one pays the most. What counts is the image on screen when the server receives your answer.` | J1 |
| `game.help.scoring.speed_bonus` | `:percent` | `Bonus de rapidité : jusqu'à :percent % de la valeur de l'image, dégressif jusqu'à l'image suivante. Avec le barème par défaut, attendre l'image suivante ne rapporte jamais plus ; au mieux autant.` | `Speed bonus: up to :percent% of the image's value, shrinking until the next image. With the default scoring, waiting for the next image never pays more; at best the same.` | J1 |
| `game.help.scoring.tie_break` | — | `À score égal : le plus de bonnes réponses, puis le temps cumulé le plus court, puis le plus de trouvailles sur les premières images. Sinon, la place est partagée, jamais tirée au sort.` | `On equal scores: most correct answers, then shortest total time, then most finds on the earliest images. Otherwise the place is shared, never drawn at random.` | J1 |
| `game.help.scoring.no_penalty` | — | `Une mauvaise réponse ne coûte aucun point.` | `A wrong answer costs no points.` | J1 |
| `game.help.scoring.cancelled_round` | — | `Une manche annulée à la suite d'un incident ne compte pas : ni points, ni manche jouée.` | `A round cancelled after an incident does not count: no points, no round played.` | J1 |
| `game.podium.scoreless` | — | `Partie sans score : classement aux bonnes réponses, puis au temps` | `No-score game: ranked by correct answers, then by time` | J2 |
| `game.help.scoring.scoreless` | — | `Quand toutes les images valent 0, le classement suit les bonnes réponses, puis le temps cumulé.` | `When every image is worth 0, the standings follow correct answers, then total time.` | J2 |

`:percent` de `game.help.scoring.speed_bonus` vaut `speedBonusMaxPercent[N]` de la prop `PlatformLimits::toArray()` (C0 § 3.4) pour le `N` du contexte — salon, partie ou preset solo —, et `RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND` hors contexte. La ligne est masquée quand le bonus est désactivé (J2) — masquage porté par 90, propriétaire de l'écran d'aide (§ 1.4). `tier_values` porte la réserve « avec le barème par défaut » pour la même raison que `speed_bonus` : au J2, l'onglet Avancé admet des barèmes égaux ou non décroissants (§ 2.4), où la première image ne rapporte pas le plus. « Au mieux autant » est la forme publique de l'égalité exacte à `N = 3` et `N = 5` (§ 3.3). L'écran d'aide, sa place et son déclencheur appartiennent à 90, qui en possède l'écran quand 70 et 80 en fournissent les textes (C15) ; la règle du préfixe (`game.help.prefix`) est écrite par 70.

---

## 16. Tests

Noms en phrase française au présent (C18) ; les tests hérités du contrat C13 sont repris à la lettre, les ajouts de cette spec sont marqués « ajout ».

**`tests/Feature/Scoring/ScoreCalculatorTest.php`** (L80-1)
- `une réponse reçue à borne + (tier_grace_ms − 1) retient le palier précédent`
- `une réponse reçue à borne + (tier_grace_ms + 1) retient le palier suivant`
- `le bonus est un plancher entier : P = 100, B_max = 50 %, d = 5 000 ms, t = 1 700 ms donne 33`
- `le bonus vaut exactement intdiv(P × B_max, 100) à t = 0 et ne le dépasse jamais`
- `un palier à 0 rapporte 0, bonus compris`
- `bonus désactivé : seule la valeur du palier compte`
- `B_max vaut 50, 50, 33 et 25 % pour N = 2, 3, 4 et 5`
- `au barème par défaut, attendre la frontière suivante ne rapporte jamais plus, pour tout N et toute durée légale` — matrice exhaustive, sans échantillonnage : chaque `N` de `MIN_FRAMES_PER_ROUND` à `MAX_FRAMES_PER_ROUND`, chaque durée entière de `minRoundDuration(N)` à `MAX_ROUND_DURATION` au découpage `defaultTierDurations(N, D)`, chaque frontière ; on compare la dernière milliseconde du palier `i` à la première du palier `i + 1`, ce qui suffit par la preuve du § 3.3 ; bornes lues dans `RoomSettingsBounds`, aucun littéral
- `aux cas N = 3 et N = 5, le début du palier 2 rapporte exactement la valeur du palier 1`
- `un clic QCM en Normal reçu dans la grâce de T_N est crédité au palier N avec t = 0`
- `texte libre et clic QCM se notent à l’identique hors plancher`
- `une version de règle inconnue est refusée`
- `un answered_at_ms hors de [0, D + tier_grace_ms) est refusé`
- `points_total = points_tier + points_bonus et tient en unsignedSmallInteger au plafond`

**`tests/Feature/Scoring/ScoringRulesTest.php`**
- `empreinte de la version 1 : table B_max, chaîne de départage, échantillons d’arrondi` (L80-1)
- `la fabrique de partie écrit ScoringRules::VERSION dans scoring_version` (L80-1)
- ajout, J2 : `waitingPays est faux pour tout barème par défaut et vrai pour 300, 250, 100 à N = 3` (L80-8)

**`tests/Feature/Scoring/SpeedBonusTest.php`** (L80-1, exemple de consommation de C18, sur le jeu de données `room_settings.accepted`)
- `garde B_max en pourcentage entier pour chaque N accepté`

**`tests/Feature/Room/PlatformLimitsTest.php`** (fichier de C0, propriété de 50, écrit par L50-1 ; 80 en exige les cas ci-dessous sans les écrire, C13 § 7)
- `fixe speedBonusMaxPercent à 50, 50, 33 et 25 pour N = 2 à 5 sous la version de score 1`
- `refuse un N hors bornes pour speedBonusMaxPercent au lieu de l'écrêter`
- `ignore toute configuration de B_max`
- `ignore toute configuration de tier_grace_ms et de preload_lead_ms` (note 2 du 23/09)

**`tests/Feature/Scoring/ScoreReplayTest.php`**
- `le rejeu de (answered_at_ms, round_tier, tier_grace_ms, settings_snapshot, scoring_version) redonne exactement tier_index et points_total` — 10 § 7.5, avec un cas `N = 5` et un clic QCM en Normal (L80-2)
- `un changement de configuration de tier_grace_ms après la partie ne change pas le rejeu` — la partie porte un `tier_grace_ms` différent de `PlatformLimits::tierGraceMs()`, et le rejeu relit la colonne (L80-2)
- ajout, J2 : `mismatches ne rend rien pour une partie cohérente et nomme un guess altéré` (L80-9)

**`tests/Feature/Scoring/RankingTest.php`** (L80-3)
- `ordre : score, puis bonnes réponses, puis temps cumulé, puis trouvailles aux paliers 1 à N − 1`
- `à score égal, le siège qui a le plus de bonnes réponses passe devant`
- `place partagée en classement de compétition 1, 2, 2, 4`
- `aucune graine n’intervient : deux parties aux graines différentes et aux totaux identiques donnent le même classement`
- `partis et expulsés restent classés avec leurs points`
- `un siège sans manche jouée n’a pas de rang`
- `mode sans score : le classement suit la chaîne à partir des bonnes réponses`

**`tests/Feature/Scoring/LeaderboardVisibilityTest.php`** (L80-3)
- `la resynchronisation d’un tiers pendant une manche où A est verrouillé laisse le score de A inchangé`
- `points et palier d’une manche deviennent publics dès revealing, jamais avant`
- `roundFinders refuse une manche running`
- `aucun bloc TierScore, SeatScore, Leaderboard ni RoundFinder ne contient d’identifiant interne, de titre ni de nature d’appariement ; seul Podium.recap porte des titres, après le gel`
- ajout : `en solo, le classement intermédiaire ne porte aucun rang`

**`tests/Feature/Scoring/FinalizeGameTest.php`** (L80-4)
- `le gel écrit ended_at, le statut, rounds_completed et les cinq agrégats dans une seule transaction`
- `un second appel ne réécrit rien et ne réémet pas GameFinalized`
- `une manche cancelled portant deux guess de 400 points ne modifie ni final_score, ni correct_answers, ni rounds_played, ni le rang` (10 A13)
- `correct_answers ≤ rounds_played pour chaque siège, clôture d’office comprise`
- `partie interrompue à la manche k : rounds_completed = k et agrégats gelés`
- `partie interrompue avant toute manche close : agrégats à zéro, aucun rang`
- `retardataire : rounds_played ne compte que ses manches`
- `en solo, final_rank reste nul et les manches revealed ou skipped comptent comme jouées, jamais comme bonnes réponses`
- `une partie bloquée est close à sa dernière activité connue`
- `le gel refuse une manche running dont la durée n’est pas écoulée`
- ajout : `la clôture d'office pose round.ended_at à started_at + duration_ms`
- ajout : `une partie terminée sur une annulation sans manche restante est gelée completed avec rounds_completed inférieur à M`

**`tests/Concurrency/Scoring/FinalizeGameConcurrencyTest.php`** (groupe `locks-timing` par répertoire, MySQL ; L80-4)
- `deux appels concurrents ne gèlent qu’une fois`

**`tests/Feature/Scoring/PodiumTest.php`** (L80-5)
- `le podium ne lit que les agrégats figés`
- `meilleure réponse : points puis vitesse ; en mode sans score, la plus rapide`
- `film que personne n’a eu : manches completed à found_count = 0`
- `le récapitulatif ne montre jamais une manche pending et montre une annulation non remplacée sans titre`
- `le récapitulatif porte un paquet de titres par locale activée et aucun identifiant interne`
- `le podium d’une partie interrompue porte k et M`
- ajout : `une manche annulée puis remplacée n'apparaît qu'une fois, sous son remplaçant`

**`tests/Feature/Architecture/ScoringWritersTest.php`** (balayage statique de `app/` avec liste d'autorisation nominative)
- `dans app/, seul FinalizeGame écrit game.ended_at et les agrégats de game_player` (L80-4)
- `toute écriture de guess.points_* passe par ScoreCalculator` (L80-2)
- `aucune classe de App\Support\Scoring n’emploie draw_seed, hash_hmac, random_int ni shuffle` (L80-2)
- ajout (L80-4) : `dans app/, seul FinalizeGame écrit game.status à completed ou interrupted` — sans lui, un appelant de 60 pourrait terminer une partie sans gel et casser « `ended_at` non nul ⟺ statut terminal » (C17)

**Vitest**, sous `tests/Frontend/` (C18)
- `tests/Frontend/game/round-timeline.test.ts` (fichier de C16) : `tierValueAt rend la valeur entière du palier courant, sans grâce ni bonus` (L80-6)
- `tests/Frontend/scoring/scoring-format.test.ts`, ajouts (L80-7) : `choisit la clé ordinale par Intl.PluralRules en français et en anglais` ; `insère le titre dans un fragment portant son attribut lang` ; `formate une durée en millisecondes en secondes à une décimale dans la locale du joueur`

Les tests « B_max jamais lu en configuration », « aucune résolution par compte ni par plan », « B_max en pourcentage entier dans toArray » et « fromInput refuse speedBonusMaxPercent » vivent une seule fois dans les fichiers de C0 (R-04). La symétrie des nouvelles clés FR/EN est prouvée par `TranslationCoverageTest` [existant].

---

## 17. Arbitrages

**A80-1 — B_max : fraction flottante réglable, ou pourcentage entier constant fonction de `N` ?**
Écarté : `speedBonusMaxFraction = 0.50`, flottant, lu en configuration, identique pour tout `N`. Retenu : `speedBonusMaxPercent(N) = min(50, intdiv(100, N − 1))`, pourcentage entier, constante d'instance jamais lue en configuration (D22 du 23/09). Raison : le flottant diverge d'un point entre direct et rejeu ; la configuration change la règle sans changer la version ; et 50 % fixe rend l'attente payante dès `N = 4`.

**A80-2 — Une fonction ou deux pour le palier et les points ?**
Écarté : palier choisi par 70 à la validation, bonus calculé par 80. Retenu : une seule fonction pure, `ScoreCalculator::forGuess()`, plancher du QCM compris (R-18, R-19). Raison : deux formules finissent par lire deux instants différents, et le rejeu doit rejouer exactement ce qui a été écrit.

**A80-3 — Temps cumulé brut ou corrigé ?**
Retenu : brut. Raison : § 8.4 ; la correction est une translation uniforme à bonnes réponses égales, sauf sous la grâce, où elle crée des égalités artificielles.

**A80-4 — Rang d'un siège sans manche jouée : partagé ou nul ?**
Retenu : nul. Raison : un rang 1 partagé pour une partie interrompue à `k = 0` s'afficherait « 1ᵉʳ » dans l'historique d'une partie que personne n'a jouée.

**A80-5 — Quand un score devient-il public : après `reveal_ends_at`, ou dès `revealing` ?**
Retenu : dès `revealing` (n° 65, E10-52). Raison : la révélation montre qui a trouvé, en combien de temps, et le classement intermédiaire pendant `R` ; `reveal_ends_at` en marque la **fin**.

**A80-6 — Instant de fin d'une partie bloquée : maintenant, ou dernière activité connue ?**
Retenu : dernière activité connue. Raison : § 10.5 ; « maintenant » prolongerait la conservation d'une partie ancienne et rendrait fausse une durée publiée.

**A80-7 — Deux filtres ou un seul pour les agrégats ?**
Retenu : un seul, les manches `completed`, après clôture d'office. Raison : avec deux filtres, le taux de réussite peut dépasser 100 % (n° 67).

**A80-8 — Récapitulatif : vignettes ou texte ?**
Retenu : texte seul (Q80-1). Raison : § 11.4.

**A80-9 — Meilleure manche par joueur, ou meilleure réponse de la partie ?**
Retenu : un fait marquant de partie unique, la meilleure réponse, plus les deux faits marquants dérivés des données (D25 du 23/09). Raison : tout se lit dans les données déjà nécessaires au récapitulatif, et une ligne par joueur surchargerait le portrait à 12 joueurs.

**A80-10 — Que montre l'écran de manche avant le verrouillage ?**
Retenu : la valeur entière du palier courant, sans bonus (D29 du 23/09). Écartés : rien (l'enjeu du palier resterait invisible) ; des points potentiels en direct (écart systématique avec l'attribué, animation continue).

**A80-11 — Classement plafonné à 12 lignes ?**
Retenu : sans plafond (n° 67). Raison : les partis restent classés et les retardataires prennent leur siège ; la capacité borne l'effectif simultané, pas l'historique des sièges.

---

## 18. Exigences adressées à 10

Exigences consolidées de la feuille de contrats dont cette spec dépend ; `10` les inscrit ou les refuse en le signalant.

- **E10-04** — `game_player.final_rank` élargi de `unsignedTinyInteger` à `unsignedSmallInteger`, par amendement de la migration `2026_09_22_100027_create_game_player_table` : `game_player` n'est pas borné. **Précision non consolidée** : l'amendement de la migration de création ne vaut que **si aucun déploiement de production ne l'a encore jouée** ; sinon, migration additive `alter_final_rank_on_game_player_table` (`->unsignedSmallInteger('final_rank')->nullable()->change()`). D1 du 23/09 fait naître la production dès le J1 : `migrate` ne rejoue jamais une migration déjà jouée, et la CI, sur base neuve, passerait au vert pendant que le schéma de production divergerait en silence.
- **E10-14** — 10 § 1.8, L1 : ajouter la lecture « publiable » (`ScoreScope::Publishable`, manches `revealing` et `completed`) pour tout score montré à un autre siège.
- **E10-27** — 10 § 6.1 : `PlatformLimits::speedBonusMaxPercent(N)` en pourcentage entier, non surchargeable, exception hors bornes ; `speedBonusMaxFraction` supprimé ; constantes d'instance jamais résolues par compte ni par plan. **Précision non consolidée** : par la note 2 du rédacteur en chef du 23/09, `tierGraceMs` et `preloadLeadMs` rejoignent B_max hors configuration (§ 3.5).
- **E10-36** — 10 § 7.2 : `rounds_completed = COUNT(round completed)`, maintenu par 60 et recalculé au gel ; `ended_at` écrit exclusivement par `FinalizeGame` ; « `ended_at IS NULL` ⟺ statut non terminal ».
- **E10-38** — 10 § 7.2 et § 6.1 : `scoring_version` écrite depuis `ScoringRules::VERSION` ; déclencheurs d'incrément du § 6.1 ; exemple « 0,50 → 0,40 » réécrit en pourcentage entier.
- **E10-40** — 10 § 7.2 : aucune égalité de score n'est tranchée par la graine ; contexte `tiebreak:{roundId}` retiré.
- **E10-43** — 10 § 7.3 : agrégats sous le filtre unique `completed`, invariant `correct_answers ≤ rounds_played`, `final_rank` NULL en solo ou si `rounds_played = 0`, écrits exclusivement par `FinalizeGame`, manche `cancelled` hors de tout agrégat.
- **E10-44** — 10 § 7.3, § 7.6, § 12 : borne de `guess` = `roomSeats() × min(M + drawSubstituteMargin(), |vivier|)` ; `game_player` non borné à 12. **Remarque** : le texte consolidé écrit « soit 396 aux défauts » ; 396 est la valeur **au plafond de `M`** (12 × 33), la valeur au réglage par défaut étant 156 (12 × 13).
- **E10-48** — 10 § 7.5 : le plancher du QCM est écrit dans 80, la fenêtre d'acceptation dans 70 ; le test de rejeu lit aussi `guess.source`, `game.input_difficulty` et `game.frames_per_round`.
- **E10-51** — 10 § 7.6 : ordre de verrouillage global room → player → game → round → round_player ; la transaction de verrouillage ne verrouille jamais `game`.
- **E10-52** — 10 § 7.6 et docblock de `Guess` : points et `tier_index` publiables dès `round.status = revealing`, via `Scoreboard`, jamais par `toArray()`.
- **E10-62** — 10 § 11.1 et § 11.3 : la clôture forcée `stale_game` et la réparation signalée par la sonde n° 1 passent par `FinalizeGame(Interrupted, lastKnownActivity())`.
- **E10-67** — 10 § 15 l.1408 : retirer « À confirmer par 80 » ; une manche `cancelled` est hors `rounds_played`.

Aucune colonne nouvelle, aucun cas d'énumération persistant, aucun index nouveau : les lectures passent par `round_game_status_idx`, `guess_round_player_uq`, `round_player_round_player_uq` et `game_player_game_idx`.

---

## 19. Amendements à d'autres documents

Cette spec ne modifie aucun document ; elle dépend des amendements consolidés suivants, appliqués par un travail séparé.

**`docs/specs/00-overview.md`**
- **A-03** — l.20-21 : la révélation montre les `N` images servies, le titre dans la langue du joueur, le titre original s'il diffère et l'année ; « affiche » et « précharge la manche suivante » retirés ; « fige les scores » devient « clôt la saisie ; rend publiables points et paliers de la manche ».
- **A-04** — l.27 : l'affichage « mode solo » à un seul joueur connecté devient le libellé `game.round.lone_player`, sans effet sur `game.mode`, le rang ni les compteurs.
- **A-10** — l.90 et l.178 : B_max = min(50 %, 100 % / (N − 1)) en pourcentage entier, constante d'instance ; mode sans gradient réécrit (le bonus classe la promptitude à l'intérieur de chaque palier, attendre la frontière y paie, d'où l'avertissement).
- **A-16** — l.119 : « meilleure manche » devient « meilleure réponse de la partie », plus « film le plus rapidement trouvé » et « film que personne n'a eu ».
- **A-19** — l.137 : ajouter « jamais la graine » à la chaîne de départage.
- **A-25** — lexique : « gel du podium » → `finalize` (`FinalizeGame`, `GameFinalized`) ; « fait marquant » → `highlight`.
- **A-35** — l.439 (carte des specs) : la ligne de 80 dit « partie interrompue » et ajoute « gel idempotent, faits du podium ».

**`docs/specs/questions-ouvertes.md`**
- **A-60** — l.320 : B_max = min(50 %, 100 % / (N − 1)), en pourcentage entier.

**`CLAUDE.md`**
- **A-68** — §2 (barème) : « B_max = 50 % … vit dans le value object de limites » devient « B_max(N) = min(50 %, 100 % / (N − 1)), en pourcentage entier, constante d'instance de `PlatformLimits`, jamais surchargeable ».
- **A-70** — §2 (partie et validation) : fin anticipée « quand tous les participants ont leur saisie close » ; « mode solo » à deux joueurs renvoyé à 00 l.27, cosmétique ; « réglages figés du lancement au podium » devient « … au « Rejouer » de l'hôte ».
- **A-74** — §6 (lexique) : ajouter gel du podium → `finalize`, fait marquant → `highlight`.

Aucun amendement consolidé de `05` n'est demandé par 80 (contrat C13 § 6). Un seul changement lui est adressé, non consolidé et signalé au porteur : la parité de `translateChoice` avec `Translator::choice` (§ 1.4). Les corrections de code — docblocks de `Guess` et de `GamePlayer`, `GameFactory::SCORING_VERSION` — sont portées par les lots du § 20 ; celles de `PlatformLimits` et du docblock de `RoomSettings`, par L50-1 (50).

---

## 20. Lots d'implémentation

Estimations en heures, barre « terminé » comprise (tests verts, textes FR et EN, états de chargement, d'erreur et de déconnexion, parcours clavier), facteur 1,5 à 2 intégré (00 § Jalons). L'intégration des blocs de 80 dans les transitions et les pages de 60 (appels depuis `RevealRound`, `EndReveal`, `CancelRound`, `InterruptPausedGame`, `ResumeGame`, `SkipSoloRound`, `StartSoloGame`, `GameStateBuilder`, montage des composants) est comptée dans les lots de 60, pas ici. Le changement B_max de `PlatformLimits` et ses trois tests sont comptés dans L50-1 (50), pas ici (§ 1.4).

| Lot | Jalon | Contenu | Dépend de | Heures |
|---|---|---|---|---|
| L80-1 | J1 | Règle de score et fonction pure (part de D22 du 23/09 propre au calcul) | L50-1 (50) ; C18 (jeu de données `room_settings.accepted`, 100) | 4–6 |
| L80-2 | J1 | Portées de lecture et rejeu | L80-1 | 2–4 |
| L80-3 | J1 | Départage, classement intermédiaire et types TypeScript du score | L80-2 ; L60-2 (`RevealMovie`, même commit) ; L40-6 (`PlayerIdentity`) | 4–6 |
| L80-4 | J1 | Action de gel | L80-3 ; E10-04 ; C18 (suite `Concurrency`, `tests/Pest.php`, `tests-mysql.yml`, 100) | 4–6 |
| L80-5 | J1 | Podium, récapitulatif et faits marquants (D25 du 23/09) | L80-4 ; L40-6 (C5) ; L60-2, L60-6 (C7) ; L70-7 (C11) | 6–8 |
| L80-6 | J1 | Textes FR/EN, aide, `tierValueAt` (D29 du 23/09) | L80-1 ; socle 90 (C16) ; C18 (Vitest, 100) | 2–4 |
| L80-7 | J1 | Composants de classement, de récapitulatif et de podium | L80-3, L80-5, L80-6 ; L60-9 (assistant client des titres) ; socle 90 (C16) ; L40-6 (C5) ; C18 (Vitest, 100) | 5–7 |
| L80-8 | J2 | Mode sans score à l'écran, `waitingPays()` | L80-7 ; onglet Avancé (50, J2) | 3–4 |
| L80-9 | J2 | Rapport d'écarts du rejeu | L80-2 ; écran « inspecter une partie » (20, J2) | 2–3 |

**L80-1 — Règle de score et fonction pure** (J1, 4–6 h, dont la part de D22 du 23/09 propre au calcul ≈ 1 h)
- Dépendances : L50-1 (50), qui écrit `PlatformLimits::speedBonusMaxPercent()`, `FULL_PERCENT`, `SPEED_BONUS_MAX_PERCENT_CAP`, la suppression de la fraction, la clé `toArray()`, les docblocks de `PlatformLimits` et de `RoomSettings`, ainsi que les trois cas B_max de `PlatformLimitsTest` (C0, C13 § 1 et § 7). **L80-1 ne modifie pas `PlatformLimits`** et n'en compte ni le code ni les tests. C18 (100 [J1]) : jeu de données `room_settings.accepted` (`tests/Datasets/RoomSettingsMatrix.php`), consommé par `SpeedBonusTest`.
- Créés : `app/Support/Scoring/ScoringRules.php`, `ScoreCalculator.php`, `UnsupportedScoringVersion.php` ; `app/ValueObjects/Scoring/TierWindow.php`, `TierSchedule.php`, `TierScore.php`.
- Modifiés : `app/Models/RoundTier.php` (`containsOffsetMs()` délègue à `TierWindow`) ; `database/factories/GameFactory.php` (`SCORING_VERSION` supprimé, lecture de `ScoringRules::VERSION`) ; `database/factories/GuessFactory.php` (état `scoredAt(int $answeredAtMs, GuessSource $source = GuessSource::Text)`, qui appelle `forGuess()` ; `atTier()` et `withSpeedBonus()` conservés).
- Tests : `ScoreCalculatorTest` (14), `ScoringRulesTest` (2), `SpeedBonusTest` (1).

**L80-2 — Portées de lecture et rejeu** (J1, 2–4 h)
- Dépendances : L80-1.
- Créés : `app/Enums/ScoreScope.php` ; `app/Support/Scoring/ScoreReplayer.php` (`replay()` seul).
- Modifiés : `app/Models/Guess.php` (scope `inScoreScope` ; docblock l.25, l.39 et l.55-56 ; docblock de `counted()` l.131-135 réécrit, texte au § 7.4 — le scope lui-même est conservé) ; `app/Models/RoundPlayer.php` (scope `inScoreScope`).
- Tests : `ScoreReplayTest` (2), `ScoringWritersTest` (`toute écriture de guess.points_* passe par ScoreCalculator` et `aucune classe de App\Support\Scoring n’emploie draw_seed, hash_hmac, random_int ni shuffle`).

**L80-3 — Départage, classement intermédiaire et types TypeScript du score** (J1, 4–6 h)
- Dépendances : L80-2 ; L60-2 (`RevealMovie` dans `types/game-wire.ts`, C7) ; L40-6 (`PlayerIdentity` dans `types/player.ts`, C5). `types/game-wire.ts` importe à son tour `TierWindow`, `RoundFinder`, `Leaderboard` et `Podium` de `types/scoring.ts` (60 § 11.4) : aucun des deux fichiers ne compile sans l'autre, et **L60-2 et L80-3 entrent dans le même commit** (§ 1.4). C'est aussi pourquoi `types/scoring.ts` naît ici en entier, `Podium` compris, et non par morceaux jusqu'en L80-5 : `tsc` casserait `game-wire.ts` entre les deux lots. Consommé par L60-6 (`RoundRevealed`) et L60-12 (`GameStatePacket`).
- Créés : `app/Support/Scoring/Ranking.php` ; `app/Support/Scoring/Scoreboard.php` (`tallies`, `leaderboard`, `roundFinders`, `seatScore`, aux signatures du § 4.1 ; `@phpstan-type` `LeaderboardPayload`, `RoundFinderPayload`, `SeatScorePayload`, `PodiumPayload` ; forçage du rang nul en solo, § 8.2) ; `app/ValueObjects/Scoring/PlayerTally.php`, `Standing.php` ; `resources/js/types/scoring.ts`, en entier (C13 § 2.4) : `TierWindow`, `TierScore`, `SeatScore`, `LeaderboardRow`, `Leaderboard`, `RoundFinder`, `TitlePacket` (`= RevealMovie`, importé de `types/game-wire.ts`), `RecapEntry`, `PodiumStanding` (qui étend `PlayerIdentity`, importé de `types/player.ts`), `PodiumHighlights`, `Podium` ; seule déclaration de ces types, dans `WATCHED`.
- Tests : `RankingTest` (7), `LeaderboardVisibilityTest` (5).

**L80-4 — Action de gel** (J1, 4–6 h)
- Dépendances : L80-3 ; E10-04 ; C18 (100 [J1]) : suite `Concurrency` de `phpunit.xml`, `tests/Pest.php` (`DatabaseTruncation`, `requireMysql()`) et workflow `tests-mysql.yml` — sans eux, `FinalizeGameConcurrencyTest` ne tourne nulle part. Consommé par 60 (fin normale, annulation sans manche restante, clôture après pause par `InterruptPausedGame` ou `ResumeGame`, passage de la dernière manche solo, relance d'un solo en cours, reprise), 100 (`stale_game`), 50 (`ReplayRoom`).
- Créés : `app/Actions/Game/FinalizeGame.php` ; `app/Events/Game/GameFinalized.php` ; `tests/Concurrency/Scoring/FinalizeGameConcurrencyTest.php`.
- Modifiés : `app/Models/GamePlayer.php` (docblock l.26-27, texte au § 7.4) ; E10-04 (`final_rank` en `unsignedSmallInteger`) par amendement de `database/migrations/2026_09_22_100027_create_game_player_table.php` **seulement si aucun déploiement de production ne l'a encore jouée** ; sinon par une migration additive `alter_final_rank_on_game_player_table` (`->unsignedSmallInteger('final_rank')->nullable()->change()`), jamais par l'édition d'une migration déjà jouée (D1 du 23/09, § 18) ; `database/factories/GameFactory.php` (nouvel état `finalized(GameStatus $outcome = GameStatus::Completed)`, qui appelle `FinalizeGame` après création ; `completed()` et `interrupted()` restent pour les tests de schéma et de purge, et aucun test qui lit un agrégat ne les emploie).
- Tests : `FinalizeGameTest` (12), `FinalizeGameConcurrencyTest` (1, MySQL), `ScoringWritersTest` (`dans app/, seul FinalizeGame écrit game.ended_at et les agrégats de game_player`, et l'ajout sur `game.status`).

**L80-5 — Podium, récapitulatif et faits marquants** (J1, 6–8 h, dont D25 du 23/09 ≈ 3 h)
- Dépendances : L80-4 ; L40-6 (`PlayerIdentity`, C5) ; L60-2 (types de `game-wire.ts`) et L60-6 (construction PHP de `RevealMovie`, réutilisée telle quelle pour `recap[].titles`, C7, R-24, § 1.4) ; L70-7 (`DisplayTitleResolver`, C11).
- Modifiés : `Scoreboard.php` (`podium()`, composition du récapitulatif, faits marquants). `types/scoring.ts` n'est pas modifié : L80-3 l'a posé en entier, `Podium` compris.
- Tests : `PodiumTest` (7).
- Contenu de l'estimation : `podium()` (classement sur agrégats figés, `PlayerIdentity`, `rankShared`) ; composition du récapitulatif (une entrée par numéro, annulations remplacées dédoublonnées, manches `pending` exclues, `RevealMovie` et `finders` par manche) ; les trois faits marquants (≈ 3 h, D25 du 23/09) ; sept tests.

**L80-6 — Textes, aide et valeur du palier** (J1, 2–4 h, dont D29 du 23/09 ≈ 1 h)
- Dépendances : L80-1 ; socle 90 qui crée `lib/game/round-timeline.ts` (C16) ; socle i18n (05, existant) ; C18 (100 [J1]) : bloc `test` de `vite.config.ts`, script `npm run test` et `tests/Frontend/**/*.ts` dans `tsconfig.json`, absents au commit `d167a6a`. Correctif de parité de `translateChoice` demandé à 05 (§ 1.4) : L80-6 n'en dépend pas pour être livré — tant qu'il manque, `:count` s'affiche brut (§ 15.1).
- Modifiés : `lang/fr/game.php`, `lang/en/game.php` (clés J1 du § 15.2, colonnes du classement comprises) ; `resources/js/lib/game/round-timeline.ts` (`tierValueAt`) ; `resources/js/types/translations.d.ts` régénéré par `php artisan lang:types`, jamais édité. `resources/js/lib/i18n.ts` n'est **pas** modifié (C15 § 2.1).
- Tests : `TranslationCoverageTest` [existant] ; Vitest `round-timeline.test.ts` (1).

**L80-7 — Composants de classement, de récapitulatif et de podium** (J1, 5–7 h)
- Dépendances : L80-3, L80-5, L80-6 ; L60-9 (assistant client de rendu des titres de `RevealMovie`, § 11.4 et § 1.4) ; socle 90 (`GameLayout`, composants d'état, liste close, règles de focus, C16) ; `player-avatar.tsx` (L40-6, C5) ; exigence du § 1.4 sur la liste close ; C18 (100 [J1]) : bloc `test` de `vite.config.ts`, script `npm run test` et `tests/Frontend/**/*.ts` dans `tsconfig.json`.
- Créés : `resources/js/components/game/standings-table.tsx` (lignes du classement : avatar, pseudo, rang ordinal ou « — », score, delta, statut, entrée tardive ; primitives `table`, `badge` ; un `<TableHeader>` dont chaque `<TableHead>` porte une clé `game.leaderboard.column.*`, et un `<caption>` égal à `game.leaderboard.title`, ou `game.podium.title` au podium : un tableau de données sans en-têtes ne se lit pas au lecteur d'écran, principe 8) ; `round-recap.tsx` ; `podium-highlights.tsx` ; `podium.tsx` (en-tête terminée ou interrompue, classement, faits marquants, récapitulatif) ; `resources/js/lib/game/scoring-format.ts` (catégorie ordinale et table de clés, durée, découpage autour de `:title`). Aucun composant ne lit Echo ni l'horloge : props seulement (C16 § 2.9).
- États : chargement (`LoadingState` tant que le podium attendu n'est pas arrivé par `game.ended` ou la resynchronisation) ; erreur (`ErrorState`, « réessayer » = resynchronisation) ; déconnexion (`ConnectionBanner` de 90, C16 ; podium rejoué à l'identique par la resynchronisation de 60) ; clavier (focus sur le titre du podium au montage, C16 § 2.12 ; tableau parcouru au clavier ; glyphe « — » en `aria-hidden="true"`, doublé du texte accessible `game.leaderboard.unranked` en `sr-only`, § 8.2).
- **Portrait d'abord** (règle 10, principe 5) : à 360 px de large, chaque ligne montre sur une rangée le rang (ordinal ou « — »), l'avatar, le pseudo et le score ; le delta, les bonnes réponses, le temps cumulé, le statut et l'entrée tardive passent sur une seconde rangée de la même ligne, jamais en colonnes supplémentaires, leurs en-têtes restant présents en `sr-only` ; aucun défilement horizontal ; le desktop élargit en colonnes. Le classement n'a pas de plafond de lignes (§ 8.5) : sept colonnes côte à côte déborderaient à cette largeur.
- Tests : Vitest `scoring-format.test.ts` (3). Si C18 configure un environnement DOM pour Vitest, ajouter `le podium place le focus sur son titre au montage`.
- Variable d'ajustement : aucune. Depuis D35 du 23/09 (J1 complet), les variables d'ajustement de développement (recadreur minimal, retardataires) sont sans objet : D17 du 23/09 n'a plus d'effet, les retardataires sont livrés au J1 et l'affichage de l'entrée tardive (`game.leaderboard.late_joiner`, § 8.5) y est atteignable. Le nombre de films du J1, réglé par le verdict du pilote (D10 du 23/09), relève de la curation et ne porte pas sur 80. — amendé le 23/09

**L80-8 — Mode sans score à l'écran et « attendre paie »** (J2, 3–4 h)
- Dépendances : L80-7 ; onglet Avancé (50, J2). Les deux masquages du § 14 (`game.round.tier_value` en mode sans score) et du § 15.2 (ligne `game.help.scoring.speed_bonus`, bonus désactivé) sont portés par 60 et 90 au J2 (§ 1.4), pas par ce lot.
- Modifiés : `ScoringRules.php` (`waitingPays()`) ; `standings-table.tsx`, `podium.tsx` (affichage sans score) ; `lang/{fr,en}/game.php` (`game.podium.scoreless`, `game.help.scoring.scoreless`).
- Tests : `ScoringRulesTest` (ajout J2) ; `PodiumTest`, ajout : `le podium d'une partie sans score met en tête les bonnes réponses puis le temps`.

**L80-9 — Rapport d'écarts du rejeu** (J2, 2–3 h)
- Dépendances : L80-2 ; écran « inspecter une partie » (20, J2).
- Modifiés : `ScoreReplayer.php` (`mismatches()`).
- Tests : `ScoreReplayTest` (ajout J2).

**Totaux** : jalon 1, **27 à 41 h** (L80-1 à L80-7) ; jalon 2, **5 à 7 h** (L80-8 et L80-9). Ces heures sont des mesures de taille, jamais un calendrier ni un budget à tenir (D36 du 23/09 : le développement est confié à l'IA) ; la taille globale du jalon 1 est consolidée par 00 § Jalons à partir des sections « Lots » de toutes les specs. — amendé le 23/09

---

## 21. Ce que cette spec ne décide pas

| Sujet | Spec propriétaire |
|---|---|
| Colonnes, types, index, rétention et ordre de purge des faits de partie ; la formulation définitive d'E10-44 (« 396 au plafond », et non « aux défauts ») | `10-catalogue-et-modele-de-donnees.md` |
| La classe `PlatformLimits` — dont l'écriture du changement B_max et de ses tests (L50-1) —, `config/game.php`, `RoomSettings`, les bornes, l'onglet Avancé, la règle D34 du 23/09, les codes et textes d'avertissement du barème (`room.warnings.*`) ; l'alignement de `tierGraceMs` et `preloadLeadMs` sur la note 2 du 23/09 ; l'adoption éventuelle d'un avertissement fondé sur `waitingPays()` (J2) | `50-salon-reglages-presets-et-lobby.md` |
| Événements, canaux, enveloppe, paquet de resynchronisation ; transitions de manche, pause et clôture après 15 min ; annulation d'une manche et moment du gel quand aucune ne reste ; mécanisme de reprise d'une partie bloquée ; contenu de la révélation ; gestes du solo, relance d'un solo en cours et N ramené d'office ; assistant client de rendu des titres et construction PHP de `RevealMovie` (lots L60-9 et L60-6 demandés au § 1.4), ordre de livraison commun de L60-2 et L80-3 ; masquage de la valeur du palier en mode sans score (J2) | `60-moteur-de-partie-temps-reel-et-mode-solo.md` |
| Fenêtre d'acceptation, transaction de verrouillage, allocation de `lock_rank`, refus d'un clic avant l'ouverture du QCM, normalisation et validation, texte de `game.help.prefix` | `70-validation-des-reponses.md` |
| Tirage, manches de réserve, remplacement d'une manche annulée, usage du contexte de graine des variantes | `30-themes-vivier-et-tirage-des-variantes.md` |
| `PlayerIdentity`, avatars et masquage du pseudo (J1 : identité invitée ; J2 : branche provider et masquage) ; les quatre compteurs, leurs requêtes, leur cache et l'écran d'historique (J2) — dont la question, ouverte, de compter ou non une partie sans score dans « meilleur score » | `40-comptes-auth-sociale-et-avatars.md` |
| Coquilles, liste close des composants (et l'inscription des quatre composants de L80-7), écran d'aide et sa place — dont le masquage de la ligne du bonus quand il est désactivé (J2) —, présentation du détail `pointsTier` / `pointsBonus`, repli visuel des lignes « parti » | `90-ecrans-etats-et-structure.md` |
| Parité de `translateChoice` avec `Translator::choice` dans `resources/js/lib/i18n.ts` (§ 1.4, écart à C15 § 2.1 signalé au porteur) ; **question ouverte** : une règle typographique française commune à tous les dictionnaires (espace insécable U+00A0 ou fine U+202F avant « : ; ! ? % » et à l'intérieur de « »), qui s'appliquerait à tous les domaines à la fois, jamais au seul domaine `game` (§ 15.1) | `05-i18n-et-langues.md` |
| Écran « inspecter une partie » et sa mise en forme (J2) | `20-back-office-curation.md` |
| Ordonnancement de la clôture `stale_game`, sondes de production, placement des tests `locks-timing` et leur outillage (suite `Concurrency`, `tests/Pest.php`, `tests-mysql.yml`), jeu de données de la matrice des réglages, configuration de Vitest et son environnement DOM, fichier `tests/Frontend/i18n/translate-choice.test.ts` (L100-3) | `100-qualite-tests-et-ci.md` |
