<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Qui lit l'écran « Audience » — spec 20 § 12.4, ligne 44 de la matrice des
 * capacités (D48 du 01/10).
 *
 * **Administrateur seul**, comme « Performances » : des agrégats sans donnée
 * personnelle, mais qui décrivent le pilotage du site. Aucune méthode
 * d'écriture.
 *
 * Découverte par convention `App\Models\AudienceDaily` → `App\Policies\AudienceDailyPolicy`.
 */
class AudienceDailyPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }
}
