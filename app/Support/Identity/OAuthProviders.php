<?php

namespace App\Support\Identity;

use App\Enums\OAuthProvider;
use Illuminate\Support\Facades\Config;

/**
 * Les fournisseurs de connexion ACTIFS — spec 40 § 12.1, D51 du 01/10.
 *
 * Un fournisseur est actif si et seulement si ses deux clés sont posées dans
 * l'environnement (`services.<p>.client_id` et `client_secret`). Seule
 * lecture de cette règle : routes, boutons et prop partagée passent par ici.
 * Indépendant de `ACCOUNTS_REGISTRATION_OPEN` (D51) : cet interrupteur ne
 * gouverne que l'inscription par mot de passe.
 */
final class OAuthProviders
{
    /**
     * @return list<OAuthProvider>
     */
    public static function enabled(): array
    {
        return array_values(array_filter(
            OAuthProvider::cases(),
            static fn (OAuthProvider $provider): bool => self::isEnabled($provider),
        ));
    }

    public static function isEnabled(OAuthProvider $provider): bool
    {
        $id = Config::get('services.'.$provider->value.'.client_id');
        $secret = Config::get('services.'.$provider->value.'.client_secret');

        return is_string($id) && $id !== '' && is_string($secret) && $secret !== '';
    }

    /**
     * La prop partagée `oauthProviders` : les valeurs des fournisseurs actifs.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (OAuthProvider $provider): string => $provider->value, self::enabled());
    }
}
