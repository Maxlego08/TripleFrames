<?php

namespace App\Support\Retention\Handlers;

use App\Enums\PurgeScope;
use App\Models\Visitor;
use App\Support\Retention\RetentionWindows;
use App\Support\Retention\TablePurgeHandler;
use Carbon\CarbonImmutable;

/**
 * Périmètre `visitor` — 10 § 11.1 (D62 du 06/10) : un visiteur consentant
 * supprimé 13 mois après sa dernière activité (`last_seen_at`,
 * `visitor_last_seen_idx`), la durée maximale d'un traceur ; ses sièges
 * perdent leur lien par `nullOnDelete`.
 */
final class VisitorHandler extends TablePurgeHandler
{
    public function scope(): PurgeScope
    {
        return PurgeScope::Visitor;
    }

    protected function connectionName(): ?string
    {
        return (new Visitor)->getConnectionName();
    }

    protected function table(): string
    {
        return (new Visitor)->getTable();
    }

    protected function pilotColumn(): string
    {
        return 'last_seen_at';
    }

    protected function keyColumn(): string
    {
        return 'id';
    }

    protected function cutoff(CarbonImmutable $asOf): string
    {
        return $asOf->subMonthsNoOverflow(RetentionWindows::VISITOR_MONTHS)->format((new Visitor)->getDateFormat());
    }
}
