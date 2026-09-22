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
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->fromUser($request)
            ?? LocaleCookie::read($request)
            ?? $this->playerToken->fromRequest($request);

        if (! $locale instanceof Locale) {
            $negotiated = $this->fromAcceptLanguage($request->header('Accept-Language'));

            if ($negotiated instanceof Locale) {
                LocaleCookie::queue($negotiated);
            }

            $locale = $negotiated ?? Translations::fallback();
        }

        App::setLocale($locale->value);

        return $next($request);
    }

    /** Niveau 1. */
    protected function fromUser(Request $request): ?Locale
    {
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
