<?php

use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\GuessMatchKind;
use App\ValueObjects\Answers\MatchResult;
use Tests\Support\Answers\MatchFixtures;

/*
|--------------------------------------------------------------------------
| Règle du sous-titre — spec 70 § 4.1, § 4.2, D23 du 23/09, lot L70-3
|--------------------------------------------------------------------------
|
| La partie APRÈS le premier séparateur d'un titre est une clé dérivée,
| soumise à la même règle de collision que le préfixe : acceptée pour le
| film de la manche sauf si un autre film `published` porte la même forme,
| sous quelque nature que ce soit, mesurée sur le catalogue publié entier et
| à l'instant de réception.
|
*/

it('accepte un sous-titre non ambigu', function (): void {
    $towers = MatchFixtures::movie('The Lord of the Rings: The Two Towers', [
        'en' => 'The Lord of the Rings: The Two Towers',
        'fr' => 'Le Seigneur des Anneaux : Les Deux Tours',
    ]);
    // Un second épisode publié rend le préfixe de la saga ambigu ; les
    // sous-titres, eux, désignent chacun un seul film.
    MatchFixtures::movie('The Lord of the Rings: The Fellowship of the Ring', [
        'en' => 'The Lord of the Rings: The Fellowship of the Ring',
        'fr' => 'Le Seigneur des Anneaux : La Communauté de l\'Anneau',
    ]);

    $round = MatchFixtures::roundOn($towers);

    // Dans les deux langues activées, quelle que soit la langue du joueur.
    foreach (['The Two Towers' => 'two towers', 'les deux tours' => 'deux tours'] as $typed => $subtitle) {
        expect(MatchFixtures::key($towers, $subtitle)->key_kind)->toBe(AnswerKeyKind::Subtitle)
            ->and(MatchFixtures::judge($round, $typed))->toEqual(new MatchResult(
                accepted: true,
                submittedNormalized: $subtitle,
                answerKeyId: MatchFixtures::key($towers, $subtitle)->id,
                answerKeyNormalized: $subtitle,
                matchKind: GuessMatchKind::Subtitle,
                editDistance: 0,
                prefixWasAmbiguous: false,
            ));
    }

    // Un sous-titre non ambigu est candidat à la tolérance, comme un préfixe.
    $typo = MatchFixtures::judge($round, 'the two tower');

    expect($typo->accepted)->toBeTrue()
        ->and($typo->matchKind)->toBe(GuessMatchKind::Subtitle)
        ->and($typo->editDistance)->toBe(1);

    // Le préfixe partagé de la saga, lui, reste refusé dans la même manche.
    expect(MatchFixtures::judge($round, 'Le Seigneur des Anneaux')->accepted)->toBeFalse();

    // Le découpage se fait au PREMIER séparateur, et à lui seul : la partie
    // après le second n'est pas une clé.
    $empire = MatchFixtures::movie('Star Wars : Épisode V - L\'Empire contre-attaque');
    $empireRound = MatchFixtures::roundOn($empire);

    expect(MatchFixtures::judge($empireRound, 'Épisode V - L\'Empire contre-attaque')->matchKind)
        ->toBe(GuessMatchKind::Subtitle)
        ->and(MatchFixtures::judge($empireRound, 'L\'Empire contre-attaque')->accepted)->toBeFalse();
});

it('refuse un sous-titre porté par un autre film publié', function (): void {
    // Un sous-titre générique partagé par deux films publiés : la règle de
    // collision le neutralise d'elle-même (§ 4.2).
    $killBill = MatchFixtures::movie('Kill Bill : Volume 1');
    $nightfall = MatchFixtures::movie('Nightfall Diaries: Volume 1');

    expect(MatchFixtures::key($killBill, 'volume 1')->key_kind)->toBe(AnswerKeyKind::Subtitle)
        ->and(MatchFixtures::key($nightfall, 'volume 1')->key_kind)->toBe(AnswerKeyKind::Subtitle);

    foreach ([$killBill, $nightfall] as $target) {
        $round = MatchFixtures::roundOn($target);

        // Refusé avec le corps d'un refus franc, et sa faute de frappe aussi :
        // un sous-titre ambigu n'est jamais candidat à la tolérance.
        expect(MatchFixtures::judge($round, 'Volume 1'))->toEqual(MatchResult::rejected('volume 1'))
            ->and(MatchFixtures::judge($round, 'Volum 1'))->toEqual(MatchResult::rejected('volum 1'));
    }

    // Sous QUELQUE NATURE que ce soit : un film publié dont le titre entier
    // égale le sous-titre le rend refusable pour la saga…
    $towers = MatchFixtures::movie('The Lord of the Rings: The Two Towers');
    $documentary = MatchFixtures::movie('Two Towers');

    expect(MatchFixtures::judge(MatchFixtures::roundOn($towers), 'The Two Towers'))
        ->toEqual(MatchResult::rejected('two towers'));

    // … tandis que ce titre complet reste accepté pour son propre film, avec
    // l'homonymie publiée journalisée.
    $documentaryResult = MatchFixtures::judge(MatchFixtures::roundOn($documentary), 'Two Towers');

    expect($documentaryResult->accepted)->toBeTrue()
        ->and($documentaryResult->matchKind)->toBe(GuessMatchKind::Title)
        ->and($documentaryResult->prefixWasAmbiguous)->toBeTrue();

    // Témoin : un brouillon qui porte le même sous-titre ne pèse pas — seul le
    // catalogue PUBLIÉ compte.
    $ember = MatchFixtures::movie('Ember Road: Second Dawn');
    MatchFixtures::movie('Frost Line: Second Dawn', availability: ContentAvailability::Draft);

    expect(MatchFixtures::judge(MatchFixtures::roundOn($ember), 'Second Dawn')->matchKind)
        ->toBe(GuessMatchKind::Subtitle);
});
