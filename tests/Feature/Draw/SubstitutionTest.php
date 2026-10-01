<?php

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\RoundStatus;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Movie;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundTier;
use App\Models\SeenFrame;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\DrawContext;
use App\Support\Draw\ReplacementRoundChooser;
use App\Support\Draw\SeededPrf;
use App\Support\Draw\VariantChooser;
use App\Support\Frames\FrameStoragePrefix;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Database\Factories\RoundFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Substitution et remplacement — spec 30 § 8, lot L30-6, contrat C3
|--------------------------------------------------------------------------
|
| `VariantChooser::substitute()` choisit la variante qui remplace, AU MÊME
| NIVEAU, la variante tirée d'un palier devenue indisponible ;
| `ReplacementRoundChooser::next()` choisit la manche de réserve qui remplace
| une manche annulée. La détection, l'instant, l'écriture et l'annulation
| appartiennent à la spec 60 : ces tests appellent les deux choix directement,
| sur de vraies manches, de vrais paliers et de vraies variantes à fichier
| réel sur le disque `frames` simulé (`PoolFixtures`).
|
| Les attendus de départage par la graine sont RECALCULÉS par `SeededPrf`
| dans le contexte `DrawContext::substitute(s, i)` (dont l'algorithme est figé
| par ses vecteurs de référence, L30-4), jamais recopiés : le test prouve que la
| substitution consulte le bon contexte sur le bon groupe de candidates.
|
| Chaque clause du prédicat de variante jouable (`Frame::servable()`) et de la
| présence sur disque est rendue fausse, chacune sur sa variante, au même
| niveau que le palier : c'est l'endroit où une clause oubliée servirait une
| image cassée.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->now = CarbonImmutable::parse('2026-09-25 10:00:00.250');
});

/**
 * Une graine déterministe de 64 caractères hexadécimaux, indexée par `$k`.
 */
function substitutionSeed(int $k): string
{
    return hash('sha256', 'substitution:'.$k);
}

/**
 * Une partie à graine connue : multijoueur sur `$room`, solo sans salon.
 */
function substitutionGame(?Room $room, int $k = 0): Game
{
    $factory = $room instanceof Room ? Game::factory()->forRoom($room) : Game::factory()->solo();

    return $factory->state(['draw_seed' => substitutionSeed($k)])->create();
}

/**
 * Le palier `$tierIndex` d'une manche `$sequenceIndex` de la partie sur
 * `$movie`, matérialisé sur la variante `$frameId` (niveau recopié d'elle).
 *
 * Le numéro affiché diffère de la position, comme pour une remplaçante (§ 8.2) :
 * le contexte de départage se fonde sur `sequence_index`, jamais sur
 * `round_number`.
 */
function substitutionTier(Game $game, Movie $movie, int $sequenceIndex, int $tierIndex, int $frameId): RoundTier
{
    $round = Round::factory()
        ->forGame($game)
        ->forMovie($movie)
        ->atSequence($sequenceIndex, $sequenceIndex + 1)
        ->create();

    return RoundTier::factory()
        ->for($round)
        ->atTier($tierIndex)
        ->forFrame(Frame::query()->findOrFail($frameId))
        ->create();
}

/**
 * La graine `$k` posée sur la partie du palier, puis la manche et le palier
 * relus en base : aucune relation mise en cache ne survit d'un appel à l'autre.
 *
 * @return array{0: Round, 1: RoundTier}
 */
function substitutionReseed(RoundTier $tier, int $k): array
{
    $gameId = Round::query()->whereKey($tier->round_id)->value('game_id');

    Game::query()->whereKey($gameId)->update(['draw_seed' => substitutionSeed($k)]);

    return [Round::query()->findOrFail($tier->round_id), RoundTier::query()->findOrFail($tier->id)];
}

/**
 * La substitution du palier sous la graine `$k`.
 *
 * @param  list<int>  $excluded
 */
function substitutionRun(RoundTier $tier, int $k, CarbonImmutable $now, array $excluded = []): ?int
{
    [$round, $fresh] = substitutionReseed($tier, $k);

    return (new VariantChooser)->substitute($round, $fresh, $excluded, $now);
}

/**
 * La variante que la graine `$k` désigne parmi `$group` : le groupe trié par
 * `frameId`, puis `index(substitute(s, i), |groupe|)` — recalculé, jamais
 * recopié.
 *
 * @param  list<int>  $group
 */
function substitutionPick(int $k, int $sequenceIndex, int $tierIndex, array $group, ?DrawContext $context = null): int
{
    sort($group);

    $context ??= DrawContext::substitute($sequenceIndex, $tierIndex);

    return $group[(new SeededPrf(substitutionSeed($k)))->index($context, count($group))];
}

/**
 * Les identifiants des variantes de niveau `$level` du film, croissants.
 *
 * @return list<int>
 */
function substitutionFrameIds(Movie $movie, FrameLevel $level): array
{
    return Frame::query()
        ->where('movie_id', $movie->id)
        ->where('frame_level', $level->value)
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->values()
        ->all();
}

/**
 * Les écritures qui rendent fausse, chacune, UNE clause du prédicat de
 * variante jouable (10 § 3.2) : toute disponibilité autre que `published`,
 * tout état de traitement autre que `ready`, `game_path` nul.
 *
 * @return list<array<string, string|null>>
 */
function substitutionUnplayableWrites(): array
{
    return [
        ...array_map(
            static fn (ContentAvailability $availability): array => ['availability' => $availability->value],
            array_values(array_filter(
                ContentAvailability::cases(),
                static fn (ContentAvailability $availability): bool => $availability !== ContentAvailability::Published,
            )),
        ),
        ...array_map(
            static fn (FrameProcessingState $state): array => ['processing_state' => $state->value],
            array_values(array_filter(
                FrameProcessingState::cases(),
                static fn (FrameProcessingState $state): bool => $state !== FrameProcessingState::Ready,
            )),
        ),
        ['game_path' => null],
    ];
}

/**
 * Une variante publiée de plus au niveau `$level`, fichier réel sur le disque,
 * puis sortie du jeu par `$write` en écriture de masse : seule la portée de
 * variante jouable peut l'écarter.
 *
 * @param  array<string, string|null>  $write
 */
function substitutionUnplayable(Movie $movie, FrameLevel $level, array $write): int
{
    $frame = Frame::factory()->for($movie)->level($level)->published()->create();

    Frame::query()->whereKey($frame->id)->update($write);

    return $frame->id;
}

/**
 * Une ligne de mémoire du salon : `$frameId` vu par `$room` à `$seenAt`.
 */
function substitutionSeen(Room $room, int $frameId, CarbonImmutable $seenAt): void
{
    SeenFrame::factory()->forRoom($room)->state([
        'frame_id' => $frameId,
        'last_seen_at' => $seenAt,
    ])->create();
}

/**
 * Le SQL émis pendant `$run`, capturé par `DB::listen`.
 *
 * @return list<string>
 */
function substitutionQueries(Closure $run): array
{
    $captured = [];
    $listening = true;

    DB::listen(static function (QueryExecuted $query) use (&$captured, &$listening): void {
        if ($listening) {
            $captured[] = $query->sql;
        }
    });

    try {
        $run();
    } finally {
        $listening = false;
    }

    return $captured;
}

/**
 * Un film publié et `clear` sans banque : `next()` ne lit que
 * `Movie::inPool()`.
 */
function replacementMovie(): Movie
{
    return Movie::factory()->playable()->create();
}

/**
 * Les manches numérotées `1..M` de la partie, en attente et non démarrées :
 * elles ne sont jamais des remplaçantes, elles ont un numéro.
 */
function replacementNumberedRounds(Game $game): void
{
    foreach (range(1, $game->rounds_count) as $sequenceIndex) {
        Round::factory()->forGame($game)->forMovie(replacementMovie())->atSequence($sequenceIndex)->create();
    }
}

/**
 * Une manche de réserve (`round_number` nul) de la partie à la position
 * `$sequenceIndex`, en attente et non démarrée sauf `$state`.
 *
 * @param  (Closure(RoundFactory): RoundFactory)|null  $state
 */
function replacementReserve(Game $game, int $sequenceIndex, ?Movie $movie = null, ?Closure $state = null): Round
{
    $factory = Round::factory()
        ->forGame($game)
        ->forMovie($movie ?? replacementMovie())
        ->atSequence($sequenceIndex)
        ->state(['round_number' => null]);

    if ($state instanceof Closure) {
        $factory = $state($factory);
    }

    return $factory->create();
}

function replacementSettings(): RoomSettings
{
    return PoolFixtures::settings(roundsCount: RoomSettingsBounds::MIN_ROUNDS_COUNT);
}

it('une variante de substitution reste dans la plage du palier et exclut la variante fautive', function () {
    $room = Room::factory()->create();
    $game = substitutionGame($room);

    // Trois variantes jouables au niveau 1, deux au niveau 3, une au niveau 5.
    $movie = PoolFixtures::movie([
        FrameLevel::Level1, FrameLevel::Level1, FrameLevel::Level1,
        FrameLevel::Level3, FrameLevel::Level3,
        FrameLevel::Level5,
    ]);
    [$faulty, $first, $second] = substitutionFrameIds($movie, FrameLevel::Level1);
    [$faultyLevel3, $otherLevel3] = substitutionFrameIds($movie, FrameLevel::Level3);

    // Au niveau du palier, une variante par clause fausse du prédicat de
    // variante jouable, fichier présent sur le disque : seule la portée les
    // écarte.
    $unplayable = array_map(
        static fn (array $write): int => substitutionUnplayable($movie, FrameLevel::Level1, $write),
        substitutionUnplayableWrites(),
    );

    // La portée SQL et son jumeau PHP rendent le même verdict, ligne par ligne,
    // sur toutes les clauses.
    $servable = Frame::query()->servable()->orderBy('id')->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)->all();
    $isServable = Frame::query()->orderBy('id')->get()
        ->filter(static fn (Frame $frame): bool => $frame->isServable())
        ->map(static fn (Frame $frame): int => $frame->id)->values()->all();

    expect($servable)->toBe($isServable)
        ->and(array_values(array_intersect($unplayable, $servable)))->toBe([])
        ->and(count($unplayable))->toBe(count(substitutionUnplayableWrites()));

    $tier = substitutionTier($game, $movie, 2, 1, $faulty);
    $picked = [];

    foreach (range(0, 15) as $k) {
        $choice = substitutionRun($tier, $k, $this->now);

        expect($choice)->toBe(substitutionPick($k, 2, 1, [$first, $second]))
            ->and(Frame::query()->findOrFail($choice)->frame_level)->toBe(FrameLevel::Level1);

        $picked[(int) $choice] = true;
    }

    // Jamais la fautive, jamais une non jouable : les deux autres sortent, et
    // c'est la graine qui tranche.
    expect(array_keys($picked))->toEqualCanonicalizing([$first, $second]);

    // Les exclusions de l'appelant (un fichier disparu entre le choix et la
    // frappe) s'ajoutent à la fautive.
    foreach (range(0, 7) as $k) {
        expect(substitutionRun($tier, $k, $this->now, [$first]))->toBe($second)
            ->and(substitutionRun($tier, $k, $this->now, [$second]))->toBe($first)
            ->and(substitutionRun($tier, $k, $this->now, [$first, $second]))->toBeNull();
    }

    // Un palier tiré en repli garde son niveau parmi les permis : un palier 1
    // tiré sur le niveau 3, hors de sa plage, se substitue dans sa plage OU
    // au niveau 3 (D45 du 01/10).
    $fallback = substitutionTier($game, $movie, 3, 1, $faultyLevel3);

    expect($fallback->frame_level)->toBe(FrameLevel::Level3)
        ->and(FrameLevelCoverage::bands(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND)[0])
        ->not->toContain(FrameLevel::Level3);

    foreach (range(0, 7) as $k) {
        expect(substitutionRun($fallback, $k, $this->now))
            ->toBe(substitutionPick($k, 3, 1, [$faulty, $first, $second, $otherLevel3]));
    }

    // Un palier d'une autre manche est refusé.
    expect(fn () => (new VariantChooser)->substitute(
        Round::query()->findOrFail($tier->round_id),
        RoundTier::query()->findOrFail($fallback->id),
        [],
        $this->now,
    ))->toThrow(InvalidArgumentException::class);
});

it('sans variante dans la plage du palier, la substitution rend null et jamais hors de la plage', function () {
    $room = Room::factory()->create();
    $game = substitutionGame($room);

    // Une seule variante jouable dans la plage 1-2 du palier 1 — celle du
    // palier —, les niveaux hors de la plage abondants.
    $movie = PoolFixtures::movie([
        FrameLevel::Level1,
        FrameLevel::Level3, FrameLevel::Level3,
        FrameLevel::Level4, FrameLevel::Level4,
        FrameLevel::Level5, FrameLevel::Level5, FrameLevel::Level5,
    ]);
    [$faulty] = substitutionFrameIds($movie, FrameLevel::Level1);

    // Au niveau 1, rien d'autre que du non jouable : un brouillon sans fichier
    // et une variante par clause fausse du prédicat.
    Frame::factory()->for($movie)->level(FrameLevel::Level1)->create();

    foreach (substitutionUnplayableWrites() as $write) {
        substitutionUnplayable($movie, FrameLevel::Level1, $write);
    }

    $tier = substitutionTier($game, $movie, 1, 1, $faulty);

    foreach (range(0, 15) as $k) {
        expect(substitutionRun($tier, $k, $this->now))->toBeNull();
    }

    // La variante du palier elle-même sortie du jeu : toujours null.
    Frame::query()->whereKey($faulty)->update(['availability' => ContentAvailability::Unpublished->value]);

    foreach (range(0, 7) as $k) {
        expect(substitutionRun($tier, $k, $this->now))->toBeNull();
    }

    // Au niveau 5, deux autres variantes jouables : elles sortent, et les
    // exclure toutes deux rend null. Le niveau 4, dans la plage 4-5 du
    // dernier palier, est déjà montré par le palier précédent : jamais
    // proposé, l'ordre strict des niveaux tient.
    [$faultyLevel5, $firstLevel5, $secondLevel5] = substitutionFrameIds($movie, FrameLevel::Level5);
    $tierLevel5 = substitutionTier($game, $movie, 2, RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND, $faultyLevel5);

    RoundTier::factory()
        ->for(Round::query()->findOrFail($tierLevel5->round_id))
        ->atTier(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND - 1)
        ->forFrame(Frame::query()->findOrFail(substitutionFrameIds($movie, FrameLevel::Level4)[0]))
        ->create();

    foreach (range(0, 7) as $k) {
        expect(substitutionRun($tierLevel5, $k, $this->now))->toBeIn([$firstLevel5, $secondLevel5])
            ->and(substitutionRun($tierLevel5, $k, $this->now, [$firstLevel5, $secondLevel5]))->toBeNull();
    }
});

it('la substitution du palier du milieu se borne aux niveaux de ses voisins, image servie comprise', function () {
    $room = Room::factory()->create();
    $game = substitutionGame($room);

    // N = 3 : le palier 2 a pour plage 2-3-4 (D45 du 01/10).
    $movie = PoolFixtures::movie([
        FrameLevel::Level1, FrameLevel::Level1,
        FrameLevel::Level2, FrameLevel::Level2,
        FrameLevel::Level3, FrameLevel::Level3,
        FrameLevel::Level4, FrameLevel::Level4,
        FrameLevel::Level5,
    ]);
    $level1 = substitutionFrameIds($movie, FrameLevel::Level1);
    $level2 = substitutionFrameIds($movie, FrameLevel::Level2);
    [$faulty, $otherLevel3] = substitutionFrameIds($movie, FrameLevel::Level3);
    $level4 = substitutionFrameIds($movie, FrameLevel::Level4);
    [$level5] = substitutionFrameIds($movie, FrameLevel::Level5);

    $neighbours = static function (RoundTier $middle, int $previous, int $next): void {
        $round = Round::query()->findOrFail($middle->round_id);

        foreach ([1 => $previous, 3 => $next] as $tierIndex => $frameId) {
            RoundTier::factory()->for($round)->atTier($tierIndex)
                ->forFrame(Frame::query()->findOrFail($frameId))->create();
        }
    };

    // Voisins aux niveaux 1 et 5 : toute la plage 2-3-4 est permise.
    $wide = substitutionTier($game, $movie, 1, 2, $faulty);
    $neighbours($wide, $level1[0], $level5);

    foreach (range(0, 7) as $k) {
        expect(substitutionRun($wide, $k, $this->now))
            ->toBe(substitutionPick($k, 1, 2, [...$level2, $otherLevel3, ...$level4]));
    }

    // Palier 1 tiré au niveau 1 mais SERVI au niveau 2, palier 3 au niveau 4 :
    // seul le niveau 3 reste strictement entre eux.
    $narrow = substitutionTier($game, $movie, 2, 2, $faulty);
    $neighbours($narrow, $level1[0], $level4[0]);

    RoundTier::query()
        ->where('round_id', $narrow->round_id)
        ->where('tier_index', 1)
        ->update(['served_frame_id' => $level2[0]]);

    foreach (range(0, 7) as $k) {
        expect(substitutionRun($narrow, $k, $this->now))->toBe($otherLevel3)
            ->and(substitutionRun($narrow, $k, $this->now, [$otherLevel3]))->toBeNull();
    }
});

it('la substitution écarte une variante dont le fichier manque sur le disque', function () {
    $room = Room::factory()->create();
    $game = substitutionGame($room);
    $movie = PoolFixtures::movie([
        FrameLevel::Level1, FrameLevel::Level1, FrameLevel::Level1, FrameLevel::Level1,
        FrameLevel::Level3,
        FrameLevel::Level5,
    ]);
    [$faulty, $missing, $foreign, $present] = substitutionFrameIds($movie, FrameLevel::Level1);
    $tier = substitutionTier($game, $movie, 1, 1, $faulty);

    // Témoin : fichiers en place, la graine fait sortir chacune des trois.
    $picked = [];

    foreach (range(0, 23) as $k) {
        $picked[(int) substitutionRun($tier, $k, $this->now)] = true;
    }

    expect(array_keys($picked))->toEqualCanonicalizing([$missing, $foreign, $present]);

    // Le dérivé de `$missing` disparaît du disque ; celui de `$foreign` désigne
    // un chemin hors du préfixe `game/` — son master, présent sur le disque,
    // que la route de jeu refuse (C8, C9). En base, les deux restent jouables :
    // seule la vérification du disque peut les écarter.
    $disk = Storage::disk(FrameStoragePrefix::DISK);
    $foreignMaster = (string) Frame::query()->findOrFail($foreign)->master_path;

    $disk->delete((string) Frame::query()->findOrFail($missing)->game_path);
    Frame::query()->whereKey($foreign)->update(['game_path' => $foreignMaster]);

    expect(Frame::query()->findOrFail($missing)->isServable())->toBeTrue()
        ->and(Frame::query()->findOrFail($foreign)->isServable())->toBeTrue()
        ->and($disk->exists($foreignMaster))->toBeTrue()
        ->and(FrameStoragePrefix::Game->owns($foreignMaster))->toBeFalse();

    // La présence est testée AVANT le choix : jamais un null tant qu'une
    // candidate a son fichier.
    foreach (range(0, 23) as $k) {
        expect(substitutionRun($tier, $k, $this->now))->toBe($present);
    }

    // Le dernier fichier disparaît à son tour : plus aucune candidate.
    $disk->delete((string) Frame::query()->findOrFail($present)->game_path);

    foreach (range(0, 7) as $k) {
        expect(substitutionRun($tier, $k, $this->now))->toBeNull();
    }
});

it('le remplaçant est la première manche de réserve encore au catalogue', function () {
    $room = Room::factory()->create();
    $game = PoolFixtures::game($room, replacementSettings());
    $chooser = new ReplacementRoundChooser;
    $roundsCount = $game->rounds_count;

    // Une autre partie du même salon a sa propre réserve, créée la première :
    // elle n'est jamais le remplaçant de celle-ci.
    $elsewhere = PoolFixtures::game($room, replacementSettings());
    replacementReserve($elsewhere, $roundsCount + 1);

    replacementNumberedRounds($game);

    // Les films de réserve qui quitteront le catalogue : toute disponibilité
    // autre que `published`, tout drapeau de contenu autre que `clear`.
    $departures = [
        ...array_map(
            static fn (ContentAvailability $availability): array => ['availability' => $availability->value],
            array_values(array_filter(
                ContentAvailability::cases(),
                static fn (ContentAvailability $availability): bool => $availability !== ContentAvailability::Published,
            )),
        ),
        ...array_map(
            static fn (ContentFlag $flag): array => ['content_flag' => $flag->value],
            array_values(array_filter(
                ContentFlag::cases(),
                static fn (ContentFlag $flag): bool => $flag !== ContentFlag::Clear,
            )),
        ),
    ];

    // Le plan de la réserve, par `sequence_index` croissant : chaque manche
    // écartée ne diffère de la remplaçante attendue que par UNE clause.
    $plan = [];
    $sequenceIndex = $roundsCount;

    foreach ($departures as $write) {
        $plan[++$sequenceIndex] = ['movie' => replacementMovie(), 'departure' => $write, 'state' => null];
    }

    $plan[++$sequenceIndex] = [
        'movie' => replacementMovie(),
        'departure' => null,
        'state' => static fn (RoundFactory $round): RoundFactory => $round->cancelled(),
    ];
    $plan[++$sequenceIndex] = [
        'movie' => replacementMovie(),
        'departure' => null,
        'state' => fn (RoundFactory $round): RoundFactory => $round->state(['started_at' => $this->now->subMinute()]),
    ];
    $expectedIndex = ++$sequenceIndex;
    $plan[$expectedIndex] = ['movie' => replacementMovie(), 'departure' => null, 'state' => null];
    $plan[++$sequenceIndex] = ['movie' => replacementMovie(), 'departure' => null, 'state' => null];

    // Créées à rebours : les identifiants décroissent quand `sequence_index`
    // croît, l'ordre ne peut venir que de `sequence_index`.
    $reserve = [];

    foreach (array_reverse($plan, true) as $index => $spec) {
        $reserve[$index] = replacementReserve($game, $index, $spec['movie'], $spec['state']);
    }

    // Au lancement, tous les films sont au catalogue : la première réserve.
    $firstReserve = $roundsCount + 1;

    expect($chooser->next($game)?->id)->toBe($reserve[$firstReserve]->id);

    // Les films partent (suspension, retrait, dépublication, blocage…) : la
    // clause se lit à l'appel, jamais au lancement.
    foreach ($plan as $index => $spec) {
        if ($spec['departure'] !== null) {
            Movie::query()->whereKey($spec['movie']->id)->update($spec['departure']);
        }
    }

    $next = null;
    $queries = substitutionQueries(function () use (&$next, $chooser, $game): void {
        $next = $chooser->next($game);
    });

    // La suivante, jouable elle aussi, a un identifiant plus petit : seul
    // `sequence_index` désigne l'attendue.
    expect($reserve[$expectedIndex + 1]->id)->toBeLessThan($reserve[$expectedIndex]->id)
        ->and($next)->toBeInstanceOf(Round::class)
        ->and($next?->id)->toBe($reserve[$expectedIndex]->id)
        ->and($next?->sequence_index)->toBe($expectedIndex)
        ->and($next?->round_number)->toBeNull();

    // Une lecture, aucune écriture.
    expect($queries)->toHaveCount(1)
        ->and(strtolower(ltrim($queries[0])))->toStartWith('select');

    // Un film revenu au catalogue redevient remplaçant.
    Movie::query()->whereKey($plan[$firstReserve]['movie']->id)->update([
        'availability' => ContentAvailability::Published->value,
    ]);

    expect($chooser->next($game)?->id)->toBe($reserve[$firstReserve]->id);

    // Le solo s'y plie à l'identique, sans salon ni mémoire.
    $solo = substitutionGame(null);
    replacementNumberedRounds($solo);
    $soloReserve = replacementReserve($solo, $solo->rounds_count + 1);

    expect($chooser->next($solo)?->id)->toBe($soloReserve->id);
});

it('une réserve épuisée rend null', function () {
    $room = Room::factory()->create();
    $chooser = new ReplacementRoundChooser;

    // Sans réserve (`W = M`) : aucun remplaçant.
    $exact = PoolFixtures::game($room, replacementSettings());
    replacementNumberedRounds($exact);

    expect($chooser->next($exact))->toBeNull();

    // Avec réserve : la spec 60 consomme les remplaçantes une à une — le
    // numéro de la manche annulée posé, puis la programmation.
    $game = PoolFixtures::game($room, replacementSettings());
    replacementNumberedRounds($game);
    $roundsCount = $game->rounds_count;

    $first = replacementReserve($game, $roundsCount + 1);
    $second = replacementReserve($game, $roundsCount + 2);
    $departed = replacementReserve($game, $roundsCount + 3);

    // Le dernier film de réserve a quitté le catalogue après le lancement.
    Movie::query()->whereKey($departed->movie_id)->update([
        'availability' => ContentAvailability::Suspended->value,
    ]);

    foreach ([$first, $second] as $offset => $expected) {
        $next = $chooser->next($game);

        expect($next?->id)->toBe($expected->id);

        // La manche annulée garde son numéro ; la remplaçante le reçoit et est
        // programmée.
        Round::query()->whereKey($expected->id)->update([
            'round_number' => $offset + 1,
            'started_at' => $this->now->addMinutes($offset + 1),
        ]);
    }

    // Réserve épuisée : la partie continue avec une manche de moins.
    expect($chooser->next($game))->toBeNull();

    // Une remplaçante programmée puis annulée à son tour ne revient pas.
    Round::query()->whereKey($second->id)->update([
        'status' => RoundStatus::Cancelled->value,
        'cancelled_at' => $this->now->addMinutes(3),
    ]);

    expect($chooser->next($game))->toBeNull();
});

it('la substitution rend la même variante pour des entrées identiques', function () {
    $room = Room::factory()->create();
    $game = substitutionGame($room);
    $movie = PoolFixtures::movie([
        FrameLevel::Level1, FrameLevel::Level1, FrameLevel::Level1, FrameLevel::Level1,
        FrameLevel::Level3,
        FrameLevel::Level5,
    ]);
    [$faulty, $seen, $firstUnseen, $secondUnseen] = substitutionFrameIds($movie, FrameLevel::Level1);
    $sequenceIndex = 4;
    $tier = substitutionTier($game, $movie, $sequenceIndex, 1, $faulty);

    // `$seen` a été vue par le salon : la mémoire fait partie des entrées.
    substitutionSeen($room, $seen, $this->now->subDay());

    // Des exclusions sans rapport avec le palier (variantes d'un autre film).
    $unrelated = Frame::query()->where('movie_id', PoolFixtures::movie()->id)->orderBy('id')->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)->all();

    $chooser = new VariantChooser;
    $picked = [];
    $contextDiffers = 0;

    foreach (range(0, 15) as $k) {
        [$round, $fresh] = substitutionReseed($tier, $k);
        $choice = null;

        $queries = substitutionQueries(function () use (&$choice, $chooser, $round, $fresh): void {
            $choice = $chooser->substitute($round, $fresh, [], $this->now);
        });

        // Lectures seules : la substitution n'écrit rien, ni mémoire ni palier.
        foreach ($queries as $sql) {
            expect(strtolower(ltrim($sql)))->toStartWith('select');
        }

        expect($choice)->toBe(substitutionPick($k, $sequenceIndex, 1, [$firstUnseen, $secondUnseen]))
            ->and($chooser->substitute($round, $fresh, [], $this->now))->toBe($choice)
            ->and(substitutionRun($tier, $k, $this->now))->toBe($choice)
            ->and(substitutionRun($tier, $k, $this->now, [$faulty, $faulty]))->toBe($choice)
            ->and(substitutionRun($tier, $k, $this->now, array_reverse($unrelated)))->toBe($choice);

        $picked[(int) $choice] = true;

        if ($choice !== substitutionPick($k, $sequenceIndex, 1, [$firstUnseen, $secondUnseen], DrawContext::variant($sequenceIndex, 1))) {
            $contextDiffers++;
        }
    }

    // La graine tranche entre les deux non vues, dans le contexte de
    // substitution et non dans celui du tirage (qui aurait rendu une autre
    // variante sous certaines graines).
    expect(array_keys($picked))->toEqualCanonicalizing([$firstUnseen, $secondUnseen])
        ->and($contextDiffers)->toBeGreaterThan(0);

    expect(SeenFrame::query()->count())->toBe(1)
        ->and(RoundTier::query()->findOrFail($tier->id)->served_frame_id)->toBeNull();

    // Toutes vues par le salon : la moins récemment vue, à chaque graine.
    substitutionSeen($room, $firstUnseen, $this->now->subHours(2));
    substitutionSeen($room, $secondUnseen, $this->now->subHour());

    foreach (range(0, 7) as $k) {
        expect(substitutionRun($tier, $k, $this->now))->toBe($seen);
    }

    // La mémoire lue est celle de la fenêtre du salon à `$now` : une ligne plus
    // vieille que la fenêtre, que la purge du jour n'a pas encore effacée, se
    // lit comme non vue (E10-55) et rejoint le groupe des non vues.
    SeenFrame::query()->where('frame_id', $firstUnseen)->update([
        'last_seen_at' => $this->now->subDays(PlatformLimits::roomMemoryWindowDays() + 1),
    ]);
    SeenFrame::query()->where('frame_id', $secondUnseen)->delete();

    $picked = [];

    foreach (range(0, 15) as $k) {
        $choice = substitutionRun($tier, $k, $this->now);

        expect($choice)->toBe(substitutionPick($k, $sequenceIndex, 1, [$firstUnseen, $secondUnseen]));
        $picked[(int) $choice] = true;
    }

    expect(array_keys($picked))->toEqualCanonicalizing([$firstUnseen, $secondUnseen]);
});

it('en solo la substitution ne lit aucune mémoire et se départage par la graine', function () {
    $room = Room::factory()->create();
    $movie = PoolFixtures::movie([
        FrameLevel::Level1, FrameLevel::Level1, FrameLevel::Level1,
        FrameLevel::Level3,
        FrameLevel::Level5,
    ]);
    [$faulty, $seen, $unseen] = substitutionFrameIds($movie, FrameLevel::Level1);

    // Le salon a vu `$seen` et joué une manche : ni sa mémoire ni ses manches
    // ne concernent le solo.
    substitutionSeen($room, $seen, $this->now->subHour());
    PoolFixtures::round(PoolFixtures::game($room), $movie, $this->now->subHour());

    // Un AUTRE salon a vu `$unseen`, plus récemment : sa mémoire ne concerne ni
    // le solo ni le salon du témoin multijoueur, qui ne lit que `game.room_id`.
    substitutionSeen(Room::factory()->create(), $unseen, $this->now->subMinute());

    $solo = substitutionGame(null);
    $soloTier = substitutionTier($solo, $movie, 1, 1, $faulty);
    $multiplayerTier = substitutionTier(substitutionGame($room), $movie, 1, 1, $faulty);

    $picked = [];

    foreach (range(0, 15) as $k) {
        [$round, $tier] = substitutionReseed($soloTier, $k);
        $choice = null;

        expect($round->room_id)->toBeNull();

        $queries = substitutionQueries(function () use (&$choice, $round, $tier): void {
            $choice = (new VariantChooser)->substitute($round, $tier, [], $this->now);
        });

        // Aucune lecture de mémoire : ni `seen_frame`, ni `round`.
        expect(array_values(array_filter(
            $queries,
            static fn (string $sql): bool => preg_match('/\b(seen_frame|round)\b/i', $sql) === 1,
        )))->toBe([]);

        // La graine seule départage, variante vue par le salon comprise.
        expect($choice)->toBe(substitutionPick($k, 1, 1, [$seen, $unseen]));
        $picked[(int) $choice] = true;

        // Témoin multijoueur, même graine : la variante vue par le salon est
        // écartée.
        expect(substitutionRun($multiplayerTier, $k, $this->now))->toBe($unseen);
    }

    expect(array_keys($picked))->toEqualCanonicalizing([$seen, $unseen]);
});
