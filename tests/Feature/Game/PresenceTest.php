<?php

use App\Actions\Game\RecordHeartbeat;
use App\Enums\GamePlayerStatus;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundPlayerInputState;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Jobs\Game\SweepSeatPresence;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\RoundPlayer;
use App\Settings\EngineConstants;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Game\PresenceFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\HostGestures;

/*
|--------------------------------------------------------------------------
| Présence d'un siège — spec 60 § 3.4, § 13.1 à § 13.3 et § 13.6 (lot L60-13)
|--------------------------------------------------------------------------
|
| Seuls les battements HTTP écrivent `last_seen_at`, par Eloquent, à la
| milliseconde (10 § 1.2) ; le balayage de présence (`SweepSeatPresence`) est
| le seul écrivain des transitions `connected → disconnected → left`, et le
| battement celui du retour à `connected`. Le balayage s'exécute ici comme le
| worker l'exécute (verrou d'unicité libéré au début du traitement, horloge
| à l'instant de disponibilité du job) ; le battement de salon part par la
| route, celui du solo par l'action partagée (`solo.heartbeat` arrive avec
| L60-16). Aucune valeur de jeu en littéral : seuils, délai de départ et
| cadence se lisent sur `EngineConstants` et sur les réglages.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class, SweepSeatPresence::class]);

    $this->now = CarbonImmutable::parse('2026-09-26 14:00:00.250');
    Date::setTestNow($this->now);
});

/** Réglages d'une partie courte, au délai de départ voulu. */
function presenceSettings(int $graceSeconds): RoomSettings
{
    return RoomSettings::fromInput([
        'roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT,
        'disconnectGraceSeconds' => $graceSeconds,
    ]);
}

/** La table qu'écrit une requête (`insert`, `update`, `delete`), ou `null`. */
function presenceWrittenTable(string $sql): ?string
{
    return preg_match('/^\s*(?:insert\s+into|update|delete\s+from)\s+[`"]?(\w+)/i', $sql, $match) === 1 ? $match[1] : null;
}

/**
 * Les diffusions `seat.updated` enregistrées : publicId et état de chaque
 * siège vu.
 *
 * @return list<array{string, string, bool}>
 */
function presenceSeatUpdates(RecordingBroadcaster $recorder): array
{
    $updates = [];

    foreach ($recorder->sent as $sent) {
        if ($sent['event'] === 'seat.updated') {
            $seat = $sent['payload']['seat'];
            $updates[] = [(string) $seat['publicId'], (string) $seat['connection'], (bool) $seat['isHost']];
        }
    }

    return $updates;
}

test('le battement écrit last_seen_at avec une partie milliseconde non nulle', function (): void {
    [$room, $host, $token] = HostGestures::room();
    $interval = EngineConstants::heartbeatIntervalMs();
    $activity = $room->last_activity_at;

    // Un battement ordinaire, moins d'un intervalle après la dernière
    // activité du salon : il n'écrit que la ligne `player`, par Eloquent.
    $beatAt = $activity->addMilliseconds(intdiv($interval, 2) + 431);
    $writes = [];
    DB::listen(static function (QueryExecuted $query) use (&$writes): void {
        $table = presenceWrittenTable($query->sql);

        if ($table !== null) {
            $writes[] = $table;
        }
    });

    PresenceFixtures::beat($this, $room, $token, $beatAt)->assertNoContent();

    $raw = (string) DB::table('player')->where('id', $host->id)->value('last_seen_at');

    expect($raw)->toBe($beatAt->format('Y-m-d H:i:s.v'))
        ->and($beatAt->format('v'))->not->toBe('000')
        ->and($host->refresh()->last_seen_at->equalTo($beatAt))->toBeTrue()
        ->and($writes)->toBe(['player'])
        ->and($room->refresh()->last_activity_at->equalTo($activity))->toBeTrue();

    // Un intervalle après la dernière activité, le battement la nourrit —
    // une fois : le suivant, moins d'un intervalle plus tard, ne la réécrit pas.
    $fedAt = $activity->addMilliseconds($interval + 517);
    PresenceFixtures::beat($this, $room, $token, $fedAt)->assertNoContent();

    expect($room->refresh()->last_activity_at->format('Y-m-d H:i:s'))->toBe($fedAt->format('Y-m-d H:i:s'));

    PresenceFixtures::beat($this, $room, $token, $fedAt->addMilliseconds(intdiv($interval, 2)))->assertNoContent();

    expect($room->refresh()->last_activity_at->format('Y-m-d H:i:s'))->toBe($fedAt->format('Y-m-d H:i:s'))
        ->and((string) DB::table('player')->where('id', $host->id)->value('last_seen_at'))
        ->toBe($fedAt->addMilliseconds(intdiv($interval, 2))->format('Y-m-d H:i:s.v'));

    // Le premier battement a armé le balayage à l'échéance du siège, arrondie
    // à la seconde supérieure, sur la file `game` ; les suivants, un balayage
    // étant en attente, n'en arment aucun autre.
    $sweeps = PresenceFixtures::sweeps();

    expect($sweeps)->toHaveCount(1)
        ->and($sweeps[0]->roomId)->toBe($room->id)
        ->and($sweeps[0]->queue)->toBe('game')
        ->and($sweeps[0]->tries)->toBe(1)
        ->and($sweeps[0]->uniqueId())->toBe('room:'.$room->id)
        ->and(PresenceFixtures::dueAt($sweeps[0])->equalTo($beatAt->addMilliseconds(EngineConstants::disconnectAfterMs())->ceilSecond()))->toBeTrue();

    // Sans siège tenu par le jeton : 403, rien d'écrit.
    [$other] = HostGestures::room();
    $before = HostGestures::raw('player', $host->id);

    PresenceFixtures::beat($this, $other, $token)->assertForbidden();

    expect(HostGestures::raw('player', $host->id))->toBe($before);
});

test('un siège sans battement depuis disconnectAfterMs passe disconnected, puis left après disconnectGraceSeconds', function (): void {
    $recorder = RecordingBroadcaster::install();
    $gameGrace = RoomSettingsBounds::MIN_DISCONNECT_GRACE_SECONDS;

    // En partie, le délai de départ se lit dans l'instantané de la partie,
    // jamais dans les réglages du salon, qui gardent le défaut.
    $game = EngineFixtures::game(presenceSettings($gameGrace));
    $room = $game->room ?? throw new LogicException('Partie sans salon.');
    [$quiet, $quietToken] = PresenceFixtures::heldSeat($game);
    [$lively, $livelyToken] = PresenceFixtures::heldSeat($game);

    expect($room->settings->disconnectGraceSeconds)->not->toBe($gameGrace);

    $t0 = $this->now;
    PresenceFixtures::beat($this, $room, $quietToken, $t0)->assertNoContent();
    PresenceFixtures::beat($this, $room, $livelyToken, $t0->addSeconds(5))->assertNoContent();

    // Un seul balayage, armé à l'échéance du premier battement.
    $first = PresenceFixtures::lastSweep();
    $disconnectsAt = $t0->addMilliseconds(EngineConstants::disconnectAfterMs());

    expect(PresenceFixtures::sweeps())->toHaveCount(1);

    // Une milliseconde avant l'échéance : rien.
    PresenceFixtures::run($first, $disconnectsAt->subMillisecond());

    expect($quiet->refresh()->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and(presenceSeatUpdates($recorder))->toBe([]);

    // À l'échéance du balayage réarmé : `disconnected`, à l'instant du
    // passage ; l'autre siège, qui a battu depuis, reste connecté.
    $second = PresenceFixtures::lastSweep();
    $sweptAt = PresenceFixtures::dueAt($second);
    PresenceFixtures::run($second);

    $quiet->refresh();

    expect($sweptAt->greaterThanOrEqualTo($disconnectsAt))->toBeTrue()
        ->and($quiet->connection_state)->toBe(PlayerConnectionState::Disconnected)
        ->and($quiet->disconnected_at?->equalTo($sweptAt))->toBeTrue()
        ->and($quiet->left_at)->toBeNull()
        ->and($lively->refresh()->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and(presenceSeatUpdates($recorder))->toBe([[$quiet->public_id, 'disconnected', false]]);

    // Le siège vivant bat encore ; le balayage suit les échéances jusqu'au
    // départ, qui tombe à `disconnected_at + disconnectGraceSeconds` de la
    // PARTIE — le défaut du salon ne l'aurait pas encore fait partir.
    $leavesAt = $sweptAt->addSeconds($gameGrace);
    PresenceFixtures::beat($this, $room, $livelyToken, $sweptAt->addSeconds(1))->assertNoContent();

    while (($next = PresenceFixtures::lastSweep()) && PresenceFixtures::dueAt($next)->lessThan($leavesAt)) {
        PresenceFixtures::run($next);

        expect($quiet->refresh()->connection_state)->toBe(PlayerConnectionState::Disconnected);
    }

    expect(PresenceFixtures::dueAt($next)->equalTo($leavesAt->ceilSecond()))->toBeTrue();

    PresenceFixtures::run($next);
    $quiet->refresh();

    expect($quiet->connection_state)->toBe(PlayerConnectionState::Left)
        ->and($quiet->left_at?->equalTo($leavesAt->ceilSecond()))->toBeTrue()
        ->and($quiet->kicked_at)->toBeNull()
        // La participation passe `left`, points conservés.
        ->and(GamePlayer::query()->whereBelongsTo($game)->where('player_id', $quiet->id)->value('status'))->toBe(GamePlayerStatus::Left)
        ->and(GamePlayer::query()->whereBelongsTo($game)->where('player_id', $lively->id)->value('status'))->toBe(GamePlayerStatus::Playing)
        ->and(array_slice(presenceSeatUpdates($recorder), -1))->toBe([[$quiet->public_id, 'left', false]]);

    // Au lobby, le délai de départ se lit dans les réglages du salon.
    $lobbyGrace = RoomSettingsBounds::MAX_DISCONNECT_GRACE_SECONDS;
    [$lobby, $seat, $token] = HostGestures::room(presenceSettings($lobbyGrace));
    PresenceFixtures::beat($this, $lobby, $token, $leavesAt->addMinute())->assertNoContent();

    $lobbySweep = PresenceFixtures::lastSweep();
    PresenceFixtures::run($lobbySweep);
    $lobbyDisconnectedAt = $seat->refresh()->disconnected_at ?? throw new LogicException('Siège resté connecté.');

    do {
        $pending = PresenceFixtures::lastSweep();
        PresenceFixtures::run($pending);
    } while ($seat->refresh()->connection_state === PlayerConnectionState::Disconnected);

    expect($seat->connection_state)->toBe(PlayerConnectionState::Left)
        ->and($seat->left_at?->equalTo($lobbyDisconnectedAt->addSeconds($lobbyGrace)->ceilSecond()))->toBeTrue();
});

test('un battement ramène un siège disconnected ou left à connected sans réadmettre un expulsé', function (): void {
    $recorder = RecordingBroadcaster::install();
    $game = EngineFixtures::game(presenceSettings(RoomSettingsBounds::DEFAULT_DISCONNECT_GRACE_SECONDS));
    $room = $game->room ?? throw new LogicException('Partie sans salon.');
    $earlier = $this->now->subMinute();

    [$away, $awayToken] = PresenceFixtures::heldSeat($game, [
        'connection_state' => PlayerConnectionState::Disconnected,
        'disconnected_at' => $earlier,
    ]);
    [$gone, $goneToken] = PresenceFixtures::heldSeat($game, [
        'connection_state' => PlayerConnectionState::Left,
        'disconnected_at' => $earlier,
        'left_at' => $earlier->addSeconds(30),
    ], status: GamePlayerStatus::Left);
    [$kicked, $kickedToken] = PresenceFixtures::heldSeat($game, [
        'connection_state' => PlayerConnectionState::Left,
        'left_at' => $earlier,
        'kicked_at' => $earlier,
    ], status: GamePlayerStatus::Kicked);

    Room::query()->whereKey($room->id)->update(['last_activity_at' => $earlier]);

    // Le siège déconnecté revient : `connected`, instant de déconnexion effacé.
    $backAt = $this->now->addMilliseconds(1_234);
    PresenceFixtures::beat($this, $room, $awayToken, $backAt)->assertNoContent();
    $away->refresh();

    expect($away->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and($away->disconnected_at)->toBeNull()
        ->and($away->last_seen_at->equalTo($backAt))->toBeTrue()
        // Le retour nourrit l'activité du salon.
        ->and($room->refresh()->last_activity_at->format('Y-m-d H:i:s'))->toBe($backAt->format('Y-m-d H:i:s'));

    // Le siège parti revient : c'est une reconnexion, sa participation repasse
    // `playing`, ses points restent acquis.
    PresenceFixtures::beat($this, $room, $goneToken, $backAt->addSecond())->assertNoContent();
    $gone->refresh();

    expect($gone->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and($gone->left_at)->toBeNull()
        ->and($gone->disconnected_at)->toBeNull()
        ->and(GamePlayer::query()->whereBelongsTo($game)->where('player_id', $gone->id)->value('status'))->toBe(GamePlayerStatus::Playing);

    // Chaque retour émet `seat.updated`, avec l'identité GELÉE de la partie.
    expect(presenceSeatUpdates($recorder))->toBe([
        [$away->public_id, 'connected', false],
        [$gone->public_id, 'connected', false],
    ]);

    // L'expulsé n'est jamais réadmis : 403, ni la ligne ni sa participation
    // ne changent, et rien ne part sur le fil.
    $seatBefore = HostGestures::raw('player', $kicked->id);
    $participationBefore = GamePlayer::query()->whereBelongsTo($game)->where('player_id', $kicked->id)->firstOrFail()->getRawOriginal();
    $sweepsBefore = count(PresenceFixtures::sweeps());
    $recorder->sent = [];

    PresenceFixtures::beat($this, $room, $kickedToken, $backAt->addSeconds(2))->assertForbidden();

    expect(HostGestures::raw('player', $kicked->id))->toBe($seatBefore)
        ->and(GamePlayer::query()->whereBelongsTo($game)->where('player_id', $kicked->id)->firstOrFail()->getRawOriginal())->toBe($participationBefore)
        ->and($recorder->sent)->toBe([])
        ->and(PresenceFixtures::sweeps())->toHaveCount($sweepsBefore);

    // Course : l'expulsion se valide entre la résolution du siège par le
    // jeton et son verrou. Le battement relit le siège verrouillé et refuse.
    $resolved = Player::query()->findOrFail($away->id);
    $kickedAt = $backAt->addSeconds(3);
    $away->forceFill(['connection_state' => PlayerConnectionState::Left, 'left_at' => $kickedAt, 'kicked_at' => $kickedAt])->save();
    $racedBefore = HostGestures::raw('player', $away->id);

    expect(app(RecordHeartbeat::class)->handle($resolved, $room))->toBeFalse()
        ->and(HostGestures::raw('player', $away->id))->toBe($racedBefore)
        ->and($recorder->sent)->toBe([]);

    // Course : un balayage déconnecte le siège entre sa résolution (encore
    // connecté) et son verrou, l'activité du salon étant récente. Le passage
    // sans verrou du salon n'écrit rien ; il est rejoué avec le salon, pris
    // AVANT le siège, et le siège revient comme tout retour.
    $resolved = Player::query()->findOrFail($gone->id);
    $racedAt = $backAt->addSeconds(4);
    $gone->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => $racedAt])->save();
    Room::query()->whereKey($room->id)->update(['last_activity_at' => $racedAt]);
    Date::setTestNow($racedAt->addMilliseconds(250));
    $room->refresh();
    $reads = [];
    DB::listen(static function (QueryExecuted $query) use (&$reads): void {
        if (preg_match('/^\s*select\b.*\bfrom\s+[`"]?(room|player)[`"]?\s/i', $query->sql, $match) === 1) {
            $reads[] = $match[1];
        }
    });

    expect(app(RecordHeartbeat::class)->handle($resolved, $room))->toBeTrue()
        ->and($gone->refresh()->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and(array_slice($reads, 0, 3))->toBe(['player', 'room', 'player'])
        ->and(presenceSeatUpdates($recorder))->toBe([[$gone->public_id, 'connected', false]]);

    // Un salon archivé refuse tout battement.
    Room::query()->whereKey($room->id)->update(['archived_at' => $backAt, 'room_code_active' => null]);

    PresenceFixtures::beat($this, $room, $awayToken, $backAt->addSeconds(3))->assertForbidden();
});

test('un siège déconnecté à T₁ reçoit sa ligne round_player et peut répondre à son retour', function (): void {
    $target = SubmissionFixtures::movie('Quartz Harbour');
    $token = PlayerToken::mint(Locale::French);
    $absentToken = PlayerToken::mint(Locale::French);

    [$game, $round, [$present, $absent]] = SubmissionFixtures::openedRound([$token, $absentToken], target: $target, open: false);
    $room = $game->room ?? throw new LogicException('Partie sans salon.');

    // Le siège perd le réseau avant T₁ : il reste un siège non parti.
    $absent->forceFill([
        'connection_state' => PlayerConnectionState::Disconnected,
        'disconnected_at' => Date::now(),
    ])->save();

    EngineFixtures::openTier($round, 1);
    $participation = RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $absent->id)->first();

    // Il reçoit sa ligne à T₁, saisie ouverte, sans être participant tant
    // qu'il est déconnecté.
    expect($participation)->not->toBeNull()
        ->and($participation?->input_state)->toBe(RoundPlayerInputState::Open)
        ->and(RoundPlayer::query()->where('round_id', $round->id)->participants()->pluck('player_id')->all())->toBe([$present->id]);

    // Il revient : participant de nouveau, il répond au palier affiché.
    $backAt = EngineFixtures::opensAt($round, 1)->addMilliseconds(2_345);
    PresenceFixtures::beat($this, $room, $absentToken, $backAt)->assertNoContent();

    expect(RoundPlayer::query()->where('round_id', $round->id)->participants()->pluck('player_id')->all())
        ->toEqualCanonicalizing([$present->id, $absent->id]);

    SubmissionFixtures::submit($this, $absent, $absentToken, 'Quartz Harbour', $backAt->addMilliseconds(500))
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'tierIndex' => 1]);

    expect(SubmissionFixtures::participation($round, $absent)->input_state)->toBe(RoundPlayerInputState::Locked);
});

test('le départ de l\'hôte déclenche le transfert d\'hôte dans la même transaction', function (): void {
    $recorder = RecordingBroadcaster::install();
    [$room, $host, $hostToken] = HostGestures::room();
    // L'aîné connecté hérite ; le déconnecté plus ancien encore, non.
    [$older] = HostGestures::seat($room, 20);
    [$heir, $heirToken] = HostGestures::seat($room, 10);
    $older->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => $this->now])->save();

    // L'hôte cesse de battre ; l'héritier continue.
    PresenceFixtures::beat($this, $room, $hostToken, $this->now)->assertNoContent();

    $trace = [];
    $transaction = 0;
    Event::listen(TransactionBeginning::class, static function () use (&$transaction): void {
        if (DB::transactionLevel() === 1) {
            $transaction++;
        }
    });
    DB::listen(static function (QueryExecuted $query) use (&$trace, &$transaction): void {
        if (preg_match('/^\s*update\b/i', $query->sql) === 1) {
            $trace[] = [
                'table' => presenceWrittenTable($query->sql),
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'transaction' => $transaction,
                'level' => DB::transactionLevel(),
            ];
        }
    });

    $cursor = $this->now;

    do {
        $cursor = $cursor->addSeconds(10);
        PresenceFixtures::beat($this, $room, $heirToken, $cursor)->assertNoContent();

        $sweep = PresenceFixtures::lastSweep();

        if (PresenceFixtures::dueAt($sweep)->lessThanOrEqualTo($cursor->addSeconds(10))) {
            $trace = [];
            PresenceFixtures::run($sweep);
        }
    } while ($host->refresh()->connection_state !== PlayerConnectionState::Left);

    $room->refresh();

    expect($room->host_player_id)->toBe($heir->id)
        ->and($host->left_at)->not->toBeNull();

    // Le passage à `left` du partant et la réécriture de l'hôte : une seule
    // transaction, ouverte par le balayage.
    $leftWrite = collect($trace)->first(static fn (array $entry): bool => $entry['table'] === 'player'
        && in_array(PlayerConnectionState::Left->value, $entry['bindings'], true));
    $hostWrite = collect($trace)->first(static fn (array $entry): bool => $entry['table'] === 'room'
        && str_contains($entry['sql'], 'host_player_id'));

    expect($leftWrite)->not->toBeNull()
        ->and($hostWrite)->not->toBeNull()
        ->and($leftWrite['level'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($hostWrite['level'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($hostWrite['transaction'] ?? null)->toBe($leftWrite['transaction'] ?? null);

    // Sur le fil : `host.changed`, puis le `seat.updated` du partant, composé
    // après le transfert — il n'y est plus l'hôte.
    $events = array_column($recorder->sent, 'event');
    $hostChanged = array_search('host.changed', $events, true);

    expect($hostChanged)->not->toBeFalse()
        ->and($recorder->sent[$hostChanged]['payload'])->toMatchArray([
            'hostPublicId' => $heir->public_id,
            'previousHostPublicId' => $host->public_id,
        ])
        ->and(array_slice($events, (int) $hostChanged))->toBe(['host.changed', 'seat.updated'])
        ->and(array_slice(presenceSeatUpdates($recorder), -1))->toBe([[$host->public_id, 'left', false]]);

    // Revenu, l'ancien hôte ne récupère pas le rôle.
    PresenceFixtures::beat($this, $room, $hostToken, $cursor->addSecond())->assertNoContent();

    expect($host->refresh()->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and($room->refresh()->host_player_id)->toBe($heir->id);
});

test('un siège solo ne passe jamais left', function (): void {
    $recorder = RecordingBroadcaster::install();
    $game = EngineFixtures::game(presenceSettings(RoomSettingsBounds::MIN_DISCONNECT_GRACE_SECONDS), solo: true);
    $seat = EngineFixtures::seat($game);

    app(RecordHeartbeat::class)->handle($seat, null);

    $sweep = PresenceFixtures::lastSweep();

    expect($sweep->roomId)->toBeNull()
        ->and($sweep->soloSeatId)->toBe($seat->id)
        ->and($sweep->uniqueId())->toBe('seat:'.$seat->id);

    PresenceFixtures::run($sweep);
    $disconnectedAt = $seat->refresh()->disconnected_at;

    expect($seat->connection_state)->toBe(PlayerConnectionState::Disconnected)
        ->and($disconnectedAt?->equalTo(PresenceFixtures::dueAt($sweep)))->toBeTrue();

    // Bien au-delà de tout délai de départ, un balayage du siège solo ne le
    // fait jamais partir : `disconnectGraceSeconds` est sans effet en solo.
    $late = SweepSeatPresence::forSoloSeat($seat->id);
    PresenceFixtures::run($late, $this->now->addSeconds(RoomSettingsBounds::MAX_DISCONNECT_GRACE_SECONDS)->addHour());
    $seat->refresh();

    expect($seat->connection_state)->toBe(PlayerConnectionState::Disconnected)
        ->and($seat->left_at)->toBeNull()
        ->and($seat->disconnected_at?->equalTo($disconnectedAt))->toBeTrue()
        ->and(GamePlayer::query()->whereBelongsTo($game)->value('status'))->toBe(GamePlayerStatus::Playing)
        // Aucune diffusion en solo.
        ->and($recorder->sent)->toBe([]);
});

test('un siège revenu à connected pendant qu\'un balayage attend une échéance lointaine passe disconnected à sa propre échéance', function (): void {
    // Au lobby, délai de départ au plus long : l'échéance `left` est lointaine.
    [$room, $seat, $token] = HostGestures::room(presenceSettings(RoomSettingsBounds::MAX_DISCONNECT_GRACE_SECONDS));
    $disconnectAfter = EngineConstants::disconnectAfterMs();

    // Instants à la seconde ronde : l'arrondi du délai à la seconde supérieure
    // ne décale alors aucune échéance.
    $t0 = $this->now->startOfSecond()->addSecond();
    PresenceFixtures::beat($this, $room, $token, $t0)->assertNoContent();

    $first = PresenceFixtures::lastSweep();
    PresenceFixtures::run($first);
    $disconnectedAt = $seat->refresh()->disconnected_at ?? throw new LogicException('Siège resté connecté.');

    // Le balayage réarmé attend le départ lointain, mais jamais plus d'un
    // seuil de déconnexion : il est plafonné.
    $waiting = PresenceFixtures::lastSweep();
    $leavesAt = $disconnectedAt->addSeconds(RoomSettingsBounds::MAX_DISCONNECT_GRACE_SECONDS);

    expect(PresenceFixtures::dueAt($waiting)->equalTo($disconnectedAt->addMilliseconds($disconnectAfter)))->toBeTrue()
        ->and(PresenceFixtures::dueAt($waiting)->lessThan($leavesAt))->toBeTrue();

    // Le siège revient pendant cette attente ; son battement n'arme rien de
    // plus, un balayage étant déjà en attente.
    $backAt = $disconnectedAt->addSeconds(4);
    $sweepsBefore = count(PresenceFixtures::sweeps());
    PresenceFixtures::beat($this, $room, $token, $backAt)->assertNoContent();

    expect($seat->refresh()->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and(PresenceFixtures::sweeps())->toHaveCount($sweepsBefore);

    // Il se tait de nouveau. Le balayage plafonné s'exécute avant son
    // échéance, puis se réarme dessus.
    PresenceFixtures::run($waiting);

    expect($seat->refresh()->connection_state)->toBe(PlayerConnectionState::Connected);

    $own = PresenceFixtures::lastSweep();
    $ownDeadline = $backAt->addMilliseconds($disconnectAfter);

    expect(PresenceFixtures::dueAt($own)->equalTo($ownDeadline))->toBeTrue();

    PresenceFixtures::run($own);
    $seat->refresh();

    expect($seat->connection_state)->toBe(PlayerConnectionState::Disconnected)
        ->and($seat->disconnected_at?->equalTo($ownDeadline))->toBeTrue()
        ->and($ownDeadline->lessThan($leavesAt))->toBeTrue();
});

test('le balayage d\'un siège solo s\'arrête à disconnected et le battement suivant le réarme', function (): void {
    $recorder = RecordingBroadcaster::install();
    $game = EngineFixtures::game(EngineFixtures::settings(), solo: true);
    $seat = EngineFixtures::seat($game);

    app(RecordHeartbeat::class)->handle($seat, null);

    $sweep = PresenceFixtures::lastSweep();
    $dueAt = PresenceFixtures::dueAt($sweep);

    expect($dueAt->equalTo($this->now->addMilliseconds(EngineConstants::disconnectAfterMs())->ceilSecond()))->toBeTrue();

    // Avant l'échéance, il se réarme dessus ; à l'échéance, `disconnected`,
    // et plus aucun balayage n'est armé.
    PresenceFixtures::run($sweep, $dueAt->subSeconds(3));

    expect($seat->refresh()->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and(PresenceFixtures::sweeps())->toHaveCount(2);

    PresenceFixtures::run(PresenceFixtures::lastSweep());

    expect($seat->refresh()->connection_state)->toBe(PlayerConnectionState::Disconnected)
        ->and(PresenceFixtures::sweeps())->toHaveCount(2);

    // Le battement suivant le ramène à `connected` et réarme le balayage à sa
    // propre échéance.
    $backAt = $dueAt->addSeconds(7)->addMilliseconds(321);
    Date::setTestNow($backAt);
    expect(app(RecordHeartbeat::class)->handle($seat->refresh(), null))->toBeTrue();

    $rearmed = PresenceFixtures::lastSweep();
    $seat->refresh();

    expect($seat->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and($seat->disconnected_at)->toBeNull()
        ->and($seat->last_seen_at->equalTo($backAt))->toBeTrue()
        ->and(PresenceFixtures::sweeps())->toHaveCount(3)
        ->and($rearmed->soloSeatId)->toBe($seat->id)
        ->and(PresenceFixtures::dueAt($rearmed)->equalTo($backAt->addMilliseconds(EngineConstants::disconnectAfterMs())->ceilSecond()))->toBeTrue()
        // Ni la déconnexion ni le retour n'ont rien porté sur le fil.
        ->and($recorder->sent)->toBe([]);
});
