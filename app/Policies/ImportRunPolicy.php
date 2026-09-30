<?php

namespace App\Policies;

use App\Enums\ImportRunStatus;
use App\Enums\UserRole;
use App\Models\ImportRun;
use App\Models\User;

/**
 * Le journal de provenance des balayages TMDB : qui lit, qui lance, qui
 * reprend — et ce que personne ne supprime.
 *
 * Même règle qu'ailleurs : **aucun `Gate::before`**, le seuil est posé ici par
 * {@see UserRole::atLeast()}. Décision 9 : le curateur importe. Le filtre de
 * CONTENU n'est pas une affaire de rôle — il n'est contournable par personne,
 * administrateur compris — et n'a donc rien à faire dans cette policy.
 */
class ImportRunPolicy
{
    /**
     * L'historique des balayages.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    /**
     * Le détail d'un balayage — même seuil, **sans condition sur `status` ni
     * sur `actor_id`** : un curateur lit le balayage d'un autre curateur, c'est
     * le sens même d'un journal de provenance. Un film entré par exception doit
     * rester auditable jusqu'au filtre exact qui l'a laissé passer
     * (décision 11), y compris quand l'auteur du balayage a supprimé son
     * compte.
     */
    public function view(User $user, ImportRun $importRun): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    /**
     * Ouvrir un balayage, par l'une ou l'autre voie — `admin.import.discover`
     * et `admin.import.ids` partagent ce seuil unique.
     */
    public function create(User $user): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    /**
     * Reprendre un balayage suspendu.
     *
     * Le second terme n'est pas cosmétique : reprendre un balayage `completed`
     * relancerait un curseur déjà consommé et fausserait les quatre compteurs,
     * qui sont la preuve opposable de ce qu'un balayage a fait. La garde vit
     * ici et non dans un contrôleur, de sorte que toute écriture — route
     * actuelle, commande future, écran de la spec 20 — la rencontre.
     */
    public function update(User $user, ImportRun $importRun): bool
    {
        return $user->role->atLeast(UserRole::Curator)
            && $importRun->status === ImportRunStatus::Running;
    }

    /**
     * Refus sans condition : `import_run` est en périmètre **interdit** de la
     * purge (spec 10 § 2). Un journal de provenance ne se supprime pas depuis
     * un écran, et pas davantage depuis une console.
     */
    public function delete(User $user, ImportRun $importRun): bool
    {
        return false;
    }
}
