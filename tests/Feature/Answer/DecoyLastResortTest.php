<?php

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\Locale;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\Room;
use App\Models\Round;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Answers\DecoyPicker;
use App\Support\Draw\DrawContext;
use App\Support\Draw\SeededPrf;
use App\ValueObjects\Answers\DecoyPick;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Leurres de dernier recours — spec 70 § 10.3, D53 du 02/10
|--------------------------------------------------------------------------
|
| Quand R4 échoue, là où `DecoyPicker::pick()` rendait le cas terminal, deux
| rangs de plus, au profil normal puis au titre original : R5, le catalogue
| publié SANS la non-répétition du salon ; R6, la réserve non publiée
| (`draft` ou `unpublished`, `clear`, jamais `suspended` ni `withdrawn`).
| Toujours exclus : la cible, son `movie_group`, les films des manches
| démarrées. L'ordre antérieur est conservé tel quel : un tirage que R1-R4
| suffisaient à composer rend exactement les mêmes leurres.
|
| Graines fixes, films de vivier de `PoolFixtures` ; les films non publiés
| n'ont aucune banque d'images (aucune clause de `N` en R6).
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->at = CarbonImmutable::parse('2026-10-02 10:00:00.250');
});

/** @return list<string> */
function lastResortSeeds(): array
{
    return array_map(
        static fn (int $index): string => hash('sha256', "decoy-last-resort-seed-{$index}"),
        range(1, 6),
    );
}

/** Un titre inventé, unique dans le test. */
function lastResortTitle(): string
{
    /** @var string $words */
    $words = fake()->unique()->words(3, true);

    return Str::title($words);
}

/** @return array<string, string> Profil « français seul ». */
function lastResortFrenchOnly(): array
{
    return [Locale::French->value => lastResortTitle()];
}

/** @return array<string, string> Profil « anglais seul ». */
function lastResortEnglishOnly(): array
{
    return [Locale::English->value => lastResortTitle()];
}

/**
 * Un film publié de vivier au profil voulu (`[]` = `en` et `fr`).
 *
 * @param  array<string, string>  $titles
 */
function lastResortPublished(array $titles = [], ?string $original = null, ?MovieGroup $group = null): Movie
{
    $state = $original === null
        ? null
        : static fn (MovieFactory $factory): MovieFactory => $factory->state(['title_original' => $original]);

    return PoolFixtures::movie(null, $group, $state, $titles);
}

/**
 * Un film hors du catalogue publié, sans banque d'images, à la disponibilité
 * et au verdict de contenu voulus — titres et projection présents.
 *
 * @param  array<string, string>  $titles
 */
function lastResortHidden(
    ContentAvailability $availability,
    array $titles = [],
    ?string $original = null,
    ContentFlag $flag = ContentFlag::Clear,
    ?MovieGroup $group = null,
): Movie {
    $state = static function (MovieFactory $factory) use ($availability, $original, $flag): MovieFactory {
        $factory = match ($availability) {
            ContentAvailability::Draft => $factory->state([
                'availability' => ContentAvailability::Draft,
                'availability_changed_at' => null,
                'first_published_at' => null,
            ]),
            ContentAvailability::Unpublished => $factory->unpublished(),
            ContentAvailability::Suspended => $factory->suspended(),
            ContentAvailability::Withdrawn => $factory->withdrawn(),
            ContentAvailability::Published => $factory,
        };

        $factory = $factory->contentFlag($flag);

        return $original === null ? $factory : $factory->state(['title_original' => $original]);
    };

    return PoolFixtures::movie([], $group, $state, $titles);
}

/** Partie multijoueur à graine fixe. */
function lastResortGame(Room $room, RoomSettings $settings, string $seed): Game
{
    return Game::factory()->forRoom($room)->withSettings($settings)->state(['draw_seed' => $seed])->create();
}

/** La manche cible, démarrée avant l'instant de composition. */
function lastResortTarget(Game $game, Movie $movie, CarbonImmutable $at): Round
{
    return PoolFixtures::round($game, $movie, $at->subSeconds(RoomSettingsBounds::MIN_TIER_DURATION), RoundStatus::Running);
}

/** Le tirage relu en base, sur une instance de `PoolQuery` neuve. */
function lastResortPick(Round $round, CarbonImmutable $at, ?string $seed = null): ?DecoyPick
{
    if ($seed !== null) {
        Game::query()->whereKey($round->game_id)->update(['draw_seed' => $seed]);
    }

    app()->forgetScopedInstances();

    return app(DecoyPicker::class)->pick(
        Round::query()->findOrFail($round->id),
        Game::query()->findOrFail($round->game_id),
        $at,
    );
}

/**
 * Parcours attendu d'un rang sans rejet : candidats triés par `movie.id`,
 * dans l'ordre de la permutation du contexte, jusqu'au troisième leurre.
 *
 * @param  list<Movie>  $rank
 * @param  list<int>  $retained
 * @return list<int>
 */
function lastResortWalk(Round $round, DrawContext $context, array $rank, array $retained = []): array
{
    $ids = array_map(static fn (Movie $movie): int => $movie->id, $rank);
    sort($ids);
    $game = Game::query()->findOrFail($round->game_id);

    foreach (SeededPrf::forGame($game)->permutation($context, count($ids)) as $index) {
        if (count($retained) === DecoyPick::COUNT) {
            break;
        }

        $retained[] = $ids[$index];
    }

    return $retained;
}

it("R5 sauve une manche épuisée par la non-répétition sans jamais proposer le film d'une manche démarrée", function () {
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(noRepeatMovies: true);

    // Une partie précédente du salon a joué quatre films au profil de la
    // cible : la non-répétition les retire de R1 à R4.
    $previous = PoolFixtures::game($room, $settings);
    $played = [lastResortPublished(), lastResortPublished(), lastResortPublished(), lastResortPublished()];

    foreach ($played as $index => $movie) {
        PoolFixtures::round($previous, $movie, $this->at->subHours(2)->addMinutes($index));
    }

    $game = lastResortGame($room, $settings, lastResortSeeds()[0]);

    // Une manche déjà démarrée de CETTE partie : exclue à tous les rangs,
    // dernier recours compris, même si le salon l'a jouée.
    $started = lastResortPublished();
    PoolFixtures::round($game, $started, $this->at->subMinutes(2));
    $round = lastResortTarget($game, lastResortPublished(), $this->at);

    // Un brouillon au même profil : R5 suffit, R6 n'est jamais lu.
    $draft = lastResortHidden(ContentAvailability::Draft);

    foreach (lastResortSeeds() as $seed) {
        $pick = lastResortPick($round, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds)->toBe(lastResortWalk($round, DrawContext::decoysLastResort($round->sequence_index), $played))
            ->and($pick?->movieIds)->not->toContain($started->id)
            ->and($pick?->movieIds)->not->toContain($draft->id);
    }
});

it('R6 sauve une cible seule de son profil avec des films non publiés, écartés compris', function () {
    $game = lastResortGame(Room::factory()->create(), PoolFixtures::settings(), lastResortSeeds()[0]);

    // Cible au titre natif, « français seul » : aucun film publié ne partage
    // ni son masque ni la forme de son titre original.
    $group = MovieGroup::factory()->create();
    $target = lastResortPublished(lastResortFrenchOnly(), '青い猫の庭', $group);
    lastResortPublished();
    lastResortPublished(lastResortEnglishOnly());

    // Réserve au même profil : deux brouillons, un film dépublié (écarté).
    $reserve = [
        lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly(), '赤い月の森'),
        lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly(), '黒い犬の山'),
        lastResortHidden(ContentAvailability::Unpublished, lastResortFrenchOnly(), '緑の星の川'),
    ];

    // Jamais : suspendu, retiré, bloqué, en attente de verdict, frère de la
    // cible, ni le film d'une manche démarrée dépublié depuis.
    $never = [
        lastResortHidden(ContentAvailability::Suspended, lastResortFrenchOnly(), '金の魚の空'),
        lastResortHidden(ContentAvailability::Withdrawn, lastResortFrenchOnly(), '銀の花の谷'),
        lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly(), '白い鳥の海', ContentFlag::Blocked),
        lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly(), '茶色の風の島', ContentFlag::UnratedPending),
        lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly(), '紫の雲の城', group: $group),
    ];
    $startedThenUnpublished = lastResortPublished(lastResortFrenchOnly(), '灰色の雨の町');
    PoolFixtures::round($game, $startedThenUnpublished, $this->at->subMinutes(2));
    Movie::query()->whereKey($startedThenUnpublished->id)->update(['availability' => ContentAvailability::Unpublished]);
    $never[] = $startedThenUnpublished;

    $round = lastResortTarget($game, $target, $this->at);

    foreach (lastResortSeeds() as $seed) {
        $pick = lastResortPick($round, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds)->toBe(lastResortWalk($round, DrawContext::decoysLastResort($round->sequence_index), $reserve));

        foreach ($never as $movie) {
            expect($pick?->movieIds)->not->toContain($movie->id);
        }
    }

});

it('R6 au titre original sauve la cible quand aucun profil normal ne tient', function () {
    // Une réserve d'un autre masque mais de même forme native : le profil
    // normal échoue en R5-R6, le mode dégradé la tire sur son propre contexte.
    $degradedGame = lastResortGame(Room::factory()->create(), PoolFixtures::settings(), lastResortSeeds()[1]);
    $degradedTarget = lastResortPublished(lastResortFrenchOnly(), '青い星の丘');
    lastResortPublished();
    lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly());
    $englishReserve = [
        lastResortHidden(ContentAvailability::Draft, lastResortEnglishOnly(), '赤い砂の港'),
        lastResortHidden(ContentAvailability::Unpublished, lastResortEnglishOnly(), '黒い霧の駅'),
        lastResortHidden(ContentAvailability::Draft, [], '緑の雪の村'),
    ];
    $degradedRound = lastResortTarget($degradedGame, $degradedTarget, $this->at);

    foreach (lastResortSeeds() as $seed) {
        $pick = lastResortPick($degradedRound, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeTrue()
            ->and($pick?->movieIds)->toBe(lastResortWalk(
                $degradedRound,
                DrawContext::decoysLastResortOriginal($degradedRound->sequence_index),
                $englishReserve,
            ));
    }
});

it('passe par R5 avant R6 : les films publiés retenus précèdent les non publiés', function () {
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(noRepeatMovies: true);

    // Un seul film publié au profil de la cible, déjà joué par le salon.
    $played = lastResortPublished(lastResortEnglishOnly());
    PoolFixtures::round(PoolFixtures::game($room, $settings), $played, $this->at->subHour());

    $game = lastResortGame($room, $settings, lastResortSeeds()[0]);
    $round = lastResortTarget($game, lastResortPublished(lastResortEnglishOnly()), $this->at);

    $drafts = [
        lastResortHidden(ContentAvailability::Draft, lastResortEnglishOnly()),
        lastResortHidden(ContentAvailability::Draft, lastResortEnglishOnly()),
        lastResortHidden(ContentAvailability::Unpublished, lastResortEnglishOnly()),
        lastResortHidden(ContentAvailability::Draft, lastResortEnglishOnly()),
    ];

    foreach (lastResortSeeds() as $seed) {
        $pick = lastResortPick($round, $this->at, $seed);

        // Le leurre de R5 est conservé en tête ; R6 complète sur le même
        // contexte.
        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds[0] ?? null)->toBe($played->id)
            ->and($pick?->movieIds)->toBe(lastResortWalk(
                $round,
                DrawContext::decoysLastResort($round->sequence_index),
                $drafts,
                [$played->id],
            ));
    }
});

it('est déterministe pour une graine donnée', function () {
    $target = lastResortPublished(lastResortFrenchOnly(), '青い猫の庭');
    $drafts = [];

    for ($index = 0; $index < 6; $index++) {
        $drafts[] = lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly());
    }

    $round = lastResortTarget(lastResortGame(Room::factory()->create(), PoolFixtures::settings(), lastResortSeeds()[0]), $target, $this->at);
    $twin = lastResortTarget(lastResortGame(Room::factory()->create(), PoolFixtures::settings(), lastResortSeeds()[0]), $target, $this->at);
    $other = lastResortTarget(lastResortGame(Room::factory()->create(), PoolFixtures::settings(), lastResortSeeds()[1]), $target, $this->at);

    DB::enableQueryLog();
    $pick = lastResortPick($round, $this->at);
    $statements = array_map(static fn (array $query): string => (string) $query['query'], DB::getQueryLog());
    DB::disableQueryLog();

    $expected = lastResortWalk($round, DrawContext::decoysLastResort($round->sequence_index), $drafts);

    expect($pick?->movieIds)->toBe($expected)
        ->and(lastResortPick($round, $this->at)?->movieIds)->toBe($expected)
        ->and(lastResortPick($twin, $this->at)?->movieIds)->toBe($expected)
        ->and(lastResortPick($other, $this->at)?->movieIds)
        ->toBe(lastResortWalk($other, DrawContext::decoysLastResort($other->sequence_index), $drafts));

    // Lecture seule, budget borné : aucune requête par candidat.
    foreach ($statements as $statement) {
        expect(Str::lower(ltrim($statement)))->toStartWith('select');
    }

    expect(count($statements))->toBeLessThanOrEqual(20);
});

it('rend le cas terminal quand ni R5 ni R6 ne fournissent trois leurres', function () {
    $game = lastResortGame(Room::factory()->create(), PoolFixtures::settings(), lastResortSeeds()[0]);
    $round = lastResortTarget($game, lastResortPublished(lastResortFrenchOnly(), '青い猫の庭'), $this->at);

    // Deux non publiés éligibles seulement ; les autres ne le sont jamais.
    lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly(), '赤い月の森');
    lastResortHidden(ContentAvailability::Unpublished, lastResortFrenchOnly(), '黒い犬の山');
    lastResortHidden(ContentAvailability::Suspended, lastResortFrenchOnly(), '金の魚の空');
    lastResortHidden(ContentAvailability::Withdrawn, lastResortFrenchOnly(), '銀の花の谷');
    lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly(), '白い鳥の海', ContentFlag::Blocked);
    lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly(), '茶色の風の島', ContentFlag::UnratedPending);

    expect(lastResortPick($round, $this->at))->toBeNull();

    // Témoin : un troisième brouillon éligible suffit.
    lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly(), '緑の星の川');

    expect(lastResortPick($round, $this->at)?->movieIds)->toHaveCount(DecoyPick::COUNT);
});

it('ne change rien à un tirage que R1 à R4 suffisaient à composer', function () {
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(noRepeatMovies: true);

    // De quoi nourrir R5 et R6 : des films joués par le salon et une réserve
    // non publiée, aux deux profils.
    $previous = PoolFixtures::game($room, $settings);

    foreach ([lastResortPublished(), lastResortPublished(lastResortFrenchOnly())] as $index => $movie) {
        PoolFixtures::round($previous, $movie, $this->at->subHours(2)->addMinutes($index));
    }

    $hidden = [
        lastResortHidden(ContentAvailability::Draft),
        lastResortHidden(ContentAvailability::Unpublished),
        lastResortHidden(ContentAvailability::Draft, lastResortFrenchOnly()),
    ];

    // Mode normal : R1 suffit.
    $game = lastResortGame($room, $settings, lastResortSeeds()[0]);
    $round = lastResortTarget($game, lastResortPublished(), $this->at);
    $peers = [lastResortPublished(), lastResortPublished(), lastResortPublished()];

    // Mode dégradé : une cible « français seul » sans pair au même profil
    // tombe en R3 sur les films de même forme latine.
    $degradedGame = lastResortGame($room, $settings, lastResortSeeds()[1]);
    $degradedRound = lastResortTarget($degradedGame, lastResortPublished(['de' => lastResortTitle()]), $this->at);

    foreach (lastResortSeeds() as $seed) {
        $pick = lastResortPick($round, $this->at, $seed);

        expect($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds)->toBe(lastResortWalk($round, DrawContext::decoys($round->sequence_index), $peers));

        $degraded = lastResortPick($degradedRound, $this->at, $seed);
        $published = Movie::query()
            ->inPool()
            ->whereNotIn('id', [$degradedRound->movie_id, $round->movie_id, ...array_map(static fn (Movie $movie): int => $movie->id, $hidden)])
            ->whereNotIn('id', Round::query()->where('room_id', $room->id)->where('game_id', '!=', $degradedGame->id)->pluck('movie_id'))
            ->orderBy('id')
            ->get()
            ->all();

        expect($degraded?->useOriginalTitle)->toBeTrue()
            ->and($degraded?->movieIds)->toBe(lastResortWalk(
                $degradedRound,
                DrawContext::decoysOriginal($degradedRound->sequence_index),
                $published,
            ));

        foreach ($hidden as $movie) {
            expect($pick?->movieIds)->not->toContain($movie->id)
                ->and($degraded?->movieIds)->not->toContain($movie->id);
        }
    }
});

it('R5 reprend les leurres non joués que R1-R2 avaient retenus au lieu de les jeter', function () {
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(noRepeatMovies: true);

    // Quatre films au profil de la cible, réponses d'une partie précédente du
    // salon : la non-répétition les retire de R1 à R4.
    $previous = PoolFixtures::game($room, $settings);
    $played = [lastResortPublished(), lastResortPublished(), lastResortPublished(), lastResortPublished()];

    foreach ($played as $index => $movie) {
        PoolFixtures::round($previous, $movie, $this->at->subHours(2)->addMinutes($index));
    }

    // Deux films non joués seulement : R1-R2 en retiennent deux, il en manque
    // un. Ils doivent rester dans le QCM, complété par un seul film joué.
    $unplayed = [lastResortPublished(), lastResortPublished()];

    $game = lastResortGame($room, $settings, lastResortSeeds()[0]);
    $round = lastResortTarget($game, lastResortPublished(), $this->at);

    foreach (lastResortSeeds() as $seed) {
        $pick = lastResortPick($round, $this->at, $seed);
        $carried = lastResortWalk($round, DrawContext::decoys($round->sequence_index), $unplayed);

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeFalse()
            ->and($pick?->movieIds)->toContain($unplayed[0]->id)
            ->and($pick?->movieIds)->toContain($unplayed[1]->id)
            ->and($pick?->movieIds)->toBe(lastResortWalk(
                $round,
                DrawContext::decoysLastResort($round->sequence_index),
                $played,
                $carried,
            ));
    }
});

it("R6 n'hydrate qu'un lot de la réserve, jamais la réserve entière, même au titre original", function () {
    $game = lastResortGame(Room::factory()->create(), PoolFixtures::settings(), lastResortSeeds()[0]);

    // Cible au titre natif, « français seul », seule de son profil au
    // catalogue publié : on va jusqu'à R6 au titre original.
    $target = lastResortPublished(lastResortFrenchOnly(), '青い猫の庭');
    lastResortPublished();

    // Une réserve volumineuse : beaucoup de brouillons latins d'un autre
    // masque (hors profil aux deux passages), et assez de brouillons natifs
    // d'un autre masque pour le passage au titre original.
    for ($index = 0; $index < 60; $index++) {
        lastResortHidden(ContentAvailability::Draft, lastResortEnglishOnly());
    }

    $native = [];

    for ($index = 0; $index < 3 * DecoyPicker::LAST_RESORT_BATCH; $index++) {
        $native[] = lastResortHidden(ContentAvailability::Draft, lastResortEnglishOnly(), "赤い月の森 {$index}");
    }

    $round = lastResortTarget($game, $target, $this->at);

    $hydrated = 0;
    Event::listen('eloquent.retrieved: '.Movie::class, static function () use (&$hydrated): void {
        $hydrated++;
    });

    foreach (lastResortSeeds() as $seed) {
        $hydrated = 0;
        $pick = lastResortPick($round, $this->at, $seed);

        expect($pick)->not->toBeNull()
            ->and($pick?->useOriginalTitle)->toBeTrue()
            ->and($pick?->movieIds)->toBe(lastResortWalk(
                $round,
                DrawContext::decoysLastResortOriginal($round->sequence_index),
                $native,
            ))
            // La cible, la manche et le catalogue publié (deux films, lus
            // par chaque rang publié), plus un lot de la réserve.
            ->and($hydrated)->toBeLessThanOrEqual(DecoyPicker::LAST_RESORT_BATCH + 16);
    }
});
