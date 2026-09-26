<?php

use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Gestes de salon — spec 50 § 17.1, contrat C6 § 2
|--------------------------------------------------------------------------
|
| L'autorité d'hôte est portée par un SIÈGE (`room.host_player_id`), jamais
| par un compte ni par un rôle : chaque méthode de `RoomPolicy` est vraie si
| et seulement si le siège appartient au salon et en est l'hôte. Aucune
| clause de rôle, aucun `Gate::before` : un administrateur n'a aucun geste
| sur un salon. La policy n'est qu'une première garde : chaque action relit
| l'autorité sous le verrou du salon.
|
*/

it('refuse la manche suivante à un non-hôte', function (): void {
    $room = Room::factory()->playing()->create();
    $host = Player::factory()->for($room)->create();
    $guest = Player::factory()->for($room)->create();
    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);
    $room->refresh();

    $elsewhere = Room::factory()->playing()->create();
    $foreignHost = Player::factory()->for($elsewhere)->create();
    Room::query()->whereKey($elsewhere->id)->update(['host_player_id' => $foreignHost->id]);

    $admin = User::factory()->admin()->create();

    $allows = static fn (?User $user, Room $target, ?Player $seat): bool => Gate::forUser($user)
        ->allows('advanceRound', [$target, $seat]);

    // L'hôte, invité ou connecté à un compte : seul autorisé.
    expect($allows(null, $room, $host))->toBeTrue()
        ->and($allows($admin, $room, $host))->toBeTrue();

    // Un siège non hôte du salon, un visiteur sans siège, l'hôte d'un AUTRE
    // salon, et un administrateur sans le siège hôte : refusés.
    expect($allows(null, $room, $guest))->toBeFalse()
        ->and($allows(null, $room, null))->toBeFalse()
        ->and($allows(null, $room, $foreignHost))->toBeFalse()
        ->and($allows($admin, $room, $guest))->toBeFalse()
        ->and($allows($admin, $room, null))->toBeFalse();

    // L'autorité suit le siège : le rôle transféré, l'ancien hôte est refusé.
    Room::query()->whereKey($room->id)->update(['host_player_id' => $guest->id]);
    $room->refresh();

    expect($allows(null, $room, $guest))->toBeTrue()
        ->and($allows(null, $room, $host))->toBeFalse();

    // Référence d'hôte vide : personne.
    Room::query()->whereKey($room->id)->update(['host_player_id' => null]);
    $room->refresh();

    expect($allows(null, $room, $guest))->toBeFalse()
        ->and($allows(null, $room, $host))->toBeFalse();

    // Le même siège, pour le lancement : même clause, mêmes refus.
    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);
    $room->refresh();

    expect(Gate::forUser(null)->allows('launch', [$room, $host]))->toBeTrue()
        ->and(Gate::forUser(null)->allows('launch', [$room, $guest]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('launch', [$room, null]))->toBeFalse();
});
