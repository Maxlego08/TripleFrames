# Reprise — TripleFrames

**Dernière session : 22/09/2026 — `10-catalogue-et-modele-de-donnees.md` écrite et vérifiée.** Le schéma est arrêté : 35 tables de domaine plus `users` altérée. Les 19 décisions sont prises. Une seule reste ouverte : le nom de domaine (décision 5). Ce fichier dit où on en est, ce qui est verrouillé, ce qui bloque, et par quoi commencer demain. À jour à chaque fin de session.

---

## Où en est le projet

**Phase : implémentation.** Les specs `00`, `05` et `10` sont écrites. Le schéma est en base ; modèles, factories et seeders existent ; **le socle i18n est complet** ; **l’import TMDB fonctionne contre l’API réelle** ; un **début de panel admin** est en place. Le lot 0 (dette du starter) est purgé.

**État de la suite, vérifié le 23/09 :** `composer ci:check` vert de bout en bout — Pint passé, **PHPStan 0 erreur au niveau 7**, **Pest 491 tests / 4 433 assertions** (40 au début du 22/09), `npm run check` passé (126 fichiers formatés, 99 lintés, **script anti-couleur-en-dur inclus**), `tsc --noEmit` 0 erreur, `npm run build` OK. `migrate` et `migrate:rollback` passent 42/42.

**Catalogue réel sur la base de dev :** 3 films importés depuis TMDB (Fight Club, Parasite, Le Labyrinthe de Pan), tous en `draft`, tous marqués `is_import_exception` (2 pour `exception_for_language`). **0 frame curée, donc vivier = 0 à tout N** — c’est normal, un film n’entre au vivier qu’avec ses images curées aux niveaux 1/3/5. C’est exactement ce que la spec `20` débloque.

| Fichier | Rôle | État |
|---|---|---|
| `docs/specs/00-overview.md` | Vue d'ensemble : concept, boucle de jeu, réglages, vocabulaire, principes, exploitation, jalons, ouverture, carte des 12 specs, décisions du cadre | **v3, validée, à jour des 19 décisions** (473 lignes) |
| `docs/specs/questions-ouvertes.md` | Journal de décisions : 19 décisions closes + arbitrages déjà tranchés + ordre d'écriture des specs + risques ouverts | **clos** (391 lignes) |
| `docs/specs/05-i18n-et-langues.md` | Propriétaire unique de la règle de langue | **ÉCRITE le 22/09** (316 lignes) |
| `docs/specs/10-catalogue-et-modele-de-donnees.md` | **Propriétaire unique du schéma** : 35 tables de domaine + `users` altérée, conventions portables SQLite/MySQL, rétention et purge, arbitrages | **ÉCRITE le 22/09** (1 426 lignes) |
| `CLAUDE.md` | Mémoire projet chargée à chaque session : règles de jeu, stack, commandes, conventions, pièges | **à jour** (148 lignes) |
| `docs/specs/20` → `100` | Les 9 specs techniques restantes | **aucune écrite — `20` est la prochaine** |
| `database/migrations/` | 37 migrations de domaine + les 5 du starter | **ÉCRITES et vertes le 22/09** (42 fichiers, 35 tables de domaine) |
| `app/Models/` | 35 modèles de domaine + `User` amendé + un pivot typé | **ÉCRITS le 22/09** (37 fichiers) |
| `app/Enums/`, `app/Settings/`, `app/Casts/`, `app/Support/` | 44 enums, `RoomSettings` + bornes + limites, le cast versionné, grille d'exclusion et préfixes de stockage | **ÉCRITS le 22/09** |
| `tests/Feature/Schema/` | Balayages : familles d'horodatage, dérive PHPDoc ↔ colonnes, sérialisation et fuites, journaux en ajout seul, cast versionné | **ÉCRITS le 22/09** (172 tests) |

---

## Ce qui est verrouillé — ne pas rouvrir

Le jeu : blindtest de films et dessins animés. Une manche = 1 film, `N` images (3 par défaut) affichées successivement de la plus cryptique à la plus évidente, sur une durée `D` (30 s par défaut). Salons privés par code + mode solo. Le premier qui trouve ne coupe pas la manche : il est verrouillé, les autres continuent. Points par palier d'image + bonus de rapidité. Serveur autoritaire, Reverb.

**Rien n'est une constante.** `N` (2-5), `D` (10-120 s), la durée de révélation `R` (3-20 s), le barème et le nombre de manches sont des réglages de salon, en deux onglets Simple / Avancé, sauvegardables par un utilisateur connecté. Un film n'a pas 3 images figées : il a une **banque d'images classées sur 5 niveaux**, avec plusieurs variantes par niveau, et le tirage préfère une variante que le salon n'a pas encore vue — **axe salon uniquement, aucun axe joueur**.

---

## Décisions du 22/09

Relevé. Le détail, les options écartées et les cascades vivent dans `docs/specs/questions-ouvertes.md` ; la règle, elle, vit dans la spec de son domaine.

| # | Décision | Prise | Écart |
|---|---|---|---|
| 1 | Ouverture de la v1 | **Public et indexé dès la v1** | **oui** |
| 2 | Monétisation | **Rien en v1, architecture laissée neutre** pour un plan payant futur | **oui** |
| 3 | Rythme et première vraie partie | **~3 mois souples, ~10 h/semaine**, dev et curation confondus | non |
| 4 | Mentions légales | **Personne physique** : e-mail de contact public, adresse sur demande, textes FR | non |
| 5 | **Nom de domaine** | **À acheter — SEULE DÉCISION ENCORE OUVERTE** | — |
| 6 | Dépôt | **Privé, tous droits réservés**, comptes tiers au nom du porteur | non |
| 7 | Origine des images | **TMDB + captures personnelles**, source tracée image par image | non |
| 8 | Recadreur | **Intégré au back-office**, zoom, ratio fixe, classement et prévisualisation | non |
| 9 | Curation | **Rôles distincts** : le curateur cure et publie, l'**admin seul** modère et gère les accès | **oui** |
| 10 | Débit de curation | **Lot pilote de 20 films** chronométré avec l'outil livré, puis cible de volume | non |
| 11 | Notoriété à l'import | **≥ 500 votes, langue originale FR/EN/JA, depuis 1970** + voie d'exception tracée | **oui** |
| 12 | Contenus sensibles | **`adult` + FR -18 / US NC-17 exclus**, les -16 restent | non |
| 13 | Réponse partielle sur une saga | **Préfixe accepté sauf si un autre film publié le partage** | non |
| 14 | Avatar téléversé | **Pas en v1** : prédéfinis + copie provider ; téléversement en v1.1 | non |
| 15 | Historique | **Liste des parties + 4 compteurs** | non |
| 16 | Hébergement | **VPS Plesk existant**, abonnement de plus, aucun coût supplémentaire | **remplace un arbitrage** |
| 17 | Sous-traitants | **UE seulement**, liste fermée et nommée | non |
| 18 | Sauvegardes | **24 h de perte maximum**, stockage chez un **autre fournisseur**, restauration testée | non |
| 19 | Rétention | **12 mois glissants**, compte compris — **plafond, jamais plancher** | **oui** |

Les cinq écarts (1, 2, 9, 11, 19) et le remplacement d'arbitrage (16) sont ceux qui produisent des cascades : ils sont traités longuement dans `questions-ouvertes.md`.

---

## Ce qui bloque

1. **Acheter le domaine** (décision 5). Le nom n'a pas été fourni. Tant qu'il n'est pas acheté, toutes les specs écrivent `<DOMAINE>`, et **aucun compte de production ni aucune passkey n'est créé**. Il bloque aussi le développement d'OAuth et des passkeys via le montage `dev.<DOMAINE>` résolu en 127.0.0.1 — et tout déploiement du jalon 1 autre qu'un tunnel temporaire. Une dizaine d'euros, seul achat sur le chemin critique.
2. **Relever le VPS** — **cette semaine, avant l'écriture de `60`**, pas avant `100` : `ssh root@`, `free -m`, `nproc`, `uptime`, `ss -ltnp`, `/opt/plesk/php/8.3/bin/php -m`, liste des abonnements servis, version de Plesk. Sans accès root : ni Redis dédié, ni workers systemd, ni Reverb, donc pas de temps réel sur cette machine. Seuil minimal défendable : 4 Go de RAM, 2 vCPU.
3. **Fournir les six noms de sous-traitants UE** : hébergeur, SMTP, suivi d'erreurs, stockage objet de sauvegarde (fournisseur différent du VPS), supervision externe, second canal d'alerte. Sans eux, la page de confidentialité ne peut pas être écrite, donc le site ne peut pas ouvrir. Y ajouter la confirmation que le VPS est bien en région UE.
4. **Commander les textes légaux** (décision 4) — **délai externe de 2 à 6 semaines, à lancer en parallèle du jalon 1, pas après**. Poser au même conseil, en même temps, la question de la **licéité de l'acte de capture** (décision 7 : d'où une capture personnelle a le droit de venir).
5. **Valider la coupe du jalon 1** (décision 3) : c'est une décision produit. Elle inclut la cible de **60 films publiés en passe 1** (180 images, 6 à 9 h de curation) et la **liste d'amorçage d'environ 200 identifiants TMDB** à constituer (4 à 6 h).

Points d'exécution secondaires, qui ne rouvrent aucune décision : personne de confiance pour la seconde adresse d'administration, 2FA sur `curator` **et** `admin` (valeur retenue, réversible), seuils chiffrés du lot pilote, seuil minimal de manches du taux de réussite (20), durée de dormance d'un compte (24 mois).

---

## À trancher par la spec 20

Liste **normative** des questions que le lot « début de panel admin » a
rencontrées et n'a délibérément pas refermées. Elle existe pour que la
citation de `routes/admin.php` pointe sur un chemin vérifiable, et pour
qu'aucune de ces questions ne soit réputée tranchée ailleurs. Tant que
`docs/specs/20-back-office-curation.md` n'est pas écrite, **c'est ici que la
liste vit**.

### Autorisation et accès

1. **2FA obligatoire sur les rôles privilégiés** (valeur retenue, réversible) — **non implémentée**. Reste à trancher : contrainte posée à la connexion, ou middleware du groupe `/admin` ? Redirection vers l'enrôlement, ou 403 ? Quelle file « rôle privilégié sans 2FA » (la requête existe déjà, servie par `users_role_last_login_at_index`, colonne de tête) ? Le groupe de routes est écrit pour que la contrainte ne soit **qu'un middleware à ajouter**, sans toucher à un contrôleur. Aujourd'hui, un `curator` ou un `admin` ouvre `/admin` avec un simple mot de passe.
2. **Écran de gestion des accès** : attribution et retrait de rôle, historique daté, règle du dernier administrateur indéboulonnable — et donc `App\Policies\UserPolicy` (`updateRole` ⇒ admin seul), volontairement non écrite ici.
3. **Suspension conservatoire et retrait juridique** : admin SEUL (décision 9), cascade sur les `frame`, suppression explicite des `seen_frame`, `admin_action` en rétention permanente, job de suppression des fichiers.
4. **Modération des pseudos et des copies d'avatar provider** : masquage, levée, repérage d'un signaleur abusif — admin seul.
5. **`App\Policies\FrameReviewPolicy`** refusant `update` et `delete` (immuabilité opposable, spec 10 § 4.2) : aucune ligne `frame_review` n'existe, la policy arrive avec la passe de revue.
6. **Le premier administrateur peut-il être créé sans preuve d'adresse**, ou doit-il d'abord s'inscrire normalement ? `admin:first-admin` promeut un compte existant ; la création reste derrière `--create`, drapeau explicite et provisoire, qui pose `email_verified_at` sans vérification d'adresse. Question mitoyenne de la spec 40.

### Curation, images, publication

7. **Éditeur de la banque d'images** : ajout d'une variante depuis les visuels TMDB, téléversement d'une capture personnelle, collage depuis le presse-papiers, bande de visuels en lot, pilotage clavier.
8. **Recadreur intégré** : zoom, déplacement, ratio de jeu fixe, rectangle par défaut pré-proposé, traitement différé sans attente (décision 8).
9. **Classement d'une image sur l'échelle `frame_level` 1-5** et prévisualisation en conditions de jeu.
10. **Grille d'exclusion interactive**, passe de revue produisant une ligne `frame_review` immuable, déclaration de la source au moment de la revue, file « images à re-revoir » après changement de `ExclusionGrid::CURRENT_VERSION`.
11. **Workflow de publication et de dépublication** (film et image) : quel bouton, quelle confirmation, quel motif obligatoire — et l'avertissement NOMINATIF exigé par la décision 13, « voici les préfixes que cette publication rend ambigus et à quels films ils appartenaient », affiché AVANT confirmation.
12. **Coche « contenu vérifié, pas de classification restrictive »** : depuis quel écran, avec quelle confirmation, et la ligne `admin_action` (`movie.content_verified`) qui l'accompagne.
13. **File de curation ORDONNÉE** : critère de tri, réservation d'un film par un curateur, reprise après interruption. Le tableau de bord de ce lot ne montre qu'une file « plus anciens brouillons d'abord », sans réservation.
14. **Mesure du débit de curation** (décision 10) : battement de cœur throttlé alimentant `movie.curation_active_seconds`, exclusion des pauses > 60 s, temps de recadrage par image, médiane et p90, projection en heures, lot pilote de 20 films, SEUILS ET RÉACTION fixés avant le lot.
15. **Couverture des titres par langue et file « titres manquants »** : retirée du tableau de bord de ce lot parce que la carte des specs la range dans la 20. La fiche film expose la donnée brute (`title_locale_mask`, fraîcheur du masque), pas la file.
16. **`movie_group`** : suggestion de candidats homonymes et remakes (formes normalisées proches dans `answer_key`, ou `collection_id` identique) et geste de regroupement manuel.
17. **Correction des titres et des alias**, promotion d'un `near_miss` en alias, et l'effet de bord `origin = 'curator'` qui protège la correction d'une resynchronisation.

### Import et journal de provenance

18. **Resynchronisation TMDB depuis l'écran** : écran de différences, liste close de ce qui est écrasable (spec 10 § 9.3), proposition de dépublication quand une certification restrictive apparaît. Ce lot n'expose PAS `--resync`.
19. **Import unitaire par recherche TMDB** (champ de recherche, résultats, import d'une fiche) : ce lot n'expose que le balayage `discover` et le collage d'identifiants.
20. **Reprise d'un COLLAGE interrompu** : la liste collée n'est stockée nulle part — aucune colonne de la spec 10 ne la porte, et en ajouter une est une décision de la spec 10, pas de la 20. Trancher : re-coller à la main, fichier d'amorçage versionné, ou exigence formulée à la spec 10.
21. **Abandon explicite d'un balayage suspendu** : `POST /admin/import/run/{importRun}/abandon` (garde `ImportRunPolicy::update`) posant `status = failed` + `finished_at`. Ce lot s'en passe en bornant la concurrence dans le temps (`ImportLauncher::BUSY_GRACE_MINUTES`) : un balayage suspendu n'interdit plus d'en lancer un autre. Reste à trancher si le curateur doit pouvoir le clore d'un bouton, et avec quel motif.
22. **Films jamais trouvés et incidents techniques par film** (`round_movie_found_idx`) : aucune partie n'existe encore, l'écran serait vide par construction.

### Demandes de retrait

23. **File des demandes de retrait** : compte à rebours, accusé de réception et notification de décision FR/EN (domaine `mail`, jamais `admin`).

### Dettes d'accessibilité héritées des primitives shadcn

24. **Coquille mobile de la barre latérale** : sous le point d'arrêt mobile, `components/ui/sidebar.tsx` rend `<Sidebar>` dans un `<Sheet>` dont le `SheetTitle` (« Sidebar ») et la `SheetDescription` (« Displays the mobile sidebar. ») sont **en dur, en anglais**, et constituent le nom et la description accessibles du dialogue. Ils ne sont pas supplantables de l'extérieur : `Sidebar` répand ses props sur `<Sheet>` (racine Radix, qui ne rend aucun élément), pas sur `<SheetContent>`. Deux issues, aucune ne touchant `components/ui/*` : composer la coquille mobile dans `admin-sidebar.tsx` avec son propre `<SheetContent>`, un `SheetTitle` en `sr-only` tiré de `admin.nav.section` et une `SheetDescription` sur une clé `admin.a11y.nav_mobile` à créer ; ou renoncer à la barre latérale en portrait. Le cas `SidebarRail` (« Toggle sidebar ») est déjà écarté pour la même raison ; `sheet.tsx` (« Close ») est neutralisé par `[&>button]:hidden`, donc hors de l'arbre d'accessibilité.

---

## Prochaine action, dans l'ordre

1. ~~Écrire les factories et les seeders~~ — **FAIT le 22/09** : 36 factories, 4 seeders, catalogue de démonstration de 16 films jouables à N=2 à 5 (394 lignes, 78 fichiers `.webp` réels), et le test de chaîne du `10` § 13.3 : « un salon créé sur le catalogue de démonstration peut lancer une partie de 10 manches ». C'est le seul test qui prouve que la chaîne des **sept faits indépendants** tient — dont deux portent sur des octets réels sur disque. Les cinq exigences du § 13.3 sont impératives, en particulier : `Frame::factory()->published()` doit **écrire un vrai fichier WebP** et calculer `published_hash` sur les octets écrits (deux hashs tirés indépendamment ⇒ aucun film publiable et un lobby à « 0 film » sur un catalogue complet), et les **trois comptes de démonstration** doivent précéder le catalogue. **Le lot 0 est entièrement appliqué** — code et documentaire. Ne pas le refaire.
2. **Écrire `20-back-office-curation.md`** — débloque le démarrage de la curation, seul chantier humain de plusieurs semaines. Elle doit aussi **confirmer explicitement l'arbitrage A7** de `10` (aucune colonne de support ni d'édition sur une capture) et écrire le contenu item par item de la grille d'exclusion.
3. Puis `30` → `50` → `60` → `70` → `80` → `40` → `90` → `100`, ordre détaillé en fin de `questions-ouvertes.md`.

**Le chemin critique n'est pas le code, c'est la curation.** Plus tôt le back-office de curation existe, plus tôt le compteur démarre. Et le budget est commun : ~130 h sur le trimestre, dev **et** curation confondus.

---

## Dettes du starter à purger avant la première ligne de code métier

- ~~`tests/Pest.php` : `RefreshDatabase` commenté~~ — **fait le 22/09**, actif.
- ~~Larastan sature la mémoire~~ — **fait**, `--memory-limit=1G` est dans `composer types:check`.
- ~~Pint échoue sur 12 fichiers~~ — **fait**, 13 fichiers corrigés.
- ~~PHPDoc `Carbon` de `User`~~ — **fait**, `CarbonImmutable` partout.
- Aucun temps réel : `BROADCAST_CONNECTION=log`. Reverb, `laravel-echo` et `pusher-js` restent à installer.
- Session + cache + queue tous sur le MySQL Homestead distant : c'est le goulet du cadencement. Redis est acté avant le moteur, et **`predis/predis` n'est pas encore requis en composer** (pas d'`ext-redis` sous Windows) — c'est `predis` qui doit être la ligne de base.
- Socialite absent, aucune lib d'image installée (le pipeline vise **Imagick**, présent).
- **`.env.example` à compléter** : Reverb, Redis, OAuth, TMDB, racine du disque `frames` (`FRAMES_DISK_ROOT`). `composer setup` le copie, donc toute variable manquante casse la CI. Ajouter aussi une étape de création du répertoire dans `composer setup`, sinon le seeder d'images écrit dans un chemin inexistant (`10` § 13.3).
- `APP_NAME=Laravel` → les titres React affichent « Laravel ». `database/database.sqlite` est un vestige.

---

## Pour relancer la session

Dire à Claude : **« Lis `docs/REPRISE.md`, puis écris `docs/specs/20-back-office-curation.md` »** — c’est elle qui débloque la curation, le seul chantier de plusieurs semaines, et elle arrive avec les **24 questions en attente** listées plus haut.

**Pour utiliser le panel admin** : `php artisan admin:first-admin` crée ou promeut le premier administrateur (interactif — le mot de passe ne passe jamais en argument de ligne de commande), puis `composer dev` et `/admin`.

`CLAUDE.md` se charge tout seul et contient déjà les règles de jeu, la stack, les commandes et les pièges de l'environnement — inutile de les redonner.
