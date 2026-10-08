<?php

use App\Enums\ImportSource;
use App\Enums\UserRole;
use App\Models\Movie;
use App\Models\SettingPreset;
use App\Models\User;
use App\Support\Frames\FrameStoragePrefix;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\DemoCatalogueSeeder;
use Database\Seeders\PreprodCurationAccountsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Catalogue de démonstration et environnements — spec 100 § 8 (règle 2) et § 18
|--------------------------------------------------------------------------
|
| Liste blanche : le catalogue de démonstration n'entre qu'en `local`,
| `testing` et `staging` (la préproduction, n° 5 de D66 du 07/10) ; les comptes
| de démonstration, au mot de passe publié dans le dépôt, qu'en `local` et
| `testing`. En production, `DatabaseSeeder` ne pose que les données du site.
|
*/

beforeEach(function (): void {
    // Les fichiers ne participent à aucune transaction (DemoCatalogueChainTest).
    Storage::fake(FrameStoragePrefix::DISK);
});

/** Joue le point d'entrée sous l'environnement demandé. */
function seedUnderEnvironment(string $environment): void
{
    app()->detectEnvironment(static fn (): string => $environment);

    expect(app()->environment())->toBe($environment);

    // --force : en production, db:seed demande sinon une confirmation.
    test()->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
}

/** @return list<string> */
function demoAccountEmails(): array
{
    return [DemoAccountsSeeder::PLAYER_EMAIL, DemoAccountsSeeder::CURATOR_EMAIL, DemoAccountsSeeder::ADMIN_EMAIL];
}

it('le catalogue de démonstration n\'est jamais seedé en production, et l\'est en staging', function (): void {
    seedUnderEnvironment('production');

    expect(SettingPreset::query()->count())->toBeGreaterThan(0)
        ->and(Movie::query()->where('import_source', ImportSource::Demo)->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);

    // Un appel direct par --class ne contourne pas la garde.
    expect(fn () => (new DemoCatalogueSeeder)->run())->toThrow(RuntimeException::class)
        ->and(fn () => (new DemoAccountsSeeder)->run())->toThrow(RuntimeException::class)
        ->and(fn () => (new PreprodCurationAccountsSeeder)->run())->toThrow(RuntimeException::class);

    seedUnderEnvironment('staging');

    expect(Movie::query()->where('import_source', ImportSource::Demo)->count())->toBe(DemoCatalogueSeeder::DEMO_MOVIE_COUNT);
});

it('ne pose en staging aucun compte de démonstration, et signe le catalogue par deux comptes inouvrables', function (): void {
    seedUnderEnvironment('staging');

    expect(User::query()->whereIn('email', demoAccountEmails())->exists())->toBeFalse()
        ->and(fn () => (new DemoAccountsSeeder)->run())->toThrow(RuntimeException::class);

    $curator = PreprodCurationAccountsSeeder::account(UserRole::Curator);
    $admin = PreprodCurationAccountsSeeder::account(UserRole::Admin);

    // Les deux seuls comptes de la base, et aucun ne s'ouvre : mot de passe
    // aléatoire (jamais celui, publié, des comptes de démonstration), adresse
    // non vérifiée sous un TLD réservé.
    expect(User::query()->count())->toBe(2);

    foreach ([$curator, $admin] as $account) {
        expect(Hash::check(DemoAccountsSeeder::PASSWORD, $account->password))->toBeFalse()
            ->and($account->email_verified_at)->toBeNull()
            ->and($account->email)->toEndWith('.test')
            ->and(trim((string) $account->real_name))->not->toBe('');
    }

    expect($curator->role)->toBe(UserRole::Curator)
        ->and($admin->role)->toBe(UserRole::Admin);

    // Le catalogue est signé par le curateur de préproduction.
    $signers = Movie::query()
        ->where('import_source', ImportSource::Demo)
        ->whereNotNull('content_verified_by_id')
        ->distinct()
        ->pluck('content_verified_by_id')
        ->all();

    expect($signers)->toBe([$curator->id]);

    // Rejouer est inoffensif : aucun mot de passe régénéré, aucun doublon.
    $password = $curator->password;

    seedUnderEnvironment('staging');

    expect(User::query()->count())->toBe(2)
        ->and($curator->fresh()?->password)->toBe($password)
        ->and(Movie::query()->where('import_source', ImportSource::Demo)->count())->toBe(DemoCatalogueSeeder::DEMO_MOVIE_COUNT);
});

it('garde en local les comptes de démonstration comme auteurs du catalogue', function (): void {
    seedUnderEnvironment('local');

    expect(User::query()->whereIn('email', demoAccountEmails())->count())->toBe(3)
        ->and(User::query()->whereIn('email', [PreprodCurationAccountsSeeder::CURATOR_EMAIL, PreprodCurationAccountsSeeder::ADMIN_EMAIL])->exists())->toBeFalse()
        ->and(Movie::query()->where('import_source', ImportSource::Demo)->count())->toBe(DemoCatalogueSeeder::DEMO_MOVIE_COUNT);
});
