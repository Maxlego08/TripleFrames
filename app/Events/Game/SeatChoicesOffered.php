<?php

namespace App\Events\Game;

/**
 * `seat.choices` — canal du siège, ciblé (`private-seat.{publicId}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `OpenTier` au palier du QCM (`T₁` en Facile, `T_N` en
 * Normal, jamais en Expert), à chaque siège dont la saisie accepte un
 * choix.
 *
 * Charge, hors enveloppe : `{ sequenceIndex }` + `ChoicesPayload` (contrat
 * C11) : `{ choices: [string, string, string, string], useOriginalTitle,
 * lang }` — quatre chaînes permutées pour CE siège, sans index ni
 * drapeau de la cible (§ 11.7).
 */
final class SeatChoicesOffered extends SeatBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'sequenceIndex' => 'int',
        'choices' => 'list',
        'useOriginalTitle' => 'bool',
        'lang' => '?string',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'seat.choices';
    }
}
