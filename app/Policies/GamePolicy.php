<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Game;
use App\Models\User;
use App\Support\Account\PlayHistory;
use Illuminate\Auth\Access\Response;

/**
 * Qui inspecte les parties — spec 20 § 12.2, ligne 36 de la matrice des
 * capacités (D46 du 01/10).
 *
 * **Administrateur seul** : la fiche d'une partie expose pseudos, scores et
 * réponses soumises, donc des données personnelles de joueurs (décision
 * « Observabilité » du 22/09). Le curateur n'y a jamais accès.
 *
 * **Exception joueur** : {@see self::viewHistory()}, le détail d'une partie
 * dans l'historique de son titulaire (spec 40 § 13.4), quel que soit le rôle.
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
     * L'écran « Statistiques de jeu » (spec 20 § 12.6, ligne 51) : des
     * agrégats sans aucune donnée personnelle, administrateur seul comme
     * l'audience.
     */
    public function viewStats(User $actor): bool
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

    /**
     * Le détail d'une partie dans « Mes parties » (spec 40 § 13.4, n° 30) :
     * un siège du compte dans la partie, partie gelée dans la fenêtre et au
     * moins une manche jouée ({@see PlayHistory::seatFor()}). Tout autre cas —
     * partie d'un autre compte, hors fenêtre, non gelée — répond **404**,
     * jamais 403 : la réponse n'apprend pas qu'une partie existe. Aucun rôle
     * n'y déroge, l'administrateur compris (il inspecte au back-office).
     */
    public function viewHistory(User $actor, Game $game): Response
    {
        return app(PlayHistory::class)->seatFor($actor, $game) !== null
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
