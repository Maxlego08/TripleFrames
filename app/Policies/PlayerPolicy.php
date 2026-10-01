<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Player;
use App\Models\User;

/**
 * Qui inspecte les sièges — spec 20 § 12.2, ligne 42 de la matrice des
 * capacités (D46 du 01/10).
 *
 * **Administrateur seul**, au même seuil que l'inspection d'une partie : un
 * siège porte un pseudo, ses scores et ses réponses soumises.
 *
 * Ni l'hôte d'un salon ni un joueur ne passent par cette classe : les gestes
 * du jeu (prise de siège, expulsion, réponse) ont leurs propres gardes
 * (`seat.active`, actions du salon), jamais une policy de modèle.
 *
 * Découverte par convention `App\Models\Player` → `App\Policies\PlayerPolicy`.
 */
class PlayerPolicy
{
    /**
     * L'annuaire des sièges, invités compris.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }

    /**
     * La fiche d'un siège.
     */
    public function view(User $actor, Player $player): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }
}
