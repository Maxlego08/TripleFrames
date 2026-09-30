<?php

namespace App\Support\Realtime;

use App\Models\Game;
use Carbon\CarbonImmutable;

/**
 * L'enveloppe du fil de jeu — spec 60 § 11.1, contrat C7 § 2.5.
 *
 * Présente dans chaque événement et en tête de tout paquet de
 * resynchronisation : `{ v, serverNow, gameRef }`.
 *
 * - `v` = {@see self::VERSION}, miroir de `GAME_WIRE_VERSION` dans
 *   `resources/js/lib/game/wire.ts` (`WireVersionTest`). Toute rupture de
 *   forme d'une charge l'incrémente des deux côtés.
 * - `serverNow` = l'instant d'émission, en `IsoMs` ({@see WireTime::iso()}) ;
 *   le client s'en sert pour relever son décalage d'horloge, **pour
 *   l'affichage seulement** (§ 2.4).
 * - `gameRef` = {@see GameRef::for()}, nul pour un événement de lobby hors
 *   partie.
 *
 * Durées et décalages de toute charge : entiers en millisecondes.
 */
final class GameWire
{
    public const int VERSION = 1;

    /**
     * @return array{v: int, serverNow: string, gameRef: string|null}
     */
    public static function envelope(?Game $game, CarbonImmutable $now): array
    {
        return [
            'v' => self::VERSION,
            'serverNow' => WireTime::iso($now),
            'gameRef' => $game === null ? null : GameRef::for($game),
        ];
    }
}
