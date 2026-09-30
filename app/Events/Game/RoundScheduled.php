<?php

namespace App\Events\Game;

/**
 * `round.scheduled` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `ScheduleRound` : manche 1, manche `k+1` au début de la
 * révélation de `k`, reprise, remplacement, « manche suivante ».
 * Réémis pour une manche `pending` reprogrammée, jamais après `T₁` (C7
 * § 4.2).
 *
 * Charge, hors enveloppe : `{ round: RoundTimeline, image: TierImageRef }`
 * — l'image du palier 1 seulement.
 */
final class RoundScheduled extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'round' => 'object',
        'image' => 'object',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    /** Diffusion de frontière : retard réel journalisé, périmée au rattrapage (§ 4.4, § 4.7). */
    public const bool BOUNDARY = true;

    public function broadcastAs(): string
    {
        return 'round.scheduled';
    }
}
