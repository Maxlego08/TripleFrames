<?php

namespace App\Events\Game;

/**
 * `round.revealed` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `RevealRound`, à `ended_at + tier_grace_ms`.
 *
 * Charge, hors enveloppe : `{ sequenceIndex, roundNumber, revealEndsAt,
 * movie: RevealMovie, images: TierImageRef[], frames: RevealFrame[],
 * finders: RoundFinder[], leaderboard: Leaderboard }` : `movie` par le seul
 * `RevealMovieBuilder`, `images` des seuls paliers ouverts (§ 11.5), `frames`
 * l'identité publique de leurs images servies, pour le lien « Signaler »
 * (`RevealFramesPresenter`, D63 du 07/10) — jamais avant la révélation.
 */
final class RoundRevealed extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'sequenceIndex' => 'int',
        'roundNumber' => 'int',
        'revealEndsAt' => 'iso',
        'movie' => 'object',
        'images' => 'list',
        'frames' => 'list',
        'finders' => 'list',
        'leaderboard' => 'object',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    /** Diffusion de frontière : retard réel journalisé, périmée au rattrapage (§ 4.4, § 4.7). */
    public const bool BOUNDARY = true;

    public function broadcastAs(): string
    {
        return 'round.revealed';
    }
}
