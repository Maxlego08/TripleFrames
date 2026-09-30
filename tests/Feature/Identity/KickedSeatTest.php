<?php

use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Models\Player;
use App\Models\Room;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Refus du jeton d'un siège expulsé — D15 du 23/09, spec 40 § 3.10 (L40-1)
|--------------------------------------------------------------------------
|
| Le geste d'expulsion appartient à `50` (L50-6) : ici, le siège expulsé est
| fabriqué par `PlayerFactory::kicked()` — `left`, `left_at = kicked_at` au
| même instant —, et ce qui est prouvé est ce que le jeton en voit, par la pile
| `web` réelle (cookie chiffré, `EncryptCookies`) : `seatIn()` le masque,
| `wasKickedFrom()` le signale, jusqu'à ce que l'archivage efface le hash.
|
*/

beforeEach(function () {
    Route::middleware('web')->get('/_test/kicked-seat/rooms/{room}', function (Request $request, Room $room, PlayerTokenManager $tokens): JsonResponse {
        return response()->json([
            'seat' => $tokens->seatIn($request, $room)?->public_id,
            'kicked' => $tokens->wasKickedFrom($request, $room),
        ]);
    });

    // Les requêtes JSON du client de test n'emportent les cookies qu'à cette
    // condition — un navigateur, lui, les envoie toujours.
    $this->withCredentials();
});

/** Ce que le jeton voit d'un salon : son siège non expulsé, et le refus. */
function kickedSeatView(PlayerToken $token, Room $room): TestResponse
{
    return test()
        ->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR))
        ->getJson('/_test/kicked-seat/rooms/'.$room->room_code)
        ->assertOk();
}

it('masque un siège expulsé à seatIn tandis que wasKickedFrom le signale', function () {
    $token = PlayerToken::mint(Locale::English);
    $room = Room::factory()->create();
    $kicked = Player::factory()->for($room)->kicked()->create(['player_token_hash' => $token->hash()]);

    // L'expulsion : `left`, `left_at = kicked_at` au même instant, à la milliseconde.
    expect($kicked->refresh()->wasKicked())->toBeTrue()
        ->and($kicked->connection_state)->toBe(PlayerConnectionState::Left)
        ->and($kicked->kicked_at?->format('Y-m-d H:i:s.v'))->toBe($kicked->left_at?->format('Y-m-d H:i:s.v'));

    kickedSeatView($token, $room)->assertExactJson(['seat' => null, 'kicked' => true]);

    // Le scope ne filtre pas l'expulsion : c'est au consommateur de l'exclure.
    expect(Player::query()->heldByToken($token)->count())->toBe(1)
        ->and(Player::query()->heldByToken($token)->whereNull('kicked_at')->count())->toBe(0);

    // Le même jeton reste libre ailleurs : il y tient un siège non expulsé.
    $elsewhere = Room::factory()->create();
    $held = Player::factory()->for($elsewhere)->create(['player_token_hash' => $token->hash()]);

    kickedSeatView($token, $elsewhere)->assertExactJson(['seat' => $held->public_id, 'kicked' => false]);

    // Un siège parti sans expulsion reste repris : seul `kicked_at` refuse.
    $leftRoom = Room::factory()->create();
    $left = Player::factory()->for($leftRoom)->left()->create(['player_token_hash' => $token->hash()]);

    expect($left->wasKicked())->toBeFalse();
    kickedSeatView($token, $leftRoom)->assertExactJson(['seat' => $left->public_id, 'kicked' => false]);

    // Un autre jeton, dans le salon de l'expulsion, n'est pas concerné.
    kickedSeatView(PlayerToken::mint(Locale::English), $room)->assertExactJson(['seat' => null, 'kicked' => false]);

    // Sans jeton, ni siège ni refus.
    $this->getJson('/_test/kicked-seat/rooms/'.$room->room_code)
        ->assertOk()
        ->assertExactJson(['seat' => null, 'kicked' => false]);
});

it("n'oublie le refus que lorsque l'archivage efface le hash du jeton", function () {
    $this->freezeSecond();

    $token = PlayerToken::mint(Locale::French);
    $room = Room::factory()->create();
    $kicked = Player::factory()->for($room)->kicked()->create(['player_token_hash' => $token->hash()]);
    $kickedAt = $kicked->refresh()->kicked_at;

    // Le temps seul ne lève pas le refus : 23 h plus tard, le salon vit encore.
    $this->travel(23)->hours();

    kickedSeatView($token, $room)->assertExactJson(['seat' => null, 'kicked' => true]);

    // L'archivage (§ 2.1, étape 8 ; 10 § 11.1) efface pseudo, forme repliée et
    // hash dans la même transaction, par Eloquent (10 § 1.2).
    DB::transaction(function () use ($room): void {
        $room->forceFill([
            'status' => RoomStatus::Archived,
            'room_code_active' => null,
            'archived_at' => now(),
        ])->save();

        Player::query()->whereBelongsTo($room)->update([
            'nickname' => null,
            'nickname_normalized' => null,
            'player_token_hash' => null,
        ]);
    });

    kickedSeatView($token, $room)->assertExactJson(['seat' => null, 'kicked' => false]);

    // Le refus est tombé sans que `kicked_at` soit jamais remise à NULL.
    expect($kicked->refresh()->wasKicked())->toBeTrue()
        ->and($kicked->kicked_at?->format('Y-m-d H:i:s.v'))->toBe($kickedAt?->format('Y-m-d H:i:s.v'))
        ->and($kicked->player_token_hash)->toBeNull();
});
