<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Game;
use App\Models\User;

/**
 * Qui inspecte les parties — spec 20 § 12.2, ligne 36 de la matrice des
 * capacités (D46 du 01/10).
 *
 * **Administrateur seul** : la fiche d'une partie expose pseudos, scores et
 * réponses soumises, donc des données personnelles de joueurs (décision
 * « Observabilité » du 22/09). Le curateur n'y a jamais accès.
 *
 * Aucune méthode d'écriture : le `Gate` refuse par défaut ce que cette classe
 * ne nomme pas, et aucune route n'écrit dans une partie depuis le back-office.
 *
 * Découverte par convention `App\Models\Game` → `App\Policies\GamePolicy`.
 */
class GamePolicy
{
    /**
     * La liste des parties, en cours et terminées.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }

    /**
     * La fiche d'une partie — sans rien d'une manche non révélée d'une partie
     * en cours (règle 3), tenu par le presenter et non par la policy.
     */
    public function view(User $actor, Game $game): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }
}
