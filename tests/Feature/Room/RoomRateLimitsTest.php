<?php

use App\Support\Room\RoomRateLimits;

/*
|--------------------------------------------------------------------------
| Limiteurs d'entrée du salon — spec 50 § 17.3
|--------------------------------------------------------------------------
|
| `RoomRateLimits` suit le patron de `PlatformLimits` : chaque débit est
| déclaré dans `config/game.php › room` à sa constante `DEFAULT_*`, lu par son
| accesseur, et gardé à chaque lecture. Un débit nul refuserait tout : un
| limiteur n'est jamais un interrupteur.
|
*/

it('déclare une clé game.room par débit d\'entrée, et réciproquement', function (): void {
    expect(config('game.room'))->toBe([
        'creates_per_hour' => RoomRateLimits::DEFAULT_CREATES_PER_HOUR,
        'joins_per_minute' => RoomRateLimits::DEFAULT_JOINS_PER_MINUTE,
    ]);

    expect(RoomRateLimits::createsPerHour())->toBe(RoomRateLimits::DEFAULT_CREATES_PER_HOUR);
    expect(RoomRateLimits::joinsPerMinute())->toBe(RoomRateLimits::DEFAULT_JOINS_PER_MINUTE);

    config()->set('game.room.creates_per_hour', 3);
    config()->set('game.room.joins_per_minute', 4);

    expect(RoomRateLimits::createsPerHour())->toBe(3);
    expect(RoomRateLimits::joinsPerMinute())->toBe(4);
});

it('refuse un débit d\'entrée nul ou négatif', function (string $key, int $value, string $accessor): void {
    config()->set('game.room.'.$key, $value);

    expect(fn (): int => RoomRateLimits::{$accessor}())->toThrow(InvalidArgumentException::class);
})->with([
    'créations nulles' => ['creates_per_hour', 0, 'createsPerHour'],
    'entrées négatives' => ['joins_per_minute', -1, 'joinsPerMinute'],
]);
