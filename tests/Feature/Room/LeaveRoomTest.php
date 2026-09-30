<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundPlayerInputState;
use App\Enums\ScoreScope;
use App\Events\Game\SeatUpdated;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Room;
use App\Models\RoundPlayer;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\ChannelNames;
use App\Support\Scoring\Scoreboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Départ volontaire — spec 50 § 11.4 (lot L50-6)
|--------------------------------------------------------------------------
|
| Chaque joueur peut quitter, hôte compris : le siège passe `left` et libère
| sa place ; la participation d'une partie en cours passe `left` sans perdre
| ses points ; le rôle d'hôte passe au plus ancien connecté. Après la
| validation, `seat.updated` (et `host.changed`), puis la réévaluation de la
| fin anticipée de la manche en cours, dans une seconde transaction. Le
| partant peut revenir par le lien.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-27 18:30:12.905');
    $this->travelTo($this->now);
});

it('passe le siège en parti et libère sa place', function (): void {
    $settings = RoomSettings::fromInput(['capacity' => RoomSettingsBounds::MIN_CAPACITY + 1]);
    [$room, $host] = HostGestures::room($settings);
    $capacity = $room->capacity;

    // Le salon est plein.
    $guests = [];

    while (Player::query()->whereBelongsTo($room)->holdingSeat()->count() < $capacity) {
        $guests[] = HostGestures::seat($room, 20 - count($guests));
    }

    [$leaver, $leaverToken] = $guests[0];
    $before = HostGestures::raw('player', $leaver->id);
    Room::query()->whereKey($room->id)->update(['last_activity_at' => $this->now->subHour()]);
    $recorder = RecordingBroadcaster::install();

    LobbyWrites::actAs($this, PlayerToken::mint(Locale::French));
    $this->from(route('room.entry', $room))
        ->post(route('room.join', $room), SeatEntry::form('Attente'))
        ->assertSessionHasErrors('room');

    // Le départ : l'accueil, en 303.
    HostGestures::leave($this, $room, $leaverToken, $leaver)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('home'));

    $left = $leaver->refresh();

    expect($left->connection_state)->toBe(PlayerConnectionState::Left)
        ->and(HostGestures::ms($left->left_at))->toBe('2026-09-27 18:30:12.905')
        ->and($left->wasKicked())->toBeFalse()
        ->and(Player::query()->whereBelongsTo($room)->holdingSeat()->count())->toBe($capacity - 1)
        ->and($room->refresh()->last_activity_at?->equalTo($this->now->startOfSecond()))->toBeTrue()
        ->and($room->host_player_id)->toBe($host->id);

    // Rien d'autre du siège ne change : son pseudo reste réservé.
    expect(array_diff_key(HostGestures::raw('player', $leaver->id), array_flip(['connection_state', 'left_at', 'updated_at'])))
        ->toBe(array_diff_key($before, array_flip(['connection_state', 'left_at', 'updated_at'])));

    // Au salon : le siège, parti, jamais retiré.
    expect(array_column($recorder->sent, 'event'))->toBe(['seat.updated']);

    $sent = $recorder->sent[0];

    expect($sent['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and($sent['payload']['seat']['publicId'])->toBe($leaver->public_id)
        ->and($sent['payload']['seat']['connection'])->toBe('left')
        ->and($sent['payload']['seat']['kicked'])->toBeFalse();

    // La place est libre : un nouveau venu entre.
    LobbyWrites::actAs($this, PlayerToken::mint(Locale::French));
    $this->post(route('room.join', $room), SeatEntry::form('Arrivée'))->assertRedirect(route('room.show', $room));

    expect(Player::query()->whereBelongsTo($room)->holdingSeat()->count())->toBe($capacity);

    // Un second départ ne réécrit rien : `left_at` reste le premier.
    $this->travel(4)->seconds();
    $recorder->sent = [];
    HostGestures::leave($this, $room, $leaverToken, $leaver)->assertRedirect(route('home'));

    expect(HostGestures::ms($leaver->refresh()->left_at))->toBe('2026-09-27 18:30:12.905')
        ->and($recorder->sent)->toBe([]);

    // Le partant revient par le lien : une reprise, sans nouveau siège.
    LobbyWrites::actAs($this, $leaverToken);
    $this->get(route('room.show', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('game/lobby')->where('state.self.publicId', $leaver->public_id));

    expect(Player::query()->whereBelongsTo($room)->heldByToken($leaverToken)->count())->toBe(1);
});

it("transfère le rôle quand l'hôte quitte", function (): void {
    [$room, $host, $hostToken] = HostGestures::room();
    [$oldest] = HostGestures::seat($room, 20);
    [$younger] = HostGestures::seat($room, 5);
    $recorder = RecordingBroadcaster::install();

    HostGestures::leave($this, $room, $hostToken, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('home'));

    expect($room->refresh()->host_player_id)->toBe($oldest->id)
        ->and($host->refresh()->connection_state)->toBe(PlayerConnectionState::Left);

    // Le nouvel hôte, puis le partant — que sa vue dit déjà non-hôte : les
    // deux messages s'accordent dans quelque ordre qu'on les applique.
    expect(array_column($recorder->sent, 'event'))->toBe(['host.changed', 'seat.updated']);

    [$changed, $updated] = $recorder->sent;

    expect($changed['payload']['hostPublicId'])->toBe($oldest->public_id)
        ->and($changed['payload']['previousHostPublicId'])->toBe($host->public_id)
        ->and($updated['payload']['seat']['publicId'])->toBe($host->public_id)
        ->and($updated['payload']['seat']['isHost'])->toBeFalse()
        ->and($updated['payload']['seat']['connection'])->toBe('left');

    // Seul au salon, l'hôte qui part laisse le rôle vacant, sans message.
    [$alone, $aloneHost, $aloneToken] = HostGestures::room();
    $recorder->sent = [];

    HostGestures::leave($this, $alone, $aloneToken, $aloneHost)->assertRedirect(route('home'));

    expect($alone->refresh()->host_player_id)->toBeNull()
        ->and(array_column($recorder->sent, 'event'))->toBe(['seat.updated'])
        ->and($younger->refresh()->connection_state)->toBe(PlayerConnectionState::Connected);
});

it("marque left la participation d'une partie en cours et conserve les points", function (): void {
    [$room, $host] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room, 20);
    [$game, $round] = HostGestures::runningGame($room, [$host, $guest]);

    $guess = Guess::factory()->forRound($round, $guest)->create(['received_at' => Date::now()]);
    RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $guest->id)->update([
        'input_state' => RoundPlayerInputState::Locked->value,
        'input_closed_at' => (new RoundPlayer)->fromDateTime(Date::now()),
    ]);
    $guessRow = HostGestures::raw('guess', $guess->id);
    $score = Scoreboard::seatScore($game, $guest)['ownScore'];

    expect($score)->toBeGreaterThan(0);

    HostGestures::leave($this, $room, $guestToken, $guest)->assertRedirect(route('home'));

    $participation = GamePlayer::query()->whereBelongsTo($game)->where('player_id', $guest->id)->sole();
    $tally = collect(Scoreboard::tallies($game, ScoreScope::Own))->firstWhere('publicId', $guest->public_id);

    expect($participation->status)->toBe(GamePlayerStatus::Left)
        ->and($participation->final_score)->toBeNull()
        ->and(HostGestures::raw('guess', $guess->id))->toBe($guessRow)
        ->and(Scoreboard::seatScore($game, $guest)['ownScore'])->toBe($score)
        ->and($tally?->status)->toBe(GamePlayerStatus::Left)
        ->and($tally?->score)->toBe($score)
        ->and(GamePlayer::query()->whereBelongsTo($game)->where('player_id', $host->id)->value('status'))
        ->toBe(GamePlayerStatus::Playing);

    // La manche continue : le partant avait déjà trouvé, l'hôte non.
    expect($round->refresh()->ended_at)->toBeNull();

    // Partie déjà figée (podium) : aucune écriture sur `game_player`.
    [$podium, $podiumHost] = HostGestures::room();
    [$podiumGuest, $podiumToken] = HostGestures::seat($podium, 20);
    [$ended, $endedRound] = HostGestures::runningGame($podium, [$podiumHost, $podiumGuest]);
    app(FinalizeGame::class)->handle($ended, GameStatus::Interrupted, EngineFixtures::durationEnd($endedRound));
    $frozen = GamePlayer::query()->whereBelongsTo($ended)->where('player_id', $podiumGuest->id)->sole();
    $row = HostGestures::raw('game_player', $frozen->id);

    HostGestures::leave($this, $podium, $podiumToken, $podiumGuest)->assertRedirect(route('home'));

    expect(HostGestures::raw('game_player', $frozen->id))->toBe($row)
        ->and($podiumGuest->refresh()->connection_state)->toBe(PlayerConnectionState::Left);
});

it('réévalue la fin anticipée de la manche en cours après un départ volontaire', function (): void {
    [$room, $host] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room, 20);
    [, $round] = HostGestures::runningGame($room, [$host, $guest]);

    // L'hôte a trouvé ; l'invité, encore ouvert, retient seul la manche.
    RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $host->id)->update([
        'input_state' => RoundPlayerInputState::Locked->value,
        'input_closed_at' => (new RoundPlayer)->fromDateTime(Date::now()),
    ]);

    $leaveAt = Date::now()->toImmutable()->addMilliseconds(3_217);
    $this->travelTo($leaveAt);

    // L'horloge avance entre la validation et la réévaluation : le moteur
    // clôt à l'instant ÉCRIT du départ, jamais à l'heure d'exécution.
    Event::listen(SeatUpdated::class, fn () => $this->travelTo($leaveAt->addMilliseconds(1_200)));

    HostGestures::leave($this, $room, $guestToken, $guest)->assertRedirect(route('home'));

    $leftAt = $guest->refresh()->left_at;

    expect(HostGestures::ms($leftAt))->toBe(HostGestures::ms($leaveAt))
        ->and(HostGestures::ms($round->refresh()->ended_at))->toBe(HostGestures::ms($leftAt));

    // Un départ qui laisse un participant encore ouvert ne clôt rien.
    [$open, $openHost] = HostGestures::room();
    [$first, $firstToken] = HostGestures::seat($open, 20);
    [$second] = HostGestures::seat($open, 10);
    [, $openRound] = HostGestures::runningGame($open, [$openHost, $first, $second]);

    HostGestures::leave($this, $open, $firstToken, $first)->assertRedirect(route('home'));

    expect($openRound->refresh()->ended_at)->toBeNull();
});
