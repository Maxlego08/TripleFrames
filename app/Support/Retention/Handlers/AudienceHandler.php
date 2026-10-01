<?php

namespace App\Support\Retention\Handlers;

use App\Enums\PurgeScope;
use App\Models\AudienceDaily;
use App\Support\Retention\RetentionWindows;
use App\Support\Retention\TablePurgeHandler;
use Carbon\CarbonImmutable;

/**
 * Périmètre `audience` — 10 § 11.1 (D48 du 01/10) : les compteurs quotidiens
 * d'audience, 13 mois, sur `day` (`audience_daily_day_idx`). La présence des
 * dix dernières minutes est effacée par l'enregistreur lui-même.
 */
final class AudienceHandler extends TablePurgeHandler
{
    public function scope(): PurgeScope
    {
        return PurgeScope::Audience;
    }

    protected function connectionName(): ?string
    {
        return (new AudienceDaily)->getConnectionName();
    }

    protected function table(): string
    {
        return (new AudienceDaily)->getTable();
    }

    protected function pilotColumn(): string
    {
        return 'day';
    }

    protected function keyColumn(): string
    {
        return 'id';
    }

    /** Un jour nu : la colonne pilote est une date (voir la classe mère). */
    protected function cutoff(CarbonImmutable $asOf): string
    {
        return $asOf->startOfDay()->subMonthsNoOverflow(RetentionWindows::AUDIENCE_MONTHS)->toDateString();
    }
}
