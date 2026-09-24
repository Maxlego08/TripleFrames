<?php

use App\Support\Answers\AnswerRules;
use App\Support\Catalog\AnswerKeyNormalizer;

/*
|--------------------------------------------------------------------------
| Barème de tolérance et distance — spec 70 § 5.8 et § 6.3, lot L70-1
|--------------------------------------------------------------------------
|
| La tolérance est une constante de version (`AnswerRules`), jamais un
| réglage d'hôte : la règle d'acceptation est une propriété de l'instance,
| identique pour tous les salons (§ 1.2). La longueur qui la fixe est celle,
| compacte, de la clé visée, jamais celle de la saisie.
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
