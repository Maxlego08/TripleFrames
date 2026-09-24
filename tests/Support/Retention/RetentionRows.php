<?php

namespace Tests\Support\Retention;

use App\Enums\PurgeScope;
use App\Models\PurgeRun;
use App\Support\Retention\RetentionWindows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Des lignes de chaque périmètre de purge, datées par rapport à sa fenêtre de
 * 10 § 11.1 — aucune donnée réelle : identifiants de session, adresses et
 * jetons sont fabriqués, et les adresses IP viennent des plages de
 * documentation (RFC 5737).
 *
 * Chaque ligne porte un **marqueur** (`seed-` en tête de clé, file `seed`,
 * scope `stale_lobby` pour `purge_run`) : {@see self::seeded()} ne compte
 * qu'elles, jamais les lignes que la purge écrit elle-même.
 *
 * {@see self::seed()} connaît chaque périmètre implémenté et LÈVE pour un
 * périmètre inconnu : un périmètre ajouté à `PurgeScope::implemented()` sans
 * jeu de lignes ici fait échouer les tests qui balaient tous les périmètres,
 * au lieu de passer sans rien prouver.
 */
final class RetentionRows
{
    public const string MARKER = 'seed-';

    public const string QUEUE_MARKER = 'seed';

    /** `purge_run` n'a pas de clé à marquer : le scope du balayage de 50, que la purge n'écrit jamais. */
    public const PurgeScope PURGE_RUN_MARKER = PurgeScope::StaleLobby;

    /**
     * Deux lignes éligibles et deux lignes conservées pour `$scope`, à
     * l'instant `$now` : l'une juste au-delà de la fenêtre, l'une loin
     * au-delà ; l'une exactement à la borne (non éligible : la borne est
     * stricte), l'une récente.
     *
     * @return array{eligible: int, kept: int}
     */
    public static function seed(PurgeScope $scope, CarbonImmutable $now): array
    {
        $cutoff = self::cutoff($scope, $now);

        foreach ([$cutoff->subSecond(), $cutoff->subDays(40)] as $eligible) {
            self::insert($scope, $eligible);
        }

        foreach ([$cutoff, $now->subMinute()] as $kept) {
            self::insert($scope, $kept);
        }

        return ['eligible' => 2, 'kept' => 2];
    }

    /**
     * La borne d'éligibilité de `$scope` à `$now`, calculée ici depuis les
     * fenêtres de 10 § 11.1 et non depuis le gestionnaire : c'est ce que le
     * test compare au prédicat du gestionnaire.
     */
    public static function cutoff(PurgeScope $scope, CarbonImmutable $now): CarbonImmutable
    {
        return match ($scope) {
            PurgeScope::FrameworkSessions => $now->subMinutes(RetentionWindows::sessionLifetimeMinutes()),
            PurgeScope::FrameworkFailedJobs => $now->subDays(RetentionWindows::FAILED_JOBS_DAYS),
            PurgeScope::FrameworkResetTokens => $now->subMinutes(RetentionWindows::resetTokenMinutes()),
            PurgeScope::PurgeRun => $now->subMonthsNoOverflow(RetentionWindows::PURGE_RUN_MONTHS),
            default => throw new LogicException("Aucun jeu de lignes de test pour le périmètre {$scope->value}."),
        };
    }

    /** Insère une ligne marquée de `$scope`, datée de `$at` sur sa colonne pilote. */
    public static function insert(PurgeScope $scope, CarbonImmutable $at, ?string $key = null): void
    {
        $key ??= self::MARKER.Str::lower(Str::random(12));

        match ($scope) {
            PurgeScope::FrameworkSessions => self::session($key, $at),
            PurgeScope::FrameworkFailedJobs => self::failedJob($at),
            PurgeScope::FrameworkResetTokens => self::resetToken($key.'@example.com', $at),
            PurgeScope::PurgeRun => self::purgeRun($at),
            default => throw new LogicException("Aucun jeu de lignes de test pour le périmètre {$scope->value}."),
        };
    }

    /** Le nombre de lignes marquées encore présentes dans la table de `$scope`. */
    public static function seeded(PurgeScope $scope): int
    {
        return match ($scope) {
            PurgeScope::FrameworkSessions => DB::table(Config::string('session.table'))
                ->where('id', 'like', self::MARKER.'%')
                ->count(),
            PurgeScope::FrameworkFailedJobs => DB::table(Config::string('queue.failed.table'))
                ->where('queue', self::QUEUE_MARKER)
                ->count(),
            PurgeScope::FrameworkResetTokens => DB::table(self::resetTokenTable())
                ->where('email', 'like', self::MARKER.'%')
                ->count(),
            PurgeScope::PurgeRun => PurgeRun::query()
                ->where('scope', self::PURGE_RUN_MARKER->value)
                ->count(),
            default => throw new LogicException("Aucun jeu de lignes de test pour le périmètre {$scope->value}."),
        };
    }

    /** Une session, avec l'adresse IP et l'agent que la migration du starter y stocke. */
    public static function session(string $id, CarbonImmutable $lastActivity): void
    {
        DB::table(Config::string('session.table'))->insert([
            'id' => $id,
            'user_id' => null,
            'ip_address' => '192.0.2.'.random_int(1, 254),
            'user_agent' => 'Agent de test',
            'payload' => base64_encode('charge de test'),
            'last_activity' => $lastActivity->getTimestamp(),
        ]);
    }

    public static function failedJob(CarbonImmutable $failedAt): void
    {
        DB::table(Config::string('queue.failed.table'))->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => self::QUEUE_MARKER,
            'payload' => '{"displayName":"Tâche de test"}',
            'exception' => 'Exception de test',
            'failed_at' => $failedAt,
        ]);
    }

    public static function resetToken(string $email, CarbonImmutable $createdAt): void
    {
        DB::table(self::resetTokenTable())->insert([
            'email' => $email,
            'token' => hash('sha256', $email),
            'created_at' => $createdAt,
        ]);
    }

    private static function resetTokenTable(): string
    {
        return Config::string('auth.passwords.'.RetentionWindows::passwordBroker().'.table');
    }

    public static function purgeRun(CarbonImmutable $ranAt): void
    {
        PurgeRun::factory()->forScope(self::PURGE_RUN_MARKER)->create([
            'started_at' => $ranAt,
            'finished_at' => $ranAt->addMinute(),
            'ran_at' => $ranAt,
        ]);
    }
}
