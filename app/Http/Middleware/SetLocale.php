<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Models\User;
use App\Support\I18n\LocaleCookie;
use App\Support\I18n\PlayerTokenLocale;
use App\Support\I18n\Translations;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Résolution de la langue du joueur, premier élément trouvé gagnant
 * (spec 05 § Négociation) :
 *
 *  1. `users.locale` — un compte transporte sa langue d'un appareil à l'autre ;
 *  2. cookie `locale` — un choix manuel déjà exprimé sur cet appareil ;
 *  3. revendication `locale` du `player_token` — invité revenu sans cookie ;
 *  4. négociation `Accept-Language` — première visite ;
 *  5. repli d'instance — en-tête absent, illisible ou sans locale activée.
 *
 * Placé **avant** `HandleInertiaRequests` : les props partagées doivent déjà
 * connaître la locale quand elles sont construites.
 *
 * Il appelle `App::setLocale()` et, dans le seul cas de la négociation, repose
 * le cookie — la détection est collante, un francophone ne la rejoue pas à
 * chaque visite. Il ne touche pas à `CarbonImmutable` : c'est le listener sur
 * `LocaleUpdated` qui s'en charge, pour que la bascule s'applique aussi dans
 * un job de mail en file, où aucun middleware HTTP ne tourne.
 *
 * La chaîne elle-même est exposée, **sans effet de bord**, par
 * {@see self::resolve()} (spec 05 § Négociation, 90 § 4.8) : le rendu des pages
 * d'erreur la réemploie, parce que les erreurs les plus fréquentes naissent
 * avant que ce middleware ne s'exécute.
 *
 * **Aucune valeur d'origine utilisateur n'atteint `App::setLocale()` sans
 * passer par `Locale::tryFrom()`** : une locale construit des chemins de
 * fichiers.
 */
class SetLocale
{
    public function __construct(private readonly PlayerTokenLocale $playerToken) {}

    /**
     * Handle an incoming request.
     *
     * Seul lieu des deux effets : la pose de la locale et, après une
     * négociation, celle du cookie qui la rend collante.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        [$locale, $negotiated] = $this->resolution($request);

        if ($negotiated) {
            LocaleCookie::queue($locale);
        }

        App::setLocale($locale->value);

        return $next($request);
    }

    /**
     * La locale de la requête, par la chaîne de résolution de ce middleware,
     * **sans effet de bord** : ni `App::setLocale()`, ni cookie mis en file.
     *
     * Réemployée par le rendu des pages d'erreur (spec 90 § 4.8), où la
     * requête n'a pas forcément traversé le groupe `web` : pour une URL
     * inconnue, ni session ni cookie chiffré ne sont lisibles, et la
     * résolution retombe sur le cookie `locale` (en clair), puis
     * `Accept-Language`, puis la locale de repli. Chaque niveau rend `null`
     * sur une valeur illisible, jamais une exception.
     */
    public function resolve(Request $request): Locale
    {
        return $this->resolution($request)[0];
    }

    /**
     * La chaîne de résolution, et si la locale vient de la négociation — seul
     * cas où `handle()` repose le cookie.
     *
     * @return array{0: Locale, 1: bool}
     */
    private function resolution(Request $request): array
    {
        $stored = $this->fromUser($request)
            ?? LocaleCookie::read($request)
            ?? $this->playerToken->fromRequest($request);

        if ($stored instanceof Locale) {
            return [$stored, false];
        }

        $negotiated = $this->fromAcceptLanguage($request->header('Accept-Language'));

        return $negotiated instanceof Locale
            ? [$negotiated, true]
            : [Translations::fallback(), false];
    }

    /**
     * Niveau 1 — sur une requête qui porte une session, seulement. Sans elle
     * (`clock.show`, `frame.serve`, page d'erreur d'une URL inconnue), le
     * guard retomberait sur le cookie « se souvenir de moi » : lecture de
     * `users`, régénération d'une session (un DELETE sur `sessions`) et
     * `Login` émis à chaque requête, sur des routes en lecture seule (spec 60
     * § 7.3, contrat C8 § 4.5).
     */
    protected function fromUser(Request $request): ?Locale
    {
        if (! $request->hasSession()) {
            return null;
        }

        $user = $request->user();

        return $user instanceof User ? $user->locale : null;
    }

    /**
     * Niveau 4 : négociation restreinte aux locales activées, quality values
     * respectées, sous-étiquettes de région ramenées à la langue — `fr-CA`,
     * `fr-BE` et `fr-CH` valent `fr`.
     *
     * L'en-tête est analysé ici plutôt que par `getPreferredLanguage()` : le
     * repli d'une étiquette régionale vers sa langue de base dépend de la
     * version de Symfony, et cette règle-là est produit.
     */
    protected function fromAcceptLanguage(?string $header): ?Locale
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $chunk) {
            $parts = explode(';', $chunk);
            $tag = strtolower(trim($parts[0]));

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($parts, 1) as $parameter) {
                $parameter = strtolower(trim($parameter));

                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float) substr($parameter, 2);
                }
            }

            if ($quality <= 0.0) {
                continue;
            }

            $candidates[] = ['language' => explode('-', $tag)[0], 'quality' => $quality];
        }

        // Le tri de PHP 8 est stable : à qualité égale, l'ordre de l'en-tête
        // est conservé, ce qui est exactement la règle de RFC 9110.
        usort($candidates, fn (array $a, array $b): int => $b['quality'] <=> $a['quality']);

        foreach ($candidates as $candidate) {
            $locale = Locale::tryFrom($candidate['language']);

            if ($locale instanceof Locale) {
                return $locale;
            }
        }

        return null;
    }
}
