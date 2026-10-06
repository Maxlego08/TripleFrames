<?php

namespace App\Http\Requests\Admin;

use App\Support\Curation\ReadyBatch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * « Publier les films prêts » — spec 20 § 8.1 bis, D59 du 06/10.
 *
 * Deux champs, tous deux venus de l'écran du lot : `movie_ids`, les films
 * montrés au curateur, et `ambiguity_digest`, l'empreinte de l'aperçu du lot
 * ({@see ReadyBatch}). Un champ absent ou mal formé ne vient pas d'un écran à
 * jour : le curateur relit le lot, comme pour une empreinte périmée.
 */
class MovieBatchPublishRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'movie_ids' => ['required', 'array', 'min:1'],
            'movie_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'ambiguity_digest' => ['required', 'string', 'size:'.MoviePublishRequest::DIGEST_LENGTH],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $stale = __('admin.catalog.publish_ready.stale');

        $messages = [];

        foreach ($this->rules() as $field => $rules) {
            foreach ($rules as $rule) {
                if (is_string($rule)) {
                    $messages[$field.'.'.explode(':', $rule)[0]] = $stale;
                }
            }
        }

        return $messages;
    }

    /** @return list<int> */
    public function movieIds(): array
    {
        /** @var array<int, int|string> $ids */
        $ids = $this->array('movie_ids');

        return array_values(array_map(static fn (int|string $id): int => (int) $id, $ids));
    }

    public function ambiguityDigest(): string
    {
        return (string) $this->string('ambiguity_digest');
    }
}
