<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Écrivains du score — spec 80 § 5, § 8.3 et § 16, contrat C13 (L80-2)
|--------------------------------------------------------------------------
|
| Balayage statique de `app/`, avec liste d'autorisation nominative. Deux
| propriétés que rien ne rendrait visibles en relecture :
|
| - LES POINTS D'UNE BONNE RÉPONSE NE S'ÉCRIVENT QU'UNE FOIS, ET PAR LA RÈGLE.
|   `guess.points_tier`, `points_bonus` et `points_total` sont écrits par la
|   seule transaction de verrouillage (70 § 9.1, `LockGuess`, lot L70-6), qui
|   recopie TELLES QUELLES les sorties de `ScoreCalculator::forGuess()` (80
|   § 5) : aucune autre écriture, aucun recalcul, aucune formule parallèle. Un
|   second écrivain, ou une part recomposée à la main, ferait diverger le
|   journal du rejeu sans qu'aucune donnée ne dise lequel est juste.
| - AUCUN ALÉA DANS LE SCORE. Aucune classe de `App\Support\Scoring` — ni ses
|   objets valeur, `App\ValueObjects\Scoring` — ne lit la graine de la partie
|   ni ne tire au sort : une égalité se départage par une chaîne déterministe,
|   jamais par `draw_seed` (§ 8.3, n° 66, E10-40).
|
| Le balayage lit le CODE au tokeniseur PHP, jamais les commentaires : un
| docblock qui cite `$guess->points_total = …` pour l'expliquer n'est pas une
| écriture. Chaque détecteur est prouvé dans les deux sens sur des témoins
| écrits en chaînes littérales — donc invisibles au balayage de ce fichier —,
| dans les formes que le framework offre : un motif affaibli rougit sur l'un
| ou l'autre.
|
*/

/**
 * Colonnes de points de `guess` et propriété homonyme de `TierScore`, seule
 * valeur qu'un écrivain autorisé peut y recopier.
 *
 * @return array<string, string>
 */
function scoringWritersPointColumns(): array
{
    return [
        'points_tier' => 'pointsTier',
        'points_bonus' => 'pointsBonus',
        'points_total' => 'pointsTotal',
    ];
}

/**
 * Liste d'autorisation nominative des écrivains de `guess.points_*` : fichier
 * → motif.
 *
 * `LockGuess` n'est pas encore livré (L70-6) : son entrée est posée d'avance,
 * pour que la transaction de verrouillage soit soumise à la règle dès sa
 * naissance. Un écrivain autorisé doit appeler `ScoreCalculator::forGuess()`,
 * en affecter le résultat à une variable, et n'écrire chaque colonne que de la
 * propriété homonyme de cette variable (`'points_tier' => $score->pointsTier`).
 *
 * @return array<string, string>
 */
function scoringWritersPointWriters(): array
{
    return [
        'app/Actions/Game/LockGuess.php' => 'seule écrivaine de guess (70 § 9.1), recopie les quatre sorties de ScoreCalculator::forGuess() (80 § 5)',
    ];
}

/**
 * Chemins relatifs (séparateur `/`) des fichiers PHP sous des répertoires du
 * dépôt, triés.
 *
 * @param  list<string>  $directories
 * @return list<string>
 */
function scoringWritersPhpFiles(array $directories): array
{
    $files = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR));
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Jetons signifiants d'une source : ni espaces, ni commentaires, ni balise.
 *
 * @return list<PhpToken>
 */
function scoringWritersTokens(string $source): array
{
    return array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));
}

/**
 * Contenu d'un littéral de chaîne simple, sans ses guillemets ; `null` pour
 * tout autre jeton.
 */
function scoringWritersLiteral(PhpToken $token): ?string
{
    return $token->is(T_CONSTANT_ENCAPSED_STRING) ? substr($token->text, 1, -1) : null;
}

/**
 * Index des jetons compris dans le corps d'une méthode `casts()` : une
 * déclaration de type (`'points_total' => 'integer'`), jamais une écriture.
 *
 * @param  list<PhpToken>  $tokens
 * @return array<int, true>
 */
function scoringWritersCastsBody(array $tokens): array
{
    $exempt = [];

    foreach ($tokens as $index => $token) {
        if (! $token->is(T_FUNCTION) || ! ($tokens[$index + 1] ?? null)?->is(T_STRING) || strtolower($tokens[$index + 1]->text) !== 'casts') {
            continue;
        }

        $depth = 0;

        for ($cursor = $index + 2; $cursor < count($tokens); $cursor++) {
            $text = $tokens[$cursor]->text;

            // Méthode sans corps (abstraite, interface) : rien à exempter.
            if ($depth === 0 && $text === ';') {
                break;
            }

            if ($text === '{' || $tokens[$cursor]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }

            if ($depth > 0) {
                $exempt[$cursor] = true;
            }
        }
    }

    return $exempt;
}

/**
 * Jetons d'une expression de valeur, depuis `$start` jusqu'au premier `,`,
 * `;`, `]` ou `)` hors de toute parenthèse ou de tout crochet ouverts par elle.
 *
 * @param  list<PhpToken>  $tokens
 * @return list<PhpToken>
 */
function scoringWritersValue(array $tokens, int $start): array
{
    $value = [];
    $depth = 0;

    for ($cursor = $start; $cursor < count($tokens); $cursor++) {
        $text = $tokens[$cursor]->text;

        if ($depth === 0 && in_array($text, [',', ';', ']', ')'], true)) {
            break;
        }

        if (in_array($text, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($text, [')', ']', '}'], true)) {
            $depth--;
        }

        $value[] = $tokens[$cursor];
    }

    return $value;
}

/**
 * Opérateurs qui écrivent la propriété qu'ils suivent.
 */
function scoringWritersIsAssignment(?PhpToken $token): bool
{
    return $token !== null && ($token->text === '=' || $token->is([
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_POW_EQUAL,
        T_CONCAT_EQUAL, T_COALESCE_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
        T_INC, T_DEC,
    ]));
}

/**
 * Toutes les écritures de `guess.points_*` d'une source, sous cinq formes :
 *
 * - `array` : clé de tableau suivie de `=>` (`create`, `insert`, `update`,
 *   `forceFill`, `fill`, `upsert`, `incrementEach`…), hors corps de `casts()` ;
 * - `property` : `$guess->points_total = …`, affectation composée, `++` / `--` ;
 * - `offset` : `$guess['points_total'] = …` (un modèle est un `ArrayAccess`) ;
 * - `call` : premier argument de `increment`, `decrement`, leurs variantes
 *   silencieuses, ou `setAttribute` ;
 * - `sql` : une chaîne SQL `INSERT`, `UPDATE` ou `REPLACE` qui nomme la colonne.
 *
 * Une colonne qualifiée par la table (`'guess.points_total'`) compte comme la
 * colonne nue, dans les trois formes à littéral (`array`, `offset`, `call`).
 *
 * `value` porte les jetons de la valeur écrite pour `array`, et pour
 * `property` / `offset` en affectation simple ; `null` sinon.
 *
 * @return list<array{line: int, column: string, form: string, value: list<PhpToken>|null}>
 */
function scoringWritersPointWrites(string $source): array
{
    $columns = array_keys(scoringWritersPointColumns());
    $tokens = scoringWritersTokens($source);
    $castsBody = scoringWritersCastsBody($tokens);
    $callers = ['increment', 'decrement', 'incrementquietly', 'decrementquietly', 'setattribute'];
    $writes = [];

    foreach ($tokens as $index => $token) {
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;
        $literal = scoringWritersLiteral($token);
        // Colonne qualifiée par la table (`'guess.points_total'`, forme courante
        // d'un `update` avec jointure) : seule compte la colonne.
        $column = $literal === null ? null : Str::afterLast($literal, '.');

        // Clé de tableau.
        if (in_array($column, $columns, true) && $next?->is(T_DOUBLE_ARROW) && ! isset($castsBody[$index])) {
            $writes[] = ['line' => $token->line, 'column' => (string) $column, 'form' => 'array', 'value' => scoringWritersValue($tokens, $index + 2)];
        }

        // Accès par offset : `$guess['points_total'] = …`.
        if (in_array($column, $columns, true) && $previous?->text === '[' && $next?->text === ']' && scoringWritersIsAssignment($tokens[$index + 2] ?? null)) {
            $operator = $tokens[$index + 2];
            $writes[] = ['line' => $token->line, 'column' => (string) $column, 'form' => 'offset', 'value' => $operator->text === '=' ? scoringWritersValue($tokens, $index + 3) : null];
        }

        // Premier argument d'un appel qui écrit la colonne nommée.
        if (in_array($column, $columns, true) && $previous?->text === '(' && ($tokens[$index - 2] ?? null)?->is(T_STRING) && in_array(strtolower($tokens[$index - 2]->text), $callers, true)) {
            $writes[] = ['line' => $token->line, 'column' => (string) $column, 'form' => 'call', 'value' => null];
        }

        // Propriété de modèle : `->points_total` suivi d'une affectation, ou précédé de `++` / `--`.
        if ($token->is(T_STRING) && in_array($token->text, $columns, true) && $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            $prefixed = ($tokens[$index - 2] ?? null)?->is(T_VARIABLE) && ($tokens[$index - 3] ?? null)?->is([T_INC, T_DEC]);

            if (scoringWritersIsAssignment($next) || $prefixed) {
                $writes[] = ['line' => $token->line, 'column' => $token->text, 'form' => 'property', 'value' => $next?->text === '=' ? scoringWritersValue($tokens, $index + 2) : null];
            }
        }

        // SQL brut.
        if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])
            && preg_match('/\b(?:insert|update|replace)\b[\s\S]*\b(points_(?:tier|bonus|total))\b/i', $token->text, $match) === 1) {
            $writes[] = ['line' => $token->line, 'column' => strtolower($match[1]), 'form' => 'sql', 'value' => null];
        }
    }

    return $writes;
}

/**
 * Variables affectées directement du résultat de `ScoreCalculator::forGuess()`.
 *
 * @return list<string>
 */
function scoringWritersCalculatorVariables(string $source): array
{
    $tokens = scoringWritersTokens($source);
    $variables = [];

    foreach ($tokens as $index => $token) {
        $class = $tokens[$index + 2] ?? null;

        if ($token->is(T_VARIABLE)
            && ($tokens[$index + 1] ?? null)?->text === '='
            && $class?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && ($class->text === 'ScoreCalculator' || str_ends_with($class->text, '\\ScoreCalculator'))
            && ($tokens[$index + 3] ?? null)?->is(T_DOUBLE_COLON)
            && ($tokens[$index + 4] ?? null)?->text === 'forGuess'
            && ($tokens[$index + 5] ?? null)?->text === '(') {
            $variables[] = $token->text;
        }
    }

    return array_values(array_unique($variables));
}

/**
 * Violations de la règle « toute écriture de `guess.points_*` passe par
 * `ScoreCalculator` » dans une source.
 *
 * Hors liste d'autorisation, toute écriture en est une. Dans la liste, seules
 * sont admises les affectations simples (`array`, `property`, `offset`) dont la
 * valeur est exactement `$variable->pointsX`, `$variable` venant de
 * `ScoreCalculator::forGuess()` et `pointsX` étant la propriété homonyme de la
 * colonne ; un écrivain autorisé qui n'écrit plus rien signale une écriture
 * déplacée hors de la liste.
 *
 * @return list<string>
 */
function scoringWritersPointViolations(string $file, string $source, bool $allowlisted): array
{
    $writes = scoringWritersPointWrites($source);
    $violations = [];

    if (! $allowlisted) {
        foreach ($writes as $write) {
            $violations[] = "{$file}:{$write['line']} écrit guess.{$write['column']} ({$write['form']}) hors de la liste d'autorisation.";
        }

        return $violations;
    }

    if ($writes === []) {
        return ["{$file} n'écrit plus aucune colonne de points : l'écriture a quitté la liste d'autorisation."];
    }

    $variables = scoringWritersCalculatorVariables($source);

    if ($variables === []) {
        $violations[] = "{$file} n'affecte jamais le résultat de ScoreCalculator::forGuess() à une variable.";
    }

    foreach ($writes as $write) {
        $property = scoringWritersPointColumns()[$write['column']];
        $value = $write['value'];
        $copied = $value !== null
            && count($value) === 3
            && $value[0]->is(T_VARIABLE) && in_array($value[0]->text, $variables, true)
            && $value[1]->is(T_OBJECT_OPERATOR)
            && $value[2]->is(T_STRING) && $value[2]->text === $property;

        if (! $copied) {
            $violations[] = "{$file}:{$write['line']} écrit guess.{$write['column']} ({$write['form']}) autrement qu'en recopiant ->{$property} du résultat de ScoreCalculator::forGuess().";
        }
    }

    return $violations;
}

/**
 * Emplois d'aléa ou de graine dans une source : `draw_seed` (propriété,
 * variable ou chaîne), `hash_hmac`, `random_*`, `shuffle` sous toutes ses
 * formes (`->shuffleArray()`, `->shuffleBytes()`, `->pickArrayKeys()`
 * comprises), toute mention de l'API `Random\` de PHP 8.2 (`Randomizer`,
 * `Random\Engine\*` : import, instanciation, nom qualifié), les autres tirages
 * de PHP et du framework (`rand`, `mt_rand`, `array_rand`, `str_shuffle`,
 * `uniqid`, `lcg_value`, `openssl_random_pseudo_bytes`, `crc32`, `Str::random`,
 * `->random()`), ces mêmes noms en callable de chaîne (`$f = 'shuffle'`), ainsi
 * que `SeededPrf`, seul porteur légitime de la graine. Même périmètre que
 * `drawBoundaryRandomnessCalls()` (E70-3), plus la graine et `hash_hmac`.
 *
 * @return list<string>
 */
function scoringWritersRandomUses(string $source): array
{
    $forbidden = [
        'hash_hmac', 'shuffle', 'str_shuffle', 'shufflearray', 'shufflebytes', 'pickarraykeys', 'array_rand',
        'rand', 'mt_rand', 'mt_srand', 'srand', 'lcg_value', 'uniqid', 'openssl_random_pseudo_bytes', 'crc32',
        'random', 'randomizer', 'draw_seed', 'drawseed', 'seededprf',
    ];
    $uses = [];

    foreach (scoringWritersTokens($source) as $token) {
        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
            $bare = strtolower(ltrim($token->text, '\\'));
            $name = strtolower(Str::afterLast($token->text, '\\'));

            if (in_array($name, $forbidden, true) || str_starts_with($name, 'random_') || str_starts_with($bare, 'random\\')) {
                $uses[] = "ligne {$token->line} : {$token->text}";
            }
        }

        // Callable en chaîne : `$f = 'shuffle'; $f($rows)`, `array_map('mt_rand', …)`.
        $literal = scoringWritersLiteral($token);

        // (Une chaîne qui contient `draw_seed` est signalée plus bas, une seule fois.)
        if ($literal !== null && stripos($literal, 'draw_seed') === false) {
            $callable = strtolower(ltrim($literal, '\\'));

            if (in_array($callable, $forbidden, true) || str_starts_with($callable, 'random_')) {
                $uses[] = "ligne {$token->line} : {$token->text}";
            }
        }

        if ($token->is(T_VARIABLE) && in_array(strtolower(ltrim($token->text, '$')), ['draw_seed', 'drawseed'], true)) {
            $uses[] = "ligne {$token->line} : {$token->text}";
        }

        if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && stripos($token->text, 'draw_seed') !== false) {
            $uses[] = "ligne {$token->line} : {$token->text}";
        }
    }

    return $uses;
}

test('toute écriture de guess.points_* passe par ScoreCalculator', function (): void {
    // Détecteur, sens négatif : chaque forme d'écriture est vue.
    $unauthorized = [
        'update en masse' => "<?php Guess::query()->whereKey(\$id)->update(['points_total' => 0]);",
        'insert par la façade' => "<?php DB::table('guess')->insert(['round_id' => 1, 'points_tier' => 300]);",
        'forceFill sur plusieurs lignes' => "<?php \$guess->forceFill([\n    'points_bonus' => 12,\n]);",
        'affectation de propriété' => '<?php $guess->points_bonus = 0;',
        'affectation composée' => '<?php $guess->points_total += 10;',
        'incrément suffixe' => '<?php $guess->points_total++;',
        'incrément préfixe' => '<?php ++$guess->points_tier;',
        'offset de modèle' => "<?php \$guess['points_total'] = 1;",
        'increment' => "<?php \$guess->increment('points_total', 5);",
        'decrementQuietly' => "<?php \$guess->decrementQuietly('points_bonus');",
        'setAttribute' => "<?php \$guess->setAttribute('points_tier', 100);",
        'SQL brut' => "<?php DB::update('UPDATE guess SET points_total = ? WHERE id = ?', [0, 1]);",
        'SQL en heredoc' => "<?php DB::statement(<<<SQL\n    INSERT INTO guess (round_id, points_bonus) VALUES ({\$id}, 0)\n    SQL);",
        'update qualifié' => "<?php DB::table('guess')->join('round', 'round.id', '=', 'guess.round_id')->update(['guess.points_total' => 0]);",
        'increment qualifié' => "<?php DB::table('guess')->increment('guess.points_bonus', 5);",
    ];

    foreach ($unauthorized as $label => $source) {
        expect(scoringWritersPointWrites($source))->not->toBeEmpty($label)
            ->and(scoringWritersPointViolations('témoin', $source, false))->not->toBeEmpty($label);
    }

    // Détecteur, sens positif : lectures, déclarations et citations ne sont pas des écritures.
    $readOnly = <<<'PHP'
        <?php
        #[Hidden(['points_tier', 'points_bonus', 'points_total'])]
        class Witness extends Model
        {
            /** $guess->points_total = 0 ; 'points_bonus' => 1 ; UPDATE guess SET points_total = 0 */
            protected function casts(): array
            {
                return ['points_tier' => 'integer', 'points_bonus' => 'integer', 'points_total' => 'integer'];
            }

            public function read(Guess $guess): array
            {
                // $guess->points_total = 0;
                $same = $guess->points_total === $guess->points_tier + $guess->points_bonus;
                $loose = $guess->points_total == 0;
                $sum = Guess::query()->sum('points_total');
                $raw = Guess::query()->selectRaw('SUM(points_total) AS score')->value('score');
                $qualified = DB::table('guess')->orderBy('guess.points_total')->pluck('guess.points_total');

                return [$same, $loose, $sum, $raw, $qualified, $guess->getAttribute('points_total')];
            }
        }
        PHP;

    expect(scoringWritersPointWrites($readOnly))->toBe([]);

    // Écrivain autorisé : la recopie telle quelle du résultat de forGuess() est
    // admise, toute autre valeur ne l'est pas.
    $faithful = <<<'PHP'
        <?php
        $score = ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $answeredAtMs, $source);
        $guess = new Guess;
        $guess->forceFill([
            'tier_index' => $score->tierIndex,
            'points_tier' => $score->pointsTier,
            'points_bonus' => $score->pointsBonus,
            'points_total' => $score->pointsTotal,
        ]);
        $guess->points_total = $score->pointsTotal;
        PHP;

    expect(scoringWritersPointWrites($faithful))->toHaveCount(4)
        ->and(scoringWritersPointViolations('témoin', $faithful, true))->toBe([]);

    $unfaithful = [
        'total recomposé' => str_replace("'points_total' => \$score->pointsTotal", "'points_total' => \$score->pointsTier + \$score->pointsBonus", $faithful),
        'propriété croisée' => str_replace("'points_bonus' => \$score->pointsBonus", "'points_bonus' => \$score->pointsTier", $faithful),
        'valeur constante' => str_replace("'points_bonus' => \$score->pointsBonus", "'points_bonus' => 0", $faithful),
        'score construit à la main' => str_replace('ScoreCalculator::forGuess(', 'new TierScore(', $faithful),
        'autre variable' => str_replace('$guess->points_total = $score->pointsTotal', '$guess->points_total = $other->pointsTotal', $faithful),
        'affectation composée' => str_replace('$guess->points_total = $score->pointsTotal', '$guess->points_total += $score->pointsTotal', $faithful),
        'incrément' => $faithful."\n\$guess->increment('points_total');",
    ];

    foreach ($unfaithful as $label => $source) {
        expect($source)->not->toBe($faithful, $label)
            ->and(scoringWritersPointViolations('témoin', $source, true))->not->toBeEmpty($label);
    }

    // Balayage de app/.
    $writers = scoringWritersPointWriters();
    $files = scoringWritersPhpFiles(['app']);
    $violations = [];

    expect($files)->toContain('app/Models/Guess.php', 'app/Support/Scoring/ScoreCalculator.php');

    foreach ($files as $file) {
        $violations = [...$violations, ...scoringWritersPointViolations(
            $file,
            (string) file_get_contents(base_path($file)),
            array_key_exists($file, $writers),
        )];
    }

    expect($violations)->toBe([]);
});

test('aucune classe de App\Support\Scoring n’emploie draw_seed, hash_hmac, random_int ni shuffle', function (): void {
    // Détecteur, sens négatif : chaque forme est vue.
    $witnesses = [
        'graine de la partie' => '<?php $seed = $game->draw_seed;',
        'graine par attribut' => "<?php \$seed = \$game->getAttribute('draw_seed');",
        'graine en variable' => '<?php function tie(string $drawSeed): int { return 0; }',
        'hash_hmac' => "<?php \$key = hash_hmac('sha256', \$a, \$b);",
        'random_int qualifié' => '<?php $pick = \random_int(0, 3);',
        'random_bytes' => '<?php $bytes = random_bytes(4);',
        'shuffle' => '<?php shuffle($rows);',
        'shuffle de collection' => '<?php $rows = collect($rows)->shuffle();',
        'Arr::shuffle' => '<?php $rows = Arr::shuffle($rows);',
        'Str::random' => '<?php $token = Str::random(8);',
        'mt_rand' => '<?php $n = mt_rand();',
        'array_rand' => '<?php $k = array_rand($rows);',
        'PRF de la graine' => '<?php $index = SeededPrf::forGame($game)->index($context, 2);',
        'Randomizer::shuffleArray' => '<?php $rows = (new \Random\Randomizer())->shuffleArray($rows);',
        'Randomizer importé' => '<?php use Random\Randomizer; $rows = (new Randomizer())->shuffleArray($rows);',
        'Randomizer::pickArrayKeys' => '<?php $keys = (new \Random\Randomizer())->pickArrayKeys($rows, 1);',
        'moteur Random\Engine' => '<?php use Random\Engine\Mt19937;',
        'moteur Random\Engine qualifié' => '<?php $n = (new \Random\Randomizer(new \Random\Engine\Mt19937($seed)))->getInt(0, 3);',
        'openssl_random_pseudo_bytes' => '<?php $bytes = openssl_random_pseudo_bytes(4);',
        'crc32' => '<?php $tie = crc32($a) <=> crc32($b);',
        'callable en chaîne' => "<?php \$f = 'shuffle'; \$f(\$rows);",
        'callable en chaîne pour array_map' => "<?php \$rolls = array_map('mt_rand', \$rows);",
    ];

    foreach ($witnesses as $label => $source) {
        expect(scoringWritersRandomUses($source))->not->toBeEmpty($label);
    }

    // Sens positif : un tri déterministe, une citation en commentaire et un texte
    // qui cite un nom interdit sans en être un callable ne sont pas de l'aléa.
    $deterministic = <<<'PHP'
        <?php
        /** Jamais draw_seed, hash_hmac, random_int ni shuffle (§ 8.3). */
        usort($standings, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        // shuffle($standings); (new \Random\Randomizer())->shuffleArray($standings);
        $label = 'shuffle the deck';
        PHP;

    expect(scoringWritersRandomUses($deterministic))->toBe([]);

    // Balayage de App\Support\Scoring et de ses objets valeur.
    $files = scoringWritersPhpFiles(['app/Support/Scoring', 'app/ValueObjects/Scoring']);
    $uses = [];

    expect($files)->toContain(
        'app/Support/Scoring/ScoringRules.php',
        'app/Support/Scoring/ScoreCalculator.php',
        'app/Support/Scoring/ScoreReplayer.php',
        'app/Support/Scoring/Ranking.php',
        'app/Support/Scoring/Scoreboard.php',
        'app/ValueObjects/Scoring/PlayerTally.php',
        'app/ValueObjects/Scoring/Standing.php',
    );

    foreach ($files as $file) {
        foreach (scoringWritersRandomUses((string) file_get_contents(base_path($file))) as $use) {
            $uses[] = "{$file} — {$use}";
        }
    }

    expect($uses)->toBe([]);
});
