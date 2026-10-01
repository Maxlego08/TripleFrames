<?php

namespace App\Http\Requests\Admin;

use App\Concerns\CatalogImportValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le collage d'identifiants — la voie d'EXCEPTION.
 *
 * Elle **ignore entièrement le filtre de goût** et marque chaque film entré
 * `is_import_exception`, avec ses motifs, même lorsque le film satisfait tout
 * le filtre (décision 11). Aucun champ de filtre n'est donc offert ici : ce
 * serait mentir sur ce que la voie fait.
 *
 * Le filtre de CONTENU, lui, s'applique à l'identique : `adult`, FR -18, US
 * NC-17 et US X refusent par cette voie exactement comme par le balayage, et
 * aucune option ne le contourne (décision 12).
 *
 * `theme_ids` (D43 du 01/10, spec 20 § 3.3) : la multi-sélection facultative
 * des thèmes à appliquer en exception `added` aux films du collage. La même
 * requête sert l'aperçu à blanc, qui l'ignore : un aperçu n'écrit rien.
 */
class ImportIdsRequest extends FormRequest
{
    use CatalogImportValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string|\Closure>>
     */
    public function rules(): array
    {
        return [
            'ids' => $this->identifiersRules(),
            ...$this->themeIdsRules(),
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
            'ids.required' => __('admin.validation.ids.required'),
            'theme_ids.max' => __('admin.validation.import_themes.max', ['max' => self::pasteMaxThemes()]),
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
            'theme_ids' => __('admin.validation.import_themes.attribute'),
            'theme_ids.*' => __('admin.validation.import_themes.attribute'),
        ];
    }

    /**
     * Les thèmes choisis, dans l'ordre envoyé et dédoublonnés ; `[]` sans
     * sélection.
     *
     * @return list<int>
     */
    public function themeIds(): array
    {
        /** @var array<array-key, mixed> $raw */
        $raw = (array) $this->input('theme_ids', []);

        /** @var list<int> $ids */
        $ids = [];

        foreach ($raw as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);

            if (is_int($id) && $id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Les identifiants du collage, dans l'ordre du curateur et dédoublonnés —
     * lus par `TmdbIdentifierList`, seul lecteur autorisé d'un collage.
     *
     * @return list<int>
     */
    public function identifiers(): array
    {
        return $this->parseIdentifiers((string) $this->string('ids'));
    }
}
