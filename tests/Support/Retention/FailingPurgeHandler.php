<?php

namespace Tests\Support\Retention;

use App\Enums\PurgeScope;
use App\Support\Retention\PurgeHandler;
use App\Support\Retention\PurgeRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * Le gestionnaire RÉEL d'un périmètre, dont l'effacement de certaines lignes
 * échoue APRÈS avoir supprimé la ligne — comme une contrainte `restrict`
 * vérifiée en aval dans la même transaction.
 *
 * L'exception levée est une vraie `QueryException` dont le message porte la
 * clé de la ligne dans ses valeurs liées : c'est ce que le moteur ne doit
 * jamais recopier dans `purge_run` ni au journal. Portable SQLite et MySQL,
 * là où un déclencheur de base serait du DDL — validation implicite en MySQL,
 * donc incompatible avec la transaction englobante des tests.
 */
final readonly class FailingPurgeHandler implements PurgeHandler
{
    /**
     * @param  list<int|string>  $failingKeys
     */
    public function __construct(private PurgeHandler $inner, private array $failingKeys) {}

    /**
     * Enveloppe le gestionnaire déclaré de `$scope` et le lie à sa place.
     *
     * @param  list<int|string>  $failingKeys
     */
    public static function wrap(PurgeScope $scope, array $failingKeys): self
    {
        $failing = new self(FakePurgeHandler::declared($scope), $failingKeys);

        FakePurgeHandler::substitute($scope, $failing);

        return $failing;
    }

    public function scope(): PurgeScope
    {
        return $this->inner->scope();
    }

    public function eligibleCount(?CarbonImmutable $asOf = null): int
    {
        return $this->inner->eligibleCount($asOf);
    }

    public function nextBatch(CarbonImmutable $now, ?PurgeRow $after, int $size): array
    {
        return $this->inner->nextBatch($now, $after, $size);
    }

    public function purge(PurgeRow $row, CarbonImmutable $now): int
    {
        $affected = $this->inner->purge($row, $now);

        if (in_array($row->key, $this->failingKeys, true)) {
            throw new QueryException(
                'testing',
                'delete from "table" where "key" = ?',
                [$row->key],
                new PDOException('restrict simulé', 23000),
            );
        }

        return $affected;
    }

    public function connection(): ConnectionInterface
    {
        return $this->inner->connection();
    }
}
