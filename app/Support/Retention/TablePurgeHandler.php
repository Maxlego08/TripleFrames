<?php

namespace App\Support\Retention;

use App\Enums\PurgeScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Un périmètre qui **supprime** les lignes d'une table dont la colonne pilote
 * est antérieure à une borne — les quatre périmètres sans jeu du premier
 * temps (spec 100 § 14).
 *
 * Le prédicat d'éligibilité est écrit UNE fois ({@see self::eligible()}) et
 * lu par le compte de la sonde, par la sélection du lot et par l'effacement,
 * qui le RELIT : une ligne redevenue non éligible entre sa sélection et sa
 * transaction (une session reprise à la dernière seconde) n'est pas
 * supprimée. La borne est stricte (`<`).
 */
abstract class TablePurgeHandler implements PurgeHandler
{
    abstract public function scope(): PurgeScope;

    /** Le nom de la connexion qui porte la table, `null` = connexion par défaut. */
    abstract protected function connectionName(): ?string;

    abstract protected function table(): string;

    /** La colonne pilote : l'instant dont l'âge rend la ligne éligible. */
    abstract protected function pilotColumn(): string;

    /** La clé unique de la ligne, second critère de tri du curseur. */
    abstract protected function keyColumn(): string;

    /**
     * La borne à l'instant `$asOf` : est éligible toute ligne dont la colonne
     * pilote lui est strictement antérieure. Durée lue dans
     * {@see RetentionWindows}, jamais écrite ici.
     */
    abstract protected function cutoff(CarbonImmutable $asOf): int|CarbonImmutable;

    public function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }

    final public function eligibleCount(?CarbonImmutable $asOf = null): int
    {
        return $this->eligible($asOf ?? CarbonImmutable::now())->count();
    }

    final public function nextBatch(CarbonImmutable $now, ?PurgeRow $after, int $size): array
    {
        $pilot = $this->pilotColumn();
        $key = $this->keyColumn();

        $query = $this->eligible($now)
            ->select([$pilot, $key])
            ->orderBy($pilot)
            ->orderBy($key)
            ->limit($size);

        if ($after !== null) {
            $query->where(static function (Builder $cursor) use ($pilot, $key, $after): void {
                $cursor->where($pilot, '>', $after->pilot)
                    ->orWhere(static function (Builder $tie) use ($pilot, $key, $after): void {
                        $tie->where($pilot, '=', $after->pilot)->where($key, '>', $after->key);
                    });
            });
        }

        $rows = [];

        foreach ($query->get() as $row) {
            $values = (array) $row;
            $rows[] = new PurgeRow(self::scalar($values[$pilot] ?? null), self::scalar($values[$key] ?? null));
        }

        return $rows;
    }

    public function purge(PurgeRow $row, CarbonImmutable $now): int
    {
        return $this->eligible($now)->where($this->keyColumn(), '=', $row->key)->delete();
    }

    /**
     * LE prédicat d'éligibilité du périmètre, à l'instant `$asOf`.
     */
    final protected function eligible(CarbonImmutable $asOf): Builder
    {
        return $this->connection()
            ->table($this->table())
            ->where($this->pilotColumn(), '<', $this->cutoff($asOf));
    }

    private static function scalar(mixed $value): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        throw new UnexpectedValueException('Colonne pilote ou clé de purge nulle ou non scalaire.');
    }
}
