<?php

namespace App\Http\Requests\Settings;

use App\Avatars\AvatarPresetCatalog;
use App\Avatars\SeatAvatar;
use App\Enums\AvatarKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Choix de la nature effective de l'avatar d'un compte — `avatar.update`
 * (spec 40 § 11.1). Un seul champ, `avatar`, comme au formulaire de siège :
 * une clé du catalogue, ou `account` (« Mon avatar ») pour l'image
 * téléversée. Que l'image soit encore affichable, l'action le relit sous
 * verrou. La copie provider (J2) n'est pas un choix de cet écran au J1.
 */
class AvatarChoiceRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'string', Rule::in([...AvatarPresetCatalog::keys(), SeatAvatar::ACCOUNT, self::PROVIDER])],
        ];
    }

    /** La photo du fournisseur (spec 40 § 12.6, D51 du 01/10). */
    public const string PROVIDER = 'provider';

    public function kind(): AvatarKind
    {
        return match ($this->validated('avatar')) {
            SeatAvatar::ACCOUNT => AvatarKind::Upload,
            self::PROVIDER => AvatarKind::Provider,
            default => AvatarKind::Preset,
        };
    }

    /** La clé validée, seulement pour un prédéfini. */
    public function preset(): ?string
    {
        $avatar = $this->validated('avatar');

        return is_string($avatar) && AvatarPresetCatalog::has($avatar) ? $avatar : null;
    }
}
