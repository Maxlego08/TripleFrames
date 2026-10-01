<?php

namespace App\Http\Requests\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;

/**
 * La fenêtre de l'écran « Performances » — spec 20 § 12.3 (D47 du 01/10).
 */
class PerformanceRequest extends FormRequest
{
    /**
     * Les trois fenêtres offertes, en heures.
     *
     * @var array<string, int>
     */
    public const array WINDOWS = ['1h' => 1, '24h' => 24, '7d' => 168];

    public const string DEFAULT_WINDOW = '24h';

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

    public function since(): CarbonImmutable
    {
        return Date::now()->toImmutable()->subHours(self::WINDOWS[$this->window()]);
    }
}
