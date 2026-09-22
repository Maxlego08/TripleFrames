<?php

namespace App\Http\Middleware;

use App\Support\I18n\TranslationDomains;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Déclare, sur un groupe de routes, les domaines de traduction dont ses pages
 * ont besoin : `->middleware('translations:account')`,
 * `->middleware('translations:game,room')`.
 *
 * Un middleware de route plutôt qu'un appel de contrôleur parce que trois des
 * pages existantes n'ont pas de contrôleur (`Route::inertia`) et que Fortify
 * enregistre ses routes lui-même — il reçoit son domaine par
 * `config('fortify.middleware')`.
 *
 * `common` est toujours joint : il n'a pas à être déclaré.
 */
class SelectTranslationDomains
{
    public function __construct(private readonly TranslationDomains $domains) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$domains): Response
    {
        $this->domains->need(...$domains);

        return $next($request);
    }
}
