<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| La date de dernière connexion — spec 10 § 5.1, écrite par L20-19
|--------------------------------------------------------------------------
|
| `users.last_login_at` pilote la dormance du jalon 2 et la « dernière
| connexion » de l'écran de gestion des accès (spec 20 § 2.8). Elle n'est
| écrite qu'à une connexion ABOUTIE, et jamais par `updated_at` : la colonne
| existe précisément parce que les deux ne disent pas la même chose.
|
*/

test('la connexion inscrit la date de dernière connexion sans toucher updated_at', function (): void {
    $past = CarbonImmutable::parse('2026-01-01 10:00:00');
    $user = User::factory()->create(['updated_at' => $past, 'last_login_at' => null]);

    $this->travelTo(CarbonImmutable::parse('2026-09-28 14:30:00'));

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);

    $fresh = $user->fresh();

    expect($fresh?->last_login_at?->toDateTimeString())->toBe('2026-09-28 14:30:00')
        ->and($fresh?->updated_at?->toDateTimeString())->toBe($past->toDateTimeString());
});

test('une connexion refusée n\'inscrit aucune date', function (): void {
    $user = User::factory()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();

    expect($user->fresh()?->last_login_at)->toBeNull();
});

test('un défi de double authentification réussi inscrit la date de dernière connexion', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    // Le chemin de tout compte privilégié : mot de passe, puis second
    // facteur. `recovery-code-1` est le code de secours de la fabrique.
    $user = User::factory()->withTwoFactor()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    expect($user->fresh()?->last_login_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-09-28 16:05:00'));

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1']);

    $this->assertAuthenticatedAs($user);

    expect($user->fresh()?->last_login_at?->toDateTimeString())->toBe('2026-09-28 16:05:00');
});

test('l\'étape qui demande le second facteur n\'inscrit aucune date', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $user = User::factory()->withTwoFactor()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();

    expect($user->fresh()?->last_login_at)->toBeNull();
});
