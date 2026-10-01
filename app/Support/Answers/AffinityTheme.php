<?php

namespace App\Support\Answers;

/**
 * Un thème qui forme un groupe d'affinité des leurres (spec 70 § 10.3 bis,
 * D44 du 01/10) : son palier (1 = studio, saga ou manuel ; 2 = genre), sa clé
 * et son rang de tri pour le départage, et l'ensemble de ses membres actifs,
 * catalogue entier, croisé ensuite avec les candidats du mode.
 *
 * Interne à {@see DecoyPicker} : jamais sérialisé, jamais hors du serveur.
 */
final readonly class AffinityTheme
{
    /**
     * @param  array<int, true>  $members  `movie.id` des membres actifs.
     */
    public function __construct(
        public int $tier,
        public string $key,
        public int $sortOrder,
        public array $members,
    ) {}
}
