<?php

use App\Actions\Admin\UnhideAvatar;
use App\Avatars\UploadedAvatars;
use App\Enums\AdminActionType;
use App\Enums\AvatarKind;
use App\Models\AdminAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Écran « Avatars » — ligne 45, spec 20 § 12.5 ; règle : spec 40 § 11.7
| (D49 du 01/10, lot L40-8)
|--------------------------------------------------------------------------
|
| Administrateur seul. Lever rouvre l'image et le téléversement et remet la
| fenêtre de signalements à zéro ; retirer supprime le fichier, bloque le
| téléversement et exige un motif. Chaque geste a sa ligne de journal.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake(UploadedAvatars::DISK);
});

it('liste les avatars téléversés et filtre les masqués et les signalés, sans adresse e-mail', function () {
    $admin = User::factory()->admin()->create();
    $visible = User::factory()->withUploadedAvatar()->create();
    $hidden = User::factory()->uploadedAvatarHidden()->create();
    $reported = User::factory()->withUploadedAvatar()->create();
    Report::factory()->againstUploadedAvatar($reported)->create();
    User::factory()->withPresetAvatar()->create();

    $this->actingAs($admin)
        ->get(route('admin.avatars.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/avatars/index')
            ->has('avatars.data', 3)
            ->missing('avatars.data.0.email')
            ->where('counts.uploaded', 3)
            ->where('counts.hidden', 1));

    $this->actingAs($admin)
        ->get(route('admin.avatars.index', ['filter' => 'hidden']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('avatars.data', 1)
            ->where('avatars.data.0.id', $hidden->id)
            ->where('avatars.data.0.state', 'hidden'));

    $this->actingAs($admin)
        ->get(route('admin.avatars.index', ['filter' => 'reported']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('avatars.data', 1)
            ->where('avatars.data.0.id', $reported->id)
            ->where('avatars.data.0.reports', 1));

    expect($visible->id)->not->toBe($reported->id);
});

it('sert à l’administrateur une image masquée, sans cache', function () {
    $admin = User::factory()->admin()->create();
    $hidden = User::factory()->uploadedAvatarHidden()->create();

    $response = $this->actingAs($admin)->get(route('admin.avatars.image', $hidden));

    $response->assertOk()->assertHeader('Content-Type', 'image/webp');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('lève un masquage, rouvre l’image et consigne avatar.unhidden', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->uploadedAvatarHidden()->create();

    $this->actingAs($admin)
        ->from(route('admin.avatars.index'))
        ->post(route('admin.avatars.unhide', $user), ['reason' => 'Faux positif'])
        ->assertRedirect(route('admin.avatars.index'));

    $user->refresh();
    $line = AdminAction::query()->sole();

    expect($user->avatar_upload_hidden_at)->toBeNull()
        ->and($user->avatarRef()->kind)->toBe(AvatarKind::Upload)
        ->and($line->action)->toBe(AdminActionType::AvatarUnhidden)
        ->and($line->actor_id)->toBe($admin->id)
        ->and($line->reason)->toBe('Faux positif');
});

it('refuse de lever un avatar qui n’est ni masqué ni retiré', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->withUploadedAvatar()->create();

    expect(fn () => app(UnhideAvatar::class)->handle($admin, $user, null))
        ->toThrow(ValidationException::class);

    expect(AdminAction::query()->count())->toBe(0);
});

it('retire une image : fichier supprimé, téléversement bloqué, motif obligatoire', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->withUploadedAvatar()->create(['avatar_preset' => 'preset-04']);
    $path = (string) $user->avatar_upload_path;

    $this->actingAs($admin)
        ->post(route('admin.avatars.remove', $user), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($admin)
        ->post(route('admin.avatars.remove', $user), ['reason' => 'Image violente'])
        ->assertSessionHasNoErrors();

    $user->refresh();
    $line = AdminAction::query()->sole();

    expect($user->avatar_upload_path)->toBeNull()
        ->and($user->avatar_upload_hidden_at)->not->toBeNull()
        ->and($user->isAvatarUploadBlocked())->toBeTrue()
        ->and($user->avatar_kind)->toBe(AvatarKind::Preset)
        ->and($line->action)->toBe(AdminActionType::AvatarRemoved)
        ->and($line->reason)->toBe('Image violente');
    Storage::disk(UploadedAvatars::DISK)->assertMissing($path);
});

it('refuse l’écran et les gestes au curateur', function () {
    $curator = User::factory()->curator()->create();
    $user = User::factory()->uploadedAvatarHidden()->create();

    $this->actingAs($curator)->get(route('admin.avatars.index'))->assertForbidden();
    $this->actingAs($curator)->post(route('admin.avatars.unhide', $user))->assertForbidden();

    expect($user->refresh()->avatar_upload_hidden_at)->not->toBeNull();
});
