<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\FrameReview;
use App\Models\User;

/**
 * La preuve de revue d'une image : on la passe, on ne la touche plus —
 * contrat C14-bis, spec 20 § 7.8.
 *
 * Troisième pièce de l'immuabilité de `frame_review`, avec l'absence
 * d'`updated_at` (`#[WithoutTimestamps]`) et la garde `saving` du modèle,
 * doublée de `AppendOnlyBuilder` : aucune route ne modifie ni ne supprime une
 * revue, et cette policy dit pourquoi aucune n'en aura jamais le droit. Une
 * revue erronée se corrige par une revue SUIVANTE sur les mêmes octets, jamais
 * en réécrivant la précédente.
 *
 * **Aucun `Gate::before`** : le seuil est posé par {@see UserRole::atLeast()},
 * et le refus de `update` et `delete` vaut pour l'admin comme pour tous.
 *
 * Découverte par convention `App\Models\FrameReview` →
 * `App\Policies\FrameReviewPolicy`.
 */
class FrameReviewPolicy
{
    /**
     * Passer une revue, et lire la file de revue qui y mène.
     */
    public function create(User $user): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    /**
     * Refus sans condition : une preuve ne se réécrit pas.
     */
    public function update(User $user, FrameReview $frameReview): bool
    {
        return false;
    }

    /**
     * Refus sans condition : une preuve ne se supprime pas, et une frame n'est
     * jamais supprimée, donc sa preuve n'est jamais orpheline.
     */
    public function delete(User $user, FrameReview $frameReview): bool
    {
        return false;
    }
}
