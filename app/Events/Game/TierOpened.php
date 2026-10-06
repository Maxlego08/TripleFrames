<?php

namespace App\Events\Game;

/**
 * `tier.opened` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `OpenTier` à `Tᵢ`, `i` = 1..N.
 *
 * Charge, hors enveloppe : `{ sequenceIndex, roundNumber, tierIndex,
 * opensAt: IsoMs, next: TierImageRef | null, choicesUnavailable: bool }` :
 * `opensAt` = `Tᵢ` théorique, `next` = palier `i+1` frappé dans la même
 * transition, nul au dernier palier (§ 11.5) ; `choicesUnavailable` vrai au
 * seul palier du QCM d'une manche Normal dont la composition a abouti au cas
 * terminal (70 § 10.7, D54 du 02/10) — booléen identique pour tout le
 * salon, qui ne dit rien de la réponse.
 */
final class TierOpened extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'sequenceIndex' => 'int',
        'roundNumber' => 'int',
        'tierIndex' => 'int',
        'opensAt' => 'iso',
        'next' => '?object',
        'choicesUnavailable' => 'bool',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    /** Diffusion de frontière : retard réel journalisé, périmée au rattrapage (§ 4.4, § 4.7). */
    public const bool BOUNDARY = true;

    public function broadcastAs(): string
    {
        return 'tier.opened';
    }
}
