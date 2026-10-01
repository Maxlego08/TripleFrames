<?php

use App\Avatars\AvatarImage;
use App\Avatars\SeatAvatar;
use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Identity\PlayerIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Avatar téléversé d'un compte — spec 40 § 11, D49 du 01/10 (lot L40-8)
|--------------------------------------------------------------------------
|
| Téléversement normalisé par le serveur, choix explicite de la nature,
| suppression, service par la route `avatar.show` (qui refuse une image
| masquée), et le siège d'un compte : « Mon avatar », rattachement au compte,
| résolution vivante jusque dans l'affichage gelé d'une partie.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    Storage::fake(UploadedAvatars::DISK);
    config()->set('game.room.joins_per_minute', 1000);
});

/** Une image de test encodée par Imagick, jamais une image réelle. */
function avatarUploadImage(int $width = 600, int $height = 400, string $format = 'png'): string
{
    // Les limites qu'`AvatarImage` pose valent pour tout le processus : la
    // fixture d'une source trop grande doit pouvoir naître après elles.
    foreach (['WIDTH', 'HEIGHT'] as $type) {
        $constant = Imagick::class.'::RESOURCETYPE_'.$type;

        if (defined($constant)) {
            Imagick::setResourceLimit((int) constant($constant), 1 << 20);
        }
    }

    $image = new Imagick;
    $image->newImage($width, $height, new ImagickPixel('rgb(200, 60, 40)'));
    $image->setImageFormat($format);
    $bytes = $image->getImageBlob();
    $image->clear();

    return $bytes;
}

function avatarUploadFile(string $bytes, string $name = 'avatar.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('normalise une image téléversée en WebP carré de 256 pixels et la choisit', function () {
    $user = User::factory()->withPresetAvatar('preset-05')->create();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['avatar' => avatarUploadFile(avatarUploadImage())])
        ->assertRedirect(route('avatar.edit'))
        ->assertSessionHasNoErrors();

    $user->refresh();
    $path = (string) $user->avatar_upload_path;
    $bytes = (string) Storage::disk(UploadedAvatars::DISK)->get($path);
    $size = getimagesizefromstring($bytes);

    expect($path)->toMatch('#^upload/[0-9a-f]{32}\.webp$#')
        ->and($user->avatar_kind)->toBe(AvatarKind::Upload)
        ->and($user->avatar_preset)->toBe('preset-05')
        ->and($user->avatar_upload_reports_from)->not->toBeNull()
        ->and($size)->not->toBeFalse()
        ->and($size[0] ?? null)->toBe(AvatarImage::OUTPUT_SIZE_PX)
        ->and($size[1] ?? null)->toBe(AvatarImage::OUTPUT_SIZE_PX)
        ->and($size['mime'] ?? null)->toBe('image/webp')
        ->and(strlen($bytes))->toBeLessThanOrEqual(AvatarImage::MAX_OUTPUT_BYTES)
        ->and($user->avatarRef()->url)->toBe(UploadedAvatars::url($path))
        ->and($user->toArray())->not->toHaveKey('avatar_upload_path');
});

it('remplace l’image précédente et supprime son fichier', function () {
    $user = User::factory()->withUploadedAvatar()->create();
    $old = (string) $user->avatar_upload_path;

    $this->actingAs($user)
        ->post(route('avatar.store'), ['avatar' => avatarUploadFile(avatarUploadImage())])
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->avatar_upload_path)->not->toBe($old);
    Storage::disk(UploadedAvatars::DISK)->assertMissing($old);
    Storage::disk(UploadedAvatars::DISK)->assertExists((string) $user->avatar_upload_path);
});

it('refuse un fichier qui n’est pas une image, sous le champ avatar et en erreur traduite', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['avatar' => avatarUploadFile('pas une image', 'avatar.png')])
        ->assertSessionHasErrors(['avatar' => __('account.avatar.errors.format')]);

    expect($user->refresh()->avatar_upload_path)->toBeNull();
    expect(Storage::disk(UploadedAvatars::DISK)->allFiles())->toBe([]);
});

it('refuse une image trop grande avant tout décodage', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['avatar' => avatarUploadFile(avatarUploadImage(AvatarImage::MAX_SOURCE_PX + 1, 8))])
        ->assertSessionHasErrors(['avatar' => __('account.avatar.errors.dimensions')]);
});

it('refuse un fichier au-delà du plafond de plate-forme par une erreur de validation', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('avatar.png', PlatformLimits::avatarUploadMaxKilobytes() + 1, 'image/png');

    $this->actingAs($user)
        ->post(route('avatar.store'), ['avatar' => $file])
        ->assertSessionHasErrors('avatar');
});

it('ferme le téléversement tant qu’une image est masquée, même après sa suppression', function () {
    $user = User::factory()->uploadedAvatarHidden()->create();

    $this->actingAs($user)->delete(route('avatar.destroy'))->assertRedirect(route('avatar.edit'));

    $user->refresh();

    expect($user->avatar_upload_path)->toBeNull()
        ->and($user->avatar_upload_hidden_at)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['avatar' => avatarUploadFile(avatarUploadImage())])
        ->assertSessionHasErrors(['avatar' => __('account.avatar.errors.blocked')]);

    expect(Storage::disk(UploadedAvatars::DISK)->allFiles())->toBe([]);
});

it('supprime l’image du titulaire et redescend au prédéfini du compte', function () {
    $user = User::factory()->withUploadedAvatar()->create(['avatar_preset' => 'preset-07']);
    $path = (string) $user->avatar_upload_path;

    $this->actingAs($user)->delete(route('avatar.destroy'))->assertRedirect(route('avatar.edit'));

    $user->refresh();

    expect($user->avatar_upload_path)->toBeNull()
        ->and($user->avatar_kind)->toBe(AvatarKind::Preset)
        ->and($user->avatarRef()->kind)->toBe(AvatarKind::Preset);
    Storage::disk(UploadedAvatars::DISK)->assertMissing($path);
});

it('choisit explicitement entre un prédéfini et l’image, l’image non choisie restant stockée', function () {
    $user = User::factory()->withUploadedAvatar()->create();

    $this->actingAs($user)->patch(route('avatar.update'), ['avatar' => 'preset-09'])->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->avatar_kind)->toBe(AvatarKind::Preset)
        ->and($user->avatar_preset)->toBe('preset-09')
        ->and($user->avatar_upload_path)->not->toBeNull()
        ->and($user->avatarRef()->kind)->toBe(AvatarKind::Preset);

    $this->actingAs($user)->patch(route('avatar.update'), ['avatar' => SeatAvatar::ACCOUNT])->assertSessionHasNoErrors();

    expect($user->refresh()->avatarRef()->kind)->toBe(AvatarKind::Upload);
});

it('refuse de choisir une image masquée', function () {
    $user = User::factory()->uploadedAvatarHidden()->create(['avatar_preset' => 'preset-02']);

    $this->actingAs($user)
        ->patch(route('avatar.update'), ['avatar' => SeatAvatar::ACCOUNT])
        ->assertSessionHasErrors(['avatar' => __('account.avatar.errors.unavailable')]);

    expect($user->avatarRef()->kind)->toBe(AvatarKind::Preset);
});

it('rend l’écran Avatar du compte avec l’état de modération', function () {
    $user = User::factory()->uploadedAvatarHidden()->create();

    $this->actingAs($user)
        ->get(route('avatar.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/avatar')
            ->where('upload.url', null)
            ->where('upload.stored', true)
            ->where('upload.blocked', true)
            ->where('upload.maxKilobytes', PlatformLimits::avatarUploadMaxKilobytes())
            ->has('options', PlatformLimits::avatarPresets()));
});

it('renvoie un invité à la connexion', function () {
    $this->get(route('avatar.edit'))->assertRedirect(route('login'));
});

it('sert une image visible, cacheable, sans session ni cookie', function () {
    $user = User::factory()->withUploadedAvatar()->create();

    $response = $this->get(UploadedAvatars::url((string) $user->avatar_upload_path));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex');

    expect($response->headers->get('Cache-Control'))->toContain('immutable')
        ->and($response->headers->getCookies())->toBe([]);
});

it('refuse de servir une image masquée, retirée ou inconnue', function () {
    $hidden = User::factory()->uploadedAvatarHidden()->create();

    $this->get(UploadedAvatars::url((string) $hidden->avatar_upload_path))->assertNotFound();
    $this->get('/a/'.str_repeat('a', 32).'.webp')->assertNotFound();
    $this->get('/a/../../.env')->assertNotFound();
});

it('propose « Mon avatar » au siège d’un compte et rattache le siège au compte', function () {
    $user = User::factory()->withUploadedAvatar()->create();
    $room = Room::factory()->create();

    $this->actingAs($user)
        ->get(route('room.entry', $room))
        ->assertInertia(fn (Assert $page) => $page
            ->where('avatars.account.url', UploadedAvatars::url((string) $user->avatar_upload_path))
            ->where('avatars.suggested', SeatAvatar::ACCOUNT));

    $joined = $this->actingAs($user)
        ->post(route('room.join', $room), SeatEntry::form('Zoé', SeatAvatar::ACCOUNT))
        ->assertSessionHasNoErrors();

    $seat = Player::query()->sole();
    $identity = PlayerIdentity::fromSeat($seat)->toArray();

    expect($seat->user_id)->toBe($user->id)
        ->and($seat->avatar_kind)->toBe(AvatarKind::Upload)
        ->and($seat->avatar_preset)->toBe($user->avatar_preset)
        ->and($identity['avatar']['kind'])->toBe('upload')
        ->and($identity['avatar']['url'])->toBe(UploadedAvatars::url((string) $user->avatar_upload_path))
        ->and($identity['avatar']['initials'])->toBe('Z')
        // Le jeton porte le prédéfini de repli, jamais l'image du compte (I4.5).
        ->and(SeatEntry::claims($joined)['avatar'])->toBe($user->avatar_preset);
});

it('refuse « Mon avatar » à un invité et à un compte dont l’image est masquée', function () {
    $room = Room::factory()->create();

    $this->post(route('room.join', $room), SeatEntry::form('Zoé', SeatAvatar::ACCOUNT))
        ->assertSessionHasErrors('avatar');

    $hidden = User::factory()->uploadedAvatarHidden()->create();

    $this->actingAs($hidden)
        ->post(route('room.join', $room), SeatEntry::form('Léa', SeatAvatar::ACCOUNT))
        ->assertSessionHasErrors('avatar');

    expect(Player::query()->count())->toBe(0);
});

it('fait redescendre au prédéfini de repli un siège dont l’image est masquée, affichage gelé compris', function () {
    $user = User::factory()->withUploadedAvatar()->create(['avatar_preset' => 'preset-11']);
    $seat = Player::factory()->create([
        'user_id' => $user->id,
        'nickname' => 'Zoé',
        'avatar_kind' => AvatarKind::Upload,
        'avatar_preset' => 'preset-11',
    ]);
    $participation = GamePlayer::factory()->frozenFrom($seat)->create();
    $participation->load('player:'.implode(',', PlayerIdentity::FROZEN_SEAT_COLUMNS));

    expect(PlayerIdentity::fromGamePlayer($participation)->toArray()['avatar']['kind'])->toBe('upload');

    $user->forceFill(['avatar_upload_hidden_at' => CarbonImmutable::now()])->save();

    $seatAvatar = PlayerIdentity::fromSeat($seat->refresh())->toArray()['avatar'];
    $frozenAvatar = PlayerIdentity::fromGamePlayer($participation)->toArray()['avatar'];

    expect($seatAvatar['kind'])->toBe('preset')
        ->and($seatAvatar['url'])->toEndWith('preset-11.webp')
        ->and($frozenAvatar['kind'])->toBe('preset')
        ->and($frozenAvatar['url'])->toEndWith('preset-11.webp');
});
