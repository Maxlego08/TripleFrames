<?php

namespace App\Support\I18n;

use App\Enums\Locale;
use Illuminate\Http\Request;

/**
 * Implémentation par défaut du niveau 3 : aucune revendication, donc aucune
 * locale. La résolution passe alors directement du cookie à la négociation
 * `Accept-Language`.
 *
 * Elle existe pour que le middleware dépende d'un contrat et jamais d'un
 * `class_exists()` ou d'un `if` temporaire : le jour où le `player_token` est
 * frappé, seule la liaison du conteneur change.
 */
final class NullPlayerTokenLocale implements PlayerTokenLocale
{
    public function fromRequest(Request $request): ?Locale
    {
        return null;
    }
}
