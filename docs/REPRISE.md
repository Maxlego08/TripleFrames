# Reprise — TripleFrames

**Dernière session : 22/09/2026 — `10-catalogue-et-modele-de-donnees.md` écrite et vérifiée.** Le schéma est arrêté : 35 tables de domaine plus `users` altérée. Les 19 décisions sont prises. Une seule reste ouverte : le nom de domaine (décision 5). Ce fichier dit où on en est, ce qui est verrouillé, ce qui bloque, et par quoi commencer demain. À jour à chaque fin de session.

---

## Où en est le projet

**Phase : implémentation du schéma.** Les specs `00`, `05` et `10` sont écrites ; **le schéma est en base et les modèles existent**. Le lot 0 (dette du starter) est purgé.

**État de la suite, vérifié le 22/09 :** `composer test` vert — Pint passé, **PHPStan 0 erreur au niveau 7**, **Pest 212 tests / 1 151 assertions** (40 avant cette session). `migrate` et `migrate:rollback` passent 42/42.

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

## Prochaine action, dans l'ordre

1. **Écrire les factories et les seeders**, puis le test de bout en bout du `10` § 13.3 : « un salon créé sur le catalogue de démonstration peut lancer une partie de 10 manches ». C'est le seul test qui prouve que la chaîne des **sept faits indépendants** tient — dont deux portent sur des octets réels sur disque. Les cinq exigences du § 13.3 sont impératives, en particulier : `Frame::factory()->published()` doit **écrire un vrai fichier WebP** et calculer `published_hash` sur les octets écrits (deux hashs tirés indépendamment ⇒ aucun film publiable et un lobby à « 0 film » sur un catalogue complet), et les **trois comptes de démonstration** doivent précéder le catalogue. **Le lot 0 est entièrement appliqué** — code et documentaire. Ne pas le refaire.
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

Dire à Claude : **« Lis `docs/REPRISE.md`, puis écris les factories, les seeders et le test de bout en bout du `10` § 13.3 »**

Ou, si tu préfères repasser au travail de spécification plutôt qu'au code : **« … puis écris `docs/specs/20-back-office-curation.md` »** — c'est elle qui débloque la curation, le seul chantier de plusieurs semaines.

`CLAUDE.md` se charge tout seul et contient déjà les règles de jeu, la stack, les commandes et les pièges de l'environnement — inutile de les redonner.
