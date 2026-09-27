<?php

use App\Actions\Room\HandOverHost;
use App\Actions\Room\KickSeat;
use App\Enums\Locale;
use App\Enums\SettingPresetKey;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Policies\RoomPolicy;
use App\Settings\RoomSettingsBounds;
use App\Support\Identity\PlayerToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

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

beforeEach(function (): void {
    SeatEntry::isolateCookies();
});

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

it("n'accorde aucun geste de salon à un administrateur qui n'est pas l'hôte", function (): void {
    [$room, $host, $hostToken] = HostGestures::room();
    [$adminSeat, $adminToken] = HostGestures::seat($room, 20);
    [$target] = HostGestures::seat($room, 10);
    [$elsewhere] = HostGestures::room();
    [$foreignSeat] = HostGestures::seat($elsewhere, 10);
    $admin = User::factory()->admin()->create();

    // Aucun `Gate::before` : rien ne court-circuite les policies.
    $gate = Gate::getFacadeRoot();

    expect((new ReflectionProperty($gate, 'beforeCallbacks'))->getValue($gate))->toBe([]);

    // Toutes les méthodes de la policy, présentes et futures : sans siège,
    // l'administrateur n'a aucun geste — pas même quitter.
    $abilities = array_values(array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            (new ReflectionClass(RoomPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => ! $method->isStatic() && ! $method->isConstructor(),
        ),
    ));

    expect($abilities)->toContain('updateSettings', 'launch', 'advanceRound', 'kick', 'transferHost', 'leave');

    foreach ($abilities as $ability) {
        expect(Gate::forUser($admin)->allows($ability, [$room, null]))->toBeFalse($ability)
            ->and(Gate::forUser($admin)->allows($ability, [$room, $foreignSeat]))->toBeFalse($ability);
    }

    // Tenant un siège qui n'est pas l'hôte : aucun geste d'hôte ; quitter son
    // propre siège, comme tout joueur.
    foreach (array_diff($abilities, ['leave']) as $ability) {
        expect(Gate::forUser($admin)->allows($ability, [$room, $adminSeat]))->toBeFalse($ability);
    }

    expect(Gate::forUser($admin)->allows('leave', [$room, $adminSeat]))->toBeTrue();

    // Par les routes, connecté à son compte : 403, rien d'écrit.
    $roomRow = HostGestures::raw('room', $room->id);
    $targetRow = HostGestures::raw('player', $target->id);
    $this->actingAs($admin);

    HostGestures::kick($this, $room, $adminToken, $adminSeat, $target->public_id)->assertForbidden();
    HostGestures::transfer($this, $room, $adminToken, $adminSeat, ['publicId' => $target->public_id])->assertForbidden();
    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, ['roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT], $adminSeat)->assertForbidden();
    LobbyWrites::send($this, 'POST', route('room.launch', $room), $room, [], $adminSeat)->assertForbidden();

    // Sans siège dans ce salon, le compte seul n'ouvre rien : 403.
    HostGestures::kick($this, $room, PlayerToken::mint(Locale::French), $host, $target->public_id)->assertForbidden();

    expect(HostGestures::raw('room', $room->id))->toBe($roomRow)
        ->and(HostGestures::raw('player', $target->id))->toBe($targetRow);

    // L'autorité suit le siège, jamais le rôle : l'hôte invité garde ses gestes.
    expect(Gate::forUser(null)->allows('kick', [$room, $host]))->toBeTrue()
        ->and(Gate::forUser(null)->allows('transferHost', [$room, $host]))->toBeTrue();

    HostGestures::kick($this, $room, $hostToken, $host, $target->public_id)->assertStatus(Response::HTTP_SEE_OTHER);

    expect($target->refresh()->wasKicked())->toBeTrue();
});

it("relit l'autorité d'hôte sous verrou à chaque écriture", function (): void {
    // Chaque écriture d'hôte : la policy passe (le siège est hôte à la
    // requête), puis le rôle change avant le verrou du salon. L'action relit
    // l'autorité sous ce verrou et refuse : 403, rien d'écrit.
    $cases = [
        'réglages' => ['updateSettings', static fn (Room $room, Player $target): array => ['PATCH', route('room.settings.update', $room), ['roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT]]],
        'preset' => ['updateSettings', static fn (Room $room, Player $target): array => ['POST', route('room.settings.preset', $room), ['preset' => SettingPresetKey::Fast->value]]],
        'lancement' => ['launch', static fn (Room $room, Player $target): array => ['POST', route('room.launch', $room), []]],
        'expulsion' => ['kick', static fn (Room $room, Player $target): array => ['POST', route('room.players.kick', ['room' => $room, 'target' => $target->public_id]), []]],
        'transfert' => ['transferHost', static fn (Room $room, Player $target): array => ['POST', route('room.host.transfer', $room), ['publicId' => $target->public_id]]],
    ];

    foreach ($cases as $label => [$ability, $request]) {
        [$room, $host, $hostToken] = HostGestures::room();
        [$successor] = HostGestures::seat($room, 20);
        [$target] = HostGestures::seat($room, 10);
        $evaluated = false;

        Event::listen(GateEvaluated::class, static function (GateEvaluated $event) use ($ability, $room, $successor, &$evaluated): void {
            $subject = $event->arguments[0] ?? null;

            if ($event->ability !== $ability || $event->result !== true || ! $subject instanceof Room || $subject->id !== $room->id) {
                return;
            }

            $evaluated = true;
            Room::query()->whereKey($room->id)->update(['host_player_id' => $successor->id]);
        });

        $roomRow = array_merge(HostGestures::raw('room', $room->id), ['host_player_id' => $successor->id]);
        $targetRow = HostGestures::raw('player', $target->id);
        [$method, $uri, $body] = $request($room, $target);

        LobbyWrites::actAs($this, $hostToken);
        LobbyWrites::send($this, $method, $uri, $room, $body, $host)->assertForbidden();

        expect($evaluated)->toBeTrue($label)
            ->and(HostGestures::raw('room', $room->id))->toBe($roomRow)
            ->and(HostGestures::raw('player', $target->id))->toBe($targetRow)
            ->and(Game::query()->where('room_id', $room->id)->exists())->toBeFalse();
    }

    // Les actions elles-mêmes, appelées avec un hôte périmé.
    [$room, $host] = HostGestures::room();
    [$successor] = HostGestures::seat($room, 20);
    [$target] = HostGestures::seat($room, 10);
    Room::query()->whereKey($room->id)->update(['host_player_id' => $successor->id]);

    expect(fn () => app(KickSeat::class)->handle($room, $host, $target))->toThrow(AuthorizationException::class)
        ->and(fn () => app(HandOverHost::class)->handle($room, $host, $target->public_id))->toThrow(AuthorizationException::class)
        ->and($target->refresh()->wasKicked())->toBeFalse()
        ->and(Room::query()->whereKey($room->id)->value('host_player_id'))->toBe($successor->id);
});
