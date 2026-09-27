<?php

namespace App\Support\Retention;

use Illuminate\Support\Facades\Config;

/**
 * Les durées de conservation que la purge applique — **source unique** en
 * code (spec 100 § 14), chaque entrée reprenant une ligne du tableau de
 * 10 § 11.1. Ce ne sont pas des valeurs de jeu : ce sont des durées annoncées
 * publiquement, que 90 publiera depuis ici sans les recopier.
 *
 * Quand une durée est déjà déclarée ailleurs — par le framework
 * (`session.lifetime`, expiration du courtier de mots de passe de Fortify) ou
 * par 50 (`App\Support\Room\RoomExpiry`, échéances du lobby et du salon) —,
 * elle est LUE à sa source, jamais doublée d'une seconde constante. Le filet
 * `stale_room` n'a pas d'autre source : sa durée vit ici.
 */
final class RetentionWindows
{
    /** `failed_jobs` : 14 jours (périmètre `framework_failed_jobs`). */
    public const int FAILED_JOBS_DAYS = 14;

    /**
     * `purge_run` : 13 mois, auto-purgé par le même job — une fenêtre de plus
     * que la plus longue qu'il atteste (périmètre `purge_run`).
     */
    public const int PURGE_RUN_MONTHS = 13;

    /**
     * Filet `stale_room` : un salon non archivé 48 h après sa dernière
     * activité, archivé de force par l'action d'archivage de 50
     * (`StaleRoomHandler`). La sonde n° 2 de 10 § 11.3 lit la même durée.
     */
    public const int STALE_ROOM_HOURS = 48;

    /**
     * `sessions` : `session.lifetime`, en minutes — la durée au-delà de
     * laquelle le framework tient déjà une session pour expirée à la lecture
     * (périmètre `framework_sessions`).
     */
    public static function sessionLifetimeMinutes(): int
    {
        return Config::integer('session.lifetime');
    }

    /**
     * `password_reset_tokens` : le défaut Fortify, soit l'expiration, en
     * minutes, du courtier de mots de passe qu'emploie Fortify — celle que
     * `auth:clear-resets` applique (périmètre `framework_reset_tokens`).
     */
    public static function resetTokenMinutes(): int
    {
        return Config::integer('auth.passwords.'.self::passwordBroker().'.expire');
    }

    /** Le courtier de mots de passe de Fortify, dont la purge lit la table et l'expiration. */
    public static function passwordBroker(): string
    {
        return Config::string('fortify.passwords');
    }
}
