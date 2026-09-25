<?php

namespace App\Support\Draw;

use App\Enums\FrameLevel;
use Carbon\CarbonImmutable;

/**
 * Une variante jouable d'un film, réduite à ce que le choix de variante lit
 * (spec 30 § 6.2 et § 7, contrat C3).
 *
 * « Jouable » est le prédicat unique de la spec 10 § 3.2 (`Frame::isServable()`) :
 * publiée, traitée, dérivé présent. `lastSeenAt` est la mémoire du **salon**
 * (`seen_frame.last_seen_at`), jamais celle d'un joueur : nul quand le salon ne
 * l'a jamais vue, et nul partout en solo ou pour le vivier catalogue, qui n'ont
 * pas de salon (§ 9).
 *
 * Aucun de ces champs ne quitte le serveur — `frameId` et `frameLevel` (qui
 * trahirait un repli de niveau) moins que tout autre : la classe n'est ni
 * `Arrayable` ni `JsonSerializable` (§ 5.5, règle 3).
 */
final readonly class VariantCandidate
{
    /**
     * @param  int  $frameId  `frame.id`.
     * @param  FrameLevel  $frameLevel  `frame.frame_level`.
     * @param  CarbonImmutable|null  $lastSeenAt  Dernier affichage au salon ; nul si jamais vue ou sans salon.
     */
    public function __construct(
        public int $frameId,
        public FrameLevel $frameLevel,
        public ?CarbonImmutable $lastSeenAt,
    ) {}
}
