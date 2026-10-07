<?php

namespace App\Support\ContentReport;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Le débit des limiteurs nommés `content-report` (D63 du 07/10), sur
 * `content-report.store`, et `content-report-frame` (amendé le 07/10), sur
 * l'aperçu `content-report.frame` : par compte connecté, sinon par hash du
 * `player_token`, repli sur l'IP, comme les limiteurs de salon. La clé ne vit
 * que dans le cache du limiteur, jamais dans une table de domaine. Le second
 * est un seau distinct de `frame-serve` : l'aperçu ne consomme jamais le
 * budget de chargement des paliers (C8).
 *
 * Défaut ici, surcharge par `config('game.content_report.*')`, jamais en
 * littéral dispersé.
 */
final readonly class ContentReportRateLimits
{
    /** Signalements envoyés par heure et par compte, jeton ou adresse (`content-report`). */
    public const int DEFAULT_REPORTS_PER_HOUR = 10;

    /** Aperçus de l'image signalée servis par minute et par compte, jeton ou adresse (`content-report-frame`). */
    public const int DEFAULT_FRAME_PREVIEWS_PER_MINUTE = 20;

    /** Préfixe de configuration sous lequel le débit est surchargeable. */
    private const string CONFIG_PREFIX = 'game.content_report.';

    /** Un débit nul se lirait comme « tout refuser » : un limiteur n'est jamais un interrupteur. */
    private const int MIN_PER_WINDOW = 1;

    public static function reportsPerHour(): int
    {
        return self::read('reports_per_hour', self::DEFAULT_REPORTS_PER_HOUR);
    }

    public static function framePreviewsPerMinute(): int
    {
        return self::read('frame_previews_per_minute', self::DEFAULT_FRAME_PREVIEWS_PER_MINUTE);
    }

    private static function read(string $key, int $default): int
    {
        $value = Config::integer(self::CONFIG_PREFIX.$key, $default);

        if ($value < self::MIN_PER_WINDOW) {
            throw new InvalidArgumentException(sprintf(
                'ContentReportRateLimits : %s%s vaut %d, sous sa borne basse %d.',
                self::CONFIG_PREFIX,
                $key,
                $value,
                self::MIN_PER_WINDOW,
            ));
        }

        return $value;
    }
}
