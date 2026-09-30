<?php

use App\Enums\AdminActionRetention;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Journal des lectures sensibles — D41 du 30/09
|--------------------------------------------------------------------------
|
| L'annuaire, la fiche d'un compte et l'écran des accès montrent des
| données personnelles : chaque visite écrit une ligne, hors transaction,
| signée du nom réel. Un rechargement partiel ou un préchargement n'en écrit
| aucune, et ni la recherche ni l'adresse cherchée ne sont recopiées.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->admin = User::factory()->admin()->create();
});

/**
 * Le même écran rechargé partiellement, comme le fait un `router.reload`.
 */
function sensitiveReadPartial(string $url, string $component): TestResponse
{
    $page = test()->actingAs(test()->admin)->get($url)->viewData('page');

    return test()->actingAs(test()->admin)->get($url, [
        Header::INERTIA => 'true',
        Header::VERSION => (string) $page['version'],
        Header::PARTIAL_COMPONENT => $component,
        Header::PARTIAL_ONLY => 'users',
    ]);
}

test('chaque visite de l\'annuaire écrit accounts.directory_viewed, sans la recherche', function (): void {
    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['search' => 'camille@example.test']))
        ->assertOk();

    $line = AdminAction::query()->sole();

    expect($line->action)->toBe(AdminActionType::AccountsDirectoryViewed)
        ->and($line->actor_id)->toBe($this->admin->id)
        ->and($line->actor_name)->toBe($this->admin->real_name)
        ->and($line->subject_type)->toBe(AdminActionSubject::Accounts)
        ->and($line->subject_id)->toBeNull()
        ->and($line->retention_class)->toBe(AdminActionRetention::Permanent)
        ->and($line->reason)->toBeNull()
        ->and($line->details)->toBeNull();

    // Un envoi des filtres est une visite : une ligne de plus.
    $this->actingAs($this->admin)->get(route('admin.users.index', ['role' => 'admin']))->assertOk();

    expect(AdminAction::query()->count())->toBe(2);
});

test('un rechargement partiel ou un préchargement n\'écrit aucune ligne', function (): void {
    sensitiveReadPartial(route('admin.users.index'), 'admin/users/index')->assertOk();

    // La visite complète qui a fourni la version, seule.
    expect(AdminAction::query()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index'), ['Purpose' => 'prefetch'])
        ->assertOk();

    $this->actingAs($this->admin)
        ->get(route('admin.access.index'), ['Purpose' => 'prefetch'])
        ->assertOk();

    expect(AdminAction::query()->count())->toBe(1);
});

test('la fiche d\'un compte écrit user.viewed, que son historique ne montre pas', function (): void {
    $player = User::factory()->player()->create();

    $this->actingAs($this->admin)->get(route('admin.users.show', $player))->assertOk();

    $line = AdminAction::query()->sole();

    expect($line->action)->toBe(AdminActionType::UserViewed)
        ->and($line->subject_type)->toBe(AdminActionSubject::User)
        ->and($line->subject_id)->toBe($player->id)
        ->and($line->retention_class)->toBe(AdminActionRetention::Permanent);

    // La seconde visite ne voit pas la première dans l'historique des
    // gestes, et la consultation n'entre pas au compteur de preuves signées
    // de l'administrateur.
    $this->actingAs($this->admin)
        ->get(route('admin.users.show', $player))
        ->assertInertia(fn (Assert $page) => $page->has('history', 0));

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', $this->admin))
        ->assertInertia(fn (Assert $page) => $page->where('traces.admin_actions', 0));

    expect(AdminAction::query()->where('action', AdminActionType::UserViewed->value)->count())->toBe(3);
});

test('l\'écran des accès écrit une seule ligne par visite, user.looked_up quand la recherche trouve', function (): void {
    $player = User::factory()->player()->create(['email' => 'camille@example.test']);

    $this->actingAs($this->admin)->get(route('admin.access.index'))->assertOk();

    expect(AdminAction::query()->sole()->action)->toBe(AdminActionType::AccountsAccessViewed);

    // Une adresse que personne ne porte : l'écran, pas un compte.
    $this->actingAs($this->admin)
        ->get(route('admin.access.index', ['email' => 'personne@example.test']))
        ->assertOk();

    expect(AdminAction::query()->where('action', AdminActionType::AccountsAccessViewed->value)->count())->toBe(2);

    $this->actingAs($this->admin)
        ->get(route('admin.access.index', ['email' => 'camille@example.test']))
        ->assertOk();

    $found = AdminAction::query()->where('action', AdminActionType::UserLookedUp->value)->sole();

    expect($found->subject_id)->toBe($player->id)
        ->and($found->reason)->toBeNull()
        ->and($found->details)->toBeNull()
        ->and(AdminAction::query()->count())->toBe(3);
});

test('un curateur refusé aux écrans des comptes n\'écrit aucune ligne', function (): void {
    $curator = User::factory()->curator()->create();

    foreach ([route('admin.users.index'), route('admin.users.show', $this->admin), route('admin.access.index')] as $url) {
        $this->actingAs($curator)->get($url)->assertForbidden();
    }

    expect(AdminAction::query()->count())->toBe(0);
});
