<?php

namespace App\Jobs\Curation;

use App\Support\Curation\NearMissAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Reconstruit périodiquement la file anonyme de suggestions d'alias. */
final class AggregateNearMisses implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(NearMissAggregator $aggregator): void
    {
        $aggregator->refresh();
    }
}
