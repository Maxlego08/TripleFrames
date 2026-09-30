# Contradictions du corpus relevées le 23/09/2026

> **Annexe archivée le 23/09/2026.** Liste des contradictions entre `00`, `05`, `10`, `questions-ouvertes.md`, `REPRISE.md`, `CLAUDE.md` et le code, relevées par la pré-analyse vérifiée de la session du 23/09. Les specs et `docs/annexes/contrats-j1.md` les citent « n° N » ou « contradiction N » (numérotation à partir de 0). Toutes ont été traitées le 23/09 : résolution appliquée au corpus, ou tranchée par une décision du porteur (n° 14 → D4, n° 36 → D2, n° 53 → D7 et D14 du 23/09). Numéros de ligne valables au commit `d167a6a`.

## n° 0

Le recadreur « n'envoie qu'un dérivé de 80-150 Ko », alors que 10 exige un master 1920 px non recadré, un rectangle dans l'espace du master et un source_hash calculé sur les octets reçus.

- **Emplacements** : 00 l.215, l.326 ; CLAUDE.md §8 ; questions-ouvertes § 8 l.121 ; 10 § 4.1 l.441-445, A7 l.1327, § 10 l.1114
- **Résolution** : Source TMDB : le serveur télécharge l'original et hache les vrais octets ; capture : le navigateur envoie la source normalisée (≤ 1920 px, ≤ 1 536 Ko) + rectangle + dérivé de jeu, source_hash = empreinte de la source reçue. Amender 00, CLAUDE.md, questions-ouvertes § 8 et 10 § 4.1 (exigence de 20).

## n° 1

Disque frames en serve => true et noms en ULID dans 00 et questions-ouvertes, contre serve => false et bin2hex dans 10, CLAUDE.md et le code.

- **Emplacements** : 00 l.325 ; questions-ouvertes l.121, l.293, l.315 (ServeFile), l.382 ; 10 l.1079, l.1092, A8
- **Résolution** : 10 fait foi : route /f/{serve_token}, noms bin2hex(random_bytes(16)), no-store et X-Robots-Tag émis par la route dédiée. Corriger 00 l.325 et questions-ouvertes.

## n° 2

Pilotage clavier du recadreur déclaré coupable, alors que le parcours clavier fait partie de la barre « terminé » et du principe 8 ; et « Entrée publie » dans le recadreur est impossible puisque la publication exige une revue sur le rendu final.

- **Emplacements** : questions-ouvertes § 8 l.123, l.125, l.298 ; 00 l.215, principe 8 l.385 ; 10 § 4.2 l.504
- **Résolution** : Distinguer opérabilité clavier (jamais coupée) et raccourcis de débit (coupables) ; dans le recadreur 1-5 = classer et envoyer, dans la passe de revue Entrée = « conforme, publier ». Amender principe 8, 00 l.215 et questions-ouvertes § 8.

## n° 3

Publication d'un film décrite comme automatique (« dès qu'il couvre 1, 3 et 5 ») alors que 10 en fait un geste de curateur avec confirmation.

- **Emplacements** : CLAUDE.md §2 ; 00 l.36, l.216, l.306 ; questions-ouvertes l.145 ; 10 § 4.3 l.513, § 3.5 l.360
- **Résolution** : Publication = geste explicite, disponible dès que content_flag = clear et la couverture 1/3/5 tiennent ; « dès que » décrit l'éligibilité. Préciser CLAUDE.md §2 et 00.

## n° 4

10 A16 pose « published implique levels_mask & 21 = 21 » en invariant, alors que A3 admet published + blocked et que 10 § 4.3 fait tester au vivier seulement levels_count ≥ N ; les défauts de 20 (dépublication automatique) et de 30 (maintien) divergent ; le test de chaîne exige le masque dans le vivier.

- **Emplacements** : 10 A16 l.1399, A3 l.1298-1302, § 4.3 l.513 ; CLAUDE.md règle 9 ; 00 l.306 ; DemoCatalogueChainTest.php l.83-91
- **Résolution** : A16 devient une garde de transition vers published. Un film publié qui perd une couverture reste published, jouable pour N ≤ levels_count avec repli de niveau ; 20 avertit le curateur avant une dépublication qui casse 1/3/5 et le signale « incomplet » ; aucune dépublication automatique. Retirer le masque 21 du test ; corriger CLAUDE.md règle 9 et 00 l.306 (« à sa publication »).

## n° 5

Liste fermée admin_action incomplète : ni movie.published, ni frame.unpublished, ni frame.unsuspended, alors que tout changement de disponibilité doit écrire une ligne ; acteur 'console' introduit par le code sans être prévu par 10 ; site:close sans action ni sujet.

- **Emplacements** : 10 § 8.3 l.1005-1019, A2 l.1296, § 4.1 l.433 ; AdminActionType.php ; FirstAdminCommand.php l.103, l.325 ; DemoCatalogueSeeder l.482-492
- **Résolution** : Exigences de 20/100 à 10 : ajouter frame.unpublished, frame.unsuspended, site.closed/site.reopened (colonne string, aucune migration) ; publication d'une frame prouvée par frame_review ; ratifier actor_name 'console' au § 8.3 et aligner le seeder.

## n° 6

Clés de la grille sous un domaine curation.* qui n'existe pas.

- **Emplacements** : ExclusionGrid.php l.37, l.145 ; 05 l.94
- **Résolution** : Clés admin.exclusion_grid.v{n}.{slug} (+ .help), changement gratuit tant qu'aucune ligne frame_review n'existe.

## n° 7

Messages du back-office qui prescrivent une commande artisan ou une variable d'environnement à un curateur sans accès au dépôt.

- **Emplacements** : lang/fr/admin.php l.528, l.594 (et l.342, l.715-717 à terme) ; questions-ouvertes l.302 ; 00 l.418
- **Résolution** : Reformuler côté curateur (« l'administrateur est prévenu », bouton « Reprendre ») ; la consigne technique va aux journaux et sondes.

## n° 8

Liste d'amorçage « collée en un geste » par des curateurs, alors que le fichier ne s'emploie qu'en console et que le formulaire web plafonne à 50 identifiants ; l'aperçu « ligne par ligne » du collage n'a aucun support.

- **Emplacements** : 00 l.152, l.433 ; questions-ouvertes l.143, l.309 ; config/catalog.php l.80-93 ; tmdb-seed-list.txt ; 10 § 9.1 l.1035-1037
- **Résolution** : Bouton back-office « Importer la liste d'amorçage » lisant le fichier versionné par lots de paste_max_ids, idempotent ; aperçu = balayage à blanc en job dont le résultat ligne par ligne est tenu en cache, sans ligne import_run.

## n° 9

« Aucun hotlink d'image » contre le chargement direct des visuels TMDB dans le navigateur du curateur.

- **Emplacements** : 00 l.416 ; CLAUDE.md §8 (crossOrigin)
- **Résolution** : Borner l'interdiction aux surfaces joueur ; 20 écrit le chargement curateur (crossOrigin anonymous) ; 90 mentionne l'IP curateur vers TMDB dans la confidentialité.

## n° 10

05 range le back-office parmi les surfaces sans rendu client, alors qu'il est rendu par React et formate en Intl côté client.

- **Emplacements** : 05 l.237 ; resources/js/lib/admin-format.ts
- **Résolution** : Amender 05 : formatage client en fr pour le back-office ; App\Support\Format réservé aux e-mails et exports.

## n° 11

Propriété de la matrice de capacités, du tableau de rétention et de la file de signalement des avatars attribuée à deux specs.

- **Emplacements** : 00 l.217, l.433, l.435 ; 10 § 15 l.1414, § 11.1 l.1122 ; questions-ouvertes l.358 ; CLAUDE.md §2
- **Résolution** : Matrice → 20 (corriger 10 § 15) ; tableau de rétention → 10 (corriger 00 l.435) ; signalements : règle 40, écran 20 (corriger 00 l.217).

## n° 12

10 A7 dit que 00 et questions-ouvertes écrivent encore « support », or ils sont corrigés, sauf le risque l.380.

- **Emplacements** : 10 A7 l.1326 ; questions-ouvertes l.380
- **Résolution** : 20 confirme A7 ; corriger questions-ouvertes l.380 et retirer la phrase périmée de 10 A7.

## n° 13

Budget du trimestre écrit 120 h dans questions-ouvertes et 130 h dans 00 ; questions-ouvertes l.266 dit que chaque confirmation a une valeur par défaut alors que liste d'amorçage et seuils pilote n'en ont pas.

- **Emplacements** : questions-ouvertes l.58, l.76, l.266, l.276-277, l.385 ; 00 l.366
- **Résolution** : Aligner sur 130 h ; inscrire les seuils retenus par 20 comme valeur par défaut, ou corriger l.266.

## n° 14 — tranchée par le porteur

CLAUDE.md §1 compte « les comptes de curation » parmi les comptes du J1, alors que seul le premier admin a une voie de création ; les comptes privilégiés avant l'achat du domaine sont interdits à la lettre.

- **Emplacements** : CLAUDE.md §1 ; 00 l.211, l.364, l.400 ; questions-ouvertes l.260
- **Résolution** : Dépend des questions « Curateurs J1 » et « Montage J1 » ; écrire en 40/20 que seuls les passkeys sont le point de non-retour.

## n° 15

Le premier admin peut naître sans preuve d'adresse par deux chemins (création et promotion), pas seulement --create.

- **Emplacements** : FirstAdminCommand.php l.252, l.312-320 ; REPRISE l.96
- **Résolution** : 20 tranche une seule fois pour les deux chemins : l'accès shell vaut preuve, --create définitif pour le premier admin.

## n° 16

Re-recadrage d'une image publiée : défaut de 20 « nouvelle variante » contre modèle de 10 « en place » ; en place, une frame restée published garderait une revue périmée invisible à la sonde.

- **Emplacements** : 10 § 4.1 l.442, § 4.2 l.493, l.504 ; questions-ouvertes l.109
- **Résolution** : En place conformément à 10 : la frame sort de published avant que le job réécrive game_path ; nouvelle sonde published_hash = reviewed_hash de published_review_id.

## n° 17

Tri de la file des non curés sur movie_difficulty, qu'aucun code ne calcule pour les films réels.

- **Emplacements** : 10 § 3.1 l.255-257 ; MovieImporter.php ; DemoCatalogueSeeder l.287-288
- **Résolution** : Tri par vote_count décroissant tant que 30 n'a pas livré la dérivation.

## n° 18

Levée de suspension : la cascade balaie aussi des frames draft/unpublished dont l'état antérieur n'est stocké nulle part.

- **Emplacements** : 10 § 4.3 l.518
- **Résolution** : La cascade ne suspend que les frames published ; la levée ne rétablit que celles dont la dernière revue passed porte sur le published_hash et la version de grille courants ; frame.unsuspended ajouté (voir ci-dessus).

## n° 19

REPRISE liste comme ouverte la mécanique 2FA déjà fixée par 10 A16 ; questions-ouvertes dit la valeur réversible « tant que 40 n'est pas écrite » alors que 20 l'implémente avant.

- **Emplacements** : REPRISE l.91 ; 10 A16 l.1399 ; questions-ouvertes l.273
- **Résolution** : 20 cite A16 et ne décide que la redirection vers l'enrôlement et le jalon de la file ; corriger l.273 en « tant que 20 n'est pas implémentée ».

## n° 20

Axe joueur réintroduit dans des commentaires (« repli non vue par le joueur »).

- **Emplacements** : DemoCatalogueSeeder.php l.98-99 ; DemoCatalogueChainTest.php l.675
- **Résolution** : Corriger en « non vue par le salon → vue la moins récemment par le salon → graine ».

## n° 21

Table N → niveaux écrite dans une fabrique et marge de substitution 3 en constante de seeder, au lieu de FrameLevelCoverage et PlatformLimits.

- **Emplacements** : MovieFactory.php l.463-474 ; DemoCatalogueSeeder.php l.80 ; 10 § 6.1 l.645, § 15 l.1409
- **Résolution** : Créer app/ValueObjects/Catalog/FrameLevelCoverage.php ; PlatformLimits::drawSubstituteMargin() (défaut 3) ajouté à l'énumération de 10 § 6.1.

## n° 22

La spec 30 est absente de la liste du jalon 1 alors que le lancement exige vivier et tirage.

- **Emplacements** : 00 l.363 ; questions-ouvertes l.78
- **Résolution** : Ajouter 30 (vivier sans thème, tirage, variantes, non-répétition, movie_group, substitution).

## n° 23

Le vivier compte des films alors que le tirage n'en retient qu'un par movie_group : la garde peut passer à M et le tirage échouer.

- **Emplacements** : 10 § 12 l.1188-1189 ; questions-ouvertes l.310 ; DashboardController::poolByFramesPerRound()
- **Résolution** : Compter des œuvres (COUNT(DISTINCT CASE WHEN group_id IS NULL THEN id END) + COUNT(DISTINCT group_id)) au lobby, à la garde et pour draw_pool_size ; amender 10 § 12.

## n° 24

Définition du vivier : fonction de (thèmes, N) seulement, alors que la non-répétition le rend fonction du salon ; le back-office a inventé un « vivier CATALOGUE ».

- **Emplacements** : CLAUDE.md §2 ; 00 l.281, l.82 ; 10 l.1190 ; lang/fr/admin.php pool.scope_notice
- **Résolution** : 30 nomme « vivier catalogue (thèmes, N) » et « vivier du salon » ; mettre à jour le lexique de 00 et CLAUDE.md §2.

## n° 25

« 3 à 4 parties sans répétition » pour 60 films, alors que la clause porte sur les manches démarrées (≈ 6 parties).

- **Emplacements** : 00 l.363 ; questions-ouvertes l.384 ; 10 l.1190
- **Résolution** : 30 fixe « joué = manche démarrée, annulée comprise » ; corriger l'estimation à ≈ 6 parties.

## n° 26

Supervision back-office en GROUP BY avec bornes de N en littéral.

- **Emplacements** : DashboardController.php l.187-222 ; CatalogIndexRequest.php l.66-68 ; 10 l.96 ; CLAUDE.md règle 2
- **Résolution** : Remplacer par le constructeur unique de 30 ; bornes lues dans RoomSettingsBounds.

## n° 27

Pixar et Ghibli rangés en thèmes de saga ; décennies 1940-1960 absentes alors que l'âge d'or Disney entre par exception ; lexique de la difficulté « fixée en curation » ; collection « deux consommations ».

- **Emplacements** : questions-ouvertes l.305 ; PlatformDataSeeder.php l.60-67 ; DemoCatalogueChainTest FAIT 8 ; 00 l.280, l.304 ; 10 l.309
- **Résolution** : Pixar/Ghibli en studio ; décennies 1930-2020 sans trou avec trois films de démo 1940-1969 dans le même lot ; lexique « dérivée, corrigeable en curation » et six natures de thème ; 10 l.309 « trois consommations ».

## n° 28

draw_pool_size caché au motif qu'il réduirait le champ des films, alors que le lobby affiche ce compte à tous.

- **Emplacements** : 10 § 7.2 l.763 ; 00 l.116, l.164
- **Résolution** : Garder #[Hidden] par hygiène, retirer la justification.

## n° 29

« Même normaliseur qu'answer_key » pour le pseudo : retrait d'article, ponctuation et allongement par translittération (erreur 1406 en MySQL).

- **Emplacements** : 10 § 7.1 l.721, § 1.3 l.74 ; AnswerKeyNormalizer.php ; PlayerFactory normalizeNickname()
- **Résolution** : 70 expose fold() ; 40 définit NicknameNormalizer dessus, validation refusant une forme vide ou > 20 ; amender 10 § 7.1.

## n° 30

Masquage de pseudo « pour la partie en cours » contre état persistant levable par l'admin ; « deux joueurs distincts » contre deux sièges ; garde-fou « manche partagée » qui interdit le signalement au lobby.

- **Emplacements** : questions-ouvertes l.318 ; 10 § 7.1 l.722, l.740, § 8.1 l.960 ; 00 l.207, l.242
- **Résolution** : Masquage pour la vie du siège, levée par l'admin ; reformuler en « deux sièges distincts » ; garde-fou = siège présent dans le même salon ; résidu multi-siège nommé.

## n° 31

Avatar provider : accesseur de siège sans branche provider ; déliaison « sans écriture » qui laisse la photo affichée faute de provenance ; masquage qui tombe sur les initiales en ignorant le prédéfini.

- **Emplacements** : Player::avatarRef(), GamePlayer::avatarRef() ; 10 § 5.4 l.586, § 5.3 l.570, § 7.3 l.793 ; 00 l.188, l.207 ; User::avatarRef()
- **Résolution** : 40 écrit la résolution vivante par le compte ; la déliaison du fournisseur d'origine supprime le fichier et vide les colonnes (exigence à 10 : colonne de provenance nullable) ; le masquage réécrit avatar_kind = preset si avatar_preset existe. Dette du J2.

## n° 32

Suppression de compte en suppression dure (code, test, texte), et impossible pour un compte sans mot de passe.

- **Emplacements** : ProfileController::destroy() ; ProfileUpdateTest l.53-67 ; lang/*/account.php delete_account.dialog_description ; ProfileDeleteRequest ; 10 § 5.5 l.596-610 ; 00 principe 12
- **Résolution** : Au J1 : texte corrigé et route profile.destroy fermée ; 40 écrit l'anonymisation et la confirmation par ré-authentification OAuth pour les comptes sans mot de passe.

## n° 33

Types front non alignés sur l'ALTER de users (email et avatar nullables, Auth.user nullable).

- **Emplacements** : resources/js/types/auth.ts ; 10 § 5.2 l.565
- **Résolution** : Corriger dans le lot du premier écran à avatar, avec settings/profile.tsx et user-info.tsx.

## n° 34

Clés d'avatar dans un domaine avatar.* inexistant ; 05 range les avatars dans account alors qu'ils s'affichent en lobby et en jeu.

- **Emplacements** : AvatarRef.php l.46-50 ; 05 l.94 ; routes/settings.php
- **Résolution** : Clés common.avatar.alt.* et libellés common.avatar.preset.{clé} ; amender 05 l.94.

## n° 35

Inscription publique et passkeys actives sans condition alors que le J1 exclut tout compte de production.

- **Emplacements** : config/fortify.php l.172, l.180 ; welcome.tsx l.31 ; CLAUDE.md §1 ; 00 l.226
- **Résolution** : Interrupteurs dédiés lus depuis l'environnement, fermés en production jusqu'au J2 et au domaine ; lien Register masqué ; la commande d'arrêt réutilise l'interrupteur.

## n° 36 — tranchée par le porteur

Forme et transport du player_token : propriété renvoyée à 10 qui ne l'écrit pas ; 00 hésite entre cookie et localStorage ; 05 l.36 justifie le niveau 3 par un cas qu'aucun transport client ne couvre.

- **Emplacements** : 05 l.36, l.307 ; 00 l.129, l.435 ; 10 § 7.1 l.723 ; PlayerTokenLocale.php ; AppServiceProvider.php l.50
- **Résolution** : Propriétaire selon la question « Identité J1 » ; cookie HttpOnly chiffré ; corriger 00 l.129, 05 l.36 et l.307.

## n° 37

Passkey contre « jamais connecté sans second facteur » ; scopes « identify/email » inexistants chez Google.

- **Emplacements** : 00 l.185, l.389, l.410 ; questions-ouvertes l.54 ; vendor passkeys PasskeyLoginController
- **Résolution** : Passkey à vérification utilisateur obligatoire = second facteur, rôles privilégiés compris ; scopes Discord identify email, Google openid email profile ; corriger 00 et questions-ouvertes.

## n° 38

Balayage de dormance dit mensuel alors que la purge est quotidienne ; un rappel manqué n'est pas rattrapable.

- **Emplacements** : 10 l.1204, l.1140 ; questions-ouvertes l.278
- **Résolution** : Exécution quotidienne ; exigence à 10 d'une colonne dormancy_notified_at #[Hidden] ; anonymisation seulement après un rappel envoyé depuis 30 jours ou sans e-mail.

## n° 39

Bornes croisées 4 et 5 dites refusées par 10, alors que 00 et le code en font des avertissements.

- **Emplacements** : 10 § 6.1 l.632 ; RoomSettings.php docblock l.32-33 ; 00 l.97-98
- **Résolution** : 1-2 bloquantes dans fromInput(), 3 bloquante à la garde, 4-5 avertissements ; corriger 10 et le docblock.

## n° 40

« Lobby purgé » contre « archivage forcé, jamais suppression ».

- **Emplacements** : 00 l.135 ; 10 l.669, l.1129, l.1415
- **Résolution** : Employer « archivage anticipé du lobby » dans 00 et 10.

## n° 41

Attributs de validation en snake_case dans 05 alors que le value object n'accepte que des clés camelCase ; lang porte les deux familles.

- **Emplacements** : 05 l.100 ; 10 § 6.2 l.664 ; RoomSettings::FIELDS ; lang/fr/validation.php l.223-254
- **Résolution** : Champs postés = clés camelCase + roundDuration ; retirer les libellés snake_case sans consommateur ; corriger 05 l.100 et 10 l.664.

## n° 42

tabs et table listés comme composants shadcn manquants alors qu'ils sont installés ; form (react-hook-form) listé alors qu'il doublerait <Form> d'Inertia.

- **Emplacements** : CLAUDE.md §3 ; 00 l.329 ; resources/js/components/ui/tabs.tsx, table.tsx
- **Résolution** : Retirer tabs et table ; exclure form ; 90 publie la liste par jalon.

## n° 43

host_player_id « réécrite exclusivement par l'action de transfert », alors que la création doit poser l'hôte ; Player masque room_id que 10 ne masque pas.

- **Emplacements** : 10 § 6.2 l.661, § 7.1 l.744 ; app/Models/Player.php
- **Résolution** : La création appelle l'action de transfert dans la même transaction ; aligner 10 sur le #[Hidden] du code.

## n° 44

Message de blocage limité à thèmes, N ou M alors que la non-répétition est une quatrième cause ; capacité d'une saved_config sous l'effectif présent sans comportement défini.

- **Emplacements** : 00 l.77, l.104, l.116 ; 10 A16 l.1399
- **Résolution** : Ajouter noRepeatMovies aux codes fautifs ; au chargement, capacité relevée à l'effectif et signalée champ par champ.

## n° 45

Expulsion en partie contre « jamais de commande touchant une manche en cours ».

- **Emplacements** : 00 l.134 ; questions-ouvertes l.324 ; 10 § 7.7 l.891-892
- **Résolution** : « Toucher une manche » = modifier horloge, paliers ou scores ; l'expulsion est un départ forcé dont l'effet est celui de tout départ.

## n° 46

Client Redis phpredis dans .env.example contre predis ligne de base ; predis et Reverb absents de composer.json.

- **Emplacements** : .env.example l.49 ; CLAUDE.md §3 ; questions-ouvertes l.291
- **Résolution** : REDIS_CLIENT=predis, predis/predis requis et variables Redis/Reverb dans .env.example, dans le premier lot du moteur.

## n° 47

serve_token « jamais existant avant l'ouverture du palier », alors que le préchargement sert l'image preload_lead_ms avant ; sièges « présents » à l'ouverture excluant un joueur momentanément déconnecté.

- **Emplacements** : 10 § 7.4 l.824, l.835, § 7.6 l.857, § 10 l.1112 ; RoundTier.php docblock ; RoundTierFactory.php l.17
- **Résolution** : Jeton du palier i frappé à l'ouverture du palier i−1 (ou à la programmation pour i = 1), substitution décidée à la frappe ; appartenance jugée par game_player ; « présent » = non parti. Amender 10 § 7.4, § 7.6 et le code.

## n° 48

R et P décrites comme fenêtres de préchargement, exception de la manche 1 pendant P, et marge de préchargement comptée en dᵢ au lieu de preload_lead_ms.

- **Emplacements** : 00 l.7, l.17, l.21, l.75, l.97, l.118, l.171, principe 6 ; 10 § 10 l.1106, l.1108, l.1112 ; RoomSettingsBounds RECOMMENDED_MIN_REVEAL_DURATION
- **Résolution** : Tous les paliers servables dès Tᵢ − preload_lead_ms, sans exception ; P = décompte de lancement ; avertissement « R < 5 s » gardé pour l'accessibilité. Réécrire 00 et 10 § 10.

## n° 49

Borne d'URL de resynchronisation « jamais plus d'une » et « courant plus suivant » dans la même phrase.

- **Emplacements** : 10 § 15 l.1410, § 12 l.1195
- **Résolution** : « Au plus une URL par palier dont la garde est franchie » ; toute levée après révélation passe par la question « Révélation ».

## n° 50

Fin anticipée « tous verrouillés » contre « saisie close » ; correction RTT et grâce symétrique ±300 ms contre constante serveur unilatérale.

- **Emplacements** : 00 l.27, l.94, l.172, principe 4 l.381 ; CLAUDE.md §2, règle 7 ; 10 § 1.3 l.63, § 1.8 L3, § 7.5 l.845, § 7.7 l.892
- **Résolution** : Adopter 10 : saisie close ; décalage unilatéral de tier_grace_ms à la réception, offset d'horloge pour l'affichage seulement ; refaire le calcul de la borne croisée 1. Corriger 00 et CLAUDE.md.

## n° 51

« Essai unique » et anti-spam formulés par joueur au lieu de par siège.

- **Emplacements** : 00 l.50, l.58 ; questions-ouvertes l.321 ; 10 § 7.1 l.740
- **Résolution** : Reformuler « par siège » ; limiteur answer clé sur le siège ; résidu multi-siège assumé.

## n° 52

Film suspendu « sorti à la seconde de toute partie en cours », alors que 10 ne vérifie qu'au prochain service ou palier.

- **Emplacements** : questions-ouvertes l.314 ; 10 § 4.3 l.520, § 4.1 l.478
- **Résolution** : 60 ajoute une annulation active (job sur la file game) sur suspension ou retrait admin ; valeur d'incident movie_suspended ; dépublication de curation paresseuse.

## n° 53 — tranchée par le porteur

« Affiche » à la révélation, qu'aucun schéma ne stocke et qu'aucune voie légale ne permet ; repli LQIP sans stockage.

- **Emplacements** : 00 l.20, principe 6 l.383, l.171 ; 10 § 3.1 l.266, § 4.1, l.1110
- **Résolution** : Retirer « affiche » de 00 l.20 ; le reste suit les questions « Révélation » et « LQIP ».

## n° 54

Préalable du relevé du VPS contradictoire dans questions-ouvertes.

- **Emplacements** : questions-ouvertes l.266, l.268, l.349, l.354, l.371 ; 00 l.441, l.473 ; REPRISE l.71
- **Résolution** : Aligner après la réponse à la question posée à part ; le montage et la capacité restent à 100.

## n° 55

Charge ciblée du QCM « quatre chaînes, rien d'autre » contre drapeau choices_use_original_title transmis avec elles ; lang du QCM = locale effective atteinte.

- **Emplacements** : 05 l.179 ; 10 § 7.4 l.805
- **Résolution** : Amender 05 : les quatre chaînes, ce seul drapeau et un attribut lang égal à la locale effective.

## n° 56

Job de frontière « auto-périmant qui ne fait rien » contre écritures de journal obligatoires ; relâchement du job incompatible avec --tries=1.

- **Emplacements** : questions-ouvertes l.292 ; 00 l.328 ; 10 § 7.4 l.835 ; Worker.php l.683-699
- **Résolution** : Écritures de transition idempotentes toujours exécutées, seule la diffusion se périme ; attente en processus (< 1 s) au lieu d'un relâchement.

## n° 57

Leurres « tirés au lancement » dans le code contre première composition dans 10.

- **Emplacements** : RoundFactory.php l.179-182 ; 10 § 3.2 l.281, § 12 l.1194
- **Résolution** : Tirage à la première composition (T₁ Facile, T_N Normal), contexte draw:decoys:{roundId} ; corriger le docblock.

## n° 58

guess.prefix_was_ambiguous toujours faux tel qu'écrit ; repli de cache et lecture « une fois par appariement exact » qui violent L4 ; invalidation L2 fausse (recomputeAmbiguity touche d'autres films).

- **Emplacements** : 10 § 7.6 l.877, § 3.5 l.348, l.362, § 12 l.1192, § 1.8 L4 ; AnswerKeyProjector.php l.40-43, l.108-145 ; GuessFactory.php l.165-195
- **Résolution** : Colonne = homonymie à l'instant du match ; lecture WHERE normalized = ? à chaque soumission ; aucun cache au J1 ; fabriques alignées. Amendements textuels de 10.

## n° 59

Clic QCM en Normal crédité au palier N−1 par la grâce ; fenêtre d'acceptation autour de D non définie (réponse soumise après lecture de la révélation).

- **Emplacements** : 10 § 7.5 l.845 ; 00 l.19-21, l.47 ; InputDifficulty::choicesOpenTierIndex()
- **Résolution** : Plancher : tier_index ≥ palier d'apparition du QCM ; recevable jusqu'à D + tier_grace_ms, et le paquet de révélation (titres) n'est diffusé qu'à D + tier_grace_ms (clôture visible à D). Renvoi dans 10 § 7.5.

## n° 60

Exemples « seigneur des anneaux 2 » et « The Two Towers » présentés comme acceptés par règle.

- **Emplacements** : 00 l.54 ; 05 l.189
- **Résolution** : Reformuler « si l'alias existe » ; le sous-titre suit la question « Sous-titre ».

## n° 61

Titres non latins « toujours acceptés », alors que Str::ascii rend la chaîne vide pour le japonais, le coréen et la pleine chasse, et « Rocky Ⅳ » devient « rocky ».

- **Emplacements** : questions-ouvertes l.303 ; 05 l.134, l.193 ; AnswerKeyNormalizer.php l.81 ; ImportFilterTest.php l.214
- **Résolution** : Str::transliterate() à l'étape 2 (même alphabet, A6 non rouvert), attente du test réécrite, reprojection, sorties figées dans l'empreinte de validation_version.

## n° 62

« Aucune chaîne saisissable n'est jamais tronquée » alors que la translittération allonge.

- **Emplacements** : 10 § 1.3 l.74 ; AnswerKeyNormalizer.php l.37, l.90
- **Résolution** : Troncature symétrique à 200 assumée ; 10 reformulé en « aucune chaîne n'excède la colonne ».

## n° 63

Test L4 sous QUEUE_CONNECTION=sync (job exécuté dans la requête) et « aucune ligne » avec le driver database.

- **Emplacements** : 10 § 7.9 l.932 ; phpunit.xml l.36 ; .env.example l.42
- **Résolution** : Test sous Queue::fake() : même nombre d'envois et de requêtes quelle que soit la distance ; « aucune ligne » = tables de domaine.

## n° 64

B_max surchargeable par configuration et flottant, jamais persisté, alors que le rejeu doit redonner exactement points_total ; constantes de règle logées dans le value object de confort.

- **Emplacements** : PlatformLimits.php l.48, l.145-151 ; 10 § 6.1 l.645, l.649, § 7.2 l.768, l.787, § 7.5 l.849 ; CLAUDE.md §2 ; questions-ouvertes l.66
- **Résolution** : B_max en pourcentage entier dans PlatformLimits, non surchargeable (ou test contre ScoringRules::VERSION), calcul intdiv ; 80 écrit que ce sont des constantes d'instance, jamais résolues par compte ni plan. Amender 10 § 6.1.

## n° 65

Vocabulaire et bornes de 80 : « partie abandonnée », « figer les scores » à la révélation, « sans gradient », 120 lignes guess, points visibles « après reveal_ends_at », « mode solo à 2 joueurs ».

- **Emplacements** : 00 l.21, l.90, l.439 ; 10 § 7.3 l.799, § 7.6 l.885, § 12 l.1200 ; Guess.php l.25, l.55-56 ; CLAUDE.md §2
- **Résolution** : « interrompue » ; la révélation clôt la saisie ; propriété écrite du sans-gradient ; borne 12 × min(M+3, |vivier|) et game_player non borné ; points publiables dès revealing ; CLAUDE.md renvoie à 00 l.27.

## n° 66

Contexte de graine tiebreak:{roundId} sans usage assigné, lu comme départage de score.

- **Emplacements** : 10 § 7.2 l.762, l.782 ; 00 l.37, l.137
- **Résolution** : 80 écrit qu'aucune égalité de score n'est tranchée par la graine ; 30 assigne ou fait retirer ce contexte.

## n° 67

Clôture forcée stale_game qui pose ended_at hors du gel ; score affichable pendant une manche incluant les points du verrouillage en cours ; rounds_played et correct_answers sur deux filtres ; classement au-delà de 12 lignes.

- **Emplacements** : 10 § 11.1 l.1127, § 11.3 l.1173, § 1.8 L1 ; Guess::counted() ; questions-ouvertes l.322 ; 00 l.127
- **Résolution** : Action de gel seule écrivaine de ended_at et des agrégats ; score montré aux autres sur manches revealing/completed ; filtre unique (manches completed) et invariant correct_answers ≤ rounds_played ; classement sans plafond.

## n° 68

Pied de page légal sur le back-office alors que seul le domaine admin y est chargé.

- **Emplacements** : 00 l.222, principe 12 ; 05 l.111 ; TranslationDomains::selected()
- **Résolution** : Chaque groupe joueur déclare legal ; le back-office porte admin.footer.*.

## n° 69

Pages légales : habillage dans la langue du visiteur contre <html lang=fr> « cachable » ; « signaler un contenu » rédigée en français contre formulaire via dictionnaire FR/EN.

- **Emplacements** : 05 l.141, l.253 ; 00 principe 12 l.389, l.401 ; VaryOnLanguage.php docblock ; routes/web.php
- **Résolution** : <html lang> = locale du visiteur, corps FR en partiel Blade dans <div lang=fr>, habillage et libellés via legal.*, Vary étendu, « cachable » retiré ; corriger 05 l.253.

## n° 70

SSR déclaré actif ou absent selon les textes, alors que la production n'a pas de processus Node.

- **Emplacements** : 10 § 1.7 l.134 ; User.php docblock ; config/inertia.php ; use-forced-appearance.ts docblock
- **Résolution** : Aucun SSR en v1 : ssr.enabled = false (parité dev/prod, 00 l.341) ; corriger les docblocks.

## n° 71

robots.txt autorise tout et aucun en-tête noindex n'est émis, alors que le J1 est noindex intégral et le back-office disallow inconditionnel.

- **Emplacements** : public/robots.txt ; 00 l.363 ; questions-ouvertes l.302, l.315
- **Résolution** : X-Robots-Tag noindex par défaut, Disallow /admin et /f/ seulement (jamais Disallow / qui empêcherait de lire le noindex), Referrer-Policy globale.

## n° 72

Principe 13 outillé sur les « composants de jeu » alors que 21 fichiers joueurs hérités violent les tokens et que le script ne surveille que le back-office ; marques tierces (TMDB, Google, Discord) contre « aucun asset de marque ».

- **Emplacements** : 00 principe 13 l.390, principe 12 ; questions-ouvertes l.330 ; CLAUDE.md règle 5 ; scripts/check-theme-tokens.mjs
- **Résolution** : Tout répertoire joueur nouveau entre dans WATCHED à sa création, les hérités à leur réécriture, méta-vérification de classement ; exception fermée des marques tierces en fichiers statiques et tokens dédiés.

## n° 73

Inventaire des cookies du principe 12 incomplet (sidebar_state, XSRF-TOKEN, remember_web_*, localStorage appearance) ; phrase publique sur l'IP de session inexacte.

- **Emplacements** : 00 principe 12 l.389 ; bootstrap/app.php ; 10 l.1141, l.1148 ; config/session.php
- **Résolution** : 90 tient l'inventaire normatif publié et 100 le teste ; 10 corrige « au plus 24 h après la fin de la session ».

## n° 74

Pseudo masqué dont l'avatar serait le discriminant, alors que les doublons d'avatar sont permis ; règle 8 « pas de timer client » contre annonces aria-live à seuils relatifs.

- **Emplacements** : 00 l.204 ; CLAUDE.md règle 8 ; 00 principe 8 l.385, l.13
- **Résolution** : Libellé neutre + ordinal dérivé au rendu (« Joueur 3 ») ; règle 8 reformulée « aucun minuteur client ne décide ».

## n° 75

Licence MIT et identité du starter dans composer.json, aucun LICENSE.

- **Emplacements** : composer.json l.3-10 ; 00 l.420 ; questions-ouvertes l.101
- **Résolution** : LICENSE « tous droits réservés », proprietary / UNLICENSED, nom et description du paquet corrigés, test.

## n° 76

Instantané bloquant avant migration sur 2 tables dans 00 et questions-ouvertes, 5 dans 10 et CLAUDE.md ; hook de déploiement incomplet (lang:hash, deploy:guard, snapshot, seeder de presets).

- **Emplacements** : 00 l.345, l.351 ; questions-ouvertes l.222, l.289, l.391 ; 10 l.136 ; 05 l.121
- **Résolution** : Cinq tables partout ; 100 écrit le hook complet (voir question « Déploiement ») et 00 / questions-ouvertes y renvoient.

## n° 77

CLAUDE.md et 00 décrivent comme ouvertes des dettes purgées et comme absents des répertoires et commandes qui existent ; REPRISE annonce fait le test de bout en bout § 13.3 qui ne lance aucune partie.

- **Emplacements** : CLAUDE.md §4, §5, §6, §8 ; 00 l.331 ; REPRISE l.132, l.149-150 ; DemoCatalogueChainTest.php en-tête ; 10 l.1269
- **Résolution** : Mettre CLAUDE.md, 00 l.331 et REPRISE à jour ; l'exigence 4 du § 13.3 est partielle, le test de bout en bout s'écrit avec l'action de lancement.

## n° 78

Garde « aucun domaine littéral » inapplicable telle quelle (hôtes tiers et TLD réservés présents) ; « six noms » de sous-traitants alors que le suivi d'erreurs est optionnel.

- **Emplacements** : questions-ouvertes l.262, l.270 ; 00 l.441, l.473 ; config/services.php, mail.php
- **Résolution** : Liste d'autorisation commentée, garde permanente ; « un nom par catégorie branchée ».

## n° 79

VITE_REVERB_* figées au build en CI empêchent de promouvoir un artefact préprod → prod.

- **Emplacements** : 00 l.319 ; questions-ouvertes l.290, l.296, l.299
- **Résolution** : Echo configuré à l'exécution depuis window.location et une prop partagée (clé publique) ; la garde zéro secret distingue identifiants publics et secrets.

## n° 80

Sauvegardes : écriture seule, chiffrement inexploitable depuis la machine et 30 jours incompatibles avec un outil dédupliquant ; restauration prévue sur la préprod ; services de préprod arrêtés par root ; tunnel hors UE du montage (b).

- **Emplacements** : questions-ouvertes l.220, l.222, l.296, l.389 ; 00 l.471 (b) ; décision 17
- **Résolution** : Chiffrement asymétrique, clé PutObject et bucket versionné, archives complètes à 30 jours, tier froid adressé par empreinte ; restauration sur cible jetable ; aucun geste root par promotion ; tunnel UE ou auto-hébergé.

## n° 81

database.sqlite dit vestige alors que la CI crée et migre ce chemin.

- **Emplacements** : CLAUDE.md §8 ; .env.example DB_CONNECTION=sqlite ; composer.json setup
- **Résolution** : Documenter comme base de fumée des migrations en CI, distincte du :memory: des tests.

