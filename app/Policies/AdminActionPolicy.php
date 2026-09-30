<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Admin\AdminJournal;

/**
 * Qui lit le journal d'administration — spec 20 § 2.2, ligne 41 (D41 du
 * 30/09).
 *
 * **Administrateur seul.** Le journal nomme chaque auteur par son nom réel,
 * trace les consultations de l'annuaire et garde le texte de ce qu'un geste a
 * détruit : il sert à savoir qui fait quoi dans le back-office, et c'est
 * précisément ce qu'un curateur n'a pas à lire sur ses pairs.
 *
 * **Aucune écriture** : le journal s'écrit par son seul écrivain
 * ({@see AdminJournal}), jamais par une route. Ni `create`,
 * ni `update`, ni `delete` — le `Gate` refuse par défaut ce que cette classe ne
 * nomme pas (ligne 37 de la matrice : aucune route ne supprime une ligne).
 *
 * Découverte par convention `App\Models\AdminAction` →
 * `App\Policies\AdminActionPolicy`.
 */
class AdminActionPolicy
{
    /**
     * L'écran « Journal » et ses filtres.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }
}
