<?php

use App\Enums\Locale;
use App\Enums\RoomStatus;
use App\Enums\SettingPresetKey;
use App\Http\Controllers\Room\RoomPresetController;
use App\Http\Controllers\Room\RoomSettingsController;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Middleware\SetLocale;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Policies\RoomPolicy;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Support\Identity\PlayerToken;
use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Room\LobbyWrites;

/*
|--------------------------------------------------------------------------
| Routes des réglages de salon — spec 50 § 3.1, § 5.3, § 12.5, § 17.1,
| § 21 ; contrats C0, C6, C7 (lot L50-2, second temps)
|--------------------------------------------------------------------------
|
| Ajouts hors intitulés de la spec : la couche HTTP des deux écritures de
| l'hôte. Pile `seat.active` puis `throttle:game-write` ; policy
| `RoomPolicy::updateSettings` ; forme du corps seule dans les FormRequest ;
| traduction de l'issue de l'action en réponse (écrite : flash et retour ;
| `not_host` : 403 ; `not_in_lobby` : 303 sans erreur). Aucune réponse 409
| ni 422 à une visite Inertia hors de `seat.active`.
|
*/

/**
 * La ligne `room` brute : ce qu'une écriture refusée ne doit pas toucher.
 *
 * @return array<string, mixed>
 */
function settingsRouteRow(Room $room): array
{
    return (array) DB::table('room')->where('id', $room->id)->first();
}

it('déclare les deux écritures de réglages sous seat.active puis throttle:game-write, avant la liaison du salon', function (): void {
    // Le noyau HTTP pose ses groupes et sa liste de priorité à sa construction.
    app(HttpKernel::class);
    $router = app('router');

    $expected = [
        'room.settings.update' => ['PATCH', 'r/{room}/settings', RoomSettingsController::class.'@update'],
        'room.settings.preset' => ['POST', 'r/{room}/settings/preset', RoomPresetController::class.'@store'],
    ];

    foreach ($expected as $name => [$method, $uri, $action]) {
        $route = $router->getRoutes()->getByName($name);

        expect($route)->not->toBeNull();

        if ($route === null) {
            continue;
        }

        expect($route->methods())->toContain($method)
            ->and($route->uri())->toBe($uri)
            ->and($route->getActionName())->toBe($action)
            ->and($route->gatherMiddleware())->toContain('web', 'seat.active', 'throttle:game-write');

        // Aucun domaine de traduction : l'écriture redirige, elle ne rend pas de page.
        expect(array_filter($route->gatherMiddleware(), static fn (string $m): bool => str_starts_with($m, 'translations:')))
            ->toBe([]);

        $stack = $router->gatherRouteMiddleware($route);
        $at = static fn (string $middleware): int|false => array_search($middleware, $stack, true);

        expect($at(SetLocale::class))->toBeInt()
            ->and($at(SetLocale::class))->toBeLessThan($at(EnsureActiveSeat::class))
            ->and($at(EnsureActiveSeat::class))->toBeLessThan($at(ThrottleRequests::class.':game-write'))
            ->and($at(ThrottleRequests::class.':game-write'))->toBeLessThan($at(SubstituteBindings::class));
    }
});

it("refuse l'écriture d'un onglet supplanté en 409 et un visiteur sans siège en 403, sans rien écrire", function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token);
    $before = settingsRouteRow($room);
    $routes = [
        ['PATCH', route('room.settings.update', $room), ['roundsCount' => Bounds::MIN_ROUNDS_COUNT]],
        ['POST', route('room.settings.preset', $room), ['preset' => SettingPresetKey::Fast->value]],
    ];

    LobbyWrites::actAs($this, $token);

    foreach ($routes as [$method, $uri, $body]) {
        // Onglet supplanté, ou aucun en-tête : 409 en données, jamais une page.
        LobbyWrites::send($this, $method, $uri, $room, $body, (string) Str::ulid())
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertExactJson(['code' => EnsureActiveSeat::SUPERSEDED]);
        LobbyWrites::send($this, $method, $uri, $room, $body, false)
            ->assertStatus(Response::HTTP_CONFLICT);
    }

    // Un jeton sans siège dans ce salon : 403, jamais 409.
    LobbyWrites::actAs($this, PlayerToken::mint(Locale::French));

    foreach ($routes as [$method, $uri, $body]) {
        LobbyWrites::send($this, $method, $uri, $room, $body, $host)->assertForbidden();
    }

    // L'hôte expulsé n'écrit plus.
    LobbyWrites::actAs($this, $token);
    $host->forceFill(['kicked_at' => now(), 'left_at' => now()])->save();

    foreach ($routes as [$method, $uri, $body]) {
        LobbyWrites::send($this, $method, $uri, $room, $body, $host)->assertForbidden();
    }

    expect(settingsRouteRow($room))->toBe($before);

    // Salon archivé : son code a quitté le créneau actif, plus aucun siège n'y écrit.
    [$archived, $archivedHost] = LobbyWrites::hostedRoom($token);
    Room::query()->whereKey($archived->id)->update([
        'status' => RoomStatus::Archived->value,
        'room_code_active' => null,
        'archived_at' => now(),
    ]);
    $before = settingsRouteRow($archived);

    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $archived), $archived, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT], $archivedHost)
        ->assertForbidden();
    LobbyWrites::send($this, 'POST', route('room.settings.preset', $archived), $archived, ['preset' => SettingPresetKey::Fast->value], $archivedHost)
        ->assertForbidden();

    expect(settingsRouteRow($archived))->toBe($before);
});

it("refuse en 403 un siège qui n'est pas l'hôte, et suit le transfert du rôle", function (): void {
    $hostToken = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($hostToken);
    $guestToken = PlayerToken::mint(Locale::English);
    $guest = LobbyWrites::seat($room, $guestToken);
    $before = settingsRouteRow($room);

    // Les contrôleurs passent par la policy, avant l'action qui relit
    // l'autorité sous le verrou : chaque geste est évalué `updateSettings`,
    // sur le salon et le siège résolu par `seat.active`.
    $evaluated = [];
    Event::listen(GateEvaluated::class, function (GateEvaluated $event) use (&$evaluated): void {
        $seat = $event->arguments[1] ?? null;
        $evaluated[] = [$event->ability, $seat instanceof Player ? $seat->id : null, $event->result];
    });

    LobbyWrites::actAs($this, $guestToken);

    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT], $guest)
        ->assertForbidden();
    LobbyWrites::send($this, 'POST', route('room.settings.preset', $room), $room, ['preset' => SettingPresetKey::Fast->value], $guest)
        ->assertForbidden();

    expect(settingsRouteRow($room))->toBe($before);

    // Le rôle transféré : le nouvel hôte écrit, l'ancien est refusé.
    Room::query()->whereKey($room->id)->update(['host_player_id' => $guest->id]);

    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT], $guest)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors();

    LobbyWrites::actAs($this, $hostToken);

    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, ['roundsCount' => Bounds::MAX_ROUNDS_COUNT], $host)
        ->assertForbidden();

    expect(Room::query()->findOrFail($room->id)->rounds_count)->toBe(Bounds::MIN_ROUNDS_COUNT)
        ->and($evaluated)->toBe([
            ['updateSettings', $guest->id, false],
            ['updateSettings', $guest->id, false],
            ['updateSettings', $guest->id, true],
            ['updateSettings', $host->id, false],
        ]);
});

it('RoomPolicy::updateSettings ne connaît que le siège hôte du salon, sans clause de rôle', function (): void {
    $room = Room::factory()->create();
    $host = Player::factory()->for($room)->create();
    $guest = Player::factory()->for($room)->create();
    $elsewhere = Player::factory()->create();
    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);
    $room->refresh();
    $admin = User::factory()->admin()->create();
    $policy = new RoomPolicy;

    expect($policy->updateSettings(null, $room, $host))->toBeTrue()
        ->and($policy->updateSettings(null, $room, $guest))->toBeFalse()
        ->and($policy->updateSettings(null, $room, null))->toBeFalse()
        // Un siège d'un autre salon, même désigné par la référence d'hôte.
        ->and($policy->updateSettings(null, (clone $room)->forceFill(['host_player_id' => $elsewhere->id]), $elsewhere))->toBeFalse()
        // Aucune clause de rôle : un admin sans le siège hôte n'a aucun geste.
        ->and($policy->updateSettings($admin, $room, $guest))->toBeFalse()
        ->and($policy->updateSettings($admin, $room, null))->toBeFalse()
        // Référence d'hôte vide : personne.
        ->and($policy->updateSettings(null, (clone $room)->forceFill(['host_player_id' => null]), $host))->toBeFalse();

    // Découverte automatiquement, et ouverte aux invités (`?User`).
    expect(Gate::getPolicyFor(Room::class))->toBeInstanceOf(RoomPolicy::class)
        ->and(Gate::forUser(null)->allows('updateSettings', [$room, $host]))->toBeTrue()
        ->and(Gate::forUser(null)->allows('updateSettings', [$room, $guest]))->toBeFalse();
});

it('renvoie au salon sans erreur ni rapport une écriture hors du lobby, en 303', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token);
    LobbyWrites::actAs($this, $token);

    // Partie lancée, podium compris : les réglages sont figés jusqu'au « Rejouer ».
    Room::query()->whereKey($room->id)->update(['status' => RoomStatus::Playing->value]);
    $before = settingsRouteRow($room);

    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT], $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlashMissing('settingsChanges');

    LobbyWrites::send($this, 'POST', route('room.settings.preset', $room), $room, ['preset' => SettingPresetKey::Fast->value], $host)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlashMissing('settingsChanges');

    // Sans en-tête `Referer` ni URL précédente en session : la page du salon
    // (`room.show`), jamais un retour arrière vers l'accueil (L50-3b, E86-1).
    $this->flushSession();
    $this->withoutHeader('referer')
        ->json('PATCH', route('room.settings.update', $room), ['roundsCount' => Bounds::MIN_ROUNDS_COUNT], [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
            EnsureActiveSeat::HEADER => (string) $host->active_seat_token,
        ])
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlashMissing('settingsChanges');

    expect(settingsRouteRow($room))->toBe($before);
});

it("rend les refus de l'éditeur et de la forme du corps en erreurs de champ, dans la langue de l'hôte", function (): void {
    foreach ([Locale::French, Locale::English] as $locale) {
        $token = PlayerToken::mint($locale);
        [$room, $host] = LobbyWrites::hostedRoom($token, RoomSettings::fromInput(['capacity' => Bounds::MIN_CAPACITY + 1]));
        Player::factory()->for($room)->count(Bounds::MIN_CAPACITY)->create();
        $holding = Bounds::MIN_CAPACITY + 1;
        $before = settingsRouteRow($room);
        $update = route('room.settings.update', $room);
        $attribute = static fn (string $field): string => trans('validation.attributes.'.$field, locale: $locale->value);

        $this->flushSession();
        LobbyWrites::actAs($this, $token);

        // Garde de capacité, sous le verrou : erreur sous `capacity`, jamais un 422.
        LobbyWrites::send($this, 'PATCH', $update, $room, ['capacity' => Bounds::MIN_CAPACITY], $host)
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(LobbyWrites::lobbyUrl($room))
            ->assertSessionHasErrors([
                'capacity' => trans('validation.room_settings.capacity_below_headcount', ['count' => $holding], $locale->value),
            ])
            ->assertInertiaFlashMissing('settingsChanges');

        // Clé hors de l'onglet Simple : `not_editable`, sous la clé du champ.
        LobbyWrites::send($this, 'PATCH', $update, $room, ['speedBonus' => false], $host)
            ->assertSessionHasErrors([
                'speedBonus' => trans('validation.room_settings.not_editable', ['attribute' => $attribute('speedBonus')], $locale->value),
            ]);

        // Forme du corps : `advanced` illisible, même texte que le value object.
        LobbyWrites::send($this, 'PATCH', $update, $room, ['advanced' => 'oui'], $host)
            ->assertSessionHasErrors([
                'advanced' => trans('validation.room_settings.boolean', ['attribute' => $attribute('advanced')], $locale->value),
            ]);

        // Preset inconnu ou absent : erreur sous `preset`.
        LobbyWrites::send($this, 'POST', route('room.settings.preset', $room), $room, ['preset' => 'marathon'], $host)
            ->assertSessionHasErrors('preset');
        LobbyWrites::send($this, 'POST', route('room.settings.preset', $room), $room, [], $host)
            ->assertSessionHasErrors('preset');

        expect(settingsRouteRow($room))->toBe($before);
    }
});

it('ne lit que le corps posté, sans les champs de transport du framework', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token);
    LobbyWrites::actAs($this, $token);

    // Formulaire Wayfinder : usurpation de méthode dans l'URL (`.form()`),
    // jeton CSRF éventuel dans le corps. Ni l'un ni l'autre n'est un réglage.
    $spoofed = route('room.settings.update', ['room' => $room, '_method' => 'PATCH']);

    LobbyWrites::send($this, 'POST', $spoofed, $room, [
        '_method' => 'PATCH',
        '_token' => Str::random(40),
        'roundsCount' => Bounds::MIN_ROUNDS_COUNT,
    ], $host)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors();

    expect(Room::query()->findOrFail($room->id)->rounds_count)->toBe(Bounds::MIN_ROUNDS_COUNT);

    // Une clé de réglage dans la seule chaîne de requête n'est pas postée :
    // ni écrite, ni validée.
    $query = route('room.settings.update', ['room' => $room, 'roundsCount' => Bounds::MAX_ROUNDS_COUNT, 'advanced' => 'oui']);

    LobbyWrites::send($this, 'PATCH', $query, $room, [], $host)
        ->assertSessionHasNoErrors();

    expect(Room::query()->findOrFail($room->id)->rounds_count)->toBe(Bounds::MIN_ROUNDS_COUNT);
});
