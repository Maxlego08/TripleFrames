<?php

namespace App\Support\Eloquent;

use App\Models\AdminAction;
use App\Models\FrameReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Le constructeur de requêtes des tables en AJOUT SEUL — `admin_action` et
 * `frame_review`.
 *
 * Les deux modèles portent déjà une garde d'écriture sur un événement de modèle
 * (`updating` pour {@see AdminAction}, `saving` pour
 * {@see FrameReview}), mais **aucun événement de modèle n'est émis par
 * une mise à jour de masse** : `AdminAction::where(...)->update([...])` passe sans
 * rien lever.
 *
 * Ce n'est pas une hypothèse d'école. L'action d'anonymisation de compte s'écrit
 * naturellement `AdminAction::where('actor_id', $user->id)->update(['actor_name' =>
 * …])`, alors que `actor_name` est nommément EXCLUE de l'anonymisation (§ 8.3) :
 * l'auteur d'un retrait juridique redeviendrait anonyme et la preuve perdrait sa
 * valeur, sans erreur ni trace. Symétriquement, une passe de re-revue écrite
 * `FrameReview::where('frame_id', $id)->update(['grid_version' => …])` ferait passer
 * pour revues sous une grille qu'aucun curateur n'a exercée des images que personne
 * n'a regardées.
 *
 * **Portée exacte de la garde** : elle couvre Eloquent, et Eloquent seul. Un
 * `DB::table('admin_action')->update(...)` ne la rencontre jamais — comme aucun
 * `$dateFormat` ne s'applique à un `DB::table()` (§ 1.2). L'immuabilité reste
 * outillée, jamais garantie par un déclencheur SQL.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class AppendOnlyBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): never
    {
        throw new LogicException(
            'La table ['.$this->getModel()->getTable().'] est en ajout seul : une mise à jour de masse y est refusée.',
        );
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw new LogicException(
            'La table ['.$this->getModel()->getTable().'] est en ajout seul : un `upsert` y est refusé.',
        );
    }
}
