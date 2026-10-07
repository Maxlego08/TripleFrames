<?php

namespace App\Support\ContentReport;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Le débit du limiteur nommé `content-report` (D63 du 07/10), sur
 * `content-report.store` : par compte connecté, sinon par hash du
 * `player_token`, repli sur l'IP, comme les limiteurs de salon. La clé ne vit que dans le cache du limiteur,
 * jamais dans une table de domaine.
 *
 * Défaut ici, surcharge par `config('game.content_report.*')`, jamais en
 * littéral dispersé.
 */
final readonly class ContentReportRateLimits
{
    /** Signalements envoyés par heure et par compte, jeton ou adresse (`content-report`). */
    public const int DEFAULT_REPORTS_PER_HOUR = 10;

    /** Préfixe de configuration sous lequel le débit est surchargeable. */
    private const string CONFIG_PREFIX = 'game.content_report.';

    /** Un débit nul se lirait comme « tout refuser » : un limiteur n'est jamais un interrupteur. */
    private const int MIN_PER_WINDOW = 1;

    public static function reportsPerHour(): int
    {
        $value = Config::integer(self::CONFIG_PREFIX.'reports_per_hour', self::DEFAULT_REPORTS_PER_HOUR);

        if ($value < self::MIN_PER_WINDOW) {
            throw new InvalidArgumentException(sprintf(
                'ContentReportRateLimits : %sreports_per_hour vaut %d, sous sa borne basse %d.',
                self::CONFIG_PREFIX,
                $value,
                self::MIN_PER_WINDOW,
            ));
        }

        return $value;
    }
}
