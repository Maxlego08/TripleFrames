<?php

use App\Actions\Account\RecordConsents;
use App\Enums\ConsentKind;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Models\User;
use App\Models\UserConsent;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Ré-acceptation des CGU — spec 40 § 13.1 (Q40-6, n° 12, n° 13, n° 39)
|--------------------------------------------------------------------------
|
| Un compte dont la version acceptée diffère de `legal.terms_version` voit
| ses pages de compte renvoyées vers l'interstitiel ; le jeu, le back-office,
| la déconnexion, l'export et la suppression ne le sont jamais.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
});

it("redirige un compte à version périmée vers l'interstitiel", function () {
    $user = User::factory()->outdatedTerms()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertRedirect(route('terms.show'));

    // Une écriture de compte aussi, sans rien écrire.
    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Autre Nom', 'email' => $user->email])
        ->assertRedirect(route('terms.show'));

    expect($user->fresh()?->name)->toBe($user->name);

    $this->actingAs($user)
        ->get(route('terms.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/terms-update')
            ->where('firstAcceptance', false)
            ->where('asksAge', false));
});

it('laisse passer un compte à jour, et le renvoie de l’interstitiel vers son accueil', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    $this->actingAs($user)->get(route('terms.show'))->assertRedirect(Config::string('fortify.home'));
});

it('accepter écrit une nouvelle ligne et met à jour les projections', function () {
    $user = User::factory()->outdatedTerms('ancienne-1')->create();
    UserConsent::factory()->for($user)->terms()->state(['version' => 'ancienne-1'])->create();
    Config::set('legal.terms_version', 'definitive-1');

    // L'URL demandée est retenue, puis rendue après l'acceptation.
    $this->actingAs($user)->get(route('linked_accounts.edit'))->assertRedirect(route('terms.show'));

    $this->actingAs($user)
        ->post(route('terms.accept'), ['terms' => '1'])
        ->assertRedirect(route('linked_accounts.edit'));

    $user->refresh();
    $rows = UserConsent::query()->where('user_id', $user->id)->where('kind', ConsentKind::Terms->value)->get();

    expect($rows->pluck('version')->sort()->values()->all())->toBe(['ancienne-1', 'definitive-1'])
        ->and($user->terms_version)->toBe('definitive-1')
        ->and($user->terms_accepted_at?->equalTo($rows->firstWhere('version', 'definitive-1')?->accepted_at))->toBeTrue()
        // L'âge déjà déclaré n'est pas redemandé.
        ->and(UserConsent::query()->where('user_id', $user->id)->where('kind', ConsentKind::Age->value)->count())->toBe(0);

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
});

it('refuse une acceptation sans la case', function () {
    $user = User::factory()->outdatedTerms()->create();

    $this->actingAs($user)
        ->post(route('terms.accept'), [])
        ->assertSessionHasErrors(['terms' => __('account.consent.errors.terms_required')]);

    expect($user->fresh()?->terms_version)->toBe('ancienne-1')
        ->and(UserConsent::query()->count())->toBe(0);
});

it("demande aussi l'âge à un compte qui ne l'a jamais déclaré (premier administrateur)", function () {
    $admin = User::factory()->admin()->withoutConsent()->create();

    $this->actingAs($admin)
        ->get(route('terms.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('firstAcceptance', true)
            ->where('asksAge', true));

    $this->actingAs($admin)
        ->post(route('terms.accept'), ['terms' => '1'])
        ->assertSessionHasErrors('age');

    $this->actingAs($admin)
        ->post(route('terms.accept'), ['terms' => '1', 'age' => '1'])
        ->assertRedirect();

    $admin->refresh();

    expect($admin->terms_version)->toBe(Config::string('legal.terms_version'))
        ->and($admin->age_confirmed_at)->not->toBeNull()
        ->and(UserConsent::query()->where('user_id', $admin->id)->count())->toBe(2);
});

it('ne bloque jamais le jeu ni le back-office', function () {
    $player = User::factory()->outdatedTerms()->create();

    $this->actingAs($player)->get(route('home'))->assertOk();
    $this->actingAs($player)->get(route('room.create'))->assertOk();
    $this->actingAs($player)->get(route('solo.create'))->assertOk();

    $curator = User::factory()->curator()->withoutConsent()->create();

    $this->actingAs($curator)->get(route('admin.dashboard'))->assertOk();

    // L'enrôlement du second facteur, où `admin.2fa` envoie un compte
    // privilégié, reste ouvert : sans lui, des CGU non acceptées fermeraient le
    // back-office par ce détour.
    $this->actingAs($curator)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk();

    // Un joueur, lui, y est renvoyé vers l'interstitiel.
    $this->actingAs($player)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertRedirect(route('terms.show'));
});

it("laisse la déconnexion, l'export et la suppression ouverts", function () {
    // Aucune route du jeu, du back-office, de l'interstitiel, de la
    // déconnexion, des pages légales ni d'OAuth ne porte `terms.current` ;
    // l'export et la suppression du compte (§ 13.5, § 13.6) non plus.
    $guarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('terms.current', $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route): string => (string) $route->getName())
        ->values();

    expect($guarded)->toContain('profile.edit', 'security.edit', 'linked_accounts.edit', 'avatar.edit');

    $forbidden = $guarded->filter(fn (string $name): bool => preg_match(
        '/^(admin\.|room\.|solo\.|round\.|game\.|clock\.|frame\.|terms\.|logout$|legal\.|oauth\.|verification\.|password\.|export|account\.export|account\.delete|profile\.destroy|data_export)/',
        $name,
    ) === 1);

    expect($forbidden->all())->toBe([]);

    $user = User::factory()->outdatedTerms()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('redemande les CGU à un compte OAuth né sous la version provisoire', function () {
    $user = User::factory()->consented('provisoire-1')->create();
    Config::set('legal.terms_version', 'definitive-1');

    expect(EnsureTermsAccepted::isCurrent($user))->toBeFalse();

    $this->actingAs($user)->get(route('avatar.edit'))->assertRedirect(route('terms.show'));
});

it('une seconde acceptation concurrente, partie du même état périmé, garde la première preuve sans erreur', function () {
    $user = User::factory()->outdatedTerms('ancienne-1')->create();
    Config::set('legal.terms_version', 'definitive-1');

    // Deux requêtes chargent le même compte périmé : chacune passe le
    // contrôle `isCurrent()` du contrôleur avant que l'autre n'écrive.
    $first = User::query()->findOrFail($user->id);
    $second = User::query()->findOrFail($user->id);

    expect(EnsureTermsAccepted::isCurrent($first))->toBeFalse()
        ->and(EnsureTermsAccepted::isCurrent($second))->toBeFalse();

    Date::setTestNow(CarbonImmutable::parse('2026-10-07 10:00:00'));
    app(RecordConsents::class)->handle($first, [ConsentKind::Terms]);

    Date::setTestNow(CarbonImmutable::parse('2026-10-07 10:00:05'));
    app(RecordConsents::class)->handle($second, [ConsentKind::Terms]);

    $rows = UserConsent::query()
        ->where('user_id', $user->id)
        ->where('kind', ConsentKind::Terms->value)
        ->where('version', 'definitive-1')
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->accepted_at->toDateTimeString())->toBe('2026-10-07 10:00:00')
        ->and($user->fresh()?->terms_accepted_at?->toDateTimeString())->toBe('2026-10-07 10:00:00')
        ->and($user->fresh()?->terms_version)->toBe('definitive-1');

    Date::setTestNow();
});
