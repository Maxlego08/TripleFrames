<?php

use App\Actions\Game\AdvanceToNextRound;
use App\Actions\Game\CatchUpGame;
use App\Actions\Game\FinalizeGame;
use App\Actions\Game\ScheduleRound;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Events\Game\GameEnded;
use App\Events\Game\GameFinalized;
use App\Events\Game\GameLaunched;
use App\Events\Game\GamePaused;
use App\Events\Game\GamePauseRequestCancelled;
use App\Events\Game\GamePauseRequested;
use App\Events\Game\GameResumed;
use App\Events\Game\HostChanged;
use App\Events\Game\PlayerLocked;
use App\Events\Game\RoomArchived;
use App\Events\Game\RoomBroadcast;
use App\Events\Game\RoomReplayed;
use App\Events\Game\RoundCancelled;
use App\Events\Game\RoundClosed;
use App\Events\Game\RoundRevealed;
use App\Events\Game\RoundScheduled;
use App\Events\Game\SeatBroadcast;
use App\Events\Game\SeatChoicesOffered;
use App\Events\Game\SeatJoined;
use App\Events\Game\SeatKicked;
use App\Events\Game\SeatSuperseded;
use App\Events\Game\SeatUpdated;
use App\Events\Game\SettingsChanged;
use App\Events\Game\TierOpened;
use App\Http\Middleware\EnsureActiveSeat;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Collection;
use App\Models\Frame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\MovieTitle;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Models\Theme;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Answers\ChoicesPresenter;
use App\Support\Draw\DrawContext;
use App\Support\Draw\SeededPrf;
use App\Support\Game\GameStateBuilder;
use App\Support\Game\NextRoundOutcome;
use App\Support\Game\RevealMovieBuilder;
use App\Support\Game\RoundStep;
use App\Support\Game\SeatViewPresenter;
use App\Support\Game\TransitionBroadcasts;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\Support\Identity\PublicId;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\GameWire;
use App\Support\Realtime\WirePayload;
use App\Support\Realtime\WireTime;
use App\Support\Scoring\Scoreboard;
use App\ValueObjects\Answers\ChoicesPayload;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\I18n\FrontSource;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Realtime\RecordingJob;
use Tests\Support\Realtime\WireFixtures;
use Tests\Support\Realtime\WireScene;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\SeatEntry;
use Tests\Support\Scoring\ScoringFixtures;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Contrat d'événements — spec 60 § 11, contrat C7 § 2.3 et § 3 (lots L60-3, L60-6, L60-7, L60-11, L60-12)
|--------------------------------------------------------------------------
|
| Les dix-neuf événements de la liste close, émis par la chaîne réelle du
| framework (`ShouldDispatchAfterCommit`, `ShouldBroadcastNow`,
| `ShouldRescue`, `broadcastOn()`, `broadcastWith()`) jusqu'à un diffuseur
| enregistreur, avec des charges de la forme que leurs émetteurs produiront
| (`WireFixtures`). Les intitulés de ce lot portent sur le transport :
| enveloppe, identifiants internes, liste close, transaction annulée,
| diffusion en échec. Ceux de L60-6 portent sur les émetteurs de clôture et
| de révélation (`CloseRound`, `RevealRound`, par les transitions réelles) :
| instants de clôture et de titres, `lang` de chaque titre. Celui de L60-7
| porte sur la réémission de `round.scheduled` (« manche suivante »,
| rattrapage). La liste close s'éprouve aussi sur son miroir client (L60-9,
| écart (i)) : l'union `GameEventName` et les charges de `types/game-wire.ts`,
| et la répartition salon / siège des écoutes de `lib/game/echo.ts`. Ceux
| de L60-11 portent sur ses émetteurs, en fin de fichier : QCM ciblé
| (`OpenTier`), `player.locked` (crochet de fin de saisie), et aucun titre
| avant `revealStartsAt` hors des quatre propositions ; « ni aucun paquet »
| s'éprouve sur
| `GameStatePacket` : branche sans partie dès L60-4 (lobby et solo, par
| `room.state` et par le constructeur), branche de partie depuis L60-12
| (manche en cours au QCM composé, grâce finale, révélation, partie gelée,
| partie solo ; le `serve_token` n'y sort que dans l'URL de son palier).
|
*/

/**
 * La liste close du J1 (60 § 11.3) : nom → classe, canal, champs de la
 * charge hors enveloppe, dans l'ordre du tableau.
 *
 * @return array<string, array{class-string, string, list<string>}>
 */
function eventPayloadClosedList(): array
{
    return [
        'seat.joined' => [SeatJoined::class, 'room', ['seat']],
        'seat.updated' => [SeatUpdated::class, 'room', ['seat']],
        'host.changed' => [HostChanged::class, 'room', ['hostPublicId', 'previousHostPublicId']],
        'settings.changed' => [SettingsChanged::class, 'room', ['settings', 'warnings', 'pool']],
        'room.replayed' => [RoomReplayed::class, 'room', ['settings', 'warnings', 'pool']],
        'game.launched' => [GameLaunched::class, 'room', ['mode', 'roundsCount', 'framesPerRound', 'inputDifficulty', 'revealDurationMs', 'speedBonus', 'seats']],
        'room.archived' => [RoomArchived::class, 'room', []],
        'round.scheduled' => [RoundScheduled::class, 'room', ['round', 'image']],
        'tier.opened' => [TierOpened::class, 'room', ['sequenceIndex', 'roundNumber', 'tierIndex', 'opensAt', 'next', 'choicesUnavailable']],
        'player.locked' => [PlayerLocked::class, 'room', ['sequenceIndex', 'publicId', 'lockRank']],
        'round.closed' => [RoundClosed::class, 'room', ['sequenceIndex', 'roundNumber', 'endedAt', 'revealStartsAt', 'revealEndsAt']],
        'round.revealed' => [RoundRevealed::class, 'room', ['sequenceIndex', 'roundNumber', 'revealEndsAt', 'movie', 'images', 'frames', 'finders', 'leaderboard']],
        'round.cancelled' => [RoundCancelled::class, 'room', ['sequenceIndex', 'roundNumber']],
        'game.paused' => [GamePaused::class, 'room', ['pausedAt', 'interruptsAt', 'kind']],
        'game.resumed' => [GameResumed::class, 'room', ['resumedAt']],
        'game.pause_requested' => [GamePauseRequested::class, 'room', ['requestedAt']],
        'game.pause_request_cancelled' => [GamePauseRequestCancelled::class, 'room', []],
        'game.ended' => [GameEnded::class, 'room', ['podium']],
        'seat.choices' => [SeatChoicesOffered::class, 'seat', ['sequenceIndex', 'choices', 'useOriginalTitle', 'lang']],
        'seat.superseded' => [SeatSuperseded::class, 'seat', []],
        'seat.kicked' => [SeatKicked::class, 'seat', []],
    ];
}

/**
 * Les littéraux d'une union TS de chaînes (`export type X = 'a' | 'b';`), dans
 * l'ordre du fichier, commentaires retirés.
 *
 * @return list<string>
 */
function eventPayloadTsUnion(string $file, string $type): array
{
    $source = FrontSource::withoutComments((string) file_get_contents(resource_path($file)));

    expect(preg_match('/export\s+type\s+'.$type.'\s*=([^;]+);/', $source, $union))->toBe(1, "Union {$type} introuvable dans {$file}.");
    preg_match_all("/'([^']+)'/", $union[1], $literals);

    return $literals[1];
}

/**
 * Les éléments d'un tableau TS constant (`export const X = ['a', …]`).
 *
 * @return list<string>
 */
function eventPayloadTsArray(string $file, string $constant): array
{
    $source = FrontSource::withoutComments((string) file_get_contents(resource_path($file)));

    expect(preg_match('/export\s+const\s+'.$constant.'\s*=\s*\[([^\]]*)\]/', $source, $array))->toBe(1, "Tableau {$constant} introuvable dans {$file}.");
    preg_match_all("/'([^']+)'/", $array[1], $literals);

    return $literals[1];
}

/**
 * Les clés de premier niveau d'une interface TS écrites entre apostrophes
 * (`'a.b': …;`), dans l'ordre.
 *
 * @return list<string>
 */
function eventPayloadTsInterfaceKeys(string $file, string $interface): array
{
    $source = FrontSource::withoutComments((string) file_get_contents(resource_path($file)));
    $start = strpos($source, 'export interface '.$interface.' ');

    expect($start)->not->toBeFalse("Interface {$interface} introuvable dans {$file}.");

    $body = substr($source, (int) strpos($source, '{', (int) $start) + 1);
    $keys = [];
    $depth = 0;

    foreach (preg_split('/\R/', $body) ?: [] as $line) {
        if ($depth === 0 && preg_match("/^\s*'([^']+)'\s*:/", $line, $key) === 1) {
            $keys[] = $key[1];
        }

        $depth += substr_count($line, '{') - substr_count($line, '}');

        if ($depth < 0) {
            break;
        }
    }

    return $keys;
}

/**
 * Les clés qui ne partent jamais, en `snake_case` comme en `camelCase`
 * (60 § 11.7, 10 § 1.1) — liste écrite ici, indépendante de la garde de
 * `WirePayload`.
 *
 * @return list<string>
 */
function eventPayloadInternalKeys(): array
{
    $snake = [
        'id', 'round_id', 'game_id', 'room_id', 'player_id', 'frame_id', 'movie_id', 'user_id',
        'served_frame_id', 'frame_level', 'game_path', 'master_path', 'draw_seed', 'draw_pool_size',
        'choice_1', 'choice_2', 'choice_3', 'choice_4', 'decoy_movie_id_1', 'decoy_movie_id_2',
        'decoy_movie_id_3', 'active_seat_token', 'player_token_hash', 'serve_token', 'theme_ids',
        'answer_key_id', 'host_player_id', 'nickname_normalized',
    ];

    $keys = [];

    foreach ($snake as $key) {
        $keys[] = $key;
        $keys[] = Str::camel($key);
    }

    return array_values(array_unique([...$keys, 'tokenHash', 'input_state']));
}

/**
 * Toutes les clés d'une charge, à toute profondeur.
 *
 * @param  array<array-key, mixed>  $payload
 * @return list<string>
 */
function eventPayloadKeys(array $payload): array
{
    $keys = [];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($value)) {
            array_push($keys, ...eventPayloadKeys($value));
        }
    }

    return $keys;
}

/** Nom Pusher attendu du canal d'un événement de la scène. */
function eventPayloadChannel(RoomBroadcast|SeatBroadcast $event, WireScene $scene): string
{
    return $event instanceof SeatBroadcast
        ? 'private-'.ChannelNames::seat($scene->guest)
        : 'presence-'.ChannelNames::room($scene->room);
}

it('chaque événement porte v, serverNow au format ISO-8601 UTC à la milliseconde et gameRef', function (): void {
    $recorder = RecordingBroadcaster::install();
    $scene = WireFixtures::scene();

    // Charges précalculées sous le verrou, hors UTC, microsecondes comprises…
    Date::setTestNow(CarbonImmutable::parse('2026-09-23 16:05:13.004999', 'Europe/Paris'));

    $cases = [];

    foreach (WireFixtures::events($scene) as $class => $event) {
        $inGame = $event::GAME_BOUND || $class === SeatUpdated::class;
        $cases[] = [$event, $inGame ? $scene->game : null];
    }

    // Les événements qui peuvent partir au lobby partent aussi en partie.
    $cases[] = [new HostChanged($scene->room, $scene->game, ['hostPublicId' => $scene->guest->public_id, 'previousHostPublicId' => null]), $scene->game];
    $cases[] = [new SeatSuperseded($scene->guest, $scene->game, []), $scene->game];
    $cases[] = [new SeatUpdated($scene->room, null, ['seat' => WireFixtures::lobbySeatView($scene->room, $scene->host)]), null];

    // …et émises plus tard : `serverNow` est pris à l'émission.
    Date::setTestNow(CarbonImmutable::parse('2026-09-23 16:05:14.254321', 'Europe/Paris'));

    foreach ($cases as [$event]) {
        event($event);
    }

    expect($recorder->sent)->toHaveCount(count($cases));

    foreach ($cases as $index => [$event, $game]) {
        $sent = $recorder->sent[$index];
        $payload = $sent['payload'];
        $name = $event->broadcastAs();

        expect($sent['event'])->toBe($name)
            ->and($sent['channels'])->toBe([eventPayloadChannel($event, $scene)])
            // L'enveloppe en tête, puis les seuls champs de la liste close.
            ->and(array_slice(array_keys($payload), 0, 3))->toBe(['v', 'serverNow', 'gameRef'])
            ->and(array_slice(array_keys($payload), 3))->toBe(array_keys($event::FIELDS))
            ->and($payload['v'])->toBe(GameWire::VERSION)->toBe(1)
            ->and($payload['serverNow'])->toBe('2026-09-23T14:05:14.254Z')
            ->and($payload['serverNow'])->toMatch(WireTime::PATTERN)
            ->and($payload['gameRef'])->toBe($game instanceof Game ? GameRef::for($game) : null);

        // Sur le fil : un entier, une chaîne, et `null` écrit en toutes lettres.
        expect($sent['json'])->toStartWith('{"v":1,"serverNow":"2026-09-23T14:05:14.254Z","gameRef":')
            ->and($sent['json'])->not->toContain('"socket"');

        if ($game instanceof Game) {
            expect($payload['gameRef'])->toMatch('/^[0-9a-f]{16}$/')
                ->and($sent['json'])->not->toContain('"gameRef":'.$game->id.',');
        }
    }

    // Tous les instants d'une charge suivent la même forme `IsoMs`.
    $revealed = collect($recorder->sent)->firstWhere('event', 'round.closed');

    foreach (['endedAt', 'revealStartsAt', 'revealEndsAt'] as $field) {
        expect($revealed['payload'][$field] ?? null)->toMatch(WireTime::PATTERN);
    }
});

/**
 * Les jetons d'image que portent les références d'image d'une charge, à
 * toute profondeur (`TierImageRef` : `{ tierIndex, url, fetchNotBefore }`),
 * jeton → palier annoncé.
 *
 * @param  array<array-key, mixed>  $payload
 * @return array<string, int>
 */
function eventPayloadImageTokens(array $payload): array
{
    $tokens = [];

    if (isset($payload['tierIndex'], $payload['url']) && is_string($payload['url']) && is_int($payload['tierIndex'])) {
        $tokens[(string) preg_replace('#^/f/([0-9a-f]{32})\?.*$#', '$1', $payload['url'])] = $payload['tierIndex'];
    }

    foreach ($payload as $value) {
        if (is_array($value)) {
            $tokens += eventPayloadImageTokens($value);
        }
    }

    return $tokens;
}

/**
 * Le `serve_token` — seul identifiant d'image qui quitte le serveur (C8) —
 * ne sort que dans l'URL signée d'une référence d'image, celle de SON
 * palier, une seule fois ; aucun autre jeton frappé en base n'apparaît.
 *
 * @param  array<array-key, mixed>  $payload
 */
function eventPayloadAssertServeTokens(array $payload, string $json, string $label): void
{
    $carried = eventPayloadImageTokens($payload);

    foreach ($carried as $serveToken => $tierIndex) {
        expect(RoundTier::query()->where('serve_token', $serveToken)->value('tier_index'))
            ->toBe($tierIndex, "[{$label}] porte l'URL d'un autre palier que celui annoncé.")
            ->and(substr_count($json, $serveToken))->toBe(1, "[{$label}] porte le jeton d'image hors de son URL.");
    }

    $minted = array_filter(RoundTier::query()->pluck('serve_token')->all(), is_string(...));

    expect($minted)->not->toBeEmpty();

    foreach (array_diff($minted, array_keys($carried)) as $serveToken) {
        expect($json)->not->toContain($serveToken);
    }
}

/**
 * Les paquets de PARTIE que `room.state` sert à un siège d'une partie
 * multijoueur en Normal — manche en cours au palier du QCM (composé), grâce
 * finale, révélation, partie gelée avec son podium —, et le paquet d'une
 * partie solo en cours, par le constructeur. Horloge figée à chaque instant,
 * jobs de frontière retenus : c'est le rattrapage de la route qui fait
 * avancer la partie.
 *
 * @return array{Room, array<string, array{payload: array<string, mixed>, json: string}>}
 */
function eventPayloadGamePackets(TestCase $test): array
{
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    PoolFixtures::fakeFramesDisk();
    SubmissionFixtures::decoyCandidates();

    $room = Room::factory()->playing()->create();
    $game = EngineFixtures::game(SubmissionFixtures::settings(InputDifficulty::Normal), room: $room);
    $token = PlayerToken::mint(Locale::French);
    $seat = EngineFixtures::seat($game, ['player_token_hash' => $token->hash(), 'active_seat_token' => (string) Str::ulid()]);
    EngineFixtures::seat($game);
    $room->forceFill(['host_player_id' => $seat->id])->save();
    EngineFixtures::materialize($game);

    $round = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($round, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));

    $fetch = static function (CarbonImmutable $at) use ($test, $room, $seat, $token): array {
        Date::setTestNow($at);
        Cache::flush();
        (new ReflectionProperty($test, 'defaultCookies'))->setValue($test, []);

        $response = $test->withCredentials()
            ->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR))
            ->getJson(route('room.state', ['room' => $room->room_code]), [EnsureActiveSeat::HEADER => (string) $seat->active_seat_token])
            ->assertOk();

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        return ['payload' => $payload, 'json' => (string) $response->getContent()];
    };

    $choicesAt = EngineFixtures::opensAt($round, (int) $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round));
    $durationEnd = EngineFixtures::durationEnd($round);
    $packets = [
        'manche, QCM composé' => $fetch($choicesAt->addMilliseconds(1)),
        'grâce finale' => $fetch($durationEnd->addMilliseconds(1)),
        'révélation' => $fetch($durationEnd->addMilliseconds($game->tier_grace_ms)),
    ];

    $frozenAt = CarbonImmutable::parse($packets['révélation']['payload']['round']['revealEndsAt'] ?? throw new LogicException('Révélation sans fin.'));
    Date::setTestNow($frozenAt);
    app(FinalizeGame::class)->handle($game->refresh(), GameStatus::Interrupted, $frozenAt);
    $packets['partie gelée'] = $fetch($frozenAt->addSecond());

    $solo = EngineFixtures::game(SubmissionFixtures::settings(InputDifficulty::Normal), solo: true);
    $soloSeat = EngineFixtures::seat($solo);
    EngineFixtures::materialize($solo);
    $soloRound = EngineFixtures::round($solo, 1);
    EngineFixtures::schedule($soloRound, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));
    EngineFixtures::openTier($soloRound, 1);
    $soloPacket = GameStateBuilder::build($solo->refresh(), $soloSeat, Date::now()->toImmutable(), $soloSeat->active_seat_token);
    $packets['partie solo'] = ['payload' => $soloPacket, 'json' => json_encode($soloPacket, JSON_THROW_ON_ERROR)];

    return [$room, $packets];
}

it("aucune charge d'événement ni aucun paquet ne contient de clé d'identifiant interne", function (): void {
    $recorder = RecordingBroadcaster::install();
    $scene = WireFixtures::scene();

    // Un jeton d'image frappé et une frame réelle derrière chaque palier : ce
    // qui ne doit jamais partir existe bien en base.
    RoundTier::query()->each(function (RoundTier $tier): void {
        $tier->forceFill(['serve_token' => bin2hex(random_bytes(16))])->save();
    });

    foreach (WireFixtures::events($scene) as $event) {
        event($event);
    }

    expect($recorder->sent)->toHaveCount(count(eventPayloadClosedList()));

    $forbidden = eventPayloadInternalKeys();
    $never = array_values(array_filter([
        ...Player::query()->pluck('player_token_hash')->all(),
        ...Player::query()->pluck('active_seat_token')->all(),
        ...Frame::query()->pluck('game_path')->all(),
        ...Game::query()->pluck('draw_seed')->all(),
    ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

    expect($never)->not->toBeEmpty();

    foreach ($recorder->sent as $sent) {
        foreach (eventPayloadKeys($sent['payload']) as $key) {
            expect(in_array($key, $forbidden, true))->toBeFalse("[{$sent['event']}] porte la clé interne [{$key}].");

            // Toute clé d'identifiant, hors l'adresse publique d'un siège.
            expect(preg_match('/^id$|_id$|(?<!public|Public)Id$/', $key))->toBe(0, "[{$sent['event']}] porte la clé d'identifiant [{$key}].");
        }

        foreach ($never as $value) {
            expect($sent['json'])->not->toContain($value);
        }

        eventPayloadAssertServeTokens($sent['payload'], $sent['json'], $sent['event']);
    }

    // Les URL signées réelles partent bien : `round.scheduled`,
    // `tier.opened.next` et `round.revealed` portent chacun leur jeton.
    expect(count(eventPayloadImageTokens(collect($recorder->sent)->firstWhere('event', 'round.revealed')['payload'] ?? [])))
        ->toBe($scene->game->frames_per_round);

    // « Ni aucun paquet » — branche sans partie (L60-4) : le paquet de lobby
    // servi par `room.state` à un siège dont l'onglet tient la main, et le
    // paquet solo. Mêmes clés interdites, même garde, et aucune des valeurs
    // qui ne partent jamais — `active_seat_token` compris, que l'onglet
    // présente pourtant.
    $lobby = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);
    $seat = Player::factory()->create(['room_id' => $lobby->id, 'player_token_hash' => $token->hash()]);
    Player::factory()->count(2)->create(['room_id' => $lobby->id]);
    Player::factory()->kicked()->create(['room_id' => $lobby->id]);
    $lobby->forceFill(['host_player_id' => $seat->id])->save();

    $served = $this->withCredentials()
        ->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR))
        ->getJson(route('room.state', ['room' => $lobby->room_code]), [EnsureActiveSeat::HEADER => (string) $seat->active_seat_token])
        ->assertOk();

    expect($served->json('self.seatActive'))->toBeTrue();

    $packets = [
        'lobby' => ['payload' => $served->json(), 'json' => (string) $served->getContent()],
    ];

    $solo = GameStateBuilder::build(null, Player::factory()->solo()->create(), Date::now()->toImmutable(), null);
    $packets['solo'] = ['payload' => $solo, 'json' => json_encode($solo, JSON_THROW_ON_ERROR)];

    // Branche de partie (L60-12, passation E83-3) : les paquets qui
    // concentrent le risque de fuite — manche en cours QCM composé, grâce
    // finale, révélation, partie gelée avec son podium — servis par
    // `room.state` après le rattrapage, et une partie solo par le
    // constructeur.
    [$gameRoom, $gamePackets] = eventPayloadGamePackets($this);
    $packets = [...$packets, ...$gamePackets];

    $never = array_values(array_filter([
        ...$never,
        ...Player::query()->pluck('player_token_hash')->all(),
        ...Player::query()->pluck('active_seat_token')->all(),
        ...Frame::query()->pluck('game_path')->all(),
        ...Game::query()->pluck('draw_seed')->all(),
    ], static fn (mixed $value): bool => is_string($value) && $value !== ''));
    foreach ($packets as $label => $packet) {
        WirePayload::assertSafe($packet['payload'], "paquet {$label}");

        foreach (eventPayloadKeys($packet['payload']) as $key) {
            expect(in_array($key, $forbidden, true))->toBeFalse("[paquet {$label}] porte la clé interne [{$key}].");
            expect(preg_match('/^id$|_id$|(?<!public|Public)Id$/', $key))->toBe(0, "[paquet {$label}] porte la clé d'identifiant [{$key}].");
        }

        foreach ($never as $value) {
            expect($packet['json'])->not->toContain($value);
        }

        eventPayloadAssertServeTokens($packet['payload'], $packet['json'], "paquet {$label}");

        // Ni l'identifiant du salon, ni son code : le canal passe par la clé
        // HMAC (E10-31).
        expect($packet['json'])->not->toContain('"'.$lobby->room_code.'"')
            ->and($packet['json'])->not->toContain('"'.$gameRoom->room_code.'"');
    }

    // Chaque paquet de partie a bien porté ce qu'il devait éprouver.
    expect($packets['manche, QCM composé']['payload']['self']['input']['choices']['choices'] ?? [])->toHaveCount(4)
        ->and($packets['manche, QCM composé']['payload']['round']['images'] ?? [])->not->toBeEmpty()
        ->and($packets['grâce finale']['payload']['round']['phase'] ?? null)->toBe('closed')
        ->and($packets['révélation']['payload']['round']['reveal'] ?? null)->not->toBeNull()
        ->and($packets['partie gelée']['payload']['podium'] ?? null)->not->toBeNull()
        ->and($packets['partie solo']['payload']['round']['images'] ?? [])->not->toBeEmpty();

    // La QCM ciblé porte ses quatre chaînes, jamais `choice_1` en position
    // identifiable : sa charge est une liste, sans clé par proposition.
    $choices = collect($recorder->sent)->firstWhere('event', 'seat.choices');
    expect(array_is_list($choices['payload']['choices'] ?? null))->toBeTrue();

    // La garde refuse à la construction, donc sous le verrou et avant tout
    // envoi, un modèle, un `toArray()` distrait et toute clé interne.
    $round = Round::query()->findOrFail($scene->scheduled->id);
    $image = WireFixtures::image($scene->game, $round, 1);
    $timeline = WireFixtures::timeline($scene->game, $round);

    $leaks = [
        'un modèle' => ['round' => $round, 'image' => $image],
        'un toArray()' => ['round' => $round->toArray(), 'image' => $image],
        'movie_id' => ['round' => [...$timeline, 'movie_id' => $round->movie_id], 'image' => $image],
        'roundId' => ['round' => [...$timeline, 'roundId' => $round->id], 'image' => $image],
        'frameLevel' => ['round' => $timeline, 'image' => [...$image, 'frameLevel' => 1]],
        'servedFrameId' => ['round' => $timeline, 'image' => [...$image, 'servedFrameId' => 3]],
        'serve_token' => ['round' => $timeline, 'image' => [...$image, 'serve_token' => 'x']],
        'un instant objet' => ['round' => [...$timeline, 'startsAt' => $round->started_at], 'image' => $image],
    ];

    foreach ($leaks as $label => $payload) {
        expect(fn () => new RoundScheduled($scene->room, $scene->game, $payload))
            ->toThrow(LogicException::class, message: "La garde laisse passer {$label}.");
    }

    foreach (['drawSeed', 'activeSeatToken', 'playerTokenHash', 'choice_1', 'id'] as $key) {
        expect(fn () => new GameEnded($scene->room, $scene->game, ['podium' => ['gameStatus' => 'completed', 'nested' => [$key => 'x']]]))
            ->toThrow(LogicException::class);
    }
});

it("aucune charge de manche ne porte l'appartenance d'un film à un thème, une saga ou une collection", function (): void {
    // Spec 30 § 13.3 (D43 du 01/10, critique C14) : une clé `saga.iron-man`
    // désigne presque un titre. Chaque film de la scène est rangé dans une
    // saga publiée et un thème manuel, avec des clés et un nom reconnaissables.
    $recorder = RecordingBroadcaster::install();
    $scene = WireFixtures::scene();

    $values = [];

    foreach (Round::query()->pluck('movie_id')->unique() as $index => $movieId) {
        $collection = Collection::factory()->named("Sentinel Saga {$index} Collection")->create();
        Movie::query()->whereKey($movieId)->update(['collection_id' => $collection->id]);
        $saga = Theme::factory()->saga($collection->id, "sentinel-saga-{$index}")->published()->create();
        $manual = Theme::factory()->withKey("manual.sentinel-{$index}")->published()->create();

        MovieTheme::factory()->auto()->create(['movie_id' => $movieId, 'theme_id' => $saga->id]);
        MovieTheme::factory()->manualAdded()->create(['movie_id' => $movieId, 'theme_id' => $manual->id]);

        array_push($values, $collection->name, $saga->key, $manual->key);
    }

    foreach (WireFixtures::events($scene) as $event) {
        event($event);
    }

    $forbidden = ['theme', 'themes', 'themeId', 'theme_id', 'collection', 'collections', 'collectionId', 'collection_id', 'saga'];

    foreach ($recorder->sent as $sent) {
        foreach (eventPayloadKeys($sent['payload']) as $key) {
            expect(in_array($key, $forbidden, true))->toBeFalse("[{$sent['event']}] porte la clé d'appartenance [{$key}].");
        }

        foreach ($values as $value) {
            expect($sent['json'])->not->toContain($value);
        }
    }
});

it("une transaction annulée n'émet aucun événement", function (): void {
    $recorder = RecordingBroadcaster::install();
    $scene = WireFixtures::scene();
    $events = WireFixtures::events($scene);

    try {
        DB::transaction(function () use ($events): void {
            foreach ($events as $event) {
                event($event);
            }

            throw new RuntimeException('Transition annulée.');
        });
    } catch (RuntimeException) {
        // Attendu.
    }

    expect($recorder->attempts)->toBe(0)
        ->and($recorder->sent)->toBe([]);

    // Un point de sauvegarde annulé n'émet rien, même quand la transaction
    // englobante est validée.
    DB::transaction(function () use ($events): void {
        try {
            DB::transaction(function () use ($events): void {
                event($events[RoundCancelled::class]);

                throw new RuntimeException('Étape annulée.');
            });
        } catch (RuntimeException) {
            // Attendu.
        }

        event($events[TierOpened::class]);
    });

    expect(array_column($recorder->sent, 'event'))->toBe(['tier.opened']);

    // Témoin : validée, la transaction émet — après son commit, jamais avant.
    DB::transaction(function () use ($events, $recorder): void {
        event($events[SeatChoicesOffered::class]);

        expect($recorder->attempts)->toBe(1);
    });

    expect(array_column($recorder->sent, 'event'))->toBe(['tier.opened', 'seat.choices']);
});

it('la liste des événements diffusés est exactement la liste close du J1', function (): void {
    $expected = eventPayloadClosedList();
    $found = [];
    $bases = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(['/', '.php'], ['\\', ''], Str::after(str_replace('\\', '/', $file->getPathname()), str_replace('\\', '/', app_path()).'/'));
        $class = 'App\\'.$relative;

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->implementsInterface(ShouldBroadcast::class)) {
            continue;
        }

        if ($reflection->isAbstract()) {
            $bases[] = $class;

            continue;
        }

        /** @var RoomBroadcast|SeatBroadcast $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        expect($reflection->isFinal())->toBeTrue("{$class} doit être final.")
            ->and(array_key_exists($instance->broadcastAs(), $found))->toBeFalse("Nom {$instance->broadcastAs()} en double.");

        $found[$instance->broadcastAs()] = [
            $class,
            match (true) {
                $instance instanceof RoomBroadcast => 'room',
                $instance instanceof SeatBroadcast => 'seat',
            },
            array_keys($class::FIELDS),
        ];
    }

    ksort($found);
    ksort($expected);

    // Vingt et un, ni plus ni moins : tout nouvel événement amende 60 § 11.3
    // et entre ici (D64 du 07/10 : demande de pause et son retrait).
    expect($found)->toBe($expected)
        ->and($found)->toHaveCount(21);

    // Seuls trois événements sont ciblés.
    expect(array_keys(array_filter($found, static fn (array $row): bool => $row[1] === 'seat')))
        ->toEqualCanonicalizing(['seat.choices', 'seat.superseded', 'seat.kicked']);

    // Miroir client (L60-9, écart (i) du § 22 bis) : l'union `GameEventName`
    // et les charges typées nomment exactement les vingt et un, dans l'ordre de
    // la liste close ; les écoutes d'Echo suivent le canal de chaque classe.
    $closedList = eventPayloadClosedList();
    $onChannel = static fn (string $channel): array => array_keys(array_filter(
        $closedList,
        static fn (array $row): bool => $row[1] === $channel,
    ));

    expect(eventPayloadTsUnion('js/types/game-wire.ts', 'GameEventName'))->toBe(array_keys($closedList))
        ->and(eventPayloadTsInterfaceKeys('js/types/game-wire.ts', 'GameEventPayloads'))->toBe(array_keys($closedList))
        ->and(eventPayloadTsArray('js/lib/game/echo.ts', 'ROOM_EVENTS'))->toBe($onChannel('room'))
        ->and(eventPayloadTsArray('js/lib/game/echo.ts', 'SEAT_EVENTS'))->toBe($onChannel('seat'));

    // Deux bases, et le transport de 60 § 11.2 sur chacune.
    sort($bases);

    expect($bases)->toBe([RoomBroadcast::class, SeatBroadcast::class]);

    foreach ($bases as $base) {
        $reflection = new ReflectionClass($base);

        expect($reflection->implementsInterface(ShouldBroadcastNow::class))->toBeTrue()
            ->and($reflection->implementsInterface(ShouldDispatchAfterCommit::class))->toBeTrue()
            ->and($reflection->implementsInterface(ShouldRescue::class))->toBeTrue()
            ->and($reflection->getMethod('broadcastWith')->isFinal())->toBeTrue()
            ->and($reflection->getMethod('__construct')->isFinal())->toBeTrue()
            ->and($reflection->getMethod('broadcastAs')->isAbstract())->toBeTrue();
    }
});

it("une diffusion en échec n'annule ni la transition ni la programmation du job suivant", function (): void {
    Exceptions::fake();
    $recorder = RecordingBroadcaster::install();
    $recorder->failing = true;
    RecordingJob::$handled = [];

    $scene = WireFixtures::scene();
    $events = WireFixtures::events($scene);
    $round = Round::query()->findOrFail($scene->scheduled->id);
    $startsAt = CarbonImmutable::parse('2026-09-23 14:05:03.000');

    // Une transition qui écrit, émet sur le salon et sur un siège, puis
    // programme le job de l'étape suivante après son commit : les rappels
    // après commit s'exécutent dans cet ordre, la diffusion AVANT le job.
    $outcome = DB::transaction(function () use ($round, $startsAt, $events): string {
        $round->started_at = $startsAt;
        $round->save();

        event($events[RoundScheduled::class]);
        event($events[SeatKicked::class]);

        RecordingJob::dispatch('advance-round')->afterCommit();

        return 'committed';
    });

    expect($outcome)->toBe('committed')
        // Les deux diffusions ont été tentées, et ont échoué…
        ->and($recorder->attempts)->toBe(2)
        ->and($recorder->sent)->toBe([])
        // …sans défaire l'écriture de la transition…
        ->and($round->fresh()?->started_at?->equalTo($startsAt))->toBeTrue()
        // …ni empêcher le job suivant de partir.
        ->and(RecordingJob::$handled)->toBe(['advance-round']);

    // L'échec est rapporté, jamais avalé en silence.
    Exceptions::assertReported(BroadcastException::class);
    Exceptions::assertReportedCount(2);
});

it('$round->toArray() ne contient aucune colonne cachée', function (): void {
    // Ajout (10, tableau de renvoi vers 60) : `$round->toArray()`,
    // `$roundTier->toArray()` et `$choiceSet->toArray()` ne contiennent aucune
    // colonne cachée, relations chargées comprises. `ModelSerializationTest`
    // en tient la liste modèle par modèle ; ici, sur la manche d'une scène de
    // jeu, QCM composé et jeton frappé — et la garde des charges refuse
    // malgré tout ce `toArray()`, qui porte encore `id`.
    $scene = WireFixtures::scene();

    RoundTier::query()->whereBelongsTo($scene->running)->each(function (RoundTier $tier): void {
        $tier->forceFill(['serve_token' => bin2hex(random_bytes(16)), 'served_frame_id' => $tier->frame_id])->save();
    });

    $round = Round::query()
        ->with(['movie', 'decoyMovie1', 'decoyMovie2', 'decoyMovie3', 'tiers.frame', 'tiers.servedFrame', 'choiceSets'])
        ->findOrFail($scene->running->id);

    $serialized = $round->toArray();
    $json = json_encode($serialized, JSON_THROW_ON_ERROR);

    foreach (['movie_id', 'decoy_movie_id_1', 'decoy_movie_id_2', 'decoy_movie_id_3', 'choices_use_original_title', 'movie', 'decoy_movie1', 'decoy_movie2', 'decoy_movie3', 'decoyMovie1'] as $hidden) {
        expect(array_key_exists($hidden, $serialized))->toBeFalse("round sérialise [{$hidden}].");
    }

    expect($serialized['tiers'])->toHaveCount($scene->game->frames_per_round);

    foreach ($serialized['tiers'] as $tier) {
        foreach (['frame_id', 'served_frame_id', 'frame_level', 'serve_token', 'frame', 'served_frame'] as $hidden) {
            expect(array_key_exists($hidden, $tier))->toBeFalse("round_tier sérialise [{$hidden}].");
        }
    }

    expect($serialized['choice_sets'])->not->toBeEmpty();

    foreach ($serialized['choice_sets'] as $set) {
        foreach (['choice_1', 'choice_2', 'choice_3', 'choice_4', 'rendered_locale'] as $hidden) {
            expect(array_key_exists($hidden, $set))->toBeFalse("round_choice_set sérialise [{$hidden}].");
        }
    }

    // Ni le titre du film, ni une chaîne du QCM, ni un jeton d'image.
    $set = RoundChoiceSet::query()->whereBelongsTo($round)->firstOrFail();

    expect($json)->not->toContain($round->movie->title_original)
        ->not->toContain($set->choice_1)
        ->not->toContain($set->choice_2);

    foreach (RoundTier::query()->whereBelongsTo($round)->pluck('serve_token') as $token) {
        expect($json)->not->toContain((string) $token);
    }

    expect(fn () => new RoundScheduled($scene->room, $scene->game, [
        'round' => $serialized,
        'image' => WireFixtures::image($scene->game, $round, 1),
    ]))->toThrow(LogicException::class, "clé d'identifiant interne [round.id]");
});

it('les bases refusent une partie solo, un siège sans salon et une charge hors de sa liste close', function (): void {
    // Ajout : la garde de mode de 60 § 11.2 et la liste close des champs
    // (§ 11.3) tiennent dans les bases, qu'aucun événement ne contourne. Rien
    // n'est émis pour une construction refusée.
    $recorder = RecordingBroadcaster::install();

    $scene = WireFixtures::scene();
    $solo = Game::factory()->solo()->create();
    $soloSeat = Player::factory()->solo()->create();
    $otherRoom = Room::factory()->create();
    $otherSeat = Player::factory()->create(['room_id' => $otherRoom->id]);
    $cancelled = ['sequenceIndex' => 2, 'roundNumber' => 2];

    $qcm = ['sequenceIndex' => 1, 'choices' => ['a', 'b', 'c', 'd'], 'useOriginalTitle' => false, 'lang' => 'en'];
    $launched = ['mode' => 'multiplayer', 'roundsCount' => 10, 'framesPerRound' => 3, 'inputDifficulty' => 'normal', 'revealDurationMs' => 8000, 'speedBonus' => true];

    // Chaque refus pour SA raison : le message nomme la garde qui a levé.
    $refusals = [
        'partie solo au salon' => [fn () => new RoundCancelled($scene->room, $solo, $cancelled), 'partie solo'],
        'partie solo au siège' => [fn () => new SeatSuperseded($scene->guest, $solo, []), 'partie solo'],
        'siège sans salon' => [fn () => new SeatKicked($soloSeat, null, []), 'siège sans salon'],
        'siège sans salon, QCM' => [fn () => new SeatChoicesOffered($soloSeat, $solo, $qcm), 'siège sans salon'],
        "partie d'un autre salon" => [fn () => new RoundCancelled($otherRoom, $scene->game, $cancelled), "n'appartient pas"],
        "siège d'un autre salon" => [fn () => new SeatKicked($otherSeat, $scene->game, []), "n'appartient pas"],
        'événement de partie sans partie' => [fn () => new PlayerLocked($scene->room, null, ['sequenceIndex' => 1, 'publicId' => $scene->host->public_id, 'lockRank' => 1]), 'sans partie'],
        'QCM sans partie' => [fn () => new SeatChoicesOffered($scene->guest, null, $qcm), 'sans partie'],
        'champ manquant' => [fn () => new RoundCancelled($scene->room, $scene->game, ['sequenceIndex' => 2]), 'manquants [roundNumber]'],
        'champ en trop' => [fn () => new PlayerLocked($scene->room, $scene->game, ['sequenceIndex' => 1, 'publicId' => $scene->host->public_id, 'lockRank' => 1, 'points' => 300]), 'en trop [points]'],
        'charge pour un événement vide' => [fn () => new RoomArchived($scene->room, null, ['reason' => 'x']), 'en trop [reason]'],
        'entier attendu' => [fn () => new RoundCancelled($scene->room, $scene->game, ['sequenceIndex' => '2', 'roundNumber' => 2]), '[sequenceIndex] doit être de nature [int]'],
        'IsoMs attendu' => [fn () => new GameResumed($scene->room, $scene->game, ['resumedAt' => '2026-09-23 14:05:13']), '[resumedAt] doit être de nature [iso]'],
        'IsoMs sans millisecondes' => [fn () => new GameResumed($scene->room, $scene->game, ['resumedAt' => '2026-09-23T14:05:13Z']), '[resumedAt] doit être de nature [iso]'],
        "IsoMs suivi d'un saut de ligne" => [fn () => new GameResumed($scene->room, $scene->game, ['resumedAt' => "2026-09-23T14:05:13.004Z\n"]), '[resumedAt] doit être de nature [iso]'],
        'objet attendu, liste reçue' => [fn () => new GameEnded($scene->room, $scene->game, ['podium' => ['a', 'b']]), '[podium] doit être de nature [object]'],
        'objet attendu, vide reçu' => [fn () => new GameEnded($scene->room, $scene->game, ['podium' => []]), '[podium] doit être de nature [object]'],
        'liste attendue' => [fn () => new GameLaunched($scene->room, $scene->game, [...$launched, 'seats' => ['x' => []]]), '[seats] doit être de nature [list]'],
        'non nullable' => [fn () => new HostChanged($scene->room, null, ['hostPublicId' => null, 'previousHostPublicId' => null]), '[hostPublicId] doit être de nature [string]'],
    ];

    foreach ($refusals as $label => [$construct, $reason]) {
        expect($construct)->toThrow(LogicException::class, $reason, "Accepté à tort, ou refusé pour une autre raison : {$label}.");
    }

    // Par le raccourci `dispatch()` aussi : la construction lève avant l'envoi.
    expect(fn () => SeatKicked::dispatch($soloSeat, null, []))->toThrow(LogicException::class)
        ->and($recorder->attempts)->toBe(0);

    // Témoins : nullable admis, champs remis dans l'ordre déclaré.
    $locked = new PlayerLocked($scene->room, $scene->game, ['lockRank' => 1, 'publicId' => $scene->host->public_id, 'sequenceIndex' => 1]);
    $changed = new HostChanged($scene->room, null, ['hostPublicId' => $scene->host->public_id, 'previousHostPublicId' => null]);

    expect(array_keys($locked->payload()))->toBe(['sequenceIndex', 'publicId', 'lockRank'])
        ->and($changed->payload())->toBe(['hostPublicId' => $scene->host->public_id, 'previousHostPublicId' => null]);
});

/**
 * Une partie multijoueur au siège présent, sa manche 1 programmée puis
 * ouverte jusqu'à son palier `$openTiers` (le dernier par défaut), par les
 * transitions réelles (L60-6). `$movie` prépare le film de la manche avant la
 * programmation.
 *
 * @param  (Closure(Movie): void)|null  $movie
 * @return array{Game, Round}
 */
function eventPayloadOpenedRound(?Closure $movie = null, ?int $openTiers = null): array
{
    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game);
    $movies = EngineFixtures::materialize($game);

    if ($movie instanceof Closure) {
        $movie($movies[0]);
    }

    $round = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($round, Date::now()->toImmutable()->addMilliseconds(5_381));

    foreach (range(1, $openTiers ?? $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($round, $tierIndex);
    }

    return [$game, $round->refresh()];
}

/**
 * Les chaînes qui désignent le film, telles que le fil les écrirait (JSON,
 * Unicode échappé) : titre original, translittération, titres de toute
 * locale et alias.
 *
 * @return list<string>
 */
function eventPayloadTitleStrings(Movie $movie): array
{
    $strings = [$movie->title_original, $movie->title_original_latin];

    foreach (MovieTitle::query()->where('movie_id', $movie->id)->pluck('title') as $title) {
        $strings[] = $title;
    }

    foreach (Alias::query()->where('movie_id', $movie->id)->pluck('alias') as $alias) {
        $strings[] = $alias;
    }

    return array_values(array_map(
        static fn (string $title): string => substr(json_encode($title, JSON_THROW_ON_ERROR), 1, -1),
        array_filter($strings, static fn (mixed $title): bool => is_string($title) && $title !== ''),
    ));
}

it('la clôture est émise à ended_at et les titres à ended_at + tier_grace_ms', function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class]);
    Date::setTestNow(CarbonImmutable::parse('2026-09-26 14:00:00.250'));
    $recorder = RecordingBroadcaster::install();

    // À `D` comme en fin anticipée : `round.closed` part à l'instant de
    // clôture écrit, `round.revealed` à `ended_at + tier_grace_ms`, jamais
    // avant, et rien de ce qui précède ne nomme le film.
    foreach (['à D' => null, 'fin anticipée' => 3_417] as $label => $afterT1) {
        $recorder->sent = [];
        [$game, $round] = eventPayloadOpenedRound(openTiers: $afterT1 === null ? null : 1);
        $movie = Movie::query()->findOrFail($round->movie_id);
        $titles = eventPayloadTitleStrings($movie);

        $endedAt = $afterT1 === null
            ? EngineFixtures::durationEnd($round)
            : EngineFixtures::opensAt($round, 1)->addMilliseconds($afterT1);
        $revealStartsAt = $endedAt->addMilliseconds($game->tier_grace_ms);
        $revealEndsAt = $revealStartsAt->addSeconds($game->settings_snapshot->revealDuration);

        EngineFixtures::close($round, $endedAt);

        $closed = collect($recorder->sent)->last();

        expect($closed['event'] ?? null)->toBe('round.closed', $label)
            ->and($closed['payload'])->toMatchArray([
                'serverNow' => WireTime::iso($endedAt),
                'sequenceIndex' => 1,
                'roundNumber' => 1,
                'endedAt' => WireTime::iso($endedAt),
                'revealStartsAt' => WireTime::iso($revealStartsAt),
                'revealEndsAt' => WireTime::iso($revealEndsAt),
            ])
            // Close, la manche reste `running` : la grâce finale court.
            ->and($round->refresh()->status)->toBe(RoundStatus::Running, $label);

        // Une milliseconde avant `ended_at + tier_grace_ms` : aucun titre.
        EngineFixtures::reveal($round, $revealStartsAt->subMillisecond());

        expect(collect($recorder->sent)->last()['event'] ?? null)->toBe('round.closed', $label)
            ->and($round->refresh()->status)->toBe(RoundStatus::Running, $label);

        EngineFixtures::reveal($round);

        // Les titres, puis la programmation de la manche suivante (§ 9.4).
        expect(array_slice(array_column($recorder->sent, 'event'), -2))->toBe(['round.revealed', 'round.scheduled'], $label);

        $revealed = collect($recorder->sent)->firstWhere('event', 'round.revealed');

        expect($revealed['event'] ?? null)->toBe('round.revealed', $label)
            ->and($revealed['payload']['serverNow'])->toBe(WireTime::iso($revealStartsAt), $label)
            ->and($revealed['payload']['revealEndsAt'])->toBe(WireTime::iso($revealEndsAt), $label)
            ->and($revealed['payload']['movie']['originalTitle'])->toBe($movie->title_original, $label)
            ->and($round->refresh()->status)->toBe(RoundStatus::Revealing, $label);

        // Tout ce qui est parti avant `revealStartsAt` — programmation,
        // ouvertures, clôture — ne porte aucun titre du film.
        $before = array_values(array_filter(
            $recorder->sent,
            static fn (array $sent): bool => $sent['payload']['serverNow'] < WireTime::iso($revealStartsAt),
        ));

        expect(array_column($before, 'event'))->toContain('round.closed');

        foreach ($before as $sent) {
            foreach ($titles as $title) {
                expect($sent['json'])->not->toContain($title, "[{$label}] {$sent['event']} porte un titre.");
            }
        }

        // La fin anticipée n'a ouvert que le palier 1 : la révélation n'en
        // remontre pas d'autre (D14 du 23/09).
        expect($revealed['payload']['images'])->toHaveCount($afterT1 === null ? $game->frames_per_round : 1, $label)
            ->and(array_column($revealed['payload']['images'], 'tierIndex'))
            ->toBe($afterT1 === null ? range(1, $game->frames_per_round) : [1], $label);
    }
});

it("chaque titre de la révélation porte l'attribut lang de la locale atteinte", function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class]);
    Date::setTestNow(CarbonImmutable::parse('2026-09-26 14:00:00.250'));
    $recorder = RecordingBroadcaster::install();

    /**
     * Le film de la manche, remis à un état de titres donné.
     *
     * @param  array<string, string>  $titles  locale de catalogue → titre
     */
    $movieWith = static function (array $titles, string $original, ?string $latin, string $language): Closure {
        return static function (Movie $movie) use ($titles, $original, $latin, $language): void {
            MovieTitle::query()->where('movie_id', $movie->id)->delete();

            foreach ($titles as $locale => $title) {
                MovieTitle::factory()->for($movie)->forLocale($locale)->titled($title)->create();
            }

            $movie->forceFill([
                'title_original' => $original,
                'title_original_latin' => $latin,
                'original_language' => $language,
            ])->save();
        };
    };

    $spirited = '千と千尋の神隠し';
    $latin = 'Sen to Chihiro no Kamikakushi';

    // Scénario → [film, titres attendus par locale d'interface : texte, lang].
    $scenarios = [
        'les deux locales' => [
            $movieWith(['en' => 'Spirited Away', 'fr' => 'Le Voyage de Chihiro'], $spirited, $latin, 'ja'),
            ['en' => ['Spirited Away', 'en'], 'fr' => ['Le Voyage de Chihiro', 'fr']],
        ],
        'une seule locale activée' => [
            $movieWith(['fr' => 'Le Voyage de Chihiro', 'ja' => $spirited], $spirited, $latin, 'ja'),
            ['en' => ['Le Voyage de Chihiro', 'fr'], 'fr' => ['Le Voyage de Chihiro', 'fr']],
        ],
        'titre original translittéré' => [
            $movieWith(['ja' => $spirited], $spirited, $latin, 'ja'),
            ['en' => [$latin, 'ja-Latn'], 'fr' => [$latin, 'ja-Latn']],
        ],
        'titre original sans translittération' => [
            $movieWith([], $spirited, null, 'ja'),
            ['en' => [$spirited, 'ja'], 'fr' => [$spirited, 'ja']],
        ],
        'titre original latin' => [
            $movieWith([], 'Amélie', 'Amelie', 'fr'),
            ['en' => ['Amélie', 'fr'], 'fr' => ['Amélie', 'fr']],
        ],
    ];

    foreach ($scenarios as $label => [$prepare, $expected]) {
        [, $round] = eventPayloadOpenedRound($prepare);
        $movie = Movie::query()->findOrFail($round->movie_id);

        EngineFixtures::close($round);
        $recorder->sent = [];
        EngineFixtures::reveal($round);

        $revealed = collect($recorder->sent)->firstWhere('event', 'round.revealed');
        $packet = $revealed['payload']['movie'] ?? null;

        expect($packet)->toBe([
            'titles' => array_map(
                static fn (array $title): array => ['text' => $title[0], 'lang' => $title[1]],
                $expected,
            ),
            'originalTitle' => $movie->title_original,
            'originalTitleLatin' => $movie->title_original_latin,
            'originalLanguage' => $movie->original_language,
            'year' => $movie->release_year,
            'letterboxdUrl' => "https://letterboxd.com/tmdb/{$movie->tmdb_id}/",
            'tmdb' => $movie->tmdb_id,
        ], $label)
            // Une entrée par locale activée, dans l'ordre du registre.
            ->and(array_keys($packet['titles'] ?? []))->toBe(array_map(static fn (Locale $locale): string => $locale->value, Locale::cases()), $label);
    }
});

it('round.scheduled est réémis pour une manche pending reprogrammée et jamais après T₁', function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class]);
    Date::setTestNow(CarbonImmutable::parse('2026-09-26 14:00:00.250'));
    $recorder = RecordingBroadcaster::install();

    [$game, $first] = eventPayloadOpenedRound();
    $second = EngineFixtures::round($game, 2);
    $host = static fn (Room $room): bool => true;

    /** @return list<array<string, mixed>> les `round.scheduled` de la manche 2, dans l'ordre */
    $scheduledSecond = static fn (): array => array_values(array_filter(
        $recorder->sent,
        static fn (array $sent): bool => $sent['event'] === 'round.scheduled' && $sent['payload']['round']['sequenceIndex'] === 2,
    ));

    // La révélation de la manche 1 programme la manche 2 à sa fin.
    EngineFixtures::close($first);
    EngineFixtures::reveal($first);

    $plannedT1 = $first->refresh()->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');

    expect($scheduledSecond())->toHaveCount(1)
        ->and($scheduledSecond()[0]['payload']['round']['startsAt'])->toBe(WireTime::iso($plannedT1));

    // « Manche suivante » pendant la révélation : la manche 2, encore
    // `pending`, est reprogrammée plus tôt, et `round.scheduled` réémis pour
    // la même manche, au `serverNow` plus grand, qui l'emporte (C7 § 4.2).
    $endedAt = $first->ended_at ?? throw new LogicException('Manche 1 non close.');
    $gestureAt = $endedAt->addMilliseconds($game->tier_grace_ms + 1_000);
    $newT1 = $gestureAt->addMilliseconds($game->preload_lead_ms + EngineConstants::nextRoundMarginMs());
    Date::setTestNow($gestureAt);

    expect(app(AdvanceToNextRound::class)->handle($game, $gestureAt, $host))->toBe(NextRoundOutcome::Advanced);

    [$initial, $reissued] = $scheduledSecond();

    expect($reissued['payload']['round'])->toMatchArray(['sequenceIndex' => 2, 'roundNumber' => 2, 'startsAt' => WireTime::iso($newT1)])
        ->and($reissued['payload']['image'])->toMatchArray(['tierIndex' => 1, 'fetchNotBefore' => WireTime::iso($newT1->subMilliseconds($game->preload_lead_ms))])
        ->and($reissued['payload']['serverNow'] > $initial['payload']['serverNow'])->toBeTrue()
        ->and($first->refresh()->reveal_ends_at?->equalTo($newT1))->toBeTrue()
        ->and($second->refresh()->status)->toBe(RoundStatus::Pending)
        ->and($second->started_at?->equalTo($newT1))->toBeTrue();

    // Un geste qui ne raccourcirait rien ne réémet rien.
    expect(app(AdvanceToNextRound::class)->handle($game, $gestureAt, $host))->toBe(NextRoundOutcome::Unchanged)
        ->and($scheduledSecond())->toHaveCount(2);

    // T₁ : la fin de révélation de la manche 1, puis l'ouverture de la
    // manche 2 — `tier.opened`, jamais un `round.scheduled`.
    Date::setTestNow($newT1);
    app(CatchUpGame::class)->handle($game, $newT1);

    expect($second->refresh()->status)->toBe(RoundStatus::Running)
        ->and(collect($recorder->sent)->last()['event'] ?? null)->toBe('tier.opened')
        ->and($scheduledSecond())->toHaveCount(2);

    // Après T₁, plus rien ne la reprogramme : le geste est hors révélation,
    // l'origine est immuable, et les rattrapages suivants n'émettent que ses
    // paliers.
    $after = $newT1->addMilliseconds(500);
    Date::setTestNow($after);

    expect(app(AdvanceToNextRound::class)->handle($game, $after, $host))->toBe(NextRoundOutcome::NotRevealing)
        ->and(static fn () => app(ScheduleRound::class)->handle($second, $after->addSeconds(10)))->toThrow(LogicException::class);

    $secondT2 = EngineFixtures::opensAt($second, 2);
    Date::setTestNow($secondT2);
    app(CatchUpGame::class)->handle($game, $secondT2);

    expect($scheduledSecond())->toHaveCount(2)
        ->and($second->refresh()->started_at?->equalTo($newT1))->toBeTrue();

    // Aucun `round.scheduled` n'est jamais parti après le T₁ qu'il annonce.
    foreach ($recorder->sent as $sent) {
        if ($sent['event'] === 'round.scheduled') {
            expect($sent['payload']['serverNow'] < $sent['payload']['round']['startsAt'])->toBeTrue();
        }
    }
});

it('un passage de rattrapage ne libère que l\'état courant de chaque manche et jamais un événement annulé', function (): void {
    // Ajout (§ 4.4) : la règle de péremption de `TransitionBroadcasts`,
    // éprouvée sur des charges réelles de la liste close, `seat.choices`
    // compris (émis par `OpenTier` depuis L60-11).
    $recorder = RecordingBroadcaster::install();
    $scene = WireFixtures::scene();
    $events = WireFixtures::events($scene);
    $game = $scene->game;
    $room = $scene->room;
    $running = $scene->running;

    $laterTier = new TierOpened($room, $game, [
        'sequenceIndex' => $running->sequence_index,
        'roundNumber' => (int) $running->round_number,
        'tierIndex' => 2,
        'opensAt' => WireTime::iso(CarbonImmutable::parse('2026-09-23 14:05:13.000')),
        'next' => null,
        'choicesUnavailable' => false,
    ]);

    app(TransitionBroadcasts::class)->coalesce(static function () use ($events, $laterTier): void {
        // Manche courante : palier 1, son QCM, puis le palier 2 qui les
        // rend tous deux périmés.
        event($events[TierOpened::class]);
        event($events[SeatChoicesOffered::class]);
        event($events[PlayerLocked::class]);
        event($laterTier);
        // Manche révélée : sa clôture, puis ses titres.
        event($events[RoundClosed::class]);
        event($events[RoundRevealed::class]);
        // Manche programmée, puis annulée : l'annulation ne se périme jamais
        // et rend la programmation périmée.
        event($events[RoundScheduled::class]);
        event($events[RoundCancelled::class]);
        event($events[GamePaused::class]);

        // Une transaction annulée ne confie rien au passage.
        try {
            DB::transaction(static function () use ($events): void {
                event($events[GameResumed::class]);

                throw new RuntimeException('transition annulée');
            });
        } catch (RuntimeException) {
            // La transition a échoué : rien à émettre.
        }
    });

    expect(array_column($recorder->sent, 'event'))->toBe([
        'player.locked',
        'tier.opened',
        'round.revealed',
        'round.cancelled',
        'game.paused',
    ])
        ->and($recorder->sent[1]['payload']['tierIndex'])->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Émetteurs de L60-11 : QCM ciblé, `player.locked`, `game.ended`
|--------------------------------------------------------------------------
|
| Parties réelles (`SubmissionFixtures`) : sièges tenus par un `player_token`,
| soumissions et clics PAR LA ROUTE, manches ouvertes par les transitions
| réelles, crochets de fin de saisie déclenchés par les vrais événements de
| domaine de 70 (`AnswerAccepted`, `InputClosed`), gel par l'action réelle.
| Le diffuseur enregistreur voit ce qui partirait vers Reverb.
|
*/

/**
 * Le décor commun des tests de L60-11 : disque simulé, jobs du moteur
 * retenus, cookies isolés, horloge figée, diffuseur enregistreur.
 */
function eventPayloadQcmSetUp(): RecordingBroadcaster
{
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();
    Date::setTestNow(CarbonImmutable::parse('2026-09-27 16:00:00.250'));

    return RecordingBroadcaster::install();
}

/**
 * Une partie multijoueur à cette difficulté, un siège par locale donnée
 * (colonne `player.locale`, que la composition fige), manche 1 programmée,
 * et ouverte à son palier 1 par `OpenTier` sauf `$open = false`.
 *
 * @param  list<Locale>  $locales
 * @return array{game: Game, round: Round, seats: list<Player>, tokens: list<PlayerToken>}
 */
function eventPayloadQcmRound(InputDifficulty $difficulty, array $locales, ?Movie $target = null, bool $open = true): array
{
    $tokens = array_map(static fn (Locale $locale): PlayerToken => PlayerToken::mint($locale), $locales);
    [$game, $round, $seats] = SubmissionFixtures::openedRound($tokens, SubmissionFixtures::settings($difficulty), $target, open: false);

    foreach ($seats as $index => $seat) {
        $seat->forceFill(['locale' => $locales[$index]])->save();
    }

    if ($open) {
        EngineFixtures::openTier($round, 1);
    }

    return ['game' => $game, 'round' => $round->refresh(), 'seats' => $seats, 'tokens' => $tokens];
}

/**
 * Les `seat.choices` enregistrés, indexés par nom de canal Pusher.
 *
 * @param  list<array{channels: list<string>, event: string, payload: array<string, mixed>, json: string}>  $sent
 * @return list<array{channels: list<string>, event: string, payload: array<string, mixed>, json: string}>
 */
function eventPayloadChoicesSent(array $sent): array
{
    return array_values(array_filter($sent, static fn (array $row): bool => $row['event'] === 'seat.choices'));
}

/** Le canal Pusher privé d'un siège. */
function eventPayloadSeatChannel(Player $seat): string
{
    return 'private-'.ChannelNames::seat($seat);
}

/**
 * Un film de vivier au titre, au titre français et à l'alias imposés — clés
 * `answer_key` projetées par le vrai projecteur —, une variante publiée par
 * niveau nominal du `N` par défaut.
 */
function eventPayloadAliasedMovie(string $title, string $frenchTitle, string $alias): Movie
{
    $levels = FrameLevelCoverage::nominal(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);

    $movie = Movie::factory()
        ->playable(
            titles: [Locale::English->value => $title, Locale::French->value => $frenchTitle],
            aliases: [Locale::French->value => [$alias]],
        )
        ->state(['title_original' => $title, 'title_original_latin' => null])
        ->has(
            Frame::factory()
                ->count(count($levels))
                ->published()
                ->sequence(...array_map(static fn ($level): array => ['frame_level' => $level], $levels)),
            'frames',
        )
        ->create();

    MovieFactory::recomputeProjection($movie);

    return $movie->refresh();
}

it('aucune charge émise avant revealStartsAt ne contient un titre, un alias ou une forme normalisée du film de la manche hors des quatre propositions du QCM', function (): void {
    $recorder = eventPayloadQcmSetUp();

    $target = eventPayloadAliasedMovie('Harbour Lights', 'Les Feux du port', 'Port des lumières');
    SubmissionFixtures::decoyCandidates();
    ['game' => $game, 'round' => $round, 'seats' => $seats, 'tokens' => $tokens] = eventPayloadQcmRound(
        InputDifficulty::Normal,
        [Locale::French, Locale::English, Locale::French],
        $target,
    );
    [$finder, $clicker, $idle] = $seats;
    $cadence = SubmissionFixtures::cadenceMs($game);
    $framesPerRound = $game->frames_per_round;

    // Tout ce que le fil pourrait laisser échapper : titres de chaque locale,
    // titre original, alias, et toute forme normalisée projetée.
    $forbidden = eventPayloadTitleStrings($target);

    foreach (AnswerKey::query()->where('movie_id', $target->id)->pluck('normalized') as $normalized) {
        $forbidden[] = $normalized;
    }

    $forbidden = array_values(array_unique($forbidden));

    // Les chaînes du fil sont échappées comme le JSON les écrit.
    expect($forbidden)->toContain('Harbour Lights', 'Les Feux du port', substr(json_encode('Port des lumières', JSON_THROW_ON_ERROR), 1, -1), 'harbour lights');

    // Une manche entière : programmation et palier 1 (déjà enregistrés), un
    // siège qui trouve par l'alias (`player.locked`), les paliers suivants,
    // le QCM ciblé à `T_N`, un clic faux, la clôture à `D`, puis la révélation.
    SubmissionFixtures::submit($this, $finder, $tokens[0], 'Port des lumières', EngineFixtures::opensAt($round, 1)->addMilliseconds($cadence))
        ->assertOk()
        ->assertJson(['result' => 'accepted']);

    $tN = EngineFixtures::opensAt($round, $framesPerRound);
    Date::setTestNow($tN);
    app(CatchUpGame::class)->handle($game, $tN);

    SubmissionFixtures::click($this, $clicker, $tokens[1], SubmissionFixtures::wrongChoice($round, $clicker), $tN->addMilliseconds($cadence))
        ->assertOk()
        ->assertJson(['result' => 'rejected', 'inputState' => RoundPlayerInputState::QcmWrong->value]);

    $endedAt = EngineFixtures::durationEnd($round);
    Date::setTestNow($endedAt);
    app(CatchUpGame::class)->handle($game, $endedAt);

    $revealStartsAt = $endedAt->addMilliseconds($game->tier_grace_ms);
    Date::setTestNow($revealStartsAt);
    app(CatchUpGame::class)->handle($game, $revealStartsAt);

    $before = array_values(array_filter(
        $recorder->sent,
        static fn (array $sent): bool => $sent['payload']['serverNow'] < WireTime::iso($revealStartsAt),
    ));

    // La scène couvre tous les émetteurs d'une manche avant ses titres.
    expect(array_values(array_unique(array_column($before, 'event'))))
        ->toBe(['round.scheduled', 'tier.opened', 'player.locked', 'seat.choices', 'round.closed']);

    $pushedTarget = 0;

    foreach ($before as $sent) {
        $payload = $sent['payload'];

        // Les quatre propositions, et elles seules, peuvent nommer le film :
        // la bonne y figure bien, et rien d'autre de la charge ne le nomme.
        if ($sent['event'] === 'seat.choices') {
            $seat = collect($seats)->first(static fn (Player $candidate): bool => $sent['channels'] === [eventPayloadSeatChannel($candidate)]);
            expect($seat)->toBeInstanceOf(Player::class);

            expect($payload['choices'])->toContain(SubmissionFixtures::correctChoice($round, $seat));
            $pushedTarget++;
            unset($payload['choices']);
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach ($forbidden as $string) {
            expect(str_contains($json, $string))->toBeFalse("[{$sent['event']}] nomme le film avant revealStartsAt : [{$string}].");
        }
    }

    // Témoins : le QCM a bien été poussé aux deux sièges dont la saisie
    // accepte un clic (le trouveur est verrouillé), et les titres partent à
    // `revealStartsAt`, jamais avant.
    expect($pushedTarget)->toBe(2);

    $revealed = collect($recorder->sent)->firstWhere('event', 'round.revealed');

    expect($revealed['payload']['serverNow'] ?? null)->toBe(WireTime::iso($revealStartsAt))
        ->and($revealed['json'] ?? '')->toContain('Harbour Lights')
        ->and(SubmissionFixtures::participation($round, $idle)->input_state)->toBe(RoundPlayerInputState::Open);
});

it('la position de la cible parmi les quatre propositions suit la permutation qcmOrder du siège', function (): void {
    $recorder = eventPayloadQcmSetUp();

    SubmissionFixtures::decoyCandidates();
    $locales = [Locale::French, Locale::English, Locale::French, Locale::English, Locale::French];
    ['game' => $game, 'round' => $round, 'seats' => $seats] = eventPayloadQcmRound(InputDifficulty::Easy, $locales);

    $pushed = eventPayloadChoicesSent($recorder->sent);
    $prf = SeededPrf::forGame($game);
    $reordered = 0;

    expect($pushed)->toHaveCount(count($seats));

    foreach ($seats as $seat) {
        $set = SubmissionFixtures::choiceSet($round, $seat);
        $stored = [$set->choice_1, $set->choice_2, $set->choice_3, $set->choice_4];

        // La permutation du siège, recalculée hors du présentateur, sur le
        // seul contexte `draw:qcm:{sequenceIndex}:{publicId}`.
        $order = $prf->permutation(DrawContext::qcmOrder($round->sequence_index, $seat->public_id), ChoicesPayload::COUNT);
        $received = collect($pushed)->first(static fn (array $sent): bool => $sent['channels'] === [eventPayloadSeatChannel($seat)]);

        expect($received)->not->toBeNull()
            ->and($set->locale)->toBe($seat->locale)
            ->and($received['payload']['choices'])->toBe(array_map(static fn (int $index): string => $stored[$index], $order))
            // La cible, `choice_1`, à la position que la permutation lui donne.
            ->and(array_search($stored[0], $received['payload']['choices'], true))->toBe(array_search(0, $order, true))
            // Ni index, ni drapeau, ni métadonnée par proposition.
            ->and(array_slice(array_keys($received['payload']), 3))->toBe(['sequenceIndex', 'choices', 'useOriginalTitle', 'lang'])
            ->and(array_is_list($received['payload']['choices']))->toBeTrue();

        if ($received['payload']['choices'] !== $stored) {
            $reordered++;
        }
    }

    // Aucune position conventionnelle : l'ordre stocké n'est pas celui du fil.
    expect($reordered)->toBeGreaterThan(0);
});

it('player.locked ne porte que sequenceIndex, publicId et lockRank', function (): void {
    $recorder = eventPayloadQcmSetUp();

    $target = SubmissionFixtures::movie('Harbour Lights');
    ['game' => $game, 'round' => $round, 'seats' => [$first, $second, $spent], 'tokens' => $tokens] = eventPayloadQcmRound(
        InputDifficulty::Expert,
        [Locale::French, Locale::English, Locale::French],
        $target,
    );
    $cadence = SubmissionFixtures::cadenceMs($game);
    $t1 = EngineFixtures::opensAt($round, 1);
    $room = Room::query()->findOrFail($game->room_id);

    foreach ([[$first, $tokens[0], 1], [$second, $tokens[1], 2]] as [$seat, $token, $rank]) {
        $recorder->sent = [];
        $receivedAt = $t1->addMilliseconds($rank * $cadence);

        $response = SubmissionFixtures::submit($this, $seat, $token, 'Harbour Lights', $receivedAt)
            ->assertOk()
            ->assertJson(['result' => 'accepted', 'lockRank' => $rank]);

        expect($recorder->sent)->toHaveCount(1);

        $locked = $recorder->sent[0];

        // Au salon, l'enveloppe puis trois champs, rien d'autre : ni points,
        // ni palier, ni chaîne — le verrouillé les lit dans SA réponse.
        expect($locked['event'])->toBe('player.locked')
            ->and($locked['channels'])->toBe(['presence-'.ChannelNames::room($room)])
            ->and(array_keys($locked['payload']))->toBe(['v', 'serverNow', 'gameRef', 'sequenceIndex', 'publicId', 'lockRank'])
            ->and($locked['payload'])->toMatchArray([
                'serverNow' => WireTime::iso($receivedAt),
                'gameRef' => GameRef::for($game),
                'sequenceIndex' => $round->sequence_index,
                'publicId' => $seat->public_id,
                'lockRank' => $rank,
            ])
            ->and($locked['json'])->not->toContain('Harbour')
            ->and($locked['json'])->not->toContain('points')
            ->and($locked['json'])->not->toContain('tierIndex')
            ->and($response->json('tierIndex'))->toBeInt()
            ->and($response->json('pointsTotal'))->toBeInt();
    }

    // Une saisie close sans bonne réponse n'est jamais annoncée : la
    // dernière saisie ouverte s'épuise, et seule la clôture part.
    $recorder->sent = [];
    SubmissionFixtures::spend($round, $spent, $game->settings_snapshot->attemptsPerRound - 1);
    $exhaustedAt = $t1->addMilliseconds(3 * $cadence);

    SubmissionFixtures::submit($this, $spent, $tokens[2], SubmissionFixtures::WRONG, $exhaustedAt)
        ->assertOk()
        ->assertJson(['result' => 'rejected', 'inputState' => RoundPlayerInputState::AttemptsExhausted->value]);

    expect(array_column($recorder->sent, 'event'))->toBe(['round.closed'])
        ->and($recorder->sent[0]['payload']['endedAt'])->toBe(WireTime::iso($exhaustedAt));
});

it('seat.choices part sur le canal privé du siège et jamais sur le canal du salon', function (): void {
    $recorder = eventPayloadQcmSetUp();

    SubmissionFixtures::decoyCandidates();
    ['round' => $round, 'seats' => $seats] = eventPayloadQcmRound(InputDifficulty::Easy, [Locale::French, Locale::English, Locale::French]);

    $pushed = eventPayloadChoicesSent($recorder->sent);

    // Un envoi par siège, chacun sur SON canal privé, jamais deux fois.
    expect(array_map(static fn (array $sent): array => $sent['channels'], $pushed))
        ->toEqualCanonicalizing(array_map(static fn (Player $seat): array => [eventPayloadSeatChannel($seat)], $seats));

    foreach ($pushed as $sent) {
        expect($sent['channels'])->toHaveCount(1)
            ->and($sent['channels'][0])->toStartWith('private-seat.');
    }

    // Aucune diffusion au salon ne porte le QCM ni l'une de ses chaînes, dans
    // aucune locale.
    $strings = RoundChoiceSet::query()
        ->where('round_id', $round->id)
        ->get()
        ->flatMap(static fn (RoundChoiceSet $set): array => [$set->choice_1, $set->choice_2, $set->choice_3, $set->choice_4])
        ->map(static fn (string $choice): string => substr(json_encode($choice, JSON_THROW_ON_ERROR), 1, -1))
        ->all();

    expect($strings)->toHaveCount(count(Locale::cases()) * ChoicesPayload::COUNT);

    $onRoom = array_values(array_filter(
        $recorder->sent,
        static fn (array $sent): bool => str_starts_with($sent['channels'][0], 'presence-'),
    ));

    expect(array_column($onRoom, 'event'))->toBe(['round.scheduled', 'tier.opened']);

    foreach ($onRoom as $sent) {
        foreach ($strings as $string) {
            expect(str_contains($sent['json'], $string))->toBeFalse("[{$sent['event']}] porte une proposition du QCM.");
        }
    }
});

it('en Facile le QCM est poussé à T₁, en Normal à T_N, en Expert jamais', function (): void {
    $recorder = eventPayloadQcmSetUp();

    SubmissionFixtures::decoyCandidates();

    foreach ([InputDifficulty::Easy, InputDifficulty::Normal, InputDifficulty::Expert] as $difficulty) {
        ['game' => $game, 'round' => $round, 'seats' => $seats] = eventPayloadQcmRound(
            $difficulty,
            [Locale::French, Locale::English],
            open: false,
        );
        $choicesTier = $difficulty->choicesOpenTierIndex($game->frames_per_round);

        foreach (range(1, $game->frames_per_round) as $tierIndex) {
            $recorder->sent = [];
            $opensAt = EngineFixtures::opensAt($round, $tierIndex);

            $queries = SubmissionFixtures::queries(static fn () => EngineFixtures::openTier($round, $tierIndex));

            if ($tierIndex !== $choicesTier) {
                expect(array_column($recorder->sent, 'event'))->toBe(['tier.opened'], "{$difficulty->value}, palier {$tierIndex}");

                continue;
            }

            // Au palier du QCM : l'ouverture, puis un envoi par siège, à
            // l'instant de l'ouverture.
            expect(array_column($recorder->sent, 'event'))->toBe(['tier.opened', 'seat.choices', 'seat.choices'], $difficulty->value);

            foreach (eventPayloadChoicesSent($recorder->sent) as $sent) {
                expect($sent['payload']['serverNow'])->toBe(WireTime::iso($opensAt))
                    ->and($sent['payload']['sequenceIndex'])->toBe($round->sequence_index)
                    ->and($sent['payload']['choices'])->toHaveCount(ChoicesPayload::COUNT);
            }

            expect(array_merge(...array_column(eventPayloadChoicesSent($recorder->sent), 'channels')))
                ->toEqualCanonicalizing(array_map(eventPayloadSeatChannel(...), $seats));

            // La langue de chaque siège est figée par la composition, qui suit
            // la naissance des participants (contrat C7 § 4.8), jamais
            // rattrapée par le cas défensif du présentateur.
            $defensive = array_filter(
                SubmissionFixtures::writes($queries),
                static fn (string $sql): bool => SubmissionFixtures::touches($sql, 'round_player')
                    && preg_match('/choices_locale[`"]?\s+is\s+null/i', $sql) === 1,
            );

            expect($defensive)->toBe([])
                ->and(RoundPlayer::query()->where('round_id', $round->id)->whereNull('choices_locale')->exists())->toBeFalse();
        }

        // Composé au palier du QCM, et jamais en Expert.
        expect($round->refresh()->decoy_movie_id_1 !== null)->toBe($choicesTier !== null, $difficulty->value)
            ->and($round->status)->toBe(RoundStatus::Running);
    }
});

it('à T_N en Normal, le QCM est poussé au siège dont le texte libre est épuisé', function (): void {
    $recorder = eventPayloadQcmSetUp();

    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    $labels = ['épuisé', 'ouvert', 'trouveur', 'déconnecté', 'parti'];
    ['game' => $game, 'round' => $round, 'seats' => $list, 'tokens' => $tokenList] = eventPayloadQcmRound(
        InputDifficulty::Normal,
        array_fill(0, count($labels), Locale::French),
        $target,
    );
    $seats = array_combine($labels, $list);
    $tokens = array_combine($labels, $tokenList);
    SubmissionFixtures::decoyCandidates();

    $cadence = SubmissionFixtures::cadenceMs($game);
    $t1 = EngineFixtures::opensAt($round, 1);
    $tN = EngineFixtures::opensAt($round, $game->frames_per_round);

    // Le trouveur verrouille au palier 1 ; l'épuisé use son texte libre avant
    // `T_N` et attend le QCM (D20 du 23/09).
    SubmissionFixtures::submit($this, $seats['trouveur'], $tokens['trouveur'], 'Harbour Lights', $t1->addMilliseconds($cadence))
        ->assertOk()
        ->assertJson(['result' => 'accepted']);

    SubmissionFixtures::spend($round, $seats['épuisé'], $game->settings_snapshot->attemptsPerRound - 1);
    SubmissionFixtures::submit($this, $seats['épuisé'], $tokens['épuisé'], SubmissionFixtures::WRONG, $tN->subMilliseconds($cadence))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::TextExhausted));

    // Un siège déconnecté reste destinataire ; un siège parti ne l'est plus.
    $seats['déconnecté']->forceFill([
        'connection_state' => PlayerConnectionState::Disconnected,
        'disconnected_at' => $tN->subMilliseconds($cadence),
    ])->save();
    $seats['parti']->forceFill([
        'connection_state' => PlayerConnectionState::Left,
        'disconnected_at' => $tN->subMilliseconds(2 * $cadence),
        'left_at' => $tN->subMilliseconds($cadence),
    ])->save();

    $recorder->sent = [];
    Date::setTestNow($tN);
    app(CatchUpGame::class)->handle($game, $tN);

    $pushed = eventPayloadChoicesSent($recorder->sent);

    expect(array_column($recorder->sent, 'event'))->toBe(['tier.opened', 'seat.choices', 'seat.choices', 'seat.choices'])
        ->and(array_map(static fn (array $sent): array => $sent['channels'], $pushed))->toBe([
            [eventPayloadSeatChannel($seats['épuisé'])],
            [eventPayloadSeatChannel($seats['ouvert'])],
            [eventPayloadSeatChannel($seats['déconnecté'])],
        ]);

    // La charge de l'épuisé est celle que toute resynchronisation lui rejoue,
    // et le QCM lui rend la main : son clic juste verrouille au palier N.
    $exhausted = SubmissionFixtures::participation($round, $seats['épuisé']);
    $replayed = app(ChoicesPresenter::class)->forSeat($exhausted);

    expect($exhausted->input_state)->toBe(RoundPlayerInputState::TextExhausted)
        ->and($replayed)->toBeInstanceOf(ChoicesPayload::class)
        ->and(array_slice($pushed[0]['payload'], 3))->toBe(['sequenceIndex' => $round->sequence_index, ...$replayed?->toArray() ?? []]);

    SubmissionFixtures::click($this, $seats['épuisé'], $tokens['épuisé'], SubmissionFixtures::correctChoice($round, $seats['épuisé']), $tN->addMilliseconds($cadence))
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'tierIndex' => $game->frames_per_round]);
});

it("en Normal, un QCM non composable n'émet aucun seat.choices", function (): void {
    $recorder = eventPayloadQcmSetUp();

    // Aucun film de plus que les `M` de la partie : deux leurres possibles
    // au plus, jamais trois — le cas terminal (contrat C11).
    ['game' => $game, 'round' => $round, 'seats' => [$exhausted, $open], 'tokens' => $tokens] = eventPayloadQcmRound(
        InputDifficulty::Normal,
        [Locale::French, Locale::English],
    );
    $cadence = SubmissionFixtures::cadenceMs($game);
    $tN = EngineFixtures::opensAt($round, $game->frames_per_round);

    expect(Movie::query()->count())->toBeLessThan($game->rounds_count + ChoicesPayload::COUNT - 1);

    SubmissionFixtures::spend($round, $exhausted, $game->settings_snapshot->attemptsPerRound - 1);
    SubmissionFixtures::submit($this, $exhausted, $tokens[0], SubmissionFixtures::WRONG, $tN->subMilliseconds($cadence))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::TextExhausted));

    $recorder->sent = [];
    Date::setTestNow($tN);
    app(CatchUpGame::class)->handle($game, $tN);

    // Le palier s'ouvre, sans aucun envoi de QCM : la manche continue en
    // saisie texte seule.
    expect(array_column($recorder->sent, 'event'))->toBe(['tier.opened'])
        ->and($round->refresh()->status)->toBe(RoundStatus::Running)
        ->and($round->ended_at)->toBeNull()
        ->and($round->decoy_movie_id_1)->toBeNull()
        ->and(RoundChoiceSet::query()->where('round_id', $round->id)->exists())->toBeFalse()
        ->and(EngineFixtures::tier($round, $game->frames_per_round)->served_at?->equalTo($tN))->toBeTrue();

    // Le siège qui attendait le QCM n'a plus rien à attendre : sa saisie se
    // ferme à l'instant de composition, sans diffusion ; l'autre reste ouvert.
    $closed = SubmissionFixtures::participation($round, $exhausted);

    expect($closed->input_state)->toBe(RoundPlayerInputState::AttemptsExhausted)
        ->and($closed->input_closed_at?->equalTo($tN))->toBeTrue()
        ->and(SubmissionFixtures::participation($round, $open)->input_state)->toBe(RoundPlayerInputState::Open)
        ->and(eventPayloadChoicesSent($recorder->sent))->toBe([]);
});

it('en Normal, un QCM non composable qui ferme le dernier participant émet round.closed après tier.opened', function (bool $catchUp): void {
    // Ajout (E108-1) : la composition terminale ferme le siège au texte
    // épuisé dans une transaction IMBRIQUÉE, dont les rappels après commit
    // partent avant ceux d'`OpenTier` (E90-4) ; l'écouteur de cette clôture
    // attend donc la fin de l'ouverture. Sur le fil, `round.closed` suit
    // `tier.opened` — seul dans un passage de rattrapage, dernière étape
    // (§ 4.4) — et la frontière d'`OpenTier` comme la révélation sont
    // programmées.
    $recorder = eventPayloadQcmSetUp();

    ['game' => $game, 'round' => $round, 'seats' => [$exhausted], 'tokens' => [$token]] = eventPayloadQcmRound(
        InputDifficulty::Normal,
        [Locale::French],
    );
    $cadence = SubmissionFixtures::cadenceMs($game);
    $tN = EngineFixtures::opensAt($round, $game->frames_per_round);

    expect(Movie::query()->count())->toBeLessThan($game->rounds_count + ChoicesPayload::COUNT - 1);

    SubmissionFixtures::spend($round, $exhausted, $game->settings_snapshot->attemptsPerRound - 1);
    SubmissionFixtures::submit($this, $exhausted, $token, SubmissionFixtures::WRONG, $tN->subMilliseconds($cadence))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::TextExhausted));

    $recorder->sent = [];
    Date::setTestNow($tN);

    if ($catchUp) {
        app(CatchUpGame::class)->handle($game, $tN);
    } else {
        EngineFixtures::openTier($round, $game->frames_per_round, $tN);
    }

    $closed = collect($recorder->sent)->firstWhere('event', 'round.closed');

    expect(array_column($recorder->sent, 'event'))->toBe($catchUp ? ['round.closed'] : ['tier.opened', 'round.closed'])
        ->and($closed['payload']['endedAt'] ?? null)->toBe(WireTime::iso($tN))
        ->and($round->refresh()->ended_at?->equalTo($tN))->toBeTrue()
        ->and(SubmissionFixtures::participation($round, $exhausted)->input_closed_at?->equalTo($tN))->toBeTrue()
        ->and(EngineFixtures::tier($round, $game->frames_per_round)->served_at?->equalTo($tN))->toBeTrue();

    $steps = Queue::pushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id && in_array($job->step, [RoundStep::Close, RoundStep::Reveal], true))
        ->map(static fn (AdvanceRound $job): array => [$job->step, $job->dueAt])
        ->values()
        ->all();

    expect($steps)->toEqualCanonicalizing([
        [RoundStep::Close, WireTime::iso(EngineFixtures::durationEnd($round))],
        [RoundStep::Reveal, WireTime::iso($tN->addMilliseconds($game->tier_grace_ms))],
    ]);
})->with([
    'par le rattrapage' => [true],
    "par l'appel direct" => [false],
]);

it("le gel d'une partie multijoueur émet game.ended avec son podium, et rien en solo ni pour une partie disparue", function (): void {
    // Ajout (§ 14.5) : l'écouteur de `GameFinalized` compose le podium par
    // son seul producteur, sur la partie relue, en multijoueur seulement ;
    // idempotent, il tolère une partie purgée.
    $recorder = eventPayloadQcmSetUp();

    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game);
    EngineFixtures::schedule(EngineFixtures::round($game, 1), Date::now()->toImmutable()->addSeconds(5));

    foreach (range(1, $game->rounds_count - 1) as $sequenceIndex) {
        EngineFixtures::play(EngineFixtures::round($game, $sequenceIndex));
    }

    $last = EngineFixtures::round($game, $game->rounds_count);

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($last, $tierIndex);
    }

    EngineFixtures::close($last);
    EngineFixtures::reveal($last);
    $recorder->sent = [];
    EngineFixtures::endReveal($last);

    $game->refresh();
    $podium = Scoreboard::podium($game);
    $room = Room::query()->findOrFail($game->room_id);

    // La fin de la dernière révélation gèle la partie : `game.ended` part
    // après le gel, avec le podium que toute resynchronisation rejouera.
    expect($game->status)->toBe(GameStatus::Completed)
        ->and(array_column($recorder->sent, 'event'))->toBe(['game.ended']);

    $ended = $recorder->sent[0];

    expect($ended['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and($ended['payload']['gameRef'])->toBe(GameRef::for($game))
        ->and($ended['payload']['serverNow'])->toBe(WireTime::iso(Date::now()->toImmutable()))
        ->and($ended['payload']['podium'])->toBe(json_decode(json_encode($podium, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->and($ended['payload']['podium']['gameStatus'])->toBe(GameStatus::Completed->value);

    // Redélivré : le même podium, rien d'autre.
    event(new GameFinalized($game->id, GameStatus::Completed));

    expect(array_column($recorder->sent, 'event'))->toBe(['game.ended', 'game.ended'])
        ->and($recorder->sent[1]['payload']['podium'])->toBe($ended['payload']['podium']);

    // Une partie disparue n'annonce rien ; une partie solo non plus.
    $recorder->sent = [];
    $attempts = $recorder->attempts;

    event(new GameFinalized($game->id + 1_000, GameStatus::Interrupted));

    $solo = EngineFixtures::game(EngineFixtures::settings(), solo: true);
    EngineFixtures::seat($solo);
    EngineFixtures::materialize($solo);

    expect(app(FinalizeGame::class)->handle($solo, GameStatus::Interrupted, Date::now()->toImmutable()))->toBeTrue()
        ->and($recorder->attempts)->toBe($attempts)
        ->and($recorder->sent)->toBe([]);
});

/**
 * Le corps HTTP qu'un événement enverrait à l'API de Reverb, tel que le SDK
 * Pusher le compose : la charge encodée en JSON, puis encodée une seconde
 * fois dans le corps `{ name, data, channel }`.
 */
function eventPayloadReverbBody(RoomBroadcast|SeatBroadcast $event): string
{
    $channel = $event->broadcastOn();

    return json_encode([
        'name' => $event->broadcastAs(),
        'data' => json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR),
        'channel' => $channel->name,
    ], JSON_THROW_ON_ERROR);
}

it('chaque charge aux bornes tient sous la borne de requête de Reverb, pire cas d\'écriture compris', function (): void {
    // Ajout (passation E83-5) : toute diffusion passe par l'API HTTP de
    // Reverb, dont le tampon est borné par `max_request_size` ; au-delà, 413
    // et l'événement est perdu sans resynchronisation. Mesuré sur les
    // producteurs réels, aux bornes : `MAX_ROUNDS_COUNT` manches,
    // `roomSeats()` sièges qui trouvent tous chaque manche, `N` maximal,
    // titres et pseudos à la largeur de leur colonne dans leurs caractères
    // les plus lourds sur le fil. Titres : hors du plan multilingue de base
    // (colonnes utf8mb4), échappés en paire de substitution
    // `\uXXXX\uXXXX`, puis une seconde fois par le corps. Pseudos : la
    // lettre accentuée, le plus lourd de ce que `ValidNickname` admet.
    Event::fake([GameFinalized::class]);

    $bound = (int) config('reverb.servers.reverb.max_request_size');
    // En-têtes, chemin et signature de la requête HTTP, hors corps.
    $requestOverhead = 1_024;
    $title = str_repeat("\u{20000}", 255);
    $original = str_repeat("\u{20001}", 255);
    $nickname = str_repeat('é', 20);

    $game = ScoringFixtures::game(RoomSettings::fromInput([
        'roundsCount' => RoomSettingsBounds::MAX_ROUNDS_COUNT,
        'framesPerRound' => RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
    ]));
    $room = Room::query()->findOrFail($game->room_id);
    $seats = [];

    foreach (range(1, PlatformLimits::roomSeats()) as $position) {
        $seat = ScoringFixtures::seat($game);
        $seat->forceFill(['nickname' => $nickname])->save();
        GamePlayer::query()->where('player_id', $seat->id)->update(['display_nickname' => $nickname]);
        $seats[] = $seat;
    }

    $round = null;

    foreach (range(1, RoomSettingsBounds::MAX_ROUNDS_COUNT) as $roundNumber) {
        $movie = Movie::factory()->create(['title_original' => $original, 'title_original_latin' => $title, 'original_language' => 'ja']);
        MovieTitle::query()->where('movie_id', $movie->id)->delete();

        foreach (Locale::cases() as $locale) {
            MovieTitle::factory()->for($movie)->forLocale($locale->value)->titled($title)->create();
        }

        $round = ScoringFixtures::round($game, $roundNumber, RoundStatus::Completed, movie: $movie);

        foreach ($seats as $position => $seat) {
            ScoringFixtures::find($round, $seat, 1_000 + $position * 37);
        }
    }

    expect($round)->toBeInstanceOf(Round::class)
        ->and(app(FinalizeGame::class)->handle($game, GameStatus::Completed, Date::now()->toImmutable()))->toBeTrue();

    $game->refresh();
    $last = Round::query()->findOrFail($round?->id);

    $heaviest = [
        'game.ended' => new GameEnded($room, $game, ['podium' => Scoreboard::podium($game)]),
        'round.revealed' => new RoundRevealed($room, $game, [
            'sequenceIndex' => $last->sequence_index,
            'roundNumber' => (int) $last->round_number,
            'revealEndsAt' => WireTime::iso(Date::now()->toImmutable()),
            'movie' => RevealMovieBuilder::build(Movie::query()->findOrFail($last->movie_id)),
            'images' => array_map(static fn (int $tierIndex): array => WireFixtures::image($game, $last, $tierIndex), range(1, $game->frames_per_round)),
            // Pire cas : une identité publique par palier (D63 du 07/10).
            'frames' => array_map(static fn (int $tierIndex): array => ['tierIndex' => $tierIndex, 'framePublicId' => str_repeat('Z', PublicId::LENGTH)], range(1, $game->frames_per_round)),
            'finders' => Scoreboard::roundFinders($last),
            'leaderboard' => Scoreboard::leaderboard($game, $last),
        ]),
    ];

    // Non vacant : chaque manche porte ses titres, chaque siège trouve.
    expect($heaviest['game.ended']->payload()['podium']['recap'])->toHaveCount(RoomSettingsBounds::MAX_ROUNDS_COUNT)
        ->and($heaviest['round.revealed']->payload()['finders'])->toHaveCount(PlatformLimits::roomSeats());

    $sizes = [];

    foreach ($heaviest as $name => $event) {
        $sizes[$name] = strlen(eventPayloadReverbBody($event));

        expect($sizes[$name] + $requestOverhead)->toBeLessThanOrEqual($bound, "[{$name}] pèse {$sizes[$name]} octets aux bornes.");
    }

    // L'ancienne borne du paquet ne portait même pas la fin d'une partie.
    expect($sizes['game.ended'])->toBeGreaterThan(10_000);

    // Et les dix-neuf événements d'une scène ordinaire, un à un.
    $scene = WireFixtures::scene();

    foreach (WireFixtures::events($scene) as $event) {
        expect(strlen(eventPayloadReverbBody($event)) + $requestOverhead)->toBeLessThanOrEqual($bound, $event->broadcastAs());
    }
});

it('un pseudo masqué part à nul dans toute vue de siège, lobby comme partie, affichage gelé compris', function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    // Spec 60 § 11.3 (J2, L60-17) et 40 § 13.3 : `nickname: null`,
    // `masked: true`, jamais le pseudo ni ses initiales, sur le même
    // transport que tout le reste.
    [$room, $host] = HostGestures::room();
    [$target] = HostGestures::seat($room, attributes: ['nickname' => 'Pseudo Vilain', 'nickname_normalized' => 'pseudovilain']);
    [$game] = HostGestures::runningGame($room, [$host, $target]);

    $target->forceFill(['nickname_masked_at' => Date::now()])->save();
    $recorder = RecordingBroadcaster::install();

    SeatJoined::dispatch($room, null, ['seat' => SeatViewPresenter::lobby($target, $room->host_player_id)]);
    SeatUpdated::dispatch($room, $game, ['seat' => SeatViewPresenter::ofSeat($target, $game, $room->host_player_id)]);

    expect(array_column($recorder->sent, 'event'))->toBe(['seat.joined', 'seat.updated']);

    foreach ($recorder->sent as $sent) {
        expect($sent['payload']['seat']['publicId'])->toBe($target->public_id)
            ->and($sent['payload']['seat']['nickname'])->toBeNull()
            ->and($sent['payload']['seat']['masked'])->toBeTrue()
            ->and($sent['json'])->not->toContain('Pseudo Vilain')
            ->and($sent['json'])->not->toContain('pseudovilain');
    }

    // La partie en cours garde son affichage gelé en base, jamais sur le fil.
    expect(GamePlayer::query()->whereBelongsTo($game)->where('player_id', $target->id)->sole()->display_nickname)
        ->toBe('Pseudo Vilain');
});
