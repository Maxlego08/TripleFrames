<?php

namespace App\Support\Retention;

use App\Support\Room\RoomExpiry;
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
 * elle est LUE à sa source, jamais doublée d'une seconde constante — ainsi de
 * l'effacement des sièges solo, qui applique la durée de l'archivage d'un
 * salon. Le filet `stale_room` n'a pas d'autre source : sa durée vit ici.
 */
final class RetentionWindows
{
    /** `failed_jobs` : 14 jours (périmètre `framework_failed_jobs`). */
    public const int FAILED_JOBS_DAYS = 14;

    /**
     * `perf_sample` (et ses requêtes lentes, en cascade) et `game_trace` :
     * 14 jours (périmètres `perf` et `game_trace`, D47 du 01/10).
     */
    public const int PERF_DAYS = 14;

    /**
     * `audience_daily` : 13 mois, limite de l'exemption de consentement de la
     * CNIL pour la mesure d'audience (périmètre `audience`, D48 du 01/10).
     */
    public const int AUDIENCE_MONTHS = 13;

    /**
     * `purge_run` : 13 mois, auto-purgé par le même job — une fenêtre de plus
     * que la plus longue qu'il atteste (périmètre `purge_run`).
     */
    public const int PURGE_RUN_MONTHS = 13;

    /**
     * `guest_nickname` : pseudo, forme normalisée et pseudos figés d'un
     * siège, anonymisés 12 mois après sa dernière activité (`last_seen_at`) —
     * la durée des faits de partie qu'ils servent à analyser (D62 du 06/10).
     */
    public const int GUEST_NICKNAME_MONTHS = 12;

    /**
     * `visitor` : le visiteur consentant, supprimé 13 mois après sa dernière
     * activité — la durée maximale d'un traceur selon la CNIL, celle du
     * cookie `visitor` (D62 du 06/10).
     */
    public const int VISITOR_MONTHS = 13;

    /**
     * Filet `stale_room` : un salon non archivé 48 h après sa dernière
     * activité, archivé de force par l'action d'archivage de 50
     * (`StaleRoomHandler`). La sonde n° 2 de 10 § 11.3 lit la même durée.
     */
    public const int STALE_ROOM_HOURS = 48;

    /**
     * Branche sièges solo d'`orphan_player` : empreintes du jeton d'un siège
     * solo effacées 24 h après sa dernière activité (`last_seen_at`), en
     * minutes (`OrphanPlayerHandler`) ; le pseudo, lui, vit
     * {@see self::GUEST_NICKNAME_MONTHS} mois (D62 du 06/10).
     *
     * **Lue chez 50, jamais recopiée.** 10 § 11.1 écrit cette ligne
     * « Idem » de celle des identifiants d'invité d'un siège de salon :
     * une seule durée annoncée — 24 h après la dernière activité —, à deux
     * déclencheurs. Pour un siège de salon, c'est l'archivage du salon à
     * `RoomExpiry::ROOM_IDLE_MINUTES` ; un siège solo n'a pas de salon, et
     * c'est la purge qui applique la même durée.
     */
    public const int SOLO_SEAT_IDLE_MINUTES = RoomExpiry::ROOM_IDLE_MINUTES;

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
