<?php

namespace App\Http\Requests\Admin;

use App\Concerns\CatalogImportValidationRules;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le balayage `discover` déclenché depuis l'écran — la voie ORDINAIRE.
 *
 * Elle applique le filtre de goût : notoriété, langue originale, année. Élargir
 * un seul de ces trois axes n'est pas interdit, c'est **tracé** —
 * `ImportFilter::isWiderThanDefault()` pose `import_run.is_widened`, et tout
 * film entré par un balayage élargi est marqué `is_import_exception` avec ses
 * motifs (décision 11).
 *
 * Les défauts viennent de `config('catalog.import_filter')` par
 * {@see ImportFilter::default()}, jamais d'un littéral.
 */
class ImportDiscoverRequest extends FormRequest
{
    use CatalogImportValidationRules;

    /**
     * Prepare the data for validation.
     *
     * Trois commodités, et aucune règle : les langues acceptées aussi bien en
     * tableau qu'en chaîne séparée par des virgules — un `<select multiple>`
     * envoie l'un, un champ libre l'autre — et les trois axes absents remplis
     * par le défaut du site. Un envoi qui ne porte aucun des trois est donc le
     * balayage par défaut, et non une erreur de validation.
     */
    protected function prepareForValidation(): void
    {
        $default = ImportFilter::default();
        $languages = $this->input('languages');

        if (is_string($languages)) {
            $languages = array_values(array_filter(array_map('trim', explode(',', $languages))));
        }

        $this->merge([
            'min_votes' => $this->filled('min_votes') ? $this->input('min_votes') : $default->minVoteCount,
            'languages' => is_array($languages) && $languages !== [] ? $languages : $default->languages,
            'min_year' => $this->filled('min_year') ? $this->input('min_year') : $default->minReleaseYear,
            'pages' => $this->filled('pages') ? $this->input('pages') : self::pagesMin(),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'min_votes' => $this->minVotesRules(),
            'min_year' => $this->minYearRules(),
            'pages' => $this->pagesRules(),
            ...$this->languagesRules(),
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
            'min_votes' => __('admin.validation.min_votes'),
            'languages' => __('admin.validation.languages'),
            'languages.*' => __('admin.validation.languages'),
            'min_year' => __('admin.validation.min_year'),
            'pages' => __('admin.validation.pages'),
        ];
    }

    /**
     * Le filtre appliqué à ce balayage. C'est lui, comparé au défaut du site,
     * qui pose `is_widened` — **figé au démarrage**, jamais recalculé : le
     * défaut peut changer après coup, la preuve qu'un balayage a été élargi,
     * non.
     */
    public function filter(): ImportFilter
    {
        /** @var list<string> $languages */
        $languages = [];

        foreach ($this->array('languages') as $language) {
            if (is_string($language) && trim($language) !== '') {
                $languages[] = trim($language);
            }
        }

        return ImportFilter::make(
            minVoteCount: $this->integer('min_votes'),
            languages: $languages === [] ? null : $languages,
            minReleaseYear: $this->integer('min_year'),
        );
    }

    /** Le nombre de pages TMDB que cet envoi autorise le worker à traiter. */
    public function pages(): int
    {
        return max(self::pagesMin(), min(self::pagesMax(), $this->integer('pages')));
    }
}
