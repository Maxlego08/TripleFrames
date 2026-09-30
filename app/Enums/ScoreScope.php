<?php

namespace App\Enums;

/**
 * Portée de lecture du score : les manches dont les points entrent dans une
 * lecture, selon QUI la lit et QUAND (spec 80 § 7.1, contrat C13 § 2.3).
 * Énumération pure, jamais persistée.
 *
 * - `Own` — manches `running`, `revealing`, `completed` : le siège qui a
 *   répondu, et lui seul, en ciblé (`SelfState.ownScore`) ;
 * - `Publishable` — manches `revealing`, `completed` : tout score montré à un
 *   AUTRE siège (classement, `finders`, resynchronisation d'un tiers) ;
 * - `Settled` — manches `completed` : le gel et le podium, sur les agrégats
 *   figés seulement.
 *
 * Les trois excluent `cancelled` (invariant L1) — une manche annulée garde ses
 * lignes `guess` comme trace de l'incident, jamais ses points — et `pending`,
 * qui ne porte jamais de `guess`. **Les points et le palier d'une manche
 * deviennent publics dès `round.status = revealing`, jamais avant** (E10-52) :
 * un classement recalculé pendant une manche et montré à un tiers publierait le
 * palier et la vitesse d'un joueur verrouillé alors que les autres cherchent
 * encore (E10-14).
 *
 * {@see self::roundStatuses()} est le SEUL domicile de ces listes : les
 * portées `inScoreScope` de `Guess` et de `RoundPlayer` et toute lecture de
 * score la lisent, jamais ne la recopient.
 */
enum ScoreScope
{
    /** Manches `running`, `revealing` et `completed` : lue par le siège qui a répondu, et par lui seul. */
    case Own;

    /** Manches `revealing` et `completed` : tout score montré à un autre siège. */
    case Publishable;

    /** Manches `completed` : agrégats figés seulement (gel et podium). */
    case Settled;

    /**
     * Les statuts de manche dont les points entrent dans cette portée.
     *
     * @return list<RoundStatus>
     */
    public function roundStatuses(): array
    {
        return match ($this) {
            self::Own => [RoundStatus::Running, RoundStatus::Revealing, RoundStatus::Completed],
            self::Publishable => [RoundStatus::Revealing, RoundStatus::Completed],
            self::Settled => [RoundStatus::Completed],
        };
    }
}
