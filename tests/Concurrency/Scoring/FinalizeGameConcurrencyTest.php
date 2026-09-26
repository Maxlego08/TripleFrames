<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Events\Game\GameFinalized;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Scoring\ScoringFixtures;

/*
|--------------------------------------------------------------------------
| Deux gels concurrents — spec 80 § 10.2 et § 10.8, contrat C13 § 4.5 (L80-4)
|--------------------------------------------------------------------------
|
| Groupe `locks-timing` (par répertoire), MySQL réel, `DatabaseTruncation` :
| deux connexions voient les écritures l'une de l'autre.
|
| Une fin normale et une clôture après pause peuvent arriver ensemble. Le gel
| n'est idempotent que parce que la lecture de `game.ended_at` est une lecture
| VERROUILLANTE (`lockForUpdate`) : sans elle, le second appelant lirait, sous
| son instantané, une partie encore ouverte, puis écraserait le premier gel
| dès que celui-ci validerait.
|
| L'entrelacement est rendu déterministe, sans processus ni horloge :
| 1. le premier appel gèle sur une seconde connexion, dans une transaction
|    laissée ouverte — ses écritures sont faites, rien n'est validé ;
| 2. le second appel, sur la connexion par défaut, BUTE sur la ligne `game`
|    dès sa première requête, la lecture verrouillante (sa connexion attend
|    au plus une seconde, `innodb_lock_wait_timeout` de session : c'est la
|    borne de l'attente, jamais le verdict) ;
| 3. le premier appel valide ; repris, le second relit la partie gelée et
|    rend `false`, sans aucune écriture ni événement.
|
*/

test('deux appels concurrents ne gèlent qu’une fois', function (): void {
    Event::fake([GameFinalized::class]);

    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, 1_700 + $grace);   // 424
    ScoringFixtures::played($first, $b);

    $last = ScoringFixtures::round($game, 2, RoundStatus::Revealing);
    ScoringFixtures::find($last, $b, 11_700 + $grace);   // 283
    ScoringFixtures::played($last, $a);

    $endedAt = $last->reveal_ends_at;
    $finalize = app(FinalizeGame::class);

    expect($endedAt)->not->toBeNull();

    $default = DB::getDefaultConnection();
    $rival = 'finalize_game_rival';

    config(["database.connections.{$rival}" => config("database.connections.{$default}")]);

    $blocked = null;

    try {
        // 1. Premier appel, sur sa propre connexion, transaction laissée ouverte.
        DB::connection($rival)->beginTransaction();
        DB::setDefaultConnection($rival);

        expect($finalize->handle($game, GameStatus::Completed, $endedAt))->toBeTrue();

        // 2. Second appel, concurrent : il attend le verrou de la partie.
        DB::setDefaultConnection($default);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $finalize->handle($game, GameStatus::Interrupted, $endedAt->addMinutes(15));
        } catch (QueryException $exception) {
            $blocked = $exception;
        }

        // Rien du premier gel n'est encore visible, rien n'est encore annoncé.
        expect(Game::query()->whereKey($game->id)->value('ended_at'))->toBeNull()
            ->and(GamePlayer::query()->where('game_id', $game->id)->whereNotNull('final_score')->count())->toBe(0);
        Event::assertNotDispatched(GameFinalized::class);

        // 3. Le premier appel valide.
        DB::connection($rival)->commit();
    } finally {
        DB::setDefaultConnection($default);

        if (DB::connection($rival)->transactionLevel() > 0) {
            DB::connection($rival)->rollBack();
        }

        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        DB::purge($rival);
    }

    // Le second appel a buté sur la lecture verrouillante de la partie, sa
    // toute première requête (MySQL 1205 : attente de verrou dépassée).
    expect($blocked)->toBeInstanceOf(QueryException::class)
        ->and($blocked?->errorInfo[1] ?? null)->toBe(1205)
        ->and(strtolower((string) $blocked?->getSql()))->toContain('`game`')
        ->and(strtolower((string) $blocked?->getSql()))->toContain('for update');

    Event::assertDispatchedTimes(GameFinalized::class, 1);

    $frozen = Game::query()->findOrFail($game->id);
    $seats = GamePlayer::query()->where('game_id', $game->id)->orderBy('id')->get()->toArray();

    // Repris après la validation du premier, le second appel relit la partie
    // gelée : il rend false, n'écrit rien, n'annonce rien.
    $writes = [];

    DB::listen(static function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(?:insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    expect($finalize->handle($game, GameStatus::Interrupted, $endedAt->addMinutes(15)))->toBeFalse()
        ->and($writes)->toBe([]);
    Event::assertDispatchedTimes(GameFinalized::class, 1);

    // Un seul gel : l'issue, l'instant et les agrégats du premier.
    $stored = Game::query()->findOrFail($game->id);

    expect($stored->status)->toBe(GameStatus::Completed)
        ->and($stored->ended_at?->format('Y-m-d H:i:s.v'))->toBe($endedAt->format('Y-m-d H:i:s.v'))
        ->and($stored->updated_at?->format('Y-m-d H:i:s.v'))->toBe($frozen->updated_at?->format('Y-m-d H:i:s.v'))
        ->and($stored->rounds_completed)->toBe(2)
        ->and(GamePlayer::query()->where('game_id', $game->id)->orderBy('id')->get()->toArray())->toBe($seats)
        ->and(array_column($seats, 'final_score'))->toBe([424, 283])
        ->and(array_column($seats, 'final_rank'))->toBe([1, 2]);
});
