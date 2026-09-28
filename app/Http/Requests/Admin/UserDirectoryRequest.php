<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le contrat de la query string de l'annuaire des comptes — recherche,
 * filtres, tri et pagination (spec 20 § 2.8).
 *
 * Il est le propriétaire unique de ses listes blanches (`state`, `sort`,
 * `direction`) : le contrôleur les relit pour composer la prop `options`, de
 * sorte qu'un choix offert à l'écran soit, par construction, un choix
 * accepté. Le rôle est validé par `Rule::enum`, jamais par une liste recopiée.
 *
 * **Aucun message n'est écrit ici** : `attributes()` nomme chaque champ par une
 * clé `admin.validation.*` (règle 4).
 */
class UserDirectoryRequest extends FormRequest
{
    /**
     * Les deux états d'un compte : actif, ou pierre tombale d'une
     * anonymisation (`users.anonymized_at`, spec 10 § 5.5). Aucun filtre :
     * tous les comptes.
     *
     * @var list<string>
     */
    public const array STATES = ['active', 'anonymized'];

    /**
     * Les quatre tris offerts. Seul `last_login_at` croise un index — en
     * seconde colonne de `users_role_last_login_at_index`, donc sans en
     * profiter hors filtre de rôle — : un filesort sur une table de comptes,
     * assumé à l'échelle du projet.
     *
     * @var list<string>
     */
    public const array SORTS = ['created_at', 'last_login_at', 'name', 'email'];

    /** @var list<string> */
    public const array DIRECTIONS = ['asc', 'desc'];

    /** Une page d'annuaire. */
    public const int PER_PAGE = 25;

    public const string DEFAULT_SORT = 'created_at';

    public const string DEFAULT_DIRECTION = 'desc';

    /** La longueur d'une recherche libre, comme celle du catalogue. */
    public const int SEARCH_MAX_LENGTH = 120;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:'.self::SEARCH_MAX_LENGTH],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'state' => ['nullable', Rule::in(self::STATES)],
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
            'role' => __('admin.validation.role'),
            'state' => __('admin.validation.account_state'),
            'sort' => __('admin.validation.sort'),
            'direction' => __('admin.validation.direction'),
        ];
    }

    /**
     * Les filtres tels que l'écran doit les réafficher — miroir exact de la
     * query string, `sort` et `direction` toujours posés.
     *
     * @return array{q: string|null, role: string|null, state: string|null, sort: string, direction: string}
     */
    public function filters(): array
    {
        return [
            'q' => $this->search(),
            'role' => $this->role()?->value,
            'state' => $this->state(),
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

    /** Le rôle filtré, hors liste ramené à `null`. */
    public function role(): ?UserRole
    {
        return UserRole::tryFrom(trim((string) $this->string('role')));
    }

    /** L'état filtré, hors liste ramené à `null`. */
    public function state(): ?string
    {
        return $this->choice('state', self::STATES);
    }

    public function sort(): string
    {
        return $this->choice('sort', self::SORTS) ?? self::DEFAULT_SORT;
    }

    /**
     * Le sens du tri, narrowé à deux littéraux : aucune valeur d'origine
     * utilisateur n'atteint jamais un fragment de SQL.
     *
     * @return 'asc'|'desc'
     */
    public function direction(): string
    {
        $direction = $this->choice('direction', self::DIRECTIONS) ?? self::DEFAULT_DIRECTION;

        return $direction === 'asc' ? 'asc' : 'desc';
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
