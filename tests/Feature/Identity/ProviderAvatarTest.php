<?php

use App\Actions\Admin\RemoveAvatar;
use App\Actions\Room\ReportSeatAvatar;
use App\Avatars\AccountImage;
use App\Avatars\SeatAvatar;
use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Enums\OAuthProvider;
use App\Enums\ReportTarget;
use App\Jobs\Account\CopyProviderAvatar;
use App\Models\LinkedAccount;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Copie locale de la photo du fournisseur — spec 40 § 12.6, D51 du 01/10
|--------------------------------------------------------------------------
|
| Téléchargement borné (liste blanche, HTTPS, aucune redirection), même
| normalisation que l'image téléversée, jamais par-dessus un avatar choisi,
| servie par `avatar.show`, signalable et retirable comme elle.
|
*/

beforeEach(function (): void {
    Storage::fake(UploadedAvatars::DISK);
    Http::preventStrayRequests();
});

function providerAvatarPng(): string
{
    $image = new Imagick;
    $image->newImage(300, 300, new ImagickPixel('rgb(30, 160, 90)'));
    $image->setImageFormat('png');
    $bytes = $image->getImageBlob();
    $image->clear();

    return $bytes;
}

function providerAvatarLink(User $user, string $url = 'https://lh3.googleusercontent.com/a/photo'): LinkedAccount
{
    return LinkedAccount::factory()->for($user)->create([
        'provider' => OAuthProvider::Google,
        'provider_avatar_url' => $url,
    ]);
}

it('copie la photo et en fait l’avatar d’un compte qui n’en avait aucun', function () {
    Http::fake(['lh3.googleusercontent.com/*' => Http::response(providerAvatarPng(), 200, ['Content-Type' => 'image/png'])]);
    $user = User::factory()->create(['avatar_kind' => null, 'avatar_preset' => null]);
    $linked = providerAvatarLink($user);

    (new CopyProviderAvatar($linked->id))->handle();

    $user->refresh();

    expect($user->avatar_kind)->toBe(AvatarKind::Provider)
        ->and($user->avatar_provider_path)->toMatch('#^provider/[0-9a-f]{32}\.webp$#')
        ->and($user->avatar_provider_source)->toBe(OAuthProvider::Google)
        ->and($linked->refresh()->provider_avatar_url)->toBeNull()
        ->and($user->avatarRef()->url)->toBe(UploadedAvatars::url((string) $user->avatar_provider_path));

    $this->get((string) $user->avatarRef()->url)->assertOk()->assertHeader('Content-Type', 'image/webp');
});

it('ne remplace jamais un avatar déjà choisi', function () {
    Http::fake(['lh3.googleusercontent.com/*' => Http::response(providerAvatarPng())]);
    $user = User::factory()->withPresetAvatar('preset-08')->create();

    (new CopyProviderAvatar(providerAvatarLink($user)->id))->handle();

    $user->refresh();

    expect($user->avatar_provider_path)->not->toBeNull()
        ->and($user->avatar_kind)->toBe(AvatarKind::Preset)
        ->and($user->avatarRef()->kind)->toBe(AvatarKind::Preset);
});

it('refuse un hôte hors liste blanche, un schéma non chiffré et une redirection', function (string $url) {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.example/x.png'])]);
    $user = User::factory()->create();

    (new CopyProviderAvatar(providerAvatarLink($user, $url)->id))->handle();

    expect($user->refresh()->avatar_provider_path)->toBeNull();
    expect(Storage::disk(UploadedAvatars::DISK)->allFiles())->toBe([]);
})->with([
    'hôte inconnu' => 'https://evil.example/photo.png',
    'http' => 'http://lh3.googleusercontent.com/a/photo',
    'redirection' => 'https://lh3.googleusercontent.com/a/redirige',
]);

it('ne copie rien pour un compte dont la photo est masquée', function () {
    Http::fake(['lh3.googleusercontent.com/*' => Http::response(providerAvatarPng())]);
    $user = User::factory()->create(['avatar_provider_hidden_at' => now()]);

    (new CopyProviderAvatar(providerAvatarLink($user)->id))->handle();

    expect($user->refresh()->avatar_provider_path)->toBeNull();
    expect(Storage::disk(UploadedAvatars::DISK)->allFiles())->toBe([]);
});

it('propose la photo comme « Mon avatar » au siège, en nature provider', function () {
    $user = User::factory()->create(['avatar_preset' => 'preset-02']);
    $path = UploadedAvatars::newPath(AccountImage::Provider);
    Storage::disk(UploadedAvatars::DISK)->put($path, 'octets');
    $user->forceFill(['avatar_kind' => AvatarKind::Provider, 'avatar_provider_path' => $path, 'avatar_provider_source' => OAuthProvider::Google])->save();

    $avatar = SeatAvatar::resolve(SeatAvatar::ACCOUNT, $user, null, []);

    expect($avatar->kind)->toBe(AvatarKind::Provider)
        ->and($avatar->preset)->toBe('preset-02')
        ->and(SeatAvatar::accountOption($user))->toBe(['url' => UploadedAvatars::url($path)]);
});

it('compte les signalements de la photo sur sa propre cible et son propre masquage', function () {
    $user = User::factory()->create();
    $user->forceFill(['avatar_provider_path' => UploadedAvatars::newPath(AccountImage::Provider), 'avatar_provider_reports_from' => now()->subDay()])->save();
    Report::factory()->againstProviderAvatar($user)->count(2)->create();
    Report::factory()->againstUploadedAvatar($user)->create();

    expect(ReportSeatAvatar::reportersSince($user->refresh(), AccountImage::Provider))->toBe(2)
        ->and(ReportSeatAvatar::reportersSince($user, AccountImage::Upload))->toBe(1)
        ->and(AccountImage::Provider->reportTarget())->toBe(ReportTarget::ProviderAvatar);
});

it('retire la photo : fichier supprimé, provenance vidée, re-téléchargement bloqué', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['avatar_preset' => 'preset-05']);
    $path = UploadedAvatars::newPath(AccountImage::Provider);
    Storage::disk(UploadedAvatars::DISK)->put($path, 'octets');
    $user->forceFill(['avatar_kind' => AvatarKind::Provider, 'avatar_provider_path' => $path, 'avatar_provider_source' => OAuthProvider::Google])->save();

    app(RemoveAvatar::class)->handle($admin, $user, 'Photo choquante', AccountImage::Provider);

    $user->refresh();

    expect($user->avatar_provider_path)->toBeNull()
        ->and($user->avatar_provider_source)->toBeNull()
        ->and($user->avatar_provider_hidden_at)->not->toBeNull()
        ->and($user->avatar_kind)->toBe(AvatarKind::Preset);
    Storage::disk(UploadedAvatars::DISK)->assertMissing($path);
});
