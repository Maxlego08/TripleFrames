<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Support\Deploy\DeployDrain;
use App\Support\Frames\FrameGeometry;
use App\Support\I18n\LangVersion;
use App\Support\I18n\TranslationDomains;
use App\Support\I18n\Translations;
use App\Support\Identity\AccountSwitches;
use App\Support\Identity\OAuthProviders;
use App\Support\Realtime\RealtimeClientConfig;
use App\Support\Visitor\ConsentCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * L'empreinte des fichiers `lang/` s'ajoute à celle des assets : sans
     * elle, un déploiement qui ne touche qu'une traduction ne change rien pour
     * Inertia, et le joueur garde l'ancien texte jusqu'au vidage de son cache.
     * Elle ne dépend jamais de la locale active — sinon changer de langue
     * provoquerait un rechargement complet de page, en pleine manche.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        $assets = parent::version($request);
        $lang = app(LangVersion::class)->fingerprint();

        return $assets === null ? $lang : hash('xxh128', $assets.'|'.$lang);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // `share()` est évaluée à l'entrée du middleware, sur TOUTE route
            // du groupe `web`. Sans session (`clock.show`, `frame.serve`),
            // aucun compte n'est résolu : le guard retomberait sur le cookie
            // « se souvenir de moi » et écrirait (régénération de session,
            // `Login`) sur une route en lecture seule (spec 60 § 7.3).
            'auth' => [
                'user' => $request->hasSession() ? $request->user() : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

            // Liens de connexion et d'inscription de l'en-tête public, et
            // crochet de compte d'après podium (spec 40 § 8.2, 90 § 2.4) :
            // absents tant que l'inscription est fermée, donc en production
            // au jalon 1. Le porteur atteint `/login` par son adresse.
            'accountsOpen' => AccountSwitches::registrationOpen(),

            // Les fournisseurs de connexion actifs (spec 40 § 12.1, D51 du
            // 01/10) : boutons de connexion, et lien « Se connecter » de
            // l'en-tête public même inscription fermée.
            'oauthProviders' => OAuthProviders::values(),

            // Le choix de la bannière de consentement (D62 du 06/10) :
            // `accepted`, `refused`, ou null tant que le visiteur n'a pas
            // répondu à la version courante — la bannière s'affiche alors.
            'consent' => ConsentCookie::read($request)?->value,

            // Format fixe de la frame servable (spec 90 § 7.1, contrat C16 ;
            // C9) : deux entiers, globaux et identiques pour tous, sans
            // aucune donnée de manche. Ils ne servent qu'aux attributs
            // `width` / `height` de l'image de `GameFrame`, en jeu comme en
            // aperçu admin ; le ratio du cadre vient du jeton
            // `--aspect-frame`, source unique (R-37).
            'frameFormat' => [
                'width' => FrameGeometry::GAME_WIDTH,
                'height' => FrameGeometry::GAME_HEIGHT,
            ],

            // Configuration du client temps réel (spec 60 § 10.5, contrat C7
            // § 2.6) : clé PUBLIQUE de Reverb, hôte, port et schéma visés par
            // le navigateur (nuls = `window.location`), cadence du battement
            // et échantillons d'horloge. Lue au runtime, jamais figée au build
            // (A-27) ; identique pour tous, sans aucune donnée de partie.
            // Closure : un rechargement partiel qui ne la demande pas ne la
            // calcule pas.
            'realtime' => fn (): array => RealtimeClientConfig::toArray(),

            // Drapeau de drainage de déploiement (spec 100 § 11.3, contrat
            // C18-bis) : un BOOLÉEN, vrai dans les deux phases, et rien
            // d'autre — ni heure, ni phase, ni compte de parties. Le bandeau
            // de 90 l'affiche à la réponse Inertia suivante ; aucune diffusion
            // Reverb au J1, et le refus de lancement reste la seule garantie.
            // Closure : lu au rendu, jamais sur un rechargement partiel qui ne
            // le demande pas.
            'maintenance' => fn (): bool => app(DeployDrain::class)->isDraining(),

            // Les trois props d'i18n sont des closures : `share()` est appelée
            // à l'entrée du middleware Inertia, donc AVANT les middlewares de
            // route qui sélectionnent les domaines et, sur le back-office,
            // forcent le français. Résolues au rendu, elles voient l'état
            // final ; lues à l'entrée, elles ne verraient jamais que `common`.
            'locale' => fn (): string => $this->locale()->value,
            'locales' => fn (): array => $this->locales(),
            'translations' => fn (): array => $this->translations($request),
        ];
    }

    /**
     * Locale active, toujours validée par l'enum : `App::getLocale()` est une
     * chaîne libre, et une valeur inconnue construirait des chemins de
     * fichiers côté front comme côté serveur.
     */
    protected function locale(): Locale
    {
        return Locale::tryFrom(App::getLocale()) ?? Translations::fallback();
    }

    /**
     * Registre des locales activées. Le libellé est **natif** : un sélecteur
     * de langue affiche toujours chaque langue dans sa propre langue, jamais
     * traduite — c'est ce qui permet à un joueur perdu dans une interface
     * qu'il ne lit pas de retrouver la sienne.
     *
     * @return list<array{value: string, label: string, bcp47: string, dir: string}>
     */
    protected function locales(): array
    {
        return array_map(
            fn (Locale $locale): array => [
                'value' => $locale->value,
                'label' => $locale->nativeLabel(),
                'bcp47' => $locale->bcp47(),
                'dir' => $locale->direction(),
            ],
            Locale::cases(),
        );
    }

    /**
     * Dictionnaire aplati, limité aux domaines déclarés par la page rendue.
     *
     * Envoyer le dictionnaire entier à chaque réponse serait du poids réseau
     * pur sur un écran mobile en 4G.
     *
     * @return array<string, string>
     */
    protected function translations(Request $request): array
    {
        return app(Translations::class)->flatten(
            $this->translationsLocale($request),
            app(TranslationDomains::class)->selected(),
        );
    }

    /**
     * Locale du dictionnaire : celle de la requête, sauf préchargement du
     * sélecteur de langue, qui demande explicitement la locale visée avant de
     * persister le choix. Ce paramètre ne change **que** cette prop : il
     * n'appelle jamais `App::setLocale()` et n'est pas une source de locale.
     */
    protected function translationsLocale(Request $request): Locale
    {
        $preview = $request->input(Translations::PREVIEW_PARAM);

        return (is_string($preview) ? Locale::tryFrom($preview) : null) ?? $this->locale();
    }
}
