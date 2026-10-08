<?php

namespace App\Concerns;

use App\Models\User;
use App\Rules\ValidNickname;
use App\Support\Identity\NicknameNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, Closure|ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null, ?string $currentName = null): array
    {
        return [
            'name' => $this->nameRules($currentName),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Le nom du compte, pseudo persistant (spec 40 § 13.1, n° 20, D66 du
     * 07/10) : la règle de pseudo de jeu ({@see ValidNickname}, étapes 1 à 5
     * du § 5.4), SANS l'unicité par salon, qui n'appartient qu'à la prise de
     * siège. Inscription, finalisation OAuth, profil et premier admin.
     *
     * Elle ne s'applique qu'à une écriture qui CHANGE le nom : `$currentName`
     * renvoyé tel quel passe, si bien qu'un nom antérieur non conforme n'est
     * jamais refusé tant qu'il n'est pas modifié. La valeur doit avoir été
     * préparée par {@see self::prepareName()}.
     *
     * @return array<int, Closure|ValidationRule|string>
     */
    protected function nameRules(?string $currentName = null): array
    {
        $nickname = new ValidNickname;

        if ($currentName === null) {
            return ['required', 'string', $nickname];
        }

        return [
            'required',
            'string',
            static function (string $attribute, mixed $value, Closure $fail) use ($currentName, $nickname): void {
                if ($value !== $currentName) {
                    $nickname->validate($attribute, $value, $fail);
                }
            },
        ];
    }

    /**
     * La forme canonique d'un nom saisi ({@see NicknameNormalizer::canonical()}),
     * sauf s'il est identique au nom courant, rendu intact ; toute autre
     * valeur passe telle quelle, et la règle `string` la refuse.
     */
    protected function prepareName(mixed $raw, ?string $currentName = null): mixed
    {
        if (! is_string($raw) || ($currentName !== null && $raw === $currentName)) {
            return $raw;
        }

        return NicknameNormalizer::canonical($raw);
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
