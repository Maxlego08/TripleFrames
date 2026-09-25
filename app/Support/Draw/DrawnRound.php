<?php

namespace App\Support\Draw;

/**
 * Une manche tirée au lancement (spec 30 § 6.3, contrat C3).
 *
 * `sequenceIndex` est la position dans le tirage figé, `1..K`, unique par
 * partie (`round_game_sequence_uq`) et connue **avant** l'insertion des lignes :
 * c'est elle qui nomme les contextes de la graine, jamais un identifiant de
 * base. `roundNumber` vaut `sequenceIndex` pour les `M` premières manches et
 * **nul pour une manche de réserve** (E10-45), qui ne reçoit le numéro de la
 * manche qu'elle remplace qu'au remplacement (spec 60).
 *
 * Jamais sérialisé : `movieId` est la bonne réponse de la manche (règle 3).
 */
final readonly class DrawnRound
{
    /**
     * @param  int  $sequenceIndex  `s`, de 1 à `K`.
     * @param  int|null  $roundNumber  `s` si `s ≤ M`, nul pour la réserve.
     * @param  int  $movieId  Film retenu, `movie.id`.
     * @param  list<DrawnTier>  $tiers  Les `N` paliers, par `tierIndex` croissant.
     */
    public function __construct(
        public int $sequenceIndex,
        public ?int $roundNumber,
        public int $movieId,
        public array $tiers,
    ) {}
}
