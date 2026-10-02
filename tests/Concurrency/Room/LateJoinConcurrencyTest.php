<?php

use App\Actions\Game\OpenTier;
use App\Actions\Room\TakeSeat;
use App\Enums\GamePlayerStatus;
use App\Enums\Locale;
use App\Enums\RoomStatus;
use App\Enums\RoundStatus;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\BroadcastLobbyState;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Settings\EngineConstants;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\HostGestures;

/*
|--------------------------------------------------------------------------
| Concurrence de l'admission d'un retardataire — spec 50 § 15.2 (lot L50-9)
|--------------------------------------------------------------------------
|
| Groupe `locks-timing` (par répertoire), MySQL réel, `DatabaseTruncation` :
| deux connexions voient les écritures l'une de l'autre.
|
| « Le verrou de la manche sérialise l'admission avec son ouverture ; sans
| lui, un retardataire admis à la milliseconde de `T₁` n'aurait ni la manche
| courante ni sa ligne. » La prise de siège suit l'ordre global `room →
| player → game → round` : la dernière partie du salon est relue `FOR
| UPDATE` (un gel concurrent n'est jamais lu périmé), puis la manche
| candidate est lue `FOR UPDATE` et son prédicat relu sur la ligne
| verrouillée. `OpenTier(1)` prend `room` en PARTAGÉ (le verrou que la clé
| étrangère de `seen_frame` prendrait sinon en dernier, fermant un cycle
| avec l'admission), puis `game → round → round_tier` : entre ces deux
| gestes réels, la première attente naît donc dès la ligne `room` — l'un la
| tient en partagé, l'autre la demande en exclusif —, et la décision se
| prend sous le verrou de la manche. Trois entrelacements :
|
| 1. l'ouverture d'abord : l'admission attend dès `room` (S1), puis relit la
|    manche ouverte et entre à la suivante — jamais à la manche en cours ;
| 2. l'admission d'abord : l'ouverture attend dès `room` (verrou partagé),
|    puis crée la ligne `round_player` du retardataire admis à cette manche ;
| 3. le verrou de la manche à lui seul, seule preuve de ce verrou : un
|    écrivain qui ne tiendrait que la ligne `round` (ni salon, ni partie)
|    fait attendre la lecture de la candidate, qui relit la manche passée
|    `running` et entre à la suivante.
|
| L'entrelacement est rendu déterministe, sans processus ni horloge (patron
| de `LaunchConcurrencyTest`) : le premier geste s'exécute sur une connexion
| jumelle, transaction laissée ouverte ; le second, sur la connexion par
| défaut, BUTE sur la ligne verrouillée (`innodb_lock_wait_timeout` de
| session : la borne de l'attente, jamais le verdict) ; le premier valide,
| puis le second est rejoué et relit l'état validé.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class, BroadcastLobbyState::class]);

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 22:40:00.250'));
});

/**
 * Un salon ouvert aux retardataires, en partie sur `M` manches au plus petit
 * admis : l'hôte et un invité (participations du lancement), la manche 1
 * jouée jusqu'au bout par le moteur, la manche 2 programmée par sa
 * révélation. L'horloge reste à la fin de la révélation de la manche 1.
 *
 * @return array{0: Room, 1: Game, 2: Round, 3: Round}
 */
function lateJoinConcurrencyGame(): array
{
    $settings = RoomSettings::fromInput([
        'allowLateJoin' => true,
        'roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT,
    ]);

    [$room, $host] = HostGestures::room($settings);
    [$guest] = HostGestures::seat($room);
    $game = EngineFixtures::game($settings, room: $room);

    foreach ([$host, $guest] as $seat) {
        GamePlayer::factory()->for($game)->frozenFrom($seat, 1)->create(['status' => GamePlayerStatus::Playing]);
    }

    Room::query()->whereKey($room->id)->update([
        'status' => RoomStatus::Playing->value,
        'launched_at' => Date::now(),
    ]);

    EngineFixtures::materialize($game);

    $now = Date::now()->toImmutable();
    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, $now->addMilliseconds(EngineConstants::launchCountdownMs()), $now);
    EngineFixtures::play($first);

    return [$room->refresh(), $game->refresh(), EngineFixtures::round($game, 2), EngineFixtures::round($game, 3)];
}

/**
 * La prise de siège d'un nouveau venu, sans jeton, comme la route l'appelle.
 */
function lateJoinConcurrencySeat(Room $room, string $nickname): Player
{
    $outcome = app(TakeSeat::class)->handle(
        $room,
        Request::create('/r/'.$room->room_code.'/join', 'POST'),
        $nickname,
        Locale::French,
    );

    return $outcome instanceof Player ? $outcome : throw new LogicException('Prise de siège refusée : '.$outcome->value);
}

/** La manche d'entrée du siège dans la partie, `null` sans participation. */
function lateJoinConcurrencyFirstRound(Game $game, Player $seat): ?int
{
    $value = GamePlayer::query()->whereBelongsTo($game)->where('player_id', $seat->id)->value('first_round_number');

    return is_int($value) ? $value : null;
}

/**
 * Joue `$first` sur une connexion jumelle, transaction laissée ouverte, puis
 * `$second` sur la connexion par défaut, qui doit buter sur une ligne que
 * tient la jumelle ; `$whileHeld` s'exécute avant la validation de la
 * jumelle. Rend ce que `$first` a rendu et l'exception du second.
 *
 * @template T
 *
 * @param  Closure(): T  $first
 * @param  Closure(): mixed  $second
 * @param  Closure(): void  $whileHeld
 * @return array{0: T, 1: QueryException|null}
 */
function lateJoinConcurrencyInterleave(string $rival, Closure $first, Closure $second, Closure $whileHeld): array
{
    $default = DB::getDefaultConnection();

    config(["database.connections.{$rival}" => config("database.connections.{$default}")]);

    $blocked = null;

    try {
        DB::connection($rival)->beginTransaction();
        DB::setDefaultConnection($rival);

        $result = $first();

        DB::setDefaultConnection($default);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $second();
        } catch (QueryException $exception) {
            $blocked = $exception;
        }

        $whileHeld();

        DB::connection($rival)->commit();
    } finally {
        DB::setDefaultConnection($default);

        if (DB::connection($rival)->transactionLevel() > 0) {
            DB::connection($rival)->rollBack();
        }

        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        DB::purge($rival);
    }

    return [$result, $blocked];
}

/**
 * Le second geste a buté sur une lecture verrouillante de `$table` (MySQL
 * 1205 : attente de verrou dépassée), exclusive (`for update`) ou partagée
 * (`lock in share mode`, clause de `sharedLock()` sous MySQL).
 */
function lateJoinConcurrencyAssertBlockedOn(?QueryException $blocked, string $table, string $lock = 'for update'): void
{
    $sql = strtolower((string) $blocked?->getSql());

    expect($blocked)->toBeInstanceOf(QueryException::class)
        ->and($blocked?->errorInfo[1] ?? null)->toBe(1205)
        ->and($sql)->toContain("from `{$table}`")
        ->and($sql)->toContain($lock);
}

test("sérialise l'admission avec l'ouverture de la manche sous le verrou de la manche", function (): void {
    // 1. L'ouverture d'abord. `OpenTier(1)` de la manche 2 tient le salon en
    // partagé, la partie et la manche ; un nouveau venu arrivé une
    // milliseconde avant `T₁` attend dès le verrou du salon, puis relit la
    // manche 2 ouverte : il entre à la manche 3, jamais à la manche en cours,
    // et n'a aucune ligne dans la manche 2.
    [$room, $game, $second, $third] = lateJoinConcurrencyGame();
    $opensAt = EngineFixtures::opensAt($second, 1);

    [, $blocked] = lateJoinConcurrencyInterleave(
        'late_join_open_rival',
        static fn () => EngineFixtures::openTier($second, 1, $opensAt),
        static function () use ($room, $opensAt): void {
            Date::setTestNow($opensAt->subMillisecond());
            lateJoinConcurrencySeat($room, 'Retard');
        },
        static function () use ($room, $second): void {
            // Rien du geste bloqué n'a survécu à son échec, et l'ouverture
            // n'est pas encore visible.
            expect(Player::query()->whereBelongsTo($room)->where('nickname', 'Retard')->exists())->toBeFalse()
                ->and(Round::query()->whereKey($second->id)->value('status'))->toBe(RoundStatus::Pending);
        },
    );

    lateJoinConcurrencyAssertBlockedOn($blocked, 'room');

    expect($second->refresh()->status)->toBe(RoundStatus::Running);

    Date::setTestNow($opensAt->subMillisecond());
    $late = lateJoinConcurrencySeat($room, 'Retard');

    expect(lateJoinConcurrencyFirstRound($game, $late))->toBe($third->round_number)
        ->and(RoundPlayer::query()->where('round_id', $second->id)->where('player_id', $late->id)->exists())->toBeFalse();

    // 2. L'admission d'abord. Un nouveau venu admis une milliseconde avant
    // `T₁` tient le salon, la partie et la manche 2 ; `OpenTier(1)` attend dès
    // le verrou partagé du salon, puis crée sa ligne `round_player` : il joue
    // la manche où il a été admis.
    [$room, $game, $second] = lateJoinConcurrencyGame();
    $opensAt = EngineFixtures::opensAt($second, 1);
    Date::setTestNow($opensAt->subMillisecond());

    [$admitted, $blocked] = lateJoinConcurrencyInterleave(
        'late_join_seat_rival',
        static fn (): Player => lateJoinConcurrencySeat($room, 'Admis'),
        static function () use ($second, $opensAt): void {
            Date::setTestNow($opensAt);
            app(OpenTier::class)->handle(EngineFixtures::tier($second, 1), $opensAt);
        },
        static function () use ($second): void {
            expect(Round::query()->whereKey($second->id)->value('status'))->toBe(RoundStatus::Pending)
                ->and(RoundPlayer::query()->where('round_id', $second->id)->exists())->toBeFalse();
        },
    );

    lateJoinConcurrencyAssertBlockedOn($blocked, 'room', 'lock in share mode');

    expect(lateJoinConcurrencyFirstRound($game, $admitted))->toBe($second->round_number)
        ->and($second->refresh()->status)->toBe(RoundStatus::Pending);

    EngineFixtures::openTier($second, 1, $opensAt);

    expect($second->refresh()->status)->toBe(RoundStatus::Running)
        ->and(RoundPlayer::query()->where('round_id', $second->id)->where('player_id', $admitted->id)->exists())->toBeTrue()
        ->and(RoundPlayer::query()->where('round_id', $second->id)->count())->toBe(3);

    // 3. Le verrou de la manche à lui seul. Un écrivain qui ne tient que la
    // ligne de la manche 2, et la fait démarrer, fait attendre la lecture
    // verrouillante de la candidate — la partie, elle, est libre ; repris,
    // le nouveau venu relit la manche passée `running` et entre à la
    // manche 3.
    [$room, $game, $second, $third] = lateJoinConcurrencyGame();
    $opensAt = EngineFixtures::opensAt($second, 1);
    Date::setTestNow($opensAt->subMillisecond());

    [, $blocked] = lateJoinConcurrencyInterleave(
        'late_join_round_rival',
        static function () use ($second): void {
            Round::query()->whereKey($second->id)->lockForUpdate()->firstOrFail();
            Round::query()->whereKey($second->id)->update(['status' => RoundStatus::Running->value]);
        },
        static fn () => lateJoinConcurrencySeat($room, 'Candidat'),
        static function () use ($room): void {
            expect(Player::query()->whereBelongsTo($room)->where('nickname', 'Candidat')->exists())->toBeFalse();
        },
    );

    lateJoinConcurrencyAssertBlockedOn($blocked, 'round');

    $candidate = lateJoinConcurrencySeat($room, 'Candidat');

    expect(lateJoinConcurrencyFirstRound($game, $candidate))->toBe($third->round_number);
});
