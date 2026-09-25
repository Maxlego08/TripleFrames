<?php

use App\Enums\GuessMatchKind;
use App\Support\Answers\AnswerRules;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\ValueObjects\Answers\MatchResult;
use Tests\Support\Answers\MatchFixtures;

/*
|--------------------------------------------------------------------------
| Barème de tolérance et distance — spec 70 § 5.8 et § 6.3, lots L70-1, L70-3
|--------------------------------------------------------------------------
|
| La tolérance est une constante de version (`AnswerRules`), jamais un
| réglage d'hôte : la règle d'acceptation est une propriété de l'instance,
| identique pour tous les salons (§ 1.2). La longueur qui la fixe est celle,
| compacte, de la clé visée, jamais celle de la saisie. Une suite de
| chiffres différente n'est jamais tolérée (§ 6.3).
|
*/

it('garde stricts les titres de quatre caractères compacts ou moins', function (): void {
    // « Up », « Ran », « It », « Heat » : aucune faute admise.
    foreach (['up', 'ran', 'it', 'heat'] as $key) {
        expect(AnswerRules::tolerance(strlen(AnswerKeyNormalizer::compact($key))))->toBe(0, $key);
    }

    expect(AnswerRules::tolerance(0))->toBe(0)
        ->and(AnswerRules::tolerance(4))->toBe(0)
        // Au-delà, le barème v1 : 5 à 8 → 1 ; 9 à 15 → 2 ; 16 et plus → 3.
        ->and(AnswerRules::tolerance(5))->toBe(1)
        ->and(AnswerRules::tolerance(8))->toBe(1)
        ->and(AnswerRules::tolerance(9))->toBe(2)
        ->and(AnswerRules::tolerance(15))->toBe(2)
        ->and(AnswerRules::tolerance(16))->toBe(AnswerRules::MAX_TOLERANCE)
        ->and(AnswerRules::tolerance(AnswerKeyNormalizer::MAX_NORMALIZED_LENGTH))->toBe(AnswerRules::MAX_TOLERANCE);

    // La longueur est COMPACTE : « wall e » compte cinq caractères, pas six.
    expect(AnswerRules::tolerance(strlen(AnswerKeyNormalizer::compact(AnswerKeyNormalizer::normalize('WALL·E')))))->toBe(1)
        // « It » reste strict : une seule lettre fausse dépasse le barème.
        ->and(AnswerKeyNormalizer::distance('it', 'id'))->toBeGreaterThan(AnswerRules::tolerance(strlen('it')));
});

it('mesure la distance aussi sur la forme compacte', function (): void {
    // Levenshtein brut : 1. Forme compacte : 0.
    expect(levenshtein('walle', 'wall e'))->toBe(1)
        ->and(AnswerKeyNormalizer::distance('walle', 'wall e'))->toBe(0)
        ->and(AnswerKeyNormalizer::distance('alien 3', 'alien3'))->toBe(0)
        ->and(AnswerKeyNormalizer::distance('star wars', 'starwars'))->toBe(0)
        // Une saisie tapée « WALLE » retrouve la clé de « WALL·E ».
        ->and(AnswerKeyNormalizer::distance(
            AnswerKeyNormalizer::normalize('WALLE'),
            AnswerKeyNormalizer::normalize('WALL·E'),
        ))->toBe(0);

    // Le minimum des deux, jamais la seule forme compacte : une espace
    // déplacée ne coûte rien, une lettre fausse coûte toujours.
    expect(AnswerKeyNormalizer::distance('seigneur des anneaux les deux tour', 'seigneur des anneaux les deux tours'))->toBe(1)
        ->and(AnswerKeyNormalizer::distance('rocky 4', 'rocky 5'))->toBe(1)
        ->and(AnswerKeyNormalizer::distance('alien', 'aliens'))->toBe(1)
        ->and(AnswerKeyNormalizer::distance('abc', 'abc'))->toBe(0)
        // Symétrique.
        ->and(AnswerKeyNormalizer::distance('wall e', 'walle'))->toBe(AnswerKeyNormalizer::distance('walle', 'wall e'));
});

it('accepte une faute de frappe sous le barème', function (): void {
    $towers = MatchFixtures::movie('The Lord of the Rings: The Two Towers', [
        'en' => 'The Lord of the Rings: The Two Towers',
        'fr' => 'Le Seigneur des Anneaux : Les Deux Tours',
    ]);

    // Une lettre oubliée sur une clé de plus de quinze caractères compacts.
    expect(MatchFixtures::judge(MatchFixtures::roundOn($towers), 'le seigneur des anneaux les deux tour'))
        ->toEqual(new MatchResult(
            accepted: true,
            submittedNormalized: 'seigneur des anneaux les deux tour',
            answerKeyId: MatchFixtures::key($towers, 'seigneur des anneaux les deux tours')->id,
            answerKeyNormalized: 'seigneur des anneaux les deux tours',
            matchKind: GuessMatchKind::Title,
            editDistance: 1,
            prefixWasAmbiguous: false,
        ));

    // Le barème suit la longueur compacte de la CLÉ : deux fautes admises
    // sur onze caractères, trois refusées.
    $ratatouille = MatchFixtures::movie('Ratatouille');
    $round = MatchFixtures::roundOn($ratatouille);
    $tolerance = AnswerRules::tolerance(strlen(AnswerKeyNormalizer::compact('ratatouille')));

    $twoTypos = MatchFixtures::judge($round, 'Ratatuile');

    expect(AnswerKeyNormalizer::distance('ratatuile', 'ratatouille'))->toBe($tolerance)
        ->and($twoTypos->accepted)->toBeTrue()
        ->and($twoTypos->editDistance)->toBe($tolerance)
        ->and(AnswerKeyNormalizer::distance('ratauile', 'ratatouille'))->toBeGreaterThan($tolerance)
        ->and(MatchFixtures::judge($round, 'Ratauile'))->toEqual(MatchResult::rejected('ratauile'));

    // La forme compacte absorbe l'espace de « WALL·E » : distance 0, mais par
    // tolérance, la saisie n'étant égale à aucune clé.
    $walle = MatchFixtures::movie('WALL·E');
    $compact = MatchFixtures::judge(MatchFixtures::roundOn($walle), 'Walle');

    expect($compact->accepted)->toBeTrue()
        ->and($compact->answerKeyNormalized)->toBe('wall e')
        ->and($compact->submittedNormalized)->toBe('walle')
        ->and($compact->editDistance)->toBe(0);

    // Les titres courts restent stricts, et une saisie longue n'achète aucune
    // tolérance : c'est la longueur de la clé qui compte.
    $heat = MatchFixtures::movie('Heat');
    $up = MatchFixtures::movie('Up');

    expect(MatchFixtures::judge(MatchFixtures::roundOn($heat), 'Heal')->accepted)->toBeFalse()
        ->and(MatchFixtures::judge(MatchFixtures::roundOn($heat), 'Heat')->accepted)->toBeTrue()
        // Cinq caractères saisis vaudraient une faute ; la clé en a quatre.
        ->and(MatchFixtures::judge(MatchFixtures::roundOn($heat), 'Heats')->accepted)->toBeFalse()
        ->and(MatchFixtures::judge(MatchFixtures::roundOn($up), 'Upp')->accepted)->toBeFalse();
});

it('refuse une suite de chiffres différente', function (): void {
    // Chaque couple est à distance compacte 1, sous le barème de la clé :
    // seule la règle des chiffres stricts les refuse.
    $cases = [
        ['Shrek', 'Shrek 2'],
        ['Rocky V', 'Rocky IV'],
        ['Rocky V', 'rocky 4'],
        ['Toy Story 3', 'Toy Story 2'],
        ['Le Seigneur des Anneaux', 'seigneur des anneaux 2'],
    ];

    $movies = [];

    foreach ($cases as [$title, $typed]) {
        $key = AnswerKeyNormalizer::normalize($title);
        $submitted = AnswerKeyNormalizer::normalize($typed);

        expect(AnswerKeyNormalizer::distance($submitted, $key))
            ->toBeLessThanOrEqual(AnswerRules::tolerance(strlen(AnswerKeyNormalizer::compact($key))), $typed)
            ->and(AnswerKeyNormalizer::digits($submitted))->not->toBe(AnswerKeyNormalizer::digits($key));

        $movies[$title] ??= MatchFixtures::movie($title);

        expect(MatchFixtures::judge(MatchFixtures::roundOn($movies[$title]), $typed))
            ->toEqual(MatchResult::rejected($submitted));
    }

    // « alien 3 » dans la manche « Aliens » (§ 4.4) : la lecture O ne trouve
    // pas « Alien³ », dont la forme est `alien3`, et la seule clé de la cible
    // n'a pas les mêmes chiffres.
    MatchFixtures::movie('Alien³');
    $aliens = MatchFixtures::movie('Aliens');

    expect(MatchFixtures::judge(MatchFixtures::roundOn($aliens), 'alien 3'))
        ->toEqual(MatchResult::rejected('alien 3'));

    // Témoin : aux mêmes chiffres, la même faute est tolérée.
    $sameDigits = MatchFixtures::judge(MatchFixtures::roundOn($movies['Rocky V']), 'Roky 5');

    expect($sameDigits->accepted)->toBeTrue()
        ->and($sameDigits->answerKeyNormalized)->toBe('rocky 5')
        ->and($sameDigits->editDistance)->toBe(1);
});
