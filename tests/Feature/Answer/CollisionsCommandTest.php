<?php

use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\Locale;
use App\Models\Alias;
use App\Models\Movie;
use App\Support\Answers\AnswerRules;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Catalog\AnswerKeyProjector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Answers\MatchFixtures;

/*
|--------------------------------------------------------------------------
| Rapport de collisions `answers:collisions` — spec 70 § 13.1, lot L70-11
|--------------------------------------------------------------------------
|
| Outil du porteur, en lecture seule, hors du chemin du curateur. Sur les
| clés des seuls films PUBLIÉS : (i) les formes exactes partagées par au
| moins deux films, l'une au moins de nature exacte, alias TMDB compris ;
| (ii) les couples de clés candidates à la tolérance (exactes ou dérivées
| non ambiguës), à chiffres identiques, à distance au plus la SOMME des deux
| tolérances — directs sous la plus grande, pivots au-delà. Une ligne par
| couple, sans aucune phrase.
|
*/

/**
 * Les lignes du rapport, lues par `--json`, sans aucune écriture en base.
 *
 * @return list<array<string, mixed>>
 */
function collisionReport(): array
{
    $statements = [];

    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    expect(Artisan::call('answers:collisions', ['--json' => true]))->toBe(0);

    // En lecture seule : aucune instruction autre qu'une lecture.
    expect($statements)->not->toBeEmpty();

    foreach ($statements as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }

    /** @var list<array<string, mixed>> $lines */
    $lines = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return $lines;
}

/**
 * La ligne attendue d'un couple, `A` étant la clé de plus petite forme.
 *
 * @return array<string, mixed>
 */
function collisionLine(Movie $movieA, string $normalizedA, Movie $movieB, string $normalizedB, int $distance): array
{
    return [
        'normalizedA' => $normalizedA,
        'movieA' => ['id' => $movieA->id, 'titleOriginal' => $movieA->title_original],
        'kindA' => MatchFixtures::key($movieA, $normalizedA)->key_kind->value,
        'normalizedB' => $normalizedB,
        'movieB' => ['id' => $movieB->id, 'titleOriginal' => $movieB->title_original],
        'kindB' => MatchFixtures::key($movieB, $normalizedB)->key_kind->value,
        'distance' => $distance,
    ];
}

it('liste les formes exactes partagées, alias TMDB compris, et les paires sous le seuil', function (): void {
    // Le risque du § 4.3 : un alias TMDB générique posé sur un seul épisode
    // valide cet épisode, alors que le préfixe identique de l'autre est refusé.
    $newHope = MatchFixtures::movie('Star Wars: A New Hope');
    Alias::factory()->tmdb()->forLocale(Locale::English)->accepting('Star Wars')->create(['movie_id' => $newHope->id]);
    (new AnswerKeyProjector)->project($newHope);
    $empire = MatchFixtures::movie('Star Wars: The Empire Strikes Back');
    $jedi = MatchFixtures::movie('Star Wars: Return of the Jedi');

    expect(MatchFixtures::key($newHope, 'star wars')->key_kind)->toBe(AnswerKeyKind::Alias)
        ->and(MatchFixtures::key($empire, 'star wars')->key_kind)->toBe(AnswerKeyKind::Prefix)
        ->and(MatchFixtures::key($jedi, 'star wars')->key_kind)->toBe(AnswerKeyKind::Prefix);

    // Deux homonymes légitimes (remake) : une forme exacte partagée.
    $thing = MatchFixtures::movie('The Thing');
    $thingRemake = MatchFixtures::movie('The Thing');

    // Sous le seuil : « alien » et « aliens », une lettre, chiffres identiques.
    $alien = MatchFixtures::movie('Alien');
    $aliens = MatchFixtures::movie('Aliens');

    // Chiffres différents : jamais une paire, à distance 1 pourtant.
    MatchFixtures::movie('Shrek');
    MatchFixtures::movie('Shrek 2');
    expect(AnswerKeyNormalizer::distance('shrek', 'shrek 2'))->toBe(1);

    // Deux titres proches d'un MÊME film ne forment jamais un couple : le
    // rapport compare des films distincts.
    MatchFixtures::movie('Ratatouille', ['en' => 'Ratatouille', 'fr' => 'Ratatouile']);
    expect(AnswerKeyNormalizer::distance('ratatouille', 'ratatouile'))->toBe(1);

    // Hors du catalogue publié : un brouillon et un film dépublié qui portent
    // les mêmes formes n'entrent dans aucune ligne.
    MatchFixtures::movie('Alien', availability: ContentAvailability::Draft);
    MatchFixtures::movie('The Thing', availability: ContentAvailability::Unpublished);

    // L'alias contre chacun des deux préfixes identiques ; jamais les deux
    // préfixes entre eux : deux clés dérivées identiques sont déjà refusées
    // toutes deux par la règle de collision, sans alias fautif à supprimer
    // ni homonyme à accepter.
    expect(collisionReport())->toBe([
        collisionLine($newHope, 'star wars', $empire, 'star wars', 0),
        collisionLine($newHope, 'star wars', $jedi, 'star wars', 0),
        collisionLine($thing, 'thing', $thingRemake, 'thing', 0),
        collisionLine($alien, 'alien', $aliens, 'aliens', 1),
    ]);

    // La sortie table reprend les noms de champs comme en-têtes, sans phrase.
    $this->artisan('answers:collisions')
        ->expectsTable(
            ['normalizedA', 'movieA.id', 'movieA.titleOriginal', 'kindA', 'normalizedB', 'movieB.id', 'movieB.titleOriginal', 'kindB', 'distance'],
            [
                ['star wars', (string) $newHope->id, 'Star Wars: A New Hope', 'alias', 'star wars', (string) $empire->id, 'Star Wars: The Empire Strikes Back', 'prefix', '0'],
                ['star wars', (string) $newHope->id, 'Star Wars: A New Hope', 'alias', 'star wars', (string) $jedi->id, 'Star Wars: Return of the Jedi', 'prefix', '0'],
                ['thing', (string) $thing->id, 'The Thing', MatchFixtures::key($thing, 'thing')->key_kind->value, 'thing', (string) $thingRemake->id, 'The Thing', MatchFixtures::key($thingRemake, 'thing')->key_kind->value, '0'],
                ['alien', (string) $alien->id, 'Alien', MatchFixtures::key($alien, 'alien')->key_kind->value, 'aliens', (string) $aliens->id, 'Aliens', MatchFixtures::key($aliens, 'aliens')->key_kind->value, '1'],
            ],
        )
        ->assertSuccessful();
});

it('liste les couples pivots à distance au plus la somme des deux tolérances', function (): void {
    // Barème v1 : 9 caractères compacts → 2 ; 5 à 8 → 1.
    expect(AnswerRules::tolerance(strlen('moonlight')))->toBe(2)
        ->and(AnswerRules::tolerance(strlen('moonlite')))->toBe(1)
        ->and(AnswerRules::tolerance(strlen('wanted')))->toBe(1);

    $moonlight = MatchFixtures::movie('Moonlight');
    $moonlite = MatchFixtures::movie('Moonlite');
    $moonrise = MatchFixtures::movie('Moonrise');
    $wanted = MatchFixtures::movie('Wanted');
    $wander = MatchFixtures::movie('Wander');
    $wonder = MatchFixtures::movie('Wonder');
    // Tolérances 3 et 2, cinq caractères compacts d'écart : la fenêtre de
    // longueur ne coupe aucun couple que la somme admet.
    $neverendingStory = MatchFixtures::movie('The Neverending Story');
    $neverending = MatchFixtures::movie('Neverending');
    // Une clé dérivée NON ambiguë est candidate à la tolérance : le préfixe
    // « mission », que seul ce film porte, forme un pivot avec « passion ».
    $missionImpossible = MatchFixtures::movie('Mission: Impossible');
    $passion = MatchFixtures::movie('Passion');
    // Une clé dérivée AMBIGUË ne l'est jamais (étape (d) du juge) : le
    // préfixe « alien », porté par deux épisodes, ne forme aucun couple avec
    // « aliens », ni direct ni pivot.
    $covenant = MatchFixtures::movie('Alien: Covenant');
    MatchFixtures::movie('Alien: Romulus');
    $aliens = MatchFixtures::movie('Aliens');

    expect(MatchFixtures::key($missionImpossible, 'mission'))
        ->key_kind->toBe(AnswerKeyKind::Prefix)
        ->is_ambiguous->toBeFalse()
        ->and(MatchFixtures::key($covenant, 'alien'))
        ->key_kind->toBe(AnswerKeyKind::Prefix)
        ->is_ambiguous->toBeTrue();

    $lines = collisionReport();

    expect($lines)->toBe([
        // Direct : distance 1, sous la plus grande des deux tolérances.
        collisionLine($wander, 'wander', $wonder, 'wonder', 1),
        // Pivots : au-delà de la plus grande tolérance, jusqu'à la somme.
        collisionLine($missionImpossible, 'mission', $passion, 'passion', 2),
        collisionLine($moonlite, 'moonlite', $moonrise, 'moonrise', 2),
        collisionLine($wander, 'wander', $wanted, 'wanted', 2),
        // Tolérances inégales (2 + 1) : la somme, ni deux fois la plus petite
        // ni deux fois la plus grande.
        collisionLine($moonlight, 'moonlight', $moonlite, 'moonlite', 3),
        collisionLine($neverending, 'neverending', $neverendingStory, 'neverending story', 5),
    ]);

    // Au-delà de la somme, aucune ligne : « moonlight » et « moonrise » (4 pour
    // 2 + 1), « wanted » et « wonder » (3 pour 1 + 1).
    expect(AnswerKeyNormalizer::distance('moonlight', 'moonrise'))->toBe(4)
        ->and(AnswerKeyNormalizer::distance('wanted', 'wonder'))->toBe(3);

    // Chaque pivot l'est vraiment : au-delà de la plus grande tolérance.
    foreach (array_slice($lines, 1) as $line) {
        $toleranceA = AnswerRules::tolerance(strlen(AnswerKeyNormalizer::compact((string) $line['normalizedA'])));
        $toleranceB = AnswerRules::tolerance(strlen(AnswerKeyNormalizer::compact((string) $line['normalizedB'])));

        expect($line['distance'])->toBeGreaterThan(max($toleranceA, $toleranceB))
            ->toBeLessThanOrEqual($toleranceA + $toleranceB);
    }

    // Le rapport dit ce que le juge fait : une chaîne à mi-chemin du pivot
    // couvre les deux films ; à côté du préfixe ambigu, elle ne couvre
    // qu'« Aliens ».
    expect(MatchFixtures::judge(MatchFixtures::roundOn($missionImpossible), 'massion')->accepted)->toBeTrue()
        ->and(MatchFixtures::judge(MatchFixtures::roundOn($passion), 'massion')->accepted)->toBeTrue()
        ->and(MatchFixtures::judge(MatchFixtures::roundOn($aliens), 'alienx')->accepted)->toBeTrue()
        ->and(MatchFixtures::judge(MatchFixtures::roundOn($covenant), 'alienx')->accepted)->toBeFalse();
});
