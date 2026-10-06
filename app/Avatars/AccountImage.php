<?php

namespace App\Avatars;

use App\Enums\AvatarKind;
use App\Enums\ReportTarget;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Les deux images PERSONNELLES d'un compte — l'image téléversée (spec 40 § 11,
 * D49 du 01/10) et la copie locale de la photo du fournisseur (§ 12.6, D51 du
 * 01/10).
 *
 * Elles se modèrent de la même façon — signalement par deux sièges distincts,
 * masquage qui survit à la suppression du fichier, levée et retrait par
 * l'administrateur — sur des colonnes distinctes : cette énumération est le
 * seul endroit qui relie une nature à ses colonnes, à sa cible de signalement
 * et à son préfixe de stockage.
 */
enum AccountImage: string
{
    case Upload = 'upload';

    case Provider = 'provider';

    /** L'image d'une nature d'avatar, `null` pour un prédéfini ou les initiales. */
    public static function fromKind(?AvatarKind $kind): ?self
    {
        return match ($kind) {
            AvatarKind::Upload => self::Upload,
            AvatarKind::Provider => self::Provider,
            default => null,
        };
    }

    public function kind(): AvatarKind
    {
        return match ($this) {
            self::Upload => AvatarKind::Upload,
            self::Provider => AvatarKind::Provider,
        };
    }

    public function pathColumn(): string
    {
        return match ($this) {
            self::Upload => 'avatar_upload_path',
            self::Provider => 'avatar_provider_path',
        };
    }

    public function hiddenColumn(): string
    {
        return match ($this) {
            self::Upload => 'avatar_upload_hidden_at',
            self::Provider => 'avatar_provider_hidden_at',
        };
    }

    public function reportsFromColumn(): string
    {
        return match ($this) {
            self::Upload => 'avatar_upload_reports_from',
            self::Provider => 'avatar_provider_reports_from',
        };
    }

    public function reportTarget(): ReportTarget
    {
        return match ($this) {
            self::Upload => ReportTarget::UploadedAvatar,
            self::Provider => ReportTarget::ProviderAvatar,
        };
    }

    /** Préfixe du fichier sur le disque `avatars`. */
    public function prefix(): string
    {
        return match ($this) {
            self::Upload => UploadedAvatars::PREFIX,
            self::Provider => UploadedAvatars::PROVIDER_PREFIX,
        };
    }

    /**
     * Les colonnes qu'une lecture du compte doit charger pour juger de la
     * visibilité de ses deux images.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'id',
            'avatar_kind',
            'avatar_preset',
            'avatar_upload_path',
            'avatar_upload_hidden_at',
            'avatar_upload_reports_from',
            'avatar_provider_path',
            'avatar_provider_hidden_at',
            'avatar_provider_reports_from',
            'avatar_provider_source',
            'anonymized_at',
        ];
    }

    public function path(User $user): ?string
    {
        $path = $user->getAttribute($this->pathColumn());

        return is_string($path) ? $path : null;
    }

    public function hiddenAt(User $user): ?CarbonImmutable
    {
        $hidden = $user->getAttribute($this->hiddenColumn());

        return $hidden instanceof CarbonImmutable ? $hidden : null;
    }

    public function reportsFrom(User $user): ?CarbonImmutable
    {
        $from = $user->getAttribute($this->reportsFromColumn());

        return $from instanceof CarbonImmutable ? $from : null;
    }

    /** Un fichier, aucun masquage ni retrait, compte non anonymisé. */
    public function isVisible(User $user): bool
    {
        return $this->path($user) !== null
            && $this->hiddenAt($user) === null
            && $user->anonymized_at === null;
    }
}
