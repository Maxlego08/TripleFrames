<?php

use App\Models\Game;
use App\Support\Draw\DrawContext;
use App\Support\Draw\SeededPrf;

/*
|--------------------------------------------------------------------------
| Graine et PRF à contextes nommés — spec 30 § 5, lot L30-4, contrat C3
|--------------------------------------------------------------------------
|
| L'algorithme est NORMATIF (E10-40) : ses sorties sont figées ici par les dix
| vecteurs de référence du § 5.4 (deux ajoutés par D44 du 01/10), calculés sur la graine
| `000102…1e1f`. Une implémentation qui en diverge est fausse, même si elle
| est « aussi aléatoire » : le tirage matérialisé, les leurres et l'ordre du
| QCM de 70 en dépendent tous.
|
| Le rejet de la réduction est prouvé deux fois : statistiquement (χ² sur
| 60 000 graines déterministes, bornes minuscules), et mécaniquement, sur une
| borne où un mot sur quatre est rejeté — seul cas où un `mod` nu et un rejet
| rendent des valeurs différentes à coup sûr. L'oracle du second recalcule
| les mots du bloc 0 en clair, sans passer par la classe testée.
|
*/

/** La graine des vecteurs de référence (§ 5.4). */
function seededPrfReferenceSeed(): string
{
    return '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
}

/**
 * Les huit mots de 32 bits du bloc 0 d'un contexte, calculés sans la classe
 * testée.
 *
 * @return list<int>
 */
function seededPrfBlockZeroWords(string $seed, DrawContext $context): array
{
    $words = unpack('N8', hash_hmac('sha256', $context->value.'#0', $seed, true));

    expect($words)->toBeArray();

    return array_values(array_map(intval(...), (array) $words));
}

it('les vecteurs de référence sont figés', function () {
    $prf = new SeededPrf(seededPrfReferenceSeed());

    // Les cinq vecteurs du contrat C3, les trois figés par la spec 30, puis les
    // deux contextes d'affinité des leurres (D44 du 01/10).
    expect($prf->permutation(DrawContext::movies(), 10))->toBe([0, 6, 3, 8, 4, 1, 7, 2, 9, 5])
        ->and($prf->index(DrawContext::variant(1, 1), 3))->toBe(2)
        ->and($prf->index(DrawContext::substitute(2, 3), 2))->toBe(0)
        ->and($prf->permutation(DrawContext::decoys(4), 5))->toBe([4, 3, 1, 2, 0])
        ->and($prf->index(DrawContext::workMember(1), 2))->toBe(1)
        ->and($prf->permutation(DrawContext::decoysOriginal(4), 5))->toBe([0, 1, 3, 4, 2])
        ->and($prf->permutation(DrawContext::qcmOrder(1, 'K7Q2M9XR4T6W'), 4))->toBe([2, 3, 0, 1])
        ->and($prf->permutation(DrawContext::qcmOrder(1, 'P3N8D5HB2C9F'), 4))->toBe([1, 0, 3, 2])
        ->and($prf->permutation(DrawContext::decoysAffinity(4), 5))->toBe([2, 3, 1, 0, 4])
        ->and($prf->permutation(DrawContext::decoysAffinityOriginal(4), 5))->toBe([4, 1, 0, 2, 3]);

    // Fonction pure : un second appel repart du bloc 0 de son contexte.
    expect($prf->permutation(DrawContext::movies(), 10))->toBe([0, 6, 3, 8, 4, 1, 7, 2, 9, 5])
        ->and($prf->index(DrawContext::variant(1, 1), 3))->toBe(2);

    // `forGame()` lit `game.draw_seed` tel que stocké, et rien d'autre.
    $game = new Game;
    $game->draw_seed = seededPrfReferenceSeed();

    expect(SeededPrf::forGame($game)->permutation(DrawContext::movies(), 10))->toBe([0, 6, 3, 8, 4, 1, 7, 2, 9, 5])
        ->and(SeededPrf::forGame($game)->index(DrawContext::workMember(1), 2))->toBe(1);
});

it('la réduction par rejet est sans biais', function () {
    $draws = 60_000;
    $counts = [];

    for ($k = 0; $k < $draws; $k++) {
        $key = implode(',', (new SeededPrf(hash('sha256', 'seed'.$k)))->permutation(DrawContext::movies(), 3));
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    // Les 3! permutations sont toutes atteintes, et seulement elles.
    ksort($counts);

    expect(array_keys($counts))->toBe(['0,1,2', '0,2,1', '1,0,2', '1,2,0', '2,0,1', '2,1,0']);

    $expected = $draws / 6;
    $chiSquare = array_sum(array_map(
        static fn (int $observed): float => ($observed - $expected) ** 2 / $expected,
        $counts,
    ));

    // 5 degrés de liberté, p = 0,001.
    expect($chiSquare)->toBeLessThan(20.52);

    // Le rejet lui-même : pour `bound = 3 × 2²⁹`, `2³² mod bound = 2³⁰`, donc
    // `limit = 3 × 2³⁰` et un mot sur quatre est rejeté. Une graine dont le
    // premier mot tombe au-delà de `limit` doit rendre le SECOND mot réduit —
    // un `mod` nu rendrait le premier.
    $bound = 3 << 29;
    $limit = (1 << 32) - ((1 << 32) % $bound);
    $witness = null;

    for ($k = 0; $k < 1_000 && $witness === null; $k++) {
        $seed = hash('sha256', 'seed'.$k);
        [$first, $second] = seededPrfBlockZeroWords($seed, DrawContext::movies());

        if ($first >= $limit && $second < $limit) {
            $witness = [$seed, $first, $second];
        }
    }

    expect($witness)->not->toBeNull();

    [$seed, $first, $second] = $witness;

    expect((new SeededPrf($seed))->index(DrawContext::movies(), $bound))
        ->toBe($second % $bound)
        ->not->toBe($first % $bound);

    // Une borne qui divise 2³² ne rejette rien : le premier mot, réduit.
    $reference = seededPrfReferenceSeed();
    [$firstWord] = seededPrfBlockZeroWords($reference, DrawContext::movies());

    expect((new SeededPrf($reference))->index(DrawContext::movies(), 1 << 31))->toBe($firstWord % (1 << 31));

    // Domaine de la réduction : `index` sur `[1, 2³¹]` ; `permutation` refuse
    // `count < 0` et `count > 2³¹` (borne héritée de la réduction, sans
    // garantie d'usage : `range()` ne tient pas 2³¹ entrées) — une
    // permutation de l'ensemble vide est la liste vide.
    $prf = new SeededPrf($reference);

    expect($prf->index(DrawContext::movies(), 1))->toBe(0)
        ->and($prf->permutation(DrawContext::movies(), 0))->toBe([])
        ->and($prf->permutation(DrawContext::movies(), 1))->toBe([0])
        ->and(fn () => $prf->index(DrawContext::movies(), 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $prf->index(DrawContext::movies(), (1 << 31) + 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $prf->permutation(DrawContext::movies(), -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $prf->permutation(DrawContext::movies(), (1 << 31) + 1))->toThrow(InvalidArgumentException::class);
});

it("une graine qui n'est pas 64 caractères hexadécimaux est refusée", function () {
    $valid = seededPrfReferenceSeed();

    $invalid = [
        'vide' => '',
        '63 caractères' => substr($valid, 1),
        '65 caractères' => $valid.'0',
        'hexadécimal majuscule' => strtoupper($valid),
        'caractère non hexadécimal' => 'g'.substr($valid, 1),
        'saut de ligne final' => $valid."\n",
        'espace en tête' => ' '.substr($valid, 1),
        '32 octets bruts' => (string) hex2bin($valid),
    ];

    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        foreach ($invalid as $label => $seed) {
            $thrown = null;

            try {
                new SeededPrf($seed);
            } catch (InvalidArgumentException $exception) {
                $thrown = $exception;
            }

            expect($thrown)->toBeInstanceOf(InvalidArgumentException::class, "graine « {$label} » acceptée");

            // La graine n'apparaît ni dans le message ni dans les arguments
            // de la trace : `#[\SensitiveParameter]` la masque.
            $frame = collect($thrown?->getTrace() ?? [])->first(
                static fn (array $frame): bool => ($frame['class'] ?? null) === SeededPrf::class && $frame['function'] === '__construct',
            );

            // Comparé à la graine REFUSÉE (chaque forme), et à la graine de
            // référence (dont dérivent les formes 65 caractères, majuscules et
            // saut de ligne).
            expect($seed === '' || ! str_contains((string) $thrown?->getMessage(), $seed))->toBeTrue("graine « {$label} » citée dans le message")
                ->and($thrown?->getMessage())->not->toContain(strtolower($valid))
                ->and($frame)->toBeArray()
                ->and($frame['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class, "graine « {$label} » visible dans la trace");
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
    }

    // La graine est une propriété privée, masquée des traces.
    $parameter = (new ReflectionMethod(SeededPrf::class, '__construct'))->getParameters()[0];

    expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1)
        ->and((new ReflectionProperty(SeededPrf::class, 'seed'))->isPrivate())->toBeTrue();

    // La forme stockée est acceptée.
    expect((new SeededPrf($valid))->index(DrawContext::movies(), 2))->toBeInt();
});

it('generateSeed produit 64 caractères hexadécimaux', function () {
    expect(SeededPrf::SEED_BYTES)->toBe(32);

    $seeds = [];

    for ($index = 0; $index < 200; $index++) {
        $seeds[] = SeededPrf::generateSeed();
    }

    foreach ($seeds as $seed) {
        expect($seed)->toMatch('/\A[0-9a-f]{64}\z/')
            ->and(strlen($seed))->toBe(SeededPrf::SEED_BYTES * 2)
            ->and((new SeededPrf($seed))->permutation(DrawContext::movies(), 3))->toHaveCount(3);
    }

    // CSPRNG : 256 bits, aucune collision attendue.
    expect(array_unique($seeds))->toHaveCount(count($seeds));
});

it('les neuf contextes du registre produisent neuf chaînes distinctes', function () {
    $contexts = [
        DrawContext::movies()->value,
        DrawContext::workMember(3)->value,
        DrawContext::variant(3, 2)->value,
        DrawContext::substitute(3, 2)->value,
        DrawContext::decoys(3)->value,
        DrawContext::decoysOriginal(3)->value,
        DrawContext::decoysAffinity(3)->value,
        DrawContext::decoysAffinityOriginal(3)->value,
        DrawContext::qcmOrder(3, 'K7Q2M9XR4T6W')->value,
    ];

    expect($contexts)->toBe([
        'draw:movies',
        'draw:work-member:3',
        'draw:variant:3:2',
        'draw:substitute:3:2',
        'draw:decoys:3',
        'draw:decoys:3:original',
        'draw:decoys:3:affinity',
        'draw:decoys:3:affinity-original',
        'draw:qcm:3:K7Q2M9XR4T6W',
    ])->and(array_unique($contexts))->toHaveCount(9);

    // Registre FERMÉ : constructeur privé, exactement neuf fabriques, et le
    // contexte `tiebreak` retiré (contradiction n° 66) — aucune égalité de
    // score n'est tranchée par la graine.
    $class = new ReflectionClass(DrawContext::class);
    $factories = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        array_filter($class->getMethods(ReflectionMethod::IS_PUBLIC), static fn (ReflectionMethod $method): bool => ! $method->isConstructor()),
    );
    sort($factories);

    expect($class->isFinal())->toBeTrue()
        ->and($class->isReadOnly())->toBeTrue()
        ->and($class->getConstructor()?->isPrivate())->toBeTrue()
        ->and($factories)->toBe(['decoys', 'decoysAffinity', 'decoysAffinityOriginal', 'decoysOriginal', 'movies', 'qcmOrder', 'substitute', 'variant', 'workMember'])
        ->and(array_filter($factories, static fn (string $name): bool => str_contains(strtolower($name), 'tiebreak')))->toBe([]);

    // La grammaire est injective sur les arguments : chaque couple distinct
    // nomme un flux distinct (`variant(1, 12)` n'est pas `variant(11, 2)`).
    $all = [DrawContext::movies()->value];

    foreach (range(1, 13) as $sequence) {
        $all[] = DrawContext::workMember($sequence)->value;
        $all[] = DrawContext::decoys($sequence)->value;
        $all[] = DrawContext::decoysOriginal($sequence)->value;
        $all[] = DrawContext::decoysAffinity($sequence)->value;
        $all[] = DrawContext::decoysAffinityOriginal($sequence)->value;
        $all[] = DrawContext::qcmOrder($sequence, 'K7Q2M9XR4T6W')->value;
        $all[] = DrawContext::qcmOrder($sequence, 'P3N8D5HB2C9F')->value;

        foreach (range(1, 12) as $tier) {
            $all[] = DrawContext::variant($sequence, $tier)->value;
            $all[] = DrawContext::substitute($sequence, $tier)->value;
        }
    }

    expect(array_unique($all))->toHaveCount(count($all));

    // Neuf contextes, neuf flux : la même graine rend des blocs distincts.
    $blocks = array_map(
        static fn (string $context): string => hash_hmac('sha256', $context.'#0', seededPrfReferenceSeed()),
        $contexts,
    );

    expect(array_unique($blocks))->toHaveCount(9);

    // Arguments gardés, jamais écrêtés.
    expect(fn () => DrawContext::workMember(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DrawContext::variant(0, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DrawContext::variant(1, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DrawContext::substitute(1, -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DrawContext::decoys(-3))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DrawContext::decoysOriginal(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DrawContext::decoysAffinity(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DrawContext::decoysAffinityOriginal(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DrawContext::qcmOrder(0, 'K7Q2M9XR4T6W'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DrawContext::qcmOrder(1, ''))->toThrow(InvalidArgumentException::class);
});
