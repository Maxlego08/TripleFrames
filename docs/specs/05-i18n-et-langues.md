# Internationalisation et langues

Ce document est le **propriétaire unique de la règle de langue** de TripleFrames. Il décide quelles locales existent, comment celle d'un joueur est négociée, persistée et changée, où vivent les dictionnaires, comment un titre de film se choisit, pourquoi un QCM ne peut pas être diffusé en clair au salon, et comment une troisième langue s'ajoute sans refonte. Il est écrit **avant le premier écran** parce qu'aucun texte ne doit naître en dur (principe 7, règle 4 de `CLAUDE.md`). Convention de renvoi, valable dans tout le document : « règle N » désigne `CLAUDE.md` §7, « principe N » désigne `00-overview.md` § Principes directeurs — les deux numérotations sont différentes et ne se recoupent pas.

Ce document ne possède **aucune table** : quand une colonne ou une ligne est nécessaire, l'exigence est formulée ici et le schéma est arbitré par `10-catalogue-et-modele-de-donnees.md`. Il ne possède ni la normalisation des réponses (`70`), ni les écrans (`90`), ni la matrice de tests (`100`) — seulement la règle de langue que ces specs consomment.

> **État réel du dépôt au moment d'écrire** : `APP_LOCALE=en` et `APP_FALLBACK_LOCALE=en`, **aucun dossier `lang/`**, **deux** appels `__()` dans `app/` (`ProfileController`, `SecurityController`, tous deux avec une chaîne littérale en clé), 100 % du JSX en anglais en dur, aucune brique i18n installée. Tout ce qui suit est à construire ; rien n'est à défaire.

---

## Locales activées

| Locale | Code BCP 47 | Libellé natif | Rôle |
|---|---|---|---|
| Anglais | `en` | English | **Repli d'instance** : langue servie quand rien d'autre n'est déterminé, langue des e-mails à destinataire de locale inconnue, langue indexée. |
| Français | `fr` | Français | Langue servie aux navigateurs francophones, langue **unique** du back-office. |

Le registre des locales est un **enum backed** `App\Enums\Locale`, pas un tableau de configuration : au niveau 7 de Larastan un `config('locales')` renvoie `mixed` et ne type rien, alors qu'un enum donne l'exhaustivité au `match` et interdit une locale inventée. Il porte le code, le libellé natif, le code BCP 47, le sens d'écriture (`ltr` en v1) et le **rang de repli d'affichage**. Le nom `Locale` est sans risque dans `App\Enums` : c'est la classe globale `\Locale` d'`ext-intl` qui devra être aliasée (`use Locale as IntlLocale;`) dans les rares fichiers qui l'utiliseraient, si l'extension est un jour ajoutée.

`APP_LOCALE=en` et `APP_FALLBACK_LOCALE=en` restent tels quels : ils décrivent exactement le repli d'instance. **Une locale n'est jamais lue depuis une entrée utilisateur sans passer par `Locale::tryFrom()`** — cookie, jeton, paramètre de formulaire et `Accept-Language` compris. Une valeur inconnue n'atteint jamais `App::setLocale()`, qui construit des chemins de fichiers.

**Ordre de repli d'affichage déclaré par l'instance** : `en`, puis `fr`. Cet ordre sert uniquement à choisir un titre quand celui de la locale du joueur manque ; il n'a aucun effet sur la négociation.

**Locale d'interface ≠ locale de catalogue.** L'interface est livrée en `fr` et `en`. Le catalogue, lui, stocke des titres et des alias dans **toute** langue que TMDB fournit, `ja` compris. Un film japonais a un `title_original` japonais et, quand TMDB la fournit, une **translittération latine stockée sur `movie.title_original_latin`** — une colonne invariante, jamais une ligne d'`alias` (`10` § A4) : `alias` reste une table de validation pure, qui n'alimente aucun chemin de rendu. Ces lignes existent sans qu'il faille activer `ja` comme langue d'interface.

---

## Négociation, persistance et changement de langue

### Résolution, premier élément trouvé gagnant

| # | Source | Quand |
|---|---|---|
| 1 | `users.locale` | Joueur authentifié. Un compte transporte sa langue d'un appareil à l'autre. |
| 2 | Cookie `locale` | Choix manuel déjà exprimé sur cet appareil. |
| 3 | Revendication `locale` du `player_token` | Invité revenu sans cookie (autre navigateur, cookie purgé) : le jeton restaure sa langue en même temps que son siège. |
| 4 | Négociation `Accept-Language` | Première visite. Restreinte aux locales activées, quality values respectées, `fr-CA`/`fr-BE`/`fr-CH` ramenés à `fr`. |
| 5 | Repli d'instance `en` | En-tête absent, illisible, ou ne proposant aucune locale activée. |

La résolution vit dans un middleware `App\Http\Middleware\SetLocale`, **calqué sur `HandleAppearance`** et ajouté en tête de la liste `$middleware->web(append: [...])`, donc **avant `HandleInertiaRequests`** : les props partagées doivent déjà connaître la locale quand elles sont construites. Il appelle `App::setLocale()`, rien d'autre.

`Carbon` est recâblé **une seule fois**, par un listener sur `Illuminate\Foundation\Events\LocaleUpdated` enregistré dans `AppServiceProvider::boot()` (le dépôt n'a pas d'`EventServiceProvider`), qui exécute `CarbonImmutable::setLocale()`. Le middleware ne le fait pas lui-même : la même bascule doit s'appliquer dans un **job de mail en file**, où aucun middleware HTTP ne tourne. `Date::use(CarbonImmutable::class)` étant actif, c'est bien `CarbonImmutable` que l'on règle.

Quand la locale a été obtenue par négociation (ligne 4), la réponse **repose le cookie** : la négociation est collante, un francophone ne rejoue pas la détection à chaque visite.

### Le cookie `locale`

Nom `locale`, durée un an, `path=/`, `SameSite=Lax`, `Secure` en production, **ajouté aux exceptions de chiffrement** dans `bootstrap/app.php` : `$middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale'])`. Raison, identique à celle d'`appearance` : c'est une préférence publique, non sensible, que le front lit et écrit directement pour appliquer la langue sans attendre un aller-retour — un cookie chiffré serait illisible côté client. Il n'est jamais une source d'autorité : sa valeur est validée par l'enum avant usage, et `users.locale` le supplante toujours.

### Persistance

| Nature de joueur | Support | Exigence adressée à la spec `10` |
|---|---|---|
| Compte | Colonne `locale` sur `users` | Toute lecture de la langue d'un compte renvoie une **locale activée**, jamais `null` ni une valeur inconnue, y compris pour un compte créé avant l'ajout d'une locale. `10` choisit la forme — colonne non nullable avec défaut, ou nullable résolue à la lecture. |
| Invité | Revendication `locale` du `player_token` signé | Le jeton porte déjà identité, siège et avatar prédéfini ; la langue le rejoint. |
| Joueur d'un salon | Colonne `locale` sur `player` | **Son existence est obligatoire**, et sa valeur doit être lisible **hors de toute requête HTTP** : une diffusion Reverb n'a ni requête ni cookie, et le serveur doit connaître la langue de chaque joueur pour composer un envoi ciblé au moment d'émettre, pas au moment de répondre. `10` choisit la forme. |

Changer de langue **re-signe** le `player_token` sans jamais changer le siège qu'il porte : la règle « un `player_token` = un siège » reste intacte, seul le contenu de la revendication `locale` change.

### Changement de langue

Un seul geste : `POST locale.update`, appelé par le sélecteur. Le sélecteur **précharge** le dictionnaire de la locale cible pour la page courante à l'ouverture du menu (visite partielle `only: ['translations']` paramétrée par la locale visée), de sorte que la bascule visuelle soit immédiate au clic ; le `POST locale.update` ne fait ensuite que persister, et la réponse est suivie d'un `router.reload({ only: ['locale', 'translations'], preserveState: true, preserveScroll: true })`. `preserveState` garantit que le composant de page **n'est pas remonté** : ni la souscription Echo, ni l'état de manche, ni le chronomètre client ne sont touchés. À défaut de préchargement, le sélecteur affiche un état d'attente pendant l'unique aller-retour — jamais une application optimiste d'un dictionnaire absent. `document.documentElement.lang` est mis à jour dans le même mouvement.

L'action serveur écrit `users.locale` si le joueur est connecté, re-signe le `player_token` s'il en existe un, met à jour `player.locale` pour chaque siège actif de ce joueur, repose le cookie, et répond par `back()`. **Elle ne touche aucun état de jeu** (règle 1 de `CLAUDE.md`) : aucune manche ne redémarre, aucun chrono ne se resynchronise, aucune tentative envoyée n'est invalidée, aucun score n'est recalculé.

**Ce qui reste figé jusqu'à la manche suivante**, parce que ce sont des paquets déjà composés et déjà envoyés :

| Élément | Pourquoi il ne bascule pas |
|---|---|
| QCM déjà poussé | Envoi ciblé composé à `T_N` (ou `T₁` en Facile) avec les titres résolus pour l'ancienne locale ; le recomposer exigerait de renvoyer les quatre propositions en cours de palier, donc de rouvrir une fenêtre de fuite. **Composé et matérialisé une seule fois ; tout renvoi (langue, resync, second onglet) rejoue les mêmes quatre chaînes.** |
| Révélation déjà affichée | Paquet de révélation déjà reçu ; il porte les titres de toutes les locales activées, mais l'écran est rendu et l'on ne rejoue pas une révélation. |
| E-mail déjà mis en file | La locale est capturée au moment de la mise en file, pas au moment de l'envoi. |
| Instants, durées, valeurs de palier de `round_tier` | Aucun contenu textuel : rien à traduire, rien à refaire. |

Tout le reste — libellés, chrono, statuts, liste des joueurs, classement intermédiaire, messages d'erreur ultérieurs — bascule immédiatement, parce que tout cela transite en données et se rend côté client.

---

## Dictionnaires serveur

```
lang/
  en/        auth.php pagination.php passwords.php validation.php   (php artisan lang:publish)
             common.php game.php room.php account.php legal.php mail.php
  fr/        auth.php pagination.php passwords.php validation.php   (traductions maintenues dans le dépôt)
             common.php game.php room.php account.php legal.php mail.php
             admin.php                                              (fr uniquement, voir plus bas)
  en.json    clés littérales émises par Fortify et le framework
  fr.json    leurs traductions
```

`legal.php` ne porte que l'**habillage** : libellés de pied de page, titres de navigation, date de mise à jour, bloc de contact, avertissement « Ces textes ne sont disponibles qu'en français ». Il est soumis à la symétrie FR/EN comme tout autre domaine joueur. Le **corps** des trois pages légales, lui, n'y est pas — voir plus bas.

**Découpage en domaines**, et il est normatif : `common` (navigation, boutons, états génériques), `game` (manche, palier, saisie, révélation, podium), `room` (création, réglages, lobby, presets), `account` (Fortify, profil, comptes liés, avatars), `legal` (mentions, CGU, confidentialité, attribution TMDB), `mail` (objets et corps), `admin` (back-office). Aucun domaine n'est un fourre-tout : le découpage sert directement au calibrage de la charge utile front et à la règle de couverture CI.

**`lang/*.json` est imposé par Fortify**, qui mélange clés de fichier (`auth.failed`) et chaînes anglaises littérales. Les deux appels `__()` existants (`'Profile updated.'`, `'Password updated.'`) sont exactement de cette famille et rejoindront `lang/*.json`. Décision ferme : **le code du projet n'utilise jamais une chaîne littérale comme clé.** Toute clé écrite pour TripleFrames est de la forme `domaine.chemin.clé`. `lang/en.json` et `lang/fr.json` existent uniquement pour couvrir ce que le framework et Fortify émettent, et cet ensemble est traité comme fermé et subi.

Les fichiers `fr` du framework (`auth`, `pagination`, `passwords`, `validation`) sont **maintenus dans le dépôt**, initialisés depuis le pack communautaire puis relus. Aucune dépendance de traduction n'est ajoutée à l'exécution : `lang/` est du contenu versionné, pas un paquet.

`lang/fr/validation.php` contient son bloc `attributes` rempli pour **chaque champ exposé au joueur** (`nickname`, `room_code`, `frames_per_round`, `rounds_count`…). Sans lui, un message affiche le nom de colonne anglais à un joueur francophone. Les règles de validation maison (bornes croisées de salon, format de pseudo, liste noire par langue) apportent leurs propres clés dans le même fichier.

---

## Dictionnaire front, `t()` et invalidation Inertia

**`t()` maison, aucune bibliothèque i18n.** Le besoin se limite à une recherche de clé, une interpolation `:placeholder` et un sélecteur de pluriel à deux formes ; une bibliothèque doublerait Laravel comme source de vérité et violerait le principe 11 de `00-overview.md`.

**Source de vérité unique : `lang/`.** Le front ne possède pas son propre dictionnaire. `HandleInertiaRequests::share()` expose deux props :

- `locale` : le code actif, plus la liste des locales activées avec leur libellé natif — le sélecteur de langue affiche toujours chaque langue **dans sa propre langue**, jamais traduite.
- `translations` : les domaines nécessaires à la page rendue, **`common` plus ceux que la page déclare**. Une page de jeu embarque `common` + `game` ; l'accueil `common` + `legal`. Le domaine `admin` n'est jamais envoyé à un écran joueur, et réciproquement. Envoyer le dictionnaire entier à chaque réponse serait du poids réseau pur sur un écran mobile en 4G, ce que le principe 6 de `00-overview.md` n'autorise pas.

**Typage, sous contrainte `lint.typeAware` + `denyWarnings: true`.** Un dictionnaire typé `Record<string, any>` casse la CI, et un accès non typé la casserait aussi. La commande `php artisan lang:types` génère `resources/js/types/translations.d.ts` : un type union `TranslationKey` de toutes les clés du jeu **`en`** (locale de référence pour l'existence des clés), et un type de charge utile par domaine. Le fichier est **committé, jamais édité à la main**, au même titre que les fichiers Wayfinder, et la CI le régénère puis vérifie l'absence de différence. La signature est :

`t<K extends TranslationKey>(key: K, replacements?: Record<string, string | number>): string`

Clé absente à l'exécution : la clé est renvoyée telle quelle et un `console.error` est émis en développement, jamais une chaîne vide ni un `undefined` rendu.

**Pluriels** : `tChoice(key, count)` réimplémente le sélecteur **de Laravel** (`|`, `{0}`, `[2,*]`), pas ICU. Raison : `ext-intl` est absent du serveur, donc `Intl.PluralRules` n'a pas d'équivalent côté PHP ; le serveur doit produire exactement la même phrase qu'un client pour les e-mails et les messages de validation. FR et EN étant tous deux à deux formes, la règle tient en dix lignes et elle est testée.

**Invalidation.** `HandleInertiaRequests::version()` inclut une empreinte des fichiers `lang/`, sinon le dictionnaire client reste périmé après un déploiement qui ne touche qu'une traduction : l'empreinte des assets Vite ne bouge pas, Inertia ne force aucun rechargement, et le joueur garde l'ancien texte jusqu'au vidage de son cache. La commande `php artisan lang:hash` écrit `bootstrap/cache/lang-version.php` et s'ajoute au script de déploiement à côté de `config:cache` ; en développement, le fichier étant absent, l'empreinte est calculée à la volée. **Cette empreinte ne dépend jamais de la locale active** : un changement de langue modifierait sinon `version()`, ce qui déclencherait un rechargement complet de page — inacceptable en pleine manche.

**Attribut `lang`.** `resources/views/app.blade.php` gère déjà `<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">`, rien à ajouter au rendu initial ; le changement de langue sans rechargement met à jour `document.documentElement.lang`. **Tout fragment rendu dans une autre langue que celle du joueur porte son propre attribut `lang`** — cas principal et non théorique : un titre de film obtenu par la chaîne de repli. Un joueur francophone à qui l'on affiche `The Two Towers` reçoit `<span lang="en">The Two Towers</span>`, sinon un lecteur d'écran le prononce en français. `dir` est émis depuis le registre des locales et vaut `ltr` partout en v1 ; les écritures droite-à-gauche sont hors périmètre, mais l'attribut existe déjà pour qu'elles ne soient pas une refonte.

---

## Contenus traduits stockés en base

Trois familles de contenu sont traduites en données, pas en fichiers, parce qu'elles changent sans déploiement. Les tables appartiennent à `10-catalogue-et-modele-de-donnees.md` ; voici les **exigences** que cette spec lui adresse.

| Contenu | Exigence | Règle de publication |
|---|---|---|
| `title_original` | Invariant, jamais réécrit, jamais copié depuis une traduction. Dernier maillon de la chaîne de repli. | Obligatoire. |
| `title_original_latin` | Colonne invariante de `movie`, **jamais un alias** (`10` § A4). Affichée **et** acceptée en réponse, inconditionnellement, sans filtre de locale activée. | Facultatif. |
| `movie_title` par `locale` | Au plus un titre par couple (film, locale). Une locale de catalogue quelconque est admise, pas seulement les locales activées. | **Facultatif** : un film se publie sans couverture complète, le repli le rend jouable. Le back-office affiche la couverture par langue et une file « titres manquants ». |
| `alias` par `locale` | Plusieurs alias par couple (film, locale). Sert **uniquement** à la validation, jamais à l'affichage. | Facultatif. Un alias FR n'est jamais proposé comme titre EN à la révélation, même s'il reste accepté en réponse. |
| Libellé de `theme` par `locale` | Un libellé par couple (thème, locale), éditable en back-office. | **Obligatoire dans chaque locale activée pour publier un thème.** Un thème sans libellé afficherait son identifiant technique au joueur ; et les thèmes se comptent en dizaines, quand les films se comptent en centaines — l'exigence est tenable là où elle ne l'est pas pour un titre. |

**Le repli est un affichage, jamais une écriture.** Aucun titre n'est recopié d'une langue vers une autre en base, à aucun moment, ni à l'import, ni à la curation, ni au tirage. Un titre manquant reste manquant : c'est ainsi que la file « titres manquants » reste vraie.

**Le corps des trois pages légales est rédigé en français seulement** (décision 4). Il vit en partiels de vue (`resources/views/legal/{page}.fr.blade.php`), **hors des dictionnaires**, et est rendu dans un conteneur portant `lang="fr"` quelle que soit la locale du joueur ; un visiteur en `en` voit l'avertissement de disponibilité en anglais et le corps en français correctement balisé. C'est le cas d'usage principal de la règle d'attribut `lang` sur fragment.

---

## Chaîne de repli d'affichage d'un titre

1. `movie_title` de la locale du joueur.
2. `movie_title` d'une autre locale activée, dans l'**ordre de repli déclaré par l'instance** (`en`, puis `fr`).
3. `title_original`.

Quand le titre retenu est en écriture non latine, l'affichage utilise la **translittération latine de `movie.title_original_latin`** si TMDB l'a fournie ; sinon le titre tel quel. **Jamais un alias** : un alias n'est jamais affiché, sans exception (`10` § A4). La **révélation affiche toujours le titre retenu et `title_original` s'il diffère**, plus l'année — qui est aussi le discriminant des homonymes et des remakes.

Le **rang atteint** dans cette chaîne est une donnée de premier plan : c'est lui qui rend le QCM dangereux.

---

## QCM : homogénéité linguistique et envoi ciblé obligatoire

Les quatre propositions sont composées **par joueur** et transmises en **envoi ciblé**, jamais en diffusion salon. Ce n'est pas une optimisation, c'est la seule façon de tenir la règle 3 de `CLAUDE.md` en environnement multilingue, et voici pourquoi.

Supposons une diffusion unique de quatre identifiants de film, chaque client résolvant lui-même les titres par sa chaîne de repli. Pour un joueur francophone, trois films ont un titre FR et le quatrième n'en a pas : son écran affiche trois titres français et un titre anglais. **Cette singularité est l'information.** Qu'elle désigne la bonne réponse ou qu'elle l'élimine, elle n'aurait jamais dû quitter le serveur, et aucun mélange d'ordre ne la masque.

D'où l'algorithme, arbitré ici et consommé par `70-validation-des-reponses.md` :

1. Pour le joueur `P` de locale `L`, on calcule le **rang de repli** atteint par le film cible : 1 (titre dans `L`), 2 (titre dans une autre locale activée), 3 (`title_original`).
2. Les trois leurres sont tirés **une seule fois par manche**, parmi les films dont le **profil de disponibilité de titre sur l'ensemble des locales activées est identique à celui du film cible** — pour chaque locale activée, le leurre a un titre dans cette locale si et seulement si le film cible en a un. Tous les joueurs du salon reçoivent donc **les mêmes quatre films** ; seules la langue de rendu des quatre chaînes et leur ordre diffèrent d'un joueur à l'autre. Cette règle donne l'homogénéité dans **toutes** les locales activées à la fois, là où un tirage au rang de repli du seul joueur ne la garantit que dans la sienne.
3. S'il n'existe pas trois films à ce profil, les quatre propositions basculent ensemble sur `title_original` **pour tout le salon**. On dégrade les quatre, jamais un seul.
4. Aucune traduction à la volée, jamais : un leurre est un titre réel d'un film réel du catalogue.
5. Le serveur envoie **quatre chaînes déjà résolues**, pas des identifiants : la résolution côté client rouvrirait exactement le trou que l'étape 2 vient de boucher.
6. L'ordre des quatre propositions est mélangé **par joueur**, avec une graine propre au couple (manche, joueur), pour que l'ordre lui-même ne soit pas un canal — ni entre deux écrans côte à côte, ni d'une manche à l'autre.
7. **Le QCM d'un couple (manche, joueur) est composé une seule fois et matérialisé.** Les trois leurres retenus et l'ordre des quatre propositions sont figés à la première composition — persistés avec la manche, ou dérivés d'une graine déterministe sur (manche, joueur) enregistrée au lancement au même titre que la graine de tirage des films. Tout renvoi ultérieur — resynchronisation après déconnexion, rechargement de page, reprise de siège par un second onglet, changement de langue — **rejoue exactement les mêmes quatre propositions**, jamais un nouveau tirage. Raison : deux tirages indépendants pour le même joueur ont le film cible pour seul élément commun garanti ; leur intersection donne la réponse sans aucune complicité extérieure, et un simple rechargement suffirait. Si la locale du joueur a changé entre la composition et le renvoi, les propositions repartent dans la **langue de composition**, jamais recomposées dans la nouvelle.

Conséquence : les quatre **films** proposés sont les mêmes pour tout le salon, seules leur langue de rendu et leur ordre changent. C'est délibéré : un tirage par joueur donnerait à deux joueurs qui comparent leurs écrans une intersection dont le seul élément garanti est le film cible — un oracle à un message.

**Règle générale dont le QCM est le cas limite** :

| Moment | Ce qui transite | Forme |
|---|---|---|
| Avant la révélation | Propositions QCM | Chaînes déjà résolues, **envoi ciblé par joueur**. Aucun message, **ciblé comme diffusé**, ne contient l'identifiant du film cible, un titre ou un alias hors des quatre chaînes elles-mêmes, ni **l'index, le drapeau, la position conventionnelle ou toute métadonnée par proposition** désignant la bonne réponse : la charge utile ciblée est un tableau de quatre chaînes homogènes, rien d'autre. Le client renvoie la chaîne choisie, le serveur seul juge. |
| À la révélation et après | Titre, titre original, année, récapitulatif, podium | Diffusion salon d'un **paquet de titres par locale activée** ; chaque client choisit le sien. Une seule diffusion, aucun coût par joueur, et plus rien à cacher. |
| À tout moment | Feed « a trouvé », classement, statuts, chrono | **Données** : identifiants de joueur, scores entiers, instants ISO-8601 UTC, durées en millisecondes. Jamais une phrase pré-formatée par le serveur. |

Un titre de film est une **donnée**, pas une chaîne pré-formatée : le servir résolu ne contredit pas la règle 4 de `CLAUDE.md`, qui interdit les phrases construites côté serveur (« Alice a trouvé en 4,2 s »), pas les valeurs.

---

## Acceptation multilingue des réponses

**Une bonne réponse est acceptée dans toutes les langues activées, quel que soit le joueur et quel que soit le salon.** « seigneur des anneaux 2 », « Le Seigneur des Anneaux : Les Deux Tours » et « The Two Towers » valident la même manche, pour un joueur en FR comme pour un joueur en EN.

Périmètre exact des chaînes acceptées pour un film :

- `title_original` et sa translittération latine quand elle existe ;
- tous les `movie_title` des **locales activées** ;
- tous les `alias` des **locales activées**.

**La locale du joueur n'entre dans aucune décision de validation.** Corollaire direct de la règle 1 : changer de langue en pleine manche ne peut pas rendre juste une réponse déjà refusée, ni l'inverse. La langue pilote l'affichage, jamais l'arbitrage.

Conséquence à porter à `70` : le **seuil de tolérance de distance d'édition se calibre sur le corpus multilingue complet**, pas sur un corpus par langue. Le titre EN d'un film peut se trouver à faible distance du titre FR d'un autre film publié, et c'est le genre de collision qu'un calibrage monolingue ne voit jamais.

---

## Erreurs, validation et messages à destinataire unique

Règle de partage, valable partout :

> Un message est **résolu côté serveur** quand il n'a qu'un destinataire et que la requête connaît sa locale. Il transite en **données** dès qu'il est diffusé à plusieurs.

Une réponse HTTP a toujours un destinataire unique, et la requête porte sa locale : messages de validation, erreurs 4xx et 5xx, `Inertia::flash('toast', ...)` sont donc rendus en clair dans la langue de la requête. Un événement Reverb, lui, part vers un salon entier : il ne contient que des données, ou il est ciblé.

S'ensuivent, sans exception : messages de rejet de pseudo traduits, **liste noire de pseudos par langue activée**, bornes croisées de réglages dont le message nomme le réglage fautif dans la langue de l'hôte, pages d'erreur traduites, et `alt` d'image **neutre et traduit** — la clé `game.frame.alt` vaut `Image :index sur :total de la manche en cours`, elle ne décrit jamais le contenu (principe 8 de `00-overview.md`, et anti-triche).

---

## E-mails

`App\Models\User` implémente `Illuminate\Contracts\Translation\HasLocalePreference` et retourne `users.locale`. C'est **obligatoire, pas facultatif** : les mails partent en file, et un job ne voit ni requête, ni cookie, ni `App::getLocale()` du moment de l'action. Sans ce contrat, un compte francophone reçoit ses e-mails Fortify en anglais.

| Destinataire | D'où vient la locale | Mécanisme |
|---|---|---|
| `User` existant | `users.locale`, via `preferredLocale()` | `Mail::to($user)` — Laravel fige la locale dans le job. |
| Adresse seule (vérification d'une adresse ajoutée, contact) | Locale de la requête qui déclenche l'envoi | `Mail::to($address)->locale($locale)` pour un `Mailable` ; voir ci-dessous pour une `Notification`. |
| **Message sortant vers un tiers hors du jeu** (accusé de réception d'une demande de retrait, notification de décision, réponse de signalement) | Locale **déclarée par le demandeur dans le formulaire public**, repli sur la langue de sa demande puis sur le repli d'instance. Elle est **stockée avec la demande** et n'est **jamais** lue dans `App::getLocale()`, que le middleware d'administration force à `fr`. | Ces gabarits existent en FR **et** EN et entrent dans la vérification de symétrie : c'est l'unique exception au « back-office en français seulement ». |
| Locale inconnue | — | Repli d'instance : `en`. |

**Les e-mails Fortify sont des `Notification`, pas des `Mailable`.** `NotificationSender::preferredLocale()` lit `$notification->locale`, puis `preferredLocale()` du notifiable — c'est ce second point que `HasLocalePreference` sert. `AnonymousNotifiable` n'expose ni `locale()` ni `preferredLocale()` : un envoi à une adresse seule s'écrit `Notification::route('mail', $address)->notify((new X(...))->locale($locale))`. `Mail::to($address)->locale($locale)` ne vaut que pour un vrai `Mailable`.

Objets et corps passent par `lang/{locale}/mail.php` et par les clés Fortify du framework, `lang/*.json` compris. Le listener `LocaleUpdated` décrit plus haut règle `CarbonImmutable` **dans le job**, sans quoi une date dans un e-mail français s'écrit en anglais. Un compte sans e-mail (Discord peut n'en retourner aucun) ne reçoit rien : la spec `40` en tire ses conséquences.

---

## Nombres, dates et durées, sans `ext-intl`

`ext-intl` est **absent** du serveur : `Number::format()` lève une `RuntimeException`, et cette absence fausse déjà `db:show`. Décisions :

1. **Le serveur ne met en forme ni nombre ni date destinés à un écran de jeu.** Les scores partent en entiers, les instants en ISO-8601 UTC, les durées en millisecondes. Le client formate avec `Intl.NumberFormat` et `Intl.DateTimeFormat`, disponibles dans tous les navigateurs cibles, dans la locale du joueur. C'est la même règle que « tout ce qui est partagé transite en données », vue sous l'angle du formatage.
2. **Là où aucun client ne rend le texte** — e-mails, back-office, export de données — un helper `App\Support\Format` s'appuie sur `number_format()` avec les séparateurs tirés du registre de locales (FR : espace insécable et virgule ; EN : virgule et point) et sur `CarbonImmutable::translatedFormat()`.
3. **`ext-intl` n'est pas ajouté en v1.** Si une évolution l'exige, `CLAUDE.md` impose d'ajouter la clé `extensions:` au workflow `.github/workflows/tests.yml` **dans le même commit** que la contrainte `composer.json`, faute de quoi la CI casse sur toutes les branches à la fois.

---

## Pas de préfixe de locale dans les URL

Aucune URL ne porte `/{locale}/`. Quatre raisons, dont la dernière est produit :

1. Fortify enregistre ses routes lui-même : elles échapperaient au groupe préfixé.
2. `.well-known/passkey-endpoints` et `storage/{path}` sont à chemin fixe par contrat.
3. Le préfixe réécrirait tous les helpers Wayfinder générés, que le principe 11 interdit de contourner à la main.
4. **Un lien de salon se partage entre amis de langues différentes.** `/fr/room/ABC42` envoyé par un hôte français à un ami anglophone impose le français ou déclenche une redirection : le code de salon doit désigner un salon, pas une langue.

La langue vit donc dans le cookie `locale`, la colonne `users.locale` et le `player_token`, et nulle part dans le chemin.

**Conséquences, le site étant public et indexé (décision 1)** : `Vary: Accept-Language` sur les seules pages dont le contenu varie réellement avec la locale — l'**accueil** —, jamais sur les pages légales, qui sont **mono-langue FR** (décision 4), portent `<html lang="fr">` quelle que soit la locale du visiteur et doivent rester cachables. Balise canonique pointant l'URL nue partout. **`hreflang` n'est déclaré que là où deux variantes d'une même page existent réellement — donc nulle part en v1**, puisqu'il n'y a pas d'URL alternative. Googlebot n'envoyant pas d'`Accept-Language`, **l'accueil est indexé dans le repli d'instance, en anglais** : c'est accepté et écrit ; les trois pages légales, elles, sont indexées en français. Tout le reste du site est `noindex`. Corollaire d'exploitation : **aucun cache HTTP de page complète** n'est posé devant l'application pour l'accueil, sans quoi la première langue servie serait servie à tous.

---

## Back-office en français seulement

Le back-office de curation est **en français uniquement** — la décision 9 sépare les rôles et confie la curation à un non-technicien francophone —, mais **100 % de ses textes passent par des clés** de traduction, dans le domaine `admin`. Aucune chaîne en dur, même là.

La règle est **appliquée, pas espérée** : un middleware sur le groupe de routes d'administration force `App::setLocale(Locale::French)` pour toute la durée de la requête, quelle que soit la préférence personnelle du curateur. Sans lui, un curateur dont le compte est en `en` verrait s'afficher des clés brutes, puisque `lang/en/admin.php` n'existe pas. C'est aussi ce qui garantit que le domaine `admin` n'est jamais expédié dans la charge utile d'un écran joueur.

**Exception normative — messages sortants vers un tiers extérieur.** Accusé de réception d'une demande de retrait, notification de décision et réponse à un signalement existent en **FR et EN**, dans la langue déclarée par le demandeur avec repli sur la langue de sa demande (décision 1 : le site est public et indexé, les demandes arrivent en anglais). Ces gabarits **ne vivent pas dans le domaine `admin`** mais dans `mail`, domaine joueur, et **entrent donc dans la couverture de clés vérifiée en CI**. Le middleware qui force `Locale::French` ne concerne que le rendu des écrans d'administration : tout envoi de ce type passe par la locale stockée avec la demande, jamais par la locale de la requête du curateur.

Traduire le back-office plus tard ne demandera qu'un fichier `lang/en/admin.php` et le retrait de ce middleware.

---

## Couverture des clés, vérifiée en CI

Trois vérifications, écrites en Pest, donc exécutées par `composer test` et par `composer ci:check`. Elles portent sur le **domaine joueur seulement** — **`mail` compris, gabarits de réponse aux demandes de retrait inclus** — ; seul `admin` en est exclu, français par construction.

| Vérification | Ce qu'elle refuse |
|---|---|
| **Symétrie des clés** | Une clé présente dans un fichier `lang/en/*.php` (hors `admin`, et hors le corps des pages légales, qui n'est pas un dictionnaire) et absente du `lang/fr/*.php` correspondant, ou l'inverse. Comparaison récursive sur les clés aplaties. Même règle entre `lang/en.json` et `lang/fr.json`. |
| **Symétrie des paramètres** | Deux traductions d'une même clé qui n'emploient pas exactement le même jeu de `:placeholder`. Un `:count` oublié en FR ne se voit pas à la relecture et produit une phrase fausse en production. |
| **Clés réellement appelées** | Un littéral passé à `t()` ou `tChoice()` dans `resources/js/**` qui n'existe dans aucun dictionnaire. C'est possible parce que `t()` est maison et que les clés sont des littéraux : un balayage par expression régulière suffit. |

S'y ajoute un contrôle de dérive : la CI relance `php artisan lang:types` et échoue si `resources/js/types/translations.d.ts` diffère du fichier committé.

La définition de « terminé » du projet inclut déjà « textes FR et EN complets » ; ces tests en sont la forme exécutable.

---

## Ajouter une troisième langue

Procédure complète, à dérouler telle quelle. Rien d'autre n'est requis : c'est la démonstration que l'architecture accueille une langue « sans refonte ».

1. Ajouter le cas à l'enum `App\Enums\Locale` et sa position dans l'ordre de repli d'affichage.
2. Créer `lang/es/` et `lang/es.json`, publier les fichiers du framework, traduire les domaines joueur (`admin` reste français).
3. **Compléter le libellé de chaque thème publié dans la nouvelle locale** — c'est la seule tâche bloquante, et elle se fait en back-office, sans déploiement.
4. Fournir la liste noire de pseudos de cette langue.
5. Relancer les trois vérifications de couverture et régénérer `translations.d.ts`.
6. **Revalider les seuils de distance d'édition de `70` sur le corpus élargi** : accepter une langue de plus élargit la surface d'acceptation et peut créer des collisions entre un titre espagnol et un titre français déjà publié.
7. Vérifier le corpus de leurres : si trop peu de films portent un titre dans la nouvelle locale, la règle d'homogénéité fera basculer les QCM sur `title_original` — comportement correct, mais à connaître avant d'annoncer la langue.

Ce qui **ne bouge pas** : aucune migration (titres, alias et libellés de thèmes sont des lignes, pas des colonnes), aucune route, aucune régénération Wayfinder, aucune URL, aucun écran.

Les langues au-delà de FR et EN restent **hors périmètre v1**, ainsi que la traduction des contenus de curation autres que titres et alias, et les écritures droite-à-gauche.

---

## Ce que cette spec ne décide pas

| Sujet | Spec propriétaire |
|---|---|
| Les tables `movie_title`, `alias`, libellés de `theme`, `users.locale`, `player.locale`, et la forme du `player_token` | `10-catalogue-et-modele-de-donnees.md` — seul propriétaire du schéma. |
| L'écran de couverture des titres par langue, la file « titres manquants », l'édition des libellés de thèmes et des alias | `20-back-office-curation.md`. |
| Le tirage des films et des variantes, dont dépend le corpus de leurres disponible | `30-themes-vivier-et-tirage-des-variantes.md`. |
| Le filtrage des pseudos par langue, `HasLocalePreference` côté compte, la langue portée par un invité qui se connecte en cours de partie | `40-comptes-auth-sociale-et-avatars.md`. |
| Les libellés et bornes de chaque réglage, et le texte des messages de bornes croisées | `50-salon-reglages-presets-et-lobby.md`. |
| Le contrat d'événements, les canaux, et la mécanique de l'envoi ciblé que cette spec rend obligatoire | `60-moteur-de-partie-temps-reel-et-mode-solo.md`. |
| La normalisation des réponses, les seuils de distance d'édition, le tirage des leurres et la réponse partielle sur une saga | `70-validation-des-reponses.md`. |
| Le contenu localisé du podium et du récapitulatif de fin de partie | `80-scoring-podium-et-fin-de-partie.md`. |
| Le sélecteur de langue comme écran, ses états, son motif ARIA et sa place dans la navigation | `90-ecrans-etats-et-structure.md`. |
| La place exacte des trois vérifications dans le pipeline et les seuils de la matrice de tests | `100-qualite-tests-et-ci.md`. |
