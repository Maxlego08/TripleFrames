<?php

namespace Tests\Support\Retention;

use App\Enums\PurgeScope;
use App\Support\Retention\PurgeHandler;
use App\Support\Retention\PurgeHandlers;
use App\Support\Retention\PurgeRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Un gestionnaire de purge de test : il annonce `$eligible` lignes éligibles,
 * ne sélectionne jamais aucune ligne, et retient chaque appel — l'instant
 * auquel la sonde l'évalue, et s'il a été exécuté par le moteur.
 *
 * {@see self::replace()} le substitue au gestionnaire réel d'un périmètre
 * (le moteur et la sonde voient alors UN gestionnaire, le faux) ;
 * {@see self::alongside()} l'étiquette EN PLUS, pour un périmètre servi par
 * deux gestionnaires ou pour un périmètre que le moteur ne déclare pas.
 */
final class FakePurgeHandler implements PurgeHandler
{
    public ?CarbonImmutable $askedAsOf = null;

    public int $batchesAsked = 0;

    public int $purged = 0;

    public function __construct(private readonly PurgeScope $served, public int $eligible = 0) {}

    /**
     * Remplace le gestionnaire réel de `$scope` ; l'étiquette en plus si le
     * périmètre n'en a aucun.
     */
    public static function replace(PurgeScope $scope, int $eligible = 0): self
    {
        $fake = new self($scope, $eligible);

        if (! self::substitute($scope, $fake)) {
            self::tag($fake);
        }

        return $fake;
    }

    /** Étiquette un gestionnaire de plus pour `$scope`, à côté de ceux qui existent. */
    public static function alongside(PurgeScope $scope, int $eligible = 0): self
    {
        $fake = new self($scope, $eligible);

        self::tag($fake);

        return $fake;
    }

    /**
     * Lie `$handler` à la place du gestionnaire déclaré de `$scope` dans
     * {@see PurgeHandlers::CLASSES} ; `false` si aucun ne le sert.
     */
    public static function substitute(PurgeScope $scope, PurgeHandler $handler): bool
    {
        foreach (PurgeHandlers::CLASSES as $class) {
            $current = app($class);

            if ($current instanceof PurgeHandler && $current->scope() === $scope) {
                app()->instance($class, $handler);

                return true;
            }
        }

        return false;
    }

    /** Le gestionnaire déclaré de `$scope`, tel que le conteneur le rend. */
    public static function declared(PurgeScope $scope): PurgeHandler
    {
        foreach (PurgeHandlers::CLASSES as $class) {
            $current = app($class);

            if ($current instanceof PurgeHandler && $current->scope() === $scope) {
                return $current;
            }
        }

        throw new \LogicException("Aucun gestionnaire déclaré pour {$scope->value}.");
    }

    private static function tag(PurgeHandler $handler): void
    {
        $abstract = 'tests.purge-handler.'.$handler->scope()->value.'.'.spl_object_id($handler);

        app()->instance($abstract, $handler);
        app()->tag([$abstract], PurgeHandler::class);
    }

    public function scope(): PurgeScope
    {
        return $this->served;
    }

    public function eligibleCount(?CarbonImmutable $asOf = null): int
    {
        $this->askedAsOf = $asOf;

        return $this->eligible;
    }

    public function nextBatch(CarbonImmutable $now, ?PurgeRow $after, int $size): array
    {
        $this->batchesAsked++;

        return [];
    }

    public function purge(PurgeRow $row, CarbonImmutable $now): int
    {
        $this->purged++;

        return 0;
    }

    public function connection(): ConnectionInterface
    {
        return DB::connection();
    }

    /** Vrai si le moteur l'a exécuté, ne serait-ce qu'en lui demandant un lot. */
    public function wasExecuted(): bool
    {
        return $this->batchesAsked > 0 || $this->purged > 0;
    }
}
