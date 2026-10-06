<?php

namespace App\Http\Controllers\Room\Concerns;

use App\Enums\Locale;
use App\Support\I18n\Translations;
use App\Support\Identity\NicknameNormalizer;
use App\Support\Room\LobbyAvatars;
use Illuminate\Support\Facades\App;

/**
 * Ce que partagent les formulaires de siège, `room/create` et `room/join`
 * (spec 50 § 6.2 et § 7.2) et `room/solo` (spec 60 § 16.4) ; 40 § 2.1,
 * étape 2 ; contrat C5 § 3.
 *
 * Réponse au seul demandeur, jamais diffusée : les bornes du pseudo lues sur
 * `NicknameNormalizer` — le front n'écrit jamais `2` ni `20` en dur et
 * n'embarque jamais la liste noire. **Aucun avatar** (D55 du 02/10) : la
 * prise de siège l'attribue, il se change au lobby ({@see LobbyAvatars}).
 * Aucun pseudo, aucun `public_id` : un visiteur sans siège ne voit pas qui
 * est dans le salon.
 */
trait PresentsSeatForm
{
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
