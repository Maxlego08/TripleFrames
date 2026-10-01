<?php

namespace App\Http\Requests\Admin;

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ImportSource;
use App\Enums\Locale;
use App\Settings\RoomSettingsBounds;
use App\Support\Curation\CurationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le contrat de la query string du catalogue — tri, filtres et pagination.
 *
 * Il est le propriétaire unique des trois listes blanches (`exception`, `sort`,
 * `direction`) : le contrôleur les relit pour composer la prop `options`, de
 * sorte qu'un choix offert à l'écran soit, par construction, un choix accepté
 * par la validation. Deux listes séparées finiraient par diverger.
 *
 * Les trois filtres d'état sont validés par `Rule::enum` et jamais par une
 * liste de chaînes recopiée : ajouter une valeur à `ContentAvailability` doit
 * suffire.
 *
 * **Aucun message n'est écrit ici** : `attributes()` nomme chaque champ par une
 * clé `admin.validation.*` et laisse Laravel composer la phrase depuis
 * `lang/fr/validation.php` (règle 4).
 */
class CatalogIndexRequest extends FormRequest
{
    /**
     * Le filtre exigé par la décision 11 — « entrés par exception », et par
     * quel motif. `any` est le fait d'être entré par exception, qui n'est
     * **jamais dérivable** des trois motifs : un film collé qui satisfait tout
     * le filtre reste marqué entré par exception.
     *
     * @var list<string>
     */
    public const array EXCEPTIONS = ['any', 'language', 'vote_count', 'release_year'];

    /**
     * Les six tris offerts, et **aucun n'est indexé** — c'est la vérité
     * mesurable, pas une approximation flatteuse. La requête pilote est
     * `movie`, qui joint `movie_projection` par sa clé primaire :
     * `movie_projection_levels_idx (levels_count, movie_id)` n'est donc pas
     * emprunté pour un `ORDER BY levels_count`, et `variants_total` n'a aucun
     * index. Les quatre autres portent sur des colonnes que le § 3.1 refuse
     * explicitement d'indexer (2 à 5 valeurs distinctes pour les unes,
     * quelques centaines de lignes pour toutes). Chaque tri est donc un
     * filesort sur le jeu filtré : assumé à cette échelle, et à elle seule.
     *
     * @var list<string>
     */
    public const array SORTS = [
        'created_at',
        'title_original',
        'release_year',
        'vote_count',
        'levels_count',
        'variants_total',
    ];

    /** @var list<string> */
    public const array DIRECTIONS = ['asc', 'desc'];

    /** Une page de catalogue. */
    public const int PER_PAGE = 25;

    public const string DEFAULT_SORT = 'created_at';

    public const string DEFAULT_DIRECTION = 'desc';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'availability' => ['nullable', Rule::enum(ContentAvailability::class)],
            'content_flag' => ['nullable', Rule::enum(ContentFlag::class)],
            'import_source' => ['nullable', Rule::enum(ImportSource::class)],
            'exception' => ['nullable', Rule::in(self::EXCEPTIONS)],
            // Les bornes de `N` sont celles des réglages de salon, lues dans
            // `RoomSettingsBounds` et jamais recopiées ici (règle 2, n° 26).
            'playable_at' => [
                'nullable',
                'integer',
                'min:'.RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
                'max:'.RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
            ],
            // La file « titres manquants » (spec 20 § 9.3) : une locale ACTIVÉE.
            'missing_title' => ['nullable', Rule::enum(Locale::class)],
            // Prêts à publier, incomplets, écartés (§ 4.2, § 6.6, § 8.6).
            'curation_status' => ['nullable', Rule::enum(CurationStatus::class)],
            // Les films ACTIFS dans un thème, publié ou non (spec 20 § 9.6,
            // option de L20-28 ; D43 du 01/10).
            'theme_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(self::DIRECTIONS)],
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
            'q' => __('admin.validation.search'),
            'availability' => __('admin.validation.availability'),
            'content_flag' => __('admin.validation.content_flag'),
            'import_source' => __('admin.validation.import_source'),
            'exception' => __('admin.validation.exception'),
            'playable_at' => __('admin.validation.playable_at'),
            'missing_title' => __('admin.validation.missing_title'),
            'curation_status' => __('admin.validation.curation_status'),
            'theme_id' => __('admin.validation.catalog_theme'),
            'sort' => __('admin.validation.sort'),
            'direction' => __('admin.validation.direction'),
        ];
    }

    /**
     * Les filtres tels que l'écran doit les réafficher — miroir exact de la
     * query string, `sort` et `direction` toujours posés.
     *
     * @return array{
     *     q: string|null,
     *     availability: string|null,
     *     content_flag: string|null,
     *     import_source: string|null,
     *     exception: string|null,
     *     playable_at: int|null,
     *     missing_title: string|null,
     *     curation_status: string|null,
     *     theme_id: int|null,
     *     sort: string,
     *     direction: string,
     * }
     */
    public function filters(): array
    {
        return [
            'q' => $this->search(),
            'availability' => $this->choice('availability', array_column(ContentAvailability::cases(), 'value')),
            'content_flag' => $this->choice('content_flag', array_column(ContentFlag::cases(), 'value')),
            'import_source' => $this->choice('import_source', array_column(ImportSource::cases(), 'value')),
            'exception' => $this->choice('exception', self::EXCEPTIONS),
            'playable_at' => $this->playableAt(),
            'missing_title' => $this->missingTitle()?->value,
            'curation_status' => $this->curationStatus()?->value,
            'theme_id' => $this->themeId(),
            'sort' => $this->sort(),
            'direction' => $this->direction(),
        ];
    }

    /** La recherche libre, vide ramenée à `null`. */
    public function search(): ?string
    {
        $value = trim((string) $this->string('q'));

        return $value === '' ? null : $value;
    }

    /** Le `N` du filtre « jouable à N », hors bornes ramené à `null`. */
    public function playableAt(): ?int
    {
        if (! $this->filled('playable_at')) {
            return null;
        }

        $value = $this->integer('playable_at');

        return $value >= RoomSettingsBounds::MIN_FRAMES_PER_ROUND && $value <= RoomSettingsBounds::MAX_FRAMES_PER_ROUND
            ? $value
            : null;
    }

    /** La locale activée dont le titre manque, hors registre ramenée à `null`. */
    public function missingTitle(): ?Locale
    {
        return Locale::tryFrom(trim((string) $this->string('missing_title')));
    }

    /** L'état de curation filtré, hors liste ramené à `null`. */
    public function curationStatus(): ?CurationStatus
    {
        return CurationStatus::tryFrom(trim((string) $this->string('curation_status')));
    }

    /**
     * Le thème filtré, ou `null`. Un identifiant sans thème ne filtre pas en
     * erreur : il ne rend simplement aucun film.
     */
    public function themeId(): ?int
    {
        if (! $this->filled('theme_id')) {
            return null;
        }

        $value = $this->integer('theme_id');

        return $value >= 1 ? $value : null;
    }

    public function sort(): string
    {
        return $this->choice('sort', self::SORTS) ?? self::DEFAULT_SORT;
    }

    /**
     * Le sens du tri, **narrowé à deux littéraux** et non à `string` : c'est ce
     * qui autorise le contrôleur à le passer à `orderBy()` sans conversion, et
     * ce qui garantit qu'aucune valeur d'origine utilisateur n'atteint jamais
     * un fragment de SQL.
     *
     * @return 'asc'|'desc'
     */
    public function direction(): string
    {
        return $this->choice('direction', self::DIRECTIONS) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Une valeur de liste blanche, ou `null`. Relue ici plutôt que prise dans
     * `validated()` : la prop `filters` doit être typée, et `validated()` rend
     * du `mixed`.
     *
     * @param  list<string>  $allowed
     */
    private function choice(string $key, array $allowed): ?string
    {
        $value = trim((string) $this->string($key));

        return in_array($value, $allowed, true) ? $value : null;
    }
}
