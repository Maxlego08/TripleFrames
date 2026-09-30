<?php

namespace App\Support\Draw;

use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Round;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le « film suivant du tirage » (spec 30 § 8.2, contrat C3) : la manche de
 * réserve qui remplace une manche annulée.
 *
 * La **règle de choix** appartient à cette spec ; l'annulation
 * (`RoundIncidentReason`), la numérotation — la remplaçante reçoit le
 * `round_number` de la manche annulée — et la programmation appartiennent à la
 * spec 60 (§ 8.3).
 *
 * Aucune graine, aucune mémoire, aucune écriture, aucun verrou : la réserve est
 * déjà tirée et figée au lancement, dans l'ordre de `sequence_index`. Le solo
 * s'y plie à l'identique (§ 9).
 */
final readonly class ReplacementRoundChooser
{
    /**
     * La première manche de la partie, par `sequence_index` croissant, qui a
     * `round_number IS NULL`, `status = pending`, `started_at IS NULL`, **et
     * dont le film satisfait encore {@see Movie::inPool()}** — un film de
     * réserve peut avoir été suspendu, retiré ou bloqué entre le lancement et
     * son usage : le prendre recréerait l'incident qu'on remplace.
     *
     * Un palier devenu non servable dans une manche de réserve n'est pas
     * examiné ici : il passe par {@see VariantChooser::substitute()} à la
     * frappe, comme toute manche.
     *
     * Une lecture, servie par `round_game_sequence_uq (game_id, sequence_index)`.
     *
     * @return Round|null La remplaçante, ou `null` : réserve épuisée, la partie
     *                    continue avec une manche de moins (`00` § Réglages du
     *                    salon, borne croisée 3).
     */
    public function next(Game $game): ?Round
    {
        return Round::query()
            ->where('game_id', $game->id)
            ->whereNull('round_number')
            ->where('status', RoundStatus::Pending->value)
            ->whereNull('started_at')
            ->whereHas('movie', static function (Builder $movie): void {
                /** @var Builder<Movie> $movie */
                $movie->inPool();
            })
            ->orderBy('sequence_index')
            ->first();
    }
}
