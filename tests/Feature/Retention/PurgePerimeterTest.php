<?php

use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Models\Frame;
use App\Models\PurgeRun;
use App\Models\SavedConfig;
use App\Models\TmdbCompany;
use App\Support\Frames\FrameStoragePrefix;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Retention\RetentionRows;

/*
|--------------------------------------------------------------------------
| Le périmètre INTERDIT de la purge — 10 § 11.2, spec 100 § 14
|--------------------------------------------------------------------------
|
| Forme exécutable du § 11.2 : le catalogue, la banque d'images, les actifs
| du compte et la conformité survivent à la purge, quel que soit leur âge.
| Chaque test exécute TOUS les périmètres de `PurgeScope::implemented()`,
| avec des lignes réellement éligibles dans chacun : il se rejoue de lui-même
| à chaque périmètre ajouté, et un périmètre sans jeu de lignes le fait
| échouer ({@see RetentionRows::seed()}).
|
*/

beforeEach(function (): void {
    Storage::fake(FrameStoragePrefix::DISK);

    $this->travelTo(CarbonImmutable::parse('2026-09-24 02:10:00', 'UTC'));
});

/**
 * Le compte des lignes de chaque table du périmètre interdit (10 § 11.2) ;
 * pour `admin_action`, les seules lignes de classe `permanent`.
 *
 * @return array<string, int>
 */
function purgePerimeterSnapshot(): array
{
    $tables = [
        // Catalogue.
        'movie', 'movie_projection', 'movie_group', 'collection', 'movie_title', 'alias', 'answer_key',
        'movie_certification', 'movie_tmdb_tag', 'tmdb_company', 'theme', 'theme_label', 'movie_theme', 'import_run',
        // Images.
        'frame', 'frame_review',
        // Actifs du compte.
        'saved_config', 'linked_account', 'user_consent', 'users', 'setting_preset',
        // Conformité.
        'takedown_request',
    ];

    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    $counts['admin_action (permanent)'] = DB::table('admin_action')->where('retention_class', 'permanent')->count();

    return $counts;
}

/**
 * Des lignes éligibles dans chaque périmètre implémenté, puis la purge telle
 * que la restauration la joue (`purge:run --sync`) : chaque périmètre doit
 * avoir tourné ET supprimé.
 *
 * Rend l'instantané du périmètre interdit pris APRÈS l'ensemencement et
 * AVANT la purge : un signalement de contenu (D63 du 07/10) vise un film du
 * catalogue, que son jeu de lignes crée — la purge, elle, n'y touche jamais.
 *
 * @return array<string, int>
 */
function purgePerimeterRunAll(): array
{
    $now = CarbonImmutable::now();
    $seeded = [];

    foreach (PurgeScope::implemented() as $scope) {
        $seeded[$scope->value] = RetentionRows::seed($scope, $now);
    }

    $beforePurge = purgePerimeterSnapshot();

    test()->artisan('purge:run', ['--sync' => true])->assertSuccessful();

    $runs = PurgeRun::query()->where('ran_at', $now)->orderBy('id')->get();

    expect($runs->map(static fn (PurgeRun $run): PurgeScope => $run->scope)->all())->toBe(PurgeScope::implemented());

    foreach ($runs as $run) {
        expect($run->status)->toBe(PurgeRunStatus::Completed, $run->scope->value)
            ->and($run->rows_deleted)->toBe($seeded[$run->scope->value]['eligible'], $run->scope->value);
    }

    return $beforePurge;
}

it('une saved_config de 18 mois existe toujours après purge', function (): void {
    $now = CarbonImmutable::now();

    $this->travelTo($now->subMonths(18));
    $config = SavedConfig::factory()->asDefault()->create();
    $this->travelTo($now);

    $attributes = $config->fresh()?->getAttributes();
    $owner = $config->user()->firstOrFail()->getAttributes();
    $before = purgePerimeterSnapshot();

    expect($config->created_at->lessThan($now->subMonths(12)))->toBeTrue();

    $beforePurge = purgePerimeterRunAll();

    // « Compte compris » dans la règle des 12 mois signifie « pas
    // d'exemption », jamais « supprimé à 12 mois » : la configuration et son
    // compte sont intacts, à l'octet.
    expect(SavedConfig::query()->find($config->id)?->getAttributes())->toBe($attributes)
        ->and(DB::table('users')->where('id', $config->user_id)->first())->not->toBeNull()
        ->and($config->user()->firstOrFail()->getAttributes())->toBe($owner)
        ->and(purgePerimeterSnapshot())->toBe($beforePurge)
        ->and(array_diff_key($beforePurge, array_flip(['movie', 'movie_projection'])))->toBe(array_diff_key($before, array_flip(['movie', 'movie_projection'])));
});

it('une frame de 13 mois survit à la purge', function (): void {
    $now = CarbonImmutable::now();

    // Le catalogue de démonstration et une frame publiée, curés il y a
    // 13 mois : plus vieux que toute fenêtre de 12 mois.
    $this->travelTo($now->subMonths(13));
    $this->seed(DatabaseSeeder::class);
    $frame = Frame::factory()->published()->create();
    // `tmdb_company` est au périmètre interdit (10 § 11.2, D43 du 01/10).
    TmdbCompany::factory()->create();
    $this->travelTo($now);

    $frame = $frame->fresh() ?? throw new LogicException('Frame introuvable.');
    $attributes = $frame->getAttributes();
    $review = DB::table('frame_review')->where('id', $frame->published_review_id)->first();
    $before = purgePerimeterSnapshot();

    expect($frame->created_at->lessThan($now->subMonths(12)))->toBeTrue()
        ->and($before['frame'])->toBeGreaterThan(1)
        ->and($before['tmdb_company'])->toBeGreaterThan(0);

    $beforePurge = purgePerimeterRunAll();

    expect(Frame::query()->find($frame->id)?->getAttributes())->toBe($attributes)
        ->and(DB::table('frame_review')->where('id', $frame->published_review_id)->first())->toEqual($review)
        ->and(Storage::disk(FrameStoragePrefix::DISK)->exists((string) $frame->game_path))->toBeTrue()
        ->and(purgePerimeterSnapshot())->toBe($beforePurge)
        ->and(array_diff_key($beforePurge, array_flip(['movie', 'movie_projection'])))->toBe(array_diff_key($before, array_flip(['movie', 'movie_projection'])));
});
