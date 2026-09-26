<?php

namespace App\Support\Game;

use App\Events\Game\RoundScheduled;
use App\Models\Game;
use App\Models\RoundTier;
use App\Support\Realtime\WireTime;
use LogicException;

/**
 * `TierImageRef` — la référence d'image d'un palier (spec 60 § 11.4 et
 * § 11.5, contrat C7 § 3) : `{ tierIndex, url, fetchNotBefore }`. Miroir de
 * `TierImageRef` dans `resources/js/types/game-wire.ts`.
 *
 * - `url` = {@see ServeUrl::for()} : l'URL signée relative du `serve_token`
 *   frappé, recalculée à chaque émission, jamais stockée ;
 * - `fetchNotBefore` = `Tᵢ − game.preload_lead_ms` (colonne figée de la
 *   partie, jamais la configuration), lu par {@see RoundTier::servingOpensAt()},
 *   la formule unique que la garde de service relit
 *   ({@see RoundTier::isOpenForServing()}) : le client ne demande jamais
 *   l'image avant (§ 7.6), et le serveur la refuse jusque-là (§ 7.2).
 *
 * **Ne choisit pas QUELLES images partent** : `round.scheduled` porte le
 * seul palier 1 (§ 11.3), `tier.opened` le seul palier suivant, la
 * révélation les paliers ouverts, et le paquet de resynchronisation au plus
 * deux URL pendant la manche (§ 12.4) — chaque appelant décide, cette classe
 * compose. Aucun niveau, aucun chemin, aucun identifiant de frame (§ 11.7).
 *
 * Seul constructeur serveur de la forme : {@see RoundScheduled} (L60-5), puis
 * `tier.opened`, `round.revealed` (L60-6) et le paquet (L60-12).
 *
 * @phpstan-type TierImageRefPayload array{tierIndex: int, url: string, fetchNotBefore: string}
 */
final class TierImageRefPresenter
{
    /**
     * @return TierImageRefPayload
     *
     * @throws LogicException Palier d'une autre partie, sans jeton frappé, ou
     *                        manche sans origine de temps.
     */
    public static function image(Game $game, RoundTier $tier): array
    {
        $round = $tier->round;

        if ($round->game_id !== $game->id) {
            throw new LogicException('TierImageRefPresenter : le palier n’appartient pas à la partie.');
        }

        // La formule même de la garde de service (`isOpenForServing()`) : le
        // client demande l'image à l'instant où le serveur l'autorise.
        $fetchNotBefore = $tier->servingOpensAt($game->preload_lead_ms) ?? throw new LogicException(sprintf(
            'TierImageRefPresenter : la manche %d n’est pas programmée.',
            $round->sequence_index,
        ));

        return [
            'tierIndex' => $tier->tier_index,
            'url' => ServeUrl::for($tier),
            'fetchNotBefore' => WireTime::iso($fetchNotBefore),
        ];
    }
}
