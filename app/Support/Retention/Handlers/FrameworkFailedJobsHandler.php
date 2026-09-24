<?php

namespace App\Support\Retention\Handlers;

use App\Enums\PurgeScope;
use App\Support\Retention\RetentionWindows;
use App\Support\Retention\TablePurgeHandler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Périmètre `framework_failed_jobs` — 10 § 11.1 : 14 jours, sur `failed_at`.
 *
 * **Pourquoi une purge.** Le payload sérialisé d'un job échoué peut porter
 * des données personnelles — celui d'une notification de décision contient
 * `requester_email` et le corps de la décision.
 *
 * La spec en désigne la mise en œuvre par `queue:prune-failed` : c'en est
 * l'équivalent exact — même table et même connexion, lues à la configuration
 * du fournisseur `database-uuids` (`queue.failed.*`), même prédicat
 * `failed_at < now − 14 jours` que `queue:prune-failed --hours=336` —, exécuté
 * par le moteur pour que la suppression soit bornée, comptée ligne à ligne et
 * lue par la sonde n° 4 au même prédicat. La commande du framework supprime
 * par paquets de 1 000 sans borne de lots ni compte par ligne.
 */
final class FrameworkFailedJobsHandler extends TablePurgeHandler
{
    public function scope(): PurgeScope
    {
        return PurgeScope::FrameworkFailedJobs;
    }

    protected function connectionName(): ?string
    {
        $connection = Config::get('queue.failed.database');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    protected function table(): string
    {
        return Config::string('queue.failed.table');
    }

    protected function pilotColumn(): string
    {
        return 'failed_at';
    }

    protected function keyColumn(): string
    {
        return 'id';
    }

    protected function cutoff(CarbonImmutable $asOf): CarbonImmutable
    {
        return $asOf->subDays(RetentionWindows::FAILED_JOBS_DAYS);
    }
}
