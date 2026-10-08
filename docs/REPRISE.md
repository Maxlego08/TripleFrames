# Reprise — TripleFrames

**Session du 08/10/2026 — vérification et commits des vagues V0 à V3** : `composer ci:check` rejoué (5 191 tests Pest hors `mysql`/`locks-timing`) ; deux corrections : `PlayHistory` assemblait sa ligne de détail par affectations `->final_score = …` et un `'status' =>` terminal, lus par `ScoringWritersTest` comme des écritures hors de `FinalizeGame` (ligne bâtie désormais par arguments nommés, `endedStatus()`), et le manifeste Vite ne connaissait pas encore `settings/history*` (`npm run build`). Tout est commité, un commit par lot ; les commits intermédiaires ne sont pas garantis verts isolément (fichiers partagés rangés avec le dernier lot qui les touche). Ensuite : migrations `100059`, `100061` et `100063` jouées en dev ; `composer test:mysql` vert (22, base `tripleframes_test` et Redis de Homestead) ; **V1 close** — suspension, BO-A et advanced-tab livrés en trois branches (entrées ci-dessous), fusionnés dans `develop`, `composer ci:check` vert après fusion (5 293 Pest, 165 Vitest, `TmdbBoundaryTest` compris). **Prochaine action** : relecture du porteur (textes listés dans chaque entrée), essai à 360 px de l'onglet Avancé, puis vague V2 suite (takedown, themes-saved-configs, SC-2).
- **V1 — advanced-tab, onglet Avancé et volet Avancé de L60-17 (L50-10, `50` § 3.3 ; `60` § 2.5 et § 11.3), commité sur `j2/advanced` (deux commits)** : `RoomSettingsEditor::advanced()` (rien de dérivé, `D = Σ tierDurations`, `roundDuration` → `not_editable`, `list_size` sur une liste de mauvaise taille, `reset` du barème personnalisé quand `N` change, même rattrapage de capacité que l'onglet Simple), `ADVANCED_TAB_AVAILABLE = true`, aiguillage `edit()` (`advanced: false` → `simple()`, qui réégalise et rapporte `equalized`) ; `customizedAdvancedFields()` et `overwritten()` (rapport des presets, réutilisable par `LoadSavedConfig`, L50-13) ; remède `disable_no_repeat` rendu ; **`advancedActive`** ajouté à `RoomSettingsState` (`settings.changed`, `room.replayed`) pour le bandeau `room.settings.advanced_active` — choix de forme : calculé au serveur, seul détenteur des défauts constants ; avertissement **`waiting_pays`** (n° 44) : `ScoringRules::waitingPays()` livré ici (part de L80-8), levé bonus actif seulement et jamais en plus de `non_decreasing_points` ; jeu partagé `derivations.json` étendu (`speedBonusMaxPercent`, `speedBonus` des cas d'avertissement). Client : onglets ARIA à activation manuelle, champs communs au-dessus des onglets, feuille plein écran sur mobile, `components/room/{advanced-settings-form,advanced-active-banner,setting-fields}.tsx`, `advancedFramesPerRoundChange()` et `warnings(bounds, limits, view)` de `lib/room-settings.ts`. Clés FR+EN : `room.settings.{tabs.*,advanced_sheet.*,advanced_active,tier_label}`, `room.warnings.waiting_pays` (**relecture du porteur**). L60-17 volet Avancé : `visibleTierValue()` masque la valeur quand `leaderboard.scoreless`, drapeau posé à `game.launched` depuis le barème des réglages du lobby ; tests `RoomSettingsAdvancedTest`, `EventPayloadTest` (charge Avancée), Vitest `store.test.ts`. **Restent dus** : L50-11 à L50-13, le reste de L60-17 (annulation active, `SeatView`), l'affichage sans score de L80-8, L90-15 ; vérification à la main des onglets et de la feuille à 360 px. **Piège d'environnement** : dans un worktree dont `vendor` est une jonction, le classmap optimisé charge `App\`, `Tests\` et la base de l'application **du dépôt principal** ; les tests n'ont été joués ici qu'avec un chargeur préfixé (`auto_prepend_file`), `APP_BASE_PATH` et une copie du lanceur Pest sous `storage/framework/` (non commitée) ; `TmdbBoundaryTest` (`arch()`) n'y tourne pas.

- **V1 — suspension, conservatoire et levée (L20-20, volet suspension de L60-17, reste de L20-39 ; `20` § 11.2 et § 11.6, `60` § 15.4), commité sur `j2/suspension` (un commit par lot)** : `SuspendMovie` (images `published` seules en cascade, sans ligne par image, `seen_frame` supprimées, `movie.suspended`, projection et ambiguïté recalculées, `WithdrawContentFromLiveRounds` après commit), `UnsuspendMovie` (état antérieur lu dans le journal par `App\Support\Curation\SuspensionHistory`, images de la cascade rendues selon la revue, garde du § 8.1 rejouée : empreinte d'aperçu exigée pour un retour `published`, sinon `movie.unsuspended` puis `movie.unpublished` au motif `admin.movie.unsuspend.guard_failed`), `SuspendFrame` / `UnsuspendFrame` (image `published` seulement ; levée refusée tant que le film est suspendu) ; `MoviePolicy::suspend|unsuspend`, `FramePolicy::suspend|unsuspend` (administrateur seul) ; quatre routes `admin.catalog[.frames].suspend|unsuspend` ; fiche (`unsuspension`, aperçu d'ambiguïté réutilisé) et banque d'images (`frames[].abilities`) ; boîte partagée `optional-reason-dialog.tsx`. Moteur : `App\Jobs\Game\WithdrawContentFromLiveRounds` (`ShouldQueueAfterCommit`, file `game` ; partie en pause non touchée, la frappe de la reprise refusant l'image), `RoundIncidentReason::MovieSuspended`. File des signalements : `SuspendReportedMovie` / `SuspendReportedFrame`, routes `admin.content-reports.suspend-movie|suspend-frame`, issues `movie_suspended` / `frame_suspended`, boutons administrateur seul. `nickname: null` était déjà livré par L40-12 ; ajout du test `EventPayloadTest` correspondant. Tests : `Curation/SuspensionTest` (12), `Game/LiveWithdrawalTest` (2), `ContentReportQueueTest` (+2), `AuthorizationMatrixTest` (+1), six lignes `AdminRoutes`, `FrameBankTest` ajusté. **Aucune migration** (règle 12 non touchée : `movie` et `frame` reçoivent seulement des valeurs d'enum existantes). Contrôles : Pint, PHPStan, `npm run check`, `types:check`, Vitest verts ; Pest hors `mysql`/`locks-timing` : 5 202 tests verts hors `tests/Feature/Architecture`, joué fichier par fichier (vert, hors `TmdbBoundaryTest`, voir ci-dessous). **Environnement** : `vendor` étant une jonction, l'autoload de Composer et `Application::inferBasePath()` pointent le dépôt principal ; les contrôles PHP du worktree ont tourné avec un `auto_prepend_file` (chargeur `App\`/`Tests\`/`Database\` vers le worktree, `APP_BASE_PATH`) et un lanceur Pest à racine corrigée, hors dépôt ; `Architecture/TmdbBoundaryTest` (arch, intouché) ne s'y charge pas — à rejouer dans le dépôt principal. **Reste de V1** : BO-A et advanced-tab (dont la charge Avancée de `settings.changed` et le masquage sans score de `visibleTierValue()`, reste de L60-17).
- **V1 — BO-A, commité sur `j2/boa` (un commit par lot)** : **L30-10** — `App\Support\Catalog\MovieDifficultyDeriver` (décile de `vote_count` par langue originale, seau commun sous `catalog.difficulty.min_language_sample` = 20, bonus de saga `saga_decile_bonus` = 2, cinq classes de deux déciles ; bornes refusées par `InvalidArgumentException`, jamais écrêtées ; démonstration et films retirés hors population ; `movie_difficulty_derived` puis `movie_difficulty = COALESCE(override, derived)` relu en base sous le verrou du film, thèmes de difficulté réévalués dans la même transaction) ; job `DeriveMovieDifficulty` (file par défaut, `ShouldBeUniqueUntilProcessing` et non `ShouldBeUnique` — forme livrée, `30` § 14.2), dispatché après commit à la clôture de tout balayage (`CatalogImportCommand::closeRun()` : balayage, collage, resynchronisation, jamais une suspension), par `CreateTheme` / `UpdateTheme` sur une saga, et une fois par passage de `PlatformDataSeeder` qui insère une saga ; `catalog:themes` dérive après l'instantané puis évalue. Le dispatch sur une transition vers ou depuis `withdrawn` revient au lot du retrait juridique (aucun geste ne l'écrit encore). **L20-24a** — `TmdbQuotaLimiter` singleton à calendrier partagé en cache (créneaux en microsecondes sous verrou atomique) : balayages derrière, appels interactifs (`TmdbClient::get()` → `admit()`) en priorité et repoussant les balayages d'un créneau ; verrou muet = espacement local seul. **L20-29** — `IncidentReport` (manches terminées jamais trouvées, annulations par motif, substitutions par motif, chacune datée par son propre instant ; jamais `round_player`, `guess` ni `player`), `admin.incidents.index` (ligne 29), page `admin/incidents/index`, entrée « Incidents », `catalog.curation.incidents_window_days` = 30. **L20-32** — `CurationClaim` (`curation:claim:{movieId}`, 15 min, jamais volée), prise à l'ouverture de l'éditeur (pas au rechargement partiel) et par « Film suivant » (premier film réservable, réservé atomiquement), prolongée par le battement ; la file affiche « En cours chez <nom réel> ». **L80-9** — `ScoreReplayer::mismatches()` (manches annulées comprises, tri `sequence_index` puis `lock_rank`), carte « Rejeu des scores » de l'inspection d'une partie, `null` tant que la partie n'est pas close (règle 3). **Aucune migration**, aucune colonne : `movie_difficulty_derived` et `movie_decile_idx` existaient. Tests : `Draw/DifficultyDerivationTest` (19), `Tmdb/TmdbQuotaTest` (5), `Admin/IncidentsTest` (8), `Admin/CurationClaimTest` (10), ajouts à `ThemeAdminTest`, `CatalogImportCommandTest`, `ScoreReplayTest`, `GameInspectionTest`, matrice ligne 29. Contrôles : Pint, PHPStan niveau 7 (0 erreur), `npm run check`, `npm run types:check`, Vitest 162/162 ; Pest hors `mysql`/`locks-timing` **5 240/5 240** (114 501 assertions), joué sans `--parallel` par un lanceur Pest local au worktree (`vendor` en jonction vers le dépôt principal) ; `Architecture/TmdbBoundaryTest` exclu de ce passage : il plante à l'identique sur le commit de base `0609cec` dans ce montage (trait Symfony absent lors du balayage d'`arch()`), à rejouer dans le dépôt principal. **Gestes humains** : `catalog:themes` une fois après le déploiement (déjà listé § 3) ; relire `admin.incidents.*`, `admin.curation.claimed_*`, `admin.inspection.game.replay.*`. V1 reste NON close pour **suspension** et **advanced-tab** seulement.

**Session du 07/10/2026 (suite 3) — jalon 2, vague V0 : specs seules, NON commitées** : le porteur demande d'implémenter tout le J2 listé ; ses réponses aux cinquante et une questions fermées sont consignées en **D66 du 07/10** (`questions-ouvertes.md`) : toutes les recommandations retenues, **sauf n° 23, rattachement automatique** d'un siège invité au compte connecté ; n° 10 = A (TOTP obligatoire des rôles privilégiés) ; dormance 24 mois + 30 jours confirmée ; suppression refusée pendant une partie en cours. Specs écrites ou amendées, **aucune ligne de code** : `40` § 13 (sujets 1, 5, 6, 7, 8, 9, 10, 11, 14, 15 ; lots L40-10 à L40-18, 29 à 44 h) ; `100` § 18 à § 28 (préproduction, Playwright, sous-traitants, levée du `noindex`, violation, inventaire des accès, arrêt du service, purge complète et sondes, restauration rejouée, `dev.<DOMAINE>`, licences ; lots L100-15 à L100-20 détaillés) ; `90` § 11.5, § 11.7 à § 11.10 (pages légales opposables — structure seule —, canonique, Open Graph, fermeture, crochet de compte, sans score et aide, légende des titres originaux ; lots L90-12 à L90-16) ; amendements `20` § 11.2 à § 11.6 et matrice (lignes 35, 48, 49 ; route `admin.moderation.avatar.unhide` retirée, suspension depuis la file), `60` § 9.4 et `70` L70-12 (crochet `RoundAnswersClosed` retiré), `70` § 13.2 (limiteur atomique, 1-2/s, 5-30, `maxAnswerLength` ≥ 60, `K` = 5 ; version 3 si le barème change), `05` (canonique sur les quatre routes indexables), `80` § 13 (partie sans score hors du meilleur score), `30` (seuil du sélecteur ≈ 60 en production) ; exigences inscrites à `10` (`player.nickname_reports_from`, `saved_config.public_id`, **`game.public_id`** — exigence nouvelle, signalée —, `dormancy_notified_at` confirmée, disque `exports`, quatre cas de journal, motif obligatoire de `nickname.banned`, issues `movie_suspended` / `frame_suspended`, `takedown_identity` à 5 ans). `j2-decisions-proposees.md` archivée. `CLAUDE.md` § 3 et § 9 alignés. **Suite** : vagues V1 à V6 du plan (code), chaque lot amendant sa spec propriétaire. Gestes humains : § 3, « Gestes humains du J2 ».
- **V1 — OPS-A, préproduction et parcours Playwright (L100-15, L100-16, `100` § 18 et § 19), NON commité** : gabarits `ops/` de préproduction (Redis dédié `tripleframes-preprod-redis`, workers `tripleframes-preprod-worker@{game,default}` et Reverb `tripleframes-preprod-reverb`, toujours actifs, plafonds plus serrés et `CPUWeight` 10 ; directives nginx `additional-directives.preprod.conf` à authentification HTTP au niveau du serveur, Reverb compris ; `.env` de référence `ops/preprod/env.reference`, `APP_ENV=staging`) ; `ops/mise-en-service.md` § 12 (mise en service et chaîne de promotion) ; hook inchangé, prouvé commun aux deux abonnements ; job `artifacts` de `tests.yml` publie désormais **`deploy-preprod`** (premier parent = pointe de `deploy`, pour que toute promotion reste une avance rapide) ; workflow manuel **`promote.yml`** + `ops/deploy/promote.sh` : `deploy` avancée sur le **commit exact** saisi (40 caractères, relu dans Plesk Git de la préproduction), refus d'un nom de branche, d'un commit hors de `deploy-preprod` ou d'un retour en arrière, case « parcours joués » obligatoire, `contents: write` sur ce seul job (`ZeroSecretTest` exempte `artifacts` et `promote`) ; `DatabaseSeeder` pose en `staging` le **seul** catalogue de démonstration, signé par deux comptes **inouvrables** (`PreprodCurationAccountsSeeder` : mot de passe aléatoire jeté, adresse `.test` non vérifiée) — les comptes de démonstration gardent leur propre garde `local`/`testing` ; `@playwright/test` 1.63.0 épinglé, `playwright.config.ts` (cible `E2E_BASE_URL` refusée en CI et hors `preprod.*`, `tests/Browser/support/target.ts`), trois parcours `tests/Browser/*.spec.ts` en `mobile-360` (clavier simulé) et `desktop`, `npm run test:e2e`, mode d'emploi `tests/Browser/README.md`. Tests : `PromoteWorkflowTest` (dont le script joué contre un dépôt git jetable), `DemoCatalogueSeederTest`, `BrowserJourneysTest`, `DeployHookTest` et `OpsTemplatesTest` étendus, Vitest `tests/Frontend/browser/target.test.ts`. **Parcours jamais joués ici** (aucune préproduction ; le `127.0.0.1:8000` du poste servait une autre application) : la première série sur la préproduction est leur vraie preuve, sélecteurs à ajuster si un libellé diverge.
- **V1 — PK-C, écrans de compte sortis du starter (L90-17, `90` § 11.2), NON commité** : réécrits aux tokens et sortis d'`EXEMPT` pour `WATCHED` : `settings/{profile,security}`, `auth/{reset-password,two-factor-challenge}`, `input-error` (classe `input-error` + `text-destructive`, sélecteurs SCSS `auth/_forms.scss` et `settings.scss` réaccrochés), `text-link`, `heading` (prop `id`), `alert-error`, `password-input` (bascule 44 px, `aria-controls`), composants 2FA (QR code nommé à fond clair par la feuille, plus de filtre ni de `bg-white`) et passkeys (vraie liste, suppression nommée), `use-two-factor-auth`, `use-clipboard` ; erreurs reliées par `aria-describedby`, sections nommées, cibles `min-h-11`. Supprimés (zéro importateur) : chaîne morte de la barre latérale et de l'en-tête du starter (`app-sidebar`, `app-header`, `app-shell`, `app-content`, `app-sidebar-header`, `app-logo`, `nav-main`, `nav-user`, `nav-footer`, `user-info`, `user-menu-content`, `layouts/app/*`, `auth-card-layout`, `auth-split-layout`, `use-mobile-navigation`). Clés nouvelles FR+EN : `account.two_factor.setup.{code_label,key_label,qr_label}`, `account.passkeys.remove_named`. Test ajouté à `ThemeTokensPerimeterTest`. Dette n° 24 soldée ; `dashboard` déjà retiré. Geste humain : test à 360 px sur téléphone (déjà listé § 3).
- **V1 — LS-A, canonique, Open Graph et garde des pages provisoires (L90-12 partiel, `90` § 11.5, `100` § 21), NON commité** : `LegalPage::isProvisional()` (seule lecture de `legal.pages.*.provisional`, reprise par `LegalPageController`), `routeName()` / `fromRouteName()` ; `RobotsDirectives::routeIndexable()` (variable levée, drapeau, page légale non provisoire) partagée par le middleware et la canonique : **une page légale provisoire reste `noindex` même `SITE_INDEXABLE=true`** (n° 19) ; `App\Support\Http\PageMeta`, injecté dans `app.blade.php` : `<link rel="canonical">` sur l'accueil et les trois pages légales seulement, une fois indexables, bâtie sur `APP_URL` et l'URI de route (ni chaîne de requête, ni hôte de la requête) ; description, `og:site_name`, `og:type`, `og:title`, `og:description`, `twitter:card` génériques sur toutes les pages, `og:url` avec la canonique seulement, aucune image (n° 47) ; clés `common.meta.{title,description}` FR et EN. Tests : `IndexingTest` (canonique, Open Graph), `SiteIndexingTest` (page provisoire), `LegalPagesTest` (aucun « [À FOURNIR » sur une page non provisoire). **Reste de L90-12** : `App\Support\Legal\LegalFacts` et son test, à livrer avec l'intégration des textes (V6), une fois `RetentionWindows::DATA_EXPORT_DAYS`, la dormance et `takedown_identity` en place. La levée elle-même reste un geste (`100` § 21).
- **V1 — SC-1, `App\Support\Format` et clés `common.player.*` / `common.closure.*` (05-O, 05-P, `05` § Nombres point 2, `90` § 6.4 et § 11.7), NON commité** : `Format::{number,seconds,date,dateTime}` sans `ext-intl`, séparateurs et motifs de date lus dans le registre `App\Enums\Locale` (`decimalSeparator`, `thousandsSeparator` — U+00A0 en français —, `datePattern`, `dateTimePattern`, fuseau affiché), locale de l'application par défaut (repli `app.fallback_locale` puis anglais), jamais un zéro négatif, jamais de mutation d'une date reçue ; consommateurs à venir : e-mails et export (V4). Clés FR/EN `common.player.{masked_notice,report.{action,confirm_title,confirm_body,sent}}` (sans consommateur avant nickname-moderation, V2 ; `common.player.masked` reste à elle) et `common.closure.{title,body,legal_link,report_link}` (consommées par SC-3) ; `lang:types` relancé. Tests : `tests/Feature/I18n/FormatTest.php` (9, dont la garde « jamais consommée par le back-office ni par une page » sur `app/Http`, `app/Support/Admin`, `routes`). Geste humain : relecture des textes de `common.closure.*` (engagement de retrait, `90` § 11.7).
- **V1 — signup-consent, ouverture des comptes (L40-10, `40` § 13.1), NON commité** : `RecordConsents` (seul écrivain : lignes `user_consent` `terms` et `age` à `legal.terms_version` et projections de `users`, dans la transaction de création, partagé par `CreateNewUser`, `CreateOAuthAccount`, `LinkProvider` et la ré-acceptation ; **verrou de la ligne `users` du compte avant toute lecture**, pour qu'un double envoi garde la première preuve au lieu de violer `user_consent_user_kind_version_uq`) ; intergiciel `EnsureTermsAccepted` (alias `terms.current`, pages de compte seules, jamais le jeu ni `/admin`), interstitiel `auth/terms-update` (`terms.show` / `terms.accept`, `TermsAcceptanceController`, `TermsAcceptanceRequest`) ; limiteur `register` ; règle de pseudo de jeu sur `users.name` (`ProfileValidationRules::nameRules()`, sans unicité par salon) ; trait `ConsentValidationRules`, composant `ConsentFields` ; clés `account.oauth.finish.{terms,terms_link,age,terms_required,age_required}` supprimées au profit d'`account.consent.*`. Tests : `Auth/RegistrationTest`, `Auth/TermsReacceptanceTest`, `Concurrency/Auth/TermsAcceptanceConcurrencyTest` (`locks-timing`).
- **V1 — clôture de vague (revue), NON commité** : `admin:first-admin` ne compte plus l'administrateur inouvrable semé en `staging` comme administrateur en place (`App\Support\Preprod\PreprodAuthors`, adresses partagées avec `PreprodCurationAccountsSeeder`) : l'administrateur de recette se crée **sans `--force`** après `db:seed --force` (`ops/mise-en-service.md` § 12, tests `Admin/FirstAdminCommandTest`) ; `common.player.masked_notice` au vouvoiement du jeu ; `CLAUDE.md` § 3 aligné sur `deploy-preprod` et `promote.yml`. **V1 N'EST PAS CLOSE** : trois sujets n'ont produit ni code ni spec — **suspension** (livrée le 08/10, entrée « V1 — suspension » en tête de la session du 08/10) (L20-20, volet suspension de L60-17, reste de L20-39 : `SuspendReportedMovie` / `SuspendReportedFrame`, issues `movie_suspended` / `frame_suspended`), **BO-A** (L20-24a, L30-10 `DeriveMovieDifficulty`, L20-29, L20-32, L80-9) et **advanced-tab** (L50-10 : `RoomSettingsEditor::ADVANCED_TAB_AVAILABLE` vaut toujours `false`). Ils sont à rejouer **avant** V2 : BO-B et takedown dépendent de la suspension (et takedown de L30-10), themes-saved-configs et SC-2 de l'onglet Avancé.
- **V2 — seat-claim, rattachement automatique d'un siège invité (L40-11, `40` § 13.2, D66 du 07/10 n° 23 à n° 25), NON commité** : `App\Actions\Identity\ClaimSeatForAccount` (gardes relues sous `lockForUpdate` salon puis siège, en silence : salon non archivé et hash présent, siège non expulsé, `user_id` nul, compte non anonymisé, aucun autre siège du compte dans le salon ; écrit `user_id` et `locale`, jamais `nickname` ni `game_player`) appelé au rendu de `room.show` et `solo.show` par le trait `Room\Concerns\AttachesSeatToAccount` (ce seul siège ; avis `room.seat.claimed` FR+EN en flash `toast`, rendu par `GameLayout`) ; avatar : bascule immédiate sur l'image téléversée visible au lobby (`seat.updated`), marque de cache `seat-claim:avatar-pending:{id}` en partie ou au podium (durée `RoomExpiry::archiveDeadlineFrom()`), consommée à l'étape **R9** nouvelle de `ReplayRoom` (seconde transaction sous le verrou du salon, `50` § 13) ; solo sans bascule ; `App\Support\Account\PlayHistoryCache` (clé du cache de l'historique, oubliée au rattachement — **history (V3) doit lire cette clé**). Aucun écouteur de connexion, aucune route, aucune migration. Tests : `tests/Feature/Identity/SeatClaimTest.php` (8). Geste humain : relecture de `room.seat.claimed` ; la page de confidentialité doit dire le rattachement automatique et son résidu d'appareil partagé (`90` § 11.5, déjà listé).
- **V2 — nickname-moderation, signalement et modération du pseudo (L40-12, L20-31 ; `40` § 13.3, `20` § 11.5), NON commité** : migration additive `2026_10_07_100061_add_nickname_reports_from_to_player_table` (`player.nickname_reports_from`, `#[Hidden]`) ; `ReportSeatNickname` (seuil 2 sièges distincts depuis la fenêtre, verrou du siège visé, `nickname.masked` sous `system`, `seat.updated` après validation, retour neutre), route `room.players.report_nickname` (`seat.active`, `game-write`, limiteur `seat-report` par siège, débit `game.room.seat_reports_per_minute`) ; `UnmaskNickname` (fenêtre remise à maintenant, refusée pour un banni) et `BanNickname` (motif obligatoire par `requiresReason()`, masquage définitif, forme jamais au journal), état « banni » lu dans le journal (`App\Support\Moderation\NicknameModerationState`), diffusion partagée `NicknameSeatBroadcast` ; `PlayerPolicy::moderateNicknames|moderateNickname` ; écran `admin/moderation` (`ModerationController`, `ModerationNicknameController@unmask|ban`, `ModerationReasonRequest`, `ModerationBanRequest`, entrée « Pseudos » du menu admin, trois lignes `AdminRoutes`) avec les formes à recopier ; `resources/moderation/nicknames/banned.txt` (vide, en-tête) lu par `NicknameBlocklist::BANNED_FILE` ; front : « Signaler le pseudo » sur la liste des sièges (lobby et feuille « Joueurs » en partie), libellé masqué « Joueur n » partout (`lib/game/player-label.ts`, `PlayerOrdinalsProvider`, rang = ordre reçu de `state.seats`), avis `common.player.masked_notice` au seul siège masqué ; clés `common.player.masked`, `common.player.report.not_reportable`, `admin.moderation.*`. Tests : `Room/NicknameReportTest`, `Admin/NicknameModerationTest`, Vitest `game/player-label`.
- **V2 — BO-B, resynchronisation, clôture d'un balayage, grille rétroactive, candidats de regroupement, difficulté corrigée (L20-24b, L20-25, L20-27, L20-28b ; `20` § 3.7, § 3.8, § 7.7, § 9.4, § 9.6), NON commité** : resynchronisation à l'écran (`MovieResyncController`, `admin.catalog.resync.show|store`, `MoviePolicy::resync`, page `admin/catalog/resync`) — écran de différences d'un film par écriture appliquée puis annulée (`MovieImporter::previewResync()`, `ResyncSnapshot`, `ResyncDiff`), alias réapparus, certification restrictive proposée à la dépublication (`?propose=unpublish` sur la fiche, motif pré-rempli), lot depuis la page du catalogue, lancement en file d'un balayage `resync` (`ImportLauncher::openResync()`, `RunCatalogImport::resync()`), jamais d'appel TMDB dans le POST ; `movie.resynced` par film qui change, signé de l'auteur du balayage, dans la transaction du film (désormais verrouillé) ; clôture d'un balayage (`ImportAbandonController`, `admin.import.abandon`, `ImportLauncher::abandon()|isAbandonable()`, `import.abandoned`) ; geste rétroactif de grille (`ApplyRetroactiveGrid`, `RetroactiveGrid` injectable, `ExclusionGridRetroactiveController`, `admin.exclusion_grid.retroactive.show|store`, `FramePolicy::applyRetroactiveGrid`, page `admin/exclusion-grid/retroactive`, entrée « Grille rétroactive ») ; candidats par proximité (`MovieGroupCandidates`, prop facultative `group_candidates`, bloc de l'onglet « Même œuvre ») ; difficulté corrigée (`CorrectMovieDifficulty`, `MovieDifficultyController`, `admin.catalog.difficulty.update`, `movie.difficulty_corrected`, bloc de l'onglet « Identité »). Trois cas du journal (**56 cas**), sept routes et leurs lignes de matrice (25, 26, 28, 33), clés `admin.resync.*`, `admin.import.abandon.*`, `admin.exclusion_grid.retroactive.*`, `admin.movie.{difficulty,resync,unpublish_proposed}.*`, `admin.movie.group.proximity.*`. Tests : `Catalog/ResyncScreenTest` (7), `Admin/ImportTest` (+3), `Curation/GridRetroactiveTest` (6), `Catalog/MovieGroupCandidatesTest` (4), `Catalog/MovieDifficultyCorrectionTest` (5), matrice, `AdminActionTypeTest`. **Reste dû** : BO-A n'est pas livré — le dispatch de `DeriveMovieDifficulty` (fin de resynchronisation, gestes de saga) et l'intitulé « changer la collection d'une saga dispatche … DeriveMovieDifficulty » attendent L30-10 ; le limiteur partagé (L20-24a) aussi. Gestes humains : publier un jour une grille marquée rétroactive (sinon l'écran répond « indisponible », déjà listé) ; relire les textes du back-office.
- **V2 — PK-A, second facteur et passkeys en production (L40-15, `40` § 13.8, n° 10 option A), NON commité** : `config/fortify.php` lit le relying party par `App\Support\Identity\PasskeyRelyingParty` (`PASSKEYS_RP_ID`, réduit à un hôte, repli sur l'hôte d'`APP_URL` ; `PASSKEYS_ALLOWED_ORIGINS`, liste à virgules, repli `[APP_URL]`), deux variables vides dans `.env.example` ; intergiciel `App\Http\Middleware\KeepPrivilegedTwoFactor` (alias `two_factor.keep`, ajouté au groupe de Fortify) : `two-factor.disable` refusé à un rôle ≥ `curator` (`account.two_factor.errors.privileged_required`, 422 en JSON, renvoi avec erreur sinon), ainsi que `two-factor.enable` avec `force` sur un TOTP confirmé (`account.two_factor.errors.privileged_rotate`, secret inchangé), prop `canDisableTwoFactor` de `SecurityController`, bouton remplacé par `account.two_factor.manage.privileged_hint` ; aide partagée `App\Support\Identity\LoginMethods` (déliaison `UnlinkProvider` et suppression de passkey) ; `App\Actions\Account\DeletePasskeyUnlessLastMethod` remplace `DeletePasskey` du paquet dans le conteneur (route et contrôleur de Fortify gardés, verrou du compte, refus `account.passkeys.errors.last_method` affiché dans la boîte de confirmation) ; `Passkeys::authorizeLoginUsing` refuse une pierre tombale (`anonymized_at`) ; la ré-acceptation des CGU après connexion par passkey passe par `fortify.home` (`terms.current`), sans code de plus. `admin.2fa` inchangée (TOTP confirmé seul). Tests : `Auth/PasskeyTest` (8), `Admin/PrivilegedTwoFactorTest` (6) ; aide `passkeyAssertionPayload()` dans `tests/Pest.php` (format WebAuthn réel, vérification simulée). Gestes humains : § 3 (ouverture, changement de téléphone d'un compte privilégié).
- **V2 — clôture de vague (revue), NON commité** : `KeepPrivilegedTwoFactor` refuse aussi `two-factor.enable` avec `force` sur un TOTP confirmé d'un rôle ≥ `curator` (`account.two_factor.errors.privileged_rotate`, FR+EN ; secret et codes inchangés, `admin.2fa` ne croit plus confirmé un secret jamais enrôlé) ; `CatalogImportCommand::resumableRun()` lit et estampille le balayage sous le verrou de ligne pris par `ImportLauncher::abandon()`, et `closeRun()` ne réécrit l'état que si la ligne est encore `running` (une clôture manuelle n'est plus écrasée par la fin du worker) ; `ClaimSeatForAccount` lit sans verrou les gardes durables (siège expulsé, compte déjà assis dans le salon) avant de verrouiller le salon ; `10` § 7.1 et `40` § 13.3 disent vrai sur `nickname_reports_from` (les signaleurs antérieurs à la levée ne signalent plus ce siège). L'avis du rattachement part en flash `game_notice` (jamais `toast` depuis un contrôleur de jeu, `90` § 2.3), lu par `useFlashNotice` ; `SeatAvatarChangeTest` assoit d'abord le compte connecté pour que le siège invité ne lui soit pas rattaché. Tests : `Admin/PrivilegedTwoFactorTest` (+2), `Catalog/CatalogImportCommandTest` (+2), `Identity/SeatClaimTest` (+1).
- **V3 — history, « Mes parties » et quatre compteurs (L40-13, `40` § 13.4, `80` § 13, n° 29 à 31), NON commité** : migration additive `2026_10_07_100063_add_public_id_to_game_table` (`game.public_id`, rétro-remplie, UNIQUE ; table hors règle 12, à migrer en dev avec le reste de la vague) ; `App\Support\Account\PlayHistory` (seul lecteur : fenêtre glissante `historyWindowMonths()`, compteurs multijoueur à `rounds_played ≥ 1`, meilleur score sur `final_score > 0`, taux nul sous `successRateMinRounds()` ; aucune lecture de `guess` hors du détail ; cache court des compteurs 600 s) ; écouteur `ForgetPlayHistoryCache` sur `GameFinalized` ; `GamePolicy::viewHistory` (404, jamais 403) ; `Settings\PlayHistoryController` ; routes `history.index` (`settings/history`) et `history.show` (`settings/history/{game:public_id}`, domaine `game` joint pour `letterboxd-link.tsx`) ; pages `settings/history` et `settings/history-show` (aucune image, aucun pseudo de tiers), entrée « Mes parties » de la navigation des réglages, `lib/account/play-history.ts` (Intl) ; clés `account.history.*` et `account.settings.nav.history` FR+EN ; `WATCHED` étendu. **Reste au lot export (V4)** : rendre le bouton « Exporter mes données » (clé `account.history.export` déjà posée) sous la date de la plus ancienne partie de `settings/history.tsx`, par le helper Wayfinder de son écran. Tests : `tests/Feature/Account/PlayHistoryTest.php` (10), `tests/Frontend/account/play-history.test.ts` (7). Relecture humaine : libellés FR des compteurs et de l'explication du taux.

**Session du 07/10/2026 (suite 2) — seuil de publication des thèmes supprimé (D65 du 07/10), NON commité** : à la demande du porteur, `PublishTheme` ne refuse plus un thème de moins de `DEFAULT_ROUNDS_COUNT` œuvres ; la clé `admin.themes.too_small` est supprimée, la boîte de publication avertit seulement (`below_threshold` réécrit), `threshold` et `description_publish` reformulés. Test `ThemeAdminTest` : « la publication est accordée sous le seuil d’œuvres, sans aucun refus ». Specs : `20` § 9.6, `30` § 12.3, `questions-ouvertes.md` D65.

**Session du 07/10/2026 (suite) — pause manuelle d'une partie (D64 du 07/10), implémentée et NON commitée** : quatre questions fermées, les quatre options recommandées retenues (`questions-ouvertes.md` § D64). (1) Schéma : migration `2026_10_07_100059_add_manual_pause_columns_to_game_table` (`game.pause_kind` `empty`/`manual`, enum `GamePauseKind` ; `game.pause_requested_at` ; `game.manual_paused_ms`), hors règle 12 ; (2) moteur : `RequestGamePause` (immédiate entre deux manches, sinon demandée et rendue effective par `EndReveal`, prioritaire sur la pause `empty`), `CancelPauseRequest`, `ResumePausedGame` → `ResumeGame($by)` qui **ne reprend jamais une pause `manual` au battement** ; seule définition de l'échéance `PauseDeadline::of()` (manuelle : `paused_at + pauseTimeoutMs − manual_paused_ms − launchCountdownMs`), lue par `PauseGame`, `ResumeGame`, `InterruptPausedGame` (3ᵉ argument `deadline`), `RecordHeartbeat`, `CatchUpGame`, `GameStateBuilder`, `game:reschedule` ; siège présent partagé (`SeatPresence`) ; (3) budget **cumulé par partie** (`manual_paused_ms`, attente + décompte de reprise imputés à chaque reprise ; refus `budget_exhausted` si l'attente permise < un décompte) ; partie bloquée : seuil + `manual_paused_ms` ; (4) drainage : demande refusée (`draining`), reprise jamais ; borne `deploy:drain` = `ceil((maxNatural + manualPauseBudgetMs) / 60 000) + 20` ≈ 113 min (au lieu de 98) ; (5) routes `room.game.pause|pause.cancel|resume` (`GamePauseController`, policy `RoomPolicy::pauseGame`/`resumeGame` relue sous verrou du salon : l'hôte, ou tout siège présent si l'hôte ne l'est plus) et `solo.pause|pause.cancel|resume` (`SoloPauseController`, geste = battement) ; 409 `not_running`, `no_round_left`, `budget_exhausted`, `draining` ; (6) fil : `game.pause_requested { requestedAt }`, `game.pause_request_cancelled {}` (21 événements), `game.paused` gagne `kind` ; paquet `pause.kind` + `pauseRequested` ; journal `game` : `kind`, `by` (`heartbeat`/`host`/`seat`/`solo`), demande et retrait ; (7) front : `lib/game/pause-gesture.ts` (`pauseControlOf`, `readPauseGestureFailure`), `hooks/game/use-pause-gesture.ts`, `components/game/pause-button.tsx`, bandeau « Pause demandée » et bouton dans `GameStage` (`pauseControl`), écran de pause enrichi (auteur, temps restant affiché, « Reprendre »), horloge d'affichage qui bat la seconde en pause manuelle, annonces de transition ; clés `game.pause.*` FR+EN ; (8) docs : `questions-ouvertes.md` D64, `60` § 3.1, § 1.2, § 4.4, § 9.6, § 10.1, § 11.3, § 12.1, § 13.1, § 14.1, § 14.1 bis, § 14.2, § 14.3, § 14.4, § 15.2, § 16.5, § 17.3, § 17.4 (échéance partout écrite `PauseDeadline::of()`), `80` § 10.5 (instant `Interrupted`), `10` § 7.2 (colonnes `game`), `90` § 10 (écran), `100` § 11.3 (borne), `config/deploy.php` ; (9) relecture : la demande est aussi consommée par « Passer la manche » solo (`SkipSoloRound`, pause à la clôture de la manche passée) et par l'annulation de la manche jouée (`CancelRound`, pause immédiate au décompte de la suite, sauf révélation en cours) ; « Pause » retiré du client une fois la dernière manche jouée (`pauseControlOf(state, nowMs)`) ; reprise sans partie en cours → 409 `not_running` pour tout siège du salon ; (10) tests : `tests/Feature/Game/ManualPauseTest.php` (12), `EventPayloadTest`/`WireFixtures` (21 événements), `RoundLifecycleTest` (journal), `DeployDrainCommandTest` (borne), Vitest `pause-gesture.test.ts` et `store.test.ts`. **Prochaine action** : relecture du porteur, puis `php artisan migrate` (aucun instantané requis, `game` hors règle 12) et commit.

**Session du 07/10/2026 — signalement de contenu par les joueurs (D63 du 07/10), implémenté et NON commité** : quatre questions fermées, les quatre options recommandées retenues, défauts tranchés listés dans la décision. (1) Schéma : colonne `frame.public_id` (`char(12)` base32 Crockford, générateur partagé `App\Support\Identity\PublicId`, UNIQUE `frame_public_id_uq`, rétro-remplie par la migration) et table `content_report` (film, image servie facultative, `target_key`, raison fermée `ContentReportReason`, texte libre ≤ 1000, signaleur compte **ou** siège, `status`/`resolution`, deux UNIQUE de dédoublonnage, index de file et de purge) ; **`frame` est une table de la règle 12 : `backup:snapshot` (code 0) avant `migrate`** ; (2) charges utiles après la révélation seulement : `RevealMovie.tmdb` (révélation, resynchronisation, récapitulatif du podium) et `round.revealed.frames` (`tierIndex`, `framePublicId` de la variante **servie**), aussi sous `reveal` du paquet d'état ; jamais dans `TierImageRefPresenter`, `round.scheduled` ni `tier.opened` ; (3) page publique `GET`/`POST /report` (`content-report.create` / `.store`, page `report/create`, `PublicLayout`, domaine `game`, `noindex`, limiteur `content-report`) : invité avec siège ou compte, sinon « jouez d'abord une partie » et 403 ; 404 de recevabilité ; « déjà signalé » au second envoi ; image affichée au seul demandeur qui l'a vue (aperçu `content-report.frame`, amendé le 07/10, voir plus bas) ; (4) liens « Signaler » (`components/game/report-link.tsx`, nouvel onglet) : film et chaque image ouverte à la révélation, film seul au récapitulatif du podium ; (5) file back-office « Signalements de contenu » (`admin.content-reports.*`, curateur et au-dessus, `ContentReportPolicy`) groupée par cible, compteur des cibles ouvertes sur la page de la file (pas au menu), gestes dépublier le film (motif obligatoire), dépublier l'image, ignorer (cas `content_report.dismissed`, sujet `content_report`), clôture dans la transaction du geste ; (6) purge `content_report` à 12 mois sur `created_at`. Docs : D63 dans `questions-ouvertes.md` (et « Déjà tranché » *Signalement joueur* amendé), `00` (Fonctionnalités, Hors périmètre, Vocabulaire, Ouverture point 5), `05`, `10` § 1.1, § 2, § 4.1, § 7.10, § 8.1 bis, § 8.3, § 11.1, § 13.2, A15, A16, `20` § 2.1, § 2.2 (ligne 48), § 2.7, § 11.5, § 11.6, L20-39, `60` § 9.5, § 11.3, § 11.5, § 11.7, `80` § 11.4, `90` § 4.1, § 4.5 bis, § 5.3, § 6.3, § 9.3, § 10, L90-11, `CLAUDE.md` § 2 et § 6. **Tests** : page et envoi (`tests/Feature/Public/`), file et gestes (`tests/Feature/Curation/ContentReportQueueTest.php`), `RevealFramesTest`, `FramePublicIdMigrationTest`, matrice ligne 48 (`tests/Datasets/AdminRoutes.php`), `AdminActionTypeTest` (un cas et un sujet de plus), charges utiles (`EventPayloadTest`, `LetterboxdLinkTest`, `PodiumTest`), purge (`RetentionRows`). **Relecture du 07/10** : `ContentReportPolicy::resolve` sans condition sur la cible (un film retiré garde « Ignorer » → `already_handled`) ; limiteur `content-report` par compte connecté, sinon jeton puis IP (D63 précisée) ; avis du formulaire de signalement en `$ink` (`.room-entry-form p:not(.room-entry-notice)`) ; sommaire des pages légales en `<ol lang="fr">`, couvert par `LegalPagesTest`. **Aperçu de l'image (amendé le 07/10, demande du porteur)** : la page `/report` montre l'image désignée au seul demandeur qui l'a vue — compte (siège `user_id`) ou `player_token` courant, siège non expulsé et présent à l'ouverture du palier, avec une participation `round_player` à une manche `revealing`/`completed` dont un palier ouvert a servi cette frame ; ni frame ni film `withdrawn`/`suspended` — prédicat unique `App\Support\ContentReport\FrameViewEligibility`, route d'octets `GET /report/frame/{publicId}` (`content-report.frame`, `ContentReportFrameController`, `throttle:content-report-frame` (seau distinct de `frame-serve`), `FrameImageResponse` sur `game/`, 404 uniforme), prop `frame.imageUrl`, clé `game.report.frame_alt`, style `.report-frame` ; docs D63 (`questions-ouvertes.md`), `10` § 1.1, § 4.1, § 7.10, § 10 (trois lecteurs du disque), `80` § 11.4, `90` § 4.5 bis, table des routes, § 5.3, `CLAUDE.md` § 8 ; tests `tests/Feature/Public/ContentReportFrameTest.php`. **Reste dû** : la politique de confidentialité doit nommer le texte libre d'un signalement conservé 12 mois (à la main du porteur) ; la suspension depuis la file attend L20-20 (J2) ; relecture du porteur.

**Session du 06/10/2026 (suite 4) — suivi des joueurs (D62 du 06/10), implémenté et NON commité** : (1) l'archivage n'efface plus que le hash du jeton ; nouveau périmètre `guest_nickname` qui anonymise pseudo, forme normalisée, pseudo figé, lien au visiteur et appareil 12 mois après `last_seen_at` (`player_last_seen_idx`) ; `orphan_player` n'efface plus que les jetons des sièges solo ; (2) bannière de consentement (`consent-banner.tsx`, `PublicLayout` et `GameLayout`), route `consent.store`, cookies `consent` et `visitor`, table `visitor` (purge `visitor` à 13 mois), `VisitorTracker::stamp()` à la prise de siège (salon et solo) ; (3) inspection d'un joueur : appareil et autres sièges du visiteur ; (4) politique de confidentialité amendée (données, base légale, durées, deux cookies). Migrations `2026_10_06_100055` et `100056`. Tests : `VisitorConsentTest` (9), `RetentionPurgeTest` (+1, sièges solo revus), `RoomArchiveTest` revu, jeu `RetentionRows` étendu. **Reste dû** : section « Mesure d'audience » de la politique (contredit D48), relecture juridique de la bannière.

**Session du 06/10/2026 (suite 3) — validation et analyse, implémentées et NON commitées** : (1) **D61 du 06/10** : étape (d′) d'`AnswerMatcher` — le titre, l'alias ou le préfixe non partagé de la cible suivi d'autres mots est accepté (« solo a star wars movie »), plus long début porté décisif, chiffres identiques ; lecture (L) à seize paramètres constants (invariant L4) ; `AnswerRules::VERSION` = 2, empreinte et `normalizer-v2.php` ajoutés ; (2) **« Ajouter en alias »** sur chaque réponse fausse en texte libre de l'inspection d'une partie ou d'un joueur : lien vers la fiche (`?alias=`), onglet Titres, boîte d'ajout remplie (`20` § 12.2). Tests : `MatchPrecedenceTest` (cas Solo, plus long début, préfixe partagé, chiffres).

**Session du 06/10/2026 (suite 2) — import et curation en volume, implémentés et NON commités** : (1) **export découpé** : `curation:export-batch` écrit des fichiers numérotés sous les plafonds de l'import (200 films, 1 024 Ko) ; (2) **import d'images par passages** : `ImportFrameBatch` travaille 600 s puis se relance (`--budget`, code `PARTIAL`), l'état du lot est prolongé — un seul passage tué à 900 s laissait les lots à moitié importés ; (3) **films absents par tranches** : le bouton des films absents colle au plus `paste_max_ids` (50) films, le refus s'affiche, et un lot à l'aperçu relit l'état de ses films (`refreshPreview`) ; (4) **D60 du 06/10** : balayage TMDB de 1 à 100 pages (liste 1/5/10/20/50/100), `RunCatalogImport` par passages de 600 s. Tests : `FrameBatchTest` (22), `CatalogImportCommandTest` (+3), `ImportTest` relu de la configuration.

**Session du 06/10/2026 (suite) — publier les films prêts d'un coup (D59 du 06/10), implémenté et NON commité** : à la demande du porteur (« une commande pour publier tous les films qui sont prêts »), arbitré en **bouton du back-office** plutôt qu'en commande (une commande sur le chemin du curateur disqualifie le pilote). Catalogue → « Publier les films prêts » → écran `admin/catalog/ready` : les brouillons prêts (prédicat `CurationStatus::ReadyToPublish`) avec l'avertissement d'ambiguïté de chacun, tout le lot compté comme publié ; les prêts sans titre saisissable mis à part ; confirmation tout ou rien (`PublishReadyMovies`, mêmes gardes et écritures que `PublishMovie`, découpée en `assertPublishable()` / `write()`). Docs : D59, `20` § 2.2 ligne 47, § 8.1 bis, L20-38. **Tests** : `PublishReadyTest` (4), matrice ligne 47.

**Session du 06/10/2026 — lien Letterboxd du film révélé (D58 du 06/10), implémenté et NON commité** : à la demande du porteur (« un bouton vers Letterboxd et ainsi l'avoir dans sa watchlist » ; « l'afficher pour les 3, et toujours un `target="_blank"` »). `RevealMovieBuilder` compose `letterboxdUrl` = `https://letterboxd.com/tmdb/{tmdb_id}/` (nul sans `tmdb_id`), donc révélation, resynchronisation et récapitulatif du podium le portent d'un seul constructeur ; composant `components/game/letterboxd-link.tsx` (`target="_blank"`, `rel="noopener noreferrer"`, texte + icône lucide), monté dans `round-reveal.tsx` et `round-recap.tsx` ; clés `game.reveal.letterboxd{,_label}` FR/EN. Aucune migration. **Tests** : `LetterboxdLinkTest` (2 cas) ; attendus de `EventPayloadTest`, `PodiumTest` et des fixtures Vitest complétés. Docs : D58 dans `questions-ouvertes.md`, `60` § 9.5, § 11.5, § 11.7, `80` § 11.4, `90` § 10, `40` § 10.1 sujet 7. **Reste dû** : l'historique du compte (J2) le montera avec son écran. Les modifications D57 du 05/10, toujours indexées, ne sont pas mêlées à celles-ci.

**Session du 05/10/2026 — lots d'images proposés par l'IA hors production (D57 du 05/10), implémentée et NON commitée** : à la demande du porteur (« que tu puisses ajouter toi même les images pour gagner du temps […] que je valide si le niveau de chaque image correspond bien, et de pouvoir exporter du local et importer à la prod »). Quatre arbitrages fermés, tous sur la recommandation : analyse par l'IA en session, TMDB seul, revue et publication en production, import par un écran. **Chaîne** (`20` § 5.10) : `curation:candidates {ids*}` (poste local seulement) rassemble les backdrops admissibles et leurs vignettes `w780` dans `storage/app/curation-candidates/` ; l'IA classe chaque image (niveau, cadre) et écrit un lot `tripleframes.frame-batch` ; `curation:frame-batch <fichier> --actor=<id>` le dépose en `draft` par les gardes de l'ajout unitaire (`TmdbFrameIntake`, extraite de `FrameTmdbController`) ; le porteur valide les niveaux dans l'éditeur local ; `curation:export-batch {ids*|--all}` écrit le lot de références ; en production, écran « Lots d'images » (ligne 46, `admin.frame_batch.*`) : aperçu à blanc, films absents passés au collage d'un clic, import par le job `ImportFrameBatch` (file `default`), puis revue et publication ordinaires. Aucune migration, aucun type de journal nouveau. Docs : D57 dans `questions-ouvertes.md`, `20` § 2.2 (ligne 46), § 5.10, § 10.3, L20-37, `CLAUDE.md` § 2 et § 4. **Tests** : `FrameBatchTest` (17 cas), trois lignes de la matrice, `TmdbBoundaryTest` (admet `TmdbFrameIntake` nommément). Essai réel : `curation:candidates 9487` (1001 pattes) rend 19 candidats, 21 écartés pour langue. **Reste dû** : déployer (le nouvel écran doit être en production avant le premier import), un premier lot réel, la relecture du porteur. **Point à surveiller** : beaucoup de backdrops TMDB sont des visuels promotionnels (composition, fond détouré) et non des images du film ; l'IA les écarte d'elle-même, la grille d'exclusion de la revue reste le garde-fou.

**Session du 02/10/2026 (suite 2) — choix d'apparence supprimé, tout le site sombre (D56 du 02/10), implémentée et NON commitée** : à la demande du porteur (« Il faut totalement supprimer la possibilité de changer la couleur de l'interface » ; « Sombre partout, le design du jeu va être un tout nouveau design, seul le panel admin aura le design de laravel »). `<html class="dark">` en dur dans `app.blade.php`, rendu côté serveur, sans script de détection ; retirés : bascule `AppearanceToggle` de l'en-tête public, page `settings/appearance` (route `appearance.edit`, entrée de navigation, `appearance-tabs`), cookie et stockage local `appearance` (et leurs lignes de la politique de confidentialité), `HandleAppearance`, `ForceGameAppearance` et l'alias `game.appearance` (`room.show`, `solo.show`), `data-appearance-forced`, `useAppearance`/`initializeTheme`, `useForcedAppearance`, étape « apparence » d'`ErrorPageResponder` ; clés `common.appearance.*`, `account.appearance.*`, `account.settings.nav.appearance` retirées FR+EN, `lang:types` relancé. Conservés : `GameLayout` (sans forçage), `GameThemeScope` (redondant, gardé pour le nouveau design), tokens `:root`/`.dark` d'`app.css` ; `sonner` en `theme="dark"`, QR 2FA toujours inversé ; back-office au design du starter, en sombre. Le nouveau design en cours (`resources/scss/**`, pages publiques) n'est pas touché. Docs : D56 dans `questions-ouvertes.md` (révise D8 du 23/09), `90` § 2.1-2.4, § 4.6, § 4.8, § 6, § 9, § 10 et lot **L90-10**, `00` principe 13, `05`, `20` § 13.3, `50`, `60`, `CLAUDE.md` § 5 et § 8. **Tests** : forçage réécrit (`ShellTest`, `ErrorPagesTest`, `RoomEntryPageTest`, `SoloTest`, `RoomPageTest` : `<html class="dark">` quel que soit le cookie `appearance`, route `appearance.edit` absente, `/settings/appearance` en 404). Contrôles : Pint, PHPStan, `npm run check`, types et Vitest (129) verts ; suite Pest par défaut **4 866 / 4 872**, les 6 échecs étant antérieurs (`oauthProviders` ×3, `ZeroSecretTest`, `NoLiteralDomainTest`, `LegalPagesTest`) ; test aléatoire de D55 (`SeatAvatarChangeTest`, avatar de l'hôte non fixé) corrigé. **Restent dus** : `docs/recette/checklist-features.md` et maquettes `design-test/**` montrent encore le choix d'apparence. À signaler au porteur : les maquettes `design-test/**` gardent un onglet d'apparence (hors application, laissées en l'état) ; la recette manuelle `docs/recette/checklist-features.md` (§ 1.5, § 2.10 et mentions éparses) reste à aligner.

**Session du 02/10/2026 (suite) — entrée au pseudo seul, avatar choisi au lobby (D55 du 02/10), implémentée et NON commitée** : à la demande du porteur, options posées puis choisies. L'accueil rejoint avec **code + pseudo** en un envoi (`POST room.join`) ; le lien `/r/{code}` (`room/join`), la création (`room/create`) et le solo (`room/solo`) ne demandent que le pseudo, props `avatars` retirées et champ `avatar` ignoré. L'avatar est **attribué par le serveur** sous le verrou du salon dans `TakeSeat` (image téléversée visible du compte, sinon `suggest()` du jeton ; repli d'une image de compte évitant les clés prises dans `SeatAvatar::resolve`), puis se change au lobby par `room.avatar.update` (`SeatAvatarController`, `ChangeSeatAvatarRequest`, `ChangeSeatAvatar`, `RoomPolicy::changeAvatar`) **seulement tant que le salon est en `lobby`** (refus `NotInLobby` en partie et au podium), jamais en solo. **Unicité entre sièges tenus**, replis des images de compte compris, par le seul calcul `App\Support\Room\TakenAvatars` ; prop `avatars` du lobby en closure (`options`, `taken`, `current`, `account`) ; trois doublons résiduels assumés (retour d'un siège `left`, retardataire face à une identité gelée, effectif au-delà du catalogue). Specs : `questions-ouvertes.md` D55, `00` § Déroulé et § Avatars, `40` § 2.1-2.4, § 3.3, § 3.13, § 5.8, § 6.3, § 6.4, § 6.6, § 6.7, § 7.4, § 9, § 11.4, § 11.10, `50` § 6.2, § 6.4, § 7.2, § 7.3, § 8.1, § 8.2, § 13, § 17.1, § 17.3, § 20.3, § 21, lot **L50-14**, point n° 28 (fermé pour l'avatar), `60` § 3.4, § 11.3, § 16.2, § 16.4, `90` § 4.7, § 6, § 10, exigences, `CLAUDE.md` § 2 et § 8. Contrôles : Pint, PHPStan (level 7), `npm run check`, types et Vitest (129) verts ; suite Pest par défaut **4 866 / 4 872**, les 6 échecs étant antérieurs à D55 (`oauthProviders` absent des listes de `LobbyPageTest` ×2 et `RoomEntryPageTest`, `ZeroSecretTest`, `NoLiteralDomainTest` sur `40`, `LegalPagesTest` sur `document.html`) ; groupes `mysql`/`locks-timing` non rejoués (`LateJoinConcurrencyTest`, `SoloStartConcurrencyTest` retouchés) ; aucun contrôle visuel au navigateur. **Restent dus** : `docs/recette/checklist-features.md` décrit encore l'ancien parcours (pseudo + avatar à l'entrée, doublon permis) ; annexe C5 archivée non modifiée (la spec prime).

**Session du 02/10/2026 — QCM absent pour certains films (D52, D53, D54), corrigé et NON commité** : diagnostic prouvé par simulation sur la base de dev (transactions annulées) : `DecoyPicker::pick()` rendait le cas terminal, **silencieux en Normal**, pour deux causes. (1) Aucun `movie_title` dans la langue originale (TMDB la rend vide) : masques fragmentés (anglophones à 2 ou 0, non-anglophones à 3), Parasite sans aucun partenaire de profil, donc jamais de QCM ; un joueur EN voyait aussi « Le Monde de Dory ». (2) Vivier des leurres épuisé en fin de partie et après « Rejouer » (non-répétition et manches démarrées exclues à tous les rangs). Livré : **D52** `OriginalLanguageTitle` à l'import et à la resynchronisation, commande `catalog:original-titles [--dry-run]` (instantané en tête) ; **D53** rangs de dernier recours R5 (publiés sans non-répétition) et R6 (films `draft`/`unpublished` `clear`, `PoolScope::asDecoyReserve()`, `Movie::inDecoyReserve()`), tirages antérieurs inchangés, leurres partiels conservés, réserve lue par lots ; **D54** `game.choices_unavailable` au journal `game` et à `game_trace`, booléen `choicesUnavailable` sur `tier.opened` et dans la resynchronisation, message `game.choices.unavailable` en Normal. Specs : `questions-ouvertes.md` D52-D54, `10`, `05`, `20`, `30`, `60`, `70`, `90`, `CLAUDE.md` § 2 et § 4. Pint, PHPStan, `npm run check`, types et Vitest (120) verts ; suite Pest par défaut **4 853 / 4 858** : les 5 échecs viennent du chantier OAuth non commité (`ZeroSecretTest` : secrets Google/Discord absents de `phpunit.xml` ; `NoLiteralDomainTest` : hôtes littéraux en `40` l. 892 ; `LobbyPageTest` ×2 et `RoomEntryPageTest` : prop `oauthProviders`), à corriger avant le commit. **Gestes humains** : en production, `php artisan catalog:original-titles --dry-run` puis sans l'option ; surveiller `game.choices_unavailable` dans le journal `game`. Point ouvert : une future purge des films `draft` devra tenir compte de `round.decoy_movie_id_1..3` (clé étrangère restrictive).

**Session du 01/10/2026 (suite 8) — connexion Discord et Google (D51 du 01/10), livrée et NON commitée** : à la demande du porteur, options posées puis choisies : périmètre complet **photo comprise**, **ouverte dès que les clés sont posées** (indépendante d'`ACCOUNTS_REGISTRATION_OPEN`), consentement daté par fournisseur, ré-authentification OAuth pour un compte sans mot de passe. Livré : `laravel/socialite` + `socialiteproviders/discord` ; `routes/auth.php` (`/auth/{provider}/redirect`, `/auth/{provider}/callback`, `/auth/finish`) ; table de décision `ResolveOAuthCallback` (connexion, défi 2FA de Fortify, liaison automatique sur adresse vérifiée des deux côtés sans 2FA, refus nommés) ; écran « Finaliser l'inscription » (nom, CGU, âge ; `user_consent` `terms`, `age`, `provider_<p>` à `legal.terms_version` = `provisoire-1`) ; écran « Comptes liés » (lier, délier sous confirmation fraîche et code 2FA, refus de la dernière méthode) ; confirmation par fournisseur et « Définir un mot de passe » pour un compte sans mot de passe ; copie bornée de la photo (`CopyProviderAvatar`, liste blanche, HTTPS, sans redirection), choix « Ma photo » à l'écran Avatar, « Mon avatar » au siège, signalement et modération partagés avec l'image téléversée (`AccountImage`, écran admin filtré par nature) ; migration additive `2026_10_01_100054` ; `ConsentKind` (deux cas). Specs : `questions-ouvertes.md` D51 (D50 était déjà pris par les propositions d'alias), `40` § 10 et § 12 (lot L40-9), `10` § 5.1 et § 5.4, `00`, `CLAUDE.md`. Tests : `Auth/OAuthLoginTest`, `Auth/OAuthLinkTest`, `Identity/ProviderAvatarTest`. **Gestes humains** : créer les applications chez Google (Cloud Console, écran de consentement) et Discord (Developer Portal) ; y déclarer `https://<DOMAINE>/auth/google/callback` et `https://<DOMAINE>/auth/discord/callback` (et l'origine JavaScript `https://<DOMAINE>` chez Google) ; poser `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET` dans le `.env` de production ; jouer la migration. **Restent dus** : textes opposables des CGU (J2) et ré-acceptation à leur publication (Q40-6) ; rattachement d'un siège invité pris avant la connexion (J2, sujet 5).

**Session du 01/10/2026 (suite 8) — suggestions automatiques d’alias (D50 du 01/10), livrées et NON commitées** : à la demande du porteur, les réponses texte refusées de D46 sont agrégées sur 90 jours par film et forme normalisée. Après **trois manches distinctes**, une formulation rejoint la file curateur « Suggestions d’alias » ; les clics QCM et les formes déjà acceptées sont exclus. Le job `AggregateNearMisses`, horaire sur `default`, et le bouton « Actualiser » reconstruisent la file de façon idempotente sous verrou. L’écran montre seulement les compteurs, la meilleure distance et des mois, jamais un joueur ni une partie ; le curateur choisit FR ou EN puis accepte la formulation comme alias exact (`origin = curator`, reprojection de `answer_key`) ou l’ignore. Exemple couvert par le test : « Harry Potter et la pierre philosophale » proposé pour « Harry Potter à l’école des sorciers ». D50 révise D24 : les titres alternatifs éloignés sont admis comme candidats, mais **rien n’est accepté automatiquement**. Front : formatage, types et 112 tests Vitest verts ; tests PHP ajoutés mais non exécutés sur ce poste, où PHP n’est pas installé ; le build s’arrête pour la même raison au greffon Wayfinder.

**Session du 01/10/2026 (suite 7) — avatar personnalisé téléversé (D49 du 01/10, renverse la décision 14), livré et commité** : à la demande du porteur (« ajoute tout le système d'avatar personnalisé »), options posées puis choisies : comptes seulement, modération a posteriori, **J1**, choix explicite de la nature, rattachement du siège au compte, écran admin dédié. Livré : nature `AvatarKind::Upload` ; trois colonnes `users.avatar_upload_*` (migration additive `2026_10_01_100053`) ; écran « Avatar » des réglages (`settings/avatar` : choix prédéfini ou image, recadreur carré navigateur `components/account/avatar-cropper.tsx`, suppression) ; normalisation **synchrone** bornée `App\Avatars\AvatarImage` (WebP 256 × 256, ≤ 40 Ko) ; disque **privé** `avatars` servi par `GET /a/{file}` (`avatar.show`, 404 si masquée), **aucun `storage:link`** ; la prise de siège d'un compte écrit `player.user_id` (I4.10 amendé) et propose « Mon avatar » (`SeatAvatar::ACCOUNT`), résolution vivante dans `Player`/`GamePlayer::avatarRef()` ; signalement par tout siège (`room.players.report_avatar`, bouton dans la liste des sièges, lobby et panneau en partie), masquage à **deux sièges distincts** (`ReportSeatAvatar`, `avatar.hidden` automatique) ; écran admin « Avatars » (ligne 45 : lever `avatar.unhidden`, retirer `avatar.removed` à motif obligatoire, cas nouveau d'`AdminActionType`, 52 cas). Specs : `questions-ouvertes.md` D49, `40` § 2.4 et § 11 (lot L40-8), `10` § 5.1, § 5.3, § 8.1, § 8.3, `20` ligne 45 et § 12.5, `00`, `CLAUDE.md`. Tests : `Identity/AvatarUploadTest`, `Identity/AvatarReportTest`, `Admin/AvatarModerationTest`, matrice des routes. **Gestes humains avant de déployer** : poser `AVATARS_DISK_ROOT` dans le `.env` de production sur un chemin absolu **hors du répertoire de déploiement** (comme `FRAMES_DISK_ROOT`), créer le répertoire inscriptible par PHP-FPM, puis jouer la migration. **Restent dus** : notification par e-mail du masquage (J2, avec les mails de modération) ; suppression du fichier à l'anonymisation (J2, § 10 sujet 9) ; la page de confidentialité (`90`, J2) doit mentionner l'image téléversée et les signalements.

**Session du 01/10/2026 (suite 6) — mesure d'audience sans cookie (D48 du 01/10), livrée et NON commitée** : à la demande du porteur, `RecordVisit` (middleware `web` terminable) compte chaque page vue humaine (ni partielle, ni préchargée, ni back-office, ni robot) dans des **compteurs quotidiens** `audience_daily` (pages par route, visiteurs, visites, entrées, sorties, provenance au domaine, langue, appareil), conservés 13 mois (périmètre `audience`). Visiteur = empreinte `HMAC(adresse | navigateur, sel du jour)`, sel en cache seulement, jamais en base ; visite = pause de moins de 30 min (cache). Temps réel par `audience_presence` (10 min) et les tables du jeu ; entonnoir lu dans les tables du jeu. Écran admin « Audience » (ligne 44). Interrupteur `AUDIENCE_ENABLED` (vide = active, `off` en test). Migration additive `2026_10_01_100052`. `TablePurgeHandler::cutoff()` admet désormais un jour nu (colonne pilote `date`). Specs : `questions-ouvertes.md` D48, `10` § 7.12 et § 11.1, `100` § 10.12, `20` ligne 44 et § 12.4. **Reste dû au J2** : la page de confidentialité (`90`) doit décrire cette mesure (exemption CNIL : liste fermée, pas de recoupement, 13 mois).

**Session du 01/10/2026 (suite 5) — mesure des performances et chronologie technique des parties (D47 du 01/10), livrées et NON commitées** : à la demande du porteur, mesure maison active partout et coupable (`PERF_ENABLED`, `PERF_SAMPLE_RATE`, `PERF_SLOW_QUERY_MS`). `MeasureRequest` (middleware global terminable) et les événements de file écrivent un `perf_sample` par requête et par job (nom de route, jamais l'URL ; durée, requêtes SQL, mémoire) et les requêtes SQL lentes à paramètres (`perf_slow_query`) ; `GameTraceWriter` écrit après commit la chronologie de chaque partie (`game_trace` : transitions de `GameJournal`, diffusions de frontière et leur retard, jobs `AdvanceRound` avec retard au démarrage, soumissions avec durée et issue, jamais la saisie). Écran admin « Performances » (ligne 43, `admin/performance`), chronologie sur la fiche d'une partie, commande `perf:report`. Migration additive `2026_10_01_100051` (trois tables, hors règle 12), purge 14 jours (périmètres `perf` et `game_trace`). `phpunit.xml` coupe la mesure (`PERF_ENABLED=off`). Specs : `questions-ouvertes.md` D47, `10` § 7.11 et § 11.1, `100` § 10.11, `20` ligne 43 et § 12.3, `CLAUDE.md` § 4. Le correctif des clés de props de l'inspection (tests d'architecture `ScoringWritersTest`, `TierServingWritersTest`), postérieur au commit `3842bc5`, est dans le même arbre de travail. **Déploiement** : `artisan migrate` (hook) ; nouvelles routes → `route:clear` avant tout build sur le VPS ; ajouter `PERF_ENABLED=` au `.env` de production n'est pas requis (vide ou absent = actif).

**Session du 01/10/2026 (suite 4) — inspection des parties, des joueurs et des réponses (D46 du 01/10), livrée et NON commitée** : à la demande du porteur, quatre écrans **administrateur seul** — parties en cours et historique (salon et solo), fiche d'une partie (réglages figés, classement, chaque manche, chaque réponse juste ou fausse par joueur), annuaire des sièges invités compris, fiche d'un siège. Le porteur a choisi de **stocker le texte des réponses fausses** (révise la décision 19) : table `wrong_answer` (migration additive `2026_10_01_100050`, hors règle 12), une ligne par refus compté, écrite dans la transaction du refus, 12 mois comme `guess` ; L4 tenu (insertion conditionnée au seul nombre de lignes touchées). Règle 3 tenue : rien d'une manche non révélée d'une partie en cours n'est chargé. Quatre lectures sensibles nouvelles au journal (51 cas), trois sujets (`game`, `games`, `players`). Specs : `questions-ouvertes.md` D46, `10` § 7.6 bis et § 11.1, `70` § 7.4 à § 7.6, `20` lignes 36 et 42, § 12.2, L20-36, `00`, `CLAUDE.md`. Tests : `GameInspectionTest`, `WrongAnswerJournalTest` [nouveaux], mises à jour de `ConstantWorkRefusalTest`, `AttemptsTest`, `AdminActionTypeTest`, `ModelTimestampsTest`, `ModelSerializationTest`, `LoadTestForgetCommandTest`, `AdminRoutes`. **Déploiement** : nouvelles routes → sur le VPS, `artisan route:clear` avant tout `npm run build` (sinon Wayfinder lit le cache de routes périmé, cf. l'échec du build du 01/10), puis `optimize`. Reste dû : la purge `game_facts` (J2) devra inclure `wrong_answer` ; l'export de données d'un compte (`40`, J2) aussi ; la page de confidentialité (`90`, J2) doit annoncer la conservation des réponses soumises.

**Session du 01/10/2026 (sélection multiple de thèmes sur la fiche film)** : le sélecteur recherchable « Ajouter des thèmes » filtre les options par nom ou nature et accumule plusieurs choix visibles et retirables avant une confirmation unique ; clavier : flèches, Entrée et Échap. Le serveur accepte `theme_ids[]`, valide que le lot ne sert qu'à l'ajout, verrouille et autorise le film une fois, puis applique tout le lot dans une transaction unique en gardant une ligne `movie.theme_set` par thème modifié. Tests ciblés : 20 cas / 163 assertions ; `npm run check`, PHPStan ciblé et build verts.

**Session du 01/10/2026 (suite 3) — E107-5 appliqué, état de production relevé** : (1) les sessions D44 et D45 ci-dessous sont **commitées** (release 1.1, `ed62a26`) ; le lot correctif de tests attendu au § 5 l'était déjà depuis le 28/09 (E116-10 par `0e41a3a`, E122-7 par `c9017e0`). (2) Par accord du porteur, le refus texte relit la fenêtre d'acceptation sous le verrou partagé de `round` : un texte faux traité après `RevealRound` n'est plus compté et répond 409 `closed`, comme le clic faux (`SubmitTextAnswer::refuse()`, `70` § 7.3 à § 7.5, test « un texte faux traité après la révélation de sa manche répond saisie close sans rien compter »). (3) **Production, déclarée par le porteur le 01/10** : domaine acheté et site en ligne, accès root et relevé du VPS faits, Redis dédié, Reverb et workers systemd posés, sauvegardes actives. Les étapes 1, 2, 27, 29 et 30 sont donc au moins en partie faites ; reste à confirmer le détail (gabarits `ops/` ajustés au relevé réel, contrôle de lisibilité 31, supervision 32, premier admin 28) avant le déploiement du moteur (126).

**Session du 01/10/2026 (suite 2) — plages de niveaux par palier (D45 du 01/10), livrées et NON commitées** : à la demande du porteur, chaque palier pioche son image dans une plage (N=2 → 1-2 | 4-5 ; N=3 → 1-2 | 2-4 | 4-5 ; N=4 → 1 | 2-3 | 4 | 5 ; N=5 → un par niveau), niveaux strictement croissants, variantes de la plage mêlées pour la préférence « non vue », repli de niveau conservé quand la banque ne remplit pas ses plages, substitution dans la plage bornée par les voisins. Code : `FrameLevelCoverage::bands()` / `sequences()`, `GameDrawer::tiers()`, `VariantChooser` (garde « un seul niveau » retirée, `substitute()` par plage). Tests : `FrameLevelCoverageTest`, `GameDrawTest`, `SubstitutionTest`, `FrameBankTest`, `DemoCatalogueChainTest`. Suite par défaut verte (4 647) ; groupes `mysql` et `locks-timing` non rejoués. Reste : libellé de l'aperçu du back-office (« Niveaux joués ») à reformuler en « séquence de référence » si le porteur le souhaite. Détail : `questions-ouvertes.md` D45, `30` § 2.

**Session du 01/10/2026 (suite) — leurres apparentés à la cible (D44 du 01/10), livrés et NON commités** : à la demande du porteur (« pour Toy Story 3, le QCM ne propose que des Toy Story »), `DecoyPicker` cherche d'abord ses trois leurres dans le catalogue publié entier parmi les films de même saga (`collection_id`), puis d'un même thème studio, saga ou manuel (du plus spécifique au plus large), puis d'un même genre ; **un groupe n'est retenu que s'il fournit seul les trois leurres** (sinon la cible se lirait dans la paire de la saga) ; à défaut, l'échelle R1-R4 inchangée ; mêmes groupes au titre original avant R3-R4. Deux contextes ajoutés au registre `DrawContext` (`decoysAffinity`, `decoysAffinityOriginal`), vecteurs figés. Tests : `DecoyAffinityTest` [nouveau], `DecoyDrawTest` (thèmes de salon passés en décennies), `SeededPrfTest`. Détail : `questions-ouvertes.md` D44, `70` § 10.3 bis, `30` § 5.3, § 5.4, § 11.

**Session du 01/10/2026 — thèmes par film avancés au J1 (D43 du 01/10), specs amendées avant le code** : à la demande du porteur (« définir les thèmes de chaque film, y compris à l'importation »), D43 du 01/10 avance au J1 l'évaluateur d'appartenance et `catalog:themes` (L30-8), les thèmes livrés étendus — Marvel 420, **DC** sur trois sociétés `429,128064,184898` (règle studio multi-valeurs), décennies sans trou, animés, **douze** sagas dont Avatar 87096 et Iron Man 131292 (L30-9) —, la mesure d'un thème (L30-11a) et le back-office des thèmes (L20-28 hors difficulté) : création de toute nature et thèmes manuels, bloc « Thèmes » de la fiche film, « Créer la saga depuis cette collection », multi-sélection de thèmes au collage ; noms des sociétés TMDB stockés (table `tmdb_company`, `catalog:company-names`) ; cinq cas du journal, **47 cas, 9 sujets**. Sélecteur du lobby et difficulté dérivée restent au J2. Identifiants TMDB vérifiés le 01/10. Ordre d'exécution : annexe, étapes 135 à 145 ; gestes du porteur : § 3, gestes 12 à 15. Détail : `questions-ouvertes.md` D43, `30` § 12-13, `20` § 2.7, § 3.3, § 9.6, `10` § 3.6 bis, § 8.3, § 9.1.

**Session du 30/09/2026 (suite) — validation en lot des images d'un film, livrée et NON commitée (relecture du porteur demandée)** : par D42 du 30/09, « Tout valider » sur la fiche d'un film et en tête de son groupe dans la file de revue valide en une fois ses images en attente (hors rejets, traitement, échec et hors jeu) — une ligne `frame_review` par image sous la grille courante, une seule ligne `movie.frames_reviewed` (42 cas), tout ou rien sous verrou ; publication du film jamais automatique. Détail : `20` § 7.9 et L20-35, `10` § 8.3, `questions-ouvertes.md` D42.

**Session du 30/09/2026 — journal de toute l'activité du back-office, partie serveur livrée et NON commitée (relecture du porteur demandée)** : par D41 du 30/09, `admin_action` consigne désormais **tout geste du back-office** (curateur comme administrateur) et les **lectures sensibles** (annuaire, fiche d'un compte, écran des accès et sa recherche) ; **19 cas nouveaux, 41 au total**, sujets `import_run` et `accounts`, colonne additive `admin_action.details` (migration `2026_09_30_100047_add_details_to_admin_action`), tous permanents ; une ligne par visite complète, jamais sur un rechargement partiel ni un préchargement. Pint, PHPStan, `npm run check` verts ; suite par défaut : une défaillance sur 4 429, corrigée, suite complète **à relancer**. **Reste dû** : l'écran « Journal » `/admin/journal` (administrateur seul, filtres acteur, type, période, sujet) et les liens « Historique » des fiches film et compte, spécifiés dans `20` § 2.10 (lot L20-34). Décision : `questions-ouvertes.md` § « Décisions du 30/09/2026 » ; schéma : `10` § 8.3 ; règle, inventaire et exclusions : `20` § 2.7 ; points ouverts EL41-1 à EL41-3.

**Répétition de la mise en service jouée sur la VM le 28/09/2026 : voir `docs/ops/repetition-vm.md`.**

**Session du 28/09/2026 (soir) — voie capture ouverte au J1 et visuels TMDB porteurs d'une langue écartés, livrés et NON commités (relecture du porteur demandée)** : lot L20-33 passé au J1 et livré (D38 du 28/09 : téléversement ou collage d'une capture, normalisée par le navigateur, minutage `h:mm:ss` obligatoire, jeu toujours dérivé par le serveur, R-46 confirmé ; interrupteur `CURATION_CAPTURE_ENABLED` ouvert par défaut, `false` le ferme) ; backdrops TMDB auxquels TMDB attache une langue masqués, comptés et refusés à l'ajout (D39 du 28/09). Suite complète **4 401 / 4 401**. Décisions consignées dans `questions-ouvertes.md` § « Décisions du 28/09/2026 » (D38 à D40) ; détail dans `20` § 5.3, § 5.4, § 6.2, § 6.3 et L20-33, points ouverts EL33-1 à EL33-5 au § 4 ci-dessous — amendé le 28/09.

**Session du 28/09/2026 (après-midi) — gestion des comptes au back-office, livrée en avance du J2 et NON commitée (relecture du porteur demandée)** : lot L20-19 (écran de gestion des accès, correction du nom réel, EN20-3 inscrite) plus un annuaire de tous les comptes, décidé par le porteur le même jour (D40 du 28/09 ; `20` § 2.8, points ouverts EL19-1 à EL19-8 au § 4 ci-dessous).

**Dernière session : 24-28/09/2026 — phases A, B et C implémentées : back-office de curation et moteur de jeu complets sur develop (≈ 4 300 tests) ; reste la phase D, qui attend les gestes du porteur.** Étapes IA livrées : 6 à 25, 33 à 53, 62, 63 et 65 à 125. Les écarts relevés pendant l'implémentation (journal des écarts, entrées « E<étape>-<n> ») sont reportés dans les specs propriétaires : marqués « amendé le 25/09 » pour les phases A et B, « amendé le 28/09 » pour la phase C (étapes 62 à 125). Les gestes du porteur qu'ils ajoutent sont au § 3, ses questions au § 4. Restent dus, outre la phase 0 : avant la porte du pilote (56), les étapes 26 à 30, 32, 54 et 55, gestes du porteur ou préparation de la mise en service ; l'étape 64 (licence du pack d'avatars) ; puis la curation (57 à 61) et la phase D (126 à 134) — amendé le 28/09.

**Session du 24-25/09/2026 — phases A et B implémentées (étapes 6 à 25 et 33 à 53), back-office de curation du J1 complet.** Écarts reportés dans les specs, marqués « amendé le 25/09 ».

**Session du 23/09/2026 — specs du jalon 1 écrites.** `20`, `30`, `50`, `60`, `70` et `80` sont complètes ; `40`, `90` et `100` ont leur section du jalon 1 ; `00`, `05`, `10`, `questions-ouvertes.md` et `CLAUDE.md` sont amendés. Aux 19 décisions du 22/09 s'ajoutent les décisions du 23/09 : **S1 à S4** pour le cadre, **D1 à D37** pour le fond. Le lot 10 (D35 à D37) a fixé le périmètre et le rythme : **jalon 1 complet, sans aucune coupe** ; **développement confié à l'IA** ; **ordre « curation d'abord »**. Aucune ligne de code métier n'a été écrite pendant cette session : la suite est l'implémentation, dans l'ordre donné en annexe.

Ce fichier dit :

- où en est le projet ;
- ce qui est verrouillé ;
- ce qui bloque, c'est-à-dire le chemin humain ;
- ce qui reste ouvert ;
- par quoi reprendre.

Il est réécrit à chaque fin de session. Il a été réécrit en entier à la fin de la session du 23/09 — amendé le 23/09.

---

## 1. Où en est le projet

**Phase : implémentation IA du jalon 1 terminée ; phase D à venir.** Toutes les specs nécessaires au jalon 1 sont écrites, puis amendées les 25/09 et 28/09 par le report des écarts d'implémentation. La section « Jalon 2 — à écrire » de `40`, de `90` et de `100` attend l'après-jalon 1 (S1 du 23/09). Tous les lots IA du J1 des phases A, B et C sont livrés sur `develop` : back-office de curation complet, moteur de partie temps réel, salons, lobby, saisie et validation des réponses, score et podium, mode solo, accueil, pages publiques et recette portrait automatisée. Ce qui reste du J1 est dans l'annexe : gestes du porteur (phase 0, mise en service, pilote, curation), étape 54, et phase D (déploiement du moteur, recette sur appareil réel, restauration chronométrée, charge, première partie) — amendé le 28/09.

### Documents

| Fichier | Rôle | État au 23/09 | Taille au 28/09 |
|---|---|---|---|
| `docs/specs/00-overview.md` | Vue d'ensemble : concept, réglages, vocabulaire, principes, exploitation, jalons, carte des specs | v3, à jour des 19 décisions et de D1 à D37 du 23/09. § Jalons est recalculé : taille du J1, chemin humain, ordre « curation d'abord » | 498 l., 157 Ko |
| `docs/specs/05-i18n-et-langues.md` | Seul propriétaire de la règle de langue | écrite le 22/09, amendée le 23/09 | 384 l., 61 Ko |
| `docs/specs/10-catalogue-et-modele-de-donnees.md` | **Seul propriétaire du schéma** : 35 tables de domaine + `users` altérée, rétention, purge | écrite le 22/09, amendée le 23/09. Les 35 tables sont inchangées ; au J1, 4 colonnes nouvelles (`users.real_name`, `player.kicked_at`, `player.solo_token_hash`, `round_choice_set.rendered_locale`) arrivent par des migrations additives portées par leurs lots, plus l'élargissement de `game_player.final_rank` (`10` § 12, n° 43 à 47 ; voie selon I-12) — amendé le 23/09 | 1 518 l., 323 Ko |
| `docs/specs/20-back-office-curation.md` | Rôles et matrice, import, frame servable, recadreur, grille, revue, publication, lot pilote, modération | écrite le 23/09, complète, jalons marqués. § 14 tranche les 24 questions du panel admin. Lots J1 : L20-1 à L20-18 ; plus L20-33 (D38), L20-34 (D41), L20-35 (D42) et **L20-28 hors difficulté** (D43 du 01/10 — amendé le 01/10) | 1 427 l., 321 Ko |
| `docs/specs/30-themes-vivier-et-tirage-des-variantes.md` | Thèmes, vivier, tirage des films et des variantes | écrite le 23/09, complète. Lots J1 : L30-1 à L30-7 ; **L30-8, L30-9 et L30-11a depuis D43 du 01/10** — amendé le 01/10 | 1 129 l., 167 Ko |
| `docs/specs/40-comptes-auth-sociale-et-avatars.md` | Identité, connexion, avatars | **partielle** : la section [J1] « identité invitée » est écrite (D2 du 23/09) ; J2 à écrire. Lots J1 : L40-1 à L40-7 | 905 l., 137 Ko |
| `docs/specs/50-salon-reglages-presets-et-lobby.md` | Salon, réglages, presets, lobby | écrite le 23/09, complète, onglet Avancé du J2 compris. Lots J1 : L50-1 à L50-9 ; L50-10 livré le 08/10 | 2 045 l., 235 Ko |
| `docs/specs/60-moteur-de-partie-temps-reel-et-mode-solo.md` | Moteur de partie, temps réel, solo | écrite le 23/09 **sous hypothèse root** (S2 du 23/09). Lots J1 : L60-1 à L60-16 | 1 464 l., 298 Ko |
| `docs/specs/70-validation-des-reponses.md` | Saisie et validation des réponses | écrite le 23/09. Lots J1 : L70-1 à L70-11 et L70-14 ; L70-12 et L70-13 au J2 | 1 167 l., 207 Ko |
| `docs/specs/80-scoring-podium-et-fin-de-partie.md` | Score, podium, fin de partie | écrite le 23/09. Lots J1 : L80-1 à L80-7 | 1 176 l., 152 Ko |
| `docs/specs/90-ecrans-etats-et-structure.md` | Écrans, états, structure, pages publiques | **partielle** : la section J1 (pages publiques et socle de coquille de jeu, D3 du 23/09) est écrite ; J2 à écrire. Lots J1 : L90-1 à L90-9, dont L90-3b, L90-6a et L90-6b | 1 028 l., 206 Ko |
| `docs/specs/100-qualite-tests-et-ci.md` | Qualité, tests, CI, production | **partielle** : la section [J1] (socle minimal de production) est écrite sous hypothèse root (D30 et S2 du 23/09) ; J2 à écrire. Lots J1 : L100-1 à L100-14 | 1 084 l., 246 Ko |
| `docs/specs/questions-ouvertes.md` | Journal des décisions : 19 du 22/09, S1-S4 et D1-D37 du 23/09, « Laissé ouvert le 23/09 », D38-D40 du 28/09 (amendé le 28/09), D41-D42 du 30/09, D43 du 01/10 (amendé le 01/10), déjà tranché, risques | questionnaire clos, sauf le nom de domaine | 517 l., 148 Ko |
| `CLAUDE.md` | Mémoire projet chargée automatiquement à chaque session | **versionné désormais** (D9 du 23/09) : la ligne a été retirée de `.gitignore` et le fichier est exclu d'oxfmt dans `vite.config.ts`. Amendé le 23/09 | 165 l., 48 Ko |

Les heures des sections « Lots d'implémentation » sont des **mesures de taille, jamais un calendrier** (D36 du 23/09). Les lots J1 des neuf specs mesurent **404,5 à 581,5 h brutes**. L'arithmétique vit dans `00` § Jalons, nulle part ailleurs. D43 du 01/10 y ajoute **22 à 31 h** de taille (`00` § Jalons, amendé le 01/10).

**Commit.** Le travail documentaire du 23/09 et chaque lot livré du 24 au 27/09 sont commités sur `develop`, un commit par lot ; le report des écarts de la phase C l'est par le commit « :memo: Report des écarts d'implémentation de la phase C ». Aucun lot de la phase C ne rejoint la branche que tire Plesk avant l'étape 126 (règle de branche, § A.2) — amendé le 28/09.

### État du code

Le code des phases A, B et C a été écrit du 24 au 27/09, un commit par lot sur `develop`. Le dernier commit de lot est `2dc258f` (L90-9, étape 125). Chaque lot a été livré « terminé » au sens du § A.1 de l'annexe ; ses écarts à la spec sont reportés dans la spec propriétaire. Ce qui existe au 28/09 — amendé le 28/09 :

| Zone | Contenu | Écrit |
|---|---|---|
| `database/migrations/` | 47 fichiers : les 42 du 22/09, plus `users.real_name` (n° 43), `player.kicked_at` (n° 44), `player.solo_token_hash` (n° 45), `round_choice_set.rendered_locale` (n° 46) et le réalignement de l'ordre des thèmes de plateforme ; `final_rank` élargi dans la migration de création (n° 47, I-12) ; 48 fichiers depuis `admin_action.details` (n° 49, 30/09) ; **50** après `tmdb_company` (n° 50) et `import_run.added_theme_ids` (n° 51), D43 du 01/10 — amendé le 01/10 | 22-27/09 |
| `app/Models/`, `app/Enums/` | 37 modèles ; 57 énumérations | 22-27/09 |
| `app/Actions/`, `app/Jobs/`, `app/Events/`, `app/Policies/` | 54 actions (curation, salon, partie, solo, réponses, score), 11 jobs (dont `AdvanceRound`, un par frontière de palier, `BroadcastLobbyState` et `ProcessFrameImage`), 24 événements Reverb, 6 policies | 24-27/09 |
| `app/Console/Commands/` | 21 commandes, dont `catalog:*`, `admin:first-admin`, `backup:snapshot`, `deploy:{guard,drain,release}`, `game:reschedule`, `purge:{run,suspend,resume}`, `room:archive-idle`, `answers:collisions` ; `catalog:themes` (L30-8) et `catalog:company-names` livrées le 01/10 par D43 du 01/10 — amendé le 01/10 | 22-27/09, 01/10 |
| `app/Http/Controllers/`, `resources/js/pages/` | 60 contrôleurs ; 32 pages Inertia : back-office de curation complet, accueil, pages légales en squelette, pages d'erreur, création et entrée de salon, salon expiré, `game/lobby` (du lobby au podium), `room/solo` et `game/solo` | 24-27/09 |
| `resources/js/components/{game,room}/` | 33 composants de jeu et de salon, découplés de leur habillage (règle 5) | 24-27/09 |
| `database/factories/`, `database/seeders/` | Catalogue de démonstration de 16 films jouables à N = 2 à 5 (78 `.webp` réels) ; la partie de 10 manches de bout en bout est prouvée par L100-14 (étape 103) | 22-27/09 |
| `tests/` | 209 fichiers Pest (201 `Feature`, 7 `Concurrency` joués sur MySQL par le job `mysql-redis`, 1 `Unit`) et 23 fichiers Vitest | 22-27/09 |

**Gestion des comptes, livrée le 28/09 en avance du J2, non commitée.** Par D40 du 28/09, le back-office gagne, pour l'administrateur seul, un groupe « Administration » : l'**annuaire des comptes** (`admin.users.index`, `admin.users.show` — tous les comptes, recherche, filtres, fiche en lecture, aucun secret ni `saved_config` sérialisés) et l'**écran de gestion des accès** (`admin.access.*` — comptes privilégiés, file « rôle privilégié sans 2FA », promotion par adresse exacte, historique, changement de rôle et correction du nom réel, chacun journalisé). Le journal comptait alors **22 cas** (`user.real_name_changed`, EN20-3 acceptée) ; il en compte **41** depuis D41 du 30/09 (`10` § 8.3) — amendé le 30/09. `users.last_login_at` est écrit à chaque connexion aboutie (écouteur `RecordLastLogin`). Détail et écarts : `20` § 2.8 et lot L20-19, `10` § 5.1 et § 8.3, `40` § 8.4 et § 10.1. Les changements sont dans l'arbre de travail de `develop`, **sans commit** : le porteur relit d'abord — amendé le 28/09.

**Voie capture et visuels TMDB avec langue, livrés le 28/09 au soir, non commités.** Par D38 du 28/09, la voie capture est **ouverte au J1** avant l'arbitrage de licéité, que le porteur laisse ouvert au conseil : dans l'éditeur de la banque, « Choisir une image » ou un collage (`Ctrl + V`) ouvre une capture normalisée par le navigateur (WebP de 1920 de large, sous le plafond d'entrée) dans le même recadreur et le même formulaire que les visuels TMDB, avec un champ de minutage obligatoire ; le serveur (`FrameCaptureStoreRequest`, `FrameCaptureController`, `AddFrame::fromCapture`) revalide, renormalise toujours et dérive lui-même l'image de jeu. L'interrupteur `CURATION_CAPTURE_ENABLED` reste vide dans `.env.example` et vaut désormais **ouverte** ; `false` la ferme, route comprise. Par D39 du 28/09, les backdrops auxquels TMDB attache une langue ne sont plus proposés : l'écran dit combien sont écartés, et l'ajout les refuse côté serveur (`admin.frame.tmdb.with_text`). Le parcours n'a **pas été essayé à la main dans un navigateur** (geste 11 du § 3). Détail et écarts : `20` § 5.3, § 5.4, § 6.2, § 6.3, lot L20-33, EL33-1 à EL33-5 — amendé le 28/09.

**Catalogue réel de la base de dev.** Trois films sont importés depuis TMDB : Fight Club, Parasite et Le Labyrinthe de Pan. Tous sont en `draft` et `is_import_exception` (dont 2 pour `exception_for_language`). Aucune frame n'est curée, donc le vivier est vide à tout N. C'est normal : au jalon 1, la curation réelle naît **en production** (D1 du 23/09). Aucun film réel n'y est curé avant la **porte du pilote** (étape 56 de l'annexe, `20` § 10.3).

**Suite de tests, dernier état vérifié le 28/09** (suite par défaut de `composer test`, SQLite, sans les groupes `mysql` et `locks-timing`) — amendé le 28/09 :

- Pint passé ;
- PHPStan niveau 7 : 0 erreur ;
- **Pest : 4 313 tests passés sur 4 313, 99 061 assertions**, en 428 s sur le poste Windows ; **4 384 sur 4 384, 101 324 assertions, en 388 s** après la gestion des comptes (28/09, arbre non commité), `npm run check`, `tsc` et `npm run build` verts — amendé le 28/09 ; **4 401 sur 4 401, 101 601 assertions, en 384 s, puis **4 404 sur 4 404, 101 630 assertions, en 440 s** après les correctifs de la revue du lot** après la voie capture et l'exclusion des visuels TMDB avec langue (28/09 au soir, arbre non commité), Vitest 112 / 112, `npm run check`, PHPStan, Pint et `npm run build` verts — amendé le 28/09.

Trois réserves :

- **`composer test` dépasse le délai de processus de Composer** (300 s) sur ce poste : Pint et PHPStan passent, puis Composer tue Pest. Lancer la dernière commande du script directement (`php -d memory_limit=1536M artisan test --exclude-group=mysql --exclude-group=locks-timing`), ou poser `COMPOSER_PROCESS_TIMEOUT=0`.
- ~~La suite `Concurrency` sur MySQL n'est pas verte~~ : corrigé le 28/09 (`0e41a3a`, E116-10).
- ~~Un test est instable~~ : corrigé le 28/09 (`c9017e0`, E122-7).

`npm run check`, `tsc --noEmit` et `npm run build` font partie de la définition de « terminé » de chaque lot (§ A.1) ; ils n'ont pas été rejoués pour ce relevé. Le piège du 23/09 demeure : un `npm run dev` actif (`public/hot`) fait échouer les rendus de page Inertia de la suite (tentative de rendu SSR vers Vite). Toujours arrêter le serveur Vite avant de lancer les tests.

### Dettes encore ouvertes, vérifiées dans le code le 23/09

`100` § 17 les tient à jour. Chacune est soldée par un lot de l'annexe. **Au 28/09, toutes sont soldées sauf la dernière, qui relève du J2** : `.env.example` porte `BROADCAST_CONNECTION=reverb`, `REDIS_CLIENT=predis`, `ACCOUNTS_*` et `REVERB_ALLOWED_ORIGINS` ; `composer dev` lance Reverb et la file `game` ; `ForceAdminAppearance` et `profile.destroy` sont retirés ; `symfony/polyfill-intl-normalizer` est une dépendance directe ; `public/avatars/` existe — amendé le 28/09.

| Dette | Soldée par |
|---|---|
| Aucun temps réel : `BROADCAST_CONNECTION=log`. `laravel/reverb`, `laravel-echo` et `pusher-js` ne sont pas installés. `predis/predis` n'est pas requis, et `.env.example` dit encore `REDIS_CLIENT=phpredis` | L60-1 (étape 10), jamais par `install:broadcasting` |
| `.env.example` incomplet. Manquent : les lignes Reverb et Redis (L60-1) ; `ACCOUNTS_*` (L40-7) ; indexation, drainage, sauvegarde, sonde, `REDIS_QUEUE_RETRY_AFTER` et `REVERB_ALLOWED_ORIGINS` (L100-4) ; OAuth (`40` J2). `FRAMES_DISK_ROOT` et les deux clés TMDB y sont déjà, vides | L60-1, L40-7, L100-4 (étapes 10, 14, 11) |
| `composer dev` ne lance ni Reverb ni la file `game` | L100-4, seul écrivain (étape 11) |
| Le back-office force toujours le thème clair (`ForceAdminAppearance`) | L90-1, retrait selon D8 du 23/09 (étape 12) |
| La suppression de compte `profile.destroy` est encore exposée ; l'inscription et les passkeys ne sont fermées nulle part | L40-7 (étape 14) |
| `symfony/polyfill-intl-normalizer` n'est pas une dépendance directe | L40-3 (étape 67) |
| `public/avatars/` n'existe pas | L40-5 (étape 63) |
| ~~Socialite absent ; page `dashboard` du starter ; feuille mobile anglaise d'`AppLayout` (dette n° 24 côté joueur)~~ — **soldé** : Socialite livré (D51 du 01/10) ; `dashboard` retiré, `fortify.home` = `/settings/profile` ; coquille à en-tête et écrans de compte réécrits (L90-17, `90` § 11.2, 07/10) | `40` J2, `90` J2 |

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
| D8 | Le back-office suit l'apparence choisie — **révisée par D56 du 02/10** : tout le site sombre, aucun choix | non |
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

### Décisions du 28/09

Relevé seulement ; détail dans `questions-ouvertes.md` § « Décisions du 28/09/2026 ». On les cite « D38 du 28/09 » — amendé le 28/09.

| # | Décision | Écart |
|---|---|---|
| D38 | **Voie capture ouverte au J1**, avant l'arbitrage de licéité (resté ouvert au conseil) ; R-46 confirmé ; minutage obligatoire ; `CURATION_CAPTURE_ENABLED` ouvert par défaut, `false` le ferme | **oui** — lève le repli de la décision 7 |
| D39 | Backdrops TMDB porteurs d'une langue masqués, comptés, refusés à l'ajout | — |
| D40 | Gestion des comptes (L20-19) et annuaire livrés en avance du J2 ; EN20-3 acceptée | — |

### Décisions du 30/09

Relevé seulement ; détail dans `questions-ouvertes.md` § « Décisions du 30/09/2026 ». On les cite « D41 du 30/09 » — amendé le 01/10.

| # | Décision | Écart |
|---|---|---|
| D41 | **Tout geste du back-office** au journal `admin_action`, lectures sensibles comprises (annuaire, fiche d'un compte, écran des accès), rétention permanente ; dix-neuf cas nouveaux, sujets `import_run` et `accounts`, colonne `details` ; écran « Journal », administrateur seul | **oui** — renverse la règle du « geste engageant » |
| D42 | **« Tout valider »** les images en attente d'un film : une ligne `frame_review` par image, une seule ligne `movie.frames_reviewed`, tout ou rien sous verrou | **oui** — la revue n'est plus seulement image par image à l'écran ; la preuve, si |

### Décisions du 01/10

Relevé seulement ; détail dans `questions-ouvertes.md` § « Décisions du 01/10/2026 ». On la cite « D43 du 01/10 » (D42 était déjà pris) — amendé le 01/10.

| # | Décision | Écart |
|---|---|---|
| D44 | **Leurres apparentés** : saga, puis thème studio, saga ou manuel, puis genre, dans le catalogue publié entier ; un seul groupe fournit les trois leurres ; puis R1-R4 inchangée — amendé le 01/10 | **oui** — un étage avant R1 et avant R3 ; deux contextes `DrawContext` |
| D43 | **Thèmes par film au J1** : évaluateur et `catalog:themes` (L30-8), thèmes livrés étendus à DC, Avatar et Iron Man avec une **règle studio multi-valeurs** (L30-9), mesure d'un thème (L30-11a), back-office des thèmes (L20-28 hors difficulté) — création de toute nature et thèmes manuels, appartenance manuelle par film, « Créer la saga depuis cette collection », thèmes choisis au collage (jamais un `removed` changé en `added`) ; noms des sociétés TMDB stockés (`tmdb_company`) ; cinq cas du journal. Sélecteur du lobby et difficulté dérivée au J2 | **oui** — remonte des lots marqués J2 |

---

## 3. Ce qui bloque — le chemin humain (D36 du 23/09)

Le développement ne borne plus rien : **ce qui fixe la date de la première vraie partie, ce sont les gestes du porteur.** L'IA ne doit jamais être ce qui les fait attendre.

| # | Geste du porteur | Bloque | Étape |
|---|---|---|---|
| 1 | **Acheter le domaine** (décision 5), **avant la semaine 4** (D1 du 23/09). Environ dix euros par an, seul achat du chemin critique. Le nom ne vit que dans le `.env` de production et les réglages Plesk ; le dépôt garde `<DOMAINE>` pour toujours (`100` § 7.2, `NoLiteralDomainTest` ; écart D-2 ci-dessous). Aucun compte de production ni aucune passkey avant l'achat ; aucune passkey au J1 | la mise en service (27), donc toute la curation | 1 |
| 2 | **Relever le VPS**, puis le **monter en root**. Le relevé : `ssh root@`, `free -m`, `nproc`, `uptime`, `ss -ltnp`, `/opt/plesk/php/8.3/bin/php -m`, abonnements servis, version de Plesk, **région UE**. Seuil défendable : 4 Go de RAM, 2 vCPU. Sans root, pas de Redis dédié, pas de workers systemd, pas de Reverb : **arrêt et question au porteur**. Le repli sans root (second VPS, contraire à la décision 16) est ouvert | la préparation des gabarits `ops/` (26) et la mise en service (27) | 2, 27 |
| 3 | **Constituer la liste d'amorçage** : environ 200 identifiants TMDB, relue une fois, 4 à 6 h hors réserve. Peut commencer tout de suite | la porte du pilote (54, 56) | 3 |
| 4 | **Vérifier la licence du pack d'avatars** Kenney au téléchargement et la consigner dans `public/avatars/LICENSE.md` (D27 du 23/09). Le pack s'appelle aujourd'hui « Animal Pack Remastered » ; le fichier est encore à `[À FOURNIR]`. Vérifier aussi la lisibilité à 32 px et trancher l'élan `preset-13` (E63-1 à E63-3, § 4) — amendé le 28/09 | la clôture de L40-5 et la première partie (133), pas les lots suivants | 64 |
| 5 | **Choisir les fournisseurs UE du J1** : stockage objet de sauvegarde chez un fournisseur **distinct** de l'hébergeur du VPS, supervision externe, second canal d'alerte ; ouvrir les comptes | la sauvegarde active avant la première image curée (30), donc le pilote | 4 |
| 6 | **Nommer les sous-traitants UE (J2)** : un par catégorie branchée (hébergeur, SMTP, sauvegarde, supervision, second canal, suivi d'erreurs s'il est branché), plus le registrar | la page de confidentialité, donc l'ouverture du J2 | — |
| 7 | **Commander les textes légaux (J2)** (décision 4). Délai externe de 2 à 6 semaines, **à lancer pendant le J1**. Poser au même conseil la question de la **licéité de la capture** (liste fermée des sources autorisées) | l'ouverture du J2 et la voie capture (L20-33, J2) — amendé le 28/09 : la voie capture n'attend plus la réponse, ouverte au J1 par D38 du 28/09 ; une réponse restrictive la fermerait (`CURATION_CAPTURE_ENABLED=false`) | 5 |
| 8 | **Rafraîchir la base de dev** : `php artisan backup:snapshot` (code 0 exigé, règle 12), puis `php artisan migrate:fresh --seed`. La base de dev a joué l'ancienne migration de création de `game_player` et garde `final_rank` en `tinyint` (I-12) ; `migrate:fresh` recrée toute la base, catalogue compris (`10` § 13.2, n° 47, E25-1) — amendé le 25/09. Il joue aussi les migrations additives de la phase C (n° 44 à 46), dont la n° 46, livrée sans avoir été jouée sur la base MySQL de dev (E78-1) — amendé le 28/09 | la parité du schéma de dev avec celui des tests et de la production | tout de suite |
| 9 | **Nom réel complet du titulaire dans `LICENSE`**. Le fichier a été livré par L100-2 avec le nom d'auteur git (« Maxence »), faute de saisie possible par une porte non interactive ; `LicenseTest` n'écrit aucun nom en dur (`100` § 7.6, E7-6, E7-15) — amendé le 25/09 | tout push vers la forge | avant le premier push |
| 10 | **Déposer le logo officiel TMDB** dans `public/brand/tmdb.svg` et **dater ses conditions d'usage** dans `public/brand/LICENSE.md` ; dans le même commit, passer sa ligne dans « Actifs livrés » de `THIRD_PARTY_NOTICES.md` (`90` § 3.2 et point resté ouvert n° 13, E15-2) — amendé le 25/09 | la mise en service (27), au plus tard | 27 au plus tard |
| 11 | **Vérifications manuelles au navigateur**, qu'aucune porte automatisée n'a pu jouer : (a) le back-office **à 375 px** — parcours clavier et affichage de la coquille mobile et d'`admin/two-factor-required` (`20`, L20-2, E18-9) ; (b) le **recadreur au clavier et à la souris sur la vraie page de l'éditeur** (L20-9a, L20-9b, L20-10) ; (c) sur **iOS Safari, l'appui long** sur une image de jeu, qui ne doit ouvrir aucun menu (`-webkit-touch-callout: none`, `90`, L90-6a, E39-6) — amendé le 25/09 ; (d) la **voie capture** de bout en bout — choisir, coller, minutage, envoi, traitement, revue —, jamais essayée à la main à sa livraison (L20-33, D38 du 28/09) — amendé le 28/09 | la porte du pilote (56) ; (c) est rejouée à la recette sur appareil réel (127) | avant 56 |
| 12 | **Avant le déploiement de L30-8 et L30-9** (D43 du 01/10) : `php artisan backup:snapshot`, **code 0 exigé**, pris à la main avant le déploiement ; le hook rejoue `PlatformDataSeeder`, qui dispatche une synchronisation par thème inséré (`30` § 13.2). `movie_theme` n'est pas une table de la règle 12, mais une passe sur tout le catalogue suit la même prudence. Les synchronisations partent sur la file par défaut à l'étape 6 du hook, avant `queue:restart` (étape 10) : un worker encore sur l'ancien code peut les consommer, et `SyncThemeMembership` n'a qu'un essai. **Après** le déploiement : `php artisan queue:failed` ; si une `SyncThemeMembership` y figure, `php artisan catalog:themes` (instantané en tête) rattrape toutes les appartenances — amendé le 01/10 | rien ; prudence avant écriture de masse | 137, 139 |
| 13 | **Après le déploiement de L30-8** : `php artisan catalog:themes` (elle prend elle-même son instantané en tête et n'écrit rien s'il échoue), puis `php artisan catalog:company-names` avec la clé TMDB de production, jamais en CI (n'écrit que `tmdb_company`, hors règle 12) — amendé le 01/10 | les appartenances et les noms de société des films déjà importés | 138 |
| 14 | **Après le déploiement de L20-28** : corriger en back-office la règle de `studio.disney` vers `2,6125,171656` (Frozen et Moana ne portent que 6125), geste journalisé `theme.updated` ; aucune migration ni aucun seeder ne le fait (`30` § 12.3) — amendé le 01/10 | l'exactitude du thème Disney | 144 |
| 15 | **Publier les thèmes** livrés ou créés, un à un, quand chacun atteint 10 œuvres jouables au réglage par défaut : avec environ 60 films publiés, presque aucun ne l'atteint ; la publication est **attendue tard au J1**, ce n'est pas un défaut. `language.anime` seulement après avoir retiré les films japonais en prise de vue réelle — amendé le 01/10 | rien au J1 (sélecteur masqué) | 145 |

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
- **2FA de `curator` et `admin`** et **dormance à 24 mois puis 30 jours** : valeurs retenues, à confirmer dans `40` J2. — **Confirmées** le 07/10 (D66 du 07/10, n° 10 et n° 28).
- **Seuil du taux de réussite** : 20 manches.

### Gestes humains du J2 (D66 du 07/10)

Ils ne se codent pas ; le code est livré prêt et chacun est documenté dans la spec citée. Aucun ne se simule.

- **Variables de production**, posées au bon moment, jamais dans le dépôt : `ACCOUNTS_REGISTRATION_OPEN=true` (ouverture de l'inscription) ; `ACCOUNTS_PASSKEYS_ENABLED=true`, après avoir vérifié que l'hôte d'`APP_URL` est l'apex `<DOMAINE>`, sinon `PASSKEYS_RP_ID` et `PASSKEYS_ALLOWED_ORIGINS` — **point de non-retour : la première passkey de production** (`40` § 13.8) ; `SITE_INDEXABLE=true` après les préconditions de `100` § 21 ; `game.platform.theme_selector_min_pool` ≈ 60 ; `MAIL_*`, `LEGAL_CONTACT_EMAIL`, `LEGAL_ALERT_EMAIL` ; `EXPORTS_DISK_ROOT`.
- **SMTP transactionnel UE** : choisir et contractualiser ; sans lui, ni accusé de retrait, ni décision, ni export, ni rappel de dormance ne part.
- **VPS** : vérifier `ext-zip` sur PHP 8.3 FPM et CLI ; créer `EXPORTS_DISK_ROOT` hors du chemin de déploiement et l'exclure des sauvegardes.
- **Préproduction** (`100` § 18, `ops/mise-en-service.md` § 12) : abonnement `preprod.<DOMAINE>` (DNS, Let's Encrypt, base séparée), un geste root pour Redis et les unités systemd, authentification HTTP (`htpasswd -B`), Plesk Git sur `deploy-preprod` ; **une seule fois**, `composer install` avec les dépendances de développement puis `db:seed --force` (le catalogue de démonstration exige Faker) ; `admin:first-admin --create` de recette (sans `--force` : l'administrateur semé ne compte pas) ; navigateurs Playwright sur le poste (`npx playwright install chromium`), trois parcours avant chaque `promote` (commit relevé dans Plesk Git de la préproduction), puis le tirage manuel de production. **Dès que ce lot atteint `main`, la production ne reçoit plus rien sans `promote`** : `artifacts` ne pousse plus `deploy`.
- **Supervision** : configurer les sondes `takedown-ack` et la purge étendue (`100` § 25).
- **Commandes manuelles** : `catalog:themes` une fois après L30-10 (il commence par un instantané) ; `takedown:reconcile` après toute restauration ; `site:close` reste un geste de console.
- **Restauration complète** chronométrée sur le VPS (clé `APP_KEY` et clé privée `age` hors ligne), temps consigné dans `100` § 13.6.
- **Base MySQL de test** sur Homestead pour la preuve `locks-timing` de L20-19.
- **Juridique** : textes opposables en français (mentions légales, CGU, confidentialité, procédure de signalement) ; sous-traitants nommés et registre ; confirmation des 5 ans de `takedown_identity` ; modèle de notification de violation ; relecture de la bannière et des engagements (72 h, 7 jours ouvrés, fermeture sans délai) ; logo officiel TMDB (`public/brand/tmdb.svg`) ; passage de `legal.terms_version` à la version définitive dans le commit qui publie les CGU.
- **Organisation** : au moins deux administrateurs nominatifs ; seconde adresse d'administration ; personne de confiance ; inventaire des accès scellé hors dépôt.
- **Données et relectures** : L70-13 sur parties réelles et test de charge (`answers:collisions`, `ReportBruteForce`) ; publier les thèmes un à un ; publier une grille marquée rétroactive (sinon L20-25 répond « indisponible ») ; promouvoir un second curateur ; relire les textes FR et EN (compteurs et taux, e-mails d'export, de dormance et de retrait, crochet de compte au podium, page de fermeture, Open Graph, interstitiel de ré-acceptation des CGU et cases de consentement à l'inscription) ; tester à 360 px sur un vrai téléphone les écrans de compte réécrits, le signalement et le libellé masqué.
- **Premier administrateur** : accepter les CGU à sa prochaine connexion ; confirmer son TOTP ; n'enregistrer sa passkey qu'après l'ouverture des passkeys en production.
- **Changement de téléphone d'un compte privilégié** (`40` § 13.8) : la 2FA d'un curateur ou d'un administrateur ne se désactive plus depuis son écran Sécurité. Téléphone perdu : se connecter par un code de récupération. Nouvelle application d'authentification : un autre administrateur retire le rôle (`role.changed` vers `player`), le titulaire coupe puis réenrôle son TOTP, le rôle est rendu. D'où l'exigence d'au moins deux administrateurs nominatifs (ligne « Organisation ») ; un administrateur seul garde ses codes de récupération hors ligne.
- **Textes génériques de partage** : relire `common.meta.title` et `common.meta.description` (FR et EN), affichés par tout aperçu de lien, salon compris (`90` § 11.5).
- **Liste noire des pseudos bannis** (`40` § 13.3, n° 8) : après chaque bannissement, recopier la forme repliée affichée par l'écran « Pseudos » du back-office dans `resources/moderation/nicknames/banned.txt` (ligne relue), commiter puis déployer. Vérifier une fois, sur téléphone, le bouton « Signaler le pseudo » et le libellé « Joueur n » au lobby et en partie.
- **`dev.<DOMAINE>`** (facultatif) : enregistrement DNS vers 127.0.0.1, certificat DNS-01, URI de redirection Discord et Google (`100` § 27).

---

## 4. Questions encore ouvertes

**Règle d'exécution.** Quand une spec écrit une **lecture retenue**, l'IA l'applique sans attendre. Seul le lot concerné attend l'avis du porteur quand aucune lecture n'est retenue. Aucune des questions ci-dessous ne bloque l'étape 6.

| Question | Propriétaire | Quand, et ce qu'elle bloque |
|---|---|---|
| **Lot 9 non posé**, trois questions : effet de « bannir un pseudo » (`nickname.banned`), preuve du consentement aux données provider, avatar d'un invité qui crée un compte | `40` § 10.2, à poser avec options et recommandation | à l'écriture de `40` J2 ; rien au J1 |
| Confirmation de la 2FA de `curator` **et** `admin`, et de la dormance (24 mois + 30 jours). La garde `admin.2fa` (L20-2, livrée) applique déjà la 2FA aux deux rôles ; revenir à `admin` seul coûterait une condition dans `EnsurePrivilegedTwoFactor` et ses tests (E18-8) — amendé le 25/09 | `40` J2, `20` | réversible ; rien au J1 |
| **R-46 — voie capture** : le serveur dérive toujours le dérivé de jeu du master et du rectangle ; le navigateur n'envoie qu'une source normalisée d'au plus 1 536 Ko et un rectangle. Tranché par le rédacteur, **à confirmer par le porteur** — **confirmé par D38 du 28/09**, livré ainsi au J1 (L20-33) ; question close — amendé le 28/09 | `20` § 5.4 | sans effet au J1 (capture désactivée) — devenu effectif au J1, la voie étant ouverte (amendé le 28/09) |
| **R-47** — suffixe `.label` des clés de la grille (`admin.exclusion_grid.v{n}.{slug}.label` et `.help`) : écart de forme seulement | `20` § 7.1 | à confirmer ; L20-12 applique la forme de `20` |
| **Liste fermée des sources autorisées** pour une capture personnelle (licéité, décision 7) | conseil du porteur | bloque L20-33 (J2) ; au J1, voie TMDB seule — amendé le 28/09 : ne bloque plus rien, L20-33 étant livré au J1 par D38 du 28/09, qui en assume le risque ; la question **reste ouverte** au conseil, et une réponse restrictive ferme la voie par `CURATION_CAPTURE_ENABLED=false`, puis se traite capture par capture (`source_kind`) |
| **Tier froid des captures dès le J1** (D38 du 28/09, EL33-4) : une capture est irremplaçable ; le tier froid doit couvrir les deux fichiers de toute frame `source_kind = 'capture'` dès le J1, et être actif avant la première capture curée en production. `BackupManifestCommand` (`backup:manifest --cold`) n'est pas encore écrite | `100` § 13.3, L100-10 | étape 30, avant la première capture curée |
| **`ops/mise-en-service.md` l.118** dit encore « vide : voie capture fermée au J1 (`20` § 5.4) », et `docs/ops/repetition-vm.md` liste la variable vide avec le même sens : la valeur vide reste juste, son sens a changé (ouverte). Fichiers modifiés par le porteur, non touchés le 28/09 ; à réaligner à la prochaine passe `ops/` (EL33-5) | le porteur, `100` | avant la mise en service (27) |
| **N100-2** — passage du tier froid en **quotidien**. Proposé, non appliqué : il reste hebdomadaire jusqu'à accord | `100` § 13.3 | avant la première image curée (étape 30), idéalement |
| **Typographie française** commune à tous les dictionnaires (U+00A0 ou U+202F avant « : ; ! ? % » et dans « »), signalée par `80` § 15.1 | `05` | aucun lot du J1 |
| **Reformulation de `game.help.prefix`** : « jouables » y désigne le vivier au lexique de `00`. Proposition appliquée par L90-7 (« Quand le site compte plusieurs films d'une même saga… ») : la confirmer, ou rétablir le texte de `70` (E99-1) — amendé le 28/09 | `90` point 3 ↔ `70` § 7.7 et § 17, arbitrage du porteur | texte livré ; aucun lot bloqué |
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
  - **n° 15, remède « nouveau salon » : aucune lecture retenue, décision produit toujours due** ; L50-4 et L50-5 ont livré le remède tel quel (E120-8, amendé le 28/09) ;
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
  - dette n° 24 (J2) — soldée le 07/10 (`90` § 11.2).
- **`100`** :
  - N100-2 ;
  - exigences aux specs sœurs, dont la garde d'instantané des imports (I-9, appliquée par L20-16) ;
  - personne de confiance.

**Questions relevées par le report des écarts des phases A et B** (— amendé le 25/09). Chacune est écrite dans la section « Ce que cette spec ne décide pas » de sa spec propriétaire, sous son identifiant `E<étape>-<n>`. Tant qu'aucune n'est arbitrée, **le code livré fait foi** et l'IA applique la lecture retenue quand il y en a une. Aucune ne bloque la phase C avant l'étape indiquée.

| Question | Propriétaire | Quand, et ce qu'elle bloque |
|---|---|---|
| **E22-7** — l'audit du plancher ne compte que les frames **corrigibles** (ni `withdrawn` ni sans `game_path`) : lecture de `20` § 5.2, retenue contre la formule littérale de `20` § 5.9, qui laisserait la sonde `integrity` rouge pour toujours après un durcissement du plancher. À ratifier | `20`, `10` § 4.1, `100` | lecture retenue appliquée ; à ratifier avant le calibrage du plancher (59) |
| **E46-1** — une image **dépubliée puis rejetée** reste pour toujours dans « Rejetées ». (a) l'accepter, **retenu au J1** ; (b) l'exclure de la liste, sa revue devenant impossible ; (c) un geste « laisser hors du jeu », qui ajouterait une colonne ou un cas `admin_action` | `10` § 15, `20` | lecture (a) appliquée ; (c) toucherait le schéma |
| **E46-8** — une garde `deleting` sur `FrameReview` ? Policy, garde `saving` et `AppendOnlyBuilder` ferment déjà le reste, mais un `$review->delete()` écrit dans le code passe encore | `10` § 15 | aucun lot bloqué |
| **E24-2** — un index `failed_jobs(failed_at)` avant le J2 ? `failed_at` n'est que la troisième colonne d'un index composite, et `password_reset_tokens.created_at` n'a aucun index. Au J1, aucune migration : tables petites, balayage par lots | `10` § 12 et § 15 | avant le J2 |
| **E42-10** — annoncer la baisse de `k` d'un film déjà incomplet qui reste jouable ? | `20` | aucun lot bloqué |
| **E43-2** — la lecture des touches `+`, `-`, `=`, `_` et Maj du recadreur convient-elle ? | `20` | à confirmer à la vérification manuelle du recadreur (§ 3, geste 11) |
| **E50-2** — une revue rejetée ne fait pas remonter son film dans la file de curation. Faut-il élargir l'ordre ? | `20` | aucun lot bloqué |
| **E42-8** — une sortie du jeu dans la même seconde qu'une revue passante fait disparaître l'image de toutes les files. Signalé à L20-12, non tranché | `20` | aucun lot bloqué |
| **E40-6** — les aperçus admin en `<img>` écrivent `_previous.url` et `url.intended` : passer par `fetch`, ou accepter le risque ? Non tranché par L20-10 | `20` | aucun lot bloqué |
| **E46-12** — le `game_url` de l'éditeur (`bankFrame()`, `sequencePreview`) n'est pas versionné : un rendu refait peut rester affiché sous l'ancienne adresse. Quel marqueur choisir ? | `20` | aucun lot bloqué |
| **E18-5** — défaut hors lot, non corrigé : `import/show.tsx` affiche `worker_missing` sans le délai de grâce de 60 s | `20` | à corriger ; aucun lot ne le porte |
| **E36-1** — salon ouvert pendant un déploiement qui **resserre une borne** : (a) le remède `reduce_rounds_count` propose une valeur que l'éditeur refuse (`count > MAX`) — plafonner `value`, ou garder la lecture littérale de `30` § 4.3 ? (b) la garde de `N` de `PoolScope` lève hors bornes et fait échouer l'état du lobby et ses diffusions (`RoomSettingsPresenter::state()`) — tolérer un `N` périmé au lobby, ou garder la garde ? L50-2 a livré le code inchangé, sans tolérance (E84-4, amendé le 28/09) | `30`, `50` point ouvert n° 17 | sans effet tant que `VERSION = 1` ; avant tout resserrement de borne |
| **E9-7** — le test « garde VERSION à 1 tant que FIELDS est inchangé » est livré à la lettre, sans instantané des bornes. Valider la proposition de le renommer | `50` point ouvert n° 8 | aucun lot bloqué |
| **Constat de l'étape 14** (L40-7) — **soldé** par L90-8 : l'accueil réécrit ne rend plus aucun lien de connexion, d'inscription ni de tableau de bord ; ils ne vivent plus que dans l'en-tête public, sous `accountsOpen` (E124-1, amendé le 28/09) | `40`, `90` point ouvert n° 14 | plus rien à trancher |
| **E16-3** — quatre affirmations des partiels légaux de L90-4 sont à relire : trois reposaient sur des règles pas encore codées (pseudo unique dans un salon, liste noire, source déclarée de chaque image), la quatrième porte sur l'adresse IP en session. Le pseudo unique et la liste noire sont codés depuis L40-3, L40-4 et L50-3b ; seule la relecture reste due (E68-4, amendé le 28/09) | `90` point ouvert n° 15, `40` | avant l'ouverture du J2 |
| **E39-1** — **fermé** par L60-9 : `GameFrame` reçoit la prop facultative `pending` (défaut faux, aperçu admin inchangé). L'écart reste à consigner à C16 § 2.5 (E95-1, amendé le 28/09) | `60` § 24, `90` point ouvert n° 12 | plus rien à trancher |
| **E10-1** — le retour à Guzzle 8 attend une version de `laravel/reverb` compatible avec `guzzlehttp/psr7 ^3` (Guzzle rétrogradé de 8.2 à 7.15 par L60-1) | `60` § 24 | rien au J1 |
| **E10-8** — (a) ajouter au § 19.1 de `60` une note : les gardes de débit bornent le pic d'une fenêtre fixe (39 lectures, 30 chargements), pas la moyenne ; (b) activer ou non `REVERB_APP_RATE_LIMITING_ENABLED` en production (défaut du paquet : faux) | `60` § 24, `100` | (b) après la séance de charge (131) |
| **E7-11** — les icônes héritées du starter (`public/favicon.ico`, `public/favicon.svg`, `public/apple-touch-icon.png`, logo Laravel) : les remplacer par une icône du projet, ou les relever avec leur licence dans `THIRD_PARTY_NOTICES.md` | `100` | avant l'ouverture publique (J2) |
| **E11-8** — le job `artifacts` est livré scindé en un job `build` en lecture seule puis un job `artifacts`, seul détenteur du jeton d'écriture. La retenir, et amender alors `100` § 2.5 | `100` § 2.5 | livré ; à ratifier |

Les gestes du porteur relevés par ce report (nom réel dans `LICENSE`, logo TMDB, base de dev, vérifications au navigateur) sont au § 3, lignes 8 à 11.

**Questions relevées par le report des écarts de la phase C** (étapes 62 à 125, — amendé le 28/09). Chacune est écrite dans la spec propriétaire, sous son identifiant `E<étape>-<n>` (section « Ce que cette spec ne décide pas », ou « points restés ouverts » de `50` et de `90`). La règle est la même que pour les phases A et B : **le code livré fait foi** tant que le porteur ne s'est pas prononcé. Aucune décision antérieure n'est rouverte. La spec `30` n'ajoute aucune question : ses points E71-3 et E72-2 sont résolus par le code livré (E72-1, E90-2).

| Question | Propriétaire | Quand, et ce qu'elle bloque |
|---|---|---|
| ~~**E116-10**~~ — corrigé le 28/09 (`0e41a3a`) : écouteur de requêtes borné à l'appel bloqué | `60` § 24 | fermé |
| ~~**E122-7**~~ — corrigé le 28/09 (`c9017e0`) : avatar fixe pour l'hôte du salon libre | `40` | fermé |
| **E63-1, E63-2, E63-3** — étape 64 : vérifier le nom du pack (aujourd'hui « Animal Pack Remastered » de Kenney), sa licence CC0 et la lisibilité à 32 px, vérifiée par l'IA seulement. `public/avatars/LICENSE.md` est encore à `[À FOURNIR]`. Accepter l'élan (`preset-13`) aux bois rognés, ou le remplacer sous la même clé | `40` § 6, geste 4 du § 3 | étape 64, donc la première partie (133) |
| **E68-2** — liste noire des pseudos : valider les sept entrées retirées, trancher le retrait de « xx » et « xxx », relire les faux positifs conservés | `40` § 5.7 | aucun lot bloqué |
| **E67-8** — ajouter le modificateur `D` à `ValidNickname::ALLOWED_PATTERN` (contrat C5), ou garder seule l'application du motif caractère par caractère | `40` § 5.4 | aucun lot bloqué |
| **E111-7, E101-6** — aucun lot du J1 ne livre de changement d'avatar ou de pseudo hors prise de siège. Nommer le lot qui rebranchera la route de test `…/resign` de `PlayerTokenTest` et l'en-tête périmé de `NicknameBlocklistTest`. **Fermé pour l'avatar par D55 du 02/10** (geste `room.avatar.update`, lot L50-14) ; reste le pseudo | `40`, `50` point n° 28 | aucun lot bloqué |
| **E65-2** — domaine du cookie `locale` : le tenir « hôte seul » comme le `player_token` (construction directe du cookie), ou lui laisser le domaine de session (`SESSION_DOMAIN`) | `05` | sans effet tant que `SESSION_DOMAIN` est vide |
| **E62-6** — pour un curateur, trois erreurs du back-office tombent sur la page `error` joueur : 404 d'un film inconnu, 429 de `throttle:admin-*`, 419. L'accepter, ou étendre la règle `admin/error` aux routes `admin.*` pour tout visiteur au moins curateur | `20` § 13.2, `90` point n° 16 | aucun lot bloqué |
| **Ordre et verrous du salon**, lectures livrées à confirmer : salon archivé refusé **avant** la réparation d'hôte, au lancement et à « Rejouer », contre la lettre de C6 § 3 (E102-1, E115-1) ; « Rejouer » déjà fait pendant un drainage rend `null` sans message (E115-1, lecture du § 14) ; verrou de la partie à l'admission d'un retardataire (E116-1) | `50` points n° 18, 19 et 21 | appliquées ; aucun lot bloqué |
| **403 `not_host` d'un hôte déchu** entre l'affichage et le clic : page `error` (livré), ou interception par une relecture (E110-5, E111-7) | `50` point n° 25 | aucun lot bloqué |
| **Rattrapage au rendu de page** : appeler `CatchUpGame` aussi dans `room.show` et `solo.show`, au prix d'écritures sur un GET de page ; aujourd'hui, seules `room.state` et `solo.state` rattrapent (E109-2) | `60` § 24, `50` point n° 24 | aucun lot bloqué |
| **Textes et clés à valider** : créer `room.errors.replay_failed` (E115-3) ; formulations de `room.identity.*` et de `validation.attributes.publicId` (E101-4, E111-3) ; textes FR/EN des clés posées par `60` : `game.errors.{not_revealing, round_not_running}`, `game.round.*`, `game.reveal.*`, `game.pause.*`, `game.solo.*` et `room.solo.*` (E112-4, E119-3, E121-7, E123-6) ; textes de l'accueil `common.home.*` (E124-2) | `50` points n° 20 et 23, `60` § 24, `90` point n° 22 | textes livrés en FR et EN ; aucun lot bloqué |
| **Mention des CGU** : le texte `legal.terms_notice` sert de texte au lien vers `legal.terms` (livré), ou un texte suivi d'un lien `legal.footer.terms` (E101-5) | `50` point n° 22, `90` point n° 17 | livré ; à confirmer |
| **Purge et échéances** : `purge:suspend` doit-il suspendre aussi le balayage `stale_lobby` (E113-2) ? Un seuil de fraîcheur propre à `stale_lobby`, par exemple 1 h, au lieu de 48 h (E114-2) ? Les durées d'archivage d'un salon et d'effacement d'un siège solo doivent-elles pouvoir diverger (E122-3) ? Refus défensif d'effacer un siège solo dont une partie n'est pas figée, ou `last_seen_at = now` à la reprise dans `StartSoloGame` (E122-2) ? | `100` § 14, `50` points n° 26 et 29, `60` § 24 | aucun lot bloqué |
| **E122-4** — la purge quotidienne efface un siège solo inactif entre 24 h et environ 48 h après sa dernière activité : publier « au plus 48 h », ou exécuter la branche solo plus souvent | `100` § 14, `90` point n° 21 | avant la publication du tableau de conservation (J2) |
| **Preuves MySQL `locks-timing`** non nommées : sérialisation de l'archivage, du battement et de la prise de siège (E113-6) ; absence d'interblocage entre battement et balayage, et geste « manche suivante » contre un transfert d'hôte concurrent (E112-6, E112-7) | `50` point n° 27, `60` § 24, avec `100` (C18) | aucun lot bloqué |
| **Lectures du moteur appliquées, à confirmer** : E90-1 (`ServeUrl`, `frame.serve` et un contrôleur fermé avancés à L60-5) ; E91-1 (l'ouverture d'un palier 1 attend la fin de toute révélation de la partie) ; E91-3 (`InterruptPausedGame` réveillé tôt attend en processus) ; E93-1 (au rattrapage, `round.scheduled` compte comme l'étape de sa manche) ; E109-1 (`round.reveal` exige la phase `revealing`) ; E112-2 (balayage de présence réarmé à la seconde supérieure) ; E116-10 (`OpenTier` prend le salon en partagé) ; E119-2 (lecture côté client de la phase `running`) ; E121-3 (« un double clic ne crée qu'une partie » lu « une seule partie en cours ») ; E123-2 (un geste solo refusé reste un battement) | `60` § 24 | appliquées ; aucun lot bloqué |
| **`REVERB_MAX_REQUEST_SIZE`** à 512 Kio, plancher utile (pire cas mesuré de `game.ended` : 495 481 octets), ou 1 Mio si les charges de fin de partie doivent grossir (E83-5, E108-4) | `60` § 19.5, `100` | à valider avec la mémoire réelle du VPS (relevé, geste 2) |
| **Écarts de contrat du moteur** : retirer `Vary: X-Inertia` et `X-RateLimit-*` des réponses `/f/` (E94-3) ; ajouter `maxAnswerLength` à la charge de `game.launched` (E95-3, contrat C7 § 2.3) ; instant de fin anticipée quand deux clôtures se croisent (E108-3, C7 § 4.6) ; une partie en pause n'est jamais tenue pour bloquée (E96-6, lecture livrée) | `60` § 24 | aucun lot bloqué |
| **Résidus du moteur** : un battement reçu pendant un passage du balayage peut retarder une transition (E112-2) ; un battement en vol au clic de « Quitter le salon » peut ramener le siège à `connected` (E112-8) ; re-signature du jeton non courue après l'échec d'un premier siège solo (E121-13) ; cause `skipped` à ajouter à `game.round_closed` (E123-7) | `60` § 24 | E112-2 et E123-7 après la séance de charge (131) ; les autres, aucun lot bloqué |
| **Source de `attemptsLeft`** tant que `self.input` est nul : `GameStatePacket.attemptsPerRound` (voie recommandée) ou `attemptsLeft: number \| null` (écart à C10 § 2). Au J1, la lecture livrée ne touche aucun contrat (E117-5, E119-4, E123-3) | `60` § 24, `70` § 16 | rien au J1 |
| **Écran de saisie** : confirmer les props et le retour ajoutés, tous facultatifs sauf les options du hook, et leur report dans C10 § 2 et C11 § 2 (E117-1) ; après un refus, sélectionner le texte envoyé (livré) ou vider le champ (E117-2) | `70` § 16, `90` | livré ; à confirmer |
| **Budget vertical clavier ouvert** : l'image passe sous 40 % de la hauteur visible, jusqu'à une vignette en Normal après `T_N` ou sous deux bandeaux. Quatre voies de présentation, dont l'amendement de `90` § 7.2 (E119-6, E125-3) | `90` point n° 18, `60` § 19.3, `70` § 16 | après la mesure sur téléphone réel (127) |
| **E125-4b** — quand clôture, révélation et manche suivante arrivent d'un seul lot, la fin de la manche vue ouverte n'est pas annoncée et la révélation n'est pas vue. Correctif proposé côté client (`90` § 7.4) et côté `60` (rattrapage d'une révélation échue) | `90` point n° 19, `60` § 24 | non corrigé ; aucun lot bloqué |
| **E123-11** — `N` de l'aide du barème sur l'écran de relance solo : celui de la dernière partie (livré, faute de source), ou une prop `presets` enrichie du `N` de chaque preset, qui change la forme de `50` § 5.3, `60` § 16.4 et `90` § 7.7 | `50` point n° 30, `60` § 24, `90` point n° 20 | invisible au J1 ; visible en passe 2 |
| **Force brute et cadence** : à `K` = 10, le rapport de force brute est nul par construction au preset par défaut ; choisir un `K` sous le budget du palier 1 (par exemple 5) ou un seuil relatif à `d₁ × attemptsPerSecond` (E75-3). Le limiteur `answer` n'est pas atomique contre un script parallèle : variante atomique dès le J1, ou « accepter et sonder » (E105-6) | `70` § 8 et § 13.2, `100` § 15 | aucun lot bloqué ; `config/ops.php` garde 10 d'ici là |
| **Réponses traitées après la révélation** : E107-5 **appliqué le 01/10** (le refus texte relit la fenêtre sous `round FOR SHARE`, 409 `closed`, rien de compté) ; reste à confirmer la transaction du clic faux (E107-1) | `70` § 7.5 et § 7.6 | E107-1 : confirmation seulement |
| **Informations sans décision attendue** : vocabulaire anglais « image » dans les textes d'aide de `80` contre « frame » dans les clés de `90` (E88-6) ; « n manches jouées » ajoute une ligne sous chaque pseudo au podium en portrait (E118-6) | `80`, `90` point n° 23 | aucune |

**Questions relevées par la livraison anticipée de L20-19 et de l'annuaire des comptes** (28/09). Écrites dans `20` § « Ce que cette spec ne décide pas » ; le code livré applique la lecture décrite au § 2.8 tant que le porteur ne s'est pas prononcé. Aucune ne bloque un lot.

| Question | Propriétaire |
|---|---|
| **EL19-1** — refus de changer **son propre rôle** (lecture retenue) : le confirmer, ou autoriser la rétrogradation de soi hors dernier administrateur | `20` § 2.8 |
| **EL19-2** — nom réel **conservé** à la rétrogradation en joueur (lecture retenue), ou vidé | `20`, `10` § 5.1 |
| **EL19-3** — la correction du nom réel **par la console** n'écrit toujours aucune ligne : ouvrir `user.real_name_changed` à l'acteur `console` ? | `20` § 2.5, `10` § 8.3 |
| **EL19-4** — l'annuaire montre l'adresse de chaque compte : à nommer dans la page de confidentialité du J2 | `90` (J2) |
| **EL19-5** — ratifier l'écrivain de `last_login_at` (écouteur sur `Login`) dans la règle du compte | `40` (J2) |
| **EL19-6** — limiteur `admin-curation` réemployé pour les gestes d'accès, ou limiteur dédié | `20` § 13.7 |
| **EL19-7** — un compte **sans adresse** (Discord, J2) n'est jamais promu : à confirmer avec l'OAuth | `40` (J2) |
| **EL19-8** — la preuve MySQL du verrou des administrateurs (`locks-timing`) n'est pas écrite : la VM Homestead n'a pas de base MySQL de test (seulement `tripleframes`, que `RefreshDatabase` viderait, et `tripleframes_prod`) ; en créer une est un geste du porteur | `20` L20-19, `100` § 2.1 |

**Questions relevées par la livraison de L20-33 et de l'exclusion des visuels TMDB avec langue** (28/09 au soir). Écrites dans `20` § « Ce que cette spec ne décide pas » ; le code livré applique la lecture décrite aux § 5.4 et § 6.3 tant que le porteur ne s'est pas prononcé. Aucune ne bloque un lot — amendé le 28/09.

| Question | Propriétaire |
|---|---|
| **EL33-1** — les textes du minutage disent « heures, minutes et secondes », jamais « h:mm:ss », que le balayage de `CuratorMessagesTest` lit comme un nom de commande : l'accepter, ou affiner le balayage | `20` § 5.4 |
| **EL33-2** — pas de `forceFormData` (refusé par les types d'Inertia) : c'est le fichier ajouté qui rend l'envoi multipart ; aucun test ne rejoue l'envoi du navigateur | `20` § 6.3 |
| **EL33-3** — le refus `admin.frame.tmdb.with_text` conseille « …ou envoyez une capture », faux si l'interrupteur ferme la voie : texte neutre, ou deux textes | `20` § 5.3 |
| **EL33-4** — tier froid des captures dès le J1 (ligne ci-dessus) | `100` § 13.3 |
| **EL33-5** — `ops/mise-en-service.md` et `docs/ops/repetition-vm.md` à réaligner (ligne ci-dessus) | le porteur |

L'ordre d'exécution relève aussi des **correctifs de dépendances** (I-1 à I-13, annexe § A.3). Ils sont déjà appliqués dans l'ordre, mais pas encore reportés dans les specs propriétaires. **I-12** est appliqué depuis le 24/09 : `final_rank` élargi dans la migration de création (étape 25, accord du porteur ; `10` § 13.2, n° 47). Attendent encore l'accord du porteur : **I-13** (drainage livré en deux déploiements) et la **règle de branche pendant la curation** (§ A.2) — amendé le 25/09.

Deux écarts documentaires restent à corriger :

- **I-7** : `00` § Jalons et `20` § 10.3 placent la « restauration jouée » avant la curation. `100`, qui en est propriétaire, n'y met que le contrôle de lisibilité, et place la restauration chronométrée avant la première partie. Il faut suivre `100`.
- **D-2** : `00` et `questions-ouvertes.md` prévoient encore une recherche-remplacement de `<DOMAINE>` le jour de l'achat. `100` § 7.2 rend la garde permanente : il faut suivre `100`.

---

## 5. Prochaine action, dans l'ordre

L'ordre complet est en **annexe** : 134 étapes, humaines et IA. C'est la seule copie versionnée de l'ordre établi le 23/09. Le numéro d'une étape donne un ordre de démarrage, pas un calendrier. Une étape IA dont les prérequis sont livrés avance pendant qu'un geste humain antérieur attend.

**État au 28/09.** Les phases A, B et C sont livrées pour leur part IA : étapes 6 à 25, 33 à 53, 62, 63 et 65 à 125. **Plus aucune étape IA de l'annexe n'a tous ses prérequis livrés** : chacune attend un geste du porteur, directement ou par ses prérequis. Amendé le 01/10 (D43 du 01/10) : sauf les étapes IA des thèmes par film (136, 137 et 139 à 143), prêtes le 01/10 et livrées le même jour (arbre de travail, avant commit). C'est désormais le chemin humain du § 3 qui fixe la date de la première partie — amendé le 28/09.

**Tout de suite, porteur, en parallèle :**

- **Phase 0**, pour ce qui n'est pas encore fait :
  - (1) acheter le domaine : il bloque la mise en service (27), donc le pilote et toute la phase D ;
  - (2) relever le VPS, confirmer root et région UE : il débloque les gabarits `ops/` (26) ;
  - (3) constituer la liste d'amorçage : elle débloque l'étape 54 ;
  - (4) choisir le stockage de sauvegarde, la supervision et le second canal ;
  - (5) commander les textes légaux, non bloquant.
- **Étape 64** : licence, nom et lisibilité du pack d'avatars (§ 3, geste 4).
- **Gestes 8 à 11 du § 3** : base de dev rafraîchie, nom réel dans `LICENSE`, logo TMDB, vérifications au navigateur.
- **Questions du § 4** : E116-10, E122-7 et E107-5 sont fermés (01/10) ; restent les confirmations de lectures appliquées.

**IA, livrées le 01/10 (D43 du 01/10)** : les étapes 136, 137, 139, 140 puis 141 à 143 de l'annexe — thèmes par film —, dont aucune n'attendait de geste du porteur ; restent les gestes 12 à 15 du § 3 (étapes 138, 144, 145) ; chacune finit par `composer ci:check`, `php artisan lang:types` dès qu'une clé est ajoutée et `npm run build` (Wayfinder) dès qu'une route est ajoutée, avant `npm run check` — amendé le 01/10.

**IA, lot correctif de tests** : livré le 28/09 (E116-10, E122-7) ; E107-5 livré le 01/10.

**Puis, dans l'ordre de l'annexe :**

- **IA** : gabarits `ops/` ajustés au relevé (26), dès le relevé ; liste d'amorçage versionnée (54), dès la liste.
- **Porteur, avec l'IA** : mise en service root (27), premier admin (28), premier déploiement (29) ; sauvegarde active (30), contrôle de lisibilité (31), supervision (32).
- **Porteur** : heures du pilote (55), **porte du pilote** (56), puis curation (57 à 61). Si l'outil est disqualifié, l'IA re-livre en priorité (60).
- **Phase D (126 à 134)**, qui attend les gestes du porteur :
  - déploiement du moteur par la transition du drainage (126 ; I-13 et la règle de branche du § A.2 attendent son accord) ;
  - recette sur téléphone et lecteur d'écran réels (127), où se tranche le budget vertical clavier ouvert (E125-3) ;
  - restauration chronométrée (128) ;
  - outillage de charge (129), répétition à deux salons (130) et séance de charge (131) ;
  - clôture de L70-11 sur le catalogue publié (132) ;
  - vérification des conditions (133), puis **première vraie partie entre invités** (134).

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

Dire à Claude : **« Lis `docs/REPRISE.md`, puis reprends la prochaine action du § 5. »** Avant tout lot, deux vérifications (§ 1) :

1. `git status` : le travail précédent est-il commité ?
2. `composer ci:check`, serveur Vite arrêté : la suite est-elle verte ?

Ensuite, à chaque session : **« Implémente l'étape suivante de l'ordre des lots J1. »** — amendé le 28/09. Claude suit la section « Lots d'implémentation » de la spec du lot : fichiers, tests Pest nommés, dépendances. En fin de lot, il marque l'étape livrée dans l'annexe et met à jour ce fichier.

`CLAUDE.md` se charge tout seul, avec les règles de jeu, la stack, les commandes, les conventions et les pièges. Inutile de les redonner.

**Annexes du 23/09**, dans `docs/annexes/` : `contrats-j1.md` (feuille de contrats partagés figée pour la rédaction : noms, signatures, charges utiles ; résout les renvois « contrat Cn », « E10-nn », « A-nn », « R-nn ») et `contradictions-j1.md` (les 82 contradictions du corpus, citées « n° N »). Elles ne priment jamais sur une spec.

**Panel admin, aujourd'hui** :

1. `php artisan admin:first-admin` crée ou promeut le premier administrateur. La commande est interactive : le mot de passe ne passe jamais en argument.
2. Lancer `composer dev`.
3. Ouvrir `/admin`. On y trouve le tableau de bord, le catalogue et l'import (`discover`, collage d'identifiants, reprise). Un administrateur y trouve en plus, depuis le 28/09, le groupe « Administration » : « Comptes » (annuaire) et « Accès » (rôles, noms réels, historique). En développement, les comptes de démonstration (`DemoAccountsSeeder`, mot de passe `password`) permettent de l'essayer : `admin@tripleframes.test` administre, `curator@tripleframes.test` ne voit pas ce groupe.

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
5. P — commander les textes légaux du J2 ; question de licéité de la capture (non bloquant ; voie ouverte au J1 par D38 du 28/09, amendé le 28/09) → —

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

26. L100-9 (préparation) — gabarits `ops/` ajustés au relevé, liste de contrôle de `100` § 11.6 → 2, 10, 11, 13, 14*, 17*, 18*, 19, 20, 23 — ✅ préparée le 28/09 (sous hypothèse, à ajuster au relevé)
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
40. L20-6 — aperçu admin des images → 22 — ✅ livrée le 24/09
41. L20-7 — ajout depuis TMDB, dédoublonnage, route capture refusante → 22, 33 — ✅ livrée le 24/09
42. L20-8 — re-recadrage, niveau, dépublication d'image → 17, 34, 41 — ✅ livrée le 24/09
43. L20-9a — recadreur : cadre, boutons, clavier → 8, 12, 21 — ✅ livrée le 24/09
44. L20-9b — recadreur : poignées, molette, pincement (D35) → 43 — ✅ livrée le 24/09
45. L20-10 — éditeur de la banque d'images → 34, 39, 41, 42, 43 — ✅ livrée le 24/09
46. L20-12 — grille v1, passe de revue → 17, 39, 40, 42, 45 — ✅ livrée le 24/09
47. L20-11 — raccourcis de débit, bande balayable (D35) → 8, 45, 46 — ✅ livrée le 24/09
48. L20-13 — publication d'un film, contenu vérifié, ambiguïté → 17, 38, 46 — ✅ livrée le 24/09
49. L20-14 — titres, alias, `movie_group` manuel → 48 — ✅ livrée le 24/09
50. L20-15 — file de curation, fiche, tableau de bord → 36, 48 — ✅ livrée le 24/09
51. L20-16 — recherche TMDB, liste d'amorçage, aperçu à blanc, garde d'instantané des imports (I-9) → 18, 20* — ✅ livrée le 24/09
52. L20-17 — débit et verdict du pilote → 8, 45, 48, 50 — ✅ livrée le 24/09
53. L20-18 — « premiers pas du curateur » → 46, 47* — ✅ livrée le 24/09
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

62. L90-5 — pages d'erreur, composants d'état → 15, 18 — ✅ livrée le 25/09
63. L40-5 — registre des 24 avatars (Kenney CC0, D27) → — — ✅ livrée le 25/09
64. P — licence du pack dans `public/avatars/LICENSE.md` → 63
65. L40-1 — jeton d'invité, refus de l'expulsé (`kicked_at`) → 63 — ✅ livrée le 25/09
66. L40-2 — langue portée par le jeton → 65 — ✅ livrée le 25/09
67. L40-3 — pseudo : forme canonique, écriture latine (D26) → 37, 63 — ✅ livrée le 25/09
68. L40-4 — liste noire des pseudos → 67 — ✅ livrée le 25/09
69. L40-6 — identité affichée, composants d'avatar → 12, 63, 67 — ✅ livrée le 25/09
70. L30-4 — `SeededPrf`, `DrawContext` → — — ✅ livrée le 25/09
71. L30-5 — tirage des films et des variantes → 9, 34, 35, 70 — ✅ livrée le 26/09
72. L30-6 — substitution et remplacement → 71 — ✅ livrée le 26/09
73. L70-3 — `AnswerMatcher` → 37, 38 — ✅ livrée le 26/09
74. L70-11 — rapport de collisions, sonde de force brute → 73 — ✅ livrée le 26/09
75. L100-7 (2e temps) — `ReportBruteForce` → 23, 74 — ✅ livrée le 26/09
76. L70-4 — états de saisie (D20), `types/answers.ts` → — — ✅ livrée le 26/09
77. L70-7 — résolveur de titre, tirage des leurres (D21) → 35, 70 — ✅ livrée le 26/09
78. L70-8 — composition du QCM, `rendered_locale` → 76, 77 — ✅ livrée le 26/09
79. L80-1 — règle de score, fonction pure → 8, 9 — ✅ livrée le 26/09
80. L80-2 — portées de lecture et rejeu → 79 — ✅ livrée le 26/09
81. L60-2 — horloge, instant de réception, enveloppe, limiteurs → 8, 10 — ✅ livrée le 26/09
82. L80-3 — départage, classement, `types/scoring.ts` → 69, 80, 81 — ✅ livrée le 26/09
83. L60-3 — canaux, garde `player`, événements → 65, 69, 81 — ✅ livrée le 26/09
84. L50-2 (1er temps) — éditeur Simple, présentateur, presets, `types/room-settings.ts` (I-1) → 9, 35, 36, 65, 81, 83 — ✅ livrée le 26/09
85. L60-4 — siège actif, second onglet, lobby → 69, 76, 82, 83, 84 — ✅ livrée le 26/09
86. L50-2 (2e temps) — routes sous `seat.active`, `BroadcastLobbyState` → 84, 85 — ✅ livrée le 26/09
87. L90-6b — chronologie cliente, annonces de manche → 39, 81, 82, 85* — ✅ livrée le 27/09
88. L80-6 — textes, aide, `tierValueAt` (D29) → 8, 79, 87 — ✅ livrée le 27/09
89. L80-4 — `FinalizeGame` → 6, 82 — ✅ livrée le 27/09
90. L60-5 — matérialisation, programmation, `serve_token`, annulation → 8, 72, 83, 89 — ✅ livrée le 27/09
91. L60-6 — transitions de manche, pause → 77, 82, 89, 90 — ✅ livrée le 27/09
92. L80-5 — podium, récapitulatif, faits (D25) → 69, 77, 81, 89, 91 — ✅ livrée le 27/09
93. L60-7 — rattrapage, jobs de frontière, fin anticipée → 23, 76, 91 — ✅ livrée le 27/09
94. L60-8 — service d'image `/f/{serveToken}` → 21, 40, 65, 81, 90 — ✅ livrée le 27/09
95. L60-9 — client temps réel → 8, 39, 81, 83, 84, 85, 87 — ✅ livrée le 27/09
96. L60-10 — prédicat « partie en cours », `game:reschedule` → 89, 91, 93 — ✅ livrée le 27/09
97. L100-5 — drainage (D32), étapes 3 et 12 du hook → 11, 20, 96 — ✅ livrée le 27/09
98. L90-3b — bandeau de maintenance → 15, 97 — ✅ livrée le 27/09
99. L90-7 — coquille de jeu, forçage sombre, aide → 39, 98 — ✅ livrée le 27/09
100. L50-3a — code de salon, identifiant public de siège → 8, 12, 86 — ✅ livrée le 27/09
101. L50-3b — création, entrée, prise de siège → 15, 16, 63, 65, 66, 67, 68, 69, 81, 83, 100 — ✅ livrée le 27/09
102. L50-7a — lancement, garde de drainage → 10, 37, 71, 79, 83, 86, 90, 97, 101 — ✅ livrée le 27/09
103. L100-14 — bout en bout : partie de 10 manches sur le catalogue de démonstration → 90, 102 — ✅ livrée le 27/09
104. L70-5 — soumission texte, fenêtre, refus à travail constant → 65, 73, 76, 81, 85, 93, 102 — ✅ livrée le 27/09
105. L70-14 — limiteur `answer`, ordre des middlewares → 85, 104 — ✅ livrée le 27/09
106. L70-6 — transaction de verrouillage → 79, 93, 104 — ✅ livrée le 27/09
107. L70-9 — clic QCM, `SeatInputView` → 78, 106 — ✅ livrée le 27/09
108. L60-11 — crochets de saisie, QCM ciblé, fin de partie → 6, 78, 89, 92, 93, 104, 106, 107 — ✅ livrée le 27/09
109. L60-12 — resynchronisation de partie → 77, 78, 82, 85, 92, 93, 94, 107 — ✅ livrée le 27/09
110. L50-4 — page `game/lobby`, page unique du salon → 39, 83, 85, 95, 99, 101, 109 — ✅ livrée le 27/09
111. L50-6 — pouvoirs de l'hôte : expulsion (D15), transfert, départ → 65, 83, 93, 101, 110 — ✅ livrée le 27/09
112. L60-13 — présence, reprise, pouvoir d'hôte en partie → 85, 89, 93, 97, 111 — ✅ livrée le 27/09
113. L50-8 — échéances : archivage, lobby oublié, salon expiré → 23, 29, 83, 101, 112 — ✅ livrée le 27/09
114. L100-8 (2e temps, `stale_room`) + L100-7 (3e temps, `stale_lobby`) → 24, 113 — ✅ livrée le 27/09
115. L50-7b — gel, « Rejouer », concurrence du lancement → 6, 83, 89, 97, 102 — ✅ livrée le 27/09
116. L50-9 — retardataires (lot J1 ordinaire, D35) → 6, 91, 101, 102 — ✅ livrée le 27/09
117. L70-10 — écran de saisie, grille QCM → 8, 39, 95, 99, 104, 105, 107, 109 — ✅ livrée le 27/09
118. L80-7 — composants de classement, récapitulatif, podium → 8, 62, 69, 82, 88, 92, 95, 99 — ✅ livrée le 27/09
119. L60-14 — états de manche, de révélation et de pause → 15, 39, 95, 99, 109, 110, 112, 117, 118 — ✅ livrée le 27/09
120. L50-5 — formulaire Simple, presets, vivier, avertissements → 8, 12, 36, 86, 110 — ✅ livrée le 27/09
121. L60-15 — démarrage solo, page d'entrée (E10-N3) → 6, 15, 16, 36, 63, 65, 67, 86, 93, 97, 102 — ✅ livrée le 27/09
122. L100-8 (3e temps) — branche solo d'`orphan_player` → 24, 121 — ✅ livrée le 27/09
123. L60-16 — partie solo, gestes (D18, D19) → 94, 109, 112, 119, 121 — ✅ livrée le 27/09
124. L90-8 — accueil, champ de code → 15, 100, 101, 121 — ✅ livrée le 27/09
125. L90-9 — recette portrait et accessibilité (partie automatisée) → 99, 110, 117, 118, 119, 120, 123 — ✅ livrée le 27/09

**Thèmes par film — D43 du 01/10 (IA et P ; 22 à 31 h de taille)** — amendé le 01/10. Aucune de ces étapes n'est prérequis de la porte du pilote (56) ni de la première partie (134) ; elles se livrent dès maintenant, de préférence avant l'import de la liste d'amorçage (57), pour que les films du pilote entrent avec leurs thèmes. Ordre des tranches : specs, noms des sociétés, évaluateur, thèmes livrés, écran des thèmes, fiche film, collage.

135. IA — D43 : amendement des specs (`questions-ouvertes.md`, `30`, `20`, `10`, `00`), de ce fichier et de `CLAUDE.md` → — — ✅ livrée le 01/10
136. L30-8 (1er temps) — `tmdb_company`, noms des sociétés écrits à l'import, `catalog:company-names` → 20, 51, 135 — ✅ livrée le 01/10
137. L30-8 — `ThemeEvaluator`, `SyncThemeMembership` (`ShouldBeUniqueUntilProcessing`, écritures sous verrou), branchement à l'import, `catalog:themes`, seeder de démonstration → 19, 20, 136 — ✅ livrée le 01/10
138. P — après le déploiement de 136 et 137, précédé de l'instantané manuel (§ 3, geste 12) : `catalog:themes`, puis `catalog:company-names` (§ 3, geste 13) → 29, 137
139. L30-9 — thèmes livrés étendus : décennies sans trou, Marvel, DC, animés, douze sagas ; sociétés nommées ; déploiement précédé de l'instantané manuel (§ 3, geste 12) → 137 — ✅ livrée le 01/10
140. L30-11a — mesure d'un thème (`themeProbe`, `themeWorks`) → 36, 137 — ✅ livrée le 01/10
141. L20-28 (1er temps) — écran des thèmes : création de toute nature et thèmes manuels, correction de règle sous verrou, publication sous seuil, quatre cas du journal → 33, 136, 139, 140 — ✅ livrée le 01/10
142. L20-28 (2e temps) — bloc « Thèmes » de la fiche film, `SetMovieThemeMembership` (jamais un `removed` écrasé), « Créer la saga depuis cette collection », `movie.theme_set` → 50, 141 — ✅ livrée le 01/10
143. L20-28 (3e temps) — thèmes choisis au collage, `import_run.added_theme_ids`, doublons compris, reprise → 51, 142 — ✅ livrée le 01/10
144. P — corriger `studio.disney` vers `2,6125,171656` en back-office (§ 3, geste 14) → 29, 141
145. P — publier les thèmes prêts, tard au J1 (§ 3, geste 15) → 61, 141

**Phase D — avant la première vraie partie (8 à 12 h)**

126. P + IA — déploiement du moteur par la transition du drainage (I-13) ; ensuite, tout déploiement suit `100` § 11.4 → 62 à 125
127. P — recette de L90-9 sur téléphone réel (360 × 640, clavier ouvert) et lecteur d'écran réel → 125, 126
128. IA + P, L100-10 (2e temps) — restauration chronométrée sur cible jetable → 30, 96, 126
129. L100-12 — outillage de charge (scénarios A, A2, B ; `loadtest:forget`) → 29, 97, tous les lots J1 de `50`, `60` et `70` — ✅ livrée le 28/09
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
| I-12 | La mise en service précède L80-4 : la voie additive s'imposerait pour `final_rank` | Option : élargir dans la migration de création avant la mise en service (étape 25), **accord du porteur** — appliqué le 24/09 (accord du porteur, migration de création) |
| I-13 | Le hook de L100-5 s'arrêterait à l'étape 3 lors du premier déploiement du drainage | Livrer L100-5 en deux déploiements (d'abord le drapeau et les commandes, puis les étapes 3 et 12), **accord du porteur** ; variante : arrêt attendu puis relance |

Les étapes 135 à 145 (D43 du 01/10) ne sont prérequis d'aucune des 134 premières et ne dépendent que d'étapes antérieures — amendé le 01/10. L'ordre a été vérifié mécaniquement : chacune des 134 étapes vient après toutes ses dépendances directes, et aucun cycle ne subsiste une fois L50-2 scindé. La somme des tailles vaut 404,5 à 581,5 h, soit le total brut de `00` § Jalons.
