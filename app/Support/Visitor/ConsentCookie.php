<?php

namespace App\Support\Visitor;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Le cookie `consent` : le choix du visiteur sur la bannière, et la version
 * de la bannière à laquelle il a répondu (D62 du 06/10, spec 90 § 4.6).
 *
 * Il mémorise un choix, il n'identifie personne : aucun consentement ne lui
 * est nécessaire. **Durées CNIL** : un accord tient 13 mois, un refus 6 mois
 * — la bannière ne réapparaît pas avant. Une version changée de la bannière
 * (finalités, durées) rend tout choix antérieur caduc : la bannière revient.
 *
 * Chiffré (défaut de `EncryptCookies`), `HttpOnly` : seul le serveur le lit,
 * et le client reçoit le choix par la prop partagée `consent`.
 */
final class ConsentCookie
{
    public const string NAME = 'consent';

    /** Version de la bannière : l'incrémenter redemande le choix à tous. */
    public const string VERSION = '1';

    /** Treize mois, en minutes : la durée d'un accord. */
    public const int ACCEPTED_LIFETIME = 60 * 24 * 395;

    /** Six mois, en minutes : la durée d'un refus. */
    public const int REFUSED_LIFETIME = 60 * 24 * 183;

    public static function make(ConsentChoice $choice): SymfonyCookie
    {
        return Cookie::make(
            name: self::NAME,
            value: self::VERSION.':'.$choice->value,
            minutes: $choice === ConsentChoice::Accepted ? self::ACCEPTED_LIFETIME : self::REFUSED_LIFETIME,
            path: '/',
            domain: null,
            secure: App::isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    /** Le choix porté par la requête, s'il répond à la version courante. */
    public static function read(Request $request): ?ConsentChoice
    {
        $value = $request->cookie(self::NAME);

        if (! is_string($value) || ! str_starts_with($value, self::VERSION.':')) {
            return null;
        }

        return ConsentChoice::tryFrom(substr($value, strlen(self::VERSION) + 1));
    }
}
