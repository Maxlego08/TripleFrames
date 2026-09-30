<?php

namespace Tests\Support\Realtime;

use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;

/**
 * Une partie multijoueur en cours, telle que les charges du fil la décrivent
 * ({@see WireFixtures::scene()}) : un salon, son hôte et un second siège, une
 * manche 1 révélée où l'hôte a trouvé, une manche 2 programmée, et une
 * manche 3 dont le QCM est composé pour le second siège.
 */
final readonly class WireScene
{
    public function __construct(
        public Room $room,
        public Game $game,
        public Player $host,
        public Player $guest,
        public Round $revealed,
        public Round $scheduled,
        public Round $running,
        public RoundPlayer $guestChoices,
    ) {}
}
