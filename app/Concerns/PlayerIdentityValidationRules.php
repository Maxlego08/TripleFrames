<?php

namespace App\Concerns;

use App\Avatars\AvatarPresetCatalog;
use App\Rules\ValidNickname;
use App\Support\Identity\NicknameNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Les règles de l'identité d'un siège — pseudo et avatar prédéfini (contrat
 * C5, spec 40 § 5.8). Même modèle que {@see ProfileValidationRules}.
 *
 * Chaque FormRequest de `50` et `60` qui crée un siège (`nickname`, `avatar`) :
 *
 * 1. utilise ce trait ;
 * 2. appelle, dans `prepareForValidation()`,
 *    `$this->merge(['nickname' => $this->prepareNickname($this->input('nickname'))])` ;
 * 3. applique {@see self::nicknameRules()} et {@see self::avatarPresetRules()}.
 *
 * La forme canonique est donc ce que valide la règle **et** ce qu'écrit
 * l'action : aucune seconde normalisation dans un contrôleur. L'exclusion des
 * deux listes quand le jeton tient déjà un siège (reprise, I5.5) vit dans la
 * FormRequest de son propriétaire, jamais ici.
 */
trait PlayerIdentityValidationRules
{
    /**
     * Le pseudo : `:attribute` est résolu depuis `validation.attributes.nickname`.
     *
     * @return array<int, ValidationRule|string>
     */
    protected function nicknameRules(): array
    {
        return ['required', 'string', new ValidNickname];
    }

    /**
     * L'avatar prédéfini : une clé de {@see AvatarPresetCatalog}, seul registre
     * des clés — jamais un chemin, jamais `preset-%02d` écrit ici.
     *
     * @return array<int, mixed>
     */
    protected function avatarPresetRules(): array
    {
        return ['required', 'string', Rule::in(AvatarPresetCatalog::keys())];
    }

    /**
     * La forme canonique d'un pseudo saisi ({@see NicknameNormalizer::canonical()}) ;
     * toute autre valeur passe telle quelle, et la règle `string` la refuse.
     */
    protected function prepareNickname(mixed $raw): mixed
    {
        return is_string($raw) ? NicknameNormalizer::canonical($raw) : $raw;
    }
}
