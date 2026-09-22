<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Support\I18n\LangVersion;
use App\Support\I18n\TranslationDomains;
use App\Support\I18n\Translations;
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
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

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
