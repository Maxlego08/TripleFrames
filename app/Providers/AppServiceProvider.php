<?php

namespace App\Providers;

use App\Events\Game\AnswerAccepted;
use App\Events\Game\GameFinalized;
use App\Events\Game\InputClosed;
use App\Listeners\DiagnoseDependencies;
use App\Listeners\Game\BroadcastGameEnded;
use App\Listeners\Game\CloseSeatInput;
use App\Listeners\RecordLastLogin;
use App\Listeners\SyncCarbonLocale;
use App\Models\PerfSample;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Support\Draw\PoolQuery;
use App\Support\I18n\CookiePlayerTokenLocale;
use App\Support\I18n\LangVersion;
use App\Support\I18n\PlayerTokenLocale;
use App\Support\I18n\TranslationDomains;
use App\Support\Identity\PlayerTokenManager;
use App\Support\Ops\SystemLoad;
use App\Support\Perf\PerfRecorder;
use App\Support\Realtime\SeatPrincipal;
use App\Support\Retention\PurgeHandler;
use App\Support\Retention\PurgeHandlers;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
        $this->registerDraw();
        $this->registerOperations();

        // La mesure des performances (spec 100 § 10.11, D47 du 01/10) : une
        // seule instance, qui porte les portées ouvertes de la requête ou du job.
        $this->app->singleton(PerfRecorder::class);
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

        // La date de dernière connexion (spec 10 § 5.1), lue par l'écran de
        // gestion des accès et, au jalon 2, par le balayage de dormance.
        Event::listen(Login::class, RecordLastLogin::class);

        // Discord n'est pas un pilote natif de Socialite (spec 40 § 12.1) :
        // le paquet communautaire s'enregistre par cet événement.
        Event::listen(SocialiteWasCalled::class, [DiscordExtendSocialite::class, 'handle']);

        $this->registerGameListeners();
        $this->registerPlayerGuard();
        $this->registerPerformance();
    }

    /**
     * Les écouteurs du moteur (spec 60 § 8.2 et § 14.5, lot L60-11), ici
     * faute d'`EventServiceProvider`, découverte automatique coupée : les
     * crochets de fin de saisie sur les événements de domaine de 70
     * (`AnswerAccepted`, `InputClosed`), qui émettent `player.locked` et
     * réévaluent la fin anticipée, et l'annonce `game.ended` sur le gel de 80
     * (`GameFinalized`). Tous sont livrés après commit, synchrones.
     */
    protected function registerGameListeners(): void
    {
        Event::listen(AnswerAccepted::class, [CloseSeatInput::class, 'answerAccepted']);
        Event::listen(InputClosed::class, [CloseSeatInput::class, 'inputClosed']);
        Event::listen(GameFinalized::class, BroadcastGameEnded::class);
    }

    /**
     * La mesure des performances (spec 100 § 10.11, D47 du 01/10) : un seul
     * écouteur `DB::listen`, et une portée par job, ouverte à `JobProcessing`
     * et refermée à `JobProcessed` ou `JobExceptionOccurred`. Toujours
     * inscrits, inertes si `perf.enabled` est faux : un test peut activer la
     * mesure par la configuration seule.
     */
    protected function registerPerformance(): void
    {
        $recorder = fn (): PerfRecorder => $this->app->make(PerfRecorder::class);

        DB::listen(static function (QueryExecuted $query) use ($recorder): void {
            $recorder()->query($query);
        });

        Event::listen(JobProcessing::class, static function (JobProcessing $event) use ($recorder): void {
            $createdAt = $event->job->payload()['createdAt'] ?? null;

            $recorder()->begin(
                PerfSample::KIND_JOB,
                null,
                $event->job->getQueue(),
                is_int($createdAt) ? max(0, (int) (Date::now()->getTimestampMs() - $createdAt * 1000)) : null,
            );
        });

        Event::listen(JobProcessed::class, static function (JobProcessed $event) use ($recorder): void {
            $recorder()->finish($event->job->resolveName(), PerfSample::STATUS_PROCESSED);
        });

        Event::listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event) use ($recorder): void {
            $recorder()->finish($event->job->resolveName(), PerfSample::STATUS_FAILED);
        });
    }

    /**
     * La garde `player` des canaux de diffusion (spec 60 § 10.4, contrat C7
     * § 2.2) : elle ne lit QUE le hash du `player_token` courant, par
     * `PlayerTokenManager::current()` (C4, R-30), qui ne frappe ni ne repose
     * jamais le cookie. Sans jeton valide, aucun principal : `/broadcasting/auth`
     * refuse alors tout canal privé ou de présence avant même d'appeler la
     * classe de canal. Le siège n'est lié au principal que par cette classe,
     * une fois le salon et l'expulsion vérifiés.
     */
    protected function registerPlayerGuard(): void
    {
        Auth::viaRequest('player-token', static function (Request $request): ?SeatPrincipal {
            $hash = app(PlayerTokenManager::class)->current($request)?->hash();

            return $hash === null ? null : new SeatPrincipal($hash);
        });
    }

    /**
     * Le registre des domaines de traduction est un singleton **de requête** :
     * les middlewares de route le remplissent, la prop Inertia `translations`
     * le lit au rendu.
     *
     * `PlayerTokenLocale` est le niveau 3 de la résolution de langue, lié à
     * la revendication `locale` du `player_token` courant (spec 40 § 4.1) :
     * lue par `PlayerTokenManager::current()`, qui ne frappe jamais rien.
     *
     * `PlayerTokenManager` est un singleton **sans état** (spec 40 § 3.1) : le
     * jeton courant est mémorisé dans les attributs de la requête, jamais dans
     * l'objet, pour qu'aucune identité ne fuie d'une requête à l'autre dans un
     * processus long.
     */
    protected function registerLocalization(): void
    {
        $this->app->singleton(TranslationDomains::class);
        $this->app->singleton(LangVersion::class);
        $this->app->bind(PlayerTokenLocale::class, CookiePlayerTokenLocale::class);
        $this->app->singleton(PlayerTokenManager::class);
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
     * Le constructeur unique du vivier est `scoped` (spec 30 § 3.1) : une instance
     * par requête HTTP ou par job, que partagent le rapport de vivier et le tirage
     * d'un même lancement. Elle mémorise les thèmes publiés pour sa seule durée de
     * vie, jamais au-delà : une dépublication vaut à la requête suivante sans
     * aucune invalidation de cache.
     */
    protected function registerDraw(): void
    {
        $this->app->scoped(PoolQuery::class);
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
