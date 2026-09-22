<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Support\I18n\TranslationDomains;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le back-office est en français **uniquement** (décision 9), et la règle est
 * appliquée, pas espérée : ce middleware force `Locale::French` sur tout le
 * groupe de routes d'administration, quelle que soit la préférence du
 * curateur. Sans lui, un curateur dont le compte est en `en` verrait
 * s'afficher des clés brutes, `lang/en/admin.php` n'existant pas.
 *
 * Il sélectionne dans le même geste le domaine `admin`, ce qui garantit que ce
 * domaine n'est jamais expédié à un écran joueur — ni l'inverse.
 *
 * Il ne concerne que le **rendu des écrans** d'administration. Un message
 * sortant vers un tiers extérieur (accusé de réception d'une demande de
 * retrait, notification de décision, réponse à un signalement) part dans la
 * locale stockée avec la demande, jamais dans celle de la requête du curateur.
 *
 * À poser sur `routes/admin.php` — fichier à créer — par l'alias
 * `admin.locale`.
 */
class ForceAdminLocale
{
    public function __construct(private readonly TranslationDomains $domains) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(Locale::French->value);

        $this->domains->need(TranslationDomains::ADMIN);

        return $next($request);
    }
}
