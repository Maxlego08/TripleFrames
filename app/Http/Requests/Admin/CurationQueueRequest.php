<?php

namespace App\Http\Requests\Admin;

use App\Enums\ContentFlag;
use App\Support\Curation\CurationQueue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La query string de la file de curation et de « film suivant » (spec 20
 * § 4.1) : trois filtres, le film courant, la page.
 *
 * Les listes blanches sont celles de {@see CurationQueue}, relues ici et par le
 * contrôleur pour la prop `options` : un choix offert à l'écran est, par
 * construction, un choix accepté. Aucun message écrit ici : `attributes()`
 * nomme chaque champ par une clé `admin.validation.*` (règle 4).
 */
class CurationQueueRequest extends FormRequest
{
    /** Une page de la file. */
    public const int PER_PAGE = 25;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'entry' => ['nullable', Rule::in(CurationQueue::ENTRIES)],
            'motive' => ['nullable', Rule::in(array_keys(CurationQueue::MOTIVE_COLUMNS))],
            'content_flag' => ['nullable', Rule::enum(ContentFlag::class)],
            'current' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
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
            'entry' => __('admin.validation.entry'),
            'motive' => __('admin.validation.motive'),
            'content_flag' => __('admin.validation.content_flag'),
            'current' => __('admin.validation.current_movie'),
        ];
    }

    /**
     * La file que la query string désigne.
     */
    public function queue(): CurationQueue
    {
        $filters = $this->filters();

        return new CurationQueue(
            entry: $filters['entry'],
            motive: $filters['motive'],
            contentFlag: $filters['content_flag'] === null ? null : ContentFlag::from($filters['content_flag']),
        );
    }

    /**
     * Les filtres tels que l'écran doit les réafficher — miroir exact de la
     * query string, valeur hors liste blanche ramenée à `null`.
     *
     * @return array{entry: string|null, motive: string|null, content_flag: string|null}
     */
    public function filters(): array
    {
        return [
            'entry' => $this->choice('entry', CurationQueue::ENTRIES),
            'motive' => $this->choice('motive', array_keys(CurationQueue::MOTIVE_COLUMNS)),
            'content_flag' => $this->choice('content_flag', array_column(ContentFlag::cases(), 'value')),
        ];
    }

    /**
     * Les seuls filtres POSÉS, à reporter dans une URL : « film suivant » mène à
     * l'éditeur avec eux, pour que le film d'après reste dans la même strate du
     * lot pilote, et la file vide les réaffiche.
     *
     * @return array<string, string>
     */
    public function filterQuery(): array
    {
        return array_filter($this->filters(), static fn (?string $value): bool => $value !== null);
    }

    /** Le film courant, que « film suivant » saute ; `null` s'il n'est pas donné. */
    public function current(): ?int
    {
        if (! $this->filled('current')) {
            return null;
        }

        $current = $this->integer('current');

        return $current >= 1 ? $current : null;
    }

    /**
     * Une valeur de liste blanche, ou `null`.
     *
     * @param  list<string>  $allowed
     */
    private function choice(string $key, array $allowed): ?string
    {
        $value = trim((string) $this->string($key));

        return in_array($value, $allowed, true) ? $value : null;
    }
}
