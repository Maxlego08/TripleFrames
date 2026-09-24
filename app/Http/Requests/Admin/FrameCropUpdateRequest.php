<?php

namespace App\Http\Requests\Admin;

use App\Concerns\FrameCropValidationRules;
use App\Models\AdminAction;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Re-recadrer une image, en place — contrat C9, spec 20 § 5.7.
 *
 * **Sans fichier** : le serveur redérive le rendu du master déjà normalisé et
 * du nouveau rectangle. Les champs sont ceux du cadre de l'ajout
 * ({@see FrameCropValidationRules} : forme, 16:9 exact, bornes de largeur),
 * plus un motif facultatif — pré-rempli à l'écran par
 * `admin.frame.recrop.default_reason` —, qui n'est écrit au journal que si
 * l'image était publiée et sort du jeu.
 *
 * Ce que cette requête ne vérifie PAS : la borne de HAUTEUR du plancher et le
 * débordement, qui dépendent de la hauteur du master (contrôleur, puis job),
 * ni l'état de l'image (occupée, sans rendu, suspendue ou retirée), qui se
 * refuse par une erreur traduite de l'action, relue sous verrou.
 */
class FrameCropUpdateRequest extends FormRequest
{
    use FrameCropValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    public function rules(): array
    {
        return [
            ...$this->cropRules(),
            'reason' => ['nullable', 'string', 'max:'.AdminAction::REASON_MAX_LENGTH],
        ];
    }

    /**
     * Le ratio exact du cadre, une fois ses champs valides.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->cropAspectCheck()];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->cropMessages();
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...$this->cropAttributes(),
            'reason' => __('admin.validation.reason'),
        ];
    }

    /** Le motif saisi, rogné, ou `null` s'il est vide. */
    public function reason(): ?string
    {
        $reason = trim((string) $this->string('reason'));

        return $reason === '' ? null : $reason;
    }
}
