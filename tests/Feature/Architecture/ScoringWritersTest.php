<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Écrivains du score — spec 80 § 5, § 8.3, § 10.1 et § 16, contrat C13 (L80-2, L80-4)
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
| - LE GEL N'A QU'UNE ÉCRIVAINE (L80-4). `game.ended_at`, les cinq agrégats de
|   `game_player` et le statut terminal de la partie (`completed`,
|   `interrupted`) ne s'écrivent que dans `FinalizeGame` (§ 10.1, E10-36,
|   E10-43, C17) : un second écrivain poserait `ended_at` sans agrégats, ou
|   à « maintenant », et prolongerait une conservation annoncée publiquement ;
|   un statut terminal posé ailleurs casserait « `ended_at` non nul ⟺ statut
|   terminal ». Corollaire : dans `app/`, toute clé de tableau `ended_at` qui
|   ne nomme pas la manche, et toute clé `rounds_played`, `correct_answers`,
|   `final_score`, `total_answer_time_ms` ou `final_rank`, rougit ce test MÊME
|   EN LECTURE (présentateur, export, journal, props) : l'écrire en camelCase
|   (`endedAt`, `finalScore`…).
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
 * `LockGuess` (L70-6) y figure depuis avant sa livraison : la transaction de
 * verrouillage a été soumise à la règle dès sa naissance. Un écrivain autorisé
 * doit appeler `ScoreCalculator::forGuess()`, en affecter le résultat à une
 * variable, et n'écrire chaque colonne que de la propriété homonyme de cette
 * variable (`'points_tier' => $score->pointsTier`).
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
 * Toutes les écritures de colonnes nommées dans une source, sous cinq formes :
 *
 * - `array` : clé de tableau suivie de `=>` (`create`, `insert`, `update`,
 *   `forceFill`, `fill`, `upsert`, `incrementEach`…), hors corps de `casts()` ;
 * - `property` : `$guess->points_total = …`, affectation composée, `++` / `--` ;
 * - `offset` : `$guess['points_total'] = …` (un modèle est un `ArrayAccess`) ;
 * - `call` : premier argument de `increment`, `decrement`, leurs variantes
 *   silencieuses, `touch` ou `setAttribute` ;
 * - `sql` : une chaîne SQL `INSERT`, `UPDATE` ou `REPLACE` qui nomme la colonne.
 *
 * Une colonne qualifiée par la table (`'guess.points_total'`) compte comme la
 * colonne nue, dans les trois formes à littéral (`array`, `offset`, `call`).
 *
 * `value` porte les jetons de la valeur écrite pour `array`, pour
 * `property` / `offset` en affectation simple, et pour `setAttribute` ; `null`
 * sinon.
 *
 * `target` est la table visée, lue sur la forme elle-même (L80-4) : receveur
 * de l'affectation ou de l'appel ({@see scoringWritersReceiver()}), contexte du
 * tableau ({@see scoringWritersArrayTarget()}), table nommée par le SQL ;
 * `null` quand rien dans le code ne la dit.
 *
 * @param  list<string>  $columns
 * @return list<array{line: int, column: string, form: string, value: list<PhpToken>|null, target: string|null}>
 */
function scoringWritersColumnWrites(string $source, array $columns): array
{
    $tokens = scoringWritersTokens($source);
    $declaredClass = scoringWritersDeclaredClass($tokens);
    $castsBody = scoringWritersCastsBody($tokens);
    $callers = ['increment', 'decrement', 'incrementquietly', 'decrementquietly', 'touch', 'touchquietly', 'setattribute'];
    $sqlColumns = implode('|', array_map(static fn (string $column): string => preg_quote($column, '/'), $columns));
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
            $writes[] = [
                'line' => $token->line, 'column' => (string) $column, 'form' => 'array',
                'value' => scoringWritersValue($tokens, $index + 2),
                'target' => scoringWritersArrayTarget($tokens, $index, $declaredClass),
            ];
        }

        // Accès par offset : `$guess['points_total'] = …`.
        if (in_array($column, $columns, true) && $previous?->text === '[' && $next?->text === ']' && scoringWritersIsAssignment($tokens[$index + 2] ?? null)) {
            $operator = $tokens[$index + 2];
            $writes[] = [
                'line' => $token->line, 'column' => (string) $column, 'form' => 'offset',
                'value' => $operator->text === '=' ? scoringWritersValue($tokens, $index + 3) : null,
                'target' => scoringWritersReceiver($tokens, $index - 2, $declaredClass),
            ];
        }

        // Premier argument d'un appel qui écrit la colonne nommée.
        if (in_array($column, $columns, true) && $previous?->text === '(' && ($tokens[$index - 2] ?? null)?->is(T_STRING) && in_array(strtolower($tokens[$index - 2]->text), $callers, true)) {
            $setter = strtolower($tokens[$index - 2]->text) === 'setattribute' && $next?->text === ',';
            $writes[] = [
                'line' => $token->line, 'column' => (string) $column, 'form' => 'call',
                'value' => $setter ? scoringWritersValue($tokens, $index + 2) : null,
                'target' => ($tokens[$index - 3] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
                    ? scoringWritersReceiver($tokens, $index - 4, $declaredClass)
                    : null,
            ];
        }

        // Propriété de modèle : `->points_total` suivi d'une affectation, ou précédé de `++` / `--`.
        if ($token->is(T_STRING) && in_array($token->text, $columns, true) && $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            $prefixed = ($tokens[$index - 2] ?? null)?->is(T_VARIABLE) && ($tokens[$index - 3] ?? null)?->is([T_INC, T_DEC]);

            if (scoringWritersIsAssignment($next) || $prefixed) {
                $writes[] = [
                    'line' => $token->line, 'column' => $token->text, 'form' => 'property',
                    'value' => $next?->text === '=' ? scoringWritersValue($tokens, $index + 2) : null,
                    'target' => scoringWritersReceiver($tokens, $index - 2, $declaredClass),
                ];
            }
        }

        // SQL brut.
        if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])
            && preg_match('/\b(?:insert|update|replace)\b[\s\S]*\b('.$sqlColumns.')\b/i', $token->text, $match) === 1) {
            $table = preg_match('/\b(?:update|insert\s+(?:ignore\s+)?into|replace\s+into)\s+[`"]?(\w+)/i', $token->text, $named) === 1
                ? strtolower($named[1])
                : null;
            $writes[] = ['line' => $token->line, 'column' => strtolower($match[1]), 'form' => 'sql', 'value' => null, 'target' => $table];
        }
    }

    return $writes;
}

/**
 * Toutes les écritures de `guess.points_*` d'une source, sous les cinq formes
 * de {@see scoringWritersColumnWrites()}.
 *
 * `value` n'est jamais portée pour la forme `call` : seule une affectation
 * simple peut recopier le résultat de `ScoreCalculator::forGuess()` (L80-2).
 *
 * @return list<array{line: int, column: string, form: string, value: list<PhpToken>|null, target: string|null}>
 */
function scoringWritersPointWrites(string $source): array
{
    return array_map(
        static fn (array $write): array => $write['form'] === 'call' ? ['value' => null] + $write : $write,
        scoringWritersColumnWrites($source, array_keys(scoringWritersPointColumns())),
    );
}

/*
|--------------------------------------------------------------------------
| Cible d'une écriture (L80-4)
|--------------------------------------------------------------------------
|
| `ended_at` et `status` ne sont pas propres à une table : `round.ended_at`
| et `round.status` s'écrivent à chaque transition de manche (60), le statut
| du salon, d'un balayage d'import ou d'une purge aussi. Le balayage lit donc
| la table visée sur le code lui-même, sans exécuter ni typer : le premier
| segment qui nomme une entité, en remontant la chaîne d'objets depuis
| l'écriture — propriété (`->game`), relation (`->rounds()`), variable
| (`$lockedGame`), classe (`Game::`), `DB::table('game')`, `$this` / `self` /
| `static` (classe du fichier). Un nom qui finit par « game(s) » vise `game`,
| par « round(s) » vise `round` ; `GamePlayer` ou `$gamePlayers` visent leur
| propre table. Les méthodes de construction de requête (`query`, `where…`,
| `find…`, `lockForUpdate`…) sont traversées.
|
*/

/**
 * Nom de la classe déclarée par une source, ou `null`.
 *
 * @param  list<PhpToken>  $tokens
 */
function scoringWritersDeclaredClass(array $tokens): ?string
{
    foreach ($tokens as $index => $token) {
        if ($token->is(T_CLASS) && ! ($tokens[$index - 1] ?? null)?->is(T_DOUBLE_COLON) && ($tokens[$index + 1] ?? null)?->is(T_STRING)) {
            return $tokens[$index + 1]->text;
        }
    }

    return null;
}

/**
 * Entité nommée par une variable, une propriété ou une relation : `game` pour
 * un nom qui finit par « game(s) », `round` pour « round(s) », le nom en
 * minuscules sinon.
 */
function scoringWritersEntity(string $name): string
{
    $name = strtolower(ltrim($name, '$'));

    return match (true) {
        preg_match('/games?$/', $name) === 1 => 'game',
        preg_match('/rounds?$/', $name) === 1 => 'round',
        default => $name,
    };
}

/**
 * Entité d'une classe nommée : son nom court en minuscules (`Game` → `game`,
 * `GamePlayer` → `gameplayer`, `FinalizeGame` → `finalizegame`).
 */
function scoringWritersClassEntity(?string $class): ?string
{
    return $class === null ? null : strtolower(Str::afterLast(ltrim($class, '\\'), '\\'));
}

/**
 * Méthodes traversées par la remontée d'une chaîne : elles construisent ou
 * relisent la même requête, sans nommer d'autre entité.
 */
function scoringWritersNeutralMethod(string $method): bool
{
    $method = strtolower($method);

    return in_array($method, [
        'query', 'newquery', 'newmodelquery', 'newquerywithoutscopes', 'tobase', 'getquery', 'fresh', 'refresh',
        'fill', 'forcefill', 'tap', 'when', 'unless', 'latest', 'oldest', 'limit', 'take', 'skip', 'offset',
        'distinct', 'withoutglobalscope', 'withoutglobalscopes', 'withtrashed', 'setconnection', 'on',
        'onwriteconnection', 'sharedlock',
    ], true) || preg_match('/^(?:where|orwhere|order|group|having|with|select|join|leftjoin|rightjoin|find|first|sole|lock)/', $method) === 1;
}

/**
 * Index de l'ouvrant qui répond au fermant `$close` (`)`, `]` ou `}`).
 *
 * @param  list<PhpToken>  $tokens
 */
function scoringWritersMatchingOpener(array $tokens, int $close): ?int
{
    $depth = 0;

    for ($cursor = $close; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];

        if (in_array($token->text, [')', ']', '}'], true)) {
            $depth++;
        } elseif (in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
            $depth--;

            if ($depth === 0) {
                return $cursor;
            }
        }
    }

    return null;
}

/**
 * Index du premier ouvrant resté ouvert avant `$index` — la parenthèse, le
 * crochet ou l'accolade qui contient ce jeton.
 *
 * @param  list<PhpToken>  $tokens
 */
function scoringWritersEnclosingOpener(array $tokens, int $index): ?int
{
    $depth = 0;

    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];

        if (in_array($token->text, [')', ']', '}'], true)) {
            $depth++;
        } elseif (in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
            if ($depth === 0) {
                return $cursor;
            }

            $depth--;
        }
    }

    return null;
}

/**
 * Entité visée par la chaîne d'objets qui se termine au jeton `$end` (inclus),
 * lue de droite à gauche ; `null` si rien ne la nomme (fonction, fermeture,
 * expression).
 *
 * @param  list<PhpToken>  $tokens
 */
function scoringWritersReceiver(array $tokens, int $end, ?string $declaredClass): ?string
{
    $cursor = $end;

    while ($cursor >= 0) {
        $token = $tokens[$cursor];
        $before = $tokens[$cursor - 1] ?? null;

        // Offset dans la chaîne : `$rows[0]->…`.
        if ($token->text === ']') {
            $open = scoringWritersMatchingOpener($tokens, $cursor);

            if ($open === null) {
                return null;
            }

            $cursor = $open - 1;

            continue;
        }

        if ($token->text === ')') {
            $open = scoringWritersMatchingOpener($tokens, $cursor);

            if ($open === null) {
                return null;
            }

            $name = $tokens[$open - 1] ?? null;
            $operator = $tokens[$open - 2] ?? null;

            // Appel de méthode dans la chaîne.
            if ($name !== null && $name->is(T_STRING) && $operator !== null && $operator->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                if (strtolower($name->text) === 'table') {
                    $table = ($tokens[$open + 1] ?? null) === null ? null : scoringWritersLiteral($tokens[$open + 1]);

                    return $table === null ? null : strtolower($table);
                }

                if (! scoringWritersNeutralMethod($name->text)) {
                    return scoringWritersEntity($name->text);
                }

                $cursor = $open - 3;

                continue;
            }

            // `new Game(…)`.
            if ($name !== null && $name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $operator?->is(T_NEW)) {
                return scoringWritersClassEntity($name->text);
            }

            // `(new Game)`.
            $inner = $tokens[$open + 1] ?? null;
            $class = $tokens[$open + 2] ?? null;

            if (($name === null || ! $name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_VARIABLE]))
                && $inner?->is(T_NEW) && $class !== null && $class->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                return scoringWritersClassEntity($class->text);
            }

            // Fonction (`tap($game)`), fermeture, expression : indéterminée.
            return null;
        }

        if ($token->is(T_VARIABLE)) {
            return $token->text === '$this'
                ? scoringWritersClassEntity($declaredClass)
                : scoringWritersEntity($token->text);
        }

        // Propriété ; `$this->attributes` est le modèle lui-même.
        if ($token->is(T_STRING) && $before !== null && $before->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            if (strtolower($token->text) === 'attributes') {
                $cursor -= 2;

                continue;
            }

            return scoringWritersEntity($token->text);
        }

        // Racine de classe : `Game::…`, `self::…`, `static::…`.
        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC])) {
            return in_array(strtolower($token->text), ['self', 'static', 'parent'], true)
                ? scoringWritersClassEntity($declaredClass)
                : scoringWritersClassEntity($token->text);
        }

        return null;
    }

    return null;
}

/**
 * Entité visée par le tableau qui contient la clé en `$index` : l'argument
 * d'un appel de méthode (`->update([…])`, `->forceFill([…])`, `::create([…])`,
 * éventuellement au travers d'une fonction comme `array_merge`) vise le
 * receveur de l'appel ; `new Game([…])`, la classe ; la valeur par défaut
 * `$attributes` d'un modèle, la classe du fichier. Tout autre tableau
 * (variable locale, retour, valeur imbriquée, argument nommé) :
 * indéterminée.
 *
 * @param  list<PhpToken>  $tokens
 */
function scoringWritersArrayTarget(array $tokens, int $index, ?string $declaredClass): ?string
{
    $open = scoringWritersEnclosingOpener($tokens, $index);

    if ($open === null) {
        return null;
    }

    $start = match (true) {
        $tokens[$open]->text === '[' => $open,
        $tokens[$open]->text === '(' && ($tokens[$open - 1] ?? null)?->is(T_ARRAY) => $open - 1,
        default => null,
    };

    while ($start !== null) {
        $before = $tokens[$start - 1] ?? null;

        // Valeurs par défaut d'un modèle : `protected $attributes = […]`.
        if ($before?->text === '=') {
            $assigned = $tokens[$start - 2] ?? null;
            $declared = $assigned?->is(T_VARIABLE) && $assigned->text === '$attributes'
                && ($tokens[$start - 3] ?? null)?->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_STATIC, T_ARRAY]);
            $assignedOnThis = $assigned?->is(T_STRING) && $assigned->text === 'attributes'
                && ($tokens[$start - 3] ?? null)?->is(T_OBJECT_OPERATOR)
                && ($tokens[$start - 4] ?? null)?->text === '$this';

            return $declared || $assignedOnThis ? scoringWritersClassEntity($declaredClass) : null;
        }

        if ($before === null || ($before->text !== '(' && $before->text !== ',')) {
            return null;
        }

        $paren = $before->text === '(' ? $start - 1 : scoringWritersEnclosingOpener($tokens, $start - 1);

        if ($paren === null || $tokens[$paren]->text !== '(') {
            return null;
        }

        $name = $tokens[$paren - 1] ?? null;
        $operator = $tokens[$paren - 2] ?? null;

        if ($name === null) {
            return null;
        }

        if ($name->is(T_STRING) && $operator?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
            return scoringWritersReceiver($tokens, $paren - 3, $declaredClass);
        }

        if ($name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $operator?->is(T_NEW)) {
            return scoringWritersClassEntity($name->text);
        }

        // Fonction (`array_merge([…], […])`) : l'appel est à son tour un argument.
        if ($name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $start = $paren - 1;

            continue;
        }

        return null;
    }

    return null;
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

/**
 * Seule écrivaine du gel : `game.ended_at`, statut terminal, agrégats de
 * `game_player` (L80-4).
 */
function scoringWritersFreezeWriter(): string
{
    return 'app/Actions/Game/FinalizeGame.php';
}

/**
 * Les cinq agrégats figés de `game_player` (80 § 10.4) — des noms propres à
 * cette table : toute écriture en est une, quelle qu'en soit la forme.
 *
 * @return list<string>
 */
function scoringWritersAggregateColumns(): array
{
    return ['rounds_played', 'correct_answers', 'final_score', 'total_answer_time_ms', 'final_rank'];
}

/**
 * Violations de « seul FinalizeGame écrit `game.ended_at` et les agrégats de
 * `game_player` » dans une source hors de la liste d'autorisation.
 *
 * `ended_at` n'existe que sur `game` et `round` : hors du gel, une écriture
 * n'est admise que si sa cible se lit `round` ; une cible `game` est une
 * violation, et une cible indéterminée aussi — une écriture qui ne dit pas ce
 * qu'elle vise ne se prouve pas étrangère à la partie.
 *
 * @return list<string>
 */
function scoringWritersFreezeViolations(string $file, string $source): array
{
    $violations = [];

    foreach (scoringWritersColumnWrites($source, ['ended_at']) as $write) {
        if ($write['target'] === 'round') {
            continue;
        }

        $violations[] = $write['target'] === 'game'
            ? "{$file}:{$write['line']} écrit game.ended_at ({$write['form']}) hors de FinalizeGame."
            : "{$file}:{$write['line']} écrit ended_at ({$write['form']}) sur une cible indéterminée [".($write['target'] ?? '?').'] : '
                ."nommer la manche (\$round, ->round, Round::, DB::table('round')), ou geler par FinalizeGame.";
    }

    foreach (scoringWritersColumnWrites($source, scoringWritersAggregateColumns()) as $write) {
        $violations[] = "{$file}:{$write['line']} écrit game_player.{$write['column']} ({$write['form']}) hors de FinalizeGame.";
    }

    return $violations;
}

/**
 * Nom court d'un jeton de nom de classe (`\App\Enums\GameStatus` → `GameStatus`).
 */
function scoringWritersShortName(PhpToken $token): string
{
    return Str::afterLast(ltrim($token->text, '\\'), '\\');
}

/**
 * La valeur écrite nomme-t-elle un statut terminal de partie, ou en fabrique-t-elle
 * un depuis une valeur (`GameStatus::Completed`, `GameStatus::Interrupted`,
 * `GameStatus::from(…)`, `GameStatus::tryFrom(…)`) ?
 *
 * @param  list<PhpToken>|null  $value
 */
function scoringWritersMentionsTerminalStatus(?array $value): bool
{
    foreach ($value ?? [] as $index => $token) {
        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && scoringWritersShortName($token) === 'GameStatus'
            && ($value[$index + 1] ?? null)?->is(T_DOUBLE_COLON)
            && in_array(strtolower($value[$index + 2]->text ?? ''), ['completed', 'interrupted', 'from', 'tryfrom'], true)) {
            return true;
        }
    }

    return false;
}

/**
 * La valeur écrite est-elle, à la lettre, un statut de partie en cours —
 * `GameStatus::Running` ou `GameStatus::Paused` (suivi ou non de `->value`),
 * `'running'` ou `'paused'` ? Toute autre valeur (variable, expression,
 * statut terminal) ne se prouve pas non terminale.
 *
 * @param  list<PhpToken>|null  $value
 */
function scoringWritersIsLiveStatus(?array $value): bool
{
    if ($value === null || $value === []) {
        return false;
    }

    $literal = scoringWritersLiteral($value[0]);

    if (count($value) === 1 && $literal !== null) {
        return in_array($literal, ['running', 'paused'], true);
    }

    $texts = array_map(static fn (PhpToken $token): string => $token->text, array_slice($value, 1));

    return $value[0]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
        && scoringWritersShortName($value[0]) === 'GameStatus'
        && in_array($texts, [['::', 'Running'], ['::', 'Paused'], ['::', 'Running', '->', 'value'], ['::', 'Paused', '->', 'value']], true);
}

/**
 * Violations de « seul FinalizeGame écrit `game.status` à completed ou
 * interrupted » dans une source hors de la liste d'autorisation :
 *
 * - toute écriture de `status` dont la valeur nomme un statut terminal de
 *   partie, quelle qu'en soit la cible ;
 * - toute écriture de `status` visant `game` dont la valeur n'est pas, à la
 *   lettre, un statut de partie en cours (`running`, `paused`) — une
 *   variable ne se prouve pas non terminale ;
 * - toute écriture SQL de `status` dans la table `game`.
 *
 * Une écriture de `status` à cible indéterminée et à valeur étrangère à
 * `GameStatus` (statut d'un salon, d'un import, d'une purge, d'une charge
 * utile) n'est pas une écriture de la partie.
 *
 * @return list<string>
 */
function scoringWritersStatusViolations(string $file, string $source): array
{
    $violations = [];

    foreach (scoringWritersColumnWrites($source, ['status']) as $write) {
        $gameTarget = $write['target'] === 'game';

        $violation = match (true) {
            $write['form'] === 'sql' => $gameTarget,
            scoringWritersMentionsTerminalStatus($write['value']) => true,
            default => $gameTarget && ! scoringWritersIsLiveStatus($write['value']),
        };

        if ($violation) {
            $violations[] = "{$file}:{$write['line']} écrit ".($gameTarget ? 'game.status' : 'un statut terminal de partie')
                ." ({$write['form']}) hors de FinalizeGame : seul le gel termine une partie.";
        }
    }

    return $violations;
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

test('dans app/, seul FinalizeGame écrit game.ended_at et les agrégats de game_player', function (): void {
    // Détecteur, sens négatif : chaque forme et chaque manière de nommer la
    // partie sont lues comme une écriture de game.ended_at…
    $gameWrites = [
        'propriété' => '<?php $game->ended_at = now();',
        'partie relue sous verrou' => '<?php $lockedGame->ended_at = $endedAt;',
        'partie d’une manche' => '<?php $round->game->ended_at = $endedAt;',
        'propriété d’un job' => '<?php $this->game->ended_at = $endedAt;',
        'forceFill' => "<?php \$game->forceFill(['status' => \$outcome, 'ended_at' => \$endedAt])->save();",
        'update en masse' => "<?php Game::query()->whereKey(\$id)->lockForUpdate()->update(['ended_at' => \$now]);",
        'classe qualifiée' => "<?php \\App\\Models\\Game::whereKey(\$id)->update(['ended_at' => \$now]);",
        'relation' => "<?php \$round->game()->update(['ended_at' => \$now]);",
        'façade' => "<?php DB::table('game')->where('id', \$id)->update(['ended_at' => \$now]);",
        'au travers d’une fonction' => "<?php \$game->forceFill(array_merge(\$base, ['ended_at' => \$now]));",
        'constructeur' => "<?php \$game = new Game(['ended_at' => \$now]);",
        'constructeur entre parenthèses' => "<?php (new Game)->forceFill(['ended_at' => \$now])->save();",
        'offset' => "<?php \$game['ended_at'] = \$now;",
        'setAttribute' => "<?php \$game->setAttribute('ended_at', \$now);",
        'touch' => "<?php \$game->touch('ended_at');",
        'méthode du modèle' => '<?php class Game extends Model { public function close(): void { $this->ended_at = now(); } }',
        'attributs bruts du modèle' => "<?php class Game extends Model { public function close(): void { \$this->attributes['ended_at'] = now(); } }",
        'défaut du modèle' => "<?php class Game extends Model { protected \$attributes = ['ended_at' => null]; }",
        'requête du modèle' => "<?php class Game extends Model { public static function closeAll(): void { static::query()->update(['ended_at' => now()]); } }",
        'SQL brut' => "<?php DB::update('UPDATE game SET ended_at = ? WHERE id = ?', [\$now, \$id]);",
    ];

    foreach ($gameWrites as $label => $source) {
        expect(array_column(scoringWritersColumnWrites($source, ['ended_at']), 'target'))->toBe(['game'], $label)
            ->and(scoringWritersFreezeViolations('témoin', $source))->toHaveCount(1, $label);
    }

    // … toute écriture qui ne dit pas ce qu'elle vise est refusée aussi…
    $undetermined = [
        'cible indéterminée' => '<?php $model->ended_at = $now;',
        'tableau local' => "<?php \$values = ['ended_at' => \$now];",
        'fonction' => "<?php tap(\$game)->update(['ended_at' => \$now]);",
        'argument nommé' => '<?php $game->forceFill(attributes: [\'ended_at\' => $now]);',
        'journal' => "<?php Log::info('fin', ['ended_at' => \$now]);",
    ];

    foreach ($undetermined as $label => $source) {
        $targets = array_column(scoringWritersColumnWrites($source, ['ended_at']), 'target');

        expect($targets)->toHaveCount(1, $label)
            ->and(array_intersect($targets, ['game', 'round']))->toBe([], $label)
            ->and(scoringWritersFreezeViolations('témoin', $source))->toHaveCount(1, $label);
    }

    // … et les agrégats du siège, quelle qu'en soit la forme.
    $aggregateWrites = [
        'rang' => '<?php $seat->final_rank = 1;',
        'score en masse' => "<?php GamePlayer::query()->whereKey(\$id)->update(['final_score' => 0]);",
        'incrément' => "<?php \$seat->increment('correct_answers');",
        'façade des sièges' => "<?php DB::table('game_player')->update(['rounds_played' => 0]);",
        'SQL des sièges' => "<?php DB::statement('UPDATE game_player SET total_answer_time_ms = 0');",
    ];

    foreach ($aggregateWrites as $label => $source) {
        expect(scoringWritersFreezeViolations('témoin', $source))->toHaveCount(1, $label);
    }

    // Sens positif : les écritures de la manche, dans toutes leurs formes, sont
    // lues comme telles…
    $roundWrites = <<<'PHP'
        <?php
        $round->ended_at = $at;
        $lastRound->forceFill(['status' => RoundStatus::Completed, 'ended_at' => $at])->save();
        $this->round->ended_at = $at;
        Round::query()->where('game_id', $game->id)->where('status', 'running')->update(['ended_at' => $at]);
        $game->rounds()->whereNull('ended_at')->update(['ended_at' => $at]);
        DB::table('round')->where('game_id', $game->id)->update(['ended_at' => $at]);
        DB::update('UPDATE round SET ended_at = ? WHERE id = ?', [$at, $id]);
        PHP;

    expect(array_column(scoringWritersColumnWrites($roundWrites, ['ended_at']), 'target'))->toBe(array_fill(0, 7, 'round'))
        ->and(scoringWritersFreezeViolations('témoin', $roundWrites))->toBe([]);

    $roundModel = <<<'PHP'
        <?php
        class Round extends Model
        {
            protected function casts(): array
            {
                return ['ended_at' => 'datetime'];
            }

            public function close(CarbonImmutable $at): void
            {
                $this->ended_at = $at;
                $this->attributes['ended_at'] = $at;
            }
        }
        PHP;

    expect(scoringWritersFreezeViolations('témoin', $roundModel))->toBe([]);

    // … et les lectures, les citations et les alias de requête ne sont pas des
    // écritures.
    $readOnly = <<<'PHP'
        <?php
        /** $game->ended_at = now(); 'final_rank' => 1 ; UPDATE game SET ended_at = NOW() */
        $open = $game->ended_at === null;
        $count = Game::query()->whereNull('ended_at')->count();
        $iso = $game->ended_at?->toIso8601String();
        $played = (int) $row->rounds_played;
        $ranks = GamePlayer::query()->orderBy('final_rank')->pluck('final_score');
        $raw = RoundPlayer::query()->selectRaw('count(*) as rounds_played')->get();
        // $seat->final_rank = 1;
        PHP;

    expect(scoringWritersFreezeViolations('témoin', $readOnly))->toBe([]);

    // Balayage de app/.
    $writer = scoringWritersFreezeWriter();
    $files = scoringWritersPhpFiles(['app']);
    $violations = [];

    expect($files)->toContain($writer, 'app/Models/Game.php', 'app/Models/GamePlayer.php', 'app/Models/Round.php');

    foreach ($files as $file) {
        if ($file !== $writer) {
            $violations = [...$violations, ...scoringWritersFreezeViolations($file, (string) file_get_contents(base_path($file)))];
        }
    }

    expect($violations)->toBe([]);

    // L'écrivaine autorisée écrit bien ce que la règle lui réserve : sinon
    // l'écriture a quitté la liste d'autorisation.
    $source = (string) file_get_contents(base_path($writer));
    $gameEndedAt = array_filter(
        scoringWritersColumnWrites($source, ['ended_at']),
        static fn (array $write): bool => $write['target'] === 'game',
    );
    $aggregates = array_values(array_unique(array_column(scoringWritersColumnWrites($source, scoringWritersAggregateColumns()), 'column')));
    $expected = scoringWritersAggregateColumns();
    sort($aggregates);
    sort($expected);

    expect($gameEndedAt)->not->toBeEmpty()
        ->and($aggregates)->toBe($expected);
});

test('dans app/, seul FinalizeGame écrit game.status à completed ou interrupted', function (): void {
    // Détecteur, sens négatif : un statut terminal, quelle que soit la cible ;
    // toute valeur non prouvée en cours sur la partie ; le SQL de la partie.
    $unauthorized = [
        'statut terminal' => '<?php $game->status = GameStatus::Completed;',
        'statut en variable' => '<?php $game->status = $outcome;',
        'update en masse' => "<?php Game::query()->whereKey(\$id)->update(['status' => GameStatus::Interrupted]);",
        'littéral' => "<?php \$game->forceFill(['status' => 'completed'])->save();",
        'depuis une valeur' => '<?php $room->game->status = GameStatus::from($value);',
        'repli sur tryFrom' => '<?php $lockedGame->status = GameStatus::tryFrom($raw) ?? GameStatus::Running;',
        'façade' => "<?php DB::table('game')->where('id', \$id)->update(['status' => 'interrupted']);",
        'cible indéterminée' => "<?php \$anything->update(['status' => GameStatus::Completed]);",
        'offset qualifié' => "<?php \$payload['status'] = \\App\\Enums\\GameStatus::Interrupted->value;",
        'setAttribute' => "<?php \$game->setAttribute('status', GameStatus::Completed);",
        'SQL brut' => "<?php DB::update(\"UPDATE game SET status = 'completed' WHERE id = ?\", [\$id]);",
        'défaut du modèle' => "<?php class Game extends Model { protected \$attributes = ['status' => 'completed']; }",
        'affectation composée' => "<?php \$game->status .= 'd';",
    ];

    foreach ($unauthorized as $label => $source) {
        expect(scoringWritersStatusViolations('témoin', $source))->toHaveCount(1, $label);
    }

    // Sens positif : pause et reprise de la partie, statuts des manches, du
    // salon, charges utiles et lectures.
    $allowed = <<<'PHP'
        <?php
        $game->status = GameStatus::Paused;
        $lockedGame->status = GameStatus::Running;
        Game::query()->whereKey($id)->update(['status' => GameStatus::Paused->value, 'paused_at' => $now]);
        $game->forceFill(['status' => 'running', 'paused_at' => null])->save();
        $round->status = RoundStatus::Completed;
        $lastRound->forceFill(['status' => RoundStatus::Completed])->save();
        $room->forceFill(['status' => RoomStatus::Lobby])->save();
        DB::table('round')->update(['status' => 'completed']);
        DB::update('UPDATE round SET status = ? WHERE id = ?', ['completed', $id]);
        $payload = ['status' => $tally->status->value];
        $terminal = in_array($game->status, [GameStatus::Completed, GameStatus::Interrupted], true);
        $ended = Game::query()->whereIn('status', [GameStatus::Completed, GameStatus::Interrupted])->count();
        $label = match ($game->status) { GameStatus::Completed, GameStatus::Interrupted => 'ended', default => 'live' };
        PHP;

    // Les quatre premières visent bien la partie : c'est la valeur en cours qui
    // les admet, pas une cible manquée.
    expect(array_column(scoringWritersColumnWrites($allowed, ['status']), 'target'))
        ->toBe(['game', 'game', 'game', 'game', 'round', 'round', 'room', 'round', 'round', null])
        ->and(scoringWritersStatusViolations('témoin', $allowed))->toBe([]);

    $gameModel = "<?php class Game extends Model { protected \$attributes = ['status' => GameStatus::Running->value]; }";

    expect(array_column(scoringWritersColumnWrites($gameModel, ['status']), 'target'))->toBe(['game'])
        ->and(scoringWritersStatusViolations('témoin', $gameModel))->toBe([]);

    // Balayage de app/.
    $writer = scoringWritersFreezeWriter();
    $files = scoringWritersPhpFiles(['app']);
    $violations = [];

    expect($files)->toContain($writer, 'app/Models/Game.php');

    foreach ($files as $file) {
        if ($file !== $writer) {
            $violations = [...$violations, ...scoringWritersStatusViolations($file, (string) file_get_contents(base_path($file)))];
        }
    }

    expect($violations)->toBe([]);

    // L'écrivaine autorisée écrit bien le statut de la partie.
    $gameStatus = array_filter(
        scoringWritersColumnWrites((string) file_get_contents(base_path($writer)), ['status']),
        static fn (array $write): bool => $write['target'] === 'game',
    );

    expect($gameStatus)->not->toBeEmpty();
});
