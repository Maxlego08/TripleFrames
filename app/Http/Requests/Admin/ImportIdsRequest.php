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
        ];
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
