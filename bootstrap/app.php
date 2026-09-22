<?php

use App\Http\Middleware\ForceAdminLocale;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SelectTranslationDomains;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

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

        $middleware->alias([
            'admin.locale' => ForceAdminLocale::class,
            'translations' => SelectTranslationDomains::class,
        ]);

        // `SetLocale` passe AVANT `HandleInertiaRequests` : les props partagées
        // doivent déjà connaître la locale quand elles sont construites.
        $middleware->web(append: [
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
