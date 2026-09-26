<?php

namespace App\ValueObjects\Scoring;

use App\Enums\GamePlayerStatus;
use App\Support\Scoring\Ranking;
use App\Support\Scoring\Scoreboard;

/**
 * Les totaux d'un siège dans une partie, sous une portée de lecture du score
 * (spec 80 § 8.1, contrat C13 § 2.2) : ce que la chaîne de départage compare,
 * et rien d'autre.
 *
 * **Jamais sérialisé tel quel.** `gamePlayerId` est un identifiant interne,
 * qui ne sert qu'au tri stable des sièges à égalité (§ 8.2) : les charges
 * désignent un siège par `publicId` seul (10 § 1.1), et
 * {@see Scoreboard} compose chaque bloc champ par champ.
 *
 * Toutes les sommes portent sur les mêmes manches, celles de la portée lue
 * (`ScoreScope`) : `roundsPlayed` compte les lignes `round_player` du siège,
 * `correctAnswers` ses lignes `guess`, `score` la somme de leurs
 * `points_total`, `totalAnswerTimeMs` la somme BRUTE de leurs
 * `answered_at_ms` (§ 8.4), et `findsByTier` leurs `tier_index` — plancher du
 * QCM compris, puisque c'est le palier écrit au verrouillage. La somme des
 * trouvailles vaut donc toujours `correctAnswers`.
 *
 * Aucune garde ici : la cohérence d'un ensemble de totaux (clés `1..N`, somme
 * des trouvailles) dépend de `N`, que seul {@see Ranking} reçoit, et c'est lui
 * qui la vérifie.
 */
final readonly class PlayerTally
{
    /**
     * @param  int  $gamePlayerId  Interne : tri stable seulement, jamais exposé.
     * @param  string  $publicId  `player.public_id`, seule désignation publique du siège.
     * @param  GamePlayerStatus  $status  Issue figée du siège (`game_player.status`).
     * @param  int|null  $firstRoundNumber  Manche d'entrée du siège (`game_player.first_round_number`, 1 au lancement, plus grande pour un retardataire).
     * @param  int  $roundsPlayed  Lignes `round_player` du siège dans la portée.
     * @param  int  $correctAnswers  Lignes `guess` du siège dans la portée.
     * @param  int  $score  `Σ points_total` dans la portée.
     * @param  int  $totalAnswerTimeMs  `Σ answered_at_ms` BRUT dans la portée.
     * @param  array<int, int>  $findsByTier  Trouvailles par palier retenu, clés `1..N`.
     */
    public function __construct(
        public int $gamePlayerId,
        public string $publicId,
        public GamePlayerStatus $status,
        public ?int $firstRoundNumber,
        public int $roundsPlayed,
        public int $correctAnswers,
        public int $score,
        public int $totalAnswerTimeMs,
        public array $findsByTier,
    ) {}
}
