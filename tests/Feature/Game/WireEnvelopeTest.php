<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\GameWire;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Enveloppe, instants et noms du fil — spec 60 § 10.4 et § 11.1 (ajout)
|--------------------------------------------------------------------------
|
| Ajout, hors des intitulés de 60 § 20 : les briques de L60-2 que les
| événements (L60-3) et le paquet de resynchronisation (L60-4) composeront.
| `EventPayloadTest` et `ChannelAuthorizationTest` les éprouveront à travers
| les charges réelles ; ici, leur forme et leur dérivation seules.
|
*/

/** Un modèle en mémoire, à l'identifiant choisi, sans écriture en base. */
function wireEnvelopeModel(string $class, int $id): Game|Room
{
    /** @var Game|Room $model */
    $model = new $class;
    $model->forceFill(['id' => $id]);

    return $model;
}

it("l'enveloppe porte v, serverNow en ISO-8601 UTC à la milliseconde et gameRef, nul hors partie", function (): void {
    // Hors UTC, microsecondes comprises : UTC, tronqué à la milliseconde.
    $now = CarbonImmutable::parse('2026-09-23 16:05:13.004999', 'Europe/Paris');

    expect(WireTime::iso($now))->toBe('2026-09-23T14:05:13.004Z')
        ->and(WireTime::iso($now))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
        // L'instant reçu n'est pas modifié.
        ->and($now->getTimezone()->getName())->toBe('Europe/Paris');

    expect(GameWire::envelope(null, $now))->toBe([
        'v' => GameWire::VERSION,
        'serverNow' => '2026-09-23T14:05:13.004Z',
        'gameRef' => null,
    ]);

    $game = wireEnvelopeModel(Game::class, 41);
    $envelope = GameWire::envelope($game, $now);

    expect(array_keys($envelope))->toBe(['v', 'serverNow', 'gameRef'])
        ->and($envelope['gameRef'])->toBe(GameRef::for($game))
        ->and($envelope['gameRef'])->toMatch('/^[0-9a-f]{16}$/');
});

it("gameRef et roomKey sont des HMAC d'APP_KEY à contextes séparés, sans identifiant en clair", function (): void {
    $key = (string) config('app.key');
    $game = wireEnvelopeModel(Game::class, 7);
    $room = wireEnvelopeModel(Room::class, 7);

    // La formule du contrat C7 § 2.1, recalculée ici.
    expect(GameRef::for($game))->toBe(substr(hash_hmac('sha256', 'tf:game-ref:7', $key), 0, 16))
        ->and(ChannelNames::roomKey($room))->toBe(substr(hash_hmac('sha256', 'tf:room-channel:7', $key), 0, 32))
        ->and(ChannelNames::roomKey($room))->toMatch('/^[0-9a-f]{32}$/');

    // Même entier, deux contextes : aucune valeur partagée.
    expect(str_starts_with(ChannelNames::roomKey($room), GameRef::for($game)))->toBeFalse();

    // Stable pour un même objet, distincte d'un objet à l'autre.
    expect(GameRef::for($game))->toBe(GameRef::for(wireEnvelopeModel(Game::class, 7)))
        ->and(GameRef::for(wireEnvelopeModel(Game::class, 8)))->not->toBe(GameRef::for($game))
        ->and(ChannelNames::roomKey(wireEnvelopeModel(Room::class, 8)))->not->toBe(ChannelNames::roomKey($room));

    // Noms Echo des canaux : clé HMAC pour le salon, `public_id` pour le siège.
    $seat = (new Player)->forceFill(['id' => 7, 'public_id' => '01J9ZSEATPUBLICID0000000000']);

    expect(ChannelNames::room($room))->toBe('room.'.ChannelNames::roomKey($room))
        ->and(ChannelNames::seat($seat))->toBe('seat.01J9ZSEATPUBLICID0000000000');

    // Une autre clé d'application donne d'autres valeurs : rien de dérivable
    // de l'identifiant seul.
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

    expect(GameRef::for($game))->not->toBe(substr(hash_hmac('sha256', 'tf:game-ref:7', $key), 0, 16))
        ->and(ChannelNames::roomKey($room))->not->toBe(substr(hash_hmac('sha256', 'tf:room-channel:7', $key), 0, 32));
});
