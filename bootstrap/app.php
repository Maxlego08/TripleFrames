<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ForceAdminLocale;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SelectTranslationDomains;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VaryOnLanguage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // `locale` rejoint `appearance` et `sidebar_state` pour la même raison :
        // c'est une préférence publique, non sensible, que le front lit et écrit
        // directement pour appliquer la langue sans attendre un aller-retour. Un
        // cookie chiffré serait illisible côté client. Il n'est jamais une source
        // d'autorité : `SetLocale` le valide par `Locale::tryFrom()` et
        // `users.locale` le supplante toujours.
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale']);

        // Aucun alias de forçage d'apparence pour le back-office : il suit la
        // préférence du visiteur (D8 du 23/09, spec 90 § 2.2). Le seul forçage
        // prévu est celui des pages `game/*`, en sombre.
        $middleware->alias([
            'admin.locale' => ForceAdminLocale::class,
            'role' => EnsureUserHasRole::class,
            'translations' => SelectTranslationDomains::class,
        ]);

        // `role:curator` garde la PORTE du back-office ; les `can:` posés route
        // par route gardent la RESSOURCE. Ce rang n'est pas un raffinement :
        // sans lui, `role` s'exécuterait APRÈS `SubstituteBindings`, et
        // `/admin/catalog/{movie}` répondrait 404 à un joueur sur un
        // identifiant inconnu contre 403 sur un identifiant réel. Le seuil de
        // rôle doit tomber AVANT que la moindre ligne ne soit cherchée, sans
        // quoi le simple couple de codes de statut énumère la table `movie`.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserHasRole::class);

        // `SetLocale` passe AVANT `HandleInertiaRequests` : les props partagées
        // doivent déjà connaître la locale quand elles sont construites.
        //
        // `VaryOnLanguage` est en TÊTE du groupe, donc le DERNIER à toucher la
        // réponse : `Inertia\Middleware` pose `Vary: X-Inertia` en écrasant
        // l'en-tête, et un `Vary` posé plus bas dans l'oignon serait
        // silencieusement effacé. Il n'agit que sur les routes qui le demandent
        // par leur défaut `vary_language`.
        $middleware->web(prepend: [
            VaryOnLanguage::class,
        ], append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
