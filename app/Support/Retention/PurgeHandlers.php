<?php

namespace App\Support\Retention;

use App\Support\Retention\Handlers\FrameworkFailedJobsHandler;
use App\Support\Retention\Handlers\FrameworkResetTokensHandler;
use App\Support\Retention\Handlers\FrameworkSessionsHandler;
use App\Support\Retention\Handlers\PurgeRunHandler;
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
     * Les gestionnaires du premier temps de L100-8 (D37 du 23/09) : les
     * périmètres sans jeu. `stale_room` (L50-8) et la branche solo
     * d'`orphan_player` (L60-15) s'y ajoutent avec leur lot.
     *
     * @var list<class-string<PurgeHandler>>
     */
    public const array CLASSES = [
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
