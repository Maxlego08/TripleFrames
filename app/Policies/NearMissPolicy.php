<?php

namespace App\Policies;

use App\Enums\ContentAvailability;
use App\Enums\UserRole;
use App\Models\NearMiss;
use App\Models\User;

/** Autorisations de la file agrégée de suggestions d'alias. */
final class NearMissPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    public function promote(User $user, NearMiss $nearMiss): bool
    {
        return $user->role->atLeast(UserRole::Curator)
            && $nearMiss->movie->availability !== ContentAvailability::Withdrawn;
    }

    public function dismiss(User $user, NearMiss $nearMiss): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }
}
