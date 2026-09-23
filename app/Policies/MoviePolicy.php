<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Movie;
use App\Models\User;

/**
 * Le seuil de lecture du catalogue, et lui seul.
 *
 * **Aucun `Gate::before`.** Le projet s'interdit nommément le raccourci qui
 * accorderait tout à un administrateur : il contournerait la propriété d'une
 * `saved_config`, déclarée strictement privée, et personne ne s'en apercevrait
 * avant la fuite. Chaque policy pose donc SON seuil par
 * {@see UserRole::atLeast()} — l'admin passe ici parce qu'il est *au-dessus* du
 * seuil de curation, jamais parce qu'il est admin.
 *
 * **Décision 9** : le curateur cure et publie ; l'admin seul modère et gère les
 * accès. Ce lot n'ouvre que la LECTURE, et les méthodes d'écriture sont
 * volontairement absentes — publier, dépublier, suspendre, retirer, corriger,
 * cocher « contenu vérifié » relèvent chacune d'un seuil que la spec 20 tranche
 * (curateur pour la publication, admin seul pour la suspension et le retrait
 * juridique). Écrire ici une méthode fantôme qu'aucune route n'appelle, c'est
 * décider à la place de la spec 20 sans que personne ne le voie passer.
 *
 * Découverte par convention `App\Models\Movie` → `App\Policies\MoviePolicy` :
 * aucun enregistrement de provider, aucun `#[UsePolicy]` posé sur un modèle
 * sanctuarisé.
 */
class MoviePolicy
{
    /**
     * La liste du catalogue.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    /**
     * La fiche d'un film — même seuil, et **aucune condition sur
     * `availability`**.
     *
     * Un film `withdrawn` ou `suspended` reste lisible : la spec 10 § 3.1 exige
     * que la fiche se suffise devant une mise en demeure, motif et horodatage
     * compris. Filtrer ici casserait exactement l'usage qui justifie les
     * colonnes `availability_reason` et `availability_changed_at`.
     */
    public function view(User $user, Movie $movie): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    /**
     * Refus sans condition : la création d'un film est un acte d'IMPORT, jamais
     * un geste d'écran. Elle passe par `MovieImporter`, sous le couvert de
     * {@see ImportRunPolicy::create()}.
     *
     * La méthode existe pour que le refus soit une propriété vérifiable par un
     * test, et non l'absence d'un chemin qu'un futur contrôleur rouvrirait sans
     * le savoir.
     */
    public function create(User $user): bool
    {
        return false;
    }
}
