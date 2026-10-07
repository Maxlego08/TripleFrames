<?php

namespace App\Support\Retention\Handlers;

use App\Enums\PurgeScope;
use App\Models\ContentReport;
use App\Support\Retention\RetentionWindows;
use App\Support\Retention\TablePurgeHandler;
use Carbon\CarbonImmutable;

/**
 * Périmètre `content_report` — 10 § 11.1 (D63 du 07/10) : un signalement de
 * contenu supprimé 12 mois après `created_at` (`content_report_created_idx`),
 * qu'il soit ouvert ou clos. Son texte libre est une donnée de joueur ; la
 * trace d'un geste qu'il a déclenché vit dans `admin_action`, permanente.
 */
final class ContentReportHandler extends TablePurgeHandler
{
    public function scope(): PurgeScope
    {
        return PurgeScope::ContentReport;
    }

    protected function connectionName(): ?string
    {
        return (new ContentReport)->getConnectionName();
    }

    protected function table(): string
    {
        return (new ContentReport)->getTable();
    }

    protected function pilotColumn(): string
    {
        return 'created_at';
    }

    protected function keyColumn(): string
    {
        return 'id';
    }

    protected function cutoff(CarbonImmutable $asOf): string
    {
        return $asOf->subMonthsNoOverflow(RetentionWindows::CONTENT_REPORT_MONTHS)->format((new ContentReport)->getDateFormat());
    }
}
