<?php

namespace App\Providers;

use App\Actions\Account\DeletePasskeyUnlessLastMethod;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Enums\OAuthProvider;
use App\Http\Middleware\EnforceAccountSwitches;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Settings\EngineConstants;
use App\Settings\RoomSettingsBounds;
use App\Support\ContentReport\ContentReportRateLimits;
use App\Support\Identity\AccountSwitches;
use App\Support\Identity\OAuthProviders;
use App\Support\Identity\PlayerTokenManager;
use App\Support\Room\RoomRateLimits;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkeys;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class FortifyServiceProvider extends ServiceProvider
{
    /** Espace des clés de limiteur de jeu comptées par jeton. */
    private const string SEAT_THROTTLE_TOKEN_PREFIX = 'token:';

    /** Espace des clés de limiteur de jeu comptées par adresse, faute de jeton. */
    private const string SEAT_THROTTLE_IP_PREFIX = 'ip:';

    /**
     * Envois d'inscription par adresse et par heure (spec 40 § 13.1) : une
     * garde anti-automate, jamais une valeur de jeu.
     */
    public const int REGISTRATIONS_PER_HOUR = 20;

    /** Espace des clés du limiteur `content-report` comptées par compte connecté. */
    private const string ACCOUNT_THROTTLE_PREFIX = 'user:';

    /**
     * Les deux budgets du limiteur `answer` par siège (spec 70 § 8) : la
     * route de soumission, par son nom, et le budget qu'elle consomme. Liste
     * close : le texte libre et le clic du QCM.
     */
    private const array ANSWER_BUDGETS = [
        'round.answer.store' => 'text',
        'round.choice.store' => 'choice',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Supprimer une passkey refuse la dernière méthode de connexion (spec
        // 40 § 13.8) : l'action du paquet est remplacée, sa route gardée.
        $this->app->bind(DeletePasskey::class, DeletePasskeyUnlessLastMethod::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configurePasskeyLogin();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * La connexion par passkey (spec 40 § 13.8, n° 10 option A).
     *
     * Une passkey à vérification de l'utilisateur vaut second facteur À LA
     * CONNEXION : le paquet ouvre la session sans défi TOTP. Elle ne dispense
     * jamais d'enrôler le TOTP (`admin.2fa` ne lit que
     * `two_factor_confirmed_at`), et la ré-acceptation des CGU s'applique
     * comme à toute connexion : la réponse mène à `fortify.home`, qui porte
     * `terms.current` (§ 13.1). Une pierre tombale (`anonymized_at` posé) ne
     * se connecte jamais, même si une passkey avait survécu à
     * l'anonymisation (§ 13.6).
     */
    private function configurePasskeyLogin(): void
    {
        Passkeys::authorizeLoginUsing(
            static fn (Request $request, mixed $user): bool => ! ($user instanceof User && $user->anonymized_at !== null),
        );
    }

    /**
     * Configure Fortify views.
     *
     * `canRegister` et `canUsePasskeys` suivent les interrupteurs de compte
     * (spec 40 § 8.2) : un lien d'inscription ou un bouton de passkey affiché
     * sur une route fermée mènerait à un 404.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => $this->canRegister(),
            'canUsePasskeys' => $this->canUsePasskeys(),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        // Un compte sans mot de passe confirme par un fournisseur lié (spec 40
        // § 12.4, D51 du 01/10).
        Fortify::confirmPasswordView(fn (Request $request) => Inertia::render('auth/confirm-password', [
            'canUsePasskeys' => $this->canUsePasskeys(),
            'hasPassword' => $request->user()?->password !== null,
            'confirmProviders' => $this->linkedProviders($request),
        ]));
    }

    /**
     * Les fournisseurs ACTIFS liés au compte connecté, par lesquels il peut
     * confirmer son identité.
     *
     * @return list<string>
     */
    private function linkedProviders(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        $linked = LinkedAccount::query()
            ->where('user_id', $user->id)
            ->pluck('provider')
            ->map(static fn (mixed $provider): string => $provider instanceof OAuthProvider ? $provider->value : (string) $provider)
            ->all();

        return array_values(array_intersect(OAuthProviders::values(), $linked));
    }

    /** Fonctionnalité Fortify activée ET inscription ouverte. */
    private function canRegister(): bool
    {
        return Features::enabled(Features::registration()) && AccountSwitches::registrationOpen();
    }

    /** Fonctionnalité Fortify activée ET passkeys ouvertes. */
    private function canUsePasskeys(): bool
    {
        return Features::canManagePasskeys() && AccountSwitches::passkeysEnabled();
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Le téléversement d'un avatar de compte (spec 40 § 11.2) : il décode
        // une image dans la requête, d'où un plafond par compte.
        // L'aller-retour chez un fournisseur (spec 40 § 12.1) : par adresse,
        // un invité n'ayant pas encore de compte.
        RateLimiter::for('oauth', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // L'inscription par mot de passe (spec 40 § 13.1, L40-10) : par
        // adresse, un visiteur n'ayant pas encore de compte. Fortify n'a pas de
        // clé de limiteur d'inscription : `accounts.switches` l'applique à
        // `register.store`, sans désenregistrer la route (§ 8.2). Il compte
        // aussi les envois refusés, d'où un seau large pour une saisie ratée.
        RateLimiter::for(EnforceAccountSwitches::REGISTER_LIMITER, function (Request $request) {
            return Limit::perHour(self::REGISTRATIONS_PER_HOUR)->by((string) $request->ip());
        });

        RateLimiter::for('avatar-upload', function (Request $request) {
            return Limit::perHour(10)->by((string) $request->user()?->getAuthIdentifier());
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });

        // Les écritures du back-office d'import — balayage, collage, reprise,
        // liste d'amorçage, et l'aperçu à blanc d'un collage. Le limiteur
        // n'est pas là contre un attaquant — le groupe est déjà derrière
        // `auth`, `verified` et `role:curator` — mais contre le double-clic et
        // le rechargement nerveux : chaque envoi dispatche un job qui consomme
        // un quota TMDB partagé, et tous sauf l'aperçu ouvrent une ligne
        // `import_run`. Par UTILISATEUR, et non par IP : deux curateurs
        // derrière le même NAT associatif ne se bloquent jamais l'un l'autre.
        RateLimiter::for('admin-import', function (Request $request) {
            return Limit::perMinute(12)->by((string) $request->user()?->getAuthIdentifier());
        });

        // Les gestes d'image du back-office (spec 20 § 5.3, § 5.7 et § 13.7,
        // C9 § 2) : ajout d'une variante, re-recadrage et relance. Chacun
        // télécharge un original TMDB ou distribue un job Imagick ; le
        // limiteur borne le double clic et la rafale, jamais le débit d'un
        // curateur. Par UTILISATEUR, comme `admin-import`, et sa valeur vit
        // dans `catalog.curation.rate_limits.frame`, jamais en littéral.
        RateLimiter::for('admin-frame', function (Request $request) {
            return Limit::perMinute(Config::integer('catalog.curation.rate_limits.frame'))
                ->by((string) $request->user()?->getAuthIdentifier());
        });

        // Les gestes de curation qui n'écrivent qu'en base (spec 20 § 13.7) :
        // changer le niveau d'une image, la dépublier ou l'écarter, et, aux
        // lots suivants, revoir une image, publier ou dépublier un film. Plus
        // large qu'`admin-frame` — aucun ne télécharge ni ne distribue de job
        // Imagick —, au-dessus du débit de « Entrée = conforme, publier ». Il
        // borne aussi les deux gestes d'accès, qui n'écrivent eux aussi qu'en
        // base : changer un rôle, corriger un nom réel (§ 2.8). Par
        // utilisateur ; valeur dans `catalog.curation.rate_limits.curation`.
        RateLimiter::for('admin-curation', function (Request $request) {
            return Limit::perMinute(Config::integer('catalog.curation.rate_limits.curation'))
                ->by((string) $request->user()?->getAuthIdentifier());
        });

        // La recherche TMDB du back-office (spec 20 § 3.4) : SON limiteur, par
        // utilisateur, distinct d'`admin-import`. Une recherche n'ouvre aucun
        // balayage et ne doit pas consommer le quota des vrais imports ; un
        // import, réciproquement, ne doit jamais bloquer une recherche. Valeur
        // dans `catalog.curation.rate_limits.search`, jamais en littéral.
        // `admin.import.index`, que sonde l'aperçu d'un collage, n'en porte
        // aucun.
        RateLimiter::for('admin-tmdb-search', function (Request $request) {
            return Limit::perMinute(Config::integer('catalog.curation.rate_limits.search'))
                ->by((string) $request->user()?->getAuthIdentifier());
        });

        // Le battement de débit (spec 20 § 10.1, § 13.7) : un POST toutes les
        // `catalog.curation.heartbeat_seconds` secondes depuis l'éditeur, la
        // fiche ou la revue d'un film. Par UTILISATEUR, et au moins deux
        // onglets à cette cadence (`2 × ⌈60 ÷ heartbeat_seconds⌉`, garde de
        // `CurationConfigTest`) : un second onglet ouvert sur la même page ne
        // reçoit jamais de 429. Valeur dans
        // `catalog.curation.rate_limits.heartbeat`, jamais en littéral.
        RateLimiter::for('admin-heartbeat', function (Request $request) {
            return Limit::perMinute(Config::integer('catalog.curation.rate_limits.heartbeat'))
                ->by((string) $request->user()?->getAuthIdentifier());
        });

        // Les sondes d'exploitation (spec 100 § 15), AVANT la vérification du
        // jeton : les essais de jeton sont bornés par adresse. La supervision
        // interroge cinq sondes, dont la plus fréquente chaque minute ; trente
        // requêtes par minute et par adresse lui laissent une large marge, y
        // compris depuis plusieurs points de mesure derrière une même sortie.
        RateLimiter::for('ops-probe', function (Request $request) {
            return Limit::perMinute(30)->by((string) $request->ip());
        });

        $this->configureGameRateLimiting();
    }

    /**
     * Les trois limiteurs du moteur de partie (spec 60 § 10.3, contrat C7
     * § 2.4, C8) : `game-read` sur les lectures (resynchronisation, horloge,
     * pages de jeu), `game-write` sur les écritures (battements, gestes),
     * `frame-serve` sur les octets d'image. `answer` appartient à 70.
     *
     * Débits lus dans `EngineConstants` (§ 19.1), jamais en littéral ; ils
     * couvrent le sondage du solo, les battements et deux chargements
     * d'image par palier au pire cas des bornes (`EngineConstantsTest`).
     *
     * Posés dès que la première route en porte un (`clock.show`) : un
     * limiteur nommé absent se lit comme un maximum de zéro et refuse tout
     * en 429.
     */
    private function configureGameRateLimiting(): void
    {
        RateLimiter::for('game-read', function (Request $request) {
            return Limit::perMinute(EngineConstants::gameReadsPerMinute())
                ->by($this->seatThrottleKey($request));
        });

        RateLimiter::for('game-write', function (Request $request) {
            return Limit::perMinute(EngineConstants::gameWritesPerMinute())
                ->by($this->seatThrottleKey($request));
        });

        RateLimiter::for('frame-serve', function (Request $request) {
            return Limit::perMinute(EngineConstants::frameServePerMinute())
                ->by($this->seatThrottleKey($request));
        });

        $this->configureAnswerRateLimiting();
        $this->configureRoomRateLimiting();
    }

    /**
     * Le limiteur `answer` de la saisie (spec 70 § 8, contrat C10 § 2 et
     * § 3), sur `round.answer.store` et `round.choice.store`.
     *
     * **Clé sur le SIÈGE, jamais sur l'IP ni sur le seul `public_id`** :
     * aucune IP n'entre dans une donnée du domaine, et une clé sur le
     * `public_id` laisserait un tiers qui le connaît vider le budget d'un
     * autre. Le siège est celui que `seat.active` a résolu et mis en mémoire
     * ({@see EnsureActiveSeat::seat()}), qui s'exécute AVANT ce limiteur par
     * son rang dans la liste de priorité (`bootstrap/app.php`) : un tiers
     * reçoit 403 sans jamais atteindre le compteur. Un siège non résolu a
     * donc déjà reçu 403 ; `Limit::none()` n'est qu'un filet.
     *
     * **Deux budgets par siège**, un pour le texte, un pour le clic
     * ({@see self::ANSWER_BUDGETS}) ; deux sièges d'une même personne doublent
     * le budget, résidu assumé (10 § 7.1, A-08).
     *
     * **Cadence** : `attemptsPerSecond` du `settings_snapshot` de la partie
     * courante, que `seat.active` met aussi en mémoire
     * ({@see EnsureActiveSeat::game()}) ; sans partie courante, le défaut de
     * `RoomSettingsBounds` — le contrôleur répond alors 409. Jamais un
     * littéral (règle 2).
     *
     * **Cadence non atomique, résidu du limiteur du framework** :
     * `ThrottleRequests::handleRequest()` lit le compteur (`tooManyAttempts`)
     * puis l'incrémente (`hit`) en deux temps. Des soumissions PARALLÈLES d'un
     * même siège, lancées au même instant, peuvent donc toutes lire un
     * compteur sous la cadence et passer. La cadence tient contre un client
     * qui enchaîne ses requêtes, pas contre un script qui les parallélise :
     * seul le plafond `attemptsPerRound`, gardé par l'`UPDATE … WHERE
     * wrong_attempts < :cap` atomique du refus (70 § 7.5), borne la manche,
     * et le budget réel du palier 1 est au plus `attemptsPerRound`, et non
     * `d₁ × attemptsPerSecond` (70 § 13.2). Écart signalé au porteur (journal
     * d'implémentation, E105-6).
     *
     * **Refus** : 429 `{ message }` (`game.answer.too_fast`), résolu dans la
     * locale de la requête — `SetLocale` passe lui aussi avant le limiteur —,
     * non évalué et non compté : la requête n'atteint ni l'action ni
     * `round_player`. La clé ne vit que dans le cache du limiteur, jamais en
     * base, et ne quitte jamais le serveur.
     */
    private function configureAnswerRateLimiting(): void
    {
        RateLimiter::for('answer', function (Request $request): Limit {
            $seat = EnsureActiveSeat::seat($request);

            if ($seat === null) {
                return Limit::none();
            }

            $snapshot = EnsureActiveSeat::game($request)?->settings_snapshot;

            return Limit::perSecond($snapshot->attemptsPerSecond ?? RoomSettingsBounds::DEFAULT_ATTEMPTS_PER_SECOND)
                ->by(sprintf('answer:%s:%d', $this->answerBudget($request), $seat->id))
                ->response(static fn (Request $request, array $headers): JsonResponse => response()->json(
                    ['message' => __('game.answer.too_fast')],
                    Response::HTTP_TOO_MANY_REQUESTS,
                    $headers,
                ));
        });
    }

    /**
     * Le budget du limiteur `answer` que consomme la route : `text` ou
     * `choice` ({@see self::ANSWER_BUDGETS}).
     *
     * @throws LogicException `throttle:answer` posé sur une autre route que
     *                        les deux soumissions de la saisie.
     */
    private function answerBudget(Request $request): string
    {
        $name = $request->route()?->getName();

        return self::ANSWER_BUDGETS[$name ?? ''] ?? throw new LogicException(sprintf(
            'Le limiteur answer ne compte que les soumissions de la saisie (%s), pas la route « %s » (spec 70 § 8).',
            implode(', ', array_keys(self::ANSWER_BUDGETS)),
            $name ?? $request->path(),
        ));
    }

    /**
     * Les deux limiteurs d'entrée du salon (spec 50 § 17.3) : gardes
     * anti-abus, jamais des limites de confort ni des valeurs de jeu, jamais
     * résolues par compte. Débits lus dans `RoomRateLimits` à chaque
     * comptage, jamais en littéral.
     *
     * - `room-create` (`room.store`) : par adresse IP, clé qui ne vit que dans
     *   le cache du limiteur, jamais dans une table de domaine ;
     * - `room-join` (`room.join`) : par hash du `player_token`, repli sur
     *   l'IP, comme les limiteurs de jeu.
     *
     * S'y ajoute `content-report` (`content-report.store`, D63 du 07/10) :
     * par compte connecté, sinon comme `room-join`. Et `content-report-frame`
     * (`content-report.frame`, amendé le 07/10), même clé, seau distinct de
     * `frame-serve`, pour que l'aperçu ne consomme jamais le budget C8 des
     * paliers.
     */
    private function configureRoomRateLimiting(): void
    {
        RateLimiter::for('room-create', function (Request $request) {
            return Limit::perHour(RoomRateLimits::createsPerHour())
                ->by(self::SEAT_THROTTLE_IP_PREFIX.$request->ip());
        });

        RateLimiter::for('room-join', function (Request $request) {
            return Limit::perMinute(RoomRateLimits::joinsPerMinute())
                ->by($this->seatThrottleKey($request));
        });

        // Le signalement d'un pseudo (spec 40 § 13.3, D66 du 07/10) : par
        // SIÈGE, résolu par `seat.active`, qui passe avant ce limiteur ; repli
        // sur le jeton puis l'adresse, comme les limiteurs de jeu.
        RateLimiter::for('seat-report', function (Request $request) {
            $seat = EnsureActiveSeat::seat($request);

            return Limit::perMinute(RoomRateLimits::seatReportsPerMinute())
                ->by($seat !== null ? 'seat-report:'.$seat->id : $this->seatThrottleKey($request));
        });

        // Le signalement de contenu par un joueur (D63 du 07/10) : par compte
        // connecté — qui peut signaler sans siège, donc sans jeton —, sinon
        // par hash du `player_token`, repli sur l'IP, comme l'entrée dans un
        // salon. Plusieurs comptes derrière une même adresse ne partagent
        // jamais un seau.
        RateLimiter::for('content-report', function (Request $request) {
            $account = $request->user()?->getAuthIdentifier();

            return Limit::perHour(ContentReportRateLimits::reportsPerHour())
                ->by($account !== null
                    ? self::ACCOUNT_THROTTLE_PREFIX.$account
                    : $this->seatThrottleKey($request));
        });

        // L'aperçu de l'image signalée (amendé le 07/10) : même clé que
        // `content-report`, seau distinct de `frame-serve` — un onglet
        // « Signaler » rechargé pendant la partie ne fait jamais tomber en 429
        // le préchargement du palier suivant.
        RateLimiter::for('content-report-frame', function (Request $request) {
            $account = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(ContentReportRateLimits::framePreviewsPerMinute())
                ->by($account !== null
                    ? self::ACCOUNT_THROTTLE_PREFIX.$account
                    : $this->seatThrottleKey($request));
        });
    }

    /**
     * Clé d'un limiteur de jeu : le hash du `player_token` courant, lu par
     * {@see PlayerTokenManager::current()} — qui ne frappe jamais et ne repose
     * jamais le cookie —, avec repli sur l'IP pour une requête sans jeton.
     * Deux espaces préfixés, qui ne se recouvrent jamais. La clé ne vit que
     * dans le cache du limiteur, jamais dans une table de domaine.
     */
    private function seatThrottleKey(Request $request): string
    {
        $hash = $this->app->make(PlayerTokenManager::class)->current($request)?->hash();

        return $hash !== null
            ? self::SEAT_THROTTLE_TOKEN_PREFIX.$hash
            : self::SEAT_THROTTLE_IP_PREFIX.$request->ip();
    }
}
