<?php

namespace App\Http\Requests\Admin;

use App\Support\Curation\ExclusionGrid;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Passer une revue d'image — spec 20 § 7.5, contrat C14-bis § 3.
 *
 * Quatre champs, et **jamais `decision`** : le serveur la dérive des réponses
 * ({@see ExclusionGrid::decisionFor()}). Un champ `decision` posté est ignoré.
 *
 * - `grid_version` : la version de la grille affichée ; elle doit valoir
 *   {@see ExclusionGrid::CURRENT_VERSION}, sinon `admin.review.grid_version_outdated` ;
 * - `reviewed_hash` : l'empreinte des octets affichés, comparée à
 *   `frame.published_hash` SOUS VERROU par l'action (`admin.review.stale`) ;
 * - `answers` : `{ <slug>: bool }`, `true` = conforme. Ici, seuls des slugs
 *   de la grille et des booléens ; que l'ensemble soit EXACTEMENT celui du
 *   niveau courant se relit sous le verrou (`admin.review.level_changed`) ;
 * - `declared_source_reference` : la source affichée en lecture seule, que
 *   l'envoi confirme (§ 7.6) — comparée sous le verrou
 *   (`admin.review.source_mismatch`).
 */
class FrameReviewStoreRequest extends FormRequest
{
    /** Longueur d'une empreinte SHA-256 en hexadécimal (`frame_review.reviewed_hash`). */
    public const int HASH_LENGTH = 64;

    /** Plafond de la référence déclarée (`frame_review.declared_source_reference`, `string(255)`). */
    public const int SOURCE_REFERENCE_MAX_LENGTH = 255;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|In|Closure|string>>
     */
    public function rules(): array
    {
        return [
            'grid_version' => ['required', 'integer', Rule::in([ExclusionGrid::CURRENT_VERSION])],
            'reviewed_hash' => ['required', 'string', 'size:'.self::HASH_LENGTH],
            'answers' => ['required', 'array', self::knownSlugs()],
            'answers.*' => ['required', 'boolean'],
            'declared_source_reference' => ['required', 'string', 'max:'.self::SOURCE_REFERENCE_MAX_LENGTH],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'grid_version.in' => __('admin.review.grid_version_outdated'),
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
            'grid_version' => __('admin.validation.grid_version'),
            'reviewed_hash' => __('admin.validation.reviewed_hash'),
            'answers' => __('admin.validation.answers'),
            'answers.*' => __('admin.validation.answers'),
            'declared_source_reference' => __('admin.validation.declared_source_reference'),
        ];
    }

    /**
     * Les réponses, clées par slug, en booléens.
     *
     * @return array<string, bool>
     */
    public function answers(): array
    {
        $answers = [];
        $input = $this->input('answers');

        foreach (is_array($input) ? $input : [] as $slug => $answer) {
            $answers[(string) $slug] = filter_var($answer, FILTER_VALIDATE_BOOLEAN);
        }

        return $answers;
    }

    public function gridVersion(): int
    {
        return $this->integer('grid_version');
    }

    public function reviewedHash(): string
    {
        return (string) $this->string('reviewed_hash');
    }

    public function declaredSourceReference(): string
    {
        return (string) $this->string('declared_source_reference');
    }

    /**
     * Chaque clé de `answers` est un slug de la grille courante : une clé
     * inconnue ne vient pas de l'écran de revue.
     */
    private static function knownSlugs(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $known = ExclusionGrid::slugs(ExclusionGrid::CURRENT_VERSION);

            foreach (array_keys($value) as $slug) {
                if (! is_string($slug) || ! in_array($slug, $known, true)) {
                    $fail(__('admin.review.answers_invalid'));

                    return;
                }
            }
        };
    }
}
