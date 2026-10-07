<?php

namespace App\Support\Retention;

use App\Support\Retention\Handlers\AudienceHandler;
use App\Support\Retention\Handlers\ContentReportHandler;
use App\Support\Retention\Handlers\FrameworkFailedJobsHandler;
use App\Support\Retention\Handlers\FrameworkResetTokensHandler;
use App\Support\Retention\Handlers\FrameworkSessionsHandler;
use App\Support\Retention\Handlers\GameTraceHandler;
use App\Support\Retention\Handlers\GuestNicknameHandler;
use App\Support\Retention\Handlers\OrphanPlayerHandler;
use App\Support\Retention\Handlers\PerfSampleHandler;
use App\Support\Retention\Handlers\PurgeRunHandler;
use App\Support\Retention\Handlers\StaleRoomHandler;
use App\Support\Retention\Handlers\VisitorHandler;
use Illuminate\Contracts\Container\Container;

/**
 * Le registre des gestionnaires de purge : la liste déclarée dans le code,
 * étiquetée dans le conteneur sous {@see PurgeHandler} par
 * `AppServiceProvider`, et sa lecture par périmètre — partagée par le moteur
 * ({@see RetentionPurger}) et par la sonde `purge`, pour qu'ils voient les
 * mêmes gestionnaires.
 *
 * Un gestionnaire s'ajoute ici EN MÊME TEMPS que son périmètre entre dans
 * `PurgeScope::implemented()` : un périmètre déclaré sans gestionnaire, ou
 * servi par deux, n'est pas exécuté (ligne `purge_run` en échec) et la sonde
 * le met en alerte.
 */
final readonly class PurgeHandlers
{
    /**
     * Les gestionnaires livrés (D37 du 23/09) : `stale_room`, qui archive par
     * l'action de 50 (L50-8), la branche sièges solo d'`orphan_player`, qui
     * efface les identifiants d'un siège solo (L60-15), et les périmètres
     * sans jeu.
     *
     * @var list<class-string<PurgeHandler>>
     */
    public const array CLASSES = [
        StaleRoomHandler::class,
        OrphanPlayerHandler::class,
        GuestNicknameHandler::class,
        VisitorHandler::class,
        PerfSampleHandler::class,
        GameTraceHandler::class,
        AudienceHandler::class,
        ContentReportHandler::class,
        FrameworkSessionsHandler::class,
        FrameworkFailedJobsHandler::class,
        FrameworkResetTokensHandler::class,
        PurgeRunHandler::class,
    ];

    public function __construct(private Container $container) {}

    /**
     * Les gestionnaires étiquetés, groupés par la valeur de leur périmètre.
     *
     * @return array<string, non-empty-list<PurgeHandler>>
     */
    public function byScope(): array
    {
        $byScope = [];

        foreach ($this->container->tagged(PurgeHandler::class) as $handler) {
            if ($handler instanceof PurgeHandler) {
                $byScope[$handler->scope()->value][] = $handler;
            }
        }

        return $byScope;
    }
}
