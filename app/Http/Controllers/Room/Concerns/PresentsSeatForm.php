<?php

namespace App\Http\Controllers\Room\Concerns;

use App\Avatars\AvatarPresetCatalog;
use App\Avatars\SeatAvatar;
use App\Enums\AvatarKind;
use App\Enums\Locale;
use App\Models\User;
use App\Support\I18n\Translations;
use App\Support\Identity\NicknameNormalizer;
use App\Support\Identity\PlayerToken;
use Illuminate\Support\Facades\App;

/**
 * Ce que partagent les formulaires de siège, `room/create` et `room/join`
 * (spec 50 § 6.2 et § 7.2) et `room/solo` (spec 60 § 16.4, aucun avatar
 * pris : un solo n'a pas de salon) ; 40 § 2.1, étape 2 ; contrat C5 § 3.
 *
 * Réponse au seul demandeur, jamais diffusée : le catalogue des avatars
 * prédéfinis, les avatars déjà pris, la présélection déterministe
 * (`suggest()`, I5.8) et les bornes du pseudo lues sur `NicknameNormalizer`
 * — le front n'écrit jamais `2` ni `20` en dur et n'embarque jamais la liste
 * noire. Aucun pseudo, aucun `public_id` : un visiteur sans siège ne voit pas
 * qui est dans le salon.
 */
trait PresentsSeatForm
{
    /**
     * `{ options, taken, suggested, account }` : la revendication `avatar` du
     * jeton courant, lu sans jamais être frappé, sert de préférence. Un compte
     * qui porte une image visible reçoit en plus `account` (« Mon avatar »,
     * spec 40 § 11.4), présélectionné quand c'est son avatar effectif.
     *
     * @param  list<string>  $taken  Avatars des sièges tenus du salon.
     * @return array{options: list<array{key: string, url: string, labelKey: string}>, taken: list<string>, suggested: string, account: array{url: string}|null}
     */
    private function avatarProps(?PlayerToken $token, array $taken): array
    {
        $user = request()->user();
        $user = $user instanceof User ? $user : null;
        $account = SeatAvatar::accountOption($user);

        return [
            'options' => AvatarPresetCatalog::options(),
            'taken' => $taken,
            'suggested' => $account !== null && $user?->avatar_kind === AvatarKind::Upload
                ? SeatAvatar::ACCOUNT
                : AvatarPresetCatalog::suggest($token?->avatar, $taken),
            'account' => $account,
        ];
    }

    /**
     * Bornes du pseudo affiché : constantes de schéma, jamais des réglages de
     * jeu (C5 § 5).
     *
     * @return array{min: int, max: int}
     */
    private function nicknameProps(): array
    {
        return [
            'min' => NicknameNormalizer::MIN_LENGTH,
            'max' => NicknameNormalizer::MAX_LENGTH,
        ];
    }

    /** La locale effective de la requête, posée par `SetLocale` (ordre de `05`). */
    private function effectiveLocale(): Locale
    {
        return Locale::tryFrom(App::getLocale()) ?? Translations::fallback();
    }
}
