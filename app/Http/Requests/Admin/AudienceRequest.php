<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;

/**
 * La fenêtre de l'écran « Audience » — spec 20 § 12.4 (D48 du 01/10).
 */
class AudienceRequest extends FormRequest
{
    /**
     * Les trois fenêtres offertes, en jours (le jour courant compris).
     *
     * @var array<string, int>
     */
    public const array WINDOWS = ['7d' => 7, '30d' => 30, '90d' => 90];

    public const string DEFAULT_WINDOW = '30d';

    /**
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'window' => ['nullable', Rule::in(array_keys(self::WINDOWS))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['window' => __('admin.validation.perf_window')];
    }

    public function window(): string
    {
        $value = trim((string) $this->string('window'));

        return array_key_exists($value, self::WINDOWS) ? $value : self::DEFAULT_WINDOW;
    }

    /** Le début de la fenêtre : minuit, `n − 1` jours avant aujourd'hui. */
    public function since(): CarbonImmutable
    {
        return Date::now()->toImmutable()->startOfDay()->subDays(self::WINDOWS[$this->window()] - 1);
    }
}
