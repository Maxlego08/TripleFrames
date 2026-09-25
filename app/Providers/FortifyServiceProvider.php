<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Support\Identity\AccountSwitches;
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

        // Les sondes d'exploitation (spec 100 § 15), AVANT la vérification du
        // jeton : les essais de jeton sont bornés par adresse. La supervision
        // interroge cinq sondes, dont la plus fréquente chaque minute ; trente
        // requêtes par minute et par adresse lui laissent une large marge, y
        // compris depuis plusieurs points de mesure derrière une même sortie.
        RateLimiter::for('ops-probe', function (Request $request) {
            return Limit::perMinute(30)->by((string) $request->ip());
        });
    }
}
