<?php

use App\Actions\Curation\SetMovieThemeMembership;
use App\Enums\ThemeMembershipState;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\User;
use App\Support\Catalog\ThemeEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Concurrence de l'appartenance aux thèmes — spec 30 § 13.1, critiques C1/C2
|--------------------------------------------------------------------------
|
| D43 du 01/10. Groupe `locks-timing` (par répertoire), MySQL réel,
| `DatabaseTruncation` : deux connexions voient les écritures l'une de
| l'autre.
|
| L'évaluateur (`ThemeEvaluator`) et le geste du curateur
| (`SetMovieThemeMembership::apply()`) écrivent la même ligne `movie_theme`,
| chacun après l'avoir relue SOUS VERROU : l'un attend l'autre, et celui qui
| passe en second relit l'état validé — l'évaluateur ne réactive jamais un
| film qu'un curateur vient de retirer, et le geste ne perd jamais le
| `is_auto` que l'évaluateur vient d'écrire.
|
| L'entrelacement est rendu déterministe, sans processus ni horloge (patron
| de `LaunchConcurrencyTest`) : le premier écrivain tient la ligne sur une
| connexion jumelle, transaction ouverte ; le second bute sur le verrou
| (`innodb_lock_wait_timeout` de session : la borne de l'attente, jamais le
| verdict) ; le premier valide ; le second, rejoué, relit l'état validé.
|
*/

/**
 * Joue `$first` sur une connexion jumelle, transaction laissée ouverte, puis
 * `$second` sur la connexion par défaut, qui doit buter sur le verrou de la
 * ligne ; valide enfin la jumelle. Rend l'exception du second.
 *
 * @param  Closure(): mixed  $first
 * @param  Closure(): mixed  $second
 */
function themeMembershipInterleave(Closure $first, Closure $second): ?QueryException
{
    $default = DB::getDefaultConnection();
    $rival = 'theme_membership_rival';

    config(["database.connections.{$rival}" => config("database.connections.{$default}")]);

    $blocked = null;

    try {
        DB::connection($rival)->beginTransaction();
        DB::setDefaultConnection($rival);

        $first();

        DB::setDefaultConnection($default);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $second();
        } catch (QueryException $exception) {
            $blocked = $exception;
        }

        DB::connection($rival)->commit();
    } finally {
        DB::setDefaultConnection($default);

        if (DB::connection($rival)->transactionLevel() > 0) {
            DB::connection($rival)->rollBack();
        }

        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        DB::purge($rival);
    }

    return $blocked;
}

/** La ligne d'appartenance, relue en base. */
function themeMembershipRow(Movie $movie, Theme $theme): ?MovieTheme
{
    return MovieTheme::query()
        ->where('movie_id', $movie->id)
        ->where('theme_id', $theme->id)
        ->first();
}

test('un geste de curation pendant une synchronisation de thème garde son exception', function (): void {
    $curator = User::factory()->curator()->create();
    $theme = Theme::factory()->genre(16)->create();
    $movie = Movie::factory()->withGenre(16)->create();
    MovieTheme::factory()->auto()->create(['movie_id' => $movie->id, 'theme_id' => $theme->id]);

    $evaluator = app(ThemeEvaluator::class);
    $membership = app(SetMovieThemeMembership::class);

    // Le curateur retire le film du thème et tient la ligne ; la
    // synchronisation du thème bute sur son verrou.
    $blocked = themeMembershipInterleave(
        fn () => $membership->apply($movie, $theme, ThemeMembershipState::Removed, $curator->id, CarbonImmutable::now()),
        fn () => $evaluator->syncTheme($theme->refresh()),
    );

    expect($blocked)->toBeInstanceOf(QueryException::class);

    // Rejouée après la validation du geste, la synchronisation relit
    // l'exception sous verrou : le film reste hors du thème.
    $evaluator->syncTheme($theme->refresh());

    $row = themeMembershipRow($movie, $theme);

    expect($row?->is_auto)->toBeTrue()
        ->and($row?->manual_state)->toBe(ThemeMembershipState::Removed)
        ->and($row?->is_active)->toBeFalse()
        ->and($row?->assigned_by_id)->toBe($curator->id);
});

test('un geste de curation après une synchronisation garde la règle qu\'elle vient d\'écrire', function (): void {
    $curator = User::factory()->curator()->create();
    $theme = Theme::factory()->genre(16)->create();
    $movie = Movie::factory()->withGenre(16)->create();
    // Une exception `removed` sur une ligne que la règle ne portait pas encore.
    MovieTheme::factory()->create([
        'movie_id' => $movie->id,
        'theme_id' => $theme->id,
        'is_auto' => false,
        'manual_state' => ThemeMembershipState::Removed,
        'is_active' => false,
        'assigned_by_id' => $curator->id,
        'assigned_at' => CarbonImmutable::now(),
    ]);

    $evaluator = app(ThemeEvaluator::class);
    $membership = app(SetMovieThemeMembership::class);

    // L'évaluateur écrit `is_auto` vrai et tient la ligne ; le geste qui
    // annule l'exception bute sur son verrou.
    $blocked = themeMembershipInterleave(
        fn () => $evaluator->syncMovie($movie),
        fn () => DB::transaction(fn () => $membership->apply($movie, $theme, null, $curator->id, CarbonImmutable::now())),
    );

    expect($blocked)->toBeInstanceOf(QueryException::class);

    // Rejoué, le geste relit `is_auto` validé : annuler l'exception rend la
    // main à la règle, qui porte le film — la ligne reste, active.
    DB::transaction(fn () => $membership->apply($movie, $theme, null, $curator->id, CarbonImmutable::now()));

    $row = themeMembershipRow($movie, $theme);

    expect($row)->not->toBeNull()
        ->and($row?->is_auto)->toBeTrue()
        ->and($row?->manual_state)->toBeNull()
        ->and($row?->is_active)->toBeTrue()
        ->and($row?->assigned_by_id)->toBeNull();
});
