<?php

use App\Actions\Game\AdvanceToNextRound;
use App\Actions\Game\CatchUpGame;
use App\Actions\Game\ScheduleRound;
use App\Enums\Locale;
use App\Enums\RoundStatus;
use App\Events\Game\GameEnded;
use App\Events\Game\GameLaunched;
use App\Events\Game\GamePaused;
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
use App\Models\Alias;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundTier;
use App\Settings\EngineConstants;
use App\Support\Game\GameStateBuilder;
use App\Support\Game\NextRoundOutcome;
use App\Support\Game\TransitionBroadcasts;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\GameWire;
use App\Support\Realtime\WirePayload;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\I18n\FrontSource;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Realtime\RecordingJob;
use Tests\Support\Realtime\WireFixtures;
use Tests\Support\Realtime\WireScene;

/*
|--------------------------------------------------------------------------
| Contrat d'événements — spec 60 § 11, contrat C7 § 2.3 et § 3 (lots L60-3, L60-6, L60-7)
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
| et la répartition salon / siège des écoutes de `lib/game/echo.ts`. Les
| autres intitulés du fichier (60 § 20) arrivent avec leurs émetteurs
| (L60-11) ; « ni aucun paquet » s'éprouve sur
| `GameStatePacket` : branche sans partie dès L60-4 (lobby et solo, par
| `room.state` et par le constructeur), branche de partie en L60-12
| (passation obligatoire, E83-3).
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
        'tier.opened' => [TierOpened::class, 'room', ['sequenceIndex', 'roundNumber', 'tierIndex', 'opensAt', 'next']],
        'player.locked' => [PlayerLocked::class, 'room', ['sequenceIndex', 'publicId', 'lockRank']],
        'round.closed' => [RoundClosed::class, 'room', ['sequenceIndex', 'roundNumber', 'endedAt', 'revealStartsAt', 'revealEndsAt']],
        'round.revealed' => [RoundRevealed::class, 'room', ['sequenceIndex', 'roundNumber', 'revealEndsAt', 'movie', 'images', 'finders', 'leaderboard']],
        'round.cancelled' => [RoundCancelled::class, 'room', ['sequenceIndex', 'roundNumber']],
        'game.paused' => [GamePaused::class, 'room', ['pausedAt', 'interruptsAt']],
        'game.resumed' => [GameResumed::class, 'room', ['resumedAt']],
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
        ...RoundTier::query()->pluck('serve_token')->all(),
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
    }

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

    $never = array_values(array_filter([
        ...$never,
        ...Player::query()->pluck('player_token_hash')->all(),
        ...Player::query()->pluck('active_seat_token')->all(),
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

        // Ni l'identifiant du salon, ni son code : le canal passe par la clé
        // HMAC (E10-31).
        expect($packet['json'])->not->toContain('"'.$lobby->room_code.'"');
    }

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

    // Dix-neuf, ni plus ni moins : tout nouvel événement amende 60 § 11.3 et
    // entre ici.
    expect($found)->toBe($expected)
        ->and($found)->toHaveCount(19);

    // Seuls trois événements sont ciblés.
    expect(array_keys(array_filter($found, static fn (array $row): bool => $row[1] === 'seat')))
        ->toEqualCanonicalizing(['seat.choices', 'seat.superseded', 'seat.kicked']);

    // Miroir client (L60-9, écart (i) du § 22 bis) : l'union `GameEventName`
    // et les charges typées nomment exactement les dix-neuf, dans l'ordre de
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
    // compris (son émetteur arrive en L60-11).
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
