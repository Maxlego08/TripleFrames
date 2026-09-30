<?php

namespace App\ValueObjects\Scoring;

/**
 * La place d'un siège au classement (spec 80 § 8.2, contrat C13 § 2.2) : ses
 * totaux, son rang de compétition et le drapeau de place partagée.
 *
 * `rank` est nul pour un siège qui n'a joué aucune manche (`roundsPlayed = 0`)
 * et, en solo, pour tout siège : c'est l'appelant qui connaît le mode de la
 * partie et force alors `rank = null`, `shared = false` (§ 8.2).
 * `shared` n'est vrai que pour un rang non nul porté par au moins un autre
 * siège, égal au sien sur toute la chaîne de départage.
 */
final readonly class Standing
{
    public function __construct(
        public PlayerTally $tally,
        public ?int $rank,
        public bool $shared,
    ) {}
}
