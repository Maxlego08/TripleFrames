<?php

namespace App\Support\Draw;

/**
 * Un film du vivier, réduit à ce que le tirage lit (spec 30 § 3.1, contrat C2).
 *
 * Produit par {@see PoolQuery::candidates()} seul, trié par `movieId` croissant.
 * `groupId` porte l'unité de compte — l'œuvre (§ 3.4) : deux candidats d'un même
 * `movie_group` ne forment qu'une œuvre, et le tirage n'en retient qu'un.
 * `levelsMask` est le masque de la **projection**, lu pour information : le
 * tirage recalcule le masque réel depuis les variantes chargées et saute une
 * œuvre dont la projection est périmée (§ 6.3).
 *
 * Aucun de ces identifiants ne quitte le serveur : la classe n'est ni
 * `Arrayable` ni `JsonSerializable` (§ 5.5, règle 3).
 */
final readonly class PoolCandidate
{
    /**
     * @param  int  $movieId  `movie.id`.
     * @param  int|null  $groupId  `movie.group_id` ; nul pour un film qui est à lui seul une œuvre.
     * @param  int  $levelsMask  `movie_projection.levels_mask`, un bit par `FrameLevel::bit()`.
     */
    public function __construct(
        public int $movieId,
        public ?int $groupId,
        public int $levelsMask,
    ) {}
}
