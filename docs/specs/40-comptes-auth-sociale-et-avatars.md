# Comptes, authentification sociale et avatars

> **Spec partielle : section [J1] seule.** Ce fichier contient la section [J1] « identité invitée », écrite en entier avant `50` (D2 du 23/09), et une section **« Jalon 2 — à écrire »** (§ 10) qui liste, sans les rédiger, les sujets que la carte des specs de `00-overview.md` attribue à `40` pour l'ouverture publique. Aucune phrase des § 1 à 9 ne tranche un sujet du § 10. **Amendé le 07/10 (D66 du 07/10)** : la section J2 est rédigée au **§ 13** (sujets 1, 5, 6, 7, 8, 9, 10, 11, 14 et 15 ; sujets 12, 13, 16 et 17 soldés par renvoi), sur les réponses du porteur du 07/10 ; la spec n'est plus partielle que par les écrans de compte, qui appartiennent à `90`.

Ce document est le propriétaire de l'**identité d'un joueur** : ce qui reconnaît un siège d'une requête à l'autre sans compte (le `player_token`), ce qu'un joueur montre aux autres (pseudo et avatar), et ce qu'un compte débloque. Sa section [J1] possède deux contrats de la feuille de contrats partagés du 23/09, repris à la lettre : **C4** (`player_token`) et **C5** (règle de pseudo et registre d'avatars). Elle y ajoute trois règles de compte que le jalon 1 ne peut pas laisser ouvertes, parce que la production existe dès lui (D1 du 23/09) : l'inscription publique et les passkeys restent fermées, la suppression dure de compte est retirée, et le nom réel d'un compte privilégié est cadré du point de vue du compte : jamais recopié dans une identité de jeu, jamais montré hors du back-office (D12 du 23/09, contrat C14).

Elle ne possède **pas** : le schéma (`10`, seul propriétaire ; toute donnée nouvelle est une exigence E10-xx), la règle de langue (`05`), la prise de siège comme action — verrou, capacité, reprise, expulsion comme geste — (`50`), la garde et les canaux temps réel (`60`), la primitive de repli `fold()` (`70`), les écrans et leur mise en page (`90`), le premier administrateur, la matrice des rôles et la 2FA des rôles privilégiés au J1 (`20`).

**Convention de renvoi**, valable dans tout le document : « règle N » désigne `CLAUDE.md` §7 ; « principe N » désigne `00-overview.md` § Principes directeurs ; « décision N » désigne une des 19 décisions du 22/09 (`questions-ouvertes.md`) ; « DN du 23/09 » désigne une décision du porteur du 23/09, consignée dans `questions-ouvertes.md` § Décisions du 23/09/2026 — jalon 1 ; « contrat CN » désigne la feuille de contrats partagés du 23/09, dont cette spec reprend noms, signatures, charges et tests à la lettre ; « I4.n » et « I5.n » sont les invariants numérotés des contrats C4 et C5 ; « E10-nn » et « A-nn » sont les exigences à `10` et les amendements consolidés de la même feuille.

> **État réel du dépôt au moment d'écrire, vérifié au commit `d167a6a`.**
> - **Aucun jeton n'est frappé nulle part.** `app/Support/I18n/PlayerTokenLocale.php` est une interface ; `NullPlayerTokenLocale` (qui rend toujours `null`) est liée dans `AppServiceProvider::registerLocalization()` ; `SetLocale` appelle déjà le niveau 3. La colonne `player.player_token_hash` existe (10 § 7.1) et n'est écrite que par les fabriques. `LocaleController::update()` écrit `users.locale` et le cookie `locale`, et porte le commentaire « Reste à brancher quand le `player_token` sera frappé ».
> - **Absents** : `app/Support/Identity/`, `app/Rules/`, `resources/moderation/`, `public/avatars/`, `config/game.php`, `config/accounts.php`, la colonne `player.kicked_at`, la colonne `users.real_name`, et `AnswerKeyNormalizer::fold()` (propriété de `70`, contrat C12).
> - `App\Avatars\AvatarRef` porte des clés `avatar.alt.preset|provider|initials` dans un domaine `avatar` que `TranslationDomains::KNOWN` ne connaît pas, et n'expose pas `presetUrl()`. `Player::avatarRef()` ne connaît que le prédéfini et les initiales. `Player` a `#[Hidden(['id', 'room_id', 'user_id', 'player_token_hash', 'active_seat_token', 'user'])]` : `nickname_normalized` n'y est pas.
> - `PlayerFactory::normalizeNickname()` est un troisième normaliseur provisoire (`Str::ascii`, minuscules, espaces compressés, **tronqué à 20 en silence**), avec `NICKNAME_MAX_LENGTH = 20` ; `PlayerFactory::avatarPreset()` et `UserFactory::withPresetAvatar()` fabriquent `sprintf('preset-%02d', …)` sur `PlatformLimits::avatarPresets()` (défaut 24, `roomSeats()` défaut 12).
> - `lang/{en,fr}/validation.php` ne porte, des clés de `40`, que `attributes.nickname` (« nickname » / « pseudo ») : ni `nickname.*`, ni `attributes.avatar`.
> - `resources/js/types/auth.ts` déclare `Auth = { user: User }`, alors que `HandleInertiaRequests::share()` partage `'auth' => ['user' => $request->user()]`, nul pour tout visiteur non connecté ; `welcome.tsx`, `nav-user.tsx` et `app-header.tsx` le gardent déjà, `admin-sidebar.tsx` et `settings/profile.tsx` le lisent sans garde.
> - `routes/settings.php` déclare `.well-known/passkey-endpoints` (`well-known.passkeys`) **hors** du groupe de Fortify, avec `enroll` et `manage` pointés sur `security.edit`. `scripts/check-theme-tokens.mjs` ne surveille ni `components/game` ni `lib/game`, qui n'existent pas.
> - `DemoAccountsSeeder` n'est joué qu'en `local` et `testing` : garde en **liste blanche**, parce que `.env.example` livre `APP_ENV=local` et qu'une garde adossée au seul nom `production` laisserait passer tout autre nom.
> - `ext-intl` est absente, mais `\Normalizer::normalize(…, FORM_C)` fonctionne grâce à `symfony/polyfill-intl-normalizer`, arrivé **par transitivité** seulement ; `preg_match('/\p{Latin}/u')` fonctionne sans `intl` (vrai pour `ª`, faux pour `µ`), et `Str::transliterate('Æþßĳœŋ')` rend `AEthssijoeNG`.
> - `config/fortify.php` active **sans condition** `Features::registration()` et `Features::passkeys(['confirmPassword' => true])` ; l'identifiant de partie de confiance des passkeys est l'hôte d'`APP_URL`. `pages/welcome.tsx` et `pages/auth/login.tsx` importent le helper Wayfinder `register` ; `components/manage-passkeys.tsx` et `pages/auth/confirm-password.tsx` importent des actions Wayfinder des contrôleurs de passkeys. **`resources/js/{routes,actions,wayfinder}` sont ignorés par git et régénérés à chaque build**, donc en CI : désenregistrer une route selon l'environnement casserait la compilation TypeScript. C'est la raison vérifiée de la forme retenue au § 8.2.
> - `ProfileController::destroy()` fait `$user->delete()` (suppression dure), sur la route `profile.destroy` ouverte à tout compte `auth, verified` ; `account.delete_account.dialog_description` promet une suppression « définitive » de « toutes les données » ; `tests/Feature/Settings/ProfileUpdateTest.php` fige ce comportement (« user can delete their account »).
> - `resources/js/types/auth.ts` déclare `email: string` et `avatar?: string` ; `components/user-info.tsx` pose `alt={user.name}`. `admin:first-admin {email?} --name= --create --force` ne demande aucun nom réel.

---

## 1. Frontières

### 1.1 Ce que possède la section [J1]

| Sujet | Forme | § |
|---|---|---|
| Parcours invité, du point de vue de l'identité | Ce qui est lu, frappé, écrit et effacé à chaque étape | § 2 |
| `player_token` | Contrat **C4** : forme, transport, frappe paresseuse, durée, hash, re-signature, refus d'un jeton expulsé (D15 du 23/09) | § 3 |
| Langue portée par le jeton | Implémentation réelle de `PlayerTokenLocale`, complément de `LocaleController` | § 4 |
| Pseudo | Contrat **C5**, première moitié : écritures admises (D26 du 23/09), `ValidNickname`, `NicknameNormalizer` sur `fold()`, unicité par salon, liste noire par langue | § 5 |
| Avatars prédéfinis | Contrat **C5**, seconde moitié : pack animaux Kenney CC0 (D27 du 23/09), `AvatarPresetCatalog`, clés `preset-01`..`preset-24`, attribution par le serveur à la prise de siège, unicité dans le salon (D55 du 02/10 — amendé le 02/10) | § 6 |
| Identité affichée d'un siège | `PlayerIdentity`, seule sérialisation, forme J1 du masquage | § 7 |
| Comptes au J1 | Inscription publique et passkeys fermées en production, suppression dure retirée et texte corrigé, nom réel des comptes privilégiés vu du compte (D12 du 23/09) | § 8 |

### 1.2 Ce qu'elle consomme sans le redéfinir

- **Contrat C6** (`50`) : la transaction de lancement ne retient que les sièges `connected` ou `disconnected` — un expulsé est `left` — (étape L6) ; `OpenGame` gèle `game_player.display_nickname`, `display_avatar_kind` et `display_avatar_preset` depuis `player` (étape O6) ; le drapeau de drainage n'empêche **jamais** d'entrer dans un salon (§ 4.8 de C6). La prise de siège elle-même, sa capacité, son verrou et sa reprise appartiennent à `50`.
- **Contrat C7** (`60`) : la garde `player` (pilote `player-token`, principal `SeatPrincipal`) ne lit que `PlayerTokenManager::current($request)?->hash()` ; les canaux `room.{roomKey}` et `seat.{publicId}` refusent un siège expulsé ; `seat.kicked` part après validation de la transaction d'expulsion ; `SeatView` étend `PlayerIdentity` ; le jeton d'onglet `active_seat_token` et `ClaimSeatTab` sont un autre objet que le `player_token`. `40` n'ajoute **aucune** fonction à la demande de `60` : le hash courant suffit (C7 § 6).
- **Contrat C12** (`70`) : `AnswerKeyNormalizer::fold(string $text): string` — public et statique, minuscules, translittération ASCII par `Str::transliterate` sans `ext-intl`, espaces compressés et bords coupés, sans retrait de ponctuation, d'article ni de chiffre romain, sans troncature, idempotent, total sur de l'UTF-8 valide. `fold` n'entre pas dans `validation_version`.
- **Contrat C15** (`05`) : liste close de sept domaines (ni `avatar`, ni `curation`) ; `40` rédige les textes de `common.avatar.*`, `validation.nickname.*`, `validation.attributes.{nickname,avatar}` et `account.*` ; une clé construite par gabarit est interdite côté client (C15 § 2.7).
- **Contrat C0** (`50`) : `PlatformLimits::avatarPresets()` (`game.platform.avatar_presets`, défaut 24) et `PlatformLimits::roomSeats()` (défaut 12), et la garde `roomSeats() ≤ avatarPresets()` prouvée dans `PlatformLimitsTest`.
- **Contrat C14** (`10` pour la liste, `20` pour l'écriture) : colonne `users.real_name`, trait `RealNameValidationRules`, garde `User::saving`, option `--real-name=` de `admin:first-admin`.

### 1.3 Un siège n'est pas une personne

Toutes les garanties de ce document sont **par jeton, donc par siège**, jamais par personne (10 § 7.1). Un humain qui ouvre une fenêtre privée obtient un second jeton et peut prendre un second siège sous un autre pseudo ; aucun mécanisme ne peut l'empêcher sans adresse IP, que 10 § 11.1 interdit en table de domaine. `40` ne ferme pas ce trou et ne prétend pas le fermer : les remèdes sont des gestes de salon (liste des sièges visible, expulsion par l'hôte), propriété de `50` et `60`.

### 1.4 Rien de ce que possède `40` ne touche la règle 3

Le `player_token` ne porte aucune donnée de manche, et `PlayerIdentity` ne porte qu'un `public_id`, un pseudo et un avatar. Aucune charge construite par ce document ne peut donc approcher la bonne réponse d'un client avant la révélation (règle 3, principe 2).

---

## 2. Parcours invité [J1]

### 2.1 Déroulé, vu de l'identité

| # | Moment | Qui porte l'action | Ce que `40` impose |
|---|---|---|---|
| 1 | Accueil, lien de salon, page de salon expiré (GET) | `90`, `50` | **Aucun jeton frappé** (I4.1). Un jeton existant est **lu** (`current()`) : sa revendication de langue sert au niveau 3 de `05`, sa revendication d'avatar à l'**attribution** de la prise de siège (D55 du 02/10 — amendé le 02/10). |
| 2 | Formulaire de pseudo (création de salon, entrée par lien, carte « Rejoindre » de l'accueil, solo) | Écran `90`, props `50` et `60` | **Pseudo seul** (D55 du 02/10 — amendé le 02/10) : aucun sélecteur ni aucune prop d'avatar à l'entrée, l'avatar est attribué à l'étape 4 et changé au lobby (`50` § 8.1). Props au seul demandeur : les bornes `{min, max}` lues sur `NicknameNormalizer` (l'accueil n'en reçoit aucune, `90` § 4.7). Le client n'écrit jamais `2` ni `20` en dur et n'embarque jamais la liste noire. La mention d'acceptation des CGU et son lien (`legal.*`, `90`) s'affichent **sans rien stocker** : le jeton ne porte aucun consentement (00 § Site public et conformité). |
| 3 | Envoi du formulaire | FormRequest de `50` ou `60` | `prepareForValidation()` remplace `nickname` par sa forme canonique, puis `nicknameRules()` seul (§ 5.8 ; un champ `avatar` envoyé est ignoré, D55 du 02/10 — amendé le 02/10). **Quand le jeton courant tient déjà un siège** dans ce salon (`seatIn()` non nul) — ou, en solo, un siège solo repris selon la règle de `60` —, `nickname` n'est ni exigé ni validé (§ 5.8, I5.5). Un envoi refusé à la validation n'atteint pas l'action : **il ne frappe aucun jeton**. |
| 4 | Prise de siège | Action de `50` (salon) ou de `60` (solo) | Dans cet ordre : (1) `current()`, qui ne frappe rien ; (2) `seatIn()` : un siège existant est repris **sans revalider son pseudo** (I5.5), et `ensure()` fait glisser le cookie ; (3) `wasKickedFrom()` refuse par `room.join.kicked` **avant** tout comptage (§ 3.10) ; (4) capacité et unicité du pseudo sous le verrou du salon (§ 5.6) ; (5) attribution de l'avatar sous le même verrou (§ 6.4, § 11.4), puis `ensure()` **seulement alors**, juste avant l'écriture du siège (§ 2.2), puis re-signature avec l'avatar attribué (amendé le 02/10). Tout refus — salon archivé, expulsé, salon plein, pseudo pris, et en solo drainage ou vivier sans `N` jouable — tombe **avant** `ensure()` : une prise de siège refusée ne frappe aucun jeton et n'émet aucun `Set-Cookie` `player_token` — prolongement de la minimisation d'I4.1 : un identifiant de 30 jours posé sur un visiteur refusé ne désignerait aucun siège. Un seul `Set-Cookie` part (I4.2). |
| 5 | Toute requête ultérieure (page, resynchronisation, battement, image, canal) | `50`, `60`, `70` | Le siège est identifié **exclusivement** par le hash du jeton courant, expulsé toujours exclu (§ 3.9). |
| 6 | Changement de langue | `LocaleController` (`05`, complété ici) | Re-signature à `tid` égal, `player.locale` mise à jour sur chaque siège tenu (§ 4.2). |
| 6 bis | Changement d'avatar au lobby, salon en `lobby` seulement (D55 du 02/10 — amendé le 02/10) | `ChangeSeatAvatar` de `50` (§ 8.1) | Geste du seul titulaire du siège, refusé pendant une partie et sur le podium (`NotInLobby`) ; clé prise par un autre siège tenu refusée sous le verrou du salon (§ 6.4) ; `account` résolu par `SeatAvatar::resolve()` (§ 11.4) ; re-signature de la revendication `avatar` après commit (I4.5). Jamais en solo. |
| 7 | Lancement d'une partie | `OpenGame` (C6, O6) | Pseudo et avatar gelés dans `game_player.display_*` pour la durée de la partie (§ 7.2). |
| 8 | Archivage du salon (24 h après la dernière activité) | `50`, 10 § 11.1 | `player.nickname`, `player.nickname_normalized`, `player.player_token_hash` et `game_player.display_nickname` effacés dans la même transaction (10 § 7.1 : `nickname_normalized` est « effacée avec » `nickname` ; 10 § 11.1). La forme repliée (« zoe », « jeanluc ») est un identifiant d'invité au même titre que le pseudo : la laisser vivre jusqu'à la suppression de la ligne `player`, douze mois ou plus, rendrait fausse la durée publiée. Le cookie reste dans le navigateur mais ne désigne plus rien dans ce salon ; il reste valide ailleurs. |

Le siège **solo** suit les étapes 2 à 6 sans unicité de pseudo ni expulsion ; sa reprise passe par `player_token_idx` (règle de `60`), et son effacement — les mêmes colonnes, `nickname_normalized` comprise, plus `player.solo_token_hash` — par `room_id IS NULL AND last_seen_at < now − 24 h` (10 § 11.1). Code livré (purge `orphan_player`, L100-8) — amendé le 28/09 (E122-1, E122-2) : les colonnes sont effacées dans une transaction, sans supprimer la ligne ; un siège dont une partie n'est pas figée n'est pas effacé (refus défensif, journalisé, posé au porteur par `100`).

### 2.2 Ce qu'écrit une prise de siège

Écrites par l'action de `50` (ou de `60` en solo), **dans la même écriture Eloquent** (10 § 1.2 : jamais `DB::table()` sur `player`) :

| Colonne | Valeur | Source |
|---|---|---|
| `nickname` | `NicknameNormalizer::canonical($saisie)` | § 5.2 |
| `nickname_normalized` | `NicknameNormalizer::normalize($canonique)` | § 5.5, E10-32 |
| `avatar_kind` / `avatar_preset` | **attribués par le serveur sous le verrou du salon** : `AvatarKind::Upload` et un prédéfini de repli pour un compte dont l'image téléversée est visible, sinon `AvatarKind::Preset` et `suggest(préféré du jeton, pris)` (D55 du 02/10 — amendé le 02/10) | § 6.4, § 11.4 |
| `player_token_hash` | `$token->hash()` | § 3.5, E10-33 |
| `locale` | Locale effective de la requête (`App::getLocale()` posée par `SetLocale`) | `05` |
| `user_id` | `users.id` du compte connecté à la prise de siège, sinon nul (I4.10, amendé le 01/10, D49 du 01/10) | § 2.4 |

`public_id`, `joined_at`, `connection_state`, `last_seen_at` et `active_seat_token` relèvent de `50` et `60` (10 § 7.1).

**À la reprise.** Une reprise ne réécrit ni `nickname`, ni `nickname_normalized`, ni `avatar_preset` (I5.5). Le seul autre écrivain de `avatar_kind`/`avatar_preset` est le geste du lobby (§ 2.1, étape 6 bis ; D55 du 02/10 — amendé le 02/10). Elle réaligne seulement `locale` : l'écriture qui ramène un siège `left` à `connected` — au J1, le battement de présence de `60` (60 § 13.1), ou toute reprise de `50` qui écrirait le siège — pose `locale` = `App::getLocale()` **dans la même écriture Eloquent** que ce retour. Raison : le changement de langue ne touche que les sièges `holdingSeat()` (§ 4.2), qui exclut `left` ; sans ce réalignement, un joueur parti qui change de langue puis revient garderait l'ancienne `player.locale`, et ses envois ciblés — le QCM composé à son intention (contrat C11) compris — partiraient dans une autre langue que son interface. Un siège `disconnected` n'est pas concerné : il reste `holdingSeat()` et suit le § 4.2.

### 2.3 Ce qui n'est jamais stocké

- **Le pseudo n'est jamais dans le jeton**, ni dans aucun stockage du navigateur. Raison : le jeton vit 30 jours, alors que les identifiants d'invité doivent disparaître 24 h après la dernière activité du salon (10 § 11.1) ; un pseudo porté par le cookie survivrait à l'archivage et rendrait fausse une durée de conservation publiée. Et le « pseudo persistant » est un avantage **du compte** (00 § Comptes & profils), pas de l'invité. Un invité ressaisit donc son pseudo à chaque nouveau salon ; la reprise d'un siège existant ne le redemande jamais, et la requête qui la porte n'exige ni ne valide aucun champ `nickname` (§ 5.8), et aucune requête d'entrée ne lit plus de champ `avatar` (D55 du 02/10 — amendé le 02/10).
- **Aucun consentement** n'est stocké pour un invité dans le jeton ; **amendé par D62 du 06/10** : le choix de la bannière vit dans le cookie `consent`, et un accord crée une ligne `visitor` (preuve : version et instant), qui relie les sièges successifs du navigateur avec l'appareil grossier (`10` § 7.1 bis). Le pseudo d'un invité survit à l'archivage, anonymisé 12 mois après la dernière activité du siège (`guest_nickname`) ; le hash du jeton tombe toujours à l'archivage.
- **Aucune adresse IP**, aucun identifiant de session : le jeton n'est pas la session PHP (I4.6), et `active_seat_token` n'est jamais l'identifiant de session (10 § 7.1).

### 2.4 Un compte connecté au J1

Un joueur connecté prend un siège comme un invité — il saisit un pseudo, et seulement un pseudo —, mais **la prise de siège écrit `player.user_id`** (I4.10, amendé le 01/10, D49 du 01/10) : c'est ce lien qui permet d'afficher son avatar téléversé (§ 11). **L'avatar est attribué automatiquement** (D55 du 02/10 — amendé le 02/10) : son image téléversée si `users.avatar_kind = upload` et qu'elle est visible, sinon un prédéfini libre (§ 11.4). Le sélecteur du **lobby** lui propose en plus « Mon avatar » quand son compte porte une image visible. Seule la prise de siège rattache : une reprise ne réécrit pas `user_id`, et le rattachement d'un siège **déjà pris** (invité qui se connecte en cours de partie) reste un sujet du J2 (§ 10), qui s'appuie sur la neutralité de la connexion (I4.6). Ni `users.name` ni `users.real_name` ne sont jamais recopiés dans `player.nickname` (I5.11, § 8.4).

---

## 3. Le `player_token` — contrat C4 [J1]

### 3.1 Noms

| Nom | Statut | Rôle |
|---|---|---|
| `App\Support\Identity\PlayerToken` | nouveau | Value object `final readonly` du jeton |
| `App\Support\Identity\PlayerTokenCookie` | nouveau, miroir de `App\Support\I18n\LocaleCookie` | Seule source des attributs du cookie, seul lecteur et seul écrivain |
| `App\Support\Identity\PlayerTokenManager` | nouveau, lié en `singleton` **sans état** | Lecture, frappe paresseuse, re-signature, résolution du siège |
| `App\Support\I18n\CookiePlayerTokenLocale` | nouveau, implémente `PlayerTokenLocale` | Niveau 3 réel (§ 4) |
| `App\Support\I18n\NullPlayerTokenLocale` | **supprimé** (§ 4.3) | — |
| `AppServiceProvider::registerLocalization()` | modifié | `bind(PlayerTokenLocale::class, CookiePlayerTokenLocale::class)` et `singleton(PlayerTokenManager::class)` |
| `LocaleController::update()` | modifié | Re-signature et mise à jour de `player.locale` (§ 4.2) |
| `App\Models\Player` | modifié | Scope `heldByToken`, `wasKicked()`, colonne `kicked_at` |

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

Aucune route nouvelle : `locale.update` existe, et les routes qui appellent `ensure()` appartiennent à leurs propriétaires (`room.store`, `room.join` à `50`, `solo.store` à `60`). Aucun événement ni canal : **le jeton ne voyage jamais sur Reverb** ; l'autorisation des canaux passe par `/broadcasting/auth`, requête HTTP ordinaire qui porte le cookie (C7 § 2.2). Clé de traduction figée ici, texte à `50` : `room.join.kicked`.

`PlayerTokenManager` est un singleton **sans état** parce que les workers de file sont des processus longs (Octane est exclu, mais pas les workers) : le jeton courant est mémorisé dans `$request->attributes[REQUEST_ATTRIBUTE]`, jamais dans une propriété de l'objet, pour qu'aucune identité ne fuie d'une requête à l'autre.

**Précisions du code livré** — amendé le 28/09 (E65-2, E65-3) :

- **« Miroir de `LocaleCookie` » vaut pour les noms** (`NAME`, `LIFETIME`, `make` / `queue` / `read`), pas pour la fabrication du cookie. `PlayerTokenCookie::make()` bâtit le `Symfony\Component\HttpFoundation\Cookie` directement, `domain` nul et échéance `Date::now()->addMinutes(LIFETIME)`, puis `queue()` le remet à `Cookie::queue()`. Raison : `Cookie::make(domain: null)`, qu'emploie `LocaleCookie`, laisse le `CookieJar` substituer `session.domain` (`SESSION_DOMAIN`) au domaine nul ; un `SESSION_DOMAIN` posé en production étendrait le jeton aux sous-domaines, contre le « hôte seul » du § 3.2. Que `LocaleCookie` hérite aujourd'hui de `SESSION_DOMAIN` relève de `05`.
- `mint()` refuse, comme `withAvatar()`, un avatar hors `AvatarPresetCatalog` (`InvalidArgumentException`). `ensure()` frappe un jeton **sans** avatar, que le geste de siège re-signe ensuite avec l'avatar choisi (I4.5) : un seul `Set-Cookie` part.
- `resign()` lève aussi une `LogicException` quand la requête ne porte **aucun** jeton : sans jeton courant, il n'y a pas de `tid` à conserver, et seul `ensure()` frappe (I4.1, I4.4).
- `current()` mémorise aussi l'**absence** de jeton dans l'attribut de requête : un seul décodage par requête.
- `PlayerToken::__debugInfo()` masque le `tid` : jamais dans une ligne de journal ni un `dump()` (§ 3.12).

### 3.2 Transport : un cookie `HttpOnly` chiffré, jamais `localStorage`

| Attribut | Valeur | Pourquoi |
|---|---|---|
| Nom | `player_token` | `PlayerTokenCookie::NAME`, seule orthographe du dépôt. |
| Chiffrement | Par `EncryptCookies` avec `APP_KEY`, préfixe de nom de cookie Laravel compris ; **jamais** dans `encryptCookies(except: …)` | Le mécanisme existe déjà : zéro ligne de cryptographie maison. Le MAC rend le jeton infalsifiable ; le préfixe lié au nom refuse une valeur copiée depuis un autre cookie. |
| `HttpOnly` | vrai | Le front n'a aucune raison de lire le jeton : le serveur lui donne tout ce qu'il affiche. Un script injecté ne peut pas le voler, ce que `localStorage` permettrait. D2 du 23/09 tranche « cookie HttpOnly chiffré » et retire `localStorage` de 00 (A-18). |
| `SameSite` | `Lax` | Un lien de salon partagé sur une messagerie est une navigation GET de premier niveau venue d'un autre site : en `Strict`, le cookie ne partirait pas au premier clic et le joueur ne retrouverait pas son siège. Les écritures restent protégées par le jeton CSRF. |
| `Secure` | `App::isProduction()` | Même règle que `LocaleCookie`. |
| `path`, `domain` | `/`, nul (hôte seul) | Aucune dépendance à `<DOMAINE>` : changer de domaine ne coûte que la perte des jetons, jamais une passkey. |
| Durée | `PlayerTokenCookie::LIFETIME`, 30 jours **glissants** (§ 3.6) | — |

C'est un cookie **strictement nécessaire** : la page de confidentialité de `90` le publie par la ligne « `player_token`, siège d'invité et reprise, langue et avatar, 30 jours après la dernière prise de siège ou le dernier changement de langue, HttpOnly, chiffré, strictement nécessaire ». Raison de l'ajout : `resign()` repose le cookie par `PlayerTokenCookie::queue()`, dont `make()` applique toujours `LIFETIME` ; un changement de langue (§ 4.2) fait donc repartir l'échéance de 30 jours pleins, et une durée publiée doit être vraie (principe 12). **Écart signalé au porteur** : C4 § 6 fige le texte « 30 jours après la dernière prise de siège » ; 90 § 4.6 publie déjà la ligne ci-dessus ; seul C4 § 6 reste à aligner.

### 3.3 Charge utile

JSON, chiffré puis authentifié :

| Revendication | Type | Contenu |
|---|---|---|
| `v` | int | `PlayerToken::VERSION`, vaut `1` |
| `tid` | string | 64 caractères hexadécimaux minuscules, `bin2hex(random_bytes(32))` |
| `locale` | `"en"` \| `"fr"` \| `null` | Locale effective de la dernière prise de siège ou du dernier changement de langue |
| `avatar` | `"preset-NN"` \| `null` | Dernier prédéfini attribué à la prise de siège ou choisi au lobby (D55 du 02/10 — amendé le 02/10) |

Exemple de charge **en clair**, avant chiffrement : `{"v":1,"tid":"9f2c…e41a","locale":"fr","avatar":"preset-07"}`. Ce qui voyage est illisible.

**Ce que la charge ne porte jamais** : le pseudo (§ 2.3), le siège, le salon, un consentement, un identifiant de compte. Le `tid` est tiré au CSPRNG sur 256 bits, **jamais** un ULID ni un horodatage : un identifiant trié dans le temps rendrait deux jetons frappés à la même seconde corrélables (même raison que 10 § 1.1 pour les chemins de `frame`).

**Au décodage** (`PlayerTokenCookie::read()` puis `PlayerToken::fromClaims()`) : `json_decode` en profondeur 4 avec `JSON_THROW_ON_ERROR` capturé ; `v` doit valoir `VERSION` et `tid` suivre `TID_PATTERN`, sinon le jeton est absent ; `v` est comparé en entier strict (un `v` flottant ou en chaîne rend le jeton absent) ; une revendication de mauvais type rend le jeton absent, mais une revendication `locale` ou `avatar` **absente** vaut `null` (présente, elle doit être `string|null`) — amendé le 28/09 (E65-3) ; une `locale` de bon type mais inconnue de `Locale::tryFrom()` devient `null`, un `avatar` hors `AvatarPresetCatalog` devient `null`, **et le jeton reste valide**. Retirer une langue ou changer de pack d'avatars ne détruit aucun siège.

### 3.4 Frappe paresseuse et idempotence

- **I4.1 Frappe paresseuse.** Seul `ensure()` frappe un jeton, et seuls l'appellent les gestes qui prennent ou reprennent un siège : `room.store`, `room.join`, `solo.store`. Un GET (accueil, lien de salon), `locale.update` et la connexion n'en frappent jamais. Raison : minimisation — aucun identifiant n'est posé sur un visiteur qui n'a fait aucun geste de jeu.
- **I4.2 Idempotence par requête.** Le jeton courant est mémorisé dans l'attribut de requête. Plusieurs `ensure()` et `current()` dans une même requête rendent le même jeton, et **un seul** `Set-Cookie` part : `Cookie::queue` d'un même nom et d'un même chemin remplace le précédent. « Même requête » couvre la `FormRequest` : `FormRequest::createFrom()` copie `attributes` **par valeur**, et un mémo posé sur la seule copie divergerait de `request()`, que lisent `SetLocale` et la garde `player` — deux `tid` frappés, un seul `Set-Cookie`, un siège écrit sous le jeton orphelin. `PlayerTokenManager` écrit donc le mémo sur la requête de base (`app('request')`) **et** sur la copie quand il reçoit une `FormRequest`, et lit d'abord la base — amendé le 28/09 (E65-3).
- `current()` ne frappe jamais et ne repose jamais le cookie : c'est ce qui permet à `/f/{serveToken}`, qui n'émet jamais de `Set-Cookie` (contrat C8, E10-59), de lire le jeton sans rien écrire.
- **Frappe après les refus.** Dans un geste de siège, `ensure()` n'est appelé qu'une fois tous les refus écartés (§ 2.1, étape 4) : un geste refusé ne pose aucun identifiant.
- **Résidu assumé : deux gestes concurrents d'un navigateur sans jeton.** I4.2 ne garantit l'idempotence que **par requête**. Deux envois simultanés d'un navigateur qui n'a pas encore de jeton — deux onglets, sous deux pseudos différents — frappent deux `tid` ; le dernier `Set-Cookie` reçu l'emporte, et le siège de l'autre requête devient inaccessible : son pseudo reste réservé jusqu'à l'archivage (I5.4), sa présence est retirée par le délai de déconnexion de `60`. Sous le **même** pseudo (envoi doublé), la seconde requête est refusée `taken` sous le verrou du salon et, les refus précédant `ensure()`, ne frappe rien : le premier jeton reste seul. Aucun verrou n'est ajouté pour le premier cas : l'écran désactive l'envoi pendant l'état `processing` de `<Form>` (§ 9, ligne `90`), et le remède reste l'expulsion par l'hôte, comme pour la fenêtre privée (§ 1.3).

### 3.5 Hash et invariance du siège

- **I4.3 Hash.** `player.player_token_hash = hash('sha256', tid)`, calculé sur la chaîne hexadécimale ASCII (E10-33). **On ne hache jamais la valeur chiffrée du cookie**, qui change à chaque re-signature (vecteur d'initialisation neuf). Un SHA-256 sans sel suffit : le `tid` porte 256 bits d'aléa, une colonne `player_token_hash` qui fuiterait ne rend aucun jeton. Le hash est posé à la création du siège et effacé à l'archivage (10 § 11.1).
- **I4.4 Invariance.** Aucun chemin ne change le `tid` : `withLocale` et `withAvatar` le conservent, et `resign()` lève une `LogicException` si le `tid` diffère de celui de `current()`. Un jeton = un siège par salon (`player_room_token_uq`) ; en solo, la reprise se fait par `player_token_idx` (règle de `60`).

### 3.6 Durée et glissement

- **I4.5.** Chaque `ensure()` repose le cookie avec 30 jours pleins et **réaligne** la revendication `locale` sur la locale effective de la requête, résolue par `SetLocale`. Toute écriture de `player.avatar_preset` par un geste du joueur re-signe le jeton avec cet avatar.
- **Toute re-signature fait aussi glisser l'échéance.** `resign()` repose le cookie par `PlayerTokenCookie::queue()`, donc avec 30 jours pleins : un changement de langue (§ 4.2) prolonge le jeton comme une prise de siège. C'est ce que publie la ligne du § 3.2.
- **Pourquoi 30 jours.** La durée couvre la reprise d'un siège pendant toute la vie d'un salon (archivage à 24 h, filet `stale_room` à 48 h, 10 § 11.1), le rattachement tardif du J2 et la mémoire du dernier avatar choisi d'un salon à l'autre, sans porter aucune donnée personnelle. Ce n'est **pas une valeur de jeu** (règle 2) : c'est une constante de transport, `PlayerTokenCookie::LIFETIME`, sur le précédent de `LocaleCookie::LIFETIME`, que `90` publie.

### 3.7 Jeton invalide : absent, sans bruit

**I4.7.** Sont traités comme **absents**, silencieusement, sans erreur ni ligne de journal : un MAC faux, une valeur venant d'un autre cookie ou d'une autre clé, un JSON illisible, un `v` inconnu, un `tid` hors motif. Le geste de siège suivant frappe un jeton neuf. Raison : un jeton invalide n'est jamais une attaque qu'on aurait intérêt à signaler au client, et le journaliser remplirait les journaux à chaque rotation de clé.

**Versions.** Toute version N+1 de la charge relit la version N tant qu'un siège frappé sous N peut exister, soit au moins 48 h (filet `stale_room`). Un changement de charge incrémente `PlayerToken::VERSION` ; il ne touche aucune colonne.

### 3.8 Connexion neutre

**I4.6.** Connexion, déconnexion, inscription, passkey et invalidation de session **ne lisent ni n'écrivent** le cookie `player_token` : l'identité d'un siège ne passe pas par la session PHP (00 § Cycle de vie : « pas par la session PHP »). C'est ce qui garantira au J2 qu'une connexion sociale ouverte dans un autre onglet ne crée jamais un second siège.

### 3.9 Résolution du siège par tous les consommateurs

Garde et canaux de `60`, route `/f/`, resynchronisation, middleware `seat.active` et soumission de `70` identifient le siège **exclusivement** par `PlayerTokenManager::current($request)?->hash()`, **en excluant toujours `kicked_at` non nul** (I4.9, contrat C7 § 2.2) :

| Contexte | Forme |
|---|---|
| Un salon donné | `seatIn($request, $room)` |
| Appartenance à une partie (`/f/`), siège solo, `seat.active` | `Player::heldByToken($token)` combiné à `whereNull('kicked_at')` |

Aucun autre identifiant ne sert, ni l'adresse IP, ni la session, ni un `public_id` fourni par le client sans le jeton qui le tient. `Broadcast::routes()` et `/f/` restent sous une pile qui contient `EncryptCookies`, sans quoi le cookie arriverait chiffré et le jeton serait lu comme absent.

### 3.10 Expulsion : jeton refusé (D15 du 23/09)

**I4.9.** Le geste d'expulsion (propriété de `50`) s'exécute dans une transaction sous `lockForUpdate` du salon :

- `connection_state = left`, `left_at = kicked_at =` le même instant serveur, en millisecondes (E10-01) ;
- si une partie tourne, `game_player.status = kicked`, points conservés.

Ensuite :

- `seatIn()` rend `null` pour ce siège et `wasKickedFrom()` rend `true` ;
- la prise de siège refuse avec `room.join.kicked` **avant** tout comptage de capacité et **jamais** par une erreur 1062 d'index unique ;
- `kicked_at` n'est **jamais** remis à `NULL` : pas de réadmission ;
- le refus tombe de lui-même à l'archivage, qui efface le hash ;
- un second geste d'expulsion ne fait rien ;
- le même jeton prend librement un siège dans **un autre** salon.

**Conséquence voulue** : l'unicité du pseudo porte aussi sur les sièges expulsés (§ 5.6), donc le pseudo de l'expulsé reste indisponible dans ce salon jusqu'à l'archivage, même sous un autre jeton. **Résidu assumé** : un expulsé qui revient en fenêtre privée prend un nouveau siège sous un autre pseudo (§ 1.3) ; le remède est une nouvelle expulsion par l'hôte.

### 3.11 Clé et rotation

**I4.11.** Une rotation d'`APP_KEY` invalide **tous** les jetons, donc tous les sièges d'invités en cours. Elle se fait seulement avec `APP_PREVIOUS_KEYS` renseignée (lecture des anciens jetons pendant la transition) et **hors partie en cours**, par le drainage de D32 du 23/09 (contrat C18-bis). La procédure appartient à `100`.

### 3.12 Ce qui ne quitte jamais le serveur

`tid`, `hash()`, `player_token_hash`, `active_seat_token` (sauf vers l'onglet qui vient de le frapper, en prop `seatToken`, C7) et `player.id`. Ils n'apparaissent dans aucune prop Inertia partagée, aucun message Reverb diffusé ou ciblé, aucune URL et aucune ligne de journal. Le client adresse un siège par `public_id` seulement (10 § 1.1).

### 3.13 Provenance des valeurs

| Valeur | Provenance |
|---|---|
| `tid` sur 32 octets | `PlayerToken::TID_BYTES`, constante de format, CSPRNG `random_bytes` |
| `v` | `PlayerToken::VERSION`, constante de version |
| 30 jours | `PlayerTokenCookie::LIFETIME`, constante de transport, publiée par `90` |
| Chiffrement et MAC | `EncryptCookies`, `config('app.key')`, `app.previous_keys` |
| `Secure` | `App::isProduction()` |
| `locale` | Locale effective de la requête (`SetLocale`, ordre de `05`) |
| `avatar` | Dernier prédéfini attribué ou choisi (D55 du 02/10), domaine `AvatarPresetCatalog::keys()` |
| `kicked_at` | Horloge serveur, à l'instant du geste |

Aucune valeur ne vient de `room_settings`, et aucune valeur de jeu n'est touchée (règle 2).

---

## 4. La langue portée par le jeton — `PlayerTokenLocale` [J1]

### 4.1 Niveau 3 réel

`CookiePlayerTokenLocale::fromRequest($request)` rend `current($request)?->locale` du `PlayerTokenManager` — reçu par injection de constructeur (classe `final readonly`) plutôt que par `app(PlayerTokenManager::class)` : même singleton sans état, même lecture, aucune lecture directe du cookie (`PlayerTokenBoundaryTest`) — amendé le 28/09 (E66-1). Le middleware `SetLocale` ne change pas : seule la liaison du conteneur bascule, comme le docblock de `PlayerTokenLocale` le prévoyait ; son test gagne deux cas (lot L40-2). Le niveau 3 de `05` couvre désormais exactement un cas : **cookie `locale` absent ou expiré alors que le jeton est présent** (A-38). Il ne couvre pas un autre navigateur, où le jeton est absent lui aussi. `SetLocale` s'exécute après `EncryptCookies` dans le groupe `web` : le jeton y est déjà déchiffré, et sa lecture mémorisée resservira à `ensure()` dans la même requête.

### 4.2 Changement de langue

**I4.8.** `LocaleController::update()` garde son comportement actuel (écrire `users.locale` si connecté, reposer le cookie `locale`, `back()`) et y ajoute, **si un jeton existe** :

1. re-signature par `resign($request, $token->withLocale($locale))`, même `tid` ;
2. `Player::query()->heldByToken($token)->holdingSeat()->update(['locale' => $locale->value])`, **par le constructeur de requêtes Eloquent**, jamais `DB::table()` (10 § 1.2 : les colonnes `timestamp(3)` de `player` perdraient leurs millisecondes).

Sans jeton, **aucune frappe** (I4.1). Le geste est idempotent **sur l'état** (`tid`, revendication, sièges), pas sur le `Set-Cookie` : la re-signature a lieu à chaque appel dès qu'un jeton existe, même quand la langue ne change pas, si bien que l'échéance glisse de 30 jours à chaque geste de langue (§ 3.6). Ordre livré dans `update()` : compte, puis — si un jeton existe — re-signature puis sièges, puis cookie `locale`, puis `back()` ; `->value` est écrit explicitement, valeur identique à l'enum que le constructeur de requêtes convertirait — amendé le 28/09 (E66-1). **Aucun état de jeu n'est touché**, et **aucun QCM n'est recomposé** : les quatre chaînes déjà composées sont rejouées dans leur langue de composition (`05`, contrat C11). Les sièges expulsés sont `left` et les sièges archivés n'ont plus de hash : ni les uns ni les autres ne sont touchés.

### 4.3 `NullPlayerTokenLocale` supprimée

L'implémentation neutre n'a plus aucun usage une fois la liaison basculée ; la garder serait du code mort qu'un futur lecteur relierait par erreur. Elle est supprimée dans le lot L40-2, et le docblock de `PlayerTokenLocale` est corrigé : la forme du jeton appartient à `40` [J1], `10` ne garde que le hash (E10-11).

---

## 5. Le pseudo — contrat C5, première moitié [J1]

### 5.1 Noms

| Nom | Statut |
|---|---|
| `App\Rules\ValidNickname implements Illuminate\Contracts\Validation\ValidationRule` | nouveau (dossier `app/Rules/` nouveau) |
| `App\Concerns\PlayerIdentityValidationRules` (trait, modèle : `ProfileValidationRules`) | nouveau |
| `App\Support\Identity\NicknameNormalizer` | nouveau |
| `App\Support\Identity\NicknameBlocklist` | nouveau |
| `resources/moderation/nicknames/{en,fr}.txt`, `reserved.txt` | nouveaux, un fichier par cas de `Locale` plus les noms réservés |

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
    /** @return array<int, mixed> */                 protected function seatAvatarRules(?User $user): array;  // ['required', 'string', Rule::in(keys() + 'account' si image visible)] — changement au lobby seul ; aucun formulaire d'entrée ne porte d'avatar (D55 du 02/10)
    protected function prepareNickname(mixed $raw): mixed;  // is_string ? NicknameNormalizer::canonical($raw) : $raw
}
```

### 5.2 Forme canonique — ce qui s'affiche

**I5.1.** `canonical()` applique, dans cet ordre : normalisation **NFC** (`\Normalizer::FORM_C`, polyfill Symfony, sans `ext-intl`) ; `trim()` aux extrémités ; toute suite d'espaces U+0020 intérieure réduite à une seule. C'est la forme stockée dans `player.nickname`, **accents et casse conservés** : « Zoé » s'affiche « Zoé ». Une entrée de plus de `MAX_RAW_BYTES` octets n'est pas transformée et échoue **sur la longueur** (étape 1 du § 5.4 : 256 octets d'UTF-8 font au moins 64 caractères, au-delà de `MAX_LENGTH`). Une entrée qui n'est pas de l'UTF-8 valide n'est pas transformée non plus : elle échoue sur `length` si `mb_strlen` la place hors de [`MIN_LENGTH`, `MAX_LENGTH`], sinon sur `characters` (étape 2), **jamais** sur `script` — ses octets invalides ne sont ni une lettre ni un chiffre, et I5.2 exige un message unique et déterminé.

La NFC d'abord, parce qu'un « é » saisi sur certains claviers mobiles arrive décomposé (`e` + U+0301) : sans composition, la marque combinante tomberait hors des écritures admises et un pseudo parfaitement français serait refusé. L'intergiciel `TrimStrings` du framework a déjà coupé les blancs Unicode des extrémités ; seules les espaces intérieures restent à traiter.

### 5.3 Écritures admises : l'alphabet latin seul (D26 du 23/09)

Sont admis : les lettres latines du latin de base, du **Latin-1 supplément** (U+00C0–U+00FF sauf `×` et `÷`, plus les indicateurs ordinaux `ª` et `º`, lettres de script latin) et du **Latin étendu-A** (U+0100–U+017F), les chiffres ASCII, l'espace, `-` et `_`. Tout le reste est refusé avec un message traduit : kana, kanji, cyrillique, grec, hangul, lettres pleine chasse, `µ` (lettre, mais pas de script latin : `\p{Latin}` est faux pour elle), émojis, symboles, caractères invisibles et de contrôle, marques combinantes qui ne se composent pas, espaces autres qu'U+0020.

**Pourquoi l'alphabet latin seul.** Trois raisons, dans l'ordre :

1. **La liste noire ne couvre que ce qu'elle peut lire.** Elle existe par langue activée (00, étape 2 du déroulé ; `05`), et FR comme EN s'écrivent en latin. Un pseudo en cyrillique ou en kanji échapperait à tout filtrage, sur un site public et indexé au J2.
2. **Les homoglyphes tombent d'eux-mêmes.** « Bоb » avec un `о` cyrillique, ou « аdmin » avec un `а` cyrillique, sont refusés par le message d'écriture avant même la liste noire : c'est la seule défense portable contre l'usurpation visuelle, `Spoofchecker` exigeant `ext-intl`.
3. **La règle est vérifiable sans extension** : une classe de caractères PCRE en `/u`, vérifiée dans le dépôt.

Le coût est assumé et nommé : un joueur qui voudrait un pseudo en kana est refusé, alors que le catalogue fait entrer le cinéma japonais. Ouvrir une écriture se fera **avec l'ajout de sa langue** (procédure de `05` § Ajouter une troisième langue, étape 4 : fournir la liste noire), sans refonte.

### 5.4 Ordre de validation

**I5.2.** Un seul message par envoi ; **le premier échec l'emporte** :

1. `mb_strlen(canonique)` hors de [`MIN_LENGTH`, `MAX_LENGTH`] → `length` ;
2. au moins un caractère hors de `ALLOWED_PATTERN` : si **l'un** des caractères refusés est une lettre ou un chiffre (`\p{L}` ou `\p{N}`), → `script` ; sinon → `characters`. Le message d'écriture l'emporte parce qu'il dit au joueur quoi faire. Le motif est appliqué **à un caractère à la fois**, jamais à la chaîne entière : sans modificateur `D`, son `$` s'ancre aussi devant un `\n` final, et la règle nue accepterait `"Bob\n"` (latent sur le chemin du § 5.8, où `TrimStrings` et `canonical()` rognent ce `\n`, réel pour tout appel direct de `new ValidNickname`) — amendé le 28/09 (E67-8) ;
3. `normalize(canonique)` vide → `alnum` ;
4. `strlen(normalize(canonique)) > MAX_LENGTH` → `normalized_length`, **jamais** une erreur 1406 de MySQL ;
5. `NicknameBlocklist::blocks(canonique)` → `blocked`, sans jamais citer le mot ;
6. unicité dans le salon → `taken`, émise par `50` sous le verrou du salon (§ 5.6), jamais par la règle.

Exemples, bornes par défaut de `NicknameNormalizer` :

| Saisie | Verdict | Forme normalisée |
|---|---|---|
| `Zoé` | accepté, affiché `Zoé` | `zoe` |
| `Jean-Luc`, `jean luc`, `JEAN_LUC` | acceptés, **en collision entre eux** dans un même salon | `jeanluc` |
| `  Le   Boss ` | accepté, affiché `Le Boss` | `leboss`, distinct de `boss` |
| `A` | `length` | — |
| `Bоb` (U+043E cyrillique) | `script` | — |
| `Ｂｏｂ` (pleine chasse) | `script` | — |
| `Bob🎬`, `Bob` + U+200B intérieur | `characters` | — |
| `--__` | `alnum` | — |
| vingt `ß` | `normalized_length` : quarante caractères une fois repliés | — |
| `admin` | `blocked` (nom réservé) | — |

**Précisions du code livré** — amendé le 28/09 (E67-3) : la règle valide la valeur **telle qu'elle la reçoit**, sans recomposer — c'est la FormRequest qui prépare (§ 5.8) ; une FormRequest qui oublierait `prepareNickname()` verrait un accent décomposé refusé (`characters`), au lieu d'écrire une forme que la règle n'a pas vue. L'étape 1 teste aussi `strlen > MAX_RAW_BYTES` explicitement, sans dépendre du comptage de `mb_strlen` sur de l'UTF-8 invalide. Une valeur qui n'est pas une chaîne est laissée à la règle `string` qui précède (un seul message, I5.2). Un pseudo fait d'**espaces seules** n'atteint pas la règle : `TrimStrings` le rogne à vide et `ConvertEmptyStringsToNull` le rend nul, si bien que `required` le refuse seul ; `alnum` ne voit que les saisies qui mêlent espaces, tirets et soulignés (`--__`).

### 5.5 Forme normalisée — `NicknameNormalizer` sur `fold()`

**I5.3.** `normalize()` = `AnswerKeyNormalizer::fold()` de `70` (contrat C12), puis suppression de tout ce qui n'est pas `[a-z0-9]` : espaces, `-`, `_` et résidus de translittération. **Aucun retrait d'article, aucune conversion de chiffres.** La fonction est pure, déterministe et idempotente. C'est elle, et elle seule, qui écrit `player.nickname_normalized` (E10-32) — jamais `AnswerKeyNormalizer::normalize()`, ni `fold()` seul.

**Pourquoi un normaliseur dédié plutôt que celui des réponses.** L'unicité d'un pseudo sert à **distinguer visuellement deux joueurs**, pas à accepter une réponse. Le normaliseur des réponses retire un article de tête et convertit les chiffres romains : « Le Boss », « the_boss » et « Boss » y deviendraient tous `boss`, et trois joueurs distincts se verraient refuser leur pseudo. Et il **peut allonger** la chaîne (`ß` → `ss`, `œ` → `oe`) : un pseudo de 20 caractères dépasserait `string(20)` et lèverait une 1406 en MySQL strict, invisible en SQLite. D'où l'étape 4 du § 5.4, qui refuse avec un message traduit.

**Pourquoi retirer `-` et `_`.** « Jean-Luc » et « jean luc » sont le même nom affiché à l'œil : les laisser coexister dans un salon ferait deux joueurs indiscernables au classement.

### 5.6 Unicité par salon et non-rétroactivité

- **I5.4.** L'unicité porte sur **tous** les sièges du salon, **partis et expulsés compris**, jusqu'à l'archivage : c'est la portée réelle de l'index `player_room_nickname_uq (room_id, nickname_normalized)` (E10-35). `50` la vérifie sous le verrou du salon, **après** la tentative de reprise ; une `UniqueConstraintViolationException` résiduelle est traduite en `taken`, jamais en 1062 brute. Aucune unicité en solo.
- **I5.5.** La règle s'applique à la **création** d'un siège et à toute réécriture de pseudo que `50` autoriserait. Un siège repris n'est **jamais** revalidé : une liste noire enrichie par un commit n'éjecte personne d'un salon en cours.

### 5.7 La liste noire

**I5.6.** Ressource **versionnée**, aucune table (10 § A15). Elle applique **l'union** des fichiers de toutes les locales activées et de `reserved.txt`, **quelle que soit la langue du joueur** : un pseudo s'affiche à tous les joueurs du salon, dans toutes leurs langues, comme une bonne réponse est acceptée dans toutes les langues activées.

**Fichiers.** `resources/moderation/nicknames/{en,fr}.txt` et `reserved.txt` : UTF-8, LF, une entrée par ligne, `#` en début de ligne pour les commentaires. **En-tête obligatoire**, vérifié par test : `# source:`, `# license:`, `# retrieved: AAAA-MM-JJ` ; plus `# changes:` dès qu'une entrée importée est retirée ou ajoutée. `NicknameBlocklist::files()` rend un chemin par cas de `Locale` plus `reserved` ; **un fichier manquant pour une locale activée lève une exception bruyante** — ajouter une langue sans sa liste noire doit casser, pas passer. Code livré — amendé le 28/09 (E68-3) : `reserved.txt` manquant lève de même (`RuntimeException` qui nomme le fichier, levée aussi par `blocks()` : une validation échoue en 500, jamais en acceptation silencieuse) ; une entrée est une ligne non vide qui ne commence pas par `#` en colonne 0, compilée par `normalize(canonical($ligne))` ; une entrée qui se replie à vide (l'émoji final de la liste anglaise) est ignorée, puisque vide elle serait sous-chaîne de tout ; la liste compilée est mémorisée par répertoire (`resource_path(DIRECTORY)`), sans méthode publique hors contrat.

**Sources retenues.**

| Fichier | Source | Licence | Conséquence |
|---|---|---|---|
| `en.txt`, `fr.txt` | Listes anglaise et française du projet « List of Dirty, Naughty, Obscene, and Otherwise Bad Words » (LDNOOBW, publié par Shutterstock) ; URL et empreinte de l'archive dans l'en-tête du fichier | CC BY 4.0, **à revérifier au téléchargement** — revérifiée le 25/09 par L40-4 sur l'archive figée au commit `5faf2ba4…` (fichier `LICENSE`, champ `license` de l'API GitHub, README) ; attribution « © 2012–2020 Shutterstock, Inc. », années comprises — amendé le 28/09 (E68-1) | L'attribution est due : `100` l'inscrit au relevé des licences tierces. Toute retouche d'entrée est déclarée par `# changes:`, comme la licence l'exige ; `# changes:` figure dans chaque fichier importé même sans retrait, l'en-tête ajouté étant déjà une modification à déclarer (CC BY 4.0 § 3(a)(1)(B)). |
| `reserved.txt` | Rédigée pour le projet | Tous droits réservés, comme le dépôt | — |

`reserved.txt` contient au minimum : `admin`, `administrateur`, `administrator`, `moderateur`, `moderator`, `modo`, `curateur`, `curator`, `system`, `systeme`, `support`, `staff`, `officiel`, `official`, `tripleframes`, `hote`, `host` (C5) ; `40` y ajoute `moderatrice`, `curatrice`, `tmdb` et `bot`. Raison : un pseudo qui se présente comme l'équipe du site ou comme la source des données est une usurpation, que le jeu ait ou non une modération.

**Entrées importées retirées au J1** — amendé le 28/09 (E68-2, E68-5). Critère appliqué, dans la liberté que C5 § 8 laisse à `40` (« le contenu et les sources des listes noires ») : une entrée est retirée si, **prise seule, elle n'offense pas** **et** si sa comparaison en sous-chaîne ou sa forme réduite refuse des pseudos courants ; une entrée injurieuse, sexuelle ou scatologique reste, même avec des faux positifs (résidu assumé ci-dessous). Retirées et déclarées par `# changes:` : « péter », « bourré », « bourrée » de `fr.txt` ; « cialis », « girl on », « hard core », « hardcore » de `en.txt`. « xx » et « xxx » restent (« xxx » offense pris seul ; le faux positif « Billy X » par la forme réduite `x` est le résidu que ce paragraphe assume). Validation de ces retraits, retrait éventuel de « xx » et « xxx » et relecture des résidus gardés : posés au porteur (« Ce que cette spec ne décide pas », E68-2) ; tout ajustement est une édition des fichiers et de `# changes:`, sans code.

**Compilation.** Chaque entrée est compilée par `NicknameNormalizer::normalize()`, puis mémorisée sous **deux formes** : brute `k`, et réduite `ρ(k)`, où `ρ` = `preg_replace('/([a-z])\1+/', '$1', …)` réduit toute lettre répétée à une seule. La liste compilée est mémorisée pour la durée du processus ; les fichiers sont lus à la première validation.

**Algorithme de `blocks($canonical)`.** Soit `c = NicknameNormalizer::normalize($canonical)` (forme compacte) et `f = AnswerKeyNormalizer::fold($canonical)` (forme repliée, séparateurs conservés). Pour chaque transformation `L` ∈ {identité, `LEET_PRIMARY`, `LEET_SECONDARY`} (substitution des chiffres), deux comparaisons :

- **(a) forme brute, toujours** : `c' = L(c)` et `t' = preg_split('/[^a-z]+/', L(f), -1, PREG_SPLIT_NO_EMPTY)`, comparés aux seules entrées brutes `k` ;
- **(b) forme réduite, seulement si `ρ(L(c)) ≠ L(c)`**, c'est-à-dire si la saisie contient réellement une lettre répétée : `c' = ρ(L(c))` et `t'` = jetons de `ρ(L(f))`, comparés aux seules entrées réduites `ρ(k)`.

Dans chaque comparaison, `blocks()` est vrai s'il existe une entrée `e` — `k` en (a), `ρ(k)` en (b) — telle que :
  - `strlen(e) ≥ SUBSTRING_MIN_LENGTH` **et** `e` est une sous-chaîne de `c'` ; **ou**
  - `e` est l'un des jetons de `t'` ; **ou**
  - `e === c'`.

La transformation « identité », la comparaison réduite (b) et sa condition sont des **ajouts** de `40` aux transformations de `blocks()` de C5, que C5 § 8 autorise (l'ajout, jamais le retrait). Aucune comparaison de C5 n'est retirée : celle d'une saisie réduite à une entrée brute est incluse dans (b) quand la saisie a une lettre répétée (une entrée sans lettre double vaut sa forme réduite, une entrée à lettre double ne peut apparaître dans une chaîne réduite), et se confond avec (a) sinon. Pourquoi chaque ajout :

- **sans l'identité**, une entrée qui contient un chiffre ne serait jamais reconnue, la substitution leet la transformant toujours ;
- **sans la forme réduite des entrées**, une saisie aux lettres répétées, une fois réduite, ne retrouverait plus une entrée qui porte elle-même une lettre double : « Booob » ne retrouverait pas `boob` ;
- **sans la condition de (b)**, toute saisie serait comparée aux formes réduites, et des pseudos courants tomberaient sur la réduction d'une entrée des listes retenues : « Bob » sur `ρ(boob)` = `bob`, « As » sur `ρ(ass)` = `as`, « Château » sur `ρ(chatte)` = `chate`, sous-chaîne de `chateau`. La forme réduite n'est cherchée que lorsque la saisie elle-même en montre le motif.

**Effet visé et limites.** Une entrée longue est reconnue n'importe où, y compris en leet, en lettres répétées ou en lettres séparées par des espaces ; une entrée courte ne l'est que comme **mot entier**, ce qui protège « Conan » et « Leçon » d'une entrée de trois lettres de la liste française (effet Scunthorpe). Une saisie sans lettre répétée n'est jamais comparée à la forme réduite d'une entrée : « Bob », « As » et « Château » sont acceptés ; « Booob » et « Chaatte » sont refusés. **Résidus assumés, nommés pour ne pas être découverts** : une entrée courte collée à un autre mot passe ; une entrée longue produit des faux positifs (« Badminton » contient `admin`, « Supporter » contient `support`) ; une saisie à lettre répétée sans rapport avec l'entrée peut tomber sur une forme réduite (« Châteauu » se réduit en `chateau`, qui contient `chate`) — dans chaque cas, le message reste neutre et le joueur choisit un autre pseudo. `SUBSTRING_MIN_LENGTH` vaut 5 ; il est ajustable **avant implémentation**, avec exemples testés, jamais en configuration (précédent : `LEADING_ARTICLES` de `70`). Contourner la liste reste possible ; le signalement et le masquage du J2 (§ 10) sont le second filet.

### 5.8 Intégration aux FormRequest

Champs des formulaires d'entrée de `50` et `60` : `nickname` (chaîne) seul — le champ `avatar` en est retiré, et ignoré s'il est envoyé (D55 du 02/10 — amendé le 02/10). Chaque FormRequest utilise le trait `PlayerIdentityValidationRules`, appelle `$this->merge(['nickname' => $this->prepareNickname($this->input('nickname'))])` dans `prepareForValidation()`, puis applique `nicknameRules()`. Le champ `avatar` (clé du catalogue ou `account`) ne vit plus que dans `ChangeSeatAvatarRequest`, la requête du geste du lobby (`50` § 8.1), qui applique `seatAvatarRules()`. La forme canonique est donc ce que valide la règle **et** ce qu'écrit l'action : aucune seconde normalisation dans un contrôleur. `:attribute` est résolu par le validateur depuis `validation.attributes.nickname`.

**Reprise.** La validation précède l'action : une FormRequest qui exigerait toujours `nickname` ferait ressaisir à un joueur de retour un pseudo que l'action ignore ensuite, et le refuserait si ce pseudo était entré depuis dans la liste noire — contre I5.5. Donc :

- la FormRequest de `room.join` (`50`) n'applique `nicknameRules()` que si `PlayerTokenManager::seatIn($request, $room)` est nul : les deux listes sont préfixées par `Rule::excludeIf(…)`, dont la fermeture teste ce siège — et, ajout de `50` à la livraison, un **salon archivé**, que la prise de siège refuse avant tout champ en renvoyant le visiteur, sans erreur, vers « salon expiré » (amendé le 28/09, E101-5). Il en va de même pour `solo.store` (`60`) lorsque le jeton tient un siège solo repris selon la règle de `60`. `room.store` crée un salon neuf, où aucun siège n'existe : sa règle s'applique toujours ;
- quand un siège existe, le champ `nickname` envoyé est ignoré : rien n'est revalidé ni réécrit (§ 2.2, « À la reprise »). C'est le cas d'un visiteur déjà assis qui poste code et pseudo depuis l'accueil : il est repris sous son ancien pseudo (D55 du 02/10). `ensure()` fait glisser le cookie et réaligne la revendication `locale` (I4.5) ; la revendication `avatar` n'est pas touchée, puisque la reprise n'écrit pas `avatar_preset` (seul le geste du lobby le réécrit, et re-signe) — amendé le 02/10 ;
- la page d'entrée (GET) d'un porteur de siège ne rend pas le formulaire : elle mène au lobby (propriété de `50`).

Ces trois points n'ajoutent aucun nom au trait `PlayerIdentityValidationRules` (contrat C5) : l'exclusion vit dans la FormRequest de son propriétaire.

### 5.9 Les sept messages

Textes rédigés par `40` (contrat C15 § 2.4) ; placeholders contractuels, symétriques FR/EN (`05` § Couverture des clés).

| Clé | Français | Anglais |
|---|---|---|
| `validation.nickname.length` | Le :attribute doit compter entre :min et :max caractères. | The :attribute must be between :min and :max characters. |
| `validation.nickname.script` | Le :attribute ne peut utiliser que l’alphabet latin, accents compris, des chiffres, des espaces, « - » et « _ ». | The :attribute may only use Latin letters (accents included), digits, spaces, "-" and "_". |
| `validation.nickname.characters` | Ce pseudo contient un caractère non autorisé : symbole, émoji ou caractère invisible. | This nickname contains a character that is not allowed: a symbol, an emoji or an invisible character. |
| `validation.nickname.alnum` | Le pseudo doit contenir au moins une lettre ou un chiffre. | The nickname must contain at least one letter or digit. |
| `validation.nickname.normalized_length` | Ce pseudo est trop long une fois ses lettres spéciales développées (ß, æ, œ…). Raccourcissez-le. | This nickname is too long once its special letters are expanded (ß, æ, œ…). Please shorten it. |
| `validation.nickname.blocked` | Ce pseudo n’est pas disponible. Choisissez-en un autre. | This nickname is not available. Please choose another one. |
| `validation.nickname.taken` | Ce pseudo est déjà pris dans ce salon. | This nickname is already taken in this room. |
| `validation.attributes.avatar` | avatar | avatar |

`blocked` ne cite jamais le mot et ne distingue jamais un nom réservé d'une grossièreté : le dire enseignerait la liste.

Les textes FR de `script` et `blocked` portent l'apostrophe typographique `’` (« l’alphabet », « n’est »), convention de tout `lang/fr/validation.php` ; texte inchangé par ailleurs — amendé le 28/09 (E67-3).

### 5.10 Provenance des valeurs

| Valeur | Provenance |
|---|---|
| 2 et 20 | `NicknameNormalizer::MIN_LENGTH` / `MAX_LENGTH`, constantes **de schéma** liées à `player.nickname` et `nickname_normalized` `string(20)` (10 § 7.1). Ce ne sont pas des réglages de jeu. |
| 256 octets | `NicknameNormalizer::MAX_RAW_BYTES`, garde |
| Écritures admises | `ValidNickname::ALLOWED_PATTERN` (D26 du 23/09) |
| Liste noire | Fichiers versionnés `resources/moderation/nicknames/*.txt` |
| Seuil de sous-chaîne, tables leet | Constantes d'algorithme de `NicknameBlocklist`, jamais en configuration |

---

## 6. Les avatars prédéfinis — contrat C5, seconde moitié [J1]

### 6.1 Le pack (D27 du 23/09)

Têtes d'animaux du pack **« Animal Pack Remastered » de Kenney** — nommé « Animal Pack Redux » à la rédaction : le 25/09, l'adresse `kenney.nl/assets/animal-pack-redux` répond 404, le pack aux trente têtes rondes décrit ici est publié sous le nom « Animal Pack Remastered » et le `License.txt` de l'archive porte ce nom ; l'« Animal Pack » de 2015, dix sujets, est un autre pack, non utilisé — amendé le 28/09 (E63-1) —, sous licence **CC0 1.0**, **à revérifier au téléchargement** (revérifiée le 25/09 par L40-5 ; la vérification du porteur, nom et licence, reste due à l'étape 64) et tracée dans le dépôt. CC0 autorise explicitement l'usage commercial et la redistribution : la licence reste vraie le jour d'un plan payant (00 § Dépôt et licence, décision 2). Des sujets nommables rendent les 48 libellés triviaux, et les avatars se distinguent par leur forme, pas seulement par leur couleur. Les sujets du pack laissés de côté le sont parce qu'ils se confondent à 32 px avec un sujet retenu (poule et poussin, buffle et vache, narval et baleine, gorille et singe) ou se lisent mal en tête ronde (chèvre, serpent).

| Clé | Sujet (fichier d'origine, relevé exact dans `LICENSE.md`) | Libellé FR | Libellé EN |
|---|---|---|---|
| `preset-01` | bear | Ours | Bear |
| `preset-02` | chick | Poussin | Chick |
| `preset-03` | cow | Vache | Cow |
| `preset-04` | crocodile | Crocodile | Crocodile |
| `preset-05` | dog | Chien | Dog |
| `preset-06` | duck | Canard | Duck |
| `preset-07` | elephant | Éléphant | Elephant |
| `preset-08` | frog | Grenouille | Frog |
| `preset-09` | giraffe | Girafe | Giraffe |
| `preset-10` | hippo | Hippopotame | Hippo |
| `preset-11` | horse | Cheval | Horse |
| `preset-12` | monkey | Singe | Monkey |
| `preset-13` | moose | Élan | Moose |
| `preset-14` | owl | Hibou | Owl |
| `preset-15` | panda | Panda | Panda |
| `preset-16` | parrot | Perroquet | Parrot |
| `preset-17` | penguin | Manchot | Penguin |
| `preset-18` | pig | Cochon | Pig |
| `preset-19` | rabbit | Lapin | Rabbit |
| `preset-20` | rhino | Rhinocéros | Rhino |
| `preset-21` | sloth | Paresseux | Sloth |
| `preset-22` | walrus | Morse | Walrus |
| `preset-23` | whale | Baleine | Whale |
| `preset-24` | zebra | Zèbre | Zebra |

Si un sujet manque au téléchargement, il est remplacé par un sujet restant du pack **sous la même clé** : seuls le fichier et ses deux libellés changent. **La clé n'est jamais un chemin** : changer de pack = changer les fichiers, les libellés et la table clé → fichier de `LICENSE.md`, sans migration de schéma ni réécriture de ligne (C5 I5.7). C'est la « migration de valeurs, pas une casse de données » de 10 § 5.1. Un avatar déjà choisi change alors d'image sous la même clé : un remplacement de pack après la mise en service est un **changement visible**, consigné dans `LICENSE.md`.

### 6.2 Fichiers et licence

**I5.7.** `public/avatars/preset-01.webp` … `preset-24.webp` : WebP 256 × 256, **au plus 20 Ko**, fond transparent conservé, lisibles à 32 px sur le thème sombre du jeu (vérifié à l'œil une fois, consigné dans `LICENSE.md`). `public/avatars/LICENSE.md` porte : nom du pack, auteur, intitulé et texte de la licence CC0 revérifiée, URL de récupération, date, empreinte SHA-256 de l'archive téléchargée, transformations appliquées (recadrage, 256 px, WebP), et la table clé → fichier d'origine. Les prédéfinis sont des fichiers **statiques** de `public/`, publics et cacheables, jamais servis par la route des images de jeu (10 § 5.3) ; `NoRealFixtureTest` (`100`) les autorise nommément avec leur `LICENSE.md`.

**Transformations et vérification livrées** — amendé le 28/09 (E63-2, E63-3). Variante `PNG/Round/` du pack (tête ronde, avec détails, sans contour), un fichier source par clé, nommé par le sujet ; échelle **uniforme** × 1,24 (Lanczos), le plus grand facteur qui garde chaque sujet, élan excepté, dans le disque inscrit de la toile, parce que l'`Avatar` de shadcn est `rounded-full` ; boîte englobante centrée sur une toile 256 × 256 transparente ; `stripImage` ; WebP **sans perte** (9 446 à 15 412 octets). Source matricielle : le SVG du pack est d'un seul tenant (planche des trente sujets, sans groupe par sujet), et le découper aurait coûté la traçabilité par fichier. Conséquence visible : les **bois de l'élan** (`preset-13`) sont rognés à gauche et à droite ; un élan réduit à part aurait eu une tête 25 % plus petite que les autres. La lisibilité à 32 px a été vérifiée le 25/09 **par l'IA**, sur une planche des 24 avatars réduits et découpés en disque sur `--background` sombre, et consignée « à confirmer par le porteur » dans `LICENSE.md` : la vérification « à l'œil » de ce paragraphe reste due au porteur, avec le sort de l'élan (« Ce que cette spec ne décide pas »).

### 6.3 `AvatarPresetCatalog`

```php
namespace App\Avatars;

final class AvatarPresetCatalog {
    public const string KEY_FORMAT = 'preset-%02d';
    public const string LABEL_KEY_PREFIX = 'common.avatar.preset.';
    /** @return list<string> */ public static function keys(): array;   // i = 1..PlatformLimits::avatarPresets()
    public static function has(string $key): bool;
    public static function labelKey(string $key): string;
    /** @param list<string> $taken */ public static function suggest(?string $preferred, array $taken): string;
    /** @return list<array{key: string, url: string, labelKey: string}> */ public static function options(): array;
}
```

Seul registre des clés : `seatAvatarRules()`, `PlayerToken::withAvatar()`, le décodage du jeton, les fabriques et la couverture de traduction passent par lui. `options()` rend les entrées dans l'ordre de `keys()`, avec `url = AvatarRef::presetUrl($key)` et `labelKey = labelKey($key)`. `labelKey()` lève `InvalidArgumentException` pour une clé que `has()` refuse : une clé de traduction n'est jamais bâtie sur une valeur que le registre ne connaît pas ; `suggest()` ignore une revendication hors catalogue (I5.8, étape 1), et la liste est relue depuis `PlatformLimits` à chaque appel, sans état statique — amendé le 28/09 (E63-4). Le nombre de clés vaut `PlatformLimits::avatarPresets()` (24 par défaut) : un changement de cette valeur sans les fichiers correspondants fait échouer `AvatarPresetTest`. La garde `roomSeats() ≤ avatarPresets()` (12 ≤ 24 aux valeurs par défaut) garantit qu'un salon plein trouve toujours un prédéfini libre ; elle est prouvée une seule fois, dans `PlatformLimitsTest` (C0). Depuis D55 du 02/10, elle porte l'**unicité** de l'attribution, et plus seulement la suggestion ; elle ne vaut que tant que l'effectif ne dépasse pas la capacité (`50` § 7.3, retours de sièges) — amendé le 02/10.

### 6.4 Attribution et unicité (D55 du 02/10 — amendé le 02/10)

**I5.8.** `suggest($preferred, $taken)`, déterministe :

1. `$preferred` s'il appartient au catalogue et n'est pas pris ;
2. sinon la première clé libre dans l'ordre de `keys()` ;
3. sinon — impossible sous la garde du § 6.3 — `$preferred` s'il est valide, ou `keys()[0]`.

`$preferred` est la revendication `avatar` du jeton courant : un invité retrouve l'animal qu'il avait choisi dans son salon précédent, s'il est libre. La règle « le doublon reste permis » (00 § Avatars) est **révisée par D55 du 02/10** :

- `suggest()` n'est plus une présélection mais l'**attribution serveur** de la prise de siège, sous le verrou du salon (`TakeSeat` S6, `50` § 7.3 ; `StartSoloGame`, `60` § 16.2, avec `$taken = []`). Aucun formulaire d'entrée ne porte d'avatar.
- **Pris** = `App\Support\Room\TakenAvatars::of($room, ?$except)` : l'`avatar_preset` de chaque siège `holdingSeat()` du salon, **prédéfini de repli des sièges `upload` et `provider` compris** — il redevient visible sans écriture au masquage, à la suppression ou à la déliaison de l'image (§ 11.5). Seul calcul, partagé par `TakeSeat`, `ChangeSeatAvatar` et la prop `avatars` du lobby.
- **Unicité entre sièges tenus** : le geste du lobby refuse une clé prise par un autre siège (`room.lobby.avatar_taken`), sous le même verrou. L'écran marque ces avatars par `common.avatar.picker.taken`. Aucun index unique en base.
- **Doublons résiduels assumés** (`50` § 8.1) : retour d'un siège `left` dont le prédéfini a été pris entre-temps ; retardataire face à l'identité gelée d'un siège parti ; effectif au-delà du catalogue (cas 3 ci-dessus). Le pseudo, unique par salon, reste le discriminant.

À la création d'un salon, aucun avatar n'est pris.

### 6.5 `AvatarRef` : clés renommées, URL extraite

- Les constantes `ALT_KEY_PRESET`, `ALT_KEY_PROVIDER` et `ALT_KEY_INITIALS` passent de `avatar.alt.*` à **`common.avatar.alt.*`** : le domaine `avatar` n'existe pas (C15 § 2.2), et `common` est embarqué par toute page, écran de jeu compris, alors que `account` ne l'est pas.
- `AvatarRef::presetUrl(string $key): string` est extraite de `preset()` : base `avatars.preset_base` (défaut `/avatars`) et extension `avatars.preset_extension` (défaut `webp`), valeurs existantes. `preset()` l'appelle ; `AvatarPresetCatalog::options()` aussi.

### 6.6 Accessibilité

**I5.9.** À côté d'un pseudo affiché, l'image d'avatar est **décorative** (`alt=""`) : un lecteur d'écran ne lit pas deux fois l'identité d'un joueur. Partout ailleurs, `alt = t(altKey)`. Le **libellé** d'un prédéfini (« Hibou ») ne sert que dans le sélecteur — celui du lobby depuis D55 du 02/10, et celui de l'écran « Avatar » du compte —, où il est le nom accessible de l'option (amendé le 02/10). Les avatars prédéfinis ne sont **pas signalables** (00 § Avatars) : ce sont des contenus du site.

### 6.7 Clés de traduction

| Clé | Français | Anglais |
|---|---|---|
| `common.avatar.alt.preset` | Avatar du joueur | Player avatar |
| `common.avatar.alt.provider` | Photo de profil du joueur | Player profile photo |
| `common.avatar.alt.initials` | Initiales du joueur | Player initials |
| `common.avatar.picker.label` | Choisissez un avatar | Choose an avatar |
| `common.avatar.picker.taken` | déjà choisi dans ce salon | already chosen in this room |

Depuis D55 du 02/10, `common.avatar.picker.*` sert au sélecteur du lobby ; un avatar marqué `taken` n'y est plus seulement signalé mais **non sélectionnable** (amendé le 02/10).
| `common.avatar.preset.preset-01` … `preset-24` | libellés FR du § 6.1 | libellés EN du § 6.1 |

Soit 48 libellés de prédéfinis. `common.avatar.alt.provider` n'a aucun usage au J1 (aucune copie provider) mais existe pour que `AvatarRef` reste couvert.

---

## 7. L'identité affichée d'un siège — `PlayerIdentity` [J1]

### 7.1 Forme

```php
namespace App\Support\Identity;

final readonly class PlayerIdentity {
    public static function fromSeat(Player $seat): self;
    public static function fromGamePlayer(GamePlayer $participation): self;  // exige player:id,public_id,nickname_masked_at chargé
    /** @return array{publicId: string, nickname: string|null, masked: bool, avatar: array{kind: string|null, url: string|null, altKey: string, initials: string}} */
    public function toArray(): array;
}
```

```ts
// resources/js/types/player.ts — seule déclaration côté client, réexportée par types/index.ts
type AvatarData = { kind: 'preset' | 'provider' | null; url: string | null; altKey: string; initials: string }; // = AvatarRef::toArray()
type PlayerIdentity = {
  publicId: string /* char(12) base32 */;
  nickname: string | null;
  masked: boolean;
  avatar: AvatarData;
};
```

C'est la **seule** sérialisation de l'identité affichée d'un siège, jamais `Player::toArray()`. Elle est diffusée au salon, identique pour tous : `SeatView` de `60` l'étend (C7), le podium de `80` l'embarque (C13). **Jamais sérialisés** : `id`, `room_id`, `user_id`, `nickname_normalized`, `kicked_at`, le hash du jeton, `solo_token_hash` (créneau d'unicité du siège solo, 10 § 7.1, E10-N3), `active_seat_token` (E10-34). **Au J1**, `masked` vaut toujours `false`, et `nickname` n'est `null` que pour un siège dont l'archivage a effacé le pseudo : `masked: false`, initiales `?` (repli d'`AvatarRef::initialsFrom(null)`) — amendé le 28/09 (E65-4, E69-2).

**Garde de lecture** — amendé le 28/09 (E69-2). `fromGamePlayer()` lève une `LogicException` si la relation `player` n'est pas chargée, ou l'est sans `public_id` ou sans `nickname_masked_at` ; `fromSeat()` lève de même quand `nickname_masked_at` manque aux attributs du siège, **sauf** pour un siège inséré dans la même requête (`wasRecentlyCreated`, défaut SQL NULL : cas de `TakeSeat` de `50`, qui diffuse `seat.joined` juste après l'INSERT). Raison : une requête qui n'aurait pas sélectionné la colonne lirait `null` en silence et publierait un pseudo masqué. Un lot qui lit les sièges par un `select` partiel y inclut donc la colonne. Ajout au contrat C5 : la constante publique `PlayerIdentity::FROZEN_SEAT_COLUMNS = ['id', 'public_id', 'nickname_masked_at']`, que `fromGamePlayer()` exige sur la relation `player`, pour que `60` et `80` chargent `player:` sans recopier la liste ; les propriétés restent privées, `toArray()` est la seule sortie.

### 7.2 Gel pendant une partie

`fromSeat()` sert au lobby. `fromGamePlayer()` sert pendant la partie et au podium : il lit `game_player.display_nickname`, `display_avatar_kind` et `display_avatar_preset`, gelés au lancement par `OpenGame` (C6, O6 ; E10-42). Raison du gel (00 § Cycle de vie) : pseudo et avatar servent à reconnaître un joueur d'un coup d'œil pendant qu'un classement défile ; on les gèle ensemble ou pas du tout. On ne gèle **jamais** le chemin d'une copie provider (10 § 7.3), pour qu'un masquage postérieur fasse redescendre la chaîne de repli. Il en va de même de l'image téléversée (§ 11.5) : `display_avatar_kind = upload` gèle la **nature** et le prédéfini de repli, jamais un chemin ; l'image se lit vivante sur le compte — amendé le 01/10 (D49 du 01/10).

### 7.3 Masquage : forme au J1, règle au J2

**I5.10.** La règle de masquage (seuil, signalements, levée) est un sujet du J2 (§ 10). Sa **forme** est fixée dès le J1 pour qu'aucun consommateur ne soit à refaire : un siège masqué rend `nickname: null`, `masked: true` et `avatar.initials = AvatarRef::FALLBACK_INITIAL`, pour que les initiales ne trahissent pas le pseudo. `kind`, `url` et `altKey` restent ceux de l'accesseur : l'image prédéfinie reste affichée (contenu du site, non signalable, § 6.6) ; le masquage se lit sur le siège **vivant** (`player.nickname_masked_at`), `fromGamePlayer()` compris — amendé le 28/09 (E69-2). Le client affiche alors un libellé neutre et un ordinal dérivé au rendu (`common.player.masked`, `:ordinal`, clé du J2). Un masquage s'applique aussi à l'affichage gelé.

### 7.4 Côté client

| Fichier | Contenu |
|---|---|
| `resources/js/types/player.ts` | `AvatarData`, `PlayerIdentity` (ci-dessus), plus `AvatarPresetKey`, union des 24 clés littérales, et `AvatarPresetOption = { key: AvatarPresetKey; url: string; labelKey: string }`. Fichier dans le périmètre `WATCHED` de `90` (C16 § 2.11). |
| `resources/js/lib/game/avatar-keys.ts` | `AVATAR_PRESET_LABEL_KEYS: Record<AvatarPresetKey, TranslationKey>` (24 lignes), `isAvatarPresetKey(key: string): key is AvatarPresetKey`, et `avatarAltKey(altKey: string): TranslationKey` sur une table close des trois clés `common.avatar.alt.*`, repli sur la clé des initiales. Raison : `t()` est typé, une clé composée par gabarit ne compile pas (C15 § 2.7), et ajouter une clé au catalogue sans sa ligne ici doit casser. |
| `resources/js/components/game/player-avatar.tsx` | `PlayerAvatar({ avatar, alt, className }: { avatar: AvatarData; alt: string; className?: string })` : primitives `Avatar`, `AvatarImage`, `AvatarFallback` (initiales) déjà installées ; `alt` déjà traduit par l'appelant, `''` à côté d'un pseudo (§ 6.6). Aucun appel à `t()`, aucune dépendance à Inertia, taille par classes de tokens. |
| `resources/js/components/game/avatar-picker.tsx` | `AvatarPicker({ name, options, account, value, onValueChange, legend, takenLabel, disabled })`, `value: SeatAvatarChoice | null` (rien de coché quand le choix courant n'a aucune tuile), `disabled?` (gestes désactivés), `options: { key: AvatarPresetKey; url: string; label: string; taken: boolean }[]` déjà traduites. Rend la primitive `radio-group` (installée au J1, C16 § 2.9) avec `name` natif, pour que `<Form>` d'Inertia sérialise le choix sans état maison ; au lobby (D55 du 02/10), le choix part par le geste `room.avatar.update` (`50` § 8.1), en `<Form>` ou en `router.post`, sous l'en-tête `X-Seat-Token` ; la primitive cochant l'option qu'elle focalise aux flèches, le choix n'est **envoyé que par un bouton de confirmation** (`room.lobby.avatar.apply`), jamais à chaque changement de valeur — amendé le 02/10. Nom accessible de chaque option : son libellé, suivi de la mention `takenLabel` quand elle est prise — sixième prop, `t('common.avatar.picker.taken')` résolu par l'appelant, affichée sous chaque option prise et composée par `aria-labelledby` (libellé puis mention, sans ponctuation écrite en dur) ; le composant ne traduit rien, la chaîne résolue n'avait aucune place dans la signature initiale (seul `taken: boolean` voyage par option), et 90 § 10 exige que l'avatar pris soit signalé par texte — amendé le 28/09 (E69-1) ; image décorative ; cibles `min-h-11 min-w-11` ; navigation aux flèches et un seul arrêt de tabulation, fournis par la primitive. |

Les deux composants entrent dans la liste close de `90` (C16 § 2.9), qui décide de leur place à l'écran.

---

## 8. Les comptes au jalon 1 [J1]

### 8.1 Qui a un compte en production au J1

La production existe dès le J1, sur le domaine définitif acheté avant la semaine 4 (D1 du 23/09). Les seuls comptes de production du J1 sont **le premier administrateur, qui est le porteur et le seul curateur** (D4 du 23/09), créé par `admin:first-admin` **après** l'achat du domaine (règle de `CLAUDE.md` §1, inchangée), et aucun autre : l'inscription étant fermée en production (§ 8.2), aucun compte de test jetable n'y est créé. Les comptes de test jetables qu'A-67 admet au J1 vivent sur les postes et en CI : conséquence du § 8.2 et de la garde `local`/`testing` de `DemoAccountsSeeder`. A-67 réduit les comptes du J1 au premier administrateur et aux comptes de test jetables sans dire où ceux-ci vivent ; cette précision est de `40`, et elle est **signalée au porteur**. Le **point de non-retour** d'un changement de domaine est la première passkey enregistrée en production, et elle seule (décision 5) : un compte à mot de passe se déplace d'un domaine à l'autre, une passkey non. D'où le § 8.2.

### 8.2 Inscription publique et passkeys fermées en production

Un premier déploiement du J1 avec la configuration actuelle **ouvrirait l'inscription publique et l'enregistrement de passkeys**, contre `CLAUDE.md` §1 et contre la séparation des jalons. Deux interrupteurs **dédiés** les ferment :

```php
// config/accounts.php [nouveau]
return [
    'registration_open' => env('ACCOUNTS_REGISTRATION_OPEN'),
    'passkeys_enabled' => env('ACCOUNTS_PASSKEYS_ENABLED'),
];

// App\Support\Identity\AccountSwitches [nouveau]
final class AccountSwitches {
    public static function registrationOpen(): bool; // valeur booléenne déclarée, sinon App::environment(['local', 'testing'])
    public static function passkeysEnabled(): bool;  // valeur booléenne déclarée, sinon App::environment(['local', 'testing'])
}
```

- **Lecture.** Seules les valeurs `true` et `false`, que `env()` convertit en booléens, sont lues ; toute autre valeur, **vide comprise**, vaut « non déclarée » et applique une **liste blanche**, la même garde que `DemoAccountsSeeder` : ouvert seulement en `local` et `testing`, **fermé partout ailleurs** — `production`, préproduction du J2, `staging`, `prod` et tout nom inattendu ou mal orthographié compris. Raison : `.env.example` livre ces variables vides (CI à zéro secret, `100`) ; un poste de développement et la suite de tests gardent l'inscription et les passkeys de Fortify, qu'ils testent déjà ; un serveur qui n'est ni un poste de développement ni la suite de tests reste fermé tant que `true` n'y est pas déclaré. Une garde « fermé en `production`, ouvert ailleurs » serait une liste noire : le premier nom d'environnement imprévu ouvrirait l'inscription sur un serveur public, contre `CLAUDE.md` §1 et D1 du 23/09 — le dépôt a déjà écarté ce piège pour `DemoAccountsSeeder`. Le cas restant, un serveur public installé avec l'`APP_ENV=local` que recopie `composer setup`, est fermé par la vérification de déploiement demandée à `100` (§ 9).
- **Mécanisme.** Les routes de Fortify **restent enregistrées** quel que soit l'interrupteur : `Features::registration()` et `Features::passkeys()` ne sont pas retirés de `config/fortify.php`. Raison vérifiée : les helpers Wayfinder `register` et les actions des contrôleurs de passkeys sont importés par le front et **régénérés au build en CI** ; une route désenregistrée selon l'environnement casserait la compilation, ou produirait des assets différents selon la machine qui les construit. L'intergiciel **`App\Http\Middleware\EnforceAccountSwitches`**, alias `accounts.switches`, est ajouté au groupe de Fortify (`config/fortify.php` › `middleware` : `['web', 'translations:account,legal', 'accounts.switches']`) ; il répond **404** aux routes nommées `register` et `register.store` quand l'inscription est fermée, et à toute route nommée `passkey.*` quand les passkeys sont fermées. La route **`well-known.passkeys`** (`/.well-known/passkey-endpoints`, déclarée dans `routes/settings.php` **hors** du groupe de Fortify) reçoit aussi `accounts.switches`, et l'intergiciel y répond 404 quand les passkeys sont fermées : sinon elle annoncerait aux gestionnaires de mots de passe un point d'enrôlement de passkey (`enroll` → `security.edit`) sur une production où les passkeys n'existent pas. Un 404 plutôt qu'un 403 : la fonction n'existe pas pour le visiteur, et le code de retour ne doit rien apprendre de l'état du jalon.
- **Rang dans la pile** — amendé le 25/09 (E14-5). `Authenticate` et `ThrottleRequests` figurent dans la liste de priorité du framework, `accounts.switches` non : triés, ils passent avant lui. Sur les routes de passkey sous `auth:web` (`passkey.confirm*`, `passkey.registration-options`, `passkey.store`, `passkey.destroy`), un **invité** reçoit donc la redirection de connexion, identique portes ouvertes ou fermées, et seul un compte connecté reçoit le 404 ; le limiteur `passkeys` compte aussi les requêtes refusées. Les routes `guest` (`register`, `register.store`, `passkey.login*`) et `well-known.passkeys` répondent 404 à tous. Aucun rang de priorité n'est ajouté : la redirection d'un invité n'apprend rien de l'état du jalon.
- **Surfaces.** Prop partagée **`accountsOpen: boolean`** (`HandleInertiaRequests::share()` et `types/global.d.ts`) = `registrationOpen()` : elle gouverne les liens de **connexion et d'inscription** de l'en-tête public (C16 § 2.4 : absents en production au J1) et le « crochet compte » d'après podium (00 § Déroulé d'une partie, étape 10), absent lui aussi en production au J1 par cette même prop — `90` n'en rend aucun au J1 et le branchera sur elle au J2 (§ 9). Le porteur atteint `/login` par son adresse directe. La vue `auth/login` reçoit `canRegister` (lien d'inscription) et `canUsePasskeys` (bouton de passkey), la vue `auth/confirm-password` reçoit `canUsePasskeys`, et `SecurityController` rend `canManagePasskeys = Features::canManagePasskeys() && AccountSwitches::passkeysEnabled()`.
- **Ouverture.** L'inscription s'ouvre à l'ouverture publique du J2, par `ACCOUNTS_REGISTRATION_OPEN=true` dans l'environnement de production, jamais par une valeur du dépôt (même principe que la levée du `noindex`, `CLAUDE.md` §1). Les passkeys ne s'ouvrent en production qu'**après** l'écriture, au J2, de l'articulation entre 2FA et passkeys (§ 10) : une connexion par passkey ouvre la session sans défi TOTP, ce qui contredirait « rôle privilégié ⇒ 2FA active » tant que la règle n'est pas écrite.
- **Ce n'est pas un système de drapeaux de fonctionnalités** (00 § Hors périmètre v1, qui l'interdit nommément) : deux booléens nommés, lus par une seule classe, sans table ni interface. La commande d'arrêt du service de `100` (`site:close`, J2) coupera l'inscription **par cette même classe** : la condition s'ajoutera dans `registrationOpen()`, et aucune autre lecture n'existe.

### 8.3 Suppression de compte : route retirée, texte corrigé

La suppression dure de `ProfileController::destroy()` contredit 10 § 5.5 (suppression = anonymisation) : elle détruit `user_consent` par cascade, contourne la règle du dernier administrateur et vide `frame_review.reviewer_id` par `nullOnDelete`. Au J1, où **le seul compte de production signe toute la curation** (D1 du 23/09, D4 du 23/09), un clic suffirait à supprimer l'administrateur unique. L'anonymisation est un sujet du J2 (§ 10). Donc, au J1 :

- la route `profile.destroy`, `ProfileController::destroy()` et `App\Http\Requests\Settings\ProfileDeleteRequest` sont **retirés** ; `pages/settings/profile.tsx` ne rend plus `components/delete-user.tsx`, qui est supprimé. Le J2 les recrée sur l'action d'anonymisation ;
- les textes de `account.delete_account.*` sont **corrigés dès maintenant**, pour que le J2 ne rebranche pas une promesse fausse. `description` et `dialog_description` décrivent l'anonymisation de 10 § 5.5, sans mentionner de mot de passe (le mode de confirmation d'un compte sans mot de passe est un sujet du J2) :

| Clé | Français | Anglais |
|---|---|---|
| `account.delete_account.description` | Anonymisez votre compte et supprimez vos données personnelles | Anonymise your account and delete your personal data |
| `account.delete_account.dialog_description` | Votre compte sera anonymisé : adresse e-mail, mot de passe, comptes liés, configurations sauvegardées et photo sont supprimés, et votre nom est remplacé par un libellé neutre. Les parties déjà jouées restent, sans votre nom, dans l’historique des autres joueurs jusqu’à leur effacement au bout de :months mois. | Your account will be anonymised: email address, password, linked accounts, saved configurations and photo are deleted, and your name is replaced by a neutral label. Games already played remain, without your name, in other players’ history until they are erased after :months months. |

Les textes portent l'apostrophe typographique `’`, comme toutes les chaînes de `lang/{fr,en}/account.php` — amendé le 25/09 (E14-6).

`:months` recevra `PlatformLimits::historyWindowMonths()` au J2 : la durée n'est pas écrite en dur dans un texte. Les autres clés (`heading`, `warning_hint`…) restent vraies.

### 8.4 Nom réel des comptes privilégiés, vu du compte (D12 du 23/09)

L'attribution de `curator` ou d'`admin` exige un **nom réel**, porté par la seule colonne `users.real_name` (E10-02) ; c'est lui que figent `frame_review.reviewer_name` et `admin_action.actor_name` (E10-22), pour que la revue d'une image prouve un curateur nominatif (principe 12, engagement 2). La colonne, sa garde, son écriture par `admin:first-admin` au J1 et par l'écran de gestion des accès au J2 appartiennent à `20` (contrat C14). Vu du compte :

1. **Le nom réel n'est jamais montré à un autre joueur.** Il n'entre dans aucune charge de jeu, n'est jamais recopié dans `users.name` ni dans `player.nickname` (I5.11), et `#[Hidden]` le retire de `auth.user`, que `HandleInertiaRequests` sérialise sur toutes les pages.
2. **Un curateur ou un administrateur qui joue** prend un siège sous un pseudo saisi, comme tout le monde (§ 2.4) ; rien ne le pré-remplit, ni son nom réel ni le nom de son compte. — Amendé le 07/10 (D66 du 07/10, n° 22) : au J2, son pseudo est prérempli depuis `users.name` comme pour tout compte (§ 13.9) ; le nom réel, jamais.
3. **Le nom réel n'apparaît que sur des écrans du back-office** (contrat C14 § 3 ; 20 § 2.6 : journal, revues, accès). Au J1, le titulaire — le porteur, seul administrateur (D4 du 23/09) — le fixe lui-même par `admin:first-admin --real-name=` ; il se consulte sur l'écran de gestion des accès de `20` (§ 2.8), où l'administrateur le saisit à l'attribution du rôle et le corrige — écran prévu au J2, livré en avance le 28/09 — amendé le 28/09. **Aucune page `settings/*` ne le reçoit**, ni en prop de page ni en prop partagée : `settings/profile` est une page de compte rendue dans `AppLayout`, hors back-office. Qu'un titulaire voie son propre nom réel hors du back-office serait un écart à C14 § 3, à poser au porteur s'il est souhaité, jamais une règle acquise de cette spec.
4. **`users.name`** reste au J1 le nom du compte affiché dans la coquille du site, jamais montré à un joueur ; sa règle de « pseudo persistant » est un sujet du J2.
5. À l'anonymisation (J2), `users.real_name` est vidé et les instantanés sont conservés (10 § 5.5, contrat C14).

### 8.5 Types du compte côté client

`resources/js/types/auth.ts` passe à `email: string | null`, `avatar: string | null` **et `Auth = { user: User | null }`**, alignés sur la migration déjà jouée (10 § 5.2) et sur ce que partage réellement le serveur : un invité n'a pas de compte, et `HandleInertiaRequests` partage `$request->user()`, nul hors connexion — au J1, presque tout visiteur (contradiction n° 33, défaut retenu). Tout lecteur de `auth.user` le garde, puisque le type ne connaît pas la page qui le lit : `welcome.tsx`, `nav-user.tsx` et `app-header.tsx` le font déjà ; `components/admin/admin-sidebar.tsx` et `pages/settings/profile.tsx`, rendus derrière `auth`, reçoivent une garde explicite (retour anticipé), jamais une assertion non nulle. `settings/profile.tsx` pose `defaultValue={auth.user.email ?? ''}` ; `components/user-info.tsx` garde l'affichage d'un e-mail nul et pose `alt=""` sur l'image, affichée à côté du nom (§ 6.6). `AvatarImage` n'acceptant que `string | undefined`, les deux autres lecteurs de l'avatar, `components/app-header.tsx` et `components/admin/admin-user-panel.tsx`, passent `src={… ?? undefined}` : retouche de type seule, aucun rendu changé — amendé le 25/09 (E14-2).

---

## 9. Ce que la section [J1] exige des specs voisines

| Spec | Exigence |
|---|---|
| `50` | FormRequest de création et d'entrée sur le trait `PlayerIdentityValidationRules` (§ 5.8) ; celle de `room.join` exclut `nickname` quand le jeton tient déjà un siège dans le salon (aucun formulaire d'entrée ne porte d'avatar, D55 du 02/10) (§ 5.8, « Reprise »). Action de prise de siège dans l'ordre du § 2.1, étape 4 : `current()` et `seatIn()` d'abord, `wasKickedFrom()` avant tout comptage, capacité et unicité sous verrou (collision traduite en `taken`), **`ensure()` seulement après les refus** — l'action reçoit donc la requête, lit le jeton courant par `current()`, éventuellement nul, et ne frappe qu'au moment d'écrire le siège ; signatures livrées `TakeSeat::handle(Room, Request, ?string $nickname, Locale, bool $repairHost = true): Player|JoinRefusal` (paramètre d'avatar retiré par D55 du 02/10 — amendé le 02/10) et `CreateRoom::handle(Request, …)`, aucun contrôleur n'appelant `ensure()` (amendé le 28/09, E101-1) —, écritures du § 2.2, re-signature avec l'avatar attribué. Geste d'expulsion selon I4.9. Geste de changement d'avatar au lobby et unicité sous verrou (§ 2.1 étape 6 bis, § 6.4 ; D55 du 02/10). Props de la page d'entrée du § 2.1 (bornes du pseudo seules) ; page d'entrée d'un porteur de siège qui mène au lobby sans formulaire. Texte de `room.join.kicked`. Tests exigés (contrats C4 § 7 et C5 § 7) : `tests/Feature/Room/SeatTakingTest.php` — « refuse un pseudo déjà pris dans le salon, sièges partis et expulsés compris », « traduit une collision de pseudo qui atteint l'index unique au lieu de laisser fuiter une 1062 », « ne revalide jamais le pseudo d'un siège repris » — joué par un envoi de `room.join` du porteur du siège **sans** champ `nickname`, puis avec un pseudo entré depuis dans la liste noire, les deux reprenant le siège —, et « re-signe le player_token avec l'avatar attribué » (I4.5, amendé le 02/10) ; `tests/Feature/Room/KickTest.php` — « refuse le jeton d'un siège expulsé dans le même salon jusqu'à l'archivage », « laisse un jeton expulsé prendre un siège dans un autre salon » ; `tests/Feature/Room/LobbyPayloadTest.php` — « n'envoie jamais au client le player_token, son tid ni son hash ». |
| `60` | Garde `player`, canaux, `/f/`, resynchronisation et `seat.active` résolvent le siège selon le § 3.9 ; `Broadcast::routes()` et `/f/` sous une pile contenant `EncryptCookies` ; `solo.store` appelle `ensure()` **après ses refus** (drainage, vivier sans `N` jouable ; livré par `StartSoloGame::handle(Request, …)`, qui lit le siège solo par `current()` sans frappe, amendé le 28/09, E121-2) et le même trait, sans unicité, en n'exigeant `nickname` que d'un jeton qui ne tient aucun siège solo repris (§ 5.8 ; plus aucun champ `avatar`, avatar attribué par le serveur, D55 du 02/10) ; `SeatView` étend `PlayerIdentity`. Le battement qui ramène un siège `left` à `connected` (60 § 13.1) réécrit `locale` = `App::getLocale()` dans la même écriture Eloquent (§ 2.2, « À la reprise »). Preuves : `tests/Feature/Game/ChannelAuthorizationTest.php` › « un siège expulsé est refusé sur les deux canaux jusqu'à l'archivage » ; `tests/Feature/Game/PresenceTest.php` › « réaligne la langue d'un siège parti sur la langue effective quand il est repris ». |
| `70` | Livrer `AnswerKeyNormalizer::fold()` avec les propriétés de C12 § 3 et ses fixtures. |
| `80` | Le podium embarque `PlayerIdentity::fromGamePlayer()` (C13). |
| `90` | `player-avatar.tsx` et `avatar-picker.tsx` dans la liste close ; `radio-group` installé et `components/game`, `lib/game`, `types/player.ts` inscrits dans `WATCHED` par L90-1, livré avant L40-6 (C16 § 2.9 et § 2.11) ; règle `alt` du § 6.6 ; liens de compte de l'en-tête public sur `accountsOpen` ; au J2, crochet compte d'après podium rendu seulement si `accountsOpen` est vrai (90 n'en rend aucun au J1) ; mention d'acceptation des CGU sur l'écran de pseudo et sous la carte « Rejoindre » de l'accueil (D55 du 02/10) ; sélecteur d'avatar au lobby, plus à l'entrée ; envoi du formulaire de pseudo désactivé pendant l'état `processing` de `<Form>` (§ 3.4) ; ligne de cookie `player_token` de la page de confidentialité, texte du § 3.2 (« ou le dernier changement de langue ») ; au J2, avec les pages légales opposables (90 § 11, point 5), mention dans la même page du nom réel conservé pour les comptes privilégiés (§ 8.4) ; au J1, le seul titulaire est le porteur (D4 du 23/09), qui le fixe lui-même. **Par exception** : le masquage du lien d'inscription et du bouton de passkey de `auth/login` et `auth/confirm-password` sur `canRegister` et `canUsePasskeys` est réalisé par L40-7, parce que la section J1 de `90` ne couvre pas les écrans de compte (90 § 11) et qu'une production qui existe dès le J1 (D1 du 23/09) ne peut pas les laisser affichés ; `90` le reprend dans son inventaire au J2. |
| `100` | Relevé des licences tierces : pack Kenney (CC0) et listes LDNOOBW (CC BY 4.0, attribution due) ; procédure de rotation d'`APP_KEY` (§ 3.11) ; `ACCOUNTS_REGISTRATION_OPEN` et `ACCOUNTS_PASSKEYS_ENABLED` vides dans `.env.example` ; vérification, au déploiement, que le serveur de production porte `APP_ENV=production` — et non l'`APP_ENV=local` que recopie `composer setup`, qui ouvrirait inscription et passkeys par la liste blanche du § 8.2 ; test de longueur de colonne de `player.nickname` sur MySQL (groupe `mysql`). |
| `20` | Nom réel demandé par `admin:first-admin` (C14) ; 2FA des rôles privilégiés posée au J1 sur le groupe `/admin`, règle et mécanisme de `20`. |

---

## 10. Jalon 2 — à écrire

> **Amendé le 07/10 (D66 du 07/10)** : rédigée au **§ 13**. Les sujets 1, 5, 6, 7, 8, 9, 10, 11, 14 et 15 y sont écrits ; 12, 13, 16 et 17 y sont soldés par renvoi (§ 13.10) ; les trois questions du § 10.2 y sont tranchées (§ 13.11) ; les deux valeurs « à confirmer » — 2FA de `curator` **et** d'`admin`, dormance 24 mois + 30 jours — sont **confirmées** (n° 10, n° 28). La liste ci-dessous est conservée comme trace ; en cas d'écart, le § 13 fait foi.

> **Amendé le 01/10 (D51 du 01/10)** : les sujets 2 (connexion sociale), 3 (comptes liés et déliaison, confirmation d'un compte sans mot de passe) et 4 (copie locale de la photo) sont rédigés et livrés au J1 au § 12 ; la question « preuve du consentement aux données provider » du § 10.2 est tranchée (un cas de `ConsentKind` par fournisseur).

Cette section **liste** les sujets que la carte des specs de `00-overview.md` attribue à `40` pour l'ouverture publique ; elle ne les rédige pas. Le matériau cité est de trois natures : la première est déjà normative, la deuxième se confirme auprès du porteur, et de la troisième seules les trois questions du § 10.2 lui sont posées.

- **Déjà décidé, à rédiger sans le rouvrir.** Les règles arrêtées par le corpus : 00 § Comptes & profils et § Avatars, 10 § 5.4, § 5.5, § 8 et § 11, `questions-ouvertes.md` § Déjà tranché (dont « Suppression de compte = anonymisation », « Signalement joueur », « Avatars prédéfinis »), les décisions 14, 15 et 19. Les défauts du rédacteur retenus par le porteur le 23/09, avec la résolution proposée par la pré-analyse : Q40-6, ré-acceptation des CGU (sujet 1) ; contradiction n° 11, règle de signalement à `40` et écran à `20` (sujet 6) ; n° 30, masquage du pseudo pour la vie du siège, levée par l'administrateur seul, « deux sièges distincts », garde-fou du siège présent dans le même salon, résidu multi-siège nommé (sujet 6) ; n° 31, résolution vivante de l'avatar par le compte, déliaison du fournisseur d'origine qui supprime le fichier et vide les colonnes — avec l'exigence d'une colonne de provenance nullable à `10` —, masquage qui réécrit `avatar_kind = preset` si `avatar_preset` existe (sujets 3, 4 et 6) ; n° 32, anonymisation et confirmation par ré-authentification OAuth des comptes sans mot de passe (sujets 3 et 9). S'y ajoutent deux résolutions de la pré-analyse déjà appliquées au corpus le 23/09, sans décision du porteur à prendre : n° 37, une passkey à vérification de l'utilisateur vaut second facteur, rôles privilégiés compris, et scopes minimaux Discord `identify`, `email`, Google `openid`, `email`, `profile` (00 § Comptes & profils, § Ouverture, conformité et gouvernance ; `questions-ouvertes.md` § 1 ; sujets 2 et 11) ; n° 38, balayage **quotidien** de dormance, anonymisation seulement 30 jours après un rappel réellement envoyé ou pour un compte sans e-mail, trace `users.dormancy_notified_at` [J2] (10 § 5.1, § 11.1, § 12 ; sujet 10). — amendé le 23/09
- **À confirmer ou réviser par le porteur**, et seulement elles : les deux valeurs que `questions-ouvertes.md` § Confirmations attendues déclare réversibles, dans sa rédaction du 23/09 — 2FA exigée de `curator` **et** d'`admin`, réversible tant que la garde de `20` (`admin.2fa`, `20` § 2.4) n'est pas implémentée et reprise ici pour confirmation (sujet 11) — la garde est livrée depuis L20-2 (24/09) et applique cette valeur (« Ce que cette spec ne décide pas », E18-8 ; amendé le 25/09) ; dormance, rappel à 24 mois puis anonymisation 30 jours plus tard, réversible tant que la présente section J2 n'est pas écrite (sujet 10). Seules les valeurs se confirment : la fréquence quotidienne du balayage et la règle du rappel réellement envoyé (n° 38) ne se rouvrent pas. — amendé le 23/09
- **Pistes de la pré-analyse, sans valeur normative** : tout le reste du relevé ci-dessous. Seules les trois questions du § 10.2 sont posées avec options et recommandation ; les autres pistes se tranchent comme des défauts de rédacteur, sous l'autorité des règles déjà décidées.

### 10.1 Sujets

1. **Ouverture des comptes.** Inscription Fortify ouverte par l'interrupteur du § 8.2 ; acceptation des CGU et déclaration d'âge (15 ans) horodatées, lignes `user_consent` et projections dans la même transaction ; ré-acceptation quand la version des CGU change (**déjà tranchée** le 23/09 comme défaut retenu, Q40-6 : contrôle à la connexion quand la version diffère, fonctions du compte suspendues en cas de refus, jeu jamais bloqué) ; limiteur nommé d'inscription ; `users.name` comme pseudo persistant et sa règle de validation ; `last_login_at` posé dès la création — son écriture à chaque connexion aboutie existe depuis le 28/09 (écouteur `RecordLastLogin`, posé par L20-19 pour l'écran de gestion des accès, `10` § 5.1) : la règle du compte, dormance comprise, reste à rédiger ici et ratifie cet écrivain (point EL19-5 de `20`) — amendé le 28/09.
2. **Connexion sociale.** `laravel/socialite` et `socialiteproviders/discord` (écouteur `SocialiteWasCalled` dans `AppServiceProvider::boot()`), `routes/auth.php`, routes `oauth.redirect` et `oauth.callback`, limiteur `oauth` ; scopes : Discord `identify`, `email` ; Google `openid`, `email`, `profile` (n° 37, déjà décidé — amendé le 23/09) ; table de décision du callback ; même e-mail sur deux fournisseurs ; compte déjà lié ailleurs ; compte sans e-mail ; `Auth::login()` qui court-circuite le défi 2FA de Fortify ; ordre de mise en service de Google après les pages légales ; figement de `<DOMAINE>` dans les URI de redirection.
3. **Comptes liés et déliaison.** Dernière méthode de connexion ; confirmation par ré-authentification pour un compte **sans mot de passe** (aujourd'hui, `RequirePassword`, `current_password` et `confirmPassword` bloquent tout compte OAuth pur) ; provenance de la copie de photo quand deux fournisseurs sont liés (exigence à formuler à `10`).
4. **Copie locale de la photo provider.** Liste blanche d'hôtes, délai, plafond lu en flux, revalidation et réencodage Imagick ; échec et réessai ; « supprimer ma photo » ; branche provider de `Player::avatarRef()` et `GamePlayer::avatarRef()`, absente aujourd'hui ; repli vers le prédéfini après masquage.
5. **Rattachement d'un invité à un compte** sans second siège, sur la base d'I4.6 ; langue effective ; mise à jour de l'affichage à la partie suivante ; appareil partagé.
6. **Signalements et masquage** du pseudo et de la copie provider : « deux sièges distincts » (et non « deux joueurs ») ; garde-fou du signaleur présent dans le salon ; limiteur nommé de signalement ; masquage du pseudo pour la vie du siège ; copie provider masquée dans tous les salons ; notification ; levée par l'administrateur seul depuis la file de `20` ; actions de levée (`nickname.unmasked`, `avatar.unhidden`, écrites par `AdminJournal::record` dans la transaction de l'état) et policies de modération, dont les noms de classes et de méthodes sont repris à la lettre par `tests/Datasets/AdminRoutes.php` de `20` (20 § 11.5, L20-31) ; libellé neutre `common.player.masked`.
7. **Historique sur 12 mois glissants et définition des quatre compteurs**, en requêtes sur les agrégats figés au podium par `80`, sous l'invariant L1 de 10 § 1.8. Chaque film de l'historique porte son lien Letterboxd, par `RevealMovieBuilder` et `letterboxd-link.tsx`, toujours dans un nouvel onglet (D58 du 06/10 — amendé le 06/10).
8. **Export en libre-service** : job, archive, URL signée, disque privé (exigence à formuler à `10`), commande d'administration, demande d'accès d'un invité.
9. **Anonymisation** à la suppression de compte, réouverture de `profile.destroy` sur elle (§ 8.3), écran spécifique des comptes à revues ou à journal, règle du dernier administrateur. Passation du J1 (E121-10, E122-6) : les sièges détachés y perdent aussi `player.solo_token_hash` avec `player_token_hash` (10 § 5.5, « Détachés et effacés ») ; la liste des colonnes effacées d'un siège vit dans `OrphanPlayerHandler::SEAT_IDENTITY` (purge `orphan_player`, L100-8), privée, à partager si l'anonymisation veut la même liste — amendé le 28/09.
10. **Compte dormant** : rappel à 24 mois, anonymisation 30 jours plus tard, exemption de `curator` et `admin` ; balayage **quotidien**, anonymisation seulement 30 jours après un rappel réellement envoyé ou pour un compte sans e-mail, trace `users.dormancy_notified_at` [J2] (n° 38, déjà décidé ; 10 § 5.1, § 12). — amendé le 23/09
11. **2FA et passkeys** : une passkey à vérification de l'utilisateur vaut second facteur, rôles privilégiés compris (n° 37, déjà décidé) ; restent l'ouverture des passkeys en production (§ 8.2) et l'enrôlement des rôles privilégiés, avec la désactivation interdite pour un rôle privilégié, dont le mécanisme J1 est posé par `20`. — amendé le 23/09
12. **Configurations sauvegardées vues du compte** : propriété strictement privée, y compris vis-à-vis d'un administrateur, et sort à l'anonymisation ; CRUD et normalisation à `50`.
13. **Rétention vue du compte**, par renvoi au tableau de 10 § 11.1, sans le recopier.
14. **Cycle de vie de `users.role` vu du compte** : aucun rôle attribué par une inscription ni par une liaison OAuth ; sort de la 2FA exigée et de `real_name` au retrait d'un rôle ; à l'anonymisation, `role` forcé à `player` et `real_name` vidé (10 § 5.5, contrat C14) ; exemption de dormance des rôles privilégiés ; règle du dernier administrateur vue du compte. La matrice, les policies et l'écran de gestion des accès restent à `20` (boundary map : « 40 garde le cycle de vie du rôle vu du compte »).
15. **Pré-remplissage du siège d'un joueur connecté** depuis son compte — pseudo persistant et avatar de compte (00 § Déroulé d'une partie, étape 2 : « le joueur connecté arrive avec son avatar de compte ») —, suspendu au J1 par I4.10 (contrat C5 § 1 : « pré-remplissage depuis le compte » au J2).
16. **`HasLocalePreference` côté compte** : langue des e-mails du compte, qui partent en file, et cas du compte sans e-mail (05 § Ce que cette spec ne décide pas, qui le renvoie à `40`).
17. **Page `dashboard` du starter** : retrait éventuel et nouvelle cible de `fortify.home` après connexion (C15 § 2.3, 90 § 2.4) ; au J1 elle reste, dans `AppLayout`, cible de `fortify.home`. L'inventaire et la coquille des écrans restent à `90` (90 § 11, point 2).

### 10.2 Questions ouvertes

Trois questions ont été **délibérément laissées ouvertes** le 23/09, hors du périmètre du J1. Elles se posent au porteur à l'écriture de 40-J2 :

- **Effet de « bannir un pseudo » (`nickname.banned`).** Le geste est listé (00 § Back-office, `AdminActionType::NicknameBanned`, permanent) sans aucun effet défini, alors que la liste noire est une ressource versionnée sans table (10 § A15). Lectures relevées : masquage définitif du siège et ajout de la forme à la liste noire au commit suivant ; retrait du geste en v1 ; forme interdite en base, qui rouvrirait A15.
- **Preuve du consentement aux données provider.** Le principe 12 fonde ces données sur un consentement explicite, mais `user_consent.kind` ne connaît que `terms` et `age`, et la ligne `linked_account` disparaît à la déliaison. Lectures relevées : un cas de `ConsentKind` par fournisseur (l'unicité `(user_id, kind, version)` interdit un cas unique réécrit à chaque liaison) ; la date de liaison, qui ne survit pas à la déliaison ; une requalification de la base légale, qui rouvrirait le principe 12.
- **Avatar d'un invité qui crée un compte.** Le jeton porte le prédéfini choisi comme invité, et 00 interdit de copier la photo provider « par-dessus un avatar prédéfini explicitement choisi » ; sur un compte neuf, `avatar_kind` est toujours nul. Lectures relevées : choix proposé à la création du compte ; le prédéfini de l'invité l'emporte ; la photo l'emporte.

### 10.3 Ordre de grandeur

Hors écrans de `90`, barre « terminé » comprise, **36 à 57 h** pour les sujets 1 à 13 ; les sujets 14 à 16 sont de la rédaction et des gardes adossées aux sujets 5, 9, 10 et 11, et le sujet 17 une décision de configuration adossée à la coquille des écrans de compte de `90`, sans lot propre chiffré ici. À rechiffrer par lots à l'écriture de 40-J2 : connexion sociale 6 à 10 h ; ouverture des comptes, comptes liés, signalements 4 à 6 h chacun ; copie provider, rattachement, historique, export 3 à 5 h chacun ; anonymisation 3 à 4 h ; dormance 2 à 3 h ; 2FA et passkeys 1 à 2 h. C'est une estimation de cadrage, pas un engagement.

---

## 11. Avatar téléversé [J1, D49 du 01/10]

Ajoutée le 01/10 (D49 du 01/10), qui renverse la décision 14. Un **compte** peut téléverser une image, son avatar personnel ; un invité garde les 24 prédéfinis. Livré et actif au J1 pour tout compte existant ; il sert à tous à l'ouverture de l'inscription (§ 8.2), sans travail de plus.

### 11.1 Troisième nature, choix explicite

`AvatarKind::Upload` (`upload`) rejoint `preset` et `provider`. Le compte **choisit** sa nature effective dans l'écran « Avatar » des réglages (`settings/avatar`, `account.avatar.*`) : un prédéfini, ou son image. Le dernier choix l'emporte ; l'image non choisie reste stockée, pour y revenir sans la téléverser de nouveau. `users.avatar_preset` reste le prédéfini du compte, et sert de **repli** quand l'image est masquée ou retirée.

Chaîne de `User::avatarRef()` (§ 5.3 de `10`, amendée) : `upload` et image **visible** (`avatar_upload_path` non nul, `avatar_upload_hidden_at` nul) → image ; `preset` (ou `upload` masquée) et `avatar_preset` non nul → prédéfini ; `provider` visible → copie provider (J2) ; sinon initiales de `users.name`. `altKey` d'une image : `common.avatar.alt.upload`.

### 11.2 Téléversement

- **Navigateur** : l'écran ouvre un fichier JPEG, PNG ou WebP, propose un **recadrage carré** (déplacement et zoom), et envoie un carré de `AvatarImage::SOURCE_SIZE_PX` (512) de côté, encodé en WebP (repli JPEG là où le navigateur n'encode pas le WebP), qualité descendante sous le plafond (`components/account/avatar-cropper.tsx`). Le travail lourd reste dans le navigateur, comme pour la voie capture (`CLAUDE.md` § 8 : dépasser `post_max_size` vide le jeton CSRF).
- **Serveur autoritaire** (`App\Avatars\AvatarImage::normalize()`) : plafond `PlatformLimits::avatarUploadMaxKilobytes()` (512 par défaut, sous `upload_max_filesize`) vérifié par la règle de formulaire, donc une erreur traduite et jamais un 419 ; type relu par `finfo` (JPEG, PNG, WebP) ; en-tête borné **avant décodage** (`AvatarImage::MAX_SOURCE_PX`, 4096) ; image animée refusée par un « ping » qui compte les images sans les décoder ; `Imagick::setResourceLimit` posé avant toute lecture ; recadrage au **carré central** (le serveur ne fait pas confiance au carré reçu) ; sRGB ; `stripImage()` ; réduction à `AvatarImage::OUTPUT_SIZE_PX` (256) ; WebP à qualité descendante jusqu'à `AvatarImage::MAX_OUTPUT_BYTES` (40 Ko). La transparence est conservée.
- **Synchrone**, dans la requête : la source est petite et bornée, et le joueur attend son image. Exception assumée et circonscrite à `AvatarImage` : « aucun traitement Imagick dans une requête HTTP » reste vrai pour les images de jeu (`FrameImageProcessor`).
- **Écriture** : nouveau fichier `upload/<32 hex>.webp` (`bin2hex(random_bytes(16))`, jamais un ULID ni un identifiant), bascule de `avatar_upload_path` **et** `avatar_kind = upload` sous `lockForUpdate`, puis suppression de l'ancien fichier **après** le commit. `avatar_upload_reports_from` repart à l'instant du téléversement : les signalements d'une image ne comptent pas contre la suivante.
- **Refus** (`validation` sous `avatar`, messages `account.avatar.errors.*`) : fichier trop lourd, format refusé, image animée, image illisible ou trop grande ; **téléversement bloqué** tant que l'image est masquée ou retirée (`avatar_upload_hidden_at` non nul) — sans quoi un masquage se contournerait en téléversant une autre image.
- **Suppression par le titulaire** : fichier supprimé après le commit, `avatar_upload_path` vidé, `avatar_kind` ramené à `preset` si `avatar_preset` existe, sinon nul. `avatar_upload_hidden_at` est **conservé** : un compte masqué ne se démasque pas en supprimant puis en téléversant.
- Limiteur `throttle:avatar-upload` (10 par heure et par compte).

### 11.3 Stockage et service

Disque **`avatars`** (`driver local`, `serve => false`, privé, racine `AVATARS_DISK_ROOT`, vide = `storage/app/avatars`, **hors du chemin de déploiement en production** comme `frames`). **Aucun `storage:link`** : les octets passent par la route `GET /a/{file}` (`avatar.show`, `App\Http\Controllers\Avatar\AvatarFileController`), sans session ni cookie, qui ne sert un fichier que s'il est l'image **courante et visible** d'un compte non anonymisé — 404 sinon, image masquée, retirée ou remplacée comprise : le masquage s'applique aussi à une URL déjà vue. Réponse `image/webp`, `Cache-Control: public, max-age=31536000, immutable` (un nom aléatoire ne désigne jamais deux contenus), `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex`. Ce qui reste vrai : **aucune frame sur ce disque, jamais**, et les avatars ne passent pas par la route des images de jeu.

Les images téléversées **ne vont pas dans les sauvegardes** : ce sont des actifs du compte que le titulaire peut téléverser de nouveau ; le manifeste de `100` § 13 ne couvre que `frames`.

### 11.4 Le siège d'un compte

- **I4.10 amendé** : la prise de siège (salon et solo) écrit `player.user_id` = le compte connecté (§ 2.4). Ni `users.name` ni `users.real_name` ne sont recopiés (I5.11).
- **Attribution automatique à la prise de siège** (D55 du 02/10 — amendé le 02/10), sous le verrou du salon : si `users.avatar_kind = upload` et que l'image téléversée est visible → `account`, siège `avatar_kind = upload` (même règle que la présélection d'avant D55) ; sinon → `AvatarPresetCatalog::suggest(préféré du jeton, pris)`, siège `avatar_kind = preset`. Une photo provider visible d'un compte qui a choisi un prédéfini n'est pas attribuée : le choix du compte prime.
- **Au lobby**, la prop `avatars.account` = `{ url }` (`SeatAvatar::accountOption()`) quand le compte porte une image visible, `null` sinon ; le sélecteur l'affiche en première tuile, « Mon avatar » (`common.avatar.picker.account`), de valeur `account` (`SeatAvatar::ACCOUNT`). Les formulaires d'entrée ne la reçoivent plus.
- **Le compte du siège, jamais celui de la requête** : « Mon avatar » se lit sur `player.user_id`, figé à la prise de siège, que lit aussi l'affichage (`Player::avatarRef()`) ; `SeatAvatar::seatAccount()` ne retient le compte connecté que s'il est celui du siège. Un invité qui se connecte après avoir pris son siège, ou un autre compte connecté sur le même `player_token`, ne reçoit donc pas `avatars.account` et voit `account` refusé (amendé le 02/10).
- La règle de formulaire (`seatAvatarRules()`, appliquée par `ChangeSeatAvatarRequest`) n'admet `account` que pour une requête authentifiée par le compte du siège, et dont ce compte porte une image visible. Sous le verrou, `SeatAvatar::resolve()` relit le compte : image toujours visible → siège `avatar_kind = upload` ; sinon → prédéfini. Dans les deux cas `avatar_preset` reçoit un **prédéfini de repli** : `suggest(users.avatar_preset ?? préféré du jeton, pris)`, qui évite les clés prises quand c'est possible (D55 du 02/10 ; avant, le prédéfini du compte était repris même pris). C'est ce prédéfini que porte la re-signature du jeton (I4.5).

### 11.5 Résolution vivante, gel au lancement

`Player::avatarRef()` et `GamePlayer::avatarRef()` gagnent une branche `upload` : image **visible** du compte rattaché (`player.user_id`), lue à chaque composition par `UploadedAvatars::visiblePath()` — une lecture par clé primaire, seulement pour un siège de nature `upload` ; sinon le prédéfini de repli du siège ; sinon les initiales **du pseudo**. Le gel du lancement (`OpenGame`, O6) et l'admission d'un retardataire recopient la nature et le prédéfini de repli, jamais un chemin. `PlayerIdentity::FROZEN_SEAT_COLUMNS` gagne `user_id`. Un masquage s'applique donc partout, partie en cours comprise, à la prochaine composition d'une vue.

### 11.6 Signalement et masquage

- **Qui** : tout siège actif (`seat.active`) d'un salon, sur un **autre** siège du même salon dont l'avatar affiché est une image téléversée — nature vivante du siège, ou nature gelée de sa participation à la partie en cours. Route `POST /r/{room}/players/{target}/report-avatar` (`room.players.report_avatar`), `throttle:game-write`, `App\Actions\Room\ReportSeatAvatar`. Bouton « Signaler l'avatar » (`common.avatar.report.*`) sur la liste des sièges du lobby et la bande des joueurs en partie, avec confirmation. Un siège ne se signale pas lui-même ; un avatar prédéfini n'est **jamais** signalable (I5.9).
- **Ligne** : `report` de cible `uploaded_avatar` (`ReportTarget::UploadedAvatar`), `target_user_id` = le compte ; `insertOrIgnore` sur l'unique `(reporter_player_id, target_user_id)` : un second clic du même siège ne compte pas et ne dit rien de plus.
- **Seuil** : `ReportSeatAvatar::DISTINCT_REPORTERS` = 2 sièges distincts, comptés sur les lignes `uploaded_avatar` de ce compte créées **depuis** `avatar_upload_reports_from`. Atteint, dans la même transaction, compte verrouillé : `avatar_upload_hidden_at = now`, puis `AdminJournal::recordAutomatic(AvatarHidden, user, n)`. Rien n'est supprimé : l'administrateur doit pouvoir voir l'image pour lever ou retirer.
- **Réponse** au signaleur : un retour neutre (`common.avatar.report.sent`), identique que le seuil soit atteint ou non, image déjà masquée comprise.
- **Aucune notification par e-mail au J1** : l'écran « Avatar » du titulaire dit l'état (`account.avatar.hidden_notice`) et ferme le téléversement.

### 11.7 Levée et retrait par l'administrateur

Écran « Avatars » de `20` (ligne 45, § 12.5), administrateur seul, `UserPolicy::moderateAvatar` :

- **Lever** (`avatar.unhidden`, motif facultatif) : `avatar_upload_hidden_at` vidé, `avatar_upload_reports_from` = maintenant (le compteur repart de zéro). Lever un compte dont l'image a été retirée rouvre le téléversement.
- **Retirer** (`avatar.removed`, **motif obligatoire**, cas nouveau d'`AdminActionType`) : fichier supprimé après le commit, `avatar_upload_path` vidé, `avatar_upload_hidden_at` posé s'il ne l'était pas (téléversement bloqué jusqu'à une levée), `avatar_kind` ramené au prédéfini ou nul.

Chaque geste s'écrit dans sa transaction par `AdminJournal::record()`, sujet le compte.

### 11.8 Rétention

L'image et ses trois colonnes sont des **actifs du compte** : elles vivent tant que le compte vit, hors de la fenêtre de 12 mois. L'anonymisation (J2, § 10 sujet 9) supprimera le fichier et videra les colonnes, `avatar_upload_hidden_at` comprise. Les lignes `report` suivent leur périmètre (12 mois) : les purger ne démasque rien.

### 11.9 Clés de traduction

`common.avatar.alt.upload`, `common.avatar.picker.account`, `common.avatar.report.{action,confirm_title,confirm_body,confirm,cancel,sent}` ; `account.avatar.*` (titre, choix, téléversement, recadrage, suppression, état masqué, erreurs) ; `admin.avatars.*` et `admin.enum.admin_action.avatar_removed` (français seul). FR et EN dans le même commit pour les domaines joueur.

### 11.10 Lot

**L40-8 — Avatar téléversé** (J1, D49 du 01/10). Migration additive `users` (trois colonnes) ; `AvatarKind::Upload`, `ReportTarget::UploadedAvatar`, `AdminActionType::AvatarRemoved` ; disque `avatars` et route `avatar.show` ; `AvatarImage`, `UploadedAvatars`, `SeatAvatar` ; actions `Account\{UploadAvatar,ChooseAvatar,DeleteAvatar}`, `Room\ReportSeatAvatar`, `Admin\{UnhideAvatar,RemoveAvatar}` ; écrans `settings/avatar` et `admin/avatars/index` ; sélecteur et bouton de signalement (sélecteur déplacé au lobby par D55 du 02/10, lot `50` L50-14 — amendé le 02/10). Tests : `tests/Feature/Identity/AvatarUploadTest.php`, `AvatarReportTest.php`, `tests/Feature/Admin/AvatarModerationTest.php`.

---

## 12. Connexion par Discord et Google [J1, D51 du 01/10]

Ajoutée le 01/10 (D51 du 01/10). Avance au J1 les sujets 2, 3 et 4 du § 10, dans les règles déjà arrêtées par `00` § Comptes & profils, que cette section ne rouvre pas.

### 12.1 Paquets, fournisseurs actifs, chemins

- `laravel/socialite` et `socialiteproviders/discord` ; l'écouteur `SocialiteWasCalled` est posé dans `AppServiceProvider::boot()` (aucun `EventServiceProvider`). Blocs `google` et `discord` de `config/services.php` : `client_id`, `client_secret` lus dans l'environnement, `redirect` **relatif** (`/auth/google/callback`), résolu contre `APP_URL` : aucun nom de domaine dans le dépôt.
- **Un fournisseur est actif si et seulement si ses deux clés sont posées** (`App\Support\Identity\OAuthProviders::enabled()`). Variables `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `DISCORD_CLIENT_ID`, `DISCORD_CLIENT_SECRET`, vides dans `.env.example` (CI à zéro secret). Un fournisseur inactif répond 404 sur ses routes et n'a aucun bouton.
- **Indépendant de `ACCOUNTS_REGISTRATION_OPEN`** (D51) : cet interrupteur ne gouverne plus que l'inscription par mot de passe. Prop partagée `oauthProviders: list<'google'|'discord'>` ; l'en-tête public rend « Se connecter » si `accountsOpen` **ou** si un fournisseur est actif, « Créer un compte » si `accountsOpen` seulement.
- Scopes minimaux : Discord `identify email`, Google `openid email profile`.
- Routes (`routes/auth.php`, `throttle:oauth`, 10 par minute et par adresse) :

| Nom | Méthode et chemin | Garde | Rôle |
|---|---|---|---|
| `oauth.redirect` | `GET /auth/{provider}/redirect?intent=login\|link\|confirm` | `login` : invité ; `link`, `confirm` : `auth` | Pose l'intention en session, renvoie au fournisseur |
| `oauth.callback` | `GET /auth/{provider}/callback` | aucune | Table du § 12.2 |
| `oauth.finish` | `GET /auth/finish` | invité, inscription en attente | Écran « Finaliser l'inscription » |
| `oauth.finish.store` | `POST /auth/finish` | idem | Création du compte (§ 12.3) |
| `linked_accounts.edit` | `GET /settings/accounts` | `auth` | Écran « Comptes liés » |
| `linked_accounts.destroy` | `DELETE /settings/accounts/{provider}` | `auth`, `password.confirm` | Déliaison (§ 12.5) |

URI de redirection à déclarer chez chaque fournisseur : `https://<DOMAINE>/auth/google/callback` et `https://<DOMAINE>/auth/discord/callback` ; en développement, `https://dev.<DOMAINE>/…` ou `http://localhost:8000/…` (Google refuse `http` hors `localhost`). Google demande aussi l'origine JavaScript `https://<DOMAINE>`.

### 12.2 Table de décision du retour

L'identité du fournisseur (`ProviderIdentity`) porte : identifiant, e-mail ou `null`, e-mail **déclaré vérifié** (Google `email_verified`, Discord `verified`), pseudo suggéré (Discord `global_name` puis `username`, Google `given_name` puis `name`), URL de la photo. Un refus de l'utilisateur chez le fournisseur, un état invalide ou une erreur réseau ramènent à la connexion avec `account.oauth.errors.failed`.

| Intention | Situation | Effet |
|---|---|---|
| `login` | Compte fournisseur déjà lié | Connexion de son titulaire ; **défi 2FA de Fortify** s'il a une 2FA confirmée (session `login.id`, redirection `two-factor.login`) ; compte anonymisé : refus |
| `login` | Non lié, e-mail vérifié par le fournisseur = e-mail **vérifié** d'un compte sans 2FA | **Liaison automatique** (consentement daté, photo), puis connexion |
| `login` | Idem, mais le compte a une 2FA confirmée | Refus `two_factor_link` : se connecter par mot de passe, puis lier depuis les réglages — jamais de liaison sans second facteur |
| `login` | Non lié, e-mail déjà porté par un compte, sans les conditions ci-dessus | Refus `email_taken` |
| `login` | Non lié, aucun compte | Inscription **en attente** en session (15 minutes), écran « Finaliser l'inscription » |
| `link` | Lié à un autre compte | Refus `linked_elsewhere` ; la session courante ne change pas |
| `link` | Ce compte a déjà un autre identifiant chez ce fournisseur | Refus `provider_taken` |
| `link` | Sinon | Liaison (consentement daté ; photo si première liaison) |
| `confirm` | Lié au compte connecté | Confirmation fraîche (`auth.password_confirmed_at`), retour à la page demandée |
| `confirm` | Sinon | Refus `confirm_mismatch` |

Un compte sans e-mail (Discord peut n'en rendre aucun) est valide et n'est jamais cible d'une liaison automatique.

### 12.3 Finaliser l'inscription

Écran `auth/oauth-finish` : fournisseur, adresse reçue (rien si absente), champ **nom** prérempli par la suggestion et validé par les règles du profil, cases **CGU** (lien vers `legal.terms`, nouvel onglet) et **âge** (15 ans au moins). Envoi : dans une transaction, relecture de l'identifiant (déjà lié → connexion de son titulaire) et de l'adresse (prise entre-temps → refus `email_taken`) ; `users` (mot de passe nul, `email_verified_at` posé si le fournisseur déclare l'adresse vérifiée, `locale` de la requête, projections `terms_*` et `age_confirmed_at`), `linked_account`, trois lignes `user_consent` (`terms`, `age`, `provider_<p>`) à la version courante `config('legal.terms_version')`. Après validation : copie de la photo (§ 12.6), e-mail de vérification si l'adresse n'est pas vérifiée (`Registered`), connexion, redirection vers `fortify.home`. **Aucun rôle** n'est attribué par cette voie.

### 12.4 Compte sans mot de passe

- La page de confirmation (`auth/confirm-password`) propose « Confirmer avec Google / Discord » pour chaque fournisseur lié ; un compte sans mot de passe n'y voit pas le champ mot de passe.
- L'écran Sécurité d'un compte sans mot de passe propose **Définir un mot de passe** sans mot de passe actuel ; il est derrière `password.confirm`, donc après une ré-authentification fraîche.

### 12.5 Comptes liés et déliaison

Écran `settings/accounts` : par fournisseur actif, « Lié » avec la date, ou « Lier ». La déliaison :
- exige une confirmation fraîche (`password.confirm`) et, si la 2FA est confirmée, un **code** (TOTP ou de récupération) ;
- est refusée (`last_method`) si elle retire la dernière méthode de connexion — mot de passe, autre fournisseur lié, passkey ;
- **supprime la ligne** `linked_account` (identifiant et e-mail fournisseur disparaissent) ; les lignes `user_consent` restent ;
- délier le **fournisseur d'origine** de la photo supprime le fichier et vide `avatar_provider_path` et `avatar_provider_source` ; l'avatar redescend la chaîne. Un masquage (`avatar_provider_hidden_at`) survit.

### 12.6 La copie locale de la photo

Job `CopyProviderAvatar` sur la file **par défaut**, à la création et à la **première** liaison seulement (aucune copie existante, aucun masquage). Liste blanche d'hôtes (`cdn.discordapp.com`, `lh3`…`lh6.googleusercontent.com`), HTTPS seul, **aucune redirection suivie**, délai 5 s, 2 Mo au plus, puis la même normalisation qu'un avatar téléversé (`AvatarImage`), fichier `provider/<32 hex>.webp` sur le disque `avatars`, servi par `avatar.show`. Elle devient l'avatar effectif seulement si le compte n'en avait **aucun** (`avatar_kind` nul) : jamais par-dessus un choix. L'URL distante est effacée de `linked_account` après le téléchargement ; elle n'est jamais servie au client. Un échec est consigné et abandonné (aucun réessai) : la photo est un confort.

Affichage, choix et modération suivent l'image téléversée (§ 11) : l'écran « Avatar » propose « Ma photo Google/Discord » ; le siège « Mon avatar » prend l'image personnelle **effective** du compte (téléversée ou photo, selon le dernier choix) ; deux sièges distincts depuis `avatar_provider_reports_from` la masquent (`report` cible `provider_avatar`, `avatar.hidden`) ; l'écran admin « Avatars » la lève ou la retire (`avatar.unhidden`, `avatar.removed`), filtre « nature ».

### 12.7 Clés de traduction

`account.oauth.*` (boutons, écran de finalisation, refus `failed`, `two_factor_link`, `email_taken`, `linked_elsewhere`, `provider_taken`, `confirm_mismatch`, `last_method`, `pending_expired`), `account.linked.*` (écran Comptes liés), `account.avatar.use_provider`, `common.provider.{google,discord}`. FR et EN dans le même commit.

### 12.8 Lot

**L40-9 — Connexion Discord et Google** (J1, D51 du 01/10). Paquets ; `config/services.php`, `.env.example` ; `routes/auth.php` ; `OAuthProviders`, `ProviderIdentity`, actions `Account\{HandleOAuthCallback,CreateOAuthAccount,LinkProvider,UnlinkProvider}`, job `CopyProviderAvatar` ; migration additive `users` (`avatar_provider_source`, `avatar_provider_reports_from`) ; `ConsentKind` (deux cas) ; écrans `auth/oauth-finish`, `settings/accounts`, boutons de connexion, d'inscription et de confirmation. Tests : `tests/Feature/Auth/OAuthLoginTest.php`, `OAuthLinkTest.php`, `tests/Feature/Identity/ProviderAvatarTest.php`. Socialite est simulé en test : aucune clé, aucun appel réseau (CI à zéro secret).

## 13. Jalon 2 — rédigé le 07/10 (D66 du 07/10)

Rédige les sujets 1, 5, 6, 7, 8, 9, 10, 11, 14 et 15 du § 10.1 sur les réponses du porteur du 07/10 (D66 du 07/10, `questions-ouvertes.md` ; questions numérotées « n° 1 » à « n° 51 » de l'annexe archivée `j2-decisions-proposees.md`). Toutes les recommandations sont retenues, **sauf n° 23** : le rattachement d'un siège invité au compte est **automatique** (§ 13.2). Rien de ce qui suit ne rouvre une règle du J1 ; chaque écart à une phrase des § 1 à 12 est marqué « — amendé le 07/10 (D66 du 07/10) » à l'endroit qu'il révise.

### 13.1 Ouverture des comptes (sujet 1)

- **Inscription Fortify** (`CreateNewUser`) : deux cases **distinctes, non pré-cochées**, `terms` (lien `legal.terms`, nouvel onglet) et `age` (15 ans au moins), chacune `accepted`, messages `account.consent.errors.{terms_required,age_required}`. Une **seule** écriture de consentement, `App\Actions\Account\RecordConsents` [nouveau], partagée avec `CreateOAuthAccount` (§ 12.3) : dans la transaction de création, `users` (projections `terms_accepted_at`, `terms_version`, `age_confirmed_at`, `locale` de la requête) et une ligne `user_consent` par cas (`terms`, `age`) à la version `config('legal.terms_version')`. **Aucun rôle** n'est attribué par une inscription ni par une liaison (sujet 14). `last_login_at` est posé par la connexion qui suit la création (écouteur `RecordLastLogin` sur `Login`, ratifié ici : point EL19-5 de `20`).
- **Limiteur nommé `register`** (`FortifyServiceProvider::configureRateLimiting()`), par adresse IP, appliqué à `register.store` par l'intergiciel `accounts.switches` (la route reste enregistrée, § 8.2) ; un refus rend 429 traduit, jamais une 1062.
- **`users.name` est le pseudo persistant du compte** (n° 20) : la **règle de pseudo de jeu** (`ValidNickname`, étapes 1 à 5 du § 5.4 : longueur, écriture latine, caractères, alphanumérique, forme repliée, liste noire), **sans** l'unicité par salon, s'applique à l'inscription, à la finalisation OAuth (§ 12.3) et au profil, par `ProfileValidationRules::nameRules()`. Elle ne s'applique qu'à une écriture **qui change** `name` : un nom antérieur non conforme n'est jamais migré ni refusé tant qu'il n'est pas modifié ; il n'est simplement pas prérempli (§ 13.9).
- **Ré-acceptation des CGU** (Q40-6, n° 12, n° 13, n° 39). Quand `users.terms_version` diffère de `config('legal.terms_version')`, l'intergiciel `App\Http\Middleware\EnsureTermsAccepted` (alias `terms.current`) redirige vers l'interstitiel `auth/terms-update` (`GET /account/terms` → `terms.show`, `POST /account/terms` → `terms.accept`, `AuthLayout`). Il couvre **toutes les pages de compte** (`routes/settings.php`, historique, configurations), **sauf** déconnexion, export (§ 13.5), suppression du compte (§ 13.6), l'interstitiel lui-même, les pages légales, OAuth, la vérification d'adresse et les routes de Fortify. **Le jeu n'est jamais bloqué** : aucune route de `routes/game.php`, de salon ni de solo ne porte `terms.current` (principe 10). **Le back-office en est exempté** (n° 13) : `/admin` ne porte pas l'intergiciel ; l'interstitiel s'applique au titulaire sur ses pages de compte seulement. Accepter écrit, en une transaction, une ligne `user_consent` `terms` à la version courante (unicité `(user_id, kind, version)` respectée) et `age` si `age_confirmed_at` est nul (cas du premier administrateur), puis les projections. Ne pas accepter suspend les fonctions du compte ; l'interstitiel offre la déconnexion, l'export et la suppression. **Aucun e-mail** n'annonce un changement de version (n° 39) : le contrôle se fait à la page suivante. Le passage de `legal.terms_version` de `provisoire-1` à la version définitive se fait dans le commit qui publie le texte (`90` § 11.5) et déclenche la ré-acceptation de tous les comptes, OAuth compris (D51 du 01/10).
- **Clés** : `account.consent.*`, `account.terms_update.*` (FR et EN dans le même commit).

### 13.2 Rattachement d'un siège invité à un compte (sujet 5, n° 23, n° 24, n° 25)

**Rattachement automatique** (n° 23, choix du porteur contre la recommandation du bandeau de confirmation). Quand une page de siège — `room.show` (`game/lobby`, du lobby au podium) ou `solo.show` (`game/solo`) — est rendue pour un **compte connecté** et que le siège tenu par le `player_token` de la requête a `player.user_id` nul, l'action `App\Actions\Identity\ClaimSeatForAccount` [nouvelle] rattache **ce seul siège** (n° 24 : jamais les autres sièges du jeton, solo compris quand la page est `game/solo`) au compte, sans confirmation.

- **Où, et jamais ailleurs** : au rendu de la page de siège, dans la requête qui la sert ; **jamais** dans un écouteur de `Login`, `Logout` ou `Registered` (I4.6 inchangé : la connexion, la déconnexion et l'inscription ne lisent ni n'écrivent le cookie `player_token`). La connexion dans un autre onglet ne crée jamais de second siège ; le rattachement arrive à la page de siège suivante.
- **Gardes**, relues sous `lockForUpdate` du siège (et du salon pour un siège de salon), chacune rendant « rien à faire » en silence, jamais une erreur : salon non archivé (le hash du jeton existe encore) et siège solo non effacé ; siège non expulsé (`kicked_at` nul) ; `player.user_id` toujours nul (**jamais** l'écrasement d'un siège déjà lié à un autre compte) ; compte non anonymisé ; **aucun autre siège du même compte dans le même salon** (sinon double compte dans l'historique). La fenêtre se ferme mécaniquement à l'archivage (`10` § 7.1) et à l'effacement du siège solo.
- **Écritures** : `player.user_id` = le compte et `player.locale` = `users.locale` (comme une reprise, `05`) ; **jamais** `nickname` (I5.11), **jamais** `game_player` (gel de la partie en cours, § 7.2). Le cache court de l'historique du compte est oublié (§ 13.4).
- **Avatar** (n° 25, règle D55) : salon en `lobby` → dans la même transaction, la règle d'attribution du § 11.4 est rejouée pour ce siège (image visible du compte si sa clé est libre, `TakenAvatars`), puis `seat.updated` est diffusé après commit ; partie en cours ou podium → une marque de cache `seat-claim:avatar-pending:{player.id}` (durée : l'échéance d'archivage du salon, lue dans `RoomExpiry`) est consommée au retour au lobby (« Rejouer », `50`), qui applique la même règle sous le verrou du salon. Marque perdue : aucune bascule, « Mon avatar » reste offert au lobby (`SeatAvatar::seatAccount()` est vrai dès le rattachement). Solo : aucune bascule (D55 : pas de choix d'avatar en solo). Le pseudo ne change jamais.
- **Retour** : un avis traduit `room.seat.claimed` (prop flash, rendu dans la page), une seule fois par rattachement.
- **Livré (L40-11, 07/10)** — amendé le 07/10 (D66 du 07/10) : l'appel au rendu passe par le trait `App\Http\Controllers\Room\Concerns\AttachesSeatToAccount` (`RoomController@show` après la réparation d'hôte, `SoloGameController@show`), qui pose le flash `game_notice` — jamais `toast`, une page de jeu n'émettant aucun toast (`90` § 2.3) — rendu en texte par `GameLayout` (`useFlashNotice`, qui lit `game_notice` puis le `toast` de la page expirée, chargement complet compris) ; la clé du cache de l'historique vit dans `App\Support\Account\PlayHistoryCache` (`key()`, `forget()`), que `PlayHistory` et `ForgetPlayHistoryCache` (§ 13.4) lisent ; la durée de la marque d'avatar est `RoomExpiry::archiveDeadlineFrom($now)` (24 h + une cadence de balayage) ; le retour au lobby la consomme à l'étape R9 de `ReplayRoom` (`50` § 13). La bascule ne vise que l'image **téléversée** visible (règle de `SeatAvatar::assign()`) : un compte sans elle garde l'avatar du siège, « Mon avatar » restant offert au lobby. Le jeton n'est pas re-signé au rattachement (aucune préférence d'avatar ne change).
- **Appareil partagé — résidu assumé par le porteur (D66 du 07/10)** : sur un navigateur partagé, la partie d'une autre personne tenue par le même cookie est rattachée au compte qui s'y connecte. Bornes : un seul siège, celui de la page ouverte ; jamais un siège déjà lié ; jamais après l'archivage. **Aucun geste de détachement en v1** ; une demande passe par le contact (l'administrateur peut anonymiser, § 13.6). La page de confidentialité le dit (`90` § 11.5).
- **Tests** : `tests/Feature/Identity/SeatClaimTest.php` — « rattache au compte connecté le siège invité de la page, sans second siège » ; « ne rattache jamais un siège dans un écouteur de connexion » ; « ne rattache qu'un siège, celui de la page, jamais les autres sièges du jeton » ; « ne rattache ni un siège d'un salon archivé, ni un siège expulsé, ni un siège déjà lié à un autre compte » ; « ne rattache pas un second siège du même compte dans un salon » ; « laisse intacts le pseudo et l'identité gelée de la partie en cours » ; « bascule l'avatar sur l'image du compte au lobby, et au retour au lobby après une partie » ; « rattache un siège solo et sa partie apparaît dans l'historique ».

### 13.3 Signalement et masquage du pseudo (sujet 6, n° 8, n° 26)

Même patron que l'image téléversée (§ 11.6), cible **pseudo** :

- **Qui** : un siège actif (`seat.active`) d'un salon multijoueur, non expulsé, non parti, sur un **autre** siège du même salon dont le pseudo n'est pas nul. Jamais en solo. Route `POST /r/{room}/players/{target}/report-nickname` (`room.players.report_nickname`), limiteur nommé `seat-report` (par siège, repli sur l'IP), `App\Actions\Room\ReportSeatNickname`. Bouton « Signaler le pseudo » (`common.player.report.*`) à côté de « Signaler l'avatar », sur la liste des sièges du lobby et la bande des joueurs en partie, avec confirmation.
- **Ligne** : `report` de cible `nickname`, `target_player_id` = le siège visé ; `insertOrIgnore` sur l'unique `(reporter_player_id, target_player_id)`.
- **Seuil** : `ReportSeatNickname::DISTINCT_REPORTERS` = 2 sièges distincts, comptés sur les lignes `nickname` du siège visé créées **depuis** `player.nickname_reports_from` (nul = depuis toujours ; colonne nouvelle, exigence à `10`). Les signaleurs antérieurs à une levée ne comptent plus et ne peuvent plus signaler ce siège (unique `(reporter_player_id, target_player_id)`, ligne conservée) : seuls deux sièges nouveaux le remasquent, résidu assumé — l'admin peut bannir — amendé le 07/10 (D66 du 07/10). Atteint, dans la même transaction, siège visé verrouillé : `nickname_masked_at = now`, puis `AdminJournal::recordAutomatic(NicknameMasked, player, n)`. Après commit, `seat.updated` porte l'identité masquée ; partie en cours, podium et resynchronisation la relisent sur le siège vivant (I5.10).
- **Réponse** au signaleur : `common.player.report.sent`, identique dans tous les cas acceptés (déjà signalé, déjà masqué, seuil atteint ou non).
- **Masquage pour la vie du siège**, levée par l'administrateur seul ; aucun e-mail (`10` § 8.1).
- **Avis au joueur masqué** (n° 26) : un avis discret, `common.player.masked_notice`, rendu **à ce seul siège** — le client reconnaît sa propre identité masquée —, jamais diffusé aux autres, jamais un e-mail ; il voit, comme les autres, le libellé neutre.
- **Rendu masqué** (I5.10) : `common.player.masked` (« Joueur :ordinal »), l'ordinal étant le rang du siège dans l'ordre d'arrivée du salon, stable quand le classement se réordonne ; jamais les initiales de l'avatar comme substitut. Un pseudo effacé par la rétention (`masked: false`, `nickname: null`) garde son rendu actuel. **Code livré — amendé le 07/10 (D66 du 07/10)** : l'ordinal est dérivé **au rendu**, sans champ ajouté à `PlayerIdentity` (contrat C5 inchangé), comme le rang du siège dans la liste `state.seats` **telle que le serveur l'envoie** — `joined_at` puis `id` au lobby (`50` § 8.1), `game_player.id` en partie (`60` § 11.5), ordre de lancement qui suit l'ordre d'arrivée ; `resources/js/lib/game/player-label.ts` (`seatOrdinals`, `playerLabel`), posé une fois par la page (`PlayerOrdinalsProvider`) et lu par la liste des sièges, la salle, la bande des joueurs, la révélation, le classement et le podium. Le débit du limiteur `seat-report` est `RoomRateLimits::seatReportsPerMinute()` (`game.room.seat_reports_per_minute`, 10 par défaut, borne basse 1) ; un signalement irrecevable revient sous l'erreur `nickname` (`common.player.report.not_reportable`). La diffusion des trois gestes passe par `App\Support\Moderation\NicknameSeatBroadcast` (salon vivant seulement), et l'état « banni » par `NicknameModerationState`. `banned.txt` est lu par `NicknameBlocklist::BANNED_FILE`, seule liste qui peut ne porter aucune entrée (§ 5.7).
- **Administrateur** (écran de `20` § 11.5, `PlayerPolicy::moderateNicknames` pour l'écran, `PlayerPolicy::moderateNickname` pour les gestes, administrateur seul) :
  - **Lever** (`nickname.unmasked`, motif facultatif) : `nickname_masked_at` vidé, `nickname_reports_from = now` ; refusé pour un siège banni.
  - **Bannir** (`nickname.banned`, n° 8, **motif obligatoire**) : masquage posé s'il ne l'était pas, **définitif** (la levée est ensuite refusée ; l'état se lit dans le journal, dernière ligne `nickname.*` du siège, aucune colonne). La **forme** du pseudo n'est **jamais** écrite dans `admin_action` (ligne permanente, aucune donnée personnelle) : l'écran liste les sièges bannis dont le pseudo n'est pas encore anonymisé, avec la forme repliée à recopier ; l'ajout à la liste noire versionnée se fait dans `resources/moderation/nicknames/banned.txt` [nouveau, en-tête de source « back-office » ; § 5.7], **au commit et au déploiement suivants**, geste humain relu. Aucune table de pseudos interdits (A15 de `10` intact).
- **Tests** : `tests/Feature/Room/NicknameReportTest.php` — « deux sièges distincts masquent un pseudo, le même siège deux fois jamais » ; « refuse le signalement de soi, d'un autre salon, d'un siège parti ou expulsé, et en solo » ; « répond le même retour neutre dans tous les cas acceptés » ; « diffuse l'identité masquée et masque l'affichage gelé de la partie en cours » ; « une levée repart d'une fenêtre de comptage vide » ; « purger les signalements ne démasque rien » ; `tests/Feature/Admin/NicknameModerationTest.php` — « seul un administrateur lève ou bannit un pseudo, et chaque geste écrit sa ligne » ; « un pseudo banni ne se lève plus » ; « le bannissement n'écrit jamais la forme du pseudo au journal ».

### 13.4 Historique sur 12 mois glissants et quatre compteurs (sujet 7, n° 29, n° 30, n° 31)

Lecteur unique `App\Support\Account\PlayHistory` (classe finale), sur les agrégats figés au podium (`80` § 10.4, § 13), sous l'invariant L1 de `10` § 1.8 ; **les compteurs ne lisent jamais `guess`** (`80` § 13), seul le détail d'une partie lit les `guess` du siège ; aucune requête dans le JSON, aucun agrégat stocké.

- **Périmètre** : sièges `player.user_id = compte`, parties gelées (`ended_at` non nul) dans la fenêtre `game.ended_at ≥ now − PlatformLimits::historyWindowMonths()` ; chemin `player_user_idx` → `game_player_player_game_uq` → `game_mode_ended_idx`.
- **Liste « Mes parties »** (`GET settings/history` → `history.index`) : de la plus récente à la plus ancienne, paginée ; par ligne, date, code du salon (ou « Solo »), manches jouées sur `M` (ou « interrompue à la manche k sur M »), `N`, score, rang (« — » en solo ou s'il est nul). **Une partie à `rounds_played = 0` n'est pas listée** (n° 31). La fenêtre et la date de la plus ancienne partie conservée sont affichées ; le bouton « Exporter mes données » mène au § 13.5.
- **Quatre compteurs** (`80` § 13), parties `multiplayer` à `rounds_played ≥ 1` : parties jouées ; bonnes réponses ; **meilleur score** = `MAX(final_score)` sur les parties à score non nul, avec sa date, `M`, `N` et l'ancre de sa ligne, « — » s'il n'en existe aucune — une partie sans score n'y entre jamais et reste comptée dans les trois autres (n° 29) ; taux = Σ `correct_answers` ÷ Σ `rounds_played`, « — » avec une ligne d'explication sous `PlatformLimits::successRateMinRounds()`. Chaque libellé porte la fenêtre (`:months`).
- **Détail d'une partie** (n° 30) : page `GET settings/history/{game:public_id}` → `history.show` (colonne `game.public_id`, exigence à `10`) ; manches `completed` seulement ; par film, titre dans la langue du joueur (repli sur le titre original), trouvé ou non par ce siège et points de la manche (ses `guess`, `guess.points_total`), lien Letterboxd (`RevealMovieBuilder`, `letterboxd-link.tsx`, nouvel onglet, D58 du 06/10) ; **aucune image, aucun pseudo d'un autre joueur** (décision 19). Une partie dont aucun siège n'appartient au compte répond **404** (garde de propriété `GamePolicy::viewHistory`), jamais 403.
- **Cache court** par compte, oublié par l'écouteur `App\Listeners\Account\ForgetPlayHistoryCache` sur `GameFinalized` (idempotent, tolère une partie purgée) et par le rattachement (§ 13.2).
- **Livré (L40-13, 07/10)** — amendé le 07/10 (D66 du 07/10) : migration additive `2026_10_07_100063_add_public_id_to_game_table` (rétro-remplie, UNIQUE `game_public_id_uq`) ; `game.public_id` frappé par le crochet `creating` du modèle `Game` (`OpenGame` comme les fabriques), `#[Hidden]` — il ne sort que par `PlayHistory`, jamais par un `toArray()`. Le cache ne tient que les compteurs et la date de la plus ancienne partie (`PlayHistory::summary()`, 600 s, clé `PlayHistoryCache::key()`) ; la liste paginée (20 parties par page, `?page=` lu avec indulgence) n'est pas mise en cache, une clé par page échappant à l'oubli. Le meilleur score mène au **détail** de sa partie (`history.show`), cible stable qu'une ancre de liste paginée ne serait pas ; chaque ligne garde l'ancre `game-{publicId}`. À égalité de meilleur score, la partie la plus récente. Le détail rejette aussi (404) une partie hors fenêtre ou à `rounds_played = 0`, comme la liste ; il distingue « trouvé », « non trouvé » et « manche non jouée » (aucune ligne `round_player` du siège : retardataire, départ), masque les points d'une partie sans score, et joint le domaine `game` à sa route pour `letterboxd-link.tsx`. Le taux est publié en fraction `[0 ; 1]`, nul sous le seuil ; le client le formate en pourcentage entier borné à 100 % (`lib/account/play-history.ts`). **Bouton « Exporter mes données »** : clé `account.history.export` posée, bouton **non rendu** tant que l'écran d'export (`GET settings/export`, § 13.5) n'existe pas — le lot de l'export l'ajoute sous la date de la plus ancienne partie, par son helper Wayfinder.
- **Tests** : `tests/Feature/Account/PlayHistoryTest.php` — « exclut une partie terminée il y a douze mois et un jour, et le meilleur score peut baisser » ; « liste une partie solo sans la compter » ; « compte une partie interrompue dès une manche close, jamais une partie à zéro manche » ; « n'inclut jamais une manche annulée » ; « exclut une partie sans score du seul meilleur score » ; « affiche un tiret sous le seuil du taux, jamais plus de cent pour cent » ; « répond 404 à la partie d'un autre compte » ; « oublie le cache à la fin d'une partie » ; « le détail ne montre aucun pseudo d'un tiers et porte le lien Letterboxd » ; garde d'architecture « les compteurs ne lisent jamais guess ».

### 13.5 Export en libre-service (sujet 8, n° 2, n° 3, n° 11, n° 32, n° 33)

- **Contenu** (n° 3) : toutes les données rattachées au compte — profil sans secret (nom, adresse et sa vérification, langue, rôle, nom réel s'il existe, dates de création et de dernière connexion, projections de consentement), lignes `user_consent`, comptes liés (fournisseur, identifiant, adresse fournisseur, date ; **jamais** `provider_avatar_url`), configurations sauvegardées, fichiers d'avatar du compte (image téléversée, copie provider), passkeys (nom et dates, jamais la clé), parties de ses sièges dans la fenêtre (réglages figés, rang, score, par manche le film — titre original et titre localisé —, trouvé ou non, palier, points, bonus, instant), ses bonnes réponses (`guess`) et ses réponses fausses (`wrong_answer`, D46 du 01/10), les signalements **émis** par le compte ou ses sièges (`content_report` : film, raison, texte, date ; `report` : nature de la cible et date, **jamais** l'identité du siège visé). **Exclus** : signalements reçus, `admin_action`, hachés de jeton, secrets 2FA, mots de passe, chemins de fichiers.
- **Format** (n° 2) : archive ZIP — `data.json` (lisible par machine, `export_version: 1`), `README.txt` dans la langue du compte, fichiers d'avatar. Nombres et dates du README par `App\Support\Format` (`05`). Dépendance **`ext-zip`** déclarée dans `composer.json`, ajoutée à `extensions:` de chaque job CI qui appelle `setup-php`, vérifiée sur PHP 8.3 FPM et CLI du VPS (geste humain).
- **Cycle de vie** : `POST settings/export` (`account.export.store`, limiteur nommé `data-export`, 3 par jour et par compte) — `App\Actions\Account\RequestDataExport`, sous verrou du compte : **une seule archive en préparation ou valide à la fois** (une archive valide est rendue, jamais doublée) ; ligne `data_export` (`requested_at`, `expires_at = requested_at + RetentionWindows::DATA_EXPORT_DAYS`, 7) ; job `App\Jobs\Account\BuildDataExport` sur la file **par défaut**, jamais `game`, lectures par lots bornés, fichier `path` nommé par ULID (`10` § 5.4) sur le disque privé **`exports`** (`EXPORTS_DISK_ROOT`, exigence à `10`), puis `size_bytes` et `completed_at`. Une archive non terminée au-delà de `config('accounts.export.stale_minutes')` s'affiche « échec — réessayer », état dérivé sans colonne.
- **Téléchargement** (n° 11) : `GET settings/export/download` (`account.export.download`), URL **signée temporaire** jusqu'à `expires_at` **et** session du titulaire (`auth`), sans identifiant dans l'URL (l'archive courante du compte) ; réponse `Content-Disposition: attachment`, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex` ; pose `downloaded_at`. Le chemin ne quitte jamais le serveur.
- **Notification** (n° 32) : e-mail `App\Notifications\Account\DataExportReady` (domaine `mail`, file, langue `users.locale` par `HasLocalePreference`) **si l'adresse est vérifiée** ; l'état (en préparation, prête, expirée, échec) est à l'écran dans tous les cas, `settings/export` (`account.export.*`).
- **Rétention et purge** : périmètre `data_export` (`DataExportHandler`, fichier puis ligne, lots bornés, plus les fichiers orphelins du disque de plus de 7 jours) ; l'anonymisation supprime les archives du compte **avant** de vider `users` (`App\Actions\Account\DeleteUserExports`, réutilisé par § 13.6). Le disque `exports` est **exclu des sauvegardes** : rien d'irremplaçable.
- **Invité** (n° 33) : aucun libre-service. La page de confidentialité explique l'article 11 du RGPD (un siège invité est pseudonyme et son jeton est effacé à l'archivage) et renvoie à `LEGAL_CONTACT_EMAIL` ; l'administrateur répond par la commande `account:export --seat=<public_id>` (et `--user=<id>` pour un compte qui n'a plus accès à son adresse), qui produit la même archive sur le disque `exports` et imprime son chemin ; l'archive d'un siège, sans ligne `data_export`, est effacée par la réconciliation des orphelins au bout de 7 jours. Commande sans ligne `admin_action` (la console n'est admise que pour `role.changed` et `site.*`, `10` § 8.3).
- **Mode fermeture** : l'export reste accessible (`90` § 11.7).
- **Tests** : `tests/Feature/Account/DataExportTest.php` — « une demande crée une ligne et un job sur la file par défaut, et ne double jamais une archive valide » ; « l'archive contient les réponses fausses et les signalements émis, jamais un haché, un secret ni un chemin » ; « le lien signé est refusé après expiration, sans session et pour un autre compte » ; « n'envoie l'e-mail qu'à une adresse vérifiée » ; « la purge supprime le fichier puis la ligne, et les orphelins » ; « l'anonymisation supprime les archives du compte » ; « la commande exporte un siège invité ».

### 13.6 Suppression du compte = anonymisation (sujet 9, sujet 14, n° 14, n° 27)

- **Libre-service** : `DELETE settings/profile` (`profile.destroy`, recréée), groupe `auth` (pas `verified` : un compte non vérifié doit pouvoir s'effacer), `password.confirm` (mot de passe, ou ré-authentification OAuth d'un compte sans mot de passe, D51 du 01/10), `ProfileController::destroy()` et `ProfileDeleteRequest` recréés, composant `components/account/delete-account.tsx` sur `settings/profile` (textes `account.delete_account.*` du § 8.3, `:months` = `PlatformLimits::historyWindowMonths()`). Après succès : déconnexion, session invalidée, accueil avec un avis traduit.
- **Refus traduits, jamais des 403**, relus sous verrou : **partie en cours** (n° 27) — un siège du compte dans une partie dont `ended_at` est nul — `account.delete_account.errors.game_running` ; **dernier administrateur** non anonymisé — verrous des administrateurs non anonymisés par identifiant croissant puis de la cible, comme `ChangeUserRole` — `account.delete_account.errors.last_admin`.
- **Écran spécifique** (`10` § 5.5) pour un compte qui a signé au moins une `frame_review` ou une ligne `admin_action` (`actor_id`) : il énonce ce qui est conservé (nom réel figé dans ces preuves) et pourquoi.
- **Action** `App\Actions\Account\AnonymizeAccount`, **une** transaction, liste close de `10` § 5.5 (amendée par D66) : archives d'export supprimées (fichiers après commit) ; `linked_account`, `saved_config`, passkeys, `password_reset_tokens` de l'ancienne adresse, sessions du compte supprimés ; tous les sièges détachés et effacés par la liste partagée `App\Support\Identity\SeatIdentityColumns` (pseudo, forme repliée, hachés de jeton, lien au visiteur et appareil), aussi lue par `OrphanPlayerHandler` et `GuestNicknameHandler`, et `game_player.display_nickname` ; `users` vidé (adresse, vérification, mot de passe, 2FA, `remember_token`, **toutes** les colonnes `avatar_*` et leurs fichiers, `real_name`), `role = player`, `locale` au repli d'instance, `name` = jeton neutre stable dérivé de l'identifiant, `anonymized_at` posé. Un compte `curator` ou `admin` écrit d'abord `role.changed` (vers `player`), **avant** de vider `real_name`, pour que l'historique daté des rôles reste vrai (acteur : le compte lui-même en libre-service, l'administrateur au back-office).
- **Pierre tombale** : connexion refusée par toutes les voies — mot de passe (garde explicite dans `Fortify::authenticateUsing`), « se souvenir de moi », passkey (supprimées), OAuth (déjà refusé, § 12.2).
- **Geste de l'administrateur** (n° 14) : `POST admin/accounts/{user}/anonymize` (`admin.accounts.anonymize`, `UserPolicy::anonymize`, administrateur seul, jamais sur soi-même — le libre-service existe —, `throttle:admin-curation`), **motif obligatoire**, mêmes refus, même action ; ligne **`user.anonymized`** (cas nouveau, sujet `user`, permanent, exigence à `10`) écrite dans la transaction. Il sert la demande d'effacement reçue au contact, le titulaire sans accès et le compte d'un mineur.
- **Cycle de vie du rôle vu du compte** (sujet 14) : aucun rôle par une inscription ni une liaison ; un retrait de rôle (vers `player`) ôte l'exigence de 2FA (§ 13.8) et garde `real_name` jusqu'à l'anonymisation, jamais montré hors du back-office ; l'anonymisation force `player` et vide `real_name` ; `curator` et `admin` sont exemptés de dormance (§ 13.7) ; la règle du dernier administrateur vaut sur tout chemin. Matrice, policies et écran des accès restent à `20`.
- **Tests** : `tests/Feature/Settings/AccountDeletionTest.php` — test nommé de `10` § 5.5 (solo et salon non archivé : aucun pseudo, forme repliée ni haché après anonymisation) ; « refuse la suppression pendant une partie en cours » ; « refuse le dernier administrateur, accepte un administrateur qui n'est pas le dernier et écrit role.changed avant de vider le nom réel » ; « conserve user_consent, reviewer_name et actor_name » ; « supprime les fichiers d'avatar et d'export après commit » ; « confirme un compte sans mot de passe par ré-authentification » ; « une pierre tombale ne se connecte plus par aucune voie » ; `tests/Feature/Admin/AccountAnonymizationTest.php` — « seul un administrateur anonymise un tiers, avec un motif, et écrit user.anonymized ».

### 13.7 Comptes dormants (sujet 10, n° 28)

Valeurs **confirmées** par le porteur (n° 28) : **24 mois sans connexion, puis anonymisation 30 jours après un rappel réellement envoyé**, ou directement pour un compte sans adresse. Constantes `RetentionWindows::DORMANCY_MONTHS = 24` et `DORMANCY_GRACE_DAYS = 30`, lues à leur source.

- **Balayage quotidien**, gestionnaire `DormantAccountHandler` du périmètre `dormant_account` (moteur de `100` § 14, file par défaut, lots bornés, ligne `purge_run`). Sélection par `users_role_last_login_at_index` : `role = 'player'`, `anonymized_at` nul, dernière activité (`last_login_at`, à défaut `created_at`) antérieure à 24 mois. Un compte dont un siège joue une partie en cours est sauté.
- **Rappel** : adresse non nulle et (`dormancy_notified_at` nul, ou antérieur à `last_login_at`) → notification `App\Notifications\Account\DormancyReminder` (domaine `mail`, langue du compte ; texte : se connecter suffit à garder le compte), **envoyée avant** l'écriture de `dormancy_notified_at`, pour qu'un envoi échoué soit retenté le lendemain.
- **Anonymisation** : `dormancy_notified_at` postérieur à la dernière connexion et antérieur à `now − 30 jours`, ou adresse nulle → `AnonymizeAccount` (§ 13.6), la seule action d'anonymisation. Aucune ligne `admin_action` (la ligne `purge_run` compte).
- **Réarmement** : `RecordLastLogin` remet `dormancy_notified_at` à nul ; un compte revenu repasse par un nouveau rappel.
- **Exemption** : `curator` et `admin`, par la colonne de tête de l'index.
- **Tests** : `tests/Feature/Retention/DormantAccountTest.php` — « envoie le rappel une seule fois et n'anonymise qu'au trentième jour » ; « anonymise directement un compte dormant sans adresse » ; « ne touche jamais un curateur ni un administrateur » ; « une connexion après le rappel réarme le cycle » ; « écrit sa ligne purge_run ».

### 13.8 Second facteur et passkeys (sujet 11, n° 10)

- **Règle retenue (n° 10, option A)** : **la 2FA TOTP reste obligatoire** pour `curator` et `admin` (valeur réversible du 23/09 **confirmée**) ; la garde `admin.2fa` (`EnsurePrivilegedTwoFactor`) continue de lire `two_factor_confirmed_at` seule. Une passkey à vérification de l'utilisateur vaut second facteur **à la connexion** (n° 37) : la connexion par passkey saute le défi TOTP (`userVerification = required`, imposé par le paquet), mais elle ne dispense jamais d'enrôler le TOTP. Une session ouverte par mot de passe n'entre jamais au back-office sans TOTP confirmé.
- **Désactivation interdite** : pour un rôle ≥ `curator`, la route `two-factor.disable` de Fortify est refusée (intergiciel sur la route nommée, refus traduit `account.two_factor.errors.privileged_required`) et l'écran Sécurité masque le bouton (prop `canDisableTwoFactor` de `SecurityController`). **Remplacement forcé interdit** au même rang : `two-factor.enable` avec `force` sur un TOTP déjà confirmé écrirait un secret neuf sans effacer `two_factor_confirmed_at`, que `admin.2fa` tiendrait pour confirmé ; il est refusé par le même intergiciel (`account.two_factor.errors.privileged_rotate`). Un `enable` sans `force` reste sans effet, la régénération des codes de récupération reste permise — amendé le 07/10 (D66 du 07/10).
- **Dernière méthode de connexion** : supprimer une passkey est refusé si elle est la dernière méthode (mot de passe, fournisseur lié, autre passkey), par la **même** aide que la déliaison (§ 12.5), `App\Support\Identity\LoginMethods`.
- **Configuration** : `config/fortify.php` lit `relying_party_id` dans `PASSKEYS_RP_ID` (repli sur l'hôte d'`APP_URL`) et `allowed_origins` dans `PASSKEYS_ALLOWED_ORIGINS` (liste séparée par des virgules, repli `[APP_URL]`) ; les deux variables sont vides dans `.env.example` ; aucun nom de domaine dans le dépôt. En développement sur `dev.<DOMAINE>`, `PASSKEYS_RP_ID=<DOMAINE>` (`100` § 27).
- **Ouverture en production** : `ACCOUNTS_PASSKEYS_ENABLED=true` posée par le porteur dans l'environnement de production, après livraison de ce lot, l'hôte d'`APP_URL` vérifié (apex `<DOMAINE>`) — **point de non-retour** : la première passkey enregistrée en production fige le domaine (décision 5).
- **Ré-acceptation** : une connexion par passkey passe par `terms.current` comme toute autre (§ 13.1). Une pierre tombale n'a plus de passkey (§ 13.6).
- **Tests** : `tests/Feature/Auth/PasskeyTest.php` — « les options exigent la vérification de l'utilisateur » ; « la connexion par passkey saute le défi TOTP » ; « le RP ID et les origines viennent de l'environnement, à défaut d'APP_URL » ; « refuse la suppression de la dernière méthode de connexion » ; `tests/Feature/Admin/PrivilegedTwoFactorTest.php` — « un curateur ou un administrateur ne désactive pas son second facteur » ; « une session ouverte par passkey sans TOTP confirmé n'entre pas au back-office ».
- **Livré (L40-15, 07/10)** — amendé le 07/10 (D66 du 07/10) : le relying party est lu par `App\Support\Identity\PasskeyRelyingParty` (un `PASSKEYS_RP_ID` écrit comme une URL est réduit à son hôte) ; l'intergiciel de refus est `App\Http\Middleware\KeepPrivilegedTwoFactor`, alias `two_factor.keep`, posé sur le groupe de Fortify (`config/fortify.php` › `middleware`) par nom de route comme `accounts.switches` (refus en erreur de validation sous la clé `two_factor` : 422 en JSON) ; l'écran remplace le bouton par `account.two_factor.manage.privileged_hint`. La suppression d'une passkey passe par `App\Actions\Account\DeletePasskeyUnlessLastMethod`, liée à la place de `Laravel\Passkeys\Actions\DeletePasskey` dans le conteneur (route `passkey.destroy` et contrôleur du paquet inchangés), sous le verrou du compte, refus `account.passkeys.errors.last_method` sous la clé `passkey`, affiché dans la boîte de confirmation. `Passkeys::authorizeLoginUsing` refuse la connexion d'une pierre tombale (`anonymized_at` posé), filet de l'effacement des passkeys du § 13.6. La ré-acceptation ne demande aucun code propre : la réponse du paquet mène à `fortify.home`, qui porte `terms.current`. **Changer d'application d'authentification** quand la désactivation et le remplacement forcé (`two-factor.enable` avec `force`) sont interdits — il n'existe aucune autre voie en libre-service : retrait du rôle par un autre administrateur (le retrait ôte l'exigence, § 13.6), nouvel enrôlement, restitution du rôle ; un téléphone perdu se contourne par un code de récupération (geste humain, `REPRISE.md`). Tests ajoutés : « une connexion par passkey passe par la ré-acceptation des CGU », « une pierre tombale ne se connecte jamais par passkey », « déclare les deux variables du relying party vides dans .env.example », « la déliaison et la suppression de passkey comptent les méthodes par la même aide », « un joueur garde la désactivation de son second facteur », « un curateur ou un administrateur ne remplace pas son secret TOTP confirmé par force », « un joueur garde la régénération forcée de son secret TOTP ».

### 13.9 Préremplissage du pseudo d'un compte connecté (sujet 15, n° 20, n° 21, n° 22)

- **Avatar de compte** : déjà livré par l'attribution serveur de D55 (§ 11.4).
- **Pseudo** : `PresentsSeatForm::nicknameProps()` ajoute `prefill` = `users.name` quand un compte est connecté **et** que ce nom passe la règle de pseudo (§ 13.1), sinon `null` ; **jamais** `real_name` (I5.11). L'accueil (carte « Rejoindre »), `room/create`, `room/join` et `room/solo` l'utilisent comme valeur initiale **modifiable** du champ, jamais envoyée sans geste. La prise de siège valide le champ envoyé comme aujourd'hui ; le serveur ne substitue jamais `users.name` en silence.
- **Jamais de réécriture** (n° 21) : un pseudo saisi au siège ne réécrit jamais `users.name`, qui ne change que dans Réglages › Profil.
- **Rôles privilégiés** (n° 22) : préremplis comme tout compte, depuis `users.name` — amende le § 8.4, point 2.
- **Tests** : `tests/Feature/Room/SeatPrefillTest.php` — « préremplit le pseudo d'un compte connecté depuis son nom de compte » ; « ne préremplit jamais le nom réel » ; « ne préremplit pas un nom de compte qui enfreint la règle de pseudo » ; « un pseudo saisi au siège ne réécrit jamais le nom du compte ».

### 13.10 Sujets soldés par renvoi

- **Sujet 12** (configurations sauvegardées vues du compte) : propriété strictement privée, y compris d'un administrateur (`SavedConfigPolicy`, aucun `Gate::before`) ; adressée dans les URL par une colonne **`saved_config.public_id`** (n° 1, `PublicId`, patron D63 ; exigence à `10`) ; supprimées à l'anonymisation ; routes, CRUD et normalisation à `50` (L50-12, L50-13).
- **Sujet 13** (rétention vue du compte) : par renvoi au tableau de `10` § 11.1, sans le recopier ; la page de confidentialité le publie (`90` § 11.5).
- **Sujet 16** (`HasLocalePreference`) : `User` l'implémente déjà ; tout e-mail du compte (vérification, export, dormance) part en file dans `users.locale` ; un compte sans adresse ne reçoit rien et l'écran le dit.
- **Sujet 17** (`dashboard`) : soldé — la page et sa route sont retirées, `fortify.home` = `/settings/profile`.
- **Sujets 2 à 4** : livrés au J1 (§ 12, D51 du 01/10).

### 13.11 Les trois questions du § 10.2, tranchées

- **Effet de `nickname.banned`** : n° 8 de D66, § 13.3.
- **Preuve du consentement aux données provider** : tranchée le 01/10 (D51 : un cas de `ConsentKind` par fournisseur).
- **Avatar d'un invité qui crée un compte** — défaut de rédacteur, signalé au porteur : sans objet depuis D51 et D55. Un compte neuf n'hérite pas du prédéfini du jeton (`avatar_kind` reste nul, initiales) ; la photo provider devient effective seulement sans choix (§ 12.6) ; les sièges reçoivent leur avatar par l'attribution serveur (§ 11.4), qui lit le prédéfini préféré du jeton.

### 13.12 Clés de traduction du J2

`account.consent.*`, `account.terms_update.*`, `account.history.*`, `account.export.*`, `account.delete_account.errors.*`, `account.two_factor.errors.privileged_required` ; `room.seat.claimed` ; `common.player.{masked,masked_notice}`, `common.player.report.*` ; `mail.export_ready.*`, `mail.dormancy_reminder.*` ; `admin.moderation.*`, `admin.accounts.anonymize.*`, `admin.enum.admin_action.user_anonymized` (français seul). FR et EN dans le même commit pour les domaines joueur (`TranslationCoverageTest`), puis `php artisan lang:types`.

---

## Exigences adressées à 10

La section [J1] consomme les exigences déjà consolidées dans la feuille de contrats du 23/09, plus **une** exigence nouvelle, qui aligne `10` sur lui-même.

- **E10-01** — `player.kicked_at`, `timestamp(3)` nullable, sans index, jamais remise à NULL (D15 du 23/09 ; § 3.10).
- **E10-02** — `users.real_name`, nullable, `#[Hidden]` (D12 du 23/09 ; émetteur `20`, consommée au § 8.4).
- **E10-11 (c)** — 10 § 1.1 : la forme du `player_token` appartient à `40` [J1] (§ 3).
- **E10-20 (partie 3)** — appartenance à une partie par `game_player`, hash du jeton et `kicked_at` nul (émetteur `60`, consommée au § 3.9).
- **E10-22** — `reviewer_name` et `actor_name` figent `users.real_name` (émetteur `20`, consommée au § 8.4).
- **E10-32** — 10 § 7.1 : `nickname_normalized` = `NicknameNormalizer::normalize()`, bâti sur `fold()` ; refus d'une forme vide ou de plus de 20 caractères (§ 5.5).
- **E10-33** — 10 § 7.1 : SHA-256 du `tid`, jamais de la valeur du cookie (§ 3.5).
- **E10-34** — `#[Hidden]` de `Player` : `id`, `room_id`, `user_id`, `user`, `player_token_hash`, `active_seat_token`, `nickname_normalized`, `kicked_at` (§ 7.1). Code livré — amendé le 28/09 (E65-4) : la liste de 10 § 7.1, seul propriétaire du schéma et amendé le 23/09, dans son ordre, **`solo_token_hash` compris** (colonne d'E10-N3, créée par la migration n° 45 de L60-15) ; posé dès L40-1, le masquage garde la colonne d'office à son arrivée.
- **E10-35** — unicité du pseudo sur tous les sièges du salon, partis et expulsés compris (§ 5.6).
- **E10-42** — `game_player` naît au lancement avec `display_*` gelés (émetteur `50`, consommée au § 7.2).
- **E10-59** — 10 § 10 : aucun `Set-Cookie` sur `/f/` (émetteur `60`, contrat C8 ; consommée au § 3.4).
- **E10-67** — 10 § 15, ligne de `40` : pseudo, liste noire, avatars et forme du jeton ; la matrice des rôles passe à `20`.
- **Exigences du J2 (D66 du 07/10)**, inscrites à `10` le 07/10 : `player.nickname_reports_from` (`timestamp(3)` nullable, `#[Hidden]`, § 13.3) ; `saved_config.public_id` (`char(12)` `PublicId`, UNIQUE, rétro-remplie, § 13.10) ; `game.public_id` (`char(12)` `PublicId`, UNIQUE, rétro-remplie : adresse du détail d'une partie dans l'historique, § 13.4 — exigence nouvelle, au-delà de la liste du plan, signalée au porteur) ; `users.dormancy_notified_at` confirmée, remise à nul par `RecordLastLogin` (§ 13.7) ; disque privé `exports` (`EXPORTS_DISK_ROOT`, § 13.5) ; cas `user.anonymized` d'`AdminActionType` (§ 13.6) et motif obligatoire de `nickname.banned` (§ 13.3) ; liste close de l'anonymisation (`10` § 5.5) étendue à toutes les colonnes `avatar_*` et au lien visiteur et appareil des sièges.
- **Exigence nouvelle, non consolidée — écart signalé au porteur** : aligner sur 10 § 7.1 (`nickname_normalized` « effacée avec » `nickname`) les lignes de `10` qui ne nomment que trois colonnes — § 11.1 « Identifiants d'invité », « Idem, sièges solo » et « Lobby jamais lancé », § 5.5 « Détachés et effacés » — en y ajoutant `player.nickname_normalized` (§ 2.1, étape 8). L'écart est interne à `10` ; sans lui, un archivage écrit d'après § 11.1 laisserait survivre la forme repliée du pseudo douze mois ou plus.

---

## Amendements à d'autres documents

Aucun amendement nouveau ; la section [J1] s'appuie sur les amendements consolidés suivants, qu'un travail séparé applique au corpus.

- **A-14** (00 l.134) — expulsion : jeton refusé dans ce salon jusqu'à l'archivage, sans réadmission.
- **A-15** (00 l.113) — pseudo en écriture latine seule, message traduit.
- **A-18** (00 l.129 et lexique) — cookie `player_token` HttpOnly chiffré, jamais `localStorage` ; le jeton porte identifiant opaque, langue et avatar, jamais le pseudo.
- **A-22** (00 l.204) — pack Kenney CC0, clés `preset-01`..`preset-24`, licence dans `public/avatars/LICENSE.md`.
- **A-32** (00 l.363) — 40 [J1] dans la liste des specs du jalon 1.
- **A-34** (00 l.435) — « tableau de rétention » retiré de la ligne de `40`.
- **A-37** (05 l.100) — `validation.nickname.*` et `validation.attributes.avatar` nommés.
- **A-38** (05 l.36, l.55, l.307) — niveau 3 = cookie `locale` absent alors que le jeton est présent ; forme du jeton renvoyée à 40 [J1].
- **A-39** (05 l.94) — avatars dans `common` ; liste close des domaines réaffirmée.
- **A-51** et **A-64** (`questions-ouvertes.md` l.78 et l.340-356) — 40 [J1] au jalon 1, écrite avant `50` ; sections J2 « à écrire ».
- **A-67** (`CLAUDE.md` §1) — comptes du J1 réduits au premier administrateur et aux comptes de test jetables.
- **A-71** (`CLAUDE.md` §3) — dépendance directe `symfony/polyfill-intl-normalizer`.
- **A-73** et **A-74** (`CLAUDE.md` §5 et §6) — dossiers `app/Support/Identity/`, `app/Rules/`, `resources/moderation/` ; lexique « expulsé → `kicked` », « liste noire de pseudos → `NicknameBlocklist` ».
- **A-79** (`REPRISE.md` l.134) — 40 [J1] avant `50`.

**Écarts signalés au porteur**, qu'aucune spec ne corrige en silence (en-tête de la feuille de contrats) :

- **Ligne de cookie publiée** (§ 3.2) : « 30 jours après la dernière prise de siège **ou le dernier changement de langue** », contre le texte figé en C4 § 6, que 90 § 4.6 a déjà aligné ; motif : `resign()` repose le cookie avec `LIFETIME`, et une durée publiée doit être vraie (principe 12).
- **Précision d'A-67** (§ 8.1) : les comptes de test jetables du J1 vivent sur les postes et en CI, jamais en production.
- **Effacement de `nickname_normalized` à l'archivage** (§ 2.1, étape 8) : exigence nouvelle à `10`, ci-dessus.
- **`ensure()` après les refus** (§ 2.1, étape 4) : ordre imposé à l'action de prise de siège de `50` et au démarrage solo de `60`, que C4 ne fixait pas.

**Code existant à aligner**, dans les lots de la section suivante : `PlayerFactory` (`normalizeNickname()` délègue à `NicknameNormalizer::normalize()`, `NICKNAME_MAX_LENGTH` supprimée au profit de `NicknameNormalizer::MAX_LENGTH`, `avatarPreset()` tire dans `AvatarPresetCatalog::keys()`, état `kicked()`) ; `UserFactory::withPresetAvatar()` tire dans `AvatarPresetCatalog::keys()` ; `AvatarRef` (clés `common.avatar.alt.*`, `presetUrl()`) ; `Player` (scope, méthode, colonne, `#[Hidden]`) ; `AppServiceProvider::registerLocalization()` ; `LocaleController::update()` ; `config/fortify.php` › `middleware` ; `routes/settings.php` (`profile.destroy` retirée, `accounts.switches` sur `well-known.passkeys`) ; `ProfileController` ; `lang/{en,fr}/account.php` ; `types/auth.ts` (`Auth.user` nullable), `user-info.tsx` et `components/admin/admin-sidebar.tsx`.

---

## Lots d'implémentation

| Lot | Jalon | Objet | Dépend de | Heures |
|---|---|---|---|---|
| L40-1 | J1 | Jeton d'invité et refus de l'expulsé | L40-5 ; `10` (E10-01, E10-33, E10-34) | 5–7 |
| L40-2 | J1 | Langue portée par le jeton | L40-1 ; `05` (existant) | 2–3 |
| L40-3 | J1 | Pseudo : forme canonique, écritures, normalisation | L40-5 ; `fold()` de `70` | 3–5 |
| L40-4 | J1 | Liste noire des pseudos | L40-3 | 2–4 |
| L40-5 | J1 | Registre des 24 avatars prédéfinis | — | 3–4 |
| L40-6 | J1 | Identité affichée et composants d'avatar | L40-3, L40-5 ; L90-1 | 3–5 |
| L40-7 | J1 | Comptes au jalon 1 | — | 3–5 |

Ordre : **L40-5 → L40-1 → L40-2 ; L40-5 → L40-3 → L40-4 ; L40-3, L40-5 et L90-1 → L40-6 ; L40-7 à tout moment ; tous avant le lot de prise de siège de `50`**, qui les consomme. L40-5 vient en tête parce que `AvatarPresetCatalog` est lu par `PlayerToken::withAvatar()` et par le décodage du jeton (L40-1) comme par `avatarPresetRules()` (L40-3) : dans un autre ordre, ces deux lots ne compileraient pas. Chaque estimation comprend la barre « terminé » (tests verts, textes FR et EN, états de chargement, d'erreur et de déconnexion là où un écran existe, parcours clavier) et intègre le facteur 1,5–2 du cadre S3 du 23/09.

**L40-1 — Jeton d'invité et refus de l'expulsé** (J1, 5–7 h)
- Dépendances : L40-5 (`AvatarPresetCatalog::keys()` et `has()`, lus par `PlayerToken::withAvatar()` et par le décodage du jeton) ; `10` amendée pour E10-01, E10-33 et E10-34. Débloque la prise de siège (`50`) et la garde `player` (`60`).
- Crée : `app/Support/Identity/PlayerToken.php`, `PlayerTokenCookie.php`, `PlayerTokenManager.php` ; migration additive `add_kicked_at_to_player_table`.
- Modifie : `app/Models/Player.php` (scope `heldByToken`, `wasKicked()`, cast et PHPDoc de `kicked_at`, `#[Hidden]` complété), `database/factories/PlayerFactory.php` (état `kicked()`), `app/Providers/AppServiceProvider.php` (`singleton(PlayerTokenManager::class)`).
- Tests : `tests/Feature/Identity/PlayerTokenTest.php` — « ne frappe aucun player_token sur une requête qui ne prend aucun siège », « ne frappe qu'un player_token par requête, quel que soit le nombre d'appels à ensure », « ne stocke que le SHA-256 du tid dans player_token_hash », « écrit le cookie player_token chiffré, HttpOnly, SameSite=Lax, sur le chemin / pour 30 jours », « fait glisser l'expiration du cookie et réaligne la revendication de langue à chaque prise ou reprise de siège », « traite comme absent un cookie altéré, illisible, étranger ou d'une version future », « conserve le tid quand sa revendication de langue ou d'avatar n'est plus connue », « refuse de re-signer un jeton sous un autre tid », « laisse le player_token intact à la connexion, à la déconnexion et à l'inscription » (contrat C4 § 7) ; ajouts de `40` au même fichier — « ne frappe aucun player_token quand la prise de siège est refusée » (§ 2.1, étape 4), « repart de 30 jours pleins à chaque re-signature, changement de langue compris » (§ 3.6), « re-signe le player_token avec l'avatar choisi sous le même tid, que suggest() présélectionne au salon suivant s'il y est libre » (I4.5, I5.8), « prend le siège d'un compte connecté sous le pseudo saisi et le rattache au compte » (I4.10, amendé le 01/10, D49 du 01/10) ; `tests/Feature/Identity/KickedSeatTest.php` — « masque un siège expulsé à seatIn tandis que wasKickedFrom le signale », « n'oublie le refus que lorsque l'archivage efface le hash du jeton » ; `tests/Feature/Architecture/PlayerTokenBoundaryTest.php` — « n'exempte jamais le cookie player_token du chiffrement », « ne lit et n'écrit le cookie player_token que par PlayerTokenCookie », « frappe des tid de 64 caractères hexadécimaux minuscules qui ne se répètent jamais » ; `tests/Feature/Schema/ModelSerializationTest.php` (ajout) — « ne sérialise jamais nickname_normalized ni kicked_at d'un siège ». Tant que `50` n'a pas livré `room.join`, les tests qui exigent un geste de siège — les quatre ajouts de `40` compris — passent par une route déclarée dans le fichier de test, qui suit l'ordre du § 2.1, étape 4. Condition tombée à la livraison de L50-3b (porte du 27/09, E101-6) : les onze tests à geste de siège de `PlayerTokenTest` passent par `room.join` (`JoinRoomRequest` puis `TakeSeat`), la route de test du siège est supprimée ; restent déclarées dans le fichier, faute de vraie route à leur mesure, les routes `probe`, `ensure-many`, `ensure-form`, `resign` (changement d'avatar hors siège, qu'aucun lot du J1 ne livre, E111-7) et `suggest` — amendé le 28/09.

**L40-2 — Langue portée par le jeton** (J1, 2–3 h)
- Dépendances : L40-1 ; `SetLocale` existant (`05`).
- Crée : `app/Support/I18n/CookiePlayerTokenLocale.php`.
- Modifie : `AppServiceProvider::registerLocalization()`, `LocaleController::update()`, docblock de `PlayerTokenLocale` ; supprime `NullPlayerTokenLocale.php`.
- Tests : `tests/Feature/Identity/PlayerTokenTest.php` — « re-signe le player_token avec la nouvelle langue et le même tid », « passe chaque siège tenu par le jeton à la nouvelle langue, et rien d'autre », « ne frappe pas de player_token quand un invité qui n'en a pas change de langue » ; `tests/Feature/I18n/SetLocaleTest.php` (ajouts) — « restaure la langue d'un invité depuis le player_token quand le cookie locale a disparu », « préfère le cookie locale à la revendication du player_token ».

**L40-3 — Pseudo : forme canonique, écritures, normalisation** (J1, 3–5 h)
- Dépendances : L40-5 (`avatarPresetRules()` lit `AvatarPresetCatalog::keys()` ; `validation.attributes.avatar`) ; `AnswerKeyNormalizer::fold()` (`70`, C12). Si le lot de `70` n'est pas encore livré, `fold()` est livré en tête de ce lot **exactement** tel que C12 § 3 le définit, avec ses fixtures dans `tests/Fixtures/answers/normalizer-v1.php`, et rien d'autre de `70`. Les 3 à 5 h de ce lot **n'incluent pas** `fold()` : s'il est livré ici, compter +0,5 h, retranchée de L70-1 de `70`, qui livre `AnswerKeyNormalizer` ; le total J1 de `40` reste inchangé et `00` § Jalons ne compte ce travail qu'une fois. Clause sans objet à la livraison : `fold()` était livré par L70-1 (étape 37), avant L40-3 ; `symfony/polyfill-intl-normalizer: ^1.42` est entré en dépendance directe sans changement de version (v1.42.0, seule l'empreinte `content-hash` de `composer.lock` change) — amendé le 28/09 (E67-1).
- Crée : `app/Rules/ValidNickname.php` (étapes 1 à 4 du § 5.4), `app/Concerns/PlayerIdentityValidationRules.php`, `app/Support/Identity/NicknameNormalizer.php`.
- Modifie : `composer.json` (`symfony/polyfill-intl-normalizer` en dépendance directe), `lang/{en,fr}/validation.php` (`nickname.*`, `attributes.avatar`), `PlayerFactory` (normaliseur délégué, constante supprimée), `tests/Feature/I18n/TranslationCoverageTest.php` (`ValidNickname::MESSAGE_KEYS` dans « carries every key built by an enumerable key constructor »), `tests/Feature/Schema/ColumnLengthTest.php` (« fait tenir un pseudo de longueur maximale en lettres latines étendues… », livré par L100-1, lit `NicknameNormalizer::MAX_LENGTH` au lieu de `PlayerFactory::NICKNAME_MAX_LENGTH`, dont la suppression le fait échouer à la compilation, volontairement ; ses plages de lettres, recopiées faute de `ValidNickname`, peuvent alors lire `ValidNickname::ALLOWED_PATTERN` — amendé le 25/09, E6-5).
- Tests : `tests/Feature/Identity/NicknameValidationTest.php` — « accepte les pseudos latins de 2 à 20 caractères avec chiffres, espaces, tirets et soulignés », « compose un accent décomposé avant de le valider », « rogne les extrémités et réduit les espaces intérieures dans le pseudo affiché », « refuse un pseudo de moins de 2 ou de plus de 20 caractères », « refuse cyrillique, grec, kana, kanji, hangul, lettres pleine chasse et signe micro avec le message d'écriture », « accepte les indicateurs ordinaux ª et º, lettres latines du Latin-1 supplément », « refuse les caractères sans chasse, de contrôle, combinants, symboles et émojis avec le message de caractères », « refuse un pseudo fait seulement d'espaces, de tirets et de soulignés », « refuse avec un message traduit un pseudo dont la forme repliée dépasse 20 caractères » ; `tests/Feature/Identity/NicknameNormalizerTest.php` — « replie casse, accents, espaces, tirets et soulignés en une seule forme normalisée », « garde articles et chiffres dans la forme normalisée », « rend une forme ASCII non vide pour chaque lettre latine admise ».

**L40-4 — Liste noire des pseudos** (J1, 2–4 h)
- Dépendances : L40-3.
- Crée : `app/Support/Identity/NicknameBlocklist.php`, `resources/moderation/nicknames/en.txt`, `fr.txt`, `reserved.txt`.
- Modifie : `ValidNickname` (étape 5) ; `THIRD_PARTY_NOTICES.md` (ligne des listes LDNOOBW déplacée de « Actifs attendus au jalon 1 » vers « Actifs livrés » dans le commit qui livre les fichiers, sans quoi `LicenseTest` échoue ; texte d'attribution CC BY à revérifier au téléchargement) — amendé le 25/09 (E7-1).
- Tests : `tests/Feature/Identity/NicknameBlocklistTest.php` — « livre une liste noire avec en-tête de source et de licence pour chaque locale activée et pour les noms réservés », « refuse une entrée de chaque langue activée quelle que soit la locale de la requête », « refuse les graphies leet, à lettres répétées et séparées d'une entrée longue », « ne refuse une entrée courte que comme mot entier », « refuse les noms réservés comme admin et curator », « ne cite jamais le mot refusé dans le message » (contrat C5 § 7) ; ajout de `40` — « accepte Bob, As et Château, que la forme réduite d'une entrée rattraperait » (condition de la comparaison (b), § 5.7) ; `tests/Feature/Identity/NicknameValidationTest.php` — « ne rend qu'un message, dans l'ordre longueur, écriture, caractères, alphanumérique, longueur normalisée, liste noire ». Les entrées offensantes sont lues dans les fichiers, jamais écrites en dur dans les tests.

**L40-5 — Registre des 24 avatars prédéfinis** (J1, 3–4 h)
- Dépendances : aucune (`PlatformLimits` existe). Téléchargement du pack compris. La vérification de sa licence (D27 du 23/09) est un geste humain du porteur, sur le chemin critique humain du J1 (D36 du 23/09) : le lot n'est pas terminé tant qu'elle n'est pas consignée dans `public/avatars/LICENSE.md` (I5.7). — amendé le 23/09
- Crée : `public/avatars/preset-01.webp` … `preset-24.webp`, `public/avatars/LICENSE.md`, `app/Avatars/AvatarPresetCatalog.php`.
- Modifie : `app/Avatars/AvatarRef.php` (clés `common.avatar.alt.*`, `presetUrl()`), `lang/{en,fr}/common.php` (`avatar.alt.*`, `avatar.picker.*`, 24 `avatar.preset.*`), `UserFactory::withPresetAvatar()`, `PlayerFactory::avatarPreset()`, `TranslationCoverageTest` (`AvatarPresetCatalog::labelKey()` et les trois `AvatarRef::ALT_KEY_*`), `THIRD_PARTY_NOTICES.md` (ligne du pack Kenney déplacée de « Actifs attendus au jalon 1 » vers « Actifs livrés » dans le commit qui livre les fichiers, sans quoi `LicenseTest` échoue : tout fichier de `public/avatars/` doit être couvert par une ligne livrée) — amendé le 25/09 (E7-1).
- Tests : `tests/Feature/Identity/AvatarPresetTest.php` — « déclare exactement autant de clés de prédéfini que PlatformLimits::avatarPresets() », « livre chaque prédéfini en WebP de 256 px d'au plus 20 Ko », « livre la licence du pack d'avatars à côté de ses fichiers », « nomme chaque prédéfini dans chaque locale activée », « suggère l'avatar préféré s'il est libre, sinon le premier libre dans l'ordre du catalogue », « refuse une clé d'avatar hors du catalogue », « résout chaque clé alt d'AvatarRef dans le domaine common ».

**L40-6 — Identité affichée et composants d'avatar** (J1, 3–5 h)
- Dépendances : L40-3, L40-5 ; L90-1 (installe `radio-group`, inscrit `components/game`, `lib/game` et `types/player.ts` dans `WATCHED`). L90-1 passe **avant** ce lot, et ce lot n'installe ni n'inscrit rien : il crée les premiers fichiers de `components/game` et de `lib/game`, qui échapperaient sinon au script anti-couleur (règle 5) puis que la méta-vérification des fichiers non classés refuserait ; l'installation des composants et le périmètre appartiennent à `90` seul (C16 § 2.9 et § 2.11), pour qu'un seul lot écrive `scripts/check-theme-tokens.mjs`. Seule exception, qui ne touche aucune règle du script : un lot qui supprime ou retouche un fichier hérité change dans le même commit ses **entrées de liste** (`EXEMPT`, `WATCHED`), comme l'exigent la règle `[stale-exempt]` et la règle d'entrée n° 2 de 90 § 9.3 ; L40-7 l'a fait — amendé le 25/09 (E14-3).
- Crée : `app/Support/Identity/PlayerIdentity.php`, `resources/js/types/player.ts` (réexporté par `types/index.ts`), `resources/js/lib/game/avatar-keys.ts`, `resources/js/components/game/player-avatar.tsx`, `avatar-picker.tsx`.
- Tests : `tests/Feature/Identity/PlayerIdentityTest.php` — « sérialise l'identité d'un siège avec son public_id, son pseudo et son avatar seulement », « ne laisse jamais fuiter le pseudo ni ses initiales d'un siège masqué » ; `tests/Feature/Identity/AvatarPresetTest.php` (ajout de `40`) — « couvre côté client exactement les clés du catalogue d'avatars » : le test lit `resources/js/types/player.ts`, en extrait les littéraux de l'union `AvatarPresetKey` par `/'(preset-\d{2})'/` et compare la liste triée à `AvatarPresetCatalog::keys()` ; l'exhaustivité de `AVATAR_PRESET_LABEL_KEYS: Record<AvatarPresetKey, TranslationKey>` est garantie par `tsc`. Vitest n'a aucun environnement DOM au J1 (C18 § 2.4) : le parcours clavier du sélecteur est vérifié à la main au J1, par Playwright au J2.

**L40-7 — Comptes au jalon 1** (J1, 3–5 h)
- Dépendances : aucune. Le § 8.4 ne demande rien à ce lot : colonne, garde et `#[Hidden]` de `users.real_name` sont livrés par le lot de `20` (E10-02).
- Crée : `config/accounts.php`, `app/Support/Identity/AccountSwitches.php`, `app/Http/Middleware/EnforceAccountSwitches.php`.
- Modifie : `config/fortify.php` (`middleware`), `bootstrap/app.php` (alias `accounts.switches`), `HandleInertiaRequests` (`accountsOpen`), `types/global.d.ts`, `FortifyServiceProvider` (vues `login` et `confirm-password`), `SecurityController` (`canManagePasskeys`), `pages/auth/login.tsx` et `pages/auth/confirm-password.tsx` (par exception à la frontière avec `90`, § 9), `routes/settings.php` (`profile.destroy` retirée, `accounts.switches` sur `well-known.passkeys`), `ProfileController` (`destroy()` retirée), `pages/settings/profile.tsx`, `lang/{en,fr}/account.php` (`delete_account.*` corrigées), `types/auth.ts` (`email` et `avatar` nullables, type `Auth` à `user: User | null`), `components/user-info.tsx`, `components/admin/admin-sidebar.tsx` (garde de `auth.user`) ; supprime `ProfileDeleteRequest.php` et `components/delete-user.tsx`.
- Écarts de la liste livrée — amendé le 25/09 (E11-7, E14-1, E14-2, E14-3) :
  - `.env.example` n'est **pas** modifié : L100-4 y a déjà posé les deux variables vides, avec un renvoi au § 8.2 ; les doubler ferait échouer « déclare les deux interrupteurs vides dans .env.example », qui exige une affectation active unique par variable ;
  - `components/app-header.tsx` et `components/admin/admin-user-panel.tsx` reçoivent `src={… ?? undefined}` (§ 8.5) ;
  - `scripts/check-theme-tokens.mjs`, entrées de liste seules (note de L40-6) : `components/delete-user.tsx` sort d'`EXEMPT` ; `types/auth.ts`, `types/global.d.ts` et `pages/auth/confirm-password.tsx`, propres, entrent dans `WATCHED` ; `pages/auth/login.tsx`, `pages/settings/profile.tsx`, `components/user-info.tsx` et `components/app-header.tsx`, en infraction, restent dans `EXEMPT` (90 § 9.3, règle n° 3).
- Tests : `tests/Feature/Auth/AccountSwitchesTest.php` — « ferme l'inscription et les passkeys hors local et testing quand aucune valeur n'est déclarée » (joué sous `APP_ENV=production` puis `APP_ENV=staging`), « ouvre l'inscription et les passkeys en local et en testing quand aucune valeur n'est déclarée », « ne lit que true ou false et traite toute autre valeur comme non déclarée », « répond 404 à l'écran et à l'envoi d'inscription quand l'inscription est fermée, sans créer de compte », « répond 404 à toute route de passkey, .well-known/passkey-endpoints compris, quand les passkeys sont fermées », « garde les routes d'inscription et de passkeys enregistrées quel que soit l'interrupteur », « ne partage aucun lien de compte quand l'inscription est fermée », « déclare les deux interrupteurs vides dans .env.example » ; ajout de `40` au même fichier — « ne propose aucune passkey à la connexion, à la confirmation du mot de passe ni dans la sécurité quand les passkeys sont fermées », joué portes fermées puis ouvertes, pour que `canUsePasskeys` et `canManagePasskeys` (§ 8.2) ne puissent pas revenir à `Features::…` seul sans faire échouer la CI — amendé le 25/09 (E14-4) ; `tests/Feature/Settings/ProfileUpdateTest.php` (modifié) — « user can delete their account » et « correct password must be provided to delete account » sont remplacés par « n'expose aucune suppression de compte tant que l'anonymisation n'est pas livrée », qui prouve l'absence de `ProfileDeleteRequest` par son fichier et non par `class_exists()` : sous `optimize-autoloader`, une classmap périmée ferait lever `class_exists()` au lieu de rendre faux — amendé le 25/09 (E14-7). Le typage de `auth.user` nullable est prouvé par `tsc` (`npm run types:check`).

**Lots du J2** — rédigés le 07/10 (D66 du 07/10), règles au § 13. Mêmes conventions : barre « terminé » comprise, heures = mesures de taille, jamais un calendrier (D36 du 23/09).

| Lot | Jalon | Objet | Dépend de | Heures |
|---|---|---|---|---|
| L40-10 | J2 | Ouverture des comptes : CGU et âge à l'inscription, limiteur, règle de `users.name`, ré-acceptation (§ 13.1) | — | 4–6 |
| L40-11 | J2 | Rattachement automatique d'un siège invité (§ 13.2) | — | 3–5 |
| L40-12 | J2 | Signalement, masquage et bannissement du pseudo (§ 13.3) | `10` (`player.nickname_reports_from`) ; écran de `20` (L20-31) | 4–6 |
| L40-13 | J2 | Historique et quatre compteurs (§ 13.4) | L40-11 ; `10` (`game.public_id`) | 4–6 |
| L40-14 | J2 | Export en libre-service (§ 13.5) | L40-13 ; `05` (`App\Support\Format`) ; `10` (disque `exports`) | 5–7 |
| L40-15 | J2 | Second facteur et passkeys en production (§ 13.8) | L40-10 | 2–3 |
| L40-16 | J2 | Préremplissage du pseudo (§ 13.9) | L40-10, L40-11 | 1–2 |
| L40-17 | J2 | Anonymisation, geste de l'administrateur, cycle du rôle (§ 13.6) | L40-14 | 4–6 |
| L40-18 | J2 | Comptes dormants (§ 13.7) | L40-17 ; `10` (migration n° 48) ; moteur de purge de `100` | 2–3 |

Ordre : L40-10 → L40-15 ; L40-11 et L40-12 indépendants ; L40-11 → L40-13 → L40-14 → L40-17 → L40-18 ; L40-10 et L40-11 → L40-16. La réécriture aux tokens des écrans de compte hérités du starter est un lot de `90` (§ 11, point 2), dans la coquille de laquelle ces lots livrent leurs pages.

**L40-10 — Ouverture des comptes** (J2, 4–6 h). Crée : `app/Actions/Account/RecordConsents.php`, `app/Http/Middleware/EnsureTermsAccepted.php`, `app/Http/Controllers/Settings/TermsAcceptanceController.php`, `app/Http/Requests/Settings/TermsAcceptanceRequest.php`, `resources/js/pages/auth/terms-update.tsx`. Modifie : `CreateNewUser`, `CreateOAuthAccount`, `ProfileValidationRules::nameRules()`, `FortifyServiceProvider` (limiteur `register`), `EnforceAccountSwitches` (limiteur sur `register.store`), `bootstrap/app.php` (alias `terms.current`), `routes/settings.php`, `pages/auth/register.tsx`, `lang/{fr,en}/account.php`. Tests : `tests/Feature/Auth/RegistrationTest.php` — « refuse une inscription sans CGU ou sans déclaration d'âge » ; « écrit deux lignes user_consent et les projections à la version courante dans la transaction de création » ; « limite les inscriptions par adresse » ; « applique la règle de pseudo de jeu au nom de compte, sans l'unicité par salon » ; `tests/Feature/Auth/TermsReacceptanceTest.php` — « redirige un compte à version périmée vers l'interstitiel » ; « accepter écrit une nouvelle ligne et met à jour les projections » ; « ne bloque jamais le jeu ni le back-office » ; « laisse la déconnexion, l'export et la suppression ouverts ».

**L40-11 — Rattachement automatique** (J2, 3–5 h). Crée : `app/Actions/Identity/ClaimSeatForAccount.php`. Modifie : `RoomController@show`, `SoloGameController@show` (appel au rendu), `SeatAvatar`, le retour au lobby de `50` (consommation de la marque d'avatar), `lang/{fr,en}/room.php`. Tests : `tests/Feature/Identity/SeatClaimTest.php` (§ 13.2) ; `PlayerTokenTest` — « laisse le player_token intact à la connexion, à la déconnexion et à l'inscription » reste vert.

**L40-12 — Modération du pseudo** (J2, 4–6 h). Crée : migration additive `add_nickname_reports_from_to_player`, `app/Actions/Room/ReportSeatNickname.php`, `app/Actions/Admin/{UnmaskNickname,BanNickname}.php`, `app/Policies/PlayerPolicy.php` (méthodes `moderateNicknames`, `moderateNickname`), `resources/moderation/nicknames/banned.txt`. Modifie : `Player` (cast, PHPDoc, `#[Hidden]`), `NicknameBlocklist` (lecture de `banned.txt`), `routes/game.php`, limiteur `seat-report`, composants qui affichent un pseudo (`seat-list`, `cinema-seat-map`, `round-players`, `round-reveal`, `standings-table`, `podium-highlights`), `lang/{fr,en}/common.php`. Tests : `tests/Feature/Room/NicknameReportTest.php`, `tests/Feature/Admin/NicknameModerationTest.php` (§ 13.3) ; Vitest du libellé masqué et de l'ordinal.

**L40-13 — Historique** (J2, 4–6 h). Crée : `app/Support/Account/PlayHistory.php`, `app/Http/Controllers/Settings/PlayHistoryController.php`, `app/Listeners/Account/ForgetPlayHistoryCache.php`, `app/Policies/GamePolicy.php` (`viewHistory`), `resources/js/pages/settings/history.tsx`, `history-show.tsx`. Modifie : `AppServiceProvider` (écouteur), `routes/settings.php`, navigation des réglages, `lang/{fr,en}/account.php`. Tests : `tests/Feature/Account/PlayHistoryTest.php` (§ 13.4) ; Vitest du formatage des compteurs.

**L40-14 — Export** (J2, 5–7 h). Crée : `app/Actions/Account/{RequestDataExport,DeleteUserExports}.php`, `app/Jobs/Account/BuildDataExport.php`, `app/Support/Account/DataExportBuilder.php`, `app/Notifications/Account/DataExportReady.php`, `app/Http/Controllers/Settings/DataExportController.php`, `app/Console/Commands/AccountExportCommand.php`, `app/Support/Retention/Handlers/DataExportHandler.php`, `resources/js/pages/settings/export.tsx`. Modifie : `composer.json` (`ext-zip`), workflows CI (`extensions: imagick, zip`), `config/filesystems.php` (disque `exports`), `.env.example` (`EXPORTS_DISK_ROOT` vide), `PurgeHandlers`, `PurgeScope::implemented()`, `RetentionWindows` (`DATA_EXPORT_DAYS`), limiteur `data-export`, `lang/{fr,en}/{account,mail}.php`. Tests : `tests/Feature/Account/DataExportTest.php` (§ 13.5).

**L40-15 — Second facteur et passkeys** (J2, 2–3 h). Crée : `app/Support/Identity/LoginMethods.php`, intergiciel de refus de `two-factor.disable`. Modifie : `config/fortify.php`, `.env.example` (`PASSKEYS_RP_ID`, `PASSKEYS_ALLOWED_ORIGINS` vides), `SecurityController` (`canDisableTwoFactor`), `UnlinkProvider` (aide partagée), écran Sécurité. Tests : `tests/Feature/Auth/PasskeyTest.php`, `tests/Feature/Admin/PrivilegedTwoFactorTest.php` (§ 13.8) ; `AccountSwitchesTest` inchangé.

**L40-16 — Préremplissage** (J2, 1–2 h). Modifie : `PresentsSeatForm`, contrôleurs de l'accueil, `room/create`, `room/join`, `room/solo`, pages correspondantes. Tests : `tests/Feature/Room/SeatPrefillTest.php` (§ 13.9).

**L40-17 — Anonymisation** (J2, 4–6 h). Crée : `app/Actions/Account/AnonymizeAccount.php`, `app/Support/Identity/SeatIdentityColumns.php`, `app/Http/Requests/Settings/ProfileDeleteRequest.php`, `components/account/delete-account.tsx`, `app/Http/Controllers/Admin/AccountAnonymizeController.php`. Modifie : `ProfileController` (`destroy`), `routes/settings.php`, `routes/admin.php`, `UserPolicy` (`anonymize`), `AdminActionType` (`user.anonymized`), `OrphanPlayerHandler` et `GuestNicknameHandler` (liste partagée), `FortifyServiceProvider` (garde de pierre tombale), `lang/{fr,en}/account.php`, `lang/fr/admin.php`, `tests/Datasets/AdminRoutes.php`. Tests : `tests/Feature/Settings/AccountDeletionTest.php`, `tests/Feature/Admin/AccountAnonymizationTest.php` (§ 13.6) ; `ProfileUpdateTest` › « n'expose aucune suppression de compte tant que l'anonymisation n'est pas livrée » est **retiré** dans le même commit.

**L40-18 — Comptes dormants** (J2, 2–3 h). Crée : migration additive n° 48 `add_dormancy_notified_at_to_users`, `app/Support/Retention/Handlers/DormantAccountHandler.php`, `app/Notifications/Account/DormancyReminder.php`. Modifie : `RecordLastLogin` (remise à nul), `RetentionWindows`, `PurgeHandlers`, `PurgeScope::implemented()`, `User` (cast, PHPDoc), `lang/{fr,en}/mail.php`. Tests : `tests/Feature/Retention/DormantAccountTest.php` (§ 13.7).

**Total J2 : 29 à 44 h** (L40-10 à L40-18), hors écrans de compte réécrits par `90`. Mesures de taille, jamais un calendrier ni un budget à tenir (D36 du 23/09).

**Variable d'ajustement** : aucune. Chaque lot conditionne soit la première prise de siège, soit la sûreté d'une production qui existe dès le J1. Depuis D35 du 23/09, le J1 est livré complet : les variables d'ajustement de développement (recadreur minimal, retardataires) sont sans objet — D17 du 23/09 n'a plus d'effet —, et le nombre de films du J1, réglé par le verdict du pilote (D10 du 23/09), relève de la curation ; aucune ne touche la barre « terminé ». — amendé le 23/09

**Total J1 : 21 à 33 h**, hors `fold()` s'il est livré par L40-3 (+0,5 h, retranchée de L70-1 ; sans objet, `fold()` ayant été livré par L70-1, amendé le 28/09, E67-1). **Total J2 : 29 à 44 h**, découpé en lots L40-10 à L40-18 le 07/10 (D66 du 07/10), hors écrans de `90` ; l'ordre de grandeur du § 10.3 (36 à 57 h) comprenait les sujets 2 à 4, livrés au J1 (§ 11, § 12). Ces heures sont des mesures de taille, jamais un calendrier ni un budget à tenir (D36 du 23/09 : le développement est confié à l'IA). — amendé le 23/09

---

## Ce que cette spec ne décide pas

| Sujet | Spec propriétaire |
|---|---|
| Tout le schéma : colonnes, index, types, nullabilité, rétention et son tableau complet — dont l'alignement de 10 § 11.1 et § 5.5 sur l'effacement de `nickname_normalized` (exigence nouvelle, écart signalé) | `10-catalogue-et-modele-de-donnees.md` |
| Ordre de résolution de la langue, cookie `locale`, domaines et propriété des préfixes de clés | `05-i18n-et-langues.md` (contrat C15) |
| Premier administrateur et `admin:first-admin`, écriture et écrans de `users.real_name` (back-office seul), matrice des rôles et policies, 2FA des rôles privilégiés au J1, écran de gestion des accès, file de modération comme écran | `20-back-office-curation.md` (contrat C14) |
| Prise de siège comme action (verrou, capacité, reprise, ordre des gardes sous la contrainte « `ensure()` après les refus »), FormRequest de `room.join` et exclusion des champs à la reprise, geste d'expulsion, transfert d'hôte, props nommées de la page d'entrée, texte de `room.join.kicked`, réécriture éventuelle d'un pseudo au lobby | `50-salon-reglages-presets-et-lobby.md` |
| Garde `player`, canaux, `SeatPrincipal`, `seat.kicked`, jeton d'onglet `active_seat_token`, siège solo et sa reprise, battement qui ramène un siège parti (et réaligne sa `locale`), résidus multi-sièges | `60-moteur-de-partie-temps-reel-et-mode-solo.md` (contrat C7) |
| `AnswerKeyNormalizer::fold()` et la normalisation des réponses | `70-validation-des-reponses.md` (contrat C12) |
| Contenu du podium et place de l'identité dans ses faits | `80-scoring-podium-et-fin-de-partie.md` (contrat C13) |
| Écran de pseudo et d'avatar, en-tête public, mention des CGU, page de confidentialité et sa ligne de cookie, place des composants d'avatar | `90-ecrans-etats-et-structure.md` (contrat C16) |
| Relevé des licences tierces, rotation d'`APP_KEY`, CI à zéro secret, tests MySQL de longueur de colonne, vérification d'`APP_ENV=production` au déploiement, commande d'arrêt du service | `100-qualite-tests-et-ci.md` |
| ~~Effet de « bannir un pseudo » (`nickname.banned`)~~ — **tranché** le 07/10 (D66 du 07/10, n° 8 ; § 13.3) | — |
| ~~Preuve du consentement aux données provider~~ — **tranché** le 01/10 (D51 du 01/10, § 12) | — |
| ~~Avatar d'un invité qui crée un compte~~ — **sans objet** depuis D51 et D55 (§ 13.11, défaut de rédacteur signalé) | — |
| ~~Confirmation ou révision des deux valeurs réversibles~~ — **confirmées** le 07/10 (D66 du 07/10, n° 10 et n° 28 ; § 13.7, § 13.8) : 2FA de `curator` et `admin`, dormance à 24 mois puis 30 jours. Rédaction d'origine : 2FA de `curator` et `admin`, dormance à 24 mois puis 30 jours — **à confirmer** (amendé le 23/09). La garde `admin.2fa` est livrée depuis L20-2 (24/09) et applique la 2FA aux deux rôles : revenir à `admin` seul coûterait une condition dans `EnsurePrivilegedTwoFactor` et ses tests ; les comptes de démonstration `curator@` et `admin@` s'enrôlent en développement comme en production (E18-8, amendé le 25/09) | `40` [J2], par le porteur |
| Liens « Se connecter » et « Créer un compte » de l'accueil du starter (`pages/welcome.tsx`), rendus à tout invité sans lire `accountsOpen` — **soldé** par L90-8 (étape 124, E124-1) : l'accueil réécrit ne rend plus aucun lien de connexion, d'inscription ni de tableau de bord, qui ne vivent plus que dans l'en-tête public sous la seule condition `accountsOpen` ; plus rien à trancher (constat de l'étape 14, L40-7, amendé le 25/09 ; soldé, amendé le 28/09) | — |
| Relecture des CGU et de la confidentialité livrées en squelette par L90-4, qui affirment le pseudo unique dans un salon (§ 5.6) et le refus des pseudos injurieux par la liste noire (§ 5.7) — **à relire** (E16-3, amendé le 25/09). Les deux règles sont codées depuis L40-3, L40-4 (25/09) et la prise de siège de L50-3b (26/09) : la promesse des partiels légaux est tenue ; seule la relecture reste due (E68-4, amendé le 28/09) | `90`, par le porteur |
| **E63-1, E63-3** — vérification du porteur à l'étape 64, section « Vérification du porteur » de `public/avatars/LICENSE.md` encore à `[À FOURNIR]` : confirmer le nom du pack (« Animal Pack Remastered », ex-« Redux », § 6.1), sa licence CC0 1.0, et la lisibilité à 32 px sur le thème sombre, vérifiée par l'IA seulement (§ 6.2). Le lot L40-5 n'est pas clos avant (D27 du 23/09) | le porteur |
| **E63-2** — l'élan (`preset-13`) a les bois rognés par l'échelle commune et la pastille ronde (§ 6.2) : l'accepter, ou le remplacer sous la même clé par un sujet restant du pack (fichier et deux libellés, sans migration, § 6.1) | le porteur |
| **E67-8** — `ValidNickname::ALLOWED_PATTERN` (contrat C5, § 5.1) n'a pas de modificateur `D` : son `$` s'ancre devant un `\n` final. La règle livrée ne l'applique qu'à un caractère à la fois (§ 5.4), mais un consommateur futur qui l'appliquerait à une chaîne entière accepterait `"Bob\n"`. Ajouter `D` au motif du contrat, ou garder le motif et la seule application caractère par caractère | le porteur (contrat C5) |
| **E68-2** — liste noire : valider les sept entrées importées retirées (§ 5.7) ; trancher le retrait proposé, non livré, de « xx » et « xxx » de `en.txt` ; relire les résidus gardés malgré leurs faux positifs (« conne », « trique », « étron », « bitte », « chatte », « semen », « sexual », « rapist », « shota », « twink », « spunk », « snatch », « lolita », « octopussy », « swinger », « negro », « escort », et les saisies à lettre double qui se réduisent sur une entrée, résidu nommé du § 5.7) ; tout ajustement est une édition des fichiers et de `# changes:`, sans code | le porteur |
| **E111-7** — aucun lot du J1 ne livre de geste de changement d'avatar ou de pseudo hors prise de siège (I4.5, I5.5) : la route de test `…/resign` de `PlayerTokenTest` (re-signature d'avatar hors siège) et l'en-tête de `NicknameBlocklistTest`, qui décrit encore des envois par `NicknameFormRequest` « tant que `50` n'a pas livré ses FormRequest » (condition remplie par `StoreRoomRequest` et `JoinRoomRequest`, E101-6), restent en l'état. Les rebrancher dans le lot qui livrera ce geste, ou dans une passe de dette de tests | le porteur |
| **E122-7** — `SeatTakingTest` › « re-signe le player_token avec l'avatar choisi » (preuve d'I4.5 exigée par le § 9) est instable : l'hôte du salon `$free` de `seatTakingRoom()` reçoit un avatar tiré au hasard par `PlayerFactory`, et environ une fois sur 24 il tire la clé attendue, que `suggest()` ne rend alors plus. Fixer l'avatar de cet hôte à une clé différente, dans un lot de `50` qui touche ce fichier ou une passe de dette de tests | le porteur (`50`, fichier de test) |
| Affichage du nom réel à son titulaire hors du back-office — écart à C14 § 3, **non retenu**, à poser seulement s'il est souhaité | `20` (contrat C14), sur décision du porteur |
| ~~Tous les sujets du § 10~~ — **rédigés** au § 13 le 07/10 (D66 du 07/10) ; restent : la relecture des textes FR et EN (compteurs, e-mails d'export et de dormance, interstitiel des CGU) et la vérification d'`APP_URL` avant l'ouverture des passkeys | le porteur |

Les lignes ouvertes par un identifiant `E<étape>-<n>` (E63-1, E63-2, E63-3, E67-8, E68-2, E111-7, E122-7) sont les points restés ouverts à l'implémentation de la phase C du J1 (étapes 62 à 125), relevés au journal des écarts : aucun n'est tranché par cette spec, et le code livré applique la lecture décrite à la section citée tant que le porteur ne s'est pas prononcé — amendé le 28/09.
