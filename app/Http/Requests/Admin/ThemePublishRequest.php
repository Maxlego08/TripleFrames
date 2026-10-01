<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Publier ou dépublier un thème — spec 20 § 9.6. `is_published` requis,
 * booléen : publier est refusé sous le seuil par l'action, en erreur traduite
 * relue dans la transaction ; dépublier est toujours permis.
 */
class ThemePublishRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'is_published' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'is_published' => (string) __('admin.validation.theme_published'),
        ];
    }

    /** La bascule demandée. */
    public function published(): bool
    {
        return $this->boolean('is_published');
    }
}
