<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->assertFramesDiskRoot();
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
