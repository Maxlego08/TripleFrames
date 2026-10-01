<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Theme;
use App\Models\User;

/**
 * Qui lit, crée, corrige et publie un thème — spec 20 § 2.9, ligne 28 de la
 * matrice des capacités (J1 depuis D43 du 01/10).
 *
 * Toutes les méthodes au seuil du curateur : les thèmes sont de la curation
 * de catalogue, jamais un geste qui engage le projet vis-à-vis d'un tiers.
 * **Aucun `Gate::before`** : l'admin passe parce qu'il est au-dessus du seuil.
 *
 * `create` ne voit pas la nature du thème : le refus de la nature
 * `difficulty`, livrée seule, est une erreur de validation de
 * `ThemeStoreRequest` (`admin.themes.kind_forbidden`), jamais un 403. Ni
 * `delete` ni `forceDelete` : aucune suppression de thème au J1, la
 * dépublication est le seul retrait.
 *
 * Vérifiée à chaque écriture par le middleware `can:` de chaque route, puis
 * relue sous verrou par l'action (`Gate::forUser()->authorize()`).
 */
class ThemePolicy
{
    /** L'écran des thèmes. */
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    /** Créer un thème, toute nature créable ou sans règle. */
    public function create(User $user): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    /** Corriger la règle, la négation, les libellés ou l'ordre ; jamais la nature ni la clé. */
    public function update(User $user, Theme $theme): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    /** Publier sous seuil, ou dépublier. */
    public function publish(User $user, Theme $theme): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }
}
