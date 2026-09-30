<?php

namespace App\Http\Requests\Admin;

use App\Support\Catalog\AmbiguityReport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publier ou republier un film — spec 20 § 8.1.
 *
 * Un seul champ : `ambiguity_digest`, l'empreinte de l'aperçu d'ambiguïté que
 * la confirmation a montré au curateur ({@see AmbiguityReport::digest()},
 * SHA-256 en hexadécimal). L'action la compare, sous le verrou du film, à
 * celle qu'elle recalcule à l'instant : un catalogue changé entre l'aperçu
 * et le clic fait refuser la publication (`admin.movie.publish.preview_stale`).
 *
 * Les conditions de publication — contenu, couverture, devinabilité — ne sont
 * pas des champs : l'action les lit sous verrou, et les refuse en erreurs
 * traduites, jamais en 403.
 */
class MoviePublishRequest extends FormRequest
{
    /** Longueur d'une empreinte SHA-256 en hexadécimal. */
    public const int DIGEST_LENGTH = 64;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'ambiguity_digest' => ['required', 'string', 'size:'.self::DIGEST_LENGTH],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * Une empreinte absente ou mal formée ne vient pas de la confirmation à
     * jour : le curateur relit l'aperçu, comme pour une empreinte périmée.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ambiguity_digest.required' => __('admin.movie.publish.preview_stale'),
            'ambiguity_digest.string' => __('admin.movie.publish.preview_stale'),
            'ambiguity_digest.size' => __('admin.movie.publish.preview_stale'),
        ];
    }

    public function ambiguityDigest(): string
    {
        return (string) $this->string('ambiguity_digest');
    }
}
