<?php

namespace App\Support\ContentReport;

use App\Enums\ContentReportResolution;
use App\Enums\ContentReportStatus;
use App\Models\ContentReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * La clôture des signalements OUVERTS d'une cible (D63 du 07/10) : `status`,
 * `resolution`, `resolved_at` et `resolved_by_id` écrits ensemble, sous
 * verrou, dans la transaction du geste qui la motive. Idempotente : un
 * signalement déjà clos n'est jamais réécrit.
 */
final class ContentReportClosure
{
    /**
     * Clôt les signalements ouverts que `$scope` restreint, et rend leurs
     * identifiants, du plus ancien au plus récent.
     *
     * @param  callable(Builder<ContentReport>): mixed  $scope
     * @return list<int>
     *
     * @throws LogicException hors transaction
     */
    public static function close(callable $scope, ContentReportResolution $resolution, User $curator): array
    {
        $query = ContentReport::query()->where('status', ContentReportStatus::Open->value);
        $scope($query);

        if ($query->getConnection()->transactionLevel() === 0) {
            throw new LogicException('ContentReportClosure : clôture hors transaction.');
        }

        $ids = array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            $query->orderBy('id')->lockForUpdate()->pluck('id')->all(),
        ));

        if ($ids === []) {
            return [];
        }

        $now = CarbonImmutable::now();

        ContentReport::query()->whereKey($ids)->update([
            'status' => $resolution->status()->value,
            'resolution' => $resolution->value,
            'resolved_at' => $now,
            'resolved_by_id' => $curator->id,
            'updated_at' => $now,
        ]);

        return $ids;
    }
}
