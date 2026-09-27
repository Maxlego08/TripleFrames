<?php

use App\Actions\Game\CancelRound;
use App\Actions\Game\FinalizeGame;
use App\Enums\AvatarKind;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Jobs\Game\AdvanceRound;
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
use App\Support\I18n\TranslationDomains;
use App\Support\Realtime\GameRef;
use App\Support\Scoring\Scoreboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Retardataires — spec 50 § 15 (lot L50-9) ; 60 § 13.7, E10-20, E10-49
|--------------------------------------------------------------------------
|
| Un salon ouvert aux retardataires (`allow_late_join`, lu en projection)
| admet un siège entré en cours de partie **à la manche suivante
| uniquement**, avec un score initial de 0 : la prochaine manche numérotée
| `pending` non démarrée dans l'ordre de jeu (`round_number`, puis
| `sequence_index`), remplaçante comprise tant qu'elle n'a pas démarré,
| réserve sans numéro jamais. Fermé, ou sans manche à démarrer, le siège
| attend la partie suivante, sans participation. La manche en cours n'est
| jamais donnée, même quand son `T₁` est franchi et que son ouverture tarde.
|
| Parties matérialisées et jouées par les actions réelles du moteur
| (`EngineFixtures`), à leurs instants théoriques ; entrée par la vraie
| route `room.join`. Horloge figée.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    // Les entrées de ce fichier viennent toutes de la même adresse : le
    // limiteur `room-join` (§ 17.3) n'est pas leur sujet.
    config()->set('game.room.joins_per_minute', 1000);

    $this->now = CarbonImmutable::parse('2026-09-27 18:30:00.125');
    Date::setTestNow($this->now);
});

/**
 * Un salon en partie, ouvert ou fermé aux retardataires, sur `M` manches au
 * plus petit admis (et la réserve demandée) : l'hôte et un invité tenus par
 * des jetons, participations gelées au lancement (`first_round_number = 1`,
 * E10-42), tirage matérialisé par l'action réelle, manche 1 programmée à la
 * fin du décompte de lancement.
 *
 * @return array{0: Room, 1: Game, 2: Player}
 */
function lateJoinGame(bool $open = true, int $reserve = 0): array
{
    $settings = RoomSettings::fromInput([
        'allowLateJoin' => $open,
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

    EngineFixtures::materialize($game, $reserve);

    $now = Date::now()->toImmutable();
    EngineFixtures::schedule(EngineFixtures::round($game, 1), $now->addMilliseconds(EngineConstants::launchCountdownMs()), $now);

    return [$room->refresh(), $game->refresh(), $host->refresh()];
}

/**
 * L'entrée d'un nouveau venu par la route, sans jeton : 303 vers la page du
 * salon, sans erreur.
 *
 * @return TestResponse<Response>
 */
function lateJoinEnter(TestCase $test, Room $room, string $nickname, int $avatar = 3): TestResponse
{
    $test->flushSession();

    return $test->withoutHeader('referer')
        ->post(route('room.join', $room), SeatEntry::form($nickname, SeatEntry::avatar($avatar)))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();
}

/** Le siège de ce pseudo dans ce salon. */
function lateJoinSeat(Room $room, string $nickname): Player
{
    return Player::query()->whereBelongsTo($room)->where('nickname', $nickname)->sole();
}

/** La participation du siège à la partie, ou `null` (attente). */
function lateJoinParticipation(Game $game, Player $seat): ?GamePlayer
{
    return GamePlayer::query()->whereBelongsTo($game)->where('player_id', $seat->id)->first();
}

/** Le siège a-t-il une ligne `round_player` dans cette manche ? */
function lateJoinPlays(Round $round, Player $seat): bool
{
    return RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $seat->id)->exists();
}

/**
 * L'état de la page d'entrée d'un visiteur sans siège, dans cette langue,
 * et le texte de cet état tel que la page le reçoit.
 *
 * @return array{0: string, 1: string|null}
 */
function lateJoinEntryState(TestCase $test, Room $room, Locale $locale): array
{
    app()->forgetInstance(TranslationDomains::class);

    $response = $test->withUnencryptedCookie('locale', $locale->value)
        ->get(route('room.entry', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('room/join')->etc());

    $entry = $response->inertiaProps('entry');
    $translations = $response->inertiaProps('translations');
    $text = is_string($entry) && is_array($translations) ? ($translations['room.join.'.$entry] ?? null) : null;

    return [is_string($entry) ? $entry : '', is_string($text) ? $text : null];
}

/**
 * Le paquet `state` de la page du salon, vu par le siège de ce jeton.
 *
 * @param  TestResponse<Response>  $joined  La réponse de l'entrée, qui pose le jeton.
 * @return array<string, mixed>
 */
function lateJoinState(TestCase $test, Room $room, TestResponse $joined): array
{
    LobbyWrites::actAs($test, SeatEntry::tokenFrom($joined));

    $state = $test->get(route('room.show', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('game/lobby')->etc())
        ->inertiaProps('state');

    // Le visiteur suivant arrive sans jeton : le cookie de ce siège n'est
    // pas rejoué sur les requêtes qui suivent.
    (fn () => $this->defaultCookies = [])->call($test);

    expect($state)->toBeArray();

    /** @var array<string, mixed> $state */
    return $state;
}

it('admet un retardataire à la prochaine manche numérotée non démarrée, avec une participation à 0 point', function (): void {
    [$room, $game] = lateJoinGame();
    $first = EngineFixtures::round($game, 1);
    EngineFixtures::openTier($first, 1);

    // La page d'entrée l'annonce, dans la langue du visiteur : l'état est
    // indicatif, la prise de siège le recalcule sous verrou.
    foreach ([Locale::French, Locale::English] as $locale) {
        expect(lateJoinEntryState($this, $room, $locale))
            ->toBe(['late_join', trans('room.join.late_join', [], $locale->value)]);
    }

    $recorder = RecordingBroadcaster::install();
    $joined = lateJoinEnter($this, $room, 'Retard', 6);
    $seat = lateJoinSeat($room, 'Retard');
    $participation = lateJoinParticipation($game, $seat);

    // La manche 1 court : il entre à la manche 2, `playing`, identité gelée
    // depuis son siège, agrégats nuls jusqu'au gel.
    expect($participation)->not->toBeNull()
        ->and($participation?->status)->toBe(GamePlayerStatus::Playing)
        ->and($participation?->first_round_number)->toBe(2)
        ->and($participation?->display_nickname)->toBe('Retard')
        ->and($participation?->display_avatar_kind)->toBe(AvatarKind::Preset)
        ->and($participation?->display_avatar_preset)->toBe(SeatEntry::avatar(6))
        ->and($participation?->rounds_played)->toBeNull()
        ->and($participation?->correct_answers)->toBeNull()
        ->and($participation?->final_score)->toBeNull()
        ->and($participation?->total_answer_time_ms)->toBeNull()
        ->and($participation?->final_rank)->toBeNull()
        ->and($seat->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and(lateJoinPlays($first, $seat))->toBeFalse()
        ->and(GamePlayer::query()->whereBelongsTo($game)->count())->toBe(3);

    // `seat.joined` au salon, dans la partie en cours, porte sa manche
    // d'entrée (§ 15.3) — et aucun identifiant interne.
    expect(array_column($recorder->sent, 'event'))->toBe(['seat.joined'])
        ->and($recorder->sent[0]['payload']['gameRef'])->toBe(GameRef::for($game))
        ->and($recorder->sent[0]['payload']['seat'])->toMatchArray([
            'publicId' => $seat->public_id,
            'nickname' => 'Retard',
            'isHost' => false,
            'connection' => PlayerConnectionState::Connected->value,
            'kicked' => false,
            'firstRoundNumber' => 2,
        ])
        ->and($recorder->sent[0]['json'])->not->toContain('"player_id"')
        ->and($recorder->sent[0]['json'])->not->toContain('"id"');

    // Score initial de 0 : classé, sans aucun point, sans manche jouée.
    $row = collect(Scoreboard::leaderboard($game->refresh())['rows'])->firstWhere('publicId', $seat->public_id);

    expect($row)->toMatchArray([
        'score' => 0,
        'correctAnswers' => 0,
        'roundsPlayed' => 0,
        'firstRoundNumber' => 2,
    ])->and(Scoreboard::seatScore($game, $seat)['ownScore'])->toBe(0);

    // Sa page : membre de la partie, pas de la manche en cours.
    $state = lateJoinState($this, $room, $joined);
    $seats = collect($state['seats']);

    expect($state['gameRef'])->toBe(GameRef::for($game))
        ->and($state['self']['member'])->toBeFalse()
        ->and($state['self']['participates'])->toBeFalse()
        ->and($state['self']['ownScore'])->toBe(0)
        ->and($seats->firstWhere('publicId', $seat->public_id)['firstRoundNumber'] ?? null)->toBe(2);

    // Complet l'emporte sur l'entrée des retardataires (§ 7.2).
    Room::query()->whereKey($room->id)->update([
        'capacity' => Player::query()->whereBelongsTo($room)->holdingSeat()->count(),
    ]);

    expect(lateJoinEntryState($this, $room, Locale::French)[0])->toBe('full');

    // La manche 1 se joue jusqu'au bout ; à `T₁` de la manche 2, le moteur
    // crée sa ligne `round_player` (E10-49) : il joue, toujours à 0 point.
    EngineFixtures::play($first);
    $second = EngineFixtures::round($game, 2);
    EngineFixtures::openTier($second, 1);

    expect(RoundPlayer::query()->where('round_id', $second->id)->where('player_id', $seat->id)->value('input_state'))
        ->toBe(RoundPlayerInputState::Open)
        ->and(Scoreboard::seatScore($game->refresh(), $seat)['ownScore'])->toBe(0);
});

it('admet le retardataire à la manche de remplacement quand elle est la prochaine à jouer', function (): void {
    [$room, $game] = lateJoinGame(reserve: 1);
    $first = EngineFixtures::round($game, 1);
    $second = EngineFixtures::round($game, 2);
    $third = EngineFixtures::round($game, 3);

    // Manche 1 jouée jusqu'à sa révélation, qui programme la manche 2.
    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);
    EngineFixtures::reveal($first);

    // La manche 2 est annulée avant de démarrer : la réserve la remplace,
    // sous son numéro, et se joue avant la manche 3.
    $cancelledAt = Date::now()->toImmutable();
    DB::transaction(static fn () => app(CancelRound::class)->handle($second->refresh(), RoundIncidentReason::FrameUnavailable, $cancelledAt));

    $replacement = EngineFixtures::round($game, $game->rounds_count + 1);

    expect($second->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and($replacement->round_number)->toBe(2)
        ->and($replacement->status)->toBe(RoundStatus::Pending)
        ->and($replacement->sequence_index)->toBeGreaterThan($third->sequence_index);

    // Pendant la révélation de la manche 1 : la remplaçante est la cible,
    // jamais la manche 3, de `sequence_index` plus petit, ni la manche
    // annulée.
    $recorder = RecordingBroadcaster::install();
    lateJoinEnter($this, $room, 'Remplaçant');
    $seat = lateJoinSeat($room, 'Remplaçant');

    expect(lateJoinParticipation($game, $seat)?->first_round_number)->toBe(2)
        ->and($recorder->sent[0]['payload']['seat']['firstRoundNumber'] ?? null)->toBe(2);

    // Fin de la révélation, puis `T₁` de la remplaçante : il y joue, et
    // nulle part ailleurs.
    EngineFixtures::endReveal($first);
    EngineFixtures::openTier($replacement, 1);

    expect($replacement->refresh()->status)->toBe(RoundStatus::Running)
        ->and(lateJoinPlays($replacement, $seat))->toBeTrue()
        ->and(lateJoinPlays($second, $seat))->toBeFalse()
        ->and(lateJoinPlays($first, $seat))->toBeFalse();

    // La remplaçante démarrée n'est plus une cible : le suivant entre à la
    // manche 3.
    lateJoinEnter($this, $room, 'Suivant', 7);

    expect(lateJoinParticipation($game, lateJoinSeat($room, 'Suivant'))?->first_round_number)->toBe(3);

    // Réserve épuisée : la manche 3, programmée par la révélation de la
    // remplaçante puis annulée, n'a plus de remplaçante. Annulée, elle n'est
    // jamais une cible, même programmée dans le futur : l'entrée attend la
    // partie suivante.
    foreach (range(2, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($replacement, $tierIndex);
    }

    EngineFixtures::close($replacement);
    EngineFixtures::reveal($replacement);

    $third->refresh();
    $thirdPlannedAt = $third->started_at;
    DB::transaction(static fn () => app(CancelRound::class)->handle($third, RoundIncidentReason::FrameUnavailable, Date::now()->toImmutable()));

    expect($third->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and($thirdPlannedAt?->greaterThan(Date::now()))->toBeTrue()
        ->and(lateJoinEntryState($this, $room, Locale::French)[0])->toBe('in_progress');

    lateJoinEnter($this, $room, 'Annule', 8);

    expect(lateJoinParticipation($game, lateJoinSeat($room, 'Annule')))->toBeNull();
});

it('fait attendre la partie suivante quand aucune manche numérotée ne reste à démarrer', function (): void {
    // Une manche de réserve, sans numéro, reste `pending` jusqu'au bout :
    // elle n'est jamais une cible.
    [$room, $game] = lateJoinGame(reserve: 1);
    $last = EngineFixtures::round($game, $game->rounds_count);
    $reserve = EngineFixtures::round($game, $game->rounds_count + 1);

    foreach (range(1, $game->rounds_count - 1) as $sequenceIndex) {
        EngineFixtures::play(EngineFixtures::round($game, $sequenceIndex));
    }

    expect($last->refresh()->status)->toBe(RoundStatus::Pending)
        ->and($reserve->refresh()->round_number)->toBeNull()
        ->and($reserve->status)->toBe(RoundStatus::Pending);

    // 1. `T₁` de la dernière manche franchi, son ouverture en retard : elle
    // est jouée, rien ne reste à démarrer.
    Date::setTestNow(EngineFixtures::opensAt($last, 1));

    foreach ([Locale::French, Locale::English] as $locale) {
        expect(lateJoinEntryState($this, $room, $locale))
            ->toBe(['in_progress', trans('room.join.in_progress', [], $locale->value)]);
    }

    $recorder = RecordingBroadcaster::install();
    lateJoinEnter($this, $room, 'Tardif');
    $late = lateJoinSeat($room, 'Tardif');

    // Un siège, compté dans la capacité, sans participation ; annoncé sans
    // manche d'entrée.
    expect(lateJoinParticipation($game, $late))->toBeNull()
        ->and(Player::query()->whereBelongsTo($room)->holdingSeat()->pluck('id')->all())->toContain($late->id)
        ->and($recorder->sent[0]['payload']['seat'] ?? null)->toMatchArray(['publicId' => $late->public_id, 'firstRoundNumber' => null]);

    // 2. La dernière manche en cours : attente.
    EngineFixtures::openTier($last, 1);
    lateJoinEnter($this, $room, 'Encore', 7);

    expect(lateJoinParticipation($game, lateJoinSeat($room, 'Encore')))->toBeNull();

    // Aucun des deux ne joue la dernière manche.
    expect(lateJoinPlays($last, $late))->toBeFalse();

    // 3. Podium : la partie est figée, le salon toujours en partie jusqu'au
    // « Rejouer » ; l'entrée attend la partie suivante.
    foreach (range(2, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($last, $tierIndex);
    }

    EngineFixtures::close($last);
    EngineFixtures::reveal($last);
    EngineFixtures::endReveal($last);

    expect($game->refresh()->status)->toBe(GameStatus::Completed)
        ->and($room->refresh()->status)->toBe(RoomStatus::Playing)
        ->and(lateJoinEntryState($this, $room, Locale::French)[0])->toBe('in_progress');

    lateJoinEnter($this, $room, 'Podium', 8);

    expect(lateJoinParticipation($game, lateJoinSeat($room, 'Podium')))->toBeNull()
        ->and(GamePlayer::query()->whereBelongsTo($game)->count())->toBe(2);

    // 4. Partie interrompue (gel en pause) : ses manches numérotées restent
    // `pending`, mais la partie est figée — aucune participation n'y naît.
    [$paused, $interrupted] = lateJoinGame();
    $frozenAt = Date::now()->toImmutable();
    app(FinalizeGame::class)->handle($interrupted, GameStatus::Interrupted, $frozenAt);

    expect($interrupted->refresh()->ended_at)->not->toBeNull()
        ->and(Round::query()->where('game_id', $interrupted->id)->lateJoinableAt($frozenAt)->count())->toBe($interrupted->rounds_count)
        ->and(lateJoinEntryState($this, $paused, Locale::French)[0])->toBe('in_progress');

    lateJoinEnter($this, $paused, 'Gel', 9);

    expect(lateJoinParticipation($interrupted, lateJoinSeat($paused, 'Gel')))->toBeNull();
});

it('ne donne jamais au retardataire la manche en cours', function (): void {
    [$room, $game] = lateJoinGame();
    $first = EngineFixtures::round($game, 1);
    $second = EngineFixtures::round($game, 2);
    $firstOpensAt = EngineFixtures::opensAt($first, 1);

    // 1. Pendant le décompte de lancement, une milliseconde avant `T₁` : la
    // manche 1 n'a pas démarré, elle est la suivante.
    Date::setTestNow($firstOpensAt->subMillisecond());
    lateJoinEnter($this, $room, 'Avant');
    $before = lateJoinSeat($room, 'Avant');

    expect(lateJoinParticipation($game, $before)?->first_round_number)->toBe(1);

    // 2. À `T₁` pile, la manche 1 encore `pending` (job d'ouverture en
    // retard) : elle est jouée — manche suivante.
    Date::setTestNow($firstOpensAt);
    expect($first->refresh()->status)->toBe(RoundStatus::Pending);

    $atFirst = lateJoinEnter($this, $room, 'Pile', 7);
    $onTime = lateJoinSeat($room, 'Pile');

    expect(lateJoinParticipation($game, $onTime)?->first_round_number)->toBe(2);

    // L'ouverture, en retard : le premier joue la manche 1, le second non,
    // et sa page ne le dit pas membre de la manche en cours.
    EngineFixtures::openTier($first, 1, $firstOpensAt->addSeconds(2));

    expect(lateJoinPlays($first, $before))->toBeTrue()
        ->and(lateJoinPlays($first, $onTime))->toBeFalse()
        ->and(lateJoinState($this, $room, $atFirst)['self']['member'])->toBeFalse();

    // 3. Manche 1 en cours : manche 2.
    lateJoinEnter($this, $room, 'Pendant', 8);

    expect(lateJoinParticipation($game, lateJoinSeat($room, 'Pendant'))?->first_round_number)->toBe(2);

    // 4. Révélation de la manche 1, la manche 2 programmée : elle n'a pas
    // démarré, elle est la cible ; à son `T₁` pile, c'est la manche 3.
    foreach (range(2, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);
    EngineFixtures::reveal($first);

    $secondOpensAt = EngineFixtures::opensAt($second, 1);
    Date::setTestNow($secondOpensAt->subMillisecond());
    lateJoinEnter($this, $room, 'Revelation', 9);

    expect(lateJoinParticipation($game, lateJoinSeat($room, 'Revelation'))?->first_round_number)->toBe(2);

    Date::setTestNow($secondOpensAt);
    lateJoinEnter($this, $room, 'Frontiere', 10);
    $boundary = lateJoinSeat($room, 'Frontiere');

    expect(lateJoinParticipation($game, $boundary)?->first_round_number)->toBe(3);

    EngineFixtures::endReveal($first);
    EngineFixtures::openTier($second, 1);

    expect(lateJoinPlays($second, $boundary))->toBeFalse()
        ->and(lateJoinPlays($second, lateJoinSeat($room, 'Revelation')))->toBeTrue();
});

it("n'admet personne en partie quand les retardataires sont fermés", function (): void {
    [$room, $game] = lateJoinGame(open: false);
    $first = EngineFixtures::round($game, 1);

    // Même pendant le décompte de lancement : aucune participation.
    lateJoinEnter($this, $room, 'Decompte');

    EngineFixtures::openTier($first, 1);

    foreach ([Locale::French, Locale::English] as $locale) {
        expect(lateJoinEntryState($this, $room, $locale))
            ->toBe(['in_progress', trans('room.join.in_progress', [], $locale->value)]);
    }

    $recorder = RecordingBroadcaster::install();
    $joined = lateJoinEnter($this, $room, 'Ferme', 7);
    $closed = lateJoinSeat($room, 'Ferme');

    // Un siège qui compte dans la capacité, sans participation : il jouera
    // la partie suivante, au « Rejouer ».
    expect(lateJoinParticipation($game, $closed))->toBeNull()
        ->and(lateJoinParticipation($game, lateJoinSeat($room, 'Decompte')))->toBeNull()
        ->and(GamePlayer::query()->whereBelongsTo($game)->count())->toBe(2)
        ->and(Player::query()->whereBelongsTo($room)->holdingSeat()->count())->toBe(4)
        ->and($recorder->sent[0]['payload']['gameRef'] ?? null)->toBe(GameRef::for($game))
        ->and($recorder->sent[0]['payload']['seat'] ?? null)->toMatchArray(['publicId' => $closed->public_id, 'firstRoundNumber' => null]);

    // Sa page : la partie du salon, hors des sièges de la partie, non membre.
    $state = lateJoinState($this, $room, $joined);

    expect($state['gameRef'])->toBe(GameRef::for($game))
        ->and($state['self']['member'])->toBeFalse()
        ->and(array_column($state['seats'], 'publicId'))->not->toContain($closed->public_id);

    // Aucune manche suivante ne le fait jouer.
    EngineFixtures::play($first);
    $second = EngineFixtures::round($game, 2);
    EngineFixtures::openTier($second, 1);

    expect(lateJoinPlays($second, $closed))->toBeFalse()
        ->and(RoundPlayer::query()->where('round_id', $second->id)->count())->toBe(2);
});
