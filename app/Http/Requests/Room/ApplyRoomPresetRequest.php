<?php

namespace App\Http\Requests\Room;

use App\Actions\Room\ApplyRoomPreset;
use App\Enums\SettingPresetKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Application d'un preset du site — `room.settings.preset`,
 * `POST /r/{room}/settings/preset`, corps `{ preset: SettingPresetKey }`
 * (spec 50 § 3.1 et § 5.3, contrat C0).
 *
 * Ne valide que la forme du corps. Le serveur n'interdit pas d'appliquer un
 * preset grisé (§ 5.3) : l'autorité, le statut et la capacité sont relus sous
 * le verrou du salon par {@see ApplyRoomPreset}.
 */
class ApplyRoomPresetRequest extends FormRequest
{
    private const string PRESET = 'preset';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|Enum>>
     */
    public function rules(): array
    {
        return [
            self::PRESET => ['required', 'string', Rule::enum(SettingPresetKey::class)],
        ];
    }

    /** Le preset demandé. */
    public function preset(): SettingPresetKey
    {
        return SettingPresetKey::from($this->string(self::PRESET)->value());
    }
}
