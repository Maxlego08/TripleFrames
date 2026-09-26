<?php

use App\Models\Room;
use App\Support\Room\RoomCode;
use App\Support\Room\SeatPublicId;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\I18n\FrontSource;

/*
|--------------------------------------------------------------------------
| Code de salon et identifiant public de siège — spec 50 § 6.3 (lot L50-3a)
|--------------------------------------------------------------------------
|
| `RoomCode` est le seul générateur du `room_code`, `SeatPublicId` celui de
| `player.public_id` ; les fabriques en sont lectrices. La saisie est
| tolérante (casse, espaces, tirets), le contrôle strict ; le miroir client
| `lib/game/room-code.ts` rend les mêmes formes et les mêmes verdicts, prouvé
| par le jeu partagé `tests/Fixtures/room/room-codes.json`, écrit à la main.
|
| L50-3b complète ce fichier (attribution et recyclage par la création,
| résolution et 404 par la route `room.show`).
|
*/

/** Chemin du jeu partagé, relatif à la racine du dépôt. */
function roomCodeCasesPath(): string
{
    return 'tests/Fixtures/room/room-codes.json';
}

/**
 * Le jeu partagé, lu STRICTEMENT : une liste de `{ input, normalized,
 * wellFormed }`, ni clé en plus ni clé en moins, sans doublon de saisie. Lu,
 * jamais écrit.
 *
 * @return list<array{input: string, normalized: string, wellFormed: bool}>
 */
function roomCodeCases(): array
{
    $decoded = json_decode((string) file_get_contents(base_path(roomCodeCasesPath())), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
        throw new RuntimeException(roomCodeCasesPath().' : une liste non vide est attendue.');
    }

    $cases = [];

    foreach ($decoded as $index => $case) {
        if (! is_array($case)
            || array_keys($case) !== ['input', 'normalized', 'wellFormed']
            || ! is_string($case['input'])
            || ! is_string($case['normalized'])
            || ! is_bool($case['wellFormed'])) {
            throw new RuntimeException(sprintf('%s : cas n° %d mal formé.', roomCodeCasesPath(), $index));
        }

        $cases[] = ['input' => $case['input'], 'normalized' => $case['normalized'], 'wellFormed' => $case['wellFormed']];
    }

    $inputs = array_column($cases, 'input');

    if (count(array_unique($inputs)) !== count($inputs)) {
        throw new RuntimeException(roomCodeCasesPath().' : saisie en double.');
    }

    return $cases;
}

/**
 * Les requêtes SQL exécutées pendant `$callback`.
 *
 * @return list<string>
 */
function roomCodeQueries(Closure $callback): array
{
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $callback();

    return $queries;
}

it("tire six caractères dans l'alphabet non ambigu", function (): void {
    // L'alphabet : 32 signes distincts, ni I, ni O, ni 0, ni 1.
    expect(RoomCode::LENGTH)->toBe(6)
        ->and(strlen(RoomCode::ALPHABET))->toBe(32)
        ->and(count(array_unique(str_split(RoomCode::ALPHABET))))->toBe(32)
        ->and(RoomCode::ALPHABET)->toMatch('/^[A-Z2-9]+$/');

    foreach (['I', 'O', '0', '1'] as $ambiguous) {
        expect(RoomCode::ALPHABET)->not->toContain($ambiguous);
    }

    // Chaque code : six signes de l'alphabet, déjà canonique, bien formé.
    $signs = [];

    for ($draw = 0; $draw < 2000; $draw++) {
        $code = RoomCode::generate();

        expect(strlen($code))->toBe(RoomCode::LENGTH)
            ->and(strspn($code, RoomCode::ALPHABET))->toBe(RoomCode::LENGTH)
            ->and(RoomCode::normalize($code))->toBe($code)
            ->and(RoomCode::isWellFormed($code))->toBeTrue();

        foreach (str_split($code) as $sign) {
            $signs[$sign] = true;
        }
    }

    // Tout l'alphabet est tiré, bornes comprises : 12 000 signes laissent un
    // signe donné de côté avec une probabilité de (31/32)^12000, environ
    // 10^-165 — un tirage hors de [0, 31] ou borné à 30 échoue ici.
    expect(array_keys($signs))->toEqualCanonicalizing(str_split(RoomCode::ALPHABET));

    // Un tirage libre coûte une seule lecture de clé, sur le créneau actif.
    $queries = roomCodeQueries(fn () => RoomCode::generate());

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('room_code_active')
        ->and(strtolower($queries[0]))->toStartWith('select');

    // La fabrique est lectrice du générateur : un salon fabriqué sans être
    // persisté coûte exactement la lecture de clé du générateur, sur le
    // créneau actif. Un tirage local à la fabrique n'en ferait aucune, et
    // pourrait heurter `room_active_code_uq`.
    $room = null;
    $queries = roomCodeQueries(function () use (&$room): void {
        $room = Room::factory()->make();
    });

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('room_code_active')
        ->and(strtolower($queries[0]))->toStartWith('select')
        ->and($room)->toBeInstanceOf(Room::class)
        ->and(RoomCode::isWellFormed($room->room_code))->toBeTrue()
        ->and(RoomCode::normalize($room->room_code))->toBe($room->room_code)
        ->and($room->room_code_active)->toBe($room->room_code);
});

it('retrouve un salon quelle que soit la casse ou les espaces saisis', function (): void {
    $room = Room::factory()->create();
    $code = $room->room_code;
    $lower = strtolower($code);
    [$head, $tail] = str_split($code, intdiv(RoomCode::LENGTH, 2));

    $variants = [
        $code,
        $lower,
        ucfirst($lower),
        " {$code} ",
        strtolower($head).' '.$tail,
        "{$head}-{$tail}",
        strtolower("{$head} - {$tail}"),
        implode(' ', str_split($lower)),
        "\t{$lower}\n",
    ];

    foreach ($variants as $variant) {
        expect(Room::normalizeCode($variant))->toBe($code, json_encode($variant, JSON_THROW_ON_ERROR))
            ->and((new Room)->resolveRouteBinding($variant)?->id)->toBe($room->id, json_encode($variant, JSON_THROW_ON_ERROR));
    }

    // Même tolérance pour un salon archivé : le vieux lien, saisi en
    // minuscules, mène encore au salon « expiré ».
    $archived = Room::factory()->archived()->create();

    expect((new Room)->resolveRouteBinding(strtolower($archived->room_code))?->id)->toBe($archived->id);

    // De bout en bout : le motif tolérant de `{room}` laisse passer au
    // routeur la casse, les espaces et les tirets, et la liaison retrouve le
    // salon. Route de sonde déclarée après `routes/game.php`, donc sous le
    // même `Route::pattern()`.
    Route::middleware('web')->get('/_test/room-code/r/{room}', fn (Room $room): string => $room->room_code);

    foreach ([$lower, "{$head}-{$tail}", strtolower($head).' '.$tail] as $variant) {
        $this->get('/_test/room-code/r/'.rawurlencode($variant))
            ->assertOk()
            ->assertSeeText($code);
    }

    // Le motif est posé en tête de `routes/game.php`, donc sur toute route
    // de salon déjà déclarée.
    expect(Route::getPatterns())->toHaveKey('room', RoomCode::ROUTE_PATTERN);

    $routes = app('router')->getRoutes();

    foreach (['room.state', 'room.settings.update', 'room.settings.preset'] as $name) {
        expect($routes->getByName($name)?->wheres)->toHaveKey('room', RoomCode::ROUTE_PATTERN);
    }

    // Contrôle négatif : une saisie mal formée ne retrouve rien, et sans
    // aucune requête.
    foreach ([substr($code, 1), $code.$head, 'new'] as $malformed) {
        $resolved = null;
        $queries = roomCodeQueries(function () use ($malformed, &$resolved): void {
            $resolved = (new Room)->resolveRouteBinding($malformed);
        });

        expect($resolved)->toBeNull()
            ->and($queries)->toBe([]);
    }
});

it('le miroir client isWellFormedRoomCode accepte et refuse exactement les codes que RoomCode::isWellFormed() accepte et refuse', function (): void {
    $cases = roomCodeCases();

    foreach ($cases as $case) {
        $label = json_encode($case['input'], JSON_THROW_ON_ERROR);

        expect(RoomCode::normalize($case['input']))->toBe($case['normalized'], $label)
            ->and(RoomCode::isWellFormed($case['input']))->toBe($case['wellFormed'], $label)
            // La forme canonique est un point fixe, au même verdict.
            ->and(RoomCode::normalize($case['normalized']))->toBe($case['normalized'], $label)
            ->and(RoomCode::isWellFormed($case['normalized']))->toBe($case['wellFormed'], $label);
    }

    // Le jeu couvre ce que § 6.3 exige qu'il couvre : sans cela, Vitest
    // comparerait le miroir sur un sous-ensemble et laisserait passer une
    // divergence sur la catégorie absente.
    $covers = static fn (Closure $predicate): bool => array_filter($cases, $predicate) !== [];
    $inAlphabet = static fn (string $normalized): bool => $normalized !== ''
        && strspn($normalized, RoomCode::ALPHABET) === strlen($normalized);

    $coverage = [
        'accepté' => $covers(fn (array $case): bool => $case['wellFormed']),
        'refusé' => $covers(fn (array $case): bool => ! $case['wellFormed']),
        'minuscules' => $covers(fn (array $case): bool => $case['wellFormed'] && preg_match('/[a-z]/', $case['input']) === 1),
        'espaces' => $covers(fn (array $case): bool => $case['wellFormed'] && str_contains($case['input'], ' ')),
        'tirets' => $covers(fn (array $case): bool => $case['wellFormed'] && str_contains($case['input'], '-')),
        'blancs de contrôle' => $covers(fn (array $case): bool => $case['wellFormed'] && preg_match('/[\t\n\x0B\f\r]/', $case['input']) === 1),
        'I' => $covers(fn (array $case): bool => ! $case['wellFormed'] && str_contains($case['normalized'], 'I')),
        'O' => $covers(fn (array $case): bool => ! $case['wellFormed'] && str_contains($case['normalized'], 'O')),
        '0' => $covers(fn (array $case): bool => ! $case['wellFormed'] && str_contains($case['normalized'], '0')),
        '1' => $covers(fn (array $case): bool => ! $case['wellFormed'] && str_contains($case['normalized'], '1')),
        'trop court' => $covers(fn (array $case): bool => $inAlphabet($case['normalized']) && strlen($case['normalized']) < RoomCode::LENGTH),
        'trop long' => $covers(fn (array $case): bool => $inAlphabet($case['normalized']) && strlen($case['normalized']) > RoomCode::LENGTH),
        'vide' => $covers(fn (array $case): bool => $case['normalized'] === ''),
        'non ASCII' => $covers(fn (array $case): bool => preg_match('/[^\x00-\x7F]/', $case['input']) === 1),
    ];

    expect(array_keys(array_filter($coverage, static fn (bool $covered): bool => ! $covered)))->toBe([]);
});

it('le miroir client room-code.ts reflète ALPHABET et LENGTH de RoomCode', function (): void {
    $source = FrontSource::withoutComments((string) file_get_contents(resource_path('js/lib/game/room-code.ts')));

    expect(preg_match('/export\s+const\s+ROOM_CODE\s*=\s*\{(?<body>[^}]*)\}\s*as\s+const\s*;/', $source, $match))->toBe(1);

    preg_match_all("/(?<key>\w+)\s*:\s*(?:'(?<string>[^']*)'|(?<number>\d+))\s*,?/", $match['body'], $pairs, PREG_SET_ORDER);

    $mirror = [];

    foreach ($pairs as $pair) {
        $mirror[$pair['key']] = ($pair['number'] ?? '') !== '' ? (int) $pair['number'] : $pair['string'];
    }

    expect($mirror)->toBe([
        'alphabet' => RoomCode::ALPHABET,
        'length' => RoomCode::LENGTH,
    ]);

    // Les deux fonctions ne lisent que `ROOM_CODE` : hors de l'objet, ni
    // l'alphabet ni aucun chiffre n'est réécrit.
    $rest = str_replace($match[0], '', $source);

    expect(substr_count($source, RoomCode::ALPHABET))->toBe(1)
        ->and(preg_match('/\d/', $rest))->toBe(0)
        ->and($rest)->toMatch('/export\s+function\s+normalizeRoomCode\s*\(\s*code\s*:\s*string\s*\)\s*:\s*string\b/')
        ->and($rest)->toMatch('/export\s+function\s+isWellFormedRoomCode\s*\(\s*code\s*:\s*string\s*\)\s*:\s*boolean\b/');
});

it('lève après MAX_ATTEMPTS candidats tous portés par un salon actif', function (): void {
    $active = Room::factory()->create();
    $draws = 0;

    $generate = function () use ($active, &$draws): string {
        return RoomCode::generate(function () use ($active, &$draws): string {
            $draws++;

            return $active->room_code;
        });
    };

    expect($generate)->toThrow(RuntimeException::class)
        ->and($draws)->toBe(RoomCode::MAX_ATTEMPTS);

    // Un candidat injecté doit être canonique : la source de test ne
    // contourne jamais la forme.
    expect(fn () => RoomCode::generate(fn (): string => strtolower($active->room_code)))
        ->toThrow(LogicException::class);
});

it('tire un public_id de douze signes base32, sans I, L, O ni U', function (): void {
    expect(SeatPublicId::LENGTH)->toBe(12)
        ->and(strlen(SeatPublicId::ALPHABET))->toBe(32)
        ->and(count(array_unique(str_split(SeatPublicId::ALPHABET))))->toBe(32)
        ->and(SeatPublicId::ALPHABET)->toMatch('/^[0-9A-Z]+$/');

    foreach (['I', 'L', 'O', 'U'] as $excluded) {
        expect(SeatPublicId::ALPHABET)->not->toContain($excluded);
    }

    $signs = [];

    for ($draw = 0; $draw < 1000; $draw++) {
        $id = SeatPublicId::generate();

        expect(strlen($id))->toBe(SeatPublicId::LENGTH)
            ->and(strspn($id, SeatPublicId::ALPHABET))->toBe(SeatPublicId::LENGTH);

        foreach (str_split($id) as $sign) {
            $signs[$sign] = true;
        }
    }

    expect(array_keys($signs))->toEqualCanonicalizing(str_split(SeatPublicId::ALPHABET));

    // Aucune requête : l'unicité est tenue par l'index de la colonne.
    expect(roomCodeQueries(fn () => SeatPublicId::generate()))->toBe([]);
});
