<?php

use App\Concerns\RealNameValidationRules;
use App\Enums\AdminActionType;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\FrameReview;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Nom réel des comptes privilégiés — D12 du 23/09
|--------------------------------------------------------------------------
|
| `users.real_name` (`10` § 5.1, E10-02) est ce que figent
| `frame_review.reviewer_name` et `admin_action.actor_name` : il signe les
| preuves opposables du back-office. Requis pour tout rôle ≥ `curator`, jamais
| une valeur réservée du journal, jamais sérialisé vers une surface joueur,
| vidé à l'anonymisation sans que les instantanés qu'il a produits bougent.
|
*/

/**
 * Les règles partagées, lues comme un FormRequest ou la commande les lit.
 *
 * @return array<string, mixed>
 */
function realNameRulesUnderTest(): array
{
    $holder = new class
    {
        use RealNameValidationRules;

        /** @return array<int, mixed> */
        public function rules(): array
        {
            return $this->realNameRules();
        }
    };

    return ['real_name' => $holder->rules()];
}

test('un compte ne peut porter curator ou admin sans nom réel', function (): void {
    foreach ([UserRole::Curator, UserRole::Admin] as $role) {
        foreach ([null, '', '   '] as $missing) {
            expect(fn () => User::factory()->role($role)->create(['real_name' => $missing]))
                ->toThrow(LogicException::class);
        }
    }

    // La promotion d'un joueur sans nom réel est refusée à l'écriture.
    $player = User::factory()->create();
    $player->role = UserRole::Curator;

    expect(fn () => $player->save())->toThrow(LogicException::class);
    expect($player->fresh()?->role)->toBe(UserRole::Player);

    // Vider le nom réel d'un compte privilégié est refusé de même.
    $curator = User::factory()->curator()->create();
    $curator->real_name = null;

    expect(fn () => $curator->save())->toThrow(LogicException::class);
    expect($curator->fresh()?->real_name)->not->toBeNull();

    // La garde ne se déclenche que si `role` ou `real_name` change : un compte
    // privilégié antérieur à la colonne n'est jamais bloqué sur une écriture
    // sans rapport.
    $legacyId = DB::table('users')->insertGetId([
        'name' => 'Compte ancien',
        'email' => 'ancien@tripleframes.test',
        'role' => UserRole::Curator->value,
        'real_name' => null,
    ]);

    $legacy = User::query()->findOrFail($legacyId);
    $legacy->last_login_at = now();
    $legacy->save();

    expect($legacy->fresh()?->last_login_at)->not->toBeNull();

    // Un joueur, lui, n'a pas de nom réel à porter.
    expect(User::factory()->create()->real_name)->toBeNull();
});

test('system et console sont refusés comme nom réel', function (): void {
    foreach (['system', 'console', 'System', 'CONSOLE', '  Console  '] as $reserved) {
        $validator = Validator::make(['real_name' => $reserved], realNameRulesUnderTest());

        expect($validator->fails())->toBeTrue($reserved)
            ->and($validator->errors()->first('real_name'))->toBe(__('admin.validation.real_name', [], 'fr'));

        expect(fn () => User::factory()->curator()->create(['real_name' => $reserved]))
            ->toThrow(LogicException::class);
    }

    // Les bornes de la colonne, et un vrai nom qui contient le mot.
    expect(Validator::make(['real_name' => 'C'], realNameRulesUnderTest())->fails())->toBeTrue()
        ->and(Validator::make(['real_name' => str_repeat('a', 256)], realNameRulesUnderTest())->fails())->toBeTrue()
        ->and(Validator::make(['real_name' => ''], realNameRulesUnderTest())->fails())->toBeTrue()
        ->and(Validator::make(['real_name' => 'Camille Martin'], realNameRulesUnderTest())->passes())->toBeTrue()
        ->and(Validator::make(['real_name' => 'Systemia Consolo'], realNameRulesUnderTest())->passes())->toBeTrue();

    // Le message de refus ne nomme pas les valeurs réservées : il s'affichera
    // un jour à un administrateur, sur l'écran de gestion des accès.
    expect(__('admin.validation.real_name', [], 'fr'))->not->toContain('console')
        ->and(__('admin.validation.real_name', [], 'fr'))->not->toContain('system');

    expect(User::query()->whereIn('real_name', ['system', 'console'])->exists())->toBeFalse();
});

test('real_name n\'est jamais sérialisé', function (): void {
    $curator = User::factory()->curator()->create(['real_name' => 'Camille Martin']);
    $admin = User::factory()->admin()->create(['real_name' => 'Alex Durand']);

    foreach ([$curator, $admin] as $user) {
        $fresh = $user->fresh();

        expect($fresh?->getAttributes())->toHaveKey('real_name')
            ->and($fresh?->toArray())->not->toHaveKey('real_name')
            ->and((string) $fresh?->toJson())->not->toContain((string) $user->real_name);
    }

    // `HandleInertiaRequests` sérialise l'utilisateur sur TOUTES les pages :
    // la page du compte comme celle du back-office.
    $this->actingAs($curator->fresh())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $curator->id)
            ->missing('auth.user.real_name'))
        ->assertDontSee('Camille Martin');

    $this->actingAs($admin->fresh())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $admin->id)
            ->missing('auth.user.real_name'))
        ->assertDontSee('Alex Durand');
});

test('actor_name et reviewer_name figent le nom réel à l\'instant du geste', function (): void {
    $journal = app(AdminJournal::class);
    $admin = User::factory()->admin()->create(['name' => 'pseudo-admin', 'real_name' => '  Alex Durand ']);
    $curator = User::factory()->curator()->create(['name' => 'pseudo-curation', 'real_name' => 'Camille Martin']);

    $action = DB::transaction(fn (): AdminAction => $journal->record(
        $admin,
        AdminActionType::MovieUnpublished,
        4242,
        'Doublon d’un film déjà publié.',
    ));
    $review = FrameReview::factory()->by($curator)->create();

    // Le nom réel, rogné — jamais le pseudo de compte.
    expect($action->fresh()?->actor_name)->toBe('Alex Durand')
        ->and($review->fresh()?->reviewer_name)->toBe('Camille Martin');

    // Une correction ultérieure ne vaut que pour les gestes SUIVANTS.
    $admin->real_name = 'Alexandre Durand';
    $admin->save();
    $curator->real_name = 'Camille Martin-Leroy';
    $curator->save();

    $next = DB::transaction(fn (): AdminAction => $journal->record(
        $admin,
        AdminActionType::MovieRepublished,
        4242,
    ));
    $nextReview = FrameReview::factory()->by($curator)->create();

    expect($action->fresh()?->actor_name)->toBe('Alex Durand')
        ->and($review->fresh()?->reviewer_name)->toBe('Camille Martin')
        ->and($next->fresh()?->actor_name)->toBe('Alexandre Durand')
        ->and($nextReview->fresh()?->reviewer_name)->toBe('Camille Martin-Leroy');

    // Un compte sans nom réel ne signe ni revue ni ligne de journal.
    $player = User::factory()->create();

    expect(fn () => FrameReview::factory()->by($player))->toThrow(InvalidArgumentException::class)
        ->and(fn () => AdminAction::factory()->byActor($player))->toThrow(InvalidArgumentException::class);
});

test('l\'anonymisation vide real_name et conserve les instantanés de revue et de journal', function (): void {
    $journal = app(AdminJournal::class);
    $curator = User::factory()->curator()->create(['real_name' => 'Camille Martin']);

    $action = DB::transaction(fn (): AdminAction => $journal->record(
        $curator,
        AdminActionType::MovieContentVerified,
        4242,
        'Aucune classification restrictive.',
    ));
    $review = FrameReview::factory()->by($curator)->create();

    // L'état final de la pierre tombale (`10` § 5.5), tel que la fabrique le
    // décrit : l'action d'anonymisation de `40` écrit ces colonnes-là.
    $tombstone = User::factory()->anonymized()->raw();
    $curator->forceFill(array_intersect_key($tombstone, array_flip([
        'name', 'email', 'email_verified_at', 'password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
        'role', 'real_name', 'locale', 'anonymized_at',
    ])));
    $curator->save();

    $curator = $curator->fresh();

    expect($curator?->real_name)->toBeNull()
        ->and($curator?->role)->toBe(UserRole::Player)
        ->and($curator?->anonymized_at)->not->toBeNull();

    // Les instantanés ne bougent pas : l'auteur reste nominatif.
    expect($action->fresh()?->actor_name)->toBe('Camille Martin')
        ->and($action->fresh()?->actor_id)->toBe($curator?->id)
        ->and($review->fresh()?->reviewer_name)->toBe('Camille Martin')
        ->and($review->fresh()?->reviewer_id)->toBe($curator?->id);

    // Et aucune mise à jour de masse ne peut les réécrire.
    expect(fn () => AdminAction::query()->where('actor_id', $curator?->id)->update(['actor_name' => 'anonyme']))
        ->toThrow(LogicException::class)
        ->and(fn () => FrameReview::query()->where('reviewer_id', $curator?->id)->update(['reviewer_name' => 'anonyme']))
        ->toThrow(LogicException::class);

    // La fabrique de pierre tombale vide le nom réel même après un rôle privilégié.
    expect(User::factory()->admin()->anonymized()->create()->real_name)->toBeNull();
});

test('les fabriques curator et admin produisent un compte valide sous la garde', function (): void {
    $curators = User::factory()->curator()->count(3)->create();
    $admin = User::factory()->admin()->create();

    foreach ([...$curators, $admin] as $user) {
        $fresh = $user->fresh();

        expect($fresh)->not->toBeNull()
            ->and(trim((string) $fresh?->real_name))->not->toBe('')
            ->and(AdminAction::isReservedActorName((string) $fresh?->real_name))->toBeFalse();
    }

    expect($curators->pluck('role')->unique()->all())->toBe([UserRole::Curator])
        ->and($admin->role)->toBe(UserRole::Admin);

    // Un nom réel explicite l'emporte sur le nom factice.
    expect(User::factory()->curator()->create(['real_name' => 'Camille Martin'])->real_name)->toBe('Camille Martin');

    // Les fabriques qui signent une preuve partent d'un compte valide, et
    // figent son nom réel.
    $review = FrameReview::factory()->by($curators->first())->create();
    $line = AdminAction::factory()->byActor($admin)->create();
    $default = AdminAction::factory()->create();

    expect($review->reviewer_name)->toBe($curators->first()->real_name)
        ->and($line->actor_name)->toBe($admin->real_name)
        ->and($default->actor_name)->toBe(User::query()->findOrFail($default->actor_id)->real_name);
});
