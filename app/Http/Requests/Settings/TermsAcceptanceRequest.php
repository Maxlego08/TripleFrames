<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ConsentValidationRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La ré-acceptation des CGU (spec 40 § 13.1) : la case `terms`, et la case
 * `age` seulement pour un compte qui n'a jamais déclaré son âge (le premier
 * administrateur, créé en console).
 */
class TermsAcceptanceRequest extends FormRequest
{
    use ConsentValidationRules;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return $this->consentRules(age: $this->asksAge());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->consentMessages();
    }

    /** Vrai si le compte doit aussi déclarer son âge. */
    public function asksAge(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->age_confirmed_at === null;
    }
}
