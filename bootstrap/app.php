<?php

use App\Http\Middleware\CaptureReceptionInstant;
use App\Http\Middleware\EnforceAccountSwitches;
use App\Http\Middleware\EnsurePrivilegedTwoFactor;
use App\Http\Middleware\EnsureProbeToken;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ForceAdminLocale;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RobotsDirectives;
use App\Http\Middleware\SelectTranslationDomains;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VaryOnLanguage;
use App\Support\Http\ErrorPageResponder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Sondes d'exploitation (spec 100 § 15), HORS du groupe `web` : sans
        // session, sans cookie, sans jeton CSRF. Le limiteur passe AVANT le
        // jeton, pour que les essais de jeton soient bornés par adresse.
        then: static function (): void {
            Route::middleware(['throttle:ops-probe', EnsureProbeToken::class])
                ->group(__DIR__.'/../routes/ops.php');
        },
    )
    // Canaux de diffusion du moteur (spec 60 § 10.4, contrat C7 § 2.2) :
    // `/broadcasting/auth`, chemin fixe du framework, sous `web` — donc
    // `EncryptCookies`, sans quoi la garde `player` lirait le jeton absent —
    // et `throttle:game-read`, compté par jeton. Posé ici et JAMAIS par
    // `install:broadcasting`, qui ajouterait `channels:` à `withRouting()`,
    // donc un second `/broadcasting/auth` sans ce middleware (lot L60-1).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'throttle:game-read']])
    // Chaque écouteur est enregistré EXPLICITEMENT dans `AppServiceProvider`.
    // La découverte de `app/Listeners`, active par défaut, les enregistrerait
    // une seconde fois : `/up` interrogerait alors deux fois la base et Redis.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // `X-Robots-Tag` et `Referrer-Policy` sur TOUTE réponse (spec 90 § 5.2).
        // Global et non du groupe `web` : une URL inconnue lève avant tout
        // middleware de route, et une 404 doit porter `noindex` comme le reste.
        // En TÊTE de la pile globale, donc le dernier à toucher la réponse :
        // placé en queue, il ne verrait ni la 503 du mode maintenance, ni la 400
        // d'un chemin mal encodé, ni la 413 d'un corps trop lourd, que les
        // middlewares globaux du framework rendent avant lui.
        $middleware->prepend(RobotsDirectives::class);

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
        //
        // `accounts.switches` ferme l'inscription et les passkeys hors `local`
        // et `testing` (spec 40 § 8.2) : posé sur le groupe de Fortify par
        // `config/fortify.php` et sur `well-known.passkeys`.
        //
        // `admin.2fa` ferme la porte `/admin` à tout compte privilégié dont le
        // second facteur n'est pas confirmé, et le renvoie vers l'écran
        // d'enrôlement (spec 20 § 2.4, `10` A16).
        $middleware->alias([
            'accounts.switches' => EnforceAccountSwitches::class,
            'admin.2fa' => EnsurePrivilegedTwoFactor::class,
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

        // `admin.2fa` rejoint `role` en tête de la liste, juste DERRIÈRE lui
        // et AVANT `SubstituteBindings`, pour la même raison d'énumération
        // (spec 20 § 2.3) : sans ce rang, un curateur sans second facteur
        // recevrait 404 sur un identifiant de film inconnu et une redirection
        // d'enrôlement sur un identifiant réel. Derrière `role` : un joueur
        // reçoit 403 avant toute redirection, qui confirmerait l'existence de
        // la porte.
        //
        // Un second `prependToPriorityList` sur `SubstituteBindings`, et non un
        // `appendToPriorityList` sur `role` : le framework applique les ajouts
        // « après » AVANT les ajouts « avant », si bien que `role` ne serait pas
        // encore dans la liste et `admin.2fa` tomberait en queue, derrière la
        // substitution. Les ajouts « avant » s'appliquent dans l'ordre de
        // déclaration : `role`, puis `admin.2fa`, puis `SubstituteBindings`.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsurePrivilegedTwoFactor::class);

        // `SetLocale` passe AVANT `HandleInertiaRequests` : les props partagées
        // doivent déjà connaître la locale quand elles sont construites.
        //
        // `CaptureReceptionInstant` ouvre le groupe (spec 60 § 2.2, contrat
        // C10 L3) : l'instant de réception est capturé AVANT les cookies, la
        // session, la langue et tout middleware de jeu (`throttle:*`,
        // `seat.active`), si bien qu'aucune attente de verrou ou de cache ne le
        // décale. Aucun middleware de la liste de priorité ne le déplace : le
        // tri du routeur ne réordonne que les middlewares qui y figurent.
        //
        // `VaryOnLanguage` le suit, et reste le DERNIER à toucher la réponse
        // (le premier ne la modifie pas) : `Inertia\Middleware` pose
        // `Vary: X-Inertia` en écrasant l'en-tête, et un `Vary` posé plus bas
        // dans l'oignon serait silencieusement effacé. Il n'agit que sur les
        // routes qui le demandent par leur défaut `vary_language`.
        $middleware->web(prepend: [
            CaptureReceptionInstant::class,
            VaryOnLanguage::class,
        ], append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Une seule règle « attend du JSON », partagée avec le rendu des pages
        // d'erreur ci-dessous : une requête qui l'attend reçoit toujours du
        // JSON, jamais la page `error` (spec 90 § 4.8).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ErrorPageResponder::expectsJson($request),
        );

        // Pages d'erreur traduites, HORS mode debug (spec 90 § 4.8) : `error`
        // côté joueur, `admin/error` quand le back-office avait sélectionné son
        // domaine, retour arrière avec message sur une page expirée en visite
        // Inertia. La locale, le domaine `legal` et l'apparence du visiteur y
        // sont résolus par le gestionnaire lui-même : la plupart des erreurs
        // naissent avant `SetLocale` et `HandleInertiaRequests`.
        Inertia::handleExceptionsUsing(
            fn (ExceptionResponse $response) => app(ErrorPageResponder::class)($response),
        );
    })->create();
