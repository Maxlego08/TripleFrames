<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Qui lit l'écran « Performances » — spec 20 § 12.3, ligne 43 de la matrice
 * des capacités (D47 du 01/10).
 *
 * **Administrateur seul** : l'écran ne montre aucune donnée personnelle,
 * mais il décrit l'exploitation (routes, jobs, SQL), qui n'est pas l'affaire
 * du curateur. Aucune méthode d'écriture : les échantillons ne s'écrivent que
 * par la mesure, et ne partent que par la purge.
 *
 * Découverte par convention `App\Models\PerfSample` → `App\Policies\PerfSamplePolicy`.
 */
class PerfSamplePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }
}
