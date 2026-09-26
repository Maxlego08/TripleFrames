<?php

namespace App\Events\Game;

use App\Actions\Game\FinalizeGame;
use App\Enums\GameStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * La partie vient d'être gelée — spec 80 § 10.7, contrat C13 § 2.3 (nom et
 * signature figés).
 *
 * Événement **de domaine**, jamais diffusé : il porte un identifiant interne
 * (`game.id`), qui ne quitte pas le serveur. Émis par {@see FinalizeGame},
 * seulement quand CET appel a gelé la partie (`handle()` rend `true`), et
 * livré **après commit** (`ShouldDispatchAfterCommit`) : un gel annulé avec sa
 * transaction n'annonce rien, et un appelant qui gèle dans sa propre
 * transaction (annulation sans manche restante, relance d'un solo) ne
 * l'annonce qu'une fois tout validé.
 *
 * C'est l'**unique déclencheur** des effets de fin : l'émission de
 * `game.ended` par l'écouteur de 60, le cache des compteurs de 40 (J2). Il ne
 * ramène PAS le salon au lobby : le salon reste `playing`, podium compris,
 * jusqu'au « Rejouer » de l'hôte, que `ended_at` non nul rend possible. Ses
 * écouteurs sont idempotents et tolèrent une partie déjà purgée — une clôture
 * `stale_game` est suivie de la purge dans la même passe.
 */
final class GameFinalized implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $gameId,
        public readonly GameStatus $outcome,
    ) {}
}
