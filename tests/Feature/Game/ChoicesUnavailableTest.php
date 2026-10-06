<?php

use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundStatus;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\GameTrace;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use App\Support\Game\GameJournal;
use App\Support\Game\GameStateBuilder;
use App\Support\Identity\PlayerToken;
use App\Support\Perf\GameTraceWriter;
use App\Support\Perf\PerfRecorder;
use App\Support\Realtime\GameRef;
use App\ValueObjects\Answers\ChoicesPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Answers\ChoiceSetFixtures;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Game\GameJournalRecorder;
use Tests\Support\Realtime\RecordingBroadcaster;

/*
|--------------------------------------------------------------------------
| Cas terminal du QCM, tracé et signalé — D54 du 02/10
|--------------------------------------------------------------------------
|
| Spec 70 § 10.7 et exigence 17, spec 60 § 4.7, § 6.3 (étape 7), § 11.5 et
| § 12.2, spec 90 § 10. Quand `ComposeChoiceSets` aboutit au cas terminal,
| une ligne `game.choices_unavailable` part au journal `game` et à
| `game_trace` (cause, jamais un titre) ; en Normal, `tier.opened` du palier
| du QCM porte `choicesUnavailable = true`, après commit, et le paquet de
| resynchronisation porte le même booléen. Expert : rien ; Facile :
| l'annulation existante, inchangée.
|
| Le cas terminal se provoque sans dépendre de l'échelle des leurres : le
| catalogue entier compte moins de quatre films (`MIN_ROUNDS_COUNT` films
| tirés, aucun autre), donc aucun tirage, même de dernier recours, ne trouve
| trois leurres.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    Config::set('perf.enabled', true);
    app(PerfRecorder::class)->reset();
    Date::setTestNow(CarbonImmutable::parse('2026-10-02 10:00:00.250'));

    $this->recorder = RecordingBroadcaster::install();
    $this->journal = GameJournalRecorder::start();
});

afterEach(function (): void {
    $this->journal->stop();
});

/**
 * Une partie multijoueur à cette difficulté, deux sièges (FR, EN), manche 1
 * programmée et ouverte à son palier 1.
 *
 * @return array{game: Game, round: Round, seats: list<Player>}
 */
function choicesUnavailableRound(InputDifficulty $difficulty, bool $open = true): array
{
    $locales = [Locale::French, Locale::English];
    $tokens = array_map(static fn (Locale $locale): PlayerToken => PlayerToken::mint($locale), $locales);
    [$game, $round, $seats] = SubmissionFixtures::openedRound($tokens, SubmissionFixtures::settings($difficulty), open: false);

    foreach ($seats as $index => $seat) {
        $seat->forceFill(['locale' => $locales[$index]])->save();
    }

    if ($open) {
        EngineFixtures::openTier($round, 1);
    }

    return ['game' => $game->refresh(), 'round' => $round->refresh(), 'seats' => $seats];
}

/**
 * Ouvre les paliers 2 à `$last` dans l'ordre, chacun à son `Tᵢ` : le jeton
 * d'un palier est frappé par l'ouverture du précédent.
 */
function choicesUnavailableOpenUpTo(Round $round, int $last): void
{
    for ($index = 2; $index <= $last; $index++) {
        EngineFixtures::openTier($round, $index);
    }
}

/**
 * Les charges `tier.opened` enregistrées, dans l'ordre.
 *
 * @param  list<array{channels: list<string>, event: string, payload: array<string, mixed>, json: string}>  $sent
 * @return list<array<string, mixed>>
 */
function choicesUnavailableTierOpened(array $sent): array
{
    return array_values(array_map(
        static fn (array $row): array => $row['payload'],
        array_filter($sent, static fn (array $row): bool => $row['event'] === 'tier.opened'),
    ));
}

/** Le booléen de manche du paquet de resynchronisation, à `$now`. */
function choicesUnavailableInPacket(Game $game, Player $seat, CarbonImmutable $now): ?bool
{
    $packet = GameStateBuilder::build($game->refresh(), $seat->refresh(), $now, null);

    return $packet['round']['choicesUnavailable'] ?? null;
}

it('en Normal, le cas terminal journalise game.choices_unavailable sans titre ni identifiant de film', function (): void {
    ['game' => $game, 'round' => $round] = choicesUnavailableRound(InputDifficulty::Normal);
    $tierIndex = $game->frames_per_round;

    expect(Movie::query()->count())->toBeLessThan(ChoicesPayload::COUNT + 1);

    choicesUnavailableOpenUpTo($round, $tierIndex);

    expect($round->refresh()->decoy_movie_id_1)->toBeNull()
        ->and(RoundChoiceSet::query()->where('round_id', $round->id)->exists())->toBeFalse()
        ->and($this->journal->contexts(GameJournal::CHOICES_UNAVAILABLE))->toBe([[
            'gameRef' => GameRef::for($game),
            'mode' => 'multiplayer',
            'inputDifficulty' => 'normal',
            'sequenceIndex' => $round->sequence_index,
            'roundNumber' => $round->round_number,
            'tierIndex' => $tierIndex,
            'cause' => GameJournal::CHOICES_CAUSE_NO_DECOYS,
        ]])
        ->and($this->journal->lines(GameJournal::CHOICES_UNAVAILABLE)[0]['level_name'] ?? null)->toBe('WARNING');

    // Ni titre, ni identifiant de film, ni identifiant interne de manche.
    $raw = $this->journal->raw();

    foreach (Movie::query()->with('titles')->get() as $movie) {
        expect($raw)->not->toContain($movie->title_original);

        foreach ($movie->titles as $title) {
            expect($raw)->not->toContain($title->title);
        }
    }

    expect($raw)->not->toContain('"movieId"')
        ->and($raw)->not->toContain('"roundId"');

    // La chronologie technique (D47 du 01/10) porte la même cause.
    $trace = GameTrace::query()->where('event', GameTraceWriter::CHOICES_UNAVAILABLE)->sole();

    expect($trace->game_id)->toBe($game->id)
        ->and($trace->sequence_index)->toBe($round->sequence_index)
        ->and($trace->tier_index)->toBe($tierIndex)
        ->and($trace->details)->toBe(['cause' => GameJournal::CHOICES_CAUSE_NO_DECOYS, 'inputDifficulty' => 'normal']);
});

it('en Normal, tier.opened du palier du QCM porte choicesUnavailable, émis seulement après le commit', function (): void {
    ['game' => $game, 'round' => $round] = choicesUnavailableRound(InputDifficulty::Normal);
    $tierIndex = $game->frames_per_round;
    choicesUnavailableOpenUpTo($round, $tierIndex - 1);
    $this->recorder->sent = [];

    DB::transaction(function () use ($round, $tierIndex): void {
        EngineFixtures::openTier($round, $tierIndex);

        // Rien ne part tant que la transaction englobante n'est pas validée :
        // ni la diffusion, ni la ligne du journal.
        expect($this->recorder->sent)->toBe([])
            ->and($this->journal->lines(GameJournal::CHOICES_UNAVAILABLE))->toBe([]);
    });

    $opened = choicesUnavailableTierOpened($this->recorder->sent);

    expect(array_column($this->recorder->sent, 'event'))->toBe(['tier.opened'])
        ->and($opened[0]['tierIndex'])->toBe($tierIndex)
        ->and($opened[0]['choicesUnavailable'])->toBeTrue()
        ->and($this->journal->contexts(GameJournal::CHOICES_UNAVAILABLE))->toHaveCount(1)
        ->and($round->refresh()->status)->toBe(RoundStatus::Running);
});

it('en Normal, le paquet de resynchronisation porte choicesUnavailable après T_N, et pas avant', function (): void {
    ['game' => $game, 'round' => $round, 'seats' => [$seat]] = choicesUnavailableRound(InputDifficulty::Normal);
    $tierIndex = $game->frames_per_round;
    $tN = EngineFixtures::opensAt($round, $tierIndex);

    choicesUnavailableOpenUpTo($round, $tierIndex - 1);

    expect(choicesUnavailableInPacket($game, $seat, $tN->subMilliseconds(1)))->toBeFalse();

    EngineFixtures::openTier($round, $tierIndex);

    // Un client qui recharge après T_N voit le même état que celui qui a
    // reçu `tier.opened`.
    expect(choicesUnavailableInPacket($game, $seat, $tN->addSecond()))->toBeTrue();
});

it('en Normal, un QCM composé laisse choicesUnavailable faux partout et ne journalise rien', function (): void {
    SubmissionFixtures::decoyCandidates();
    ['game' => $game, 'round' => $round, 'seats' => [$seat]] = choicesUnavailableRound(InputDifficulty::Normal);
    $tierIndex = $game->frames_per_round;
    $tN = EngineFixtures::opensAt($round, $tierIndex);

    choicesUnavailableOpenUpTo($round, $tierIndex);

    expect($round->refresh()->decoy_movie_id_1)->not->toBeNull()
        ->and(array_column(choicesUnavailableTierOpened($this->recorder->sent), 'choicesUnavailable'))->each->toBeFalse()
        ->and(choicesUnavailableInPacket($game, $seat, $tN->addSecond()))->toBeFalse()
        ->and($this->journal->lines(GameJournal::CHOICES_UNAVAILABLE))->toBe([])
        ->and(GameTrace::query()->where('event', GameTraceWriter::CHOICES_UNAVAILABLE)->exists())->toBeFalse();
});

it("en Expert, aucun palier ne porte choicesUnavailable et rien n'est journalisé", function (): void {
    ['game' => $game, 'round' => $round, 'seats' => [$seat]] = choicesUnavailableRound(InputDifficulty::Expert);

    choicesUnavailableOpenUpTo($round, $game->frames_per_round);

    $opened = choicesUnavailableTierOpened($this->recorder->sent);

    expect($opened)->toHaveCount($game->frames_per_round)
        ->and(array_column($opened, 'choicesUnavailable'))->each->toBeFalse()
        ->and(choicesUnavailableInPacket($game, $seat, EngineFixtures::opensAt($round, $game->frames_per_round)->addSecond()))->toBeFalse()
        ->and($this->journal->lines(GameJournal::CHOICES_UNAVAILABLE))->toBe([]);
});

it("en Facile, le cas terminal annule la manche comme avant, journalisé avant l'annulation, sans tier.opened", function (): void {
    ['game' => $game, 'round' => $round, 'seats' => [$seat]] = choicesUnavailableRound(InputDifficulty::Easy, open: false);
    $this->recorder->sent = [];

    EngineFixtures::openTier($round, 1);

    $messages = array_column($this->journal->lines(), 'message');
    $unavailable = array_search(GameJournal::CHOICES_UNAVAILABLE, $messages, true);
    $cancelled = array_search(GameJournal::ROUND_CANCELLED, $messages, true);

    expect($round->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and(choicesUnavailableTierOpened($this->recorder->sent))->toBe([])
        ->and(array_column($this->recorder->sent, 'event'))->toContain('round.cancelled')
        ->and($unavailable)->toBeInt()
        ->and($cancelled)->toBeInt()
        ->and($unavailable < $cancelled)->toBeTrue()
        ->and($this->journal->contexts(GameJournal::CHOICES_UNAVAILABLE)[0]['inputDifficulty'] ?? null)->toBe('easy')
        ->and($this->journal->contexts(GameJournal::ROUND_CANCELLED)[0]['reason'] ?? null)->toBe('choices_unavailable');
});

it('une exception de la phase de calcul journalise la cause compute_failed, sans le titre du message', function (): void {
    Exceptions::fake();

    $secret = 'Le Phare Englouti Des Brumes';
    $target = SubmissionFixtures::movie('The Sunken Mist Lighthouse', $secret);
    PoolFixtures::movies(4);

    $at = Date::now()->toImmutable();
    $game = ChoiceSetFixtures::game(Room::factory()->create(), ChoiceSetFixtures::settings(InputDifficulty::Normal), ChoiceSetFixtures::seeds()[0]);
    $round = ChoiceSetFixtures::round($game, $target, $at);
    ChoiceSetFixtures::seat($round, Locale::French);

    // Une donnée de catalogue fait lever le calcul, avec un titre dans le
    // message de l'exception.
    $armed = true;
    Movie::retrieved(static function () use (&$armed, $secret): void {
        if ($armed) {
            throw new RuntimeException("titre illisible : {$secret}");
        }
    });

    $composed = DB::transaction(static fn (): bool => ChoiceSetFixtures::compose($round, $at));
    $armed = false;

    expect($composed)->toBeFalse()
        ->and($this->journal->contexts(GameJournal::CHOICES_UNAVAILABLE))->toHaveCount(1)
        ->and($this->journal->contexts(GameJournal::CHOICES_UNAVAILABLE)[0]['cause'])->toBe(GameJournal::CHOICES_CAUSE_COMPUTE_FAILED)
        ->and($this->journal->raw())->not->toContain($secret)
        ->and(GameTrace::query()->where('event', GameTraceWriter::CHOICES_UNAVAILABLE)->sole()->details)
        ->toBe(['cause' => GameJournal::CHOICES_CAUSE_COMPUTE_FAILED, 'inputDifficulty' => 'normal']);
});
