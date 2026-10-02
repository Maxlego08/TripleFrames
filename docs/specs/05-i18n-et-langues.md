# Internationalisation et langues

Ce document est le **propriétaire unique de la règle de langue** de TripleFrames. Il décide quelles locales existent, comment celle d'un joueur est négociée, persistée et changée, où vivent les dictionnaires, comment un titre de film se choisit, pourquoi un QCM ne peut pas être diffusé en clair au salon, et comment une troisième langue s'ajoute sans refonte. Il est écrit **avant le premier écran** parce qu'aucun texte ne doit naître en dur (principe 7, règle 4 de `CLAUDE.md`). Convention de renvoi, valable dans tout le document : « règle N » désigne `CLAUDE.md` §7, « principe N » désigne `00-overview.md` § Principes directeurs — les deux numérotations sont différentes et ne se recoupent pas. « Décision N » désigne une des dix-neuf décisions du 22/09 ; « Dn du 23/09 » une des décisions du porteur du 23/09, consignées dans `questions-ouvertes.md`. Un passage suivi de « — amendé le 23/09 » a été réécrit ce jour-là pour appliquer ces décisions et les specs `20` à `100` qui en découlent — amendé le 23/09.

Ce document ne possède **aucune table** : quand une colonne ou une ligne est nécessaire, l'exigence est formulée ici et le schéma est arbitré par `10-catalogue-et-modele-de-donnees.md`. Il ne possède ni la normalisation des réponses (`70`), ni les écrans (`90`), ni la matrice de tests (`100`) — seulement la règle de langue que ces specs consomment. Il possède en revanche le **contrat C15, domaines et clés de traduction** : la mécanique, la liste close des domaines et la propriété des préfixes de clés vivent ici ; `90` § 6 en rédige par délégation la déclaration des domaines page par page et les clés figées de ses coquilles — amendé le 23/09.

> **État réel du dépôt au moment d'écrire** : `APP_LOCALE=en` et `APP_FALLBACK_LOCALE=en`, **aucun dossier `lang/`**, **deux** appels `__()` dans `app/` (`ProfileController`, `SecurityController`, tous deux avec une chaîne littérale en clé), 100 % du JSX en anglais en dur, aucune brique i18n installée. Tout ce qui suit est à construire ; rien n'est à défaire.
>
> **Au 23/09**, la mécanique de cette spec existe dans le dépôt : `App\Enums\Locale`, `SetLocale`, `LocaleCookie`, `LocaleController`, l'interface `PlayerTokenLocale` (liée à `NullPlayerTokenLocale` jusqu'au lot L40-2 de `40`), `TranslationDomains`, `SelectTranslationDomains` (alias `translations`), `ForceAdminLocale` (alias `admin.locale`), `Translations::flatten()`, `VaryOnLanguage`, les commandes `lang:types` et `lang:hash`, `resources/js/lib/i18n.ts`, `use-translations.ts`, `translations.d.ts`, les dictionnaires `lang/{en,fr}` et les suites `tests/Feature/I18n/` (`LangVersionTest`, `SetLocaleTest`, `TranslationCoverageTest`) — amendé le 23/09.

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
| 3 | Revendication `locale` du `player_token` | Invité dont le cookie `locale` est **absent ou expiré alors que le jeton est présent** : le jeton restaure sa langue. Un autre navigateur n'a pas le jeton non plus : ce niveau ne le couvre pas. Lecture par `PlayerTokenLocale`, liée à `CookiePlayerTokenLocale` (`40` § 4.1) — amendé le 23/09. |
| 4 | Négociation `Accept-Language` | Première visite. Restreinte aux locales activées, quality values respectées, `fr-CA`/`fr-BE`/`fr-CH` ramenés à `fr`. |
| 5 | Repli d'instance `en` | En-tête absent, illisible, ou ne proposant aucune locale activée. |

La résolution vit dans un middleware `App\Http\Middleware\SetLocale`, **calqué sur `HandleAppearance`** et ajouté en tête de la liste `$middleware->web(append: [...])`, donc **avant `HandleInertiaRequests`** : les props partagées doivent déjà connaître la locale quand elles sont construites. Il appelle `App::setLocale()` et, dans le seul cas de la négociation (ligne 4), met le cookie `locale` en file ; il ne règle pas `Carbon` (voir plus bas) — amendé le 23/09.

**Rang dans la liste de priorité.** `bootstrap/app.php` inscrit aussi `$middleware->prependToPriorityList(ThrottleRequests::class, SetLocale::class)`. Sans cette ligne, Laravel trierait `ThrottleRequests`, présent dans la liste de priorité du noyau, avant `SetLocale`, qui n'y figure pas : le corps d'un 429 construit à l'intérieur d'un limiteur nommé — `game.answer.too_fast` de `70`, limiteurs `game-read`, `game-write` et `frame-serve` de `60` — partirait dans la locale de repli. `SetLocale` ne dépend d'aucune liaison de route (il lit l'utilisateur, le cookie `locale` et la revendication du jeton) : la locale est donc posée avant tout limiteur nommé. `EnsureActiveSeat` de `60` s'inscrit après lui ; la pile réelle devient `SetLocale`, `EnsureActiveSeat`, `ThrottleRequests`, `SubstituteBindings` (`70` § 8, `60` § 10.2). Exigence de `70` § 8, non consolidée, signalée au porteur ; si aucun lot antérieur ne l'a posée, L70-14 la pose — amendé le 23/09. **Posée par L60-4** (`60`), avec celle d'`EnsureActiveSeat` juste après ; L70-14 l'a trouvée en place. Elle vaut pour toutes les routes du groupe `web`, back-office compris : un 429 et une 404 de liaison sortent désormais dans la langue résolue, et la page d'erreur n'a plus à résoudre la locale que pour une URL inconnue, le jeton CSRF et le mode maintenance (`90` § 4.8) — amendé le 28/09 (E85-3).

**Résolution sans effet de bord.** La chaîne de résolution est exposée par `SetLocale::resolve(Request $request): Locale` [nouveau], que `handle()` appelle en gardant seul la pose du cookie de négociation. Le rendu des pages d'erreur la réemploie, parce que les erreurs les plus fréquentes naissent avant que `SetLocale` ne s'exécute (`90` § 4.8) — amendé le 23/09.

`Carbon` est recâblé **une seule fois**, par un listener sur `Illuminate\Foundation\Events\LocaleUpdated` enregistré dans `AppServiceProvider::boot()` (le dépôt n'a pas d'`EventServiceProvider`), qui exécute `CarbonImmutable::setLocale()`. Le middleware ne le fait pas lui-même : la même bascule doit s'appliquer dans un **job de mail en file**, où aucun middleware HTTP ne tourne. `Date::use(CarbonImmutable::class)` étant actif, c'est bien `CarbonImmutable` que l'on règle.

Quand la locale a été obtenue par négociation (ligne 4), la réponse **repose le cookie** : la négociation est collante, un francophone ne rejoue pas la détection à chaque visite. Seules les routes servies sans session ni `Set-Cookie` — `/f/{serveToken}` et `clock.show` de `60` — ne l'émettent jamais : leur pile retire `AddQueuedCookiesToResponse` (`60` § 7.3) — amendé le 23/09.

### Le cookie `locale`

Nom `locale`, durée un an, `path=/`, `SameSite=Lax`, `Secure` en production, **ajouté aux exceptions de chiffrement** dans `bootstrap/app.php` : `$middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale'])`. Raison, identique à celle d'`appearance` : c'est une préférence publique, non sensible, que le front lit et écrit directement pour appliquer la langue sans attendre un aller-retour — un cookie chiffré serait illisible côté client. Il n'est jamais une source d'autorité : sa valeur est validée par l'enum avant usage, et `users.locale` le supplante toujours.

### Persistance

| Nature de joueur | Support | Exigence adressée à la spec `10` |
|---|---|---|
| Compte | Colonne `locale` sur `users` | Toute lecture de la langue d'un compte renvoie une **locale activée**, jamais `null` ni une valeur inconnue, y compris pour un compte créé avant l'ajout d'une locale. `10` choisit la forme — colonne non nullable avec défaut, ou nullable résolue à la lecture. |
| Invité | Revendication `locale` du `player_token`, cookie `HttpOnly` chiffré dont la forme, le transport et la re-signature appartiennent à `40` [J1] (§ 3) | `10` ne porte que `player.player_token_hash`, SHA-256 du `tid` et jamais de la valeur chiffrée du cookie (E10-33). Le jeton lui-même — identifiant opaque, dernier avatar prédéfini, langue réalignée à chaque prise de siège, jamais le siège ni le pseudo, **le siège se retrouvant par le hash** — appartient à `40` [J1] (§ 3.3, § 3.6) — amendé le 23/09. |
| Joueur d'un salon | Colonne `locale` sur `player` | **Son existence est obligatoire**, et sa valeur doit être lisible **hors de toute requête HTTP** : une diffusion Reverb n'a ni requête ni cookie, et le serveur doit connaître la langue de chaque joueur pour composer un envoi ciblé au moment d'émettre, pas au moment de répondre. `10` choisit la forme. |

Changer de langue **re-signe** le `player_token` sans jamais changer son identifiant opaque, donc sans changer le siège que retrouve son hash : la règle « un `player_token` = un siège par salon » reste intacte, seul le contenu de la revendication `locale` change (`40` § 3.5, § 4.2) — amendé le 23/09.

### Changement de langue

Un seul geste : `POST locale.update`, appelé par le sélecteur. Le sélecteur **précharge** le dictionnaire de la locale cible pour la page courante à l'ouverture du menu (visite partielle `only: ['translations']` paramétrée par la locale visée), de sorte que la bascule visuelle soit immédiate au clic ; le `POST locale.update` ne fait ensuite que persister, et la réponse est suivie d'un `router.reload({ only: ['locale', 'translations'], preserveState: true, preserveScroll: true })`. `preserveState` garantit que le composant de page **n'est pas remonté** : ni la souscription Echo, ni l'état de manche, ni le chronomètre client ne sont touchés. À défaut de préchargement, le sélecteur affiche un état d'attente pendant l'unique aller-retour — jamais une application optimiste d'un dictionnaire absent. `document.documentElement.lang` est mis à jour dans le même mouvement.

L'action serveur écrit `users.locale` si le joueur est connecté, re-signe le `player_token` s'il en existe un — sans jeton, elle n'en frappe aucun —, met à jour `player.locale` pour chaque siège tenu par ce jeton, repose le cookie, et répond par `back()` (`40` § 4.2) — amendé le 23/09. **Elle ne touche aucun état de jeu** (règle 1 de `CLAUDE.md`) : aucune manche ne redémarre, aucun chrono ne se resynchronise, aucune tentative envoyée n'est invalidée, aucun score n'est recalculé.

**Ce qui reste figé jusqu'à la manche suivante**, parce que ce sont des paquets déjà composés et déjà envoyés :

| Élément | Pourquoi il ne bascule pas |
|---|---|
| QCM déjà poussé | Envoi ciblé composé à `T_N` (ou `T₁` en Facile) avec les titres résolus pour l'ancienne locale ; le recomposer exigerait de renvoyer les quatre propositions en cours de palier, donc de rouvrir une fenêtre de fuite. **Composé et matérialisé une seule fois ; tout renvoi (langue, resync, second onglet) rejoue les mêmes quatre chaînes**, dans leur langue de composition (`round_player.choices_locale`) et avec le même attribut `lang` — amendé le 23/09. |
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

`legal.php` ne porte que l'**habillage** : libellés de pied de page, titres de navigation, date de mise à jour, bloc de contact, avertissement « Ces textes ne sont disponibles qu'en français ». Il est soumis à la symétrie FR/EN comme tout autre domaine joueur. Le **corps** des trois pages légales et de la page « signaler un contenu », lui, n'y est pas — voir plus bas — amendé le 23/09.

**Découpage en domaines**, et il est normatif : `common` (navigation, boutons, états génériques, erreurs HTTP, bandeaux, apparence, avatars — textes alternatifs et libellés des prédéfinis, `common.avatar.*` : un avatar s'affiche au lobby, en jeu, au podium et en compte, et `common` est le seul domaine joint partout), `game` (manche, palier, saisie, révélation, podium), `room` (création, réglages, lobby, presets), `account` (Fortify, profil, comptes liés, écrans de choix d'avatar du compte), `legal` (mentions, CGU, confidentialité, attribution TMDB), `mail` (objets et corps), `admin` (back-office, dont la grille d'exclusion `admin.exclusion_grid.*` et les libellés du journal `admin.enum.admin_action.*`). **Liste close de sept domaines**, tenue par `TranslationDomains::KNOWN` [existant], dont `need()` lève sur un domaine inconnu : ni `avatar`, ni `curation`, ni `deploy`. Aucun domaine n'est un fourre-tout : le découpage sert directement au calibrage de la charge utile front et à la règle de couverture CI — amendé le 23/09.

**`lang/*.json` est imposé par Fortify**, qui mélange clés de fichier (`auth.failed`) et chaînes anglaises littérales. Les deux appels `__()` existants (`'Profile updated.'`, `'Password updated.'`) sont exactement de cette famille et rejoindront `lang/*.json`. Décision ferme : **le code du projet n'utilise jamais une chaîne littérale comme clé.** Toute clé écrite pour TripleFrames est de la forme `domaine.chemin.clé`. `lang/en.json` et `lang/fr.json` existent uniquement pour couvrir ce que le framework et Fortify émettent, et cet ensemble est traité comme fermé et subi.

Les fichiers `fr` du framework (`auth`, `pagination`, `passwords`, `validation`) sont **maintenus dans le dépôt**, initialisés depuis le pack communautaire puis relus. Aucune dépendance de traduction n'est ajoutée à l'exécution : `lang/` est du contenu versionné, pas un paquet.

`lang/fr/validation.php` contient son bloc `attributes` rempli pour **chaque champ posté par un écran joueur, sous le nom exact du champ posté** : les clés camelCase de `RoomSettings::FIELDS`, plus `roundDuration` (clé d'entrée seule de l'onglet Simple) et `themeKeys` — `roundsCount`, `framesPerRound`… —, puis `nickname`, `avatar`, `answer`, `choice` et `preset`. Sans lui, un message affiche le nom brut du champ à un joueur francophone. **Aucun libellé snake_case sans consommateur** : `validation.attributes.{frames_per_round,rounds_count,room_code}` sont retirés au J1 (`50` § 20.4 : le code de salon voyage dans l'URL, jamais dans un champ posté). Les règles de validation maison apportent leurs propres clés dans le même fichier : `validation.room_settings.*` (bornes croisées de salon, `50`) et `validation.nickname.*` (les sept messages de la règle de pseudo, format et liste noire compris, `40` § 5.9). Rédacteur de chaque famille : § Propriété des préfixes de clés — amendé le 23/09.

---

## Dictionnaire front, `t()` et invalidation Inertia

**`t()` maison, aucune bibliothèque i18n.** Le besoin se limite à une recherche de clé, une interpolation `:placeholder` et un sélecteur de pluriel à deux formes ; une bibliothèque doublerait Laravel comme source de vérité et violerait le principe 11 de `00-overview.md`.

**Source de vérité unique : `lang/`.** Le front ne possède pas son propre dictionnaire. `HandleInertiaRequests::share()` expose trois props [existantes] — amendé le 23/09 :

- `locale` : le code actif ; `locales` : la liste des locales activées, `{value, label, bcp47, dir}`, avec leur libellé natif — le sélecteur de langue affiche toujours chaque langue **dans sa propre langue**, jamais traduite — amendé le 23/09.
- `translations` : les domaines nécessaires à la page rendue, **`common` plus ceux que la route déclare** par le middleware `SelectTranslationDomains` [existant] (alias `translations`, par exemple `->middleware('translations:game,room,legal')`). **Toute route qui rend une page Inertia joueur déclare `legal`**, parce que le pied de page est présent sur tous les écrans : une page de jeu embarque `common`, `game`, `room` et `legal` ; l'accueil et les pages légales, `common` et `legal` ; une page d'entrée `room/*`, `common`, `room` et `legal` ; une page de compte, `common`, `account` et `legal` ; la page d'erreur joueur, `common` et `legal`. Le back-office reçoit **`admin` seul** — `TranslationDomains::selected()` rend `['admin']` dès que `admin` est demandé —, avec son propre pied de page sur `admin.footer.*`, jamais `legal`. Le domaine `admin` n'est donc jamais envoyé à un écran joueur, et réciproquement. La table de déclaration page par page appartient à `90` § 6.3, sa preuve à `TranslationDomainDeclarationTest` (`90`) — amendé le 23/09. Envoyer le dictionnaire entier à chaque réponse serait du poids réseau pur sur un écran mobile en 4G, ce que le principe 6 de `00-overview.md` n'autorise pas.

**Typage, sous contrainte `lint.typeAware` + `denyWarnings: true`.** Un dictionnaire typé `Record<string, any>` casse la CI, et un accès non typé la casserait aussi. La commande `php artisan lang:types` génère `resources/js/types/translations.d.ts` : un type union `TranslationKey` de toutes les clés du jeu **`en`** (locale de référence pour l'existence des clés), et un type de charge utile par domaine. Le fichier est **committé, jamais édité à la main**, au même titre que les fichiers Wayfinder, et la CI le régénère puis vérifie l'absence de différence (`lang:types --check`) — amendé le 23/09. La signature est :

`t<K extends TranslationKey>(key: K, replacements?: Record<string, string | number>): string`

Clé absente à l'exécution : la clé est renvoyée telle quelle et un `console.error` est émis en développement, jamais une chaîne vide ni un `undefined` rendu.

**Pluriels** : `tChoice(key, count)` réimplémente le sélecteur **de Laravel** (`|`, `{0}`, `[2,*]`), pas ICU. Raison : `ext-intl` est absent du serveur, donc `Intl.PluralRules` n'a pas d'équivalent côté PHP ; le serveur doit produire exactement la même phrase qu'un client pour les e-mails et les messages de validation. FR et EN étant tous deux à deux formes, la règle tient en dix lignes et elle est testée.

**Parité d'interpolation avec `Translator::choice`.** La signature complète est `tChoice(key, count, replacements?)`. Comme côté serveur, un `count` fourni dans les remplacements est **conservé**, et `count` n'est injecté que s'il est absent : c'est ce qui permet de passer par `:count` un nombre déjà formaté par `Intl.NumberFormat` (`{ count: fmt(n) }`, `80` § 15.1). `translateChoice` de `resources/js/lib/i18n.ts` est aligné depuis L100-3 (correctif I-10) : il construit `{ count, ...replacements }` — un `count` fourni est conservé, `count` n'est injecté que s'il manque, comme `isset($replace['count'])` de `Translator::choice` — et la forme plurielle se choisit toujours sur le nombre brut. L'ancien écart (`{ ...replacements, count }`, qui écrasait un `count` fourni) est résolu. La parité est tenue par trois tests de `tests/Frontend/i18n/translate-choice.test.ts` (fichier créé par L100-3), dont « conserve un count fourni, comme Translator::choice ». Exigence de `80` § 1.4, non consolidée, signalée au porteur — amendé le 23/09 ; amendé le 25/09 (E8-2).

**Invalidation.** `HandleInertiaRequests::version()` inclut une empreinte des fichiers `lang/`, sinon le dictionnaire client reste périmé après un déploiement qui ne touche qu'une traduction : l'empreinte des assets Vite ne bouge pas, Inertia ne force aucun rechargement, et le joueur garde l'ancien texte jusqu'au vidage de son cache. La commande `php artisan lang:hash` écrit `bootstrap/cache/lang-version.php` et est une étape du hook de déploiement, jouée après `artisan optimize` ; l'ordre complet du hook appartient à `100` § 11.5 — amendé le 23/09. En développement, le fichier étant absent, l'empreinte est calculée à la volée. **Cette empreinte ne dépend jamais de la locale active** : un changement de langue modifierait sinon `version()`, ce qui déclencherait un rechargement complet de page — inacceptable en pleine manche.

**Attribut `lang`.** `resources/views/app.blade.php` gère déjà `<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">`, rien à ajouter au rendu initial ; le changement de langue sans rechargement met à jour `document.documentElement.lang`. **Tout fragment rendu dans une autre langue que celle du joueur porte son propre attribut `lang`** — cas principal et non théorique : un titre de film obtenu par la chaîne de repli. Un joueur francophone à qui l'on affiche `The Two Towers` reçoit `<span lang="en">The Two Towers</span>`, sinon un lecteur d'écran le prononce en français. `dir` est émis depuis le registre des locales et vaut `ltr` partout en v1 ; les écritures droite-à-gauche sont hors périmètre, mais l'attribut existe déjà pour qu'elles ne soient pas une refonte.

**QCM : un seul `lang` pour les quatre chaînes.** Les quatre propositions portent **un attribut `lang` unique**, posé sur leur conteneur (`choice-grid.tsx`) : la **locale effective atteinte** à la composition (`round_choice_set.rendered_locale`), commune aux quatre par construction (§ QCM, étape 2), jamais un attribut par proposition. Il est absent quand les chaînes sortent de `title_original` (`70` § 10.5, § 10.8). **Révélation et récapitulatif** : chaque titre du paquet porte le `lang` de la locale atteinte, et le titre original celui de la langue originale du film, suffixé `-Latn` quand la translittération est rendue (`60` § 9.5, § 11.5) — amendé le 23/09.

---

## Propriété des préfixes de clés

Contrat C15, amendement A-50. Chaque préfixe a **un seul rédacteur de texte**, pour qu'aucune clé ne soit écrite deux fois par deux specs parallèles. Ce tableau est la source unique ; `90` § 6.4 y renvoie. Le texte de chaque clé, et les sous-clés sous un préfixe possédé, sont libres pour son rédacteur, hors les formes figées plus bas ; toute clé suit la symétrie FR/EN et la couverture du § Couverture des clés — amendé le 23/09.

| Préfixe | Rédacteur du texte | Jalon |
|---|---|---|
| `common.nav.*`, `common.action.*`, `common.state.*`, `common.language.*`, `common.appearance.*`, `common.error.*`, `common.maintenance.*`, `common.connection.*`, `common.home.*` | `90` | J1 |
| `common.avatar.*` | `40` ; forme des clés figée ci-dessous | J1 |
| `common.player.*` (pseudo masqué) | `90`, sur la règle de `40` | J2 |
| `common.closure.*` (page de fermeture) | `90` | J2 |
| `game.frame.*`, `game.a11y.*`, `game.help.*` | `90` ; texte de `game.help.prefix` par `70`, famille `game.help.scoring.*` par `80` | J1 |
| `game.round.*` (chrono, palier, statuts ; `game.round.lone_player`, affiché quand un seul siège est connecté en multijoueur, `60` § 9.3) | `60`, sauf `game.round.tier_value` : forme figée ci-dessous (D29 du 23/09), texte par `80` | J1 |
| `game.seat.*` (second onglet, expulsion), `game.pause.*`, `game.host.*`, `game.errors.*` | `60` | J1 |
| `game.reveal.*`, `game.solo.*` | `60` | J1 |
| `game.answer.*` (saisie, refus neutre, tentatives) | `70` | J1 |
| `game.choices.*` (QCM) | `70` | J1 |
| `game.leaderboard.*`, `game.podium.*`, `game.recap.*`, `game.score.*` | `80` | J1 |
| `room.*` (dont `room.presets.*` [existant], `room.settings.*`, `room.warnings.*`, `room.pool.*`, `room.refusal.*`, `room.join.kicked`), sauf `room.solo.*` | `50` | J1 |
| `room.solo.*` (page d'entrée du solo `room/solo`) | `60` | J1 |
| `account.*` | `40` | J2 (écrans du starter existants) |
| `legal.*` | `90` | J1 |
| `mail.*` | `20` (retrait), `40` (compte) | J2 |
| `validation.room_settings.*` [existant], `validation.attributes.<champ de réglage>` | `50` | J1 |
| `validation.nickname.*`, `validation.attributes.nickname`, `validation.attributes.avatar` | `40` | J1 |
| `validation.attributes.answer`, `validation.attributes.choice` | `70` | J1 |
| `validation.attributes.preset` | `60` | J1 |
| `admin.*` | `20` | J1 |
| `admin.exclusion_grid.*`, `admin.footer.*` | `20` ; forme figée ci-dessous | J1 |
| `admin.console.first_admin.*` [existant] | `20` | J1 |
| `admin.console.deploy.*` (dont `admin.console.deploy.phase.*`), `admin.console.backup.*`, `admin.console.reproject.*`, `admin.console.purge.*`, `admin.console.loadtest.*` | `100` | J1 |

Trois lignes viennent des specs écrites le 23/09, non consolidées et signalées au porteur pour être reportées dans la feuille de contrats : `room.solo.*` et `validation.attributes.preset` (`60` § 22 bis, écarts (l) et (o)), et les familles `admin.console.*` de `100` au-delà de `deploy.*` (`100` § 11.3). La ligne `game.round.*` suit `60` § 9.3 : « un seul siège connecté », et non « deux joueurs connectés » (`90`, point resté ouvert n° 11) — amendé le 23/09. Les sous-clés que les lots de `100` ont ajoutées au-delà de la liste de `100` § 11.3 — `admin.console.backup.snapshot_driver` (`:driver`, L100-6) et `admin.console.purge.{queued, already_queued, failed}` (`failed` avec `:scopes`, L100-8) — vivent sous des familles déjà déclarées ici : elles n'appellent aucune ligne de plus, seule la liste de `100` § 11.3 les recense — amendé le 25/09 (E20-3, E24-3). De même `admin.console.deploy.invalid_minutes` (`:option`, L100-5), refus d'une durée de drainage qui n'est pas un entier de minutes, sous `admin.console.deploy.*` — amendé le 28/09 (E97-3).

**Formes figées par cette spec** — le texte reste au rédacteur, la forme et les placeholders sont contractuels — amendé le 23/09 :

| Clé | Forme et placeholders | Jalon |
|---|---|---|
| `common.avatar.alt.preset`, `common.avatar.alt.provider`, `common.avatar.alt.initials` | Aucun placeholder ; ce sont les constantes `AvatarRef::ALT_KEY_*`. À côté d'un pseudo, l'image est décorative (`alt=""`, règle I5.9 de `40`). | J1 |
| `common.avatar.preset.{presetKey}`, `presetKey` ∈ `AvatarPresetCatalog::keys()`, soit `preset-01` … `preset-24` (`PlatformLimits::avatarPresets()`) | Aucun placeholder ; une clé FR et une clé EN par prédéfini. | J1 |
| `game.round.tier_value` (D29 du 23/09) | Pluriel : `tChoice('game.round.tier_value', points, { points: fmt(points) })`, `fmt` = `Intl.NumberFormat(locale)` ; `:points` est la valeur **entière** du palier courant, sans bonus, calculée côté client (`90` § 7.3, `80` § 14). | J1 |
| `admin.exclusion_grid.v{n}.{slug}.label`, `admin.exclusion_grid.v{n}.{slug}.help` | Aucun placeholder ; neuf slugs en v1 ; clés rendues par `ExclusionGrid::labelKey()` et `helpKey()`. Le suffixe `.label` s'écarte de la forme `….{slug}` retenue par la résolution de la contradiction n° 6 de la pré-analyse, parce qu'une clé de tableau de langue PHP ne peut pas être à la fois feuille et nœud (R-47, écart de forme signalé au porteur). | J1 |
| `admin.footer.{label,notice,terms,privacy,tmdb_attribution,tmdb_logo_alt}` | Aucun placeholder ; français seul, comme tout le domaine `admin`. | J1 |

Les autres clés figées par le contrat — erreurs HTTP, maintenance, connexion, langue, annonces `game.a11y.*`, aide, pied de page `legal.*` —, les renommages de la fusion des contrats et les clés abandonnées sont tenus par `90` § 6.5 et § 6.6 — amendé le 23/09.

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

**Le repli est un affichage, jamais une écriture.** Aucun titre n'est recopié d'une langue vers une autre en base, à aucun moment, ni à l'import, ni à la curation, ni au tirage. Un titre manquant reste manquant : c'est ainsi que la file « titres manquants » reste vraie. **Exception unique, le titre de la langue originale** (D52 du 02/10) : quand la langue originale du film est une locale activée et qu'aucun `movie_title` n'existe pour elle, l'import écrit `title_original` comme titre de cette locale (`10` § 3.4) — ce n'est pas une recopie entre langues, et c'est ce qui fait voir « Finding Dory » au joueur `en` au lieu du repli sur le titre français — amendé le 02/10.

**Le corps des trois pages légales et de la page « signaler un contenu » est rédigé en français seulement** (décision 4). Il vit en partiels de vue (`resources/views/legal/{notice,terms,privacy,report}.fr.blade.php`), **hors des dictionnaires**, et la page `legal/show` l'injecte dans un **`<div lang="fr">`** quelle que soit la locale du visiteur. **`<html lang>` suit, lui, la locale du visiteur**, jamais un `fr` forcé : l'habillage (`legal.*` — titre, bandeau provisoire, avertissement de disponibilité en français, date de mise à jour, bloc de contact, pied de page) est dans sa langue, et un lecteur d'écran doit le prononcer dans sa langue ; seul le corps est balisé `fr`. Un visiteur en `en` voit donc l'habillage en anglais et le corps en français correctement balisé. C'est le cas d'usage principal de la règle d'attribut `lang` sur fragment. « Signaler un contenu » (`takedown.create`) suit le même modèle, sans formulaire au J1, et reste `noindex` en permanence, y compris après l'ouverture (`90` § 4.2, § 4.5) — amendé le 23/09.

---

## Chaîne de repli d'affichage d'un titre

1. `movie_title` de la locale du joueur.
2. `movie_title` d'une autre locale activée, dans l'**ordre de repli déclaré par l'instance** (`en`, puis `fr`).
3. `title_original`.

Quand le titre retenu est en écriture non latine, l'affichage utilise la **translittération latine de `movie.title_original_latin`** si TMDB l'a fournie ; sinon le titre tel quel. **Jamais un alias** : un alias n'est jamais affiché, sans exception (`10` § A4). La **révélation affiche toujours le titre retenu et `title_original` s'il diffère**, plus l'année — qui est aussi le discriminant des homonymes et des remakes.

Le **rang atteint** dans cette chaîne est une donnée de premier plan : c'est lui qui rend le QCM dangereux.

---

## QCM : homogénéité linguistique et envoi ciblé obligatoire

Les quatre propositions sont rendues **par joueur** — langue de rendu et ordre — et transmises en **envoi ciblé**, jamais en diffusion salon — amendé le 23/09. Ce n'est pas une optimisation, c'est la seule façon de tenir la règle 3 de `CLAUDE.md` en environnement multilingue, et voici pourquoi.

Supposons une diffusion unique de quatre identifiants de film, chaque client résolvant lui-même les titres par sa chaîne de repli. Pour un joueur francophone, trois films ont un titre FR et le quatrième n'en a pas : son écran affiche trois titres français et un titre anglais. **Cette singularité est l'information.** Qu'elle désigne la bonne réponse ou qu'elle l'élimine, elle n'aurait jamais dû quitter le serveur, et aucun mélange d'ordre ne la masque.

D'où l'algorithme, arbitré ici et consommé par `70-validation-des-reponses.md` :

1. Pour le joueur `P` de locale `L`, on calcule le **rang de repli** atteint par le film cible : 1 (titre dans `L`), 2 (titre dans une autre locale activée), 3 (`title_original`).
2. Les trois leurres sont tirés **une seule fois par manche**, parmi les films dont le **profil de disponibilité de titre sur l'ensemble des locales activées est identique à celui du film cible** — pour chaque locale activée, le leurre a un titre dans cette locale si et seulement si le film cible en a un. Tous les joueurs du salon reçoivent donc **les mêmes quatre films** ; seules la langue de rendu des quatre chaînes et leur ordre diffèrent d'un joueur à l'autre. Cette règle donne l'homogénéité dans **toutes** les locales activées à la fois, là où un tirage au rang de repli du seul joueur ne la garantit que dans la sienne. Le tirage a lieu à la première composition, à l'ouverture du palier du QCM (`T₁` en Facile, `T_N` en Normal), jamais au lancement, et suit l'ordre de D21 du 23/09 : d'abord le **vivier du salon**, puis le **catalogue publié**, non-répétition conservée ; sont toujours exclus le film cible, son `movie_group` et les films des manches déjà démarrées de la partie (`started_at` ≤ instant théorique de composition) ; les films des manches futures, programmées comprises, ne sont **jamais** exclus, car les exclure trahirait le tirage (D21 du 23/09 ; `70` § 10.2, `30` § 10.2). Quand le film cible n'a de titre dans aucune locale activée, la forme de son titre original (latine, translittérée ou native) entre aussi dans le profil. L'échelle de tirage appartient à `70` § 10.2-10.3, le vivier à `PoolScope::forDecoys` de `30` § 10 — amendé le 23/09.
3. S'il n'existe pas trois films à ce profil, les quatre propositions basculent ensemble sur `title_original` **pour tout le salon**, avec des leurres de même forme de titre original que la cible. On dégrade les quatre, jamais un seul. Si même ce mode ne fournit pas trois leurres, aucun QCM n'est composé : la manche est annulée en Facile, et en Normal le texte libre reste le seul mode (cas terminal, `70` § 10.7) — amendé le 23/09.
4. Aucune traduction à la volée, jamais : un leurre est un titre réel d'un film réel du catalogue.
5. Le serveur envoie **quatre chaînes déjà résolues**, pas des identifiants : la résolution côté client rouvrirait exactement le trou que l'étape 2 vient de boucher.
6. L'ordre des quatre propositions est mélangé **par joueur**, avec une graine propre au couple (manche, joueur), pour que l'ordre lui-même ne soit pas un canal — ni entre deux écrans côte à côte, ni d'une manche à l'autre.
7. **Le QCM d'une manche est composé une seule fois et matérialisé, et la langue de chaque joueur y est figée.** Les trois leurres retenus et les quatre chaînes de chaque locale activée sont **persistés à la première composition** (une ligne `round_choice_set` par locale), et la langue de rendu de chaque siège est figée au même instant (`round_player.choices_locale`). L'ordre, lui, n'est pas stocké : il se recalcule à chaque envoi par la permutation de l'étape 6, identique d'un envoi à l'autre (`70` § 10.6, § 10.8) — amendé le 23/09. Tout renvoi ultérieur — resynchronisation après déconnexion, rechargement de page, reprise de siège par un second onglet, changement de langue — **rejoue exactement les mêmes quatre propositions**, jamais un nouveau tirage. Raison : deux tirages indépendants pour le même joueur ont le film cible pour seul élément commun garanti ; leur intersection donne la réponse sans aucune complicité extérieure, et un simple rechargement suffirait. Si la locale du joueur a changé entre la composition et le renvoi, les propositions repartent dans la **langue de composition**, jamais recomposées dans la nouvelle.

Conséquence : les quatre **films** proposés sont les mêmes pour tout le salon, seules leur langue de rendu et leur ordre changent. C'est délibéré : un tirage par joueur donnerait à deux joueurs qui comparent leurs écrans une intersection dont le seul élément garanti est le film cible — un oracle à un message.

**Règle générale dont le QCM est le cas limite** :

| Moment | Ce qui transite | Forme |
|---|---|---|
| Avant la révélation | Propositions QCM | Chaînes déjà résolues, **envoi ciblé par joueur** (`seat.choices`, canal privé du siège). Aucun message, **ciblé comme diffusé**, ne contient l'identifiant du film cible, un titre ou un alias hors des quatre chaînes elles-mêmes, ni **l'index, le drapeau, la position conventionnelle ou toute métadonnée par proposition** désignant la bonne réponse : la charge utile ciblée porte **les quatre chaînes homogènes, le seul drapeau `choices_use_original_title`** (`useOriginalTitle`, commun à tout le salon) **et un attribut `lang` égal à la locale effective atteinte** (`null` quand les chaînes sortent de `title_original`), plus l'enveloppe de `60` et `sequenceIndex` (`60` § 11.3), rien d'autre. Le client renvoie la chaîne choisie, le serveur seul juge — amendé le 23/09. |
| À la révélation et après | Titre, titre original, année et images des paliers ouverts, à la révélation ; récapitulatif et podium, en texte seul | Diffusion salon d'un **paquet de titres par locale activée** ; chaque client choisit le sien, et **chaque titre porte la locale atteinte**, pour son attribut `lang` (`RevealMovie`, `60` § 11.5). Le paquet de révélation porte aussi les URL des paliers **ouverts**, jamais d'un palier non ouvert, servables jusqu'à la fin de la révélation (D14 du 23/09). Une seule diffusion, aucun coût par joueur, et plus rien à cacher — amendé le 23/09. |
| À tout moment | Feed « a trouvé », classement, statuts, chrono | **Données** : identifiants de joueur, scores entiers, instants ISO-8601 UTC, durées en millisecondes. Jamais une phrase pré-formatée par le serveur. |

Un titre de film est une **donnée**, pas une chaîne pré-formatée : le servir résolu ne contredit pas la règle 4 de `CLAUDE.md`, qui interdit les phrases construites côté serveur (« Alice a trouvé en 4,2 s »), pas les valeurs.

---

## Acceptation multilingue des réponses

**Une bonne réponse est acceptée dans toutes les langues activées, quel que soit le joueur et quel que soit le salon.** « Le Seigneur des Anneaux : Les Deux Tours » valide la manche, pour un joueur en FR comme pour un joueur en EN ; « Les Deux Tours » et « The Two Towers » la valident aussi, par la règle du sous-titre (D23 du 23/09), tant qu'aucun autre film publié ne porte la même forme normalisée, sous quelque nature que ce soit — titre, alias, préfixe ou sous-titre (`70` § 4.2) ; « seigneur des anneaux 2 » ne la valide que si cet alias curé existe — amendé le 23/09.

Périmètre exact des chaînes acceptées pour un film :

- `title_original` et sa translittération latine quand elle existe ;
- tous les `movie_title` des **locales activées** ;
- tous les `alias` des **locales activées** ;
- les formes dérivées des titres, **jamais des alias** : le **préfixe** (partie avant le premier séparateur déclaré, décision 13) et le **sous-titre** (partie après, D23 du 23/09), chacun refusé quand il désigne aussi un autre film publié — ambiguïté mesurée sur le catalogue publié entier, à l'instant serveur de réception, jamais rétroactive. Règle et exemples : `70` § 4.2, § 4.4 — amendé le 23/09.

**La locale du joueur n'entre dans aucune décision de validation.** Corollaire direct de la règle 1 : changer de langue en pleine manche ne peut pas rendre juste une réponse déjà refusée, ni l'inverse. La langue pilote l'affichage, jamais l'arbitrage.

Conséquence portée par `70` : le **seuil de tolérance de distance d'édition se calibre sur le corpus multilingue complet**, pas sur un corpus par langue. Le titre EN d'un film peut se trouver à faible distance du titre FR d'un autre film publié, et c'est le genre de collision qu'un calibrage monolingue ne voit jamais. Ce calibrage se fait par le rapport de collisions `answers:collisions` (`70` § 13.1), outil du porteur en lecture seule, lancé sur le catalogue publié réel avant la clôture du J1 et **rejoué à l'ajout d'une langue** — amendé le 23/09.

---

## Erreurs, validation et messages à destinataire unique

Règle de partage, valable partout :

> Un message est **résolu côté serveur** quand il n'a qu'un destinataire et que la requête connaît sa locale. Il transite en **données** dès qu'il est diffusé à plusieurs.

Une réponse HTTP a toujours un destinataire unique, et la requête porte sa locale : messages de validation, erreurs 4xx et 5xx, `Inertia::flash('toast', ...)` sont donc rendus en clair dans la langue de la requête. Un événement Reverb, lui, part vers un salon entier : il ne contient que des données, ou il est ciblé.

S'ensuivent, sans exception : messages de rejet de pseudo traduits, écriture refusée comprise (pseudo en alphabet latin seul, D26 du 23/09), **liste noire de pseudos par langue activée**, appliquée en union quelle que soit la langue du joueur (`40` § 5.3, § 5.7) — amendé le 23/09 —, bornes croisées de réglages dont le message nomme le réglage fautif dans la langue de l'hôte, pages d'erreur traduites, et `alt` d'image **neutre et traduit** — la clé `game.frame.alt` vaut `Image :index sur :total de la manche en cours`, elle ne décrit jamais le contenu (principe 8 de `00-overview.md`, et anti-triche).

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

Objets et corps passent par `lang/{locale}/mail.php` et par les clés Fortify du framework, `lang/*.json` compris. Le listener `LocaleUpdated` décrit plus haut règle `CarbonImmutable` **dans le job**, sans quoi une date dans un e-mail français s'écrit en anglais. Un compte sans e-mail (Discord peut n'en retourner aucun) ne reçoit rien : la spec `40`, dans sa section du jalon 2, en tire ses conséquences — amendé le 23/09.

---

## Nombres, dates et durées, sans `ext-intl`

`ext-intl` est **absent** du serveur : `Number::format()` lève une `RuntimeException`, et cette absence fausse déjà `db:show`. Décisions :

1. **Le serveur ne met en forme ni nombre ni date destinés à un écran** — jeu ou back-office. Les scores partent en entiers, les instants en ISO-8601 UTC, les durées en millisecondes. Le client formate avec `Intl.NumberFormat` et `Intl.DateTimeFormat`, disponibles dans tous les navigateurs cibles, dans la locale du joueur. C'est la même règle que « tout ce qui est partagé transite en données », vue sous l'angle du formatage. **Le back-office**, rendu par React comme le jeu, formate lui aussi côté client, par `resources/js/lib/admin-format.ts` [existant], `Intl` en locale `fr` forcée — amendé le 23/09.
2. **Là où aucun client ne rend le texte** — e-mails et export de données (J2) — un helper `App\Support\Format` s'appuie sur `number_format()` avec les séparateurs tirés du registre de locales (FR : espace insécable et virgule ; EN : virgule et point) et sur `CarbonImmutable::translatedFormat()`. Il ne sert jamais au back-office — amendé le 23/09.
3. **`ext-intl` n'est pas ajouté en v1.** Si une évolution l'exige, `CLAUDE.md` impose d'ajouter la clé `extensions:` au workflow `.github/workflows/tests.yml` **dans le même commit** que la contrainte `composer.json`, faute de quoi la CI casse sur toutes les branches à la fois.

---

## Pas de préfixe de locale dans les URL

Aucune URL ne porte `/{locale}/`. Quatre raisons, dont la dernière est produit :

1. Fortify enregistre ses routes lui-même : elles échapperaient au groupe préfixé.
2. `.well-known/passkey-endpoints` et `storage/{path}` sont à chemin fixe par contrat.
3. Le préfixe réécrirait tous les helpers Wayfinder générés, que le principe 11 interdit de contourner à la main.
4. **Un lien de salon se partage entre amis de langues différentes.** `/fr/room/ABC42` envoyé par un hôte français à un ami anglophone impose le français ou déclenche une redirection : le code de salon doit désigner un salon, pas une langue.

La langue vit donc dans le cookie `locale`, la colonne `users.locale` et le `player_token`, et nulle part dans le chemin.

**Conséquences, le site étant public et indexé à l'ouverture (décision 1 ; au jalon 1, il est intégralement `noindex`)** : `Vary: Accept-Language` sur les pages dont le contenu varie réellement avec la locale — l'**accueil**, les **trois pages légales** et « **signaler un contenu** », dont l'habillage suit la locale du visiteur alors que le corps reste en français (§ Contenus traduits stockés en base). Balise canonique pointant l'URL nue partout. **`hreflang` n'est déclaré que là où deux variantes d'une même page existent réellement — donc nulle part en v1**, puisqu'il n'y a pas d'URL alternative. Googlebot n'envoyant pas d'`Accept-Language`, **l'accueil est indexé dans le repli d'instance, en anglais** : c'est accepté et écrit ; les trois pages légales, dont le corps est en français, sont indexées à leur URL ; « signaler un contenu » ne l'est jamais. Tout le reste du site est `noindex`. Corollaire d'exploitation : **aucun cache HTTP de page complète** n'est posé devant l'application (`CLAUDE.md` §3), sans quoi la première langue servie serait servie à tous ; une réponse avec session sort de toute façon en `Cache-Control: private` — amendé le 23/09.

---

## Back-office en français seulement

Le back-office de curation est **en français uniquement** — la décision 9 sépare les rôles et confie la curation à un non-technicien francophone —, mais **100 % de ses textes passent par des clés** de traduction, dans le domaine `admin`. Aucune chaîne en dur, même là.

La règle est **appliquée, pas espérée** : un middleware sur le groupe de routes d'administration, `ForceAdminLocale` [existant] (alias `admin.locale`), force `App::setLocale(Locale::French)` pour toute la durée de la requête, quelle que soit la préférence personnelle du curateur, et ne sélectionne que le domaine `admin`. Sans lui, un curateur dont le compte est en `en` verrait s'afficher des clés brutes, puisque `lang/en/admin.php` n'existe pas. C'est aussi ce qui garantit que le domaine `admin` n'est jamais expédié dans la charge utile d'un écran joueur, et qu'aucun domaine joueur n'est expédié au back-office : son pied de page passe par `admin.footer.*`, jamais par `legal`, et son erreur par la page `admin/error` (`20` § 13.2). Le middleware `role` s'exécutant avant `admin.locale`, un joueur refusé par `role:curator` voit, lui, la page d'erreur joueur, dans sa langue (`90` § 4.8) — amendé le 23/09.

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

S'y ajoute un contrôle de dérive : la CI relance `php artisan lang:types --check` et échoue si `resources/js/types/translations.d.ts` diffère du fichier committé — amendé le 23/09.

Ces vérifications existent déjà dans `tests/Feature/I18n/TranslationCoverageTest.php` [existant] ; leur place dans le pipeline — groupe Pest par défaut, job `ci` sur chaque PR — est fixée par `100` § 5. Le test Vitest de parité de `translateChoice` (§ Dictionnaire front) vit dans `tests/Frontend/i18n/translate-choice.test.ts` (`100` § 6) — amendé le 23/09.

**Clés construites à l'exécution.** Une clé composée par gabarit (`` t(`common.avatar.preset.${k}`) ``) est **interdite** : elle échapperait au typage `TranslationKey` et à la vérification des clés réellement appelées. Toute famille énumérable passe, côté client, par une table `Record<Valeur, TranslationKey>` (patron `resources/js/lib/admin-enum-keys.ts` [existant]) et, côté serveur, par une méthode `…Key()` (patron `SettingPresetKey::labelKey()` [existant]). Toute clé construite par du code livré est couverte par le test d'énumération `carries every key built by an enumerable key constructor`, que chaque propriétaire étend pour ses familles — grille d'exclusion, avatars prédéfinis, erreurs HTTP, messages de pseudo, refus de salon, causes et remèdes du vivier (`90` § 6.7) — amendé le 23/09.

La définition de « terminé » du projet inclut déjà « textes FR et EN complets » ; ces tests en sont la forme exécutable.

---

## Ajouter une troisième langue

Procédure complète, à dérouler telle quelle. Rien d'autre n'est requis : c'est la démonstration que l'architecture accueille une langue « sans refonte ».

1. Ajouter le cas à l'enum `App\Enums\Locale` et sa position dans l'ordre de repli d'affichage, et incrémenter `Locale::MASK_VERSION`. `catalog:reproject`, joué à chaque déploiement (`100` § 11.5), recalcule alors les profils de titre ; tant qu'il n'a pas tourné, les QCM basculent sur `title_original`, du côté visible (`10` § 3.2). Étendre l'union `LocaleCode` de `resources/js/types/game-wire.ts` (`60` § 11.4), écrite à la main, sans quoi `RevealMovie.titles` ne type pas la nouvelle locale — amendé le 23/09.
2. Créer `lang/es/` et `lang/es.json`, publier les fichiers du framework, traduire les domaines joueur (`admin` reste français).
3. **Compléter le libellé de chaque thème publié dans la nouvelle locale** — c'est la seule tâche bloquante, et elle se fait en back-office, sans déploiement.
4. Fournir la liste noire de pseudos de cette langue (`resources/moderation/nicknames/{locale}.txt`, en-tête de source et de licence ; un fichier manquant pour une locale activée lève, `40` § 5.7). Pour une langue écrite hors de l'alphabet latin, c'est aussi l'étape où son écriture s'ouvre aux pseudos (`40` § 5.3, D26 du 23/09) — amendé le 23/09.
5. Relancer les trois vérifications de couverture et régénérer `translations.d.ts`.
6. **Revalider les seuils de distance d'édition de `70` sur le corpus élargi**, en rejouant le rapport `answers:collisions` (`70` § 13.1) : accepter une langue de plus élargit la surface d'acceptation et peut créer des collisions entre un titre espagnol et un titre français déjà publié — amendé le 23/09.
7. Vérifier le corpus de leurres : si trop peu de films portent un titre dans la nouvelle locale, la règle d'homogénéité fera basculer les QCM sur `title_original` — comportement correct, mais à connaître avant d'annoncer la langue.

Ce qui **ne bouge pas** : aucune migration (titres, alias et libellés de thèmes sont des lignes, pas des colonnes), aucune route, aucune régénération Wayfinder, aucune URL, aucun écran.

Les langues au-delà de FR et EN restent **hors périmètre v1**, ainsi que la traduction des contenus de curation autres que titres et alias, et les écritures droite-à-gauche.

---

## Ce que cette spec ne décide pas

Les onze autres specs de la carte (`00`, `10` à `100`) sont désormais écrites ; `40`, `90` et `100` ne le sont que pour leur section du jalon 1, et leur section « Jalon 2 — à écrire » liste les sujets restants. Chaque ligne renvoie à la section qui décide — amendé le 23/09.

| Sujet | Spec propriétaire |
|---|---|
| Les tables `movie_title`, `alias`, `theme_label`, les colonnes `users.locale`, `player.locale`, `round_choice_set.rendered_locale`, `round_player.choices_locale`, le profil de titre de `movie_projection` et `Locale::MASK_VERSION`, et le hash du `player_token` (`player.player_token_hash`) | `10-catalogue-et-modele-de-donnees.md` — seul propriétaire du schéma — amendé le 23/09. |
| La forme, le transport (cookie `HttpOnly` chiffré), la durée et la re-signature du `player_token` ; la liaison `CookiePlayerTokenLocale` du niveau 3 ; la règle de pseudo (écriture latine seule, D26 du 23/09) et la liste noire par langue ; le registre des avatars prédéfinis et le texte de `common.avatar.*`, `validation.nickname.*` et `validation.attributes.{nickname,avatar}` — section [J1], § 3 à § 6. Au jalon 2 (§ 10) : `HasLocalePreference` côté compte, le compte sans e-mail, la langue portée par un invité qui se connecte en cours de partie | `40-comptes-auth-sociale-et-avatars.md` — amendé le 23/09. |
| L'écran de couverture des titres par langue et la file « titres manquants » (§ 9.3), l'édition des titres et des alias (§ 9.1, § 9.2), l'édition des libellés de thèmes (§ 9.6, J2), le texte du domaine `admin`, grille d'exclusion et pied du back-office compris (§ 7.1, § 13.2), les gabarits `mail.takedown.*` (§ 11.4, J2) | `20-back-office-curation.md` — amendé le 23/09. |
| Le vivier et `PoolScope::forDecoys`, dans lesquels se tirent les leurres (§ 3, § 10), les thèmes livrés et leurs libellés (§ 12) | `30-themes-vivier-et-tirage-des-variantes.md` — amendé le 23/09. |
| Les libellés et bornes de chaque réglage, le texte des messages de bornes croisées et `validation.attributes.<champ de réglage>` (§ 4.2, § 20) | `50-salon-reglages-presets-et-lobby.md` — amendé le 23/09. |
| Le contrat d'événements, les canaux et la mécanique de l'envoi ciblé `seat.choices` que cette spec rend obligatoire (§ 8.3, § 11), le contenu de la révélation et le paquet `RevealMovie` (§ 9.5, § 11.5), le texte de `room.solo.*` et de `validation.attributes.preset` (§ 16.2, § 16.4, § 19.4) | `60-moteur-de-partie-temps-reel-et-mode-solo.md` — amendé le 23/09. |
| La normalisation des réponses, les seuils de distance d'édition et leur calibrage par `answers:collisions` (§ 5, § 6, § 13), le préfixe et le sous-titre, c'est-à-dire la réponse partielle sur une saga (§ 4.2), le tirage des leurres selon D21 du 23/09, la composition et le rejeu du QCM (§ 10), l'exigence du rang de `SetLocale` (§ 8) | `70-validation-des-reponses.md` — amendé le 23/09. |
| Le contenu localisé du podium et du récapitulatif de fin de partie (§ 11.4), le texte de `game.round.tier_value` et de `game.help.scoring.*` (§ 14, § 15) | `80-scoring-podium-et-fin-de-partie.md` — amendé le 23/09. |
| Le sélecteur de langue comme écran, ses états, son motif ARIA et sa place dans la navigation (§ 8) ; la table de déclaration des domaines page par page et les clés figées des coquilles (§ 6) ; le rendu des pages légales et de « signaler un contenu » (§ 4.2, § 4.5) et des pages d'erreur (§ 4.8) | `90-ecrans-etats-et-structure.md` — amendé le 23/09. |
| La place des vérifications de couverture dans le pipeline (§ 5), le test Vitest de `translateChoice` (§ 6), l'étape `lang:hash` du hook de déploiement (§ 11.5) | `100-qualite-tests-et-ci.md` — amendé le 23/09. |

**Question ouverte, relevant de cette spec et laissée au porteur** : une règle typographique française commune à tous les dictionnaires — espace insécable U+00A0 ou fine U+202F avant « : ; ! ? % » et à l'intérieur de « ». Tant qu'elle n'est pas tranchée, les dictionnaires gardent l'espace ordinaire de `lang/fr/` ; si elle est adoptée, elle s'applique à tous les domaines à la fois, jamais au seul domaine `game` (`80` § 15.1, § 21) — amendé le 23/09.

**Question ouverte ajoutée le 28/09, laissée au porteur** — **(E65-2)** domaine du cookie `locale`. `LocaleCookie` passe par `Cookie::make(domain: null)`, et le `CookieJar` du framework remplace un domaine nul par `session.domain` (`SESSION_DOMAIN`) : un `SESSION_DOMAIN` posé en production étendrait le cookie `locale` aux sous-domaines de `<DOMAINE>`. Sans conséquence tant que la variable est vide. Le `player_token`, lui, est tenu « hôte seul » par construction (`PlayerTokenCookie` bâtit le cookie Symfony directement, `40` § 3.2). À trancher ici : « hôte seul » vaut-il aussi pour le cookie `locale` (même construction directe), ou le domaine de session lui convient-il ?
