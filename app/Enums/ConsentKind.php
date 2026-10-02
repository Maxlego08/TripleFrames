<?php

namespace App\Enums;

/** Nature d'un consentement daté et conservé en ajout seul : cast de `user_consent.kind`. */
enum ConsentKind: string
{
    case Terms = 'terms';

    case Age = 'age';

    /** Usage des données du compte Discord, daté à chaque liaison (D51 du 01/10, spec 40 § 12). */
    case ProviderDiscord = 'provider_discord';

    /** Usage des données du compte Google, daté à chaque liaison (D51 du 01/10, spec 40 § 12). */
    case ProviderGoogle = 'provider_google';

    /** Le consentement de liaison d'un fournisseur. */
    public static function forProvider(OAuthProvider $provider): self
    {
        return match ($provider) {
            OAuthProvider::Discord => self::ProviderDiscord,
            OAuthProvider::Google => self::ProviderGoogle,
        };
    }
}
