<?php

use App\Actions\Room\LaunchGame;
use App\Actions\Room\UpdateRoomSettings;
use App\Enums\Locale;
use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Events\Game\GameLaunched;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\BroadcastLobbyState;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Room\LobbyWrites;

/*
|--------------------------------------------------------------------------
| Concurrence du lancement — spec 50 § 12.2 et § 12.6, contrat C6 § 4
| (lot L50-7b)
|--------------------------------------------------------------------------
|
| Groupe `locks-timing` (par répertoire), MySQL réel, `DatabaseTruncation` :
| deux connexions voient les écritures l'une de l'autre.
|
| Le lancement prend le verrou du salon en PREMIÈRE instruction (L1), comme
| toute écriture de réglages (§ 2.5) : deux lancements, ou un lancement et
| une écriture, se sérialisent sur la ligne `room`. Le second relit sous le
| verrou l'état validé par le premier — statut, réglages — et en tire sa
| décision : un double clic ne crée qu'une partie (invariant 5), et une
| partie ne fige jamais des réglages qu'une écriture concurrente aurait
| changés entre la garde et l'INSERT.
|
| L'entrelacement est rendu déterministe, sans processus ni horloge (patron
| de `FinalizeGameConcurrencyTest`) :
| 1. le premier geste s'exécute sur une connexion jumelle, dans une
|    transaction laissée ouverte — ses écritures sont faites, rien n'est
|    validé ;
| 2. le second, sur la connexion par défaut, BUTE sur la ligne `room` dès sa
|    première requête, la lecture verrouillante (`innodb_lock_wait_timeout`
|    de session : la borne de l'attente, jamais le verdict) ;
| 3. le premier valide ; repris, le second relit l'état validé.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class, BroadcastLobbyState::class]);

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 21:15:00.500'));
});

/**
 * Un salon au lobby aux réglages donnés, son hôte (siège du jeton) et un
 * invité connecté : de quoi lancer.
 *
 * @return array{0: Room, 1: Player}
 */
function launchConcurrencyRoom(RoomSettings $settings): array
{
    [$room, $host] = LobbyWrites::hostedRoom(PlayerToken::mint(Locale::French), $settings);
    Player::factory()->for($room)->create();

    return [$room, $host];
}

/**
 * Joue `$first` sur une connexion jumelle, transaction laissée ouverte, puis
 * `$second` sur la connexion par défaut, qui doit buter sur le verrou du
 * salon ; valide enfin la jumelle. Rend ce que `$first` a rendu, l'exception
 * du second et les requêtes que le second a menées à bien AVANT de buter —
 * `QueryExecuted` ne part jamais pour l'instruction en échec (1205).
 *
 * @template T
 *
 * @param  Closure(): T  $first
 * @param  Closure(): mixed  $second
 * @param  Closure(): void  $whileHeld  Assertions pendant que la jumelle tient le verrou.
 * @return array{0: T, 1: QueryException|null, 2: list<string>}
 */
function launchConcurrencyInterleave(string $rival, Closure $first, Closure $second, Closure $whileHeld): array
{
    $default = DB::getDefaultConnection();

    config(["database.connections.{$rival}" => config("database.connections.{$default}")]);

    $blocked = null;
    $before = [];
    $recording = false;

    // Les requêtes abouties de la connexion par défaut pendant le second
    // geste, et pendant lui seul : ni le `SET SESSION` qui le précède, ni les
    // lectures de `$whileHeld`.
    DB::listen(static function (QueryExecuted $query) use (&$before, &$recording, $default): void {
        if ($recording && $query->connectionName === $default) {
            $before[] = $query->sql;
        }
    });

    try {
        // 1. Le premier geste, sur sa propre connexion, transaction ouverte.
        DB::connection($rival)->beginTransaction();
        DB::setDefaultConnection($rival);

        $result = $first();

        // 2. Le second, concurrent : il attend le verrou du salon.
        DB::setDefaultConnection($default);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $recording = true;

        try {
            $second();
        } catch (QueryException $exception) {
            $blocked = $exception;
        } finally {
            $recording = false;
        }

        $whileHeld();

        // 3. Le premier valide.
        DB::connection($rival)->commit();
    } finally {
        DB::setDefaultConnection($default);

        if (DB::connection($rival)->transactionLevel() > 0) {
            DB::connection($rival)->rollBack();
        }

        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        DB::purge($rival);
    }

    return [$result, $blocked, $before];
}

/**
 * Le second geste a buté sur la lecture verrouillante du salon, sa toute
 * première requête (MySQL 1205 : attente de verrou dépassée) : aucune
 * requête n'a abouti avant elle, donc rien — statut, réglages, sièges — n'a
 * été lu hors du verrou pour être réutilisé une fois celui-ci obtenu.
 *
 * @param  list<string>  $before  Requêtes abouties du second geste avant l'échec.
 */
function launchConcurrencyAssertBlockedOnRoom(?QueryException $blocked, array $before): void
{
    expect($blocked)->toBeInstanceOf(QueryException::class)
        ->and($blocked?->errorInfo[1] ?? null)->toBe(1205)
        ->and(strtolower((string) $blocked?->getSql()))->toContain('`room`')
        ->and(strtolower((string) $blocked?->getSql()))->toContain('for update')
        ->and($before)->toBe([]);
}

/**
 * Les écritures SQL exécutées pendant `$callback`.
 *
 * @return list<string>
 */
function launchConcurrencyWrites(Closure $callback): array
{
    $writes = [];

    DB::listen(static function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(?:insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $callback();

    return $writes;
}

test("ne crée qu'une partie pour deux lancements concurrents", function (): void {
    Event::fake([GameLaunched::class]);

    $settings = RoomSettings::fromInput(['roundsCount' => Bounds::MIN_ROUNDS_COUNT]);
    PoolFixtures::movies($settings->roundsCount + PlatformLimits::drawSubstituteMargin());
    [$room, $host] = launchConcurrencyRoom($settings);
    $launch = app(LaunchGame::class);

    [$first, $blocked, $before] = launchConcurrencyInterleave(
        'launch_game_rival',
        static fn () => $launch->handle($room, $host),
        static fn () => $launch->handle($room, $host),
        static function () use ($room): void {
            // Rien du premier lancement n'est encore visible, rien annoncé.
            expect(Game::query()->where('room_id', $room->id)->exists())->toBeFalse()
                ->and(Room::query()->whereKey($room->id)->value('status'))->toBe(RoomStatus::Lobby);
            Event::assertNotDispatched(GameLaunched::class);
        },
    );

    expect($first->isLaunched())->toBeTrue();
    launchConcurrencyAssertBlockedOnRoom($blocked, $before);
    Event::assertDispatchedTimes(GameLaunched::class, 1);

    // Repris après la validation du premier, le second relit le salon en
    // partie : `not_in_lobby`, le double clic idempotent — rien d'écrit,
    // rien d'annoncé.
    $second = null;
    $writes = launchConcurrencyWrites(static function () use ($launch, $room, $host, &$second): void {
        $second = $launch->handle($room, $host);
    });

    expect($second?->refusal)->toBe(RoomRefusal::NotInLobby)
        ->and($writes)->toBe([]);
    Event::assertDispatchedTimes(GameLaunched::class, 1);

    // Une seule partie, ses participations et son tirage.
    $game = Game::query()->where('room_id', $room->id)->sole();

    expect($game->id)->toBe($first->game?->id)
        ->and(GamePlayer::query()->whereBelongsTo($game)->count())->toBe(2)
        ->and(Round::query()->whereBelongsTo($game)->count())->toBe(Round::query()->count())
        ->and($room->refresh()->status)->toBe(RoomStatus::Playing);
});

test("sérialise une écriture de réglages concurrente d'un lancement", function (): void {
    $settings = RoomSettings::fromInput(['roundsCount' => Bounds::MIN_ROUNDS_COUNT]);
    $raised = $settings->roundsCount + 1;
    PoolFixtures::movies($raised + PlatformLimits::drawSubstituteMargin());
    $launch = app(LaunchGame::class);
    $update = app(UpdateRoomSettings::class);

    // Le lancement d'abord : l'écriture attend, puis relit le salon en partie
    // et refuse `not_in_lobby` — les réglages figés ne bougent pas.
    [$room, $host] = launchConcurrencyRoom($settings);

    [$launched, $blocked, $before] = launchConcurrencyInterleave(
        'launch_game_rival',
        static fn () => $launch->handle($room, $host),
        static fn () => $update->handle($room, $host, ['roundsCount' => $raised]),
        static fn () => null,
    );

    expect($launched->isLaunched())->toBeTrue();
    launchConcurrencyAssertBlockedOnRoom($blocked, $before);

    $refused = null;
    $writes = launchConcurrencyWrites(static function () use ($update, $room, $host, $raised, &$refused): void {
        $refused = $update->handle($room, $host, ['roundsCount' => $raised]);
    });

    expect($refused?->refusal)->toBe(RoomRefusal::NotInLobby)
        ->and($writes)->toBe([])
        ->and($room->refresh()->settings->equals($settings))->toBeTrue()
        ->and($launched->game?->refresh()->settings_snapshot->equals($settings))->toBeTrue();

    // L'écriture d'abord : le lancement attend, puis fige les réglages
    // ÉCRITS, jamais ceux qu'il aurait lus avant le verrou.
    [$other, $otherHost] = launchConcurrencyRoom($settings);

    [$written, $blocked, $before] = launchConcurrencyInterleave(
        'update_room_settings_rival',
        static fn () => $update->handle($other, $otherHost, ['roundsCount' => $raised]),
        static fn () => $launch->handle($other, $otherHost),
        static function () use ($other): void {
            expect(Game::query()->where('room_id', $other->id)->exists())->toBeFalse();
        },
    );

    expect($written->refusal)->toBeNull();
    launchConcurrencyAssertBlockedOnRoom($blocked, $before);

    $outcome = $launch->handle($other, $otherHost);
    $game = $outcome->game ?? throw new LogicException('Lancement refusé après l’écriture concurrente.');

    expect($other->refresh()->settings->roundsCount)->toBe($raised)
        ->and($game->rounds_count)->toBe($raised)
        ->and($game->settings_snapshot->equals($other->settings))->toBeTrue()
        ->and(Round::query()->whereBelongsTo($game)->whereNotNull('round_number')->count())->toBe($raised);
});
