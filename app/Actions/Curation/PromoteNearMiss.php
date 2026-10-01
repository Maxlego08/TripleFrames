<?php

namespace App\Actions\Curation;

use App\Enums\Locale;
use App\Models\Alias;
use App\Models\NearMiss;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Transforme une suggestion agrégée en alias exact, après choix de locale. */
final readonly class PromoteNearMiss
{
    public function __construct(private AddAlias $aliases) {}

    /**
     * @throws AuthorizationException
     * @throws Throwable
     */
    public function handle(NearMiss $nearMiss, User $curator, Locale $locale, string $text): Alias
    {
        return DB::transaction(function () use ($nearMiss, $curator, $locale, $text): Alias {
            $locked = NearMiss::query()->whereKey($nearMiss->id)->lockForUpdate()->firstOrFail();
            $locked->load('movie');

            Gate::forUser($curator)->authorize('promote', $locked);

            $alias = $this->aliases->handle($locked->movie, $curator, $locale, $text);
            $locked->delete();

            return $alias;
        });
    }
}
