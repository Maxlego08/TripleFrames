<?php

use App\Actions\Account\RecordConsents;
use App\Enums\ConsentKind;
use App\Models\User;
use App\Models\UserConsent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Concurrence de deux acceptations des CGU — spec 40 § 13.1 (L40-10)
|--------------------------------------------------------------------------
|
| Groupe `locks-timing` (par répertoire), MySQL réel, `DatabaseTruncation`.
|
| Deux onglets, ou un double envoi : deux `POST /account/terms` passent tous
| deux le contrôle `isCurrent()` du contrôleur. `RecordConsents` verrouille
| la ligne `users` du compte AVANT toute lecture : la seconde acceptation
| attend la première, puis relit la ligne validée et garde sa date — jamais
| la violation de `user_consent_user_kind_version_uq` (500).
|
| Entrelacement déterministe (patron de `LaunchConcurrencyTest`) : la première
| acceptation s'exécute sur une connexion jumelle, transaction laissée
| ouverte ; la seconde, sur la connexion par défaut, BUTE sur le verrou du
| compte (`innodb_lock_wait_timeout` de session : la borne de l'attente,
| jamais le verdict) ; la première valide, puis la seconde est rejouée.
|
*/

it('la seconde acceptation attend le verrou du compte, puis garde la première preuve sans violer l’unicité', function () {
    Config::set('legal.terms_version', 'definitive-1');
    $user = User::factory()->outdatedTerms('ancienne-1')->create();

    $default = DB::getDefaultConnection();
    $rival = 'terms_rival';
    config(["database.connections.{$rival}" => config("database.connections.{$default}")]);

    $blocked = null;
    $before = [];
    $recording = false;

    // Les requêtes ABOUTIES de la connexion par défaut pendant la seconde
    // acceptation, et pendant elle seule.
    DB::listen(static function (QueryExecuted $query) use (&$before, &$recording, $default): void {
        if ($recording && $query->connectionName === $default) {
            $before[] = $query->sql;
        }
    });

    try {
        // 1. La première acceptation, transaction laissée ouverte.
        DB::connection($rival)->beginTransaction();
        DB::setDefaultConnection($rival);
        Date::setTestNow(CarbonImmutable::parse('2026-10-07 10:00:00'));
        app(RecordConsents::class)->handle(User::query()->findOrFail($user->id), [ConsentKind::Terms]);

        // 2. La seconde, partie du même état périmé : elle bute sur le compte.
        DB::setDefaultConnection($default);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        Date::setTestNow(CarbonImmutable::parse('2026-10-07 10:00:05'));
        $stale = User::query()->findOrFail($user->id);

        $recording = true;

        try {
            app(RecordConsents::class)->handle($stale, [ConsentKind::Terms]);
        } catch (QueryException $exception) {
            $blocked = $exception;
        } finally {
            $recording = false;
        }

        // 3. La première valide.
        DB::connection($rival)->commit();
    } finally {
        DB::setDefaultConnection($default);

        if (DB::connection($rival)->transactionLevel() > 0) {
            DB::connection($rival)->rollBack();
        }

        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        DB::purge($rival);
    }

    // Le verrou du compte, et non l'index unique, a arrêté la seconde : elle
    // a buté AVANT de lire `user_consent`. Sans ce verrou, sa lecture non
    // verrouillante aboutirait (aucune ligne visible), puis son insertion
    // buterait sur l'unicité et finirait en violation une fois la première
    // validée.
    expect($blocked)->toBeInstanceOf(QueryException::class)
        ->and($blocked?->errorInfo[1] ?? null)->toBe(1205)
        ->and(collect($before)->filter(fn (string $sql): bool => str_contains($sql, 'user_consent'))->all())->toBe([]);

    // Rejouée après la validation, elle relit la ligne et n'écrit rien.
    app(RecordConsents::class)->handle(User::query()->findOrFail($user->id), [ConsentKind::Terms]);

    $rows = UserConsent::query()
        ->where('user_id', $user->id)
        ->where('kind', ConsentKind::Terms->value)
        ->where('version', 'definitive-1')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->accepted_at->toDateTimeString())->toBe('2026-10-07 10:00:00')
        ->and($user->fresh()?->terms_accepted_at?->toDateTimeString())->toBe('2026-10-07 10:00:00');

    Date::setTestNow();
});
