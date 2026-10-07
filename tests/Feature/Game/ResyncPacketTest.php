<?php

use App\Actions\Game\ClaimSeatTab;
use App\Actions\Game\FinalizeGame;
use App\Actions\Game\MaterializeDraw;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundPlayerInputState;
use App\Http\Middleware\EnsureActiveSeat;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Alias;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Settings\EngineConstants;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Answers\ChoicesPresenter;
use App\Support\Game\GameJournal;
use App\Support\Game\GameStateBuilder;
use App\Support\Game\RevealFramesPresenter;
use App\Support\Game\RevealMovieBuilder;
use App\Support\Game\SeatViewPresenter;
use App\Support\Game\ServeGuard;
use App\Support\Game\TierImageRefPresenter;
use App\Support\Identity\PlayerIdentity;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\WireTime;
use App\Support\Scoring\Scoreboard;
use App\ValueObjects\Answers\SeatInputView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Game\GameJournalRecorder;
use Tests\Support\I18n\FrontSource;
use Tests\Support\Scoring\ScoringTypes;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Paquet de resynchronisation — spec 60 § 12, contrat C7 § 3 (lots L60-4, L60-12)
|--------------------------------------------------------------------------
|
| `GameStatePacket`, une seule forme sortie d'un seul constructeur : prop
| `state` des pages `game/*`, réponse de `room.state` et de `solo.state`.
|
| Lot L60-4 : la branche SANS partie (lobby) — canaux du salon, sièges dans
| l'ordre de 50, `self` et `seatActive`, aucune manche, classement vide — et
| `room.state` qui la sert.
|
| Lot L60-12 : la branche DE PARTIE, servie par `room.state` après le
| rattrapage — manche portée (§ 12.3), borne des URL (§ 12.4 : au plus une
| URL par palier, deux au plus pendant la manche, les paliers ouverts en
| révélation), `nextTransitionAt` (§ 12.5), saisie du siège, classement,
| podium. Vraies parties matérialisées sur de vraies variantes, fichiers sur
| le disque `frames` simulé (aucune image réelle), étapes jouées par les
| actions réelles ou par le rattrapage de la route. Les jobs de frontière
| sont retenus : le temps n'avance que par l'horloge figée du test, et seule
| la resynchronisation rattrape une étape due.
|
*/

/**
 * `room.state` sous ce jeton, en présentant éventuellement un jeton d'onglet.
 * Limiteur remis à neuf : le débit de `game-read` est prouvé par
 * `GameRateLimitersTest`, et un balayage de partie en fait plus.
 *
 * @return TestResponse<Response>
 */
function resyncPacketFetch(TestCase $test, Room $room, ?PlayerToken $token, ?string $seatToken = null): TestResponse
{
    Cache::flush();
    (new ReflectionProperty($test, 'defaultCookies'))->setValue($test, []);

    if ($token !== null) {
        $test->withCredentials()
            ->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR));
    }

    return $test->getJson(
        route('room.state', ['room' => $room->room_code]),
        $seatToken === null ? [] : [EnsureActiveSeat::HEADER => $seatToken],
    );
}

/**
 * Le banc du moteur : horloge figée à un instant de lancement, disque
 * `frames` simulé (aucune image réelle), jobs de frontière retenus — le temps
 * n'avance que par l'horloge du test, et seule la resynchronisation rattrape
 * une étape due. Rend l'instant de lancement.
 */
function resyncPacketEngine(): CarbonImmutable
{
    $now = CarbonImmutable::parse('2026-09-27 10:00:00.250');
    Date::setTestNow($now);

    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    return $now;
}

/**
 * Une partie multijoueur en cours dans un salon `playing` : `$seats` sièges
 * tenus chacun par un jeton (onglet actif frappé, langue française), le
 * premier hôte, la partie matérialisée par l'action réelle — `$target` en
 * manche 1 s'il est donné — et sa manche 1 programmée au bout du décompte de
 * lancement. Appelée après {@see resyncPacketEngine()}.
 *
 * @return array{0: Game, 1: Room, 2: list<Player>, 3: list<PlayerToken>}
 */
function resyncPacketGame(RoomSettings $settings, int $seats = 1, ?Movie $target = null): array
{
    $room = Room::factory()->playing()->create();
    $game = EngineFixtures::game($settings, room: $room);
    $players = [];
    $tokens = [];

    foreach (range(1, $seats) as $ignored) {
        $token = PlayerToken::mint(Locale::French);
        $tokens[] = $token;
        $players[] = EngineFixtures::seat($game, [
            'player_token_hash' => $token->hash(),
            'active_seat_token' => (string) Str::ulid(),
            'locale' => Locale::French,
        ]);
    }

    $room->forceFill(['host_player_id' => $players[0]->id])->save();

    if ($target instanceof Movie) {
        $movies = [$target, ...EngineFixtures::movies($game, $game->rounds_count - 1)];
        DB::transaction(static fn () => app(MaterializeDraw::class)->handle($game, EngineFixtures::draw($game, $movies)));
    } else {
        EngineFixtures::materialize($game);
    }

    EngineFixtures::schedule(
        EngineFixtures::round($game, 1),
        Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()),
    );

    return [$game->refresh(), $room->refresh(), $players, $tokens];
}

/**
 * Le paquet que `room.state` sert à ce siège (onglet actif) à l'instant
 * `$at` : l'horloge y est figée, la route rattrape les étapes échues puis
 * construit le paquet.
 *
 * @return array<string, mixed>
 */
function resyncPacketAt(TestCase $test, Room $room, Player $seat, PlayerToken $token, CarbonImmutable $at): array
{
    Date::setTestNow($at);

    /** @var array<string, mixed> $packet */
    $packet = resyncPacketFetch($test, $room, $token, $seat->active_seat_token)->assertOk()->json();

    return $packet;
}

/**
 * Les paliers dont le paquet porte l'URL, dans l'ordre du paquet.
 *
 * @param  array<string, mixed>  $packet
 * @return list<int>
 */
function resyncPacketTiers(array $packet): array
{
    /** @var list<array{tierIndex: int}> $images */
    $images = $packet['round']['images'] ?? [];

    return array_map(static fn (array $image): int => $image['tierIndex'], $images);
}

/**
 * La référence d'image attendue d'un palier, par son seul constructeur,
 * relue en base à l'instant courant.
 *
 * @return array{tierIndex: int, url: string, fetchNotBefore: string}
 */
function resyncPacketImage(Game $game, Round $round, int $tierIndex): array
{
    return TierImageRefPresenter::image(Game::query()->findOrFail($game->id), EngineFixtures::tier($round, $tierIndex));
}

/**
 * Les `serve_token` frappés des paliers donnés d'une manche.
 *
 * @param  list<int>  $tierIndexes
 * @return list<string>
 */
function resyncPacketTokens(Round $round, array $tierIndexes): array
{
    return array_values(array_filter(array_map(
        static fn (int $tierIndex): ?string => EngineFixtures::tier($round, $tierIndex)->serve_token,
        $tierIndexes,
    )));
}

/**
 * La borne du § 12.4, commune à toute sonde d'une manche en cours : au plus
 * une URL par palier, aucune avant sa garde, deux au plus, dans l'ordre des
 * paliers, la première au palier courant — jamais un palier antérieur.
 *
 * @param  array<string, mixed>  $packet
 */
function resyncPacketAssertBound(array $packet, CarbonImmutable $at, string $label): void
{
    $tiers = resyncPacketTiers($packet);

    expect($tiers)->toBe(array_values(array_unique($tiers)), "{$label} : un palier porte deux URL.")
        ->and(count($tiers))->toBeLessThanOrEqual(2, "{$label} : plus de deux URL pendant la manche.");

    $sorted = $tiers;
    sort($sorted);
    expect($tiers)->toBe($sorted, "{$label} : URL hors de l'ordre des paliers.");

    if ($tiers !== []) {
        expect($tiers[0])->toBe($packet['round']['currentTierIndex'], "{$label} : la première URL n'est pas celle du palier courant.");
    }

    /** @var list<array{fetchNotBefore: string}> $images */
    $images = $packet['round']['images'];

    foreach ($images as $image) {
        expect(CarbonImmutable::parse($image['fetchNotBefore'])->lessThanOrEqualTo($at))
            ->toBeTrue("{$label} : URL portée avant sa garde.");
    }
}

/**
 * Toutes les clés d'un paquet, à toute profondeur.
 *
 * @param  array<array-key, mixed>  $payload
 * @return list<string>
 */
function resyncPacketKeys(array $payload): array
{
    $keys = [];

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($value)) {
            array_push($keys, ...resyncPacketKeys($value));
        }
    }

    return $keys;
}

/**
 * Les chaînes qui désignent le film, telles que le JSON les écrirait (Unicode
 * échappé) : titre original, translittération, titres de toute locale et
 * alias.
 *
 * @return list<string>
 */
function resyncPacketTitles(Movie $movie): array
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

it("le paquet d'un lobby porte les canaux du salon et aucune manche", function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-09-23 16:05:13.004999', 'Europe/Paris'));

    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);

    // Quatre sièges, arrivés dans le désordre de leurs identifiants : l'hôte
    // (arrivé en second), le demandeur, un siège parti et un siège expulsé —
    // tous restent dans la liste, chacun avec son état.
    $early = Player::factory()->create(['room_id' => $room->id, 'joined_at' => Date::now()->subMinutes(9)]);
    $seat = Player::factory()->create([
        'room_id' => $room->id,
        'player_token_hash' => $token->hash(),
        'joined_at' => Date::now()->subMinutes(5),
    ]);
    $host = Player::factory()->create(['room_id' => $room->id, 'joined_at' => Date::now()->subMinutes(7)]);
    $left = Player::factory()->left()->create(['room_id' => $room->id, 'joined_at' => Date::now()->subMinutes(3)]);
    $kicked = Player::factory()->kicked()->create(['room_id' => $room->id, 'joined_at' => Date::now()->subMinute()]);
    $room->forceFill(['host_player_id' => $host->id])->save();

    // Un siège d'un autre salon n'y figure jamais.
    Player::factory()->create();

    $packet = resyncPacketFetch($this, $room, $token)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json();

    // L'enveloppe, puis les champs de GameStatePacket dans l'ordre du type.
    expect(array_keys($packet))->toBe([
        'v', 'serverNow', 'gameRef', 'mode', 'channels', 'status', 'roundsCount', 'roundsCompleted',
        'framesPerRound', 'inputDifficulty', 'maxAnswerLength', 'seats', 'pause', 'round', 'self',
        'leaderboard', 'podium', 'nextTransitionAt',
    ])
        ->and($packet['v'])->toBe(1)
        ->and($packet['serverNow'])->toBe('2026-09-23T14:05:13.004Z')
        ->and($packet['serverNow'])->toMatch(WireTime::PATTERN)
        ->and($packet['gameRef'])->toBeNull()
        ->and($packet['mode'])->toBe('multiplayer');

    // Les deux canaux du salon, présents au lobby (écart (a) du § 22 bis) :
    // clé HMAC du salon, jamais le room_code ni room.id.
    expect($packet['channels'])->toBe([
        'room' => ChannelNames::room($room),
        'seat' => ChannelNames::seat($seat),
    ])
        ->and($packet['channels']['room'])->not->toContain($room->room_code)
        ->and($packet['channels']['room'])->toMatch('/^room\.[0-9a-f]{32}$/');

    // Aucune manche, aucune partie : tout ce qui décrit une partie est nul.
    foreach (['status', 'roundsCount', 'roundsCompleted', 'framesPerRound', 'inputDifficulty', 'maxAnswerLength', 'pause', 'round', 'podium', 'nextTransitionAt'] as $field) {
        expect($packet[$field])->toBeNull("[{$field}] devrait être nul au lobby.");
    }

    expect($packet['leaderboard'])->toBe(['scoreless' => false, 'roundNumber' => null, 'rows' => []]);

    // Les sièges du salon, par `joined_at` croissant (50 § 8.1), identité
    // courante (C5) et état de siège.
    expect(array_column($packet['seats'], 'publicId'))
        ->toBe([$early->public_id, $host->public_id, $seat->public_id, $left->public_id, $kicked->public_id]);

    foreach ($packet['seats'] as $view) {
        $player = Player::query()->where('public_id', $view['publicId'])->firstOrFail();

        expect($view)->toBe([
            ...PlayerIdentity::fromSeat($player)->toArray(),
            'isHost' => $player->id === $host->id,
            'connection' => $player->connection_state->value,
            'kicked' => $player->kicked_at !== null,
            'firstRoundNumber' => null,
        ]);
    }

    expect(array_column($packet['seats'], 'connection', 'publicId'))->toMatchArray([
        $left->public_id => PlayerConnectionState::Left->value,
        $kicked->public_id => PlayerConnectionState::Left->value,
    ])
        ->and(array_column($packet['seats'], 'kicked', 'publicId'))->toBe([
            $early->public_id => false,
            $host->public_id => false,
            $seat->public_id => false,
            $left->public_id => false,
            $kicked->public_id => true,
        ]);

    // Soi : aucune participation, aucun score, aucune saisie.
    expect($packet['self'])->toBe([
        'publicId' => $seat->public_id,
        'seatActive' => false,
        'isHost' => false,
        'member' => false,
        'participates' => false,
        'input' => null,
        'ownScore' => 0,
    ]);

    // L'hôte se sait hôte.
    $hostToken = PlayerToken::mint(Locale::English);
    $host->forceFill(['player_token_hash' => $hostToken->hash()])->save();

    expect(resyncPacketFetch($this, $room, $hostToken)->assertOk()->json('self.isHost'))->toBeTrue();

    // Une partie close d'un salon REVENU au lobby (« Rejouer ») ne ressuscite
    // aucune manche : le paquet reste celui du lobby.
    Game::factory()->forRoom($room)->completed()->create();

    expect(resyncPacketFetch($this, $room, $token)->assertOk()->json('round'))->toBeNull();

    // Sans siège tenu par le jeton, siège expulsé, ou salon archivé : 403.
    resyncPacketFetch($this, $room, null)->assertForbidden();
    resyncPacketFetch($this, $room, PlayerToken::mint(Locale::French))->assertForbidden();

    $kickedToken = PlayerToken::mint(Locale::French);
    $kicked->forceFill(['player_token_hash' => $kickedToken->hash()])->save();
    resyncPacketFetch($this, $room, $kickedToken)->assertForbidden();

    $room->forceFill(['room_code_active' => null, 'archived_at' => Date::now(), 'status' => 'archived'])->save();
    resyncPacketFetch($this, $room, $token)->assertForbidden();

    // En solo, sans partie : aucun canal, et soi pour seul siège.
    $solo = Player::factory()->solo()->create();
    $soloPacket = GameStateBuilder::build(null, $solo, Date::now()->toImmutable(), null);

    expect($soloPacket['mode'])->toBe('solo')
        ->and($soloPacket['channels'])->toBeNull()
        ->and(array_column($soloPacket['seats'], 'publicId'))->toBe([$solo->public_id])
        ->and($soloPacket['seats'][0]['isHost'])->toBeFalse()
        ->and($soloPacket['round'])->toBeNull();
});

it("l'onglet supplanté reçoit seatActive faux", function (): void {
    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);
    $seat = Player::factory()->create([
        'room_id' => $room->id,
        'player_token_hash' => $token->hash(),
        'active_seat_token' => null,
    ]);

    $claim = app(ClaimSeatTab::class);
    $first = $claim->handle($seat, null);
    $second = $claim->handle(Player::query()->findOrFail($seat->id), null);

    expect($second)->not->toBe($first);

    // L'onglet supplanté lit `seatActive: false` — `room.state` n'est pas sous
    // `seat.active` : c'est ainsi qu'il l'apprend, puis passe en lecture seule.
    resyncPacketFetch($this, $room, $token, $first)->assertOk()->assertJsonPath('self.seatActive', false);

    // L'onglet qui a pris la main, lui, le tient ; sans en-tête, personne.
    resyncPacketFetch($this, $room, $token, $second)->assertOk()->assertJsonPath('self.seatActive', true);
    resyncPacketFetch($this, $room, $token)->assertOk()->assertJsonPath('self.seatActive', false);

    // Le jeton d'onglet ne voyage jamais dans le paquet.
    $json = (string) resyncPacketFetch($this, $room, $token, $second)->getContent();

    expect($json)->not->toContain($first)
        ->and($json)->not->toContain($second);
});

it('la forme du paquet sans partie suit GameStatePacket, SelfState et SeatView de game-wire.ts', function (): void {
    $source = FrontSource::withoutComments((string) file_get_contents(resource_path('js/types/game-wire.ts')));
    $declarations = ScoringTypes::declarations($source);

    foreach (['SeatView', 'RoundTimeline', 'RoundState', 'SelfState', 'GameStatePacket', 'RealtimeConfig'] as $name) {
        expect($declarations)->toHaveKey($name);
    }

    // Les types des autres contrats sont importés, jamais redéclarés (R-27).
    foreach (['PlayerIdentity', 'SeatInputView', 'TierWindow', 'RoundFinder', 'Leaderboard', 'Podium'] as $imported) {
        expect($declarations)->not->toHaveKey($imported);
    }

    expect($source)->toContain("from '@/types/player'")
        ->toContain("from '@/types/answers'")
        ->toContain("from '@/types/scoring'");

    $fields = static fn (string $name): array => ScoringTypes::objectFields($declarations[$name])[0];

    $room = Room::factory()->create();
    $seat = Player::factory()->create(['room_id' => $room->id]);
    $packet = GameStateBuilder::build(null, $seat, Date::now()->toImmutable(), null);

    expect(array_keys($packet))->toBe([...$fields('WireEnvelope'), ...$fields('GameStatePacket')])
        ->and(array_keys($packet['self']))->toBe($fields('SelfState'))
        ->and(array_keys($packet['seats'][0]))->toBe([
            ...array_keys(PlayerIdentity::fromSeat($seat)->toArray()),
            ...$fields('SeatView'),
        ]);

    // `RoundState` prolonge `RoundTimeline` ; les champs de `RoundTimeline`
    // sont ceux de sa charge serveur (60 § 11.5).
    expect($declarations['RoundState'])->toContain('extends RoundTimeline')
        ->and($fields('RoundTimeline'))->toBe([
            'sequenceIndex', 'roundNumber', 'roundsCount', 'startsAt', 'durationMs', 'tiers', 'choicesAtTierIndex',
        ]);
});

// --- Lot L60-12 : branche de partie -----------------------------------------

it("une resynchronisation à t = 1 s d'une manche à N=5 ne renvoie qu'une URL", function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings(framesPerRound: 5));
    $round = EngineFixtures::round($game, 1);
    $at = EngineFixtures::opensAt($round, 1)->addSecond();

    $packet = resyncPacketAt($this, $room, $seat, $token, $at);

    expect($packet['round']['sequenceIndex'])->toBe(1)
        ->and($packet['round']['phase'])->toBe('running')
        ->and($packet['round']['currentTierIndex'])->toBe(1)
        ->and($packet['round']['images'])->toBe([resyncPacketImage($game, $round, 1)]);

    resyncPacketAssertBound($packet, $at, 't = 1 s');

    // Le palier 2, frappé dès l'ouverture du palier 1, n'est signé qu'à sa
    // garde : son jeton ne quitte pas le serveur. Les suivants ne sont pas
    // encore frappés.
    $hidden = resyncPacketTokens($round, [2, 3, 4, 5]);
    expect($hidden)->toHaveCount(1);

    foreach ($hidden as $serveToken) {
        expect(json_encode($packet, JSON_THROW_ON_ERROR))->not->toContain($serveToken);
    }

    // L'URL portée sert l'image à ce siège, à cet instant.
    $served = $this->get($packet['round']['images'][0]['url']);
    $served->assertOk();
    $served->streamedContent();
});

it('dans la fenêtre de préchargement, le paquet porte au plus une URL par palier dont la garde est franchie', function (): void {
    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        resyncPacketEngine();
        [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings($framesPerRound));
        $round = EngineFixtures::round($game, 1);

        foreach (range(1, $framesPerRound) as $tierIndex) {
            $probes = [];

            if ($tierIndex === $framesPerRound) {
                // Dernier palier : aucune garde à venir, une seule URL.
                $probes[] = [EngineFixtures::opensAt($round, $tierIndex)->addMilliseconds(1), [$tierIndex]];
            } else {
                $nextOpensAt = EngineFixtures::opensAt($round, $tierIndex + 1);
                $guard = $nextOpensAt->subMilliseconds($game->preload_lead_ms);

                // Une milliseconde avant la garde : le seul palier courant ;
                // de la garde à l'ouverture : le courant et le suivant.
                $probes[] = [$guard->subMilliseconds(1), [$tierIndex]];
                $probes[] = [$guard, [$tierIndex, $tierIndex + 1]];
                $probes[] = [$nextOpensAt->subMilliseconds(1), [$tierIndex, $tierIndex + 1]];
            }

            foreach ($probes as [$at, $expected]) {
                $label = "N = {$framesPerRound}, palier {$tierIndex}, ".WireTime::iso($at);
                $packet = resyncPacketAt($this, $room, $seat, $token, $at);

                resyncPacketAssertBound($packet, $at, $label);

                expect(resyncPacketTiers($packet))->toBe($expected, $label)
                    ->and($packet['round']['images'])->toBe(array_map(
                        static fn (int $tier): array => resyncPacketImage($game, $round, $tier),
                        $expected,
                    ), $label);
            }
        }
    }
});

it('pendant la révélation, le paquet porte les URL des seuls paliers ouverts, puis aucune après reveal_ends_at', function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings(framesPerRound: 5));

    // Joué sur la DERNIÈRE manche (écart (c) du § 22 bis) : les deux
    // premières à leurs instants théoriques, la troisième close par fin
    // anticipée une seconde après l'ouverture de son palier 2.
    EngineFixtures::play(EngineFixtures::round($game, 1));
    EngineFixtures::play(EngineFixtures::round($game, 2));
    $last = EngineFixtures::round($game, $game->rounds_count);

    EngineFixtures::openTier($last, 1);
    EngineFixtures::openTier($last, 2);
    EngineFixtures::close($last, EngineFixtures::opensAt($last, 2)->addSecond());

    $last->refresh();
    $endedAt = $last->ended_at ?? throw new LogicException('Manche non close.');
    $revealStartsAt = $endedAt->addMilliseconds($game->tier_grace_ms);
    $revealEndsAt = $last->reveal_ends_at ?? throw new LogicException('Manche sans fin de révélation.');
    // Le palier 3 a été frappé à l'ouverture du palier 2, mais la fin
    // anticipée l'a empêché de s'ouvrir : jamais servi, jamais porté.
    $unopened = resyncPacketTokens($last, [3]);
    expect($unopened)->toHaveCount(1);

    // Grâce finale (phase closed) : le dernier palier ouvert seul, aucun titre.
    $closed = resyncPacketAt($this, $room, $seat, $token, $endedAt->addMilliseconds(1));

    expect($closed['round']['phase'])->toBe('closed')
        ->and($closed['round']['currentTierIndex'])->toBe(2)
        ->and(resyncPacketTiers($closed))->toBe([2])
        ->and($closed['round']['reveal'])->toBeNull()
        ->and($closed['round']['revealStartsAt'])->toBe(WireTime::iso($revealStartsAt))
        ->and($closed['round']['revealEndsAt'])->toBe(WireTime::iso($revealEndsAt));

    // À revealStartsAt, job de révélation en retard : la manche est encore
    // close en base — ni titre ni trouveurs tant que `RevealRound` n'a pas
    // rendu la manche publiable (E10-52), quel que soit l'instant.
    Date::setTestNow($revealStartsAt);
    $unrevealed = GameStateBuilder::build($game->refresh(), $seat->refresh(), $revealStartsAt, $seat->active_seat_token);

    expect($unrevealed['round'])->toHaveKey('phase', 'closed')
        ->toHaveKey('reveal', null);

    // Révélation : les paliers OUVERTS, et eux seuls, jusqu'à reveal_ends_at.
    foreach ([$revealStartsAt, $revealEndsAt->subMilliseconds(1)] as $at) {
        $packet = resyncPacketAt($this, $room, $seat, $token, $at);

        expect($packet['round']['sequenceIndex'])->toBe($last->sequence_index)
            ->and($packet['round']['phase'])->toBe('revealing')
            ->and($packet['round']['images'])->toBe([
                resyncPacketImage($game, $last, 1),
                resyncPacketImage($game, $last, 2),
            ])
            ->and($packet['round']['reveal'])->toBe([
                'movie' => RevealMovieBuilder::build(Movie::query()->findOrFail($last->movie_id)),
                // D63 du 07/10 : l'identité publique des images servies, paliers ouverts seuls.
                'frames' => RevealFramesPresenter::frames(
                    RoundTier::query()->where('round_id', $last->id)->whereNotNull('served_at')->with('servedFrame')->get(),
                ),
                'finders' => Scoreboard::roundFinders($last->refresh()),
            ]);

        foreach ([...$unopened, ...resyncPacketTokens(EngineFixtures::round($game, 1), [1, 2, 3, 4, 5])] as $serveToken) {
            expect(json_encode($packet, JSON_THROW_ON_ERROR))->not->toContain($serveToken);
        }
    }

    // À reveal_ends_at, job de fin de révélation en retard : la manche est
    // encore `revealing` en base, et plus aucune URL ne part.
    Date::setTestNow($revealEndsAt);
    $late = GameStateBuilder::build($game->refresh(), $seat->refresh(), $revealEndsAt, $seat->active_seat_token);

    expect($late['round']['phase'] ?? null)->toBe('revealing')
        ->and($late['round']['images'] ?? null)->toBe([]);

    // Resynchronisé, le rattrapage clôt la révélation et gèle la partie :
    // aucune manche, aucune URL, le podium.
    $frozen = resyncPacketAt($this, $room, $seat, $token, $revealEndsAt);

    expect($frozen['status'])->toBe(GameStatus::Completed->value)
        ->and($frozen['round'])->toBeNull()
        ->and($frozen['podium'])->toBe(Scoreboard::podium($game->refresh()))
        ->and(json_encode($frozen, JSON_THROW_ON_ERROR))->not->toContain('/f/');
});

it("au palier 3 d'une manche à N = 5, hors fenêtre de préchargement, le paquet ne porte que l'URL du palier 3", function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings(framesPerRound: 5));
    $round = EngineFixtures::round($game, 1);
    $at = EngineFixtures::opensAt($round, 3)->addSecond();

    expect($at->lessThan(EngineFixtures::opensAt($round, 4)->subMilliseconds($game->preload_lead_ms)))->toBeTrue();

    $packet = resyncPacketAt($this, $room, $seat, $token, $at);

    expect($packet['round']['currentTierIndex'])->toBe(3)
        ->and($packet['round']['images'])->toBe([resyncPacketImage($game, $round, 3)]);

    // Les paliers 1 et 2, déjà montrés, restent servables à un client lent
    // (aucune borne haute de la garde, § 7.2) — le paquet ne les re-signe
    // pourtant jamais ; le palier 4, déjà frappé, attend sa garde.
    $guard = app(ServeGuard::class);
    $hidden = resyncPacketTokens($round, [1, 2, 4]);

    expect($hidden)->toHaveCount(3)
        ->and($guard->allows(EngineFixtures::tier($round, 1), $token->hash(), $at))->toBeTrue()
        ->and($guard->allows(EngineFixtures::tier($round, 2), $token->hash(), $at))->toBeTrue();

    foreach ($hidden as $serveToken) {
        expect(json_encode($packet, JSON_THROW_ON_ERROR))->not->toContain($serveToken);
    }
});

it('dans la fenêtre de préchargement du palier 4, le paquet porte les URL des paliers 3 et 4 et aucune autre', function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings(framesPerRound: 5));
    $round = EngineFixtures::round($game, 1);
    $opensAt = EngineFixtures::opensAt($round, 4);
    $guard = $opensAt->subMilliseconds($game->preload_lead_ms);

    foreach ([$guard, $guard->addMilliseconds(intdiv($game->preload_lead_ms, 2)), $opensAt->subMilliseconds(1)] as $at) {
        $packet = resyncPacketAt($this, $room, $seat, $token, $at);

        expect($packet['round']['currentTierIndex'])->toBe(3)
            ->and($packet['round']['images'])->toBe([
                resyncPacketImage($game, $round, 3),
                resyncPacketImage($game, $round, 4),
            ]);

        foreach (resyncPacketTokens($round, [1, 2]) as $serveToken) {
            expect(json_encode($packet, JSON_THROW_ON_ERROR))->not->toContain($serveToken);
        }

        // Le palier 5 n'est frappé qu'à l'ouverture du palier 4.
        expect(EngineFixtures::tier($round, 5)->serve_token)->toBeNull();
    }

    // À T₄, le palier 3 sort du paquet.
    expect(resyncPacketTiers(resyncPacketAt($this, $room, $seat, $token, $opensAt)))->toBe([4]);
});

it("un paquet construit au milieu du palier i, puis une resynchronisation à la garde de i+1, livrent l'URL de i+1", function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings(framesPerRound: 4));
    $round = EngineFixtures::round($game, 1);

    foreach (range(1, $game->frames_per_round - 1) as $tierIndex) {
        $middle = EngineFixtures::opensAt($round, $tierIndex)
            ->addMilliseconds(intdiv(EngineFixtures::tier($round, $tierIndex)->duration_ms, 2));
        $guard = EngineFixtures::opensAt($round, $tierIndex + 1)->subMilliseconds($game->preload_lead_ms);

        expect($middle->lessThan($guard))->toBeTrue();

        // Au milieu du palier i : son URL seule, et la garde de i+1 annoncée
        // — c'est l'instant où le client se resynchronise (§ 12.6), aucun
        // événement ne lui apportant l'URL de i+1.
        $packet = resyncPacketAt($this, $room, $seat, $token, $middle);

        expect(resyncPacketTiers($packet))->toBe([$tierIndex])
            ->and($packet['nextTransitionAt'])->toBe(WireTime::iso($guard));

        $resync = resyncPacketAt($this, $room, $seat, $token, CarbonImmutable::parse($packet['nextTransitionAt']));

        expect(resyncPacketTiers($resync))->toBe([$tierIndex, $tierIndex + 1])
            ->and($resync['round']['images'][1])->toBe(resyncPacketImage($game, $round, $tierIndex + 1));

        // Et l'URL livrée à la garde sert l'image dès cet instant.
        $served = $this->get($resync['round']['images'][1]['url']);
        $served->assertOk();
        $served->streamedContent();
    }
});

it('nextTransitionAt annonce la prochaine garde de palier', function (): void {
    $launchedAt = resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings());
    $first = EngineFixtures::round($game, 1);
    $lead = $game->preload_lead_ms;
    $opensAt = static fn (Round $round, int $tier): CarbonImmutable => EngineFixtures::opensAt($round, $tier);
    $next = fn (CarbonImmutable $at): ?string => resyncPacketAt($this, $room, $seat, $token, $at)['nextTransitionAt'];

    // Paquet du lancement, construit avant la garde du palier 1 : il n'en
    // porte pas l'URL, et annonce cette garde.
    $launch = resyncPacketAt($this, $room, $seat, $token, $launchedAt);

    expect($launch['round']['phase'])->toBe('scheduled')
        ->and($launch['round']['images'])->toBe([])
        ->and($launch['nextTransitionAt'])->toBe(WireTime::iso($opensAt($first, 1)->subMilliseconds($lead)));

    // Chaque garde, puis chaque ouverture qu'elle précède.
    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        $guard = $opensAt($first, $tierIndex)->subMilliseconds($lead);

        expect($next($guard))->toBe(WireTime::iso($opensAt($first, $tierIndex)));

        $following = $tierIndex < $game->frames_per_round
            ? $opensAt($first, $tierIndex + 1)->subMilliseconds($lead)
            : EngineFixtures::durationEnd($first);

        expect($next($opensAt($first, $tierIndex)))->toBe(WireTime::iso($following));
    }

    // Clôture à D : le début de la révélation ; en révélation, la garde du
    // palier 1 de la manche suivante, avant la fin de révélation qu'elle
    // précède ; à cette garde, l'ouverture de la manche suivante.
    $durationEnd = EngineFixtures::durationEnd($first);
    $revealStartsAt = $durationEnd->addMilliseconds($game->tier_grace_ms);

    expect($next($durationEnd))->toBe(WireTime::iso($revealStartsAt));

    $second = EngineFixtures::round($game, 2);
    $revealing = resyncPacketAt($this, $room, $seat, $token, $revealStartsAt);
    $secondGuard = $opensAt($second, 1)->subMilliseconds($lead);

    expect($revealing['round']['phase'])->toBe('revealing')
        ->and($revealing['nextTransitionAt'])->toBe(WireTime::iso($secondGuard))
        ->and($secondGuard->lessThan($first->refresh()->reveal_ends_at ?? throw new LogicException('Manche sans fin de révélation.')))->toBeTrue()
        ->and($next($secondGuard))->toBe(WireTime::iso($opensAt($second, 1)));
});

it("une resynchronisation en cours de manche ne modifie aucun score d'autrui", function (): void {
    resyncPacketEngine();
    $target = SubmissionFixtures::movie('Night Harbor', 'Port de nuit');
    [$game, $room, [$finder, $other, $second], [$finderToken, $otherToken, $secondToken]] = resyncPacketGame(SubmissionFixtures::settings(), seats: 3, target: $target);
    $round = EngineFixtures::round($game, 1);
    $opensAt = EngineFixtures::opensAt($round, 1);

    // Deux sièges trouvent, dans cet ordre ; le troisième cherche encore.
    resyncPacketAt($this, $room, $finder, $finderToken, $opensAt->addSecond());
    SubmissionFixtures::submit($this, $finder, $finderToken, 'Night Harbor', $opensAt->addMilliseconds(1_500))->assertOk();
    SubmissionFixtures::submit($this, $second, $secondToken, 'Night Harbor', $opensAt->addMilliseconds(1_800))->assertOk();

    $guess = SubmissionFixtures::guess($round, $finder);
    $snapshot = static fn (): array => [
        DB::table('guess')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('round_player')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('game_player')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
    ];
    $before = $snapshot();

    // L'autre siège se resynchronise : le verrouillé ne lui apparaît que par
    // son rang, le classement reste gelé à la dernière manche révélée (ici,
    // aucune), et aucun point de la manche en cours ne lui parvient.
    $seen = resyncPacketAt($this, $room, $other, $otherToken, $opensAt->addSeconds(2));
    $rows = collect($seen['leaderboard']['rows'])->keyBy('publicId');

    expect($seen['round']['locked'])->toBe([
        ['publicId' => $finder->public_id, 'lockRank' => 1],
        ['publicId' => $second->public_id, 'lockRank' => 2],
    ])
        ->and($seen['leaderboard'])->toBe(Scoreboard::leaderboard($game->refresh()))
        ->and($seen['leaderboard']['roundNumber'])->toBeNull()
        ->and($rows[$finder->public_id]['score'])->toBe(0)
        ->and($rows[$finder->public_id]['correctAnswers'])->toBe(0)
        ->and($rows[$finder->public_id]['roundDelta'])->toBe(0)
        ->and($rows[$second->public_id]['score'])->toBe(0)
        ->and($seen['self']['ownScore'])->toBe(0)
        ->and($seen['self']['input']['locked'] ?? null)->toBeNull()
        ->and(array_intersect(resyncPacketKeys($seen), ['pointsTier', 'pointsBonus', 'pointsTotal', 'finders']))->toBe([]);

    // Le verrouillé, lui, lit ses points — et le même classement gelé.
    $own = resyncPacketAt($this, $room, $finder, $finderToken, $opensAt->addSeconds(2));

    expect($own['self']['ownScore'])->toBe($guess->points_total)
        ->and($guess->points_total)->toBeGreaterThan(0)
        ->and($own['self']['input'])->toBe(SeatInputView::forSeat(SubmissionFixtures::participation($round, $finder))->toArray())
        ->and($own['self']['input']['locked']['pointsTotal'] ?? null)->toBe($guess->points_total)
        ->and($own['leaderboard'])->toBe($seen['leaderboard']);

    // Aucune écriture de score, de saisie ni d'agrégat par ces lectures.
    expect($snapshot())->toBe($before);

    // Les points ne deviennent publics qu'à la révélation (E10-52).
    $revealed = resyncPacketAt($this, $room, $other, $otherToken, EngineFixtures::durationEnd($round)->addMilliseconds($game->tier_grace_ms));
    $publicRows = collect($revealed['leaderboard']['rows'])->keyBy('publicId');

    expect($revealed['round']['phase'])->toBe('revealing')
        ->and($revealed['leaderboard']['roundNumber'])->toBe(1)
        ->and($publicRows[$finder->public_id]['score'])->toBe($guess->points_total)
        ->and($publicRows[$finder->public_id]['roundDelta'])->toBe($guess->points_total)
        ->and($revealed['round']['reveal']['finders'][0]['pointsTotal'] ?? null)->toBe($guess->points_total)
        ->and(SubmissionFixtures::guess($round, $finder)->getAttributes())->toBe($guess->getAttributes());
});

it("le QCM déjà composé est rejoué à l'identique après un changement de langue", function (): void {
    resyncPacketEngine();
    SubmissionFixtures::decoyCandidates();
    $target = SubmissionFixtures::movie('Night Harbor', 'Port de nuit');
    [$game, $room, [$seat], [$token]] = resyncPacketGame(SubmissionFixtures::settings(InputDifficulty::Normal), target: $target);
    $round = EngineFixtures::round($game, 1);
    $choicesAt = EngineFixtures::opensAt($round, (int) $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round));

    // Avant le palier du QCM : aucune proposition.
    expect(resyncPacketAt($this, $room, $seat, $token, $choicesAt->subMilliseconds(1))['self']['input']['choices'])->toBeNull();

    // À T_N, le rattrapage ouvre le palier et compose le QCM dans la langue
    // du siège : le titre français du film est l'une des quatre chaînes.
    $french = resyncPacketAt($this, $room, $seat, $token, $choicesAt->addMilliseconds(1));
    $choices = $french['self']['input']['choices'];

    expect($choices['choices'])->toHaveCount(4)
        ->toContain('Port de nuit')
        ->not->toContain('Night Harbor')
        ->and($choices['lang'])->toBe(Locale::French->bcp47());

    $rows = RoundChoiceSet::query()->where('round_id', $round->id)->orderBy('id')->get()
        ->map(static fn (RoundChoiceSet $row): array => $row->getAttributes())->all();

    expect($rows)->toHaveCount(count(Locale::cases()));

    // Le joueur passe l'interface en anglais : siège et jeton.
    $seat->forceFill(['locale' => Locale::English])->save();
    $english = $token->withLocale(Locale::English);

    $replayed = resyncPacketAt($this, $room, $seat->refresh(), $english, $choicesAt->addSeconds(2));

    // Les mêmes quatre chaînes, dans le même ordre, de la même langue —
    // jamais recomposées (05 § QCM, contrat C11).
    expect($replayed['self']['input']['choices'])->toBe($choices)
        ->and($replayed['self']['input']['choices'])->toBe(
            app(ChoicesPresenter::class)->forSeat(SubmissionFixtures::participation($round, $seat))?->toArray(),
        )
        ->and($replayed['self']['input']['choices']['choices'])->not->toContain('Night Harbor')
        ->and(SubmissionFixtures::participation($round, $seat)->choices_locale)->toBe(Locale::French)
        // Aucune composition de plus : une ligne par locale activée, écrite à T_N.
        ->and(RoundChoiceSet::query()->where('round_id', $round->id)->orderBy('id')->get()
            ->map(static fn (RoundChoiceSet $row): array => $row->getAttributes())->all())->toBe($rows);
});

it('le paquet porte maxAnswerLength du snapshot en partie et null sans partie', function (): void {
    resyncPacketEngine();
    $settings = RoomSettings::fromInput([
        'roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT,
        'maxAnswerLength' => RoomSettingsBounds::MIN_ANSWER_LENGTH,
    ]);

    expect(RoomSettingsBounds::MIN_ANSWER_LENGTH)->not->toBe(RoomSettingsBounds::DEFAULT_ANSWER_LENGTH);

    [$game, $room, [$seat], [$token]] = resyncPacketGame($settings);

    // En partie — au lancement comme en manche — : la valeur figée au lancement.
    expect(resyncPacketAt($this, $room, $seat, $token, Date::now()->toImmutable())['maxAnswerLength'])
        ->toBe($game->settings_snapshot->maxAnswerLength)
        ->toBe(RoomSettingsBounds::MIN_ANSWER_LENGTH)
        ->and(resyncPacketAt($this, $room, $seat, $token, EngineFixtures::opensAt(EngineFixtures::round($game, 1), 1))['maxAnswerLength'])
        ->toBe(RoomSettingsBounds::MIN_ANSWER_LENGTH);

    // En solo aussi, par le même constructeur.
    $solo = EngineFixtures::game($settings, solo: true);
    $soloSeat = EngineFixtures::seat($solo);

    expect(GameStateBuilder::build($solo, $soloSeat, Date::now()->toImmutable(), null)['maxAnswerLength'])
        ->toBe(RoomSettingsBounds::MIN_ANSWER_LENGTH);

    // Sans partie : nul — lobby d'un salon, solo pas encore lancé.
    $lobby = Room::factory()->withSettings($settings)->create();
    $lobbyToken = PlayerToken::mint(Locale::French);
    Player::factory()->create(['room_id' => $lobby->id, 'player_token_hash' => $lobbyToken->hash()]);

    expect(resyncPacketFetch($this, $lobby, $lobbyToken)->assertOk()->json('maxAnswerLength'))->toBeNull()
        ->and(GameStateBuilder::build(null, Player::factory()->solo()->create(), Date::now()->toImmutable(), null)['maxAnswerLength'])->toBeNull();
});

it('dans les preload_lead_ms finales de la révélation, le paquet porte la manche suivante et la seule URL de son palier 1', function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings());
    $first = EngineFixtures::round($game, 1);
    $second = EngineFixtures::round($game, 2);
    $revealStartsAt = EngineFixtures::durationEnd($first)->addMilliseconds($game->tier_grace_ms);

    // Révélation de la manche 1 : ses paliers ouverts ; la manche 2 est
    // programmée à reveal_ends_at(1).
    $revealing = resyncPacketAt($this, $room, $seat, $token, $revealStartsAt);
    $revealEndsAt = $first->refresh()->reveal_ends_at ?? throw new LogicException('Manche sans fin de révélation.');
    $secondOpensAt = EngineFixtures::opensAt($second, 1);
    $guard = $secondOpensAt->subMilliseconds($game->preload_lead_ms);

    expect($revealing['round']['sequenceIndex'])->toBe(1)
        ->and(resyncPacketTiers($revealing))->toBe(range(1, $game->frames_per_round))
        ->and($secondOpensAt->equalTo($revealEndsAt))->toBeTrue();

    // Une milliseconde avant la garde du palier 1 suivant : encore la révélation.
    $before = resyncPacketAt($this, $room, $seat, $token, $guard->subMilliseconds(1));

    expect($before['round']['sequenceIndex'])->toBe(1)
        ->and($before['round']['phase'])->toBe('revealing')
        ->and(resyncPacketTiers($before))->toBe(range(1, $game->frames_per_round))
        ->and($before['nextTransitionAt'])->toBe(WireTime::iso($guard));

    // De la garde à T₁(k+1) : la manche suivante, programmée, et la seule
    // URL de son palier 1 — aucune de la manche révélée, aucun titre.
    foreach ([$guard, $secondOpensAt->subMilliseconds(1)] as $at) {
        $packet = resyncPacketAt($this, $room, $seat, $token, $at);

        expect($packet['round']['sequenceIndex'])->toBe(2)
            ->and($packet['round']['phase'])->toBe('scheduled')
            ->and($packet['round']['currentTierIndex'])->toBeNull()
            ->and($packet['round']['images'])->toBe([resyncPacketImage($game, $second, 1)])
            ->and($packet['round']['reveal'])->toBeNull()
            ->and($packet['round']['locked'])->toBe([])
            ->and($packet['self']['participates'])->toBeFalse()
            ->and($packet['self']['member'])->toBeTrue()
            // Le classement reste celui de la manche en révélation.
            ->and($packet['leaderboard'])->toBe(Scoreboard::leaderboard($game->refresh(), $first->refresh()))
            ->and($packet['nextTransitionAt'])->toBe(WireTime::iso($secondOpensAt));

        $json = json_encode($packet, JSON_THROW_ON_ERROR);

        foreach (resyncPacketTokens($first, range(1, $game->frames_per_round)) as $serveToken) {
            expect($json)->not->toContain($serveToken);
        }

        foreach (resyncPacketTitles(Movie::query()->findOrFail($first->movie_id)) as $title) {
            expect($json)->not->toContain($title);
        }
    }
});

it('la resynchronisation rattrape les étapes échues avant de construire le paquet', function (): void {
    $journal = GameJournalRecorder::start();

    try {
        resyncPacketEngine();
        [$game, $room, [$seat, $other], [$token]] = resyncPacketGame(EngineFixtures::settings(), seats: 2);
        $round = EngineFixtures::round($game, 1);
        $at = EngineFixtures::opensAt($round, 2)->addSecond();

        // Aucun job n'a tourné : la manche est encore programmée en base, et
        // le constructeur seul la décrit telle quelle, sans rien écrire.
        Date::setTestNow($at);
        $stale = GameStateBuilder::build($game->refresh(), $seat, $at, $seat->active_seat_token);

        expect($stale['round']['phase'] ?? null)->toBe('scheduled')
            ->and(EngineFixtures::tier($round, 1)->served_at)->toBeNull()
            ->and(RoundPlayer::query()->where('round_id', $round->id)->count())->toBe(0);

        // La route rattrape d'abord : paliers 1 et 2 ouverts à leurs instants
        // THÉORIQUES, participations nées à T₁, puis le paquet de l'état échu.
        $packet = resyncPacketAt($this, $room, $seat, $token, $at);

        expect($packet['round']['phase'])->toBe('running')
            ->and($packet['round']['currentTierIndex'])->toBe(2)
            ->and($packet['round']['images'])->toBe([resyncPacketImage($game, $round, 2)])
            ->and(EngineFixtures::tier($round, 1)->served_at?->equalTo(EngineFixtures::opensAt($round, 1)))->toBeTrue()
            ->and(EngineFixtures::tier($round, 2)->served_at?->equalTo(EngineFixtures::opensAt($round, 2)))->toBeTrue()
            ->and(EngineFixtures::tier($round, 3)->served_at)->toBeNull()
            ->and(RoundPlayer::query()->where('round_id', $round->id)->pluck('player_id')->sort()->values()->all())
            ->toBe(collect([$seat->id, $other->id])->sort()->values()->all())
            ->and($packet['self']['participates'])->toBeTrue()
            ->and($packet['self']['input']['inputState'] ?? null)->toBe(RoundPlayerInputState::Open->value);

        // Une ligne au journal `game` par resynchronisation de partie, rien
        // du paquet ; aucune pour un salon au lobby.
        $lobby = Room::factory()->create();
        $lobbyToken = PlayerToken::mint(Locale::French);
        Player::factory()->create(['room_id' => $lobby->id, 'player_token_hash' => $lobbyToken->hash()]);
        resyncPacketFetch($this, $lobby, $lobbyToken)->assertOk();

        expect($journal->contexts(GameJournal::RESYNCHRONIZED))->toBe([
            ['gameRef' => GameRef::for($game), 'mode' => 'multiplayer'],
        ]);
    } finally {
        $journal->stop();
    }
});

// --- Branche de partie : un champ hors manche par test (E85-2) ----------------

it("l'enveloppe d'un paquet de partie porte le gameRef de la partie", function (): void {
    $launchedAt = resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings());

    $packet = resyncPacketAt($this, $room, $seat, $token, $launchedAt->addMilliseconds(4));

    expect(array_slice($packet, 0, 3, true))->toBe([
        'v' => 1,
        'serverNow' => WireTime::iso($launchedAt->addMilliseconds(4)),
        'gameRef' => GameRef::for($game),
    ])
        ->and($packet['gameRef'])->toMatch('/^[0-9a-f]{16}$/');
});

it('le mode et les canaux sont ceux de la partie, nuls en solo', function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings());

    $packet = resyncPacketAt($this, $room, $seat, $token, Date::now()->toImmutable());

    expect($packet['mode'])->toBe('multiplayer')
        ->and($packet['channels'])->toBe(['room' => ChannelNames::room($room), 'seat' => ChannelNames::seat($seat)]);

    $solo = EngineFixtures::game(EngineFixtures::settings(), solo: true);
    $soloSeat = EngineFixtures::seat($solo);
    $soloPacket = GameStateBuilder::build($solo, $soloSeat, Date::now()->toImmutable(), null);

    expect($soloPacket['mode'])->toBe('solo')
        ->and($soloPacket['channels'])->toBeNull()
        ->and($soloPacket['gameRef'])->toBe(GameRef::for($solo))
        ->and($soloPacket['self']['isHost'])->toBeFalse()
        ->and(array_column($soloPacket['seats'], 'publicId'))->toBe([$soloSeat->public_id]);

    // Un siège étranger au salon de la partie n'en reçoit jamais le paquet.
    expect(fn () => GameStateBuilder::build($solo, $seat, Date::now()->toImmutable(), null))->toThrow(LogicException::class);
});

it('status, roundsCount, roundsCompleted, framesPerRound et inputDifficulty sont les colonnes de la partie', function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(SubmissionFixtures::settings(InputDifficulty::Expert));
    $first = EngineFixtures::round($game, 1);

    $columns = static fn (array $packet): array => array_intersect_key($packet, array_flip([
        'status', 'roundsCount', 'roundsCompleted', 'framesPerRound', 'inputDifficulty',
    ]));

    expect($columns(resyncPacketAt($this, $room, $seat, $token, Date::now()->toImmutable())))->toBe([
        'status' => GameStatus::Running->value,
        'roundsCount' => $game->rounds_count,
        'roundsCompleted' => 0,
        'framesPerRound' => $game->frames_per_round,
        'inputDifficulty' => InputDifficulty::Expert->value,
    ]);

    // La manche 1 révélée puis close : une manche jouée.
    resyncPacketAt($this, $room, $seat, $token, EngineFixtures::durationEnd($first)->addMilliseconds($game->tier_grace_ms));
    $after = resyncPacketAt($this, $room, $seat, $token, EngineFixtures::opensAt(EngineFixtures::round($game, 2), 1));

    expect($first->refresh()->status->value)->toBe('completed')
        ->and($columns($after)['roundsCompleted'])->toBe(1)
        ->and($columns($after)['roundsCompleted'])->toBe($game->refresh()->rounds_completed);
});

it('les sièges sont les lignes game_player gelées, parties et expulsées comprises, par game_player.id', function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings(), seats: 1);
    $left = EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Left, 'left_at' => Date::now()], status: GamePlayerStatus::Left);
    $kicked = EngineFixtures::seat($game, [], status: GamePlayerStatus::Kicked);
    $kicked->forceFill(['kicked_at' => Date::now(), 'left_at' => Date::now(), 'connection_state' => PlayerConnectionState::Left])->save();
    $late = EngineFixtures::seat($game, [], firstRoundNumber: 2);

    // Le pseudo courant change après le lancement : le paquet garde l'identité gelée.
    $frozen = PlayerIdentity::fromGamePlayer(GamePlayer::query()
        ->where('player_id', $seat->id)
        ->with('player:'.implode(',', SeatViewPresenter::GAME_SEAT_COLUMNS))
        ->firstOrFail())->toArray();
    $seat->forceFill(['nickname' => 'Renommée'])->save();

    $packet = resyncPacketAt($this, $room, $seat, $token, Date::now()->toImmutable());
    $views = collect($packet['seats'])->keyBy('publicId');

    expect($packet['seats'])->toBe(SeatViewPresenter::gameSeats($game, $room->host_player_id))
        ->and(array_column($packet['seats'], 'publicId'))->toBe([$seat->public_id, $left->public_id, $kicked->public_id, $late->public_id])
        ->and($views[$seat->public_id]['nickname'])->toBe($frozen['nickname'])
        ->and($views[$seat->public_id]['isHost'])->toBeTrue()
        ->and($views[$left->public_id]['connection'])->toBe(PlayerConnectionState::Left->value)
        ->and($views[$kicked->public_id]['kicked'])->toBeTrue()
        ->and($views[$late->public_id]['firstRoundNumber'])->toBe(2);
});

it('une partie en pause porte pausedAt et interruptsAt, et aucune manche', function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings(roundsCount: 3));
    $first = EngineFixtures::round($game, 1);

    // Deux retardataires admis aux manches 2 et 3, absents : la pause ne les
    // attend pas (§ 1.2), et leur `self.member` se lit sans manche portée.
    $absent = ['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()];
    $late2 = EngineFixtures::seat($game, $absent, firstRoundNumber: 2);
    $late3 = EngineFixtures::seat($game, $absent, firstRoundNumber: 3);

    // La manche 1 jouée, le seul siège déconnecté avant la fin de sa révélation.
    $revealing = resyncPacketAt($this, $room, $seat, $token, EngineFixtures::durationEnd($first)->addMilliseconds($game->tier_grace_ms));
    expect($revealing['pause'])->toBeNull();

    $seat->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()])->save();
    $pausedAt = $first->refresh()->reveal_ends_at ?? throw new LogicException('Manche sans fin de révélation.');
    $interruptsAt = $pausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs());

    $paused = resyncPacketAt($this, $room, $seat, $token, $pausedAt);

    expect($paused['status'])->toBe(GameStatus::Paused->value)
        ->and($paused['pause'])->toBe(['pausedAt' => WireTime::iso($pausedAt), 'interruptsAt' => WireTime::iso($interruptsAt)])
        ->and($paused['round'])->toBeNull()
        ->and($paused['nextTransitionAt'])->toBe(WireTime::iso($interruptsAt))
        // Sans manche portée, l'éligibilité se lit contre la manche suivante à
        // jouer : la 2 (paquet par le constructeur, les retardataires absents).
        ->and(GameStateBuilder::build($game->refresh(), $late2->refresh(), $pausedAt, null)['self']['member'])->toBeTrue()
        ->and(GameStateBuilder::build($game->refresh(), $late3->refresh(), $pausedAt, null)['self']['member'])->toBeFalse();

    // À l'échéance, le rattrapage interrompt la partie : plus de pause.
    $interrupted = resyncPacketAt($this, $room, $seat, $token, $interruptsAt);

    expect($interrupted['status'])->toBe(GameStatus::Interrupted->value)
        ->and($interrupted['pause'])->toBeNull()
        ->and($interrupted['nextTransitionAt'])->toBeNull()
        ->and($interrupted['podium'])->toBe(Scoreboard::podium($game->refresh()))
        // Partie close, manches 2 et 3 jamais jouées : la référence reste la 2.
        ->and(GameStateBuilder::build($game->refresh(), $late2->refresh(), $interruptsAt, null)['self']['member'])->toBeTrue()
        ->and(GameStateBuilder::build($game->refresh(), $late3->refresh(), $interruptsAt, null)['self']['member'])->toBeFalse();
});

it('self.member suit la manche portée : un retardataire en attente n\'est pas membre, puis l\'est à sa manche', function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings());
    $lateToken = PlayerToken::mint(Locale::English);
    $late = EngineFixtures::seat($game, ['player_token_hash' => $lateToken->hash()], firstRoundNumber: 2);
    $first = EngineFixtures::round($game, 1);
    $inFirst = EngineFixtures::opensAt($first, 1)->addSecond();

    $waiting = resyncPacketAt($this, $room, $late, $lateToken, $inFirst);

    expect($waiting['round']['sequenceIndex'])->toBe(1)
        ->and($waiting['self']['member'])->toBeFalse()
        // En attente : le chrono, mais aucune image (partie 3 de la garde, § 13.7).
        ->and($waiting['round']['images'])->toBe([])
        ->and(resyncPacketAt($this, $room, $seat, $token, $inFirst)['self']['member'])->toBeTrue();

    // La révélation de la manche 1 programme la manche 2, celle du retardataire.
    resyncPacketAt($this, $room, $seat, $token, EngineFixtures::durationEnd($first)->addMilliseconds($game->tier_grace_ms));
    $second = EngineFixtures::round($game, 2);
    $admitted = resyncPacketAt($this, $room, $late, $lateToken, EngineFixtures::opensAt($second, 1)->addSecond());

    expect($admitted['round']['sequenceIndex'])->toBe(2)
        ->and($admitted['self']['member'])->toBeTrue()
        ->and(resyncPacketTiers($admitted))->toBe([1]);

    // Expulsé de la partie, le siège n'est plus membre (le salon lui refuse
    // `room.state` : le paquet se lit par le constructeur).
    GamePlayer::query()->where('player_id', $late->id)->update(['status' => GamePlayerStatus::Kicked->value]);

    expect(GameStateBuilder::build($game->refresh(), $late->refresh(), Date::now()->toImmutable(), null)['self']['member'])->toBeFalse();
});

it('self.participates et self.input suivent la ligne round_player de la manche portée', function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings());
    $first = EngineFixtures::round($game, 1);

    // Manche programmée : aucune ligne, aucune saisie.
    $scheduled = resyncPacketAt($this, $room, $seat, $token, Date::now()->toImmutable());

    expect($scheduled['self']['participates'])->toBeFalse()
        ->and($scheduled['self']['input'])->toBeNull();

    // Manche ouverte : la ligne née à T₁, et la vue de saisie de 70.
    $running = resyncPacketAt($this, $room, $seat, $token, EngineFixtures::opensAt($first, 1)->addSecond());

    expect($running['self']['participates'])->toBeTrue()
        ->and($running['self']['input'])->toBe(SeatInputView::forSeat(SubmissionFixtures::participation($first, $seat))->toArray())
        ->and($running['self']['input']['attemptsLeft'])->toBe($game->settings_snapshot->attemptsPerRound);
});

it('self.ownScore est le score du seul siège, portée Own, manche en cours comprise', function (): void {
    resyncPacketEngine();
    $target = SubmissionFixtures::movie('Night Harbor', 'Port de nuit');
    [$game, $room, [$seat], [$token]] = resyncPacketGame(SubmissionFixtures::settings(), target: $target);
    $round = EngineFixtures::round($game, 1);
    $opensAt = EngineFixtures::opensAt($round, 1);

    expect(resyncPacketAt($this, $room, $seat, $token, $opensAt)['self']['ownScore'])->toBe(0);

    SubmissionFixtures::submit($this, $seat, $token, 'Night Harbor', $opensAt->addSecond())->assertOk();
    $packet = resyncPacketAt($this, $room, $seat, $token, $opensAt->addSeconds(2));

    expect($packet['self']['ownScore'])->toBe(Scoreboard::seatScore($game, $seat)['ownScore'])
        ->and($packet['self']['ownScore'])->toBe(SubmissionFixtures::guess($round, $seat)->points_total)
        ->and($packet['self']['ownScore'])->toBeGreaterThan(0);
});

it('le classement est gelé à la dernière manche révélée et le podium rejoué à l\'identique après le gel', function (): void {
    resyncPacketEngine();
    [$game, $room, [$seat], [$token]] = resyncPacketGame(EngineFixtures::settings());
    $first = EngineFixtures::round($game, 1);

    $running = resyncPacketAt($this, $room, $seat, $token, EngineFixtures::opensAt($first, 1)->addSecond());

    expect($running['leaderboard'])->toBe(Scoreboard::leaderboard($game->refresh()))
        ->and($running['podium'])->toBeNull();

    $revealing = resyncPacketAt($this, $room, $seat, $token, EngineFixtures::durationEnd($first)->addMilliseconds($game->tier_grace_ms));

    expect($revealing['leaderboard'])->toBe(Scoreboard::leaderboard($game->refresh(), $first->refresh()))
        ->and($revealing['leaderboard']['roundNumber'])->toBe(1)
        ->and($revealing['podium'])->toBeNull();

    // Gel (interruption) : le podium, rejoué à l'identique tant que le salon
    // reste `playing`.
    $frozenAt = $revealing['round']['revealEndsAt'];
    Date::setTestNow(CarbonImmutable::parse($frozenAt));
    app(FinalizeGame::class)->handle($game->refresh(), GameStatus::Interrupted, CarbonImmutable::parse($frozenAt));

    $podium = resyncPacketAt($this, $room, $seat, $token, CarbonImmutable::parse($frozenAt)->addSecond());
    $replayed = resyncPacketAt($this, $room, $seat, $token, CarbonImmutable::parse($frozenAt)->addMinutes(5));

    expect($podium['podium'])->toBe(Scoreboard::podium($game->refresh()))
        ->and($replayed['podium'])->toBe($podium['podium'])
        ->and($replayed['round'])->toBeNull()
        ->and($replayed['nextTransitionAt'])->toBeNull();
});

it('la forme du paquet de partie suit GameStatePacket, RoundState et SelfState de game-wire.ts', function (): void {
    resyncPacketEngine();
    $target = SubmissionFixtures::movie('Night Harbor', 'Port de nuit');
    [$game, $room, [$seat], [$token]] = resyncPacketGame(SubmissionFixtures::settings(), target: $target);
    $round = EngineFixtures::round($game, 1);

    $source = FrontSource::withoutComments((string) file_get_contents(resource_path('js/types/game-wire.ts')));
    $declarations = ScoringTypes::declarations($source);
    $fields = static fn (string $name): array => ScoringTypes::objectFields($declarations[$name])[0];

    // En manche (phase running) puis en révélation (film et trouveurs).
    $packets = [
        resyncPacketAt($this, $room, $seat, $token, EngineFixtures::opensAt($round, 1)->addSecond()),
        resyncPacketAt($this, $room, $seat, $token, EngineFixtures::durationEnd($round)->addMilliseconds($game->tier_grace_ms)),
    ];

    foreach ($packets as $packet) {
        expect(array_keys($packet))->toBe([...$fields('WireEnvelope'), ...$fields('GameStatePacket')])
            ->and(array_keys($packet['self']))->toBe($fields('SelfState'))
            ->and(array_keys($packet['round']))->toBe([...$fields('RoundTimeline'), ...$fields('RoundState')])
            ->and(array_keys($packet['round']['images'][0]))->toBe($fields('TierImageRef'));
    }

    expect(array_keys($packets[1]['round']['reveal'] ?? []))->toBe(['movie', 'frames', 'finders'])
        ->and(array_keys($packets[1]['round']['reveal']['movie']))->toBe($fields('RevealMovie'));
});
