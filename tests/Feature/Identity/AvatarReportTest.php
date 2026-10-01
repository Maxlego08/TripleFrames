<?php

use App\Actions\Room\ReportSeatAvatar;
use App\Avatars\UploadedAvatars;
use App\Enums\AdminActionType;
use App\Enums\AvatarKind;
use App\Enums\ReportTarget;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\AdminAction;
use App\Models\Player;
use App\Models\Report;
use App\Models\Room;
use App\Models\User;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Signalement d'un avatar téléversé — spec 40 § 11.6, D49 du 01/10 (L40-8)
|--------------------------------------------------------------------------
|
| Tout siège actif signale l'image d'un AUTRE siège du même salon ; deux
| sièges distincts depuis le début de la fenêtre masquent l'image et
| consignent `avatar.hidden`, geste automatique. Un prédéfini n'est jamais
| signalable, ni son propre siège.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    Storage::fake(UploadedAvatars::DISK);
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
});

/**
 * Un siège du salon qui affiche l'image téléversée de ce compte.
 */
function avatarReportUploadSeat(Room $room, User $user): Player
{
    [$seat] = HostGestures::seat($room, attributes: [
        'user_id' => $user->id,
        'avatar_kind' => AvatarKind::Upload,
        'avatar_preset' => 'preset-03',
    ]);

    return $seat;
}

/**
 * Le signalement par la route, au nom du siège signaleur.
 *
 * @return TestResponse<Response>
 */
function avatarReportSend(TestCase $test, Room $room, PlayerToken $token, Player $reporter, Player $target): TestResponse
{
    LobbyWrites::actAs($test, $token);

    return LobbyWrites::send(
        $test,
        'POST',
        route('room.players.report_avatar', ['room' => $room, 'target' => $target->public_id]),
        $room,
        [],
        $reporter,
    );
}

it('masque l’image au second siège distinct et consigne avatar.hidden sans acteur', function () {
    [$room, $host, $hostToken] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room);
    $user = User::factory()->withUploadedAvatar()->create();
    $target = avatarReportUploadSeat($room, $user);

    avatarReportSend($this, $room, $hostToken, $host, $target)->assertSessionHasNoErrors();

    expect($user->refresh()->avatar_upload_hidden_at)->toBeNull();

    avatarReportSend($this, $room, $guestToken, $guest, $target)->assertSessionHasNoErrors();

    $user->refresh();
    $line = AdminAction::query()->sole();

    expect($user->avatar_upload_hidden_at)->not->toBeNull()
        ->and($user->avatar_upload_path)->not->toBeNull()
        ->and($line->action)->toBe(AdminActionType::AvatarHidden)
        ->and($line->subject_id)->toBe($user->id)
        ->and($line->actor_id)->toBeNull()
        ->and($line->actor_name)->toBe(AdminAction::SYSTEM_ACTOR)
        ->and($line->reports_count)->toBe(2)
        ->and(Report::query()->where('target_type', ReportTarget::UploadedAvatar->value)->count())->toBe(2)
        ->and($target->refresh()->avatarRef()->kind)->toBe(AvatarKind::Preset);
    Storage::disk(UploadedAvatars::DISK)->assertExists((string) $user->avatar_upload_path);
});

it('ne compte qu’une fois le second clic du même siège', function () {
    [$room, $host, $hostToken] = HostGestures::room();
    $user = User::factory()->withUploadedAvatar()->create();
    $target = avatarReportUploadSeat($room, $user);

    avatarReportSend($this, $room, $hostToken, $host, $target)->assertSessionHasNoErrors();
    avatarReportSend($this, $room, $hostToken, $host, $target)->assertSessionHasNoErrors();

    expect(Report::query()->count())->toBe(1)
        ->and($user->refresh()->avatar_upload_hidden_at)->toBeNull()
        ->and(AdminAction::query()->count())->toBe(0);
});

it('refuse de signaler un avatar prédéfini ou son propre siège', function () {
    [$room, $host, $hostToken] = HostGestures::room();
    [$guest] = HostGestures::seat($room, attributes: ['avatar_kind' => AvatarKind::Preset, 'avatar_preset' => 'preset-01']);

    avatarReportSend($this, $room, $hostToken, $host, $guest)
        ->assertSessionHasErrors(['avatar' => __('common.avatar.report.not_reportable')]);

    $user = User::factory()->withUploadedAvatar()->create();
    $host->forceFill(['user_id' => $user->id, 'avatar_kind' => AvatarKind::Upload])->save();

    avatarReportSend($this, $room, $hostToken, $host, $host)
        ->assertSessionHasErrors('avatar');

    expect(Report::query()->count())->toBe(0);
});

it('ne compte pas les signalements antérieurs à une levée', function () {
    [$room, $host, $hostToken] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room);
    $user = User::factory()->withUploadedAvatar()->create();
    $target = avatarReportUploadSeat($room, $user);

    $this->travelTo(CarbonImmutable::now());
    avatarReportSend($this, $room, $hostToken, $host, $target)->assertSessionHasNoErrors();

    // Une levée remet la fenêtre à maintenant : le signalement passé ne compte plus.
    $this->travel(1)->minutes();
    $user->refresh()->forceFill(['avatar_upload_reports_from' => CarbonImmutable::now()])->save();
    $this->travel(1)->minutes();

    avatarReportSend($this, $room, $guestToken, $guest, $target)->assertSessionHasNoErrors();

    expect($user->refresh()->avatar_upload_hidden_at)->toBeNull()
        ->and(ReportSeatAvatar::reportersSince($user))->toBe(1);
});

it('répond 404 à une cible inconnue du salon', function () {
    [$room, $host, $hostToken] = HostGestures::room();
    [$other] = HostGestures::seat(Room::factory()->create());

    avatarReportSend($this, $room, $hostToken, $host, $other)->assertNotFound();
});
