<?php

namespace App\Avatars;

use App\Enums\AvatarKind;
use App\Models\Player;
use App\Models\User;

/**
 * L'avatar qu'écrit une prise de siège ou un changement au lobby — spec 40
 * § 11.4, D49 du 01/10, D55 du 02/10.
 *
 * **À la prise de siège, le serveur attribue** ({@see self::assign()}) : aucun
 * formulaire d'entrée ne porte d'avatar (D55 du 02/10). Un compte dont
 * l'avatar choisi est son image TÉLÉVERSÉE, visible, reçoit « Mon avatar » ;
 * sinon un prédéfini libre du salon, la revendication `avatar` du jeton en
 * préférence (`suggest()`, I5.8).
 *
 * **Au lobby, le joueur choisit** ({@see self::resolve()}) une clé du
 * catalogue, ou {@see self::ACCOUNT} (« Mon avatar ») pour un compte qui
 * porte une image visible. Sous le verrou du salon, le compte est RELU : son
 * image personnelle effective ({@see User::personalImage()}), téléversée ou
 * photo du fournisseur, donne la nature `upload` ou `provider` ; sinon
 * prédéfini. Seul le compte du siège (`player.user_id`) ouvre ce choix
 * ({@see self::seatAccount()}). Dans les deux cas le siège reçoit un
 * prédéfini de REPLI — `suggest(users.avatar_preset ?? préféré, pris)` —, que porte aussi
 * la re-signature du jeton (I4.5) et vers lequel un masquage fait redescendre
 * l'affichage (§ 11.5).
 */
final readonly class SeatAvatar
{
    /** Valeur du formulaire de siège qui désigne l'image du compte. */
    public const string ACCOUNT = 'account';

    private function __construct(
        public AvatarKind $kind,
        public string $preset,
    ) {}

    /**
     * L'attribution automatique d'une prise de siège (D55 du 02/10) : « Mon
     * avatar » quand l'avatar choisi du compte est son image téléversée et
     * qu'une image personnelle est visible ; sinon le prédéfini que
     * `suggest()` tire de `$preferred` parmi les clés libres.
     *
     * @param  User|null  $user  Le compte connecté de la requête.
     * @param  string|null  $preferred  Revendication `avatar` du jeton courant.
     * @param  list<string>  $taken  Avatars prédéfinis des sièges tenus.
     */
    public static function assign(?User $user, ?string $preferred, array $taken): self
    {
        $account = self::reread($user);

        // L'image TÉLÉVERSÉE elle-même, visible : jamais la photo du
        // fournisseur à sa place (spec 40 § 2.2, § 11.4).
        if ($account !== null && $account->avatar_kind === AvatarKind::Upload && AccountImage::Upload->isVisible($account)) {
            return self::forAccount($account, $preferred, $taken);
        }

        return new self(AvatarKind::Preset, AvatarPresetCatalog::suggest($preferred, $taken));
    }

    /**
     * Le choix explicite du lobby.
     *
     * @param  string  $choice  Clé validée du catalogue, ou {@see self::ACCOUNT}.
     * @param  User|null  $user  Le compte connecté de la requête.
     * @param  string|null  $preferred  Préférence du repli : le prédéfini
     *                                  courant du siège, ou la revendication
     *                                  `avatar` du jeton.
     * @param  list<string>  $taken  Avatars prédéfinis des AUTRES sièges tenus.
     */
    public static function resolve(string $choice, ?User $user, ?string $preferred, array $taken): self
    {
        if ($choice !== self::ACCOUNT) {
            return new self(AvatarKind::Preset, $choice);
        }

        return self::forAccount(self::reread($user), $preferred, $taken);
    }

    /**
     * « Mon avatar » : la nature de l'image personnelle visible, sinon
     * prédéfini ; le repli est `suggest(users.avatar_preset ?? $preferred,
     * pris)` (spec 40 § 11.4) — jamais une clé tenue par un autre siège quand
     * il en reste une libre.
     *
     * @param  list<string>  $taken
     */
    private static function forAccount(?User $account, ?string $preferred, array $taken): self
    {
        $fallback = AvatarPresetCatalog::suggest($account->avatar_preset ?? $preferred, $taken);

        $image = $account?->personalImage();

        if ($image !== null) {
            return new self($image->kind(), $fallback);
        }

        return new self(AvatarKind::Preset, $fallback);
    }

    /**
     * Le compte dont « Mon avatar » se lit pour ce siège : le compte connecté
     * de la requête, seulement s'il est celui du siège (`player.user_id`,
     * figé à la prise de siège). L'affichage ({@see Player::avatarRef()}) lit
     * l'image de `player.user_id` : un invité connecté après coup, ou un autre
     * compte sur le même jeton, n'a donc pas « Mon avatar » sur ce siège.
     */
    public static function seatAccount(Player $seat, ?User $user): ?User
    {
        return $user !== null && $seat->user_id !== null && $seat->user_id === $user->id ? $user : null;
    }

    /** Le compte relu sur les seules colonnes d'image, sous le verrou de l'appelant. */
    private static function reread(?User $user): ?User
    {
        return $user === null ? null : User::query()->select(AccountImage::columns())->find($user->id);
    }

    /**
     * Vrai si le formulaire de siège peut proposer « Mon avatar » à ce compte.
     */
    public static function accountChoiceAvailable(?User $user): bool
    {
        return $user?->personalImage() !== null;
    }

    /**
     * La prop `avatars.account` du formulaire de siège : l'URL de l'image du
     * compte, ou `null`.
     *
     * @return array{url: string}|null
     */
    public static function accountOption(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $path = $user->personalImage()?->path($user);

        return $path === null ? null : ['url' => UploadedAvatars::url($path)];
    }
}
