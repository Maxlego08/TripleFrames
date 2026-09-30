<?php

use App\Enums\RoundStatus;
use App\Models\Movie;
use App\Models\Room;
use App\Settings\PlatformLimits;
use App\Support\Draw\RoomMemoryWindow;
use Carbon\CarbonImmutable;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Mémoire du salon — spec 30 § 3.5, lot L30-2, contrat C2
|--------------------------------------------------------------------------
|
| `since(salon, now) = max(now − jours, t)`, `t` étant le `started_at` de la
| `roomMemoryWindowRounds()`-ième manche JOUÉE la plus récente du salon :
| la borne atteinte en premier gagne. « Jouée » = `started_at <= now` — une
| manche programmée dans le futur, une réserve jamais démarrée et les manches
| d'un autre salon n'entrent pas dans le décompte.
|
| Les deux bornes sont posées par `platformLimitsConfigure()` : aucune valeur
| n'est lue en dur, et la même fonction sert la non-répétition, la préférence
| de variante et la purge de `seen_frame`.
|
*/

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-09-24 10:00:00.250');
    $this->room = Room::factory()->create();
    $this->game = PoolFixtures::game($this->room);
});

/**
 * Une manche du salon démarrée à `$startedAt` (nul = réserve jamais démarrée),
 * sur un film quelconque : la mémoire ne lit que `round`.
 */
function roomMemoryWindowRound(mixed $test, ?CarbonImmutable $startedAt, RoundStatus $status = RoundStatus::Completed): void
{
    PoolFixtures::round($test->game, Movie::factory()->create(), $startedAt, $status, reserve: $startedAt === null);
}

/**
 * Bruit qui ne doit jamais compter : une manche programmée (T₁ à venir), une
 * réserve jamais démarrée, et des manches récentes d'un AUTRE salon.
 */
function roomMemoryWindowNoise(mixed $test): void
{
    roomMemoryWindowRound($test, $test->now->addMinute(), RoundStatus::Pending);
    roomMemoryWindowRound($test, null, RoundStatus::Pending);

    $elsewhere = PoolFixtures::game(Room::factory()->create());

    foreach (range(1, PlatformLimits::roomMemoryWindowRounds() + 1) as $minutes) {
        PoolFixtures::round($elsewhere, Movie::factory()->create(), $test->now->subMinutes($minutes));
    }
}

it('la fenêtre est la plus récente des deux bornes : jours ou N-ième manche démarrée', function () {
    platformLimitsConfigure([
        'room_memory_window_rounds' => 3,
        'room_memory_window_days' => 30,
    ]);

    roomMemoryWindowNoise($this);

    // Quatre manches jouées, la troisième plus récente à J−3 ; une annulée compte.
    roomMemoryWindowRound($this, $this->now->subDay());
    roomMemoryWindowRound($this, $this->now->subDays(2), RoundStatus::Cancelled);
    roomMemoryWindowRound($this, $this->now->subDays(3));
    roomMemoryWindowRound($this, $this->now->subDays(4));

    // Borne en manches (J−3) plus récente que la borne en jours (J−30) : elle gagne.
    $since = RoomMemoryWindow::since($this->room->id, $this->now);

    expect($since->equalTo($this->now->subDays(3)))->toBeTrue((string) $since);

    // Borne en jours (J−2) plus récente que la troisième manche (J−3) : elle gagne.
    platformLimitsConfigure(['room_memory_window_days' => 2]);

    $since = RoomMemoryWindow::since($this->room->id, $this->now);

    expect($since->equalTo($this->now->subDays(2)))->toBeTrue((string) $since);

    // La manche programmée n'est jouée qu'une fois son T₁ franchi : à cet
    // instant, elle devient la plus récente et décale la N-ième d'un rang.
    platformLimitsConfigure(['room_memory_window_days' => 30]);

    $later = $this->now->addMinute();

    expect(RoomMemoryWindow::since($this->room->id, $later)->equalTo($this->now->subDays(2)))->toBeTrue();
});

it('moins de N manches démarrées : seule la borne en jours s\'applique', function () {
    platformLimitsConfigure([
        'room_memory_window_rounds' => 3,
        'room_memory_window_days' => 30,
    ]);

    roomMemoryWindowNoise($this);

    roomMemoryWindowRound($this, $this->now->subDay());
    roomMemoryWindowRound($this, $this->now->subDays(40));

    $since = RoomMemoryWindow::since($this->room->id, $this->now);

    expect($since->equalTo($this->now->subDays(30)))->toBeTrue((string) $since);

    // Un salon neuf repart d'une mémoire vide : la borne en jours seule.
    $fresh = Room::factory()->create();

    expect(RoomMemoryWindow::since($fresh->id, $this->now)->equalTo($this->now->subDays(30)))->toBeTrue();
});
