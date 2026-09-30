<?php

namespace App\Support\Retention\Handlers;

use App\Enums\PurgeScope;
use App\Support\Retention\RetentionWindows;
use App\Support\Retention\TablePurgeHandler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Périmètre `framework_reset_tokens` — 10 § 11.1 : le défaut Fortify, sur
 * `created_at`.
 *
 * La spec en désigne la mise en œuvre par `auth:clear-resets` : c'en est
 * l'équivalent exact — table, connexion et expiration du courtier de Fortify
 * (`fortify.passwords`, `auth.passwords.<courtier>.*`), même prédicat
 * `created_at < now − expire` que `DatabaseTokenRepository::deleteExpired()` —,
 * exécuté par le moteur pour que la suppression soit comptée et que la sonde
 * n° 4 lise le même prédicat. `deleteExpired()` ne rend aucun compte.
 *
 * La clé est l'adresse électronique : elle ne sert qu'au curseur et à
 * l'effacement, jamais au journal ni à `purge_run`.
 */
final class FrameworkResetTokensHandler extends TablePurgeHandler
{
    public function scope(): PurgeScope
    {
        return PurgeScope::FrameworkResetTokens;
    }

    protected function connectionName(): ?string
    {
        $connection = Config::get('auth.passwords.'.RetentionWindows::passwordBroker().'.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    protected function table(): string
    {
        return Config::string('auth.passwords.'.RetentionWindows::passwordBroker().'.table');
    }

    protected function pilotColumn(): string
    {
        return 'created_at';
    }

    protected function keyColumn(): string
    {
        return 'email';
    }

    protected function cutoff(CarbonImmutable $asOf): CarbonImmutable
    {
        return $asOf->subMinutes(RetentionWindows::resetTokenMinutes());
    }
}
