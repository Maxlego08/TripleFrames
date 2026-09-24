<?php

namespace App\Providers;

use App\Listeners\DiagnoseDependencies;
use App\Listeners\SyncCarbonLocale;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Support\I18n\LangVersion;
use App\Support\I18n\NullPlayerTokenLocale;
use App\Support\I18n\PlayerTokenLocale;
use App\Support\I18n\TranslationDomains;
use App\Support\Ops\SystemLoad;
use App\Support\Retention\PurgeHandler;
use App\Support\Retention\PurgeHandlers;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerLocalization();
        $this->registerPlatformLimits();
        $this->registerEngineConstants();
        $this->registerOperations();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->assertFramesDiskRoot();

        // `CarbonImmutable` est recâblé une seule fois, par un listener et non
        // par le middleware de locale : la même bascule doit s'appliquer dans
        // un job de mail en file, où aucun middleware HTTP ne tourne. Le dépôt
        // n'a pas d'`EventServiceProvider`, l'enregistrement vit donc ici, et
        // la découverte automatique est coupée (`bootstrap/app.php`) pour
        // qu'aucun écouteur ne soit enregistré deux fois.
        Event::listen(LocaleUpdated::class, SyncCarbonLocale::class);

        // `/up` interroge la base, et Redis quand le cache ou la file en
        // dépendent (spec 100 § 15) : le framework répond 500 dès qu'un
        // écouteur de `DiagnosingHealth` lève.
        Event::listen(DiagnosingHealth::class, DiagnoseDependencies::class);
    }

    /**
     * Le registre des domaines de traduction est un singleton **de requête** :
     * les middlewares de route le remplissent, la prop Inertia `translations`
     * le lit au rendu.
     *
     * `PlayerTokenLocale` est le niveau 3 de la résolution de langue ; la
     * forme du `player_token` appartenant aux specs 10 et 40, il est lié à une
     * implémentation neutre tant que le jeton n'est pas frappé.
     */
    protected function registerLocalization(): void
    {
        $this->app->singleton(TranslationDomains::class);
        $this->app->singleton(LangVersion::class);
        $this->app->bind(PlayerTokenLocale::class, NullPlayerTokenLocale::class);
    }

    /**
     * Les plafonds de plateforme sont mémoïsés **dans le conteneur**, une fois par
     * cycle de vie (requête, job), et jamais dans une propriété statique de classe
     * (spec 50 § 2.3).
     *
     * `scoped` et non `singleton` : un worker de file ou d'Octane oublie l'instance
     * à chaque job ou requête, et un conteneur neuf par test Pest ne relit jamais
     * la valeur d'un test précédent. Tous les accesseurs statiques de
     * {@see PlatformLimits} délèguent à cette instance : la garde de bornes de son
     * constructeur s'applique donc sur tous les chemins.
     */
    protected function registerPlatformLimits(): void
    {
        $this->app->scoped(PlatformLimits::class, static fn (): PlatformLimits => PlatformLimits::fromConfig());
    }

    /**
     * Les constantes du moteur suivent le même régime que les plafonds de
     * plateforme (spec 60 § 19.1) : `scoped`, jamais une propriété statique.
     *
     * Leur garde lit `PlatformLimits::drawSubstituteMargin()` (clôture après
     * pause) : une instance mémoïsée porte donc aussi la marge de tirage du
     * cycle de vie qui l'a construite, et se périme avec lui.
     */
    protected function registerEngineConstants(): void
    {
        $this->app->scoped(EngineConstants::class, static fn (): EngineConstants => EngineConstants::fromConfig());
    }

    /**
     * Les mesures de la machine lues par la sonde `load` (spec 100 § 15) : les
     * sources réelles, que les tests remplacent par une instance aux sources
     * injectées. `bind` et non `singleton` : chaque sonde relit la machine.
     *
     * Les gestionnaires de purge (spec 100 § 14) sont étiquetés sous
     * {@see PurgeHandler}, liste déclarée dans {@see PurgeHandlers::CLASSES} :
     * le moteur et la sonde `purge` les lisent par la même étiquette.
     */
    protected function registerOperations(): void
    {
        $this->app->bind(SystemLoad::class, static fn (): SystemLoad => SystemLoad::fromHost());

        $this->app->tag(PurgeHandlers::CLASSES, PurgeHandler::class);
    }

    /**
     * La racine du disque `frames` doit être déclarée **hors** du répertoire de
     * déploiement, et le repli de confort ne vaut qu'en local et en testing.
     *
     * `config/filesystems.php` replie une `FRAMES_DISK_ROOT` vide sur
     * `storage/app/frames`. Sur un déploiement par répertoire de release où
     * `storage/` n'est pas partagé, toutes les images de jeu disparaissent au
     * déploiement suivant pendant que `Frame::isServable()` reste vrai en base : le
     * moteur n'emprunte pas le chemin de substitution et signe une URL vers un
     * objet absent — image cassée pendant toute la durée `D`, à chaque manche, sans
     * une ligne de journal applicatif. Une variable absente se comportant
     * exactement comme une variable vide, seule une garde rend l'oubli bruyant.
     *
     * La garde vit dans un provider et non dans le fichier de configuration : un
     * fichier de config ne doit jamais lever.
     */
    protected function assertFramesDiskRoot(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        $root = trim((string) config('filesystems.disks.frames.root'));

        if ($root === '' || str_starts_with($root, base_path())) {
            throw new RuntimeException(
                'FRAMES_DISK_ROOT doit être un chemin absolu déclaré HORS du répertoire de déploiement ; la '
                ."racine résolue vaut [{$root}]. Sans cela, un déploiement par répertoire de release emporte "
                .'toutes les images de jeu, sans qu’aucune ligne `frame` ne cesse d’être servable.',
            );
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
