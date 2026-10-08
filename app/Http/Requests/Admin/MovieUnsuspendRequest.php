<?php

namespace App\Http\Requests\Admin;

use App\Actions\Curation\UnsuspendMovie;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Lever la suspension d'un film — spec 20 § 11.2.
 *
 * Motif facultatif (`movie.unsuspended`), et l'empreinte de l'aperçu
 * d'ambiguïté que la confirmation a montré quand le film revient `published`
 * (décision 13). Elle n'est pas exigée ici : seul {@see UnsuspendMovie} sait,
 * sous verrou, si le film revient au catalogue publié ; absente ou périmée
 * alors, la levée est refusée sans rien écrire (`preview_stale`).
 */
class MovieUnsuspendRequest extends SuspensionRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'ambiguity_digest' => ['nullable', 'string', 'size:'.MoviePublishRequest::DIGEST_LENGTH],
        ];
    }

    /**
     * Une empreinte mal formée ne vient pas de la confirmation à jour :
     * l'administrateur relit l'aperçu, comme pour une empreinte périmée.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ambiguity_digest.string' => __('admin.movie.publish.preview_stale'),
            'ambiguity_digest.size' => __('admin.movie.publish.preview_stale'),
        ];
    }

    public function ambiguityDigest(): ?string
    {
        $digest = trim((string) $this->string('ambiguity_digest'));

        return $digest === '' ? null : $digest;
    }
}
