<?php

use App\Actions\Game\FinalizeGame;
use App\Actions\Game\OpenGame;
use App\Actions\Room\LaunchGame;
use App\Enums\ContentAvailability;
use App\Enums\GameMode;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\PoolFault;
use App\Enums\PoolRemedyKind;
use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Enums\RoundStatus;
use App\Http\Controllers\Room\LaunchController;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Middleware\SetLocale;
use App\Jobs\Game\AdvanceRound;
use App\Models\Frame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\MovieTitle;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundTier;
use App\Models\Theme;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Support\Answers\AnswerRules;
use App\Support\Deploy\DeployDrain;
use App\Support\Draw\PoolReport;
use App\Support\Draw\PoolReporter;
use App\Support\Draw\PoolScope;
use App\Support\Draw\PoolTooSmallException;
use App\Support\Game\SeatViewPresenter;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\WirePayload;
use App\Support\Room\RoomSettingsPresenter;
use App\Support\Scoring\ScoringRules;
use App\Support\Tmdb\TmdbClient;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use App\ValueObjects\Room\LaunchOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\GameJournalRecorder;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\LobbyWrites;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Lancement d'une partie — spec 50 § 12, contrat C6 (lot L50-7a)
|--------------------------------------------------------------------------
|
| Une seule transaction, sous le verrou du salon : autorité d'hôte relue (et
| réparée), statut, réglages de la version courante, seuil de joueurs
| connectés, puis `OpenGame` — drainage, garde de vivier REJOUÉE, graine,
| partie, participations, tirage de `30`, matérialisation et programmation
| de `60` —, et le salon en `playing`. Les jobs et les diffusions partent
| après la validation. Un refus de règle est une donnée, rendue dans la
| langue de la requête ; un échec technique annule tout et se rend traduit.
|
| Les parties se jouent sur de vrais films (`PoolFixtures`), fichiers sur un
| disque `frames` simulé, avec `M` au minimum des bornes : la marge de
| réserve se tire dans la limite du vivier, et chaque film coûte sa banque
| d'images.
|
| La page du salon en partie (`room.show`, branche de partie de
| `GameStateBuilder`) est le lot L60-12 : aucune redirection n'est suivie ici.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->now = CarbonImmutable::parse('2026-09-26 14:05:13.042');
    $this->travelTo($this->now);
});

/**
 * Réglages d'une partie courte : `M` au minimum des bornes, tout le reste au
 * défaut, par le seul constructeur borné.
 *
 * @param  array<string, mixed>  $input
 */
function launchSettings(array $input = []): RoomSettings
{
    return RoomSettings::fromInput(['roundsCount' => Bounds::MIN_ROUNDS_COUNT, ...$input]);
}

/**
 * Un salon au lobby, son hôte (siège du jeton, onglet actif) et `$guests`
 * sièges connectés.
 *
 * @return array{0: Room, 1: Player, 2: list<Player>}
 */
function launchRoom(PlayerToken $token, ?RoomSettings $settings = null, int $guests = 1): array
{
    [$room, $host] = LobbyWrites::hostedRoom($token, $settings ?? launchSettings());
    $others = [];

    for ($index = 0; $index < $guests; $index++) {
        $others[] = Player::factory()->for($room)->create();
    }

    return [$room, $host, $others];
}

/**
 * Le lancement par la route, depuis la page du salon, onglet actif du siège.
 *
 * @return TestResponse<Response>
 */
function launchPost(TestCase $test, Room $room, Player $seat): TestResponse
{
    return LobbyWrites::send($test, 'POST', route('room.launch', $room), $room, [], $seat);
}

function launchAct(Room $room, Player $requester): LaunchOutcome
{
    return app(LaunchGame::class)->handle($room, $requester);
}

/**
 * Les lignes que seul un lancement écrit.
 *
 * @return array{game: int, game_player: int, round: int, round_tier: int}
 */
function launchRowCounts(): array
{
    return [
        'game' => Game::query()->count(),
        'game_player' => GamePlayer::query()->count(),
        'round' => Round::query()->count(),
        'round_tier' => RoundTier::query()->count(),
    ];
}

/**
 * La ligne `room` brute, sans cast : pour prouver qu'un refus n'a rien écrit.
 *
 * @return array<string, mixed>
 */
function launchRawRoom(Room $room): array
{
    return (array) DB::table('room')->where('id', $room->id)->first();
}

/**
 * Les jobs en file, tels que la table `jobs` les porte : file et classe.
 *
 * @return list<array{queue: string, job: string}>
 */
function launchQueuedJobs(): array
{
    return DB::table('jobs')->orderBy('id')->get()->map(static function (object $row): array {
        $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);

        return ['queue' => (string) $row->queue, 'job' => (string) data_get($payload, 'displayName')];
    })->values()->all();
}

/**
 * Le message d'un refus, dans la langue de la requête.
 *
 * @param  array<string, int>  $replace
 */
function launchMessage(string $key, Locale $locale, array $replace = []): string
{
    $message = trans($key, $replace, $locale->value);

    expect($message)->toBeString()->not->toBe($key);

    return (string) $message;
}

it('crée une partie, une participation par siège tenu, min(M + marge, vivier) manches de N paliers, et passe le salon en playing', function (): void {
    $settings = launchSettings();
    $roundsCount = $settings->roundsCount;
    $framesPerRound = $settings->framesPerRound;
    $works = $roundsCount + PlatformLimits::drawSubstituteMargin() + 1;
    $movies = PoolFixtures::movies($works);
    $movieIds = array_map(static fn (Movie $movie): int => $movie->id, $movies);

    $token = PlayerToken::mint(Locale::French);
    [$room, $host, [$guest]] = launchRoom($token, $settings);
    $away = Player::factory()->for($room)->disconnected()->create();
    $gone = Player::factory()->for($room)->left()->create();
    $kicked = Player::factory()->for($room)->kicked()->create();
    LobbyWrites::actAs($this, $token);

    launchPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    $game = Game::query()->sole();

    // La partie : la projection des réglages figés, la règle, les constantes
    // non surchargeables, l'instant serveur, le vivier compté en œuvres.
    expect($game->room_id)->toBe($room->id)
        ->and($game->mode)->toBe(GameMode::Multiplayer)
        ->and($game->status)->toBe(GameStatus::Running)
        ->and($game->ended_at)->toBeNull()
        ->and($game->input_difficulty)->toBe($settings->inputDifficulty)
        ->and($game->rounds_count)->toBe($roundsCount)
        ->and($game->frames_per_round)->toBe($framesPerRound)
        ->and($game->settings_snapshot->equals($room->settings))->toBeTrue()
        ->and($game->settings_version)->toBe(RoomSettings::VERSION)
        ->and($game->tier_grace_ms)->toBe(PlatformLimits::tierGraceMs())
        ->and($game->preload_lead_ms)->toBe(PlatformLimits::preloadLeadMs())
        ->and($game->scoring_version)->toBe(ScoringRules::VERSION)
        ->and($game->validation_version)->toBe(AnswerRules::VERSION)
        ->and($game->draw_pool_size)->toBe($works)
        ->and($game->draw_seed)->toMatch('/^[0-9a-f]{64}$/')
        ->and($game->started_at->format('Y-m-d H:i:s.v'))->toBe($this->now->format('Y-m-d H:i:s.v'));

    // Une participation par siège tenu — connecté ou déconnecté —, jamais
    // pour un siège parti ou expulsé ; affichage gelé depuis le siège.
    $participations = GamePlayer::query()->whereBelongsTo($game)->orderBy('id')->get();

    expect($participations->pluck('player_id')->all())->toBe([$host->id, $guest->id, $away->id])
        ->and($participations->pluck('player_id')->all())->not->toContain($gone->id)
        ->and($participations->pluck('player_id')->all())->not->toContain($kicked->id);

    foreach ($participations as $participation) {
        $seat = Player::query()->findOrFail($participation->player_id);

        expect($participation->status)->toBe(GamePlayerStatus::Playing)
            ->and($participation->first_round_number)->toBe(1)
            ->and($participation->display_nickname)->toBe($seat->nickname)
            ->and($participation->display_avatar_kind)->toBe($seat->avatar_kind)
            ->and($participation->display_avatar_preset)->toBe($seat->avatar_preset)
            ->and($participation->final_score)->toBeNull()
            ->and($participation->final_rank)->toBeNull();
    }

    // min(M + marge, vivier) manches, numérotées 1..M puis la réserve, de N
    // paliers chacune, chaque palier sur une variante de son film.
    $rounds = Round::query()->whereBelongsTo($game)->orderBy('sequence_index')->get();
    $expectedRounds = min($roundsCount + PlatformLimits::drawSubstituteMargin(), $works);

    expect($rounds)->toHaveCount($expectedRounds)
        ->and($rounds->pluck('sequence_index')->all())->toBe(range(1, $expectedRounds))
        ->and($rounds->pluck('round_number')->all())->toBe([
            ...range(1, $roundsCount),
            ...array_fill(0, $expectedRounds - $roundsCount, null),
        ])
        ->and(array_diff($rounds->pluck('movie_id')->all(), $movieIds))->toBe([])
        ->and($rounds->pluck('movie_id')->unique()->count())->toBe($expectedRounds);

    foreach ($rounds as $round) {
        $tiers = RoundTier::query()->where('round_id', $round->id)->orderBy('tier_index')->get();

        expect($round->status)->toBe(RoundStatus::Pending)
            ->and($tiers->pluck('tier_index')->all())->toBe(range(1, $framesPerRound))
            ->and($tiers->pluck('frame_level')->all())->toBe(FrameLevelCoverage::nominal($framesPerRound));

        foreach ($tiers as $tier) {
            expect(Frame::query()->findOrFail($tier->frame_id)->movie_id)->toBe($round->movie_id);
        }
    }

    // La manche 1 programmée après le décompte de lancement, son palier 1
    // frappé ; aucune autre manche programmée ; aucune participation de
    // manche avant T₁.
    $first = $rounds->firstOrFail();

    expect($first->started_at?->format('Y-m-d H:i:s.v'))
        ->toBe($this->now->addMilliseconds(EngineConstants::launchCountdownMs())->format('Y-m-d H:i:s.v'))
        ->and(RoundTier::query()->where('round_id', $first->id)->where('tier_index', 1)->value('serve_token'))->toBeString()
        ->and($rounds->slice(1)->pluck('started_at')->filter()->all())->toBe([])
        ->and(DB::table('round_player')->count())->toBe(0);

    // Le salon en partie, au même instant.
    $room->refresh();

    expect($room->status)->toBe(RoomStatus::Playing)
        ->and($room->launched_at?->toDateTimeString())->toBe($this->now->toDateTimeString())
        ->and($room->last_activity_at->toDateTimeString())->toBe($this->now->toDateTimeString());

    // Un vivier plus étroit que la marge : toutes ses œuvres, pas une de plus.
    platformLimitsConfigure(['draw_substitute_margin' => $works - $roundsCount + 2]);

    $otherToken = PlayerToken::mint(Locale::English);
    [$other, $otherHost] = launchRoom($otherToken, $settings);

    $outcome = launchAct($other, $otherHost);

    expect($outcome->isLaunched())->toBeTrue()
        ->and(Round::query()->whereBelongsTo($outcome->game)->count())->toBe($works)
        ->and($outcome->game?->draw_pool_size)->toBe($works);
});

it('refuse un siège qui n\'est pas l\'hôte courant', function (): void {
    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $hostToken = PlayerToken::mint(Locale::French);
    $guestToken = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($hostToken, guests: 0);
    $guest = LobbyWrites::seat($room, $guestToken);
    $before = launchRawRoom($room);

    // Par la route : la policy refuse le siège qui n'est pas l'hôte.
    LobbyWrites::actAs($this, $guestToken);

    launchPost($this, $room, $guest)->assertForbidden();

    expect(launchRowCounts())->toBe(['game' => 0, 'game_player' => 0, 'round' => 0, 'round_tier' => 0])
        ->and(launchRawRoom($room))->toBe($before);

    // L'hôte change entre la policy et le verrou : l'action relit l'autorité
    // sous le verrou, et refuse en 403.
    LobbyWrites::actAs($this, $hostToken);
    Event::listen(GateEvaluated::class, static function (GateEvaluated $event) use ($room, $guest): void {
        if ($event->ability === 'launch' && $event->result === true) {
            Room::query()->whereKey($room->id)->update(['host_player_id' => $guest->id]);
        }
    });

    launchPost($this, $room, $host)->assertForbidden();

    expect(launchRowCounts()['game'])->toBe(0)
        ->and(Room::query()->findOrFail($room->id)->status)->toBe(RoomStatus::Lobby);

    // Un hôte sans cible valide (parti) : le rôle passe au siège connecté le
    // plus ancien AVANT la décision, jamais une erreur ; un autre demandeur
    // est refusé, la réparation reste acquise.
    $repair = Room::factory()->withSettings(launchSettings())->create();
    $formerHost = Player::factory()->for($repair)->left()->create(['joined_at' => $this->now->subMinutes(3)]);
    $oldest = Player::factory()->for($repair)->create(['joined_at' => $this->now->subMinutes(2)]);
    $younger = Player::factory()->for($repair)->create(['joined_at' => $this->now->subMinute()]);
    Room::query()->whereKey($repair->id)->update(['host_player_id' => $formerHost->id]);

    expect(launchAct($repair, $younger)->refusal)->toBe(RoomRefusal::NotHost)
        ->and(Room::query()->findOrFail($repair->id)->host_player_id)->toBe($oldest->id)
        ->and(Game::query()->where('room_id', $repair->id)->exists())->toBeFalse();

    expect(launchAct($repair, $oldest)->isLaunched())->toBeTrue();

    // Un siège d'un AUTRE salon n'est jamais l'hôte de celui-ci, même désigné
    // par la référence : la référence est réparée vers un siège du salon.
    [$elsewhere, $elsewhereHost] = launchRoom(PlayerToken::mint(Locale::French));
    Room::query()->whereKey($elsewhere->id)->update(['host_player_id' => $host->id]);

    expect(launchAct($elsewhere, $host)->refusal)->toBe(RoomRefusal::NotHost)
        ->and(Room::query()->findOrFail($elsewhere->id)->host_player_id)->toBe($elsewhereHost->id)
        ->and(Game::query()->where('room_id', $elsewhere->id)->exists())->toBeFalse();
});

it('ne crée qu\'une partie sur un double clic et redirige le second sans erreur', function (): void {
    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::French);
    [$room, $host, $guests] = launchRoom($token);
    LobbyWrites::actAs($this, $token);

    launchPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    $afterFirst = launchRowCounts();
    $roomAfterFirst = launchRawRoom($room);

    // Le second clic : le salon est en partie, 303 vers la même page, sans
    // erreur ni écriture.
    launchPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    expect(launchRowCounts())->toBe($afterFirst)
        ->and($afterFirst['game'])->toBe(1)
        ->and($afterFirst['game_player'])->toBe(1 + count($guests))
        ->and(launchRawRoom($room))->toBe($roomAfterFirst);

    // Par l'action : `not_in_lobby`, rien de plus.
    expect(launchAct($room, $host)->refusal)->toBe(RoomRefusal::NotInLobby)
        ->and(Game::query()->where('room_id', $room->id)->whereNull('ended_at')->count())->toBe(1);
});

it('refuse un salon archivé avec room_archived', function (): void {
    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::French);

    // L'hôte relu tient encore son siège : `room_archived`, rien d'écrit.
    [$room, $host] = launchRoom($token);
    Room::query()->whereKey($room->id)->update(['status' => RoomStatus::Archived->value, 'archived_at' => $this->now]);
    $before = launchRawRoom($room);

    $outcome = launchAct($room, $host);

    expect($outcome->refusal)->toBe(RoomRefusal::RoomArchived)
        ->and($outcome->game)->toBeNull()
        ->and(launchRowCounts()['game'])->toBe(0)
        ->and(launchRawRoom($room))->toBe($before);

    // Un salon archivé n'a plus d'hôte : il est refusé sans qu'aucun hôte ne
    // lui soit rendu.
    Room::query()->whereKey($room->id)->update(['host_player_id' => null]);

    expect(launchAct($room, $host)->refusal)->toBe(RoomRefusal::RoomArchived)
        ->and(Room::query()->findOrFail($room->id)->host_player_id)->toBeNull();

    // Par la route, archivé entre la policy et le verrou : le refus revient
    // traduit dans la page du salon, jamais en 500.
    [$live, $liveHost] = launchRoom($token);
    LobbyWrites::actAs($this, $token);
    Event::listen(GateEvaluated::class, static function (GateEvaluated $event) use ($live): void {
        if ($event->ability === 'launch') {
            Room::query()->whereKey($live->id)->update(['status' => RoomStatus::Archived->value]);
        }
    });

    launchPost($this, $live, $liveHost)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($live))
        ->assertSessionHasErrors(['room' => launchMessage('room.refusal.room_archived', Locale::French)]);

    expect(launchRowCounts()['game'])->toBe(0);
});

it('refuse sous deux sièges connectés avec not_enough_players', function (): void {
    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token, guests: 0);

    // Hors l'hôte, que des sièges qui ne comptent pas : déconnecté, parti,
    // expulsé.
    $away = Player::factory()->for($room)->disconnected()->create();
    Player::factory()->for($room)->left()->create();
    Player::factory()->for($room)->kicked()->create();
    LobbyWrites::actAs($this, $token);
    $before = launchRawRoom($room);
    $min = Bounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH;

    $outcome = launchAct($room, $host);

    expect($outcome->refusal)->toBe(RoomRefusal::NotEnoughPlayers)
        ->and($outcome->replace)->toBe(['min' => $min]);

    launchPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasErrors(['room' => launchMessage('room.refusal.not_enough_players', Locale::French, ['min' => $min])]);

    expect(launchRowCounts()['game'])->toBe(0)
        ->and(launchRawRoom($room))->toBe($before);

    // Le seuil atteint : la partie part, le siège déconnecté y participe.
    Player::query()->whereKey($away->id)->update([
        'connection_state' => PlayerConnectionState::Connected->value,
        'disconnected_at' => null,
    ]);

    launchPost($this, $room, $host)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    expect(GamePlayer::query()->pluck('player_id')->all())->toEqualCanonicalizing([$host->id, $away->id]);
});

it('rejoue la garde de vivier : vivier réduit depuis le lobby → pool_insufficient avec le rapport en données, aucune ligne écrite', function (): void {
    config(['queue.default' => 'database']);
    $recorder = RecordingBroadcaster::install();
    // Un `M` au-dessus du minimum : réduire le nombre de manches reste un
    // remède, que le rapport porte en données.
    $settings = launchSettings(['roundsCount' => Bounds::MIN_ROUNDS_COUNT + 1]);
    $roundsCount = $settings->roundsCount;
    $movies = PoolFixtures::movies($roundsCount);
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token, $settings);
    LobbyWrites::actAs($this, $token);

    // Au lobby, le compteur dit « jouable ».
    $lobby = RoomSettingsPresenter::state($room, $this->now);

    expect($lobby['pool']['blocked'])->toBeFalse()
        ->and($lobby['pool']['count'])->toBe($roundsCount);

    // Un film sort du vivier entre le lobby et le clic (suspension
    // conservatoire) : la garde, rejouée sous le verrou, fait seule autorité.
    Movie::query()->whereKey($movies[0]->id)->update(['availability' => ContentAvailability::Suspended->value]);
    $before = launchRawRoom($room);

    $outcome = launchAct($room, $host);
    $pool = $outcome->pool;

    expect($outcome->refusal)->toBe(RoomRefusal::PoolInsufficient)
        ->and($outcome->game)->toBeNull()
        ->and($outcome->replace)->toBe(['playable' => $roundsCount - 1, 'required' => $roundsCount])
        ->and($pool)->toBeInstanceOf(PoolReport::class)
        ->and($pool?->blocked())->toBeTrue()
        ->and($pool?->count)->toBe($roundsCount - 1)
        ->and($pool?->roundsCount)->toBe($roundsCount)
        ->and($pool?->causes)->toBe([PoolFault::RoundsCount])
        ->and($pool?->toArray()['remedies'])->toBe([[
            'kind' => PoolRemedyKind::ReduceRoundsCount->value,
            'value' => $roundsCount - 1,
            'count' => $roundsCount - 1,
        ]]);

    // En données : entiers, booléens et codes, aucun identifiant.
    WirePayload::assertSafe($pool?->toArray() ?? [], 'pool_insufficient');

    // Aucune ligne écrite : ni partie, ni participation, ni manche, ni le
    // salon lui-même.
    expect(launchRowCounts())->toBe(['game' => 0, 'game_player' => 0, 'round' => 0, 'round_tier' => 0])
        ->and(launchRawRoom($room))->toBe($before)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($recorder->sent)->toBe([]);

    // Par la route : le refus traduit pour l'hôte, et l'état recalculé au
    // salon, par la diffusion anti-rebondie, sur la file `game`.
    launchPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasErrors(['room' => launchMessage('room.refusal.pool_insufficient', Locale::French, [
            'playable' => $roundsCount - 1,
            'required' => $roundsCount,
        ])]);

    expect(launchRowCounts()['game'])->toBe(0)
        ->and(launchRawRoom($room))->toBe($before)
        ->and(array_column(LobbyWrites::queuedBroadcasts(), 'queue'))->toBe(['game']);

    $this->travel(PlatformLimits::lobbyBroadcastDebounceMs() + 1000)->milliseconds();

    expect(LobbyWrites::workGameQueue())->toBe(1)
        ->and($recorder->sent)->toHaveCount(1)
        ->and($recorder->sent[0]['event'])->toBe('settings.changed')
        ->and($recorder->sent[0]['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and($recorder->sent[0]['payload']['pool']['blocked'])->toBeTrue()
        ->and($recorder->sent[0]['payload']['pool']['count'])->toBe($roundsCount - 1);
});

it('normalise des réglages de version antérieure, rapporte les changements et refuse settings_outdated', function (): void {
    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $withdrawn = Theme::factory()->unpublished()->create();
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token, RoomSettings::defaults());

    // Une charge écrite sous une version antérieure : un `M` devenu hors
    // bornes, un thème retiré du site, un champ absent.
    $legacy = RoomSettings::defaults()->toPayload();
    $legacy['roundsCount'] = Bounds::MAX_ROUNDS_COUNT + 5;
    $legacy['themeIds'] = [$withdrawn->id];
    unset($legacy['advanced']);

    DB::table('room')->where('id', $room->id)->update([
        'settings' => json_encode($legacy, JSON_THROW_ON_ERROR),
        'settings_version' => RoomSettings::VERSION - 1,
    ]);

    $outcome = launchAct($room, $host);

    // Le rapport, champ par champ, sous les clés client.
    expect($outcome->refusal)->toBe(RoomRefusal::SettingsOutdated)
        ->and($outcome->game)->toBeNull()
        ->and($outcome->changes)->toEqual([
            'themeKeys' => RoomSettings::CHANGE_PRUNED,
            'roundsCount' => RoomSettings::CHANGE_CLAMPED,
            'advanced' => RoomSettings::CHANGE_DEFAULTED,
        ]);

    // La seule branche de refus qui écrit : la ligne est normalisée, par
    // l'écrivain unique, projections comprises, et aucune partie ne naît.
    $room->refresh();

    expect($room->settings_version)->toBe(RoomSettings::VERSION)
        ->and($room->settings->sourceVersion)->toBe(RoomSettings::VERSION)
        ->and($room->settings->roundsCount)->toBe(Bounds::MAX_ROUNDS_COUNT)
        ->and($room->settings->themeIds)->toBe([])
        ->and($room->settings->advanced)->toBe(Bounds::DEFAULT_ADVANCED)
        ->and($room->rounds_count)->toBe(Bounds::MAX_ROUNDS_COUNT)
        ->and($room->capacity)->toBe($room->settings->capacity)
        ->and($room->frames_per_round)->toBe($room->settings->framesPerRound)
        ->and($room->status)->toBe(RoomStatus::Lobby)
        ->and($room->last_activity_at->toDateTimeString())->toBe($this->now->toDateTimeString())
        ->and(launchRowCounts()['game'])->toBe(0);

    // Par la route, sur une seconde ligne ancienne : le rapport en flash à
    // l'auteur seul, le refus traduit.
    [$again, $againHost] = launchRoom($token, RoomSettings::defaults());
    DB::table('room')->where('id', $again->id)->update([
        'settings' => json_encode($legacy, JSON_THROW_ON_ERROR),
        'settings_version' => RoomSettings::VERSION - 1,
    ]);
    LobbyWrites::actAs($this, $token);

    launchPost($this, $again, $againHost)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($again))
        ->assertSessionHasErrors(['room' => launchMessage('room.refusal.settings_outdated', Locale::French)])
        ->assertInertiaFlash('settingsChanges', [
            'themeKeys' => RoomSettings::CHANGE_PRUNED,
            'roundsCount' => RoomSettings::CHANGE_CLAMPED,
            'advanced' => RoomSettings::CHANGE_DEFAULTED,
        ]);

    // Relancé, le salon normalisé franchit L5 et va jusqu'à la garde de
    // vivier, qui juge le `M` NORMALISÉ : la normalisation est acquise.
    launchPost($this, $again, $againHost)
        ->assertSessionHasErrors(['room' => launchMessage('room.refusal.pool_insufficient', Locale::French, [
            'playable' => Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin(),
            'required' => Bounds::MAX_ROUNDS_COUNT,
        ])]);

    expect(launchRowCounts()['game'])->toBe(0);
});

it('diffuse au salon les réglages normalisés après un refus settings_outdated', function (): void {
    config(['queue.default' => 'database']);
    $recorder = RecordingBroadcaster::install();
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token, RoomSettings::defaults());
    $legacy = RoomSettings::defaults()->toPayload();
    $legacy['revealDuration'] = Bounds::MAX_REVEAL_DURATION + 7;

    DB::table('room')->where('id', $room->id)->update([
        'settings' => json_encode($legacy, JSON_THROW_ON_ERROR),
        'settings_version' => RoomSettings::VERSION - 1,
    ]);
    LobbyWrites::actAs($this, $token);

    launchPost($this, $room, $host)
        ->assertSessionHasErrors(['room' => launchMessage('room.refusal.settings_outdated', Locale::French)])
        ->assertInertiaFlash('settingsChanges', ['revealDuration' => RoomSettings::CHANGE_CLAMPED]);

    // Rien n'est parti avec la réponse ; une diffusion du salon attend sur la
    // file `game`, en instant arrondi à la seconde supérieure.
    $queued = LobbyWrites::queuedBroadcasts();

    expect($recorder->sent)->toBe([])
        ->and($queued)->toHaveCount(1)
        ->and($queued[0]['queue'])->toBe('game')
        ->and($queued[0]['job']->roomId)->toBe($room->id);

    $this->travel(PlatformLimits::lobbyBroadcastDebounceMs() + 1000)->milliseconds();

    expect(LobbyWrites::workGameQueue())->toBe(1)
        ->and($recorder->sent)->toHaveCount(1);

    // Tous les sièges voient les réglages NORMALISÉS, en données, et rien du
    // rapport, réservé à l'auteur.
    $sent = $recorder->sent[0];
    $state = RoomSettingsPresenter::state($room->refresh(), Date::now()->toImmutable());
    $wire = json_decode((string) json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($sent['event'])->toBe('settings.changed')
        ->and($sent['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and(array_diff_key($sent['payload'], array_flip(['v', 'serverNow', 'gameRef'])))->toBe($wire)
        ->and($sent['payload']['settings']['revealDuration'])->toBe(Bounds::MAX_REVEAL_DURATION)
        ->and($sent['json'])->not->toContain('settingsChanges')
        ->and($sent['json'])->not->toContain('"'.RoomSettings::CHANGE_CLAMPED.'"');
});

it('refuse de lancer pendant le drainage avec le message de maintenance et accepte après expiration du drapeau', function (): void {
    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $drain = app(DeployDrain::class);

    foreach ([Locale::French, Locale::English] as $locale) {
        $token = PlayerToken::mint($locale);
        [$room, $host] = launchRoom($token);
        LobbyWrites::actAs($this, $token);
        $before = launchRawRoom($room);

        $drain->release();
        $drain->start(DeployDrain::defaultTimeoutMinutes());

        // Le message unique de la maintenance, jamais `room.refusal.draining`.
        expect(launchAct($room, $host)->refusal)->toBe(RoomRefusal::Draining);

        launchPost($this, $room, $host)
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(LobbyWrites::lobbyUrl($room))
            ->assertSessionHasErrors(['room' => launchMessage('common.maintenance.launch_blocked', $locale)]);

        // Fenêtre libre ouverte : toujours drainé.
        $window = $drain->openWindow(1);

        launchPost($this, $room, $host)
            ->assertSessionHasErrors(['room' => launchMessage('common.maintenance.launch_blocked', $locale)]);

        expect(Game::query()->where('room_id', $room->id)->exists())->toBeFalse()
            ->and(launchRawRoom($room))->toBe($before);

        // Le drapeau expiré de lui-même : le lancement passe.
        $this->travelTo($window->expiresAt);

        launchPost($this, $room, $host)
            ->assertRedirect(route('room.show', $room))
            ->assertSessionHasNoErrors();

        expect(Game::query()->where('room_id', $room->id)->count())->toBe(1);
    }
});

it('ne pose launched_at qu\'au premier lancement', function (): void {
    $settings = launchSettings();
    PoolFixtures::movies($settings->roundsCount + PlatformLimits::drawSubstituteMargin() + 2);
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token, $settings);

    $first = launchAct($room, $host)->game ?? throw new LogicException('Premier lancement refusé.');
    $room->refresh();

    expect($room->launched_at?->toDateTimeString())->toBe($this->now->toDateTimeString())
        ->and($room->last_activity_at->toDateTimeString())->toBe($this->now->toDateTimeString());

    // La partie close, le salon rendu au lobby (« Rejouer », L50-7b), puis un
    // second lancement plus tard.
    $later = $this->now->addMinutes(20);
    $this->travelTo($later);
    app(FinalizeGame::class)->handle($first, GameStatus::Interrupted, $later);
    Room::query()->whereKey($room->id)->update(['status' => RoomStatus::Lobby->value]);

    $second = launchAct($room, $host)->game ?? throw new LogicException('Second lancement refusé.');
    $room->refresh();

    expect($room->launched_at?->toDateTimeString())->toBe($this->now->toDateTimeString())
        ->and($room->last_activity_at->toDateTimeString())->toBe($later->toDateTimeString())
        ->and($second->started_at->format('Y-m-d H:i:s.v'))->toBe($later->format('Y-m-d H:i:s.v'))
        ->and($room->status)->toBe(RoomStatus::Playing);
});

it('écrit draw_pool_size égal au nombre d\'œuvres du vivier rejoué', function (): void {
    $settings = launchSettings();
    $roundsCount = $settings->roundsCount;
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token, $settings);

    // M + 2 films seuls, et deux films d'une même œuvre (`movie_group`).
    $singles = PoolFixtures::movies($roundsCount + 2);
    $group = MovieGroup::factory()->create();
    $grouped = [PoolFixtures::movie(group: $group), PoolFixtures::movie(group: $group)];

    // Le premier a déjà été joué par ce salon : la non-répétition l'écarte.
    $previous = Game::factory()->forRoom($room)->withSettings($settings)->completed()
        ->create(['started_at' => $this->now->subHours(2)]);
    PoolFixtures::round($previous, $singles[0], $this->now->subHour());

    $lobbyCount = RoomSettingsPresenter::state($room, $this->now)['pool']['count'];

    // Un second sort du vivier entre le lobby et le clic.
    Movie::query()->whereKey($singles[1]->id)->update(['availability' => ContentAvailability::Suspended->value]);

    $replayed = app(PoolReporter::class)->report(PoolScope::forRoom($room, $settings, $this->now), $roundsCount)->count;
    $game = launchAct($room, $host)->game ?? throw new LogicException('Lancement refusé.');

    // Les œuvres du vivier REJOUÉ sous le verrou : ni les films (le groupe
    // compte une fois), ni le compteur du lobby.
    expect($replayed)->toBe($roundsCount + 1)
        ->and($lobbyCount)->toBe($roundsCount + 2)
        ->and($game->draw_pool_size)->toBe($replayed);

    // Le tirage a lu le même vivier : toutes ses œuvres, un seul film du groupe.
    $drawn = Round::query()->whereBelongsTo($game)->pluck('movie_id')->all();
    $groupIds = array_map(static fn (Movie $movie): int => $movie->id, $grouped);

    expect($drawn)->toHaveCount(min($roundsCount + PlatformLimits::drawSubstituteMargin(), $replayed))
        ->and($drawn)->not->toContain($singles[0]->id)
        ->and($drawn)->not->toContain($singles[1]->id)
        ->and(count(array_intersect($drawn, $groupIds)))->toBe(1);
});

it('annule toute la transaction si la matérialisation échoue', function (): void {
    config(['queue.default' => 'database']);
    $recorder = RecordingBroadcaster::install();
    $journal = GameJournalRecorder::start();
    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token);

    // Panne injectée au milieu de la matérialisation : après les manches,
    // avant les paliers. L'exception naît ici, pour que son lieu soit connu.
    $failure = new RuntimeException('panne de matérialisation', 0, new PDOException('verrou perdu'));
    $failureLine = __LINE__ - 1;

    DB::beforeExecuting(static function (string $query) use ($failure): void {
        if (preg_match('/^insert into ["`]?round_tier["`]?/i', $query) === 1) {
            throw $failure;
        }
    });

    // Par l'action, sur un hôte sans cible valide : la réparation d'hôte, la
    // partie, ses participations et ses manches sont annulées ensemble.
    Room::query()->whereKey($room->id)->update(['host_player_id' => null]);
    $orphan = launchRawRoom($room);

    expect(static fn () => launchAct($room, $host))->toThrow(RuntimeException::class, 'panne de matérialisation');

    expect(launchRowCounts())->toBe(['game' => 0, 'game_player' => 0, 'round' => 0, 'round_tier' => 0])
        ->and(launchRawRoom($room))->toBe($orphan);

    // Par la route : le salon reste au lobby, l'hôte lit un message traduit,
    // le journal `game` porte la classe, le code et le lieu de l'exception,
    // la classe de sa cause et le statut relu — ni message ni trace.
    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);
    $before = launchRawRoom($room);
    LobbyWrites::actAs($this, $token);

    launchPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasErrors(['room' => launchMessage(LaunchController::KEY_LAUNCH_FAILED, Locale::French)]);

    expect(launchRowCounts())->toBe(['game' => 0, 'game_player' => 0, 'round' => 0, 'round_tier' => 0])
        ->and(launchRawRoom($room))->toBe($before)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($recorder->sent)->toBe([])
        ->and($journal->contexts(LaunchController::LOG_LAUNCH_FAILED))->toBe([[
            'exception' => RuntimeException::class,
            'code' => 0,
            'file' => 'tests/Feature/Room/LaunchGameTest.php',
            'line' => $failureLine,
            'previous' => PDOException::class,
            'roomPlaying' => false,
        ]]);

    $line = json_encode($journal->lines(), JSON_THROW_ON_ERROR);

    expect($line)->not->toContain($room->room_code)
        ->and($line)->not->toContain((string) $host->nickname)
        ->and($line)->not->toContain('panne de matérialisation')
        ->and($line)->not->toContain('verrou perdu')
        ->and($line)->not->toContain(trim(json_encode(base_path(), JSON_THROW_ON_ERROR), '"'));
});

it('rend une erreur traduite et laisse le salon en lobby quand le tirage lève PoolTooSmallException', function (): void {
    config(['queue.default' => 'database']);
    $recorder = RecordingBroadcaster::install();
    $journal = GameJournalRecorder::start();
    $settings = launchSettings();
    $movies = PoolFixtures::movies($settings->roundsCount);

    // Une projection périmée : le vivier compte M œuvres, le tirage n'en
    // retient que M − 1 (30 § 4.6) — un échec technique, jamais un refus
    // de vivier.
    $stale = FrameLevelCoverage::nominal($settings->framesPerRound)[0];
    DB::table('frame')->where('movie_id', $movies[0]->id)->where('frame_level', $stale->value)
        ->update(['availability' => ContentAvailability::Draft->value]);

    foreach ([Locale::French, Locale::English] as $locale) {
        $token = PlayerToken::mint($locale);
        [$room, $host] = launchRoom($token, $settings);
        LobbyWrites::actAs($this, $token);
        $before = launchRawRoom($room);

        expect(RoomSettingsPresenter::state($room, $this->now)['pool']['blocked'])->toBeFalse();

        launchPost($this, $room, $host)
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(LobbyWrites::lobbyUrl($room))
            ->assertSessionHasErrors(['room' => launchMessage(LaunchController::KEY_LAUNCH_FAILED, $locale)])
            ->assertSessionDoesntHaveErrors(['room' => launchMessage('room.refusal.pool_insufficient', $locale, [
                'playable' => $settings->roundsCount - 1,
                'required' => $settings->roundsCount,
            ])]);

        expect(Room::query()->findOrFail($room->id)->status)->toBe(RoomStatus::Lobby)
            ->and(launchRawRoom($room))->toBe($before);
    }

    // Aucune ligne, aucun job, aucune diffusion : pas même l'état du lobby,
    // puisque rien n'a été refusé par la règle. L'exception interceptée est
    // bien celle du tirage, une ligne de journal par lancement.
    expect(launchRowCounts())->toBe(['game' => 0, 'game_player' => 0, 'round' => 0, 'round_tier' => 0])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($recorder->sent)->toBe([])
        ->and(array_column($journal->contexts(LaunchController::LOG_LAUNCH_FAILED), 'exception'))
        ->toBe([PoolTooSmallException::class, PoolTooSmallException::class])
        ->and(array_column($journal->contexts(LaunchController::LOG_LAUNCH_FAILED), 'roomPlaying'))
        ->toBe([false, false]);
});

it('rend la page du salon sans erreur quand la file refuse le job de la manche 1 après la validation', function (): void {
    RecordingBroadcaster::install();
    $journal = GameJournalRecorder::start();
    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token);
    LobbyWrites::actAs($this, $token);

    // Une file injoignable à la poussée (Redis plein en `noeviction`, par
    // exemple) : `AdvanceRound` est `ShouldQueueAfterCommit`, sa poussée court
    // APRÈS le COMMIT et lève hors de `DB::transaction()`.
    Queue::extend('unreachable', static fn (): ConnectorInterface => new class implements ConnectorInterface
    {
        public function connect(array $config): QueueContract
        {
            // `AdvanceRound` porte son instant : il part par `later()`.
            return new class extends NullQueue
            {
                public function push($job, $data = '', $queue = null): mixed
                {
                    return $this->later(null, $job, $data, $queue);
                }

                public function later($delay, $job, $data = '', $queue = null): mixed
                {
                    return $this->enqueueUsing(
                        $job,
                        $this->createPayload($job, $queue ?? 'default', $data),
                        $queue,
                        $delay,
                        static fn (): never => throw new RuntimeException('file injoignable'),
                    );
                }
            };
        }
    });
    config(['queue.connections.unreachable' => ['driver' => 'unreachable'], 'queue.default' => 'unreachable']);

    // La partie est née : la réponse est celle d'un lancement réussi, jamais
    // « le lancement a échoué », qui inviterait l'hôte à relancer une partie
    // déjà en cours. La manche sans job est rattrapée par `CatchUpGame`.
    launchPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    expect(Room::query()->findOrFail($room->id)->status)->toBe(RoomStatus::Playing)
        ->and(launchRowCounts()['game'])->toBe(1)
        ->and(Round::query()->where('status', RoundStatus::Pending->value)->whereNotNull('started_at')->count())->toBe(1)
        ->and($journal->contexts(LaunchController::LOG_LAUNCH_FAILED))->toHaveCount(1)
        ->and($journal->contexts(LaunchController::LOG_LAUNCH_FAILED)[0])->toMatchArray([
            'exception' => RuntimeException::class,
            'previous' => null,
            'roomPlaying' => true,
        ]);

    // Relancé, le geste reste idempotent : aucune seconde partie.
    launchPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    expect(launchRowCounts()['game'])->toBe(1)
        ->and($journal->contexts(LaunchController::LOG_LAUNCH_FAILED))->toHaveCount(1);
});

it('n\'émet aucun job ni diffusion avant la validation de la transaction', function (): void {
    config(['queue.default' => 'database']);
    $recorder = RecordingBroadcaster::install();
    PoolFixtures::movies(2 * (Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin()));
    $token = PlayerToken::mint(Locale::French);

    // Annulée par l'appelant après le lancement : rien ne part, rien ne reste.
    [$cancelled, $cancelledHost] = launchRoom($token);

    try {
        DB::transaction(static function () use ($cancelled, $cancelledHost): void {
            expect(launchAct($cancelled, $cancelledHost)->isLaunched())->toBeTrue();

            throw new RuntimeException('annulée');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($recorder->sent)->toBe([])
        ->and(launchRowCounts()['game'])->toBe(0)
        ->and(Room::query()->findOrFail($cancelled->id)->status)->toBe(RoomStatus::Lobby);

    // Validée : pendant la transaction, rien ; au commit, le job de la
    // frontière du palier 1 et les deux diffusions du lancement.
    [$room, $host, [$guest]] = launchRoom($token);

    DB::transaction(function () use ($room, $host, $recorder): void {
        expect(launchAct($room, $host)->isLaunched())->toBeTrue()
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and($recorder->sent)->toBe([]);
    });

    $game = Game::query()->where('room_id', $room->id)->sole();

    expect(launchQueuedJobs())->toBe([['queue' => 'game', 'job' => AdvanceRound::class]])
        ->and(array_column($recorder->sent, 'event'))->toEqualCanonicalizing(['game.launched', 'round.scheduled']);

    // `game.launched` : les colonnes figées, la révélation et le bonus de
    // l'instantané, les sièges gelés par participation.
    $launched = collect($recorder->sent)->firstWhere('event', 'game.launched');
    $expectedSeats = json_decode(
        (string) json_encode(SeatViewPresenter::gameSeats($game, $host->id), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($launched['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and(array_diff_key($launched['payload'], array_flip(['v', 'serverNow', 'gameRef'])))->toBe([
            'mode' => GameMode::Multiplayer->value,
            'roundsCount' => $game->rounds_count,
            'framesPerRound' => $game->frames_per_round,
            'inputDifficulty' => $game->input_difficulty->value,
            'revealDurationMs' => $game->settings_snapshot->revealDuration * 1000,
            'speedBonus' => $game->settings_snapshot->speedBonus,
            'seats' => $expectedSeats,
        ])
        ->and(array_column($launched['payload']['seats'], 'publicId'))->toBe([$host->public_id, $guest->public_id]);
});

it('ne renvoie ni graine, ni film, ni identifiant interne', function (): void {
    $recorder = RecordingBroadcaster::install();
    $movies = PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token);
    LobbyWrites::actAs($this, $token);

    $response = launchPost($this, $room, $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    $game = Game::query()->sole();
    $movieIds = array_map(static fn (Movie $movie): int => $movie->id, $movies);
    $secrets = [
        $game->draw_seed,
        ...array_map(static fn (Movie $movie): string => $movie->title_original, $movies),
        ...MovieTitle::query()->whereIn('movie_id', $movieIds)->pluck('title')->all(),
    ];

    // La réponse : une redirection vers la page du salon, rien d'autre.
    $surfaces = [
        'réponse' => (string) $response->getContent().json_encode($response->headers->all(), JSON_THROW_ON_ERROR),
        'session' => json_encode(session()->all(), JSON_THROW_ON_ERROR),
    ];

    // Les diffusions : aucune clé d'identifiant interne, aucune graine, aucun
    // titre, aucune taille de vivier.
    foreach ($recorder->sent as $sent) {
        WirePayload::assertSafe($sent['payload'], $sent['event']);
        $surfaces[$sent['event']] = $sent['json'];
    }

    expect(array_keys($surfaces))->toContain('game.launched');

    foreach ($surfaces as $surface => $content) {
        foreach ($secrets as $secret) {
            expect(str_contains((string) $content, (string) $secret))->toBeFalse("[{$surface}] porte [{$secret}]");
        }

        expect((string) $content)->not->toContain('draw_seed')
            ->and((string) $content)->not->toContain('drawPoolSize')
            ->and((string) $content)->not->toContain('draw_pool_size');
    }

    // Un refus aussi : un message et, pour le vivier, des données sans
    // identifiant.
    Movie::query()->whereIn('id', $movieIds)->update(['availability' => ContentAvailability::Suspended->value]);
    [$blocked, $blockedHost] = launchRoom($token);
    $refused = launchAct($blocked, $blockedHost);

    expect($refused->refusal)->toBe(RoomRefusal::PoolInsufficient);
    WirePayload::assertSafe($refused->pool?->toArray() ?? [], 'pool_insufficient');
});

it('n\'appelle jamais TMDB', function (): void {
    Http::fake();
    $resolved = false;
    app()->bind(TmdbClient::class, static function () use (&$resolved): never {
        $resolved = true;

        throw new LogicException('TMDB appelé au lancement (règle 6).');
    });

    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token);
    LobbyWrites::actAs($this, $token);

    // Un lancement complet — garde, tirage, matérialisation, frappe et
    // programmation de la manche 1 — puis un refus rejoué.
    launchPost($this, $room, $host)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    launchPost($this, $room, $host)->assertRedirect(route('room.show', $room));

    expect(Game::query()->count())->toBe(1)
        ->and($resolved)->toBeFalse();

    Http::assertNothingSent();
});

it('OpenGame refuse un appel hors de son contrat et n\'écrit rien hors transaction', function (): void {
    // Ajout (hors intitulés de la spec) : les assertions O0, avant toute
    // lecture et toute écriture.
    [$room] = launchRoom(PlayerToken::mint(Locale::French));
    $seats = Player::query()->whereBelongsTo($room)->get();
    $open = app(OpenGame::class);
    $settings = $room->settings;

    DB::transaction(static function () use ($open, $room, $settings, $seats): void {
        $now = Date::now()->toImmutable();

        expect(static fn () => $open->handle(GameMode::Solo, $room, $settings, $seats, $now))->toThrow(LogicException::class)
            ->and(static fn () => $open->handle(GameMode::Multiplayer, null, $settings, $seats, $now))->toThrow(LogicException::class)
            ->and(static fn () => $open->handle(GameMode::Multiplayer, $room, $settings, $seats->take(0), $now))->toThrow(LogicException::class)
            ->and(static fn () => $open->handle(GameMode::Multiplayer, $room, RoomSettings::fromStorage($settings->toPayload(), RoomSettings::VERSION - 1), $seats, $now))
            ->toThrow(LogicException::class)
            ->and(static fn () => $open->handle(GameMode::Multiplayer, $room, $settings, Player::query()->whereKey(Player::factory()->create()->id)->get(), $now))
            ->toThrow(LogicException::class);
    });

    expect(launchRowCounts()['game'])->toBe(0);

    // Hors de toute transaction de l'appelant : refusé avant la première
    // lecture. `RefreshDatabase` tient une transaction ouverte : on en sort
    // (en l'annulant) le temps de l'assertion, en dernier geste du test, puis
    // on la rouvre pour que le nettoyage de fin de test la retrouve.
    $connection = DB::connection();
    $level = $connection->transactionLevel();

    for ($index = 0; $index < $level; $index++) {
        $connection->rollBack();
    }

    try {
        expect($connection->transactionLevel())->toBe(0)
            ->and(static fn () => $open->handle(GameMode::Multiplayer, $room, $settings, $seats, Date::now()->toImmutable()))
            ->toThrow(LogicException::class, 'transaction');
    } finally {
        for ($index = 0; $index < $level; $index++) {
            $connection->beginTransaction();
        }
    }
});

it('lance par une route sous seat.active puis game-write, et refuse un onglet supplanté en 409 et un visiteur sans siège en 403', function (): void {
    // Ajout (hors intitulés de la spec) : la pile de `room.launch` (§ 21) et
    // les deux refus du middleware de siège, avant toute action.
    $router = app('router');
    app(HttpKernel::class);
    $route = $router->getRoutes()->getByName('room.launch');

    expect($route)->not->toBeNull()
        ->and($route?->methods())->toContain('POST')
        ->and($route?->uri())->toBe('r/{room}/launch')
        ->and($route?->getActionName())->toBe(LaunchController::class.'@store')
        ->and($route?->gatherMiddleware())->toContain('web', 'seat.active', 'throttle:game-write');

    $stack = $route === null ? [] : $router->gatherRouteMiddleware($route);
    $at = static fn (string $middleware): int|false => array_search($middleware, $stack, true);

    expect($at(SetLocale::class))->toBeInt()
        ->and($at(SetLocale::class))->toBeLessThan($at(EnsureActiveSeat::class))
        ->and($at(EnsureActiveSeat::class))->toBeLessThan($at(ThrottleRequests::class.':game-write'))
        ->and($at(ThrottleRequests::class.':game-write'))->toBeLessThan($at(SubstituteBindings::class));

    PoolFixtures::movies(Bounds::MIN_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin());
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = launchRoom($token);
    $before = launchRawRoom($room);
    LobbyWrites::actAs($this, $token);

    // Onglet supplanté, ou aucun en-tête : 409 en données, jamais une page.
    LobbyWrites::send($this, 'POST', route('room.launch', $room), $room, [], (string) Str::ulid())
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(['code' => EnsureActiveSeat::SUPERSEDED]);
    LobbyWrites::send($this, 'POST', route('room.launch', $room), $room, [], false)
        ->assertStatus(Response::HTTP_CONFLICT);

    // Un jeton sans siège dans ce salon : 403.
    LobbyWrites::actAs($this, PlayerToken::mint(Locale::French));

    launchPost($this, $room, $host)->assertForbidden();

    expect(launchRowCounts()['game'])->toBe(0)
        ->and(launchRawRoom($room))->toBe($before);
});
