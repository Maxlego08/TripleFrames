<?php

namespace App\Http\Requests\Admin;

use App\Enums\FrameLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Changer le niveau d'une image — spec 20 § 5.7.
 *
 * Un seul champ, le niveau sur l'échelle fermée 1-5, **sans défaut** : un
 * défaut serait un classement non décidé (§ 6.5). Aucun motif n'est saisi :
 * quand une image publiée sort du jeu par ce geste, c'est le serveur qui écrit
 * le motif, en texte (§ 2.7).
 */
class FrameLevelUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|Enum>>
     */
    public function rules(): array
    {
        return [
            'frame_level' => ['required', 'integer', Rule::enum(FrameLevel::class)],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'frame_level' => __('admin.validation.frame_level'),
        ];
    }

    /** Le niveau choisi par le curateur. */
    public function frameLevel(): FrameLevel
    {
        return FrameLevel::from($this->integer('frame_level'));
    }
}
