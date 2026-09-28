<?php

use App\Actions\Admin\ChangeUserRole;
use App\Actions\Admin\CorrectRealName;
use App\Enums\AdminActionRetention;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\Locale;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\AccessController;
use App\Models\AdminAction;
use App\Models\FrameReview;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Gestion des accès — spec 20 § 2.8, lot L20-19
|--------------------------------------------------------------------------
|
| Administrateur seul. Deux gestes, chacun journalisé dans la transaction de
| l'état qu'il justifie : attribuer ou retirer un rôle (`role.changed`),
| corriger un nom réel (`user.real_name_changed`, EN20-3). Les refus d'état
| sont des erreurs traduites relues sous verrou, jamais des 403, et un geste
| refusé n'écrit rien.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
});

/** Un texte du domaine `admin`, qui doit exister. */
function accessText(string $key): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, [], Locale::French->value);
}

/**
 * Le geste de changement de rôle, posté depuis la fiche du compte visé.
 *
 * @param  array<string, mixed>  $payload
 */
function accessChangeRole(User $actor, User $target, array $payload): TestResponse
{
    return test()
        ->actingAs($actor)
        ->from(route('admin.users.show', ['user' => $target->id]))
        ->patch(route('admin.access.update', ['user' => $target->id]), $payload);
}

/**
 * La correction du nom réel, postée depuis la fiche du compte visé.
 *
 * @param  array<string, mixed>  $payload
 */
function accessCorrectRealName(User $actor, User $target, array $payload): TestResponse
{
    return test()
        ->actingAs($actor)
        ->from(route('admin.users.show', ['user' => $target->id]))
        ->patch(route('admin.access.real_name.update', ['user' => $target->id]), $payload);
}

test('seul un administrateur change un rôle', function (): void {
    $target = User::factory()->player()->create();

    foreach ([User::factory()->player()->create(), User::factory()->curator()->create()] as $refused) {
        accessChangeRole($refused, $target, ['role' => 'curator', 'real_name' => 'Camille Martin'])
            ->assertForbidden();
    }

    expect($target->fresh()?->role)->toBe(UserRole::Player)
        ->and(AdminAction::query()->count())->toBe(0);

    $admin = User::factory()->admin()->create();

    accessChangeRole($admin, $target, ['role' => 'curator', 'real_name' => 'Camille Martin'])
        ->assertRedirect(route('admin.users.show', ['user' => $target->id]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', accessText('admin.access.flash.role_changed'));

    expect($target->fresh()?->role)->toBe(UserRole::Curator);
});

test('le dernier administrateur non anonymisé ne peut être rétrogradé', function (): void {
    $admin = User::factory()->admin()->create();

    // Un administrateur anonymisé ne compte pas (spec 10 § 5.5) : `admin`
    // est le dernier.
    User::factory()->admin()->create(['anonymized_at' => now()]);

    foreach (['curator', 'player'] as $role) {
        accessChangeRole($admin, $admin, ['role' => $role])
            ->assertSessionHasErrors(['role' => accessText('admin.access.errors.last_admin')]);
    }

    expect($admin->fresh()?->role)->toBe(UserRole::Admin)
        ->and(AdminAction::query()->count())->toBe(0);

    // Avec un second administrateur, le premier se rétrograde par l'autre,
    // jamais par lui-même…
    $second = User::factory()->admin()->create();

    accessChangeRole($admin, $admin, ['role' => 'curator'])
        ->assertSessionHasErrors(['role' => accessText('admin.access.errors.self')]);

    accessChangeRole($second, $admin, ['role' => 'curator'])->assertSessionHasNoErrors();

    expect($admin->fresh()?->role)->toBe(UserRole::Curator);

    // … et le second est désormais le dernier.
    accessChangeRole($second, $second, ['role' => 'player'])
        ->assertSessionHasErrors(['role' => accessText('admin.access.errors.last_admin')]);

    expect($second->fresh()?->role)->toBe(UserRole::Admin)
        ->and(AdminAction::query()->count())->toBe(1);
});

test('un administrateur rétrogradé entre la garde et le verrou ne signe plus rien', function (): void {
    $actor = User::factory()->admin()->create();
    User::factory()->admin()->create();
    $player = User::factory()->player()->create();
    $curator = User::factory()->curator()->create(['real_name' => 'Camile Durand']);

    // L'instance a passé la garde de la route ; la ligne, elle, a changé.
    User::query()->whereKey($actor->id)->toBase()->update(['role' => UserRole::Curator->value]);

    expect(fn () => app(ChangeUserRole::class)->handle($actor, $player, UserRole::Curator, 'Camille Durand', null))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(CorrectRealName::class)->handle($actor, $curator, 'Camille Durand', null))
        ->toThrow(AuthorizationException::class);

    expect($player->fresh()?->role)->toBe(UserRole::Player)
        ->and($curator->fresh()?->real_name)->toBe('Camile Durand')
        ->and(AdminAction::query()->count())->toBe(0);
});

test('chaque changement écrit role.changed avec les rôles avant et après', function (): void {
    $admin = User::factory()->admin()->create(['real_name' => 'Alex Martin']);
    $target = User::factory()->player()->create();

    accessChangeRole($admin, $target, [
        'role' => 'curator',
        'real_name' => 'Camille Durand',
        'reason' => '  Renfort de curation.  ',
    ])->assertSessionHasNoErrors();

    accessChangeRole($admin, $target, ['role' => 'admin'])->assertSessionHasNoErrors();

    accessChangeRole($admin, $target, ['role' => 'player', 'reason' => '   '])->assertSessionHasNoErrors();

    $lines = AdminAction::query()->orderBy('id')->get();

    expect($lines)->toHaveCount(3);

    foreach ($lines as $line) {
        expect($line->action)->toBe(AdminActionType::RoleChanged)
            ->and($line->actor_id)->toBe($admin->id)
            ->and($line->actor_name)->toBe('Alex Martin')
            ->and($line->subject_type)->toBe(AdminActionSubject::User)
            ->and($line->subject_id)->toBe($target->id)
            ->and($line->retention_class)->toBe(AdminActionRetention::Permanent);
    }

    expect([$lines[0]->role_before, $lines[0]->role_after])->toBe([UserRole::Player, UserRole::Curator])
        ->and($lines[0]->reason)->toBe('Renfort de curation.')
        ->and([$lines[1]->role_before, $lines[1]->role_after])->toBe([UserRole::Curator, UserRole::Admin])
        ->and($lines[1]->reason)->toBeNull()
        ->and([$lines[2]->role_before, $lines[2]->role_after])->toBe([UserRole::Admin, UserRole::Player])
        ->and($lines[2]->reason)->toBeNull();
});

test('l\'attribution d\'un rôle privilégié exige un nom réel', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->player()->create(['real_name' => null]);

    accessChangeRole($admin, $target, ['role' => 'curator'])
        ->assertSessionHasErrors(['real_name' => accessText('admin.access.errors.real_name_required')]);

    // Un nom réservé au journal est refusé par la règle partagée.
    accessChangeRole($admin, $target, ['role' => 'curator', 'real_name' => 'Console'])
        ->assertSessionHasErrors('real_name');

    expect($target->fresh()?->role)->toBe(UserRole::Player)
        ->and($target->fresh()?->real_name)->toBeNull()
        ->and(AdminAction::query()->count())->toBe(0);

    // Saisi dans le même formulaire, il est posé avec le rôle.
    accessChangeRole($admin, $target, ['role' => 'curator', 'real_name' => '  Camille Durand  '])
        ->assertSessionHasNoErrors();

    expect($target->fresh()?->role)->toBe(UserRole::Curator)
        ->and($target->fresh()?->real_name)->toBe('Camille Durand');
});

test('la file des rôles privilégiés sans double authentification les liste tous', function (): void {
    $admin = User::factory()->admin()->create();
    $curatorWithout = User::factory()->curator()->withoutTwoFactor()->create();
    $adminWithout = User::factory()->admin()->withoutTwoFactor()->create();
    // Secret posé, jamais confirmé : toujours sans double authentification.
    $pending = User::factory()->curator()->withoutTwoFactor()->create(['two_factor_secret' => encrypt('secret')]);

    // Ni un joueur sans second facteur, ni une pierre tombale, ni un
    // privilégié confirmé.
    User::factory()->player()->create();
    User::factory()->anonymized()->create();
    User::factory()->curator()->create();

    $this->actingAs($admin)
        ->get(route('admin.access.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/access/index')
            ->has('without_two_factor', 3)
            ->where('without_two_factor', fn ($rows): bool => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$curatorWithout->id, $adminWithout->id, $pending->id])->sort()->values()->all()));
});

test('seul un administrateur corrige un nom réel, et les instantanés déjà figés ne changent pas', function (): void {
    $curator = User::factory()->curator()->create(['real_name' => 'Camile Durand']);
    $review = FrameReview::factory()->by($curator)->create();
    $signed = AdminAction::factory()->byActor($curator)->of(AdminActionType::MoviePublished, 4242)->create();

    foreach ([User::factory()->player()->create(), User::factory()->curator()->create()] as $refused) {
        accessCorrectRealName($refused, $curator, ['real_name' => 'Camille Durand'])->assertForbidden();
    }

    // Un joueur n'a pas de nom réel à corriger.
    $admin = User::factory()->admin()->create();

    accessCorrectRealName($admin, User::factory()->player()->create(), ['real_name' => 'Camille Durand'])
        ->assertForbidden();

    expect($curator->fresh()?->real_name)->toBe('Camile Durand');

    accessCorrectRealName($admin, $curator, ['real_name' => 'Camille Durand'])
        ->assertRedirect(route('admin.users.show', ['user' => $curator->id]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', accessText('admin.access.flash.real_name_corrected'));

    expect($curator->fresh()?->real_name)->toBe('Camille Durand')
        ->and($review->fresh()?->reviewer_name)->toBe('Camile Durand')
        ->and($signed->fresh()?->actor_name)->toBe('Camile Durand');

    // Un nom identique est un refus traduit, jamais une ligne vide de sens.
    $lines = AdminAction::query()->count();

    accessCorrectRealName($admin, $curator, ['real_name' => 'Camille Durand'])
        ->assertSessionHasErrors(['real_name' => accessText('admin.access.errors.real_name_unchanged')]);

    expect(AdminAction::query()->count())->toBe($lines);
});

test('corriger un nom réel écrit user.real_name_changed dans la même transaction', function (): void {
    $admin = User::factory()->admin()->create(['real_name' => 'Alex Martin']);
    $curator = User::factory()->curator()->create(['real_name' => 'Camile Durand']);

    accessCorrectRealName($admin, $curator, ['real_name' => 'Camille Durand', 'reason' => 'Faute de frappe.'])
        ->assertSessionHasNoErrors();

    $line = AdminAction::query()->where('action', AdminActionType::UserRealNameChanged)->sole();

    expect($line->actor_id)->toBe($admin->id)
        ->and($line->actor_name)->toBe('Alex Martin')
        ->and($line->subject_type)->toBe(AdminActionSubject::User)
        ->and($line->subject_id)->toBe($curator->id)
        ->and($line->reason)->toBe('Faute de frappe.')
        ->and($line->role_before)->toBeNull()
        ->and($line->role_after)->toBeNull()
        ->and($line->retention_class)->toBe(AdminActionRetention::Permanent);

    // Même transaction : une ligne que la garde du journal refuse — un motif
    // au-delà de la colonne, que seule la requête HTTP aurait borné — annule
    // la correction avec elle.
    expect(fn () => app(CorrectRealName::class)->handle(
        $admin,
        $curator,
        'Camille Durand-Petit',
        str_repeat('é', AdminAction::REASON_MAX_LENGTH + 1),
    ))->toThrow(LogicException::class);

    expect($curator->fresh()?->real_name)->toBe('Camille Durand')
        ->and(AdminAction::query()->count())->toBe(1);

    // Et de même pour un changement de rôle.
    $player = User::factory()->player()->create();

    expect(fn () => app(ChangeUserRole::class)->handle(
        $admin,
        $player,
        UserRole::Curator,
        'Dominique Leroy',
        str_repeat('é', AdminAction::REASON_MAX_LENGTH + 1),
    ))->toThrow(LogicException::class);

    expect($player->fresh()?->role)->toBe(UserRole::Player)
        ->and($player->fresh()?->real_name)->toBeNull()
        ->and(AdminAction::query()->count())->toBe(1);
});

test('la correction refuse un nom réel vide, trop court, réservé ou trop long', function (): void {
    $admin = User::factory()->admin()->create();
    $curator = User::factory()->curator()->create(['real_name' => 'Camille Durand']);

    foreach (['', 'x', 'System', ' console ', str_repeat('a', 256)] as $invalid) {
        accessCorrectRealName($admin, $curator, ['real_name' => $invalid])
            ->assertSessionHasErrors('real_name');
    }

    expect($curator->fresh()?->real_name)->toBe('Camille Durand')
        ->and(AdminAction::query()->count())->toBe(0);
});

test('une cible anonymisée ou rétrogradée entre la garde et le verrou est refusée sous le verrou', function (): void {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->player()->create();
    $anonymizedCurator = User::factory()->curator()->create(['real_name' => 'Camille Durand']);
    $demotedCurator = User::factory()->curator()->create(['real_name' => 'Dominique Leroy']);

    // Les instances ont passé la garde de la route ; les lignes, elles, ont
    // changé avant le verrou.
    User::query()->whereKey($player->id)->toBase()->update(['anonymized_at' => now()]);
    User::query()->whereKey($anonymizedCurator->id)->toBase()->update(['anonymized_at' => now()]);
    User::query()->whereKey($demotedCurator->id)->toBase()->update(['role' => UserRole::Player->value]);

    expect(fn () => app(ChangeUserRole::class)->handle($admin, $player, UserRole::Curator, 'Alex Martin', null))
        ->toThrow(AuthorizationException::class);

    foreach ([$anonymizedCurator, $demotedCurator] as $stale) {
        expect(fn () => app(CorrectRealName::class)->handle($admin, $stale, 'Nom Corrigé', null))
            ->toThrow(AuthorizationException::class);
    }

    expect($player->fresh()?->role)->toBe(UserRole::Player)
        ->and($anonymizedCurator->fresh()?->real_name)->toBe('Camille Durand')
        ->and($demotedCurator->fresh()?->real_name)->toBe('Dominique Leroy')
        ->and(AdminAction::query()->count())->toBe(0);
});

test('un compte privilégié anonymisé n\'apparaît ni dans les listes ni dans le décompte des administrateurs', function (): void {
    $admin = User::factory()->admin()->create();
    // Des pierres tombales qui portent encore un rôle privilégié et une
    // adresse : l'exclusion doit venir de `anonymized_at`, pas du rôle.
    $ghostCurator = User::factory()->curator()->withoutTwoFactor()->create(['anonymized_at' => now()]);
    $ghostAdmin = User::factory()->admin()->create(['anonymized_at' => now()]);
    User::factory()->player()->create(['email' => 'efface@example.test', 'anonymized_at' => now()]);

    $this->actingAs($admin)
        ->get(route('admin.access.index', ['email' => 'efface@example.test']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('privileged', fn ($rows): bool => collect($rows)->pluck('id')->all() === [$admin->id])
            ->where('without_two_factor', [])
            ->where('admins_count', 1)
            ->where('candidate.email', 'efface@example.test')
            ->where('candidate.account', null));

    expect(User::query()->whereKey([$ghostCurator->id, $ghostAdmin->id])->count())->toBe(2);
});

test('un administrateur ne change jamais son propre rôle', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create();

    accessChangeRole($admin, $admin, ['role' => 'curator'])
        ->assertSessionHasErrors(['role' => accessText('admin.access.errors.self')]);

    expect($admin->fresh()?->role)->toBe(UserRole::Admin)
        ->and(AdminAction::query()->count())->toBe(0);
});

test('un rôle inchangé est refusé sans écrire de ligne', function (): void {
    $admin = User::factory()->admin()->create();
    $curator = User::factory()->curator()->create();

    accessChangeRole($admin, $curator, ['role' => 'curator'])
        ->assertSessionHasErrors(['role' => accessText('admin.access.errors.unchanged')]);

    expect(AdminAction::query()->count())->toBe(0);
});

test('un rôle privilégié est refusé à une adresse non vérifiée', function (): void {
    $admin = User::factory()->admin()->create();
    $unverified = User::factory()->player()->unverified()->create();
    $withoutEmail = User::factory()->player()->withoutEmail()->create();

    foreach ([$unverified, $withoutEmail] as $target) {
        accessChangeRole($admin, $target, ['role' => 'curator', 'real_name' => 'Camille Durand'])
            ->assertSessionHasErrors(['role' => accessText('admin.access.errors.email_unverified')]);

        expect($target->fresh()?->role)->toBe(UserRole::Player);
    }

    expect(AdminAction::query()->count())->toBe(0);
});

test('une pierre tombale ne reçoit aucun rôle', function (): void {
    $admin = User::factory()->admin()->create();
    $tombstone = User::factory()->anonymized()->create();

    accessChangeRole($admin, $tombstone, ['role' => 'curator', 'real_name' => 'Camille Durand'])
        ->assertForbidden();

    expect($tombstone->fresh()?->role)->toBe(UserRole::Player);
});

test('le nom réel est conservé à la rétrogradation et jamais réécrit par le changement de rôle', function (): void {
    $admin = User::factory()->admin()->create();
    $curator = User::factory()->curator()->create(['real_name' => 'Camille Durand']);

    accessChangeRole($admin, $curator, ['role' => 'player'])->assertSessionHasNoErrors();

    expect($curator->fresh()?->role)->toBe(UserRole::Player)
        ->and($curator->fresh()?->real_name)->toBe('Camille Durand');

    // Re-promu : le nom réel déjà porté suffit, et un nom saisi ne le
    // remplace pas — le corriger est un autre geste, journalisé à part.
    accessChangeRole($admin, $curator, ['role' => 'curator', 'real_name' => 'Autre Nom'])
        ->assertSessionHasNoErrors();

    expect($curator->fresh()?->real_name)->toBe('Camille Durand')
        ->and(AdminAction::query()->where('action', AdminActionType::UserRealNameChanged)->count())->toBe(0);
});

test('la recherche par adresse exacte trouve un compte à promouvoir', function (): void {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->player()->create(['email' => 'camille@example.test']);
    $tombstone = User::factory()->anonymized()->create();

    $this->actingAs($admin)
        ->get(route('admin.access.index', ['email' => 'camille@example.test']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/access/index')
            ->where('candidate.email', 'camille@example.test')
            ->where('candidate.account.id', $player->id)
            ->where('candidate.account.role', 'player')
            ->where('candidate.account.abilities.updateRole', true)
            ->where('candidate.account.abilities.updateRealName', false)
            ->where('candidate.account.is_self', false));

    // Une adresse partielle ne trouve rien : la recherche est exacte.
    $this->actingAs($admin)
        ->get(route('admin.access.index', ['email' => 'camille@']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidate.email', 'camille@')
            ->where('candidate.account', null));

    // Sans recherche, aucun candidat.
    $this->actingAs($admin)
        ->get(route('admin.access.index'))
        ->assertInertia(fn (Assert $page) => $page->where('candidate', null));

    expect($tombstone->email)->toBeNull();
});

test('l\'écran des accès liste les comptes privilégiés et l\'historique borné', function (): void {
    $admin = User::factory()->admin()->create(['real_name' => 'Alex Martin']);
    $curator = User::factory()->curator()->create(['real_name' => 'Camille Durand']);
    User::factory()->player()->create();
    User::factory()->anonymized()->create();

    accessChangeRole($admin, User::factory()->player()->create(), ['role' => 'curator', 'real_name' => 'Dominique Leroy'])
        ->assertSessionHasNoErrors();
    accessCorrectRealName($admin, $curator, ['real_name' => 'Camille Durand-Petit'])->assertSessionHasNoErrors();

    // Une ligne d'un autre geste n'entre pas dans l'historique des accès.
    AdminAction::factory()->byActor($admin)->of(AdminActionType::MoviePublished, 4242)->create();

    $this->actingAs($admin)
        ->get(route('admin.access.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/access/index')
            ->has('privileged', 3)
            // Les administrateurs d'abord.
            ->where('privileged.0.id', $admin->id)
            ->where('privileged.0.is_self', true)
            ->where('admins_count', 1)
            ->where('second_admin_missing', true)
            ->where('history_limit', AccessController::HISTORY_LIMIT)
            ->has('history', 2)
            ->where('history.0.action', 'user.real_name_changed')
            ->where('history.0.subject.id', $curator->id)
            ->where('history.0.actor_name', 'Alex Martin')
            ->where('history.1.action', 'role.changed')
            ->where('history.1.role_before', 'player')
            ->where('history.1.role_after', 'curator'));

    // Deux administrateurs nominatifs : l'avertissement disparaît.
    User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.access.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('admins_count', 2)
            ->where('second_admin_missing', false));
});

test('l\'historique de l\'écran des accès est borné et le dit', function (): void {
    $admin = User::factory()->admin()->create();

    AdminAction::factory()
        ->count(AccessController::HISTORY_LIMIT + 5)
        ->byActor($admin)
        ->of(AdminActionType::UserRealNameChanged, $admin->id)
        ->create();

    $this->actingAs($admin)
        ->get(route('admin.access.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('history', AccessController::HISTORY_LIMIT)
            ->where('history_limit', AccessController::HISTORY_LIMIT));
});
