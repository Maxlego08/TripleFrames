<?php

namespace App\Concerns;

use App\Avatars\AvatarPresetCatalog;
use App\Avatars\SeatAvatar;
use App\Models\User;
use App\Rules\ValidNickname;
use App\Support\Identity\NicknameNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Les règles de l'identité d'un siège — pseudo et avatar prédéfini (contrat
 * C5, spec 40 § 5.8). Même modèle que {@see ProfileValidationRules}.
 *
 * Chaque FormRequest de `50` et `60` qui crée un siège (`nickname`) :
 *
 * 1. utilise ce trait ;
 * 2. appelle, dans `prepareForValidation()`,
 *    `$this->merge(['nickname' => $this->prepareNickname($this->input('nickname'))])` ;
 * 3. applique {@see self::nicknameRules()}.
 *
 * Aucune n'accepte d'avatar (D55 du 02/10) : la prise de siège l'attribue.
 * {@see self::seatAvatarRules()} ne sert qu'au changement d'avatar au lobby.
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
     * L'avatar d'un siège au lobby (spec 40 § 11.4, D49 du 01/10, D55 du
     * 02/10) : une clé de {@see AvatarPresetCatalog}, seul registre des clés —
     * jamais un chemin, jamais `preset-%02d` écrit ici —, ou `account`
     * (« Mon avatar ») pour un compte qui porte une image visible — jamais
     * pour un invité ni pour une image masquée.
     *
     * @return array<int, mixed>
     */
    protected function seatAvatarRules(?User $user): array
    {
        $allowed = AvatarPresetCatalog::keys();

        if (SeatAvatar::accountChoiceAvailable($user)) {
            $allowed[] = SeatAvatar::ACCOUNT;
        }

        return ['required', 'string', Rule::in($allowed)];
    }

    /**
     * Le compte connecté de la requête, ou `null` : seul un `User` ouvre le
     * choix « Mon avatar ».
     */
    protected function authenticatedUser(): ?User
    {
        $user = request()->user();

        return $user instanceof User ? $user : null;
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
