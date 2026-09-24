<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test("n'expose aucune suppression de compte tant que l'anonymisation n'est pas livrée", function () {
    // Spec 40 § 8.3 : la suppression dure contredit 10 § 5.5 (suppression =
    // anonymisation) et ferait tomber en un clic l'administrateur unique du
    // jalon 1. Route, action, requête et composant sont retirés ; le jalon 2
    // les recrée sur l'anonymisation.
    expect(Route::has('profile.destroy'))->toBeFalse()
        ->and(method_exists(ProfileController::class, 'destroy'))->toBeFalse()
        ->and(app_path('Http/Requests/Settings/ProfileDeleteRequest.php'))->not->toBeFile()
        ->and(resource_path('js/components/delete-user.tsx'))->not->toBeFile();

    $user = User::factory()->create();

    // L'adresse du profil n'accepte plus DELETE, mot de passe correct compris.
    $this->actingAs($user)
        ->delete(route('profile.update'), [
            'password' => 'password',
        ])
        ->assertMethodNotAllowed();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->not->toBeNull();

    // La page de profil se rend toujours, sans `delete-user.tsx` à importer.
    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/profile'));
});
