<?php

namespace App\Avatars;

use App\Enums\AvatarKind;
use App\Models\User;

/**
 * L'avatar qu'écrit une prise de siège — spec 40 § 11.4, D49 du 01/10.
 *
 * Le formulaire de siège envoie une clé du catalogue, ou {@see self::ACCOUNT}
 * (« Mon avatar ») pour un compte qui porte une image visible. Sous le verrou
 * de la prise de siège, le compte est RELU : son image personnelle effective
 * ({@see User::personalImage()}), téléversée ou photo du fournisseur, donne la
 * nature `upload` ou `provider` ; sinon prédéfini. Dans les deux cas le siège reçoit un prédéfini
 * de REPLI — celui du compte, sinon la suggestion du salon —, que porte aussi
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
     * @param  string  $choice  Clé validée du catalogue, ou {@see self::ACCOUNT}.
     * @param  User|null  $user  Le compte connecté de la requête.
     * @param  string|null  $preferred  Revendication `avatar` du jeton courant.
     * @param  list<string>  $taken  Avatars prédéfinis des sièges tenus.
     */
    public static function resolve(string $choice, ?User $user, ?string $preferred, array $taken): self
    {
        if ($choice !== self::ACCOUNT) {
            return new self(AvatarKind::Preset, $choice);
        }

        $account = $user === null ? null : User::query()->select(AccountImage::columns())->find($user->id);

        $own = $account?->avatar_preset;
        $fallback = $own !== null && AvatarPresetCatalog::has($own) ? $own : AvatarPresetCatalog::suggest($preferred, $taken);

        $image = $account?->personalImage();

        if ($image !== null) {
            return new self($image->kind(), $fallback);
        }

        return new self(AvatarKind::Preset, $fallback);
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
