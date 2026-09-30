<?php

namespace App\Support\Answers;

use App\Actions\Game\CatchUpGame;
use App\Actions\Game\RevealRound;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Round;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * La fenêtre d'acceptation d'une soumission — spec 70 § 7.3, contrat C10 § 4
 * (R-20). **Seule écriture** du prédicat, lue par la soumission texte à
 * l'étape S3 et, sous le verrou `round`, par la transaction de verrouillage
 * et le clic (lots L70-6 et L70-9).
 *
 * Une soumission est **recevable** si et seulement si :
 *
 * - `round.status = 'running'` — elle le reste de `T₁` jusqu'à
 *   {@see RevealRound}, qui la passe `revealing` à `ended_at + tier_grace_ms` ;
 * - et `0 ≤ answeredAtMs` : l'instant de réception n'est pas antérieur à
 *   `round.started_at`, comparé sans troncature — une fraction de
 *   milliseconde avant `T₁` n'est pas le début de la manche ;
 * - et `receivedAt < (round.ended_at ?? round.started_at + round.duration_ms)
 *   + game.tier_grace_ms`.
 *
 * L'égalité de `round.sequence_index` avec la manche annoncée par le client
 * n'est pas testée ici : l'appelant lit la manche PAR ce numéro.
 *
 * **Pourquoi jusqu'à `D + tier_grace_ms`** : une réponse tapée à `D − 100 ms`
 * et reçue à `D + 150 ms` est honnête, et la grâce absorbe ce hasard réseau
 * (principe 4). Aucune fuite : les titres ne partent qu'à `ended_at +
 * tier_grace_ms`, l'instant même où la fenêtre se ferme. **`ended_at` d'abord**
 * : après une fin anticipée, la fenêtre se referme `tier_grace_ms` après
 * l'événement déclencheur, jamais à `D`.
 *
 * Les deux bornes se complètent : {@see CatchUpGame}, appelé à l'instant de
 * réception avant toute lecture, passe la manche `revealing` dès que
 * `receivedAt ≥ ended_at + tier_grace_ms` ; la borne haute tient seule quand
 * la révélation n'a pas encore été appliquée. Aucune valeur déclarée par le
 * client n'y entre (invariant L3) : `receivedAt` est l'instant serveur de
 * réception.
 */
final class AcceptanceWindow
{
    /**
     * L'instant, exclu, où la fenêtre se ferme : `(ended_at ?? started_at + D)
     * + tier_grace_ms` — `null` pour une manche qui n'a pas d'origine de temps.
     *
     * @throws LogicException Manche d'une autre partie.
     */
    public static function closesAt(Round $round, Game $game): ?CarbonImmutable
    {
        self::assertSameGame($round, $game);

        $startedAt = $round->started_at;

        if ($startedAt === null) {
            return null;
        }

        $closedAt = $round->ended_at ?? $startedAt->addMilliseconds($round->duration_ms);

        return $closedAt->addMilliseconds($game->tier_grace_ms);
    }

    /**
     * Vrai si une soumission reçue à `$receivedAt` est recevable pour cette
     * manche, dans l'état où elle est lue.
     *
     * @throws LogicException Manche d'une autre partie.
     */
    public static function admits(Round $round, Game $game, CarbonImmutable $receivedAt): bool
    {
        $closesAt = self::closesAt($round, $game);
        $startedAt = $round->started_at;

        return $round->status === RoundStatus::Running
            && $startedAt !== null
            && $closesAt !== null
            && ! $receivedAt->lessThan($startedAt)
            && $receivedAt->lessThan($closesAt);
    }

    /**
     * @throws LogicException
     */
    private static function assertSameGame(Round $round, Game $game): void
    {
        if ($round->game_id !== $game->id) {
            throw new LogicException(sprintf(
                'AcceptanceWindow : la manche %d n’appartient pas à la partie donnée.',
                $round->sequence_index,
            ));
        }
    }
}
