<?php

namespace App\Support\Room;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Débits des deux limiteurs d'entrée du salon (spec 50 § 17.3).
 *
 * Suit le patron de `PlatformLimits` : constantes `DEFAULT_*`, accesseurs
 * statiques, garde de bornes sur chaque lecture. Lus dans
 * `config/game.php › room`, section lue EXCLUSIVEMENT ici.
 *
 * Ce ne sont ni des limites de confort ni des valeurs de jeu, mais des gardes
 * anti-abus : elles ne sont jamais résolues par compte, par plan ni par siège.
 * Les limiteurs nommés `room-create` (clé : adresse IP, en cache, jamais une
 * table de domaine) et `room-join` (clé : hash du `player_token`, repli sur
 * l'IP) les lisent au moment de compter.
 */
final readonly class RoomRateLimits
{
    /** Créations de salon par heure et par adresse IP (`room-create`). */
    public const int DEFAULT_CREATES_PER_HOUR = 10;

    /** Entrées dans un salon par minute et par jeton (`room-join`). */
    public const int DEFAULT_JOINS_PER_MINUTE = 10;

    /**
     * Signalements de pseudo par minute et par siège (`seat-report`, spec 40
     * § 13.3, D66 du 07/10) : douze sièges au plus par salon, un signalement
     * utile par siège visé — au-delà, c'est un script.
     */
    public const int DEFAULT_SEAT_REPORTS_PER_MINUTE = 10;

    /** Préfixe de configuration sous lequel chaque débit est surchargeable. */
    private const string CONFIG_PREFIX = 'game.room.';

    /** Un débit nul se lirait comme « tout refuser » : un limiteur n'est jamais un interrupteur. */
    private const int MIN_PER_WINDOW = 1;

    /**
     * @throws InvalidArgumentException Débit configuré sous sa borne basse.
     */
    public static function createsPerHour(): int
    {
        return self::configured('creates_per_hour', self::DEFAULT_CREATES_PER_HOUR);
    }

    /**
     * @throws InvalidArgumentException Débit configuré sous sa borne basse.
     */
    public static function joinsPerMinute(): int
    {
        return self::configured('joins_per_minute', self::DEFAULT_JOINS_PER_MINUTE);
    }

    /**
     * @throws InvalidArgumentException Débit configuré sous sa borne basse.
     */
    public static function seatReportsPerMinute(): int
    {
        return self::configured('seat_reports_per_minute', self::DEFAULT_SEAT_REPORTS_PER_MINUTE);
    }

    private static function configured(string $key, int $default): int
    {
        $value = Config::integer(self::CONFIG_PREFIX.$key, $default);

        if ($value < self::MIN_PER_WINDOW) {
            throw new InvalidArgumentException(sprintf(
                'RoomRateLimits : %s vaut %d, sous sa borne basse %d.',
                self::CONFIG_PREFIX.$key,
                $value,
                self::MIN_PER_WINDOW,
            ));
        }

        return $value;
    }
}
