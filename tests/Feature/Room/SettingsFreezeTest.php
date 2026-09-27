<?php

use App\Actions\Game\FinalizeGame;
use App\Actions\Room\WriteRoomSettings;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\SettingPresetKey;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Support\Identity\PlayerToken;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Gel des réglages — spec 50 § 2.5 (invariant 3) et § 12.7, contrat C6
| (lot L50-7b)
|--------------------------------------------------------------------------
|
| Au lancement, `room.settings` est figé en `game.settings_snapshot`, et les
| colonnes typées de la partie en sont la projection. `room.settings` reste
| immuable du lancement au « Rejouer » de l'hôte, podium compris ; la partie,
| son instantané et ses colonnes figées le restent pour toujours. Les deux
| constantes serveur de la partie (`tier_grace_ms`, `preload_lead_ms`)
| viennent de `PlatformLimits`, jamais de l'hôte ni d'une configuration :
| un hôte hostile ne peut pas s'acheter le palier 1 pour toute la manche.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-27 17:02:44.375');
    $this->travelTo($this->now);
});

/**
 * Une écriture du lobby par la route, au nom du jeton et de l'onglet actif
 * du siège.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function freezeSend(TestCase $test, PlayerToken $token, Room $room, Player $seat, string $method, string $route, array $body = []): TestResponse
{
    LobbyWrites::actAs($test, $token);

    return LobbyWrites::send($test, $method, route($route, $room), $room, $body, $seat);
}

/**
 * Une ligne brute, sans cast : l'état exact, à l'octet.
 *
 * @return array<string, mixed>
 */
function freezeRaw(string $table, int $id): array
{
    return (array) DB::table($table)->where('id', $id)->first();
}

/**
 * Les manches et les paliers bruts d'une partie.
 *
 * @return array{rounds: list<array<string, mixed>>, tiers: list<array<string, mixed>>}
 */
function freezeRawDraw(Game $game): array
{
    $roundIds = Round::query()->whereBelongsTo($game)->pluck('id')->all();

    return [
        'rounds' => DB::table('round')->whereIn('id', $roundIds)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        'tiers' => DB::table('round_tier')->whereIn('round_id', $roundIds)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
    ];
}

it('fige settings_snapshot égal à room.settings et les colonnes de game égales au snapshot', function (): void {
    // Des réglages éloignés des défauts, posés par l'hôte lui-même : `N`,
    // `D`, `R`, la difficulté de saisie et `M` changent tous.
    $framesPerRound = Bounds::DEFAULT_FRAMES_PER_ROUND + 1;
    $roundsCount = Bounds::MIN_ROUNDS_COUNT + 1;
    PoolFixtures::movies($roundsCount + PlatformLimits::drawSubstituteMargin(), FrameLevelCoverage::nominal($framesPerRound));
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token);
    Player::factory()->for($room)->create();

    freezeSend($this, $token, $room, $host, 'PATCH', 'room.settings.update', [
        'framesPerRound' => $framesPerRound,
        'roundDuration' => Bounds::DEFAULT_ROUND_DURATION + 10,
        'revealDuration' => Bounds::DEFAULT_REVEAL_DURATION + 2,
        'inputDifficulty' => InputDifficulty::Easy->value,
        'roundsCount' => $roundsCount,
    ])->assertSessionHasNoErrors();

    $settings = $room->refresh()->settings;

    expect($settings->framesPerRound)->toBe($framesPerRound)
        ->and($settings->roundsCount)->toBe($roundsCount)
        ->and($settings->inputDifficulty)->toBe(InputDifficulty::Easy)
        ->and($settings->equals(RoomSettings::defaults()))->toBeFalse();

    // Le lancement, par la route.
    freezeSend($this, $token, $room, $host, 'POST', 'room.launch')
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    $game = Game::query()->sole();
    $roomRow = freezeRaw('room', $room->id);
    $gameRow = freezeRaw('game', $game->id);

    // L'instantané EST les réglages du salon au lancement : même valeur, même
    // charge à l'octet (SQLite garde la chaîne écrite), même version.
    expect($game->settings_snapshot->equals($room->refresh()->settings))->toBeTrue()
        ->and($gameRow['settings_snapshot'])->toBe($roomRow['settings'])
        ->and($game->settings_version)->toBe(RoomSettings::VERSION)
        ->and($room->settings_version)->toBe(RoomSettings::VERSION);

    // Les colonnes de la partie sont la projection de l'instantané.
    $snapshot = $game->settings_snapshot;

    expect($game->input_difficulty)->toBe($snapshot->inputDifficulty)
        ->and($game->rounds_count)->toBe($snapshot->roundsCount)
        ->and($game->frames_per_round)->toBe($snapshot->framesPerRound)
        ->and($game->tier_grace_ms)->toBe(PlatformLimits::tierGraceMs())
        ->and($game->preload_lead_ms)->toBe(PlatformLimits::preloadLeadMs());

    // Et les manches et paliers matérialisés aussi (§ 12.4) : durée de
    // manche, durée, valeur et décalage de chaque palier.
    $rounds = Round::query()->whereBelongsTo($game)->orderBy('sequence_index')->get();

    expect($rounds)->not->toBeEmpty();

    foreach ($rounds as $round) {
        $tiers = RoundTier::query()->where('round_id', $round->id)->orderBy('tier_index')->get();

        expect($round->duration_ms)->toBe($snapshot->roundDuration() * 1000)
            ->and($tiers)->toHaveCount($snapshot->framesPerRound);

        foreach ($tiers as $tier) {
            expect($tier->duration_ms)->toBe($snapshot->tierDurations[$tier->tier_index - 1] * 1000)
                ->and($tier->points)->toBe($snapshot->tierPoints[$tier->tier_index - 1])
                ->and($tier->starts_at_offset_ms)->toBe($snapshot->tierStartOffsetMs($tier->tier_index));
        }
    }

    // En partie : les réglages sont figés. Écriture et preset repartent vers
    // la page du salon, sans erreur ni écriture ; l'écrivain unique lève.
    $refuseEveryWrite = function () use ($token, $room, $host, $roomRow): void {
        freezeSend($this, $token, $room, $host, 'PATCH', 'room.settings.update', ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('room.show', $room))
            ->assertSessionHasNoErrors();

        freezeSend($this, $token, $room, $host, 'POST', 'room.settings.preset', ['preset' => SettingPresetKey::Fast->value])
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('room.show', $room))
            ->assertSessionHasNoErrors();

        expect(fn () => DB::transaction(static function () use ($room): void {
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            app(WriteRoomSettings::class)->handle($locked, RoomSettings::defaults(), Date::now()->toImmutable());
        }))->toThrow(LogicException::class);

        expect(freezeRaw('room', $room->id))->toBe($roomRow);
    };

    $refuseEveryWrite();

    // Sur le podium : toujours figés, jusqu'au « Rejouer ».
    $this->travel(1)->minutes();
    app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, Date::now()->toImmutable());
    $frozenGame = freezeRaw('game', $game->id);
    $draw = freezeRawDraw($game);

    $refuseEveryWrite();

    // Après « Rejouer », le salon se règle de nouveau ; la partie jouée,
    // elle, reste figée pour toujours : ni son instantané, ni ses colonnes,
    // ni ses manches et paliers ne suivent les nouveaux réglages.
    freezeSend($this, $token, $room, $host, 'POST', 'room.replay')->assertSessionHasNoErrors();
    freezeSend($this, $token, $room, $host, 'PATCH', 'room.settings.update', [
        'framesPerRound' => Bounds::MIN_FRAMES_PER_ROUND,
        'roundsCount' => Bounds::MIN_ROUNDS_COUNT,
    ])->assertSessionHasNoErrors();

    expect($room->refresh()->frames_per_round)->toBe(Bounds::MIN_FRAMES_PER_ROUND)
        ->and($room->settings->equals($snapshot))->toBeFalse()
        ->and(freezeRaw('game', $game->id))->toBe($frozenGame)
        ->and(freezeRawDraw($game))->toBe($draw)
        ->and($game->refresh()->settings_snapshot->equals($snapshot))->toBeTrue()
        ->and($frozenGame['settings_snapshot'])->toBe($gameRow['settings_snapshot']);
});

it('porte les constantes de plateforme sur une partie lancée par un hôte hostile', function (): void {
    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token, RoomSettings::fromInput(['roundsCount' => Bounds::MIN_ROUNDS_COUNT]));
    Player::factory()->for($room)->create();
    $roomRow = freezeRaw('room', $room->id);

    // Un déploiement qui surchargerait les deux constantes : ignoré (§ 2.3).
    platformLimitsConfigure(['tier_grace_ms' => 120_000, 'preload_lead_ms' => 9_000]);

    expect(PlatformLimits::tierGraceMs())->toBe(PlatformLimits::DEFAULT_TIER_GRACE_MS)
        ->and(PlatformLimits::preloadLeadMs())->toBe(PlatformLimits::DEFAULT_PRELOAD_LEAD_MS);

    // L'hôte poste les constantes serveur, sous tous leurs noms : refus dur,
    // sous le champ posté, rien d'écrit.
    $hostile = [
        'graceMs' => 120_000,
        'tierGraceMs' => 120_000,
        'preloadLeadMs' => 9_000,
        'speedBonusMaxPercent' => 100,
        'speedBonusMaxFraction' => 1,
        'tier_grace_ms' => 120_000,
        'preload_lead_ms' => 9_000,
    ];

    foreach ($hostile as $key => $value) {
        freezeSend($this, $token, $room, $host, 'PATCH', 'room.settings.update', [$key => $value])
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertSessionHasErrors([$key]);

        expect(freezeRaw('room', $room->id))->toBe($roomRow);
    }

    // Même glissées dans la charge stockée du salon, hors de tout
    // constructeur, elles ne sont jamais lues.
    $raw = json_decode((string) $roomRow['settings'], true, flags: JSON_THROW_ON_ERROR);
    DB::table('room')->where('id', $room->id)->update([
        'settings' => json_encode([...$raw, 'tierGraceMs' => 120_000, 'preloadLeadMs' => 9_000, 'graceMs' => 120_000], JSON_THROW_ON_ERROR),
    ]);

    freezeSend($this, $token, $room, $host, 'POST', 'room.launch')
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    $game = Game::query()->sole();
    $snapshot = json_decode((string) freezeRaw('game', $game->id)['settings_snapshot'], true, flags: JSON_THROW_ON_ERROR);

    // La partie porte les constantes de plateforme, et son instantané ne
    // porte que les seize champs du value object.
    expect($game->tier_grace_ms)->toBe(PlatformLimits::DEFAULT_TIER_GRACE_MS)
        ->and($game->preload_lead_ms)->toBe(PlatformLimits::DEFAULT_PRELOAD_LEAD_MS)
        ->and(array_keys($snapshot))->toBe(RoomSettings::FIELDS)
        ->and($game->settings_snapshot->equals(RoomSettings::fromInput(['roundsCount' => Bounds::MIN_ROUNDS_COUNT])))->toBeTrue();

    // Et elles ne changent plus : la garde des colonnes figées refuse de les
    // réécrire après le lancement.
    expect(fn () => $game->forceFill(['tier_grace_ms' => 120_000])->save())->toThrow(LogicException::class, 'tier_grace_ms')
        ->and(fn () => $game->refresh()->forceFill(['preload_lead_ms' => 9_000])->save())->toThrow(LogicException::class, 'preload_lead_ms')
        ->and($game->refresh()->tier_grace_ms)->toBe(PlatformLimits::DEFAULT_TIER_GRACE_MS)
        ->and($game->preload_lead_ms)->toBe(PlatformLimits::DEFAULT_PRELOAD_LEAD_MS);
});
