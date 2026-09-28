<?php

namespace App\Http\Requests\Admin;

use App\Actions\Admin\ChangeUserRole;
use App\Concerns\RealNameValidationRules;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attribuer ou retirer un rôle — spec 20 § 2.8.
 *
 * Trois champs :
 *
 * - `role`, le rôle visé, dans la liste de {@see UserRole} ;
 * - `real_name`, saisi par l'administrateur quand la cible n'en porte pas
 *   encore et qu'un rôle privilégié lui est attribué (D12 du 23/09). Il est
 *   **écarté** de la validation dans tous les autres cas : un nom réel déjà
 *   porté ne se réécrit jamais par ce geste — le corriger est un autre geste,
 *   journalisé à part —, et un joueur n'en a pas l'usage. Son caractère
 *   REQUIS n'est pas jugé ici mais sous verrou, par l'action, qui seule
 *   connaît l'état de la cible à l'instant de l'écriture ;
 * - `reason`, le motif, **facultatif** (`role.changed`, contrat C14), borné
 *   par la colonne `admin_action.reason`.
 */
class RoleUpdateRequest extends FormRequest
{
    use RealNameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string|Closure>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(UserRole::class)],
            'real_name' => $this->acceptsRealName() ? $this->optionalRealNameRules() : ['exclude'],
            'reason' => ['nullable', 'string', 'max:'.AdminAction::REASON_MAX_LENGTH],
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
            'role' => __('admin.validation.role'),
            'real_name' => __('admin.validation.real_name_field'),
            'reason' => __('admin.validation.reason'),
        ];
    }

    /** Le rôle visé — valide une fois la validation passée. */
    public function role(): UserRole
    {
        return UserRole::from((string) $this->string('role'));
    }

    /**
     * Le nom réel saisi, rogné ; `null` s'il est vide ou écarté de la
     * validation.
     */
    public function realName(): ?string
    {
        if (! $this->acceptsRealName()) {
            return null;
        }

        $realName = trim((string) $this->string('real_name'));

        return $realName === '' ? null : $realName;
    }

    /** Le motif saisi, rogné, ou `null` s'il est vide. */
    public function reason(): ?string
    {
        $reason = trim((string) $this->string('reason'));

        return $reason === '' ? null : $reason;
    }

    /**
     * Le nom réel n'est lu que pour attribuer un rôle privilégié à un compte
     * qui n'en porte pas encore.
     */
    private function acceptsRealName(): bool
    {
        $target = $this->route('user');
        $role = UserRole::tryFrom((string) $this->string('role'));

        return $target instanceof User
            && $role !== null
            && $role->atLeast(UserRole::Curator)
            && ChangeUserRole::lacksRealName($target);
    }

    /**
     * Les règles du nom réel, sans « requis » : l'absence se juge sous verrou.
     *
     * @return array<int, ValidationRule|array<mixed>|string|Closure>
     */
    private function optionalRealNameRules(): array
    {
        return [
            'nullable',
            ...array_values(array_filter(
                $this->realNameRules(),
                static fn (mixed $rule): bool => $rule !== 'required',
            )),
        ];
    }
}
