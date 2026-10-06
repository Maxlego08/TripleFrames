<?php

namespace App\Http\Requests\Legal;

use App\Support\Visitor\ConsentChoice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Le choix posté par la bannière de consentement, ou par « Modifier mon
 * choix » de la politique de confidentialité (D62 du 06/10).
 */
class ConsentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string|Enum>>
     */
    public function rules(): array
    {
        return [
            'choice' => ['required', 'string', Rule::enum(ConsentChoice::class)],
        ];
    }

    public function choice(): ConsentChoice
    {
        return ConsentChoice::from((string) $this->string('choice'));
    }
}
