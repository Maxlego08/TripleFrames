<?php

use App\Enums\Locale;
use App\Rules\ValidNickname;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\I18n\LocaleCookie;
use App\Support\Identity\NicknameBlocklist;
use App\Support\Identity\NicknameNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Tests\Support\Identity\NicknameFormRequest;

/*
|--------------------------------------------------------------------------
| La liste noire des pseudos — spec 40 § 5.7, contrat C5 (L40-4)
|--------------------------------------------------------------------------
|
| Étape 5 de `ValidNickname` : l'union des listes de chaque locale activée
| et des noms réservés, quelle que soit la langue du joueur. Une entrée
| longue est reconnue n'importe où (leet, lettres répétées ou séparées), une
| entrée courte seulement comme mot entier, et la forme réduite d'une entrée
| n'est cherchée que si la saisie montre elle-même une lettre répétée.
|
| **Les entrées offensantes sont lues dans les fichiers, jamais écrites dans
| ce test** — ni en clair, ni dans un message d'échec, qui les désigne par
| fichier et numéro de ligne. Seuls y figurent des noms réservés (« admin »,
| « curator »), qui n'offensent personne, et les pseudos que la spec nomme
| (« Conan », « Leçon », « Bob », « As », « Château », « Châteauu »).
|
| Les envois traversent la vraie pile `web` par `NicknameFormRequest`, comme
| dans `NicknameValidationTest`, tant que `50` n'a pas livré ses FormRequest.
|
*/

beforeEach(function () {
    Route::middleware('web')->post(nicknameBlocklistProbeUri(), function (NicknameFormRequest $request): JsonResponse {
        return response()->json(['nickname' => $request->validated('nickname')]);
    });
});

function nicknameBlocklistProbeUri(): string
{
    return '/_test/nickname-blocklist';
}

/**
 * Les entrées d'une liste (toutes si `$name` est nul), dans l'ordre des
 * fichiers de {@see NicknameBlocklist::files()} : fichier, ligne, forme
 * compilée. Le texte brut n'est rendu que pour être soumis, jamais affiché.
 *
 * @return list<array{file: string, line: int, raw: string, compiled: string}>
 */
function nicknameBlocklistEntries(?string $name = null): array
{
    $entries = [];

    foreach (NicknameBlocklist::files() as $path) {
        $file = basename($path, '.txt');

        if ($name !== null && $file !== $name) {
            continue;
        }

        foreach (explode("\n", (string) file_get_contents($path)) as $index => $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $entries[] = [
                'file' => $file,
                'line' => $index + 1,
                'raw' => $line,
                'compiled' => NicknameNormalizer::normalize(NicknameNormalizer::canonical($line)),
            ];
        }
    }

    return $entries;
}

/**
 * Désigne une entrée sans la citer.
 *
 * @param  array{file: string, line: int}  $entry
 */
function nicknameBlocklistLabel(array $entry, string $variant = ''): string
{
    return "{$entry['file']}.txt, ligne {$entry['line']}".($variant === '' ? '' : ", {$variant}");
}

/** `ρ` de la spec : toute lettre répétée ramenée à une seule. */
function nicknameBlocklistReduce(string $text): string
{
    return (string) preg_replace('/([a-z])\1+/', '$1', $text);
}

/**
 * Les messages que la règle nue rend pour une saisie canonique, dans la
 * langue courante de l'application.
 *
 * @return list<string>
 */
function nicknameBlocklistRuleErrors(string $nickname): array
{
    return Validator::make(
        ['nickname' => NicknameNormalizer::canonical($nickname)],
        ['nickname' => [new ValidNickname]],
    )->errors()->get('nickname');
}

/**
 * Vrai si la saisie passe les étapes 1 à 4 de la règle : ce que la liste
 * noire en fait est alors seul en cause.
 */
function nicknameBlocklistWellFormed(string $nickname): bool
{
    app()->setLocale(Locale::English->value);

    return array_diff(nicknameBlocklistRuleErrors($nickname), [__(ValidNickname::KEY_BLOCKED)]) === [];
}

/**
 * Le texte du § 5.9, tel qu'un joueur le lit.
 */
function nicknameBlocklistBlockedText(Locale $locale): string
{
    return $locale === Locale::French
        ? 'Ce pseudo n’est pas disponible. Choisissez-en un autre.'
        : 'This nickname is not available. Please choose another one.';
}

function nicknameBlocklistSubmit(string $nickname, Locale $locale): TestResponse
{
    return test()
        ->withUnencryptedCookie(LocaleCookie::NAME, $locale->value)
        ->post(
            nicknameBlocklistProbeUri(),
            ['nickname' => $nickname, 'avatar' => 'preset-01'],
            ['Accept' => 'application/json'],
        );
}

/**
 * Refus par la liste noire, et par elle seule : un message, `blocked`, sur le
 * seul champ `nickname`, dans chaque langue de requête.
 */
function expectNicknameBlocklisted(string $nickname, string $label): void
{
    foreach (Locale::cases() as $locale) {
        $response = nicknameBlocklistSubmit($nickname, $locale);

        expect($response->status())->toBe(422, "{$label} : non refusé ({$locale->value})")
            ->and($response->json('errors'))->toBe(
                ['nickname' => [nicknameBlocklistBlockedText($locale)]],
                "{$label} : autre refus que la liste noire ({$locale->value})",
            );
    }
}

/**
 * Acceptation dans chaque langue de requête, sous la forme canonique.
 */
function expectNicknameNotBlocklisted(string $nickname): void
{
    foreach (Locale::cases() as $locale) {
        $response = nicknameBlocklistSubmit($nickname, $locale);

        $response->assertOk();

        expect($response->json('nickname'))->toBe(NicknameNormalizer::canonical($nickname));
    }
}

/**
 * Vrai si une entrée figure dans une forme comme le compare `blocks()` :
 * sous-chaîne si elle est longue, jeton ou forme entière toujours.
 */
function nicknameBlocklistEntryMatches(string $entry, string $compact, string $folded): bool
{
    return (strlen($entry) >= NicknameBlocklist::SUBSTRING_MIN_LENGTH && str_contains($compact, $entry))
        || in_array($entry, preg_split('/[^a-z]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [], true)
        || $entry === $compact;
}

it('livre une liste noire avec en-tête de source et de licence pour chaque locale activée et pour les noms réservés', function () {
    $directory = resource_path(NicknameBlocklist::DIRECTORY);
    $expected = array_map(
        static fn (string $name): string => "{$directory}/{$name}.txt",
        [...array_map(static fn (Locale $locale): string => $locale->value, Locale::cases()), NicknameBlocklist::RESERVED_FILE],
    );

    // Un fichier par cas de `Locale`, dans l'ordre de déclaration, puis les
    // noms réservés ; aucun fichier du répertoire n'est laissé sans lecteur.
    expect(NicknameBlocklist::files())->toBe($expected);

    $present = glob("{$directory}/*") ?: [];
    sort($present);
    $sorted = $expected;
    sort($sorted);

    expect($present)->toBe($sorted);

    foreach ($expected as $path) {
        $file = basename($path);
        $contents = (string) file_get_contents($path);

        // UTF-8, LF, sans marque d'ordre des octets, terminé par un saut de
        // ligne, sans ligne vide ni blanc autour d'une entrée.
        expect(mb_check_encoding($contents, 'UTF-8'))->toBeTrue("{$file} : UTF-8 invalide")
            ->and(str_contains($contents, "\r"))->toBeFalse("{$file} : fins de ligne CRLF")
            ->and(str_starts_with($contents, "\u{FEFF}"))->toBeFalse("{$file} : BOM")
            ->and(str_ends_with($contents, "\n"))->toBeTrue("{$file} : pas de saut de ligne final");

        $lines = explode("\n", substr($contents, 0, -1));

        // L'en-tête : les commentaires de tête, avant la première entrée.
        $header = [];

        foreach ($lines as $line) {
            if (! str_starts_with($line, '#')) {
                break;
            }

            $header[] = $line;
        }

        $fields = [];

        foreach ($header as $line) {
            if (preg_match('/^# (source|license|retrieved|changes): (\S.*)$/u', $line, $match) === 1) {
                $fields[$match[1]][] = $match[2];
            }
        }

        foreach (['source', 'license', 'retrieved'] as $field) {
            expect($fields[$field] ?? [])->toHaveCount(1, "{$file} : « # {$field}: » absent de l'en-tête, ou répété");
        }

        $retrieved = CarbonImmutable::createFromFormat('!Y-m-d', $fields['retrieved'][0]);

        expect($fields['retrieved'][0])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and($retrieved)->toBeInstanceOf(CarbonImmutable::class)
            ->and($retrieved?->format('Y-m-d'))->toBe($fields['retrieved'][0], "{$file} : date de récupération invalide")
            ->and($retrieved?->isAfter(CarbonImmutable::today()))->toBeFalse("{$file} : date de récupération future");

        // Une source CC BY est retouchée dès qu'on lui ajoute un en-tête : la
        // licence exige de le déclarer (CC BY 4.0 § 3(a)(1)(B)), comme toute
        // entrée retirée ou ajoutée.
        if (preg_match('/\bCC[ -]BY\b/i', $fields['license'][0]) === 1) {
            expect($fields['changes'] ?? [])->not->toBeEmpty("{$file} : source CC BY sans « # changes: »");
        }

        // Clés conservées : l'indice + 1 est le numéro de ligne du fichier.
        $entries = array_filter(
            array_slice($lines, count($header), preserve_keys: true),
            static fn (string $line): bool => ! str_starts_with($line, '#'),
        );

        expect($entries)->not->toBeEmpty("{$file} : aucune entrée");

        // Un booléen, jamais l'entrée : un échec ne la cite pas.
        foreach ($entries as $index => $entry) {
            $number = $index + 1;

            expect($entry !== '' && $entry === trim($entry))
                ->toBeTrue("{$file}, ligne {$number} : ligne vide, ou entrée entourée de blancs");
        }

        expect(array_filter(
            nicknameBlocklistEntries(basename($path, '.txt')),
            static fn (array $entry): bool => $entry['compiled'] !== '',
        ))->not->toBeEmpty("{$file} : aucune entrée ne compile");
    }

    // Ajouter une langue sans sa liste noire casse bruyamment — y compris à la
    // validation, qui ne laisse alors passer aucun pseudo en silence.
    $base = app()->basePath();

    foreach ($expected as $missing) {
        $root = sys_get_temp_dir().'/tripleframes-blocklist-'.bin2hex(random_bytes(6));
        $copy = $root.'/resources/'.NicknameBlocklist::DIRECTORY;

        File::ensureDirectoryExists($copy);

        foreach ($expected as $path) {
            if ($path !== $missing) {
                File::copy($path, $copy.'/'.basename($path));
            }
        }

        try {
            app()->setBasePath($root);

            expect(fn () => NicknameBlocklist::files())->toThrow(RuntimeException::class, basename($missing))
                ->and(fn () => NicknameBlocklist::blocks('Zoé'))->toThrow(RuntimeException::class, basename($missing));
        } finally {
            app()->setBasePath($base);
            File::deleteDirectory($root);
        }
    }

    // La liste du dépôt, elle, se lit à nouveau.
    expect(NicknameBlocklist::files())->toBe($expected)
        ->and(NicknameBlocklist::blocks('Zoé'))->toBeFalse();
});

it('refuse une entrée de chaque langue activée quelle que soit la locale de la requête', function () {
    $compiledByFile = [];

    foreach (nicknameBlocklistEntries() as $entry) {
        $compiledByFile[$entry['file']][$entry['compiled']] = true;
    }

    foreach (Locale::cases() as $listLocale) {
        $candidates = array_values(array_filter(
            nicknameBlocklistEntries($listLocale->value),
            static fn (array $entry): bool => nicknameBlocklistWellFormed($entry['raw']),
        ));

        expect($candidates)->not->toBeEmpty("{$listLocale->value}.txt : aucune entrée qui soit un pseudo bien formé");

        // Chaque entrée bien formée se bloque elle-même, dans toute langue
        // d'application : la liste ne dépend jamais de la locale.
        foreach (Locale::cases() as $appLocale) {
            app()->setLocale($appLocale->value);

            foreach ($candidates as $entry) {
                expect(NicknameBlocklist::blocks(NicknameNormalizer::canonical($entry['raw'])))
                    ->toBeTrue(nicknameBlocklistLabel($entry)." : non bloquée sous la locale {$appLocale->value}");
            }
        }

        // Une entrée propre à cette liste — absente de toutes les autres —,
        // refusée dans chaque langue de requête : c'est l'union qui s'applique.
        $own = null;

        foreach ($candidates as $entry) {
            $elsewhere = array_filter(
                $compiledByFile,
                static fn (array $compiled, string $file): bool => $file !== $listLocale->value && isset($compiled[$entry['compiled']]),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($elsewhere === []) {
                $own = $entry;

                break;
            }
        }

        expect($own)->not->toBeNull("{$listLocale->value}.txt : aucune entrée qui lui soit propre");

        expectNicknameBlocklisted($own['raw'], nicknameBlocklistLabel($own));
    }
});

it('refuse les graphies leet, à lettres répétées et séparées d\'une entrée longue', function () {
    $toLeetPrimary = array_map('strval', array_flip(NicknameBlocklist::LEET_PRIMARY));
    $toLeetSecondary = array_map('strval', array_flip(NicknameBlocklist::LEET_SECONDARY));
    $accents = ['a' => 'à', 'c' => 'ç', 'e' => 'é', 'i' => 'ï', 'o' => 'ô'];

    // Les entrées longues faites de lettres seules, assez courtes pour que
    // chaque graphie tienne dans un pseudo.
    $long = array_values(array_filter(
        nicknameBlocklistEntries(),
        static fn (array $entry): bool => preg_match('/^[a-z]+$/', $entry['compiled']) === 1
            && strlen($entry['compiled']) >= NicknameBlocklist::SUBSTRING_MIN_LENGTH
            && 2 * strlen($entry['compiled']) <= NicknameNormalizer::MAX_LENGTH,
    ));

    expect(array_values(array_unique(array_column($long, 'file'))))->toEqualCanonicalizing([
        ...array_map(static fn (Locale $locale): string => $locale->value, Locale::cases()),
        NicknameBlocklist::RESERVED_FILE,
    ]);

    $sent = [];

    foreach ($long as $entry) {
        $word = $entry['compiled'];
        $letters = str_split($word);

        $variants = [
            'casse et accents' => mb_convert_case(strtr($word, $accents), MB_CASE_TITLE),
            'lettres doublées' => implode('', array_map(static fn (string $letter): string => $letter.$letter, $letters)),
            'dernière lettre triplée' => $word.str_repeat(substr($word, -1), 2),
            'lettres séparées d\'espaces' => implode(' ', $letters),
            'lettres séparées de tirets et soulignés' => implode('', array_map(
                static fn (string $letter, int $index): string => $index === 0 ? $letter : ($index % 2 === 0 ? '-' : '_').$letter,
                $letters,
                array_keys($letters),
            )),
            'au milieu d\'un pseudo' => 'Le'.$word.'42',
        ];

        if (strtr($word, $toLeetPrimary) !== $word) {
            $variants['leet, 1 pour i'] = strtr($word, $toLeetPrimary);
            $variants['leet séparé'] = implode('-', str_split(strtr($word, $toLeetPrimary)));
        }

        if (str_contains($word, 'l')) {
            $variants['leet, 1 pour l'] = strtr($word, $toLeetSecondary);
        }

        foreach ($variants as $name => $nickname) {
            $label = nicknameBlocklistLabel($entry, $name);

            expect($nickname !== $entry['raw'])->toBeTrue("{$label} : graphie identique à l'entrée")
                ->and(nicknameBlocklistWellFormed($nickname))->toBeTrue("{$label} : pseudo mal formé")
                ->and(NicknameBlocklist::blocks(NicknameNormalizer::canonical($nickname)))->toBeTrue("{$label} : non bloqué");
        }

        // Par la vraie pile, une entrée de chaque liste, toutes graphies.
        if (! isset($sent[$entry['file']])) {
            $sent[$entry['file']] = true;

            foreach ($variants as $name => $nickname) {
                expectNicknameBlocklisted($nickname, nicknameBlocklistLabel($entry, $name));
            }
        }
    }
});

it('ne refuse une entrée courte que comme mot entier', function () {
    $short = array_values(array_filter(
        nicknameBlocklistEntries(),
        static fn (array $entry): bool => preg_match('/^[a-z]+$/', $entry['compiled']) === 1
            && strlen($entry['compiled']) >= NicknameNormalizer::MIN_LENGTH
            && strlen($entry['compiled']) < NicknameBlocklist::SUBSTRING_MIN_LENGTH,
    ));

    expect($short)->not->toBeEmpty();

    foreach ($short as $entry) {
        $word = $entry['compiled'];

        // Seule, ou mot parmi d'autres : refusée.
        foreach (['seule' => ucfirst($word), 'mot parmi d\'autres' => 'Mon-'.$word.' 42'] as $name => $nickname) {
            expect(nicknameBlocklistWellFormed($nickname))->toBeTrue(nicknameBlocklistLabel($entry, $name))
                ->and(NicknameBlocklist::blocks($nickname))->toBeTrue(nicknameBlocklistLabel($entry, $name).' : non bloquée');
        }

        // Collée dans un mot : acceptée. Les lettres ajoutées ne doublent
        // jamais celles de l'entrée, pour ne pas créer de lettre répétée.
        $glued = ($word[0] === 'q' ? 'J' : 'Q').$word.(substr($word, -1) === 'z' ? 'j' : 'z');

        expect(nicknameBlocklistWellFormed($glued))->toBeTrue(nicknameBlocklistLabel($entry, 'collée dans un mot'))
            ->and(NicknameBlocklist::blocks($glued))->toBeFalse(nicknameBlocklistLabel($entry, 'collée dans un mot').' : bloquée');
    }

    // L'effet Scunthorpe que nomme la spec : une entrée courte de la liste
    // française figure dans « Conan » et dans « Leçon », qui passent.
    foreach (['Conan', 'Leçon'] as $name) {
        $inside = array_filter(
            $short,
            static fn (array $entry): bool => str_contains(NicknameNormalizer::normalize($name), $entry['compiled']),
        );

        expect($inside)->not->toBeEmpty("aucune entrée courte dans « {$name} » : l'exemple ne prouve plus rien");

        expectNicknameNotBlocklisted($name);

        foreach ($inside as $entry) {
            expectNicknameBlocklisted(ucfirst($entry['compiled']), nicknameBlocklistLabel($entry, 'seule'));
            expectNicknameBlocklisted('Le '.$entry['compiled'], nicknameBlocklistLabel($entry, 'mot entier'));
        }
    }
});

it('refuse les noms réservés comme admin et curator', function () {
    $reserved = nicknameBlocklistEntries(NicknameBlocklist::RESERVED_FILE);
    $compiled = array_column($reserved, 'compiled');

    // La liste minimale du contrat C5, plus les ajouts de 40.
    expect($compiled)->toContain(
        'admin', 'administrateur', 'administrator', 'moderateur', 'moderator', 'modo', 'curateur', 'curator',
        'system', 'systeme', 'support', 'staff', 'officiel', 'official', 'tripleframes', 'hote', 'host',
        'moderatrice', 'curatrice', 'tmdb', 'bot',
    );

    foreach ($reserved as $entry) {
        expectNicknameBlocklisted($entry['raw'], nicknameBlocklistLabel($entry));
    }

    // Toutes graphies, n'importe où pour un nom long.
    foreach ([
        'Admin', 'ADMIN', 'Curator', '4dm1n', 'Aaadmin', 'a d m i n', 'C-U-R-A-T-O-R', 'Le Curateur',
        'Modérateur', 'Modératrice', 'Système', 'Hôte', 'SuperAdmin', 'TripleFrames Staff', 'Official_42',
        'Bot', 'Le Bot', 'Bot_42', 'Modo 3000', 'TMDB',
    ] as $nickname) {
        expectNicknameBlocklisted($nickname, "« {$nickname} »");
    }

    // Un nom réservé court ne se reconnaît que comme mot entier.
    foreach (['Robot', 'Ghost', 'Hostile', 'Botaniste', 'Modou'] as $nickname) {
        expectNicknameNotBlocklisted($nickname);
    }
});

it('ne cite jamais le mot refusé dans le message', function () {
    $toLeet = array_map('strval', array_flip(NicknameBlocklist::LEET_PRIMARY));
    $cases = [];

    // Une entrée bien formée de chaque liste, grossièreté comme nom réservé,
    // saisie telle quelle puis en leet.
    foreach (NicknameBlocklist::files() as $path) {
        $file = basename($path, '.txt');

        foreach (nicknameBlocklistEntries($file) as $entry) {
            if (preg_match('/^[a-z]+$/', $entry['compiled']) === 1 && nicknameBlocklistWellFormed($entry['raw'])) {
                $cases[] = [$entry, $entry['raw']];
                $cases[] = [$entry, strtr($entry['compiled'], $toLeet)];

                break;
            }
        }
    }

    expect(count($cases))->toBe(2 * count(NicknameBlocklist::files()));

    $messages = [];

    foreach ($cases as [$entry, $nickname]) {
        foreach (Locale::cases() as $locale) {
            $response = nicknameBlocklistSubmit($nickname, $locale);
            $label = nicknameBlocklistLabel($entry)." ({$locale->value})";
            $body = AnswerKeyNormalizer::fold((string) $response->getContent());

            $response->assertUnprocessable();

            // Le texte neutre du § 5.9, sur le seul champ `nickname`…
            expect($response->json('errors'))->toBe(['nickname' => [nicknameBlocklistBlockedText($locale)]], $label)
                // … et nulle part, dans toute la réponse, le mot ni la saisie.
                ->and(str_contains($body, $entry['compiled']))->toBeFalse("{$label} : l'entrée figure dans la réponse")
                ->and(str_contains($body, AnswerKeyNormalizer::fold($nickname)))->toBeFalse("{$label} : la saisie figure dans la réponse");

            $messages[$locale->value][] = $response->json('errors.nickname.0');
        }
    }

    // Un seul message par langue : un nom réservé ne se distingue jamais
    // d'une grossièreté, ni une graphie d'une autre.
    foreach (Locale::cases() as $locale) {
        expect(array_unique($messages[$locale->value]))->toBe([nicknameBlocklistBlockedText($locale)]);
    }
});

it('accepte Bob, As et Château, que la forme réduite d\'une entrée rattraperait', function () {
    $entries = nicknameBlocklistEntries();

    foreach (['Bob', 'As', 'Château'] as $nickname) {
        $compact = NicknameNormalizer::normalize($nickname);
        $folded = AnswerKeyNormalizer::fold($nickname);

        // La saisie n'a aucune lettre répétée : la comparaison (b) ne joue pas.
        expect(nicknameBlocklistReduce($compact))->toBe($compact);

        // Aucune entrée brute ne la vise…
        $raw = array_filter(
            $entries,
            static fn (array $entry): bool => $entry['compiled'] !== ''
                && nicknameBlocklistEntryMatches($entry['compiled'], $compact, $folded),
        );

        // Les entrées fautives désignées par fichier et ligne, jamais citées.
        expect(array_map(static fn (array $entry): string => nicknameBlocklistLabel($entry), array_values($raw)))
            ->toBe([], "« {$nickname} » visé par une entrée brute");

        // … mais la forme réduite d'au moins une entrée la rattraperait, si
        // elle était cherchée sans condition.
        $reduced = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['compiled'] !== ''
                && nicknameBlocklistReduce($entry['compiled']) !== $entry['compiled']
                && nicknameBlocklistEntryMatches(nicknameBlocklistReduce($entry['compiled']), $compact, $folded),
        ));

        expect($reduced)->not->toBeEmpty("« {$nickname} » : aucune forme réduite ne le rattrape, l'exemple ne prouve plus rien");

        expectNicknameNotBlocklisted($nickname);

        // Dès que la saisie montre une lettre répétée, la forme réduite est
        // cherchée : la graphie étirée de l'entrée est refusée.
        foreach ($reduced as $entry) {
            $stretched = (string) preg_replace('/([a-z])\1/', '$1$1$1', $entry['compiled'], 1);

            expect(NicknameBlocklist::blocks($stretched))->toBeTrue(nicknameBlocklistLabel($entry, 'lettre étirée'));
        }
    }

    // Résidu assumé (§ 5.7) : une lettre répétée sans rapport avec l'entrée
    // suffit à chercher la forme réduite.
    expectNicknameBlocklisted('Châteauu', '« Châteauu »');
});
