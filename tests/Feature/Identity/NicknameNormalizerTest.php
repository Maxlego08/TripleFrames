<?php

use App\Rules\ValidNickname;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Identity\NicknameNormalizer;

/*
|--------------------------------------------------------------------------
| La forme normalisée d'un pseudo — spec 40 § 5.5, contrat C5 (L40-3)
|--------------------------------------------------------------------------
|
| `NicknameNormalizer::normalize()` = `AnswerKeyNormalizer::fold()` de `70`,
| puis suppression de tout ce qui n'est pas `[a-z0-9]`. Seule écrivaine de
| `player.nickname_normalized`, qui porte l'unicité par salon : elle doit
| confondre ce que l'œil confond, et rien de plus.
|
*/

it('replie casse, accents, espaces, tirets et soulignés en une seule forme normalisée', function () {
    $groups = [
        'jeanluc' => ['Jean-Luc', 'jean luc', 'JEAN_LUC', 'Jean Luc', 'jeanluc', 'Jéan-Lüc', 'J-e-a-n L_u_c'],
        'zoe' => ['Zoé', 'ZOÉ', 'zoe', 'Zoe', 'Z o é', 'zo-É'],
        'strasse' => ['Straße', 'STRASSE', 'Stras_se'],
        'oedipe' => ['Œdipe', 'oedipe', 'Oe-dipe'],
        'lodz' => ['Łódź', 'Lodz', 'LO DZ'],
    ];

    foreach ($groups as $normalized => $nicknames) {
        foreach ($nicknames as $nickname) {
            expect(NicknameNormalizer::normalize($nickname))->toBe($normalized, $nickname);
        }
    }

    // Pure, déterministe et idempotente ; sa sortie n'a que `[a-z0-9]`.
    foreach (array_merge(...array_values($groups)) as $nickname) {
        $once = NicknameNormalizer::normalize($nickname);

        expect(NicknameNormalizer::normalize($nickname))->toBe($once)
            ->and(NicknameNormalizer::normalize($once))->toBe($once)
            ->and($once)->toMatch('/^[a-z0-9]+$/');
    }

    // Bâtie sur `fold()` de `70`, jamais sur un normaliseur à elle.
    expect(NicknameNormalizer::normalize('Jean-Luc Ærø'))
        ->toBe(preg_replace('/[^a-z0-9]+/', '', AnswerKeyNormalizer::fold('Jean-Luc Ærø')));
});

it('garde articles et chiffres dans la forme normalisée', function () {
    // Trois joueurs distincts : « Le Boss », « the_boss » et « Boss ».
    expect(NicknameNormalizer::normalize('Le Boss'))->toBe('leboss')
        ->and(NicknameNormalizer::normalize('the_boss'))->toBe('theboss')
        ->and(NicknameNormalizer::normalize('Boss'))->toBe('boss')
        ->and(NicknameNormalizer::normalize('Un Deux'))->toBe('undeux')
        ->and(NicknameNormalizer::normalize('The The'))->toBe('thethe');

    // Aucune conversion de chiffres, romains ou arabes, ni de zéro de tête.
    expect(NicknameNormalizer::normalize('Rocky II'))->toBe('rockyii')
        ->and(NicknameNormalizer::normalize('Rocky 2'))->toBe('rocky2')
        ->and(NicknameNormalizer::normalize('Agent 007'))->toBe('agent007')
        ->and(NicknameNormalizer::normalize('XIV'))->toBe('xiv');

    // Là où le normaliseur des réponses les confondrait : c'est pourquoi le
    // pseudo a le sien (§ 5.5).
    expect(AnswerKeyNormalizer::normalize('Le Boss'))->toBe(AnswerKeyNormalizer::normalize('Boss'))
        ->and(AnswerKeyNormalizer::normalize('Rocky II'))->toBe(AnswerKeyNormalizer::normalize('Rocky 2'));
});

it('rend une forme ASCII non vide pour chaque lettre latine admise', function () {
    // Les lettres que la règle admet, lues dans son motif — jamais recopiées.
    $admitted = [];

    for ($codePoint = 0x0000; $codePoint <= 0x2FFF; $codePoint++) {
        if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
            continue;
        }

        $character = mb_chr($codePoint, 'UTF-8');

        if (preg_match('/^\p{L}$/u', $character) === 1
            && preg_match(ValidNickname::ALLOWED_PATTERN, $character) === 1) {
            $admitted[] = $codePoint;
        }
    }

    // Le motif dit ce que dit D26 : latin de base, `ª` et `º`, U+00C0–U+00FF
    // sauf `×` et `÷`, U+0100–U+017F — et rien d'autre.
    $d26 = [
        ...range(0x0041, 0x005A),
        ...range(0x0061, 0x007A),
        0x00AA,
        0x00BA,
        ...array_values(array_diff(range(0x00C0, 0x00FF), [0x00D7, 0x00F7])),
        ...range(0x0100, 0x017F),
    ];
    sort($d26);

    expect($admitted)->toBe($d26);

    foreach ($admitted as $codePoint) {
        $letter = mb_chr($codePoint, 'UTF-8');

        expect(NicknameNormalizer::normalize($letter))
            ->toMatch('/^[a-z]+$/', sprintf('U+%04X (%s) ne se replie en aucune lettre ASCII.', $codePoint, $letter));
    }

    // Les chiffres ASCII restent eux-mêmes.
    foreach (range(0, 9) as $digit) {
        expect(NicknameNormalizer::normalize((string) $digit))->toBe((string) $digit);
    }
});
