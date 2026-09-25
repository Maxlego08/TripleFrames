<?php

use App\Avatars\AvatarPresetCatalog;
use App\Enums\Locale;
use App\Rules\ValidNickname;
use App\Support\I18n\LocaleCookie;
use App\Support\Identity\NicknameBlocklist;
use App\Support\Identity\NicknameNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Tests\Support\Identity\NicknameFormRequest;

/*
|--------------------------------------------------------------------------
| La règle de pseudo — spec 40 § 5.2 à § 5.5 et § 5.9, contrat C5 (L40-3,
| L40-4)
|--------------------------------------------------------------------------
|
| Forme canonique (NFC, bords rognés, espaces intérieures réduites), puis la
| règle `ValidNickname`, qui rend UN message, le premier échec l'emportant :
| longueur, écriture, caractères, alphanumérique, longueur normalisée, liste
| noire. La liste noire elle-même est éprouvée par `NicknameBlocklistTest` ;
| l'unicité (étape 6) est de `50`.
|
| Tant que les FormRequest de `50` et `60` n'existent pas, la saisie traverse
| la vraie pile `web` par une route de ce fichier et par
| `NicknameFormRequest`, qui applique l'intégration du § 5.8 à la lettre :
| `TrimStrings` et `ConvertEmptyStringsToNull` jouent, `SetLocale` choisit la
| langue du message par le cookie `locale`. Les envois sont encodés en
| formulaire, non en JSON : une saisie qui n'est pas de l'UTF-8 valide doit
| pouvoir arriver jusqu'à la règle.
|
*/

beforeEach(function () {
    Route::middleware('web')->post(nicknameProbeUri(), function (NicknameFormRequest $request): JsonResponse {
        $nickname = (string) $request->validated('nickname');

        // Ce qu'écrirait l'action : la forme validée, et sa forme repliée.
        return response()->json([
            'nickname' => $nickname,
            'normalized' => NicknameNormalizer::normalize($nickname),
        ]);
    });
});

function nicknameProbeUri(): string
{
    return '/_test/nickname';
}

/**
 * Un envoi de siège réduit à son identité, avatar valide, dans une langue.
 */
function nicknameSubmit(mixed $nickname, Locale $locale = Locale::English): TestResponse
{
    return test()
        ->withUnencryptedCookie(LocaleCookie::NAME, $locale->value)
        ->post(
            nicknameProbeUri(),
            ['nickname' => $nickname, 'avatar' => AvatarPresetCatalog::keys()[0]],
            ['Accept' => 'application/json'],
        );
}

/**
 * Le message qu'une clé de la règle rend dans une langue, `:attribute`
 * résolu depuis `validation.attributes.nickname` comme le fait le validateur.
 */
function nicknameMessage(string $key, Locale $locale): string
{
    $message = __($key, [
        'attribute' => __('validation.attributes.nickname', [], $locale->value),
        'min' => NicknameNormalizer::MIN_LENGTH,
        'max' => NicknameNormalizer::MAX_LENGTH,
    ], $locale->value);

    // Ni clé brute, ni `:placeholder` laissé sans valeur.
    expect($message)->toBeString()->not->toBe($key)->not->toMatch('/:[A-Za-z]/');

    return (string) $message;
}

/**
 * Le texte rédigé au § 5.9, tel qu'un joueur le lit aux bornes par défaut.
 */
function nicknameSpecText(string $key, Locale $locale): string
{
    $texts = [
        ValidNickname::KEY_LENGTH => [
            'The nickname must be between 2 and 20 characters.',
            'Le pseudo doit compter entre 2 et 20 caractères.',
        ],
        ValidNickname::KEY_SCRIPT => [
            'The nickname may only use Latin letters (accents included), digits, spaces, "-" and "_".',
            'Le pseudo ne peut utiliser que l’alphabet latin, accents compris, des chiffres, des espaces, « - » et « _ ».',
        ],
        ValidNickname::KEY_CHARACTERS => [
            'This nickname contains a character that is not allowed: a symbol, an emoji or an invisible character.',
            'Ce pseudo contient un caractère non autorisé : symbole, émoji ou caractère invisible.',
        ],
        ValidNickname::KEY_ALNUM => [
            'The nickname must contain at least one letter or digit.',
            'Le pseudo doit contenir au moins une lettre ou un chiffre.',
        ],
        ValidNickname::KEY_NORMALIZED_LENGTH => [
            'This nickname is too long once its special letters are expanded (ß, æ, œ…). Please shorten it.',
            'Ce pseudo est trop long une fois ses lettres spéciales développées (ß, æ, œ…). Raccourcissez-le.',
        ],
        ValidNickname::KEY_BLOCKED => [
            'This nickname is not available. Please choose another one.',
            'Ce pseudo n’est pas disponible. Choisissez-en un autre.',
        ],
    ];

    return $texts[$key][$locale === Locale::French ? 1 : 0];
}

/**
 * Refus par UN seul message, le bon, sur le seul champ `nickname`, dans
 * chaque langue activée.
 */
function expectNicknameRefused(mixed $nickname, string $key): void
{
    foreach ([Locale::English, Locale::French] as $locale) {
        $response = nicknameSubmit($nickname, $locale);

        $response->assertUnprocessable();

        expect($response->json('errors'))->toBe(['nickname' => [nicknameMessage($key, $locale)]])
            ->and($response->json('errors.nickname.0'))->toBe(nicknameSpecText($key, $locale));
    }
}

/**
 * Refus par la règle nue, hors de toute préparation (ni `TrimStrings`, ni
 * `prepareNickname()`) : la règle valide ce qu'elle reçoit (§ 5.8).
 */
function expectBareNicknameRefused(string $nickname, string $key): void
{
    app()->setLocale(Locale::English->value);

    $bare = Validator::make(['nickname' => $nickname], ['nickname' => [new ValidNickname]]);

    expect($bare->errors()->get('nickname'))->toBe([nicknameMessage($key, Locale::English)]);
}

/**
 * Acceptation : la forme validée est la forme canonique attendue, et c'est
 * elle que l'action écrirait (§ 5.8).
 */
function expectNicknameAccepted(mixed $nickname, string $canonical, string $normalized): void
{
    $response = nicknameSubmit($nickname);

    $response->assertOk();

    expect($response->json('nickname'))->toBe($canonical)
        ->and($response->json('normalized'))->toBe($normalized);
}

it('accepte les pseudos latins de 2 à 20 caractères avec chiffres, espaces, tirets et soulignés', function (string $nickname, string $normalized) {
    // Accents et casse conservés dans la forme affichée.
    expectNicknameAccepted($nickname, $nickname, $normalized);

    expect(mb_strlen($nickname))->toBeGreaterThanOrEqual(NicknameNormalizer::MIN_LENGTH)
        ->toBeLessThanOrEqual(NicknameNormalizer::MAX_LENGTH);
})->with([
    'accent' => ['Zoé', 'zoe'],
    'deux lettres' => ['Jo', 'jo'],
    'deux chiffres' => ['42', '42'],
    'tiret' => ['Jean-Luc', 'jeanluc'],
    'espace' => ['jean luc', 'jeanluc'],
    'souligné' => ['JEAN_LUC', 'jeanluc'],
    'chiffres et espace' => ['Agent 007', 'agent007'],
    'chiffres et tirets' => ['R2-D2', 'r2d2'],
    'vingt lettres' => ['abcdefghijklmnopqrst', 'abcdefghijklmnopqrst'],
    'vingt lettres accentuées' => [str_repeat('é', 20), str_repeat('e', 20)],
    'Latin étendu-A' => ['Łukasz_Żółć', 'lukaszzolc'],
    'Latin-1 supplément' => ['Ærøskøbing', 'aeroskobing'],
    'tout mêlé' => ['Ÿves-Ŝ_42 Œil', 'yvess42oeil'],
]);

it('compose un accent décomposé avant de le valider', function () {
    // « é » décomposé (`e` + U+0301), tel que certains claviers mobiles
    // l'envoient : validé et affiché sous sa forme composée U+00E9.
    expectNicknameAccepted("Zoe\u{0301}", "Zo\u{00E9}", 'zoe');
    expectNicknameAccepted("Noe\u{0308}l A\u{030A}sa", "No\u{00EB}l \u{00C5}sa", 'noelasa');

    // La longueur se mesure APRÈS la composition : quarante points de code
    // saisis, vingt caractères affichés.
    expectNicknameAccepted(str_repeat("e\u{0301}", 20), str_repeat("\u{00E9}", 20), str_repeat('e', 20));

    // C'est la préparation de la FormRequest qui compose, pas la règle : sur
    // la saisie brute, la marque combinante serait refusée.
    $bare = Validator::make(['nickname' => "Zoe\u{0301}"], ['nickname' => [new ValidNickname]]);

    expect($bare->errors()->get('nickname'))->toBe([nicknameMessage(ValidNickname::KEY_CHARACTERS, Locale::English)]);
});

it('rogne les extrémités et réduit les espaces intérieures dans le pseudo affiché', function () {
    expectNicknameAccepted('  Le   Boss ', 'Le Boss', 'leboss');
    expectNicknameAccepted(' Zoé  DUPONT ', 'Zoé DUPONT', 'zoedupont');
    expectNicknameAccepted('Jean     -     Luc', 'Jean - Luc', 'jeanluc');

    // La longueur se mesure sur la forme affichée : trente-deux caractères
    // saisis, trois affichés.
    expectNicknameAccepted('a'.str_repeat(' ', 30).'b', 'a b', 'ab');

    // Hors requête HTTP, `canonical()` rogne seul les bords. Il ne réduit que
    // l'espace U+0020 : les autres blancs intérieurs restent à la règle, qui
    // les refuse.
    expect(NicknameNormalizer::canonical("\t Le   Boss \n"))->toBe('Le Boss')
        ->and(NicknameNormalizer::canonical("Le\u{00A0}\u{00A0}Boss"))->toBe("Le\u{00A0}\u{00A0}Boss")
        ->and(NicknameNormalizer::canonical("Le\t\tBoss"))->toBe("Le\t\tBoss");

    expectNicknameRefused("Le\u{00A0}\u{00A0}Boss", ValidNickname::KEY_CHARACTERS);

    // La règle nue, elle, ne rogne rien : un saut de ligne final, que le `$`
    // du motif (sans modificateur `D`) laisserait passer sur la chaîne
    // entière, est refusé comme tout autre caractère de contrôle.
    foreach (["Bob\n", "ab\n", "Le Boss\n"] as $untrimmed) {
        expectBareNicknameRefused($untrimmed, ValidNickname::KEY_CHARACTERS);
    }

    // Idempotente : la forme affichée est un point fixe.
    foreach (['  Le   Boss ', "Zoe\u{0301}", 'Jean     -     Luc', "\t Zoé \n"] as $raw) {
        $canonical = NicknameNormalizer::canonical($raw);

        expect(NicknameNormalizer::canonical($canonical))->toBe($canonical);
    }
});

it('refuse un pseudo de moins de 2 ou de plus de 20 caractères', function (string $nickname) {
    expectNicknameRefused($nickname, ValidNickname::KEY_LENGTH);

    // Au-delà de la garde, ou hors UTF-8, l'entrée n'est pas transformée
    // (I5.1) : aucune normalisation Unicode n'est payée sur une charge
    // arbitraire.
    if (strlen($nickname) > NicknameNormalizer::MAX_RAW_BYTES || ! mb_check_encoding($nickname, 'UTF-8')) {
        expect(NicknameNormalizer::canonical($nickname))->toBe($nickname);
    }
})->with([
    'une lettre' => ['A'],
    'une lettre de deux octets' => ['é'],
    'une lettre entre des espaces' => ['   A   '],
    'vingt et une lettres' => ['abcdefghijklmnopqrstu'],
    'vingt et une lettres accentuées' => [str_repeat('é', 21)],
    'la longueur avant les caractères' => ['🎬'],
    'la longueur avant l\'écriture' => [str_repeat('映', 21)],
    'au-delà de MAX_RAW_BYTES' => [str_repeat('a', 300)],
    // Au-delà de la garde, l'entrée n'est pas même composée : six cents
    // octets, refusés sur la longueur.
    'décomposé au-delà de MAX_RAW_BYTES' => [str_repeat("e\u{0301}", 200)],
    // De l'UTF-8 invalide que `mb_strlen` place hors des bornes (I5.1).
    'un octet UTF-8 invalide' => ["\xFF"],
]);

it('refuse cyrillique, grec, kana, kanji, hangul, lettres pleine chasse et signe micro avec le message d\'écriture', function (string $nickname) {
    expectNicknameRefused($nickname, ValidNickname::KEY_SCRIPT);
})->with([
    'o cyrillique (homoglyphe)' => ["B\u{043E}b"],
    'a cyrillique (homoglyphe)' => ["\u{0430}dmin"],
    'cyrillique' => ['Борис'],
    'grec' => ['Ζεύς'],
    'kana' => ['さくら'],
    'kanji' => ['映画'],
    'hangul' => ['영화'],
    'pleine chasse' => ['Ｂｏｂ'],
    'signe micro' => ["\u{00B5}Bob"],
    'chiffre d\'une autre écriture' => ["Bob\u{0663}"],
    // Une lettre refusée l'emporte sur un symbole refusé, avant ou après
    // lui : le message d'écriture dit au joueur quoi faire.
    'émoji puis lettre refusés' => ['Bob🎬й'],
    'lettre puis symbole refusés' => ["B\u{043E}b!"],
]);

it('accepte les indicateurs ordinaux ª et º, lettres latines du Latin-1 supplément', function () {
    expectNicknameAccepted("N\u{00BA} 5", "N\u{00BA} 5", 'no5');
    expectNicknameAccepted("1\u{00AA} Dama", "1\u{00AA} Dama", '1adama');
    expectNicknameAccepted("\u{00BA}\u{00AA}", "\u{00BA}\u{00AA}", 'oa');

    // Lettres de script latin (D26), là où `µ`, lettre elle aussi, ne l'est
    // pas : c'est ce qui sépare leur sort.
    expect(preg_match('/^\p{Latin}$/u', "\u{00AA}"))->toBe(1)
        ->and(preg_match('/^\p{Latin}$/u', "\u{00BA}"))->toBe(1)
        ->and(preg_match('/^\p{Latin}$/u', "\u{00B5}"))->toBe(0)
        ->and(preg_match('/^\p{L}$/u', "\u{00B5}"))->toBe(1);
});

it('refuse les caractères sans chasse, de contrôle, combinants, symboles et émojis avec le message de caractères', function (string $nickname) {
    expectNicknameRefused($nickname, ValidNickname::KEY_CHARACTERS);
    expectBareNicknameRefused($nickname, ValidNickname::KEY_CHARACTERS);
})->with([
    'espace sans chasse' => ["Bo\u{200B}b"],
    'liant sans chasse' => ["Bo\u{200D}b"],
    'gluon de mots' => ["Bo\u{2060}b"],
    'trait d\'union conditionnel' => ["Bo\u{00AD}b"],
    'indicateur d\'ordre des octets' => ["Bo\u{FEFF}b"],
    'contrôle' => ["Bo\u{0007}b"],
    'tabulation intérieure' => ["Bo\tb"],
    'combinant sans forme composée' => ["x\u{0301}y"],
    'combinant de trop' => ["Zoe\u{0301}\u{0301}"],
    'combinant de surimpression' => ["Bo\u{0336}b"],
    'espace insécable' => ["Bo\u{00A0}b"],
    'espace idéographique' => ["Bo\u{3000}b"],
    'espace fine' => ["Bo\u{2009}b"],
    'ponctuation' => ['Bob!'],
    'apostrophe' => ["Bob's"],
    'arobase' => ['Bob@home'],
    'multiplication' => ["Bob\u{00D7}2"],
    'division' => ["Bob\u{00F7}2"],
    'monnaie' => ['€uro'],
    'balise' => ['<b>Bob</b>'],
    'émoji' => ['Bob🎬'],
    'émojis seuls' => ['🎬🎬'],
    'émoji composé' => ["Bob\u{2764}\u{FE0F}"],
    // Ses octets invalides ne sont ni une lettre ni un chiffre : jamais le
    // message d'écriture (I5.1).
    'UTF-8 invalide' => ["Bo\xFFb"],
]);

it('refuse un pseudo fait seulement d\'espaces, de tirets et de soulignés', function () {
    foreach (['--__', '__', '- -', '_ _', '-_-_-', '-- __ --'] as $nickname) {
        expect(NicknameNormalizer::normalize(NicknameNormalizer::canonical($nickname)))->toBe('');

        expectNicknameRefused($nickname, ValidNickname::KEY_ALNUM);
    }

    // Des espaces seules sont rognées à vide par `TrimStrings`, puis rendues
    // nulles : `required` les refuse, seul, avant la règle.
    $blank = nicknameSubmit('     ');

    $blank->assertUnprocessable();

    expect($blank->json('errors'))->toBe(['nickname' => [
        __('validation.required', ['attribute' => __('validation.attributes.nickname')]),
    ]]);
});

it('refuse avec un message traduit un pseudo dont la forme repliée dépasse 20 caractères', function () {
    // Vingt `ß` : vingt caractères affichés, quarante une fois repliés.
    $twentyEszetts = str_repeat('ß', 20);

    expect(mb_strlen($twentyEszetts))->toBe(NicknameNormalizer::MAX_LENGTH)
        ->and(strlen(NicknameNormalizer::normalize($twentyEszetts)))->toBe(2 * NicknameNormalizer::MAX_LENGTH);

    expectNicknameRefused($twentyEszetts, ValidNickname::KEY_NORMALIZED_LENGTH);

    // Un caractère replié de trop suffit ; à vingt pile, le pseudo passe.
    expectNicknameRefused(str_repeat('ß', 10).'a', ValidNickname::KEY_NORMALIZED_LENGTH);
    expectNicknameRefused(str_repeat('Æ', 11), ValidNickname::KEY_NORMALIZED_LENGTH);
    expectNicknameAccepted(str_repeat('ß', 10), str_repeat('ß', 10), str_repeat('ss', 10));
    expectNicknameAccepted('Œdipe Þór', 'Œdipe Þór', 'oedipethor');
});

it('ne rend qu\'un message, dans l\'ordre longueur, écriture, caractères, alphanumérique, longueur normalisée, liste noire', function (string $nickname, array $failing) {
    $canonical = NicknameNormalizer::canonical($nickname);
    $length = mb_strlen($canonical);
    $refused = array_filter(
        mb_str_split($canonical),
        static fn (string $character): bool => preg_match(ValidNickname::ALLOWED_PATTERN, $character) !== 1,
    );
    $normalized = NicknameNormalizer::normalize($canonical);

    // Les étapes que la saisie manque, mesurées une à une, sans la règle.
    $steps = [
        ValidNickname::KEY_LENGTH => $length < NicknameNormalizer::MIN_LENGTH || $length > NicknameNormalizer::MAX_LENGTH,
        ValidNickname::KEY_SCRIPT => array_filter($refused, static fn (string $c): bool => preg_match('/[\p{L}\p{N}]/u', $c) === 1) !== [],
        ValidNickname::KEY_CHARACTERS => array_filter($refused, static fn (string $c): bool => preg_match('/[\p{L}\p{N}]/u', $c) !== 1) !== [],
        ValidNickname::KEY_ALNUM => $normalized === '',
        ValidNickname::KEY_NORMALIZED_LENGTH => strlen($normalized) > NicknameNormalizer::MAX_LENGTH,
        ValidNickname::KEY_BLOCKED => NicknameBlocklist::blocks($canonical),
    ];

    expect(array_keys(array_filter($steps)))->toBe($failing);

    // Un seul message, celui de la première étape manquée, dans chaque
    // langue ; par la vraie pile comme par la règle nue.
    expectNicknameRefused($nickname, $failing[0]);
    expectBareNicknameRefused($canonical, $failing[0]);
})->with([
    // `admin`, nom réservé, rend chaque saisie refusable par la liste noire.
    'longueur avant tout' => ['admin'."\u{0439}".'!'.str_repeat('ß', 14), [
        ValidNickname::KEY_LENGTH, ValidNickname::KEY_SCRIPT, ValidNickname::KEY_CHARACTERS,
        ValidNickname::KEY_NORMALIZED_LENGTH, ValidNickname::KEY_BLOCKED,
    ]],
    'écriture avant caractères' => ['admin'."\u{0439}".'!'.str_repeat('ß', 13), [
        ValidNickname::KEY_SCRIPT, ValidNickname::KEY_CHARACTERS,
        ValidNickname::KEY_NORMALIZED_LENGTH, ValidNickname::KEY_BLOCKED,
    ]],
    'caractères avant la forme repliée' => ['admin!'.str_repeat('ß', 14), [
        ValidNickname::KEY_CHARACTERS, ValidNickname::KEY_NORMALIZED_LENGTH, ValidNickname::KEY_BLOCKED,
    ]],
    // Une forme repliée vide ne peut être ni trop longue ni sur la liste.
    'alphanumérique seul' => ['-- __ --', [ValidNickname::KEY_ALNUM]],
    'longueur normalisée avant la liste noire' => ['admin'.str_repeat('ß', 15), [
        ValidNickname::KEY_NORMALIZED_LENGTH, ValidNickname::KEY_BLOCKED,
    ]],
    'liste noire en dernier' => ['admin', [ValidNickname::KEY_BLOCKED]],
]);
