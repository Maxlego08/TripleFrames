<?php

namespace App\Support\Retention\Handlers;

use App\Enums\PurgeScope;
use App\Support\Retention\RetentionWindows;
use App\Support\Retention\TablePurgeHandler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Périmètre `framework_sessions` — 10 § 11.1 : suppression **déterministe
 * quotidienne** des sessions au-delà de `session.lifetime`, sur
 * `last_activity` (indexée par la migration du starter).
 *
 * **Jamais le seul tirage.** `config/session.php` pose `'lottery' => [2, 100]` :
 * sur un site peu fréquenté hors des pics, des lignes qui portent une adresse
 * IP et un agent utilisateur survivraient des semaines au ramasse-miettes
 * probabiliste du framework. La page de confidentialité annonce « au plus
 * 24 h après la fin de la session » : c'est cette purge quotidienne qui rend
 * l'engagement vrai, quel que soit le trafic.
 *
 * Une session est éligible quand sa dernière activité est strictement
 * antérieure à `now − lifetime` : exactement la condition sous laquelle le
 * framework la tient déjà pour expirée à la lecture. Table et connexion sont
 * celles de `config/session.php`.
 */
final class FrameworkSessionsHandler extends TablePurgeHandler
{
    public function scope(): PurgeScope
    {
        return PurgeScope::FrameworkSessions;
    }

    protected function connectionName(): ?string
    {
        $connection = Config::get('session.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    protected function table(): string
    {
        return Config::string('session.table');
    }

    protected function pilotColumn(): string
    {
        return 'last_activity';
    }

    protected function keyColumn(): string
    {
        return 'id';
    }

    protected function cutoff(CarbonImmutable $asOf): int
    {
        return $asOf->subMinutes(RetentionWindows::sessionLifetimeMinutes())->getTimestamp();
    }
}
