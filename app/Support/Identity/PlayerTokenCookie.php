<?php

namespace App\Support\Identity;

use App\Support\I18n\LocaleCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;
use JsonException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Le cookie `player_token` (spec 40 § 3.2), miroir de {@see LocaleCookie} :
 * **seule source de ses attributs, seul lecteur et seul écrivain** du dépôt
 * (`PlayerTokenBoundaryTest`). Son seul appelant est {@see PlayerTokenManager},
 * qui porte le mémo de la requête.
 *
 * | Attribut | Valeur |
 * |---|---|
 * | Nom | `player_token` |
 * | Chiffrement | `EncryptCookies` avec `APP_KEY`, préfixe lié au nom compris ; **jamais** dans `encryptCookies(except: …)` |
 * | `HttpOnly` | vrai : le front n'a aucune raison de lire le jeton |
 * | `SameSite` | `Lax` : un lien de salon ouvert depuis une messagerie est une navigation GET venue d'un autre site |
 * | `Secure` | `App::isProduction()` |
 * | `path`, `domain` | `/`, nul — hôte seul, aucune dépendance à `<DOMAINE>` |
 * | Durée | {@see self::LIFETIME}, 30 jours glissants |
 *
 * **Chiffré, jamais signé à la main** : le MAC d'`EncryptCookies` rend la
 * charge infalsifiable et son préfixe refuse une valeur copiée d'un autre
 * cookie — zéro ligne de cryptographie maison. {@see self::read()} lit donc une
 * valeur déjà déchiffrée : hors d'une pile qui contient `EncryptCookies`, la
 * valeur arrive chiffrée, ne se décode pas, et le jeton est lu comme absent.
 *
 * **Hôte seul, par construction.** Le cookie est bâti directement en
 * `Symfony\Component\HttpFoundation\Cookie`, et non par `Cookie::make()` : le
 * `CookieJar` du framework remplace un domaine nul par `session.domain`, si
 * bien qu'un `SESSION_DOMAIN` posé en production étendrait le jeton à tous les
 * sous-domaines.
 */
final class PlayerTokenCookie
{
    public const string NAME = 'player_token';

    /**
     * 30 jours, en minutes, glissants : chaque prise ou reprise de siège et
     * chaque re-signature repartent de la durée pleine (§ 3.6). Constante de
     * transport publiée par la page de confidentialité (`90`), jamais une
     * valeur de jeu (règle 2).
     */
    public const int LIFETIME = 60 * 24 * 30;

    /** Profondeur de décodage : quatre revendications scalaires, rien d'imbriqué (§ 3.3). */
    private const int JSON_DEPTH = 4;

    public static function make(PlayerToken $token): SymfonyCookie
    {
        return new SymfonyCookie(
            name: self::NAME,
            value: json_encode($token->toClaims(), JSON_THROW_ON_ERROR),
            expire: Date::now()->addMinutes(self::LIFETIME)->getTimestamp(),
            path: '/',
            domain: null,
            secure: App::isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: SymfonyCookie::SAMESITE_LAX,
        );
    }

    /**
     * Met le cookie en file : un second appel dans la même requête remplace le
     * premier (même nom, même chemin), donc un seul `Set-Cookie` part (I4.2).
     */
    public static function queue(PlayerToken $token): void
    {
        Cookie::queue(self::make($token));
    }

    /**
     * Jeton porté par la requête, valeur déjà déchiffrée par `EncryptCookies`.
     *
     * `null`, silencieusement — ni exception ni journal (I4.7) —, pour un
     * cookie absent, un MAC faux, une valeur venue d'un autre cookie ou d'une
     * autre clé (qu'`EncryptCookies` a déjà remplacée par `null`), un JSON
     * illisible, un `v` inconnu, un `tid` hors motif ou une revendication de
     * mauvais type.
     */
    public static function read(Request $request): ?PlayerToken
    {
        $value = $request->cookie(self::NAME);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $claims = json_decode($value, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($claims) ? PlayerToken::fromClaims($claims) : null;
    }
}
