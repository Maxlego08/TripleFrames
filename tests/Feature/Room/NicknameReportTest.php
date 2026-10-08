<?php

use App\Actions\Admin\UnmaskNickname;
use App\Actions\Room\ReportSeatNickname;
use App\Avatars\AvatarRef;
use App\Enums\AdminActionType;
use App\Enums\PlayerConnectionState;
use App\Enums\ReportTarget;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\AdminAction;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Report;
use App\Models\Room;
use App\Models\User;
use App\Support\Identity\PlayerIdentity;
use App\Support\Identity\PlayerToken;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Signalement et masquage du pseudo — spec 40 § 13.3 (D66 du 07/10, L40-12)
|--------------------------------------------------------------------------
|
| Tout siège tenu, ni parti ni expulsé, signale le pseudo d'un AUTRE siège du
| même salon ; deux sièges distincts depuis le début de la fenêtre masquent
| le pseudo pour la vie du siège et consignent `nickname.masked`, geste
| automatique. La réponse est la même dans tous les cas acceptés.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
});

/**
 * Le signalement par la route, au nom du siège signaleur.
 *
 * @return TestResponse<Response>
 */
function nicknameReportSend(TestCase $test, Room $room, PlayerToken $token, Player $reporter, Player $target): TestResponse
{
    LobbyWrites::actAs($test, $token);

    return LobbyWrites::send(
        $test,
        'POST',
        route('room.players.report_nickname', ['room' => $room, 'target' => $target->public_id]),
        $room,
        [],
        $reporter,
    );
}

it('deux sièges distincts masquent un pseudo, le même siège deux fois jamais', function () {
    [$room, $host, $hostToken] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room);
    [$target] = HostGestures::seat($room);

    nicknameReportSend($this, $room, $hostToken, $host, $target)->assertSessionHasNoErrors();
    nicknameReportSend($this, $room, $hostToken, $host, $target)->assertSessionHasNoErrors();

    expect(Report::query()->count())->toBe(1)
        ->and($target->refresh()->nickname_masked_at)->toBeNull()
        ->and(AdminAction::query()->count())->toBe(0);

    nicknameReportSend($this, $room, $guestToken, $guest, $target)->assertSessionHasNoErrors();

    $target->refresh();
    $line = AdminAction::query()->sole();

    expect($target->nickname_masked_at)->not->toBeNull()
        ->and($target->nickname)->not->toBeNull()
        ->and($line->action)->toBe(AdminActionType::NicknameMasked)
        ->and($line->subject_id)->toBe($target->id)
        ->and($line->actor_id)->toBeNull()
        ->and($line->actor_name)->toBe(AdminAction::SYSTEM_ACTOR)
        ->and($line->reports_count)->toBe(2)
        ->and(Report::query()->where('target_type', ReportTarget::Nickname->value)->where('target_player_id', $target->id)->count())->toBe(2);
});

it('refuse le signalement de soi, d’un autre salon, d’un siège parti ou expulsé, et en solo', function () {
    [$room, $host, $hostToken] = HostGestures::room();
    [$target] = HostGestures::seat($room);
    $report = app(ReportSeatNickname::class);
    $refused = function (Room $room, Player $reporter, Player $target) use ($report): void {
        expect(fn () => $report->handle($room, $reporter, $target))
            ->toThrow(ValidationException::class);
    };

    // Soi-même, par la route : l'erreur traduite revient sous `nickname`.
    nicknameReportSend($this, $room, $hostToken, $host, $host)
        ->assertSessionHasErrors(['nickname' => __('common.player.report.not_reportable')]);

    // Un siège d'un autre salon : introuvable dans celui-ci.
    [, $elsewhere] = HostGestures::room();
    nicknameReportSend($this, $room, $hostToken, $host, $elsewhere)->assertNotFound();
    $refused($room, $host, $elsewhere);

    // Un signaleur parti, ou expulsé.
    [$left] = HostGestures::seat($room, attributes: [
        'connection_state' => PlayerConnectionState::Left,
        'left_at' => now(),
    ]);
    [$kicked] = HostGestures::seat($room, attributes: [
        'connection_state' => PlayerConnectionState::Left,
        'left_at' => now(),
        'kicked_at' => now(),
    ]);
    $refused($room, $left->refresh(), $target);
    $refused($room, $kicked->refresh(), $target);

    // En solo : un siège sans salon ne signale rien, ni n'est signalé.
    $solo = Player::factory()->create(['room_id' => null]);
    $refused($room, $solo, $target);
    $refused($room, $host, $solo);

    // Un pseudo effacé n'a plus rien à signaler.
    $target->forceFill(['nickname' => null, 'nickname_normalized' => null])->save();
    $refused($room, $host, $target->refresh());

    expect(Report::query()->count())->toBe(0)
        ->and(AdminAction::query()->count())->toBe(0);
});

it('répond le même retour neutre dans tous les cas acceptés', function () {
    [$room, $host, $hostToken] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room);
    [$third, $thirdToken] = HostGestures::seat($room);
    [$target] = HostGestures::seat($room);

    $shape = static fn (TestResponse $response): array => [
        $response->getStatusCode(),
        $response->headers->get('Location'),
        session('errors')?->getBag('default')->toArray() ?? [],
    ];

    $first = $shape(nicknameReportSend($this, $room, $hostToken, $host, $target));
    $again = $shape(nicknameReportSend($this, $room, $hostToken, $host, $target));
    $threshold = $shape(nicknameReportSend($this, $room, $guestToken, $guest, $target));
    $alreadyMasked = $shape(nicknameReportSend($this, $room, $thirdToken, $third, $target));

    expect($first[0])->toBe(Response::HTTP_SEE_OTHER)
        ->and($first[2])->toBe([])
        ->and($again)->toBe($first)
        ->and($threshold)->toBe($first)
        ->and($alreadyMasked)->toBe($first)
        ->and(AdminAction::query()->count())->toBe(1);
});

it('diffuse l’identité masquée et masque l’affichage gelé de la partie en cours', function () {
    [$room, $host] = HostGestures::room();
    [$guest] = HostGestures::seat($room);
    [$target] = HostGestures::seat($room, attributes: ['nickname' => 'Pseudo Vilain', 'nickname_normalized' => 'pseudovilain']);
    [$game] = HostGestures::runningGame($room, [$host, $guest, $target]);
    $recorder = RecordingBroadcaster::install();
    $report = app(ReportSeatNickname::class);

    $report->handle($room, $host, $target);

    expect($recorder->sent)->toBe([]);

    $report->handle($room, $guest, $target);

    $updated = collect($recorder->sent)->firstWhere('event', 'seat.updated');

    expect($updated)->not->toBeNull()
        ->and($updated['payload']['gameRef'])->not->toBeNull()
        ->and($updated['payload']['seat']['publicId'])->toBe($target->public_id)
        ->and($updated['payload']['seat']['masked'])->toBeTrue()
        ->and($updated['payload']['seat']['nickname'])->toBeNull()
        ->and($updated['payload']['seat']['avatar']['initials'])->toBe(AvatarRef::FALLBACK_INITIAL)
        ->and($updated['json'])->not->toContain('Pseudo Vilain');

    $participation = GamePlayer::query()
        ->whereBelongsTo($game)
        ->where('player_id', $target->id)
        ->with('player:'.implode(',', PlayerIdentity::FROZEN_SEAT_COLUMNS))
        ->sole();
    $frozen = PlayerIdentity::fromGamePlayer($participation)->toArray();

    expect($participation->display_nickname)->toBe('Pseudo Vilain')
        ->and($frozen['masked'])->toBeTrue()
        ->and($frozen['nickname'])->toBeNull();
});

it('une levée repart d’une fenêtre de comptage vide', function () {
    [$room, $host] = HostGestures::room();
    [$guest] = HostGestures::seat($room);
    [$third] = HostGestures::seat($room);
    [$target] = HostGestures::seat($room);
    $report = app(ReportSeatNickname::class);

    $report->handle($room, $host, $target);
    $report->handle($room, $guest, $target);

    expect($target->refresh()->nickname_masked_at)->not->toBeNull();

    $this->travel(1)->seconds();
    app(UnmaskNickname::class)->handle(User::factory()->admin()->create(), $target, null);
    $this->travel(1)->seconds();

    expect($target->refresh()->nickname_masked_at)->toBeNull()
        ->and($target->nickname_reports_from)->not->toBeNull()
        ->and(ReportSeatNickname::reportersSince($target))->toBe(0);

    // Un seul signaleur nouveau ne remasque pas ; les deux anciens ne
    // comptent plus (et ne peuvent plus signaler ce siège).
    $report->handle($room, $third, $target);
    $report->handle($room, $host, $target);

    expect($target->refresh()->nickname_masked_at)->toBeNull()
        ->and(ReportSeatNickname::reportersSince($target))->toBe(1);
});

it('purger les signalements ne démasque rien', function () {
    [$room, $host] = HostGestures::room();
    [$guest] = HostGestures::seat($room);
    [$target] = HostGestures::seat($room);
    $report = app(ReportSeatNickname::class);

    $report->handle($room, $host, $target);
    $report->handle($room, $guest, $target);

    Report::query()->delete();

    expect($target->refresh()->nickname_masked_at)->not->toBeNull()
        ->and(PlayerIdentity::fromSeat($target)->toArray()['masked'])->toBeTrue()
        ->and(AdminAction::query()->where('action', AdminActionType::NicknameMasked->value)->count())->toBe(1);
});

it('limite les signalements de pseudo par siège', function () {
    config()->set('game.room.seat_reports_per_minute', 1);
    [$room, $host, $hostToken] = HostGestures::room();
    [$first] = HostGestures::seat($room);
    [$second] = HostGestures::seat($room);

    nicknameReportSend($this, $room, $hostToken, $host, $first)->assertSessionHasNoErrors();
    nicknameReportSend($this, $room, $hostToken, $host, $second)->assertTooManyRequests();

    expect(Report::query()->count())->toBe(1);
});
