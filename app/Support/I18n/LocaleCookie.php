<?php

namespace App\Support\I18n;

use App\Enums\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Le cookie `locale` : un an, `path=/`, `SameSite=Lax`, `Secure` en
 * production, **non chiffré** (exception déclarée dans `bootstrap/app.php`).
 *
 * Source unique de ses attributs : le middleware qui le repose après
 * négociation et l'action de changement de langue doivent écrire exactement le
 * même cookie, sinon la préférence se dédouble entre deux chemins.
 *
 * Il n'est jamais une source d'autorité : sa valeur passe par l'enum avant
 * usage, et `users.locale` le supplante toujours.
 */
final class LocaleCookie
{
    public const string NAME = 'locale';

    /** Un an, en minutes. */
    public const int LIFETIME = 60 * 24 * 365;

    public static function make(Locale $locale): SymfonyCookie
    {
        return Cookie::make(
            name: self::NAME,
            value: $locale->value,
            minutes: self::LIFETIME,
            path: '/',
            domain: null,
            secure: App::isProduction(),
            httpOnly: false,
            raw: false,
            sameSite: 'lax',
        );
    }

    public static function queue(Locale $locale): void
    {
        Cookie::queue(self::make($locale));
    }

    /** Locale portée par le cookie de la requête, validée par l'enum. */
    public static function read(Request $request): ?Locale
    {
        $cookie = $request->cookie(self::NAME);

        return is_string($cookie) ? Locale::tryFrom($cookie) : null;
    }
}
