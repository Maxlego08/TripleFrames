<?php

use App\Console\Commands\FirstAdminCommand;
use App\Enums\AdminActionRetention;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| `admin:first-admin` — le seul rôle qu'aucun écran ne peut attribuer
|--------------------------------------------------------------------------
|
| Les libellés attendus sont calculés par la MÊME résolution que la commande :
| tant que `lang/fr/admin.php` ne porte pas encore les clés
| `admin.console.first_admin.*`, les deux côtés rendent la clé brute, et le
| jour où elles y entrent, les deux côtés rendent la phrase. Le test ne dépend
| donc ni de l'existence des clés ni de leur formulation — seulement du fait
| qu'aucun texte n'est écrit en dur dans la commande.
|
*/

/**
 * @param  array<string, int|string>  $replace
 */
function firstAdminLine(string $key, array $replace = []): string
{
    $full = FirstAdminCommand::LANG_PREFIX.$key;

    $line = trans($full, $replace, 'fr');

    return is_string($line) && $line !== $full ? $line : $full;
}

test('promeut un curateur existant et inscrit la ligne role.changed', function (): void {
    $curator = User::factory()->curator()->create(['email' => 'curation@tripleframes.test']);

    $this->artisan('admin:first-admin', [
        'email' => 'curation@tripleframes.test',
        '--real-name' => 'Camille Martin',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($curator->refresh()->role)->toBe(UserRole::Admin);

    $action = AdminAction::query()->latest('id')->first();

    expect($action)->not->toBeNull()
        ->and($action->action)->toBe(AdminActionType::RoleChanged)
        ->and($action->actor_id)->toBeNull()
        ->and($action->actor_name)->toBe(AdminAction::CONSOLE_ACTOR)
        ->and($action->subject_type)->toBe(AdminActionSubject::User)
        ->and($action->subject_id)->toBe($curator->id)
        ->and($action->role_before)->toBe(UserRole::Curator)
        ->and($action->role_after)->toBe(UserRole::Admin)
        ->and($action->retention_class)->toBe(AdminActionRetention::Permanent);
});

test('l’acteur de la console n’est jamais `system`, valeur réservée aux gestes automatiques', function (): void {
    User::factory()->curator()->create(['email' => 'curation@tripleframes.test']);

    $this->artisan('admin:first-admin', [
        'email' => 'curation@tripleframes.test',
        '--real-name' => 'Camille Martin',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $action = AdminAction::query()->latest('id')->first();

    expect($action->actor_name)->toBe('console')
        ->and($action->actor_name)->not->toBe(AdminAction::SYSTEM_ACTOR);
});

test('elle est idempotente : relancée sur l’administrateur en place, elle ne réécrit rien et sort en succès', function (): void {
    $admin = User::factory()->admin()->create(['email' => 'patron@tripleframes.test']);

    $this->artisan('admin:first-admin', [
        'email' => 'patron@tripleframes.test',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($admin->refresh()->role)->toBe(UserRole::Admin)
        ->and(AdminAction::query()->count())->toBe(0);
});

test('elle refuse de nommer un second administrateur tant qu’un premier existe', function (): void {
    User::factory()->admin()->create(['email' => 'patron@tripleframes.test']);
    $curator = User::factory()->curator()->create(['email' => 'second@tripleframes.test']);

    $this->artisan('admin:first-admin', [
        'email' => 'second@tripleframes.test',
        '--real-name' => 'Camille Martin',
        '--no-interaction' => true,
    ])->assertFailed();

    expect($curator->refresh()->role)->toBe(UserRole::Curator)
        ->and(AdminAction::query()->count())->toBe(0);
});

test('--force lève le refus de doublon, et le dit', function (): void {
    User::factory()->admin()->create(['email' => 'patron@tripleframes.test']);
    $curator = User::factory()->curator()->create(['email' => 'second@tripleframes.test']);

    $this->artisan('admin:first-admin', [
        'email' => 'second@tripleframes.test',
        '--force' => true,
        '--real-name' => 'Camille Martin',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($curator->refresh()->role)->toBe(UserRole::Admin)
        ->and(AdminAction::query()->count())->toBe(1);
});

test('un administrateur anonymisé ne compte pas comme administrateur en place', function (): void {
    User::factory()->admin()->create([
        'email' => 'ancien@tripleframes.test',
        'anonymized_at' => CarbonImmutable::now(),
    ]);

    $curator = User::factory()->curator()->create(['email' => 'releve@tripleframes.test']);

    $this->artisan('admin:first-admin', [
        'email' => 'releve@tripleframes.test',
        '--real-name' => 'Camille Martin',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($curator->refresh()->role)->toBe(UserRole::Admin);
});

test('une pierre tombale ne se promeut pas', function (): void {
    $tombstone = User::factory()->curator()->create([
        'email' => 'efface@tripleframes.test',
        'anonymized_at' => CarbonImmutable::now(),
    ]);

    $this->artisan('admin:first-admin', [
        'email' => 'efface@tripleframes.test',
        '--real-name' => 'Camille Martin',
        '--no-interaction' => true,
    ])->assertFailed();

    expect($tombstone->refresh()->role)->toBe(UserRole::Curator);
});

test('en session non interactive, un compte absent est refusé plutôt que créé sans mot de passe', function (): void {
    $this->artisan('admin:first-admin', [
        'email' => 'personne@tripleframes.test',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('une adresse invalide est refusée avant la moindre requête', function (): void {
    $this->artisan('admin:first-admin', [
        'email' => 'pas-une-adresse',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('sans --create, un compte absent est refusé : cette commande promeut, elle n’inscrit pas', function (): void {
    $this->artisan('admin:first-admin', ['email' => 'inconnue@tripleframes.test'])
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('un compte promu dont l’e-mail n’est pas vérifié ne reste pas bloqué devant /email/verify', function (): void {
    $curator = User::factory()->curator()->unverified()->create([
        'email' => 'sans-verif@tripleframes.test',
    ]);

    $this->artisan('admin:first-admin', [
        'email' => 'sans-verif@tripleframes.test',
        '--real-name' => 'Camille Martin',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($curator->refresh()->role)->toBe(UserRole::Admin)
        ->and($curator->email_verified_at)->not->toBeNull();
});

test('en session interactive avec --create, elle crée le compte, le promeut, et le mot de passe ne vient que d’une invite masquée', function (): void {
    $this->artisan('admin:first-admin', ['email' => 'premiere@tripleframes.test', '--create' => true])
        ->expectsConfirmation(
            firstAdminLine('confirm_create', ['email' => 'premiere@tripleframes.test']),
            'yes',
        )
        ->expectsQuestion(firstAdminLine('ask_name'), 'Première Curatrice')
        ->expectsQuestion(firstAdminLine('ask_password'), 'cheval-pile-agrafe-42')
        ->expectsQuestion(firstAdminLine('ask_password_confirmation'), 'cheval-pile-agrafe-42')
        ->expectsQuestion(firstAdminLine('real_name_prompt'), 'Camille Martin')
        ->assertSuccessful();

    $user = User::query()->where('email', 'premiere@tripleframes.test')->sole();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->name)->toBe('Première Curatrice')
        ->and($user->real_name)->toBe('Camille Martin')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('cheval-pile-agrafe-42', (string) $user->password))->toBeTrue();

    $action = AdminAction::query()->latest('id')->first();

    expect($action->role_before)->toBe(UserRole::Player)
        ->and($action->role_after)->toBe(UserRole::Admin);
});

test('un refus de confirmation n’écrit rien', function (): void {
    $this->artisan('admin:first-admin', ['email' => 'premiere@tripleframes.test', '--create' => true])
        ->expectsConfirmation(
            firstAdminLine('confirm_create', ['email' => 'premiere@tripleframes.test']),
            'no',
        )
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('deux mots de passe discordants n’écrivent rien', function (): void {
    $this->artisan('admin:first-admin', ['email' => 'premiere@tripleframes.test', '--create' => true])
        ->expectsConfirmation(
            firstAdminLine('confirm_create', ['email' => 'premiere@tripleframes.test']),
            'yes',
        )
        ->expectsQuestion(firstAdminLine('ask_name'), 'Première Curatrice')
        ->expectsQuestion(firstAdminLine('ask_password'), 'cheval-pile-agrafe-42')
        ->expectsQuestion(firstAdminLine('ask_password_confirmation'), 'cheval-pile-agrafe-43')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('aucune option ni aucun argument ne transporte un mot de passe en ligne de commande', function (): void {
    $definition = (new FirstAdminCommand)->getDefinition();

    expect($definition->hasOption('password'))->toBeFalse()
        ->and($definition->hasArgument('password'))->toBeFalse();
});

test('la commande exige un nom réel et le pose sur le compte', function (): void {
    $curator = User::factory()->curator()->create([
        'email' => 'curation@tripleframes.test',
        'real_name' => 'Nom Précédent',
    ]);

    // Session non interactive sans l'option : refus, et RIEN n'est écrit —
    // ni rôle, ni nom réel, ni ligne de journal.
    $this->artisan('admin:first-admin', [
        'email' => 'curation@tripleframes.test',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain(firstAdminLine('real_name_required'))
        ->assertFailed();

    expect($curator->refresh()->role)->toBe(UserRole::Curator)
        ->and($curator->real_name)->toBe('Nom Précédent')
        ->and(AdminAction::query()->count())->toBe(0);

    // Un nom réservé au journal n'est jamais un nom réel.
    $this->artisan('admin:first-admin', [
        'email' => 'curation@tripleframes.test',
        '--real-name' => 'Console',
        '--no-interaction' => true,
    ])->assertFailed();

    expect($curator->refresh()->role)->toBe(UserRole::Curator)
        ->and(AdminAction::query()->count())->toBe(0);

    // Session interactive sans l'option : une invite, et la réponse rognée
    // est posée sur le compte.
    $this->artisan('admin:first-admin', ['email' => 'curation@tripleframes.test'])
        ->expectsQuestion(firstAdminLine('real_name_prompt'), '  Camille Martin  ')
        ->assertSuccessful();

    expect($curator->refresh()->role)->toBe(UserRole::Admin)
        ->and($curator->real_name)->toBe('Camille Martin')
        ->and($curator->name)->not->toBe('Camille Martin');
});

test('la ligne role.changed porte console et un actor_id nul', function (): void {
    $player = User::factory()->create(['email' => 'joueuse@tripleframes.test']);

    $this->artisan('admin:first-admin', [
        'email' => 'joueuse@tripleframes.test',
        '--real-name' => 'Camille Martin',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $action = AdminAction::query()->sole();

    expect($action->action)->toBe(AdminActionType::RoleChanged)
        ->and($action->actor_name)->toBe(AdminAction::CONSOLE_ACTOR)
        ->and($action->actor_id)->toBeNull()
        ->and($action->subject_type)->toBe(AdminActionSubject::User)
        ->and($action->subject_id)->toBe($player->id)
        ->and($action->role_before)->toBe(UserRole::Player)
        ->and($action->role_after)->toBe(UserRole::Admin)
        // Le nom réel signe les gestes SUIVANTS de l'administrateur ; la ligne
        // de sa propre nomination, elle, est celle de la console.
        ->and($action->actor_name)->not->toBe('Camille Martin');
});

test('relancer la commande sur l\'administrateur en place avec un autre nom réel le corrige sans écrire role.changed', function (): void {
    $admin = User::factory()->admin()->create([
        'email' => 'patron@tripleframes.test',
        'real_name' => 'Camile Martin',
    ]);

    $this->artisan('admin:first-admin', [
        'email' => 'patron@tripleframes.test',
        '--real-name' => 'Camille Martin',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain(firstAdminLine('real_name_updated', ['email' => 'patron@tripleframes.test']))
        ->assertSuccessful();

    expect($admin->refresh()->real_name)->toBe('Camille Martin')
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and(AdminAction::query()->count())->toBe(0);

    // Même nom : rien à corriger, l'idempotence est intacte.
    $this->artisan('admin:first-admin', [
        'email' => 'patron@tripleframes.test',
        '--real-name' => 'Camille Martin',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain(firstAdminLine('unchanged', ['email' => 'patron@tripleframes.test']))
        ->assertSuccessful();

    // Sans l'option : l'idempotence existante, inchangée.
    $this->artisan('admin:first-admin', [
        'email' => 'patron@tripleframes.test',
        '--no-interaction' => true,
    ])->assertSuccessful();

    // Un nom réservé est refusé, et le nom en place reste.
    $this->artisan('admin:first-admin', [
        'email' => 'patron@tripleframes.test',
        '--real-name' => 'SYSTEM',
        '--no-interaction' => true,
    ])->assertFailed();

    expect($admin->refresh()->real_name)->toBe('Camille Martin')
        ->and(AdminAction::query()->count())->toBe(0);
});
