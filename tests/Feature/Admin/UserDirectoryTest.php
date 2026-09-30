<?php

use App\Enums\AdminActionType;
use App\Enums\OAuthProvider;
use App\Http\Requests\Admin\UserDirectoryRequest;
use App\Models\AdminAction;
use App\Models\FrameReview;
use App\Models\LinkedAccount;
use App\Models\SavedConfig;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Annuaire des comptes et fiche d'un compte — spec 20 § 2.8, ligne 40
|--------------------------------------------------------------------------
|
| Administrateur seul, en lecture seule. Tous les comptes y figurent,
| joueurs et pierres tombales compris. La fiche ne sérialise jamais un
| secret ni une configuration sauvegardée : strictement privée, y compris
| d'un administrateur.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * Les identifiants d'une page de l'annuaire, dans son ordre.
 *
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function userDirectoryIds(User $admin, array $query = []): array
{
    $ids = [];

    test()->actingAs($admin)
        ->get(route('admin.users.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$ids): void {
            $page->component('admin/users/index');

            $ids = array_map(
                static fn (array $row): int => $row['id'],
                $page->toArray()['props']['users']['data'],
            );
        });

    return $ids;
}

test('l\'annuaire liste tous les comptes, joueurs et pierres tombales compris', function (): void {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->player()->create();
    $curator = User::factory()->curator()->create();
    $tombstone = User::factory()->anonymized()->create();

    expect(userDirectoryIds($admin))->toEqualCanonicalizing([$admin->id, $player->id, $curator->id, $tombstone->id]);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.meta.total', 4)
            ->where('counts.total', 3)
            ->where('counts.players', 1)
            ->where('counts.curators', 1)
            ->where('counts.admins', 1)
            ->where('filters.sort', UserDirectoryRequest::DEFAULT_SORT)
            ->where('filters.direction', UserDirectoryRequest::DEFAULT_DIRECTION)
            ->where('options.sort', UserDirectoryRequest::SORTS)
            ->where('options.state', UserDirectoryRequest::STATES));
});

test('un curateur ne lit ni l\'annuaire ni la fiche d\'un compte', function (): void {
    $curator = User::factory()->curator()->create();
    $player = User::factory()->player()->create();

    $this->actingAs($curator)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($curator)->get(route('admin.users.show', ['user' => $player->id]))->assertForbidden();
    $this->actingAs($curator)->get(route('admin.access.index'))->assertForbidden();
});

test('un curateur reçoit le même 403 sur un compte réel et sur un identifiant inconnu', function (): void {
    // La porte `role:admin` précède la résolution de `{user}` : sans elle,
    // 404 d'un côté et 403 de l'autre énuméreraient la table `users`.
    $curator = User::factory()->curator()->create();
    $existing = User::factory()->player()->create()->id;
    $absent = $existing + 1_000_000;

    foreach ([$existing, $absent] as $id) {
        $this->actingAs($curator)
            ->get(route('admin.users.show', ['user' => $id]))
            ->assertForbidden();

        $this->actingAs($curator)
            ->patch(route('admin.access.update', ['user' => $id]), ['role' => 'curator'])
            ->assertForbidden();

        $this->actingAs($curator)
            ->patch(route('admin.access.real_name.update', ['user' => $id]), ['real_name' => 'Camille Durand'])
            ->assertForbidden();
    }

    // Un administrateur, lui, lit bien un 404 sur un identifiant inconnu.
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.users.show', ['user' => $absent]))
        ->assertNotFound();
});

test('la recherche trouve un compte par son adresse, son pseudo, son nom réel ou son identifiant', function (): void {
    $admin = User::factory()->admin()->create(['name' => 'Admin Démo', 'real_name' => 'Alex Martin']);
    $camille = User::factory()->player()->create(['name' => 'Kamiko', 'email' => 'camille.durand@example.test']);
    $dominique = User::factory()->curator()->create(['name' => 'Domi', 'real_name' => 'Dominique Zyxwar']);

    expect(userDirectoryIds($admin, ['q' => 'durand@example']))->toBe([$camille->id])
        ->and(userDirectoryIds($admin, ['q' => 'kamik']))->toBe([$camille->id])
        ->and(userDirectoryIds($admin, ['q' => 'Zyxwar']))->toBe([$dominique->id])
        ->and(userDirectoryIds($admin, ['q' => (string) $dominique->id]))->toContain($dominique->id)
        ->and(userDirectoryIds($admin, ['q' => 'introuvable']))->toBe([]);
});

test('les filtres de rôle et d\'état et le tri s\'appliquent', function (): void {
    $admin = User::factory()->admin()->create(['last_login_at' => CarbonImmutable::parse('2026-09-01')]);
    $player = User::factory()->player()->create(['last_login_at' => CarbonImmutable::parse('2026-09-20')]);
    $curator = User::factory()->curator()->create(['last_login_at' => CarbonImmutable::parse('2026-09-10')]);
    $tombstone = User::factory()->anonymized()->create();

    expect(userDirectoryIds($admin, ['role' => 'curator']))->toBe([$curator->id])
        ->and(userDirectoryIds($admin, ['state' => 'anonymized']))->toBe([$tombstone->id])
        ->and(userDirectoryIds($admin, ['state' => 'active']))->toEqualCanonicalizing([$admin->id, $player->id, $curator->id])
        ->and(userDirectoryIds($admin, ['state' => 'active', 'sort' => 'last_login_at', 'direction' => 'desc']))
        ->toBe([$player->id, $curator->id, $admin->id]);

    // Une valeur hors liste blanche est une erreur de validation, jamais un
    // fragment de SQL.
    $this->actingAs($admin)
        ->get(route('admin.users.index', ['sort' => 'password']))
        ->assertSessionHasErrors('sort');
});

test('les tris par pseudo et par adresse suivent l\'ordre demandé', function (): void {
    $admin = User::factory()->admin()->create(['name' => 'Mona', 'email' => 'mona@example.test']);
    $bruno = User::factory()->player()->create(['name' => 'Bruno', 'email' => 'zoe-bruno@example.test']);
    $alice = User::factory()->player()->create(['name' => 'Alice', 'email' => 'yann-alice@example.test']);

    expect(userDirectoryIds($admin, ['sort' => 'name', 'direction' => 'asc']))->toBe([$alice->id, $bruno->id, $admin->id])
        ->and(userDirectoryIds($admin, ['sort' => 'email', 'direction' => 'asc']))->toBe([$admin->id, $alice->id, $bruno->id])
        ->and(userDirectoryIds($admin, ['sort' => 'email', 'direction' => 'desc']))->toBe([$bruno->id, $alice->id, $admin->id]);
});

test('à date de création égale, l\'identifiant départage et la pagination ne perd ni ne répète aucun compte', function (): void {
    $sameInstant = CarbonImmutable::parse('2026-09-01 12:00:00');
    $admin = User::factory()->admin()->create(['created_at' => $sameInstant]);
    User::factory()->count(UserDirectoryRequest::PER_PAGE + 3)->player()->create(['created_at' => $sameInstant]);

    $pages = [
        ...userDirectoryIds($admin, ['page' => 1]),
        ...userDirectoryIds($admin, ['page' => 2]),
    ];

    $expected = User::query()->orderByDesc('id')->pluck('id')->all();

    expect($pages)->toBe($expected);
});

test('l\'annuaire est paginé', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->count(UserDirectoryRequest::PER_PAGE + 3)->player()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', UserDirectoryRequest::PER_PAGE)
            ->where('users.meta.total', UserDirectoryRequest::PER_PAGE + 4)
            ->where('users.meta.last_page', 2)
            ->missing('users.links'));

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 4));
});

test('la fiche d\'un compte montre son historique, ses preuves et ses gestes possibles', function (): void {
    $admin = User::factory()->admin()->create(['real_name' => 'Alex Martin']);
    $curator = User::factory()->curator()->create(['real_name' => 'Camille Durand']);

    FrameReview::factory()->by($curator)->count(2)->create();
    AdminAction::factory()->byActor($curator)->of(AdminActionType::MoviePublished, 4242)->create();
    LinkedAccount::factory()->for($curator)->create(['provider' => OAuthProvider::Discord]);

    $this->actingAs($admin)
        ->from(route('admin.users.show', ['user' => $curator->id]))
        ->patch(route('admin.access.real_name.update', ['user' => $curator->id]), ['real_name' => 'Camille Durand-Petit'])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->get(route('admin.users.show', ['user' => $curator->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/show')
            ->where('account.id', $curator->id)
            ->where('account.role', 'curator')
            ->where('account.real_name', 'Camille Durand-Petit')
            ->where('providers', ['discord'])
            ->where('passkeys_count', 0)
            ->where('traces.frame_reviews', 2)
            ->where('traces.admin_actions', 1)
            ->where('traces.import_runs', 0)
            ->has('history', 1)
            ->where('history.0.action', 'user.real_name_changed')
            ->where('history.0.actor_name', 'Alex Martin')
            ->where('history.0.subject', null)
            ->where('abilities.updateRole', true)
            ->where('abilities.updateRealName', true)
            ->where('is_self', false));

    // Sa propre fiche : ni geste de rôle affiché par l'écran, ni refus caché.
    $this->actingAs($admin)
        ->get(route('admin.users.show', ['user' => $admin->id]))
        ->assertInertia(fn (Assert $page) => $page->where('is_self', true));

    // Une pierre tombale se lit, mais ne reçoit plus aucun rôle.
    $tombstone = User::factory()->anonymized()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.show', ['user' => $tombstone->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('account.anonymized', true)
            ->where('abilities.updateRole', false)
            ->where('abilities.updateRealName', false));
});

test('ni l\'annuaire ni la fiche ne sérialisent un secret ou une configuration sauvegardée', function (): void {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->player()->withProviderAvatar()->create([
        'two_factor_secret' => encrypt('secret-de-test'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-de-secours-de-test'])),
        'remember_token' => 'jeton-memoire-de-test',
    ]);

    SavedConfig::factory()->for($player)->named('Soirée secrète de Camille')->create();
    LinkedAccount::factory()->for($player)->create([
        'provider' => OAuthProvider::Google,
        'provider_email' => 'fournisseur-secret@example.test',
        'provider_user_id' => 'identifiant-fournisseur-secret',
    ]);

    $stored = User::query()->whereKey($player->id)->firstOrFail();
    $secrets = [
        (string) $stored->password,
        (string) $stored->two_factor_secret,
        (string) $stored->two_factor_recovery_codes,
        // Et leurs valeurs en clair : un écran qui les déchiffrerait ne
        // montrerait jamais la forme chiffrée.
        'secret-de-test',
        'code-de-secours-de-test',
        'jeton-memoire-de-test',
        (string) $stored->avatar_provider_path,
        'Soirée secrète de Camille',
        'fournisseur-secret@example.test',
        'identifiant-fournisseur-secret',
    ];

    foreach ([
        route('admin.users.index'),
        route('admin.users.show', ['user' => $player->id]),
        route('admin.access.index', ['email' => $player->email]),
    ] as $url) {
        $page = json_encode(
            $this->actingAs($admin)->get($url)->assertOk()->viewData('page'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        expect($page)->toBeString();

        foreach ($secrets as $secret) {
            expect(str_contains((string) $page, $secret))->toBeFalse("{$url} expose [{$secret}]");
        }
    }
});
