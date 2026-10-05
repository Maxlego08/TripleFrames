<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lancer l'import d'un lot à l'aperçu — spec 20 § 5.10, D57 du 05/10. Le
 * jeton désigne un lot de l'auteur de la requête, et de lui seul : le
 * contrôleur le relit sous sa clé.
 */
class FrameBatchImportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|array<mixed>|string|Closure>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.*' => __('admin.frame_batch.expired'),
        ];
    }

    public function token(): string
    {
        return (string) $this->string('token');
    }
}
