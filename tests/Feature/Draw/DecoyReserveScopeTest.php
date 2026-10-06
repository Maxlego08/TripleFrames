<?php

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\RoundStatus;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\Room;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolScope;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Réserve non publiée des leurres — spec 30 § 3.2, spec 70 § 10.3, D53 du 02/10
|--------------------------------------------------------------------------
|
| `PoolScope::asDecoyReserve()` est le seul périmètre de `PoolQuery` dont la
| clause de catalogue n'est pas « publié et clear » : `draft` ou
| `unpublished`, toujours `clear`. Il sert le rang R6 des leurres et rien
| d'autre : le constructeur refuse de lui rendre un thème, un `N` ou la
| non-répétition, et `PoolQuery` refuse de le compter ou d'en tirer des
| candidats.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();

    $this->at = CarbonImmutable::parse('2026-10-02 10:00:00.250');
});

/** Un film sans banque d'images à la disponibilité et au verdict voulus. */
function decoyReserveMovie(ContentAvailability $availability, ContentFlag $flag = ContentFlag::Clear, ?MovieGroup $group = null): Movie
{
    return PoolFixtures::movie([], $group, static function (MovieFactory $factory) use ($availability, $flag): MovieFactory {
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

        return $factory->contentFlag($flag);
    });
}

it('la réserve des leurres ne lit que les films draft ou unpublished et clear, exclusions comprises', function () {
    $room = Room::factory()->create();
    $settings = PoolFixtures::settings(noRepeatMovies: true);
    $game = PoolFixtures::game($room, $settings);
    $group = MovieGroup::factory()->create();

    $target = PoolFixtures::movie(group: $group);
    $round = PoolFixtures::round($game, $target, $this->at->subSeconds(RoomSettingsBounds::MIN_TIER_DURATION), RoundStatus::Running);

    $eligible = [
        decoyReserveMovie(ContentAvailability::Draft),
        decoyReserveMovie(ContentAvailability::Unpublished),
    ];

    // Jamais : publié, suspendu, retiré, bloqué, en attente de verdict, frère
    // de la cible.
    PoolFixtures::movie();
    decoyReserveMovie(ContentAvailability::Suspended);
    decoyReserveMovie(ContentAvailability::Withdrawn);
    decoyReserveMovie(ContentAvailability::Draft, ContentFlag::Blocked);
    decoyReserveMovie(ContentAvailability::Unpublished, ContentFlag::UnratedPending);
    decoyReserveMovie(ContentAvailability::Draft, group: $group);

    // Le film d'une manche démarrée, dépublié depuis : toujours exclu.
    $started = PoolFixtures::movie();
    PoolFixtures::round($game, $started, $this->at->subMinutes(2));
    Movie::query()->whereKey($started->id)->update(['availability' => ContentAvailability::Unpublished]);

    $reserve = PoolScope::forDecoys($round, $this->at)->asDecoyReserve();
    $ids = array_map(static fn (Movie $movie): int => $movie->id, app(PoolQuery::class)->movies($reserve)->get()->all());
    $expected = array_map(static fn (Movie $movie): int => $movie->id, $eligible);
    sort($expected);

    expect($ids)->toBe($expected)
        ->and($reserve->decoyReserve)->toBeTrue()
        ->and($reserve->themeIds)->toBe([])
        ->and($reserve->framesPerRound)->toBeNull()
        ->and($reserve->noRepeatMovies)->toBeFalse()
        ->and($reserve->excludedMovieIds)->toContain($target->id, $started->id)
        ->and($reserve->excludedGroupIds)->toBe([$group->id]);
});

it('la réserve des leurres est refusée partout ailleurs', function () {
    $room = Room::factory()->create();
    $game = PoolFixtures::game($room, PoolFixtures::settings(noRepeatMovies: true));
    $round = PoolFixtures::round($game, PoolFixtures::movie(), $this->at, RoundStatus::Running);
    $reserve = PoolScope::forDecoys($round, $this->at)->asDecoyReserve();
    $pool = app(PoolQuery::class);

    // Ni thème, ni N, ni non-répétition ne lui sont rendus ; sans la cible
    // exclue, elle n'existe pas.
    expect(fn () => $reserve->withThemeIds([1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reserve->withFramesPerRound(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PoolScope::catalogue([], null)->asDecoyReserve())->toThrow(InvalidArgumentException::class)
        ->and(fn () => PoolScope::forRoom($room, PoolFixtures::settings(), $this->at)->asDecoyReserve())->toThrow(InvalidArgumentException::class);

    // Jamais comptée, jamais tirée comme film de manche.
    expect(fn () => $pool->countWorks($reserve))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $pool->candidates($reserve))->toThrow(InvalidArgumentException::class);

    // Aucun autre constructeur ne la porte.
    expect(PoolScope::forRoom($room, PoolFixtures::settings(), $this->at)->decoyReserve)->toBeFalse()
        ->and(PoolScope::forGame($game, $this->at)->decoyReserve)->toBeFalse()
        ->and(PoolScope::forDecoys($round, $this->at)->decoyReserve)->toBeFalse()
        ->and(PoolScope::forDecoys($round, $this->at)->withoutNoRepeat()->decoyReserve)->toBeFalse()
        ->and(PoolScope::catalogue([], null)->decoyReserve)->toBeFalse();

    // Témoin : une exclusion ajoutée et la non-répétition levée la gardent.
    expect($reserve->excluding([PHP_INT_MAX], [])->decoyReserve)->toBeTrue()
        ->and($reserve->withoutNoRepeat()->decoyReserve)->toBeTrue();
});
