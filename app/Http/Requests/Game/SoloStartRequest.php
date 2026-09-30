<?php

namespace App\Http\Requests\Game;

use App\Actions\Game\StartSoloGame;
use App\Concerns\PlayerIdentityValidationRules;
use App\Enums\SettingPresetKey;
use App\Support\Game\SoloSeat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Démarrage d'une partie solo — `solo.store`, `POST /solo` (spec 60 § 16.2 ;
 * contrat C7 § 2.4, écart (g) du § 22 bis ; contrat C5, 40 § 5.8).
 *
 * - `preset` ∈ `SettingPresetKey`, toujours : le joueur choisit un des
 *   quatre presets du site, sans formulaire de réglages (D19 du 23/09). Son
 *   libellé d'erreur est `validation.attributes.preset` (écart (o)) : un
 *   refus n'affiche jamais le nom brut du champ.
 * - `nickname` et `avatar`, par le trait {@see PlayerIdentityValidationRules}
 *   (pseudo remplacé par sa forme canonique avant validation), **seulement
 *   pour un jeton qui ne tient encore aucun siège solo** : un siège n'existe
 *   jamais sans pseudo au J1 (C5), et la reprise d'un siège ne revalide
 *   jamais un pseudo (I5.5). Aucune unicité en solo (C5).
 *
 * Ce n'est qu'une lecture préalable, sans verrou : {@see StartSoloGame} relit
 * le siège du jeton sous son verrou et fait seule autorité. Un envoi refusé à
 * la validation n'atteint pas l'action : il ne frappe aucun `player_token`
 * (40 § 2.1, étape 3).
 */
class SoloStartRequest extends FormRequest
{
    use PlayerIdentityValidationRules;

    /** Mémo de {@see self::requiresIdentity()} : une seule lecture par requête. */
    private ?bool $identityRequired = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'preset' => ['required', 'string', Rule::enum(SettingPresetKey::class)],
        ];

        if ($this->requiresIdentity()) {
            $rules['nickname'] = $this->nicknameRules();
            $rules['avatar'] = $this->avatarPresetRules();
        }

        return $rules;
    }

    /**
     * Le preset validé.
     *
     * @throws LogicException Appelé avant une validation réussie.
     */
    public function preset(): SettingPresetKey
    {
        return SettingPresetKey::tryFrom((string) $this->validated('preset'))
            ?? throw new LogicException('SoloStartRequest : preset non validé.');
    }

    /** Le pseudo validé, forme canonique ; `null` quand il n'est pas exigé. */
    public function nickname(): ?string
    {
        $nickname = $this->validated('nickname');

        return is_string($nickname) ? $nickname : null;
    }

    /** La clé d'avatar validée ; `null` quand elle n'est pas exigée. */
    public function avatarPreset(): ?string
    {
        $avatar = $this->validated('avatar');

        return is_string($avatar) ? $avatar : null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->requiresIdentity()) {
            $this->merge(['nickname' => $this->prepareNickname($this->input('nickname'))]);
        }
    }

    /** Vrai tant que le jeton courant ne tient aucun siège solo. */
    private function requiresIdentity(): bool
    {
        return $this->identityRequired ??= app(SoloSeat::class)->of($this) === null;
    }
}
