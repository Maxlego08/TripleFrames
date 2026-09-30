<?php

use App\Enums\AnswerKeyKind;
use App\Models\AnswerKey;
use App\Support\Catalog\AnswerKeyNormalizer;
use Illuminate\Support\Str;
use Tests\Support\Answers\AnswerRuleFixtures;

/*
|--------------------------------------------------------------------------
| Normaliseur v1 — spec 70 § 5, contrat C12, lot L70-1
|--------------------------------------------------------------------------
|
| Une seule fonction pour les clés et pour les saisies (10 A5), agnostique de
| la langue, repliée en PHP pour que SQLite et MySQL rendent le même verdict
| (10 A6). Les sorties de la version courante sont figées par
| `tests/Fixtures/answers/normalizer-v{VERSION}.php` : une mise à jour de
| `voku/portable-ascii` qui en change une fait échouer ce fichier, et le
| remède est une nouvelle version de règle, jamais une réécriture des
| attentes (§ 12).
|
*/

it('les sorties figées de la version courante ne bougent pas', function (): void {
    $fixtures = AnswerRuleFixtures::normalizer();

    foreach ($fixtures['normalize'] as [$input, $expected]) {
        expect(AnswerKeyNormalizer::normalize($input))->toBe($expected, sprintf('normalize(%s)', $input));
    }

    foreach ($fixtures['fold'] as [$input, $expected]) {
        expect(AnswerKeyNormalizer::fold($input))->toBe($expected, sprintf('fold(%s)', json_encode($input, JSON_UNESCAPED_UNICODE)));
    }

    // Garde d'intégrité du fichier v1, jamais réécrit (§ 5.6) : les seize
    // paires du contrat C12 et les ajouts mesurés du § 5.6 y figurent à
    // l'identique. `toContain` compare par identité : une paire retirée ou
    // modifiée échoue ici, quelle que soit la version courante.
    $required = [
        // Contrat C12.
        ['ガラスの果樹園', 'garasunoguo shu yuan'],
        ['千と千尋の神隠し', 'qian toqian xun noshen yin shi'],
        ['기생충', 'gisaengcung'],
        ['Ｔｏｋｙｏ', 'tokyo'],
        ['Rocky Ⅳ', 'rocky 4'],
        ['Rocky V', 'rocky 5'],
        ['Saw X', 'saw 10'],
        ['X-Men', 'x men'],
        ['I, Robot', 'i robot'],
        ['Final Fantasy VII', 'final fantasy 7'],
        ['Alien³', 'alien3'],
        ['Se7en', 'se7en'],
        ['Le Fabuleux Destin d\'Amélie Poulain', 'fabuleux destin d amelie poulain'],
        ['WALL·E', 'wall e'],
        ['Straße', 'strasse'],
        ['L\'Été', 'ete'],
        // Spec 70 § 5.6.
        ['Malcolm X', 'malcolm 10'],
        ['xXx', '30'],
        ['The The Thing', 'the thing'],
        ['Star Wars : Épisode V - L\'Empire contre-attaque', 'star wars episode v l empire contre attaque'],
    ];

    $requiredFold = [
        ['José', 'jose'],
        ['Le Chat', 'le chat'],
        ['Jean-Luc_77', 'jean-luc_77'],
        ['ß-Boy', 'ss-boy'],
        ['  A  b  ', 'a b'],
    ];

    $v1 = AnswerRuleFixtures::frozenNormalizer(1);

    foreach ($required as $pair) {
        expect($v1['normalize'])->toContain($pair);
    }

    foreach ($requiredFold as $pair) {
        expect($v1['fold'])->toContain($pair);
    }

    // Toute version courante continue d'exercer les entrées du contrat, avec
    // ses propres sorties : une version suivante ne les abandonne pas.
    $inputs = array_column($fixtures['normalize'], 0);
    $foldInputs = array_column($fixtures['fold'], 0);

    foreach ($required as [$input]) {
        expect($inputs)->toContain($input);
    }

    foreach ($requiredFold as [$input]) {
        expect($foldInputs)->toContain($input);
    }
});

it('translittère les écritures non latines et la pleine chasse sans ext-intl', function (): void {
    // Les sorties attendues sont celles de la table pur PHP de
    // `voku/portable-ascii`, pas celles d'ICU : `strict = false` n'emprunte
    // jamais `transliterator_transliterate()`, que l'extension soit chargée
    // ou non (§ 5.2).
    expect(AnswerKeyNormalizer::normalize('ガラスの果樹園'))->toBe('garasunoguo shu yuan')
        ->and(AnswerKeyNormalizer::normalize('千と千尋の神隠し'))->toBe('qian toqian xun noshen yin shi')
        ->and(AnswerKeyNormalizer::normalize('기생충'))->toBe('gisaengcung')
        ->and(AnswerKeyNormalizer::normalize('Брат'))->toBe('brat')
        // Pleine chasse et formes de compatibilité.
        ->and(AnswerKeyNormalizer::normalize('Ｔｏｋｙｏ'))->toBe('tokyo')
        ->and(AnswerKeyNormalizer::normalize('Rocky Ⅳ'))->toBe('rocky 4');

    // Un titre non latin ne normalise plus en chaîne vide : la saisie dans
    // l'écriture d'origine s'apparie de façon déterministe à `title_original`
    // (§ 4.1, A-58), la clé et la saisie passant par la même fonction.
    $key = AnswerKey::factory()->titleOriginal('千と千尋の神隠し')->make();

    expect($key->normalized)->toBe(AnswerKeyNormalizer::normalize('千と千尋の神隠し'))
        ->and($key->normalized)->not->toBe('');

    // Le latin déjà couvert par `Str::ascii()` garde ses sorties.
    foreach (['Straße' => 'strasse', 'Œdipe roi' => 'oedipe roi', 'Æon Flux' => 'aeon flux', 'Łódź' => 'lodz', 'Ōkami' => 'okami', 'Amélie' => 'amelie'] as $input => $expected) {
        expect(AnswerKeyNormalizer::normalize($input))->toBe($expected)
            ->and(AnswerKeyNormalizer::normalize($input))->toBe(AnswerKeyNormalizer::normalize(Str::ascii($input)));
    }

    // Sortie toujours dans l'alphabet `[a-z0-9 ]`, espaces simples, sans bord.
    foreach (['ガラスの果樹園', '千と千尋の神隠し', '기생충', 'Ｔｏｋｙｏ', '🎬 Film ½', "Tab\tet\u{00A0}insécable"] as $input) {
        expect(AnswerKeyNormalizer::normalize($input))->toMatch('/^([a-z0-9]+( [a-z0-9]+)*)?$/');
    }
});

it('convertit les chiffres romains stricts de deux lettres et plus', function (): void {
    expect(AnswerKeyNormalizer::normalize('Final Fantasy VII'))->toBe('final fantasy 7')
        ->and(AnswerKeyNormalizer::normalize('Rocky IV'))->toBe('rocky 4')
        ->and(AnswerKeyNormalizer::normalize('XXXIX'))->toBe('39')
        // En toute position, pas seulement en dernier jeton.
        ->and(AnswerKeyNormalizer::normalize('Part II The Return'))->toBe('part 2 the return')
        ->and(AnswerKeyNormalizer::normalize('Star Wars Episode IV A New Hope'))->toBe('star wars episode 4 a new hope')
        // Effet symétrique assumé : « xXx » est un nombre romain valide.
        ->and(AnswerKeyNormalizer::normalize('xXx'))->toBe('30')
        // Notation non stricte : laissée telle quelle.
        ->and(AnswerKeyNormalizer::normalize('IIII'))->toBe('iiii')
        ->and(AnswerKeyNormalizer::normalize('VV'))->toBe('vv')
        ->and(AnswerKeyNormalizer::normalize('IIX'))->toBe('iix')
        // Au-delà de 39 : laissé tel quel.
        ->and(AnswerKeyNormalizer::normalize('XXXX'))->toBe('xxxx')
        // `m`, `d`, `c` et `l` ne sont jamais convertis.
        ->and(AnswerKeyNormalizer::normalize('XL'))->toBe('xl')
        ->and(AnswerKeyNormalizer::normalize('MCMLXXXIV'))->toBe('mcmlxxxiv')
        ->and(AnswerKeyNormalizer::normalize('Mix Live'))->toBe('mix live');

    // La saisie arabe et la clé romaine se rejoignent : la conversion frappe
    // les deux côtés à l'identique.
    expect(AnswerKeyNormalizer::normalize('final fantasy 7'))->toBe(AnswerKeyNormalizer::normalize('Final Fantasy VII'));
});

it('ne convertit un I, V ou X isolé qu\'en dernier jeton', function (): void {
    // Dernier jeton d'une chaîne d'au moins deux jetons : converti.
    expect(AnswerKeyNormalizer::normalize('Rocky V'))->toBe('rocky 5')
        ->and(AnswerKeyNormalizer::normalize('Saw X'))->toBe('saw 10')
        ->and(AnswerKeyNormalizer::normalize('Malcolm X'))->toBe('malcolm 10')
        ->and(AnswerKeyNormalizer::normalize('Henry I'))->toBe('henry 1');

    // Ailleurs : épargné.
    expect(AnswerKeyNormalizer::normalize('X-Men'))->toBe('x men')
        ->and(AnswerKeyNormalizer::normalize('I, Robot'))->toBe('i robot')
        ->and(AnswerKeyNormalizer::normalize('V pour Vendetta'))->toBe('v pour vendetta')
        ->and(AnswerKeyNormalizer::normalize('Star Wars : Épisode V - L\'Empire contre-attaque'))
        ->toBe('star wars episode v l empire contre attaque');

    // Seul jeton de la chaîne : jamais converti.
    expect(AnswerKeyNormalizer::normalize('V'))->toBe('v')
        ->and(AnswerKeyNormalizer::normalize('X'))->toBe('x')
        ->and(AnswerKeyNormalizer::normalize('I'))->toBe('i');
});

it('ne retire qu\'un article de tête et jamais le dernier mot', function (): void {
    expect(AnswerKeyNormalizer::normalize('The Thing'))->toBe('thing')
        ->and(AnswerKeyNormalizer::normalize('Les Misérables'))->toBe('miserables')
        ->and(AnswerKeyNormalizer::normalize('L\'Été'))->toBe('ete')
        // Un seul article : le second reste.
        ->and(AnswerKeyNormalizer::normalize('The The Thing'))->toBe('the thing')
        // Jamais le dernier mot.
        ->and(AnswerKeyNormalizer::normalize('Le'))->toBe('le')
        ->and(AnswerKeyNormalizer::normalize('The'))->toBe('the')
        ->and(AnswerKeyNormalizer::normalize('A'))->toBe('a')
        // Jamais après un séparateur : la saisie tapée sans le deux-points
        // retrouve la clé.
        ->and(AnswerKeyNormalizer::normalize('Le Seigneur des Anneaux : Les Deux Tours'))->toBe('seigneur des anneaux les deux tours')
        ->and(AnswerKeyNormalizer::normalize('le seigneur des anneaux les deux tours'))->toBe('seigneur des anneaux les deux tours')
        // Les articles d'autres langues de catalogue ne sont pas retirés.
        ->and(AnswerKeyNormalizer::normalize('Die Hard'))->toBe('die hard')
        ->and(AnswerKeyNormalizer::normalize('El Laberinto del Fauno'))->toBe('el laberinto del fauno');

    // La liste fermée, union des locales activées, lue par l'empreinte.
    expect(AnswerKeyNormalizer::leadingArticles())
        ->toBe(['le', 'la', 'les', 'l', 'un', 'une', 'des', 'du', 'de', 'the', 'a', 'an']);
});

it('tronque symétriquement les clés et les saisies à 200 caractères', function (): void {
    $limit = AnswerKeyNormalizer::MAX_NORMALIZED_LENGTH;

    // La translittération allonge (`ß` → `ss`) au-delà de la colonne.
    $title = 'Straße '.str_repeat('ß', $limit);
    $key = AnswerKey::factory()->forText($title, AnswerKeyKind::Title, 'fr')->make();
    $typed = AnswerKeyNormalizer::normalize(Str::upper('strasse '.str_repeat('ss', $limit)));

    expect(strlen($key->normalized))->toBe($limit)
        ->and($typed)->toBe($key->normalized);

    // Une coupe qui tombe juste après un mot ne laisse pas d'espace finale :
    // le verdict reste le même sous PAD SPACE (MySQL) et en SQLite.
    $words = str_repeat('abcd ', (int) ceil($limit / 5) + 10);
    $normalized = AnswerKeyNormalizer::normalize($words);

    expect(strlen($normalized))->toBeLessThanOrEqual($limit)
        ->and($normalized)->not->toEndWith(' ')
        ->and($normalized)->toBe(AnswerKeyNormalizer::normalize(Str::upper($words)));

    // Une forme courte n'est jamais touchée.
    expect(AnswerKeyNormalizer::normalize('Alien'))->toBe('alien');
});

it('fold conserve articles, tirets et soulignés', function (): void {
    expect(AnswerKeyNormalizer::fold('Le Chat'))->toBe('le chat')
        ->and(AnswerKeyNormalizer::fold('Jean-Luc_77'))->toBe('jean-luc_77')
        ->and(AnswerKeyNormalizer::fold('ß-Boy'))->toBe('ss-boy')
        ->and(AnswerKeyNormalizer::fold('José'))->toBe('jose')
        ->and(AnswerKeyNormalizer::fold('  A  b  '))->toBe('a b');

    // Ni chiffres romains, ni article retiré, ni troncature.
    $long = str_repeat('ß', AnswerKeyNormalizer::MAX_NORMALIZED_LENGTH);

    expect(AnswerKeyNormalizer::fold('Rocky IV'))->toBe('rocky iv')
        ->and(AnswerKeyNormalizer::fold('The Thing'))->toBe('the thing')
        ->and(strlen(AnswerKeyNormalizer::fold($long)))->toBe(2 * AnswerKeyNormalizer::MAX_NORMALIZED_LENGTH);

    // Idempotente, contrairement à `normalize()`.
    foreach (['Le Chat', 'Jean-Luc_77', 'ß-Boy', '  A  b  ', 'Ｔｏｋｙｏ', '千と千尋の神隠し', "Tab\tNew\nLine"] as $input) {
        $folded = AnswerKeyNormalizer::fold($input);

        expect(AnswerKeyNormalizer::fold($folded))->toBe($folded);
    }
});

it('découpe le sous-titre au premier séparateur, titres seulement', function (): void {
    // Corps livré par L70-1 ; le branchement au projecteur est L70-2 (D23).
    expect(AnswerKeyNormalizer::prefixOf('The Lord of the Rings: The Two Towers'))->toBe('lord of the rings')
        ->and(AnswerKeyNormalizer::subtitleOf('The Lord of the Rings: The Two Towers'))->toBe('two towers')
        ->and(AnswerKeyNormalizer::subtitleOf('Le Seigneur des Anneaux : Les Deux Tours'))->toBe('deux tours')
        // Au premier séparateur, et à lui seul.
        ->and(AnswerKeyNormalizer::prefixOf('Star Wars : Épisode V - L\'Empire contre-attaque'))->toBe('star wars')
        ->and(AnswerKeyNormalizer::subtitleOf('Star Wars : Épisode V - L\'Empire contre-attaque'))->toBe('episode v l empire contre attaque')
        ->and(AnswerKeyNormalizer::subtitleOf('Kill Bill : Volume 1'))->toBe('volume 1')
        // Sans séparateur, trop court, ou égal au préfixe : aucune clé.
        ->and(AnswerKeyNormalizer::subtitleOf('Alien'))->toBeNull()
        ->and(AnswerKeyNormalizer::subtitleOf('Heat : Up'))->toBeNull()
        ->and(AnswerKeyNormalizer::subtitleOf('Northbound : Northbound'))->toBeNull()
        // Un séparateur en position 0 est ignoré.
        ->and(AnswerKeyNormalizer::subtitleOf(': Northbound'))->toBeNull()
        ->and(AnswerKeyNormalizer::subtitleOf(''))->toBeNull();
});

it('rend les chiffres comme une suite d\'entiers et la forme compacte sans espace', function (): void {
    expect(AnswerKeyNormalizer::digits('agent 007 skyfall 23'))->toBe([7, 23])
        ->and(AnswerKeyNormalizer::digits('agent 7 skyfall 23'))->toBe(AnswerKeyNormalizer::digits('agent 007 skyfall 23'))
        ->and(AnswerKeyNormalizer::digits('alien3'))->toBe([3])
        ->and(AnswerKeyNormalizer::digits('aliens'))->toBe([])
        ->and(AnswerKeyNormalizer::compact('wall e'))->toBe('walle')
        ->and(AnswerKeyNormalizer::compact('alien 3'))->toBe('alien3');
});
