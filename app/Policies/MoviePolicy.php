<?php

namespace App\Policies;

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\UserRole;
use App\Models\Movie;
use App\Models\User;

/**
 * Qui lit, cure, publie et dépublie un film — la table du § 2.9 de la spec 20,
 * méthodes du jalon 1.
 *
 * **Aucun `Gate::before`.** Le projet s'interdit nommément le raccourci qui
 * accorderait tout à un administrateur : il contournerait la propriété d'une
 * `saved_config`, déclarée strictement privée, et personne ne s'en apercevrait
 * avant la fuite. Chaque méthode pose donc SON seuil par
 * {@see UserRole::atLeast()} — l'admin passe ici parce qu'il est *au-dessus* du
 * seuil de curation, jamais parce qu'il est admin.
 *
 * **Décision 9** : la ligne de partage n'est pas « qui touche au catalogue »
 * mais « qui engage le projet ». Le curateur importe, cure, publie et dépublie ;
 * l'admin seul suspend et prononce un retrait juridique. Ces deux gestes, et la
 * resynchronisation, sont des méthodes du jalon 2 (`resync`, `suspend`,
 * `unsuspend`, `withdraw`) : elles arrivent avec les lots qui livrent leurs
 * routes (L20-20, L20-21, L20-24), jamais avant — une méthode qu'aucune route
 * n'appelle serait une décision que personne ne voit passer.
 *
 * **Vérifiée à chaque écriture** : chaque route du back-office la nomme par son
 * middleware `can:`, et le jeu `tests/Datasets/AdminRoutes.php` prouve que
 * toutes le font. Les booléens `abilities` envoyés aux écrans ne servent qu'à
 * masquer un bouton : ils n'autorisent rien.
 *
 * Découverte par convention `App\Models\Movie` → `App\Policies\MoviePolicy` :
 * aucun enregistrement de provider, aucun `#[UsePolicy]` posé sur un modèle
 * sanctuarisé.
 */
class MoviePolicy
{
    /**
     * La liste du catalogue, la file de curation, le débit du pilote.
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
     * le savoir. C'est aussi pourquoi la garde d'ajout d'une image nomme la
     * classe `Frame` en premier argument : écrite `can:create,movie`, elle
     * tomberait ici et toute la voie TMDB répondrait 403 (C9, V-17).
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Curer : l'éditeur de la banque d'images, les titres, les alias, le
     * regroupement `movie_group` et le battement de débit.
     *
     * Tout état sauf `withdrawn`, terminal : un film retiré se lit, il ne se
     * travaille plus.
     */
    public function curate(User $user, Movie $movie): bool
    {
        return $user->role->atLeast(UserRole::Curator)
            && $movie->availability !== ContentAvailability::Withdrawn;
    }

    /**
     * Publier un brouillon, ou republier un film dépublié.
     *
     * Seuls `draft` et `unpublished` en partent : publier un film suspendu
     * lèverait une suspension par la bande, geste réservé à l'admin
     * (`unsuspend`, J2). La couverture 1/3/5 et les autres conditions de
     * publication ne sont pas une affaire de rôle : l'action les vérifie sous
     * verrou et les refuse en erreur traduite, jamais en 403.
     */
    public function publish(User $user, Movie $movie): bool
    {
        return $user->role->atLeast(UserRole::Curator)
            && in_array($movie->availability, [ContentAvailability::Draft, ContentAvailability::Unpublished], true);
    }

    /**
     * Dépublier un film publié, ou écarter un brouillon.
     *
     * Jamais depuis `suspended` ni `withdrawn` : la dépublication de curation
     * écraserait l'état qu'un administrateur a posé.
     */
    public function unpublish(User $user, Movie $movie): bool
    {
        return $user->role->atLeast(UserRole::Curator)
            && in_array($movie->availability, [ContentAvailability::Draft, ContentAvailability::Published], true);
    }

    /**
     * Cocher « contenu vérifié, pas de classification restrictive ».
     *
     * Seul `unrated_pending` s'y prête : `blocked` ne se lève par aucune voie ni
     * aucun rôle (décision 12), et `clear` n'a rien à vérifier.
     */
    public function verifyContent(User $user, Movie $movie): bool
    {
        return $user->role->atLeast(UserRole::Curator)
            && $movie->content_flag === ContentFlag::UnratedPending
            && $movie->availability !== ContentAvailability::Withdrawn;
    }

    /**
     * Refus sans condition : un film n'est jamais supprimé (spec 10 § 3.1). Le
     * retrait juridique lui-même garde la ligne, et son `tmdb_id` unique est à
     * lui seul le blocage de réimport.
     */
    public function delete(User $user, Movie $movie): bool
    {
        return false;
    }
}
