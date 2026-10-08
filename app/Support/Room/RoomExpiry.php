<?php

namespace App\Support\Room;

use Carbon\CarbonImmutable;

/**
 * Les échéances d'un salon (spec 50 § 16.1 et § 16.2 ; 10 § 11.1) — **source
 * unique** en code des deux durées d'inactivité que le balayage
 * `room:archive-idle` applique, de sa cadence et de la taille de ses lots.
 *
 * Ce ne sont pas des valeurs de jeu (règle 2) : ce sont des durées de
 * conservation annoncées publiquement. `90` les publie depuis ces constantes,
 * augmentées de {@see self::SWEEP_EVERY_MINUTES} pour que la durée annoncée
 * reste un plafond vrai (décision 19) ; `RetentionWindows` de `100` les lit
 * ici, jamais dans une seconde constante (100 § 14).
 *
 * Toutes se comptent depuis **la dernière activité** (`room.last_activity_at`),
 * jamais en absolu : une partie légale de 30 manches à 120 s dure 70 minutes.
 * Les sources d'activité sont nommées au § 16.1 — création et prise de siège,
 * écriture de réglages, lancement et « Rejouer », expulsion, transfert,
 * départ, et le battement de présence de `60`.
 */
final class RoomExpiry
{
    /**
     * Archivage anticipé du lobby (périmètre `stale_lobby`) : un salon jamais
     * lancé (`launched_at` nul), sans activité depuis 2 h.
     */
    public const int LOBBY_IDLE_MINUTES = 120;

    /** Archivage du salon : 24 h sans activité, lancé ou non. */
    public const int ROOM_IDLE_MINUTES = 1440;

    /**
     * Cadence du balayage, en minutes. L'archivage effectif intervient au
     * plus cet intervalle après l'échéance. Diviseur de 60, pour que
     * l'expression cron de {@see self::sweepCron()} tombe à intervalles
     * réguliers ; au moins 2, le verrou d'unicité du balayage durant une
     * cadence moins une minute.
     */
    public const int SWEEP_EVERY_MINUTES = 10;

    /** Taille d'un lot du balayage, lu du plus ancien au plus récent. */
    public const int BATCH_SIZE = 100;

    /**
     * Borne de l'archivage anticipé du lobby : un salon jamais lancé dont la
     * dernière activité est STRICTEMENT antérieure est échu.
     */
    public static function lobbyIdleBefore(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMinutes(self::LOBBY_IDLE_MINUTES);
    }

    /**
     * Borne de l'archivage : un salon dont la dernière activité est
     * STRICTEMENT antérieure est échu.
     */
    public static function roomIdleBefore(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMinutes(self::ROOM_IDLE_MINUTES);
    }

    /**
     * L'échéance d'archivage d'un salon actif à `$now` : au plus tard
     * l'inactivité de 24 h plus une cadence de balayage. Une activité
     * ultérieure la repousse ; c'est donc une borne basse de la vie du
     * salon, durée de la marque d'avatar d'un rattachement (spec 40 § 13.2).
     */
    public static function archiveDeadlineFrom(CarbonImmutable $now): CarbonImmutable
    {
        return $now->addMinutes(self::ROOM_IDLE_MINUTES + self::SWEEP_EVERY_MINUTES);
    }

    /**
     * L'expression cron du balayage, bâtie sur {@see self::SWEEP_EVERY_MINUTES}
     * et jamais écrite ailleurs (`routes/console.php`).
     */
    public static function sweepCron(): string
    {
        return '*/'.self::SWEEP_EVERY_MINUTES.' * * * *';
    }
}
