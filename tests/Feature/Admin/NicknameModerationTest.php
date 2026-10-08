<?php

use App\Actions\Admin\BanNickname;
use App\Actions\Admin\UnmaskNickname;
use App\Enums\AdminActionType;
use App\Models\AdminAction;
use App\Models\Player;
use App\Models\Report;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Identity\NicknameBlocklist;
use App\Support\Moderation\NicknameModerationState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Modération des pseudos — ligne 35, spec 20 § 11.5 ; règle : spec 40 § 13.3
| (D66 du 07/10, lots L40-12 et L20-31)
|--------------------------------------------------------------------------
|
| Administrateur seul. Lever vide le masquage et remet la fenêtre de
| comptage à maintenant ; bannir masque pour toujours et exige un motif.
| Chaque geste a sa ligne de journal ; la forme du pseudo banni n'y est
| jamais écrite, l'écran la donne à recopier dans la liste noire versionnée.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
});

/** Un siège masqué par deux signalements, avec la ligne `nickname.masked` du système. */
function nicknameModerationMasked(string $nickname = 'Pseudo Vilain'): Player
{
    $seat = Player::factory()->withNickname($nickname)->masked()->create();
    Report::factory()->againstNickname($seat)->create();
    Report::factory()->againstNickname($seat)->create();

    DB::transaction(fn () => app(AdminJournal::class)->recordAutomatic(AdminActionType::NicknameMasked, $seat->id, 2));

    return $seat->refresh();
}

it('seul un administrateur lève ou bannit un pseudo, et chaque geste écrit sa ligne', function () {
    $admin = User::factory()->admin()->create();
    $curator = User::factory()->curator()->create();
    $unmasked = nicknameModerationMasked();
    $banned = Player::factory()->create();

    $this->actingAs($curator)->post(route('admin.moderation.nickname.unmask', ['player' => $unmasked->id]))->assertForbidden();
    $this->actingAs($curator)->post(route('admin.moderation.nickname.ban', ['player' => $banned->id]), ['reason' => 'Injure'])->assertForbidden();
    $this->actingAs($curator)->get(route('admin.moderation.index'))->assertForbidden();

    expect($unmasked->refresh()->nickname_masked_at)->not->toBeNull()
        ->and($banned->refresh()->nickname_masked_at)->toBeNull();

    $this->actingAs($admin)
        ->post(route('admin.moderation.nickname.unmask', ['player' => $unmasked->id]), ['reason' => 'Faux positif'])
        ->assertRedirect(route('admin.moderation.index'))
        ->assertSessionHasNoErrors();

    $unmasked->refresh();
    $unmask = AdminAction::query()->where('action', AdminActionType::NicknameUnmasked->value)->sole();

    expect($unmasked->nickname_masked_at)->toBeNull()
        ->and($unmasked->nickname_reports_from)->not->toBeNull()
        ->and($unmask->subject_id)->toBe($unmasked->id)
        ->and($unmask->actor_id)->toBe($admin->id)
        ->and($unmask->reason)->toBe('Faux positif');

    // Bannir exige un motif.
    $this->actingAs($admin)
        ->post(route('admin.moderation.nickname.ban', ['player' => $banned->id]), ['reason' => '  '])
        ->assertSessionHasErrors('reason');

    expect($banned->refresh()->nickname_masked_at)->toBeNull();

    $this->actingAs($admin)
        ->post(route('admin.moderation.nickname.ban', ['player' => $banned->id]), ['reason' => 'Injure'])
        ->assertRedirect(route('admin.moderation.index'))
        ->assertSessionHasNoErrors();

    $ban = AdminAction::query()->where('action', AdminActionType::NicknameBanned->value)->sole();

    expect($banned->refresh()->nickname_masked_at)->not->toBeNull()
        ->and($ban->subject_id)->toBe($banned->id)
        ->and($ban->actor_id)->toBe($admin->id)
        ->and($ban->reason)->toBe('Injure')
        ->and(NicknameModerationState::isBanned($banned->id))->toBeTrue()
        ->and(NicknameModerationState::isBanned($unmasked->id))->toBeFalse();
});

it('un pseudo banni ne se lève plus', function () {
    $admin = User::factory()->admin()->create();
    $seat = nicknameModerationMasked();

    app(BanNickname::class)->handle($admin, $seat, 'Injure');

    $this->actingAs($admin)
        ->post(route('admin.moderation.nickname.unmask', ['player' => $seat->id]))
        ->assertSessionHasErrors(['reason' => __('admin.moderation.errors.banned')]);

    expect($seat->refresh()->nickname_masked_at)->not->toBeNull()
        ->and(fn () => app(BanNickname::class)->handle($admin, $seat, 'Encore'))->toThrow(ValidationException::class)
        ->and(fn () => app(UnmaskNickname::class)->handle($admin, $seat, null))->toThrow(ValidationException::class)
        ->and(AdminAction::query()->where('action', AdminActionType::NicknameUnmasked->value)->count())->toBe(0);
});

it('refuse de lever un pseudo qui n’est pas masqué', function () {
    $admin = User::factory()->admin()->create();
    $seat = Player::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.moderation.nickname.unmask', ['player' => $seat->id]))
        ->assertSessionHasErrors(['reason' => __('admin.moderation.errors.not_masked')]);

    expect(AdminAction::query()->count())->toBe(0);
});

it('le bannissement n’écrit jamais la forme du pseudo au journal', function () {
    $admin = User::factory()->admin()->create();
    $seat = nicknameModerationMasked('Zorglub Vilain');

    app(BanNickname::class)->handle($admin, $seat, 'Injure');

    $line = AdminAction::query()->where('action', AdminActionType::NicknameBanned->value)->sole();
    $row = (array) DB::table('admin_action')->where('id', $line->id)->first();
    $serialized = json_encode($row, JSON_THROW_ON_ERROR);

    expect($line->details)->toBeNull()
        ->and($serialized)->not->toContain('Zorglub')
        ->and($serialized)->not->toContain('zorglub');
});

it('liste les sièges masqués et bannis, leurs signaleurs et les formes à recopier dans la liste noire', function () {
    $admin = User::factory()->admin()->create();
    $masked = nicknameModerationMasked('Masque Simple');
    $banned = nicknameModerationMasked('Zorglub Vilain');
    $erased = nicknameModerationMasked('Bientot Efface');
    Player::factory()->create();

    app(BanNickname::class)->handle($admin, $banned, 'Injure');
    app(BanNickname::class)->handle($admin, $erased, 'Injure');
    $erased->forceFill(['nickname' => null, 'nickname_normalized' => null])->save();

    $this->actingAs($admin)
        ->get(route('admin.moderation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/moderation/index')
            ->has('seats.data', 3)
            ->where('counts.masked', 3)
            ->has('blocklist', 1)
            ->where('blocklist.0.id', $banned->id)
            ->where('blocklist.0.form', $banned->nickname_normalized)
            ->has('seats.data.0.reporters', 2)
            ->missing('seats.data.0.email'));

    $rows = collect($this->actingAs($admin)->get(route('admin.moderation.index'))->viewData('page')['props']['seats']['data'])->keyBy('id');

    expect($rows[$masked->id]['banned'])->toBeFalse()
        ->and($rows[$masked->id]['reports_count'])->toBe(2)
        ->and($rows[$masked->id]['current_reports'])->toBe(2)
        ->and($rows[$banned->id]['banned'])->toBeTrue()
        ->and($rows[$erased->id]['nickname'])->toBeNull();
});

it('la liste noire lit les pseudos bannis recopiés dans banned.txt', function () {
    expect(NicknameBlocklist::files())->toContain(
        resource_path(NicknameBlocklist::DIRECTORY.'/'.NicknameBlocklist::BANNED_FILE.'.txt'),
    );
});
