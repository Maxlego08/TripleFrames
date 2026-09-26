<?php

use App\Actions\Game\CancelRound;
use App\Actions\Game\MintTierServeToken;
use App\Actions\Game\ScheduleRound;
use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\Locale;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Jobs\Game\AdvanceRound;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Models\SeenFrame;
use App\Settings\EngineConstants;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Game\RoundStep;
use App\Support\Game\ServeUrl;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\WireTime;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\LobbyWrites;

/*
|--------------------------------------------------------------------------
| Frappe et ouverture — spec 60 § 6.1-6.3, contrat C8 § 4.1-4.3 (lots L60-5, L60-6)
|--------------------------------------------------------------------------
|
| `MintTierServeToken` est la seule écrivaine de `serve_token`,
| `served_frame_id` et `substitution_reason` ; `ScheduleRound` frappe le
| palier 1 dans la transaction qui écrit `started_at`. Les intitulés de ce
| lot portent sur la frappe, l'idempotence, la substitution, l'annulation
| faute de variante et le jeton lié à une manche. Les autres intitulés du
| fichier (60 § 20 : `served_at` théorique, requêtes anticipées,
| `seen_frame`, palier suivant une fin anticipée ou une annulation, palier
| dont l'ouverture annule la manche) arrivent avec `OpenTier` (L60-6), qui
| complète aussi le premier intitulé de sa moitié « à l'ouverture du palier
| i−1 ».
|
| Vraies variantes, fichiers réels sur le disque `frames` simulé
| (`PoolFixtures`) : la présence du fichier fait partie de la règle (E10-25,
| E72-2). Horloge figée à la milliseconde.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->now = CarbonImmutable::parse('2026-09-26 14:00:00.250');
    Date::setTestNow($this->now);
});

/**
 * Une manche `pending` de la partie sur `$movie`, ses `N` paliers
 * matérialisés sur les variantes nominales du film.
 */
function mintRound(Game $game, Movie $movie, int $sequenceIndex = 1): Round
{
    $round = Round::factory()
        ->forGame($game)
        ->forMovie($movie)
        ->atSequence($sequenceIndex)
        ->state(['duration_ms' => $game->settings_snapshot->roundDuration() * 1000])
        ->create();

    foreach (FrameLevelCoverage::nominal($game->frames_per_round) as $index => $level) {
        RoundTier::factory()
            ->for($round)
            ->atTier($index + 1, settings: $game->settings_snapshot)
            ->forFrame(EngineFixtures::variant($movie, $level))
            ->create();
    }

    return $round;
}

/**
 * Frappe le palier `$tierIndex` de la manche, dans une transaction, et le
 * rend relu en base.
 */
function mintTier(Round $round, int $tierIndex, CarbonImmutable $now): RoundTier
{
    $tier = EngineFixtures::tier($round, $tierIndex);

    DB::transaction(static fn () => app(MintTierServeToken::class)->handle($tier, $now));

    return $tier->refresh();
}

/**
 * Les requêtes d'écriture sur `round_tier` exécutées par `$callback`.
 *
 * @return list<string>
 */
function mintTierWrites(Closure $callback): array
{
    $writes = [];

    DB::listen(static function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(update|insert)\b.*\bround_tier\b/is', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $callback();

    return $writes;
}

test('le jeton du palier 1 est frappé à la programmation, celui du palier i à l\'ouverture du palier i−1', function (): void {
    Queue::fake([AdvanceRound::class]);
    $recorder = RecordingBroadcaster::install();

    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);

    // Matérialisés, aucun palier n'est frappé.
    expect(RoundTier::query()->whereNotNull('serve_token')->count())->toBe(0);

    // Au bout du décompte, à une fraction de seconde telle que la grâce de
    // frontière franchisse une seconde : l'`expires` de l'URL, tronqué à la
    // seconde, la voit (§ 7.5).
    $startsAt = $this->now->startOfSecond()
        ->addMilliseconds(EngineConstants::launchCountdownMs() + 1000 - intdiv($game->tier_grace_ms, 2));

    DB::transaction(static fn () => app(ScheduleRound::class)->handle($round, $startsAt));

    // À la programmation : `started_at` et le jeton du palier 1, et lui seul,
    // dans la même transaction ; aucun palier n'est ouvert.
    $round->refresh();
    $first = EngineFixtures::tier($round, 1);

    expect($round->status)->toBe(RoundStatus::Pending)
        ->and($round->started_at?->format('Y-m-d H:i:s.v'))->toBe($startsAt->format('Y-m-d H:i:s.v'))
        ->and($first->serve_token)->toMatch('/^[0-9a-f]{32}$/')
        ->and($first->served_frame_id)->toBe($first->frame_id)
        ->and($first->substitution_reason)->toBeNull()
        ->and($first->served_at)->toBeNull()
        ->and(RoundTier::query()->whereNotNull('serve_token')->pluck('id')->all())->toBe([$first->id]);

    // Après commit : le job de frontière `OpenTier(1)` sur la file `game`,
    // délai exprimé en INSTANT, jamais en entier (§ 4.2).
    Queue::assertPushedOn('game', AdvanceRound::class, function (AdvanceRound $job) use ($game, $round, $startsAt): bool {
        return $job instanceof ShouldQueueAfterCommit
            && $job->gameId === $game->id
            && $job->roundId === $round->id
            && $job->step === RoundStep::OpenTier
            && $job->tierIndex === 1
            && $job->dueAt === WireTime::iso($startsAt)
            && $job->delay instanceof CarbonImmutable
            && $job->delay->equalTo(CarbonImmutable::parse(WireTime::iso($startsAt)))
            && $job->tries === 1;
    });
    Queue::assertPushed(AdvanceRound::class, 1);

    // En multijoueur, `round.scheduled` : la chronologie publique et la seule
    // image du palier 1, sans niveau, chemin ni identifiant.
    $scheduled = array_values(array_filter($recorder->sent, static fn (array $sent): bool => $sent['event'] === 'round.scheduled'));

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]['channels'])->toBe(['presence-'.ChannelNames::room($game->room()->firstOrFail())]);

    $payload = $scheduled[0]['payload'];
    $settings = $game->settings_snapshot;

    expect($payload['round'])->toBe([
        'sequenceIndex' => 1,
        'roundNumber' => 1,
        'roundsCount' => $game->rounds_count,
        'startsAt' => WireTime::iso($startsAt),
        'durationMs' => $settings->roundDuration() * 1000,
        'tiers' => array_map(
            static fn (int $index): array => [
                'tierIndex' => $index + 1,
                'startsAtOffsetMs' => array_sum(array_slice($settings->tierDurations, 0, $index)) * 1000,
                'durationMs' => $settings->tierDurations[$index] * 1000,
                'points' => $settings->tierPoints[$index],
            ],
            array_keys($settings->tierDurations),
        ),
        'choicesAtTierIndex' => $game->input_difficulty->choicesOpenTierIndex($game->frames_per_round),
    ])
        ->and($payload['image']['tierIndex'])->toBe(1)
        ->and($payload['image']['fetchNotBefore'])->toBe(WireTime::iso($startsAt->subMilliseconds($game->preload_lead_ms)));

    // L'URL : relative, adressée par le jeton de la MANCHE, signée, expirant
    // à la fin prévue de la révélation plus la marge (§ 7.5) ; la route
    // existe et ne sert rien sans son prédicat de service (L60-8).
    $url = $payload['image']['url'];
    $expires = $startsAt->addMilliseconds(
        $settings->roundDuration() * 1000 + $game->tier_grace_ms + $settings->revealDuration * 1000
        + EngineConstants::serveUrlExpiryMarginMs(),
    )->getTimestamp();

    expect(parse_url($url, PHP_URL_HOST))->toBeNull()
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/f/'.$first->serve_token)
        ->and($url)->toContain('expires='.$expires)
        ->and(URL::hasValidRelativeSignature(Request::create($url)))->toBeTrue();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(array_keys($query))->toBe(['expires', 'signature']);

    $this->get($url)->assertNotFound();

    // En solo : la même frappe, le même job, aucune diffusion (§ 11.2).
    $recorder->sent = [];
    $solo = EngineFixtures::game(EngineFixtures::settings(), solo: true);
    EngineFixtures::materialize($solo);
    $soloRound = EngineFixtures::round($solo, 1);

    DB::transaction(static fn () => app(ScheduleRound::class)->handle($soloRound, $startsAt));

    expect(EngineFixtures::tier($soloRound, 1)->serve_token)->toMatch('/^[0-9a-f]{32}$/')
        ->and(RoundTier::query()->where('round_id', $soloRound->id)->whereNotNull('serve_token')->count())->toBe(1)
        ->and($recorder->sent)->toBe([]);

    Queue::assertPushed(AdvanceRound::class, 2);

    // Un cran à l'avance (lot L60-6) : l'ouverture du palier i−1 frappe le
    // palier i, et lui seul — le palier que `tier.opened.next` annonce ; le
    // dernier palier ne frappe plus rien.
    foreach (range(1, $game->frames_per_round) as $opened) {
        EngineFixtures::openTier($round, $opened);

        $minted = min($opened + 1, $game->frames_per_round);

        expect(RoundTier::query()->where('round_id', $round->id)->whereNotNull('serve_token')->orderBy('tier_index')->pluck('tier_index')->all())
            ->toBe(range(1, $minted));

        if ($opened < $game->frames_per_round) {
            $next = EngineFixtures::tier($round, $opened + 1);

            expect($next->serve_token)->toMatch('/^[0-9a-f]{32}$/')
                ->and($next->served_frame_id)->toBe($next->frame_id)
                ->and($next->served_at)->toBeNull();

            $opening = collect($recorder->sent)->last(static fn (array $sent): bool => $sent['event'] === 'tier.opened');

            expect($opening['payload']['tierIndex'] ?? null)->toBe($opened)
                ->and(parse_url((string) ($opening['payload']['next']['url'] ?? ''), PHP_URL_PATH))->toBe('/f/'.$next->serve_token);
        }
    }
});

test('la frappe est idempotente', function (): void {
    Queue::fake([AdvanceRound::class]);

    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);

    DB::transaction(fn () => app(ScheduleRound::class)->handle($round, $this->now->addSeconds(10)));

    $first = EngineFixtures::tier($round, 1);
    $minted = $first->only(['serve_token', 'served_frame_id', 'substitution_reason', 'updated_at']);

    // La frame servie devient non servable et perd son fichier : une frappe
    // rejouée ne revoit rien — ni jeton, ni variante, ni motif — et n'écrit
    // rien. C'est `OpenTier` qui revérifie, jamais la frappe (§ 6.3).
    $served = Frame::query()->findOrFail($first->served_frame_id);
    Storage::disk(FrameStoragePrefix::DISK)->delete((string) $served->game_path);
    $served->forceFill(['availability' => ContentAvailability::Unpublished])->save();

    $writes = mintTierWrites(fn () => mintTier($round, 1, $this->now->addSecond()));

    expect($writes)->toBe([])
        ->and(EngineFixtures::tier($round, 1)->only(array_keys($minted)))->toEqual($minted);

    // Une reprogrammation de la manche `pending` réécrit son origine et
    // réutilise le jeton déjà frappé (§ 14.2).
    $later = $this->now->addSeconds(30);

    DB::transaction(static fn () => app(ScheduleRound::class)->handle($round, $later));

    expect($round->refresh()->started_at?->equalTo($later))->toBeTrue()
        ->and(EngineFixtures::tier($round, 1)->only(array_keys($minted)))->toEqual($minted)
        ->and(RoundTier::query()->whereNotNull('serve_token')->count())->toBe(1);

    // Une substitution frappée l'est une fois aussi : même remplaçante, même
    // motif, même jeton.
    $second = EngineFixtures::tier($round, 2);
    $drawn = Frame::query()->findOrFail($second->frame_id);
    Frame::factory()->for(Movie::query()->findOrFail($round->movie_id))->level($drawn->frame_level)->published()->create();
    $drawn->forceFill(['availability' => ContentAvailability::Suspended])->save();

    $substituted = mintTier($round, 2, $this->now);
    $state = $substituted->only(['serve_token', 'served_frame_id', 'substitution_reason']);

    expect($state['substitution_reason'])->toBe(RoundIncidentReason::FrameUnavailable)
        ->and($state['served_frame_id'])->not->toBe($second->frame_id);

    expect(mintTierWrites(fn () => mintTier($round, 2, $this->now)))->toBe([])
        ->and(EngineFixtures::tier($round, 2)->only(array_keys($state)))->toEqual($state);
});

test('une frame non servable ou absente du disque est substituée à la frappe avec frame_unavailable', function (): void {
    $game = EngineFixtures::game(EngineFixtures::settings());
    $level = FrameLevelCoverage::nominal($game->frames_per_round)[0];

    // Chaque cas rend la variante tirée du palier 1 indisponible d'UNE façon,
    // et laisse UNE seule autre variante servable au même niveau — la
    // remplaçante est désignée sans départage : chaque disponibilité non
    // publiée, chaque état de traitement non prêt, aucun dérivé, dérivé absent
    // du disque, dérivé hors du préfixe `game/` (E72-2), variante supprimée du
    // catalogue (`frame_id` mis à NULL par la clé étrangère).
    $unservable = [];

    foreach (ContentAvailability::cases() as $availability) {
        if ($availability !== ContentAvailability::Published) {
            $unservable['disponibilité '.$availability->value] = ['availability' => $availability];
        }
    }

    foreach (FrameProcessingState::cases() as $state) {
        if ($state !== FrameProcessingState::Ready) {
            $unservable['traitement '.$state->value] = ['processing_state' => $state];
        }
    }

    $unservable['sans dérivé'] = ['game_path' => null];

    /** @var array<string, Closure(Movie, RoundTier): int> $cases */
    $cases = [];

    foreach ($unservable as $label => $columns) {
        $cases[$label] = static function (Movie $movie, RoundTier $tier) use ($level, $columns): int {
            Frame::query()->findOrFail($tier->frame_id)->forceFill($columns)->save();

            return Frame::factory()->for($movie)->level($level)->published()->create()->id;
        };
    }

    $cases['fichier absent du disque'] = static function (Movie $movie, RoundTier $tier) use ($level): int {
        Storage::disk(FrameStoragePrefix::DISK)->delete((string) Frame::query()->findOrFail($tier->frame_id)->game_path);

        return Frame::factory()->for($movie)->level($level)->published()->create()->id;
    };

    $cases['dérivé hors du préfixe game/'] = static function (Movie $movie, RoundTier $tier) use ($level): int {
        $drawn = Frame::query()->findOrFail($tier->frame_id);
        $drawn->forceFill(['game_path' => $drawn->master_path])->save();

        return Frame::factory()->for($movie)->level($level)->published()->create()->id;
    };

    // Une variante encore brouillon, donc supprimable, tirée puis retirée du
    // catalogue : la variante publiée du film reste seule au niveau.
    $cases['variante supprimée du catalogue'] = static function (Movie $movie, RoundTier $tier) use ($level): int {
        $published = EngineFixtures::variant($movie, $level)->id;
        $draft = Frame::factory()->for($movie)->level($level)->create();

        RoundTier::query()->whereKey($tier->id)->update(['frame_id' => $draft->id]);
        $draft->delete();

        expect(EngineFixtures::tier($tier->round, 1)->frame_id)->toBeNull();

        return $published;
    };

    foreach ($cases as $label => $breakDrawn) {
        $movie = PoolFixtures::movie(FrameLevelCoverage::nominal($game->frames_per_round));
        $round = mintRound($game, $movie, Round::query()->where('game_id', $game->id)->count() + 1);
        $expected = $breakDrawn($movie, EngineFixtures::tier($round, 1));

        $minted = mintTier($round, 1, $this->now);

        expect($minted->serve_token)->toMatch('/^[0-9a-f]{32}$/', $label)
            ->and($minted->served_frame_id)->toBe($expected, $label)
            ->and($minted->substitution_reason)->toBe(RoundIncidentReason::FrameUnavailable, $label)
            ->and(Frame::query()->findOrFail($expected)->frame_level)->toBe($level, $label)
            ->and($round->refresh()->status)->toBe(RoundStatus::Pending, $label);
    }

    // Témoin : une variante tirée servable, fichier présent, est retenue sans
    // motif, même quand une autre variante du niveau existe.
    $movie = PoolFixtures::movie(FrameLevelCoverage::nominal($game->frames_per_round));
    $round = mintRound($game, $movie, Round::query()->where('game_id', $game->id)->count() + 1);
    Frame::factory()->for($movie)->level($level)->published()->create();

    $kept = mintTier($round, 1, $this->now);

    expect($kept->served_frame_id)->toBe(EngineFixtures::variant($movie, $level)->id)
        ->and($kept->substitution_reason)->toBeNull();
});

test('sans variante servable, la frappe annule la manche avec no_variant_available', function (): void {
    Queue::fake([AdvanceRound::class]);
    $recorder = RecordingBroadcaster::install();

    $game = EngineFixtures::game(EngineFixtures::settings());
    $movies = EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);
    $level = FrameLevelCoverage::nominal($game->frames_per_round)[0];

    // L'unique variante du niveau 1 du film est dépubliée ; une variante
    // servable d'un AUTRE niveau reste : jamais un autre niveau (§ 6.2).
    EngineFixtures::variant($movies[0], $level)->forceFill(['availability' => ContentAvailability::Unpublished])->save();
    Frame::factory()->for($movies[0])->level(FrameLevel::Level5)->published()->create();

    DB::transaction(fn () => app(ScheduleRound::class)->handle($round, $this->now->addSeconds(10)));

    $round->refresh();
    $first = EngineFixtures::tier($round, 1);

    // La manche est annulée, aucun jeton n'est frappé ni aucune variante
    // retenue, et rien ne la programme : ni job, ni `round.scheduled`.
    expect($round->status)->toBe(RoundStatus::Cancelled)
        ->and($round->cancel_reason)->toBe(RoundIncidentReason::NoVariantAvailable)
        ->and($round->cancelled_at?->equalTo($this->now))->toBeTrue()
        ->and($first->serve_token)->toBeNull()
        ->and($first->served_frame_id)->toBeNull()
        ->and($first->substitution_reason)->toBeNull();

    Queue::assertNotPushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id);

    $events = array_map(static fn (array $sent): string => $sent['event'], $recorder->sent);

    expect($events)->toContain('round.cancelled')
        ->and(array_values(array_filter(
            $recorder->sent,
            static fn (array $sent): bool => $sent['event'] === 'round.scheduled' && $sent['payload']['round']['sequenceIndex'] === 1,
        )))->toBe([]);

    // Frappée seule, sur une manche en cours : même annulation.
    $other = mintRound($game, $movies[1], Round::query()->where('game_id', $game->id)->max('sequence_index') + 1);
    $other->forceFill(['status' => RoundStatus::Running, 'started_at' => $this->now->subSeconds(5)])->save();
    EngineFixtures::variant($movies[1], FrameLevelCoverage::nominal($game->frames_per_round)[1])
        ->forceFill(['processing_state' => FrameProcessingState::Failed])
        ->save();

    $second = mintTier($other, 2, $this->now);

    expect($second->serve_token)->toBeNull()
        ->and($other->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and($other->cancel_reason)->toBe(RoundIncidentReason::NoVariantAvailable);
});

test('deux manches distinctes portant la même frame produisent deux serve_token différents', function (): void {
    $movie = PoolFixtures::movie();
    $level = FrameLevelCoverage::nominal(EngineFixtures::settings()->framesPerRound)[0];
    $frame = EngineFixtures::variant($movie, $level);

    // La même image dans deux parties solo — le terrain du dictionnaire
    // (paire adresse → titre) —, puis dans une partie multijoueur.
    $tokens = [];

    foreach ([true, true, false] as $solo) {
        $game = EngineFixtures::game(EngineFixtures::settings(), solo: $solo);
        $minted = mintTier(mintRound($game, $movie), 1, $this->now);

        expect($minted->served_frame_id)->toBe($frame->id)
            ->and($minted->serve_token)->toMatch('/^[0-9a-f]{32}$/')
            ->and($minted->serve_token)->not->toContain(pathinfo((string) $frame->game_path, PATHINFO_FILENAME));

        $tokens[] = $minted->serve_token;
    }

    expect(array_unique($tokens))->toHaveCount(3);
});

/**
 * Une partie matérialisée, un siège présent, la manche 1 programmée à
 * `now + 5 s` — à une fraction de seconde, pour que les instants théoriques
 * portent des millisecondes.
 *
 * @return array{Game, Round}
 */
function mintScheduled(bool $solo = false, int $reserve = 0): array
{
    $game = EngineFixtures::game(EngineFixtures::settings(), solo: $solo);
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game, $reserve);
    $round = EngineFixtures::round($game, 1);

    EngineFixtures::schedule($round, Date::now()->toImmutable()->addMilliseconds(5_137));

    return [$game, $round->refresh()];
}

/**
 * Les lignes `seen_frame`, `frame_id => last_seen_at` à la seconde.
 *
 * @return array<int, string>
 */
function mintSeenFrames(): array
{
    return SeenFrame::query()->orderBy('frame_id')->get()
        ->mapWithKeys(static fn (SeenFrame $seen): array => [$seen->frame_id => $seen->last_seen_at->format('Y-m-d H:i:s')])
        ->all();
}

test('served_at vaut l\'instant théorique même quand le job est en retard', function (): void {
    Queue::fake([AdvanceRound::class]);
    $recorder = RecordingBroadcaster::install();

    [$game, $round] = mintScheduled();
    $recorder->sent = [];

    // Chaque ouverture s'exécute en retard, d'un retard différent : l'instant
    // écrit est toujours `Tᵢ = started_at + starts_at_offset_ms`, à la
    // milliseconde, jamais l'heure d'exécution.
    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        $opensAt = EngineFixtures::opensAt($round, $tierIndex);
        $late = $opensAt->addMilliseconds(2_700 + 113 * $tierIndex);

        $opened = EngineFixtures::openTier($round, $tierIndex, $late);

        expect($opened->served_at?->format('Y-m-d H:i:s.v'))->toBe($opensAt->format('Y-m-d H:i:s.v'))
            ->and($opened->served_at?->format('Y-m-d H:i:s.v'))->not->toBe($late->format('Y-m-d H:i:s.v'));

        // La diffusion part en retard (son `serverNow`), mais annonce l'instant
        // théorique ; la mémoire du salon aussi.
        $sent = collect($recorder->sent)->last();

        expect($sent['event'] ?? null)->toBe('tier.opened')
            ->and($sent['payload']['opensAt'] ?? null)->toBe(WireTime::iso($opensAt))
            ->and($sent['payload']['serverNow'] ?? null)->toBe(WireTime::iso($late))
            ->and(mintSeenFrames()[(int) $opened->served_frame_id] ?? null)->toBe($opensAt->format('Y-m-d H:i:s'));

        // Rejouée plus tard encore, l'ouverture ne réécrit rien.
        EngineFixtures::openTier($round, $tierIndex, $late->addSeconds(4));

        expect(EngineFixtures::tier($round, $tierIndex)->served_at?->equalTo($opensAt))->toBeTrue();
    }

    expect(array_column($recorder->sent, 'event'))->toBe(array_fill(0, $game->frames_per_round, 'tier.opened'));
});

test('après N requêtes d\'image anticipées et aucune frontière franchie, served_at est nul et seen_frame est vide', function (): void {
    // Le siège présente son `player_token` comme le navigateur : c'est la
    // requête que le prédicat de service autorisera (C8 § 2, membre de la
    // manche), celle que vise la règle (10 § 7.4).
    $token = PlayerToken::mint(Locale::French);
    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game, ['player_token_hash' => $token->hash()]);
    EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($round, Date::now()->toImmutable()->addMilliseconds(5_137));

    $first = EngineFixtures::tier($round, 1);
    $t1 = EngineFixtures::opensAt($round, 1);
    $windowOpens = $first->servingOpensAt($game->preload_lead_ms) ?? throw new LogicException('Palier 1 sans origine de temps.');
    $url = ServeUrl::for($first);

    LobbyWrites::actAs($this, $token);

    // N requêtes du palier 1 dans SA fenêtre de préchargement
    // `[T₁ − preload_lead_ms, T₁)`, bords compris, sans qu'aucune frontière
    // soit franchie : la première requête d'image d'un palier arrive
    // jusqu'à `preload_lead_ms` avant son ouverture (10 § 7.4). Servie (200)
    // une fois le prédicat branché (L60-8), refusée (404) d'ici là par la
    // route fermée : dans les deux cas elle est en lecture seule et n'ouvre
    // rien.
    foreach (range(1, $game->frames_per_round) as $request) {
        Date::setTestNow($windowOpens->addMilliseconds(intdiv(
            ($request - 1) * ($game->preload_lead_ms - 1),
            $game->frames_per_round - 1,
        )));

        expect($this->get($url)->baseResponse->getStatusCode())->toBeIn([200, 404]);
    }

    expect(Date::now()->equalTo($t1->subMillisecond()))->toBeTrue();

    // Aucune frontière franchie : même l'ouverture appelée une milliseconde
    // avant T₁ n'écrit rien, et le palier 2 n'est pas frappé.
    EngineFixtures::openTier($round, 1, $t1->subMillisecond());

    expect(RoundTier::query()->where('round_id', $round->id)->whereNotNull('serve_token')->pluck('tier_index')->all())->toBe([1])
        ->and(RoundTier::query()->where('round_id', $round->id)->whereNotNull('served_at')->count())->toBe(0)
        ->and(SeenFrame::query()->count())->toBe(0)
        ->and($round->refresh()->status)->toBe(RoundStatus::Pending)
        ->and(RoundPlayer::query()->where('round_id', $round->id)->count())->toBe(0);
});

test('seen_frame est écrit sur served_frame_id à l\'ouverture, jamais à la frappe, jamais en solo', function (): void {
    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game);
    $movies = EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);
    $levels = FrameLevelCoverage::nominal($game->frames_per_round);

    // Le palier 2 sera substitué à sa frappe : la variante tirée est
    // suspendue, une autre variante publiée du même niveau la remplacera.
    $drawn = EngineFixtures::variant($movies[0], $levels[1]);
    $substitute = Frame::factory()->for($movies[0])->level($levels[1])->published()->create();
    $drawn->forceFill(['availability' => ContentAvailability::Suspended])->save();

    // Le palier 3 a déjà été vu par le salon, il y a longtemps.
    $third = EngineFixtures::variant($movies[0], $levels[2]);
    $longAgo = Date::now()->toImmutable()->subDays(40);
    SeenFrame::query()->create(['room_id' => $game->room_id, 'frame_id' => $third->id, 'last_seen_at' => $longAgo]);

    // À la frappe du palier 1 (programmation) : rien.
    EngineFixtures::schedule($round, Date::now()->toImmutable()->addSeconds(5));

    expect(mintSeenFrames())->toBe([$third->id => $longAgo->format('Y-m-d H:i:s')]);

    // À l'ouverture du palier 1 : sa variante, à T₁ ; le palier 2, frappé
    // (et substitué) dans la même transition, n'est pas encore vu.
    EngineFixtures::openTier($round, 1);
    $first = EngineFixtures::tier($round, 1);
    $second = EngineFixtures::tier($round, 2);

    expect($second->served_frame_id)->toBe($substitute->id)
        ->and($second->substitution_reason)->toBe(RoundIncidentReason::FrameUnavailable)
        ->and(array_keys(mintSeenFrames()))->toEqualCanonicalizing([$first->served_frame_id, $third->id])
        ->and(mintSeenFrames()[(int) $first->served_frame_id])->toBe(EngineFixtures::opensAt($round, 1)->format('Y-m-d H:i:s'));

    // À l'ouverture du palier 2 : la variante SERVIE, jamais celle du tirage.
    EngineFixtures::openTier($round, 2);

    expect(mintSeenFrames())->toHaveKey($substitute->id)
        ->and(mintSeenFrames())->not->toHaveKey($drawn->id)
        ->and(mintSeenFrames()[$substitute->id])->toBe(EngineFixtures::opensAt($round, 2)->format('Y-m-d H:i:s'));

    // À l'ouverture du palier 3 : la ligne existante est mise à jour, jamais
    // doublée (`seen_frame_room_frame_uq`).
    EngineFixtures::openTier($round, 3);

    expect(SeenFrame::query()->where('frame_id', $third->id)->count())->toBe(1)
        ->and(mintSeenFrames()[$third->id])->toBe(EngineFixtures::opensAt($round, 3)->format('Y-m-d H:i:s'))
        ->and(SeenFrame::query()->count())->toBe(3)
        ->and(SeenFrame::query()->distinct()->pluck('room_id')->all())->toBe([$game->room_id]);

    // En solo : les mêmes ouvertures, aucune mémoire de salon (barrière 3).
    SeenFrame::query()->delete();
    [$solo, $soloRound] = mintScheduled(solo: true);

    foreach (range(1, $solo->frames_per_round) as $tierIndex) {
        expect(EngineFixtures::openTier($soloRound, $tierIndex)->served_at)->not->toBeNull();
    }

    expect(SeenFrame::query()->count())->toBe(0);
});

test('un palier dont l\'ouverture suit une fin anticipée ou une annulation n\'écrit ni served_at ni seen_frame', function (): void {
    Queue::fake([AdvanceRound::class]);
    $recorder = RecordingBroadcaster::install();

    // 1. Fin anticipée entre T₁ et T₂, puis à T₂ exactement : le palier 2
    // ne s'ouvre jamais (`Tᵢ ≥ ended_at`).
    foreach (['avant T₂' => 1_900, 'à T₂' => 0] as $label => $beforeT2) {
        [$game, $round] = mintScheduled();
        EngineFixtures::openTier($round, 1);
        $seen = mintSeenFrames();
        $t2 = EngineFixtures::opensAt($round, 2);

        EngineFixtures::close($round, $t2->subMilliseconds($beforeT2));
        $recorder->sent = [];

        $second = EngineFixtures::openTier($round, 2, $t2->addMillisecond());

        expect($second->served_at)->toBeNull($label)
            ->and(mintSeenFrames())->toBe($seen, $label)
            // Ni frappe du palier 3, ni diffusion, ni frontière suivante.
            ->and(EngineFixtures::tier($round, 3)->serve_token)->toBeNull($label)
            ->and($recorder->sent)->toBe([], $label)
            ->and($round->refresh()->status)->toBe(RoundStatus::Running, $label);

        Queue::assertNotPushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id
            && $job->step === RoundStep::OpenTier
            && $job->tierIndex === 3);
    }

    // 2. Annulation entre T₁ et T₂ : le palier 2 ne s'ouvre jamais.
    [$game, $round] = mintScheduled(reserve: 1);
    EngineFixtures::openTier($round, 1);
    $seen = mintSeenFrames();
    $t2 = EngineFixtures::opensAt($round, 2);

    DB::transaction(static fn () => app(CancelRound::class)->handle($round, RoundIncidentReason::FrameUnavailable, $t2->subSecond()));
    $recorder->sent = [];

    $second = EngineFixtures::openTier($round, 2, $t2);

    expect($round->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and($second->served_at)->toBeNull()
        ->and(mintSeenFrames())->toBe($seen)
        ->and(EngineFixtures::tier($round, 3)->serve_token)->toBeNull()
        ->and($recorder->sent)->toBe([]);
});

test('un palier dont l\'ouverture annule la manche n\'est jamais marqué servi', function (): void {
    Queue::fake([AdvanceRound::class]);
    $recorder = RecordingBroadcaster::install();

    // 1. La frame frappée n'est plus servable à T₁ : annulation
    // `frame_unavailable` avant toute écriture de l'ouverture.
    [$game, $round] = mintScheduled(reserve: 1);
    Frame::query()->findOrFail(EngineFixtures::tier($round, 1)->served_frame_id)
        ->forceFill(['availability' => ContentAvailability::Unpublished])
        ->save();
    $recorder->sent = [];

    $first = EngineFixtures::openTier($round, 1);

    expect($round->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and($round->cancel_reason)->toBe(RoundIncidentReason::FrameUnavailable)
        ->and($first->served_at)->toBeNull()
        ->and(SeenFrame::query()->count())->toBe(0)
        ->and(RoundPlayer::query()->where('round_id', $round->id)->count())->toBe(0)
        ->and(EngineFixtures::tier($round, 2)->serve_token)->toBeNull()
        ->and(array_column($recorder->sent, 'event'))->not->toContain('tier.opened');

    // 2. La frappe du palier suivant, faute de variante, annule la manche à
    // l'ouverture du palier 1 : le palier 1 n'est pas marqué servi.
    [$game, $round] = mintScheduled(reserve: 1);
    $second = EngineFixtures::tier($round, 2);
    Frame::query()->findOrFail($second->frame_id)->forceFill(['availability' => ContentAvailability::Withdrawn])->save();
    $recorder->sent = [];

    $first = EngineFixtures::openTier($round, 1);

    expect($round->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and($round->cancel_reason)->toBe(RoundIncidentReason::NoVariantAvailable)
        ->and($first->served_at)->toBeNull()
        ->and(EngineFixtures::tier($round, 2)->serve_token)->toBeNull()
        ->and(SeenFrame::query()->count())->toBe(0)
        ->and(array_column($recorder->sent, 'event'))->toBe(['round.cancelled', 'round.scheduled']);

    Queue::assertNotPushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id && $job->tierIndex === 2);
});
