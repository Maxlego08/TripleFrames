<?php

namespace App\Support\Game;

use App\Jobs\Game\AdvanceRound;

/**
 * Les quatre étapes d'une manche — spec 60 § 4.1, contrat C7 § 2.5 (enum
 * interne, hors schéma : il ne porte aucune colonne).
 *
 * | Étape       | Instant théorique                       | Écrivain (lot)        |
 * |-------------|-----------------------------------------|-----------------------|
 * | `OpenTier`  | `Tᵢ = started_at + starts_at_offset_ms` | `OpenTier` (L60-6)    |
 * | `Close`     | `started_at + D`, sauf fin anticipée    | `CloseRound` (L60-6)  |
 * | `Reveal`    | `ended_at + tier_grace_ms`              | `RevealRound` (L60-6) |
 * | `EndReveal` | `reveal_ends_at`                        | `EndReveal` (L60-6)   |
 *
 * **Ordre à instant égal** : `EndReveal(k)` précède `OpenTier(k+1, 1)`, dans
 * le job comme dans le rattrapage (contrat C7 § 4.14). À l'intérieur d'une
 * manche, deux étapes ne tombent jamais au même instant (`dᵢ ≥
 * MIN_TIER_DURATION` s, `tier_grace_ms > 0`).
 *
 * L'étape portée par un job de frontière ({@see AdvanceRound}) ne sert qu'à
 * son délai et à sa journalisation : le job ne décide rien, il réveille le
 * rattrapage, qui relit l'état et exécute ce qui est échu (§ 4.2, règle 8).
 * Les deux échéances de partie et de siège (`InterruptPausedGame`,
 * `SweepSeatPresence`) ont leurs propres jobs et ne sont pas des étapes de
 * manche.
 */
enum RoundStep: string
{
    /** Ouverture du palier `i` à `Tᵢ`, `i` = 1..N. */
    case OpenTier = 'open_tier';

    /** Clôture à `D` (la fin anticipée n'a pas de job : elle vient d'un geste). */
    case Close = 'close';

    /** Début de la révélation, `ended_at + tier_grace_ms`. */
    case Reveal = 'reveal';

    /** Fin de la révélation, `reveal_ends_at`. */
    case EndReveal = 'end_reveal';

    /** Vrai pour la seule étape qui désigne un palier. */
    public function targetsTier(): bool
    {
        return $this === self::OpenTier;
    }
}
