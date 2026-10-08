<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le profil du compte : le nom suit la règle de pseudo de jeu, mais seulement
 * quand il CHANGE (spec 40 § 13.1) — un nom antérieur renvoyé tel quel n'est
 * ni refusé ni réécrit.
 */
class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => $this->prepareName($this->input('name'), $this->currentName())]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, Closure|ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();

        return $this->profileRules($user instanceof User ? $user->id : null, $this->currentName());
    }

    private function currentName(): ?string
    {
        $user = $this->user();

        return $user instanceof User ? $user->name : null;
    }
}
