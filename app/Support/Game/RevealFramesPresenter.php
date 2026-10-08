<?php

namespace App\Support\Game;

use App\Models\Frame;
use App\Models\RoundTier;

/**
 * `reveal.frames` — l'identité publique des images réellement servies d'une
 * manche, pour le lien « Signaler cette image » (D63 du 07/10, spec 60
 * § 11.5) : `{ tierIndex, framePublicId }` par palier OUVERT (`served_at`
 * non nul), dans l'ordre des paliers.
 *
 * **Seulement à la révélation** (règle 3) : appelé par `RevealRound`
 * (`round.revealed`) et par `GameStateBuilder` sous `round.reveal`, déjà
 * gardé par `revealStartsAt`. **Jamais** par {@see TierImageRefPresenter},
 * `round.scheduled` ni `tier.opened` : un identifiant stable d'image avant
 * la révélation laisserait un tricheur noter la paire (image → titre).
 *
 * La variante **servie** (`served_frame_id`), jamais la variante tirée
 * (`frame_id`) : elles diffèrent après une substitution. Les paliers doivent
 * porter `servedFrame` chargé.
 *
 * @phpstan-type RevealFramePayload array{tierIndex: int, framePublicId: string}
 */
final class RevealFramesPresenter
{
    /**
     * @param  iterable<RoundTier>  $tiers
     * @return list<RevealFramePayload>
     */
    public static function frames(iterable $tiers): array
    {
        $frames = [];

        foreach ($tiers as $tier) {
            $served = $tier->served_at === null ? null : $tier->servedFrame;

            if ($served instanceof Frame) {
                $frames[] = [
                    'tierIndex' => $tier->tier_index,
                    'framePublicId' => $served->public_id,
                ];
            }
        }

        usort($frames, static fn (array $a, array $b): int => $a['tierIndex'] <=> $b['tierIndex']);

        return $frames;
    }
}
