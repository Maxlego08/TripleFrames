<?php

namespace App\Support\Retention\Handlers;

use App\Enums\PurgeScope;
use App\Models\PurgeRun;
use App\Support\Retention\RetentionWindows;
use App\Support\Retention\TablePurgeHandler;
use Carbon\CarbonImmutable;

/**
 * Périmètre `purge_run` — 10 § 11.1 : auto-purge à 13 mois, sur `ran_at`
 * (`purge_run_ran_idx`). Une fenêtre de plus que la plus longue que la table
 * atteste : la preuve qu'une durée de 12 mois a été tenue survit à cette
 * durée.
 *
 * Il s'exécute en dernier (ordre du tableau de 10) : les lignes de
 * l'exécution en cours, écrites à l'instant, ne sont jamais éligibles.
 */
final class PurgeRunHandler extends TablePurgeHandler
{
    public function scope(): PurgeScope
    {
        return PurgeScope::PurgeRun;
    }

    protected function connectionName(): ?string
    {
        return (new PurgeRun)->getConnectionName();
    }

    protected function table(): string
    {
        return (new PurgeRun)->getTable();
    }

    protected function pilotColumn(): string
    {
        return 'ran_at';
    }

    protected function keyColumn(): string
    {
        return 'id';
    }

    protected function cutoff(CarbonImmutable $asOf): CarbonImmutable
    {
        return $asOf->subMonthsNoOverflow(RetentionWindows::PURGE_RUN_MONTHS);
    }
}
