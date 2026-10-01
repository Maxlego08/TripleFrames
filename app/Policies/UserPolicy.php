<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Qui lit l'annuaire des comptes et qui gère les accès — spec 20 § 2.8 et
 * § 2.9, lignes 34 et 40 de la matrice des capacités.
 *
 * **Administrateur seul, partout.** Gérer les accès est l'un des gestes qui
 * « engagent le projet » (décision 9) : un curateur cure, il ne nomme
 * personne. L'annuaire est au même seuil, parce qu'il montre l'adresse de
 * chaque compte.
 *
 * **Aucun `Gate::before`** : l'administrateur passe ici parce qu'il est au
 * seuil `admin`, jamais parce qu'un raccourci lui ouvrirait tout — ce même
 * raccourci lui donnerait la `saved_config` d'un tiers, strictement privée.
 *
 * Aucune méthode `update` ni `delete` : le `Gate` refuse par défaut ce que
 * cette classe ne nomme pas. Supprimer ou anonymiser un compte appartient au
 * cycle de vie du compte (`40`), jamais à cet écran.
 *
 * Découverte par convention `App\Models\User` → `App\Policies\UserPolicy`.
 */
class UserPolicy
{
    /**
     * L'annuaire des comptes et l'écran de gestion des accès.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }

    /**
     * La fiche d'un compte, en lecture seule — pierre tombale comprise : ce
     * qu'une anonymisation a conservé (revues signées, lignes du journal) se
     * lit toujours.
     */
    public function view(User $actor, User $target): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }

    /**
     * Attribuer ou retirer `curator` ou `admin`. Une pierre tombale n'a plus
     * de rôle à recevoir : la promouvoir rendrait un compte privilégié sans
     * adresse ni mot de passe.
     *
     * Les refus d'état — son propre rôle, rôle inchangé, adresse non vérifiée,
     * nom réel manquant, dernier administrateur — sont des erreurs traduites
     * relues sous verrou par l'action, jamais des 403.
     */
    public function updateRole(User $actor, User $target): bool
    {
        return $actor->role->atLeast(UserRole::Admin)
            && $target->anonymized_at === null;
    }

    /**
     * Corriger le nom réel d'un compte privilégié non anonymisé : le nom réel
     * n'existe que pour ces comptes (D12 du 23/09), et c'est lui qui signe les
     * preuves.
     */
    public function updateRealName(User $actor, User $target): bool
    {
        return $actor->role->atLeast(UserRole::Admin)
            && $target->anonymized_at === null
            && $target->role->atLeast(UserRole::Curator);
    }

    /**
     * L'écran « Avatars » (ligne 45, spec 40 § 11.7, D49 du 01/10) :
     * administrateur seul, comme toute modération.
     */
    public function moderateAvatars(User $actor): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }

    /**
     * Voir, lever ou retirer l'image téléversée d'un compte.
     */
    public function moderateAvatar(User $actor, User $target): bool
    {
        return $actor->role->atLeast(UserRole::Admin);
    }
}
