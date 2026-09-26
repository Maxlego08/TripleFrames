<?php

use App\Http\Middleware\EnsureActiveSeat;
use App\Support\Realtime\GameWire;
use Tests\Support\I18n\FrontSource;

/*
|--------------------------------------------------------------------------
| Version du fil de jeu — spec 60 § 11.1 et § 20, contrat C7 § 2.5
|--------------------------------------------------------------------------
|
| `GameWire::VERSION` porte le champ `v` de chaque enveloppe ; le client le
| lit par `GAME_WIRE_VERSION` (`lib/game/wire.ts`) et le type par le littéral
| `v` de `WireEnvelope` (`types/game-wire.ts`). Les trois ne font qu'un.
|
*/

it('GAME_WIRE_VERSION côté TS égale GameWire::VERSION', function (): void {
    $wire = FrontSource::withoutComments((string) file_get_contents(resource_path('js/lib/game/wire.ts')));

    expect(preg_match_all('/export\s+const\s+GAME_WIRE_VERSION\s*=\s*(?<version>\d+)\s*;/', $wire, $constants))->toBe(1)
        ->and((int) $constants['version'][0])->toBe(GameWire::VERSION);

    // Le type de l'enveloppe fige le même littéral : un client compilé contre
    // une autre version ne typerait pas les charges reçues.
    $types = FrontSource::withoutComments((string) file_get_contents(resource_path('js/types/game-wire.ts')));

    expect(preg_match('/export\s+interface\s+WireEnvelope\s*\{(?<body>[^}]*)\}/', $types, $envelope))->toBe(1)
        ->and(preg_match('/\bv\s*:\s*(?<version>\d+)\s*;/', $envelope['body'], $literal))->toBe(1)
        ->and((int) $literal['version'])->toBe(GameWire::VERSION);

    // Et l'enveloppe serveur porte bien cette constante.
    expect(GameWire::envelope(null, now()->toImmutable())['v'])->toBe(GameWire::VERSION);
});

it("le jeton d'onglet part côté TS sous l'en-tête que lit EnsureActiveSeat", function (): void {
    // Ajout du lot L60-9 (60 § 12.7) : le magasin présente le jeton d'onglet
    // sur toute requête ; un en-tête mal nommé ferait supplanter l'onglet
    // par lui-même à chaque rechargement partiel.
    $store = FrontSource::withoutComments((string) file_get_contents(resource_path('js/lib/game/store.ts')));

    expect(preg_match("/export\s+const\s+SEAT_TOKEN_HEADER\s*=\s*'(?<header>[^']+)'\s*;/", $store, $constant))->toBe(1)
        ->and($constant['header'])->toBe(EnsureActiveSeat::HEADER);
});
