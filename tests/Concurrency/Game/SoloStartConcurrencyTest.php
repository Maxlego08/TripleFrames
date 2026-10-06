<?php

use App\Actions\Game\StartSoloGame;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\SettingPresetKey;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Settings\SettingPresetCatalog;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\ValueObjects\Game\SoloStartOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Concurrence du démarrage solo — spec 60 § 16.2 (étape 3), 10 § 7.1,
| E10-N3 (lot L60-15 ; écart (q) du § 22 bis)
|--------------------------------------------------------------------------
|
| Groupe `locks-timing` (par répertoire), MySQL réel, `DatabaseTruncation` :
| deux connexions voient les écritures l'une de l'autre. La garantie ne se
| prouve qu'avec deux connexions : sans siège, aucune ligne n'est
| verrouillable, et l'unicité `(room_id, player_token_hash)` est inopérante
| pour un siège solo (les NULL ne collisionnent pas).
|
| Le démarrage prend en PREMIÈRE instruction SQL la lecture verrouillante du
| siège solo du jeton. Deux premiers lancements sous un même jeton :
| 1. le premier s'exécute sur une connexion jumelle, dans une transaction
|    laissée ouverte — siège inséré, partie née, rien de validé ;
| 2. le second, sur la connexion par défaut, BUTE dès sa première requête
|    sur le siège que la jumelle vient d'insérer (`innodb_lock_wait_timeout`
|    de session : la borne de l'attente, jamais le verdict) ; aucune requête
|    n'a abouti avant elle, donc rien n'a été lu hors du verrou ;
| 3. la jumelle valide : un siège, une partie. Repris, le second relit le
|    siège validé, le reprend — jamais un second siège — et interrompt la
|    partie du premier : au plus une partie solo en cours par siège.
|
| L'entrelacement est rendu déterministe, sans processus ni horloge (patron
| de `LaunchConcurrencyTest`).
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 11:20:00.125'));
});

/**
 * La requête d'un démarrage, cookie `player_token` déjà déchiffré, comme
 * `EncryptCookies` le remet à la route.
 */
function soloConcurrencyRequest(PlayerToken $token): Request
{
    return Request::create('/solo', 'POST', cookies: [
        PlayerTokenCookie::NAME => json_encode($token->toClaims(), JSON_THROW_ON_ERROR),
    ]);
}

/**
 * Joue `$first` sur une connexion jumelle, transaction laissée ouverte, puis
 * `$second` sur la connexion par défaut, qui doit buter sur une ligne que
 * tient la jumelle ; valide enfin la jumelle. Rend ce que `$first` a rendu,
 * l'exception du second et les requêtes que le second a menées à bien AVANT
 * de buter — `QueryExecuted` ne part jamais pour l'instruction en échec.
 *
 * @template T
 *
 * @param  Closure(): T  $first
 * @param  Closure(): mixed  $second
 * @param  Closure(): void  $whileHeld  Assertions pendant que la jumelle tient ses verrous.
 * @return array{0: T, 1: QueryException|null, 2: list<string>}
 */
function soloConcurrencyInterleave(string $rival, Closure $first, Closure $second, Closure $whileHeld): array
{
    $default = DB::getDefaultConnection();

    config(["database.connections.{$rival}" => config("database.connections.{$default}")]);

    $blocked = null;
    $before = [];
    $recording = false;

    DB::listen(static function (QueryExecuted $query) use (&$before, &$recording, $default): void {
        if ($recording && $query->connectionName === $default) {
            $before[] = $query->sql;
        }
    });

    try {
        // 1. Le premier démarrage, sur sa propre connexion, transaction ouverte.
        DB::connection($rival)->beginTransaction();
        DB::setDefaultConnection($rival);

        $result = $first();

        // 2. Le second, concurrent : il attend le siège inséré par le premier.
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

test("deux premiers lancements solo concurrents sous le même jeton ne créent qu'un siège et qu'une partie", function (): void {
    $preset = SettingPresetKey::Classic;
    PoolFixtures::movies(SettingPresetCatalog::settingsFor($preset)->roundsCount);

    $token = PlayerToken::mint(Locale::French);
    $start = app(StartSoloGame::class);
    $launch = static fn (string $nickname): SoloStartOutcome => $start->handle(
        soloConcurrencyRequest($token),
        $preset,
        $nickname,
        Locale::French,
    );

    [$first, $blocked, $before] = soloConcurrencyInterleave(
        'solo_start_rival',
        static fn (): SoloStartOutcome => $launch('Premier'),
        static fn (): SoloStartOutcome => $launch('Second'),
        static function (): void {
            // Rien du premier démarrage n'est encore visible.
            expect(Player::query()->count())->toBe(0)
                ->and(Game::query()->count())->toBe(0);
        },
    );

    // Le second a buté sur la lecture verrouillante du siège, sa toute
    // première requête (MySQL 1205).
    $sql = strtolower((string) $blocked?->getSql());

    expect($first->isStarted())->toBeTrue()
        ->and($blocked)->toBeInstanceOf(QueryException::class)
        ->and($blocked?->errorInfo[1] ?? null)->toBe(1205)
        ->and($sql)->toContain('from `player`')
        ->and($sql)->toContain('for update')
        ->and($before)->toBe([]);

    // Un siège, une partie : celle du premier.
    $seat = Player::query()->sole();
    $game = Game::query()->sole();

    expect($seat->nickname)->toBe('Premier')
        ->and($seat->solo_token_hash)->toBe($token->hash())
        ->and($game->id)->toBe($first->game?->id)
        ->and(GamePlayer::query()->sole()->player_id)->toBe($seat->id);

    // Repris après la validation du premier, le second relit le siège validé
    // et le reprend ; il interrompt la partie du premier.
    $second = $launch('Second');

    expect($second->isStarted())->toBeTrue()
        ->and(Player::query()->sole()->id)->toBe($seat->id)
        ->and(Player::query()->sole()->nickname)->toBe('Premier')
        ->and($second->seat?->id)->toBe($seat->id)
        ->and(Game::query()->inProgress()->count())->toBe(1)
        ->and(Game::query()->inProgress()->sole()->id)->toBe($second->game?->id)
        ->and($game->refresh()->status)->toBe(GameStatus::Interrupted);
});
