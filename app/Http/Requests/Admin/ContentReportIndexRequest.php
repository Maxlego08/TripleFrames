<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\ContentReportController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le contrat de la query string de la file des signalements de contenu
 * (D63 du 07/10, spec 20 § 11.6) — filtre et pagination.
 */
class ContentReportIndexRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', Rule::in(ContentReportController::FILTERS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** `open` par défaut. */
    public function filter(): string
    {
        $value = trim((string) $this->string('filter'));

        return in_array($value, ContentReportController::FILTERS, true) ? $value : ContentReportController::FILTERS[0];
    }
}
