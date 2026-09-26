<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Settings\EngineConstants;
use App\Support\Identity\AccountSwitches;
use App\Support\Identity\PlayerTokenManager;
use App\Support\Room\RoomRateLimits;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /** Espace des clés de limiteur de jeu comptées par jeton. */
    private const string SEAT_THROTTLE_TOKEN_PREFIX = 'token:';

    /** Espace des clés de limiteur de jeu comptées par adresse, faute de jeton. */
    private const string SEAT_THROTTLE_IP_PREFIX = 'ip:';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
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

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password', [
            'canUsePasskeys' => $this->canUsePasskeys(),
        ]));
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
        // Imagick —, au-dessus du débit de « Entrée = conforme, publier ». Par
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

        $this->configureRoomRateLimiting();
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
