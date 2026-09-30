<?php

namespace App\Policies;

use App\Models\SavedConfig;
use App\Models\User;

/**
 * **La policy qui prouve l'absence de `Gate::before`.**
 *
 * Une configuration de salon sauvegardée est STRICTEMENT privée : elle
 * n'appartient qu'à son compte, et un administrateur n'y accède pas. Il n'y a
 * ni colonne de visibilité, ni jeton de partage, ni table de partage — la
 * propriété est le seul critère, et c'est pourquoi `$user->role` n'est **jamais
 * lu dans ce fichier**. Une seule ligne de rôle ici, et la garantie tombe.
 *
 * C'est la raison nommée par `00-overview.md` pour interdire tout
 * `Gate::before` global : un raccourci « l'admin peut tout » ferait passer ces
 * trois méthodes sans jamais les exécuter, silencieusement, et aucun test
 * n'échouerait — d'où le test `tests/Feature/Admin/AuthorizationTest.php`, qui
 * assure qu'un `UserRole::Admin` est REFUSÉ sur la configuration d'un tiers.
 *
 * Aucun écran du lot « début de panel admin » n'appelle cette policy : elle est
 * livrée quand même, précisément pour que le garde-fou existe avant l'écran qui
 * en aura besoin.
 */
class SavedConfigPolicy
{
    /**
     * Lire une configuration : la posséder.
     */
    public function view(User $user, SavedConfig $savedConfig): bool
    {
        return $savedConfig->user_id === $user->id;
    }

    /**
     * La modifier : la posséder. Vérifié à CHAQUE écriture, jamais seulement à
     * l'affichage du formulaire — un formulaire n'est qu'une suggestion, la
     * requête d'écriture est le geste.
     */
    public function update(User $user, SavedConfig $savedConfig): bool
    {
        return $savedConfig->user_id === $user->id;
    }

    /**
     * La supprimer : la posséder.
     */
    public function delete(User $user, SavedConfig $savedConfig): bool
    {
        return $savedConfig->user_id === $user->id;
    }
}
