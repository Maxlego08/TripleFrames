<?php

namespace App\Http\Requests\Locale;

use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class LocaleUpdateRequest extends FormRequest
{
    /**
     * Le changement de langue est ouvert aux invités : le jeu passe avant le
     * compte, et un joueur qui ne lit pas l'interface doit pouvoir en sortir
     * sans créer de compte.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
        ];
    }

    /** Locale demandée, déjà validée par l'enum — jamais une chaîne libre. */
    public function locale(): Locale
    {
        $value = $this->validated('locale');

        return (is_string($value) ? Locale::tryFrom($value) : null)
            ?? throw new LogicException('La règle `enum` aurait dû rejeter cette valeur de locale.');
    }
}
