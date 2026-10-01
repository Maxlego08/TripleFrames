<?php

namespace App\Http\Requests\Admin;

use App\Enums\GameMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le contrat de la query string de la liste des parties — onglet, mode, code
 * de salon et pagination (spec 20 § 12.2, D46 du 01/10).
 *
 * Propriétaire unique de ses listes blanches : le contrôleur les relit pour
 * composer la prop `options`. Le mode est validé par `Rule::enum`.
 */
class GameInspectionRequest extends FormRequest
{
    /**
     * Les deux onglets : en cours (`ended_at` nul) et terminées.
     *
     * @var list<string>
     */
    public const array STATES = ['running', 'ended'];

    public const string DEFAULT_STATE = 'running';

    /** Une page de la liste. */
    public const int PER_PAGE = 25;

    /** Un code de salon : `room.room_code` est un `char(6)`. */
    public const int ROOM_CODE_LENGTH = 6;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'state' => ['nullable', Rule::in(self::STATES)],
            'mode' => ['nullable', Rule::enum(GameMode::class)],
            'room' => ['nullable', 'string', 'max:'.self::ROOM_CODE_LENGTH],
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
            'state' => __('admin.validation.game_state'),
            'mode' => __('admin.validation.game_mode'),
            'room' => __('admin.validation.room_code'),
        ];
    }

    /**
     * Les filtres tels que l'écran doit les réafficher.
     *
     * @return array{state: string, mode: string|null, room: string|null}
     */
    public function filters(): array
    {
        return [
            'state' => $this->state(),
            'mode' => $this->mode()?->value,
            'room' => $this->roomCode(),
        ];
    }

    public function state(): string
    {
        $value = trim((string) $this->string('state'));

        return in_array($value, self::STATES, true) ? $value : self::DEFAULT_STATE;
    }

    public function mode(): ?GameMode
    {
        return GameMode::tryFrom(trim((string) $this->string('mode')));
    }

    /** Le code de salon, en capitales, vide ramené à `null`. */
    public function roomCode(): ?string
    {
        $value = mb_strtoupper(trim((string) $this->string('room')));

        return $value === '' ? null : $value;
    }
}
