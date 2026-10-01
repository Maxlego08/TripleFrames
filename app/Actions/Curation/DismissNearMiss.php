<?php

namespace App\Actions\Curation;

use App\Models\NearMiss;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Écarte une suggestion sans lui rattacher un auteur. */
final class DismissNearMiss
{
    /**
     * @throws AuthorizationException
     * @throws Throwable
     */
    public function handle(NearMiss $nearMiss, User $curator): void
    {
        DB::transaction(static function () use ($nearMiss, $curator): void {
            $locked = NearMiss::query()->whereKey($nearMiss->id)->lockForUpdate()->firstOrFail();
            $locked->load('movie');

            Gate::forUser($curator)->authorize('dismiss', $locked);

            if ($locked->dismissed_at === null) {
                $locked->dismissed_at = now();
                $locked->save();
            }
        });
    }
}
